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
            'optional' => ['eyebrow', 'subheading', 'body', 'cta_text', 'cta_url', 'cta_secondary_text', 'cta_secondary_url', 'background_image', 'overlay_opacity', 'image'],
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
            'optional' => ['heading', 'body', 'submit_label', 'fields', 'subheading', 'phone', 'email', 'address'],
        ],
        'blog_list' => [
            'optional' => ['heading', 'max_posts', 'subheading', 'body'],
        ],
        'footer' => [
            'optional' => ['columns', 'copyright', 'social_links', 'links', 'phone', 'email', 'address'],
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
        // ─── v1.4.4 Phase D-2 (2026-05-30) — booking + events ───────
        // booking_form: reservation / appointment widget. Renders as a
        // structured form with optional service selector, date+time, and
        // contact fields. Backend submission goes through the contact_form
        // pipeline today; a dedicated booking pipeline can swap later.
        'booking_form' => [
            'optional' => [
                'heading', 'subheading', 'body',
                'submit_label', 'success_message',
                'fields',                // array of {name,label,type,required,options?}
                'services',              // array of service strings for a service-selector dropdown
                'duration_options',      // array of duration choices (e.g. [15,30,60])
                'show_calendar',         // bool — whether to render a date picker
                'show_time_slots',       // bool — whether to render time-slot chips
                'background_image',
            ],
        ],
        // events_calendar: upcoming events / classes / shows. Industries:
        // event_venue, training_center, online_courses, hotel, resort,
        // cafe, restaurant, gym (class schedule), news_channel.
        'events_calendar' => [
            'optional' => [
                'heading', 'subheading', 'body',
                'events',                // array of {title, date, time, location, description, image, cta_text, cta_url}
                'view',                  // 'list' | 'grid' | 'calendar'
                'max_events',            // int — how many to show inline (defaults to all)
                'cta_text', 'cta_url',
            ],
        ],
        // ─── v1.4.4 Phase D-3 (2026-05-30) — listings / map / trust ─
        // grid: generic card-grid section. Items shape:
        //   {title, subtitle, image, price, badge, cta_text, cta_url, tags?}
        // Powers property listings, product grids, room types, vehicles,
        // course catalogues. Distinct from `features` (which is icon+text).
        'grid' => [
            'optional' => [
                'heading', 'subheading', 'body',
                'items',                 // array of item cards
                'columns',               // int 2-4 (defaults to 3)
                'style',                 // 'card' | 'media' | 'compact'
                'cta_text', 'cta_url',
            ],
        ],
        // filter_bar: client-side filter chips that target a grid by id.
        // Powers property search, product filters, course filters.
        'filter_bar' => [
            'optional' => [
                'heading',
                'target_grid_id',        // string — id of the grid section to filter
                'filters',               // array of {label, options[]}
                'search_enabled',        // bool — show free-text search box
                'sort_options',          // array of strings (e.g. ['Newest', 'Price low to high'])
            ],
        ],
        // map: embed a static map illustration with location pins.
        // Powers locations page, store finder, branch listings.
        'map' => [
            'optional' => [
                'heading', 'subheading', 'body',
                'locations',             // array of {name, address, phone, hours, lat?, lng?}
                'embed_url',             // optional iframe src for live map (Mapbox/Google)
                'height',                // px height (default 480)
            ],
        ],
        // related_listings: smaller card-grid for cross-sell on detail pages.
        // Same item shape as `grid` but defaults to 3 columns and compact style.
        'related_listings' => [
            'optional' => [
                'heading', 'subheading',
                'items',
                'max_items',             // int (default 6)
            ],
        ],
        // trust_signals: social proof bar — logos, badges, awards, stats.
        // Compact row, sits under hero or above footer.
        'trust_signals' => [
            'optional' => [
                'heading', 'subheading',
                'items',                 // array of {label, logo?, value?, sublabel?}
                'style',                 // 'logo_row' | 'stat_row' | 'badge_row'
            ],
        ],
        // ─── v1.4.4 Phase D-5 (2026-05-30) — commerce / account ────
        // cart_summary: shopping cart line items + totals + checkout CTA.
        'cart_summary' => [
            'optional' => [
                'heading', 'subheading',
                'items',                 // array of {name, qty, price, subtotal, image?}
                'subtotal_label', 'tax_label', 'shipping_label', 'total_label',
                'subtotal', 'tax', 'shipping', 'total',
                'currency',
                'cta_text', 'cta_url',
                'continue_shopping_url',
                'empty_message',
            ],
        ],
        // checkout_form: multi-section checkout (contact, shipping, payment).
        'checkout_form' => [
            'optional' => [
                'heading', 'subheading',
                'sections',              // array of {title, fields[]}
                'order_summary',         // optional sidebar summary {items[], total}
                'submit_label',
                'success_message',
                'payment_methods',       // array of method labels (display only)
            ],
        ],
        // account_nav: vertical or top nav for /account pages.
        'account_nav' => [
            'optional' => [
                'heading',               // e.g. "Welcome back, {name}"
                'subheading',            // e.g. email
                'items',                 // array of {label, url, active?}
                'orientation',           // 'vertical' | 'top'
                'logout_url',
            ],
        ],
        // account_panel: dashboard panel for orders / addresses / profile.
        'account_panel' => [
            'optional' => [
                'heading', 'subheading',
                'panel_type',            // 'orders' | 'addresses' | 'profile' | 'wishlist'
                'items',                 // panel-specific list (orders/addresses/etc)
                'empty_message',
                'cta_text', 'cta_url',
            ],
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
            'update_style',    // change site colours (:root vars / settings_json)
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
