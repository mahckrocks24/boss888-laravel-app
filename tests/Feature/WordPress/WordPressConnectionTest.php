<?php

namespace Tests\Feature\WordPress;

use App\Engines\Write\Services\WriteService;
use App\Models\WpSiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WP-1..4 (2026-08-29) — WordPress is part of the Websites ecosystem (Owner decision): a plugin
 * "Test connection" registers the site (wp_site_connections + a websites row), the app resolves the
 * connection from that row, and a first-time article publish lands INSIDE WordPress with a truthful
 * outcome. No separate WordPress pricing ladder anywhere in this path.
 */
class WordPressConnectionTest extends TestCase
{
    private const WS  = 999999905;
    private const KEY = 'lgs_test_wp_connection_key_0000000000000000000000';
    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'WP Owner', 'email' => 'wp-test-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'WP Test WS', 'slug' => 'wp-test-ws', 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => self::WS, 'user_id' => $this->uid, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('api_keys')->insert(['workspace_id' => self::WS, 'user_id' => $this->uid, 'key' => self::KEY, 'name' => 'test', 'type' => 'connector', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (['wp_site_connections', 'websites', 'seo_settings', 'articles', 'seo_content_index', 'audit_logs'] as $t) {
            try { DB::table($t)->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        }
        try { DB::table('api_keys')->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('workspace_users')->where('workspace_id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('workspaces')->where('id', self::WS)->delete(); } catch (\Throwable) {}
        try { DB::table('users')->where('email', 'wp-test-owner@example.test')->delete(); } catch (\Throwable) {}
    }

    private function registerSite(string $url = 'https://blog.example.test', string $secret = 'secret-0123456789'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-API-KEY' => self::KEY, 'Accept' => 'application/json'])
            ->postJson('/api/connector/register-site', ['site_url' => $url, 'webhook_secret' => $secret, 'site_name' => 'Test Blog', 'plugin_version' => '1.1.0', 'wp_version' => '7.1']);
    }

    private function article(string $status = 'draft', ?int $wpPostId = null): int
    {
        return (int) DB::table('articles')->insertGetId([
            'workspace_id' => self::WS, 'title' => 'Hello WP', 'slug' => 'hello-wp-' . uniqid(), 'content' => '<h1>Hello WP</h1><p>Body text here.</p>',
            'status' => $status, 'type' => 'blog_article', 'wp_post_id' => $wpPostId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @test */
    public function test_connection_registers_the_site_and_creates_a_wordpress_website_row(): void
    {
        $res = $this->registerSite();
        $res->assertOk()->assertJson(['success' => true, 'status' => 'active']);

        $conn = DB::table('wp_site_connections')->where('workspace_id', self::WS)->first();
        $this->assertNotNull($conn, 'a wp_site_connections row must exist after register-site');
        $this->assertSame('blog.example.test', $conn->site_host);
        $this->assertSame('active', $conn->status);
        $this->assertSame('1.1.0', $conn->plugin_version);

        $site = DB::table('websites')->where('id', $conn->website_id)->first();
        $this->assertNotNull($site, 'register-site must create the websites row (WordPress is a Website)');
        $this->assertSame('wordpress', $site->platform);
        $this->assertSame('connected', $site->connector_status);

        $this->assertSame((int) $conn->id, (int) DB::table('api_keys')->where('key', self::KEY)->value('site_connection_id'));
    }

    /** @test */
    public function test_registering_the_same_host_twice_updates_one_connection(): void
    {
        $this->registerSite()->assertOk();
        $this->registerSite('https://www.blog.example.test/', 'rotated-secret-987654')->assertOk();
        $rows = DB::table('wp_site_connections')->where('workspace_id', self::WS)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('rotated-secret-987654', $rows[0]->webhook_secret);
        $this->assertCount(1, DB::table('websites')->where('workspace_id', self::WS)->where('platform', 'wordpress')->get());
    }

    /** @test */
    public function test_connection_resolver_prefers_the_connection_row_and_refuses_a_suspended_one(): void
    {
        $svc = app(WriteService::class);
        $this->assertNull($svc->wpConnectionFor(self::WS), 'no connection → null');

        // Legacy fallback: seo_settings only.
        DB::table('seo_settings')->insert([['workspace_id' => self::WS, 'key' => 'site_url', 'value' => 'https://legacy.example.test', 'created_at' => now(), 'updated_at' => now()], ['workspace_id' => self::WS, 'key' => 'webhook_secret', 'value' => 'legacy-secret-123', 'created_at' => now(), 'updated_at' => now()]]);
        $this->assertSame('https://legacy.example.test', $svc->wpConnectionFor(self::WS)['site_url']);

        $this->registerSite();
        $c = $svc->wpConnectionFor(self::WS);
        $this->assertSame('https://blog.example.test', $c['site_url']);
        $this->assertNotNull($c['connection_id']);

        DB::table('wp_site_connections')->where('workspace_id', self::WS)->update(['status' => WpSiteConnection::STATUS_BILLING_SUSPENDED]);
        $this->assertNull($svc->wpConnectionFor(self::WS), 'a suspended connection must not be pushed to — and must not fall back to stale seo_settings');
    }

    /** @test */
    public function test_first_time_publish_lands_in_wordpress_and_records_the_push(): void
    {
        $this->registerSite();
        Http::fake(['blog.example.test/wp-json/lgsc/v1/create-post' => Http::response(['success' => true, 'post_id' => 77, 'url' => 'https://blog.example.test/hello-wp/', 'status' => 'publish'], 200)]);

        $id  = $this->article('draft');
        $out = app(WriteService::class)->updateArticle($id, ['status' => 'published'], self::WS);

        $this->assertTrue($out['wordpress']['ok'] ?? false, 'updateArticle must report the WordPress outcome');
        $this->assertSame(77, $out['wordpress']['wp_post_id']);
        $this->assertSame(77, (int) DB::table('articles')->where('id', $id)->value('wp_post_id'));
        $conn = DB::table('wp_site_connections')->where('workspace_id', self::WS)->first();
        $this->assertSame('ok', $conn->last_push_status);
        $this->assertNotNull($conn->last_push_at);

        Http::assertSent(function ($req) use ($id) {
            return str_contains($req->url(), '/wp-json/lgsc/v1/create-post')
                && $req->hasHeader('X-LGSC-Secret', 'secret-0123456789')
                && $req['status'] === 'publish'
                && (int) $req['levelup_article_id'] === $id;
        });

        // Second save with status already published → no second push.
        app(WriteService::class)->updateArticle($id, ['status' => 'published', 'title' => 'Hello WP (edited)'], self::WS);
        Http::assertSentCount(1);
    }

    /** @test */
    public function test_a_failed_wordpress_push_is_reported_truthfully_not_as_success(): void
    {
        $this->registerSite();
        Http::fake(['blog.example.test/*' => Http::response(['code' => 'lgsc_bad_secret', 'message' => 'Invalid secret'], 403)]);

        $id  = $this->article('draft');
        $out = app(WriteService::class)->updateArticle($id, ['status' => 'published'], self::WS);

        $this->assertFalse($out['wordpress']['ok']);
        $this->assertStringContainsString('HTTP 403', $out['wordpress']['error']);
        $this->assertNull(DB::table('articles')->where('id', $id)->value('wp_post_id'));
        $conn = DB::table('wp_site_connections')->where('workspace_id', self::WS)->first();
        $this->assertSame('failed', $conn->last_push_status);
        $this->assertSame('failed', $conn->status);
        // The Laravel article is still published — the customer's own site is not held hostage by WP.
        $this->assertSame('published', DB::table('articles')->where('id', $id)->value('status'));
    }

    /** @test */
    public function test_a_generation_time_draft_push_does_not_count_as_already_published(): void
    {
        $this->registerSite();
        Http::fake(['blog.example.test/*' => Http::response(['success' => true, 'post_id' => 5, 'url' => 'https://blog.example.test/hello-wp/', 'status' => 'publish', 'updated' => true], 200)]);

        $id = $this->article('draft', 5); // draft pushed at generation time → wp_post_id set, WP status draft
        $res = $this->actingAs(\App\Models\User::find($this->uid))
            ->withHeaders(['X-API-KEY' => self::KEY, 'Accept' => 'application/json'])
            ->postJson('/api/write/articles/' . $id . '/publish', ['workspace_id' => self::WS]);

        $res->assertOk();
        $this->assertNotTrue($res->json('already'), 'a WordPress DRAFT is not "already published"');
        Http::assertSent(fn ($req) => $req['status'] === 'publish' && (int) $req['levelup_article_id'] === $id);
        $this->assertSame('published', DB::table('articles')->where('id', $id)->value('status'));
    }

    /** @test */
    public function test_verify_connection_reports_the_entitled_plan_not_a_wordpress_ladder(): void
    {
        $res = $this->withHeaders(['X-API-KEY' => self::KEY, 'Accept' => 'application/json'])->getJson('/api/connector/ping');
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertArrayHasKey('plan', $res->json());
        $this->assertArrayHasKey('credits_remaining', $res->json());
        $this->assertStringNotContainsString('wp_', (string) $res->json('plan'), 'no wp_* ladder');
    }
}
