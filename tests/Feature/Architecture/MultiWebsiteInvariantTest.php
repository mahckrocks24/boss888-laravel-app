<?php

namespace Tests\Feature\Architecture;

use App\Core\Tenancy\WebsiteScope;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INC-0006 — the exit standard for multi-website businesses, asserted.
 *
 * ArchitectureInvariantTest proves the shape: one workspace, many websites, one wallet. This file proves the
 * consequence — that a business can actually OPERATE several websites without them contaminating each other.
 *
 * Every case here corresponds to a defect that was live before this work. The three settings tables were UNIQUE
 * on workspace_id, so a second website could not hold its own chatbot, its own WordPress endpoint or its own
 * Search Console property; the scan and crawl caches were keyed on the workspace, so progress for one site
 * overwrote another's; and several call sites resolved "the website" by taking the first or newest row, which
 * silently picked one of a business's sites and acted on it.
 *
 * The last two tests are the negative controls: isolation between separate businesses must survive all of it.
 */
class MultiWebsiteInvariantTest extends TestCase
{
    /** A business with a plan, a wallet and two websites. @return array{0:int,1:int,2:int,3:int} ws, uid, siteA, siteB */
    private function businessWithTwoSites(): array
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'mw-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'Business', 'slug' => 'mw-' . uniqid(), 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_users')->insert([
            'workspace_id' => $ws, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $plan = (int) (DB::table('plans')->where('max_websites', '>=', 3)->value('id')
            ?: DB::table('plans')->orderByDesc('max_websites')->value('id'));
        DB::table('subscriptions')->insert([
            'workspace_id' => $ws, 'plan_id' => $plan, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('credits')->insert([
            'workspace_id' => $ws, 'balance' => 500, 'reserved_balance' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $site = fn (string $name, string $host) => (int) DB::table('websites')->insertGetId([
            'workspace_id' => $ws, 'name' => $name, 'created_by' => $uid,
            'subdomain' => $host, 'status' => 'published', 'type' => 'levelup',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$ws, $uid, $site('Site A', 'mw-a-' . uniqid()), $site('Site B', 'mw-b-' . uniqid())];
    }

    /*----------------------------------------------------------------- website-isolated configuration */

    /** Each website carries its own chatbot. Before this, the table was UNIQUE on workspace_id. */
    public function test_two_websites_hold_two_distinct_chatbot_configurations(): void
    {
        [$ws, , $a, $b] = $this->businessWithTwoSites();

        foreach ([[$a, 'Welcome to A', 1], [$b, 'Welcome to B', 0]] as [$site, $greeting, $enabled]) {
            DB::table('chatbot_settings')->updateOrInsert(
                ['workspace_id' => $ws, 'website_id' => $site],
                ['greeting' => $greeting, 'enabled' => $enabled, 'theme' => 'auto',
                 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $rowA = WebsiteScope::settingsRow('chatbot_settings', $ws, $a);
        $rowB = WebsiteScope::settingsRow('chatbot_settings', $ws, $b);

        $this->assertSame('Welcome to A', $rowA->greeting);
        $this->assertSame('Welcome to B', $rowB->greeting);
        $this->assertSame(1, (int) $rowA->enabled, 'site A runs a chatbot');
        $this->assertSame(0, (int) $rowB->enabled, 'site B opted out, and that must not disable A');
    }

    /** A website with no row of its own inherits the business default rather than a sibling's settings. */
    public function test_a_website_without_its_own_row_inherits_the_business_default_not_a_sibling(): void
    {
        [$ws, , $a, $b] = $this->businessWithTwoSites();

        DB::table('chatbot_settings')->insert([
            'workspace_id' => $ws, 'website_id' => WebsiteScope::BUSINESS_DEFAULT,
            'greeting' => 'House default', 'enabled' => 1, 'theme' => 'auto',
            'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chatbot_settings')->insert([
            'workspace_id' => $ws, 'website_id' => $a,
            'greeting' => 'A only', 'enabled' => 1, 'theme' => 'auto',
            'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('A only', WebsiteScope::settingsRow('chatbot_settings', $ws, $a)->greeting);
        $this->assertSame('House default', WebsiteScope::settingsRow('chatbot_settings', $ws, $b)->greeting,
            'B has no row of its own, so it falls back to the business default — never to A');
    }

    /** WordPress endpoints are per-site: two sites in one business must not share one site_url. */
    public function test_each_website_keeps_its_own_wordpress_endpoint(): void
    {
        [$ws, , $a, $b] = $this->businessWithTwoSites();

        WebsiteScope::putSeo($ws, $a, 'site_url', 'https://a.example.test');
        WebsiteScope::putSeo($ws, $b, 'site_url', 'https://b.example.test');

        $this->assertSame('https://a.example.test', WebsiteScope::seo($ws, $a, 'site_url'));
        $this->assertSame('https://b.example.test', WebsiteScope::seo($ws, $b, 'site_url'));
    }

    /** An IndexNow key proves ownership of ONE host, so it must not be shared across a business's sites. */
    public function test_indexnow_keys_are_per_website(): void
    {
        [$ws, , $a, $b] = $this->businessWithTwoSites();

        WebsiteScope::putSeo($ws, $a, 'indexnow_key', str_repeat('a', 32));
        WebsiteScope::putSeo($ws, $b, 'indexnow_key', str_repeat('b', 32));

        $this->assertNotSame(
            WebsiteScope::seo($ws, $a, 'indexnow_key'),
            WebsiteScope::seo($ws, $b, 'indexnow_key'),
            'sharing one key would fail verification for both hosts',
        );
    }

    /** Search Console: the property is chosen per website, while the OAuth grant stays business-wide. */
    public function test_search_console_property_is_per_website_but_the_grant_is_shared(): void
    {
        [$ws, , $a, $b] = $this->businessWithTwoSites();
        $gsc = app(\App\Engines\SEO\Services\GscClient::class);

        DB::table('gsc_connections')->insert([
            'workspace_id' => $ws, 'website_id' => WebsiteScope::BUSINESS_DEFAULT, 'provider' => 'google',
            'refresh_token_enc' => 'enc', 'connected' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $gsc->setSite($ws, 'sc-domain:a.example.test', $a);
        $gsc->setSite($ws, 'sc-domain:b.example.test', $b);

        $this->assertSame('sc-domain:a.example.test', $gsc->getConnection($ws, $a)->site_url);
        $this->assertSame('sc-domain:b.example.test', $gsc->getConnection($ws, $b)->site_url);
        $this->assertSame(1, DB::table('gsc_connections')
            ->where('workspace_id', $ws)->whereNotNull('refresh_token_enc')->count(),
            'one Google authorisation for the business, not one per website');
    }

    /*----------------------------------------------------------------- no cache or job collisions */

    public function test_scan_and_crawl_state_never_collide_between_two_websites(): void
    {
        [, , $a, $b] = $this->businessWithTwoSites();

        $this->assertNotSame(
            WebsiteScope::cacheSuffix($a),
            WebsiteScope::cacheSuffix($b),
            'two websites must produce two different cache namespaces',
        );
        $this->assertNotSame(
            \App\Jobs\CrawlChatbotKnowledgeJob::statusKey(1, $a),
            \App\Jobs\CrawlChatbotKnowledgeJob::statusKey(1, $b),
            'crawling site B must not overwrite the progress of a crawl running on site A',
        );
        $this->assertNotSame(
            \App\Engines\SEO\Services\ScanProgressService::key(1, 'pages' . WebsiteScope::cacheSuffix($a)),
            \App\Engines\SEO\Services\ScanProgressService::key(1, 'pages' . WebsiteScope::cacheSuffix($b)),
            'two scans in one business must report separately',
        );
    }

    /*----------------------------------------------------------------- no arbitrary first-site behaviour */

    /** With more than one website and none named, the answer is a question — never a guess. */
    public function test_an_ambiguous_website_is_refused_rather_than_guessed(): void
    {
        [$ws, , $a] = $this->businessWithTwoSites();

        [$id, $err] = WebsiteScope::resolve($ws, null);
        $this->assertNull($id, 'a multi-site business has no implicit "the" website');
        $this->assertSame(WebsiteScope::REQUIRED, $err);

        [$id, $err] = WebsiteScope::resolve($ws, $a);
        $this->assertSame($a, $id, 'naming a website resolves it');
        $this->assertNull($err);
    }

    /** A single-site business stays frictionless: there is only one answer, so it is not a guess. */
    public function test_a_single_website_business_still_resolves_without_being_asked(): void
    {
        [$ws, , $a, $b] = $this->businessWithTwoSites();
        DB::table('websites')->where('id', $b)->update(['deleted_at' => now()]);

        [$id, $err] = WebsiteScope::resolve($ws, null);
        $this->assertSame($a, $id);
        $this->assertNull($err);
    }

    /*----------------------------------------------------------------- retired workspaces */

    public function test_an_archived_failed_build_is_not_a_business_the_customer_can_see_or_open(): void
    {
        [$ws, $uid] = $this->businessWithTwoSites();
        DB::table('workspaces')->where('id', $ws)->update(['lifecycle_state' => 'archived_failed_build']);

        $user = \App\Models\User::find($uid);
        $listed = app(\App\Core\Workspaces\WorkspaceService::class)->listForUser($user);
        $this->assertSame([], array_values(array_filter($listed, fn ($w) => (int) $w['id'] === $ws)),
            'an archived failed build must not appear as one of the customer\'s businesses');

        $this->assertTrue(Workspace::find($ws)->isRetired());
    }

    public function test_a_qa_fixture_is_hidden_from_customers_but_its_rows_survive(): void
    {
        [$ws, $uid, $a] = $this->businessWithTwoSites();
        DB::table('workspaces')->where('id', $ws)->update(['lifecycle_state' => 'qa']);

        $user = \App\Models\User::find($uid);
        $listed = app(\App\Core\Workspaces\WorkspaceService::class)->listForUser($user);
        $this->assertSame([], array_values(array_filter($listed, fn ($w) => (int) $w['id'] === $ws)));

        $this->assertNotNull(DB::table('websites')->where('id', $a)->first(),
            'a QA fixture is preserved, never deleted — its evidence stays readable through admin');
    }

    /** Archived is closed to everyone; a QA fixture stays open to the people who maintain it. */
    public function test_archived_is_unopenable_while_a_qa_fixture_remains_reachable_by_its_members(): void
    {
        [$archived, $uid] = $this->businessWithTwoSites();
        [$fixture] = $this->businessWithTwoSites();

        DB::table('workspaces')->where('id', $archived)->update(['lifecycle_state' => 'archived_failed_build']);
        DB::table('workspaces')->where('id', $fixture)->update(['lifecycle_state' => 'qa']);
        DB::table('workspace_users')->insert([
            'workspace_id' => $fixture, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = \App\Models\User::find($uid);
        $svc  = app(\App\Core\Auth\WorkspaceSwitchService::class);

        $this->assertSame($fixture, $svc->switchWorkspace($user, $fixture)['current_workspace_id'],
            'a QA fixture must stay reachable for the people who maintain it');

        try {
            $svc->switchWorkspace($user, $archived);
            $this->fail('opening an archived failed build should be refused');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(),
                'an archived failed build is not a business and must never become a working context');
        }
    }

    /*----------------------------------------------------------------- negative controls */

    /** The whole point of the boundary: same owner, two businesses, still two tenants. */
    public function test_two_businesses_owned_by_one_person_stay_completely_isolated(): void
    {
        [$wsOne, $uid, $siteOne] = $this->businessWithTwoSites();
        [$wsTwo, , $siteTwo] = $this->businessWithTwoSites();

        // give the same person access to both, which is the case the model must survive
        DB::table('workspace_users')->insert([
            'workspace_id' => $wsTwo, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNotSame($wsOne, $wsTwo, 'two businesses are two workspaces');

        // configuration written for one business is invisible to the other
        WebsiteScope::putSeo($wsOne, $siteOne, 'site_url', 'https://one.example.test');
        $this->assertNull(WebsiteScope::seo($wsTwo, $siteTwo, 'site_url'),
            'same owner is not shared configuration');

        // and neither wallet nor website crosses the boundary
        $this->assertSame(0, DB::table('websites')->where('workspace_id', $wsTwo)->where('id', $siteOne)->count());
        $this->assertNotSame(
            (int) DB::table('credits')->where('workspace_id', $wsOne)->value('id'),
            (int) DB::table('credits')->where('workspace_id', $wsTwo)->value('id'),
        );
    }

    /** A website id from another business must never resolve, even for a user who owns both. */
    public function test_a_website_from_another_business_is_refused_not_silently_ignored(): void
    {
        [$wsOne] = $this->businessWithTwoSites();
        [, , $foreignSite] = $this->businessWithTwoSites();

        [$id, $err] = WebsiteScope::resolve($wsOne, $foreignSite);

        $this->assertNull($id);
        $this->assertSame(WebsiteScope::NOT_IN_WORKSPACE, $err,
            'a foreign website must be rejected outright, not quietly replaced with a local one');
    }
}
