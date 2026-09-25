<?php

namespace Tests\Feature\Domains;

use App\Jobs\RegisterDomainJob;
use App\Models\CustomerDomain;
use App\Models\DomainOrder;
use App\Models\DomainOrderItem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * RISK-0202 (2026-09-25): two runners on one registration item. The loser must never write a refund on a
 * domain the winner registered; a runner must not take an item another one is working on.
 */
class RegisterDomainJobRaceTest extends TestCase
{
    private int $ws = 991501;
    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'namecheap.environment' => 'sandbox',
            'namecheap.client_ip'   => '134.209.93.41',
            'namecheap.endpoints.sandbox' => 'https://api.sandbox.namecheap.com/xml.response',
            'namecheap.credentials.sandbox' => ['api_user' => 'testuser', 'api_key' => 'test-key-fake', 'username' => 'testuser'],
            'domains.auto_dns.enabled' => false,
        ]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'Race Fixture', 'email' => 'race-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'Race WS', 'slug' => 'race-' . uniqid(), 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        CustomerDomain::where('workspace_id', $this->ws)->delete();
        DomainOrderItem::where('workspace_id', $this->ws)->delete();
        DomainOrder::where('workspace_id', $this->ws)->delete();
        DB::table('infra_events')->where('workspace_id', $this->ws)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        if ($this->uid) { DB::table('users')->where('id', $this->uid)->delete(); }
    }

    private function paidItem(string $domain, string $status = DomainOrderItem::STATUS_PENDING): DomainOrderItem
    {
        $order = DomainOrder::create(['workspace_id' => $this->ws, 'user_id' => null, 'status' => DomainOrder::STATUS_PROVISIONING, 'currency' => 'USD',
            'subtotal_minor' => 1817, 'tax_minor' => 0, 'total_minor' => 1817, 'cost_total_minor' => 1398, 'paid_at' => now(), 'metadata_json' => []]);

        return DomainOrderItem::create(['domain_order_id' => $order->id, 'workspace_id' => $this->ws, 'domain' => $domain, 'tld' => 'com', 'years' => 1,
            'action' => 'register', 'provider' => 'namecheap', 'registrar_cost_minor' => 1398, 'markup_minor' => 419, 'retail_minor' => 1817, 'currency' => 'USD',
            'is_premium' => false, 'status' => $status, 'attempts' => $status === DomainOrderItem::STATUS_REGISTERING ? 1 : 0]);
    }

    /** getInfo answers "not yours" until create has been attempted, then $ownedAfter decides. */
    private function fakeRegistrar(bool $createOk, bool $ownedAfter): void
    {
        $attempted = false;
        Http::fake(function ($request) use ($createOk, $ownedAfter, &$attempted) {
            $body = (string) $request->body();
            if (str_contains($body, 'domains.getInfo')) {
                return Http::response(($attempted && $ownedAfter) ? $this->xmlOwned() : $this->xmlNotOwned(), 200);
            }
            if (str_contains($body, 'domains.create')) {
                $attempted = true;
                return Http::response($createOk ? $this->xmlCreated() : $this->xmlError('3031166', 'Domain name not available'), 200);
            }
            return Http::response($this->xmlError('2011170', 'Unexpected command in test'), 200);
        });
    }

    public function test_the_job_is_unique_per_item_on_the_queue(): void
    {
        $this->assertContains(ShouldBeUnique::class, class_implements(RegisterDomainJob::class));
        $this->assertSame('register-domain-42', (new RegisterDomainJob(42))->uniqueId());
    }

    public function test_an_item_another_runner_is_registering_is_left_alone(): void
    {
        $this->fakeRegistrar(true, true);
        $item = $this->paidItem('busy.com', DomainOrderItem::STATUS_REGISTERING);   // updated_at = now

        (new RegisterDomainJob($item->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(DomainOrderItem::STATUS_REGISTERING, $item->fresh()->status);
        $this->assertSame(1, (int) $item->fresh()->attempts, 'no second attempt');
    }

    public function test_a_dead_runners_item_is_taken_over(): void
    {
        $this->fakeRegistrar(true, true);
        $item = $this->paidItem('stale.com', DomainOrderItem::STATUS_REGISTERING);
        DB::table('domain_order_items')->where('id', $item->id)->update(['updated_at' => now()->subMinutes(15)]);

        (new RegisterDomainJob($item->id))->handle();

        $this->assertSame(DomainOrderItem::STATUS_REGISTERED, $item->fresh()->status);
        $this->assertSame(2, (int) $item->fresh()->attempts);
    }

    public function test_not_available_after_our_own_registration_is_a_success_not_a_refund(): void
    {
        $this->fakeRegistrar(false, true);   // create says "not available"; the account then says "yours"
        $item = $this->paidItem('raced.com');

        (new RegisterDomainJob($item->id))->handle();

        $fresh = $item->fresh();
        $this->assertSame(DomainOrderItem::STATUS_REGISTERED, $fresh->status);
        $this->assertNull($fresh->last_error);
        $this->assertSame('3031166', $fresh->provider_metadata_json['recovered_from'] ?? null);
        $this->assertTrue($fresh->provider_metadata_json['idempotent_replay'] ?? false);
        $this->assertSame(1, CustomerDomain::where('domain', 'raced.com')->count(), 'ownership row written');
        $this->assertSame(DomainOrder::STATUS_COMPLETED, $fresh->order->fresh()->status);
        $this->assertSame(0, DB::table('infra_events')->where('workspace_id', $this->ws)->where('event', 'domain.registration.refund_due')->count(), 'no refund on a delivered domain');
        $this->assertSame(1, DB::table('infra_events')->where('workspace_id', $this->ws)->where('event', 'domain.registration.succeeded')->count());
    }

    public function test_a_genuine_refusal_is_still_a_refund(): void
    {
        $this->fakeRegistrar(false, false);   // create fails and the account does not hold it
        $item = $this->paidItem('taken.com');

        (new RegisterDomainJob($item->id))->handle();

        $this->assertSame(DomainOrderItem::STATUS_REFUND_DUE, $item->fresh()->status);
        $this->assertSame(0, CustomerDomain::where('domain', 'taken.com')->count());
        $this->assertSame(1, DB::table('infra_events')->where('workspace_id', $this->ws)->where('event', 'domain.registration.refund_due')->count());
    }

    // ---------------------------------------------------------------- XML --

    private function xmlNotOwned(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="2030166">Domain is invalid</Error></Errors>
<CommandResponse Type="namecheap.domains.getInfo"><DomainGetInfoResult ID="0" IsOwner="false" IsPremium="false"><DomainDetails><NumYears>0</NumYears></DomainDetails>
<DnsDetails IsUsingOurDNS="false" HostCount="0" /></DomainGetInfoResult></CommandResponse></ApiResponse>';
    }

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

    private function xmlError(string $n, string $m): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response"><Errors><Error Number="' . $n . '">' . $m . '</Error></Errors></ApiResponse>';
    }
}
