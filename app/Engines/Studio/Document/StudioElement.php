<?php

namespace App\Engines\Studio\Document;

/**
 * STUDIO888 · Studio Document Model — canonical element.
 *
 * The atom the whole engine reasons about. A StudioElement describes an object
 * the way a designer sees it — role, meaning, visual character, geometry,
 * relationships — with NO reference to HTML, CSS, DOM nodes, or selectors. It
 * is renderer-neutral: the same element could be projected to HTML, Canvas,
 * SVG, PDF, a video timeline, or native mobile.
 *
 * `confidence` is how sure we are that this element's METADATA is correct
 * (e.g. an inferred role/tag). Selection layers combine it with match quality.
 */
final class StudioElement
{
    /**
     * @param string   $id
     * @param string   $role           headline|body|cta|stat|logo|hero|image|background|section|group|label|caption|...
     * @param string|null $text         content text, if any (used for text/ordinal queries)
     * @param string[] $visualTags      big|small|bold|yellow|red|rounded|uppercase|...
     * @param string[] $semanticTags    metric|percentage|price|title|legal|date|...
     * @param Bbox     $bbox            geometry in canvas coordinates
     * @param string|null $parent       parent element id
     * @param string[] $children        child element ids
     * @param int      $layer           z-order (higher = front)
     * @param bool     $visible
     * @param bool     $locked
     * @param bool     $selected
     * @param array    $style           renderer-neutral style facts (color, background_color, font_size, font_weight, font_family, media_type, ...)
     * @param string[] $capabilities    operation types applicable to this element
     * @param array    $relationships   explicit relations: [ ['type'=>RelationType::*, 'target'=>id], ... ]
     * @param array    $state           free-form additional state
     * @param float    $confidence      metadata confidence 0..1
     */
    public function __construct(
        public readonly string  $id,
        public readonly string  $role,
        public readonly ?string $text = null,
        public readonly array   $visualTags = [],
        public readonly array   $semanticTags = [],
        public readonly Bbox    $bbox = new Bbox(0, 0, 0, 0),
        public readonly ?string $parent = null,
        public readonly array   $children = [],
        public readonly int     $layer = 0,
        public readonly bool    $visible = true,
        public readonly bool    $locked = false,
        public readonly bool    $selected = false,
        public readonly array   $style = [],
        public readonly array   $capabilities = [],
        public readonly array   $relationships = [],
        public readonly array   $state = [],
        public readonly float   $confidence = 1.0,
    ) {
    }

    public function hasVisualTag(string $tag): bool
    {
        return in_array($tag, $this->visualTags, true);
    }

    public function hasSemanticTag(string $tag): bool
    {
        return in_array($tag, $this->semanticTags, true);
    }

    /** A renderer-neutral style fact, or null. */
    public function style(string $key): mixed
    {
        return $this->style[$key] ?? null;
    }

    public function fontSize(): ?float
    {
        $v = $this->style['font_size'] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }
}
