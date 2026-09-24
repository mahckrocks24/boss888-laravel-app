<?php

namespace Tests\Feature\Business;

use App\Core\Business\FactUsageScanner;
use App\Core\Business\KnowledgeAdvisor;
use App\Models\Business;
use App\Models\BusinessFact;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * K9 (2026-09-25): what Sarah says about a business's knowledge.
 *
 * Not a score. "Knowledge Health 73%" tells an owner nothing they can act on
 * and invents precision out of an absence. Every observation is a sentence with
 * a consequence and something to do about it.
 *
 * And the discipline that matters most: a candidate is quoted back as a
 * question, never asserted. Sarah says "I have this, is it right?" — she does
 * not say "your phone number is".
 */
class KnowledgeAdvisorTest extends TestCase
{
    private int $user;
    private int $ws;
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = (int) DB::table('users')->insertGetId([
            'name' => 'K9 advisor', 'email' => 'k9-' . uniqid() . '@example.test',
            'password' => password_hash('K9-Advisor-Password-1', PASSWORD_BCRYPT),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'K9 Workspace', 'slug' => 'k9-' . uniqid(), 'timezone' => 'UTC',
            'created_by' => $this->user, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $ids = DB::table('businesses')->where('workspace_id', $this->ws)->pluck('id')->all();
        if ($ids) {
            DB::table('fact_usages')->whereIn('business_id', $ids)->delete();
            DB::table('business_facts')->whereIn('business_id', $ids)->delete();
        }
        if ($this->sites) {
            DB::table('pages')->whereIn('website_id', $this->sites)->delete();
            DB::table('websites')->whereIn('id', $this->sites)->delete();
        }
        DB::table('businesses')->where('workspace_id', $this->ws)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        DB::table('users')->where('id', $this->user)->delete();
        parent::tearDown();
    }

    private function seedBusiness(array $attrs = []): Business
    {
        $b = Business::create(array_merge([
            'workspace_id' => $this->ws, 'name' => 'K9 Co', 'industry' => 'bakery',
            'slug' => 'k9co-' . uniqid(), 'is_default' => 0, 'sort_order' => 0,
        ], $attrs));

        $site = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $this->ws, 'business_id' => $b->id, 'name' => 'K9 Site',
            'subdomain' => 'k9-' . uniqid() . '.levelupgrowth.io', 'status' => 'published',
            'domain_verified' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sites[] = $site;

        return $b;
    }

    private function advisor(): KnowledgeAdvisor
    {
        return app(KnowledgeAdvisor::class);
    }

    private function saying(array $items, string $needle): ?array
    {
        foreach ($items as $item) {
            if (str_contains(strtolower($item['say']), strtolower($needle))) {
                return $item;
            }
        }

        return null;
    }

    // ---- no invented precision ------------------------------------------

    public function test_nothing_it_says_is_a_score(): void
    {
        $items = $this->advisor()->forBusiness((int) $this->seedBusiness()->id);
        $this->assertNotEmpty($items);

        $text = strtolower(json_encode($items));
        foreach (['%', 'score', 'health', 'out of 10', 'rating'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "no invented precision: {$forbidden}");
        }
    }

    public function test_every_observation_carries_a_consequence_and_something_to_do(): void
    {
        foreach ($this->advisor()->forBusiness((int) $this->seedBusiness()->id) as $item) {
            $this->assertNotSame('', trim($item['say']));
            $this->assertNotSame('', trim($item['action']));
            $this->assertArrayHasKey('kind', $item);
        }
    }

    // ---- missing ---------------------------------------------------------

    public function test_a_missing_field_is_named_with_what_it_costs(): void
    {
        $items = $this->advisor()->forBusiness((int) $this->seedBusiness()->id);
        $phone = $this->saying($items, 'phone number');

        $this->assertNotNull($phone);
        $this->assertSame('missing', $phone['kind']);
        $this->assertStringContainsString('reach you', $phone['say']);
    }

    public function test_a_published_field_prompts_nothing(): void
    {
        $b = $this->seedBusiness(['phone' => '+44 161 123 4567']);
        // No recorded source means owner-stated, which is publishable.

        $this->assertNull($this->saying($this->advisor()->forBusiness((int) $b->id), 'phone number'));
    }

    public function test_a_business_that_is_not_a_place_is_not_asked_for_an_address(): void
    {
        $b = $this->seedBusiness(['industry' => 'news_channel']);
        $items = $this->advisor()->forBusiness((int) $b->id);

        $this->assertNull($this->saying($items, 'business address'));
        $this->assertNull($this->saying($items, 'opening hours'));
    }

    // ---- unconfirmed: ask, never assert ---------------------------------

    public function test_a_candidate_is_quoted_back_as_a_question_not_asserted(): void
    {
        $b = $this->seedBusiness(['phone' => '+63 947 166 0941']);
        $b->stampIdentitySource('phone', 'proposed', 'websites.settings_json');
        $b->save();

        $item = $this->saying($this->advisor()->forBusiness((int) $b->id), '+63 947 166 0941');

        $this->assertNotNull($item);
        $this->assertSame('unconfirmed', $item['kind']);
        $this->assertStringContainsString('not published', $item['say']);
        $this->assertStringContainsString('nobody has confirmed', $item['say']);
    }

    public function test_an_ai_written_candidate_says_where_it_came_from(): void
    {
        $b = $this->seedBusiness(['phone' => '(512) 555-0178']);
        $b->stampIdentitySource('phone', 'ai_generated', 'websites.template_variables');
        $b->save();

        $item = $this->saying($this->advisor()->forBusiness((int) $b->id), '555-0178');

        $this->assertNotNull($item);
        $this->assertStringContainsString('which I wrote', $item['say'], 'Sarah owns the provenance out loud');
    }

    public function test_an_unconfirmed_claim_is_surfaced_too(): void
    {
        $b = $this->seedBusiness();
        BusinessFact::create([
            'business_id' => $b->id, 'kind' => 'award', 'label' => 'Best Bakery 2026',
            'source' => 'proposed', 'published' => false,
        ]);

        $item = $this->saying($this->advisor()->forBusiness((int) $b->id), 'Best Bakery 2026');

        $this->assertNotNull($item);
        $this->assertSame('unconfirmed', $item['kind']);
    }

    // ---- stale is the most urgent ---------------------------------------

    public function test_a_changed_fact_is_reported_first_and_names_the_pages(): void
    {
        $b = $this->seedBusiness(['phone' => '+44 161 123 4567']);
        app(FactUsageScanner::class)->scanBusiness((int) $b->id);

        $b->phone = '+44 161 999 0000';
        $b->save();

        $items = $this->advisor()->forBusiness((int) $b->id);

        $this->assertSame('stale', $items[0]['kind'], 'what is now wrong comes before what is merely absent');
        $this->assertStringContainsString('still show', $items[0]['say']);
        $this->assertStringContainsString('telephone', $items[0]['say']);
    }

    // ---- the entity itself ----------------------------------------------

    public function test_a_static_export_still_stating_a_generic_type_is_raised(): void
    {
        // A live-rendered site takes its type from the composer and is correct
        // by construction. A static export carries whatever it was built with
        // until it is republished, which is the only case worth raising.
        $b = $this->seedBusiness(['industry' => 'travel_agency']);
        $siteId = (int) DB::table('websites')->where('business_id', $b->id)->value('id');
        $export = storage_path("app/public/sites/{$siteId}/index.html");
        @mkdir(dirname($export), 0775, true);
        file_put_contents($export, '<html><head></head><body></body></html>');

        try {
            $item = $this->saying($this->advisor()->forBusiness((int) $b->id), 'generic');

            $this->assertNotNull($item);
            $this->assertSame('entity', $item['kind']);
            $this->assertStringContainsString('TravelAgency', $item['say']);
            $this->assertStringContainsString('republish', $item['action']);
        } finally {
            @unlink($export);
            @rmdir(dirname($export));
        }
    }

    public function test_a_live_rendered_site_is_not_nagged_about_its_type(): void
    {
        $b = $this->seedBusiness(['industry' => 'travel_agency']);

        $this->assertNull($this->saying($this->advisor()->forBusiness((int) $b->id), 'generic'), 'the composer already states it correctly');
    }

    public function test_an_unknown_business_says_nothing(): void
    {
        $this->assertSame([], $this->advisor()->forBusiness(0));
    }
}
