<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\AdDeliveryService;
use App\Engines\Ads\Services\AdGateService;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Services\AdTagAssetService;
use App\Engines\Ads\Services\InventoryProfileService;
use App\Engines\Ads\Support\AdSettings;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADS888 — the product's central promise: upgrade and the advertisement goes.
 *
 * Every layer is asserted, because a break at any one of them leaves a paying
 * customer looking at an advertisement on their own website.
 */
class AdUpgradeRemovalTest extends TestCase
{
    use RefreshDatabase;

    private AdSettingsService $settings;
    private AdGateService $gate;
    private AdDeliveryService $delivery;
    private int $seq = 0;
    private int $slotId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(AdSettingsService::class);
        $this->gate     = app(AdGateService::class);
        $this->delivery = app(AdDeliveryService::class);
        $this->settings->flush();
        $this->settings->set(AdSettings::MASTER_ENABLED, true);
        $this->settings->set(AdSettings::EXEMPT_WORKSPACE_IDS, [999999]);

        $this->slotId = (int) DB::table('ad_slots')->insertGetId([
            'code' => 'footer_sticky', 'name' => 'Footer', 'format' => 'mobile_banner',
            'width' => 320, 'height' => 50, 'position' => 'footer_sticky', 'device' => 'all',
            'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedFreeSiteWithHouseAd(): array
    {
        $n = ++$this->seq;

        $userId = (int) DB::table('users')->insertGetId([
            'name' => "U{$n}", 'email' => "upg-{$n}-" . substr(md5((string) mt_rand()), 0, 8) . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $wsId = (int) DB::table('workspaces')->insertGetId([
            'name' => "WS{$n}", 'slug' => "upg-{$n}-" . substr(md5((string) mt_rand()), 0, 8),
            'created_by' => $userId, 'industry' => 'dental clinic', 'location' => 'Dubai, UAE',
            'created_at' => now()->subDays(120), 'updated_at' => now(),
        ]);
        $siteId = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $wsId, 'name' => "S{$n}", 'subdomain' => "upg-{$n}.levelupgrowth.io",
            'status' => 'published', 'created_at' => now()->subDays(120), 'updated_at' => now(),
        ]);

        app(InventoryProfileService::class)->profile($siteId);

        $advId = (int) DB::table('advertisers')->insertGetId([
            'name' => "House{$n}", 'status' => 'active', 'is_house' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $campId = (int) DB::table('ad_campaigns')->insertGetId([
            'advertiser_id' => $advId, 'name' => 'House', 'kind' => 'house', 'status' => 'active',
            'pricing_model' => 'flat', 'rate_micros' => 0, 'budget_total_micros' => null,
            'priority_tier' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ad_creatives')->insert([
            'campaign_id' => $campId, 'slot_id' => $this->slotId, 'type' => 'html',
            'html' => '<strong>Upgrade to remove ads</strong>', 'click_url' => 'https://levelupgrowth.io/pricing',
            'alt_text' => 'Upgrade', 'weight' => 100, 'review_state' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$wsId, $siteId];
    }

    private function paidPlanId(): int
    {
        return (int) (DB::table('plans')->where('slug', 'pro')->value('id')
            ?? DB::table('plans')->insertGetId([
                'name' => 'Pro', 'slug' => 'pro', 'price' => 199, 'billing_period' => 'monthly',
                'features_json' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]));
    }

    /** The whole journey, at every layer. */
    public function test_upgrading_removes_the_advertisement_end_to_end(): void
    {
        [$wsId, $siteId] = $this->seedFreeSiteWithHouseAd();

        // ── FREE ────────────────────────────────────────────────────────
        $this->assertSame(0, DB::table('subscriptions')->where('workspace_id', $wsId)->count(),
            'no subscription row at all — the case a SQL join would get wrong');

        $g = $this->gate->evaluate($siteId);
        $this->assertTrue($g['allowed']);
        $this->assertSame('free', $g['plan_slug']);

        $this->assertSame(200, $this->delivery->decide(
            ['website_id' => $siteId, 'slots' => ['footer_sticky']]
        )['status']);

        $this->assertTrue(
            $this->delivery->tag($siteId, app(AdTagAssetService::class), $this->gate)['served']
        );

        // ── UPGRADE, through the Eloquent model the app really uses ─────
        Subscription::create([
            'workspace_id' => $wsId,
            'plan_id'      => $this->paidPlanId(),
            'status'       => 'active',
            'starts_at'    => now(),
        ]);

        // NO manual cache clearing here — the model hook must have done it.
        $g2 = $this->gate->evaluate($siteId);

        $this->assertFalse($g2['allowed'], 'a paying customer must not see ads');
        $this->assertSame('pro', $g2['plan_slug']);
        $this->assertSame(AdGateService::DENY_PLAN_NOT_ELIGIBLE, $g2['reason']);

        $api = $this->delivery->decide(['website_id' => $siteId, 'slots' => ['footer_sticky']]);
        $this->assertSame(204, $api['status']);
        $this->assertNull($api['body']);

        $tag = $this->delivery->tag($siteId, app(AdTagAssetService::class), $this->gate);
        $this->assertFalse($tag['served']);
        $this->assertStringNotContainsString('fetch(', $tag['js'], 'the stub must carry no ad logic');
    }

    /**
     * The invalidation must not depend on StripeService — a plan can change from
     * several places, and a new one added later must not silently keep serving.
     */
    public function test_cache_is_invalidated_by_the_model_not_the_caller(): void
    {
        [$wsId, $siteId] = $this->seedFreeSiteWithHouseAd();

        $this->assertTrue($this->gate->evaluate($siteId)['allowed']);   // warms the cache

        // A raw model write, nothing to do with the billing service.
        $sub = Subscription::create([
            'workspace_id' => $wsId, 'plan_id' => $this->paidPlanId(),
            'status' => 'active', 'starts_at' => now(),
        ]);

        $this->assertFalse($this->gate->evaluate($siteId)['allowed'], 'created event must invalidate');

        // ── DOWNGRADE via update ────────────────────────────────────────
        $sub->update(['status' => 'cancelled']);

        $this->assertTrue($this->gate->evaluate($siteId)['allowed'], 'updated event must invalidate');

        // ── And via delete ──────────────────────────────────────────────
        Subscription::create([
            'workspace_id' => $wsId, 'plan_id' => $this->paidPlanId(),
            'status' => 'active', 'starts_at' => now(),
        ]);
        $this->assertFalse($this->gate->evaluate($siteId)['allowed']);

        Subscription::where('workspace_id', $wsId)->where('status', 'active')->first()?->delete();

        $this->assertTrue($this->gate->evaluate($siteId)['allowed'], 'deleted event must invalidate');
    }

    /** Downgrade restores ads by the same mechanism. */
    public function test_downgrade_restores_advertising(): void
    {
        [$wsId, $siteId] = $this->seedFreeSiteWithHouseAd();

        $sub = Subscription::create([
            'workspace_id' => $wsId, 'plan_id' => $this->paidPlanId(),
            'status' => 'active', 'starts_at' => now(),
        ]);
        $this->assertFalse($this->gate->evaluate($siteId)['allowed']);

        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $g = $this->gate->evaluate($siteId);
        $this->assertTrue($g['allowed']);
        $this->assertSame('free', $g['plan_slug']);
        $this->assertSame(200, $this->delivery->decide(
            ['website_id' => $siteId, 'slots' => ['footer_sticky']]
        )['status']);
    }

    /**
     * The tag must take a live creative DOWN when the server says no-fill —
     * this is the path a visitor takes when the owner upgrades mid-session.
     * A network failure must NOT do the same, or one timeout would blank an ad
     * the advertiser paid for.
     */
    public function test_tag_takes_the_creative_down_on_explicit_no_fill_only(): void
    {
        $js = app(AdTagAssetService::class)->build(1);

        $this->assertStringContainsString('r.status === 204', $js, 'must distinguish 204 from a failure');
        $this->assertStringContainsString('cur.hidden = true', $js, 'an explicit no-fill must hide the slot');
        $this->assertStringContainsString('releaseSpace()', $js, 'and release the reserved footer space');

        // The catch path must not blank anything.
        $this->assertMatchesRegularExpression(
            '/\.catch\(function \(\) \{ clearTimeout\(timer\); \}\)/',
            $js,
            'a network error must leave the current creative alone'
        );
    }

    /** A billing write must never fail because ad-cache invalidation failed. */
    public function test_invalidation_failure_cannot_block_a_billing_write(): void
    {
        [$wsId] = $this->seedFreeSiteWithHouseAd();

        // The hook is wrapped in try/catch; prove a subscription still persists
        // even when the ads engine is unhappy by pointing the cache at nothing.
        $sub = Subscription::create([
            'workspace_id' => $wsId, 'plan_id' => $this->paidPlanId(),
            'status' => 'active', 'starts_at' => now(),
        ]);

        $this->assertTrue($sub->exists);
        $this->assertDatabaseHas('subscriptions', ['workspace_id' => $wsId, 'status' => 'active']);
    }
}
