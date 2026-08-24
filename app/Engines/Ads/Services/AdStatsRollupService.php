<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AdStatsRollupService — collapse raw events into the daily table every report
 * and every invoice reads.
 *
 * IDEMPOTENT BY CONSTRUCTION
 * The rollup DELETES the target date's rows for the grain it is about to write,
 * then re-inserts from source. Re-running a date is therefore always safe and
 * always correct — which matters because you WILL re-run a date, either to fix
 * a bug or to answer a dispute.
 *
 * REVENUE IS COMPUTED HERE, ONCE
 * `ad_stats_daily.revenue_micros` is the single source of truth for both the
 * advertiser's invoice and `AdBudgetService`'s spend check. If those two read
 * different numbers, one of them is lying to somebody; reading the same column
 * means neither can.
 *
 * Billing follows the IAB/MRC display standard: CPM bills on VIEWABLE
 * impressions, not served ones. Invalid traffic is excluded from revenue but
 * retained and counted separately, so the discrepancy is visible rather than
 * quietly absorbed.
 */
final class AdStatsRollupService
{
    public function __construct(
        private readonly AdSettingsService $settings,
        private readonly AdBudgetService $budget,
    ) {
    }

    /**
     * Roll up one date.
     *
     * @return array{date: string, rows: int, impressions: int, viewable: int, clicks: int, invalid: int, revenue_micros: int}
     */
    public function rollup(?string $date = null): array
    {
        $date = $date ?? CarbonImmutable::yesterday()->toDateString();

        $grain = DB::table('ad_events')
            ->whereDate('occurred_at', $date)
            ->select('campaign_id', 'creative_id', 'website_id', 'event', 'is_invalid', DB::raw('COUNT(*) as c'))
            ->groupBy('campaign_id', 'creative_id', 'website_id', 'event', 'is_invalid')
            ->get();

        // Fold the event dimension into columns, keyed by the storage grain.
        $buckets = [];

        foreach ($grain as $row) {
            $key = implode('|', [
                $row->campaign_id ?? 'null',
                $row->creative_id ?? 'null',
                $row->website_id ?? 'null',
            ]);

            $buckets[$key] ??= [
                'campaign_id'          => $row->campaign_id,
                'creative_id'          => $row->creative_id,
                'website_id'           => $row->website_id,
                'requests'             => 0,
                'impressions'          => 0,
                'viewable_impressions' => 0,
                'clicks'               => 0,
                'invalid_impressions'  => 0,
                'video_starts'         => 0,
                'video_completes'      => 0,
                'dismissals'           => 0,
            ];

            $count = (int) $row->c;

            if ($row->is_invalid) {
                // Invalid events are counted in ONE place only, so they can be
                // reported without ever inflating a billable metric.
                if (in_array($row->event, ['impression', 'viewable'], true)) {
                    $buckets[$key]['invalid_impressions'] += $count;
                }
                continue;
            }

            match ($row->event) {
                'request'        => $buckets[$key]['requests'] += $count,
                'impression'     => $buckets[$key]['impressions'] += $count,
                'viewable'       => $buckets[$key]['viewable_impressions'] += $count,
                'click'          => $buckets[$key]['clicks'] += $count,
                'video_start'    => $buckets[$key]['video_starts'] += $count,
                'video_complete' => $buckets[$key]['video_completes'] += $count,
                'dismiss'        => $buckets[$key]['dismissals'] += $count,
                // Quartiles are recorded as raw events for the funnel view but
                // are not rolled into columns — three more columns that nothing
                // bills from would just be storage.
                default          => null,
            };
        }

        // Campaign pricing, fetched once.
        $campaignIds = array_values(array_filter(array_column($buckets, 'campaign_id')));
        $campaigns   = $campaignIds === []
            ? collect()
            : DB::table('ad_campaigns')->whereIn('id', $campaignIds)->get()->keyBy('id');

        // Idempotency: clear the date, then rewrite it.
        DB::table('ad_stats_daily')->whereDate('stat_date', $date)->delete();

        $totals = ['impressions' => 0, 'viewable' => 0, 'clicks' => 0, 'invalid' => 0, 'revenue' => 0];
        $rows   = [];

        foreach ($buckets as $bucket) {
            $revenue = 0;
            $campaign = $bucket['campaign_id'] !== null ? $campaigns->get($bucket['campaign_id']) : null;

            if ($campaign !== null) {
                $revenue += $this->budget->revenueForEvent($campaign, 'viewable') * $bucket['viewable_impressions'];
                $revenue += $this->budget->revenueForEvent($campaign, 'click') * $bucket['clicks'];
                $revenue += $this->budget->revenueForEvent($campaign, 'video_complete') * $bucket['video_completes'];
            }

            $rows[] = $bucket + [
                'stat_date'      => $date,
                'revenue_micros' => $revenue,
                'created_at'     => now(),
                'updated_at'     => now(),
            ];

            $totals['impressions'] += $bucket['impressions'];
            $totals['viewable']    += $bucket['viewable_impressions'];
            $totals['clicks']      += $bucket['clicks'];
            $totals['invalid']     += $bucket['invalid_impressions'];
            $totals['revenue']     += $revenue;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('ad_stats_daily')->insert($chunk);
        }

        return [
            'date'           => $date,
            'rows'           => count($rows),
            'impressions'    => $totals['impressions'],
            'viewable'       => $totals['viewable'],
            'clicks'         => $totals['clicks'],
            'invalid'        => $totals['invalid'],
            'revenue_micros' => $totals['revenue'],
        ];
    }

    /**
     * Delete raw events past the retention horizon. Rollups are kept forever;
     * only the high-volume raw table is pruned.
     */
    public function prune(): int
    {
        $days   = max(1, $this->settings->int(AdSettings::EVENT_RETENTION_DAYS));
        $cutoff = CarbonImmutable::now()->subDays($days);

        return DB::table('ad_events')->where('occurred_at', '<', $cutoff)->delete();
    }

    /**
     * Reconciliation: raw event counts vs the rolled-up figures for a date.
     * Rarely opened; decisive when it is, because it is the only thing that
     * proves the rollup is neither dropping nor double-counting.
     */
    public function reconcile(string $date): array
    {
        $raw = DB::table('ad_events')
            ->whereDate('occurred_at', $date)
            ->where('is_invalid', false)
            ->select('event', DB::raw('COUNT(*) as c'))
            ->groupBy('event')
            ->pluck('c', 'event')
            ->toArray();

        $rolled = DB::table('ad_stats_daily')
            ->whereDate('stat_date', $date)
            ->selectRaw('SUM(requests) r, SUM(impressions) i, SUM(viewable_impressions) v, SUM(clicks) c, SUM(invalid_impressions) x')
            ->first();

        $rawInvalid = (int) DB::table('ad_events')
            ->whereDate('occurred_at', $date)
            ->where('is_invalid', true)
            ->whereIn('event', ['impression', 'viewable'])
            ->count();

        $compare = [
            'requests'    => [(int) ($raw['request'] ?? 0),    (int) ($rolled->r ?? 0)],
            'impressions' => [(int) ($raw['impression'] ?? 0), (int) ($rolled->i ?? 0)],
            'viewable'    => [(int) ($raw['viewable'] ?? 0),   (int) ($rolled->v ?? 0)],
            'clicks'      => [(int) ($raw['click'] ?? 0),      (int) ($rolled->c ?? 0)],
            'invalid'     => [$rawInvalid,                     (int) ($rolled->x ?? 0)],
        ];

        $mismatches = [];
        foreach ($compare as $metric => [$rawCount, $rolledCount]) {
            if ($rawCount !== $rolledCount) {
                $mismatches[$metric] = ['raw' => $rawCount, 'rolled' => $rolledCount];
            }
        }

        return [
            'date'       => $date,
            'compare'    => $compare,
            'mismatches' => $mismatches,
            'balanced'   => $mismatches === [],
        ];
    }
}
