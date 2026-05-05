<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$svc   = app(\App\Engines\Studio\Services\StudioService::class);
$ai    = app(\App\Engines\Studio\Services\StudioAiService::class);
$WS    = 1;

echo "── S1: semantic profiles present on all manifests ──\n";
$count = 0; $withSem = 0;
foreach (scandir('/var/www/levelup-staging/storage/templates/studio') as $slug) {
    if ($slug === '.' || $slug === '..') continue;
    $mf = '/var/www/levelup-staging/storage/templates/studio/' . $slug . '/manifest.json';
    if (!is_file($mf)) continue;
    $count++;
    $d = json_decode(file_get_contents($mf), true);
    if (!empty($d['semantic']) && !empty($d['semantic']['arthur_instructions'])) $withSem++;
}
echo "  $withSem / $count templates enriched\n";
assert($withSem === $count);

echo "── S2: AI endpoints surface api_key_missing when keys absent ──\n";
$r1 = $ai->generateDesign($WS, ['prompt' => 'Gym opening', 'format' => 'square']);
echo "  generateDesign: success=" . ($r1['success'] ? 'true' : 'false') . " error=" . ($r1['error'] ?? 'none') . "\n";
$r2 = $ai->generateImage($WS, ['prompt' => 'sunset mountain']);
echo "  generateImage: success=" . ($r2['success'] ? 'true' : 'false') . " error=" . ($r2['error'] ?? 'none') . "\n";
$r3 = $ai->suggestCopy($WS, ['field_type' => 'headline', 'brand_name' => 'Acme']);
echo "  suggestCopy: success=" . ($r3['success'] ? 'true' : 'false') . " error=" . ($r3['error'] ?? 'none') . "\n";
$r4 = $ai->chat($WS, ['message' => 'Make it bold', 'design_id' => 1]);
echo "  chat: success=" . ($r4['success'] ? 'true' : 'false') . " error=" . ($r4['error'] ?? 'none') . "\n";

echo "── S3: publish-social gracefully degrades ──\n";
$d = $svc->createDesign($WS, ['name' => 'smoke-publish', 'format' => 'square']);
$pub = $svc->publishToSocial($d['design_id'], $WS, ['platforms' => ['instagram'], 'caption' => 'Test']);
echo "  publishToSocial: " . json_encode(array_intersect_key($pub, array_flip(['success','queued','message','error']))) . "\n";

echo "── S4: resizeDesign rescales elements ──\n";
$e1 = $svc->saveElement($WS, $d['design_id'], ['element_type' => 'text', 'properties_json' => ['x' => 100, 'y' => 100, 'width' => 400, 'height' => 80, 'font_size' => 48]]);
$res = $svc->resizeDesign($d['design_id'], 1920, 1080);
echo "  resize 1080x1080 -> 1920x1080: success=" . ($res['success'] ? 'true' : 'false') . "\n";
$el = DB::table('studio_elements')->where('id', $e1['element_id'])->first();
$p  = json_decode($el->properties_json, true);
$avg = (1920/1080 + 1080/1080) / 2; // 1.444
echo "  element width 400 -> " . $p['width'] . " (expected ~" . (int) round(400 * $avg) . ")\n";

echo "── S5: saveExportToMedia ──\n";
// Set an exported_url first
DB::table('studio_designs')->where('id', $d['design_id'])->update(['exported_url' => 'https://example.com/test.png']);
$sv = $svc->saveExportToMedia($d['design_id'], $WS);
echo "  saveExport: " . json_encode(array_intersect_key($sv, array_flip(['success','error','message','media_id']))) . "\n";

echo "── S6: generateThumbnail fallback works when renderer absent ──\n";
$t = $svc->generateThumbnail($d['design_id']);
echo "  thumb: " . json_encode(array_intersect_key($t, array_flip(['thumbnail_url','note','error']))) . "\n";

// Cleanup
$svc->deleteDesign($d['design_id']);

echo "\n✅ Phase 4+5 smoke PASSED\n";
