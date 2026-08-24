<?php
/**
 * SARAH888 — RUNTIME-NATIVE SHADOW PATH (permanent regression).
 *
 * Proves the boundary invariants without spending inference:
 *   - shadow never mutates
 *   - conversation memory is isolated by conversation_id, not just workspace
 *   - structured context is compact and carries provenance
 *   - simulated statuses can never claim execution
 *
 * Set SHADOW_LIVE=1 to additionally run real model + vendor turns.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\{ShadowTurn, ToolIntent, ToolIntentGateway, ToolResult};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$st  = app(ShadowTurn::class);
$gw  = app(ToolIntentGateway::class);
$WS  = 2;
$WS2 = 990100;

echo "-- shadow NEVER mutates, whatever governance would have decided --\n";
$before = [
    'tasks'     => DB::table('tasks')->where('workspace_id', $WS)->count(),
    'proposals' => DB::table('strategy_proposals')->where('workspace_id', $WS)->count(),
    'approvals' => DB::table('approvals')->where('workspace_id', $WS)->count(),
    'leads'     => DB::table('leads')->where('workspace_id', $WS)->count(),
];
foreach ([['create_lead', ['name' => 'Shadow Probe']],
          ['publish_builder_page', ['page_id' => 999999]],
          ['generate_image', ['prompt' => 'a plate of food']]] as [$cap, $params]) {
    $r = $gw->handle(new ToolIntent($cap, $WS, $params), true);
    ok("{$cap} is simulated, not executed", $r->simulated() === true, $r->status);
    ok("  {$cap} executed() false", $r->executed() === false);
    ok("  {$cap} may_claim_done false", $r->toRuntime()['may_claim_done'] === false);
    ok("  {$cap} status is a WOULD_*", str_starts_with($r->status, 'WOULD_'), $r->status);
}
$after = [
    'tasks'     => DB::table('tasks')->where('workspace_id', $WS)->count(),
    'proposals' => DB::table('strategy_proposals')->where('workspace_id', $WS)->count(),
    'approvals' => DB::table('approvals')->where('workspace_id', $WS)->count(),
    'leads'     => DB::table('leads')->where('workspace_id', $WS)->count(),
];
ok('no task/proposal/approval/lead row was created', $before === $after,
   json_encode(['before' => $before, 'after' => $after]));

echo "-- a mutation that governance would REFUSE still simulates, never runs --\n";
$r = $gw->handle(new ToolIntent('create_lead', $WS, []), true);   // missing required param
ok('missing params -> WOULD_BE_REFUSED', $r->status === ToolResult::WOULD_BE_REFUSED, $r->status);

echo "-- publish_article: governance answers, and it is NOT 'unavailable' --\n";
// 2026-08-14. Sarah: "I can't publish article drafts from here — there's no publish
// action for articles", while ws 2 held 45 completed and 8 pending publish_article
// tasks. It is a mutation, so the gateway settles it at governance before an executor
// is ever needed; the honest answer is "needs your approval", never "I can't".
$pubBefore = DB::table('tasks')->where('workspace_id', $WS)
    ->where('action', 'publish_article')->count();

$r = $gw->handle(new ToolIntent('publish_article', $WS, ['article_id' => 999999]), true);
ok('publish_article -> WOULD_REQUIRE_APPROVAL',
   $r->status === ToolResult::WOULD_REQUIRE_APPROVAL, $r->status);
ok('  it is NOT reported unavailable',
   $r->status !== ToolResult::WOULD_BE_UNAVAILABLE, $r->status);
ok('  nothing executed', $r->executed() === false && $r->simulated() === true);
ok('  may_claim_done false', $r->toRuntime()['may_claim_done'] === false);

$r = $gw->handle(new ToolIntent('publish_article', $WS, []), true);
ok('publish_article without article_id -> WOULD_BE_REFUSED',
   $r->status === ToolResult::WOULD_BE_REFUSED, $r->status);

ok('no publish_article task was created',
   DB::table('tasks')->where('workspace_id', $WS)
       ->where('action', 'publish_article')->count() === $pubBefore);

echo "-- shadow reads DO execute: the point is real evidence --\n";
$r = $gw->handle(new ToolIntent('gsc_performance', $WS, ['days' => 30]), true);
ok('read executes in shadow', $r->status === ToolResult::SUCCEEDED, $r->status);
ok('  and is NOT marked simulated', $r->simulated() === false);
ok('  carries real provenance', str_contains((string) $r->provenance, 'gsc_metrics'),
   (string) $r->provenance);

echo "-- an unavailable capability simulates honestly in shadow --\n";
$r = $gw->handle(new ToolIntent('gsc_performance', $WS2, []), true);
ok('ws990100 -> WOULD_BE_MISCONFIGURED', $r->status === ToolResult::WOULD_BE_MISCONFIGURED, $r->status);
$r = $gw->handle(new ToolIntent('no_such_thing', $WS, []), true);
ok('unknown -> WOULD_BE_UNAVAILABLE', $r->status === ToolResult::WOULD_BE_UNAVAILABLE, $r->status);

echo "-- every simulated status is barred from claiming execution --\n";
foreach (ToolResult::SIMULATED as $s) {
    $x = new ToolResult($s, 'x');
    ok("{$s} cannot claim done", $x->executed() === false && $x->toRuntime()['may_claim_done'] === false);
    ok("  {$s} reports simulated=true", $x->simulated() === true);
}

echo "-- THE SHADOW MUST BE INVISIBLE TO THE WORKSPACE OWNER --\n";
// 2026-08-14, and it was live. `remember()` wrote shadow turns as agent_slug='sarah', and
// GET /agents/{slug}/messages selects on workspace_id + agent_slug with no conversation
// filter and no shadow exclusion. In ws 2 — Chef Red, a LIVE CUSTOMER — 100 of the 100 rows
// that endpoint returns were shadow test turns ("Push the homepage builder page live",
// "Send the summer campaign email to our list now"), each attributed to the customer.
//
// This asserts the ENDPOINT'S OWN QUERY, not the writer's intention: the rows the customer
// would actually be served must contain no shadow turn.
$st->run($WS, 'shtest-invisible-' . getmypid(), 'How many drafts are waiting?');

$asCustomerWouldSee = DB::table('agent_messages')
    ->where('workspace_id', $WS)->where('agent_slug', 'sarah')   // exactly what the route does
    ->orderByDesc('id')->limit(100)->get(['id', 'metadata_json']);
$leaked = $asCustomerWouldSee->filter(fn ($r) =>
    !empty(json_decode((string) $r->metadata_json, true)['shadow']));
ok('no shadow row appears in what the owner is served', $leaked->isEmpty(),
   $leaked->count() . ' leaked, e.g. #' . ($leaked->first()->id ?? '-'));

ok('shadow turns are written under their own agent slug',
   DB::table('agent_messages')->where('workspace_id', $WS)
     ->where('agent_slug', 'sarah-shadow')->exists());

echo "-- CONVERSATION ISOLATION: memory is per conversation, not per workspace --\n";
$cA = 'shtest-conv-A-' . getmypid();
$cB = 'shtest-conv-B-' . getmypid();
$mk = function (string $conv, string $role, string $text) use ($WS) {
    DB::table('agent_messages')->insert([
        'workspace_id' => $WS, 'agent_slug' => 'sarah',
        'sender' => $role === 'user' ? 'Owner' : 'Sarah',
        'role' => $role, 'content' => $text,
        'metadata_json' => json_encode(['conversation_id' => $conv]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
};
$mk($cA, 'user', 'SECRET-ALPHA: keep captions short.');
$mk($cA, 'agent', 'Understood, short captions.');
$mk($cB, 'user', 'SECRET-BRAVO: publish weekly.');

$ctxA = $st->context($WS, $cA, 'what did I say?');
$ctxB = $st->context($WS, $cB, 'what did I say?');
$jA = json_encode($ctxA['conversation']);
$jB = json_encode($ctxB['conversation']);

ok('conversation A sees its own message', str_contains($jA, 'SECRET-ALPHA'), $jA);
ok('conversation A does NOT see B', !str_contains($jA, 'SECRET-BRAVO'), $jA);
ok('conversation B sees its own message', str_contains($jB, 'SECRET-BRAVO'), $jB);
ok('conversation B does NOT see A', !str_contains($jB, 'SECRET-ALPHA'), $jB);

DB::table('agent_messages')->where('workspace_id', $WS)
    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.conversation_id')) IN (?, ?)", [$cA, $cB])
    ->delete();

echo "-- STRUCTURED CONTEXT is compact and typed --\n";
$ctx = $st->context($WS, 'shtest-ctx', 'how are we doing?');
$bytes = strlen(json_encode($ctx));
ok('context is far smaller than the legacy prose prompt', $bytes < 20000, (string) $bytes);
ok('facts are typed with unit AND period',
   isset($ctx['facts'][0]['name'], $ctx['facts'][0]['value'],
         $ctx['facts'][0]['unit'], $ctx['facts'][0]['period']));
ok('capabilities carry provider and required parameters',
   isset($ctx['capabilities'][0]['capability_id'], $ctx['capabilities'][0]['provider'],
         $ctx['capabilities'][0]['required_parameters']));
// THREE SEPARATE QUEUES, NAMED SO THEY CANNOT BE CONFUSED.
// Measured 2026-08-14 on ws 990100: asked the same question twice minutes apart, the
// shadow said "223 tasks, NO proposals pending" and then "223 tasks plus 24 proposals".
// The 24 was in the context both times. `ExecutiveFacts` offers `tasks_awaiting_approval`
// (9, the status enum) beside `tasks_awaiting_owner_approval` (223, the flag) — names one
// word apart for numbers 214 apart — so the model had to disambiguate on every turn.
// These keys make the distinction structural instead.
// Renamed from `approval_state` 2026-08-14: Runtime emitted that key as a CAPABILITY_ID,
// got TOOL_UNAVAILABLE, and told the owner the capability did not exist — while the answer
// sat inside the block it had misread. Context keys must not read like callable tools.
$as = $ctx['what_is_waiting_on_you'];
ok('no context key reads like a capability id',
   !array_intersect(array_keys($ctx),
       array_column(app(\App\Core\Sarah888\CapabilityManifest::class)->forWorkspace($WS), 'capability_id')),
   implode(',', array_intersect(array_keys($ctx),
       array_column(app(\App\Core\Sarah888\CapabilityManifest::class)->forWorkspace($WS), 'capability_id'))));
ok('approval state names the OWNER-approval queue unambiguously',
   isset($as['tasks_awaiting_YOUR_approval']));
ok('  and the proposal-decision queue separately',
   isset($as['proposals_awaiting_YOUR_decision']));
ok('  and blocked work separately again', isset($as['tasks_blocked_needing_attention']));
ok('  and says explicitly that they must not be merged',
   isset($as['note']) && stripos($as['note'], 'separate') !== false);

// Each must equal its own authoritative query — a disambiguated name carrying the wrong
// number is worse than an ambiguous one, because it looks trustworthy.
ok('tasks_awaiting_YOUR_approval matches the table',
   $as['tasks_awaiting_YOUR_approval'] === DB::table('tasks')->where('workspace_id', $WS)
       ->where('requires_approval', 1)
       ->whereNotIn('status', ['completed', 'failed', 'cancelled'])->count());
ok('proposals_awaiting_YOUR_decision matches the table',
   $as['proposals_awaiting_YOUR_decision'] === DB::table('strategy_proposals')
       ->where('workspace_id', $WS)->where('status', 'pending_approval')->count());
ok('tasks_blocked_needing_attention matches the table',
   $as['tasks_blocked_needing_attention'] === DB::table('tasks')
       ->where('workspace_id', $WS)->where('status', 'blocked')->count());
ok('only AVAILABLE capabilities are offered',
   count($ctx['capabilities']) === count(app(\App\Core\Sarah888\CapabilityManifest::class)->availableFor($WS)));

echo "-- workspace isolation of the context itself --\n";
$c2 = $st->context($WS2, 'shtest-ws2', 'how are we doing?');
$ids2 = array_column($c2['capabilities'], 'capability_id');
ok('ws990100 is not offered gsc_performance', !in_array('gsc_performance', $ids2, true),
   implode(',', array_slice($ids2, 0, 6)));

if (getenv('SHADOW_LIVE') === '1') {
    echo "-- LIVE: a full runtime-native turn --\n";
    app(\App\Core\Sarah888\SpendContext::class)->setTurn(
        ['authorized' => true, 'reason' => 'shtest', 'classification' => 'authorisation'], $WS);
    $t = $st->run($WS, 'shtest-live-' . getmypid(),
        'What is Search Console telling us about our keyword opportunities?');
    ok('live turn produced a reply', trim($t['reply']) !== '');
    ok('  it selected gsc_performance',
       ($t['trace']['tool_intent']['capability_id'] ?? '') === 'gsc_performance',
       json_encode($t['trace']['tool_intent'] ?? null));
    ok('  the tool really executed',
       ($t['trace']['tool_result']['status'] ?? '') === ToolResult::SUCCEEDED);
    ok('  timings were recorded', ($t['timings']['total_ms'] ?? 0) > 0);
    echo "    total_ms=" . $t['timings']['total_ms'] . " reply=" . mb_substr($t['reply'], 0, 90) . "\n";
} else {
    echo "-- LIVE turns skipped (set SHADOW_LIVE=1) --\n";
}

echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
