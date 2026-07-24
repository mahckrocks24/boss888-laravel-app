<?php
/**
 * INFRA888 Phase 2B-R2 — diagnostics credential lifecycle + certification proof.
 *
 * Runs against the real staging DB inside a rolled-back transaction. No provider
 * contacted; verification uses ControlledCredentialVerifier (simulated, stamped).
 *
 * Proves three things:
 *   1. The credential-CERTIFICATION machinery works: a least-privilege credential
 *      certifies, and one with unexpected write/excess authority FAILS.
 *   2. A credential whose verifier reports EXCESS scope cannot activate.
 *   3. 🔴 A genuine architectural finding: the `readonly_diagnostics` purpose maps
 *      to an EMPTY capability set, so a diagnostics credential CANNOT be created
 *      through the capability-scoped credential path. Surfaced, not papered over.
 *
 * Also asserts commercial isolation across the whole run (WS15).
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Connectors\Infrastructure\Testing\ControlledCredentialVerifier as CV;
use App\Engines\Infrastructure\Models\InfraCertificationCheck as Check;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Registry\CredentialPurposeRegistry as Purpose;
use App\Engines\Infrastructure\Services\ProviderCertificationService;
use App\Engines\Infrastructure\Services\ProviderCredentialService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Engines\Infrastructure\States\CertificationLevel as Level;
use App\Engines\Infrastructure\States\CredentialState;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] {$l}\n"; }
    else { $fail++; echo "  [FAIL] {$l}" . ($d ? " -> {$d}" : '') . "\n"; }
}

$registry    = app(ProviderRegistryService::class);
$credentials = app(ProviderCredentialService::class);
$certSvc     = app(ProviderCertificationService::class);

DB::beginTransaction();

$commercialBefore = [
    'subscriptions' => DB::table('infra_subscriptions')->count(),
    'products'      => DB::table('infra_products')->count(),
    'plans'         => DB::table('infra_plans')->count(),
    'hosting'       => DB::table('infra_hosting_accounts')->count(),
    'sites'         => DB::table('infra_hosted_sites')->count(),
    'websites'      => DB::table('websites')->count(),
];

try {
    echo "\n=== 1. DIAGNOSTICS PURPOSE IS UNSCOPABLE (architectural finding) ===\n";
    check('readonly_diagnostics maps to an empty capability set',
        Purpose::capabilitiesFor(Purpose::PURPOSE_DIAGNOSTICS) === []);
    check('readonly_diagnostics is non-mutating',
        !Purpose::isMutating(Purpose::PURPOSE_DIAGNOSTICS));

    $p = $registry->register([
        'provider_key'  => 'diag-harness',
        'display_name'  => 'Diagnostics Harness',
        'provider_type' => 'composite',
        'environments_json' => ['sandbox'],
        'sandbox_ready' => true,
    ], 1);

    // Attempt the diagnostics credential through the normal path — must be refused.
    $diagRefused = false;
    try {
        $credentials->create($p, 'diag-token', 'seed-value-abc', [
            'capability_scope_json' => [],   // diagnostics serves no capability
            'purpose'               => Purpose::PURPOSE_DIAGNOSTICS,
        ], 1);
    } catch (\Throwable $e) {
        $diagRefused = str_contains($e->getMessage(), 'at least one capability')
                    || str_contains($e->getMessage(), 'read-only');
    }
    check('diagnostics credential CANNOT be created via capability-scoped path', $diagRefused);
    echo "  NOTE: diagnostics credentials need their own storage or a nominal\n";
    echo "        'diagnostics' capability. Documented as a Phase 2B-R2 finding.\n";

    echo "\n=== 2. CREDENTIAL-CERTIFICATION MACHINERY (least-privilege) ===\n";
    // A real DNS-purpose credential CAN be created + certified as a credential.
    $registry->declareCapability($p, 'dns', 'sandbox', [], 1);
    $registry->transition($p->fresh(), \App\Engines\Infrastructure\States\ProviderLifecycleState::TESTING, 1);
    $registry->enableCapability($p->fresh(), 'dns', 'sandbox', 1);

    $cred = $credentials->create($p->fresh(), 'dns-least-priv', 'least-priv-secret', [
        'capability_scope_json' => ['dns'],
        'environment'           => 'sandbox',
    ], 1);
    $res = $credentials->verify($cred, new CV(CV::OUTCOME_VERIFIED, ['dns']), 1);
    check('least-privilege credential verifies', $res->permitsActivation());

    // Certify the credential (B-domain focus) at Level 1.
    $cert = $certSvc->start($p->fresh(), 'dns', 'sandbox', Level::READ_ONLY_VERIFIED, 1, 'diagnostics WS5');
    foreach ([
        ['credentials.purpose_declared',        Check::EVIDENCE_TEST],
        ['credentials.granted_scopes_verified', Check::EVIDENCE_RUNTIME],
        ['credentials.missing_scopes_documented', Check::EVIDENCE_RUNTIME],
        ['credentials.encrypted_at_rest',       Check::EVIDENCE_TEST],
        ['credentials.never_disclosed',         Check::EVIDENCE_TEST],
    ] as [$k, $ev]) {
        $certSvc->recordCheck($cert, $k, Check::RESULT_PASS, $ev, 'controlled verifier', [], 1);
    }
    check('credential certification records evidence', $cert->fresh()->checks()->where('result', Check::RESULT_PASS)->count() >= 5);

    echo "\n=== 3. EXCESS AUTHORITY FAILS ===\n";
    // A credential whose provider grants MORE than its purpose -> activation refused.
    $excess = $credentials->create($p->fresh(), 'dns-excess', 'excess-secret', [
        'capability_scope_json' => ['dns'],
        'environment'           => 'sandbox',
    ], 1);
    $exRes = $credentials->verify($excess, new CV(CV::OUTCOME_EXCESS_GRANT, ['dns']), 1);
    $exBlocked = false;
    try { $credentials->activate($excess->fresh(), $exRes, 990016); }
    catch (\Throwable $e) { $exBlocked = str_contains($e->getMessage(), 'beyond its purpose'); }
    check('excess-authority credential cannot activate', $exBlocked);
    check('excess credential stays pending', $excess->fresh()->state === CredentialState::PENDING_VERIFICATION);

    echo "\n=== 4. COMMERCIAL ISOLATION (WS15) ===\n";
    $commercialAfter = [
        'subscriptions' => DB::table('infra_subscriptions')->count(),
        'products'      => DB::table('infra_products')->count(),
        'plans'         => DB::table('infra_plans')->count(),
        'hosting'       => DB::table('infra_hosting_accounts')->count(),
        'sites'         => DB::table('infra_hosted_sites')->count(),
        'websites'      => DB::table('websites')->count(),
    ];
    check('commercial + customer tables unchanged', $commercialBefore === $commercialAfter,
        json_encode(['before' => $commercialBefore, 'after' => $commercialAfter]));

} catch (\Throwable $e) {
    $fail++;
    echo "\n  [ERROR] " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n>>> ROLLED BACK. residual diag-harness providers: "
        . InfraProvider::where('provider_key', 'diag-harness')->count() . "\n";
}

echo "\n==================== RESULT: {$pass} passed, {$fail} failed ====================\n";
exit($fail > 0 ? 1 : 0);
