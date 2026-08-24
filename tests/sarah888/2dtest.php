<?php
/**
 * SARAH888 — the pending authorization object, and what a later "yes" may bind to.
 *
 * Every case below is a way consent can be silently exceeded between the moment
 * Sarah offers to do something and the moment the owner accepts. Before this
 * suite the live path created no pending object at all, so none of these could
 * even be asked.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\ChatActionProposal as CAP;

$WS = 999916; $WS2 = 999917;
foreach ([$WS,$WS2] as $w) {
  DB::table('workspaces')->updateOrInsert(['id'=>$w], ['name'=>"authbind $w",'slug'=>"authbind-$w",
    'timezone'=>'UTC','created_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
}
$clean = function() use ($WS,$WS2) {
  DB::table('approvals')->whereIn('workspace_id',[$WS,$WS2])->delete();
  DB::table('strategy_proposals')->whereIn('workspace_id',[$WS,$WS2])->delete();
  DB::table('tasks')->whereIn('workspace_id',[$WS,$WS2])->delete();
};
$clean();

$pass=0; $fail=0;
function ok(string $l, bool $c, string $d=''): void {
  global $pass,$fail; if($c){$pass++;printf("  PASS  %s\n",$l);} else {$fail++;printf("  FAIL  %s  %s\n",$l,$d);} }

$p = app(CAP::class);

$payload = ['engine'=>'write','action'=>'publish_article','source'=>'agent',
            'category'=>'publish','credit_cost'=>5,'requires_approval'=>true,
            'description'=>'publish the wholesale page',
            'payload'=>['article_id'=>4242,'title'=>'Wholesale']];
$turn = ['conversation_id'=>'conv-A','execution_id'=>'exec-A','user_message_id'=>777];

echo "=== the pending object binds the whole of what was promised ===\n";
$r = $p->propose($WS, $payload, $turn);
$row = DB::table('strategy_proposals')->find($r['proposal_id']);

ok('authorization id',      (int)$row->id === (int)$r['proposal_id']);
ok('workspace',             (int)$row->workspace_id === $WS);
ok('conversation',          $row->conversation_id === 'conv-A');
ok('source execution',      $row->execution_id === 'exec-A');
ok('source user message',   (int)$row->source_message_id === 777, (string)$row->source_message_id);
ok('capability',            $row->capability === 'write.publish_article', (string)$row->capability);
ok('action',                ($p->payloadFor($row)['action'] ?? '') === 'publish_article');
ok('entity type',           $row->entity_type === 'article', (string)$row->entity_type);
ok('entity id',             (string)$row->entity_id === '4242', (string)$row->entity_id);
ok('scope digest',          strlen((string)$row->scope_hash) === 64);
ok('quoted cost',           (int)$row->total_credits === 5);
ok('risk/policy band',      $row->risk_tier === 'publish', (string)$row->risk_tier);
ok('explicit expiry',       $row->expires_at !== null);
ok('authorization state',   $row->status === 'pending_approval');
ok('and still zero tasks',  DB::table('tasks')->where('workspace_id',$WS)->count() === 0);

echo "\n=== a matching authorization is accepted ===\n";
$a = $p->authorize($WS, 'conv-A', (int)$r['proposal_id'], [
  'credit_cost'=>5, 'entity_id'=>'4242', 'scope_hash'=>$p->scopeHash($payload)]);
ok('exact match authorises', $a['ok'] === true && $a['reason'] === CAP::AUTH_OK, json_encode($a['reason']));

echo "\n=== CROSS-BINDING REFUSALS ===\n";
$no = fn(array $x, string $reason) => $x['ok'] === false && $x['reason'] === $reason;

ok('unknown proposal',
   $no($p->authorize($WS,'conv-A', 99999999), CAP::AUTH_NOT_FOUND));
ok('other workspace cannot authorise it',
   $no($p->authorize($WS2,'conv-A',(int)$r['proposal_id']), CAP::AUTH_WRONG_WORKSPACE));
ok('other conversation cannot authorise it',
   $no($p->authorize($WS,'conv-B',(int)$r['proposal_id']), CAP::AUTH_WRONG_CONVERSATION));
ok('changed cost refuses',
   $no($p->authorize($WS,'conv-A',(int)$r['proposal_id'],['credit_cost'=>50]), CAP::AUTH_COST_CHANGED));
ok('changed entity refuses',
   $no($p->authorize($WS,'conv-A',(int)$r['proposal_id'],['entity_id'=>'9999']), CAP::AUTH_ENTITY_CHANGED));
ok('changed scope refuses',
   $no($p->authorize($WS,'conv-A',(int)$r['proposal_id'],['scope_hash'=>str_repeat('0',64)]), CAP::AUTH_SCOPE_CHANGED));

echo "\n=== expiry, both ways ===\n";
DB::table('strategy_proposals')->where('id',$r['proposal_id'])
  ->update(['expires_at'=>now()->subMinute()]);
ok('an explicitly expired offer refuses',
   $no($p->authorize($WS,'conv-A',(int)$r['proposal_id']), CAP::AUTH_EXPIRED));
ok('and it disappears from pending', count($p->pending($WS,'conv-A')) === 0);

DB::table('strategy_proposals')->where('id',$r['proposal_id'])
  ->update(['expires_at'=>null, 'created_at'=>now()->subMinutes(CAP::TTL_MINUTES + 5)]);
ok('a legacy row with no expires_at still ages out',
   $no($p->authorize($WS,'conv-A',(int)$r['proposal_id']), CAP::AUTH_EXPIRED));

echo "\n=== an offer already decided cannot be re-authorised ===\n";
DB::table('strategy_proposals')->where('id',$r['proposal_id'])
  ->update(['expires_at'=>now()->addHour(), 'created_at'=>now(), 'status'=>'approved']);
ok('approved is not pending',
   $no($p->authorize($WS,'conv-A',(int)$r['proposal_id']), CAP::AUTH_NOT_PENDING));

echo "\n=== ambiguity: two pending, bare yes resolves to nothing, but each is still addressable ===\n";
$clean();
$b1 = $p->propose($WS, $payload, $turn);
$b2 = $p->propose($WS, array_merge($payload, [
        'description'=>'publish the stockist page',
        'payload'=>['article_id'=>5150,'title'=>'Stockist']]), $turn);
ok('a bare yes is ambiguous', $p->resolveAuthorization($WS,'conv-A') === null);
ok('both are disclosed',      count($p->pending($WS,'conv-A')) === 2);
ok('naming the first still authorises it',
   $p->authorize($WS,'conv-A',(int)$b1['proposal_id'],['entity_id'=>'4242'])['ok'] === true);
ok('naming the second still authorises it',
   $p->authorize($WS,'conv-A',(int)$b2['proposal_id'],['entity_id'=>'5150'])['ok'] === true);
ok('and the first cannot be approved with the second\'s entity',
   $no($p->authorize($WS,'conv-A',(int)$b1['proposal_id'],['entity_id'=>'5150']), CAP::AUTH_ENTITY_CHANGED));

echo "\n=== the scope digest is about scope, not key order ===\n";
$reordered = ['payload'=>['title'=>'Wholesale','article_id'=>4242], 'credit_cost'=>5,
              'action'=>'publish_article','engine'=>'write'];
ok('an equivalent payload hashes the same',
   $p->scopeHash($payload) === $p->scopeHash($reordered));
ok('a different parameter hashes differently',
   $p->scopeHash($payload) !== $p->scopeHash(array_merge($payload,
     ['payload'=>['article_id'=>4243,'title'=>'Wholesale']])));

echo "\n=== risk bands ===\n";
$band = function(array $over) use ($p, $WS, $turn) {
    $r = $p->propose($WS, array_merge(
        ['engine'=>'x','action'=>'a','source'=>'agent','credit_cost'=>0,'payload'=>[]], $over), $turn);
    return DB::table('strategy_proposals')->where('id',$r['proposal_id'])->value('risk_tier');
};
ok('delete is destructive', $band(['action'=>'delete_article']) === 'destructive');
ok('publish is publish',    $band(['action'=>'publish_article']) === 'publish');
ok('send is outbound',      $band(['action'=>'send_newsletter']) === 'outbound');
ok('paid work is spend',    $band(['action'=>'write_article','credit_cost'=>3]) === 'spend');
ok('free internal work is standard', $band(['action'=>'summarise']) === 'standard');

echo "\n=== the proactive engine's 1,783 proposals are untouched ===\n";
ok('none gained a chat binding',
   DB::table('strategy_proposals')->where('type','!=',CAP::TYPE)
     ->whereNotNull('capability')->count() === 0);

$clean();
DB::table('workspaces')->whereIn('id',[$WS,$WS2])->delete();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail>0?1:0);
