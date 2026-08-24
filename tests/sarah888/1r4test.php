<?php
/**
 * SARAH888 Slice 1R.4 — CERT-A-D04 / CERT-A-D05.
 *
 * Positives are the exact Pass A plants that produced no record. Negatives
 * matter more than usual here: constraint patterns are broad by nature, and
 * turning ordinary prose into commitments would bury the executive record.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\CommitmentExtractor;
use App\Core\Sarah888\CommitmentStore;
use App\Core\Sarah888\CommitmentSync;

const WS = 999912;
$pass = 0; $fail = 0;
function ok(string $l, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($d ? "  :: $d" : '') . "\n"; }
}
$x = new CommitmentExtractor();
$titles = fn (string $t) => array_map(fn ($i) => $i['title'] ?? $i['target'] ?? '', $x->extract($t));

echo "\n──── CERT-A-D04: THE FIVE LOST PLANTS ────\n";
foreach ([
    ['The trade portal cannot start until the price list is signed off.', 'price list'],
    ['The studio rebuild must finish by 30 July. It is tied to the lease break.', 'lease break'],
    ['The Ravensworth investment talks stay confidential. Nothing in shared documents.', 'Ravensworth'],
    ['The courier needs the final pallet manifest 24 days before that date.', '24 days'],
    ['Workshop capacity is 24 places per weekend. That is the ceiling.', 'capacity'],
    ['Kiln safety inspection is every six months. Next one 11 December.', 'every six months'],
] as [$line, $needle]) {
    $t = $titles($line);
    $found = false;
    foreach ($t as $title) if (stripos($title, $needle) !== false) { $found = true; break; }
    ok('recorded: "' . mb_substr($line, 0, 48) . '"', $found, implode(' | ', $t) ?: 'NOTHING');
}

echo "\n──── PRECISION: PROSE MUST NOT BECOME COMMITMENTS ────\n";
foreach ([
    'The kiln runs every day and it is fine.',
    'I spoke to Imogen about the wholesale numbers.',
    'That was a good quarter overall.',
    'How many days before the show do we need the manifest?',
    'What is our workshop capacity?',
    'Thanks, that all makes sense.',
    'The glaze looks better than last time.',
] as $prose) {
    $t = array_values(array_filter($titles($prose)));
    ok('not recorded: "' . mb_substr($prose, 0, 46) . '"', $t === [], implode(' | ', $t));
}

echo "\n──── CERT-A-D05: \"KILL X AS WELL\" ────\n";
foreach ([
    ['Kill the pottery podcast as well.', 'pottery podcast'],
    ['Cancel the gift box bundle too.',   'gift box bundle'],
    ['Drop the trade newsletter also.',   'trade newsletter'],
] as [$line, $expect]) {
    $items = $x->extract($line);
    $c = null;
    foreach ($items as $i) if (($i['type'] ?? '') === 'cancellation') { $c = $i; break; }
    ok('target is clean: "' . $line . '"',
        $c !== null && strcasecmp(trim($c['target'] ?? ''), $expect) === 0,
        json_encode($c));
}

echo "\n──── END TO END: THE SECOND CANCELLATION APPLIES ────\n";
DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$store = app(CommitmentStore::class);
$sync  = app(CommitmentSync::class);
$corr  = ['conversation_id' => 'ws' . WS . ':sarah', 'user_message_id' => 1, 'execution_id' => 'test-1r4'];

$sync->syncFromMessage(WS, 'More for the list: gift box bundle, trade newsletter, showroom booking, and the pottery podcast.', $corr);
$before = count($store->live(WS));
$sync->syncFromMessage(WS, 'Kill the gift box bundle.', $corr);
$sync->syncFromMessage(WS, 'Kill the pottery podcast as well.', $corr);
$dead = array_map(fn ($c) => strtolower($c->title), $store->cancelled(WS));

ok('four items were recorded', $before >= 4, (string) $before);
ok('gift box bundle is cancelled',
    (bool) array_filter($dead, fn ($t) => str_contains($t, 'gift box')), implode(' | ', $dead));
ok('pottery podcast is cancelled too',
    (bool) array_filter($dead, fn ($t) => str_contains($t, 'podcast')), implode(' | ', $dead));
ok('  …and both left the live list', count($store->live(WS)) === $before - 2,
    $before . ' -> ' . count($store->live(WS)));

DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
ok('scratch cleaned', DB::table('sarah_commitments')->where('workspace_id', WS)->count() === 0);

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
