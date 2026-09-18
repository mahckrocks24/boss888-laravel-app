<?php

namespace App\Engines\Builder\Support;

/**
 * PALETTE ROLES (Owner, 2026-09-18): a colour palette is the WHOLE site, not the accent.
 *
 * A theme names five colours (primary, secondary, accent, bg, text). Every design needs many more — surfaces,
 * lines, muted text, the dark section, what to write on top of each brand colour, the accent as small text — and
 * until now each template hard-coded those, so a palette switch repainted the buttons and left the rest as the
 * template shipped. This class derives the complete set with contrast maths, once, and every consumer (the build
 * painter, the palette switch on an exported site, the injected :root block) reads from it:
 *
 *   bg surface surface2 line text muted dark on_dark
 *   primary primary_deep on_primary primary_text primary_on_dark
 *   secondary secondary_soft on_secondary secondary_text secondary_on_dark
 *   accent accent_deep accent_soft on_accent accent_text accent_on_dark
 *
 * Guarantees (AA = 4.6:1, a margin over 4.5 so a rounded reading never fails): text ≥ 7:1 on bg; muted ≥ AA on
 * surface2 (so on bg and surface); on_* ≥ AA on their surface; *_text (a brand colour as small text) ≥ AA on the
 * darkest light surface (cards, soft tints); *_on_dark (the brand lifted for the dark band) ≥ AA on dark; on_dark ≥ 7:1. Hue is never changed — only lightness
 * moves, and only as far as it must. A dark-scheme design (dark ground) gets the same roles inverted.
 */
final class PaletteRoles
{
    public const ROLES = ['bg', 'surface', 'surface2', 'line', 'text', 'muted', 'dark', 'on_dark',
        'primary', 'primary_deep', 'on_primary', 'primary_text', 'primary_on_dark',
        'secondary', 'secondary_soft', 'on_secondary', 'secondary_text', 'secondary_on_dark',
        'accent', 'accent_deep', 'accent_soft', 'on_accent', 'accent_text', 'accent_on_dark'];

    /** Every promise is kept with a little room over the WCAG line, so a rounded measurement never reads 4.49. */
    public const AA = 4.6;

    /** The 62 generated designs share one :root vocabulary; these are repainted by role. */
    public const GEN_B_NEUTRALS = ['--paper' => 'bg', '--tint' => 'surface', '--ink' => 'text', '--deep' => 'dark', '--line' => 'line'];
    /** Hard-coded neutral names a few industry templates declare outside their manifest; re-pointed when present. */
    public const COMMON_NEUTRALS = ['--muted' => 'muted', '--bg-soft' => 'surface', '--bg' => 'bg', '--text' => 'text', '--surface' => 'surface'];

    /**
     * @param array{primary?:?string,secondary?:?string,accent?:?string,bg?:?string,text?:?string} $theme
     * @param string $scheme 'light' | 'dark' — the design's ground
     * @return array<string,string> role => #RRGGBB
     */
    public static function derive(array $theme, string $scheme = 'light'): array
    {
        $h = ColorTheme::harmonise(['primary' => $theme['primary'] ?? null, 'secondary' => $theme['secondary'] ?? null, 'accent' => $theme['accent'] ?? null]);
        $primary   = $h['primary']   ?? '#1F2937';
        $secondary = $h['secondary'] ?? $primary;
        $accent    = $h['accent']    ?? $primary;
        $bgIn   = self::norm($theme['bg'] ?? null)   ?? '#FFFFFF';
        $textIn = self::norm($theme['text'] ?? null) ?? '#111827';
        $dark   = $scheme === 'dark';

        if ($dark) {
            // ground: a deep neutral leaning to the primary's hue; text: the theme's light paper
            $bg   = self::setLightness(self::mix('#0B0F19', $primary, 0.10), 0.07);
            $text = self::ensureContrast(self::lightness($bgIn) > 0.6 ? $bgIn : '#F5F5F5', $bg, 7.0);
        } else {
            $bg   = self::lightness($bgIn) > 0.85 ? $bgIn : '#FFFFFF';
            $text = self::ensureContrast(self::lightness($textIn) < 0.35 ? $textIn : '#111827', $bg, 7.0);
        }
        $surface  = self::mix($bg, $text, $dark ? 0.07 : 0.045);
        $surface2 = self::mix($bg, $text, $dark ? 0.12 : 0.09);
        $line     = self::mix($bg, $text, $dark ? 0.20 : 0.16);
        $muted    = self::ensureContrast(self::mix($text, $bg, 0.38), $surface2, self::AA);

        // the dark section: the primary's own depth when it can carry light text, else the deep neutral
        $primaryDeep = self::shade($primary, -0.12);
        $darkSurface = ($dark) ? self::setLightness(self::mix($bg, '#000000', 0.45), 0.04)
                                : (ColorTheme::contrast('#FFFFFF', $primaryDeep) >= 7.0 ? $primaryDeep : self::setLightness(self::mix($text, $primary, 0.25), 0.11));
        $onDark = self::ensureContrast($dark ? $text : $bg, $darkSurface, 7.0);
        // the darkest light surface a brand colour is read on as small text: cards and the soft brand tints
        $textGround = $dark ? [$bg, $surface2, self::mix($bg, $secondary, 0.22), self::mix($bg, $accent, 0.20)]
                            : [$bg, $surface2, self::mix($bg, $secondary, 0.16), self::mix($bg, $accent, 0.14)];

        // a brand colour that nothing reads on (a mid green: white 4.35:1, black 4.4:1) is moved — hue kept —
        // until white does, exactly as harmonise() already does for the primary
        $secondary = self::fitSurface($secondary, $text, $bg);
        $accent    = self::fitSurface($accent, $text, $bg);

        return [
            'bg' => $bg, 'surface' => $surface, 'surface2' => $surface2, 'line' => $line, 'text' => $text, 'muted' => $muted,
            'dark' => $darkSurface, 'on_dark' => $onDark,
            'primary' => $primary, 'primary_deep' => $primaryDeep,
            'on_primary' => self::onSurface($primary, $text, $bg),
            'primary_text' => self::asText($primary, $textGround, $dark),
            'primary_on_dark' => self::asText($primary, $dark ? $textGround : [$darkSurface, $text], true),
            'secondary' => $secondary, 'secondary_soft' => self::mix($bg, $secondary, $dark ? 0.22 : 0.16),
            'on_secondary' => self::onSurface($secondary, $text, $bg),
            'secondary_text' => self::asText($secondary, $textGround, $dark),
            'secondary_on_dark' => self::asText($secondary, $dark ? $textGround : [$darkSurface, $text], true),
            'accent' => $accent, 'accent_deep' => self::shade($accent, -0.12), 'accent_soft' => self::mix($bg, $accent, $dark ? 0.20 : 0.14),
            'on_accent' => self::onSurface($accent, $text, $bg),
            'accent_text' => self::asText($accent, $textGround, $dark),
            'accent_on_dark' => self::asText($accent, $dark ? $textGround : [$darkSurface, $text], true),
        ];
    }

    /** Roles for a set of render variables (primary/secondary/accent + palette_bg/palette_text or the named theme). */
    public static function forVariables(array $variables, array $manifest = []): array
    {
        $theme = [
            'primary' => $variables['primary_color'] ?? null, 'secondary' => $variables['secondary_color'] ?? null, 'accent' => $variables['accent_color'] ?? null,
            'bg' => $variables['palette_bg'] ?? null, 'text' => $variables['palette_text'] ?? null,
        ];
        if (($theme['bg'] === null || $theme['text'] === null) && ! empty($variables['palette'])) {
            $t = ColorTheme::find((string) $variables['palette']);
            if ($t) { $theme['bg'] = $theme['bg'] ?? $t['bg']; $theme['text'] = $theme['text'] ?? $t['text']; }
        }
        return self::derive($theme, (string) ($manifest['palette_scheme'] ?? 'light'));
    }

    /** Write (or refresh) the roles block in a page. Gen-B pages (the --paper vocabulary) also get their neutrals re-pointed. */
    public static function injectBlock(string $html, array $variables, array $manifest = [], ?array $roles = null): string
    {
        $roles = $roles ?? self::forVariables($variables, $manifest);
        $genB = empty($manifest['palette_roles']) && (bool) preg_match('/--paper\s*:/', $html);
        // a design's own hard-coded neutral (`--muted:#64748B`, never a manifest placeholder) follows the palette too
        $declared = [];
        foreach (self::COMMON_NEUTRALS as $var => $role) {
            if (! preg_match('/(?<![-\w])' . preg_quote($var, '/') . '\s*:\s*(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6})\b/', $html, $dm)) continue;
            // only when the design uses the name the way the role means it (a `--bg` that is a dark hero stays)
            $l = self::lightness($dm[1]);
            $fits = match ($role) { 'bg', 'surface' => $l > 0.8, 'text' => $l < 0.35, 'muted' => $l >= 0.25 && $l < 0.65, default => false };
            if ($fits) $declared[] = $var;
        }
        $block = self::cssBlock($roles, $genB, $declared);
        if (preg_match('/<style id="lug-palette-roles"[^>]*>.*?<\/style>/s', $html)) {
            return preg_replace('/<style id="lug-palette-roles"[^>]*>.*?<\/style>/s', $block, $html, 1) ?? $html;
        }
        return stripos($html, '</head>') !== false ? str_ireplace('</head>', $block . "\n</head>", $html) : $block . $html;
    }

    /**
     * For a palette switch on an EXPORTED site: the :root variables to rewrite, extended from the brand vars the
     * painter chose to every neutral the design declares (gen-B vocabulary, or the manifest's palette_roles).
     * @param array<string,string> $vars   --var => hex already chosen by the painter
     * @param array<string,string> $have   --var => hex present in the export's :root
     */
    public static function siteVarsForRoles(array $vars, array $manifest, array $roles, array $have): array
    {
        foreach (self::GEN_B_NEUTRALS as $css => $role) {
            if (isset($have[$css]) && isset($roles[$role])) $vars[$css] = $roles[$role];
        }
        foreach (($manifest['palette_roles'] ?? []) as $var => $role) {
            if (! is_string($role) || $role === 'keep' || ! isset($roles[$role])) continue;
            $css = '--' . str_replace('_', '-', (string) $var);
            if (! isset($have[$css])) continue;
            if (isset($vars[$css]) && in_array($role, ['primary', 'secondary', 'accent', 'primary_deep', 'accent_deep'], true)) continue;
            $vars[$css] = $roles[$role];
        }
        return $vars;
    }

    /** Normalise an exported site's pages (literals → roles, idempotent) and write the roles block into each. */
    public static function normaliseExport(int $websiteId, array $roles, array $manifest): array
    {
        $dir = storage_path("app/public/sites/{$websiteId}");
        $brand = [];
        foreach (($manifest['palette_roles'] ?? []) as $var => $role) {
            if (in_array($role, ['primary', 'secondary', 'accent'], true)) { $d = $manifest['variables'][$var]['default'] ?? null; if (is_string($d)) $brand[$role] = $d; }
        }
        $done = [];
        foreach (glob($dir . '/*.html') ?: [] as $file) {
            $html = (string) @file_get_contents($file);
            if ($html === '') continue;
            $n = PaletteNormalizer::normalizeHtml($html, $brand, PaletteNormalizer::varRolesFor($manifest));
            $out = self::injectBlock($n['html'], [], $manifest, $roles);
            if ($out !== $html) { @file_put_contents($file, $out); }
            $done[basename($file)] = $n['count'];
        }
        return $done;
    }

    /** The <style> block every rendered page carries: the roles, and the gen-B neutrals pointed at them. */
    public static function rgbTriple(string $hex): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        return hexdec(substr($h, 0, 2)) . ',' . hexdec(substr($h, 2, 2)) . ',' . hexdec(substr($h, 4, 2));
    }

    /** The roles as css custom properties (`--lu-on-accent` => hex; `--lu-on-dark-rgb` => "r,g,b"). */
    public static function cssVars(array $roles): array
    {
        $out = [];
        foreach (self::ROLES as $r) { if (isset($roles[$r])) $out['--lu-' . str_replace('_', '-', $r)] = $roles[$r]; }
        // translucent text (`rgba(255,255,255,.7)` on a dark band) keeps its alpha: the role as an r,g,b triple
        foreach (['on_dark', 'on_primary', 'on_secondary', 'on_accent', 'text'] as $r) { if (isset($roles[$r])) $out['--lu-' . str_replace('_', '-', $r) . '-rgb'] = self::rgbTriple($roles[$r]); }
        return $out;
    }

    public static function cssBlock(array $roles, bool $genB, array $declaredNeutrals = []): string
    {
        $decl = '';
        foreach (self::cssVars($roles) as $k => $v) $decl .= $k . ':' . $v . ';';
        $css = ':root{' . $decl . '}';
        $map = '';
        if ($genB) { foreach (self::GEN_B_NEUTRALS as $var => $role) { $map .= $var . ':var(--lu-' . str_replace('_', '-', $role) . ');'; } }
        foreach ($declaredNeutrals as $var) { $role = self::COMMON_NEUTRALS[$var] ?? null; if ($role) $map .= $var . ':var(--lu-' . str_replace('_', '-', $role) . ');'; }
        if ($map !== '') { $css .= ':root{' . $map . '}'; }
        return '<style id="lug-palette-roles" data-owner="arthur">' . $css . '</style>';
    }

    /**
     * Fill a template's OWN colour variables from the roles, as declared in its manifest ("palette_roles":
     * {"chalk":"bg","carbon":"text",...}). Brand roles are already painted by applyBrandColors; this covers the rest.
     * @return array<string,string> var => hex written
     */
    public static function paintManifestVars(array &$variables, array $manifest, array $roles): array
    {
        $map = $manifest['palette_roles'] ?? [];
        // the brand painter owns the variables the manifest's color_roles name (and the generic ones); a second brand
        // variable (a childcare sky next to its coral) is painted here, from the same roles
        $owned = array_merge(['primary_color', 'primary_deep', 'secondary_color', 'accent_color'], array_values(array_filter($manifest['color_roles'] ?? [], 'is_string')));
        $written = [];
        foreach ($map as $var => $role) {
            if (! is_string($role) || $role === 'keep' || ! isset($roles[$role])) continue;
            if (in_array($role, ['primary', 'secondary', 'accent', 'primary_deep', 'accent_deep'], true) && in_array((string) $var, $owned, true) && isset($variables[$var]) && self::norm($variables[$var])) continue;
            $variables[$var] = $roles[$role];
            $written[$var] = $roles[$role];
        }
        return $written;
    }

    /** Which colour should be written ON a brand surface: white when it reads, else the palette's text/bg. */
    public static function onSurface(string $surface, string $text, string $bg): string
    {
        $cands = ['#FFFFFF', $text, $bg, '#111111'];
        $best = '#FFFFFF'; $bestR = 0;
        foreach ($cands as $c) { $r = ColorTheme::contrast($c, $surface); if ($r >= self::AA) return strtoupper($c); if ($r > $bestR) { $bestR = $r; $best = $c; } }
        return strtoupper($best);
    }

    /** A surface at least one candidate can be written on at 4.5:1; otherwise the surface itself moves (darker for white). */
    public static function fitSurface(string $surface, string $text, string $bg): string
    {
        $cur = strtoupper($surface);
        foreach (['#FFFFFF', $text, $bg, '#111111'] as $c) { if (ColorTheme::contrast($c, $cur) >= self::AA) return $cur; }
        return ColorTheme::ensureContrastOnWhiteText($cur, self::AA);
    }

    /** A brand colour used as small text on the page ground: pushed darker (or lighter on a dark ground) until 4.5:1. */
    public static function asText(string $hex, string|array $bg, bool $darkGround): string
    {
        // one or several grounds (a deep-blue band and a near-black text used as a band): the colour moves until
        // it reads on every one of them — lightness sorts them poorly, luminance is what contrast uses
        $grounds = is_array($bg) ? $bg : [$bg];
        $cur = strtoupper($hex);
        $reads = function (string $c) use ($grounds): bool { foreach ($grounds as $g) { if (ColorTheme::contrast($c, $g) < self::AA) return false; } return true; };
        for ($i = 0; $i < 80 && ! $reads($cur); $i++) {
            $cur = self::shade($cur, $darkGround ? 0.02 : -0.02);
        }
        return $cur;
    }

    public static function lightest(array $hexes): string
    {
        usort($hexes, fn ($a, $b) => self::lightness($b) <=> self::lightness($a));
        return strtoupper($hexes[0]);
    }

    /** The lowest-luminance colour of a set. */
    public static function darkest(array $hexes): string
    {
        usort($hexes, fn ($a, $b) => self::lightness($a) <=> self::lightness($b));
        return strtoupper($hexes[0]);
    }

    /** Move $fg away from $bg (toward black or white, whichever it already leans to) until the ratio holds. */
    public static function ensureContrast(string $fg, string $bg, float $min): string
    {
        $cur = strtoupper($fg);
        if (ColorTheme::contrast($cur, $bg) >= $min) return $cur;
        $towardLight = self::lightness($bg) < 0.5;
        for ($i = 0; $i < 100 && ColorTheme::contrast($cur, $bg) < $min; $i++) {
            $cur = self::shade($cur, $towardLight ? 0.015 : -0.015);
        }
        return $cur;
    }

    // ---- maths ----------------------------------------------------------------------------------------------
    public static function norm(?string $hex): ?string
    {
        if (! is_string($hex)) return null;
        $h = trim($hex);
        if (preg_match('/^#?([0-9a-fA-F]{3})$/', $h, $m)) { $h = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2]; }
        if (! preg_match('/^#?[0-9a-fA-F]{6}$/', $h)) return null;
        return '#' . strtoupper(ltrim($h, '#'));
    }

    public static function mix(string $a, string $b, float $t): string
    {
        [$ar, $ag, $ab] = self::rgb($a); [$br, $bg, $bb] = self::rgb($b);
        return self::hex((int) round($ar + ($br - $ar) * $t), (int) round($ag + ($bg - $ag) * $t), (int) round($ab + ($bb - $ab) * $t));
    }

    public static function lightness(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $max = max($r, $g, $b) / 255; $min = min($r, $g, $b) / 255;
        return ($max + $min) / 2;
    }

    public static function shade(string $hex, float $dl): string
    {
        [$h, $s, $l] = self::hsl($hex);
        return self::fromHsl($h, $s, max(0.0, min(1.0, $l + $dl)));
    }

    public static function setLightness(string $hex, float $l): string
    {
        [$h, $s] = self::hsl($hex);
        return self::fromHsl($h, $s, $l);
    }

    private static function rgb(string $hex): array
    {
        $h = ltrim(self::norm($hex) ?? '#000000', '#');
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    private static function hex(int $r, int $g, int $b): string
    {
        $c = fn (int $v) => str_pad(dechex(max(0, min(255, $v))), 2, '0', STR_PAD_LEFT);
        return strtoupper('#' . $c($r) . $c($g) . $c($b));
    }

    private static function hsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn ($v) => $v / 255, self::rgb($hex));
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2;
        if ($max === $min) return [0.0, 0.0, $l];
        $d = $max - $min; $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) { $r => (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6, $g => (($b - $r) / $d + 2) / 6, default => (($r - $g) / $d + 4) / 6 };
        return [$h, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        if ($s == 0.0) { $v = (int) round($l * 255); return self::hex($v, $v, $v); }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s; $p = 2 * $l - $q;
        $f = function (float $t) use ($p, $q): float {
            if ($t < 0) $t += 1; if ($t > 1) $t -= 1;
            if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
            if ($t < 1 / 2) return $q;
            if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
            return $p;
        };
        return self::hex((int) round($f($h + 1 / 3) * 255), (int) round($f($h) * 255), (int) round($f($h - 1 / 3) * 255));
    }
}
