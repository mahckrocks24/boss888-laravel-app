<?php

namespace App\Engines\Builder\Support;

/**
 * CATALOGUE888 kind registry (DEC-0049, 2026-09-14). The differences between what a business sells are DATA:
 * which attributes an item has, which template slot suffixes it feeds, which statuses make sense, what the button
 * says, what the customer calls it in chat, and what the tab is called in each industry. One entry per kind; the
 * engine (CatalogueService) is the same for all of them.
 *
 *   family        the repeated variable family in the design ("service" → service_1_title, service_2_title …)
 *   attrs         kind-specific fields: key, label, type (text|number|select|textarea), options, from (template suffixes)
 *   pages         'index+detail' | 'index' | 'none'
 *   open/closed   statuses shown in the home block / in the closed row (sold, let …); the rest are hidden
 *   industries    per-industry overrides: [plural label, singular, page slug, chat nouns]
 */
final class CatalogueKinds
{
    public const KINDS = [
        'listing' => [
            'family' => 'listing', 'plural' => 'Listings', 'singular' => 'listing', 'page_slug' => 'listings', 'detail_prefix' => 'property',
            'pages' => 'index+detail', 'cta' => 'View details', 'enquiry_source' => 'listing_enquiry',
            'statuses' => ['for_sale' => 'For Sale', 'to_let' => 'To Let', 'under_offer' => 'Under Offer', 'sold' => 'Sold', 'let' => 'Let', 'withdrawn' => 'Withdrawn'],
            'open' => ['for_sale', 'to_let', 'under_offer'], 'closed' => ['sold', 'let'], 'default_status' => 'for_sale',
            'closed_label' => 'Recently sold & let', 'closed_family_default' => 'portfolio',
            'attrs' => [
                ['key' => 'location', 'label' => 'Location', 'type' => 'text', 'from' => ['location', 'area']],
                ['key' => 'property_type', 'label' => 'Property type', 'type' => 'text'],
                ['key' => 'beds', 'label' => 'Beds', 'type' => 'number', 'from' => ['beds']],
                ['key' => 'baths', 'label' => 'Baths', 'type' => 'number', 'from' => ['baths']],
                ['key' => 'size_value', 'label' => 'Size', 'type' => 'number', 'from' => ['sqft']],
                ['key' => 'size_unit', 'label' => 'Size unit', 'type' => 'select', 'options' => ['sq ft', 'sq m', 'acres'], 'default' => 'sq ft'],
                ['key' => 'specs_text', 'label' => 'Details line (replaces beds • baths • size)', 'type' => 'text', 'from' => ['specs']],
            ],
            'nouns' => 'listing|listings|property|properties|house|houses|home|homes|apartment|apartments|flat|flats|condo|condos|villa|villas|townhouse|townhome|bungalow|penthouse|duplex|cottage|plot|land|unit',
            'industries' => [],
        ],
        'service' => [
            'family' => 'service', 'plural' => 'Services & prices', 'singular' => 'service', 'page_slug' => 'services', 'detail_prefix' => 'service',
            'pages' => 'index', 'cta' => 'Enquire', 'enquiry_source' => 'service_enquiry',
            'statuses' => ['active' => 'Available', 'hidden' => 'Hidden'], 'open' => ['active'], 'closed' => [], 'default_status' => 'active',
            'closed_label' => '', 'closed_family_default' => '',
            'attrs' => [
                ['key' => 'duration', 'label' => 'Duration (e.g. 60 min)', 'type' => 'text', 'from' => ['duration']],
            ],
            'nouns' => 'service|services|treatment|treatments|package|packages|offering|offerings',
            'industries' => [
                'restaurant'       => ['Menu', 'dish', 'menu', 'dish|dishes|menu item|menu items|meal|meals|plate|plates|starter|starters|main|mains|dessert|desserts|special|specials'],
                'cafe'             => ['Menu', 'item', 'menu', 'dish|dishes|menu item|menu items|pastry|pastries|cake|cakes|coffee|coffees|drink|drinks|special|specials'],
                'catering'         => ['Packages', 'package', 'packages', 'package|packages|menu|menus|dish|dishes'],
                'retail_shop'      => ['Products', 'product', 'products', 'product|products|item|items'],
                'ecommerce'        => ['Products', 'product', 'products', 'product|products|item|items'],
                'aesthetic_clinic' => ['Treatments', 'treatment', 'treatments', 'treatment|treatments|procedure|procedures|service|services'],
                'beauty_salon'     => ['Treatments', 'treatment', 'treatments', 'treatment|treatments|service|services'],
                'barbershop'       => ['Services & prices', 'service', 'services', 'service|services|cut|cuts|shave|shaves|trim|trims'],
                'dental'           => ['Treatments', 'treatment', 'treatments', 'treatment|treatments|procedure|procedures|service|services'],
                'medical_clinic'   => ['Services', 'service', 'services', 'service|services|treatment|treatments|consultation|consultations|procedure|procedures'],
                'online_courses'   => ['Courses', 'course', 'courses', 'course|courses|class|classes|lesson|lessons|programme|program|programs'],
                'tutoring'         => ['Courses', 'course', 'courses', 'course|courses|class|classes|lesson|lessons|subject|subjects'],
                'training_center'  => ['Courses', 'course', 'courses', 'course|courses|class|classes|programme|program|programs|workshop|workshops'],
                'hotel'            => ['Rooms & rates', 'room', 'rooms', 'room|rooms|suite|suites|rate|rates'],
                'resort'           => ['Rooms & rates', 'room', 'rooms', 'room|rooms|suite|suites|villa|villas|rate|rates'],
                'short_term_rental'=> ['Rentals', 'rental', 'rentals', 'rental|rentals|property|properties|unit|units|apartment|apartments|home|homes'],
                'gym'              => ['Programmes', 'programme', 'programmes', 'programme|programmes|program|programs|training|workout|workouts'],
                'travel_agency'    => ['Trips', 'trip', 'trips', 'trip|trips|tour|tours|package|packages|itinerary|itineraries|holiday|holidays'],
                'event_venue'      => ['Packages', 'package', 'packages', 'package|packages|event type|event types|hire|hires'],
                'childcare'        => ['Programmes', 'programme', 'programmes', 'programme|programmes|program|programs|class|classes|session|sessions'],
                'pet_services'     => ['Services & prices', 'service', 'services', 'service|services|grooming|walk|walks|boarding'],
                'home_services'    => ['Services & prices', 'service', 'services', 'service|services|job|jobs|repair|repairs'],
                'automotive'       => ['Services & prices', 'service', 'services', 'service|services|repair|repairs'],
                'consulting'       => ['Services', 'service', 'services', 'service|services|engagement|engagements|offering|offerings'],
                'marketing_agency' => ['Services', 'service', 'services', 'service|services|offering|offerings'],
                'it_services'      => ['Services', 'service', 'services', 'service|services|offering|offerings'],
                'architecture'     => ['Services', 'service', 'services', 'service|services|offering|offerings'],
                'construction'     => ['Services', 'service', 'services', 'service|services|trade|trades'],
                'interior_design'  => ['Services', 'service', 'services', 'service|services|offering|offerings'],
                'real_estate_agency' => ['Services', 'service', 'services', 'service|services'],
                'news_channel'     => ['Services', 'service', 'services', 'service|services|offering|offerings'],
            ],
        ],
        'menu' => [
            'family' => 'menu', 'plural' => 'Menu', 'singular' => 'dish', 'page_slug' => 'menu', 'detail_prefix' => 'dish',
            'pages' => 'index', 'cta' => 'Order', 'enquiry_source' => 'menu_enquiry',
            'statuses' => ['active' => 'On the menu', 'sold_out' => 'Sold out', 'hidden' => 'Hidden'], 'open' => ['active', 'sold_out'], 'closed' => [], 'default_status' => 'active',
            'closed_label' => '', 'closed_family_default' => '',
            'attrs' => [
                ['key' => 'tag', 'label' => 'Tag (e.g. Bestseller)', 'type' => 'text', 'from' => ['tag']],
                ['key' => 'origin', 'label' => 'Origin / note', 'type' => 'text', 'from' => ['origin']],
            ],
            'nouns' => 'dish|dishes|menu item|menu items|item|items|pastry|pastries|cake|cakes|bread|breads|coffee|coffees|drink|drinks|meal|meals|plate|plates|special|specials|menu',
            'industries' => [],
        ],
        // ── Phase 2 (DEC-0049): priced kinds with their own families ──
        'plan' => [
            'family' => 'plan', 'families' => ['plan', 'tier', 'pricing'], 'plural' => 'Plans & pricing', 'singular' => 'plan', 'page_slug' => 'pricing', 'detail_prefix' => 'plan',
            'pages' => 'index', 'cta' => 'Choose', 'enquiry_source' => 'plan_enquiry',
            'statuses' => ['active' => 'Available', 'hidden' => 'Hidden'], 'open' => ['active'], 'closed' => [], 'default_status' => 'active',
            'closed_label' => '', 'closed_family_default' => '',
            'attrs' => [
                ['key' => 'term', 'label' => 'Term (e.g. 12 months)', 'type' => 'text', 'from' => ['term']],
                ['key' => 'apr', 'label' => 'APR / rate', 'type' => 'text', 'from' => ['apr']],
            ],
            'nouns' => 'plan|plans|tier|tiers|membership|memberships|pricing|subscription|subscriptions|bundle|bundles',
            'industries' => ['gym' => ['Memberships', 'membership', 'memberships', 'membership|memberships|plan|plans|tier|tiers|pass|passes']],
        ],
        'room' => [
            'family' => 'room', 'families' => ['room', 'suite'], 'plural' => 'Rooms & rates', 'singular' => 'room', 'page_slug' => 'rooms', 'detail_prefix' => 'room',
            'pages' => 'index+detail', 'cta' => 'Reserve', 'enquiry_source' => 'room_enquiry',
            'statuses' => ['active' => 'Available', 'hidden' => 'Hidden'], 'open' => ['active'], 'closed' => [], 'default_status' => 'active',
            'closed_label' => '', 'closed_family_default' => '',
            'attrs' => [
                ['key' => 'size', 'label' => 'Size / sleeps (e.g. 55 sqm · Sleeps 2)', 'type' => 'text', 'from' => ['size']],
                ['key' => 'view', 'label' => 'View', 'type' => 'text', 'from' => ['view']],
            ],
            'nouns' => 'room|rooms|suite|suites|villa|villas|bungalow|bungalows|cabin|cabins',
            'industries' => [],
        ],
        'vehicle' => [
            'family' => 'vehicle', 'families' => ['vehicle', 'car'], 'plural' => 'Vehicles', 'singular' => 'vehicle', 'page_slug' => 'vehicles', 'detail_prefix' => 'vehicle',
            'pages' => 'index+detail', 'cta' => 'Enquire', 'enquiry_source' => 'vehicle_enquiry', 'title_from' => ['make', 'model'],
            'statuses' => ['for_sale' => 'For Sale', 'reserved' => 'Reserved', 'sold' => 'Sold', 'hidden' => 'Hidden'], 'open' => ['for_sale', 'reserved'], 'closed' => ['sold'], 'default_status' => 'for_sale',
            'closed_label' => 'Recently sold', 'closed_family_default' => '',
            'attrs' => [
                ['key' => 'make', 'label' => 'Make', 'type' => 'text', 'from' => ['make'], 'in_title' => true],
                ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'from' => ['model'], 'in_title' => true],
                ['key' => 'year', 'label' => 'Year', 'type' => 'text', 'from' => ['year']],
                ['key' => 'mileage', 'label' => 'Mileage', 'type' => 'text', 'from' => ['mileage']],
                ['key' => 'engine', 'label' => 'Engine', 'type' => 'text', 'from' => ['engine']],
                ['key' => 'transmission', 'label' => 'Transmission', 'type' => 'text', 'from' => ['transmission']],
            ],
            'nouns' => 'vehicle|vehicles|car|cars|van|vans|truck|trucks|suv|suvs|motorbike|motorbikes|bike|bikes',
            'industries' => [],
        ],
        'program' => [
            'family' => 'program', 'families' => ['program', 'programme'], 'plural' => 'Programmes', 'singular' => 'programme', 'page_slug' => 'programmes', 'detail_prefix' => 'programme',
            'pages' => 'index', 'cta' => 'Enquire', 'enquiry_source' => 'program_enquiry',
            'statuses' => ['active' => 'Open', 'full' => 'Full', 'hidden' => 'Hidden'], 'open' => ['active', 'full'], 'closed' => [], 'default_status' => 'active',
            'closed_label' => '', 'closed_family_default' => '',
            'attrs' => [
                ['key' => 'age', 'label' => 'Age range', 'type' => 'text', 'from' => ['age']],
                ['key' => 'duration', 'label' => 'Duration', 'type' => 'text', 'from' => ['duration']],
                ['key' => 'tag', 'label' => 'Tag / level', 'type' => 'text', 'from' => ['tag']],
            ],
            'nouns' => 'programme|programmes|program|programs|course|courses|class|classes|curriculum',
            'industries' => ['training_center' => ['Courses', 'course', 'courses', 'course|courses|programme|programmes|program|programs|class|classes|degree|degrees|diploma|diplomas']],
        ],
        'event' => [
            'family' => 'event', 'families' => ['event'], 'plural' => 'Events', 'singular' => 'event', 'page_slug' => 'events', 'detail_prefix' => 'event',
            'pages' => 'index', 'cta' => 'Enquire', 'enquiry_source' => 'event_enquiry',
            'statuses' => ['active' => 'Shown', 'hidden' => 'Hidden'], 'open' => ['active'], 'closed' => [], 'default_status' => 'active',
            'closed_label' => '', 'closed_family_default' => '',
            'attrs' => [
                ['key' => 'type', 'label' => 'Type (e.g. Wedding · Three-Day)', 'type' => 'text', 'from' => ['type']],
                ['key' => 'venue', 'label' => 'Venue / guests', 'type' => 'text', 'from' => ['venue']],
                ['key' => 'date', 'label' => 'Date', 'type' => 'text', 'from' => ['date']],
            ],
            'nouns' => 'event|events|wedding|weddings|conference|conferences|gala|galas|party|parties|celebration|celebrations',
            'industries' => [],
        ],
        'session' => [
            'family' => 'session', 'families' => ['session'], 'plural' => 'Timetable', 'singular' => 'session', 'page_slug' => 'timetable', 'detail_prefix' => 'session',
            'pages' => 'index', 'cta' => '', 'enquiry_source' => 'session_enquiry',
            'statuses' => ['active' => 'On', 'full' => 'Full', 'hidden' => 'Hidden'], 'open' => ['active', 'full'], 'closed' => [], 'default_status' => 'active',
            'closed_label' => '', 'closed_family_default' => '',
            'attrs' => [
                ['key' => 'time', 'label' => 'Time', 'type' => 'text', 'from' => ['time']],
                ['key' => 'day', 'label' => 'Day(s)', 'type' => 'text', 'from' => ['day', 'days']],
                ['key' => 'trainer', 'label' => 'Trainer / coach', 'type' => 'text', 'from' => ['trainer', 'coach', 'instructor']],
            ],
            'nouns' => 'session|sessions|slot|slots|timetable|schedule|class time|class times',
            'industries' => [],
        ],
        // SGTRAVEL CAT-1 (2026-09-21) — travel packages: the travel vertical's catalogue (renderer sites read it at render time).
        'package' => [
            'family' => 'package', 'families' => ['package', 'trip', 'tour'], 'plural' => 'Packages', 'singular' => 'package', 'page_slug' => 'packages', 'detail_prefix' => 'package',
            'pages' => 'index', 'cta' => 'Book now', 'enquiry_source' => 'booking_inquiry',
            'statuses' => ['active' => 'Available', 'sold_out' => 'Sold out', 'hidden' => 'Hidden'], 'open' => ['active', 'sold_out'], 'closed' => [], 'default_status' => 'active',
            'closed_label' => '', 'closed_family_default' => '',
            'attrs' => [
                ['key' => 'nights', 'label' => 'Length (e.g. 3N / 4D)', 'type' => 'text', 'from' => ['nights', 'duration', 'days']],
                ['key' => 'destination', 'label' => 'Destination(s)', 'type' => 'text', 'from' => ['destination', 'location']],
                ['key' => 'departure', 'label' => 'Departs from (e.g. Manila · Muscat)', 'type' => 'text', 'from' => ['departure', 'from']],
                ['key' => 'dates', 'label' => 'Travel dates / validity', 'type' => 'text', 'from' => ['dates', 'validity']],
                ['key' => 'inclusions', 'label' => 'Inclusions (one per line)', 'type' => 'textarea', 'from' => ['inclusions', 'includes']],
                ['key' => 'exclusions', 'label' => 'Exclusions (one per line)', 'type' => 'textarea', 'from' => ['exclusions', 'excludes']],
            ],
            'nouns' => 'package|packages|tour|tours|trip|trips|itinerary|itineraries|holiday|holidays|cruise|cruises|getaway|getaways',
            'industries' => [],
        ],
        'project' => [
            'family' => 'project', 'families' => ['project', 'portfolio', 'case'], 'plural' => 'Projects', 'singular' => 'project', 'page_slug' => 'projects', 'detail_prefix' => 'project',
            'pages' => 'index+detail', 'cta' => 'See project', 'enquiry_source' => 'project_enquiry',
            'statuses' => ['active' => 'Shown', 'hidden' => 'Hidden'], 'open' => ['active'], 'closed' => [], 'default_status' => 'active',
            'closed_label' => '', 'closed_family_default' => '',
            'attrs' => [
                ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'from' => ['category', 'cat', 'badge']],
                ['key' => 'location', 'label' => 'Location / year', 'type' => 'text', 'from' => ['location']],
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'from' => ['client']],
                ['key' => 'value', 'label' => 'Value / duration', 'type' => 'text', 'from' => ['value']],
                ['key' => 'result', 'label' => 'Result', 'type' => 'text', 'from' => ['result', 'price_note']],
            ],
            'nouns' => 'project|projects|case study|case studies|portfolio|portfolio item|portfolio items|work|works|engagement|engagements',
            'industries' => [
                'consulting'       => ['Case studies', 'case study', 'case-studies', 'case study|case studies|engagement|engagements|project|projects|portfolio'],
                'marketing_agency' => ['Case studies', 'case study', 'case-studies', 'case study|case studies|campaign|campaigns|project|projects|portfolio'],
                'event_venue'      => ['Past events', 'event', 'past-events', 'event|events|wedding|weddings|project|projects|portfolio'],
            ],
        ],
    ];

    /** Suffix candidates the projection tries for each shared column, in order (first one the design has wins). */
    public const SHARED_SUFFIXES = [
        'title'   => ['title', 'name'],
        'summary' => ['text', 'desc', 'description', 'body'],
        'price'   => ['price'],
        'image'   => ['image'],
        'badge'   => ['badge'],
        'cta'     => ['cta'],
    ];

    public static function get(string $kind): ?array
    {
        return self::KINDS[$kind] ?? null;
    }

    /** Plural label, singular, page slug and chat nouns for a kind in an industry. */
    public static function labels(string $kind, string $industry): array
    {
        $k = self::KINDS[$kind] ?? null;
        if (! $k) { return ['plural' => ucfirst($kind), 'singular' => $kind, 'page_slug' => $kind, 'nouns' => $kind]; }
        $o = $k['industries'][$industry] ?? null;
        return $o
            ? ['plural' => $o[0], 'singular' => $o[1], 'page_slug' => $o[2], 'nouns' => $o[3]]
            : ['plural' => $k['plural'], 'singular' => $k['singular'], 'page_slug' => $k['page_slug'], 'nouns' => $k['nouns']];
    }

    /** What the editor needs to draw a form for a kind. */
    public static function publicSchema(string $kind): array
    {
        $k = self::KINDS[$kind] ?? [];
        return ['attrs' => $k['attrs'] ?? [], 'statuses' => $k['statuses'] ?? [], 'open' => $k['open'] ?? [], 'closed' => $k['closed'] ?? [], 'default_status' => $k['default_status'] ?? 'active', 'pages' => $k['pages'] ?? 'none'];
    }
}
