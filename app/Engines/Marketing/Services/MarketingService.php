<?php

namespace App\Engines\Marketing\Services;

use App\Connectors\EmailConnector;
use App\Connectors\DeepSeekConnector;
use App\Core\Intelligence\EngineIntelligenceService;
use App\Engines\Creative\Services\CreativeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MarketingService
{
    public function __construct(
        private EmailConnector            $email,
        private DeepSeekConnector         $llm,
        private EngineIntelligenceService  $engineIntel,
        private CreativeService            $creative,
        private \App\Connectors\RuntimeClient $runtime,
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

    public function updateCampaign(int $id, array $data): array
    {
        $update = array_intersect_key($data, array_flip(['name', 'subject', 'body_html', 'type']));
        if (isset($data['recipients'])) $update['recipients_json'] = json_encode($data['recipients']);
        if (isset($data['template_id'])) $update['template_id'] = $data['template_id'];
        $update['updated_at'] = now();
        DB::table('campaigns')->where('id', $id)->update($update);
        return ['updated' => true];
    }

    public function scheduleCampaign(int $id, string $scheduledAt): array
    {
        DB::table('campaigns')->where('id', $id)->update([
            'status' => 'scheduled', 'scheduled_at' => $scheduledAt, 'updated_at' => now(),
        ]);
        return ['scheduled' => true, 'scheduled_at' => $scheduledAt];
    }

    public function sendCampaign(int $wsId, int $id): array
    {
        $campaign = DB::table('campaigns')->where('id', $id)->first();
        if (!$campaign) throw new \RuntimeException("Campaign not found");
        if ($campaign->status === 'sent') throw new \RuntimeException("Campaign already sent");

        $recipients = json_decode($campaign->recipients_json ?? '[]', true);
        if (empty($recipients)) {
            // Auto-populate from CRM leads with email
            $recipients = DB::table('leads')->where('workspace_id', $wsId)
                ->whereNotNull('email')->where('email', '!=', '')
                ->pluck('email')->toArray();
        }

        if (empty($recipients)) throw new \RuntimeException("No recipients");

        DB::table('campaigns')->where('id', $id)->update(['status' => 'sending', 'updated_at' => now()]);

        $sent = 0; $failed = 0;
        foreach ($recipients as $email) {
            $body = $this->applyMergeTags($campaign->body_html ?? '', $email, $wsId);
            try {
                $this->email->send($email, $campaign->subject ?? '', $body);
                $sent++;
            } catch (\Throwable $e) { $failed++; }
        }

        DB::table('campaigns')->where('id', $id)->update([
            'status' => 'sent', 'sent_at' => now(),
            'stats_json' => json_encode(['sent' => $sent, 'delivered' => $sent, 'opened' => 0, 'clicked' => 0, 'bounced' => $failed]),
            'updated_at' => now(),
        ]);

        $this->engineIntel->recordToolUsage('marketing', 'send_campaign', $failed === 0 ? 0.9 : 0.5);
        return ['sent' => $sent, 'failed' => $failed, 'total' => count($recipients)];
    }

    public function deleteCampaign(int $id): void
    {
        DB::table('campaigns')->where('id', $id)->update(['deleted_at' => now()]);
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

    public function getEmailSettings(): array
    {
        $token   = (string) env('POSTMARK_TOKEN', '');
        $masked  = $token !== '' ? (str_repeat('*', max(0, strlen($token) - 4)) . substr($token, -4)) : '';
        return [
            'configured'     => $token !== '',
            'driver'         => (string) env('EMAIL_CONNECTOR_DRIVER', env('MAIL_MAILER', 'smtp')),
            'from_email'     => (string) env('MAIL_FROM_ADDRESS', ''),
            'from_name'      => (string) env('MAIL_FROM_NAME', ''),
            'postmark_token' => $masked,
        ];
    }

    public function updateEmailSettings(array $data): array
    {
        // phase5-settings-aliases — accept legacy field names from existing UI
        if (array_key_exists('postmark_api_key', $data) && !array_key_exists('postmark_token', $data)) {
            $data['postmark_token'] = $data['postmark_api_key'];
        }
        if (array_key_exists('sender_email', $data) && !array_key_exists('from_email', $data)) {
            $data['from_email'] = $data['sender_email'];
        }
        if (array_key_exists('sender_name', $data) && !array_key_exists('from_name', $data)) {
            $data['from_name'] = $data['sender_name'];
        }
        $path = base_path('.env');
        if (!is_writable($path)) {
            return ['success' => false, 'error' => '.env not writable'];
        }
        $env = file_get_contents($path);

        $map = [];
        if (array_key_exists('driver', $data) && $data['driver'] !== '') {
            $map['MAIL_MAILER']            = (string) $data['driver'];
            $map['EMAIL_CONNECTOR_DRIVER'] = (string) $data['driver'];
        }
        if (array_key_exists('postmark_token', $data) && !str_contains((string) $data['postmark_token'], '*')) {
            // Only accept a real token — ignore the masked form we return on GET
            $map['POSTMARK_TOKEN'] = (string) $data['postmark_token'];
        }
        if (array_key_exists('from_email', $data) && $data['from_email'] !== '') {
            $map['MAIL_FROM_ADDRESS'] = (string) $data['from_email'];
        }
        if (array_key_exists('from_name', $data) && $data['from_name'] !== '') {
            $map['MAIL_FROM_NAME'] = '"' . str_replace('"', '\"', (string) $data['from_name']) . '"';
        }

        foreach ($map as $key => $val) {
            $line = $key . '=' . $val;
            if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $env)) {
                $env = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $line, $env);
            } else {
                $env .= (str_ends_with($env, "
") ? '' : "
") . $line . "
";
            }
        }
        file_put_contents($path, $env);

        // Clear cached config so subsequent requests see the new values
        try { \Illuminate\Support\Facades\Artisan::call('config:clear'); } catch (\Throwable $e) {}

        return ['success' => true, 'configured' => !empty($map['POSTMARK_TOKEN'] ?? env('POSTMARK_TOKEN'))];
    }

    // phase5-test-email-alias
    public function sendTestEmail(string $toEmail): array
    {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address'];
        }
        $result = $this->email->execute('send_email', [
            'to'      => $toEmail,
            'subject' => 'LevelUp Growth — test email',
            'body'    => '<p>This is a test email from your LevelUp Growth marketing engine.</p>'
                       . '<p>If you received this, Postmark/SMTP is configured correctly.</p>',
            'html'    => true,
        ]);
        return [
            'success' => (bool) ($result['success'] ?? false),
            'message' => $result['message'] ?? ($result['error'] ?? 'unknown'),
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

        Mail::send(
            'emails.notification',
            [
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
            function ($m) use ($email, $context, $subject) {
                $m->to($email, $context['firstname'] ?? null)
                  ->subject($subject)
                  ->from(
                      config('mail.from.address', env('MAIL_FROM_ADDRESS', 'hello@levelupgrowth.io')),
                      config('mail.from.name',    env('MAIL_FROM_NAME',    'LevelUp Growth'))
                  );
            }
        );
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
            'brand_voice'    => 'Maya — email marketing specialist',
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
