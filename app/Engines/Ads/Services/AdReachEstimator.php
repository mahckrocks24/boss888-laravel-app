<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AdReachEstimator — how much inventory actually matches a targeting spec?
 *
 * ══════════════════════════════════════════════════════════════════════════
 * THIS EXISTS TO PREVENT MIS-SELLING.
 *
 * Without it, someone sells "dental clinics in Dubai", delivers 200 impressions
 * against a booked 50,000, and that advertiser never comes back. The estimator
 * makes the inventory shortage visible AT THE POINT OF SALE, which is the only
 * place it can be handled honestly.
 *
 * It therefore REFUSES TO QUOTE below a floor. Returning "about 300 impressions"
 * for inventory that does not exist is worse than returning "not enough
 * inventory to quote" — the first loses a customer, the second starts a
 * conversation.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Only SELLABLE inventory is counted (source != unknown, confidence >= 0.75).
 * Unclassified sites can carry house ads but were never available to sell, so
 * counting them would inflate every quote.
 */
final class AdReachEstimator
{
    /** Below these, no number is quoted at all. */
    public const MIN_SITES_TO_QUOTE       = 3;
    public const MIN_IMPRESSIONS_TO_QUOTE = 10_000;

    /** Days of delivery history used to project forward. */
    private const HISTORY_DAYS = 30;

    public function __construct(
        private readonly AdTargetingMatcher $matcher,
        private readonly AdSettingsService $settings,
    ) {
    }

    /* The three thresholds below are operational settings; the class constants
       remain the shipped defaults and the fallback if settings are unreadable. */
    private function minSites(): int
    {
        return max(1, $this->settings->int(AdSettings::REACH_MIN_SITES));
    }

    private function minImpressions(): int
    {
        return max(0, $this->settings->int(AdSettings::REACH_MIN_IMPRESSIONS));
    }

    private function historyDays(): int
    {
        return max(1, $this->settings->int(AdSettings::REACH_HISTORY_DAYS));
    }

    /**
     * @param  array<int,array{dimension: string, operator?: string, values: array}>  $rules
     * @return array<string,mixed>
     */
    public function estimate(array $rules, array $context = []): array
    {
        $profiles = DB::table('ad_inventory_profiles as p')
            ->join('websites as w', 'w.id', '=', 'p.website_id')
            ->whereNull('w.deleted_at')
            ->where('w.status', 'published')
            ->get([
                'p.website_id', 'p.workspace_id', 'p.industry_slug', 'p.archetype',
                'p.iab_categories', 'p.interests', 'p.business_country', 'p.business_region',
                'p.confidence', 'p.source', 'p.quality_score', 'w.subdomain',
            ]);

        $matched      = [];
        $rejectedFor  = [];
        $unsellable   = 0;

        foreach ($profiles as $row) {
            $profile = (array) $row;

            foreach (['iab_categories', 'interests'] as $jsonKey) {
                $decoded = json_decode((string) ($profile[$jsonKey] ?? '[]'), true);
                $profile[$jsonKey] = is_array($decoded) ? $decoded : [];
            }

            // Only sellable inventory may be quoted.
            if (! IndustryClassifier::isSellableForTargeting(
                $profile['source'] ?? null,
                (float) ($profile['confidence'] ?? 0)
            )) {
                $unsellable++;
                continue;
            }

            $result = $this->matcher->match($rules, $profile, $context);

            if ($result['matched']) {
                $matched[] = $profile;
            } else {
                $key = $result['failed_on'] ?? 'unknown';
                $rejectedFor[$key] = ($rejectedFor[$key] ?? 0) + 1;
            }
        }

        $projection = $this->project(array_column($matched, 'website_id'));

        $quotable = count($matched) >= $this->minSites()
            && $projection['projected_monthly_impressions'] >= $this->minImpressions();

        return [
            'matched_sites'                  => count($matched),
            'sellable_pool'                  => $profiles->count() - $unsellable,
            'unsellable_excluded'            => $unsellable,
            'rejected_by_dimension'          => $rejectedFor,
            'history_days'                   => $this->historyDays(),
            'observed_impressions'           => $projection['observed'],
            'projected_monthly_impressions'  => $projection['projected_monthly_impressions'],
            'quotable'                       => $quotable,
            'quote_refusal_reason'           => $quotable ? null : $this->refusalReason(count($matched), $projection),
            'sites'                          => array_map(static fn ($p) => [
                'website_id' => $p['website_id'],
                'subdomain'  => $p['subdomain'],
                'industry'   => $p['industry_slug'],
                'country'    => $p['business_country'],
                'confidence' => (float) $p['confidence'],
            ], array_slice($matched, 0, 50)),
        ];
    }

    /**
     * Project forward from observed delivery. With no history, projection is
     * ZERO — never an invented per-site average. An invented number is exactly
     * what this class exists to prevent.
     *
     * @param  array<int,mixed>  $websiteIds
     */
    private function project(array $websiteIds): array
    {
        if ($websiteIds === []) {
            return ['observed' => 0, 'projected_monthly_impressions' => 0];
        }

        $from = CarbonImmutable::now()->subDays($this->historyDays())->toDateString();

        $observed = (int) DB::table('ad_stats_daily')
            ->whereIn('website_id', $websiteIds)
            ->where('stat_date', '>=', $from)
            ->sum('impressions');

        $projected = (int) round($observed * (30 / $this->historyDays()));

        return ['observed' => $observed, 'projected_monthly_impressions' => $projected];
    }

    private function refusalReason(int $sites, array $projection): string
    {
        if ($sites < $this->minSites()) {
            return sprintf(
                'Only %d matching site(s); a quote needs at least %d. Do not sell this targeting yet.',
                $sites, $this->minSites()
            );
        }

        if ($projection['observed'] === 0) {
            return 'No delivery history for the matched inventory, so no honest projection is possible. '
                . 'Serve house ads first and quote once there is measured volume.';
        }

        return sprintf(
            'Projected %s impressions/month is below the %s floor required to quote.',
            number_format($projection['projected_monthly_impressions']),
            number_format($this->minImpressions())
        );
    }
}
