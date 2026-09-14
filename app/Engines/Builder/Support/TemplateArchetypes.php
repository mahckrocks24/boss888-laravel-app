<?php

namespace App\Engines\Builder\Support;

/**
 * TemplateArchetypes — the section-fit / use-case brain for the website builder.
 *
 * Templates are grouped into BUSINESS ARCHETYPES (how a business operates), each
 * with a section policy: which blocks are cross-archetype WRONG (always removed)
 * and which are CREDIBILITY blocks that require real track-record data (removed
 * for a brand-new auto-build, re-enabled once the owner adds real content).
 *
 * This replaces industry-keyword-only routing: an unlisted service business no
 * longer falls onto the enterprise-consulting layout, and no template renders a
 * section that does not belong to the business (e.g. a "doctors" block on a
 * restaurant — an artifact of 12 templates that were cloned from the medical one).
 *
 * Added 2026-07-24 (P1/P2 of the builder template-integrity architecture).
 */
final class TemplateArchetypes
{
    /** Every wired website template → its business archetype. */
    private const TEMPLATE_ARCHETYPE = [
        // medical (doctors + certifications are legitimate here)
        'dental' => 'medical', 'medical_clinic' => 'medical',
        'aesthetic_clinic' => 'medical', 'pet_services' => 'medical',
        // appointment service (books time, shows team + portfolio, no case studies)
        'gym' => 'appointment_service', 'beauty_salon' => 'appointment_service',
        'barbershop' => 'appointment_service',
        // professional advisory (case studies + clients — only when established)
        'consulting' => 'professional_advisory', 'marketing_agency' => 'professional_advisory',
        'it_services' => 'professional_advisory', 'real_estate_agency' => 'real_estate', 'estate_frontage' => 'real_estate',
        // 2026-09-14 (Owner brief): personal-brand designs — a consultant's profile, a realtor's portfolio with listings
        'consultant_profile' => 'professional_advisory', 'realtor_profile' => 'real_estate',
        // portfolio / project
        'architecture' => 'portfolio_project', 'interior_design' => 'portfolio_project',
        'construction' => 'portfolio_project',
        // product / retail (no booking, no case studies, no doctors)
        'retail_shop' => 'product_retail', 'ecommerce' => 'product_retail',
        // hospitality / stay (reservation, rooms/menu, experiences)
        'hotel' => 'hospitality_stay', 'resort' => 'hospitality_stay',
        'short_term_rental' => 'hospitality_stay', 'restaurant' => 'hospitality_stay',
        'cafe' => 'hospitality_stay', 'catering' => 'hospitality_stay',
        'travel_agency' => 'hospitality_stay', 'event_venue' => 'hospitality_stay',
        // education
        'tutoring' => 'education', 'training_center' => 'education',
        'online_courses' => 'education', 'childcare' => 'education',
        // content / editorial
        'news_channel' => 'content_editorial',
        // local service (licensed trades — certifications legit, no case studies)
        'home_services' => 'local_service', 'automotive' => 'local_service',
    ];

    /**
     * Per-archetype section policy.
     *  forbidden = blocks that NEVER belong to this archetype (always removed).
     *  maturity  = credibility blocks that require REAL data (removed on a fresh
     *              auto-build; the owner re-enables them in the builder later).
     *  fallback  = best template when the exact industry has no dedicated one.
     */
    private const POLICY = [
        'medical' => [
            'forbidden' => ['case_studies', 'clients', 'methodology', 'inventory', 'financing', 'room_types', 'listings', 'menu_highlights', 'specials', 'membership'],
            'maturity'  => ['stats', 'stats_strip', 'awards', 'press', 'social_proof', 'transformations', 'partners', 'brands', 'certifications'],
            'fallback'  => 'medical_clinic',
        ],
        'appointment_service' => [
            'forbidden' => ['doctors', 'case_studies', 'clients', 'methodology', 'inventory', 'financing', 'room_types', 'listings', 'certifications'],
            'maturity'  => ['stats', 'stats_strip', 'awards', 'press', 'social_proof', 'transformations', 'partners', 'brands'],
            'fallback'  => 'beauty_salon',
        ],
        // EV-1000 (2026-09-12): real estate is an INVENTORY business — listings/areas are what it sells, never a
        // credibility block to be held back until the business is 'established'.
        'real_estate' => [
            'forbidden' => ['doctors', 'menu_highlights', 'specials', 'room_types', 'inventory', 'financing', 'transformations', 'membership', 'breeds_served'],
            'maturity'  => ['stats', 'stats_strip', 'case_studies', 'clients', 'results', 'awards', 'press', 'social_proof', 'partners'],
            'fallback'  => 'real_estate_agency',
        ],
        'professional_advisory' => [
            'forbidden' => ['doctors', 'menu_highlights', 'specials', 'room_types', 'inventory', 'financing', 'transformations', 'membership', 'breeds_served'],
            'maturity'  => ['stats', 'stats_strip', 'case_studies', 'clients', 'results', 'awards', 'press', 'social_proof', 'listings', 'partners'],
            'fallback'  => 'consulting',
        ],
        'portfolio_project' => [
            'forbidden' => ['doctors', 'menu_highlights', 'specials', 'room_types', 'inventory', 'membership', 'financing', 'breeds_served'],
            'maturity'  => ['stats', 'stats_strip', 'awards', 'clients', 'press', 'social_proof', 'partners', 'certifications'],
            'fallback'  => 'architecture',
        ],
        'product_retail' => [
            'forbidden' => ['doctors', 'case_studies', 'clients', 'methodology', 'certifications', 'room_types', 'booking', 'listings', 'membership', 'financing'],
            'maturity'  => ['stats', 'stats_strip', 'awards', 'press', 'social_proof', 'brands', 'partners'],
            'fallback'  => 'retail_shop',
        ],
        'hospitality_stay' => [
            'forbidden' => ['doctors', 'case_studies', 'clients', 'methodology', 'certifications', 'inventory', 'financing', 'listings', 'membership'],
            'maturity'  => ['stats', 'stats_strip', 'awards', 'press', 'social_proof', 'partners'],
            'fallback'  => 'hotel',
        ],
        'education' => [
            'forbidden' => ['doctors', 'menu_highlights', 'specials', 'room_types', 'inventory', 'case_studies', 'financing', 'membership'],
            'maturity'  => ['stats', 'stats_strip', 'awards', 'partners', 'press', 'social_proof', 'certifications'],
            'fallback'  => 'training_center',
        ],
        'content_editorial' => [
            'forbidden' => ['services', 'booking', 'doctors', 'pricing', 'case_studies', 'clients', 'certifications'],
            'maturity'  => ['stats', 'stats_strip'],
            'fallback'  => 'news_channel',
        ],
        'local_service' => [
            'forbidden' => ['doctors', 'menu_highlights', 'specials', 'room_types', 'case_studies', 'clients', 'methodology', 'listings', 'membership'],
            'maturity'  => ['stats', 'stats_strip', 'awards', 'press', 'social_proof', 'transformations', 'partners', 'brands', 'featured_vehicles', 'certifications'],
            'fallback'  => 'home_services',
        ],
    ];

    /**
     * Keyword → archetype for classifying an UNLISTED business from its stated
     * industry (+ name). Longest-list-first is not needed; first hit wins per
     * archetype scan order (medical before appointment so "aesthetic clinic"
     * lands medical, "tattoo" lands appointment).
     */
    private const ARCHETYPE_KEYWORDS = [
        'medical'               => ['clinic', 'dental', 'dentist', 'doctor', 'medical', 'physio', 'dermatolog', 'orthodon', 'optical', 'optometr', 'vet', 'veterinar', 'surgery', 'surgeon', 'hospital', 'pediatric', 'chiropract', 'fertility', 'ivf', 'psychiatr', 'pharmacy'],
        'appointment_service'   => ['tattoo', 'piercing', 'salon', 'barber', 'spa', 'nail', 'lash', 'brow', 'massage', 'wax', 'grooming', 'beauty', 'makeup', 'hair', 'gym', 'fitness', 'yoga', 'pilates', 'crossfit', 'trainer', 'wellness', 'therapy', 'therapist', 'acupunctur', 'aesthetic'],
        'education'             => ['tutor', 'school', 'academy', 'course', 'training', 'learn', 'coaching', 'nursery', 'daycare', 'preschool', 'kindergarten', 'montessori', 'education', 'e-learning', 'university', 'college', 'institute', 'lessons', 'driving school'],
        'hospitality_stay'      => ['hotel', 'resort', 'rental', 'airbnb', 'restaurant', 'cafe', 'coffee', 'bakery', 'catering', 'caterer', 'bar ', 'bistro', 'diner', 'grill', 'kitchen', 'travel', 'tour', 'venue', 'wedding', 'banquet', 'lodge', 'inn', 'hospitality', 'dining', 'cuisine', 'patisserie'],
        'product_retail'        => ['shop', 'store', 'boutique', 'retail', 'ecommerce', 'e-commerce', 'fashion', 'clothing', 'apparel', 'jewelry', 'jeweller', 'furniture', 'florist', 'flower', 'bookstore', 'grocery', 'pharmacy shop', 'shopify', 'merch', 'goods'],
        'portfolio_project'     => ['architect', 'interior', 'construction', 'contractor', 'builder', 'renovat', 'landscap', 'photograph', 'videograph', 'design studio', 'fit-out', 'fitout', 'joinery', 'carpentry', 'engineering firm', 'surveyor'],
        'local_service'         => ['plumb', 'electric', 'hvac', 'cleaning', 'handyman', 'repair', 'auto ', 'automotive', 'mechanic', 'pest', 'moving', 'movers', 'locksmith', 'garage', 'detailing', 'maintenance', 'installation', 'roofing', 'painting', 'pool service', 'shelving', 'funeral', 'memorial', 'cremation', 'mortuary', 'crematorium', 'landscaping', 'gardening', 'security service', 'catering equipment'],
        'content_editorial'     => ['news', 'magazine', 'media outlet', 'blog', 'publication', 'journal', 'press', 'broadcast', 'podcast', 'newspaper', 'gazette', 'tribune'],
        'real_estate'           => ['real estate', 'realty', 'realtor', 'estate agent', 'estate agency', 'property', 'properties', 'lettings', 'leasing', 'brokerage'],
        'professional_advisory' => ['consult', 'advisory', 'agency', 'marketing', 'seo', 'advertis', 'branding', 'legal', 'law firm', 'lawyer', 'attorney', 'account', 'bookkeep', 'finance', 'financial', 'insurance', 'hr ', 'recruit', 'it services', 'software', 'saas', 'tech', 'real estate', 'realtor', 'broker', 'property', 'notary', 'audit', 'tax'],
    ];

    /**
     * P2b — industry-defining section labels. The re-sectioned clone templates
     * carry a generic "services" block; for their real industry that block IS the
     * menu / catalog / programs / destinations. Relabel its heading + intro so it
     * reads correctly (content is already industry-correct via generateContent).
     * Keyed by template slug; empty = keep the template's own labels.
     */
    private const SECTION_LABELS = [
        'restaurant'        => ['services_eyebrow' => 'What We Serve',     'services_title' => 'Our Menu',                'services_intro' => 'A taste of what we serve — crafted fresh, every day.'],
        'catering'          => ['services_eyebrow' => 'Catering Menus',    'services_title' => 'Menus & Packages',        'services_intro' => 'Curated menus and packages for every occasion.'],
        'retail_shop'       => ['services_eyebrow' => 'Our Collection',    'services_title' => 'Shop by Category',        'services_intro' => 'Browse our latest pieces and everyday essentials.'],
        'ecommerce'         => ['services_eyebrow' => 'Our Collection',    'services_title' => 'Shop the Collection',     'services_intro' => 'Discover our range — delivered to your door.'],
        'travel_agency'     => ['services_eyebrow' => 'Where We Take You',  'services_title' => 'Destinations & Packages', 'services_intro' => 'Handcrafted itineraries for every kind of traveller.'],
        'online_courses'    => ['services_eyebrow' => 'What You Will Learn', 'services_title' => 'Our Courses',            'services_intro' => 'Learn at your own pace with expert-led courses.'],
        'tutoring'          => ['services_eyebrow' => 'What We Teach',      'services_title' => 'Subjects & Programs',      'services_intro' => 'Personalised tutoring across the subjects that matter.'],
        'resort'            => ['services_eyebrow' => 'Your Stay',          'services_title' => 'Experiences & Amenities', 'services_intro' => 'Everything you need for an unforgettable escape.'],
        'short_term_rental' => ['services_eyebrow' => 'Your Stay',          'services_title' => 'Our Spaces',              'services_intro' => 'Thoughtfully designed spaces for a home-away-from-home.'],
    ];

    public static function sectionLabels(string $slug): array
    {
        return self::SECTION_LABELS[$slug] ?? [];
    }

    public static function archetypeOf(string $slug): string
    {
        return self::TEMPLATE_ARCHETYPE[$slug] ?? 'professional_advisory';
    }

    /** Classify an unlisted business from raw industry + name; null if unknown. */
    public static function archetypeForBusiness(string $rawIndustry, string $name = ''): ?string
    {
        $hay = ' ' . mb_strtolower(trim($rawIndustry . ' ' . $name)) . ' ';
        if (trim($hay) === '') return null;
        foreach (self::ARCHETYPE_KEYWORDS as $arch => $kws) {
            foreach ($kws as $kw) {
                if (mb_strpos($hay, $kw) !== false) return $arch;
            }
        }
        return null;
    }

    public static function fallbackTemplate(string $archetype): ?string
    {
        return self::POLICY[$archetype]['fallback'] ?? null;
    }

    /**
     * P3 — maturity gate. Credibility blocks (stats/case_studies/clients/awards)
     * only belong on a site backed by REAL track record. A fresh auto-build is
     * NOT established (conservative — no fabrication). This flips true only on an
     * explicit flag or when the caller supplies real credibility DATA to fill the
     * blocks truthfully (the builder UI's path when a user enters real numbers) —
     * never from vague "10 years" text, which cannot honestly populate a block.
     */
    public static function looksEstablished(array $data): bool
    {
        if (filter_var($data['established'] ?? false, FILTER_VALIDATE_BOOLEAN)) return true;
        foreach (['stats', 'client_logos', 'clients', 'case_studies'] as $k) {
            if (!empty($data[$k]) && is_array($data[$k])) return true;
        }
        return false;
    }

    /**
     * Block ids to strip from a build of $slug. $established=false (default) is a
     * fresh auto-build with no real track record → credibility blocks removed too.
     */
    public static function blocksToRemove(string $slug, bool $established = false): array
    {
        $arch = self::archetypeOf($slug);
        $pol  = self::POLICY[$arch] ?? [];
        $remove = $pol['forbidden'] ?? [];
        if (!$established) {
            $remove = array_merge($remove, $pol['maturity'] ?? []);
        }
        return array_values(array_unique($remove));
    }

    /**
     * Remove whole <section data-block="X"> … </section> blocks from rendered
     * HTML. Blocks are top-level siblings delimited by data-block anchors, so we
     * cut from each target anchor to the next anchor — robust to inner nesting.
     */
    public static function removeBlocks(string $html, array $blockIds): string
    {
        if (empty($blockIds) || $html === '') return $html;
        $remove = array_flip(array_map('strtolower', $blockIds));
        if (!preg_match_all('/<(?:section|div|header|footer|aside|nav)\b[^>]*\bdata-block="([^"]+)"/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $n = count($m[0]);
        $out = substr($html, 0, $m[0][0][1]); // preamble (doctype/head/open body/nav-in-head)
        for ($i = 0; $i < $n; $i++) {
            $from = $m[0][$i][1];
            $to   = ($i + 1 < $n) ? $m[0][$i + 1][1] : strlen($html);
            $id   = strtolower($m[1][$i][0]);
            if (!isset($remove[$id])) {
                $out .= substr($html, $from, $to - $from);
            }
        }
        return $out;
    }
}
