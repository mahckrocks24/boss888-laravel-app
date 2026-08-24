<?php
/**
 * SARAH888 Phase 1J — self-report guard.
 *
 * The verbatim F1-D09 denials are the fixtures. The most important cases here
 * are the negative ones: a guard that corrects a TRUE "nothing changed" would
 * turn an honest answer into a fabricated one, which is a worse defect than the
 * one it fixes.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Core\Sarah888\ActionLedger;
use App\Core\Sarah888\SelfReportGuard;

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail ? "  :: $detail" : '') . "\n"; }
}

/** A ledger stub so the guard is tested against known totals, not live data. */
final class FakeLedger extends ActionLedger
{
    public function __construct(private array $l) {}
    public function forWorkspace(int $w, int $s = 1440, ?string $e = null, ?array $o = null): array { return $this->l; }
    public function forConversation(int $w, string $c, int $s = 1440): array { return $this->l; }
}
$mk = static function (int $mine, int $auto = 0, int $pending = 0, int $other = 0, int $credits = 0): array {
    $rows = static fn (int $n, string $a) => array_map(
        static fn ($i) => ['id' => $i, 'action' => $a, 'status' => 'completed', 'title' => '',
                           'credits' => 0, 'via' => '', 'at' => '', 'error' => null, 'this_turn' => false],
        range(1, max($n, 0)));
    return [
        'mine' => $mine ? $rows($mine, 'create_task') : [],
        'automated' => $auto ? $rows($auto, 'fill_missing_images') : [],
        'pending' => $pending ? $rows($pending, 'publish_article') : [],
        'other' => $other ? $rows($other, 'manual') : [],
        'totals' => ['all' => $mine + $auto + $pending + $other, 'mine' => $mine,
                     'automated' => $auto, 'pending' => $pending, 'other' => $other,
                     'credits_spent' => $credits],
    ];
};
$guard = static fn (array $l) => new SelfReportGuard(new FakeLedger($l));

echo "\n──── THE VERBATIM F1-D09 / PHASE 1H DENIALS ────\n";
$d09 = "In the last 7 days, we've completed 385 tasks (41 failed). Today, no changes have been "
     . "made that affect the data in this workspace. If you have specific areas you want to focus on, let me know!";
$r = $guard($mk(322, 12, 0, 0, 44))->validate($d09, 2, 'ws2:sarah');
ok('the 1H denial is rewritten', $r['rewritten'], $r['reply']);
ok('  …and the false sentence is gone',
    stripos($r['reply'], 'no changes have been made') === false, $r['reply']);
ok('  …and the real count appears', str_contains($r['reply'], '322'), $r['reply']);
ok('  …and automation is attributed separately', str_contains($r['reply'], '12'), $r['reply']);
ok('  …and unrelated sentences survive', str_contains($r['reply'], '385 tasks'), $r['reply']);

$f1 = "No — nothing I've done today has changed any data in this workspace. The team did complete "
    . "4 featured-image generations, but I didn't initiate those.";
$r2 = $guard($mk(5))->validate($f1, 2, 'ws2:sarah');
ok('the original F1-D09 wording is rewritten', $r2['rewritten'], $r2['reply']);

foreach ([
    "I haven't made any changes to this workspace.",
    "Nothing has been changed today.",
    "There have been no changes to your data.",
    "I did not change anything in this workspace.",
] as $variant) {
    $rv = $guard($mk(3))->validate($variant, 2, 'ws2:sarah');
    ok('rewrites: "' . mb_substr($variant, 0, 42) . '"', $rv['rewritten'], $rv['reply']);
}

echo "\n──── VERBATIM LIVE REPLIES THAT DEFEATED THE FIRST BUILD ────\n";
// Captured from the HTTP path on ws2 after 1J was first deployed. An adverb
// between the auxiliary and the verb walked straight past the pattern.
$live1 = "In the last 7 days, we've completed 422 tasks (48 failed). However, no data has been "
       . "officially changed in the workspace yet as those tasks are pending execution.";
$rl1 = $guard($mk(111, 1, 8))->validate($live1, 2, 'ws2:sarah',
    "Has anything you've done today changed data in this workspace?");
ok('"no data has been OFFICIALLY changed" is caught', $rl1['rewritten'], $rl1['reply']);
ok('  …and the 7-day total is not mistaken for a self-report',
    str_contains($rl1['reply'], '111'), $rl1['reply']);

$live2 = "However, no data has been officially changed in the workspace yet as those tasks are "
       . "still pending execution. If you'd like me to proceed, just let me know!";
$rl2 = $guard($mk(111, 1, 8))->validate($live2, 2, 'ws2:sarah',
    'What did you change in this workspace during this conversation? Be exact.');
ok('the second live denial is caught too', $rl2['rewritten'], $rl2['reply']);

// alreadyReports must not be fooled by a workspace-wide count.
$rl3 = $guard($mk(9))->validate("In the last 7 days, we've completed 422 tasks.", 2, 'ws2:sarah',
    'What did you change in this workspace during this conversation? Be exact.');
ok('a 7-day workspace total does not suppress the answer', $rl3['appended'], $rl3['reply']);
ok('  …and the conversation-scoped count is given', str_contains($rl3['reply'], '9'), $rl3['reply']);

foreach ([
    'No changes have been actually made to your data.',
    'Nothing has been formally changed today.',
    "I haven't really changed anything here.",
] as $adv) {
    $ra = $guard($mk(4))->validate($adv, 2, 'ws2:sarah');
    ok('adverb variant caught: "' . mb_substr($adv, 0, 40) . '"', $ra['rewritten'], $ra['reply']);
}

echo "\n──── S1L-D04: THIRD-PERSON DENIALS ────\n";
// Verbatim from Phase 1L. Every branch assumed "no…", "nothing…" or a
// first-person "I have not", so a third-person subject escaped while 83 tasks
// sat in the ledger.
$third = "In the last 7 days, we've completed 490 tasks (64 failed). Today's actions have not "
       . "altered any data yet, as the tasks are still pending your confirmation.";
$r3p = $guard($mk(83, 1, 36))->validate($third, 2, 'ws2:sarah');
ok('"Today\'s actions have not altered any data" is caught', $r3p['rewritten'], $r3p['reply']);
ok('  …and the real count appears', str_contains($r3p['reply'], '83'), $r3p['reply']);

foreach ([
    "The workspace has not changed today.",
    "Your records have not been modified in this conversation.",
    "Nothing was created in this workspace today.",
] as $variant) {
    $rv = $guard($mk(5))->validate($variant, 2, 'ws2:sarah');
    ok('caught: "' . mb_substr($variant, 0, 44) . '"', $rv['rewritten'], $rv['reply']);
}

// Ordinary progress reporting must NOT be treated as a denial of change.
foreach ([
    "The articles have not been published yet.",
    "Three of the tasks have not been completed.",
    "The winter menu page has not been reviewed by your team.",
] as $progress) {
    $rp2 = $guard($mk(5))->validate($progress, 2, 'ws2:sarah');
    ok('progress report untouched: "' . mb_substr($progress, 0, 40) . '"',
        !$rp2['rewritten'], $rp2['reply']);
}

echo "\n──── A REPLY THAT DENIES TWICE IS CORRECTED ONCE ────\n";
// Observed live: the reply denied up front and again in a closing restatement,
// and the guard printed the whole ledger paragraph twice in one answer.
$twice = "In the last 7 days, we've completed 422 tasks. However, no data has been officially "
       . "changed in the workspace yet. To move forward, I recommend confirming Kofi's preparations. "
       . "Since these tasks are still pending, nothing has been changed.";
$rt = $guard($mk(111, 1, 8, 0, 12))->validate($twice, 2, 'ws2:sarah');
ok('both denials are removed', !preg_match('/no data has been|nothing has been changed/i', $rt['reply']), $rt['reply']);
ok('  …but the correction appears exactly once',
    substr_count($rt['reply'], 'To be exact') === 1,
    substr_count($rt['reply'], 'To be exact') . ' occurrences :: ' . $rt['reply']);
ok('  …and the credit total is not repeated',
    substr_count($rt['reply'], '12 credits') === 1, $rt['reply']);
ok('  …and surrounding content survives',
    str_contains($rt['reply'], '422 tasks') && str_contains($rt['reply'], "Kofi's preparations"), $rt['reply']);

echo "\n──── S1J-N01: CONVERSATION SCOPE IS EMPTY, WORKSPACE IS NOT ────\n";
// Tasks do not carry execution_id, so forConversation() reports zero however
// much work was done. The guard must fall back rather than read that zero as
// "the denial was true" — which is exactly how it no-opped on its first live
// run — and it must label the window it actually used.
final class SplitLedger extends ActionLedger
{
    public function __construct(private array $ws, private array $conv) {}
    public function forWorkspace(int $w, int $s = 1440, ?string $e = null, ?array $o = null): array { return $this->ws; }
    public function forConversation(int $w, string $c, int $s = 1440): array { return $this->conv; }
}
$split = new SelfReportGuard(new SplitLedger($mk(111, 1, 8), $mk(0)));
$rs = $split->validate("No data has been officially changed in the workspace yet.", 2, 'ws2:sarah');
ok('an empty conversation scope falls back to the workspace', $rs['rewritten'], $rs['reply']);
ok('  …and says which window it used',
    $rs['scope'] === 'in this workspace today' && str_contains($rs['reply'], 'in this workspace today'), $rs['scope']);
ok('  …and does not claim conversation precision',
    !str_contains($rs['reply'], 'in this conversation'), $rs['reply']);

$split2 = new SelfReportGuard(new SplitLedger($mk(111), $mk(6)));
$rs2 = $split2->validate("Nothing has been changed.", 2, 'ws2:sarah');
ok('a populated conversation scope is preferred over the workspace',
    $rs2['scope'] === 'in this conversation' && str_contains($rs2['reply'], '6'), $rs2['reply']);

$split3 = new SelfReportGuard(new SplitLedger($mk(0), $mk(0)));
$rs3 = $split3->validate("No changes have been made.", 2, 'ws2:sarah');
ok('both scopes empty still leaves a true denial alone', !$rs3['rewritten'], $rs3['reply']);

echo "\n──── THE GUARD MUST NOT MANUFACTURE CHANGES ────\n";
$empty = $mk(0);
$r3 = $guard($empty)->validate("No changes have been made in this workspace today.", 2, 'ws2:sarah');
ok('a TRUE denial is left alone', !$r3['rewritten'], $r3['reply']);
ok('  …and the text is byte-identical',
    $r3['reply'] === "No changes have been made in this workspace today.", $r3['reply']);
$r4 = $guard($empty)->validate("Nothing yet.", 2, 'ws2:sarah', 'What did you change in this workspace?');
ok('an empty ledger appends nothing', !$r4['appended'], $r4['reply']);

echo "\n──── A REPLY WITH NO DENIAL IS UNTOUCHED ────\n";
$plain = "The cellar audit is scheduled for 14 April and Kofi owns it.";
$r5 = $guard($mk(9))->validate($plain, 2, 'ws2:sarah');
ok('no denial, no rewrite', !$r5['rewritten'] && $r5['reply'] === $plain, $r5['reply']);

echo "\n──── THE UNANSWERED QUESTION (1H final turn) ────\n";
$dodge = "To enhance clarity, I recommend: 1) Schedule a strategy meeting; 2) Document key decisions; "
       . "3) Set up regular follow-ups. Shall I proceed with queuing these tasks?";
$r6 = $guard($mk(7, 2))->validate($dodge, 2, 'ws2:sarah',
    'Last thing — what did you change in this workspace during this conversation? Be exact.');
ok('a dodged self-report question is answered', $r6['appended'], $r6['reply']);
ok('  …with the real count', str_contains($r6['reply'], '7'), $r6['reply']);
ok('  …and the original text is preserved', str_contains($r6['reply'], 'strategy meeting'), $r6['reply']);

$r7 = $guard($mk(7))->validate("I queued 7 tasks for you.", 2, 'ws2:sarah',
    'What did you change in this workspace?');
ok('a reply that already reports is not double-answered', !$r7['appended'], $r7['reply']);

$r8 = $guard($mk(7))->validate("The wholesale target is 9 stockists.", 2, 'ws2:sarah',
    'What is the wholesale target?');
ok('an unrelated question appends nothing', !$r8['appended'], $r8['reply']);

echo "\n──── PENDING WORK IS NOT REPORTED AS DONE ────\n";
$r9 = $guard($mk(0, 0, 4))->validate("No changes have been made.", 2, 'ws2:sarah');
ok('approval-pending items are reported as not run',
    stripos($r9['reply'], 'waiting on your approval') !== false, $r9['reply']);
ok('  …and are not described as queued by me',
    stripos($r9['reply'], 'I queued') === false, $r9['reply']);

echo "\n──── LIVE WIRING: the real ledger answers by conversation ────\n";
$real = app(ActionLedger::class);
$conv = $real->forConversation(2, 'ws2:sarah');
ok('forConversation returns the ledger shape',
    isset($conv['totals']['all'], $conv['mine'], $conv['automated']), json_encode(array_keys($conv)));
ok('  …and is not larger than the 24h workspace ledger',
    $conv['totals']['all'] <= $real->forWorkspace(2)['totals']['all'],
    $conv['totals']['all'] . ' vs ' . $real->forWorkspace(2)['totals']['all']);
$unknown = $real->forConversation(2, 'ws2:no-such-conversation-xyz');
ok('an unknown conversation reports zero, not everything', $unknown['totals']['all'] === 0,
    (string) $unknown['totals']['all']);

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
