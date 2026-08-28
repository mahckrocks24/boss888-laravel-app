<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Engines\Builder\Services\TemplateService;

const WID = 999993;
$dir=storage_path('app/public/sites/'.WID); $idx=$dir.'/index.html'; $hist=$dir.'/.history';
$pass=0;$fail=0; function ok($l,$c,$d=''){global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l".($d?"  :: $d":'')."\n";}}
if(is_dir($dir)){array_map('unlink',glob("$hist/*")?:[]); @rmdir($hist); @unlink($idx); @rmdir($dir);}
@mkdir($dir,0775,true);
file_put_contents($idx,'<!doctype html><html><body><div data-field="hero">ORIGINAL</div></body></html>');
$svc=app(TemplateService::class);

echo "RISK-0107 restore\n";
$svc->updateField(WID,'hero','EDIT1');   // backup #1 holds ORIGINAL
$svc->updateField(WID,'hero','EDIT2');   // backup #2 holds EDIT1
$h=$svc->listHistory(WID);
ok('1 listHistory 2 backups, newest-first', count($h)===2 && strcmp($h[0]['file'],$h[1]['file'])>0, json_encode(array_column($h,'file')));
$origFile=null; foreach($h as $x){ if(strpos(file_get_contents("$hist/{$x['file']}"),'ORIGINAL')!==false){$origFile=$x['file'];break;} }
ok('2 found ORIGINAL backup', $origFile!==null);
$res=$svc->restoreFromHistory(WID,$origFile);
ok('3 restore succeeded', ($res['restored']??false)===true, json_encode($res));
$served=file_get_contents($idx);
ok('4 served reverted to ORIGINAL (EDIT2 gone)', strpos($served,'ORIGINAL')!==false && strpos($served,'EDIT2')===false);
$anyEdit2=false; foreach(glob("$hist/index-*.html")?:[] as $b){ if(strpos(file_get_contents($b),'EDIT2')!==false){$anyEdit2=true;break;} }
ok('5 pre-restore EDIT2 backed up (restore reversible)', $anyEdit2);
$bad=$svc->restoreFromHistory(WID,'../../../../etc/passwd');
ok('6 path traversal rejected', ($bad['restored']??true)===false && ($bad['error']??'')==='invalid_backup');
$bad2=$svc->restoreFromHistory(WID,'index-20260828-000000-zzzz.html');
ok('7 malformed backup name rejected', ($bad2['restored']??true)===false);
$bad3=$svc->restoreFromHistory(WID,'index-20260828-000000-a1b2.html'); // well-formed but nonexistent
ok('8 nonexistent backup -> not_found', ($bad3['restored']??true)===false && ($bad3['error']??'')==='not_found');
array_map('unlink',glob("$hist/*")?:[]); @rmdir($hist); @unlink($idx); @rmdir($dir);
ok('9 cleaned up', !is_dir($dir));
printf("\n==== %d/%d PASS, %d FAIL ====\n",$pass,$pass+$fail,$fail);
exit($fail===0?0:1);
