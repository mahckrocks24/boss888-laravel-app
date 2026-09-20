<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\BuilderRenderer;
use App\Engines\Builder\Services\TemplateService;
use App\Engines\Builder\Support\BuilderCapabilities;
use App\Engines\Builder\Support\ColorTheme;
use App\Engines\Builder\Support\PaletteRoles;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RISK-0191 U1 (2026-09-19) — added sections and pages speak the site's palette roles.
 *
 * The renderer's literal colours (white shells, #1a1a2e headings, #5a5f72 copy, brand hexes …) become
 * `var(--lu-<role>, <current hex>)`; the conversion is idempotent, never changes visible text, keeps semantic
 * reds/greens and tints, and works on a dark ground as well as a light one. Sections stored before the roles are
 * converted once, in place, and marked; the export's page list is explicit and bounded to the site's own directory.
 */
class SectionRolesTest extends TestCase
{
    private int $ws = 998877;
    private int $site = 998877;
    private int $madeUser = 0;
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('pages')->where('website_id', $this->site)->delete();
        DB::table('websites')->where('id', $this->site)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        $uid = (int) (DB::table('users')->min('id') ?: 0);
        if ($uid <= 0) { $uid = (int) DB::table('users')->insertGetId(['name' => 'Roles QA', 'email' => 'rolesqa-' . $this->ws . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]); $this->madeUser = $uid; }
        DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'Roles QA', 'slug' => 'roles-qa-' . $this->ws, 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert(['id' => $this->site, 'workspace_id' => $this->ws, 'name' => 'Roles QA', 'subdomain' => 'rolesqa.levelupgrowth.io', 'status' => 'draft', 'type' => 'builder',
            'settings_json' => json_encode(['template' => 'pet_services']),
            'template_variables' => json_encode(['primary_color' => '#C8502F', 'secondary_color' => '#F4A261', 'accent_color' => '#2A9D8F', 'palette' => 'midnight_gold']),
            'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->rmdir(storage_path('app/public/sites/' . $this->site));
        $this->rmdir(storage_path('app/public/sites/' . ($this->site + 1)));
        @unlink(sys_get_temp_dir() . '/lu-roles-outside-' . $this->site . '.html');
        DB::table('pages')->where('website_id', $this->site)->delete();
        DB::table('websites')->where('id', $this->site)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        if ($this->madeUser > 0) DB::table('users')->where('id', $this->madeUser)->delete();
        parent::tearDown();
    }

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) { @unlink($dir); return; }
        foreach (scandir($dir) ?: [] as $f) { if ($f === '.' || $f === '..') continue; $p = $dir . '/' . $f; if (is_link($p) || is_file($p)) @unlink($p); else $this->rmdir($p); }
        @rmdir($dir);
    }

    private function visible(string $html): string
    {
        // U3 (2026-09-20): an entity and its character are the same text (&#10003; and ✓) — the field pass restores UTF-8
        return html_entity_decode(strip_tags((string) preg_replace('~<(style|script)\b[^>]*>.*?</\1>~is', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Colour literals outside var() fallbacks, per declaration of a colour property. */
    private function literalsLeft(string $html): array
    {
        $stripped = preg_replace('/rgba\(\s*var\([^()]*\)\s*,\s*[0-9.]+\s*\)/', 'VAR', $html) ?? $html;   // translucent role: rgba(var(--lu-x-rgb, r,g,b), a)
        $stripped = preg_replace('/var\([^()]*(?:\([^()]*\)[^()]*)*\)/', 'VAR', $stripped) ?? $stripped;
        preg_match_all('/(?:^|[;"{])\s*(?:color|background|background-color|border(?:-[a-z]+)?|fill|stroke)\s*:\s*([^;"}]*)/i', $stripped, $m);
        $out = [];
        foreach ($m[1] as $v) { if (preg_match_all('/#[0-9a-f]{3,6}\b|rgba\([^)]*\)|(?<![-\w])(?:white|black)(?![-\w])/i', $v, $lm)) foreach ($lm[0] as $l) $out[] = strtolower($l); }
        return array_values(array_unique($out));
    }

    public function test_every_catalogue_section_speaks_the_roles_on_a_light_and_a_dark_ground(): void
    {
        $theme = ColorTheme::find('midnight_gold');
        $brand = ['primary' => $theme['primary'], 'secondary' => $theme['secondary'], 'accent' => $theme['accent']];
        $renderer = app(BuilderRenderer::class);
        $specs = [
            'pricing' => ['tiers' => [['name' => 'Basic', 'price' => '$10', 'features' => ['A']], ['name' => 'Pro', 'price' => '$20', 'features' => ['C'], 'highlight' => true]]],
            'faq' => ['items' => [['q' => 'Q1?', 'a' => 'A1']]], 'testimonials' => ['items' => [['quote' => 'Great', 'author' => 'Ann', 'role' => 'CEO']]],
            'team' => ['members' => [['name' => 'Ann', 'role' => 'CEO']]], 'gallery' => ['images' => [['url' => '/x.jpg', 'caption' => 'c']]],
            'stats' => ['items' => [['value' => '10', 'label' => 'years']]], 'features' => ['items' => [['title' => 'Fast', 'description' => 'd']]],
            'services' => ['items' => [['title' => 'S1', 'description' => 'd']]], 'map' => ['address' => 'Main St'], 'trust_signals' => ['items' => [['label' => 'ISO']]],
            'cta' => ['button_text' => 'Go', 'button_url' => '#'], 'booking_form' => ['services' => ['A']], 'events_calendar' => ['events' => []],
            'travel_quiz' => ['business_name' => 'X', 'currency' => 'AED', 'subheading' => 's', 'reference_prefix' => 'X'],
            'video_embed' => ['video_url' => 'https://www.youtube.com/watch?v=abc123'],
        ];
        // what may stay literal: semantic success/error colours and translucent tints (never a solid text/ground)
        $allowed = '/^(#155724|#f8d7da|#721c24|#065f46|#a12530|#fde8e8|rgba\((?:0,0,0|15,23,42|124,58,237),\s*\.?0?\.?[0-9]+\))$/';
        foreach (['light', 'dark'] as $scheme) {
            $roles = PaletteRoles::derive($theme, $scheme);
            foreach (array_keys(BuilderCapabilities::SECTIONS) as $type) {
                $raw = $renderer->renderSection(['type' => $type, 'heading' => ucfirst($type)] + ($specs[$type] ?? []), $brand, []);
                $c = PaletteRoles::roleifyRendered($raw, $roles, $brand);
                $this->assertGreaterThan(0, preg_match_all('/var\(--lu-/', $c), "$type/$scheme: no role variable written");
                $this->assertSame($c, PaletteRoles::roleifyRendered($c, $roles, $brand), "$type/$scheme: not idempotent");
                $this->assertSame($this->visible($raw), $this->visible($c), "$type/$scheme: visible text changed");
                foreach ($this->literalsLeft($c) as $lit) { $this->assertMatchesRegularExpression($allowed, $lit, "$type/$scheme: literal left: $lit"); }
                // every fallback is the role's current value — never a stale or foreign colour
                if (preg_match_all('/var\(--lu-([a-z-]+), (#[0-9A-Fa-f]{6})\)/', $c, $vm, PREG_SET_ORDER)) {
                    foreach ($vm as $m) { $this->assertSame(strtoupper($roles[str_replace('-', '_', $m[1])] ?? '?'), strtoupper($m[2]), "$type/$scheme: fallback of {$m[1]}"); }
                }
                // a declaration that paints both ground and ink names roles that read on each other
                if (preg_match_all('/style="([^"]*)"/', $c, $sm)) {
                    foreach ($sm[1] as $attr) {
                        if (! preg_match('/background(?:-color)?:\s*var\(--lu-([a-z-]+)/', $attr, $bm) || ! preg_match('/(?:^|;)\s*color:\s*var\(--lu-([a-z-]+)/', $attr, $tm)) continue;
                        $bg = $roles[str_replace('-', '_', $bm[1])] ?? null; $fg = $roles[str_replace('-', '_', $tm[1])] ?? null;
                        if ($bg && $fg) $this->assertGreaterThanOrEqual(PaletteRoles::AA, ColorTheme::contrast($fg, $bg), "$type/$scheme: {$tm[1]} on {$bm[1]}");
                    }
                }
            }
        }
    }

    public function test_a_section_stored_before_the_roles_is_converted_once_in_place_and_marked(): void
    {
        $legacy = '<section data-block="added_pricing" id="lu-pricing" style="padding:24px 0;scroll-margin-top:100px"><section style="background:#ffffff;padding:80px 24px"><div style="max-width:1100px;margin:0 auto"><h2 style="color:#1a1a2e;text-align:center">Simple pricing</h2><div style="background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:16px"><h3 style="color:#1a1a2e">Gourmet</h3><div style="color:#C8502F;font-size:32px">From AED 10</div><li style="color:#5a5f72">&#10003; Tailored</li><a style="background:#C8502F;color:#fff">Book</a></div></div></section></section>';
        $noWrapper = '<div style="color:#1a1a2e">loose fragment with no added_* wrapper</div>';
        $s = json_decode((string) DB::table('websites')->where('id', $this->site)->value('settings_json'), true);
        $s['arthur_sections'] = [
            ['type' => 'pricing', 'html' => $legacy, 'anchor' => 'contact', 'where' => 'before'],
            ['type' => 'odd', 'html' => $noWrapper, 'anchor' => 'contact', 'where' => 'before'],
        ];
        DB::table('websites')->where('id', $this->site)->update(['settings_json' => json_encode($s)]);

        $ts = app(TemplateService::class);
        $r = $ts->roleifyStoredSections($this->site);
        $this->assertSame(1, $r['converted']);
        $this->assertCount(1, $r['skipped']);
        $this->assertStringContainsString('odd', $r['skipped'][0]);

        $after = json_decode((string) DB::table('websites')->where('id', $this->site)->value('settings_json'), true)['arthur_sections'];
        $this->assertCount(2, $after, 'conversion never adds or removes a section');
        $conv = $after[0]['html'];
        $this->assertStringContainsString(PaletteRoles::RENDERED_MARK . '="' . PaletteRoles::RENDERED_MARK_VERSION . '"', $conv);
        $this->assertSame($this->visible($legacy), $this->visible($conv), 'content untouched');
        $this->assertSame(1, substr_count($conv, 'data-block="added_pricing"'), 'no duplicated wrapper');
        $this->assertStringContainsString('background:var(--lu-bg, ', $conv);
        $this->assertStringContainsString('color:var(--lu-primary-text, ', $conv);
        $this->assertStringContainsString('background:var(--lu-primary, ', $conv);
        $this->assertStringContainsString('color:var(--lu-on-primary, ', $conv);
        $this->assertStringContainsString('border:1px solid var(--lu-line, ', $conv);
        $this->assertStringNotContainsString('#1a1a2e', $conv);
        $this->assertStringNotContainsString('#5a5f72', $conv);
        $this->assertSame($noWrapper, $after[1]['html'], 'a fragment that cannot be marked is preserved verbatim');

        // repeatable: nothing changes on the second pass, no markup accumulates
        $r2 = $ts->roleifyStoredSections($this->site);
        $this->assertSame(0, $r2['converted']);
        $this->assertSame(1, $r2['kept']);
        $again = json_decode((string) DB::table('websites')->where('id', $this->site)->value('settings_json'), true)['arthur_sections'];
        $this->assertSame($conv, $again[0]['html']);
        $this->assertSame(strlen($conv), strlen($again[0]['html']));
    }

    public function test_export_normalisation_is_bounded_to_the_websites_own_pages(): void
    {
        $dir = storage_path('app/public/sites/' . $this->site);
        $other = storage_path('app/public/sites/' . ($this->site + 1));
        $outside = sys_get_temp_dir() . '/lu-roles-outside-' . $this->site . '.html';
        @mkdir($dir . '/about', 0775, true); @mkdir($dir . '/.history', 0775, true); @mkdir($other, 0775, true);
        $body = '<section data-block="added_faq" style="padding:24px 0"><section style="background:#ffffff"><h2 style="color:#1a1a2e">FAQ</h2></section></section>';
        $doc = '<!doctype html><html><head><title>t</title></head><body>' . $body . '</body></html>';
        file_put_contents($dir . '/index.html', $doc);
        file_put_contents($dir . '/about/index.html', '<!doctype html><html><head></head><body><main data-lu-page="about"><section style="background:#ffffff"><h2 style="color:#1a1a2e">About</h2></section></main></body></html>');
        file_put_contents($dir . '/.history/index-20260101-000000-abcd.html', $doc);
        file_put_contents($other . '/index.html', $doc);
        file_put_contents($outside, $doc);
        @symlink(dirname($outside), $dir . '/linked');                 // a symlinked directory
        @symlink($other . '/index.html', $dir . '/stray.html');        // a symlinked top-level file
        foreach (['about', 'linked', 'stray', 'evil/../x', '.history', 'blog'] as $slug) {
            DB::table('pages')->insert(['website_id' => $this->site, 'title' => $slug, 'slug' => $slug, 'status' => 'published', 'sections_json' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        }

        $files = array_map(fn ($f) => str_replace($dir, '', $f), PaletteRoles::exportPageFiles($this->site));
        sort($files);
        $this->assertSame(['/about/index.html', '/index.html'], $files, 'only the site\'s own real pages: no symlink, no history, no blog, no traversal');

        $ctx = app(TemplateService::class)->rolesForSite($this->site);
        $done = PaletteRoles::normaliseExport($this->site, $ctx['roles'], $ctx['manifest']);
        $this->assertSame(['index.html', 'about/index.html'], array_keys($done));
        $home = file_get_contents($dir . '/index.html'); $about = file_get_contents($dir . '/about/index.html');
        $this->assertStringContainsString('id="lug-palette-roles"', $home);
        $this->assertStringContainsString('id="lug-palette-roles"', $about, 'the added page carries the roles block');
        $this->assertStringContainsString('var(--lu-text, ', $about, 'the added page body speaks the roles');
        $this->assertStringContainsString(PaletteRoles::RENDERED_MARK . '="', $home, 'the home\'s added section is marked');
        $this->assertSame($doc, file_get_contents($other . '/index.html'), 'another website untouched');
        $this->assertSame($doc, file_get_contents($outside), 'a symlink target outside the site untouched');
        $this->assertSame($doc, file_get_contents($dir . '/.history/index-20260101-000000-abcd.html'), 'snapshots untouched');
        // repeatable
        $h1 = md5($home); PaletteRoles::normaliseExport($this->site, $ctx['roles'], $ctx['manifest']);
        $this->assertSame($h1, md5_file($dir . '/index.html'));
    }

    public function test_a_deployed_page_carries_the_roles_block_and_role_colours(): void
    {
        $dir = storage_path('app/public/sites/' . $this->site);
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/index.html', '<!doctype html><html lang="en"><head><title>Home</title></head><body><nav data-block="nav"><a href="#">Home</a></nav><footer>f</footer></body></html>');
        $path = app(TemplateService::class)->deployPage($this->site, 'pricing', '<section style="background:#ffffff;padding:40px"><h1 style="color:#1a1a2e">Pricing</h1><p style="color:#5a5f72">copy</p><a style="background:#C8502F;color:#fff">Go</a></section>', 'Pricing');
        $this->assertNotNull($path);
        $page = file_get_contents($path);
        $this->assertStringContainsString('id="lug-palette-roles"', $page);
        $this->assertStringContainsString('<main data-lu-page="pricing">', $page);
        $this->assertStringContainsString('color:var(--lu-text, ', $page);
        $this->assertStringContainsString('color:var(--lu-muted, ', $page);
        $this->assertStringContainsString('background:var(--lu-primary, ', $page);
        $this->assertStringContainsString('color:var(--lu-on-primary, ', $page);
        $this->assertStringNotContainsString('color:#1a1a2e', $page);
        $this->assertStringContainsString('>Pricing<', $page);
    }
}
