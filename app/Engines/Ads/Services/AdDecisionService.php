<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdSettings;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AdDecisionService — pick the advertisement for one slot on one page view.
 *
 * THE WATERFALL
 *   1. Gate      — master switch, plan eligibility, site overrides (AdGateService)
 *   2. Slot      — active, and eligible for this site's template industry
 *   3. Candidates— approved creatives, active campaigns, in flight, in budget,
 *                  matching targeting, under frequency cap
 *   4. Rank      — priority_tier ASC (paid tier 1 beats house tier 5),
 *                  then eCPM DESC, then weighted-random within the tier
 *   5. Fallback  — house campaigns are never budget-capped and always eligible,
 *                  so fill rate is 100% and a slot is never blank
 *
 * SALEABILITY IS ENFORCED HERE, NOT ASSUMED
 * A PAID campaign may only serve on inventory whose profile is sellable
 * (source != unknown AND confidence >= 0.75) and whose quality clears the
 * configured floor. House campaigns have no such restriction — they are how
 * unclassified inventory is monetised while it waits for a human.
 *
 * NO REQUEST-PATH CODE. This class is pure decision logic over the database.
 * It is called by the delivery plane (P0.3) and, today, by `ads:simulate` —
 * which is how the whole engine is proven before a single byte is injected into
 * a customer's website.
 */
final class AdDecisionService
{
    public function __construct(
        private readonly AdGateService $gate,
        private readonly AdSettingsService $settings,
        private readonly AdTargetingMatcher $matcher,
        private readonly AdBudgetService $budget,
        private readonly InventoryProfileService $profiles,
    ) {
    }

    /**
     * Decide what to serve.
     *
     * @param  array<string,mixed>  $context  visitor_country, device, language, page_type, ip_hash
     * @return array{
     *   fill: array<string,mixed>|null,
     *   reason: string,
     *   diagnostics: array<string,mixed>
     * }
     */
    public function decide(int $websiteId, string $slotCode, array $context = []): array
    {
        $diagnostics = ['website_id' => $websiteId, 'slot' => $slotCode];

        try {
            // ── 1. Gate ─────────────────────────────────────────────────
            $gate = $this->gate->evaluate($websiteId);
            $diagnostics['gate'] = $gate;

            if (! $gate['allowed']) {
                return $this->noFill($gate['reason'], $diagnostics);
            }

            // ── 2. Slot ─────────────────────────────────────────────────
            $slot = DB::table('ad_slots')->where('code', $slotCode)->where('is_active', true)->first();

            if (! $slot) {
                return $this->noFill('slot_inactive_or_unknown', $diagnostics);
            }

            if (! $this->slotEligibleForSite($slot, $websiteId, $diagnostics)) {
                return $this->noFill('slot_not_eligible_for_template', $diagnostics);
            }

            // ── 3. Inventory profile ────────────────────────────────────
            $profile = $this->profiles->read($websiteId);

            if ($profile === null) {
                // Unprofiled inventory is not sellable, but it is still ours to
                // monetise with a house ad.
                $diagnostics['profile'] = 'missing';
                $profile = ['website_id' => $websiteId, 'confidence' => 0, 'sellable_for_targeting' => false];
            }

            $diagnostics['profile_source']   = $profile['source'] ?? 'none';
            $diagnostics['profile_sellable'] = (bool) ($profile['sellable_for_targeting'] ?? false);

            // The interstitial has its own master switch, so the footer bar can
            // go live without it.
            if (($slot->is_interstitial ?? false) && ! $this->settings->bool(AdSettings::MODAL_ENABLED)) {
                return $this->noFill('interstitial_disabled', $diagnostics);
            }

            $paidAllowed = $this->paidAllowedOn($profile, $gate, $diagnostics, $slot, $websiteId);

            // ── 4. Candidates ───────────────────────────────────────────
            $candidates = $this->candidates((int) $slot->id, $profile, $context, $paidAllowed, $diagnostics);

            if ($candidates === []) {
                if ($this->settings->string(AdSettings::NO_FILL_BEHAVIOUR) === 'blank') {
                    return $this->noFill('no_eligible_creative', $diagnostics);
                }

                return $this->noFill('no_eligible_creative', $diagnostics);
            }

            // ── 5. Rank and select ──────────────────────────────────────
            $chosen = $this->select($candidates);

            $diagnostics['candidate_count'] = count($candidates);
            $diagnostics['chosen_tier']     = $chosen['priority_tier'];

            return [
                'fill' => [
                    'slot'          => $slotCode,
                    'slot_id'       => (int) $slot->id,
                    // The CREATIVE's dimensions when it declares them, falling back
                    // to the slot's. A slot-only dimension is what made a 728x90
                    // render illegibly in a 320x50 mobile bar (§B3b).
                    'width'         => $chosen['cr_width'] ?? (int) $slot->width,
                    'height'        => $chosen['cr_height'] ?? (int) $slot->height,
                    'creative_id'   => (int) $chosen['creative_id'],
                    'campaign_id'   => (int) $chosen['campaign_id'],
                    'kind'          => $chosen['kind'],
                    'type'          => $chosen['type'],
                    'asset_url'     => $chosen['asset_url'],
                    'html'          => $chosen['html'],
                    'click_url'     => $chosen['click_url'],
                    'alt'           => $chosen['alt_text'],
                    'label'         => $this->settings->string(AdSettings::DISCLOSURE_LABEL),
                    // Interstitial fields — null/absent for a normal in-page slot.
                    'interstitial'  => (bool) ($slot->is_interstitial ?? false),
                    'aspect_ratio'  => $chosen['aspect_ratio'],
                    'media_type'    => $chosen['media_type'],
                    'video_url'     => $chosen['video_url'],
                    'video_webm_url'=> $chosen['video_webm_url'],
                    'poster_url'    => $chosen['poster_url'],
                    'duration_ms'   => $chosen['duration_ms'],
                ],
                'reason'      => 'filled',
                'diagnostics' => $diagnostics,
            ];
        } catch (Throwable $e) {
            // Fail closed and silent: a tenant page must never break because ad
            // selection threw.
            $diagnostics['error'] = $e->getMessage();

            return $this->noFill('decision_error', $diagnostics);
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * May PAID campaigns serve on this inventory?
     * House campaigns are always permitted; this only gates the paid tier.
     */
    private function paidAllowedOn(
        array $profile,
        array $gate,
        array &$diagnostics,
        ?object $slot = null,
        int $websiteId = 0,
    ): bool {
        if ($gate['house_only'] ?? false) {
            $diagnostics['paid_blocked'] = 'site_house_only_or_new';

            return false;
        }

        // A full-screen ad is far more prominent than a 50px strip, so a new
        // site is held from PAID interstitials for much longer than from paid
        // footer ads — 30 days rather than 7. House interstitials still serve.
        if (($slot->is_interstitial ?? false) && $websiteId > 0) {
            $holdDays = $this->settings->int(AdSettings::MODAL_NEW_SITE_HOLD_DAYS);
            $created  = DB::table('websites')->where('id', $websiteId)->value('created_at');

            if ($holdDays > 0 && $created !== null) {
                try {
                    if (\Carbon\Carbon::parse($created)->greaterThan(now()->subDays($holdDays))) {
                        $diagnostics['paid_blocked'] = 'interstitial_new_site_hold';

                        return false;
                    }
                } catch (Throwable) {
                    $diagnostics['paid_blocked'] = 'interstitial_new_site_hold';

                    return false;
                }
            }
        }

        if (! ($profile['sellable_for_targeting'] ?? false)) {
            $diagnostics['paid_blocked'] = 'inventory_not_sellable';

            return false;
        }

        $minQuality = $this->settings->float(AdSettings::MIN_QUALITY_FOR_PAID);

        if ((float) ($profile['quality_score'] ?? 0) < $minQuality) {
            $diagnostics['paid_blocked'] = 'quality_below_floor';

            return false;
        }

        return true;
    }

    /**
     * Approved creatives on live, in-flight, in-budget, targeting-matched
     * campaigns, under frequency cap.
     *
     * @return array<int,array<string,mixed>>
     */
    private function candidates(
        int $slotId,
        array $profile,
        array $context,
        bool $paidAllowed,
        array &$diagnostics,
    ): array {
        $now = now();

        $rows = DB::table('ad_creatives as cr')
            ->join('ad_campaigns as c', 'c.id', '=', 'cr.campaign_id')
            ->join('advertisers as a', 'a.id', '=', 'c.advertiser_id')
            ->where('cr.slot_id', $slotId)
            ->where('cr.review_state', 'approved')      // nothing unapproved ever serves
            ->where('c.status', 'active')
            ->where('a.status', 'active')
            ->where(fn ($q) => $q->whereNull('c.starts_at')->orWhere('c.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('c.ends_at')->orWhere('c.ends_at', '>=', $now))
            ->when(! $paidAllowed, fn ($q) => $q->where('c.kind', 'house'))
            ->when(
                // Video may be switched off independently of the modal itself,
                // because video is what carries the bandwidth cost.
                ! $this->settings->bool(AdSettings::MODAL_ALLOW_VIDEO),
                fn ($q) => $q->where(fn ($w) => $w->whereNull('cr.media_type')->orWhere('cr.media_type', '!=', 'video'))
            )
            ->get([
                'cr.id as creative_id', 'cr.campaign_id', 'cr.type', 'cr.asset_url', 'cr.html',
                'cr.click_url', 'cr.alt_text', 'cr.weight',
                'cr.aspect_ratio', 'cr.media_type', 'cr.video_url', 'cr.video_webm_url',
                'cr.poster_url', 'cr.duration_ms', 'cr.width as cr_width', 'cr.height as cr_height',
                'c.kind', 'c.priority_tier', 'c.pricing_model', 'c.rate_micros',
                'c.budget_total_micros', 'c.budget_daily_micros', 'c.pacing', 'c.id as cid',
            ]);

        $diagnostics['raw_candidates'] = $rows->count();
        $rejected = [];

        // Targeting rules, fetched once per campaign rather than per creative.
        $campaignIds = $rows->pluck('campaign_id')->unique()->values()->all();
        $rulesByCampaign = [];

        if ($campaignIds !== []) {
            foreach (DB::table('ad_targeting')->whereIn('campaign_id', $campaignIds)->get() as $rule) {
                $values = json_decode((string) $rule->values, true);
                $rulesByCampaign[(int) $rule->campaign_id][] = [
                    'dimension' => $rule->dimension,
                    'operator'  => $rule->operator,
                    'values'    => is_array($values) ? $values : [],
                ];
            }
        }

        $eligible = [];

        foreach ($rows as $row) {
            $campaignId = (int) $row->campaign_id;

            // Targeting
            $match = $this->matcher->match(
                $rulesByCampaign[$campaignId] ?? [],
                $profile,
                $context
            );

            if (! $match['matched']) {
                $rejected[$campaignId] = 'targeting:' . $match['failed_on'];
                continue;
            }

            // Budget + pacing
            $budget = $this->budget->check((object) [
                'id'                  => $campaignId,
                'budget_total_micros' => $row->budget_total_micros,
                'budget_daily_micros' => $row->budget_daily_micros,
                'pacing'              => $row->pacing,
            ]);

            if (! $budget['has_budget']) {
                $rejected[$campaignId] = 'budget:' . $budget['reason'];
                continue;
            }

            // Frequency cap
            if ($this->frequencyCapped($campaignId, $context)) {
                $rejected[$campaignId] = 'frequency_capped';
                continue;
            }

            $eligible[] = [
                'creative_id'   => (int) $row->creative_id,
                'campaign_id'   => $campaignId,
                'kind'          => $row->kind,
                'priority_tier' => (int) $row->priority_tier,
                'weight'        => max(1, (int) $row->weight),
                'ecpm_micros'   => $this->ecpmMicros($row),
                'type'          => $row->type,
                'asset_url'     => $row->asset_url,
                'html'          => $row->html,
                'click_url'     => $row->click_url,
                'alt_text'      => $row->alt_text,
                'aspect_ratio'  => $row->aspect_ratio,
                'media_type'    => $row->media_type ?? 'image',
                'video_url'     => $row->video_url,
                'video_webm_url'=> $row->video_webm_url,
                'poster_url'    => $row->poster_url,
                'duration_ms'   => $row->duration_ms !== null ? (int) $row->duration_ms : null,
                'cr_width'      => $row->cr_width !== null ? (int) $row->cr_width : null,
                'cr_height'     => $row->cr_height !== null ? (int) $row->cr_height : null,
                // Ratio fit is a TIE-BREAK, not a filter: an advertiser who
                // supplied only one ratio must still serve everywhere.
                'ratio_fit'     => ($row->aspect_ratio !== null
                    && $row->aspect_ratio === ($context['preferred_ratio'] ?? null)) ? 1 : 0,
            ];
        }

        $diagnostics['rejected'] = $rejected;

        return $eligible;
    }

    /**
     * Rank: priority tier first (paid beats house), then eCPM, then weighted
     * random within the winning group. Weighted random — not "first row" —
     * is what makes creative rotation and A/B tests work.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function select(array $candidates): array
    {
        // Commercial value first (tier, then eCPM); ratio fit only separates
        // creatives that are already commercially equivalent, so a better-fitting
        // ratio can never cost the publisher revenue.
        usort($candidates, static function ($a, $b) {
            return [$a['priority_tier'], -$a['ecpm_micros'], -($a['ratio_fit'] ?? 0)]
                <=> [$b['priority_tier'], -$b['ecpm_micros'], -($b['ratio_fit'] ?? 0)];
        });

        $bestTier = $candidates[0]['priority_tier'];
        $bestEcpm = $candidates[0]['ecpm_micros'];
        $bestFit  = $candidates[0]['ratio_fit'] ?? 0;

        $group = array_values(array_filter(
            $candidates,
            static fn ($c) => $c['priority_tier'] === $bestTier
                && $c['ecpm_micros'] === $bestEcpm
                && ($c['ratio_fit'] ?? 0) === $bestFit
        ));

        $totalWeight = array_sum(array_column($group, 'weight'));

        if ($totalWeight <= 0) {
            return $group[0];
        }

        $roll = random_int(1, $totalWeight);
        $acc  = 0;

        foreach ($group as $candidate) {
            $acc += $candidate['weight'];
            if ($roll <= $acc) {
                return $candidate;
            }
        }

        return $group[0];
    }

    /** Effective CPM in micros, for ranking across pricing models. */
    private function ecpmMicros(object $row): int
    {
        $rate = (int) ($row->rate_micros ?? 0);

        return match ($row->pricing_model ?? 'cpm') {
            'cpm'   => $rate,
            // A CPC campaign's eCPM depends on CTR, which we cannot know before
            // it has delivered. Assume a deliberately conservative 0.1% so a CPC
            // campaign never outranks a known-value CPM campaign on a guess.
            'cpc'   => intdiv($rate, 1000),
            default => 0,
        };
    }

    private function frequencyCapped(int $campaignId, array $context): bool
    {
        $ipHash = $context['ip_hash'] ?? null;

        if (! is_string($ipHash) || $ipHash === '') {
            return false; // no identifier → cannot cap; do not block delivery
        }

        $cap = $this->settings->int(AdSettings::FREQ_CAP_PER_IP_DAY);

        if ($cap <= 0) {
            return false;
        }

        try {
            $count = (int) DB::table('ad_frequency')
                ->where('ip_hash', $ipHash)
                ->where('campaign_id', $campaignId)
                ->whereDate('bucket_date', now()->toDateString())
                ->value('count');

            return $count >= $cap;
        } catch (Throwable) {
            return false;
        }
    }

    private function slotEligibleForSite(object $slot, int $websiteId, array &$diagnostics): bool
    {
        try {
            // Per-site, per-slot override
            $override = DB::table('ad_site_overrides')
                ->where('website_id', $websiteId)
                ->where('slot_id', $slot->id)
                ->value('mode');

            if ($override === 'force_off') {
                $diagnostics['slot_override'] = 'force_off';

                return false;
            }

            $industry = DB::table('websites')->where('id', $websiteId)->value('template_industry');

            if (! $industry) {
                return true; // no industry recorded → no per-industry exclusion applies
            }

            $row = DB::table('ad_slot_eligibility')
                ->where('slot_id', $slot->id)
                ->where('template_industry', $industry)
                ->first(['enabled']);

            return $row === null || (bool) $row->enabled;
        } catch (Throwable) {
            return false; // fail closed
        }
    }

    /** @return array{fill: null, reason: string, diagnostics: array<string,mixed>} */
    private function noFill(string $reason, array $diagnostics): array
    {
        return ['fill' => null, 'reason' => $reason, 'diagnostics' => $diagnostics];
    }
}
