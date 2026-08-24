<?php

namespace Tests\Feature\Infrastructure;

use App\Connectors\Infrastructure\Contracts\DomainRegistrarConnector;
use App\Connectors\Infrastructure\ProviderResult;
use App\Engines\Infrastructure\Services\DomainRenewalService;
use App\Engines\Infrastructure\States\DomainRenewalState as S;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INFRA888 S3 — proves the renewal orchestration behaves under the conditions
 * that actually cost money: timeouts, lying providers, and retries.
 *
 * No test touches a real registrar. The connector is a controllable fake.
 */
class DomainRenewalOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private function domain(array $over = []): int
    {
        return DB::table('customer_domains')->insertGetId(array_merge([
            'workspace_id' => 1, 'user_id' => 1, 'domain' => 'example-test.com',
            'provider' => 'namecheap', 'status' => 'active',
            'registered_at' => now()->subYear(),
            'expires_at' => now()->addDays(10),
            'auto_renew' => 1, 'is_locked' => 0, 'whois_privacy' => 0,
            'last_synced_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    private function svc(?DomainRegistrarConnector $fake = null): DomainRenewalService
    {
        return new DomainRenewalService($fake ?: $this->fake());
    }

    /** A registrar we fully control. */
    private function fake(array $opts = []): DomainRegistrarConnector
    {
        return new class($opts) implements DomainRegistrarConnector
        {
            public int $renewCalls = 0;

            public function __construct(public array $o) {}

            public function renewDomain(string $d, int $y, string $k): ProviderResult
            {
                $this->renewCalls++;

                if (($this->o['throw'] ?? false) === true) {
                    throw new \RuntimeException('cURL timeout after 60s');
                }

                if (isset($this->o['fail'])) {
                    return ProviderResult::failed('PROVIDER_ERR', 'nope', $this->o['fail']);
                }

                return ProviderResult::verified('active', providerResourceId: $d, providerState: 'renewed',
                    data: ['order_id' => 'O1', 'transaction_id' => 'T1', 'charged_amount' => 12.34]);
            }

            public function getDomainStatus(string $d): ProviderResult
            {
                if (($this->o['status_fails'] ?? false) === true) {
                    return ProviderResult::failed('UNREADABLE', 'no', 'transient');
                }

                return ProviderResult::verified('active', providerResourceId: $d,
                    data: ['expires_at' => $this->o['expiry'] ?? now()->addDays(10)->toDateTimeString()]);
            }

            public function searchDomain(string $d): ProviderResult { return ProviderResult::verified('active'); }
            public function quoteRegistration(string $d, int $y): ProviderResult { return ProviderResult::verified('active'); }
            public function registerDomain(string $d, int $y, array $c, string $k): ProviderResult { return ProviderResult::verified('active'); }
            public function transferDomain(string $d, string $a, array $c, string $k): ProviderResult { return ProviderResult::verified('active'); }
            public function updateNameservers(string $d, array $n, string $k): ProviderResult { return ProviderResult::verified('active'); }
            public function key(): string { return 'fake'; }
            public function provider(): string { return 'fake'; }
            public function capability(): string { return 'registrar'; }
            public function healthCheck(): ProviderResult { return ProviderResult::verified('active'); }
            public function synchronize(string $id): ProviderResult { return ProviderResult::verified('active'); }
            public function verify(string $id, array $e = []): ProviderResult { return ProviderResult::verified('active'); }
        };
    }

    private function scheduleOne(int $domainId): int
    {
        $this->svc()->schedule(null, false);

        return (int) DB::table('infra_domain_renewals')->where('customer_domain_id', $domainId)->value('id');
    }

    // ── eligibility & scheduling ─────────────────────────────────────────────

    public function test_domain_outside_horizon_is_not_eligible(): void
    {
        $this->domain(['expires_at' => now()->addDays(200)]);
        $this->assertSame([], $this->svc()->findEligible()['eligible']);
    }

    public function test_domain_without_expiry_is_not_eligible_and_is_reported(): void
    {
        $this->domain(['expires_at' => null]);
        $r = $this->svc()->findEligible();

        $this->assertSame([], $r['eligible']);
        $this->assertStringContainsString('no expiry', $r['skipped'][0]['reason']);
    }

    public function test_scheduling_is_idempotent_and_never_duplicates(): void
    {
        $id = $this->domain();

        $this->svc()->schedule(null, false);
        $this->svc()->schedule(null, false);
        $this->svc()->schedule(null, false);

        $this->assertSame(1, DB::table('infra_domain_renewals')->where('customer_domain_id', $id)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->domain();
        $this->svc()->schedule(null, true);

        $this->assertSame(0, DB::table('infra_domain_renewals')->count());
    }

    // ── the money-critical behaviours ────────────────────────────────────────

    public function test_provider_success_does_not_mean_renewed(): void
    {
        $d = $this->domain();
        $r = $this->scheduleOne($d);

        $out = $this->svc()->execute($r, force: true);

        $this->assertSame('ACCEPTED', $out['outcome']);
        $this->assertSame(S::VERIFYING, DB::table('infra_domain_renewals')->where('id', $r)->value('state'),
            'A provider OK must never be recorded as RENEWED without read-back.');
    }

    public function test_timeout_is_ambiguous_not_failure(): void
    {
        $d = $this->domain();
        $r = $this->scheduleOne($d);

        $out = $this->svc($this->fake(['throw' => true]))->execute($r, force: true);

        $this->assertSame('AMBIGUOUS', $out['outcome']);
        $row = DB::table('infra_domain_renewals')->where('id', $r)->first();
        $this->assertSame(S::VERIFYING, $row->state);
        $this->assertSame('ambiguous', $row->failure_class);
    }

    public function test_in_flight_renewal_refuses_re_execution(): void
    {
        $d = $this->domain();
        $r = $this->scheduleOne($d);

        $this->svc()->execute($r, force: true);        // -> verifying
        $out = $this->svc()->execute($r, force: true); // must refuse

        $this->assertSame('IN_FLIGHT', $out['outcome'],
            'Re-attempting an in-flight billable renewal risks charging the customer twice.');
    }

    public function test_verification_promotes_to_renewed_only_when_expiry_moves(): void
    {
        $d = $this->domain(['expires_at' => now()->addDays(10)]);
        $r = $this->scheduleOne($d);

        $this->svc()->execute($r, force: true);

        $moved = now()->addDays(375)->toDateTimeString();
        $out = $this->svc($this->fake(['expiry' => $moved]))->verify($r);

        $this->assertSame('RENEWED', $out['outcome']);
        $this->assertSame(S::RENEWED, DB::table('infra_domain_renewals')->where('id', $r)->value('state'));

        // the estate learns the new truth only once proven
        $this->assertSame(substr($moved, 0, 10),
            substr((string) DB::table('customer_domains')->where('id', $d)->value('expires_at'), 0, 10));
    }

    public function test_unmoved_expiry_escalates_to_manual_and_never_claims_success(): void
    {
        $d = $this->domain(['expires_at' => now()->addDays(10)]);
        $r = $this->scheduleOne($d);

        $svc = $this->svc();
        $svc->execute($r, force: true);

        $stuck = $this->svc($this->fake(['expiry' => now()->addDays(10)->toDateTimeString()]));

        for ($i = 0; $i < DomainRenewalService::MAX_VERIFY_ATTEMPTS; $i++) {
            $out = $stuck->verify($r);
        }

        $this->assertSame('ESCALATED', $out['outcome']);
        $row = DB::table('infra_domain_renewals')->where('id', $r)->first();
        $this->assertSame(S::NEEDS_MANUAL, $row->state);
        $this->assertSame('EXPIRY_UNCHANGED', $row->failure_code);
    }

    public function test_permanent_failure_abandons_and_transient_schedules_retry(): void
    {
        $d1 = $this->domain(['domain' => 'perm-test.com']);
        $r1 = $this->scheduleOne($d1);
        $this->svc($this->fake(['fail' => 'permanent']))->execute($r1, force: true);
        $this->assertSame(S::ABANDONED, DB::table('infra_domain_renewals')->where('id', $r1)->value('state'));

        DB::table('infra_domain_renewals')->delete();
        DB::table('customer_domains')->delete();

        $d2 = $this->domain(['domain' => 'trans-test.com']);
        $r2 = $this->scheduleOne($d2);
        $this->svc($this->fake(['fail' => 'retryable']))->execute($r2, force: true);

        $row = DB::table('infra_domain_renewals')->where('id', $r2)->first();
        $this->assertSame(S::FAILED, $row->state);
        $this->assertNotNull($row->next_attempt_at, 'A transient failure must schedule a backoff retry.');
    }

    public function test_execution_is_blocked_by_default_gate(): void
    {
        config(['infrastructure.renewals.execution_enabled' => false]);
        $d = $this->domain();
        $r = $this->scheduleOne($d);

        $this->assertSame('BLOCKED', $this->svc()->execute($r)['outcome']);
        $this->assertSame(0, DB::table('infra_domain_renewals')->where('id', $r)->value('attempt_count'));
    }

    // ── tenancy, visibility, monitoring ──────────────────────────────────────

    public function test_customer_payload_leaks_no_provider_identity(): void
    {
        $d = $this->domain();
        $r = $this->scheduleOne($d);
        $this->svc()->execute($r, force: true);

        $row = DB::table('infra_domain_renewals')->where('id', $r)->first();
        $json = json_encode($this->svc()->customerView($row));

        foreach (['namecheap', 'Namecheap', 'O1', 'T1', 'idempotency'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "Customer payload leaked: {$leak}");
        }

        $this->assertStringContainsString('LevelUp Growth', $json);
    }

    public function test_admin_view_retains_full_provider_detail(): void
    {
        $d = $this->domain();
        $r = $this->scheduleOne($d);
        $this->svc()->execute($r, force: true);

        $row = DB::table('infra_domain_renewals')->where('id', $r)->first();
        $admin = $this->svc()->adminView($row);

        $this->assertSame('O1', $admin['provider_order_id']);
        $this->assertSame('T1', $admin['provider_transaction_id']);
        $this->assertNotEmpty($admin['idempotency_key']);
        $this->assertNotEmpty($admin['history']);
    }

    public function test_monitoring_detects_overdue_and_stale_observation(): void
    {
        $this->domain(['domain' => 'overdue-test.com', 'expires_at' => now()->subDays(3), 'last_synced_at' => now()->subDays(40)]);

        $checks = collect($this->svc()->monitor())->pluck('check')->all();

        $this->assertContains('overdue_renewal', $checks);
        $this->assertContains('stale_verification', $checks);
    }

    public function test_workspace_scoping_is_honoured(): void
    {
        $this->domain(['workspace_id' => 1, 'domain' => 'ws1-test.com']);
        $this->domain(['workspace_id' => 2, 'domain' => 'ws2-test.com']);

        $only = $this->svc()->findEligible(2)['eligible'];

        $this->assertCount(1, $only);
        $this->assertSame('ws2-test.com', $only[0]['domain']);
    }
}
