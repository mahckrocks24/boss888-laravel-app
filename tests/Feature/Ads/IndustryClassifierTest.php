<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\IndustryClassifier;
use App\Engines\Ads\Support\AdIndustryTaxonomy;
use Tests\TestCase;

/**
 * ADS888 P0a — IndustryClassifier.
 *
 * The free-text inputs below are the ACTUAL values in `workspaces.industry` on
 * staging. If the classifier cannot handle these, it cannot handle production.
 */
class IndustryClassifierTest extends TestCase
{
    private IndustryClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new IndustryClassifier();
    }

    public function test_valid_template_industry_wins_and_scores_high(): void
    {
        $r = $this->classifier->classify(
            ['template_industry' => 'restaurant'],
            ['industry' => 'something else entirely']
        );

        $this->assertSame('restaurant', $r['industry_slug']);
        $this->assertSame('template', $r['source']);
        $this->assertSame(IndustryClassifier::CONFIDENCE_TEMPLATE, $r['confidence']);
        $this->assertSame('hospitality_stay', $r['archetype']);
    }

    /**
     * Regression guard for a real defect in the data: website 62 carries
     * template_industry='travel', which is NOT one of the 31 slugs
     * (`travel_agency` is). It must fall through, not poison the profile.
     */
    public function test_invalid_template_industry_falls_through_and_is_recorded(): void
    {
        $r = $this->classifier->classify(
            ['template_industry' => 'travel'],
            ['industry' => 'Travel & Tours', 'business_name' => 'AMG Global Travel & Tours']
        );

        $this->assertNotSame('travel', $r['industry_slug'], 'An unknown slug must never be stored');
        $this->assertSame('travel_agency', $r['industry_slug']);
        $this->assertSame('classified', $r['source']);
        $this->assertSame('travel', $r['evidence']['rejected_template_industry']);
    }

    /** @dataProvider realStagingIndustries */
    public function test_classifies_real_staging_free_text(string $raw, ?string $expectedSlug, string $expectedSource): void
    {
        $r = $this->classifier->classify(['template_industry' => null], ['industry' => $raw]);

        $this->assertSame($expectedSlug, $r['industry_slug'], "Failed on: {$raw}");
        $this->assertSame($expectedSource, $r['source'], "Failed on: {$raw}");
    }

    public static function realStagingIndustries(): array
    {
        return [
            'ws10' => ['aesthetic clinic',           'aesthetic_clinic',   'classified'],
            'ws11' => ['marketing agency',           'marketing_agency',   'classified'],
            'ws12' => ['news media',                 'news_channel',       'classified'],
            'ws13' => ['dental clinic',              'dental',             'classified'],
            'ws14' => ['pet clinic',                 'pet_services',       'classified'],
            'ws15' => ['fitness gym',                'gym',                'classified'],
            'ws16' => ['shelving installation',      'home_services',      'classified'],
            'ws26' => ['Travel & Tours',             'travel_agency',      'classified'],
            'ws9'  => ['digital marketing agency',   'marketing_agency',   'classified'],
            'ws2'  => ['private chef',               'restaurant',         'classified'],
        ];
    }

    /**
     * "dental clinic" contains "clinic", which also matches medical_clinic.
     * Ordering must resolve the specific industry, not the generic one.
     */
    public function test_specific_industry_beats_generic_on_overlapping_keywords(): void
    {
        $this->assertSame('dental', $this->classifier->classify([], ['industry' => 'dental clinic'])['industry_slug']);
        $this->assertSame('aesthetic_clinic', $this->classifier->classify([], ['industry' => 'aesthetic clinic'])['industry_slug']);
        $this->assertSame('pet_services', $this->classifier->classify([], ['industry' => 'pet clinic'])['industry_slug']);
        $this->assertSame('medical_clinic', $this->classifier->classify([], ['industry' => 'walk-in clinic'])['industry_slug']);
    }

    public function test_unresolvable_text_returns_unknown_and_is_not_sellable(): void
    {
        $r = $this->classifier->classify([], ['industry' => 'zzzz qqqq']);

        $this->assertNull($r['industry_slug']);
        $this->assertNull($r['archetype']);
        $this->assertSame('unknown', $r['source']);
        $this->assertSame(IndustryClassifier::CONFIDENCE_UNKNOWN, $r['confidence']);
        $this->assertFalse(IndustryClassifier::isSellableForTargeting($r['source'], $r['confidence']));
    }

    public function test_empty_input_returns_unknown(): void
    {
        $r = $this->classifier->classify([], []);

        $this->assertSame('unknown', $r['source']);
        $this->assertFalse(IndustryClassifier::isSellableForTargeting($r['source'], $r['confidence']));
    }

    public function test_admin_override_outranks_everything(): void
    {
        $r = $this->classifier->classify(
            ['template_industry' => 'restaurant'],
            ['industry' => 'dental clinic'],
            'gym'
        );

        $this->assertSame('gym', $r['industry_slug']);
        $this->assertSame('explicit', $r['source']);
        $this->assertSame(IndustryClassifier::CONFIDENCE_EXPLICIT, $r['confidence']);
    }

    public function test_invalid_admin_override_is_ignored(): void
    {
        $r = $this->classifier->classify(
            ['template_industry' => 'restaurant'],
            [],
            'not_a_real_slug'
        );

        $this->assertSame('restaurant', $r['industry_slug'], 'A bogus override must not be honoured');
        $this->assertSame('template', $r['source']);
    }

    /**
     * The commercial rule: archetype-only knowledge is real but weaker, and is
     * NOT a good enough basis to charge an advertiser who bought a specific
     * industry.
     */
    public function test_archetype_only_resolution_is_below_the_paid_targeting_threshold(): void
    {
        $this->assertLessThan(
            IndustryClassifier::MIN_CONFIDENCE_FOR_PAID_TARGETING,
            IndustryClassifier::CONFIDENCE_CLASSIFIED_ARCHETYPE
        );

        $this->assertFalse(IndustryClassifier::isSellableForTargeting(
            'classified',
            IndustryClassifier::CONFIDENCE_CLASSIFIED_ARCHETYPE
        ));
    }

    public function test_every_industry_maps_to_a_valid_archetype_and_iab_category(): void
    {
        foreach (array_keys(AdIndustryTaxonomy::INDUSTRIES) as $slug) {
            $archetype = AdIndustryTaxonomy::archetypeForIndustry($slug);

            $this->assertNotNull($archetype, "Industry {$slug} has no archetype");
            $this->assertTrue(AdIndustryTaxonomy::isValidArchetype($archetype), "Industry {$slug} → bogus archetype {$archetype}");
            $this->assertNotEmpty(AdIndustryTaxonomy::iabFor($slug), "Industry {$slug} has no IAB mapping");
        }
    }

    /**
     * Guards the difference from TemplateArchetypes::archetypeOf(), which
     * defaults an unknown slug to professional_advisory. For advertising that
     * default would sell a bakery to a law-firm campaign.
     */
    public function test_unknown_slug_yields_null_archetype_not_a_default(): void
    {
        $this->assertNull(AdIndustryTaxonomy::archetypeForIndustry('not_a_real_slug'));
        $this->assertNull(AdIndustryTaxonomy::archetypeForIndustry(null));
    }
}
