<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Observation\DomainDrift;
use App\Engines\Infrastructure\Observation\EstateAlertService;
use App\Models\CustomerDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INFRA888 S7 — alert lifecycle and health computation.
 *
 * The two rules under test are the ones that stop monitoring lying:
 * health never improves with time, and alerts only recover on fresh evidence.
 */
class EstateAlertTest extends TestCase
{
    use RefreshDatabase;

    private function domain(array $o = []): CustomerDomain
    {
        $id = DB::table('customer_domains')->insertGetId(array_merge([
            'workspace_id' => 1, 'user_id' => 1, 'domain' => 'alert-test.com',
            'provider' => 'namecheap', 'status' => 'active',
            'registered_at' => now()->subYear(), 'expires_at' => now()->addYear(),
            'auto_renew' => 0, 'is_locked' => 0, 'whois_privacy' => 0,
            'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $o));

        return CustomerDomain::find($id);
    }

    /** Insert an observation row directly — this suite tests alerts, not probing. */
    private function observation(CustomerDomain $d, array $driftClasses = [], bool $reachable = true, $observedAt = null): object
    {
        $drift = array_map(fn ($c) => [
            'class' => $c,
            'severity' => DomainDrift::severityOf($c),
            'title' => DomainDrift::describe($c)['title'],
        ], $driftClasses);

        $id = DB::table('infra_domain_observations')->insertGetId([
            'workspace_id' => $d->workspace_id, 'customer_domain_id' => $d->id,
            'domain' => $d->domain, 'provider' => 'namecheap',
            'reachable' => $reachable,
            'has_drift' => $drift !== [], 'drift_count' => count($drift),
            'highest_severity' => DomainDrift::highest($driftClasses),
            'drift_json' => json_encode($drift),
            'observed_at' => $observedAt ?: now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('infra_domain_observations')->where('id', $id)->first();
    }

    private function svc(): EstateAlertService
    {
        return new EstateAlertService();
    }

    // ── alert identity ───────────────────────────────────────────────────────

    public function test_repeated_drift_is_one_alert_with_occurrences(): void
    {
        $d = $this->domain();
        $svc = $this->svc();

        foreach (range(1, 4) as $_) {
            $svc->reconcileFromObservation($this->observation($d, [DomainDrift::NAMESERVER_DRIFT]));
        }

        $this->assertSame(1, DB::table('infra_estate_alerts')->count());
        $this->assertSame(4, (int) DB::table('infra_estate_alerts')->value('occurrence_count'));
    }

    // ── recovery: the load-bearing rule ──────────────────────────────────────

    public function test_alert_recovers_only_with_the_observation_that_proved_it(): void
    {
        $d = $this->domain();
        $svc = $this->svc();
        $svc->reconcileFromObservation($this->observation($d, [DomainDrift::NAMESERVER_DRIFT]));

        $clean = $this->observation($d, []);
        $svc->reconcileFromObservation($clean);

        $a = DB::table('infra_estate_alerts')->first();
        $this->assertSame(EstateAlertService::RECOVERED, $a->state);
        $this->assertSame((int) $clean->id, (int) $a->recovered_by_observation_id,
            'Recovery must name the observation that proved it.');
    }

    public function test_unreachable_observation_recovers_nothing(): void
    {
        $d = $this->domain();
        $svc = $this->svc();
        $svc->reconcileFromObservation($this->observation($d, [DomainDrift::NAMESERVER_DRIFT]));

        // We learned nothing, so nothing may close.
        $svc->reconcileFromObservation($this->observation($d, [], reachable: false));

        $this->assertSame(EstateAlertService::OPEN, DB::table('infra_estate_alerts')->value('state'));
        $this->assertNull(DB::table('infra_estate_alerts')->value('recovered_at'));
    }

    public function test_alerts_never_recover_by_age(): void
    {
        $d = $this->domain();
        $this->svc()->reconcileFromObservation($this->observation($d, [DomainDrift::CUSTODY_LOST]));

        DB::table('infra_estate_alerts')->update([
            'first_seen_at' => now()->subMonths(6), 'last_seen_at' => now()->subMonths(6),
        ]);

        $this->svc()->escalateDue();

        $this->assertNotSame(EstateAlertService::RECOVERED, DB::table('infra_estate_alerts')->value('state'),
            'An alert must never close because it got old.');
    }

    // ── acknowledgement / suppression / escalation ───────────────────────────

    public function test_acknowledgement_stops_escalation_but_does_not_close(): void
    {
        $d = $this->domain();
        $this->svc()->reconcileFromObservation($this->observation($d, [DomainDrift::CUSTODY_LOST]));
        $id = (int) DB::table('infra_estate_alerts')->value('id');

        $this->assertTrue($this->svc()->acknowledge($id, 7, 'investigating with registrar'));

        $a = DB::table('infra_estate_alerts')->where('id', $id)->first();
        $this->assertSame(EstateAlertService::ACKNOWLEDGED, $a->state);
        $this->assertNull($a->next_escalation_at);
        $this->assertNull($a->recovered_at, 'Knowing about a problem is not the problem being gone.');
    }

    public function test_suppression_is_time_boxed_and_capped(): void
    {
        $d = $this->domain();
        $this->svc()->reconcileFromObservation($this->observation($d, [DomainDrift::AUTO_RENEW_DRIFT]));
        $id = (int) DB::table('infra_estate_alerts')->value('id');

        $this->svc()->suppress($id, 7, 'customer asked us to hold', 99999);

        $a = DB::table('infra_estate_alerts')->where('id', $id)->first();
        $this->assertSame(EstateAlertService::SUPPRESSED, $a->state);
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($a->suppressed_until)
                ->lte(now()->addHours(EstateAlertService::MAX_SUPPRESSION_HOURS)->addMinute()),
            'Suppression must be capped — permanent silence is how outages get missed.'
        );
    }

    public function test_expired_suppression_returns_the_alert_to_open(): void
    {
        $d = $this->domain();
        $svc = $this->svc();
        $svc->reconcileFromObservation($this->observation($d, [DomainDrift::AUTO_RENEW_DRIFT]));
        $id = (int) DB::table('infra_estate_alerts')->value('id');
        $svc->suppress($id, 7, 'temporary', 1);
        DB::table('infra_estate_alerts')->where('id', $id)->update(['suppressed_until' => now()->subHour()]);

        $svc->reconcileFromObservation($this->observation($d, [DomainDrift::AUTO_RENEW_DRIFT]));

        $this->assertSame(EstateAlertService::OPEN, DB::table('infra_estate_alerts')->where('id', $id)->value('state'));
    }

    public function test_unanswered_critical_escalates(): void
    {
        $d = $this->domain();
        $this->svc()->reconcileFromObservation($this->observation($d, [DomainDrift::CUSTODY_LOST]));
        DB::table('infra_estate_alerts')->update(['next_escalation_at' => now()->subMinute()]);

        $this->assertSame(1, $this->svc()->escalateDue());

        $a = DB::table('infra_estate_alerts')->first();
        $this->assertSame(EstateAlertService::ESCALATED, $a->state);
        $this->assertSame(1, (int) $a->escalation_level);
    }

    public function test_escalation_stops_at_level_three(): void
    {
        $d = $this->domain();
        $svc = $this->svc();
        $svc->reconcileFromObservation($this->observation($d, [DomainDrift::CUSTODY_LOST]));

        foreach (range(1, 6) as $_) {
            DB::table('infra_estate_alerts')->update(['next_escalation_at' => now()->subMinute()]);
            $svc->escalateDue();
        }

        $this->assertSame(3, (int) DB::table('infra_estate_alerts')->value('escalation_level'));
    }

    // ── health ───────────────────────────────────────────────────────────────

    public function test_never_observed_is_unknown_not_healthy(): void
    {
        $h = $this->svc()->health($this->domain());

        $this->assertSame(EstateAlertService::H_UNKNOWN, $h['state']);
    }

    public function test_stale_observation_decays_to_unknown(): void
    {
        $d = $this->domain();
        $this->observation($d, [], observedAt: now()->subDays(30));

        $this->assertSame(EstateAlertService::H_UNKNOWN, $this->svc()->health($d)['state'],
            'Health must never survive on an old reading.');
    }

    public function test_unreachable_last_observation_is_unknown(): void
    {
        $d = $this->domain();
        $this->observation($d, [], reachable: false);

        $this->assertSame(EstateAlertService::H_UNKNOWN, $this->svc()->health($d)['state']);
    }

    public function test_health_improves_only_after_a_fresh_successful_observation(): void
    {
        $d = $this->domain();
        $this->observation($d, [], observedAt: now()->subDays(30));
        $this->assertSame(EstateAlertService::H_UNKNOWN, $this->svc()->health($d)['state']);

        $this->observation($d, []);
        $this->assertSame(EstateAlertService::H_HEALTHY, $this->svc()->health($d)['state']);
    }

    public function test_unresolved_critical_alert_makes_health_critical(): void
    {
        $d = $this->domain();
        $this->svc()->reconcileFromObservation($this->observation($d, [DomainDrift::CUSTODY_LOST]));

        $this->assertSame(EstateAlertService::H_CRITICAL, $this->svc()->health($d)['state']);
    }

    public function test_health_always_explains_itself(): void
    {
        $d = $this->domain();
        $this->observation($d, []);

        $this->assertNotEmpty($this->svc()->health($d)['reasons']);
    }

    // ── visibility ───────────────────────────────────────────────────────────

    public function test_customer_view_exposes_no_diagnostics(): void
    {
        $d = $this->domain();
        $this->svc()->reconcileFromObservation($this->observation($d, [DomainDrift::REGISTRAR_UNAVAILABLE]));

        $json = json_encode($this->svc()->customerView($d));

        foreach (['namecheap', 'registrar_unavailable', 'TRANSPORT', 'API', 'probe'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "Customer payload leaked: {$leak}");
        }

        $this->assertStringContainsString('last_verified', $json);
        $this->assertStringContainsString('confidence', $json);
        $this->assertStringContainsString('LevelUp Growth', $json);
    }

    public function test_admin_view_is_fully_explainable(): void
    {
        $d = $this->domain();
        $svc = $this->svc();
        $svc->reconcileFromObservation($this->observation($d, [DomainDrift::CUSTODY_LOST]));
        $svc->acknowledge((int) DB::table('infra_estate_alerts')->value('id'), 7, 'checking');

        $v = $svc->adminView($d);

        $this->assertNotEmpty($v['health']['reasons']);
        $this->assertNotEmpty($v['observation_history']);
        $this->assertNotEmpty($v['alerts']);
        $this->assertNotEmpty($v['alerts'][0]['recommended_action']);
        $this->assertNotEmpty($v['alerts'][0]['operator_guidance']);
        $this->assertNotEmpty($v['alerts'][0]['history']);
        $this->assertSame('checking', $v['alerts'][0]['acknowledgement_note']);
    }

    public function test_suppressed_alert_is_hidden_from_customer_warnings(): void
    {
        $d = $this->domain();
        $svc = $this->svc();
        $svc->reconcileFromObservation($this->observation($d, [DomainDrift::CUSTODY_LOST]));
        $svc->suppress((int) DB::table('infra_estate_alerts')->value('id'), 7, 'known, handled', 24);

        $this->assertSame([], $svc->customerView($d)['active_warnings']);
    }
}
