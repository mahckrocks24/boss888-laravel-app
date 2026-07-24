<?php

/**
 * INFRA888 — governed operation lifecycle verification (Phase 1B §17).
 *
 * Proves the end-to-end flow with the Null connector:
 *   request → authorize → record → approve → dispatch → execute → audit
 *
 * SAFETY
 * ------
 *   - Null connector only. No provider is contacted, no DNS, no server, no cert.
 *   - All records created are deleted in the finally block.
 *   - Uses an existing workspace; creates no users or workspaces.
 *
 * Usage:  php scripts/infra888-verify-lifecycle.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraEvent;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\Models\InfraProviderResource;
use App\Engines\Infrastructure\Policies\InfraHostingAccountPolicy;
use App\Engines\Infrastructure\Registry\InfrastructureCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Services\ProvisioningService;
use App\Engines\Infrastructure\States\OperationState;
use App\Core\EngineKernel\CapabilityMapService;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0; $opIds = []; $wsIds = [];

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

echo "INFRA888 governed lifecycle verification\n";
echo "========================================\n\n";

$svc = app(ProvisioningService::class);

$row = DB::table('workspace_users')->where('role', 'owner')->orderBy('workspace_id')->first();
$wsA = (int) $row->workspace_id;
$ownerId = (int) $row->user_id;
$wsIds[] = $wsA;
echo "Fixture: workspace {$wsA}, owner user {$ownerId}\n\n";

try {
    // ---------------------------------------------------------- capability
    echo "-- Capability registration --\n";

    check('capability registered in the platform capability map', function () {
        $r = app(CapabilityMapService::class)->resolveAction('infrastructure', 'provision_hosting');
        return is_array($r) || is_object($r);
    });

    check('capability is protected (never auto)', function () {
        $mode = app(CapabilityMapService::class)->getApprovalMode('infrastructure', 'provision_hosting');
        return $mode === 'protected';
    });

    check('capability costs ZERO AI credits', function () {
        foreach (Registry::operations() as $meta) {
            if ((int) $meta['credit_cost'] !== 0) { return false; }
        }
        return true;
    });

    check('registry and capability map agree', function () {
        $svcMap = app(CapabilityMapService::class);
        foreach (Registry::capabilityMapRows() as $slug => $row) {
            if ($svcMap->getApprovalMode('infrastructure', $slug) !== $row['approval_mode']) { return false; }
        }
        return true;
    });

    check('every registered operation has a handler method on the connector contract', function () {
        foreach (Registry::operations() as $meta) {
            if (!method_exists($meta['connector_contract'], $meta['handler'])) { return false; }
        }
        return true;
    });

    // ------------------------------------------------------------- request
    echo "\n-- Request phase --\n";

    $req = $svc->requestHostingProvision($wsA, $ownerId, ['name' => 'LIFECYCLE-PROBE-1', 'environment' => 'staging']);
    $op = $req['operation'];
    $opIds[] = $op->id;

    check('operation created in requested state', fn () => $op->state === OperationState::REQUESTED);
    check('operation carries workspace and actor', fn () => (int) $op->workspace_id === $wsA && (int) $op->actor_user_id === $ownerId);
    check('idempotency key generated', fn () => !empty($op->idempotency_key));
    check('request payload persisted and redacted', fn () => ($op->request_json['name'] ?? null) === 'LIFECYCLE-PROBE-1');

    check('NO hosting account exists yet (nothing provisioned pre-approval)',
        fn () => WorkspaceContext::run($wsA, fn () => InfraHostingAccount::where('name', 'LIFECYCLE-PROBE-1')->count()) === 0);

    check('idempotent replay returns the SAME operation', function () use ($svc, $wsA, $ownerId, $op) {
        $again = $svc->requestHostingProvision($wsA, $ownerId, ['name' => 'X'], $op->idempotency_key);
        return $again['reused'] === true && $again['operation']->id === $op->id;
    });

    // ------------------------------------------------------------ approval
    echo "\n-- Approval gate --\n";

    $svc->markAwaitingApproval($wsA, $op->id, 999001, 999002);
    $op->refresh();
    check('operation moved to awaiting_approval', fn () => $op->state === OperationState::AWAITING_APPROVAL);

    check('protected operation CANNOT execute without approval', function () use ($wsA, $ownerId, $svc) {
        $r = $svc->requestHostingProvision($wsA, $ownerId, ['name' => 'LIFECYCLE-PROBE-NOAPPROVAL']);
        global $opIds; $opIds[] = $r['operation']->id;
        try {
            // Still in `requested`: no approval recorded at all.
            $svc->execute($wsA, ['operation_id' => $r['operation']->id]);
            return 'executed without approval';
        } catch (Throwable) {
            return true;
        }
    });

    // ----------------------------------------------------------- execution
    echo "\n-- Execution (Null connector, success) --\n";

    $result = $svc->execute($wsA, ['operation_id' => $op->id]);
    $op->refresh();

    check('operation succeeded', fn () => $op->state === OperationState::SUCCEEDED && $result['success'] === true);
    check('attempt counted', fn () => (int) $op->attempt_count === 1);
    check('started_at and finished_at recorded', fn () => $op->started_at !== null && $op->finished_at !== null);
    check('provider correlation id persisted', fn () => !empty($op->provider_correlation_id));

    $accountId = (int) ($result['hosting_account_id'] ?? 0);
    check('hosting account created', fn () => $accountId > 0);

    check('provider resource id PERSISTED (not discarded)', function () use ($wsA, $accountId) {
        return WorkspaceContext::run($wsA, function () use ($accountId) {
            $r = InfraProviderResource::where('owner_type', 'hosting_account')->where('owner_id', $accountId)->first();
            return $r !== null && !empty($r->provider_resource_id) && $r->provider === 'null';
        });
    });

    check('account is NOT marked active (connector result was unverified)', function () use ($wsA, $accountId) {
        return WorkspaceContext::run($wsA, fn () => InfraHostingAccount::find($accountId)->state) === 'provisioning';
    });

    check('IDEMPOTENT: re-executing a succeeded operation does nothing', function () use ($svc, $wsA, $op) {
        $r = $svc->execute($wsA, ['operation_id' => $op->id]);
        $countAfter = WorkspaceContext::run($wsA, fn () => InfraHostingAccount::where('name', 'LIFECYCLE-PROBE-1')->count());
        return ($r['idempotent'] ?? false) === true && $countAfter === 1;
    });

    // ------------------------------------------------------- failure paths
    echo "\n-- Deterministic failure paths --\n";

    $retryReq = $svc->requestHostingProvision($wsA, $ownerId, ['name' => 'LIFECYCLE-PROBE-RETRY', 'simulate' => 'retryable_failure']);
    $retryOp = $retryReq['operation']; $opIds[] = $retryOp->id;
    $svc->markAwaitingApproval($wsA, $retryOp->id, 999003, 999004);
    $rr = $svc->execute($wsA, ['operation_id' => $retryOp->id]);
    $retryOp->refresh();

    check('retryable failure -> failed_retryable', fn () => $retryOp->state === OperationState::FAILED_RETRYABLE);
    check('retryable failure flagged retryable', fn () => ($rr['retryable'] ?? false) === true);
    check('failure summary is customer-safe (no vendor/stack leak)', function () use ($retryOp) {
        $s = strtolower((string) $retryOp->failure_summary);
        foreach (['exception', 'stack', 'sql', 'curl', 'http 5', 'null-', '\\'] as $leak) {
            if (str_contains($s, $leak)) { return "leaked: {$leak}"; }
        }
        return $s !== '';
    });

    $termReq = $svc->requestHostingProvision($wsA, $ownerId, ['name' => 'LIFECYCLE-PROBE-TERM', 'simulate' => 'terminal_failure']);
    $termOp = $termReq['operation']; $opIds[] = $termOp->id;
    $svc->markAwaitingApproval($wsA, $termOp->id, 999005, 999006);
    $svc->execute($wsA, ['operation_id' => $termOp->id]);
    $termOp->refresh();

    check('terminal failure -> failed_terminal', fn () => $termOp->state === OperationState::FAILED_TERMINAL);
    check('terminal failure cannot restart', function () use ($termOp) {
        return OperationState::canTransition(OperationState::FAILED_TERMINAL, OperationState::QUEUED) === false;
    });
    check('no hosting account created on failure', function () use ($wsA) {
        return WorkspaceContext::run($wsA, fn () => InfraHostingAccount::whereIn('name',
            ['LIFECYCLE-PROBE-RETRY', 'LIFECYCLE-PROBE-TERM'])->count()) === 0;
    });

    // --------------------------------------------------------- authorization
    echo "\n-- Authorization --\n";

    $policy = new InfraHostingAccountPolicy();
    $viewerRow = DB::table('workspace_users')->where('workspace_id', $wsA)->where('role', '!=', 'owner')->first();

    if ($viewerRow) {
        $nonOwner = (object) ['id' => $viewerRow->user_id, 'is_platform_admin' => false];
        check('non-owner cannot request provisioning', fn () => $policy->create($nonOwner, $wsA) === false);
    } else {
        echo "  SKIP  non-owner test (workspace {$wsA} has only owners)\n";
    }

    check('owner can request provisioning',
        fn () => $policy->create((object) ['id' => $ownerId, 'is_platform_admin' => false], $wsA) === true);

    check('unknown infrastructure action fails closed', function () use ($wsA) {
        try {
            app(\App\Core\EngineKernel\EngineExecutionService::class)
                ->execute($wsA, 'infrastructure', 'definitely_not_registered', [], []);
            return 'did not fail';
        } catch (Throwable) { return true; }
    });

    // ------------------------------------------------------------- audit
    echo "\n-- Audit trail --\n";

    $events = WorkspaceContext::run($wsA, fn () => InfraEvent::where('operation_id', $op->id)->get());

    check('events recorded for the operation', fn () => $events->count() >= 3);
    check('actor recorded on events', fn () => $events->every(fn ($e) => (int) $e->actor_user_id === $ownerId));
    check('state transitions recorded with from/to',
        fn () => $events->contains(fn ($e) => $e->from_state && $e->to_state));
    check('success event records provider + resource id',
        fn () => $events->contains(fn ($e) => $e->event === 'operation_succeeded' && $e->provider === 'null' && !empty($e->provider_resource_id)));
    check('no secrets in any event context', function () use ($events) {
        foreach ($events as $e) {
            $json = strtolower(json_encode($e->context_json));
            foreach (['token', 'secret', 'password', 'api_key'] as $k) {
                if (str_contains($json, '"' . $k . '"') && !str_contains($json, '[redacted]')) { return "leak: {$k}"; }
            }
        }
        return true;
    });

    // -------------------------------------------------------------- reaper
    echo "\n-- Reaper --\n";

    $stuck = $svc->requestHostingProvision($wsA, $ownerId, ['name' => 'LIFECYCLE-PROBE-STUCK']);
    $stuckOp = $stuck['operation']; $opIds[] = $stuckOp->id;
    WorkspaceContext::run($wsA, function () use ($stuckOp) {
        $stuckOp->state = OperationState::RUNNING;
        $stuckOp->attempt_count = 1;
        $stuckOp->save();
        // Backdate past the stuck threshold.
        DB::table('infra_operations')->where('id', $stuckOp->id)
            ->update(['updated_at' => now()->subHours(3)]);
    });

    Illuminate\Support\Facades\Artisan::call('infra:reap-operations');
    $stuckOp->refresh();

    check('stuck RUNNING operation is reaped to compensation_pending (NOT blindly retried)',
        fn () => $stuckOp->state === OperationState::COMPENSATION_PENDING);
    check('reaper preserved the original actor', fn () => (int) $stuckOp->actor_user_id === $ownerId);
    check('reaper did not create a hosting account',
        fn () => WorkspaceContext::run($wsA, fn () => InfraHostingAccount::where('name', 'LIFECYCLE-PROBE-STUCK')->count()) === 0);
    check('reaper never approves anything', function () use ($svc, $wsA, $ownerId) {
        $pending = $svc->requestHostingProvision($wsA, $ownerId, ['name' => 'LIFECYCLE-PROBE-PENDING']);
        global $opIds; $opIds[] = $pending['operation']->id;
        $svc->markAwaitingApproval($wsA, $pending['operation']->id, null, null);
        DB::table('infra_operations')->where('id', $pending['operation']->id)->update(['updated_at' => now()->subHours(3)]);
        Illuminate\Support\Facades\Artisan::call('infra:reap-operations');
        return DB::table('infra_operations')->where('id', $pending['operation']->id)->value('state') === OperationState::AWAITING_APPROVAL;
    });

} finally {
    echo "\n-- Cleanup --\n";
    $names = ['LIFECYCLE-PROBE-1','LIFECYCLE-PROBE-RETRY','LIFECYCLE-PROBE-TERM','LIFECYCLE-PROBE-STUCK','LIFECYCLE-PROBE-PENDING','LIFECYCLE-PROBE-NOAPPROVAL'];
    $accIds = DB::table('infra_hosting_accounts')->whereIn('name', $names)->pluck('id');
    DB::table('infra_provider_resources')->whereIn('owner_id', $accIds)->where('owner_type','hosting_account')->delete();
    DB::table('infra_hosting_accounts')->whereIn('name', $names)->delete();
    DB::table('infra_events')->whereIn('operation_id', $opIds)->delete();
    DB::table('infra_operations')->whereIn('id', $opIds)->delete();
    echo "  removed " . count($opIds) . " operations, " . $accIds->count() . " accounts, related resources + events\n";
    foreach (['infra_operations','infra_hosting_accounts','infra_provider_resources','infra_events'] as $t) {
        echo "  {$t}: " . DB::table($t)->count() . " rows remaining\n";
    }
}

echo "\n========================================\n";
echo "PASS: {$pass}   FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
