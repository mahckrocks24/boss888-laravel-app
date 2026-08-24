<?php
/**
 * SARAH888 — ConfirmationClaimGuard.
 *
 * The five phrasings at the top are the exact replies measured on the live path
 * on 2026-08-09, each of which promised execution on confirmation while creating
 * nothing. They are the regression this guard exists to prevent.
 */
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\{ConfirmationClaimGuard, ChatActionProposal};

$WS = 999918;
DB::table('workspaces')->updateOrInsert(['id'=>$WS], ['name'=>'claimguard','slug'=>'claimguard-probe',
  'timezone'=>'UTC','created_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
$clean = function() use ($WS) {
  DB::table('approvals')->where('workspace_id',$WS)->delete();
  DB::table('strategy_proposals')->where('workspace_id',$WS)->delete();
};
$clean();

$pass=0;$fail=0;
function ok(string $l,bool $c,string $d=''):void{global $pass,$fail;
  if($c){$pass++;printf("  PASS  %s\n",$l);}else{$fail++;printf("  FAIL  %s  %s\n",$l,$d);} }

$g = app(ConfirmationClaimGuard::class);
$p = app(ChatActionProposal::class);
$CONV = 'conv-G';

echo "=== the five live replies, with NOTHING pending ===\n";
$live = [
  'Please confirm that you want to publish the wholesale page now, and I will handle the task immediately.',
  'Please confirm that you want to publish the winter range article now, and I will handle the task immediately.',
  'Shall I publish the trade landing page?',
  'Would you like me to publish the stockist page?',
  'Approve this and I\'ll proceed with publishing the autumn lookbook.',
];
foreach ($live as $i => $reply) {
    $r = $g->validate($reply, $WS, $CONV);
    ok('reply ' . ($i+1) . ' is caught as unbound', $r['fired'] === true && $r['state'] === 'unbound', $r['state']);
    ok('reply ' . ($i+1) . ' no longer promises execution on confirmation',
       !preg_match('/\bplease\s+confirm\b|\bshall\s+i\b|\bwould\s+you\s+like\s+me\s+to\b/i', $r['reply']),
       $r['reply']);
    ok('reply ' . ($i+1) . ' states the truth instead',
       str_contains($r['reply'], 'nothing is waiting for your approval'), $r['reply']);
}

echo "\n=== read-only confirmations are left completely alone ===\n";
foreach ([
  'Shall I explain that in more detail?',
  'Would you like me to summarise the last quarter?',
  'Do you want me to walk through the numbers again?',
  'Please confirm which of the two reports you meant.',
  'Shall I pull the figures for August or September?',
] as $benign) {
    $r = $g->validate($benign, $WS, $CONV);
    ok('untouched: "' . mb_substr($benign,0,42) . '..."',
       $r['fired'] === false && $r['reply'] === $benign && $r['state'] === 'not_applicable', $r['state']);
}

echo "\n=== statements that commit nothing are left alone ===\n";
foreach ([
  'I have published the wholesale page.',
  'The newsletter went out this morning.',
  'Three articles are scheduled for next week.',
  'I cannot delete that for you.',
] as $plain) {
    $r = $g->validate($plain, $WS, $CONV);
    ok('untouched: "' . mb_substr($plain,0,38) . '..."', $r['fired'] === false && $r['reply'] === $plain);
}

echo "\n=== surrounding context survives; only the false promise goes ===\n";
$mixed = 'The wholesale page is finished and reads well. '
       . 'Please confirm that you want to publish it now, and I will handle it immediately. '
       . 'It has 1,200 words and a featured image.';
$r = $g->validate($mixed, $WS, $CONV);
ok('the first sentence survives',  str_contains($r['reply'], 'finished and reads well'), $r['reply']);
ok('the last sentence survives',   str_contains($r['reply'], '1,200 words'), $r['reply']);
ok('the promise is gone',          !str_contains(mb_strtolower($r['reply']), 'please confirm'), $r['reply']);

echo "\n=== WITH a real pending authorization, the promise stands and the cost is disclosed ===\n";
$clean();
$p->propose($WS, ['engine'=>'write','action'=>'publish_article','source'=>'agent',
                  'category'=>'publish','credit_cost'=>5,
                  'description'=>'publish the wholesale page',
                  'payload'=>['article_id'=>4242]],
            ['conversation_id'=>$CONV,'execution_id'=>'exec-G','user_message_id'=>5]);

$r = $g->validate('Please confirm that you want to publish the wholesale page now, and I will handle it immediately.', $WS, $CONV);
ok('state is bound',                     $r['state'] === 'bound', $r['state']);
ok('the promise is NOT rewritten',       str_contains(mb_strtolower($r['reply']), 'please confirm'), $r['reply']);
// P1-3: the guard records the disclosure, TurnStatusEmitter delivers it.
// Asserted on the FINAL reply, which is what the owner actually reads.
$emitter = app(\App\Core\Sarah888\TurnStatusEmitter::class);
$final   = $emitter->emit($WS, $CONV, $r['reply']);
ok('the pending item is disclosed',      str_contains($final, 'Awaiting your approval'), $final);
ok('the cost is stated',                 str_contains($final, '5 credits'), $final);
ok('and it says nothing was charged',    str_contains($final, 'Nothing has been created or charged yet'), $final);
ok('and it is disclosed exactly ONCE',   substr_count($final, 'Awaiting your approval') === 1, $final);
$emitter->reset();

echo "\n=== a pending item in ANOTHER conversation does not rescue the promise ===\n";
$r = $g->validate('Shall I publish the wholesale page?', $WS, 'conv-OTHER');
ok('still unbound in a different conversation', $r['state'] === 'unbound', $r['state']);

echo "\n=== an EXPIRED pending item does not rescue it either ===\n";
DB::table('strategy_proposals')->where('workspace_id',$WS)->update(['expires_at'=>now()->subMinute()]);
$r = $g->validate('Shall I publish the wholesale page?', $WS, $CONV);
ok('an expired offer cannot back a promise', $r['state'] === 'unbound', $r['state']);

echo "\n=== typographic apostrophes must not slip past ===\n";
$curly = "Approve this and I\u{2019}ll proceed with publishing the autumn lookbook.";
$r = $g->validate($curly, $WS, $CONV);
ok('curly apostrophe still caught', $r['fired'] === true && $r['state'] === 'unbound', $r['state']);

echo "\n=== disclosure is not appended twice ===\n";
$clean();
$p->propose($WS, ['engine'=>'write','action'=>'publish_article','source'=>'agent',
                  'category'=>'publish','credit_cost'=>5,'description'=>'publish it',
                  'payload'=>['article_id'=>1]],
            ['conversation_id'=>$CONV,'execution_id'=>'exec-H']);
$once  = $g->validate('Shall I publish it?', $WS, $CONV)['reply'];
$twice = $g->validate($once, $WS, $CONV)['reply'];
ok('a reply already carrying disclosure is left as-is', $once === $twice);

$clean();
DB::table('workspaces')->where('id',$WS)->delete();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail>0?1:0);
