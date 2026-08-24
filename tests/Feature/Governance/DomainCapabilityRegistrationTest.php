<?php

namespace Tests\Feature\Governance;

use App\Core\Governance\DomainCapabilityRegistry;
use RuntimeException;
use Tests\TestCase;

/**
 * E1 — CAPABILITY REGISTRATION ENFORCEMENT
 *
 * Asserts that every domain capability is DECLARED with a complete governance
 * contract, and that the registry is fail-closed for anything unlisted.
 *
 * What this test deliberately does NOT do: assert that the declarations are
 * enforced at runtime. They are not, and Phase 0 forbids wiring them in. The
 * test instead requires each declaration to state honestly which controls are
 * 'enforced' today and which are merely 'declared', so the gap is visible in
 * CI rather than discovered in an architecture review.
 */
class DomainCapabilityRegistrationTest extends TestCase
{
    /** The capability set approved by Mark on 2026-07-29. */
    private const APPROVED_KEYS = [
        'domain.search',
        'domain.price',
        'domain.register',
        'domain.list',
        'domain.view',
        'domain.sync',
        'domain.renew',
        'domain.transfer.request',
        'domain.transfer.status',
        'domain.nameservers.update',
        'domain.privacy.update',
        'domain.autorenew.update',
    ];

    // ───────────────────────────────────────────── completeness ──

    public function test_every_approved_capability_is_declared(): void
    {
        $declared = DomainCapabilityRegistry::keys();

        foreach (self::APPROVED_KEYS as $key) {
            $this->assertContains($key, $declared, "Approved capability '{$key}' is not declared");
        }
    }

    public function test_no_undeclared_extras_have_crept_in(): void
    {
        $extra = array_diff(DomainCapabilityRegistry::keys(), self::APPROVED_KEYS);

        $this->assertSame([], array_values($extra),
            'Capabilities exist that were not in the approved set: ' . implode(', ', $extra)
            . '. Adding a capability requires approval, not just a code change.');
    }

    public function test_every_declaration_carries_every_required_field(): void
    {
        foreach (DomainCapabilityRegistry::all() as $key => $decl) {
            foreach (DomainCapabilityRegistry::REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $decl, "{$key} is missing required field '{$field}'");
            }
        }
    }

    public function test_capability_key_matches_engine_and_action(): void
    {
        foreach (DomainCapabilityRegistry::all() as $key => $decl) {
            $this->assertSame($key, $decl['capability_key'], "array key and capability_key disagree for {$key}");
            $this->assertSame('domain', $decl['engine'], "{$key} must live in the 'domain' engine namespace");
            $this->assertSame($key, $decl['engine'] . '.' . $decl['action'],
                "{$key} does not follow the engine.action idiom");
        }
    }

    // ───────────────────────────────────────────── vocabularies ──

    public function test_risk_reversibility_and_cost_use_the_approved_vocabulary(): void
    {
        $risks = ['read', 'low', 'medium', 'high', 'irreversible'];
        $revs  = ['n/a', 'reversible', 'compensable', 'irreversible'];
        $costs = ['free', 'metered', 'billable'];

        foreach (DomainCapabilityRegistry::all() as $key => $decl) {
            $this->assertContains($decl['risk'], $risks, "{$key} has an unknown risk class");
            $this->assertContains($decl['reversibility'], $revs, "{$key} has an unknown reversibility class");
            $this->assertContains($decl['cost_class'], $costs, "{$key} has an unknown cost class");
            $this->assertContains($decl['approval'], ['none', 'confirm', 'review', 'sod'],
                "{$key} has an unknown approval policy");
        }
    }

    // ───────────────────────────────────── reads vs writes ──

    public function test_reads_are_not_treated_like_writes(): void
    {
        foreach (DomainCapabilityRegistry::all() as $key => $decl) {
            if ($decl['risk'] !== 'read') {
                continue;
            }

            $this->assertSame('free', $decl['cost_class'], "read capability {$key} must be free");
            $this->assertSame('none', $decl['approval'], "read capability {$key} must not require approval");
            $this->assertSame('n/a', $decl['reversibility'], "read capability {$key} has no reversibility");
            $this->assertSame([], $decl['emits'], "read capability {$key} must not emit business events");
        }
    }

    // ─────────────────────────────── the money rules ──

    public function test_billable_capabilities_declare_an_idempotency_strategy(): void
    {
        $billable = DomainCapabilityRegistry::billableKeys();

        $this->assertNotEmpty($billable, 'expected at least one billable capability');

        foreach ($billable as $key) {
            $decl = DomainCapabilityRegistry::forKey($key);

            $this->assertNotSame('not_required', $decl['idempotency'],
                "billable capability {$key} MUST declare an idempotency strategy");
            $this->assertNotEmpty($decl['idempotency'], "billable capability {$key} has an empty idempotency strategy");
        }
    }

    public function test_billable_capabilities_never_auto_retry_on_an_ambiguous_failure(): void
    {
        foreach (DomainCapabilityRegistry::billableKeys() as $key) {
            $decl = DomainCapabilityRegistry::forKey($key);

            $this->assertContains($decl['retry'], ['no_auto_retry_on_ambiguous', 'never'],
                "billable capability {$key} must not auto-retry an ambiguous failure — that is how a customer gets charged twice");
        }
    }

    public function test_billable_capabilities_always_audit_and_notify(): void
    {
        foreach (DomainCapabilityRegistry::billableKeys() as $key) {
            $decl = DomainCapabilityRegistry::forKey($key);

            $this->assertSame('always', $decl['audit'], "billable capability {$key} must always audit");
            $this->assertNotSame('none', $decl['notification'],
                "billable capability {$key} must declare a notification policy");
        }
    }

    /**
     * The central agent-safety rule. An agent may propose a purchase; it may
     * never execute one. Renewal is the single exception, and only inside a
     * human-authorised budget envelope, because renewal preserves an asset the
     * customer already owns rather than creating a new obligation.
     */
    public function test_no_agent_may_execute_an_irreversible_billable_capability(): void
    {
        foreach (DomainCapabilityRegistry::all() as $key => $decl) {
            if ($decl['cost_class'] !== 'billable') {
                continue;
            }

            if ($key === 'domain.renew') {
                $this->assertSame('budget_envelope_only', $decl['agent_execute'],
                    'renewal is the only envelope-eligible billable capability');
                continue;
            }

            $this->assertFalse($decl['agent_execute'],
                "{$key} is billable and must never be agent-executable");
        }
    }

    public function test_transfer_requires_separation_of_duties(): void
    {
        $decl = DomainCapabilityRegistry::forKey('domain.transfer.request');

        $this->assertTrue($decl['separation_of_duties'],
            'a transfer requester must never be the approver — this is the standard domain-theft vector');
        $this->assertSame('sod', $decl['approval']);
        $this->assertFalse($decl['agent_execute'], 'transfers are never agent-executable under any envelope');
        $this->assertSame('never', $decl['retry']);
    }

    // ─────────────────────────── provider verification ──

    public function test_capabilities_whose_writes_can_silently_fail_require_read_back(): void
    {
        // The registrar returns Status="OK" for an auto-renew change it does not
        // apply (observed 2026-07-29 in sandbox). Any capability that mutates
        // provider-side configuration must verify by reading it back.
        foreach (['domain.autorenew.update', 'domain.nameservers.update'] as $key) {
            $this->assertSame('read_back_required', DomainCapabilityRegistry::forKey($key)['provider_verification'],
                "{$key} must verify by read-back; an OK envelope is not an effect");
        }
    }

    public function test_registration_requires_an_ownership_precheck(): void
    {
        $this->assertSame('ownership_precheck_required',
            DomainCapabilityRegistry::forKey('domain.register')['provider_verification'],
            'registration must ask the registrar whether we already own the domain before spending');
    }

    // ───────────────────────────────────── fail-closed ──

    public function test_unknown_capability_key_is_denied(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DENIED/');

        DomainCapabilityRegistry::forKey('domain.delete_everything');
    }

    public function test_has_returns_false_for_unknown_keys(): void
    {
        $this->assertFalse(DomainCapabilityRegistry::has('domain.not_a_thing'));
        $this->assertTrue(DomainCapabilityRegistry::has('domain.register'));
    }

    // ──────────────────────── honesty about enforcement ──

    public function test_every_declaration_states_its_enforcement_position(): void
    {
        foreach (DomainCapabilityRegistry::all() as $key => $decl) {
            $this->assertIsArray($decl['enforcement'], "{$key} must declare an enforcement position");
            $this->assertNotEmpty($decl['enforcement'], "{$key} must name at least one control and its state");

            foreach ($decl['enforcement'] as $control => $state) {
                $this->assertContains($state, ['enforced', 'declared'],
                    "{$key}.{$control} has an unknown enforcement state '{$state}'");
            }
        }
    }

    /**
     * Phase 0 is a declaration milestone. Most controls are NOT yet in force,
     * and that must be true in the fixture as well as in reality — if this ever
     * reports zero, someone has claimed enforcement that does not exist.
     */
    public function test_declared_but_unenforced_controls_are_visible(): void
    {
        $gaps = DomainCapabilityRegistry::declaredNotEnforced();

        $this->assertNotEmpty($gaps,
            'Phase 0 declares far more than it enforces. An empty gap list means a declaration is lying.');

        // Recorded for the report; not an assertion about the count.
        fwrite(STDERR, "\n  [E1] declared-but-not-enforced controls: " . count($gaps) . "\n");
    }

    public function test_controls_proven_by_existing_tests_are_marked_enforced(): void
    {
        // These are already true in production and covered by the domain suite.
        // Marking them 'declared' would understate what is protected today.
        $register = DomainCapabilityRegistry::forKey('domain.register')['enforcement'];

        $this->assertSame('enforced', $register['idempotency'] ?? null,
            'five-layer idempotency is proven by DomainCommerceTest and a real crash recovery');
        $this->assertSame('enforced', $register['no_auto_retry'] ?? null);
        $this->assertSame('enforced', $register['tenancy'] ?? null);
        $this->assertSame('enforced', $register['ownership_check'] ?? null);
    }
}
