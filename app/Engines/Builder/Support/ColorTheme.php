<?php

namespace App\Engines\Builder\Support;

/**
 * COLOUR THEMES (2026-09-05) — Boss decision: Arthur offers curated colour themes instead of a bare colour
 * picker, so every site ships a palette with real contrast. Two jobs:
 *
 *  1. propose()   — the 3 best themes for a style ("bubbly and colorful") and an industry (pet_services), in the
 *                   shape the Arthur chat already renders as palette cards (id, label, primary, secondary, accent,
 *                   bg, text).
 *  2. harmonise() — whatever colours reach the builder (theme, logo palette, typed hex, named colour) are made
 *                   readable before they are painted: the primary carries white text at WCAG AA (4.5:1), the
 *                   accent (buttons) at least 3:1, and neon-saturated picks (#FF00FF) are tamed while keeping their
 *                   hue. The customer's colour stays their colour — only its lightness/saturation moves, and only
 *                   as far as it must.
 *
 * Every theme below is itself passed through harmonise() at build time, so the library can never drift out of
 * contrast even if a value is edited by hand.
 */
final class ColorTheme
{
    public const PRIMARY_MIN_CONTRAST = 4.5; // white text on primary surfaces
    public const ACCENT_MIN_CONTRAST  = 3.0; // white text on buttons / accent surfaces (large, bold UI text)

    /** id => [label, moods[], industries[], primary, secondary, accent, bg, text] */
    private const THEMES = [
        'coral_reef'     => ['Coral Reef',     ['playful','modern'],           ['pet_services','childcare','cafe','travel_agency','resort'],                 '#C8502F', '#F4A261', '#2A9D8F', '#FFFDF9', '#1F2933'],
        'bubblegum_pop'  => ['Bubblegum Pop',  ['playful'],                    ['pet_services','childcare','retail_shop','ecommerce','beauty_salon'],        '#6D28D9', '#FF6FB5', '#DB2777', '#FFF7FB', '#2A1B3D'],
        'sunny_side'     => ['Sunny Side',     ['playful','classic'],          ['cafe','childcare','tutoring','online_courses','catering'],                  '#003049', '#FCBF49', '#C2410C', '#FFFBF2', '#1B2A38'],
        'mint_fresh'     => ['Mint Fresh',     ['playful','minimal'],          ['dental','medical_clinic','pet_services','childcare','gym'],                 '#0B6E4F', '#B7E4C7', '#0F8A60', '#F7FCF9', '#12332A'],
        'sunset_violet'  => ['Sunset Violet',  ['playful','modern'],           ['marketing_agency','event_venue','beauty_salon','online_courses'],           '#4C1D95', '#F472B6', '#7C3AED', '#FBF8FF', '#241536'],
        'lagoon'         => ['Lagoon',         ['modern','minimal'],           ['it_services','marketing_agency','consulting','travel_agency','dental'],     '#0F4C81', '#38BDF8', '#0369A1', '#F6FAFE', '#0F2233'],
        'graphite_lime'  => ['Graphite Lime',  ['modern'],                     ['gym','automotive','it_services','construction','news_channel'],             '#111827', '#A3E635', '#4D7C0F', '#FAFAF7', '#111827'],
        'arctic'         => ['Arctic',         ['minimal','modern'],           ['medical_clinic','dental','aesthetic_clinic','it_services','architecture'],  '#1E3A5F', '#CFE8FF', '#2563EB', '#FFFFFF', '#14253A'],
        'slate_copper'   => ['Slate Copper',   ['modern','classic'],           ['construction','automotive','barbershop','home_services','architecture'],    '#263238', '#B87333', '#9A4F2A', '#FAF8F6', '#1E272C'],
        'midnight_gold'  => ['Midnight Gold',  ['luxury','classic'],           ['consulting','real_estate_agency','hotel','event_venue','aesthetic_clinic'], '#1A2744', '#C9943A', '#A9782A', '#FDFBF7', '#1A2233'],
        'emerald_estate' => ['Emerald Estate', ['luxury','classic'],           ['real_estate_agency','hotel','resort','consulting','catering'],              '#0F3D3E', '#C6A664', '#2E7D6B', '#F8FBF9', '#10292A'],
        'royal_plum'     => ['Royal Plum',     ['luxury'],                     ['aesthetic_clinic','beauty_salon','event_venue','retail_shop','hotel'],      '#3D1E4F', '#C9A96E', '#7A3E8E', '#FCF9FD', '#2A1636'],
        'rose_quartz'    => ['Rose Quartz',    ['luxury','minimal'],           ['beauty_salon','aesthetic_clinic','event_venue','retail_shop'],              '#5B2333', '#E8B4B8', '#A64D63', '#FFF8F8', '#33161E'],
        'terracotta'     => ['Terracotta',     ['classic','modern'],           ['restaurant','interior_design','architecture','cafe','catering'],            '#7C2D12', '#E7C6A5', '#B45309', '#FFFAF5', '#2B1A12'],
        'sage_linen'     => ['Sage Linen',     ['minimal','luxury'],           ['beauty_salon','aesthetic_clinic','interior_design','short_term_rental'],    '#3F5B4F', '#D9CFC1', '#5E8266', '#FBFAF7', '#22302A'],
        'forest_moss'    => ['Forest Moss',    ['classic','minimal'],          ['construction','home_services','consulting','training_center','resort'],     '#1F3A2B', '#A7C4A0', '#3E7C4E', '#F8FAF7', '#18261E'],
        'espresso'       => ['Espresso',       ['classic'],                    ['cafe','barbershop','restaurant','catering','tutoring'],                     '#3E2723', '#D7B899', '#8D5B3A', '#FBF7F3', '#26191A'],
        'ruby_night'     => ['Ruby Night',     ['modern','luxury'],            ['restaurant','news_channel','retail_shop','ecommerce','automotive'],         '#7F1D1D', '#FCA5A5', '#B91C1C', '#FFF8F8', '#2B1414'],
    ];

    /** All themes, harmonised, in the chat's palette-card shape. */
    public static function all(): array
    {
        $out = [];
        foreach (self::THEMES as $id => [$label, $moods, $industries, $p, $s, $a, $bg, $text]) {
            $h = self::harmonise(['primary' => $p, 'secondary' => $s, 'accent' => $a]);
            $out[] = [
                'id' => $id, 'label' => $label, 'moods' => $moods, 'industries' => $industries,
                'primary' => $h['primary'], 'secondary' => $h['secondary'], 'accent' => $h['accent'], 'bg' => $bg, 'text' => $text,
                'source' => 'theme',
            ];
        }
        return $out;
    }

    public static function find(?string $id): ?array
    {
        if (!$id) return null;
        foreach (self::all() as $t) { if ($t['id'] === $id) return $t; }
        return null;
    }

    /**
     * The best $n themes for a design direction and an industry. Style words go through DesignStyle's mood map
     * ("bubbly and colorful" → playful); industry is a template slug. Always returns $n themes.
     */
    public static function propose(?string $style, ?string $industry, int $n = 3): array
    {
        $mood = DesignStyle::normaliseStyle($style);
        $ind  = strtolower(trim((string) $industry));
        $scored = [];
        foreach (self::all() as $i => $t) {
            $score = 0;
            if ($mood && in_array($mood, $t['moods'], true)) $score += 3 - array_search($mood, $t['moods'], true); // first mood = strongest
            if ($ind !== '' && in_array($ind, $t['industries'], true)) $score += 4 - min(3, (int) array_search($ind, $t['industries'], true));
            $scored[] = [$score, $i, $t];
        }
        usort($scored, fn($x, $y) => $y[0] <=> $x[0] ?: $x[1] <=> $y[1]);
        $out = [];
        foreach (array_slice($scored, 0, max(1, $n)) as [$score, $i, $t]) {
            unset($t['moods'], $t['industries']);
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Make a colour set readable without changing what colour it is.
     * @param array{primary?:?string,secondary?:?string,accent?:?string} $colors  normalised #RRGGBB or null
     * @return array same keys + 'adjusted' => [role => [from, to]] for logging
     */
    public static function harmonise(array $colors): array
    {
        $out = ['adjusted' => []];
        foreach (['primary', 'secondary', 'accent'] as $role) {
            $hex = self::norm($colors[$role] ?? null);
            if ($hex === null) { $out[$role] = null; continue; }
            $new = self::tame($hex);
            if ($role === 'primary')      $new = self::ensureContrastOnWhiteText($new, self::PRIMARY_MIN_CONTRAST);
            elseif ($role === 'accent')   $new = self::ensureContrastOnWhiteText($new, self::ACCENT_MIN_CONTRAST);
            if (strcasecmp($new, $hex) !== 0) $out['adjusted'][$role] = [$hex, $new];
            $out[$role] = $new;
        }
        return $out;
    }

    /** Neon (near-full saturation at mid lightness, e.g. #FF00FF) is pulled back to a rich, printable version of the same hue. */
    public static function tame(string $hex): string
    {
        [$h, $s, $l] = self::hexToHsl($hex);
        if ($s > 0.92 && $l > 0.30 && $l < 0.70) {
            $s = 0.78;
            $l = min($l, 0.50);
            return self::hslToHex($h, $s, $l);
        }
        return strtoupper($hex);
    }

    /** Lower lightness (hue and saturation kept) until white text reaches the wanted contrast. */
    public static function ensureContrastOnWhiteText(string $hex, float $min): string
    {
        $cur = strtoupper($hex);
        if (self::contrast('#FFFFFF', $cur) >= $min) return $cur;
        [$h, $s, $l] = self::hexToHsl($cur);
        for ($i = 0; $i < 60 && $l > 0.04; $i++) {
            $l -= 0.015;
            $cur = self::hslToHex($h, $s, $l);
            if (self::contrast('#FFFFFF', $cur) >= $min) break;
        }
        return $cur;
    }

    // ---- colour maths -------------------------------------------------------------------------------------

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a); $lb = self::luminance($b);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];
        return ($hi + 0.05) / ($lo + 0.05);
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $f = function (int $c): float { $c /= 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    private static function norm(?string $v): ?string
    {
        if (!$v) return null;
        $v = trim($v);
        if (preg_match('/^#?([0-9a-f]{3})$/i', $v, $m)) { $c = $m[1]; return strtoupper('#' . $c[0].$c[0].$c[1].$c[1].$c[2].$c[2]); }
        if (preg_match('/^#?([0-9a-f]{6})$/i', $v, $m)) return strtoupper('#' . $m[1]);
        return null;
    }

    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function hexToHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn($c) => $c / 255, self::hexToRgb($hex));
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2;
        if ($max === $min) return [0.0, 0.0, $l];
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max === $r)      $h = fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6);
        elseif ($max === $g)  $h = ($b - $r) / $d + 2;
        else                  $h = ($r - $g) / $d + 4;
        return [$h * 60, $s, $l];
    }

    public static function hslToHex(float $h, float $s, float $l): string
    {
        $h = fmod($h, 360) / 360; $s = max(0, min(1, $s)); $l = max(0, min(1, $l));
        if ($s == 0) { $v = (int) round($l * 255); return sprintf('#%02X%02X%02X', $v, $v, $v); }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s; $p = 2 * $l - $q;
        $f = function (float $t) use ($p, $q): float {
            if ($t < 0) $t += 1; if ($t > 1) $t -= 1;
            if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
            if ($t < 1/2) return $q;
            if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
            return $p;
        };
        return sprintf('#%02X%02X%02X', (int) round($f($h + 1/3) * 255), (int) round($f($h) * 255), (int) round($f($h - 1/3) * 255));
    }
}
