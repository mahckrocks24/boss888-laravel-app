<?php
/**
 * Slice 1D.2 — ActionLedger proof. Reconstructs the exact F1-D09 situation:
 * one approval-pending event Sarah created, four completed image generations
 * run by automation, and asserts she can now tell them apart.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require_once '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\ActionLedger;

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = '') {
    global $pass, $fail;
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " $n" . ($d ? " :: $d" : '') . "\n";
    $ok ? $pass++ : $fail++;
}

// Isolated workspace with the same FK shape as the horizon test.
$WS = 999809;
$required = [];
foreach (DB::select('SHOW COLUMNS FROM workspaces') as $col) {
    if ($col->Null === 'NO' && $col->Default === null && $col->Extra !== 'auto_increment') $required[$col->Field] = $col->Type;
}
DB::table('workspaces')->where('id', $WS)->delete();
$row = ['id' => $WS, 'created_at' => now(), 'updated_at' => now()];
foreach ($required as $f => $type) {
    if (isset($row[$f])) continue;
    $row[$f] = match (true) {
        str_contains($type, 'int') => 0,
        str_contains($type, 'json') => '{}',
        str_contains($type, 'date') || str_contains($type, 'time') => now(),
        default => 'sarah888-al-' . $WS,
    };
}
$row['name'] = 'Sarah888 ledger test';
if (array_key_exists('created_by', $row)) $row['created_by'] = (int) DB::table('users')->orderBy('id')->value('id');
DB::table('workspaces')->insert($row);

$mk = function (array $a) use ($WS) {
    DB::table('tasks')->insert([
        'workspace_id' => $WS, 'engine' => $a['engine'] ?? 'seo', 'action' => $a['action'],
        'status' => $a['status'], 'source' => $a['source'] ?? 'agent',
        'requires_approval' => $a['requires_approval'] ?? 0,
        'approval_status' => $a['approval_status'] ?? null,
        'credit_cost' => $a['credit_cost'] ?? 0,
        'payload_json' => json_encode($a['payload'] ?? []),
        'created_at' => now(), 'updated_at' => now(),
    ]);
};

echo "\n─── RECONSTRUCTING THE F1-D09 SITUATION ───\n\n";
// Exactly what existed when she said "nothing I've done has changed any data".
$mk(['action'=>'create_event','status'=>'pending','requires_approval'=>1,'approval_status'=>'pending',
     'payload'=>['created_via'=>'sarah_chat','title'=>'Renew chefredraymundo.com domain']]);
for ($i=0;$i<4;$i++) $mk(['action'=>'generate_image_mini','status'=>'completed','credit_cost'=>2,
     'payload'=>['created_via'=>'fill_missing_images','title'=>"Featured image for article $i"]]);

$led = new ActionLedger();
$l = $led->forWorkspace($WS);
t('the ledger sees all five rows', $l['totals']['all'] === 5, json_encode($l['totals']));
t('the approval-pending event is attributed to HER', $l['totals']['pending'] === 1);
t('the four image generations are attributed to AUTOMATION', $l['totals']['automated'] === 4);
t('credits spent are counted', $l['totals']['credits_spent'] === 8, $l['totals']['credits_spent'] . ' credits');

$r = $led->renderForPrompt($WS);
t('render names the pending approval', str_contains($r, 'Renew chefredraymundo.com'));
t('render names the automated work', str_contains($r, 'generate_image_mini'));
t('render separates automation from her own work', str_contains($r, 'RUN BY BACKGROUND AUTOMATION'));
t('render states the credit spend', str_contains($r, 'Credits spent on completed work in this window: 8'));
t('render forbids the F1-D09 answer', str_contains($r, 'never answer "nothing changed" while this ledger is non-empty'));
t('render cites the original failure so it is not repeated', str_contains($r, 'nothing I\'ve done today has changed any data'));

echo "\n─── CONVERSATIONAL WORK IS ATTRIBUTED TO HER ───\n\n";
$mk(['action'=>'write_article','status'=>'completed','credit_cost'=>1,
     'payload'=>['created_via'=>'sarah_chat','title'=>'Article she queued in chat']]);
$mk(['action'=>'fix_orphans','status'=>'completed',
     'payload'=>['created_via'=>'sarah_router','title'=>'Orphan fix from chat']]);
$l = $led->forWorkspace($WS);
t('chat-queued work lands under HERS', $l['totals']['mine'] === 2, json_encode($l['totals']));
$r = $led->renderForPrompt($WS);
t('render lists her own work separately', str_contains($r, 'QUEUED BY YOU FROM CONVERSATION (2)'));

echo "\n─── OWNER-CREATED WORK IS NOT CLAIMED ───\n\n";
$mk(['action'=>'write_article','status'=>'completed','source'=>'manual',
     'payload'=>['created_via'=>'manual','title'=>'Owner created this']]);
$l = $led->forWorkspace($WS);
t('owner-created work is NOT attributed to her', $l['totals']['other'] === 1, json_encode($l['totals']));
t('and it is still visible, not hidden', str_contains($led->renderForPrompt($WS), 'NOT YOURS'));

echo "\n─── FAILED WORK IS REPORTED AS FAILED ───\n\n";
DB::table('tasks')->insert([
    'workspace_id'=>$WS,'engine'=>'seo','action'=>'write_article','status'=>'failed','source'=>'agent',
    'credit_cost'=>1,'error_text'=>'provider timeout after 3 attempts',
    'payload_json'=>json_encode(['created_via'=>'sarah_chat','title'=>'Article that failed']),
    'created_at'=>now(),'updated_at'=>now(),
]);
$r = $led->renderForPrompt($WS);
t('a failed task is shown as failed, not as done', str_contains($r, 'failed'));
t('the failure reason is surfaced', str_contains($r, 'provider timeout'));
t('failed work does not count toward credits spent',
   $led->forWorkspace($WS)['totals']['credits_spent'] === 9, 'only completed rows counted');

echo "\n─── EMPTY LEDGER ───\n\n";
$WS2 = 999810;
$row['id'] = $WS2; $row['name'] = 'Sarah888 ledger empty';
// workspaces.slug is UNIQUE — the second temp row needs its own.
if (array_key_exists('slug', $row)) $row['slug'] = 'sarah888-al-' . $WS2;
DB::table('workspaces')->where('id', $WS2)->delete();
DB::table('workspaces')->insert($row);
$r2 = $led->renderForPrompt($WS2);
t('empty ledger says so plainly', str_contains($r2, 'no tasks were created'));
t('empty ledger permits "nothing"', str_contains($r2, 'the honest answer is nothing'));

echo "\n─── ISOLATION ───\n\n";
t('another workspace sees none of these rows', $led->forWorkspace($WS2)['totals']['all'] === 0);

echo "\n─── RENDERED BLOCK ───\n\n";
echo preg_replace('/^/m', '    ', rtrim($led->renderForPrompt($WS))) . "\n";

DB::table('tasks')->whereIn('workspace_id', [$WS, $WS2])->delete();
DB::table('workspaces')->whereIn('id', [$WS, $WS2])->delete();
echo "\n  cleanup: temp workspaces + tasks removed\n";
echo "\n  $pass passed, $fail failed\n\n";
exit($fail ? 1 : 0);
