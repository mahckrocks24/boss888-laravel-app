<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Observation\AlertEligibility as E;
use App\Engines\Infrastructure\Observation\Custody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Infrastructure\Support\EstateFixture;
use Tests\TestCase;

/**
 * INFRA888 S8.2/S8.3 — eligibility policy and its architectural guards.
 */
class EligibilityTest extends TestCase
{
    use RefreshDatabase;

    private EstateFixture $fx;
    private E $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = EstateFixture::make('elig');
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
            'first_seen_at' => now(), 'last_seen_at' => now(),
            'occurrence_count' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $over));

        return DB::table('infra_estate_alerts')->where('id', $id)->first();
    }

    /** A domain that is served and has resolved: a real production asset. */
    private function realCustomerDomain(string $host): void
    {
        $this->fx->servedWebsite($host);
        $this->fx->observation($host, 'dns', ['success' => true, 'confidence' => Custody::VERIFIED]);
    }

    private function verdict(object $a): string
    {
        return $this->policy->evaluate($a, false)['verdict'];
    }

    // ── customer safety (Stage 4) ────────────────────────────────────────────

    /**
     * SUPERSEDED CONTRACT (S8.3 -> S8.4, ratified 2026-08-02).
     *
     * FORMER expectation: an asset registered by us with no DNS resolution was
     * INELIGIBLE / internal_asset immediately.
     *
     * WHY SUPERSEDED: that description also fits a brand-new legitimate customer
     * domain for its first hours, before DNS propagates and before the website is
     * attached. Under the old contract its alerts were silently dropped — failing
     * silently on exactly the asset a customer just paid for.
     *
     * NEW expectation: DEFERRED / pending_validation. Not delivered, not
     * permanently excluded, visible to operators as awaiting validation.
     */
    public function test_cold_start_asset_is_deferred_not_internal_superseding_s83(): void
    {
        $this->fx->registeredDomain('anything-at-all.com');
        $this->fx->observation('anything-at-all.com', 'dns', ['success' => false, 'error_code' => 'NXDOMAIN']);

        $d = $this->policy->evaluate($this->alert('anything-at-all.com'), false);

        $this->assertSame(E::DEFERRED, $d['verdict'], 'new contract: cold start defers');
        $this->assertNotSame(E::INELIGIBLE, $d['verdict'], 'must not be permanently excluded');
        $this->assertStringContainsString('pending_validation', $d['reason']);
        $this->assertStringNotContainsString('anything-at-all', json_encode(E::rulePipeline()),
            'Safety must not depend on a hostname list.');
    }

    public function test_sandbox_registration_never_qualifies(): void
    {
        // Established (resolves + serves) so lifecycle passes; the sandbox rule
        // is what must block it. Under S8.4 an unestablished asset defers first.
        config(['namecheap.environment' => 'sandbox']);
        $this->fx->registeredDomain('sandbox-asset.com');
        $this->fx->servedWebsite('sandbox-asset.com');
        $this->fx->observation('sandbox-asset.com', 'dns', ['success' => true]);

        $this->assertSame(E::INELIGIBLE, $this->verdict($this->alert('sandbox-asset.com')));
    }

    /** New contract: a genuine internal asset, past grace and repeatedly failing, is still INELIGIBLE. */
    public function test_established_internal_asset_is_ineligible_after_grace(): void
    {
        $old = now()->subHours(\App\Engines\Infrastructure\Observation\AssetLifecycle::COLD_START_GRACE_HOURS + 24);
        $this->fx->registeredDomain('proven-scaffold.com', ['created_at' => $old]);

        foreach (range(1, 3) as $_) {
            $this->fx->observation('proven-scaffold.com', 'dns',
                ['success' => false, 'error_code' => 'NXDOMAIN', 'observed_at' => $old]);
        }

        $d = $this->policy->evaluate($this->alert('proven-scaffold.com'), false);

        $this->assertSame(E::INELIGIBLE, $d['verdict']);
        $this->assertSame('internal_asset', $d['deciding_rule']);
    }

    /** New contract: DEFERRED assets are still not deliverable. */
    public function test_deferred_asset_is_not_delivery_eligible(): void
    {
        $this->fx->registeredDomain('deferred-check.com');
        $this->fx->observation('deferred-check.com', 'dns', ['success' => false, 'error_code' => 'NXDOMAIN']);

        $this->assertNotSame(E::ELIGIBLE, $this->verdict($this->alert('deferred-check.com')),
            'Deferred must never page or deliver.');
    }

    public function test_retired_asset_never_qualifies(): void
    {
        $this->fx->registeredDomain('retired.com', ['status' => 'released']);
        $this->fx->observation('retired.com', 'dns', ['success' => true]);

        $d = $this->policy->evaluate($this->alert('retired.com'), false);

        $this->assertSame(E::INELIGIBLE, $d['verdict']);
        $this->assertSame('asset_retired', $d['deciding_rule']);
    }

    public function test_unowned_hostname_goes_to_manual_review_not_out(): void
    {
        $d = $this->policy->evaluate($this->alert('never-heard-of-it.com'), false);

        $this->assertSame(E::MANUAL_REVIEW, $d['verdict']);
        $this->assertSame('asset_ownership', $d['deciding_rule']);
    }

    // ── the positive path must actually work ─────────────────────────────────

    public function test_real_customer_critical_alert_is_eligible(): void
    {
        $this->realCustomerDomain('real-customer.com');

        $d = $this->policy->evaluate($this->alert('real-customer.com'), false);

        $this->assertSame(E::ELIGIBLE, $d['verdict'],
            'A genuine customer outage must get through — over-blocking is the worse failure.');
    }

    // ── individual rules ─────────────────────────────────────────────────────

    public function test_acknowledged_alert_is_suppressed(): void
    {
        $this->realCustomerDomain('ack.com');

        $this->assertSame(E::SUPPRESSED, $this->verdict($this->alert('ack.com', ['state' => 'acknowledged'])));
    }

    public function test_suppressed_alert_stays_suppressed(): void
    {
        $this->realCustomerDomain('supp.com');

        $this->assertSame(E::SUPPRESSED, $this->verdict($this->alert('supp.com', [
            'state' => 'suppressed', 'suppressed_until' => now()->addDay(),
        ])));
    }

    public function test_recovered_alert_is_ineligible(): void
    {
        $this->realCustomerDomain('rec.com');

        $this->assertSame(E::INELIGIBLE, $this->verdict($this->alert('rec.com', ['state' => 'recovered'])));
    }

    public function test_below_threshold_severity_is_deferred_not_discarded(): void
    {
        $this->realCustomerDomain('low.com');

        $d = $this->policy->evaluate($this->alert('low.com', ['severity' => 'medium']), false);

        $this->assertSame(E::DEFERRED, $d['verdict']);
        $this->assertStringContainsString('digest', $d['reason'],
            'Below threshold means it does not interrupt, not that it vanishes.');
    }

    public function test_unknown_confidence_defers(): void
    {
        // SUPERSEDED DETAIL: previously the deciding rule was observation_confidence.
        // Under S8.4 an unestablished asset defers at lifecycle first. The verdict
        // is unchanged (DEFERRED) and that is what the contract guarantees.
        $this->fx->servedWebsite('nodata.com');
        $this->fx->observation('nodata.com', 'dns', ['success' => false, 'error_code' => 'TIMEOUT']);

        $d = $this->policy->evaluate($this->alert('nodata.com'), false);
        $this->assertSame(E::DEFERRED, $d['verdict']);
        $this->assertContains($d['deciding_rule'], ['internal_asset', 'observation_confidence']);
    }

    public function test_inferred_evidence_below_critical_defers_but_critical_passes(): void
    {
        $this->fx->servedWebsite('inf.com');
        $this->fx->observation('inf.com', 'dns', ['success' => true, 'confidence' => Custody::VERIFIED]);
        $this->fx->observation('inf.com', 'whois', ['success' => true, 'confidence' => Custody::INFERRED]);

        $high = $this->alert('inf.com', ['severity' => 'high']);
        $this->assertSame(E::DEFERRED, $this->verdict($high));

        $crit = $this->alert('inf.com', ['severity' => 'critical']);
        $this->assertSame(E::ELIGIBLE, $this->verdict($crit),
            'A possibly-expired customer domain is worth a conversation even on imperfect evidence.');
    }

    public function test_maintenance_window_suppresses(): void
    {
        $this->realCustomerDomain('maint.com');
        config(['infrastructure.maintenance_windows' => [
            ['from' => now()->subHour()->toDateTimeString(), 'to' => now()->addHour()->toDateTimeString()],
        ]]);

        $this->assertSame(E::SUPPRESSED, $this->verdict($this->alert('maint.com')));
    }

    public function test_duplicate_cooldown_defers_a_repeat(): void
    {
        $this->realCustomerDomain('dupe.com');
        $a = $this->alert('dupe.com');

        $this->assertSame(E::ELIGIBLE, $this->policy->evaluate($a, true)['verdict']);
        $this->assertSame(E::DEFERRED, $this->policy->evaluate($a, false)['verdict'],
            'The same alert must not leave twice inside the cooldown.');
    }

    // ── audit (Stage 3) ──────────────────────────────────────────────────────

    public function test_every_decision_records_a_full_rule_trail(): void
    {
        $this->realCustomerDomain('audit.com');
        $this->policy->evaluate($this->alert('audit.com'), true);

        $row = DB::table('infra_alert_eligibility_decisions')->first();
        $trail = json_decode((string) $row->trail_json, true);

        $this->assertNotEmpty($trail);

        foreach ($trail as $t) {
            $this->assertArrayHasKey('rule', $t);
            $this->assertArrayHasKey('result', $t);
            $this->assertArrayHasKey('reason', $t);
            $this->assertArrayHasKey('at', $t);
        }
    }

    public function test_audit_records_the_deciding_rule_for_a_block(): void
    {
        $this->fx->registeredDomain('blocked.com', ['status' => 'released']);
        $this->fx->observation('blocked.com', 'dns', ['success' => true]);
        $this->policy->evaluate($this->alert('blocked.com'), true);

        $row = DB::table('infra_alert_eligibility_decisions')->first();

        $this->assertSame('asset_retired', $row->deciding_rule);
        $this->assertNotEmpty($row->reason);
    }

    // ── architecture guards (Stage 5) ────────────────────────────────────────

    private function source(): string
    {
        $f = '/var/www/levelup-staging/app/Engines/Infrastructure/Observation/AlertEligibility.php';
        $out = '';

        foreach (token_get_all((string) file_get_contents($f)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    public function test_eligibility_never_delivers(): void
    {
        $this->assertSame(0, preg_match('/Mail::|Notification::|->notify\(|Http::post|curl_exec/', $this->source()));
    }

    public function test_eligibility_never_schedules_or_dispatches(): void
    {
        $this->assertSame(0, preg_match('/Schedule::|dispatch\(|event\(|Queue::|Bus::/', $this->source()));
    }

    public function test_eligibility_never_mutates_assets(): void
    {
        $this->assertSame(0, preg_match(
            "/table\(\s*'(customer_domains|websites|custom_domains|infra_observation_facts|users|workspaces)'\s*\)\s*->\s*(update|insert|insertGetId|delete)\s*\(/",
            $this->source()));
    }

    public function test_eligibility_never_changes_alert_state(): void
    {
        $this->assertSame(0, preg_match(
            "/table\(\s*'infra_estate_alerts'\s*\)\s*->[^;]*->\s*(update|delete|insert)\s*\(/",
            $this->source()),
            'Eligibility evaluates; it must never acknowledge, suppress or recover an alert.');
    }

    public function test_eligibility_only_writes_its_own_audit_table(): void
    {
        preg_match_all("/table\(\s*'([a-z_]+)'\s*\)\s*->\s*(?:insert|update|delete)/", $this->source(), $m);

        $this->assertSame(['infra_alert_eligibility_decisions'], array_values(array_unique($m[1] ?? [])));
    }
}
