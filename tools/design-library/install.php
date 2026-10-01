<?php
// Replace the design-library tiles (Pinterest-derived) with our rendered designs. Run from the Laravel root.
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Storage;
$src = '/root/design-regen/library'; $map = json_decode(file_get_contents("$src/map.json"), true);
$disk = Storage::disk('public'); $root = $disk->path('');
$bak = rtrim($root, '/') . '/design-library.before-regen-' . date('Ymd-His');
if (is_dir($root . 'design-library')) { exec('cp -a ' . escapeshellarg($root . 'design-library') . ' ' . escapeshellarg($bak)); echo "backup -> $bak\n"; }
$rows = DB::table('design_recipes')->orderBy('id')->get(['id', 'key', 'title']);
$ok = 0; $miss = []; $used = [];
foreach ($rows as $r) {
    $id = $map[$r->key] ?? null;
    if (! $id || ! is_file("$src/full/$id.jpg")) { $miss[] = $r->key; continue; }
    $disk->put('design-library/' . $r->key . '.jpg', file_get_contents("$src/full/$id.jpg"));
    $thumb = 'design-library/thumbs/' . $r->key . '.jpg';
    $disk->put($thumb, file_get_contents("$src/thumbs/$id.jpg"));
    DB::table('design_recipes')->where('id', $r->id)->update(['thumb_path' => $thumb, 'thumb_status' => 'done', 'updated_at' => now()]);
    $used[$id] = true; $ok++;
}
exec('chown -R www-data:www-data ' . escapeshellarg($root . 'design-library'));
$unused = array_values(array_diff(array_values($map), array_keys($used)));
echo "replaced $ok of " . count($rows) . " rows\n";
echo "rows without a render (" . count($miss) . "): " . implode(', ', $miss) . "\n";
echo "renders without a row (" . count($unused) . "): " . implode(', ', $unused) . "\n";
