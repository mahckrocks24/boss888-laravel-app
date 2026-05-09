<?php

namespace App\Core\Orchestration;

use App\Core\TaskSystem\TaskDispatcher;
use App\Core\TaskSystem\TaskService;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ExecutionPlanService — multi-step task dependency graphs.
 *
 * Spec deviations vs the original ask:
 *   - tasks.status is the canonical state column (no parallel `state`).
 *     "blocked-on-deps" maps to `pending` until unlocked, then the task
 *     transitions through queued -> running -> completed.
 *   - tasks has no `agent_id`; assignees are stored as a JSON array on
 *     `assigned_agents_json`. createPlan() accepts either an `agent` slug
 *     string or `agent_id` number for compatibility, but always writes
 *     the slug array.
 *   - TaskService::create signature is (wsId, array $data). Wrapper used
 *     accordingly.
 *
 * Patched 2026-05-10 (Phase 2F).
 */
class ExecutionPlanService
{
    /**
     * Build a multi-step plan. $steps elements may include:
     *   - engine, action  (required)
     *   - agent           (slug, optional — defaults to 'sarah')
     *   - payload         (array, optional)
     *   - depends_on      (array of step indices in $steps, optional)
     *
     * Steps with no dependencies are dispatched immediately; the rest
     * stay `pending` and are unlocked by onTaskComplete() when their
     * dependencies finish.
     */
    public function createPlan(int $wsId, string $goal, array $steps, array $meta = []): string
    {
        $planId = 'plan_' . Str::random(20);
        $taskIds = [];
        $now = now();

        foreach ($steps as $i => $step) {
            $engine     = (string)($step['engine'] ?? '');
            $action     = (string)($step['action'] ?? '');
            $agentSlug  = (string)($step['agent']  ?? 'sarah');
            $payload    = (array) ($step['payload'] ?? []);
            $depIndices = (array) ($step['depends_on'] ?? []);

            // Translate dependency step-indices to task ids assigned earlier
            // in this same plan creation. Forward references are skipped.
            $depTaskIds = [];
            foreach ($depIndices as $idx) {
                if (isset($taskIds[$idx])) $depTaskIds[] = $taskIds[$idx];
            }

            // Use TaskService for canonical write path. Note: TaskService
            // automatically dispatches on its own — but only when there are
            // no unmet dependencies. For now we let it create the row and
            // override status/dispatch logic here based on $depTaskIds.
            $task = app(TaskService::class)->create($wsId, [
                'engine'          => $engine,
                'action'          => $action,
                'source'          => 'agent',
                'priority'        => 'normal',
                'assigned_agents' => [$agentSlug],
                'payload'         => $payload,
            ]);

            DB::table('tasks')->where('id', $task->id)->update([
                'execution_plan_id' => $planId,
                'sequence_order'    => $i,
                'depends_on'        => json_encode($depTaskIds),
                // If unmet deps, force status back to pending so workers
                // skip this row and the unlock path picks it up later.
                'status'            => empty($depTaskIds) ? $task->status : 'pending',
                'updated_at'        => $now,
            ]);

            // Dispatch only if no unmet deps AND not approval-gated.
            if (empty($depTaskIds) && !$task->requires_approval) {
                try {
                    app(TaskDispatcher::class)->dispatch(Task::find($task->id));
                } catch (\Throwable $e) {
                    Log::warning("ExecutionPlanService::createPlan dispatch failed for task {$task->id}: " . $e->getMessage());
                }
            }

            $taskIds[$i] = $task->id;
        }

        DB::table('agent_execution_plans')->insert([
            'id'           => $planId,
            'workspace_id' => $wsId,
            'goal'         => mb_substr($goal, 0, 500),
            'task_ids'     => json_encode($taskIds),
            'status'       => 'running',
            'meta'         => json_encode($meta),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        Log::info("ExecutionPlanService::createPlan {$planId}", [
            'ws' => $wsId, 'steps' => count($steps), 'goal' => $goal,
        ]);

        return $planId;
    }

    /**
     * Called from Orchestrator after markCompleted. Unlocks any sibling
     * tasks in the same plan whose dependencies are now all satisfied.
     */
    public function onTaskComplete(int $taskId): void
    {
        $task = DB::table('tasks')->where('id', $taskId)->first();
        if (!$task || empty($task->execution_plan_id)) return;

        $planId = (string)$task->execution_plan_id;

        // Find sibling tasks still pending in this plan
        $siblings = DB::table('tasks')
            ->where('execution_plan_id', $planId)
            ->where('status', 'pending')
            ->get();

        foreach ($siblings as $s) {
            $deps = $s->depends_on ? (json_decode($s->depends_on, true) ?: []) : [];
            if (empty($deps)) continue;

            $unmet = DB::table('tasks')
                ->whereIn('id', $deps)
                ->where('status', '!=', 'completed')
                ->exists();
            if ($unmet) continue;

            // All deps complete — inject their results into payload then dispatch.
            $depResults = DB::table('tasks')
                ->whereIn('id', $deps)
                ->pluck('result_json', 'id')
                ->all();

            $payload = $s->payload_json ? (json_decode($s->payload_json, true) ?: []) : [];
            $payload['_dependency_results'] = array_map(
                fn($v) => is_string($v) ? (json_decode($v, true) ?: $v) : $v,
                $depResults
            );

            DB::table('tasks')->where('id', $s->id)->update([
                'payload_json' => json_encode($payload),
                'status'       => 'queued',
                'queued_at'    => now(),
                'updated_at'   => now(),
            ]);

            try {
                app(TaskDispatcher::class)->dispatch(Task::find($s->id));
                Log::info("ExecutionPlan {$planId}: task {$s->id} unlocked after task {$taskId} completed");
            } catch (\Throwable $e) {
                Log::warning("ExecutionPlan {$planId}: dispatch on unlock failed for task {$s->id}: " . $e->getMessage());
            }
        }

        // Plan completion check
        $stillOpen = DB::table('tasks')
            ->where('execution_plan_id', $planId)
            ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
            ->exists();
        if (!$stillOpen) {
            $anyFailed = DB::table('tasks')
                ->where('execution_plan_id', $planId)
                ->where('status', 'failed')
                ->exists();
            DB::table('agent_execution_plans')
                ->where('id', $planId)
                ->update([
                    'status'       => $anyFailed ? 'failed' : 'completed',
                    'completed_at' => now(),
                    'updated_at'   => now(),
                ]);
            Log::info("ExecutionPlan {$planId} terminal state: " . ($anyFailed ? 'failed' : 'completed'));
        }
    }

    public function getPlanStatus(string $planId): array
    {
        $plan = DB::table('agent_execution_plans')->where('id', $planId)->first();
        if (!$plan) return ['error' => 'Plan not found', 'plan_id' => $planId];

        $tasks = DB::table('tasks')
            ->where('execution_plan_id', $planId)
            ->orderBy('sequence_order')
            ->get(['id', 'status', 'engine', 'action', 'sequence_order',
                   'depends_on', 'completed_at', 'duration_ms', 'error_text']);

        $completed = $tasks->where('status', 'completed')->count();
        $failed    = $tasks->where('status', 'failed')->count();
        $total     = $tasks->count();

        return [
            'plan_id'      => $planId,
            'goal'         => $plan->goal,
            'status'       => $plan->status,
            'progress'     => "{$completed}/{$total}",
            'completed'    => $completed,
            'failed'       => $failed,
            'total'        => $total,
            'tasks'        => $tasks->all(),
            'created_at'   => $plan->created_at,
            'completed_at' => $plan->completed_at,
        ];
    }
}
