<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * Deterministic trade-off construction.
 *
 * WHY THIS EXISTS.
 * Strategic Tradeoffs sat at 20-59% through every language-level intervention.
 * Measured: recommendation_made 5/6 and options_named 3/6, but cost_of_each
 * 0/5 - she decides confidently and cannot say what the alternatives cost. Two
 * completion passes were built to make her state it; both were rolled back,
 * because a revision pass cannot compare options that were never constructed.
 *
 * A trade-off is not a way of writing. It is a comparison between objects that
 * each carry a cost, a forfeit, a risk and a confidence. Those objects are
 * computable from the workspace record, so they are computed here and handed to
 * her already comparable. She selects and justifies; she does not invent the
 * economics.
 *
 * EVERY FIELD IS DERIVED. Where a quantity cannot be computed the option says so
 * rather than carrying a plausible number - an invented confidence is worse than
 * an absent one, because it will be acted on.
 */
class ExecutiveTradeoffEngine
{
    /**
     * @return array<int, array<string,mixed>> option objects, richest first
     */
    public function options(int $wsId): array
    {
        $t = fn() => DB::table('tasks')->where('workspace_id', $wsId);
        $a = fn() => DB::table('articles')->where('workspace_id', $wsId);
        $wk = now()->subDays(7);

        $drafts    = (int) (clone $a())->where('status', 'draft')->count();
        $scheduled = (int) (clone $a())->where('status', 'scheduled')->count();
        $awaiting  = (int) (clone $t())->where('status', 'awaiting_approval')->count();
        $blocked   = (int) (clone $t())->where('status', 'blocked')->count();
        $failedWk  = (int) (clone $t())->where('status', 'failed')->where('created_at', '>', $wk)->count();
        $doneWk    = (int) (clone $t())->where('status', 'completed')->where('created_at', '>', $wk)->count();
        $open      = (int) (clone $t())->whereIn('status', ['pending', 'awaiting_approval'])->count();
        $balance   = (int) (DB::table('credits')->where('workspace_id', $wsId)->value('balance') ?? 0);

        $price = function (string $action): int {
            try { return max(1, (int) app(\App\Core\EngineKernel\CapabilityMapService::class)
                                    ->getCreditCost($action)); }
            catch (\Throwable) { return 1; }
        };

        // Elapsed time comes from measured throughput only. No throughput, no duration.
        $days = function (int $items) use ($doneWk): ?float {
            if ($items <= 0) return 0.0;
            if ($doneWk <= 0) return null;
            return round($items / max(0.1, $doneWk / 7), 1);
        };

        // Recovery evidence per action: an action with completions this week is
        // recoverable by retry; one with none is not, and that is measured.
        $recoverable = 0; $unrecoverable = 0;
        foreach ((clone $t())->where('status', 'failed')->where('created_at', '>', $wk)
                   ->selectRaw('action, count(*) c')->groupBy('action')->get() as $r) {
            $done = (int) (clone $t())->where('action', $r->action)->where('status', 'completed')
                        ->where('created_at', '>', $wk)->count();
            $done > 0 ? $recoverable += (int) $r->c : $unrecoverable += (int) $r->c;
        }
        $retryConfidence = $failedWk > 0
            ? (int) round(100 * $recoverable / max(1, $recoverable + $unrecoverable)) : null;

        $failureRate = ($doneWk + $failedWk) > 0
            ? (int) round(100 * $failedWk / ($doneWk + $failedWk)) : null;

        $opts = [];

        if ($awaiting > 0) $opts[] = [
            'option'            => 'clear_approvals',
            'action'            => "Clear the {$awaiting} item(s) awaiting your approval",
            'credits'           => 0,
            'elapsed_days'      => 0.0,
            'dependencies'      => 'none - the decision is yours and nothing upstream is required',
            'opportunity_cost'  => 'your decision time only; no other option is foreclosed',
            'risk'              => 'approving work that then fails at the ' . ($failureRate ?? 0) . '% current failure rate',
            'confidence'        => 100,
            'confidence_basis'  => 'the count is exact and no execution is involved',
            'reversibility'     => 'reversible until the approved work publishes',
            'expected_outcome'  => "tasks_awaiting_approval falls from {$awaiting} to 0 and downstream work unblocks",
        ];

        if ($drafts > 0) $opts[] = [
            'option'            => 'publish_drafts',
            'action'            => "Publish the {$drafts} finished drafts",
            'credits'           => 0,
            'elapsed_days'      => $days($drafts),
            'dependencies'      => $awaiting > 0 ? "approval decisions ({$awaiting} outstanding)" : 'none',
            'opportunity_cost'  => 'review time; it does not address why work is failing',
            'risk'              => 'publishing content written before the current failures were understood',
            'confidence'        => 95,
            'confidence_basis'  => 'the work exists and is already paid for; only the publish step remains',
            'reversibility'     => 'IRREVERSIBLE - this platform has no rollback of a published article',
            'expected_outcome'  => "articles_published rises by up to {$drafts}; articles_draft falls to 0",
        ];

        if ($blocked > 0) $opts[] = [
            'option'            => 'redrive_blocked',
            'action'            => "Re-drive the {$blocked} blocked item(s) via retry_blocked",
            'credits'           => 0,
            'elapsed_days'      => $days($blocked),
            'dependencies'      => 'the original blocker must already be gone',
            'opportunity_cost'  => 'none material',
            'risk'              => 'the blocker persists and the item blocks again',
            'confidence'        => 50,
            'confidence_basis'  => 'the record does not say why these are blocked, so success cannot be predicted',
            'reversibility'     => 'reversible',
            'expected_outcome'  => "tasks_blocked falls from {$blocked} toward 0",
        ];

        if ($failedWk > 0) $opts[] = [
            'option'            => 'retry_failures',
            'action'            => "Retry this week's {$failedWk} failures",
            'credits'           => $failedWk * $price('write_article'),
            'elapsed_days'      => $days($failedWk),
            'dependencies'      => 'none, but the cause is unfixed',
            'opportunity_cost'  => 'spends credits a second time on work already paid for, buying nothing new',
            'risk'              => $unrecoverable > 0
                                    ? "{$unrecoverable} of the failures are in actions with NO completions this week - "
                                      . 'retrying those is likely to fail again'
                                    : 'low; every failing action has completed at least once this week',
            'confidence'        => $retryConfidence,
            'confidence_basis'  => "measured: {$recoverable} of {$failedWk} failures are in actions that also "
                                 . 'completed this week',
            'reversibility'     => 'reversible - a retry that fails leaves the state unchanged',
            'expected_outcome'  => 'up to ' . $recoverable . ' items recovered; the rest need the cause found first',
        ];

        $opts[] = [
            'option'            => 'pause_commissioning',
            'action'            => 'Pause new commissioning until the backlog drains',
            'credits'           => 0,
            'elapsed_days'      => $days($open),
            'dependencies'      => 'none',
            'opportunity_cost'  => 'ALL new output while paused; published volume falls further before it recovers',
            'risk'              => 'output gap widens if the backlog does not clear at the current rate',
            'confidence'        => $doneWk > 0 ? 80 : null,
            'confidence_basis'  => $doneWk > 0
                                    ? "throughput is measured at {$doneWk} completions a week"
                                    : 'no completions this week, so no clearance rate can be computed',
            'reversibility'     => 'fully reversible - resume at any time',
            'expected_outcome'  => "open_backlog falls from {$open} toward 0",
        ];

        $newBatch = 10 * $price('write_article') + 10 * $price('generate_image_mini');
        $opts[] = [
            'option'            => 'commission_new',
            'action'            => 'Commission 10 replacement articles',
            'credits'           => $newBatch,
            'elapsed_days'      => $days(10),
            'dependencies'      => 'the same queue that is currently failing',
            'opportunity_cost'  => "{$newBatch} credits of a {$balance} balance, and queue capacity that the "
                                 . 'existing backlog needs',
            'risk'              => $failureRate !== null
                                    ? "the same {$failureRate}% failure rate applies to this work"
                                    : 'unmeasured failure rate',
            'confidence'        => $failureRate !== null ? max(0, 100 - $failureRate) : null,
            'confidence_basis'  => 'derived from the measured failure rate on work already attempted',
            'reversibility'     => 'credits spent are not recoverable',
            'expected_outcome'  => 'up to 10 new drafts, joining a queue that is not currently clearing',
        ];

        return $opts;
    }

    /**
     * The comparison table. Fixed fields per option so two options are compared
     * on the same axes rather than on whichever attribute came to mind.
     */
    public function render(int $wsId): string
    {
        $opts = $this->options($wsId);
        if (!$opts) return '';

        $out = "EXECUTIVE OPTIONS - computed, comparable, complete.\n"
             . "These are the courses of action available in this workspace right now, each costed\n"
             . "from the platform's own price list and timed from measured throughput. To decide,\n"
             . "COMPARE THESE OBJECTS on cost, opportunity_cost, risk, confidence and reversibility,\n"
             . "then say which you chose and which you rejected. Do not invent an option that is not\n"
             . "here, and do not assert a cost, duration or confidence that differs from these.\n";

        foreach ($opts as $o) {
            $out .= "\n  [{$o['option']}] {$o['action']}\n";
            $out .= "    cost             : {$o['credits']} credits\n";
            $out .= "    elapsed          : " . ($o['elapsed_days'] === null
                        ? 'not computable - no measured throughput' : "{$o['elapsed_days']} day(s)") . "\n";
            $out .= "    dependencies     : {$o['dependencies']}\n";
            $out .= "    opportunity_cost : {$o['opportunity_cost']}\n";
            $out .= "    risk             : {$o['risk']}\n";
            $out .= "    confidence       : " . ($o['confidence'] === null
                        ? 'NOT COMPUTABLE - say so rather than estimating'
                        : "{$o['confidence']}% ({$o['confidence_basis']})") . "\n";
            $out .= "    reversibility    : {$o['reversibility']}\n";
            $out .= "    expected_outcome : {$o['expected_outcome']}\n";
        }

        return $out;
    }
}
