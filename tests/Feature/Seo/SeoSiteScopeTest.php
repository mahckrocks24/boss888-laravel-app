<?php

namespace Tests\Feature\Seo;

use App\Engines\Builder\Support\ArticleScope;
use App\Engines\SEO\Services\SeoService;
use App\Engines\SEO\Support\SiteScope;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-09-21 (Owner): "stats of Chef Red's show up on all other websites". A workspace with two websites: what names a
 * site belongs to that site; what names none (a keyword with no target_url) belongs to the FIRST website only — the
 * rule ArticleScope set for articles (RISK-0198).
 */
class SeoSiteScopeTest extends TestCase
{
    private int $ws; private int $first; private int $second;

    protected function setUp(): void
    {
        parent::setUp();
        $uid = (int) DB::table('users')->min('id');
        $this->ws = (int) DB::table('workspaces')->insertGetId(['name' => 'seo-scope-test', 'slug' => 'seo-scope-test-' . uniqid(), 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        $this->first = (int) DB::table('websites')->insertGetId(['workspace_id' => $this->ws, 'name' => 'First', 'slug' => 'scope-first-' . uniqid(), 'subdomain' => 'scope-first.levelupgrowth.io', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $this->second = (int) DB::table('websites')->insertGetId(['workspace_id' => $this->ws, 'name' => 'Second', 'slug' => 'scope-second-' . uniqid(), 'subdomain' => 'scope-second.levelupgrowth.io', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seo_keywords')->insert([
            ['workspace_id' => $this->ws, 'keyword' => 'untargeted', 'target_url' => null, 'status' => 'tracking', 'volume' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $this->ws, 'keyword' => 'second only', 'target_url' => 'https://scope-second.levelupgrowth.io/x', 'status' => 'tracking', 'volume' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('seo_content_index')->insert([
            ['workspace_id' => $this->ws, 'website_id' => $this->first, 'url' => 'https://scope-first.levelupgrowth.io/a', 'title' => 'A', 'content_score' => 40, 'word_count' => 500, 'inbound_links' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $this->ws, 'website_id' => $this->second, 'url' => 'https://scope-second.levelupgrowth.io/b', 'title' => 'B', 'content_score' => 90, 'word_count' => 500, 'inbound_links' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        ArticleScope::reset();
    }

    protected function tearDown(): void
    {
        DB::table('seo_content_index')->where('workspace_id', $this->ws)->delete();
        DB::table('seo_keywords')->where('workspace_id', $this->ws)->delete();
        DB::table('websites')->where('workspace_id', $this->ws)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        ArticleScope::reset();
        parent::tearDown();
    }

    public function test_the_first_website_is_the_primary_host(): void
    {
        $this->assertTrue(SiteScope::isPrimaryHost($this->ws, 'scope-first.levelupgrowth.io'));
        $this->assertFalse(SiteScope::isPrimaryHost($this->ws, 'scope-second.levelupgrowth.io'));
    }

    public function test_an_untargeted_keyword_belongs_to_the_first_website_only(): void
    {
        $svc = app(SeoService::class);
        $first  = array_column($svc->listKeywords($this->ws, ['site_url' => 'https://scope-first.levelupgrowth.io'])['keywords'], 'keyword');
        $second = array_column($svc->listKeywords($this->ws, ['site_url' => 'https://scope-second.levelupgrowth.io'])['keywords'], 'keyword');
        $this->assertSame(['untargeted'], $first);
        $this->assertSame(['second only'], $second);
    }

    public function test_knowledge_reads_only_the_selected_websites_pages(): void
    {
        $svc = app(SeoService::class);
        $first  = $svc->getKnowledge($this->ws, 'https://scope-first.levelupgrowth.io');
        $second = $svc->getKnowledge($this->ws, 'https://scope-second.levelupgrowth.io');
        $this->assertNotEquals(json_encode($first['content_health'] ?? $first), json_encode($second['content_health'] ?? $second), 'each website sees its own content health');
        $all = $svc->getKnowledge($this->ws);
        $this->assertIsArray($all, 'no site still answers (workspace-wide, as before)');
    }
}
