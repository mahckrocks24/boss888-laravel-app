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
        // PATCH 2026-05-09 — executeAsync was creating tasks but never queueing
        // them. 12 stuck `tasks` rows, 0 in `jobs`, workers idle. Inject the
        // dispatcher so async creation flows straight to the queue.
        private \App\Core\TaskSystem\TaskDispatcher $dispatcher,
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

        // ─── Step 1a: LAUNCH SCOPE POLICY (2026-07-20) ───────
        // Authoritative launch-boundary gate. Runs BEFORE agent-cap, plan,
        // credits, approval and dispatch, so a removed capability can never
        // reserve credits, call a provider, create a job/record, or notify —
        // regardless of how it was reached (API closure, agent, approval click,
        // automation step). The retained blog-article-share sliver is permitted
        // only in article-share context. See App\Core\LaunchScope\LaunchScopePolicy.
        $__scopeDenied = \App\Core\LaunchScope\LaunchScopePolicy::deniedReason($engine, $action, $params, $context);
        if ($__scopeDenied !== null) {
            Log::info('[LaunchScope] blocked removed capability', [
                'ws' => $wsId, 'engine' => $engine, 'action' => $action, 'reason' => $__scopeDenied,
                'agent' => $agentId ?? null,
            ]);
            return [
                'success' => false,
                'error'   => 'This capability is not available in the current plan.',
                'code'    => $__scopeDenied,
            ];
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
                return [
                    'success' => false,
                    'error'   => "Not enough credits to run this action. {$creditCost} credit"
                                 . ($creditCost === 1 ? '' : 's') . " required — top up to continue.",
                    'code'    => 'NO_CREDITS',
                    'required_credits' => $creditCost,
                ];
            }
            // Reserve credits
            $reservationId = $this->creditService->reserve($wsId, $creditCost, "{$engine}/{$action}");
        }

        // ─── Step 4: Check approval requirements ─────────────
        $approvalLevel = $capability['approval_level'] ?? 'auto';

        /* b8-plan */
        // Plan-aware downgrade — when a task runs as part of an approved
        // execution_plan (status=executing), 'review' level actions are
        // pre-authorized via the plan's single approval. 'protected' actions
        // (publish_*, send_*, publish_pack, publish_website) are NEVER bypassed
        // — they're the publishing second-gate per the user's design:
        //   "single approval for the Task Group, autopilot generation,
        //    pause on publishing for preview review"
        $planAuthorized = false;
        $planIdInCtx    = (int) ($context['plan_id'] ?? 0);

        // b18 (2026-07-24) — CAPTURED PUBLISH CONSENT (narrows the rule above).
        //
        // A 'protected' action is still never bypassed by the mere existence of
        // a plan. But when the user gave an EXPLICIT publish instruction, Sarah
        // answered with a confirmation naming exactly what would go live, and
        // the user approved that plan, the approval IS the publish click. Asking
        // again per article is the same double-consent removed from the chat
        // path on 2026-07-23 — and on a recurring plan ("publish 2 every 5
        // minutes") it meant Sarah queued review items on a cadence instead of
        // carrying out the instruction.
        //
        // Consent is recorded by SarahOrchestrator::approvePlan ONLY for plans
        // that actually passed through the human approval gate, and lists the
        // exact action names disclosed. An action not on that list is untouched
        // by this and still stops for review.
        $consentAuthorized = false;
        if ($planIdInCtx > 0 && $approvalLevel === 'protected') {
            $consentPlan = DB::table('execution_plans')
                ->where('id', $planIdInCtx)
                ->where('workspace_id', $wsId)
                ->where('status', 'executing')
                ->whereNotNull('approved_at')
                ->first(['id', 'strategy_json']);

            if ($consentPlan) {
                $consent = json_decode((string) $consentPlan->strategy_json, true)['publish_consent'] ?? null;
                if (is_array($consent) && in_array($action, $consent['actions'] ?? [], true)) {
                    $consentAuthorized = true;
                    $approvalLevel     = 'auto';
                    Log::info('[EES] executing under captured plan publish-consent', [
                        'plan_id' => $planIdInCtx, 'engine' => $engine, 'action' => $action,
                        'workspace_id' => $wsId, 'agent' => $agentId,
                        'approved_by' => $consent['approved_by'] ?? null,
                        'approved_at' => $consent['approved_at'] ?? null,
                    ]);
                    try {
                        app(\App\Core\Audit\AuditLogService::class)
                            ->log($wsId, $consent['approved_by'] ?? null, 'plan.publish_consent_used',
                                  'execution_plan', $planIdInCtx,
                                  ['engine' => $engine, 'action' => $action, 'params' => $params]);
                    } catch (\Throwable $auditErr) {
                        Log::warning('[EES] publish-consent audit log failed: ' . $auditErr->getMessage());
                    }
                }
            }
        }

        if ($planIdInCtx > 0 && $approvalLevel === 'review') {
            $plan = DB::table('execution_plans')
                ->where('id', $planIdInCtx)
                ->where('workspace_id', $wsId)
                ->where('status', 'executing')
                ->first(['id', 'status', 'approved_at']);
            if ($plan) {
                $planAuthorized = true;
                $approvalLevel  = 'auto';
                Log::info('[EES] plan-authorized auto-approval', [
                    'plan_id' => $planIdInCtx, 'engine' => $engine, 'action' => $action,
                    'workspace_id' => $wsId, 'agent' => $agentId,
                ]);
                // Audit log every plan-authorized auto-approval so admins can
                // trace what ran under the plan's umbrella.
                try {
                    app(\App\Core\Audit\AuditLogService::class)
                        ->log($wsId, $userId, 'plan.auto_approved',
                              'execution_plan', $planIdInCtx,
                              ['engine' => $engine, 'action' => $action]);
                } catch (\Throwable $auditErr) {
                    Log::warning('[EES] plan-auth audit log failed: ' . $auditErr->getMessage());
                }
            }
        }

        // RISK-0099 / MONEY-1 (2026-08-29): a customer's OWN click is the review. 'review'-level
        // work triggered directly from the app by a signed-in user runs; only 'protected'
        // (publish / delete) still goes through the approval queue. Without this, POST
        // /social/posts from the composer produced an ORPHAN approval (#12313, task_id NULL — the
        // fallback path, because 'create_post' is not a capability-map key) and the customer saw
        // "Action requires approval" for a draft they had just written.
        $__directUserAction = $source === 'manual' && ! empty($context['user_id']) && empty($agentId);
        if ($__directUserAction && $approvalLevel === 'review') {
            $approvalLevel = 'auto';
        }

        if ($approvalLevel !== 'auto') {
            /* b10-orphan-fix */
            // Use TaskService::create instead of ApprovalService::requestIfNeeded.
            // requestIfNeeded creates orphan approvals (task_id=NULL) which then
            // crash ApprovalService::approve() at $approval->task->update().
            // TaskService::create is the canonical creator: it makes a Task row
            // AND a linked Approval row in one call (see ManualExecutionController
            // line 82 for the established pattern).
            $createdTask = null;
            $approval = null;
            // RISK-0099 / MONEY-1 (2026-08-29): a customer's OWN click in the app is the
            // authorisation. Outside a Sarah chat turn SpendContext has no turn and fails closed,
            // so every paid 'review'-level action a customer triggered by hand (Social "Generate
            // with AI", hashtags, …) was silently parked in the approval queue and the UI got
            // AWAITING_APPROVAL for something the user had just asked for. Direct user actions
            // authorise their own spend and auto-approve the review tier; 'protected' (publish,
            // delete) still requires the explicit approval step.
            $__directUserAction = $source === 'manual' && ! empty($context['user_id']) && empty($agentId);
            if ($__directUserAction) {
                try {
                    app(\App\Core\Sarah888\SpendContext::class)->setTurn([
                        'authorized' => true, 'specifies_action' => true,
                        'reason' => 'direct user action in the app', 'classification' => 'authorisation',
                    ], $wsId);
                } catch (\Throwable) { /* fail closed: TaskService will hold the spend */ }
            }
            try {
                $createdTask = $this->taskService->create($wsId, [
                    'engine'          => $engine,
                    'action'          => $action,
                    'payload'         => $params,
                    'source'          => $source,
                    'auto_approve'    => $__directUserAction ? true : ($params['auto_approve'] ?? null),
                    'priority'        => $priority,
                    'assigned_agents' => $agentId ? [$agentId] : null,
                    // INFRA888 Phase 1D — carry the REQUESTER through so the
                    // approval layer can enforce separation of duties. Without
                    // it, protected capabilities fail closed on approval.
                    'user_id'         => $context['user_id'] ?? null,
                    'agent_id'        => $agentId,
                ]);
                if ($createdTask && $createdTask->requires_approval) {
                    $approval = \App\Models\Approval::where('task_id', $createdTask->id)
                        ->where('status', 'pending')
                        ->orderByDesc('id')
                        ->first();
                    // Defensive: if TaskService batch-folded into an existing
                    // approval (same workspace+batch_id+action), look that up.
                    if (!$approval && $createdTask->batch_id) {
                        $approval = \App\Models\Approval::where('workspace_id', $wsId)
                            ->where('batch_id', $createdTask->batch_id)
                            ->where('action', $action)
                            ->where('status', 'pending')
                            ->first();
                    }
                }
            } catch (\Throwable $createErr) {
                /* b10b-idempotency */
                // Most common cause of throw: idempotency_key UNIQUE conflict
                // (same action+params already pending). Look up the existing
                // task + its approval rather than creating an orphan via the
                // legacy fallback.
                $payload = $params;
                if (is_array($payload)) ksort($payload);
                $idemKey = hash('sha256', "{$wsId}:{$action}:" . json_encode($payload));
                $existingTask = \App\Models\Task::where('workspace_id', $wsId)
                    ->where('idempotency_key', $idemKey)
                    ->orderByDesc('id')
                    ->first();
                if ($existingTask) {
                    $approval = \App\Models\Approval::where('task_id', $existingTask->id)
                        ->where('status', 'pending')
                        ->orderByDesc('id')
                        ->first();
                    Log::info('[EES] TaskService.create dedupe — reusing existing task', [
                        'task_id' => $existingTask->id, 'approval_id' => $approval?->id,
                    ]);
                } else {
                    Log::warning('[EES] TaskService.create failed (no existing task to reuse), falling back to requestIfNeeded', [
                        'engine' => $engine, 'action' => $action, 'err' => $createErr->getMessage(),
                    ]);
                    $approval = $this->approvalService->requestIfNeeded($wsId, $engine, $action, $approvalLevel, $params);
                }
            }
            if ($approval && ($approval['status'] ?? 'pending') === 'pending') {
                /* b9-publish */
                // When a PROTECTED action is gated inside an EXECUTING plan,
                // also enqueue a rich-preview row for the user-facing publish
                // queue. The actual gate is still the approvals row above;
                // PublishGate is the preview-aware view ON TOP of it.
                // Plan must be in status='executing' — draft / completed /
                // other states do not qualify (matches B8 semantics).
                if ($approvalLevel === 'protected' && $planIdInCtx > 0) {
                    $planStatusForB9 = DB::table('execution_plans')
                        ->where('id', $planIdInCtx)
                        ->where('workspace_id', $wsId)
                        ->value('status');
                    if ($planStatusForB9 === 'executing') {
                        try {
                            $approvalId = is_object($approval) ? ($approval->id ?? 0)
                                         : (is_array($approval) ? ($approval['id'] ?? 0) : 0);
                            if ($approvalId > 0) {
                                app(\App\Core\Orchestration\PublishGateService::class)
                                    ->enqueue($wsId, $planIdInCtx, (int) $approvalId, $engine, $action, $params);
                            }
                        } catch (\Throwable $pgErr) {
                            Log::warning('[EES] PublishGate enqueue failed: ' . $pgErr->getMessage());
                        }
                    }
                }
                // Release reserved credits — will re-reserve on approval
                if (isset($reservationId)) $this->creditService->release($wsId, $reservationId);

                // T_NOTIF — flag approval-required to workspace owner (agent-driven only)
                if ($source === 'agent') {
                    $this->notifyTaskEvent($wsId, \App\Core\Notifications\NotificationTypes::AGENT_TASK_REQUIRES_APPROVAL,
                        'Approval required',
                        "An agent task ({$engine}/{$action}) requires your approval before it can run.",
                        'warning', '/approvals');
                }

                return ['success' => true, 'pending_approval' => true, 'approval_id' => $approval['id'],
                        'message' => 'Action requires approval', 'code' => 'AWAITING_APPROVAL'];
            }
        }

        // ─── Step 5: Execute the actual engine action ────────
        // ─── Step 4b (Phase I): open an observational CreativeJob (Studio only) ──
        $__cjs  = app(\App\Engines\Studio\Services\CreativeJobService::class);
        $__cjob = in_array($engine, ['creative', 'studio'], true)
            ? $this->cjSafe(fn () => $__cjs->begin([
                'workspace_id'    => $wsId,
                'user_id'         => $context['user_id'] ?? null,
                'type'            => $action === 'generate_video' ? 'video' : 'generation',
                'capability'      => $action,
                'original_prompt' => is_string($params['prompt'] ?? null) ? $params['prompt'] : null,
                'source'          => $source,
            ]))
            : null;

        try {
            $result = $this->dispatchToEngine($wsId, $engine, $action, $params, $context);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            if (isset($reservationId)) $this->creditService->release($wsId, $reservationId);
            $this->cjSafe(fn () => $__cjs->fail($__cjob, 'Resource not found'));
            return ['success' => false, 'error' => 'Resource not found', 'code' => 'NOT_FOUND'];
        } catch (\Throwable $e) {
            // Release credits on failure
            if (isset($reservationId)) $this->creditService->release($wsId, $reservationId);
            Log::error("EngineExecution failed: {$engine}/{$action}", ['error' => $e->getMessage(), 'ws' => $wsId, 'user_id' => $userId, 'agent_id' => $agentId, 'source' => $source]);

            // T_NOTIF — flag task failure to workspace owner (agent-driven only)
            if ($source === 'agent') {
                $this->notifyTaskEvent($wsId, \App\Core\Notifications\NotificationTypes::AGENT_TASK_FAILED,
                    'Agent task failed',
                    "Agent task {$engine}/{$action} failed: " . $e->getMessage(),
                    'error', '/agents');
            }

            $this->cjSafe(fn () => $__cjs->fail($__cjob, $e->getMessage()));
            return ['success' => false, 'error' => $e->getMessage(), 'code' => 'EXECUTION_FAILED'];
        }

        // ─── Step 5b (Phase H1): truthful billing for creative/studio ──────
        // CreativeService and StudioAiService RETURN structured failures instead of
        // throwing (status=failed / success=false / runtime_unavailable / ambiguous).
        // Without this, Step 6 would commit the reservation and the caller would see
        // success:true for a failed generation. Detect a semantic failure, RELEASE
        // (never commit), and return a truthful sanitized failure. Scoped to
        // creative+studio ONLY — all other engines are unaffected.
        if (in_array($engine, ['creative', 'studio'], true)
            && is_array($result)
            && ! $this->creativeResultIsSuccessful($result)) {
            if (isset($reservationId) && $creditCost > 0) {
                $this->creditService->release($wsId, $reservationId);
            }
            if ($source === 'agent') {
                $this->notifyTaskEvent($wsId, \App\Core\Notifications\NotificationTypes::AGENT_TASK_FAILED,
                    'Agent task failed',
                    "Agent task {$engine}/{$action} did not complete.",
                    'error', '/agents');
            }
            $this->cjSafe(fn () => $__cjs->fail($__cjob, (string) ($result['error'] ?? $result['status'] ?? 'generation_failed')));
            return $this->creativeFailureResponse($engine, $action, $result);
        }

        // ─── Step 5c (REPORT-0027, 2026-09-02): a builder edit that changed nothing must not be billed ──
        // editPage now returns success:false for a no-op (no field matched / 0 actions). Without this it
        // was committed here and the task marked completed (Chef Red phone-in-footer). Release, fail, be honest.
        if ($engine === 'builder' && is_array($result) && array_key_exists('success', $result) && $result['success'] === false) {
            if (isset($reservationId) && $creditCost > 0) {
                $this->creditService->release($wsId, $reservationId);
            }
            if ($source === 'agent') {
                $this->notifyTaskEvent($wsId, \App\Core\Notifications\NotificationTypes::AGENT_TASK_FAILED,
                    'Agent task made no change',
                    "{$engine}/{$action} did not change anything: " . (string) ($result['reply'] ?? $result['error'] ?? 'nothing was applied'),
                    'error', '/agents');
            }
            $this->cjSafe(fn () => $__cjs->fail($__cjob, (string) ($result['error'] ?? $result['reply'] ?? 'no_effect')));
            return [
                'success'      => false,
                'error'        => (string) ($result['reply'] ?? $result['error'] ?? 'No change was made.'),
                'code'         => 'NO_EFFECT',
                'data'         => $result,
                'credits_used' => 0,
            ];
        }

        // ─── Step 5d (F-STUDIO-G-EDIT-DUPCHARGE, 2026-09-03): a duplicate submit that
        // REPLAYED a cached result (same idempotency_key) did NO new work and must NOT be
        // charged again. The interactive creative/edit route has a GET_LOCK guard, but the
        // sync kernel path (Sarah/agent-driven) did not — a same-key edit_image returned the
        // SAME child asset yet the reservation was committed a second time (double-charge).
        // Release the reservation and report zero credits for a replay.
        if (is_array($result) && ($result['idempotent_replay'] ?? false) === true) {
            if (isset($reservationId) && $creditCost > 0) {
                $this->creditService->release($wsId, $reservationId);
            }
            $this->cjSafe(fn () => $__cjs->complete($__cjob, ['status' => 'completed', 'asset_id' => (isset($result['asset_id']) && is_numeric($result['asset_id'])) ? (int) $result['asset_id'] : null]));
            return array_merge($result, ['success' => true, 'credits_used' => 0]);
        }

        // ─── Step 6: Commit credits ──────────────────────────
        if (isset($reservationId) && $creditCost > 0) {
            $this->creditService->commit($wsId, $reservationId, $creditCost);
        }

        // ─── Step 6b (Phase I): finalize the observational CreativeJob ──
        if ($__cjob) {
            $this->cjSafe(fn () => $__cjs->complete($__cjob, [
                'status'   => (is_array($result) && ($result['status'] ?? null) === 'in_progress') ? 'running' : 'completed',
                'asset_id' => (is_array($result) && isset($result['asset_id']) && is_numeric($result['asset_id'])) ? (int) $result['asset_id'] : null,
                'provider' => (is_array($result) && isset($result['provider'])) ? $result['provider'] : null,
            ]));
        }

        // ─── Step 7: Fire automation triggers ────────────────
        $triggers = $this->fireAutomationTriggers($wsId, $engine, $action, $params, $result);

        // ─── Step 8: Cross-engine sync ───────────────────────
        $this->crossEngineSync($wsId, $engine, $action, $params, $result);

        // ─── Step 9: Audit log ───────────────────────────────
        $this->auditLog->log($wsId, $userId, "{$engine}.{$action}", ucfirst($engine), $result['entity_id'] ?? null, [
            'source' => $source, 'agent' => $agentId, 'credits' => $creditCost, 'params' => array_keys($params),
        ]);

        // T_NOTIF — flag task completion to workspace owner (agent-driven only)
        if ($source === 'agent') {
            $this->notifyTaskEvent($wsId, \App\Core\Notifications\NotificationTypes::AGENT_TASK_COMPLETED,
                'Agent task completed',
                "{$engine}/{$action} completed successfully.",
                'success', null);
        }

        // ─── Step 10: Record intelligence (learning loop) ────
        try {
            // Record agent experience (if agent-driven)
            if ($agentId) {
                $agent = \App\Models\Agent::where('slug', $agentId)->first();
                if ($agent) {
                    $workspace = \App\Models\Workspace::find($wsId);
                    app(\App\Core\Intelligence\AgentExperienceService::class)
                        ->recordTaskCompletion($agent->id, $engine, $action, $workspace?->industry, [
                            'success' => ($result['success'] ?? true), 'tokens_used' => $result['usage']['total_tokens'] ?? 0,
                        ]);
                }
            }

            // Record engine tool usage via ToolFeedbackService (the learning loop).
            // Converts outcome into an effectiveness score and feeds it back
            // to EngineIntelligenceService::recordToolUsage (which is now self-healing).
            app(\App\Core\Intelligence\ToolFeedbackService::class)->record($engine, $action, [
                'success' => ($result['success'] ?? true),
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

        // PATCH (Phase 2G — confidence scoring, 2026-05-10) — record a
        // 0.0..1.0 confidence on every async task so the admin dashboard
        // (and future risk-based gating) has a signal to read. The score
        // is INFORMATIONAL on this pass — the canonical approval gate
        // remains CapabilityMapService.
        try {
            $conf = app(\App\Core\Orchestration\ConfidenceScorer::class)
                ->score($engine, $action, $params, $wsId);
            \Illuminate\Support\Facades\DB::table('tasks')->where('id', $task->id)->update([
                'confidence_score'  => $conf['score'],
                'confidence_reason' => mb_substr($conf['reason'], 0, 500),
            ]);
        } catch (\Throwable $confErr) {
            Log::warning("ConfidenceScorer failed for task {$task->id}: " . $confErr->getMessage());
        }

        Log::info("Task {$task->id} created via TaskService (async)", [
            'engine' => $engine, 'action' => $action, 'ws' => $wsId,
            'status' => $task->status, 'requires_approval' => $task->requires_approval,
        ]);

        // PATCH 2026-05-09 — push to the queue. Tasks awaiting approval are
        // not dispatched (they wait on ApprovalService). Tasks already in a
        // terminal state are skipped defensively.
        $dispatched = false;
        if (! $task->requires_approval && in_array($task->status, ['pending', 'queued'], true)) {
            try {
                $this->dispatcher->dispatch($task);
                $dispatched = true;
            } catch (\Throwable $e) {
                Log::error("executeAsync dispatch failed for task {$task->id}: " . $e->getMessage());
                // Row stays pending; queue worker / scheduler can retry.
            }
        }

        return [
            'success'          => true,
            'task_id'          => $task->id,
            'status'           => $task->status,
            'requires_approval'=> $task->requires_approval,
            'dispatched'       => $dispatched,
            'mode'             => 'async',
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // ENGINE DISPATCH — routes action to correct engine service
    // ═══════════════════════════════════════════════════════════

    /**
     * Phase H1 — semantic-success predicate for creative/studio downstream results.
     * CreativeService returns a lifecycle `status` (completed/in_progress/pending on
     * success, failed on failure); StudioAiService returns an explicit `success`
     * boolean. Anything without a recognized positive signal is treated as a failure
     * so an ambiguous/malformed payload is never billed as success.
     */
    /**
     * Phase I — run a CreativeJob observational side-write with total isolation.
     * A CreativeJob fault MUST NEVER affect execution, billing, or the response.
     */
    private function cjSafe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('[CreativeJob] hook error (ignored, execution unaffected): ' . $e->getMessage());
            return null;
        }
    }

    private function creativeResultIsSuccessful(array $result): bool
    {
        if (array_key_exists('success', $result)) {
            return $result['success'] === true;
        }

        if (isset($result['status']) && is_scalar($result['status'])) {
            $status = strtolower((string) $result['status']);
            if (in_array($status, ['failed', 'error', 'cancelled', 'canceled', 'timeout', 'timed_out'], true)) {
                return false;
            }
            if (in_array($status, ['completed', 'complete', 'success', 'succeeded', 'in_progress',
                                    'processing', 'pending', 'queued', 'dispatching', 'ok', 'created', 'done'], true)) {
                return true;
            }
            return false; // unrecognized status → fail safe (do not bill)
        }

        return false; // no success flag and no status → ambiguous → fail safe
    }

    /**
     * Phase H1 — truthful, sanitized failure envelope for a non-throwing
     * creative/studio failure. Surfaces a known-safe snake_case code where the
     * service provided one (runtime_unavailable, image_generation_failed, …) and a
     * generic user message; never leaks raw provider messages, bodies, or secrets.
     */
    private function creativeFailureResponse(string $engine, string $action, array $result): array
    {
        $code = 'generation_failed';
        foreach (['code', 'error'] as $k) {
            if (isset($result[$k]) && is_string($result[$k]) && preg_match('/^[a-z][a-z0-9_]{2,40}$/', $result[$k])) {
                $code = $result[$k];
                break;
            }
        }

        return [
            'success' => false,
            'error'   => 'Creative generation did not complete. No credits were charged.',
            'code'    => strtoupper($code),
            'engine'  => $engine,
            'action'  => $action,
        ];
    }

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
            // PATCH 2026-05-09 — wire the two engines whose services exist
            // but were never reachable through the kernel switch. Note:
            // CapabilityMapService still has no rows for these engines, so
            // `resolveAction()` at line 75 will reject any action with code
            // INVALID_ACTION before reaching this switch. These arms exist
            // so once cap-map rows are added, no second patch is required.
            'chatbot' => $this->executeChatbotAction($wsId, $action, $params, $context),
            'studio'  => $this->executeStudioAction($wsId, $action, $params, $context),
            'content' => $this->executeContentAction($wsId, $action, $params, $context), /* h1-batch3-arm */
            'sarah'   => $this->executeSarahAction($wsId, $action, $params, $context), /* b4-sarah-arm */
            'infrastructure' => $this->executeInfrastructureAction($wsId, $action, $params, $context), /* INFRA888 */
            default => throw new \RuntimeException("Unknown engine: {$engine}"),
        };

        return is_array($result) ? $result : ['result' => $result];
    }

    /**
     * INFRA888 (2026-07-18) — infrastructure operation dispatch.
     *
     * Fails closed: an action absent from InfrastructureCapabilityRegistry throws
     * rather than silently no-opping, so capability/handler drift is a hard error.
     */
    private function executeInfrastructureAction(int $wsId, string $action, array $params, array $ctx): array
    {
        if (!\App\Engines\Infrastructure\Registry\InfrastructureCapabilityRegistry::has($action)) {
            throw new \RuntimeException("Unknown infrastructure action: {$action}");
        }

        $svc = app(\App\Engines\Infrastructure\Services\ProvisioningService::class);

        $catalog = app(\App\Engines\Infrastructure\Services\CatalogAuthoringService::class);
        $migrations = app(\App\Engines\Infrastructure\Services\SubscriberMigrationPlanner::class);
        $actor = $ctx['user_id'] ?? null;

        return match ($action) {
            'provision_hosting' => $svc->execute($wsId, $params),

            // Phase 2A-2 catalog authoring. Platform-level commercial changes:
            // no provider mutation, no workspace resource, but they determine
            // what every customer can buy.
            'publish_product' => ['success' => true, 'data' => $catalog
                ->publishProduct((int) ($params['product_id'] ?? 0), $actor)->toArray()],
            'deprecate_product' => ['success' => true, 'data' => $catalog
                ->deprecateProduct((int) ($params['product_id'] ?? 0), $actor)->toArray()],
            'retire_product' => ['success' => true, 'data' => $catalog
                ->retireProduct((int) ($params['product_id'] ?? 0), $params['successor_product_id'] ?? null, $actor)->toArray()],
            'publish_plan' => ['success' => true, 'data' => $catalog
                ->publishPlan((int) ($params['plan_id'] ?? 0), $actor)->toArray()],
            'withdraw_plan' => ['success' => true, 'data' => $catalog
                ->withdrawPlan((int) ($params['plan_id'] ?? 0), $params['successor_plan_id'] ?? null, $actor)->toArray()],
            'plan_subscriber_migration' => ['success' => true, 'data' => $migrations
                ->plan(
                    (int) ($params['from_plan_id'] ?? 0),
                    (int) ($params['to_plan_id'] ?? 0),
                    (string) ($params['strategy'] ?? 'at_renewal'),
                    $params['reason'] ?? null,
                    $actor
                )->toArray()],

            default => throw new \RuntimeException("No handler for infrastructure action: {$action}"),
        };
    }

    private function executeCrmAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\CRM\Services\CrmService::class);
        return match ($action) {
            'create_lead' => ['entity_type' => 'Lead', 'entity_id' => $svc->createLead($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null]))->id, 'action' => 'created'],
            // 2026-05-22 FIX 17 — list_leads sync dispatch.
            'list_leads' => $svc->listLeads($wsId, $params),
            'update_lead' => ['entity_type' => 'Lead', 'entity_id' => $params['lead_id'], 'data' => $svc->updateLead($params['lead_id'], $params, $ctx['user_id'] ?? null, $wsId)],
            'delete_lead' => ['entity_type' => 'Lead', 'entity_id' => $params['lead_id'], 'action' => 'deleted'] + (function() use ($svc, $params, $wsId) { $svc->deleteLead($params['lead_id'], $wsId); return []; })(),
            'score_lead' => ['entity_type' => 'Lead', 'entity_id' => $params['lead_id'], 'data' => $svc->scoreLead($params['lead_id'], $params['score'] ?? null, $wsId)],
            'assign_lead' => ['entity_type' => 'Lead', 'entity_id' => $params['lead_id'], 'data' => $svc->assignLead($params['lead_id'], $params['assigned_to'] ?? null, $ctx['user_id'] ?? null, $wsId)],
            'import_leads' => $svc->importLeads($wsId, $params['rows'] ?? [], $ctx['user_id'] ?? null),
            'create_contact' => ['entity_type' => 'Contact', 'entity_id' => $svc->createContact($wsId, $params)->id],
            'merge_contacts' => ['entity_type' => 'Contact', 'data' => $svc->mergeContacts($wsId, $params['keep_id'], $params['merge_id'])],
            'create_deal' => ['entity_type' => 'Deal', 'entity_id' => $svc->createDeal($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null]))->id],
            'update_deal_stage' => ['entity_type' => 'Deal', 'entity_id' => $params['deal_id'], 'data' => $svc->updateDealStage($params['deal_id'], $params['stage'], $ctx['user_id'] ?? null, $wsId)],
            'log_activity' => ['entity_type' => 'Activity', 'entity_id' => $svc->logActivity($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null]))->id],
            'add_note' => ['entity_type' => 'Note', 'entity_id' => $svc->addNote($wsId, $params['entity_type'], $params['entity_id'], $params['body'], $ctx['user_id'] ?? null)->id],
            // Sarah × CRM Phase 1 — AI surface kernel-reachable /* b5-crm-arms */
            'generate_outreach'   => $svc->generateOutreach($wsId, $params),
            'generate_followup'   => $svc->generateFollowUp($wsId, $params),
            'ai_followup_draft'   => $svc->generateFollowUp($wsId, $params),
            'ai_reply_suggestion' => $svc->aiReplySuggestion($wsId, $params),
            'ai_lead_scoring'     => $svc->aiLeadScoring($wsId, $params),
            // v1.4.4 (2026-05-30) — Phase B wiring
            'move_lead' => ['entity_type' => 'Lead', 'entity_id' => $params['lead_id'] ?? 0, 'data' => $svc->updateLead((int) ($params['lead_id'] ?? 0), ['status' => (string) ($params['stage'] ?? '')], $ctx['user_id'] ?? null, $wsId)],
            'list_sequences' => app(\App\Engines\Marketing\Services\SequenceService::class)->listSequences($wsId),
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
            // 2026-05-22 FIX 13c — list_keywords + stub handlers for FIX 9
            // keyword tools, so the SYNC execution path (Sarah chat ->
            // ToolSchemaService::executeToolCall -> EngineExecutionService)
            // works for them, not just the async Orchestrator dispatch.
            'list_keywords' => $svc->listKeywords($wsId, $params),
            'keyword_research' => $svc->keywordResearch($wsId, $params),
            'keywords_suggest' => $svc->keywordResearch($wsId, $params),
            'keyword_check' => ['success' => false, 'not_implemented' => true, 'message' => 'keyword_check is in the catalog but not yet wired to a service. Use serp_analysis for ranking lookups.'],
            'link_suggestions', 'generate_links' => $svc->generateLinkSuggestions($wsId, $params),
            'insert_link' => ['inserted' => $svc->insertLink($wsId, $params['link_id'] ?? 0)],
            'dismiss_link' => ['dismissed' => $svc->dismissLink($wsId, $params['link_id'] ?? 0)],
            'check_outbound' => $svc->checkOutbound($wsId, $params),
            // RISK-0091 (2026-08-25): outbound_links was cap-mapped (2cr) with NO executor. Wired to the real method.
            'outbound_links' => $svc->outboundLinks($wsId, $params),
            // Sarah/agent<->SEO alignment: these status/read caps are in the capability
            // map + granted to James/Sarah but had NO executeSeoAction arm -> agents hit
            // "Unknown SEO action". Wire them to the same SeoService methods the HTTP
            // controller uses.
            'ai_status' => $svc->aiStatus($wsId),
            'list_goals' => $svc->listGoals($wsId),
            'agent_status' => $svc->agentStatus($wsId),
            'create_goal', 'autonomous_goal' => $svc->createGoal($wsId, $params),
            'pause_goal' => ['paused' => $svc->pauseGoal($wsId, $params['goal_id'] ?? 0)],
            'resume_goal' => ['resumed' => $svc->resumeGoal($wsId, $params['goal_id'] ?? 0)],
            // v1.4.4 (2026-05-30) — Phase B competitive intelligence
            'competitor_serp' => $svc->competitorSerp($wsId, $params),
            'competitor_gaps' => $svc->competitorGaps($wsId, $params),
            // RISK-0091 (2026-08-25): these were async-only; sync tool_calls threw. Mirror the
            // proven Orchestrator routing so the same action works on both paths.
            'link_suggestions' => $svc->generateLinkSuggestions($wsId, $params),
            'fix_orphans'      => $svc->fixOrphans($wsId, $params),
            'gsc_sync'         => app(\App\Engines\SEO\Services\GscSyncService::class)->sync($wsId, $params),
            default => throw new \RuntimeException("Unknown SEO action: {$action}"),
        };
    }

    private function executeWriteAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Write\Services\WriteService::class);
        return match ($action) {
            'create_article', 'write_article' => $svc->createArticle($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null])),
            'update_article' => $svc->updateArticle($params['article_id'], $params, $wsId),
            'improve_draft' => $svc->improveDraft($wsId, $params),
            'generate_outline' => $svc->generateOutline($wsId, $params),
            'generate_headlines' => $svc->generateHeadlines($wsId, $params),
            'generate_meta' => $svc->generateMeta($wsId, $params),
            // b17 (2026-07-24) — both of these are registered in
            // CapabilityMapService and have real WriteService implementations,
            // but neither was reachable from a Sarah plan: this match() threw
            // "Unknown Write action" for them. publish_article was only wired
            // into the async chat path (TaskSystem\Orchestrator), so a plan
            // could never publish anything.
            'aeo_enrich' => $svc->aeoEnrich($wsId, $params),
            'fill_missing_images' => $svc->fillMissingImages($wsId, $params),
            // Canonical publish, matching the chat path and the API endpoint:
            // status=published (updateArticle stamps published_at on first
            // publish and re-syncs the SEO index) plus is_marketing_blog, which
            // BuilderRenderer::renderArticle requires or the live URL 404s.
            // approval_mode=protected still gates whether this runs at all.
            'publish_article' => $svc->updateArticle(
                (int) ($params['article_id'] ?? 0),
                ['status' => 'published', 'is_marketing_blog' => 1],
                $wsId
            ),
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
            // RISK-0091 (2026-08-25): mini/high were async-only; sync tool_calls threw
            // "Unknown Creative action". Route to the same real image generation (tier is
            // metered via the cap-map; quality handling is Runtime-side, RISK-0090).
            'generate_image_mini' => $svc->generateImage($wsId, $params),
            'generate_image_high' => $svc->generateImage($wsId, $params),
            // STUDIO888 Phase O — masked/local image editing (non-destructive child version).
            'edit_image'     => $svc->editImage($wsId, $params),
            'create_asset'   => $svc->createAsset($wsId, $params),
            default => throw new \RuntimeException("Unknown Creative action: {$action}"),
        };
    }

    private function executeBuilderAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Builder\Services\BuilderService::class);
        return match ($action) {
            'create_website' => $svc->createWebsite($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null])),
            'generate_page' => (function() use ($svc, $params, $wsId) { if (!\Illuminate\Support\Facades\DB::table('websites')->where('id', $params['website_id'] ?? 0)->where('workspace_id', $wsId)->exists()) throw new \RuntimeException('Website not found'); return $svc->createPage($params['website_id'], $params); })(),
            // RISK-0091 (2026-08-25): wizard_generate is advertised (EngineIntelligence) as the
            // website wizard but BuilderService::wizardGenerate returns 'gone' (retired 2026-04-19).
            // Route it to the SAME proven Arthur build as full_site_generation so the advertised
            // wizard actually builds instead of dead-ending. (Cost reconciliation vs full_site_generation
            // is a noted follow-up.)
            'wizard_generate' => app(\App\Engines\Builder\Services\ArthurService::class)
                ->buildFromChat($wsId, $params['build_data'] ?? $params, $params['logo_url'] ?? null, $params['images'] ?? [], $params['colors'] ?? [], $ctx['user_id'] ?? null),
            'publish_website' => ['action' => 'published'] + (function() use ($svc, $params, $wsId) { if (!\Illuminate\Support\Facades\DB::table('websites')->where('id', $params['website_id'] ?? 0)->where('workspace_id', $wsId)->exists()) throw new \RuntimeException('Website not found'); $svc->publishWebsite($params['website_id']); return []; })(),
            'publish_builder_page' => ['action' => 'published'] + (function() use ($svc, $params, $wsId) { return $svc->publishPage((int) ($params['page_id'] ?? 0), $wsId); })(),
            'import_html_page' => (function() use ($svc, $params, $wsId) { return $svc->importHtmlPage((int) ($params['website_id'] ?? 0), $params, $wsId); })(),
            // v1.4.4 (2026-05-30) — Phase B landing-page visibility
            'list_builder_pages' => $svc->listWorkspacePages($wsId, $params),
            'get_builder_page' => $svc->getPage((int) ($params['page_id'] ?? 0), $wsId) ?? ['success' => false, 'error' => 'Page not found'],
            // v1.4.4 (2026-05-30) — page editing.
            //   update_page → direct mutation via BuilderService (auto-snapshots).
            //   ai_builder_action → hands off to Arthur, who proposes structured
            //   actions on sections_json and applies them atomically. This is
            //   Sarah's coordination path with Arthur.
            'update_page' => (function () use ($svc, $params, $wsId) {
                $svc->updatePage((int) ($params['page_id'] ?? 0), $params, $wsId);
                return ['entity_type' => 'Page', 'entity_id' => (int) ($params['page_id'] ?? 0), 'action' => 'updated'];
            })(),
            'ai_builder_action' => app(\App\Engines\Builder\Services\ArthurEditService::class)
                ->editPage(
                    (int) ($params['page_id'] ?? 0),
                    (string) ($params['command'] ?? ''),
                    isset($params['section_index']) ? (int) $params['section_index'] : null,
                    array_merge($params['context'] ?? [], ['workspace_id' => $wsId, 'agent_slug' => $ctx['agent_slug'] ?? 'sarah']) /* TN-1: trusted keys LAST so a model-supplied context cannot override the authenticated workspace_id */
                ),
            // v1.4.4 Phase D-1 (2026-05-30) — add new page from universal template.
            'add_page_from_template' => $svc->addPageFromTemplate($wsId, $params),
            // RISK-0088 (2026-08-25): full_site_generation was a governed 10cr row
            // with NO executor (DISCONNECTED). Wire it to the PROVEN Arthur build
            // (buildFromChat -> generateWebsite) so Sarah's website capability does
            // real work instead of throwing "Unknown Builder action".
            'full_site_generation' => app(\App\Engines\Builder\Services\ArthurService::class)
                ->buildFromChat($wsId, $params['build_data'] ?? $params, $params['logo_url'] ?? null, $params['images'] ?? [], $params['colors'] ?? [], $ctx['user_id'] ?? null),
            default => throw new \RuntimeException("Unknown Builder action: {$action}"),
        };
    }

    private function executeMarketingAction(int $wsId, string $action, array $params, array $ctx): array
    {
        // 2026-05-22 FIX 17 — list_campaigns sync dispatch (short-circuit).
        if ($action === 'list_campaigns') {
            return app(\App\Engines\Marketing\Services\MarketingService::class)->listCampaigns($wsId, $params);
        }
        $svc = app(\App\Engines\Marketing\Services\MarketingService::class);
        $eb = app(\App\Engines\Marketing\Services\EmailBuilderService::class);
        return match ($action) {
            'create_campaign'   => $svc->createCampaign($wsId, array_merge($params, ['user_id' => $ctx['user_id'] ?? null])),
            'create_automation' => ['entity_type' => 'Automation', 'entity_id' => $svc->createAutomation($wsId, $params)],
            'schedule_campaign' => $svc->scheduleCampaign($params['campaign_id'] ?? 0, $params['scheduled_at'] ?? '', $wsId),
            'send_campaign'     => $svc->sendCampaign($wsId, $params['campaign_id'] ?? 0),  // Phase 3: was missing → credits reserved then released on RuntimeException
            // v1.4.4 (2026-05-30) — Phase B marketing ops
            'list_templates' => $svc->listTemplates($wsId),
            'create_template' => ['entity_type' => 'Template', 'entity_id' => $svc->createTemplate($wsId, $params)],
                        // Email Builder Phase 1 arms — wire EmailBuilderService methods
            'email_ai_generate'      => $eb->aiGenerate($wsId, $params),
            'email_block_rewrite'    => $eb->aiRewriteBlock((int) ($params['template_id'] ?? 0), (int) ($params['block_id'] ?? 0), (string) ($params['instruction'] ?? 'rewrite')),
            'email_subject_suggest'  => $eb->aiSuggestSubjects((int) ($params['template_id'] ?? 0), array_merge($params, ['workspace_id' => $wsId])),
            'email_spam_check'       => $eb->aiSpamCheck((int) ($params['template_id'] ?? 0), (string) ($params['subject'] ?? '')),
            'email_preview_template' => ['success' => true, 'html' => $eb->previewTemplate((int) ($params['template_id'] ?? 0), (array) ($params['variables'] ?? []), (string) ($params['format'] ?? 'desktop'))],
            'email_send_test'        => $eb->sendTest((int) ($params['template_id'] ?? $params['campaign_id'] ?? 0), (string) ($params['to_email'] ?? ''), (array) ($params['variables'] ?? [])),
            'email_validate_campaign'=> $eb->validateCampaign((int) ($params['campaign_id'] ?? 0)),
            'email_use_template'     => $eb->useSystemTemplate($wsId, (int) ($params['template_id'] ?? 0)),
            'email_template_picker'  => $this->emailTemplatePicker($wsId, $eb, $params),
            default => throw new \RuntimeException("Unknown Marketing action: {$action}"),
        };
    }

    private function executeSocialAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Social\Services\SocialService::class);
        return match ($action) {
            'social_create_post', 'create_post' => $svc->createPost($wsId, $params),
            'social_schedule_post', 'schedule_post' => ['scheduled' => true] + (function() use ($svc, $params, $wsId) { $svc->schedulePost((int) ($params['post_id'] ?? 0), (string) ($params['scheduled_at'] ?? ''), $wsId); return []; })(),
            'social_publish_post', 'publish_post' => $svc->publishPost((int) ($params['post_id'] ?? 0), $wsId),
            // v1.4.4 (2026-05-30) — Phase B social queue visibility
            'get_queue' => $svc->getCalendarPosts($wsId, $params['from'] ?? null, $params['to'] ?? null),
            // Sarah × Social Phase 1 — close half-built AI bridge
            'social_ai_post', 'ai_generate_post' => $svc->aiGeneratePost($wsId, $params),
            'hashtag_suggestions', 'generate_hashtags' => $svc->generateHashtags($wsId, $params),
            'social_image' => app(\App\Engines\Creative\Services\CreativeService::class)
                ->generateImage($wsId, array_merge(['style' => 'social_post', 'aspect' => '1:1'], $params)),
            // Sarah/Marcus<->Social alignment: these caps are granted to agents + cap-mapped
            // but lacked an executor arm -> agents hit "Unknown Social action".
            'list_posts'  => $svc->listPosts($wsId, $params),
            'update_post' => $svc->updatePost((int) ($params['post_id'] ?? $params['id'] ?? 0), $params, $wsId),
            'delete_post' => (function() use ($svc, $params, $wsId) { $svc->deletePost((int) ($params['post_id'] ?? $params['id'] ?? 0), $wsId); return ['deleted' => true]; })(),
            // No analytics recorder exists (and there is no connected platform yet) — return
            // a TRUTHFUL not-implemented rather than a hard "Unknown action" for the agent.
            'record_social_analytics' => ['success' => false, 'not_implemented' => true, 'message' => 'Social analytics recording activates once a social platform is connected for this workspace.'],
            default => throw new \RuntimeException("Unknown Social action: {$action}"),
        };
    }

    private function executeCalendarAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Calendar\Services\CalendarService::class);
        return match ($action) {
            'create_event' => ['entity_type' => 'Event', 'entity_id' => $svc->createEvent($wsId, $params)],
            // v1.4.4 (2026-05-30) — Phase B calendar visibility
            'list_events' => $svc->getEvents($wsId, $params['from'] ?? null, $params['to'] ?? null, $params['category'] ?? null),
            default => throw new \RuntimeException("Unknown Calendar action: {$action}"),
        };
    }

    private function executeBeforeAfterAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\BeforeAfter\Services\BeforeAfterService::class);
        return match ($action) {
            'ba_transform', 'create_design' => $svc->createDesign($wsId, $params),
            'ba_design_report' => (function() use ($svc, $params, $wsId) { $svc->generateReport((int) ($params['design_id'] ?? 0), $wsId); return ['status' => 'generated']; })(),  // Phase 3: was missing
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

    // PATCH 2026-05-09 — Studio engine routing.
    // Real public methods on StudioAiService: generateImage, generateDesign,
    // suggestCopy, chat. Wiring them so once CapabilityMapService gets
    // matching rows, an agent / UI can trigger them through the kernel.
        /**
     * Email template picker — ranks system templates by fit for the workspace.
     * Sarah × Email Phase 1: takes workspace brand kit + industry + intent,
     * scores all is_system=1 templates, returns top 5.
     */
    private function emailTemplatePicker(int $wsId, \App\Engines\Marketing\Services\EmailBuilderService $eb, array $params): array
    {
        $kit = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($wsId);
        $industry = (string) ($params['industry'] ?? $kit['industry'] ?? '');
        $intent   = (string) ($params['intent']   ?? $params['goal'] ?? 'announce');
        $templates = \Illuminate\Support\Facades\DB::table('email_templates')
            ->where('is_system', 1)
            ->where('is_active', 1)
            ->get(['id', 'name', 'category', 'thumbnail_url']);
        // Score: category match counts double, name keyword match counts single
        $scored = [];
        $intentLower = strtolower($intent);
        $industryLower = strtolower($industry);
        foreach ($templates as $t) {
            $score = 0.0;
            $catLower = strtolower($t->category ?? '');
            $nameLower = strtolower($t->name ?? '');
            if ($intentLower && (str_contains($catLower, $intentLower) || str_contains($nameLower, $intentLower))) $score += 2.0;
            if ($industryLower && (str_contains($nameLower, $industryLower) || str_contains($catLower, $industryLower))) $score += 2.0;
            // Bonus for industry-tagged templates from earlier seeding
            if ($industryLower && str_contains($nameLower, '·')) $score += 1.0;
            $scored[] = ['id' => $t->id, 'name' => $t->name, 'category' => $t->category, 'thumbnail_url' => $t->thumbnail_url, 'score' => $score];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return ['success' => true, 'ranked' => array_slice($scored, 0, 5), 'industry' => $industry, 'intent' => $intent, 'brand_grounded' => !$kit['is_neutral']];
    }

private function executeStudioAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Studio\Services\StudioAiService::class);
        return match ($action) {
            'generate_design' => $svc->generateDesign($wsId, $params),
            'generate_image'  => $svc->generateImage($wsId, $params),
            'suggest_copy'    => $svc->suggestCopy($wsId, $params),
            default => throw new \RuntimeException("Unknown Studio action: {$action}"),
        };
    }

    // PATCH 2026-05-09 — Chatbot engine routing.
    // The chatbot is session-driven (handleMessage takes a sessionId, not a
    // workspace + action), so the only sensible engine-action surface today
    // is knowledge-base ingestion. Adding more arms requires rethinking the
    // session boundary — defer until we have a real use case.
    private function executeChatbotAction(int $wsId, string $action, array $params, array $ctx): array
    {
        return match ($action) {
            'ingest_text' => [
                'source_id' => app(\App\Engines\Chatbot\Services\ChatbotKnowledgeService::class)
                    ->ingestText($wsId, $params['label'] ?? 'untitled', $params['text'] ?? ''),
            ],
            default => throw new \RuntimeException("Unknown Chatbot action: {$action}"),
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
            // Sarah × CRM Phase 1 — close lead→testimonial and lead→reactivation chains /* b5-crm-triggers */
            'crm.generate_outreach' => 'outreach_drafted',
            'crm.ai_followup_draft' => 'followup_drafted',
            'crm.generate_followup' => 'followup_drafted',
            'marketing.create_campaign' => 'campaign_created',
            'social.create_post' => 'post_created',
            'write.create_article' => 'article_created',
            'write.write_article' => 'article_created', // G5 — Sarah-driven article writes now fire the trigger
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

            /* b19-phase2-sync */
            // Marketing → AutomationCal: Sarah-driven email send (Email Mktg Cal)
            if ($engine === 'marketing' && $action === 'schedule_campaign' && !empty($params['scheduled_at'])) {
                app(\App\Core\Strategy\AutomationCalendarService::class)->createEvent($wsId, [
                    'title' => "Campaign: " . ($params['name'] ?? 'Untitled'),
                    'starts_at' => $params['scheduled_at'],
                    'category' => 'scheduled_email',
                    'engine' => 'marketing',
                    'reference_id' => $result['entity_id'] ?? ($params['campaign_id'] ?? null),
                    'reference_type' => 'campaign',
                    'color' => '#F59E0B',
                    'status' => 'scheduled',
                ]);
            }

            // Social → AutomationCal: scheduled post (system publishes, not user)
            if ($engine === 'social' && in_array($action, ['social_schedule_post', 'create_post']) && !empty($params['scheduled_at'])) {
                app(\App\Core\Strategy\AutomationCalendarService::class)->createEvent($wsId, [
                    'title' => "Post: " . substr($params['content'] ?? '', 0, 40),
                    'starts_at' => $params['scheduled_at'],
                    'category' => 'scheduled_post',
                    'engine' => 'social',
                    'reference_id' => $result['entity_id'] ?? ($params['post_id'] ?? null),
                    'reference_type' => 'social_post',
                    'color' => '#EC4899',
                    'status' => 'scheduled',
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

        // ── Strategy learning — Sarah's outcomes journal (2026-06-30) ──────────
        // Best-effort: record a strategy_outcomes row for proposal/strategy-origin
        // tasks so StrategyLearningService::getLearnings() can inform future
        // proposals (closes the learning-loop WRITE path). Keyed by raw action
        // slug so the read side can match per ProactiveRuleSet candidate. Runs
        // AFTER the task is already in a terminal state, fully guarded — a
        // failure here can never affect task completion or credit finalisation.
        try {
            $createdVia = $payload['created_via'] ?? null;
            if (in_array($createdVia, ['sarah_proposal', 'auto_orphan_rescue'], true)) {
                $resData = (is_array($result) && isset($result['data']) && is_array($result['data']))
                    ? $result['data']
                    : (is_array($result) ? $result : []);
                app(\App\Core\Intelligence\StrategyLearningService::class)->recordOutcome(
                    $task->workspace_id,
                    (string) $task->action,
                    [
                        'action'      => $task->action,
                        'engine'      => $task->engine,
                        'title'       => $payload['title'] ?? null,
                        'created_via' => $createdVia,
                        'task_id'     => $task->id,
                    ],
                    [
                        'status'  => $status,
                        'success' => $status === 'completed',
                        'applied' => $resData['applied'] ?? null,
                        'credits' => $payload['_credit_cost'] ?? null,
                        'summary' => $resData['message'] ?? ($result['summary'] ?? null),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning("handleRuntimeCallback: strategy outcome recording failed for task {$taskId}: {$e->getMessage()}");
        }
    }

    /**
     * T_NOTIF helper — look up workspace owner and dispatch a task-related
     * notification. Wrapped in try/catch so any notification failure never
     * breaks the engine execution flow.
     */
    private function notifyTaskEvent(
        int $wsId,
        string $type,
        string $title,
        string $body,
        string $severity,
        ?string $actionUrl
    ): void {
        try {
            $ownerId = \Illuminate\Support\Facades\DB::table('workspace_users')
                ->where('workspace_id', $wsId)
                ->where('role', 'owner')
                ->value('user_id');
            if (! $ownerId) return;

            $this->notifications->dispatch(
                type: $type,
                userId: (int) $ownerId,
                title: $title,
                workspaceId: $wsId,
                body: $body,
                severity: $severity,
                actionUrl: $actionUrl
            );
        } catch (\Throwable $e) {
            Log::warning("notifyTaskEvent failed: {$type}", ['error' => $e->getMessage()]);
        }
    }
    /* h1-batch3-method */
    private function executeContentAction(int $wsId, string $action, array $params, array $ctx): array
    {
        $svc = app(\App\Engines\Content\Services\ContentPackService::class);
        return match ($action) {
            'create_pack'  => $svc->createPack($wsId, $params),
            'add_asset'    => $svc->addAsset($wsId, $params),
            'get_pack'     => $svc->getPack($wsId, (int) ($params['pack_id'] ?? 0)),
            'list_packs'   => $svc->listPacks($wsId, $params),
            'publish_pack' => $svc->publishPack($wsId, $params),
            default        => throw new \RuntimeException("Unknown content action: $action"),
        };
    }

    /* b4-sarah-method */
    private function executeSarahAction(int $wsId, string $action, array $params, array $ctx): array
    {
        return match ($action) {
            'draft_campaign' => app(\App\Core\Orchestration\SarahCampaignOrchestrator::class)
                ->draftCampaign($wsId, $params + ['source' => $ctx['source'] ?? 'sarah', 'user_id' => $ctx['user_id'] ?? null]),
            // Existing cap-map sarah actions (assistant_message, agent_message,
            // strategy_meeting) are dispatched via their own controller paths
            // upstream of EngineExecutionService::execute() — they don't need
            // an arm here. Throw on any other unknown sarah action.
            default => throw new \RuntimeException("Unknown sarah action: $action"),
        };
    }

}
