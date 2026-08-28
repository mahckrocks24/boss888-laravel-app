<?php
// RISK-0119 proof — drive MediaController::upload directly (fake 'public' disk, scratch ws) with
// spoofed uploads and assert the fix: (A) actual PHP/HTML content is rejected by magic bytes even
// with an image/png client header; (B) a real image with a .php client filename is stored under a
// SAFE (.png) extension — the client extension is never used. No real files written; media rows for
// the scratch workspace are deleted.
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\MediaController;

const WS = 999994;
Storage::fake('public');

$pass = 0; $fail = 0;
function ok(string $l, bool $c, string $d = ''): void { global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l".($d?"  :: $d":'')."\n";} }

function tmpfileWith(string $content, string $suffix): string {
    $p = tempnam(sys_get_temp_dir(), 'r0119') . $suffix;
    file_put_contents($p, $content);
    return $p;
}
function callUpload(UploadedFile $f): array {
    $req = Request::create('/media/upload', 'POST', ['kind' => 'image']);
    $req->files->set('file', $f);
    $req->attributes->set('workspace_id', WS);
    $req->setUserResolver(fn () => null);
    try {
        $resp = app(MediaController::class)->upload($req);
        return ['status' => $resp->getStatusCode(), 'data' => json_decode($resp->getContent(), true) ?: []];
    } catch (\Illuminate\Validation\ValidationException $e) {
        return ['status' => 422, 'data' => ['validation' => $e->errors()]];
    }
}

echo "RISK-0119 — media upload hardening\n";

// A) PHP shell content, spoofed image/png client mime, .php filename -> magic bytes = text/x-php -> REJECT
$php = tmpfileWith('<?php echo "PWN"; ?>', '.bin');
$rA = callUpload(new UploadedFile($php, 'shell.php', 'image/png', null, true));
ok('1 PHP payload (spoofed image/png) is REJECTED', $rA['status'] === 422, 'status=' . $rA['status']);
ok('2 PHP payload did not yield a success url', empty($rA['data']['url']));
@unlink($php);

// B) HTML content, spoofed image/png, .html filename -> magic bytes = text/html -> REJECT
$htmlF = tmpfileWith('<html><body><script>alert(1)</script></body></html>', '.bin');
$rB = callUpload(new UploadedFile($htmlF, 'x.html', 'image/png', null, true));
ok('3 HTML payload (spoofed image/png) is REJECTED', $rB['status'] === 422, 'status=' . $rB['status']);
@unlink($htmlF);

// C) a REAL png but with a .php client filename -> accepted, but stored as .png (client ext ignored)
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$pngF = tmpfileWith($pngBytes, '.bin');
$rC = callUpload(new UploadedFile($pngF, 'evil.php', 'image/png', null, true));
$url = (string) ($rC['data']['url'] ?? '');
ok('4 real PNG accepted (success)', ($rC['data']['success'] ?? false) === true, 'resp=' . json_encode($rC));
ok('5 stored url ends in a SAFE extension (.png), NOT .php', $url !== '' && str_ends_with($url, '.png') && !str_contains($url, '.php'), 'url=' . $url);
@unlink($pngF);

// D) control: a real PNG with a normal name still works
$pngF2 = tmpfileWith($pngBytes, '.bin');
$rD = callUpload(new UploadedFile($pngF2, 'photo.png', 'image/png', null, true));
ok('6 control: normal PNG upload still succeeds', ($rD['data']['success'] ?? false) === true);
ok('7 control: stored as .png', str_ends_with((string) ($rD['data']['url'] ?? ''), '.png'));
@unlink($pngF2);

// cleanup any media rows created for the scratch workspace
$deleted = DB::table('media')->where('workspace_id', WS)->delete();
ok('8 scratch media rows cleaned up', DB::table('media')->where('workspace_id', WS)->count() === 0, "deleted=$deleted");

printf("\n==== %d/%d PASS, %d FAIL ====\n", $pass, $pass + $fail, $fail);
exit($fail === 0 ? 0 : 1);
