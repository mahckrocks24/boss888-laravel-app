<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\AdDecisionService;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Services\InventoryProfileService;
use App\Engines\Ads\Support\AdSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADS888 P0 — AdDecisionService, the waterfall end to end.
 */
class AdDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdDecisionService $decisions;
    private AdSettingsService $settings;
    private int $seq = 0;
    private int $slotId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decisions = app(AdDecisionService::class);
        $this->settings  = app(AdSettingsService::class);
        $this->settings->flush();
        $this->settings->set(AdSettings::MASTER_ENABLED, true);

        $this->slotId = (int) DB::table('ad_slots')->insertGetId([
            'code' => 'footer_sticky', 'name' => 'Footer sticky', 'format' => 'mobile_banner',
            'width' => 320, 'height' => 50, 'position' => 'footer_sticky', 'device' => 'all',
            'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A published, profiled, FREE site that is old enough to carry paid ads. */
    private function seedSite(array $workspace = [], array $website = []): array
    {
        $n = ++$this->seq;

        $userId = (int) DB::table('users')->insertGetId([
            'name' => "U{$n}", 'email' => "dec-{$n}-" . substr(md5((string) mt_rand()), 0, 8) . '@example.test',
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $wsId = (int) DB::table('workspaces')->insertGetId(array_merge([
            'name' => "WS{$n}", 'slug' => "dec-ws-{$n}-" . substr(md5((string) mt_rand()), 0, 8),
            'created_by' => $userId, 'industry' => 'dental clinic', 'location' => 'Dubai, UAE',
            'created_at' => now()->subDays(90), 'updated_at' => now(),
        ], $workspace));

        $siteId = (int) DB::table('websites')->insertGetId(array_merge([
            'workspace_id' => $wsId, 'name' => "Site{$n}",
            'subdomain' => "dec-{$n}.levelupgrowth.io", 'status' => 'published',
            'created_at' => now()->subDays(90), 'updated_at' => now(),
        ], $website));

        app(InventoryProfileService::class)->profile($siteId);

        return [$wsId, $siteId];
    }

    private function seedCampaign(string $kind, int $tier, array $campaign = [], array $creative = [], array $targeting = []): int
    {
        $advertiserId = (int) DB::table('advertisers')->insertGetId([
            'name' => $kind . '-adv-' . uniqid(), 'status' => 'active',
            'is_house' => $kind === 'house', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $campaignId = (int) DB::table('ad_campaigns')->insertGetId(array_merge([
            'advertiser_id' => $advertiserId, 'name' => $kind . ' campaign',
            'kind' => $kind, 'status' => 'active',
            'pricing_model' => 'cpm', 'rate_micros' => 1_000_000,
            'budget_total_micros' => $kind === 'house' ? null : 100_000_000,
            'budget_daily_micros' => null, 'pacing' => 'asap',
            'priority_tier' => $tier, 'created_at' => now(), 'updated_at' => now(),
        ], $campaign));

        DB::table('ad_creatives')->insert(array_merge([
            'campaign_id' => $campaignId, 'slot_id' => $this->slotId, 'type' => 'html',
            'html' => "<span>{$kind}</span>", 'click_url' => 'https://example.test',
            'alt_text' => $kind, 'weight' => 100, 'review_state' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ], $creative));

        foreach ($targeting as $rule) {
            DB::table('ad_targeting')->insert([
                'campaign_id' => $campaignId, 'dimension' => $rule['dimension'],
                'operator' => $rule['operator'] ?? 'in',
                'values' => json_encode($rule['values']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $campaignId;
    }

    public function test_master_switch_off_yields_no_fill(): void
    {
        $this->settings->set(AdSettings::MASTER_ENABLED, false);
        [, $siteId] = $this->seedSite();
        $this->seedCampaign('house', 5);

        $result = $this->decisions->decide($siteId, 'footer_sticky');

        $this->assertNull($result['fill']);
        $this->assertSame('master_switch_off', $result['reason']);
    }

    public function test_house_ad_fills_when_no_paid_campaign_exists(): void
    {
        [, $siteId] = $this->seedSite();
        $this->seedCampaign('house', 5);

        $result = $this->decisions->decide($siteId, 'footer_sticky');

        $this->assertNotNull($result['fill']);
        $this->assertSame('house', $result['fill']['kind']);
    }

    /** The core commercial behaviour: a sold impression displaces a house ad. */
    public function test_paid_campaign_outranks_house(): void
    {
        [, $siteId] = $this->seedSite();
        $this->seedCampaign('house', 5);
        $this->seedCampaign('paid', 1);

        $result = $this->decisions->decide($siteId, 'footer_sticky');

        $this->assertNotNull($result['fill']);
        $this->assertSame('paid', $result['fill']['kind']);
        $this->assertSame(1, $result['diagnostics']['chosen_tier']);
    }

    public function test_unapproved_creative_never_serves(): void
    {
        [, $siteId] = $this->seedSite();
        $this->seedCampaign('house', 5, [], ['review_state' => 'pending']);

        $result = $this->decisions->decide($siteId, 'footer_sticky');

        $this->assertNull($result['fill'], 'A pending creative must never serve');
    }

    public function test_paused_campaign_never_serves(): void
    {
        [, $siteId] = $this->seedSite();
        $this->seedCampaign('house', 5, ['status' => 'paused']);

        $this->assertNull($this->decisions->decide($siteId, 'footer_sticky')['fill']);
    }

    public function test_out_of_flight_campaign_never_serves(): void
    {
        [, $siteId] = $this->seedSite();
        $this->seedCampaign('house', 5, ['ends_at' => now()->subDay()]);

        $this->assertNull($this->decisions->decide($siteId, 'footer_sticky')['fill']);
    }

    public function test_targeting_match_and_miss(): void
    {
        [, $siteId] = $this->seedSite();
        $this->seedCampaign('house', 5);

        // Matching paid campaign — dental in AE, which is what seedSite creates.
        $this->seedCampaign('paid', 1, [], [], [
            ['dimension' => 'industry', 'values' => ['dental']],
            ['dimension' => 'business_country', 'values' => ['AE']],
        ]);

        $this->assertSame('paid', $this->decisions->decide($siteId, 'footer_sticky')['fill']['kind']);

        // Now a non-matching country only.
        DB::table('ad_targeting')->where('dimension', 'business_country')
            ->update(['values' => json_encode(['GB'])]);

        $result = $this->decisions->decide($siteId, 'footer_sticky');

        $this->assertSame('house', $result['fill']['kind'], 'A non-matching paid campaign falls back to house');
    }

    /**
     * Unsellable inventory may carry house ads but must never carry paid ones.
     */
    public function test_unsellable_inventory_gets_house_only(): void
    {
        [, $siteId] = $this->seedSite(['industry' => null, 'business_name' => null, 'name' => 'zzz']);
        $this->seedCampaign('house', 5);
        $this->seedCampaign('paid', 1);

        $result = $this->decisions->decide($siteId, 'footer_sticky');

        $this->assertSame('house', $result['fill']['kind']);
        $this->assertSame('inventory_not_sellable', $result['diagnostics']['paid_blocked']);
    }

    public function test_new_site_gets_house_only(): void
    {
        [, $siteId] = $this->seedSite([], ['created_at' => now()->subDay()]);
        $this->seedCampaign('house', 5);
        $this->seedCampaign('paid', 1);

        $result = $this->decisions->decide($siteId, 'footer_sticky');

        $this->assertSame('house', $result['fill']['kind']);
    }

    public function test_exhausted_budget_stops_paid_delivery(): void
    {
        [, $siteId] = $this->seedSite();
        $this->seedCampaign('house', 5);
        $paidId = $this->seedCampaign('paid', 1, ['budget_total_micros' => 1_000_000]);

        DB::table('ad_stats_daily')->insert([
            'stat_date' => now()->toDateString(), 'campaign_id' => $paidId,
            'creative_id' => null, 'website_id' => $siteId,
            'revenue_micros' => 1_000_000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->decisions->decide($siteId, 'footer_sticky');

        $this->assertSame('house', $result['fill']['kind'], 'An exhausted paid campaign falls back to house');
    }

    public function test_inactive_slot_yields_no_fill(): void
    {
        [, $siteId] = $this->seedSite();
        $this->seedCampaign('house', 5);
        DB::table('ad_slots')->where('id', $this->slotId)->update(['is_active' => false]);

        $result = $this->decisions->decide($siteId, 'footer_sticky');

        $this->assertNull($result['fill']);
        $this->assertSame('slot_inactive_or_unknown', $result['reason']);
    }

    public function test_slot_excluded_for_template_industry(): void
    {
        [, $siteId] = $this->seedSite([], ['template_industry' => 'news_channel']);
        $this->seedCampaign('house', 5);

        DB::table('ad_slot_eligibility')->insert([
            'slot_id' => $this->slotId, 'template_industry' => 'news_channel',
            'enabled' => false, 'reason' => 'no data-block anchors',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->decisions->decide($siteId, 'footer_sticky');

        $this->assertNull($result['fill']);
        $this->assertSame('slot_not_eligible_for_template', $result['reason']);
    }

    public function test_frequency_cap_blocks_repeat_delivery(): void
    {
        [, $siteId] = $this->seedSite();
        $houseId = $this->seedCampaign('house', 5);

        $ipHash = hash('sha256', 'visitor-1');
        DB::table('ad_frequency')->insert([
            'ip_hash' => $ipHash, 'campaign_id' => $houseId,
            'bucket_date' => now()->toDateString(),
            'count' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->decisions->decide($siteId, 'footer_sticky', ['ip_hash' => $ipHash]);

        $this->assertNull($result['fill'], 'A capped campaign must not serve again today');
    }

    public function test_decision_never_throws_on_bad_input(): void
    {
        $result = $this->decisions->decide(999999, 'footer_sticky');

        $this->assertNull($result['fill']);
        $this->assertIsString($result['reason']);
    }
}
