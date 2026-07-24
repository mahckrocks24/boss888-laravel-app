<?php

/**
 * INFRA888 — empirical workspace-isolation verification.
 *
 * WHY THIS EXISTS
 * ---------------
 * The PHPUnit suite cannot run on this install: composer install was executed
 * --no-dev, so Mockery is absent and RefreshDatabase errors during setUp. The
 * existing SystemHealthTest fails 6/6 for the same reason — pre-existing, not
 * caused by INFRA888.
 *
 * Rather than claim untested isolation, this script proves it against the REAL
 * staging database using the documented standalone-bootstrap workaround (artisan
 * tinker is also unavailable on this install).
 *
 * SAFETY
 * ------
 *   - Everything runs inside a transaction that is ALWAYS rolled back.
 *   - Uses synthetic workspace ids in the 990000+ range.
 *   - Touches no real tenant data, no provider, no live website.
 *
 * Usage:  php scripts/infra888-verify-isolation.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Policies\InfraHostingAccountPolicy;
use App\Engines\Infrastructure\Services\HostingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

const WS_A = 990001;
const WS_B = 990002;

$pass = 0;
$fail = 0;

function check(string $label, callable $assertion): void
{
    global $pass, $fail;

    try {
        $result = $assertion();
        if ($result === true) {
            $pass++;
            echo "  PASS  {$label}\n";
        } else {
            $fail++;
            echo "  FAIL  {$label} (returned " . var_export($result, true) . ")\n";
        }
    } catch (Throwable $e) {
        $fail++;
        echo "  FAIL  {$label} -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

echo "INFRA888 workspace-isolation verification\n";
echo "=========================================\n";
echo "All writes occur inside a transaction that is rolled back.\n\n";

DB::beginTransaction();

try {
    $a = WorkspaceContext::run(WS_A, fn () => InfraHostingAccount::create([
        'name' => 'ISOLATION-PROBE-A', 'state' => 'requested',
    ]));

    $b = WorkspaceContext::run(WS_B, fn () => InfraHostingAccount::create([
        'name' => 'ISOLATION-PROBE-B', 'state' => 'requested',
    ]));

    echo "Created probe records: A={$a->id} (ws " . WS_A . "), B={$b->id} (ws " . WS_B . ")\n\n";

    echo "-- Global scope --\n";

    check('workspace_id auto-populated from context', fn () => (int) $a->workspace_id === WS_A);

    check('ws A sees exactly its own record', fn () => WorkspaceContext::run(WS_A, function () {
        $rows = InfraHostingAccount::whereIn('name', ['ISOLATION-PROBE-A', 'ISOLATION-PROBE-B'])->get();
        return $rows->count() === 1 && $rows->first()->name === 'ISOLATION-PROBE-A';
    }));

    check('ws B sees exactly its own record', fn () => WorkspaceContext::run(WS_B, function () {
        $rows = InfraHostingAccount::whereIn('name', ['ISOLATION-PROBE-A', 'ISOLATION-PROBE-B'])->get();
        return $rows->count() === 1 && $rows->first()->name === 'ISOLATION-PROBE-B';
    }));

    check('query without context throws (fails closed)', function () {
        WorkspaceContext::reset();
        try {
            InfraHostingAccount::first();
            return false;
        } catch (RuntimeException) {
            return true;
        }
    });

    echo "\n-- Service layer --\n";

    $hosting = app(HostingService::class);

    check('cross-tenant find() throws ModelNotFound (-> 404)', function () use ($hosting, $b) {
        try {
            $hosting->find(WS_A, $b->id);
            return false;
        } catch (ModelNotFoundException) {
            return true;
        }
    });

    check('cross-tenant record absent from list()', fn () => collect($hosting->list(WS_A)['items'])
        ->pluck('name')->doesntContain('ISOLATION-PROBE-B'));

    check('CONTROL: same-workspace find() succeeds', fn () => $hosting->find(WS_A, $a->id)->id === $a->id);

    check('CONTROL: same-workspace list() includes own record',
        fn () => collect($hosting->list(WS_A)['items'])->pluck('name')->contains('ISOLATION-PROBE-A'));

    echo "\n-- Immutability --\n";

    // NOTE: uses a FRESH instance. Assigning to the shared $a would leave the
    // fixture mutated in memory even though save() correctly throws — which then
    // silently breaks every later policy assertion.
    check('workspace_id cannot be reassigned', function () {
        try {
            WorkspaceContext::run(WS_A, function () {
                $fresh = InfraHostingAccount::where('name', 'ISOLATION-PROBE-A')->first();
                $fresh->workspace_id = WS_B;
                $fresh->save();
            });
            return false;
        } catch (RuntimeException) {
            return true;
        }
    });

    check('context released after an exception', function () {
        try {
            WorkspaceContext::run(WS_A, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
        return WorkspaceContext::isSet() === false;
    });

    check('nested contexts restore the outer workspace',
        fn () => WorkspaceContext::run(WS_A, function () {
            WorkspaceContext::run(WS_B, fn () => null);
            return WorkspaceContext::id() === WS_A;
        }));

    echo "\n-- Policy layer --\n";

    $policy = new InfraHostingAccountPolicy();

    // workspace_users has FKs to BOTH workspaces and users, so synthetic parents
    // must exist. All of this is inside the transaction that gets rolled back.
    DB::table('users')->insert([
        ['id' => 990010, 'name' => 'probe-owner',  'email' => 'probe-owner@infra888.invalid',  'password' => 'x'],
        ['id' => 990011, 'name' => 'probe-member', 'email' => 'probe-member@infra888.invalid', 'password' => 'x'],
        ['id' => 990012, 'name' => 'probe-viewer', 'email' => 'probe-viewer@infra888.invalid', 'password' => 'x'],
    ]);
    DB::table('workspaces')->insert([
        ['id' => WS_A, 'name' => 'INFRA888 probe A', 'slug' => 'infra888-probe-a', 'created_by' => 990010],
        ['id' => WS_B, 'name' => 'INFRA888 probe B', 'slug' => 'infra888-probe-b', 'created_by' => 990010],
    ]);
    DB::table('workspace_users')->insert([
        ['workspace_id' => WS_A, 'user_id' => 990010, 'role' => 'owner'],
        ['workspace_id' => WS_A, 'user_id' => 990011, 'role' => 'member'],
        ['workspace_id' => WS_A, 'user_id' => 990012, 'role' => 'viewer'],
    ]);

    $owner    = (object) ['id' => 990010, 'is_platform_admin' => false];
    $member   = (object) ['id' => 990011, 'is_platform_admin' => false];
    $viewer   = (object) ['id' => 990012, 'is_platform_admin' => false];
    $outsider = (object) ['id' => 990099, 'is_platform_admin' => false];

    check('non-member denied view',            fn () => $policy->view($outsider, $a) === false);
    check('viewer may view',                   fn () => $policy->view($viewer, $a) === true);
    check('viewer denied backups',             fn () => $policy->viewBackups($viewer, $a) === false);
    check('member denied terminate',           fn () => $policy->terminate($member, $a) === false);
    check('member denied suspend',             fn () => $policy->suspend($member, $a) === false);
    check('owner allowed terminate',           fn () => $policy->terminate($owner, $a) === true);
    check('null user denied',                  fn () => $policy->view(null, $a) === false);

    echo "\n-- Append-only audit --\n";

    check('InfraEvent rejects update', function () {
        $e = WorkspaceContext::run(WS_A, fn () => App\Engines\Infrastructure\Models\InfraEvent::create([
            'owner_type' => 'hosting_account', 'event' => 'probe',
            'severity' => 'info', 'summary' => 'probe', 'created_at' => now(),
        ]));
        try {
            $e->summary = 'tampered';
            $e->save();
            return false;
        } catch (RuntimeException) {
            return true;
        }
    });

} finally {
    DB::rollBack();
    echo "\nTransaction rolled back — no rows persisted.\n";
}

echo "\n=========================================\n";
echo "PASS: {$pass}   FAIL: {$fail}\n";

exit($fail === 0 ? 0 : 1);
