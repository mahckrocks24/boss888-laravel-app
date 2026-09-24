<?php

namespace Tests\Feature\Business;

use App\Core\Business\FactUsageScanner;
use App\Models\Business;
use App\Models\BusinessFact;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * K8 (2026-09-25): where a fact is represented publicly, and what is stale.
 *
 * The question that could not be asked before this: "if this phone number
 * changes, what is now wrong?" Answering it needs a record of which fact
 * reached which page and which node, and a hash of the value that went out.
 *
 * Nothing writes on a page render. These rows come from an explicit scan.
 */
class FactUsageTest extends TestCase
{
    private int $user;
    private int $ws;
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = (int) DB::table('users')->insertGetId([
            'name' => 'K8 usage', 'email' => 'k8-' . uniqid() . '@example.test',
            'password' => password_hash('K8-Usage-Password-1', PASSWORD_BCRYPT),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'K8 Workspace', 'slug' => 'k8-' . uniqid(), 'timezone' => 'UTC',
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

    /** A business with a publishable phone number and one published extra page. */
    private function seedBusiness(array $attrs = []): array
    {
        $b = Business::create(array_merge([
            'workspace_id' => $this->ws, 'name' => 'K8 Co', 'industry' => 'bakery',
            'slug' => 'k8co-' . uniqid(), 'is_default' => 0, 'sort_order' => 0,
            'phone' => '+44 161 123 4567',
        ], $attrs));
        // No recorded source means owner-stated, which is publishable (K4).

        $sub = 'k8-' . uniqid() . '.levelupgrowth.io';
        $site = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $this->ws, 'business_id' => $b->id, 'name' => 'K8 Site',
            'subdomain' => $sub, 'status' => 'published', 'domain_verified' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sites[] = $site;

        DB::table('pages')->insert([
            'website_id' => $site, 'slug' => 'contact', 'title' => 'Contact',
            'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$b, $site, 'https://' . $sub];
    }

    private function scanner(): FactUsageScanner
    {
        return app(FactUsageScanner::class);
    }

    public function test_a_published_fact_is_recorded_against_every_page_that_carries_it(): void
    {
        [$b, $site, $base] = $this->seedBusiness();

        $written = $this->scanner()->scanBusiness((int) $b->id);
        $uses = $this->scanner()->usagesOf((int) $b->id, 'identity', 'phone');

        $this->assertGreaterThanOrEqual(2, $written, 'home plus the contact page');
        $this->assertCount(2, $uses);
        $urls = array_column($uses, 'page_url');
        $this->assertContains($base . '/', $urls);
        $this->assertContains($base . '/contact', $urls);
        $this->assertSame('telephone', $uses[0]['property']);
        $this->assertSame($base . '/#organization', $uses[0]['node_id'], 'and which node carries it');
    }

    public function test_a_withheld_fact_is_never_recorded_as_published(): void
    {
        [$b] = $this->seedBusiness();
        $b->stampIdentitySource('phone', 'ai_generated');
        $b->save();

        $this->scanner()->scanBusiness((int) $b->id);

        $this->assertSame([], $this->scanner()->usagesOf((int) $b->id, 'identity', 'phone'));
    }

    public function test_changing_a_fact_makes_every_page_that_published_it_stale(): void
    {
        [$b, , $base] = $this->seedBusiness();
        $this->scanner()->scanBusiness((int) $b->id);
        $this->assertSame([], $this->scanner()->stale((int) $b->id), 'nothing is stale immediately after a scan');

        $b->phone = '+44 161 999 0000';
        $b->save();

        $stale = $this->scanner()->stale((int) $b->id);

        $this->assertCount(2, $stale, 'both pages published the old number');
        $this->assertSame('telephone', $stale[0]['property']);
        $this->assertStringContainsString('changed', $stale[0]['reason']);
        $this->assertContains($base . '/contact', array_column($stale, 'page_url'));
    }

    public function test_withdrawing_a_fact_marks_its_pages_stale_too(): void
    {
        [$b] = $this->seedBusiness();
        $this->scanner()->scanBusiness((int) $b->id);

        $b->phone = null;
        $b->save();

        $stale = $this->scanner()->stale((int) $b->id);
        $this->assertNotEmpty($stale);
        $this->assertStringContainsString('changed', $stale[0]['reason']);
    }

    public function test_a_claim_is_traced_to_the_property_it_was_published_as(): void
    {
        [$b, , $base] = $this->seedBusiness();
        $fact = BusinessFact::create([
            'business_id' => $b->id, 'kind' => 'award', 'label' => 'Best Bakery 2026',
            'source' => 'owner_stated', 'published' => true,
        ]);

        $this->scanner()->scanBusiness((int) $b->id);
        $uses = $this->scanner()->usagesOf((int) $b->id, 'claim', (string) $fact->id);

        $this->assertCount(2, $uses);
        $this->assertSame('award', $uses[0]['property']);
        $this->assertSame($base . '/#organization', $uses[0]['node_id']);
    }

    public function test_editing_a_claim_makes_its_pages_stale(): void
    {
        [$b] = $this->seedBusiness();
        $fact = BusinessFact::create([
            'business_id' => $b->id, 'kind' => 'award', 'label' => 'Best Bakery 2026',
            'source' => 'owner_stated', 'published' => true,
        ]);
        $this->scanner()->scanBusiness((int) $b->id);
        $this->assertSame([], $this->scanner()->stale((int) $b->id));

        $fact->label = 'Best Bakery 2027';
        $fact->save();

        $stale = $this->scanner()->stale((int) $b->id);
        $this->assertNotEmpty($stale);
        $this->assertSame('award', $stale[0]['property']);
    }

    public function test_unpublishing_a_claim_reports_it_as_no_longer_published(): void
    {
        [$b] = $this->seedBusiness();
        $fact = BusinessFact::create([
            'business_id' => $b->id, 'kind' => 'award', 'label' => 'Withdrawn',
            'source' => 'owner_stated', 'published' => true,
        ]);
        $this->scanner()->scanBusiness((int) $b->id);

        $fact->published = false;
        $fact->save();

        $stale = $this->scanner()->stale((int) $b->id);
        $reasons = array_column($stale, 'reason');
        $this->assertContains('the fact is no longer published at all', $reasons);
    }

    public function test_the_scan_is_idempotent_and_replaces_rather_than_appends(): void
    {
        [$b] = $this->seedBusiness();
        $first = $this->scanner()->scanBusiness((int) $b->id);
        $second = $this->scanner()->scanBusiness((int) $b->id);

        $this->assertSame($first, $second);
        $this->assertSame($first, DB::table('fact_usages')->where('business_id', $b->id)->count());
    }

    public function test_a_draft_website_is_not_scanned(): void
    {
        [$b, $site] = $this->seedBusiness();
        DB::table('websites')->where('id', $site)->update(['status' => 'draft']);

        $this->assertSame(0, $this->scanner()->scanBusiness((int) $b->id), 'a draft has no public representation (EV-1097)');
    }

    public function test_one_business_never_sees_anothers_usage(): void
    {
        [$mine] = $this->seedBusiness(['name' => 'Mine']);
        [$theirs] = $this->seedBusiness(['name' => 'Theirs']);

        $this->scanner()->scanBusiness((int) $mine->id);
        $this->scanner()->scanBusiness((int) $theirs->id);

        $rows = DB::table('fact_usages')->where('business_id', $mine->id)->pluck('business_id')->unique()->all();
        $this->assertSame([(int) $mine->id], array_map('intval', $rows));
    }
}
