<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Observation\Custody;
use App\Engines\Infrastructure\Observation\ObservationAlertBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Infrastructure\Support\EstateFixture;
use Tests\TestCase;

/**
 * INFRA888 S8.2 — S8.1 bridge coverage: observation → finding → alert → health.
 */
class BridgeTest extends TestCase
{
    use RefreshDatabase;

    private EstateFixture $fx;
    private ObservationAlertBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = EstateFixture::make('bridge');
        $this->bridge = new ObservationAlertBridge();
    }

    private function subject(string $host = 'bridge-test.com'): string
    {
        $this->fx->servedWebsite($host);

        return $host;
    }

    private function alerts(): \Illuminate\Support\Collection
    {
        return DB::table('infra_estate_alerts')->get();
    }

    // ── observation → finding ────────────────────────────────────────────────

    public function test_drift_becomes_a_canonical_finding(): void
    {
        $h = $this->subject();
        $id = $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('critical', 'Hostname does not resolve')]]);

        $f = $this->bridge->findingsFor(DB::table('infra_observation_facts')->find($id));

        $this->assertCount(1, $f);
        $this->assertSame(ObservationAlertBridge::DNS_BROKEN, $f[0]['canonical_issue']);
    }

    public function test_failed_observation_becomes_observability_lost(): void
    {
        $h = $this->subject();
        $id = $this->fx->observation($h, 'http', ['success' => false, 'error_code' => 'HTTPS_UNAVAILABLE']);

        $f = $this->bridge->findingsFor(DB::table('infra_observation_facts')->find($id));

        $this->assertSame(ObservationAlertBridge::OBSERVABILITY_LOST, $f[0]['canonical_issue'],
            'Losing visibility is a fact worth recording, not silence.');
    }

    public function test_certificate_expiry_and_domain_expiry_are_different_issues(): void
    {
        $h = $this->subject();
        $c = $this->fx->observation($h, 'certificate', ['drift' => [EstateFixture::drift('critical', 'Certificate has EXPIRED')]]);
        $w = $this->fx->observation($h, 'whois', ['drift' => [EstateFixture::drift('critical', 'WHOIS reports this domain as already expired')]]);

        $cf = $this->bridge->findingsFor(DB::table('infra_observation_facts')->find($c));
        $wf = $this->bridge->findingsFor(DB::table('infra_observation_facts')->find($w));

        $this->assertSame(ObservationAlertBridge::TLS_INVALID, $cf[0]['canonical_issue']);
        $this->assertSame(ObservationAlertBridge::EXPIRY_RISK, $wf[0]['canonical_issue'],
            'Same word, different incident, different owner.');
    }

    // ── alert identity ───────────────────────────────────────────────────────

    public function test_identity_is_deterministic_and_observer_independent(): void
    {
        $a = $this->bridge->identity('x.com', ObservationAlertBridge::TLS_INVALID);
        $b = $this->bridge->identity('X.COM', ObservationAlertBridge::TLS_INVALID);
        $c = $this->bridge->identity('x.com', ObservationAlertBridge::DNS_BROKEN);

        $this->assertSame($a, $b, 'Identity must be case-insensitive on the subject.');
        $this->assertNotSame($a, $c, 'Different issue must be a different alert.');
    }

    public function test_repeated_observation_updates_one_alert(): void
    {
        $h = $this->subject();

        foreach (range(1, 3) as $_) {
            DB::table('infra_observation_facts')->where('subject', $h)->delete();
            $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('critical', 'Hostname does not resolve')]]);
            $this->bridge->bridgeSubject($h);
        }

        $this->assertCount(1, $this->alerts());
        $this->assertSame(3, (int) $this->alerts()->first()->occurrence_count);
    }

    public function test_severity_change_updates_the_same_alert_and_is_recorded(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'http', ['drift' => [EstateFixture::drift('medium', 'HTTPS returns 404')]]);
        $this->bridge->bridgeSubject($h);

        DB::table('infra_observation_facts')->where('subject', $h)->delete();
        $this->fx->observation($h, 'http', ['drift' => [EstateFixture::drift('critical', 'HTTPS returns 500 server error')]]);
        $this->bridge->bridgeSubject($h);

        $a = $this->alerts();
        $this->assertCount(1, $a, 'Worsening must update the alert an operator is already looking at.');
        $this->assertSame('critical', $a->first()->severity);
        $this->assertStringContainsString('severity_changed', (string) $a->first()->history_json);
    }

    // ── causal collapse / deduplication ──────────────────────────────────────

    public function test_dns_failure_collapses_tls_and_http_consequences(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('critical', 'Hostname does not resolve')]]);
        $this->fx->observation($h, 'certificate', ['drift' => [EstateFixture::drift('critical', 'TLS handshake failed')]]);
        $this->fx->observation($h, 'http', ['drift' => [EstateFixture::drift('critical', 'HTTPS is not answering')]]);

        $this->bridge->bridgeSubject($h);

        $a = $this->alerts();
        $this->assertCount(1, $a, 'Three symptoms of one broken thing must be one alert.');
        $this->assertSame(ObservationAlertBridge::DNS_BROKEN, $a->first()->drift_class,
            'The root cause survives, not a symptom.');
    }

    public function test_collapse_preserves_all_evidence(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('critical', 'Hostname does not resolve')]]);
        $this->fx->observation($h, 'certificate', ['drift' => [EstateFixture::drift('critical', 'TLS handshake failed')]]);
        $this->fx->observation($h, 'http', ['drift' => [EstateFixture::drift('critical', 'HTTPS is not answering')]]);

        $this->bridge->bridgeSubject($h);

        $detail = json_decode((string) $this->alerts()->first()->detail_json, true);
        $this->assertCount(3, $detail['evidence'],
            'Collapsing is pointless if the corroborating observations disappear.');
    }

    public function test_independent_problems_are_not_collapsed(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('high', 'MX records have disappeared')]]);
        $this->fx->observation($h, 'http', ['drift' => [EstateFixture::drift('medium', 'HTTPS response is very slow')]]);

        $this->bridge->bridgeSubject($h);

        $this->assertCount(2, $this->alerts(),
            'Mail failure and slow responses are different problems and must stay separate.');
    }

    // ── correlation ──────────────────────────────────────────────────────────

    public function test_incidents_group_alerts_by_correlation(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('high', 'MX records have disappeared')]]);
        $this->fx->observation($h, 'http', ['drift' => [EstateFixture::drift('medium', 'HTTPS response is very slow')]]);
        $this->bridge->bridgeSubject($h);

        $inc = $this->bridge->incidents();
        $groups = array_column($inc, 'correlation_group');

        $this->assertContains('communications', $groups);
        $this->assertContains('service_delivery', $groups);
    }

    public function test_incident_severity_is_the_highest_of_its_alerts(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'http', ['drift' => [
            EstateFixture::drift('medium', 'HTTPS response is very slow'),
            EstateFixture::drift('critical', 'HTTPS returns 500 server error'),
        ]]);
        $this->bridge->bridgeSubject($h);

        $this->assertSame('critical', $this->bridge->incidents()[0]['severity']);
    }

    // ── recovery ─────────────────────────────────────────────────────────────

    public function test_recovery_requires_a_successful_observation_of_a_covering_dimension(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('critical', 'Hostname does not resolve')]]);
        $this->bridge->bridgeSubject($h);
        $this->assertSame('open', $this->alerts()->first()->state);

        DB::table('infra_observation_facts')->where('subject', $h)->delete();
        $this->fx->observation($h, 'dns', ['drift' => []]);
        $this->bridge->bridgeSubject($h);

        $a = $this->alerts()->first();
        $this->assertSame('recovered', $a->state);
        $this->assertNotNull($a->recovered_by_observation_id, 'Recovery must name its proof.');
    }

    public function test_unsuccessful_observation_recovers_nothing(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('critical', 'Hostname does not resolve')]]);
        $this->bridge->bridgeSubject($h);

        DB::table('infra_observation_facts')->where('subject', $h)->delete();
        $this->fx->observation($h, 'dns', ['success' => false, 'error_code' => 'TIMEOUT']);
        $this->bridge->bridgeSubject($h);

        $this->assertNotSame('recovered', $this->alerts()->where('drift_class', ObservationAlertBridge::DNS_BROKEN)->first()->state,
            'An unreachable provider must never look like a resolved problem.');
    }

    public function test_success_in_an_unrelated_dimension_does_not_recover(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('critical', 'Hostname does not resolve')]]);
        $this->bridge->bridgeSubject($h);

        DB::table('infra_observation_facts')->where('subject', $h)->delete();
        // Only WHOIS succeeds. It cannot speak to whether DNS resolves.
        $this->fx->observation($h, 'whois', ['drift' => []]);
        $this->bridge->bridgeSubject($h);

        $this->assertSame('open', $this->alerts()->first()->state,
            'A DNS alert must not be closed because WHOIS happened to answer.');
    }

    // ── health pipeline ──────────────────────────────────────────────────────

    public function test_health_is_derived_from_alerts(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('critical', 'Hostname does not resolve')]]);
        $this->bridge->bridgeSubject($h);

        $res = $this->bridge->health($h);
        $this->assertSame('critical', $res['state']);
        $this->assertSame('alerts', $res['derived_from']);
        $this->assertSame(1, $res['alerts']);
    }

    public function test_health_is_unknown_without_fresh_successful_observation(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['success' => false, 'error_code' => 'TIMEOUT']);

        $this->assertSame('unknown', $this->bridge->health($h)['state']);
    }

    public function test_suppressed_alert_does_not_drag_health_down(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['drift' => [EstateFixture::drift('critical', 'Hostname does not resolve')]]);
        $this->bridge->bridgeSubject($h);

        DB::table('infra_estate_alerts')->update([
            'state' => 'suppressed', 'suppressed_until' => now()->addDay(),
        ]);

        $this->assertSame('healthy', $this->bridge->health($h)['state'],
            'Health and alerts must not disagree — that was the S8 defect this pipeline fixes.');
    }

    public function test_healthy_when_fresh_and_no_alerts(): void
    {
        $h = $this->subject();
        $this->fx->observation($h, 'dns', ['drift' => []]);

        $this->assertSame('healthy', $this->bridge->health($h)['state']);
    }
}
