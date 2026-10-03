<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\Log;

/**
 * VIDEO-CERT-1 (2026-10-03): the words on a video are ours, never the video model's.
 *
 * Probe V2 asked for a clip with the headline 'Fall Menu Now Served'. The scene planner wrote the headline AND the campaign
 * offer into the scene, and the model painted "10% of private dinnœs s boukd in October". Video models cannot spell, so the
 * scene prompt is now always text-free and the customer's quoted headline is burned onto the finished clip here, exactly as
 * typed: centred lower third, white on a soft dark band, fading in after half a second. The untitled clip is kept beside it
 * as "-raw.mp4", which also makes this idempotent.
 */
final class VideoTitler
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf';

    /** Break the headline into at most three balanced lines. @return list<string> */
    public static function wrap(string $text, int $maxChars): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = []; $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if ($cur !== '' && mb_strlen($try) > $maxChars) { $lines[] = $cur; $cur = $w; } else { $cur = $try; }
        }
        if ($cur !== '') $lines[] = $cur;
        // two short lines read better than a long one and an orphan
        if (count($lines) === 2 && mb_strlen($lines[1]) < 6) {
            $all = implode(' ', $lines); $mid = (int) floor(mb_strlen($all) / 2);
            $cut = mb_strrpos(mb_substr($all, 0, $mid + 4), ' ');
            if ($cut !== false && $cut > 0) $lines = [mb_substr($all, 0, $cut), mb_substr($all, $cut + 1)];
        }
        return $lines;
    }

    public static function apply(string $file, string $headline): bool
    {
        $headline = trim($headline);
        if ($headline === '' || ! is_file($file)) return false;
        $raw = preg_replace('/\.mp4$/i', '', $file) . '-raw.mp4';
        if (is_file($raw)) return true;   // already titled
        if (! is_file(self::FONT)) { Log::warning('[VIDEO-CERT-1] titling font missing'); return false; }

        $probe = trim((string) @shell_exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 ' . escapeshellarg($file) . ' 2>/dev/null'));
        if (! preg_match('/^(\d+),(\d+)/', $probe, $m)) return false;
        [$w, $h] = [(int) $m[1], (int) $m[2]];
        $vertical = $h > $w;
        $size = (int) round(($vertical ? $w : $h) * ($vertical ? 0.082 : 0.078));
        $lines = self::wrap($headline, $vertical ? 16 : 30);

        // one drawtext per line so every line is centred; each line sits on its own soft band
        $out = preg_replace('/\.mp4$/i', '', $file) . '-titled.mp4';
        $step = (int) round($size * 1.42);
        // VIDEO-CERT-3: the title lives in the calm upper part of the frame (the planner keeps it clear), never on the subject
        $top = (int) round($vertical ? $h * 0.12 : $h * 0.10);
        $txts = []; $draws = [];
        foreach ($lines as $i => $line) {
            $t = tempnam(sys_get_temp_dir(), 'vtitle'); file_put_contents($t, $line); $txts[] = $t;
            $draws[] = 'drawtext=fontfile=' . self::FONT . ':textfile=' . $t . ':fontcolor=white:fontsize=' . $size
                . ':box=1:boxcolor=black@0.40:boxborderw=' . (int) round($size * 0.32)
                . ':x=(w-text_w)/2:y=' . ($top + $i * $step) . ":alpha='if(lt(t,0.5),0,if(lt(t,1.1),(t-0.5)/0.6,1))'";
        }
        $filter = implode(',', $draws);
        $txt = '';
        @shell_exec('ffmpeg -v error -y -i ' . escapeshellarg($file) . ' -vf ' . escapeshellarg($filter)
            . ' -c:v libx264 -preset veryfast -crf 20 -pix_fmt yuv420p -movflags +faststart -c:a copy ' . escapeshellarg($out) . ' 2>&1');
        foreach ($txts as $t) @unlink($t);
        if (! is_file($out) || filesize($out) < 10000) { @unlink($out); Log::warning('[VIDEO-CERT-1] titling failed', ['file' => basename($file)]); return false; }
        rename($file, $raw);
        rename($out, $file);
        Log::info('[VIDEO-CERT-1] headline burned onto the clip', ['file' => basename($file), 'lines' => count($lines)]);
        return true;
    }
}
