<?php
/**
 * Bulk-generate puppeteer thumbnails for every system email template.
 *
 * Strategy per row:
 *   1. If `html_body` contains its own <html>...</html>, render it directly
 *      (the JSX-imported templates fall here). Avoids nesting our shell.
 *   2. Otherwise, fall back to EmailBuilderService::render() which assembles
 *      shell + blocks (the Premium and original-10 templates).
 *
 * Writes /storage/app/public/email-thumbnails/tpl-<id>.png and updates
 * email_templates.thumbnail_url.
 */

require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$svc      = app(\App\Engines\Marketing\Services\EmailBuilderService::class);
$thumbDir = '/var/www/levelup-staging/storage/app/public/email-thumbnails';
$tool     = '/var/www/levelup-staging/tools/bake-email-thumbnail.cjs';

if (!is_dir($thumbDir)) { mkdir($thumbDir, 0775, true); }
chown($thumbDir, 'www-data');
chgrp($thumbDir, 'www-data');

$rows = DB::table('email_templates')->where('is_system', 1)->where('is_active', 1)->orderBy('id')->get(['id','name','html_body']);
echo "Generating thumbnails for {$rows->count()} system templates...\n";

$ok = 0; $fail = 0; $skip = 0;

foreach ($rows as $row) {
    $html = (string) $row->html_body;
    if (trim($html) === '') {
        // Try EmailBuilderService::render() which assembles from blocks
        try { $html = $svc->render((int) $row->id); } catch (\Throwable $e) {}
    }
    if (trim($html) === '') {
        echo "  · skip id={$row->id} ({$row->name}) — no html_body and render() empty\n";
        $skip++;
        continue;
    }

    $tmpHtml = '/tmp/eb-thumb-src-' . $row->id . '.html';
    $outPng  = $thumbDir . '/tpl-' . $row->id . '.png';
    file_put_contents($tmpHtml, $html);

    $cmd = 'node ' . escapeshellarg($tool) . ' ' . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($outPng) . ' 2>&1';
    exec($cmd, $output, $rc);

    if ($rc !== 0 || !file_exists($outPng) || filesize($outPng) < 200) {
        echo "  ✗ id={$row->id} ({$row->name}) FAILED rc=$rc " . substr(implode(' | ', $output), 0, 200) . "\n";
        $fail++;
        @unlink($tmpHtml);
        continue;
    }
    @unlink($tmpHtml);

    $url = '/storage/email-thumbnails/tpl-' . $row->id . '.png?v=' . time();
    DB::table('email_templates')->where('id', $row->id)->update([
        'thumbnail_url' => $url,
        'updated_at'    => now(),
    ]);
    @chown($outPng, 'www-data'); @chgrp($outPng, 'www-data');
    echo "  ✓ id={$row->id} {$row->name} (" . filesize($outPng) . " bytes)\n";
    $ok++;
}

echo "\nDONE  ok=$ok fail=$fail skip=$skip\n";
