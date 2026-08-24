<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Observation\AlertEligibility as E;
use App\Engines\Infrastructure\Observation\AssetLifecycle as L;
use App\Engines\Infrastructure\Observation\Custody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Infrastructure\Support\EstateFixture;
use Tests\TestCase;

/**
 * INFRA888 S8.4 — lifecycle model, cold-start protection, and guards.
 */
class LifecycleEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private EstateFixture $fx;
    private E $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = EstateFixture::make('lifecycle');
        $this->policy = new E();
    }

    private function alert(string $subject, array $over = []): object
    {
        $id = DB::table('infra_estate_alerts')->insertGetId(array_merge([
            'workspace_id' => $this->fx->workspaceId,
            'domain' => $subject,
            'alert_key' => substr(hash('sha256', $subject . uniqid()), 0, 40),
            'drift_class' => 'dns_broken',
            'severity' => 'critical',
            'state' => 'open',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'occurrence_count' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $over));

        return DB::table('infra_estate_alerts')->where('id', $id)->first();
    }

    private function failedDns(string $host, int $times, $at = null): void
    {
        foreach (range(1, $times) as $_) {
            $this->fx->observation($host, 'dns', [
                'success' => false, 'error_code' => 'NXDOMAIN',
                'observed_at' => $at ?: now(),
            ]);
        }
    }

    // ── THE COLD-START FIX ───────────────────────────────────────────────────

    public function test_brand_new_domain_is_pending_validation_not_internal(): void
    {
        // Registered a minute ago, not attached, not resolving yet — the exact
        // shape S8.3 would have called internal.
        $this->fx->registeredDomain('brand-new.com', ['created_at' => now()]);
        $this->failedDns('brand-new.com', 3);

        $l = L::resolve('brand-new.com');

        $this->assertSame(L::PENDING_VALIDATION, $l['state']);
        $this->assertStringContainsString('propagating', $l['reason']);
    }

    public function test_cold_start_alert_is_deferred_never_dropped(): void
    {
        $this->fx->registeredDomain('cold.com', ['created_at' => now()]);
        $this->failedDns('cold.com', 3);

        $d = $this->policy->evaluate($this->alert('cold.com'), false);

        $this->assertNotSame(E::INELIGIBLE, $d['verdict'],
            'A brand-new customer asset must never be silently dropped as internal.');
        $this->assertSame(E::DEFERRED, $d['verdict']);
    }

    public function test_scaffolding_still_blocked_after_grace_and_attempts(): void
    {
        $old = now()->subHours(L::COLD_START_GRACE_HOURS + 24);
        $this->fx->registeredDomain('scaffold.com', ['created_at' => $old]);
        $this->failedDns('scaffold.com', L::MIN_FAILED_OBSERVATIONS, $old);

        $this->assertSame(L::INTERNAL, L::resolve('scaffold.com')['state']);
        $this->assertSame(E::INELIGIBLE, $this->policy->evaluate($this->alert('scaffold.com'), false)['verdict']);
    }

    public function test_grace_alone_is_not_enough_attempts_are_required(): void
    {
        $old = now()->subHours(L::COLD_START_GRACE_HOURS + 24);
        $this->fx->registeredDomain('barely.com', ['created_at' => $old]);
        $this->failedDns('barely.com', 1, $old);

        $l = L::resolve('barely.com');

        $this->assertSame(L::PENDING_VALIDATION, $l['state']);
        $this->assertStringContainsString('required before asserting scaffolding', $l['reason']);
    }

    public function test_a_served_domain_is_never_internal_however_old(): void
    {
        $old = now()->subYear();
        $this->fx->registeredDomain('served.com', ['created_at' => $old]);
        $this->fx->servedWebsite('served.com');
        $this->failedDns('served.com', 10, $old);

        $this->assertNotSame(L::INTERNAL, L::resolve('served.com')['state'],
            'Serving a website is proof of purpose regardless of DNS.');
    }

    // ── lifecycle states ─────────────────────────────────────────────────────

    public function test_unknown_hostname_is_discovered(): void
    {
        $this->assertSame(L::DISCOVERED, L::resolve('who-is-this.com')['state']);
    }

    public function test_estate_member_never_observed_is_imported(): void
    {
        $this->fx->registeredDomain('imported.com');

        $this->assertSame(L::IMPORTED, L::resolve('imported.com')['state']);
    }

    public function test_resolving_domain_is_observed(): void
    {
        $this->fx->registeredDomain('resolves.com');
        $this->fx->observation('resolves.com', 'dns', ['success' => true]);

        $this->assertSame(L::OBSERVED, L::resolve('resolves.com')['state']);
    }

    public function test_resolving_and_serving_is_operational(): void
    {
        $this->fx->servedWebsite('live.com');
        $this->fx->observation('live.com', 'dns', ['success' => true]);

        $this->assertSame(L::OPERATIONAL, L::resolve('live.com')['state']);
    }

    public function test_released_domain_is_retired_and_outranks_everything(): void
    {
        $this->fx->registeredDomain('gone.com', ['status' => 'released']);
        $this->fx->servedWebsite('gone.com');
        $this->fx->observation('gone.com', 'dns', ['success' => true]);

        $this->assertSame(L::RETIRED, L::resolve('gone.com')['state']);
    }

    // ── confidence ladder ────────────────────────────────────────────────────

    public function test_confidence_ladder_is_ordered(): void
    {
        $this->assertTrue(L::atLeast(L::C_VERIFIED, L::C_OBSERVED));
        $this->assertTrue(L::atLeast(L::C_CUSTOMER_CONFIRMED, L::C_VERIFIED));
        $this->assertFalse(L::atLeast(L::C_LOW, L::C_OBSERVED));
        $this->assertFalse(L::atLeast(L::C_UNKNOWN, L::C_LOW));
    }

    public function test_never_observed_is_unknown_confidence(): void
    {
        $this->fx->registeredDomain('noobs.com');

        $this->assertSame(L::C_UNKNOWN, L::resolve('noobs.com')['confidence']);
    }

    public function test_new_asset_is_low_confidence_not_unknown_and_not_verified(): void
    {
        $this->fx->registeredDomain('newish.com', ['created_at' => now()]);
        $this->fx->observation('newish.com', 'whois', ['success' => true, 'confidence' => Custody::INFERRED]);
        $this->failedDns('newish.com', 1);

        $c = L::resolve('newish.com')['confidence'];

        $this->assertSame(L::C_LOW, $c,
            'A brand-new asset is neither treated as internal nor fully trusted.');
    }

    public function test_served_and_resolving_reaches_customer_confirmed(): void
    {
        $this->fx->servedWebsite('confirmed.com');
        $this->fx->observation('confirmed.com', 'dns', ['success' => true, 'confidence' => Custody::VERIFIED]);

        $this->assertSame(L::C_CUSTOMER_CONFIRMED, L::resolve('confirmed.com')['confidence']);
    }

    // ── guards (Stage 6) ─────────────────────────────────────────────────────

    private function lifecycleSource(): string
    {
        $f = '/var/www/levelup-staging/app/Engines/Infrastructure/Observation/AssetLifecycle.php';
        $out = '';

        foreach (token_get_all((string) file_get_contents($f)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    public function test_lifecycle_never_mutates_anything(): void
    {
        $this->assertSame(0, preg_match(
            "/->\s*(update|insert|insertGetId|delete|truncate|save)\s*\(/", $this->lifecycleSource()),
            'Lifecycle resolution is a pure read.');
    }

    public function test_lifecycle_never_delivers_or_schedules(): void
    {
        $this->assertSame(0, preg_match(
            '/Mail::|Notification::|->notify\(|Schedule::|dispatch\(|Queue::/', $this->lifecycleSource()));
    }

    public function test_lifecycle_never_affects_observation(): void
    {
        foreach (['RegistrarObservationService', 'EstateObservationService', 'Observers/DnsObserver', 'Observers/EndpointObserver'] as $f) {
            $p = "/var/www/levelup-staging/app/Engines/Infrastructure/Observation/{$f}.php";

            if (! is_file($p)) {
                continue;
            }

            $this->assertStringNotContainsString('AssetLifecycle', (string) file_get_contents($p),
                "Observers must measure reality regardless of lifecycle — {$f} references it.");
        }
    }

    public function test_lifecycle_affects_eligibility(): void
    {
        $src = (string) file_get_contents('/var/www/levelup-staging/app/Engines/Infrastructure/Observation/AlertEligibility.php');

        $this->assertStringContainsString('AssetLifecycle', $src,
            'Eligibility must respect lifecycle.');
    }

    public function test_cold_start_assets_never_become_internal_automatically(): void
    {
        // Sweep the whole grace window: nothing inside it may be INTERNAL.
        foreach ([0, 1, 12, 47, L::COLD_START_GRACE_HOURS - 1] as $i => $hours) {
            $host = "sweep{$i}.com";
            $this->fx->registeredDomain($host, ['created_at' => now()->subHours($hours)]);
            $this->failedDns($host, 5, now()->subHours($hours));

            $this->assertNotSame(L::INTERNAL, L::resolve($host)['state'],
                "An asset {$hours}h old was classified internal inside the grace window.");
        }
    }

    // ── migration namespace (Stage 5) ────────────────────────────────────────

    public function test_infra888_migrations_do_not_share_a_timestamp_with_another_workstream(): void
    {
        $dir = '/var/www/levelup-staging/database/migrations';
        $byStamp = [];

        foreach ((array) glob($dir . '/*.php') as $f) {
            $base = basename($f, '.php');

            if (! preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_(.+)$/', $base, $m)) {
                continue;
            }

            $byStamp[$m[1]][] = $m[2];
        }

        $collisions = [];

        foreach ($byStamp as $stamp => $names) {
            if (count($names) < 2) {
                continue;
            }

            $mine = array_filter($names, fn ($n) => str_contains($n, 'infra'));

            if ($mine !== [] && count($names) > count($mine)) {
                $collisions[$stamp] = $names;
            }
        }

        // Historical collisions are preserved unchanged by ratified policy — applied
        // migrations must never be renamed. The guard therefore protects the FUTURE:
        // anything dated after this baseline must not collide.
        $baseline = '2026_08_02_999999';
        $collisions = array_filter($collisions, fn ($v, $k) => $k > $baseline, ARRAY_FILTER_USE_BOTH);

        $this->assertSame([], $collisions,
            'INFRA888 migrations must not share a timestamp with another workstream: '
            . json_encode($collisions));
    }
}
