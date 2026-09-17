<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\TemplateService;
use App\Engines\Builder\Support\LogoFieldSemantics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * RISK-0185 (2026-09-17) — the logo contract through the real fields route on real designs.
 *
 * `logo_url` is the one logo-image field. A textual brand-logo field (logo / header_logo / nav_logo / footer_logo)
 * is the brand NAME; the preview's click-on-logo door on it is how a customer adds a logo image, and that image must
 * be persisted in logo_url and rendered as an <img> — never printed into the brand text (EV-1056: the live header of
 * site 900 read "/storage/crops/logo-….png"). No design, family or slug is special-cased: the test walks a text-logo
 * design and an image-slot design picked from the library by their markup.
 */
class LogoFieldSemanticsTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    protected function tearDown(): void
    {
        foreach ($this->sites as $id) {
            $dir = storage_path('app/public/sites/' . $id);
            foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
            // RISK-0189 firewall: updateField() keeps RISK-0107 backups under .history — a leftover directory at a low id later made
            // another suite's renderer site look static. Leave nothing behind.
            foreach (glob($dir . '/.history/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
            @rmdir($dir . '/.history');
            @rmdir($dir);
        }
        parent::tearDown();
    }

    /** A design whose logo is text, and one whose logo is an <img data-field="logo_url"> — found by markup, not by name. */
    private function designs(): array
    {
        $text = null; $img = null;
        foreach (glob(storage_path('templates/*/template.html')) as $tpl) {
            $slug = basename(dirname($tpl));
            if (! is_file(dirname($tpl) . '/manifest.json')) continue;
            $html = (string) file_get_contents($tpl);
            if ($img === null && preg_match('/<img[^>]*data-field="logo_url"/', $html)) $img = $slug;
            if ($text === null && preg_match('/<(div|a)\b[^>]*data-field="logo"[^>]*>\{\{logo\}\}/', $html) && ! str_contains($html, 'data-field="logo_url"')) $text = $slug;
            if ($text && $img) break;
        }
        $this->assertNotNull($text, 'the library has a text-logo design');
        $this->assertNotNull($img, 'the library has an image-slot logo design');
        return [$text, $img];
    }

    /** A website of the test workspace on the given design, exported to disk exactly as the editor would find it. */
    private function site(string $slug, array $overrides = []): int
    {
        $manifest = json_decode((string) file_get_contents(storage_path("templates/{$slug}/manifest.json")), true);
        $vars = ['business_name' => 'Ember Bakes'];
        foreach (($manifest['variables'] ?? []) as $k => $def) { $vars[$k] = (string) ($def['default'] ?? ''); }
        $vars['logo'] = 'Ember Bakes'; $vars['footer_logo'] = 'Ember Bakes'; $vars['logo_url'] = '';
        $vars = array_merge($vars, $overrides);
        $id = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $this->testWorkspace->id, 'name' => 'Ember Bakes', 'status' => 'draft', 'type' => 'template',
            'template_industry' => $slug, 'settings_json' => json_encode(['template' => $slug]),
            'template_variables' => json_encode($vars), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $html = (new TemplateService())->render($slug, $vars, $id);
        $dir = storage_path('app/public/sites/' . $id);
        if (! is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($dir . '/index.html', $html);
        $this->sites[] = $id;
        return $id;
    }

    private function vars(int $id): array
    {
        return json_decode((string) DB::table('websites')->where('id', $id)->value('template_variables'), true) ?: [];
    }

    private function export(int $id): string
    {
        return (string) file_get_contents(storage_path("app/public/sites/{$id}/index.html"));
    }

    private function putField(int $id, string $field, string $value)
    {
        return $this->withHeaders($this->authHeaders())->putJson("/api/builder/websites/{$id}/fields/{$field}", ['value' => $value]);
    }

    public function test_a_text_logo_design_renders_the_brand_name_as_text_until_an_image_is_chosen(): void
    {
        [$text] = $this->designs();
        $id = $this->site($text);
        $this->assertStringContainsString('data-field="logo">Ember Bakes<', $this->export($id));
        $this->assertStringNotContainsString('lu-logo-img', $this->export($id));

        $this->putField($id, 'logo', 'Ember & Bean')->assertOk()->assertJson(['saved' => true, 'field' => 'logo', 'redirected_from' => null]);
        $this->assertSame('Ember & Bean', $this->vars($id)['logo']);
        $this->assertSame('', $this->vars($id)['logo_url']);
        $this->assertStringContainsString('Ember &amp; Bean', $this->export($id));
    }

    public function test_an_image_chosen_for_the_text_logo_is_persisted_as_logo_url_and_rendered_as_an_image(): void
    {
        [$text] = $this->designs();
        $id = $this->site($text);

        $r = $this->putField($id, 'logo', '/storage/crops/logo-abc123.png');
        $r->assertOk()->assertJson(['saved' => true, 'field' => 'logo_url', 'redirected_from' => 'logo', 'export_patched' => true]);

        $v = $this->vars($id);
        $this->assertSame('/storage/crops/logo-abc123.png', $v['logo_url'], 'the image lives in the logo-image field');
        $this->assertSame('Ember Bakes', $v['logo'], 'the brand text is untouched');
        $this->assertSame('Ember Bakes', $v['footer_logo']);

        $export = $this->export($id);
        $this->assertMatchesRegularExpression('/data-field="logo"[^>]*data-lu-logo-text="Ember Bakes"[^>]*><img class="lu-logo-img" src="\/storage\/crops\/logo-abc123\.png"/', $export);
        $this->assertStringNotContainsString('>/storage/crops/logo-abc123.png<', $export, 'the path is never printed as copy');

        // the preview / published render from the saved variables agrees with the export
        $render = (new TemplateService())->render($text, $v, $id);
        $this->assertStringContainsString('<img class="lu-logo-img" src="/storage/crops/logo-abc123.png"', $render);
        $this->assertStringNotContainsString('>/storage/crops/logo-abc123.png<', $render);
    }

    public function test_removing_the_logo_restores_the_brand_text_and_replacing_it_swaps_the_image(): void
    {
        [$text] = $this->designs();
        $id = $this->site($text);
        $this->putField($id, 'logo', '/storage/crops/logo-one.png')->assertOk();
        $this->putField($id, 'logo_url', '/storage/crops/logo-two.png')->assertOk()->assertJson(['field' => 'logo_url', 'redirected_from' => null]);
        $this->assertStringContainsString('src="/storage/crops/logo-two.png"', $this->export($id));
        $this->assertStringNotContainsString('logo-one.png', $this->export($id));

        $this->putField($id, 'logo_url', '')->assertOk();
        $this->assertSame('', $this->vars($id)['logo_url']);
        $this->assertSame('Ember Bakes', $this->vars($id)['logo']);
        $export = $this->export($id);
        $this->assertStringNotContainsString('lu-logo-img', $export);
        $this->assertStringContainsString('data-field="logo">Ember Bakes<', $export, 'the text comes back exactly');
    }

    public function test_an_image_aimed_at_ordinary_copy_is_refused_not_printed(): void
    {
        [$text] = $this->designs();
        $id = $this->site($text);
        $before = $this->vars($id)['hero_title'];
        $this->putField($id, 'hero_title', '/storage/crops/logo-abc123.png')->assertStatus(422)->assertJson(['saved' => false, 'field' => 'hero_title']);
        $this->assertSame($before, $this->vars($id)['hero_title']);
        $this->assertStringNotContainsString('/storage/crops/logo-abc123.png', $this->export($id));
    }

    public function test_a_design_with_an_image_logo_slot_is_unchanged(): void
    {
        [, $img] = $this->designs();
        $id = $this->site($img);
        $this->putField($id, 'logo_url', '/storage/crops/logo-slot.png')->assertOk()->assertJson(['saved' => true, 'field' => 'logo_url', 'redirected_from' => null]);
        $this->assertSame('/storage/crops/logo-slot.png', $this->vars($id)['logo_url']);
        $export = $this->export($id);
        $this->assertMatchesRegularExpression('/<img[^>]*(data-field="logo_url"[^>]*src="\/storage\/crops\/logo-slot\.png"|src="\/storage\/crops\/logo-slot\.png"[^>]*data-field="logo_url")/', $export, 'the slot carries the logo');
        $this->assertMatchesRegularExpression('/data-field="logo_url"[^>]*>\s*<[a-z]+[^>]*style="display:none"/', $export, 'the brand text beside the slot is hidden while a logo shows');
        $this->assertStringNotContainsString('>/storage/crops/logo-slot.png<', $export);
        $this->assertStringNotContainsString('lu-logo-img', $export, 'no text-logo swap on an image-slot design');

        $this->putField($id, 'logo_url', '')->assertOk();
        $export = $this->export($id);
        $this->assertMatchesRegularExpression('/data-field="logo_url"[^>]*>\s*<[a-z]+[^>]*style="display:block"/', $export, 'the brand text returns when the logo is removed');
        $this->assertStringNotContainsString('logo-slot.png', $export);
    }

    public function test_ordinary_image_fields_still_take_a_url_including_ones_typed_only_by_the_manifest(): void
    {
        $slug = null; $byManifest = null;
        foreach (glob(storage_path('templates/*/manifest.json')) as $mf) {
            $m = json_decode((string) file_get_contents($mf), true) ?: [];
            foreach (($m['variables'] ?? []) as $k => $def) {
                if (($def['type'] ?? '') === 'image' && ! str_ends_with($k, '_image') && ! str_contains($k, '_img') && ! str_contains($k, 'image_') && ! str_contains($k, 'logo')) { $slug = basename(dirname($mf)); $byManifest = $k; break 2; }
            }
        }
        $this->assertNotNull($byManifest, 'the library has an image field typed only by a manifest');
        $id = $this->site($slug);
        $this->assertTrue(LogoFieldSemantics::isImageField($id, $byManifest));
        $this->putField($id, $byManifest, '/storage/uploads/photo.jpg')->assertOk()->assertJson(['saved' => true, 'field' => $byManifest, 'redirected_from' => null]);
        $this->assertSame('/storage/uploads/photo.jpg', $this->vars($id)[$byManifest]);
    }

    public function test_what_counts_as_an_image_value(): void
    {
        foreach (['/storage/crops/logo-1.png', 'https://x.test/a/b.webp?v=2', '/storage/uploads/UiZrvdFzqMQRJpwY', 'data:image/png;base64,AAAA', '/img/agents/sarah.webp'] as $v) {
            $this->assertTrue(LogoFieldSemantics::looksLikeImage($v), $v);
        }
        foreach (['Ember & Bean', 'Bakery on Storage Street', 'https://example.com/about', 'Fresh bakes daily.', '/menu', ''] as $v) {
            $this->assertFalse(LogoFieldSemantics::looksLikeImage($v), $v);
        }
    }
}
