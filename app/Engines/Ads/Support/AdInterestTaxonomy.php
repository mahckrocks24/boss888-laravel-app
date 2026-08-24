<?php

namespace App\Engines\Ads\Support;

/**
 * AdInterestTaxonomy — the controlled CONTEXTUAL interest vocabulary.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * THE BOUNDARY THIS CLASS EXISTS TO PROTECT
 *
 * An interest here describes THE SITE, never THE VISITOR.
 *
 * "This site is about dental care" is a contextual attribute of inventory.
 * "This reader is interested in dental care" is a behavioural profile.
 *
 * The published Free-plan advertising terms (clause 9) state that we do not
 * use cookies, cross-site identifiers or behavioural profiling to select
 * advertisements. The moment an interest is derived from observing a person
 * rather than from reading a page, that clause becomes false, consent
 * management becomes mandatory across every tenant site, and the cookieless
 * position — a genuine commercial differentiator — is gone.
 *
 * Every derivation source in InterestDeriver is therefore a property of the
 * published site: its keywords, its services, its copy, its articles.
 * If a future change needs visitor signals, it needs a legal review first,
 * not a code review.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * @see \App\Engines\Ads\Services\InterestDeriver
 */
final class AdInterestTaxonomy
{
    /**
     * The controlled vocabulary: code → label.
     *
     * Free tags are unsellable for the same reason free-text industry is —
     * an advertiser cannot buy "wellness-ish". Codes are stable and never
     * renamed once a campaign has targeted them.
     */
    public const INTERESTS = [
        // Health & personal care
        'health_wellness'      => 'Health & Wellness',
        'dental_care'          => 'Dental Care',
        'aesthetic_treatments' => 'Aesthetic Treatments',
        'beauty_grooming'      => 'Beauty & Grooming',
        'fitness_training'     => 'Fitness & Training',
        'nutrition_diet'       => 'Nutrition & Diet',
        'veterinary_care'      => 'Veterinary & Pet Care',

        // Food & hospitality
        'food_dining'          => 'Food & Dining',
        'coffee_cafe'          => 'Coffee & Café',
        'catering_events'      => 'Catering & Events',

        // Travel & stays
        'travel_tourism'       => 'Travel & Tourism',
        'hotels_stays'         => 'Hotels & Stays',
        'vacation_rentals'     => 'Vacation Rentals',

        // Home & property
        'home_improvement'     => 'Home Improvement',
        'interior_decor'       => 'Interior & Décor',
        'architecture_design'  => 'Architecture & Design',
        'construction_trades'  => 'Construction & Trades',
        'cleaning_services'    => 'Cleaning Services',
        'real_estate'          => 'Real Estate',
        'property_rental'      => 'Property Rental',

        // Vehicles
        'automotive_care'      => 'Automotive Care',

        // Business & professional
        'business_consulting'  => 'Business Consulting',
        'marketing_advertising'=> 'Marketing & Advertising',
        'seo_search'           => 'SEO & Search',
        'it_software'          => 'IT & Software',
        'legal_services'       => 'Legal Services',
        'financial_services'   => 'Financial Services',
        'insurance'            => 'Insurance',
        'recruitment_hr'       => 'Recruitment & HR',

        // Education
        'education_learning'   => 'Education & Learning',
        'online_courses'       => 'Online Courses',
        'tutoring_academic'    => 'Tutoring & Academic Support',
        'childcare_early_years'=> 'Childcare & Early Years',
        'vocational_training'  => 'Vocational Training',

        // Retail
        'retail_shopping'      => 'Retail & Shopping',
        'fashion_apparel'      => 'Fashion & Apparel',
        'jewellery_accessories'=> 'Jewellery & Accessories',
        'ecommerce_online'     => 'Online Shopping',

        // Media & events
        'news_current_affairs' => 'News & Current Affairs',
        'media_publishing'     => 'Media & Publishing',
        'events_weddings'      => 'Events & Weddings',
    ];

    /**
     * Baseline interests implied by an industry.
     *
     * These are the floor, not the ceiling: a site always carries its industry's
     * default interests, and InterestDeriver adds more from the site's own
     * keywords and copy. A restaurant that blogs about athlete nutrition should
     * end up with both food_dining AND nutrition_diet.
     */
    public const INDUSTRY_DEFAULTS = [
        'aesthetic_clinic'   => ['aesthetic_treatments', 'beauty_grooming', 'health_wellness'],
        'architecture'       => ['architecture_design', 'interior_decor'],
        'automotive'         => ['automotive_care'],
        'barbershop'         => ['beauty_grooming'],
        'beauty_salon'       => ['beauty_grooming', 'health_wellness'],
        'cafe'               => ['coffee_cafe', 'food_dining'],
        'catering'           => ['catering_events', 'food_dining'],
        'childcare'          => ['childcare_early_years', 'education_learning'],
        'construction'       => ['construction_trades', 'home_improvement'],
        'consulting'         => ['business_consulting'],
        'dental'             => ['dental_care', 'health_wellness'],
        'ecommerce'          => ['ecommerce_online', 'retail_shopping'],
        'event_venue'        => ['events_weddings', 'catering_events'],
        'gym'                => ['fitness_training', 'health_wellness'],
        'home_services'      => ['home_improvement', 'cleaning_services'],
        'hotel'              => ['hotels_stays', 'travel_tourism'],
        'interior_design'    => ['interior_decor', 'home_improvement'],
        'it_services'        => ['it_software'],
        'marketing_agency'   => ['marketing_advertising', 'seo_search'],
        'medical_clinic'     => ['health_wellness'],
        'news_channel'       => ['news_current_affairs', 'media_publishing'],
        'online_courses'     => ['online_courses', 'education_learning'],
        'pet_services'       => ['veterinary_care'],
        'real_estate_agency' => ['real_estate', 'property_rental'],
        'resort'             => ['hotels_stays', 'travel_tourism'],
        'restaurant'         => ['food_dining'],
        'retail_shop'        => ['retail_shopping'],
        'short_term_rental'  => ['vacation_rentals', 'travel_tourism'],
        'training_center'    => ['vocational_training', 'education_learning'],
        'travel_agency'      => ['travel_tourism'],
        'tutoring'           => ['tutoring_academic', 'education_learning'],
    ];

    /**
     * interest code → keywords that evidence it in site content.
     *
     * Matched word-boundary against SEO keywords, stated services, template
     * copy and article titles — all properties of the published site.
     */
    private const INTEREST_KEYWORDS = [
        'health_wellness'       => ['health', 'wellness', 'wellbeing', 'clinic', 'medical', 'therapy', 'treatment', 'recovery'],
        'dental_care'           => ['dental', 'dentist', 'teeth', 'tooth', 'orthodontic', 'implant', 'whitening', 'invisalign'],
        'aesthetic_treatments'  => ['aesthetic', 'botox', 'filler', 'laser', 'dermal', 'rejuvenation', 'skincare', 'anti-ageing', 'anti-aging'],
        'beauty_grooming'       => ['beauty', 'salon', 'hair', 'nails', 'lashes', 'brows', 'makeup', 'barber', 'grooming', 'manicure', 'pedicure'],
        'fitness_training'      => ['fitness', 'gym', 'workout', 'training', 'strength', 'yoga', 'pilates', 'crossfit', 'exercise'],
        'nutrition_diet'        => ['nutrition', 'diet', 'nutritionist', 'meal plan', 'macros', 'calories', 'eating', 'dietitian'],
        'veterinary_care'       => ['veterinary', 'vet', 'pet', 'dog', 'cat', 'grooming', 'kennel', 'puppy'],

        'food_dining'           => ['restaurant', 'menu', 'dining', 'chef', 'cuisine', 'dish', 'food', 'bistro', 'tasting'],
        'coffee_cafe'           => ['coffee', 'cafe', 'café', 'espresso', 'barista', 'bakery', 'pastry', 'brunch'],
        'catering_events'       => ['catering', 'caterer', 'buffet', 'banquet', 'canape', 'event catering'],

        'travel_tourism'        => ['travel', 'tour', 'tours', 'itinerary', 'destination', 'excursion', 'holiday', 'sightseeing', 'visa'],
        'hotels_stays'          => ['hotel', 'resort', 'suite', 'room', 'booking', 'stay', 'accommodation', 'check-in'],
        'vacation_rentals'      => ['airbnb', 'rental', 'villa', 'apartment', 'holiday let', 'short stay'],

        'home_improvement'      => ['renovation', 'remodel', 'repair', 'plumbing', 'electrical', 'handyman', 'roofing', 'painting', 'maintenance'],
        'interior_decor'        => ['interior', 'decor', 'furnishing', 'styling', 'furniture', 'fit-out'],
        'architecture_design'   => ['architecture', 'architect', 'blueprint', 'planning permission', 'structural'],
        'construction_trades'   => ['construction', 'contractor', 'builder', 'joinery', 'carpentry', 'scaffolding', 'concrete'],
        'cleaning_services'     => ['cleaning', 'cleaner', 'janitorial', 'housekeeping', 'deep clean'],
        'real_estate'           => ['real estate', 'property', 'listing', 'realtor', 'mortgage', 'valuation', 'freehold'],
        'property_rental'       => ['rent', 'rental', 'tenant', 'lease', 'letting', 'landlord'],

        'automotive_care'       => ['car', 'vehicle', 'automotive', 'mechanic', 'servicing', 'tyre', 'tire', 'detailing', 'mot'],

        'business_consulting'   => ['consulting', 'consultancy', 'strategy', 'advisory', 'business plan', 'operations', 'transformation'],
        'marketing_advertising' => ['marketing', 'advertising', 'campaign', 'branding', 'social media', 'content strategy'],
        'seo_search'            => ['seo', 'search engine', 'keyword', 'ranking', 'serp', 'backlink', 'organic traffic'],
        'it_software'           => ['software', 'saas', 'cloud', 'development', 'api', 'cybersecurity', 'infrastructure', 'automation'],
        'legal_services'        => ['legal', 'law', 'solicitor', 'attorney', 'litigation', 'contract', 'compliance'],
        'financial_services'    => ['accounting', 'bookkeeping', 'tax', 'audit', 'payroll', 'invoice', 'financial'],
        'insurance'             => ['insurance', 'policy', 'premium', 'coverage', 'claim'],
        'recruitment_hr'        => ['recruitment', 'hiring', 'staffing', 'talent', 'payroll', 'onboarding'],

        'education_learning'    => ['education', 'learning', 'curriculum', 'student', 'teaching', 'school'],
        'online_courses'        => ['online course', 'e-learning', 'elearning', 'module', 'certification', 'webinar'],
        'tutoring_academic'     => ['tutoring', 'tutor', 'tuition', 'exam', 'revision', 'gcse', 'homework'],
        'childcare_early_years' => ['childcare', 'nursery', 'daycare', 'preschool', 'toddler', 'early years', 'montessori'],
        'vocational_training'   => ['vocational', 'apprenticeship', 'accreditation', 'workshop', 'upskilling', 'nvq'],

        'retail_shopping'       => ['shop', 'store', 'retail', 'collection', 'product', 'boutique'],
        'fashion_apparel'       => ['fashion', 'clothing', 'apparel', 'wardrobe', 'style', 'outfit'],
        'jewellery_accessories' => ['jewellery', 'jewelry', 'ring', 'necklace', 'watch', 'accessories'],
        'ecommerce_online'      => ['ecommerce', 'e-commerce', 'online store', 'checkout', 'delivery', 'shipping'],

        'news_current_affairs'  => ['news', 'headline', 'report', 'breaking', 'politics', 'coverage'],
        'media_publishing'      => ['magazine', 'publication', 'editorial', 'journalism', 'broadcast', 'podcast'],
        'events_weddings'       => ['wedding', 'event', 'venue', 'reception', 'celebration', 'party', 'conference'],
    ];

    public static function isValid(string $code): bool
    {
        return isset(self::INTERESTS[$code]);
    }

    public static function label(string $code): ?string
    {
        return self::INTERESTS[$code] ?? null;
    }

    public static function defaultsForIndustry(?string $industrySlug): array
    {
        if ($industrySlug === null) {
            return [];
        }

        return self::INDUSTRY_DEFAULTS[$industrySlug] ?? [];
    }

    /**
     * Score free text against the vocabulary.
     *
     * @return array<string,int> interest code → number of distinct keywords hit
     *                           (a crude but honest confidence proxy; one
     *                           incidental mention should not equal a whole
     *                           site being about the topic)
     */
    public static function scoreText(string $text): array
    {
        $haystack = self::normalise($text);

        if ($haystack === '') {
            return [];
        }

        $scores = [];

        foreach (self::INTEREST_KEYWORDS as $interest => $keywords) {
            $hits = 0;
            foreach ($keywords as $keyword) {
                if (self::containsWord($haystack, $keyword)) {
                    $hits++;
                }
            }
            if ($hits > 0) {
                $scores[$interest] = $hits;
            }
        }

        arsort($scores);

        return $scores;
    }

    private static function normalise(string $text): string
    {
        $text = mb_strtolower(strip_tags($text));
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    private static function containsWord(string $haystack, string $needle): bool
    {
        $needle = self::normalise($needle);

        if ($needle === '') {
            return false;
        }

        return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $haystack);
    }
}
