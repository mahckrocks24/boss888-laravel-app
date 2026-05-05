<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$svc = app(\App\Engines\Studio\Services\StudioService::class);

$rows = \DB::table('studio_designs')
    ->whereNull('deleted_at')
    ->where(function($q){ $q->whereNull('thumbnail_url')->orWhere('thumbnail_url',''); })
    
    ->orderByDesc('id')
    ->get(['id','name']);

echo "Generating thumbnails for {$rows->count()} designs...\n";
foreach ($rows as $r) {
    $res = $svc->generateThumbnail((int) $r->id);
    echo "  id={$r->id} {$r->name} → " . ($res['thumbnail_url'] ?? ($res['error'] ?? 'unknown')) . " (" . ($res['note'] ?? '') . ")\n";
}
echo "DONE\n";
