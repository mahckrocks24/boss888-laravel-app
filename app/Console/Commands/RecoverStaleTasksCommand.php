<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Core\Billing\CreditService;
use App\Core\Audit\AuditLogService;
use App\Services\TaskProgressService;
use App\Services\IdempotencyService;

class RecoverStaleTasksCommand extends Command
{
    protected $signature = 'boss888:recover-stale
        {--timeout=600 : Seconds before a running task is considered stale}
        {--dry-run : Show what would be recovered without acting}';

    protected $description = 'Detect and recover stale/stuck tasks and orphaned credit reservations';

    public function handle(
        CreditService $creditService,
        AuditLogService $auditLog,
        TaskProgressService $progress,
        IdempotencyService $idempotency,
    ): int {
        $timeout = (int) $this->option('timeout');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Scanning for stale tasks (timeout: {$timeout}s)...");

        // ── 1. Stale running tasks ───────────────────────────────────────
        $staleTasks = Task::where('status', 'running')
            ->where('started_at', '<', now()->subSeconds($timeout))
            ->get();

        $this->info("Found {$staleTasks->count()} stale running tasks");

        foreach ($staleTasks as $task) {
            $age = now()->diffInSeconds($task->started_at);
            $this->warn("  Task #{$task->id} ({$task->action}) — running for {$age}s");

            if (! $dryRun) {
                // If under max retries, requeue. Otherwise mark failed.
                if ($task->retry_count < 4) {
                    $task->update(['status' => 'queued', 'progress_message' => 'Recovered from stale state — requeued']);
                    $progress->recordEvent($task->id, 'stale_recovered', 'queued',
                        message: "Recovered from stale running state after {$age}s");
                } else {
                    $task->update([
                        'status' => 'failed',
                        'error_text' => "Stale execution timeout after {$age}s (max retries exhausted)",
                        'completed_at' => now(),
                    ]);
                    $progress->recordEvent($task->id, 'stale_failed', 'failed',
                        message: "Failed: stale execution after {$age}s, no retries left");
                }

                // Release idempotency lock if held
                if ($task->idempotency_key) {
                    $idempotency->releaseLock($task->idempotency_key);
                }

                $auditLog->log($task->workspace_id, null, 'task.stale_recovered', 'Task', $task->id, [
                    'stale_duration' => $age,
                    'new_status' => $task->status,
                ]);
            }
        }

        // ── 2. Stale verifying tasks ─────────────────────────────────────
        $staleVerifying = Task::where('status', 'verifying')
            ->where('updated_at', '<', now()->subSeconds($timeout))
            ->get();

        $this->info("Found {$staleVerifying->count()} stale verifying tasks");

        foreach ($staleVerifying as $task) {
            $this->warn("  Task #{$task->id} ({$task->action}) — stuck in verifying");

            if (! $dryRun) {
                $task->update([
                    'status' => 'degraded',
                    'progress_message' => 'Verification timed out',
                ]);
                $progress->recordEvent($task->id, 'verification_timeout', 'degraded',
                    message: 'Verification never completed');
            }
        }

        // ── 3. Orphaned credit reservations ──────────────────────────────
        $orphaned = $creditService->findOrphanedReservations(30);

        $this->info("Found {$orphaned->count()} orphaned credit reservations");

        foreach ($orphaned as $reservation) {
            $this->warn("  Reservation {$reservation->reservation_reference} — {$reservation->amount} credits, workspace #{$reservation->workspace_id}");

            if (! $dryRun) {
                $creditService->releaseReservedCredits($reservation->reservation_reference);
                $auditLog->log($reservation->workspace_id, null, 'credit.orphan_released', 'CreditTransaction', $reservation->id, [
                    'amount' => $reservation->amount,
                    'reservation_reference' => $reservation->reservation_reference,
                ]);
            }
        }

        // ── 4. Blocked/degraded tasks older than 1 hour ──────────────────
        $staleBlocked = Task::whereIn('status', ['blocked', 'degraded'])
            ->where('updated_at', '<', now()->subHour())
            ->count();

        $this->info("Stale blocked/degraded tasks (>1h): {$staleBlocked}");

        $this->info($dryRun ? 'DRY RUN — no changes made' : 'Recovery complete');

        return 0;
    }
}
