<?php
/**
 * SARAH888 Phase 1M — correlation stamping (S1J-N01).
 *
 * The binding assertion is the important one. An unbound concrete class
 * resolves to a NEW instance on every app() call, so a context that looks
 * correctly set can still be invisible to the code that reads it — that exact
 * mistake made the Phase 1E cost gate silently inert, and the same shape made
 * the Phase 1J guard no-op on its first live run.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\CorrelationContext;
use App\Core\Sarah888\ActionLedger;
use App\Core\TaskSystem\TaskService;

const WS = 999903;
$pass = 0; $fail = 0; $skip = 0;
function ok(string $l, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($d ? "  :: $d" : '') . "\n"; }
}
function skip(string $l, string $why): void { global $skip; $skip++; echo "  SKIP  $l  :: $why\n"; }

// tasks.workspace_id carries a foreign key to workspaces. The first run of this
// suite used a fictitious id, every create() died on the constraint, and the two
// integration assertions SKIPPED — which read as "no free action available" and
// quietly left the central claim of this slice unproven. A real scratch
// workspace is provisioned and destroyed here; the FK cascades, so removing it
// removes anything created under it.
DB::table('tasks')->where('workspace_id', WS)->delete();
DB::table('workspaces')->where('id', WS)->delete();
DB::table('workspaces')->insert([
    'id' => WS, 'name' => 'SARAH888 scratch (1M)', 'slug' => 'sarah888-scratch-1m',
    'created_by' => DB::table('workspaces')->where('id', 2)->value('created_by'),
    'created_at' => now(), 'updated_at' => now(),
]);

echo "\n──── THE CONTEXT ITSELF ────\n";
$c = new CorrelationContext();
ok('unset context reports isSet() false', !$c->isSet());
ok('  …and stamps nothing', $c->payloadStamp() === [], json_encode($c->payloadStamp()));

$corr = ['conversation_id' => 'ws' . WS . ':sarah', 'user_message_id' => 4242,
         'execution_id' => 'exec-1m-aaaa', 'correlation_id' => 'exec-1m-aaaa'];
$c->set($corr, WS);
ok('set() reports isSet() true', $c->isSet());
$s = $c->payloadStamp();
ok('  …stamps execution_id', ($s['execution_id'] ?? null) === 'exec-1m-aaaa', json_encode($s));
ok('  …stamps conversation_id', ($s['conversation_id'] ?? null) === 'ws' . WS . ':sarah', json_encode($s));
ok('  …stamps source_message_id', ($s['source_message_id'] ?? null) === 4242, json_encode($s));
ok('workspaceId is retained', $c->workspaceId() === WS);

echo "\n──── THE BINDING (the 1E.2 mistake) ────\n";
$resolved = app(CorrelationContext::class);
ok('app() returns the SAME instance after set()', $resolved === $c);
ok('  …and it carries the envelope', $resolved->executionId() === 'exec-1m-aaaa', (string) $resolved->executionId());

$c->clear();
ok('clear() empties the envelope', !$c->isSet() && $c->payloadStamp() === []);

echo "\n──── INTEGRATION: TaskService stamps what it creates ────\n";
$svc = app(TaskService::class);
$ctx = app(CorrelationContext::class);
$ctx->set(['conversation_id' => 'ws' . WS . ':sarah', 'user_message_id' => 77,
           'execution_id' => 'exec-1m-bbbb'], WS);

// create_lead and update_lead both resolve in the capability map at credit
// cost 0, so neither trips the spend gate. Every attempt's error is retained —
// keeping only the last one is what disguised the foreign-key failure as an
// unmapped-action failure on the first run.
$made = null; $usedAction = null; $errs = [];
foreach (['create_lead', 'update_lead', 'list_leads'] as $action) {
    try {
        $made = $svc->create(WS, ['action' => $action, 'source' => 'agent',
            'payload' => ['title' => 'Phase 1M correlation probe', 'created_via' => 'sarah_chat']]);
        $usedAction = $action;
        break;
    } catch (\Throwable $e) { $errs[] = $action . ': ' . mb_substr($e->getMessage(), 0, 120); }
}
$lastErr = implode(' | ', $errs);
ok('the integration path actually ran (not skipped)', $made !== null, $lastErr);

if (!$made) {
    skip('TaskService stamps execution_id', 'no free mapped action available — ' . $lastErr);
    skip('forConversation finds the stamped task', 'depends on the above');
} else {
    $row = DB::table('tasks')->where('id', $made->id)->first();
    $p = is_string($row->payload_json) ? json_decode($row->payload_json, true) : (array) $row->payload_json;
    ok("a task created via $usedAction carries execution_id",
        ($p['execution_id'] ?? null) === 'exec-1m-bbbb', json_encode($p));
    ok('  …and conversation_id', ($p['conversation_id'] ?? null) === 'ws' . WS . ':sarah', json_encode($p));
    ok('  …without losing created_via', ($p['created_via'] ?? null) === 'sarah_chat', json_encode($p));
    ok('  …or the title', ($p['title'] ?? null) === 'Phase 1M correlation probe', json_encode($p));

    // A caller that sets its own value must win over the context.
    $ctx->set(['conversation_id' => 'ws' . WS . ':sarah', 'execution_id' => 'exec-1m-cccc'], WS);
    $made2 = null;
    try {
        $made2 = $svc->create(WS, ['action' => $usedAction, 'source' => 'agent',
            'payload' => ['title' => 'Phase 1M explicit override', 'created_via' => 'sarah_chat',
                          'execution_id' => 'caller-wins']]);
    } catch (\Throwable $e) { $lastErr = $e->getMessage(); }
    if ($made2) {
        $r2 = DB::table('tasks')->where('id', $made2->id)->first();
        $p2 = is_string($r2->payload_json) ? json_decode($r2->payload_json, true) : (array) $r2->payload_json;
        ok('an explicit caller value is not overwritten',
            ($p2['execution_id'] ?? null) === 'caller-wins', json_encode($p2));
    } else {
        skip('an explicit caller value is not overwritten', $lastErr);
    }

    echo "\n──── THE LEDGER CAN NOW ATTRIBUTE THE WORK ────\n";
    DB::table('agent_messages')->insert([
        'workspace_id' => WS, 'agent_slug' => 'sarah', 'sender' => 'Sarah',
        'content' => 'Phase 1M probe', 'role' => 'agent',
        'metadata_json' => json_encode(['conversation_id' => 'ws' . WS . ':sarah',
                                        'execution_id' => 'exec-1m-bbbb', 'phase' => 'final']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $l = app(ActionLedger::class)->forConversation(WS, 'ws' . WS . ':sarah');
    ok('forConversation now finds the task', ($l['totals']['all'] ?? 0) >= 1, json_encode($l['totals']));
    $unknown = app(ActionLedger::class)->forConversation(WS, 'ws' . WS . ':nope');
    ok('  …and still reports zero for another conversation',
        ($unknown['totals']['all'] ?? 0) === 0, json_encode($unknown['totals']));
    DB::table('agent_messages')->where('workspace_id', WS)->delete();
}

echo "\n──── AN UNSET CONTEXT CHANGES NOTHING (workers, schedulers) ────\n";
app(CorrelationContext::class)->clear();
if ($usedAction) {
    $made3 = null;
    try {
        $made3 = $svc->create(WS, ['action' => $usedAction, 'source' => 'agent',
            'payload' => ['title' => 'Phase 1M unset probe', 'created_via' => 'daily_cycle']]);
    } catch (\Throwable $e) { $lastErr = $e->getMessage(); }
    if ($made3) {
        $r3 = DB::table('tasks')->where('id', $made3->id)->first();
        $p3 = is_string($r3->payload_json) ? json_decode($r3->payload_json, true) : (array) $r3->payload_json;
        ok('no context means no correlation keys are invented',
            !array_key_exists('execution_id', $p3), json_encode($p3));
    } else { skip('no context means no correlation keys are invented', $lastErr); }
}

$left = DB::table('tasks')->where('workspace_id', WS)->count();
DB::table('tasks')->where('workspace_id', WS)->delete();
DB::table('agent_messages')->where('workspace_id', WS)->delete();
DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
DB::table('workspaces')->where('id', WS)->delete();
ok('scratch workspace and all its rows removed',
    DB::table('tasks')->where('workspace_id', WS)->count() === 0
    && DB::table('workspaces')->where('id', WS)->count() === 0, "removed $left tasks");

echo "\n  $pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail > 0 ? 1 : 0);
