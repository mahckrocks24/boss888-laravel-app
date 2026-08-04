<?php

namespace App\Engines\Studio\Selection;

/**
 * STUDIO888 · Selection — a structured selection query.
 *
 * The renderer-neutral description of WHAT to select. It is expressed in the
 * document's own vocabulary (roles, tags, geometry, relationships) — never as
 * an HTML selector. A future AI planner emits these; a deterministic
 * QueryParser also emits them for common phrases. The Selection Engine and
 * resolvers consume only this object.
 */
final class SelectionQuery
{
    /**
     * @param string|null $role          exact role to match
     * @param string[]    $visualTags    all must be present
     * @param string[]    $semanticTags  all must be present
     * @param string|null $text          case-insensitive substring of element text
     * @param string|null $mediaType     image|video|audio (element style.media_type or media role)
     * @param string|null $region        above_fold|below_fold|top|bottom|left|right
     * @param string|null $superlative   largest|smallest|biggest|tallest|widest (by metric)
     * @param string|null $metric        font_size|area|height|width (defaults per superlative)
     * @param int|null    $ordinal       1-based index in reading order
     * @param bool|null   $visible       filter by visibility
     * @param bool|null   $locked        filter by lock state
     * @param bool|null   $selected      filter by selection state
     * @param string|null $withinId      restrict to descendants of this element
     * @param array|null  $relativeTo    ['relation'=>above|below|left|right,'role'=>anchorRole]
     * @param bool        $expectMultiple  true for group selections ("everything ...")
     */
    public function __construct(
        public readonly ?string $role = null,
        public readonly array   $visualTags = [],
        public readonly array   $semanticTags = [],
        public readonly ?string $text = null,
        public readonly ?string $mediaType = null,
        public readonly ?string $region = null,
        public readonly ?string $superlative = null,
        public readonly ?string $metric = null,
        public readonly ?int    $ordinal = null,
        public readonly ?bool   $visible = null,
        public readonly ?bool   $locked = null,
        public readonly ?bool   $selected = null,
        public readonly ?string $withinId = null,
        public readonly ?array  $relativeTo = null,
        public readonly bool    $expectMultiple = false,
    ) {
    }

    /** How many distinct constraints this query specifies (drives specificity/confidence). */
    public function criterionCount(): int
    {
        $c = 0;
        $c += $this->role !== null ? 1 : 0;
        $c += count($this->visualTags);
        $c += count($this->semanticTags);
        $c += $this->text !== null ? 1 : 0;
        $c += $this->mediaType !== null ? 1 : 0;
        $c += $this->region !== null ? 1 : 0;
        $c += $this->superlative !== null ? 1 : 0;
        $c += $this->ordinal !== null ? 1 : 0;
        $c += $this->relativeTo !== null ? 1 : 0;
        $c += $this->visible !== null ? 1 : 0;
        $c += $this->locked !== null ? 1 : 0;
        $c += $this->selected !== null ? 1 : 0;

        return $c;
    }

    public function withDefaults(array $overrides): self
    {
        return new self(
            role:           $overrides['role'] ?? $this->role,
            visualTags:     $overrides['visualTags'] ?? $this->visualTags,
            semanticTags:   $overrides['semanticTags'] ?? $this->semanticTags,
            text:           $overrides['text'] ?? $this->text,
            mediaType:      $overrides['mediaType'] ?? $this->mediaType,
            region:         $overrides['region'] ?? $this->region,
            superlative:    $overrides['superlative'] ?? $this->superlative,
            metric:         $overrides['metric'] ?? $this->metric,
            ordinal:        $overrides['ordinal'] ?? $this->ordinal,
            visible:        $overrides['visible'] ?? $this->visible,
            locked:         $overrides['locked'] ?? $this->locked,
            selected:       $overrides['selected'] ?? $this->selected,
            withinId:       $overrides['withinId'] ?? $this->withinId,
            relativeTo:     $overrides['relativeTo'] ?? $this->relativeTo,
            expectMultiple: $overrides['expectMultiple'] ?? $this->expectMultiple,
        );
    }
}
