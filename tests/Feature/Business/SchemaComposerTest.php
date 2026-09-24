<?php

namespace Tests\Feature\Business;

use App\Core\Business\SchemaComposer;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * K5 (2026-09-25): one connected entity graph, from canonical truth only.
 *
 * What this pins, against the measured state of 2026-09-24: every Builder page
 * emitted the same five-field blob with @type hardcoded to LocalBusiness
 * whatever the industry, and all 202 article blobs lacked @id, image and
 * mainEntityOfPage, so no node could reference another.
 *
 * And the rule that matters more than the shape: a fact the business is not
 * allowed to publish does not appear in the graph.
 */
class SchemaComposerTest extends TestCase
{
    private int $user;
    private int $ws;
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = (int) DB::table('users')->insertGetId([
            'name' => 'K5 schema', 'email' => 'k5-' . uniqid() . '@example.test',
            'password' => password_hash('K5-Schema-Password-1', PASSWORD_BCRYPT),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'K5 Workspace', 'slug' => 'k5-' . uniqid(), 'timezone' => 'UTC',
            'created_by' => $this->user, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('articles')->where('workspace_id', $this->ws)->delete();
        if ($this->sites) {
            DB::table('websites')->whereIn('id', $this->sites)->delete();
        }
        DB::table('businesses')->where('workspace_id', $this->ws)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        DB::table('users')->where('id', $this->user)->delete();
        parent::tearDown();
    }

    private function composer(): SchemaComposer
    {
        return app(SchemaComposer::class);
    }

    private function makeSite(array $business = [], array $site = []): int
    {
        $b = Business::create(array_merge([
            'workspace_id' => $this->ws, 'name' => 'K5 Co',
            'slug' => 'k5co-' . uniqid(), 'is_default' => 0, 'sort_order' => 0,
        ], $business));

        $id = (int) DB::table('websites')->insertGetId(array_merge([
            'workspace_id' => $this->ws, 'business_id' => $b->id, 'name' => 'K5 Site',
            'subdomain' => 'k5-' . uniqid() . '.levelupgrowth.io', 'status' => 'published',
            'domain_verified' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $site));
        $this->sites[] = $id;

        return $id;
    }

    private function nodeOfType(array $graph, string $type): ?array
    {
        foreach ($graph['@graph'] ?? [] as $node) {
            if (($node['@type'] ?? null) === $type) {
                return $node;
            }
        }

        return null;
    }

    // ---- the type is no longer a guess ----------------------------------

    /** @dataProvider industries */
    public function test_the_most_specific_correct_type_is_chosen(string $industry, string $expected): void
    {
        $this->assertSame($expected, $this->composer()->typeFor($industry));
    }

    public static function industries(): array
    {
        return [
            'travel agency' => ['travel_agency', 'TravelAgency'],
            'restaurant slug' => ['restaurant_counter', 'Restaurant'],
            'bakery' => ['bakery', 'Bakery'],
            'dental' => ['dental', 'Dentist'],
            'gym' => ['gym', 'ExerciseGym'],
            'news' => ['news_channel', 'NewsMediaOrganization'],
            'it services' => ['it_services', 'ProfessionalService'],
            'marketing agency' => ['marketing_agency', 'ProfessionalService'],
            'real estate' => ['real_estate_agency', 'RealEstateAgent'],
            'legal' => ['legal', 'LegalService'],
            'unknown local trade' => ['artisan cheesemonger', 'LocalBusiness'],
            'nothing known' => ['', 'Organization'],
        ];
    }

    public function test_an_explicit_schema_type_overrides_the_mapping(): void
    {
        $this->assertSame('Winery', $this->composer()->typeFor('restaurant', 'Winery'));
    }

    public function test_the_type_is_not_hardcoded_localbusiness_for_every_site(): void
    {
        $travel = $this->makeSite(['industry' => 'travel_agency']);
        $news = $this->makeSite(['industry' => 'news_channel']);

        $this->assertSame('TravelAgency', $this->nodeOfType($this->composer()->forWebsite($travel), 'TravelAgency')['@type']);
        $this->assertNotNull($this->nodeOfType($this->composer()->forWebsite($news), 'NewsMediaOrganization'));
    }

    // ---- the graph is connected -----------------------------------------

    public function test_nodes_carry_stable_ids_and_reference_each_other(): void
    {
        $site = $this->makeSite(['industry' => 'bakery'], ['subdomain' => 'k5graph.levelupgrowth.io']);
        $base = 'https://k5graph.levelupgrowth.io';

        $graph = $this->composer()->forWebsite($site, ['url' => $base . '/about', 'name' => 'About']);

        $org = $this->nodeOfType($graph, 'Bakery');
        $website = $this->nodeOfType($graph, 'WebSite');
        $page = $this->nodeOfType($graph, 'WebPage');

        $this->assertSame($base . '/#organization', $org['@id']);
        $this->assertSame($base . '/#website', $website['@id']);
        $this->assertSame($base . '/about#webpage', $page['@id']);
        $this->assertSame(['@id' => $base . '/#organization'], $website['publisher'], 'the site is published BY the organization');
        $this->assertSame(['@id' => $base . '/#website'], $page['isPartOf']);
        $this->assertSame(['@id' => $base . '/#organization'], $page['about']);
    }

    public function test_an_article_references_the_same_organization_rather_than_redeclaring_it(): void
    {
        $site = $this->makeSite(['industry' => 'bakery'], ['subdomain' => 'k5art.levelupgrowth.io']);
        $base = 'https://k5art.levelupgrowth.io';
        $articleId = (int) DB::table('articles')->insertGetId([
            'workspace_id' => $this->ws, 'website_id' => $site, 'title' => 'Sourdough',
            'slug' => 'sourdough-' . uniqid(), 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $graph = $this->composer()->forArticle($articleId, [
            'headline' => 'Sourdough', 'url' => $base . '/blog/sourdough',
            'datePublished' => '2026-09-01T00:00:00+00:00', 'image' => $base . '/hero.jpg',
        ], [['q' => 'How long does it keep?', 'a' => 'Three days.']]);

        $article = $this->nodeOfType($graph, 'Article');

        $this->assertSame($base . '/blog/sourdough#article', $article['@id'], 'an article must be addressable');
        $this->assertSame(['@id' => $base . '/#organization'], $article['publisher'], 'by reference, not a second anonymous Organization');
        $this->assertSame(['@id' => $base . '/#organization'], $article['author']);
        $this->assertSame(['@id' => $base . '/blog/sourdough#webpage'], $article['mainEntityOfPage']);
        $this->assertNotEmpty($article['image']);
        $this->assertNotNull($this->nodeOfType($graph, 'FAQPage'));
        $this->assertNotNull($this->nodeOfType($graph, 'Bakery'), 'the organization node travels with the article');
    }

    public function test_an_article_with_no_resolvable_host_claims_nothing_addressable(): void
    {
        $this->makeSite([], ['subdomain' => 'k5one.levelupgrowth.io']);
        $this->makeSite([], ['subdomain' => 'k5two.levelupgrowth.io']);
        $articleId = (int) DB::table('articles')->insertGetId([
            'workspace_id' => $this->ws, 'website_id' => null, 'title' => 'Untagged',
            'slug' => 'untagged-' . uniqid(), 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $graph = $this->composer()->forArticle($articleId, ['headline' => 'Untagged']);
        $article = $this->nodeOfType($graph, 'Article');

        $this->assertArrayNotHasKey('@id', $article, 'no host means no stable id to invent');
        $this->assertSame('Organization', $article['publisher']['@type']);
        $this->assertArrayNotHasKey('url', $article['publisher'], 'and no url for a site we could not identify');
    }

    // ---- provenance decides what is published ---------------------------

    public function test_a_withheld_candidate_never_reaches_the_graph(): void
    {
        $site = $this->makeSite(['industry' => 'bakery', 'phone' => '(512) 555-0178', 'email' => 'fake@example.test']);
        $b = Business::where('workspace_id', $this->ws)->first();
        $b->stampIdentitySource('phone', 'ai_generated');
        $b->stampIdentitySource('email', 'proposed');
        $b->save();

        $org = $this->nodeOfType($this->composer()->forWebsite($site), 'Bakery');

        $this->assertArrayNotHasKey('telephone', $org, 'AI-generated template seed data is not a business fact');
        $this->assertArrayNotHasKey('email', $org, 'an unconfirmed proposal is not a business fact');
    }

    public function test_a_confirmed_fact_is_published(): void
    {
        $site = $this->makeSite([
            'industry' => 'bakery', 'phone' => '+44 161 123 4567',
            'address_json' => ['streetAddress' => '1 High St', 'addressLocality' => 'Manchester'],
            'sameas_json' => ['https://facebook.com/k5'],
        ]);
        $b = Business::where('workspace_id', $this->ws)->first();
        foreach (['phone', 'address_json', 'sameas_json'] as $field) {
            $b->stampIdentitySource($field, 'owner_stated');
        }
        $b->save();

        $org = $this->nodeOfType($this->composer()->forWebsite($site), 'Bakery');

        $this->assertSame('+44 161 123 4567', $org['telephone']);
        $this->assertSame('PostalAddress', $org['address']['@type']);
        $this->assertSame('Manchester', $org['address']['addressLocality']);
        $this->assertSame(['https://facebook.com/k5'], $org['sameAs']);
    }

    public function test_an_organization_that_is_not_a_place_carries_no_address(): void
    {
        $site = $this->makeSite([
            'industry' => 'news_channel',
            'address_json' => ['addressLocality' => 'Manila'],
        ]);
        $b = Business::where('workspace_id', $this->ws)->first();
        $b->stampIdentitySource('address_json', 'owner_stated');
        $b->save();

        $org = $this->nodeOfType($this->composer()->forWebsite($site), 'NewsMediaOrganization');

        $this->assertArrayNotHasKey('address', $org, 'a news organization is not a storefront');
    }

    public function test_free_text_opening_hours_are_not_published_as_a_specification(): void
    {
        $site = $this->makeSite([
            'industry' => 'restaurant',
            'opening_hours_json' => ['raw' => 'Tue-Thu 11:30 AM-9:30 PM'],
        ]);
        $b = Business::where('workspace_id', $this->ws)->first();
        $b->stampIdentitySource('opening_hours_json', 'owner_stated');
        $b->save();

        $org = $this->nodeOfType($this->composer()->forWebsite($site), 'Restaurant');

        $this->assertArrayNotHasKey('openingHoursSpecification', $org, 'guessing the structure of a free-text blob invents a fact');
    }

    // ---- nodes appear only when there is something to say ----------------

    public function test_services_come_from_the_structured_list_only(): void
    {
        $site = $this->makeSite(['industry' => 'bakery', 'services_json' => ['Wedding cakes', 'Sourdough classes']]);
        $base = 'https://' . DB::table('websites')->where('id', $site)->value('subdomain');

        $graph = $this->composer()->forWebsite($site);
        $services = array_values(array_filter($graph['@graph'], fn ($n) => ($n['@type'] ?? '') === 'Service'));

        $this->assertCount(2, $services);
        $this->assertSame($base . '/#service/wedding-cakes', $services[0]['@id']);
        $this->assertSame(['@id' => $base . '/#organization'], $services[0]['provider']);
    }

    public function test_no_services_list_means_no_service_nodes(): void
    {
        $site = $this->makeSite(['industry' => 'bakery']);

        $this->assertNull($this->nodeOfType($this->composer()->forWebsite($site), 'Service'));
    }

    public function test_a_single_crumb_is_not_a_breadcrumb(): void
    {
        $site = $this->makeSite(['industry' => 'bakery']);
        $base = 'https://' . DB::table('websites')->where('id', $site)->value('subdomain');

        $one = $this->composer()->forWebsite($site, ['url' => $base . '/about', 'breadcrumb' => [['name' => 'Home', 'url' => $base]]]);
        $two = $this->composer()->forWebsite($site, ['url' => $base . '/about', 'breadcrumb' => [
            ['name' => 'Home', 'url' => $base], ['name' => 'About', 'url' => $base . '/about'],
        ]]);

        $this->assertNull($this->nodeOfType($one, 'BreadcrumbList'));
        $crumbs = $this->nodeOfType($two, 'BreadcrumbList');
        $this->assertCount(2, $crumbs['itemListElement']);
        $this->assertSame(1, $crumbs['itemListElement'][0]['position']);
    }

    public function test_a_site_with_no_usable_host_composes_nothing(): void
    {
        $site = $this->makeSite(['industry' => 'bakery'], ['subdomain' => '', 'domain' => '', 'custom_domain' => '']);

        $this->assertSame([], $this->composer()->forWebsite($site), 'no stable base means no stable ids');
    }
}
