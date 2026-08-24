<?php
/**
 * SARAH888 — APPROVAL COUNT SEMANTICS (permanent regression).
 *
 * T004: "roughly 127 tasks pending approval".  T005: "roughly 38".
 * Both unverifiable, because the record answers "what is waiting on me?" four
 * different ways and nothing said which was meant:
 *
 *   tasks.status = 'pending'                        133
 *   tasks.status = 'awaiting_approval'                0
 *   approvals.status = 'pending'                    127
 *   strategy_proposals.status = 'pending_approval'   45
 *
 * The measured truth in ws 2: all 133 pending tasks carry requires_approval = 1,
 * so 'awaiting_approval' being 0 was the misleading fact - approval-gated work is
 * parked in 'pending'. Counting by MEANING, not by status string, is the rule
 * under test.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\ExecutiveFacts;

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$W = 999876;
DB::table('workspaces')->updateOrInsert(['id' => $W], ['name' => 'p23', 'slug' => 'p23',
    'timezone' => 'UTC', 'created_by' => 2, 'created_at' => now(), 'updated_at' => now()]);
DB::table('tasks')->where('workspace_id', $W)->delete();
DB::table('strategy_proposals')->where('workspace_id', $W)->delete();

$task = function (string $status, int $requiresApproval) use ($W) {
    DB::table('tasks')->insert(['workspace_id' => $W, 'engine' => 'seo', 'action' => 'deep_audit',
        'category' => 'research', 'status' => $status, 'requires_approval' => $requiresApproval,
        'payload_json' => '{}', 'created_at' => now(), 'updated_at' => now()]);
};

// 5 approval-gated + 3 freely runnable + 1 explicitly awaiting_approval.
for ($i = 0; $i < 5; $i++) $task('pending', 1);
for ($i = 0; $i < 3; $i++) $task('pending', 0);
$task('awaiting_approval', 1);

DB::table('strategy_proposals')->insert([
    ['workspace_id' => $W, 'type' => 'daily_action_publish', 'status' => 'pending_approval',
     'title' => 'p', 'description' => 'p', 'cost_breakdown_json' => '[]', 'total_credits' => 0,
     'created_at' => now(), 'updated_at' => now()],
    ['workspace_id' => $W, 'type' => 'chat_action', 'status' => 'pending_approval',
     'title' => 'c', 'description' => 'c', 'cost_breakdown_json' => '[]', 'total_credits' => 0,
     'created_at' => now(), 'updated_at' => now()],
]);

$facts = app(ExecutiveFacts::class)->all($W);
$by = [];
foreach ($facts as $f) $by[$f['name'] . '|' . $f['period']] = $f['value'];

echo "-- each quantity has its own name --\n";
ok('tasks_awaiting_owner_approval counts BOTH gated shapes',
   ($by['tasks_awaiting_owner_approval|now'] ?? null) === 6,
   json_encode($by['tasks_awaiting_owner_approval|now'] ?? null));
ok('tasks_runnable_no_approval counts only ungated work',
   ($by['tasks_runnable_no_approval|now'] ?? null) === 3,
   json_encode($by['tasks_runnable_no_approval|now'] ?? null));
ok('proposals_pending_decision counts all open proposals',
   ($by['proposals_pending_decision|now'] ?? null) === 2,
   json_encode($by['proposals_pending_decision|now'] ?? null));
ok('chat_offers_live counts only THIS conversation kind',
   ($by['chat_offers_live|now'] ?? null) === 1,
   json_encode($by['chat_offers_live|now'] ?? null));

echo "-- the three are genuinely different numbers --\n";
ok('awaiting-owner != runnable',
   ($by['tasks_awaiting_owner_approval|now'] ?? 0) !== ($by['tasks_runnable_no_approval|now'] ?? 0));
ok('awaiting-owner != proposals',
   ($by['tasks_awaiting_owner_approval|now'] ?? 0) !== ($by['proposals_pending_decision|now'] ?? 0));
ok('proposals != chat offers',
   ($by['proposals_pending_decision|now'] ?? 0) !== ($by['chat_offers_live|now'] ?? 0));

echo "-- status-based counting alone would have been misleading --\n";
ok('status awaiting_approval sees only 1 of the 6',
   ($by['tasks_awaiting_approval|all_time'] ?? null) === 1,
   json_encode($by['tasks_awaiting_approval|all_time'] ?? null));
ok('tasks_pending still reports the raw 8',
   ($by['tasks_pending|all_time'] ?? null) === 8,
   json_encode($by['tasks_pending|all_time'] ?? null));

echo "-- Sarah is TOLD they are different --\n";
$r = app(ExecutiveFacts::class)->render($W);
ok('render warns that pending != pending approval',
   str_contains($r, "'Pending' and 'pending approval' are DIFFERENT"), '');
ok('render names all four canonical facts',
   str_contains($r, 'tasks_awaiting_owner_approval')
   && str_contains($r, 'tasks_runnable_no_approval')
   && str_contains($r, 'proposals_pending_decision')
   && str_contains($r, 'chat_offers_live'));

echo "-- ws 2, the workspace the defect was measured in --\n";
$live = [];
foreach (app(ExecutiveFacts::class)->all(2) as $f) $live[$f['name'] . '|' . $f['period']] = $f['value'];
ok('ws2 awaiting-owner is stated', isset($live['tasks_awaiting_owner_approval|now']));
ok('ws2 awaiting-owner + runnable == tasks_pending + status-awaiting',
   ($live['tasks_awaiting_owner_approval|now'] + $live['tasks_runnable_no_approval|now'])
   === ($live['tasks_pending|all_time'] + $live['tasks_awaiting_approval|all_time']),
   json_encode($live['tasks_awaiting_owner_approval|now'] ?? null) . ' + '
   . json_encode($live['tasks_runnable_no_approval|now'] ?? null));

DB::table('tasks')->where('workspace_id', $W)->delete();
DB::table('strategy_proposals')->where('workspace_id', $W)->delete();
DB::table('workspaces')->where('id', $W)->delete();

echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
