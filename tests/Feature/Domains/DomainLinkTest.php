<?php

namespace Tests\Feature\Domains;

use App\Http\Controllers\Api\CustomerDomainController;
use App\Jobs\ConfigureDomainDnsJob;
use App\Jobs\ConnectDomainToWebsiteJob;
use App\Jobs\RegisterDomainJob;
use App\Models\CustomDomain;
use App\Models\CustomerDomain;
use App\Models\DomainOrder;
use App\Models\DomainOrderItem;
use App\Services\CustomDomainService;
use App\Services\Domains\Contracts\CustomHostnameProvider;
use App\Services\Domains\CustomHostnameResult;
use App\Services\Domains\DomainCommerceService;
use App\Services\Domains\DomainLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * RFC-0015 — a domain bought from us connects itself.
 *
 * U1 DOMAIN-LINK-1: a website may be named on the order line; tenancy is enforced; the id
 *    is frozen on the item and carried to the ownership row; attach/detach round-trip.
 * U2 DNS-AUTO-1: after registration the domain's web records are MERGE-written at the
 *    registrar (mail untouched, parking removed), from captured sandbox XML.
 * U3 CONNECT-AUTO-1: attachment goes through the locked custom-hostname path — gated
 *    keeps waiting, open goes live and syncs websites.custom_domain, a slow one stalls.
 */
class DomainLinkTest extends TestCase
{
    use RefreshDatabase;

    private int $wsA;
    private int $wsB;
    private int $siteA;
    private int $siteA2;
    private int $siteB;

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
            'cloudflare.routing_target' => 'levelupgrowth.io',
            'cloudflare.saas_enabled' => false,
            'domains.auto_dns.enabled' => true,
            'domains.link.stall_minutes' => 60,
            'queue.default' => 'sync',
        ]);

        Http::preventStrayRequests();

        $this->wsA = $this->workspace();
        $this->wsB = $this->workspace();
        $this->siteA  = $this->website($this->wsA, 'Bakery A');
        $this->siteA2 = $this->website($this->wsA, 'Bakery A Two');
        $this->siteB  = $this->website($this->wsB, 'Cafe B');
    }

    // ------------------------------------------------------------- fixtures --

    private function workspace(): int
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Link Fixture', 'email' => 'link-' . uniqid() . '@example.test', 'password' => bcrypt('x'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('workspaces')->insertGetId([
            'name' => 'Link WS', 'slug' => 'link-' . uniqid(), 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function website(int $ws, string $name): int
    {
        return (int) DB::table('websites')->insertGetId([
            'workspace_id' => $ws, 'name' => $name, 'subdomain' => strtolower(str_replace(' ', '-', $name)) . '.levelupgrowth.io',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Registrar at the HTTP boundary: check/pricing/create as the commerce suite, plus DNS read and write. */
    private function fakeRegistrar(?string $hostsXml = null, bool $usesOurDns = true): void
    {
        $hostsXml = $hostsXml ?? $this->hostsParking();
        // The registrar's view of ownership flips once we have created the domain: before, getInfo says
        // "not yours" (the adapter's pre-purchase check); after, it says "yours" — otherwise the inline
        // SyncCustomerDomainJob that follows registration would mark the fresh row RELEASED and the
        // automatic set-up would (correctly) refuse to touch a domain we no longer own.
        $created = false;
        Http::fake(function ($request) use ($hostsXml, $usesOurDns, &$created) {
            $body = (string) $request->body() . ' ' . $request->url();

            if (str_contains($body, 'domains.check'))     { return Http::response($this->xmlCheck(), 200); }
            if (str_contains($body, 'users.getPricing'))  { return Http::response($this->xmlPricing(), 200); }
            if (str_contains($body, 'domains.getInfo'))   { return Http::response($created ? $this->xmlOwned() : $this->xmlNotOwned(), 200); }
            if (str_contains($body, 'domains.create'))    { $created = true; return Http::response($this->xmlCreated(), 200); }
            if (str_contains($body, 'dns.getHosts'))      { return Http::response($this->xmlHosts($hostsXml, $usesOurDns), 200); }
            if (str_contains($body, 'dns.setHosts'))      { return Http::response($this->xmlSetHosts(), 200); }

            return Http::response($this->xmlError('2011170', 'Unexpected command in test: ' . substr($body, 0, 80)), 200);
        });
    }

    private function order(int $ws, array $lines): array
    {
        return DomainCommerceService::make()->createOrder($ws, null, $lines, 'idem-' . uniqid());
    }

    private function paidOrder(int $ws, array $lines): DomainOrder
    {
        $res = $this->order($ws, $lines);
        $this->assertArrayHasKey('order', $res, json_encode($res));
        $order = DomainOrder::find($res['order']['id']);
        $order->update(['paid_at' => now(), 'status' => DomainOrder::STATUS_PROVISIONING]);

        return $order;
    }

    private function register(DomainOrder $order): CustomerDomain
    {
        foreach ($order->items as $item) {
            (new RegisterDomainJob($item->id))->handle();
        }

        return CustomerDomain::where('domain', $order->items->first()->domain)->firstOrFail();
    }

    private function controllerRequest(int $ws, array $input = [], array $query = []): Request
    {
        $r = Request::create('/api/domains', 'POST', $input + $query);
        $r->attributes->set('workspace_id', $ws);

        return $r;
    }

    // ============================================================ U1: LINK ==

    public function test_order_line_refuses_a_website_outside_the_workspace(): void
    {
        $this->fakeRegistrar();

        $res = $this->order($this->wsA, [['domain' => 'tenant-check.com', 'years' => 1, 'website_id' => $this->siteB]]);

        $this->assertSame('WEBSITE_NOT_FOUND', $res['code'] ?? null, json_encode($res));
        $this->assertSame(0, DomainOrder::where('workspace_id', $this->wsA)->count(), 'nothing is written');
    }

    public function test_controller_maps_a_foreign_website_to_404_not_403(): void
    {
        $this->fakeRegistrar();

        $resp = (new CustomerDomainController())->createOrder($this->controllerRequest($this->wsA, [
            'items' => [['domain' => 'tenant-check.com', 'years' => 1, 'website_id' => $this->siteB]],
        ]));

        $this->assertSame(404, $resp->getStatusCode());
    }

    public function test_website_id_is_frozen_on_the_item_and_carried_to_ownership(): void
    {
        $this->fakeRegistrar();
        Queue::fake();   // registration only; the DNS job is asserted, not run

        $order = $this->paidOrder($this->wsA, [['domain' => 'carried.com', 'years' => 1, 'website_id' => $this->siteA]]);
        $this->assertSame($this->siteA, (int) $order->items->first()->website_id);

        $owned = $this->register($order);

        $this->assertSame($this->siteA, (int) $owned->website_id);
        $this->assertSame(DomainLinkService::DNS_PENDING, $owned->dns_state);
        $this->assertSame(DomainLinkService::CONNECT_PENDING, $owned->connect_state);
        Queue::assertPushed(ConfigureDomainDnsJob::class, fn ($j) => $j->domain === 'carried.com');
    }

    public function test_order_without_a_website_has_no_connect_state(): void
    {
        $this->fakeRegistrar();
        Queue::fake();

        $owned = $this->register($this->paidOrder($this->wsA, [['domain' => 'loose.com', 'years' => 1]]));

        $this->assertNull($owned->website_id);
        $this->assertNull($owned->connect_state);
        $this->assertSame(DomainLinkService::DNS_PENDING, $owned->dns_state, 'DNS is still pointed at us: the customer may attach it later');
    }

    public function test_attach_and_detach_round_trip_with_tenancy_and_one_domain_per_website(): void
    {
        $this->fakeRegistrar();
        Queue::fake();

        $one = $this->register($this->paidOrder($this->wsA, [['domain' => 'first.com', 'years' => 1]]));
        $two = $this->register($this->paidOrder($this->wsA, [['domain' => 'second.com', 'years' => 1]]));
        $svc = new DomainLinkService(null, new CustomDomainService(new FakeLinkProvider()));

        $this->assertSame('WEBSITE_NOT_FOUND', $svc->attach($one, $this->siteB)['code'] ?? null, 'another tenant\'s website is not found');

        $this->assertTrue($svc->attach($one, $this->siteA)['ok'] ?? false);
        $this->assertSame($this->siteA, (int) $one->fresh()->website_id);
        $this->assertSame(DomainLinkService::CONNECT_PENDING, $one->fresh()->connect_state);

        $this->assertSame('WEBSITE_TAKEN', $svc->attach($two, $this->siteA)['code'] ?? null, 'one bought domain per website');

        $this->assertTrue($svc->detach($one)['ok'] ?? false);
        $this->assertNull($one->fresh()->website_id);
        $this->assertNull($one->fresh()->connect_state);

        $this->assertTrue($svc->attach($two, $this->siteA)['ok'] ?? false, 'free again after detach');
        $this->assertSame(1, DB::table('infra_events')->where('event', 'domain.website.detached')->count());
    }

    public function test_index_can_be_filtered_by_website_and_carries_the_journey(): void
    {
        $this->fakeRegistrar();
        Queue::fake();

        $this->register($this->paidOrder($this->wsA, [['domain' => 'for-site.com', 'years' => 1, 'website_id' => $this->siteA]]));
        $this->register($this->paidOrder($this->wsA, [['domain' => 'no-site.com', 'years' => 1]]));

        $r = Request::create('/api/domains', 'GET', ['website_id' => $this->siteA]);
        $r->attributes->set('workspace_id', $this->wsA);
        $json = (new CustomerDomainController())->index($r)->getData(true);

        $this->assertSame(1, $json['count']);
        $d = $json['domains'][0];
        $this->assertSame('for-site.com', $d['domain']);
        $this->assertSame('Bakery A', $d['website_name']);
        $this->assertSame('www.for-site.com', $d['hostname']);
        $this->assertSame(['paid', 'registering', 'dns', 'securing', 'live'], array_column($d['journey']['steps'], 'key'));
        $this->assertSame(['done', 'done', 'current', 'pending', 'pending'], array_column($d['journey']['steps'], 'state'));
        $this->assertArrayNotHasKey('provider_metadata_json', $d);
        $this->assertStringNotContainsStringIgnoringCase('namecheap', json_encode($d));
    }

    // ========================================================= U2: DNS-AUTO ==

    public function test_merge_keeps_mail_and_text_records_drops_parking_and_adds_ours(): void
    {
        $current = [
            ['name' => 'www', 'type' => 'CNAME', 'address' => 'parkingpage.namecheap.com.', 'ttl' => 1800],
            ['name' => '@',   'type' => 'URL',   'address' => 'http://www.acme.com?from=@', 'ttl' => 1800],
            ['name' => '@',   'type' => 'MX',    'address' => 'mx1.mail.test.', 'mx_pref' => 10, 'ttl' => 1800],
            ['name' => '@',   'type' => 'TXT',   'address' => 'v=spf1 include:mail.test ~all', 'ttl' => 1800],
            ['name' => 'blog','type' => 'A',     'address' => '10.0.0.9', 'ttl' => 300],
        ];

        $merged = DomainLinkService::mergeRecords($current, 'acme.com');

        $sig = array_map(fn ($r) => $r['name'] . ' ' . $r['type'] . ' ' . $r['address'], $merged);
        $this->assertContains('@ MX mx1.mail.test.', $sig);
        $this->assertContains('@ TXT v=spf1 include:mail.test ~all', $sig);
        $this->assertContains('blog A 10.0.0.9', $sig);
        $this->assertContains('www CNAME levelupgrowth.io.', $sig);
        $this->assertContains('@ URL301 https://www.acme.com', $sig);
        $this->assertNotContains('www CNAME parkingpage.namecheap.com.', $sig);
        $this->assertNotContains('@ URL http://www.acme.com?from=@', $sig);
        $this->assertSame(10, collect($merged)->firstWhere('type', 'MX')['mx_pref']);
        $this->assertCount(5, $merged);

        $this->assertFalse(DomainLinkService::dnsLooksSet($current, 'acme.com'));
        $this->assertTrue(DomainLinkService::dnsLooksSet($merged, 'acme.com'), 'a second pass changes nothing');
    }

    public function test_registration_writes_merged_dns_at_the_registrar_and_queues_the_connect(): void
    {
        $this->fakeRegistrar($this->hostsParkingWithMx());

        $order = $this->paidOrder($this->wsA, [['domain' => 'auto-dns.com', 'years' => 1, 'website_id' => $this->siteA]]);

        // Sync queue: RegisterDomainJob → advance() → ConfigureDomainDnsJob runs inline; the connect
        // job then runs inline too and, with the gate closed, parks the row at "waiting".
        $owned = $this->register($order);
        $owned->refresh();

        $this->assertSame(DomainLinkService::DNS_SET, $owned->dns_state, $owned->link_error ?? '');
        $this->assertSame(DomainLinkService::CONNECT_WAITING, $owned->connect_state);

        $write = null;
        Http::assertSent(function ($request) use (&$write) {
            if (str_contains((string) $request->body() . $request->url(), 'dns.setHosts')) { $write = $request; return true; }
            return false;
        });
        $params = [];
        parse_str(parse_url($write->url(), PHP_URL_QUERY) ?: '', $params);
        $params = $params + (array) $write->data();

        $this->assertSame('@',      (string) ($params['HostName1'] ?? ''));
        $this->assertSame('MX',     (string) ($params['RecordType1'] ?? ''));
        $this->assertSame('10',     (string) ($params['MXPref1'] ?? ''));
        $this->assertSame('www',    (string) ($params['HostName2'] ?? ''));
        $this->assertSame('CNAME',  (string) ($params['RecordType2'] ?? ''));
        $this->assertSame('levelupgrowth.io.', (string) ($params['Address2'] ?? ''));
        $this->assertSame('@',      (string) ($params['HostName3'] ?? ''));
        $this->assertSame('URL301', (string) ($params['RecordType3'] ?? ''));
        $this->assertSame('https://www.auto-dns.com', (string) ($params['Address3'] ?? ''));
        $this->assertArrayNotHasKey('HostName4', $params);
        $this->assertStringNotContainsString('parkingpage', json_encode($params));

        $this->assertSame(1, DB::table('infra_events')->where('event', 'domain.dns.configured')->where('provider_resource_id', 'auto-dns.com')->count());
    }

    public function test_custom_nameservers_skip_the_automatic_dns_honestly(): void
    {
        $this->fakeRegistrar($this->hostsParking(), false);

        $owned = $this->register($this->paidOrder($this->wsA, [['domain' => 'own-ns.com', 'years' => 1, 'website_id' => $this->siteA]]));
        $owned->refresh();

        $this->assertSame(DomainLinkService::DNS_EXTERNAL, $owned->dns_state);
        $this->assertSame(DomainLinkService::CONNECT_PENDING, $owned->connect_state, 'attachment does not start without our records');
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body() . $r->url(), 'dns.setHosts'));
        $this->assertSame('skipped', collect(DomainLinkService::make()->journey($owned)['steps'])->firstWhere('key', 'dns')['state']);
    }

    public function test_dns_write_is_not_repeated_when_records_are_already_ours(): void
    {
        $this->fakeRegistrar($this->hostsAlreadyOurs('already.com'));

        $owned = $this->register($this->paidOrder($this->wsA, [['domain' => 'already.com', 'years' => 1]]));

        $this->assertSame(DomainLinkService::DNS_SET, $owned->fresh()->dns_state);
        Http::assertNotSent(fn ($r) => str_contains((string) $r->body() . $r->url(), 'dns.setHosts'));
    }

    // ===================================================== U3: CONNECT-AUTO ==

    private function ownedWithDnsSet(string $domain, ?int $websiteId): CustomerDomain
    {
        $this->fakeRegistrar();
        Queue::fake();
        $line = ['domain' => $domain, 'years' => 1] + ($websiteId ? ['website_id' => $websiteId] : []);
        $owned = $this->register($this->paidOrder($this->wsA, [$line]));
        $owned->update(['dns_state' => DomainLinkService::DNS_SET]);

        return $owned->fresh();
    }

    public function test_connect_waits_while_the_hostname_gate_is_closed_and_touches_nothing(): void
    {
        config(['cloudflare.saas_enabled' => false]);
        $owned = $this->ownedWithDnsSet('gated.com', $this->siteA);
        $fake = new FakeLinkProvider();
        $svc = new DomainLinkService(null, new CustomDomainService($fake));

        $r = $svc->connect($owned);

        $this->assertSame(DomainLinkService::CONNECT_WAITING, $r['state']);
        $this->assertFalse($r['done']);
        $this->assertSame(0, $fake->createCount);
        $this->assertNull(DB::table('websites')->where('id', $this->siteA)->value('custom_domain'));
        $this->assertSame('current', collect($svc->journey($owned->fresh())['steps'])->firstWhere('key', 'securing')['state']);
    }

    public function test_connect_goes_live_through_the_locked_path_and_syncs_the_website(): void
    {
        config(['cloudflare.saas_enabled' => true]);
        $owned = $this->ownedWithDnsSet('golive.com', $this->siteA);
        $fake = new FakeLinkProvider();
        $fake->makeActive = true;
        $svc = new DomainLinkService(null, new CustomDomainService($fake));

        $r = $svc->connect($owned);

        $this->assertSame(DomainLinkService::CONNECT_ACTIVE, $r['state']);
        $this->assertSame(1, $fake->createCount);
        $this->assertSame('www.golive.com', DB::table('websites')->where('id', $this->siteA)->value('custom_domain'));
        $this->assertSame(1, (int) DB::table('websites')->where('id', $this->siteA)->value('domain_verified'));
        $this->assertSame(1, CustomDomain::withoutGlobalScopes()->where('website_id', $this->siteA)->where('hostname', 'www.golive.com')->count());

        $j = $svc->journey($owned->fresh());
        $this->assertTrue($j['complete']);
        $this->assertSame('https://www.golive.com', $j['live_url']);
        $this->assertSame(['done', 'done', 'done', 'done', 'done'], array_column($j['steps'], 'state'));
        $this->assertSame(1, DB::table('infra_events')->where('event', 'domain.website.connected')->count());

        // Detach takes our hostname off the website again, and only ours.
        $svc->detach($owned->fresh());
        $this->assertNull(DB::table('websites')->where('id', $this->siteA)->value('custom_domain'));
        $this->assertSame(1, $fake->deleteCount);
    }

    public function test_connect_polls_until_live_and_stalls_past_the_cap(): void
    {
        config(['cloudflare.saas_enabled' => true]);
        $owned = $this->ownedWithDnsSet('slow.com', $this->siteA);
        $fake = new FakeLinkProvider();   // never active
        $svc = new DomainLinkService(null, new CustomDomainService($fake));

        $this->assertSame(DomainLinkService::CONNECT_CONNECTING, $svc->connect($owned)['state']);
        $this->assertSame(DomainLinkService::CONNECT_CONNECTING, $svc->poll($owned->fresh())['state'], 'inside the cap: keep polling');

        $owned->fresh()->update(['connect_started_at' => now()->subMinutes(61)]);
        $this->assertSame(DomainLinkService::CONNECT_STALLED, $svc->poll($owned->fresh())['state']);
        $this->assertSame('failed', collect($svc->journey($owned->fresh())['steps'])->firstWhere('key', 'securing')['state']);
        $this->assertSame(1, DB::table('infra_events')->where('event', 'domain.website.stalled')->count());

        // A hostname that later turns active is picked up by the next poll after an operator resets it.
        $owned->fresh()->update(['connect_state' => DomainLinkService::CONNECT_CONNECTING, 'connect_started_at' => now()]);
        $fake->makeActive = true;
        $this->assertSame(DomainLinkService::CONNECT_ACTIVE, $svc->poll($owned->fresh())['state']);
    }

    public function test_connect_refuses_to_replace_a_domain_the_customer_connected_elsewhere(): void
    {
        config(['cloudflare.saas_enabled' => true]);
        $owned = $this->ownedWithDnsSet('mine.com', $this->siteA);
        DB::table('websites')->where('id', $this->siteA)->update(['custom_domain' => 'www.theirs.com', 'domain_verified' => 1]);
        $fake = new FakeLinkProvider();
        $svc = new DomainLinkService(null, new CustomDomainService($fake));

        $r = $svc->connect($owned);

        $this->assertSame(DomainLinkService::CONNECT_FAILED, $r['state']);
        $this->assertSame(0, $fake->createCount);
        $this->assertSame('www.theirs.com', DB::table('websites')->where('id', $this->siteA)->value('custom_domain'), 'untouched');
        $this->assertStringContainsString('www.theirs.com', (string) $owned->fresh()->link_error);
    }

    public function test_connect_job_is_a_noop_without_a_website_or_before_dns(): void
    {
        $owned = $this->ownedWithDnsSet('nosite.com', null);
        (new ConnectDomainToWebsiteJob('nosite.com'))->handle();
        $this->assertNull($owned->fresh()->connect_state);

        $owned2 = $this->ownedWithDnsSet('predns.com', $this->siteA2);
        $owned2->update(['dns_state' => DomainLinkService::DNS_PENDING]);
        $this->assertTrue((new DomainLinkService(null, new CustomDomainService(new FakeLinkProvider())))->connect($owned2->fresh())['done']);
        $this->assertSame(DomainLinkService::CONNECT_PENDING, $owned2->fresh()->connect_state, 'no attempt before DNS is set');
    }

    // ================================================================ XML ==

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
</Product></ProductCategory></ProductType></UserGetPricingResult></CommandResponse></ApiResponse>';
    }

    private function xmlNotOwned(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="2030166">Domain is invalid</Error></Errors>
<CommandResponse Type="namecheap.domains.getInfo"><DomainGetInfoResult ID="0" IsOwner="false" IsPremium="false"><DomainDetails><NumYears>0</NumYears></DomainDetails>
<DnsDetails IsUsingOurDNS="false" HostCount="0" /></DomainGetInfoResult></CommandResponse></ApiResponse>';
    }

    /** After creation: the registrar reports the domain in our account (shape from the commerce suite's replay test). */
    private function xmlOwned(): string
    {
        return '<?xml version="1.0"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors />
<CommandResponse><DomainGetInfoResult Status="Ok" ID="5" IsOwner="true" AutoRenew="false" IsLocked="false">
<DomainDetails><CreatedDate>09/25/2026</CreatedDate><ExpiredDate>09/25/2027</ExpiredDate></DomainDetails>
<DnsDetails IsUsingOurDNS="true"><Nameserver>dns1.registrar-servers.com</Nameserver><Nameserver>dns2.registrar-servers.com</Nameserver></DnsDetails>
</DomainGetInfoResult></CommandResponse></ApiResponse>';
    }

    private function xmlCreated(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors /><CommandResponse Type="namecheap.domains.create">
<DomainCreateResult Domain="test.com" Registered="true" ChargedAmount="14.18" DomainID="99" OrderID="777001" TransactionID="888001" WhoisguardEnable="true" FreePositiveSSL="false" />
</CommandResponse></ApiResponse>';
    }

    /** Captured from the live sandbox on 2026-09-25 for chefred-fcf3e2.com: the registrar's parking records. */
    private function hostsParking(): string
    {
        return '<host HostId="1" Name="www" Type="CNAME" Address="parkingpage.namecheap.com." MXPref="10" TTL="1800" />
<host HostId="2" Name="@" Type="URL" Address="http://www.test.com?from=@" MXPref="10" TTL="1800" />';
    }

    private function hostsParkingWithMx(): string
    {
        return '<host HostId="3" Name="@" Type="MX" Address="mx1.mail.test." MXPref="10" TTL="1800" />' . $this->hostsParking();
    }

    private function hostsAlreadyOurs(string $domain): string
    {
        return '<host HostId="7" Name="www" Type="CNAME" Address="levelupgrowth.io." MXPref="10" TTL="1800" />
<host HostId="8" Name="@" Type="URL301" Address="https://www.' . $domain . '" MXPref="10" TTL="1800" />';
    }

    private function xmlHosts(string $hosts, bool $usesOurDns): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors /><CommandResponse Type="namecheap.domains.dns.getHosts">
<DomainDNSGetHostsResult Domain="test.com" EmailType="MXE" IsUsingOurDNS="' . ($usesOurDns ? 'true' : 'false') . '">' . $hosts . '</DomainDNSGetHostsResult>
</CommandResponse></ApiResponse>';
    }

    private function xmlSetHosts(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors /><CommandResponse Type="namecheap.domains.dns.setHosts">
<DomainDNSSetHostsResult Domain="test.com" IsSuccess="true" /></CommandResponse></ApiResponse>';
    }

    private function xmlError(string $n, string $m): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="' . $n . '">' . $m . '</Error></Errors></ApiResponse>';
    }
}

/** The custom-hostname provider, faked: no HTTP, state chosen by the test. */
class FakeLinkProvider implements CustomHostnameProvider
{
    public int $createCount = 0;
    public int $deleteCount = 0;
    public bool $makeActive = false;

    public function key(): string { return 'fake'; }

    public function createCustomHostname(string $hostname, array $options = []): CustomHostnameResult
    {
        $this->createCount++;

        return $this->snapshot($hostname);
    }

    public function getCustomHostname(string $providerHostnameId): ?CustomHostnameResult { return $this->snapshot('www.fake.test'); }
    public function listCustomHostnames(string $hostname): array { return [$this->snapshot($hostname)]; }
    public function verifyCustomHostname(string $providerHostnameId): CustomHostnameResult { return $this->snapshot('www.fake.test'); }
    public function deleteCustomHostname(string $providerHostnameId): bool { $this->deleteCount++; return true; }
    public function getValidationInstructions(CustomHostnameResult $result): array
    {
        return [['type' => 'TXT', 'name' => '_cf-custom-hostname.' . $result->hostname, 'value' => 'token', 'purpose' => 'ownership']];
    }
    public function getCertificateStatus(string $providerHostnameId): ?string { return $this->makeActive ? 'active' : 'pending_validation'; }

    private function snapshot(string $hostname): CustomHostnameResult
    {
        return new CustomHostnameResult(
            providerHostnameId: 'cf-link-1',
            hostname: $hostname,
            ownershipStatus: $this->makeActive ? 'active' : 'pending',
            sslStatus: $this->makeActive ? 'active' : 'pending_validation',
            validationMethod: 'txt',
            validationRecords: [],
            active: $this->makeActive,
            errors: [],
            raw: [],
        );
    }
}
