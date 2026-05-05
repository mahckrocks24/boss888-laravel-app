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
        $needsApproval = $this->requiresApproval($analysis) || !($challenge['approved'] ?? true);

        if ($needsApproval) {
            DB::table('execution_plans')->where('id', $plan['id'])
                ->update(['requires_approval' => true, 'status' => 'draft']);

            return [
                'plan_id' => $plan['id'],
                'status' => 'awaiting_approval',
                'plan' => $plan,
                'assessment' => $assessment,
                'challenges' => $challenge['challenges'] ?? [],
                'suggestions' => $challenge['suggestions'] ?? [],
                'sarah_reasoning' => $assessment['sarah_reasoning'] ?? null,
                'message' => $assessment['recommendation']['message'] ?? "I've created a plan. Please review and approve.",
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

            // UNIFICATION: also create a tasks table row so all UI surfaces can see it
            $taskRowId = DB::table('tasks')->insertGetId([
                'workspace_id' => $wsId,
                'engine' => $task['engine'],
                'action' => $task['action'],
                'payload_json' => json_encode($task['params'] ?? []),
                'status' => 'pending',
                'source' => 'agent',
                'assigned_agents_json' => json_encode([$task['agent']]),
                'priority' => 'normal',
                'plan_task_id' => $insertedId,
                'progress_message' => ucfirst(str_replace('_', ' ', $task['action'])) . ' (Plan #' . $planId . ')',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

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
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // 4. APPROVE & EXECUTE — start the plan
    // ═══════════════════════════════════════════════════════════

    public function approvePlan(int $wsId, int $planId, int $userId): array
    {
        DB::table('execution_plans')->where('id', $planId)->update([
            'status' => 'executing',
            'approved_at' => now(),
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        // Execute first batch of tasks (no dependencies)
        $results = $this->executeNextTasks($wsId, $planId);

        return [
            'plan_id' => $planId,
            'status' => 'executing',
            'tasks_started' => count($results),
            'message' => "Plan approved. I'm starting execution now. I'll report back as tasks complete.",
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
            $pendingTasks = DB::table('plan_tasks')
                ->where('plan_id', $planId)
                ->where('status', 'pending')
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
                    $result = $this->executor->execute($wsId, $task->engine, $task->action, $params, [
                        'user_id' => null,
                        'agent_id' => $task->assigned_agent,
                        'source' => 'orchestrator',
                    ]);

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

    private function assessQuality(array $result): float
    {
        if (!($result['success'] ?? false)) return 0.0;
        $data = $result['data'] ?? [];

        $score = 0.6; // Base score for successful execution

        // Boost for rich results
        if (count($data) > 3) $score += 0.1;
        if (isset($data['score']) && $data['score'] > 70) $score += 0.1;
        if (!empty($data['recommendations'] ?? $data['items'] ?? $data['results'] ?? [])) $score += 0.1;
        if (($result['credits_used'] ?? 0) <= 2) $score += 0.1; // efficient execution

        return min(1.0, round($score, 2));
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
        $remaining = DB::table('plan_tasks')->where('plan_id', $planId)
            ->whereIn('status', ['pending', 'executing', 'blocked'])->count();

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

    public function getPlanStatus(int $planId): array
    {
        $plan = DB::table('execution_plans')->where('id', $planId)->first();
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

    public function cancelPlan(int $planId): void
    {
        DB::table('execution_plans')->where('id', $planId)->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('plan_tasks')->where('plan_id', $planId)->whereIn('status', ['pending', 'blocked'])
            ->update(['status' => 'skipped', 'updated_at' => now()]);
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE — decision engine
    // ═══════════════════════════════════════════════════════════

    private function identifyEngines(string $goal): array
    {
        $lower = strtolower($goal);
        $engines = [];

        $patterns = [
            'seo' => '/\b(seo|keyword|ranking|search|organic|audit|backlink)\b/',
            'write' => '/\b(write|article|blog|content|copy|draft)\b/',
            'creative' => '/\b(image|video|creative|design|photo|visual)\b/',
            'social' => '/\b(social|instagram|facebook|twitter|linkedin|tiktok|post)\b/',
            'marketing' => '/\b(campaign|email|newsletter|automation|marketing|nurture)\b/',
            'crm' => '/\b(lead|crm|contact|deal|follow.?up|pipeline)\b/',
            'builder' => '/\b(website|landing.?page|site|page|builder)\b/',
            'beforeafter' => '/\b(interior|room|before.?after|transform|renovation)\b/',
        ];

        foreach ($patterns as $engine => $pattern) {
            if (preg_match($pattern, $lower)) $engines[] = $engine;
        }

        // Default to SEO + content if unclear
        if (empty($engines)) $engines = ['seo', 'write'];

        return $engines;
    }

    private function selectAgents(int $wsId, array $engines, ?string $industry): array
    {
        $agentMap = [
            'seo' => 'james', 'write' => 'priya', 'creative' => 'sarah',
            'social' => 'marcus', 'marketing' => 'elena', 'crm' => 'elena',
            'builder' => 'sarah', 'beforeafter' => 'sarah', 'traffic' => 'alex',
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
        return $this->toolSelector->selectTools($wsId, $goal, $analysis);
    }

    private function requiresApproval(array $analysis): bool
    {
        // External-facing actions always need approval
        $externalEngines = ['social', 'marketing', 'builder'];
        foreach ($analysis['engines_required'] as $engine) {
            if (in_array($engine, $externalEngines)) return true;
        }

        // High-credit operations need approval
        if ($analysis['credit_estimate'] > 10) return true;

        // Complex plans need approval
        if ($analysis['complexity'] === 'high') return true;

        return false;
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

    private function estimateTaskCount(array $engines, string $goal): int
    {
        return max(1, count($engines) * 2);
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
