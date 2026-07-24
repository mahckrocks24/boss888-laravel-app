<?php
/**
 * INFRA888 Phase 2B-R3 — credential roles (WS2) + governance state machine (WS4).
 *
 * Real staging DB, rolled back. No provider contacted. Proves:
 *   - a diagnostics credential IS now storable (the R2 gap, closed)
 *   - a diagnostics credential NEVER appears as a resolution candidate
 *   - governance state machine: bootstrap -> activated -> degraded (fail-closed)
 *     -> recovery -> activated
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Connectors\Infrastructure\Testing\ControlledCredentialVerifier as CV;
use App\Core\Auth\AdminGovernanceService;
use App\Core\Auth\TotpMfaService;
use App\Engines\Infrastructure\Models\InfraGovernanceState;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Registry\CredentialRoleRegistry as Role;
use App\Engines\Infrastructure\Services\ProviderCredentialService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Engines\Infrastructure\Services\ProviderResolutionService;
use App\Engines\Infrastructure\States\CredentialState;
use App\Engines\Infrastructure\States\ProviderLifecycleState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] {$l}\n"; }
    else { $fail++; echo "  [FAIL] {$l}" . ($d ? " -> {$d}" : '') . "\n"; }
}

$registry = app(ProviderRegistryService::class);
$creds    = app(ProviderCredentialService::class);
$res      = app(ProviderResolutionService::class);
$gov      = app(AdminGovernanceService::class);
$mfa      = app(TotpMfaService::class);
$SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function totp(TotpMfaService $m, string $seed): string {
    $r = new ReflectionMethod(TotpMfaService::class, 'hotp'); $r->setAccessible(true);
    return $r->invoke($m, $seed, (int) floor(time() / 30));
}

DB::beginTransaction();
try {
    echo "\n=== WS2: CREDENTIAL ROLES ===\n";
    $p = $registry->register([
        'provider_key' => 'r3-harness', 'display_name' => 'R3', 'provider_type' => 'composite',
        'environments_json' => ['sandbox'], 'sandbox_ready' => true,
    ], 1);
    $registry->declareCapability($p, 'dns', 'sandbox', [], 1);
    $registry->transition($p->fresh(), ProviderLifecycleState::TESTING, 1);
    $registry->enableCapability($p->fresh(), 'dns', 'sandbox', 1);

    // 🔴 The R2 gap: a diagnostics credential IS now storable (empty scope OK).
    $diag = $creds->create($p->fresh(), 'diag', 'diag-secret-value', [
        'credential_role'       => Role::ROLE_DIAGNOSTICS,
        'capability_scope_json' => [],
        'environment'           => 'sandbox',
    ], 1);
    check('diagnostics credential is now STORABLE (empty scope)', $diag->exists);
    check('diagnostics credential role persisted', $diag->credential_role === Role::ROLE_DIAGNOSTICS);

    // A diagnostics credential with a scope is refused (read-only inspector).
    $scopedDiagRefused = false;
    try {
        $creds->create($p->fresh(), 'diag-bad', 'x-secret-value', [
            'credential_role' => Role::ROLE_DIAGNOSTICS, 'capability_scope_json' => ['dns'],
        ], 1);
    } catch (Throwable $e) { $scopedDiagRefused = str_contains($e->getMessage(), 'must NOT declare a capability scope'); }
    check('scoped diagnostics credential is refused', $scopedDiagRefused);

    // A provisioning credential still requires a scope.
    $unscopedProvRefused = false;
    try {
        $creds->create($p->fresh(), 'prov-bad', 'y-secret-value', [
            'credential_role' => Role::ROLE_PROVISIONING, 'capability_scope_json' => [],
        ], 1);
    } catch (Throwable $e) { $unscopedProvRefused = str_contains($e->getMessage(), 'must list at least one capability'); }
    check('unscoped provisioning credential still refused', $unscopedProvRefused);

    // Activate the diagnostics credential (verify + activate) so it is "usable"
    // and would appear in resolution IF roles were not enforced.
    $r = $creds->verify($diag->fresh(), new CV(CV::OUTCOME_VERIFIED, []), 1);
    // Diagnostics has empty scope; activation should succeed with no ungranted.
    $active = $creds->activate($diag->fresh(), $r, 990016);
    check('diagnostics credential activates', $active->state === CredentialState::ACTIVE);

    // A real provisioning credential, so the provider CAN resolve.
    $prov = $creds->create($p->fresh(), 'prov', 'prov-secret-value', [
        'capability_scope_json' => ['dns'], 'environment' => 'sandbox',
    ], 1);
    $pr = $creds->verify($prov, new CV(CV::OUTCOME_VERIFIED, ['dns']), 1);
    $creds->activate($prov->fresh(), $pr, 990016);
    // Health so the provider is selectable.
    app(\App\Engines\Infrastructure\Services\ProviderHealthService::class)
        ->recordSuccess($p->fresh(), 'dns', 'sandbox', 10);

    // 🔴 Resolution must pick the PROVISIONING credential, never the diagnostics one.
    $chosen = $res->usableCredential($p->fresh(), 'dns', 'sandbox');
    check('resolution selects a provisioning credential', $chosen && $chosen->credential_role === Role::ROLE_PROVISIONING);
    check('resolution NEVER selects the diagnostics credential', !$chosen || $chosen->id !== $active->id);

    echo "\n=== WS4: GOVERNANCE STATE MACHINE ===\n";
    // Fresh governance state within the transaction.
    $state = InfraGovernanceState::current();
    check('starts in bootstrap', $state->mode === InfraGovernanceState::MODE_BOOTSTRAP);
    check('bootstrap: step-up NOT enforced (self-disable valid here)', !$gov->stepUpEnforced());

    // Create two MFA admins to meet the bar.
    $mkAdmin = function (string $n) use ($mfa, $SEED) {
        $u = User::create(['name' => $n, 'email' => $n . '-' . Str::random(6) . '@t.local',
            'password' => Hash::make(Str::random(16)), 'is_admin' => 1, 'is_platform_admin' => 1,
            'account_classification' => 'standard']);
        $u->forceFill(['mfa_secret_encrypted' => $SEED, 'mfa_enabled' => false])->save();
        $mfa->confirm($u->fresh(), totp($mfa, $SEED));
        return $u->fresh();
    };
    $a = $mkAdmin('r3-admin-a');
    $b = $mkAdmin('r3-admin-b');
    check('bar met with two MFA admins', $gov->meetsProductionGovernanceBar());

    // Activate.
    check('governance activates', $gov->maybeActivateGovernance($a->id));
    check('activation is idempotent', !$gov->maybeActivateGovernance($a->id));
    check('step-up now ENFORCED', $gov->stepUpEnforced());
    check('mode is activated', $gov->currentMode() === InfraGovernanceState::MODE_ACTIVATED);

    // Offboard one admin -> degraded.
    $gov->offboard($b->fresh(), $a->fresh(), 'simulated departure');
    check('bar no longer met', !$gov->meetsProductionGovernanceBar());
    check('🔴 governance is DEGRADED (fail-closed), NOT reverted to bootstrap', $gov->isDegraded());
    check('step-up STILL enforced after dropping below bar', $gov->stepUpEnforced());
    check('mode did NOT silently revert to bootstrap', $gov->currentMode() !== InfraGovernanceState::MODE_BOOTSTRAP);

    // Recovery.
    $gov->enterRecoveryMode($a->id, 'restore second admin', 60);
    check('recovery mode entered', $gov->currentMode() === InfraGovernanceState::MODE_RECOVERY);
    check('recovery lifts the degraded block', !$gov->isDegraded());
    check('recovery is time-boxed', InfraGovernanceState::current()->recovery_expires_at !== null);

    // Restore second admin, exit recovery.
    $c = $mkAdmin('r3-admin-c');
    $gov->exitRecoveryMode($a->id);
    check('bar restored', $gov->meetsProductionGovernanceBar());
    check('back to activated', $gov->currentMode() === InfraGovernanceState::MODE_ACTIVATED);
    check('never regressed to bootstrap across the whole cycle', InfraGovernanceState::current()->wasEverActivated());

} catch (Throwable $e) {
    $fail++;
    echo "\n  [ERROR] " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n>>> ROLLED BACK. residual r3-harness: " . InfraProvider::where('provider_key', 'r3-harness')->count() . "\n";
}

echo "\n==================== RESULT: {$pass} passed, {$fail} failed ====================\n";
exit($fail > 0 ? 1 : 0);
