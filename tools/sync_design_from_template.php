<?php
$root = realpath(__DIR__ . '/..');
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ids = array_slice($argv, 1);
if (empty($ids)) { echo "usage: php sync_design_from_template.php <design_id> [<design_id>...]\n"; exit(1); }

foreach ($ids as $id) {
    $design = Illuminate\Support\Facades\DB::table('studio_designs')->where('id', $id)->first();
    if (!$design || !$design->template_id) { echo "no template for design $id\n"; continue; }
    $tpl = Illuminate\Support\Facades\DB::table('studio_video_templates')->where('id', $design->template_id)->first();
    if (!$tpl || !$tpl->structure_json) { echo "no structure_json on template {$design->template_id}\n"; continue; }
    Illuminate\Support\Facades\DB::table('studio_designs')->where('id', $id)->update([
        'video_data' => $tpl->structure_json,
        'updated_at' => now(),
    ]);
    echo "synced design $id from template {$design->template_id} ({$tpl->slug})\n";
}
