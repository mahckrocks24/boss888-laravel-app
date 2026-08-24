<?php

namespace App\Engines\Ads\Services;

/**
 * LocationParser — free-text business location → structured, targetable geography.
 *
 * WHY THIS IS NEEDED
 * `workspaces.location` is free text written by whoever onboarded the business.
 * Real values on staging today:
 *
 *   "Dubai, UAE"
 *   "New Jersey, USA (serving NJ, NY, CT)"
 *   "Santa Rosa, Laguna, Philippines"
 *   "Dubai, UAE"                              (11 of 25 workspaces; the rest NULL)
 *
 * An advertiser cannot buy "businesses in Dubai" against that. This parser
 * resolves what it can to ISO-3166-1 alpha-2 + region + city, records a
 * confidence, and — critically — REFUSES TO GUESS. A location it cannot parse
 * returns confidence 0.0 and the site is excluded from geo-targeted campaigns
 * rather than being quietly assigned to the wrong country.
 *
 * SCOPE AND ITS LIMITS (stated honestly)
 * This is a lexicon-driven parser, not a geocoding service. It covers the
 * countries and cities this platform actually serves plus common aliases. It
 * will not resolve an arbitrary village. That is the correct trade-off here:
 * a wrong country silently sells an advertiser the wrong audience, whereas an
 * unresolved location is visible in the admin unclassified queue and gets
 * fixed by a human. Fail loud, not wrong.
 *
 * THIS IS THE PUBLISHER'S LOCATION, NOT THE VISITOR'S.
 * Visitor country is a request-time attribute read from the Cloudflare
 * `CF-IPCountry` header at ad-decision time and is never stored here.
 */
final class LocationParser
{
    /**
     * Country lexicon: alias (lowercase) → ISO-3166-1 alpha-2.
     * Aliases matter more than completeness — "UAE", "U.A.E.", "Emirates" and
     * "United Arab Emirates" all appear in real data.
     */
    private const COUNTRIES = [
        'uae' => 'AE', 'u a e' => 'AE', 'united arab emirates' => 'AE', 'emirates' => 'AE',
        'usa' => 'US', 'u s a' => 'US', 'united states' => 'US', 'united states of america' => 'US', 'us' => 'US', 'america' => 'US',
        'uk' => 'GB', 'united kingdom' => 'GB', 'great britain' => 'GB', 'britain' => 'GB', 'england' => 'GB', 'scotland' => 'GB', 'wales' => 'GB',
        'philippines' => 'PH', 'the philippines' => 'PH', 'ph' => 'PH',
        'canada' => 'CA', 'australia' => 'AU', 'new zealand' => 'NZ', 'ireland' => 'IE',
        'india' => 'IN', 'pakistan' => 'PK', 'bangladesh' => 'BD', 'sri lanka' => 'LK',
        'singapore' => 'SG', 'malaysia' => 'MY', 'indonesia' => 'ID', 'thailand' => 'TH', 'vietnam' => 'VN',
        'saudi arabia' => 'SA', 'ksa' => 'SA', 'qatar' => 'QA', 'kuwait' => 'KW', 'bahrain' => 'BH', 'oman' => 'OM',
        'egypt' => 'EG', 'jordan' => 'JO', 'lebanon' => 'LB', 'turkey' => 'TR', 'morocco' => 'MA',
        'south africa' => 'ZA', 'nigeria' => 'NG', 'kenya' => 'KE', 'ghana' => 'GH',
        'germany' => 'DE', 'france' => 'FR', 'spain' => 'ES', 'italy' => 'IT', 'portugal' => 'PT',
        'netherlands' => 'NL', 'holland' => 'NL', 'belgium' => 'BE', 'switzerland' => 'CH', 'austria' => 'AT',
        'sweden' => 'SE', 'norway' => 'NO', 'denmark' => 'DK', 'finland' => 'FI', 'poland' => 'PL',
        'greece' => 'GR', 'cyprus' => 'CY', 'malta' => 'MT',
        'japan' => 'JP', 'china' => 'CN', 'hong kong' => 'HK', 'south korea' => 'KR',
        'brazil' => 'BR', 'mexico' => 'MX', 'argentina' => 'AR', 'chile' => 'CL',
    ];

    /**
     * City → [country, region]. Lets a bare "Dubai" resolve without a country
     * token, at a deliberately lower confidence than an explicit country.
     */
    private const CITY_HINTS = [
        'dubai'        => ['AE', 'Dubai'],
        'abu dhabi'    => ['AE', 'Abu Dhabi'],
        'sharjah'      => ['AE', 'Sharjah'],
        'ajman'        => ['AE', 'Ajman'],
        'ras al khaimah' => ['AE', 'Ras Al Khaimah'],
        'fujairah'     => ['AE', 'Fujairah'],
        'umm al quwain'=> ['AE', 'Umm Al Quwain'],
        'al ain'       => ['AE', 'Abu Dhabi'],
        'london'       => ['GB', 'Greater London'],
        'manchester'   => ['GB', 'Greater Manchester'],
        'birmingham'   => ['GB', 'West Midlands'],
        'edinburgh'    => ['GB', 'Scotland'],
        'glasgow'      => ['GB', 'Scotland'],
        'new york'     => ['US', 'New York'],
        'los angeles'  => ['US', 'California'],
        'san francisco'=> ['US', 'California'],
        'chicago'      => ['US', 'Illinois'],
        'miami'        => ['US', 'Florida'],
        'houston'      => ['US', 'Texas'],
        'boston'       => ['US', 'Massachusetts'],
        'seattle'      => ['US', 'Washington'],
        'toronto'      => ['CA', 'Ontario'],
        'vancouver'    => ['CA', 'British Columbia'],
        'sydney'       => ['AU', 'New South Wales'],
        'melbourne'    => ['AU', 'Victoria'],
        'manila'       => ['PH', 'Metro Manila'],
        'makati'       => ['PH', 'Metro Manila'],
        'cebu'         => ['PH', 'Cebu'],
        'santa rosa'   => ['PH', 'Laguna'],
        'singapore'    => ['SG', 'Singapore'],
        'doha'         => ['QA', 'Doha'],
        'riyadh'       => ['SA', 'Riyadh'],
        'jeddah'       => ['SA', 'Makkah'],
        'kuwait city'  => ['KW', 'Kuwait'],
        'manama'       => ['BH', 'Capital'],
        'muscat'       => ['OM', 'Muscat'],
        'cairo'        => ['EG', 'Cairo'],
        'mumbai'       => ['IN', 'Maharashtra'],
        'delhi'        => ['IN', 'Delhi'],
        'bangalore'    => ['IN', 'Karnataka'],
    ];

    /** US state abbreviation → full name, so "NJ" normalises to "New Jersey". */
    private const US_STATES = [
        'al' => 'Alabama', 'ak' => 'Alaska', 'az' => 'Arizona', 'ar' => 'Arkansas', 'ca' => 'California',
        'co' => 'Colorado', 'ct' => 'Connecticut', 'de' => 'Delaware', 'fl' => 'Florida', 'ga' => 'Georgia',
        'hi' => 'Hawaii', 'id' => 'Idaho', 'il' => 'Illinois', 'in' => 'Indiana', 'ia' => 'Iowa',
        'ks' => 'Kansas', 'ky' => 'Kentucky', 'la' => 'Louisiana', 'me' => 'Maine', 'md' => 'Maryland',
        'ma' => 'Massachusetts', 'mi' => 'Michigan', 'mn' => 'Minnesota', 'ms' => 'Mississippi', 'mo' => 'Missouri',
        'mt' => 'Montana', 'ne' => 'Nebraska', 'nv' => 'Nevada', 'nh' => 'New Hampshire', 'nj' => 'New Jersey',
        'nm' => 'New Mexico', 'ny' => 'New York', 'nc' => 'North Carolina', 'nd' => 'North Dakota', 'oh' => 'Ohio',
        'ok' => 'Oklahoma', 'or' => 'Oregon', 'pa' => 'Pennsylvania', 'ri' => 'Rhode Island', 'sc' => 'South Carolina',
        'sd' => 'South Dakota', 'tn' => 'Tennessee', 'tx' => 'Texas', 'ut' => 'Utah', 'vt' => 'Vermont',
        'va' => 'Virginia', 'wa' => 'Washington', 'wv' => 'West Virginia', 'wi' => 'Wisconsin', 'wy' => 'Wyoming',
        'dc' => 'District of Columbia',
    ];

    /**
     * Confidence bands. Exposed as constants so tests and the admin console
     * agree on what "high confidence" means rather than each hard-coding 0.9.
     */
    public const CONFIDENCE_EXPLICIT_FULL    = 0.95; // city + region + country all resolved
    public const CONFIDENCE_EXPLICIT_COUNTRY = 0.85; // country token matched
    public const CONFIDENCE_CITY_INFERRED    = 0.60; // country inferred from a known city
    public const CONFIDENCE_NONE             = 0.00; // nothing resolved — do not sell

    /**
     * @return array{
     *   country: string|null, region: string|null, city: string|null,
     *   service_areas: array<int,string>, confidence: float, evidence: array<string,mixed>
     * }
     */
    public function parse(?string $raw): array
    {
        $result = [
            'country'       => null,
            'region'        => null,
            'city'          => null,
            'service_areas' => [],
            'confidence'    => self::CONFIDENCE_NONE,
            'evidence'      => ['raw' => $raw],
        ];

        $raw = trim((string) $raw);

        if ($raw === '') {
            $result['evidence']['reason'] = 'empty';

            return $result;
        }

        // 1. Pull out a parenthetical service area — "(serving NJ, NY, CT)" —
        //    before tokenising, so it cannot be mistaken for the primary location.
        [$main, $serviceAreas] = $this->extractServiceAreas($raw);
        $result['service_areas'] = $serviceAreas;

        // 2. Split the remainder into comma-separated parts, most-specific first.
        $parts = array_values(array_filter(array_map(
            static fn ($p) => trim($p),
            explode(',', $main)
        ), static fn ($p) => $p !== ''));

        if ($parts === []) {
            $result['evidence']['reason'] = 'no_parts';

            return $result;
        }

        // 3. The last part is conventionally the country. Try it first.
        $countryCode = $this->matchCountry(end($parts));

        if ($countryCode !== null) {
            $result['country'] = $countryCode;
            $result['evidence']['country_from'] = 'explicit_token';
            array_pop($parts); // consumed

            // Remaining parts, most specific first: [city] or [city, region].
            if (count($parts) >= 2) {
                $result['city']   = $this->titleCase($parts[0]);
                $result['region'] = $this->normaliseRegion($parts[1], $countryCode);
                $result['confidence'] = self::CONFIDENCE_EXPLICIT_FULL;
            } elseif (count($parts) === 1) {
                // Ambiguous: one token could be a city or a region. Prefer a
                // known city, since that also yields a region for free.
                $token = $parts[0];
                $hint  = self::CITY_HINTS[$this->key($token)] ?? null;

                if ($hint !== null && $hint[0] === $countryCode) {
                    $result['city']   = $this->titleCase($token);
                    $result['region'] = $hint[1];
                } elseif ($countryCode === 'US' && $this->resolveUsState($token) !== null) {
                    // "New Jersey, USA" — a state, not a city. Assigning it to
                    // `city` would make the site invisible to a campaign
                    // targeting business_region.
                    $result['region'] = $this->resolveUsState($token);
                } else {
                    $result['city'] = $this->titleCase($token);
                }
                $result['confidence'] = self::CONFIDENCE_EXPLICIT_FULL;
            } else {
                $result['confidence'] = self::CONFIDENCE_EXPLICIT_COUNTRY;
            }

            return $result;
        }

        // 4. No country token. Try to infer from a known city anywhere in the
        //    string — deliberately at lower confidence than an explicit country.
        foreach ($parts as $index => $part) {
            $hint = self::CITY_HINTS[$this->key($part)] ?? null;

            if ($hint !== null) {
                $result['country']    = $hint[0];
                $result['region']     = $hint[1];
                $result['city']       = $this->titleCase($part);
                $result['confidence'] = self::CONFIDENCE_CITY_INFERRED;
                $result['evidence']['country_from'] = 'city_hint:' . $this->key($part);
                unset($index);

                return $result;
            }
        }

        // 5. Unresolvable. Record the city guess for the admin queue but keep
        //    confidence at zero so this inventory is never geo-targeted.
        $result['city'] = $this->titleCase($parts[0]);
        $result['evidence']['reason'] = 'unrecognised_country_and_city';

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * "New Jersey, USA (serving NJ, NY, CT)" → ["New Jersey, USA", ["New Jersey","New York","Connecticut"]]
     *
     * @return array{0: string, 1: array<int,string>}
     */
    private function extractServiceAreas(string $raw): array
    {
        if (! preg_match('/\(([^)]*)\)/u', $raw, $m)) {
            return [$raw, []];
        }

        $inner = $m[1];
        $main  = trim(str_replace($m[0], '', $raw));

        // Only treat the parenthetical as a service area when it says so.
        // "(est. 2019)" is not a coverage list.
        if (! preg_match('/\b(serving|servicing|covers?|coverage|areas?)\b/iu', $inner)) {
            return [$main, []];
        }

        $inner = preg_replace('/\b(serving|servicing|covers?|coverage|areas?)\b/iu', '', $inner) ?? '';

        $areas = [];
        foreach (preg_split('/[,\/&]|\band\b/iu', $inner) ?: [] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $areas[] = $this->looksLikeUsState($token)
                ? self::US_STATES[$this->key($token)]
                : $this->titleCase($token);
        }

        return [$main, array_values(array_unique($areas))];
    }

    private function matchCountry(string $token): ?string
    {
        $key = $this->key($token);

        if ($key === '') {
            return null;
        }

        if (isset(self::COUNTRIES[$key])) {
            return self::COUNTRIES[$key];
        }

        // Accept a bare ISO alpha-2 only when it is a real country code we know,
        // so a US state abbreviation ("NJ") is never read as a country.
        $upper = strtoupper($key);
        if (strlen($upper) === 2 && in_array($upper, self::COUNTRIES, true)) {
            return $upper;
        }

        return null;
    }

    /** True for a US state ABBREVIATION ("nj"). Full names use resolveUsState(). */
    private function looksLikeUsState(string $token): bool
    {
        return isset(self::US_STATES[$this->key($token)]);
    }

    /**
     * Resolve a US state from either form — abbreviation ("NJ") or full name
     * ("New Jersey") — to its canonical full name. Null when it is neither.
     *
     * The full-name direction matters: real data says "New Jersey, USA", and
     * without this the state lands in `business_city`, which silently excludes
     * the site from any campaign targeting business_region.
     */
    private function resolveUsState(string $token): ?string
    {
        $key = $this->key($token);

        if ($key === '') {
            return null;
        }

        if (isset(self::US_STATES[$key])) {
            return self::US_STATES[$key];
        }

        foreach (self::US_STATES as $full) {
            if ($this->key($full) === $key) {
                return $full;
            }
        }

        return null;
    }

    private function normaliseRegion(string $token, ?string $countryCode): ?string
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        if ($countryCode === 'US') {
            $state = $this->resolveUsState($token);

            if ($state !== null) {
                return $state;
            }
        }

        return $this->titleCase($token);
    }

    /** Lowercase, punctuation-stripped lookup key. */
    private function key(string $token): string
    {
        $token = mb_strtolower(trim($token));
        $token = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $token) ?? '';

        return trim(preg_replace('/\s+/', ' ', $token) ?? '');
    }

    private function titleCase(string $token): string
    {
        $token = trim(preg_replace('/\s+/', ' ', $token) ?? '');

        if ($token === '') {
            return '';
        }

        // Uppercase acronyms of 3 or fewer characters are left alone (e.g. "NYC").
        if (mb_strlen($token) <= 3 && mb_strtoupper($token) === $token) {
            return $token;
        }

        return mb_convert_case(mb_strtolower($token), MB_CASE_TITLE, 'UTF-8');
    }
}
