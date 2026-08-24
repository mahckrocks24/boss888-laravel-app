<?php
/**
 * SARAH888 Slice 1R.2 — CERT-A-D02.
 *
 * An uncommissioned turn must not create EXECUTABLE work. The whole point is
 * that this is decided BEFORE the row exists, so a fast task cannot complete
 * before a reversal reaches it.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\SpendPolicy;
use App\Core\Sarah888\SpendContext;
use App\Core\Sarah888\CorrelationContext;
use App\Core\TaskSystem\TaskService;

const WS = 999910;
$pass = 0; $fail = 0;
function ok(string $l, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($d ? "  :: $d" : '') . "\n"; }
}

DB::table('tasks')->where('workspace_id', WS)->delete();
DB::table('workspaces')->where('id', WS)->delete();
DB::table('workspaces')->insert([
    'id' => WS, 'name' => 'SARAH888 scratch (1R.2)', 'slug' => 'sarah888-scratch-1r2',
    'created_by' => DB::table('workspaces')->where('id', 2)->value('created_by'),
    'created_at' => now(), 'updated_at' => now(),
]);
$svc = app(TaskService::class);
app(CorrelationContext::class)->set(['conversation_id' => 'ws' . WS . ':sarah', 'execution_id' => 'exec-1r2'], WS);

$setTurn = function (string $userText) {
    $ctx = new SpendContext();
    $ctx->setTurn(app(SpendPolicy::class)->assessTurn($userText), WS);
    return $ctx;
};
/**
 * Mirrors what the chat route actually sends.
 *
 * agents-01.php passes requires_approval => false for non-destructive work,
 * which turns the 'review' category default into no-approval — that is exactly
 * why Pass A's 50 create_lead tasks executed. A test that omits it exercises a
 * path the product never takes and proves nothing.
 */
$make = function (string $action) use ($svc) {
    try {
        return $svc->create(WS, ['action' => $action, 'source' => 'agent',
            'requires_approval' => false,
            'payload' => ['title' => 'probe ' . $action . ' ' . uniqid(), 'created_via' => 'sarah_chat']]);
    } catch (\Throwable $e) { return $e; }
};

echo "\n──── THE PASS A FICTION TURNS ────\n";
foreach ([
    'How did Thursday distributor summit go?',
    'Where has the Reykjavik export deal got to?',
    'What did the retail panel say about the winter range?',
    'What is blocking the trade portal from starting?',
    'What is the ceiling on workshop places?',
] as $q) {
    $setTurn($q);
    // CONTRACT MIGRATED 2026-08-08.
    // Sarah Task Safety changed from execution-gated approval to
    // creation-gated authorization. READ_ONLY and unapproved ASK_FIRST turns
    // must not create task rows.
    //
    // ORIGINALLY this asserted the task WAS created with requires_approval=1
    // and approval_status='pending'. That was CERT-A-D02's remedy: Pass A had
    // executed 18 create_lead tasks against invented premises before any
    // reversal could reach them, so the row was held instead of run.
    //
    // Holding proved insufficient. Measured live: 111 of 139 replies that asked
    // "shall I proceed?" had already created tasks in the same execution_id,
    // from prompts that were questions. A held row still carries an idempotency
    // key, a credit cost and a queue position, still appears in counts and in
    // the approval queue, and still means the owner's workspace changed because
    // they asked a question. requires_approval only decides whether the row
    // RUNS — that check lives in markRunning(), far downstream.
    //
    // The intent of this test is unchanged and is stated in its own header:
    // the decision must happen BEFORE the row exists. That is now literally
    // true, so the assertion moves from "held" to "never created".
    $before = DB::table('tasks')->where('workspace_id', WS)->count();
    $t = $make('create_lead');
    $refused = $t instanceof \App\Core\TaskSystem\Exceptions\TaskCreationNotAuthorized
               || (is_string($t) && str_contains($t, 'UNCOMMISSIONED_TURN'));
    ok('creates NO work: "' . mb_substr($q, 0, 44) . '"',
        $refused, 'got: ' . (is_object($t) ? get_class($t) : (string) $t));
    ok('  …and zero task rows exist for it',
        DB::table('tasks')->where('workspace_id', WS)->count() === $before,
        'row count changed');
}

echo "\n──── COMMISSIONED WORK STILL EXECUTES ────\n";
foreach ([
    ['Draft a short article about the winter range.', 'directive'],
    ['Queue a task to follow up the trade leads.',    'directive'],
    ['Do it. Go ahead and run it.',                   'authorisation'],
] as [$q, $expect]) {
    $cls = app(SpendPolicy::class)->assessTurn($q)['classification'];
    $setTurn($q);
    $t = $make('create_lead');
    $isTask = $t instanceof \App\Models\Task;
    ok('executable: "' . mb_substr($q, 0, 40) . '" (' . $cls . ')',
        $isTask && !$t->requires_approval, $isTask ? ('requires_approval=' . (int) $t->requires_approval) : (string) $t);
}

echo "\n──── RESEARCH IS NO LONGER EXEMPT ────\n";
// CONTRACT MIGRATED 2026-08-09.
// The exemption existed so Sarah could look something up in order to answer
// the question she was asked, and it was right when written. The
// execution-attributed battery then lost 8 of 30 read-only scenarios to free
// research lookups spawned from questions like "what is our earliest
// deadline?" — a question was creating work.
//
// The capability that justified the exception has been replaced by a better
// one. DerivedState, TemporalAnchor and CognitiveFrame answer counts,
// deadlines, overdue work and ownership deterministically, and they are in the
// frame before the model reads the turn — proven live at 9/10 on exactly these
// questions. "How many leads are qualified?" is answerable without spawning
// anything, so it should spawn nothing.
//
// READ_ONLY means zero task rows. No category is exempt from that.
$setTurn('How many leads are qualified?');
$t = $make('list_leads');
$refused = $t instanceof \App\Core\TaskSystem\Exceptions\TaskCreationNotAuthorized
           || (is_string($t) && str_contains($t, 'UNCOMMISSIONED_TURN'));
ok('a research action creates NO work on a question turn', $refused,
   'got: ' . (is_object($t) ? get_class($t) : (string) $t));

echo "\n──── THE GATE NEVER RELAXES AN EXISTING REQUIREMENT ────\n";
$setTurn('Draft a short article about the winter range.');
// CONTRACT MIGRATED 2026-08-09.
// Sarah Task Safety changed from execution-gated approval to creation-gated
// authorization. READ_ONLY and unapproved ASK_FIRST turns must not create
// task rows.
//
// ORIGINALLY: publish_article was CREATED with requires_approval=1, and the
// assertion checked that flag. The intent was that a directive turn must not
// relax an existing protection — the gate can tighten, never loosen.
//
// That intent is now satisfied more strongly. Publishing is consequential
// (its output leaves the workspace), so it is PROPOSED rather than created:
// there is no row, no queue entry and no cost until the owner approves. A
// protection that prevents the row existing is strictly stronger than one
// that flags a row which already does.
$t = $make('publish_article');
$proposed = $t instanceof \App\Core\TaskSystem\Exceptions\TaskCreationNotAuthorized
            || (is_string($t) && str_contains($t, 'ASK_FIRST_PROPOSED'));
ok('publish is PROPOSED, not created, on a directive turn', $proposed,
    'got: ' . (is_object($t) ? get_class($t) : (string) $t));

echo "\n──── BACKGROUND WORK IS NOT IN SCOPE FOR THIS GATE ────\n";
// An unset context means there was no chat turn at all — a queue worker, the
// daily cycle, fill_missing_images. That is NOT the same as a chat turn that
// commissioned nothing, and holding it would put every background job on the
// platform into the approval queue. Background spend is governed by the Phase
// 1E cost gate, which DOES fail closed on unset context for paid work; this
// gate is about conversational intent and correctly ignores non-conversational
// callers.
$fresh = new SpendContext();
app()->instance(SpendContext::class, $fresh);
$t = $make('create_lead');
ok('an unset context is left to the cost gate, not held here',
    $t instanceof \App\Models\Task && !$t->requires_approval,
    $t instanceof \App\Models\Task ? ('requires_approval=' . (int) $t->requires_approval) : (string) $t);
ok('  …and the classification really is "none"',
    (app(SpendContext::class)->turn()['classification'] ?? '') === 'none',
    json_encode(app(SpendContext::class)->turn()));

DB::table('tasks')->where('workspace_id', WS)->delete();
DB::table('workspaces')->where('id', WS)->delete();
ok('scratch removed', DB::table('workspaces')->where('id', WS)->count() === 0);

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
