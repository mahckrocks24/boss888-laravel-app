<?php
/**
 * SARAH888 Phase 1K — commitment-state guard.
 *
 * The fixture is the verbatim live reply that produced S1I-N03. The negative
 * cases matter most: a guard that contradicts a CORRECT cancellation answer
 * would turn a good reply into a self-contradicting one.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\CommitmentStore;
use App\Core\Sarah888\CommitmentSync;
use App\Core\Sarah888\CommitmentStateGuard;

const WS = 999902;
$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail ? "  :: $detail" : '') . "\n"; }
}

DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$store = app(CommitmentStore::class);
$sync  = app(CommitmentSync::class);
$guard = app(CommitmentStateGuard::class);
$corr  = ['conversation_id' => 'ws' . WS . ':sarah', 'user_message_id' => 1, 'execution_id' => 'test-1k'];

// Rebuild the exact live scenario.
$sync->syncFromMessage(WS, 'Winter tasting programme. Add these: door list, cellar audit, '
    . 'glassware hire, corkage policy, chef briefing, and a guest feedback card.', $corr);
$sync->syncFromMessage(WS, 'Cancel the glassware hire. Dead.', $corr);
$sync->syncFromMessage(WS, 'Cancel the guest feedback card too.', $corr);

$live = count($store->live(WS));
$dead = array_map(static fn ($c) => $c->title, $store->cancelled(WS));
ok('scenario rebuilt: 4 live, 2 cancelled', $live === 4 && count($dead) === 2,
    "live=$live cancelled=" . implode('|', $dead));

echo "\n──── THE VERBATIM S1I-N03 REPLY ────\n";
$bad = "The winter tasting program currently includes 5 items: door list, cellar audit, "
     . "corkage policy, chef briefing, and the guest feedback card.";
$r = $guard->validate($bad, WS);
ok('a cancelled item listed as live is corrected', $r['corrected'], $r['reply']);
ok('  …naming the guest feedback card',
    in_array('guest feedback card', $r['items'], true), implode('|', $r['items']));
ok('  …and not the glassware hire, which was not mentioned',
    !in_array('glassware hire', $r['items'], true), implode('|', $r['items']));
ok('  …with the original text preserved', str_contains($r['reply'], '5 items'), $r['reply']);
ok('  …and a single correction sentence',
    substr_count($r['reply'], 'Correction:') === 1, $r['reply']);

echo "\n──── A CORRECT ANSWER MUST NOT BE CONTRADICTED ────\n";
$good = "You canceled the glassware hire and the guest feedback card from the winter tasting program.";
$r2 = $guard->validate($good, WS);
ok('a correct cancellation answer is untouched', !$r2['corrected'], $r2['reply']);
ok('  …byte-identical', $r2['reply'] === $good);

foreach ([
    'The glassware hire is dead and the guest feedback card was dropped.',
    'Both the glassware hire and the guest feedback card are no longer active.',
    'I removed from the plan: glassware hire, guest feedback card.',
] as $okReply) {
    $rr = $guard->validate($okReply, WS);
    ok('not corrected: "' . mb_substr($okReply, 0, 46) . '"', !$rr['corrected'], $rr['reply']);
}

echo "\n──── MIXED: ONE REPORTED DEAD, ONE PRESENTED AS LIVE ────\n";
// The whole-reply check would let the second error hide behind the first.
$mixed = "The glassware hire was cancelled. Remaining items are the door list, the cellar audit "
       . "and the guest feedback card.";
$r3 = $guard->validate($mixed, WS);
ok('the hidden second error is still caught', $r3['corrected'], $r3['reply']);
ok('  …only the misstated item is named',
    $r3['items'] === ['guest feedback card'], implode('|', $r3['items']));

echo "\n──── NO FALSE POSITIVES ────\n";
$unrelated = "The cellar audit is scheduled for 14 April and Kofi owns it.";
$r4 = $guard->validate($unrelated, WS);
ok('a reply mentioning no cancelled item is untouched', !$r4['corrected'], $r4['reply']);

$r5 = $guard->validate('', WS);
ok('an empty reply is safe', !$r5['corrected']);

DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$r6 = $guard->validate($bad, WS);
ok('a workspace with no cancellations corrects nothing', !$r6['corrected'], $r6['reply']);

echo "\n──── SHORT TITLES ARE NOT MATCHED (precision) ────\n";
$id = $store->record(WS, ['title' => 'deck', 'status' => 'cancelled', 'confidence' => 1.0]);
$r7 = $guard->validate('I built a deck of slides for the investor update.', WS);
ok('a 4-character cancelled title does not fire', !$r7['corrected'], $r7['reply']);

echo "\n──── THE RENDER MARKS EVERY CANCELLED LINE ────\n";
DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$sync->syncFromMessage(WS, 'Winter tasting programme. Add these: door list, cellar audit, '
    . 'glassware hire, corkage policy, chef briefing, and a guest feedback card.', $corr);
$sync->syncFromMessage(WS, 'Cancel the glassware hire. Dead.', $corr);
$render = $store->renderForPrompt(WS);
ok('cancelled lines carry a per-line marker', str_contains($render, '[CANCELLED] glassware hire'), '');
ok('  …and live lines do not', !preg_match('/\[CANCELLED\]\s+door list/', $render), '');
ok('  …and the header forbids counting them',
    stripos($render, 'Never count them') !== false, '');

DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
ok('scratch workspace cleaned up',
    DB::table('sarah_commitments')->where('workspace_id', WS)->count() === 0);

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
