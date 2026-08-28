<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\SsrfGuard;
$pass=0;$fail=0; function ok($l,$c){global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}}
echo "SsrfGuard canonical\n";
foreach ([
 'http://169.254.169.254/','http://127.0.0.1/','http://localhost/','http://10.0.0.5/','http://192.168.1.1/',
 'http://172.16.0.1/','http://[::ffff:169.254.169.254]/','http://[fe80::1]/','http://2852039166/','http://0177.0.0.1/',
 'ftp://x/','file:///etc/passwd',
] as $u) ok("block $u", SsrfGuard::isBlockedUrl($u));
foreach (['https://example.com/','http://8.8.8.8/','https://staging.levelupgrowth.io/'] as $u) ok("allow $u", !SsrfGuard::isBlockedUrl($u));
printf("\n==== %d/%d PASS, %d FAIL ====\n%d passed, %d failed\n",$pass,$pass+$fail,$fail,$pass,$fail);
exit($fail===0?0:1);
