<?php

namespace App\Console\Commands\Tests;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Models\CreditTransaction;
use App\Core\Billing\CreditService;
use Illuminate\Support\Facades\Cache;

class WorkerFailureTestCommand extends Command
{
    protected $signature = 'boss888:worker-failure-test';

    protected $description = 'Phase 5: Simulate worker crash and validate recovery';

    public function handle(CreditService $creditService): int
    {
        $this->info('═══ Worker Failure + Recovery Test ═══');

        $ws = \App\Models\Workspace::first();
        if (! $ws) {
            $this->error('No workspace found.');
            return 1;
        }

        // 1. Create a task stuck in running (simulates worker crash)
        $this->info('  Creating stuck task...');
        $stuckTask = Task::create([
            'workspace_id' => $ws->id,
            'engine' => 'crm',
            'action' => 'create_lead',
            'payload_json' => ['name' => 'Stuck Task Test'],
            'status' => 'running',
            'started_at' => now()->subMinutes(15),
            'source' => 'manual',
            'idempotency_key' => 'idem_stuck_test_' . time(),
        ]);

        // 2. Create an orphaned credit reservation
        $this->info('  Creating orphaned credit reservation...');
        $credit = \App\Models\Credit::where('workspace_id', $ws->id)->first();
        $balanceBefore = $credit->balance;

        CreditTransaction::create([
            'workspace_id' => $ws->id,
            'type' => 'reserve',
            'amount' => 25,
            'reservation_status' => 'pending',
            'reservation_reference' => 'rsv_stuck_' . time(),
            'reference_type' => 'Task',
            'reference_id' => $stuckTask->id,
            'created_at' => now()->subMinutes(45),
        ]);
        $credit->increment('reserved_balance', 25);

        // 3. Set an idempotency lock (simulates lock left by crashed worker)
        $lockKey = $stuckTask->idempotency_key;
        Cache::put("idem_lock:{$lockKey}", now()->toIso8601String(), 3600);

        $this->info('  State: task stuck, reservation orphaned, lock held');

        // 4. Run recovery
        $this->info('  Running boss888:recover-stale...');
        $this->call('boss888:recover-stale', ['--timeout' => 60]);

        // 5. Validate
        $stuckTask->refresh();
        $credit->refresh();

        $taskFixed = in_array($stuckTask->status, ['queued', 'failed']);
        $lockReleased = ! Cache::has("idem_lock:{$lockKey}");
        $reservedCleared = $credit->reserved_balance === 0;

        $this->newLine();
        $this->table(
            ['Check', 'Status'],
            [
                ['Stuck task resolved', $taskFixed ? "✅ ({$stuckTask->status})" : '❌ still running'],
                ['Idempotency lock released', $lockReleased ? '✅' : '❌ still held'],
                ['Orphaned credits released', $reservedCleared ? '✅' : "❌ reserved={$credit->reserved_balance}"],
            ]
        );

        $passed = $taskFixed && $reservedCleared;

        $this->newLine();
        $this->line($passed ? '✅ WORKER FAILURE RECOVERY PASSED' : '❌ WORKER FAILURE RECOVERY FAILED');

        return $passed ? 0 : 1;
    }
}
