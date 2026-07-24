<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * lu:scheduled-actions:run — P1 executor for scheduled_actions (SHADOW MODE).
 *
 * Per SCHEDULING-ARCHITECTURE-2026-06-08.md. Mirrors RunSequences (the existing
 * due->execute precedent). Finds due scheduled_actions and, in P1, ONLY LOGS
 * what it WOULD fire — it creates no tasks and touches no live execution. This
 * proves the finder + recurrence math at zero risk before P2 wires real firing.
 *
 * P2 (later, behind --live, after planner sign-off) will, per due row:
 *   1. ask the RUNTIME governance brain for approval_mode (confidence-score)
 *      + requires-approval (orchestrator-intelligence),
 *   2. auto -> TaskService::create + Orchestrator (generation via RuntimeClient),
 *      review/protected -> raise an approval (never auto-fire),
 *   3. one-shot -> status=completed; recurring -> advance fire_at.
 *
 * Default mode is SHADOW. --live is intentionally NOT implemented yet (P2).
 */
class RunScheduledActions extends Command
{
    protected $signature = 'lu:scheduled-actions:run
        {--live : (P2, not yet implemented) actually fire due actions}
        {--limit=100 : max due rows to process this run}';

    protected $description = 'P1 shadow-mode executor: log what scheduled_actions would fire (fires nothing).';

    public function handle(): int
    {
        if ($this->option('live')) {
            $this->error('--live is not implemented in P1 (shadow mode only). See SCHEDULING-ARCHITECTURE P2.');
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        $due = DB::table('scheduled_actions')
            ->where('status', 'scheduled')
            ->where('fire_at', '<=', now())
            ->orderBy('fire_at')
            ->limit($limit)
            ->get();

        if ($due->isEmpty()) {
            $this->info('[scheduled-actions][SHADOW] no due rows.');
            return self::SUCCESS;
        }

        $n = 0;
        foreach ($due as $row) {
            // What P2 WOULD do — logged, not done.
            $msg = sprintf(
                '[scheduled-actions][SHADOW] would fire id=%d ws=%d agent=%s engine=%s action=%s fire_at=%s recurrence=%s',
                $row->id, $row->workspace_id, $row->agent_slug ?? '-',
                $row->engine, $row->action, $row->fire_at, $row->recurrence ?? 'one-shot'
            );
            Log::info($msg);
            $this->line($msg);

            // Mark logged so we don't re-log the same row every minute. In P2
            // this becomes the real scheduled->fired->completed transition.
            DB::table('scheduled_actions')->where('id', $row->id)->update([
                'status'     => 'shadow_logged',
                'notes'      => 'P1 shadow: would have fired',
                'updated_at' => now(),
            ]);
            $n++;
        }

        $this->info("[scheduled-actions][SHADOW] logged {$n} due row(s); fired 0 (shadow mode).");
        return self::SUCCESS;
    }
}
