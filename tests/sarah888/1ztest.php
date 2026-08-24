<?php
/** The two-turn ASK_FIRST contract, end to end. */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\{ChatActionProposal, SpendPolicy, SpendContext};
use App\Core\Orchestration\ProactiveStrategyEngine;

$WS = 999915;
DB::table('workspaces')->updateOrInsert(['id'=>$WS], ['name'=>'twoturn','slug'=>'twoturn-probe',
  'timezone'=>'UTC','created_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
$clean = function() use ($WS) {
  DB::table('approvals')->where('workspace_id',$WS)->delete();
  DB::table('strategy_proposals')->where('workspace_id',$WS)->delete();
  DB::table('tasks')->where('workspace_id',$WS)->delete();
};
$clean();

$pass=0;$fail=0;
function ok(string $l,bool $c,string $d=''):void{global $pass,$fail;
  if($c){$pass++;printf("  PASS  %s\n",$l);}else{$fail++;printf("  FAIL  %s  %s\n",$l,$d);} }

$prop = app(ChatActionProposal::class);
$rows = fn() => DB::table('tasks')->where('workspace_id',$WS)->count();

$payload = ['engine'=>'seo','action'=>'deep_audit','source'=>'agent',
            'credit_cost'=>0,'requires_approval'=>true,'auto_approve'=>false,
            'description'=>'deep audit','payload'=>['scope'=>'site']];

echo "=== TURN 1 — 'Create the audit.'  (ASK_FIRST) ===\n";
$r = $prop->propose($WS, $payload, ['conversation_id'=>'conv-X','execution_id'=>'exec-X']);
ok('0 task rows',        $rows() === 0);
ok('0 queue entries',    DB::table('tasks')->where('workspace_id',$WS)->whereIn('status',['pending','awaiting_approval'])->count() === 0);
ok('proposal pending',   DB::table('strategy_proposals')->where('id',$r['proposal_id'])->value('status') === 'pending_approval');
ok('approval pending, no task attached',
   (bool) DB::table('approvals')->where('id',$r['approval_id'])->whereNull('task_id')->where('status','pending')->exists());

echo "\n=== TURN 2 — 'Yes, proceed.' ===\n";
$turn2 = app(SpendPolicy::class)->assessTurn('Yes, proceed.');
app(SpendContext::class)->setTurn($turn2);
$resolved = $prop->resolveAuthorization($WS, 'conv-X');
ok('authorization resolves to the exact proposal', $resolved?->id === $r['proposal_id']);

$res = app(ProactiveStrategyEngine::class)->approveProposal($WS, 1, (int) $r['proposal_id']);
ok('approval succeeded', ($res['success'] ?? false) === true, json_encode($res));
ok('NOW a task exists', $rows() === 1, 'rows=' . $rows());
ok('and it is the action that was proposed',
   DB::table('tasks')->where('workspace_id',$WS)->value('action') === 'deep_audit');
ok('proposal marked approved',
   DB::table('strategy_proposals')->where('id',$r['proposal_id'])->value('status') === 'approved');

echo "\n=== the same approval cannot be replayed ===\n";
$again = null;
try { $again = app(ProactiveStrategyEngine::class)->approveProposal($WS, 1, (int) $r['proposal_id']); }
catch (\Throwable $e) { $again = ['success'=>false,'error'=>$e->getMessage()]; }
ok('second approval refused', ($again['success'] ?? false) === false, json_encode($again));
ok('still exactly one task', $rows() === 1, 'rows=' . $rows());

echo "\n=== nothing pending is left dangling ===\n";
ok('no pending proposals remain', count($prop->pending($WS,'conv-X')) === 0);

$clean();
DB::table('workspaces')->where('id',$WS)->delete();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail>0?1:0);
