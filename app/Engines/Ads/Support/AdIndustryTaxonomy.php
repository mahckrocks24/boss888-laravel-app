<?php

namespace App\Engines\Ads\Support;

use App\Engines\Builder\Support\TemplateArchetypes;

/**
 * AdIndustryTaxonomy — the controlled industry vocabulary for ad targeting.
 *
 * THREE LEVELS
 *   archetype     9 values   — reused verbatim from Builder TemplateArchetypes
 *   industry_slug 31 values  — the template directories under storage/templates
 *   iab_category  IAB Content Taxonomy 3.0 — the vocabulary media buyers use
 *
 * WHY REUSE TemplateArchetypes RATHER THAN DEFINE A SECOND TAXONOMY
 * The Builder already classifies businesses into 9 archetypes and ships a
 * keyword classifier (ARCHETYPE_KEYWORDS) that has been exercised against real
 * free-text business descriptions. Duplicating that here would guarantee the
 * two drift apart, and a site would then be one industry to the builder and a
 * different one to the ad server. This class delegates archetype resolution and
 * adds only what advertising needs on top: slug-level resolution and the IAB
 * mapping.
 *
 * ONE IMPORTANT DIFFERENCE IN BEHAVIOUR
 * TemplateArchetypes::archetypeOf() defaults an unknown slug to
 * 'professional_advisory' — correct for the builder, which must always render
 * *something*. For advertising that default is a silently wrong answer that
 * would sell a bakery to a law-firm campaign. This class therefore validates
 * the slug first and returns null rather than guessing. Unresolved inventory
 * serves house ads; it is never sold.
 *
 * @see \App\Engines\Ads\Services\IndustryClassifier for the derivation cascade
 */
final class AdIndustryTaxonomy
{
    /** The 9 business archetypes, with buyer-facing labels. */
    public const ARCHETYPES = [
        'medical'               => 'Medical & Healthcare',
        'appointment_service'   => 'Appointment Services',
        'professional_advisory' => 'Professional & Advisory',
        'portfolio_project'     => 'Design & Construction',
        'product_retail'        => 'Retail & Commerce',
        'hospitality_stay'      => 'Hospitality & Travel',
        'education'             => 'Education & Training',
        'content_editorial'     => 'Media & Publishing',
        'local_service'         => 'Local Trade Services',
    ];

    /**
     * The 31 template industries → buyer-facing label.
     * Keys MUST stay in lockstep with the directories under storage/templates.
     */
    public const INDUSTRIES = [
        'aesthetic_clinic'   => 'Aesthetic Clinic',
        'architecture'       => 'Architecture',
        'automotive'         => 'Automotive',
        'barbershop'         => 'Barbershop',
        'beauty_salon'       => 'Beauty Salon',
        'cafe'               => 'Café',
        'catering'           => 'Catering',
        'childcare'          => 'Childcare',
        'construction'       => 'Construction',
        'consulting'         => 'Consulting',
        'dental'             => 'Dental',
        'ecommerce'          => 'E-commerce',
        'event_venue'        => 'Event Venue',
        'gym'                => 'Gym & Fitness',
        'home_services'      => 'Home Services',
        'hotel'              => 'Hotel',
        'interior_design'    => 'Interior Design',
        'it_services'        => 'IT Services',
        'marketing_agency'   => 'Marketing Agency',
        'medical_clinic'     => 'Medical Clinic',
        'news_channel'       => 'News & Media',
        'online_courses'     => 'Online Courses',
        'pet_services'       => 'Pet Services',
        'real_estate_agency' => 'Real Estate Agency',
        'resort'             => 'Resort',
        'restaurant'         => 'Restaurant',
        'retail_shop'        => 'Retail Shop',
        'short_term_rental'  => 'Short-term Rental',
        'training_center'    => 'Training Centre',
        'travel_agency'      => 'Travel Agency',
        'tutoring'           => 'Tutoring',
    ];

    /**
     * industry_slug → IAB Content Taxonomy 3.0 categories.
     *
     * Kept even though programmatic is out of scope: IAB is the vocabulary a
     * media buyer already thinks in, it makes creative specs portable, and a
     * rate card expressed in IAB categories reads as professional to anyone who
     * has bought advertising before.
     */
    public const IAB_MAP = [
        'aesthetic_clinic'   => ['Medical Health', 'Style & Fashion > Beauty'],
        'architecture'       => ['Fine Art > Design', 'Home & Garden'],
        'automotive'         => ['Automotive'],
        'barbershop'         => ['Style & Fashion > Beauty'],
        'beauty_salon'       => ['Style & Fashion > Beauty'],
        'cafe'               => ['Food & Drink > Dining Out'],
        'catering'           => ['Food & Drink', 'Events and Attractions'],
        'childcare'          => ['Family and Relationships > Parenting', 'Education'],
        'construction'       => ['Home & Garden', 'Business and Finance > Industries'],
        'consulting'         => ['Business and Finance > Business'],
        'dental'             => ['Medical Health > Dental Health'],
        'ecommerce'          => ['Shopping'],
        'event_venue'        => ['Events and Attractions'],
        'gym'                => ['Healthy Living > Fitness and Exercise'],
        'home_services'      => ['Home & Garden'],
        'hotel'              => ['Travel > Travel Accommodations'],
        'interior_design'    => ['Home & Garden > Interior Decorating'],
        'it_services'        => ['Technology & Computing'],
        'marketing_agency'   => ['Business and Finance > Business > Marketing and Advertising'],
        'medical_clinic'     => ['Medical Health'],
        'news_channel'       => ['News and Politics'],
        'online_courses'     => ['Education > Online Education'],
        'pet_services'       => ['Pets'],
        'real_estate_agency' => ['Real Estate'],
        'resort'             => ['Travel > Travel Accommodations'],
        'restaurant'         => ['Food & Drink > Dining Out'],
        'retail_shop'        => ['Shopping'],
        'short_term_rental'  => ['Travel > Travel Accommodations'],
        'training_center'    => ['Education > Vocational Training'],
        'travel_agency'      => ['Travel'],
        'tutoring'           => ['Education'],
    ];

    /**
     * Free-text → industry_slug keywords.
     *
     * ORDER IS SIGNIFICANT — first match wins, so specific precedes generic.
     * 'dental' must be tested before 'medical_clinic' or "dental clinic" lands
     * on the wrong slug; 'home_services' must precede the generic trades so
     * "shelving installation" resolves correctly.
     *
     * Matching is WORD-BOUNDARY, not substring: a naive substring match makes
     * "spa" hit "spacious" and "vet" hit "veteran".
     */
    private const INDUSTRY_KEYWORDS = [
        'dental'             => ['dental', 'dentist', 'dentistry', 'orthodontic', 'orthodontist', 'endodontic'],
        'aesthetic_clinic'   => ['aesthetic', 'aesthetics', 'medspa', 'med spa', 'cosmetic clinic', 'dermatology', 'dermatologist', 'botox', 'filler', 'laser clinic'],
        'pet_services'       => ['pet', 'pets', 'veterinary', 'veterinarian', 'vet clinic', 'grooming', 'kennel', 'cattery', 'dog', 'cat'],
        'medical_clinic'     => ['clinic', 'medical', 'doctor', 'physician', 'physiotherapy', 'physio', 'surgery', 'surgeon', 'hospital', 'paediatric', 'pediatric', 'chiropractic', 'optical', 'optometry', 'fertility', 'psychiatry'],
        'barbershop'         => ['barber', 'barbershop'],
        'beauty_salon'       => ['salon', 'nails', 'nail', 'lashes', 'lash', 'brows', 'waxing', 'spa', 'hairdresser', 'hair', 'makeup', 'beauty'],
        'gym'                => ['gym', 'fitness', 'crossfit', 'yoga', 'pilates', 'bootcamp', 'personal training', 'personal trainer'],
        'cafe'               => ['cafe', 'café', 'coffee', 'bakery', 'patisserie', 'tea room', 'coffee shop'],
        'catering'           => ['catering', 'caterer', 'caterers'],
        'restaurant'         => ['restaurant', 'bistro', 'diner', 'grill', 'eatery', 'dining', 'chef', 'cuisine', 'brasserie', 'steakhouse'],
        'resort'             => ['resort'],
        'hotel'              => ['hotel', 'inn', 'lodge', 'hostel', 'guesthouse'],
        'short_term_rental'  => ['airbnb', 'short-term rental', 'short term rental', 'holiday let', 'vacation rental', 'serviced apartment'],
        'travel_agency'      => ['travel', 'tours', 'tour', 'excursion', 'holidays', 'travel agency'],
        'event_venue'        => ['event venue', 'banquet', 'wedding venue', 'conference centre', 'conference center', 'function hall', 'events venue'],
        'ecommerce'          => ['ecommerce', 'e-commerce', 'online store', 'online shop', 'shopify', 'dropshipping'],
        'retail_shop'        => ['retail', 'boutique', 'shop', 'store', 'florist', 'jewellery', 'jewelry', 'jeweller', 'grocery', 'bookstore'],
        'real_estate_agency' => ['real estate', 'realtor', 'realty', 'property', 'properties', 'lettings', 'estate agent', 'brokerage'],
        'architecture'       => ['architecture', 'architect', 'architects'],
        'interior_design'    => ['interior design', 'interior designer', 'interior decorating', 'interiors'],
        'construction'       => ['construction', 'contractor', 'contracting', 'builder', 'builders', 'renovation', 'renovations', 'fit-out', 'fitout', 'joinery', 'carpentry'],
        'home_services'      => ['plumbing', 'plumber', 'electrical', 'electrician', 'hvac', 'cleaning', 'handyman', 'landscaping', 'gardening', 'roofing', 'painting', 'pest control', 'locksmith', 'shelving', 'installation', 'maintenance', 'movers', 'moving'],
        'automotive'         => ['automotive', 'auto repair', 'mechanic', 'garage', 'car detailing', 'car wash', 'tyre', 'tire', 'bodyshop', 'car dealership'],
        'it_services'        => ['it services', 'it support', 'managed services', 'software', 'saas', 'web development', 'cybersecurity', 'cloud services', 'devops'],
        'marketing_agency'   => ['marketing', 'advertising', 'seo', 'branding', 'digital agency', 'media agency', 'pr agency', 'creative agency'],
        'consulting'         => ['consulting', 'consultancy', 'consultant', 'advisory', 'strategy', 'coaching', 'accountant', 'accounting', 'bookkeeping', 'legal', 'law firm', 'lawyer', 'solicitor', 'attorney', 'tax', 'insurance', 'recruitment', 'staffing'],
        'news_channel'       => ['news', 'magazine', 'newspaper', 'tribune', 'gazette', 'journal', 'publication', 'media outlet', 'broadcast', 'podcast'],
        'childcare'          => ['childcare', 'nursery', 'daycare', 'day care', 'preschool', 'kindergarten', 'montessori', 'creche'],
        'online_courses'     => ['online course', 'online courses', 'e-learning', 'elearning', 'course platform', 'mooc'],
        'tutoring'           => ['tutoring', 'tutor', 'tutors', 'tuition', 'lessons'],
        'training_center'    => ['training', 'vocational', 'institute', 'academy', 'upskilling', 'certification'],
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────────────────

    public static function isValidIndustry(?string $slug): bool
    {
        return $slug !== null && isset(self::INDUSTRIES[$slug]);
    }

    public static function isValidArchetype(?string $code): bool
    {
        return $code !== null && isset(self::ARCHETYPES[$code]);
    }

    /**
     * Archetype for a known industry slug — null (never a guess) when the slug
     * is not one of the 31. Deliberately stricter than
     * TemplateArchetypes::archetypeOf(), which defaults to professional_advisory.
     */
    public static function archetypeForIndustry(?string $slug): ?string
    {
        if (! self::isValidIndustry($slug)) {
            return null;
        }

        $archetype = TemplateArchetypes::archetypeOf($slug);

        return self::isValidArchetype($archetype) ? $archetype : null;
    }

    /** IAB 3.0 categories for an industry slug; empty when unresolved. */
    public static function iabFor(?string $slug): array
    {
        return self::isValidIndustry($slug) ? (self::IAB_MAP[$slug] ?? []) : [];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Classification
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve free text (an industry description, optionally plus the business
     * name) to one of the 31 industry slugs.
     *
     * @return array{slug: string|null, matched: string|null}
     *         `matched` is the keyword that fired — recorded as evidence so a
     *         classification can be explained and audited later.
     */
    public static function industryFromText(string $rawIndustry, string $name = ''): array
    {
        $haystack = self::normalise($rawIndustry . ' ' . $name);

        if ($haystack === '') {
            return ['slug' => null, 'matched' => null];
        }

        foreach (self::INDUSTRY_KEYWORDS as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (self::containsWord($haystack, $keyword)) {
                    return ['slug' => $slug, 'matched' => $keyword];
                }
            }
        }

        return ['slug' => null, 'matched' => null];
    }

    /**
     * Archetype from free text, delegating to the Builder's classifier so the
     * two engines can never disagree about what a business is.
     */
    public static function archetypeFromText(string $rawIndustry, string $name = ''): ?string
    {
        $archetype = TemplateArchetypes::archetypeForBusiness($rawIndustry, $name);

        return self::isValidArchetype($archetype) ? $archetype : null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    private static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        // Collapse punctuation to spaces so "Travel & Tours" and "e-commerce"
        // both tokenise predictably. Hyphens are preserved because several
        // keywords are legitimately hyphenated ("short-term rental").
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * Word-boundary containment. Substring matching produces false positives
     * that are invisible until an advertiser complains — "spa" inside
     * "spacious", "vet" inside "veteran", "tour" inside "detour".
     */
    private static function containsWord(string $haystack, string $needle): bool
    {
        $needle = self::normalise($needle);

        if ($needle === '') {
            return false;
        }

        return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $haystack);
    }
}
