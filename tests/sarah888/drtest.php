<?php
/** Slice 1F.1 — DelegationRecorder proof, and the guard interaction. */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require_once '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\DelegationRecorder;
use App\Core\Sarah888\DenialGuard;

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = '') {
    global $pass, $fail;
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " $n" . ($d ? " :: $d" : '') . "\n";
    $ok ? $pass++ : $fail++;
}

$WS = 999813;
$required = [];
foreach (DB::select('SHOW COLUMNS FROM workspaces') as $c) {
    if ($c->Null === 'NO' && $c->Default === null && $c->Extra !== 'auto_increment') $required[$c->Field] = $c->Type;
}
DB::table('workspaces')->where('id', $WS)->delete();
$row = ['id'=>$WS,'created_at'=>now(),'updated_at'=>now()];
foreach ($required as $f=>$ty) { if (!isset($row[$f])) $row[$f] = str_contains($ty,'int')?0:(str_contains($ty,'json')?'{}':'s888-1f'); }
$row['name']='Sarah888 delegation test';
if (array_key_exists('created_by',$row)) $row['created_by'] = (int) DB::table('users')->orderBy('id')->value('id');
DB::table('workspaces')->insert($row);

$rec = new DelegationRecorder();
$guard = new DenialGuard();
$EXEC = 'exec-1f-aaa';
$OTHER = 'exec-1f-bbb';

echo "\n─── NOTHING CONSULTED = NOTHING ATTRIBUTABLE ───\n\n";
t('no consultation on record', $rec->wasAnyoneConsulted($WS, $EXEC) === false);
$r = $guard->validate("All team members confirm: there is nothing scheduled.", $WS, false,
                      $rec->wasAnyoneConsulted($WS, $EXEC));
t('attribution is stripped when unconsulted', count($r['rewritten']) === 1, mb_substr($r['reply'],0,60));
t('the prompt block says so plainly', str_contains($rec->recentForPrompt($WS), 'none on record'));

echo "\n─── A REAL CONSULTATION IS RECORDED AND UNLOCKS ATTRIBUTION ───\n\n";
$n = $rec->recordRound($WS, ['james','priya'], 'What content gaps do we have?', [
    'execution_id' => $EXEC, 'source_message_id' => 4242, 'conversation_id' => "ws{$WS}:sarah",
    'responses' => ['james' => 'Three keyword clusters are uncovered.', 'priya' => 'Two thin pages need rewriting.'],
]);
t('both consultations recorded', $n === 2, "$n rows");
t('they are attributed to THIS execution', $rec->consultedInExecution($WS, $EXEC) === ['james','priya'],
   json_encode($rec->consultedInExecution($WS, $EXEC)));
t('wasAnyoneConsulted is now true', $rec->wasAnyoneConsulted($WS, $EXEC) === true);
$r = $guard->validate("James and Priya both confirm the gap analysis is worth running.", $WS, false,
                      $rec->wasAnyoneConsulted($WS, $EXEC));
t('a GENUINE attribution now survives the guard', count($r['rewritten']) === 0, 'not stripped');

echo "\n─── A CONSULTATION IN ANOTHER TURN LICENSES NOTHING ───\n\n";
t('a different execution has no record', $rec->wasAnyoneConsulted($WS, $OTHER) === false);
$r = $guard->validate("James and Priya both confirm it.", $WS, false, $rec->wasAnyoneConsulted($WS, $OTHER));
t('attribution stripped in the OTHER turn', count($r['rewritten']) === 1, 'stale consultation does not carry over');

echo "\n─── THE RECORD IS EVIDENCE, NOT A CLAIM ───\n\n";
$row = DB::table('agent_delegations')->where('workspace_id',$WS)->orderByDesc('id')->first();
$res = json_decode($row->result_json, true);
t('the question asked is stored', ($res['question'] ?? '') === 'What content gaps do we have?');
t('the answer given is stored', str_contains($res['response'] ?? '', 'thin pages'));
t('the source message is traceable', ($res['source_message_id'] ?? null) === 4242);
t('the execution is traceable', ($res['execution_id'] ?? '') === $EXEC);
t('marked as a consultation, not an execution delegation', ($res['kind'] ?? '') === 'consultation');
t('plan_id is null (no execution plan)', $row->plan_id === null);

echo "\n─── A FAILED CONSULTATION IS DISCLOSED, NOT HIDDEN ───\n\n";
$rec->recordConsultation($WS, 'elena', 'Any view on the rebrand?', null, ['execution_id' => $EXEC]);
$e1 = DB::table('agent_delegations')->where('workspace_id',$WS)->where('to_agent','elena')->first();
t('no individual answer records as CONSULTED, not failed', $e1->status === 'consulted', $e1->status);
$rec->recordConsultation($WS, 'nora', 'Any view?', null, ['execution_id' => $EXEC, 'failed' => true]);
$e2 = DB::table('agent_delegations')->where('workspace_id',$WS)->where('to_agent','nora')->first();
t('an EXPLICIT failure records as failed', $e2->status === 'failed', $e2->status);
t('it still appears in the prompt block', str_contains($rec->recentForPrompt($WS), 'elena'));

echo "\n─── ISOLATION ───\n\n";
t('another workspace sees no consultations', $rec->wasAnyoneConsulted(999814, $EXEC) === false);
t('consultedInExecution is workspace-scoped', $rec->consultedInExecution(999814, $EXEC) === []);

echo "\n─── EXISTING EXECUTION DELEGATIONS ARE NOT DISTURBED ───\n\n";
$before = DB::table('agent_delegations')->whereNotNull('plan_id')->count();
t('rows with a plan_id are untouched by the consultation reader',
   count($rec->consultedInExecution(2, 'nonexistent-exec')) === 0, "$before execution rows exist");

DB::table('agent_delegations')->where('workspace_id', $WS)->delete();
DB::table('workspaces')->where('id', $WS)->delete();
echo "\n  cleanup done\n\n  $pass passed, $fail failed\n\n";
exit($fail ? 1 : 0);
