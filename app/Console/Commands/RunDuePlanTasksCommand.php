<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Orchestration\SarahOrchestrator;

/**
 * b15 (2026-07-24) — Fire DUE scheduled plan tasks.
 *
 * PlanSchedulerService plots a plan onto the calendar by stamping
 * plan_tasks.scheduled_for (+ an automation_events row). Until now NOTHING
 * read that column back: `lu:scheduled-actions:run` targets a different table
 * (scheduled_actions) and is shadow-only, and `sarah:auto-execute` drains
 * strategy_proposals. So a scheduled plan appeared on the calendar and then
 * never fired — the cadence was cosmetic.
 *
 * This command closes that loop: every minute it finds plans that have at
 * least one PENDING task whose scheduled_for has arrived, and hands the plan
 * to the normal execution path (SarahOrchestrator::executeNextTasks), which
 * now skips still-future tasks. Dependencies, retries, credit accounting and
 * agent delegation are unchanged — we only decide WHEN to hand over.
 *
 * Safety: bounded per run, skips plans that aren't executing/approved, and
 * withoutOverlapping() on the scheduler side.
 */
class RunDuePlanTasksCommand extends Command
{
    protected $signature = 'sarah:run-due-tasks
        {--limit=25 : max plans to advance in one run}
        {--plan= : only this plan id}
        {--dry : report what would fire without executing}';

    protected $description = 'Execute plan tasks whose scheduled_for has come due (calendar-driven execution)';

    public function handle(SarahOrchestrator $sarah): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dry   = (bool) $this->option('dry');

        $this->reconcileApprovedTasks($dry);

        // Plans holding at least one due, still-pending task.
        $q = DB::table('plan_tasks')
            ->join('execution_plans', 'execution_plans.id', '=', 'plan_tasks.plan_id')
            ->where('plan_tasks.status', 'pending')
            ->whereNotNull('plan_tasks.scheduled_for')
            ->where('plan_tasks.scheduled_for', '<=', now())
            // 'partial' is included deliberately: a plan with a mix of blocked
            // and future-scheduled tasks is finalized as partial, but its later
            // calendar slots must still fire when they come due.
            // 'awaiting_approval' likewise: once one publish parks in the review
            // queue the plan carries that status, and the remaining slots must
            // still come due on schedule (each will park for review in turn).
            ->whereIn('execution_plans.status', ['executing', 'approved', 'partial', 'awaiting_approval'])
            ->when($this->option('plan'), fn ($qq) => $qq->where('plan_tasks.plan_id', (int) $this->option('plan')))
            ->groupBy('plan_tasks.plan_id', 'execution_plans.workspace_id')
            ->orderBy('plan_tasks.plan_id')
            ->limit($limit)
            ->get([
                'plan_tasks.plan_id',
                'execution_plans.workspace_id',
                DB::raw('COUNT(*) as due_count'),
            ]);

        if ($q->isEmpty()) {
            $this->info('[run-due-tasks] nothing due.');
            return self::SUCCESS;
        }

        $plans = 0; $fired = 0;
        foreach ($q as $row) {
            $planId = (int) $row->plan_id;
            $wsId   = (int) $row->workspace_id;
            $plans++;

            if ($dry) {
                $this->line("  [DRY] plan #{$planId} (ws {$wsId}) — {$row->due_count} task(s) due");
                continue;
            }

            try {
                $results = $sarah->executeNextTasks($wsId, $planId);
                $fired  += count($results);
                $this->line("  ✓ plan #{$planId} (ws {$wsId}) — fired " . count($results) . " task(s)");
                Log::info('[run-due-tasks] advanced plan', [
                    'plan_id' => $planId, 'ws_id' => $wsId,
                    'due' => (int) $row->due_count, 'fired' => count($results),
                ]);
            } catch (\Throwable $e) {
                Log::error('[run-due-tasks] plan failed', [
                    'plan_id' => $planId, 'ws_id' => $wsId, 'error' => $e->getMessage(),
                ]);
                $this->error("  ✗ plan #{$planId}: " . $e->getMessage());
            }
        }

        $this->info("[run-due-tasks] {$plans} plan(s) advanced, {$fired} task(s) fired" . ($dry ? ' [DRY]' : ''));
        return self::SUCCESS;
    }

    /**
     * b17 — Close the loop after a review-queue decision.
     *
     * A plan_task parked as 'awaiting_approval' has a linked `tasks` row
     * (tasks.plan_task_id). ApprovalService executes THAT row when the owner
     * approves, but nothing ever wrote the outcome back to plan_tasks — so a
     * published article left its plan_task reading 'awaiting_approval' forever
     * and the plan could never finalize. Mirror the terminal state back.
     *
     * Runs every minute alongside the due-task scan, so it also self-heals
     * rows stranded before this fix.
     */
    private function reconcileApprovedTasks(bool $dry): void
    {
        // The APPROVAL decision is the source of truth, not tasks.status.
        //
        // tasks.status cannot be trusted on its own here: the pre-b17 code
        // marked the unified tasks row 'completed' at the moment a protected
        // action was parked for review, and TaskService dedupes new tasks onto
        // existing identical rows — so a fresh plan_task can land on a stale
        // row that already claims completed while its approval is still
        // pending. Reconciling on that alone re-published the very lie this
        // command exists to correct (two articles reported live while still
        // drafts). Only a decided approval moves a parked task.
        $rows = DB::table('plan_tasks')
            ->join('tasks', 'tasks.id', '=', 'plan_tasks.task_id')
            ->leftJoin('approvals', 'approvals.task_id', '=', 'tasks.id')
            ->where('plan_tasks.status', 'awaiting_approval')
            ->limit(200)
            ->get([
                'plan_tasks.id as plan_task_id',
                'plan_tasks.plan_id',
                'tasks.status as task_status',
                'tasks.completed_at',
                'approvals.status as approval_status',
            ]);

        if ($rows->isEmpty()) return;

        $moved = 0;
        foreach ($rows as $r) {
            // Still waiting on the owner — leave it parked.
            if ($r->approval_status === 'pending' || $r->approval_status === null) {
                continue;
            }

            $newStatus = match (true) {
                $r->approval_status === 'approved' && $r->task_status === 'completed' => 'completed',
                $r->approval_status === 'approved' && $r->task_status === 'failed'    => 'failed',
                // approved but the task hasn't finished running yet
                $r->approval_status === 'approved'                                    => null,
                // rejected / expired
                default                                                               => 'cancelled',
            };

            if ($newStatus === null) continue;
            $moved++;

            if ($dry) {
                $this->line("  [DRY] plan_task #{$r->plan_task_id} → {$newStatus}");
                continue;
            }

            DB::table('plan_tasks')->where('id', $r->plan_task_id)->update([
                'status'       => $newStatus,
                'completed_at' => $newStatus === 'completed'
                    ? ($r->completed_at ?? now())
                    : null,
                'updated_at'   => now(),
            ]);
        }

        if ($moved > 0) {
            $this->info("[run-due-tasks] reconciled {$moved} decided task(s)" . ($dry ? ' [DRY]' : ''));
        }
    }
}
