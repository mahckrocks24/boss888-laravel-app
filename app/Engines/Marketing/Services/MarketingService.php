<?php

namespace App\Engines\Marketing\Services;

use App\Connectors\DeepSeekConnector;
use App\Core\Email888\Contracts\SendEmailCommand;
use App\Core\Email888\EmailDispatcher;
use App\Engines\Marketing\Support\CampaignPlanner;
use App\Jobs\SendEmailCampaignJob;
use App\Core\Intelligence\EngineIntelligenceService;
use App\Engines\Creative\Services\CreativeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MarketingService
{
    public function __construct(
        // EM-7: EmailConnector injection removed — no EmailConnector import (EM-7).
        private DeepSeekConnector         $llm,
        private EngineIntelligenceService  $engineIntel,
        private CreativeService            $creative,
        private \App\Connectors\RuntimeClient $runtime,
        // EM-7: campaign mail dispatches through Email888, never through a
        // connector. Appended last so no positional caller changes meaning.
        private EmailDispatcher $dispatcher,
        // EM-7/12: normalises either campaign shape into one dispatch plan.
        private CampaignPlanner $planner,
    ) {}

    // ── Creative blueprint helper ────────────────────────────────────────────
    private function blueprint(int $wsId, string $type, array $context = []): array
    {
        try {
            $result = $this->creative->generateThroughBlueprint('marketing', $type, $wsId, $context);
            return $result['output'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function blueprintContext(array $bp): string
    {
        // FIX 2026-04-13 (Phase 0.17b downstream): the chat_json blueprint refactor
        // means BlueprintService can now return richer JSON shapes — fields like
        // `avoid` and `tone_instructions` may come back as arrays. Coerce them to
        // comma-joined strings so the string interpolation doesn't throw.
        $stringify = static function ($v): ?string {
            if ($v === null || $v === '') return null;
            if (is_string($v)) return $v;
            if (is_array($v)) {
                $flat = array_filter(array_map(
                    fn($x) => is_scalar($x) ? (string) $x : null,
                    $v
                ), fn($x) => $x !== null && $x !== '');
                return empty($flat) ? null : implode(', ', $flat);
            }
            return is_scalar($v) ? (string) $v : null;
        };

        $brand = $stringify($bp['brand_context'] ?? null);
        $tone  = $stringify($bp['tone_instructions'] ?? null);
        $avoid = $stringify($bp['avoid'] ?? null);

        $parts = array_filter([
            $brand,
            $tone  !== null ? "Tone: {$tone}"   : null,
            $avoid !== null ? "Avoid: {$avoid}" : null,
        ]);
        return empty($parts) ? '' : implode(' | ', $parts);
    }

    // ═══════════════════════════════════════════════════════
    // CAMPAIGNS
    // ═══════════════════════════════════════════════════════

    public function createCampaign(int $wsId, array $data): array
    {
        $id = DB::table('campaigns')->insertGetId([
            'workspace_id' => $wsId,
            'name' => $data['name'] ?? 'Untitled Campaign',
            'type' => $data['type'] ?? 'email',
            'status' => 'draft',
            'subject' => $data['subject'] ?? null,
            'body_html' => $data['body_html'] ?? null,
            'recipients_json' => json_encode($data['recipients'] ?? []),
            'stats_json' => json_encode(['sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0]),
            'template_id' => $data['template_id'] ?? null,
            'created_by' => $data['user_id'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->engineIntel->recordToolUsage('marketing', 'create_campaign');
        return ['campaign_id' => $id, 'status' => 'draft'];
    }

    public function getCampaign(int $wsId, int $id): ?object
    {
        $campaign = DB::table('campaigns')->where('workspace_id', $wsId)->where('id', $id)->first();
        if ($campaign) {
            $campaign->recipient_count = count(json_decode($campaign->recipients_json ?? '[]', true));
        }
        return $campaign;
    }

    public function listCampaigns(int $wsId, array $filters = []): array
    {
        $q = DB::table('campaigns')->where('workspace_id', $wsId)->whereNull('deleted_at');
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        if (!empty($filters['type'])) $q->where('type', $filters['type']);
        $total = $q->count();
        return ['campaigns' => $q->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get(), 'total' => $total];
    }

    public function updateCampaign(int $id, array $data, ?int $wsId = null): array
    {
        $update = array_intersect_key($data, array_flip(['name', 'subject', 'body_html', 'type']));
        if (isset($data['recipients'])) $update['recipients_json'] = json_encode($data['recipients']);
        if (isset($data['template_id'])) $update['template_id'] = $data['template_id'];
        $update['updated_at'] = now();
        $n = DB::table('campaigns')->where('id', $id)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->update($update);
        if ($wsId !== null && $n === 0) throw new \RuntimeException('Campaign not found');
        return ['updated' => true];
    }

    public function scheduleCampaign(int $id, string $scheduledAt, ?int $wsId = null): array
    {
        $n = DB::table('campaigns')->where('id', $id)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->update([
            'status' => 'scheduled', 'scheduled_at' => $scheduledAt, 'updated_at' => now(),
        ]);
        if ($wsId !== null && $n === 0) throw new \RuntimeException('Campaign not found');
        return ['scheduled' => true, 'scheduled_at' => $scheduledAt];
    }

    public function sendCampaign(int $wsId, int $id): array
    {
        $campaign = DB::table('campaigns')->where('id', $id)->where('workspace_id', $wsId)->first();
        if (!$campaign) throw new \RuntimeException("Campaign not found");
        if ($campaign->status === 'sent') throw new \RuntimeException("Campaign already sent");

        // ── EM-7 PHASE 12 — asynchronous hand-off ─────────────────────
        // This method used to fan out to every recipient INSIDE the HTTP
        // request: one `leads` query and one provider call per person. A large
        // audience therefore coupled customer request latency to provider
        // latency, risked a request timeout, and — worst — a timeout mid-loop
        // left a campaign partially sent with the client free to retry it.
        //
        // The request now does only bounded work: normalise, record intent,
        // enqueue. It returns an ACKNOWLEDGEMENT and deliberately cannot report
        // a send, because at this moment nothing has been sent. Recipient
        // dispatch, the ledger and the aggregate all belong to the worker.

        // Already in flight: do not queue a second fan-out. Per-recipient
        // idempotency would stop the duplicate mail anyway, but discovering
        // that by running the whole campaign again is wasted provider work and
        // a confusing history.
        if (in_array($campaign->status, ['pending', 'sending'], true)) {
            return [
                'accepted'          => true,
                'already_in_flight' => true,
                'campaign_id'       => $id,
                'status'            => (string) $campaign->status,
                'queued_recipients' => 0,
                'sent'              => 0,
                'failed'            => 0,
                'total'             => 0,
                'message'           => 'This campaign is already being processed.',
            ];
        }

        // Throws for "not found" and "No recipients" — both BEFORE anything is
        // queued, so a campaign that cannot be planned is never left looking
        // in-flight.
        $plan = $this->planner->plan($id, $wsId);

        DB::table('campaigns')->where('id', $id)->update([
            'status'     => 'pending',
            'sent_at'    => null,
            'stats_json' => json_encode([
                'recipients' => $plan->count(),
                'accepted'   => 0,
                'duplicate'  => 0,
                'refused'    => 0,
                'sent'       => 0,
                'delivered'  => 0,
                'delivered_source' => 'email888_delivery_ledger',
                'failures'   => [],
            ]),
            'updated_at' => now(),
        ]);

        try {
            SendEmailCampaignJob::dispatch($id)->onQueue('tasks');
        } catch (\Throwable $e) {
            // Nothing was queued, so nothing will ever be sent. A campaign left
            // in 'pending' here would look in-flight forever.
            DB::table('campaigns')->where('id', $id)->update([
                'status' => 'failed', 'sent_at' => null, 'updated_at' => now(),
            ]);
            Log::error('marketing.campaign.enqueue_failed', [
                'campaign_id' => $id, 'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Campaign could not be queued for sending.', 0, $e);
        }

        $this->engineIntel->recordToolUsage('marketing', 'send_campaign', 0.7);

        return [
            'accepted'          => true,
            'campaign_id'       => $id,
            'status'            => 'pending',
            'queued_recipients' => $plan->count(),

            // Legacy keys, and literally true at this instant: nothing has been
            // sent and nothing has failed yet. They are not a claim of success.
            'sent'    => 0,
            'failed'  => 0,
            'total'   => $plan->count(),
            'message' => 'Campaign accepted for processing. Delivery outcomes appear in the Email Delivery ledger.',
        ];
    }
    public function deleteCampaign(int $id, ?int $wsId = null): void
    {
        $n = DB::table('campaigns')->where('id', $id)->when($wsId !== null, fn($q) => $q->where('workspace_id', $wsId))->update(['deleted_at' => now()]);
        if ($wsId !== null && $n === 0) throw new \RuntimeException('Campaign not found');
    }

    // ═══════════════════════════════════════════════════════
    // TEMPLATES
    // ═══════════════════════════════════════════════════════

    public function createTemplate(int $wsId, array $data): int
    {
        return DB::table('email_templates')->insertGetId([
            'workspace_id' => $wsId,
            'name' => $data['name'] ?? 'Untitled Template',
            'category' => $data['category'] ?? 'general',
            'subject' => $data['subject'] ?? '',
            'body_html' => $data['body_html'] ?? '',
            'variables_json' => json_encode($data['variables'] ?? ['{{name}}', '{{company}}', '{{unsubscribe}}']),
            'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function listTemplates(int $wsId): array
    {
        return DB::table('email_templates')
            ->where(fn($q) => $q->where('workspace_id', $wsId)->orWhere('is_system', true))
            ->orderByDesc('created_at')->get()->toArray();
    }

    public function getTemplate(int $id): ?object
    {
        return DB::table('email_templates')->where('id', $id)->first();
    }

    public function updateTemplate(int $id, array $data): array
    {
        $update = array_intersect_key($data, array_flip(['name', 'category', 'subject', 'body_html']));
        if (isset($data['variables'])) {
            $update['variables_json'] = json_encode($data['variables']);
        }
        $update['updated_at'] = now();
        DB::table('email_templates')->where('id', $id)->update($update);
        return ['updated' => true];
    }

    public function deleteTemplate(int $id): bool
    {
        // Protect system templates from deletion
        $row = DB::table('email_templates')->where('id', $id)->first();
        if (!$row) return false;
        if ($row->is_system) return false;
        DB::table('email_templates')->where('id', $id)->delete();
        return true;
    }

    // ═══════════════════════════════════════════════════════
    // EMAIL SETTINGS
    // ═══════════════════════════════════════════════════════

    /*
     | EM-7 (2026-08-13) — getEmailSettings()/updateEmailSettings() REMOVED.
     |
     | updateEmailSettings() wrote POSTMARK_TOKEN, MAIL_MAILER, MAIL_FROM_ADDRESS
     | and MAIL_FROM_NAME straight into the platform's .env, then ran
     | config:clear. Its route carried `auth.jwt` and nothing else — no admin
     | check, no workspace-owner check — so ANY authenticated user could
     | substitute the provider credential that every outbound message on the
     | platform depends on, password resets included, and read them all.
     |
     | It also read the token back through getEmailSettings(), which was the last
     | place outside the provider boundary that touched POSTMARK_TOKEN at all.
     |
     | Nothing in public/ or resources/ referenced either endpoint, so removal
     | costs no surface. If per-workspace email configuration is ever wanted, it
     | belongs behind admin authorisation, in a governed credential store — never
     | as a .env write from a workspace-scoped service.
     */
    // phase5-test-email-alias
    public function sendTestEmail(string $toEmail): array
    {
        // LAUNCH SCOPE (2026-07-20) — removed capability execution hard-stop.
        return ['success' => false, 'error' => 'Email marketing is not available in the current plan.', 'code' => 'LAUNCH_SCOPE_REMOVED_ACTION'];
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address'];
        }
        // EM-7: unreachable today (the Launch-Scope return above fires first),
        // converged anyway so that restoring the capability restores the GOVERNED
        // path rather than a call into a connector that no longer sends.
        try {
            $result = $this->dispatcher->send(new SendEmailCommand(
                purpose:    'campaign',
                recipients: [$toEmail],
                subject:    'LevelUp Growth — test email',
                html:       '<p>This is a test email from your LevelUp Growth marketing engine.</p>'
                          . '<p>If you received this, outbound email is configured correctly.</p>',
                metadata:   ['test_send' => true],
            ));
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success' => $result->accepted,
            'message' => $result->accepted
                ? 'Accepted by the provider — delivery is confirmed separately in the delivery ledger.'
                : ($result->failureCategory ?? 'refused'),
        ];
    }

    // ═══════════════════════════════════════════════════════
    // AUTOMATIONS
    // ═══════════════════════════════════════════════════════

    public function createAutomation(int $wsId, array $data): int
    {
        return DB::table('automations')->insertGetId([
            'workspace_id' => $wsId,
            'name' => $data['name'] ?? 'Untitled Automation',
            'status' => 'draft',
            'trigger_type' => $data['trigger_type'] ?? 'lead_created',
            'trigger_config_json' => json_encode($data['trigger_config'] ?? []),
            'steps_json' => json_encode($data['steps'] ?? []),
            'execution_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function listAutomations(int $wsId): array
    {
        return DB::table('automations')->where('workspace_id', $wsId)->orderByDesc('created_at')->get()->toArray();
    }

    public function toggleAutomation(int $id, string $status): void
    {
        DB::table('automations')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
    }

    /**
     * Trigger automation when an event occurs.
     *
     * PATCH 7 (2026-05-08): replaced the increment-and-exit stub with a real
     * action dispatcher. Walks the automation's steps_json and runs each
     * supported action (send_email / notify_owner / add_tag /
     * enroll_in_sequence). Per-step failures are logged and don't abort the
     * batch. Per-automation failures are caught so one bad automation
     * doesn't stop the others.
     */
    public function triggerAutomation(int $wsId, string $triggerType, array $context): int
    {
        $automations = DB::table('automations')->where('workspace_id', $wsId)
            ->where('trigger_type', $triggerType)->where('status', 'active')->get();

        $triggered = 0;
        foreach ($automations as $auto) {
            try {
                $this->executeAutomationActions($auto, $context);
                DB::table('automations')->where('id', $auto->id)->increment('execution_count');
                $triggered++;
            } catch (\Throwable $e) {
                Log::error("Automation {$auto->id} failed", [
                    'workspace_id' => $wsId,
                    'trigger'      => $triggerType,
                    'error'        => $e->getMessage(),
                ]);
            }
        }
        return $triggered;
    }

    /**
     * Walk the automation's steps_json and run each action.
     */
    private function executeAutomationActions(object $automation, array $context): void
    {
        $steps = json_decode($automation->steps_json ?? '[]', true) ?: [];
        if (! is_array($steps)) return;

        foreach ($steps as $i => $step) {
            $type = (string) ($step['type'] ?? '');
            try {
                match ($type) {
                    'send_email'         => $this->autoSendEmail($automation, $step, $context),
                    'notify_owner'       => $this->autoNotifyOwner($automation, $step, $context),
                    'add_tag'            => $this->autoAddTag($automation, $step, $context),
                    'enroll_in_sequence' => $this->autoEnrollInSequence($automation, $step, $context),
                    default              => Log::info("Automation action type '{$type}' not implemented", [
                        'automation_id' => $automation->id,
                        'step_index'    => $i,
                    ]),
                };
            } catch (\Throwable $e) {
                Log::warning("Automation step failed (continuing batch)", [
                    'automation_id' => $automation->id,
                    'step_index'    => $i,
                    'type'          => $type,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }

    private function autoSendEmail(object $automation, array $action, array $context): void
    {
        $email = (string) ($context['email'] ?? '');
        if ($email === '') return;

        $subject = (string) ($action['subject'] ?? 'Message from us');
        $body    = (string) ($action['body']    ?? '');

        // EM-7: this declared the purpose (EM-4) but then set ->from() anyway,
        // with an env fallback. Declaring intent and then choosing the identity
        // is not governance — whichever won, the registry was not deciding.
        // It now issues the canonical command and chooses nothing.
        try {
            $this->dispatcher->send(new SendEmailCommand(
                purpose:      'campaign',
                recipients:   [$email],
                subject:      $subject,
                template:     'emails.notification',
                templateData: [
                    'notification' => (object) [
                        'title'      => $subject,
                        'body'       => $body,
                        'action_url' => $action['action_url'] ?? null,
                    ],
                    'user' => (object) [
                        'email' => $email,
                        'name'  => $context['firstname'] ?? $context['name'] ?? null,
                    ],
                ],
                workspaceId:  isset($automation->workspace_id) ? (int) $automation->workspace_id : null,
                metadata:     ['automation_id' => isset($automation->id) ? (int) $automation->id : null],
            ));
        } catch (\Throwable $e) {
            // An automation step must not take the whole automation run down.
            Log::warning('marketing.automation.email_refused', [
                'automation_id' => $automation->id ?? null,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    private function autoNotifyOwner(object $automation, array $action, array $context): void
    {
        $owner = DB::table('workspace_users')
            ->where('workspace_id', $automation->workspace_id)
            ->where('role', 'owner')
            ->first();
        if (! $owner) return;

        $notif = app(\App\Core\Notifications\NotificationService::class);
        $notif->dispatch(
            type:        \App\Core\Notifications\NotificationTypes::SYSTEM_ADMIN_BROADCAST,
            userId:      (int) $owner->user_id,
            title:       (string) ($action['title'] ?? 'Automation triggered'),
            workspaceId: (int) $automation->workspace_id,
            body:        (string) ($action['body']  ?? "Automation '{$automation->name}' fired."),
            data:        $context,
            severity:    'info'
        );
    }

    private function autoAddTag(object $automation, array $action, array $context): void
    {
        $contactId = (int) ($context['contact_id'] ?? 0);
        $newTag    = (string) ($action['tag'] ?? '');
        if ($contactId <= 0 || $newTag === '') return;

        $contact = DB::table('contacts')->where('id', $contactId)->first();
        if (! $contact) return;

        $tags = json_decode($contact->tags ?? '[]', true);
        if (! is_array($tags)) $tags = [];

        if (! in_array($newTag, $tags, true)) {
            $tags[] = $newTag;
            DB::table('contacts')->where('id', $contactId)->update([
                'tags'       => json_encode(array_values($tags)),
                'updated_at' => now(),
            ]);
        }
    }

    private function autoEnrollInSequence(object $automation, array $action, array $context): void
    {
        $sequenceId = (int) ($action['sequence_id'] ?? 0);
        $contactId  = (int) ($context['contact_id'] ?? 0);
        if ($sequenceId <= 0 || $contactId <= 0) return;

        // Verify sequence belongs to this workspace + is active.
        $seq = DB::table('sequences')
            ->where('id', $sequenceId)
            ->where('workspace_id', $automation->workspace_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();
        if (! $seq) return;

        // Insert-or-ignore enrollment (idempotency anchor: ws + seq + contact unique).
        DB::table('sequence_enrollments')->insertOrIgnore([
            'workspace_id'       => $automation->workspace_id,
            'sequence_id'        => $sequenceId,
            'contact_id'         => $contactId,
            'enrolled_at'        => now(),
            'current_step_order' => 1,
            'status'             => 'active',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // AI GENERATION
    // ═══════════════════════════════════════════════════════

    /**
     * REFACTORED 2026-04-12 (Phase 2L MKT1 / doc 14): now routes through
     * RuntimeClient::aiRun('email_generation', ...) instead of direct
     * DeepSeekConnector. Hands vs brain pattern: runtime generates, Laravel
     * persists.
     */
    public function aiGenerateCampaign(int $wsId, array $params): array
    {
        $goal     = $params['goal'] ?? 'promote our services';
        $audience = $params['audience'] ?? 'existing leads';
        $type     = $params['type'] ?? 'email';

        // ── Creative blueprint (still routes through CreativeService for R5) ─
        $bp    = $this->blueprint($wsId, 'email', [
            'goal'         => $goal,
            'segment'      => $audience,
            'campaign_name'=> $params['campaign_name'] ?? $goal,
        ]);
        $bpCtx = $this->blueprintContext($bp);
        $subjectAngle = $bp['subject_line_angle'] ?? null;
        $structure    = is_array($bp['structure'] ?? null) ? implode(', ', $bp['structure']) : ($bp['structure'] ?? null);
        // ───────────────────────────────────────────────────────────────────

        $context = array_filter([
            'goal'           => $goal,
            'audience'       => $audience,
            'campaign_type'  => $type,
            'brand_voice'    => "the brand's own marketing voice", // launch-scope 2026-07-20: no removed-agent persona (email marketing is kernel-denied at launch)
            'brand_context'  => $bpCtx ?: null,
            'subject_angle'  => $subjectAngle,
            'email_structure'=> $structure,
            'business'       => !empty($params['context']) ? json_encode($params['context']) : null,
        ], fn($v) => $v !== null && $v !== '');

        $userPrompt = "Generate a {$type} campaign.\n"
                    . "Goal: {$goal}\n"
                    . "Audience: {$audience}\n"
                    . "Output as JSON: {\"subject\":\"...\",\"body_html\":\"...\",\"suggested_name\":\"...\"}";

        $result = $this->runtime->aiRun('email_generation', $userPrompt, $context, 1000);

        // Try to parse the runtime's text response as JSON
        $parsed = null;
        if ($result['success'] && !empty($result['text'])) {
            $maybe = json_decode($result['text'], true);
            if (is_array($maybe)) $parsed = $maybe;
        }

        if ($result['success'] && $parsed) {
            $campaign = $this->createCampaign($wsId, [
                'name'      => $parsed['suggested_name'] ?? "AI: {$goal}",
                'type'      => $type,
                'subject'   => $parsed['subject']   ?? '',
                'body_html' => $parsed['body_html'] ?? '',
            ]);
            return array_merge($campaign, ['ai_generated' => true, 'source' => 'runtime']);
        }

        // Persist the raw output as a campaign even if JSON parsing failed
        if ($result['success'] && !empty($result['text'])) {
            $campaign = $this->createCampaign($wsId, [
                'name'      => "AI: {$goal}",
                'type'      => $type,
                'subject'   => $goal,
                'body_html' => $result['text'],
            ]);
            return array_merge($campaign, [
                'ai_generated' => true,
                'source'       => 'runtime',
                'note'         => 'JSON parse failed — stored raw text as body_html',
            ]);
        }

        return [
            'error' => $result['error'] ?? 'AI generation failed',
            'ai_generated' => false,
            'source' => 'runtime',
        ];
    }

    // ═══════════════════════════════════════════════════════
    // DASHBOARD
    // ═══════════════════════════════════════════════════════

    public function getDashboard(int $wsId): array
    {
        $campaigns = DB::table('campaigns')->where('workspace_id', $wsId)->whereNull('deleted_at');
        $sent = (clone $campaigns)->where('status', 'sent')->get();

        $totalSent = 0; $totalOpened = 0; $totalClicked = 0;
        foreach ($sent as $c) {
            $stats = json_decode($c->stats_json ?? '{}', true);
            $totalSent += $stats['sent'] ?? 0;
            $totalOpened += $stats['opened'] ?? 0;
            $totalClicked += $stats['clicked'] ?? 0;
        }

        return [
            'total_campaigns' => (clone $campaigns)->count(),
            'sent_campaigns' => $sent->count(),
            'draft_campaigns' => (clone $campaigns)->where('status', 'draft')->count(),
            'scheduled_campaigns' => (clone $campaigns)->where('status', 'scheduled')->count(),
            'total_emails_sent' => $totalSent,
            'open_rate' => $totalSent > 0 ? round(($totalOpened / $totalSent) * 100, 1) : 0,
            'click_rate' => $totalSent > 0 ? round(($totalClicked / $totalSent) * 100, 1) : 0,
            'active_automations' => DB::table('automations')->where('workspace_id', $wsId)->where('status', 'active')->count(),
            'templates' => DB::table('email_templates')->where('workspace_id', $wsId)->count(),
            'recent' => (clone $campaigns)->orderByDesc('updated_at')->limit(5)->get(),
        ];
    }

    // ═══════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════

    private function applyMergeTags(string $html, string $email, int $wsId): string
    {
        $lead = DB::table('leads')->where('workspace_id', $wsId)->where('email', $email)->first();
        $replacements = [
            '{{name}}' => $lead->name ?? 'there',
            '{{email}}' => $email,
            '{{company}}' => $lead->company ?? '',
            '{{first_name}}' => explode(' ', $lead->name ?? 'there')[0],
            '{{unsubscribe}}' => '<a href="#">Unsubscribe</a>',
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }
}
