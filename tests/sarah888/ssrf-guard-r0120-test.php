<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Engines\SEO\Services\SeoService;

$svc = app(SeoService::class);
$m = new ReflectionMethod($svc, 'isBlockedFetchUrl'); $m->setAccessible(true);
$blocked = fn(string $u) => (bool) $m->invoke($svc, $u);

$pass=0;$fail=0;
function ok($l,$c){global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l\n";}}

echo "RISK-0120 — SSRF guard on fetchAndIndexUrl\n";
// must BLOCK
foreach ([
  'http://169.254.169.254/metadata/v1/','http://127.0.0.1/','http://localhost/admin',
  'http://10.0.0.5/','http://192.168.1.1/','http://172.16.0.1/','http://172.31.255.1/',
  'ftp://example.com/x','file:///etc/passwd','http://[::1]/','gopher://x/','http://0.0.0.0/',
] as $u) { ok("block $u", $blocked($u)); }
// must ALLOW (public)
foreach ([
  'https://staging.levelupgrowth.io/','http://example.com/','https://1.1.1.1/','http://8.8.8.8/',
] as $u) { ok("allow $u", ! $blocked($u)); }

printf("\n==== %d/%d PASS, %d FAIL ====\n",$pass,$pass+$fail,$fail);
printf("%d passed, %d failed
", $pass, $fail);
exit($fail===0?0:1);
