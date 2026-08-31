<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MISSION-018 WS-1 (2026-08-24, RISK-0041). One-shot + schedulable recovery for
 * tasks parked in 'blocked' whose parent is in a terminal failure state and can
 * therefore never complete. TaskDispatcher no longer creates these (it now marks
 * such children 'failed' directly), but 101 pre-existing rows were stuck in the
 * entrance-with-no-exit before the fix.
 *
 * SAFETY — surgically scoped to the RISK-0041 signature ONLY:
 *   status = 'blocked' AND parent_task_id's row is in (failed|cancelled|degraded).
 * It never touches the other users of 'blocked' — parent-still-pending (woken by
 * the completion waker), execution-lock-held, or circuit-breaker-open (all
 * temporary and requeued elsewhere). --dry-run reports the exact set and changes
 * nothing; a run without --confirm refuses.
 */
class RecoverBlockedFailedParentCommand extends Command
{
    protected $signature = 'tasks:recover-blocked-failed-parent
                            {--dry-run : list what would change and exit}
                            {--confirm : required to actually write}';

    protected $description = 'Mark blocked tasks whose parent terminally failed as failed (RISK-0041 recovery)';

    public function handle(): int
    {
        $rows = DB::table('tasks as c')
            ->join('tasks as p', 'p.id', '=', 'c.parent_task_id')
            ->where('c.status', 'blocked')
            ->whereIn('p.status', ['failed', 'cancelled', 'degraded'])
            ->select('c.id', 'c.workspace_id', 'c.parent_task_id', 'p.status as parent_status')
            ->get();

        $this->info("RISK-0041 recovery — {$rows->count()} blocked task(s) with a terminally-failed parent.");
        foreach ($rows->take(20) as $r) {
            $this->line("  task #{$r->id} (ws {$r->workspace_id}) parent #{$r->parent_task_id} = {$r->parent_status}");
        }
        if ($rows->count() > 20) {
            $this->line('  … and ' . ($rows->count() - 20) . ' more');
        }

        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run') || ! $this->option('confirm')) {
            $this->warn('DRY RUN — nothing written. Re-run with --confirm to apply.');
            return self::SUCCESS;
        }

        $ids = $rows->pluck('id')->all();
        $affected = DB::table('tasks')
            ->whereIn('id', $ids)
            ->where('status', 'blocked') // re-check under write, never clobber a status that moved
            ->update([
                'status' => 'failed',
                'progress_message' => 'Recovered by RISK-0041 sweep: parent task did not complete; this step could not run.',
                // REASON-1: also record it where every consumer looks, or the task reads as failed for no reason.
                'error_text' => 'Parent task did not complete, so this step could not run (RISK-0041 sweep).',
                'updated_at' => now(),
            ]);

        $this->info("Marked {$affected} task(s) failed.");
        \Illuminate\Support\Facades\Log::warning('tasks:recover-blocked-failed-parent', ['affected' => $affected]);
        return self::SUCCESS;
    }
}
