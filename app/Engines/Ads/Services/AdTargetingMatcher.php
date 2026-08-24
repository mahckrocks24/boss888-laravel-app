<?php

namespace App\Engines\Ads\Services;

/**
 * AdTargetingMatcher — does this inventory + request match a campaign's targeting?
 *
 * SEMANTICS
 *   AND across dimensions, OR within a dimension.
 *   "industry in (dental, medical_clinic) AND business_country in (AE)"
 *   means: a dental OR medical clinic, that is ALSO in the UAE.
 *
 * A campaign with NO targeting rows matches everything — that is how an
 * untargeted run-of-network campaign (and every house campaign) is expressed.
 *
 * THE RULE THAT MAKES THIS HONEST
 * When a campaign targets an inventory dimension and the profile cannot supply
 * a trustworthy value for it, the match FAILS. An advertiser who bought
 * "dental clinics in the UAE" is not served on a site whose industry we could
 * not resolve. They get what they bought or they get nothing.
 */
final class AdTargetingMatcher
{
    /** Dimensions that describe the INVENTORY (from ad_inventory_profiles). */
    private const INVENTORY_DIMENSIONS = [
        'business_country', 'business_region', 'archetype', 'industry',
        'iab_category', 'interest', 'website', 'min_confidence',
    ];

    /** Dimensions that describe the REQUEST (resolved per ad call). */
    private const REQUEST_DIMENSIONS = [
        'visitor_country', 'device', 'language', 'page_type',
    ];

    /**
     * @param  array<int,array{dimension: string, operator: string, values: array}>  $rules
     * @param  array<string,mixed>  $profile  a decoded ad_inventory_profiles row
     * @param  array<string,mixed>  $context  request context
     * @return array{matched: bool, failed_on: string|null, detail: string|null}
     */
    public function match(array $rules, array $profile, array $context = []): array
    {
        if ($rules === []) {
            return ['matched' => true, 'failed_on' => null, 'detail' => 'no targeting — run of network'];
        }

        // Group by dimension so multiple rows on the same dimension OR together.
        $byDimension = [];
        foreach ($rules as $rule) {
            $byDimension[$rule['dimension']][] = $rule;
        }

        foreach ($byDimension as $dimension => $dimensionRules) {
            foreach ($dimensionRules as $rule) {
                $operator = $rule['operator'] ?? 'in';
                $values   = array_values((array) ($rule['values'] ?? []));

                $actual = $this->actualValue($dimension, $profile, $context);

                // min_confidence is a threshold, not a set membership test.
                if ($dimension === 'min_confidence') {
                    $required = (float) ($values[0] ?? 0);
                    if ((float) ($profile['confidence'] ?? 0) < $required) {
                        return $this->fail($dimension, sprintf(
                            'confidence %.2f < required %.2f',
                            (float) ($profile['confidence'] ?? 0), $required
                        ));
                    }
                    continue;
                }

                // Inventory dimension with no trustworthy value → no match.
                if ($actual === null || $actual === [] || $actual === '') {
                    if (in_array($dimension, self::INVENTORY_DIMENSIONS, true)) {
                        return $this->fail($dimension, 'inventory has no value for this dimension');
                    }
                    // A missing REQUEST attribute (e.g. unknown device) only
                    // fails an inclusive rule; an exclusion still passes.
                    if ($operator === 'in') {
                        return $this->fail($dimension, 'request context missing this attribute');
                    }
                    continue;
                }

                $actualList = is_array($actual) ? $actual : [$actual];
                $intersects = array_intersect(
                    array_map('strval', $actualList),
                    array_map('strval', $values)
                ) !== [];

                if ($operator === 'in' && ! $intersects) {
                    return $this->fail($dimension, sprintf(
                        'actual [%s] not in [%s]',
                        implode(',', array_map('strval', $actualList)),
                        implode(',', array_map('strval', $values))
                    ));
                }

                if ($operator === 'not_in' && $intersects) {
                    return $this->fail($dimension, sprintf(
                        'actual [%s] excluded by [%s]',
                        implode(',', array_map('strval', $actualList)),
                        implode(',', array_map('strval', $values))
                    ));
                }
            }
        }

        return ['matched' => true, 'failed_on' => null, 'detail' => null];
    }

    /** Resolve a dimension to its value from the profile or request context. */
    private function actualValue(string $dimension, array $profile, array $context): mixed
    {
        return match ($dimension) {
            'business_country' => $profile['business_country'] ?? null,
            'business_region'  => $profile['business_region'] ?? null,
            'archetype'        => $profile['archetype'] ?? null,
            'industry'         => $profile['industry_slug'] ?? null,
            'iab_category'     => $profile['iab_categories'] ?? [],
            'interest'         => $profile['interests'] ?? [],
            'website'          => $profile['website_id'] ?? null,
            'min_confidence'   => $profile['confidence'] ?? 0,

            'visitor_country'  => $context['visitor_country'] ?? null,
            'device'           => $context['device'] ?? null,
            'language'         => $context['language'] ?? null,
            'page_type'        => $context['page_type'] ?? null,

            default            => null,
        };
    }

    /** @return array{matched: false, failed_on: string, detail: string} */
    private function fail(string $dimension, string $detail): array
    {
        return ['matched' => false, 'failed_on' => $dimension, 'detail' => $detail];
    }

    /** @return array<int,string> every dimension the matcher understands */
    public static function supportedDimensions(): array
    {
        return array_merge(self::INVENTORY_DIMENSIONS, self::REQUEST_DIMENSIONS);
    }
}
