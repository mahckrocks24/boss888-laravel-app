<?php
/**
 * SARAH888 — turn-2 binding: the full cross-binding battery at unit level.
 *
 * One pending, multiple pending, bare yes, specific yes, expired, changed cost,
 * changed scope, changed entity, other conversation, other workspace, and
 * cancellation before approval.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\{AuthorizationBinder as AB, ChatActionProposal};
use App\Core\Orchestration\ProactiveStrategyEngine;

$WS = 999919; $WS2 = 999920;
foreach ([$WS,$WS2] as $w) {
  DB::table('workspaces')->updateOrInsert(['id'=>$w], ['name'=>"binder $w",'slug'=>"binder-$w",
    'timezone'=>'UTC','created_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
}
// Approval reserves credits for real, so a workspace with an empty wallet fails
// at the wallet and proves nothing about binding. Seeded deliberately: the paid
// path is the one that matters.
foreach ([$WS,$WS2] as $w) {
  DB::table('credits')->updateOrInsert(['workspace_id'=>$w],
    ['balance'=>1000, 'reserved_balance'=>0, 'created_at'=>now(), 'updated_at'=>now()]);
}
$clean = function() use ($WS,$WS2) {
  DB::table('approvals')->whereIn('workspace_id',[$WS,$WS2])->delete();
  DB::table('strategy_proposals')->whereIn('workspace_id',[$WS,$WS2])->delete();
  DB::table('tasks')->whereIn('workspace_id',[$WS,$WS2])->delete();
};
$clean();

$pass=0;$fail=0;
function ok(string $l,bool $c,string $d=''):void{global $pass,$fail;
  if($c){$pass++;printf("  PASS  %s\n",$l);}else{$fail++;printf("  FAIL  %s  %s\n",$l,$d);} }

$b = app(AB::class);
$p = app(ChatActionProposal::class);
$CONV = 'conv-B1';

$offer = function(string $desc, int $entityId, int $cost = 5, string $conv = 'conv-B1') use ($p, $WS) {
    return $p->propose($WS, ['engine'=>'write','action'=>'publish_article','source'=>'agent',
        'category'=>'publish','credit_cost'=>$cost,'description'=>$desc,
        'payload'=>['article_id'=>$entityId]],
        ['conversation_id'=>$conv,'execution_id'=>'exec-'.$entityId,'user_message_id'=>1]);
};
$tasks = fn() => DB::table('tasks')->where('workspace_id',$WS)->count();

echo "=== a turn that is not an authorization does nothing ===\n";
$clean(); $offer('publish the wholesale page', 4242);
foreach (['How many articles are published?','Tell me about the wholesale page.','Thanks.'] as $m) {
    $r = $b->bind($WS, $CONV, $m);
    ok('not an authorization: "' . $m . '"', $r['outcome'] === AB::NOT_AUTHORIZATION, $r['outcome']);
}
ok('and nothing ran', $tasks() === 0);

echo "\n=== ONE pending + bare yes -> binds to exactly that offer ===\n";
$r = $b->bind($WS, $CONV, 'Yes, go ahead.');
ok('authorized',                 $r['outcome'] === AB::AUTHORIZED, $r['outcome'] . ' ' . $r['reason']);
ok('bound to the right offer',   (string)($r['proposal']->entity_id ?? '') === '4242');
ok('still nothing created yet',  $tasks() === 0);

$res = app(ProactiveStrategyEngine::class)->approveProposal($WS, 1, (int)$r['proposal']->id);
ok('the engine then creates exactly one task', ($res['success'] ?? false) === true && $tasks() === 1,
   json_encode($res));
ok('and it is the action that was offered',
   DB::table('tasks')->where('workspace_id',$WS)->value('action') === 'publish_article');

echo "\n=== nothing pending + yes -> refuses, says so, runs nothing ===\n";
$clean();
$r = $b->bind($WS, $CONV, 'Yes, do it.');
ok('none_pending',            $r['outcome'] === AB::NONE_PENDING, $r['outcome']);
ok('tells the owner plainly', str_contains($r['message'], "nothing waiting for your approval"));
ok('nothing ran',             $tasks() === 0);

echo "\n=== MULTIPLE pending + bare yes -> ambiguous, runs nothing ===\n";
$clean();
$a1 = $offer('publish the wholesale page', 4242);
$a2 = $offer('publish the stockist directory', 5150);
$r  = $b->bind($WS, $CONV, 'Yes, proceed.');
ok('ambiguous',              $r['outcome'] === AB::AMBIGUOUS, $r['outcome']);
ok('both are disclosed',     $r['pending'] === 2);
ok('the message lists them', str_contains($r['message'],'wholesale') && str_contains($r['message'],'stockist'), $r['message']);
ok('and it states the total',str_contains($r['message'],'10 credits'), $r['message']);
ok('nothing ran',            $tasks() === 0);

echo "\n=== MULTIPLE pending + a SPECIFIC yes -> binds to the named one only ===\n";
$r = $b->bind($WS, $CONV, 'Yes, publish the stockist directory.');
ok('authorized',              $r['outcome'] === AB::AUTHORIZED, $r['outcome']);
ok('bound to the named offer',(string)($r['proposal']->entity_id ?? '') === '5150',
   (string)($r['proposal']->entity_id ?? ''));
ok('not the other one',       (int)$r['proposal']->id === (int)$a2['proposal_id']);

echo "\n=== naming it by entity id also works ===\n";
$r = $b->bind($WS, $CONV, 'Yes, go ahead with 4242.');
ok('bound by id', $r['outcome'] === AB::AUTHORIZED && (int)$r['proposal']->id === (int)$a1['proposal_id'],
   $r['outcome']);

echo "\n=== EXPIRED -> refused with a reason, runs nothing ===\n";
$clean(); $e = $offer('publish the winter range', 6001);
DB::table('strategy_proposals')->where('id',$e['proposal_id'])->update(['expires_at'=>now()->subMinute()]);
$r = $b->bind($WS, $CONV, 'Yes, go ahead.');
ok('treated as nothing pending once expired', in_array($r['outcome'],[AB::NONE_PENDING,AB::REFUSED],true), $r['outcome']);
ok('and says nothing was charged', str_contains(mb_strtolower($r['message']),'nothing'), $r['message']);
ok('nothing ran', $tasks() === 0);

echo "\n=== CHANGED COST -> refused, runs nothing ===\n";
// The quoted price (total_credits, what the owner was told) drifts away from
// the payload's credit_cost (what would actually run).
$clean(); $c = $offer('publish the trade page', 7001, 5);
DB::table('strategy_proposals')->where('id',$c['proposal_id'])->update(['total_credits'=>50]);
$chk = $p->authorize($WS, $CONV, (int)$c['proposal_id'], ['credit_cost'=>5]);
ok('a yes quoted at the OLD price is refused',
   $chk['ok'] === false && $chk['reason'] === ChatActionProposal::AUTH_COST_CHANGED, $chk['reason']);
$r = $b->bind($WS, $CONV, 'Yes, go ahead.');
ok('and the binder refuses it too',
   $r['outcome'] === AB::REFUSED && $r['reason'] === ChatActionProposal::AUTH_COST_CHANGED,
   $r['outcome'] . '/' . $r['reason']);
ok('it tells the owner the price moved', str_contains($r['message'], 'cost has changed'), $r['message']);
ok('nothing ran', $tasks() === 0);

echo "\n=== CHANGED SCOPE -> refused, runs nothing ===\n";
$clean(); $s = $offer('publish the autumn lookbook', 8001);
DB::table('strategy_proposals')->where('id',$s['proposal_id'])->update(['scope_hash'=>str_repeat('a',64)]);
$r = $b->bind($WS, $CONV, 'Yes, go ahead.');
ok('refused',                  $r['outcome'] === AB::REFUSED, $r['outcome']);
ok('reason is scope',          $r['reason'] === ChatActionProposal::AUTH_SCOPE_CHANGED, $r['reason']);
ok('explains it did not run',  str_contains(mb_strtolower($r['message']),'nothing has run'), $r['message']);
ok('nothing ran',              $tasks() === 0);

echo "\n=== CHANGED ENTITY -> the offer cannot be approved for a different item ===\n";
$clean(); $x = $offer('publish the spring guide', 9001);
$chk = $p->authorize($WS, $CONV, (int)$x['proposal_id'], ['entity_id'=>'9999']);
ok('refused on entity', $chk['ok'] === false && $chk['reason'] === ChatActionProposal::AUTH_ENTITY_CHANGED,
   $chk['reason']);

echo "\n=== OTHER CONVERSATION cannot approve it ===\n";
$r = $b->bind($WS, 'conv-ELSEWHERE', 'Yes, go ahead.');
ok('sees nothing pending elsewhere', $r['outcome'] === AB::NONE_PENDING, $r['outcome']);
ok('nothing ran',                    $tasks() === 0);

echo "\n=== OTHER WORKSPACE cannot approve it ===\n";
$r = $b->bind($WS2, $CONV, 'Yes, go ahead.');
ok('sees nothing pending in another workspace', $r['outcome'] === AB::NONE_PENDING, $r['outcome']);
ok('nothing ran in either workspace',
   $tasks() === 0 && DB::table('tasks')->where('workspace_id',$WS2)->count() === 0);

echo "\n=== CANCELLATION before approval ===\n";
$clean(); $k = $offer('publish the clearance page', 9500);
$r = $b->bind($WS, $CONV, "No, don't publish it.");
ok('cancelled',            $r['outcome'] === AB::CANCELLED, $r['outcome']);
ok('says nothing charged', str_contains($r['message'],'nothing was created or charged'), $r['message']);
ok('the offer is withdrawn',
   DB::table('strategy_proposals')->where('id',$k['proposal_id'])->value('status') === 'rejected');
ok('the approval row is withdrawn too',
   DB::table('approvals')->where('proposal_id',$k['proposal_id'])->value('status') === 'rejected');
ok('nothing ran', $tasks() === 0);

echo "\n=== and a later yes cannot revive a cancelled offer ===\n";
$r = $b->bind($WS, $CONV, 'Yes, go ahead.');
ok('nothing left to authorise', $r['outcome'] === AB::NONE_PENDING, $r['outcome']);
ok('nothing ran',               $tasks() === 0);

echo "\n=== a yes naming a DIFFERENT item does not bind to the one on offer ===\n";
$clean(); $offer('publish the wholesale page', 4242);
$r = $b->bind($WS, $CONV, 'Yes, publish 9876.');
ok('refused rather than mis-bound', $r['outcome'] === AB::REFUSED, $r['outcome']);
ok('reason is entity',              $r['reason'] === ChatActionProposal::AUTH_ENTITY_CHANGED, $r['reason']);
ok('nothing ran',                   $tasks() === 0);
ok('and the offer is still pending, not consumed',
   DB::table('strategy_proposals')->where('workspace_id',$WS)->value('status') === 'pending_approval');

echo "\n=== a bare affirmative with NOTHING pending stays an ordinary turn ===\n";
$clean();
$r = $b->bind($WS, $CONV, 'Yes, publish the wholesale page.');
ok('not silently treated as approval', in_array($r['outcome'],[AB::NOT_AUTHORIZATION,AB::NONE_PENDING],true), $r['outcome']);
ok('nothing ran', $tasks() === 0);

echo "\n=== challenging an approval is not granting one ===\n";
$clean(); $offer('publish the wholesale page', 4242);
$r = $b->bind($WS, $CONV, 'You approved that already. On what authority?');
ok('a challenge does not authorise', $r['outcome'] === AB::NOT_AUTHORIZATION, $r['outcome']);
ok('nothing ran',                    $tasks() === 0);

$clean();
DB::table('workspaces')->whereIn('id',[$WS,$WS2])->delete();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail>0?1:0);
