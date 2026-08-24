<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\LocationParser;
use Tests\TestCase;

/**
 * ADS888 P0a — LocationParser.
 *
 * The cases below are drawn from the ACTUAL values in `workspaces.location` on
 * staging, not invented ones. The parser exists because those values are free
 * text; these tests exist to prove it never invents a country it cannot justify.
 */
class LocationParserTest extends TestCase
{
    private LocationParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new LocationParser();
    }

    /** Real staging value: workspace 10, 11, 12, 13, 14, 15, 16. */
    public function test_parses_city_and_country(): void
    {
        $r = $this->parser->parse('Dubai, UAE');

        $this->assertSame('AE', $r['country']);
        $this->assertSame('Dubai', $r['city']);
        $this->assertSame(LocationParser::CONFIDENCE_EXPLICIT_FULL, $r['confidence']);
    }

    /** Real staging value: workspace 2 (chef-red). */
    public function test_parses_region_country_and_service_areas(): void
    {
        $r = $this->parser->parse('New Jersey, USA (serving NJ, NY, CT)');

        $this->assertSame('US', $r['country']);
        $this->assertContains('New Jersey', $r['service_areas']);
        $this->assertContains('New York', $r['service_areas'], 'US state abbreviations must be expanded');
        $this->assertContains('Connecticut', $r['service_areas']);
        $this->assertGreaterThanOrEqual(LocationParser::CONFIDENCE_EXPLICIT_COUNTRY, $r['confidence']);
    }

    /** Real staging value: workspace 26 (AMG Travel). */
    public function test_parses_city_region_country(): void
    {
        $r = $this->parser->parse('Santa Rosa, Laguna, Philippines');

        $this->assertSame('PH', $r['country']);
        $this->assertSame('Santa Rosa', $r['city']);
        $this->assertSame('Laguna', $r['region']);
        $this->assertSame(LocationParser::CONFIDENCE_EXPLICIT_FULL, $r['confidence']);
    }

    public function test_infers_country_from_known_city_at_lower_confidence(): void
    {
        $r = $this->parser->parse('Dubai');

        $this->assertSame('AE', $r['country']);
        $this->assertSame(
            LocationParser::CONFIDENCE_CITY_INFERRED,
            $r['confidence'],
            'An inferred country must score lower than an explicit one'
        );
    }

    /**
     * The single most important behaviour in this class: refuse to guess.
     * A wrong country silently sells an advertiser the wrong audience.
     */
    public function test_returns_zero_confidence_rather_than_guessing(): void
    {
        foreach (['', null, 'somewhere nice', 'Flat 4, The Old Mill'] as $input) {
            $r = $this->parser->parse($input);

            $this->assertNull($r['country'], sprintf('Input %s must not resolve a country', var_export($input, true)));
            $this->assertSame(LocationParser::CONFIDENCE_NONE, $r['confidence']);
        }
    }

    public function test_us_state_abbreviation_is_never_read_as_a_country(): void
    {
        // "NJ" is a US state, not a country. Treating a bare two-letter token as
        // ISO-3166 would have made this resolve to a country named NJ.
        $r = $this->parser->parse('Newark, NJ');

        $this->assertNotSame('NJ', $r['country']);
        $this->assertSame(LocationParser::CONFIDENCE_NONE, $r['confidence']);
    }

    public function test_non_service_area_parenthetical_is_not_treated_as_coverage(): void
    {
        $r = $this->parser->parse('Dubai, UAE (est. 2019)');

        $this->assertSame('AE', $r['country']);
        $this->assertSame([], $r['service_areas'], 'Only "serving/covers" parentheticals are coverage lists');
    }

    public function test_country_aliases_resolve(): void
    {
        foreach ([
            'Dubai, United Arab Emirates' => 'AE',
            'London, UK'                  => 'GB',
            'London, United Kingdom'      => 'GB',
            'Austin, United States'       => 'US',
            'Manila, The Philippines'     => 'PH',
        ] as $input => $expected) {
            $this->assertSame($expected, $this->parser->parse($input)['country'], "Failed on: {$input}");
        }
    }

    /**
     * Regression: "New Jersey, USA" previously landed the state in `city`,
     * leaving `region` null — which silently excluded the site from any
     * campaign targeting business_region.
     */
    public function test_us_state_full_name_resolves_to_region_not_city(): void
    {
        $r = $this->parser->parse('New Jersey, USA (serving NJ, NY, CT)');

        $this->assertSame('US', $r['country']);
        $this->assertSame('New Jersey', $r['region'], 'A US state must populate region');
        $this->assertNull($r['city'], 'A US state must not be stored as a city');
    }

    public function test_us_state_abbreviation_after_country_also_resolves_to_region(): void
    {
        $r = $this->parser->parse('NJ, USA');

        $this->assertSame('US', $r['country']);
        $this->assertSame('New Jersey', $r['region'], 'Abbreviations must expand to the canonical name');
    }

    public function test_city_region_country_still_assigns_city_correctly(): void
    {
        // Guards against the state fix over-reaching into the 3-part form.
        $r = $this->parser->parse('Newark, New Jersey, USA');

        $this->assertSame('US', $r['country']);
        $this->assertSame('Newark', $r['city']);
        $this->assertSame('New Jersey', $r['region']);
    }

    public function test_evidence_is_always_recorded(): void
    {
        $r = $this->parser->parse('Dubai, UAE');

        $this->assertArrayHasKey('raw', $r['evidence']);
        $this->assertSame('Dubai, UAE', $r['evidence']['raw']);
    }
}
