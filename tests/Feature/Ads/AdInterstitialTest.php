<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\AdCreativeValidator;
use App\Engines\Ads\Services\AdDeliveryService;
use App\Engines\Ads\Services\AdEventRecorder;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Services\AdSlotInjector;
use App\Engines\Ads\Services\AdStatsRollupService;
use App\Engines\Ads\Services\AdTagAssetService;
use App\Engines\Ads\Services\AdTokenService;
use App\Engines\Ads\Services\InventoryProfileService;
use App\Engines\Ads\Support\AdSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADS888 — session interstitial (1:1 / 3:4, image or video).
 *
 * The SEO guards and the creative spec are the load-bearing parts here: a modal
 * shown on arrival from search is a documented mobile ranking penalty on the
 * TENANT's site, and a silently-altered creative is an advertiser dispute.
 */
class AdInterstitialTest extends TestCase
{
    use RefreshDatabase;

    private AdSettingsService $settings;
    private AdCreativeValidator $validator;
    private int $seq = 0;
    private int $modalSlotId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings  = app(AdSettingsService::class);
        $this->validator = app(AdCreativeValidator::class);
        $this->settings->flush();
        $this->settings->set(AdSettings::MASTER_ENABLED, true);
        $this->settings->set(AdSettings::MODAL_ENABLED, true);
        $this->settings->set(AdSettings::EXEMPT_WORKSPACE_IDS, [999999]);

        $this->modalSlotId = (int) DB::table('ad_slots')->insertGetId([
            'code' => 'interstitial_modal', 'name' => 'Session interstitial',
            'format' => 'modal', 'width' => 1080, 'height' => 1080,
            'position' => 'modal', 'device' => 'all', 'is_active' => true,
            'allowed_ratios' => '["1:1","3:4"]', 'is_interstitial' => true,
            'sort_order' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedSite(int $ageDays = 90): int
    {
        $n = ++$this->seq;

        $userId = (int) DB::table('users')->insertGetId([
            'name' => "U{$n}", 'email' => "mod-{$n}-" . substr(md5((string) mt_rand()), 0, 8) . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $wsId = (int) DB::table('workspaces')->insertGetId([
            'name' => "WS{$n}", 'slug' => "mod-{$n}-" . substr(md5((string) mt_rand()), 0, 8),
            'created_by' => $userId, 'industry' => 'dental clinic', 'location' => 'Dubai, UAE',
            'created_at' => now()->subDays($ageDays), 'updated_at' => now(),
        ]);

        $siteId = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $wsId, 'name' => "S{$n}", 'subdomain' => "mod-{$n}.levelupgrowth.io",
            'status' => 'published', 'created_at' => now()->subDays($ageDays), 'updated_at' => now(),
        ]);

        app(InventoryProfileService::class)->profile($siteId);

        return $siteId;
    }

    private function seedCampaign(string $kind, int $tier, array $creative = []): int
    {
        $advertiserId = (int) DB::table('advertisers')->insertGetId([
            'name' => $kind . uniqid(), 'status' => 'active', 'is_house' => $kind === 'house',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $campaignId = (int) DB::table('ad_campaigns')->insertGetId([
            'advertiser_id' => $advertiserId, 'name' => $kind, 'kind' => $kind, 'status' => 'active',
            'pricing_model' => 'cpm', 'rate_micros' => 1000000,
            'budget_total_micros' => $kind === 'house' ? null : 100000000,
            'priority_tier' => $tier, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('ad_creatives')->insert(array_merge([
            'campaign_id' => $campaignId, 'slot_id' => $this->modalSlotId,
            'type' => 'image', 'media_type' => 'image',
            'asset_url' => 'https://cdn.test/a.jpg', 'click_url' => 'https://example.test',
            'alt_text' => 'ad', 'weight' => 100, 'review_state' => 'approved',
            'aspect_ratio' => '1:1', 'width' => 1080, 'height' => 1080,
            'created_at' => now(), 'updated_at' => now(),
        ], $creative));

        return $campaignId;
    }

    // ── Creative spec ───────────────────────────────────────────────────

    public function test_accepts_square_and_portrait(): void
    {
        $this->assertSame('1:1', $this->validator->detectRatio(1080, 1080));
        $this->assertSame('3:4', $this->validator->detectRatio(1080, 1440));
        $this->assertSame('4:5', $this->validator->detectRatio(1080, 1350));
    }

    public function test_rejects_an_unsupported_ratio(): void
    {
        $this->assertNull($this->validator->detectRatio(1920, 1080));

        $r = $this->validator->validate(['width' => 1920, 'height' => 1080, 'asset_url' => 'x']);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('not a supported aspect ratio', implode(' ', $r['errors']));
    }

    public function test_tolerates_rounding(): void
    {
        $this->assertSame('1:1', $this->validator->detectRatio(1080, 1081));
    }

    public function test_rejects_a_ratio_the_slot_does_not_accept(): void
    {
        $r = $this->validator->validate(
            ['width' => 1080, 'height' => 1350, 'asset_url' => 'x'],
            ['1:1', '3:4']
        );

        $this->assertFalse($r['valid'], '4:5 is valid but this slot only accepts 1:1 and 3:4');
    }

    public function test_rejects_an_asset_that_would_be_upscaled(): void
    {
        $r = $this->validator->validate(['width' => 300, 'height' => 300, 'asset_url' => 'x']);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('below the 600px minimum', implode(' ', $r['errors']));
    }

    /** The cap is a hard reject — never a silent trim. */
    public function test_rejects_video_longer_than_the_hard_cap(): void
    {
        $r = $this->validator->validate([
            'width' => 1080, 'height' => 1080, 'media_type' => 'video',
            'video_url' => 'https://cdn.test/v.mp4', 'poster_url' => 'https://cdn.test/p.jpg',
            'duration_ms' => 20000,
        ]);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('hard cap is 15s', implode(' ', $r['errors']));
        $this->assertStringContainsString('will not trim it', implode(' ', $r['errors']));
    }

    public function test_accepts_video_at_the_cap(): void
    {
        $r = $this->validator->validate([
            'width' => 1080, 'height' => 1440, 'media_type' => 'video',
            'video_url' => 'https://cdn.test/v.mp4', 'poster_url' => 'https://cdn.test/p.jpg',
            'duration_ms' => 15000, 'file_bytes' => 4000000,
        ]);

        $this->assertTrue($r['valid'], implode(' | ', $r['errors']));
        $this->assertSame('3:4', $r['ratio']);
    }

    public function test_video_requires_a_poster(): void
    {
        $r = $this->validator->validate([
            'width' => 1080, 'height' => 1080, 'media_type' => 'video',
            'video_url' => 'https://cdn.test/v.mp4', 'duration_ms' => 10000,
        ]);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('poster_url is required', implode(' ', $r['errors']));
    }

    public function test_rejects_oversized_video(): void
    {
        $r = $this->validator->validate([
            'width' => 1080, 'height' => 1080, 'media_type' => 'video',
            'video_url' => 'v', 'poster_url' => 'p', 'duration_ms' => 10000,
            'file_bytes' => 20 * 1048576,
        ]);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('bandwidth', implode(' ', $r['errors']));
    }

    // ── Ratio preference ────────────────────────────────────────────────

    public function test_portrait_viewport_prefers_portrait_creative(): void
    {
        $this->assertSame('3:4', AdCreativeValidator::preferredRatio(390, 844));
        $this->assertSame('1:1', AdCreativeValidator::preferredRatio(1280, 800));
        $this->assertSame('1:1', AdCreativeValidator::preferredRatio(null, null));
    }

    // ── Gating ──────────────────────────────────────────────────────────

    public function test_modal_switch_is_independent_of_the_platform_switch(): void
    {
        $siteId = $this->seedSite();
        $this->seedCampaign('house', 5);
        $this->settings->set(AdSettings::MODAL_ENABLED, false);

        $r = app(AdDeliveryService::class)->decide(['website_id' => $siteId, 'slots' => ['interstitial_modal']]);

        $this->assertSame(204, $r['status'], 'The footer bar can be live while the modal is not');
    }

    public function test_video_is_excluded_while_video_is_disabled(): void
    {
        $siteId = $this->seedSite();
        $this->seedCampaign('house', 5, [
            'media_type' => 'video', 'video_url' => 'https://cdn.test/v.mp4',
            'poster_url' => 'https://cdn.test/p.jpg', 'duration_ms' => 10000,
        ]);

        $this->assertFalse($this->settings->bool(AdSettings::MODAL_ALLOW_VIDEO));

        $r = app(AdDeliveryService::class)->decide(['website_id' => $siteId, 'slots' => ['interstitial_modal']]);
        $this->assertSame(204, $r['status']);

        $this->settings->set(AdSettings::MODAL_ALLOW_VIDEO, true);

        $r = app(AdDeliveryService::class)->decide(['website_id' => $siteId, 'slots' => ['interstitial_modal']]);
        $this->assertSame(200, $r['status']);
        $this->assertSame('video', $r['body']['fills'][0]['media_type']);
    }

    /** A full-screen ad is far more prominent, so the new-site hold is 30 days. */
    public function test_new_site_gets_house_interstitial_only(): void
    {
        $siteId = $this->seedSite(ageDays: 10);   // older than the 7-day footer hold
        $this->seedCampaign('house', 5);
        $this->seedCampaign('paid', 1);

        $r = app(AdDeliveryService::class)->decide(['website_id' => $siteId, 'slots' => ['interstitial_modal']]);

        $this->assertSame(200, $r['status']);
        $this->assertSame(1, DB::table('ad_events')->where('event', 'request')->count());

        $creativeId = DB::table('ad_events')->where('event', 'request')->value('creative_id');
        $campaignId = DB::table('ad_creatives')->where('id', $creativeId)->value('campaign_id');

        $this->assertSame('house', DB::table('ad_campaigns')->where('id', $campaignId)->value('kind'));
    }

    public function test_established_site_may_carry_a_paid_interstitial(): void
    {
        $siteId = $this->seedSite(ageDays: 120);
        $this->seedCampaign('house', 5);
        $this->seedCampaign('paid', 1);

        $r = app(AdDeliveryService::class)->decide(['website_id' => $siteId, 'slots' => ['interstitial_modal']]);

        $creativeId = DB::table('ad_events')->where('event', 'request')->value('creative_id');
        $campaignId = DB::table('ad_creatives')->where('id', $creativeId)->value('campaign_id');

        $this->assertSame(200, $r['status']);
        $this->assertSame('paid', DB::table('ad_campaigns')->where('id', $campaignId)->value('kind'));
    }

    public function test_fill_carries_the_interstitial_fields(): void
    {
        $siteId = $this->seedSite();
        $this->seedCampaign('house', 5, ['aspect_ratio' => '3:4', 'width' => 1080, 'height' => 1440]);

        $r = app(AdDeliveryService::class)->decide([
            'website_id' => $siteId, 'slots' => ['interstitial_modal'],
            'ctx' => ['viewport_w' => 390, 'viewport_h' => 844],
        ]);

        $fill = $r['body']['fills'][0];
        $this->assertTrue($fill['interstitial']);
        $this->assertSame('3:4', $fill['aspect_ratio']);
        $this->assertSame(1080, $fill['width']);
        $this->assertSame(1440, $fill['height'], 'Dimensions come from the creative, not the slot');
    }

    // ── Measurement ─────────────────────────────────────────────────────

    public function test_video_funnel_events_are_accepted_on_one_token(): void
    {
        $siteId = $this->seedSite();
        $this->settings->set(AdSettings::MODAL_ALLOW_VIDEO, true);
        $this->seedCampaign('house', 5, [
            'media_type' => 'video', 'video_url' => 'https://cdn.test/v.mp4',
            'poster_url' => 'https://cdn.test/p.jpg', 'duration_ms' => 10000,
        ]);

        $delivery = app(AdDeliveryService::class);
        $token = $delivery->decide(['website_id' => $siteId, 'slots' => ['interstitial_modal']])['body']['fills'][0]['token'];

        // A real browser's signals. Without a user agent the invalid-traffic
        // filter correctly marks every event invalid, which would make this test
        // pass its "accepted" assertions while proving nothing about billing.
        $signals = ['ip' => '203.0.113.9', 'user_agent' => 'Mozilla/5.0 (iPhone) Safari/604.1'];

        foreach (['impression', 'video_start', 'video_q1', 'video_q2', 'video_q3', 'video_complete', 'dismiss'] as $event) {
            $r = $delivery->event(['event' => $event, 'token' => $token], $signals);
            $this->assertSame(202, $r['status'], "event {$event} rejected");
            $this->assertTrue($r['body']['accepted'], "event {$event} not accepted");
            $this->assertFalse($r['body']['invalid'], "event {$event} was flagged invalid");
        }

        $this->assertSame(1, DB::table('ad_events')->where('event', 'video_complete')->where('is_invalid', false)->count());
    }

    /** Each billable step is still single-use, even sharing one token. */
    public function test_video_complete_cannot_be_replayed(): void
    {
        $siteId = $this->seedSite();
        $this->settings->set(AdSettings::MODAL_ALLOW_VIDEO, true);
        $this->seedCampaign('house', 5, [
            'media_type' => 'video', 'video_url' => 'v', 'poster_url' => 'p', 'duration_ms' => 5000,
        ]);

        $delivery = app(AdDeliveryService::class);
        $token = $delivery->decide(['website_id' => $siteId, 'slots' => ['interstitial_modal']])['body']['fills'][0]['token'];

        $this->assertTrue($delivery->event(['event' => 'video_complete', 'token' => $token])['body']['accepted']);
        $this->assertFalse($delivery->event(['event' => 'video_complete', 'token' => $token])['body']['accepted']);
    }

    public function test_request_event_cannot_be_injected_by_a_client(): void
    {
        $r = app(AdDeliveryService::class)->event(['event' => 'request', 'token' => 'x']);

        $this->assertSame(400, $r['status'], 'request is server-recorded only');
    }

    // ── Billing ─────────────────────────────────────────────────────────

    public function test_cpcv_bills_only_on_completion(): void
    {
        $budget = app(\App\Engines\Ads\Services\AdBudgetService::class);
        $campaign = (object) ['pricing_model' => 'cpcv', 'rate_micros' => 25000];

        $this->assertSame(25000, $budget->revenueForEvent($campaign, 'video_complete'));
        $this->assertSame(0, $budget->revenueForEvent($campaign, 'video_q3'));
        $this->assertSame(0, $budget->revenueForEvent($campaign, 'viewable'));
        $this->assertSame(0, $budget->revenueForEvent($campaign, 'impression'));
    }

    public function test_rollup_counts_video_and_dismissals(): void
    {
        $advertiserId = (int) DB::table('advertisers')->insertGetId([
            'name' => 'A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $campaignId = (int) DB::table('ad_campaigns')->insertGetId([
            'advertiser_id' => $advertiserId, 'name' => 'C', 'kind' => 'paid', 'status' => 'active',
            'pricing_model' => 'cpcv', 'rate_micros' => 20000, 'priority_tier' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $base = [
            'campaign_id' => $campaignId, 'creative_id' => 5, 'website_id' => 9,
            'workspace_id' => 2, 'slot_id' => 1, 'is_invalid' => false,
            'invalid_reason' => null, 'occurred_at' => now()->subDay(),
        ];

        $rows = [];
        for ($i = 0; $i < 100; $i++) { $rows[] = ['event' => 'video_start'] + $base; }
        for ($i = 0; $i < 40;  $i++) { $rows[] = ['event' => 'video_complete'] + $base; }
        for ($i = 0; $i < 60;  $i++) { $rows[] = ['event' => 'dismiss'] + $base; }
        DB::table('ad_events')->insert($rows);

        $result = app(AdStatsRollupService::class)->rollup(now()->subDay()->toDateString());

        $row = DB::table('ad_stats_daily')->first();
        $this->assertSame(100, (int) $row->video_starts);
        $this->assertSame(40, (int) $row->video_completes);
        $this->assertSame(60, (int) $row->dismissals);
        // 40 completions x 20000 micros
        $this->assertSame(800000, $result['revenue_micros']);
    }

    // ── Rendering ───────────────────────────────────────────────────────

    public function test_modal_css_is_injected_with_both_ratios(): void
    {
        $css = app(AdSlotInjector::class)->snippet(42, 'footer_sticky');

        $this->assertStringContainsString('lu-ad-modal-backdrop', $css);
        $this->assertStringContainsString('aspect-ratio:1/1', $css);
        $this->assertStringContainsString('aspect-ratio:3/4', $css);
        $this->assertStringContainsString('2147482000', $css, 'A blocking modal sits above the chatbot');
    }

    /**
     * The dismissal countdown.
     *
     * Gating only the close button would leave ESC and the backdrop as ways
     * around the delay — a broken contract with the advertiser and an
     * inconsistent experience. All three paths route through one `canClose()`
     * gate, and the remaining time is shown, because an inert control with no
     * explanation is a dark pattern while a stated wait is the industry norm.
     */
    public function test_close_delay_gates_every_dismissal_path(): void
    {
        $this->assertSame(6000, $this->settings->int(AdSettings::MODAL_CLOSE_DELAY_MS));

        $js = app(AdTagAssetService::class)->build(42);

        $this->assertStringContainsString('function canClose()', $js);

        // Button, ESC and backdrop must each consult the gate.
        $this->assertMatchesRegularExpression(
            '/close\.addEventListener\("click",\s*function\s*\(\)\s*\{\s*if\s*\(canClose\(\)\)/',
            $js,
            'the close control must be gated'
        );
        $this->assertMatchesRegularExpression(
            '/e\.target === wrap && canClose\(\)/',
            $js,
            'the backdrop must be gated'
        );
        $this->assertMatchesRegularExpression(
            '/e\.key === "Escape"\s*\)\s*\{\s*if\s*\(canClose\(\)\)/',
            $js,
            'ESC must be gated'
        );
    }

    public function test_countdown_is_visible_and_announced(): void
    {
        $js = app(AdTagAssetService::class)->build(42);

        // The seconds counter, then an X. Nothing else.
        $this->assertStringContainsString('lu-count', $js);
        $this->assertStringContainsString('lu-x', $js);
        $this->assertStringContainsString('closes in ', $js, 'the wait must be announced to screen readers');
        $this->assertStringContainsString('aria-disabled', $js);

        $css = app(AdSlotInjector::class)->snippet(42, 'footer_sticky');

        $this->assertStringContainsString('.lu-ad-modal-close', $css);
        $this->assertStringContainsString('data-ready', $css);
        // 44px hit area retained even though the control is visually light.
        $this->assertStringContainsString('width:44px;height:44px', $css);
    }

    /** No rings, no discs, no chrome around the close control — numbers and an X. */
    public function test_close_control_has_no_circular_ornament(): void
    {
        $js  = app(AdTagAssetService::class)->build(42);
        $css = app(AdSlotInjector::class)->snippet(42, 'footer_sticky');

        foreach (['lu-ring-track', 'lu-ring-prog', 'stroke-dashoffset', 'stroke-dasharray'] as $token) {
            $this->assertStringNotContainsString($token, $js, "JS still builds ring ornament: {$token}");
            $this->assertStringNotContainsString($token, $css, "CSS still styles ring ornament: {$token}");
        }

        $this->assertStringNotContainsString('createElementNS(SVGNS, "circle")', $js, 'no circle elements');
        $this->assertStringNotContainsString('border-radius:50%', $css, 'no circular button chrome');
    }

    public function test_zero_delay_unlocks_immediately(): void
    {
        $this->settings->set(AdSettings::MODAL_CLOSE_DELAY_MS, 0);

        $js = app(AdTagAssetService::class)->build(42);

        $this->assertStringContainsString('MODAL_CLOSE_DELAY = 0', $js);
        $this->assertStringContainsString('if (MODAL_CLOSE_DELAY <= 0)', $js);
    }

    public function test_tag_carries_the_seo_guards(): void
    {
        $js = app(AdTagAssetService::class)->build(42);

        $this->assertStringContainsString('MODAL_MIN_PV', $js);
        $this->assertStringContainsString('MODAL_NO_SEARCH', $js);
        $this->assertStringContainsString('cameFromSearch', $js);
        $this->assertStringContainsString('watchQuartiles', $js);
        $this->assertStringContainsString('closeModal', $js);
    }

    /**
     * Two budgets, because there are two tags.
     *
     * The common case is a site with the modal OFF, and that must stay tiny —
     * it is enforced separately at 8 KB by AdDeliveryTest. With the interstitial
     * enabled the tag also carries modal rendering, focus management, the
     * trigger rules and video quartile measurement, so it is allowed 16 KB.
     * For scale: a Google Publisher Tag is comfortably over 100 KB.
     */
    /**
     * Regression guard for a bug that shipped invalid JavaScript in the DEFAULT
     * configuration: stripping the modal block left the opening `/*` of its
     * comment behind, which swallowed the rest of the file as an unterminated
     * comment. Node rejected it outright; nothing in PHP noticed.
     *
     * Both variants are checked, because only the stripped one was broken.
     */
    public function test_generated_javascript_is_syntactically_balanced(): void
    {
        foreach ([true, false] as $modalEnabled) {
            $this->settings->set(AdSettings::MODAL_ENABLED, $modalEnabled);
            $js    = app(AdTagAssetService::class)->build(42);
            $label = $modalEnabled ? 'modal on' : 'modal off';

            $this->assertSame(
                substr_count($js, '/*'),
                substr_count($js, '*/'),
                "{$label}: unbalanced block comments — the tag would be unparseable"
            );
            $this->assertSame(
                substr_count($js, '{'),
                substr_count($js, '}'),
                "{$label}: unbalanced braces"
            );
            $this->assertSame(
                substr_count($js, '('),
                substr_count($js, ')'),
                "{$label}: unbalanced parentheses"
            );
            $this->assertStringNotContainsString('@@MODAL_BLOCK', $js, "{$label}: build marker leaked into the served asset");
            $this->assertStringNotContainsString('tag build error', $js, "{$label}: the build guard tripped");
        }
    }

    public function test_tag_budget_differs_by_whether_the_modal_is_enabled(): void
    {
        $withModal = strlen(app(AdTagAssetService::class)->build(42));
        $this->assertLessThan(16384, $withModal, 'Modal-enabled tag budget');

        $this->settings->set(AdSettings::MODAL_ENABLED, false);

        $withoutModal = strlen(app(AdTagAssetService::class)->build(42));

        $this->assertLessThan(8192, $withoutModal, 'A site with the modal off must not pay for modal code');
        $this->assertLessThan(
            $withModal,
            $withoutModal,
            'The modal block must be stripped at generation time, not merely skipped at runtime'
        );
    }
}
