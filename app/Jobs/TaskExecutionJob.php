<?php

namespace App\Jobs;

use App\Models\Task;
use App\Core\TaskSystem\Orchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TaskExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [8, 16, 32, 64];

    /**
     * PATCH v1.0.2: explicit timeout = 180s.
     *
     * Reasoning:
     *   - config/queue.php retry_after = 90s (Redis connection)
     *   - DeepSeek deep_audit can run 30-60s; image generation up to 90s
     *   - Laravel default job timeout = 60s — below worst-case AI execution time
     *   - Job timeout must exceed retry_after or the queue treats a
     *     still-running job as lost and spawns a duplicate worker — double execution.
     *   - 180s gives a 2x safety margin above retry_after with headroom for
     *     slow AI provider responses under load.
     *   - Supervisor --max-time=3600 is process lifetime, not per-job — unaffected.
     */
    public int $timeout = 180;

    public function __construct(private int $taskId) {}

    public function handle(Orchestrator $orchestrator): void
    {
        $task = Task::find($this->taskId);

        if (! $task || $task->status === 'completed' || $task->status === 'failed') {
            return;
        }

        $orchestrator->execute($task);
    }
}
