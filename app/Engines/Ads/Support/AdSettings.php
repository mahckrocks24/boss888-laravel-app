<?php

namespace App\Engines\Ads\Support;

/**
 * AdSettings — the canonical list of operational knobs (§7.A of the plan).
 *
 * Defaults live in code so a fresh environment is safe without a seeder, and so
 * every knob is diffable in git. The `ad_settings` table only holds OVERRIDES.
 *
 * THE MOST IMPORTANT LINE IN THIS FILE IS `ads_master_enabled => false`.
 * The platform ships OFF. No advertisement can render anywhere until someone
 * deliberately turns it on — which, per the enterprise plan, must not happen
 * before the Free-plan advertising terms are in force.
 */
final class AdSettings
{
    public const MASTER_ENABLED        = 'ads_master_enabled';
    public const ELIGIBLE_PLAN_SLUGS   = 'eligible_plan_slugs';
    public const EXEMPT_WORKSPACE_IDS  = 'exempt_workspace_ids';
    public const NO_FILL_BEHAVIOUR     = 'no_fill_behaviour';
    public const DISCLOSURE_LABEL      = 'disclosure_label';
    public const FREQ_CAP_PER_IP_DAY   = 'freq_cap_per_ip_day';
    public const FREQ_CAP_PER_SESSION  = 'freq_cap_per_session';
    public const EVENT_RETENTION_DAYS  = 'event_retention_days';
    public const AD_TAG_VERSION        = 'ad_tag_version';
    public const DECIDE_TIMEOUT_MS     = 'decide_timeout_ms';
    // ── Session interstitial (modal) ────────────────────────────────────
    public const MODAL_ENABLED             = 'modal_enabled';
    public const MODAL_ALLOW_VIDEO         = 'modal_allow_video';
    public const MODAL_MIN_PAGEVIEWS       = 'modal_min_pageviews';
    public const MODAL_DWELL_MS            = 'modal_dwell_ms';
    public const MODAL_SUPPRESS_FROM_SEARCH= 'modal_suppress_from_search';
    public const MODAL_SESSION_CAP         = 'modal_session_cap';
    public const MODAL_CLOSE_DELAY_MS      = 'modal_close_delay_ms';
    public const MODAL_MAX_VIDEO_SECONDS   = 'modal_max_video_seconds';
    public const MODAL_MAX_VIDEO_BYTES     = 'modal_max_video_bytes';
    public const MODAL_SUPPRESS_FOOTER_MS  = 'modal_suppress_footer_ms';
    public const MODAL_NEW_SITE_HOLD_DAYS  = 'modal_new_site_hold_days';
    public const MODAL_EXCLUDED_PATHS      = 'modal_excluded_paths';

    // ── Commercial thresholds (were hard-coded constants) ───────────────
    public const MIN_CONFIDENCE_FOR_PAID = 'min_confidence_for_paid_targeting';
    public const REACH_MIN_SITES         = 'reach_min_sites_to_quote';
    public const REACH_MIN_IMPRESSIONS   = 'reach_min_impressions_to_quote';
    public const REACH_HISTORY_DAYS      = 'reach_history_days';
    public const PROFILE_STALE_DAYS      = 'profile_stale_after_days';
    public const TOKEN_TTL_SECONDS       = 'token_ttl_seconds';
    public const CREATIVE_MIN_SHORT_EDGE = 'creative_min_short_edge_px';

    public const REFRESH_INTERVAL_MS   = 'refresh_interval_ms';
    public const REFRESH_MAX_PER_PAGE  = 'refresh_max_per_page';
    public const CATEGORY_BLOCKLIST    = 'category_blocklist';
    public const NEW_SITE_HOUSE_DAYS   = 'new_site_house_only_days';
    public const MIN_QUALITY_FOR_PAID  = 'min_quality_score_for_paid';

    /**
     * key => [default, description]
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function definitions(): array
    {
        return [
            self::MASTER_ENABLED => [
                false,
                'Emergency kill-switch. False = zero ads platform-wide, instantly. SHIPS FALSE.',
            ],
            self::ELIGIBLE_PLAN_SLUGS => [
                ['free'],
                'Which plan slugs display ads. Future-proofs "ads on Starter too" without a deploy.',
            ],
            self::EXEMPT_WORKSPACE_IDS => [
                [1],
                'Workspaces that never show ads. Workspace 1 is the platform\'s own site.',
            ],
            self::NO_FILL_BEHAVIOUR => [
                'house',
                'What to serve when no paid campaign matches: house | blank.',
            ],
            self::DISCLOSURE_LABEL => [
                'Sponsored',
                'Visitor-facing label. Legally required — must never be blank.',
            ],
            self::FREQ_CAP_PER_IP_DAY => [
                12,
                'Max impressions of one campaign to one ip_hash per day.',
            ],
            self::FREQ_CAP_PER_SESSION => [
                3,
                'Client-side per-session cap, enforced in the tag on the tenant origin.',
            ],
            self::EVENT_RETENTION_DAYS => [
                90,
                'Raw ad_events prune horizon. Daily rollups are kept indefinitely.',
            ],
            self::AD_TAG_VERSION => [
                'v1',
                'Bumps /ads.js?v= to bust the 4h Cloudflare asset cache on deploy.',
            ],
            self::DECIDE_TIMEOUT_MS => [
                100,
                'Tag gives up and leaves the slot empty past this. Never block a tenant page.',
            ],
            // ── Session interstitial ────────────────────────────────────
            self::MODAL_ENABLED => [
                false,
                'Session interstitial master switch. Ships FALSE, independently of the '
                . 'platform switch, so the footer bar can go live without the modal.',
            ],
            self::MODAL_ALLOW_VIDEO => [
                false,
                'Allow video interstitials. Ships FALSE — video adds hosting, bandwidth '
                . 'and a new billing model; enable only once cost per completed view is modelled.',
            ],
            self::MODAL_MIN_PAGEVIEWS => [
                2,
                'Never show the modal before this many pageviews in the session. '
                . 'THIS IS AN SEO CONTROL: an interstitial on entry is what Google penalises. '
                . 'Setting it to 1 puts tenant search rankings at risk.',
            ],
            self::MODAL_DWELL_MS => [
                8000,
                'Minimum time on the current page before the modal may fire.',
            ],
            self::MODAL_SUPPRESS_FROM_SEARCH => [
                true,
                'Never show the modal when the visitor arrived from a search engine. '
                . 'THIS IS THE PRIMARY SEO GUARD — turning it off is an SEO decision, not a tuning knob.',
            ],
            self::MODAL_SESSION_CAP => [
                1,
                'Interstitials per session. 1 = "one time per session".',
            ],
            self::MODAL_CLOSE_DELAY_MS => [
                6000,
                'Delay before the interstitial can be dismissed, in ms. Gates ALL three '
                . 'paths — close control, ESC and backdrop click — so there is no way around it. '
                . 'The remaining time is shown as a depleting ring with a seconds counter: an '
                . 'inert close button with no explanation is a dark pattern, a stated wait is the '
                . 'industry norm (the same pattern as a skippable pre-roll). '
                . 'Set 0 to allow immediate dismissal. Do not raise this materially without '
                . 'legal sign-off — a hard-to-dismiss interstitial is a consumer-protection risk '
                . 'and worsens the Google intrusive-interstitial exposure described in the plan.',
            ],
            self::MODAL_MAX_VIDEO_SECONDS => [
                15,
                'Hard cap on video length. Longer uploads are REJECTED at review, never trimmed.',
            ],
            self::MODAL_MAX_VIDEO_BYTES => [
                5242880,
                'Video file ceiling (5 MB). Every completed view pays this in bandwidth.',
            ],
            self::MODAL_SUPPRESS_FOOTER_MS => [
                30000,
                'After the modal shows, suppress the footer bar for this long. '
                . 'A visitor should not get a full-screen ad and a footer bar together.',
            ],
            self::MODAL_NEW_SITE_HOLD_DAYS => [
                30,
                'New sites serve house interstitials only for this long — stricter than the '
                . 'footer bar, because a full-screen ad is far more prominent.',
            ],
            self::MODAL_EXCLUDED_PATHS => [
                ['/checkout', '/cart', '/account', '/booking', '/book'],
                'Never interrupt a conversion the tenant actually wants. Prefix match.',
            ],

            // ── Commercial thresholds ───────────────────────────────────
            self::MIN_CONFIDENCE_FOR_PAID => [
                0.75,
                'Minimum inventory confidence before a site may carry a PAID targeted campaign. '
                . 'Below this it serves house ads only. Lowering it sells advertisers inventory '
                . 'we are less sure about — 0.50 means archetype-only guesses become sellable.',
            ],
            self::REACH_MIN_SITES => [
                3,
                'ads:reach refuses to quote a targeting spec matching fewer sites than this. '
                . 'The guard exists so nobody sells "dental clinics in Dubai" against one site.',
            ],
            self::REACH_MIN_IMPRESSIONS => [
                10000,
                'ads:reach refuses to quote below this projected monthly impression count.',
            ],
            self::REACH_HISTORY_DAYS => [
                30,
                'Days of delivery history the reach estimator projects forward from.',
            ],
            self::PROFILE_STALE_DAYS => [
                30,
                'Days before an inventory profile is considered stale and re-derived by '
                . 'ads:profile-inventory --stale.',
            ],
            self::TOKEN_TTL_SECONDS => [
                300,
                'Lifetime of a signed impression token. Too short and a slow page loses legitimate '
                . 'measurement; too long and a stolen token stays replayable for longer.',
            ],
            self::CREATIVE_MIN_SHORT_EDGE => [
                600,
                'Minimum short edge (px) for an interstitial creative. Below this an asset is '
                . 'upscaled into the modal and looks soft.',
            ],

            self::REFRESH_INTERVAL_MS => [
                30000,
                'Banner rotation interval. 0 disables rotation. NEVER set below 30000 — '
                . '30s is the IAB/AdSense floor, and a faster refresh mints billable '
                . 'impressions nobody had time to see.',
            ],
            self::REFRESH_MAX_PER_PAGE => [
                10,
                'Max rotations per pageview. Stops a tab left open overnight minting '
                . 'thousands of impressions against an advertiser\'s budget.',
            ],
            self::CATEGORY_BLOCKLIST => [
                ['adult', 'gambling', 'crypto', 'pharma', 'political', 'weapons'],
                'Advertiser categories that may never serve.',
            ],
            self::NEW_SITE_HOUSE_DAYS => [
                7,
                'Sites newer than this serve house ads only, regardless of demand. '
                . 'Stops a brand-new AI-generated site carrying a paying advertiser\'s brand '
                . 'before a human has looked at it.',
            ],
            self::MIN_QUALITY_FOR_PAID => [
                0.30,
                'Minimum inventory quality_score before a paid campaign may serve on a site.',
            ],
        ];
    }

    public static function default(string $key): mixed
    {
        return self::definitions()[$key][0] ?? null;
    }

    public static function describe(string $key): ?string
    {
        return self::definitions()[$key][1] ?? null;
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    /**
     * Numbers that are DELIBERATELY not settings, with the reason.
     *
     * Surfaced by `ads:settings --fixed` so the answer to "why can't I change
     * the viewability threshold" lives next to the code rather than in someone's
     * memory.
     *
     * @return array<string,string>
     */
    public static function fixedByDesign(): array
    {
        return [
            'viewable_ratio (0.5)' =>
                'IAB/MRC display standard: >=50% of pixels. Making it tunable would let us '
                . 'redefine "viewable" and bill advertisers on a non-standard metric. That is '
                . 'not a knob, it is a dispute waiting to happen.',
            'viewable_ms (1000)' =>
                'IAB/MRC display standard: >=1 continuous second. Same reasoning as above.',
            'IndustryClassifier::CONFIDENCE_* bands' =>
                'These define what each cascade step MEANS (template-derived vs keyword-classified). '
                . 'Changing them does not tune behaviour, it makes the recorded provenance untrue. '
                . 'Use min_confidence_for_paid_targeting to change what is sellable.',
            'LocationParser::CONFIDENCE_* bands' =>
                'Same: they encode how strong each kind of location evidence is.',
            'AdSettingsService / AdGateService cache TTL (60s)' =>
                'Tied to the Cloudflare 60s edge cache on tenant HTML. Raising it would break the '
                . '"ads disappear within 60 seconds of upgrade" commitment.',
            'AdSlotInjector Z_INDEX / chat-bubble reserve' =>
                'Layout implementation detail, derived from the chatbot widget z-index (99999) '
                . 'and its bottom-right footprint.',
            'AdCreativeValidator::RATIO_TOLERANCE (1%)' =>
                'Rounding tolerance so 1080x1081 still reads as 1:1. Not a business decision.',
        ];
    }

    /**
     * Validate a proposed value. Returns an error string, or null when accepted.
     *
     * These are guardrails against changes that would quietly make the inventory
     * indefensible — not an attempt to stop an operator doing their job. Each
     * one explains itself, because a rejection with no reason just gets worked
     * around.
     */
    public static function validate(string $key, mixed $value): ?string
    {
        return match ($key) {
            self::REFRESH_INTERVAL_MS => (is_numeric($value) && (int) $value !== 0 && (int) $value < 30000)
                ? 'Below the 30s IAB/AdSense floor. A faster refresh mints billable impressions '
                  . 'nobody had time to see. Use 0 to disable rotation entirely.'
                : null,

            self::MIN_CONFIDENCE_FOR_PAID => (! is_numeric($value) || $value < 0 || $value > 1)
                ? 'Must be between 0 and 1.'
                : (((float) $value) < 0.5
                    ? 'Below 0.5 makes archetype-only guesses sellable as precise targeting. '
                      . 'An advertiser who bought "dental" would be served on sites we only know '
                      . 'are "medical-ish".'
                    : null),

            self::MODAL_CLOSE_DELAY_MS => (! is_numeric($value) || $value < 0)
                ? 'Must be 0 or greater.'
                : (((int) $value) > 15000
                    ? 'Over 15s. A hard-to-dismiss full-screen interstitial is a consumer-protection '
                      . 'risk and worsens the Google intrusive-interstitial exposure. Needs legal sign-off.'
                    : null),

            self::MODAL_MIN_PAGEVIEWS => (! is_numeric($value) || (int) $value < 1)
                ? 'Must be 1 or greater.'
                : (((int) $value) < 2
                    ? 'Setting this to 1 shows the interstitial on arrival, which is exactly the '
                      . 'pattern Google penalises on mobile. This is an SEO decision affecting TENANT '
                      . 'rankings, not a tuning knob.'
                    : null),

            self::MODAL_MAX_VIDEO_SECONDS => (! is_numeric($value) || (int) $value < 1)
                ? 'Must be 1 or greater.'
                : (((int) $value) > 30 ? 'Over 30s is not a viable interstitial length.' : null),

            self::EVENT_RETENTION_DAYS => (! is_numeric($value) || (int) $value < 7)
                ? 'At least 7 days — below that an advertiser dispute cannot be investigated.'
                : null,

            self::ELIGIBLE_PLAN_SLUGS => (! is_array($value) || $value === [])
                ? 'Must be a non-empty list of plan slugs.'
                : null,

            self::DISCLOSURE_LABEL => (! is_string($value) || trim($value) === '')
                ? 'Ad disclosure is legally required and cannot be blank.'
                : null,

            default => null,
        };
    }
}
