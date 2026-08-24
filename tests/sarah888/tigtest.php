<?php
/**
 * SARAH888 — TOOL INTENT BOUNDARY, END TO END (permanent regression).
 *
 * Architecture ruling 2026-08-13: Runtime selects, Laravel executes.
 *
 * The defect this closes, measured the same day:
 *   Runtime `/ai/run task=serp_analysis` returned success:true carrying a
 *   gpt-4o-mini essay with ZERO urls and ZERO ranking positions, while the real
 *   DataForSEO call returned 161 results with positions in 3.2s.
 *
 * So the contract under test is: a SUCCEEDED result must carry vendor evidence,
 * and every non-executed outcome must be typed, truthful, and unable to be
 * described in the past tense.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\{ToolIntent, ToolResult, ToolIntentGateway};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$gw  = app(ToolIntentGateway::class);
$WS  = 2;
$WS2 = 990100;

echo "-- intent parsing rejects what cannot be executed --\n";
$bad = ToolIntent::fromRuntime([], $WS);
ok('no capability_id -> error', $bad['intent'] === null && $bad['error'] !== null);
$bad2 = ToolIntent::fromRuntime(['capability_id' => 'serp_analysis', 'parameters' => 'nope'], $WS);
ok('non-object parameters -> error', $bad2['intent'] === null);
$good = ToolIntent::fromRuntime(
    ['tool' => 'serp_analysis', 'parameters' => ['keyword' => 'x', 'location' => 'y'], 'reason' => 'check ranks'],
    $WS, ['conversation_id' => 'c1', 'execution_id' => 'e1']);
ok('runtime shape parses', $good['intent'] instanceof ToolIntent);
ok('  objective carried', $good['intent']->objective === 'check ranks');
ok('  correlation carried', $good['intent']->conversationId === 'c1'
   && $good['intent']->executionId === 'e1');

echo "-- UNAVAILABLE: capability that does not exist --\n";
$r = $gw->handle(new ToolIntent('no_such_capability', $WS));
ok('status UNAVAILABLE', $r->status === ToolResult::UNAVAILABLE, $r->status);
ok('  executed() false', $r->executed() === false);
ok('  may_claim_done false', $r->toRuntime()['may_claim_done'] === false);

echo "-- REFUSED: required parameters missing --\n";
$r = $gw->handle(new ToolIntent('serp_analysis', $WS, []));
ok('status REFUSED', $r->status === ToolResult::REFUSED, $r->status);
// `location` was removed from required on 2026-08-14 — the executor defaults it, so it
// was never required, and demanding it made Runtime invent a country the owner had not
// named. The assertion that matters is unchanged: the refusal must NAME what is missing
// rather than fail opaquely, so the owner is told what to supply.
ok('  names the missing parameter',
   str_contains((string) $r->message, 'keyword'), (string) $r->message);
ok('  and does not demand a parameter the executor defaults',
   !str_contains((string) $r->message, 'location'), (string) $r->message);
ok('  nothing executed', $r->executed() === false);

echo "-- MISCONFIGURED: GSC-backed capability in a workspace with no connection --\n";
$r = $gw->handle(new ToolIntent('gsc_performance', $WS2, []));
ok('ws990100 GSC -> MISCONFIGURED', $r->status === ToolResult::MISCONFIGURED, $r->status);
ok('  says Search Console is not connected',
   str_contains(mb_strtolower((string) $r->message), 'search console'), (string) $r->message);
ok('  names the missing configuration', !empty($r->missingConfiguration),
   json_encode($r->missingConfiguration));

echo "-- REQUIRES_APPROVAL: a MUTATION never executes on a runtime intent --\n";
// Registry tool_ids only. publish_article/delete_lead are task actions, NOT registered
// capabilities — asking for them correctly yields UNAVAILABLE, proven above.
foreach (['create_lead' => ['name' => 'Boundary Test'],
          'publish_builder_page' => ['page_id' => 999999]] as $capId => $params) {
    $beforeT = DB::table('tasks')->where('workspace_id', $WS)->count();
    $beforeL = DB::table('leads')->where('workspace_id', $WS)->count();
    $r = $gw->handle(new ToolIntent($capId, $WS, $params));
    ok("{$capId} -> REQUIRES_APPROVAL", $r->status === ToolResult::REQUIRES_APPROVAL, $r->status);
    ok("  {$capId} executed nothing", $r->executed() === false);
    ok("  {$capId} may_claim_done false", $r->toRuntime()['may_claim_done'] === false);
    ok("  {$capId} created no task row",
       DB::table('tasks')->where('workspace_id', $WS)->count() === $beforeT);
    ok("  {$capId} created no lead row",
       DB::table('leads')->where('workspace_id', $WS)->count() === $beforeL);
}

echo "-- THE SPEND GATE: a metered READ is held until the turn is authorised --\n";
// serp_analysis is category=research/mode=auto but observed_max_credits=5, so the
// 1E.2 cost gate governs it. Unauthorised context must fail CLOSED.
$serpParams = ['keyword' => 'private chef New Jersey', 'location' => 'United States'];
$held = $gw->handle(new ToolIntent('serp_analysis', $WS, $serpParams, 'owner asked how we rank'));
ok('unauthorised turn -> REQUIRES_APPROVAL', $held->status === ToolResult::REQUIRES_APPROVAL,
   $held->status);
ok('  nothing executed', $held->executed() === false);
ok('  the cost is disclosed', $held->creditCost > 0, (string) $held->creditCost);
ok('  REQUIRES_APPROVAL is not an error', $held->errorClass === null);

echo "-- SUCCEEDED: the real SERP loop, with vendor evidence --\n";
// Simulates an owner-authorised turn, which is what the chat route sets.
app(\App\Core\Sarah888\SpendContext::class)->setTurn(
    ['authorized' => true, 'reason' => 'tigtest: owner asked for a SERP check',
     'classification' => 'authorisation'], $WS);

if (getenv('TIG_LIVE') !== '1') {
    echo "    SKIPPED live vendor call (set TIG_LIVE=1 to bill DataForSEO once).\n";
    echo "    Gate behaviour above is proven deterministically and costs nothing.\n";
    ok('spend gate opens once the turn is authorised',
       app(\App\Core\Sarah888\SpendContext::class)->isAuthorized() === true);
} else {
    $t0 = microtime(true);
    $r  = $gw->handle(new ToolIntent('serp_analysis', $WS, $serpParams, 'owner asked how we rank'));
    $took = round((microtime(true) - $t0) * 1000);
    echo "    status={$r->status} latency={$r->latencyMs}ms wall={$took}ms\n";

    if ($r->status === ToolResult::SUCCEEDED) {
        // UNESCAPED_SLASHES matters: json_encode turns https:// into https:\/\/, which
        // silently defeats a naive URL check and reports "0 urls" on real vendor data.
        $json = json_encode($r->data, JSON_UNESCAPED_SLASHES);
        ok('executed() true', $r->executed() === true);
        ok('  may_claim_done true', $r->toRuntime()['may_claim_done'] === true);
        ok('  provenance names the connector',
           str_contains((string) $r->provenance, 'DataForSeoConnector'), (string) $r->provenance);
        ok('  carries REAL ranking positions', (bool) preg_match('/"position":\s*\d+/', $json));
        ok('  carries REAL urls', preg_match_all('#https?://#', $json) > 0);
        ok('  carries total_results', isset($r->data['total_results']));
        ok('  keyword echoed back', ($r->data['keyword'] ?? '') === 'private chef New Jersey');
        ok('  latency recorded', $r->latencyMs > 0);
        ok('  NOT model prose: no token usage anywhere', !str_contains($json, 'completion_tokens'));
    } else {
        // A vendor outage is legitimate; it must be TYPED, never fabricated.
        ok('non-success is typed, never fabricated',
           in_array($r->status, ToolResult::NOT_EXECUTED, true), $r->status);
        echo "    NOTE: vendor did not return data this run — status {$r->status}\n";
    }
}

echo "-- SUCCEEDED: the real GSC read, from the authoritative synced store --\n";
$g = $gw->handle(new ToolIntent('gsc_performance', $WS, ['days' => 90, 'limit' => 5],
    'owner asked how search is performing'));
echo "    status={$g->status} latency={$g->latencyMs}ms\n";
ok('ws2 GSC read SUCCEEDED', $g->status === ToolResult::SUCCEEDED, $g->status);
if ($g->status === ToolResult::SUCCEEDED) {
    $d = $g->data;
    ok('  may_claim_done true', $g->toRuntime()['may_claim_done'] === true);
    ok('  provenance names the synced store',
       str_contains((string) $g->provenance, 'gsc_metrics'), (string) $g->provenance);
    ok('  provenance states the sync time',
       str_contains((string) $g->provenance, 'last sync'), (string) $g->provenance);
    ok('  real rows returned', (int) $d['rows'] > 0, (string) $d['rows']);
    ok('  impressions are real', (int) $d['totals']['impressions'] > 0,
       (string) $d['totals']['impressions']);
    ok('  average position present', $d['totals']['position'] !== null);
    ok('  top queries returned', count($d['top_queries']) > 0, (string) count($d['top_queries']));
    ok('  a query row carries impressions + position',
       isset($d['top_queries'][0]['impressions'], $d['top_queries'][0]['position']));
    ok('  top pages returned', count($d['top_pages']) > 0, (string) count($d['top_pages']));
    ok('  site_url is the connected property',
       str_contains((string) $d['site_url'], 'cheflisted'), (string) $d['site_url']);
    ok('  CTR is derived from totals, not averaged per row',
       $d['totals']['ctr'] === ($d['totals']['impressions'] > 0
           ? round($d['totals']['clicks'] / $d['totals']['impressions'] * 100, 2) : null),
       json_encode($d['totals']));
    ok('  states that position is an AVERAGE, not a live rank',
       str_contains((string) $d['measurement'], 'AVERAGE'), (string) $d['measurement']);
    ok('  no fabricated metric keys',
       empty(array_diff(array_keys($d['totals']), ['clicks','impressions','ctr','position'])),
       implode(',', array_keys($d['totals'])));
}

echo "-- GSC is workspace-isolated --\n";
$g99 = $gw->handle(new ToolIntent('gsc_performance', $WS2, ['days' => 90]));
ok('ws990100 cannot read ws2 GSC data', $g99->status === ToolResult::MISCONFIGURED, $g99->status);
ok('  and returns no data at all', $g99->data === null);

echo "-- no status other than SUCCEEDED may claim execution --\n";
foreach (ToolResult::NOT_EXECUTED as $s) {
    $x = new ToolResult($s, 'serp_analysis');
    ok("{$s} cannot claim done", $x->executed() === false && $x->toRuntime()['may_claim_done'] === false);
}

echo "-- the runtime envelope is complete enough to reason from --\n";
$env = $gw->handle(new ToolIntent('serp_analysis', $WS, []))->toRuntime();
foreach (['status','capability_id','executed','may_claim_done','provenance','error_class',
          'message','credit_cost','latency_ms','missing_configuration'] as $k)
    ok("envelope has {$k}", array_key_exists($k, $env));

echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
