<?php

namespace Tests\Feature\Studio\Document;

use App\Engines\Studio\Document\RelationType;
use PHPUnit\Framework\TestCase;

/** Pure — graph is derived from StudioElements only; no HTML anywhere. */
final class SemanticGraphTest extends TestCase
{
    public function test_structural_contains_and_belongs_to(): void
    {
        $g = DocumentFixture::graph();
        $this->assertTrue($g->hasRelation('sec_hero', RelationType::CONTAINS, 'headline'));
        $this->assertTrue($g->hasRelation('headline', RelationType::BELONGS_TO, 'sec_hero'));
    }

    public function test_grouped_with_between_group_members(): void
    {
        $g = DocumentFixture::graph();
        $this->assertTrue($g->hasRelation('stat', RelationType::GROUPED_WITH, 'cta'));
        $this->assertTrue($g->hasRelation('cta', RelationType::GROUPED_WITH, 'stat'));
    }

    public function test_role_meaning_edges(): void
    {
        $g = DocumentFixture::graph();
        $this->assertTrue($g->hasRelation('headline', RelationType::HEADLINE_OF, 'sec_hero'));
        $this->assertTrue($g->hasRelation('cta', RelationType::BUTTON_FOR, 'group_stats'));
        $this->assertTrue($g->hasRelation('hero_img', RelationType::IMAGE_OF, 'sec_hero'));
        $this->assertTrue($g->hasRelation('bg', RelationType::BACKGROUND_OF, 'sec_hero'));
        $this->assertTrue($g->hasRelation('small_note', RelationType::CAPTION_OF, 'hero_img'));
    }

    public function test_spatial_overlap_edge(): void
    {
        $g = DocumentFixture::graph();
        $this->assertTrue($g->hasRelation('badge', RelationType::OVERLAPS, 'stat'));
        $this->assertTrue($g->hasRelation('stat', RelationType::OVERLAPS, 'badge'));
    }

    public function test_spatial_above_below(): void
    {
        $g = DocumentFixture::graph();
        $this->assertTrue($g->hasRelation('headline', RelationType::ABOVE, 'stat'));
        $this->assertTrue($g->hasRelation('stat', RelationType::BELOW, 'headline'));
    }

    public function test_related_traversal_returns_elements(): void
    {
        $g = DocumentFixture::graph();
        $children = $g->related('sec_body', RelationType::CONTAINS);
        $ids = array_map(fn ($e) => $e->id, $children);
        $this->assertContains('para1', $ids);
        $this->assertContains('para2', $ids);
    }

    public function test_layer_ordering_is_preserved(): void
    {
        $els = DocumentFixture::graph()->elements();
        usort($els, fn ($a, $b) => $b->layer <=> $a->layer);
        $this->assertSame('hidden_el', $els[0]->id); // layer 6 = frontmost
        $this->assertSame('badge', $els[1]->id);     // layer 5
    }

    public function test_canvas_facts_are_renderer_neutral(): void
    {
        $c = DocumentFixture::graph()->canvas();
        $this->assertSame(1080.0, $c['width']);
        $this->assertSame(600.0, $c['fold']);
    }
}
