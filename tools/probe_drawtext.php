<?php
$root = realpath(__DIR__ . '/..');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$did = (int)($argv[1] ?? 66);
$fieldPat = $argv[2] ?? 'stat_3_value';

$row = Illuminate\Support\Facades\DB::table('studio_designs')->where('id', $did)->first();
$data = json_decode($row->video_data, true);

$job = new App\Jobs\RenderStudioVideoJob($did);
$ref = new ReflectionClass($job);
$m = $ref->getMethod('buildDrawtext');
$m->setAccessible(true);
$rm = $ref->getMethod('applyElementTypeDefaults');
$rm->setAccessible(true);

foreach ($data['text_overlays'] ?? [] as $t) {
    if (strpos($t['id'] ?? '', $fieldPat) === false) continue;
    $tWithDefaults = $rm->invoke($job, $t);
    $drawtext = $m->invoke($job, $t, 1080, 1920, '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf');
    echo "──── {$t['id']} ────\n";
    echo "content: " . var_export($t['content'], true) . "\n";
    echo "element_type: " . ($t['element_type'] ?? 'none') . "\n";
    echo "with defaults: " . json_encode($tWithDefaults, JSON_UNESCAPED_SLASHES) . "\n";
    echo "DRAWTEXT:\n$drawtext\n\n";
}
