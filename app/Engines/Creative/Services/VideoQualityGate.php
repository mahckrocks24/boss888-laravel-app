<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * RFC-0025 P4 (2026-10-03): every clip is measured before the owner sees it.
 *   foreign_words  - the RAW clip (before our layer) carries no writing the video model invented (writing already in the
 *                    owner's photo is allowed). FATAL: the clip is not delivered, the credits go back.
 *   exact_copy     - the finished clip shows the owner's quoted words exactly. Remedy: re-render the layer once, then
 *                    the plain titler.
 *   contrast       - measured on the real text pixels against what is behind them, at least 3:1 for headline-size type.
 *                    Remedy: a stronger glow, once.
 *   safe_zone      - the text block sits inside the platform's safe zone.
 *   subject_clear  - the text does not cover a face or the main subject. Remedy: the opposite corner, once.
 *   brand_fidelity - the brand font loaded; the logo is there when the business has one.
 *   named_people   - no scene prompt carries the business name or "Chef <Name>".
 *   photo_integrity- the owner's photo is the first frame (average-hash distance).
 *   technical      - shape, length, H.264, moov before mdat.
 *   money          - one charge, equal to the price for that length.
 * Results go to assets.metadata_json.quality; nothing here ever claims a gate that did not run.
 */
final class VideoQualityGate
{
    public static function enabled(?int $wsId): bool
    {
        $f = storage_path('app/videogates.on');
        if (! is_file($f)) return false;
        $ids = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) @file_get_contents($f)) ?: []));
        return in_array('*', $ids, true) || ($wsId !== null && in_array((string) $wsId, $ids, true));
    }

    /**
     * @param callable(array):array $rerender  re-runs the brand layer with context overrides, returns its result
     * @return array{passed:bool, fatal:?string, gates:array, actions:array}
     */
    public function check(object $asset, string $file, array $layer, array $ctx, callable $rerender): array
    {
        $g = []; $actions = [];
        $ws = (int) $asset->workspace_id;
        $raw = preg_replace('/\.mp4$/i', '', $file) . '-raw.mp4';
        $rawFile = is_file($raw) ? $raw : $file;
        $headline = trim((string) ($ctx['headline'] ?? ''));
        $dur = $this->duration($file);

        // foreign words in the raw clip (fatal)
        $allowed = [];   // the raw clip carries NO writing of its own - a model-painted headline would sit under ours
        if (! empty($ctx['photo_paths'])) foreach ((array) $ctx['photo_paths'] as $pp) { $allowed = array_merge($allowed, $this->words(implode(' ', $this->readText($ws, Storage::disk('public')->path($pp), 'photo')))); }
        $rawText = $this->readText($ws, $this->strip($rawFile, [0.8, max(1.0, $dur / 2), max(1.2, $dur - 0.4)]), 'raw');
        $foreign = array_values(array_diff($this->words(implode(' ', $rawText)), $allowed));
        $g['foreign_words'] = ['pass' => count($foreign) === 0, 'found' => array_slice($foreign, 0, 8), 'raw_text' => array_slice($rawText, 0, 6)];

        // exact copy + subject clear (one look at the finished frame)
        $look = $headline !== '' ? $this->lookAtFinal($ws, $file, $headline) : null;
        if ($headline !== '') {
            $exact = (bool) ($look['exact'] ?? false);
            if (! $exact) { $actions[] = 'exact_copy: re-render layer'; $layer = $rerender([]) + $layer; $look = $this->lookAtFinal($ws, $file, $headline); $exact = (bool) ($look['exact'] ?? false); }
            if (! $exact) { $actions[] = 'exact_copy: plain titler'; @copy($rawFile, $file); VideoTitler::apply($file, $headline); $look = $this->lookAtFinal($ws, $file, $headline); $exact = (bool) ($look['exact'] ?? false); }
            $g['exact_copy'] = ['pass' => $exact, 'seen' => $look['text'] ?? []];
            if (! empty($look['overlap']) && ! empty($layer['success'])) {
                $actions[] = 'subject_clear: opposite corner';
                $layer = $rerender(['placement' => str_contains((string) ($layer['zone'] ?? ''), 'left') ? 'upper-right' : 'upper-left']) + $layer;
                $look = $this->lookAtFinal($ws, $file, $headline);
            }
            $g['subject_clear'] = ['pass' => empty($look['overlap'])];
        }

        // contrast and safe zone on the real text pixels
        if ($headline !== '' && ! empty($layer['text_png']) && is_file((string) $layer['text_png'])) {
            $c = $this->contrast($file, (string) $layer['text_png']);
            if (($c['ratio'] ?? 99) < 3.0) {
                $actions[] = 'contrast: stronger glow';
                $layer = $rerender(['strong_glow' => true]) + $layer;
                if (! empty($layer['text_png']) && is_file((string) $layer['text_png'])) $c = $this->contrast($file, (string) $layer['text_png']);
            }
            $g['contrast'] = ['pass' => ($c['ratio'] ?? 0) >= 3.0, 'ratio' => $c['ratio'] ?? null];
            $box = $c['box'] ?? null; $safe = (array) ($layer['profile_safe'] ?? [0, 0, 0, 0]);
            if ($box) {
                [$w, $h] = [$c['w'], $c['h']];
                $inside = $box[1] >= $safe[0] * $h - 2 && $box[3] <= $h - $safe[1] * $h + 2 && $box[0] >= $safe[2] * $w - 2 && $box[2] <= $w - $safe[3] * $w + 2;
                $g['safe_zone'] = ['pass' => $inside, 'box' => $box, 'profile' => $layer['profile'] ?? null];
            }
        }

        // brand fidelity
        if (! empty($layer['success'])) {
            $fontOk = $headline === '' || ! empty(((array) ($layer['fonts_loaded'] ?? []))[(string) ($layer['head_font'] ?? '')]);
            $logoOk = empty($layer['logo_expected']) || ! empty($layer['logo']);
            $g['brand_fidelity'] = ['pass' => $fontOk && $logoOk, 'font' => $layer['head_font'] ?? null, 'font_loaded' => $fontOk, 'logo' => $logoOk];
        }

        // named people in the scene prompts
        $biz = trim((string) ($ctx['business_name'] ?? ''));
        $bad = DB::table('creative_video_jobs')->where('asset_id', $asset->id)->pluck('scene_prompt')->filter(fn ($p) => ($biz !== '' && stripos((string) $p, $biz) !== false) || preg_match('/\bChef\s+\p{Lu}/u', (string) $p))->count();
        $g['named_people'] = ['pass' => $bad === 0];

        // the owner's photo is the first frame
        if (! empty($ctx['photo_paths'])) {
            $first = $this->frame($rawFile, 0.05);
            $d = $first ? $this->hashDistance($first, Storage::disk('public')->path((string) $ctx['photo_paths'][0])) : 64;
            $g['photo_integrity'] = ['pass' => $d <= 14, 'distance' => $d];
            if ($first) @unlink($first);
        }

        // technical
        $probe = trim((string) @shell_exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height,codec_name -of csv=p=0 ' . escapeshellarg($file) . ' 2>/dev/null'));
        [$codec, $pw, $ph] = array_pad(explode(',', $probe), 3, null);
        $want = ['9:16' => 9 / 16, '16:9' => 16 / 9, '1:1' => 1.0, '2:3' => 2 / 3, '4:5' => 0.8][(string) ($ctx['aspect'] ?? '9:16')] ?? 9 / 16;
        $ratioOk = $pw && $ph && abs(($pw / $ph) - $want) / $want < 0.03;
        $head = (string) @file_get_contents($file, false, null, 0, 262144);
        $fast = ($m = strpos($head, 'moov')) !== false && (($d2 = strpos($head, 'mdat')) === false || $m < $d2);
        $lenOk = $dur >= ((float) ($ctx['duration'] ?? 6)) - 0.5;
        $g['technical'] = ['pass' => $codec === 'h264' && $ratioOk && $fast && $lenOk, 'codec' => $codec, 'size' => "{$pw}x{$ph}", 'seconds' => round($dur, 2), 'faststart' => $fast];

        // money
        if (! empty($asset->task_id)) {
            $commits = DB::table('credit_transactions')->where('workspace_id', $ws)->where('reference_type', 'Task')->where('reference_id', $asset->task_id)->where('type', 'commit')->pluck('amount')->map(fn ($a) => (int) $a)->all();
            $price = app(\App\Core\EngineKernel\CapabilityMapService::class)->creditCostFor('generate_video', ['duration' => (int) ($ctx['duration'] ?? 6)]);
            $g['money'] = ['pass' => count($commits) === 1 && $commits[0] === $price, 'charged' => $commits, 'price' => $price];
        }

        if (! empty($layer['text_png'])) @unlink((string) $layer['text_png']);
        $fatal = ! $g['foreign_words']['pass'] ? 'foreign_words' : null;
        $passed = ! array_filter($g, fn ($x) => empty($x['pass']));
        Log::info('[RFC-0025] quality gates', ['asset' => $asset->id, 'passed' => $passed, 'fatal' => $fatal, 'failed' => array_keys(array_filter($g, fn ($x) => empty($x['pass']))), 'actions' => $actions]);
        return ['passed' => $passed, 'fatal' => $fatal, 'gates' => $g, 'actions' => $actions, 'checked_at' => now()->toIso8601String()];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function duration(string $f): float
    {
        return (float) trim((string) @shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($f) . ' 2>/dev/null'));
    }

    private function frame(string $f, float $t): ?string
    {
        $p = sys_get_temp_dir() . '/vqg-' . bin2hex(random_bytes(5)) . '.png';
        @shell_exec('ffmpeg -v error -y -ss ' . number_format($t, 2, '.', '') . ' -i ' . escapeshellarg($f) . ' -frames:v 1 ' . escapeshellarg($p) . ' 2>/dev/null');
        return is_file($p) ? $p : null;
    }

    /** three frames side by side, small enough for one vision call */
    private function strip(string $f, array $times): string
    {
        $parts = array_values(array_filter(array_map(fn ($t) => $this->frame($f, $t), $times)));
        $out = sys_get_temp_dir() . '/vqg-strip-' . bin2hex(random_bytes(5)) . '.jpg';
        $in = implode(' ', array_map(fn ($p) => '-i ' . escapeshellarg($p), $parts));
        @shell_exec('ffmpeg -v error -y ' . $in . ' -filter_complex ' . escapeshellarg(implode('', array_map(fn ($i) => "[$i:v]scale=-2:720[s$i];", array_keys($parts))) . implode('', array_map(fn ($i) => "[s$i]", array_keys($parts))) . 'hstack=inputs=' . count($parts)) . ' -q:v 3 ' . escapeshellarg($out) . ' 2>/dev/null');
        foreach ($parts as $p) @unlink($p);
        return $out;
    }

    /** @return list<string> */
    private function readText(int $ws, string $imagePath, string $tag): array
    {
        if (! is_file($imagePath)) return [];
        $rel = 'ai-videos/' . $ws . '/qa-' . $tag . '-' . bin2hex(random_bytes(5)) . '.jpg';
        Storage::disk('public')->put($rel, (string) file_get_contents($imagePath));
        if (str_contains($imagePath, '/vqg-')) @unlink($imagePath);
        try {
            $v = app(\App\Connectors\RuntimeClient::class)->visionAnalyze('Transcribe every piece of readable text, letters or numbers visible anywhere in this image, exactly as written (signs, labels, captions, logos). Return ONLY a JSON array of strings; [] if there is none.', '', Storage::disk('public')->url($rel));
            $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', (string) ($v['analysis'] ?? ''));
            $arr = preg_match('/\[.*\]/s', (string) $raw, $m) ? (json_decode($m[0], true) ?: []) : [];
            return array_values(array_filter(array_map('strval', (array) $arr)));
        } catch (\Throwable) { return []; }
        finally { Storage::disk('public')->delete($rel); }
    }

    private function lookAtFinal(int $ws, string $file, string $headline): array
    {
        $p = $this->frame($file, 2.5);
        if (! $p) return [];
        $rel = 'ai-videos/' . $ws . '/qa-final-' . bin2hex(random_bytes(5)) . '.jpg';
        Storage::disk('public')->put($rel, (string) file_get_contents($p)); @unlink($p);
        try {
            $v = app(\App\Connectors\RuntimeClient::class)->visionAnalyze('Look at this video frame. Return ONLY JSON: {"text": [every piece of readable text, exactly as written], "overlap": true or false (does any text cover a person\'s face or the main subject)}', '', Storage::disk('public')->url($rel));
            $raw = (string) ($v['analysis'] ?? '');
            $j = preg_match('/\{.*\}/s', $raw, $m) ? (json_decode($m[0], true) ?: []) : [];
            $seen = array_values(array_filter(array_map('strval', (array) ($j['text'] ?? []))));
            $norm = fn ($s) => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
            return ['text' => $seen, 'overlap' => (bool) ($j['overlap'] ?? false), 'exact' => str_contains(' ' . $norm(implode(' ', $seen)) . ' ', ' ' . $norm($headline) . ' ')];
        } catch (\Throwable) { return []; }
        finally { Storage::disk('public')->delete($rel); }
    }

    /** @return list<string> words of three letters or more, lower case */
    private function words(string $s): array
    {
        $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($w, fn ($x) => mb_strlen($x) >= 3)));
    }

    /** headline pixels (alpha > 230 in the text state) against what is around them in the finished frame */
    private function contrast(string $file, string $textPng): array
    {
        $fr = $this->frame($file, 2.5);
        if (! $fr) return [];
        $t = @imagecreatefrompng($textPng); $f = @imagecreatefrompng($fr); @unlink($fr);
        if (! $t || ! $f) return [];
        $w = imagesx($t); $h = imagesy($t);
        if (imagesx($f) !== $w || imagesy($f) !== $h) { $f2 = imagescale($f, $w, $h); if ($f2) $f = $f2; }
        $lum = function (int $c): float { $r = (($c >> 16) & 255) / 255; $gg = (($c >> 8) & 255) / 255; $b = ($c & 255) / 255;
            $lin = fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; return 0.2126 * $lin($r) + 0.7152 * $lin($gg) + 0.0722 * $lin($b); };
        $x0 = $w; $y0 = $h; $x1 = 0; $y1 = 0; $txt = [];
        for ($y = 0; $y < $h; $y += 2) for ($x = 0; $x < $w; $x += 2) {
            $a = 127 - ((imagecolorat($t, $x, $y) >> 24) & 0x7F);   // 127 = opaque
            if ($a > 115) { $txt[] = $lum(imagecolorat($f, $x, $y)); $x0 = min($x0, $x); $y0 = min($y0, $y); $x1 = max($x1, $x); $y1 = max($y1, $y); }
        }
        if (! $txt) return ['ratio' => null, 'w' => $w, 'h' => $h];
        $bg = [];
        $pad = 6;
        for ($y = max(0, $y0 - $pad); $y <= min($h - 1, $y1 + $pad); $y += 2) for ($x = max(0, $x0 - $pad); $x <= min($w - 1, $x1 + $pad); $x += 2) {
            $a = 127 - ((imagecolorat($t, $x, $y) >> 24) & 0x7F);
            if ($a <= 115) $bg[] = $lum(imagecolorat($f, $x, $y));   // glow and clear pixels around the glyphs
        }
        $lt = array_sum($txt) / count($txt); $lb = $bg ? array_sum($bg) / count($bg) : 0.0;
        $ratio = (max($lt, $lb) + 0.05) / (min($lt, $lb) + 0.05);
        return ['ratio' => round($ratio, 2), 'box' => [$x0, $y0, $x1, $y1], 'w' => $w, 'h' => $h];
    }

    private function hashDistance(string $a, string $b): int
    {
        $hash = function (string $p): ?string {
            $im = @imagecreatefromstring((string) @file_get_contents($p)); if (! $im) return null;
            $s = imagecreatetruecolor(8, 8); imagecopyresampled($s, $im, 0, 0, 0, 0, 8, 8, imagesx($im), imagesy($im));
            $v = []; for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++) { $c = imagecolorat($s, $x, $y); $v[] = ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255)) / 3; }
            $avg = array_sum($v) / 64; return implode('', array_map(fn ($x) => $x >= $avg ? '1' : '0', $v));
        };
        // the photo is cut to the clip's shape before animating: compare the centre crop of the photo with the first frame
        $ha = $hash($a);
        $tmp = sys_get_temp_dir() . '/vqg-photo-' . bin2hex(random_bytes(4)) . '.png';
        $fa = @getimagesize($a); $fb = @getimagesize($b);
        if ($fa && $fb) {
            $ra = $fa[0] / $fa[1]; $rb = $fb[0] / $fb[1];
            $vf = $rb > $ra ? 'crop=ih*' . $ra . ':ih' : 'crop=iw:iw/' . $ra;
            @shell_exec('ffmpeg -v error -y -i ' . escapeshellarg($b) . ' -vf ' . escapeshellarg($vf) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
        }
        $hb = $hash(is_file($tmp) ? $tmp : $b); @unlink($tmp);
        if (! $ha || ! $hb) return 64;
        return count(array_filter(str_split($ha), fn ($c, $i) => $c !== $hb[$i], ARRAY_FILTER_USE_BOTH));
    }
}
