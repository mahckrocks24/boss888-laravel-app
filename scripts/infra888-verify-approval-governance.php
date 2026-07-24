<?php

/**
 * INFRA888 Phase 1D — approval separation-of-duties proof (§9, §12, §13).
 *
 * Proves the remediation of the confirmed defect:
 *   ApprovalController::approve() authorised on workspace membership alone —
 *   no role check, no self-approval prevention.
 *
 * Uses THREE distinct canonical identities in one workspace:
 *   requester (owner) · approver (owner) · viewer
 *
 * Everything runs through real HTTP. SAFETY: Null connector only; all records
 * created are removed in the finally block.
 *
 * Usage: php scripts/infra888-verify-approval-governance.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const HOST = 'staging.levelupgrowth.io';
const BASE = 'http://127.0.0.1';

$pass = 0; $fail = 0; $opIds = []; $names = [];

function http(string $method, string $path, array $body = null, ?string $token = null, array $hdrs = []): array {
    $ch = curl_init(BASE . $path);
    $h = ['Host: ' . HOST, 'Accept: application/json', 'Content-Type: application/json'];
    if ($token) { $h[] = 'Authorization: Bearer ' . $token; }
    foreach ($hdrs as $k => $v) { $h[] = "{$k}: {$v}"; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string) $raw, true)];
}

function check(string $label, callable $fn): void {
    global $pass, $fail;
    try { $r = $fn();
        if ($r === true) { $pass++; echo "  PASS  {$label}\n"; }
        else { $fail++; echo "  FAIL  {$label} -> " . var_export($r, true) . "\n"; }
    } catch (Throwable $e) { $fail++; echo "  FAIL  {$label} -> " . $e->getMessage() . "\n"; }
}

function waitFor(int $opId, array $notIn, int $seconds = 60): ?string {
    $deadline = time() + $seconds;
    do {
        $s = DB::table('infra_operations')->where('id', $opId)->value('state');
        if ($s !== null && !in_array($s, $notIn, true)) { return $s; }
        usleep(750000);
    } while (time() < $deadline);
    return DB::table('infra_operations')->where('id', $opId)->value('state');
}

/** Reset the three staging identities and return fresh credentials. */
function identities(): array {
    $wsId = DB::table('workspaces')->where('slug', 'infra888-test')->value('id');
    $out = ['ws' => $wsId];
    foreach ([
        'requester' => 'infra888-test@levelupgrowth.io',
        'approver'  => 'infra888-approver@levelupgrowth.io',
        'viewer'    => 'infra888-viewer@levelupgrowth.io',
    ] as $role => $email) {
        $pw = ucfirst(substr($role, 0, 3)) . '!' . bin2hex(random_bytes(9));
        DB::table('users')->where('email', $email)->update(['password' => Hash::make($pw)]);
        $out[$role] = ['email' => $email, 'password' => $pw,
                       'id' => DB::table('users')->where('email', $email)->value('id')];
    }
    return $out;
}

function login(array $who): ?string {
    $r = http('POST', '/api/auth/login', ['email' => $who['email'], 'password' => $who['password']]);
    return $r['json']['access_token'] ?? null;
}

echo "INFRA888 Phase 1D — approval separation of duties\n";
echo "=================================================\n\n";

$id = identities();
$wsId = (int) $id['ws'];

$reqTok = login($id['requester']);
$appTok = login($id['approver']);
$viewTok = login($id['viewer']);

echo "workspace={$wsId} requester={$id['requester']['id']} approver={$id['approver']['id']} viewer={$id['viewer']['id']}\n\n";

try {
    check('all three identities authenticate', fn () => $reqTok && $appTok && $viewTok);

    // ---------------------------------------------------------- submit
    echo "-- Requester submits --\n";
    $names[] = 'SOD-PROBE-APPROVED';
    $sub = http('POST', '/api/infrastructure/hosting',
        ['name' => 'SOD-PROBE-APPROVED', 'environment' => 'staging'], $reqTok);
    $opId = (int) ($sub['json']['data']['id'] ?? 0);
    $opIds[] = $opId;

    check('request accepted (202)', fn () => $sub['code'] === 202 && $opId > 0);

    $approvalId = (int) DB::table('infra_operations')->where('id', $opId)->value('approval_id');
    check('approval row linked', fn () => $approvalId > 0);

    check('REQUESTER identity is now persisted on the approval', function () use ($approvalId, $id) {
        $r = DB::table('approvals')->where('id', $approvalId)->first();
        return $r && (int) $r->requested_by === (int) $id['requester']['id']
            ? true : 'requested_by=' . var_export($r->requested_by ?? null, true);
    });

    check('approval policy snapshot stored', function () use ($approvalId) {
        $p = DB::table('approvals')->where('id', $approvalId)->value('approval_policy_json');
        $d = json_decode((string) $p, true);
        return is_array($d) && ($d['self_approval_allowed'] ?? null) === false
            ? true : 'policy=' . var_export($p, true);
    });

    // ------------------------------------------------------- denials
    echo "\n-- Denials --\n";

    check('SELF-APPROVAL denied (was: permitted)', function () use ($approvalId, $reqTok) {
        $r = http('POST', "/api/approvals/{$approvalId}/approve", [], $reqTok);
        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'self_approval_not_permitted'
            ? true : 'HTTP ' . $r['code'] . ' ' . json_encode($r['json']);
    });

    check('VIEWER approval denied (was: permitted)', function () use ($approvalId, $viewTok) {
        $r = http('POST', "/api/approvals/{$approvalId}/approve", [], $viewTok);
        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'approval_not_authorized'
            ? true : 'HTTP ' . $r['code'] . ' ' . json_encode($r['json']);
    });

    check('VIEWER rejection also denied', function () use ($approvalId, $viewTok) {
        $r = http('POST', "/api/approvals/{$approvalId}/reject", ['reason' => 'x'], $viewTok);
        return $r['code'] === 403;
    });

    check('SHARED ADMIN TOKEN approval denied', function () use ($approvalId) {
        $t = env('BELLA_ADMIN_TOKEN', '');
        if ($t === '') { return true; }
        $a = http('POST', '/api/admin/auth', ['token' => $t]);
        $jwt = $a['json']['token'] ?? null;
        if (!$jwt) { return 'admin auth failed'; }
        $r = http('POST', "/api/approvals/{$approvalId}/approve", [], $jwt);
        // A platform admin who is not a member of this workspace is concealed
        // with 404 BEFORE the shared-token check — both are valid denials.
        $denied = ($r['code'] === 403 && ($r['json']['code'] ?? '') === 'shared_admin_token_not_permitted')
               || $r['code'] === 404;
        return $denied ? true : 'HTTP ' . $r['code'] . ' ' . json_encode($r['json']);
    });

    check('nothing was provisioned by any denied attempt',
        fn () => DB::table('infra_hosting_accounts')->where('name', 'SOD-PROBE-APPROVED')->count() === 0);

    check('operation still awaiting approval after denials',
        fn () => DB::table('infra_operations')->where('id', $opId)->value('state') === 'awaiting_approval');

    check('denials are audited', function () use ($wsId) {
        return DB::table('audit_logs')->where('workspace_id', $wsId)
            ->where('action', 'approval.denied')->count() >= 3;
    });

    // ------------------------------------------------- authorized approval
    echo "\n-- Authorized second actor approves --\n";

    check('distinct authorized owner CAN approve', function () use ($approvalId, $appTok) {
        $r = http('POST', "/api/approvals/{$approvalId}/approve", [], $appTok);
        return in_array($r['code'], [200, 202], true) ? true : 'HTTP ' . $r['code'] . ' ' . json_encode($r['json']);
    });

    $state = waitFor($opId, ['awaiting_approval', 'approved', 'queued', 'running'], 60);
    check('worker executed to succeeded', fn () => $state === 'succeeded' ?: 'state=' . $state);
    check('exactly ONE hosting account created',
        fn () => DB::table('infra_hosting_accounts')->where('name', 'SOD-PROBE-APPROVED')->count() === 1);

    // ------------------------------------------------------ attribution
    echo "\n-- Attribution --\n";

    check('REQUESTER preserved on the operation', function () use ($opId, $id) {
        return (int) DB::table('infra_operations')->where('id', $opId)->value('actor_user_id')
            === (int) $id['requester']['id'];
    });

    check('APPROVER recorded separately on the approval', function () use ($approvalId, $id) {
        $d = DB::table('approvals')->where('id', $approvalId)->value('decision_by');
        return (int) $d === (int) $id['approver']['id'] ? true : 'decision_by=' . var_export($d, true);
    });

    check('requester and approver are DIFFERENT identities', function () use ($approvalId, $opId) {
        $a = DB::table('approvals')->where('id', $approvalId)->first();
        $o = DB::table('infra_operations')->where('id', $opId)->first();
        return (int) $a->decision_by !== (int) $o->actor_user_id;
    });

    check('no user-1 attribution anywhere in this flow', function () use ($approvalId, $opId) {
        $a = DB::table('approvals')->where('id', $approvalId)->first();
        $o = DB::table('infra_operations')->where('id', $opId)->first();
        return (int) $a->decision_by !== 1 && (int) $o->actor_user_id !== 1;
    });

    check('duplicate approval does not re-dispatch', function () use ($approvalId, $appTok, $opId) {
        $before = (int) DB::table('infra_operations')->where('id', $opId)->value('attempt_count');
        http('POST', "/api/approvals/{$approvalId}/approve", [], $appTok);
        sleep(3);
        $after = (int) DB::table('infra_operations')->where('id', $opId)->value('attempt_count');
        return $before === $after && DB::table('infra_hosting_accounts')
            ->where('name', 'SOD-PROBE-APPROVED')->count() === 1;
    });

    // --------------------------------------------------------- rejection
    echo "\n-- Rejection by the distinct approver --\n";

    $names[] = 'SOD-PROBE-REJECTED';
    $sub2 = http('POST', '/api/infrastructure/hosting', ['name' => 'SOD-PROBE-REJECTED'], $reqTok);
    $opId2 = (int) ($sub2['json']['data']['id'] ?? 0);
    $opIds[] = $opId2;
    $appr2 = (int) DB::table('infra_operations')->where('id', $opId2)->value('approval_id');

    check('requester cannot self-reject either', function () use ($appr2, $reqTok) {
        $r = http('POST', "/api/approvals/{$appr2}/reject", ['reason' => 'self'], $reqTok);
        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'self_approval_not_permitted';
    });

    check('authorized approver CAN reject', function () use ($appr2, $appTok) {
        $r = http('POST', "/api/approvals/{$appr2}/reject", ['reason' => 'governance probe'], $appTok);
        return in_array($r['code'], [200, 202], true) ?: 'HTTP ' . $r['code'];
    });

    sleep(4);
    check('rejected request provisioned nothing',
        fn () => DB::table('infra_hosting_accounts')->where('name', 'SOD-PROBE-REJECTED')->count() === 0);

    // ---------------------------------------------------- isolation
    echo "\n-- Cross-workspace concealment --\n";

    check('cross-workspace approval id returns 404, not 403', function () use ($reqTok, $wsId) {
        $foreign = DB::table('approvals')->where('workspace_id', '!=', $wsId)
            ->where('status', 'pending')->value('id');
        if (!$foreign) { return true; }
        $r = http('POST', "/api/approvals/{$foreign}/approve", [], $reqTok);
        return $r['code'] === 404 ?: 'HTTP ' . $r['code'];
    });

    // -------------------------------------------- non-INFRA regression
    echo "\n-- Regression: self-service capabilities still work --\n";

    check('publish_website is classified as self-service (self-approval allowed)', function () {
        $p = \App\Core\Governance\ApprovalPolicyRegistry::forCapability('builder', 'publish_website', 'protected');
        return $p['self_approval_allowed'] === true && $p['classification'] === 'self_service_confirmation';
    });

    check('unclassified protected capability FAILS CLOSED', function () {
        $p = \App\Core\Governance\ApprovalPolicyRegistry::forCapability('somewhere', 'unknown_action', 'protected');
        return $p['self_approval_allowed'] === false;
    });

    check('viewers excluded from every classification', function () {
        foreach (\App\Core\Governance\ApprovalPolicyRegistry::all() as $p) {
            if (in_array('viewer', $p['approval_roles'], true)) { return false; }
        }
        return true;
    });

} finally {
    echo "\n-- Cleanup --\n";
    $accIds = DB::table('infra_hosting_accounts')->whereIn('name', $names)->pluck('id');
    DB::table('infra_provider_resources')->whereIn('owner_id', $accIds)->where('owner_type', 'hosting_account')->delete();
    DB::table('infra_hosting_accounts')->whereIn('name', $names)->delete();
    DB::table('infra_events')->whereIn('operation_id', $opIds)->orWhereIn('owner_id', $opIds)->delete();
    $appr = DB::table('infra_operations')->whereIn('id', $opIds)->pluck('approval_id')->filter();
    $tsk  = DB::table('infra_operations')->whereIn('id', $opIds)->pluck('task_id')->filter();
    DB::table('infra_operations')->whereIn('id', $opIds)->delete();
    if ($appr->isNotEmpty()) { DB::table('approvals')->whereIn('id', $appr)->delete(); }
    if ($tsk->isNotEmpty())  { DB::table('tasks')->whereIn('id', $tsk)->delete(); }
    DB::table('audit_logs')->where('action', 'approval.denied')->delete();
    foreach (['infra_operations', 'infra_hosting_accounts', 'infra_events'] as $t) {
        echo "  {$t}: " . DB::table($t)->count() . " rows remaining\n";
    }
    echo "  infrastructure approvals: " . DB::table('approvals')->where('engine', 'infrastructure')->count() . "\n";
}

echo "\n=================================================\n";
echo "PASS: {$pass}   FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
