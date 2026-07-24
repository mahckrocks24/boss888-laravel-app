<?php
/**
 * W6 — create/refresh the dedicated STAGING QA accounts for authenticated
 * browser verification (spec §13). Modelled on infra888-make-test-account.php.
 *
 * No real customer account is touched and no existing password is read or
 * changed. Generated passwords are written to /root/.w6qa-credentials (0600)
 * and are never printed to stdout.
 *
 * Contexts provisioned:
 *   w6qa-agency   NEW workspace, agency plan (highest), onboarded
 *   w6qa-trial    NEW workspace, starter plan, is_trial, NOT onboarded
 *   w6qa-existing attached to EXISTING workspace 2 (designated Laravel test tenant)
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

$out = [];

function ensureUser(string $email, string $name, string $password): int
{
    $u = DB::table('users')->where('email', $email)->first();
    if ($u) {
        DB::table('users')->where('id', $u->id)
            ->update(['password' => Hash::make($password), 'updated_at' => now()]);
        return $u->id;
    }
    return DB::table('users')->insertGetId([
        'name' => $name, 'email' => $email,
        'password' => Hash::make($password),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function ensureMembership(int $wsId, int $userId): void
{
    if (!DB::table('workspace_users')->where('workspace_id', $wsId)->where('user_id', $userId)->exists()) {
        DB::table('workspace_users')->insert([
            'workspace_id' => $wsId, 'user_id' => $userId, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function ensureWorkspace(string $slug, string $name, int $userId): int
{
    $ws = DB::table('workspaces')->where('slug', $slug)->first();
    if ($ws) { return $ws->id; }
    return DB::table('workspaces')->insertGetId([
        'name' => $name, 'slug' => $slug, 'created_by' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function setPlan(int $wsId, string $planSlug): void
{
    $planId = DB::table('plans')->where('slug', $planSlug)->value('id');
    if (DB::table('subscriptions')->where('workspace_id', $wsId)->exists()) {
        DB::table('subscriptions')->where('workspace_id', $wsId)
            ->update(['plan_id' => $planId, 'status' => 'active', 'updated_at' => now()]);
    } else {
        DB::table('subscriptions')->insert([
            'workspace_id' => $wsId, 'plan_id' => $planId, 'status' => 'active',
            'starts_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function ensureCredits(int $wsId): void
{
    if (Schema::hasTable('credits') && !DB::table('credits')->where('workspace_id', $wsId)->exists()) {
        DB::table('credits')->insert([
            'workspace_id' => $wsId, 'balance' => 100, 'reserved_balance' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

DB::transaction(function () use (&$out) {

    // ---- 1. AGENCY (highest plan), NEW workspace, onboarded --------------
    $pw = 'W6q!' . bin2hex(random_bytes(9));
    $uid = ensureUser('w6qa-agency@levelupgrowth.io', 'W6 QA Agency Owner', $pw);
    $ws  = ensureWorkspace('w6qa-agency', 'W6 QA Agency Workspace', $uid);
    ensureMembership($ws, $uid);
    setPlan($ws, 'agency');
    DB::table('workspaces')->where('id', $ws)->update([
        'onboarded' => 1, 'onboarded_at' => now(), 'updated_at' => now(),
    ]);
    ensureCredits($ws);
    $out[] = ['label' => 'agency', 'email' => 'w6qa-agency@levelupgrowth.io',
              'password' => $pw, 'user_id' => $uid, 'workspace_id' => $ws, 'plan' => 'agency'];

    // ---- 2. TRIAL, NEW workspace, NOT onboarded (fresh onboarding flow) --
    $pw = 'W6q!' . bin2hex(random_bytes(9));
    $uid = ensureUser('w6qa-trial@levelupgrowth.io', 'W6 QA Trial Owner', $pw);
    $ws  = ensureWorkspace('w6qa-trial', 'W6 QA Trial Workspace', $uid);
    ensureMembership($ws, $uid);
    setPlan($ws, 'starter');
    DB::table('workspaces')->where('id', $ws)->update([
        'onboarded' => 0, 'onboarded_at' => null,
        'is_trial' => 1, 'trial_started_at' => now(),
        'trial_expires_at' => now()->addDays(3),
        'updated_at' => now(),
    ]);
    ensureCredits($ws);
    $out[] = ['label' => 'trial', 'email' => 'w6qa-trial@levelupgrowth.io',
              'password' => $pw, 'user_id' => $uid, 'workspace_id' => $ws, 'plan' => 'starter (trial)'];

    // ---- 3. EXISTING workspace 2 (designated Laravel test tenant) --------
    $pw = 'W6q!' . bin2hex(random_bytes(9));
    $uid = ensureUser('w6qa-existing@levelupgrowth.io', 'W6 QA Existing Member', $pw);
    ensureMembership(2, $uid);
    $out[] = ['label' => 'existing', 'email' => 'w6qa-existing@levelupgrowth.io',
              'password' => $pw, 'user_id' => $uid, 'workspace_id' => 2, 'plan' => '(inherits ws2)'];
});

$lines = "# W6 QA credentials — staging only. Generated " . date('c') . "\n";
$lines .= "# Delete with: php scripts/w6-drop-qa-accounts.php\n";
foreach ($out as $r) {
    $lines .= sprintf("%-9s %s  %s  user_id=%d workspace_id=%d plan=%s\n",
        $r['label'], $r['email'], $r['password'], $r['user_id'], $r['workspace_id'], $r['plan']);
}
file_put_contents('/root/.w6qa-credentials', $lines);
chmod('/root/.w6qa-credentials', 0600);

foreach ($out as $r) {
    echo sprintf("%-9s %-34s user_id=%-7d workspace_id=%-7d plan=%s\n",
        $r['label'], $r['email'], $r['user_id'], $r['workspace_id'], $r['plan']);
}
echo "credentials written to /root/.w6qa-credentials (0600)\n";
