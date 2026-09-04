<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Schema\SectionSchema;
use App\Engines\Builder\Services\BuilderRenderer;
use App\Engines\Builder\Services\KabayanNewsTheme;
use App\Engines\Jobs\Services\JobsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KABAYAN888 JOBS-1 (2026-09-04) — job portal: contract, engine gates, renderers.
 * Uses the test DB (levelup_test); rows are cleaned up after each test.
 */
class JobsPortalTest extends TestCase
{
    private int $ws = 999999901;
    private int $site = 999999901;
    private int $madeUser = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('websites')->where('id', $this->site)->delete();
        DB::table('job_listings')->where('workspace_id', $this->ws)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        $uid = (int) (DB::table('users')->min('id') ?: 0);
        if ($uid <= 0) { $uid = (int) DB::table('users')->insertGetId(['name' => 'Jobs QA', 'email' => 'jobsqa-' . $this->ws . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]); $this->madeUser = $uid; }
        DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'Jobs QA', 'slug' => 'jobs-qa-' . $this->ws, 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert(['id' => $this->site, 'workspace_id' => $this->ws, 'name' => 'Jobs QA', 'subdomain' => 'jobsqa.levelupgrowth.io', 'status' => 'published', 'type' => 'builder',
            'settings_json' => json_encode(['theme' => 'kabayan-news']), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        DB::table('job_listings')->where('workspace_id', $this->ws)->delete();
        DB::table('websites')->where('id', $this->site)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        if ($this->madeUser > 0) DB::table('users')->where('id', $this->madeUser)->delete();
        parent::tearDown();
    }

    public function test_jobs_board_is_in_the_section_contract(): void
    {
        $this->assertContains('jobs_board', SectionSchema::allowedTypes());
        $this->assertContains('show_post_form', SectionSchema::allowedFieldsFor('jobs_board'));
    }

    public function test_publish_is_gated_until_the_listing_can_be_verified_and_applied_to(): void
    {
        $svc = app(JobsService::class);
        $r = $svc->create($this->ws, ['title' => 'Barista', 'company' => 'Kape Co', 'city' => 'Dubai', 'website_id' => $this->site, 'description' => 'Make coffee.']);
        $this->assertTrue($r['success']); $id = $r['job_id'];
        $p = $svc->publish($this->ws, $id);
        $this->assertFalse($p['success']); $this->assertSame('NOT_PUBLISHABLE', $p['error']);
        $this->assertStringContainsString('apply_url', $p['message']); $this->assertStringContainsString('verification_source', $p['message']);
        $svc->update($this->ws, $id, ['apply_email' => 'jobs@kape.example', 'verification_source' => 'https://kape.example/careers']);
        $p2 = $svc->publish($this->ws, $id);
        $this->assertTrue($p2['success']); $this->assertSame('published', $p2['status']);
        $this->assertStringContainsString('/jobs/barista-kape-co', $p2['url']);
        $row = DB::table('job_listings')->where('id', $id)->first();
        $this->assertNotNull($row->posted_at); $this->assertNotNull($row->expires_at); $this->assertSame(1, (int) $row->is_verified);
    }

    public function test_create_needs_an_unambiguous_website_and_sanitises_html(): void
    {
        $svc = app(JobsService::class);
        $r = $svc->create($this->ws, ['title' => 'X', 'company' => 'Y', 'website_id' => 42]);
        $this->assertFalse($r['success']); $this->assertSame('WEBSITE_REQUIRED', $r['error']);
        $r2 = $svc->create($this->ws, ['title' => 'Nurse', 'company' => 'Clinic', 'website_id' => $this->site, 'description' => '<p>Care</p><script>alert(1)</script><a href="javascript:x" onclick="y">link</a>', 'apply_url' => 'javascript:alert(1)', 'salary_min' => -5]);
        $row = DB::table('job_listings')->where('id', $r2['job_id'])->first();
        $this->assertStringNotContainsString('<script', $row->description); $this->assertStringNotContainsString('onclick', $row->description); $this->assertStringNotContainsString('javascript:', $row->description);
        $this->assertNull($row->apply_url); $this->assertSame(0, (int) $row->salary_min);
    }

    public function test_cross_workspace_ids_are_not_found(): void
    {
        $svc = app(JobsService::class);
        $r = $svc->create($this->ws, ['title' => 'Driver', 'company' => 'Fleet', 'website_id' => $this->site]);
        $foreign = $svc->update($this->ws + 1, $r['job_id'], ['title' => 'Hacked']);
        $this->assertFalse($foreign['success']); $this->assertSame('NOT_FOUND', $foreign['error']);
        $this->assertSame('Driver', DB::table('job_listings')->where('id', $r['job_id'])->value('title'));
    }

    public function test_board_and_job_page_render_published_only(): void
    {
        $svc = app(JobsService::class);
        $pub = $svc->create($this->ws, ['title' => 'Chef de Partie', 'company' => 'Hotel One', 'city' => 'Abu Dhabi', 'website_id' => $this->site, 'description' => 'Cook.', 'apply_url' => 'https://hotel.example/apply', 'verification_source' => 'https://hotel.example/careers', 'salary_text' => 'AED 3,500', 'publish' => true]);
        $draft = $svc->create($this->ws, ['title' => 'Secret Draft', 'company' => 'Hidden', 'city' => 'Dubai', 'website_id' => $this->site]);
        $this->assertSame('published', $pub['status']);
        $website = (array) DB::table('websites')->where('id', $this->site)->first();
        $theme = new KabayanNewsTheme();
        $html = $theme->renderBody([['type' => 'header'], ['type' => 'jobs_board', 'heading' => 'Jobs', 'show_filters' => true, 'show_post_form' => true], ['type' => 'footer']], ['primary' => '#0038A8'], $website, ['slug' => 'jobs']);
        $this->assertStringContainsString('Chef de Partie', $html); $this->assertStringNotContainsString('Secret Draft', $html);
        $this->assertStringContainsString('data-kb-jobs-filters', $html); $this->assertStringContainsString('id="kb-postjob"', $html);
        $this->assertStringContainsString('class="is-active" aria-current="page" href="/jobs"', $html, 'bottom nav marks Jobs active');
        $page = app(BuilderRenderer::class)->renderJob('jobsqa', $pub['slug']);
        $this->assertNotNull($page);
        $this->assertStringContainsString('"@type":"JobPosting"', $page); $this->assertStringContainsString('Apply now', $page); $this->assertStringContainsString('class="kb-jobpage"', $page);
        $this->assertNull(app(BuilderRenderer::class)->renderJob('jobsqa', $draft['slug']), 'drafts have no public page');
        $generic = app(BuilderRenderer::class)->renderSection(['type' => 'jobs_board', 'heading' => 'Open roles'], ['primary' => '#123456'], $website, [], 'jobs');
        $this->assertStringContainsString('Chef de Partie', $generic);
    }
}
