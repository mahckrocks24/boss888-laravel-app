<?php
/**
 * SARAH888 Phase 1O — extraction generality (S1L-D01 / D02 / N01).
 *
 * The positives are the exact Phase 1L turns that were lost. The negatives are
 * the reason this slice is dangerous: a date detector that fires on any month
 * name, or a rename that fires on any "it's X now", would fabricate commitments
 * — which is worse than missing them.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\CommitmentExtractor;
use App\Core\Sarah888\CommitmentStore;
use App\Core\Sarah888\CommitmentSync;

const WS = 999905;
$pass = 0; $fail = 0;
function ok(string $l, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($d ? "  :: $d" : '') . "\n"; }
}
$x = new CommitmentExtractor();
$first = fn(array $r) => $r[0] ?? null;

echo "\n──── S1L-D01: THE DATE THAT WAS LOST ────\n";
$r = $x->extract('Residency first. 24 sittings, hard opening 6 May, the landlord needs the final layout 35 days ahead. Work backwards and give me my real date.');
$dated = array_values(array_filter($r, fn($i) => !empty($i['deadline'])));
ok('the anchor commitment is extracted', $r !== [], json_encode(array_map(fn($i)=>$i['title'] ?? $i['target'] ?? '?', $r)));
ok('  …with the 6 May opening captured', $dated !== [] && str_contains($dated[0]['deadline'], '-05-06'),
    json_encode(array_map(fn($i)=>$i['deadline'] ?? null, $r)));
ok('  …and the subject is still identifiable',
    $dated !== [] && (stripos($dated[0]['title'], 'sitting') !== false || stripos($dated[0]['title'], 'opening') !== false),
    $dated[0]['title'] ?? '');

echo "\n──── VOCABULARY-INDEPENDENT DATE DETECTION ────\n";
foreach ([
    ['12 sittings, hard opening 6 May.',            '-05-06'],
    ['30 covers, hard launch 9 March.',             '-03-09'],
    ['The kickoff is 14 April.',                    '-04-14'],
    ['Go-live 2 September, no slippage.',           '-09-02'],
    ['Handover 11 October to the new team.',        '-10-11'],
    ['Board review 19 May.',                        '-05-19'],
    ['The tasting session 3 February.',             '-02-03'],
    ['Supplier audit 7 December.',                  '-12-07'],
] as [$line, $expect]) {
    $res = $x->extract($line);
    $d = $first(array_values(array_filter($res, fn($i) => !empty($i['deadline']))));
    ok('date found in: "' . mb_substr($line, 0, 38) . '"',
        $d !== null && str_contains($d['deadline'], $expect), json_encode($d['deadline'] ?? null));
}

echo "\n──── PAST EVENTS ARE NOT DEADLINES ────\n";
foreach ([
    'We met on 6 May to discuss the layout.',
    'The soft opening was 14 April and it went badly.',
    'I spoke to Marcus on 9 March about the fit-out.',
    'Last month we finished the audit on 3 February.',
] as $past) {
    $res = $x->extract($past);
    $d = array_values(array_filter($res, fn($i) => !empty($i['deadline'])));
    ok('no deadline invented: "' . mb_substr($past, 0, 40) . '"', $d === [], json_encode($d));
}

echo "\n──── S1L-D02: BOTH RENAME FORMS ────\n";
$r2 = $x->extract("Rename the winter market residency. It's Project Kiln now.");
$rn = array_values(array_filter($r2, fn($i) => ($i['type'] ?? '') === 'rename'));
ok('two rename intents are produced', count($rn) === 2, json_encode($rn));
ok('  …the first names the target', ($rn[0]['target'] ?? '') !== '' && empty($rn[0]['new_title']), json_encode($rn[0] ?? null));
ok('  …the second carries the new name', ($rn[1]['new_title'] ?? '') === 'Project Kiln', json_encode($rn[1] ?? null));
ok('  …and is above the confirm threshold',
    ($rn[1]['confidence'] ?? 0) >= CommitmentStore::CONFIRM_THRESHOLD, (string) ($rn[1]['confidence'] ?? 0));
ok('  …with the pronoun flagged for resolution', !empty($rn[1]['target_is_pronoun']), json_encode($rn[1] ?? null));

$r3 = $x->extract("Actually Project Kiln is wrong. It's Project Foundry now.");
$rn3 = array_values(array_filter($r3, fn($i) => ($i['type'] ?? '') === 'rename'));
ok('the correction form is a rename', $rn3 !== [] && ($rn3[0]['new_title'] ?? '') === 'Project Foundry', json_encode($rn3));

echo "\n──── A RENAME MUST LOOK LIKE A NAME ────\n";
foreach (["It's Friday now.", "It's fine now.", "It's ready now.", "It's much better now."] as $notName) {
    $res = $x->extract($notName);
    $rr = array_values(array_filter($res, fn($i) => ($i['type'] ?? '') === 'rename'
        && ($i['confidence'] ?? 0) >= CommitmentStore::CONFIRM_THRESHOLD));
    ok('not a rename: "' . $notName . '"', $rr === [], json_encode($rr));
}

echo "\n──── S1L-N01: THE INTRODUCER IS STRIPPED ────\n";
$r4 = $x->extract('Three fronts this quarter: the winter market residency, the retail accounts push, and the kitchen fit-out. Log those as the three fronts.');
$titles = array_map(fn($i) => $i['title'] ?? $i['target'] ?? '', $r4);
ok('no title retains the introducer',
    !in_array('Three fronts this quarter: the winter market residency', $titles, true), implode(' | ', $titles));
ok('  …and the residency is a clean item',
    in_array('winter market residency', $titles, true), implode(' | ', $titles));
ok('  …and all three fronts are present',
    in_array('retail accounts push', $titles, true) && in_array('kitchen fit-out', $titles, true),
    implode(' | ', $titles));

echo "\n──── END TO END: THE RENAME CHAIN IS WALKABLE ────\n";
DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$store = app(CommitmentStore::class);
$sync  = app(CommitmentSync::class);
$corr  = ['conversation_id' => 'ws' . WS . ':sarah', 'user_message_id' => 1, 'execution_id' => 'test-1o'];

$sync->syncFromMessage(WS, 'Add the winter market residency to the list.', $corr);
$sync->syncFromMessage(WS, "Rename the winter market residency. It's Project Kiln now.", $corr);
$sync->syncFromMessage(WS, "Actually Project Kiln is wrong. It's Project Foundry now.", $corr);

$live = $store->live(WS);
$titlesLive = array_map(fn($c) => $c->title, $live);
ok('the current name is Project Foundry', in_array('Project Foundry', $titlesLive, true), implode(' | ', $titlesLive));
ok('  …and exactly one is live', count($live) === 1, (string) count($live));

$render = $store->renderForPrompt(WS);
ok('the rename history is rendered', stripos($render, 'RENAME HISTORY') !== false, mb_substr($render, 0, 400));
ok('  …showing the previous name', stripos($render, 'Kiln') !== false, mb_substr($render, 0, 500));
ok('  …and the original', stripos($render, 'winter market residency') !== false, mb_substr($render, 0, 500));

DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
ok('scratch cleaned', DB::table('sarah_commitments')->where('workspace_id', WS)->count() === 0);

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
