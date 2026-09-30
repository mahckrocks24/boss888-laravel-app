<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\ArthurService;
use App\Engines\Builder\Services\TemplateService;
use App\Engines\Builder\Support\Editor3;
use Tests\TestCase;

/**
 * EDITOR-3 (RFC-0021 wave 3) — fail-first tests from REPORT-0068 #5 (sections cannot be removed), #11 (internal wording
 * in the page picker), #15 (uploads have no alt text) and REPORT-0067 #14 (8 of 9 images without alt).
 */
class Editor3Test extends TestCase
{
    private const SITE_ID = 999999903;
    private const FIXTURE = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>EDITOR3 fixture</title></head>
<body>
<nav data-block="nav"><a href="#services">Services</a><a href="#testimonials" class="nav-link">Reviews</a></nav>
<section id="testimonials" data-block="testimonials" class="quotes" style="padding:10px"><p data-field="testimonial_1_quote">Sample words</p></section>
<section id="services" data-block="services"><h2 data-field="services_title">What we do</h2></section>
<section class="about"><img data-field="about_image" src="/old.jpg" alt=""><p data-field="about_text">About us</p></section>
</body></html>
HTML;

    private string $dir; private string $path; private bool $madeSwitch = false;

    protected function setUp(): void
    {
        parent::setUp();
        $sw = storage_path(Editor3::SWITCH); if (! is_file($sw)) { touch($sw); $this->madeSwitch = true; }
        $this->dir = storage_path('app/public/sites/' . self::SITE_ID); $this->path = $this->dir . '/index.html';
        if (! is_dir($this->dir)) mkdir($this->dir, 0775, true);
        file_put_contents($this->path, self::FIXTURE);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/.history/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir . '/.history'); @unlink($this->path); @rmdir($this->dir);
        if ($this->madeSwitch) @unlink(storage_path(Editor3::SWITCH));
        parent::tearDown();
    }

    public function test_a_section_can_be_hidden_removed_and_shown_again(): void
    {
        $h = Editor3::applyHiddenBlocks(self::FIXTURE, ['testimonials']);
        $this->assertStringContainsString('<section id="testimonials" data-block="testimonials" class="quotes" style="padding:10px;display:none/*lu-hidden*/" data-lu-hidden="1">', $h);
        $this->assertStringContainsString('<section id="services" data-block="services">', $h, 'other sections untouched');
        $h = Editor3::hideNavLinks($h, 'testimonials', 'testimonials');
        $this->assertStringContainsString('<a href="#testimonials" class="nav-link" style="display:none/*lu-hidden*/" data-lu-hidden-link="testimonials">', $h);
        $this->assertStringContainsString('<a href="#services">Services</a>', $h);
        $back = Editor3::unhide($h, 'testimonials');
        $this->assertSame(self::FIXTURE, $back, 'show puts the page back exactly');
    }

    public function test_the_builder_hides_a_section_on_the_export_and_remembers_it(): void
    {
        $ts = new TemplateService();
        $r = $ts->setBlockVisibility(self::SITE_ID, 'testimonials', true, true);
        $this->assertTrue($r['success']); $this->assertTrue($r['removed']);
        $html = file_get_contents($this->path);
        $this->assertStringContainsString('data-lu-hidden="1"', $html); $this->assertStringContainsString('data-lu-hidden-link="testimonials"', $html);
        $this->assertStringContainsString('<section id="services" data-block="services">', $html);
        $r2 = $ts->setBlockVisibility(self::SITE_ID, 'testimonials', false);
        $this->assertTrue($r2['success']); $this->assertSame(self::FIXTURE, file_get_contents($this->path));
    }

    public function test_a_placed_picture_gets_alt_text(): void
    {
        $this->assertSame('Crumb and Co – about', Editor3::altFor('Crumb and Co', 'about_image'));
        $this->assertSame('Crumb and Co – gallery', Editor3::altFor('Crumb and Co', 'gallery_3'));
        $this->assertSame('hero', Editor3::altFor('', 'hero_image'));
        $ts = new TemplateService();
        $this->assertTrue($ts->updateField(self::SITE_ID, 'about_image', '/storage/crops/section-abc.jpg', false));
        $html = file_get_contents($this->path);
        $this->assertMatchesRegularExpression('/<img data-field="about_image" src="\/storage\/crops\/section-abc\.jpg" alt="[^"]+"/', $html);
        $this->assertStringNotContainsString('alt=""', $html);
    }

    public function test_the_page_picker_speaks_the_owners_language(): void
    {
        $banned = '/template|tenant|skeleton|agnostic|booking_form|filter_bar|events_calendar|Hero \+|\bCTA\b/i';
        foreach (ArthurService::PAGE_TEMPLATE_CATALOGUE as $slug => $meta) {
            $d = (string) ($meta['description'] ?? '');
            $this->assertNotSame('', $d, "$slug has a description");
            $this->assertDoesNotMatchRegularExpression($banned, $d, "$slug: \"$d\"");
            $this->assertArrayHasKey($slug, Editor3::PAGE_COPY);
        }
    }
}
