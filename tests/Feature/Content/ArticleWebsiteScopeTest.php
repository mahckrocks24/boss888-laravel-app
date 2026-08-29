<?php

namespace Tests\Feature\Content;

use App\Engines\Builder\Services\BuilderRenderer;
use App\Engines\Write\Services\WriteService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CONTENT-2 (2026-08-29) — an article belongs to ONE website; a sibling site of the same workspace
 * must not render it; the article page renders the title once (no duplicate H1) with its hero.
 */
class ArticleWebsiteScopeTest extends TestCase
{
    private const WS = 999999906;
    private int $siteA = 0;
    private int $siteB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $uid = (int) DB::table('users')->insertGetId(['name' => 'Scope Owner', 'email' => 'scope-test-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Scope WS', 'slug' => 'scope-ws', 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        $this->siteA = (int) DB::table('websites')->insertGetId(['workspace_id' => self::WS, 'name' => 'Site A', 'subdomain' => 'scope-a.levelupgrowth.io', 'status' => 'published', 'created_by' => $uid, 'created_at' => now()->subDay(), 'updated_at' => now()]);
        $this->siteB = (int) DB::table('websites')->insertGetId(['workspace_id' => self::WS, 'name' => 'Site B', 'subdomain' => 'scope-b.levelupgrowth.io', 'status' => 'published', 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (['articles', 'websites', 'seo_content_index', 'article_versions'] as $t) {
            try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'scope-test-owner@example.test')->delete(); } catch (\Throwable) {}
    }

    private function article(array $extra = []): int
    {
        return (int) DB::table('articles')->insertGetId(array_merge([
            'workspace_id' => self::WS, 'title' => 'Scoped Post', 'slug' => 'scoped-post', 'type' => 'blog_article',
            'content' => '<h1>Scoped Post</h1><p>Body paragraph.</p>', 'status' => 'draft', 'is_marketing_blog' => 1,
            'featured_image_url' => 'https://example.test/hero.jpg', 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    /** @test */
    public function test_first_time_publish_binds_the_article_to_the_newest_published_site(): void
    {
        $id = $this->article();
        app(WriteService::class)->updateArticle($id, ['status' => 'published'], self::WS);
        $row = DB::table('articles')->where('id', $id)->first();
        $this->assertSame('published', $row->status);
        $this->assertSame($this->siteB, (int) $row->website_id, 'newest published LevelUp site wins when none is given');

        // An explicit website_id in the publish payload is honoured.
        $id2 = $this->article(['slug' => 'scoped-post-2']);
        app(WriteService::class)->updateArticle($id2, ['status' => 'published', 'website_id' => $this->siteA], self::WS);
        $this->assertSame($this->siteA, (int) DB::table('articles')->where('id', $id2)->value('website_id'));
    }

    /** @test */
    public function test_a_sibling_site_does_not_render_another_sites_article(): void
    {
        $this->article(['status' => 'published', 'published_at' => now(), 'website_id' => $this->siteA]);
        $r = app(BuilderRenderer::class);
        $this->assertNotNull($r->renderArticle('scope-a', 'scoped-post'), 'owning site renders it');
        $this->assertNull($r->renderArticle('scope-b', 'scoped-post'), 'sibling site must 404 it');
    }

    /** @test */
    public function test_the_article_page_renders_one_h1_and_the_hero(): void
    {
        $this->article(['status' => 'published', 'published_at' => now(), 'website_id' => $this->siteA]);
        $html = (string) app(BuilderRenderer::class)->renderArticle('scope-a', 'scoped-post');
        $this->assertSame(1, preg_match_all('#<h1\b#i', $html), 'exactly one H1');
        $this->assertStringContainsString('https://example.test/hero.jpg', $html);
        $this->assertStringContainsString('Body paragraph.', $html);
    }

    /** @test */
    public function test_legacy_unassigned_articles_still_render_everywhere(): void
    {
        $this->article(['status' => 'published', 'published_at' => now(), 'website_id' => null]);
        $r = app(BuilderRenderer::class);
        $this->assertNotNull($r->renderArticle('scope-a', 'scoped-post'));
        $this->assertNotNull($r->renderArticle('scope-b', 'scoped-post'));
    }
}
