<?php

namespace App\Core\Orchestration;

use App\Core\Billing\CreditService;
use App\Core\Intelligence\GlobalKnowledgeService;
use App\Core\Notifications\NotificationService;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Proactive Strategy Engine — Sarah initiates, user consents, agents execute.
 *
 * HARD GUARDRAIL:
 *   Sarah's ONLY autonomous action = send a TEMPLATE message (zero credits).
 *   Every LLM call, every scan, every generation = costs credits.
 *   Credits must be ESTIMATED → SHOWN TO USER → APPROVED → RESERVED → then spent.
 *   No exceptions. No "free research." No surprise charges.
 *
 * Flow:
 *   1. Onboarding completes → Sarah sends cost estimate (TEMPLATE, 0 credits)
 *   2. User reviews estimated cost → approves or declines
 *   3. Credits reserved upfront → meeting runs → credits committed
 *   4. Any execution plan → cost breakdown shown → approved → reserved → executed
 *
 * Credit costs (defined in capability map, mirroring CREDIT888):
 *   - Strategy meeting: ~8 credits (6 agent contributions + synthesis)
 *   - SERP analysis: 1 credit
 *   - Deep audit: 3 credits
 *   - AI report: 2 credits
 *   - Write article: 3 credits
 *   - Generate image: 2 credits
 *   - Generate video: 5 credits
 */
class ProactiveStrategyEngine
{
    /** A website exists and has no content. Raised once per website. */
    public const TYPE_WEBSITE_LAUNCH = 'website_launch_plan';

    // Credit costs per action (matches CREDIT888 capability map)
    private const CREDIT_COSTS = [
        'strategy_meeting' => 8,
        'serp_analysis' => 1,
        'deep_audit' => 3,
        'ai_report' => 2,
        'write_article' => 3,
        'improve_draft' => 2,
        'generate_outline' => 1,
        'generate_image' => 2,
        'generate_video' => 5,
        'social_create_post' => 0,  // Manual creation is free
        'social_ai_post' => 1,     // AI-generated post costs 1
        'create_campaign' => 0,     // Creation is free, sending costs
        'send_campaign' => 2,
    ];

    // Max tokens per agent per meeting round
    private const AGENT_TOKEN_CAP = 200;

    public function __construct(
        private CreditService $credits,
        private AgentMeetingEngine $meetings,
        private SarahOrchestrator $sarah,
        private GlobalKnowledgeService $globalKnowledge,
        private NotificationService $notifications,
        private \App\Core\Agents\AgentMessageService $agentMessages,
    ) {}

    /**
     * Triggered when workspace completes onboarding.
     * Sarah sends a TEMPLATE cost estimate — ZERO credits.
     * Nothing runs until user approves.
     */
    public function onOnboardingComplete(int $wsId, int $userId): array
    {
        $workspace = Workspace::findOrFail($wsId);
        $balance = $this->credits->getBalance($wsId);

        // Estimate what the initial strategy session will cost
        $estimate = $this->estimateInitialSessionCost($workspace);

        // Store the pending proposal (not executed yet)
        $proposalId = DB::table('strategy_proposals')->insertGetId([
            'workspace_id' => $wsId,
            'type' => 'initial_strategy',
            'title' => 'Initial Marketing Strategy Session',
            'description' => "Strategy meeting with your AI marketing team to create a comprehensive plan for {$workspace->business_name}",
            'status' => 'pending_approval',
            'cost_breakdown_json' => json_encode($estimate['breakdown']),
            'total_credits' => $estimate['total'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Send TEMPLATE notification — this is Sarah's ONLY free action
        $proposalMsg = $this->buildCostEstimateMessage($workspace, $estimate, $balance);
        $this->notifications->send($wsId, 'in_app', 'sarah_proposal', [
            'user_id' => $userId,
            'message' => $proposalMsg,
        ]);

        // Wave 6 (2026-05-18). Also post into Sarah's chat thread so the
        // unified messages floater + agent profile + Messages page all
        // see the cost estimate. Per AI Assistant Operating Rules — AI
        // surfaces work through the platform, not in the background.
        $this->agentMessages->postAsAgent($wsId, 'sarah', $proposalMsg, [
            'notification_type' => 'sarah_proposal',
            'proposal_id'       => $proposalId,
            'action_link'       => '/app/strategy/' . $proposalId,
        ]);

        return [
            'proposal_id' => $proposalId,
            'status' => 'pending_approval',
            'estimated_credits' => $estimate['total'],
            'balance' => $balance['available'] ?? 0,
            'message' => 'Cost estimate sent. Waiting for user approval.',
        ];
    }

    /**
     * User approves a proposal. Routes by proposal.type to the correct
     * execution path (2026-05-24 FIX 55):
     *   - discovery_strategy_meeting / monthly_30_day_plan → start a meeting
     *   - daily_action_*                                   → create Task(s)
     *   - goal_pivot / celebrate_goal_achieved / budget_*  → mark approved
     *                                                        + chat ack
     *                                                        (no execution)
     */
    public function approveProposal(int $wsId, int $userId, int $proposalId): array
    {
        $proposal = DB::table('strategy_proposals')->where('id', $proposalId)->where('workspace_id', $wsId)->first();
        if (!$proposal) throw new \RuntimeException('Proposal not found');
        if ($proposal->status !== 'pending_approval') {
            // RISK-0142 (2026-09-07, DEC-0040): a second approval (double click, a chat "yes" after the route, a
            // retry) used to throw and surface as a 500. It is a no-op with a name: nothing is created or charged again.
            return ['success' => false, 'code' => 'ALREADY_PROCESSED', 'status' => (string) $proposal->status,
                    'error' => 'This proposal has already been processed. Nothing was created or charged again.'];
        }

        $type = (string) $proposal->type;

        // 2026-07-07 — publishing is the ONE human-gated action in the
        // bounded-autonomy model. Approving a publish_ready proposal makes the
        // workspace's ready drafts live (0 credits — generation was already paid).
        if ($type === 'publish_ready') {
            return $this->executePublishReady($wsId, $proposalId);
        }

        // ── SARAH888 — a chat action the owner was asked to approve ────────
        // Sarah's chat path used to create the task immediately and mark it
        // requires_approval, so the row (with its credit cost and queue
        // position) existed before the owner had said anything. The enterprise
        // contract is that an ASK_FIRST turn creates nothing until a later
        // explicit authorization, so the proposal now carries the intended
        // create payload and the task is built HERE, at the moment of consent.
        //
        // The payload is replayed exactly as proposed. It is never
        // reconstructed from the conversation, so what runs is what the owner
        // saw and agreed to — not a fresh interpretation of their words.
        if ($type === \App\Core\Sarah888\ChatActionProposal::TYPE) {
            $payload = app(\App\Core\Sarah888\ChatActionProposal::class)->payloadFor($proposal);
            if (!$payload) {
                return ['success' => false, 'error' => 'proposal_payload_missing',
                        'message' => 'This proposal has no recorded action to run.'];
            }

            $cost = (int) ($payload['credit_cost'] ?? 0);
            if ($cost > 0 && ! $this->credits->hasBalance($wsId, $cost)) {
                DB::table('strategy_proposals')->where('id', $proposalId)
                    ->update(['status' => 'insufficient_credits', 'updated_at' => now()]);
                return ['success' => false, 'code' => 'NO_CREDITS',
                        'error' => "Insufficient credits. Required: {$cost}"];
            }

            // Consent has been given, so the task may now be created and run.
            // The marker below is what tells the creation gate this is the
            // authorised second turn rather than a fresh unauthorised request;
            // without it the gate would correctly refuse its own proposal.
            // CONTENT-1 (2026-08-29): a publish offer bound to a same-turn write task publishes THAT
            // task's article. If the article is not written yet, say so and keep the offer pending.
            $__boundTaskId = (int) ($payload['payload']['publish_of_task_id'] ?? 0);
            if ($__boundTaskId > 0 && (($payload['action'] ?? '') === 'publish_article')) {
                $__wt = DB::table('tasks')->where('id', $__boundTaskId)->where('workspace_id', $wsId)->first();
                $__wr = $__wt ? json_decode((string) $__wt->result_json, true) : null;
                $__aid = (int) ($__wr['data']['article_id'] ?? $__wr['article_id'] ?? 0);
                if ($__aid <= 0 && $__wt && $__wt->status === 'completed') {
                    $__aid = (int) (DB::table('articles')->where('workspace_id', $wsId)->where('task_id', $__boundTaskId)->value('id') ?? 0);
                }
                if ($__aid <= 0) {
                    return ['success' => false, 'code' => 'ARTICLE_NOT_READY',
                            'error' => 'The article from that request is not written yet — I will publish it once it is finished. Nothing has been published.'];
                }
                $payload['payload']['article_id'] = $__aid;
                \Illuminate\Support\Facades\Log::info('[Sarah888] publish offer bound to the article it was made for', [
                    'ws' => $wsId, 'proposal' => $proposalId, 'write_task' => $__boundTaskId, 'article_id' => $__aid,
                ]);
            }

            $payload['authorized_by_proposal'] = $proposalId;
            $payload['requires_approval']      = false;
            $payload['auto_approve']           = true;
            // CONTENT-1 (2026-08-29): TaskService's publish category is a HARD gate except when the
            // caller captured the owner's explicit confirmation for an ARTICLE publish
            // (user_confirmed, 2026-07-23 Boss decision). This IS that confirmation — the owner's own
            // "yes" bound to the offer they were shown. Without the flag the approved publish landed
            // in the Review Queue a second time (task 31842 / approval 12318) and never ran.
            if (($payload['action'] ?? '') === 'publish_article') $payload['user_confirmed'] = true;

            // Audit trail: which authorization produced this task. The flag
            // above is a control signal for the creation gate and never
            // reaches payload_json, so without this the row cannot say who
            // approved it or against which offer.
            if (!isset($payload['payload']) || !is_array($payload['payload'])) $payload['payload'] = [];
            $payload['payload']['authorized_by_proposal'] = $proposalId;

            try {
                $task = app(\App\Core\TaskSystem\TaskService::class)->create($wsId, $payload);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Sarah888] approved chat action failed to create', [
                    'ws' => $wsId, 'proposal' => $proposalId, 'error' => $e->getMessage(),
                ]);
                return ['success' => false, 'error' => 'task_creation_failed',
                        'message' => $e->getMessage()];
            }

            DB::table('strategy_proposals')->where('id', $proposalId)->update([
                'status' => 'approved', 'approved_at' => now(), 'updated_at' => now(),
            ]);
            // CONTENT-1: the approval row the offer created stayed 'pending' forever (approval 12316) and
            // showed in the Review Queue after the owner had already said yes in chat. Close it.
            DB::table('approvals')->where('proposal_id', $proposalId)->where('status', 'pending')->update([
                'status' => 'approved', 'decision_by' => $userId > 0 ? $userId : null, 'decided_at' => now(),
                'decision_note' => 'approved in chat', 'task_id' => $task->id, 'updated_at' => now(),
            ]);

            return ['success' => true, 'type' => $type, 'task_id' => $task->id,
                    'credits_used' => $cost, 'message' => 'Approved and queued.'];
        }

        // Informational types: no credits, no execution. Just ack.
        if (in_array($type, ['goal_pivot', 'celebrate_goal_achieved', 'budget_warning', 'budget_critical', 'budget_alert'], true)) {
            DB::table('strategy_proposals')->where('id', $proposalId)->update([
                'status'      => 'acknowledged',
                'approved_at' => now(),
                'updated_at'  => now(),
            ]);
            return [
                'success'      => true,
                'type'         => $type,
                'credits_used' => 0,
                'message'      => 'Acknowledged.',
            ];
        }

        $totalCredits = (int) $proposal->total_credits;

        // 2026-07-07 — FIX double-charge. daily_action_* / weekly_pivot_* proposals
        // SPAWN Tasks that carry their own credit_cost and are committed ONCE by
        // the Orchestrator when they run. Reserving+committing at the proposal
        // level too billed the same work twice (traced: 6cr charged for a 3cr
        // article). For these task-spawning types we ONLY pre-check balance and
        // let the task be the single charge. Non-spawning types (strategy
        // meetings) still reserve/commit here.
        $spawnsTasks = str_starts_with($type, 'daily_action_') || str_starts_with($type, 'weekly_pivot_');

        if ($totalCredits > 0 && ! $this->credits->hasBalance($wsId, $totalCredits)) {
            DB::table('strategy_proposals')->where('id', $proposalId)->update(['status' => 'insufficient_credits', 'updated_at' => now()]);
            return [
                'success' => false,
                'error'   => "Insufficient credits. Required: {$totalCredits}, available: " . ($this->credits->getBalance($wsId)['available'] ?? 0),
                'code'    => 'NO_CREDITS',
            ];
        }

        $reservationRef = (! $spawnsTasks && $totalCredits > 0)
            ? $this->credits->reserve($wsId, $totalCredits, "proposal:{$proposalId}")
            : null;

        DB::table('strategy_proposals')->where('id', $proposalId)->update([
            'status'          => 'approved',
            'approved_at'     => now(),
            'reservation_ref' => $reservationRef,
            'updated_at'      => now(),
        ]);

        // Dispatch by type
        try {
            if ($spawnsTasks) {
                $taskIds = $this->dispatchDailyAction($wsId, $userId, $proposal);
                // No proposal-level commit — the spawned tasks self-charge in the
                // Orchestrator (single charge). $reservationRef is null here.
                DB::table('strategy_proposals')->where('id', $proposalId)->update([
                    'status'     => 'executing',
                    'updated_at' => now(),
                ]);
                return [
                    'success'      => true,
                    'type'         => $type,
                    'task_ids'     => $taskIds,
                    'credits_used' => $totalCredits,
                    'message'      => count($taskIds) === 1
                        ? 'Task created — executing now.'
                        : count($taskIds) . ' tasks created — executing now.',
                ];
            }

            // Default: strategy meeting (covers discovery_strategy_meeting,
            // monthly_30_day_plan, and any legacy/unknown type that should
            // still trigger an agent meeting).
            $workspace = Workspace::find($wsId);
            $goal = $this->buildOnboardingGoal($workspace);
            // RISK-0142 (a) (2026-09-07, DEC-0040): the proposal's reservation travels INTO the meeting and is
            // committed ONCE, by completeMeeting(), when the session finishes (or the customer ends it). It used to
            // be committed here — before a single agent had spoken — and the meeting then reserved a further 4 on
            // its own: 12 held for an "8 credit" session that had not run (EV-0917, RISK-0142).
            $meeting = $this->meetings->startMeeting($wsId, $userId, $goal, [], $reservationRef, $reservationRef !== null ? $totalCredits : 0);
            DB::table('strategy_proposals')->where('id', $proposalId)->update([
                'status'     => 'executing',
                'meeting_id' => $meeting['meeting_id'] ?? null,
                'updated_at' => now(),
            ]);
            return [
                'success'      => true,
                'type'         => $type,
                'meeting_id'   => $meeting['meeting_id'] ?? null,
                'credits_used' => $totalCredits,
                'message'      => 'Strategy session started. Your team is collaborating now.',
            ];
        } catch (\Throwable $e) {
            if ($reservationRef !== null) {
                try { $this->credits->release($wsId, $reservationRef); } catch (\Throwable $ignored) {}
            }
            DB::table('strategy_proposals')->where('id', $proposalId)->update(['status' => 'failed', 'updated_at' => now()]);
            Log::error('[ProactiveStrategy] approve dispatch failed', [
                'proposal_id' => $proposalId, 'type' => $type, 'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 2026-05-24 FIX 55 — create the Task(s) for a daily_action_* proposal.
     * Mirrors the proven SeoAssistantService::execBatchArticles pattern.
     * Returns the list of created task IDs (parent first).
     */
    /**
     * 2026-07-07 — publish the workspace's ready drafts (own-blog only:
     * wp_post_id NULL, reversible). Publishing is the single human-gated step in
     * the bounded-autonomy model. Zero credits — the generation was already paid.
     */
    private function executePublishReady(int $wsId, int $proposalId): array
    {
        $drafts = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('status', 'draft')
            ->whereNull('wp_post_id')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->limit(50)
            ->pluck('id');

        $writeSvc = app(\App\Engines\Write\Services\WriteService::class);
        $published = 0;
        foreach ($drafts as $id) {
            try {
                $writeSvc->updateArticle((int) $id, ['status' => 'published']);
                $published++;
            } catch (\Throwable $e) {
                Log::warning('[ProactiveStrategy] publish_ready failed for article', [
                    'workspace_id' => $wsId, 'article_id' => $id, 'error' => $e->getMessage(),
                ]);
            }
        }

        DB::table('strategy_proposals')->where('id', $proposalId)->update([
            'status'      => 'executing',
            'approved_at' => now(),
            'updated_at'  => now(),
        ]);

        return [
            'success'      => true,
            'type'         => 'publish_ready',
            'published'    => $published,
            'credits_used' => 0,
            'message'      => $published === 1 ? '1 article is now live.' : "{$published} articles are now live.",
        ];
    }

    private function dispatchDailyAction(int $wsId, int $userId, object $proposal): array
    {
        $slug = preg_replace('/^(daily_action_|weekly_pivot_)/', '', (string) $proposal->type);
        $title = (string) $proposal->title;
        $taskSvc = app(\App\Core\TaskSystem\TaskService::class);
        $ids = [];

        // Article: parent write_article + 3 children (meta, link suggestions, insert).
        // Image/AEO skipped here — proposals don't yet carry those flags.
        if ($slug === 'write_article') {
            // b24 (2026-07-24) — A PROPOSAL TITLE IS NOT AN ARTICLE TOPIC.
            //
            // Both 'title' and 'topic' below used to be the proposal title
            // verbatim. Proposal titles are ACTION descriptions, so the writer
            // was told to write an article *about the instruction*. Live result
            // on customer blogs:
            //
            //   Chef Red (private chef)      → "Expand 7 Thin Pages to 800+
            //                                   Words: A Practical Guide" ×7
            //   AMG Global Travel & Tours    → "Optimize Published Articles
            //                                   with Focus Keywords"
            //
            // Off-brand SEO-housekeeping content, 1,200 words each, published
            // to real customer sites. Recover the actual subject; when there
            // isn't one, the proposal was mis-typed as write_article and must
            // not produce an article at all.
            $writeSvc = app(\App\Engines\Write\Services\WriteService::class);
            $subject  = $writeSvc->looksLikeInstruction($title)
                ? $writeSvc->subjectFromInstruction($title)
                : $title;

            if ($subject === null || trim((string) $subject) === '') {
                Log::warning('[Proactive] write_article proposal has no real topic — skipped', [
                    'workspace_id' => $wsId,
                    'proposal_id'  => $proposal->id,
                    'title'        => $title,
                ]);
                DB::table('strategy_proposals')->where('id', $proposal->id)->update([
                    'status'     => 'superseded',
                    'updated_at' => now(),
                ]);
                return $ids;
            }

            $parent = $taskSvc->create($wsId, [
                'engine'            => 'write',
                'action'            => 'write_article',
                'source'            => 'agent',
                'priority'          => 'normal',
                'assigned_agents'   => ['priya'],
                'auto_approve'      => true,
                'requires_approval' => false,
                'credit_cost'       => (int) $proposal->total_credits,
                'payload'           => [
                    // b24 — the recovered SUBJECT, never the instruction.
                    'title'        => $subject,
                    'topic'        => $subject,
                    'audience'     => 'small business owners',
                    'tone'         => 'professional yet warm',
                    'length'       => 1100,
                    'created_via'  => 'sarah_proposal',
                    'proposal_id'  => $proposal->id,
                ],
            ]);
            $ids[] = (int) $parent->id;
            $parent->update(['progress_message' => 'Sarah proposal — ' . mb_substr($title, 0, 60)]);

            foreach (['generate_meta' => 'write', 'link_suggestions' => 'seo', 'insert_link' => 'seo'] as $action => $engine) {
                $assignee = ($engine === 'seo') ? 'james' : 'priya';
                $child = $taskSvc->create($wsId, [
                    'engine'            => $engine,
                    'action'            => $action,
                    'source'            => 'agent',
                    'priority'          => 'normal',
                    'assigned_agents'   => [$assignee],
                    'parent_task_id'    => (int) $parent->id,
                    'auto_approve'      => true,
                    'requires_approval' => false,
                    'credit_cost'       => 0,
                    'payload'           => [
                        'title'       => "Sarah proposal: " . str_replace('_', ' ', $action),
                        'created_via' => 'sarah_proposal',
                        'proposal_id' => $proposal->id,
                    ],
                ]);
                $ids[] = (int) $child->id;
            }
            return $ids;
        }

        // ── 2026-06-30 Phase 0 — proposal→executor translation map ───────────
        // Proactive proposal slugs do NOT 1:1 match the Orchestrator's async
        // execution whitelist ("engine/action", Orchestrator.php). Before this
        // map, every non-SEO slug fell to the write/priya fallback and produced
        // a task whose "engine/action" wasn't whitelisted → it failed in the
        // worker ("Unknown … action"). So Sarah's multi-channel proposals
        // (send_email, social, campaign, audit, keyword research, unstick) were
        // dead on arrival. Each entry below maps a slug to a VERIFIED whitelisted
        // engine/action. 'action' overrides the task action when the slug name
        // differs from the real whitelisted action; otherwise the slug is used.
        $singleMap = [
            // already-correct SEO / content / image
            'generate_meta'         => ['engine' => 'write',     'agent' => 'priya'],
            'insert_link'           => ['engine' => 'seo',       'agent' => 'james'],
            'link_suggestions'      => ['engine' => 'seo',       'agent' => 'james'],
            'fix_orphans'           => ['engine' => 'seo',       'agent' => 'james'],
            'generate_image'        => ['engine' => 'creative',  'agent' => 'priya'],
            'improve_draft'         => ['engine' => 'write',     'agent' => 'priya'],
            'deep_audit'            => ['engine' => 'seo',       'agent' => 'james'],
            'generate_links'        => ['engine' => 'seo',       'agent' => 'james'],
            'keyword_research'      => ['engine' => 'seo',       'agent' => 'james'],
            // slug name ≠ whitelisted action → translate the action too
            'refresh_stale'         => ['engine' => 'write',     'agent' => 'priya',  'action' => 'improve_draft'],
            'expand_thin_pages'     => ['engine' => 'write',     'agent' => 'priya',  'action' => 'improve_draft'],
            'apply_link_suggestions'=> ['engine' => 'seo',       'agent' => 'james',  'action' => 'link_suggestions'],
            // LAUNCH SCOPE 2026-07-20 — removed capability executor mapping deleted (was: 'send_email'            => ['engine' => 'marketing', 'agent'...)
            // LAUNCH SCOPE 2026-07-20 — removed capability executor mapping deleted (was: 'create_campaign'       => ['engine' => 'marketing', 'agent'...)
            // LAUNCH SCOPE 2026-07-20 — removed capability executor mapping deleted (was: 'social_create_post'    => ['engine' => 'social',    'agent'...)
            'unstick_tasks'         => ['engine' => 'tasks',     'agent' => 'sarah',  'action' => 'retry_blocked'],
        ];

        // Unmapped slugs (e.g. goal_pivot, strategy_meeting, weekly_review) have
        // no async executor yet and still need their own wiring (GoalLifecycle /
        // meeting controller). Keep the legacy write/priya fallback but make it
        // OBSERVABLE so these surface in logs instead of silently failing.
        $cfg = $singleMap[$slug] ?? null;
        if ($cfg === null) {
            Log::warning('[ProactiveStrategy] proposal slug not mapped to a verified executor — using write/priya fallback (likely to fail in worker; needs dedicated wiring)', [
                'workspace_id' => $wsId, 'proposal_id' => $proposal->id, 'slug' => $slug,
            ]);
            $cfg = ['engine' => 'write', 'agent' => 'priya'];
        }

        $task = $taskSvc->create($wsId, [
            'engine'            => $cfg['engine'],
            'action'            => $cfg['action'] ?? $slug,
            'source'            => 'agent',
            'priority'          => 'normal',
            'assigned_agents'   => [$cfg['agent']],
            'auto_approve'      => true,
            'requires_approval' => false,
            'credit_cost'       => (int) $proposal->total_credits,
            'payload'           => [
                'title'        => $title,
                'description'  => (string) $proposal->description,
                'created_via'  => 'sarah_proposal',
                'proposal_id'  => $proposal->id,
            ],
        ]);
        $ids[] = (int) $task->id;
        return $ids;
    }

    /**
     * User declines the proposal. Zero credits spent.
     */
    public function declineProposal(int $wsId, int $proposalId): array
    {
        // RISK-0142 (2026-09-07, DEC-0040): only a proposal still waiting can be declined; declining one that is
        // already approved/executing/declined is a no-op with a name, never a silent status overwrite.
        $n = DB::table('strategy_proposals')->where('id', $proposalId)->where('workspace_id', $wsId)
            ->where('status', 'pending_approval')
            ->update(['status' => 'declined', 'updated_at' => now()]);
        if ($n === 0) {
            $st = (string) (DB::table('strategy_proposals')->where('id', $proposalId)->where('workspace_id', $wsId)->value('status') ?? 'missing');
            return ['success' => false, 'code' => 'ALREADY_PROCESSED', 'status' => $st, 'credits_used' => 0,
                    'message' => 'This proposal is not waiting for a decision.'];
        }

        return ['success' => true, 'credits_used' => 0, 'message' => 'Proposal declined. No credits were used.'];
    }

    /**
     * 2026-05-24 FIX 55 — Batch approve. Loops the per-proposal logic.
     * Each entry runs independently — one failing doesn't block the rest.
     * Returns per-id results + aggregate summary.
     */
    public function batchApprove(int $wsId, int $userId, array $ids): array
    {
        $results = [];
        $succeeded = 0; $failed = 0; $totalCredits = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            try {
                $r = $this->approveProposal($wsId, $userId, $id);
                $results[$id] = $r;
                if ($r['success'] ?? false) {
                    $succeeded++;
                    $totalCredits += (int) ($r['credits_used'] ?? 0);
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $results[$id] = ['success' => false, 'error' => $e->getMessage()];
                Log::warning('[ProactiveStrategy] batchApprove item failed', [
                    'workspace_id' => $wsId, 'proposal_id' => $id, 'error' => $e->getMessage(),
                ]);
            }
        }
        return [
            'success'       => $failed === 0,
            'results'       => $results,
            'summary'       => [
                'total'         => count($ids),
                'succeeded'     => $succeeded,
                'failed'        => $failed,
                'credits_used'  => $totalCredits,
            ],
        ];
    }

    /**
     * 2026-05-24 FIX 55 — Batch decline. Bulk DB update, single round-trip.
     */
    public function batchDecline(int $wsId, array $ids): array
    {
        $ids = array_map('intval', $ids);
        $count = DB::table('strategy_proposals')
            ->where('workspace_id', $wsId)
            ->whereIn('id', $ids)
            ->where('status', 'pending_approval')
            ->update(['status' => 'declined', 'updated_at' => now()]);

        return [
            'success'        => true,
            'declined_count' => $count,
            'message'        => "Declined {$count} proposal(s). No credits used.",
        ];
    }

    /**
     * Estimate cost for any plan before execution.
     * Called by SarahOrchestrator before creating any plan.
     */
    public function estimatePlanCost(array $tasks): array
    {
        $breakdown = [];
        $total = 0;

        foreach ($tasks as $task) {
            $action = $task['action'] ?? '';
            $cost = self::CREDIT_COSTS[$action] ?? 0;

            // Check capability map for credit cost
            if ($cost === 0) {
                $cap = app(\App\Core\EngineKernel\CapabilityMapService::class)->resolveAction($task['engine'] ?? '', $action);
                $cost = $cap['credit_cost'] ?? 0;
            }

            $breakdown[] = [
                'engine' => $task['engine'] ?? '',
                'action' => $action,
                'agent' => $task['agent'] ?? $task['assigned_agent'] ?? 'sarah',
                'description' => $task['description'] ?? "{$task['engine']}/{$action}",
                'credits' => $cost,
            ];
            $total += $cost;
        }

        return ['breakdown' => $breakdown, 'total' => $total];
    }

    /**
     * Daily proactive check — Sarah reviews workspace health.
     * Sends TEMPLATE notifications only — zero credits.
     */
    /**
     * SEO-DRAIN FIX (2026-07-18) — signal-aware supersede.
     *
     * Replaces a blind `created_at < now-7d` bulk wipe that was destroying
     * valid SEO work (80/80 SEO proposal deaths on ws2 in 30 days came from
     * it). A proposal is only killed when the gap it targets is actually gone.
     *
     * SEO slugs -> checked against live gap counts.
     * Everything else -> original 7-day age-out, unchanged, but now with a
     * recorded reason so a supersede is never unexplained again.
     */
    private function supersedeStaleProposals(int $wsId): void
    {
        $stale = DB::table('strategy_proposals')
            ->where('workspace_id', $wsId)
            ->where('status', 'pending_approval')
            ->where('created_at', '<', now()->subDays(7))
            ->get(['id', 'type', 'title']);

        if ($stale->isEmpty()) {
            return;
        }

        $gaps = $this->currentSeoGaps($wsId);

        foreach ($stale as $p) {
            $slug = preg_replace('/^(daily_action_|weekly_pivot_)/', '', (string) $p->type);

            if (array_key_exists($slug, $gaps)) {
                // Signal still real -> the work is still worth doing. Leave it
                // pending however old it is; the auto-executor will drain it.
                if ($gaps[$slug] > 0) {
                    continue;
                }
                $this->supersedeOne((int) $p->id, "signal_resolved:{$slug}", $wsId, (string) $p->title);
                continue;
            }

            $this->supersedeOne((int) $p->id, 'aged_out_7d', $wsId, (string) $p->title);
        }
    }

    /** Single supersede + reason. Reason column is additive; tolerate its absence. */
    private function supersedeOne(int $id, string $reason, int $wsId, string $title): void
    {
        $update = ['status' => 'superseded', 'updated_at' => now()];
        if (Schema::hasColumn('strategy_proposals', 'superseded_reason')) {
            $update['superseded_reason'] = $reason;
        }
        DB::table('strategy_proposals')->where('id', $id)->update($update);

        Log::info('[ProactiveStrategy] proposal superseded', [
            'workspace_id' => $wsId,
            'proposal_id'  => $id,
            'reason'       => $reason,
            'title'        => $title,
        ]);
    }

    /**
     * Live SEO gap counts, keyed by the proposal slug that targets each gap.
     * A slug present here with value 0 means "this work is finished".
     *
     * write_article / improve_draft are intentionally ABSENT: content is never
     * "done", so those keep the plain age-out rather than being kept alive
     * forever.
     *
     * On any read failure we return [] — every proposal then falls through to
     * the original 7-day age-out. That is the pre-existing behaviour, so a
     * broken gap query degrades to exactly what shipped before, never to
     * unbounded accumulation.
     */
    private function currentSeoGaps(int $wsId): array
    {
        try {
            $idx = DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->selectRaw(
                    'SUM(CASE WHEN inbound_links = 0 THEN 1 ELSE 0 END) AS orphans, '
                    . 'SUM(CASE WHEN word_count < 300 THEN 1 ELSE 0 END) AS thin, '
                    . 'SUM(CASE WHEN meta_description IS NULL OR meta_description = "" THEN 1 ELSE 0 END) AS missing_meta'
                )
                ->first();

            $suggestedLinks = (int) DB::table('seo_links')
                ->where('workspace_id', $wsId)
                ->where('status', 'suggested')
                ->count();

            $orphans     = (int) ($idx->orphans ?? 0);
            $thin        = (int) ($idx->thin ?? 0);
            $missingMeta = (int) ($idx->missing_meta ?? 0);

            return [
                'fix_orphans'            => $orphans,
                'insert_link'            => $suggestedLinks,
                'link_suggestions'       => $suggestedLinks,
                'apply_link_suggestions' => $suggestedLinks,
                'expand_thin_pages'      => $thin,
                'generate_meta'          => $missingMeta,
            ];
        } catch (\Throwable $e) {
            Log::warning('[ProactiveStrategy] SEO gap read failed — falling back to age-out', [
                'workspace_id' => $wsId,
                'error'        => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * A website that exists with nothing on it is a launch waiting to happen.
     *
     * When Chef Red's FIRST site went up in May, Sarah raised first_content and seo_audit proposals the next
     * day — that generator has since been deleted, and every trigger that replaced it works on content that
     * already exists: generate_meta, insert_link, fix_orphans, improve_draft, refresh_stale, expand_thin_pages.
     * Every one of them needs something to act ON. So when a second website was built on 2026-09-01 — a
     * graphic design business with two pages and no articles — nothing could fire, and the owner watched a
     * brand-new site sit there while Sarah said nothing about it.
     *
     * This is the missing case: not "improve what is there" but "there is nothing there yet". One proposal
     * per website, raised once. It is a proposal rather than work because a launch plan spends credits, and
     * the owner decides that — the same contract every other proposal honours.
     *
     * @return array<int,array{website_id:int,proposal_id:int,name:string}>
     */
    public function newWebsiteCheck(int $wsId): array
    {
        $raised = [];

        try {
            $sites = DB::table('websites')
                ->where('workspace_id', $wsId)
                ->whereNull('deleted_at')
                ->whereIn('status', ['published', 'draft'])
                ->get(['id', 'name', 'template_industry', 'subdomain', 'custom_domain', 'created_at']);

            foreach ($sites as $site) {
                $hasContent = DB::table('articles')
                    ->where('workspace_id', $wsId)
                    ->where('website_id', $site->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($hasContent) {
                    continue;   // this site is already being worked on
                }

                // Raised once per website, whatever the owner decided last time. A launch plan they declined
                // is an answer, not an invitation to ask again tomorrow.
                $already = DB::table('strategy_proposals')
                    ->where('workspace_id', $wsId)
                    ->where('type', self::TYPE_WEBSITE_LAUNCH)
                    ->where('entity_id', $site->id)
                    ->exists();

                if ($already) {
                    continue;
                }

                $name = trim((string) ($site->name ?? '')) ?: 'the new website';
                $host = (string) ($site->custom_domain ?: $site->subdomain ?: '');
                // The column holds a template slug like marketing_agency; a person should never be
                // shown that. 'in marketing_agency' is the sort of detail that tells an owner the
                // message was assembled by a machine that was not paying attention.
                $industry = trim(str_replace('_', ' ', (string) ($site->template_industry ?? '')));

                $estimate = $this->estimateLaunchCost();

                $proposalId = (int) DB::table('strategy_proposals')->insertGetId([
                    'workspace_id' => $wsId,
                    'type'         => self::TYPE_WEBSITE_LAUNCH,
                    'entity_type'  => 'website',
                    'entity_id'    => (int) $site->id,
                    'title'        => 'Launch plan for ' . $name,
                    'description'  => $name . ' is live' . ($host !== '' ? " at {$host}" : '')
                                    . ' with no content yet. A launch plan gives it the first articles, the '
                                    . 'keywords worth ranking for, and page titles and descriptions that read '
                                    . 'like a real business'
                                    . ($industry !== '' ? " in {$industry}." : '.'),
                    'status'              => 'pending_approval',
                    'cost_breakdown_json' => json_encode($estimate['breakdown']),
                    'total_credits'       => $estimate['total'],
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);

                $msg = "You've built {$name}" . ($host !== '' ? " at {$host}" : '') . ', and there is nothing '
                     . "on it yet. I can start it properly: keyword research for what people actually search "
                     . "in " . ($industry !== '' ? $industry : 'your market') . ", the first articles written "
                     . "for those terms, and titles and descriptions on every page. That is "
                     . "{$estimate['total']} credits. Say the word and I will set it up.";

                try {
                    $this->notifications->send($wsId, 'in_app', 'sarah_proposal', ['message' => $msg]);
                } catch (\Throwable $e) { /* the chat post below is the one that matters */ }

                $this->agentMessages->postAsAgent($wsId, 'sarah', $msg, [
                    'notification_type' => 'sarah_proposal',
                    'proposal_id'       => $proposalId,
                    'website_id'        => (int) $site->id,
                    'action_link'       => '/app/strategy/' . $proposalId,
                ]);

                Log::info('[ProactiveStrategy] proposed a launch plan for a website with no content', [
                    'workspace_id' => $wsId, 'website_id' => (int) $site->id, 'proposal_id' => $proposalId,
                ]);

                $raised[] = ['website_id' => (int) $site->id, 'proposal_id' => $proposalId, 'name' => $name];
            }
        } catch (\Throwable $e) {
            Log::warning('[ProactiveStrategy] newWebsiteCheck failed', [
                'workspace_id' => $wsId, 'error' => $e->getMessage(),
            ]);
        }

        return $raised;
    }

    /** What a first launch costs. Deliberately modest: enough to start, not a full campaign. */
    private function estimateLaunchCost(): array
    {
        $breakdown = [
            ['item' => 'Keyword research for the site\'s market', 'credits' => 2],
            ['item' => 'First 3 articles written for those keywords', 'credits' => 9],
            ['item' => 'Titles and descriptions across the pages', 'credits' => 1],
        ];

        return ['breakdown' => $breakdown, 'total' => array_sum(array_column($breakdown, 'credits'))];
    }

    public function dailyCheck(int $wsId): array
    {
        $workspace = Workspace::find($wsId);
        if (!$workspace || !$workspace->onboarded) return ['skipped' => true];

        $actions = [];

        // A website with nothing on it cannot be picked up by any of the content triggers below — they all
        // act on work that already exists. This is the one that notices a site nobody has started.
        foreach ($this->newWebsiteCheck($wsId) as $launch) {
            $actions[] = ['type' => 'website_launch_proposed', 'website_id' => $launch['website_id'],
                          'proposal_id' => $launch['proposal_id']];
        }

        // Check for pending approvals (template notification, free)
        $pendingApprovals = DB::table('execution_plans')
            ->where('workspace_id', $wsId)
            ->where('status', 'draft')
            ->where('requires_approval', true)
            ->count();

        $pendingProposals = DB::table('strategy_proposals')
            ->where('workspace_id', $wsId)
            ->where('status', 'pending_approval')
            ->count();

        if ($pendingApprovals > 0 || $pendingProposals > 0) {
            $total = $pendingApprovals + $pendingProposals;
            $actions[] = ['type' => 'pending_approvals', 'count' => $total];
            $reminderMsg = "You have {$total} item(s) waiting for your approval. " .
                "Your AI team is ready to work once you give the go-ahead. Check the Strategy Room.";
            $this->notifications->send($wsId, 'in_app', 'sarah_reminder', [
                'message' => $reminderMsg,
            ]);
            // Wave 6 (2026-05-18). Mirror into Sarah's chat thread.
            $this->agentMessages->postAsAgent($wsId, 'sarah', $reminderMsg, [
                'notification_type' => 'sarah_reminder',
                'pending_count'     => $total,
                'action_link'       => '/app/strategy',
            ]);
        }

        // Check for stale tasks (template notification, free)
        $staleTasks = DB::table('plan_tasks')
            ->join('execution_plans', 'plan_tasks.plan_id', '=', 'execution_plans.id')
            ->where('execution_plans.workspace_id', $wsId)
            ->where('plan_tasks.status', 'pending')
            ->where('plan_tasks.created_at', '<', now()->subHours(48))
            ->count();

        if ($staleTasks > 0) {
            $actions[] = ['type' => 'stale_tasks', 'count' => $staleTasks];
        }

        // Wave 86 — supersede pending proposals older than 7 days BEFORE
        // checking opportunities, so stale onboarding nudges dont keep
        // accruing day after day.
        //
        // SEO-DRAIN FIX (2026-07-18) — this blind age wipe was killing REAL,
        // still-valid SEO work. Measured on ws2: ALL 80 SEO proposal deaths in
        // 30 days came from this line (zero from the 15-min family dedup).
        // insert_link 27 superseded / 0 completed (avg age 285h), write_article
        // 22/4 (343h), generate_meta 3/0 (436h). They aged out here only
        // because the auto-executor was budget-starved and never drained them
        // (see SarahAutoExecuteCommand::spentToday) — so the work was valid the
        // whole time and got thrown away anyway, then re-proposed tomorrow.
        //
        // Now: SEO proposals are superseded ONLY when the underlying signal is
        // genuinely resolved (no orphans left, no thin pages left, etc).
        // Everything else keeps the original 7-day age-out, unchanged.
        $this->supersedeStaleProposals($wsId);

        // Identify opportunities and propose them WITH cost estimates
        $opportunities = $this->findOpportunities($wsId, $workspace);
        foreach ($opportunities as $opp) {
            // Wave 86 — dedup: skip if a pending proposal of the same type
            // already exists for this workspace. Sarah will keep showing
            // the existing one in the count; we dont need duplicates.
            $alreadyExists = DB::table('strategy_proposals')
                ->where('workspace_id', $wsId)
                ->where('type', $opp['type'])
                ->where('status', 'pending_approval')
                ->exists();
            if ($alreadyExists) {
                continue;
            }
            // Create a proposal with cost estimate
            DB::table('strategy_proposals')->insert([
                'workspace_id' => $wsId,
                'type' => $opp['type'],
                'title' => $opp['title'],
                'description' => $opp['description'],
                'status' => 'pending_approval',
                'cost_breakdown_json' => json_encode($opp['cost_breakdown']),
                'total_credits' => $opp['total_credits'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $actions[] = ['type' => 'opportunity', 'proposal' => $opp['title'], 'credits' => $opp['total_credits']];
        }

        return ['actions' => $actions, 'checked_at' => now()->toISOString()];
    }

    /**
     * Weekly performance review — template notification, zero credits.
     * If recommending new actions, sends cost estimate for approval.
     */
    public function weeklyReview(int $wsId): array
    {
        $workspace = Workspace::find($wsId);
        if (!$workspace || !$workspace->onboarded) return ['skipped' => true];

        $weekStart = now()->subWeek();

        $tasksCompleted = DB::table('plan_tasks')
            ->join('execution_plans', 'plan_tasks.plan_id', '=', 'execution_plans.id')
            ->where('execution_plans.workspace_id', $wsId)
            ->where('plan_tasks.status', 'completed')
            ->where('plan_tasks.completed_at', '>=', $weekStart)
            ->count();

        $creditsUsed = abs(DB::table('credit_transactions')
            ->where('workspace_id', $wsId)
            ->where('type', 'commit')
            ->where('created_at', '>=', $weekStart)
            ->sum('amount') ?? 0);

        $newLeads = DB::table('leads')
            ->where('workspace_id', $wsId)
            ->where('created_at', '>=', $weekStart)
            ->count();

        // Template notification — zero credits
        $weeklyMsg = "Weekly report from Sarah:\n\n" .
            "Tasks completed: {$tasksCompleted}\n" .
            "Credits used: {$creditsUsed}\n" .
            "New leads: {$newLeads}\n\n" .
            "Check the Strategy Room for recommendations.";
        $this->notifications->send($wsId, 'in_app', 'sarah_weekly', [
            'message' => $weeklyMsg,
        ]);
        // Wave 6 (2026-05-18). Mirror into Sarah's chat thread.
        $this->agentMessages->postAsAgent($wsId, 'sarah', $weeklyMsg, [
            'notification_type' => 'sarah_weekly',
            'tasks_completed'   => $tasksCompleted,
            'credits_used'      => $creditsUsed,
            'new_leads'         => $newLeads,
            'action_link'       => '/app/strategy',
        ]);

        return ['tasks_completed' => $tasksCompleted, 'credits_used' => $creditsUsed, 'new_leads' => $newLeads];
    }

    /**
     * Monthly strategy — proposes a new strategy meeting WITH cost estimate.
     */
    public function monthlyStrategy(int $wsId, int $userId): array
    {
        $workspace = Workspace::find($wsId);
        if (!$workspace || !$workspace->onboarded) return ['skipped' => true];

        $estimate = $this->estimateInitialSessionCost($workspace);

        $proposalId = DB::table('strategy_proposals')->insertGetId([
            'workspace_id' => $wsId,
            'type' => 'monthly_review',
            'title' => 'Monthly Strategy Review',
            'description' => "Monthly strategy session to review performance and plan ahead for {$workspace->business_name}",
            'status' => 'pending_approval',
            'cost_breakdown_json' => json_encode($estimate['breakdown']),
            'total_credits' => $estimate['total'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $balance = $this->credits->getBalance($wsId);

        $monthlyMsg = "It's time for your monthly strategy review. I'd like to gather the team.\n\n" .
            "Estimated cost: {$estimate['total']} credits\n" .
            "Your balance: " . ($balance['available'] ?? 0) . " credits\n\n" .
            "Approve in the Strategy Room to start.";
        $this->notifications->send($wsId, 'in_app', 'sarah_monthly_proposal', [
            'user_id' => $userId,
            'message' => $monthlyMsg,
        ]);
        // Wave 6 (2026-05-18). Mirror into Sarah's chat thread.
        $this->agentMessages->postAsAgent($wsId, 'sarah', $monthlyMsg, [
            'notification_type' => 'sarah_monthly_proposal',
            'estimated_credits' => $estimate['total'],
            'balance'           => $balance['available'] ?? 0,
            'action_link'       => '/app/strategy',
        ]);

        return ['proposal_id' => $proposalId, 'estimated_credits' => $estimate['total']];
    }

    /**
     * List pending proposals for a workspace.
     */
    public function listProposals(int $wsId, ?string $status = null): array
    {
        $q = DB::table('strategy_proposals')->where('workspace_id', $wsId);
        if ($status) $q->where('status', $status);
        return $q->orderByDesc('created_at')->limit(20)->get()->toArray();
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════════

    private function estimateInitialSessionCost(Workspace $workspace): array
    {
        // LAUNCH SCOPE 2026-07-20 — 'marcus' (removed social agent) dropped from the
        // strategy-meeting cost estimate; retained launch agents only.
        $agents = ['sarah', 'james', 'priya', 'elena', 'max'];
        $breakdown = [];

        // Meeting costs
        foreach ($agents as $agent) {
            $breakdown[] = [
                'action' => 'agent_contribution',
                'agent' => $agent,
                'description' => ucfirst($agent) . "'s expert analysis (~" . self::AGENT_TOKEN_CAP . " tokens)",
                'credits' => 1,
            ];
        }

        // Discussion round
        $breakdown[] = ['action' => 'meeting_debate', 'agent' => 'team', 'description' => 'Team discussion and debate', 'credits' => 2];

        // Sarah's synthesis
        $breakdown[] = ['action' => 'meeting_synthesis', 'agent' => 'sarah', 'description' => 'Sarah creates the final plan', 'credits' => 1];

        $total = array_sum(array_column($breakdown, 'credits'));

        return ['breakdown' => $breakdown, 'total' => $total];
    }

    private function buildCostEstimateMessage(Workspace $workspace, array $estimate, array $balance): string
    {
        $lines = [
            "Welcome to LevelUp, {$workspace->business_name}! I'm Sarah, your Digital Marketing Manager.",
            "",
            "I'd like to run a strategy session with the team to plan your digital marketing. Here's what it involves:",
            "",
        ];

        foreach ($estimate['breakdown'] as $item) {
            $lines[] = "  • {$item['description']}: {$item['credits']} credit(s)";
        }

        $lines[] = "";
        $lines[] = "Total estimated cost: {$estimate['total']} credits";
        $lines[] = "Your current balance: " . ($balance['available'] ?? 0) . " credits";
        $lines[] = "";
        $lines[] = "Approve in the Strategy Room to get started. No credits will be used until you approve.";

        return implode("\n", $lines);
    }

    private function buildOnboardingGoal(Workspace $workspace): string
    {
        $parts = ["Create a digital marketing strategy for {$workspace->business_name}"];
        if ($workspace->industry) $parts[] = "in {$workspace->industry}";
        if ($workspace->location) $parts[] = "targeting {$workspace->location}";
        if ($workspace->goal) {
            $goalMap = ['leads' => 'to generate leads', 'brand' => 'to build brand awareness',
                        'ecommerce' => 'to drive online sales', 'portfolio' => 'to showcase their portfolio'];
            $parts[] = $goalMap[$workspace->goal] ?? "with goal: {$workspace->goal}";
        }
        return implode(' ', $parts);
    }

    /**
     * Wave 91 — Opportunity detection via runtime (canonical). DB queries
     * that compute the feature vector stay local (workspace-scoped facts);
     * rule logic goes through runtime. On unreachable runtime, returns []
     * (better no nudge than a stale one).
     */
    private function findOpportunities(int $wsId, Workspace $workspace): array
    {
        $articleCount = DB::table('articles')->where('workspace_id', $wsId)->where('status', 'published')->count();
        $hasAudit = DB::table('seo_audits')->where('workspace_id', $wsId)->where('type', 'full')->exists();

        $rt = app(\App\Connectors\RuntimeClient::class);
        $result = [];
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $runtimeResult = $rt->proactiveFindOpportunities((int) $articleCount, (bool) $hasAudit);
            if ($runtimeResult !== null) $result = $runtimeResult;
        }
        // /* studio-stale-sweep-v1 */ — append visual refresh opportunities
        // Surfaces Studio designs older than 90 days so Sarah can propose a
        // regen via studio/generate_design. Recommendation only — never auto-acts.
        try {
            $staleCount = DB::table('studio_designs')
                ->where('workspace_id', $wsId)
                ->whereNull('deleted_at')
                ->where('status', 'exported')
                ->where('updated_at', '<', now()->subDays(90))
                ->count();
            if ($staleCount > 0) {
                $result[] = [
                    'type'         => 'studio_refresh',
                    'severity'     => $staleCount >= 5 ? 'high' : 'medium',
                    'count'        => $staleCount,
                    'headline'     => $staleCount === 1
                        ? '1 published design is over 90 days old'
                        : $staleCount . ' published designs are over 90 days old',
                    'recommendation' => 'Refresh stale visuals with studio/generate_design grounded in current brand kit.',
                    'engine'       => 'studio',
                    'action'       => 'generate_design',
                    'priority'     => $staleCount >= 5 ? 8 : 5,
                ];
            }
        } catch (\Throwable $e) {
            // Fail-silent — opportunity surfacing must not break the daily check.
            \Illuminate\Support\Facades\Log::warning('studio-stale-sweep failed: ' . $e->getMessage());
        }
        // /* email-stale-sweep-v1 */ — surface stale email templates as refresh opportunities.
        // Conditions: template updated >90 days ago AND owned by workspace (not system).
        // Sarah's recommendation only — never auto-acts on the user's templates.
        try {
            $staleEmails = DB::table('email_templates')
                ->where('workspace_id', $wsId)
                ->where('is_system', 0)
                ->where('is_active', 1)
                ->where('updated_at', '<', now()->subDays(90))
                ->count();
            if ($staleEmails > 0) {
                $result[] = [
                    'type'         => 'email_refresh',
                    'severity'     => $staleEmails >= 3 ? 'high' : 'medium',
                    'count'        => $staleEmails,
                    'headline'     => $staleEmails === 1
                        ? '1 email template is over 90 days old'
                        : $staleEmails . ' email templates are over 90 days old',
                    'recommendation' => 'Refresh stale email templates with marketing/email_ai_generate grounded in current brand kit + recent performance.',
                    'engine'       => 'marketing',
                    'action'       => 'email_ai_generate',
                    'priority'     => $staleEmails >= 3 ? 7 : 4,
                ];
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('email-stale-sweep failed: ' . $e->getMessage());
        }
        // /* cross-engine-article-email-v1 */ — surface articles without companion email.
        // Sarah recommends drafting an email campaign for any article published in the
        // last 7 days that has no email_campaign_log linking to it.
        try {
            $recentArticles = DB::table('articles')
                ->where('workspace_id', $wsId)
                ->where('status', 'published')
                ->where('published_at', '>=', now()->subDays(7))
                ->select('id', 'title', 'slug', 'published_at')
                ->get();
            if ($recentArticles->isNotEmpty()) {
                $unmatched = [];
                foreach ($recentArticles as $art) {
                    // Check if any campaign references this article (by URL or template name containing slug)
                    $linked = DB::table('email_campaigns_log')
                        ->where('workspace_id', $wsId)
                        ->where(function ($q) use ($art) {
                            $q->where('content', 'like', '%' . $art->slug . '%')
                              ->orWhere('subject', 'like', '%' . $art->title . '%');
                        })
                        ->exists();
                    if (!$linked) $unmatched[] = $art;
                }
                if (count($unmatched) > 0) {
                    $first = $unmatched[0];
                    $result[] = [
                        'type'         => 'article_to_email',
                        'severity'     => 'medium',
                        'count'        => count($unmatched),
                        'headline'     => count($unmatched) === 1
                            ? 'Recent article "' . substr($first->title, 0, 50) . '" has no companion email'
                            : count($unmatched) . ' recent articles have no companion email campaigns',
                        'recommendation' => 'Draft an email campaign for each article to amplify reach. Sarah will pre-fill the subject + preview from the article title + meta.',
                        'engine'       => 'marketing',
                        'action'       => 'email_ai_generate',
                        'priority'     => 6,
                        'context'      => [
                            'article_ids' => array_map(fn($a) => $a->id, $unmatched),
                            'goal'        => 'announce',
                        ],
                    ];
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('cross-engine-article-email failed: ' . $e->getMessage());
        }
        return $result;
    }

}
