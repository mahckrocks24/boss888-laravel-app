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
 * RENDER-BRIEF-1 (RISK-0207, Owner 2026-09-30: "Use renderer but must honor prompt"). The renderer used to be ONE
 * fixed template - Syne 800, white, centred at the top, a full-frame dark gradient, the accent bar in the first
 * palette colour - whatever the blueprint's typography_strategy said. It now reads the brief:
 *   - placement  ("upper-left negative space", "bottom band", "centred") -> the zone the text sits in;
 *   - style      (font names, hex colours, "thin rule", "small caps-tracking", "italic", weights) -> the type;
 *   - color_palette / fonts from the brand kit -> defaults when the style names none;
 *   - the background itself (GD) -> the quietest zone near the asked placement, so the words never sit on the
 *     subject, and light or dark type + a LOCAL scrim decided from what is actually behind the text.
 *
 * Returns the composited PNG's public URL + storage path, or null on failure
 * (caller keeps the clean background so a render error never loses the image).
 */
class ImageOverlayRenderer
{
    /** 3x3 zone grid: id => [col, row] */
    private const ZONES = [
        'upper-left' => [0, 0], 'upper-centre' => [1, 0], 'upper-right' => [2, 0],
        'middle-left' => [0, 1], 'centre' => [1, 1], 'middle-right' => [2, 1],
        'lower-left' => [0, 2], 'lower-centre' => [1, 2], 'lower-right' => [2, 2],
    ];

    /**
     * @param string $bgStoragePath  public-disk relative path to the AI background (e.g. ai-images/1/x.png)
     * @param array  $overlay        {headline, supporting_copy[], placement, style, color_palette[], fonts?, text_tone?, accent?}
     * @param int    $w
     * @param int    $h
     * @param int    $wsId
     * @return array{success:bool, url?:string, storage_path?:string, error?:string, zone?:string}
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

        $brief = $this->brief($overlay, $bytes, $w, $h);
        // LAYOUT-A1: the editorial-luxury direction gets the designed layout, with the brand kit
        $kit = null;
        try { $kit = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($wsId, isset($overlay['business_id']) ? (int) $overlay['business_id'] : null); } catch (\Throwable) {}
        $dirId = (string) ($overlay['direction_id'] ?? '');
        $useA1 = is_array($kit) && empty($kit['is_neutral'])
            && ($dirId === 'D1' || ($dirId === '' && preg_match('/\b(editorial|luxury)\b/i', (string) ($kit['visual_style'] ?? ''))));
        $brief['layout'] = $useA1 ? 'A1' : 'zone';
        $html  = $useA1
            ? $this->buildA1Html($bgData, $headline, $copy, $brief, $kit, (string) ($overlay['eyebrow'] ?? ''), $w, $h)
            : $this->buildHtml($bgData, $headline, $copy, $brief, $w, $h);

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

        Log::info('[ImageOverlayRenderer] RENDER-BRIEF-1', ['ws' => $wsId, 'zone' => $brief['zone'], 'asked' => $brief['asked_zone'], 'font' => $brief['font_head'], 'tone' => $brief['tone'], 'head_color' => $brief['head_color'], 'layout' => $brief['layout'] ?? 'zone']);
        return ['success' => true, 'url' => Storage::disk('public')->url($outPath), 'storage_path' => $outPath, 'zone' => $brief['zone']];
    }

    /**
     * RFC-0025 P1: the same page render() draws - brief from the words, the brand and the pixels of $frameBytes, then the
     * zone or A1 layout - returned as HTML for the video brand layer (BrandMotionRenderer) instead of a screenshot.
     * @return array{html:string, brief:array, layout:string, row:int, col:int}
     */
    public function layerHtml(array $overlay, string $frameBytes, int $w, int $h, int $wsId): array
    {
        $headline = trim((string) ($overlay['headline'] ?? ''));
        $copy     = array_values(array_filter(array_map('trim', (array) ($overlay['supporting_copy'] ?? []))));
        $bgData   = 'data:image/png;base64,' . base64_encode($frameBytes);
        $brief    = $this->brief($overlay, $frameBytes, $w, $h);
        $kit = null;
        try { $kit = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($wsId, isset($overlay['business_id']) ? (int) $overlay['business_id'] : null); } catch (\Throwable) {}
        $dirId = (string) ($overlay['direction_id'] ?? '');
        $useA1 = is_array($kit) && empty($kit['is_neutral'])
            && ($dirId === 'D1' || ($dirId === '' && preg_match('/\b(editorial|luxury)\b/i', (string) ($kit['visual_style'] ?? ''))));
        $brief['layout'] = $useA1 ? 'A1' : 'zone';
        $html = $useA1
            ? $this->buildA1Html($bgData, $headline, $copy, $brief, $kit, (string) ($overlay['eyebrow'] ?? ''), $w, $h)
            : $this->buildHtml($bgData, $headline, $copy, $brief, $w, $h);
        [$col, $row] = self::ZONES[$brief['zone']] ?? [0, 0];
        return ['html' => $html, 'brief' => $brief, 'layout' => $brief['layout'], 'row' => $row, 'col' => $col];
    }
    /* ─────────────────────────── the brief, read ─────────────────────────── */

    /**
     * Everything buildHtml needs, derived from the blueprint's words, the brand and the pixels.
     * @return array{zone:string, asked_zone:string, font_head:string, font_body:string, head_color:string, body_color:string, accent:string, tone:string, italic_word:?string, rule:bool, caps_body:bool, weight:int, scrim:float, serif:bool}
     */
    private function brief(array $overlay, string $bytes, int $w, int $h): array
    {
        $style     = (string) ($overlay['style'] ?? '');
        $placement = (string) ($overlay['placement'] ?? '');
        $palette   = array_values(array_filter(array_map('strval', (array) ($overlay['color_palette'] ?? []))));
        $fonts     = (array) ($overlay['fonts'] ?? []);
        $text      = $style . ' ' . $placement;

        // 1. the zone the brief asks for, then the quietest zone near it on the real background
        $asked = $this->zoneFromWords($placement) ?? $this->zoneFromWords($style) ?? 'upper-left';
        $keepClear = $this->clearSideFromWords($text);   // "right third stays clear" -> never put type on the right
        $stats = $this->zoneStats($bytes);
        $zone  = $this->pickZone($asked, $stats, $keepClear);

        // 2. type: named fonts first, then the brand's, then what the style words imply
        $named = $this->fontsFromWords($style);
        $serifWords = (bool) preg_match('/\b(serif|editorial|luxury|elegant|refined|classic|magazine)\b/i', $style) && ! preg_match('/\bsans[- ]serif\b/i', $style);
        $fontHead = $named[0] ?? (string) ($fonts['heading'] ?? '') ?: ($serifWords ? 'Playfair Display' : ((bool) preg_match('/\b(bold|impact|punchy|loud|oversized|block)\b/i', $style) ? 'Anton' : ((bool) preg_match('/\b(handmade|cosy|cozy|organic|friendly serif|warm)\b/i', $style) ? 'Fraunces' : 'Manrope')));
        $fontBody = $named[1] ?? (string) ($fonts['body'] ?? '') ?: ($fontHead === 'Playfair Display' ? 'DM Sans' : ($fontHead === 'Anton' ? 'Archivo' : 'Manrope'));
        $weight = (bool) preg_match('/\b(light|thin|delicate|graceful)\b/i', $style) ? 400 : ((bool) preg_match('/\b(bold|heavy|black|impact|oversized)\b/i', $style) ? 800 : ($serifWords || $fontHead === 'Playfair Display' ? 600 : 700));
        $italicWord = null;
        if (preg_match('/\b(?:payoff|emphasi[sz]ed|italic|accented?)\s+word\s+[\'"\x{2018}\x{2019}\x{201C}\x{201D}]?([A-Za-z][\w-]*)/iu', $style, $m)) $italicWord = $m[1];
        elseif (preg_match('/[\'"\x{2018}\x{2019}\x{201C}\x{201D}]([A-Za-z][\w-]*)[\'"\x{2018}\x{2019}\x{201C}\x{201D}]\s+(?:optionally\s+)?(?:the\s+only\s+)?(?:metallic|accent|italic|highlight)/iu', $style, $m)) $italicWord = $m[1];

        // 3. colour: hex codes in the style (first = headline, second = body), else the brand palette,
        //    tone from the pixels behind the chosen zone
        $hexes = $this->hexesFromWords($style);
        $dark  = $stats[$zone]['lum'] < 0.52;   // is the zone dark?
        $tone  = (bool) preg_match('/\b(dark|charcoal|black)\s+(text|type|lettering|ink)\b/i', $style) ? 'dark' : ((bool) preg_match('/\b(white|cream|light)\s+(text|type|lettering|ink)\b/i', $style) ? 'light' : ($dark ? 'light' : 'dark'));
        $accent = $this->accentFrom($palette, $hexes);
        $headColor = $hexes[0] ?? ($tone === 'light' ? ($this->isLight($accent) ? $accent : '#FFFFFF') : ($this->isLight($accent) ? '#111111' : $accent));
        $bodyColor = $hexes[1] ?? ($tone === 'light' ? '#F4F1EA' : '#1B1B1B');
        // a headline colour that would vanish on the zone is corrected, never trusted blindly
        if ($this->contrast($headColor, $stats[$zone]['lum']) < 2.2) $headColor = $tone === 'light' ? '#FFFFFF' : '#111111';
        if ($this->contrast($bodyColor, $stats[$zone]['lum']) < 2.2) $bodyColor = $tone === 'light' ? '#F4F1EA' : '#1B1B1B';

        // RENDER-SPAN-1: explicit brief fields win over the words
        if (! empty($overlay['accent_word'])) $italicWord = (string) $overlay['accent_word'];
        $span = isset($overlay['headline_span']) ? max(0.3, min(0.95, (float) $overlay['headline_span'])) : null;
        $ruleWanted = array_key_exists('rule', $overlay) ? (bool) $overlay['rule'] : null;
        return [
            'span' => $span, 'rule_explicit' => $ruleWanted,
            'zone' => $zone, 'asked_zone' => $asked, 'font_head' => $fontHead, 'font_body' => $fontBody,
            'head_color' => $headColor, 'body_color' => $bodyColor, 'accent' => $accent, 'tone' => $tone,
            'italic_word' => $italicWord, 'weight' => $weight, 'serif' => $serifWords || in_array($fontHead, ['Playfair Display', 'Fraunces', 'Cormorant Garamond', 'Libre Baskerville', 'Lora'], true),
            'rule' => $ruleWanted !== null ? $ruleWanted : ((bool) preg_match('/\b(rule|hairline|underline|divider|line beneath)\b/i', $style) || $serifWords),
            'caps_body' => (bool) preg_match('/\b(caps|capitals|uppercase|tracking|small[- ]caps)\b/i', $style),
            'scrim' => $this->scrimStrength($stats[$zone], $tone),
        ];
    }

    private function zoneFromWords(string $s): ?string
    {
        $s = strtolower($s);
        if ($s === '') return null;
        $row = preg_match('/\b(top|upper|above)\b/', $s) ? 0 : (preg_match('/\b(bottom|lower|beneath|foot|band)\b/', $s) ? 2 : (preg_match('/\b(middle|centre|center|vertically)\b/', $s) ? 1 : null));
        $col = preg_match('/\bleft\b/', $s) ? 0 : (preg_match('/\bright\b/', $s) ? 2 : (preg_match('/\b(centre|center|centred|centered|middle)\b/', $s) ? 1 : null));
        if ($row === null && $col === null) return null;
        $row ??= 0; $col ??= 0;
        foreach (self::ZONES as $id => [$c, $r]) if ($c === $col && $r === $row) return $id;
        return null;
    }

    /** "right third stays clear", "keep the left free" -> the column never used for type. */
    private function clearSideFromWords(string $s): ?int
    {
        if (preg_match('/\b(right)[\w\s-]{0,30}\b(clear|free|unobstructed|empty|untouched)\b/i', $s) || preg_match('/\b(clear|free|keep)[\w\s-]{0,20}\bright\b/i', $s)) return 2;
        if (preg_match('/\b(left)[\w\s-]{0,30}\b(clear|free|unobstructed|empty|untouched)\b/i', $s) || preg_match('/\b(clear|free|keep)[\w\s-]{0,20}\bleft\b/i', $s)) return 0;
        return null;
    }

    /** Per-zone mean luminance (0..1) and busyness (std dev of luminance, 0..1) from a downscaled copy (GD). */
    private function zoneStats(string $bytes): array
    {
        $stats = [];
        foreach (self::ZONES as $id => $_) $stats[$id] = ['lum' => 0.35, 'busy' => 0.2];
        if (! function_exists('imagecreatefromstring')) return $stats;
        $im = @imagecreatefromstring($bytes);
        if (! $im) return $stats;
        $sw = 96; $sh = 96;
        $small = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($small, $im, 0, 0, 0, 0, $sw, $sh, imagesx($im), imagesy($im));
        $grid = [];
        for ($y = 0; $y < $sh; $y++) for ($x = 0; $x < $sw; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $grid[$y][$x] = (0.2126 * (($rgb >> 16) & 255) + 0.7152 * (($rgb >> 8) & 255) + 0.0722 * ($rgb & 255)) / 255;
        }
        imagedestroy($small); imagedestroy($im);
        // the FOOTPRINT the text box will occupy in each zone (not the whole third): where the words actually land
        $xr = [0 => [0.05, 0.58], 1 => [0.16, 0.84], 2 => [0.42, 0.95]];
        $yr = [0 => [0.05, 0.42], 1 => [0.30, 0.70], 2 => [0.58, 0.95]];
        foreach (self::ZONES as $id => [$c, $r]) {
            $vals = []; $edges = 0; $cells = 0;
            for ($y = (int) ($yr[$r][0] * $sh); $y < (int) ($yr[$r][1] * $sh); $y++) for ($x = (int) ($xr[$c][0] * $sw); $x < (int) ($xr[$c][1] * $sw); $x++) {
                $vals[] = $grid[$y][$x];
                // local contrast: a subject's edges (a shoe, a face, a plate) show as neighbour jumps
                if ($x + 1 < $sw && $y + 1 < $sh) { $edges += abs($grid[$y][$x] - $grid[$y][$x + 1]) + abs($grid[$y][$x] - $grid[$y + 1][$x]); $cells++; }
            }
            $n = count($vals); $mean = array_sum($vals) / max(1, $n);
            $var = 0; foreach ($vals as $v) $var += ($v - $mean) ** 2; $sd = sqrt($var / max(1, $n));
            $stats[$id] = ['lum' => $mean, 'busy' => $sd * 0.6 + ($cells ? ($edges / $cells) * 2.4 : 0)];
        }
        return $stats;
    }

    /** The asked zone if it is calm enough, else the calmest of its neighbours on the same row, then anywhere. */
    private function pickZone(string $asked, array $stats, ?int $keepClear): string
    {
        $ok = fn (string $z) => $keepClear === null || self::ZONES[$z][0] !== $keepClear;
        $busy = fn (string $z) => $stats[$z]['busy'];
        if ($ok($asked) && $busy($asked) <= 0.32) return $asked;   // calibrated: a quiet corner measures ~0.28, a subject ~0.45+
        [$ac, $ar] = self::ZONES[$asked];
        $candidates = [];
        foreach (self::ZONES as $id => [$c, $r]) {
            if (! $ok($id)) continue;
            $dist = abs($c - $ac) + abs($r - $ar) * 1.5;   // stay on the asked row when possible
            $candidates[$id] = $busy($id) + $dist * 0.03;
        }
        if (! $candidates) return $asked;
        asort($candidates);
        return (string) array_key_first($candidates);
    }

    /** Font family names written in the brief ("Playfair Display (or equivalent refined serif) headline … 'Chef Red Raymundo' in DM Sans"). */
    private function fontsFromWords(string $s): array
    {
        $known = ['Playfair Display', 'DM Sans', 'Manrope', 'Inter', 'Syne', 'Anton', 'Archivo Black', 'Archivo', 'Bebas Neue', 'Oswald', 'Fraunces', 'Cormorant Garamond', 'Libre Baskerville', 'Lora', 'Montserrat', 'Poppins', 'Raleway', 'Lato', 'Roboto', 'Open Sans', 'Nunito', 'Space Grotesk', 'Plus Jakarta Sans', 'Bricolage Grotesque', 'Great Vibes', 'Dancing Script', 'Pacifico', 'Josefin Sans', 'Cinzel', 'Merriweather', 'Source Serif 4', 'EB Garamond', 'Work Sans', 'Rubik', 'Barlow', 'Barlow Condensed'];
        $found = [];
        foreach ($known as $f) { $pos = stripos($s, $f); if ($pos !== false) $found[$pos] = $f; }
        ksort($found);
        return array_values($found);
    }

    private function hexesFromWords(string $s): array
    {
        preg_match_all('/#[0-9a-fA-F]{6}\b/', $s, $m);
        return array_values(array_unique($m[0] ?? []));
    }

    /** The palette colour that is neither near-black nor near-white (the brand's accent), else a named hex, else gold. */
    private function accentFrom(array $palette, array $hexes): string
    {
        foreach (array_merge($hexes, $palette) as $c) {
            if (! preg_match('/#[0-9a-fA-F]{6}/', (string) $c, $m)) continue;
            $l = $this->luminance($m[0]);
            if ($l > 0.08 && $l < 0.85) return $m[0];
        }
        return '#C9943A';
    }

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return 0.5;
        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    }

    private function isLight(string $hex): bool { return $this->luminance($hex) >= 0.55; }

    /** A rough contrast ratio between a text colour and the zone's mean luminance (both 0..1, +0.05). */
    private function contrast(string $hex, float $zoneLum): float
    {
        $a = $this->luminance($hex) + 0.05; $b = $zoneLum + 0.05;
        return $a > $b ? $a / $b : $b / $a;
    }

    /** How strong the LOCAL scrim behind the text should be: enough for the tone, never a full-frame wash. */
    private function scrimStrength(array $zoneStat, string $tone): float
    {
        $lum = $zoneStat['lum']; $busy = $zoneStat['busy'];
        if ($tone === 'light') return min(0.72, max(0.28, ($lum - 0.15) * 1.2 + $busy * 1.4));
        return min(0.60, max(0.20, (0.85 - $lum) * 1.0 + $busy * 1.2));
    }

    /* ─────────────────────────── the page ─────────────────────────── */

    private function buildHtml(string $bgData, string $headline, array $copy, array $b, int $w, int $h): string
    {
        [$col, $row] = self::ZONES[$b['zone']];
        $pad    = (int) round($w * 0.062);
        $boxW   = (int) round($w * ($col === 1 ? 0.70 : 0.56));
        $hlLen  = mb_strlen($headline);
        $hlSize = (int) round($w * ($b['serif'] ? 0.070 : 0.076));
        if ($hlLen > 18) $hlSize = (int) round($hlSize * 0.86);
        if ($hlLen > 28) $hlSize = (int) round($hlSize * 0.84);
        if ($hlLen > 40) $hlSize = (int) round($hlSize * 0.84);
        $hlSize   = max(28, $hlSize);
        // RENDER-SPAN-1: a stated width share sizes the headline so its longest line spans that share of the canvas
        if (! empty($b['span'])) {
            $words = preg_split('/\s+/', trim($headline)) ?: [$headline]; $lines = [];
            if (count($words) <= 2) $lines = [implode(' ', $words)]; else { $half = (int) ceil(count($words) / 2); $lines = [implode(' ', array_slice($words, 0, $half)), implode(' ', array_slice($words, $half))]; }
            $longest = max(array_map('mb_strlen', $lines)); $em = $b['serif'] ? 0.5 : 0.56;
            $hlSize = (int) round(max(28, min($w * 0.2, ($w * $b['span']) / max(1, $longest * $em))));
            $boxW = (int) round($w * min(0.92, $b['span'] + 0.06));
        }
        $bodySize = max(16, (int) round($w * ($b['caps_body'] ? 0.017 : 0.024)));
        $align    = $col === 1 ? 'center' : 'left';

        // position of the text box
        $pos = ($col === 0 ? "left:{$pad}px;" : ($col === 2 ? "right:{$pad}px;" : "left:50%;transform:translateX(-50%);"))
             . ($row === 0 ? "top:{$pad}px;" : ($row === 2 ? "bottom:{$pad}px;" : "top:50%;" . ($col === 1 ? "transform:translate(-50%,-50%);" : "transform:translateY(-50%);")));

        // the LOCAL scrim: a soft ellipse behind the text zone in the ground colour the tone needs
        $scrimRgb = $b['tone'] === 'light' ? '10,8,6' : '250,247,240';
        $s = number_format($b['scrim'], 2, '.', '');
        $s2 = number_format($b['scrim'] * 0.55, 2, '.', '');
        $cx = $col === 0 ? '28%' : ($col === 2 ? '72%' : '50%');
        $cy = $row === 0 ? '26%' : ($row === 2 ? '76%' : '50%');
        $scrim = "background:radial-gradient(ellipse 62% 52% at {$cx} {$cy}, rgba({$scrimRgb},{$s}) 0%, rgba({$scrimRgb},{$s2}) 42%, rgba({$scrimRgb},0) 72%);";

        // headline with the emphasised word in italic accent when the brief names one
        $hlHtml = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
        if ($b['italic_word'] && $b['serif']) {
            $hlHtml = preg_replace('/\b(' . preg_quote(htmlspecialchars($b['italic_word'], ENT_QUOTES, 'UTF-8'), '/') . ')\b/iu', '<em>$1</em>', $hlHtml, 1);
        }
        $copyHtml = '';
        foreach ($copy as $line) $copyHtml .= '<p class="ln">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
        $ruleHtml = $b['rule'] ? '<div class="rule"></div>' : '';

        $fam = fn (string $f) => "'" . str_replace("'", '', $f) . "'";
        $gf  = fn (string $f) => str_replace(' ', '+', $f);
        $shadow = $b['tone'] === 'light' ? '0 2px 14px rgba(0,0,0,.45)' : '0 1px 10px rgba(255,255,255,.35)';
        $fontLink = 'https://fonts.googleapis.com/css2?family=' . $gf($b['font_head']) . ':ital,wght@0,400;0,600;0,700;0,800;1,500;1,600&family=' . $gf($b['font_body']) . ':wght@400;500;600&display=swap';
        $emColor = $this->isLight($b['accent']) === ($b['tone'] === 'light') ? $b['accent'] : $b['head_color'];
        $bodyTransform = $b['caps_body'] ? 'text-transform:uppercase;letter-spacing:.16em;' : 'letter-spacing:.01em;';
        $ruleAlign = $col === 1 ? 'margin:22px auto 16px;' : 'margin:22px 0 16px;';

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="{$fontLink}" rel="stylesheet">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:{$w}px; height:{$h}px; }
  .canvas { position:relative; width:{$w}px; height:{$h}px; overflow:hidden; background:#111; }
  .bg { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
  .scrim { position:absolute; inset:0; {$scrim} }
  .box { position:absolute; {$pos} width:{$boxW}px; max-width:calc(100% - {$pad}px * 2); text-align:{$align}; }
  .headline { font-family:{$fam($b['font_head'])},'Liberation Serif','DejaVu Serif',serif; font-weight:{$b['weight']};
    font-size:{$hlSize}px; line-height:1.04; color:{$b['head_color']}; letter-spacing:-0.012em; text-shadow:{$shadow}; text-wrap:balance; }
  .headline em { font-style:italic; font-weight:500; color:{$emColor}; }
  .rule { width:84px; height:2px; background:{$b['accent']}; border-radius:1px; {$ruleAlign} box-shadow:0 1px 6px rgba(0,0,0,.35); }
  .copy { margin-top:18px; font-family:{$fam($b['font_body'])},'Liberation Sans','DejaVu Sans',sans-serif; font-weight:500;
    font-size:{$bodySize}px; line-height:1.45; color:{$b['body_color']}; {$bodyTransform} text-shadow:{$shadow}; }
  .copy .ln { margin-bottom:6px; }
</style></head>
<body><div class="canvas">
  <img class="bg" src="{$bgData}" alt="">
  <div class="scrim"></div>
  <div class="box">
    <div class="headline">{$hlHtml}</div>
    {$ruleHtml}
    <div class="copy">{$copyHtml}</div>
  </div>
</div></body></html>
HTML;
    }

    /* ─────────────────────────── LAYOUT-A1: statement over scene ─────────────────────────── */

    /**
     * RFC-0017 5c, A1. Measured from the Owner's designer samples: one outer margin (5.5% of the width) shared by text
     * and logo; text column on the fade side, 55-60% of the width; eyebrow in letter-spaced caps, headline, short rule,
     * one or two subhead lines, nothing else; one accent on under 10% of the area (payoff word, rule, eyebrow,
     * descriptor); legibility from a directional fade, never from text shadows; logo lockup bottom-left.
     */
    private function buildA1Html(string $bgData, string $headline, array $copy, array $b, array $kit, string $eyebrow, int $w, int $h): string
    {
        [$col, $row] = self::ZONES[$b['zone']];
        $side = $col === 2 ? 'right' : 'left';
        $m    = (int) round($w * 0.055);

        // palette roles from the kit: ink = darkest, paper = lightest, accent = the most saturated mid tone
        $cols = array_values(array_unique(array_filter(array_merge(
            [(string) ($kit['primary_color'] ?? ''), (string) ($kit['secondary_color'] ?? ''), (string) ($kit['accent_color'] ?? '')],
            array_map('strval', (array) ($kit['colors_json'] ?? []))
        ), fn ($c) => (bool) preg_match('/^#[0-9a-f]{6}$/i', $c))));
        if (! $cols) $cols = ['#0A0806', '#C9943A', '#F2EBDF'];
        usort($cols, fn ($x, $y) => $this->luminance($x) <=> $this->luminance($y));
        $ink   = $this->luminance($cols[0]) < 0.12 ? $cols[0] : '#0B0A09';
        $paper = $this->luminance(end($cols)) > 0.75 ? end($cols) : '#F4F1EA';
        $accent = $b['accent'];
        $best = -1.0;
        foreach ($cols as $c) {
            [$r, $g, $bl] = sscanf($c, '#%02x%02x%02x');
            $sat = (max($r, $g, $bl) - min($r, $g, $bl)) / 255; $lum = $this->luminance($c);
            if ($lum > 0.18 && $lum < 0.8 && $sat > $best) { $best = $sat; $accent = $c; }
        }
        $rgb = fn (string $hex) => implode(',', sscanf($hex, '#%02x%02x%02x'));

        // type: the brand's heading and body fonts
        $fh = (string) ($kit['heading_font'] ?? '') ?: $b['font_head'];
        $fb = (string) ($kit['body_font'] ?? '') ?: $b['font_body'];
        $serif = in_array($fh, ['Playfair Display', 'Cormorant Garamond', 'Libre Baskerville', 'Lora', 'Fraunces', 'Marcellus', 'DM Serif Display', 'Bodoni Moda', 'EB Garamond'], true)
            || (bool) preg_match('/serif/i', (string) ($kit['visual_style'] ?? ''));

        // headline: the payoff word (named in the brief, else the last word) in the accent
        $words = preg_split('/\s+/u', trim($headline)) ?: [];
        $payoff = $b['italic_word'] ?: (count($words) > 1 ? end($words) : '');
        $hl = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
        if ($payoff !== '') {
            $pq = preg_quote(htmlspecialchars(rtrim($payoff, '.,!?;:'), ENT_QUOTES, 'UTF-8'), '/');
            $hl = preg_replace('/(' . $pq . ')(?!.*' . $pq . ')/u', '<span class="pay">$1</span>', $hl, 1);
        }
        $len = mb_strlen($headline);
        $hs  = $w * ($serif ? 0.088 : 0.094);
        if ($len > 20) $hs *= 0.86; if ($len > 30) $hs *= 0.86; if ($len > 44) $hs *= 0.84;
        $hs  = (int) round(max(30, $hs));

        // the lockup carries the brand name, so a subhead that only repeats it is dropped
        $brandName = trim((string) ($kit['brand_name'] ?? ''));
        $copy = array_values(array_filter($copy, fn ($l) => mb_strtolower(trim($l)) !== mb_strtolower($brandName)));
        $copy = array_slice($copy, 0, 2);
        $sub = ''; foreach ($copy as $l) $sub .= '<p>' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</p>';

        $eb = trim($eyebrow);
        $ebHtml = $eb !== '' ? '<div class="eb">' . htmlspecialchars($eb, ENT_QUOTES, 'UTF-8') . '</div>' : '';

        // logo lockup: the logo file when there is one, else a wordmark with the descriptor under it
        $logo = (string) (str_starts_with($__l = (string) ($kit['logo_url'] ?? ''), '/') && ! str_starts_with($__l, '//') ? rtrim((string) config('app.url'), '/') . $__l : $__l);   // VIDEO-RENDER-1: a site path never loads in a page rendered from a local file
        $desc = trim((string) ($kit['tagline'] ?? '')) ?: trim((string) ($kit['industry'] ?? ''));
        $lockup = $logo !== ''
            ? '<img class="logo" src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="">'
            : '<div class="wm">' . htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') . '</div>'
              . ($desc !== '' ? '<div class="wd">' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</div>' : '');

        // the fade: dark from the text side, stronger over a bright or busy scene; a low band for the lockup
        $st = $b['scrim'];
        $k  = min(1.0, max(0.78, 0.70 + $st * 0.5));
        $a0 = number_format(0.92 * $k, 2, '.', ''); $a1 = number_format(0.74 * $k, 2, '.', '');
        $a2 = number_format(0.30 * $k, 2, '.', '');
        $dir = $side === 'left' ? 'to right' : 'to left';
        $fade = "linear-gradient({$dir}, rgba({$rgb($ink)},{$a0}) 0%, rgba({$rgb($ink)},{$a1}) 28%, rgba({$rgb($ink)},{$a2}) 52%, rgba({$rgb($ink)},0) 70%),"
              . "linear-gradient(to top, rgba({$rgb($ink)},0.62) 0%, rgba({$rgb($ink)},0) 26%)";

        $colW = (int) round($w * 0.58);
        $vpos = $row === 0 ? 'top:' . (int) round($m * 1.35) . 'px;' : ($row === 2 ? 'bottom:' . (int) round($h * 0.20) . 'px;' : 'top:50%;transform:translateY(-58%);');
        $hpos = $side === 'left' ? "left:{$m}px;" : "right:{$m}px;";
        $talign = $side === 'left' ? 'left' : 'right';
        $lpos = $side === 'left' ? "left:{$m}px;" : "right:{$m}px;";

        $fam = fn (string $f) => "'" . str_replace("'", '', $f) . "'";
        $gf  = fn (string $f) => str_replace(' ', '+', $f);
        $fontLink = 'https://fonts.googleapis.com/css2?family=' . $gf($fh) . ':ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600&family=' . $gf($fb) . ':wght@400;500;600;700&display=swap';
        $hw   = $serif ? 500 : 700;
        $case = $serif ? 'none' : 'uppercase';
        $track = $serif ? '-0.015em' : '0.005em';
        $payStyle = $serif ? 'font-style:italic;font-weight:500;' : '';
        $ebSize = max(12, (int) round($w * 0.0165)); $subSize = max(15, (int) round($w * 0.026));
        $wmSize = max(13, (int) round($w * 0.0215)); $wdSize = max(10, (int) round($w * 0.0118));
        $ruleW = (int) round($w * 0.07); $ruleH = max(2, (int) round($w / 560));
        $gap = (int) round($w * 0.024);
        $logoH = (int) round($w * 0.052);

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="{$fontLink}" rel="stylesheet">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:{$w}px; height:{$h}px; }
  .canvas { position:relative; width:{$w}px; height:{$h}px; overflow:hidden; background:{$ink}; }
  .bg { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
  .fade { position:absolute; inset:0; background:{$fade}; }
  .col { position:absolute; {$hpos} {$vpos} width:{$colW}px; text-align:{$talign}; }
  .eb { font-family:{$fam($fb)},sans-serif; font-weight:600; font-size:{$ebSize}px; letter-spacing:.28em; text-transform:uppercase; color:{$accent}; margin-bottom:{$gap}px; }
  .hl { font-family:{$fam($fh)},'DejaVu Serif',serif; font-weight:{$hw}; font-size:{$hs}px; line-height:1.02; letter-spacing:{$track}; text-transform:{$case}; color:{$paper}; text-wrap:balance; }
  .hl .pay { color:{$accent}; {$payStyle} }
  .rule { width:{$ruleW}px; height:{$ruleH}px; background:{$accent}; margin:{$gap}px 0 0; display:inline-block; }
  .sub { margin-top:{$gap}px; font-family:{$fam($fb)},sans-serif; font-weight:400; font-size:{$subSize}px; line-height:1.4; color:rgba({$rgb($paper)},.86); }
  .sub p + p { margin-top:4px; }
  .lock { position:absolute; {$lpos} bottom:{$m}px; text-align:{$talign}; }
  .wm { font-family:{$fam($fh)},serif; font-weight:500; font-size:{$wmSize}px; letter-spacing:.2em; text-transform:uppercase; color:{$paper}; }
  .wd { margin-top:6px; font-family:{$fam($fb)},sans-serif; font-weight:600; font-size:{$wdSize}px; letter-spacing:.34em; text-transform:uppercase; color:{$accent}; }
  .logo { height:{$logoH}px; width:auto; display:block; }
</style></head>
<body><div class="canvas">
  <img class="bg" src="{$bgData}" alt="">
  <div class="fade"></div>
  <div class="col">
    {$ebHtml}
    <div class="hl">{$hl}</div>
    <div class="rule"></div>
    <div class="sub">{$sub}</div>
  </div>
  <div class="lock">{$lockup}</div>
</div></body></html>
HTML;
    }
}
