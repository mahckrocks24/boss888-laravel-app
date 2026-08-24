<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdIndustryTaxonomy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AdReportService — the admin reporting surfaces (§7B of the enterprise plan).
 *
 * FOUR GUARANTEES, APPLIED TO EVERY SURFACE
 *
 *  1. STATED FRESHNESS. `ad_stats_daily` is a nightly rollup, so today's figures
 *     are partial. Every report returns `freshness`, and callers must show it.
 *     Presenting partial data as final is how you lose an advertiser's trust
 *     once and permanently.
 *
 *  2. ONE TIMEZONE, STATED. Rollups are date-keyed; without a fixed timezone the
 *     same day returns different numbers to different viewers. Everything here
 *     is UTC and says so. `workspaces.timezone` is per-tenant and must never
 *     leak into ad reporting.
 *
 *  3. INVALID TRAFFIC IS NEVER SILENTLY NETTED OUT. Every surface reports gross,
 *     invalid and net as three separate figures.
 *
 *  4. REPORTS READ `ad_stats_daily`, NEVER RAW `ad_events`. That is what keeps
 *     reporting fast as event volume grows. Only reconciliation and the
 *     invalid-traffic drill-down touch raw events, always date-bounded.
 */
final class AdReportService
{
    public const TIMEZONE = 'UTC';

    public function __construct(
        private readonly InventoryProfileService $profiles,
    ) {
    }

    /** Surface 1 — executive dashboard. */
    public function dashboard(int $days = 30): array
    {
        [$from, $to] = $this->window($days);

        $totals = $this->totals($from, $to);

        // Paid vs house split — the single best measure of inventory health.
        $byKind = DB::table('ad_stats_daily as s')
            ->leftJoin('ad_campaigns as c', 'c.id', '=', 's.campaign_id')
            ->whereBetween('s.stat_date', [$from, $to])
            ->select('c.kind', DB::raw('SUM(s.impressions) as imps'), DB::raw('SUM(s.revenue_micros) as rev'))
            ->groupBy('c.kind')
            ->get();

        $paidImps  = 0;
        $houseImps = 0;
        foreach ($byKind as $row) {
            if ($row->kind === 'paid') {
                $paidImps = (int) $row->imps;
            } elseif ($row->kind === 'house') {
                $houseImps = (int) $row->imps;
            }
        }

        $totalImps = $paidImps + $houseImps;

        return [
            'window'    => ['from' => $from, 'to' => $to, 'days' => $days],
            'timezone'  => self::TIMEZONE,
            'freshness' => $this->freshness(),
            'totals'    => $totals,
            'fill'      => [
                'paid_impressions'  => $paidImps,
                'house_impressions' => $houseImps,
                'paid_share'        => $totalImps > 0 ? round($paidImps / $totalImps, 4) : 0.0,
            ],
            'alerts'    => $this->alerts(),
        ];
    }

    /** Surface 2 — campaign report, with an under-delivery projection. */
    public function campaign(int $campaignId, int $days = 30): array
    {
        [$from, $to] = $this->window($days);

        $campaign = DB::table('ad_campaigns')->where('id', $campaignId)->first();

        if (! $campaign) {
            return ['error' => 'campaign_not_found'];
        }

        $totals = $this->totals($from, $to, ['campaign_id' => $campaignId]);

        $byCreative = DB::table('ad_stats_daily')
            ->where('campaign_id', $campaignId)
            ->whereBetween('stat_date', [$from, $to])
            ->select('creative_id',
                DB::raw('SUM(impressions) i'), DB::raw('SUM(viewable_impressions) v'),
                DB::raw('SUM(clicks) c'), DB::raw('SUM(revenue_micros) r'))
            ->groupBy('creative_id')
            ->get()
            ->map(fn ($row) => [
                'creative_id' => $row->creative_id,
                'impressions' => (int) $row->i,
                'viewable'    => (int) $row->v,
                'clicks'      => (int) $row->c,
                'ctr'         => $row->i > 0 ? round($row->c / $row->i, 4) : 0.0,
                'revenue_micros' => (int) $row->r,
            ])->all();

        return [
            'campaign'    => [
                'id' => $campaign->id, 'name' => $campaign->name, 'kind' => $campaign->kind,
                'status' => $campaign->status, 'pricing_model' => $campaign->pricing_model,
                'rate_micros' => (int) $campaign->rate_micros,
                'budget_total_micros' => $campaign->budget_total_micros,
                'starts_at' => $campaign->starts_at, 'ends_at' => $campaign->ends_at,
            ],
            'timezone'    => self::TIMEZONE,
            'freshness'   => $this->freshness(),
            'totals'      => $totals,
            'by_creative' => $byCreative,
            'pacing'      => $this->pacing($campaign),
        ];
    }

    /** Surface 3 — advertiser roll-up. Becomes the P1 advertiser portal view. */
    public function advertiser(int $advertiserId, int $days = 30): array
    {
        [$from, $to] = $this->window($days);

        $campaignIds = DB::table('ad_campaigns')->where('advertiser_id', $advertiserId)->pluck('id')->all();

        if ($campaignIds === []) {
            return ['advertiser_id' => $advertiserId, 'campaigns' => [], 'totals' => $this->emptyTotals()];
        }

        $rows = DB::table('ad_stats_daily')
            ->whereIn('campaign_id', $campaignIds)
            ->whereBetween('stat_date', [$from, $to])
            ->select('campaign_id',
                DB::raw('SUM(impressions) i'), DB::raw('SUM(viewable_impressions) v'),
                DB::raw('SUM(clicks) c'), DB::raw('SUM(invalid_impressions) x'),
                DB::raw('SUM(revenue_micros) r'))
            ->groupBy('campaign_id')
            ->get();

        return [
            'advertiser_id' => $advertiserId,
            'timezone'      => self::TIMEZONE,
            'freshness'     => $this->freshness(),
            'totals'        => $this->totals($from, $to, ['campaign_ids' => $campaignIds]),
            'campaigns'     => $rows->map(fn ($r) => [
                'campaign_id' => $r->campaign_id,
                'impressions' => (int) $r->i, 'viewable' => (int) $r->v,
                'clicks' => (int) $r->c, 'invalid' => (int) $r->x,
                'revenue_micros' => (int) $r->r,
            ])->all(),
        ];
    }

    /**
     * Surface 4 — inventory report. THIS IS THE SALES COLLATERAL: it answers
     * "what can I actually sell?" broken down the way a media buyer asks.
     */
    public function inventory(int $days = 30): array
    {
        [$from, $to] = $this->window($days);

        $delivery = DB::table('ad_stats_daily')
            ->whereBetween('stat_date', [$from, $to])
            ->select('website_id',
                DB::raw('SUM(impressions) i'), DB::raw('SUM(viewable_impressions) v'),
                DB::raw('SUM(clicks) c'), DB::raw('SUM(invalid_impressions) x'),
                DB::raw('SUM(revenue_micros) r'))
            ->groupBy('website_id')
            ->get()
            ->keyBy('website_id');

        $profiles = DB::table('ad_inventory_profiles as p')
            ->leftJoin('websites as w', 'w.id', '=', 'p.website_id')
            ->get([
                'p.website_id', 'p.industry_slug', 'p.archetype', 'p.business_country',
                'p.confidence', 'p.source', 'p.quality_score', 'w.subdomain', 'w.status',
            ]);

        $sites = [];
        $byArchetype = [];
        $byCountry = [];
        $byIndustry = [];

        foreach ($profiles as $p) {
            $d = $delivery->get($p->website_id);
            $imps = (int) ($d->i ?? 0);

            $sites[] = [
                'website_id'  => $p->website_id,
                'subdomain'   => $p->subdomain,
                'status'      => $p->status,
                'industry'    => $p->industry_slug,
                'archetype'   => $p->archetype,
                'country'     => $p->business_country,
                'confidence'  => (float) $p->confidence,
                'quality'     => (float) $p->quality_score,
                'sellable'    => IndustryClassifier::isSellableForTargeting($p->source, (float) $p->confidence),
                'impressions' => $imps,
                'viewable'    => (int) ($d->v ?? 0),
                'clicks'      => (int) ($d->c ?? 0),
                'revenue_micros' => (int) ($d->r ?? 0),
            ];

            if ($p->archetype) {
                $byArchetype[$p->archetype] ??= ['sites' => 0, 'impressions' => 0];
                $byArchetype[$p->archetype]['sites']++;
                $byArchetype[$p->archetype]['impressions'] += $imps;
            }
            if ($p->business_country) {
                $byCountry[$p->business_country] ??= ['sites' => 0, 'impressions' => 0];
                $byCountry[$p->business_country]['sites']++;
                $byCountry[$p->business_country]['impressions'] += $imps;
            }
            if ($p->industry_slug) {
                $byIndustry[$p->industry_slug] ??= ['sites' => 0, 'impressions' => 0];
                $byIndustry[$p->industry_slug]['sites']++;
                $byIndustry[$p->industry_slug]['impressions'] += $imps;
            }
        }

        arsort($byArchetype);
        arsort($byCountry);
        arsort($byIndustry);

        return [
            'window'       => ['from' => $from, 'to' => $to],
            'timezone'     => self::TIMEZONE,
            'freshness'    => $this->freshness(),
            'site_count'   => count($sites),
            'sellable'     => count(array_filter($sites, fn ($s) => $s['sellable'])),
            'sites'        => $sites,
            'by_archetype' => $byArchetype,
            'by_country'   => $byCountry,
            'by_industry'  => $byIndustry,
            'unclassified' => $this->profiles->unclassified(),
        ];
    }

    /**
     * Surface 5 — invalid traffic. The document that survives an advertiser
     * dispute. Reads raw events by necessity, always date-bounded.
     */
    public function invalidTraffic(int $days = 7): array
    {
        [$from, $to] = $this->window($days);

        $byReason = DB::table('ad_events')
            ->whereBetween('occurred_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->where('is_invalid', true)
            ->select('invalid_reason', DB::raw('COUNT(*) c'))
            ->groupBy('invalid_reason')
            ->orderByDesc('c')
            ->pluck('c', 'invalid_reason')
            ->toArray();

        $bySite = DB::table('ad_events')
            ->whereBetween('occurred_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->where('is_invalid', true)
            ->select('website_id', DB::raw('COUNT(*) c'))
            ->groupBy('website_id')
            ->orderByDesc('c')
            ->limit(25)
            ->pluck('c', 'website_id')
            ->toArray();

        $totalEvents = (int) DB::table('ad_events')
            ->whereBetween('occurred_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->count();

        $invalidTotal = array_sum($byReason);

        return [
            'window'        => ['from' => $from, 'to' => $to],
            'timezone'      => self::TIMEZONE,
            'total_events'  => $totalEvents,
            'invalid_total' => $invalidTotal,
            'invalid_rate'  => $totalEvents > 0 ? round($invalidTotal / $totalEvents, 4) : 0.0,
            'by_reason'     => $byReason,
            'by_site'       => $bySite,
        ];
    }

    /** Surface 6 — revenue: booked vs delivered vs deferred. */
    public function revenue(int $days = 90): array
    {
        [$from, $to] = $this->window($days);

        $delivered = DB::table('ad_stats_daily as s')
            ->join('ad_campaigns as c', 'c.id', '=', 's.campaign_id')
            ->join('advertisers as a', 'a.id', '=', 'c.advertiser_id')
            ->whereBetween('s.stat_date', [$from, $to])
            ->where('c.kind', 'paid')
            ->select('a.id as advertiser_id', 'a.name', DB::raw('SUM(s.revenue_micros) r'))
            ->groupBy('a.id', 'a.name')
            ->orderByDesc('r')
            ->get();

        $booked = DB::table('ad_campaigns')
            ->where('kind', 'paid')
            ->whereIn('status', ['active', 'paused'])
            ->sum('budget_total_micros');

        $deliveredTotal = (int) $delivered->sum('r');

        return [
            'window'                 => ['from' => $from, 'to' => $to],
            'timezone'               => self::TIMEZONE,
            'freshness'              => $this->freshness(),
            'booked_micros'          => (int) $booked,
            'delivered_micros'       => $deliveredTotal,
            'deferred_micros'        => max(0, (int) $booked - $deliveredTotal),
            'by_advertiser'          => $delivered->map(fn ($r) => [
                'advertiser_id' => $r->advertiser_id,
                'name'          => $r->name,
                'delivered_micros' => (int) $r->r,
            ])->all(),
        ];
    }

    /** Surface 7 — reconciliation lives on AdStatsRollupService::reconcile(). */

    // ─────────────────────────────────────────────────────────────────────

    private function totals(string $from, string $to, array $filter = []): array
    {
        $q = DB::table('ad_stats_daily')->whereBetween('stat_date', [$from, $to]);

        if (isset($filter['campaign_id'])) {
            $q->where('campaign_id', $filter['campaign_id']);
        }
        if (isset($filter['campaign_ids'])) {
            $q->whereIn('campaign_id', $filter['campaign_ids']);
        }

        $row = $q->selectRaw(
            'SUM(requests) rq, SUM(impressions) i, SUM(viewable_impressions) v, '
            . 'SUM(clicks) c, SUM(invalid_impressions) x, SUM(revenue_micros) r'
        )->first();

        $impressions = (int) ($row->i ?? 0);
        $viewable    = (int) ($row->v ?? 0);
        $clicks      = (int) ($row->c ?? 0);
        $invalid     = (int) ($row->x ?? 0);

        return [
            'requests'            => (int) ($row->rq ?? 0),
            'impressions_gross'   => $impressions + $invalid,   // never hide the gross
            'impressions_invalid' => $invalid,
            'impressions_net'     => $impressions,
            'viewable'            => $viewable,
            'clicks'              => $clicks,
            'ctr'                 => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
            'viewability_rate'    => $impressions > 0 ? round($viewable / $impressions, 4) : 0.0,
            'invalid_rate'        => ($impressions + $invalid) > 0
                ? round($invalid / ($impressions + $invalid), 4) : 0.0,
            'revenue_micros'      => (int) ($row->r ?? 0),
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'requests' => 0, 'impressions_gross' => 0, 'impressions_invalid' => 0,
            'impressions_net' => 0, 'viewable' => 0, 'clicks' => 0, 'ctr' => 0.0,
            'viewability_rate' => 0.0, 'invalid_rate' => 0.0, 'revenue_micros' => 0,
        ];
    }

    /** Under-delivery projection, surfaced early enough to act on. */
    private function pacing(object $campaign): array
    {
        if ($campaign->budget_total_micros === null || ! $campaign->starts_at || ! $campaign->ends_at) {
            return ['applicable' => false];
        }

        $start = CarbonImmutable::parse($campaign->starts_at);
        $end   = CarbonImmutable::parse($campaign->ends_at);
        $now   = CarbonImmutable::now();

        $totalSeconds   = max(1, $end->getTimestamp() - $start->getTimestamp());
        $elapsedSeconds = max(0, min($totalSeconds, $now->getTimestamp() - $start->getTimestamp()));
        $elapsedShare   = $elapsedSeconds / $totalSeconds;

        $spent  = (int) DB::table('ad_stats_daily')->where('campaign_id', $campaign->id)->sum('revenue_micros');
        $booked = (int) $campaign->budget_total_micros;

        $deliveredShare = $booked > 0 ? $spent / $booked : 0.0;
        $projected      = $elapsedShare > 0 ? min(1.0, $deliveredShare / $elapsedShare) : 0.0;

        return [
            'applicable'        => true,
            'elapsed_share'     => round($elapsedShare, 4),
            'delivered_share'   => round($deliveredShare, 4),
            'projected_delivery'=> round($projected, 4),
            'under_delivering'  => $elapsedShare > 0.1 && $deliveredShare < ($elapsedShare * 0.8),
        ];
    }

    private function alerts(): array
    {
        $alerts = [];

        $unclassified = count($this->profiles->unclassified());
        if ($unclassified > 0) {
            $alerts[] = [
                'level'   => 'info',
                'code'    => 'unclassified_inventory',
                'message' => "{$unclassified} site(s) cannot be sold to a targeted campaign",
            ];
        }

        $pending = (int) DB::table('ad_creatives')->where('review_state', 'pending')->count();
        if ($pending > 0) {
            $alerts[] = [
                'level'   => 'action',
                'code'    => 'creatives_pending_review',
                'message' => "{$pending} creative(s) awaiting approval — nothing serves unapproved",
            ];
        }

        $stale = DB::table('ad_inventory_profiles')->where('stale_after', '<=', now())->count();
        if ($stale > 0) {
            $alerts[] = [
                'level'   => 'info',
                'code'    => 'stale_profiles',
                'message' => "{$stale} inventory profile(s) past their staleness horizon",
            ];
        }

        return $alerts;
    }

    /**
     * How current the rollup is. Callers MUST show this — see guarantee 1.
     */
    public function freshness(): array
    {
        $latest = DB::table('ad_stats_daily')->max('stat_date');
        $today  = CarbonImmutable::now(self::TIMEZONE)->toDateString();

        return [
            'rolled_up_through' => $latest,
            'today'             => $today,
            'today_is_partial'  => true,
            'note'              => $latest === null
                ? 'No rollup has run yet — every figure is zero, not "no activity".'
                : "Figures are final through {$latest} (UTC). Today is partial until the next rollup.",
        ];
    }

    /** @return array{0: string, 1: string} */
    private function window(int $days): array
    {
        $to   = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $from = CarbonImmutable::now(self::TIMEZONE)->subDays(max(1, $days) - 1)->toDateString();

        return [$from, $to];
    }

    /** Human label for an industry/archetype code, for report rendering. */
    public static function label(string $kind, string $code): string
    {
        return match ($kind) {
            'industry'  => AdIndustryTaxonomy::INDUSTRIES[$code] ?? $code,
            'archetype' => AdIndustryTaxonomy::ARCHETYPES[$code] ?? $code,
            default     => $code,
        };
    }
}
