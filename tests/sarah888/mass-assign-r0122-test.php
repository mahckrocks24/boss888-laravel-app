<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

$pass=0;$fail=0;
function ok($l,$c,$d=''){global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l".($d?"  :: $d":'')."\n";}}
$emails=['r0122a@scratch.test','r0122b@scratch.test','r0122c@scratch.test'];
DB::table('users')->whereIn('email',$emails)->delete();

echo "RISK-0122 — is_platform_admin mass-assignment guard\n";

// 1) mass-assign attempt via create array -> silently ignored, user is NOT platform admin
try { $u = User::create(['name'=>'x','email'=>$emails[0],'password'=>Hash::make('x'),'is_platform_admin'=>true]); $u->refresh();
  ok('1 create([...is_platform_admin=>true]) does NOT set it', ! $u->is_platform_admin, 'val='.var_export($u->is_platform_admin,true));
} catch (\Throwable $e) { ok('1 mass-assign rejected (threw '.get_class($e).')', true); }

// 2) fill()/update() with the key also blocked
$u2 = User::create(['name'=>'y','email'=>$emails[1],'password'=>Hash::make('y')]);
$u2->fill(['is_platform_admin'=>true]); $u2->save(); $u2->refresh();
ok('2 fill([is_platform_admin]) does NOT set it', ! $u2->is_platform_admin);

// 3) forceFill (the privileged path) DOES set it
$u2->forceFill(['is_platform_admin'=>true])->save(); $u2->refresh();
ok('3 forceFill DOES set is_platform_admin (privileged path intact)', (bool)$u2->is_platform_admin === true);

// 4) registration-shape data is safe (explicit fields; extra key ignored)
$u3 = User::create(['name'=>'z','email'=>$emails[2],'password'=>Hash::make('z')]);
ok('4 normal registration user is not admin', ! $u3->is_platform_admin);

DB::table('users')->whereIn('email',$emails)->delete();
ok('5 scratch users cleaned', DB::table('users')->whereIn('email',$emails)->count()===0);
printf("\n==== %d/%d PASS, %d FAIL ====\n",$pass,$pass+$fail,$fail);
printf("%d passed, %d failed
", $pass, $fail);
exit($fail===0?0:1);
