<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$svc = app(\App\Engines\Studio\Services\StudioService::class);
$WS = 1;

echo "── S1: DB tables exist ──\n";
foreach (['studio_elements','studio_brand_kits','studio_design_history'] as $t) {
    $exists = \Schema::hasTable($t);
    echo "  $t: " . ($exists ? 'OK' : 'MISSING') . "\n";
    assert($exists);
}
echo "  studio_designs.background_type column: " . (\Schema::hasColumn('studio_designs', 'background_type') ? 'OK' : 'MISSING') . "\n";
echo "  studio_designs.tags_json column: " . (\Schema::hasColumn('studio_designs', 'tags_json') ? 'OK' : 'MISSING') . "\n";

echo "── S2: formats catalog ──\n";
$f = $svc->getFormats();
echo "  " . count($f['formats']) . " formats: " . implode(', ', array_column($f['formats'], 'slug')) . "\n";
assert(count($f['formats']) >= 7);

echo "── S3: fonts catalog ──\n";
$fn = $svc->getFonts();
echo "  " . count($fn['fonts']) . " fonts\n";
assert(count($fn['fonts']) === 20);

echo "── S4: createDesign → getDesign → updateDesign → duplicate → delete ──\n";
$created = $svc->createDesign($WS, ['name'=>'smoke design', 'format'=>'story']);
$id = $created['design_id'];
echo "  created id=$id\n";
$got = $svc->getDesign($id);
echo "  getDesign: name={$got['design']['name']} format={$got['design']['format']} canvas={$got['design']['canvas_width']}x{$got['design']['canvas_height']}\n";
assert($got['design']['format'] === 'story');
assert($got['design']['canvas_width'] === 1080);
assert($got['design']['canvas_height'] === 1920);

$svc->updateDesign($id, ['name' => 'smoke design renamed', 'background_value' => '#FF00FF']);
$got2 = $svc->getDesign($id);
assert($got2['design']['name'] === 'smoke design renamed');
assert($got2['design']['background_value'] === '#FF00FF');
echo "  updateDesign: OK\n";

$dup = $svc->duplicateDesign($id, $WS);
echo "  duplicate: new id=" . $dup['design_id'] . "\n";
$dupGot = $svc->getDesign($dup['design_id']);
assert(str_ends_with($dupGot['design']['name'], '(copy)'));

$svc->deleteDesign($id);
$svc->deleteDesign($dup['design_id']);
echo "  deleted both\n";

echo "── S5: elements CRUD + reorder ──\n";
$d = $svc->createDesign($WS, ['name'=>'smoke elems', 'format'=>'square']);
$did = $d['design_id'];
$e1 = $svc->saveElement($WS, $did, ['element_type'=>'text', 'properties_json'=>['content'=>'Hello','x'=>10,'y'=>20]]);
$e2 = $svc->saveElement($WS, $did, ['element_type'=>'shape', 'properties_json'=>['shape_type'=>'rectangle','fill'=>'#FF0000']]);
$e3 = $svc->saveElement($WS, $did, ['element_type'=>'image', 'properties_json'=>['src_url'=>'https://example.com/x.png']]);
$els = $svc->getElements($did);
echo "  3 elements: types=" . implode(',', array_column($els, 'element_type')) . " orders=" . implode(',', array_column($els, 'layer_order')) . "\n";
assert(count($els) === 3);

$svc->updateElement($e2['element_id'], ['properties_json' => ['shape_type'=>'circle','fill'=>'#00FF00']]);
$els2 = $svc->getElements($did);
$circle = array_filter($els2, fn($e) => $e['id'] == $e2['element_id']);
$circle = array_values($circle)[0];
echo "  updated shape: shape_type=" . $circle['properties_json']['shape_type'] . " fill=" . $circle['properties_json']['fill'] . "\n";
assert($circle['properties_json']['shape_type'] === 'circle');

$svc->reorderElements($did, [$e3['element_id'], $e1['element_id'], $e2['element_id']]);
$els3 = $svc->getElements($did);
echo "  reordered: types in new order=" . implode(',', array_column($els3, 'element_type')) . "\n";
assert($els3[0]['id'] === $e3['element_id']);

$svc->deleteElement($e1['element_id']);
$els4 = $svc->getElements($did);
assert(count($els4) === 2);
echo "  deleteElement: remaining count=" . count($els4) . "\n";

echo "── S6: brand kit ──\n";
$bk = $svc->getBrandKit($WS);
echo "  defaults: primary={$bk['primary_color']} heading={$bk['heading_font']} body={$bk['body_font']}\n";
assert($bk['primary_color'] === '#6C5CE7');
assert($bk['heading_font']  === 'Syne');

$svc->updateBrandKit($WS, ['primary_color' => '#FF00FF', 'brand_name' => 'Smoke Brand']);
$bk2 = $svc->getBrandKit($WS);
echo "  updated: primary={$bk2['primary_color']} brand_name={$bk2['brand_name']}\n";
assert($bk2['primary_color'] === '#FF00FF');
assert($bk2['brand_name'] === 'Smoke Brand');

// Restore
$svc->updateBrandKit($WS, ['primary_color' => '#6C5CE7', 'brand_name' => null]);

echo "── S7: history snapshots (cap 50) ──\n";
$svc->saveHistory($did, ['step' => 1, 'blocks' => []]);
$svc->saveHistory($did, ['step' => 2, 'blocks' => [['x'=>1]]]);
$svc->saveHistory($did, ['step' => 3, 'blocks' => [['x'=>1],['x'=>2]]]);
$h = $svc->getHistory($did);
echo "  stored " . count($h['history']) . " snapshots\n";
assert(count($h['history']) === 3);
assert($h['history'][0]['snapshot']['step'] === 3); // most recent first

echo "── S8: thumbnail placeholder (valid PNG) ──\n";
$thumb = $svc->generateThumbnail($did);
echo "  thumbnail_url=" . ($thumb['thumbnail_url'] ?? 'NULL') . "\n";
$path = '/var/www/levelup-staging/storage/app/public/studio-thumbs/design-' . $did . '.png';
$ok = file_exists($path) && strpos(file_get_contents($path), "\x89PNG") === 0;
echo "  PNG valid: " . ($ok ? 'yes' : 'NO') . " (" . (file_exists($path) ? filesize($path) : 0) . " bytes)\n";
assert($ok);

// Cleanup
$svc->deleteDesign($did);

echo "\n✅ Studio Phase 1 smoke PASSED\n";
