<?php

namespace App\Engines\Builder\Schema;

/**
 * SectionSchema — formal contract for sections_json content.
 *
 * Patch 8.5 (2026-05-08). Defines the 15 section types Arthur is allowed
 * to produce + the field rules per type + the allowed mutation ops.
 *
 * IMPORTANT — flat field shape:
 *   Sections are stored FLAT in pages.sections_json:
 *     {"type": "hero", "heading": "...", "body": "..."}
 *   NOT nested under a `data` key. This matches the existing data on
 *   page id=2 (the only meaningful real example), what BuilderRenderer
 *   actually reads, and what ArthurService::buildDefaultSectionsForPage
 *   writes (Patch 8 Tier 1).
 *
 * The renderer is canonical. If a future schema migration moves to nested
 * `data`, BuilderRenderer must change in lockstep.
 */
class SectionSchema
{
    /**
     * Allowed section types + their field rules.
     * 'required' = must be present and non-empty
     * 'optional' = may be present
     */
    private static array $schema = [
        'header' => [
            'optional' => ['logo_text', 'logo_url', 'nav_links', 'cta_text', 'cta_url', 'components'],
        ],
        'hero' => [
            'required' => ['heading'],
            'optional' => ['subheading', 'body', 'cta_text', 'cta_url', 'cta_secondary_text', 'cta_secondary_url', 'background_image', 'overlay_opacity', 'image'],
        ],
        'features' => [
            'required' => ['heading'],
            'optional' => ['body', 'items', 'columns', 'subheading'],
        ],
        'cta' => [
            'required' => ['heading'],
            'optional' => ['body', 'cta_text', 'cta_url', 'background_color', 'subheading'],
        ],
        'contact_form' => [
            'optional' => ['heading', 'body', 'submit_label', 'fields', 'subheading'],
        ],
        'blog_list' => [
            'optional' => ['heading', 'max_posts', 'subheading', 'body'],
        ],
        'footer' => [
            'optional' => ['columns', 'copyright', 'social_links', 'links'],
        ],
        'gallery' => [
            'optional' => ['heading', 'images', 'columns', 'style', 'body'],
        ],
        'services' => [
            'required' => ['heading'],
            'optional' => ['body', 'items', 'subheading', 'columns'],
        ],
        'team' => [
            'optional' => ['heading', 'members', 'body'],
        ],
        'testimonials' => [
            'optional' => ['heading', 'items', 'body'],
        ],
        'faq' => [
            'optional' => ['heading', 'items', 'body'],
        ],
        'pricing' => [
            'optional' => ['heading', 'tiers', 'body'],
        ],
        'stats' => [
            'optional' => ['heading', 'items', 'body'],
        ],
        'generic' => [
            'optional' => ['heading', 'body', 'content'],
        ],
    ];

    public static function allowedTypes(): array
    {
        return array_keys(self::$schema);
    }

    /**
     * Validate a section against its type's field rules.
     * Returns ['ok' => bool, 'errors' => string[]?].
     */
    public static function validate(array $section): array
    {
        $type = $section['type'] ?? null;

        if (! $type) {
            return ['ok' => false, 'errors' => ['Missing section type']];
        }

        // Generic and unknown types pass — graceful fallback.
        if ($type === 'generic' || ! isset(self::$schema[$type])) {
            return ['ok' => true];
        }

        $rules  = self::$schema[$type];
        $errors = [];

        foreach ($rules['required'] ?? [] as $field) {
            $val = $section[$field] ?? null;
            if ($val === null || $val === '' || (is_array($val) && empty($val))) {
                $errors[] = "Required field '{$field}' missing or empty for type '{$type}'";
            }
        }

        return $errors === []
            ? ['ok' => true]
            : ['ok' => false, 'errors' => $errors];
    }

    public static function isKnownType(string $type): bool
    {
        return isset(self::$schema[$type]) || $type === 'generic';
    }

    /**
     * Mutation ops Arthur is allowed to emit.
     */
    public static function allowedOps(): array
    {
        return [
            'update_text',     // update heading/body/any text field
            'update_field',    // update any allowed field in a section
            'update_image',    // update image_url / background_image
            'add_section',     // append or insert a new section
            'remove_section',  // remove section by index
            'reorder_section', // move section from index A to index B
        ];
    }

    /**
     * Allowed fields for a section type, used to gate update_field writes.
     */
    public static function allowedFieldsFor(string $type): array
    {
        if (! isset(self::$schema[$type])) return [];
        $rules = self::$schema[$type];
        return array_merge($rules['required'] ?? [], $rules['optional'] ?? []);
    }
}
