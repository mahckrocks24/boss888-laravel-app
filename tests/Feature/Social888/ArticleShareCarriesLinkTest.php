<?php

namespace Tests\Feature\Social888;

use App\Engines\Social\Services\SocialService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PREVIEW-1 (Owner 2026-09-25: "We need to add preview or draft of what will be posted inside the chat"): a share of an
 * article carries the article's link, site and business — the preview shows them and the publish payload sends the link.
 * Sarah's two drafts today carried none of the three.
 */
class ArticleShareCarriesLinkTest extends TestCase
{
    private const WS = 999999916;
    private int $uid = 0; private int $site = 0; private int $biz = 0; private int $article = 0;

    protected function setUp(): void
    {
        parent::setUp(); $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'Share Owner', 'email' => 'share-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Share WS', 'slug' => 'share-ws', 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
        $this->biz = (int) DB::table('businesses')->insertGetId(['workspace_id' => self::WS, 'name' => 'Share Bakery', 'slug' => 'share-bakery', 'created_at' => now(), 'updated_at' => now()]);
        $this->site = (int) DB::table('websites')->insertGetId(['workspace_id' => self::WS, 'name' => 'Share Site', 'subdomain' => 'share-site.levelupgrowth.io', 'custom_domain' => 'sharebakery.example', 'status' => 'published', 'created_by' => $this->uid, 'business_id' => $this->biz, 'created_at' => now(), 'updated_at' => now()]);
        $this->article = (int) DB::table('articles')->insertGetId(['workspace_id' => self::WS, 'website_id' => $this->site, 'title' => 'Sourdough for beginners', 'slug' => 'sourdough-for-beginners-ab12', 'content' => 'x', 'status' => 'published', 'featured_image_url' => 'https://staging.levelupgrowth.io/storage/ai-images/x.jpg', 'created_at' => now(), 'updated_at' => now()]);
    }
    protected function tearDown(): void { $this->cleanup(); parent::tearDown(); }
    private function cleanup(): void
    {
        foreach (['social_posts', 'articles', 'websites', 'businesses'] as $t) { try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {} }
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'share-owner@example.test')->delete(); } catch (\Throwable) {}
    }

    public function test_a_share_of_an_article_carries_link_site_and_business(): void
    {
        $r = app(SocialService::class)->createPost(self::WS, ['platform' => 'facebook', 'content' => 'Sourdough, the easy way.', 'article_id' => $this->article]);
        $p = DB::table('social_posts')->where('id', $r['post_id'])->first();
        $this->assertSame('https://sharebakery.example/blog/sourdough-for-beginners-ab12', $p->canonical_url, 'the article link, on the custom domain');
        $this->assertSame($this->site, (int) $p->website_id);
        $this->assertSame($this->biz, (int) $p->business_id);
    }

    public function test_a_post_without_an_article_is_unchanged(): void
    {
        $r = app(SocialService::class)->createPost(self::WS, ['platform' => 'facebook', 'content' => 'Open late on Fridays.']);
        $p = DB::table('social_posts')->where('id', $r['post_id'])->first();
        $this->assertNull($p->canonical_url); $this->assertNull($p->website_id);
    }

    public function test_an_explicit_link_is_kept(): void
    {
        $r = app(SocialService::class)->createPost(self::WS, ['platform' => 'facebook', 'content' => 'Read this.', 'article_id' => $this->article, 'canonical_url' => 'https://sharebakery.example/special']);
        $this->assertSame('https://sharebakery.example/special', DB::table('social_posts')->where('id', $r['post_id'])->value('canonical_url'));
    }
}
