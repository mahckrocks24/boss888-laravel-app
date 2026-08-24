<?php
/**
 * SARAH888 — METERED EXECUTION CHARGES THE WORKSPACE (permanent regression).
 *
 * Closes the measured gap of 2026-08-14: the gateway calls connectors directly, bypassing
 * the task pipeline that charges credits, so a real DataForSEO query executed and both
 * `credits.balance` and `credit_transactions` were unchanged. A vendor query was purchased
 * and nobody was billed.
 *
 * Every assertion below watches the LEDGER, not the intention to charge.
 * Runs on the forensic tenant; it spends real credits there, deliberately.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\{ToolIntent, ToolIntentGateway, ToolResult, SpendContext, CapabilityPricing,
                       CapabilityManifest};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$WS  = 990100;
$gw  = app(ToolIntentGateway::class);
$mf  = app(CapabilityManifest::class);
$pr  = app(CapabilityPricing::class);

$bal = fn () => (float) DB::table('credits')->where('workspace_id', $WS)->value('balance');
$txn = fn (string $type) => DB::table('credit_transactions')->where('workspace_id', $WS)
    ->where('type', $type)->count();
$pending = fn () => DB::table('credit_transactions')->where('workspace_id', $WS)
    ->where('type', 'reserve')->where('reservation_status', 'pending')->count();

echo "-- PRICES COME FROM THE PLATFORM, NOT FROM THIS CODE --\n";
$q = $pr->quote('serp_analysis', 'seo');
ok('serp_analysis is priced by a blueprint', $q['source'] === 'blueprint', $q['source']);
ok('  at the canonical 1 credit', $q['credits'] === 1, (string) $q['credits']);
ok('deep_audit canonical is 3', $pr->quote('deep_audit', 'seo')['credits'] === 3);
ok('ai_report canonical is 2', $pr->quote('ai_report', 'seo')['credits'] === 2);

echo "-- reading your own database is NOT billable --\n";
// The per-engine estimation fallback would price these at 2-3 credits each. Using it to
// bill would be this code inventing a price the product never set.
foreach (['gsc_performance' => 'seo', 'list_articles' => 'write', 'get_lead' => 'crm',
          'recent_tasks' => 'platform', 'list_goals' => 'seo'] as $cap => $eng) {
    $qq = $pr->quote($cap, $eng);
    ok("{$cap} is free", $qq['credits'] === 0, $qq['source'] . '=' . $qq['credits']);
}
ok('an explicitly-zero blueprint is marked as priced, not unpriced',
   $pr->quote('list_leads', 'crm')['source'] === 'blueprint_free');

echo "-- a FREE read touches the ledger not at all --\n";
app(SpendContext::class)->setTurn(
    ['authorized' => true, 'reason' => 'metertest', 'classification' => 'authorisation'], $WS);
$b0 = $bal(); $t0 = $txn('reserve');
$gw->handle(new ToolIntent('recent_tasks', $WS, []));
ok('balance unchanged by a free read', $bal() === $b0);
ok('no reservation was even opened', $txn('reserve') === $t0);

echo "-- SHADOW does not bill: our testing is not the owner's usage --\n";
$b1 = $bal(); $t1 = $txn('reserve');
$r = $gw->handle(new ToolIntent('serp_analysis', $WS,
    ['keyword' => 'ceramic studio classes', 'location' => 'United Kingdom']), true);
ok('shadow SERP did not charge', $bal() === $b1, "{$b1} -> " . $bal());
ok('  and opened no reservation', $txn('reserve') === $t1);

echo "-- A METERED READ THAT SUCCEEDS IS BILLED --\n";
$b2 = $bal(); $c2 = $txn('commit'); $p2 = $pending();
$r = $gw->handle(new ToolIntent('serp_analysis', $WS,
    ['keyword' => 'handmade stoneware', 'location' => 'United Kingdom']));
ok('the read succeeded', $r->status === ToolResult::SUCCEEDED, $r->status);
ok('the workspace was charged exactly 1 credit', $bal() === $b2 - 1.0,
   "{$b2} -> " . $bal());
ok('  a commit was written to the ledger', $txn('commit') === $c2 + 1);
ok('  nothing is left pending', $pending() === $p2, 'pending=' . $pending());

echo "-- A FAILED VENDOR CALL MUST NOT BILL --\n";
// Missing required parameters are refused BEFORE execution — no vendor call, no charge.
$b3 = $bal(); $t3 = $txn('reserve');
$r = $gw->handle(new ToolIntent('serp_analysis', $WS, ['keyword' => '']));
ok('a refused call is not charged', $bal() === $b3, "{$b3} -> " . $bal());
ok('  and opens no reservation', $txn('reserve') === $t3, 'refused before execution');
ok('  and is reported as refused', $r->status === ToolResult::REFUSED, $r->status);

echo "-- NO DOUBLE CHARGE: two identical reads cost exactly two credits --\n";
$b4 = $bal();
$gw->handle(new ToolIntent('serp_analysis', $WS, ['keyword' => 'raku glaze', 'location' => 'United Kingdom']));
$gw->handle(new ToolIntent('serp_analysis', $WS, ['keyword' => 'raku glaze', 'location' => 'United Kingdom']));
ok('two reads cost exactly 2 credits', $bal() === $b4 - 2.0, "{$b4} -> " . $bal());
ok('no reservation left pending after either', $pending() === 0, 'pending=' . $pending());

echo "-- INSUFFICIENT BALANCE IS REFUSED BEFORE THE VENDOR IS CALLED --\n";
$saved = $bal();
DB::table('credits')->where('workspace_id', $WS)->update(['balance' => 0]);
try {
    $b5 = $bal(); $t5 = $txn('reserve');
    $r = $gw->handle(new ToolIntent('serp_analysis', $WS,
        ['keyword' => 'kiln repair', 'location' => 'United Kingdom']));
    ok('a workspace with no credits is refused', $r->status === ToolResult::REQUIRES_APPROVAL, $r->status);
    ok('  balance did not go negative', $bal() === $b5, (string) $bal());
    ok('  and no reservation was opened', $txn('reserve') === $t5);
} finally {
    DB::table('credits')->where('workspace_id', $WS)->update(['balance' => $saved]);
}
ok('balance restored for the next run', $bal() === $saved);

echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
