<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\AdDeliveryService;
use App\Engines\Ads\Services\AdGateService;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Services\AdSlotInjector;
use App\Engines\Ads\Services\AdTagAssetService;
use App\Engines\Ads\Services\InventoryProfileService;
use App\Engines\Ads\Support\AdSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADS888 P0.3 — delivery logic, tested WITHOUT routes or HTTP.
 *
 * The endpoints are not wired yet; this proves the logic behind them so that
 * wiring is a thin, reviewable change rather than a leap of faith.
 */
class AdDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private AdDeliveryService $delivery;
    private AdSlotInjector $injector;
    private AdTagAssetService $assets;
    private AdSettingsService $settings;
    private int $slotId;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->delivery = app(AdDeliveryService::class);
        $this->injector = app(AdSlotInjector::class);
        $this->assets   = app(AdTagAssetService::class);
        $this->settings = app(AdSettingsService::class);
        $this->settings->flush();
        $this->settings->set(AdSettings::MASTER_ENABLED, true);

        // Under RefreshDatabase the first seeded workspace gets id 1, which is
        // in the DEFAULT exempt list — so without this every delivery test would
        // be silently testing the exemption path instead of delivery.
        // Exemption itself is covered by AdGateServiceTest::test_exempt_workspace_is_blocked.
        $this->settings->set(AdSettings::EXEMPT_WORKSPACE_IDS, [999999]);

        $this->slotId = (int) DB::table('ad_slots')->insertGetId([
            'code' => 'footer_sticky', 'name' => 'Footer sticky', 'format' => 'mobile_banner',
            'width' => 320, 'height' => 50, 'position' => 'footer_sticky', 'device' => 'all',
            'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedSite(): int
    {
        $n = ++$this->seq;

        $userId = (int) DB::table('users')->insertGetId([
            'name' => "U{$n}", 'email' => "del-{$n}-" . substr(md5((string) mt_rand()), 0, 8) . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $wsId = (int) DB::table('workspaces')->insertGetId([
            'name' => "WS{$n}", 'slug' => "del-{$n}-" . substr(md5((string) mt_rand()), 0, 8),
            'created_by' => $userId, 'industry' => 'dental clinic', 'location' => 'Dubai, UAE',
            'created_at' => now()->subDays(90), 'updated_at' => now(),
        ]);

        $siteId = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $wsId, 'name' => "S{$n}", 'subdomain' => "del-{$n}.levelupgrowth.io",
            'status' => 'published', 'created_at' => now()->subDays(90), 'updated_at' => now(),
        ]);

        app(InventoryProfileService::class)->profile($siteId);

        return $siteId;
    }

    private function seedHouseCampaign(): int
    {
        $advertiserId = (int) DB::table('advertisers')->insertGetId([
            'name' => 'House', 'status' => 'active', 'is_house' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $campaignId = (int) DB::table('ad_campaigns')->insertGetId([
            'advertiser_id' => $advertiserId, 'name' => 'House', 'kind' => 'house',
            'status' => 'active', 'pricing_model' => 'flat', 'rate_micros' => 0,
            'budget_total_micros' => null, 'priority_tier' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('ad_creatives')->insert([
            'campaign_id' => $campaignId, 'slot_id' => $this->slotId, 'type' => 'html',
            'html' => '<strong>Upgrade to remove ads</strong>',
            'click_url' => 'https://levelupgrowth.io/pricing', 'alt_text' => 'Upgrade',
            'weight' => 100, 'review_state' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $campaignId;
    }

    // ── Slot injection ──────────────────────────────────────────────────

    private function page(): string
    {
        return "<!DOCTYPE html><html><head><title>T</title></head><body>"
             . "<nav>n</nav><section>s</section><footer data-block=\"footer\">f</footer>"
             . "<script>console.log(1)</script></body></html>";
    }

    public function test_injects_slot_before_closing_body(): void
    {
        $out = $this->injector->inject($this->page(), 42);

        $this->assertStringContainsString('lu-ad-slot', $out);
        $this->assertStringContainsString('/ads.js?w=42', $out);
        $this->assertLessThan(
            strpos($out, '</body>'),
            strpos($out, 'lu-ad-slot'),
            'The slot must be inserted BEFORE </body>'
        );
    }

    public function test_injection_is_idempotent(): void
    {
        $once  = $this->injector->inject($this->page(), 42);
        $twice = $this->injector->inject($once, 42);

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, 'id="lu-ad-slot"'));
    }

    public function test_no_injection_when_master_switch_is_off(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, false);

        $page = $this->page();

        $this->assertSame($page, $this->injector->inject($page, 42), 'With ads off, not even markup is added');
    }

    public function test_preserves_original_markup_exactly(): void
    {
        $page = $this->page();
        $out  = $this->injector->inject($page, 42);

        // Everything up to </body> must be byte-identical.
        $this->assertStringStartsWith(substr($page, 0, strpos($page, '</body>')), $out);
        $this->assertStringEndsWith('</body></html>', $out);
    }

    public function test_handles_markup_without_a_body_tag(): void
    {
        $fragment = '<div>partial</div>';
        $out = $this->injector->inject($fragment, 42);

        $this->assertStringStartsWith($fragment, $out);
        $this->assertStringContainsString('lu-ad-slot', $out);
    }

    public function test_slot_reserves_height_to_avoid_layout_shift(): void
    {
        $out = $this->injector->inject($this->page(), 42);

        $this->assertStringContainsString('min-height:50px', $out);
        $this->assertStringContainsString('hidden', $out, 'The slot ships hidden and is revealed only when filled');
    }

    /** The chatbot bubble must never sit on top of a paid creative. */
    public function test_slot_reserves_space_for_the_chatbot_bubble(): void
    {
        $out = $this->injector->inject($this->page(), 42);

        $this->assertStringContainsString('padding:4px 84px', $out, 'Right-side room for the chat bubble');
        $this->assertStringContainsString('z-index:99990', $out, 'Below the chatbot (99999) so the tenant chat wins');
    }

    public function test_disclosure_label_is_present(): void
    {
        $out = $this->injector->inject($this->page(), 42);

        $this->assertStringContainsString('Sponsored', $out, 'Ad disclosure is legally required');
    }

    public function test_never_throws_on_empty_input(): void
    {
        $this->assertSame('', $this->injector->inject('', 42));
        $this->assertSame($this->page(), $this->injector->inject($this->page(), 0));
    }

    // ── Tag asset ───────────────────────────────────────────────────────

    public function test_tag_is_served_for_an_eligible_site(): void
    {
        $siteId = $this->seedSite();

        $result = $this->delivery->tag($siteId, $this->assets, app(AdGateService::class));

        $this->assertTrue($result['served']);
        $this->assertStringContainsString('IntersectionObserver', $result['js']);
        $this->assertStringContainsString('/api/ads/decide', $result['js']);
    }

    public function test_tag_returns_valid_empty_js_when_not_eligible(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, false);

        $result = $this->delivery->tag(1, $this->assets, app(AdGateService::class));

        $this->assertFalse($result['served']);
        $this->assertStringStartsWith('/*', $result['js'], 'Must be valid JS so a tenant page shows no console error');
        $this->assertStringNotContainsString('fetch(', $result['js']);
    }

    public function test_tag_stays_within_its_size_budget(): void
    {
        $siteId = $this->seedSite();
        $js = $this->delivery->tag($siteId, $this->assets, app(AdGateService::class))['js'];

        $this->assertLessThan(8192, strlen($js), 'The tag runs on someone else\'s site; keep it under 8 KB');
    }

    // ── Decide ──────────────────────────────────────────────────────────

    public function test_decide_returns_a_fill_with_a_signed_click_url(): void
    {
        $siteId = $this->seedSite();
        $this->seedHouseCampaign();

        $result = $this->delivery->decide(
            ['website_id' => $siteId, 'slots' => ['footer_sticky'], 'ctx' => ['device' => 'mobile']],
            ['ip' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 Safari', 'country' => 'AE']
        );

        $this->assertSame(200, $result['status']);
        $this->assertCount(1, $result['body']['fills']);

        $fill = $result['body']['fills'][0];
        $this->assertStringContainsString('/api/ads/click/', $fill['click_url']);
        $this->assertNotEmpty($fill['token']);
        $this->assertSame('Sponsored', $fill['label']);
    }

    public function test_decide_returns_204_when_nothing_matches(): void
    {
        $siteId = $this->seedSite();   // no campaigns at all

        $result = $this->delivery->decide(['website_id' => $siteId, 'slots' => ['footer_sticky']]);

        $this->assertSame(204, $result['status']);
        $this->assertNull($result['body']);
    }

    public function test_decide_rejects_a_malformed_payload(): void
    {
        $this->assertSame(400, $this->delivery->decide([])['status']);
        $this->assertSame(400, $this->delivery->decide(['website_id' => 0, 'slots' => ['x']])['status']);
    }

    /** A client must not be able to claim a geo an advertiser paid for. */
    public function test_visitor_country_comes_from_the_server_not_the_client(): void
    {
        $siteId = $this->seedSite();
        $this->seedHouseCampaign();

        // Client claims GB in ctx; the server-side signal says AE.
        $result = $this->delivery->decide(
            ['website_id' => $siteId, 'slots' => ['footer_sticky'], 'ctx' => ['visitor_country' => 'GB']],
            ['country' => 'AE', 'ip' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 Safari']
        );

        // The house campaign is untargeted so it fills either way; what matters
        // is that the client's claim never reached the decision context.
        $this->assertSame(200, $result['status']);
        $this->assertSame(1, DB::table('ad_events')->where('event', 'request')->count());
    }

    // ── Event beacon ────────────────────────────────────────────────────

    public function test_event_with_a_valid_token_is_accepted(): void
    {
        $siteId = $this->seedSite();
        $this->seedHouseCampaign();

        $decide = $this->delivery->decide(
            ['website_id' => $siteId, 'slots' => ['footer_sticky']],
            ['ip' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 Safari']
        );
        $token = $decide['body']['fills'][0]['token'];

        $result = $this->delivery->event(
            ['event' => 'impression', 'token' => $token],
            ['ip' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 Safari']
        );

        $this->assertSame(202, $result['status']);
        $this->assertTrue($result['body']['accepted']);
        $this->assertFalse($result['body']['invalid']);
    }

    /**
     * A FORGED signature must be rejected WITHOUT a row. Recording it would let
     * anyone inject events against any website id and pollute the very
     * invalid-traffic report we rely on as dispute evidence.
     */
    public function test_forged_token_is_rejected_without_polluting_statistics(): void
    {
        $siteId = $this->seedSite();
        $this->seedHouseCampaign();

        $decide = $this->delivery->decide(
            ['website_id' => $siteId, 'slots' => ['footer_sticky']],
            ['ip' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 Safari']
        );
        $token = $decide['body']['fills'][0]['token'];

        $forged = substr($token, 0, -4) . 'dead';   // same payload, broken signature

        $result = $this->delivery->event(['event' => 'impression', 'token' => $forged]);

        $this->assertSame(202, $result['status']);
        $this->assertFalse($result['body']['accepted']);
        $this->assertSame('bad_signature', $result['body']['reason']);

        $this->assertSame(0, DB::table('ad_events')->where('event', 'impression')->count(),
            'An unbelievable token must not create an attributable event');
    }

    /**
     * An EXPIRED token has a valid signature, so the claims are ours and the
     * event CAN be truthfully attributed. It is recorded as invalid — this is
     * what explains a gap between decisions and impressions.
     */
    public function test_expired_but_authentic_token_is_recorded_as_invalid(): void
    {
        $siteId = $this->seedSite();
        $this->seedHouseCampaign();

        $decide = $this->delivery->decide(
            ['website_id' => $siteId, 'slots' => ['footer_sticky']],
            ['ip' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 Safari']
        );
        $token = $decide['body']['fills'][0]['token'];

        $this->travel(\App\Engines\Ads\Services\AdTokenService::TTL_SECONDS + 10)->seconds();

        $result = $this->delivery->event(['event' => 'impression', 'token' => $token]);

        $this->assertSame(202, $result['status']);
        $this->assertFalse($result['body']['accepted']);
        $this->assertSame('expired', $result['body']['reason']);

        $this->assertSame(1, DB::table('ad_events')
            ->where('event', 'impression')->where('is_invalid', true)
            ->where('invalid_reason', 'token')->count());
    }

    public function test_event_rejects_unsupported_event_types(): void
    {
        $this->assertSame(400, $this->delivery->event(['event' => 'request', 'token' => 'x'])['status']);
        $this->assertSame(400, $this->delivery->event(['event' => 'nonsense', 'token' => 'x'])['status']);
    }

    // ── Click redirect ──────────────────────────────────────────────────

    public function test_click_redirects_to_the_creatives_own_url(): void
    {
        $siteId = $this->seedSite();
        $this->seedHouseCampaign();

        $decide = $this->delivery->decide(
            ['website_id' => $siteId, 'slots' => ['footer_sticky']],
            ['ip' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 Safari']
        );
        $token = $decide['body']['fills'][0]['token'];

        $result = $this->delivery->click($token, ['ip' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 Safari']);

        $this->assertSame(302, $result['status']);
        $this->assertSame('https://levelupgrowth.io/pricing', $result['url']);
        $this->assertSame(1, DB::table('ad_events')->where('event', 'click')->where('is_invalid', false)->count());
    }

    /** The endpoint must not be usable as an open redirect. */
    public function test_click_destination_never_comes_from_the_request(): void
    {
        $result = $this->delivery->click('not-a-real-token');

        $this->assertSame(400, $result['status']);
        $this->assertNull($result['url']);
    }

    public function test_click_refuses_an_unapproved_creative(): void
    {
        $siteId = $this->seedSite();
        $this->seedHouseCampaign();

        $decide = $this->delivery->decide(
            ['website_id' => $siteId, 'slots' => ['footer_sticky']],
            ['ip' => '203.0.113.5', 'user_agent' => 'Mozilla/5.0 Safari']
        );
        $token = $decide['body']['fills'][0]['token'];

        DB::table('ad_creatives')->update(['review_state' => 'rejected']);

        $result = $this->delivery->click($token);

        $this->assertSame(404, $result['status']);
        $this->assertNull($result['url']);
    }
}
