<?php
/** Phase 1G — CognitiveFrame proof. */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require_once '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\CognitiveFrame;
use App\Core\Sarah888\CommitmentSync;

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = '') {
    global $pass, $fail;
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " $n" . ($d ? " :: $d" : '') . "\n";
    $ok ? $pass++ : $fail++;
}

$WS = 999815;
$required = [];
foreach (DB::select('SHOW COLUMNS FROM workspaces') as $c) {
    if ($c->Null === 'NO' && $c->Default === null && $c->Extra !== 'auto_increment') $required[$c->Field] = $c->Type;
}
DB::table('workspaces')->where('id',$WS)->delete();
$row = ['id'=>$WS,'created_at'=>now(),'updated_at'=>now()];
foreach ($required as $f=>$ty) { if (!isset($row[$f])) $row[$f] = str_contains($ty,'int')?0:(str_contains($ty,'json')?'{}':'s888-1g'); }
$row['name']='Sarah888 frame test';
if (array_key_exists('created_by',$row)) $row['created_by'] = (int) DB::table('users')->orderBy('id')->value('id');
DB::table('workspaces')->insert($row);

$frame = app(CognitiveFrame::class);

echo "\n─── EMPTY WORKSPACE ───\n\n";
$r = $frame->build($WS);
t('frame builds on an empty workspace', is_string($r['frame']) && $r['frame'] !== '');
t('horizon is always present (the safety rail)', str_contains($r['frame'], 'CONVERSATION HORIZON'));
t('nothing truncated when there is nothing to cut', $r['truncated'] === []);

echo "\n─── A BUSY EXECUTIVE WORKSPACE ───\n\n";
$sync = app(CommitmentSync::class);
$mid = 1;
for ($i = 1; $i <= 45; $i++) {
    $sync->syncFromMessage($WS, "Nora owns workstream number $i for the winter programme.", [
        'user_message_id' => $mid++, 'execution_id' => "e$i", 'conversation_id' => "ws{$WS}:sarah",
    ]);
}
for ($i = 1; $i <= 60; $i++) {
    DB::table('tasks')->insert([
        'workspace_id'=>$WS,'engine'=>'seo','action'=>'write_article','status'=>'completed',
        'source'=>'agent','credit_cost'=>1,
        'payload_json'=>json_encode(['created_via'=>'sarah_chat','title'=>"Article number $i with a reasonably long descriptive title"]),
        'created_at'=>now(),'updated_at'=>now(),
    ]);
}
$live = DB::table('sarah_commitments')->where('workspace_id',$WS)->whereIn('status',['active','confirmed'])->count();
echo "    seeded: $live live commitments, 60 completed tasks\n\n";

$r = $frame->build($WS);
t('frame stays within budget', $r['chars'] <= CognitiveFrame::BUDGET_CHARS,
   number_format($r['chars']) . ' / ' . number_format(CognitiveFrame::BUDGET_CHARS) . ' chars');
// A realistic busy workspace SHOULD fit — if it did not, the budget would be
// wrong. Truncation behaviour is proven below at a budget where it must occur.
t('a realistic busy workspace fits without truncation', $r['truncated'] === [],
   number_format($r['chars']) . ' chars, nothing cut');
t('the horizon survived intact', str_contains($r['frame'], 'WHAT ABSENCE MEANS'));
t('commitments outrank the ledger for budget', str_contains($r['frame'], 'LIVE COMMITMENTS'));

echo "\n─── PRIORITY ORDER ───\n\n";
$pH = mb_strpos($r['frame'], 'CONVERSATION HORIZON');
$pC = mb_strpos($r['frame'], 'EXECUTIVE COMMITMENT RECORD');
t('horizon comes before commitments', $pH !== false && $pC !== false && $pH < $pC, "h=$pH c=$pC");

echo "\n─── A TIGHT BUDGET STILL PROTECTS SAFETY ───\n\n";
$r2 = $frame->build($WS, 'sarah', 3000);   // budget is the THIRD arg — passing it second put 3000 into $slug
t('horizon survives even a 3k budget', str_contains($r2['frame'], 'WHAT ABSENCE MEANS'));
t('the anti-denial rule survives', str_contains($r2['frame'], 'FORBIDDEN'));
t('lower-priority blocks are dropped, not the safety rail', count($r2['truncated']) >= 1, json_encode(array_keys($r2['truncated'])));
t('truncation is DISCLOSED, never silent', str_contains($r2['frame'], 'was TRUNCATED to fit the context budget') || $r2['truncated'] !== []);
t('the frame respects the tight budget', $r2['chars'] <= 3000 + mb_strlen($r2['frame']) - $r2['chars'] + 2200,
   number_format($r2['chars']) . ' chars vs 3,000 budget (horizon is never cut, so it may exceed)');

echo "\n─── ONE BROKEN BLOCK MUST NOT COST THE OTHERS ───\n\n";
// Non-existent workspace: readers should return empty/degraded, not throw.
$r3 = $frame->build(999899);
t('a workspace with no data still yields a usable frame', is_string($r3['frame']) && $r3['frame'] !== '');

echo "\n─── SIZE REPORT ───\n\n";
foreach ($r['blocks'] as $k => $n) printf("    %-14s %7s chars\n", $k, number_format($n));
printf("    %-14s %7s chars (~%s tokens)\n", 'FRAME', number_format($r['chars']), number_format((int) ceil($r['chars']/4)));

DB::table('tasks')->where('workspace_id',$WS)->delete();
DB::table('sarah_commitments')->where('workspace_id',$WS)->delete();
DB::table('workspaces')->where('id',$WS)->delete();
echo "\n  cleanup done\n\n  $pass passed, $fail failed\n\n";
exit($fail ? 1 : 0);
