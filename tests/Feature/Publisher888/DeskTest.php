<?php

namespace Tests\Feature\Publisher888;

use App\Core\Auth\RefreshTokenService;
use App\Engines\Publisher\Services\DeskService;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PUBLISHER888 Unit 1 (2026-09-04) — Publisher Desk: host branch, roles, stories, sections, jobs, inbox, scheduler.
 * Test DB rows are created in setUp and removed in tearDown.
 */
class DeskTest extends TestCase
{
    private int $ws = 999999901;
    private int $site = 999999902;     // kabayan-news (desk)
    private int $plainSite = 999999903; // amg-travel (no desk)
    private int $owner = 0;
    private int $member = 0;
    private array $madeUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->owner = $this->user('desk-owner-' . $this->ws . '@example.test', 'Desk Owner');
        $this->member = $this->user('desk-member-' . $this->ws . '@example.test', 'Desk Member');
        DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'Desk QA', 'slug' => 'desk-qa-' . $this->ws, 'created_by' => $this->owner, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert([
            ['workspace_id' => $this->ws, 'user_id' => $this->owner, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $this->ws, 'user_id' => $this->member, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('websites')->insert([
            ['id' => $this->site, 'workspace_id' => $this->ws, 'name' => 'Desk QA News', 'subdomain' => 'deskqa.levelupgrowth.io', 'status' => 'published', 'type' => 'builder', 'settings_json' => json_encode(['theme' => 'kabayan-news', 'article_base' => 'news']), 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->plainSite, 'workspace_id' => $this->ws, 'name' => 'Desk QA Plain', 'subdomain' => 'deskqaplain.levelupgrowth.io', 'status' => 'published', 'type' => 'builder', 'settings_json' => json_encode(['theme' => 'amg-travel']), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('blog_categories')->insert(['workspace_id' => $this->ws, 'name' => 'News', 'slug' => 'news', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void { $this->cleanup(); parent::tearDown(); }

    private function cleanup(): void
    {
        DB::table('desk_commissions')->where('workspace_id', $this->ws)->delete();
        DB::table('desk_members')->where('workspace_id', $this->ws)->delete();
        DB::table('activities')->where('workspace_id', $this->ws)->delete();
        DB::table('leads')->where('workspace_id', $this->ws)->delete();
        DB::table('job_listings')->where('workspace_id', $this->ws)->delete();
        DB::table('article_versions')->whereIn('article_id', DB::table('articles')->where('workspace_id', $this->ws)->pluck('id'))->delete();
        DB::table('articles')->where('workspace_id', $this->ws)->delete();
        DB::table('blog_categories')->where('workspace_id', $this->ws)->delete();
        DB::table('websites')->whereIn('id', [$this->site, $this->plainSite])->delete();
        DB::table('workspace_users')->where('workspace_id', $this->ws)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        DB::table('users')->where('email', 'like', 'desk-%-' . $this->ws . '@example.test')->delete();
    }

    private function user(string $email, string $name): int
    {
        return (int) DB::table('users')->insertGetId(['name' => $name, 'email' => $email, 'password' => bcrypt('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function token(int $userId): string
    {
        return app(RefreshTokenService::class)->issueAccessToken(User::find($userId), Workspace::find($this->ws), 'desk-test');
    }

    private function api(int $userId, string $method, string $path, array $data = [], ?int $site = null)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($userId), 'X-Desk-Website' => (string) ($site ?? $this->site), 'Accept' => 'application/json'])->json($method, '/api/desk' . $path, $data);
    }

    // ── host branch ──────────────────────────────────────────────────────────

    public function test_admin_on_a_desk_theme_host_serves_the_desk_not_the_platform_admin(): void
    {
        $r = $this->get('https://deskqa.levelupgrowth.io/admin');
        $r->assertStatus(200); $r->assertHeader('X-Served-By', 'publisher-desk');
        $this->assertStringContainsString('window.DESK', $r->getContent()); $this->assertStringContainsString('"website_id":' . $this->site, $r->getContent());
        $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'));
        $r2 = $this->get('https://deskqa.levelupgrowth.io/admin/stories/12');
        $r2->assertStatus(200); $r2->assertHeader('X-Served-By', 'publisher-desk');
        $plain = $this->get('https://deskqaplain.levelupgrowth.io/admin/login');
        $this->assertNotSame('publisher-desk', $plain->headers->get('X-Served-By'), 'a non-desk theme keeps the platform admin');
    }

    public function test_api_on_the_site_host_resolves_the_website_from_the_host(): void
    {
        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($this->owner), 'Accept' => 'application/json'])->getJson('https://deskqa.levelupgrowth.io/api/desk/context');
        $r->assertStatus(200)->assertJsonPath('site.id', $this->site)->assertJsonPath('role', 'owner');
    }

    // ── roles ────────────────────────────────────────────────────────────────

    public function test_roles_resolve_from_workspace_role_and_desk_overrides(): void
    {
        $this->api($this->owner, 'GET', '/context')->assertStatus(200)->assertJsonPath('role', 'owner');
        $this->api($this->member, 'GET', '/context')->assertStatus(200)->assertJsonPath('role', 'editor');
        $this->api($this->member, 'PUT', '/members/' . $this->owner, ['role' => 'viewer'])->assertStatus(403);
        $this->api($this->owner, 'PUT', '/members/' . $this->member, ['role' => 'moderator'])->assertStatus(200);
        $this->api($this->member, 'GET', '/context')->assertJsonPath('role', 'moderator');
        $this->api($this->member, 'POST', '/stories', ['title' => 'Nope'])->assertStatus(403);
        $this->api($this->owner, 'PUT', '/members/' . $this->owner, ['role' => 'viewer'])->assertStatus(422)->assertJsonPath('error', 'OWNER_LOCKED');
        $this->api($this->owner, 'GET', '/context', [], $this->plainSite)->assertStatus(404)->assertJsonPath('error', 'DESK_NOT_AVAILABLE');
        $stranger = $this->user('desk-stranger-' . $this->ws . '@example.test', 'Stranger');
        $this->assertSame(null, app(DeskService::class)->resolveRole($this->ws, $this->site, $stranger, null));
    }

    // ── stories ──────────────────────────────────────────────────────────────

    public function test_story_lifecycle_create_edit_gate_publish_unpublish_delete(): void
    {
        $body = '<p>' . str_repeat('Kabayan news sentence with enough words to pass the gate. ', 12) . '</p><script>alert(1)</script><p onclick="x">clean</p>';
        $c = $this->api($this->owner, 'POST', '/stories', ['title' => 'Desk story one', 'section' => 'news', 'content' => $body, 'type' => 'news']);
        $c->assertStatus(200)->assertJsonPath('story.status', 'draft')->assertJsonPath('story.section', 'news');
        $id = (int) $c->json('id');
        $row = DB::table('articles')->where('id', $id)->first();
        $this->assertSame($this->site, (int) $row->website_id); $this->assertSame(1, (int) $row->is_marketing_blog);
        $this->assertStringNotContainsString('<script', $row->content); $this->assertStringNotContainsString('onclick', $row->content);
        $this->assertStringContainsString('Desk QA News Desk', (string) $row->brief_json);

        // gate: missing section blocks publish
        $short = $this->api($this->owner, 'POST', '/stories', ['title' => 'Too short', 'content' => '<p>tiny</p>']);
        $this->api($this->owner, 'POST', '/stories/' . $short->json('id') . '/publish')->assertStatus(422)->assertJsonPath('error', 'NOT_PUBLISHABLE');

        $this->api($this->owner, 'PUT', '/stories/' . $id, ['excerpt' => 'A short excerpt', 'author' => 'Maria Reporter', 'sources' => [['label' => 'DMW', 'url' => 'https://dmw.gov.ph']], 'tags' => ['OWWA', 'Dubai']])
            ->assertStatus(200)->assertJsonPath('story.author', 'Maria Reporter')->assertJsonPath('story.tags.0', 'owwa');
        $p = $this->api($this->owner, 'POST', '/stories/' . $id . '/publish');
        $p->assertStatus(200)->assertJsonPath('story.status', 'published');
        $this->assertStringContainsString('https://deskqa.levelupgrowth.io/news/', (string) $p->json('story.url'));
        $this->assertNotNull(DB::table('articles')->where('id', $id)->value('published_at'));

        $list = $this->api($this->owner, 'GET', '/stories?status=published');
        $list->assertStatus(200)->assertJsonPath('total', 1);
        // other site's article never leaks in
        DB::table('articles')->insert(['workspace_id' => $this->ws, 'website_id' => $this->plainSite, 'title' => 'Other site', 'slug' => 'other-site-x', 'content' => 'x', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $this->api($this->owner, 'GET', '/stories?status=all')->assertJsonPath('total', 2);

        $this->api($this->owner, 'POST', '/stories/' . $id . '/unpublish')->assertJsonPath('story.status', 'draft');
        $this->api($this->owner, 'DELETE', '/stories/' . $id)->assertStatus(200);
        $this->api($this->owner, 'GET', '/stories/' . $id)->assertStatus(404);
        $this->assertNotNull(DB::table('articles')->where('id', $id)->value('deleted_at'), 'soft delete, never hard delete');
    }

    public function test_schedule_then_scheduler_publishes_due_but_skips_stale(): void
    {
        $body = '<p>' . str_repeat('Scheduled words for the gate. ', 20) . '</p>';
        $id = (int) $this->api($this->owner, 'POST', '/stories', ['title' => 'Scheduled story', 'section' => 'news', 'content' => $body])->json('id');
        $this->api($this->owner, 'POST', '/stories/' . $id . '/schedule', ['at' => now()->addHours(2)->toDateTimeString()])->assertStatus(200)->assertJsonPath('story.status', 'scheduled');
        $this->api($this->owner, 'POST', '/stories/' . $id . '/schedule', ['at' => now()->subHour()->toDateTimeString()])->assertStatus(422);
        // make it due; add a stale one from last month
        DB::table('articles')->where('id', $id)->update(['scheduled_at' => now()->subMinutes(2)]);
        DB::table('articles')->insert(['workspace_id' => $this->ws, 'website_id' => $this->site, 'title' => 'Stale', 'slug' => 'stale-x', 'content' => 'x', 'status' => 'scheduled', 'scheduled_at' => now()->subDays(30), 'created_at' => now(), 'updated_at' => now()]);
        $r = app(DeskService::class)->publishDue(24);
        $this->assertContains($id, $r['published']); $this->assertGreaterThanOrEqual(1, $r['stale_skipped']);
        $this->assertSame('published', DB::table('articles')->where('id', $id)->value('status'));
        $this->assertSame('scheduled', DB::table('articles')->where('slug', 'stale-x')->value('status'));
        $this->artisan('publisher:publish-scheduled')->assertExitCode(0);
    }

    public function test_commission_reconciles_a_finished_write_task_into_a_site_story(): void
    {
        $aid = (int) DB::table('articles')->insertGetId(['workspace_id' => $this->ws, 'website_id' => null, 'title' => 'Priya wrote this', 'slug' => 'priya-wrote-this-x', 'content' => '<p>text</p>', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $tid = (int) DB::table('tasks')->insertGetId(['workspace_id' => $this->ws, 'engine' => 'write', 'action' => 'write_article', 'category' => 'create', 'payload_json' => json_encode(['topic' => 'x']), 'status' => 'completed', 'source' => 'manual', 'result_json' => json_encode(['success' => true, 'article_id' => $aid]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('desk_commissions')->insert(['workspace_id' => $this->ws, 'website_id' => $this->site, 'task_id' => $tid, 'title' => 'Priya wrote this', 'section_slug' => 'news', 'type' => 'article', 'status' => 'queued', 'requested_by' => $this->owner, 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->api($this->owner, 'GET', '/commissions');
        $r->assertStatus(200)->assertJsonPath('commissions.0.status', 'ready')->assertJsonPath('commissions.0.article_id', $aid);
        $a = DB::table('articles')->where('id', $aid)->first();
        $this->assertSame($this->site, (int) $a->website_id); $this->assertSame('news', $a->blog_category); $this->assertSame(1, (int) $a->is_marketing_blog);
        $this->api($this->owner, 'GET', '/stories?status=draft')->assertJsonPath('total', 1);
        DB::table('tasks')->where('id', $tid)->delete();
    }

    // ── sections ─────────────────────────────────────────────────────────────

    public function test_sections_crud_and_in_use_guard(): void
    {
        $this->api($this->member, 'POST', '/sections', ['name' => 'Entertainment'])->assertStatus(200)->assertJsonPath('slug', 'entertainment');
        $this->api($this->member, 'POST', '/sections', ['name' => 'Entertainment'])->assertStatus(422)->assertJsonPath('error', 'DUPLICATE');
        $sid = (int) DB::table('blog_categories')->where('workspace_id', $this->ws)->where('slug', 'entertainment')->value('id');
        $this->api($this->owner, 'POST', '/stories', ['title' => 'Showbiz', 'section' => 'entertainment', 'content' => '<p>x</p>'])->assertStatus(200);
        $this->api($this->owner, 'DELETE', '/sections/' . $sid)->assertStatus(422)->assertJsonPath('error', 'IN_USE');
        $this->api($this->owner, 'PUT', '/sections/' . $sid, ['name' => 'Showbiz & Music', 'slug' => 'showbiz'])->assertStatus(200);
        $this->assertSame('showbiz', DB::table('articles')->where('workspace_id', $this->ws)->where('title', 'Showbiz')->value('blog_category'), 'renaming a slug moves its stories');
        $this->api($this->owner, 'GET', '/sections')->assertStatus(200)->assertJsonCount(2, 'sections');
    }

    // ── jobs ─────────────────────────────────────────────────────────────────

    public function test_jobs_moderation_roles_and_publish_gate(): void
    {
        $this->api($this->owner, 'PUT', '/members/' . $this->member, ['role' => 'moderator'])->assertStatus(200);
        $c = $this->api($this->member, 'POST', '/jobs', ['title' => 'Barista', 'company' => 'Kape Co', 'city' => 'Dubai', 'description' => 'Make coffee.']);
        $c->assertStatus(200)->assertJsonPath('job.status', 'draft'); $jid = (int) $c->json('job_id');
        $this->api($this->member, 'POST', '/jobs/' . $jid . '/publish')->assertStatus(422)->assertJsonPath('error', 'NOT_PUBLISHABLE');
        $this->api($this->member, 'PUT', '/jobs/' . $jid, ['apply_email' => 'jobs@kape.example', 'verification_source' => 'https://kape.example/careers'])->assertStatus(200);
        $this->api($this->member, 'POST', '/jobs/' . $jid . '/publish')->assertStatus(200)->assertJsonPath('job.status', 'published');
        $this->api($this->owner, 'PUT', '/members/' . $this->member, ['role' => 'editor'])->assertStatus(200);
        $this->api($this->member, 'POST', '/jobs/' . $jid . '/status', ['status' => 'expired'])->assertStatus(403, 'editors may draft jobs but not publish/expire');
        $this->api($this->owner, 'POST', '/jobs/' . $jid . '/status', ['status' => 'expired'])->assertStatus(200)->assertJsonPath('job.status', 'expired');
        $this->api($this->owner, 'GET', '/jobs?status=all')->assertJsonPath('total', 1);
    }

    // ── inbox ────────────────────────────────────────────────────────────────

    public function test_inbox_lists_site_leads_updates_status_and_drafts_a_job(): void
    {
        $lid = (int) DB::table('leads')->insertGetId(['workspace_id' => $this->ws, 'website_id' => $this->site, 'name' => 'QA Employer', 'email' => 'employer@example.test', 'source' => 'job_post', 'status' => 'new', 'score' => 0, 'deal_value' => 0,
            'metadata_json' => json_encode(['first_message' => "JOB POST\nCompany: QA Co\nTitle: Delivery rider\nCity: Sharjah", 'submitted_at' => now()->toIso8601String()]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leads')->insert(['workspace_id' => $this->ws, 'website_id' => $this->plainSite, 'name' => 'Other', 'email' => 'o@example.test', 'source' => 'website_form', 'status' => 'new', 'score' => 0, 'deal_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->api($this->owner, 'GET', '/inbox')->assertStatus(200)->assertJsonPath('total', 1)->assertJsonPath('items.0.source', 'job_post');
        $this->api($this->owner, 'PUT', '/inbox/' . $lid, ['status' => 'contacted', 'note' => 'Called them'])->assertStatus(200)->assertJsonPath('item.status', 'contacted')->assertJsonCount(1, 'item.timeline');
        $j = $this->api($this->owner, 'POST', '/inbox/' . $lid . '/job');
        $j->assertStatus(200)->assertJsonPath('job.title', 'Delivery rider')->assertJsonPath('job.company', 'QA Co')->assertJsonPath('job.city', 'Sharjah')->assertJsonPath('job.status', 'draft');
        $this->assertSame($lid, (int) DB::table('job_listings')->where('id', $j->json('job_id'))->value('lead_id'));
    }
}
