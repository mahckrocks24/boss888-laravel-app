<?php

namespace App\Engines\Studio\Selection;

/**
 * STUDIO888 · Selection — deterministic phrase → SelectionQuery parser.
 *
 * A pure, rule-based translator for common design phrases ("the yellow number",
 * "the biggest heading", "everything above the fold"). It is a DETERMINISTIC
 * STAND-IN for the future AI planner: same input → same query, no Runtime, no
 * provider, no network. It maps only into the document's own vocabulary
 * (roles, tags, geometry) — it never emits an HTML selector.
 */
final class QueryParser
{
    private const COLORS = [
        'yellow', 'red', 'blue', 'green', 'gold', 'orange', 'purple', 'pink',
        'black', 'white', 'gray', 'grey', 'teal', 'navy', 'cyan', 'magenta',
    ];

    private const ORDINALS = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5];

    public function parse(string $phrase): SelectionQuery
    {
        $p = strtolower(trim($phrase));

        $expectMultiple = (bool) preg_match('/\b(everything|every|all)\b/', $p);
        $visualTags = [];
        $semanticTags = [];
        $role = null;
        $mediaType = null;
        $region = null;
        $superlative = null;
        $ordinal = null;
        $relativeTo = null;

        // colours → visual tags
        foreach (self::COLORS as $c) {
            if (preg_match('/\b' . $c . '\b/', $p)) {
                $visualTags[] = $c;
            }
        }

        // size adjectives
        if (preg_match('/\b(big|large)\b/', $p)) {
            $visualTags[] = 'big';
        }
        if (preg_match('/\b(small|tiny|little)\b/', $p)) {
            $visualTags[] = 'small';
        }

        // region
        if (str_contains($p, 'above the fold')) {
            $region = 'above_fold';
        } elseif (str_contains($p, 'below the fold')) {
            $region = 'below_fold';
        }

        // superlative
        if (preg_match('/\b(biggest|largest)\b/', $p)) {
            $superlative = 'largest';
        } elseif (preg_match('/\bsmallest\b/', $p)) {
            $superlative = 'smallest';
        } elseif (preg_match('/\btallest\b/', $p)) {
            $superlative = 'tallest';
        } elseif (preg_match('/\bwidest\b/', $p)) {
            $superlative = 'widest';
        }

        // ordinal (words or "2nd")
        foreach (self::ORDINALS as $word => $num) {
            if (preg_match('/\b' . $word . '\b/', $p)) {
                $ordinal = $num;
            }
        }
        if ($ordinal === null && preg_match('/\b(\d+)(st|nd|rd|th)\b/', $p, $m)) {
            $ordinal = (int) $m[1];
        }

        // spatial-relative ("below the image", "above the logo", "left of the button")
        if (preg_match('/\b(below|above|left of|right of)\b(?:\s+the)?\s+(image|photo|picture|logo|hero|button|cta|headline|heading|text)\b/', $p, $m)) {
            $rel = str_replace(' of', '', $m[1]);
            $relRole = $this->roleForNoun($m[2]);
            if ($relRole !== null && ! ($m[2] === 'text' && $rel === 'below' && $region !== null)) {
                $relativeTo = ['relation' => $rel, 'role' => $relRole];
            }
        }

        // primary noun → role / media / semantic tag
        if (preg_match('/\b(hero)\b/', $p)) {
            $role = 'hero';
        } elseif (preg_match('/\b(logo)\b/', $p)) {
            $role = 'logo';
        } elseif (preg_match('/\b(background)\b/', $p)) {
            $role = 'background';
        } elseif (preg_match('/\b(button|cta)\b/', $p)) {
            $role = 'cta';
        } elseif (preg_match('/\b(image|photo|picture)\b/', $p)) {
            $mediaType = 'image';
        } elseif (preg_match('/\b(number|stat|metric)\b/', $p)) {
            $semanticTags[] = 'metric';
        } elseif (preg_match('/\b(percentage|percent)\b/', $p)) {
            $semanticTags[] = 'percentage';
        } elseif (preg_match('/\b(title)\b/', $p)) {
            $semanticTags[] = 'title';
        } elseif (preg_match('/\b(headline|heading)\b/', $p)) {
            $role = 'headline';
        } elseif (preg_match('/\b(paragraph|body)\b/', $p)) {
            $role = 'body';
        } elseif (preg_match('/\btext\b/', $p)) {
            $role = 'body';
        }

        return new SelectionQuery(
            role: $role,
            visualTags: array_values(array_unique($visualTags)),
            semanticTags: array_values(array_unique($semanticTags)),
            mediaType: $mediaType,
            region: $region,
            superlative: $superlative,
            ordinal: $ordinal,
            relativeTo: $relativeTo,
            expectMultiple: $expectMultiple,
        );
    }

    private function roleForNoun(string $noun): ?string
    {
        return match ($noun) {
            'image', 'photo', 'picture' => 'image',
            'logo'                      => 'logo',
            'hero'                      => 'hero',
            'button', 'cta'             => 'cta',
            'headline', 'heading'       => 'headline',
            default                     => null,
        };
    }
}
