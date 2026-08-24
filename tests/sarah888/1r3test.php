<?php
/**
 * SARAH888 Slice 1R.3 — CERT-A-D01: memory at scale.
 *
 * Reproduces the Pass A shape: a workspace with far more live commitments than
 * the render cap, where the OLD ones were seeded first and the NEW ones came
 * from the conversation. The old ordering showed the seed and hid the
 * conversation, which is how recall reached 30%.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\CommitmentStore;

const WS = 999911;
$pass = 0; $fail = 0;
function ok(string $l, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($d ? "  :: $d" : '') . "\n"; }
}

DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$store = app(CommitmentStore::class);

// 40 old commitments, as if seeded months ago.
for ($i = 0; $i < 40; $i++) {
    $id = $store->record(WS, ['title' => 'seeded legacy item ' . $i, 'confidence' => 1.0]);
    DB::table('sarah_commitments')->where('id', $id)->update([
        'created_at' => now()->subMonths(6), 'updated_at' => now()->subMonths(6),
    ]);
}
// 70 fresh ones, as if from the current conversation.
$freshTitles = [];
for ($i = 0; $i < 70; $i++) {
    $t = 'conversation commitment ' . $i;
    $freshTitles[] = $t;
    $id = $store->record(WS, ['title' => $t, 'confidence' => 1.0]);
    DB::table('sarah_commitments')->where('id', $id)->update([
        'created_at' => now()->subMinutes(70 - $i), 'updated_at' => now()->subMinutes(70 - $i),
    ]);
}
// One old-but-urgent item, to prove urgency still beats recency.
$urgent = $store->record(WS, ['title' => 'urgent legacy renewal', 'deadline' => now()->addDays(5)->toDateString(), 'confidence' => 1.0]);
DB::table('sarah_commitments')->where('id', $urgent)->update([
    'created_at' => now()->subMonths(8), 'updated_at' => now()->subMonths(8),
]);

$live = count($store->live(WS));
ok('scenario built: 111 live commitments', $live === 111, (string) $live);

$render = $store->renderForPrompt(WS);

echo "\n──── THE CONVERSATION'S OWN COMMITMENTS ARE VISIBLE ────\n";
$missing = [];
foreach (array_slice($freshTitles, -40) as $t) if (stripos($render, $t) === false) $missing[] = $t;
ok('the 40 most recent conversation commitments are all present',
    $missing === [], count($missing) . ' missing, e.g. ' . implode(', ', array_slice($missing, 0, 3)));

$allFreshMissing = array_values(array_filter($freshTitles, fn ($t) => stripos($render, $t) === false));
ok('every conversation commitment is retrievable by name',
    $allFreshMissing === [], count($allFreshMissing) . ' missing');

echo "\n──── URGENCY STILL BEATS RECENCY ────\n";
ok('an old but imminent deadline is in the detailed block',
    stripos($render, 'urgent legacy renewal') !== false, '');
$detailStart = strpos($render, 'LIVE COMMITMENTS');
$alsoStart   = strpos($render, 'ALSO LIVE');
$posUrgent   = stripos($render, 'urgent legacy renewal');
ok('  …in the DETAILED section, not the overflow list',
    $alsoStart === false || $posUrgent < $alsoStart,
    "urgent@$posUrgent alsoLive@" . var_export($alsoStart, true));

echo "\n──── THE OVERFLOW IS NAMED, NOT SILENTLY DROPPED ────\n";
ok('an overflow section exists', stripos($render, 'ALSO LIVE') !== false);
ok('  …and nothing is described as simply missing without a count',
    !preg_match('/\.\.\. and \d+ more not shown here/i', $render));

echo "\n──── THE FRAME STILL FITS ITS BUDGET ────\n";
$frame = app(App\Core\Sarah888\CognitiveFrame::class)->build(WS, 'sarah');
ok('cognitive frame is within budget', $frame['chars'] <= 10000,
    number_format($frame['chars']) . ' chars, truncated=' . json_encode($frame['truncated']));
ok('  …and the commitments block survived', ($frame['blocks']['commitments'] ?? 0) > 0,
    json_encode($frame['blocks']));

echo "\n──── SMALL WORKSPACES ARE UNCHANGED ────\n";
DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
foreach (['alpha item', 'beta item', 'gamma item'] as $t) $store->record(WS, ['title' => $t, 'confidence' => 1.0]);
$small = $store->renderForPrompt(WS);
ok('a 3-item workspace has no overflow section', stripos($small, 'ALSO LIVE') === false);
ok('  …and all three are present',
    stripos($small, 'alpha item') !== false && stripos($small, 'beta item') !== false
    && stripos($small, 'gamma item') !== false);

DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
ok('scratch cleaned', DB::table('sarah_commitments')->where('workspace_id', WS)->count() === 0);

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
