<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\ChatActionProposal;

$WS = 999913; $WS2 = 999914;
foreach ([$WS,$WS2] as $w) {
  DB::table('workspaces')->updateOrInsert(['id'=>$w], ['name'=>"prop probe $w",'slug'=>"prop-probe-$w",
    'timezone'=>'UTC','created_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
}
$clean = function() use ($WS,$WS2) {
  DB::table('approvals')->whereIn('workspace_id',[$WS,$WS2])->delete();
  DB::table('strategy_proposals')->whereIn('workspace_id',[$WS,$WS2])->delete();
  DB::table('tasks')->whereIn('workspace_id',[$WS,$WS2])->delete();
};
$clean();

$p = app(ChatActionProposal::class);
$pass=0; $fail=0;
function ok(string $l, bool $c, string $d=''): void {
  global $pass,$fail; if($c){$pass++;printf("  PASS  %s\n",$l);} else {$fail++;printf("  FAIL  %s  %s\n",$l,$d);} }

$payload = ['engine'=>'crm','action'=>'create_campaign','source'=>'agent',
            'credit_cost'=>5,'requires_approval'=>true,'auto_approve'=>false,
            'description'=>'winter campaign','payload'=>['name'=>'winter']];

echo "=== proposing creates NO task, NO queue, NO credit reservation ===\n";
$t0 = DB::table('tasks')->where('workspace_id',$WS)->count();
$r = $p->propose($WS, $payload, ['conversation_id'=>'conv-A','execution_id'=>'exec-1']);
ok('zero task rows', DB::table('tasks')->where('workspace_id',$WS)->count() === $t0);
ok('proposal row exists', DB::table('strategy_proposals')->where('id',$r['proposal_id'])->exists());
ok('approval mirrors it with proposal_id + NULL task_id',
   (bool) DB::table('approvals')->where('id',$r['approval_id'])
        ->whereNull('task_id')->where('proposal_id',$r['proposal_id'])->exists());
$row = DB::table('strategy_proposals')->find($r['proposal_id']);
ok('conversation binding recorded', $row->conversation_id === 'conv-A', (string)$row->conversation_id);
ok('cost recorded on the proposal', (int)$row->total_credits === 5);
ok('exact create payload preserved',
   ($p->payloadFor($row)['action'] ?? null) === 'create_campaign', json_encode($p->payloadFor($row)));

echo "\n=== a single pending proposal resolves ===\n";
ok('resolves in its own conversation', $p->resolveAuthorization($WS,'conv-A')?->id === $r['proposal_id']);

echo "\n=== CROSS-BINDING DEFENCES ===\n";
ok('another conversation cannot authorise it', $p->resolveAuthorization($WS,'conv-B') === null);
ok('another workspace cannot authorise it',    $p->resolveAuthorization($WS2,'conv-A') === null);

$r2 = $p->propose($WS, array_merge($payload,['action'=>'create_lead','credit_cost'=>2]),
                  ['conversation_id'=>'conv-A','execution_id'=>'exec-2']);
ok('TWO pending proposals -> ambiguous, resolves to NOTHING',
   $p->resolveAuthorization($WS,'conv-A') === null);
ok('both are still listed for disclosure', count($p->pending($WS,'conv-A')) === 2);

echo "\n=== expiry ===\n";
DB::table('strategy_proposals')->where('id',$r2['proposal_id'])->delete();
DB::table('strategy_proposals')->where('id',$r['proposal_id'])
  ->update(['created_at'=>now()->subMinutes(ChatActionProposal::TTL_MINUTES + 5)]);
ok('a stale proposal cannot be authorised', $p->resolveAuthorization($WS,'conv-A') === null);

echo "\n=== the proactive engine's own proposals are untouched ===\n";
ok('existing proposals still present', DB::table('strategy_proposals')->count() >= 1735);
ok('and none of them gained a conversation binding',
   DB::table('strategy_proposals')->whereNotNull('conversation_id')
     ->where('type','!=',ChatActionProposal::TYPE)->count() === 0);

$clean();
DB::table('workspaces')->whereIn('id',[$WS,$WS2])->delete();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail>0?1:0);
