<?php
/**
 * INFRA888 Phase 2B runtime validation — provider control plane.
 *
 * Exercises the REAL services against the REAL staging database, then rolls the
 * whole thing back. Nothing is left behind: the directive forbids seeding fake
 * providers as active, and a rolled-back transaction satisfies that absolutely
 * while still proving the code path executes against real MySQL.
 *
 * NO PROVIDER IS CONTACTED. No network call is made.
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Connectors\Infrastructure\Contracts\CredentialVerifier;
use App\Connectors\Infrastructure\CredentialVerificationResult;
use App\Connectors\Infrastructure\Null\NullCredentialVerifier;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use App\Engines\Infrastructure\Services\ProviderCredentialService;
use App\Engines\Infrastructure\Services\ProviderHealthService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Engines\Infrastructure\Services\ProviderResolutionService;
use App\Engines\Infrastructure\States\CredentialState;
use App\Engines\Infrastructure\States\ProviderHealthState;
use App\Engines\Infrastructure\States\ProviderLifecycleState;
use Illuminate\Support\Facades\DB;

$SENTINEL = 'RUNTIME-SENTINEL-4c9e17a3f5b8-DO-NOT-LEAK';
$pass = 0; $fail = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail ? " -> {$detail}" : '') . "\n"; }
}

/** Local verifier granting only DNS — mirrors the measured real-world scope gap. */
$dnsOnlyVerifier = new class implements CredentialVerifier {
    public function providerKey(): string { return 'verify-harness'; }
    public function verify(string $secret, string $environment, array $expectedCapabilities = []): CredentialVerificationResult {
        return CredentialVerificationResult::success(
            granted: ['dns'],
            missing: array_values(array_diff($expectedCapabilities, ['dns'])),
            accountIdentifier: 'harness-account-1',
            accountLabel: 'Verification Harness'
        );
    }
    public function verifiableCapabilities(): array { return ['dns']; }
};

/** Grants only `certificate`, leaving custom_hostname ungranted. */
$certOnlyVerifier = new class implements CredentialVerifier {
    public function providerKey(): string { return 'verify-harness'; }
    public function verify(string $secret, string $environment, array $expectedCapabilities = []): CredentialVerificationResult {
        return CredentialVerificationResult::success(
            granted: ['certificate'],
            missing: array_values(array_diff($expectedCapabilities, ['certificate'])),
            accountIdentifier: 'harness-account-1'
        );
    }
    public function verifiableCapabilities(): array { return ['certificate']; }
};

$registry    = app(ProviderRegistryService::class);
$credentials = app(ProviderCredentialService::class);
$health      = app(ProviderHealthService::class);
$resolution  = app(ProviderResolutionService::class);

DB::beginTransaction();

try {
    echo "\n=== 1. REGISTRY ===\n";
    $p = $registry->register([
        'provider_key'      => 'verify-harness',
        'display_name'      => 'Verification Harness',
        'provider_type'     => 'composite',
        'environments_json' => [InfraProvider::ENV_SANDBOX, InfraProvider::ENV_PRODUCTION],
        'sandbox_ready'     => true,
    ], 1);
    check('registers in draft, disabled', $p->lifecycle_state === ProviderLifecycleState::DRAFT && !$p->enabled);

    $registry->declareCapability($p, 'dns', InfraProvider::ENV_SANDBOX, [], 1);
    $registry->declareCapability($p, 'certificate', InfraProvider::ENV_SANDBOX, [], 1);
    $capDns = $registry->capabilityRow($p, 'dns', InfraProvider::ENV_SANDBOX);
    check('declared capability is NOT auto-enabled', $capDns->supported && !$capDns->enabled);

    $registry->transition($p, ProviderLifecycleState::TESTING, 1, 'runtime validation');
    $registry->setEnabled($p, true, 1, 'runtime validation');
    $registry->enableCapability($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX, 1);
    // `certificate` is enabled too, so the rollup below genuinely exercises the
    // pessimistic path. The rollup counts ONLY enabled capabilities by design —
    // a declared-but-disabled capability routes nothing, and counting it would
    // hold every provider permanently red throughout onboarding.
    $registry->enableCapability($p->fresh(), 'certificate', InfraProvider::ENV_SANDBOX, 1);
    check('dns capability enabled', $registry->capabilityRow($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX)->enabled);

    echo "\n=== 2. CREDENTIALS ===\n";
    $cred = $credentials->create($p->fresh(), 'harness-dns', $GLOBALS['SENTINEL'], [
        'capability_scope_json' => ['dns'],
        'environment'           => InfraProvider::ENV_SANDBOX,
        'purpose'               => 'runtime validation',
    ], actorUserId: 1);
    check('credential lands pending_verification (inert)', $cred->state === CredentialState::PENDING_VERIFICATION);

    $raw = DB::table('infra_provider_credentials')->where('id', $cred->id)->value('secret_encrypted');
    check('secret encrypted at rest', !str_contains((string) $raw, $GLOBALS['SENTINEL']));
    check('secret round-trips correctly', $cred->fresh()->secretForVerification() === $GLOBALS['SENTINEL']);
    check('secret absent from JSON', !str_contains(json_encode($cred->toArray()), $GLOBALS['SENTINEL']));

    // Null verifier must be unable to certify anything.
    $nullResult = $credentials->verify($cred->fresh(), new NullCredentialVerifier(), 1);
    check('null verifier returns not-attempted', !$nullResult->attempted && !$nullResult->permitsActivation());
    check('not-attempted does not mark invalid', $cred->fresh()->state === CredentialState::PENDING_VERIFICATION);

    $blocked = false;
    try { $credentials->activate($cred->fresh(), $nullResult, 1); }
    catch (\Throwable $e) { $blocked = str_contains($e->getMessage(), 'never attempted'); }
    check('activation refused without evidence', $blocked);

    // Machine identity refused.
    $machineBlocked = false;
    try { $credentials->create($p->fresh(), 'machine-cred', 'x-secret-value', ['capability_scope_json' => ['dns']], actorUserId: null); }
    catch (\Throwable $e) { $machineBlocked = str_contains($e->getMessage(), 'individual authenticated user'); }
    check('machine identity refused', $machineBlocked);

    // Phase 2B-G: cross-purpose scope is refused at CREATION - strictly stronger
    // than the activation-time refusal this originally asserted.
    $crossBlocked = false;
    try {
        $credentials->create($p->fresh(), 'harness-cross', $GLOBALS['SENTINEL'] . '-cross', [
            'capability_scope_json' => ['dns', 'certificate'],
            'environment'           => InfraProvider::ENV_SANDBOX,
        ], actorUserId: 1);
    } catch (\Throwable $e) {
        $crossBlocked = str_contains($e->getMessage(), 'spans multiple credential purposes');
    }
    check('cross-purpose scope refused at CREATION', $crossBlocked);

    // Under-granted scope INSIDE one purpose still reaches, and fails, activation.
    $over = $credentials->create($p->fresh(), 'harness-over', $GLOBALS['SENTINEL'] . '-over', [
        'capability_scope_json' => ['certificate', 'custom_hostname'],
        'environment'           => InfraProvider::ENV_SANDBOX,
    ], actorUserId: 1);
    $overResult = $credentials->verify($over, $certOnlyVerifier, 1);
    $overBlocked = false;
    try { $credentials->activate($over->fresh(), $overResult, 1); }
    catch (\Throwable $e) { $overBlocked = str_contains($e->getMessage(), 'did not grant'); }
    check('under-granted credential refused activation', $overBlocked);

    // Correct scope activates.
    $result = $credentials->verify($cred->fresh(), $dnsOnlyVerifier, 1);
    $active = $credentials->activate($cred->fresh(), $result, 1);
    check('correctly-scoped credential activates', $active->state === CredentialState::ACTIVE);
    check('provider account identity captured', $active->account_identifier === 'harness-account-1');

    echo "\n=== 3. HEALTH ===\n";
    $health->recordSuccess($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX, 37);
    $health->recordFailure($p->fresh(), 'certificate', InfraProvider::ENV_SANDBOX, 'unauthorized', 'Authentication error.');

    $hDns  = $health->current($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX);
    $hCert = $health->current($p->fresh(), 'certificate', InfraProvider::ENV_SANDBOX);
    check('dns healthy / certificate unauthorized simultaneously',
        $hDns->health_state === ProviderHealthState::HEALTHY
        && $hCert->health_state === ProviderHealthState::UNAUTHORIZED);
    check('unauthorized is NOT retryable', !$hCert->isRetryable());

    $rollup = $health->recomputeProviderRollup($p->fresh(), InfraProvider::ENV_SANDBOX);
    check('rollup is pessimistic', $rollup->health_state === ProviderHealthState::UNAUTHORIZED);

    echo "\n=== 4. RESOLUTION ===\n";
    $r = $resolution->resolve('dns', InfraProvider::ENV_SANDBOX);
    check('dns resolves to the harness provider', $r['provider']->provider_key === 'verify-harness');
    check('resolution returns the active credential', $r['credential']->id === $active->id);

    $certCandidates = $resolution->candidates('certificate', InfraProvider::ENV_SANDBOX);
    check('certificate does NOT resolve (fails closed)', $certCandidates === []);

    $prodCandidates = $resolution->candidates('dns', InfraProvider::ENV_PRODUCTION);
    check('testing provider not selectable for production', $prodCandidates === []);

    echo "\n=== 5. ROTATION ===\n";
    $new = $credentials->beginRotation($active->fresh(), $GLOBALS['SENTINEL'] . '-ROTATED', [], 1);
    check('rotation creates a NEW row', $new->id !== $active->id && $new->supersedes_id === $active->id);
    check('old credential still ACTIVE during rotation', $active->fresh()->state === CredentialState::ACTIVE);

    $sameBlocked = false;
    try { $credentials->beginRotation($active->fresh(), $GLOBALS['SENTINEL'], [], 1); }
    catch (\Throwable $e) { $sameBlocked = str_contains($e->getMessage(), 'identical'); }
    check('identical-secret rotation refused', $sameBlocked);

    $newResult = $credentials->verify($new, $dnsOnlyVerifier, 1);
    $credentials->activate($new->fresh(), $newResult, 1);
    $credentials->completeRotation($new->fresh(), 1);
    check('old credential superseded, not deleted',
        $active->fresh()->state === CredentialState::SUPERSEDED
        && \App\Engines\Infrastructure\Models\InfraProviderCredential::find($active->id) !== null);

    echo "\n=== 6. REVOCATION ===\n";
    $revoked = $credentials->revoke($new->fresh(), 'runtime validation sweep', 1);
    check('revocation immediate and attributed',
        $revoked->state === CredentialState::REVOKED && $revoked->revoked_by_user_id === 1);

    echo "\n=== 7. EVENTS ===\n";
    $events = InfraProviderEvent::where('provider_id', $p->id)->get();
    check('events recorded', $events->count() >= 15, "count={$events->count()}");

    $leaked = $events->filter(fn ($e) => str_contains(json_encode($e->toArray()), $GLOBALS['SENTINEL']));
    check('NO event leaked the secret', $leaked->isEmpty(), "leaks={$leaked->count()}");

    $attributed = $events->filter(fn ($e) => $e->actor_type === InfraProviderEvent::ACTOR_USER && $e->actor_user_id === 1);
    check('events carry individual actor attribution', $attributed->count() >= 8);

    $immutable = false;
    try { $events->first()->update(['summary' => 'tampered']); }
    catch (\Throwable $e) { $immutable = str_contains($e->getMessage(), 'append-only'); }
    check('events are append-only', $immutable);

    echo "\n=== 8. COMMERCIAL ISOLATION ===\n";
    $subsBefore = DB::table('infra_subscriptions')->orderBy('id')->pluck('state', 'id')->toArray();
    for ($i = 0; $i < 8; $i++) {
        $health->recordFailure($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX, 'unauthorized', 'total failure');
    }
    $subsAfter = DB::table('infra_subscriptions')->orderBy('id')->pluck('state', 'id')->toArray();
    check('provider failure did NOT touch subscriptions', $subsBefore === $subsAfter);
    check('health did NOT change provider lifecycle',
        $p->fresh()->lifecycle_state === ProviderLifecycleState::TESTING);

    echo "\n=== EVENT TIMELINE (sanitized, as stored) ===\n";
    foreach (InfraProviderEvent::where('provider_id', $p->id)->orderBy('id')->get() as $e) {
        printf("  %-34s %-9s %s\n", $e->event_type, $e->severity,
            substr((string) $e->summary, 0, 76));
    }

} catch (\Throwable $e) {
    $fail++;
    echo "\n  [ERROR] " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo "    at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n>>> TRANSACTION ROLLED BACK - no rows persisted.\n";
    echo ">>> residual providers named 'verify-harness': "
        . InfraProvider::where('provider_key', 'verify-harness')->count() . "\n";
}

echo "\n==================== RESULT: {$pass} passed, {$fail} failed ====================\n";
exit($fail > 0 ? 1 : 0);
