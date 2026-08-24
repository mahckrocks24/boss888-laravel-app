<?php

namespace Tests\Feature\Domains;

use App\Http\Controllers\Api\CustomerDomainController;
use App\Models\CustomerDomain;
use App\Models\DomainOrder;
use App\Models\DomainOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The customer portal surfaces: activity timeline and auto-renew.
 *
 * The timeline is the sharpest test of the customer-safe boundary. Internally
 * the lifecycle of the first real purchase included three registration
 * attempts, a provider error code, a failure and a refund_due flag. A customer
 * must see none of that — only that their domain was ordered, paid for and
 * registered.
 */
class DomainPortalTest extends TestCase
{
    private int $wsA = 992001;
    private int $wsB = 992002;
    private CustomerDomain $domain;
    private DomainOrder $order;
    private DomainOrderItem $item;

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
        ]);

        $this->cleanup();

        $this->order = DomainOrder::create([
            'workspace_id' => $this->wsA, 'status' => DomainOrder::STATUS_COMPLETED,
            'currency' => 'USD', 'subtotal_minor' => 1817, 'total_minor' => 1817,
            'cost_total_minor' => 1398, 'paid_at' => now(),
        ]);

        $this->item = DomainOrderItem::create([
            'domain_order_id' => $this->order->id, 'workspace_id' => $this->wsA,
            'domain' => 'portal-test.com', 'tld' => 'com', 'years' => 1,
            'registrar_cost_minor' => 1398, 'markup_minor' => 419, 'retail_minor' => 1817,
            'status' => DomainOrderItem::STATUS_REGISTERED, 'registered_at' => now(),
        ]);

        $this->domain = CustomerDomain::create([
            'workspace_id' => $this->wsA, 'domain' => 'portal-test.com',
            'domain_order_item_id' => $this->item->id, 'status' => CustomerDomain::STATUS_ACTIVE,
            'registered_at' => now(), 'expires_at' => now()->addYear(),
            'nameservers_json' => ['ns1.example.com', 'ns2.example.com'],
        ]);

        // The full, messy internal history of a real purchase.
        $this->event('domain.order.created', 'domain_order', $this->order->id, 'info');
        $this->event('domain.order.checkout_started', 'domain_order', $this->order->id, 'info');
        $this->event('domain.order.paid', 'domain_order', $this->order->id, 'info');
        $this->event('domain.order.provisioning', 'domain_order', $this->order->id, 'info');
        $this->event('domain.registration.attempt', 'domain_order_item', $this->item->id, 'info');
        $this->event('domain.registration.attempt', 'domain_order_item', $this->item->id, 'info');
        $this->event('domain.registration.failed', 'domain_order_item', $this->item->id, 'error',
            'Registration of portal-test.com failed: 1011102');
        $this->event('domain.registration.refund_due', 'domain_order_item', $this->item->id, 'error',
            'REFUND DUE: portal-test.com was paid for but could not be registered');
        $this->event('domain.registration.attempt', 'domain_order_item', $this->item->id, 'info');
        $this->event('domain.registration.succeeded', 'domain_order_item', $this->item->id, 'info');
        $this->event('domain.synced', 'customer_domain', null, 'info');
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
            DB::table('infra_events')->where('workspace_id', $ws)->delete();
        }
    }

    private function event(string $event, string $ownerType, ?int $ownerId, string $sev, ?string $summary = null): void
    {
        DB::table('infra_events')->insert([
            'workspace_id' => $this->wsA, 'owner_type' => $ownerType, 'owner_id' => $ownerId,
            'event' => $event, 'severity' => $sev, 'source' => 'domain_commerce',
            'provider' => 'namecheap', 'provider_resource_id' => 'portal-test.com',
            'summary' => $summary ?? $event, 'context_json' => json_encode(['error_code' => '1011102']),
            'created_at' => now(),
        ]);
    }

    private function request(int $workspaceId, array $input = []): Request
    {
        $r = Request::create('/x', 'POST', $input);
        $r->attributes->set('workspace_id', $workspaceId);

        return $r;
    }

    private function timeline(int $workspaceId): array
    {
        $res = (new CustomerDomainController())->timeline($this->request($workspaceId), $this->domain->id);

        return ['status' => $res->getStatusCode(), 'json' => json_decode($res->getContent(), true), 'raw' => $res->getContent()];
    }

    // ------------------------------------------------------------ timeline --

    public function test_timeline_returns_the_full_customer_journey(): void
    {
        $t = $this->timeline($this->wsA);

        $this->assertSame(200, $t['status']);
        $this->assertSame('portal-test.com', $t['json']['domain']);
        $this->assertTrue($t['json']['complete']);

        $keys = array_column($t['json']['steps'], 'key');
        $this->assertSame(['ordered', 'paid', 'queued', 'registering', 'registered', 'dns'], $keys,
            'the journey must be presented in order');
    }

    public function test_timeline_collapses_repeated_attempts_into_one_step(): void
    {
        $t = $this->timeline($this->wsA);
        $registering = array_filter($t['json']['steps'], fn ($s) => $s['key'] === 'registering');

        $this->assertCount(1, $registering, 'three internal attempts are one step to a customer');
    }

    public function test_timeline_hides_failures_refunds_and_provider_codes(): void
    {
        $t = $this->timeline($this->wsA);

        foreach (['1011102', 'refund', 'REFUND', 'failed', 'attempt', 'namecheap', 'error_code'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden, $t['raw'], "'{$forbidden}' must never reach a customer timeline"
            );
        }
    }

    public function test_timeline_marks_unreached_steps_as_pending(): void
    {
        // A domain whose journey stopped after payment.
        DB::table('infra_events')->where('workspace_id', $this->wsA)
            ->whereIn('event', ['domain.registration.succeeded', 'domain.synced', 'domain.registration.attempt'])
            ->delete();

        $t = $this->timeline($this->wsA);
        $byKey = collect($t['json']['steps'])->keyBy('key');

        $this->assertSame('done', $byKey['paid']['state']);
        $this->assertSame('pending', $byKey['registered']['state']);
        $this->assertNull($byKey['registered']['at'], 'a pending step has no timestamp');
    }

    public function test_timeline_steps_carry_plain_language(): void
    {
        $t = $this->timeline($this->wsA);
        $labels = array_column($t['json']['steps'], 'label');

        $this->assertContains('Payment received', $labels);
        $this->assertContains('Registration complete', $labels);
        $this->assertContains('DNS configured', $labels);
    }

    // ------------------------------------------------------- authorization --

    public function test_timeline_is_denied_without_a_workspace(): void
    {
        $r = Request::create('/x', 'GET');
        $this->assertSame(403, (new CustomerDomainController())->timeline($r, $this->domain->id)->getStatusCode());
    }

    public function test_timeline_of_another_tenants_domain_is_not_found(): void
    {
        $this->assertSame(404, $this->timeline($this->wsB)['status']);
    }

    public function test_timeline_does_not_leak_events_from_another_workspace(): void
    {
        $other = CustomerDomain::create([
            'workspace_id' => $this->wsB, 'domain' => 'other-tenant.com', 'status' => 'active',
        ]);

        DB::table('infra_events')->insert([
            'workspace_id' => $this->wsB, 'owner_type' => 'customer_domain', 'owner_id' => $other->id,
            'event' => 'domain.order.created', 'severity' => 'info', 'source' => 'domain_commerce',
            'provider_resource_id' => 'other-tenant.com', 'summary' => 'SECRET-OTHER-TENANT',
            'context_json' => '{}', 'created_at' => now(),
        ]);

        $this->assertStringNotContainsString('SECRET-OTHER-TENANT', $this->timeline($this->wsA)['raw']);
    }

    // ----------------------------------------------------------- auto-renew --

    private function fakeRegistrar(bool $ownedAutoRenew, bool $afterAutoRenew): void
    {
        $calls = 0;

        Http::fake(function ($request) use (&$calls, $ownedAutoRenew, $afterAutoRenew) {
            $body = (string) $request->body();

            if (str_contains($body, 'domains.getInfo')) {
                $calls++;
                $ar = $calls === 1 ? $ownedAutoRenew : $afterAutoRenew;

                return Http::response('<?xml version="1.0"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors />
<CommandResponse><DomainGetInfoResult Status="Ok" ID="1" IsOwner="true" AutoRenew="'
                    . ($ar ? 'true' : 'false') . '" IsLocked="false">
<DomainDetails><CreatedDate>07/29/2026</CreatedDate><ExpiredDate>07/29/2027</ExpiredDate></DomainDetails>
<DnsDetails IsUsingOurDNS="true"><Nameserver>ns1.example.com</Nameserver><Nameserver>ns2.example.com</Nameserver></DnsDetails>
</DomainGetInfoResult></CommandResponse></ApiResponse>', 200);
            }

            return Http::response('<?xml version="1.0"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><Errors />
<CommandResponse /></ApiResponse>', 200);
        });
    }

    public function test_auto_renew_confirmed_when_the_registrar_applies_it(): void
    {
        $this->fakeRegistrar(false, true);

        $res = (new CustomerDomainController())->setAutoRenew($this->request($this->wsA, ['enabled' => true]), $this->domain->id);
        $j = json_decode($res->getContent(), true);

        $this->assertTrue($j['ok']);
        $this->assertTrue($j['auto_renew']);
        $this->assertTrue($j['confirmed']);
        $this->assertSame('Auto-renew is on.', $j['message']);
        $this->assertTrue((bool) $this->domain->fresh()->auto_renew, 'local state follows the registrar');
    }

    /**
     * The registrar returned OK but the setting did not change — observed for
     * real on 2026-07-29. The customer must NOT be told it is done.
     */
    public function test_auto_renew_is_reported_as_unconfirmed_when_the_registrar_does_not_apply_it(): void
    {
        $this->fakeRegistrar(false, false);

        $res = (new CustomerDomainController())->setAutoRenew($this->request($this->wsA, ['enabled' => true]), $this->domain->id);
        $j = json_decode($res->getContent(), true);

        $this->assertTrue($j['ok']);
        $this->assertFalse($j['confirmed'], 'an unverified write must never be reported as confirmed');
        $this->assertFalse($j['auto_renew'], 'the reported state must be the ACTUAL state');
        $this->assertStringContainsString('still being applied', $j['message']);
        $this->assertFalse((bool) $this->domain->fresh()->auto_renew);
    }

    public function test_auto_renew_already_set_is_a_no_op(): void
    {
        $this->fakeRegistrar(true, true);

        $res = (new CustomerDomainController())->setAutoRenew($this->request($this->wsA, ['enabled' => true]), $this->domain->id);
        $j = json_decode($res->getContent(), true);

        $this->assertTrue($j['ok']);
        $this->assertTrue($j['confirmed']);
    }

    public function test_auto_renew_is_denied_without_a_workspace(): void
    {
        $r = Request::create('/x', 'POST', ['enabled' => true]);
        $this->assertSame(403, (new CustomerDomainController())->setAutoRenew($r, $this->domain->id)->getStatusCode());
    }

    public function test_auto_renew_on_another_tenants_domain_is_not_found(): void
    {
        Http::fake();

        $res = (new CustomerDomainController())->setAutoRenew($this->request($this->wsB, ['enabled' => true]), $this->domain->id);

        $this->assertSame(404, $res->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_auto_renew_failure_returns_a_customer_safe_message(): void
    {
        Http::fake(function () {
            return Http::response('<?xml version="1.0"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response">
<Errors><Error Number="1011102">API Key is invalid or API access has not been enabled</Error></Errors>
</ApiResponse>', 200);
        });

        $res = (new CustomerDomainController())->setAutoRenew($this->request($this->wsA, ['enabled' => true]), $this->domain->id);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringNotContainsString('1011102', $res->getContent());
        $this->assertStringNotContainsString('API Key', $res->getContent());
    }

    // ------------------------------------------------------------- payload --

    public function test_domain_detail_payload_is_customer_safe(): void
    {
        $r = Request::create('/x', 'GET');
        $r->attributes->set('workspace_id', $this->wsA);

        $body = (new CustomerDomainController())->show($r, $this->domain->id)->getContent();

        foreach (['INFRA888', 'BOSS888', 'namecheap', 'Namecheap', 'cost_minor', 'markup', 'registrar_cost'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }

        $this->assertStringContainsString('LevelUp Growth', $body);
        $this->assertStringContainsString('support@levelupgrowth.io', $body);
    }
}
