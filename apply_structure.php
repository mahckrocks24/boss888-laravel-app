<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$slug = $argv[1] ?? null;
$in   = $argv[2] ?? null;
if (!$slug || !$in) { echo "usage: php apply_structure.php <slug> <structure.json>\n"; exit(1); }
$json = file_get_contents($in);
if (!$json || !json_decode($json, true)) { echo "invalid json\n"; exit(1); }
$n = Illuminate\Support\Facades\DB::table('studio_video_templates')
    ->where('slug', $slug)
    ->update(['structure_json' => $json, 'updated_at' => now()]);
echo "updated $n row(s) for $slug\n";
