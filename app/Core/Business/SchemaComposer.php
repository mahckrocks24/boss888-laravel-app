<?php

namespace App\Core\Business;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * K5 (2026-09-25) — one connected entity graph per page, from canonical truth.
 *
 * Before this, two renderers invented business entities independently and
 * neither could reference the other. BuilderRenderer emitted the same five-field
 * blob on every page of every site — @type hardcoded to LocalBusiness whatever
 * the industry, with name, url and description and nothing else — while
 * WriteService declared a second, anonymous Organization inside each article.
 * Across 202 article blobs, 202 lacked @id, image and mainEntityOfPage, so
 * nothing could be referenced and every page re-declared the business from
 * scratch as a fresh anonymous node.
 *
 * This composes ONE graph whose nodes carry stable @ids and point at each other:
 *
 *     {site}/#organization   the business
 *     {site}/#website        the site, publisher -> #organization
 *     {pageUrl}#webpage      the page, isPartOf -> #website, about -> #organization
 *     {pageUrl}#article      the article, publisher/author -> #organization
 *     {site}/#service/{slug} a service the business offers
 *     {site}/#person/{slug}  a named person
 *
 * Two rules it will not break:
 *
 *   1. Only facts that may be published get published. Every identity field is
 *      gated on Business::identityIsPublishable(), so the 157 candidates
 *      recovered by K4 — 151 of them AI-generated template seed data — stay out
 *      of public structured data until someone confirms them.
 *
 *   2. A node is emitted only when there is something real to say. No empty
 *      Person, no Service list built from marketing prose, no address assembled
 *      out of a city name alone.
 */
final class SchemaComposer
{
    public const CONTEXT = 'https://schema.org';

    /**
     * industry -> the most specific schema.org type that is actually correct.
     *
     * Matching is on the longest key contained in the industry string, so
     * 'restaurant counter' resolves to Restaurant and 'travel agency' to
     * TravelAgency. Anything unmatched falls back by shape rather than
     * pretending: a local-serving business becomes LocalBusiness, everything
     * else Organization.
     */
    private const TYPES = [
        'bakery' => 'Bakery',
        'cafe' => 'CafeOrCoffeeShop',
        'coffee' => 'CafeOrCoffeeShop',
        'restaurant' => 'Restaurant',
        'bar' => 'BarOrPub',
        'catering' => 'FoodEstablishment',
        'private chef' => 'FoodEstablishment',
        'dental' => 'Dentist',
        'dentist' => 'Dentist',
        'medical clinic' => 'MedicalClinic',
        'clinic' => 'MedicalClinic',
        'physio' => 'Physician',
        'veterinary' => 'VeterinaryCare',
        'pet services' => 'LocalBusiness',
        'gym' => 'ExerciseGym',
        'fitness' => 'ExerciseGym',
        'yoga' => 'ExerciseGym',
        'wellness' => 'HealthAndBeautyBusiness',
        'spa' => 'DaySpa',
        'salon' => 'BeautySalon',
        'aesthetic' => 'HealthAndBeautyBusiness',
        'travel agency' => 'TravelAgency',
        'travel' => 'TravelAgency',
        'tour' => 'TravelAgency',
        'hotel' => 'Hotel',
        'real estate' => 'RealEstateAgent',
        'realtor' => 'RealEstateAgent',
        'legal' => 'LegalService',
        'law' => 'LegalService',
        'attorney' => 'LegalService',
        'solicitor' => 'LegalService',
        'accounting' => 'AccountingService',
        'accountant' => 'AccountingService',
        'bookkeeping' => 'AccountingService',
        'insurance' => 'InsuranceAgency',
        'plumb' => 'Plumber',
        'electric' => 'Electrician',
        'roofing' => 'RoofingContractor',
        'construction' => 'GeneralContractor',
        'contractor' => 'GeneralContractor',
        'interior design' => 'HomeAndConstructionBusiness',
        'landscap' => 'LandscapingBusiness',
        'auto' => 'AutoRepair',
        'car repair' => 'AutoRepair',
        'event venue' => 'EventVenue',
        'event' => 'EventVenue',
        'photography' => 'ProfessionalService',
        'tutoring' => 'EducationalOrganization',
        'school' => 'EducationalOrganization',
        'training' => 'EducationalOrganization',
        'news' => 'NewsMediaOrganization',
        'publisher' => 'NewsMediaOrganization',
        'magazine' => 'NewsMediaOrganization',
        'charity' => 'NGO',
        'foundation' => 'NGO',
        'nonprofit' => 'NGO',
    ];

    /** Types that are Organization but NOT LocalBusiness — they take no address block. */
    private const NON_LOCAL = ['NewsMediaOrganization', 'NGO', 'EducationalOrganization', 'Organization'];

    /** Industries that are professional services rather than a storefront. */
    private const PROFESSIONAL = ['consulting', 'marketing', 'agency', 'it services', 'software', 'design', 'engineering', 'recruit', 'logistics', 'security'];

    public function __construct(private readonly CanonicalSite $canonical)
    {
    }

    /**
     * The graph for one page of one website.
     *
     * $page accepts: url, name, description, breadcrumb (array of [name, url]),
     * isHome (bool). Returns [] when the site has no usable public host, because
     * a graph with no stable base cannot carry stable ids.
     */
    public function forWebsite(int $websiteId, array $page = []): array
    {
        $identity = $this->canonical->forWebsite($websiteId);
        if (! $identity || empty($identity['host'])) {
            return [];
        }

        $site = rtrim($identity['url'], '/');
        $business = app(BusinessProfileResolver::class)->forWebsite($websiteId);

        $graph = [$this->organization($business, $identity, $site), $this->website($identity, $site)];

        foreach ($this->services($business, $site) as $service) {
            $graph[] = $service;
        }

        $pageUrl = rtrim((string) ($page['url'] ?? $site), '/') ?: $site;
        if ($page) {
            $graph[] = $this->webPage($pageUrl, $site, $page);
            $crumbs = $this->breadcrumb($pageUrl, $page);
            if ($crumbs) {
                $graph[] = $crumbs;
            }
        }

        return ['@context' => self::CONTEXT, '@graph' => $graph];
    }

    /**
     * The graph for one article: Article and optional FAQPage, both hanging off
     * the SAME organization and website nodes the site's pages use.
     */
    public function forArticle(int $articleId, array $article = [], array $faqs = []): array
    {
        $identity = $this->canonical->forArticle($articleId);
        $name = (string) ($identity['name'] ?? '');

        // With no host there are no stable ids, so fall back to the flat shape:
        // a named publisher and nothing that pretends to be addressable.
        if (empty($identity['host'])) {
            return $this->flatArticle($name, $article, $faqs);
        }

        $site = rtrim((string) $identity['url'], '/');
        $business = $identity['website_id']
            ? app(BusinessProfileResolver::class)->forWebsite((int) $identity['website_id'])
            : null;

        $url = rtrim((string) ($article['url'] ?? ''), '/');
        $articleId_ = $url !== '' ? $url . '#article' : $site . '/#article';

        $node = array_filter([
            '@type' => 'Article',
            '@id' => $articleId_,
            'headline' => $article['headline'] ?? null,
            'description' => $article['description'] ?? null,
            'datePublished' => $article['datePublished'] ?? null,
            'dateModified' => $article['dateModified'] ?? null,
            'url' => $url !== '' ? $url : null,
            'mainEntityOfPage' => $url !== '' ? ['@id' => $url . '#webpage'] : null,
            'image' => ! empty($article['image']) ? $this->image($article['image']) : null,
            'author' => ['@id' => $site . '/#organization'],
            'publisher' => ['@id' => $site . '/#organization'],
        ], fn ($v) => $v !== null && $v !== '');

        $graph = [$node];

        if ($business) {
            $graph[] = $this->organization($business, $identity, $site);
            $graph[] = $this->website($identity, $site);
        }

        if ($url !== '') {
            $graph[] = array_filter([
                '@type' => 'WebPage',
                '@id' => $url . '#webpage',
                'url' => $url,
                'name' => $article['headline'] ?? null,
                'isPartOf' => ['@id' => $site . '/#website'],
                'primaryImageOfPage' => ! empty($article['image']) ? ['@id' => $url . '#primaryimage'] : null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        if ($faqs) {
            $graph[] = $this->faqPage($faqs, $url !== '' ? $url . '#faq' : null);
        }

        return ['@context' => self::CONTEXT, '@graph' => $graph];
    }

    /** The most specific correct type for a business. */
    public function typeFor(?string $industry, ?string $override = null): string
    {
        $override = trim((string) $override);
        if ($override !== '') {
            return $override;
        }

        $needle = strtolower(trim(str_replace('_', ' ', (string) $industry)));
        if ($needle === '') {
            return 'Organization';
        }

        $best = null;
        foreach (self::TYPES as $key => $type) {
            if (str_contains($needle, $key) && (($best === null) || strlen($key) > strlen($best[0]))) {
                $best = [$key, $type];
            }
        }
        if ($best) {
            return $best[1];
        }

        foreach (self::PROFESSIONAL as $key) {
            if (str_contains($needle, $key)) {
                return 'ProfessionalService';
            }
        }

        return 'LocalBusiness';
    }

    // ---- nodes -----------------------------------------------------------

    private function organization(?Business $business, array $identity, string $site): array
    {
        $type = $this->typeFor($business?->industry, $business?->schema_type);

        $node = [
            '@type' => $type,
            '@id' => $site . '/#organization',
            'name' => (string) ($identity['name'] ?? ''),
            'url' => $site . '/',
        ];

        if (! $business) {
            return $node;
        }

        // Only what may be published. A candidate value is held back.
        if ($business->identityIsPublishable('phone')) {
            $node['telephone'] = $business->phone;
        }
        if ($business->identityIsPublishable('email')) {
            $node['email'] = $business->email;
        }
        if ($business->identityIsPublishable('sameas_json')) {
            $node['sameAs'] = array_values($business->sameas_json);
        }
        if ($business->identityIsPublishable('founding_date')) {
            $node['foundingDate'] = $business->founding_date;
        }
        if ($business->identityIsPublishable('area_served_json')) {
            $node['areaServed'] = array_values($business->area_served_json);
        }

        // K7 — evidence-bearing claims, and only the ones cleared to be stated.
        foreach ($this->claims($business) as $property => $value) {
            $node[$property] = $value;
        }

        // A LocalBusiness may carry a postal address; a NewsMediaOrganization
        // or an NGO is not a place and must not pretend to be one.
        if (! in_array($type, self::NON_LOCAL, true)) {
            if ($business->identityIsPublishable('address_json')) {
                $address = array_filter($business->address_json, fn ($v) => $v !== null && $v !== '');
                if ($address) {
                    $node['address'] = ['@type' => 'PostalAddress'] + $address;
                }
            }
            if ($business->identityIsPublishable('opening_hours_json')) {
                $hours = $business->opening_hours_json;
                // Only a real specification is emitted. A free-text blob such as
                // "Tue-Thu 11:30 AM-9:30 PM" is not openingHoursSpecification and
                // guessing its structure would invent a fact.
                if (isset($hours['specification']) && is_array($hours['specification'])) {
                    $node['openingHoursSpecification'] = $hours['specification'];
                }
            }
        }

        if ($business->identityIsPublishable('logo_url') || ! empty($business->logo_url)) {
            $logo = (string) $business->logo_url;
            if (str_starts_with($logo, 'http')) {
                $node['logo'] = ['@type' => 'ImageObject', '@id' => $site . '/#logo', 'url' => $logo];
            }
        }

        return $node;
    }

    /**
     * K7 (2026-09-25) — the claims a business is allowed to state publicly.
     *
     * BusinessFact::publishableFor applies both gates: the platform's (the
     * source is one it will stand behind) and the owner's (they chose to
     * publish it). Everything else — a model's guess, an unconfirmed import, an
     * observation — is stored and stays silent.
     *
     * Statistics are deliberately NOT mapped. schema.org has no honest property
     * for "we have served 4,000 customers", and hanging it off description or
     * slogan would be dressing a marketing line as structured data.
     *
     * @return array<string, mixed> schema.org property => value
     */
    private function claims(?Business $business): array
    {
        if (! $business || ! $business->id) {
            return [];
        }

        $awards = [];
        $credentials = [];
        $memberships = [];
        $reviews = [];

        foreach (\App\Models\BusinessFact::publishableFor((int) $business->id) as $fact) {
            $label = trim((string) $fact->label);

            switch ((string) $fact->kind) {
                case 'award':
                    $awards[] = $label;
                    break;

                case 'certification':
                    $credentials[] = array_filter([
                        '@type' => 'EducationalOccupationalCredential',
                        'name' => $label,
                        'url' => $fact->source_url ?: null,
                    ], fn ($v) => $v !== null && $v !== '');
                    break;

                case 'membership':
                    $memberships[] = array_filter([
                        '@type' => 'Organization',
                        'name' => $label,
                        'url' => $fact->source_url ?: null,
                    ], fn ($v) => $v !== null && $v !== '');
                    break;

                case 'testimonial':
                    $body = trim((string) $fact->value);
                    if ($body === '') {
                        break;
                    }
                    $reviews[] = array_filter([
                        '@type' => 'Review',
                        'reviewBody' => $body,
                        'author' => ['@type' => 'Person', 'name' => $label],
                        'datePublished' => $fact->occurred_on ?: null,
                    ], fn ($v) => $v !== null && $v !== '');
                    break;

                // 'statistic' is stored, never published. See the note above.
            }
        }

        return array_filter([
            'award' => $awards,
            'hasCredential' => $credentials,
            'memberOf' => $memberships,
            'review' => $reviews,
        ], fn ($v) => $v !== []);
    }
    private function website(array $identity, string $site): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => $site . '/#website',
            'url' => $site . '/',
            'name' => (string) ($identity['name'] ?? ''),
            'publisher' => ['@id' => $site . '/#organization'],
        ];
    }

    private function webPage(string $pageUrl, string $site, array $page): array
    {
        return array_filter([
            '@type' => 'WebPage',
            '@id' => $pageUrl . '#webpage',
            'url' => $pageUrl,
            'name' => $page['name'] ?? null,
            'description' => $page['description'] ?? null,
            'isPartOf' => ['@id' => $site . '/#website'],
            'about' => ['@id' => $site . '/#organization'],
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Services are emitted only from the structured services list on the
     * business profile — never parsed out of page copy.
     */
    private function services(?Business $business, string $site): array
    {
        $services = $business?->services_json;
        if (! is_array($services) || ! $services) {
            return [];
        }

        $out = [];
        foreach (array_slice(array_values($services), 0, 24) as $service) {
            $name = is_array($service) ? (string) ($service['name'] ?? '') : (string) $service;
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $slug = Str::slug($name) ?: substr(md5($name), 0, 8);
            $out[] = [
                '@type' => 'Service',
                '@id' => $site . '/#service/' . $slug,
                'name' => $name,
                'provider' => ['@id' => $site . '/#organization'],
            ];
        }

        return $out;
    }

    private function breadcrumb(string $pageUrl, array $page): ?array
    {
        $trail = $page['breadcrumb'] ?? [];
        if (! is_array($trail) || count($trail) < 2) {
            return null;
        }

        $items = [];
        $position = 1;
        foreach ($trail as $crumb) {
            $name = trim((string) ($crumb['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $items[] = array_filter([
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $name,
                'item' => $crumb['url'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $items ? ['@type' => 'BreadcrumbList', '@id' => $pageUrl . '#breadcrumb', 'itemListElement' => $items] : null;
    }

    private function faqPage(array $faqs, ?string $id): array
    {
        $entities = [];
        foreach ($faqs as $faq) {
            $q = trim((string) ($faq['q'] ?? ''));
            $a = trim((string) ($faq['a'] ?? ''));
            if ($q === '' || $a === '') {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $q,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
            ];
        }

        return array_filter([
            '@type' => 'FAQPage',
            '@id' => $id,
            'mainEntity' => $entities,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function image(string $url): array|string
    {
        return str_starts_with($url, 'http') ? ['@type' => 'ImageObject', 'url' => $url] : $url;
    }

    /** No host: name the publisher, claim nothing addressable. */
    private function flatArticle(string $name, array $article, array $faqs): array
    {
        $organization = array_filter(['@type' => 'Organization', 'name' => $name], fn ($v) => $v !== '');

        $node = array_filter([
            '@type' => 'Article',
            'headline' => $article['headline'] ?? null,
            'description' => $article['description'] ?? null,
            'datePublished' => $article['datePublished'] ?? null,
            'dateModified' => $article['dateModified'] ?? null,
            'author' => $organization ?: null,
            'publisher' => $organization ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        $graph = [$node];
        if ($faqs) {
            $graph[] = $this->faqPage($faqs, null);
        }

        return ['@context' => self::CONTEXT, '@graph' => $graph];
    }
}
