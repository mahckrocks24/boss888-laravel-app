<?php
/**
 * W6 — tear down the dedicated QA accounts.
 *
 * Removes every artefact created by scripts/w6-make-qa-accounts.php and nothing
 * else. Pre-existing users, workspaces and customer data are never touched:
 * the only rows deleted are those whose email/slug carries the `w6qa-` tag, plus
 * the membership row that attached the QA user to workspace 2.
 *
 * Prints before/after counts so the restoration is provable.
 *
 * Usage:  php scripts/w6-drop-qa-accounts.php          (dry run - shows targets)
 *         php scripts/w6-drop-qa-accounts.php --commit (performs the teardown)
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$commit = in_array('--commit', $argv, true);

$userIds = DB::table('users')->where('email', 'like', 'w6qa-%')->pluck('id')->all();
$wsIds   = DB::table('workspaces')->where('slug', 'like', 'w6qa-%')->pluck('id')->all();

echo "QA users      : " . (count($userIds) ? implode(',', $userIds) : 'none') . "\n";
echo "QA workspaces : " . (count($wsIds) ? implode(',', $wsIds) : 'none') . "\n";

function counts(): array {
    return [
        'users'           => DB::table('users')->count(),
        'workspaces'      => DB::table('workspaces')->count(),
        'workspace_users' => DB::table('workspace_users')->count(),
        'subscriptions'   => DB::table('subscriptions')->count(),
        'credits'         => Schema::hasTable('credits') ? DB::table('credits')->count() : 0,
        'ws2_members'     => DB::table('workspace_users')->where('workspace_id', 2)->count(),
        'tasks'           => DB::table('tasks')->count(),
        'articles'        => DB::table('articles')->count(),
    ];
}

$before = counts();
echo "\nBEFORE: " . json_encode($before) . "\n";

if (!$commit) {
    echo "\nDRY RUN. Re-run with --commit to perform the teardown.\n";
    exit(0);
}

DB::transaction(function () use ($userIds, $wsIds) {
    // Anything the QA session generated inside the QA workspaces.
    foreach (['tasks','approvals','agent_messages','meeting_messages','meetings',
              'calendar_events','articles','leads','notifications','audit_logs',
              'credit_transactions','api_keys'] as $t) {
        if (Schema::hasTable($t) && Schema::hasColumn($t, 'workspace_id') && $wsIds) {
            DB::table($t)->whereIn('workspace_id', $wsIds)->delete();
        }
    }

    if ($wsIds) {
        DB::table('subscriptions')->whereIn('workspace_id', $wsIds)->delete();
        if (Schema::hasTable('credits')) DB::table('credits')->whereIn('workspace_id', $wsIds)->delete();
        DB::table('workspace_users')->whereIn('workspace_id', $wsIds)->delete();
        DB::table('workspaces')->whereIn('id', $wsIds)->delete();
    }

    if ($userIds) {
        // Detach the QA member from workspace 2 (and anywhere else it was added).
        DB::table('workspace_users')->whereIn('user_id', $userIds)->delete();
        foreach (['refresh_tokens','personal_access_tokens','sessions'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'user_id')) {
                DB::table($t)->whereIn('user_id', $userIds)->delete();
            }
        }
        DB::table('users')->whereIn('id', $userIds)->delete();
    }
});

$after = counts();
echo "AFTER : " . json_encode($after) . "\n\n";

$delta = [];
foreach ($before as $k => $v) if ($after[$k] !== $v) $delta[$k] = $v . ' -> ' . $after[$k];
echo "DELTA : " . ($delta ? json_encode($delta) : 'none') . "\n";

$leftUsers = DB::table('users')->where('email', 'like', 'w6qa-%')->count();
$leftWs    = DB::table('workspaces')->where('slug', 'like', 'w6qa-%')->count();
$ws2       = DB::table('workspace_users')->where('workspace_id', 2)->count();

echo "\nQA users remaining      : $leftUsers\n";
echo "QA workspaces remaining : $leftWs\n";
echo "workspace 2 memberships : $ws2 (expected 1 - the original owner)\n";
echo ($leftUsers === 0 && $leftWs === 0) ? "\nTEARDOWN COMPLETE\n" : "\nTEARDOWN INCOMPLETE\n";

@unlink('/root/.w6qa-credentials');
echo "credentials file removed\n";
