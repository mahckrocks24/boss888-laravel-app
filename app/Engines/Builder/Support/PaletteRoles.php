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

    /**
     * Normalise an exported site's pages (literals → roles, idempotent) and write the roles block into each.
     * RISK-0191 U1 (2026-09-19): the set of files is the explicit list from exportPageFiles() — the top-level pages
     * AND every `pages` row's {slug}/index.html (they were never repainted before) — and the rendered regions
     * (Arthur-added sections, deployed page bodies) go through roleifyRendered as well, since PaletteNormalizer
     * leaves a template's neutrals alone on a dark ground while the renderer's neutrals are only defaults.
     * @param array<string,string> $siteBrand  the site's current primary/secondary/accent hex (read from the row when omitted)
     */
    public static function normaliseExport(int $websiteId, array $roles, array $manifest, array $siteBrand = []): array
    {
        $brand = [];
        foreach (($manifest['palette_roles'] ?? []) as $var => $role) {
            if (in_array($role, ['primary', 'secondary', 'accent'], true)) { $d = $manifest['variables'][$var]['default'] ?? null; if (is_string($d)) $brand[$role] = $d; }
        }
        if ($siteBrand === []) {
            $tv = json_decode((string) (\Illuminate\Support\Facades\DB::table('websites')->where('id', $websiteId)->value('template_variables') ?: '{}'), true) ?: [];
            foreach (['primary', 'secondary', 'accent'] as $r) { if (is_string($tv[$r . '_color'] ?? null)) $siteBrand[$r] = $tv[$r . '_color']; }
        }
        $done = [];
        $dir = storage_path("app/public/sites/{$websiteId}");
        foreach (self::exportPageFiles($websiteId) as $file) {
            $html = (string) @file_get_contents($file);
            if ($html === '') continue;
            $n = PaletteNormalizer::normalizeHtml($html, $brand, PaletteNormalizer::varRolesFor($manifest));
            $r = self::roleifyRegions($n['html'], $roles, $siteBrand);
            $out = self::injectBlock($r['html'], [], $manifest, $roles);
            if ($out !== $html) { @file_put_contents($file, $out); }
            $done[ltrim(str_replace($dir, '', $file), '/')] = $n['count'] + $r['regions'];
        }
        return $done;
    }

    // ── RISK-0191 U1 (2026-09-19): rendered sections and pages speak the roles ────────────────────────────────────
    /** Marker on an added-section wrapper (and a deployed page's <main>) that says its colours are role variables. */
    public const RENDERED_MARK = 'data-lu-roles';
    public const RENDERED_MARK_VERSION = '1';

    /**
     * BuilderRenderer (and the Editorial/Enterprise traits) write a closed vocabulary of literal colours into inline
     * styles: `#fff` shells and cards, `#1a1a2e`/`#111827` headings, `#5a5f72`/`#6b7280` muted copy, `rgba(0,0,0,.1)`
     * borders and the three brand hexes. Those literals are the renderer's DEFAULTS, not a design's intent, so —
     * unlike a template's own white card on a dark design — every one of them becomes the matching role:
     * `var(--lu-<role>, <the role's current hex>)`. The fallback is the role's value at write time, so a page without
     * the roles block still reads right and a page with it repaints on every palette switch. Idempotent: values that
     * are already `var(...)` are never touched, and the same fragment converted twice is byte-identical.
     *
     * Context: white text takes its "on" role from the nearest painted ancestor (a brand button, a dark band, a brand
     * section shell) found by walking the fragment's own tags; with no painted ancestor the literal is left alone
     * rather than guessed. Semantic reds/greens, shadows and translucent black tints are left as they are.
     *
     * @param array<string,string> $roles  PaletteRoles::derive() output for the site
     * @param array<string,string> $brand  'primary'|'secondary'|'accent' => hex the renderer was given (so its brand
     *                                     literals map to the brand role rather than to "some accent")
     */
    public static function roleifyRendered(string $html, array $roles, array $brand = []): string
    {
        if ($html === '' || $roles === []) return $html;
        $brandHex = [];
        foreach (['primary', 'secondary', 'accent'] as $r) {
            foreach ([$r, $r . '_color'] as $k) { $h = self::norm($brand[$k] ?? null); if ($h !== null) $brandHex[$h] = $r; }
        }
        // the renderer's own defaults when no brand was supplied
        foreach (['#7C3AED' => 'primary', '#6C5CE7' => 'primary', '#00E5A8' => 'accent'] as $h => $r) { $brandHex[self::norm($h)] ??= $r; }

        $var = function (string $role, ?string $alpha = null) use ($roles): ?string {
            if (! isset($roles[$role])) return null;
            $css = '--lu-' . str_replace('_', '-', $role);
            if ($alpha !== null) return 'rgba(var(' . $css . '-rgb, ' . self::rgbTriple($roles[$role]) . '), ' . $alpha . ')';
            return 'var(' . $css . ', ' . $roles[$role] . ')';
        };

        // One declaration block (a style attribute or a rule body). $ctxRole = the painted ancestor's role, if any.
        // Returns [css, ownBgRole|null].
        $convert = function (string $css, ?string $ctxRole, bool $isShell) use ($var, $brandHex, $roles): array {
            // a background written as a role or brand variable (`background:var(--lu-primary, …)`, the quiz's
            // `var(--lq-p)`) is a painted surface too: its white text is "on" that role
            $bgVarRole = null;
            if (preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*[^;]*var\(\s*--(lu-(primary|secondary|accent|dark)|lq-(p|a))\b/i', $css, $vm)) {
                $bgVarRole = match (strtolower($vm[1])) { 'lq-p' => 'primary', 'lq-a' => 'accent', default => strtolower($vm[2]) };
            }
            // an already-converted gradient (var(--lu-primary…) 0%, var(--lu-secondary…) 100%) gets the same on-colour rule
            $css = preg_replace_callback('/(background(?:-image)?\s*:\s*[^;]*gradient\()([^;]*)/i', function ($gm) use ($roles) {
                $first = null;
                $body = preg_replace_callback('/var\(\s*--lu-(primary|secondary|accent|dark|primary-deep|accent-deep)\s*,\s*#[0-9a-fA-F]{3,6}\s*\)/', function ($vm) use (&$first, $roles) {
                    $role = str_replace('-', '_', $vm[1]); $fam = explode('_', $role)[0];
                    if ($first === null) { $first = $fam; return $vm[0]; }
                    if ($fam !== $first && isset($roles['on_' . $first], $roles[$role]) && ColorTheme::contrast($roles['on_' . $first], $roles[$role]) < self::AA) {
                        return 'var(--lu-' . $first . ', ' . $roles[$first] . ')';
                    }
                    return $vm[0];
                }, $gm[2]) ?? $gm[2];
                return $gm[1] . $body;
            }, $css) ?? $css;
            // mask var(...) values (their fallbacks are hexes too) and template placeholders
            $masks = [];
            $css = preg_replace_callback('/var\([^()]*(?:\([^()]*\)[^()]*)*\)|\{\{[^{}]*\}\}/', function ($m) use (&$masks) { $k = "\x01" . count($masks) . "\x02"; $masks[$k] = $m[0]; return $k; }, $css) ?? $css;
            $decls = array_map('trim', explode(';', $css));
            $bgRole = null; $bgLit = null;
            // first pass: what is this block's own background?
            foreach ($decls as $d) {
                if (! preg_match('/^(background|background-color)\s*:\s*(.+)$/i', $d, $m)) continue;
                $bgLit = $m[2];
            }
            $classify = function (string $lit, string $cls, ?string $on) use ($brandHex, $roles, $isShell): ?array {
                $l = strtolower(trim($lit));
                // white
                if ($l === '#fff' || $l === '#ffffff' || $l === 'white') {
                    if ($cls === 'text' || $cls === 'fill') return $on ? ['on_' . $on, null] : null;
                    if ($cls === 'bg') return [$isShell ? 'bg' : 'surface', null];
                    return null;
                }
                if (preg_match('/^rgba\(\s*255\s*,\s*255\s*,\s*255\s*,\s*([0-9.]+)\s*\)$/', $l, $am)) {
                    if (($cls === 'text' || $cls === 'fill') && $on) return ['on_' . $on, $am[1]];
                    return null;
                }
                // translucent dark neutral (rgba(0,0,0,.1), the quiz's rgba(15,23,42,.7)): text by alpha, a border line, a tint left alone
                if (preg_match('/^rgba\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([0-9.]+)\s*\)$/', $l, $am) && (int) $am[1] < 64 && (int) $am[2] < 64 && (int) $am[3] < 64) {
                    $a = (float) $am[4];
                    if ($cls === 'text' || $cls === 'fill') return $a >= 0.6 ? ['text', null] : ($a >= 0.3 ? ['muted', null] : null);
                    return ($cls === 'border' && $a <= 0.35) ? ['line', null] : null;
                }
                if (preg_match('/^rgba\(\s*15\s*,\s*17\s*,\s*23\s*,/', $l)) return $cls === 'bg' ? ['dark', null] : null;
                if ($l === 'black' || $l === '#000' || $l === '#000000') return $cls === 'text' ? ['text', null] : ($cls === 'bg' ? ['dark', null] : null);
                if (! preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $l)) return null;
                $hex = self::norm($l); if ($hex === null) return null;
                if (isset($brandHex[$hex])) {
                    $r = $brandHex[$hex];
                    return match ($cls) { 'text', 'fill' => [$r . '_text', null], 'bg', 'gradient', 'border' => [$r, null], default => null };
                }
                $L = self::lightness($hex);
                // chroma: a coloured literal that is not the brand — the accent family (semantic red/green untouched)
                [$sat, $hue] = self::satHue($hex);
                $chroma = $sat * (1 - abs(2 * $L - 1));
                if ($sat >= 0.35 && $chroma >= 0.12 && ($hue >= 335 || $hue <= 20 || ($hue >= 80 && $hue <= 165))) return null;
                if ($chroma >= 0.25 && $L > 0.12 && $L < 0.92) {
                    return match ($cls) { 'text', 'fill' => ['accent_text', null], 'bg', 'gradient' => [$L < 0.6 ? 'accent' : 'accent_soft', null], 'border' => ['accent', null], default => null };
                }
                // neutrals — the renderer's defaults, mapped whatever the design's ground is
                if ($L < 0.25)  return match ($cls) { 'text', 'fill' => ['text', null], 'bg', 'gradient' => ['dark', null], 'border' => ['line', null], default => null };
                if ($L < 0.62)  return match ($cls) { 'text', 'fill' => ['muted', null], 'bg', 'gradient' => ['surface2', null], 'border' => ['line', null], default => null };
                if ($L < 0.955) return match ($cls) { 'text', 'fill' => $on ? ['on_' . $on, null] : null, 'bg', 'gradient' => ['surface2', null], 'border' => ['line', null], default => null };
                return match ($cls) { 'text', 'fill' => $on ? ['on_' . $on, null] : null, 'bg', 'gradient' => [$isShell ? 'bg' : 'surface', null], 'border' => ['line', null], default => null };
            };
            // the block's own background role decides what its white text is "on"
            if ($bgLit !== null) {
                $cls = str_contains(strtolower($bgLit), 'gradient') ? 'gradient' : 'bg';
                $lits = [];
                preg_match_all('/#(?:[0-9a-f]{3}|[0-9a-f]{6})\b|rgba\([^)]*\)|(?<![-\w])(?:white|black)(?![-\w])/i', $bgLit, $lm);
                foreach ($lm[0] as $lit) { $c = $classify($lit, $cls, null); if ($c) { $lits[] = $c[0]; } }
                if ($lits !== []) {
                    $first = $lits[0];
                    $bgRole = in_array($first, ['primary', 'secondary', 'accent', 'dark'], true) ? $first
                        : (in_array($first, ['primary_deep', 'accent_deep'], true) ? explode('_', $first)[0] : null);
                }
            }
            $bgRole = $bgRole ?? $bgVarRole;
            $on = $bgRole ?? $ctxRole;
            $outDecls = [];
            foreach ($decls as $d) {
                if ($d === '') continue;
                if (! preg_match('/^([a-zA-Z-]+)\s*:\s*(.+)$/s', $d, $m)) { $outDecls[] = $d; continue; }
                $prop = strtolower($m[1]); $val = $m[2];
                if (str_starts_with($prop, '--') && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', trim($val))) {
                    $h = self::norm(trim($val));
                    if ($h !== null && isset($brandHex[$h])) { $outDecls[] = $prop . ':' . ($var($brandHex[$h]) ?? $val); continue; }
                    $outDecls[] = $d; continue;
                }
                $cls = match (true) {
                    $prop === 'color' => 'text',
                    $prop === 'fill' || $prop === 'stroke' => 'fill',
                    $prop === 'background' || $prop === 'background-color' => str_contains(strtolower($val), 'gradient') ? 'gradient' : 'bg',
                    in_array($prop, ['border', 'border-color', 'border-top', 'border-bottom', 'border-left', 'border-right', 'outline'], true) => 'border',
                    default => null,
                };
                if ($cls === null) { $outDecls[] = $d; continue; }
                // a gradient across two brand colours carries ONE on-colour (the first stop's): a later stop that on-colour
                // cannot be read on (white on a pale secondary) is painted with the first stop's role instead — a flat
                // brand band beats an unreadable one (Owner 2026-09-18: contrast first)
                $gradFirst = null;
                $val = preg_replace_callback('/#(?:[0-9a-f]{3}|[0-9a-f]{6})\b|rgba\([^)]*\)|(?<![-\w])(?:white|black)(?![-\w])/i', function ($lm) use ($classify, $cls, $on, $var, $roles, &$gradFirst) {
                    $c = $classify($lm[0], $cls, $on);
                    if ($c === null) return $lm[0];
                    if ($cls === 'gradient' && in_array($c[0], ['primary', 'secondary', 'accent', 'dark', 'primary_deep', 'accent_deep'], true)) {
                        $fam = explode('_', $c[0])[0];
                        if ($gradFirst === null) { $gradFirst = $fam; }
                        elseif ($fam !== $gradFirst && isset($roles['on_' . $gradFirst], $roles[$c[0]]) && ColorTheme::contrast($roles['on_' . $gradFirst], $roles[$c[0]]) < self::AA) { $c = [$gradFirst, null]; }
                    }
                    return $var($c[0], $c[1]) ?? $lm[0];
                }, $val) ?? $val;
                $outDecls[] = $prop . ':' . $val;
            }
            $css = implode(';', $outDecls);
            if ($outDecls !== [] && substr($css, -1) !== ';') $css .= ';';
            $css = str_replace(array_keys($masks), array_values($masks), $css);
            return [$css, $bgRole];
        };

        // <style> blocks the renderer ships (self-initialising elements): rule bodies, no ancestor context
        $html = preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function ($m) use ($convert) {
            if (preg_match('/id="lug-/', $m[1])) return $m[0];
            $css = preg_replace_callback('/\{([^{}]*)\}/s', fn ($b) => '{' . $convert($b[1], null, false)[0] . '}', $m[2]) ?? $m[2];
            return $m[1] . $css . $m[3];
        }, $html) ?? $html;

        // tags in order, with a stack of painted ancestors so white text finds what it sits on
        $stack = [];   // [tagName, bgRole|null]
        $void = ['br' => 1, 'img' => 1, 'hr' => 1, 'input' => 1, 'meta' => 1, 'link' => 1, 'source' => 1, 'wbr' => 1, 'area' => 1, 'col' => 1, 'embed' => 1, 'track' => 1];
        $out = preg_replace_callback('/<\/?([a-zA-Z][a-zA-Z0-9-]*)(\s[^<>]*?)?(\/?)>/s', function ($m) use (&$stack, $void, $convert) {
            $tag = strtolower($m[1]); $attrs = $m[2] ?? ''; $selfClose = $m[3] === '/';
            if ($m[0][1] === '/') {
                // closing: pop to the matching open tag (tolerant of the renderer's occasional unbalanced markup)
                for ($i = count($stack) - 1; $i >= 0; $i--) { if ($stack[$i][0] === $tag) { array_splice($stack, $i); break; } }
                return $m[0];
            }
            $ctx = null;
            for ($i = count($stack) - 1; $i >= 0; $i--) { if ($stack[$i][1] !== null) { $ctx = $stack[$i][1]; break; } }
            $bg = null;
            if ($attrs !== '' && preg_match('/\sstyle="([^"]*)"/i', $attrs, $sm)) {
                [$css, $bg] = $convert($sm[1], $ctx, $tag === 'section');
                if ($css !== $sm[1]) $attrs = str_replace($sm[0], ' style="' . $css . '"', $attrs);
            }
            if (! $selfClose && ! isset($void[$tag])) $stack[] = [$tag, $bg];
            return '<' . $m[1] . $attrs . ($selfClose ? '/' : '') . '>';
        }, $html);
        $out = $out ?? $html;
        // every fallback names the role's CURRENT value, so a file never carries a stale colour (the variable wins on
        // any page with the roles block; the fallback is what a page without it would show)
        $out = preg_replace_callback('/var\(--lu-([a-z]+(?:-[a-z]+)*?)(-rgb)?, (#[0-9A-Fa-f]{3,6}|\d{1,3},\d{1,3},\d{1,3})\)/', function ($fm) use ($roles) {
            $role = str_replace('-', '_', $fm[1]);
            if (! isset($roles[$role])) return $fm[0];
            $cur = $fm[2] === '-rgb' ? self::rgbTriple($roles[$role]) : $roles[$role];
            return 'var(--lu-' . $fm[1] . $fm[2] . ', ' . $cur . ')';
        }, $out) ?? $out;
        return $out;
    }

    /** @return array{0:float,1:float} saturation (0..1) and hue in degrees */
    private static function satHue(string $hex): array
    {
        $h = ltrim($hex, '#');
        $r = hexdec(substr($h, 0, 2)) / 255; $g = hexdec(substr($h, 2, 2)) / 255; $b = hexdec(substr($h, 4, 2)) / 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2;
        if ($max === $min) return [0.0, 0.0];
        $d = $max - $min; $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $hue = match ($max) { $r => (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6, $g => (($b - $r) / $d + 2) / 6, default => (($r - $g) / $d + 4) / 6 };
        return [$s, $hue * 360];
    }

    /**
     * Convert the rendered regions of an exported page: every `<section data-block="added_*">` wrapper (the home's
     * Arthur-added sections) and the `<main data-lu-page>` body of a deployed sub-page. The template's own chrome is
     * never touched here (PaletteNormalizer owns it). Idempotent.
     * @return array{html:string, regions:int}
     */
    public static function roleifyRegions(string $doc, array $roles, array $brand = []): array
    {
        $n = 0;
        $doc = self::replaceBalanced($doc, '/<section\b[^>]*\bdata-block="added_[a-z0-9_]+"[^>]*>/i', 'section', function (string $open, string $inner, string $close) use ($roles, $brand, &$n) {
            $n++;
            $conv = self::roleifyRendered($inner, $roles, $brand);
            if (! str_contains($open, self::RENDERED_MARK . '=')) $open = preg_replace('/<section\b/i', '<section ' . self::RENDERED_MARK . '="' . self::RENDERED_MARK_VERSION . '"', $open, 1) ?? $open;
            return $open . $conv . $close;
        });
        $doc = self::replaceBalanced($doc, '/<main\b[^>]*\bdata-lu-page="[^"]*"[^>]*>/i', 'main', function (string $open, string $inner, string $close) use ($roles, $brand, &$n) {
            $n++;
            return $open . self::roleifyRendered($inner, $roles, $brand) . $close;
        });
        return ['html' => $doc, 'regions' => $n];
    }

    /** Replace every balanced <tag …>…</tag> block whose opening tag matches $openRe (nested same-name tags counted). */
    private static function replaceBalanced(string $doc, string $openRe, string $tag, callable $fn): string
    {
        $offset = 0; $out = '';
        while (preg_match($openRe, $doc, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1]; $openLen = strlen($m[0][0]);
            $depth = 1; $pos = $start + $openLen; $end = null;
            while (preg_match('/<(\/?)' . $tag . '\b[^>]*>/i', $doc, $t, PREG_OFFSET_CAPTURE, $pos)) {
                $pos = $t[0][1] + strlen($t[0][0]);
                $depth += $t[1][0] === '/' ? -1 : 1;
                if ($depth === 0) { $end = $pos; $closeLen = strlen($t[0][0]); break; }
            }
            if ($end === null) break;   // unbalanced: leave the rest untouched
            $inner = substr($doc, $start + $openLen, $end - $closeLen - ($start + $openLen));
            $out .= substr($doc, $offset, $start - $offset) . $fn($m[0][0], $inner, substr($doc, $end - $closeLen, $closeLen));
            $offset = $end;
        }
        return $out . substr($doc, $offset);
    }

    /**
     * The exported pages of ONE website, as an explicit, bounded list: the top-level *.html files and, for every
     * `pages` row of that website, `{slug}/index.html`. Never recursive; a slug is [a-z0-9-] only (no `.`, no `/`);
     * a symlinked directory or file is skipped; and every path must resolve inside the website's own directory.
     * @return string[] absolute file paths
     */
    public static function exportPageFiles(int $websiteId): array
    {
        $dir = storage_path("app/public/sites/{$websiteId}");
        $real = realpath($dir);
        if ($real === false || ! is_dir($real) || is_link($dir)) return [];
        $files = [];
        foreach (glob($dir . '/*.html') ?: [] as $f) { if (! is_link($f) && is_file($f)) $files[] = $f; }
        $slugs = \Illuminate\Support\Facades\DB::table('pages')->where('website_id', $websiteId)->pluck('slug');
        foreach ($slugs as $slug) {
            $slug = (string) $slug;
            if (! preg_match('/^[a-z0-9-]{1,80}$/', $slug) || in_array($slug, ['home', 'index', 'blog'], true)) continue;
            $sub = $dir . '/' . $slug;
            if (is_link($sub) || ! is_dir($sub)) continue;
            $f = $sub . '/index.html';
            if (is_link($f) || ! is_file($f)) continue;
            $rf = realpath($f);
            if ($rf === false || ! str_starts_with($rf, $real . DIRECTORY_SEPARATOR)) continue;
            $files[] = $f;
        }
        return array_values(array_unique($files));
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
