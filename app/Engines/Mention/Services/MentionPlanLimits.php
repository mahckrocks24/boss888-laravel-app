<?php

namespace App\Engines\Mention\Services;

use App\Core\Billing\FeatureGateService;

/**
 * MentionPlanLimits — single source for per-plan caps used by the
 * Mention engine. Mirrors MentionScanDailyCommand::PLAN_DAILY_CAPS
 * for the scan-per-day side; this class adds the watchlist-term cap.
 *
 * Plans not listed default to the most conservative cap (Free).
 */
class MentionPlanLimits
{
    public const WATCHLIST_TERM_CAPS = [
        'free'      => 1,
        'starter'   => 1,
        'ai-lite'   => 1,
        'wp_bundle' => 1,
        'growth'    => 5,
        'wp_growth' => 5,
        'pro'       => 10,
        'wp_pro'    => 10,
        'agency'    => 30,
        'wp_agency' => 30,
    ];

    public function __construct(private readonly FeatureGateService $gates) {}

    public function watchlistCapFor(int $wsId): int
    {
        $plan = $this->gates->getActivePlanFor($wsId);
        $slug = $plan?->slug ?? 'free';
        return self::WATCHLIST_TERM_CAPS[$slug] ?? 1;
    }

    public function planSlugFor(int $wsId): string
    {
        $plan = $this->gates->getActivePlanFor($wsId);
        return $plan?->slug ?? 'free';
    }
}