<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\TemplateService;
use Tests\TestCase;

/**
 * BUILDER888 · D4 (2026-08-28) — TemplateService::updateField must never destroy the
 * polished site while "saving" a field.
 *
 * Interactive-browser finding (site 445, Fable QA Bakery): replacing the hero image via
 * the editor's "Choose Image" wrote the URL as TEXT into `<section data-field="hero_image">`
 * because the section's background came from a CSS class (no inline style). The section's
 * whole subtree — h1, subtitle, trust items, booking form — was replaced by the URL string
 * on the PUBLISHED site, and the API returned saved=true. These tests pin the safe behaviour:
 * image writes on content wrappers set an inline background and keep the children; text
 * writes never replace a wrapper that contains other fields.
 */
class UpdateFieldStructuralSafetyTest extends TestCase
{
    private const SITE_ID = 999999901;

    private const FIXTURE = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>D4 fixture</title>
<style>.hero{padding:10px;background-image:linear-gradient(rgba(253,246,236,0.82) 0%,rgba(253,246,236,0.92) 100%),url('/storage/builder-heroes/cafe.jpg');background-size:cover}
@media(max-width:600px){.hero{padding:4px}}</style></head>
<body>
<section class="hero" data-block="hero" data-field="hero_image">
  <div class="wrap">
    <span class="eyebrow" data-field="hero_eyebrow">Welcome</span>
    <h1 class="hero-title" data-field="hero_title">Baking Dreams into Reality</h1>
    <p class="hero-subtitle" data-field="hero_subtitle">Savor the art.</p>
    <form><button type="submit" data-field="hero_form_submit">Subscribe Now</button></form>
  </div>
</section>
<section class="about"><img data-field="about_image" src="/old.jpg" alt=""><p data-field="about_text">About us</p></section>
<div class="logo-wrap" data-field="logo"></div>
</body></html>
HTML;

    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir  = storage_path('app/public/sites/' . self::SITE_ID);
        $this->path = $this->dir . '/index.html';
        if (! is_dir($this->dir)) mkdir($this->dir, 0775, true);
        file_put_contents($this->path, self::FIXTURE);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/.history/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir . '/.history');
        @unlink($this->path);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function svc(): TemplateService
    {
        return app(TemplateService::class);
    }

    public function test_background_wrapper_image_replacement_keeps_the_hero_content(): void
    {
        $ok = $this->svc()->updateField(self::SITE_ID, 'hero_image', '/storage/template-images/cafe/gallery_2.jpg');
        $html = file_get_contents($this->path);

        $this->assertTrue($ok, 'image save on a class-backgrounded wrapper must be applied');
        $this->assertStringContainsString('Baking Dreams into Reality', $html, 'the hero h1 must survive');
        $this->assertStringContainsString('data-field="hero_title"', $html);
        $this->assertStringContainsString('data-field="hero_subtitle"', $html);
        $this->assertStringContainsString('data-field="hero_form_submit"', $html);
        $this->assertMatchesRegularExpression(
            '#<section[^>]*data-field="hero_image"[^>]*style="[^"]*background-image:linear-gradient\(rgba\(253,246,236,0\.82\) 0%,rgba\(253,246,236,0\.92\) 100%\),url\(\'/storage/template-images/cafe/gallery_2\.jpg\'\)#',
            $html,
            'the wrapper must get an inline background-image that KEEPS the class rule\'s wash layer (D3) with the url swapped'
        );
        $this->assertStringNotContainsString('>/storage/template-images/cafe/gallery_2.jpg<', $html, 'the URL must never appear as text content');
    }

    public function test_text_write_never_replaces_a_wrapper_that_contains_other_fields(): void
    {
        // A crafted/misrouted TEXT value for the hero wrapper must not wipe its children.
        $ok = $this->svc()->updateField(self::SITE_ID, 'hero_image', 'not a url just text');
        $html = file_get_contents($this->path);
        $this->assertStringContainsString('Baking Dreams into Reality', $html);
        $this->assertStringContainsString('data-field="hero_form_submit"', $html);
        $this->assertTrue($ok, 'still applied as a background (inline style), never as text');

        // Same for a non-image field name pointing at a wrapper: refused, file unchanged.
        file_put_contents($this->path, str_replace('data-field="hero_image"', 'data-field="hero_block"', self::FIXTURE));
        $before = file_get_contents($this->path);
        $ok2 = $this->svc()->updateField(self::SITE_ID, 'hero_block', 'text that would destroy the hero');
        $this->assertFalse($ok2, 'a text write into a wrapper with nested fields must be refused');
        $this->assertSame($before, file_get_contents($this->path), 'the served file must be untouched');
    }

    public function test_leaf_text_and_img_src_edits_still_work(): void
    {
        $this->assertTrue($this->svc()->updateField(self::SITE_ID, 'hero_title', 'Fresh Bread Daily'));
        $this->assertTrue($this->svc()->updateField(self::SITE_ID, 'about_image', '/new.jpg'));
        $this->assertTrue($this->svc()->updateField(self::SITE_ID, 'logo', 'Text Logo'), 'an empty logo wrapper may take text');
        $html = file_get_contents($this->path);
        $this->assertStringContainsString('>Fresh Bread Daily<', $html);
        $this->assertStringContainsString('src="/new.jpg"', $html);
        $this->assertStringContainsString('>Text Logo<', $html);
        $this->assertStringContainsString('data-field="hero_subtitle"', $html, 'unrelated fields untouched');
    }

    public function test_css_url_value_cannot_break_out_of_the_style_attribute(): void
    {
        $this->svc()->updateField(self::SITE_ID, 'hero_image', "x.jpg') ; color:red; background:url('evil");
        $html = file_get_contents($this->path);
        $this->assertMatchesRegularExpression(
            "#data-field=\"hero_image\"[^>]*style=\"background-image:(?:linear-gradient\\([^\"]*\\),)?url\\('[^'()]*'\\)\"#",
            $html,
            'exactly one well-formed url() with no quote/paren breakout'
        );
        $this->assertDoesNotMatchRegularExpression('#;\s*color:red#', $html, 'no second declaration can be injected');
        $this->assertStringContainsString('Baking Dreams into Reality', $html);
    }
}
