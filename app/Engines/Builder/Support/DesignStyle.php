<?php

namespace App\Engines\Builder\Support;

/**
 * DESIGN-STYLE LAYER (2026-09-05) — closes the "Arthur asks for a style but never
 * applies it" gap. Arthur's discovery collects `style` (modern|luxury|minimal|classic)
 * and, now, optional `fonts` (display/body). This class turns that direction into a
 * concrete typography + shape token set and emits a <link>+<style> layer that is
 * injected into every rendered page. It overrides each template's hardcoded fonts
 * only when the customer expressed a direction — with no direction, the template's
 * own design stands untouched (opt-in, zero regression).
 *
 * Fonts are loaded from Google Fonts. Explicit customer fonts win over the preset.
 */
final class DesignStyle
{
    public const STYLES = ['modern', 'luxury', 'minimal', 'classic', 'playful'];

    /** Preset → tokens. Curated pairings, not arbitrary. */
    private const PRESETS = [
        // playful = "bubbly / colorful / fun": rounded display face, big radii, bold friendly weights
        'playful' => ['display' => 'Fredoka',            'dw' => '500;600;700', 'body' => 'Nunito',        'bw' => '400;600;700',     'radius' => '24px', 'eyebrow_ls' => '.1em',  'display_weight' => '700', 'display_transform' => 'none'],
        'luxury'  => ['display' => 'Cormorant Garamond', 'dw' => '500;600;700', 'body' => 'Inter',         'bw' => '300;400;500;600', 'radius' => '4px',  'eyebrow_ls' => '.2em',  'display_weight' => '600', 'display_transform' => 'none'],
        'modern'  => ['display' => 'Space Grotesk',      'dw' => '500;600;700', 'body' => 'Inter',         'bw' => '400;500;600',     'radius' => '12px', 'eyebrow_ls' => '.12em', 'display_weight' => '700', 'display_transform' => 'none'],
        'minimal' => ['display' => 'Inter',              'dw' => '400;500;600', 'body' => 'Inter',         'bw' => '300;400;500',     'radius' => '8px',  'eyebrow_ls' => '.16em', 'display_weight' => '500', 'display_transform' => 'none'],
        'classic' => ['display' => 'Libre Baskerville',  'dw' => '400;700',     'body' => 'Source Sans 3', 'bw' => '400;600',         'radius' => '6px',  'eyebrow_ls' => '.14em', 'display_weight' => '700', 'display_transform' => 'none'],
    ];

    /** Mood words a customer might use → preset. */
    private const MOOD_TO_STYLE = [
        'bubbly' => 'playful', 'colorful' => 'playful', 'colourful' => 'playful', 'playful' => 'playful', 'fun' => 'playful', 'vibrant' => 'playful', 'cheerful' => 'playful', 'friendly' => 'playful', 'quirky' => 'playful', 'kid' => 'playful', 'family' => 'playful', 'bright' => 'playful', 'lively' => 'playful', 'whimsical' => 'playful',
        'luxury' => 'luxury', 'luxurious' => 'luxury', 'elegant' => 'luxury', 'premium' => 'luxury', 'high-end' => 'luxury', 'upscale' => 'luxury', 'sophisticated' => 'luxury', 'refined' => 'luxury',
        'modern' => 'modern', 'contemporary' => 'modern', 'bold' => 'modern', 'powerful' => 'modern', 'strong' => 'modern', 'dynamic' => 'modern', 'energetic' => 'modern', 'edgy' => 'modern', 'tech' => 'modern', 'geometric' => 'modern', 'sleek' => 'modern', 'futuristic' => 'modern',
        'minimal' => 'minimal', 'minimalist' => 'minimal', 'clean' => 'minimal', 'simple' => 'minimal', 'airy' => 'minimal', 'light' => 'minimal', 'scandinavian' => 'minimal',
        'classic' => 'classic', 'traditional' => 'classic', 'timeless' => 'classic', 'heritage' => 'classic', 'conventional' => 'classic', 'formal' => 'classic',
    ];

    /** Normalise a free-text style/mood to a preset key, or null. */
    public static function normaliseStyle(?string $raw): ?string
    {
        $s = strtolower(trim((string) $raw));
        if ($s === '') return null;
        if (in_array($s, self::STYLES, true)) return $s;
        foreach (self::MOOD_TO_STYLE as $word => $style) {
            if (str_contains($s, $word)) return $style;
        }
        return null;
    }

    /**
     * Pull an explicit font request out of free text, e.g. "use Poppins", "Montserrat for
     * headings", "body in Lato". Returns ['display'=>?, 'body'=>?] (either may be null).
     */
    public static function parseFonts(string $text): array
    {
        $out = ['display' => null, 'body' => null];
        if ($text === '') return $out;
        // "<Font> for headings/titles", "headings in <Font>"
        if (preg_match('/\b([A-Z][A-Za-z]+(?:\s[A-Z][A-Za-z]+)?)\s+(?:for|as)\s+(?:the\s+)?(?:headings?|titles?|display)\b/', $text, $m)) $out['display'] = trim($m[1]);
        if (preg_match('/\b(?:headings?|titles?|display)\s+(?:in|with|using)\s+([A-Z][A-Za-z]+(?:\s[A-Z][A-Za-z]+)?)\b/', $text, $m)) $out['display'] = trim($m[1]);
        if (preg_match('/\b([A-Z][A-Za-z]+(?:\s[A-Z][A-Za-z]+)?)\s+(?:for|as)\s+(?:the\s+)?(?:body|paragraphs?|text)\b/', $text, $m)) $out['body'] = trim($m[1]);
        if (preg_match('/\b(?:body|paragraphs?|text)\s+(?:in|with|using)\s+([A-Z][A-Za-z]+(?:\s[A-Z][A-Za-z]+)?)\b/', $text, $m)) $out['body'] = trim($m[1]);
        // generic "use <Font>" / "font <Font>" / "in <Font> font" → display (and body if none)
        if (!$out['display'] && preg_match('/\b(?:use|using|with|in|font(?:\s+of)?|typeface)\s+(?:the\s+)?([A-Z][A-Za-z]+(?:\s[A-Z][A-Za-z]+)?)(?:\s+font|\s+typeface)?\b/', $text, $m)) {
            $cand = trim($m[1]);
            // reject obvious non-fonts caught by the loose pattern
            if (!preg_match('/^(The|And|For|With|Dubai|UAE|Modern|Luxury|Minimal|Classic|Our|Your|Please|Something|Business|Website)$/i', $cand)) $out['display'] = $cand;
        }
        return $out;
    }

    /** Resolve final tokens from style + explicit fonts. Returns null if no direction given. */
    public static function resolve(?string $style, ?string $fontDisplay, ?string $fontBody): ?array
    {
        $style = self::normaliseStyle($style);
        $fd = self::cleanFont($fontDisplay);
        $fb = self::cleanFont($fontBody);
        if (!$style && !$fd && !$fb) return null;
        $p = self::PRESETS[$style ?? 'modern'];
        if ($fd) { $p['display'] = $fd; $p['dw'] = '400;500;600;700'; }
        if ($fb) { $p['body'] = $fb;    $p['bw'] = '300;400;500;600'; }
        elseif ($fd && !$style) { $p['body'] = 'Inter'; }
        $p['style'] = $style ?? 'custom';
        return $p;
    }

    private static function cleanFont(?string $f): ?string
    {
        $f = trim((string) $f, " \t\n\r\0\x0B'\"");
        if ($f === '' || strlen($f) > 40 || !preg_match('/^[A-Za-z0-9 +\-]+$/', $f)) return null;
        return $f;
    }

    /** The injectable layer: Google Fonts link + tokenised overrides. '' when no direction. */
    public static function layer(?string $style, ?string $fontDisplay, ?string $fontBody, array $colors = []): string
    {
        $t = self::resolve($style, $fontDisplay, $fontBody);
        if (!$t) return '';
        // FONTS-7 (2026-10-01): fonts named without a style change the faces only — the design keeps its own shapes
        return self::layerFromTokens($t, $colors, self::normaliseStyle($style) === null && FontPairs::on());
    }

    /** A preset's token set (modern when unknown). */
    public static function preset(?string $style): array
    {
        return self::PRESETS[$style] ?? self::PRESETS['modern'];
    }

    /** The heading and body selector lists every font layer paints (FONTS-8 reads them too). */
    public const HEAD_SEL = 'h1,h2,h3,h4,.hero-title,.hero-name,.h-section,.section-title,.card-title,.logo,.brand,.stat-value,.price,.plan-name';
    public const BODY_SEL = 'body,p,li,dd,dt,td,th,label,a,button,input,select,textarea,.section-intro,.hero-subtitle,.hero-body,.card-text,.nav a,nav a,.eyebrow,.hero-eyebrow,.stat-label,.btn,.btn-primary,.nav-cta,.hero-cta';

    /**
     * The layer for a resolved token set; $fontsOnly = the two font rules and nothing that touches shapes or colour.
     * $tokenVars (FONTS-8, 2026-10-06): also repoint the designs' own font tokens (v3 --display/--body, v1/v2 --fh/--fb/--fd),
     * so every rule that reads them takes the new faces, not only the selectors listed above. Off by default: builds unchanged.
     */
    public static function layerFromTokens(array $t, array $colors = [], bool $fontsOnly = false, bool $tokenVars = false): string
    {
        $gf = 'https://fonts.googleapis.com/css2?family=' . str_replace(' ', '+', $t['display']) . ':wght@' . $t['dw']
            . '&family=' . str_replace(' ', '+', $t['body']) . ':wght@' . $t['bw'] . '&display=swap';
        $d = htmlspecialchars($t['display'], ENT_QUOTES);
        $b = htmlspecialchars($t['body'], ENT_QUOTES);
        return "\n<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\"><link rel=\"stylesheet\" href=\"" . htmlspecialchars($gf, ENT_QUOTES) . "\">\n"
            . "<style id=\"lug-design-style\" data-style=\"" . htmlspecialchars($t['style'], ENT_QUOTES) . "\">\n"
            . ":root{--ds-font-display:'{$d}';--ds-font-body:'{$b}';--ds-radius:{$t['radius']};--ds-eyebrow-ls:{$t['eyebrow_ls']};--ds-display-weight:{$t['display_weight']}}\n"
            . ($tokenVars ? "html:root{--display:'{$d}',Georgia,serif;--fh:'{$d}',Georgia,serif;--fd:'{$d}',Georgia,serif;--body:'{$b}',system-ui,sans-serif;--fb:'{$b}',system-ui,sans-serif}\n" : '')
            . self::HEAD_SEL . "{font-family:var(--ds-font-display),Georgia,serif!important;font-weight:var(--ds-display-weight)!important;letter-spacing:-0.01em}\n"
            . self::BODY_SEL . "{font-family:var(--ds-font-body),system-ui,sans-serif!important}\n"
            . ($fontsOnly ? '' : self::shapeCss($t, $colors))
            . "</style>\n";
    }

    /** A preset's shapes and colour treatment: eyebrows, radii and the treatment (no fonts). */
    public static function shapeCss(array $t, array $colors = []): string
    {
        return ".eyebrow,.hero-eyebrow,[class*='eyebrow'],.stat-label,.section-head span:first-child{letter-spacing:var(--ds-eyebrow-ls)!important;text-transform:uppercase}\n"
            . ".btn,.btn-primary,.btn-secondary,.nav-cta,.hero-cta,.cta,button,.card,.feature,.service,.plan,input,select,textarea,.form-input,.post-card,.blog-card,.testimonial{border-radius:var(--ds-radius)!important}\n"
            . self::treatment($t['style'], $colors);
    }

    // ── COLOUR TREATMENT (2026-09-06) ────────────────────────────────────────────────────────────────────
    // The Boss's ask: "use some creative gradients and patterns on some sections and backgrounds", and for
    // "bubbly and colorful" a site that IS colourful, not one accent colour. Everything here is background-IMAGE
    // or border/button paint on top of the template: no section background-colour is replaced, so a dark booking
    // band stays dark and readable. Palette = brand accent + secondary + two analogous hues, with pastel tints.

    /** @return array{c:array<int,string>,t:array<int,string>,rgb:array<int,string>} four hues, four tints, four "r,g,b" strings */
    public static function palette(array $colors): array
    {
        $norm = function ($v): ?string { $v = trim((string) $v); return preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtoupper($v) : null; };
        $c1 = $norm($colors['accent'] ?? null) ?? $norm($colors['primary'] ?? null) ?? '#6C5CE7';
        [$h, $s, $l] = \App\Engines\Builder\Support\ColorTheme::hexToHsl($c1);
        $s = max($s, 0.45);
        $c2 = $norm($colors['secondary'] ?? null) ?? \App\Engines\Builder\Support\ColorTheme::hslToHex($h + 150, $s, min(max($l, 0.42), 0.58));
        $c3 = \App\Engines\Builder\Support\ColorTheme::hslToHex($h + 40, $s, min(max($l, 0.45), 0.6));
        $c4 = \App\Engines\Builder\Support\ColorTheme::hslToHex($h - 45, $s, min(max($l, 0.45), 0.6));
        $cs = [$c1, $c2, $c3, $c4]; $tints = []; $rgb = [];
        foreach ($cs as $hex) {
            [$hh, $ss, $ll] = \App\Engines\Builder\Support\ColorTheme::hexToHsl($hex);
            $tints[] = \App\Engines\Builder\Support\ColorTheme::hslToHex($hh, min(1, $ss * 0.9), 0.94);
            $rgb[]   = implode(',', \App\Engines\Builder\Support\ColorTheme::hexToRgb($hex));
        }
        return ['c' => $cs, 't' => $tints, 'rgb' => $rgb];
    }

    /** CSS for the treatment of a resolved style ('' for minimal / custom-font-only). */
    public static function treatment(?string $style, array $colors): string
    {
        if (!in_array($style, ['playful', 'modern', 'luxury', 'classic'], true)) return '';
        $p = self::palette($colors); [$c1, $c2, $c3, $c4] = $p['c']; [$t1, $t2, $t3, $t4] = $p['t']; [$r1, $r2, $r3, $r4] = $p['rgb'];
        $sec = 'body > section:not(.hero):not(footer), main > section:not(.hero):not(footer)';
        $cards = '.card,.service,.feature,.plan,.testimonial,.post-card,.blog-card,.team-card,.stat,.faq-item,.amenity,.room,.program,.package';
        $icons = '.service-icon,.feature-icon,.icon,.card-icon,.step-num,.step-number';
        $btns  = '.btn-primary,.hero-cta,.nav-cta,.btn.primary,button[type=submit],.cta-btn,.btn-cta';
        // A comma list with a pseudo-class only on the last selector (".card,.service:nth-child(2)") leaves the others bare:
        // every card took the LAST hue and the card body took the icon tint (seen on 610). Expand per selector.
        $each = fn(string $list, string $suffix) => implode(',', array_map(fn($s) => trim($s) . $suffix, explode(',', $list)));
        $inside = function (string $list, string $suffix, string $inner): string { $out = []; foreach (explode(',', $list) as $s) { foreach (explode(',', $inner) as $i) { $out[] = trim($s) . $suffix . ' ' . trim($i); } } return implode(',', $out); };
        $css = "\n/* colour treatment: {$style} */\n:root{--cf1:{$c1};--cf2:{$c2};--cf3:{$c3};--cf4:{$c4};--cf1t:{$t1};--cf2t:{$t2};--cf3t:{$t3};--cf4t:{$t4}}\n";
        switch ($style) {
            case 'playful':
                // BUBBLES: translucent circles in four brand hues, a different constellation on every third section.
                $css .= "{$sec}{position:relative;background-repeat:no-repeat}\n"
                    . "{$sec}:nth-of-type(3n+1){background-image:radial-gradient(circle at 6% 18%,rgba({$r1},.16) 0 90px,transparent 91px),radial-gradient(circle at 94% 78%,rgba({$r2},.14) 0 140px,transparent 141px),radial-gradient(circle at 72% 8%,rgba({$r3},.13) 0 46px,transparent 47px)}\n"
                    . "{$sec}:nth-of-type(3n+2){background-image:radial-gradient(circle at 92% 14%,rgba({$r4},.15) 0 110px,transparent 111px),radial-gradient(circle at 10% 86%,rgba({$r1},.13) 0 70px,transparent 71px),radial-gradient(circle at 40% 96%,rgba({$r2},.12) 0 38px,transparent 39px)}\n"
                    . "{$sec}:nth-of-type(3n+3){background-image:radial-gradient(circle at 14% 10%,rgba({$r2},.14) 0 120px,transparent 121px),radial-gradient(circle at 86% 88%,rgba({$r3},.15) 0 95px,transparent 96px),radial-gradient(circle at 60% 20%,rgba({$r4},.11) 0 40px,transparent 41px)}\n"
                    // CARDS: each card gets its own hue (top bar + tinted icon), cycling through four colours.
                    . "{$cards}{border-top:5px solid var(--cf1)!important}\n"
                    . $each($cards, ':nth-child(4n+2)') . "{border-top-color:var(--cf2)!important}" . $each($cards, ':nth-child(4n+3)') . "{border-top-color:var(--cf3)!important}" . $each($cards, ':nth-child(4n+4)') . "{border-top-color:var(--cf4)!important}\n"
                    . $inside($cards, '', $icons) . "{background:var(--cf1t)!important;color:var(--cf1)!important;border-radius:16px!important}\n"
                    . $inside($cards, ':nth-child(4n+2)', $icons) . "{background:var(--cf2t)!important;color:var(--cf2)!important}" . $inside($cards, ':nth-child(4n+3)', $icons) . "{background:var(--cf3t)!important;color:var(--cf3)!important}" . $inside($cards, ':nth-child(4n+4)', $icons) . "{background:var(--cf4t)!important;color:var(--cf4)!important}\n"
                    // BUTTONS: two-hue gradient with a coloured glow; eyebrows take the secondary hue.
                    . "{$btns}{background-image:linear-gradient(135deg,var(--cf1),var(--cf2))!important;background-color:var(--cf1)!important;border-color:transparent!important;color:#fff!important;box-shadow:0 10px 24px rgba({$r1},.28)!important}\n"
                    . "section:not(.hero) .eyebrow,section:not(.hero) [class*='eyebrow']{color:var(--cf2)!important}\n"
                    // HERO: a soft two-hue tint over the photo overlay so the opening is warm, not grey.
                    . ".hero{background-blend-mode:normal}.hero::after{content:'';position:absolute;inset:0;pointer-events:none;background:linear-gradient(135deg,rgba({$r1},.16),rgba({$r2},.12));z-index:0}.hero>*{position:relative;z-index:1}\n";
                break;
            case 'modern':
                // Dot grid on odd sections, a diagonal gradient wash on every third, gradient buttons.
                $css .= "{$sec}:nth-of-type(odd){background-image:radial-gradient(rgba({$r1},.13) 1px,transparent 1.6px);background-size:22px 22px}\n"
                    . "{$sec}:nth-of-type(3n){background-image:linear-gradient(135deg,rgba({$r1},.08),transparent 55%),radial-gradient(rgba({$r1},.10) 1px,transparent 1.6px);background-size:auto,22px 22px}\n"
                    . "{$btns}{background-image:linear-gradient(135deg,var(--cf1),var(--cf3))!important;border-color:transparent!important;color:#fff!important}\n";
                break;
            case 'luxury':
                // Quiet radial glow of the secondary (gold) at the top of even sections; gradient eyebrows.
                $css .= "{$sec}:nth-of-type(even){background-image:radial-gradient(ellipse at 50% 0%,rgba({$r2},.12),transparent 60%)}\n"
                    . "{$sec}:nth-of-type(4n+1){background-image:linear-gradient(180deg,transparent,rgba({$r1},.05))}\n"
                    . ".eyebrow,.hero-eyebrow,[class*='eyebrow']{background:linear-gradient(90deg,var(--cf2),var(--cf1));-webkit-background-clip:text;background-clip:text;color:transparent!important}\n";
                break;
            case 'classic':
                // Fine linen texture on odd sections, a warm tint band on every fourth.
                $css .= "{$sec}:nth-of-type(odd){background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.028) 0 1px,transparent 1px 4px)}\n"
                    . "{$sec}:nth-of-type(4n){background-image:linear-gradient(180deg,rgba({$r2},.10),transparent 40%)}\n";
                break;
        }
        return $css;
    }
}
