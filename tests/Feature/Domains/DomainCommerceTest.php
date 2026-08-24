<?php

namespace Tests\Feature\Domains;

use App\Http\Controllers\Api\CustomerDomainController;
use App\Jobs\RegisterDomainJob;
use App\Models\CustomerDomain;
use App\Models\DomainOrder;
use App\Models\DomainOrderItem;
use App\Services\Domains\DomainCommerceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Domain commerce: search -> cart -> order -> payment -> registration.
 *
 * Namecheap is faked at the HTTP boundary with responses captured verbatim
 * from the live sandbox, so these tests exercise the real parsing and
 * classification code without spending money or needing network access.
 */
class DomainCommerceTest extends TestCase
{
    private int $wsA = 991001;
    private int $wsB = 991002;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'namecheap.environment' => 'sandbox',
            'namecheap.client_ip'   => '134.209.93.41',
            'namecheap.endpoints.sandbox' => 'https://api.sandbox.namecheap.com/xml.response',
            'namecheap.credentials.sandbox' => [
                'api_user' => 'testuser', 'api_key' => 'test-key-fake', 'username' => 'testuser',
            ],
            'namecheap.pricing.markup_percent'     => 30.0,
            'namecheap.pricing.markup_minimum_usd' => 4.00,
        ]);

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach ([$this->wsA, $this->wsB] as $ws) {
            CustomerDomain::where('workspace_id', $ws)->delete();
            DomainOrderItem::where('workspace_id', $ws)->delete();
            DomainOrder::where('workspace_id', $ws)->delete();
        }
        DB::table('infra_events')->where('workspace_id', $this->wsA)->delete();
        DB::table('infra_events')->where('workspace_id', $this->wsB)->delete();
    }

    // ------------------------------------------------------- fake provider --

    private function fakeAvailableAndPriced(bool $available = true): void
    {
        Http::fake(function ($request) use ($available) {
            $body = (string) $request->body();

            if (str_contains($body, 'domains.check')) {
                return Http::response($this->xmlCheck($available), 200);
            }

            if (str_contains($body, 'users.getPricing')) {
                return Http::response($this->xmlPricing(), 200);
            }

            if (str_contains($body, 'domains.getInfo')) {
                return Http::response($this->xmlNotOwned(), 200);
            }

            if (str_contains($body, 'domains.create')) {
                return Http::response($this->xmlCreated(), 200);
            }

            return Http::response($this->xmlError('2011170', 'Unexpected command in test'), 200);
        });
    }

    private function xmlCheck(bool $available): string
    {
        $a = $available ? 'true' : 'false';

        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <Errors /><CommandResponse Type="namecheap.domains.check">
    <DomainCheckResult Domain="test.com" Available="' . $a . '" IsPremiumName="false" IcannFee="0.18" />
  </CommandResponse></ApiResponse>';
    }

    private function xmlPricing(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <Errors /><CommandResponse Type="namecheap.users.getPricing">
    <UserGetPricingResult><ProductType Name="domains"><ProductCategory Name="register">
      <Product Name="com">
        <Price Duration="1" DurationType="YEAR" Price="13.98" RegularPrice="13.98" YourPrice="13.98" Currency="USD" />
        <Price Duration="3" DurationType="YEAR" Price="41.24" RegularPrice="41.24" YourPrice="41.24" Currency="USD" />
      </Product>
    </ProductCategory></ProductType></UserGetPricingResult>
  </CommandResponse></ApiResponse>';
    }

    private function xmlNotOwned(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response">
  <Errors><Error Number="2030166">Domain is invalid</Error></Errors>
  <CommandResponse Type="namecheap.domains.getInfo">
    <DomainGetInfoResult ID="0" IsOwner="false" IsPremium="false"><DomainDetails><NumYears>0</NumYears></DomainDetails>
    <DnsDetails IsUsingOurDNS="false" HostCount="0" /></DomainGetInfoResult>
  </CommandResponse></ApiResponse>';
    }

    private function xmlCreated(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <Errors /><CommandResponse Type="namecheap.domains.create">
    <DomainCreateResult Domain="test.com" Registered="true" ChargedAmount="14.18" DomainID="99"
      OrderID="777001" TransactionID="888001" WhoisguardEnable="true" FreePositiveSSL="false" />
  </CommandResponse></ApiResponse>';
    }

    private function xmlError(string $n, string $m): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response">
  <Errors><Error Number="' . $n . '">' . $m . '</Error></Errors></ApiResponse>';
    }

    // ================================================================ UNIT ==

    public function test_unit_markup_is_percentage_of_cost(): void
    {
        $this->fakeAvailableAndPriced();

        $q = \App\Services\Domains\DomainPricingService::make()->quoteRegistration('unit-test.com', 1);

        $this->assertSame(1398, $q['cost_minor']);
        $this->assertSame(419, $q['markup_minor'], '30% of 1398 = 419');
        $this->assertSame(1817, $q['retail_minor']);
        $this->assertSame('exclusive', $q['tax_treatment']);
    }

    public function test_unit_markup_floor_applies_to_cheap_domains(): void
    {
        config(['namecheap.pricing.markup_percent' => 5.0]);
        $this->fakeAvailableAndPriced();

        $q = \App\Services\Domains\DomainPricingService::make()->quoteRegistration('cheap.com', 1);

        // 5% of 1398 = 70, below the 400 floor, so the floor wins.
        $this->assertSame(400, $q['markup_minor']);
        $this->assertStringContainsString('flat minimum', $q['markup_basis']);
    }

    public function test_unit_order_status_derives_from_items(): void
    {
        $order = $this->makeOrder($this->wsA, ['a-unit.com', 'b-unit.com']);

        $this->assertSame(DomainOrder::STATUS_PROVISIONING, $order->recomputeStatus());

        $order->items()->first()->update(['status' => DomainOrderItem::STATUS_REGISTERED]);
        $this->assertSame(DomainOrder::STATUS_PROVISIONING, $order->fresh()->recomputeStatus());

        $order->items()->get()->each(fn ($i) => $i->update(['status' => DomainOrderItem::STATUS_REGISTERED]));
        $this->assertSame(DomainOrder::STATUS_COMPLETED, $order->fresh()->recomputeStatus());
    }

    public function test_unit_partial_completion_is_reported_as_partial(): void
    {
        $order = $this->makeOrder($this->wsA, ['ok-unit.com', 'bad-unit.com']);
        $items = $order->items()->orderBy('id')->get();
        $items[0]->update(['status' => DomainOrderItem::STATUS_REGISTERED]);
        $items[1]->update(['status' => DomainOrderItem::STATUS_REFUND_DUE]);

        $this->assertSame(DomainOrder::STATUS_PARTIAL, $order->fresh()->recomputeStatus());
    }

    public function test_unit_registrar_idempotency_key_is_deterministic(): void
    {
        $order = $this->makeOrder($this->wsA, ['idem-unit.com']);
        $item = $order->items()->first();

        $this->assertSame($item->registrarIdempotencyKey(), $item->fresh()->registrarIdempotencyKey());
        $this->assertStringContainsString((string) $item->id, $item->registrarIdempotencyKey());
    }

    public function test_unit_expiry_helpers(): void
    {
        $d = new CustomerDomain(['domain' => 'x.com', 'expires_at' => now()->addDays(10), 'status' => CustomerDomain::STATUS_ACTIVE]);

        $this->assertSame(10, $d->daysUntilExpiry());
        $this->assertTrue($d->isExpiringSoon(30));
        $this->assertFalse($d->isExpiringSoon(5));
        $this->assertSame('Expiring soon', $d->customerStatusLabel());
    }

    // ============================================================= FEATURE ==

    public function test_feature_search_returns_price_and_period(): void
    {
        $this->fakeAvailableAndPriced();

        $r = DomainCommerceService::make()->search('feature-search.com', 1);

        $this->assertTrue($r['available']);
        $this->assertSame('$18.17', $r['retail']);
        $this->assertSame(1, $r['registration_period']['years']);
        $this->assertArrayHasKey('renewal', $r);
        $this->assertFalse($r['transfer_eligible']);
    }

    public function test_feature_taken_domain_has_no_price(): void
    {
        $this->fakeAvailableAndPriced(available: false);

        $r = DomainCommerceService::make()->search('taken.com', 1);

        $this->assertFalse($r['available']);
        $this->assertArrayNotHasKey('retail_minor', $r);
    }

    public function test_feature_order_stores_cost_markup_and_retail(): void
    {
        $this->fakeAvailableAndPriced();

        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [['domain' => 'store-me.com', 'years' => 1]]);

        $item = DomainOrderItem::find($r['order']['items'][0]['id']);
        $this->assertSame(1398, $item->registrar_cost_minor);
        $this->assertSame(419, $item->markup_minor);
        $this->assertSame(1817, $item->retail_minor);
        $this->assertSame('USD', $item->currency);
        $this->assertSame('namecheap', $item->provider);
    }

    public function test_feature_cart_supports_multiple_domains_and_terms(): void
    {
        $this->fakeAvailableAndPriced();

        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [
            ['domain' => 'multi-one.com', 'years' => 1],
            ['domain' => 'multi-two.com', 'years' => 3],
        ]);

        $this->assertCount(2, $r['order']['items']);
        $years = DomainOrderItem::where('domain_order_id', $r['order']['id'])->pluck('years', 'domain');
        $this->assertSame(1, (int) $years['multi-one.com']);
        $this->assertSame(3, (int) $years['multi-two.com']);
    }

    public function test_feature_unavailable_domain_is_rejected_not_ordered(): void
    {
        $this->fakeAvailableAndPriced(available: false);

        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [['domain' => 'nope.com']]);

        $this->assertArrayHasKey('error', $r);
        $this->assertSame('NONE_AVAILABLE', $r['code']);
        $this->assertSame(0, DomainOrder::where('workspace_id', $this->wsA)->count());
    }

    public function test_feature_duplicate_domains_in_one_cart_are_collapsed(): void
    {
        $this->fakeAvailableAndPriced();

        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [
            ['domain' => 'dupe.com'], ['domain' => 'DUPE.com'], ['domain' => 'dupe.com'],
        ]);

        $this->assertCount(1, $r['order']['items']);
    }

    public function test_feature_oversized_cart_is_refused(): void
    {
        $cart = [];
        for ($i = 0; $i < DomainCommerceService::MAX_ITEMS_PER_ORDER + 1; $i++) {
            $cart[] = ['domain' => "bulk{$i}.com"];
        }

        $r = DomainCommerceService::make()->createOrder($this->wsA, null, $cart);

        $this->assertSame('CART_TOO_LARGE', $r['code']);
    }

    // ========================================================= IDEMPOTENCY ==

    public function test_idempotency_same_key_returns_the_same_order(): void
    {
        $this->fakeAvailableAndPriced();
        $svc = DomainCommerceService::make();

        $a = $svc->createOrder($this->wsA, null, [['domain' => 'idem-key.com']], 'KEY-1');
        $b = $svc->createOrder($this->wsA, null, [['domain' => 'idem-key.com']], 'KEY-1');

        $this->assertSame($a['order']['id'], $b['order']['id']);
        $this->assertTrue($b['idempotent_replay']);
        $this->assertSame(1, DomainOrder::where('workspace_id', $this->wsA)->count());
    }

    public function test_idempotency_duplicate_webhook_does_not_reprovision(): void
    {
        Queue::fake();
        $order = $this->makeOrder($this->wsA, ['webhook-dupe.com']);
        $order->update(['stripe_session_id' => 'cs_test_dupe', 'status' => DomainOrder::STATUS_AWAITING_PAYMENT]);

        $svc = DomainCommerceService::make();

        $first = $svc->markPaidAndProvision('cs_test_dupe', 'pi_1');
        $second = $svc->markPaidAndProvision('cs_test_dupe', 'pi_1');

        $this->assertSame('marked_paid', $first['action']);
        $this->assertSame('already_processed', $second['action']);
        Queue::assertPushed(RegisterDomainJob::class, 1);
    }

    public function test_idempotency_unknown_session_is_ignored(): void
    {
        $r = DomainCommerceService::make()->markPaidAndProvision('cs_test_does_not_exist');

        $this->assertFalse($r['handled']);
    }

    public function test_idempotency_registration_replay_does_not_create_a_second_domain(): void
    {
        // Provider reports the domain is ALREADY in the account.
        Http::fake(function ($request) {
            if (str_contains((string) $request->body(), 'domains.getInfo')) {
                return Http::response('<?xml version="1.0"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors />
<CommandResponse><DomainGetInfoResult Status="Ok" ID="5" IsOwner="true" AutoRenew="false" IsLocked="false">
<DomainDetails><CreatedDate>07/29/2026</CreatedDate><ExpiredDate>07/29/2027</ExpiredDate></DomainDetails>
<DnsDetails IsUsingOurDNS="true"><Nameserver>ns1.x.com</Nameserver><Nameserver>ns2.x.com</Nameserver></DnsDetails>
</DomainGetInfoResult></CommandResponse></ApiResponse>', 200);
            }

            return Http::response($this->xmlCreated(), 200);
        });

        $order = $this->makeOrder($this->wsA, ['replay-safe.com']);
        $order->update(['paid_at' => now(), 'status' => DomainOrder::STATUS_PROVISIONING]);
        $item = $order->items()->first();

        (new RegisterDomainJob($item->id))->handle();
        (new RegisterDomainJob($item->id))->handle();

        $this->assertSame(1, CustomerDomain::where('domain', 'replay-safe.com')->count());
    }

    // ================================================================ QUEUE ==

    public function test_queue_payment_dispatches_one_job_per_item(): void
    {
        Queue::fake();
        $order = $this->makeOrder($this->wsA, ['q1.com', 'q2.com', 'q3.com']);
        $order->update(['stripe_session_id' => 'cs_q', 'status' => DomainOrder::STATUS_AWAITING_PAYMENT]);

        DomainCommerceService::make()->markPaidAndProvision('cs_q', 'pi_q');

        Queue::assertPushed(RegisterDomainJob::class, 3);
    }

    public function test_queue_job_refuses_to_register_an_unpaid_order(): void
    {
        Http::fake();
        $order = $this->makeOrder($this->wsA, ['unpaid.com']);   // paid_at stays null
        $item = $order->items()->first();

        (new RegisterDomainJob($item->id))->handle();

        $this->assertSame(DomainOrderItem::STATUS_FAILED, $item->fresh()->status);
        $this->assertSame('NOT_PAID', $item->fresh()->last_error_code);
        $this->assertSame(0, CustomerDomain::where('domain', 'unpaid.com')->count());
        Http::assertNothingSent();
    }

    public function test_queue_job_is_a_noop_when_item_already_registered(): void
    {
        Http::fake();
        $order = $this->makeOrder($this->wsA, ['already.com']);
        $order->update(['paid_at' => now()]);
        $item = $order->items()->first();
        $item->update(['status' => DomainOrderItem::STATUS_REGISTERED]);

        (new RegisterDomainJob($item->id))->handle();

        Http::assertNothingSent();
    }

    public function test_queue_permanent_provider_failure_flags_refund_due(): void
    {
        Http::fake(function ($request) {
            if (str_contains((string) $request->body(), 'domains.getInfo')) {
                return Http::response($this->xmlNotOwned(), 200);
            }

            return Http::response($this->xmlError('2033409', 'Possibly a taken domain'), 200);
        });

        $order = $this->makeOrder($this->wsA, ['will-fail.com']);
        $order->update(['paid_at' => now()]);
        $item = $order->items()->first();

        (new RegisterDomainJob($item->id))->handle();

        $this->assertSame(DomainOrderItem::STATUS_REFUND_DUE, $item->fresh()->status);
        $this->assertSame(DomainOrder::STATUS_FAILED, $order->fresh()->status);
    }

    // ============================================================== BILLING ==

    public function test_billing_checkout_requires_an_unpaid_order(): void
    {
        $order = $this->makeOrder($this->wsA, ['paid-already.com']);
        $order->update(['paid_at' => now()]);

        $r = DomainCommerceService::make()->startCheckout($order);

        $this->assertSame('ALREADY_PAID', $r['code']);
    }

    public function test_billing_order_totals_equal_the_sum_of_items(): void
    {
        $this->fakeAvailableAndPriced();

        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [
            ['domain' => 'sum-one.com'], ['domain' => 'sum-two.com'],
        ]);

        $order = DomainOrder::find($r['order']['id']);
        $this->assertSame((int) $order->items->sum('retail_minor'), (int) $order->total_minor);
        $this->assertSame((int) $order->items->sum('registrar_cost_minor'), (int) $order->cost_total_minor);
        $this->assertSame($order->marginMinor(), (int) $order->items->sum('markup_minor'));
    }

    public function test_billing_margin_is_recorded_for_audit(): void
    {
        $this->fakeAvailableAndPriced();
        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [['domain' => 'margin.com']]);
        $order = DomainOrder::find($r['order']['id']);

        $this->assertSame(419, $order->marginMinor());
    }

    // ========================================================= MULTI-TENANT ==

    public function test_multitenant_orders_are_scoped_to_their_workspace(): void
    {
        $this->fakeAvailableAndPriced();
        $svc = DomainCommerceService::make();

        $a = $svc->createOrder($this->wsA, null, [['domain' => 'tenant-a.com']]);
        $b = $svc->createOrder($this->wsB, null, [['domain' => 'tenant-b.com']]);

        $this->assertNotNull(DomainOrder::forWorkspace($this->wsA)->find($a['order']['id']));
        $this->assertNull(DomainOrder::forWorkspace($this->wsB)->find($a['order']['id']));
        $this->assertNull(DomainOrder::forWorkspace($this->wsA)->find($b['order']['id']));
    }

    public function test_multitenant_owned_domains_do_not_leak_across_workspaces(): void
    {
        CustomerDomain::create(['workspace_id' => $this->wsA, 'domain' => 'owned-a.com', 'status' => 'active']);
        CustomerDomain::create(['workspace_id' => $this->wsB, 'domain' => 'owned-b.com', 'status' => 'active']);

        $this->assertSame(1, CustomerDomain::forWorkspace($this->wsA)->count());
        $this->assertSame('owned-a.com', CustomerDomain::forWorkspace($this->wsA)->first()->domain);
        $this->assertSame(0, CustomerDomain::forWorkspace($this->wsA)->where('domain', 'owned-b.com')->count());
    }

    public function test_multitenant_idempotency_key_from_another_tenant_is_not_disclosed(): void
    {
        $this->fakeAvailableAndPriced();
        $svc = DomainCommerceService::make();

        $svc->createOrder($this->wsA, null, [['domain' => 'secret-a.com']], 'SHARED-KEY');
        $r = $svc->createOrder($this->wsB, null, [['domain' => 'secret-b.com']], 'SHARED-KEY');

        // Tenant B must not receive tenant A's order just by guessing the key.
        $this->assertSame('NOT_FOUND', $r['code'] ?? null);
    }

    // ======================================================= AUTHORIZATION ==

    public function test_authorization_request_without_workspace_is_refused(): void
    {
        $c = new CustomerDomainController();
        $req = Request::create('/api/domains', 'GET');   // no workspace attribute

        $this->assertSame(403, $c->index($req)->getStatusCode());
        $this->assertSame(403, $c->search($req)->getStatusCode());
        $this->assertSame(403, $c->orders($req)->getStatusCode());
    }

    public function test_authorization_cannot_read_another_tenants_order(): void
    {
        $this->fakeAvailableAndPriced();
        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [['domain' => 'cross-read.com']]);

        $req = Request::create('/api/domains/orders/' . $r['order']['id'], 'GET');
        $req->attributes->set('workspace_id', $this->wsB);

        $this->assertSame(404, (new CustomerDomainController())->order($req, $r['order']['id'])->getStatusCode());
    }

    public function test_authorization_cannot_checkout_another_tenants_order(): void
    {
        $this->fakeAvailableAndPriced();
        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [['domain' => 'cross-pay.com']]);

        $req = Request::create('/x', 'POST');
        $req->attributes->set('workspace_id', $this->wsB);

        $this->assertSame(404, (new CustomerDomainController())->checkout($req, $r['order']['id'])->getStatusCode());
    }

    public function test_authorization_cannot_sync_another_tenants_domain(): void
    {
        $d = CustomerDomain::create(['workspace_id' => $this->wsA, 'domain' => 'cross-sync.com', 'status' => 'active']);

        $req = Request::create('/x', 'POST');
        $req->attributes->set('workspace_id', $this->wsB);

        $this->assertSame(404, (new CustomerDomainController())->sync($req, $d->id)->getStatusCode());
    }

    // ============================================================== BRAND ==

    public function test_customer_payload_never_exposes_cost_markup_or_provider(): void
    {
        CustomerDomain::create([
            'workspace_id' => $this->wsA, 'domain' => 'brand-check.com', 'status' => 'active',
            'provider' => 'namecheap', 'nameservers_json' => ['ns1.x.com'],
        ]);

        $req = Request::create('/api/domains', 'GET');
        $req->attributes->set('workspace_id', $this->wsA);

        $json = (new CustomerDomainController())->index($req)->getContent();

        foreach (['INFRA888', 'BOSS888', 'namecheap', 'Namecheap', 'cost_minor', 'markup', 'registrar_cost'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "'{$forbidden}' must never reach a customer");
        }

        $this->assertStringContainsString('LevelUp Growth', $json);
    }

    public function test_admin_payload_does_expose_commercial_internals(): void
    {
        $this->fakeAvailableAndPriced();
        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [['domain' => 'admin-view.com']]);
        $order = DomainOrder::find($r['order']['id']);

        $admin = DomainCommerceService::make()->presentOrderForAdmin($order);

        // An operator cannot diagnose margin problems without these.
        $this->assertArrayHasKey('cost_total', $admin);
        $this->assertArrayHasKey('margin', $admin);
        $this->assertArrayHasKey('registrar_cost', $admin['items'][0]);
    }

    // ============================================================== AUDIT ==

    public function test_audit_trail_is_written_for_the_lifecycle(): void
    {
        Queue::fake();
        $this->fakeAvailableAndPriced();

        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [['domain' => 'audited.com']]);
        $order = DomainOrder::find($r['order']['id']);
        $order->update(['stripe_session_id' => 'cs_audit', 'status' => DomainOrder::STATUS_AWAITING_PAYMENT]);
        DomainCommerceService::make()->markPaidAndProvision('cs_audit', 'pi_audit');

        $events = DB::table('infra_events')->where('workspace_id', $this->wsA)->pluck('event')->all();

        $this->assertContains('domain.order.created', $events);
        $this->assertContains('domain.order.paid', $events);
        $this->assertContains('domain.order.provisioning', $events);
    }

    public function test_audit_records_never_contain_credentials(): void
    {
        Queue::fake();
        $this->fakeAvailableAndPriced();

        $r = DomainCommerceService::make()->createOrder($this->wsA, null, [['domain' => 'no-secrets.com']]);

        $blob = DB::table('infra_events')->where('workspace_id', $this->wsA)->pluck('context_json')->implode("\n");

        $this->assertStringNotContainsString('test-key-fake', $blob);
        $this->assertStringNotContainsString('ApiKey', $blob);
    }

    // ------------------------------------------------------------- helpers --

    private function makeOrder(int $workspaceId, array $domains): DomainOrder
    {
        $order = DomainOrder::create([
            'workspace_id'     => $workspaceId,
            'status'           => DomainOrder::STATUS_PENDING,
            'currency'         => 'USD',
            'subtotal_minor'   => 1817 * count($domains),
            'total_minor'      => 1817 * count($domains),
            'cost_total_minor' => 1398 * count($domains),
        ]);

        foreach ($domains as $d) {
            DomainOrderItem::create([
                'domain_order_id'      => $order->id,
                'workspace_id'         => $workspaceId,
                'domain'               => $d,
                'tld'                  => 'com',
                'years'                => 1,
                'registrar_cost_minor' => 1398,
                'markup_minor'         => 419,
                'retail_minor'         => 1817,
                'status'               => DomainOrderItem::STATUS_PENDING,
            ]);
        }

        return $order->fresh('items');
    }
}
