<?php

namespace Tests\Feature\Domains;

use App\Models\CustomDomain;
use App\Services\CustomDomainService;
use App\Services\Domains\Contracts\CustomHostnameProvider;
use App\Services\Domains\CustomHostnameResult;
use App\Services\Domains\ProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Orchestration tests for the Cloudflare-for-SaaS custom-domain lifecycle,
 * driven by a fake provider (no HTTP). Covers the production gate, idempotency,
 * compensating cleanup, apex rejection, verification, and disconnect.
 */
class CustomDomainServiceTest extends TestCase
{
    use RefreshDatabase;

    private function website(): int
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name'       => 'Domain Fixture',
            'email'      => 'domain-fixture-'.uniqid().'@example.test',
            'password'   => bcrypt('x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ws = (int) DB::table('workspaces')->insertGetId([
            'name'       => 'Domain Fixture WS',
            'slug'       => 'domain-fixture-'.uniqid(),
            'created_by' => $uid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return (int) DB::table('websites')->insertGetId([
            'workspace_id' => $ws,
            'name'         => 'QA Site',
            'subdomain'    => 'qa-site.levelupgrowth.io',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function openGate(): void { config(['cloudflare.saas_enabled' => true]); }
    private function closeGate(): void { config(['cloudflare.saas_enabled' => false]); }

    public function test_connect_is_gated_when_saas_disabled(): void
    {
        $this->closeGate();
        $id = $this->website();
        $fake = new FakeHostnameProvider();
        $svc = new CustomDomainService($fake);

        $res = $svc->connect($id, 'www.acme.com');

        $this->assertFalse($res['success']);
        $this->assertTrue($res['gated']);
        $this->assertSame(0, $fake->createCount, 'gate must not create a provider hostname');
        $this->assertDatabaseCount('custom_domains', 0);
        $this->assertNotEmpty($res['preview']); // routing CNAME preview still shown
    }

    public function test_connect_creates_hostname_and_persists_when_gate_open(): void
    {
        $this->openGate();
        $id = $this->website();
        $fake = new FakeHostnameProvider();
        $svc = new CustomDomainService($fake);

        $res = $svc->connect($id, 'WWW.Acme.com/'); // normalization: lowercase + strip trailing slash

        $this->assertTrue($res['success']);
        $this->assertTrue($res['connected']);
        $this->assertSame('www.acme.com', $res['hostname']);
        $this->assertSame(CustomDomain::STATE_AWAITING_DNS, $res['state']);
        $this->assertSame(1, $fake->createCount);

        $row = CustomDomain::withoutGlobalScopes()->where('website_id', $id)->first();
        $this->assertSame('cf-hostname-1', $row->provider_hostname_id, 'provider id persisted immediately');
        $this->assertSame('www.acme.com', DB::table('websites')->where('id', $id)->value('custom_domain'));
        $this->assertNotEmpty($res['records']); // ownership/ssl instructions present
    }

    public function test_connect_is_idempotent_for_same_hostname(): void
    {
        $this->openGate();
        $id = $this->website();
        $fake = new FakeHostnameProvider();
        $svc = new CustomDomainService($fake);

        $svc->connect($id, 'www.acme.com');
        $svc->connect($id, 'www.acme.com');

        $this->assertSame(1, $fake->createCount, 'repeat connect must not create a duplicate');
        $this->assertSame(1, CustomDomain::withoutGlobalScopes()->where('website_id', $id)->count());
    }

    public function test_apex_domain_is_rejected_with_guidance(): void
    {
        $this->openGate();
        $id = $this->website();
        $svc = new CustomDomainService(new FakeHostnameProvider());

        $res = $svc->connect($id, 'acme.com');

        $this->assertFalse($res['success']);
        $this->assertTrue($res['apex_unsupported']);
    }

    public function test_invalid_domain_rejected(): void
    {
        $this->openGate();
        $id = $this->website();
        $svc = new CustomDomainService(new FakeHostnameProvider());

        $this->assertFalse($svc->connect($id, 'not a domain')['success']);
    }

    public function test_second_different_domain_requires_disconnect(): void
    {
        $this->openGate();
        $id = $this->website();
        $svc = new CustomDomainService(new FakeHostnameProvider());

        $svc->connect($id, 'www.acme.com');
        $res = $svc->connect($id, 'shop.acme.com');

        $this->assertFalse($res['success']);
    }

    public function test_verify_transitions_to_active(): void
    {
        $this->openGate();
        $id = $this->website();
        $fake = new FakeHostnameProvider();
        $svc = new CustomDomainService($fake);

        $svc->connect($id, 'www.acme.com');
        $fake->makeActive = true; // provider now reports fully active
        $res = $svc->verify($id);

        $this->assertSame(CustomDomain::STATE_ACTIVE, $res['state']);
        $this->assertTrue($res['active']);
        $this->assertSame(1, (int) DB::table('websites')->where('id', $id)->value('domain_verified'));
    }

    public function test_disconnect_deletes_provider_hostname_and_is_idempotent(): void
    {
        $this->openGate();
        $id = $this->website();
        $fake = new FakeHostnameProvider();
        $svc = new CustomDomainService($fake);

        $svc->connect($id, 'www.acme.com');
        $res = $svc->disconnect($id);

        $this->assertTrue($res['success']);
        $this->assertSame(1, $fake->deleteCount);
        $this->assertNull(DB::table('websites')->where('id', $id)->value('custom_domain'));
        $this->assertSame(CustomDomain::STATE_DISCONNECTED, CustomDomain::withoutGlobalScopes()->where('website_id', $id)->latest('id')->first()->state);

        // Idempotent second disconnect.
        $res2 = $svc->disconnect($id);
        $this->assertTrue($res2['success']);
        $this->assertSame(1, $fake->deleteCount, 'no extra provider delete on repeat');
    }

    public function test_compensating_cleanup_deletes_hostname_when_persist_fails(): void
    {
        $this->openGate();
        $id = $this->website();
        $fake = new FakeHostnameProvider();
        $fake->throwOnInstructions = true; // simulate a persist-time failure after create
        $svc = new CustomDomainService($fake);

        $res = $svc->connect($id, 'www.acme.com');

        $this->assertFalse($res['success']);
        $this->assertSame(1, $fake->createCount);
        $this->assertSame(1, $fake->deleteCount, 'provider hostname must be compensated (deleted)');
    }

    public function test_provider_error_marks_failed_no_orphan(): void
    {
        $this->openGate();
        $id = $this->website();
        $fake = new FakeHostnameProvider();
        $fake->throwOnCreate = true;
        $svc = new CustomDomainService($fake);

        $res = $svc->connect($id, 'www.acme.com');

        $this->assertFalse($res['success']);
        $this->assertSame(0, $fake->deleteCount);
        $row = CustomDomain::withoutGlobalScopes()->where('website_id', $id)->first();
        $this->assertSame(CustomDomain::STATE_FAILED, $row->state);
        $this->assertNull($row->provider_hostname_id);
    }
}

/**
 * In-memory provider double. Deterministic ids; toggles simulate failure modes.
 */
class FakeHostnameProvider implements CustomHostnameProvider
{
    public int $createCount = 0;
    public int $deleteCount = 0;
    public bool $makeActive = false;
    public bool $throwOnCreate = false;
    public bool $throwOnInstructions = false;

    public function key(): string { return 'fake'; }

    public function createCustomHostname(string $hostname, array $options = []): CustomHostnameResult
    {
        if ($this->throwOnCreate) {
            throw new ProviderException('simulated create failure', ['boom']);
        }
        $this->createCount++;
        return $this->snapshot($hostname);
    }

    public function getCustomHostname(string $providerHostnameId): ?CustomHostnameResult
    {
        return $this->snapshot('www.acme.com');
    }

    public function listCustomHostnames(string $hostname): array
    {
        return [$this->snapshot($hostname)];
    }

    public function verifyCustomHostname(string $providerHostnameId): CustomHostnameResult
    {
        return $this->snapshot('www.acme.com');
    }

    public function deleteCustomHostname(string $providerHostnameId): bool
    {
        $this->deleteCount++;
        return true;
    }

    public function getValidationInstructions(CustomHostnameResult $result): array
    {
        if ($this->throwOnInstructions) {
            throw new \RuntimeException('simulated persist-time failure');
        }
        return array_merge(
            [['type' => 'CNAME', 'name' => $result->hostname, 'value' => 'routing.levelupgrowth.io', 'purpose' => 'routing']],
            $result->validationRecords,
        );
    }

    public function getCertificateStatus(string $providerHostnameId): ?string
    {
        return $this->makeActive ? 'active' : 'pending_validation';
    }

    private function snapshot(string $hostname): CustomHostnameResult
    {
        return new CustomHostnameResult(
            providerHostnameId: 'cf-hostname-1',
            hostname: $hostname,
            ownershipStatus: $this->makeActive ? 'active' : 'pending',
            sslStatus: $this->makeActive ? 'active' : 'pending_validation',
            validationMethod: 'txt',
            validationRecords: [['type' => 'TXT', 'name' => '_cf.'.$hostname, 'value' => 'dcv-token', 'purpose' => 'ownership']],
            active: $this->makeActive,
            errors: [],
            raw: ['id' => 'cf-hostname-1', 'status' => $this->makeActive ? 'active' : 'pending'],
        );
    }
}
