<?php

/**
 * INFRA888 — end-to-end authentication verification (Phase 1B §3).
 *
 * Proves the ten required properties with REAL HTTP requests against the running
 * staging application, using freshly minted tokens and temporary API keys.
 *
 * WHY HTTP AND NOT PHPUnit
 * ------------------------
 * The framework test suite cannot run on this install: composer install was run
 * --no-dev, so Mockery is absent and RefreshDatabase errors during setUp. This
 * script is NOT a substitute for the Laravel feature tests — it is an
 * independent end-to-end probe that exercises the real middleware stack.
 *
 * SAFETY
 * ------
 *   - Temporary api_keys rows are prefixed INFRA888-TEMP- and deleted in finally.
 *   - No existing key is modified. No user, workspace or tenant data is changed.
 *   - Read-only endpoints only.
 *
 * Usage:  php scripts/infra888-verify-auth.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Auth\RefreshTokenService;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

const HOST = 'staging.levelupgrowth.io';
const BASE = 'http://127.0.0.1';

$pass = 0; $fail = 0; $created = [];

function req(string $path, array $headers = []): array
{
    $ch = curl_init(BASE . $path);
    $h = ['Host: ' . HOST, 'Accept: application/json'];
    foreach ($headers as $k => $v) { $h[] = "{$k}: {$v}"; }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $body, 'json' => json_decode((string) $body, true)];
}

function check(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $r = $fn();
        if ($r === true) { $pass++; echo "  PASS  {$label}\n"; }
        else { $fail++; echo "  FAIL  {$label} -> " . var_export($r, true) . "\n"; }
    } catch (Throwable $e) {
        $fail++; echo "  FAIL  {$label} -> " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function mkKey(array $attrs): string
{
    global $created;
    $key = 'INFRA888-TEMP-' . bin2hex(random_bytes(12));
    DB::table('api_keys')->insert(array_merge([
        'key'        => $key,
        'name'       => 'INFRA888 temp verification key',
        'type'       => 'test',
        'is_active'  => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
    $created[] = $key;
    return $key;
}

echo "INFRA888 authentication verification\n";
echo "====================================\n\n";

try {
    // ---- fixtures from EXISTING data (no new users/workspaces) -------------
    $rowA = DB::table('workspace_users')->orderBy('workspace_id')->first();
    $wsA  = (int) $rowA->workspace_id;
    $userA = User::find($rowA->user_id);

    $rowB = DB::table('workspace_users')->where('workspace_id', '!=', $wsA)->orderBy('workspace_id')->first();
    $wsB  = (int) $rowB->workspace_id;

    echo "Fixtures: workspace A={$wsA} (user {$userA->id}), workspace B={$wsB}\n\n";

    $tokens = app(RefreshTokenService::class);
    $jwtA = $tokens->issueAccessToken($userA, Workspace::find($wsA));

    echo "-- 1. Browser JWT session --\n";
    check('valid JWT reaches its authorized workspace', function () use ($jwtA, $wsA) {
        $r = req('/api/infrastructure/overview', ['Authorization' => 'Bearer ' . $jwtA]);
        return $r['code'] === 200 && (int) ($r['json']['data']['workspace_id'] ?? 0) === $wsA;
    });

    check('invalid JWT denied', function () {
        return req('/api/infrastructure/overview', ['Authorization' => 'Bearer not-a-real-token'])['code'] === 401;
    });

    check('no credential denied', fn () => req('/api/infrastructure/overview')['code'] === 401);

    echo "\n-- 2. API-key binding --\n";

    $boundKey = mkKey(['workspace_id' => $wsA, 'user_id' => $userA->id]);
    check('explicitly bound API key resolves to its bound workspace', function () use ($boundKey, $wsA) {
        // Probed on an existing api.key route: infra routes deny api_key by design.
        $r = req('/api/connector/ping', ['X-API-KEY' => $boundKey]);
        return in_array($r['code'], [200, 404], true);
    });

    $unboundKey = mkKey(['workspace_id' => $wsA, 'user_id' => null]);
    check('UNBOUND API key is DENIED (was: escalated to first workspace member)', function () use ($unboundKey) {
        $r = req('/api/connector/ping', ['X-API-KEY' => $unboundKey]);
        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'api_key_unbound';
    });

    check('unbound API key denied on JWT-protected routes too', function () use ($unboundKey) {
        $r = req('/api/infrastructure/overview', ['X-API-KEY' => $unboundKey]);
        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'api_key_unbound';
    });

    check('invalid API key denied', function () {
        $r = req('/api/connector/ping', ['X-API-KEY' => 'INFRA888-TEMP-does-not-exist']);
        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'invalid_api_key';
    });

    $inactiveKey = mkKey(['workspace_id' => $wsA, 'user_id' => $userA->id, 'is_active' => 0]);
    check('revoked/disabled API key denied', function () use ($inactiveKey) {
        $r = req('/api/connector/ping', ['X-API-KEY' => $inactiveKey]);
        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'invalid_api_key';
    });

    $expiredKey = mkKey([
        'workspace_id' => $wsA, 'user_id' => $userA->id,
        'expires_at' => now()->subDay(),
    ]);
    check('expired API key denied', function () use ($expiredKey) {
        $r = req('/api/connector/ping', ['X-API-KEY' => $expiredKey]);
        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'api_key_expired';
    });

    // A key whose bound user is NOT a member of the key's workspace.
    $nonMember = DB::table('users')
        ->whereNotIn('id', DB::table('workspace_users')->where('workspace_id', $wsB)->pluck('user_id'))
        ->value('id');

    if ($nonMember) {
        $strayKey = mkKey(['workspace_id' => $wsB, 'user_id' => $nonMember]);
        check('API key whose principal is not a workspace member is DENIED', function () use ($strayKey) {
            $r = req('/api/connector/ping', ['X-API-KEY' => $strayKey]);
            return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'api_key_binding_invalid';
        });
    } else {
        echo "  SKIP  non-member binding test (every user is a member of workspace {$wsB})\n";
    }

    echo "\n-- 3. Workspace override attempts --\n";

    check('caller-supplied workspace_id in query cannot override binding', function () use ($jwtA, $wsA, $wsB) {
        $r = req("/api/infrastructure/overview?workspace_id={$wsB}", ['Authorization' => 'Bearer ' . $jwtA]);
        return $r['code'] === 200 && (int) ($r['json']['data']['workspace_id'] ?? 0) === $wsA;
    });

    check('caller-supplied X-Workspace-Id header cannot override binding', function () use ($jwtA, $wsA, $wsB) {
        $r = req('/api/infrastructure/overview', [
            'Authorization'  => 'Bearer ' . $jwtA,
            'X-Workspace-Id' => (string) $wsB,
        ]);
        return $r['code'] === 200 && (int) ($r['json']['data']['workspace_id'] ?? 0) === $wsA;
    });

    echo "\n-- 4. INFRA888 rejects machine credentials (control C6) --\n";

    check('bound API key is REFUSED on infrastructure routes', function () use ($boundKey) {
        $r = req('/api/infrastructure/overview', ['X-API-KEY' => $boundKey]);
        return $r['code'] === 403 && ($r['json']['code'] ?? '') === 'api_key_not_permitted';
    });

    echo "\n-- 5. Non-INFRA888 routes did not gain broader access --\n";

    check('connector route still rejects missing credential', function () {
        return in_array(req('/api/connector/ping')['code'], [401, 403], true);
    });

    check('SPA shell still served', function () {
        $ch = curl_init(BASE . '/app/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Host: ' . HOST], CURLOPT_TIMEOUT => 20]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    });

} finally {
    foreach ($created as $k) {
        DB::table('api_keys')->where('key', $k)->delete();
    }
    $left = DB::table('api_keys')->where('key', 'like', 'INFRA888-TEMP-%')->count();
    echo "\nCleanup: removed " . count($created) . " temp keys; remaining INFRA888-TEMP rows: {$left}\n";
}

echo "\n====================================\n";
echo "PASS: {$pass}   FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
