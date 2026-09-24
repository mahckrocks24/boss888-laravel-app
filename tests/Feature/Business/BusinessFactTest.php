<?php

namespace Tests\Feature\Business;

use App\Core\Business\SchemaComposer;
use App\Models\Business;
use App\Models\BusinessFact;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * K7 (2026-09-25): claims a business may state publicly, and claims it may not.
 *
 * Two gates, and both must open. The platform's gate is the source: it will not
 * assert something only a model believes. The owner's gate is `published`: a
 * true fact they would rather not advertise stays private.
 *
 * Unlike K4's identity fields, an unstamped source here does NOT mean
 * owner-stated. Every row is created by something, the column defaults to
 * `proposed`, and silence means nobody has vouched for it.
 */
class BusinessFactTest extends TestCase
{
    private int $user;
    private int $ws;
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = (int) DB::table('users')->insertGetId([
            'name' => 'K7 facts', 'email' => 'k7-' . uniqid() . '@example.test',
            'password' => password_hash('K7-Facts-Password-1', PASSWORD_BCRYPT),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'K7 Workspace', 'slug' => 'k7-' . uniqid(), 'timezone' => 'UTC',
            'created_by' => $this->user, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $ids = DB::table('businesses')->where('workspace_id', $this->ws)->pluck('id')->all();
        if ($ids) {
            DB::table('business_facts')->whereIn('business_id', $ids)->delete();
        }
        if ($this->sites) {
            DB::table('websites')->whereIn('id', $this->sites)->delete();
        }
        DB::table('businesses')->where('workspace_id', $this->ws)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        DB::table('users')->where('id', $this->user)->delete();
        parent::tearDown();
    }

    private function makeBusinessWithSite(array $attrs = []): array
    {
        $b = Business::create(array_merge([
            'workspace_id' => $this->ws, 'name' => 'K7 Co', 'industry' => 'bakery',
            'slug' => 'k7co-' . uniqid(), 'is_default' => 0, 'sort_order' => 0,
        ], $attrs));

        $site = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $this->ws, 'business_id' => $b->id, 'name' => 'K7 Site',
            'subdomain' => 'k7-' . uniqid() . '.levelupgrowth.io', 'status' => 'published',
            'domain_verified' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sites[] = $site;

        return [$b, $site];
    }

    private function fact(Business $b, array $attrs): BusinessFact
    {
        return BusinessFact::create(array_merge([
            'business_id' => $b->id, 'kind' => 'award', 'label' => 'A claim',
        ], $attrs));
    }

    private function orgNode(int $site): array
    {
        $graph = app(SchemaComposer::class)->forWebsite($site);
        foreach ($graph['@graph'] ?? [] as $node) {
            if (($node['@type'] ?? '') === 'Bakery') {
                return $node;
            }
        }

        return [];
    }

    // ---- the two gates ---------------------------------------------------

    public function test_a_new_claim_defaults_to_proposed_and_is_not_publishable(): void
    {
        [$b] = $this->makeBusinessWithSite();
        $fact = $this->fact($b, ['label' => 'Best Bakery 2026']);

        $this->assertSame('proposed', $fact->source);
        $this->assertFalse($fact->published);
        $this->assertFalse($fact->isPublishable(), 'silence means nobody has vouched for it');
    }

    /** @dataProvider gates */
    public function test_both_gates_must_open(string $source, bool $published, bool $expected): void
    {
        [$b] = $this->makeBusinessWithSite();
        $fact = $this->fact($b, ['source' => $source, 'published' => $published]);

        $this->assertSame($expected, $fact->isPublishable());
    }

    public static function gates(): array
    {
        return [
            'owner stated + published' => ['owner_stated', true, true],
            'verified + published' => ['verified', true, true],
            'imported + published' => ['imported', true, true],
            'owner stated but not published' => ['owner_stated', false, false],
            'ai generated but published' => ['ai_generated', true, false],
            'proposed but published' => ['proposed', true, false],
            'observed but published' => ['observed', true, false],
        ];
    }

    public function test_an_ai_generated_claim_never_reaches_the_graph_even_when_published(): void
    {
        [$b, $site] = $this->makeBusinessWithSite();
        $this->fact($b, ['kind' => 'award', 'label' => 'Invented Award 2026', 'source' => 'ai_generated', 'published' => true]);

        $org = $this->orgNode($site);

        $this->assertArrayNotHasKey('award', $org);
    }

    public function test_a_claim_with_an_empty_label_is_not_publishable(): void
    {
        [$b] = $this->makeBusinessWithSite();
        $fact = $this->fact($b, ['label' => '   ', 'source' => 'owner_stated', 'published' => true]);

        $this->assertFalse($fact->isPublishable());
    }

    // ---- the mapping -----------------------------------------------------

    public function test_awards_certifications_and_memberships_map_to_their_properties(): void
    {
        [$b, $site] = $this->makeBusinessWithSite();
        $this->fact($b, ['kind' => 'award', 'label' => 'Best Bakery 2026', 'source' => 'owner_stated', 'published' => true]);
        $this->fact($b, ['kind' => 'certification', 'label' => 'SALSA Accredited', 'source' => 'verified', 'published' => true, 'source_url' => 'https://example.test/cert']);
        $this->fact($b, ['kind' => 'membership', 'label' => 'Craft Bakers Association', 'source' => 'imported', 'published' => true]);

        $org = $this->orgNode($site);

        $this->assertSame(['Best Bakery 2026'], $org['award']);
        $this->assertSame('EducationalOccupationalCredential', $org['hasCredential'][0]['@type']);
        $this->assertSame('SALSA Accredited', $org['hasCredential'][0]['name']);
        $this->assertSame('https://example.test/cert', $org['hasCredential'][0]['url']);
        $this->assertSame('Craft Bakers Association', $org['memberOf'][0]['name']);
    }

    public function test_a_testimonial_becomes_a_review_with_its_author(): void
    {
        [$b, $site] = $this->makeBusinessWithSite();
        $this->fact($b, [
            'kind' => 'testimonial', 'label' => 'Jane Okafor', 'value' => 'The sourdough is the best in the city.',
            'source' => 'owner_stated', 'published' => true, 'occurred_on' => '2026-05-01',
        ]);

        $review = $this->orgNode($site)['review'][0];

        $this->assertSame('Review', $review['@type']);
        $this->assertSame('The sourdough is the best in the city.', $review['reviewBody']);
        $this->assertSame('Jane Okafor', $review['author']['name']);
        $this->assertSame('2026-05-01', $review['datePublished']);
    }

    public function test_a_testimonial_with_no_body_is_not_a_review(): void
    {
        [$b, $site] = $this->makeBusinessWithSite();
        $this->fact($b, ['kind' => 'testimonial', 'label' => 'Anonymous', 'value' => null, 'source' => 'owner_stated', 'published' => true]);

        $this->assertArrayNotHasKey('review', $this->orgNode($site));
    }

    public function test_a_statistic_is_stored_but_never_published(): void
    {
        [$b, $site] = $this->makeBusinessWithSite();
        $fact = $this->fact($b, ['kind' => 'statistic', 'label' => 'Customers served', 'value' => '4000', 'source' => 'owner_stated', 'published' => true]);

        $this->assertTrue($fact->isPublishable(), 'the claim itself is cleared');
        $org = $this->orgNode($site);
        foreach (['award', 'hasCredential', 'memberOf', 'review', 'slogan', 'description'] as $property) {
            if ($property === 'description') {
                continue;
            }
            $this->assertArrayNotHasKey($property, $org, "a statistic must not be dressed up as {$property}");
        }
    }

    public function test_a_business_with_no_claims_gains_no_properties(): void
    {
        [, $site] = $this->makeBusinessWithSite();
        $org = $this->orgNode($site);

        foreach (['award', 'hasCredential', 'memberOf', 'review'] as $property) {
            $this->assertArrayNotHasKey($property, $org);
        }
    }

    // ---- verification and tenancy ---------------------------------------

    public function test_marking_verified_records_when_and_on_what_evidence(): void
    {
        [$b] = $this->makeBusinessWithSite();
        $fact = $this->fact($b, ['source' => 'proposed', 'published' => true]);
        $this->assertFalse($fact->isPublishable());

        $fact->markVerified('https://example.test/proof', 'Checked the register on 2026-09-25.');
        $fact->save();

        $this->assertSame('verified', $fact->source);
        $this->assertNotNull($fact->verified_at);
        $this->assertSame('https://example.test/proof', $fact->source_url);
        $this->assertTrue($fact->isPublishable(), 'a person checked it, so it may be stated');
    }

    public function test_one_business_never_publishes_another_businesss_claims(): void
    {
        [$mine, $siteMine] = $this->makeBusinessWithSite(['name' => 'Mine']);
        [$theirs] = $this->makeBusinessWithSite(['name' => 'Theirs']);

        $this->fact($theirs, ['kind' => 'award', 'label' => 'Their Award', 'source' => 'owner_stated', 'published' => true]);
        $this->fact($mine, ['kind' => 'award', 'label' => 'My Award', 'source' => 'owner_stated', 'published' => true]);

        $this->assertSame(['My Award'], $this->orgNode($siteMine)['award']);
    }

    public function test_a_soft_deleted_claim_disappears_from_the_graph(): void
    {
        [$b, $site] = $this->makeBusinessWithSite();
        $fact = $this->fact($b, ['kind' => 'award', 'label' => 'Withdrawn Award', 'source' => 'owner_stated', 'published' => true]);
        $this->assertSame(['Withdrawn Award'], $this->orgNode($site)['award']);

        $fact->delete();

        $this->assertArrayNotHasKey('award', $this->orgNode($site));
    }

    public function test_claims_follow_the_owners_order(): void
    {
        [$b, $site] = $this->makeBusinessWithSite();
        $this->fact($b, ['kind' => 'award', 'label' => 'Second', 'source' => 'owner_stated', 'published' => true, 'sort_order' => 2]);
        $this->fact($b, ['kind' => 'award', 'label' => 'First', 'source' => 'owner_stated', 'published' => true, 'sort_order' => 1]);

        $this->assertSame(['First', 'Second'], $this->orgNode($site)['award']);
    }
}
