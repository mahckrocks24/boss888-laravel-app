<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\AdReachEstimator;
use App\Engines\Ads\Services\InventoryProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADS888 P0 — AdReachEstimator.
 *
 * The behaviour under test is a commercial safeguard: refuse to quote inventory
 * that does not exist, rather than quoting a small number and losing the
 * advertiser when it under-delivers.
 */
class AdReachEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private AdReachEstimator $estimator;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = app(AdReachEstimator::class);
    }

    private function seedProfiledSite(string $industry, string $location): int
    {
        $n = ++$this->seq;

        $userId = (int) DB::table('users')->insertGetId([
            'name' => "U{$n}", 'email' => "reach-{$n}-" . substr(md5((string) mt_rand()), 0, 8) . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $wsId = (int) DB::table('workspaces')->insertGetId([
            'name' => "WS{$n}", 'slug' => "reach-{$n}-" . substr(md5((string) mt_rand()), 0, 8),
            'created_by' => $userId, 'industry' => $industry, 'location' => $location,
            'created_at' => now()->subDays(90), 'updated_at' => now(),
        ]);

        $siteId = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $wsId, 'name' => "S{$n}", 'subdomain' => "reach-{$n}.levelupgrowth.io",
            'status' => 'published', 'created_at' => now()->subDays(90), 'updated_at' => now(),
        ]);

        app(InventoryProfileService::class)->profile($siteId);

        return $siteId;
    }

    private function seedDelivery(int $siteId, int $impressions): void
    {
        DB::table('ad_stats_daily')->insert([
            'stat_date' => now()->subDays(3)->toDateString(),
            'campaign_id' => null, 'creative_id' => null, 'website_id' => $siteId,
            'impressions' => $impressions, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_matches_inventory_on_industry_and_country(): void
    {
        $this->seedProfiledSite('dental clinic', 'Dubai, UAE');
        $this->seedProfiledSite('dental clinic', 'Dubai, UAE');
        $this->seedProfiledSite('fitness gym', 'Dubai, UAE');

        $result = $this->estimator->estimate([
            ['dimension' => 'industry', 'values' => ['dental']],
            ['dimension' => 'business_country', 'values' => ['AE']],
        ]);

        $this->assertSame(2, $result['matched_sites']);
    }

    public function test_refuses_to_quote_below_the_site_floor(): void
    {
        $this->seedProfiledSite('dental clinic', 'Dubai, UAE');

        $result = $this->estimator->estimate([['dimension' => 'industry', 'values' => ['dental']]]);

        $this->assertSame(1, $result['matched_sites']);
        $this->assertFalse($result['quotable']);
        $this->assertStringContainsString('at least', $result['quote_refusal_reason']);
    }

    /**
     * The most important behaviour: matched sites with no delivery history must
     * NOT produce an invented projection.
     */
    public function test_refuses_to_quote_without_delivery_history(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedProfiledSite('dental clinic', 'Dubai, UAE');
        }

        $result = $this->estimator->estimate([['dimension' => 'industry', 'values' => ['dental']]]);

        $this->assertSame(5, $result['matched_sites']);
        $this->assertSame(0, $result['projected_monthly_impressions'], 'No history must mean no invented number');
        $this->assertFalse($result['quotable']);
        $this->assertStringContainsString('no honest projection', $result['quote_refusal_reason']);
    }

    public function test_quotable_once_sites_and_measured_volume_exist(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $siteId = $this->seedProfiledSite('dental clinic', 'Dubai, UAE');
            $this->seedDelivery($siteId, 5000);
        }

        $result = $this->estimator->estimate([['dimension' => 'industry', 'values' => ['dental']]]);

        $this->assertSame(4, $result['matched_sites']);
        $this->assertSame(20000, $result['observed_impressions']);
        $this->assertTrue($result['quotable']);
        $this->assertNull($result['quote_refusal_reason']);
    }

    /** Unsellable inventory must never inflate a quote. */
    public function test_unsellable_inventory_is_excluded_from_the_pool(): void
    {
        $this->seedProfiledSite('dental clinic', 'Dubai, UAE');
        $this->seedProfiledSite('zzzz qqqq', '');      // unclassifiable
        $this->seedProfiledSite('zzzz qqqq', '');

        $result = $this->estimator->estimate([]);

        $this->assertSame(2, $result['unsellable_excluded']);
        $this->assertSame(1, $result['sellable_pool']);
    }

    public function test_reports_which_dimension_ruled_sites_out(): void
    {
        $this->seedProfiledSite('dental clinic', 'Dubai, UAE');
        $this->seedProfiledSite('fitness gym', 'Dubai, UAE');

        $result = $this->estimator->estimate([['dimension' => 'industry', 'values' => ['dental']]]);

        $this->assertArrayHasKey('industry', $result['rejected_by_dimension']);
        $this->assertSame(1, $result['rejected_by_dimension']['industry']);
    }

    public function test_empty_targeting_returns_the_whole_sellable_pool(): void
    {
        $this->seedProfiledSite('dental clinic', 'Dubai, UAE');
        $this->seedProfiledSite('fitness gym', 'Dubai, UAE');

        $result = $this->estimator->estimate([]);

        $this->assertSame(2, $result['matched_sites']);
    }
}
