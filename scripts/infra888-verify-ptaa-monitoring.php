<?php
/**
 * INFRA888 Phase 3A — REAL monitoring of PTAA live endpoint + Founder Mode proof.
 *
 * This does something the earlier phases never could: it exercises a REAL customer
 * capability end to end against a REAL production target. The HTTP probe below
 * genuinely hits https://levelupgrowth.io/ptaa/ and records what it observes.
 *
 * Rolled back at the end so no test rows persist (a separate onboarding script
 * creates PTAA's durable records). Tenant-scoped throughout.
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Connectors\Infrastructure\SelfHosted\SelfHostedMonitoringConnector;
use App\Core\Auth\AdminGovernanceService;
use App\Core\Auth\TotpMfaService;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraGovernanceState;
use App\Engines\Infrastructure\Models\InfraMonitorCheck;
use App\Engines\Infrastructure\Models\InfraMonitorIncident;
use App\Engines\Infrastructure\Models\InfraMonitorResult;
use App\Engines\Infrastructure\Services\InfrastructureMonitoringService;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] {$l}\n"; }
    else { $fail++; echo "  [FAIL] {$l}" . ($d ? " -> {$d}" : '') . "\n"; }
}

$mon = app(InfrastructureMonitoringService::class);
$gov = app(AdminGovernanceService::class);
$mfa = app(TotpMfaService::class);
$SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
function totp($m, $seed) { $r = new ReflectionMethod(TotpMfaService::class, 'hotp'); $r->setAccessible(true); return $r->invoke($m, $seed, (int) floor(time()/30)); }

DB::beginTransaction();
try {
    echo "\n=== 1. REAL PROBE OF PTAA (raw connector) ===\n";
    $connector = app(SelfHostedMonitoringConnector::class);
    $obs = $connector->probe('https://levelupgrowth.io/ptaa/', 200, 15);
    echo "  observed: status={$obs['status']} http={$obs['http_code']} response_ms={$obs['response_ms']}\n";
    check('PTAA probe returned a real observation', in_array($obs['status'], ['up', 'degraded', 'down'], true));
    check('PTAA responded (http code present)', $obs['http_code'] !== null, 'code=' . var_export($obs['http_code'], true));
    check('response time measured', $obs['response_ms'] >= 0);

    echo "\n=== 2. TENANT-SCOPED CHECK + RESULT (workspace context) ===\n";
    $ws = Workspace::firstOrCreate(['id' => 993001],
        ['name' => 'ptaa-mon-test', 'slug' => 'ptaa-mon-' . Str::random(6), 'created_by' => 1]);

    WorkspaceContext::run($ws->id, function () use ($mon, &$checkId, &$result) {
        $c = $mon->createCheck([
            'name'       => 'PTAA Platform (test)',
            'target_url' => 'https://levelupgrowth.io/ptaa/',
            'interval_seconds' => 300,
        ]);
        $checkId = $c->id;
        $result = $mon->runCheck($c->fresh(), 'manual');
    });

    check('check created + result recorded', $result && $result->exists);
    check('result carries real status', in_array($result->status, ['up', 'degraded', 'down'], true),
        'status=' . $result->status);

    // Tenant isolation: the check is invisible from another workspace.
    $leaked = WorkspaceContext::run(999999, fn () => InfraMonitorCheck::find($checkId));
    check('check is INVISIBLE from another workspace (tenant isolation)', $leaked === null);
    $visible = WorkspaceContext::run($ws->id, fn () => InfraMonitorCheck::find($checkId));
    check('check is visible in its own workspace', $visible !== null);

    echo "\n=== 3. INCIDENT LIFECYCLE (simulated down -> recovery) ===\n";
    WorkspaceContext::run($ws->id, function () use ($mon, $checkId) {
        $c = InfraMonitorCheck::find($checkId);
        // Force a down observation by pointing at an unreachable port.
        $c->update(['target_url' => 'http://127.0.0.1:1/down', 'timeout_seconds' => 2]);
        $mon->runCheck($c->fresh(), 'manual');
    });
    $openInc = WorkspaceContext::run($ws->id, fn () => InfraMonitorIncident::where('monitor_check_id', $checkId)->where('state', 'open')->count());
    check('down transition opened an incident', $openInc === 1, "open incidents={$openInc}");

    WorkspaceContext::run($ws->id, function () use ($mon, $checkId) {
        $c = InfraMonitorCheck::find($checkId);
        // Restore a reachable target -> recovery.
        $c->update(['target_url' => 'https://levelupgrowth.io/ptaa/', 'timeout_seconds' => 15]);
        $mon->runCheck($c->fresh(), 'manual');
    });
    $resolvedInc = WorkspaceContext::run($ws->id, fn () => InfraMonitorIncident::where('monitor_check_id', $checkId)->where('state', 'resolved')->count());
    check('recovery closed the incident', $resolvedInc === 1, "resolved={$resolvedInc}");

    echo "\n=== 4. UPTIME COMPUTED FROM TIME SERIES ===\n";
    $up = WorkspaceContext::run($ws->id, function () use ($mon, $checkId) {
        return $mon->uptime(InfraMonitorCheck::find($checkId), now()->subHour(), now()->addMinute());
    });
    echo "  uptime: {$up['uptime_pct']}% over {$up['checks']} checks (up+degraded={$up['up']}, down={$up['down']})\n";
    check('uptime computed from >=3 observations', $up['checks'] >= 3);
    check('uptime reflects one down period', $up['down'] >= 1);

    echo "\n=== 5. RESULTS ARE APPEND-ONLY ===\n";
    $immutable = false;
    try {
        $r = WorkspaceContext::run($ws->id, fn () => InfraMonitorResult::where('monitor_check_id', $checkId)->first());
        $r->update(['status' => 'down', 'http_code' => 999]);
    } catch (\Throwable $e) { $immutable = str_contains($e->getMessage(), 'append-only'); }
    check('monitor results cannot be edited', $immutable);

    echo "\n=== 6. FOUNDER MODE (Stage 1 governance) ===\n";
    $state = InfraGovernanceState::current();
    $state->update(['mode' => 'bootstrap', 'activated_at' => null]); // clean baseline in txn

    // A founder WITHOUT MFA cannot enter Founder Mode (fails closed).
    $noMfaFounder = User::create(['name' => 'founder-nomfa', 'email' => 'f-nomfa-' . Str::random(6) . '@t.local',
        'password' => Hash::make(Str::random(16)), 'is_admin' => 1, 'is_platform_admin' => 1, 'account_classification' => 'standard']);
    $blocked = false;
    try { $gov->enterFounderMode($noMfaFounder); } catch (\Throwable $e) { $blocked = str_contains($e->getMessage(), 'MFA-enrolled'); }
    check('Founder Mode REQUIRES MFA (fails closed without it)', $blocked);

    // A validation-only account cannot hold Founder Mode.
    $valAcct = User::create(['name' => 'val', 'email' => 'val-' . Str::random(6) . '@t.local',
        'password' => Hash::make(Str::random(16)), 'is_admin' => 1, 'is_platform_admin' => 1, 'account_classification' => 'validation_only']);
    $valAcct->forceFill(['mfa_secret_encrypted' => $SEED, 'mfa_enabled' => false])->save();
    $mfa->confirm($valAcct->fresh(), totp($mfa, $SEED));
    $valBlocked = false;
    try { $gov->enterFounderMode($valAcct->fresh()); } catch (\Throwable $e) { $valBlocked = str_contains($e->getMessage(), 'may not hold'); }
    check('validation_only account cannot hold Founder Mode', $valBlocked);

    // A real MFA-enrolled founder CAN enter Founder Mode.
    $founder = User::create(['name' => 'founder', 'email' => 'founder-' . Str::random(6) . '@t.local',
        'password' => Hash::make(Str::random(16)), 'is_admin' => 1, 'is_platform_admin' => 1, 'account_classification' => 'standard']);
    $founder->forceFill(['mfa_secret_encrypted' => $SEED, 'mfa_enabled' => false])->save();
    $mfa->confirm($founder->fresh(), totp($mfa, $SEED));
    $gov->enterFounderMode($founder->fresh());

    check('Founder Mode entered', $gov->currentMode() === InfraGovernanceState::MODE_FOUNDER);
    check('step-up ENFORCED in Founder Mode', $gov->stepUpEnforced());
    check('Founder Mode is NOT treated as degraded', !$gov->isDegraded());
    check('founder may self-authorize', $gov->founderSelfApprovalPermitted($founder->fresh()));
    check('a different admin may NOT self-authorize as founder', !$gov->founderSelfApprovalPermitted($noMfaFounder->fresh()));
    check('governance never regressed to bootstrap', InfraGovernanceState::current()->wasEverActivated());

} catch (\Throwable $e) {
    $fail++;
    echo "\n  [ERROR] " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
    echo "\n>>> ROLLED BACK. residual test checks: " . InfraMonitorCheck::withoutGlobalScopes()->where('name', 'like', '%(test)%')->count() . "\n";
    echo ">>> live governance mode after rollback: " . InfraGovernanceState::current()->mode . "\n";
}

echo "\n==================== RESULT: {$pass} passed, {$fail} failed ====================\n";
exit($fail > 0 ? 1 : 0);
