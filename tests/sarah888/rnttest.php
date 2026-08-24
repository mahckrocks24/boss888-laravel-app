<?php
/**
 * SARAH888 — CUTOVER ADAPTER (permanent regression).
 *
 * Proves the adapter BEFORE it is wired into the production route, and proves the thing
 * that protects the customer: a workspace that has not opted in is not merely unlikely to
 * be affected, it is structurally untouchable — the adapter returns null and legacy runs.
 *
 * Enrolment is written onto a DISPOSABLE workspace, and removed again, so no real
 * workspace is ever enrolled by running the tests.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\{RuntimeNativeTurn, PathSelector};

$P = 0; $F = 0;
function ok(string $what, bool $cond, string $extra = '') {
    global $P, $F;
    if ($cond) { $P++; } else { $F++; echo "  FAIL: {$what}" . ($extra ? " - {$extra}" : '') . "\n"; }
}

$rnt = app(RuntimeNativeTurn::class);
$sel = app(PathSelector::class);
$LIVE = 2;        // Chef Red — must NEVER be served by this unless deliberately enrolled
$FOR  = 990100;   // forensic tenant

echo "-- A WORKSPACE THAT HAS NOT OPTED IN IS NOT TOUCHED --\n";
$before = DB::table('agent_messages')->where('workspace_id', $LIVE)->count();
$r = $rnt->handle($LIVE, 'sarah', 'Sarah', 'How many drafts are waiting?', ['conversation_id' => 'x']);
ok('returns null so legacy answers', $r === null, json_encode($r));
// Asserted even while the forensic tenant IS enrolled: enrolment must never spread.
ok('  and this holds while another workspace is live on the new path',
   $sel->decide($LIVE)['path'] === PathSelector::PATH_LEGACY);
ok('  and wrote nothing at all',
   DB::table('agent_messages')->where('workspace_id', $LIVE)->count() === $before);
ok('  ws2 is still on legacy', $sel->decide($LIVE)['path'] === PathSelector::PATH_LEGACY);

echo "-- THE KILL SWITCH BEATS AN ENROLLED WORKSPACE --\n";
$saved = DB::table('workspaces')->where('id', $FOR)->value('settings_json');
$s = json_decode((string) $saved, true) ?: [];
DB::table('workspaces')->where('id', $FOR)
    ->update(['settings_json' => json_encode(array_merge($s, [PathSelector::FLAG => true]))]);
try {
    ok('forensic tenant is enrolled', $sel->useRuntimeNative($FOR));

    putenv(PathSelector::KILL_ENV . '=true'); $_ENV[PathSelector::KILL_ENV] = 'true';
    $b2 = DB::table('agent_messages')->where('workspace_id', $FOR)->count();
    $r = $rnt->handle($FOR, 'sarah', 'Sarah', 'How many drafts are waiting?', ['conversation_id' => 'k']);
    ok('kill switch forces legacy even when enrolled', $r === null);
    ok('  and wrote nothing',
       DB::table('agent_messages')->where('workspace_id', $FOR)->count() === $b2);
    putenv(PathSelector::KILL_ENV); unset($_ENV[PathSelector::KILL_ENV]);

    echo "-- AN ENROLLED WORKSPACE IS SERVED, IN THE LEGACY CONTRACT --\n";
    $corr = ['conversation_id' => 'rnttest-' . getmypid(), 'correlation_id' => 'c-' . getmypid(),
             'user_message_id' => 12345];
    $r = $rnt->handle($FOR, 'sarah', 'Sarah', 'How many drafts are waiting?', $corr);
    ok('a response is returned', is_array($r), json_encode($r));
    ok('  shaped like the legacy response', isset($r['sent'], $r['reply'], $r['agent_name']));
    ok('  and marked as runtime-native', ($r['runtime_native'] ?? false) === true);
    ok('  the reply is not empty', trim((string) $r['reply']) !== '');

    $row = DB::table('agent_messages')->where('workspace_id', $FOR)->where('role', 'agent')
        ->orderByDesc('id')->first(['content', 'metadata_json', 'agent_slug']);
    $meta = json_decode((string) $row->metadata_json, true) ?: [];
    ok('a phase=final row was written', ($meta['phase'] ?? '') === 'final', json_encode($meta));
    ok('  under the OWNER-VISIBLE slug (this is her real reply)', $row->agent_slug === 'sarah');
    ok('  carrying the turn correlation', ($meta['conversation_id'] ?? '') === $corr['conversation_id']
       && ($meta['user_message_id'] ?? null) === 12345);
    ok('  flagged runtime_native for observability', ($meta['runtime_native'] ?? false) === true);
    ok('  and the row content is the reply', trim((string) $row->content) === trim((string) $r['reply']));

    echo "-- CHATTING STILL COSTS WHAT CHATTING COSTS --\n";
    // Legacy bills the act of chatting (0.1 cr effective, batched 1 credit per 10 messages).
    // The delegation returns before the legacy meter runs, so without this the new path
    // would chat for free — the same gap class as unmetered tool execution.
    ok('the response carries the chat meter the SPA expects',
       isset($r['chat_meter']['counter'], $r['chat_meter']['debited'], $r['chat_meter']['threshold']),
       json_encode($r['chat_meter'] ?? null));
    $c0 = (int) DB::table('workspaces')->where('id', $FOR)->value('chat_meter');
    $rnt->handle($FOR, 'sarah', 'Sarah', 'And how many leads?', $corr);
    $c1 = (int) DB::table('workspaces')->where('id', $FOR)->value('chat_meter');
    ok('  each turn advances the meter (or debits and resets it)',
       $c1 === $c0 + 1 || ($c1 === 0 && $c0 === 9), "{$c0} -> {$c1}");
} finally {
    DB::table('workspaces')->where('id', $FOR)->update(['settings_json' => $saved]);
    putenv(PathSelector::KILL_ENV); unset($_ENV[PathSelector::KILL_ENV]);
}

// Phase G enrolled the forensic tenant deliberately, so "nothing is enrolled" is no longer
// the invariant. What must hold is that this SUITE changed nothing it did not restore, and
// that no customer workspace is on the new path.
echo "-- THE SUITE RESTORED WHAT IT BORROWED --\n";
ok('forensic tenant is back to its pre-suite setting',
   $sel->useRuntimeNative($FOR) === ((json_decode((string) $saved, true)[PathSelector::FLAG] ?? false) === true),
   json_encode($sel->decide($FOR)));
ok('ws2 (live customer) on legacy', $sel->decide($LIVE)['path'] === PathSelector::PATH_LEGACY);
ok('no workspace outside the approved beta list is enrolled',
   array_values(array_diff($sel->enrolled(), [990100])) === [], json_encode($sel->enrolled()));

echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", $P, $F);
exit($F > 0 ? 1 : 0);
