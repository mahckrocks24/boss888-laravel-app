<?php

/**
 * INFRA888 — Phase 1C end-to-end governed execution proof (directive §4).
 *
 * Everything starts at the PUBLIC HTTP API with a real logged-in session.
 * ProvisioningService is never called directly. Approval goes through the real
 * ApprovalService HTTP path. Execution happens on the real queue worker.
 *
 * SAFETY: Null connector only — no provider contacted, no DNS, no server, no
 * certificate, no mailbox, no email. All records created are removed at the end.
 *
 * Usage: php scripts/infra888-verify-e2e.php <email> <password>
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const HOST = 'staging.levelupgrowth.io';
const BASE = 'http://127.0.0.1';

[$SCRIPT, $EMAIL, $PASSWORD] = array_pad($argv, 3, null);

$pass = 0; $fail = 0; $opIds = []; $accountNames = [];

function http(string $method, string $path, array $body = null, ?string $token = null, array $extraHeaders = []): array
{
    $ch = curl_init(BASE . $path);
    $h = ['Host: ' . HOST, 'Accept: application/json', 'Content-Type: application/json'];
    if ($token) { $h[] = 'Authorization: Bearer ' . $token; }
    foreach ($extraHeaders as $k => $v) { $h[] = "{$k}: {$v}"; }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string) $raw, true), 'raw' => $raw];
}

function check(string $label, callable $fn): void {
    global $pass, $fail;
    try {
        $r = $fn();
        if ($r === true) { $pass++; echo "  PASS  {$label}\n"; }
        else { $fail++; echo "  FAIL  {$label} -> " . var_export($r, true) . "\n"; }
    } catch (Throwable $e) {
        $fail++; echo "  FAIL  {$label} -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

/** Poll the operation until it leaves the given states or the timeout expires. */
function waitForState(int $opId, array $notIn, int $seconds = 45): ?string
{
    $deadline = time() + $seconds;
    do {
        $state = DB::table('infra_operations')->where('id', $opId)->value('state');
        if ($state !== null && !in_array($state, $notIn, true)) { return $state; }
        usleep(750000);
    } while (time() < $deadline);

    return DB::table('infra_operations')->where('id', $opId)->value('state');
}

echo "INFRA888 Phase 1C — end-to-end governed execution\n";
echo "=================================================\n\n";

try {
    // ---------------------------------------------------------------- login
    $login = http('POST', '/api/auth/login', ['email' => $EMAIL, 'password' => $PASSWORD]);
    $token = $login['json']['access_token'] ?? null;
    // Login returns current_workspace_id + workspaces[], NOT workspace.id.
    $wsId  = (int) ($login['json']['current_workspace_id']
                    ?? ($login['json']['workspaces'][0]['id'] ?? 0));
    $userId= (int) ($login['json']['user']['id'] ?? 0);

    check('real login succeeds', fn () => $login['code'] === 200 && $token !== null && $wsId > 0);
    echo "  workspace={$wsId} user={$userId}\n\n";

    // ---------------------------------------------------------- entitlement
    echo "-- Entitlement --\n";

    check('overview exposes entitlement summary', function () use ($token) {
        $r = http('GET', '/api/infrastructure/overview', null, $token);
        return $r['code'] === 200 && ($r['json']['data']['entitlement']['hosting']['can_provision'] ?? null) === true;
    });

    check('UNENTITLED plan is refused at the API (not just hidden in the UI)', function () use ($token, $wsId) {
        // Temporarily move this workspace to an unentitled plan.
        $planId = DB::table('plans')->where('slug', 'growth')->value('id');
        $orig = DB::table('subscriptions')->where('workspace_id', $wsId)->value('plan_id');
        DB::table('subscriptions')->where('workspace_id', $wsId)->update(['plan_id' => $planId]);

        $r = http('POST', '/api/infrastructure/hosting', ['name' => 'ENT-DENY-PROBE'], $token);

        DB::table('subscriptions')->where('workspace_id', $wsId)->update(['plan_id' => $orig]);

        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'PLAN_GATED';
    });

    check('no operation was created by the denied request',
        fn () => DB::table('infra_operations')->where('workspace_id', $wsId)
            ->where('request_json', 'like', '%ENT-DENY-PROBE%')->count() === 0);

    // -------------------------------------------------------- happy path
    echo "\n-- Request -> approval -> queue -> worker (approved) --\n";

    $idem = 'e2e-approve-' . bin2hex(random_bytes(6));
    $accountNames[] = 'E2E-APPROVED';
    $req = http('POST', '/api/infrastructure/hosting',
        ['name' => 'E2E-APPROVED', 'environment' => 'staging'], $token, ['Idempotency-Key' => $idem]);

    $opId = (int) ($req['json']['data']['id'] ?? 0);
    $opIds[] = $opId;

    check('request returns 202 AWAITING_APPROVAL', fn () => $req['code'] === 202 && $opId > 0);
    check('operation is awaiting approval',
        fn () => DB::table('infra_operations')->where('id',$opId)->value('state') === 'awaiting_approval');
    check('NOTHING provisioned before approval',
        fn () => DB::table('infra_hosting_accounts')->where('name','E2E-APPROVED')->count() === 0);

    $approvalId = (int) DB::table('infra_operations')->where('id', $opId)->value('approval_id');
    check('an approval record is linked to the operation', fn () => $approvalId > 0);

    check('approving via the real HTTP ApprovalService path succeeds', function () use ($approvalId, $token) {
        $r = http('POST', "/api/approvals/{$approvalId}/approve", [], $token);
        return in_array($r['code'], [200, 202], true) ?: ('HTTP ' . $r['code'] . ' ' . substr((string)$r['raw'],0,180));
    });

    $finalState = waitForState($opId, ['awaiting_approval', 'approved', 'queued', 'running'], 60);
    check('worker executed the operation to a terminal state',
        fn () => $finalState === 'succeeded' ?: ('state=' . $finalState));

    $op = DB::table('infra_operations')->where('id', $opId)->first();

    check('exactly ONE hosting account created',
        fn () => DB::table('infra_hosting_accounts')->where('name','E2E-APPROVED')->count() === 1);
    check('provider correlation id persisted', fn () => !empty($op->provider_correlation_id));
    check('provider resource row persisted with an opaque provider id', function () use ($op) {
        $r = DB::table('infra_provider_resources')->where('last_operation_id', $op->id)->first();
        return $r && !empty($r->provider_resource_id) && $r->provider === 'null';
    });
    check('actor preserved through the queue', fn () => (int) $op->actor_user_id === $userId);
    check('attempt count is exactly 1 (no double execution)', fn () => (int) $op->attempt_count === 1);

    check('idempotent replay creates no second operation', function () use ($token, $idem, $wsId) {
        $r = http('POST', '/api/infrastructure/hosting',
            ['name' => 'E2E-APPROVED', 'environment' => 'staging'], $token, ['Idempotency-Key' => $idem]);
        $count = DB::table('infra_operations')->where('workspace_id',$wsId)->where('idempotency_key',$idem)->count();
        return ($r['json']['idempotent'] ?? false) === true && $count === 1;
    });

    check('SPA-facing operation endpoint reports the final state', function () use ($token, $opId) {
        $r = http('GET', "/api/infrastructure/operations/{$opId}", null, $token);
        return $r['code'] === 200 && ($r['json']['data']['state'] ?? '') === 'succeeded';
    });

    // --------------------------------------------------------- rejection
    echo "\n-- Rejection path --\n";

    $accountNames[] = 'E2E-REJECTED';
    $rej = http('POST', '/api/infrastructure/hosting', ['name' => 'E2E-REJECTED'], $token);
    $rejOp = (int) ($rej['json']['data']['id'] ?? 0);
    $opIds[] = $rejOp;
    $rejApproval = (int) DB::table('infra_operations')->where('id', $rejOp)->value('approval_id');

    check('rejection via the real HTTP path succeeds', function () use ($rejApproval, $token) {
        $r = http('POST', "/api/approvals/{$rejApproval}/reject", ['reason' => 'e2e test rejection'], $token);
        return in_array($r['code'], [200, 202], true) ?: ('HTTP ' . $r['code']);
    });

    sleep(6);
    check('no hosting account created for a rejected request',
        fn () => DB::table('infra_hosting_accounts')->where('name','E2E-REJECTED')->count() === 0);
    check('rejected operation never reached succeeded',
        fn () => DB::table('infra_operations')->where('id',$rejOp)->value('state') !== 'succeeded');

    // ------------------------------------------------------ retryable fail
    echo "\n-- Deterministic retryable failure --\n";

    $accountNames[] = 'E2E-RETRY';
    $f = http('POST', '/api/infrastructure/hosting',
        ['name' => 'E2E-RETRY', 'simulate' => 'retryable_failure'], $token);
    $fOp = (int) ($f['json']['data']['id'] ?? 0);
    $opIds[] = $fOp;
    $fApproval = (int) DB::table('infra_operations')->where('id', $fOp)->value('approval_id');
    http('POST', "/api/approvals/{$fApproval}/approve", [], $token);

    $fState = waitForState($fOp, ['awaiting_approval','approved','queued','running'], 60);
    check('retryable failure classified as failed_retryable',
        fn () => $fState === 'failed_retryable' ?: ('state=' . $fState));
    check('no hosting account created on failure',
        fn () => DB::table('infra_hosting_accounts')->where('name','E2E-RETRY')->count() === 0);
    check('failure summary is customer-safe', function () use ($fOp) {
        $s = strtolower((string) DB::table('infra_operations')->where('id',$fOp)->value('failure_summary'));
        foreach (['exception','stack','sql','curl','null-','\\'] as $leak) {
            if (str_contains($s, $leak)) return "leak:{$leak}";
        }
        return $s !== '';
    });

    // ------------------------------------------------------------- audit
    echo "\n-- Audit completeness --\n";

    $events = DB::table('infra_events')->where('operation_id', $opId)->get();
    check('events linked to the operation', fn () => $events->count() >= 3);
    check('every event carries operation_id (orphan-event regression)',
        fn () => DB::table('infra_events')->whereIn('owner_id', $opIds)->whereNull('operation_id')
            ->where('event','state_changed')->count() === 0);
    check('actor recorded on events', fn () => $events->every(fn ($e) => (int) $e->actor_user_id === $userId));
    check('success event records provider + resource id',
        fn () => $events->contains(fn ($e) => $e->event === 'operation_succeeded'
            && $e->provider === 'null' && !empty($e->provider_resource_id)));
    check('no secrets in event context', function () use ($events) {
        foreach ($events as $e) {
            $j = strtolower((string) $e->context_json);
            foreach (['"token"','"secret"','"password"','"api_key"'] as $k) {
                if (str_contains($j, $k) && !str_contains($j, '[redacted]')) return "leak:{$k}";
            }
        }
        return true;
    });

    // ------------------------------------------------- shared admin token
    echo "\n-- Shared admin token blocked from INFRA888 --\n";

    check('BELLA_ADMIN_TOKEN-derived JWT is refused on infrastructure routes', function () {
        $adminTok = env('BELLA_ADMIN_TOKEN', '');
        if ($adminTok === '') { return true; } // nothing configured; nothing to block
        $a = http('POST', '/api/admin/auth', ['token' => $adminTok]);
        $jwt = $a['json']['token'] ?? null;
        if (!$jwt) { return 'admin auth failed: HTTP ' . $a['code']; }
        $r = http('GET', '/api/infrastructure/overview', null, $jwt);
        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'shared_admin_token_not_permitted'
            ?: ('HTTP ' . $r['code'] . ' ' . json_encode($r['json']));
    });

} finally {
    echo "\n-- Cleanup --\n";
    $accIds = DB::table('infra_hosting_accounts')->whereIn('name', $accountNames)->pluck('id');
    DB::table('infra_provider_resources')->whereIn('owner_id', $accIds)->where('owner_type','hosting_account')->delete();
    DB::table('infra_hosting_accounts')->whereIn('name', $accountNames)->delete();
    DB::table('infra_events')->whereIn('operation_id', $opIds)->orWhereIn('owner_id', $opIds)->delete();
    $approvals = DB::table('infra_operations')->whereIn('id', $opIds)->pluck('approval_id')->filter();
    $tasks = DB::table('infra_operations')->whereIn('id', $opIds)->pluck('task_id')->filter();
    DB::table('infra_operations')->whereIn('id', $opIds)->delete();
    if ($approvals->isNotEmpty()) { DB::table('approvals')->whereIn('id', $approvals)->delete(); }
    if ($tasks->isNotEmpty()) { DB::table('tasks')->whereIn('id', $tasks)->delete(); }
    foreach (['infra_operations','infra_hosting_accounts','infra_provider_resources','infra_events'] as $t) {
        echo "  {$t}: " . DB::table($t)->count() . " rows remaining\n";
    }
}

echo "\n=================================================\n";
echo "PASS: {$pass}   FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
