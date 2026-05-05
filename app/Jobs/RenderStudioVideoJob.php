<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RenderStudioVideoJob
 *
 * Reads studio_designs.video_data for a given design, builds an FFmpeg
 * filter_complex, shells out to ffmpeg, and writes the output MP4 to
 * storage/app/public/exports/{workspace_id}/{design_id}-{hash}.mp4.
 *
 * Status progression:
 *   pending → processing (job picked up)
 *   processing → done    (success: exported_video_url set)
 *   processing → failed  (error: export_error set)
 *
 * Slice A scope:
 *   - Concat video clips with xfade transitions (fade / slide_left / slide_right / zoom_in / zoom_out / dissolve)
 *   - Image clips via loop + duration
 *   - drawtext overlays with enable='between(t,X,Y)' timing
 *   - Filter presets via colorchannelmixer/eq/hue
 *   - Optional audio track with afade in/out + volume
 *   - Global filter applies to everything
 *
 * Out of scope for Slice A (drawn as best-effort or ignored):
 *   - Ken Burns (zoompan) — stub: if enabled, uses static zoom 1.0
 *   - Text animations other than fade/slide — stub: uses fade
 *   - Glitch / typewriter FFmpeg implementations — stub: fade
 */
class RenderStudioVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;  // 15 minutes hard cap (v4.1.2 — 30s reels with heavy drawtext can exceed 5min)
    public int $tries = 1;

    public function __construct(public int $designId) {}

    /**
     * Laravel calls this when the worker kills a job that exceeded $timeout
     * or when tries are exhausted. Without this, the design row is left in
     * export_status='processing' forever (frontend thinks it's still going).
     */
    public function failed(?\Throwable $e): void
    {
        $msg = $e ? $e->getMessage() : 'job_killed_or_timed_out';
        try {
            DB::table('studio_designs')->where('id', $this->designId)->update([
                'export_status' => 'failed',
                'export_error'  => mb_substr($msg, 0, 600),
                'updated_at'    => now(),
            ]);
        } catch (\Throwable $_) {}
        Log::error('studio.video.render job failed (terminal)', [
            'design_id' => $this->designId,
            'error'     => $msg,
        ]);
    }

    public function handle(): void
    {
        $row = DB::table('studio_designs')->where('id', $this->designId)->first();
        if (!$row || !$row->video_data) {
            $this->fail('design_not_found_or_empty');
            return;
        }

        DB::table('studio_designs')->where('id', $this->designId)->update([
            'export_status'        => 'processing',
            'export_progress_pct'  => 1,
            'export_error'         => null,
            'updated_at'           => now(),
        ]);

        $data = json_decode($row->video_data, true) ?: [];
        $wsId = (int) $row->workspace_id;

        try {
            $url = $this->render($data, $wsId, $this->designId);
            DB::table('studio_designs')->where('id', $this->designId)->update([
                'export_status'       => 'done',
                'export_progress_pct' => 100,
                'exported_video_url'  => $url,
                'status'              => 'exported',
                'updated_at'          => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('studio.video.render failed', [
                'design_id' => $this->designId,
                'error'     => $e->getMessage(),
                'trace'     => mb_substr($e->getTraceAsString(), 0, 2000),
            ]);
            $this->fail($e->getMessage());
        }
    }

    private function fail(string $err): void
    {
        DB::table('studio_designs')->where('id', $this->designId)->update([
            'export_status'       => 'failed',
            'export_error'        => mb_substr($err, 0, 600),
            'updated_at'          => now(),
        ]);
    }

    private function render(array $data, int $wsId, int $designId): string
    {
        $W   = (int) ($data['canvas_width']  ?? 1080);
        $H   = (int) ($data['canvas_height'] ?? 1920);
        $fps = (int) ($data['fps']           ?? 30);
        $duration = (float) ($data['duration'] ?? 15);
        $bgColor  = (string) ($data['background_color'] ?? '#000000');
        $globalFilter = (string) ($data['global_filter'] ?? 'none');

        // Drop unfilled placeholder slots — a template may ship with 3 slots
        // and the user only filled 2. Rendering an empty slot has no source
        // to read; strip them so the filled ones xfade normally. If every
        // slot is still empty, the empty-clips fallback below paints a
        // background color for the duration so text-only templates still
        // produce an MP4 instead of throwing.
        $clips = array_values(array_filter(($data['clips'] ?? []), function($c){
            if (($c['type'] ?? '') === 'placeholder') return false;
            if (($c['type'] ?? '') === 'color')       return true;   // solid-color auto clip
            $src = $c['source_url'] ?? null;
            return $src !== null && $src !== '';
        }));
        $texts = $data['text_overlays'] ?? [];
        $audio = $data['audio'] ?? null;

        // ── Output paths ────────────────────────────────────────────
        $outDir = storage_path('app/public/exports/' . $wsId);
        if (!is_dir($outDir)) @mkdir($outDir, 0775, true);
        $outName = $designId . '-' . bin2hex(random_bytes(3)) . '.mp4';
        $outPath = $outDir . '/' . $outName;
        $publicUrl = '/storage/exports/' . $wsId . '/' . $outName;

        // Scratch dir for any intermediates (colored backgrounds, etc.)
        $tmpDir = storage_path('app/studio-video-tmp');
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

        // ── If no clips, render a solid-color background for the duration ──
        // Lets text-only templates (testimonial, cinematic quote) still work.
        if (empty($clips)) {
            $clips = [[
                'id'          => 'bg_auto',
                'type'        => 'color',
                'color'       => $bgColor,
                'start_time'  => 0,
                'end_time'    => $duration,
                'duration'    => $duration,
            ]];
        }

        // ── Build the input list + per-clip normalized [v0][v1]... streams ──
        $inputs = [];
        $streams = [];
        foreach ($clips as $i => $c) {
            $dur = max(0.1, (float)(($c['end_time'] ?? 0) - ($c['start_time'] ?? 0)));
            $type = (string)($c['type'] ?? 'video');
            if ($type === 'video') {
                $src = $this->localPath($c['source_url'] ?? '');
                if (!$src) throw new \RuntimeException('missing_clip_src_' . $i);
                $inputs[] = '-i ' . escapeshellarg($src);
                // Trim, loop-if-short, scale+pad to canvas, set sar/fps.
                $trimStart = max(0.0, (float)($c['trim_start'] ?? 0));
                $streams[] = "[{$i}:v]trim=start={$trimStart}:duration={$dur},setpts=PTS-STARTPTS,"
                          . "scale={$W}:{$H}:force_original_aspect_ratio=increase,"
                          . "crop={$W}:{$H},fps={$fps},format=yuv420p[c{$i}v]";
            } elseif ($type === 'image') {
                $src = $this->localPath($c['source_url'] ?? '');
                if (!$src) throw new \RuntimeException('missing_image_src_' . $i);
                $inputs[] = '-loop 1 -t ' . $dur . ' -i ' . escapeshellarg($src);
                $kb = $c['ken_burns'] ?? null;
                if ($kb && !empty($kb['enabled'])) {
                    // zoompan operates on a frame-by-frame basis. `on` is
                    // the output frame index. Total frames = fps * duration.
                    $startS = max(1.0, (float)($kb['start_scale'] ?? 1.0));
                    $endS   = max(1.0, (float)($kb['end_scale']   ?? 1.1));
                    $startX = (float)($kb['start_x'] ?? 0);
                    $endX   = (float)($kb['end_x']   ?? 0);
                    $startY = (float)($kb['start_y'] ?? 0);
                    $endY   = (float)($kb['end_y']   ?? 0);
                    $totalFrames = (int) ceil($fps * $dur);
                    $zExpr = "'{$startS}+({$endS}-{$startS})*on/" . max(1, $totalFrames) . "'";
                    // x/y in zoompan are the TOP-LEFT of the view rect in the
                    // upscaled source. We want translation from the center
                    // offset, so: x_offset + (iw - iw/z)/2
                    $xExpr = "'((iw-iw/zoom)/2)+({$startX}+({$endX}-{$startX})*on/" . max(1, $totalFrames) . ")'";
                    $yExpr = "'((ih-ih/zoom)/2)+({$startY}+({$endY}-{$startY})*on/" . max(1, $totalFrames) . ")'";
                    // Pre-scale source 4x so zoom keeps detail; zoompan then
                    // renders at exact canvas size.
                    $streams[] = "[{$i}:v]scale=iw*4:ih*4:flags=lanczos,"
                              . "zoompan=z={$zExpr}:x={$xExpr}:y={$yExpr}:"
                              . "d=1:s={$W}x{$H}:fps={$fps},"
                              . "trim=duration={$dur},setpts=PTS-STARTPTS,format=yuv420p[c{$i}v]";
                } else {
                    $streams[] = "[{$i}:v]scale={$W}:{$H}:force_original_aspect_ratio=increase,"
                              . "crop={$W}:{$H},fps={$fps},format=yuv420p,trim=duration={$dur},setpts=PTS-STARTPTS[c{$i}v]";
                }
            } else { // 'color' background
                $col = $this->ffColor($c['color'] ?? $bgColor);
                $inputs[] = "-f lavfi -t {$dur} -i color=c={$col}:s={$W}x{$H}:r={$fps}";
                $streams[] = "[{$i}:v]format=yuv420p[c{$i}v]";
            }
        }

        // ── Chain clips with transitions (xfade) ──
        // If 1 clip, skip xfade. Else xfade adjacent pairs left-to-right.
        $chainLabel = 'c0v';
        if (count($clips) > 1) {
            $offset = (float)(($clips[0]['end_time'] ?? 0) - ($clips[0]['start_time'] ?? 0));
            for ($i = 1; $i < count($clips); $i++) {
                $t = $clips[$i]['transition_in'] ?? null;
                $tDur = max(0.1, min(1.5, (float)($t['duration'] ?? 0.5)));
                $tType = $this->ffXfadeType($t['type'] ?? 'fade');
                $outLabel = ($i === count($clips) - 1) ? 'vmix' : ('x' . $i);
                $xfadeOffset = max(0.0, $offset - $tDur);
                $streams[] = "[{$chainLabel}][c{$i}v]xfade=transition={$tType}:duration={$tDur}:offset={$xfadeOffset}[{$outLabel}]";
                $chainLabel = $outLabel;
                $offset += (float)(($clips[$i]['end_time'] ?? 0) - ($clips[$i]['start_time'] ?? 0)) - $tDur;
            }
        } else {
            // rename c0v to vmix for uniform handling below
            $streams[] = "[c0v]null[vmix]";
            $chainLabel = 'vmix';
        }

        // ── Global filter pass ──
        $afterFilter = 'vfilt';
        $filterExpr = $this->ffFilterChain($globalFilter);
        if ($filterExpr !== '') {
            $streams[] = "[vmix]{$filterExpr}[{$afterFilter}]";
        } else {
            $streams[] = "[vmix]null[{$afterFilter}]";
        }

        // ── Text overlays via drawtext ──
        $prevLabel = $afterFilter;
        $fontPath = $this->resolveFontPath();
        foreach ($texts as $ti => $t) {
            $nextLabel = 'vt' . $ti;
            $drawtext = $this->buildDrawtext($t, $W, $H, $fontPath);
            if ($drawtext === '') {
                $streams[] = "[{$prevLabel}]null[{$nextLabel}]";
            } else {
                $streams[] = "[{$prevLabel}]{$drawtext}[{$nextLabel}]";
            }
            $prevLabel = $nextLabel;
        }

        // ── Elements (logo / lower_third / badge / countdown) ──
        $elements = $data['elements'] ?? [];
        foreach ($elements as $ei => $el) {
            $inputOffset = count($inputs);
            $res = $this->buildElementFilter($el, $W, $H, $fontPath, $inputOffset);
            if (!empty($res['extraInputs'])) {
                foreach ($res['extraInputs'] as $ex) $inputs[] = $ex;
            }
            $nextLabel = 've' . $ei;
            if (!empty($res['overlayInput'])) {
                // Scale/alpha the extra input first, then overlay onto prev.
                $streams[] = $res['overlayInput']['prep_filter'];
                $ovLbl = $res['overlayInput']['overlay_label'];
                $ovArgs = $res['overlayInput']['overlay_args'];
                $streams[] = "[{$prevLabel}][{$ovLbl}]overlay={$ovArgs}[{$nextLabel}]";
                $prevLabel = $nextLabel;
            } elseif (!empty($res['filterChain'])) {
                $streams[] = "[{$prevLabel}]" . $res['filterChain'] . "[{$nextLabel}]";
                $prevLabel = $nextLabel;
            }
        }

        // ── Audio track ──
        $hasAudio = false;
        if ($audio && !empty($audio['url'])) {
            $audioSrc = $this->localPath($audio['url']);
            if ($audioSrc && is_file($audioSrc)) {
                $ai = count($inputs); // index of audio input
                $inputs[] = '-i ' . escapeshellarg($audioSrc);
                $vol     = max(0.0, min(2.0, (float)($audio['volume'] ?? 0.8)));
                $fadeIn  = max(0.0, min(5.0, (float)($audio['fade_in'] ?? 0)));
                $fadeOut = max(0.0, min(5.0, (float)($audio['fade_out'] ?? 0)));
                $afade   = [];
                if ($fadeIn  > 0) $afade[] = "afade=t=in:st=0:d={$fadeIn}";
                if ($fadeOut > 0) $afade[] = "afade=t=out:st=" . max(0, $duration - $fadeOut) . ":d={$fadeOut}";
                $afadeChain = empty($afade) ? 'anull' : implode(',', $afade);
                $streams[] = "[{$ai}:a]volume={$vol},atrim=duration={$duration},asetpts=PTS-STARTPTS,{$afadeChain}[amix]";
                $hasAudio = true;
            }
        }

        // ── Final command assembly ──
        $filterComplex = implode(';', $streams);
        $map = "-map \"[{$prevLabel}]\"";
        if ($hasAudio) $map .= " -map \"[amix]\" -c:a aac -b:a 128k";

        $cmd = '/usr/bin/ffmpeg -y -loglevel warning '
             . implode(' ', $inputs) . ' '
             . '-filter_complex ' . escapeshellarg($filterComplex) . ' '
             . $map . ' '
             . "-t {$duration} "
             . '-c:v libx264 -preset ultrafast -crf 23 -pix_fmt yuv420p '
             . '-movflags +faststart '
             . escapeshellarg($outPath) . ' 2>&1';

        Log::info('studio.video.render starting', ['design_id' => $designId, 'cmd_preview' => mb_substr($cmd, 0, 1000)]);

        DB::table('studio_designs')->where('id', $designId)->update([
            'export_progress_pct' => 10,
            'updated_at'          => now(),
        ]);

        $output = [];
        $status = 0;
        exec($cmd, $output, $status);

        DB::table('studio_designs')->where('id', $designId)->update([
            'export_progress_pct' => 90,
            'updated_at'          => now(),
        ]);

        if ($status !== 0 || !file_exists($outPath) || filesize($outPath) < 1000) {
            @unlink($outPath);
            $err = implode("\n", array_slice($output, -40));
            throw new \RuntimeException('ffmpeg_failed: ' . mb_substr($err, 0, 400));
        }

        return $publicUrl;
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function localPath(string $url): ?string
    {
        if ($url === '') return null;
        // /storage/... → storage/app/public/...
        if (str_starts_with($url, '/storage/')) {
            $rel = substr($url, strlen('/storage/'));
            $p = storage_path('app/public/' . $rel);
            return is_file($p) ? $p : null;
        }
        // Absolute URL: download to tmp
        if (preg_match('#^https?://#', $url)) {
            $tmpDir = storage_path('app/studio-video-tmp');
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
            $dest = $tmpDir . '/dl-' . substr(hash('sha256', $url), 0, 16) . '.' . ($this->guessExt($url) ?: 'mp4');
            if (!file_exists($dest)) {
                $ctx = stream_context_create(['http' => ['timeout' => 30], 'https' => ['timeout' => 30]]);
                $bin = @file_get_contents($url, false, $ctx);
                if ($bin === false || strlen($bin) < 100) return null;
                file_put_contents($dest, $bin);
            }
            return $dest;
        }
        return null;
    }

    private function guessExt(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (preg_match('/\.([a-z0-9]{2,5})(?:$|[?#])/i', $path, $m)) return strtolower($m[1]);
        return null;
    }

    private function ffColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) $hex = '000000';
        return '0x' . strtoupper($hex);
    }

    private function ffXfadeType(string $type): string
    {
        return match (strtolower($type)) {
            'fade'        => 'fade',
            'slide_left'  => 'slideleft',
            'slide_right' => 'slideright',
            'slide_up'    => 'slideup',
            'slide_down'  => 'slidedown',
            'zoom_in'     => 'zoomin',
            'zoom_out'    => 'fadeblack',  // xfade has no zoomout; fadeblack is closest dramatic alt
            'dissolve'    => 'dissolve',
            'wipe'        => 'wipeleft',
            'glitch'      => 'pixelize',   // closest rough approximation
            default       => 'fade',
        };
    }

    /** Return an FFmpeg filter chain fragment (without trailing comma) for a preset name. */
    private function ffFilterChain(string $name): string
    {
        return match (strtolower($name)) {
            'none'      => '',
            'warm'      => 'eq=saturation=1.4:gamma=1.05,colorbalance=rs=.10:gs=.03:bs=-.06',
            'cool'      => 'eq=saturation=0.9:gamma=1.02,colorbalance=rs=-.08:gs=0:bs=.10',
            'bw'        => 'hue=s=0,eq=contrast=1.10',
            'b&w'       => 'hue=s=0,eq=contrast=1.10',
            'fade'      => 'eq=brightness=0.04:contrast=0.85:saturation=0.80',
            'vivid'     => 'eq=saturation=1.7:contrast=1.12',
            'cinematic' => 'eq=contrast=1.2:brightness=-0.04:saturation=0.85,colorbalance=bs=.10:rs=-.04',
            'matte'     => 'eq=contrast=0.85:brightness=0.04:saturation=0.75',
            'film'      => 'eq=contrast=1.1:brightness=-0.02:saturation=0.95,colorbalance=rs=.06:bs=-.04',
            'neon'      => 'eq=saturation=2.2:contrast=1.25,hue=h=10',
            default     => '',
        };
    }

    private function resolveFontPath(): string
    {
        foreach ([
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ] as $f) {
            if (is_file($f)) return $f;
        }
        return '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    }

    /**
     * Apply per element_type render defaults. Only fills fields the overlay
     * hasn't already set — explicit values win. Keeps renderer type-aware
     * without requiring every caller to spell out anchor/anim/outline.
     */
    private function applyElementTypeDefaults(array $t): array
    {
        $type = (string)($t['element_type'] ?? '');
        if ($type === '') return $t;

        static $defaults = null;
        if ($defaults === null) {
            $defaults = [
                'brand_name'      => ['anchor'=>'left',   'anim'=>'fade',      'animDur'=>0.4],
                'brand_pill'      => ['anchor'=>'right',  'anim'=>'fade',      'animDur'=>0.4],
                'eyebrow'         => ['anchor'=>'left',   'anim'=>'slide_up',  'animDur'=>0.5],
                'headline'        => ['anchor'=>'left',   'anim'=>'slide_up',  'animDur'=>0.6],
                'headline_accent' => ['anchor'=>'left',   'anim'=>'slide_up',  'animDur'=>0.6, 'outline'=>true],
                'subtext'         => ['anchor'=>'left',   'anim'=>'fade',      'animDur'=>0.5],
                'feature_pill'    => ['anchor'=>'left',   'anim'=>'fade',      'animDur'=>0.4],
                'cta'             => ['anchor'=>'center', 'anim'=>'scale_pop', 'animDur'=>0.4],
                'cta_ghost'       => ['anchor'=>'left',   'anim'=>'fade',      'animDur'=>0.4],
                'stat_value'      => ['anchor'=>'center', 'anim'=>'scale_pop', 'animDur'=>0.5],
                'stat_label'      => ['anchor'=>'center', 'anim'=>'fade',      'animDur'=>0.4],
                'price_value'     => ['anchor'=>'left',   'anim'=>'scale_pop', 'animDur'=>0.5],
                'price_period'    => ['anchor'=>'left',   'anim'=>'fade',      'animDur'=>0.4],
                'quote'           => ['anchor'=>'left',   'anim'=>'fade',      'animDur'=>0.6],
                'badge_float'     => ['anchor'=>'left',   'anim'=>'slide_up',  'animDur'=>0.5],
                'badge_live'      => ['anchor'=>'left',   'anim'=>'fade',      'animDur'=>0.4, 'loop'=>'pulse'],
                'listing_line'    => ['anchor'=>'left',   'anim'=>'slide_up',  'animDur'=>0.4],
                'url'             => ['anchor'=>'left',   'anim'=>'fade',      'animDur'=>0.4],
                'handle'          => ['anchor'=>'right',  'anim'=>'fade',      'animDur'=>0.4],
            ];
        }
        $d = $defaults[$type] ?? null;
        if ($d === null) return $t;

        // anchor default
        if (!isset($t['anchor']) || $t['anchor'] === '') {
            $t['anchor'] = $d['anchor'];
        }
        // animation_in default
        if (empty($t['animation_in'])) {
            $t['animation_in'] = ['type' => $d['anim'], 'duration' => $d['animDur']];
        }
        // type-specific flags
        if (!empty($d['outline']) && !isset($t['outline'])) {
            $t['outline'] = true;
        }
        if (!empty($d['loop']) && empty($t['animation_loop'])) {
            $t['animation_loop'] = ['type' => $d['loop'], 'period' => 1.2];
        }
        return $t;
    }

    private function buildDrawtext(array $t, int $W, int $H, string $fontPath): string
    {
        $text = (string)($t['content'] ?? '');
        if ($text === '') return '';
        // ── element_type defaults ──
        $t = $this->applyElementTypeDefaults($t);
        $start = (float)($t['start_time'] ?? 0);
        $end   = (float)($t['end_time']   ?? ($start + 3));
        $size  = max(10, (int)($t['font_size'] ?? 48));
        $color = $this->ffColor($t['color'] ?? '#FFFFFF');
        $anchor = (string)($t['anchor'] ?? 'center');
        $x = isset($t['position']['x']) ? (float)$t['position']['x'] : ($t['x'] ?? $W/2);
        $y = isset($t['position']['y']) ? (float)$t['position']['y'] : ($t['y'] ?? $H/2);

        // FFmpeg drawtext x/y use "tw"/"th" tokens; anchor to canvas coords.
        if ($anchor === 'center') {
            $xExpr = '(' . $x . ')-tw/2';
            $yExpr = '(' . $y . ')-th/2';
        } elseif ($anchor === 'left') {
            $xExpr = (string)$x;
            $yExpr = '(' . $y . ')-th/2';
        } elseif ($anchor === 'right') {
            $xExpr = '(' . $x . ')-tw';
            $yExpr = '(' . $y . ')-th/2';
        } else {
            $xExpr = (string)$x; $yExpr = (string)$y;
        }

        // drawtext text arg: ' and : and \ must be escaped
        $safe = $this->escapeDrawtext($text);

        // animation_in is canonically a struct {type, duration}. Older
        // records may still have a bare string — handle both.
        $animRaw = $t['animation_in'] ?? null;
        if (is_array($animRaw)) {
            $anim    = strtolower((string)($animRaw['type'] ?? 'fade'));
            $animDur = max(0.1, (float)($animRaw['duration'] ?? 0.4));
        } else {
            $anim    = strtolower((string)($animRaw ?? 'fade'));
            $animDur = 0.4;
        }
        $alphaExpr = $this->drawtextAlphaExpr($anim, $start, $end, $animDur);

        // ── Animation-in y/fontsize expressions (Phase B — v4.4.6) ──
        // Supported: fade | slide_up | slide_down | fade_rise | scale_pop | scale_in
        $yFinal    = $yExpr;
        $sizeExpr  = (string) $size;
        $fadeInEnd = $start + $animDur;

        if ($anim === 'slide_up') {
            $yFinal = "if(lt(t,{$start}),({$yExpr})+60,"
                    . "if(lt(t,{$fadeInEnd}),({$yExpr})+60*(1-(t-{$start})/{$animDur}),"
                    . "({$yExpr})))";
        } elseif ($anim === 'slide_down') {
            $yFinal = "if(lt(t,{$start}),({$yExpr})-60,"
                    . "if(lt(t,{$fadeInEnd}),({$yExpr})-60*(1-(t-{$start})/{$animDur}),"
                    . "({$yExpr})))";
        } elseif ($anim === 'fade_rise') {
            // Subtler 24px rise paired with the fade alpha.
            $yFinal = "if(lt(t,{$start}),({$yExpr})+24,"
                    . "if(lt(t,{$fadeInEnd}),({$yExpr})+24*(1-(t-{$start})/{$animDur}),"
                    . "({$yExpr})))";
        } elseif ($anim === 'scale_pop') {
            $sSmall = (int) round($size * 0.6);
            $sizeExpr = "if(lt(t,{$start}),{$sSmall},"
                      . "if(lt(t,{$fadeInEnd}),{$sSmall}+({$size}-{$sSmall})*(t-{$start})/{$animDur},"
                      . "{$size}))";
        } elseif ($anim === 'scale_in') {
            // 40% → 100% with pow-curve easing for softness
            $sSmall = (int) round($size * 0.4);
            $sizeExpr = "if(lt(t,{$start}),{$sSmall},"
                      . "if(lt(t,{$fadeInEnd}),{$sSmall}+({$size}-{$sSmall})*pow((t-{$start})/{$animDur}\\,0.5),"
                      . "{$size}))";
        }

        // ── animation_loop: pulse (alpha oscillation) ──────────────
        // Multiplies the fade alpha by 0.70 + 0.30*(sine-normalized), period in seconds.
        $loopRaw = $t['animation_loop'] ?? null;
        if (is_array($loopRaw)) {
            $loopType = strtolower((string)($loopRaw['type'] ?? ''));
            $loopPer  = max(0.2, (float)($loopRaw['period'] ?? 1.2));
        } else {
            $loopType = strtolower((string)($loopRaw ?? ''));
            $loopPer  = 1.2;
        }
        if ($loopType === 'pulse') {
            $alphaExpr = '(' . $alphaExpr . ')*(0.70+0.30*(1+sin(2*PI*t/' . $loopPer . '))/2)';
        }

        $parts = [
            "fontfile=" . str_replace(':', '\\:', $fontPath),
            'expansion=none',
            "text='{$safe}'",
            "fontcolor={$color}",
            "fontsize='{$sizeExpr}'",
            "x={$xExpr}",
            "y='{$yFinal}'",
            "enable='between(t,{$start},{$end})'",
            "alpha='{$alphaExpr}'",
        ];
        if (!empty($t['shadow'])) { $parts[] = 'shadowcolor=black@0.55'; $parts[] = 'shadowx=2'; $parts[] = 'shadowy=2'; }
        if (!empty($t['outline'])) { $parts[] = 'bordercolor=black'; $parts[] = 'borderw=3'; }
        return 'drawtext=' . implode(':', $parts);
    }

    /**
     * Build FFmpeg filters for a Slice-B element (logo / lower_third / badge /
     * countdown). Logo uses overlay with an extra -i input; the other 3 are
     * drawbox/drawtext chains applied to the current label.
     *
     * Returns an array:
     *   [ 'extraInputs' => [...], 'filterChain' => 'drawtext=..., drawtext=...' ]
     * 'extraInputs' is indexed by logical position; caller re-numbers when
     * building the overall input list.
     */
    private function buildElementFilter(array $el, int $W, int $H, string $fontPath, int $inputOffset): array
    {
        $type = (string)($el['type'] ?? '');
        $startT = (float)($el['start_time'] ?? 0);
        $endT   = (float)($el['end_time']   ?? ($startT + 5));
        $enable = "enable='between(t,{$startT},{$endT})'";
        $fontfile = "fontfile=" . str_replace(':', '\\:', $fontPath);

        $pos = $el['position'] ?? [];
        $x = (int) ($pos['x'] ?? 80);
        $y = (int) ($pos['y'] ?? 80);

        $extraInputs = [];
        $filterChain = null;
        $preInsert = null;  // filter that must run BEFORE the chain (rare)
        $overlayInput = null; // if set, we need to run [prev][input]overlay

        if ($type === 'logo') {
            $url = $el['content']['url'] ?? null;
            $src = $url ? $this->localPath($url) : null;
            if (!$src) return ['extraInputs' => [], 'filterChain' => ''];
            $sz = $el['size'] ?? [];
            $w  = (int) ($sz['width']  ?? 200);
            $h  = (int) ($sz['height'] ?? 80);
            $op = max(0.0, min(1.0, (float)($el['opacity'] ?? 1.0)));
            // Read as input; scale + alpha-multiply; then overlay.
            $extraInputs[] = '-i ' . escapeshellarg($src);
            $overlayInput = [
                'prep_filter' => "[{$inputOffset}:v]scale={$w}:{$h},format=rgba,"
                              . "colorchannelmixer=aa={$op}[logoInput" . $inputOffset . "]",
                'overlay_label' => 'logoInput' . $inputOffset,
                'overlay_args'  => "x={$x}:y={$y}:{$enable}",
            ];
            return [
                'extraInputs'  => $extraInputs,
                'overlayInput' => $overlayInput,
                'filterChain'  => '',
            ];
        }

        if ($type === 'lower_third') {
            $c = $el['content'] ?? [];
            $name = $this->escapeDrawtext((string)($c['name']  ?? 'Your Name'));
            $title= $this->escapeDrawtext((string)($c['title'] ?? 'Your Title'));
            $color= $c['color'] ?? '#C9A56A';
            $colFF= $this->ffColor($color);
            $sz   = $el['size'] ?? [];
            $w    = (int) ($sz['width']  ?? $W * 0.6);
            $h    = 160;
            // Background box + accent stripe + name + title
            $filters = [
                "drawbox=x={$x}:y={$y}:w={$w}:h={$h}:color=black@0.85:t=fill:{$enable}",
                "drawbox=x={$x}:y={$y}:w=8:h={$h}:color={$colFF}:t=fill:{$enable}",
                "drawtext={$fontfile}:text='{$name}':fontcolor=white:fontsize=44:x=" . ($x + 32) . ":y=" . ($y + 52) . ":{$enable}",
                "drawtext={$fontfile}:text='{$title}':fontcolor={$colFF}:fontsize=22:x=" . ($x + 32) . ":y=" . ($y + 105) . ":{$enable}",
            ];
            $filterChain = implode(',', $filters);
            return ['extraInputs' => [], 'filterChain' => $filterChain];
        }

        if ($type === 'badge') {
            $c = $el['content'] ?? [];
            $txt   = $this->escapeDrawtext((string)($c['text'] ?? 'NEW'));
            $bg    = $this->ffColor($c['bg_color']   ?? '#6C5CE7');
            $fg    = $this->ffColor($c['text_color'] ?? '#FFFFFF');
            // Approximate pill width — 28px font, ~16px per character + padding
            $approxW = max(80, 16 * max(1, strlen((string)($c['text'] ?? 'NEW'))) + 44);
            $h = 56;
            $filters = [
                "drawbox=x={$x}:y={$y}:w={$approxW}:h={$h}:color={$bg}:t=fill:{$enable}",
                "drawtext={$fontfile}:text='{$txt}':fontcolor={$fg}:fontsize=28:x=" . ($x + 22) . ":y=" . ($y + 15) . ":{$enable}",
            ];
            return ['extraInputs' => [], 'filterChain' => implode(',', $filters)];
        }

        if ($type === 'countdown') {
            $c = $el['content'] ?? [];
            $size = (int) (($el['size']['font_size'] ?? 260));
            $fg   = $this->ffColor($c['color'] ?? '#FFFFFF');
            $endText = $this->escapeDrawtext((string)($c['end_text'] ?? 'GO!'));
            $halfX = (int) ($W / 2);
            $halfY = (int) ($H / 2);
            $mk = function($label, $t0, $t1) use ($fontfile, $fg, $size, $halfX, $halfY) {
                $xExpr = "({$halfX})-tw/2";
                $yExpr = "({$halfY})-th/2";
                return "drawtext={$fontfile}:text='{$label}':fontcolor={$fg}:fontsize={$size}:x={$xExpr}:y={$yExpr}:enable='between(t,{$t0},{$t1})'";
            };
            // 3 → 2 → 1 → GO! each 1 second, starting at startT
            $t = $startT;
            $filters = [
                $mk('3',      $t,     $t + 1),
                $mk('2',      $t + 1, $t + 2),
                $mk('1',      $t + 2, $t + 3),
                $mk($endText, $t + 3, $t + 4),
            ];
            return ['extraInputs' => [], 'filterChain' => implode(',', $filters)];
        }

        return ['extraInputs' => [], 'filterChain' => ''];
    }

    /**
     * Build an alpha(t) expression so the overlay fades in at start_time over
     * animDur, stays visible, then fades out at end_time over animDur.
     * Slice A treats every non-fade animation as fade (drawtext alpha only).
     */
    private function drawtextAlphaExpr(string $anim, float $start, float $end, float $animDur): string
    {
        $outDur = min($animDur, max(0.1, ($end - $start) * 0.25));
        $fadeInEnd = $start + $animDur;
        $fadeOutStart = max($fadeInEnd, $end - $outDur);
        // Clamp to [0,1]. if t<start: 0; if t<fadeInEnd: (t-start)/animDur; if t<fadeOutStart: 1; if t<end: 1-(t-fadeOutStart)/outDur; else 0
        return "if(lt(t,{$start}),0,"
             . "if(lt(t,{$fadeInEnd}),(t-{$start})/{$animDur},"
             . "if(lt(t,{$fadeOutStart}),1,"
             . "if(lt(t,{$end}),1-(t-{$fadeOutStart})/{$outDur},0))))";
    }

    private function escapeDrawtext(string $s): string
    {
        // drawtext text arg: escape \ : ' , newline.
        // `%` is NOT escaped here — the caller adds expansion=none, which
        // makes FFmpeg render `%` literally. \% by itself causes drawtext
        // to render nothing at all.
        $s = str_replace(['\\', "'", ':', ','], ['\\\\', "\\'", '\\:', '\\,'], $s);
        $s = str_replace(["\r", "\n"], [' ', ' '], $s);
        return $s;
    }
}
