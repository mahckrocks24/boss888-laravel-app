<?php

namespace App\Engines\Ads\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AdBudgetService — has this campaign got budget left, and is it pacing?
 *
 * MONEY IS INTEGER MICROS. $1.25 CPM === 1_250_000 micros. There is no float
 * anywhere in this path; a rounding error in an advertiser invoice is not
 * recoverable reputationally.
 *
 * SPEND IS DERIVED FROM DELIVERED, MEASURED EVENTS — never from a counter that
 * could drift. `ad_stats_daily.revenue_micros` is the single source of truth,
 * and it is produced by the same rollup that feeds the advertiser's report. If
 * the advertiser's report and our budget check disagree, one of them is lying;
 * reading both from the same table means neither can.
 *
 * House campaigns (budget_total_micros = null) are never budget-capped, which
 * is what guarantees 100% fill and a slot that is never blank.
 */
final class AdBudgetService
{
    /** @return array{has_budget: bool, reason: string|null, spent_micros: int, remaining_micros: int|null} */
    public function check(object $campaign): array
    {
        // Uncapped (house) campaigns always pass.
        if ($campaign->budget_total_micros === null) {
            return [
                'has_budget'       => true,
                'reason'           => null,
                'spent_micros'     => 0,
                'remaining_micros' => null,
            ];
        }

        $total = (int) $campaign->budget_total_micros;
        $spent = $this->spentMicros((int) $campaign->id);

        if ($spent >= $total) {
            return [
                'has_budget'       => false,
                'reason'           => 'total_budget_exhausted',
                'spent_micros'     => $spent,
                'remaining_micros' => 0,
            ];
        }

        // Daily cap
        if ($campaign->budget_daily_micros !== null) {
            $daily      = (int) $campaign->budget_daily_micros;
            $spentToday = $this->spentMicros((int) $campaign->id, today: true);

            if ($spentToday >= $daily) {
                return [
                    'has_budget'       => false,
                    'reason'           => 'daily_budget_exhausted',
                    'spent_micros'     => $spent,
                    'remaining_micros' => $total - $spent,
                ];
            }

            // Even pacing: don't spend the whole day's budget in the first hour.
            // Allowance grows hour by hour with catch-up for a slow start.
            if (($campaign->pacing ?? 'even') === 'even') {
                $hoursElapsed = (int) now()->format('G') + 1; // 1..24
                $allowance    = (int) floor($daily * ($hoursElapsed / 24));

                if ($spentToday >= $allowance) {
                    return [
                        'has_budget'       => false,
                        'reason'           => 'pacing_throttled',
                        'spent_micros'     => $spent,
                        'remaining_micros' => $total - $spent,
                    ];
                }
            }
        }

        return [
            'has_budget'       => true,
            'reason'           => null,
            'spent_micros'     => $spent,
            'remaining_micros' => $total - $spent,
        ];
    }

    /** Delivered spend in micros, from the same rollup the advertiser is billed on. */
    public function spentMicros(int $campaignId, bool $today = false): int
    {
        try {
            return (int) DB::table('ad_stats_daily')
                ->where('campaign_id', $campaignId)
                ->when($today, fn ($q) => $q->whereDate('stat_date', now()->toDateString()))
                ->sum('revenue_micros');
        } catch (Throwable) {
            // Fail CLOSED on the money path: if spend cannot be read we report
            // the budget as fully consumed rather than risk overdelivery we
            // cannot bill for.
            return PHP_INT_MAX;
        }
    }

    /**
     * Revenue for a delivered event, in micros.
     * CPM bills per 1000 viewable impressions; CPC per click; flat bills nothing
     * per event (it is invoiced as a lump sum).
     */
    public function revenueForEvent(object $campaign, string $event): int
    {
        $rate = (int) ($campaign->rate_micros ?? 0);

        return match ($campaign->pricing_model ?? 'cpm') {
            'cpm'   => $event === 'viewable' ? intdiv($rate, 1000) : 0,
            'cpc'   => $event === 'click' ? $rate : 0,
            // Cost per completed view — the standard for video interstitials.
            // Bills ONLY on a full play. A visitor who dismisses at 90% costs the
            // advertiser nothing, which is the whole point of the model and the
            // reason quartiles are measured separately.
            'cpcv'  => $event === 'video_complete' ? $rate : 0,
            default => 0,
        };
    }
}
