<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Engines\SEO\Services\SeoService;
use Illuminate\Support\Facades\DB;

const WS = 999995;
$pass=0;$fail=0;
function ok($l,$c,$d=''){global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l".($d?"  :: $d":'')."\n";}}
$cleanup=function(){foreach(['seo_content_index','seo_images','seo_outbound_links'] as $t){try{DB::table($t)->where('workspace_id',WS)->delete();}catch(\Throwable $e){}}};
$cleanup();
$svc = app(SeoService::class);
echo "RISK-0120 redirect-hop SSRF guard (real redirector)\n";

// direct internal still blocked (in-context)
$r0 = $svc->fetchAndIndexUrl(WS, 'http://169.254.169.254/latest/meta-data/');
ok('1 direct internal URL blocked', ($r0['success'] ?? true) === false);

// public URL (httpbin) that 302-redirects to the metadata address -> the redirect hop must be blocked
$target = 'https://httpbin.org/redirect-to?url=' . urlencode('http://169.254.169.254/latest/meta-data/') . '&status_code=302';
$r = $svc->fetchAndIndexUrl(WS, $target);
$err = (string)($r['error'] ?? '');
echo "  (result: " . json_encode($r) . ")\n";
if (strpos($err, 'blocked_redirect') !== false) {
    ok('2 redirect to internal blocked BY THE GUARD', true);
} elseif (($r['success'] ?? false) === false && stripos($err, 'httpbin') !== false) {
    echo "  SKIP  2 httpbin unreachable — cannot exercise redirect (guard correctness unverified live)\n";
} else {
    ok('2 redirect to internal blocked BY THE GUARD', false, $err);
}
ok('3 no metadata leaked', strpos(json_encode($r), 'meta-data') === false || ($r['success'] ?? true) === false);
$cleanup();
ok('4 scratch rows cleaned', DB::table('seo_content_index')->where('workspace_id',WS)->count() === 0);
printf("\n==== %d/%d PASS, %d FAIL ====\n",$pass,$pass+$fail,$fail);
exit($fail===0?0:1);
