<?php
/**
 * SARAH888 Phase 1Q.1 — domain-scoped cancellation (S1P-D02).
 *
 * The T112 scenario is rebuilt exactly: a commitment and a CRM lead whose names
 * resemble each other, then a generic "cancel" naming neither domain. The
 * negative cases matter as much — legitimate commitment cancellation and
 * legitimate explicit CRM lifecycle changes must both still work.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\EntityResolver;
use App\Core\Sarah888\CancellationPolicy;
use App\Core\Sarah888\CancellationScopeGuard;
use App\Core\Sarah888\CommitmentStore;
use App\Core\Sarah888\CommitmentSync;

const WS = 999906;
$pass = 0; $fail = 0;
function ok(string $l, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($d ? "  :: $d" : '') . "\n"; }
}

// ---- scenario: exactly what existed on Chef Red at T112 -------------------
foreach (['tasks', 'leads', 'sarah_commitments'] as $t) DB::table($t)->where('workspace_id', WS)->delete();
DB::table('workspaces')->where('id', WS)->delete();
DB::table('workspaces')->insert([
    'id' => WS, 'name' => 'SARAH888 scratch (1Q)', 'slug' => 'sarah888-scratch-1q',
    'created_by' => DB::table('workspaces')->where('id', 2)->value('created_by'),
    'created_at' => now(), 'updated_at' => now(),
]);
$sync  = app(CommitmentSync::class);
$store = app(CommitmentStore::class);
$corr  = ['conversation_id' => 'ws' . WS . ':sarah', 'user_message_id' => 1, 'execution_id' => 'test-1q'];
$sync->syncFromMessage(WS, 'Food hygiene certificate renews 4 November. Do not let it lapse.', $corr);
// A lead whose name genuinely collides with the commitment. The real Chef Red
// lead was "Food Hygiene Manager", which shares only one content word of three
// and does NOT clear the resolver's threshold — so the live T112 reference in
// fact resolves uniquely to the commitment, and the CRM write is refused on
// domain grounds alone. This fixture is deliberately harder: a lead that really
// does match, so the ambiguity branch itself is exercised rather than assumed.
DB::table('leads')->insert([
    'workspace_id' => WS, 'name' => 'Hygiene Certificate Renewal', 'status' => 'new',
    'created_at' => now(), 'updated_at' => now(),
]);
DB::table('leads')->insert([
    'workspace_id' => WS, 'name' => 'Food Hygiene Manager', 'status' => 'new',
    'created_at' => now(), 'updated_at' => now(),
]);
DB::table('leads')->insert([
    'workspace_id' => WS, 'name' => 'Acme Catering', 'company' => 'Acme', 'status' => 'new',
    'created_at' => now(), 'updated_at' => now(),
]);

$resolver = app(EntityResolver::class);
$policy   = app(CancellationPolicy::class);
$guard    = app(CancellationScopeGuard::class);

echo "\n──── THE T112 SCENARIO ────\n";
$r = $resolver->resolve(WS, 'hygiene certificate renewal');
ok('the reference matches in more than one domain',
    count($r['matches']) >= 2, json_encode(array_keys($r['matches'])));
ok('  …and resolution is AMBIGUOUS, not a best guess', $r['resolution'] === 'ambiguous', $r['resolution']);

$s = $policy->markTurn(WS, 'Cancel the hygiene certificate renewal. Changed my mind.');
ok('the turn is recognised as a cancellation', $s['active'], json_encode($s));
ok('  …reference extracted', str_contains(strtolower($s['reference']), 'hygiene'), $s['reference']);
ok('  …resolution ambiguous', $s['resolution'] === 'ambiguous', $s['resolution']);
ok('  …NO crm mutation permitted', !$policy->permits('crm'));
ok('  …NO write mutation permitted', !$policy->permits('write'));
ok('  …nothing at all permitted', !$policy->permits('seo') && !$policy->permits('crm'));

$reply = "I'll queue the task to update the food hygiene certificate renewal lead to 'lost' status. "
       . "This will effectively cancel the renewal. Proceeding now!\n\n✅ Queued 1 tasks (Elena: 1).";
$g = $guard->validate($reply, WS);
ok('the reply is corrected', $g['corrected'], $g['reply']);
ok('  …"Proceeding now!" is gone', stripos($g['reply'], 'proceeding now') === false, $g['reply']);
ok('  …the queued marker is gone', !str_contains($g['reply'], 'Queued'), $g['reply']);
ok('  …it names both candidates', stripos($g['reply'], 'commitment') !== false && stripos($g['reply'], 'CRM') !== false, $g['reply']);
ok('  …and asks which one', stripos($g['reply'], 'which one') !== false, $g['reply']);
ok('  …and states nothing was cancelled', stripos($g['reply'], "haven't cancelled") !== false, $g['reply']);

echo "\n──── REPLY HYGIENE WHEN CORRECTING (live findings) ────\n";
// Both observed on the HTTP path immediately after 1Q.1 shipped.
DB::table('leads')->insert([
    'workspace_id' => WS, 'name' => 'Hygiene Certificate Renewal', 'status' => 'new',
    'created_at' => now(), 'updated_at' => now(),
]);
$policy->markTurn(WS, 'Cancel the hygiene certificate renewal. Changed my mind.');
$gh = $guard->validate("I'll queue the update. Proceeding now! Shall I go ahead with this action?", WS);
ok('a duplicate name is not listed twice',
    substr_count(strtolower($gh['reply']), 'hygiene certificate renewal"') <= 2, $gh['reply']);
ok('  …and the dangling solicitation is removed',
    stripos($gh['reply'], 'shall i go ahead') === false, $gh['reply']);

echo "\n──── A CLEAN COMMITMENT CANCELLATION STILL WORKS ────\n";
$sync->syncFromMessage(WS, 'Add the loyalty stamp card to the list.', $corr);
$s2 = $policy->markTurn(WS, 'Cancel the loyalty stamp card. Done with it.');
ok('resolves uniquely to the commitment domain',
    $s2['resolution'] === 'unique' && $s2['domain'] === EntityResolver::DOMAIN_COMMITMENT, json_encode($s2));
ok('  …crm mutation is still refused', !$policy->permits('crm'));
$g2 = $guard->validate('I have cancelled the loyalty stamp card.', WS);
ok('  …and the reply is left alone', !$g2['corrected'], $g2['reply']);

echo "\n──── EXPLICIT CRM INTENT UNLOCKS CRM ────\n";
foreach ([
    'Mark the Acme lead as lost.',
    'Move this prospect to lost.',
    'Disqualify the Acme lead.',
    'Close this CRM opportunity.',
] as $crm) {
    $sc = $policy->markTurn(WS, $crm);
    ok('CRM permitted for: "' . $crm . '"',
        $policy->permits('crm') || !$sc['active'], json_encode($sc));
}

echo "\n──── GENERIC CANCELLATION NEVER UNLOCKS CRM ────\n";
$s3 = $policy->markTurn(WS, 'Cancel Acme.');
ok('"Cancel Acme." resolves only to a CRM record',
    isset($s3['matches'][EntityResolver::DOMAIN_CRM]), json_encode(array_keys($s3['matches'])));
ok('  …but is classified crm_without_intent',
    $s3['resolution'] === 'crm_without_intent', $s3['resolution']);
ok('  …and does NOT permit a crm write', !$policy->permits('crm'), json_encode($s3));
$g3 = $guard->validate("I'll mark Acme as lost. Proceeding now!", WS);
ok('  …the reply says nothing was changed',
    stripos($g3['reply'], "haven't changed anything") !== false, $g3['reply']);
ok('  …and explains how to actually do it',
    stripos($g3['reply'], 'marked lost') !== false, $g3['reply']);
ok('  …and drops the false claim', stripos($g3['reply'], 'proceeding now') === false, $g3['reply']);

echo "\n──── NOTHING TRACKED MEANS NOTHING CANCELLED ────\n";
$s4 = $policy->markTurn(WS, 'Cancel the Trieste distribution agreement.');
ok('resolution is none', $s4['resolution'] === 'none', json_encode($s4));
ok('  …no mutation permitted', !$policy->permits('crm') && !$policy->permits('write'));
$g4 = $guard->validate("I'll cancel the Trieste distribution agreement. Proceeding now!", WS);
ok('  …reply says nothing was cancelled',
    stripos($g4['reply'], "don't have a tracked item") !== false, $g4['reply']);
ok('  …and does not invent the entity',
    stripos($g4['reply'], 'proceeding now') === false, $g4['reply']);

echo "\n──── NON-CANCELLATION TURNS ARE UNAFFECTED ────\n";
$policy->reset();
$s5 = $policy->markTurn(WS, 'Draft a short article about the winter range.');
ok('an ordinary turn is not a cancellation', !$s5['active'], json_encode($s5));
ok('  …and everything is permitted', $policy->permits('crm') && $policy->permits('write'));
$g5 = $guard->validate("I'll queue that article now. ✅ Queued 1 tasks.", WS);
ok('  …and the reply is untouched', !$g5['corrected'], $g5['reply']);

echo "\n──── TASK CANCELLATION BY ID ────\n";
$tid = DB::table('tasks')->insertGetId([
    'workspace_id' => WS, 'engine' => 'crm', 'action' => 'create_lead', 'status' => 'pending',
    'payload_json' => json_encode(['title' => 'probe']), 'source' => 'agent',
    'created_at' => now(), 'updated_at' => now(),
]);
$s6 = $policy->markTurn(WS, "Cancel task $tid.");
ok('a task id resolves to the task domain',
    $s6['resolution'] === 'unique' && $s6['domain'] === EntityResolver::DOMAIN_TASK, json_encode($s6));
ok('  …and crm is still refused', !$policy->permits('crm'));

foreach (['tasks', 'leads', 'sarah_commitments'] as $t) DB::table($t)->where('workspace_id', WS)->delete();
DB::table('workspaces')->where('id', WS)->delete();
ok('scratch workspace removed', DB::table('workspaces')->where('id', WS)->count() === 0);

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
