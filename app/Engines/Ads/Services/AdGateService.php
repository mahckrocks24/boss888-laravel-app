<?php

namespace App\Engines\Ads\Services;

use App\Core\Billing\FeatureGateService;
use App\Engines\Ads\Support\AdSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AdGateService — may this website show an advertisement at all?
 *
 * ══════════════════════════════════════════════════════════════════════════
 * THIS IS THE MOST SAFETY-CRITICAL CLASS IN THE ENGINE.
 * A false positive here puts an advertisement on a PAYING customer's website.
 * Every branch therefore fails CLOSED: any error, any uncertainty, any missing
 * record returns "no ads".
 * ══════════════════════════════════════════════════════════════════════════
 *
 * THE TRAP THIS CLASS EXISTS TO AVOID
 * A missing `subscriptions` row means FREE, not "no plan". FeatureGateService
 * resolves that correctly (`getActivePlan()` falls back to the free plan);
 * a hand-written SQL join would silently EXEMPT exactly those workspaces from
 * ads — the opposite of what is intended, and invisible until someone counts
 * revenue. Always go through FeatureGateService. Never join `subscriptions`.
 *
 * Cached 60s, matching the plan's "ads disappear within 60 seconds of upgrade"
 * commitment. StripeService should forget `ads:plan_free:{wsId}` on subscription
 * change to make removal near-instant; the TTL is the passive backstop.
 */
final class AdGateService
{
    private const CACHE_PREFIX = 'ads:gate:';
    private const CACHE_TTL    = 60;

    /** Decision reasons — surfaced in diagnostics and the simulate command. */
    public const ALLOW                = 'allow';
    public const DENY_MASTER_OFF      = 'master_switch_off';
    public const DENY_EXEMPT_WORKSPACE= 'workspace_exempt';
    public const DENY_PLAN_NOT_ELIGIBLE = 'plan_not_eligible';
    public const DENY_SITE_NOT_FOUND  = 'website_not_found';
    public const DENY_NOT_PUBLISHED   = 'website_not_published';
    public const DENY_FORCE_OFF       = 'site_override_force_off';
    public const DENY_ERROR           = 'gate_error';

    public function __construct(
        private readonly AdSettingsService $settings,
    ) {
    }

    /**
     * @return array{allowed: bool, reason: string, house_only: bool, workspace_id: int|null, plan_slug: string|null}
     */
    public function evaluate(int $websiteId): array
    {
        try {
            // 1. Master kill-switch. Cheapest check, and the one an operator
            //    reaches for in an emergency — so it runs first.
            if (! $this->settings->bool(AdSettings::MASTER_ENABLED)) {
                return $this->deny(self::DENY_MASTER_OFF);
            }

            $website = DB::table('websites')
                ->whereNull('deleted_at')
                ->where('id', $websiteId)
                ->first(['id', 'workspace_id', 'status', 'created_at']);

            if (! $website) {
                return $this->deny(self::DENY_SITE_NOT_FOUND);
            }

            if (($website->status ?? null) !== 'published') {
                return $this->deny(self::DENY_NOT_PUBLISHED);
            }

            $workspaceId = (int) ($website->workspace_id ?? 0);

            // 2. Hard workspace exemption. Workspace 1 is the platform's own
            //    site; it is exempt in settings AND would be exempt by plan.
            //    Belt and braces on purpose.
            if ($workspaceId <= 0
                || in_array($workspaceId, array_map('intval', $this->settings->array(AdSettings::EXEMPT_WORKSPACE_IDS)), true)) {
                return $this->deny(self::DENY_EXEMPT_WORKSPACE, $workspaceId);
            }

            // 3. Plan eligibility — via FeatureGateService, never raw SQL.
            $planSlug = $this->planSlug($workspaceId);
            $eligible = array_map('strval', $this->settings->array(AdSettings::ELIGIBLE_PLAN_SLUGS));

            // Owner directive: ads show on free sites AND on not-yet-paying TRIALS.
            // A trialing account resolves to the paid plan slug it is trialing, so
            // slug-eligibility alone would wrongly exempt it. Allow it while it is
            // trialing; the moment the trial converts to a paid/active sub the slug
            // check applies again and ads stop.
            if (! in_array($planSlug, $eligible, true) && ! $this->isTrialing($workspaceId)) {
                return $this->deny(self::DENY_PLAN_NOT_ELIGIBLE, $workspaceId, $planSlug);
            }

            // 4. Per-site override (brand safety escape hatch).
            $override = $this->siteOverride($websiteId);

            if ($override === 'force_off') {
                return $this->deny(self::DENY_FORCE_OFF, $workspaceId, $planSlug);
            }

            // 5. New-site hold: a brand-new AI-generated site serves house ads
            //    only until a human has had a chance to look at it.
            $houseOnly = $override === 'house_only' || $this->isWithinNewSiteHold($website);

            return [
                'allowed'      => true,
                'reason'       => self::ALLOW,
                'house_only'   => $houseOnly,
                'workspace_id' => $workspaceId,
                'plan_slug'    => $planSlug,
            ];
        } catch (Throwable) {
            // Fail closed. An exception must never result in an ad appearing
            // somewhere it should not.
            return $this->deny(self::DENY_ERROR);
        }
    }

    public function allows(int $websiteId): bool
    {
        return $this->evaluate($websiteId)['allowed'];
    }

    /**
     * True when the workspace has a live TRIALING subscription (not yet paying).
     * Such accounts are ad-eligible per the Owner directive even though their
     * plan slug is the paid plan they are trialing.
     */
    private function isTrialing(int $workspaceId): bool
    {
        try {
            return DB::table('subscriptions')
                ->where('workspace_id', $workspaceId)
                ->where('status', 'trialing')
                ->whereNull('cancelled_at')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Resolve the workspace's plan slug through the billing gate.
     * A workspace with NO subscription row resolves to 'free'.
     */
    public function planSlug(int $workspaceId): string
    {
        return Cache::remember(
            self::CACHE_PREFIX . "plan:{$workspaceId}",
            self::CACHE_TTL,
            function () use ($workspaceId) {
                try {
                    $plan = app(FeatureGateService::class)->getActivePlanFor($workspaceId);

                    return (string) ($plan->slug ?? 'free');
                } catch (Throwable) {
                    // Unknown plan must NOT be treated as free — that would put
                    // ads on a paying customer's site. Fail closed with a
                    // sentinel that matches no eligible list.
                    return '__unresolved__';
                }
            }
        );
    }

    /** Invalidate the cached plan for a workspace — call on subscription change. */
    public function forgetWorkspace(int $workspaceId): void
    {
        Cache::forget(self::CACHE_PREFIX . "plan:{$workspaceId}");
    }

    private function siteOverride(int $websiteId): string
    {
        try {
            $row = DB::table('ad_site_overrides')
                ->where('website_id', $websiteId)
                ->whereNull('slot_id')
                ->first(['mode']);

            return (string) ($row->mode ?? 'normal');
        } catch (Throwable) {
            // A missing override table must not open the gate wider.
            return 'normal';
        }
    }

    private function isWithinNewSiteHold(object $website): bool
    {
        $days = $this->settings->int(AdSettings::NEW_SITE_HOUSE_DAYS);

        if ($days <= 0 || empty($website->created_at)) {
            return false;
        }

        try {
            // Deliberately an explicit instant comparison rather than
            // diffInDays(): Carbon 3 (Laravel 11) returns a SIGNED difference,
            // so `now()->diffInDays($past)` is NEGATIVE and any `< $days` test
            // is true for every site ever created. That bug made every site
            // permanently house-only, which silently means no paid ad can ever
            // serve. Caught by AdDecisionServiceTest::test_paid_campaign_outranks_house.
            return \Carbon\Carbon::parse($website->created_at)->greaterThan(now()->subDays($days));
        } catch (Throwable) {
            return true; // unparseable date → be conservative, house only
        }
    }

    /** @return array{allowed: false, reason: string, house_only: bool, workspace_id: int|null, plan_slug: string|null} */
    private function deny(string $reason, ?int $workspaceId = null, ?string $planSlug = null): array
    {
        return [
            'allowed'      => false,
            'reason'       => $reason,
            'house_only'   => false,
            'workspace_id' => $workspaceId,
            'plan_slug'    => $planSlug,
        ];
    }
}
