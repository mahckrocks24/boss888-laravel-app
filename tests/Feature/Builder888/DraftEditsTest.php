<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\DraftEdits;
use Tests\TestCase;

/**
 * DRAFT-5 (RFC-0021 wave 5) — fail-first tests from REPORT-0068 #7 (every edit live at once; the Back dialog's false
 * draft) and REPORT-0067 #7 (a placeholder Menu page published and charged).
 */
class DraftEditsTest extends TestCase
{
    private const SITE_ID = 999999904;
    private string $root; private bool $madeSwitch = false;

    protected function setUp(): void
    {
        parent::setUp();
        $sw = storage_path(DraftEdits::SWITCH); if (! is_file($sw)) { touch($sw); $this->madeSwitch = true; }
        $this->root = storage_path('app/public/sites/' . self::SITE_ID);
        $this->rm($this->root);
        @mkdir($this->root . '/about', 0775, true); @mkdir($this->root . '/.history', 0775, true); @mkdir($this->root . '/blog/first-post', 0775, true);
        file_put_contents($this->root . '/index.html', '<html><body><section data-block="hero"><h1 data-field="hero_title">Old title</h1><p data-field="hero_subtitle">Sub</p><img data-field="hero_image" src="/a.jpg"></section><section id="t" data-block="testimonials"><p data-field="testimonial_1_quote">Sample</p></section></body></html>');
        file_put_contents($this->root . '/about/index.html', '<html><body><h1 data-field="about_title">About</h1></body></html>');
        file_put_contents($this->root . '/.history/index-old.html', 'x');
        file_put_contents($this->root . '/blog/first-post/index.html', '<html><body>post</body></html>');
        file_put_contents($this->root . '/logo.png', 'png');
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
        if ($this->madeSwitch) @unlink(storage_path(DraftEdits::SWITCH));
        parent::tearDown();
    }

    private function rm(string $d): void { if (! is_dir($d)) return; foreach (scandir($d) ?: [] as $e) { if ($e === '.' || $e === '..') continue; $p = "$d/$e"; is_dir($p) ? $this->rm($p) : @unlink($p); } @rmdir($d); }

    public function test_visitors_are_served_the_frozen_copy_only_once_it_exists(): void
    {
        $this->assertSame($this->root, DraftEdits::servedRoot($this->root), 'no live copy yet: the working tree, as before');
        $r = DraftEdits::promote(self::SITE_ID);
        $this->assertTrue($r['promoted']); $this->assertGreaterThanOrEqual(4, $r['files']);
        $this->assertSame($this->root . '/.live', DraftEdits::servedRoot($this->root));
        $this->assertFileExists($this->root . '/.live/index.html'); $this->assertFileExists($this->root . '/.live/about/index.html'); $this->assertFileExists($this->root . '/.live/logo.png');
        $this->assertFileDoesNotExist($this->root . '/.live/.history/index-old.html', 'history never goes live');
    }

    public function test_edits_land_in_the_draft_and_the_change_list_names_them(): void
    {
        DraftEdits::promote(self::SITE_ID);
        $this->assertSame(0, DraftEdits::changes(self::SITE_ID)['count'], 'nothing pending right after a publish');
        // the owner edits the title, hides the sample reviews, swaps the hero picture and adds a page
        file_put_contents($this->root . '/index.html', '<html><body><section data-block="hero"><h1 data-field="hero_title">New title</h1><p data-field="hero_subtitle">Sub</p><img data-field="hero_image" src="/b.jpg"></section><section id="t" data-block="testimonials" style="display:none/*lu-hidden*/" data-lu-hidden="1"><p data-field="testimonial_1_quote">Sample</p></section></body></html>');
        @mkdir($this->root . '/contact', 0775, true); file_put_contents($this->root . '/contact/index.html', '<html><body><h1 data-field="contact_title">Contact</h1></body></html>');
        $c = DraftEdits::changes(self::SITE_ID);
        $this->assertSame(4, $c['count']);
        $this->assertSame(['Contact'], $c['pages_added']);
        $texts = array_values(array_filter($c['fields'], fn ($f) => $f['kind'] === 'text')); $pics = array_values(array_filter($c['fields'], fn ($f) => $f['kind'] === 'picture'));
        $this->assertSame(['Home', 'hero_title', 'Old title', 'New title'], [$texts[0]['page'], $texts[0]['field'], $texts[0]['before'], $texts[0]['after']]);
        $this->assertSame('hero_image', $pics[0]['field']);
        $this->assertSame(['section' => 'testimonials', 'now' => 'hidden'], ['section' => $c['sections'][0]['section'], 'now' => $c['sections'][0]['now']]);
        // visitors still see the old title until Publish changes
        $this->assertStringContainsString('Old title', file_get_contents($this->root . '/.live/index.html'));
        DraftEdits::promote(self::SITE_ID);
        $this->assertStringContainsString('New title', file_get_contents($this->root . '/.live/index.html'));
        $this->assertFileExists($this->root . '/.live/contact/index.html');
        $this->assertSame(0, DraftEdits::changes(self::SITE_ID)['count']);
    }

    public function test_a_menu_page_needs_dishes_first(): void
    {
        $this->assertSame('menu', DraftEdits::pageNeedsItems(self::SITE_ID, 'menu'));
        $this->assertSame('listing', DraftEdits::pageNeedsItems(self::SITE_ID, 'listing_browser'));
        $this->assertNull(DraftEdits::pageNeedsItems(self::SITE_ID, 'about'));
    }
}
