<?php

namespace Tests\Feature\Sarah;

use App\Core\Orchestration\ToolSchemaService;
use App\Core\Sarah888\ReadToolPromotion;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6 negative controls for the read tools that pass 1 now executes from Sarah's chat (REPORT-0024 SF-05,
 * EV-0900). The product rule under test: ONE workspace → MANY websites → SHARED intelligence, and a website
 * named by an owner of ANOTHER workspace, or a website/page id belonging to another workspace, must never be
 * readable — not by id, not by name, not by omission.
 */
class ReadToolTenancyTest extends TestCase
{
    /** @return array{ws:int, site:int, page:int} */
    private function business(string $siteName): array
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'rt-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ws = (int) DB::table('workspaces')->insertGetId([
            'name' => $siteName . ' Ltd', 'slug' => 'rt-' . uniqid(), 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $site = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $ws, 'name' => $siteName, 'subdomain' => 'rt-' . uniqid() . '.levelupgrowth.io',
            'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $page = (int) DB::table('pages')->insertGetId([
            'website_id' => $site, 'title' => $siteName . ' Home', 'slug' => 'home-' . uniqid(), 'is_homepage' => 1,
            'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['ws' => $ws, 'site' => $site, 'page' => $page];
    }

    public function test_a_foreign_website_id_yields_nothing_from_list_pages(): void
    {
        $a = $this->business('Alpha Bakery');
        $b = $this->business('Beta Dental');

        $r = app(ToolSchemaService::class)->executeToolCall('platform.list_pages', ['website_id' => $b['site']], $a['ws'], 'sarah');

        $this->assertTrue((bool) ($r['success'] ?? false));
        $this->assertStringNotContainsString('Beta Dental', json_encode($r));
        $this->assertStringNotContainsString((string) $b['page'], json_encode($r['data'] ?? []));
    }

    public function test_a_foreign_page_id_is_not_found_from_read_page(): void
    {
        $a = $this->business('Alpha Bakery');
        $b = $this->business('Beta Dental');

        $r = app(ToolSchemaService::class)->executeToolCall('platform.read_page', ['page_id' => $b['page']], $a['ws'], 'sarah');

        $this->assertFalse((bool) ($r['success'] ?? true));
        $this->assertSame('NOT_FOUND', $r['code'] ?? null);
        $this->assertStringNotContainsString('Beta Dental', json_encode($r));
    }

    public function test_the_named_website_resolution_is_workspace_scoped(): void
    {
        $a = $this->business('Alpha Bakery');
        $this->business('Beta Dental');

        $svc = app(ToolSchemaService::class);
        $this->assertSame([], $svc->websiteNamesMentioned($a['ws'], 'List the pages on Beta Dental please'),
            'a website of another workspace is never resolved by name');
        $named = $svc->websiteNamesMentioned($a['ws'], 'List the pages on Alpha Bakery please');
        $this->assertCount(1, $named);
        $this->assertSame($a['site'], $named[0]['id']);
    }

    public function test_two_websites_in_one_workspace_share_the_listing_and_split_by_name(): void
    {
        $a = $this->business('Alpha Bakery');
        $second = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $a['ws'], 'name' => 'Alpha Cafe Two', 'subdomain' => 'rt-' . uniqid() . '.levelupgrowth.io',
            'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pages')->insert([
            'website_id' => $second, 'title' => 'Cafe Menu', 'slug' => 'menu-' . uniqid(), 'is_homepage' => 0,
            'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $svc = app(ToolSchemaService::class);

        // Workspace-wide: no website named, pages span two sites → the tool asks by NAME (never guesses).
        $wide = $svc->executeToolCall('platform.list_pages', [], $a['ws'], 'sarah');
        $this->assertSame('CLARIFY_TARGET', $wide['code'] ?? null);
        $this->assertStringContainsString('Alpha Bakery', (string) ($wide['error'] ?? ''));
        $this->assertStringContainsString('Alpha Cafe Two', (string) ($wide['error'] ?? ''));

        // Explicit site → that site only.
        $one = $svc->executeToolCall('platform.list_pages', ['website_id' => $second], $a['ws'], 'sarah');
        $this->assertTrue((bool) ($one['success'] ?? false));
        $titles = array_column((array) ($one['data']['pages'] ?? $one['data'] ?? []), 'title');
        $this->assertContains('Cafe Menu', $titles);
        $this->assertNotContains('Alpha Bakery Home', $titles);
    }

    /** Run 5 turn 5 (2.37.11 live): the tool_calls path had no website id for a site the owner named. */
    public function test_target_by_name_sets_the_named_website_and_never_a_foreign_one(): void
    {
        $a = $this->business('Alpha Bakery');
        $b = $this->business('Beta Dental');
        $svc = app(ToolSchemaService::class);

        [$params, $ctx] = ReadToolPromotion::targetByName($svc, $a['ws'], 'List the pages on Alpha Bakery.', []);
        $this->assertSame($a['site'], $params['website_id'] ?? null);
        $this->assertSame('Alpha Bakery', $ctx['explicit_name'] ?? null);

        [$params, $ctx] = ReadToolPromotion::targetByName($svc, $a['ws'], 'List the pages on Beta Dental.', []);
        $this->assertArrayNotHasKey('website_id', $params, "another workspace's site name resolves to nothing");
        $this->assertArrayNotHasKey('explicit_name', $ctx);

        [$params] = ReadToolPromotion::targetByName($svc, $a['ws'], 'List the pages on Alpha Bakery.', ['website_id' => 4242]);
        $this->assertSame(4242, $params['website_id'], 'an explicit id from the caller is kept');
    }

    public function test_only_read_tools_are_ever_promoted_out_of_create_tasks(): void
    {
        $ids = app(ToolSchemaService::class)->getAllToolIds();
        foreach (['builder.update_page', 'builder.publish_website', 'write.write_article', 'crm.create_lead', 'social.create_post'] as $write) {
            [$engine, $action] = explode('.', $write, 2);
            $this->assertNull(ReadToolPromotion::toolIdFor($engine, $action, $ids), "$write must stay a governed task");
        }
        $this->assertSame('platform.list_pages', ReadToolPromotion::toolIdFor('platform', 'list_pages', $ids));
    }
}
