<?php

namespace Tests\Feature\Infrastructure;

use App\Core\Auth\AdminGovernanceService;
use App\Core\Auth\TotpMfaService;
use App\Engines\Infrastructure\Models\InfraGovernanceState;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Registry\CredentialRoleRegistry as Role;
use App\Engines\Infrastructure\Services\ProviderCredentialService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2B-R3 — credential roles (WS2) + governance state machine (WS4).
 */
class CredentialRolesAndGovernanceModeTest extends TestCase
{
    use DatabaseTransactions;

    private const SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private ProviderCredentialService $creds;
    private ProviderRegistryService $registry;
    private AdminGovernanceService $gov;
    private TotpMfaService $mfa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creds    = app(ProviderCredentialService::class);
        $this->registry = app(ProviderRegistryService::class);
        $this->gov      = app(AdminGovernanceService::class);
        $this->mfa      = app(TotpMfaService::class);
    }

    private function provider(): InfraProvider
    {
        return $this->registry->register([
            'provider_key' => 'role-' . substr(md5(uniqid('', true)), 0, 8),
            'display_name' => 'Role Test', 'provider_type' => 'composite', 'sandbox_ready' => true,
        ], 1);
    }

    private function code(): string
    {
        $r = new \ReflectionMethod(TotpMfaService::class, 'hotp');
        $r->setAccessible(true);
        return $r->invoke($this->mfa, self::SEED, (int) floor(time() / 30));
    }

    private function mfaAdmin(string $n): User
    {
        $u = User::create(['name' => $n, 'email' => $n . '-' . Str::random(6) . '@t.local',
            'password' => Hash::make(Str::random(16)), 'is_admin' => 1, 'is_platform_admin' => 1,
            'account_classification' => 'standard']);
        $u->forceFill(['mfa_secret_encrypted' => self::SEED, 'mfa_enabled' => false])->save();
        $this->mfa->confirm($u->fresh(), $this->code());
        return $u->fresh();
    }

    // ── WS2: roles ────────────────────────────────────────────────────────

    public function test_role_registry_only_provisioning_resolves(): void
    {
        $this->assertTrue(Role::participatesInResolution(Role::ROLE_PROVISIONING));
        foreach ([Role::ROLE_DIAGNOSTICS, Role::ROLE_CERTIFICATION, Role::ROLE_BILLING,
                  Role::ROLE_MIGRATION, Role::ROLE_INCIDENT_RESPONSE] as $r) {
            $this->assertFalse(Role::participatesInResolution($r), "{$r} must not resolve");
        }
    }

    public function test_diagnostics_credential_is_storable_unscoped(): void
    {
        $p = $this->provider();
        $c = $this->creds->create($p, 'diag', 'diag-secret-value', [
            'credential_role' => Role::ROLE_DIAGNOSTICS, 'capability_scope_json' => [],
        ], 1);

        $this->assertTrue($c->exists);
        $this->assertSame(Role::ROLE_DIAGNOSTICS, $c->credential_role);
    }

    public function test_diagnostics_credential_may_not_have_scope(): void
    {
        $p = $this->provider();
        $this->expectExceptionMessageMatches('/must NOT declare a capability scope/i');
        $this->creds->create($p, 'diag-bad', 'x-secret-value', [
            'credential_role' => Role::ROLE_DIAGNOSTICS, 'capability_scope_json' => ['dns'],
        ], 1);
    }

    public function test_provisioning_credential_still_requires_scope(): void
    {
        $p = $this->provider();
        $this->expectExceptionMessageMatches('/must list at least one capability/i');
        $this->creds->create($p, 'prov-bad', 'y-secret-value', [
            'credential_role' => Role::ROLE_PROVISIONING, 'capability_scope_json' => [],
        ], 1);
    }

    public function test_unknown_role_is_refused(): void
    {
        $p = $this->provider();
        $this->expectExceptionMessageMatches('/Unknown credential role/i');
        $this->creds->create($p, 'weird', 'z-secret-value', [
            'credential_role' => 'sudo', 'capability_scope_json' => [],
        ], 1);
    }

    public function test_default_role_is_provisioning(): void
    {
        $p = $this->provider();
        $c = $this->creds->create($p, 'default', 'd-secret-value', [
            'capability_scope_json' => ['dns'],
        ], 1);
        $this->assertSame(Role::ROLE_PROVISIONING, $c->credential_role);
    }

    // ── WS4: governance state machine ─────────────────────────────────────

    public function test_starts_in_bootstrap_with_enforcement_off(): void
    {
        // Reset within the transaction to a clean bootstrap.
        InfraGovernanceState::current()->update([
            'mode' => InfraGovernanceState::MODE_BOOTSTRAP,
            'activated_at' => null, 'activated_by_user_id' => null,
        ]);

        $this->assertSame(InfraGovernanceState::MODE_BOOTSTRAP, $this->gov->currentMode());
        $this->assertFalse($this->gov->stepUpEnforced());
    }

    public function test_activation_is_one_way_and_idempotent(): void
    {
        InfraGovernanceState::current()->update(['mode' => 'bootstrap', 'activated_at' => null]);
        $a = $this->mfaAdmin('gov-a');
        $this->mfaAdmin('gov-b');

        $this->assertTrue($this->gov->maybeActivateGovernance($a->id));
        $this->assertFalse($this->gov->maybeActivateGovernance($a->id), 'second call is a no-op');
        $this->assertTrue($this->gov->stepUpEnforced());
    }

    /** 🔴 The core WS4 property: dropping below the bar fails closed, never reverts. */
    public function test_dropping_below_bar_is_degraded_not_bootstrap(): void
    {
        InfraGovernanceState::current()->update(['mode' => 'bootstrap', 'activated_at' => null]);
        $a = $this->mfaAdmin('deg-a');
        $b = $this->mfaAdmin('deg-b');
        $this->gov->maybeActivateGovernance($a->id);

        $this->gov->offboard($b->fresh(), $a->fresh(), 'left');

        $this->assertFalse($this->gov->meetsProductionGovernanceBar());
        $this->assertTrue($this->gov->isDegraded(), 'must be degraded');
        $this->assertTrue($this->gov->stepUpEnforced(), 'enforcement must persist');
        $this->assertNotSame(InfraGovernanceState::MODE_BOOTSTRAP, $this->gov->currentMode());
    }

    public function test_recovery_is_explicit_time_boxed_and_lifts_block(): void
    {
        InfraGovernanceState::current()->update(['mode' => 'bootstrap', 'activated_at' => null]);
        $a = $this->mfaAdmin('rec-a');
        $b = $this->mfaAdmin('rec-b');
        $this->gov->maybeActivateGovernance($a->id);
        $this->gov->offboard($b->fresh(), $a->fresh(), 'left');
        $this->assertTrue($this->gov->isDegraded());

        $state = $this->gov->enterRecoveryMode($a->id, 'restore admin', 60);

        $this->assertSame(InfraGovernanceState::MODE_RECOVERY, $this->gov->currentMode());
        $this->assertFalse($this->gov->isDegraded(), 'recovery lifts the degraded block');
        $this->assertNotNull($state->recovery_expires_at);
        $this->assertSame($a->id, $state->recovery_by_user_id);
    }

    public function test_exit_recovery_returns_to_activated_never_bootstrap(): void
    {
        InfraGovernanceState::current()->update(['mode' => 'bootstrap', 'activated_at' => null]);
        $a = $this->mfaAdmin('exit-a');
        $this->mfaAdmin('exit-b');
        $this->gov->maybeActivateGovernance($a->id);
        $this->gov->enterRecoveryMode($a->id, 'x', 60);

        $this->gov->exitRecoveryMode($a->id);

        $this->assertSame(InfraGovernanceState::MODE_ACTIVATED, $this->gov->currentMode());
        $this->assertTrue(InfraGovernanceState::current()->wasEverActivated());
    }
}
