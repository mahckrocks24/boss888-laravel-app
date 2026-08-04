<?php

namespace App\Engines\Studio\Document;

/**
 * STUDIO888 · Studio Document Model — semantic relation vocabulary.
 *
 * The typed edges of the Semantic Graph. These describe MEANING and structure
 * between elements — not DOM parentage. New relation types are added here;
 * traversal code keys off these constants, never off HTML.
 */
final class RelationType
{
    // Structural
    public const CONTAINS    = 'contains';
    public const BELONGS_TO  = 'belongs_to';
    public const GROUPED_WITH = 'grouped_with';

    // Spatial
    public const ADJACENT_TO = 'adjacent_to';
    public const OVERLAPS    = 'overlaps';
    public const ABOVE       = 'above';
    public const BELOW       = 'below';
    public const LEFT_OF     = 'left_of';
    public const RIGHT_OF    = 'right_of';

    // Role / meaning
    public const BACKGROUND_OF = 'background_of';
    public const CAPTION_OF    = 'caption_of';
    public const HEADLINE_OF   = 'headline_of';
    public const BUTTON_FOR    = 'button_for';
    public const IMAGE_OF      = 'image_of';

    /** @return string[] all relation types (for validation/iteration). */
    public static function all(): array
    {
        return [
            self::CONTAINS, self::BELONGS_TO, self::GROUPED_WITH,
            self::ADJACENT_TO, self::OVERLAPS, self::ABOVE, self::BELOW, self::LEFT_OF, self::RIGHT_OF,
            self::BACKGROUND_OF, self::CAPTION_OF, self::HEADLINE_OF, self::BUTTON_FOR, self::IMAGE_OF,
        ];
    }
}
