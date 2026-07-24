<?php

namespace App\Core\Strategy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 2026-05-24 FIX 45 — Sarah-as-DMM strategy tier framework.
 *
 * Single source of truth for the 3 intensity tiers Sarah and the SEO
 * Assistant operate under. Defines cadence, credit cost, top-up math,
 * platform multiplier, disclaimers, and the recommendation logic.
 *
 * Tier numbers locked by Shukran 2026-05-24. See:
 *   - C:\Users\User\BOSS888-Project\SARAH-TIER-FRAMEWORK-LOCKED.md
 *   - project_sarah_tier_framework memory
 *
 * DO NOT change numbers without re-approval.
 */
class StrategyTierService
{
    // ── Per-asset credit costs ────────────────────────────────────────
    public const COST_ARTICLE_CHAIN     = 3;   // write + meta + image + links + AEO bundled
    public const COST_AUTOSHARE_PER_PLATFORM = 0.5;  // caption only (image from article)
    public const COST_SOCIAL_IMAGE      = 1;   // image generation only (caption added per platform)
    public const COST_CAPTION_PER_PLATFORM = 1; // per-platform tailored caption
    public const COST_VIDEO             = 8;   // video file gen
    public const COST_EMAIL             = 1;   // subject + body
    public const COST_AUDIT             = 3;
    public const COST_STRATEGY_MEETING  = 8;
    public const COST_RETARGETING_REFRESH = 6;

    // ── Tier cadence definitions (locked) ────────────────────────────
    public const TIER_NORMAL            = 'normal';
    public const TIER_AGGRESSIVE        = 'aggressive';
    public const TIER_SUPER_AGGRESSIVE  = 'super_aggressive';

    public const DEFAULT_PLATFORM_COUNT = 2;  // typical SMB has 2 social channels

    /**
     * Returns full tier definition map: cadence per channel, expected
     * outcome timeline, and disclaimers specific to each tier.
     */
    public static function getTiers(): array
    {
        return [
            self::TIER_NORMAL => [
                'slug' => self::TIER_NORMAL,
                'name' => 'Normal',
                'tagline' => 'Steady professional baseline',
                'description' => 'Consumes the full Growth $99 / 300cr allowance. Sustainable; builds compound SEO authority over months.',
                'cadence' => [
                    'articles_per_month'       => 24,
                    'standalone_socials_per_month' => 12,
                    'videos_per_month'         => 4,
                    'emails_per_month'         => 8,
                    'audits_per_month'         => 4,
                    'strategy_meetings_per_month' => 2,
                    'retargeting_campaigns_per_month' => 0,
                ],
                'expected_outcome' => '6-12 months to top-10 on moderate keywords. Compound effect kicks in month 4+.',
                'risks' => [
                    'Long timeline — Google rewards consistency over volume; results compound slowly.',
                ],
            ],
            self::TIER_AGGRESSIVE => [
                'slug' => self::TIER_AGGRESSIVE,
                'name' => 'Aggressive',
                'tagline' => 'Active growth campaign',
                'description' => 'For users targeting visible rank gains within one quarter. Fits Pro $199; Growth users need a +300cr top-up.',
                'cadence' => [
                    'articles_per_month'       => 50,
                    'standalone_socials_per_month' => 30,
                    'videos_per_month'         => 8,
                    'emails_per_month'         => 12,
                    'audits_per_month'         => 8,
                    'strategy_meetings_per_month' => 4,
                    'retargeting_campaigns_per_month' => 4,
                ],
                'expected_outcome' => '3-6 months for top-10 on moderate keywords. Faster topic-authority accumulation.',
                'risks' => [
                    'Article volume past 12/wk can dilute topic authority — quality must hold.',
                    'Social cadence approaching 1/day per platform — watch engagement signals.',
                ],
            ],
            self::TIER_SUPER_AGGRESSIVE => [
                'slug' => self::TIER_SUPER_AGGRESSIVE,
                'name' => 'Super Aggressive',
                'tagline' => 'Agency-equivalent push',
                'description' => 'Pro / Agency tier territory. Growth users would need a 1,200cr top-up — recommend upgrade instead.',
                'cadence' => [
                    'articles_per_month'       => 90,
                    'standalone_socials_per_month' => 60,
                    'videos_per_month'         => 16,
                    'emails_per_month'         => 20,
                    'audits_per_month'         => 30,
                    'strategy_meetings_per_month' => 8,
                    'retargeting_campaigns_per_month' => 8,
                ],
                'expected_outcome' => '2-4 months for top-10 if quality holds. Risk: brand fatigue, algorithm dampening.',
                'risks' => [
                    'LinkedIn reduces per-post reach by 40-60% beyond 2/day — total reach often DROPS at this volume.',
                    'Email at 5/wk: open rates drop ~12-15% vs 2/wk; unsubscribe rate triples.',
                    'Per-article LLM authority score drops 8-15% at daily article cadence.',
                    'Soft note: Meta (FB/IG) algorithm rewards stickiness — high posting volume CAN help on Meta. Trade-off varies by platform.',
                ],
            ],
        ];
    }

    /**
     * Compute estimated monthly credit burn for a tier, given the number
     * of connected social platforms (default 2 — typical SMB).
     */
    public static function computeMonthlyBurn(string $tier, int $platformCount = self::DEFAULT_PLATFORM_COUNT): int
    {
        $tiers = self::getTiers();
        if (!isset($tiers[$tier])) {
            return 0;
        }
        $c = $tiers[$tier]['cadence'];
        $plats = max(1, $platformCount);

        $articles      = $c['articles_per_month']               * self::COST_ARTICLE_CHAIN;
        $autoShares    = $c['articles_per_month']               * $plats * self::COST_AUTOSHARE_PER_PLATFORM;
        $socials       = $c['standalone_socials_per_month']     * (self::COST_SOCIAL_IMAGE + ($plats * self::COST_CAPTION_PER_PLATFORM));
        $videos        = $c['videos_per_month']                 * (self::COST_VIDEO + ($plats * self::COST_CAPTION_PER_PLATFORM));
        $emails        = $c['emails_per_month']                 * self::COST_EMAIL;
        $audits        = $c['audits_per_month']                 * self::COST_AUDIT;
        $meetings      = $c['strategy_meetings_per_month']      * self::COST_STRATEGY_MEETING;
        $retargeting   = $c['retargeting_campaigns_per_month']  * self::COST_RETARGETING_REFRESH;

        $subtotal = $articles + $autoShares + $socials + $videos + $emails + $audits + $meetings + $retargeting;

        // Add a buffer for ad-hoc work (SERP checks, chat, mid-month
        // adjustments). Buffer scales with tier so Super Aggressive
        // (a lot of moving parts) gets bigger headroom.
        $buffer = (int) round($subtotal * 0.30);

        return (int) round($subtotal + $buffer);
    }

    /**
     * Compute the cost line-items for display in Sarah's proposal narration.
     */
    public static function computeBurnBreakdown(string $tier, int $platformCount = self::DEFAULT_PLATFORM_COUNT): array
    {
        $tiers = self::getTiers();
        if (!isset($tiers[$tier])) return [];
        $c = $tiers[$tier]['cadence'];
        $plats = max(1, $platformCount);
        return [
            'articles' => [
                'volume' => $c['articles_per_month'],
                'cost'   => $c['articles_per_month'] * self::COST_ARTICLE_CHAIN,
            ],
            'auto_shares' => [
                'volume' => $c['articles_per_month'] . " x {$plats} platforms",
                'cost'   => $c['articles_per_month'] * $plats * self::COST_AUTOSHARE_PER_PLATFORM,
            ],
            'standalone_socials' => [
                'volume' => $c['standalone_socials_per_month'],
                'cost'   => $c['standalone_socials_per_month'] * (self::COST_SOCIAL_IMAGE + ($plats * self::COST_CAPTION_PER_PLATFORM)),
            ],
            'videos' => [
                'volume' => $c['videos_per_month'],
                'cost'   => $c['videos_per_month'] * (self::COST_VIDEO + ($plats * self::COST_CAPTION_PER_PLATFORM)),
            ],
            'emails' => [
                'volume' => $c['emails_per_month'],
                'cost'   => $c['emails_per_month'] * self::COST_EMAIL,
            ],
            'audits_and_meetings' => [
                'volume' => $c['audits_per_month'] . ' audits + ' . $c['strategy_meetings_per_month'] . ' meetings',
                'cost'   => $c['audits_per_month'] * self::COST_AUDIT + $c['strategy_meetings_per_month'] * self::COST_STRATEGY_MEETING,
            ],
            'retargeting' => [
                'volume' => $c['retargeting_campaigns_per_month'],
                'cost'   => $c['retargeting_campaigns_per_month'] * self::COST_RETARGETING_REFRESH,
            ],
        ];
    }

    /**
     * Recommend a tier for a workspace based on their plan's credit
     * allowance and the goal ambition. Returns the recommended tier
     * plus reasoning and upgrade options if the recommended tier
     * doesn't fit the current plan.
     *
     * Goal ambition: 'low' (general presence) | 'medium' (steady growth) | 'high' (aggressive ranking push)
     */
    public static function recommendTier(int $planCreditLimit, string $goalAmbition = 'medium', int $platformCount = self::DEFAULT_PLATFORM_COUNT): array
    {
        $tiers = self::getTiers();
        $tierKeys = array_keys($tiers);

        // Compute burn for each tier
        $burns = [];
        foreach ($tierKeys as $k) {
            $burns[$k] = self::computeMonthlyBurn($k, $platformCount);
        }

        // The user has X credits. We want to recommend the tier where
        // burn <= 0.85 * plan_limit (15% safety margin for ad-hoc work
        // beyond the tier's own buffer).
        $safeBudget = (int) round($planCreditLimit * 0.85);

        $fitTier = self::TIER_NORMAL;
        foreach ([self::TIER_SUPER_AGGRESSIVE, self::TIER_AGGRESSIVE, self::TIER_NORMAL] as $t) {
            if ($burns[$t] <= $safeBudget) {
                $fitTier = $t;
                break;
            }
        }

        // Adjust by ambition — if user expressed high ambition AND a higher
        // tier is within reach of a single top-up (less than +500cr), push them
        // toward the higher tier.
        if ($goalAmbition === 'high') {
            $idx = array_search($fitTier, $tierKeys, true);
            if ($idx !== false && isset($tierKeys[$idx + 1])) {
                $next = $tierKeys[$idx + 1];
                $shortfall = $burns[$next] - $planCreditLimit;
                if ($shortfall <= 500) {
                    $fitTier = $next;
                }
            }
        } elseif ($goalAmbition === 'low' && $fitTier !== self::TIER_NORMAL) {
            $fitTier = self::TIER_NORMAL;
        }

        $recommendedBurn = $burns[$fitTier];
        $fits = $recommendedBurn <= $planCreditLimit;

        return [
            'recommended_tier'    => $fitTier,
            'recommended_burn'    => $recommendedBurn,
            'plan_credit_limit'   => $planCreditLimit,
            'fits_plan'           => $fits,
            'safety_margin_used'  => $planCreditLimit > 0 ? round(($recommendedBurn / $planCreditLimit) * 100) : 0,
            'all_burns'           => $burns,
            'reasoning'           => self::buildRecommendationReason($fitTier, $goalAmbition, $planCreditLimit, $recommendedBurn),
        ];
    }

    private static function buildRecommendationReason(string $tier, string $ambition, int $planLimit, int $burn): string
    {
        $tierName = self::getTiers()[$tier]['name'];
        $pct = $planLimit > 0 ? round(($burn / $planLimit) * 100) : 0;
        $reason = "Recommending **{$tierName}** for your goal — ";
        $reason .= "estimated {$burn}cr/month against your {$planLimit}cr plan allowance ({$pct}% utilization)";
        if ($pct < 70) {
            $reason .= ", leaving healthy headroom for ad-hoc work.";
        } elseif ($pct <= 85) {
            $reason .= ", which uses your allowance fully but stays within budget.";
        } else {
            $reason .= " — this will require either a top-up or upgrade to sustain.";
        }
        return $reason;
    }

    /**
     * Compute the top-up needed to sustain a tier on a given plan.
     * Returns shortfall in credits + an upgrade alternative.
     */
    public static function computeTopUpNeeded(string $tier, int $planCreditLimit, int $platformCount = self::DEFAULT_PLATFORM_COUNT): array
    {
        $burn = self::computeMonthlyBurn($tier, $platformCount);
        $shortfall = max(0, $burn - $planCreditLimit);
        return [
            'tier'              => $tier,
            'monthly_burn'      => $burn,
            'plan_credit_limit' => $planCreditLimit,
            'credits_short'     => $shortfall,
            'requires_top_up'   => $shortfall > 0,
        ];
    }

    /**
     * Read the workspace's active strategy from workspace_memory.
     * Returns default Normal if not set.
     */
    public static function getActiveStrategy(int $wsId): array
    {
        $tier = self::readMemKey($wsId, 'strategy_tier', self::TIER_NORMAL);
        $platforms = (int) self::readMemKey($wsId, 'connected_platforms_count', self::DEFAULT_PLATFORM_COUNT);
        $tiers = self::getTiers();
        $tierData = $tiers[$tier] ?? $tiers[self::TIER_NORMAL];

        // Apply per-workspace overrides if user customized cadence.
        $cadence = $tierData['cadence'];
        foreach (['articles', 'standalone_socials', 'videos', 'emails'] as $field) {
            $override = self::readMemKey($wsId, "cadence_{$field}_per_month", null);
            if ($override !== null) $cadence["{$field}_per_month"] = (int) $override;
        }

        return [
            'tier'                     => $tier,
            'tier_name'                => $tierData['name'],
            'cadence'                  => $cadence,
            'connected_platforms'      => $platforms,
            'estimated_monthly_cost'   => self::computeMonthlyBurn($tier, $platforms),
            'locked_at'                => self::readMemKey($wsId, 'strategy_locked_at', null),
        ];
    }

    /**
     * Persist a tier choice for a workspace.
     */
    public static function setActiveStrategy(int $wsId, string $tier, array $cadenceOverrides = [], ?int $platformCount = null): void
    {
        if (!array_key_exists($tier, self::getTiers())) {
            throw new \InvalidArgumentException("Unknown tier: {$tier}");
        }
        self::writeMemKey($wsId, 'strategy_tier', $tier);
        if ($platformCount !== null) {
            self::writeMemKey($wsId, 'connected_platforms_count', (int) $platformCount);
        }
        foreach ($cadenceOverrides as $field => $value) {
            if (str_starts_with($field, 'cadence_') && is_numeric($value)) {
                self::writeMemKey($wsId, $field, (int) $value);
            }
        }
        self::writeMemKey($wsId, 'strategy_locked_at', now()->toIso8601String());

        Log::info('[StrategyTier] active strategy locked', [
            'workspace_id' => $wsId, 'tier' => $tier,
            'overrides' => $cadenceOverrides, 'platforms' => $platformCount,
        ]);
    }

    /**
     * Build the system-prompt block for Sarah and the SEO Assistant.
     * Inject this into both prompts so the LLM operates with the
     * framework in context.
     */
    public static function buildPromptBlock(int $wsId, int $planCreditLimit): string
    {
        $active = self::getActiveStrategy($wsId);
        $tiers = self::getTiers();
        $platforms = $active['connected_platforms'];

        $p = "════ STRATEGY TIER FRAMEWORK (LOCKED — never invent different numbers) ════\n";
        $p .= "Three intensity tiers. User picks one; you respect cadence and quote real credit costs.\n\n";

        $p .= "CURRENT WORKSPACE TIER: **{$active['tier_name']}** (locked: " . ($active['locked_at'] ?: 'not yet locked') . ")\n";
        $p .= "Connected social platforms: {$platforms}\n";
        $p .= "Plan credit allowance: {$planCreditLimit}cr/month\n\n";

        $p .= "TIER DEFINITIONS (per-month cadence and credit burn at {$platforms} platforms):\n";
        foreach ($tiers as $key => $t) {
            $burn = self::computeMonthlyBurn($key, $platforms);
            $marker = ($key === $active['tier']) ? ' (CURRENT)' : '';
            $p .= "  {$t['name']}{$marker}: {$t['cadence']['articles_per_month']} articles, "
                . "{$t['cadence']['standalone_socials_per_month']} standalone socials, "
                . "{$t['cadence']['videos_per_month']} videos, "
                . "{$t['cadence']['emails_per_month']} emails => ~{$burn}cr/mo. "
                . "Expected: {$t['expected_outcome']}\n";
        }
        $p .= "\nPER-ASSET COSTS (use these for any credit math):\n";
        $p .= "  - Article (full chain): 3cr bundled\n";
        $p .= "  - Auto-share to socials: 0.5cr per connected platform (caption only)\n";
        $p .= "  - Standalone social: 1cr image + 1cr per platform caption\n";
        $p .= "  - Video post: 8cr video + 1cr per platform caption\n";
        $p .= "  - Email: 1cr. Audit: 3cr. Strategy meeting: 8cr. Retargeting refresh: 6cr.\n";

        $p .= "\nRECOMMENDATION RULES when user expresses an ambitious goal:\n";
        $p .= "  1. Quote realistic TIMELINE (Normal 6-12mo, Aggressive 3-6mo, Super Aggressive 2-4mo) — never promise faster.\n";
        $p .= "  2. Recommend the tier that fits the user's plan with >=15% headroom (use computeMonthlyBurn against plan_credit_limit).\n";
        $p .= "  3. If user wants a higher tier than plan supports, offer BOTH options transparently: top-up (per-credit cost) vs upgrade (next plan). State which is better value at sustained use.\n";
        $p .= "  4. ASK the user for cadence overrides within the chosen tier — they may want 2/wk articles instead of the default 6/wk.\n";
        $p .= "  5. Quote ALL hard disclaimers below before locking the tier.\n";

        $p .= "\nHARD DISCLAIMERS (quote when proposing Aggressive or Super Aggressive):\n";
        $p .= "  - Article volume diminishing returns: past 4-5/wk Google authority dilutes\n";
        $p .= "  - Social cadence ceiling: LinkedIn penalizes >2/day with reduced reach; total reach often DROPS\n";
        $p .= "  - Email frequency erosion: open rate drops ~3% per extra weekly send beyond 2/wk; >3/wk triples unsubs\n";
        $p .= "  - SEO timeline reality: a burst of 30 articles in week 1 does NOT compress 6mo timeline to 1mo\n";
        $p .= "  - Quality at high volume: per-article authority score drops 8-15% at 1 article/day\n";
        $p .= "  - Credit burn: Super Aggressive on Pro burns through 67% of monthly credits\n";
        $p .= "  - Soft trade-off: Meta (FB/IG) algorithm rewards stickiness — high posting can HELP on Meta specifically\n";

        $p .= "\nRULE: Never invent tier numbers different from above. Never quote credit costs not in the per-asset list.\n";

        return $p;
    }

    // ── Memory helpers (workspace_memory Redis-backed) ────────────────

    private static function memKey(int $wsId): string
    {
        return "seo_ws_memory_{$wsId}";
    }

    private static function readMemKey(int $wsId, string $key, $default = null)
    {
        // Fast path: Redis hash.
        try {
            $raw = Redis::get(self::memKey($wsId));
            if ($raw) {
                $mem = json_decode($raw, true);
                if (is_array($mem) && array_key_exists($key, $mem)) {
                    return $mem[$key];
                }
            }
        } catch (\Throwable $e) {
            // fall through to durable store
        }

        // Durable fallback (2026-07-06): tier + strategy MUST survive a Redis
        // flush/TTL-expiry. Before this, a rebuilt/flushed Redis silently reverted
        // every workspace to the 'normal' default (root cause of ws2 reporting
        // Normal after the owner picked Aggressive). MySQL workspace_memory is
        // the source of truth; we rehydrate Redis on read.
        try {
            $row = DB::table('workspace_memory')
                ->where('workspace_id', $wsId)
                ->where('key', $key)
                ->value('value_json');
            if ($row !== null) {
                $val = json_decode($row, true);
                self::rehydrateRedis($wsId, $key, $val);
                return $val;
            }
        } catch (\Throwable $e) {
            // ignore — return default
        }

        return $default;
    }

    private static function writeMemKey(int $wsId, string $key, $value): void
    {
        // Redis (fast read cache, 90-day TTL).
        try {
            $raw = Redis::get(self::memKey($wsId));
            $mem = $raw ? (json_decode($raw, true) ?: []) : [];
            $mem[$key] = $value;
            Redis::setex(self::memKey($wsId), 90 * 86400, json_encode($mem));
        } catch (\Throwable $e) {
            Log::warning('[StrategyTier] writeMemKey redis failed: ' . $e->getMessage());
        }

        // Durable MySQL upsert (survives Redis flush).
        try {
            DB::table('workspace_memory')->updateOrInsert(
                ['workspace_id' => $wsId, 'key' => $key],
                ['value_json' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]
            );
        } catch (\Throwable $e) {
            Log::warning('[StrategyTier] writeMemKey durable failed: ' . $e->getMessage());
        }
    }

    private static function rehydrateRedis(int $wsId, string $key, $value): void
    {
        try {
            $raw = Redis::get(self::memKey($wsId));
            $mem = $raw ? (json_decode($raw, true) ?: []) : [];
            $mem[$key] = $value;
            Redis::setex(self::memKey($wsId), 90 * 86400, json_encode($mem));
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
