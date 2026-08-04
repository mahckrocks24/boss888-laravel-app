<?php

namespace Tests\Feature\Studio\Document;

use App\Engines\Studio\Document\Bbox;
use App\Engines\Studio\Document\InMemoryStudioElementRepository;
use App\Engines\Studio\Document\SemanticGraph;
use App\Engines\Studio\Document\StudioElement;

/**
 * A renderer-neutral sample design used across Phase-2 tests. Pure data — no
 * HTML, no DB. A hero section (background, hero image, logo, headline, a
 * grouped stat + CTA, a caption) above the fold, and a body section (a section
 * heading, two paragraphs, a locked image) below it.
 */
final class DocumentFixture
{
    public const CANVAS = ['width' => 1080.0, 'height' => 1080.0, 'fold' => 600.0];

    /** @return StudioElement[] */
    public static function elements(): array
    {
        return [
            new StudioElement(id: 'sec_hero', role: 'section', bbox: new Bbox(0, 0, 1080, 600), layer: 0,
                children: ['bg', 'hero_img', 'logo', 'headline', 'group_stats', 'small_note', 'hidden_el', 'badge']),

            new StudioElement(id: 'bg', role: 'background', visualTags: ['red'], bbox: new Bbox(0, 0, 1080, 600),
                parent: 'sec_hero', layer: 0, style: ['background_color' => '#ff0000']),

            new StudioElement(id: 'hero_img', role: 'hero', bbox: new Bbox(540, 50, 500, 500),
                parent: 'sec_hero', layer: 1, style: ['media_type' => 'image']),

            new StudioElement(id: 'logo', role: 'logo', bbox: new Bbox(20, 20, 80, 80),
                parent: 'sec_hero', layer: 4, style: ['media_type' => 'image']),

            new StudioElement(id: 'headline', role: 'headline', text: 'Big Sale', semanticTags: ['title'],
                visualTags: ['big'], bbox: new Bbox(60, 80, 400, 90), parent: 'sec_hero', layer: 2,
                style: ['font_family' => 'Poppins', 'color' => '#111111', 'font_size' => 48],
                capabilities: ['set_text', 'set_style']),

            new StudioElement(id: 'group_stats', role: 'group', bbox: new Bbox(50, 190, 220, 230),
                parent: 'sec_hero', layer: 2, children: ['stat', 'cta']),

            new StudioElement(id: 'stat', role: 'stat', text: '98%', semanticTags: ['metric', 'percentage'],
                visualTags: ['big', 'yellow'], bbox: new Bbox(60, 200, 180, 120), parent: 'group_stats', layer: 3,
                style: ['color' => '#ffd60a', 'font_size' => 72], capabilities: ['set_text', 'set_style']),

            new StudioElement(id: 'cta', role: 'cta', text: 'Buy now', visualTags: ['yellow'],
                bbox: new Bbox(60, 360, 160, 48), parent: 'group_stats', layer: 3,
                style: ['color' => '#ffffff', 'background_color' => '#ffd60a'], capabilities: ['set_text']),

            new StudioElement(id: 'small_note', role: 'caption', text: 'photo credit', visualTags: ['small'],
                bbox: new Bbox(560, 560, 200, 30), parent: 'sec_hero', layer: 2),

            new StudioElement(id: 'hidden_el', role: 'label', text: 'promo', bbox: new Bbox(900, 20, 80, 20),
                parent: 'sec_hero', layer: 6, visible: false),

            new StudioElement(id: 'badge', role: 'label', text: 'NEW', bbox: new Bbox(200, 220, 100, 100),
                parent: 'sec_hero', layer: 5),

            new StudioElement(id: 'sec_body', role: 'section', bbox: new Bbox(0, 600, 1080, 480), layer: 0,
                children: ['sub_head', 'para1', 'para2', 'locked_el']),

            new StudioElement(id: 'sub_head', role: 'headline', text: 'Details', semanticTags: ['title'],
                bbox: new Bbox(60, 640, 300, 50), parent: 'sec_body', layer: 1,
                style: ['font_family' => 'Poppins', 'font_size' => 32]),

            new StudioElement(id: 'para1', role: 'body', text: 'First paragraph text',
                bbox: new Bbox(60, 700, 600, 40), parent: 'sec_body', layer: 1, capabilities: ['set_text']),

            new StudioElement(id: 'para2', role: 'body', text: 'Second paragraph text',
                bbox: new Bbox(60, 760, 600, 40), parent: 'sec_body', layer: 1, capabilities: ['set_text']),

            new StudioElement(id: 'locked_el', role: 'image', bbox: new Bbox(800, 900, 100, 100),
                parent: 'sec_body', layer: 1, locked: true, style: ['media_type' => 'image']),
        ];
    }

    public static function repository(): InMemoryStudioElementRepository
    {
        return new InMemoryStudioElementRepository(self::elements(), self::CANVAS);
    }

    public static function graph(): SemanticGraph
    {
        return SemanticGraph::fromRepository(self::repository());
    }
}
