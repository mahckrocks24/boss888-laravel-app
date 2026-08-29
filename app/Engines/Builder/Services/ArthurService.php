<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Arthur AI Website Builder — smart extraction + template generation.
 * Single detailed message → builds immediately.
 * Vague message → asks follow-ups conversationally.
 */
class ArthurService
{
    private \App\Connectors\RuntimeClient $runtime;
    private TemplateService $templates;

    // FIX 1 — named color → hex map used by applyBrandColors()
    private const COLOR_MAP = [
        'black'     => '#0A0A0A', 'white'   => '#FFFFFF', 'red'     => '#DC2626',
        'blue'      => '#2563EB', 'navy'    => '#1A2744', 'gold'    => '#C9943A',
        'green'     => '#16A34A', 'purple'  => '#7C3AED', 'violet'  => '#7C3AED',
        'orange'    => '#F97316', 'pink'    => '#EC4899', 'grey'    => '#6B7280',
        'gray'      => '#6B7280', 'brown'   => '#78350F', 'teal'    => '#0D9488',
        'yellow'    => '#EAB308', 'cyan'    => '#06B6D4', 'rose'    => '#E11D48',
        'emerald'   => '#059669', 'indigo'  => '#4F46E5', 'silver'  => '#9CA3AF',
        'cream'     => '#F5F1EC', 'beige'   => '#D4C5A0', 'ivory'   => '#FFFFF0',
        'charcoal'  => '#1F2937', 'maroon'  => '#7F1D1D', 'crimson' => '#B91C1C',
        'bronze'    => '#8B6F3E', 'copper'  => '#B45309', 'mint'    => '#6EE7B7',
        'coral'     => '#FB7185', 'magenta' => '#C026D3', 'turquoise' => '#06B6D4',
        'olive'     => '#65733C', 'lime'    => '#84CC16', 'peach'   => '#FDBA74',
        'sand'      => '#D4C5A0',
    ];

    // FIX 1 — every accent/brand var name known across the 10 templates.
    // applyBrandColors() walks the manifest and sets only vars that exist.
    // Sourced from: beauty/events/fashion/fitness/healthcare/interior_design/
    // legal/real_estate/restaurant/technology manifests (color-typed vars).
    private const ACCENT_VAR_NAMES = [
        // Canonical trio (restaurant + generic)
        'primary_color', 'secondary_color', 'accent_color',
        'primary_light', 'primary_deep',
        'primary', 'accent',
        // Paired accent + deep variants per industry
        'gold', 'gold_deep',
        'rose', 'rose_deep',
        'terracotta', 'terracotta_deep',
        'orange', 'orange_deep',
        'bronze', 'bronze_deep',
        'brass',  'brass_deep',
        'forest', 'forest_deep',
        'medical_blue', 'medical_deep',
        'cyan', 'violet',
        'sage', 'sage_soft',
        'navy', 'ember', 'volt',
    ];

    // FIX 4 — industry keyword deny-list. If a generated service title
    // contains a keyword from a DIFFERENT industry, we fall back to the
    // manifest default. Keys are the site's industry.
    // PATCH (deny re-key, 2026-07-24) — Keys MUST be on-disk template slugs
    // (the resolved $industry passed to isCrossIndustryLeak), NOT the old
    // abstract vocab ('fitness','healthcare','legal'…). With abstract keys the
    // lookup missed for every industry except 'restaurant', silently disabling
    // the cross-industry copy-leak guard platform-wide — which is how a
    // clothing store rendered full "Managed IT Services" copy unflagged.
    private const INDUSTRY_DENY = [
        'gym'                => ['dining','restaurant','cuisine','menu','chef','bakery','tasting','wedding','venue','clinic','attorney','law firm','property','listing','couture','atelier','saas','api','endpoint'],
        'restaurant'         => ['workout','fitness','gym','hiit','yoga','pilates','strength training','clinic','attorney','property','listing','couture','saas','api','endpoint'],
        'cafe'               => ['workout','fitness','gym','hiit','yoga','pilates','clinic','attorney','property','listing','saas','api','endpoint'],
        'catering'           => ['workout','fitness','gym','hiit','yoga','clinic','attorney','property','listing','saas','api','endpoint'],
        'dental'             => ['dining','cuisine','menu','workout','hiit','yoga','couture','atelier','property','listing','saas','api','endpoint'],
        'medical_clinic'     => ['dining','cuisine','menu','workout','hiit','yoga','couture','atelier','property','listing','saas','api','endpoint'],
        'aesthetic_clinic'   => ['dining','cuisine','menu','kitchen','workout','hiit','yoga','property','listing','saas','api','endpoint'],
        'consulting'         => ['dining','cuisine','menu','workout','hiit','yoga','clinic','salon','couture','saas','api'],
        'beauty_salon'       => ['dining','cuisine','menu','workout','hiit','yoga','attorney','law firm','property','saas','api','endpoint'],
        'barbershop'         => ['dining','cuisine','menu','workout','hiit','yoga','attorney','law firm','property','saas','api','endpoint'],
        'real_estate_agency' => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','saas','api'],
        'interior_design'    => ['dining','cuisine','menu','workout','hiit','clinic','attorney','saas','api'],
        'architecture'       => ['dining','cuisine','menu','workout','hiit','clinic','attorney','saas','api'],
        'retail_shop'        => ['dining','cuisine','menu','workout','hiit','clinic','attorney','saas','api','endpoint'],
        'ecommerce'          => ['dining','cuisine','menu','workout','hiit','clinic','attorney','saas','api'],
        'it_services'        => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','atelier'],
        'marketing_agency'   => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','atelier'],
        'home_services'      => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','saas','api'],
        'automotive'         => ['dining','cuisine','menu','workout','hiit','yoga','clinic','attorney','couture','saas','api'],
        'pet_services'       => ['dining','cuisine','menu','workout','hiit','yoga','attorney','law firm','saas','api'],
        'event_venue'        => ['dining menu','cuisine','workout','hiit','yoga','clinic','attorney','saas','api'],
    ];

    // BUG 2 FIX — keyword → canonical industry map. Order of keys matters:
    // applyIndustryMap() iterates by descending key length so longer phrases
    // ("digital marketing") match before shorter substrings ("digital").
    private const INDUSTRY_MAP = [
        // Marketing agency (new industry) — digital marketing agencies are NOT SaaS
        'digital marketing'        => 'marketing_agency',
        'marketing agency'         => 'marketing_agency',
        'seo agency'               => 'marketing_agency',
        'seo'                      => 'marketing_agency',
        'social media agency'      => 'marketing_agency',
        'social media'             => 'marketing_agency',
        'advertising agency'       => 'marketing_agency',
        'advertising'              => 'marketing_agency',
        'ppc'                      => 'marketing_agency',
        'paid ads'                 => 'marketing_agency',
        'growth agency'            => 'marketing_agency',
        'media buying'             => 'marketing_agency',
        'web design'               => 'marketing_agency',
        // Technology (SaaS / dev tools / software only)
        'web development'          => 'technology',
        'it company'               => 'technology',
        'it services'              => 'technology',
        'software'                 => 'technology',
        'app development'          => 'technology',
        'app'                      => 'technology',
        'saas'                     => 'technology',
        'tech'                     => 'technology',
        'digital'                  => 'technology',
        // Education (new)
        'education'                => 'tutoring',
        'school'                   => 'tutoring',
        'university'               => 'tutoring',
        'college'                  => 'tutoring',
        'institute'                => 'tutoring',
        'academy'                  => 'tutoring',
        'tutoring'                 => 'tutoring',
        'training center'          => 'tutoring',
        'online course'            => 'tutoring',
        // Automotive (new)
        'automotive'               => 'automotive',
        'car dealership'           => 'automotive',
        'auto'                     => 'automotive',
        'vehicle'                  => 'automotive',
        'garage'                   => 'automotive',
        'car service'              => 'automotive',
        'auto repair'              => 'automotive',
        'car rental'               => 'automotive',
        'detailing'                => 'automotive',
        // Hospitality (new)
        'hotel'                    => 'hotel',
        'hospitality'              => 'hotel',
        'resort'                   => 'hotel',
        'serviced apartment'       => 'hotel',
        'guesthouse'               => 'hotel',
        'boutique hotel'           => 'hotel',
        'vacation rental'          => 'hotel',
        // Cafe (new — split from restaurant)
        'cafe'                     => 'cafe',
        'coffee shop'              => 'cafe',
        'bakery'                   => 'cafe',
        'juice bar'                => 'cafe',
        'patisserie'               => 'cafe',
        'tea house'                => 'cafe',
        'brunch'                   => 'cafe',
        'breakfast'                => 'cafe',
        'dessert shop'             => 'cafe',
        // Cleaning (new)
        'cleaning services'        => 'home_services',
        'cleaning'                 => 'home_services',
        'maid service'             => 'home_services',
        'housekeeping'             => 'home_services',
        'office cleaning'          => 'home_services',
        'deep cleaning'            => 'home_services',
        'move-out cleaning'        => 'home_services',
        'post construction cleaning' => 'home_services',
        'sanitization'             => 'home_services',
        'disinfection'             => 'home_services',
        // Construction (new)
        'construction'             => 'construction',
        'contractor'               => 'construction',
        'builder'                  => 'construction',
        'civil engineering'        => 'construction',
        'mep'                      => 'construction',
        'fit-out'                  => 'construction',
        'renovation'               => 'construction',
        'infrastructure'           => 'construction',
        'building contractor'      => 'construction',
        'joinery'                  => 'construction',
        // Photography (new)
        'photography'              => 'consulting',
        'photographer'             => 'consulting',
        'photo studio'             => 'consulting',
        'videography'              => 'consulting',
        'videographer'             => 'consulting',
        'wedding photographer'     => 'consulting',
        'portrait studio'          => 'consulting',
        'commercial photography'   => 'consulting',
        // Childcare (new)
        'childcare'                => 'childcare',
        'nursery'                  => 'childcare',
        'daycare'                  => 'childcare',
        'kindergarten'             => 'childcare',
        'preschool'                => 'childcare',
        'early childhood'          => 'childcare',
        'kids activities'          => 'childcare',
        'after school'             => 'childcare',
        'child development center' => 'childcare',
        // Consulting (new — split from legal)
        'management consulting'    => 'consulting',
        'business consulting'      => 'consulting',
        'hr consulting'            => 'consulting',
        'financial consulting'     => 'consulting',
        'consultancy'              => 'consulting',
        'consulting'               => 'consulting',
        'strategy'                 => 'consulting',
        'advisory'                 => 'consulting',
        // Finance (new)
        'finance'                  => 'consulting',
        'accounting'               => 'consulting',
        'accountant'               => 'consulting',
        'financial advisor'        => 'consulting',
        'tax'                      => 'consulting',
        'audit'                    => 'consulting',
        'bookkeeping'              => 'consulting',
        'payroll'                  => 'consulting',
        'vat'                      => 'consulting',
        'cfo services'             => 'consulting',
        'wealth management'        => 'consulting',
        // Wellness (new)
        'wellness'                 => 'medical_clinic',
        'nutrition'                => 'medical_clinic',
        'nutritionist'             => 'medical_clinic',
        'life coach'               => 'medical_clinic',
        'mental health'            => 'medical_clinic',
        'therapist'                => 'medical_clinic',
        'meditation'               => 'medical_clinic',
        'holistic'                 => 'medical_clinic',
        'naturopath'               => 'medical_clinic',
        'health coach'             => 'medical_clinic',
        'mindfulness'              => 'medical_clinic',
        // Pet services (new)
        'pet'                      => 'pet_services',
        'veterinary'               => 'pet_services',
        'vet clinic'               => 'pet_services',
        'pet grooming'             => 'pet_services',
        'pet boarding'             => 'pet_services',
        'pet training'             => 'pet_services',
        'dog grooming'             => 'pet_services',
        'cat clinic'               => 'pet_services',
        'pet shop'                 => 'pet_services',
        'animal hospital'          => 'pet_services',
        'pet daycare'              => 'pet_services',
        // Logistics (new)
        'logistics'                => 'consulting',
        'freight'                  => 'consulting',
        'shipping'                 => 'consulting',
        'courier'                  => 'consulting',
        'delivery'                 => 'consulting',
        'warehousing'              => 'consulting',
        'moving company'           => 'consulting',
        'relocation'               => 'consulting',
        'cargo'                    => 'consulting',
        'supply chain'             => 'consulting',
        'last mile'                => 'consulting',
        'movers'                   => 'consulting',
        // Architecture (new)
        'architecture'             => 'architecture',
        'architect'                => 'architecture',
        'urban planning'           => 'architecture',
        'landscape architecture'   => 'architecture',
        'structural engineering'   => 'architecture',
        'masterplan'               => 'architecture',
        'urban design'             => 'architecture',
        'building design'          => 'architecture',
        // Real estate broker — personal brand (not a company site) — must
        // beat 'real estate' / 'property' which go to the company-site
        // 'real_estate' template. Keys ordered so the more-specific phrase
        // matches first via normalizeIndustry()'s longest-key-wins rule.
        'real estate broker'       => 'real_estate_agency',
        'property broker'          => 'real_estate_agency',
        'real estate agent'        => 'real_estate_agency',
        'property agent'           => 'real_estate_agency',
        'property consultant'      => 'real_estate_agency',
        'realtor'                  => 'real_estate_agency',
        'broker'                   => 'real_estate_agency',
        // v1.4.4 (2026-05-30) — legacy aliases REMAPPED to actual template
        // slugs. Before this, `legal`, `healthcare`, `fitness`, `beauty`,
        // `real_estate`, `fashion`, `events` were the values — but no such
        // templates exist on disk. Routing here pointed at the void; the
        // fallback only saved obvious cases. Now each routes to a real
        // template manifest.
        'law firm'                 => 'consulting',         // no `legal` template; consulting is closest professional services
        'lawyer'                   => 'consulting',
        'attorney'                 => 'consulting',
        'clinic'                   => 'medical_clinic',
        'hospital'                 => 'medical_clinic',
        'doctor'                   => 'medical_clinic',
        'gym'                      => 'gym',
        'yoga'                     => 'gym',
        'pilates'                  => 'gym',
        'personal trainer'         => 'gym',
        'salon'                    => 'beauty_salon',
        'spa'                      => 'beauty_salon',
        'barbershop'               => 'barbershop',         // own template now (was routed to beauty)
        'barber'                   => 'barbershop',
        'restaurant'               => 'restaurant',
        'bistro'                   => 'restaurant',
        'food'                     => 'restaurant',
        'interior'                 => 'interior_design',
        'real estate'              => 'real_estate_agency',
        'property'                 => 'real_estate_agency',
        'fashion'                  => 'retail_shop',        // closest match — `fashion` template doesn't exist
        'clothing'                 => 'retail_shop',
        'boutique'                 => 'retail_shop',
        'wedding'                  => 'event_venue',
        'events'                   => 'event_venue',
        // v1.4.4 — direct routes for templates that the prior map missed
        'home cleaning'            => 'home_services',
        'gardening'                => 'home_services',
        'landscaping'              => 'home_services',
        'pool service'             => 'home_services',
        'handyman'                 => 'home_services',
        'pest control'             => 'home_services',
        'ecommerce'                => 'ecommerce',
        'online store'             => 'ecommerce',
        'e-commerce'               => 'ecommerce',
        'dropshipping'             => 'ecommerce',
        'training center'          => 'training_center',
        'corporate training'       => 'training_center',
        'professional training'    => 'training_center',
        'travel agency'            => 'travel_agency',
        'tour operator'            => 'travel_agency',
        'tour'                     => 'travel_agency',
        'online course'            => 'online_courses',
        'online courses'           => 'online_courses',
        'e-learning'               => 'online_courses',
        'mooc'                     => 'online_courses',
        'news channel'             => 'news_channel',
        'newspaper'                => 'news_channel',
        'publishing'               => 'news_channel',
        'media publication'        => 'news_channel',
        'event venue'              => 'event_venue',
        'banquet hall'             => 'event_venue',
        'conference centre'        => 'event_venue',
        'conference center'        => 'event_venue',
        // Legacy slugs we shouldn't keep emitting (no template manifest):
        // 'cleaning', 'logistics', 'finance', 'wellness', 'photography',
        // 'real_estate_broker', 'education', 'healthcare', 'fitness',
        // 'beauty', 'real_estate', 'fashion', 'events', 'hospitality',
        // 'legal' — keys above now point at real templates; resolveTemplateSlug's
        // catch-all regex handles the residual cases (cleaning → home_services,
        // logistics → consulting, photography → consulting, wellness → medical_clinic).
    ];

    // Canonical industry slugs the template system understands.
    // v1.4.4 (2026-05-30) — synced to the 31 templates that actually
    // exist on disk at storage/templates/. Legacy "category" aliases
    // (healthcare, fashion, beauty, fitness, hospitality, events, education,
    // legal) are still routed via KEYWORD_TO_TEMPLATE + resolveTemplateSlug()
    // — they map TO these 31 template slugs but aren't themselves "valid"
    // raw industry slugs anymore. This keeps normalizeIndustry() from
    // emitting an industry name that has no template manifest.
    private const VALID_INDUSTRIES = [
        'aesthetic_clinic', 'architecture', 'automotive', 'barbershop',
        'beauty_salon', 'cafe', 'catering', 'childcare', 'construction',
        'consulting', 'dental', 'ecommerce', 'event_venue', 'gym',
        'home_services', 'hotel', 'interior_design', 'it_services',
        'marketing_agency', 'medical_clinic', 'news_channel', 'online_courses',
        'pet_services', 'real_estate_agency', 'resort', 'restaurant',
        'retail_shop', 'short_term_rental', 'training_center', 'travel_agency',
        'tutoring',
    ];

    /**
     * v1.4.4 (2026-05-30) — Page template catalogue.
     *
     * Authoritative metadata for the 17 page templates surfaced by
     * `buildDefaultSectionsForPage()`. This array describes WHAT each
     * template is for; the switch in buildDefaultSectionsForPage()
     * remains the source of truth for the actual section stack.
     *
     * Categories:
     *   universal          — works for any industry
     *   bookings_events    — reservations / appointments / classes
     *   listings           — searchable catalogues (property, room, product)
     *   visual_portfolios  — gallery-driven (transformations, menu, work)
     *   commerce_account   — cart → checkout → account flow
     *
     * Used by AdminTemplatesController::pageTemplates() to populate the
     * admin "Page Templates" tab.
     */
    public const PAGE_TEMPLATE_CATALOGUE = [
        // ─── Universal (D-1) ────────────────────────────────────────
        'about'           => ['label' => 'About',           'category' => 'universal',         'aliases' => ['about_us'],                                                            'industries' => ['*'],                                                                                                                                                                                    'description' => "Company story + values + team + testimonials + CTA. Industry-agnostic."],
        'services'        => ['label' => 'Services',        'category' => 'universal',         'aliases' => [],                                                                       'industries' => ['*'],                                                                                                                                                                                    'description' => "Service offerings as cards + testimonials + inline quote form."],
        'pricing'         => ['label' => 'Pricing',         'category' => 'universal',         'aliases' => [],                                                                       'industries' => ['*'],                                                                                                                                                                                    'description' => "Tiered pricing + FAQ + closing CTA."],
        'contact'         => ['label' => 'Contact',         'category' => 'universal',         'aliases' => [],                                                                       'industries' => ['*'],                                                                                                                                                                                    'description' => "Hero + contact form + footer."],
        'faq'             => ['label' => 'FAQ',             'category' => 'universal',         'aliases' => [],                                                                       'industries' => ['*'],                                                                                                                                                                                    'description' => "Common questions with industry-aware sample answers."],
        'legal'           => ['label' => 'Legal',           'category' => 'universal',         'aliases' => ['privacy', 'privacy_policy', 'terms', 'terms_of_service'],              'industries' => ['*'],                                                                                                                                                                                    'description' => "Privacy policy / Terms of service skeleton."],

        // ─── Bookings + events (D-2) ────────────────────────────────
        'booking'         => ['label' => 'Booking / Appointments', 'category' => 'bookings_events', 'aliases' => ['book', 'book_now', 'appointments', 'reservations', 'reserve'],     'industries' => ['aesthetic_clinic', 'dental', 'medical_clinic', 'beauty_salon', 'barbershop', 'gym', 'hotel', 'resort', 'restaurant', 'cafe', 'event_venue', 'training_center', 'tutoring'],            'description' => "Hero + booking_form (service picker, date+time, contact fields) + features + FAQ + footer."],
        'events'          => ['label' => 'Events / Classes',       'category' => 'bookings_events', 'aliases' => ['event', 'classes', 'schedule', 'whats_on'],                        'industries' => ['event_venue', 'training_center', 'online_courses', 'hotel', 'resort', 'cafe', 'restaurant', 'gym', 'news_channel'],                                                                       'description' => "Hero + events_calendar (grid/list of upcoming sessions) + CTA + footer."],

        // ─── Listings (D-3) ─────────────────────────────────────────
        'listing_browser' => ['label' => 'Listing browser',  'category' => 'listings',         'aliases' => ['listings', 'properties', 'rooms', 'products', 'shop', 'catalogue', 'catalog', 'inventory', 'fleet', 'courses', 'menu_browser'], 'industries' => ['real_estate_agency', 'short_term_rental', 'ecommerce', 'retail_shop', 'hotel', 'resort', 'automotive', 'online_courses', 'training_center', 'tutoring'],                              'description' => "Hero + filter_bar + grid of cards + cross-sell CTA. Industry-aware kind label (properties / rooms / products / vehicles / courses)."],
        'listing_detail'  => ['label' => 'Listing detail',   'category' => 'listings',         'aliases' => ['property', 'product', 'room', 'course'],                               'industries' => ['real_estate_agency', 'short_term_rental', 'ecommerce', 'hotel', 'resort', 'automotive', 'online_courses'],                                                                              'description' => "Hero + key-detail features + gallery + trust signals + CTA + inquiry form + related listings."],
        'locations'       => ['label' => 'Locations',        'category' => 'listings',         'aliases' => ['location', 'branches', 'find_us', 'store_finder', 'stores'],           'industries' => ['*'],                                                                                                                                                                                    'description' => "Hero + map with branch list + trust signals + CTA. Suits any tenant with physical presence."],

        // ─── Visual portfolios (D-4) ────────────────────────────────
        'before_after'    => ['label' => 'Before / After',   'category' => 'visual_portfolios','aliases' => ['before_and_after', 'transformations', 'results', 'case_studies'],      'industries' => ['aesthetic_clinic', 'dental', 'beauty_salon', 'barbershop', 'home_services', 'construction', 'interior_design'],                                                                         'description' => "Hero + gallery (paired before/after) + testimonials + stat row + CTA."],
        'menu'            => ['label' => 'Menu',             'category' => 'visual_portfolios','aliases' => ['food_menu', 'dishes', 'drinks', 'wine_list'],                          'industries' => ['restaurant', 'cafe', 'catering', 'hotel', 'resort'],                                                                                                                                    'description' => "Hero + filter_bar (sections / dietary) + 2-column grid of items + reservation CTA."],
        'portfolio'       => ['label' => 'Portfolio',        'category' => 'visual_portfolios','aliases' => ['work', 'projects', 'gallery_page', 'showcase'],                        'industries' => ['architecture', 'interior_design', 'construction', 'marketing_agency', 'consulting', 'it_services'],                                                                                     'description' => "Hero + filter_bar + media-style grid + testimonials + project CTA."],

        // ─── Commerce + account (D-5) ───────────────────────────────
        'cart'            => ['label' => 'Cart',             'category' => 'commerce_account', 'aliases' => ['basket', 'shopping_cart'],                                              'industries' => ['ecommerce', 'retail_shop', 'online_courses'],                                                                                                                                           'description' => "Cart summary (line items + tax + shipping + total) + trust signals + footer."],
        'checkout'        => ['label' => 'Checkout',         'category' => 'commerce_account', 'aliases' => ['checkout_page'],                                                        'industries' => ['ecommerce', 'retail_shop', 'online_courses'],                                                                                                                                           'description' => "Multi-section checkout form (contact / shipping / payment) + order summary sidebar + trust signals."],
        'account'         => ['label' => 'Account',          'category' => 'commerce_account', 'aliases' => ['my_account', 'dashboard', 'profile_page'],                              'industries' => ['ecommerce', 'retail_shop', 'online_courses', 'short_term_rental'],                                                                                                                      'description' => "Account nav (top) + account panel (orders / addresses / profile / wishlist)."],
    ];

    /**
     * Return the page template catalogue enriched with the actual section
     * stack each template produces. Sample data used so the structure is
     * representative — actual tenant data is substituted at runtime.
     */
    public function listPageTemplates(): array
    {
        $sample = [
            'business_name' => 'Sample Business',
            'industry'      => 'consulting',
            'core_service'  => 'our core service',
            'services'      => ['Service one', 'Service two', 'Service three'],
            'location'      => 'Dubai',
        ];

        $out = [];
        foreach (self::PAGE_TEMPLATE_CATALOGUE as $slug => $meta) {
            $stack = $this->buildDefaultSectionsForPage($slug, $sample);
            $types = array_values(array_map(fn ($s) => $s['type'] ?? '', $stack));
            $out[] = [
                'slug'                   => $slug,
                'label'                  => $meta['label'],
                'category'               => $meta['category'],
                'aliases'                => $meta['aliases'],
                'recommended_industries' => $meta['industries'],
                'description'            => $meta['description'],
                'section_types'          => $types,
                'section_count'          => count($types),
                'preview_url'            => '/page-templates/' . $slug . '/preview',
            ];
        }
        return $out;
    }

    public function __construct()
    {
        $this->runtime = app(\App\Connectors\RuntimeClient::class);
        $this->templates = new TemplateService();
    }

    // FIX 1 — normalise a color token (name or hex) to a 6-digit hex string.
    private function normalizeHex(?string $val): ?string
    {
        if (!$val) return null;
        $val = strtolower(trim($val));
        if (isset(self::COLOR_MAP[$val])) return self::COLOR_MAP[$val];
        if (preg_match('/^#([0-9a-f]{3})$/', $val, $m)) {
            $c = $m[1];
            return '#' . $c[0].$c[0].$c[1].$c[1].$c[2].$c[2];
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $val)) return strtoupper($val);
        return null;
    }

    // FIX 1 — darken a hex colour by a percentage (0–100).
    private function darkenHex(string $hex, int $pct): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return '#' . $hex;
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $factor = max(0, min(100, 100 - $pct)) / 100;
        $r = max(0, (int) round($r * $factor));
        $g = max(0, (int) round($g * $factor));
        $b = max(0, (int) round($b * $factor));
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    // FIX 1 — apply the user's brand colors across the manifest's known
    // accent variables. Rule:
    //   - primary_color / primary → user's primary
    //   - every other industry-specific accent name → user's accent
    //     (accent defaults to secondary → primary if secondary not given)
    //   - *_deep / *_dark variants → darkened shade of whatever the base
    //     would be for that name
    // Soft/muted variants are left alone to avoid inverted tonal ranges.
    private function applyBrandColors(array &$variables, array $manifest, ?array $colors): void
    {
        if (empty($colors) || !is_array($colors)) return;
        $primary   = $this->normalizeHex($colors['primary']   ?? null);
        $secondary = $this->normalizeHex($colors['secondary'] ?? null);
        $accent    = $this->normalizeHex($colors['accent']    ?? null) ?? $secondary ?? $primary;
        if (!$primary && !$secondary && !$accent) return;
        $primaryDeep = $primary ? $this->darkenHex($primary, 12) : null;
        $accentDeep  = $accent  ? $this->darkenHex($accent,  12) : null;

        $manifestVars = $manifest['variables'] ?? [];
        foreach (self::ACCENT_VAR_NAMES as $name) {
            if (!array_key_exists($name, $manifestVars)) continue;

            $isDeep = (str_contains($name, '_deep') || str_contains($name, '-deep') || str_contains($name, '_dark'));
            $isSoft = (str_contains($name, '_soft') || str_contains($name, '_light') || str_contains($name, '_muted'));
            if ($isSoft) continue; // leave soft/muted tints untouched

            if (in_array($name, ['primary_color','primary'], true)) {
                $variables[$name] = $isDeep ? ($primaryDeep ?? $primary) : $primary;
            } else {
                // Every industry-specific accent var (gold, rose, orange,
                // terracotta, cyan, bronze, brass, forest, medical_blue,
                // violet, sage, etc.) takes the user's accent color.
                $variables[$name] = $isDeep ? ($accentDeep ?? $accent) : $accent;
            }
        }
        // Ensure the canonical trio is explicitly set for downstream CSS
        // regardless of whether the template's manifest declares them.
        if ($primary)       $variables['primary_color']   = $primary;
        if ($secondary)     $variables['secondary_color'] = $secondary;
        if ($accent)        $variables['accent_color']    = $accent;
        if ($primaryDeep)   $variables['primary_deep']    = $primaryDeep;
    }

    // FIX 1 — server-side color scanner (mirrors _arthurExtractColors in JS).
    // Returns partial ['primary' => ..., 'secondary' => ..., 'accent' => ...].
    private function scanColorsServerSide(string $msg): array
    {
        if ($msg === '') return [];
        $words = implode('|', array_keys(self::COLOR_MAP));
        $out   = [];

        // Role-explicit phrases first
        if (preg_match_all('/(?:use|make|set)\s+(#(?:[0-9a-f]{3}|[0-9a-f]{6})|[a-z]+)\s+(?:as|for)(?:\s+the)?\s+(primary|main|secondary|accent|brand)/i', $msg, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                $v = strtolower($m[1]);
                $r = strtolower($m[2]);
                if ($r === 'main' || $r === 'brand') $r = 'primary';
                if (isset(self::COLOR_MAP[$v]) || preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/', $v)) {
                    $out[$r] = $v;
                }
            }
        }

        // Positional flow: hex codes + remaining named colors into unfilled slots
        preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $msg, $hex);
        preg_match_all('/\b(' . $words . ')\b/i', $msg, $named);
        $pool = array_merge(array_map('strtolower', $hex[0] ?? []), array_map('strtolower', $named[1] ?? []));
        $slots = ['primary','secondary','accent'];
        foreach ($pool as $c) {
            foreach ($slots as $role) {
                if (empty($out[$role])) { $out[$role] = $c; break; }
            }
        }
        return $out;
    }

    // FIX 4 — returns true if $text contains a keyword from a different
    // industry's deny-list for $industry.
    private function isCrossIndustryLeak(string $text, string $industry): bool
    {
        $deny = self::INDUSTRY_DENY[$industry] ?? [];
        if (empty($deny)) return false;
        $lower = strtolower($text);
        foreach ($deny as $kw) {
            if (strpos($lower, $kw) !== false) return true;
        }
        return false;
    }

    // PATCH (template-resolution, 2026-05-09) — Maps industry keywords/
    // phrases DIRECTLY to on-disk template slugs. The legacy INDUSTRY_MAP
    // above maps to abstract "canonical industries" like 'healthcare' that
    // don't exist as templates on disk — so a "Plastic Surgery" request
    // that goes through INDUSTRY_MAP gets 'healthcare', getManifest()
    // returns null, and generateWebsite falls back to RESTAURANT. This
    // map fixes that by mapping straight to disk template slugs.
    //
    // Disk templates (2026-05-09): aesthetic_clinic, architecture,
    // automotive, barbershop, beauty_salon, cafe, catering, childcare,
    // construction, consulting, dental, ecommerce, event_venue, gym,
    // home_services, hotel, interior_design, it_services,
    // marketing_agency, medical_clinic, online_courses, pet_services,
    // real_estate_agency, resort, restaurant, retail_shop,
    // short_term_rental, training_center, travel_agency, tutoring
    //
    // Longest-match-wins (sorted by key length in resolveTemplateSlug).
    private const KEYWORD_TO_TEMPLATE = [
        // Medical / health (Bico Plastic Surgery → aesthetic_clinic)
        'plastic surgery'        => 'aesthetic_clinic',
        'cosmetic surgery'       => 'aesthetic_clinic',
        'aesthetic clinic'       => 'aesthetic_clinic',
        'aesthetic medicine'     => 'aesthetic_clinic',
        'medical spa'            => 'aesthetic_clinic',
        'medspa'                 => 'aesthetic_clinic',
        'med spa'                => 'aesthetic_clinic',
        'dermatology'            => 'aesthetic_clinic',
        'dermatologist'          => 'aesthetic_clinic',
        'cosmetic'               => 'aesthetic_clinic',
        'aesthetic'              => 'aesthetic_clinic',
        'botox'                  => 'aesthetic_clinic',
        'fillers'                => 'aesthetic_clinic',
        'laser clinic'           => 'aesthetic_clinic',
        'skin clinic'            => 'aesthetic_clinic',
        'botox clinic'           => 'aesthetic_clinic',
        'fillers clinic'         => 'aesthetic_clinic',
        'cosmetic clinic'        => 'aesthetic_clinic',
        'aesthetic doctor'       => 'aesthetic_clinic',
        'dental clinic'          => 'dental',
        'dentist'                => 'dental',
        'dental'                 => 'dental',
        'orthodontist'           => 'dental',
        'orthodontics'           => 'dental',
        'medical clinic'         => 'medical_clinic',
        'medical center'         => 'medical_clinic',
        'general practitioner'   => 'medical_clinic',
        'family doctor'          => 'medical_clinic',
        'physician'              => 'medical_clinic',
        'doctor'                 => 'medical_clinic',
        'hospital'               => 'medical_clinic',
        'clinic'                 => 'medical_clinic',
        'medical'                => 'medical_clinic',
        'healthcare'             => 'medical_clinic',
        'health clinic'          => 'medical_clinic',
        'pediatric'              => 'medical_clinic',
        'pediatrician'           => 'medical_clinic',
        'cardiology'             => 'medical_clinic',
        'gynecology'             => 'medical_clinic',
        // Food
        'restaurant'             => 'restaurant',
        'bistro'                 => 'restaurant',
        'fine dining'            => 'restaurant',
        'food truck'             => 'restaurant',
        'cafe'                   => 'cafe',
        'coffee shop'            => 'cafe',
        'bakery'                 => 'cafe',
        'patisserie'             => 'cafe',
        'tea house'              => 'cafe',
        'catering'               => 'catering',
        'caterer'                => 'catering',
        'catering service'       => 'catering',
        'event catering'         => 'catering',
        'food catering'          => 'catering',
        'wedding catering'       => 'catering',
        'corporate catering'     => 'catering',
        'private chef'           => 'catering',
        'banqueting'             => 'catering',
        // Hospitality
        'short term rental'      => 'short_term_rental',
        'short-term rental'      => 'short_term_rental',
        'vacation rental'        => 'short_term_rental',
        'airbnb'                 => 'short_term_rental',
        'beach resort'           => 'resort',
        'luxury resort'          => 'resort',
        'holiday resort'         => 'resort',
        'island resort'          => 'resort',
        'desert resort'          => 'resort',
        'spa resort'             => 'resort',
        'all-inclusive resort'   => 'resort',
        'resort'                 => 'resort',
        'boutique hotel'         => 'hotel',
        'hotel'                  => 'hotel',
        'inn'                    => 'hotel',
        'hospitality'            => 'hotel',
        // Fitness
        'crossfit'               => 'gym',
        'gym'                    => 'gym',
        'fitness'                => 'gym',
        'pilates'                => 'gym',
        'yoga studio'            => 'gym',
        'yoga'                   => 'gym',
        'personal trainer'       => 'gym',
        'personal training'      => 'gym',
        'martial arts'           => 'gym',
        // Beauty / wellness
        'beauty salon'           => 'beauty_salon',
        'hair salon'             => 'beauty_salon',
        'nail salon'             => 'beauty_salon',
        'salon'                  => 'beauty_salon',
        'spa'                    => 'beauty_salon',
        'massage'                => 'beauty_salon',
        'wellness center'        => 'beauty_salon',
        'wellness'               => 'beauty_salon',
        'beauty'                 => 'beauty_salon',
        'barbershop'             => 'barbershop',
        'barber'                 => 'barbershop',
        'mens grooming'          => 'barbershop',
        // Tech
        'it services'            => 'it_services',
        'it support'             => 'it_services',
        'managed it'             => 'it_services',
        'software development'   => 'it_services',
        'software'               => 'it_services',
        'web development'        => 'it_services',
        'app development'        => 'it_services',
        'cybersecurity'          => 'it_services',
        'cloud services'         => 'it_services',
        'saas'                   => 'it_services',
        'tech'                   => 'it_services',
        'technology'             => 'it_services',
        // Marketing
        'digital marketing'      => 'marketing_agency',
        'marketing agency'       => 'marketing_agency',
        'seo agency'             => 'marketing_agency',
        'social media agency'    => 'marketing_agency',
        'advertising agency'     => 'marketing_agency',
        'web design'             => 'marketing_agency',
        'branding agency'        => 'marketing_agency',
        'marketing'              => 'marketing_agency',
        'photography'            => 'marketing_agency',
        'photographer'           => 'marketing_agency',
        // Real estate
        'real estate agency'     => 'real_estate_agency',
        'real estate'            => 'real_estate_agency',
        'realtor'                => 'real_estate_agency',
        'property'               => 'real_estate_agency',
        'broker'                 => 'real_estate_agency',
        // Education
        'training center'        => 'training_center',
        'training centre'        => 'training_center',
        'vocational training'    => 'training_center',
        'skills training'        => 'training_center',
        'corporate training'     => 'training_center',
        'professional training'  => 'training_center',
        'certification'          => 'training_center',
        'online courses'         => 'online_courses',
        'online course'          => 'online_courses',
        'e-learning'             => 'online_courses',
        'private tutoring'       => 'tutoring',
        'tutoring'               => 'tutoring',
        'tutor'                  => 'tutoring',
        'language school'        => 'tutoring',
        'academy'                => 'tutoring',
        'school'                 => 'tutoring',
        'education'              => 'tutoring',
        'coaching'               => 'tutoring',
        // Professional services
        'consulting'             => 'consulting',
        'consultant'             => 'consulting',
        'consultancy'            => 'consulting',
        'advisory'               => 'consulting',
        'law firm'               => 'consulting',
        'lawyer'                 => 'consulting',
        'attorney'               => 'consulting',
        'legal services'         => 'consulting',
        'accounting'             => 'consulting',
        'accountant'             => 'consulting',
        'finance'                => 'consulting',
        'financial advisor'      => 'consulting',
        // Construction / trades / home
        'construction'           => 'construction',
        'contractor'             => 'construction',
        'builder'                => 'construction',
        'general contractor'    => 'construction',
        'home services'          => 'home_services',
        'home repair'            => 'home_services',
        'plumber'                => 'home_services',
        'plumbing'               => 'home_services',
        'electrician'            => 'home_services',
        'hvac'                   => 'home_services',
        'handyman'               => 'home_services',
        'cleaning'               => 'home_services',
        'cleaning services'      => 'home_services',
        'shelving installation'  => 'home_services',
        'shelving systems'       => 'home_services',
        'shelving'               => 'home_services',
        'installation services'  => 'home_services',
        'installation'           => 'home_services',
        'manufacturing'          => 'construction',
        'fabrication'            => 'construction',
        'metalwork'              => 'construction',
        'carpentry'              => 'construction',
        'woodwork'               => 'construction',
        'joinery'                => 'construction',
        // Auto
        'auto repair'            => 'automotive',
        'car repair'             => 'automotive',
        'mechanic'               => 'automotive',
        'auto detailing'         => 'automotive',
        'detailing'              => 'automotive',
        'automotive'             => 'automotive',
        'auto'                   => 'automotive',
        'car dealership'         => 'automotive',
        // Other
        'interior design'        => 'interior_design',
        'interior designer'      => 'interior_design',
        'home decor'             => 'interior_design',
        'interior decoration'    => 'interior_design',
        'fit out'                => 'interior_design',
        'fitout'                 => 'interior_design',
        'space planning'         => 'interior_design',
        'home staging'           => 'interior_design',
        'architecture'           => 'architecture',
        'architect'              => 'architecture',
        'urban planning'         => 'architecture',
        'event venue'            => 'event_venue',
        'event space'            => 'event_venue',
        'banquet hall'           => 'event_venue',
        'wedding venue'          => 'event_venue',
        'travel agency'          => 'travel_agency',
        'tour operator'          => 'travel_agency',
        'travel'                 => 'travel_agency',
        'pet services'           => 'pet_services',
        'pet grooming'           => 'pet_services',
        'pet boarding'           => 'pet_services',
        'veterinary'             => 'pet_services',
        'veterinarian'           => 'pet_services',
        'vet clinic'             => 'pet_services',
        'pet clinic'             => 'pet_services',
        'animal hospital'        => 'pet_services',
        'animal clinic'          => 'pet_services',
        'pet hospital'           => 'pet_services',
        'pet'                    => 'pet_services',
        'childcare'              => 'childcare',
        'daycare'                => 'childcare',
        'nursery'                => 'childcare',
        'preschool'              => 'childcare',
        'kindergarten'           => 'childcare',
        'online store'           => 'ecommerce',
        'e-commerce'             => 'ecommerce',
        'ecommerce'              => 'ecommerce',
        'shopify'                => 'ecommerce',
        'retail shop'            => 'retail_shop',
        'retail'                 => 'retail_shop',
        'boutique'               => 'retail_shop',
        'fashion boutique'       => 'retail_shop',
        'clothing store'         => 'retail_shop',
        'shop'                   => 'retail_shop',
        'store'                  => 'retail_shop',
        // News / media (added 2026-05-09)
        'news channel'           => 'news_channel',
        'news network'           => 'news_channel',
        'news website'           => 'news_channel',
        'news outlet'            => 'news_channel',
        'online news'            => 'news_channel',
        'newspaper'              => 'news_channel',
        'media company'          => 'news_channel',
        'media outlet'           => 'news_channel',
        'press'                  => 'news_channel',
        'broadcast'              => 'news_channel',
        'broadcasting'           => 'news_channel',
        'journalism'             => 'news_channel',
        'editorial'              => 'news_channel',
        'news'                   => 'news_channel',
    ];

    // PATCH (template-resolution, 2026-05-09) — Resolve a free-form industry
    // string to an on-disk template slug. Tries direct slug match first,
    // then longest-keyword-wins lookup, then coarse-sector keyword fallback,
    // and finally falls through to 'consulting' (more generic than the old
    // 'restaurant' default for unknown businesses).
    private function resolveTemplateSlug(string $industry): string
    {
        $industry = strtolower(trim($industry));
        if ($industry === '') return 'consulting';

        // 1. Direct disk-template match (e.g. user passed 'aesthetic_clinic')
        // Traversal hygiene: keep the slug to [a-z0-9_] so a returned slug can never carry
        // ../ into a templates/{slug}/... path (declaredPlaceholders et al.). Behaviour-preserving.
        $direct = preg_replace('/[^a-z0-9_]/', '', preg_replace('/[\s-]+/', '_', $industry));
        if ($this->templates->getManifest($direct)) return $direct;

        // 2. Longest-match-wins keyword search
        $keys = array_keys(self::KEYWORD_TO_TEMPLATE);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($keys as $kw) {
            if (strpos($industry, $kw) !== false) {
                return self::KEYWORD_TO_TEMPLATE[$kw];
            }
        }

        // 3. Coarse sector-word fallback for off-the-map industries
        $patterns = [
            '/medical|surgery|surgeon|clinic|dental|doctor|hospital|nurse|physic|pediatric|cardio|gyneco|ortho|optic/' => 'medical_clinic',
            '/cosmetic|aesthetic|botox|filler|skin|laser|derma/'                                                       => 'aesthetic_clinic',
            '/beauty|salon|spa|nail|wax|brow|lash|makeup|hair/'                                                        => 'beauty_salon',
            '/restaurant|food|kitchen|dining|bistro|grill|sushi|pizz|burger|asian|italian|chef/'                       => 'restaurant',
            '/coffee|cafe|cafe|bakery|tea|brunch|patisserie|donut|dessert|juice/'                                      => 'cafe',
            '/gym|fit|crossfit|pilates|yoga|martial|train/'                                                            => 'gym',
            '/legal|law|attorney|advisor|consult|coach|finance|accounting|tax|audit|advisory/'                         => 'consulting',
            '/agency|marketing|advertis|brand|design|seo|ppc|content|social media|public relations/'                   => 'marketing_agency',
            '/it |tech|software|cloud|cyber|saas|app|web dev|developer|sysadmin/'                                      => 'it_services',
            '/property|estate|realtor|broker|real estate/'                                                             => 'real_estate_agency',
            '/educat|tutor|teach|learn|course|class|academ|institute|school|university|coaching/'                      => 'tutoring',
            '/construct|build|contractor|trade|repair|electric|plumb|hvac|carpent|roof|paint|renovation/'              => 'construction',
            '/home services|cleaning|maid|housekeep|landscap|garden|pool service/'                                     => 'home_services',
            '/auto|car|vehicle|mechanic|detailer/'                                                                     => 'automotive',
            '/architect/'                                                                                              => 'architecture',
            '/event|wedding|banquet|venue|conference/'                                                                 => 'event_venue',
            '/news|journal|press|broadcast|tribune|gazette|media outlet|reporter/'                                     => 'news_channel',
            '/travel|tour|vacation/'                                                                                   => 'travel_agency',
            '/hotel|resort|airbnb|rental|inn|guest house|lodge|motel/'                                                 => 'hotel',
            '/pet|vet|animal|dog |cat |grooming/'                                                                     => 'pet_services',
            '/child|kid |day.*care|nursery|preschool|kindergarten|montessori/'                                         => 'childcare',
            '/retail|shop|store|boutique|fashion|clothing|jewelry/'                                                   => 'retail_shop',
            '/online store|ecommerce|e-?commerce|shopify|dropship/'                                                    => 'ecommerce',
            '/interior/'                                                                                               => 'interior_design',
        ];
        foreach ($patterns as $regex => $slug) {
            if (preg_match($regex, $industry)) return $slug;
        }

        // 4. Final fallback — 'consulting' is the most generic professional
        // template; safer than 'restaurant' for unknown businesses.
        return 'consulting';
    }

    // PATCH (name-grounding, 2026-07-24) — Derive a template slug from a
    // business NAME (or any free text) but ONLY when the text carries a real
    // industry signal; returns null otherwise so callers can fall back to the
    // LLM's classification. Unlike resolveTemplateSlug (which is fed a
    // user-typed industry string and safely uses bare substring matching),
    // this scans a NAME, where bare substrings are dangerous — "Caspian
    // Travel" contains "spa", "Bishop & Co" contains "shop", "Winner Gym"
    // contains "inn". So every match here is WORD-BOUNDARIED. Longest keyword
    // wins, mirroring resolveTemplateSlug's precedence.
    private function confidentSlugFromText(?string $text): ?string
    {
        $text = strtolower(trim((string) $text));
        if ($text === '') return null;

        // 1. Word-boundaried longest-keyword-wins over the disk-slug map.
        $keys = array_keys(self::KEYWORD_TO_TEMPLATE);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($keys as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $text)) {
                return self::KEYWORD_TO_TEMPLATE[$kw];
            }
        }

        // 2. Small curated stem set for morphological variants the exact-word
        // map misses ("Architectural", "Dentistry", "Orthodontics",
        // "Veterinary"). Prefix-boundaried and deliberately unambiguous so a
        // name can never over-trigger an override.
        $stems = [
            '/\barchitect/'   => 'architecture',
            '/\bdent(al|ist)/' => 'dental',
            '/\borthodont/'   => 'dental',
            '/\bveterinar/'   => 'pet_services',
            '/\bshelv/'       => 'home_services',
        ];
        foreach ($stems as $regex => $slug) {
            if (preg_match($regex, $text)) return $slug;
        }

        // No confident signal in the name — let the LLM's guess stand.
        return null;
    }

    // PATCH (image-injection, 2026-05-09) — Build a fallback image pool
    // from the platform media library, prioritised by industry tag.
    //
    // 1. Workspace-owned uploads tagged with the industry slug
    // 2. Platform-asset images tagged with the industry slug
    // 3. Platform-asset images in category=template_image OR category=hero
    //
    // Returns up to ~20 distinct URLs so injectImagesToTemplate can fill
    // every image slot in the template even with no uploaded images.
    private function buildImagePool(string $industry, int $wsId): array
    {
        $pool = [];
        try {
            // Tier 1 — workspace's own uploads tagged with the industry
            $rows = \Illuminate\Support\Facades\DB::table('media')
                ->where('workspace_id', $wsId)
                ->where('asset_type', 'image')
                ->whereNotNull('url')
                ->where('url', '!=', '')
                ->whereRaw('JSON_CONTAINS(tags, ?)', ['"' . $industry . '"'])
                ->limit(15)
                ->pluck('url')->toArray();
            foreach ($rows as $u) if ($u && !in_array($u, $pool, true)) $pool[] = $u;

            // Tier 2 — platform-asset images tagged with this industry
            if (count($pool) < 15) {
                $rows = \Illuminate\Support\Facades\DB::table('media')
                    ->where('is_platform_asset', 1)
                    ->where('asset_type', 'image')
                    ->whereNotNull('url')
                    ->where('url', '!=', '')
                    ->whereRaw('JSON_CONTAINS(tags, ?)', ['"' . $industry . '"'])
                    ->inRandomOrder()
                    ->limit(15)->pluck('url')->toArray();
                foreach ($rows as $u) if ($u && !in_array($u, $pool, true)) $pool[] = $u;
            }

            // Tier 3 — generic platform-asset template/hero images (any
            // industry) as a last-resort floor so empty slots aren't blank.
            if (count($pool) < 8) {
                $rows = \Illuminate\Support\Facades\DB::table('media')
                    ->where('is_platform_asset', 1)
                    ->where('asset_type', 'image')
                    ->whereIn('category', ['template_image', 'hero'])
                    ->whereNotNull('url')
                    ->where('url', '!=', '')
                    ->inRandomOrder()
                    ->limit(8)->pluck('url')->toArray();
                foreach ($rows as $u) if ($u && !in_array($u, $pool, true)) $pool[] = $u;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] buildImagePool query failed: ' . $e->getMessage());
        }
        return $pool;
    }

    // PATCH (image-injection, 2026-05-09) — Fill every manifest type=image
    // variable that's still empty (or still on the industry-hero floor URL)
    // from the media pool. Each pool image is consumed at most once per
    // call; if the pool runs out we cycle back to the start so no var is
    // left blank. Logo and OG slots are deliberately excluded — logo has
    // its own opt-in flow, og falls through to hero.
    //
    // Also writes image_1..image_10 aliases pointing at the same pool so
    // any future template that uses generic image slots Just Works.
    private function injectImagesToTemplate(array &$variables, array $manifest, array $pool, ?string $heroDefaultUrl, bool $logoUploadOptIn): void
    {
        if (empty($manifest['variables'])) return;

        // Build the ordered list of slots that still need filling.
        $needFill = [];
        foreach ($manifest['variables'] as $varKey => $varSpec) {
            $type = is_array($varSpec) ? ($varSpec['type'] ?? 'text') : 'text';
            if ($type !== 'image') continue;
            if ($varKey === 'logo_url' && !$logoUploadOptIn) continue;
            if ($varKey === 'og_image') continue; // handled separately
            $current = $variables[$varKey] ?? '';
            $isFloor = ($current === '' || $current === null
                || ($heroDefaultUrl && $current === $heroDefaultUrl));
            // hero_image specifically is allowed to keep the hero floor —
            // it's the most prominent image and the floor IS the right
            // industry hero. Only fill OTHER image vars from the pool.
            if ($varKey === 'hero_image' && $current === $heroDefaultUrl) continue;
            if ($isFloor) $needFill[] = $varKey;
        }

        if (empty($needFill)) {
            // Even with nothing to fill on the manifest, set the generic
            // image_1..image_10 aliases for any template that uses them.
        } elseif (empty($pool)) {
            // No media to draw from — leave slots on whatever floor they
            // already have (manifest default / hero floor / empty).
        } else {
            $i = 0;
            foreach ($needFill as $varKey) {
                $variables[$varKey] = $pool[$i % count($pool)];
                $i++;
            }
        }

        // Generic image_1..image_10 aliases — same pool, cycled.
        if (!empty($pool)) {
            for ($n = 1; $n <= 10; $n++) {
                if (empty($variables['image_' . $n])) {
                    $variables['image_' . $n] = $pool[($n - 1) % count($pool)];
                }
            }
        }
    }

    // PATCH (Option C, 2026-05-09) — Seed 6 LLM-generated news articles
    // into the articles table for a freshly-built news_channel site.
    // Mutates $variables to populate static story_N_* slots so the page
    // is non-empty + SEO-friendly on first paint. The PublicNewsController
    // /api/public/news/{subdomain}/stories endpoint then serves the same
    // articles dynamically for live updates.
    private function seedNewsArticles(int $wsId, string $businessName, string $location, array &$variables): int
    {
        $categories = ['Politics', 'Business', 'Technology', 'Sports', 'Culture', 'World'];

        if (! $this->runtime->isConfigured()) {
            Log::warning('[Arthur] runtime not configured — skipping news article seeding');
            return 0;
        }

        $userPrompt = "Generate 6 realistic news headlines and excerpts for a news channel called '{$businessName}' "
            . "based in {$location}. One article per category. Categories: "
            . implode(', ', $categories) . ".\n\n"
            . "Return JSON with the word json — an object with key \"articles\" containing an array of 6 items, "
            . "each shaped: {\"title\": \"verb-led news headline, ~10 words\", "
            . "\"category\": \"one of the 6 categories\", "
            . "\"excerpt\": \"two-sentence news excerpt that reads like real reporting\", "
            . "\"author\": \"professional journalist name with surname\", "
            . "\"read_time_minutes\": 3-9}\n\n"
            . "Tone: editorial, authoritative, journalistic. Headlines must sound like real news with a verb "
            . "and a real claim — never marketing copy. NEVER mention dining, food, fitness, salon, or product sales.";

        try {
            $result = $this->runtime->chatJson(
                "You are a senior news editor at a premium Gulf-region newsroom. Return only valid JSON.",
                $userPrompt,
                ['workspace_id' => $wsId, 'task' => 'news_seed'],
                1500
            );
        } catch (\Throwable $e) {
            Log::warning('[Arthur] news seed chatJson threw: ' . $e->getMessage());
            return 0;
        }

        $parsed = is_array($result['parsed'] ?? null) ? $result['parsed'] : [];
        $articles = $parsed['articles'] ?? $parsed ?? [];
        if (!is_array($articles)) return 0;

        $count = 0;
        foreach ($articles as $i => $a) {
            if (!is_array($a) || empty($a['title'])) continue;
            if ($count >= 6) break;

            $title    = mb_substr((string) $a['title'], 0, 250);
            $category = (string) ($a['category'] ?? $categories[$i % 6]);
            $excerpt  = mb_substr((string) ($a['excerpt'] ?? ''), 0, 480);
            $author   = mb_substr((string) ($a['author'] ?? 'Staff Reporter'), 0, 80);
            $readMin  = (int) ($a['read_time_minutes'] ?? 4);
            if ($readMin < 1) $readMin = 3;
            $publishedAt = now()->subHours($i * 8 + rand(1, 4));

            $slug = \Illuminate\Support\Str::slug($title) ?: ('news-' . uniqid());

            try {
                DB::table('articles')->insertOrIgnore([
                    'workspace_id'      => $wsId,
                    'title'             => $title,
                    'slug'              => $slug,
                    'content'           => '<p>' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>',
                    'excerpt'           => $excerpt,
                    'status'            => 'published',
                    'type'              => 'news',
                    'blog_category'     => $category,
                    'tags_json'         => json_encode([$category, 'news', 'seed']),
                    'is_marketing_blog' => 0,
                    'featured_image_url'=> '',
                    'meta_title'        => $title,
                    'meta_description'  => $excerpt,
                    'focus_keyword'     => $category,
                    'brief_json'        => json_encode([
                        'author'    => $author,
                        'read_time' => $readMin . ' min read',
                        'seed'      => true,
                    ]),
                    'word_count'        => str_word_count($excerpt),
                    'read_time'         => $readMin,
                    'published_at'      => $publishedAt,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                // Inject into static template variables (story_1..story_6).
                $n = $count + 1;
                $variables["story_{$n}_title"]        = $title;
                $variables["story_{$n}_category"]     = strtoupper($category);
                $variables["story_{$n}_excerpt"]      = $excerpt;
                $variables["story_{$n}_byline"]       = $author;
                $variables["story_{$n}_date"]         = $publishedAt->diffForHumans();
                $variables["story_{$n}_reading_time"] = $readMin . ' min read';

                $count++;
            } catch (\Throwable $e) {
                Log::warning('[Arthur] news article insert failed: ' . $e->getMessage());
            }
        }

        return $count;
    }

    // BUG 2 FIX — normalize an industry string to a canonical slug.
    // Preference order: explicit keyword from INDUSTRY_MAP (longest match
    // wins) > already-valid slug > raw input.
    private function normalizeIndustry(?string $raw, string $message): ?string
    {
        $haystack = strtolower(trim(($raw ?? '') . ' ' . $message));
        if ($haystack === '') return $raw;
        $keys = array_keys(self::INDUSTRY_MAP);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($keys as $kw) {
            if (strpos($haystack, $kw) !== false) {
                return self::INDUSTRY_MAP[$kw];
            }
        }
        if ($raw && in_array(strtolower($raw), self::VALID_INDUSTRIES, true)) {
            return strtolower($raw);
        }
        return $raw;
    }

    // BUG 1 FIX — build the all-fields confirmation bubble shown once the
    // first rich message extraction completes. Only includes non-empty
    // fields, so a user who didn't mention e.g. pages won't see an empty
    // "📄 Pages:" line.
    private function buildConfirmationMessage(array $s): string
    {
        $name = $s['business_name'] ?? 'your business';
        $lines = ["Got it! Here's what I have:\n"];
        $lines[] = "🏢 Business: " . $name;

        if (!empty($s['colors']) && is_array($s['colors'])) {
            $cp = $s['colors']['primary']   ?? null;
            $cs = $s['colors']['secondary'] ?? null;
            $cc = $s['colors']['accent']    ?? null;
            $clist = array_values(array_filter([$cp, $cs, $cc]));
            if ($clist) $lines[] = "🎨 Colors: " . implode(' + ', $clist);
        }
        if (!empty($s['location']))      $lines[] = "📍 Location: " . $s['location'];
        if (!empty($s['services'])) {
            $svc = is_array($s['services']) ? implode(', ', $s['services']) : (string)$s['services'];
            $lines[] = "🛠 Services: " . $svc;
        }
        if (!empty($s['target_market'])) $lines[] = "👥 Target: " . $s['target_market'];
        if (!empty($s['pages'])) {
            $pgs = is_array($s['pages'])
                ? implode(', ', array_map(fn($p) => ucfirst(strtolower((string)$p)), $s['pages']))
                : (string)$s['pages'];
            $lines[] = "📄 Pages: " . $pgs;
        }
        $lines[] = "\nDoes this look right?";
        return implode("\n", $lines);
    }

    /**
     * NEW conversational entry point — pure LLM, no regex, no scripted steps.
     *
     * PATCH (Arthur AI conversation, 2026-05-09): the spec called for replacing
     * the entire scripted-wizard flow (handleMessage + extractAllFields +
     * extractFields + simpleExtract + getNextQuestion + scanColorsServerSide
     * + isCrossIndustryLeak + ...) with a single LLM-driven dialogue.
     *
     * Arthur talks like Sarah — natural, contextual, intelligent. He
     * decides when he has enough info to build the website and signals
     * via a [READY_TO_BUILD] marker followed by JSON.
     *
     * Returns:
     *   [
     *     'type'           => 'question' | 'complete',
     *     'reply'          => string,                  // user-visible text
     *     'ready_to_build' => bool,
     *     'build_data'     => array,                   // when ready_to_build
     *     'history'        => array,                   // updated for next turn
     *   ]
     *
     * The route handler (POST /api/builder/arthur/message) is responsible for
     * calling buildFromChat() if ready_to_build is true. This keeps chat()
     * pure (no DB writes, no template work) so it can be unit-tested as a
     * conversation function.
     *
     * handleMessage() above remains for callers that want the older scripted
     * flow with template picker + image upload + color extraction. The
     * route /api/builder/arthur/message now uses chat() exclusively;
     * handleMessage is reachable via direct service calls only.
     */
    public function chat(int $workspaceId, string $userMessage, array $history = []): array
    {
        $systemPrompt = <<<'PROMPT'
You are Arthur, an expert AI website builder for LevelUp Growth.
You have a natural, warm conversation to understand a business
then build them a professional website.

You are like a skilled web designer on a discovery call.
NOT a form. NOT a robot. A real conversation.

Through natural dialogue, learn:
- Business name
- What they do / industry
- Location / who they serve
- Main services or products
- Style preference (modern, luxury, minimal, classic)

Rules:
- Ask 1-2 questions at a time maximum
- React naturally to what they say
- If they give multiple details at once, acknowledge all
- Keep responses to 2-3 sentences max
- Be warm, encouraging, professional
- Never repeat a question you already got an answer to
- Use "I'll" not "I can"; never hedge ("maybe", "I think")

When you have enough to build (minimum: name + industry + location),
DO NOT build immediately. First show the user a confirmation summary
so they can review and optionally upload a logo. Return ONLY a JSON
object with these fields:
  {"reply": "<summary message — see SUMMARY FORMAT below>",
   "ready_to_confirm": true,
   "build_data": {"business_name":"...","industry":"...","location":"...","services":"...","style":"modern","description":"..."}}

SUMMARY FORMAT — when ready_to_confirm is true, the "reply" field
MUST be the following summary, translated into the user's language
(keep emoji and **bold** markdown intact). DO NOT ask any questions —
the frontend renders an interactive panel below your message where
the user can upload a logo, add images, pick brand colors, and click
Build. Your reply is just the recap:

Here's what I have for your website:

🏢 **Business:** {business name}
📍 **Location:** {location}
🎯 **Industry:** {industry}
⚙️ **Services:** {services}
🎨 **Style:** {style}

Add your logo, photos, and brand colors below — then I'll build it.

Until you have enough, return a JSON object with:
  {"reply": "<your short conversational message>",
   "ready_to_confirm": false}

Never set "ready_to_build" yourself — the frontend triggers the actual
build after the user clicks confirm. Always use "ready_to_confirm".

Always respond with valid JSON only — no prose outside the JSON object.
The "reply" field must be in the same language the user is writing in
(Arabic, German, French, Chinese, Korean, Hindi, Urdu, Tagalog, etc.) —
match the user's language naturally. The JSON keys themselves stay
in English; only the value of "reply" mirrors the user's language.
PROMPT;

        // PATCH (i18n 2026-05-09) — append the platform-wide language rule.
        // Single-quoted heredoc above can't interpolate, so concatenate.
        $systemPrompt .= "\n\n" . \App\Core\LLM\PromptTemplates::languageRule();

        // PATCH (Arthur fix 2026-05-09) — speed: trim history to last 10 turns
        // (5 user + 5 arthur). Prevents prompt bloat after long conversations
        // and keeps Arthur as snappy as Sarah / the LevelUp Assistant.
        if (count($history) > 10) {
            $history = array_slice($history, -10);
        }

        // Build a conversation transcript and pass as the user prompt body
        // (chatJson takes a single user prompt; conversation history is
        // folded in as the prompt context). The runtime's chat_json task
        // uses response_format: json_object so the model returns parsed JSON.
        $transcript = '';
        foreach ($history as $msg) {
            $role = (($msg['role'] ?? '') === 'arthur') ? 'Arthur' : 'User';
            $content = (string) ($msg['content'] ?? '');
            if ($content !== '') $transcript .= "{$role}: {$content}\n";
        }
        $transcript .= "User: {$userMessage}";

        $reply           = '';
        $readyToBuild    = false;
        $readyToConfirm  = false;
        $buildData       = [];

        try {
            $result = $this->runtime->chatJson($systemPrompt, $transcript, [
                'workspace_id' => $workspaceId,
                'task'         => 'arthur_chat',
            ], 800);

            // PATCH (Arthur fix 2026-05-09) — defensive widened reply extraction.
            // The runtime's chat_json sometimes returns the reply under different
            // keys depending on whether DeepSeek's response_format=json_object
            // succeeded in parsing or fell back to raw text. Cover all known
            // shapes so we never show an empty bubble.
            $parsed = is_array($result['parsed'] ?? null) ? $result['parsed'] : [];
            $reply  = (string) (
                $parsed['reply']
                ?? $parsed['response']
                ?? $parsed['message']
                ?? $parsed['content']
                ?? $result['reply']
                ?? $result['response']
                ?? $result['content']
                ?? $result['text']
                ?? ''
            );
            $readyToBuild   = (bool) ($parsed['ready_to_build']   ?? $result['ready_to_build']   ?? false);
            $readyToConfirm = (bool) ($parsed['ready_to_confirm'] ?? $result['ready_to_confirm'] ?? false);
            $buildDataRaw = $parsed['build_data'] ?? $result['build_data'] ?? null;
            $buildData    = is_array($buildDataRaw) ? $buildDataRaw : [];
            if (($readyToBuild || $readyToConfirm) && empty($buildData['business_name'])) {
                // protect against the model claiming ready without payload
                $readyToBuild   = false;
                $readyToConfirm = false;
            }

            // PATCH (Arthur Arabic fix 2026-05-09) — sanitize the reply through
            // mb_convert_encoding so any invalid UTF-8 sequences (rare, but
            // possible when the runtime serializes mixed-script content) get
            // dropped rather than triggering a broken JSON response on the
            // way back to the browser. UTF-8 → UTF-8 is the canonical
            // strip-invalid-bytes idiom in PHP. Verified end-to-end with
            // Arabic ("هل يمكنك التحدث باللغة العربية؟") returning 94-char
            // valid UTF-8 reply.
            if (function_exists('mb_convert_encoding')) {
                $reply = mb_convert_encoding($reply, 'UTF-8', 'UTF-8');
            }
            // Belt-and-suspenders: if reply is still empty, try the runtime's
            // raw text payload as a last resort before falling through to the
            // hiccup message.
            if ($reply === '' || $reply === null) {
                $rawText = $result['text'] ?? $result['raw'] ?? '';
                if (is_string($rawText) && $rawText !== '') {
                    $reply = $rawText;
                }
            }

            // PATCH (Arthur fix 2026-05-09) — temporary debug log so we can
            // see exactly what shape the runtime returned in logs when a
            // user reports an empty bubble. Drop this log line once the
            // empty-response issue is confirmed eliminated.
            if ($reply === '') {
                Log::warning('[Arthur:chat] reply extraction came up empty', [
                    'workspace_id' => $workspaceId,
                    'success'      => $result['success'] ?? null,
                    'error'        => $result['error'] ?? null,
                    'parsed_keys'  => array_keys($parsed),
                    'raw_keys'     => array_keys($result),
                    'parsed_dump'  => json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'text_len'     => isset($result['text']) ? strlen((string) $result['text']) : null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur:chat] runtime call threw: ' . $e->getMessage(), [
                'workspace_id' => $workspaceId,
            ]);
        }

        if ($reply === '') {
            $reply = "I'm having a brief connection hiccup — could you say that again?";
            $readyToBuild   = false;
            $readyToConfirm = false;
            $buildData = [];
        }

        $newHistory = array_merge($history, [
            ['role' => 'user',   'content' => $userMessage],
            ['role' => 'arthur', 'content' => $reply],
        ]);

        // PATCH (Arthur confirm flow 2026-05-09) — when the LLM signals
        // ready_to_confirm, cache the build_data server-side so a follow-up
        // {confirm:true} POST can retrieve it without trusting client echo.
        // 5 minute TTL is plenty for a user to upload a logo + click build.
        if ($readyToConfirm && !empty($buildData['business_name'])) {
            try {
                \Illuminate\Support\Facades\Cache::put(
                    'arthur_build_data_' . $workspaceId,
                    $buildData,
                    300
                );
            } catch (\Throwable $e) {
                Log::warning('[Arthur:chat] cache put failed: ' . $e->getMessage());
            }
        }

        $type = 'question';
        if ($readyToBuild)        $type = 'complete';
        elseif ($readyToConfirm)  $type = 'confirm';

        return [
            'type'             => $type,
            'reply'            => $reply,
            'ready_to_build'   => $readyToBuild,
            'ready_to_confirm' => $readyToConfirm,
            'build_data'       => $buildData,
            'history'          => $newHistory,
        ];
    }

    /**
     * Public wrapper around the existing private generateWebsite() so the
     * /arthur/message route can trigger website creation when the user
     * confirms via the full confirm panel (logo + images + colors + build).
     *
     * Plumbs through:
     *   - $logoUrl  → $data['logo_url'] + $data['logo_upload']=true
     *   - $images   → $data['uploaded_images']  (consumed at line ~1383
     *                 of generateWebsite, distributed across all image
     *                 manifest vars in order, hard-capped at 10)
     *   - $colors   → $data['colors']           (applied via applyBrandColors)
     *
     * Returns whatever generateWebsite returns: typically
     *   ['type' => 'website_created'|'error', 'website_id' => ?, 'website_url' => ?, 'message' => ?]
     */
    public function buildFromChat(
        int $workspaceId,
        array $buildData,
        ?string $logoUrl = null,
        array $images = [],
        array $colors = []
    , ?int $actorId = null): array {
        if ($logoUrl !== null && $logoUrl !== '') {
            $buildData['logo_url']    = $logoUrl;
            $buildData['logo_upload'] = true;
        }
        if (!empty($images)) {
            $buildData['uploaded_images'] = array_slice(array_values($images), 0, 10);
        }
        if (!empty($colors)) {
            $buildData['colors'] = array_merge(
                (array) ($buildData['colors'] ?? []),
                array_filter($colors, fn($v) => is_string($v) && $v !== '')
            );
        }
        return $this->generateWebsite($workspaceId, $buildData, $actorId);
    }

    /**
     * Handle a message from the user in the Arthur chat.
     */
    public function handleMessage(int $wsId, string $message, array $history = [], array $state = [], ?array $colorsFromPayload = null): array
    {
        // FIX 1 — merge colors from the payload (client extracts, server trusts)
        // and also scan this message server-side as a safety net in case the
        // client failed to extract.
        if (is_array($colorsFromPayload)) {
            $state['colors'] = array_merge($state['colors'] ?? [], array_filter($colorsFromPayload, fn($v) => $v !== null && $v !== ''));
        }
        $serverScan = $this->scanColorsServerSide($message);
        if (!empty($serverScan)) {
            $state['colors'] = $state['colors'] ?? [];
            foreach (['primary','secondary','accent'] as $role) {
                if (empty($state['colors'][$role]) && !empty($serverScan[$role])) {
                    $state['colors'][$role] = $serverScan[$role];
                }
            }
        }

        // Step 1: Extract business info from message + history.
        // When the client has already progressed past extraction (template
        // picked, or images decided), the state is authoritative — skip the
        // LLM round-trip and trust what the UI sent. Otherwise extraction
        // can flip ready=true back to ready=false and stall the flow.
        $inConfirmationFlow = !empty($state['template_confirmed']) || !empty($state['images_done']);
        if ($inConfirmationFlow) {
            $extracted = $state;
            // Recompute ready locally so stale state doesn't misfire.
            $extracted['ready'] = !empty($extracted['business_name']) && !empty($extracted['industry']);
        } else {
            // BUG 1 FIX — rich multi-field extraction in a single LLM call.
            // Pulls business_name, industry, services, location, target_market,
            // colors, pages all at once so Arthur never drops context from a
            // detailed first message.
            $extracted = $this->extractAllFields($message, $history, $state);
            // extractAllFields() may overwrite colors — restore from $state.
            if (!empty($state['colors'])) $extracted['colors'] = $state['colors'];
        }

        // Step 2: Check if ready to generate
        // FIX 5 (2026-04-20) — expanded required set so Arthur collects a
        // complete brief before rendering templates. `services` in the
        // extractor is the canonical field for "core_service(s)".
        $required = ['business_name', 'industry', 'services', 'location'];
        $missing = [];
        foreach ($required as $f) {
            $val = $extracted[$f] ?? null;
            $empty = ($val === null || $val === '' || (is_array($val) && count($val) === 0));
            if ($empty) $missing[] = $f;
        }
        // Back-compat: legacy callers expect 'core_service' alias for 'services'.
        $missingForClient = array_map(fn($f) => $f === 'services' ? 'core_service' : $f, $missing);

        $isReady = empty($missing) && ($extracted['ready'] ?? false);

        if ($isReady) {
            // BUG 1 FIX — before showing the template gallery, surface a
            // single "here is everything I pulled out of your message"
            // bubble so the user can confirm or correct details in one
            // shot. Only fires once: state.details_confirmed gates it.
            if (empty($extracted['details_confirmed']) && empty($extracted['template_confirmed'])) {
                return [
                    'type'     => 'confirm_details',
                    'message'  => $this->buildConfirmationMessage($extracted),
                    'state'    => $extracted,
                    'progress' => $this->getProgress($extracted),
                    'details'  => [
                        'business_name' => $extracted['business_name'] ?? '',
                        'industry'      => $extracted['industry']      ?? '',
                        'colors'        => $extracted['colors']        ?? [],
                        'location'      => $extracted['location']      ?? '',
                        'services'      => $extracted['services']      ?? [],
                        'target_market' => $extracted['target_market'] ?? '',
                        'pages'         => $extracted['pages']         ?? [],
                    ],
                ];
            }

            // 2026-04-21 — Silent template resolution.
            // Previous behaviour: showed a template_slider (for ambiguous
            // industries) or template_pick (single card) and waited for the
            // user to click "Use Template". That UX has been removed — Arthur
            // now silently resolves the best template from conversation
            // keywords and continues collecting remaining required fields.
            if (empty($extracted['template_confirmed']) && !empty($extracted['industry'])) {
                $industry = strtolower((string) $extracted['industry']);

                // Build haystack of everything the user has said so we can
                // look for secondary qualifying keywords.
                $haystack = ' ' . mb_strtolower((string) $message);
                foreach ((array) $history as $h) {
                    if (is_array($h) && isset($h['content'])) {
                        $haystack .= ' ' . mb_strtolower((string) $h['content']);
                    } elseif (is_string($h)) {
                        $haystack .= ' ' . mb_strtolower($h);
                    }
                }

                // Map each possible starting industry to a disambiguation
                // rule set. Each rule is [candidate, [keywords]]. First
                // match wins. A rule with [] keywords means "default if no
                // other rule matched".
                $rules = [
                    // "real estate" + broker/agent/personal → real_estate_broker
                    //              + agency/company/listings/properties → real_estate
                    //              (no qualifier) → real_estate
                    'real_estate' => [
                        ['real_estate_broker', ['broker', 'agent', 'personal', 'individual', 'realtor', 'my own', 'independent']],
                        ['real_estate',        ['agency', 'company', 'listings', 'properties', 'brokerage', 'team', 'office']],
                        ['real_estate',        []],
                    ],
                    // tech + software/saas/app/platform → technology
                    //      + marketing/agency/creative   → marketing_agency
                    //      (no qualifier) → technology
                    'technology' => [
                        ['technology',       ['software', 'saas', 'app ', 'platform', 'startup', 'product', 'developer']],
                        ['marketing_agency', ['marketing', 'agency', 'creative', 'brand', 'campaign', 'advertising']],
                        ['technology',       []],
                    ],
                    // fitness + yoga/wellness/holistic/mindfulness → wellness
                    //         + gym/training/crossfit/weights/class → fitness
                    //         (no qualifier) → fitness
                    'fitness' => [
                        ['wellness', ['yoga', 'wellness', 'holistic', 'mindfulness', 'meditation', 'spa', 'pilates', 'reiki']],
                        ['fitness',  ['gym', 'training', 'crossfit', 'weights', 'class', 'workout', 'hiit', 'strength', 'bodybuilding']],
                        ['fitness',  []],
                    ],
                    // restaurant + cafe/coffee/bakery/brunch → cafe
                    //            (no qualifier) → restaurant
                    'restaurant' => [
                        ['cafe',       ['cafe', 'coffee', 'bakery', 'brunch', 'pastry', 'espresso', 'tea room']],
                        ['restaurant', []],
                    ],
                    // consulting + legal/law/attorney/lawyer → legal
                    //            (no qualifier) → consulting
                    'consulting' => [
                        ['legal',      ['legal', 'law', 'attorney', 'lawyer', 'litigation', 'barrister', 'counsel', 'solicitor']],
                        ['consulting', []],
                    ],
                    // design + interior/home/decor/space → interior_design
                    //        + architecture/architect/building → architecture
                    //        (no qualifier) → interior_design
                    'design' => [
                        ['interior_design', ['interior', 'home', 'decor', 'space', 'furniture', 'residential', 'living room', 'hospitality interior']],
                        ['architecture',    ['architecture', 'architect', 'building', 'structure', 'urban', 'construction design']],
                        ['interior_design', []],
                    ],
                ];

                // Aliases — map industries that are already one of the leaf
                // candidates back to their group so disambiguation still runs
                // if the user provides different secondary keywords.
                $groupAliases = [
                    'real_estate_broker' => 'real_estate',
                    'marketing_agency'   => 'technology',
                    'wellness'           => 'fitness',
                    'cafe'               => 'restaurant',
                    'legal'              => 'consulting',
                    'interior_design'    => 'design',
                    'architecture'       => 'design',
                ];
                $group = $groupAliases[$industry] ?? $industry;

                if (isset($rules[$group])) {
                    foreach ($rules[$group] as [$candidate, $keywords]) {
                        if (empty($keywords)) { $industry = $candidate; break; }
                        $matched = false;
                        foreach ($keywords as $kw) {
                            if (mb_strpos($haystack, mb_strtolower($kw)) !== false) { $matched = true; break; }
                        }
                        if ($matched) { $industry = $candidate; break; }
                    }
                }

                $extracted['industry'] = $industry;
                $extracted['template_confirmed'] = true;

                // At this point all required fields are already present
                // (this block is gated by $isReady). Acknowledge silently
                // and jump straight to the image-upload step that used to
                // come AFTER template_confirmed.
                $extracted['industry_announced'] = true;
                $prettyInd = ucwords(str_replace('_', ' ', $industry));
                $name = $extracted['business_name'] ?? 'your business';
                return [
                    'type'     => 'image_upload',
                    'message'  => "Perfect! I'll build your **{$prettyInd}** website. Want to upload a few photos of **{$name}** (team, workspace, past work)? I'll use them across the site — gallery, team, services. The hero image stays AI-generated.\n\nUpload up to **10 photos**, or skip to build with stock imagery.",
                    'state'    => $extracted,
                    'max'      => 10,
                    'progress' => $this->getProgress($extracted),
                ];
            }
            // Template confirmed — next, prompt the user to upload photos
            // (gallery / team / services). Hero remains AI-generated.
            // Skip path: state.images_done = true AND uploaded_images = [].
            if (empty($extracted['images_done'])) {
                $name = $extracted['business_name'] ?? 'your business';
                return [
                    'type'     => 'image_upload',
                    'message'  => "Perfect. Want to upload a few photos of **{$name}** (team, workspace, past work)? I'll use them across the site — gallery, team, services. The hero image stays AI-generated.\n\nUpload up to **10 photos**, or skip to build with stock imagery.",
                    'state'    => $extracted,
                    'max'      => 10,
                    'progress' => $this->getProgress($extracted),
                ];
            }

            // T1 (2026-04-20) — logo step comes AFTER images_done, BEFORE build.
            // State flag `logo_decided` gates: once user picks Upload or Skip,
            // this branch is bypassed.
            if (empty($extracted['logo_decided'])) {
                return [
                    'type'     => 'logo_upload',
                    'message'  => "Do you have a logo? I'll place it in the nav and footer of your website.",
                    'state'    => $extracted,
                    'progress' => $this->getProgress($extracted),
                ];
            }

            // T2 (2026-04-20) — if a palette was proposed but not confirmed,
            // hold here. palette_choice fires only when palettes[] is present.
            if (!empty($extracted['palettes_proposed']) && empty($extracted['palette'])) {
                return [
                    'type'     => 'palette_choice',
                    'message'  => 'I found these colors in your logo. Which palette works for your brand?',
                    'state'    => $extracted,
                    'palettes' => $extracted['palettes_proposed'],
                    'progress' => $this->getProgress($extracted),
                ];
            }

            // Generate the website (template confirmed + image choice made + logo step done).
            return $this->generateWebsite($wsId, $extracted);
        }

        // Step 3: Ask for missing info
        $question = $this->getNextQuestion($missing, $extracted);
        $progress = $this->getProgress($extracted);

        return [
            'type' => 'question',
            'message' => $question,
            'state' => $extracted,
            'progress' => $progress,
        ];
    }

    // BUG 1 FIX — single-shot extraction of every field Arthur cares about.
    // Called instead of extractFields() from handleMessage(). Returns the
    // merged state (never overwrites existing non-empty values with null).
    // Post-processes the industry through normalizeIndustry() so "digital
    // marketing" lands on "technology" even if the LLM guesses otherwise.
    private function extractAllFields(string $message, array $history, array $state): array
    {
        if (!$this->runtime->isConfigured()) {
            $state = $this->simpleExtract($message, $state, $history);
            $state['industry'] = $this->normalizeIndustry($state['industry'] ?? null, $message);
            $state['ready'] = !empty($state['business_name']) && !empty($state['industry']);
            return $state;
        }

        $historyText = '';
        foreach (array_slice($history, -8) as $h) {
            $role = ($h['role'] ?? 'user') === 'assistant' ? 'Arthur' : 'User';
            $historyText .= "{$role}: {$h['content']}\n";
        }

        $system = <<<PROMPT
You are Arthur, an AI website builder assistant. Extract EVERY business detail the user gives you in ONE shot.

Return a JSON object (include the word "json") with these keys. Use null for fields that are genuinely missing — do NOT invent data.

- business_name: string
- industry: one of [restaurant, interior_design, fitness, healthcare, legal, real_estate, fashion, technology, events, beauty]. IMPORTANT MAPPING:
  * digital marketing / marketing agency / seo / web design / social media / advertising / it / software / app / saas / tech → "technology"
  * law firm / lawyer / attorney / consulting → "legal"
  * clinic / doctor / dental / hospital → "healthcare"
  * gym / yoga / pilates / personal trainer → "fitness"
  * salon / spa / barbershop → "beauty"
  * restaurant / cafe / bistro → "restaurant"
  * boutique / clothing / fashion → "fashion"
  * interior / fit-out / joinery → "interior_design"
  * property / real estate → "real_estate"
  * wedding / events → "events"
- services: array of short strings (e.g. ["SEO","website design","social media","paid ads"])
- location: string (city, e.g. "Dubai")
- target_market: string (who the business serves — e.g. "small and medium sized businesses")
- colors: object {primary: string|null, secondary: string|null} — named color or hex
- pages: array of page names if the user lists them (e.g. ["home","about","services","testimonials","blog","contact"])
- tagline, hero_title (3-6 words, plain text), hero_subtitle, hero_cta (3-4 words), about_text_1, meta_description

Add "ready": true if business_name AND industry are both present. Otherwise "ready": false and "missing": [list of missing fields].
No HTML. No markdown. Only valid JSON.
PROMPT;

        $userPrompt = ($historyText ? "Conversation:\n{$historyText}\n" : '')
            . "User's latest message: {$message}\n"
            . "Current extracted state: " . json_encode($state);

        try {
            $result = $this->runtime->chatJson($system, $userPrompt, ['task' => 'arthur_extract_all'], 1200);
            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null)) {
                $parsed = $result['parsed'];
                foreach ($parsed as $k => $v) {
                    // Never overwrite with null/empty. Arrays must be non-empty
                    // to win over an existing value.
                    if ($v === null || $v === '' || (is_array($v) && empty($v))) continue;
                    $state[$k] = $v;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] extractAllFields failed: ' . $e->getMessage());
            $state = $this->simpleExtract($message, $state, $history);
        }

        // Post-process the industry through the keyword map so misclassified
        // LLM output (e.g. "marketing" for a digital marketing agency) is
        // corrected before it reaches the template system.
        $state['industry'] = $this->normalizeIndustry($state['industry'] ?? null, $message);
        $state['ready'] = !empty($state['business_name']) && !empty($state['industry']);
        return $state;
    }

    private function extractFields(string $message, array $history, array $state): array
    {
        if (!$this->runtime->isConfigured()) {
            // Fallback: simple keyword extraction
            return $this->simpleExtract($message, $state, $history);
        }

        $historyText = '';
        foreach (array_slice($history, -8) as $h) {
            $role = ($h['role'] ?? 'user') === 'assistant' ? 'Arthur' : 'User';
            $historyText .= "{$role}: {$h['content']}\n";
        }

        $system = <<<PROMPT
You are Arthur, an AI website builder assistant.
Extract business information from the user's message.
Required fields: business_name, industry, location, services, goal.
Industry MUST be one of: restaurant, interior_design, fitness, healthcare, legal, real_estate, fashion, technology, events, beauty, marketing, general.
If the user gives enough info to build a website (at least business_name + industry), set "ready": true.
If info is missing, set "ready": false and list what's missing in "missing" array.
When ready, also generate: tagline, hero_title (short punchy headline, 3-6 words, plain text only), hero_subtitle, hero_cta (3-4 words), about_text_1, meta_description. No HTML tags in any field.
Return ONLY valid JSON. No markdown. Include the word "json" in your thinking.
PROMPT;

        $userPrompt = ($historyText ? "Conversation:\n{$historyText}\n" : '')
            . "User's latest message: {$message}\n"
            . "Current extracted state: " . json_encode($state);

        try {
            $result = $this->runtime->chatJson($system, $userPrompt, ['task' => 'arthur_extract'], 800);
            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null)) {
                // Merge with existing state (don't overwrite with nulls)
                $parsed = $result['parsed'];
                foreach ($parsed as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $state[$k] = $v;
                    }
                }
                return $state;
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] Extraction failed: ' . $e->getMessage());
        }

        return $this->simpleExtract($message, $state, $history);
    }

    private function simpleExtract(string $message, array $state, array $history = []): array
    {
        $lower = mb_strtolower($message);

        // Simple keyword matching fallback. BUG 2 FIX — digital marketing /
        // seo / web design / social media / advertising now all land on
        // "technology" so the template picker shows the right gallery.
        $industries = [
            'restaurant'      => ['restaurant','café','cafe','bistro','dining','chef','catering','food','kitchen','bakery','pizzeria'],
            'fitness'         => ['gym','fitness','yoga','pilates','crossfit','personal trainer','personal training','wellness'],
            'beauty'          => ['salon','spa','beauty','hair','nails','skincare','barbershop'],
            'real_estate'     => ['real estate','property','realtor','broker','apartments','housing'],
            'healthcare'      => ['clinic','doctor','dental','medical','hospital','pharmacy','health'],
            'legal'           => ['law firm','lawyer','attorney','legal','solicitor','consulting'],
            'technology'      => ['digital marketing','marketing agency','seo agency','seo','web design','web development','social media agency','social media','advertising agency','advertising','it company','it services','software','app development','app','saas','tech','digital'],
            'events'          => ['wedding','events','event production'],
            'interior_design' => ['interior','fit-out','joinery'],
            'fashion'         => ['fashion','clothing','boutique'],
        ];

        foreach ($industries as $industry => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($lower, $kw) !== false && empty($state['industry'])) {
                    $state['industry'] = $industry;
                    break 2;
                }
            }
        }

        // Extract location
        $locations = ['dubai', 'abu dhabi', 'sharjah', 'ajman', 'riyadh', 'jeddah', 'doha', 'muscat', 'bahrain', 'kuwait'];
        foreach ($locations as $loc) {
            if (strpos($lower, $loc) !== false && empty($state['location'])) {
                $state['location'] = ucwords($loc) . ', UAE';
                break;
            }
        }

        // Extract business name — look for quoted text or "called X" or "named X"
        if (empty($state['business_name'])) {
            if (preg_match('/(?:called|named|for)\s+"?([A-Z][A-Za-z\s&\']+)"?/i', $message, $m)) {
                $state['business_name'] = trim($m[1]);
            } elseif (preg_match('/"([^"]+)"/', $message, $m)) {
                $state['business_name'] = trim($m[1]);
            }
        }

        // Conversational fallback — when the runtime LLM is unavailable, plain
        // user replies like "MR Digital Media" never match the regex patterns
        // above and the wizard loops forever asking the same question. Look at
        // the most recent Arthur message in history to figure out which field
        // the user is answering and capture the message verbatim.
        $trimmed = trim($message);
        if ($trimmed !== '' && !empty($history)) {
            $lastArthur = '';
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $h = $history[$i];
                if (is_array($h) && ($h['role'] ?? '') === 'assistant') {
                    $lastArthur = mb_strtolower((string)($h['content'] ?? ''));
                    break;
                }
            }
            if ($lastArthur !== '') {
                if (empty($state['business_name']) && mb_strpos($lastArthur, "name of your business") !== false) {
                    $state['business_name'] = $trimmed;
                } elseif (empty($state['location']) && mb_strpos($lastArthur, 'based?') !== false) {
                    $state['location'] = $trimmed;
                } elseif (empty($state['services']) && (mb_strpos($lastArthur, 'services or products') !== false || mb_strpos($lastArthur, 'what services') !== false)) {
                    $parts = preg_split('/\s*,\s*|\s*;\s*|\s+and\s+/i', $trimmed);
                    $state['services'] = array_values(array_filter(array_map('trim', $parts)));
                }
            }
        }

        // Check readiness
        $state['ready'] = !empty($state['business_name']) && !empty($state['industry']);

        return $state;
    }

    private function getNextQuestion(array $missing, array $state): string
    {
        $name = $state['business_name'] ?? 'your business';

        if (in_array('business_name', $missing)) {
            return "I'd love to build your website! What's the name of your business?";
        }
        if (in_array('industry', $missing)) {
            return "Great name! What type of business is **{$name}**? (e.g., restaurant, fitness, beauty salon, etc.)";
        }
        if (empty($state['location'])) {
            return "Where is **{$name}** based? (city, country)";
        }
        if (empty($state['services'])) {
            return "What services or products does **{$name}** offer?";
        }

        return "Tell me more about **{$name}** — what makes it special?";
    }

    private function getProgress(array $state): array
    {
        return [
            ['field' => 'business_name', 'label' => 'Business Name', 'done' => !empty($state['business_name'])],
            ['field' => 'industry', 'label' => 'Industry', 'done' => !empty($state['industry'])],
            ['field' => 'location', 'label' => 'Location', 'done' => !empty($state['location'])],
            ['field' => 'services', 'label' => 'Services', 'done' => !empty($state['services'])],
        ];
    }

    /**
     * P1 (2026-06-24) — provision a NEW dedicated workspace for an additional
     * website (website = workspace architecture). Seeds: owner membership, the
     * inherited plan (subscription pointing at the billing workspace's plan),
     * the same agent team, and billing_workspace_id = the user's pool. NO own
     * credit row — credits resolve to the shared pool. Returns new workspace id.
     */
    public function provisionWebsiteWorkspace(int $sourceWsId, int $ownerUserId, int $billingWs, string $name): int
    {
        $base = \Illuminate\Support\Str::slug($name) ?: 'site';
        $slug = $base . '-' . substr(md5($name . microtime(true)), 0, 6);

        $newWsId = (int) DB::table('workspaces')->insertGetId([
            'name'                 => $name,
            'slug'                 => $slug,
            'created_by'           => $ownerUserId,
            'billing_workspace_id' => $billingWs,   // shares the user's credit pool
            'onboarded'            => 1,            // built site → skip onboarding
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // Owner membership so the user can switch to / access it.
        DB::table('workspace_users')->insert([
            'workspace_id' => $newWsId,
            'user_id'      => $ownerUserId,
            'role'         => 'owner',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Inherit the plan from the billing/primary workspace (seed subscription).
        $srcSub = DB::table('subscriptions')->where('workspace_id', $billingWs)
            ->whereIn('status', ['active', 'trialing'])->latest()->first();
        if ($srcSub) {
            DB::table('subscriptions')->insert([
                'workspace_id'         => $newWsId,
                'plan_id'              => $srcSub->plan_id,
                'status'               => $srcSub->status,
                'provider'             => 'inherited',
                'chatbot_addon_active' => $srcSub->chatbot_addon_active ?? 0,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        // Attach the same agent team (copy pivot rows → no NOT NULL surprises).
        foreach (DB::table('workspace_agents')->where('workspace_id', $sourceWsId)->get() as $row) {
            $r = (array) $row;
            unset($r['id']);
            $r['workspace_id'] = $newWsId;
            $r['created_at']   = now();
            $r['updated_at']   = now();
            DB::table('workspace_agents')->insert($r);
        }

        return $newWsId;
    }

    private function generateWebsite(int $wsId, array $data, ?int $actorId = null): array
    {
        // BUILDER888 fix: $actorId is the authenticated actor threaded from the caller
        // (buildFromChat). It was referenced below (created_by) but never declared here,
        // raising "Undefined variable $actorId" -> PERSISTENCE_FAILED on every build.
        // When absent, derive the workspace OWNER (never the payload user_id, which the
        // security note below forbids as a forgeable owner source).
        if ($actorId === null) {
            $ownerId = DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->value('user_id');
            $actorId = $ownerId ? (int) $ownerId : null;
        }
        $name = $data['business_name'] ?? 'My Business';

        // ── P1 (2026-06-24): WEBSITE = ITS OWN WORKSPACE ────────────────────
        // Each built website lives in its OWN workspace (isolated CRM/SEO/blog/
        // tasks/agent-memory; they can be different companies). Website #1 uses
        // the current workspace; #2+ each get a freshly-seeded workspace that
        // SHARES the user's credit pool + plan. Quota = plan max_websites
        // counted across ALL the user's workspaces (the pool family).
        $ownerUserId = (int) (DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->value('user_id')
            ?: DB::table('workspaces')->where('id', $wsId)->value('created_by') ?: 0);
        $billingWs = (int) (DB::table('workspaces')->where('id', $wsId)->value('billing_workspace_id') ?: $wsId);
        try {
            $sub = DB::table('subscriptions')->where('workspace_id', $billingWs)
                ->whereIn('status', ['active', 'trialing'])->latest()->first();
            $plan = $sub ? DB::table('plans')->where('id', $sub->plan_id)->first() : null;
            if (! $plan) $plan = DB::table('plans')->where('slug', 'free')->first();
            $maxWebsites = (int) ($plan->max_websites ?? 1);
            $userWsIds = DB::table('workspaces')->where('billing_workspace_id', $billingWs)->pluck('id')->all();
            if (empty($userWsIds)) $userWsIds = [$billingWs];
            $totalSites = (int) DB::table('websites')->whereIn('workspace_id', $userWsIds)->whereNull('deleted_at')->count();
            if ($totalSites >= $maxWebsites) {
                $planName = $plan->name ?? 'Free';
                return [
                    'type'          => 'error',
                    'message'       => "You've reached your plan limit of {$maxWebsites} website" . ($maxWebsites === 1 ? '' : 's') . " on the {$planName} plan. Upgrade your plan or delete a website to add more.",
                    'limit_reached' => true,
                    'current'       => $totalSites,
                    'max'           => $maxWebsites,
                    'plan'          => $planName,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] plan-limit check failed: ' . $e->getMessage(), ['workspace_id' => $wsId]);
        }

        // Target workspace: current if it has no website yet (#1 / single-site
        // users → no change); otherwise spin up a dedicated workspace for this site.
        try {
            $currentHasSite = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->exists();
            if ($currentHasSite && $ownerUserId > 0) {
                $newWs = $this->provisionWebsiteWorkspace($wsId, $ownerUserId, $billingWs, (string) $name);
                if ($newWs > 0) {
                    Log::info('[Arthur] provisioned dedicated workspace for new website', ['source_ws' => $wsId, 'new_ws' => $newWs, 'name' => $name]);
                    $wsId = $newWs; // build into the new workspace from here on
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] workspace provisioning failed; building in current workspace: ' . $e->getMessage());
        }

        $rawIndustry = (string) ($data['industry'] ?? '');

        // PATCH (template-resolution, 2026-05-09) — Resolve the free-form
        // industry string (from chat() build_data, which never normalises)
        // to an actual on-disk template slug. The old code did:
        //   $manifest = getManifest($industry); fallback restaurant;
        // which sent "Plastic Surgery" → null → restaurant. Now any
        // "plastic surgery" / "cosmetic clinic" / "med spa" maps to
        // aesthetic_clinic. Generic medical → medical_clinic. Etc.
        $industry = $this->resolveTemplateSlug($rawIndustry);

        // PATCH (name-grounding, 2026-07-24) — The live chat() path hands
        // industry classification to a free-form LLM that has misrouted on
        // sparse input (Architecture→interior_design, Clothing Store→
        // it_services, Pet Clinic→medical_clinic, Shelving→consulting). The
        // business NAME almost always states the industry outright, so run it
        // through the keyword matcher and let a CONFIDENT name hit override the
        // LLM's guess. confidentSlugFromText() returns null when the text has
        // no real signal (so a signal-free name never overrides a good guess).
        $nameSignal = $this->confidentSlugFromText((string) $name);
        if ($nameSignal !== null && $nameSignal !== $industry) {
            \Illuminate\Support\Facades\Log::info('[Arthur] name-grounded industry override', [
                'name'         => $name,
                'llm_industry' => $rawIndustry,
                'llm_slug'     => $industry,
                'name_slug'    => $nameSignal,
            ]);
            $industry = $nameSignal;
        }

        $manifest = $this->templates->getManifest($industry);

        // Defensive double-check — if resolveTemplateSlug ever returned a
        // slug that doesn't exist (shouldn't happen — all returns map to
        // disk templates), fall back to consulting (more generic than
        // restaurant for unknown businesses).
        if (!$manifest) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] resolveTemplateSlug returned non-existent template', [
                'raw'      => $rawIndustry,
                'resolved' => $industry,
            ]);
            $industry = 'consulting';
            $manifest = $this->templates->getManifest('consulting');
        }
        if (!$manifest) {
            return ['type' => 'error', 'message' => 'Could not build your website right now. Please contact support.'];
        }
        \Illuminate\Support\Facades\Log::info('[Arthur] template resolution', [
            'raw'      => $rawIndustry,
            'resolved' => $industry,
        ]);

        // PATCH (archetype routing, 2026-07-24 · P1) — the generic 'consulting'
        // fallback is an enterprise-advisory layout that fits almost nobody. If
        // we landed there but the business is clearly another archetype (tattoo
        // studio → appointment service), re-route to that archetype's best
        // template so the SECTIONS actually fit the business.
        if ($industry === 'consulting') {
            $bizArch = \App\Engines\Builder\Support\TemplateArchetypes::archetypeForBusiness($rawIndustry, (string) $name);
            if ($bizArch && $bizArch !== 'professional_advisory') {
                $reTpl = \App\Engines\Builder\Support\TemplateArchetypes::fallbackTemplate($bizArch);
                if ($reTpl && $reTpl !== 'consulting' && ($reManifest = $this->templates->getManifest($reTpl))) {
                    \Illuminate\Support\Facades\Log::info('[Arthur] archetype re-route', [
                        'from' => 'consulting', 'archetype' => $bizArch, 'to' => $reTpl, 'raw' => $rawIndustry,
                    ]);
                    $industry = $reTpl;
                    $manifest = $reManifest;
                }
            }
        }

        // Section governance (P2/P3) — blocks that don't belong to this template's
        // archetype (e.g. a "doctors" block on a restaurant — a clone artifact) or
        // that need real track-record data (stats/case_studies/clients) are
        // stripped below. The maturity gate keeps credibility blocks only when the
        // business is established WITH real data (P3); auto-builds stay conservative.
        $established  = \App\Engines\Builder\Support\TemplateArchetypes::looksEstablished($data);
        $removeBlocks = \App\Engines\Builder\Support\TemplateArchetypes::blocksToRemove($industry, $established);

        // Generate content via LLM
        $variables = $this->generateContent($data, $industry);

        // PATCH (hero-context, 2026-07-24) — signals reused by hero resolution
        // (below). The text-coverage pass and demo-brand sweep intentionally run
        // LATER (after the manifest-default injection) so their neutralizations
        // are not overwritten by that re-defaulting step.
        $rawIndustry  = trim((string) ($data['industry'] ?? ''));
        $servicesText = is_array($data['services'] ?? null)
            ? implode(', ', $data['services'])
            : (string) ($data['services'] ?? '');
        $templateFits = ($rawIndustry === '') || ($this->confidentSlugFromText($rawIndustry) === $industry);
        $copyIndustry = $templateFits ? $industry : $rawIndustry;

        // /* h2-arthur */ workspace brand colors via single resolver (was direct creative_brand_identities read)
        $kit = app(\App\Core\Brand\WorkspaceBrandKitResolver::class)->resolve($wsId);
        if (!$kit['is_neutral']) {
            $variables['primary_color']   = $kit['primary_color'];
            $variables['secondary_color'] = $kit['secondary_color'];
        }

        // Set defaults
        $variables['business_name'] = $name;
        // Logo text fallback — 14 of 31 templates use a separate {{logo}}/{{footer_logo}}
        // text variable that defaults to a template brand placeholder ("Momentum",
        // "Foliage", "APEX MOTORS", etc.). When the user hasn't uploaded a logo,
        // those defaults leak into nav/footer. Force them all to business_name so
        // the brand stays the user's. In-browser logo edits go through a different
        // path (PUT /fields/{field}) and are unaffected.
        foreach (['logo', 'nav_logo', 'footer_logo', 'header_logo', 'brand', 'brand_name'] as $logoKey) {
            $variables[$logoKey] = $name;
        }
        $variables['footer_text'] = '© ' . date('Y') . ' ' . $name . '. All rights reserved.';
        $variables['contact_address'] = $data['location'] ?? 'Dubai, UAE';
        $variables['city'] = $data['location'] ?? 'Dubai';
        $variables['country'] = 'AE';

        // BUG 2 FIX — guaranteed hero image floor from builder_default_assets.
        // This runs BEFORE any DALL-E attempt so that generation failures
        // never leave the site with an empty hero. DALL-E success below
        // simply overwrites this value.
        $heroDefaultUrl = null;
        try {
            $defaultRow = DB::table('builder_default_assets')
                ->where('asset_type', 'hero')
                // Borrowed template → the slug's floor hero is the wrong industry
                // (consulting=office); use the neutral 'default' floor instead.
                ->where('industry', $templateFits ? $industry : 'default')
                ->first();
            if (!$defaultRow) {
                $defaultRow = DB::table('builder_default_assets')
                    ->where('asset_type', 'hero')
                    ->where('industry', 'default')
                    ->first();
            }
            if ($defaultRow && !empty($defaultRow->url)) {
                $heroDefaultUrl = $defaultRow->url;
                $variables['hero_image'] = $heroDefaultUrl;
                $variables['og_image']   = $heroDefaultUrl;
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] builder_default_assets lookup failed: ' . $e->getMessage());
        }

        // Hero image — prefer EXISTING media, generate only if nothing applicable.
        // Matched template: the slug's platform hero fits (findOrGenerate reuses
        // it, one DALL-E call per industry ever). Borrowed template: the slug hero
        // is the WRONG industry (tattoo → consulting → office), so search media by
        // the ACTUAL business first and generate a business-context hero only as a
        // last resort — never reuse or poison the slug's shared platform hero.
        $existingHero = $templateFits
            ? \App\Services\MediaService::findOrGenerate($industry, 'hero', 'luxury', $wsId)
            : $this->findApplicableHero($rawIndustry, $servicesText, $wsId);
        if ($existingHero) {
            $variables['hero_image'] = $existingHero['url'];
            Log::info('[Arthur] Reused existing hero image: ' . ($existingHero['id'] ?? '?'));
        } else {
        // Generate hero image — context depends on whether the template fits.
        $heroPrompt = $templateFits
            ? $this->getHeroImagePrompt($industry, $data['location'] ?? 'Dubai')
            : $this->getBusinessHeroPrompt($rawIndustry, $servicesText, $data['location'] ?? 'Dubai');

        try {
            $imgResult = $this->runtime->imageGenerate(
                $heroPrompt,
                ['size' => '1792x1024', 'quality' => 'standard']
            );
            if ($imgResult['success'] ?? false) {
                // Download DALL-E image to local storage (URLs expire after 2h)
                    $heroLocalPath = '/storage/sites/heroes/' . uniqid('hero_') . '.png';
                    $heroFullPath = storage_path('app/public/sites/heroes');
                    if (!is_dir($heroFullPath)) mkdir($heroFullPath, 0755, true);
                    $imgData = @file_get_contents($imgResult['url']);
                    if ($imgData) {
                        file_put_contents($heroFullPath . '/' . basename($heroLocalPath), $imgData);
                        $variables['hero_image'] = $heroLocalPath;
                        // PATCH (image-rule, 2026-05-09; hero-context 2026-07-24) —
                        // Matched template: register as a PLATFORM asset keyed by
                        // the slug (workspace_id=null) so every future build of that
                        // industry reuses it — one DALL-E call per industry, ever.
                        // Borrowed template: register WORKSPACE-scoped and keyed by
                        // the real business industry, so it never poisons the slug's
                        // shared hero but is still reused within this workspace.
                        try {
                            \App\Services\MediaService::registerFull(
                                $heroLocalPath, $heroLocalPath, 'dalle', 'hero',
                                $templateFits ? $industry : ($rawIndustry !== '' ? $rawIndustry : $industry),
                                $templateFits ? null : $wsId,
                                $heroPrompt,
                                'dall-e-3', 'luxury'
                            );
                        } catch (\Throwable $e) {}
                    } else {
                        $variables['hero_image'] = $imgResult['url']; // fallback to URL
                    }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] Hero image generation failed: ' . $e->getMessage());
        }
        } // end else (no existing hero)

        // BUGFIX (hero-floor, 2026-07-24) — guarantee hero_image points to a file
        // that EXISTS. When generation times out (intermittent runtime 503) and no
        // applicable media is found, some manifests default to a missing file
        // (e.g. beauty_salon → /storage/builder-heroes/beauty.jpg, which doesn't
        // exist), rendering an empty hero. Fall back to the resolved template's
        // own builder-hero, then a guaranteed-present one.
        $heroCur = (string) ($variables['hero_image'] ?? '');
        $heroLocal = $heroCur !== '' && $heroCur[0] === '/'
            ? storage_path('app/public' . preg_replace('#^/storage#', '', $heroCur))
            : '';
        $heroOk = $heroCur !== '' && (str_starts_with($heroCur, 'http') || ($heroLocal !== '' && is_file($heroLocal)));
        if (!$heroOk) {
            foreach ([$industry, 'consulting', 'restaurant'] as $slugTry) {
                $cand = '/storage/builder-heroes/' . $slugTry . '.jpg';
                if (is_file(storage_path('app/public/builder-heroes/' . $slugTry . '.jpg'))) {
                    $variables['hero_image'] = $cand;
                    $variables['og_image']   = $variables['og_image'] ?? $cand;
                    Log::info('[Arthur] hero fell back to existing builder-hero', ['from' => $heroCur, 'to' => $cand]);
                    break;
                }
            }
        }

        // PATCH (image-rule, 2026-05-09) — Gallery generation REMOVED.
        // Old behaviour: 3 DALL-E calls per build × every site = ~$0.12/build
        // wasted on gallery shots that lived for one render.
        // New rule: pull from the platform media library (industry-tagged
        // template_image / hero category), fall back to the hero image
        // if the library has nothing for this industry. ZERO new DALL-E
        // calls for gallery slots, ever.
        $galleryImages = [];
        try {
            $galleryImages = DB::table('media')
                ->where('is_platform_asset', 1)
                ->where('asset_type', 'image')
                ->whereIn('category', ['template_image', 'hero', 'gallery'])
                ->whereRaw('JSON_CONTAINS(tags, ?)', [json_encode($industry)])
                ->whereNotNull('url')
                ->where('url', '!=', '')
                ->inRandomOrder()
                ->limit(11)
                ->pluck('url')
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('[Arthur] gallery library lookup failed: ' . $e->getMessage());
        }
        // Fill gallery slots — library photos first, then repeat the hero
        // for any leftover slot so no template variable is left empty.
        $heroFloor = $variables['hero_image'] ?? '';
        for ($gi = 1; $gi <= 11; $gi++) {
            $variables['gallery_image_' . $gi] = $galleryImages[$gi - 1]
                ?? $heroFloor;
        }


        // Hide gallery (no photos yet) and extra service slots
        $variables['gallery_display'] = 'display:none';
        $variables['feature_display'] = 'display:none';
        $variables['service_4_display'] = 'display:none';
        $variables['service_5_display'] = 'display:none';
        $variables['service_6_display'] = 'display:none';

        // BUG 1 FIX — respect explicitly-requested pages. When the user
        // lists pages ("home, about, services, testimonials, blog, contact"),
        // force the matching section's *_display var to '' (show) even if
        // it was previously defaulted to display:none (e.g. gallery). Only
        // touches manifest-declared variables so unknown templates stay
        // safe. "home" is implicit.
        if (!empty($data['pages']) && is_array($data['pages'])) {
            $requested = array_map(
                fn($p) => strtolower(trim((string)$p)),
                $data['pages']
            );
            $pageToDisplayVar = [
                'about'        => 'about_display',
                'services'     => 'services_display',
                'gallery'      => 'gallery_display',
                'testimonials' => 'testimonials_display',
                'blog'         => 'blog_display',
                'contact'      => 'contact_display',
                'team'         => 'team_display',
                'portfolio'    => 'portfolio_display',
                'process'      => 'process_display',
            ];
            $manifestVarsForPages = $manifest['variables'] ?? [];
            foreach ($pageToDisplayVar as $page => $displayVar) {
                if (!in_array($page, $requested, true)) continue;
                // Only set when the manifest declares the var OR we already
                // hold a value for it — avoids emitting hollow keys.
                if (array_key_exists($displayVar, $manifestVarsForPages)
                    || array_key_exists($displayVar, $variables)) {
                    $variables[$displayVar] = '';
                }
            }
        }

        // FIX 2 — distribute user uploads across ALL image variables in
        // manifest order. Hero_image → first upload (or DALL-E if DALL-E
        // produced a non-default URL). about/gallery/team/portfolio →
        // remaining uploads in manifest order. Any slot still empty falls
        // back to the industry hero default.
        $uploadedImages = [];
        if (isset($data['uploaded_images']) && is_array($data['uploaded_images'])) {
            foreach ($data['uploaded_images'] as $u) {
                if (is_string($u) && $u !== '') $uploadedImages[] = $u;
            }
        }
        $uploadedImages = array_slice($uploadedImages, 0, 10); // hard cap 10
        $uploadQueue    = $uploadedImages;

        // FIX 1 (2026-04-20) — logo_url must NEVER receive an auto-assigned
        // upload from general image distribution. The wizard shows a text
        // fallback until the user explicitly says "upload a logo" (state.logo_upload=true).
        $logoUploadOptIn = !empty($data['logo_upload']);
        if (!$logoUploadOptIn && !empty($uploadQueue)) {
            $variables['logo_url'] = ''; // force empty so text fallback renders
        }
        // T1 (2026-04-20) — if the wizard collected a temp logo, use it
        // directly on the in-memory variables. Permanent copy happens
        // AFTER the website row exists (we need the websiteId for the path).
        $logoTempPath = $data['logo_temp_path'] ?? null;
        $logoTempUrl  = $data['logo_url']       ?? null;
        if ($logoUploadOptIn && $logoTempUrl) {
            $variables['logo_url'] = $logoTempUrl; // transitional until we move it
        }

        try {
            $manifestForImgs = $this->templates->getManifest($industry) ?: [];
            foreach (($manifestForImgs['variables'] ?? []) as $varKey => $varSpec) {
                $type = is_array($varSpec) ? ($varSpec['type'] ?? 'text') : 'text';
                if ($type !== 'image') continue;

                // Skip logo_url from general distribution unless user opted in.
                if ($varKey === 'logo_url' && !$logoUploadOptIn) continue;

                // A slot already holds a non-default URL → DALL-E or caller
                // put something real there; keep it. The default floor URL
                // is treated as "still empty" so uploads can override it.
                $current = $variables[$varKey] ?? '';
                $isJustFloor = ($current === '' || $current === null || $current === $heroDefaultUrl);

                if ($isJustFloor && !empty($uploadQueue)) {
                    $variables[$varKey] = array_shift($uploadQueue);
                } elseif ($current === '' || $current === null) {
                    $variables[$varKey] = $heroDefaultUrl
                        ?? (is_array($varSpec) ? ($varSpec['default'] ?? '') : '');
                }
            }
            // Guarantee hero + og have something — floor to industry default.
            foreach (['hero_image','og_image'] as $rk) {
                if (empty($variables[$rk]) && $heroDefaultUrl) {
                    $variables[$rk] = $heroDefaultUrl;
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // PATCH (image-injection, 2026-05-09) — Media-library fallback.
        // After uploaded-images + manifest defaults are applied, any
        // image var still empty (or still on the industry hero floor)
        // gets filled from the workspace's media library, prioritised by
        // industry tag, then platform-asset template_image category,
        // then any platform asset. This means a Bico Plastic Surgery
        // site with 0 uploads still gets aesthetic_clinic-tagged photos
        // in the doctor_*_image, gallery_*_image slots — instead of the
        // hero leaking into every slot.
        try {
            $imagePool = $this->buildImagePool($industry, $wsId);
            $this->injectImagesToTemplate(
                $variables,
                $manifestForImgs ?? ($this->templates->getManifest($industry) ?: []),
                $imagePool,
                $heroDefaultUrl,
                $logoUploadOptIn
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] image pool fallback failed: ' . $e->getMessage());
        }

        // FIX 3 — after all image + content work, walk EVERY manifest var
        // and substitute the manifest default when the current value is
        // empty. Rule: generated/uploaded content > manifest default > empty.
        try {
            $mf = $this->templates->getManifest($industry) ?: [];
            foreach (($mf['variables'] ?? []) as $mKey => $mSpec) {
                $default = is_array($mSpec) ? ($mSpec['default'] ?? '') : '';
                $have    = $variables[$mKey] ?? null;
                if (($have === '' || $have === null) && $default !== '' && $default !== null) {
                    $variables[$mKey] = $default;
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // PATCH (no-static-text, 2026-07-24) — MUST run AFTER the FIX-3 manifest
        // re-defaulting above, otherwise every blank we set is overwritten by the
        // sample default again. Two passes on the FINAL variable set:
        //   (1) FULL TEXT COVERAGE — regenerate every content field still holding
        //       its manifest SAMPLE value from business context (text is cheap).
        //   (2) DEMO-BRAND SWEEP — neutralize any field still carrying the
        //       template's demo brand (canonical/og URLs, sample e-mails, or
        //       "Summit Advisors is a…" prose) so no static template text renders.
        $variables = $this->fillTemplateTextCoverage(
            $variables, is_array($manifest) ? $manifest : [], $data,
            $copyIndustry, $servicesText, $data['location'] ?? 'Dubai', $established
        );

        // PATCH (section-labels, 2026-07-24 · P2b) — the re-sectioned clone
        // templates carry a generic "services" block that IS the menu / catalog /
        // programs / destinations for their industry. Relabel its heading so it
        // reads correctly (a restaurant shows "Our Menu", retail "Shop", courses
        // "Our Courses"). Content is already industry-correct from generateContent.
        foreach (\App\Engines\Builder\Support\TemplateArchetypes::sectionLabels($industry) as $lk => $lv) {
            $variables[$lk] = $lv;
        }

        // P2b-full — build the bespoke industry section (real menu / catalog /
        // destinations) that will REPLACE the generic "services" block at render.
        $bespokeHtml = $this->bespokeSectionFor($industry, $data, $wsId);

        // PATCH (no-fabricated-credibility, 2026-07-24) — Templates ship invented
        // track-record props: stats ("22 Years in MENA", "AED 6B+ Value Created"),
        // client logos ("Al Noor Holding", "Zenith Capital"), and case studies
        // ("AED 380M Capital Released"). A brand-new business has done NONE of
        // this, and regenerating would only invent DIFFERENT fake numbers — a
        // false claim. Blank every credibility value by variable name; the
        // matching sections are then hidden at render (see scrubSampleStaff).
        // Users add real stats/clients later in the builder. (P3) Only blank when
        // NOT established — an established business keeps its credibility blocks
        // and the real values supplied via build_data / the builder UI.
        $credPattern = '/^(stat_\d|hero_stat_\d|strip_stat_\d|stats_strip_\d|client_logo_?\d|clients_title|clients_eyebrow|case_\d)/i';
        $isCred = fn($mk) => preg_match($credPattern, (string) $mk) || preg_match('/_metric_\d+_(value|label)$/i', (string) $mk);
        // (P3) When ESTABLISHED, inject the business's REAL credibility data
        // (build_data stats/clients/case_studies) into the kept blocks; blank any
        // credibility field NOT supplied so no template SAMPLE ("Summit Advisors",
        // "Al Noor Holding") can survive. When NOT established, blank all.
        $injected = $established
            ? $this->injectCredibilityData($variables, $data, $manifest['variables'] ?? [])
            : [];
        foreach (($manifest['variables'] ?? []) as $mk => $_mspec) {
            if (isset($injected[$mk])) continue;
            if ($isCred($mk)) {
                $variables[$mk] = '';
            }
        }

        // NOTE: lowercase BEFORE stripping — '/[^a-z0-9]/' also removes A-Z, so
        // "Summit Advisors" would wrongly become "ummitdvisors" and never match.
        $domainSlug = preg_replace('/[^a-z0-9]/', '', strtolower((string) $name)) ?: 'business';
        $sampleName = trim((string) ($manifest['variables']['business_name']['default'] ?? ''));
        $sampleSlug = preg_replace('/[^a-z0-9]/', '', strtolower($sampleName));

        // Unconditional SEO/e-mail neutralization — canonical/og URLs carry the
        // template's demo domain (e.g. coralresort.ae) whose slug need not match
        // the demo business_name, so brand-matching alone misses them. A fresh
        // draft has no canonical anyway -> blank; rewrite any e-mail to the
        // business's own domain slug.
        foreach (($manifest['variables'] ?? []) as $mk => $mspec) {
            $cur = $variables[$mk] ?? (is_array($mspec) ? ($mspec['default'] ?? null) : null);
            if (!is_string($cur) || $cur === '') continue;
            if (preg_match('/canonical|og_?url/i', (string) $mk)) {
                $variables[$mk] = '';
            } elseif ((preg_match('/email/i', (string) $mk) || strpos($cur, '@') !== false)
                      && preg_match('/@[a-z0-9.-]+\.[a-z]{2,}/i', $cur)) {
                $variables[$mk] = preg_replace('/@[^\s"\'<>]+/', '@' . $domainSlug . '.com', $cur);
            }
        }

        if (strlen($sampleSlug) >= 4 && is_array($manifest['variables'] ?? null)) {
            foreach ($manifest['variables'] as $mk => $mspec) {
                if (!is_array($mspec)) continue;
                $cur = $variables[$mk] ?? ($mspec['default'] ?? null);
                if (!is_string($cur) || $cur === '') continue;
                $hasName = $sampleName !== '' && stripos($cur, $sampleName) !== false;
                $hasSlug = strpos(preg_replace('/[^a-z0-9]/', '', strtolower($cur)), $sampleSlug) !== false;
                if (!$hasName && !$hasSlug) continue;
                $type = strtolower((string) ($mspec['type'] ?? ''));
                if (strpos($cur, '@') !== false || preg_match('/email/i', (string) $mk)) {
                    $variables[$mk] = preg_replace('/@[^\s"\'<>]+/', '@' . $domainSlug . '.com', $cur);
                } elseif ($type === 'url' || stripos($cur, 'http') === 0 || preg_match('/url|canonical|href|link/i', (string) $mk)) {
                    $variables[$mk] = '';
                } elseif ($hasName) {
                    $variables[$mk] = str_ireplace($sampleName, (string) $name, $cur);
                } else {
                    $variables[$mk] = '';
                }
            }
        }

        // T2 (2026-04-20) — if wizard collected a palette (from logo color
        // extraction), promote it into $data['colors'] so applyBrandColors
        // uses it. Also persist bg/text so templates with those vars pick up.
        if (!empty($data['palette']) && is_array($data['palette'])) {
            $pal = $data['palette'];
            $data['colors'] = array_merge(
                (array)($data['colors'] ?? []),
                [
                    'primary'   => $pal['primary']   ?? null,
                    'secondary' => $pal['secondary'] ?? null,
                    'accent'    => $pal['accent']    ?? null,
                ]
            );
            if (!empty($pal['bg']))   $variables['bg_color']   = $pal['bg'];
            if (!empty($pal['text'])) $variables['text_color'] = $pal['text'];
        }

        // FIX 1 — brand colors override (CSS custom properties fed by {{var}})
        try {
            $mf2 = $mf ?? ($this->templates->getManifest($industry) ?: []);
            $this->applyBrandColors($variables, $mf2, $data['colors'] ?? []);
        } catch (\Throwable $e) { /* non-fatal */ }

        // FIX 4 — cross-industry leak guard on generated service titles.
        // If any service_N_title smells like the wrong industry (e.g. "Fine
        // Dining" on a fitness site), fall back to the manifest default for
        // that specific field.
        try {
            $mf3 = $mf ?? ($this->templates->getManifest($industry) ?: []);
            $leakFields = ['service_1_title','service_1_text','service_2_title','service_2_text',
                           'service_3_title','service_3_text','service_4_title','service_5_title',
                           'service_6_title','program_1_title','program_2_title','program_3_title',
                           'cuisine_1_name','cuisine_2_name','cuisine_3_name',
                           'blog_section_title','blog_1_title','blog_2_title','blog_3_title'];
            foreach ($leakFields as $lf) {
                if (empty($variables[$lf])) continue;
                if ($this->isCrossIndustryLeak((string)$variables[$lf], $industry)) {
                    $spec = $mf3['variables'][$lf] ?? null;
                    $variables[$lf] = is_array($spec) ? ($spec['default'] ?? '') : '';
                    Log::info("[Arthur] Cross-industry leak fallback on {$lf} for {$industry}");
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // NEVER A FAKE PERSON (B3, generalises EV-0757). Some manifests ship SAMPLE
        // personnel names as the variable default (e.g. consulting member_1_name
        // "Tariq Al-Sayed"); FIX-3 re-defaulting applies them and they slip past the
        // coverage-pass blanking. If a personnel _N_name still equals its manifest
        // sample default, the customer supplied no real team -> blank it, and phantom-
        // card + empty-section stripping removes the fabricated person. A real name or
        // LLM value never equals the sample default, so this is safe.
        try {
            $mfPeople = $mf ?? ($this->templates->getManifest($industry) ?: []);
            $personRx = '/^(doctor|dentist|physician|surgeon|therapist|trainer|'
                . 'instructor|coach|staff|team|member|attorney|lawyer|agent|broker|'
                . 'realtor|stylist|barber|nurse|faculty|advisor|consultant|specialist|'
                . 'expert|leader|principal|partner|founder)_\\d+_name$/i';
            foreach (($mfPeople['variables'] ?? []) as $mk => $mspec) {
                if (! preg_match($personRx, (string) $mk)) {
                    continue;
                }
                $def = is_array($mspec) ? trim((string) ($mspec['default'] ?? '')) : '';
                $cur = trim((string) ($variables[$mk] ?? ''));
                if ($cur !== '' && $cur === $def) {
                    $variables[$mk] = '';
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // Render template — TemplateService also carries industry-scoped
        // image defaults so even variables unknown to the manifest won't
        // render as hollow sections.
        try {
            $html = \App\Engines\Builder\Support\TemplateArchetypes::removeBlocks(
                $this->scrubSampleStaff($this->templates->render($industry, $variables)),
                $removeBlocks
            );
            $html = \App\Engines\Builder\Support\SectionLibrary::replaceBlock($html, 'services', $bespokeHtml);
            // removeBlocks can delete a whole section (e.g. certifications) AFTER
            // TemplateService::render already ran its in-render anchor cleanup, orphaning
            // that section's nav links. Re-run the dangling-anchor pass on the FINAL html.
            $html = $this->templates->stripDanglingNavAnchors($html);
        } catch (\Throwable $e) {
            // BUILDER888 P1-8B (2026-08-10) — this line showed a customer a raw
            // PHP TypeError during the frozen Journey A. The internal cause is
            // logged in full with a correlation id; the customer gets safe wording.
            $failure = \App\Engines\Builder\Support\BuilderErrorContract::fromThrowable(
                $e,
                \App\Engines\Builder\Support\BuilderErrorContract::TEMPLATE_RENDER_FAILED,
                ['workspace_id' => $wsId ?? null, 'industry' => $industry ?? null, 'stage' => 'template_render']
            );

            return [
                'type'           => 'error',
                'message'        => $failure['build_error'],
                'error_category' => $failure['error_category'],
                'correlation_id' => $failure['correlation_id'],
                'retryable'      => $failure['retryable'],
            ];
        }

        // Create website record
        // ── BUILDER888 P1-6 · Law 11 ────────────────────────────────────────
        // Arthur no longer writes Builder tables. It compiles ONE generation
        // document and hands it to the Builder domain, which persists the
        // website and every page inside a single transaction.
        //
        // Page specs are assembled here (they depend only on $data, never on
        // $websiteId) so the whole document exists before anything is written.
        // Ordering, dedupe and positions are preserved exactly as before.
        $isNewsChannel = ($industry === 'news_channel');
        $homePos       = 0;
        $pageSpecs     = [];

        $pageSpecs[] = [
            'title'       => 'Home',
            'slug'        => 'home',
            'type'        => 'page',
            'status'      => 'published',
            'position'    => $homePos++,
            'is_homepage' => true,
            'sections'    => $this->buildDefaultSectionsForPage('home', $data),
        ];

        // User-requested pages (wizard state.pages[]), excluding Home + Blog.
        $extraPages = $data['pages'] ?? [];
        $seen = ['home' => true, 'blog' => true];
        if (is_array($extraPages)) {
            foreach ($extraPages as $p) {
                $title = trim((string) (is_array($p) ? ($p['title'] ?? $p['name'] ?? '') : $p));
                if ($title === '') continue;
                $slug = \Illuminate\Support\Str::slug($title);
                if ($slug === '' || isset($seen[$slug])) continue;
                $seen[$slug] = true;
                $pageSpecs[] = [
                    'title'       => ucfirst($title),
                    'slug'        => $slug,
                    'type'        => 'page',
                    'status'      => 'published',
                    'position'    => $homePos++,
                    'is_homepage' => false,
                    'sections'    => $this->buildDefaultSectionsForPage($slug, $data),
                ];
            }
        }

        // news_channel sites label this page "News" (slug=news); same type and
        // sections, only the user-visible label differs.
        $pageSpecs[] = [
            'title'       => $isNewsChannel ? 'News' : 'Blog',
            'slug'        => $isNewsChannel ? 'news' : 'blog',
            'type'        => 'blog',
            'status'      => 'published',
            'position'    => $homePos++,
            'is_homepage' => false,
            'sections'    => $this->buildDefaultSectionsForPage('blog', $data),
        ];

        try {
            $generation = \App\Engines\Builder\Support\BuilderGenerationDTO::fromArray([
                'workspace_id'       => $wsId,
                // BUILDER888 P1-6 — the actor is threaded explicitly from the
                // authenticated request. Deliberately NOT $data['user_id']: that
                // arrives from chat/provider payload and must never be able to
                // forge the owner of a website.
                'created_by'         => $actorId,
                'name'               => $name,
                // Representation convergence is deferred (audit R5): generated
                // sites stay on the template model until the semantic tree can
                // carry their fidelity.
                'type'               => 'template',
                'template_industry'  => $industry,
                'template_variables' => $variables,
                'settings'           => [
                    'industry'     => $industry,
                    'template'     => $industry,
                    'generated_by' => 'arthur',
                ],
                'pages'              => $pageSpecs,
                'generation_meta'    => ['source' => 'arthur_wizard'],
            ]);

            $persisted = app(\App\Engines\Builder\Services\BuilderApplicationService::class)
                ->generateWebsite($generation);

            $websiteId = (int) $persisted['website_id'];
        } catch (\App\Engines\Builder\Exceptions\BuilderRefusedException $e) {
            // BUILDER888 P1-6-a — a plan/entitlement refusal. Not a fault: the
            // customer gets the platform's own actionable wording, unchanged.
            $refusal = \App\Engines\Builder\Support\BuilderErrorContract::failureWithMessage(
                \App\Engines\Builder\Support\BuilderErrorContract::LIMIT_REACHED,
                $e->getMessage()
            );

            \Illuminate\Support\Facades\Log::info('[Builder888] generation refused', [
                'workspace_id'   => $wsId,
                'correlation_id' => $refusal['correlation_id'],
                'limit_reached'  => $e->limitReached,
            ]);

            return [
                'type'           => 'error',
                'message'        => $refusal['build_error'],
                'error_category' => $refusal['error_category'],
                'correlation_id' => $refusal['correlation_id'],
                'retryable'      => false,
            ];
        } catch (\Throwable $e) {
            // Persistence failed atomically — no website, no pages. The AI work
            // is already paid for and is NOT repeated; the caller is told the
            // truth via the canonical error contract.
            $failure = \App\Engines\Builder\Support\BuilderErrorContract::fromThrowable(
                $e,
                \App\Engines\Builder\Support\BuilderErrorContract::PERSISTENCE_FAILED,
                ['workspace_id' => $wsId, 'industry' => $industry, 'stage' => 'generation_persistence']
            );

            return [
                'type'           => 'error',
                'message'        => $failure['build_error'],
                'error_category' => $failure['error_category'],
                'correlation_id' => $failure['correlation_id'],
                'retryable'      => $failure['retryable'],
            ];
        }

        // ── post-persistence side effects (audit R9) ────────────────────────
        // All BEST-EFFORT. The website and its pages are already committed, so
        // none of these may fail the generation. Each is guarded individually
        // and records its own failure; caveats are collected for the response.
        $sideEffectWarnings = [];

        // PATCH (Option C, 2026-05-09) — for news_channel sites, seed
        // 6 LLM-generated news articles into the articles table so the
        // public news API has live content from day 1, AND inject the
        // first 6 into the static template variables for SEO + no
        // empty-state on first paint.
        if ($industry === 'news_channel') {
            try {
                $seeded = $this->seedNewsArticles($wsId, $name, $data['location'] ?? 'Dubai', $variables);
                if ($seeded > 0) {
                    // BUILDER888 P1-6 · Law 11 — the Builder domain owns this write.
                    app(\App\Engines\Builder\Services\BuilderService::class)
                        ->updateTemplateVariables($websiteId, $variables);
                    Log::info('[Arthur] news_channel seeded ' . $seeded . ' articles for ws=' . $wsId);
                }
            } catch (\Throwable $e) {
                Log::warning('[Arthur] news_channel article seeding failed: ' . $e->getMessage());
            }
        }

        // T1 (2026-04-20) — move temp logo into permanent site storage.
        // Done BEFORE deploy so the rendered HTML points at the final URL.
        if ($logoUploadOptIn && $logoTempPath && is_file($logoTempPath)) {
            try {
                $ext = pathinfo($logoTempPath, PATHINFO_EXTENSION) ?: 'png';
                $siteDir = storage_path("app/public/sites/{$websiteId}");
                if (!is_dir($siteDir)) @mkdir($siteDir, 0775, true);
                $destName = 'logo.' . strtolower($ext);
                $destPath = $siteDir . '/' . $destName;
                if (@copy($logoTempPath, $destPath)) {
                    @chmod($destPath, 0644);
                    @unlink($logoTempPath);
                    $permUrl = '/storage/sites/' . $websiteId . '/' . $destName . '?v=' . time();
                    $variables['logo_url'] = $permUrl;
                    // BUILDER888 P1-6 · Law 11 — the Builder domain owns this write.
                    app(\App\Engines\Builder\Services\BuilderService::class)
                        ->updateTemplateVariables($websiteId, $variables);
                    // Re-render with the final URL (transitional render above used temp URL).
                    try {
                        $html = \App\Engines\Builder\Support\TemplateArchetypes::removeBlocks(
                            $this->scrubSampleStaff($this->templates->render($industry, $variables)),
                            $removeBlocks
                        );
                        $html = \App\Engines\Builder\Support\SectionLibrary::replaceBlock($html, 'services', $bespokeHtml);
            // removeBlocks can delete a whole section (e.g. certifications) AFTER
            // TemplateService::render already ran its in-render anchor cleanup, orphaning
            // that section's nav links. Re-run the dangling-anchor pass on the FINAL html.
            $html = $this->templates->stripDanglingNavAnchors($html);
                    } catch (\Throwable $_e) { /* keep previous html */ }
                }
            } catch (\Throwable $e) {
                Log::warning('[Arthur] logo copy failed: ' . $e->getMessage());
            }
        }

        // Deploy HTML
        // LEGACY: T3.4 — static-HTML-only path retained for backwards
        // compatibility (Chef Red-style sites). New sites also get a
        // canonical sections_json below so BuilderRenderer can serve them.
        // Remove this deploy() call once Chef Red is migrated and arthur-edit
        // closure is rewritten on top of sections_json (Patch 8.5+).
        // BUILDER888 P1-6 (R9) — was unguarded. The site and its pages are now
        // already committed, so an unhandled throw here would report total
        // failure for a website that exists and works. The static export feeds
        // the Admin draft link only; public serving does not use it.
        try {
            $this->templates->deploy($websiteId, $html);
        } catch (\Throwable $e) {
            $sideEffectWarnings[] = 'static_export';
            Log::warning('[Builder888] post-persistence side effect failed', [
                'effect' => 'template_deploy', 'website_id' => $websiteId,
                'workspace_id' => $wsId, 'error' => $e->getMessage(),
            ]);
        }

        // FIX 2 (2026-04-20) — always create default pages: Home (homepage)
        // + Blog for every generated website. Any other pages the wizard
        // extracted via state.pages[] also get created. Failures are
        // non-fatal; logged but don't block the wizard response.
        //
        // PATCH 8 (2026-05-08) — every page row now ALSO carries a
        // canonical sections_json built from the wizard data so
        // BuilderRenderer can serve the site without falling back to the
        // static index.html. New sites are pure-canonical from now on.
        // BUILDER888 P1-6 — the three page inserts that lived here are gone.
        // Pages are now created inside the same transaction as the website by
        // BuilderApplicationService, from the page specs assembled above.
        // Law 11: Arthur performs no Builder-owned persistence.

        // PATCH (FIX 2, 2026-05-09) — Auto-enable the chatbot widget on
        // every build. chatbot_settings is workspace-UNIQUE (one row per
        // workspace), so we updateOrInsert keyed on workspace_id and
        // FORCE enabled=1 (per owner directive — auto-enable should
        // override any prior user toggle).
        // Once the row is enabled, PublishedSiteMiddleware injects
        // <script src="…/chatbot.js?ws=N" async></script> before </body>
        // on every served page automatically.
        try {
            DB::table('chatbot_settings')->updateOrInsert(
                ['workspace_id' => $wsId],
                [
                    'enabled'               => 1,
                    // PATCH (per-website greeting, 2026-05-09) — {{business}}
                    // is substituted at config-fetch time with the actual
                    // website name (resolved from Origin header), so each
                    // tenant subdomain greets as its own brand.
                    'greeting'              => 'Hi! Welcome to {{business}}. How can I help you today?',
                    'business_context_text' => $data['description'] ?? null,
                    'updated_at'            => now(),
                    'created_at'            => now(),
                ]
            );
            Log::info('[Arthur] chatbot auto-enabled for ws=' . $wsId);
        } catch (\Throwable $e) {
            Log::warning('[Arthur] chatbot auto-enable failed: ' . $e->getMessage(), ['workspace_id' => $wsId]);
        }

        // PATCH (FIX 4, 2026-05-09) — Inject the chatbot bootstrap script
        // directly into the static index.html on disk. The
        // PublishedSiteMiddleware already injects on subdomain serve, but
        // direct /storage/sites/{id}/index.html access (admin previews,
        // raw URL sharing, etc.) wouldn't see the script otherwise. Idempotent.
        try {
            $staticHtmlPath = storage_path('app/public/sites/' . $websiteId . '/index.html');
            if (is_file($staticHtmlPath)) {
                $html = file_get_contents($staticHtmlPath);
                if ($html && strpos($html, 'chatbot.js?ws=') === false) {
                    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
                    $origin = (str_contains($appHost, 'levelupgrowth.io'))
                        ? 'https://' . ($appHost ?: 'staging.levelupgrowth.io')
                        : 'https://staging.levelupgrowth.io';
                    $tag = '<script src="' . $origin . '/chatbot.js?ws=' . $wsId . '" async></script>';
                    $pos = strripos($html, '</body>');
                    if ($pos !== false) {
                        $html = substr($html, 0, $pos) . $tag . substr($html, $pos);
                    } else {
                        $html .= "\n" . $tag;
                    }
                    file_put_contents($staticHtmlPath, $html);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] static chatbot script injection failed: ' . $e->getMessage());
        }

        // Log intelligence
        try {
            app(\App\Core\Intelligence\EngineIntelligenceService::class)
                ->recordToolUsage('builder', 'wizard_generate', 0.9);
        } catch (\Throwable $e) {}

        return [
            'type' => 'complete',
            'message' => "✅ Your website for **{$name}** is ready! I built it from scratch with a premium " . str_replace('_', ' ', $industry) . " design and generated all the copy for you. You can preview it, edit the text, or publish it right away.",
            'website_id' => $websiteId,
            'workspace_id' => $wsId, // P1 — the (possibly new) workspace this site lives in; FE switches to it
            'name' => $name,
            'industry' => $industry,
        ];
    }

        private function generateContent(array $data, string $industry): array
    {
        $name = $data['business_name'] ?? 'Our Business';
        $location = $data['location'] ?? 'Dubai';
        // BUG 2 FIX — services may arrive as an array from extractAllFields();
        // join into a comma-separated list for the LLM copy prompt.
        $servicesRaw = $data['services'] ?? 'various services';
        $services = is_array($servicesRaw) ? implode(', ', $servicesRaw) : (string)$servicesRaw;
        $emailSlug = strtolower(preg_replace('/[^a-z0-9]/', '', $name));

        // PATCH (FIX 1, 2026-05-09) — Restaurant-flavored hardcoded defaults
        // were leaking through to non-food sites (Bico Plastic Surgery shipped
        // with "From Our Kitchen", "Signature Dishes", "International Cuisine"
        // because the manifest didn't declare those slots so the restaurant
        // seed survived). New defaults are NEUTRAL: industry-agnostic
        // placeholders the LLM is expected to overwrite. Manifest defaults
        // (loaded next) and the LLM call below win over these.
        $industryHuman = ucfirst(str_replace('_', ' ', $industry));
        $defaults = [
            'business_name'      => $name,
            'business_tagline'   => "{$industryHuman} · {$location}",
            'hero_title'         => $name,
            'hero_subtitle'      => $data['description'] ?? "Trusted {$industryHuman} in {$location}.",
            'hero_cta'           => 'Get Started',
            'hero_cta_secondary' => 'Learn More',
            'hero_eyebrow'       => "{$industryHuman} · {$location}",
            'about_eyebrow'      => 'About Us',
            'about_title'        => 'About Us',
            'about_text_1'       => "At {$name}, we are committed to delivering exceptional quality to our clients in {$location}.",
            'about_text_2'       => 'With years of experience, we bring expertise and dedication to every engagement.',
            'about_text_3'       => "Our commitment to excellence sets us apart.",
            'about_signature'    => $name,
            'about_image'        => '',
            'about_image_display'=> 'display:none',
            'services_eyebrow'   => 'What We Do',
            'services_title'     => 'Our Services',
            'services_intro'     => "Comprehensive {$industryHuman} services tailored to your needs.",
            'service_1_title'    => '',
            'service_1_text'     => '',
            'service_2_title'    => '',
            'service_2_text'     => '',
            'service_3_title'    => '',
            'service_3_text'     => '',
            'service_4_display'  => 'display:none',
            'service_5_display'  => 'display:none',
            'service_6_display'  => 'display:none',
            'gallery_display'    => 'display:none',
            'gallery_eyebrow'    => 'Our Work',
            'gallery_title'      => 'Gallery',
            'stats_display'      => '',
            'stat_1_value'       => '',
            'stat_1_label'       => '',
            'stat_2_value'       => '',
            'stat_2_label'       => '',
            'stat_3_value'       => '',
            'stat_3_label'       => '',
            'stat_4_value'       => '',
            'stat_4_label'       => '',
            // cuisine_* removed — only food templates declare them in their
            // manifest, where they get the right defaults. Non-food sites
            // never need them.
            'testimonial_1_quote'  => '',
            'testimonial_1_author' => '',
            'testimonial_2_quote'  => '',
            'testimonial_2_author' => '',
            'testimonial_3_quote'  => '',
            'testimonial_3_author' => '',
            'process_eyebrow'    => 'How It Works',
            'process_title'      => 'Our Process',
            'process_1_title'    => 'Discovery',
            'process_1_text'     => 'We learn about your needs and goals.',
            'process_2_title'    => 'Plan',
            'process_2_text'     => 'We design a tailored solution for you.',
            'process_3_title'    => 'Delivery',
            'process_3_text'     => 'We execute with precision and care.',
            'process_4_title'    => 'Follow-Up',
            'process_4_text'     => 'We ensure your complete satisfaction.',
            'contact_eyebrow'    => 'Get In Touch',
            'contact_title'      => 'Contact Us',
            'contact_form_title' => "Let's Start a Conversation",
            'contact_email'      => 'info@' . $emailSlug . '.com',
            'contact_website'    => strtolower(str_replace([' ', "'"], ['', ''], $name)) . '.com',
            'contact_service_area' => $location,
            'contact_availability_text' => "Currently accepting new clients in {$location}.",
            // Neutral blog title — LLM overwrites with industry-appropriate
            // (e.g. 'Health Tips' for medical, 'Training Tips' for gym).
            'blog_section_title' => 'Our Blog',
            'blog_1_title'       => '',
            'blog_1_excerpt'     => '',
            'blog_1_category'    => '',
            'blog_2_title'       => '',
            'blog_2_excerpt'     => '',
            'blog_2_category'    => '',
            'blog_3_title'       => '',
            'blog_3_excerpt'     => '',
            'blog_3_category'    => '',
            'meta_description'   => "{$name} — {$industryHuman} in {$location}.",
            'footer_text'        => '© ' . date('Y') . ' ' . $name . '. All rights reserved.',
        ];

        // FIX 3 — seed defaults from the industry's manifest, not the
        // hardcoded restaurant dictionary. Manifest defaults are already
        // tailored to each of the 10 industries we ship. The old $defaults
        // dictionary is kept above as a final-last-resort floor for old
        // restaurant field names.
        try {
            $manifestDefaults = [];
            $manifest = $this->templates->getManifest($industry) ?: [];
            foreach (($manifest['variables'] ?? []) as $mk => $mv) {
                $d = is_array($mv) ? ($mv['default'] ?? '') : '';
                if ($d !== '' && $d !== null) $manifestDefaults[$mk] = $d;
            }
            // Manifest defaults WIN over restaurant-themed hardcoded $defaults.
            $defaults = array_merge($defaults, $manifestDefaults);
            // Business-specific overrides (computed per-call) win over all.
            $defaults['business_name'] = $name;
            $defaults['business_tagline'] = ucfirst(str_replace('_',' ', $industry)) . ' · ' . $location;
            $defaults['meta_description'] = "{$name} — {$industry} in {$location}.";
            $defaults['contact_email']    = 'info@' . $emailSlug . '.com';
        } catch (\Throwable $e) { /* non-fatal, keep hardcoded $defaults */ }

        if (!$this->runtime->isConfigured()) {
            return $defaults;
        }

        // PATCH (FIX 2, 2026-05-09) — Industry hints expanded from 10 → 30
        // entries. Keys MUST match actual on-disk template slugs — the
        // resolved industry (post resolveTemplateSlug) is what the lookup
        // uses, NOT abstract names like 'healthcare' or 'fitness'.
        $industryHints = [
            // Food & hospitality
            'restaurant'         => 'Write for an upscale restaurant. Services = dining experiences (tasting menu, private events, catering). CTAs: Reserve, Book a Table. NEVER mention clinics, gyms, SaaS, or law firms.',
            'cafe'               => 'Write for a specialty coffee shop / bakery. Cozy, artisan, community feel. Services = roasted coffee, pastries, brunch, light meals. CTAs: Visit Us, Order Now.',
            'catering'           => 'Write for a premium catering company. Services = event catering categories (weddings, corporate, private dinners). CTAs: Get a Quote, Plan Your Event.',
            'hotel'              => 'Write for a boutique luxury hotel. Services = room types, dining, spa, events. CTAs: Book Now, Check Availability.',
            'resort'             => 'Write for a luxury resort. Services = experiences (suites, spa, dining, activities). CTAs: Book Your Stay, Explore.',
            'short_term_rental'  => 'Write for a short-term vacation rental. Services = property types (studios, apartments, villas). CTAs: Book Now, View Availability.',
            'travel_agency'      => 'Write for a travel agency. Services = trip categories (luxury, family, honeymoons, corporate). CTAs: Plan Your Trip, Get a Quote.',
            // Medical / health
            'dental'             => 'Write for a dental practice. Services = treatments (cleanings, whitening, implants, orthodontics, cosmetic). CTAs: Book Appointment, Call Us. NEVER mention dining, gym, or food.',
            'medical_clinic'     => 'Write for a multi-specialty medical clinic. Services = consultations, diagnostics, primary care, specialist referrals. CTAs: Book Consultation, Call Now. NEVER mention dining or food.',
            'aesthetic_clinic'   => 'Write for an aesthetic / cosmetic / plastic-surgery clinic. Services = procedures (rhinoplasty, liposuction, fillers, botox, laser). Premium, doctor-led, results-focused. CTAs: Book Consultation, Learn More. NEVER mention dining, food, kitchen, cuisine, or menus.',
            // Fitness / beauty
            'gym'                => 'Write for a fitness gym / training studio. Services = training programs (HIIT, strength, conditioning, yoga, pilates, personal training). CTAs: Start Training, Join Now. NEVER mention dining or food.',
            'beauty_salon'       => 'Write for a beauty salon. Services = treatments (hair, facials, manicures, makeup, waxing). CTAs: Book Appointment, See Services. NEVER mention dining or food.',
            'barbershop'         => 'Write for a traditional barbershop. Services = mens grooming (cuts, fades, hot-towel shaves, beard, kids cuts). CTAs: Book a Chair, Walk In.',
            // Pet & childcare
            'pet_services'       => 'Write for a pet care business (vet, grooming, daycare). Services = pet care categories. CTAs: Book Now, Meet the Team.',
            'childcare'          => 'Write for a childcare / nursery / preschool. Services = age groups, programs, activities. CTAs: Enroll Now, Schedule a Visit.',
            // Professional services
            'consulting'         => 'Write for a professional consulting firm. Services = practice areas (strategy, operations, finance, advisory). CTAs: Book a Call, Get Started. NEVER mention dining, food, or fitness.',
            'marketing_agency'   => 'Write for a digital marketing agency. Services = (SEO, paid ads, social media, content, web design). CTAs: Get a Free Audit, Let’s Talk. NEVER mention dining, food, or fitness.',
            'it_services'        => 'Write for an IT services company. Services = (managed IT, cloud, cybersecurity, support, software). CTAs: Get Support, Request a Quote.',
            // Real estate / design / construction
            'real_estate_agency' => 'Write for a real estate agency. Services = (sales, leasing, property management, investment advisory). CTAs: View Listings, Contact Agent.',
            'architecture'       => 'Write for an architecture practice. Services = (residential, commercial, masterplanning, interiors). CTAs: View Portfolio, Get in Touch.',
            'interior_design'    => 'Write for an interior design studio. Services = (residential, commercial, consultation, project management). CTAs: Book Consultation, View Work.',
            'construction'       => 'Write for a construction company. Services = (residential build, commercial fit-out, MEP, turnkey, renovation). CTAs: Get a Quote, View Projects.',
            'home_services'      => 'Write for a home-services company (cleaning / handyman / HVAC / plumbing). Services = service categories. CTAs: Book Service, Get a Quote.',
            'automotive'         => 'Write for an automotive business (dealership / service / detailing). Services = (sales, service, detailing, parts, finance). CTAs: Book Service, View Inventory.',
            // Retail / commerce
            'retail_shop'        => 'Write for a brick-and-mortar retail shop. Services = product categories. CTAs: Shop Now, View Collection.',
            'ecommerce'          => 'Write for an online ecommerce store. Services = product categories. CTAs: Shop Now, Browse Collection.',
            // Education
            'tutoring'           => 'Write for a tutoring / private-education service. Services = subject areas, age groups. CTAs: Book a Session, Enroll Now.',
            'training_center'    => 'Write for a vocational training center. Services = courses, certifications, corporate training. CTAs: Enroll Now, View Courses.',
            'online_courses'     => 'Write for an online-course platform. Services = course categories, formats. CTAs: Enroll Now, Start Learning.',
            // Events
            'event_venue'        => 'Write for an event venue / event production company. Services = event types (weddings, corporate, private celebrations). CTAs: Check Availability, Book a Consultation.',
            // News / media (added 2026-05-09)
            'news_channel'       => 'Write for a premium online news channel. Editorial tone, authoritative, journalistic. Headlines must sound like real news with a verb and a real claim — never marketing copy. Categories: Politics, Business, Technology, Sports, Culture, Opinion. Bylines = professional journalist names with surnames (e.g. "Layla Al-Mansoori", "Karim Hashem"). CTAs: Read More, Subscribe, Watch Live. Reading times in minutes. NEVER mention dining, food, kitchen, gym, salon, or product sales.',
        ];
        // PATCH (copy-identity, 2026-07-24) — $industry is the resolved TEMPLATE
        // (layout) slug. For unlisted businesses it is a GENERIC fallback
        // (tattoo studio → consulting), so telling the copywriter the business
        // IS a {$industry} makes it write consulting copy for a tattoo studio
        // (real symptom: "Inkredible Tattoo Studio is a premier management
        // consultancy"). Anchor the copy on the STATED industry whenever the
        // template is a non-matching fallback; matched industries are unchanged.
        $rawIndustry  = trim((string) ($data['industry'] ?? ''));
        $templateFits = ($rawIndustry === '') || ($this->confidentSlugFromText($rawIndustry) === $industry);
        $copyIndustry = $templateFits ? $industry : $rawIndustry;

        $hint = $industryHints[$industry] ?? "Use {$industry}-appropriate content only. Do not generate content from a different industry.";
        if (!$templateFits) {
            // Novel/unlisted business on a borrowed layout — force the copy to
            // the real business identity, not the template's industry.
            $hint = "This business is a {$rawIndustry} — NOT a {$industry}. Write EVERY field authentically for a {$rawIndustry}, using its real services ({$services}). Professional, premium Dubai/UAE tone. Do NOT describe it as a {$industry}, a consultancy, or an agency, and never invent services from another industry.";
        }

        // PATCH (FIX 4, 2026-05-09) — Add blog_section_title to the prompt
        // (was leaking 'From Our Kitchen' restaurant default before).
        // cuisine_* fields are now ONLY requested for food templates;
        // every other industry never gets cuisine_* generated, so they
        // can't leak.
        $isFoodIndustry = in_array($industry, ['restaurant', 'cafe', 'catering'], true);
        $cuisineBlock = $isFoodIndustry
            ? "cuisine_1_name, cuisine_1_note (a signature dish or category), cuisine_2_name, cuisine_2_note, cuisine_3_name, cuisine_3_note,\n"
            : '';

        try {
            $prompt = "Generate complete website content for '{$name}', a {$copyIndustry} business in {$location}. "
                . "Services: {$services}. "
                . "INDUSTRY RULES: {$hint}\n\n"
                . "Return a JSON object with the word json. ALL fields must have real, {$copyIndustry}-appropriate content — no placeholders:\n\n"
                . "hero_title (short punchy headline, 3-6 words, plain text),\n"
                . "hero_subtitle (one compelling sentence),\n"
                . "hero_cta (action button appropriate for {$copyIndustry}),\n"
                . "hero_eyebrow (short badge text),\n"
                . "business_tagline (short brand tagline for a {$copyIndustry} business; do NOT repeat the business name, it is shown separately),\n"
                . "about_title, about_text_1, about_text_2, about_text_3 (2-3 sentences each, {$copyIndustry}-appropriate),\n"
                . "service_1_title, service_1_text, service_2_title, service_2_text, service_3_title, service_3_text "
                . "(each service MUST be a {$copyIndustry} offering — not a different industry's service),\n"
                . $cuisineBlock
                . "testimonial_1_quote, testimonial_1_author (Full Name — Title, City),\n"
                . "testimonial_2_quote, testimonial_2_author,\n"
                . "testimonial_3_quote, testimonial_3_author (all testimonials must read like real {$copyIndustry} clients),\n"
                . "contact_email, contact_service_area, contact_availability_text,\n"
                . "blog_section_title (a short section title appropriate to {$copyIndustry} — e.g. 'Health Tips' for medical, 'Training Tips' for gym, 'Industry Insights' for consulting, 'Brewer's Notes' for cafe; for an aesthetic clinic try 'Beauty & Confidence'. NEVER use 'From Our Kitchen' unless this is literally a restaurant/cafe/catering business),\n"
                . "blog_1_title, blog_1_excerpt, blog_1_category "
                . "(blog content must be about {$copyIndustry} topics — e.g. for fitness: training methodology, recovery; for restaurant: cooking technique, sourcing; for aesthetic_clinic: pre/post-op care, treatment Q&A; for medical_clinic: preventive health, chronic care; for marketing_agency: SEO/PPC/content strategy),\n"
                . "blog_2_title, blog_2_excerpt, blog_2_category,\n"
                . "blog_3_title, blog_3_excerpt, blog_3_category,\n"
                . "meta_description (under 160 chars for SEO).\n\n"
                . "IMPORTANT: No HTML tags. No markdown. Plain text. Dubai/UAE tone. Premium quality. "
                . "Do NOT use content appropriate for any industry OTHER than {$copyIndustry}. "
                . ($isFoodIndustry ? '' : "Do NOT mention cuisine, menus, dishes, kitchen, dining, or chefs anywhere — this is NOT a food business.");

            $result = $this->runtime->chatJson(
                "You are a professional website copywriter for a {$copyIndustry} business in Dubai/UAE. "
                . "Never generate content from a different industry. Return only valid JSON with the word json.",
                $prompt,
                ['task' => 'arthur_copywrite'],
                2000
            );

            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null)) {
                // BUILDER888 P1-8B (2026-08-10) — this is the provider boundary.
                // Model JSON used to be merged straight into the template
                // variable map, so the first array-valued key it returned
                // reached str_replace() and killed the customer's build.
                // Everything now passes through the canonical contract, which
                // yields a flat scalar map, expands lists into the indexed
                // families the templates actually declare, and refuses shapes
                // it cannot represent rather than guessing at them.
                $contract = \App\Engines\Builder\Support\GenerationVariableContract::fromProvider(
                    $result['parsed'],
                    $this->declaredPlaceholders($industry)
                );

                if ($contract['expanded'] !== [] || $contract['rejected'] !== []) {
                    Log::info('[Builder888] provider output normalised', [
                        'industry' => $industry,
                        'expanded' => $contract['expanded'],
                        'rejected' => $contract['rejected'],
                    ]);
                }

                $merged = array_merge($defaults, $contract['variables']);

                return $this->overlayUserServices($merged, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] Content generation failed: ' . $e->getMessage());
        }

        return $this->overlayUserServices($defaults, $data);
    }

    // BUG 2 FIX — when the user supplied an explicit services list
    // (e.g. ["SEO","website design","social media","paid ads"]), overwrite
    // the service_1..6_title slots with those exact services and hide the
    // unused slots. User wins over LLM output and template defaults.
    /**
     * BUILDER888 P1-8B — placeholders a template declares, e.g. service_3_title.
     * Lets the generation contract expand a list only into slots the markup
     * really lays out. Cached per request; empty set means "accept on faith".
     *
     * @return array<string,bool>
     */
    private function declaredPlaceholders(string $industry): array
    {
        static $cache = [];

        $slug = $this->resolveTemplateSlug($industry);
        if (isset($cache[$slug])) {
            return $cache[$slug];
        }

        $path = storage_path("templates/{$slug}/template.html");
        if (! is_file($path)) {
            return $cache[$slug] = [];
        }

        $html = (string) @file_get_contents($path);
        preg_match_all('/\{\{([a-z_0-9]+)\}\}/i', $html, $m);

        $set = [];
        foreach ($m[1] ?? [] as $name) {
            $set[$name] = true;
        }

        return $cache[$slug] = $set;
    }
    private function overlayUserServices(array $vars, array $data): array
    {
        $svc = $data['services'] ?? null;
        if (!is_array($svc) || empty($svc)) return $vars;

        $svc = array_values(array_filter(array_map(
            fn($s) => is_string($s) ? trim($s) : '',
            $svc
        ), fn($s) => $s !== ''));
        if (empty($svc)) return $vars;

        for ($i = 0; $i < 6; $i++) {
            $slot = $i + 1;
            if (isset($svc[$i])) {
                $title = ucwords($svc[$i]);
                $vars["service_{$slot}_title"] = $title;
                // Only fabricate a description when the template/LLM didn't
                // provide one — never clobber a real write-up.
                if (empty($vars["service_{$slot}_text"])
                    || $this->isCrossIndustryLeak((string)$vars["service_{$slot}_text"], $this->resolveTemplateSlug((string)($data['industry'] ?? '')))) {
                    $vars["service_{$slot}_text"] = "Professional {$title} tailored to your business goals.";
                }
                $vars["service_{$slot}_display"] = '';
            } else {
                $vars["service_{$slot}_display"] = 'display:none';
            }
        }
        return $vars;
    }

    private function getHeroImagePrompt(string $industry, string $location): string
    {
        // PATCH (FIX 3, 2026-05-09) — Hero prompts expanded from 8 → 30
        // entries. Keys MUST match on-disk template slugs (the resolved
        // industry post resolveTemplateSlug). Generic fallback retained
        // for any future template that lands here without a specific
        // prompt, so the call never fails.
        $prompts = [
            // Food & hospitality
            'restaurant'         => 'Elegant upscale restaurant interior, warm ambient candlelight, dark wood tables set for dinner, intimate fine dining atmosphere, soft bokeh background, professional food photography style, wide cinematic shot, warm golden tones',
            'cafe'               => 'Cozy modern coffee shop interior, plant-filled, latte art on bar counter, artisan bakery items on wood counter, warm morning light streaming through windows',
            'catering'           => 'Elegant catering spread on long banquet table, beautifully arranged platters, professional event setup with stemware and floral centerpieces',
            'hotel'              => 'Luxurious boutique hotel lobby, marble floors, crystal chandelier, premium hospitality, golden hour daylight',
            'resort'             => 'Tropical luxury resort, infinity pool overlooking calm sea, palm trees, dramatic golden-hour landscape',
            'short_term_rental'  => 'Modern stylish apartment interior, floor-to-ceiling windows with city view, premium furnishings, bright daylight',
            'travel_agency'      => 'Stunning travel destination, exotic landscape, mountains or coastline at golden hour, sense of adventure and exploration',
            // Medical / health
            'dental'             => 'Modern dental clinic, clean bright white interior, professional dental chair, welcoming reception, calming atmosphere',
            'medical_clinic'     => 'Professional modern medical clinic reception, clean bright interior, soft daylight, trusted healthcare environment',
            'aesthetic_clinic'   => 'Luxury aesthetic / plastic-surgery clinic, pristine white and warm-marble interior, premium beauty treatment room, sophisticated calm ambiance',
            // Fitness / beauty
            'gym'                => 'Modern premium gym interior, professional equipment racks, dramatic lighting, wide angle, motivational atmosphere',
            'beauty_salon'       => 'Elegant beauty salon interior, styling chairs, vanity mirrors with warm bulb lighting, sophisticated decor, spa atmosphere',
            'barbershop'         => 'Classic modern barbershop, leather chairs, vintage barber tools, warm Edison-bulb lighting, masculine sophisticated interior',
            // Pet & childcare
            'pet_services'       => 'Happy pet being groomed by a calm professional, warm welcoming environment, natural daylight, modern clean facility',
            'childcare'          => 'Bright colorful childcare center play area, age-appropriate toys, soft natural light, safe nurturing environment, no children visible',
            // Professional services
            'consulting'         => 'Professional corporate boardroom, glass walls, city view, leather chairs around walnut conference table, confident sophisticated atmosphere',
            'marketing_agency'   => 'Modern creative agency office, team collaborating around large screens with analytics dashboards, dynamic professional environment, daylight',
            'it_services'        => 'Modern technology workspace, server racks softly lit, multiple monitors with code, professional IT environment, blue accent lighting',
            // Real estate / design / construction
            'real_estate_agency' => 'Stunning luxury property exterior, modern architecture, floor-to-ceiling windows, manicured landscaping, golden hour',
            'architecture'       => 'Award-winning modern architecture, striking building geometry, blue-hour exterior, dramatic detail of facade',
            'interior_design'    => 'Stunning luxury interior design, curated living space, premium materials and finishes, warm natural light',
            'construction'       => 'Modern construction site at golden hour, partially completed structure, clean professional, skilled workers in branded uniforms (no faces)',
            'home_services'      => 'Professional home-service technician working in a clean modern home, quality workmanship in progress, natural daylight',
            'automotive'         => 'Premium automotive showroom or service bay, luxury cars under dramatic lighting, polished concrete floor, professional environment',
            // Retail / commerce
            'retail_shop'        => 'Elegant modern retail store, beautiful product displays on warm wood shelves, premium shopping experience, soft daylight',
            'ecommerce'          => 'Premium ecommerce product flat-lay or photo studio scene, clean white background, beautifully styled product, soft studio lighting',
            // Education
            'tutoring'           => 'Engaged student studying at a modern desk with tutor pointing at a textbook, warm natural light, focused educational atmosphere',
            'training_center'    => 'Modern training facility classroom, projector screen with content, students at desks (no clear faces), professional atmosphere',
            'online_courses'     => 'Modern online-learning setup, laptop with course content on screen, headphones, notebook, warm desk light, focused environment',
            // Events
            'event_venue'        => 'Stunning event venue ballroom, dramatic uplighting, elegant tablescapes with floral centerpieces, premium celebration space, golden warm tones',
            // News / media (added 2026-05-09)
            'news_channel'       => 'Premium editorial newsroom, modern journalism workspace, multiple monitors with breaking news headlines, professional broadcast studio with anchor desk, dramatic blue and red key lighting, high-end TV-news production aesthetic',
        ];

        $base = $prompts[$industry] ?? "Professional modern {$industry} business interior, clean design, warm lighting, premium atmosphere";
        return $base . ", photorealistic, 16:9 aspect ratio, no text, no signs, no logos, no words, no lettering, no watermarks";
    }

    // PATCH (hero-context, 2026-07-24) — Hero prompt anchored on the ACTUAL
    // business (raw industry + services), used when the template is a borrowed
    // fallback so the hero depicts the real business, not the template's slug.
    private function getBusinessHeroPrompt(string $rawIndustry, string $services, string $location): string
    {
        $ri  = trim($rawIndustry) !== '' ? trim($rawIndustry) : 'business';
        $svc = trim($services) !== '' ? " that offers {$services}" : '';
        return "Photorealistic hero background for a {$ri}{$svc} in {$location}. "
            . "Depict an authentic, on-brand real-world environment for a {$ri} — premium atmosphere, "
            . "natural lighting, wide cinematic 16:9 composition, no people's faces. "
            . "photorealistic, no text, no signs, no logos, no words, no lettering, no watermarks";
    }

    // PATCH (hero-context, 2026-07-24) — Find an EXISTING media asset applicable
    // to the actual business (by industry tag / tags matching the raw industry +
    // service tokens), preferring the workspace's own uploads. Returns null when
    // nothing applicable exists so the caller generates a fresh business hero.
    private function findApplicableHero(string $rawIndustry, string $services, int $wsId): ?array
    {
        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower($rawIndustry . ' ' . $services)),
            fn($t) => strlen($t) >= 4
        ));
        if (empty($tokens)) return null;
        $tokens = array_slice(array_unique($tokens), 0, 8);
        try {
            $row = DB::table('media')
                ->whereIn('asset_type', ['hero', 'image'])
                ->whereNotNull('url')->where('url', '!=', '')
                ->where(function ($w) use ($tokens) {
                    // BUGFIX (2026-07-24) — the media table has NO 'industry' column
                    // (was erroring on every borrowed-template build). Match the raw
                    // industry/service tokens against the JSON `tags` array instead.
                    foreach ($tokens as $t) {
                        $w->orWhere('tags', 'like', '%"' . $t . '"%')
                          ->orWhere('category', 'like', '%' . $t . '%');
                    }
                })
                ->where(function ($w) use ($wsId) {
                    $w->where('workspace_id', $wsId)->orWhereNull('workspace_id');
                })
                ->orderByRaw('CASE WHEN workspace_id = ? THEN 0 ELSE 1 END', [$wsId])
                ->orderByRaw("CASE WHEN asset_type = 'hero' THEN 0 ELSE 1 END")
                ->first(['id', 'url']);
            if ($row && !empty($row->url)) {
                Log::info('[Arthur] applicable existing hero found for borrowed template', [
                    'raw' => $rawIndustry, 'media_id' => $row->id,
                ]);
                return ['id' => $row->id, 'url' => $row->url];
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] findApplicableHero failed: ' . $e->getMessage());
        }
        return null;
    }

    // PATCH (credibility-injection, 2026-07-24 · P3) — When a business is
    // established, map its REAL data (build_data.stats / clients / case_studies)
    // into the template's credibility variables so the kept blocks show the
    // business's own numbers, not the template sample. Returns the set of keys it
    // populated (assoc, key => true) so the caller can blank everything it didn't.
    private function injectCredibilityData(array &$variables, array $data, array $manifestVars): array
    {
        $set = [];
        $put = function (string $key, $val) use (&$variables, &$set, $manifestVars) {
            if (array_key_exists($key, $manifestVars) && is_string($val) && trim($val) !== '') {
                $variables[$key] = $val;
                $set[$key] = true;
            }
        };

        // STATS — [{value,label}] (or {label:value}); fills stat_N / hero_stat_N / strip_stat_N.
        $stats = $this->normalizeKV($data['stats'] ?? [], 'value', 'label');
        foreach ($stats as $i => $s) {
            $n = $i + 1;
            foreach (['stat', 'hero_stat', 'strip_stat', 'stats_strip'] as $pfx) {
                $put("{$pfx}_{$n}_value", (string) ($s['value'] ?? ''));
                $put("{$pfx}_{$n}_label", (string) ($s['label'] ?? ''));
            }
        }

        // CLIENTS — [names]; fills client_logo_N (+ clients_title if provided).
        $clients = array_values(array_filter(array_map('strval', (array) ($data['clients'] ?? $data['client_logos'] ?? [])), fn($v) => trim($v) !== ''));
        foreach ($clients as $i => $name) {
            $put('client_logo_' . ($i + 1), $name);
            $put('client_logo' . ($i + 1), $name); // some templates omit the underscore
        }
        if (!empty($data['clients_title'])) $put('clients_title', (string) $data['clients_title']);

        // CASE STUDIES — [{client,title,metrics:[{value,label}]}].
        foreach ((array) ($data['case_studies'] ?? []) as $idx => $case) {
            if (!is_array($case)) continue;
            $n = $idx + 1;
            $put("case_{$n}_client", (string) ($case['client'] ?? ''));
            $put("case_{$n}_title", (string) ($case['title'] ?? ''));
            foreach ($this->normalizeKV($case['metrics'] ?? [], 'value', 'label') as $mi => $m) {
                $mn = $mi + 1;
                $put("case_{$n}_metric_{$mn}_value", (string) ($m['value'] ?? ''));
                $put("case_{$n}_metric_{$mn}_label", (string) ($m['label'] ?? ''));
            }
        }
        return $set;
    }

    /** Normalise [{value,label}] OR ['label'=>'value'] OR ['label'=>N] into [{value,label}]. */
    private function normalizeKV($raw, string $vk, string $lk): array
    {
        if (!is_array($raw) || empty($raw)) return [];
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_array($v)) {
                $out[] = [$vk => (string) ($v[$vk] ?? $v['value'] ?? $v[0] ?? ''), $lk => (string) ($v[$lk] ?? $v['label'] ?? $v[1] ?? '')];
            } elseif (is_string($k)) {
                $out[] = [$vk => (string) $v, $lk => (string) $k]; // label=>value map
            }
        }
        return $out;
    }

    // PATCH (bespoke-blocks, 2026-07-24 · P2b-full) — Build a real industry
    // section (menu with prices / product catalog / destinations) to REPLACE the
    // generic 3-card "services" block on templates that lack one. Returns section
    // HTML (reusing the template's own classes so it themes automatically) or ''
    // to keep the original block. Slug-gated to the clones that need it.
    private function bespokeSectionFor(string $slug, array $data, int $wsId = 0): string
    {
        // 'images' mode: false = text cards; 'pool' = generic industry photos are
        // fine (travel destinations); 'uploads' = photo cards ONLY if the OWNER
        // uploaded real product photos (retail/ecommerce — the generic platform
        // gallery is store scenes that mismatch product names, so text unless the
        // owner brought their own shots).
        $cfg = [
            'restaurant'    => ['kind' => 'menu',    'images' => false,     'count' => 8, 'noun' => 'signature menu dishes',              'price' => "a realistic dish price like 'AED 45'",        'labels' => ['eyebrow' => 'What We Serve',     'title' => 'Our Menu']],
            'catering'      => ['kind' => 'menu',    'images' => false,     'count' => 6, 'noun' => 'catering menu packages',             'price' => "a per-head/package price like 'AED 120 / head'", 'labels' => ['eyebrow' => 'Catering Menus',    'title' => 'Menus & Packages']],
            'retail_shop'   => ['kind' => 'catalog', 'images' => 'uploads', 'count' => 6, 'noun' => 'featured products or collections',   'price' => "a price like 'AED 120' or 'From AED 90'",     'labels' => ['eyebrow' => 'Our Collection',    'title' => 'Shop by Category']],
            'ecommerce'     => ['kind' => 'catalog', 'images' => 'uploads', 'count' => 6, 'noun' => 'featured products',                  'price' => "a price like 'AED 120'",                      'labels' => ['eyebrow' => 'Our Collection',    'title' => 'Shop the Collection']],
            'travel_agency' => ['kind' => 'catalog', 'images' => 'pool',    'count' => 6, 'noun' => 'destination trips or packages',      'price' => "a from-price like 'From AED 2,400'",          'labels' => ['eyebrow' => 'Where We Take You', 'title' => 'Destinations & Packages']],
            'resort'            => ['kind' => 'units', 'images' => 'pool', 'count' => 6, 'noun' => 'room / suite / villa types', 'price' => "a per-night rate like 'From AED 1,200 / night'", 'meta' => "a short capacity line like 'Sleeps 4 · 65m² · Sea view'", 'labels' => ['eyebrow' => 'Your Stay', 'title' => 'Rooms & Suites']],
            'short_term_rental' => ['kind' => 'units', 'images' => 'pool', 'count' => 6, 'noun' => 'rental unit / apartment types', 'price' => "a per-night rate like 'From AED 600 / night'",  'meta' => "a short capacity line like 'Sleeps 3 · 1 bed · City view'", 'labels' => ['eyebrow' => 'Your Stay', 'title' => 'Our Spaces']],
        ][$slug] ?? null;
        if (!$cfg) return '';
        $items = $this->generateBespokeItems($data, $cfg);
        if (empty($items)) return '';
        $labels = $cfg['labels'] + ['intro' => ''];
        if ($cfg['kind'] === 'menu') {
            return \App\Engines\Builder\Support\SectionLibrary::menuSection($items, $labels);
        }
        // catalog / units — enrich with photos per the mode; otherwise text cards.
        $images = [];
        $mode = $cfg['images'] ?? false;
        if ($mode === 'pool' && $wsId > 0) {
            try { $images = $this->buildImagePool($slug, $wsId); } catch (\Throwable $e) {}
        } elseif ($mode === 'uploads') {
            // ONLY the owner's own uploaded product photos — never the generic pool.
            $up = array_values(array_filter(array_map('strval', (array) ($data['uploaded_images'] ?? [])), fn($u) => trim($u) !== ''));
            if (count($up) >= 3) $images = $up; // enough to fill a grid; else stay text
        }
        if ($cfg['kind'] === 'units') {
            return \App\Engines\Builder\Support\SectionLibrary::unitsSection($items, $labels, $images);
        }
        return \App\Engines\Builder\Support\SectionLibrary::catalogSection($items, $labels, $images);
    }

    private function generateBespokeItems(array $data, array $cfg): array
    {
        if (!$this->runtime->isConfigured()) return [];
        $name     = (string) ($data['business_name'] ?? 'the business');
        $industry = (string) ($data['industry'] ?? 'business');
        $location = (string) ($data['location'] ?? 'Dubai');
        $services = is_array($data['services'] ?? null) ? implode(', ', $data['services']) : (string) ($data['services'] ?? '');
        $sys = "You write website content for '{$name}', a {$industry} in {$location}. Every item must be specific "
             . "and realistic for a {$industry} — never placeholders. Return ONLY valid JSON (include the word json).";
        $metaKey = !empty($cfg['meta']) ? ',"meta":"..."' : '';
        $metaRule = !empty($cfg['meta']) ? " and meta is {$cfg['meta']}" : '';
        $prompt = "Generate {$cfg['count']} {$cfg['noun']} for this business. Services: {$services}. "
             . 'Return {"items":[{"name":"...","price":"..."' . $metaKey . ',"desc":"short 6-12 word description"}]} '
             . "where price is {$cfg['price']}{$metaRule}.";
        try {
            $r = $this->runtime->chatJson($sys, $prompt, ['task' => 'arthur_bespoke_items'], 1300);
            $items = $r['parsed']['items'] ?? ($r['parsed'] ?? null);
            if (is_array($items)) {
                return array_values(array_filter($items, fn($i) => is_array($i) && !empty($i['name'])));
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] bespoke items failed: ' . $e->getMessage());
        }
        return [];
    }

    // PATCH (no-static-text, 2026-07-24) — Several template.html files hardcode
    // SAMPLE staff names — in booking <option>s AND team cards ("Dr. James
    // Whitfield", "Lila Hadid", "Marcus Idowu", …); even non-medical templates
    // inherited the doctor block, so a catering site could show a "Dr. James
    // Whitfield" option. These are HTML literals (not variables), so replace
    // every occurrence post-render with a realistic, locale-neutral placeholder
    // name (a fresh site has no real team yet — the user edits these in the
    // builder). Replacing the surname-bearing form also fixes "Dr. " instances.
    private function scrubSampleStaff(string $html): string
    {
        $map = [
            'Aisha Rahman'    => 'Layla Hassan',
            'Omar Al-Sayed'   => 'Karim Nasser',
            'Sarah Nakamura'  => 'Mariam Saleh',
            'James Whitfield' => 'Adam Farouk',
            'Lila Hadid'      => 'Yasmin Aziz',
            'Priya Desai'     => 'Hana Malik',
            'Sofia Moreau'    => 'Reem Khalil',
            'Nadia Chen'      => 'Sara Mansour',
            'Layla Nader'     => 'Dana Rashed',
            'Marcus Chen'     => 'Tariq Aziz',
            'Marcus Idowu'    => 'Zaid Haddad',
            'Nadia Petrov'    => 'Lina Fares',
            'Jordan Walker'   => 'Nour Sami',
            'Rania Al Sabah'  => 'Salma Darwish',
            'Priya Menon'     => 'Amira Fadel',
        ];
        $html = str_ireplace(array_keys($map), array_values($map), $html);
        // Backstop — remove any remaining "<option>Dr. Firstname Lastname</option>".
        $html = preg_replace('~<option\b[^>]*>\s*Dr\.?\s+[A-Z][a-z]+\s+[A-Z][a-z]+\s*</option>~', '', $html);

        // Hide the fabricated-credibility sections (their VALUES are blanked in
        // generateWebsite). Classes are credibility-specific — deliberately NOT
        // generic layout classes (.reveal/.section/.wrap). A fresh business has
        // no stats/clients/case-studies; the user re-enables and fills them in
        // the builder.
        $hideCss = '<style id="lu-hide-fabricated">'
            . '.stats,.stats-grid,.stats-strip,.stats-strip-item,.stats-strip-label,'
            . '.stat-item,.stat-label,.stat-value,.hero-stats,.hero-stat,.hero-stat-label,.hero-stat-value,'
            . '.clients,.clients-grid,.clients-title,.client-logo,.client-logos,'
            . '.case-metrics,.case-metric,.case-metric-value,.case-metric-label,'
            . '.results,.result-item,.result-label,.result-value'
            . '{display:none !important}</style>';
        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('~</head>~i', $hideCss . '</head>', $html, 1);
        } else {
            $html .= $hideCss;
        }
        return is_string($html) ? $html : '';
    }

    // PATCH (text-coverage, 2026-07-24) — Regenerate EVERY remaining template
    // text field from business context so no manifest SAMPLE text (sample names,
    // wrong-industry taglines/prose) can render. Skips colors/images/urls and
    // short structural labels (nav/menu/buttons), targets only unfilled content
    // prose. A deterministic net neutralizes anything the LLM still misses.
    private function fillTemplateTextCoverage(array $variables, array $manifest, array $data, string $copyIndustry, string $services, string $location, bool $established = false): array
    {
        $vars = $manifest['variables'] ?? [];
        if (!is_array($vars) || empty($vars)) return $variables;

        $skipKey = '/(image|img|photo|logo|url|color|colour|display|icon|bg|background|style|css|href|src|width|height|dim|ratio|font|hex|locale|canonical|slug|og_image|_id$)/i';
        // structural labels we must NOT rewrite/blank (would break nav/buttons)
        $structKey = '/(nav|menu|link|button|_cta$|^cta|tab|breadcrumb|^logo|_label$)/i';

        $name = $data['business_name'] ?? 'the business';
        $toFill = [];
        foreach ($vars as $k => $spec) {
            if (!is_array($spec)) continue;
            $type = strtolower((string) ($spec['type'] ?? ''));
            if (in_array($type, ['color', 'image', 'url', 'file', 'media', 'number', 'bool', 'boolean'], true)) continue;
            $key = (string) $k;
            if (preg_match($skipKey, $key) || preg_match($structKey, $key)) continue;
            $def = $spec['default'] ?? null;
            if (!is_string($def)) continue;
            $def = trim($def);
            // Only CONTENT prose (multi-word, >=15 chars) — leaves short generic
            // labels ("About Us", "Our Services") untouched.
            if (strlen($def) < 15 || strpos($def, ' ') === false) continue;
            if (preg_match('~^(/|https?:|\#|display:)~i', $def)) continue;
            $cur = $variables[$key] ?? null;
            $filledByLLM = is_string($cur) && $cur !== '' && $cur !== $def;
            if ($filledByLLM) continue;
            $toFill[$key] = (string) ($spec['label'] ?? $spec['description'] ?? $key);
        }
        if (empty($toFill)) return $variables;

        // NEVER FABRICATE PEOPLE. Numbered personnel-card fields
        // (doctor_1_name / team_2_name / staff_3_bio / attorney_1_title / ...)
        // are EXCLUDED from the LLM pass — asking for "realistic names" invents
        // fake individuals, whose photos injectImagesToTemplate then fills with
        // mismatched, often-broken cross-industry pool images. Left unfilled the
        // deterministic net below blanks them, and phantom-card + empty-section
        // stripping removes the card/section so the customer adds their REAL team
        // in the editor. Product/service/business names are NOT personnel and
        // stay in the LLM pass.
        $personnelKey = '/^(doctor|dentist|physician|surgeon|therapist|trainer|'
            . 'instructor|coach|staff|team|member|attorney|lawyer|agent|broker|'
            . 'realtor|stylist|barber|nurse|faculty|advisor|consultant|specialist)'
            . '_\\d+_(name|title|specialty|speciality|bio|role|credential|'
            . 'qualification|position)/i';
        $llmToFill = array_filter(
            $toFill,
            fn ($k) => ! preg_match($personnelKey, (string) $k),
            ARRAY_FILTER_USE_KEY
        );

        // Regenerate in chunks so a large template stays reliable.
        foreach (array_chunk($llmToFill, 35, true) as $chunk) {
            $lines = [];
            foreach ($chunk as $k => $label) $lines[] = "- {$k}: {$label}";
            $sys = "You are a website copywriter for '{$name}', a {$copyIndustry} in {$location}. "
                 . "Return ONLY valid JSON (include the word json). Every value must be authentic, specific "
                 . "content for THIS {$copyIndustry} business — realistic names (never 'John Doe'), concise "
                 . "taglines, 1-2 sentence body copy — and NEVER content from any other industry."
                 . ($established ? '' : ' CRITICAL: this is a NEW business with NO track record yet — NEVER '
                    . 'fabricate numbers or claims of experience (no "X years", "X+ clients/projects", revenue '
                    . 'figures, awards, "trusted by", or big-name clients). For trust/badge/credential fields '
                    . 'write qualitative value propositions (approach, quality, care), not invented metrics.');
            $prompt = "Services: {$services}.\nWrite on-brand website text for EACH field below, matching its "
                 . "label/role. Return a JSON object keyed EXACTLY by these field keys:\n" . implode("\n", $lines);
            try {
                $res = $this->runtime->chatJson($sys, $prompt, ['task' => 'arthur_coverage'], 2000);
                if (($res['success'] ?? false) && is_array($res['parsed'] ?? null)) {
                    foreach ($res['parsed'] as $k => $v) {
                        if (isset($toFill[$k]) && is_string($v) && trim($v) !== '') {
                            $variables[$k] = trim($v);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[Arthur] text coverage pass failed: ' . $e->getMessage());
            }
        }

        // Deterministic net — anything STILL unfilled is neutralized so no sample
        // text can survive. Names/people → blank (never a fake person); taglines/
        // meta → derive from business; other content prose → blank.
        foreach ($toFill as $k => $label) {
            $def = trim((string) ($vars[$k]['default'] ?? ''));
            $cur = $variables[$k] ?? null;
            if (is_string($cur) && $cur !== '' && $cur !== $def) continue; // filled
            if (preg_match('/name|author|signature|broker|agent|partner|founder|owner|byline|member|manager|attorney|realtor/i', $k)) {
                $variables[$k] = '';
            } elseif (preg_match('/tagline|meta|subtitle|description/i', $k)) {
                $variables[$k] = $name . ' — ' . ucfirst($copyIndustry) . ' in ' . $location . '.';
            } else {
                $variables[$k] = '';
            }
        }
        return $variables;
    }

    /**
     * Per-industry gallery prompts. Added 2026-04-19 to replace a hardcoded
     * restaurant-themed array that was polluting every non-restaurant build.
     * Returns 3 prompts per industry. Unknown industries fall back to a
     * generic professional set.
     */
    private function getGalleryPrompts(string $industry, string $location): array
    {
        $map = [
            'restaurant' => [
                'Elegant gourmet cuisine plating on dark slate, professional food photography, warm lighting',
                'Luxury restaurant table setting, candlelight, gold cutlery, dark moody intimate atmosphere, no people',
                'Fresh colorful ingredients and spices flatlay, dark background, overhead shot, professional food photography',
            ],
            'cafe' => [
                'Artisan coffee being poured into ceramic cup with latte art, warm morning light, cozy cafe ambiance',
                'Fresh pastries and baked goods on wooden counter, warm golden lighting, artisan cafe aesthetic',
                'Cozy cafe interior with exposed brick, wooden tables, string lights, inviting atmosphere',
            ],
            'fitness' => [
                'Modern premium gym interior with free weights and equipment, dramatic lighting, motivational atmosphere',
                'Athletic person performing bodyweight exercise, professional sports photography, dynamic composition',
                'Premium fitness equipment detail shot, dumbbells and kettlebells, gym aesthetic, natural light',
            ],
            'beauty' => [
                'Luxury beauty salon styling chair, professional lighting, elegant mirror, upscale interior',
                'Skincare products flatlay on marble surface, soft daylight, minimalist beauty photography',
                'Professional makeup brushes and cosmetics palette arranged elegantly, soft pink backdrop',
            ],
            'healthcare' => [
                'Clean modern medical examination room, stethoscope on desk, professional healthcare setting',
                'Caring medical consultation between doctor and patient in modern clinic, warm welcoming light',
                'Medical instruments and charts arranged neatly on clinical surface, professional environment',
            ],
            'legal' => [
                'Prestigious law office bookshelf, leather-bound legal volumes, warm library lighting',
                'Legal contract signing desk with fountain pen and documents, professional law firm atmosphere',
                'Scales of justice statue on mahogany desk, dramatic lighting, classical legal aesthetic',
            ],
            'real_estate' => [
                'Luxury property exterior at golden hour, modern architecture, manicured landscape',
                'High-end property interior, open-concept living space with floor-to-ceiling windows',
                'Premium residential kitchen, marble countertops, designer fixtures, natural light',
            ],
            'real_estate_broker' => [
                'Real estate broker showing luxury property to client, handshake moment, elegant home interior',
                'Dubai skyline view from high-floor luxury apartment, panoramic windows at sunset',
                'Property documents being signed, premium pen on closing contract, real estate transaction moment',
            ],
            'fashion' => [
                'Elegant fashion boutique interior, curated garments on hangers, soft ambient lighting',
                'Luxury fabric texture close-up, haute couture detail, artisan craftsmanship',
                'Fashion model in editorial pose, studio lighting, minimalist backdrop',
            ],
            'technology' => [
                'Modern tech office workspace with multiple monitors showing code, clean minimalist design',
                'Server room with cascading blue LED lights, enterprise infrastructure aesthetic',
                'Developer hands typing on mechanical keyboard, dim ambient office, focused productivity',
            ],
            'marketing_agency' => [
                'Modern marketing agency office with team collaborating, monitors displaying analytics dashboards',
                'Creative brainstorm session with sticky notes and whiteboards, dynamic workspace atmosphere',
                'Social media creative assets being designed on laptop, mood board with color palette',
            ],
            'events' => [
                'Elegant wedding reception table setting, floral centerpieces, candlelight ambiance',
                'Event venue setup with dramatic uplighting, empty chairs in formation, anticipation',
                'Corporate event stage with spotlights and audience silhouettes, professional production',
            ],
            'interior_design' => [
                'Modern living room interior with designer furniture, curated art, natural light',
                'Luxury bedroom design, premium bedding, accent lighting, sophisticated color palette',
                'High-end kitchen interior, open shelving, marble island, pendant lighting',
            ],
            'education' => [
                'Modern university lecture hall with engaged students, natural light through large windows',
                'Library interior with study areas, wooden shelves, scholarly atmosphere',
                'Science laboratory class with students and microscopes, STEM education, bright lighting',
            ],
            'automotive' => [
                'Luxury car showroom floor with gleaming vehicles, polished concrete, dramatic lighting',
                'Auto technician working on engine bay, professional garage, attention to detail',
                'Premium car interior detail, leather seats and stitched dashboard, luxury cabin',
            ],
            'hospitality' => [
                'Luxury hotel lobby with grand chandelier, marble floors, elegant reception desk',
                'Premium hotel suite bedroom with panoramic city view, silk bedding, ambient lighting',
                'Resort infinity pool at sunset, tropical palms, aspirational travel destination',
            ],
            'cleaning' => [
                'Sparkling clean modern kitchen after professional service, natural light, pristine surfaces',
                'Professional cleaner in uniform using eco-friendly products in bright home interior',
                'Organized cleaning supplies and equipment arranged neatly, hygienic aesthetic',
            ],
            'construction' => [
                'Construction site with cranes and steel structure, blue sky backdrop, dynamic composition',
                'Architect reviewing blueprints on site, hard hat, focused professional atmosphere',
                'Modern building under construction, concrete and steel detail, industrial aesthetic',
            ],
            'photography' => [
                'Professional photography studio with lighting setup, seamless backdrop, camera on tripod',
                'Portrait session with natural light, artistic model composition, editorial mood',
                'Camera lens detail macro shot, aperture blades visible, photography equipment artistry',
            ],
            'childcare' => [
                'Bright colorful nursery classroom with wooden toys, cheerful learning environment',
                'Happy children playing with educational materials, safe nursery interior, natural light',
                'Teacher reading to attentive preschool children, cozy story corner, warm atmosphere',
            ],
            'consulting' => [
                'Modern executive boardroom with strategic presentation on screen, professional meeting',
                'Consultants reviewing business charts and financial data, collaborative problem-solving',
                'Corporate strategy whiteboard session with frameworks, modern consulting office',
            ],
            'finance' => [
                'Financial advisor office with charts and laptop on desk, professional consultation setting',
                'Modern accounting workspace with documents, calculator, tax preparation materials',
                'Corporate finance meeting, executives reviewing quarterly reports, professional atmosphere',
            ],
            'wellness' => [
                'Serene yoga studio with natural light, meditation cushions, calming sage tones',
                'Holistic wellness treatment room with natural materials, aromatherapy, healing atmosphere',
                'Mindfulness session outdoors with nature backdrop, peaceful contemplative mood',
            ],
            'pet_services' => [
                'Happy dog being groomed at professional salon, gentle caring hands, clean environment',
                'Modern veterinary clinic examination room, caring vet with friendly pet, bright interior',
                'Pets playing together at daycare facility, joyful atmosphere, safe indoor play area',
            ],
            'logistics' => [
                'Modern warehouse with organized shelving and forklift in action, efficient operations',
                'Shipping containers at port with cranes, global logistics, industrial scale',
                'Delivery vehicles lined up professionally, logistics fleet, transportation service',
            ],
            'architecture' => [
                'Stunning modern architecture detail, geometric forms, dramatic shadow play',
                'Architecture studio with physical models and technical drawings, creative design process',
                'Contemporary building facade close-up, materials and texture, architectural photography',
            ],
        ];

        $prompts = $map[$industry] ?? [
            "Professional {$industry} business interior in {$location}, clean modern design, warm lighting, premium atmosphere",
            "Contemporary {$industry} workspace, team collaboration, aspirational environment",
            "Premium {$industry} service in action, professional quality, attention to detail",
        ];
        $suffix = ', no text no logos no words, photorealistic';
        return array_map(fn($p) => $p . $suffix, $prompts);
    }

    /**
     * PATCH 8 (2026-05-08) — Architecture Lock Tier 1.
     *
     * Build a canonical sections_json array for an Arthur-generated page so
     * BuilderRenderer can serve the page without depending on the static
     * index.html file. Tier 1 produces a minimal-but-valid set covering the
     * 7 BuilderRenderer-supported types (header / hero / features / cta /
     * contact_form / blog_list / footer). Tier 2 will derive richer content
     * from Arthur's wizard state instead of these defaults.
     *
     * Section schema is what BuilderRenderer expects:
     *   [{"type": "...", "heading": "...", "body": "...", ...}, ...]
     */
    /**
     * v1.4.4 (2026-05-30) — made public so BuilderService::addPageFromTemplate
     * can reuse it when Sarah / the user asks Arthur to add a new page.
     */
    // PATCH (pages-context, 2026-07-24 · P4) — Public entry: build the page's
    // section SKELETON (structure), then enrich its copy with business-context,
    // archetype-aware text so an added About/Services/Contact page no longer
    // ships identical consultancy filler ("Quality first", "work with you on
    // your project") for a bakery or a law firm alike.
    public function buildDefaultSectionsForPage(string $slug, array $data): array
    {
        $sections = $this->buildRawPageSections($slug, $data);
        // RISK-0097 residue — carry the industry hero image into hero sections so the
        // dynamic renderer (structural-edit fallback) shows a hero image like the
        // static template, not an imageless hero. Runs on the always-built skeleton.
        // Leading-slash-normalized (some builder_default_assets rows lack it).
        try {
            $heroUrl = trim((string) ($data['hero_image'] ?? ''));
            if ($heroUrl === '') {
                $ind = $this->confidentSlugFromText((string) ($data['industry'] ?? '')) ?: '';
                if ($ind !== '') {
                    $heroUrl = (string) DB::table('builder_default_assets')
                        ->where('asset_type', 'hero')->where('industry', $ind)->value('url');
                }
            }
            if ($heroUrl !== '') {
                if ($heroUrl[0] !== '/' && ! preg_match('#^https?://#', $heroUrl)) {
                    $heroUrl = '/' . ltrim($heroUrl, '/');
                }
                foreach ($sections as &$sec) {
                    if (($sec['type'] ?? '') === 'hero' && empty($sec['background_image'])) {
                        $sec['background_image'] = $heroUrl;
                    }
                }
                unset($sec);
            }
        } catch (\Throwable $e) { /* non-fatal: hero stays imageless */ }
        try {
            $sections = $this->enrichPageSections($sections, $data, $slug);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] enrichPageSections failed: ' . $e->getMessage());
        }
        return $sections;
    }

    /**
     * One LLM call → business-context copy for the given page. Returns [] on any
     * failure so the raw skeleton's (now archetype-improved) copy stands.
     */
    private function pageCopy(string $pageType, array $data): array
    {
        if (!$this->runtime->isConfigured()) return [];
        $name     = (string) ($data['business_name'] ?? 'the business');
        $industry = trim((string) ($data['industry'] ?? 'business')) ?: 'business';
        $location = (string) ($data['location'] ?? 'Dubai');
        $services = is_array($data['services'] ?? null) ? implode(', ', $data['services']) : (string) ($data['services'] ?? '');
        $spec = [
            'about'    => 'story_body (2-3 sentences on how this business started and what it stands for), value_1_title (2-3 words), value_1_body (1 sentence), value_2_title, value_2_body, value_3_title, value_3_body, team_heading (short), reviews_heading (short), cta_heading (short), cta_body (1 sentence), cta_text (2-3 words)',
            'services' => 'hero_body (1 sentence), section_heading (short), reviews_heading (short), cta_heading (short), cta_body (1 sentence), cta_text (2-3 words)',
            'contact'  => 'hero_subheading (short), hero_body (1-2 sentences), form_heading (short), form_body (1 sentence)',
            'pricing'  => "hero_subheading (short), hero_body (1 sentence), pricing_heading (short), tiers (an array of EXACTLY 3 objects {name: short plan name that fits a {$industry}, price: a realistic price like 'AED 250' or 'AED 250/mo' where a {$industry} has standard pricing, otherwise 'Contact us' or 'Custom' — never '\$X', features: array of 3-4 short benefit strings specific to a {$industry}}), faq_heading (short), faq (array of 3 objects {question, answer} that a {$industry} customer actually asks about pricing), cta_heading (short), cta_body (1 sentence), cta_text (2-3 words)",
            'faq'      => "hero_body (1 sentence), faq (array of 5 objects {question, answer} — real questions a {$industry} customer asks, answered in the business's voice)",
        ][$pageType] ?? '';
        if ($spec === '') return [];
        $sys = "You are a website copywriter for '{$name}', a {$industry} in {$location}. "
             . "Write authentic, {$industry}-specific copy in the business's own voice. NEVER use generic "
             . "consultancy filler like 'we help clients with your project' or 'your success is our metric' "
             . "unless this literally IS a consultancy, and NEVER leave placeholders like '\$X' or 'Feature 1'. "
             . "Return ONLY valid JSON (include the word json).";
        $prompt = "Services: {$services}.\nFor the {$pageType} page, return a JSON object with EXACTLY these keys: {$spec}.";
        try {
            $r = $this->runtime->chatJson($sys, $prompt, ['task' => 'arthur_page_copy'], 1300);
            if (($r['success'] ?? false) && is_array($r['parsed'] ?? null)) {
                // keep both scalar copy and nested arrays (tiers / faq)
                return array_filter($r['parsed'], fn($v) => (is_string($v) && trim($v) !== '') || (is_array($v) && !empty($v)));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur] pageCopy failed: ' . $e->getMessage());
        }
        return [];
    }

    /** Overlay business-context copy from pageCopy() onto the page skeleton. */
    private function enrichPageSections(array $sections, array $data, string $slug): array
    {
        $slugN = preg_replace('/[\s\-]+/', '_', strtolower(trim($slug)));
        $pageType = in_array($slugN, ['about', 'about_us'], true) ? 'about'
            : (in_array($slugN, ['services', 'contact', 'pricing', 'faq'], true) ? $slugN : '');
        if ($pageType === '') return $sections; // blog skeleton is fine
        $c = $this->pageCopy($pageType, $data);
        if (empty($c)) return $sections;

        foreach ($sections as &$sec) {
            $t = $sec['type'] ?? '';
            if ($t === 'hero') {
                if (!empty($c['hero_body']))       $sec['body']       = $c['hero_body'];
                if (!empty($c['story_body']))      $sec['body']       = $c['story_body'];
                if (!empty($c['hero_subheading'])) $sec['subheading'] = $c['hero_subheading'];
            } elseif ($t === 'features' && $pageType === 'about') {
                for ($i = 1; $i <= 3; $i++) {
                    if (!empty($c["value_{$i}_title"]) && isset($sec['items'][$i - 1])) {
                        $sec['items'][$i - 1]['title'] = $c["value_{$i}_title"];
                        if (!empty($c["value_{$i}_body"])) $sec['items'][$i - 1]['body'] = $c["value_{$i}_body"];
                    }
                }
            } elseif ($t === 'team' && !empty($c['team_heading'])) {
                $sec['heading'] = $c['team_heading'];
            } elseif ($t === 'testimonials' && !empty($c['reviews_heading'])) {
                $sec['heading'] = $c['reviews_heading'];
            } elseif ($t === 'services' && !empty($c['section_heading'])) {
                $sec['heading'] = $c['section_heading'];
            } elseif ($t === 'cta') {
                if (!empty($c['cta_heading'])) $sec['heading']  = $c['cta_heading'];
                if (!empty($c['cta_body']))    $sec['body']     = $c['cta_body'];
                if (!empty($c['cta_text']))    $sec['cta_text'] = $c['cta_text'];
            } elseif ($t === 'contact_form') {
                if (!empty($c['form_heading'])) $sec['heading'] = $c['form_heading'];
                if (!empty($c['form_body']))    $sec['body']    = $c['form_body'];
            } elseif ($t === 'pricing') {
                if (!empty($c['pricing_heading'])) $sec['heading'] = $c['pricing_heading'];
                if (!empty($c['tiers']) && is_array($c['tiers'])) {
                    $tiers = [];
                    foreach ($c['tiers'] as $ti) {
                        if (!is_array($ti) || empty($ti['name'])) continue;
                        $tiers[] = [
                            'name'     => (string) $ti['name'],
                            'price'    => (string) ($ti['price'] ?? 'Contact us'),
                            'features' => array_values(array_filter(array_map('strval', (array) ($ti['features'] ?? [])), fn($s) => trim($s) !== '')),
                        ];
                    }
                    if (count($tiers) >= 2) {
                        $tiers[1]['highlight'] = true; // middle tier highlighted, matching the skeleton
                        $sec['tiers'] = $tiers;
                    }
                }
            } elseif ($t === 'faq') {
                if (!empty($c['faq_heading'])) $sec['heading'] = $c['faq_heading'];
                if (!empty($c['faq']) && is_array($c['faq'])) {
                    $items = [];
                    foreach ($c['faq'] as $q) {
                        if (is_array($q) && !empty($q['question'])) {
                            $items[] = ['question' => (string) $q['question'], 'answer' => (string) ($q['answer'] ?? '')];
                        }
                    }
                    if ($items) $sec['items'] = $items;
                }
            }
        }
        unset($sec);
        return $sections;
    }

    private function buildRawPageSections(string $slug, array $data): array
    {
        $businessName = (string) ($data['business_name'] ?? 'Your Business');
        $industry     = (string) ($data['industry']      ?? 'business');
        $coreService  = (string) ($data['core_service']  ?? '');
        $services     = (array)  ($data['services']      ?? []);
        $location     = (string) ($data['location']      ?? '');

        $heroHeadline = $businessName;
        $heroSub      = $coreService !== ''
            ? $coreService
            : "A trusted {$industry} business" . ($location !== '' ? " in {$location}" : '');

        $featureItems = array_slice(array_filter(array_map('strval', $services)), 0, 6);

        // Normalise common slug aliases so "about-us" / "About Us" all match the same case.
        $slugN = strtolower(trim($slug));
        $slugN = preg_replace('/[\s\-]+/', '_', $slugN);

        switch ($slugN) {
            case 'blog':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => 'Latest from ' . $businessName, 'body' => 'News, updates, and stories.'],
                    ['type' => 'blog_list'],
                    ['type' => 'footer'],
                ];

            // ─── v1.4.4 (2026-05-30) — universal page templates ─────────
            case 'about':
            case 'about_us':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Our story',
                        'subheading' => $businessName,
                        'body'       => $coreService !== ''
                            ? "We help our clients with {$coreService}. Here's how we got started."
                            : "Get to know the team behind {$businessName}."],
                    ['type' => 'features',
                        'heading' => 'What we believe in',
                        'body'    => 'The principles that guide our work.',
                        'items'   => [
                            ['title' => 'Quality first', 'body' => "We never cut corners on the things that matter."],
                            ['title' => 'Client focused', 'body' => 'Your success is the metric we measure ourselves against.'],
                            ['title' => 'Always improving', 'body' => 'We learn from every engagement and bring that forward.'],
                        ]],
                    ['type' => 'team', 'heading' => 'Meet the team'],
                    ['type' => 'testimonials', 'heading' => 'What our clients say'],
                    ['type' => 'cta',
                        'heading'  => "Ready to work with {$businessName}?",
                        'body'     => "Let's talk about your project.",
                        'cta_text' => 'Get in touch',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            case 'services':
                $svcItems = $featureItems
                    ? array_map(fn($s) => ['title' => $s, 'body' => "Learn more about our {$s} offering."], $featureItems)
                    : [['title' => 'Service one', 'body' => 'Describe what this service does for the client.']];
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Our services',
                        'subheading' => "What we do for {$industry} clients",
                        'body'       => 'A focused set of services tailored to your needs.'],
                    ['type' => 'services',
                        'heading' => 'Services we offer',
                        'body'    => '',
                        'items'   => $svcItems],
                    ['type' => 'testimonials', 'heading' => 'Client success stories'],
                    ['type' => 'cta',
                        'heading'  => 'Need something specific?',
                        'body'     => 'Tell us about your project and we will tailor a solution.',
                        'cta_text' => 'Request a quote',
                        'cta_url'  => '/contact'],
                    ['type' => 'contact_form',
                        'heading'      => 'Get a quote',
                        'body'         => 'Tell us what you need.',
                        'submit_label' => 'Request quote'],
                    ['type' => 'footer'],
                ];

            case 'pricing':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Simple, transparent pricing',
                        'subheading' => 'Choose the option that fits your needs',
                        'body'       => 'No hidden fees. Cancel anytime.'],
                    ['type' => 'pricing',
                        'heading' => 'Our plans',
                        'tiers'   => [
                            ['name' => 'Starter',    'price' => '$X',     'features' => ['Feature 1', 'Feature 2', 'Feature 3']],
                            ['name' => 'Pro',        'price' => '$Y',     'features' => ['Everything in Starter', 'Feature 4', 'Feature 5'], 'highlight' => true],
                            ['name' => 'Enterprise', 'price' => 'Custom', 'features' => ['Everything in Pro', 'Custom integrations', 'Dedicated support']],
                        ]],
                    ['type' => 'faq',
                        'heading' => 'Pricing questions',
                        'items'   => [
                            ['question' => 'Can I switch plans later?',     'answer' => 'Yes — upgrade or downgrade at any time.'],
                            ['question' => 'Is there a free trial?',         'answer' => 'Get in touch and we will set you up.'],
                            ['question' => 'Do you offer custom pricing?',   'answer' => 'Yes, contact us for Enterprise needs.'],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Ready to get started?',
                        'cta_text' => 'Choose your plan',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            case 'contact':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => "Get in touch with {$businessName}",
                        'subheading' => 'We would love to hear from you',
                        'body'       => $location !== ''
                            ? "Based in {$location}. Available across the regions we serve."
                            : 'Reach out for any inquiry — we respond within one business day.'],
                    ['type' => 'contact_form',
                        'heading'      => 'Send us a message',
                        'body'         => '',
                        'submit_label' => 'Send message'],
                    ['type' => 'footer'],
                ];

            case 'faq':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Questions, answered',
                        'subheading' => "Common questions about {$businessName}",
                        'body'       => "Can't find what you're looking for? Get in touch."],
                    ['type' => 'faq',
                        'heading' => 'Frequently asked questions',
                        'items'   => [
                            ['question' => "How do I get started with {$businessName}?", 'answer' => 'Reach out via our contact form and we will follow up.'],
                            ['question' => 'What areas do you serve?',                   'answer' => $location !== '' ? "We serve {$location} and surrounding areas." : 'We work with clients across all regions.'],
                            ['question' => 'How much does it cost?',                     'answer' => 'See our Pricing page for current rates and packages.'],
                            ['question' => 'Do you offer custom solutions?',             'answer' => 'Yes — every engagement is tailored to your specific needs.'],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Still have questions?',
                        'body'     => "We're happy to answer.",
                        'cta_text' => 'Contact us',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            case 'gallery':
            case 'galleries':
            case 'photos':
            case 'photo_gallery':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => 'Gallery', 'body' => 'A look at our work.'],
                    ['type' => 'gallery', 'heading' => 'Our work'],
                    ['type' => 'cta', 'heading' => 'Like what you see?', 'body' => 'Get in touch to start your project.'],
                    ['type' => 'footer'],
                ];

            case 'team':
            case 'our_team':
            case 'staff':
            case 'people':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => 'Meet the team', 'body' => 'The people behind ' . $businessName . '.'],
                    ['type' => 'team', 'heading' => 'Our team'],
                    ['type' => 'testimonials', 'heading' => 'What our clients say'],
                    ['type' => 'cta', 'heading' => 'Work with us', 'body' => 'Get in touch to learn more.'],
                    ['type' => 'footer'],
                ];

            case 'testimonials':
            case 'reviews':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => 'What our clients say', 'body' => 'Real results from real people.'],
                    ['type' => 'testimonials', 'heading' => 'Client stories'],
                    ['type' => 'cta', 'heading' => 'Ready to join them?', 'body' => 'Get in touch today.'],
                    ['type' => 'contact_form', 'heading' => 'Contact us'],
                    ['type' => 'footer'],
                ];

            case 'legal':
            case 'privacy':
            case 'privacy_policy':
            case 'terms':
            case 'terms_of_service':
                $legalTitle = in_array($slugN, ['privacy', 'privacy_policy'], true) ? 'Privacy Policy'
                            : (in_array($slugN, ['terms', 'terms_of_service'], true) ? 'Terms of Service' : 'Legal');
                return [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => $legalTitle, 'subheading' => 'Last updated: ' . date('F j, Y')],
                    ['type' => 'generic',
                        'content' => "Placeholder for {$legalTitle}. Add the full legal text here. This template is industry-agnostic — substitute the appropriate content for your jurisdiction. Sarah can be asked to draft a starting version and Arthur will edit it once placed."],
                    ['type' => 'footer'],
                ];

            // ─── v1.4.4 Phase D-2 (2026-05-30) — booking + events ──────
            case 'book':
            case 'booking':
            case 'book_now':
            case 'appointments':
            case 'reservations':
            case 'reserve':
                $bookingServices = $featureItems
                    ?: ($coreService !== '' ? [$coreService] : []);
                $bookingHeroSub  = $coreService !== ''
                    ? "Schedule your {$coreService} with {$businessName}."
                    : "Pick a time that works for you and we'll confirm it with you.";
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Book your appointment',
                        'subheading' => $businessName,
                        'body'       => $bookingHeroSub],
                    ['type' => 'booking_form',
                        'heading'         => 'Choose a time',
                        'subheading'      => 'Quick and easy — under 60 seconds.',
                        'submit_label'    => 'Confirm booking',
                        'success_message' => 'Thanks — we will confirm your booking by email shortly.',
                        'services'        => $bookingServices,
                        'show_calendar'   => true,
                        'show_time_slots' => true,
                        'fields'          => [
                            ['name' => 'name',  'label' => 'Your name', 'type' => 'text',     'required' => true],
                            ['name' => 'email', 'label' => 'Email',     'type' => 'email',    'required' => true],
                            ['name' => 'phone', 'label' => 'Phone',     'type' => 'tel',      'required' => true],
                            ['name' => 'notes', 'label' => 'Notes (optional)', 'type' => 'textarea', 'required' => false],
                        ]],
                    ['type' => 'features',
                        'heading' => 'Why book with us',
                        'body'    => '',
                        'items'   => [
                            ['title' => 'Confirmed quickly',  'body' => 'We respond within one business day to confirm your time.'],
                            ['title' => 'Easy rescheduling',  'body' => 'Need to move the booking? Just reply to the confirmation email.'],
                            ['title' => 'No surprises',       'body' => "What you book is what you get — clear, fixed expectations."],
                        ]],
                    ['type' => 'faq',
                        'heading' => 'Booking FAQ',
                        'items'   => [
                            ['question' => 'How do I cancel or reschedule?',  'answer' => 'Reply to the confirmation email at least 24 hours before your slot.'],
                            ['question' => 'Do you offer same-day bookings?', 'answer' => 'Depending on availability — submit the form and we will let you know.'],
                            ['question' => 'Is a deposit required?',          'answer' => "Most bookings don't require a deposit. We'll tell you in the confirmation if yours does."],
                        ]],
                    ['type' => 'footer'],
                ];

            case 'event':
            case 'events':
            case 'classes':
            case 'schedule':
            case 'whats_on':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Upcoming events',
                        'subheading' => "What's on at {$businessName}",
                        'body'       => $location !== ''
                            ? "Join us in {$location} for these upcoming sessions."
                            : 'Join us for these upcoming sessions and classes.'],
                    ['type' => 'events_calendar',
                        'heading'    => 'This month',
                        'subheading' => 'Reserve your spot — limited capacity.',
                        'view'       => 'grid',
                        'events'     => [
                            [
                                'title'       => 'Event title one',
                                'date'        => 'Sat, June 14',
                                'time'        => '6:00 PM',
                                'location'    => $location !== '' ? $location : 'Venue TBA',
                                'description' => 'A short description of what this event covers and who it is for.',
                                'cta_text'    => 'RSVP',
                                'cta_url'     => '/contact',
                            ],
                            [
                                'title'       => 'Event title two',
                                'date'        => 'Wed, June 18',
                                'time'        => '7:30 PM',
                                'location'    => $location !== '' ? $location : 'Venue TBA',
                                'description' => 'A short description of what this event covers and who it is for.',
                                'cta_text'    => 'RSVP',
                                'cta_url'     => '/contact',
                            ],
                            [
                                'title'       => 'Event title three',
                                'date'        => 'Sat, June 28',
                                'time'        => '11:00 AM',
                                'location'    => $location !== '' ? $location : 'Venue TBA',
                                'description' => 'A short description of what this event covers and who it is for.',
                                'cta_text'    => 'RSVP',
                                'cta_url'     => '/contact',
                            ],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Want to be the first to hear about new events?',
                        'body'     => 'Join the mailing list and we will let you know.',
                        'cta_text' => 'Get in touch',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            // ─── v1.4.4 Phase D-3 (2026-05-30) — listings/locations ────
            case 'listings':
            case 'listing_browser':
            case 'properties':
            case 'rooms':
            case 'products':
            case 'shop':
            case 'catalogue':
            case 'catalog':
            case 'inventory':
            case 'fleet':
            case 'courses':
            case 'menu_browser':
                // Industry-flavoured copy
                $kindLabel  = match (true) {
                    in_array($industry, ['real_estate_agency', 'short_term_rental'], true) => 'properties',
                    in_array($industry, ['hotel', 'resort'], true)                          => 'rooms',
                    in_array($industry, ['ecommerce', 'retail_shop'], true)                => 'products',
                    in_array($industry, ['automotive'], true)                              => 'vehicles',
                    in_array($industry, ['online_courses', 'training_center', 'tutoring'], true) => 'courses',
                    default                                                                => 'listings',
                };
                $listHeading = 'Browse our ' . $kindLabel;
                $sampleItems = [];
                for ($i = 1; $i <= 6; $i++) {
                    $sampleItems[] = [
                        'title'    => ucfirst($kindLabel) . ' ' . $i,
                        'subtitle' => 'A short descriptor goes here',
                        'price'    => '',
                        'badge'    => $i === 1 ? 'New' : '',
                        'cta_text' => 'View details',
                        'cta_url'  => '#',
                    ];
                }
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => $listHeading,
                        'subheading' => $businessName,
                        'body'       => "Browse the latest {$kindLabel} from {$businessName}" . ($location !== '' ? " in {$location}." : '.')],
                    ['type' => 'filter_bar',
                        'heading'        => 'Filter ' . $kindLabel,
                        'target_grid_id' => 'listings-grid',
                        'search_enabled' => true,
                        'filters'        => [
                            ['label' => 'Category',  'options' => ['All']],
                            ['label' => 'Price',     'options' => ['Any', 'Low', 'Mid', 'High']],
                        ],
                        'sort_options' => ['Newest first', 'Price: low to high', 'Price: high to low']],
                    ['type' => 'grid',
                        'heading'    => '',
                        'columns'    => 3,
                        'style'      => 'card',
                        'items'      => $sampleItems],
                    ['type' => 'cta',
                        'heading'  => "Can't find what you're looking for?",
                        'body'     => 'Tell us what you need — we may have something off-market.',
                        'cta_text' => 'Get in touch',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            case 'listing_detail':
            case 'property':
            case 'product':
            case 'room':
            case 'course':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Listing title',
                        'subheading' => 'A short tagline for this listing',
                        'body'       => 'Replace with a hero summary of the listing — key specs, headline price, and the single most compelling reason to inquire.'],
                    ['type' => 'features',
                        'heading' => 'Key details',
                        'body'    => '',
                        'items'   => [
                            ['title' => 'Detail one',   'body' => 'Replace with a key spec.'],
                            ['title' => 'Detail two',   'body' => 'Replace with a key spec.'],
                            ['title' => 'Detail three', 'body' => 'Replace with a key spec.'],
                            ['title' => 'Detail four',  'body' => 'Replace with a key spec.'],
                        ]],
                    ['type' => 'gallery', 'heading' => 'Gallery'],
                    ['type' => 'trust_signals',
                        'heading' => 'Why us',
                        'style'   => 'badge_row',
                        'items'   => [
                            ['label' => 'Verified listing'],
                            ['label' => 'Fast response'],
                            ['label' => 'Best price guarantee'],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Interested?',
                        'body'     => 'Reach out and we will get back to you within one business day.',
                        'cta_text' => 'Request information',
                        'cta_url'  => '/contact'],
                    ['type' => 'contact_form',
                        'heading'      => 'Send an inquiry',
                        'body'         => '',
                        'submit_label' => 'Send inquiry'],
                    ['type' => 'related_listings',
                        'heading' => 'You may also like',
                        'items'   => [
                            ['title' => 'Related 1', 'subtitle' => 'Short descriptor', 'cta_url' => '#'],
                            ['title' => 'Related 2', 'subtitle' => 'Short descriptor', 'cta_url' => '#'],
                            ['title' => 'Related 3', 'subtitle' => 'Short descriptor', 'cta_url' => '#'],
                        ]],
                    ['type' => 'footer'],
                ];

            case 'locations':
            case 'location':
            case 'branches':
            case 'find_us':
            case 'store_finder':
            case 'stores':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Visit us',
                        'subheading' => $businessName,
                        'body'       => $location !== ''
                            ? "Find {$businessName} in {$location} — directions, hours, and contact below."
                            : 'Directions, hours, and contact details for our locations.'],
                    ['type' => 'map',
                        'heading'   => 'Our locations',
                        'locations' => [
                            ['name' => $location !== '' ? $location : 'Main branch', 'address' => 'Replace with full address', 'phone' => '', 'hours' => 'Mon–Fri 9:00–18:00'],
                        ]],
                    ['type' => 'trust_signals',
                        'heading' => 'Why visit',
                        'style'   => 'badge_row',
                        'items'   => [
                            ['label' => 'Easy parking'],
                            ['label' => 'Friendly staff'],
                            ['label' => 'Walk-ins welcome'],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Have a question before you visit?',
                        'cta_text' => 'Get in touch',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            // ─── v1.4.4 Phase D-4 (2026-05-30) — visual portfolios ─────
            case 'before_after':
            case 'before_and_after':
            case 'transformations':
            case 'results':
            case 'case_studies':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Results that speak for themselves',
                        'subheading' => $businessName,
                        'body'       => 'Browse a selection of recent transformations and case studies.'],
                    ['type' => 'gallery',
                        'heading' => 'Before and after',
                        'body'    => 'Tap any image to see the full transformation.',
                        'columns' => 3,
                        'style'   => 'card'],
                    ['type' => 'testimonials',
                        'heading' => 'What clients say',
                        'items'   => [
                            ['quote' => 'Replace with a real quote from a happy client.',  'author' => 'Client name', 'role' => 'Treatment / project'],
                            ['quote' => 'Replace with a real quote from a happy client.',  'author' => 'Client name', 'role' => 'Treatment / project'],
                        ]],
                    ['type' => 'stats',
                        'heading' => 'By the numbers',
                        'items'   => [
                            ['label' => 'Projects completed', 'value' => '500+'],
                            ['label' => 'Client satisfaction', 'value' => '98%'],
                            ['label' => 'Years of experience', 'value' => '10+'],
                        ]],
                    ['type' => 'cta',
                        'heading'  => 'Want results like these?',
                        'body'     => 'Tell us about your goals and we will design a tailored plan.',
                        'cta_text' => 'Book a consultation',
                        'cta_url'  => '/booking'],
                    ['type' => 'footer'],
                ];

            case 'menu':
            case 'food_menu':
            case 'dishes':
            case 'drinks':
            case 'wine_list':
                $menuItems = $featureItems
                    ? array_map(fn($s) => ['title' => $s, 'subtitle' => 'Description goes here', 'price' => 'AED 00'], $featureItems)
                    : [
                        ['title' => 'Signature dish 1', 'subtitle' => 'Short description of ingredients', 'price' => 'AED 00'],
                        ['title' => 'Signature dish 2', 'subtitle' => 'Short description of ingredients', 'price' => 'AED 00'],
                        ['title' => 'Signature dish 3', 'subtitle' => 'Short description of ingredients', 'price' => 'AED 00'],
                        ['title' => 'Signature dish 4', 'subtitle' => 'Short description of ingredients', 'price' => 'AED 00'],
                    ];
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Our menu',
                        'subheading' => $businessName,
                        'body'       => $location !== ''
                            ? "Crafted with care in {$location}. Updated seasonally."
                            : 'Crafted with care. Updated seasonally.'],
                    ['type' => 'filter_bar',
                        'heading'        => 'Browse by section',
                        'target_grid_id' => 'menu-grid',
                        'search_enabled' => false,
                        'filters'        => [
                            ['label' => 'Section', 'options' => ['All', 'Starters', 'Mains', 'Desserts', 'Drinks']],
                            ['label' => 'Dietary', 'options' => ['Any', 'Vegetarian', 'Vegan', 'Gluten-free']],
                        ]],
                    ['type' => 'grid',
                        'heading'    => 'On the menu',
                        'columns'    => 2,
                        'style'      => 'compact',
                        'items'      => $menuItems],
                    ['type' => 'cta',
                        'heading'  => 'Reserve a table',
                        'body'     => 'Walk-ins welcome — bookings recommended on weekends.',
                        'cta_text' => 'Book a table',
                        'cta_url'  => '/booking'],
                    ['type' => 'footer'],
                ];

            case 'portfolio':
            case 'work':
            case 'projects':
            case 'gallery_page':
            case 'showcase':
                return [
                    ['type' => 'header'],
                    ['type' => 'hero',
                        'heading'    => 'Selected work',
                        'subheading' => $businessName,
                        'body'       => $coreService !== ''
                            ? "A selection of recent {$coreService} projects."
                            : 'A selection of recent projects.'],
                    ['type' => 'filter_bar',
                        'heading'        => 'Filter by',
                        'target_grid_id' => 'portfolio-grid',
                        'search_enabled' => true,
                        'filters'        => [
                            ['label' => 'Category', 'options' => ['All']],
                            ['label' => 'Year',     'options' => ['All', '2026', '2025', '2024']],
                        ]],
                    ['type' => 'grid',
                        'heading' => '',
                        'columns' => 3,
                        'style'   => 'media',
                        'items'   => [
                            ['title' => 'Project one',   'subtitle' => 'Category · 2026', 'badge' => 'Featured'],
                            ['title' => 'Project two',   'subtitle' => 'Category · 2026'],
                            ['title' => 'Project three', 'subtitle' => 'Category · 2025'],
                            ['title' => 'Project four',  'subtitle' => 'Category · 2025'],
                            ['title' => 'Project five',  'subtitle' => 'Category · 2024'],
                            ['title' => 'Project six',   'subtitle' => 'Category · 2024'],
                        ]],
                    ['type' => 'testimonials', 'heading' => 'Client feedback'],
                    ['type' => 'cta',
                        'heading'  => "Have a project in mind?",
                        'body'     => "Let's talk about what you want to build.",
                        'cta_text' => 'Start a project',
                        'cta_url'  => '/contact'],
                    ['type' => 'footer'],
                ];

            // ─── v1.4.4 Phase D-5 (2026-05-30) — commerce + account ────
            case 'cart':
            case 'basket':
            case 'shopping_cart':
                return [
                    ['type' => 'header'],
                    ['type' => 'cart_summary',
                        'heading' => 'Your cart',
                        'items'   => [
                            ['name' => 'Sample item 1', 'qty' => 1, 'price' => '120', 'subtotal' => '120'],
                            ['name' => 'Sample item 2', 'qty' => 2, 'price' => '60',  'subtotal' => '120'],
                        ],
                        'currency' => 'AED',
                        'subtotal' => '240',
                        'tax'      => '12',
                        'shipping' => '25',
                        'total'    => '277',
                        'cta_text' => 'Proceed to checkout',
                        'cta_url'  => '/checkout',
                        'continue_shopping_url' => '/shop'],
                    ['type' => 'trust_signals',
                        'heading' => 'Shop with confidence',
                        'style'   => 'badge_row',
                        'items'   => [
                            ['label' => 'Secure checkout'],
                            ['label' => 'Easy returns'],
                            ['label' => 'Fast delivery'],
                        ]],
                    ['type' => 'footer'],
                ];

            case 'checkout':
            case 'checkout_page':
                return [
                    ['type' => 'header'],
                    ['type' => 'checkout_form',
                        'heading'    => 'Checkout',
                        'subheading' => "You're moments away — fill in the details below.",
                        'submit_label' => 'Place order',
                        'payment_methods' => ['Visa', 'Mastercard', 'Apple Pay', 'Cash on delivery'],
                        'order_summary' => [
                            'items' => [
                                ['name' => 'Sample item 1', 'price' => 'AED 120'],
                                ['name' => 'Sample item 2 × 2', 'price' => 'AED 120'],
                                ['name' => 'Shipping',     'price' => 'AED 25'],
                                ['name' => 'Tax (VAT)',    'price' => 'AED 12'],
                            ],
                            'total' => 'AED 277',
                        ]],
                    ['type' => 'trust_signals',
                        'heading' => 'Secure transaction',
                        'style'   => 'badge_row',
                        'items'   => [
                            ['label' => 'SSL encrypted'],
                            ['label' => 'PCI compliant'],
                            ['label' => 'No card stored'],
                        ]],
                    ['type' => 'footer'],
                ];

            case 'account':
            case 'my_account':
            case 'dashboard':
            case 'profile_page':
                return [
                    ['type' => 'header'],
                    ['type' => 'account_nav',
                        'heading'    => 'Welcome back',
                        'subheading' => 'Manage your orders, addresses, and profile.',
                        'orientation' => 'top',
                        'items' => [
                            ['label' => 'Orders',    'url' => '/account/orders',    'active' => true],
                            ['label' => 'Addresses', 'url' => '/account/addresses'],
                            ['label' => 'Profile',   'url' => '/account/profile'],
                            ['label' => 'Wishlist',  'url' => '/account/wishlist'],
                        ]],
                    ['type' => 'account_panel',
                        'heading'    => 'Recent orders',
                        'panel_type' => 'orders',
                        'items'      => [
                            ['ref' => '#1042', 'date' => 'May 28, 2026', 'status' => 'Delivered',  'total' => 'AED 277', 'url' => '/account/orders/1042'],
                            ['ref' => '#1038', 'date' => 'May 14, 2026', 'status' => 'Processing', 'total' => 'AED 150', 'url' => '/account/orders/1038'],
                        ],
                        'empty_message' => "You haven't placed any orders yet.",
                        'cta_text' => 'Start shopping',
                        'cta_url'  => '/shop'],
                    ['type' => 'footer'],
                ];

            case 'home':
            default:
                $sections = [
                    ['type' => 'header'],
                    ['type' => 'hero', 'heading' => $heroHeadline, 'body' => $heroSub],
                ];

                if (! empty($featureItems)) {
                    $sections[] = [
                        'type'    => 'features',
                        'heading' => 'What we do',
                        'body'    => 'Services we provide:',
                        'items'   => array_map(fn($s) => ['title' => $s], $featureItems),
                    ];
                }

                $sections[] = [
                    'type'    => 'cta',
                    'heading' => "Ready to work with {$businessName}?",
                    'body'    => 'Get in touch and we\'ll be in contact shortly.',
                ];
                $sections[] = ['type' => 'contact_form', 'heading' => 'Contact us'];
                $sections[] = ['type' => 'footer'];

                return $sections;
        }
    }
}
