<?php

namespace Tests\Feature\Domains;

use App\Models\DomainOrder;
use App\Models\DomainOrderItem;
use App\Services\Domains\DomainCommerceService;
use App\Services\Domains\DomainPricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DOMAIN-TERMS-1 (2026-09-25): P = cost + margin (unseen); D = P × 1.5 (the per-year price); 1 year = D;
 * the 3-year bundle = $1 + D + D; renewals = D; a discount comes off D and can never make a loss.
 */
class DomainTermsPricingTest extends TestCase
{
    private int $ws = 991601;
    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'namecheap.environment' => 'sandbox',
            'namecheap.client_ip'   => '134.209.93.41',
            'namecheap.endpoints.sandbox' => 'https://api.sandbox.namecheap.com/xml.response',
            'namecheap.credentials.sandbox' => ['api_user' => 'testuser', 'api_key' => 'test-key-fake', 'username' => 'testuser'],
            'namecheap.pricing.markup_percent' => 30.0,
            'namecheap.pricing.markup_minimum_usd' => 4.00,
            'namecheap.pricing.list_multiplier' => 1.5,
            'namecheap.pricing.bundle_years' => 3,
            'namecheap.pricing.bundle_first_year_usd' => 1.00,
            'namecheap.pricing.discount_percent' => 0,
        ]);
        Http::preventStrayRequests();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'Terms Fixture', 'email' => 'terms-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'Terms WS', 'slug' => 'terms-' . uniqid(), 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        DomainOrderItem::where('workspace_id', $this->ws)->delete();
        DomainOrder::where('workspace_id', $this->ws)->delete();
        DB::table('infra_events')->where('workspace_id', $this->ws)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        if ($this->uid) { DB::table('users')->where('id', $this->uid)->delete(); }
    }

    // ------------------------------------------------------------ arithmetic --

    public function test_com_today_one_year_is_list_and_the_bundle_is_one_dollar_then_list(): void
    {
        $t = DomainPricingService::termsFor(1398, 4124);   // .com: 1-year wholesale $13.98, 3-year wholesale $41.24

        $this->assertSame(1817, $t['base_per_year_minor'], 'P = 13.98 + 30% (never shown)');
        $this->assertSame(2726, $t['list_per_year_minor'], 'D = P × 1.5');
        $this->assertSame('$27.26', $t['renewal_per_year']);

        [$one, $bundle] = $t['terms'];
        $this->assertSame([1, 2726, [2726], 1398, 1328], [$one['years'], $one['total_minor'], $one['per_year_minor'], $one['wholesale_minor'], $one['markup_minor']]);
        $this->assertSame([3, 5552, [100, 2726, 2726], 4124, 1428], [$bundle['years'], $bundle['total_minor'], $bundle['per_year_minor'], $bundle['wholesale_minor'], $bundle['markup_minor']]);
        $this->assertSame('$1 first year', $bundle['headline']);
        $this->assertSame(['$1.00', '$27.26', '$27.26'], $bundle['per_year']);
        $this->assertSame('$55.52', $bundle['total']);
        $this->assertFalse($bundle['capped']);
    }

    public function test_a_discount_comes_off_the_list_price_and_the_headline_stays(): void
    {
        config(['namecheap.pricing.discount_percent' => 10]);
        $t = DomainPricingService::termsFor(1398, 4124);

        $this->assertSame(2453, $t['list_per_year_minor'], '2725.5 × 0.9');
        $this->assertSame([100, 2453, 2453], $t['terms'][1]['per_year_minor']);
        $this->assertSame(10.0, $t['discount_percent']);
    }

    public function test_a_discount_can_never_price_a_term_below_wholesale_plus_the_minimum_margin(): void
    {
        config(['namecheap.pricing.discount_percent' => 60]);
        $t = DomainPricingService::termsFor(1398, 4124);
        [$one, $bundle] = $t['terms'];

        $this->assertSame(1798, $one['total_minor'], '1398 + $4 floor');
        $this->assertTrue($one['capped']);
        $this->assertSame(4524, $bundle['total_minor'], '4124 + $4 floor');
        $this->assertSame([100, 2212, 2212], $bundle['per_year_minor'], 'the later years absorb it; the $1 headline stays');
        $this->assertTrue($bundle['capped']);
        $this->assertGreaterThanOrEqual(400, $bundle['markup_minor']);
    }

    public function test_a_registrar_bundle_quote_below_n_years_of_cost_is_not_trusted(): void
    {
        // Measured 2026-09-25: .io/.co 3-year quotes return the 1-year wholesale.
        $t = DomainPricingService::termsFor(4498, 4498);
        $this->assertSame(3 * 4498, $t['terms'][1]['wholesale_minor']);
        $this->assertGreaterThan($t['terms'][1]['wholesale_minor'], $t['terms'][1]['total_minor']);

        $t2 = DomainPricingService::termsFor(1398, null);
        $this->assertSame(3 * 1398, $t2['terms'][1]['wholesale_minor'], 'no quote: n × the 1-year cost');
        $this->assertSame(4124, DomainPricingService::termsFor(1398, 4124)['terms'][1]['wholesale_minor'], 'a genuine multi-year discount (41.24 < 3 × 13.98) is trusted');
    }

    // --------------------------------------------------------------- live --

    private function fakeRegistrar(): void
    {
        Http::fake(function ($request) {
            $body = (string) $request->body();
            if (str_contains($body, 'domains.check'))    { return Http::response($this->xmlCheck(), 200); }
            if (str_contains($body, 'users.getPricing')) { return Http::response($this->xmlPricing(), 200); }
            if (str_contains($body, 'domains.getInfo'))  { return Http::response($this->xmlNotOwned(), 200); }
            return Http::response($this->xmlError('2011170', 'Unexpected command in test'), 200);
        });
    }

    public function test_search_shows_the_list_price_the_bundle_and_the_renewal(): void
    {
        $this->fakeRegistrar();
        $r = DomainCommerceService::make()->search('terms-search.com', 1);

        $this->assertTrue($r['available']);
        $this->assertSame('$27.26', $r['retail']);
        $this->assertSame('$27.26', $r['renewal']['retail']);
        $this->assertCount(2, $r['terms']);
        $this->assertSame('$1 first year', $r['terms'][1]['headline']);
        $this->assertSame('$55.52', $r['terms'][1]['total']);
        $this->assertSame(1, $r['registration_period']['years'], 'the period follows the years asked for; the UI defaults to the bundle');
    }

    public function test_an_order_line_freezes_the_terms_breakdown_and_refuses_other_terms(): void
    {
        $this->fakeRegistrar();
        $svc = DomainCommerceService::make();

        $res = $svc->createOrder($this->ws, null, [
            ['domain' => 'bundle-line.com', 'years' => 3],
            ['domain' => 'one-line.com', 'years' => 1],
            ['domain' => 'two-line.com', 'years' => 2],
        ], 'terms-' . uniqid());

        $this->assertArrayHasKey('order', $res, json_encode($res));
        $this->assertCount(1, $res['rejected']);
        $this->assertSame('two-line.com', $res['rejected'][0]['domain']);
        $this->assertStringContainsString('3-year plan', $res['rejected'][0]['reason']);

        $items = collect($res['order']['items'])->keyBy('domain');
        $this->assertSame(5552, $items['bundle-line.com']['price_minor']);
        $this->assertSame(2726, $items['one-line.com']['price_minor']);
        $this->assertSame('$1 first year', $items['bundle-line.com']['term']['headline']);
        $this->assertSame(['$1.00', '$27.26', '$27.26'], $items['bundle-line.com']['term']['per_year']);
        $this->assertSame(5552 + 2726, $res['order']['total_minor']);

        $row = DomainOrderItem::where('workspace_id', $this->ws)->where('domain', 'bundle-line.com')->first();
        $this->assertSame(4124, (int) $row->registrar_cost_minor, 'the bundle wholesale, not 3 × the 1-year cost');
        $this->assertSame(1428, (int) $row->markup_minor);
        $this->assertSame(3, json_decode($row->pricing_json, true)['years']);
    }

    // ---------------------------------------------------------------- XML --

    private function xmlCheck(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors /><CommandResponse Type="namecheap.domains.check">
<DomainCheckResult Domain="test.com" Available="true" IsPremiumName="false" IcannFee="0.18" /></CommandResponse></ApiResponse>';
    }

    private function xmlPricing(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors /><CommandResponse Type="namecheap.users.getPricing">
<UserGetPricingResult><ProductType Name="domains"><ProductCategory Name="register"><Product Name="com">
<Price Duration="1" DurationType="YEAR" Price="13.98" RegularPrice="13.98" YourPrice="13.98" Currency="USD" />
<Price Duration="3" DurationType="YEAR" Price="41.24" RegularPrice="41.24" YourPrice="41.24" Currency="USD" />
</Product></ProductCategory></ProductType></UserGetPricingResult></CommandResponse></ApiResponse>';
    }

    private function xmlNotOwned(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="2030166">Domain is invalid</Error></Errors>
<CommandResponse Type="namecheap.domains.getInfo"><DomainGetInfoResult ID="0" IsOwner="false" IsPremium="false"><DomainDetails><NumYears>0</NumYears></DomainDetails>
<DnsDetails IsUsingOurDNS="false" HostCount="0" /></DomainGetInfoResult></CommandResponse></ApiResponse>';
    }

    private function xmlError(string $n, string $m): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="' . $n . '">' . $m . '</Error></Errors></ApiResponse>';
    }
}
