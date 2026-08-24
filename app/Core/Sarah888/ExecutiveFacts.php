<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * Typed executive facts - the single computed source for every metric Sarah is
 * shown, and the same source the verifier checks her against.
 *
 * WHY THIS EXISTS.
 * Metrics used to reach her only as prose inside ExecutiveFrame ("1 this month
 * vs 15 last month", "failure rate 43% this week"). A sentence carries a number
 * and its meaning in the same breath, and both she and the verifier had to
 * recover the meaning by reading the words around the figure. That produced a
 * whole class of failures where a CORRECT value acquired a WRONG label:
 *
 *   - "failures surged to 103" bound 103 to "approvals" because the word
 *     appeared later in the sentence
 *   - a 43% weekly rate was checked against the 42% monthly rate and called a
 *     fabrication
 *   - published_last_month was quoted to a board as "last quarter"
 *
 * A fact with a declared unit and period cannot be mislabelled by proximity. The
 * name IS the binding, so no alias table, no forward/backward text scan, and no
 * window ambiguity: failure_rate@last_7_days and failure_rate@month are
 * different facts with different values, and both are true.
 *
 * Every value is computed from the workspace record. Nothing here is estimated,
 * and a quantity that cannot be computed is absent rather than guessed.
 */
class ExecutiveFacts
{
    /** Windows are named once, here, so the frame and the verifier cannot drift. */
    public const P_ALL   = 'all_time';
    public const P_WEEK  = 'last_7_days';
    public const P_PWEEK = 'previous_7_days';
    public const P_MONTH = 'this_month';
    public const P_LMON  = 'last_month';
    public const P_NOW   = 'now';

    /**
     * @return array<int, array{name:string, value:int, unit:string, period:string}>
     */
    public function all(int $wsId): array
    {
        $f = [];
        $add = function (string $name, $value, string $unit, string $period) use (&$f): void {
            if ($value === null) return;
            $f[] = ['name' => $name, 'value' => (int) $value, 'unit' => $unit, 'period' => $period];
        };

        $now    = now();
        $wkFrom = (clone $now)->subDays(7);
        $pwFrom = (clone $now)->subDays(14);
        $mFrom  = (clone $now)->startOfMonth();
        $lmFrom = (clone $mFrom)->subMonth();

        $tasks = fn() => DB::table('tasks')->where('workspace_id', $wsId);
        $arts  = fn() => DB::table('articles')->where('workspace_id', $wsId);

        // ---- task state, all time -------------------------------------------------
        foreach (['pending', 'completed', 'failed', 'blocked', 'awaiting_approval', 'cancelled'] as $s)
            $add("tasks_{$s}", (clone $tasks())->where('status', $s)->count(), 'count', self::P_ALL);
        $add('tasks_total', $tasks()->count(), 'count', self::P_ALL);

        // ---- throughput and failure, by window ------------------------------------
        $win = function (string $status, $from, $to = null) use ($tasks) {
            $q = (clone $tasks())->where('status', $status)->where('created_at', '>', $from);
            if ($to !== null) $q->where('created_at', '<=', $to);
            return (int) $q->count();
        };
        $doneWk  = $win('completed', $wkFrom);
        $failWk  = $win('failed', $wkFrom);
        $donePw  = $win('completed', $pwFrom, $wkFrom);
        $failPw  = $win('failed', $pwFrom, $wkFrom);

        $add('tasks_completed', $doneWk, 'count', self::P_WEEK);
        $add('tasks_failed',    $failWk, 'count', self::P_WEEK);
        $add('tasks_completed', $donePw, 'count', self::P_PWEEK);
        $add('tasks_failed',    $failPw, 'count', self::P_PWEEK);
        $add('tasks_attempted', $doneWk + $failWk, 'count', self::P_WEEK);
        $add('throughput',      $doneWk, 'completions_per_week', self::P_WEEK);

        if ($doneWk + $failWk > 0)
            $add('failure_rate', round(100 * $failWk / ($doneWk + $failWk)), 'percent', self::P_WEEK);
        if ($failPw > 0)
            $add('failure_change', round(100 * ($failWk - $failPw) / $failPw), 'percent', self::P_WEEK);
        if ($donePw > 0)
            $add('completion_change', round(100 * ($doneWk - $donePw) / $donePw), 'percent', self::P_WEEK);

        // ---- month view -----------------------------------------------------------
        $doneM = $win('completed', $mFrom);
        $failM = $win('failed', $mFrom);
        $add('tasks_completed', $doneM, 'count', self::P_MONTH);
        $add('tasks_failed',    $failM, 'count', self::P_MONTH);
        if ($doneM + $failM > 0)
            $add('failure_rate', round(100 * $failM / ($doneM + $failM)), 'percent', self::P_MONTH);

        // ---- articles -------------------------------------------------------------
        foreach (['published', 'draft', 'scheduled'] as $s)
            $add("articles_{$s}", (clone $arts())->where('status', $s)->count(), 'count', self::P_ALL);

        $pubM  = (int) (clone $arts())->where('status', 'published')
                    ->where('created_at', '>=', $mFrom)->count();
        $pubLM = (int) (clone $arts())->where('status', 'published')
                    ->where('created_at', '>=', $lmFrom)->where('created_at', '<', $mFrom)->count();
        $add('articles_published', $pubM,  'count', self::P_MONTH);
        $add('articles_published', $pubLM, 'count', self::P_LMON);
        if ($pubLM > 0)
            $add('published_change', round(100 * ($pubM - $pubLM) / $pubLM), 'percent', self::P_MONTH);

        // ---- what is actually waiting on the OWNER (P2-3) -------------------------
        // Approval-gated work sits in status 'pending' with requires_approval = 1,
        // NOT in 'awaiting_approval'. Counting by status therefore reported 0 while
        // 125 tasks were waiting on Boss. Count by meaning instead, and give each
        // distinct quantity its own name so 'pending' can never be read as
        // 'pending approval'.
        $awaitingOwner = (int) (clone $tasks())
            ->where(function ($w) {
                $w->where('status', 'awaiting_approval')
                  ->orWhere(function ($x) {
                      $x->where('status', 'pending')->where('requires_approval', 1);
                  });
            })->count();
        $runnable = (int) (clone $tasks())->where('status', 'pending')
            ->where(function ($w) {
                $w->where('requires_approval', 0)->orWhereNull('requires_approval');
            })->count();
        $proposalsOpen = (int) DB::table('strategy_proposals')->where('workspace_id', $wsId)
            ->where('status', 'pending_approval')->count();
        $chatOffersLive = (int) DB::table('strategy_proposals')->where('workspace_id', $wsId)
            ->where('type', 'chat_action')->where('status', 'pending_approval')
            ->where('created_at', '>=', (clone $now)->subMinutes(60))->count();

        $add('tasks_awaiting_owner_approval', $awaitingOwner, 'count', self::P_NOW);
        $add('tasks_runnable_no_approval',    $runnable,      'count', self::P_NOW);
        $add('proposals_pending_decision',    $proposalsOpen, 'count', self::P_NOW);
        $add('chat_offers_live',              $chatOffersLive,'count', self::P_NOW);

        // ---- backlog, capacity ----------------------------------------------------
        $open = (int) (clone $tasks())->whereIn('status', ['pending', 'awaiting_approval'])->count();
        $add('open_backlog',   $open, 'count', self::P_NOW);
        $add('credit_balance', DB::table('credits')->where('workspace_id', $wsId)->value('balance') ?? 0,
             'credits', self::P_NOW);
        if ($doneWk > 0)
            $add('backlog_clearance', ceil($open / max(1, $doneWk / 7)), 'days', self::P_NOW);

        // ---- failures by action, this week ----------------------------------------
        foreach ((clone $tasks())->where('status', 'failed')->where('created_at', '>', $wkFrom)
                   ->selectRaw('action, count(*) c')->groupBy('action')->orderByDesc('c')->limit(6)->get() as $r)
            $add('failures_' . $r->action, $r->c, 'count', self::P_WEEK);

        return $f;
    }

    /**
     * The typed block as Sarah sees it. Fixed columns, one fact per line, so a
     * value can never drift from its unit or its period.
     */
    public function render(int $wsId): string
    {
        $rows = $this->all($wsId);
        if (!$rows) return '';

        $out = "TYPED EXECUTIVE FACTS - name | value | unit | period.\n"
             . "These are the computed record. Quote a value ONLY with its own period and unit:\n"
             . "the same name on two periods is two different facts, and both are true. Do not\n"
             . "restate a period as a wider one, and do not state a percent as a count.\n"
             . "'Pending' and 'pending approval' are DIFFERENT things: "
             . "tasks_runnable_no_approval is queued work needing nothing from the owner; "
             . "tasks_awaiting_owner_approval is what is waiting on THEM; "
             . "proposals_pending_decision is offers not yet answered; chat_offers_live "
             . "is what THIS conversation has outstanding. Never merge them, and never "
             . "call tasks_pending 'pending approval'.\n";
        foreach ($rows as $r)
            $out .= sprintf("  %-26s | %8d | %-20s | %s\n", $r['name'], $r['value'], $r['unit'], $r['period']);

        return $out;
    }

    /** Every legitimate value for a fact name, across all periods. Used by the verifier. */
    public function valuesByName(int $wsId): array
    {
        $out = [];
        foreach ($this->all($wsId) as $r) $out[$r['name']][(string) $r['value']] = $r['period'];
        return $out;
    }
}
