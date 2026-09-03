<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\BuilderEditPromotion;
use Tests\TestCase;

/**
 * ARTHUR888 F-ARTHUR-B-COORD (2026-09-03): a concrete page edit / page-add request
 * must be detected for deterministic promotion to Arthur; blog/article and vague
 * non-builder messages must NOT be (they belong to other lanes).
 */
class BuilderEditPromotionTest extends TestCase
{
    public function test_headline_edit_detected_as_edit_home(): void
    {
        $d = BuilderEditPromotion::detect('Change the homepage headline to Stone-Baked Since Dawn.');
        $this->assertNotNull($d);
        $this->assertSame('edit', $d['type']);
        $this->assertSame('home', $d['page_hint']);
    }

    public function test_hero_rewrite_detected(): void
    {
        $d = BuilderEditPromotion::detect('Rewrite the hero section to be more premium.');
        $this->assertNotNull($d);
        $this->assertSame('edit', $d['type']);
    }

    public function test_add_contact_page_detected_with_template(): void
    {
        $d = BuilderEditPromotion::detect('Add a contact page to my site.');
        $this->assertNotNull($d);
        $this->assertSame('add', $d['type']);
        $this->assertSame('contact', $d['page_template']);
    }

    public function test_add_about_page_detected(): void
    {
        $d = BuilderEditPromotion::detect('Create an about page.');
        $this->assertNotNull($d);
        $this->assertSame('add', $d['type']);
        $this->assertSame('about', $d['page_template']);
    }

    public function test_blog_article_not_promoted(): void
    {
        $this->assertNull(BuilderEditPromotion::detect('Write a blog article about focaccia.'));
        $this->assertNull(BuilderEditPromotion::detect('Draft a newsletter for my subscribers.'));
    }

    public function test_image_and_logo_not_promoted_to_builder(): void
    {
        // Creative888 owns pixel generation — must NOT be caught by the builder lane.
        $this->assertNull(BuilderEditPromotion::detect('Generate a hero image for my homepage.'));
        $this->assertNull(BuilderEditPromotion::detect('Create a logo for Fable QA Bakery.'));
    }

    public function test_colour_and_typography_are_style_not_section_edit(): void
    {
        // F-ARTHUR-C-COLOR: colour/typography must NOT route to the section editor (no-op).
        $c = BuilderEditPromotion::detect('Change the homepage colours to navy and gold.');
        $this->assertNotNull($c);
        $this->assertSame('style', $c['type']);
        $t = BuilderEditPromotion::detect('Update the font/typography on my site.');
        $this->assertNotNull($t);
        $this->assertSame('style', $t['type']);
    }

    public function test_vague_nonbuilder_not_promoted(): void
    {
        $this->assertNull(BuilderEditPromotion::detect('Update me on progress.'));
        $this->assertNull(BuilderEditPromotion::detect('Change my strategy for this quarter.'));
    }
}
