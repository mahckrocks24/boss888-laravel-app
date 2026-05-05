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
