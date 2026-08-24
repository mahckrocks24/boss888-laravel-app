<?php

namespace Tests\Feature\Ads;

use App\Engines\Ads\Services\InventoryProfileService;
use App\Engines\Ads\Support\AdInterestTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADS888 P0a — InventoryProfileService end-to-end.
 *
 * Exercises the full cascade against real table shapes: classify → parse
 * location → derive interests → persist with provenance.
 */
class InventoryProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InventoryProfileService::class);
    }

    /** Monotonic per-test counter so seeded slugs/subdomains stay unique. */
    private int $seq = 0;

    private function seedSite(array $workspace = [], array $website = []): array
    {
        $n = ++$this->seq;

        // `workspaces` requires name, slug and created_by (NOT NULL, no default),
        // and created_by carries an FK to users. Same seeding pattern as
        // tests/Feature/Infrastructure/SubdomainServiceTest.
        $userId = (int) DB::table('users')->insertGetId([
            'name'       => "Test User {$n}",
            'email'      => "ads888-test-{$n}-" . substr(md5((string) mt_rand()), 0, 8) . '@example.test',
            'password'   => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wsId = DB::table('workspaces')->insertGetId(array_merge([
            'name'          => "Test Workspace {$n}",
            'slug'          => "test-ws-{$n}-" . substr(md5((string) mt_rand()), 0, 8),
            'created_by'    => $userId,
            'business_name' => 'Test Business',
            'industry'      => 'dental clinic',
            'location'      => 'Dubai, UAE',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $workspace));

        $siteId = DB::table('websites')->insertGetId(array_merge([
            'workspace_id'      => $wsId,
            'name'              => "Test Site {$n}",
            'subdomain'         => "test-site-{$n}.levelupgrowth.io",
            'status'            => 'published',
            'template_industry' => null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ], $website));

        return [$wsId, $siteId];
    }

    public function test_profiles_a_site_end_to_end(): void
    {
        [, $siteId] = $this->seedSite();

        $profile = $this->service->profile($siteId);

        $this->assertNotNull($profile);
        $this->assertSame('dental', $profile['industry_slug']);
        $this->assertSame('medical', $profile['archetype']);
        $this->assertSame('AE', $profile['business_country']);
        $this->assertSame('Dubai', $profile['business_city']);
        $this->assertSame('classified', $profile['source']);
        $this->assertTrue($profile['sellable_for_targeting']);
        $this->assertNotEmpty($profile['iab_categories']);
    }

    public function test_industry_default_interests_are_applied(): void
    {
        [, $siteId] = $this->seedSite();

        $profile = $this->service->profile($siteId);

        $this->assertContains('dental_care', $profile['interests']);

        foreach ($profile['interests'] as $code) {
            $this->assertTrue(
                AdInterestTaxonomy::isValid($code),
                "Stored interest '{$code}' is not in the controlled vocabulary"
            );
        }
    }

    /**
     * Regression: a single incidental keyword used to promote a whole interest.
     * A dental site must not acquire unrelated interests from one stray word in
     * its copy — a spurious interest sells an advertiser an audience that is not
     * there.
     */
    public function test_incidental_single_keyword_does_not_promote_an_interest(): void
    {
        [$wsId, $siteId] = $this->seedSite();

        // One — and only one — 'seo_search' keyword anywhere in the site's copy.
        DB::table('websites')->where('id', $siteId)->update([
            'template_variables' => json_encode([
                'services_intro'   => 'We rank highly on any search engine for dental implants.',
                'meta_description' => 'A dental clinic in Dubai.',
            ]),
        ]);

        $profile = $this->service->profile($siteId, force: true);

        $this->assertContains('dental_care', $profile['interests']);
        $this->assertNotContains(
            'seo_search',
            $profile['interests'],
            'A single incidental keyword must not promote an interest'
        );
    }

    public function test_unclassifiable_site_is_stored_as_unknown_and_is_not_sellable(): void
    {
        [, $siteId] = $this->seedSite(['industry' => null, 'business_name' => null, 'name' => 'zzz', 'location' => null]);

        $profile = $this->service->profile($siteId);

        $this->assertSame('unknown', $profile['source']);
        $this->assertNull($profile['industry_slug']);
        $this->assertSame('0.00', number_format((float) $profile['confidence'], 2));
        $this->assertFalse(
            $profile['sellable_for_targeting'],
            'Unclassified inventory must never be sellable to a targeted campaign'
        );
    }

    public function test_admin_override_survives_reprofiling(): void
    {
        [, $siteId] = $this->seedSite();
        $this->service->profile($siteId);

        // Simulate an admin override.
        DB::table('ad_inventory_profiles')
            ->where('website_id', $siteId)
            ->update(['source' => 'explicit', 'industry_slug' => 'gym', 'confidence' => 1.00]);

        $profile = $this->service->profile($siteId);

        $this->assertSame('gym', $profile['industry_slug'], 'Automation must not overwrite an admin override');
        $this->assertSame('explicit', $profile['source']);
    }

    public function test_force_overrides_an_admin_override(): void
    {
        [, $siteId] = $this->seedSite();
        $this->service->profile($siteId);

        DB::table('ad_inventory_profiles')
            ->where('website_id', $siteId)
            ->update(['source' => 'explicit', 'industry_slug' => 'gym', 'confidence' => 1.00]);

        $profile = $this->service->profile($siteId, force: true);

        $this->assertSame('dental', $profile['industry_slug']);
        $this->assertSame('classified', $profile['source']);
    }

    public function test_profiling_is_idempotent(): void
    {
        [, $siteId] = $this->seedSite();

        $first  = $this->service->profile($siteId);
        $second = $this->service->profile($siteId);

        $this->assertSame($first['industry_slug'], $second['industry_slug']);
        $this->assertSame($first['business_country'], $second['business_country']);
        $this->assertSame($first['source'], $second['source']);
        $this->assertSame(1, DB::table('ad_inventory_profiles')->where('website_id', $siteId)->count());
    }

    public function test_missing_website_returns_null_rather_than_throwing(): void
    {
        $this->assertNull($this->service->profile(999999));
    }

    public function test_unclassified_report_lists_only_unsellable_inventory(): void
    {
        [, $goodId] = $this->seedSite();
        [, $badId]  = $this->seedSite(['industry' => null, 'business_name' => null, 'name' => 'zzz']);

        $this->service->profile($goodId);
        $this->service->profile($badId);

        $ids = array_column($this->service->unclassified(), 'website_id');

        $this->assertContains($badId, $ids);
        $this->assertNotContains($goodId, $ids);
    }

    public function test_prune_removes_profiles_for_deleted_websites(): void
    {
        [, $siteId] = $this->seedSite();
        $this->service->profile($siteId);

        DB::table('websites')->where('id', $siteId)->delete();

        $this->assertSame(1, $this->service->prune());
        $this->assertNull($this->service->read($siteId));
    }

    public function test_summary_counts_sellable_inventory(): void
    {
        [, $goodId] = $this->seedSite();
        [, $badId]  = $this->seedSite(['industry' => null, 'business_name' => null, 'name' => 'zzz']);

        $this->service->profile($goodId);
        $this->service->profile($badId);

        $summary = $this->service->summary();

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['sellable_for_targeting']);
        $this->assertSame(1, $summary['unsellable']);
    }

    public function test_evidence_explains_the_classification(): void
    {
        [, $siteId] = $this->seedSite();

        $profile = $this->service->profile($siteId);

        $this->assertArrayHasKey('industry', $profile['evidence']);
        $this->assertArrayHasKey('location', $profile['evidence']);
        $this->assertArrayHasKey('interests', $profile['evidence']);
        $this->assertSame('workspaces.industry', $profile['evidence']['industry']['industry_from']);
    }
}
