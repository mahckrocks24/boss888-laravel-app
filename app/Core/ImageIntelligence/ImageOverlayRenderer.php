<?php

namespace App\Core\ImageIntelligence;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ImageOverlayRenderer — composites the ImageBlueprint's typography overlay
 * (headline + supporting copy) onto a text-free AI background as REAL, crisp,
 * correctly-spelled typography. This is the 'separate_overlay' fidelity path:
 * the image model draws no text (it spells badly), and we render the exact copy
 * here with actual fonts via the same headless-Chromium pipeline Studio uses
 * for design export (tools/studio-render.cjs).
 *
 * Returns the composited PNG's public URL + storage path, or null on failure
 * (caller keeps the clean background so a render error never loses the image).
 */
class ImageOverlayRenderer
{
    /**
     * @param string $bgStoragePath  public-disk relative path to the AI background (e.g. ai-images/1/x.png)
     * @param array  $overlay        {headline, supporting_copy[], placement, style, color_palette[]}
     * @param int    $w
     * @param int    $h
     * @param int    $wsId
     * @return array{success:bool, url?:string, storage_path?:string, error?:string}
     */
    public function render(string $bgStoragePath, array $overlay, int $w, int $h, int $wsId): array
    {
        $headline = trim((string) ($overlay['headline'] ?? ''));
        $copy     = array_values(array_filter(array_map('trim', (array) ($overlay['supporting_copy'] ?? []))));
        if ($headline === '' && empty($copy)) {
            return ['success' => false, 'error' => 'no_copy_to_render'];
        }

        // Background as a data URI — guarantees it loads with no network/CDN
        // dependency (the file was just written to the public disk).
        try {
            $bytes = Storage::disk('public')->get($bgStoragePath);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'bg_read_failed:' . $e->getMessage()];
        }
        if (!$bytes) return ['success' => false, 'error' => 'bg_empty'];
        $bgData = 'data:image/png;base64,' . base64_encode($bytes);

        $html = $this->buildHtml($bgData, $headline, $copy, $overlay, $w, $h);

        $tmpDir = storage_path('app/studio-render-tmp');
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
        $stamp   = bin2hex(random_bytes(6));
        $tmpHtml = $tmpDir . '/ov-' . $stamp . '.html';
        $tmpPng  = $tmpDir . '/ov-' . $stamp . '.png';
        if (file_put_contents($tmpHtml, $html) === false) {
            return ['success' => false, 'error' => 'html_write_failed'];
        }

        $script = base_path('tools/studio-render.cjs');
        if (!is_file($script)) { @unlink($tmpHtml); return ['success' => false, 'error' => 'renderer_missing']; }

        $cmd = 'node ' . escapeshellarg($script) . ' '
             . escapeshellarg($tmpHtml) . ' ' . (int) $w . ' ' . (int) $h . ' '
             . escapeshellarg($tmpPng) . ' 2>&1';

        // Same child env the render-png route uses — PHP-FPM proc_open drops
        // PUPPETEER_CACHE_DIR / HOME otherwise and Chromium can't be located.
        $childEnv = [
            'HOME'                => '/tmp',
            'PATH'                => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'PUPPETEER_CACHE_DIR' => base_path('.puppeteer-cache'),
            'LANG'                => 'C.UTF-8',
            'LC_ALL'              => 'C.UTF-8',
        ];
        $descriptorspec = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $proc = proc_open($cmd, $descriptorspec, $pipes, null, $childEnv);
        if (!is_resource($proc)) { @unlink($tmpHtml); return ['success' => false, 'error' => 'spawn_failed']; }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($proc);
        @unlink($tmpHtml);

        if ($status !== 0 || !is_file($tmpPng) || filesize($tmpPng) < 200) {
            @unlink($tmpPng);
            Log::warning('[ImageOverlayRenderer] render failed', ['status' => $status, 'out' => mb_substr($stdout . $stderr, 0, 500)]);
            return ['success' => false, 'error' => 'render_failed'];
        }

        $outPath = 'ai-images/' . $wsId . '/' . pathinfo($bgStoragePath, PATHINFO_FILENAME) . '-composed.png';
        try {
            Storage::disk('public')->put($outPath, file_get_contents($tmpPng));
        } catch (\Throwable $e) {
            @unlink($tmpPng);
            return ['success' => false, 'error' => 'store_failed:' . $e->getMessage()];
        }
        @unlink($tmpPng);

        return ['success' => true, 'url' => Storage::disk('public')->url($outPath), 'storage_path' => $outPath];
    }

    private function buildHtml(string $bgData, string $headline, array $copy, array $overlay, int $w, int $h): string
    {
        $palette = array_values(array_filter((array) ($overlay['color_palette'] ?? [])));
        $accent  = $this->firstHex($palette) ?: '#FFFFFF';

        // Adaptive sizes relative to canvas width + headline length.
        $hlLen = mb_strlen($headline);
        $hlSize = (int) round($w * 0.078);
        if ($hlLen > 22) $hlSize = (int) round($hlSize * 0.84);
        if ($hlLen > 34) $hlSize = (int) round($hlSize * 0.80);
        if ($hlLen > 48) $hlSize = (int) round($hlSize * 0.82);
        $hlSize = max(30, $hlSize);
        $bodySize = max(18, (int) round($w * 0.030));
        $pad = (int) round($w * 0.065);

        $hlEsc = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
        $copyHtml = '';
        foreach ($copy as $line) {
            $copyHtml .= '<p class="ln">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        // Brand fonts loaded best-effort from Google Fonts; robust system
        // fallback if the render host has no network (Liberation/DejaVu present).
        return <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:{$w}px; height:{$h}px; }
  .canvas { position:relative; width:{$w}px; height:{$h}px; overflow:hidden; background:#111; }
  .bg { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
  .scrim-top { position:absolute; left:0; right:0; top:0; height:46%;
    background:linear-gradient(to bottom, rgba(0,0,0,.60), rgba(0,0,0,0)); }
  .scrim-bot { position:absolute; left:0; right:0; bottom:0; height:50%;
    background:linear-gradient(to top, rgba(0,0,0,.72), rgba(0,0,0,0)); }
  .headline { position:absolute; top:{$pad}px; left:{$pad}px; right:{$pad}px;
    font-family:'Syne','Liberation Sans','DejaVu Sans',sans-serif; font-weight:800;
    font-size:{$hlSize}px; line-height:1.06; color:#fff; text-align:center;
    text-shadow:0 3px 18px rgba(0,0,0,.55); letter-spacing:-0.5px; }
  .accent { width:96px; height:6px; background:{$accent}; border-radius:3px;
    margin:22px auto 0; box-shadow:0 2px 10px rgba(0,0,0,.4); }
  .copy { position:absolute; bottom:{$pad}px; left:{$pad}px; right:{$pad}px;
    font-family:'DM Sans','Liberation Sans','DejaVu Sans',sans-serif; font-weight:500;
    font-size:{$bodySize}px; line-height:1.4; color:#fff; text-align:left;
    text-shadow:0 2px 12px rgba(0,0,0,.6); }
  .copy .ln { margin-bottom:8px; }
</style></head>
<body><div class="canvas">
  <img class="bg" src="{$bgData}" alt="">
  <div class="scrim-top"></div>
  <div class="scrim-bot"></div>
  <div class="headline">{$hlEsc}<div class="accent"></div></div>
  <div class="copy">{$copyHtml}</div>
</div></body></html>
HTML;
    }

    private function firstHex(array $palette): ?string
    {
        foreach ($palette as $c) {
            if (preg_match('/#[0-9a-fA-F]{6}/', (string) $c, $m)) return $m[0];
        }
        return null;
    }
}
