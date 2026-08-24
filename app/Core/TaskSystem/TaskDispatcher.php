<?php

namespace App\Core\TaskSystem;

use App\Models\Task;
use App\Jobs\TaskExecutionJob;
use App\Services\QueueControlService;

class TaskDispatcher
{
    public function __construct(private QueueControlService $queueControl) {}

    public function dispatch(Task $task): void
    {
        // Wave 41 — gate dispatch on parent_task_id completion. Sarah's chain
        // sets parent_task_id on every downstream step so each step has access
        // to the parent's article_id, URL, and content. If we dispatch
        // immediately, the child runs seconds before the parent — empty
        // article_id, empty content, empty URL → generic LLM output, failed
        // image gen, and 0 link suggestions.
        if (!empty($task->parent_task_id)) {
            $parent = \Illuminate\Support\Facades\DB::table('tasks')
                ->where('id', $task->parent_task_id)
                ->first(['status']);
            if ($parent && $parent->status !== 'completed') {
                $task->update([
                    'status' => 'blocked',
                    'progress_message' => 'Waiting for parent task #' . $task->parent_task_id,
                ]);
                return;
            }
            // MISSION-018 WS-1 (2026-08-24, RISK-0041): a parent in a terminal
            // failure state can never complete, so its child can never receive
            // the article_id/URL/content it needs. The old code parked the
            // child in 'blocked' and deferred to a "manual unblock" that does
            // not exist anywhere in the codebase — the completion waker fires
            // only on the parent-COMPLETED path, the orphan reaper does not
            // scan 'blocked', and nothing else moves it. That is an entrance
            // with no exit: 101 rows were stuck this way. A child that cannot
            // succeed is marked FAILED (a legal blocked/queued->failed
            // transition and an honest terminal state), so the task tree
            // resolves and the customer sees a true status instead of a task
            // that hangs forever. NOTE: only the parent-FAILED branch changes;
            // the parent-pending branch above still parks in 'blocked' and is
            // woken normally when the parent completes.
            if ($parent && in_array($parent->status, ['failed', 'cancelled', 'degraded'], true)) {
                $task->update([
                    'status' => 'failed',
                    'progress_message' => 'Parent task #' . $task->parent_task_id . ' did not complete (' . $parent->status . '); this step cannot run without it.',
                ]);
                return;
            }
        }

        $task->update(['status' => 'queued']);

        $queue = $this->queueControl->resolveQueue($task);

        // FIX-B: ->onConnection('redis') is explicit.
        // config/queue.php default is 'database'; supervisor workers run `queue:work redis`.
        // Without this, jobs silently go to the database queue and are never picked up.
        TaskExecutionJob::dispatch($task->id)
            ->onConnection('redis')
            ->onQueue($queue)
            ->delay(now()->addSeconds(1));
    }

    public function dispatchWithDelay(Task $task, int $seconds): void
    {
        $queue = $this->queueControl->resolveQueue($task);

        TaskExecutionJob::dispatch($task->id)
            ->onConnection('redis')
            ->onQueue($queue)
            ->delay(now()->addSeconds($seconds));
    }
}
