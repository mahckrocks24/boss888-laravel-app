<?php
/**
 * SARAH888 Phase 1I — extraction fidelity.
 *
 * Every case here is a defect observed in the Phase 1H transcript, not an
 * invented scenario. DB cases run against a scratch workspace id and delete
 * their own rows; they never touch Chef Red (ws 2), and they never go near
 * Schema:: — a previous slice dropped shared tables that way.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\CommitmentExtractor;
use App\Core\Sarah888\CommitmentStore;
use App\Core\Sarah888\CommitmentSync;

const WS = 999901;
$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail ? "  :: $detail" : '') . "\n"; }
}
$titles = static fn (array $items) => array_values(array_filter(array_map(
    static fn ($i) => $i['title'] ?? $i['target'] ?? null, $items)));

$x = new CommitmentExtractor();

echo "\n──── RC-1  enumerated dictation is split into items ────\n";
$list = "More for the list: run sheet, seating plan, menu costing, wine pairings, supplier deposits, "
      . "insurance rider, ticketing page, guest list, dietary log, service brief, kitchen rota, waste plan, "
      . "photography brief, press invite, thank-you mailer, and a post-event survey.";
$r = $x->extract($list);
$t = $titles($r);
ok('16-item list yields >= 14 commitments', count($r) >= 14, count($r) . ' extracted');
ok('"wine pairings" is recorded', in_array('wine pairings', $t, true), implode(' | ', array_slice($t, 0, 6)));
ok('"post-event survey" is recorded', in_array('post-event survey', $t, true));
ok('"seating plan" is recorded', in_array('seating plan', $t, true));
ok('no item retains the list introducer', !in_array('More for the list', $t, true));

$dump = "Task dump: fix the booking form, reprint menus, renew the food licence, service the oven, "
      . "replace the chiller gasket, pest control, retrain four staff on allergens, reconcile December.";
$r2 = $x->extract($dump);
ok('task dump yields >= 7 commitments', count($r2) >= 7, count($r2) . ' extracted');

echo "\n──── RC-1n  prose with commas is NOT shredded ────\n";
$prose = "I spoke to Nora, who says the stockists are slipping, so we need a call this week.";
$r3 = $x->extract($prose);
ok('prose does not explode into list items', count($r3) <= 2, count($r3) . ' extracted: ' . implode(' | ', $titles($r3)));
$prose2 = "The chiller has failed, it is full of stock, and the booking page is down.";
$r4 = $x->extract($prose2);
ok('clauses with finite verbs stay whole', count($r4) <= 2, count($r4) . ' extracted: ' . implode(' | ', $titles($r4)));
$short = "run sheet, seating plan";
ok('two items is not an enumeration', count($x->extract($short)) <= 1);

echo "\n──── RC-3  a lowercase verb is not a person ────\n";
$r5 = $x->extract('Tag leads by source.');
$owners = array_filter(array_map(static fn ($i) => $i['owner'] ?? null, $r5));
ok('"Tag" is not treated as an owner', !in_array('Tag', $owners, true), 'owners: ' . implode(',', $owners));
ok('no commitment titled "by source"', !in_array('by source', $titles($r5), true), implode(' | ', $titles($r5)));
$r6 = $x->extract('Nora owns wholesale outreach.');
ok('a real first name still resolves as owner',
    ($r6[0]['owner'] ?? null) === 'Nora' && ($r6[0]['type'] ?? '') === 'ownership',
    json_encode($r6[0] ?? null));
$r7 = $x->extract('Add the wholesale deck to the list.');
ok('"Add" at clause head is not an owner',
    !in_array('Add', array_map(static fn ($i) => $i['owner'] ?? '', $r7), true));

echo "\n──── RC-2  pronoun targets are flagged, not recorded literally ────\n";
$r8 = $x->extract('Nora owns that.');
ok('"Nora owns that" flags a pronoun target', !empty($r8[0]['target_is_pronoun']), json_encode($r8[0] ?? null));
// A pronoun target is legitimate ON THE INTENT — the sync layer resolves it.
// What must never happen is a pronoun arriving unflagged, because that is the
// shape that reaches the store as a literal title.
$unflagged = array_filter($r8, static fn ($i) =>
    preg_match('/^(that|this|it|them|those|these)$/i', trim((string) ($i['target'] ?? $i['title'] ?? 'x')))
    && empty($i['target_is_pronoun']));
ok('no pronoun reaches the store unflagged', $unflagged === [], json_encode(array_values($unflagged)));

echo "\n──── RC-4  a scheduled event states its date with a bare copula ────\n";
$r9 = $x->extract('Investor update is 11 March.');
ok('"Investor update is 11 March" is a commitment', ($r9[0]['type'] ?? '') === 'commitment', json_encode($r9[0] ?? null));
ok('  …and carries the date', !empty($r9[0]['deadline']), json_encode($r9[0]['deadline'] ?? null));
ok('  …and keeps the subject in the title',
    stripos((string) ($r9[0]['title'] ?? ''), 'investor') !== false, ($r9[0]['title'] ?? ''));
$r10 = $x->extract('Priya is off events.');
$isDated = !empty($r10[0]['deadline']);
ok('"Priya is off events" gets no fabricated date', !$isDated, json_encode($r10[0] ?? null));

echo "\n──── RC-5/RC-6  sync-level behaviour (scratch workspace " . WS . ") ────\n";
DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$store = app(CommitmentStore::class);
$sync  = app(CommitmentSync::class);
$corr  = ['conversation_id' => 'ws' . WS . ':sarah', 'user_message_id' => 1, 'execution_id' => 'test-1i'];

// RC-2 end to end: the antecedent is the commitment named in the same turn.
$a = $sync->syncFromMessage(WS, 'Wholesale: I want 9 stockists signed by 30 April. Nora owns that.', $corr);
$rows = DB::table('sarah_commitments')->where('workspace_id', WS)->get();
$junk = $rows->first(static fn ($r) => strtolower(trim($r->title)) === 'that');
ok('no commitment is titled "that"', $junk === null, $junk->title ?? '');
$whole = $rows->first(static fn ($r) => stripos($r->title, 'stockist') !== false);
ok('the wholesale commitment exists', $whole !== null);
// reassign() supersedes rather than mutating, so the owner lands on the CURRENT
// row in the chain; the original keeps owner NULL on purpose so "who had it
// before?" stays answerable. Assert against what is live.
$liveWholesale = null;
foreach ($store->live(WS) as $c) {
    if (stripos($c->title, 'stockist') !== false) { $liveWholesale = $c; break; }
}
ok('  …and Nora owns the live version', $liveWholesale && $liveWholesale->owner === 'Nora',
    'owner=' . ($liveWholesale->owner ?? 'NULL'));
ok('  …and exactly one wholesale commitment is live',
    count(array_filter($store->live(WS), static fn ($c) => stripos($c->title, 'stockist') !== false)) === 1);

// RC-5: restating the same commitment must not create a second row.
$before = DB::table('sarah_commitments')->where('workspace_id', WS)->count();
$sync->syncFromMessage(WS, 'The site rebuild must finish by 28 February.', $corr);
$mid = DB::table('sarah_commitments')->where('workspace_id', WS)->count();
$sync->syncFromMessage(WS, 'The site rebuild must finish by 28 February.', $corr);
$after = DB::table('sarah_commitments')->where('workspace_id', WS)->count();
ok('first statement records the site rebuild', $mid > $before, "$before -> $mid");
ok('an identical restatement adds no row', $after === $mid, "$mid -> $after");

// RC-5n: a genuinely different commitment is still recorded.
$sync->syncFromMessage(WS, 'The allergen matrix must finish by 28 February.', $corr);
$after2 = DB::table('sarah_commitments')->where('workspace_id', WS)->count();
ok('a different commitment is not swallowed as a duplicate', $after2 > $after, "$after -> $after2");

// RC-6 + RC-1 end to end: enumerate, then cancel two of the items by name.
DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$sync->syncFromMessage(WS, $list, $corr);
$liveBefore = count($store->live(WS));
$sync->syncFromMessage(WS, 'Cancel the wine pairings. Dead.', $corr);
$sync->syncFromMessage(WS, 'Cancel the post-event survey too.', $corr);
$cancelled = array_map(static fn ($c) => strtolower($c->title), $store->cancelled(WS));
ok('enumerated items are live before cancelling', $liveBefore >= 14, (string) $liveBefore);
ok('"wine pairings" is cancelled',
    (bool) array_filter($cancelled, static fn ($t) => str_contains($t, 'wine pairing')), implode(' | ', $cancelled));
ok('"post-event survey" is cancelled',
    (bool) array_filter($cancelled, static fn ($t) => str_contains($t, 'survey')), implode(' | ', $cancelled));
ok('cancelled items leave the live list', count($store->live(WS)) === $liveBefore - 2,
    $liveBefore . ' -> ' . count($store->live(WS)));

// The refuses-to-guess invariant survives the enumeration change: cancelling
// something that was never tracked must still create nothing. See S1I-N01.
$cancelledCountBefore = count($store->cancelled(WS));
$u = $sync->syncFromMessage(WS, 'Cancel the Easter hamper.', $corr);
ok('an untracked cancellation creates no row',
    count($store->cancelled(WS)) === $cancelledCountBefore, json_encode($u));
ok('  …and is counted as unresolved', ($u['unresolved'] ?? 0) === 1, json_encode($u));
$v = $sync->syncFromMessage(WS, 'Cancel the thing we talked about earlier.', $corr);
ok('a vague reference cancels nothing', ($v['cancelled'] ?? 0) === 0, json_encode($v));

// The prompt must actually show them — this is what Phase 1H could not answer.
$render = $store->renderForPrompt(WS);
ok('the prompt renders a CANCELLED block', stripos($render, 'CANCELLED') !== false);
ok('  …naming wine pairings', stripos($render, 'wine pairing') !== false);
ok('  …naming the survey', stripos($render, 'survey') !== false);

DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$left = DB::table('sarah_commitments')->where('workspace_id', WS)->count();
ok('scratch workspace cleaned up', $left === 0, "rows left: $left");

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
