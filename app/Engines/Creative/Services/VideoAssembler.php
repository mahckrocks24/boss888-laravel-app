<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * RFC-0025 P3 (2026-10-03): several scenes become one clip. Until now ScenePlannerService::stitchScenes returned the first
 * scene and dropped the rest. Each scene is downloaded once, trimmed to its share of the target length, scaled and cropped
 * to one master size, and joined with 0.4 s crossfades; the result is written to our own disk and returned as our own URL,
 * so the normal persist -> brand layer -> deliver path runs unchanged.
 */
final class VideoAssembler
{
    public const XFADE = 0.4;

    /**
     * @param list<string> $urls  scene clips in order (provider URLs or our own)
     * @return array{success:bool, url?:string, storage_path?:string, error?:string, scenes?:int, seconds?:float}
     */
    public function stitch(int $wsId, int $assetId, array $urls, float $target, string $aspect): array
    {
        $urls = array_values(array_filter($urls));
        $n = count($urls);
        if ($n < 2) return ['success' => false, 'error' => 'needs_two_scenes'];
        $tmp = storage_path('app/studio-render-tmp/stitch-' . $assetId . '-' . bin2hex(random_bytes(4)));
        @mkdir($tmp, 0775, true);
        $files = [];
        try {
            foreach ($urls as $i => $u) {
                $p = "$tmp/s$i.mp4";
                $own = rtrim((string) config('app.url'), '/') . '/storage/';
                if (str_starts_with($u, $own) && Storage::disk('public')->exists(substr($u, strlen($own)))) {
                    copy(Storage::disk('public')->path(substr($u, strlen($own))), $p);
                } else {
                    if (! preg_match('#^https://#', $u)) throw new \RuntimeException('scene_url_not_https');
                    $r = Http::timeout(180)->withOptions(['sink' => $p])->get($u);
                    if (! $r->successful()) throw new \RuntimeException('scene_download_' . $r->status());
                }
                if (! is_file($p) || filesize($p) < 10000) throw new \RuntimeException('scene_empty_' . $i);
                $files[] = $p;
            }
            [$W, $H] = match ($aspect) { '16:9' => [1920, 1080], '1:1' => [1080, 1080], '2:3' => [1080, 1620], '4:5' => [1080, 1350], default => [1080, 1920] };
            $seg = round(($target + ($n - 1) * self::XFADE) / $n, 3);
            $inputs = ''; $graph = [];
            foreach ($files as $i => $f) {
                $inputs .= ' -i ' . escapeshellarg($f);
                $graph[] = "[$i:v]trim=0:$seg,setpts=PTS-STARTPTS,scale=$W:$H:force_original_aspect_ratio=increase,crop=$W:$H,fps=24,format=yuv420p,setsar=1[v$i]";
            }
            $last = '[v0]';
            for ($i = 1; $i < $n; $i++) {
                $off = round($i * ($seg - self::XFADE), 3);
                $graph[] = "{$last}[v$i]xfade=transition=fade:duration=" . self::XFADE . ":offset={$off}[x$i]";
                $last = "[x$i]";
            }
            $graph[] = "{$last}format=yuv420p[vout]";
            $gf = "$tmp/graph.txt"; file_put_contents($gf, implode(";\n", $graph));
            $rel = 'ai-videos/' . $wsId . '/stitched-' . $assetId . '-' . substr(md5(implode('|', $urls)), 0, 8) . '.mp4';
            $out = Storage::disk('public')->path($rel);
            @mkdir(dirname($out), 0775, true);
            $log = (string) @shell_exec('nice -n 10 ffmpeg -v error -y' . $inputs . ' -filter_complex_script ' . escapeshellarg($gf)
                . ' -map "[vout]" -c:v libx264 -preset ultrafast -crf 19 -movflags +faststart -an -t ' . number_format($target, 3, '.', '') . ' ' . escapeshellarg($out) . ' 2>&1');
            if (! is_file($out) || filesize($out) < 10000) throw new \RuntimeException('stitch_failed: ' . mb_substr($log, 0, 200));
            Log::info('[RFC-0025] scenes stitched', ['asset' => $assetId, 'scenes' => $n, 'seconds' => $target, 'size' => "{$W}x{$H}"]);
            return ['success' => true, 'url' => Storage::disk('public')->url($rel), 'storage_path' => $rel, 'scenes' => $n, 'seconds' => $target];
        } catch (\Throwable $e) {
            Log::warning('[RFC-0025] stitch failed', ['asset' => $assetId, 'e' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
            @rmdir($tmp);
        }
    }
}
