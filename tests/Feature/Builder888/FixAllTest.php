<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\BuildQuality;
use App\Engines\Builder\Support\DesignStyle;
use App\Engines\Builder\Support\DraftEdits;
use App\Engines\Builder\Support\FontPairs;
use Tests\TestCase;

/**
 * FIX-ALL (RFC-0021 closure, 2026-10-01) — fail-first tests for the items the six waves left open: curated font pairings
 * (FONTS-7), a requested page the catalogue cannot offer is said in the summary, and renderer-served sites get a draft
 * (DRAFT-5b). The twelve pre-existing failures are covered by their own, corrected, tests.
 */
class FixAllTest extends TestCase
{
    private bool $madeSwitch = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! FontPairs::on()) { @touch(storage_path(FontPairs::SWITCH)); $this->madeSwitch = true; }
    }

    protected function tearDown(): void
    {
        if ($this->madeSwitch) @unlink(storage_path(FontPairs::SWITCH));
        parent::tearDown();
    }

    public function test_every_pairing_is_curated_and_loads_only_the_weights_its_faces_have(): void
    {
        $this->assertGreaterThanOrEqual(12, count(FontPairs::all()));
        foreach (FontPairs::all() as $id => $p) {
            foreach (['label', 'note', 'moods', 'display', 'dw', 'body', 'bw', 'display_weight'] as $k) $this->assertNotEmpty($p[$k], "$id.$k");
            foreach ($p['moods'] as $m) $this->assertContains($m, DesignStyle::STYLES, "$id mood $m");
            $this->assertMatchesRegularExpression('/^\d{3}(;\d{3})*$/', $p['dw']); $this->assertMatchesRegularExpression('/^\d{3}(;\d{3})*$/', $p['bw']);
            $this->assertContains($p['display_weight'], explode(';', $p['dw']), "$id: the heading weight is one the face is loaded at");
            $layer = FontPairs::layerFor($id, null, ['accent' => '#B05020']);
            $this->assertStringContainsString('fonts.googleapis.com/css2?family=' . str_replace(' ', '+', $p['display']) . ':wght@' . $p['dw'], $layer, "$id loads its own display weights");
            $this->assertStringContainsString("--ds-font-display:'" . htmlspecialchars($p['display'], ENT_QUOTES) . "'", $layer);
            $this->assertStringContainsString('id="lug-design-style"', $layer, 'the same layer slot the style path writes, so one replaces the other');
        }
        $this->assertSame('', FontPairs::layerFor('no_such_pair', null));
        $this->assertSame(FontPairs::DESIGN, FontPairs::currentFor([]));
        $this->assertSame('custom', FontPairs::currentFor(['font_display' => 'Lobster']));
        $this->assertSame('lora_lato', FontPairs::currentFor(['font_pair' => 'lora_lato', 'font_display' => 'Lora']));
    }

    public function test_a_pairing_changes_the_faces_and_leaves_the_designs_shapes_alone(): void
    {
        $fontsOnly = FontPairs::layerFor('spacegrotesk_inter', null, ['accent' => '#1A2744']);
        $this->assertStringNotContainsString('border-radius', $fontsOnly, 'no radius rule without a style preset');
        $this->assertStringNotContainsString('text-transform:uppercase', $fontsOnly, 'no eyebrow rule without a style preset');
        $this->assertStringNotContainsString('background', $fontsOnly, 'no colour treatment without a style preset');
        $this->assertMatchesRegularExpression('/h1,h2,h3,h4[^{]*\{font-family:var\(--ds-font-display\)/', $fontsOnly);
        // a site built with a style keeps that style's shapes and swaps the faces
        $styled = FontPairs::layerFor('spacegrotesk_inter', 'playful', ['accent' => '#1A2744']);
        $this->assertStringContainsString('border-radius:var(--ds-radius)', $styled);
        $this->assertStringContainsString("--ds-font-display:'Space Grotesk'", $styled);
        // and DesignStyle itself: fonts named in words without a style are fonts only while the switch is on
        $this->assertStringNotContainsString('border-radius', DesignStyle::layer(null, 'Lora', 'Lato'));
        $this->assertStringContainsString('border-radius', DesignStyle::layer('modern', 'Lora', 'Lato'));
        // the recommendation follows the mood; there is always a sensible set
        $this->assertContains('cormorant_inter', FontPairs::recommended('luxury'));
        $this->assertNotEmpty(FontPairs::recommended(null));
        $this->assertNotEmpty(FontPairs::recommended('something else'));
        // one stylesheet for the panel: every family once
        $css = FontPairs::previewStylesheet();
        $this->assertSame(1, substr_count($css, 'family=Inter:'), 'Inter is requested once with its weights merged');
        $this->assertStringContainsString('family=Inter:wght@300;400;500;600;700', $css);
    }

    public function test_a_requested_page_the_catalogue_cannot_offer_is_named_in_the_summary(): void
    {
        $this->assertSame(['Cakes to Order'], BuildQuality::unsupportedPages(['Home', 'Menu', 'Cakes to Order', 'Contact', 'Blog'], 'cafe'));
        $this->assertSame([], BuildQuality::unsupportedPages(['Menu', 'About', 'Contact'], 'cafe'));
        $reply = "Here is what I have:\n**Business:** Crumb and Co\n**Pages:** Home, Menu, Cakes to Order, Contact\n\nAdd your logo, photos, and brand colors below — then I'll build it.";
        $out = BuildQuality::pagesNote($reply, ['Cakes to Order']);
        $this->assertStringContainsString("**Pages:** Home, Menu, Cakes to Order, Contact\n_I have no page design for “Cakes to Order” yet, so it is not in this build — once the site is up, ask me and I will find the closest fit._\n\nAdd your logo", $out);
        $this->assertSame($out, BuildQuality::pagesNote($out, ['Cakes to Order']), 'idempotent');
        $two = BuildQuality::pagesNote('No pages line here.', ['Cakes to Order', 'Wholesale']);
        $this->assertStringContainsString('I have no page designs for “Cakes to Order” and “Wholesale” yet, so they are not in this build', $two);
        $this->assertSame($reply, BuildQuality::pagesNote($reply, []));
    }

    public function test_a_home_page_section_is_not_a_page(): void
    {
        $id = 999999906; $root = storage_path("app/public/sites/{$id}");
        @mkdir($root . '/services', 0775, true); file_put_contents($root . '/services/index.html', '<html></html>');
        try {
            // the words ask for a page the cafe catalogue offers, the site has none → page_add whatever the model said
            $this->assertSame('menu', BuildQuality::pageRequestOverride('add a menu page', 'answer', 'cafe', $id));
            $this->assertSame('menu', BuildQuality::pageRequestOverride('I want a menu page please', 'section_add', 'cafe', $id));
            // the page already exists → the model's reading stands
            $this->assertNull(BuildQuality::pageRequestOverride('add a services page', 'answer', 'cafe', $id));
            // no "page" in the words, or the model already said page_add / an edit → untouched
            $this->assertNull(BuildQuality::pageRequestOverride('add a menu section after services', 'section_add', 'cafe', $id));
            $this->assertNull(BuildQuality::pageRequestOverride('add a menu page', 'page_add', 'cafe', $id));
            $this->assertNull(BuildQuality::pageRequestOverride('change the page title to Welcome', 'copy_edit', 'cafe', $id));
        } finally { @unlink($root . '/services/index.html'); @rmdir($root . '/services'); @rmdir($root); }
    }

    public function test_a_renderer_sites_draft_is_compared_section_by_section(): void
    {
        $live  = json_encode(['schemaVersion' => 2, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Welcome to AMG', 'image' => '/storage/a.jpg']], ['type' => 'cta', 'data' => ['text' => 'Call us']]]]);
        $draft = json_encode(['schemaVersion' => 2, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Welcome to AMG Motors', 'image' => '/storage/b.jpg']], ['type' => 'cta', 'data' => ['text' => 'Call us']]]]);
        $d = DraftEdits::diffSectionTexts($live, $draft);
        $this->assertCount(2, $d);
        $this->assertSame(['field' => 'hero.data.heading', 'kind' => 'text', 'before' => 'Welcome to AMG', 'after' => 'Welcome to AMG Motors'], $d[0]);
        $this->assertSame('picture', $d[1]['kind']);
        $this->assertSame([], DraftEdits::diffSectionTexts($live, $live));
        $this->assertSame([], DraftEdits::sectionTexts('not json'));
        $this->assertFalse(DraftEdits::rendererDraft(0));
        $this->assertFalse(DraftEdits::rendererDraftForPage(0));
    }
}
