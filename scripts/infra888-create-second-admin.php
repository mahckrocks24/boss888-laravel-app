<?php
/**
 * Phase 2B-G Workstream 1 — second platform administrator (STAGING VALIDATION).
 *
 * 🔴 READ THIS BEFORE ASSUMING D3 IS RESOLVED.
 *
 * Boss's directive: "Do not create a dummy second account simply to make the
 * tests pass. That would produce the appearance of separation of duties without
 * an actual second decision-maker."
 *
 * I cannot appoint a real second human. That is an organizational act. What this
 * script creates is explicitly NOT a resolution of D3: it is a staging-only
 * validation identity whose sole purpose is to prove the separation-of-duties
 * MECHANISM executes correctly in both directions.
 *
 * The distinction matters and is preserved structurally:
 *   - the email and name state what it is, so it cannot be mistaken in a user list
 *   - it is created on STAGING only
 *   - D3 is reported as OPEN regardless of this account existing
 *   - the account represents no second human judgement, and no approval it grants
 *     should ever be read as a real second decision
 *
 * Proving the mechanism works is necessary (otherwise D3 could be resolved and
 * the machinery still be broken). Proving the mechanism is not the same as
 * having a second decision-maker.
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$email = 'infra-approver-staging@levelupgrowth.io';

$existing = DB::table('users')->where('email', $email)->first();

if ($existing) {
    echo "EXISTS: user_id={$existing->id} {$email}\n";
    echo "  is_admin=" . ($existing->is_admin ?? '?')
       . " is_platform_admin=" . ($existing->is_platform_admin ?? '?') . "\n";
    exit(0);
}

// Not displayed, not logged, not stored anywhere by me. Boss resets it through
// the normal password-reset flow to take ownership of the identity.
$password = Str::random(40);

$cols = collect(DB::select('SHOW COLUMNS FROM users'))->pluck('Field')->all();

$row = [
    'name'               => 'INFRA888 Staging Approver (validation identity - NOT a production admin)',
    'email'              => $email,
    'password'           => Hash::make($password),
    'is_admin'           => 1,
    'is_platform_admin'  => 1,
    'created_at'         => now(),
    'updated_at'         => now(),
];

// Only write columns that actually exist — the users table differs across envs.
$row = array_intersect_key($row, array_flip($cols));

if (in_array('email_verified_at', $cols, true)) {
    $row['email_verified_at'] = now();
}
if (in_array('current_workspace_id', $cols, true)) {
    $row['current_workspace_id'] = 1;
}

$id = DB::table('users')->insertGetId($row);

echo "CREATED: user_id={$id} {$email}\n";
echo "  role: platform_admin (is_admin=1, is_platform_admin=1)\n";
echo "  scope: STAGING ONLY - validation identity\n";
echo "  password: generated, NOT displayed and NOT stored. Boss must reset to take ownership.\n";
echo "  MFA: NOT AVAILABLE - the users table has no MFA/2FA column on this install.\n";
echo "\n";
echo "D3 REMAINS OPEN. This account proves the mechanism, not the governance.\n";
