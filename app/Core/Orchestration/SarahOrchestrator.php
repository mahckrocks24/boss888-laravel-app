<?php

namespace App\Core\Orchestration;

use App\Core\EngineKernel\EngineExecutionService;
use App\Core\Intelligence\GlobalKnowledgeService;
use App\Core\Intelligence\AgentExperienceService;
use App\Core\Intelligence\EngineIntelligenceService;
use App\Core\Intelligence\ToolSelectorService;
use App\Core\Intelligence\ToolCostCalculatorService;
use App\Core\LLM\AgentReasoningService;
use App\Core\LLM\PromptTemplates;
use App\Connectors\DeepSeekConnector;
use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SarahOrchestrator — THE Digital Marketing Manager.
 *
 * Sarah is NOT a prompt. She is a SYSTEM CONTROLLER.
 *
 * Responsibilities:
 *   1. RECEIVE user request
 *   2. ANALYZE: what needs to happen, which engines, which agents
 *   3. PLAN: create ExecutionPlan with ordered tasks and dependencies
 *   4. DELEGATE: assign tasks to specific agents based on expertise
 *   5. MONITOR: track execution progress, handle failures
 *   6. EVALUATE: assess results, quality-score each agent's output
 *   7. DECIDE: continue plan, modify, escalate, or complete
 *   8. REPORT: summarize results to user
 *   9. APPROVE: require user approval before external actions (publish, send, launch)
 *
 * The loop:
 *   User → Sarah.receive() → plan() → delegate() → [agents execute] → evaluate() → report()
 *                                                         ↑                    ↓
 *                                                         └── retry/modify ────┘
 */
class SarahOrchestrator
{
    private const SARAH_SLUG = 'sarah';
    private const MAX_RETRIES = 2;

    public function __construct(
        private EngineExecutionService $executor,
        private DeepSeekConnector $llm,
        private GlobalKnowledgeService $globalKnowledge,
        private AgentExperienceService $agentExperience,
        private EngineIntelligenceService $engineIntel,
        private ToolSelectorService $toolSelector,
        private ToolCostCalculatorService $costCalc,
        private AgentReasoningService $reasoning,
        private SarahStrategicLayer $strategy,
        private \App\Connectors\RuntimeClient $runtime,
        private \App\Core\PlanGating\PlanGatingService $planGating,
    ) {}

    // ═══════════════════════════════════════════════════════════
    // 1. RECEIVE — entry point for all user requests through Sarah
    // ═══════════════════════════════════════════════════════════

    public function receive(int $wsId, int $userId, string $goal, array $context = []): array
    {
        // Step 1: Analyze the request
        $analysis = $this->analyze($wsId, $goal, $context);

        // Step 2: STRATEGIC ASSESSMENT — Sarah thinks before acting
        $assessment = $this->strategy->assess($wsId, $goal, $analysis);

        // Step 2b: If recommendation is "reconsider", return with challenges
        if (($assessment['recommendation']['decision'] ?? '') === 'reconsider') {
            return [
                'status' => 'needs_revision',
                'assessment' => $assessment,
                'message' => $assessment['recommendation']['message'] ?? "I have concerns about this approach.",
                'sarah_reasoning' => $assessment['sarah_reasoning'] ?? null,
                'alternatives' => $assessment['alternatives'] ?? [],
            ];
        }

        // Step 3: Create execution plan
        $plan = $this->createPlan($wsId, $userId, $goal, $analysis);

        // Step 4: CHALLENGE the plan — push back if it doesn't make sense
        $challenge = $this->strategy->challengePlan($plan, $wsId);

        // Step 5: PRIORITIZE tasks by ROI
        if (!empty($plan['tasks'])) {
            $plan['tasks'] = $this->strategy->prioritizeTasks($plan['tasks'], $wsId, Workspace::find($wsId)?->industry);
        }

        // Step 6: Determine if approval is needed
        //
        // b17 (2026-07-24) — DETERMINISTIC PUBLISH RAIL.
        // requiresApproval() asks the runtime LLM, which judges the goal text
        // and cannot see which actions were actually selected. TaskService
        // already treats publish as a HARD override on the unified tasks
        // pipeline, but plan_tasks execute through executeNextTasks() →
        // EngineExecutionService, which performs NO approval check at all — so
        // a plan containing publish_article would push content live with no
        // human consent anywhere in the path. Protected actions now force
        // human approval regardless of the LLM's opinion.
        $protectedActions = $this->protectedActionsInPlan((int) $plan['id']);
        $needsApproval = $this->requiresApproval($analysis)
            || !($challenge['approved'] ?? true)
            || !empty($protectedActions);

        if ($needsApproval) {
            DB::table('execution_plans')->where('id', $plan['id'])
                ->update(['requires_approval' => true, 'status' => 'draft']);

            $message = $assessment['recommendation']['message']
                ?? "I've created a plan. Please review and approve.";

            // Say plainly what goes live, and how many — this is the consent
            // the customer is actually giving.
            if (!empty($protectedActions)) {
                $message = $this->describeProtectedWork($protectedActions) . ' ' . $message;
            }

            return [
                'plan_id' => $plan['id'],
                'status' => 'awaiting_approval',
                'plan' => $plan,
                'assessment' => $assessment,
                'challenges' => $challenge['challenges'] ?? [],
                'suggestions' => $challenge['suggestions'] ?? [],
                'sarah_reasoning' => $assessment['sarah_reasoning'] ?? null,
                'requires_confirmation' => !empty($protectedActions),
                'protected_actions' => $protectedActions,
                'message' => $message,
            ];
        }

        // Step 7: Auto-approve and start execution
        return array_merge(
            $this->approvePlan($wsId, $plan['id'], $userId),
            ['assessment' => $assessment, 'sarah_reasoning' => $assessment['sarah_reasoning'] ?? null]
        );
    }

    // ═══════════════════════════════════════════════════════════
    // 2. ANALYZE — understand what needs to happen
    // ═══════════════════════════════════════════════════════════

    public function analyze(int $wsId, string $goal, array $context = []): array
    {
        $workspace = Workspace::find($wsId);
        $wsContext = $workspace ? PromptTemplates::workspaceContext($workspace->toArray()) : '';
        // 2026-05-27 Phase 4 — append category mix so Sarah sees the
        // workload balance when reasoning about delegation/strategy.
        if ($workspace) {
            $catLine = \App\Core\Orchestration\AgentMeetingEngine::categoryMixLine($workspace->id);
            if ($catLine !== '') $wsContext .= "\n" . $catLine;
        }
        $industry = $workspace?->industry;
        $region = $workspace?->location;

        // Get relevant global knowledge
        $knowledge = $this->globalKnowledge->query([
            'industry' => $industry,
            'region' => $region,
        ], 5);

        // Get past strategy effectiveness
        $pastStrategies = $this->globalKnowledge->getTopStrategies('marketing', $industry, 3);

        // Determine required engines
        $engines = $this->identifyEngines($goal);

        // Determine required agents
        $agents = $this->selectAgents($wsId, $engines, $industry);

        // Estimate credits
        $creditEstimate = $this->estimateCredits($engines);

        return [
            'workspace_id' => $wsId,
            'goal' => $goal,
            'engines_required' => $engines,
            'agents_required' => $agents,
            'credit_estimate' => $creditEstimate,
            'budget_credits' => $context['budget_credits'] ?? PHP_INT_MAX,
            'industry_context' => $industry,
            'region_context' => $region,
            'relevant_knowledge' => array_map(fn($k) => $k->insight ?? '', $knowledge),
            'past_strategies' => $pastStrategies,
            'complexity' => count($engines) > 3 ? 'high' : (count($engines) > 1 ? 'medium' : 'low'),
            'estimated_tasks' => $this->estimateTaskCount($engines, $goal),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // 3. PLAN — create structured execution plan
    // ═══════════════════════════════════════════════════════════

    public function createPlan(int $wsId, int $userId, string $goal, array $analysis): array
    {
        // Step A: Run tool selection BEFORE strategy generation so the LLM
        // can see and justify the picks
        $selectionTrace = $this->getSelectionTrace($wsId, $goal, $analysis);
        $analysis['_selection_trace'] = $selectionTrace;

        // Step B: Re-estimate credits from the actual selected sequence
        // (replaces the rough per-engine estimate computed in analyze())
        $costBreakdown = $this->estimateCreditsFromSequence($selectionTrace['sequence'] ?? []);
        $analysis['credit_estimate'] = $costBreakdown['total'];

        // Step C: Use runtime LLM to generate strategic narrative (sees full engine intel + trace)
        // 2026-04-12 (Phase 1.0.2 / doc 14): now routes through RuntimeClient instead
        // of direct DeepSeekConnector. Hands vs brain pattern enforced.
        $strategy = null;
        if ($this->runtime->isConfigured()) {
            $strategy = $this->generateStrategy($wsId, $goal, $analysis);
        }

        // Attach decision metadata to the strategy record for transparency
        $strategyPayload = array_merge(
            $strategy ?? ['approach' => 'standard'],
            [
                'tool_selection_trace' => $selectionTrace,
                'cost_breakdown' => $costBreakdown,
                'confidence' => $selectionTrace['confidence'] ?? null,
            ]
        );

        // Create plan record
        $planId = DB::table('execution_plans')->insertGetId([
            'workspace_id' => $wsId,
            'created_by' => $userId,
            'title' => $this->generatePlanTitle($goal),
            'goal' => $goal,
            'status' => 'draft',
            'strategy_json' => json_encode($strategyPayload),
            'agents_required_json' => json_encode($analysis['agents_required']),
            'total_tasks' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Use the already-computed selection sequence as the task list.
        // Phase 1.5b: ToolSelectorService::inferDependencies() returns
        // POSITION-based dependencies (array indices in $existingSequence,
        // not real plan_tasks IDs — at selection time the tasks haven't
        // been inserted yet). We do a two-pass insert here:
        //   1. Insert each task with empty depends_on_json, capture the
        //      generated id into $positionToId.
        //   2. Update each row with the actual depends_on_json built from
        //      the position→id map.
        // Without this translation, executeNextTasks's dependency check
        // (`array_diff($deps, $completedIds)`) would never match because
        // it'd be comparing array indices [0,1,2] against real IDs.
        $tasks = $selectionTrace['sequence'] ?? [];
        $taskCount = 0;
        $positionToId = [];

        // Pass 1: insert plan_tasks + corresponding tasks rows, capture IDs.
        foreach ($tasks as $i => $task) {
            $insertedId = DB::table('plan_tasks')->insertGetId([
                'plan_id' => $planId,
                'step_order' => $i + 1,
                'engine' => $task['engine'],
                'action' => $task['action'],
                'params_json' => json_encode($task['params'] ?? []),
                'assigned_agent' => $task['agent'],
                'status' => 'pending',
                'depends_on_json' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $positionToId[$i] = $insertedId;
            $taskCount++;

            // PATCH (Intel Fix 2c) — UNIFICATION via TaskService instead of raw insert.
            // Was DB::table('tasks')->insertGetId([... 'status'=>'pending' ...])
            // which left tasks orphaned (no dispatcher call). TaskService routes
            // through the canonical pipeline.
            try {
                $newTask = app(\App\Core\TaskSystem\TaskService::class)->create($wsId, [
                    'engine'          => $task['engine'],
                    'action'          => $task['action'],
                    'source'          => 'agent',
                    'priority'        => 'normal',
                    'assigned_agents' => [$task['agent']],
                    'payload'         => $task['params'] ?? [],
                ]);
                $taskRowId = $newTask->id;
                $newTask->update([
                    'plan_task_id'     => $insertedId,
                    'progress_message' => ucfirst(str_replace('_', ' ', $task['action'])) . ' (Plan #' . $planId . ')',
                ]);
            } catch (\Throwable $e) {
                Log::warning("[SarahOrchestrator] TaskService::create failed for plan task", [
                    'plan_id'   => $planId,
                    'task'      => $task,
                    'error'     => $e->getMessage(),
                ]);
                continue;
            }

            // Link plan_task back to tasks row
            DB::table('plan_tasks')->where('id', $insertedId)->update(['task_id' => $taskRowId]);
        }

        // Pass 2: translate position-based deps → real IDs.
        foreach ($tasks as $i => $task) {
            $rawDeps = $task['depends_on'] ?? [];
            if (empty($rawDeps)) continue;
            $realDeps = [];
            foreach ($rawDeps as $position) {
                if (isset($positionToId[$position])) {
                    $realDeps[] = $positionToId[$position];
                }
            }
            if (!empty($realDeps)) {
                DB::table('plan_tasks')->where('id', $positionToId[$i])->update([
                    'depends_on_json' => json_encode($realDeps),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('execution_plans')->where('id', $planId)
            ->update(['total_tasks' => $taskCount]);

        /* b12-scheduler-hook */
        // If the caller supplied a schedule spec, plot the calendar BEFORE
        // the approval click so the user sees the timeline concretely.
        // (Sarah's mental model: "create the calendar items 1st, then the
        // task group, single approval at top.")
        $schedulePreview = null;
        $scheduleError   = null;
        $scheduleSpec    = $analysis['schedule'] ?? null;

        /* b14-parser-hook */
        // If no explicit schedule supplied, try to extract one from the goal
        // text. "3 articles per day for 2 weeks" → {items_per_day:3, days:14}.
        // Returns null when no schedule pattern is detected; in that case we
        // fall through to non-scheduled plan creation as before.
        if ($scheduleSpec === null) {
            try {
                $autoSpec = app(\App\Core\Strategy\ScheduleSpecParser::class)->parse($goal);
                if (is_array($autoSpec)) {
                    $scheduleSpec = $autoSpec;
                    Log::info('[SarahOrchestrator] schedule auto-extracted from goal text', [
                        'plan_id' => $planId, 'spec' => $autoSpec,
                    ]);
                }
            } catch (\Throwable $pErr) {
                Log::warning('[SarahOrchestrator] ScheduleSpecParser threw (non-fatal)', [
                    'plan_id' => $planId, 'error' => $pErr->getMessage(),
                ]);
            }
        }
        if (is_array($scheduleSpec) && !empty($scheduleSpec)) {
            try {
                $plotRes = app(\App\Core\Strategy\PlanSchedulerService::class)
                    ->plotSchedule($wsId, $planId, $scheduleSpec);
                if (!empty($plotRes['success'])) {
                    $schedulePreview = [
                        'window'      => $plotRes['window'] ?? null,
                        'scheduled'   => $plotRes['scheduled'] ?? 0,
                        'unscheduled' => $plotRes['unscheduled'] ?? 0,
                        'events'      => $plotRes['automation_events_created'] ?? 0,
                    ];
                } else {
                    $scheduleError = $plotRes['error'] ?? 'unknown plot error';
                    Log::warning('[SarahOrchestrator] plotSchedule failed (non-fatal)', [
                        'plan_id' => $planId, 'error' => $scheduleError,
                    ]);
                }
            } catch (\Throwable $schedErr) {
                $scheduleError = $schedErr->getMessage();
                Log::warning('[SarahOrchestrator] plotSchedule threw (non-fatal)', [
                    'plan_id' => $planId, 'error' => $scheduleError,
                ]);
            }
        }

        return [
            'id' => $planId,
            'title' => $this->generatePlanTitle($goal),
            'tasks' => $tasks,
            'task_count' => $taskCount,
            'agents' => $analysis['agents_required'],
            'credit_estimate' => $costBreakdown['total'],
            'cost_breakdown' => $costBreakdown,
            'selection_trace' => $selectionTrace,
            'confidence' => $selectionTrace['confidence'] ?? 0,
            'schedule' => $schedulePreview,        /* b12 */
            'schedule_error' => $scheduleError,    /* b12 */
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // 4. APPROVE & EXECUTE — start the plan
    // ═══════════════════════════════════════════════════════════

    public function approvePlan(int $wsId, int $planId, int $userId): array
    {
        // b18 (2026-07-24) — CAPTURED PUBLISH CONSENT.
        //
        // A plan containing protected actions can only reach this method through
        // the human gate: receive() forces requires_approval when the sequence
        // contains any, and shows exactly what goes live ("This will publish 42
        // articles — that goes live publicly and cannot be undone"). Approving
        // here IS that consent, given by a named user at a known time.
        //
        // Without recording it, every scheduled publish stopped for a SECOND
        // per-article click, so "publish 2 every 5 minutes" queued review items
        // on a cadence instead of publishing — Sarah not carrying out the
        // instruction she was given. This is the same double-consent the
        // 2026-07-23 decision removed from the chat path.
        //
        // Scope is deliberately narrow: consent covers ONLY the action names
        // disclosed on THIS plan, and only when the plan actually went through
        // the approval gate. It is not a blanket protected-action bypass.
        // b21 (2026-07-24) — TENANCY. $wsId was accepted and then never used in
        // a single query, so any authenticated caller could approve another
        // workspace's plan by id. Post-b18 that is worse than a data leak: an
        // approval captures publish consent, so a cross-tenant approve could
        // push a different customer's articles live. Fail closed.
        $plan = DB::table('execution_plans')
            ->where('id', $planId)
            ->where('workspace_id', $wsId)
            ->first(['requires_approval', 'strategy_json']);

        if (!$plan) {
            return [
                'plan_id' => $planId,
                'status'  => 'not_found',
                'message' => 'Plan not found.',
            ];
        }

        $consentActions = [];
        if ($plan && (int) $plan->requires_approval === 1) {
            $consentActions = array_keys($this->protectedActionsInPlan($planId));
        }

        $update = [
            'status' => 'executing',
            'approved_at' => now(),
            'started_at' => now(),
            'updated_at' => now(),
        ];

        if (!empty($consentActions)) {
            $strategy = json_decode((string) ($plan->strategy_json ?? '{}'), true) ?: [];
            $strategy['publish_consent'] = [
                'actions'     => array_values($consentActions),
                'approved_by' => $userId,
                'approved_at' => now()->toDateTimeString(),
                'scope'       => 'plan',
            ];
            $update['strategy_json'] = json_encode($strategy);

            try {
                app(\App\Core\Audit\AuditLogService::class)->log(
                    $wsId, $userId, 'plan.publish_consent_captured',
                    'execution_plan', $planId,
                    ['actions' => array_values($consentActions)]
                );
            } catch (\Throwable $e) {
                Log::warning('[Sarah] publish-consent audit log failed: ' . $e->getMessage());
            }
        }

        DB::table('execution_plans')->where('id', $planId)->update($update);

        // Execute first batch of tasks (no dependencies). Future-scheduled
        // tasks are skipped here and fired later by `sarah:run-due-tasks`.
        $results = $this->executeNextTasks($wsId, $planId);

        // b15 — report the plotted cadence so the user knows what was booked
        // rather than being told everything "is starting now".
        $scheduledCount = DB::table('plan_tasks')
            ->where('plan_id', $planId)->whereNotNull('scheduled_for')->count();
        $nextAt = DB::table('plan_tasks')
            ->where('plan_id', $planId)->where('status', 'pending')
            ->whereNotNull('scheduled_for')->min('scheduled_for');

        $message = "Plan approved. I'm starting execution now. I'll report back as tasks complete.";
        if ($scheduledCount > 0) {
            $message = $nextAt
                ? "Plan approved and added to your calendar — {$scheduledCount} scheduled task(s). Next one runs at {$nextAt}."
                : "Plan approved — {$scheduledCount} task(s) are on your calendar.";
        }

        // b17/b18 — be exact about what happens next. Anything still parked for
        // review is named as such; anything covered by the consent just captured
        // will publish on its own schedule, and the customer is told so plainly
        // rather than discovering content went live unannounced.
        $awaitingApproval = DB::table('plan_tasks')
            ->where('plan_id', $planId)->where('status', 'awaiting_approval')->count();
        if ($awaitingApproval > 0) {
            $message .= " {$awaitingApproval} item(s) are waiting in your review queue"
                . " — publishing needs your OK before anything goes live.";
        } elseif (!empty($consentActions) && $scheduledCount > 0) {
            $message .= " These will go live automatically on that schedule"
                . " — you approved the publish, so I won't ask again for each one.";
        }

        return [
            'plan_id' => $planId,
            'status' => 'executing',
            'tasks_started' => count($results),
            'scheduled_tasks' => $scheduledCount,
            'next_run_at' => $nextAt,
            'awaiting_approval' => $awaitingApproval,
            'message' => $message,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // 5. EXECUTE TASKS — delegate to agents, monitor progress
    // ═══════════════════════════════════════════════════════════

    public function executeNextTasks(int $wsId, int $planId): array
    {
        // PHASE 1.5b — convert recursive call chain
        //   executeNextTasks() → checkPlanCompletion() → executeNextTasks()
        // into a bounded iterative loop. The old version blew the call stack
        // (and process memory) on plans with retried tasks because each
        // recursive frame held the parent's pendingTasks collection + task
        // result payloads (DALL-E images at ~3MB each piled up across the
        // 3-task × 3-attempt × 6-task plan exec). The iterative loop reuses
        // a single frame and unsets large temporaries between iterations.
        $allResults = [];
        $iteration  = 0;
        $maxIterations = (self::MAX_RETRIES + 2) * 10;  // safety cap

        while ($iteration++ < $maxIterations) {
            // Re-fetch pending tasks each iteration (statuses change inside the loop)
            //
            // b15 (2026-07-24) — RESPECT THE SCHEDULE. A plan that carries a
            // plotted schedule (plan_tasks.scheduled_for, e.g. "publish 2
            // articles every 5 minutes") must NOT have its whole task list
            // executed the instant it is approved — that defeated the
            // calendar entirely. Tasks with a FUTURE scheduled_for are left
            // pending and fired by `sarah:run-due-tasks` when they come due.
            // Unscheduled tasks (scheduled_for IS NULL) behave exactly as
            // before, so non-scheduled plans are unaffected.
            $pendingTasks = DB::table('plan_tasks')
                ->where('plan_id', $planId)
                ->where('status', 'pending')
                ->where(function ($q) {
                    $q->whereNull('scheduled_for')
                      ->orWhere('scheduled_for', '<=', now());
                })
                ->orderBy('step_order')
                ->get();

            if ($pendingTasks->isEmpty()) break;

            $completedIds = DB::table('plan_tasks')
                ->where('plan_id', $planId)
                ->where('status', 'completed')
                ->pluck('id')
                ->toArray();

            $progress = false;  // did this iteration actually advance any task?

            foreach ($pendingTasks as $task) {
                $deps = json_decode($task->depends_on_json ?? '[]', true);

                // Check if all dependencies are met
                if (!empty($deps) && array_diff($deps, $completedIds)) {
                    continue; // Dependencies not met yet
                }

                // Delegate to agent
                $delegationId = $this->delegateTask($wsId, $planId, $task);

                // Execute through the execution engine
                try {
                    DB::table('plan_tasks')->where('id', $task->id)
                        ->update(['status' => 'executing', 'started_at' => now()]);

                    $params = json_decode($task->params_json ?? '{}', true);
                    /* b8-plan */ // pass plan_id so EES can recognize this is autopilot inside an approved Task Group
                    $result = $this->executor->execute($wsId, $task->engine, $task->action, $params, [
                        'user_id'  => null,
                        'agent_id' => $task->assigned_agent,
                        'source'   => 'orchestrator',
                        'plan_id'  => $planId,
                    ]);

                    // b17 (2026-07-24) — AWAITING APPROVAL IS NOT DONE.
                    //
                    // EngineExecutionService returns success=true TOGETHER with
                    // pending_approval=true when a 'protected' action (publish_*,
                    // send_*, delete_*) is parked in the review queue — that is the
                    // deliberate "pause on publishing for preview review" gate, and
                    // a plan's single approval never bypasses it (see the b8-plan
                    // block in EngineExecutionService).
                    //
                    // The old `if ($result['success'])` treated that as COMPLETED.
                    // So 4 articles queued for review were reported to the customer
                    // as published, completed_at was stamped, and the plan closed
                    // clean while nothing had actually gone live. Record the real
                    // state instead and keep the approval id for the review queue.
                    if (!empty($result['pending_approval'])) {
                        DB::table('plan_tasks')->where('id', $task->id)->update([
                            'status'      => 'awaiting_approval',
                            'result_json' => json_encode([
                                'pending_approval' => true,
                                'approval_id'      => $result['approval_id'] ?? null,
                                'message'          => $result['message'] ?? 'Waiting for your approval',
                            ]),
                            'updated_at'  => now(),
                        ]);

                        Log::info('[Sarah] task parked for approval', [
                            'plan_id' => $planId, 'task_id' => $task->id,
                            'action'  => $task->action,
                            'approval_id' => $result['approval_id'] ?? null,
                        ]);

                        $allResults[] = [
                            'task_id'     => $task->id,
                            'status'      => 'awaiting_approval',
                            'approval_id' => $result['approval_id'] ?? null,
                        ];
                        $progress = true;   // state changed — don't spin
                        unset($result, $params);
                        continue;
                    }

                    if ($result['success']) {
                        DB::table('plan_tasks')->where('id', $task->id)->update([
                            'status' => 'completed',
                            'result_json' => json_encode($result['data'] ?? []),
                            'credits_used' => $result['credits_used'] ?? 0,
                            'completed_at' => now(),
                            'updated_at' => now(),
                        ]);
                        // Sync status to unified tasks row
                        if ($task->task_id) {
                            DB::table('tasks')->where('id', $task->task_id)->update([
                                'status' => 'completed',
                                'result_json' => json_encode($result['data'] ?? []),
                                'completed_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        // Evaluate agent's work
                        $this->evaluateDelegation($delegationId, $result);
                        $progress = true;
                    } else {
                        $this->handleTaskFailure($task, $result, $planId);
                        // handleTaskFailure flips status to either 'pending' (retry) or 'failed' (terminal).
                        // Either way the next iteration will see the new state and decide.
                        $progress = true;  // status changed = progress made
                    }

                    $allResults[] = ['task_id' => $task->id, 'status' => $result['success'] ? 'completed' : 'failed'];

                    // Free large response payloads before next task
                    unset($result, $params);

                } catch (\Throwable $e) {
                    $this->handleTaskFailure($task, ['error' => $e->getMessage()], $planId);
                    $allResults[] = ['task_id' => $task->id, 'status' => 'failed', 'error' => $e->getMessage()];
                    $progress = true;
                }
            }

            // Free per-iteration collections
            unset($pendingTasks, $completedIds);

            // Defensive: if a full iteration made zero progress (stuck on
            // unmet dependencies AND no failures resolved), bail out instead
            // of looping forever. This catches circular dependency bugs.
            if (! $progress) break;
        }

        // Plan completion + final evaluation runs ONCE, at the end of the
        // iterative loop, NOT recursively from inside checkPlanCompletion().
        $this->finalizePlan($wsId, $planId);

        return $allResults;
    }

    // ═══════════════════════════════════════════════════════════
    // 6. DELEGATE — assign task to specific agent with tracking
    // ═══════════════════════════════════════════════════════════

    private function delegateTask(int $wsId, int $planId, object $task): int
    {
        // Plan-based agent gating: verify the assigned agent is on the workspace's team
        $agentSlug = $task->assigned_agent;
        $check = $this->planGating->canUseAgent($wsId, $agentSlug);
        if (!$check['allowed']) {
            // Fall back to Sarah — she's always available on AI plans
            \Illuminate\Support\Facades\Log::info("[Sarah] Agent '{$agentSlug}' not on workspace team — falling back to Sarah for {$task->engine}/{$task->action}");
            $agentSlug = self::SARAH_SLUG;
        }

        return DB::table('agent_delegations')->insertGetId([
            'workspace_id' => $wsId,
            'plan_id' => $planId,
            'from_agent' => self::SARAH_SLUG,
            'to_agent' => $agentSlug,
            'instruction' => "Execute {$task->engine}/{$task->action} as step {$task->step_order} of plan #{$planId}",
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 7. EVALUATE — assess agent work quality
    // ═══════════════════════════════════════════════════════════

    private function evaluateDelegation(int $delegationId, array $result): void
    {
        $qualityScore = $this->assessQuality($result);

        DB::table('agent_delegations')->where('id', $delegationId)->update([
            'status' => 'completed',
            'result_json' => json_encode($result['data'] ?? []),
            'evaluation_json' => json_encode([
                'quality_score' => $qualityScore,
                'credits_used' => $result['credits_used'] ?? 0,
                'triggers_fired' => $result['triggers_fired'] ?? [],
            ]),
            'quality_score' => $qualityScore,
            'updated_at' => now(),
        ]);
    }

    /**
     * Wave 90 — Result quality scoring via runtime (canonical).
     * On unreachable runtime, returns neutral 0.5.
     */
    private function assessQuality(array $result): float
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $score = $rt->orchestratorAssessQuality($result);
            if ($score !== null) return $score;
        }
        // Wave 90 Phase D — runtime canonical. Neutral score on failure
        // (neither passes nor fails downstream quality gates).
        return 0.5;
    }


    // ═══════════════════════════════════════════════════════════
    // 8. HANDLE FAILURES — retry, reassign, escalate
    // ═══════════════════════════════════════════════════════════

    /**
     * Error codes that are deterministic — retrying will never make them succeed.
     * Skip retries for these and mark the task failed immediately so the plan
     * can move on instead of burning the retry budget on an impossible error.
     *
     * PHASE 1.5b: AGENT_NOT_AUTHORIZED, INVALID_ACTION, PLAN_GATED are config
     * issues, not transient failures. Retrying them was eating ~3MB of memory
     * per attempt (DALL-E payloads) until OOM kicked in.
     */
    private const TERMINAL_ERROR_CODES = [
        'AGENT_NOT_AUTHORIZED',
        'INVALID_ACTION',
        'PLAN_GATED',
        'CAPABILITY_DENIED',
    ];

    private function handleTaskFailure(object $task, array $result, int $planId): void
    {
        $retryCount = $task->retry_count + 1;
        $errorCode  = $result['code'] ?? null;
        $isTerminal = $errorCode && in_array($errorCode, self::TERMINAL_ERROR_CODES, true);

        if (!$isTerminal && $retryCount <= self::MAX_RETRIES) {
            // Retry the task
            DB::table('plan_tasks')->where('id', $task->id)->update([
                'status' => 'pending',
                'retry_count' => $retryCount,
                'agent_notes_json' => json_encode(['last_error' => $result['error'] ?? 'Unknown error', 'retry' => $retryCount]),
                'updated_at' => now(),
            ]);
        } else {
            // Mark as failed, skip to next
            DB::table('plan_tasks')->where('id', $task->id)->update([
                'status' => 'failed',
                'result_json' => json_encode(['error' => $result['error'] ?? 'Max retries exceeded']),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            // Update plan failed count
            DB::table('execution_plans')->where('id', $planId)
                ->increment('failed_tasks');

            // Skip dependent tasks
            DB::table('plan_tasks')->where('plan_id', $planId)
                ->whereRaw("JSON_CONTAINS(depends_on_json, ?)", [(string) $task->id])
                ->update(['status' => 'skipped', 'updated_at' => now()]);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 9. PLAN COMPLETION — evaluate and report
    // ═══════════════════════════════════════════════════════════

    /**
     * PHASE 1.5b — replaces the recursive checkPlanCompletion(). Called once
     * at the end of executeNextTasks()'s iterative loop. Marks the plan
     * status, records final counts, generates the evaluation, and writes
     * learnings to global knowledge.
     *
     * The OLD checkPlanCompletion() called executeNextTasks() back at line
     * 474 when remaining > 0, creating the recursive loop that OOMed for
     * plans with retried tasks. Don't reintroduce that pattern.
     */
    private function finalizePlan(int $wsId, int $planId): void
    {
        $plan = DB::table('execution_plans')->where('id', $planId)->first();
        if (!$plan) return;

        $completed = DB::table('plan_tasks')->where('plan_id', $planId)->where('status', 'completed')->count();
        $remainingRows = DB::table('plan_tasks')->where('plan_id', $planId)
            // b17 — 'awaiting_approval' counts as OUTSTANDING work. Omitting it
            // let a plan whose publishes were parked in the review queue close as
            // 'completed', which is how 4 unpublished articles were reported done.
            ->whereIn('status', ['pending', 'executing', 'blocked', 'awaiting_approval'])
            ->get(['id', 'status', 'scheduled_for']);
        $remaining = $remainingRows->count();

        // Everything left is sitting in the review queue: the plan is not
        // partial or failed, it is waiting on the customer. Don't stamp
        // completed_at and don't fire the evaluation/learnings pass yet.
        $awaitingApproval = $remaining > 0
            && $remainingRows->every(fn ($t) => $t->status === 'awaiting_approval');

        if ($awaitingApproval) {
            DB::table('execution_plans')->where('id', $planId)->update([
                'status'          => 'awaiting_approval',
                'completed_tasks' => $completed,
                'updated_at'      => now(),
            ]);
            Log::info('[Sarah] plan waiting on review queue', [
                'plan_id' => $planId, 'ws_id' => $wsId,
                'completed' => $completed, 'awaiting' => $remaining,
            ]);
            return;
        }

        // b15 (2026-07-24) — DO NOT finalize a plan that is merely WAITING on
        // its calendar slots. When every remaining task is pending with a
        // future scheduled_for, the plan isn't partially done, it's running.
        // Finalizing here stamped completed_at, fired the evaluation +
        // learnings against a plan that had done nothing, and — because it
        // left status='partial' — dropped the plan out of the due-task
        // runner's filter, so the remaining slots never fired at all.
        $awaitingSchedule = $remaining > 0 && $remainingRows->every(
            fn ($t) => $t->status === 'pending'
                && $t->scheduled_for !== null
                && strtotime((string) $t->scheduled_for) > time()
        );

        if ($awaitingSchedule) {
            DB::table('execution_plans')->where('id', $planId)->update([
                'status'          => 'executing',
                'completed_tasks' => $completed,
                'updated_at'      => now(),
            ]);
            Log::info('[Sarah] plan awaiting scheduled slots — not finalized', [
                'plan_id'   => $planId,
                'ws_id'     => $wsId,
                'completed' => $completed,
                'waiting'   => $remaining,
                'next_slot' => $remainingRows->min('scheduled_for'),
            ]);
            return;
        }

        // If anything is still pending after the iterative loop bailed, mark the
        // plan partial — this can happen when the safety cap is hit OR when no
        // task makes progress (e.g. circular dependencies).
        $finalStatus = match (true) {
            $remaining > 0           => 'partial',
            $plan->failed_tasks > 0  => 'completed_with_errors',
            default                  => 'completed',
        };

        $evaluation = $this->generateEvaluation($planId);

        DB::table('execution_plans')->where('id', $planId)->update([
            'status'                => $finalStatus,
            'completed_tasks'       => $completed,
            'results_summary_json'  => json_encode($this->summarizeResults($planId)),
            'sarah_evaluation_json' => json_encode($evaluation),
            'completed_at'          => now(),
            'updated_at'            => now(),
        ]);

        // Record learnings to global knowledge (best-effort, never throw)
        try {
            $this->recordPlanLearnings($wsId, $planId, $evaluation);
        } catch (\Throwable $e) {
            Log::warning('SarahOrchestrator::finalizePlan recordPlanLearnings failed', [
                'plan_id' => $planId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Legacy entry point — kept as a thin shim that delegates to finalizePlan().
     * Some other code may still call checkPlanCompletion() directly; preserve
     * the public-ish behavior without the recursive trap.
     */
    private function checkPlanCompletion(int $wsId, int $planId): void
    {
        $this->finalizePlan($wsId, $planId);
    }

    private function generateEvaluation(int $planId): array
    {
        $tasks = DB::table('plan_tasks')->where('plan_id', $planId)->get();
        $delegations = DB::table('agent_delegations')->where('plan_id', $planId)->get();

        $totalQuality = $delegations->whereNotNull('quality_score')->avg('quality_score') ?? 0;
        $totalCredits = $tasks->sum('credits_used');

        $agentPerformance = [];
        foreach ($delegations->groupBy('to_agent') as $agent => $dels) {
            $agentPerformance[$agent] = [
                'tasks' => $dels->count(),
                'avg_quality' => round($dels->whereNotNull('quality_score')->avg('quality_score') ?? 0, 2),
                'completed' => $dels->where('status', 'completed')->count(),
                'failed' => $dels->where('status', 'failed')->count(),
            ];
        }

        return [
            'overall_quality' => round($totalQuality, 2),
            'total_credits_used' => $totalCredits,
            'agent_performance' => $agentPerformance,
            'tasks_completed' => $tasks->where('status', 'completed')->count(),
            'tasks_failed' => $tasks->where('status', 'failed')->count(),
            'tasks_skipped' => $tasks->where('status', 'skipped')->count(),
        ];
    }

    private function summarizeResults(int $planId): array
    {
        return DB::table('plan_tasks')
            ->where('plan_id', $planId)
            ->where('status', 'completed')
            ->get()
            ->map(fn($t) => [
                'engine' => $t->engine,
                'action' => $t->action,
                'agent' => $t->assigned_agent,
                'result' => json_decode($t->result_json ?? '{}', true),
            ])->toArray();
    }

    private function recordPlanLearnings(int $wsId, int $planId, array $evaluation): void
    {
        $plan = DB::table('execution_plans')->where('id', $planId)->first();
        if (!$plan || $evaluation['overall_quality'] < 0.3) return;

        $workspace = Workspace::find($wsId);

        $this->globalKnowledge->learnFromOutcome(
            $wsId,
            'orchestration',
            'execution_plan',
            ['goal' => $plan->goal, 'task_count' => $plan->total_tasks],
            ['quality' => $evaluation['overall_quality'], 'credits' => $evaluation['total_credits_used']],
            $workspace?->industry,
            $workspace?->location
        );
    }

    // ═══════════════════════════════════════════════════════════
    // 10. GET STATUS — for frontend polling
    // ═══════════════════════════════════════════════════════════
    // PROACTIVE — receives signals from ProactiveStrategyEngine
    // ═══════════════════════════════════════════════════════════

    /**
     * Handle a proactive signal from ProactiveStrategyEngine or cron.
     * Sarah evaluates the signal and decides whether to create a proposal,
     * dispatch a task, or send a template notification.
     *
     * Signal types:
     *   daily_check    — routine daily health check
     *   weekly_review  — weekly performance review
     *   monthly_plan   — monthly strategy proposal
     *   opportunity    — specific opportunity identified (content gap, audit, etc.)
     *
     * HARD RULE: No credits are spent here. This method only creates proposals
     * and template notifications. Credits are only reserved/committed after
     * explicit user approval via approveProposal().
     */
    public function handleProactiveSignal(int $wsId, string $signalType, array $context = []): array
    {
        try {
            $proactive = app(\App\Core\Orchestration\ProactiveStrategyEngine::class);

            $result = match ($signalType) {
                'daily_check'   => $proactive->dailyCheck($wsId),
                'weekly_review' => $proactive->weeklyReview($wsId),
                'monthly_plan'  => $proactive->monthlyStrategy($wsId, $context['user_id'] ?? 1),
                'onboarding'    => $proactive->onOnboardingComplete($wsId, $context['user_id'] ?? 1),
                default         => ['skipped' => true, 'reason' => "Unknown signal type: {$signalType}"],
            };

            Log::info("Sarah proactive signal handled", [
                'workspace_id' => $wsId,
                'signal_type'  => $signalType,
                'result'       => $result,
            ]);

            return ['success' => true, 'signal_type' => $signalType, 'result' => $result];

        } catch (\Throwable $e) {
            Log::error("Sarah proactive signal failed", [
                'workspace_id' => $wsId,
                'signal_type'  => $signalType,
                'error'        => $e->getMessage(),
            ]);
            return ['success' => false, 'signal_type' => $signalType, 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════

    /**
     * b21 (2026-07-24) — TENANCY. $wsId was absent entirely, so this returned
     * ANY plan by id to ANY authenticated caller: goal text, every task, params
     * and results belonging to another customer. Confirmed against live data —
     * a workspace-2 token read workspace 990003's plan in full.
     *
     * Nullable so internal/system callers (which legitimately have no workspace
     * context) keep working; every HTTP route now passes it and is scoped.
     */
    public function getPlanStatus(int $planId, ?int $wsId = null): array
    {
        $plan = DB::table('execution_plans')
            ->where('id', $planId)
            ->when($wsId !== null, fn ($q) => $q->where('workspace_id', $wsId))
            ->first();
        // Same message whether it is missing or someone else's — never confirm
        // that a plan id exists in another workspace.
        if (!$plan) return ['error' => 'Plan not found'];

        $tasks = DB::table('plan_tasks')->where('plan_id', $planId)->orderBy('step_order')->get();
        $delegations = DB::table('agent_delegations')->where('plan_id', $planId)->get();

        return [
            'plan' => $plan,
            'tasks' => $tasks->toArray(),
            'delegations' => $delegations->toArray(),
            'progress' => [
                'total' => $plan->total_tasks,
                'completed' => $plan->completed_tasks,
                'failed' => $plan->failed_tasks,
                'percentage' => $plan->total_tasks > 0 ? round(($plan->completed_tasks / $plan->total_tasks) * 100) : 0,
            ],
        ];
    }

    public function listPlans(int $wsId, array $filters = []): array
    {
        $q = DB::table('execution_plans')->where('workspace_id', $wsId);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        return $q->orderByDesc('created_at')->limit($filters['limit'] ?? 20)->get()->toArray();
    }

    /**
     * b21 (2026-07-24) — TENANCY + a loose end from the b17 status addition.
     *
     * 1. $wsId was absent, so any authenticated caller could cancel any
     *    workspace's plan by guessing an id.
     * 2. The task sweep only covered pending/blocked. Tasks parked as
     *    'awaiting_approval' survived cancellation and stayed in the review
     *    queue — approving one later would publish content from a plan the
     *    customer had already cancelled. Their approval rows are withdrawn too,
     *    so nothing is left clickable.
     */
    public function cancelPlan(int $planId, ?int $wsId = null): bool
    {
        $affected = DB::table('execution_plans')
            ->where('id', $planId)
            ->when($wsId !== null, fn ($q) => $q->where('workspace_id', $wsId))
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        if ($affected === 0) {
            return false;   // missing, or not this caller's plan
        }

        // Withdraw any review-queue entries this plan created, so a cancelled
        // plan can never be resurrected by an approval click.
        $taskIds = DB::table('plan_tasks')
            ->where('plan_id', $planId)
            ->where('status', 'awaiting_approval')
            ->whereNotNull('task_id')
            ->pluck('task_id')
            ->all();

        if ($taskIds) {
            DB::table('approvals')
                ->whereIn('task_id', $taskIds)
                ->where('status', 'pending')
                ->update([
                    'status'        => 'expired',
                    'decision_note' => 'Plan cancelled before approval.',
                    'decided_at'    => now(),
                    'updated_at'    => now(),
                ]);
        }

        DB::table('plan_tasks')
            ->where('plan_id', $planId)
            ->whereIn('status', ['pending', 'blocked', 'awaiting_approval'])
            ->update(['status' => 'skipped', 'updated_at' => now()]);

        return true;
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE — decision engine
    // ═══════════════════════════════════════════════════════════

    /**
     * Wave 90 — Engine identification via runtime (canonical).
     * On unreachable runtime, returns ['seo', 'write'] (mirrors
     * runtime's own empty-goal fallback).
     */
    private function identifyEngines(string $goal): array
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $result = $rt->orchestratorIdentifyEngines($goal);
            if ($result !== null) return $result;
        }
        // Wave 90 Phase D — runtime canonical. Safe default mirrors
        // the runtime's own empty-goal fallback.
        return ['seo', 'write'];
    }


    private function selectAgents(int $wsId, array $engines, ?string $industry): array
    {
        // LAUNCH SCOPE 2026-07-20 — 'social'=>marcus and 'marketing'=>elena removed:
        // social automation + email marketing are out of the launch product and marcus
        // is a removed agent. crm stays with elena (retained). Any residual social/
        // marketing engine falls to Sarah as coordinator only (execution is denied at
        // the kernel by LaunchScopePolicy), never re-executed by a substitute agent.
        $agentMap = [
            'seo' => 'james', 'write' => 'priya', 'creative' => 'sarah',
            'crm' => 'elena',
            'builder' => 'sarah', 'beforeafter' => 'sarah', 'traffic' => 'alex',
            'studio' => 'sarah',
        ];

        $agents = ['sarah']; // Sarah always coordinates
        foreach ($engines as $engine) {
            $agent = $agentMap[$engine] ?? 'sarah';
            if (!in_array($agent, $agents)) {
                // Check expertise for this industry
                $agentModel = Agent::where('slug', $agent)->first();
                if ($agentModel && $industry) {
                    $expertise = $this->agentExperience->getIndustryExpertise($agentModel->id, $industry);
                    $agents[] = $agent;
                } else {
                    $agents[] = $agent;
                }
            }
        }

        return $agents;
    }

    private function buildTaskSequence(array $analysis, ?array $strategy): array
    {
        $wsId = $analysis['workspace_id'] ?? 0;
        $goal = $analysis['goal'] ?? '';

        // Use ToolSelectorService to dynamically select tools across all required engines.
        // Every tool pick is scored, justified, and cost-calculated from blueprint metadata.
        $selection = $this->toolSelector->selectTools($wsId, $goal, $analysis);

        // The sequence returned by ToolSelectorService is already structured correctly
        // with engine, action, agent, params, depends_on, score, justification, cost.
        // Attach the selection metadata to the analysis so it's visible to downstream code.
        if (isset($analysis['_selection_trace'])) {
            // Already traced — shouldn't happen, but guard against duplication
            return $selection['sequence'];
        }

        return $selection['sequence'];
    }

    /**
     * Public helper exposed for plan creation: returns the full selection trace
     * (tools + scores + justifications + rejected tools + confidence).
     * Called by createPlan() to store decision transparency alongside the plan.
     */
    public function getSelectionTrace(int $wsId, string $goal, array $analysis): array
    {
        // b17 (2026-07-24) — INTENT BEFORE RANKING.
        //
        // toolSelector is a cost/quality ranker, not a planner: it emits every
        // blueprint tool for the identified engine and never reads the goal. So
        // "publish 2 of my draft articles" produced the whole write-engine tool
        // list and wrote a new article titled after the request, never touching
        // the customer's drafts.
        //
        // GoalIntentService handles goals with an explicit operation over real,
        // resolvable rows and binds actual entity IDs. It returns null for
        // exploratory goals ("grow my traffic"), where the ranker's breadth is
        // genuinely the right answer — so this is additive and the previous
        // behaviour is untouched for everything it does not claim.
        try {
            $intent = app(\App\Core\Strategy\GoalIntentService::class)
                ->resolve($wsId, $goal, $analysis);

            if ($intent !== null && !empty($intent['sequence'])) {
                Log::info('[Sarah] plan built from resolved intent', [
                    'ws_id'  => $wsId,
                    'goal'   => $goal,
                    'intent' => $intent['intent'] ?? null,
                    'tasks'  => count($intent['sequence']),
                ]);
                return $intent + ['dimensions_weights' => []];
            }

            // Intent understood but nothing to act on (e.g. zero drafts).
            // Returning the empty sequence is correct — running the ranker here
            // would silently substitute unrelated work for what was asked.
            if ($intent !== null && ($intent['intent']['resolved'] ?? null) === 0) {
                Log::info('[Sarah] intent resolved to no eligible entities', [
                    'ws_id' => $wsId, 'goal' => $goal, 'intent' => $intent['intent'],
                ]);
                return $intent + ['dimensions_weights' => []];
            }
        } catch (\Throwable $e) {
            Log::warning('[Sarah] intent resolution errored, using ranker', [
                'ws_id' => $wsId, 'error' => $e->getMessage(),
            ]);
        }

        return $this->toolSelector->selectTools($wsId, $goal, $analysis);
    }

    /**
     * Wave 90 — Approval-required decision via runtime (canonical).
     * On unreachable runtime, requires human approval (true).
     */
    /**
     * b17 — Protected (irreversible / public) actions in a plan's task list,
     * grouped by action with a count. Protected is CapabilityMapService's
     * strictest approval mode: publishing content live, deleting records,
     * sending to real recipients.
     *
     * @return array<string,int> e.g. ['publish_article' => 42]
     */
    private function protectedActionsInPlan(int $planId): array
    {
        $cap = app(\App\Core\EngineKernel\CapabilityMapService::class);

        $rows = DB::table('plan_tasks')->where('plan_id', $planId)->pluck('action');

        $out = [];
        foreach ($rows as $action) {
            try {
                if ($cap->getApprovalMode((string) $action) === 'protected') {
                    $out[$action] = ($out[$action] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                // Unknown action — err on the side of asking.
                $out[$action] = ($out[$action] ?? 0) + 1;
            }
        }
        return $out;
    }

    /** Plain-English summary of protected work, for the approval prompt. */
    private function describeProtectedWork(array $protectedActions): string
    {
        $labels = [
            'publish_article'      => ['publish', 'article', 'articles'],
            'publish_website'      => ['publish', 'website', 'websites'],
            'publish_builder_page' => ['publish', 'page', 'pages'],
            'social_publish_post'  => ['publish', 'social post', 'social posts'],
            'delete_article'       => ['permanently delete', 'article', 'articles'],
        ];

        $parts = [];
        foreach ($protectedActions as $action => $count) {
            [$verb, $one, $many] = $labels[$action]
                ?? ['run', str_replace('_', ' ', $action), str_replace('_', ' ', $action)];
            $parts[] = "{$verb} {$count} " . ($count === 1 ? $one : $many);
        }

        return 'This will ' . implode(' and ', $parts)
            . ' — that goes live publicly and cannot be undone automatically.';
    }

    private function requiresApproval(array $analysis): bool
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $needs = $rt->orchestratorRequiresApproval($analysis);
            if ($needs !== null) return $needs;
        }
        // Wave 90 Phase D — runtime canonical. Require human approval
        // when in doubt rather than autonomously executing.
        return true;
    }


    private function estimateCredits(array $engines): int
    {
        // Backward-compat: called with just engine list (no task sequence yet).
        // Uses ToolCostCalculatorService with the engine fallback map so we still
        // get blueprint-driven estimates where blueprints exist.
        $fakeSequence = [];
        foreach ($engines as $engine) {
            // Use a sentinel action so fallback path kicks in cleanly per engine
            $fakeSequence[] = ['engine' => $engine, 'action' => '__estimate__'];
        }
        $breakdown = $this->costCalc->estimate($fakeSequence);
        return $breakdown['total'];
    }

    /**
     * Estimate credits from a fully-planned task sequence (preferred path).
     * Returns the full breakdown: total, per-task, per-engine, confidence.
     */
    public function estimateCreditsFromSequence(array $taskSequence): array
    {
        return $this->costCalc->estimate($taskSequence);
    }

    /**
     * Wave 90 — Task count heuristic via runtime (canonical).
     * On unreachable runtime, returns safe minimum 2.
     */
    private function estimateTaskCount(array $engines, string $goal): int
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            $count = $rt->orchestratorEstimateTaskCount($engines, $goal);
            if ($count !== null) return $count;
        }
        // Wave 90 Phase D — runtime canonical. Safe minimum count.
        return 2;
    }


    private function generatePlanTitle(string $goal): string
    {
        $words = str_word_count($goal, 1);
        return count($words) > 8 ? implode(' ', array_slice($words, 0, 8)) . '...' : $goal;
    }

    private function generateStrategy(int $wsId, string $goal, array $analysis): ?array
    {
        $workspace = Workspace::find($wsId);
        $wsContext = $workspace ? PromptTemplates::workspaceContext($workspace->toArray()) : '';
        // 2026-05-27 Phase 4 — append category mix so Sarah sees the
        // workload balance when reasoning about delegation/strategy.
        if ($workspace) {
            $catLine = \App\Core\Orchestration\AgentMeetingEngine::categoryMixLine($workspace->id);
            if ($catLine !== '') $wsContext .= "\n" . $catLine;
        }
        $knowledgeContext = $this->globalKnowledge->buildAgentContext('marketing', $workspace?->industry, $workspace?->location);

        // Build a per-engine intelligence briefing so the LLM knows what tools
        // actually exist, their credit costs, effectiveness scores, best practices,
        // and constraints. This replaces the previous "Engines available: a, b, c" blind spot.
        $engineBriefings = [];
        foreach ($analysis['engines_required'] ?? [] as $engine) {
            $briefing = $this->engineIntel->buildEnginePrompt($engine);
            if (!empty($briefing)) {
                $engineBriefings[] = $briefing;
            }
        }
        $engineContext = implode("\n\n", $engineBriefings);

        // Also include the tool selection trace if available — shows the LLM
        // which tools were already picked and why
        $selectionTrace = '';
        if (!empty($analysis['_selection_trace'])) {
            $trace = $analysis['_selection_trace'];
            $selectionTrace = "\n\nPre-selected tools (from ToolSelectorService):\n";
            foreach ($trace['sequence'] ?? [] as $idx => $task) {
                $selectionTrace .= "  " . ($idx + 1) . ". {$task['engine']}.{$task['action']} "
                    . "(agent: {$task['agent']}, cost: {$task['cost']} credits, score: {$task['score']})\n";
                $selectionTrace .= "     Justification: {$task['justification']}\n";
            }
            $selectionTrace .= "Total cost: {$trace['total_cost']} credits. Confidence: " . round(($trace['confidence'] ?? 0) * 100) . "%.\n";
        }

        // MIGRATED 2026-04-13 (Phase 0.17b): switched from aiRun fold-pattern
        // to chatJson. The full strategy meeting prompt + global knowledge +
        // engine intelligence is now passed as a proper system prompt; the
        // goal + analysis snapshot is the user prompt. The LLM returns a
        // {"strategic_plan":"<narrative>"} envelope so the public return shape
        // is preserved.
        $systemPrompt = PromptTemplates::strategyMeeting()
            . "\n\nGlobal Knowledge:\n{$knowledgeContext}"
            . "\n\nEngine Intelligence (what tools you actually have):\n{$engineContext}"
            . "\n\nWorkspace context:\n{$wsContext}"
            . "\n\nOutput ONLY a valid JSON object of the form {\"strategic_plan\":\"<2-4 short paragraphs of narrative>\"}. No markdown, no commentary outside the JSON.";

        // ── House account: aggressive marketing context ─────────────
        if ($workspace && $workspace->is_house_account) {
            $systemPrompt .= "\n\nSPECIAL CONTEXT: This is the LevelUp Growth platform's own marketing workspace."
                . "\nYou are marketing an AI Marketing Operating System to SMBs in MENA, DACH, and SEA markets."
                . "\nTarget audience: Small and medium business owners in Dubai, UAE who need digital marketing help."
                . "\nBe AGGRESSIVE with content strategy. Proactively:"
                . "\n- Suggest weekly blog topics targeting high-value SEO keywords"
                . "\n- Create social media content calendar every Monday"
                . "\n- Run SEO audits on levelupgrowth.io weekly"
                . "\n- Generate lead magnets and landing page copy"
                . "\n- Track competitor positioning"
                . "\n- Propose email campaign sequences for leads"
                . "\nKey messages: 'Your AI Marketing Team', 'Hire AI agents instead of an agency', '24/7 marketing on autopilot'"
                . "\nPricing: Free → \$19 → \$49 → \$99 → \$199 → \$399/month"
                . "\nAlways think: how do we get the next 10 customers?";
        }

        $userPrompt = "Goal:\n{$goal}\n\n"
                    . "Analysis snapshot:\n"
                    . "  - Engines required: " . implode(', ', $analysis['engines_required'] ?? []) . "\n"
                    . "  - Estimated credits: " . ($analysis['credit_estimate'] ?? 'unknown') . "\n"
                    . "  - Industry: " . ($analysis['industry_context'] ?? 'unknown') . "\n"
                    . "  - Region: " . ($analysis['region_context'] ?? 'unknown') . "\n"
                    . "  - Complexity: " . ($analysis['complexity'] ?? 'unknown')
                    . $selectionTrace . "\n\n"
                    . "Produce a concise strategic narrative explaining how you'll approach this goal, "
                    . "why these engines/agents were chosen, what the main risks are, and what the first "
                    . "concrete steps look like. Do NOT enumerate every task — just the strategic framing.";

        $result = $this->runtime->chatJson($systemPrompt, $userPrompt, [
            'task'              => 'strategic_narrative',
            'workspace_id'      => (string) $wsId,
            'engines_required'  => implode(', ', $analysis['engines_required'] ?? []),
            'has_selection_trace' => !empty($selectionTrace) ? 'yes' : 'no',
        ], 700);

        if (!($result['success'] ?? false) || !is_array($result['parsed'] ?? null)) {
            return null;
        }

        return [
            'strategic_plan' => $result['parsed']['strategic_plan'] ?? $result['text'] ?? '',
            'generated_by' => 'runtime',
            'engine_briefings_injected' => count($engineBriefings),
            'selection_trace_injected' => !empty($selectionTrace),
        ];
    }
}
