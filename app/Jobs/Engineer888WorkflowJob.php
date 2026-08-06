<?php

namespace App\Jobs;

use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Run the engineering lifecycle off the request.
 *
 * VERIFY runs the project's whole suite — 405 seconds on the run that proved
 * Sprint 7. A synchronous request would die at the proxy and leave the UI
 * unable to distinguish a slow run from a dead one, so the workflow runs on the
 * queue and the Command Center polls the stage table it writes as it goes.
 *
 * NO RETRIES. `$tries = 1` is a safety property, not a tuning choice: a retried
 * workflow would re-enter IMPLEMENT, and a half-finished install repeated
 * automatically is exactly the situation SafeInstaller's backups exist to make
 * recoverable rather than routine.
 *
 * NOTE FOR DEPLOYS: queue workers cache app/ code. After changing anything the
 * workflow touches, `php artisan queue:restart` — otherwise this job runs the
 * previous revision and the stage trail will describe code that is no longer
 * on disk.
 */
final class Engineer888WorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Long enough for the suite, short enough that a hang is visible. */
    public int $timeout = 1800;

    public function __construct(
        public readonly string $taskUuid,
        public readonly bool $dryRun = false,
        public readonly string $actor = 'unknown',
    ) {}

    public function handle(): void
    {
        $engine = new WorkflowEngine();

        try {
            $result = $engine->run($this->taskUuid, $this->dryRun);

            Log::info('[engineer888] workflow finished', [
                'task' => $this->taskUuid, 'status' => $result['status'],
                'halted_at' => $result['halted_at'], 'actor' => $this->actor,
            ]);
        } catch (\Throwable $e) {
            // The run threw before it could record its own outcome, so the task
            // would otherwise sit on `running` for ever. Recorded as failed with
            // the reason rather than left ambiguous.
            DB::table('engineering_tasks')->where('uuid', $this->taskUuid)->update([
                'status' => 'failed', 'updated_at' => now(),
            ]);

            Log::error('[engineer888] workflow threw', [
                'task' => $this->taskUuid, 'error' => get_class($e) . ': ' . $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        DB::table('engineering_tasks')->where('uuid', $this->taskUuid)->update([
            'status' => 'failed', 'updated_at' => now(),
        ]);
    }
}
