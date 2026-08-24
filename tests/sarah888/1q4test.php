<?php
/**
 * SARAH888 Phase 1Q.4 — generalised ownership replacement (S1P-D06).
 *
 * The positive cases are the handover forms an executive actually uses. The
 * negatives are the reason this is risky: "replace the till roll printer" and
 * "we need to replace the packaging supplier" must never become ownership
 * changes, or every maintenance instruction rewrites the org chart.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\CommitmentExtractor;
use App\Core\Sarah888\CommitmentStore;
use App\Core\Sarah888\CommitmentSync;

const WS = 999909;
$pass = 0; $fail = 0;
function ok(string $l, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($d ? "  :: $d" : '') . "\n"; }
}
$x = new CommitmentExtractor();
$own = function (string $t) use ($x) {
    foreach ($x->extract($t) as $i) if (($i['type'] ?? '') === 'ownership') return $i;
    return null;
};

echo "\n──── HANDOVER FORMS ────\n";
foreach ([
    ["Owen replaces him on supplier sourcing.",                    'Owen'],
    ["Owen replaces Rafa on supplier sourcing.",                   'Owen'],
    ["Rafa is being replaced by Owen on supplier sourcing.",       'Owen'],
    ["Put Owen in Rafa's place on supplier sourcing.",             'Owen'],
    ["Supplier sourcing moves from Rafa to Owen.",                 'Owen'],
    ["The trade counter passes from Marta to Yusuf.",              'Yusuf'],
    ["Hand the price list over to Marta.",                         'Marta'],
    ["Owen takes over from Rafa on supplier sourcing.",            'Owen'],
    ["Yusuf steps in for Marta on the trade counter.",             'Yusuf'],
    ["Rafa's replacement on supplier sourcing is Owen.",           'Owen'],
] as [$line, $expect]) {
    $o = $own($line);
    ok(str_pad('"' . mb_substr($line, 0, 48) . '"', 52) . '→ ' . $expect,
        $o !== null && ($o['owner'] ?? '') === $expect, json_encode($o));
}

echo "\n──── CERT-A2-D01: THE GENERIC PATTERN MUST NOT SHADOW THE HANDOVER ────\n";
// Pass A: "Callum takes over from Torsten on the studio rebuild" was caught by
// the generic "<Name> takes <thing>" rule, producing a commitment titled
// "over from Torsten on the studio rebuild" while the real rebuild kept its old
// owner. And "Torsten is off the rebuild" was read as CANCELLING a person.
$o = $own('Callum takes over from Torsten on the studio rebuild.');
ok('handover wins over the generic "takes" pattern',
    $o !== null && ($o['owner'] ?? '') === 'Callum'
    && stripos($o['target'] ?? '', 'over from') === false, json_encode($o));
ok('  …and the target is the work itself',
    $o !== null && stripos($o['target'] ?? '', 'studio rebuild') !== false, json_encode($o));

$items = $x->extract('Torsten is off the rebuild. Callum takes over from Torsten on the studio rebuild.');
$cancels = array_values(array_filter($items, fn ($i) => ($i['type'] ?? '') === 'cancellation'));
ok('a person is never cancelled', $cancels === [], json_encode($cancels));
$owns = array_values(array_filter($items, fn ($i) => ($i['type'] ?? '') === 'ownership'));
ok('  …and the handover still registers', $owns !== [] && ($owns[0]['owner'] ?? '') === 'Callum', json_encode($owns));

echo "\n──── THE TARGET IS THE WORK, NOT THE PERSON ────\n";
$o = $own('Owen replaces Rafa on supplier sourcing.');
ok('target is the work', $o && stripos($o['target'], 'sourcing') !== false, json_encode($o));
ok('  …and not the outgoing person', $o && stripos($o['target'], 'rafa') === false, json_encode($o));

echo "\n──── PRECISION NEGATIVES ────\n";
foreach ([
    'Replace the till roll printer.',
    'We need to replace the packaging supplier.',
    'Replace two burner valves this week.',
    'The chiller gasket needs replacing.',
    'Replace the hero image on the landing page.',
    'Owen replaced the old copy with the new draft.',
    'Hand the flyers to the printer on Friday.',
    'Move the deadline from March to April.',
] as $neg) {
    $o = $own($neg);
    ok('no ownership from: "' . mb_substr($neg, 0, 46) . '"', $o === null, json_encode($o));
}

echo "\n──── HISTORY IS PRESERVED, NOT OVERWRITTEN ────\n";
DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$store = app(CommitmentStore::class);
$sync  = app(CommitmentSync::class);
$corr  = ['conversation_id' => 'ws' . WS . ':sarah', 'user_message_id' => 1, 'execution_id' => 'test-1q4'];

$sync->syncFromMessage(WS, 'Rafa owns supplier sourcing.', $corr);
$sync->syncFromMessage(WS, 'Rafa is off sourcing. Owen replaces him on supplier sourcing.', $corr);

$live = array_values(array_filter($store->live(WS), fn($c) => stripos($c->title, 'sourcing') !== false));
ok('exactly one live sourcing commitment', count($live) === 1, (string) count($live));
ok('  …now owned by Owen', $live && $live[0]->owner === 'Owen', $live[0]->owner ?? 'NULL');

$chain = $live ? $store->history(WS, (int) $live[0]->id) : [];
$owners = array_values(array_unique(array_map(fn($r) => $r->owner ?? '-', $chain)));
ok('  …the chain still records Rafa', in_array('Rafa', $owners, true), implode(' → ', $owners));
ok('  …and ends on Owen', end($owners) === 'Owen', implode(' → ', $owners));
ok('  …with no historical row destroyed', count($chain) >= 2, (string) count($chain));

$render = $store->renderForPrompt(WS);
ok('OWNERSHIP HISTORY is rendered', stripos($render, 'OWNERSHIP HISTORY') !== false);
ok('  …showing Rafa → Owen',
    stripos($render, 'Rafa') !== false && stripos($render, 'Owen') !== false, mb_substr($render, 0, 600));

echo "\n──── THE OTHER FORMS ALSO SUPERSEDE CLEANLY ────\n";
DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
$sync->syncFromMessage(WS, 'Marta owns the trade counter.', $corr);
$sync->syncFromMessage(WS, 'The trade counter passes from Marta to Yusuf.', $corr);
$live2 = array_values(array_filter($store->live(WS), fn($c) => stripos($c->title, 'trade counter') !== false));
ok('"passes from X to Z" reassigns', $live2 && $live2[0]->owner === 'Yusuf', $live2[0]->owner ?? 'NULL');
$chain2 = $live2 ? $store->history(WS, (int) $live2[0]->id) : [];
ok('  …and Marta survives in history',
    in_array('Marta', array_map(fn($r) => $r->owner ?? '-', $chain2), true),
    implode(' → ', array_map(fn($r) => $r->owner ?? '-', $chain2)));

DB::table('sarah_commitments')->where('workspace_id', WS)->delete();
ok('scratch cleaned', DB::table('sarah_commitments')->where('workspace_id', WS)->count() === 0);

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
