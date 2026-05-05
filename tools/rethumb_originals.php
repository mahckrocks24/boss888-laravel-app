<?php
require '/var/www/levelup-staging/vendor/autoload.php';
$app = require '/var/www/levelup-staging/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$thumbDir = '/var/www/levelup-staging/storage/app/public/email-thumbnails';
$tool     = '/var/www/levelup-staging/tools/bake-email-thumbnail.cjs';

// Re-thumbnail any system template whose html_body is empty/null but body_html exists.
$rows = DB::table('email_templates')
    ->where('is_system', 1)
    ->where(function($q){ $q->whereNull('html_body')->orWhere('html_body', ''); })
    ->whereNotNull('body_html')
    ->where('body_html', '!=', '')
    ->get(['id','name','subject','body_html','brand_color']);

echo "Re-thumbnailing {$rows->count()} templates with legacy body_html...\n";
$ok = 0; $fail = 0;

foreach ($rows as $row) {
    $brand = $row->brand_color ?: '#5B5BD6';
    // Wrap legacy body_html in a proper email document so puppeteer has
    // something to render at scale (these snippets are <p>...</p> only).
    $name = htmlspecialchars($row->name ?? 'Template');
    $subject = htmlspecialchars($row->subject ?? '');
    $body = $row->body_html;
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"/>'
          . '<meta name="viewport" content="width=device-width,initial-scale=1"/>'
          . '<title>' . $name . '</title>'
          . '<style>body{margin:0;padding:0;background:#F2F4F8;font-family:Inter,Arial,sans-serif}'
          . '.wrap{max-width:600px;margin:0 auto;padding:40px 20px}'
          . '.card{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.06)}'
          . '.header{background:' . $brand . ';padding:20px 32px;color:#fff}'
          . '.header h1{margin:0;font-size:18px;font-weight:700;letter-spacing:-0.2px}'
          . '.subj{font-size:11px;text-transform:uppercase;letter-spacing:.1em;opacity:.85;margin-bottom:6px}'
          . '.body{padding:32px;color:#0F172A;font-size:15px;line-height:1.65}'
          . '.body p{margin:0 0 14px}'
          . '.body p:last-child{margin-bottom:0}'
          . '.body a{color:' . $brand . '}'
          . '.footer{padding:18px 32px;background:#FAFAFB;color:#94A3B8;font-size:11px;text-align:center;border-top:1px solid #E2E8F0}'
          . '</style></head><body>'
          . '<div class="wrap"><div class="card">'
          . '<div class="header"><div class="subj">Email</div><h1>' . $name . '</h1></div>'
          . '<div class="body">' . $body . '</div>'
          . '<div class="footer">System template &middot; LevelUp Growth</div>'
          . '</div></div></body></html>';

    $tmp = '/tmp/eb-thumb-' . $row->id . '.html';
    $out = $thumbDir . '/tpl-' . $row->id . '.png';
    file_put_contents($tmp, $html);

    $cmd = 'node ' . escapeshellarg($tool) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($out) . ' 2>&1';
    exec($cmd, $output, $rc);
    @unlink($tmp);

    if ($rc !== 0 || !file_exists($out) || filesize($out) < 2000) {
        echo "  ✗ id={$row->id} {$row->name} rc=$rc " . substr(implode(' ', $output), 0, 200) . "\n";
        $fail++;
        continue;
    }
    $url = '/storage/email-thumbnails/tpl-' . $row->id . '.png?v=' . time();
    DB::table('email_templates')->where('id', $row->id)->update([
        'thumbnail_url' => $url,
        'updated_at'    => now(),
    ]);
    @chown($out, 'www-data'); @chgrp($out, 'www-data');
    echo "  ✓ id={$row->id} {$row->name} (" . filesize($out) . " bytes)\n";
    $ok++;
}
echo "\nDONE ok=$ok fail=$fail\n";
