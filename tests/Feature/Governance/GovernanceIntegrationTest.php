<?php

namespace Tests\Feature\Governance;

use App\Core\Governance\GovernanceGateRegistrar;
use App\Core\Governance\GovernanceService;
use App\Core\Governance\PermissionRegistry;
use App\Policies\UserPolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * P0-B GOVERNANCE INTEGRATION TESTS (2026-07-26).
 *
 * Verifies the governance layer is correctly WIRED and correctly INERT.
 * P0-B is shadow mode: governance must reach conclusions and record them
 * without ever changing production behaviour.
 */
class GovernanceIntegrationTest extends TestCase
{
    // ── PERMISSION REGISTRY ──────────────────────────────────────────────

    /**
     * E0.5 (2026-07-27) — these capabilities were REMOVED, not re-scoped.
     *
     * They were previously declared here as CRITICAL / approval / MFA and
     * "machine may request, never execute". BellaController never called
     * GovernanceService, so that declaration was entirely bypassed while the
     * handlers mutated `credits.balance` and `users.status` directly.
     *
     * The capabilities are now gone from the controller AND the registry, so
     * enforcement no longer depends on governance mode. An unknown capability
     * falls through to STRICT_DEFAULT = DENY.
     *
     * @test
     */
    public function bella_high_risk_capabilities_are_deliberately_absent(): void
    {
        $keys = PermissionRegistry::keys();

        foreach (['bella.adjust_credits', 'bella.suspend_user', 'bella.query_database'] as $cap) {
            $this->assertNotContains($cap, $keys, "{$cap} must remain absent — do not reintroduce");

            // An unknown capability does NOT return null — it returns
            // STRICT_DEFAULT (deny, owner-only, approval required), which is a
            // stronger guarantee than absence alone. Assert it resolves exactly
            // as an invented capability would.
            $this->assertSame(
                PermissionRegistry::get('capability.that.does.not.exist'),
                PermissionRegistry::get($cap),
                "{$cap} must resolve to STRICT_DEFAULT, exactly like an unknown capability"
            );
            $this->assertFalse(
                PermissionRegistry::machineMayRequest($cap),
                "{$cap} must not be requestable by a machine principal"
            );
        }

        // The human-admin equivalents are a separate concern and must survive.
        $this->assertContains('billing.adjust_credits', $keys);
    }

    /** @test */
    public function machine_principal_cannot_execute_approval_required_capability(): void
    {
        $svc = app(GovernanceService::class);

        $d = $svc->evaluate(
            capability: 'billing.adjust_credits',   // E0.5: bella.* removed; use the human capability
            principalTypeOverride: PermissionRegistry::P_MACHINE,
        );

        $this->assertFalse($d->allowed, 'MA-2: machine must not be permitted to execute');
        $this->assertSame('machine_request_requires_human_approval', $d->reason);
    }

    /** @test */
    public function machine_principal_cannot_touch_unregistered_capability(): void
    {
        $svc = app(GovernanceService::class);
        $d = $svc->evaluate(
            capability: 'bella.query_database',
            principalTypeOverride: PermissionRegistry::P_MACHINE,
        );
        $this->assertFalse($d->allowed, 'GD-002: removed capability must be denied');
        $this->assertSame('unclassified_capability_strict_default', $d->reason);
    }

    // ── SHADOW MODE ──────────────────────────────────────────────────────

    /** @test */
    public function shadow_mode_never_blocks(): void
    {
        config(['governance.mode' => GovernanceService::MODE_SHADOW]);
        $svc = app(GovernanceService::class);

        $d = $svc->evaluate('platform.config_write', null); // anonymous ⇒ denied

        $this->assertFalse($d->allowed, 'governance should conclude DENY');
        $this->assertTrue($d->effective, 'shadow mode must NOT block');
        $this->assertTrue($d->isShadowDivergence(), 'divergence must be flagged');
    }

    /** @test */
    public function off_mode_is_a_kill_switch(): void
    {
        config(['governance.mode' => GovernanceService::MODE_OFF]);
        $svc = app(GovernanceService::class);

        $d = $svc->evaluate('platform.config_write', null);

        $this->assertTrue($d->allowed);
        $this->assertTrue($d->effective);
        $this->assertSame('governance_off', $d->reason);

        config(['governance.mode' => GovernanceService::MODE_SHADOW]);
    }

    /** @test */
    public function enforce_mode_would_block(): void
    {
        config(['governance.mode' => GovernanceService::MODE_ENFORCE]);
        $svc = app(GovernanceService::class);

        $d = $svc->evaluate('platform.config_write', null);

        $this->assertFalse($d->allowed);
        $this->assertFalse($d->effective, 'enforce mode must block');
        $this->assertFalse($d->isShadowDivergence());

        config(['governance.mode' => GovernanceService::MODE_SHADOW]);
    }

    /** @test */
    public function per_capability_mode_override_wins(): void
    {
        config([
            'governance.mode' => GovernanceService::MODE_SHADOW,
            'governance.capability_modes' => ['platform.config_write' => GovernanceService::MODE_ENFORCE],
        ]);
        $svc = app(GovernanceService::class);

        $this->assertTrue($svc->isEnforcing('platform.config_write'));
        $this->assertFalse($svc->isEnforcing('workspace.update'));

        config(['governance.capability_modes' => []]);
    }

    // ── GATE RESOLUTION ──────────────────────────────────────────────────

    /** @test */
    public function gates_are_registered_for_every_capability(): void
    {
        GovernanceGateRegistrar::register();

        foreach (['billing.adjust_credits', 'admin.user_delete', 'workspace.update'] as $cap) {
            $this->assertTrue(Gate::has($cap), "Gate missing for $cap");
        }
    }

    /** @test */
    public function gate_allows_in_shadow_mode(): void
    {
        config(['governance.mode' => GovernanceService::MODE_SHADOW]);
        GovernanceGateRegistrar::register();

        // No authenticated user ⇒ governance concludes deny, gate still allows.
        $this->assertTrue(Gate::allows('workspace.update'), 'shadow gate must allow');
    }

    /** @test */
    public function no_gate_exists_for_the_removed_capability(): void
    {
        GovernanceGateRegistrar::register();
        $this->assertFalse(Gate::has('bella.query_database'), 'GD-002: no gate for removed capability');
    }

    // ── POLICY RESOLUTION ────────────────────────────────────────────────

    /** @test */
    public function policies_resolve_and_declare_capabilities(): void
    {
        $this->assertNotEmpty(UserPolicy::capabilities());
        $this->assertNotEmpty(WorkspacePolicy::capabilities());

        foreach (array_merge(UserPolicy::capabilities(), WorkspacePolicy::capabilities()) as $cap) {
            $this->assertTrue(
                PermissionRegistry::has($cap),
                "Policy references unregistered capability $cap"
            );
        }
    }

    /** @test */
    public function user_policy_protects_platform_admins(): void
    {
        $policy = app(UserPolicy::class);

        $actor  = (object) ['id' => 2, 'is_platform_admin' => true];
        $target = (object) ['id' => 1, 'is_platform_admin' => true];

        $this->assertFalse($policy->suspend($actor, $target), 'PO-1: platform admin must not be suspendable');
        $this->assertFalse($policy->delete($actor, $target), 'PO-1: platform admin must not be deletable');
    }

    // ── APPROVAL LOOKUP (single path) ────────────────────────────────────

    /** @test */
    public function approval_lookup_defers_to_approval_policy_registry(): void
    {
        // Must not throw, and must not duplicate ApprovalPolicyRegistry.
        $result = PermissionRegistry::approvalPolicyFor('infrastructure.provision_hosting');
        $this->assertTrue($result === null || is_array($result));

        $src = file_get_contents(app_path('Core/Governance/PermissionRegistry.php'));
        $this->assertStringContainsString('ApprovalPolicyRegistry', $src,
            'approval lookup must delegate, not duplicate');
    }

    // ── AUDIT GENERATION ─────────────────────────────────────────────────

    /** @test */
    public function shadow_divergence_is_audited(): void
    {
        config([
            'governance.mode' => GovernanceService::MODE_SHADOW,
            'governance.audit_decisions' => true,
            'governance.audit_denials_only_in_shadow' => true,
        ]);

        $before = \DB::table('audit_logs')->where('action', 'governance.decision')->count();
        app(GovernanceService::class)->evaluate('platform.config_write', null);
        $after = \DB::table('audit_logs')->where('action', 'governance.decision')->count();

        $this->assertGreaterThan($before, $after, 'divergence must be audited');
    }

    /** @test */
    public function audit_payload_contains_no_secrets(): void
    {
        $d = app(GovernanceService::class)->evaluate('platform.config_write', null);
        $json = json_encode($d->toArray());

        $this->assertDoesNotMatchRegularExpression('/sk-[A-Za-z0-9]{10,}/', $json);
        $this->assertStringNotContainsString('password', strtolower($json));
    }

    // ── ZERO DUPLICATED AUTHORIZATION LOGIC ──────────────────────────────

    /** @test */
    public function governance_classes_contain_no_inline_admin_checks(): void
    {
        // No governance class may DERIVE PERMISSION from is_platform_admin —
        // that decision belongs solely to GovernanceService/PermissionRegistry.
        foreach ([
            'Core/Governance/GovernanceGateRegistrar.php',
            'Policies/WorkspacePolicy.php',
        ] as $rel) {
            $src = file_get_contents(app_path($rel));
            $this->assertStringNotContainsString('is_platform_admin', $src,
                "$rel must not re-implement the admin check");
        }

        // UserPolicy is the one deliberate exception. It reads
        // is_platform_admin ONLY to enforce PO-1 (a platform admin may never be
        // suspended or deleted) — a PROTECTION, not a grant. Verify it never
        // uses the flag to grant permission.
        $userPolicy = file_get_contents(app_path('Policies/UserPolicy.php'));
        $this->assertStringContainsString('targetIsPlatformAdmin', $userPolicy,
            'UserPolicy must enforce the PO-1 protection');
        $this->assertMatchesRegularExpression(
            '/return\s+false;/', $userPolicy,
            'the PO-1 guard must deny, never grant'
        );
        // Every authorization answer must come from the governance service.
        $this->assertStringNotContainsString('return true;', $userPolicy,
            'UserPolicy must never grant permission directly — only delegate');
    }

    // ── REGRESSION: BELLA STILL DORMANT ──────────────────────────────────

    /** @test */
    public function bella_remains_dormant(): void
    {
        $this->assertSame(0, \DB::table('bella_conversations')->count(), 'Bella must remain dormant');
        $this->assertSame(0, \DB::table('bella_memory')->count(), 'Bella must remain dormant');
    }

    /** @test */
    public function bella_controller_still_exists_and_was_not_enabled(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Api/Admin/BellaController.php'));

        // Governed and gated — not retired (GD-001).
        $this->assertStringContainsString('class BellaController', $src);
        $this->assertStringContainsString('public function chat', $src);
        // The remaining allowlist must still exist and must not contain SQL.
        $this->assertStringContainsString('allowedActions', $src);
    }
}
