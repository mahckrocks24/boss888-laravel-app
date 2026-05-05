<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$slug = $argv[1] ?? null;
$out  = $argv[2] ?? null;
if (!$slug || !$out) { echo "usage: php extract_existing.php <slug> <out.json>\n"; exit(1); }
$row = Illuminate\Support\Facades\DB::table('studio_video_templates')->where('slug', $slug)->first();
if (!$row) { echo "not found: $slug\n"; exit(1); }
file_put_contents($out, $row->structure_json);
echo "wrote $out (" . strlen($row->structure_json) . " bytes)\n";
