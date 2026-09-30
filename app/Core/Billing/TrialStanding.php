<?php

namespace App\Core\Billing;

use Illuminate\Support\Facades\DB;

/**
 * TRIAL-AWARE-1 (Owner 2026-09-30: "add some hardening on how Sarah is aware of the trial and her aim is to convince the
 * user to upgrade").
 *
 * One computed sentence about the workspace's plan standing, written for Sarah's own eyes, plus her aim for it. It is
 * rendered into the never-truncated DERIVED STATE block of every turn (DerivedState) and into the daily brief's state
 * (WorkspaceStateGatherer tier_state.trial_line), so the same facts reach both the conversation and the morning brief.
 *
 * Every number comes from the record: TrialService for the trial, the plans table for the offer (never a typed price),
 * the workspace's own timezone for the end time. Nothing here is guessed, and nothing here spends AI.
 */
final class TrialStanding
{
    public function __construct(
        private TrialService $trials,
        private FeatureGateService $gate,
    ) {}

    /** The facts, for code and for the brief's state. */
    public function facts(int $wsId): array
    {
        $out = ['state' => 'unknown', 'line' => '', 'aim' => ''];
        try {
            $ws = DB::table('workspaces')->where('id', $wsId)->first(['timezone', 'trial_started_at', 'trial_expires_at', 'trial_credits']);
            if (! $ws) return $out;
            $tz = $ws->timezone ?: 'UTC';
            $plan = $this->gate->getActivePlanFor($wsId);
            $offer = $this->offer();
            $status = $this->trials->getTrialStatus($wsId);

            if (! empty($status['has_trial']) && ! empty($status['active'])) {
                $started = \Carbon\Carbon::parse($status['started_at']);
                $ends = \Carbon\Carbon::parse($status['expires_at'])->tz($tz);
                $day = min(3, max(1, (int) floor($started->diffInHours(now()) / 24) + 1));
                $left = (int) ($status['credits_remaining'] ?? 0);
                $total = (int) ($status['trial_credits'] ?? 50);
                $lastDay = $ends->isToday() || $ends->lte(now()->addHours(24));
                $trialPlan = $plan ? $plan->name : 'AI Lite';
                $out['state'] = $lastDay ? 'trial_last_day' : 'trial';
                $out['line'] = sprintf(
                    'PLAN & TRIAL (computed now, exact): free trial of %s, day %d of 3, ends %s%s (%s); %d of %d trial credits left.',
                    $trialPlan, $day, $lastDay ? 'TODAY at ' : '', $ends->format($lastDay ? 'H:i' : 'D j M \a\t H:i'), $tz, $left, $total
                );
                $out['aim'] = ($lastDay
                    ? "  This is the LAST DAY of the trial. Ask for the decision directly today: say plainly what stops without a plan (you and the team) and what stays (the website, contacts and calendar), and name the plan and price below. One clear ask, then respect the answer."
                    : "  Your aim during the trial: make the team's value visible before it ends (site live, first posts, first article, first leads), and make the case for the plan plainly, once per conversation, tied to a result you have just shown. Never pressure, never invent numbers.")
                    . ($offer ? "\n  The plan to name: {$offer}." : '')
                    . "\n  Say the trial end date when it is relevant; never quote a price other than the one written here.";
                return $out;
            }

            if ($plan && (bool) $plan->includes_dmm) {
                $balance = $this->balance($wsId);
                $out['state'] = 'paid';
                $out['line'] = sprintf('PLAN (computed now, exact): %s, %s credits a month; %s credits left. No trial running.',
                    $plan->name, number_format((int) $plan->credit_limit), number_format($balance));
                $out['aim'] = '';
                return $out;
            }

            $endedAt = ! empty($status['has_trial']) && ! empty($status['expired']) && ! empty($status['expires_at'])
                ? \Carbon\Carbon::parse($status['expires_at'])->tz($tz)->format('D j M') : null;
            $out['state'] = 'paused';
            $out['line'] = 'PLAN (computed now, exact): ' . ($plan ? $plan->name : 'Free') . ($endedAt ? ", the free trial ended {$endedAt}" : '')
                . '. This plan does not include you or the team: you are paused and nothing runs until a plan that includes you is chosen'
                . ($offer ? " ({$offer})" : '') . '.';
            return $out;
        } catch (\Throwable $e) {
            return $out;
        }
    }

    /** The block for Sarah's frame: the line, then the aim. Empty when nothing is known. */
    public function render(int $wsId): string
    {
        $f = $this->facts($wsId);
        if ($f['line'] === '') return '';
        return '  ' . $f['line'] . "\n" . ($f['aim'] !== '' ? $f['aim'] . "\n" : '');
    }

    /** One sentence for the daily brief's state. */
    public function line(int $wsId): string
    {
        $f = $this->facts($wsId);
        return trim($f['line'] . ($f['aim'] !== '' ? ' ' . preg_replace('/\s+/', ' ', $f['aim']) : ''));
    }

    /** The cheapest public plan that includes Sarah, from the plans table (the same rule SarahPaused uses). */
    private function offer(): ?string
    {
        try {
            $p = DB::table('plans')->where('is_public', 1)->where('includes_dmm', 1)->where('price', '>', 0)->orderBy('price')->first(['name', 'price', 'credit_limit']);
            return $p ? $p->name . ' at $' . (int) $p->price . ' a month with ' . number_format((int) $p->credit_limit) . ' credits' : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function balance(int $wsId): int
    {
        try { return (int) (DB::table('credits')->where('workspace_id', $wsId)->value('balance') ?? 0); } catch (\Throwable $e) { return 0; }
    }
}
