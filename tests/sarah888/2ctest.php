<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Core\Sarah888\ChatActionProposal;

$WS=999917;
DB::table('workspaces')->updateOrInsert(['id'=>$WS],['name'=>'disclose','slug'=>'disclose-probe',
 'timezone'=>'UTC','created_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
$clean=function() use ($WS){
  DB::table('approvals')->where('workspace_id',$WS)->delete();
  DB::table('strategy_proposals')->where('workspace_id',$WS)->delete(); };
$clean();

$p=app(ChatActionProposal::class);
$pass=0;$fail=0;
function ok(string $l,bool $c,string $d=''):void{global $pass,$fail;
  if($c){$pass++;printf("  PASS  %s\n",$l);}else{$fail++;printf("  FAIL  %s  %s\n",$l,$d);} }

echo "=== nothing pending -> no disclosure ===\n";
ok('empty string when nothing is pending', $p->disclosure($WS,'c1') === '');

echo "\n=== one pending proposal states its cost ===\n";
$p->propose($WS,['engine'=>'write','action'=>'publish_article','credit_cost'=>5,
  'description'=>'publish the wholesale page'],['conversation_id'=>'c1']);
$d1=$p->disclosure($WS,'c1');
echo "  -> " . trim($d1) . "\n";
ok('states the credit cost', str_contains($d1,'5 credits'), $d1);
ok('says nothing has been charged', str_contains($d1,'charged'));
ok('names the action', str_contains($d1,'wholesale page'));

echo "\n=== a free action says so rather than omitting cost ===\n";
$clean();
$p->propose($WS,['engine'=>'seo','action'=>'fix_orphans','credit_cost'=>0,
  'description'=>'fix the orphan pages'],['conversation_id'=>'c1']);
$d2=$p->disclosure($WS,'c1');
echo "  -> " . trim($d2) . "\n";
ok('discloses zero cost explicitly', str_contains($d2,'no credit cost'), $d2);

echo "\n=== several pending -> total stated, and 'yes' declared ambiguous ===\n";
$clean();
$p->propose($WS,['engine'=>'write','action'=>'publish_article','credit_cost'=>5,
  'description'=>'publish the wholesale page'],['conversation_id'=>'c1']);
$p->propose($WS,['engine'=>'creative','action'=>'generate_image','credit_cost'=>3,
  'description'=>'generate the hero image'],['conversation_id'=>'c1']);
$d3=$p->disclosure($WS,'c1');
echo "  -> " . trim($d3) . "\n";
ok('states the combined total', str_contains($d3,'8 credits in total'), $d3);
ok('lists both items', str_contains($d3,'wholesale page') && str_contains($d3,'hero image'));
ok('declares a bare yes ambiguous', str_contains($d3,'ambiguous'));
ok('and resolveAuthorization agrees', $p->resolveAuthorization($WS,'c1') === null);

echo "\n=== scoped to the conversation ===\n";
ok('another conversation sees nothing', $p->disclosure($WS,'c2') === '');

$clean(); DB::table('workspaces')->where('id',$WS)->delete();
printf("\n%d passed, %d failed\n",$pass,$fail);
exit($fail>0?1:0);
