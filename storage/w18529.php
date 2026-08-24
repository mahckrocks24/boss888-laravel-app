<?php
require "/var/www/levelup-staging/vendor/autoload.php";
$a = require "/var/www/levelup-staging/bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$ws=2; $taskId=18529;
$t = DB::table('tasks')->where('id',$taskId)->first();
echo "task $taskId: status=".($t->status??'?')." action=".($t->action??'?')." updated=".($t->updated_at??'?')."\n";
if (isset($t->error) && $t->error) echo "  task error: ".substr($t->error,0,300)."\n";
if (isset($t->result) && $t->result) echo "  task result: ".substr($t->result,0,300)."\n";
echo "ws$ws video assets (recent):\n";
foreach (DB::table('assets')->where('workspace_id',$ws)->where('type','video')->orderByDesc('id')->limit(3)->get() as $r){
  echo "  asset id={$r->id} status={$r->status} url=".substr($r->url??'',0,60)." created={$r->created_at}\n";
}
echo "creative_video_jobs (recent ws$ws):\n";
foreach (DB::table('creative_video_jobs')->where('workspace_id',$ws)->orderByDesc('id')->limit(3)->get() as $r){
  echo "  job id={$r->id} provider=".($r->provider??'?')." status=".($r->status??'?')." pjid=".($r->provider_job_id??'')." url=".substr($r->video_url??'',0,40)."\n";
}
$b=app(\App\Core\Billing\CreditService::class)->getBalance($ws);
echo "credits: balance=".($b['balance']??'?')." available=".($b['available']??'?')." reserved=".($b['reserved']??'?')."\n";
// recent credit txns
foreach (DB::table('credit_transactions')->where('workspace_id',$ws)->orderByDesc('id')->limit(4)->get() as $r){
  echo "  txn id={$r->id} type={$r->type} amount={$r->amount} ref={$r->reference_type}/{$r->reference_id}\n";
}
echo "DONE\n";
