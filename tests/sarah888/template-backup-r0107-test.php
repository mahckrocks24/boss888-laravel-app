<?php
// RISK-0107 — proof that an inline field-save now preserves the pre-edit served content in a
// rolling .history backup, so a bad template-site edit is recoverable. Uses a scratch site dir it
// creates and deletes. No DB, no real customer site.
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Engines\Builder\Services\TemplateService;

const WID = 999993;                       // scratch website id (no DB row needed; updateField is file-based)
$dir  = storage_path('app/public/sites/' . WID);
$idx  = $dir . '/index.html';
$hist = $dir . '/.history';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, string $d = ''): void { global $pass,$fail; if($c){$pass++;echo "  PASS  $l\n";}else{$fail++;echo "  FAIL  $l".($d?"  :: $d":'')."\n";} }

// clean + seed
if (is_dir($dir)) { array_map('unlink', glob("$hist/*") ?: []); @rmdir($hist); @unlink($idx); @rmdir($dir); }
@mkdir($dir, 0775, true);
file_put_contents($idx, '<!doctype html><html><body><div data-field="hero">ORIGINAL-HERO</div></body></html>');

$svc = app(TemplateService::class);

// 1) first edit: served file updated, pre-edit content backed up
$ok1 = $svc->updateField(WID, 'hero', 'EDIT-ONE');
$served = file_get_contents($idx);
$backups = glob("$hist/index-*.html") ?: [];
ok('1 updateField returned true', $ok1 === true);
ok('2 served file now shows the new value', strpos($served, 'EDIT-ONE') !== false);
ok('3 a .history backup was created', count($backups) === 1);
ok('4 backup holds the PRE-edit content (ORIGINAL-HERO)', count($backups) && strpos(file_get_contents($backups[0]), 'ORIGINAL-HERO') !== false);
ok('5 backup is NOT the post-edit content', count($backups) && strpos(file_get_contents($backups[0]), 'EDIT-ONE') === false);

// 2) second edit -> second backup holding EDIT-ONE (recover chain)
$svc->updateField(WID, 'hero', 'EDIT-TWO');
$backups2 = glob("$hist/index-*.html") ?: [];
$anyHasEditOne = false; foreach ($backups2 as $b) { if (strpos(file_get_contents($b), 'EDIT-ONE') !== false) { $anyHasEditOne = true; break; } }
ok('6 second edit adds a backup (2 total)', count($backups2) === 2);
ok('7 a backup holds EDIT-ONE (prior state recoverable)', $anyHasEditOne);

// 3) prune: drive 12 total edits -> at most 10 backups retained
for ($i = 3; $i <= 12; $i++) { $svc->updateField(WID, 'hero', "EDIT-$i"); }
$backups3 = glob("$hist/index-*.html") ?: [];
ok('8 rolling prune keeps <= 10 backups', count($backups3) <= 10, 'count=' . count($backups3));
ok('9 current served content is the latest edit', strpos(file_get_contents($idx), 'EDIT-12') !== false);

// 4) best-effort: a missing site file returns false, no throw (control)
$ok404 = $svc->updateField(2000111222, 'hero', 'X');
ok('10 updateField on a missing site returns false (no throw)', $ok404 === false);

// cleanup
array_map('unlink', glob("$hist/*") ?: []); @rmdir($hist); @unlink($idx); @rmdir($dir);
ok('11 scratch site dir cleaned up', !is_dir($dir));

printf("\n==== %d/%d PASS, %d FAIL ====\n", $pass, $pass + $fail, $fail);
printf("%d passed, %d failed
", $pass, $fail);
exit($fail === 0 ? 0 : 1);
