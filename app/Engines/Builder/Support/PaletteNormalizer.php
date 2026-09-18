<?php

namespace App\Engines\Builder\Support;

/**
 * PALETTE NORMALISER (Owner, 2026-09-18): hard-coded colours in a design's CSS never move when the palette does.
 *
 * Rewrites colour literals inside <style> blocks into palette roles — `#0F172A` as text becomes
 * `var(--lu-text, #0F172A)`, `#E2E8F0` as a border becomes `var(--lu-line, #E2E8F0)`, white text on a secondary
 * surface becomes `var(--lu-on-secondary, #fff)` — each with the original as fallback, so a page that has no role
 * block renders byte-for-byte as before. Rule-aware: what a literal becomes depends on the property it paints and,
 * for text, on the background the same rule sets. Semantic colours (error red, success green) and shadows /
 * overlays (rgba black) are left alone. Idempotent.
 *
 * Runs once over the template sources (builder:palette-normalize) and at palette-switch time over an exported site.
 */
final class PaletteNormalizer
{
    /** css var name (`--medical`) => surface role (primary|secondary|accent|dark) for the page being normalised (set per call). */
    private static array $varRole = [];

    /** selector => surface role, for rules that paint a section (footer, dark band): descendants inherit the context. */
    private static array $ctx = [];

    /** 'light' | 'dark' — the design's ground (manifest palette_scheme); neutral literals are only re-rolled on a light ground. */
    private static string $scheme = 'light';

    /**
     * Which of a design's own variables are the brand and the dark surface. The design's `:root` names them —
     * `--medical: {{medical_blue}}` — so the map is read from the placeholders (template source) and the manifest's
     * palette_roles / color_roles, with the generated designs' fixed vocabulary (--brand, --brand-2, --accent, --deep)
     * and a name-based fallback (`gold` → `--gold`) for an export whose placeholders are long gone.
     * @return array<string,string> lower-case css var => primary|secondary|accent|dark
     */
    public static function varRolesFor(array $manifest, ?string $templateHtml = null): array
    {
        $manifestRole = [];
        foreach (['primary_color' => 'primary', 'primary_deep' => 'primary', 'secondary_color' => 'secondary', 'accent_color' => 'accent'] as $v => $r) $manifestRole[$v] = $r;
        // which neutral variables are a DARK surface depends on the scheme: a light design's text/dark vars, a dark
        // design's ground and surfaces (its text var is light and never a surface)
        $darkVars = ($manifest['palette_scheme'] ?? 'light') === 'dark' ? ['bg', 'surface', 'surface2', 'dark'] : ['dark', 'text'];
        foreach (($manifest['palette_roles'] ?? []) as $var => $role) {
            if (! is_string($role)) continue;
            $r = match ($role) { 'primary', 'primary_deep' => 'primary', 'secondary' => 'secondary', 'accent', 'accent_deep' => 'accent', 'muted' => 'muted', 'line' => 'line', 'secondary_soft', 'accent_soft' => $role, default => (in_array($role, $darkVars, true) ? 'dark' : (in_array($role, ['bg', 'surface', 'surface2'], true) && ($manifest['palette_scheme'] ?? 'light') === 'light' ? $role : null)) };
            if ($r !== null) $manifestRole[(string) $var] = $r;
        }
        // the brand painter follows color_roles (a hotel's `teal` is painted with the ACCENT whatever palette_roles
        // calls it), so for the brand variables color_roles is the truth the normaliser must share
        foreach (($manifest['color_roles'] ?? []) as $role => $var) {
            if (! is_string($var) || $var === '') continue;
            if (in_array($var, ['primary_color', 'primary_deep', 'secondary_color', 'accent_color'], true)) continue; // the generic names are painted by name
            if ($role === 'accent' || $role === 'accent_deep') $manifestRole[$var] = 'accent';
            elseif ($role === 'secondary') $manifestRole[$var] = 'secondary';
        }
        $map = ['@scheme' => (($manifest['palette_scheme'] ?? 'light') === 'dark' ? 'dark' : 'light'), '--brand' => 'primary', '--brand-deep' => 'primary', '--brand-2' => 'secondary', '--accent' => 'accent', '--deep' => 'dark', '--line' => 'line', '--muted' => 'muted', '--ink' => 'text', '--text' => 'text', '--paper' => 'bg', '--tint' => 'surface'];
        foreach ($manifestRole as $var => $r) $map['--' . str_replace('_', '-', $var)] = $r;
        if ($templateHtml === null && is_string($manifest['id'] ?? null)) {
            $p = storage_path('templates/' . $manifest['id'] . '/template.html');
            if (is_file($p)) $templateHtml = (string) file_get_contents($p);
        }
        if (is_string($templateHtml) && preg_match_all('/(--[a-zA-Z0-9-]+)\s*:\s*\{\{\s*([a-z0-9_]+)\s*\}\}/', $templateHtml, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { if (isset($manifestRole[$m[2]])) $map[strtolower($m[1])] = $manifestRole[$m[2]]; }
        }
        return $map;
    }

    /** @return array{html:string, changes:array<string,int>, count:int} */
    public static function normalizeHtml(string $html, array $brandDefaults = [], array $varRoles = []): array
    {
        $changes = [];
        self::$varRole = array_change_key_case($varRoles, CASE_LOWER);
        self::$scheme = (self::$varRole['@scheme'] ?? 'light') === 'dark' ? 'dark' : 'light';
        unset(self::$varRole['@scheme']);
        $out = preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function ($m) use (&$changes, $brandDefaults, $html) {
            if (preg_match('/id="lug-(palette-roles|design-style|design-extras)"/', $m[1])) return $m[0];
            // template placeholders ({{accent_color}}) are braces too — mask them from the rule scanner
            $masked = preg_replace('/\{\{([^{}]*)\}\}/', '@@PH:$1@@', $m[2]) ?? $m[2];
            // the scoped rules from a previous run are regenerated below (and only counted when they change)
            $had = preg_match('~\n/\* lug-scope \*/.*?/\* /lug-scope \*/\n~s', $masked, $hm) ? $hm[0] : '';
            $masked = $had === '' ? $masked : str_replace($had, '', $masked);
            self::$ctx = self::surfaceContexts($masked);
            $css = self::normalizeCss($masked, $changes, $brandDefaults);
            // a generic rule (`.lede{color:var(--ink)}`) whose elements ALSO sit inside a painted section gets a scoped
            // twin there — found in the page's own DOM, not guessed
            $scopeChanges = [];
            $scoped = self::scopeIntoSurfaces($html, $css, $scopeChanges);
            if ($scoped !== $had) { foreach ($scopeChanges as $k => $n) $changes[$k] = ($changes[$k] ?? 0) + $n; }
            $css = preg_replace('/@@PH:([^@]*)@@/', '{{$1}}', $css . $scoped) ?? ($css . $scoped);
            return $m[1] . $css . $m[3];
        }, $html);
        $out = $out ?? $html;
        // inline styles (a contact form's `style="background:var(--medical);color:#fff"`) are rules too: each attribute
        // is one declaration block on its own surface (no selector, no section context)
        $out = preg_replace_callback('/(<[a-zA-Z][^>]*?\sstyle=")([^"]*)(")/', function ($m) use (&$changes, $brandDefaults) {
            if (! preg_match('/(?<![-\w])(color|background|background-color|border|border-color|fill|stroke)\s*:/i', $m[2])) return $m[0];
            $masked = preg_replace('/\{\{([^{}]*)\}\}/', '@@PH:$1@@', $m[2]) ?? $m[2];
            $decls = self::normalizeDecls($masked, $changes, $brandDefaults, '');
            $decls = preg_replace('/@@PH:([^@]*)@@/', '{{$1}}', $decls) ?? $decls;
            return $m[1] . $decls . $m[3];
        }, $out) ?? $out;
        return ['html' => $out, 'changes' => $changes, 'count' => array_sum($changes)];
    }

    /** Rewrite one stylesheet. Walks rule by rule (nested @media handled by a small brace scanner). */
    public static function normalizeCss(string $css, array &$changes, array $brandDefaults = []): string
    {
        $out = ''; $i = 0; $n = strlen($css);
        while ($i < $n) {
            $open = strpos($css, '{', $i);
            if ($open === false) { $out .= substr($css, $i); break; }
            $selector = substr($css, $i, $open - $i);
            // at-rule with a block of rules (media/supports): recurse into its body
            if (preg_match('/@(media|supports|container|layer)\b/i', $selector)) {
                $close = self::matchBrace($css, $open);
                $out .= $selector . '{' . self::normalizeCss(substr($css, $open + 1, $close - $open - 1), $changes, $brandDefaults) . '}';
                $i = $close + 1; continue;
            }
            $close = strpos($css, '}', $open);
            if ($close === false) { $out .= substr($css, $i); break; }
            $decls = substr($css, $open + 1, $close - $open - 1);
            $skip = preg_match('/:root\b/', $selector) || preg_match('/@(keyframes|font-face)/i', $selector) || str_contains($selector, '%');
            $out .= $selector . '{' . ($skip ? $decls : self::normalizeDecls($decls, $changes, $brandDefaults, $selector)) . '}';
            $i = $close + 1;
        }
        return $out;
    }

    private static function matchBrace(string $s, int $open): int
    {
        $d = 0;
        for ($k = $open, $n = strlen($s); $k < $n; $k++) {
            if ($s[$k] === '{') $d++;
            elseif ($s[$k] === '}') { $d--; if ($d === 0) return $k; }
        }
        return $n - 1;
    }

    private static function normalizeDecls(string $decls, array &$changes, array $brandDefaults, string $selector = ''): string
    {
        // the background this rule paints, if it is a role variable — decides what its text literal becomes
        $onRole = null; $ownSurface = false;
        if (preg_match('/(?<![-\w])background(?:-color)?\s*:\s*([^;]+)/i', $decls, $bm)) {
            $onRole = self::roleOfBackground($bm[1]);
            $ownSurface = $onRole !== null || self::isOpaqueBackground($bm[1]);
        }
        // no (opaque) background of its own: a descendant of a painted section (`footer .eyebrow`) sits on that section
        if ($onRole === null && ! $ownSurface && $selector !== '') $onRole = self::contextFor($selector);
        return preg_replace_callback('/(?<![-\w])([a-zA-Z-]+)(\s*:\s*)([^;{}]+)/', function ($m) use (&$changes, $onRole, $brandDefaults) {
            $prop = strtolower($m[1]); $sep = $m[2]; $val = $m[3];
            if (str_starts_with($prop, '--') || in_array($prop, ['content', 'font', 'font-family', 'transition', 'animation'], true)) return $m[0];
            $cls = self::propClass($prop, $val);
            if ($cls === null) return $m[0];
            // a brand colour used AS TEXT on the page (eyebrows, links, prices): the readable version of it.
            // One variable, two jobs — a button background and small text — cannot both be right; the text side
            // is re-pointed at the *_text role (contrast-fitted on the ground), the background keeps the variable.
            if ($cls === 'text' || $cls === 'fill') {
                // a var() already sitting as the fallback of a role — `var(--lu-accent-text, var(--accent))` — is done
                $new0 = preg_replace_callback('/(?<!,)(?<!,\s)var\(\s*(--[a-zA-Z0-9-]+)\s*(?:,\s*[^()]*)?\)/', function ($v) use (&$changes, $onRole) {
                    $name = strtolower($v[1]);
                    if (str_starts_with($name, '--lu-')) return $v[0];
                    $suffix = $onRole === 'dark' ? '_on_dark' : '_text'; // on the dark band the brand is lifted, on the page it is deepened
                    $vr = self::$varRole[$name] ?? null;
                    // on a brand surface (a button, a band) only the page's own text colours are corrected — to what
                    // reads on that surface; a brand colour written there is the designer's call
                    if ($onRole === 'primary' || $onRole === 'secondary' || $onRole === 'accent') {
                        if (! in_array($vr, ['text', 'dark', 'muted', 'line', 'bg', 'surface', 'surface2', 'secondary_soft', 'accent_soft'], true)) return $v[0];
                        $changes['on_' . $onRole] = ($changes['on_' . $onRole] ?? 0) + 1;
                        return 'var(--lu-on-' . $onRole . ', ' . $v[0] . ')';
                    }
                    // the page's text (or its dark) on the dark band is invisible: on_dark
                    if (($vr === 'text' || $vr === 'dark') && $onRole === 'dark') { $changes['on_dark'] = ($changes['on_dark'] ?? 0) + 1; return 'var(--lu-on-dark, ' . $v[0] . ')'; }
                    // the page's muted grey on a dark band is unreadable: translucent on_dark instead (a muted look kept)
                    if ($vr === 'muted' && $onRole === 'dark') { $changes['on_dark'] = ($changes['on_dark'] ?? 0) + 1; return 'rgba(var(--lu-on-dark-rgb, 255,255,255), .72)'; }
                    // a hairline colour used as text (ghosted step numerals) becomes the readable muted
                    if ($vr === 'line') { $changes['muted'] = ($changes['muted'] ?? 0) + 1; return $onRole === 'dark' ? 'rgba(var(--lu-on-dark-rgb, 255,255,255), .72)' : 'var(--lu-muted, ' . $v[0] . ')'; }
                    $role = match ($vr) { 'primary' => 'primary' . $suffix, 'secondary' => 'secondary' . $suffix, 'accent' => 'accent' . $suffix, default => null };
                    if ($role === null) return $v[0];
                    $changes[$role] = ($changes[$role] ?? 0) + 1;
                    return 'var(--lu-' . str_replace('_', '-', $role) . ', ' . $v[0] . ')';
                }, $val);
                if (is_string($new0)) $val = $new0;
            }
            // a literal inside a var() fallback (`var(--medical,#0F172A)`) belongs to that variable, not to the page
            $new = preg_replace_callback('/var\(\s*--[a-zA-Z0-9-]+\s*,\s*[^)]*\)|#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b|rgba\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([0-9.]+)\s*\)|(?<![-\w])(?:white|black)(?![-\w])/i', function ($c) use ($cls, $onRole, $brandDefaults, &$changes) {
                $lit = $c[0];
                if (str_starts_with($lit, 'var(')) return $lit; // already normalised
                if (isset($c[4]) && $c[4] !== '') {
                    $a = (float) $c[4]; $rgb = [(int) $c[1], (int) $c[2], (int) $c[3]];
                    $isWhite = min($rgb) >= 245; $isDark = max($rgb) <= 60;
                    if (! $isWhite && ! $isDark) return $lit;
                    if ($isDark) {
                        // translucent dark TEXT (`rgba(28,28,30,.5)` labels) is a muted grey in disguise — it reads at
                        // half strength on the page and not at all on a dark band; shadows and overlays are not text
                        if ($cls !== 'text' || $a >= 0.75 || self::$scheme === 'dark') return $lit;
                        $changes['muted'] = ($changes['muted'] ?? 0) + 1;
                        return $onRole === 'dark' ? 'rgba(var(--lu-on-dark-rgb, 255,255,255), .72)' : 'var(--lu-muted, ' . $lit . ')';
                    }
                    $role = self::roleFor('rgba(255,255,255,' . $c[4] . ')', $cls, $onRole, $brandDefaults);
                    if ($role === null) return $lit;
                    $changes[$role] = ($changes[$role] ?? 0) + 1;
                    if ($a >= 1) return 'var(--lu-' . str_replace('_', '-', $role) . ', ' . $lit . ')';
                    // translucent white keeps its alpha (a 9% watermark numeral must not become solid text) — but text
                    // is floored so it still reads: 62% on the dark band; on a brand surface, where the readable
                    // colour may itself be dark, it goes solid
                    if ($cls === 'text' && $role !== 'on_dark') { return 'var(--lu-' . str_replace('_', '-', $role) . ', ' . $lit . ')'; }
                    $alpha = ($cls === 'text' && $a < 0.62 && $a >= 0.3) ? '.62' : $c[4];
                    return 'rgba(var(--lu-' . str_replace('_', '-', $role) . '-rgb, 255,255,255), ' . $alpha . ')';
                }
                $role = self::roleFor($lit, $cls, $onRole, $brandDefaults);
                if ($role === null) return $lit;
                $changes[$role] = ($changes[$role] ?? 0) + 1;
                return 'var(--lu-' . str_replace('_', '-', $role) . ', ' . $lit . ')';
            }, $val);
            return $m[1] . $sep . $new;
        }, $decls);
    }

    private static function propClass(string $prop, string $val): ?string
    {
        if (str_contains($val, 'gradient')) return 'gradient';
        if (str_starts_with($prop, 'background')) return 'bg';
        if (in_array($prop, ['color', '-webkit-text-fill-color', 'caret-color'], true)) return 'text';
        if (str_starts_with($prop, 'border') || $prop === 'outline' || str_starts_with($prop, 'outline-')) return 'border';
        if (in_array($prop, ['fill', 'stroke'], true)) return 'fill';
        return null; // shadows, filters: untouched
    }

    /** Every `selector{background:…}` that paints a section surface, keyed by selector (comma lists split). */
    public static function surfaceContexts(string $css): array
    {
        $ctx = [];
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $sel = self::cleanSelector($m[1]);
                if ($sel === '' || str_starts_with($sel, '@') || str_contains($sel, ':root') || str_contains($sel, '%')) continue;
                if (! preg_match('/(?<![-\w])background(?:-color)?\s*:\s*([^;]+)/i', $m[2], $bm)) continue;
                $role = self::roleOfBackground($bm[1]) ?? (self::isLightBackground($bm[1]) ? 'light' : null);
                if ($role === null) continue;
                // a band or a card (generous padding) lends its surface to its BEM family; a button (`.form-submit`) does not
                $block = self::paintsABlock($m[2]);
                foreach (explode(',', $sel) as $s) {
                    $s = trim((string) preg_replace('/\s+/', ' ', $s)); if ($s === '') continue;
                    $ctx[$s] = $role;
                    // BEM-ish naming: `.cta-banner` / `.award` paints the band, `.cta-title` / `.award-year` sit in it
                    if ($block && preg_match('/^\.([a-zA-Z][a-zA-Z0-9]{2,})(?:[-_][a-zA-Z0-9_-]+)?$/', $s, $sm)) $ctx['~.' . strtolower($sm[1])] = $role;
                }
            }
        }
        return $ctx;
    }

    private static function isLightBackground(string $val): bool
    {
        if (preg_match_all('/var\(\s*(--[a-zA-Z0-9-]+)\s*[,)]/', $val, $vm)) {
            foreach ($vm[1] as $name) { if (in_array(self::$varRole[strtolower($name)] ?? null, ['bg', 'surface', 'surface2', 'secondary_soft', 'accent_soft'], true)) return true; }
        }
        if (preg_match('/var\(\s*--(paper|tint|lu-bg|lu-surface[0-9]*|white|bg|bg-soft|surface|chalk|cream|ivory|pearl|linen|snow|mist)[a-z0-9-]*\s*[,)]/i', $val)) return true;
        if (preg_match('/^\s*(#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}))\b/', $val, $lm) && self::lightnessOf($lm[1]) > 0.8) return true;
        return (bool) preg_match('/^\s*(white|#fff\b|#ffffff\b)/i', $val);
    }

    /** A background that covers what is behind it: a light neutral, a hex/rgb literal, a named colour or an opaque var; a translucent rgba / a gradient with alpha / transparent does not. */
    private static function isOpaqueBackground(string $val): bool
    {
        $v = trim($val);
        if (preg_match('/^(transparent|none|inherit)\b/i', $v)) return false;
        if (preg_match('/^rgba\(|^hsla\(/i', $v)) return false;
        if (str_contains($v, 'gradient') || str_contains($v, 'url(')) return false;
        if (self::isLightBackground($v)) return true;
        if (preg_match('/^(#[0-9a-fA-F]{3,8}|rgb\(|hsl\(|[a-z]+\s*(;|$))/i', $v)) return true;
        return (bool) preg_match('/^var\(/', $v);
    }

    /**
     * Generic text rules used inside painted sections. For every section container the stylesheet paints (a single
     * class or element selector with a dark/brand surface) the page's DOM is searched for elements a generic colour
     * rule matches; when one is found — and no lighter card sits between them — a scoped twin of the rule is emitted
     * (`.ct-panel .lede{color:var(--lu-on-dark, var(--ink))}`), so the section's text follows the section, not the page.
     */
    public static function scopeIntoSurfaces(string $html, string $css, array &$changes): string
    {
        $containers = [];
        foreach (self::$ctx as $sel => $role) {
            if (str_starts_with($sel, '~') || ! in_array($role, ['dark', 'primary', 'secondary', 'accent', 'light'], true)) continue;
            if (preg_match('/^(\.[a-zA-Z][\w-]*|footer|header|nav|main|aside)$/', $sel)) $containers[$sel] = $role;
            elseif (preg_match('/^[a-zA-Z.][\w.-]*(?: [a-zA-Z.][\w.-]*)*$/', $sel)) $containers[$sel] = $role; // `.ct-panel .card`
        }
        $painted = array_filter($containers, fn ($r) => $r !== 'light');
        if ($painted === []) return '';
        // generic colour rules: selector without pseudo/attr/combinators other than descendant, text painted by a var
        $rules = [];
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $sel = self::cleanSelector($m[1]);
                if ($sel === '' || str_starts_with($sel, '@') || str_contains($sel, ':') || str_contains($sel, '[') || str_contains($sel, '>') || str_contains($sel, '+') || str_contains($sel, '~') || str_contains($sel, ',')) continue;
                if (preg_match('/(?<![-\w])background(?:-color)?\s*:\s*([^;]+)/i', $m[2], $obm) && (self::roleOfBackground($obm[1]) !== null || self::isOpaqueBackground($obm[1]))) continue; // paints its own surface
                if (! preg_match('/(?<![-\w])color\s*:\s*([^;]+)/i', $m[2], $cm)) continue;
                if (self::contextFor($sel) !== null) continue; // already sits in a known section
                if (preg_match('/var\(\s*(--[a-zA-Z0-9-]+)\s*\)/', $cm[1], $vm)) { // the raw (unfitted) variable
                    $name = strtolower($vm[1]);
                    if (str_starts_with($name, '--lu-')) continue;
                    $vr = self::$varRole[$name] ?? (in_array($name, ['--ink', '--text'], true) ? 'text' : null);
                    if (! in_array($vr, ['text', 'muted', 'line', 'primary', 'secondary', 'accent'], true)) continue;
                    $rules[$sel] = ['var' => $vm[0], 'role' => $vr];
                } elseif (preg_match('/^\s*(#fff\b|#ffffff\b|white\b|rgba\(\s*255\s*,\s*255\s*,\s*255\s*,\s*([0-9.]+)\s*\))\s*$/i', $cm[1], $wm)) {
                    // a literal white (solid or translucent) written by a generic rule: on the dark band it is on_dark
                    // (alpha kept, floored at 62%), on a brand surface it is what reads there
                    $rules[$sel] = ['var' => trim($wm[1]), 'role' => 'white', 'alpha' => isset($wm[2]) && $wm[2] !== '' ? (float) $wm[2] : 1.0];
                }
            }
        }
        if ($rules === []) return '';
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_use_internal_errors($prev);
        if (! $ok) return '';
        $xp = new \DOMXPath($doc);
        // one compound (`.card`, `div.card.big`, `footer`) against one element
        $matchesCompound = function (\DOMElement $el, string $compound): bool {
            if (! preg_match('/^([a-zA-Z][\w-]*)?((?:\.[a-zA-Z][\w-]*)*)$/', $compound, $pm)) return false;
            if ($pm[1] !== '' && strtolower($el->tagName) !== strtolower($pm[1])) return false;
            $have = preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [];
            foreach (array_filter(explode('.', $pm[2])) as $cls) { if (! in_array($cls, $have, true)) return false; }
            return true;
        };
        // a descendant selector (`.ct-panel .card`) against an element: last compound on it, the rest up the tree
        $matches = function (\DOMElement $el, string $sel) use ($matchesCompound): bool {
            $parts = array_reverse(preg_split('/\s+/', trim($sel)) ?: []);
            if ($parts === [] || ! $matchesCompound($el, $parts[0])) return false;
            $n = $el->parentNode;
            for ($i = 1; $i < count($parts); $i++) {
                for (; $n instanceof \DOMElement && ! $matchesCompound($n, $parts[$i]); $n = $n->parentNode);
                if (! $n instanceof \DOMElement) return false;
                $n = $n->parentNode;
            }
            return true;
        };
        // the closest ancestor that any painted (or light) container selector matches
        $nearest = function (\DOMElement $el) use ($containers, $matches): ?array {
            for ($n = $el->parentNode; $n instanceof \DOMElement; $n = $n->parentNode) {
                $best = null;
                foreach ($containers as $sel => $role) { if ($matches($n, $sel) && ($best === null || strlen($sel) > strlen($best[0]))) $best = [$sel, $role]; }
                if ($best !== null) return $best;
            }
            return null;
        };
        $out = [];
        foreach ($rules as $sel => $info) {
            $xpath = self::selectorToXPath($sel);
            if ($xpath === null) continue;
            $nodes = @$xp->query($xpath);
            if (! $nodes) continue;
            $seen = [];
            foreach ($nodes as $el) {
                if (! $el instanceof \DOMElement) continue;
                $near = $nearest($el);
                if ($near === null || $near[1] === 'light' || isset($seen[$near[0]])) continue;
                $seen[$near[0]] = true;
                [$cSel, $role] = $near;
                $orig = $info['var'];
                // on the dark band: the page text becomes on_dark, a brand colour its lifted twin; on a brand surface
                // everything becomes what reads on that surface
                $val = match (true) {
                    $info['role'] === 'white' && $role === 'dark' && $info['alpha'] < 1 => 'rgba(var(--lu-on-dark-rgb, 255,255,255), ' . ($info['alpha'] < 0.62 && $info['alpha'] >= 0.3 ? '.62' : rtrim(rtrim(number_format($info['alpha'], 2, '.', ''), '0'), '.')) . ')',
                    $info['role'] === 'white' => $role === 'dark' ? 'var(--lu-on-dark, ' . $orig . ')' : 'var(--lu-on-' . $role . ', ' . $orig . ')',
                    $role !== 'dark' => 'var(--lu-on-' . $role . ', ' . $orig . ')',
                    $info['role'] === 'text' => 'var(--lu-on-dark, ' . $orig . ')',
                    $info['role'] === 'muted' || $info['role'] === 'line' => 'rgba(var(--lu-on-dark-rgb, 255,255,255), .72)',
                    default => 'var(--lu-' . $info['role'] . '-on-dark, ' . $orig . ')',
                };
                $tag = $role === 'dark' ? (in_array($info['role'], ['text', 'muted', 'line', 'white'], true) ? 'on_dark' : $info['role'] . '_on_dark') : 'on_' . $role;
                $changes[$tag] = ($changes[$tag] ?? 0) + 1;
                $out[] = $cSel . ' ' . $sel . '{color:' . $val . '}';
                $seen[$cSel] = true;
            }
        }
        if ($out === []) return '';
        return "\n/* lug-scope */\n" . implode("\n", $out) . "\n/* /lug-scope */\n";
    }

    /** `.a .b`, `tag`, `.a tag`, `.a.b` (descendant combinators only) → XPath; anything else is not attempted. */
    private static function selectorToXPath(string $sel): ?string
    {
        $parts = preg_split('/\s+/', trim($sel)) ?: [];
        $xp = '';
        foreach ($parts as $part) {
            if (! preg_match('/^([a-zA-Z][\w-]*)?((?:\.[a-zA-Z][\w-]*)*)$/', $part, $pm) || $part === '') return null;
            $tag = $pm[1] !== '' ? strtolower($pm[1]) : '*';
            $conds = [];
            foreach (array_filter(explode('.', $pm[2])) as $cls) $conds[] = "contains(concat(' ', normalize-space(@class), ' '), ' " . $cls . " ')";
            $xp .= '//' . $tag . ($conds ? '[' . implode(' and ', $conds) . ']' : '');
        }
        return $xp === '' ? null : $xp;
    }

    /** Does this rule paint a section or a card rather than a control? Vertical padding of 24px / 1.5rem or more says so. */
    private static function paintsABlock(string $decls): bool
    {
        if (! preg_match_all('/(?<![-\w])padding(?:-top|-bottom|-block)?\s*:\s*([^;]+)/i', $decls, $pm)) return false;
        $max = 0.0;
        foreach ($pm[1] as $val) {
            // the vertical padding is the tell (a button is 12–16px tall in padding, wide in the other axis)
            if (preg_match('/(\d*\.?\d+)\s*(px|rem|em|vw|vh)/i', $val, $n)) {
                $v = (float) $n[1] * (in_array(strtolower($n[2]), ['rem', 'em'], true) ? 16 : (strtolower($n[2]) === 'px' ? 1 : 8)); $max = max($max, $v);
            }
        }
        return $max >= 24;
    }

    /** A selector as written, without the comment or at-rule header that precedes it in the source. */
    private static function cleanSelector(string $sel): string
    {
        $sel = (string) preg_replace('~/\*.*?\*/~s', '', $sel);
        // a media/supports header leaves its at-rule text in front of the first inner selector
        $sel = (string) preg_replace('/^\s*@[^{}]*?\)\s*/', '', $sel);
        return trim($sel);
    }

    /** The surface a selector's element sits on, when every selector in its list descends from one painted section. */
    private static function contextFor(string $selector): ?string
    {
        if (self::$ctx === []) return null;
        $found = null;
        foreach (explode(',', self::cleanSelector($selector)) as $s) {
            $s = trim((string) preg_replace('/\s+/', ' ', $s)); if ($s === '') continue;
            $best = null; $bestLen = 0;
            foreach (self::$ctx as $c => $role) {
                $n = strlen($c);
                if ($n <= $bestLen || ! str_starts_with($s, $c)) continue;
                $next = $s[$n] ?? '';
                if ($next === ' ' || $next === '>' || $next === ':') { $best = $role; $bestLen = $n; }
            }
            if ($best === null && preg_match('/^\.([a-zA-Z][a-zA-Z0-9]{2,})[-_]/', $s, $sm)) $best = self::$ctx['~.' . strtolower($sm[1])] ?? null;
            if ($best === null || $best === 'light') return null;
            if ($found !== null && $found !== $best) return null;
            $found = $best;
        }
        return $found;
    }

    private static function roleOfBackground(string $val): ?string
    {
        // the design's own variables first (`--medical` is this design's accent, `--carbon` its dark)
        if (preg_match_all('/var\(\s*(--[a-zA-Z0-9-]+)\s*[,)]/', $val, $vm)) {
            foreach ($vm[1] as $name) { $r = self::$varRole[strtolower($name)] ?? null; if (in_array($r, ['primary', 'secondary', 'accent', 'dark'], true)) return $r; }
        }
        if (preg_match('/var\(\s*--(brand-2|secondary[a-z-]*|lu-secondary)\s*[,)]/i', $val)) return 'secondary';
        if (preg_match('/var\(\s*--(accent[a-z-]*|lu-accent)\s*[,)]/i', $val)) return 'accent';
        if (preg_match('/var\(\s*--(brand|brand-deep|primary[a-z-]*|lu-primary[a-z-]*)\s*[,)]/i', $val)) return 'primary';
        if (preg_match('/var\(\s*--(deep|lu-dark|carbon|steel|navy|charcoal|void|midnight|graphite|espresso)[a-z-]*\s*[,)]/i', $val)) return 'dark';
        if (preg_match('/^\s*(#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}))\b/', $val, $lm) && self::lightnessOf($lm[1]) < 0.3) return 'dark';
        return null;
    }

    /** What a literal becomes, given how it is used. null = leave it. */
    public static function roleFor(string $lit, string $cls, ?string $onRole, array $brandDefaults): ?string
    {
        $l = strtolower($lit);
        $darkGround = self::$scheme === 'dark';
        // white-ish
        if ($l === 'white' || $l === '#fff' || $l === '#ffffff' || preg_match('/^rgba\(\s*255\s*,\s*255\s*,\s*255/', $l)) {
            if ($cls === 'text' || $cls === 'fill') {
                return match ($onRole) { 'secondary' => 'on_secondary', 'accent' => 'on_accent', 'primary' => 'on_primary', 'dark' => 'on_dark', default => null };
            }
            // a white card on a dark design is a deliberate light island — the page ground there is dark
            if ($cls === 'bg' && ! $darkGround && ($l === '#fff' || $l === '#ffffff' || $l === 'white')) return 'bg';
            return null;
        }
        if ($l === 'black' || $l === '#000' || $l === '#000000') {
            if ($darkGround) return null;
            return $cls === 'text' ? 'text' : ($cls === 'bg' ? 'dark' : null);
        }
        if (! preg_match('/^#/', $l)) return null;
        $hex = PaletteRoles::norm($l); if ($hex === null) return null;
        // a literal that IS one of the template's brand defaults: the brand role itself
        foreach ($brandDefaults as $role => $def) {
            if (is_string($def) && strcasecmp(PaletteRoles::norm($def) ?? '', $hex) === 0) {
                if ($cls === 'text') return in_array($role, ['primary', 'secondary', 'accent'], true) ? $role . '_text' : $role;
                return $role;
            }
        }
        [$L, $S, $H] = self::lsh($hex);
        // chroma, not saturation: a dark slate (#0F172A) or a near-white (#F7FAFC) is a neutral however "saturated" its hue reads
        $chroma = $S * (1 - abs(2 * $L - 1));
        $deg = $H * 360;
        // a coloured red is an error, a coloured green is success — semantic, untouched, however dark
        if ($S >= 0.35 && $chroma >= 0.12 && ($deg >= 335 || $deg <= 20 || ($deg >= 80 && $deg <= 165))) return null;
        if ($chroma >= 0.25 && $L > 0.12 && $L < 0.92) {
            if ($cls === 'text' || $cls === 'fill') return 'accent_text';
            if ($cls === 'bg' || $cls === 'gradient') return $L < 0.6 ? 'accent' : 'accent_soft';
            if ($cls === 'border') return 'accent';
            return null;
        }
        // neutrals — on a dark design the hard-coded neutrals are its light islands and their text; left as designed
        // (its own variables carry the palette through the manifest roles)
        if ($darkGround) return null;
        if ($L < 0.25) {
            return match ($cls) { 'text', 'fill' => 'text', 'bg', 'gradient' => 'dark', 'border' => 'line', default => null };
        }
        if ($L < 0.62) {
            return match ($cls) { 'text', 'fill' => 'muted', 'bg', 'gradient' => 'surface2', 'border' => 'line', default => null };
        }
        return match ($cls) { 'text', 'fill' => 'on_dark', 'bg', 'gradient' => ($L >= 0.955 ? 'bg' : 'surface'), 'border' => 'line', default => null };
    }

    private static function lightnessOf(string $hex): float { return PaletteRoles::lightness($hex); }

    /** @return array{0:float,1:float,2:float} lightness, saturation, hue(0..1) */
    private static function lsh(string $hex): array
    {
        $h = ltrim($hex, '#');
        $r = hexdec(substr($h, 0, 2)) / 255; $g = hexdec(substr($h, 2, 2)) / 255; $b = hexdec(substr($h, 4, 2)) / 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2;
        if ($max === $min) return [$l, 0.0, 0.0];
        $d = $max - $min; $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $hue = match ($max) { $r => (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6, $g => (($b - $r) / $d + 2) / 6, default => (($r - $g) / $d + 4) / 6 };
        return [$l, $s, $hue];
    }
}
