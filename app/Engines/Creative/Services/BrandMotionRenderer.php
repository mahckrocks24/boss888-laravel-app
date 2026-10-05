<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0025 P1 (2026-10-03): the brand motion layer. Banners and videos share one design system: the banner renderer
 * (ImageOverlayRenderer) builds the page - the business's fonts, palette, zone chosen from the clip's own pixels, accent
 * word, rule, design-direction layout, logo lockup - and this class turns that page into a transparent, animated layer:
 *   - placed inside the target platform's safe zone (Reels/TikTok keep the top 14 %, the bottom 30 % and the right 20 %);
 *   - animated by preset (text rises in, rule draws, copy and logo follow, everything clears before the end card);
 *   - a corner logo when the business has one (D2) and a brand end card over the last 1.5 s (D4), built only from facts
 *     in the business record (logo or name, then website, else phone, else location);
 *   - recorded frame by frame by tools/brand-motion-record.cjs and composited onto the clip with ffmpeg.
 * The untitled clip stays as "-raw.mp4"; VideoTitler remains the fallback when this fails.
 */
final class BrandMotionRenderer
{
    /** Clear margins per profile, as fractions: [top, bottom, left, right]. RFC-0025 2.1 */
    public const SAFE = [
        'instagram_reels' => [0.14, 0.30, 0.06, 0.20],
        'tiktok'          => [0.10, 0.30, 0.06, 0.20],
        'facebook_reels'  => [0.14, 0.20, 0.10, 0.10],
        'youtube_shorts'  => [0.12, 0.25, 0.06, 0.15],
        'pinterest'       => [0.15, 0.15, 0.10, 0.10],
        'feed_square'     => [0.08, 0.08, 0.08, 0.08],
        'website'         => [0.06, 0.06, 0.06, 0.06],
    ];

    public static function profileFor(int $w, int $h, ?string $platform = null): string
    {
        $p = strtolower((string) $platform);
        if ($w > $h) return 'website';
        if (abs($w - $h) < 8) return 'feed_square';
        if (str_contains($p, 'pinterest')) return 'pinterest';
        if (str_contains($p, 'tiktok')) return 'tiktok';
        if (str_contains($p, 'youtube') || str_contains($p, 'short')) return 'youtube_shorts';
        if (str_contains($p, 'facebook')) return 'facebook_reels';
        return 'instagram_reels';
    }

    /** Rollout switch: storage/app/videolayer2.on lists workspace ids, one per line, or "*" for every workspace. */
    public static function enabled(?int $wsId = null): bool
    {
        $f = storage_path('app/videolayer2.on');
        if (! is_file($f)) return false;
        $ids = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) @file_get_contents($f)) ?: []));
        return in_array('*', $ids, true) || ($wsId !== null && in_array((string) $wsId, $ids, true));
    }

    /**
     * @param array{ws:int, business_id?:?int, headline?:?string, supporting?:array, platform?:?string, logo?:bool, end_card?:bool} $ctx
     * @return array{success:bool, error?:string, layout?:string, zone?:string, profile?:string, fonts_loaded?:array, logo?:bool, end_card?:bool, shots?:int}
     */
    public function apply(string $file, array $ctx): array
    {
        if (! is_file($file)) return ['success' => false, 'error' => 'no_clip'];
        $raw = preg_replace('/\.mp4$/i', '', $file) . '-raw.mp4';
        $src = is_file($raw) ? $raw : $file;   // always composite onto the untitled clip

        $__t0 = microtime(true); $__t = [];
        $probe = trim((string) @shell_exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height,r_frame_rate:format=duration -of default=nw=1 ' . escapeshellarg($src) . ' 2>/dev/null'));
        preg_match('/width=(\d+)/', $probe, $mw); preg_match('/height=(\d+)/', $probe, $mh);
        preg_match('/r_frame_rate=(\d+)\/(\d+)/', $probe, $mf); preg_match('/duration=([\d.]+)/', $probe, $md);
        $w = (int) ($mw[1] ?? 0); $h = (int) ($mh[1] ?? 0);
        $fps = isset($mf[1]) && (int) $mf[2] > 0 ? (int) round($mf[1] / $mf[2]) : 24;
        $dur = (float) ($md[1] ?? 0);
        if ($w < 200 || $h < 200 || $dur < 2) return ['success' => false, 'error' => 'probe_failed'];

        $ws = (int) $ctx['ws']; $biz = isset($ctx['business_id']) ? (int) $ctx['business_id'] : null;
        $headline = trim((string) ($ctx['headline'] ?? ''));
        $profile = self::profileFor($w, $h, $ctx['platform'] ?? null);
        [$sT, $sB, $sL, $sR] = self::SAFE[$profile];
        $vertical = $h > $w;

        // the clip's own middle frame: the zone is chosen from what is really behind the words
        $tmp = storage_path('app/studio-render-tmp'); if (! is_dir($tmp)) @mkdir($tmp, 0775, true);
        $stamp = bin2hex(random_bytes(6));
        $framePng = "$tmp/bm-$stamp-frame.png";
        @shell_exec('ffmpeg -v error -y -ss ' . number_format(min(2.5, $dur / 2), 2, '.', '') . ' -i ' . escapeshellarg($src) . ' -frames:v 1 ' . escapeshellarg($framePng) . ' 2>/dev/null');
        if (! is_file($framePng)) return ['success' => false, 'error' => 'frame_failed'];
        $frameBytes = (string) file_get_contents($framePng); @unlink($framePng);
        $__t['frame'] = round(microtime(true) - $__t0, 2);

        $kit = [];
        try { $kit = (array) app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($ws, $biz); } catch (\Throwable) {}
        // VIDEO-RENDER-1: a site path ("/storage/...") never loads in the layer page, which renders from a local file
        $logoUrl = trim((string) (str_starts_with($__l = (string) ($kit['logo_url'] ?? ''), '/') && ! str_starts_with($__l, '//') ? rtrim((string) config('app.url'), '/') . $__l : $__l));
        if ($logoUrl !== '' && self::logoIsBlank($logoUrl)) $logoUrl = '';   // VIDEO-RENDER-4: an empty logo file is no logo
        // a logo and an end card speak for ONE business: only when the clip belongs to a known business of this workspace
        // (photo probe: an unattributed clip showed the workspace's own name and another business's website)
        $bizKnown = $biz && DB::table('businesses')->where('id', $biz)->where('workspace_id', $ws)->whereNull('deleted_at')->exists();
        $logoOn = ($ctx['logo'] ?? true) && $logoUrl !== '' && $bizKnown;
        $endOn = ($ctx['end_card'] ?? true) && $dur >= 5.5 && $bizKnown;

        // the business's own type and palette, as the banner path passes them (a neutral kit lets the brief choose)
        $first = fn ($v) => trim(explode(',', (string) $v)[0] ?? '', " '\"");
        $branded = empty($kit['is_neutral']);
        $kitHead = $branded ? $first($kit['heading_font'] ?? '') : '';
        $kitBody = $branded ? $first($kit['body_font'] ?? '') : '';
        $page = app(\App\Core\ImageIntelligence\ImageOverlayRenderer::class)->layerHtml(array_filter([
            'headline' => $headline !== '' ? $headline : ' ',
            'supporting_copy' => array_values(array_filter(array_map('strval', (array) ($ctx['supporting'] ?? [])))),
            'placement' => (string) ($ctx['placement'] ?? 'upper-left'),   // RFC-0025 P4: the gate can move it
            'style' => (string) ($kit['visual_style'] ?? ''),
            'business_id' => $biz,
            'direction_id' => (string) ($kit['design_direction_id'] ?? ''),
            'fonts' => $kitHead !== '' ? array_filter(['heading' => $kitHead, 'body' => $kitBody]) : null,
            'color_palette' => $branded ? array_values(array_filter([(string) ($kit['primary_color'] ?? ''), (string) ($kit['secondary_color'] ?? ''), (string) ($kit['accent_color'] ?? '')])) : null,
        ], fn ($v) => $v !== null), $frameBytes, $w, $h, $ws);
        $html = (string) $page['html'];
        $layout = (string) $page['layout'];
        $row = (int) $page['row']; $col = (int) $page['col'];
        $b = (array) $page['brief'];
        // the fonts the page really uses: A1 takes the kit's, the zone layout the brief's
        $useHead = $layout === 'A1' && $kitHead !== '' ? $kitHead : str_replace("'", '', (string) ($b['font_head'] ?? ''));
        $useBody = $layout === 'A1' && $kitBody !== '' ? $kitBody : str_replace("'", '', (string) ($b['font_body'] ?? ''));

        // transparent: no picture, no canvas fill
        $html = (string) preg_replace('/<img class="bg"[^>]*>/', '', $html, 1);
        $html = (string) preg_replace('/(\.canvas \{[^}]*?)background:[^;]+;/', '$1background:transparent;', $html, 1);
        if ($headline === '') $html = str_replace('</style>', ".box,.col,.scrim,.fade{display:none!important}\n</style>", $html);   // no quoted words: logo and end card only

        // safe zone: the text block and the lockup stay out of the platform's own buttons and captions
        $px = fn (float $f, int $d) => (int) round($f * $d);
        $sel = $layout === 'A1' ? '.col' : '.box';
        $css = "html,body{background:transparent!important}\n";
        if ($row === 0) $css .= "$sel{top:{$px($sT + 0.03, $h)}px!important}\n";
        if ($row === 2) $css .= "$sel{bottom:{$px($sB + 0.03, $h)}px!important;top:auto!important}\n";
        if ($col === 0) $css .= "$sel{left:{$px(max($sL, 0.062), $w)}px!important}\n";
        if ($col === 2) $css .= "$sel{right:{$px($sR + 0.02, $w)}px!important;left:auto!important}\n";
        $css .= "$sel{max-width:" . $px(1 - $sL - $sR - 0.02, $w) . "px!important}\n";
        if ($layout === 'A1') $css .= ".lock{bottom:{$px($sB + 0.02, $h)}px!important}\n";
        // VIDEO-RENDER-2: a vertical or square clip is watched on a phone - small copy is lifted to a readable size
        if ($w <= $h * 1.05) $css .= '.copy,.sub{font-size:' . $px(0.036, $w) . 'px!important}' . "\n" . '.wm{font-size:' . $px(0.03, $w) . 'px!important}.wd{font-size:' . $px(0.017, $w) . 'px!important}' . "\n";

        // corner logo (zone layout; A1 already carries its lockup): the top corner opposite the text, inside the safe zone
        $logoHtml = '';
        if ($logoOn && $layout !== 'A1') {
            $lh = $vertical ? $px(0.10, $w) : $px(0.11, $h);
            // certification round A: a fixed corner put the logo on a patient's face and, on tall frames, in the headline row.
            // The logo takes the CALMEST free corner of the clip's own frame (least detail = background, not a face or the
            // subject); the text's own corner and, on tall frames, the text's row are never candidates.
            $tall = $h > $w;
            $edgeL = $px(max($sL, 0.06), $w); $edgeR = $px($sR + 0.02, $w); $edgeT = $px($sT + 0.02, $h); $edgeB = $px($sB + 0.02, $h);
            $cands = [];
            foreach (['top', 'bottom'] as $vy) foreach (['left', 'right'] as $hx) {
                $cr = $vy === 'top' ? 0 : 2; $cc = $hx === 'left' ? 0 : 2;
                if ($cr === $row && ($tall || $cc === $col || $col === 1)) continue;   // never the text's row on tall frames, never its corner
                $cands[] = [$vy, $hx];
            }
            if (! $cands) $cands = [['bottom', $col === 2 ? 'left' : 'right']];
            $boxW = $px(0.30, $w); $boxH = $lh + 2 * (int) round($lh * 0.16);
            $best = null; $bestScore = INF;
            foreach ($cands as [$vy, $hx]) {
                $x = $hx === 'left' ? $edgeL : $w - $edgeR - $boxW;
                $y = $vy === 'top' ? $edgeT : $h - $edgeB - $boxH;
                $score = self::detail($frameBytes, $w, $h, $x, $y, $boxW, $boxH);
                if ($score < $bestScore) { $bestScore = $score; $best = [$vy, $hx]; }
            }
            [$vy, $hx] = $best;
            $side = $hx === 'left' ? 'left:' . $edgeL . 'px' : 'right:' . $edgeR . 'px';
            $vpos = $vy === 'top' ? 'top:' . $edgeT . 'px' : 'bottom:' . $edgeB . 'px';
            // a backing plate the logo can always be read on: dark behind a light logo, light behind a dark one
            $plate = self::logoIsLight($logoUrl) ? 'rgba(10,10,12,.42)' : 'rgba(250,248,244,.80)';
            $padY = (int) round($lh * 0.16); $padX = (int) round($lh * 0.26);
            $logoHtml = '<div class="blogo" style="position:absolute;' . $side . ';' . $vpos . ';padding:' . $padY . 'px ' . $padX . 'px;border-radius:' . (int) round($lh * 0.3) . 'px;background:' . $plate . '">'
                . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="" style="display:block;height:' . (int) round($lh * 0.78) . 'px;width:auto"></div>';
        }

        // end card: only facts in the business record
        $endHtml = '';
        $outAt = $endOn ? max(2.2, $dur - 1.9) : $dur + 1;
        if ($endOn) {
            $bizRow = $biz ? DB::table('businesses')->where('id', $biz)->where('workspace_id', $ws)->first(['name', 'phone', 'location']) : null;
            $site = null;
            try {
                $q = DB::table('websites')->where('workspace_id', $ws)->whereNull('deleted_at')->whereNotNull('published_at');
                $q->where('business_id', $biz);   // only this business's own site
                $sr = $q->orderByDesc('published_at')->first(['custom_domain', 'domain_verified', 'domain', 'subdomain']);
                if ($sr) $site = ($sr->custom_domain && $sr->domain_verified ? $sr->custom_domain : null) ?: ($sr->domain ?: $sr->subdomain);
            } catch (\Throwable) {}
            $name = trim((string) ($kit['brand_name'] ?? ($bizRow->name ?? '')));
            $line = $site ? (str_contains((string) $site, '.') ? (string) $site : $site . '.levelupgrowth.io') : (trim((string) ($bizRow->phone ?? '')) ?: trim((string) ($bizRow->location ?? '')));
            $ink = preg_match('/^#[0-9a-f]{6}$/i', (string) ($kit['primary_color'] ?? '')) ? (string) $kit['primary_color'] : '#0B0A09';
            $fh = $useHead !== '' ? $useHead : 'DejaVu Serif';
            $fb = $useBody !== '' ? $useBody : 'DejaVu Sans';
            // VIDEO-RENDER-3: a dark logo on a dark card gets a light plate (it vanished on the brand colour)
            $__inkLum = (function (string $hx) { $hx = ltrim($hx, '#'); return strlen($hx) === 6 ? (0.2126 * hexdec(substr($hx, 0, 2)) + 0.7152 * hexdec(substr($hx, 2, 2)) + 0.0722 * hexdec(substr($hx, 4, 2))) / 255 : 0.0; })($ink);
            $__plate = $logoUrl !== '' && $__inkLum < 0.5 && ! self::logoIsLight($logoUrl);
            $mark = $logoUrl !== ''
                ? ($__plate ? '<div style="display:inline-block;background:rgba(250,248,244,.94);border-radius:' . $px(0.02, $vertical ? $w : $h) . 'px;padding:' . $px(0.022, $vertical ? $w : $h) . 'px ' . $px(0.04, $vertical ? $w : $h) . 'px;margin:0 auto 4%">' : '')
                  . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="" style="height:' . ($vertical ? $px(0.16, $w) : $px(0.16, $h)) . 'px;width:auto;display:block;margin:' . ($__plate ? '0 auto' : '0 auto 4%') . '">' . ($__plate ? '</div>' : '')
                : '<div style="font-family:\'' . $fh . '\',\'DejaVu Serif\',serif;font-weight:600;font-size:' . ($vertical ? $px(0.075, $w) : $px(0.085, $h)) . 'px;color:#fff;letter-spacing:.01em;line-height:1.1">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>';
            $endHtml = '<div class="ec" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;background:' . $ink . 'eb;padding:0 10%">'
                . '<div>' . $mark
                . ($line !== '' ? '<div style="margin-top:3%;font-family:\'' . $fb . '\',\'DejaVu Sans\',sans-serif;font-weight:500;font-size:' . ($vertical ? $px(0.036, $w) : $px(0.042, $h)) . 'px;color:rgba(255,255,255,.88);letter-spacing:.04em">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>' : '')
                . '</div></div>';
        }

        // legibility glow behind the text block - opacity and colour from the brief's tone (light text gets a dark glow)
        $glow = ($b['tone'] ?? 'light') === 'light' ? '8,6,4' : '250,247,240';
        $css .= "$sel{isolation:isolate}\n$sel::before{content:'';position:absolute;inset:-24% -18%;z-index:-1;background:radial-gradient(closest-side,rgba($glow," . (! empty($ctx['strong_glow']) ? '.88' : '.70') . "),rgba($glow," . (! empty($ctx['strong_glow']) ? '.55' : '.38') . ") 55%,rgba($glow,0))}\n";   // RFC-0025 P4: the contrast gate can ask for more
        // states: each screenshot shows one part of the layer; ffmpeg animates them at the clip's own frame rate
        $css .= "body.s-text .blogo,body.s-text .lock,body.s-text .ec{display:none!important}\n"
            . "body.s-logo .box,body.s-logo .col,body.s-logo .scrim,body.s-logo .fade,body.s-logo .ec{display:none!important}\n"
            . "body.s-end .box,body.s-end .col,body.s-end .scrim,body.s-end .fade,body.s-end .blogo,body.s-end .lock{display:none!important}\n"
            . "body.s-glyph .scrim,body.s-glyph .fade,body.s-glyph .blogo,body.s-glyph .lock,body.s-glyph .ec{display:none!important}body.s-glyph .rule{visibility:hidden!important}body.s-glyph $sel::before{display:none!important}\n";   // the letters alone, for the quality gate
        $html = str_replace('</style>', $css . '</style>', $html);
        $html = str_replace('</div></body>', $logoHtml . $endHtml . '</div></body>', $html);

        $htmlPath = "$tmp/bm-$stamp.html";
        file_put_contents($htmlPath, $html);
        $shots = [];
        if ($headline !== '') { $shots['text'] = "$tmp/bm-$stamp-text.png"; $shots['glyph'] = "$tmp/bm-$stamp-glyph.png"; }
        if ($logoHtml !== '' || $layout === 'A1') $shots['logo'] = "$tmp/bm-$stamp-logo.png";
        if ($endOn) $shots['end'] = "$tmp/bm-$stamp-end.png";
        if (! $shots) { @unlink($htmlPath); return ['success' => false, 'error' => 'nothing_to_draw']; }
        $fonts = array_values(array_unique(array_filter([$useHead, $headline !== '' || $endOn ? $useBody : ''])));
        $args = json_encode(['htmlPath' => $htmlPath, 'width' => $w, 'height' => $h, 'fonts' => $fonts,
            'shots' => array_values(array_map(fn ($k, $p) => ['cls' => 's-' . $k, 'out' => $p], array_keys($shots), $shots))]);
        $env = ['HOME' => '/tmp', 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'PUPPETEER_CACHE_DIR' => base_path('.puppeteer-cache'), 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8'];
        $proc = proc_open('nice -n 10 node ' . escapeshellarg(base_path('tools/brand-layer-shots.cjs')) . ' ' . escapeshellarg((string) $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        if (! is_resource($proc)) { @unlink($htmlPath); return ['success' => false, 'error' => 'spawn_failed']; }
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($proc);
        $__t['shots'] = round(microtime(true) - $__t0, 2);
        if (getenv('BM_KEEP')) @copy($htmlPath, '/tmp/bm-last.html');
        @unlink($htmlPath);
        $rec = json_decode((string) trim((string) strrchr("\n" . trim((string) $stdout), "\n")), true) ?: [];
        // RFC-0025 P4: the quality gate measures contrast and the safe zone on the real text pixels - keep the text state
        $keptText = null;
        $clean = function () use ($shots, $file, &$keptText) {
            foreach ($shots as $k => $p) {
                if ($k === 'glyph' && is_file($p)) { $keptText = preg_replace('/\.mp4$/i', '', $file) . '-textlayer.png'; @rename($p, $keptText); continue; }
                @unlink($p);
            }
        };
        if ($status !== 0 || empty($rec['ok']) || array_filter($shots, fn ($p) => ! is_file($p))) {
            $clean();
            Log::warning('[RFC-0025] brand layer shots failed', ['file' => basename($file), 'out' => mb_substr($stdout . $stderr, 0, 400)]);
            return ['success' => false, 'error' => 'shots_failed'];
        }

        // motion preset (D1, "rise"): text fades in and rises with an ease-out over 0.7 s from 0.35 s, the logo follows at
        // 1.2 s, both clear 0.4 s before the end card, which fades in over the last 1.5 s
        $o = number_format($outAt, 2, '.', ''); $e = number_format(max(0, $dur - 1.5), 2, '.', '');
        $rise = max(10, $px(0.014, $h));
        $inputs = ' -i ' . escapeshellarg($src); $graph = []; $last = '[0:v]'; $n = 1;
        foreach ($shots as $k => $p) {
            if ($k === 'glyph') continue;   // measured by the gate, never composited
            $inputs .= ' -loop 1 -framerate ' . $fps . ' -t ' . number_format($dur, 3, '.', '') . ' -i ' . escapeshellarg($p);
            if ($k === 'text') {
                $graph[] = "[$n:v]format=rgba,fade=t=in:st=0.35:d=0.6:alpha=1" . ($endOn ? ",fade=t=out:st=$o:d=0.4:alpha=1" : '') . "[l$n]";
                $graph[] = "{$last}[l$n]overlay=x=0:y='$rise*pow(1-min(1,max(0,(t-0.35)/0.7)),3)':eval=frame[v$n]";
            } elseif ($k === 'logo') {
                $graph[] = "[$n:v]format=rgba,fade=t=in:st=1.2:d=0.6:alpha=1" . ($endOn ? ",fade=t=out:st=$o:d=0.4:alpha=1" : '') . "[l$n]";
                $graph[] = "{$last}[l$n]overlay=0:0[v$n]";
            } else {
                $graph[] = "[$n:v]format=rgba,fade=t=in:st=$e:d=0.5:alpha=1[l$n]";
                $graph[] = "{$last}[l$n]overlay=0:0[v$n]";
            }
            $last = "[v$n]"; $n++;
        }
        $graph[] = "{$last}format=yuv420p[vout]";
        $graphFile = "$tmp/bm-$stamp-graph.txt";
        file_put_contents($graphFile, implode(";\n", $graph));
        $out = preg_replace('/\.mp4$/i', '', $file) . '-layer.mp4';
        $log = (string) @shell_exec('nice -n 10 ffmpeg -v error -y' . $inputs . ' -filter_complex_script ' . escapeshellarg($graphFile)
            . ' -map "[vout]" -map 0:a? -c:v libx264 -preset ultrafast -crf 20 -movflags +faststart -c:a copy -t ' . number_format($dur, 3, '.', '') . ' ' . escapeshellarg($out) . ' 2>&1');
        @unlink($graphFile); $clean();
        $__t['composite'] = round(microtime(true) - $__t0, 2);
        if (! is_file($out) || filesize($out) < 10000) { @unlink($out); Log::warning('[RFC-0025] brand layer composite failed', ['file' => basename($file), 'ffmpeg' => mb_substr($log, 0, 400)]); return ['success' => false, 'error' => 'composite_failed']; }
        if (! is_file($raw)) rename($file, $raw);
        rename($out, $file);
        $res = ['success' => true, 'layout' => $layout, 'zone' => (string) ($b['zone'] ?? ''), 'profile' => $profile, 'fonts_loaded' => (array) ($rec['fontsLoaded'] ?? []),
            'logo' => $logoOn, 'end_card' => $endOn, 'shots' => count($shots), 'headline' => $headline, 'timing_s' => $__t, 'text_png' => $keptText, 'profile_safe' => self::SAFE[$profile],
            'logo_expected' => $logoUrl !== '' && $bizKnown && ($ctx['logo'] ?? true), 'head_font' => $useHead];
        Log::info('[RFC-0025] brand motion layer composited', ['file' => basename($file)] + $res);
        return $res;
    }

    /** How much detail a region of the frame carries (mean absolute neighbour difference of luminance, 0..255). */
    public static function detail(string $frameBytes, int $w, int $h, int $x, int $y, int $bw, int $bh): float
    {
        static $cache = [];
        $key = md5($frameBytes);
        $im = $cache[$key] ??= @imagecreatefromstring($frameBytes);
        if (! $im) return 0.0;
        $sx = imagesx($im) / max(1, $w); $sy = imagesy($im) / max(1, $h);
        $x0 = (int) max(0, $x * $sx); $y0 = (int) max(0, $y * $sy); $x1 = (int) min(imagesx($im) - 2, ($x + $bw) * $sx); $y1 = (int) min(imagesy($im) - 2, ($y + $bh) * $sy);
        $lum = fn ($c) => 0.2126 * (($c >> 16) & 255) + 0.7152 * (($c >> 8) & 255) + 0.0722 * ($c & 255);
        $sum = 0.0; $n = 0; $step = max(2, (int) (($x1 - $x0) / 50));
        for ($yy = $y0; $yy < $y1; $yy += $step) for ($xx = $x0; $xx < $x1; $xx += $step) {
            $c = $lum(imagecolorat($im, $xx, $yy));
            $sum += abs($c - $lum(imagecolorat($im, $xx + 1, $yy))) + abs($c - $lum(imagecolorat($im, $xx, $yy + 1))); $n++;
        }
        return $n ? $sum / $n : 0.0;
    }

    /** VIDEO-RENDER-4: a logo that has no visible pixel (fully transparent, or an unreadable image read from our own storage). */
    public static function logoIsBlank(string $url): bool
    {
        try {
            $own = rtrim((string) config('app.url'), '/') . '/storage/';
            if (! str_starts_with($url, $own)) return false;   // a remote logo is trusted; only our own files are measured
            $p = substr($url, strlen($own));
            if (! \Illuminate\Support\Facades\Storage::disk('public')->exists($p)) return true;
            $im = @imagecreatefromstring((string) \Illuminate\Support\Facades\Storage::disk('public')->get($p));
            if (! $im) return true;
            $w = imagesx($im); $h = imagesy($im); $step = max(1, (int) floor(min($w, $h) / 60));
            for ($y = 0; $y < $h; $y += $step) for ($x = 0; $x < $w; $x += $step) { if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 120) return false; }
            return true;
        } catch (\Throwable) { return false; }
    }

    /** Is the logo mostly light (a white wordmark) - measured on its opaque pixels. */
    public static function logoIsLight(string $url): bool
    {
        try {
            $own = rtrim((string) config('app.url'), '/') . '/storage/';
            $bytes = str_starts_with($url, $own) && \Illuminate\Support\Facades\Storage::disk('public')->exists(substr($url, strlen($own)))
                ? (string) \Illuminate\Support\Facades\Storage::disk('public')->get(substr($url, strlen($own)))
                : (string) \Illuminate\Support\Facades\Http::timeout(10)->get($url)->body();
            $im = @imagecreatefromstring($bytes);
            if (! $im) return true;
            $w = imagesx($im); $h = imagesy($im); $sum = 0.0; $n = 0;
            $step = max(1, (int) floor(min($w, $h) / 60));
            for ($y = 0; $y < $h; $y += $step) for ($x = 0; $x < $w; $x += $step) {
                $c = imagecolorat($im, $x, $y); $a = ($c >> 24) & 0x7F;
                if ($a > 40) continue;   // mostly transparent
                $sum += (0.2126 * (($c >> 16) & 255) + 0.7152 * (($c >> 8) & 255) + 0.0722 * ($c & 255)) / 255; $n++;
            }
            return $n === 0 ? true : ($sum / $n) > 0.55;
        } catch (\Throwable) { return true; }
    }

    private static function rmdir(string $d): void
    {
        if (! is_dir($d)) return;
        foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
        @rmdir($d);
    }
}
