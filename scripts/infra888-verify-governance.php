<?php
/**
 * INFRA888 Phase 2B-G — governance activation proof.
 *
 * Exercises separation of duties, the full credential lifecycle, provider
 * activation and commercial isolation against the REAL staging database using
 * the REAL services and the REAL ApprovalAuthorizationService.
 *
 * Everything runs inside a transaction that is rolled back, so no test provider
 * is left behind and nothing is seeded as production data.
 *
 * NO PROVIDER IS CONTACTED. Verification uses ControlledCredentialVerifier,
 * which simulates outcomes and stamps `simulated: true` into every result.
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Connectors\Infrastructure\Null\NullCredentialVerifier;
use App\Connectors\Infrastructure\Testing\ControlledCredentialVerifier as CV;
use App\Core\Governance\ApprovalAuthorizationService;
use App\Core\Governance\ApprovalPolicyRegistry;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use App\Engines\Infrastructure\Registry\CredentialPurposeRegistry;
use App\Engines\Infrastructure\Registry\InfrastructureCapabilityRegistry as Reg;
use App\Engines\Infrastructure\Services\ProviderCredentialService;
use App\Engines\Infrastructure\Services\ProviderHealthService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Engines\Infrastructure\Services\ProviderResolutionService;
use App\Engines\Infrastructure\States\CredentialState;
use App\Engines\Infrastructure\States\ProviderLifecycleState;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

const SENTINEL = 'GOVSENTINEL-8d2f61b7ae94-DO-NOT-LEAK';

$pass = 0; $fail = 0; $notes = [];

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] {$label}\n"; }
    else     { $fail++; echo "  [FAIL] {$label}" . ($detail ? " -> {$detail}" : '') . "\n"; }
}

/** Build a Request authenticated as a specific user, as the middleware would. */
function actingAs(User $user): Request {
    $r = Request::create('/api/admin/infrastructure/providers', 'POST');
    $r->setUserResolver(fn () => $user);
    $r->attributes->set('auth_via', 'jwt');
    return $r;
}

/** Build a Request authenticated by a MACHINE credential. */
function actingAsMachine(?User $user = null): Request {
    $r = Request::create('/api/admin/infrastructure/providers', 'POST');
    $r->setUserResolver(fn () => $user);
    $r->attributes->set('auth_via', 'api_key');
    return $r;
}

$registry    = app(ProviderRegistryService::class);
$credentials = app(ProviderCredentialService::class);
$health      = app(ProviderHealthService::class);
$resolution  = app(ProviderResolutionService::class);
$authz       = app(ApprovalAuthorizationService::class);

$adminA = User::find(1);
$adminB = User::where('email', 'infra-approver-staging@levelupgrowth.io')->first();

if (!$adminA || !$adminB) {
    fwrite(STDERR, "ABORT: need two platform admins (found A=" . ($adminA?->id ?? 'null')
        . " B=" . ($adminB?->id ?? 'null') . ")\n");
    exit(1);
}

echo "\nAdmin A: #{$adminA->id} {$adminA->email}\n";
echo "Admin B: #{$adminB->id} {$adminB->email}\n";

/** Create a pending protected approval requested by $requester. */
function makeApproval(string $operation, array $payload, User $requester): Approval {
    $meta   = Reg::get($operation);
    $policy = ApprovalPolicyRegistry::forCapability(Reg::ENGINE, $operation, 'protected');

    return Approval::create([
        'workspace_id'         => 1,
        'requested_by'         => $requester->id,
        'requester_actor_type' => 'user',
        'engine'               => Reg::ENGINE,
        'action'               => $operation,
        'capability_key'       => $meta['capability_key'],
        'approval_policy_json' => $policy,
        'data_json'            => $payload,
        'status'               => 'pending',
    ]);
}

DB::beginTransaction();

try {
    // ══════════════════════════════════════════════════════════════════════
    echo "\n=== 1. SEPARATION OF DUTIES — BOTH DIRECTIONS ===\n";
    // ══════════════════════════════════════════════════════════════════════

    // Direction 1: A requests, A cannot approve, B can.
    $ap1 = makeApproval(Reg::OP_ACTIVATE_CREDENTIAL, ['credential_id' => 0], $adminA);

    $selfA = $authz->authorize($ap1, actingAs($adminA));
    check('A requests -> A CANNOT self-approve', !$selfA['allowed'],
        'code=' . ($selfA['code'] ?? '?'));
    check('  denial reason is self-approval', ($selfA['code'] ?? '') === ApprovalAuthorizationService::DENY_SELF_APPROVAL,
        (string) ($selfA['code'] ?? ''));

    $byB = $authz->authorize($ap1, actingAs($adminB));
    check('A requests -> B CAN approve', $byB['allowed'], (string) ($byB['code'] ?? ''));

    // Direction 2: B requests, B cannot approve, A can.
    $ap2 = makeApproval(Reg::OP_ACTIVATE_CREDENTIAL, ['credential_id' => 0], $adminB);

    $selfB = $authz->authorize($ap2, actingAs($adminB));
    check('B requests -> B CANNOT self-approve', !$selfB['allowed'],
        'code=' . ($selfB['code'] ?? '?'));

    $byA = $authz->authorize($ap2, actingAs($adminA));
    check('B requests -> A CAN approve', $byA['allowed'], (string) ($byA['code'] ?? ''));

    // Machine identities.
    $machine = $authz->authorize($ap1, actingAsMachine($adminB));
    check('machine credential CANNOT approve', !$machine['allowed'],
        'code=' . ($machine['code'] ?? '?'));

    $anon = $authz->authorize($ap1, actingAsMachine(null));
    check('unauthenticated CANNOT approve', !$anon['allowed']);

    // A non-admin user must not approve a platform_admin capability.
    $member = User::where('is_platform_admin', 0)->where('is_admin', 0)->first();
    if ($member) {
        $byMember = $authz->authorize($ap1, actingAs($member));
        check('non-admin CANNOT approve platform capability', !$byMember['allowed'],
            'code=' . ($byMember['code'] ?? '?'));
    } else {
        $notes[] = 'No non-admin user available to test role denial.';
    }

    // All four protected ops must behave identically.
    foreach ([Reg::OP_ACTIVATE_PROVIDER, Reg::OP_ENABLE_PROVIDER_CAPABILITY,
              Reg::OP_ACTIVATE_CREDENTIAL, Reg::OP_ROTATE_CREDENTIAL] as $op) {
        $a = makeApproval($op, [], $adminA);
        $s = $authz->authorize($a, actingAs($adminA));
        $o = $authz->authorize($a, actingAs($adminB));
        check("  {$op}: self denied + other allowed", !$s['allowed'] && $o['allowed']);
    }

    // ══════════════════════════════════════════════════════════════════════
    echo "\n=== 2. PROVIDER REGISTRATION -> ACTIVATION (GOVERNED PATH) ===\n";
    // ══════════════════════════════════════════════════════════════════════

    $p = $registry->register([
        'provider_key'      => 'gov-harness',
        'display_name'      => 'Governance Harness (staging validation only)',
        'provider_type'     => 'composite',
        'environments_json' => [InfraProvider::ENV_SANDBOX],
        'sandbox_ready'     => true,
    ], $adminA->id);
    check('draft created, not enabled', $p->lifecycle_state === ProviderLifecycleState::DRAFT && !$p->enabled);

    $registry->declareCapability($p, 'dns', InfraProvider::ENV_SANDBOX, [], $adminA->id);
    $registry->declareCapability($p, 'certificate', InfraProvider::ENV_SANDBOX, [], $adminA->id);
    $registry->transition($p, ProviderLifecycleState::TESTING, $adminA->id, 'validation');

    // Activation is protected: A requests, B approves.
    $apProv = makeApproval(Reg::OP_ACTIVATE_PROVIDER, ['provider_id' => $p->id], $adminA);
    check('provider activation self-approval denied', !$authz->authorize($apProv, actingAs($adminA))['allowed']);
    check('provider activation approvable by B', $authz->authorize($apProv, actingAs($adminB))['allowed']);

    $registry->transition($p->fresh(), ProviderLifecycleState::ACTIVE, $adminB->id, 'approved by B');
    $registry->setEnabled($p->fresh(), true, $adminB->id, 'approved by B');
    check('provider now ACTIVE + enabled', $p->fresh()->lifecycle_state === ProviderLifecycleState::ACTIVE
        && $p->fresh()->enabled);

    // Capability enablement, also protected.
    $apCap = makeApproval(Reg::OP_ENABLE_PROVIDER_CAPABILITY,
        ['provider_id' => $p->id, 'capability' => 'dns', 'environment' => InfraProvider::ENV_SANDBOX], $adminA);
    check('capability enablement self-approval denied', !$authz->authorize($apCap, actingAs($adminA))['allowed']);
    $registry->enableCapability($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX, $adminB->id);
    check('dns capability enabled by B', $registry->capabilityRow($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX)->enabled);

    // Still must not resolve — no credential yet. Each exclusion proven separately.
    check('EXCLUSION: no credential -> does not resolve',
        $resolution->candidates('dns', InfraProvider::ENV_SANDBOX) === []);

    // ══════════════════════════════════════════════════════════════════════
    echo "\n=== 3. CREDENTIAL PURPOSE BINDING ===\n";
    // ══════════════════════════════════════════════════════════════════════

    $crossPurpose = false;
    try {
        $credentials->create($p->fresh(), 'cross', SENTINEL . '-x',
            ['capability_scope_json' => ['dns', 'certificate']], $adminA->id);
    } catch (Throwable $e) {
        $crossPurpose = str_contains($e->getMessage(), 'spans multiple credential purposes');
    }
    check('cross-purpose scope refused at CREATION', $crossPurpose);

    $cred = $credentials->create($p->fresh(), 'gov-dns', SENTINEL,
        ['capability_scope_json' => ['dns'], 'environment' => InfraProvider::ENV_SANDBOX], $adminA->id);
    check('purpose derived from scope', $cred->purpose === CredentialPurposeRegistry::PURPOSE_DNS,
        (string) $cred->purpose);
    check('credential inert on creation', $cred->state === CredentialState::PENDING_VERIFICATION);

    // ══════════════════════════════════════════════════════════════════════
    echo "\n=== 4. CONTROLLED VERIFIER SCENARIOS ===\n";
    // ══════════════════════════════════════════════════════════════════════

    $scenarios = [
        CV::OUTCOME_UNAUTHORIZED  => 'unauthorized',
        CV::OUTCOME_EXPIRED       => 'expired',
        CV::OUTCOME_REVOKED       => 'revoked',
        CV::OUTCOME_RATE_LIMITED  => 'rate_limited',
        CV::OUTCOME_UNAVAILABLE   => 'provider_unavailable',
    ];

    foreach ($scenarios as $outcome => $expectedCode) {
        $tmp = $credentials->create($p->fresh(), "sc-{$outcome}", SENTINEL . "-{$outcome}",
            ['capability_scope_json' => ['dns']], $adminA->id);
        $res = $credentials->verify($tmp, new CV($outcome), $adminA->id);
        $blocked = false;
        try { $credentials->activate($tmp->fresh(), $res, $adminB->id); }
        catch (Throwable $e) { $blocked = true; }
        check("scenario '{$outcome}' cannot activate", $blocked && $res->code === $expectedCode,
            'code=' . (string) $res->code);
    }

    // not_attempted is neither success nor failure
    $na = $credentials->create($p->fresh(), 'sc-na', SENTINEL . '-na',
        ['capability_scope_json' => ['dns']], $adminA->id);
    $naRes = $credentials->verify($na, new CV(CV::OUTCOME_NOT_ATTEMPTED), $adminA->id);
    check('not_attempted leaves credential pending (not invalid)',
        !$naRes->attempted && $na->fresh()->state === CredentialState::PENDING_VERIFICATION);

    // excess grant refused
    $ex = $credentials->create($p->fresh(), 'sc-excess', SENTINEL . '-ex',
        ['capability_scope_json' => ['dns']], $adminA->id);
    $exRes = $credentials->verify($ex, new CV(CV::OUTCOME_EXCESS_GRANT, ['dns']), $adminA->id);
    $exBlocked = false;
    try { $credentials->activate($ex->fresh(), $exRes, $adminB->id); }
    catch (Throwable $e) { $exBlocked = str_contains($e->getMessage(), 'beyond its purpose'); }
    check('EXCESS privilege refused at activation', $exBlocked);

    // simulated marker persists into the record
    check('simulated marker persisted into verification record',
        str_contains((string) $ex->fresh()->last_verification_summary, 'SIMULATED'));

    // Null verifier can never certify
    $nullRes = $credentials->verify($cred->fresh(), new NullCredentialVerifier(), $adminA->id);
    check('NullCredentialVerifier cannot certify', !$nullRes->attempted && !$nullRes->permitsActivation());

    // ══════════════════════════════════════════════════════════════════════
    echo "\n=== 5. CREDENTIAL ACTIVATION (GOVERNED) ===\n";
    // ══════════════════════════════════════════════════════════════════════

    $goodRes = $credentials->verify($cred->fresh(), new CV(CV::OUTCOME_VERIFIED, ['dns']), $adminA->id);
    check('verification succeeded', $goodRes->permitsActivation());

    $apCred = makeApproval(Reg::OP_ACTIVATE_CREDENTIAL, ['credential_id' => $cred->id], $adminA);
    check('credential activation self-approval denied', !$authz->authorize($apCred, actingAs($adminA))['allowed']);
    check('credential activation approvable by B', $authz->authorize($apCred, actingAs($adminB))['allowed']);

    $active = $credentials->activate($cred->fresh(), $goodRes, $adminB->id);
    check('credential ACTIVE, activated by B', $active->state === CredentialState::ACTIVE
        && $active->activated_by_user_id === $adminB->id);

    $health->recordSuccess($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX, 20);
    $r = $resolution->resolve('dns', InfraProvider::ENV_SANDBOX);
    check('provider now resolves for dns', $r['provider']->provider_key === 'gov-harness');
    check('certificate still fails closed (capability not enabled)',
        $resolution->candidates('certificate', InfraProvider::ENV_SANDBOX) === []);

    // ══════════════════════════════════════════════════════════════════════
    echo "\n=== 6. ROTATION (GOVERNED) ===\n";
    // ══════════════════════════════════════════════════════════════════════

    $repl = $credentials->beginRotation($active->fresh(), SENTINEL . '-ROT', [], $adminA->id);
    check('replacement created, old still ACTIVE',
        $repl->id !== $active->id && $active->fresh()->state === CredentialState::ACTIVE);

    $apRot = makeApproval(Reg::OP_ROTATE_CREDENTIAL,
        ['credential_id' => $active->id, 'replacement_credential_id' => $repl->id], $adminA);
    check('rotation self-approval denied', !$authz->authorize($apRot, actingAs($adminA))['allowed']);

    $rotRes = $credentials->verify($repl, new CV(CV::OUTCOME_VERIFIED, ['dns']), $adminB->id);
    $credentials->activate($repl->fresh(), $rotRes, $adminB->id);
    $old = $credentials->completeRotation($repl->fresh(), $adminB->id);
    check('old superseded, still present', $old->state === CredentialState::SUPERSEDED
        && InfraProviderCredential::find($active->id) !== null);

    // ══════════════════════════════════════════════════════════════════════
    echo "\n=== 7. REVOCATION (UNILATERAL, NO APPROVAL) ===\n";
    // ══════════════════════════════════════════════════════════════════════

    $revoked = $credentials->revoke($repl->fresh(), 'suspected leak - validation', $adminA->id);
    check('revocation immediate, single actor', $revoked->state === CredentialState::REVOKED
        && $revoked->revoked_by_user_id === $adminA->id);
    check('revoked credential excluded from resolution',
        $resolution->candidates('dns', InfraProvider::ENV_SANDBOX) === []);

    $revEvent = InfraProviderEvent::where('credential_id', $repl->id)
        ->where('event_type', InfraProviderEvent::CREDENTIAL_REVOKED)->first();
    check('revocation emitted CRITICAL event with reason',
        $revEvent && $revEvent->severity === InfraProviderEvent::SEVERITY_CRITICAL
        && str_contains((string) $revEvent->summary, 'suspected leak'));

    // ══════════════════════════════════════════════════════════════════════
    echo "\n=== 8. SECRET-LEAK SENTINEL SWEEP ===\n";
    // ══════════════════════════════════════════════════════════════════════

    $allCreds = InfraProviderCredential::where('provider_id', $p->id)->get();

    $rawLeak = DB::table('infra_provider_credentials')->where('provider_id', $p->id)
        ->get()->filter(fn ($r) => str_contains((string) $r->secret_encrypted, SENTINEL));
    check('DB columns: no plaintext', $rawLeak->isEmpty());

    $serLeak = $allCreds->filter(fn ($c) => str_contains(json_encode($c->toArray()), SENTINEL)
        || str_contains(json_encode($c->attributesToArray()), SENTINEL)
        || str_contains(json_encode($c->toSafeArray()), SENTINEL));
    check('model serialization: no leak', $serLeak->isEmpty());

    $evLeak = InfraProviderEvent::where('provider_id', $p->id)->get()
        ->filter(fn ($e) => str_contains(json_encode($e->toArray()), SENTINEL));
    check('events: no leak', $evLeak->isEmpty(), 'leaks=' . $evLeak->count());

    $apLeak = Approval::where('engine', Reg::ENGINE)->where('status', 'pending')->get()
        ->filter(fn ($a) => str_contains(json_encode($a->data_json), SENTINEL));
    check('approval payloads: no leak', $apLeak->isEmpty());

    ob_start(); var_dump($allCreds->first()); $dump = ob_get_clean();
    check('var_dump: no leak', !str_contains($dump, SENTINEL));

    try { throw new RuntimeException('probe ' . $allCreds->first()?->credential_key); }
    catch (Throwable $e) {
        check('exception rendering: no leak', !str_contains($e->getTraceAsString() . $e->getMessage(), SENTINEL));
    }

    check('queue payload (serialize): no leak',
        !str_contains(serialize($allCreds->first()->toArray()), SENTINEL));

    // ══════════════════════════════════════════════════════════════════════
    echo "\n=== 9. COMMERCIAL ISOLATION ===\n";
    // ══════════════════════════════════════════════════════════════════════

    $snap = fn () => [
        'subs'      => DB::table('infra_subscriptions')->orderBy('id')->pluck('state', 'id')->toArray(),
        'products'  => DB::table('infra_products')->orderBy('id')->pluck('lifecycle_status', 'id')->toArray(),
        'plans'     => DB::table('infra_plans')->count(),
        'renewals'  => DB::table('infra_renewals')->count(),
        'usage'     => DB::table('infra_usage_records')->count(),
        'hosting'   => DB::table('infra_hosting_accounts')->count(),
        'sites'     => DB::table('infra_hosted_sites')->count(),
        'entitle'   => DB::table('infra_plan_entitlements')->count(),
    ];

    $before = $snap();

    for ($i = 0; $i < 8; $i++) {
        $health->recordFailure($p->fresh(), 'dns', InfraProvider::ENV_SANDBOX, 'unauthorized', 'total failure');
    }
    $health->recomputeProviderRollup($p->fresh(), InfraProvider::ENV_SANDBOX);
    $registry->transition($p->fresh(), ProviderLifecycleState::DISABLED, $adminA->id, 'incident');

    $after = $snap();
    check('commercial state UNCHANGED across all 8 tables', $before === $after);

    // ══════════════════════════════════════════════════════════════════════
    echo "\n=== 10. EVENT COMPLETENESS ===\n";
    // ══════════════════════════════════════════════════════════════════════

    $events = InfraProviderEvent::where('provider_id', $p->id)->orderBy('id')->get();
    check('events recorded', $events->count() >= 25, 'count=' . $events->count());

    $byA_ev = $events->where('actor_user_id', $adminA->id)->count();
    $byB_ev = $events->where('actor_user_id', $adminB->id)->count();
    check('both administrators attributed in history', $byA_ev > 0 && $byB_ev > 0,
        "A={$byA_ev} B={$byB_ev}");

    $withCorr = $events->filter(fn ($e) => !empty($e->correlation_id))->count();
    check('all events carry a correlation id', $withCorr === $events->count());

    $withUid = $events->filter(fn ($e) => !empty($e->event_uid))->count();
    check('all events carry an immutable uid', $withUid === $events->count());

    $transitions = $events->filter(fn ($e) => $e->from_state !== null && $e->to_state !== null);
    check('state transitions preserved', $transitions->count() >= 5, 'count=' . $transitions->count());

} catch (Throwable $e) {
    $fail++;
    echo "\n  [ERROR] " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo "    at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n>>> ROLLED BACK. residual gov-harness providers: "
        . InfraProvider::where('provider_key', 'gov-harness')->count() . "\n";
    echo ">>> residual pending infra approvals from this run: "
        . Approval::where('engine', 'infrastructure')->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(5))->count() . "\n";
}

foreach ($notes as $n) { echo "  [NOTE] {$n}\n"; }

echo "\n==================== RESULT: {$pass} passed, {$fail} failed ====================\n";
exit($fail > 0 ? 1 : 0);
