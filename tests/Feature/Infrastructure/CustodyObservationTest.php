<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Observation\Custody;
use App\Engines\Infrastructure\Observation\EstateObservationService;
use App\Engines\Infrastructure\Observation\Observers\DnsObserver;
use App\Engines\Infrastructure\Observation\Observers\EndpointObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INFRA888 S8 — custody model and custody-driven observation.
 */
class CustodyObservationTest extends TestCase
{
    use RefreshDatabase;

    private \Tests\Feature\Infrastructure\Support\EstateFixture $fx;
    private \Tests\Feature\Infrastructure\Support\EstateFixture $fx2;

    protected function setUp(): void
    {
        parent::setUp();
        // Root cause of the original failure: workspaces.created_by is NOT NULL
        // and a foreign key to users. The fixture now builds the whole chain.
        $this->fx = \Tests\Feature\Infrastructure\Support\EstateFixture::make('custody');
        $this->fx2 = \Tests\Feature\Infrastructure\Support\EstateFixture::second('custody2');
    }

    private function registeredByUs(string $host): void
    {
        $this->fx->registeredDomain($host);
    }

    private function servedButNotRegistered(string $host): void
    {
        $this->fx2->servedWebsite($host);
    }

    // ── custody model ────────────────────────────────────────────────────────

    public function test_domain_registered_by_us_is_managed_by_us(): void
    {
        $this->registeredByUs('ours-test.com');

        $c = Custody::resolve('ours-test.com');
        $this->assertSame(Custody::MANAGED_BY_US, $c['custody']);
        $this->assertNotNull($c['customer_domain_id']);
    }

    public function test_domain_we_serve_but_did_not_register_is_customer_held(): void
    {
        $this->servedButNotRegistered('theirs-test.com');

        $c = Custody::resolve('theirs-test.com');
        $this->assertSame(Custody::CUSTOMER_HELD, $c['custody']);
        $this->assertNotNull($c['website_id']);
    }

    public function test_unrecognised_hostname_is_unknown_not_assumed_ours(): void
    {
        $this->assertSame(Custody::UNKNOWN, Custody::resolve('nobody-knows-test.com')['custody']);
    }

    public function test_custody_is_case_insensitive(): void
    {
        $this->registeredByUs('mixedcase-test.com');

        $this->assertSame(Custody::MANAGED_BY_US, Custody::resolve('MixedCase-Test.COM')['custody']);
    }

    // ── custody drives observation strategy ──────────────────────────────────

    public function test_registrar_dimension_is_only_observable_when_we_hold_the_account(): void
    {
        $this->assertContains('registrar', Custody::observableDimensions(Custody::MANAGED_BY_US));
        $this->assertNotContains('registrar', Custody::observableDimensions(Custody::CUSTOMER_HELD));
        $this->assertNotContains('registrar', Custody::observableDimensions(Custody::THIRD_PARTY_HELD));
    }

    public function test_public_dimensions_are_observable_under_every_custody(): void
    {
        foreach ([Custody::MANAGED_BY_US, Custody::CUSTOMER_HELD, Custody::THIRD_PARTY_HELD, Custody::UNKNOWN] as $c) {
            foreach (['dns', 'certificate', 'http'] as $dim) {
                $this->assertContains($dim, Custody::observableDimensions($c),
                    "{$dim} is measured from the public internet and must not depend on custody");
            }
        }
    }

    public function test_whois_is_inferred_never_verified(): void
    {
        foreach ([Custody::MANAGED_BY_US, Custody::CUSTOMER_HELD, Custody::UNKNOWN] as $c) {
            $this->assertSame(Custody::INFERRED, Custody::confidenceFor($c, 'whois'),
                'WHOIS is unstandardised and often redacted — never an authority.');
        }
    }

    public function test_registrar_confidence_is_verified_only_for_managed(): void
    {
        $this->assertSame(Custody::VERIFIED, Custody::confidenceFor(Custody::MANAGED_BY_US, 'registrar'));
        $this->assertSame(Custody::UNCERTAIN, Custody::confidenceFor(Custody::CUSTOMER_HELD, 'registrar'));
    }

    public function test_estate_subjects_include_customer_held_domains(): void
    {
        $this->registeredByUs('ours-test.com');
        $this->servedButNotRegistered('theirs-test.com');

        $subjects = array_column(Custody::estateSubjects(), 'subject');

        $this->assertContains('ours-test.com', $subjects);
        $this->assertContains('theirs-test.com', $subjects,
            'A customer-held domain must be in the estate — this is the whole point of S8.');
    }

    // ── health integration ───────────────────────────────────────────────────

    public function test_dimension_custody_forbids_is_not_applicable_not_unhealthy(): void
    {
        $this->servedButNotRegistered('theirs-test.com');

        $h = (new EstateObservationService())->health('theirs-test.com');

        $this->assertSame('not_applicable', $h['dimensions']['registrar']['state'],
            'Not attempting an impossible observation must never count as ill health.');
    }

    public function test_never_observed_dimension_is_unknown(): void
    {
        $this->registeredByUs('ours-test.com');

        $h = (new EstateObservationService())->health('ours-test.com');

        $this->assertSame('unknown', $h['dimensions']['dns']['state']);
    }

    public function test_health_is_reported_per_dimension_independently(): void
    {
        $this->servedButNotRegistered('theirs-test.com');

        $h = (new EstateObservationService())->health('theirs-test.com');

        foreach (['dns', 'certificate', 'http', 'whois', 'registrar'] as $dim) {
            $this->assertArrayHasKey($dim, $h['dimensions']);
            $this->assertArrayHasKey('reason', $h['dimensions'][$dim]);
            $this->assertArrayHasKey('confidence', $h['dimensions'][$dim]);
        }
    }

    public function test_failed_observation_yields_unknown_not_healthy(): void
    {
        $this->servedButNotRegistered('down-test.com');

        DB::table('infra_observation_facts')->insert([
            'workspace_id' => $this->fx2->workspaceId, 'subject' => 'down-test.com', 'dimension' => 'http',
            'custody' => Custody::CUSTOMER_HELD, 'provider' => 'http_probe',
            'success' => false, 'confidence' => Custody::UNCERTAIN,
            'error_code' => 'HTTPS_UNAVAILABLE',
            'observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $h = (new EstateObservationService())->health('down-test.com');
        $this->assertSame('unknown', $h['dimensions']['http']['state']);
    }

    public function test_stale_successful_observation_decays_to_unknown(): void
    {
        $this->servedButNotRegistered('stale-test.com');

        DB::table('infra_observation_facts')->insert([
            'workspace_id' => $this->fx2->workspaceId, 'subject' => 'stale-test.com', 'dimension' => 'dns',
            'custody' => Custody::CUSTOMER_HELD, 'provider' => 'dns_resolver',
            'success' => true, 'confidence' => Custody::VERIFIED,
            'observed_at' => now()->subDays(30), 'last_success_at' => now()->subDays(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('unknown', (new EstateObservationService())->health('stale-test.com')['dimensions']['dns']['state'],
            'Health must never survive on an old reading.');
    }

    public function test_critical_drift_makes_that_dimension_critical(): void
    {
        $this->servedButNotRegistered('bad-test.com');

        DB::table('infra_observation_facts')->insert([
            'workspace_id' => $this->fx2->workspaceId, 'subject' => 'bad-test.com', 'dimension' => 'certificate',
            'custody' => Custody::CUSTOMER_HELD, 'provider' => 'tls_handshake',
            'success' => true, 'confidence' => Custody::VERIFIED,
            'has_drift' => true, 'drift_count' => 1, 'highest_severity' => 'critical',
            'drift_json' => json_encode([['class' => 'unknown_drift', 'severity' => 'critical', 'title' => 'expired']]),
            'observed_at' => now(), 'last_success_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $h = (new EstateObservationService())->health('bad-test.com');
        $this->assertSame('critical', $h['dimensions']['certificate']['state']);
        $this->assertSame('critical', $h['overall']);
    }

    // ── observers are read-only ──────────────────────────────────────────────

    public function test_dns_observer_returns_structure_without_mutating(): void
    {
        $before = DB::table('customer_domains')->count();
        $r = (new DnsObserver())->observe('example.com', Custody::CUSTOMER_HELD);

        $this->assertSame('dns', $r['dimension']);
        $this->assertArrayHasKey('records', $r['observed']);
        $this->assertSame($before, DB::table('customer_domains')->count());
    }

    public function test_endpoint_observer_reports_failure_without_throwing(): void
    {
        $r = (new EndpointObserver())->certificate('nonexistent-host-xyz.invalid', Custody::UNKNOWN);

        $this->assertFalse($r['success']);
        $this->assertSame('TLS_HANDSHAKE_FAILED', $r['error_code']);
        $this->assertNotEmpty($r['drift']);
    }

    public function test_workspace_scoping_of_subjects(): void
    {
        $this->registeredByUs('ours-test.com');
        $this->servedButNotRegistered('theirs-test.com');

        $only = array_column(Custody::estateSubjects($this->fx2->workspaceId), 'subject');

        $this->assertSame(['theirs-test.com'], $only);
    }
}
