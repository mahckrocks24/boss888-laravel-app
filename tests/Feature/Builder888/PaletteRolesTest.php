<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\ColorTheme;
use App\Engines\Builder\Support\PaletteNormalizer;
use App\Engines\Builder\Support\PaletteRoles;
use Tests\TestCase;

/**
 * Owner, 2026-09-18 — "all templates fully optimized for color palette changes … as an entire website … proper
 * contrast a priority". The palette is a role set with contrast guarantees; every theme, both schemes, every
 * template's manifest, and the literal rewrite are pinned here.
 */
class PaletteRolesTest extends TestCase
{
    public function test_every_theme_derives_a_role_set_that_keeps_its_contrast_promises_in_both_schemes(): void
    {
        foreach (ColorTheme::all() as $t) {
            foreach (['light', 'dark'] as $scheme) {
                $r = PaletteRoles::derive($t, $scheme);
                $who = $t['id'] . '/' . $scheme;
                foreach (PaletteRoles::ROLES as $role) $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $r[$role], "$who $role");
                $c = fn ($a, $b) => ColorTheme::contrast($r[$a], $r[$b]);
                $this->assertGreaterThanOrEqual(7.0, $c('text', 'bg'), "$who text on bg");
                $this->assertGreaterThanOrEqual(4.5, $c('muted', 'bg'), "$who muted on bg");
                $this->assertGreaterThanOrEqual(4.5, $c('muted', 'surface'), "$who muted on surface");
                $this->assertGreaterThanOrEqual(7.0, $c('on_dark', 'dark'), "$who on_dark on dark");
                $this->assertGreaterThanOrEqual(4.5, $c('on_primary', 'primary'), "$who on_primary");
                $this->assertGreaterThanOrEqual(4.5, $c('on_secondary', 'secondary'), "$who on_secondary");
                $this->assertGreaterThanOrEqual(4.5, $c('on_accent', 'accent'), "$who on_accent");
                $this->assertGreaterThanOrEqual(4.5, $c('accent_text', 'bg'), "$who accent as text");
                $this->assertGreaterThanOrEqual(4.5, $c('secondary_text', 'bg'), "$who secondary as text");
                $this->assertGreaterThanOrEqual(4.5, $c('primary_text', 'bg'), "$who primary as text");
                // brand text is also read on cards and inside the soft tints, and lifted on the dark band
                $this->assertGreaterThanOrEqual(4.5, $c('accent_text', 'surface2'), "$who accent as text on a card");
                $this->assertGreaterThanOrEqual(4.5, $c('accent_text', 'accent_soft'), "$who accent as text in its tint");
                $this->assertGreaterThanOrEqual(4.5, $c('secondary_text', 'secondary_soft'), "$who secondary as text in its tint");
                $this->assertGreaterThanOrEqual(4.5, $c('accent_on_dark', 'dark'), "$who accent on the dark band");
                $this->assertGreaterThanOrEqual(4.5, $c('secondary_on_dark', 'dark'), "$who secondary on the dark band");
                $this->assertGreaterThanOrEqual(4.5, $c('primary_on_dark', 'dark'), "$who primary on the dark band");
                $this->assertGreaterThanOrEqual(4.5, $c('muted', 'surface2'), "$who muted on a card");
                // the ground really is what the scheme says
                $this->assertTrue($scheme === 'dark' ? PaletteRoles::lightness($r['bg']) < 0.2 : PaletteRoles::lightness($r['bg']) > 0.85, "$who ground");
            }
        }
    }

    public function test_every_industry_manifest_maps_its_colour_variables_to_roles_and_the_generated_designs_share_the_neutral_vocabulary(): void
    {
        $genA = 0; $genB = 0;
        foreach (glob(storage_path('templates/*/manifest.json')) as $mf) {
            $m = json_decode((string) file_get_contents($mf), true);
            $slug = basename(dirname($mf));
            $cols = array_filter($m['variables'] ?? [], fn ($d) => is_array($d) && ($d['type'] ?? '') === 'color');
            $neutral = array_diff(array_keys($cols), ['primary_color', 'primary_deep', 'secondary_color', 'accent_color']);
            $tpl = (string) file_get_contents(dirname($mf) . '/template.html');
            if ($neutral === []) {
                $genB++;
                foreach (array_keys(PaletteRoles::GEN_B_NEUTRALS) as $v) $this->assertStringContainsString($v . ':', $tpl, "$slug declares $v");
                continue;
            }
            $genA++;
            $this->assertContains($m['palette_scheme'] ?? null, ['light', 'dark'], "$slug palette_scheme");
            $roles = $m['palette_roles'] ?? [];
            foreach ($neutral as $var) $this->assertArrayHasKey($var, $roles, "$slug maps $var");
            foreach ($roles as $var => $role) $this->assertTrue($role === 'keep' || in_array($role, PaletteRoles::ROLES, true), "$slug $var → $role is a role");
            $this->assertContains('bg', array_values($roles), "$slug names its ground");
            $this->assertTrue(in_array('text', array_values($roles), true) || in_array('primary_text', array_values($roles), true) || (bool) preg_match('/--ink\s*:\s*#/', $tpl), "$slug names its text (a role, the primary as readable text, or a hard-coded --ink the block re-points)");
        }
        $this->assertSame(32, $genA); $this->assertSame(62, $genB);
    }

    public function test_the_painter_fills_neutrals_from_roles_and_the_render_carries_the_roles_block(): void
    {
        $manifest = json_decode((string) file_get_contents(storage_path('templates/construction/manifest.json')), true);
        $theme = ColorTheme::find('coral_reef');
        $roles = PaletteRoles::derive($theme, $manifest['palette_scheme']);
        $vars = [];
        foreach ($manifest['variables'] as $k => $d) $vars[$k] = $d['default'] ?? '';
        $written = PaletteRoles::paintManifestVars($vars, $manifest, $roles);
        $this->assertSame($roles['bg'], $vars['steel'], 'the dark design\'s ground follows the palette');
        $this->assertSame($roles['text'], $vars['chalk']);
        $this->assertSame($roles['line'], $vars['divider']);
        $this->assertArrayNotHasKey('orange', $written, 'the accent stays the brand painter\'s');
        // a fresh Arthur build has no named theme: the painter must never leave a NULL variable behind
        // (BuilderGenerationDTO refuses it and every new build 422'd — caught by the widget journey, 2026-09-19)
        $svc = app(\App\Engines\Builder\Services\ArthurService::class);
        $m = new \ReflectionMethod($svc, 'applyBrandColors'); $m->setAccessible(true);
        $fresh = $vars; unset($fresh['palette_bg'], $fresh['palette_text']);
        $m->invokeArgs($svc, [&$fresh, $manifest, ['primary' => '#1F5F8B', 'secondary' => '#5AA9E6', 'accent' => '#F2A541']]);
        foreach ($fresh as $k => $v) $this->assertIsString($v, "variable $k must be a string after the brand painter, got " . gettype($v));

        $html = app(\App\Engines\Builder\Services\TemplateService::class)->render('cafe_arch', ['business_name' => 'T', 'primary_color' => $theme['primary'], 'secondary_color' => $theme['secondary'], 'accent_color' => $theme['accent'], 'palette' => 'coral_reef']);
        $this->assertStringContainsString('<style id="lug-palette-roles"', $html);
        $this->assertMatchesRegularExpression('/--lu-on-secondary:#[0-9A-F]{6}/', $html);
        $this->assertMatchesRegularExpression('/--lu-on-dark-rgb:\d+,\d+,\d+/', $html);
        $this->assertStringContainsString('--paper:var(--lu-bg)', $html, 'a generated design\'s paper follows the palette');
        $this->assertStringContainsString('var(--lu-on-primary, #fff)', $html, 'the rewritten button text ships in the render');
        // a design's own hard-coded neutral follows the palette; one used for something else (a dark --bg hero) does not
        $inj = PaletteRoles::injectBlock('<head><style>:root{--muted:#64748B;--bg:#0B1120;--text:#111827}</style></head>', [], [], $roles);
        $this->assertStringContainsString('--muted:var(--lu-muted)', $inj);
        $this->assertStringContainsString('--text:var(--lu-text)', $inj);
        $this->assertStringNotContainsString('--bg:var(--lu-bg)', $inj, 'a dark --bg is not the page ground');
    }

    public function test_the_literal_rewrite_is_rule_aware_semantic_safe_and_idempotent(): void
    {
        $css = '<style>.btn{background:var(--brand-2);color:#fff}.k{color:#0F172A;background:#F7FAFC;border:1px solid #E2E8F0}.err{color:#b91c1c}.ok{color:#14532d}.sh{box-shadow:0 2px 8px rgba(0,0,0,.2)}.hero{background:url(x.jpg);color:#fff}.dk{background:var(--deep);color:rgba(255,255,255,.76)}</style>';
        $n = PaletteNormalizer::normalizeHtml($css, ['accent' => '#2A9D8F']);
        $h = $n['html'];
        $this->assertStringContainsString('color:var(--lu-on-secondary, #fff)', $h, 'white on the secondary is what reads on the secondary');
        $this->assertStringContainsString('color:var(--lu-text, #0F172A)', $h);
        $this->assertStringContainsString('background:var(--lu-bg, #F7FAFC)', $h);
        $this->assertStringContainsString('border:1px solid var(--lu-line, #E2E8F0)', $h);
        $this->assertStringContainsString('.err{color:#b91c1c}', $h, 'error red is semantic');
        $this->assertStringContainsString('.ok{color:#14532d}', $h, 'success green is semantic');
        $this->assertStringContainsString('rgba(0,0,0,.2)', $h, 'shadows are untouched');
        $this->assertStringContainsString('.hero{background:url(x.jpg);color:#fff}', $h, 'white on a photo stays white');
        $this->assertStringContainsString('color:rgba(var(--lu-on-dark-rgb, 255,255,255), .76)', $h, 'translucent text keeps its alpha');
        $again = PaletteNormalizer::normalizeHtml($h, ['accent' => '#2A9D8F']);
        $this->assertSame($h, $again['html']); $this->assertSame(0, $again['count']);
        // placeholders survive
        $p = PaletteNormalizer::normalizeHtml('<style>:root{--a:{{accent_color}}}.x{color:{{accent_color}};background:#111}</style>');
        $this->assertStringContainsString('color:{{accent_color}}', $p['html']);
        $this->assertStringContainsString('background:var(--lu-dark, #111)', $p['html']);
        // a brand colour used as small text becomes its readable version; as a button it keeps the variable; in a
        // painted section (footer on the dark) the descendant inherits the surface and white text follows it
        $b = PaletteNormalizer::normalizeHtml('<style>.eyebrow{color:var(--accent)}.btn{background:var(--accent);color:#fff}.btn2{color:var(--gold);border:1px solid var(--gold)}footer{background:var(--deep)}footer .eyebrow{color:var(--accent)}footer p{color:#fff}.hero{background:var(--brand)}.hero h1{color:#fff}</style>', [], PaletteNormalizer::varRolesFor(['palette_roles' => ['gold' => 'accent']], ':root{--gold:{{gold}}}'));
        $h = $b['html'];
        $this->assertStringContainsString('.eyebrow{color:var(--lu-accent-text, var(--accent))}', $h);
        $this->assertStringContainsString('.btn{background:var(--accent);color:var(--lu-on-accent, #fff)}', $h);
        $this->assertStringContainsString('.btn2{color:var(--lu-accent-text, var(--gold));border:1px solid var(--gold)}', $h, 'the manifest names the accent variable');
        $this->assertStringContainsString('footer .eyebrow{color:var(--lu-accent-on-dark, var(--accent))}', $h, 'on the dark band the accent is the lifted one');
        $this->assertStringContainsString('footer p{color:var(--lu-on-dark, #fff)}', $h);
        $this->assertStringContainsString('.hero h1{color:var(--lu-on-primary, #fff)}', $h);
        // a variable or file name that merely contains a colour word is not a colour
        $w = PaletteNormalizer::normalizeHtml('<style>.w{background:var(--white);color:var(--black)}.l{background:url(white-logo.svg) no-repeat white}</style>');
        $this->assertStringContainsString('.w{background:var(--white);color:var(--black)}', $w['html']);
        $this->assertStringContainsString('url(white-logo.svg) no-repeat var(--lu-bg, white)', $w['html']);
        $twice = PaletteNormalizer::normalizeHtml($h, [], PaletteNormalizer::varRolesFor(['palette_roles' => ['gold' => 'accent']], ':root{--gold:{{gold}}}'));
        $this->assertSame($h, $twice['html']);
        // the design's :root names its variables (`--medical: {{medical_blue}}`): the band on the accent, and the
        // BEM siblings inside it (.cta-title in .cta-banner), get the text that reads on the accent
        $mf = json_decode((string) file_get_contents(storage_path('templates/aesthetic_clinic/manifest.json')), true);
        $vr = PaletteNormalizer::varRolesFor($mf);
        $this->assertSame('accent', $vr['--medical']); $this->assertSame('secondary', $vr['--sage']); $this->assertSame('dark', $vr['--carbon']);
        $c = PaletteNormalizer::normalizeHtml('<style>.cta-banner{background:var(--medical);color:#fff;padding:5rem 2.5rem}.cta-title{color:#fff}.cta-card{background:#fff;color:#111}.hero-note{color:#fff}.form-submit{background:var(--medical);padding:14px 28px}.form-field label{color:var(--medical)}</style>', [], $vr)['html'];
        $this->assertStringContainsString('.cta-banner{background:var(--medical);color:var(--lu-on-accent, #fff);padding:5rem 2.5rem}', $c);
        $this->assertStringContainsString('.cta-title{color:var(--lu-on-accent, #fff)}', $c, 'a BEM sibling sits on the band');
        $this->assertStringContainsString('.cta-card{background:var(--lu-bg, #fff);color:var(--lu-text, #111)}', $c, 'its own background wins');
        $this->assertStringContainsString('.hero-note{color:#fff}', $c, 'no context, no guess');
        $this->assertStringContainsString('.form-field label{color:var(--lu-accent-text, var(--medical))}', $c, 'a button does not lend its surface to its BEM family');
        $cm = PaletteNormalizer::normalizeHtml('<style>/* band */ .cta-banner{background:var(--medical);padding:64px 0} /* title */ .cta-title{color:#fff}</style>', [], $vr)['html'];
        $this->assertStringContainsString('.cta-title{color:var(--lu-on-accent, #fff)}', $cm, 'a comment before the selector is not part of it');
        // a generic rule whose element ALSO lives inside a dark panel gets a scoped twin there — from the page's own
        // DOM; the light card inside the panel shields its label; a second run changes nothing
        $page = '<html><head><style>.lede{color:var(--ink);opacity:.86}.cform label{color:var(--ink)}.ct-panel{background:var(--deep);padding:80px 24px}.ct-panel .card{background:var(--paper);padding:30px}</style></head>'
              . '<body><p class="lede">a</p><section class="ct-panel"><p class="lede">b</p><div class="card"><div class="cform"><label>c</label></div></div></section></body></html>';
        $sc = PaletteNormalizer::normalizeHtml($page, [], PaletteNormalizer::varRolesFor([]));
        $this->assertStringContainsString('.ct-panel .lede{color:var(--lu-on-dark, var(--ink))}', $sc['html']);
        $this->assertStringNotContainsString('.cform label{color:var(--lu-on-dark', $sc['html'], 'the card is light: its label keeps the page text');
        $this->assertStringContainsString('.lede{color:var(--ink);opacity:.86}', $sc['html'], 'the generic rule itself is untouched');
        $sc2 = PaletteNormalizer::normalizeHtml($sc['html'], [], PaletteNormalizer::varRolesFor([]));
        $this->assertSame($sc['html'], $sc2['html']); $this->assertSame(0, $sc2['count']);
        // an inline style is a rule on its own surface: the button's white becomes what reads on the accent
        $inl = PaletteNormalizer::normalizeHtml('<section style="padding:5rem 1.5rem;background:#fff;"><button style="background:var(--medical,#0F172A);color:#fff;border:none">Send</button><a class="blog-cta" style="color:var(--medical)">More</a></section>', [], $vr)['html'];
        $this->assertStringContainsString('<section style="padding:5rem 1.5rem;background:var(--lu-bg, #fff);">', $inl);
        $this->assertStringContainsString('style="background:var(--medical,#0F172A);color:var(--lu-on-accent, #fff);border:none"', $inl);
        $this->assertStringContainsString('style="color:var(--lu-accent-text, var(--medical))"', $inl);
        $fb = PaletteNormalizer::normalizeHtml('<a style="border:1px solid var(--medical,#0F172A);color:var(--medical,#0F172A)">x</a>', [], $vr)['html'];
        $this->assertStringContainsString('color:var(--lu-accent-text, var(--medical,#0F172A))', $fb, 'a var() with a fallback is still the variable');
        $this->assertStringContainsString('border:1px solid var(--medical,#0F172A)', $fb);
        // the brand painter follows color_roles: the hotel's teal is painted with the ACCENT, so its buttons carry on_accent
        $hv = PaletteNormalizer::varRolesFor(json_decode((string) file_get_contents(storage_path('templates/hotel/manifest.json')), true));
        $this->assertSame('accent', $hv['--teal']); $this->assertSame('secondary', $hv['--gold']);
        $nv = PaletteNormalizer::varRolesFor(json_decode((string) file_get_contents(storage_path('templates/news_channel/manifest.json')), true));
        $this->assertSame('primary', $nv['--primary'], 'a generic name is painted by name, whatever color_roles says');
        $inl2 = PaletteNormalizer::normalizeHtml($inl, [], $vr);
        $this->assertSame($inl, $inl2['html']); $this->assertSame(0, $inl2['count']);
    }
}
