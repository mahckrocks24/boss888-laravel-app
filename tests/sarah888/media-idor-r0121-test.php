<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\MediaController;

const A = 999996; const B = 999997;   // caller workspace A ; victim workspace B
$pass=0;$fail=0;
function ok($l,$c,$d=''){global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l".($d?"  :: $d":'')."\n";}}

$cleanup=function(){ DB::table('media')->whereIn('workspace_id',[A,B])->delete(); };
$cleanup();
$mk=function(int $ws,string $source,?string $usedIn) {
    return DB::table('media')->insertGetId([
        'workspace_id'=>$ws,'filename'=>'f.png','path'=>'/uploads/x.png','url'=>'/storage/uploads/x.png',
        'mime_type'=>'image/png','asset_type'=>'image','size_bytes'=>10,'source'=>$source,
        'is_platform_asset'=>0,'is_public'=>0,'used_in'=>$usedIn,'created_at'=>now(),'updated_at'=>now(),
    ]);
};
$ownUpload     = $mk(A,'upload',null);
$foreignUpload = $mk(B,'upload',json_encode(['secretContextB']));
$sharedAsset   = $mk(B,'dalle',null);

$use=function(int $id,string $ctx,int $ws){
    $req=Request::create('/media/use','POST',['media_id'=>$id,'context'=>$ctx]);
    $req->attributes->set('workspace_id',$ws);
    $resp=app(MediaController::class)->use_($req);
    return ['status'=>$resp->getStatusCode(),'data'=>json_decode($resp->getContent(),true)?:[]];
};

echo "RISK-0121 — media use_() tenancy\n";
// own upload -> allowed
$r1=$use($ownUpload,'ctxA',A);
ok('1 own upload -> success', ($r1['data']['success']??false)===true && in_array('ctxA',$r1['data']['used_in']??[],true));

// foreign PRIVATE upload -> BLOCKED (404), and NOT modified, and used_in not leaked
$r2=$use($foreignUpload,'evilCtx',A);
ok('2 foreign private upload -> 404 blocked', $r2['status']===404);
$vic=DB::table('media')->where('id',$foreignUpload)->value('used_in');
ok('3 victim used_in UNCHANGED (evil not appended)', $vic===json_encode(['secretContextB']));
ok('4 victim used_in NOT leaked in response', strpos(json_encode($r2),'secretContextB')===false);

// shared asset (owned by B but a shared source) -> allowed per owner directive
$r3=$use($sharedAsset,'ctxShared',A);
ok('5 shared dalle asset -> success (shared library policy)', ($r3['data']['success']??false)===true);

$cleanup();
ok('6 scratch media cleaned', DB::table('media')->whereIn('workspace_id',[A,B])->count()===0);
printf("\n==== %d/%d PASS, %d FAIL ====\n",$pass,$pass+$fail,$fail);
printf("%d passed, %d failed
", $pass, $fail);
exit($fail===0?0:1);
