<?php

namespace Tests\Feature\Infrastructure;

use App\Connectors\Infrastructure\Contracts\DomainRegistrarConnector;
use App\Connectors\Infrastructure\ProviderResult;
use App\Engines\Infrastructure\Observation\DomainDrift;
use App\Engines\Infrastructure\Observation\RegistrarObservationService;
use App\Models\CustomerDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INFRA888 S6 — proves observation records facts and never repairs.
 */
class ObservationTest extends TestCase
{
    use RefreshDatabase;

    private function domain(array $over = []): CustomerDomain
    {
        $id = DB::table('customer_domains')->insertGetId(array_merge([
            'workspace_id' => 1, 'user_id' => 1, 'domain' => 'obs-test.com',
            'provider' => 'namecheap', 'status' => 'active',
            'registered_at' => now()->subYear(), 'expires_at' => now()->addYear(),
            'auto_renew' => 0, 'is_locked' => 0, 'whois_privacy' => 0,
            'nameservers_json' => json_encode(['ns1.example.com', 'ns2.example.com']),
            'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $over));

        return CustomerDomain::find($id);
    }

    private function reg(array $o = []): DomainRegistrarConnector
    {
        return new class($o) implements DomainRegistrarConnector
        {
            public function __construct(public array $o) {}

            public function getDomainStatus(string $d): ProviderResult
            {
                if ($this->o['throw'] ?? false) {
                    throw new \RuntimeException('connection refused');
                }

                if ($this->o['notfound'] ?? false) {
                    return ProviderResult::failed('2019166', 'Domain name not available', 'permanent');
                }

                return ProviderResult::verified('active', providerResourceId: $d, data: array_merge([
                    'domain' => $d, 'owned' => true, 'found' => true, 'managed_by_us' => true,
                    'status' => 'Ok', 'expires_at' => now()->addYear()->format('m/d/Y'),
                    'auto_renew' => false, 'is_locked' => false,
                    'nameservers' => ['ns1.example.com', 'ns2.example.com'],
                    'dns_provider' => 'FREE', 'uses_our_dns' => true,
                ], $this->o['data'] ?? []));
            }

            public function renewDomain(string $d, int $y, string $k): ProviderResult { throw new \LogicException('observation must never renew'); }
            public function registerDomain(string $d, int $y, array $c, string $k): ProviderResult { throw new \LogicException('never'); }
            public function transferDomain(string $d, string $a, array $c, string $k): ProviderResult { throw new \LogicException('never'); }
            public function updateNameservers(string $d, array $n, string $k): ProviderResult { throw new \LogicException('never'); }
            public function searchDomain(string $d): ProviderResult { return ProviderResult::verified('active'); }
            public function quoteRegistration(string $d, int $y): ProviderResult { return ProviderResult::verified('active'); }
            public function provider(): string { return 'fake'; }
            public function capability(): string { return 'registrar'; }
            public function key(): string { return 'fake'; }
            public function healthCheck(): ProviderResult { return ProviderResult::verified('active'); }
            public function synchronize(string $i): ProviderResult { return ProviderResult::verified('active'); }
            public function verify(string $i, array $e = []): ProviderResult { return ProviderResult::verified('active'); }
        };
    }

    private function classes(array $r): array
    {
        return array_column($r['drift'], 'class');
    }

    // ── the core guarantee ───────────────────────────────────────────────────

    public function test_observation_never_repairs_the_estate(): void
    {
        $d = $this->domain(['expires_at' => now()->addYear()]);
        $was = (string) DB::table('customer_domains')->where('id', $d->id)->value('expires_at');

        // registrar reports a different expiry
        (new RegistrarObservationService($this->reg(['data' => ['expires_at' => now()->addYears(3)->format('m/d/Y')]])))->observe($d);

        $this->assertSame($was, (string) DB::table('customer_domains')->where('id', $d->id)->value('expires_at'),
            'Observation must record the difference, never silently correct it.');
    }

    public function test_observation_never_calls_a_mutating_registrar_method(): void
    {
        // Every mutating method on the fake throws LogicException.
        $d = $this->domain();
        $r = (new RegistrarObservationService($this->reg()))->observe($d);

        $this->assertTrue($r['reachable']);
    }

    public function test_observation_is_recorded_as_history_not_overwritten(): void
    {
        $d = $this->domain();
        $svc = new RegistrarObservationService($this->reg());
        $svc->observe($d);
        $svc->observe($d);
        $svc->observe($d);

        $this->assertSame(3, DB::table('infra_domain_observations')->where('customer_domain_id', $d->id)->count());
    }

    // ── drift classification ─────────────────────────────────────────────────

    public function test_expiry_later_than_believed_is_warning(): void
    {
        $d = $this->domain(['expires_at' => now()->addYear()]);
        $r = (new RegistrarObservationService($this->reg(['data' => ['expires_at' => now()->addYears(2)->format('m/d/Y')]])))->observe($d);

        $this->assertContains(DomainDrift::EXPIRY_LATER, $this->classes($r));
        $this->assertSame(DomainDrift::WARNING, $r['highest_severity']);
    }

    public function test_expiry_earlier_than_believed_is_critical(): void
    {
        $d = $this->domain(['expires_at' => now()->addYears(3)]);
        $r = (new RegistrarObservationService($this->reg(['data' => ['expires_at' => now()->addMonth()->format('m/d/Y')]])))->observe($d);

        $this->assertContains(DomainDrift::EXPIRY_EARLIER, $this->classes($r));
        $this->assertSame(DomainDrift::CRITICAL, $r['highest_severity'],
            'Lapsing sooner than believed is the dangerous direction.');
    }

    public function test_custody_loss_is_critical(): void
    {
        $d = $this->domain();
        $r = (new RegistrarObservationService($this->reg(['data' => ['owned' => false]])))->observe($d);

        $this->assertContains(DomainDrift::CUSTODY_LOST, $this->classes($r));
        $this->assertSame(DomainDrift::CRITICAL, $r['highest_severity']);
    }

    public function test_nameserver_drift_detected_and_order_insensitive(): void
    {
        $d = $this->domain();

        $same = (new RegistrarObservationService($this->reg(['data' => ['nameservers' => ['ns2.example.com', 'ns1.example.com']]])))->observe($d);
        $this->assertNotContains(DomainDrift::NAMESERVER_DRIFT, $this->classes($same), 'Order must not count as drift.');

        $diff = (new RegistrarObservationService($this->reg(['data' => ['nameservers' => ['ns9.hijack.test']]])))->observe($d);
        $this->assertContains(DomainDrift::NAMESERVER_DRIFT, $this->classes($diff));
    }

    public function test_auto_renew_and_lock_drift_detected(): void
    {
        $d = $this->domain(['auto_renew' => 0, 'is_locked' => 0]);
        $r = (new RegistrarObservationService($this->reg(['data' => ['auto_renew' => true, 'is_locked' => true]])))->observe($d);

        $this->assertContains(DomainDrift::AUTO_RENEW_DRIFT, $this->classes($r));
        $this->assertContains(DomainDrift::LOCK_DRIFT, $this->classes($r));
    }

    public function test_registrar_unreachable_is_critical_and_not_silence(): void
    {
        $d = $this->domain();
        $r = (new RegistrarObservationService($this->reg(['throw' => true])))->observe($d);

        $this->assertFalse($r['reachable']);
        $this->assertContains(DomainDrift::REGISTRAR_UNAVAILABLE, $this->classes($r));
        $this->assertSame(DomainDrift::CRITICAL, $r['highest_severity']);
    }

    public function test_orphaned_estate_when_registrar_does_not_hold_it(): void
    {
        $d = $this->domain();
        $r = (new RegistrarObservationService($this->reg(['notfound' => true])))->observe($d);

        $this->assertContains(DomainDrift::ORPHANED_ESTATE, $this->classes($r));
        $this->assertSame(DomainDrift::CRITICAL, $r['highest_severity']);
    }

    public function test_no_drift_when_everything_agrees(): void
    {
        $d = $this->domain(['expires_at' => now()->addYear()->startOfDay()]);
        $r = (new RegistrarObservationService($this->reg()))->observe($d);

        $this->assertSame([], $r['drift']);
    }

    // ── visibility ───────────────────────────────────────────────────────────

    public function test_customer_view_exposes_no_provider_internals(): void
    {
        $d = $this->domain();
        $svc = new RegistrarObservationService($this->reg(['data' => ['nameservers' => ['ns9.hijack.test']]]));
        $svc->observe($d);

        $json = json_encode($svc->customerView($d));

        foreach (['namecheap', 'Namecheap', 'ns9.hijack.test', 'dns1.registrar-servers', 'FREE', 'managed_by_us'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "Customer payload leaked: {$leak}");
        }

        $this->assertStringContainsString('LevelUp Growth', $json);
        $this->assertStringContainsString('last_verified', $json);
        $this->assertStringContainsString('verification_confidence', $json);
    }

    public function test_customer_confidence_is_unknown_when_registrar_unreachable(): void
    {
        $d = $this->domain();
        $svc = new RegistrarObservationService($this->reg(['throw' => true]));
        $svc->observe($d);

        $this->assertSame('unknown', $svc->customerView($d)['verification_confidence'],
            'An unreachable registrar must never read as verified.');
    }

    public function test_admin_view_retains_raw_observed_and_desired_and_history(): void
    {
        $d = $this->domain();
        $svc = new RegistrarObservationService($this->reg(['data' => ['auto_renew' => true]]));
        $svc->observe($d);
        $svc->observe($d);

        $a = $svc->adminView($d);

        $this->assertNotNull($a['latest']['observed']);
        $this->assertNotNull($a['latest']['desired']);
        $this->assertNotEmpty($a['latest']['drift']);
        $this->assertNotEmpty($a['latest']['raw']);
        $this->assertCount(2, $a['history']);
    }

    public function test_every_drift_class_has_severity_guidance_and_action(): void
    {
        foreach (DomainDrift::catalogue() as $class => $m) {
            $this->assertContains($m['severity'], [DomainDrift::CRITICAL, DomainDrift::WARNING, DomainDrift::INFO], $class);
            $this->assertNotEmpty($m['title'], $class);
            $this->assertNotEmpty($m['operator'], $class);
            $this->assertNotEmpty($m['action'], $class);
            $this->assertArrayHasKey('customer', $m, $class);
        }
    }

    public function test_monitor_reports_never_observed_domains_as_stale(): void
    {
        $this->domain(['domain' => 'unobserved.com']);

        $checks = array_column((new RegistrarObservationService($this->reg()))->monitor(), 'check');

        $this->assertContains(DomainDrift::STALE_OBSERVATION, $checks);
    }

    public function test_workspace_scoping(): void
    {
        $a = $this->domain(['workspace_id' => 1, 'domain' => 'ws1-obs.com']);
        $this->domain(['workspace_id' => 2, 'domain' => 'ws2-obs.com']);

        (new RegistrarObservationService($this->reg()))->observeAll(1);

        $this->assertSame(1, DB::table('infra_domain_observations')->count());
        $this->assertSame($a->domain, DB::table('infra_domain_observations')->value('domain'));
    }
}
