<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Models\InfraCertificationCheck as Check;
use App\Engines\Infrastructure\Models\InfraCertificationWaiver as Waiver;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCertification as Cert;
use App\Engines\Infrastructure\Registry\CertificationChecklistRegistry as Checklist;
use App\Engines\Infrastructure\Services\ProviderCertificationService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Engines\Infrastructure\States\CertificationLevel as Level;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 2B-CERT: certification model, checklist, evidence, waivers, recertification.
 *
 * The theme is that a certification framework's only real failure mode is
 * CERTIFYING SOMETHING IT SHOULD NOT. Most of these tests therefore assert that
 * a level is NOT awarded, or that a pass is REFUSED.
 */
class ProviderCertificationTest extends TestCase
{
    use DatabaseTransactions;

    private ProviderCertificationService $svc;
    private ProviderRegistryService $registry;
    private InfraProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = app(ProviderCertificationService::class);
        $this->registry = app(ProviderRegistryService::class);

        $this->provider = $this->registry->register([
            'provider_key'  => 'cert-' . substr(md5(uniqid('', true)), 0, 8),
            'display_name'  => 'Certification Test Provider',
            'provider_type' => 'dns',
            'sandbox_ready' => true,
        ], 1);
    }

    private function start(int $target = Level::READ_ONLY_VERIFIED): Cert
    {
        return $this->svc->start($this->provider, 'dns', 'sandbox', $target, 1, 'tester');
    }

    /** Satisfy every check required for a level, with runtime evidence. */
    private function satisfyLevel(Cert $cert, int $level): void
    {
        foreach (Checklist::forLevel($level, 'dns') as $key => $meta) {
            $this->svc->recordCheck($cert, $key, Check::RESULT_PASS,
                Check::EVIDENCE_RUNTIME, 'probe://test', [], 1);
        }
    }

    // ── model + levels ────────────────────────────────────────────────────

    public function test_starting_precreates_every_applicable_check_as_not_tested(): void
    {
        $cert = $this->start();

        $this->assertGreaterThan(10, $cert->checks()->count());
        $this->assertSame(
            $cert->checks()->count(),
            $cert->checks()->where('result', Check::RESULT_NOT_TESTED)->count(),
            'Unanswered questions must exist, not be silently absent.'
        );
    }

    public function test_certification_starts_at_level_zero(): void
    {
        $this->assertSame(Level::REGISTERED, $this->start()->level);
    }

    public function test_capability_scoped_checks_are_excluded_for_other_capabilities(): void
    {
        $forDns  = Checklist::forLevel(Level::SANDBOX_VALIDATED, 'dns');
        $forMon  = Checklist::forLevel(Level::SANDBOX_VALIDATED, 'monitoring');

        // Propagation applies to dns/certificate/custom_hostname only.
        $this->assertArrayHasKey('capability.propagation_understood', $forDns);
        $this->assertArrayNotHasKey('capability.propagation_understood', $forMon);
    }

    // ── evidence integrity ────────────────────────────────────────────────

    /** The framework's own worst failure mode: certifying from documentation. */
    public function test_critical_check_cannot_pass_on_documentary_evidence(): void
    {
        $cert = $this->start();

        $this->expectExceptionMessageMatches('/require runtime observation/i');
        $this->svc->recordCheck($cert, 'ownership.resource_identity_verified',
            Check::RESULT_PASS, Check::EVIDENCE_DOCS, 'https://vendor/docs', [], 1);
    }

    public function test_non_critical_check_may_pass_on_documentary_evidence(): void
    {
        $cert = $this->start();

        $check = $this->svc->recordCheck($cert, 'capability.unsupported_documented',
            Check::RESULT_PASS, Check::EVIDENCE_DOCS, 'https://vendor/docs', [], 1);

        $this->assertSame(Check::RESULT_PASS, $check->result);
        $this->assertFalse($check->evidence_is_runtime);
    }

    public function test_runtime_flag_reflects_evidence_type(): void
    {
        $cert = $this->start();

        $api = $this->svc->recordCheck($cert, 'ownership.account_identifier_recorded',
            Check::RESULT_PASS, Check::EVIDENCE_READONLY_API, 'GET /zones/x', [], 1);
        $doc = $this->svc->recordCheck($cert, 'capability.unsupported_documented',
            Check::RESULT_PASS, Check::EVIDENCE_DOCS, 'docs', [], 1);

        $this->assertTrue($api->evidence_is_runtime);
        $this->assertFalse($doc->evidence_is_runtime);
    }

    public function test_evidence_is_sanitized(): void
    {
        $cert = $this->start();

        $check = $this->svc->recordCheck($cert, 'capability.unsupported_documented',
            Check::RESULT_PASS, Check::EVIDENCE_DOCS, 'docs',
            ['api_token' => 'SECRET-VALUE-XYZ', 'note' => 'fine'], 1);

        $this->assertSame('[redacted]', $check->evidence_json['api_token']);
        $this->assertSame('fine', $check->evidence_json['note']);
    }

    // ── unknown never equals pass ─────────────────────────────────────────

    public function test_not_tested_and_blocked_do_not_count_as_satisfied(): void
    {
        $cert = $this->start();

        $blocked = $this->svc->recordCheck($cert, 'ownership.resource_identity_verified',
            Check::RESULT_BLOCKED, Check::EVIDENCE_READONLY_API, 'HTTP 403', [], 1);

        $this->assertFalse($blocked->countsAsSatisfied());
        $this->assertTrue($blocked->isUnsatisfiedCritical());

        $notTested = $cert->checks()->where('result', Check::RESULT_NOT_TESTED)->first();
        $this->assertFalse($notTested->countsAsSatisfied());
    }

    public function test_not_applicable_counts_as_satisfied(): void
    {
        $cert = $this->start();
        $c = $this->svc->recordCheck($cert, 'capability.unsupported_documented',
            Check::RESULT_NOT_APPLICABLE, Check::EVIDENCE_DOCS, 'n/a', [], 1);

        $this->assertTrue($c->countsAsSatisfied());
    }

    // ── level award is computed, never asserted ───────────────────────────

    public function test_level_one_awarded_only_when_all_required_checks_pass(): void
    {
        $cert = $this->start(Level::READ_ONLY_VERIFIED);
        $this->satisfyLevel($cert, Level::READ_ONLY_VERIFIED);

        $concluded = $this->svc->conclude($cert->fresh(), 1);

        $this->assertSame(Level::READ_ONLY_VERIFIED, $concluded->level);
        $this->assertSame(Cert::STATUS_PASSED, $concluded->status);
    }

    public function test_one_blocked_critical_check_prevents_level_one(): void
    {
        $cert = $this->start(Level::READ_ONLY_VERIFIED);
        $this->satisfyLevel($cert, Level::READ_ONLY_VERIFIED);

        // Now break exactly one critical check.
        $this->svc->recordCheck($cert, 'credentials.granted_scopes_verified',
            Check::RESULT_BLOCKED, Check::EVIDENCE_READONLY_API, 'HTTP 403', [], 1);

        $concluded = $this->svc->conclude($cert->fresh(), 1);

        $this->assertSame(Level::REGISTERED, $concluded->level,
            'A single unsatisfied critical check must prevent the level.');
        $this->assertNotEmpty($concluded->blockers_json);
    }

    public function test_awarded_level_may_be_lower_than_target_without_error(): void
    {
        $cert = $this->start(Level::PRODUCTION_APPROVED);
        // Only satisfy level 1.
        $this->satisfyLevel($cert, Level::READ_ONLY_VERIFIED);

        $concluded = $this->svc->conclude($cert->fresh(), 1);

        $this->assertSame(Level::READ_ONLY_VERIFIED, $concluded->level);
        $this->assertSame(Level::PRODUCTION_APPROVED, $concluded->level_target);
        $this->assertNotEmpty($concluded->blockers_json);
    }

    public function test_blockers_name_the_specific_failing_checks(): void
    {
        $cert = $this->start(Level::SANDBOX_VALIDATED);
        $this->satisfyLevel($cert, Level::READ_ONLY_VERIFIED);

        $concluded = $this->svc->conclude($cert->fresh(), 1);
        $keys = array_column($concluded->blockers_json, 'check_key');

        $this->assertNotEmpty($keys);
        foreach ($concluded->blockers_json as $b) {
            $this->assertArrayHasKey('description', $b);
            $this->assertArrayHasKey('blocks_level', $b);
        }
    }

    public function test_level_four_never_awarded_without_governance_and_commercial(): void
    {
        $cert = $this->start(Level::PRODUCTION_APPROVED);
        $this->satisfyLevel($cert, Level::STAGING_CERTIFIED);

        $concluded = $this->svc->conclude($cert->fresh(), 1);

        $this->assertLessThan(Level::PRODUCTION_APPROVED, $concluded->level);
        $this->assertFalse($concluded->permitsProduction());
    }

    public function test_only_level_four_permits_production_selection(): void
    {
        $this->assertFalse(Level::permitsProductionSelection(Level::STAGING_CERTIFIED));
        $this->assertTrue(Level::permitsProductionSelection(Level::PRODUCTION_APPROVED));
    }

    // ── waivers ───────────────────────────────────────────────────────────

    /** 🔴 The rule that keeps waivers from becoming a bypass. */
    public function test_critical_check_cannot_be_waived(): void
    {
        $cert = $this->start();

        $this->expectExceptionMessageMatches('/CRITICAL and cannot be waived/i');
        $this->svc->applyWaiver($cert, 'ownership.resource_identity_verified',
            'risk', 'because', 1, now()->addDays(30), 'none', 990016);
    }

    public function test_waiver_requires_a_different_approver(): void
    {
        $cert = $this->start();

        $this->expectExceptionMessageMatches('/may not be approved by the person who requested/i');
        $this->svc->applyWaiver($cert, 'capability.unsupported_documented',
            'risk', 'because', 1, now()->addDays(30), 'control', 1);
    }

    public function test_waiver_must_expire_in_the_future(): void
    {
        $cert = $this->start();

        $this->expectExceptionMessageMatches('/must expire in the future/i');
        $this->svc->applyWaiver($cert, 'capability.unsupported_documented',
            'risk', 'because', 1, now()->subDay(), 'control', 990016);
    }

    /** A waiver downgrades to pass_with_condition — never to an unconditional pass. */
    public function test_waiver_produces_pass_with_condition_not_pass(): void
    {
        $cert = $this->start();

        $this->svc->recordCheck($cert, 'capability.unsupported_documented',
            Check::RESULT_FAIL, Check::EVIDENCE_DOCS, 'missing', [], 1);

        $this->svc->applyWaiver($cert, 'capability.unsupported_documented',
            'minor gap', 'documented later', 1, now()->addDays(30), 'tracked', 990016);

        $check = $cert->checks()->where('check_key', 'capability.unsupported_documented')->first();

        $this->assertSame(Check::RESULT_PASS_CONDITION, $check->result);
        $this->assertNotSame(Check::RESULT_PASS, $check->result);
    }

    public function test_conditional_pass_caps_certification_status(): void
    {
        $cert = $this->start(Level::READ_ONLY_VERIFIED);
        $this->satisfyLevel($cert, Level::READ_ONLY_VERIFIED);

        $this->svc->recordCheck($cert, 'capability.unsupported_documented',
            Check::RESULT_FAIL, Check::EVIDENCE_DOCS, 'gap', [], 1);
        $this->svc->applyWaiver($cert, 'capability.unsupported_documented',
            'minor', 'later', 1, now()->addDays(30), 'tracked', 990016);

        $concluded = $this->svc->conclude($cert->fresh(), 1);

        $this->assertSame(Cert::STATUS_CONDITIONS, $concluded->status,
            'A waived check must remain visible in the verdict.');
    }

    public function test_unapproved_waiver_is_not_effective(): void
    {
        $cert = $this->start();

        $w = $this->svc->applyWaiver($cert, 'capability.unsupported_documented',
            'risk', 'because', 1, now()->addDays(30), 'control', null);

        $this->assertSame(Waiver::STATUS_PENDING, $w->status);
        $this->assertFalse($w->isEffective());
    }

    // ── immutability + recertification ────────────────────────────────────

    public function test_concluded_certification_cannot_be_edited(): void
    {
        $cert = $this->start();
        $this->satisfyLevel($cert, Level::READ_ONLY_VERIFIED);
        $concluded = $this->svc->conclude($cert->fresh(), 1);

        $this->expectExceptionMessageMatches('/concluded/i');
        $concluded->update(['notes' => 'rewriting history']);
    }

    public function test_certification_cannot_be_deleted(): void
    {
        $cert = $this->start();

        $this->expectExceptionMessageMatches('/permanent history/i');
        $cert->delete();
    }

    public function test_checks_cannot_be_recorded_after_conclusion(): void
    {
        $cert = $this->start();
        $this->satisfyLevel($cert, Level::READ_ONLY_VERIFIED);
        $this->svc->conclude($cert->fresh(), 1);

        $this->expectExceptionMessageMatches('/concluded/i');
        $this->svc->recordCheck($cert->fresh(), 'capability.unsupported_documented',
            Check::RESULT_PASS, Check::EVIDENCE_DOCS, 'late', [], 1);
    }

    public function test_recertification_supersedes_without_overwriting(): void
    {
        $first = $this->start();
        $this->satisfyLevel($first, Level::READ_ONLY_VERIFIED);
        $first = $this->svc->conclude($first->fresh(), 1);

        $second = $this->svc->recertify($first, 'credential_rotation', Level::READ_ONLY_VERIFIED, 1);
        $this->satisfyLevel($second, Level::READ_ONLY_VERIFIED);
        $second = $this->svc->conclude($second->fresh(), 1);

        $this->assertSame($first->id, $second->supersedes_id);
        $this->assertSame($second->id, $first->fresh()->superseded_by_id);
        // The original verdict survives untouched.
        $this->assertNotNull(Cert::find($first->id));
        $this->assertSame(Level::READ_ONLY_VERIFIED, $first->fresh()->level);
    }

    public function test_superseded_certification_is_no_longer_current(): void
    {
        $first = $this->start();
        $this->satisfyLevel($first, Level::READ_ONLY_VERIFIED);
        $first = $this->svc->conclude($first->fresh(), 1);
        $this->assertTrue($first->fresh()->isCurrent());

        $second = $this->svc->recertify($first, 'ownership_change', Level::READ_ONLY_VERIFIED, 1);
        $this->satisfyLevel($second, Level::READ_ONLY_VERIFIED);
        $this->svc->conclude($second->fresh(), 1);

        $this->assertFalse($first->fresh()->isCurrent());
    }

    public function test_recertification_triggers_are_enumerated(): void
    {
        $t = ProviderCertificationService::recertificationTriggers();

        foreach (['credential_scope_change', 'credential_rotation', 'provider_account_transfer',
                  'security_incident', 'ownership_change', 'certification_expiry'] as $expected) {
            $this->assertContains($expected, $t);
        }
    }

    public function test_current_returns_only_valid_certification(): void
    {
        $cert = $this->start();
        $this->satisfyLevel($cert, Level::READ_ONLY_VERIFIED);
        $this->svc->conclude($cert->fresh(), 1);

        $this->assertNotNull($this->svc->current($this->provider, 'dns', 'sandbox'));
        $this->assertNull($this->svc->current($this->provider, 'certificate', 'sandbox'));
    }
}
