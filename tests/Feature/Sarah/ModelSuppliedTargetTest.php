<?php

namespace Tests\Feature\Sarah;

use App\Core\Orchestration\ToolSchemaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P6 multi-website acceptance (RISK-0105, 2026-08-30): on a workspace with several websites a website_id the MODEL
 * supplied is not the owner's choice, and a resolved target is remembered for the conversation only when the owner
 * chose it. Before: the conversation cache was armed by a model guess and every later ambiguous edit ran there.
 */
class ModelSuppliedTargetTest extends TestCase
{
    private function ws(): int
    {
        $u = (int) DB::table('users')->insertGetId(['name' => 'T', 'email' => 'tgt-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        return (int) DB::table('workspaces')->insertGetId(['name' => 'Tgt', 'slug' => 'tgt-' . uniqid(), 'created_by' => $u, 'settings_json' => json_encode(['sarah_target_resolution' => true]), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(int $ws, string $name, string $sub): int
    {
        return (int) DB::table('websites')->insertGetId(['workspace_id' => $ws, 'name' => $name, 'subdomain' => $sub, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function page(int $site): int
    {
        return (int) DB::table('pages')->insertGetId(['website_id' => $site, 'title' => 'Home', 'slug' => 'index', 'type' => 'page', 'status' => 'published', 'is_homepage' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_ambiguous_target_on_multi_site_workspace_clarifies_by_name_and_does_not_arm_cache(): void
    {
        $ws = $this->ws();
        $this->site($ws, 'Fable QA Bakery', 'tgt-bakery-' . uniqid());
        $b  = $this->site($ws, 'Fable QA Cafe Two', 'tgt-cafe-' . uniqid());
        $pb = $this->page($b);
        $key = 'sarah:tgt:ws' . $ws . ':sarah';
        Cache::forget($key);

        // The task path (P6-d) has stripped the model's website_id/page_id because the owner named no site.
        $params = [];
        $res = app(ToolSchemaService::class)->resolveTaskTarget('builder.edit_page_with_arthur', $params, $ws, 'sarah', []);
        $this->assertIsArray($res, 'ambiguous target must CLARIFY, not execute');
        $this->assertSame('CLARIFY_TARGET', $res['code']);
        $this->assertStringContainsString('Fable QA Bakery', $res['error']);
        $this->assertStringContainsString('Fable QA Cafe Two', $res['error']);
        $this->assertStringNotContainsString((string) $pb, $res['error'], 'the owner is asked by website name, never by id');
        $this->assertNull(Cache::get($key), 'a CLARIFY never arms the conversation target');
    }

    public function test_owner_named_site_resolves_and_is_remembered_for_the_next_turn(): void
    {
        $ws = $this->ws();
        $this->site($ws, 'Fable QA Bakery', 'tgt-bakery-' . uniqid());
        $b = $this->site($ws, 'Fable QA Cafe Two', 'tgt-cafe-' . uniqid());
        $key = 'sarah:tgt:ws' . $ws . ':sarah';
        Cache::forget($key);

        $params = [];
        $res = app(ToolSchemaService::class)->resolveTaskTarget('builder.edit_page_with_arthur', $params, $ws, 'sarah', ['explicit_name' => 'Fable QA Cafe Two']);
        $this->assertNull($res);
        $this->assertSame($b, (int) $params['website_id']);
        $this->assertSame($b, (int) Cache::get($key), 'an owner-named site becomes the conversation target');

        $params2 = [];
        $this->assertNull(app(ToolSchemaService::class)->resolveTaskTarget('builder.edit_page_with_arthur', $params2, $ws, 'sarah', []));
        $this->assertSame($b, (int) $params2['website_id'], 'the owner\'s earlier choice continues deterministically');
    }

    public function test_ui_site_context_resolves_but_does_not_arm_cache(): void
    {
        $ws = $this->ws();
        $sub = 'tgt-bakery-' . uniqid();
        $a = $this->site($ws, 'Fable QA Bakery', $sub);
        $this->site($ws, 'Fable QA Cafe Two', 'tgt-cafe-' . uniqid());
        $key = 'sarah:tgt:ws' . $ws . ':sarah';
        Cache::forget($key);

        $params = [];
        $res = app(ToolSchemaService::class)->resolveTaskTarget('builder.edit_page_with_arthur', $params, $ws, 'sarah', ['ui_site_url' => 'https://' . $sub . '.levelupgrowth.io']);
        $this->assertNull($res);
        $this->assertSame($a, (int) $params['website_id'], 'the UI advisory site is an approved RISK-0105 signal');
        $this->assertNull(Cache::get($key), 'an advisory resolution must not become the conversation target (P6-e)');
    }
}
