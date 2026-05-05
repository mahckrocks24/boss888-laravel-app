<?php

namespace App\Console\Commands\Tests;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Models\CreditTransaction;
use App\Core\TaskSystem\TaskService;
use App\Services\IdempotencyService;

class IdempotencyTestCommand extends Command
{
    protected $signature = 'boss888:idempotency-test
        {--concurrent=10 : Number of duplicate dispatches}
        {--wait=30 : Seconds to wait for processing}';

    protected $description = 'Phase 5: Validate idempotency under real distributed concurrency';

    public function handle(TaskService $taskService, IdempotencyService $idempotency): int
    {
        $concurrent = (int) $this->option('concurrent');
        $waitSec = (int) $this->option('wait');

        $this->info("═══ Idempotency Stress Test ═══");
        $this->info("Concurrent dispatches: {$concurrent}");

        // Use a fixed workspace
        $ws = \App\Models\Workspace::first();
        if (! $ws) {
            $this->error('No workspace found. Run db:seed first.');
            return 1;
        }

        $payload = ['name' => 'Idempotency Test ' . now()->timestamp, 'source' => 'idem_test'];
        $idemKey = $idempotency->generateKey($ws->id, 'create_lead', $payload);
        $startMarker = now();

        $this->info("Idempotency key: {$idemKey}");
        $this->info("Dispatching {$concurrent} identical tasks...");

        $taskIds = [];
        for ($i = 0; $i < $concurrent; $i++) {
            try {
                $task = $taskService->create($ws->id, [
                    'engine' => 'crm',
                    'action' => 'create_lead',
                    'payload' => $payload,
                    'source' => 'manual',
                    'idempotency_key' => $idemKey,
                ]);
                $taskIds[] = $task->id;
            } catch (\Throwable $e) {
                $this->warn("  Dispatch {$i} blocked: {$e->getMessage()}");
            }
        }

        $this->info("Dispatched " . count($taskIds) . " tasks");
        $this->info("Waiting {$waitSec}s for workers...");
        sleep($waitSec);

        // Analyze results
        $tasks = Task::whereIn('id', $taskIds)->get();

        $completed = $tasks->where('status', 'completed')->count();
        $skipped = $tasks->filter(fn ($t) =>
            $t->progress_message && str_contains($t->progress_message, 'Duplicate')
        )->count();

        // Check credit commits
        $commits = CreditTransaction::where('workspace_id', $ws->id)
            ->where('type', 'commit')
            ->where('created_at', '>=', $startMarker)
            ->count();

        $this->newLine();
        $this->info('═══ Results ═══');
        $this->table(
            ['Check', 'Result', 'Status'],
            [
                ['Tasks dispatched', count($taskIds), '—'],
                ['Completed (including skips)', $completed, $completed > 0 ? '✅' : '❌'],
                ['Idempotent skips', $skipped, '—'],
                ['Credit commits', $commits, $commits <= 1 ? '✅' : '❌'],
                ['Duplicate execution', $commits > 1 ? 'YES' : 'NO', $commits <= 1 ? '✅' : '❌'],
            ]
        );

        $passed = $commits <= 1;

        $this->newLine();
        if ($passed) {
            $this->info('✅ IDEMPOTENCY TEST PASSED — no duplicate execution');
        } else {
            $this->error("❌ IDEMPOTENCY TEST FAILED — {$commits} credit commits detected (expected ≤1)");
        }

        return $passed ? 0 : 1;
    }
}
