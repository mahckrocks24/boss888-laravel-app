<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\AdTargetingMatcher;
use Tests\TestCase;

/**
 * ADS888 P0 — AdTargetingMatcher.
 *
 * The commercial contract under test: an advertiser gets the audience they
 * bought, or they get nothing. Never a near-miss.
 */
class AdTargetingMatcherTest extends TestCase
{
    private AdTargetingMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new AdTargetingMatcher();
    }

    private function profile(array $overrides = []): array
    {
        return array_merge([
            'website_id'       => 3,
            'industry_slug'    => 'dental',
            'archetype'        => 'medical',
            'iab_categories'   => ['Medical Health > Dental Health'],
            'interests'        => ['dental_care', 'health_wellness'],
            'business_country' => 'AE',
            'business_region'  => 'Dubai',
            'confidence'       => 0.75,
        ], $overrides);
    }

    private function rule(string $dimension, array $values, string $operator = 'in'): array
    {
        return ['dimension' => $dimension, 'operator' => $operator, 'values' => $values];
    }

    public function test_no_rules_matches_everything(): void
    {
        $this->assertTrue($this->matcher->match([], $this->profile())['matched']);
    }

    public function test_single_dimension_match(): void
    {
        $result = $this->matcher->match([$this->rule('industry', ['dental', 'medical_clinic'])], $this->profile());

        $this->assertTrue($result['matched']);
    }

    public function test_single_dimension_miss(): void
    {
        $result = $this->matcher->match([$this->rule('industry', ['gym'])], $this->profile());

        $this->assertFalse($result['matched']);
        $this->assertSame('industry', $result['failed_on']);
    }

    /** AND across dimensions: both must hold. */
    public function test_dimensions_are_anded(): void
    {
        $rules = [
            $this->rule('industry', ['dental']),
            $this->rule('business_country', ['AE']),
        ];
        $this->assertTrue($this->matcher->match($rules, $this->profile())['matched']);

        $rules[1] = $this->rule('business_country', ['GB']);
        $result = $this->matcher->match($rules, $this->profile());

        $this->assertFalse($result['matched']);
        $this->assertSame('business_country', $result['failed_on']);
    }

    /** OR within a dimension: any listed value is enough. */
    public function test_values_within_a_dimension_are_ored(): void
    {
        $result = $this->matcher->match(
            [$this->rule('business_country', ['GB', 'AE', 'US'])],
            $this->profile()
        );

        $this->assertTrue($result['matched']);
    }

    public function test_not_in_excludes(): void
    {
        $result = $this->matcher->match(
            [$this->rule('business_country', ['AE'], 'not_in')],
            $this->profile()
        );

        $this->assertFalse($result['matched']);
    }

    public function test_list_valued_dimensions_match_on_intersection(): void
    {
        $this->assertTrue($this->matcher->match(
            [$this->rule('interest', ['dental_care', 'fitness_training'])],
            $this->profile()
        )['matched']);

        $this->assertFalse($this->matcher->match(
            [$this->rule('interest', ['fitness_training'])],
            $this->profile()
        )['matched']);
    }

    /**
     * The rule that keeps targeting honest: if the inventory has no trustworthy
     * value for a targeted dimension, it does NOT match.
     */
    public function test_unknown_inventory_value_fails_the_match(): void
    {
        $result = $this->matcher->match(
            [$this->rule('industry', ['dental'])],
            $this->profile(['industry_slug' => null])
        );

        $this->assertFalse($result['matched'], 'Unresolved inventory must never satisfy a targeted campaign');
        $this->assertSame('industry', $result['failed_on']);
    }

    public function test_min_confidence_is_a_threshold_not_a_set(): void
    {
        $rules = [$this->rule('min_confidence', [0.75])];

        $this->assertTrue($this->matcher->match($rules, $this->profile(['confidence' => 0.75]))['matched']);
        $this->assertTrue($this->matcher->match($rules, $this->profile(['confidence' => 0.95]))['matched']);
        $this->assertFalse($this->matcher->match($rules, $this->profile(['confidence' => 0.50]))['matched']);
    }

    public function test_request_context_dimensions(): void
    {
        $rules = [$this->rule('visitor_country', ['AE'])];

        $this->assertTrue($this->matcher->match($rules, $this->profile(), ['visitor_country' => 'AE'])['matched']);
        $this->assertFalse($this->matcher->match($rules, $this->profile(), ['visitor_country' => 'GB'])['matched']);
    }

    /** A missing request attribute fails an inclusive rule but not an exclusion. */
    public function test_missing_request_attribute_behaviour(): void
    {
        $this->assertFalse(
            $this->matcher->match([$this->rule('device', ['mobile'])], $this->profile(), [])['matched']
        );

        $this->assertTrue(
            $this->matcher->match([$this->rule('device', ['mobile'], 'not_in')], $this->profile(), [])['matched']
        );
    }

    public function test_website_direct_pick_and_exclusion(): void
    {
        $this->assertTrue($this->matcher->match([$this->rule('website', [3])], $this->profile())['matched']);
        $this->assertFalse($this->matcher->match([$this->rule('website', [3], 'not_in')], $this->profile())['matched']);
    }

    public function test_supported_dimensions_are_documented(): void
    {
        $dimensions = AdTargetingMatcher::supportedDimensions();

        foreach (['business_country', 'industry', 'archetype', 'interest', 'visitor_country', 'device', 'min_confidence'] as $expected) {
            $this->assertContains($expected, $dimensions);
        }
    }
}
