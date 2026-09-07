<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\MetaDescriptionTruth as M;
use Tests\TestCase;

/** RISK-0128 (2026-09-07, DEC-0041): the meta description must be truthful to the brief's geography for any geography. */
class MetaDescriptionTruthTest extends TestCase
{
    private const DEFAULT = "Ember & Bean — Dubai Marina's neighbourhood coffee house. Third-wave beans roasted weekly.";

    public function test_a_uk_brief_never_keeps_a_dubai_description(): void
    {
        $out = M::resolve('Experience the warmth of our artisan cafe in Dubai, offering roasted coffee and fresh pastries.', self::DEFAULT, 'Harbour Sourdough', 'cafe', 'Sourdough loaves and pastries', 'Bristol, United Kingdom');
        $this->assertStringNotContainsString('Dubai', $out);
        $this->assertStringContainsString('Bristol, United Kingdom', $out);
        $this->assertSame('Harbour Sourdough — Sourdough loaves and pastries in Bristol, United Kingdom.', $out);
    }

    public function test_a_manchester_brief_keeps_manchester_consistent_copy(): void
    {
        $in = 'Experience artisan baking at its finest with our sourdough, pastries, and custom cakes in Manchester.';
        $this->assertSame($in, M::resolve($in, self::DEFAULT, 'Northern Crumb', 'cafe', '', 'Manchester, United Kingdom'));
    }

    public function test_a_dubai_brief_may_legitimately_say_dubai(): void
    {
        $in = 'Palm Bakes brings warm artisan bread to Dubai Marina every morning.';
        $this->assertSame($in, M::resolve($in, self::DEFAULT, 'Palm Bakes', 'cafe', '', 'Dubai Marina, Dubai, United Arab Emirates'));
    }

    public function test_another_geography_survives_and_a_foreign_place_is_replaced(): void
    {
        $in = 'Maple Crust serves the finest pastries across Toronto and the GTA.';
        $this->assertStringContainsString('Toronto', M::resolve($in, self::DEFAULT, 'Maple Crust', 'cafe', '', 'Toronto, Canada'));
        $out = M::resolve('Crafting exquisite custom cakes for every celebration in Dubai.', self::DEFAULT, 'Maple Crust', 'cafe', 'Custom cakes and dessert tables', 'Toronto, Canada');
        $this->assertStringNotContainsString('Dubai', $out);
        $this->assertStringContainsString('in Toronto, Canada.', $out);
    }

    public function test_the_template_default_and_an_empty_value_are_never_kept(): void
    {
        $this->assertStringNotContainsString('Dubai', M::resolve(self::DEFAULT, self::DEFAULT, 'Harbour Sourdough', 'cafe', '', 'Bristol, United Kingdom'));
        $this->assertSame('Harbour Sourdough — cafe in Bristol, United Kingdom.', M::resolve('', self::DEFAULT, 'Harbour Sourdough', 'cafe', '', 'Bristol, United Kingdom'));
    }

    public function test_no_location_means_no_place_is_ever_invented(): void
    {
        $out = M::resolve('Our artisan cafe in Dubai offers roasted coffee.', self::DEFAULT, 'Nameless Cafe', 'cafe', '', '');
        $this->assertSame('Nameless Cafe — cafe.', $out);
        $this->assertSame('Fine pastries baked daily by hand.', M::resolve('Fine pastries baked daily by hand.', self::DEFAULT, 'Nameless Cafe', 'cafe', '', ''));
    }

    public function test_business_name_places_and_lowercase_in_are_not_false_alarms(): void
    {
        $this->assertTrue(M::placeTruthful('Manchester Bakehouse bakes in Manchester every day.', 'Manchester, United Kingdom', 'Manchester Bakehouse'));
        $this->assertTrue(M::placeTruthful('Croissants laminated in-house and served in the heart of Manchester.', 'Manchester, United Kingdom'));
        $this->assertFalse(M::placeTruthful('A neighbourhood coffee house in Dubai Marina.', 'Manchester, United Kingdom'));
        $this->assertTrue(M::placeTruthful('Bakes in Toronto and across the GTA.', 'Toronto, GTA, Canada'));
    }

    public function test_city_and_country_come_from_the_brief_or_stay_empty(): void
    {
        $this->assertSame('Manchester', M::cityOf('Manchester, United Kingdom'));
        $this->assertSame('United Kingdom', M::countryOf('Manchester, United Kingdom'));
        $this->assertSame('Toronto', M::cityOf('Toronto, Canada'));
        $this->assertSame('', M::countryOf('Manchester'));
        $this->assertSame('', M::cityOf(''));
        $this->assertSame('', M::countryOf(''));
    }

    public function test_the_derived_description_is_capped_at_160_characters(): void
    {
        $long = str_repeat('Custom wedding cakes, birthday cakes, dessert tables and celebration bakes ', 3);
        $out = M::resolve('', '', 'Maple Crust', 'cafe', $long, 'Toronto, Canada');
        $this->assertLessThanOrEqual(160, mb_strlen($out));
        $this->assertStringEndsWith('in Toronto, Canada.', $out);
    }
    private function venueManifest(): array
    {
        return [
            'business_name'   => ['default' => 'Aurora Event Studio'],
            'contact_address' => ['default' => 'Unit 22, Alserkal Avenue, Al Quoz 1, Dubai'],
            'meta_description'=> ['default' => 'Aurora Event Studio — a Dubai-based luxury event production and wedding planning studio.'],
            'footer_tagline'  => ['default' => 'Aurora Event Studio — luxury events, fully produced, in Dubai.'],
            'venue_1'         => ['default' => 'The Lowry Hotel'],
            'venue_7'         => ['default' => 'Dubai Opera'],
            'blog_1_title'    => ['default' => 'What a Three-Day Wedding Actually Costs in Dubai'],
            'service_1_title' => ['default' => 'Bespoke Weddings'],
        ];
    }

    public function test_a_surviving_default_that_names_the_template_origin_is_blanked_for_another_geography(): void
    {
        $vars = ['venue_1' => 'The Lowry Hotel', 'venue_7' => 'Dubai Opera', 'blog_1_title' => 'What a Three-Day Wedding Actually Costs in Dubai', 'service_1_title' => 'Bespoke Weddings', 'footer_tagline' => 'Regress QA Cakes — custom cakes, Manchester.'];
        [$out, $blanked] = M::neutraliseSurvivingDefaults($vars, $this->venueManifest(), 'Manchester, United Kingdom', 'Regress QA Cakes');
        $this->assertSame('', $out['venue_7']);
        $this->assertSame('', $out['blog_1_title']);
        $this->assertSame('The Lowry Hotel', $out['venue_1'], 'a default with no origin place survives');
        $this->assertSame('Bespoke Weddings', $out['service_1_title']);
        $this->assertSame('Regress QA Cakes — custom cakes, Manchester.', $out['footer_tagline'], 'overwritten copy is never touched');
        $this->assertEqualsCanonicalizing(['venue_7', 'blog_1_title'], $blanked);
    }

    public function test_a_brief_in_the_template_origin_keeps_its_defaults(): void
    {
        $vars = ['venue_7' => 'Dubai Opera', 'blog_1_title' => 'What a Three-Day Wedding Actually Costs in Dubai'];
        [$out, $blanked] = M::neutraliseSurvivingDefaults($vars, $this->venueManifest(), 'Dubai, United Arab Emirates', 'Palm Events');
        $this->assertSame('Dubai Opera', $out['venue_7']);
        $this->assertSame([], $blanked);
    }

    public function test_origin_tokens_come_from_the_template_itself_not_from_a_list(): void
    {
        $tokens = M::originPlaceTokens($this->venueManifest());
        $this->assertContains('dubai', $tokens);
        $this->assertContains('alserkal', $tokens);
        $this->assertNotContains('aurora', $tokens, 'the sample business name is not a place');
        $this->assertNotContains('avenue', $tokens, 'generic address words are not places');
        $this->assertSame([], M::originPlaceTokens(['business_name' => ['default' => 'X']]), 'a manifest with no located defaults yields no origin');
    }
}

