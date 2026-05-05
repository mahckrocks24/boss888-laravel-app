<?php

namespace App\Core\EngineKernel;

use App\Core\TaskSystem\Orchestrator;
use App\Core\Billing\CreditService;
use App\Core\Governance\ApprovalService;
use App\Core\Audit\AuditLogService;
use App\Core\Notifications\NotificationService;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EngineExecutionService — THE central execution bridge.
 *
 * EVERY engine action (CRM, SEO, Write, etc.) MUST flow through this service.
 * Two modes:
 *   1. Manual Mode: User clicks button → execute() → immediate result
 *   2. Agent Mode:  Agent dispatches → executeAsync() → queued task → callback
 *
 * Pipeline for EVERY action:
 *   UI/Agent → EngineExecutionService::execute()
 *     → validateCapability (does this action exist?)
 *     → checkAgentCapability (is this agent allowed to use this tool?)
 *     → checkPlanGating (does the plan allow this?)
 *     → checkCredits (does the workspace have credits?)
 *     → checkApproval (does this need approval?)
 *     → executeAction (run the actual engine method)
 *     → deductCredits (if AI-powered)
 *     → fireAutomationTriggers (notify automation engine)
 *     → logAudit (record in audit trail)
 *     → createCalendarEvent (if time-based)
 *     → return result
 */
class EngineExecutionService
{
    public function __construct(
        private CapabilityMapService $capabilityMap,
        private CreditService $creditService,
        private ApprovalService $approvalService,
        private AuditLogService $auditLog,
        private NotificationService $notifications,
        // FIX-B: injected so executeAsync routes through canonical TaskService::create()
        // instead of bypassing it with a direct Task::create() that used the wrong
        // column name ('payload' vs 'payload_json') and missed idempotency keying.
        private \App\Core\TaskSystem\TaskService $taskService,
    ) {}

    /**
     * Execute an engine action through the full AI OS pipeline.
     *
     * @param int    $wsId      Workspace ID
     * @param string $engine    Engine slug (crm, seo, write, etc.)
     * @param string $action    Action name (create_lead, serp_analysis, etc.)
     * @param array  $params    Action parameters
     * @param array  $context   Execution context [user_id, agent_id, source, priority]
     * @return array            [success, data, credits_used, task_id, triggers_fired]
     */
    public function execute(int $wsId, string $engine, string $action, array $params = [], array $context = []): array
    {
        // ─── Workspace guard ─────────────────────────────────
        // workspace_id = null means the JWT had no 'ws' claim — reject hard
        // rather than silently querying across all workspaces.
        if (! $wsId) {
            return ['success' => false, 'error' => 'Workspace context missing', 'code' => 'NO_WORKSPACE'];
        }

        $userId = $context['user_id'] ?? null;
        $agentId = $context['agent_id'] ?? null;
        $source = $context['source'] ?? 'manual'; // manual | agent | automation | api
        $priority = $context['priority'] ?? 'normal';

        // ─── Step 1: Validate capability exists ──────────────
        $capability = $this->capabilityMap->resolveAction($engine, $action);
        if (!$capability) {
            return ['success' => false, 'error' => "Unknown action: {$engine}/{$action}", 'code' => 'INVALID_ACTION'];
        }

        // ─── Step 1b: Check agent capability (FIX-3) ─────────
        // If the execution context includes an agent_id, verify
        // the agent is permitted to use this tool via the runtime
        // capability map ported to AgentCapabilityService.
        // Manual/API calls (no agent_id) skip this check.
        if ($agentId) {
            $agentCapService = app(\App\Core\Agent\AgentCapabilityService::class);
            if (! $agentCapService->canUse($agentId, $action)) {
                Log::warning("Agent capability denied: {$agentId} cannot use {$action}", [
                    'ws' => $wsId, 'engine' => $engine, 'action' => $action, 'agent' => $agentId,
                ]);
                return [
                    'success' => false,
                    'error'   => 'AGENT_NOT_AUTHORIZED',
                    'message' => "{$agentId} cannot perform {$action}",
                    'code'    => 'AGENT_NOT_AUTHORIZED',
                ];
            }
        }

        // ─── Step 2: Check plan gating ───────────────────────
        $planCheck = app(\App\Core\PlanGating\PlanGatingService::class)->canExecute($wsId, $engine, $action);
        if (!$planCheck['allowed']) {
            return ['success' => false, 'error' => $planCheck['reason'] ?? 'Plan does not allow this action', 'code' => 'PLAN_GATED'];
        }

        // ─── Step 3: Check credits (for AI-powered actions) ──
        $creditCost = $capability['credit_cost'] ?? 0;
        if ($creditCost > 0) {
            $hasCredits = $this->creditService->hasBalance($wsId, $creditCost);
            if (!$hasCredits) {
                return ['success' => false, 'error' => "Insufficient credits. Required: {$creditCost}", 'code' => 'NO_CREDITS'];
            }
            // Reserve credits
            $reservationId = $this->creditService->reserve($wsId, $creditCost, "{$engine}/{$action}");
        }

        // ─── Step 4: Check approval requirements ─────────────
        $approvalLevel = $capability['approval_level'] ?? 'auto';
        if ($approvalLevel !== 'auto') {
            $approval = $this->approvalService->requestIfNeeded($wsId, $engine, $action, $approvalLevel, $params);
            if ($approval && $approval['status'] === 'pending') {
                // Release reserved credits — will re-reserve on approval
                if (isset($reservationId)) $this->creditService->release($wsId, $reservationId);
                return ['success' => true, 'pending_approval' => true, 'approval_id' => $approval['id'],
                        'message' => 'Action requires approval', 'code' => 'AWAITING_APPROVAL'];
            }
        }

        // ─── Step 5: Execute the actual engine action ────────
        try {
            $result = $this->dispatchToEngine($wsId, $engine, $action, $params, $context);
        } catch (\Throwable $e) {
            // Release credits on failure
            if (isset($reservationId)) $this->creditService->release($wsId, $reservationId);
            Log::error("EngineExecution failed: {$engine}/{$action}", ['error' => $e->getMessage(), 'ws' => $wsId]);
            return ['success' => false, 'error' => $e->getMessage(), 'code' => 'EXECUTION_FAILED'];
        }

        // ─── Step 6: Commit credits ──────────────────────────
        if (isset($reservationId) && $creditCost > 0) {
            $this->creditService->commit($wsId, $reservationId, $creditCost);
        }

        // ─── Step 7: Fire automation triggers ────────────────
        $triggers = $this->fireAutomationTriggers($wsId, $engine, $action, $params, $result);

        // ─── Step 8: Cross-engine sync ───────────────────────
        $this->crossEngineSync($wsId, $engine, $action, $params, $result);

        // ─── Step 9: Audit log ───────────────────────────────
        $this->auditLog->log($wsId, $userId, "{$engine}.{$action}", ucfirst($engine), $result['entity_id'] ?? null, [
            'source' => $source, 'agent' => $agentId, 'credits' => $creditCost, 'params' => array_keys($params),
        ]);

        // ─── Step 10: Record intelligence (learning loop) ────
        try {
            // Record agent experience (if agent-driven)
            if ($agentId) {
                $agent = \App\Models\Agent::where('slug', $agentId)->first();
                if ($agent) {
                    $workspace = \App\Models\Workspace::find($wsId);
                    app(\App\Core\Intelligence\AgentExperienceService::class)
                        ->recordTaskCompletion($agent->id, $engine, $action, $workspace?->industry, [
                            'success' => true, 'tokens_used' => $result['usage']['total_tokens'] ?? 0,
                        ]);
                }
            }

            // Record engine tool usage via ToolFeedbackService (the learning loop).
            // Converts outcome into an effectiveness score and feeds it back
            // to EngineIntelligenceService::recordToolUsage (which is now self-healing).
            app(\App\Core\Intelligence\ToolFeedbackService::class)->record($engine, $action, [
                'success' => true,
                'workspace_id' => $wsId,
                'duration_ms' => $result['duration_ms'] ?? null,
                'tokens_used' => $result['usage']['total_tokens'] ?? null,
                'quality_signal' => $result['quality_signal'] ?? null,
                'agent_id' => $agentId,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Intelligence recording failed: {$e->getMessage()}");
        }

        return [
            'success' => true,
            'data' => $result,
            'credits_used' => $creditCost,
            'triggers_fired' => $triggers,
            'source' => $source,
        ];
    }

    /**
     * Execute async (for agent mode) — creates a queued task via the canonical
     * TaskService::create() path and dispatches it to the Redis queue.
     *
     * FIX-B (root cause): Previously used Task::create() directly with wrong column name
     * ('payload' instead of 'payload_json'). The Task model's $fillable array does not
     * include 'payload', so Eloquent silently dropped the field — every async AI task
     * arrived at the Orchestrator with a null payload, causing silent execution failure.
     *
     * Fix: route through TaskService::create() which:
     *   - writes to the correct column (payload_json)
     *   - generates the idempotency key
     *   - handles approval gating
     *   - dispatches via TaskDispatcher (which sets ->onConnection('redis'))
     *
     * The manual TaskExecutionJob::dispatch() call below is removed — TaskService
     * already does this via TaskDispatcher::dispatch() for auto-approved tasks.
     */
    public function executeAsync(int $wsId, string $engine, string $action, array $params, array $context): array
    {
        if (! $wsId) {
            return ['success' => false, 'error' => 'Workspace context missing', 'code' => 'NO_WORKSPACE'];
        }

        $task = $this->taskService->create($wsId, [
            'engine'          => $engine,
            'action'          => $action,
            'payload'         => $params,               // TaskService normalises → payload_json
            'source'          => $context['source'] ?? 'agent',
            'assigned_agents' => [$context['agent_id'] ?? 'sarah'],  // TaskService → assigned_agents_json
            'priority'        => $context['priority'] ?? 'normal',
        ]);

        Log::info("Task {$task->id} created via TaskService (async)", [
            'engine' => $engine, 'action' => $action, 'ws' => $wsId,
            'status' => $task->status, 'requires_approval' => $task->requires_approval,
        ]);

        return [
            'success'          => true,
            'task_id'          => $task->id,
            'status'           => $task->status,
            'requires_approval'=> $task->requires_approval,
            'mode'             => 'async',
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // ENGINE DISPATCH — routes action to correct engine service
    // ═══════════════════════════════════════════════════════════

    private function dispatchToEngine(int $wsId, string $engine, string $action, array $params, array $context): array
    {
        $result = match ($engine) {
            'crm' => $this->executeCrmAction($wsId, $action, $params, $context),
            'seo' => $this->executeSeoAction($wsId, $action, $params, $context),
            'write' => $this->executeWriteAction($wsId, $action, $params, $context),
            'creative' => $this->executeCreativeAction($wsId, $action, $params, $context),
            'builder' => $this->executeBuilderAction($wsId, $action, $params, $context),
            'marketing' => $this->executeMarketingAction($wsId, $action, $params, $context),
            'social' => $this->executeSocialAction($wsId, $action, $params, $context),
            'calendar' => $this->executeCalendarAction($wsId, $action, $params, $context),
            'beforeafter' => $this->executeBeforeAfterAction($wsId, $action, $params, $context),
            'traffic' => $this->executeTrafficAction($wsId, $action, $params, $context),
            'manualedit' => $this->executeManualEditAction($wsId, $action, $params, $context),
            default => throw new \RuntimeException("Unknown engine: {$engine}"),
        };

        return is_array($result) ? $result : ['result' => $result];
    }

    private function executeCrmAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\CRM\Services\CrmService::class);
        return match ($action) {
            'create_lead' => ['entity_type' => 'Lead', 'entity_id' => $svc->createLead($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null]))->id, 'action' => 'created'],
            'update_lead' => ['entity_type' => 'Lead', 'entity_id' => $params['lead_id'], 'data' => $svc->updateLead($params['lead_id'], $params, $ctx['user_id'] ?? null)],
            'delete_lead' => ['entity_type' => 'Lead', 'entity_id' => $params['lead_id'], 'action' => 'deleted'] + (function() use ($svc, $params) { $svc->deleteLead($params['lead_id']); return []; })(),
            'score_lead' => ['entity_type' => 'Lead', 'entity_id' => $params['lead_id'], 'data' => $svc->scoreLead($params['lead_id'], $params['score'] ?? null)],
            'assign_lead' => ['entity_type' => 'Lead', 'entity_id' => $params['lead_id'], 'data' => $svc->assignLead($params['lead_id'], $params['assigned_to'] ?? null, $ctx['user_id'] ?? null)],
            'import_leads' => $svc->importLeads($wsId, $params['rows'] ?? [], $ctx['user_id'] ?? null),
            'create_contact' => ['entity_type' => 'Contact', 'entity_id' => $svc->createContact($wsId, $params)->id],
            'merge_contacts' => ['entity_type' => 'Contact', 'data' => $svc->mergeContacts($wsId, $params['keep_id'], $params['merge_id'])],
            'create_deal' => ['entity_type' => 'Deal', 'entity_id' => $svc->createDeal($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null]))->id],
            'update_deal_stage' => ['entity_type' => 'Deal', 'entity_id' => $params['deal_id'], 'data' => $svc->updateDealStage($params['deal_id'], $params['stage'], $ctx['user_id'] ?? null)],
            'log_activity' => ['entity_type' => 'Activity', 'entity_id' => $svc->logActivity($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null]))->id],
            'add_note' => ['entity_type' => 'Note', 'entity_id' => $svc->addNote($wsId, $params['entity_type'], $params['entity_id'], $params['body'], $ctx['user_id'] ?? null)->id],
            default => throw new \RuntimeException("Unknown CRM action: {$action}"),
        };
    }

    // Placeholder dispatchers for other engines — will be filled when each engine reaches 100%
    private function executeSeoAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\SEO\Services\SeoService::class);
        return match ($action) {
            'serp_analysis' => $svc->serpAnalysis($wsId, $params),
            'ai_report' => $svc->aiReport($wsId, $params),
            'deep_audit' => $svc->deepAudit($wsId, $params),
            'improve_draft' => $svc->improveDraft($wsId, $params),
            'write_article' => $svc->writeArticle($wsId, $params),
            'add_keyword' => ['entity_type' => 'Keyword', 'entity_id' => $svc->addKeyword($wsId, $params)],
            'link_suggestions', 'generate_links' => $svc->generateLinkSuggestions($wsId, $params),
            'insert_link' => ['inserted' => $svc->insertLink($wsId, $params['link_id'] ?? 0)],
            'dismiss_link' => ['dismissed' => $svc->dismissLink($wsId, $params['link_id'] ?? 0)],
            'check_outbound' => $svc->checkOutbound($wsId, $params),
            'create_goal', 'autonomous_goal' => $svc->createGoal($wsId, $params),
            'pause_goal' => ['paused' => $svc->pauseGoal($wsId, $params['goal_id'] ?? 0)],
            'resume_goal' => ['resumed' => $svc->resumeGoal($wsId, $params['goal_id'] ?? 0)],
            default => throw new \RuntimeException("Unknown SEO action: {$action}"),
        };
    }

    private function executeWriteAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Write\Services\WriteService::class);
        return match ($action) {
            'create_article', 'write_article' => $svc->createArticle($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null])),
            'update_article' => $svc->updateArticle($params['article_id'], $params),
            'improve_draft' => $svc->improveDraft($wsId, $params),
            'generate_outline' => $svc->generateOutline($wsId, $params),
            'generate_headlines' => $svc->generateHeadlines($wsId, $params),
            'generate_meta' => $svc->generateMeta($wsId, $params),
            default => throw new \RuntimeException("Unknown Write action: {$action}"),
        };
    }

    private function executeCreativeAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Creative\Services\CreativeService::class);
        return match ($action) {
            // Phase 2A fix: route generate_image to the real generateImage()
            // (which calls DALL-E 3 via RuntimeClient) instead of createAsset()
            // (which just makes a DB row with status=pending).
            'generate_image' => $svc->generateImage($wsId, $params),
            'generate_video' => $svc->generateVideo($wsId, $params),
            'create_asset'   => $svc->createAsset($wsId, $params),
            default => throw new \RuntimeException("Unknown Creative action: {$action}"),
        };
    }

    private function executeBuilderAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Builder\Services\BuilderService::class);
        return match ($action) {
            'create_website' => $svc->createWebsite($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null])),
            'generate_page' => $svc->createPage($params['website_id'], $params),
            'wizard_generate' => $svc->wizardGenerate($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null])),
            'publish_website' => ['action' => 'published'] + (function() use ($svc, $params) { $svc->publishWebsite($params['website_id']); return []; })(),
            default => throw new \RuntimeException("Unknown Builder action: {$action}"),
        };
    }

    private function executeMarketingAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Marketing\Services\MarketingService::class);
        return match ($action) {
            'create_campaign'   => $svc->createCampaign($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null])),
            'create_automation' => ['entity_type' => 'Automation', 'entity_id' => $svc->createAutomation($wsId, $params)],
            'schedule_campaign' => $svc->scheduleCampaign($params['campaign_id'] ?? 0, $params['scheduled_at'] ?? ''),
            'send_campaign'     => $svc->sendCampaign($wsId, $params['campaign_id'] ?? 0),  // Phase 3: was missing → credits reserved then released on RuntimeException
            default => throw new \RuntimeException("Unknown Marketing action: {$action}"),
        };
    }

    private function executeSocialAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Social\Services\SocialService::class);
        return match ($action) {
            'social_create_post', 'create_post' => $svc->createPost($wsId, $params),
            'social_schedule_post'              => ['scheduled' => true] + (function() use ($svc, $params) { $svc->schedulePost($params['post_id'], $params['scheduled_at']); return []; })(),
            'social_publish_post'               => $svc->publishPost($params['post_id']),   // PATCH v1.0.1: was missing → RuntimeException
            default => throw new \RuntimeException("Unknown Social action: {$action}"),
        };
    }

    private function executeCalendarAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Calendar\Services\CalendarService::class);
        return match ($action) {
            'create_event' => ['entity_type' => 'Event', 'entity_id' => $svc->createEvent($wsId, $params)],
            default => throw new \RuntimeException("Unknown Calendar action: {$action}"),
        };
    }

    private function executeBeforeAfterAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\BeforeAfter\Services\BeforeAfterService::class);
        return match ($action) {
            'ba_transform', 'create_design' => $svc->createDesign($wsId, $params),
            'ba_design_report' => (function() use ($svc, $params) { $svc->generateReport($params['design_id'] ?? 0); return ['status' => 'generated']; })(),  // Phase 3: was missing
            default => throw new \RuntimeException("Unknown BeforeAfter action: {$action}"),
        };
    }

    private function executeTrafficAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\TrafficDefense\Services\TrafficDefenseService::class);
        return match ($action) {
            'create_rule' => ['entity_type' => 'Rule', 'entity_id' => $svc->createRule($wsId, $params)],
            default => throw new \RuntimeException("Unknown Traffic action: {$action}"),
        };
    }

    private function executeManualEditAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\ManualEdit\Services\ManualEditService::class);
        return match ($action) {
            'create_canvas' => $svc->createCanvas($wsId, $params),
            default => throw new \RuntimeException("Unknown ManualEdit action: {$action}"),
        };
    }

    // ═══════════════════════════════════════════════════════════
    // AUTOMATION TRIGGERS
    // ═══════════════════════════════════════════════════════════

    private function fireAutomationTriggers(int $wsId, string $engine, string $action, array $params, array $result): array
    {
        $triggers = [];
        $triggerType = "{$engine}.{$action}";

        // Map engine actions to automation trigger types
        $triggerMap = [
            'crm.create_lead' => 'lead_created',
            'crm.update_lead' => 'lead_updated',
            'crm.update_deal_stage' => 'deal_stage_changed',
            'crm.create_deal' => 'deal_created',
            'crm.assign_lead' => 'lead_assigned',
            'marketing.create_campaign' => 'campaign_created',
            'social.create_post' => 'post_created',
            'write.create_article' => 'article_created',
            'builder.publish_website' => 'website_published',
        ];

        $automationTrigger = $triggerMap[$triggerType] ?? null;
        if (!$automationTrigger) return $triggers;

        // Find matching automations
        $automations = DB::table('automations')
            ->where('workspace_id', $wsId)
            ->where('status', 'active')
            ->where('trigger_type', $automationTrigger)
            ->get();

        foreach ($automations as $automation) {
            $triggers[] = $automation->name;

            // Execute automation steps
            $steps = json_decode($automation->steps_json ?? '[]', true);
            foreach ($steps as $step) {
                $this->executeAutomationStep($wsId, $step, $params, $result);
            }

            // Increment execution count
            DB::table('automations')->where('id', $automation->id)->increment('execution_count');
        }

        return $triggers;
    }

    private function executeAutomationStep(int $wsId, array $step, array $triggerParams, array $triggerResult): void
    {
        try {
            $stepType = $step['type'] ?? '';
            $stepConfig = $step['config'] ?? [];

            match ($stepType) {
                'send_email' => $this->notifications->create($wsId, null, 'automation_email', $stepConfig['message'] ?? 'Automation triggered'),
                'create_task' => $this->execute($wsId, $stepConfig['engine'] ?? 'crm', $stepConfig['action'] ?? 'log_activity', $stepConfig['params'] ?? [], ['source' => 'automation']),
                'notify' => $this->notifications->create($wsId, null, 'automation', $stepConfig['message'] ?? 'Automation step executed'),
                'wait' => null, // handled by queue delay
                default => Log::info("Unknown automation step: {$stepType}"),
            };
        } catch (\Throwable $e) {
            Log::warning("Automation step failed: {$e->getMessage()}");
        }
    }

    // ═══════════════════════════════════════════════════════════
    // CROSS-ENGINE SYNC
    // ═══════════════════════════════════════════════════════════

    private function crossEngineSync(int $wsId, string $engine, string $action, array $params, array $result): void
    {
        try {
            // CRM → Calendar: create calendar event for scheduled activities
            if ($engine === 'crm' && $action === 'log_activity' && !empty($params['scheduled_at'])) {
                app(\App\Engines\Calendar\Services\CalendarService::class)->createEvent($wsId, [
                    'title' => $params['subject'] ?? $params['type'] . ' activity',
                    'starts_at' => $params['scheduled_at'],
                    'category' => 'task_deadline',
                    'engine' => 'crm',
                    'reference_id' => $result['entity_id'] ?? null,
                    'reference_type' => 'Activity',
                    'color' => '#00E5A8',
                ]);
            }

            // CRM → Calendar: deal expected close date
            if ($engine === 'crm' && $action === 'create_deal' && !empty($params['expected_close'])) {
                app(\App\Engines\Calendar\Services\CalendarService::class)->createEvent($wsId, [
                    'title' => "Deal close: " . ($params['title'] ?? 'Untitled'),
                    'starts_at' => $params['expected_close'],
                    'category' => 'task_deadline',
                    'engine' => 'crm',
                    'reference_id' => $result['entity_id'] ?? null,
                    'reference_type' => 'Deal',
                    'color' => '#F59E0B',
                ]);
            }

            // Marketing → Calendar: scheduled campaign
            if ($engine === 'marketing' && $action === 'schedule_campaign' && !empty($params['scheduled_at'])) {
                app(\App\Engines\Calendar\Services\CalendarService::class)->createEvent($wsId, [
                    'title' => "Campaign: " . ($params['name'] ?? 'Untitled'),
                    'starts_at' => $params['scheduled_at'],
                    'category' => 'campaign_launch',
                    'engine' => 'marketing',
                    'color' => '#F59E0B',
                ]);
            }

            // Social → Calendar: scheduled post
            if ($engine === 'social' && in_array($action, ['social_schedule_post', 'create_post']) && !empty($params['scheduled_at'])) {
                app(\App\Engines\Calendar\Services\CalendarService::class)->createEvent($wsId, [
                    'title' => "Post: " . substr($params['content'] ?? '', 0, 40),
                    'starts_at' => $params['scheduled_at'],
                    'category' => 'social_post',
                    'engine' => 'social',
                    'color' => '#EC4899',
                ]);
            }

        } catch (\Throwable $e) {
            Log::warning("Cross-engine sync failed: {$e->getMessage()}");
        }
    }

    // ═══════════════════════════════════════════════════════════
    // RUNTIME CALLBACK — called by Railway Node.js via POST /api/internal/task-result
    // Authenticated by RuntimeSecretMiddleware (X-Runtime-Secret header).
    // Closes the async execution loop: queued → completed/failed + credit finalisation.
    // ═══════════════════════════════════════════════════════════

    /**
     * Handle a task-result callback from the Railway Node.js runtime.
     *
     * PATCH v1.0.1: This method was referenced by the /internal/task-result route
     * but did not exist, causing a BadMethodCallException on every callback.
     *
     * @param  string|int $taskId   Task ID from the callback payload
     * @param  string     $status   One of: completed | failed | cancelled
     * @param  array      $result   Result data / error detail from runtime
     */
    public function handleRuntimeCallback(string|int $taskId, string $status, array $result = []): void
    {
        $allowedStatuses = ['completed', 'failed', 'cancelled'];

        if (! in_array($status, $allowedStatuses, true)) {
            Log::warning("handleRuntimeCallback: rejected unknown status '{$status}' for task {$taskId}");
            return;
        }

        $task = Task::find($taskId);

        if (! $task) {
            Log::error("handleRuntimeCallback: task {$taskId} not found");
            return;
        }

        // Guard: only transition tasks that are still in-flight
        if (in_array($task->status, ['completed', 'failed', 'cancelled'], true)) {
            Log::info("handleRuntimeCallback: task {$taskId} already in terminal state '{$task->status}' — skipping");
            return;
        }

        $now = now();

        $task->update([
            'status'           => $status,
            'result_json'      => json_encode($result),
            'completed_at'     => $status === 'completed' ? $now : null,
            'progress_message' => $status === 'completed'
                ? ($result['summary'] ?? 'Task completed by runtime')
                : ($result['error']   ?? 'Task failed in runtime'),
        ]);

        // ── Credit finalisation ───────────────────────────────
        // If the task had a credit reservation, commit on success or release on failure.
        // reservation_ref is stored in the task payload by executeAsync callers that
        // go through the full 10-step pipeline.
        // FIX-B: was $task->payload (column does not exist — always null).
        // Correct column is payload_json, auto-cast to array by the Task model.
        $payload = is_array($task->payload_json) ? $task->payload_json : [];
        $reservationRef = $payload['_reservation_ref'] ?? null;

        if ($reservationRef) {
            try {
                if ($status === 'completed') {
                    $this->creditService->commit($task->workspace_id, $reservationRef, $payload['_credit_cost'] ?? 0);
                } else {
                    $this->creditService->release($task->workspace_id, $reservationRef);
                }
            } catch (\Throwable $e) {
                Log::warning("handleRuntimeCallback: credit finalisation failed for task {$taskId}: {$e->getMessage()}");
            }
        }

        // ── Audit log ─────────────────────────────────────────
        $this->auditLog->log(
            $task->workspace_id,
            null,
            "task.runtime_callback",
            'Task',
            $task->id,
            ['status' => $status, 'source' => 'runtime']
        );

        Log::info("handleRuntimeCallback: task {$taskId} → {$status}", [
            'engine' => $task->engine, 'action' => $task->action, 'ws' => $task->workspace_id,
        ]);
    }
}
