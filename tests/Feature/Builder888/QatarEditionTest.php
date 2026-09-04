<?php

namespace Tests\Feature\Builder888;

use App\Core\Auth\RefreshTokenService;
use App\Engines\Builder\Services\KabayanNewsTheme;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KABAYAN888 QATAR-1 (2026-09-04) — editions: theme feed filter, public API, desk story/jobs/inbox region handling.
 */
class QatarEditionTest extends TestCase
{
    private int $ws = 999999901;
    private int $site = 999999904;
    private int $user = 0;

    protected function setUp(): void
    {
        parent::setUp(); $this->cleanup();
        $this->user = (int) DB::table('users')->insertGetId(['name' => 'Ed QA', 'email' => 'edition-qa-' . $this->ws . '@example.test', 'password' => bcrypt('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'Edition QA', 'slug' => 'edition-qa-' . $this->ws, 'created_by' => $this->user, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => $this->ws, 'user_id' => $this->user, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('websites')->insert(['id' => $this->site, 'workspace_id' => $this->ws, 'name' => 'Edition QA', 'subdomain' => 'editionqa.levelupgrowth.io', 'status' => 'published', 'type' => 'builder',
            'settings_json' => json_encode(['theme' => 'kabayan-news', 'article_base' => 'news', 'regions' => [['code' => 'AE'], ['code' => 'QA']]]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('blog_categories')->insert(['workspace_id' => $this->ws, 'name' => 'Government', 'slug' => 'government', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['UAE story', 'AE'], ['Qatar story', 'QA'], ['Everywhere story', null]] as $i => $s) {
            DB::table('articles')->insert(['workspace_id' => $this->ws, 'website_id' => $this->site, 'title' => $s[0], 'slug' => 'ed-' . $i . '-' . $this->ws, 'content' => '<p>' . str_repeat('word ', 100) . '</p>', 'status' => 'published', 'is_marketing_blog' => 1, 'type' => 'article', 'blog_category' => 'government',
                'brief_json' => json_encode(array_filter(['author' => 'Desk', 'region' => $s[1]])), 'published_at' => now()->subMinutes(3 - $i), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    protected function tearDown(): void { $this->cleanup(); parent::tearDown(); }
    private function cleanup(): void
    {
        DB::table('desk_commissions')->where('workspace_id', $this->ws)->delete(); DB::table('desk_members')->where('workspace_id', $this->ws)->delete();
        DB::table('activities')->where('workspace_id', $this->ws)->delete(); DB::table('leads')->where('workspace_id', $this->ws)->delete(); DB::table('job_listings')->where('workspace_id', $this->ws)->delete();
        DB::table('article_versions')->whereIn('article_id', DB::table('articles')->where('workspace_id', $this->ws)->pluck('id'))->delete();
        DB::table('articles')->where('workspace_id', $this->ws)->delete(); DB::table('blog_categories')->where('workspace_id', $this->ws)->delete();
        DB::table('websites')->where('id', $this->site)->delete(); DB::table('workspace_users')->where('workspace_id', $this->ws)->delete(); DB::table('workspaces')->where('id', $this->ws)->delete();
        DB::table('users')->where('email', 'edition-qa-' . $this->ws . '@example.test')->delete();
    }
    private function api(string $method, string $path, array $data = [])
    {
        $tok = app(RefreshTokenService::class)->issueAccessToken(User::find($this->user), Workspace::find($this->ws), 'test');
        return $this->withHeaders(['Authorization' => 'Bearer ' . $tok, 'X-Desk-Website' => (string) $this->site, 'Accept' => 'application/json'])->json($method, '/api/desk' . $path, $data);
    }

    public function test_theme_feed_filters_by_edition_and_keeps_shared_stories(): void
    {
        $website = (array) DB::table('websites')->where('id', $this->site)->first(); $theme = new KabayanNewsTheme();
        $qa = $theme->renderBody([['type' => 'news_feed', 'layout' => 'list', 'limit' => 12, 'region' => 'QA']], ['primary' => '#0038A8'], $website, ['slug' => 'qatar']);
        $this->assertStringContainsString('Qatar story', $qa); $this->assertStringContainsString('Everywhere story', $qa); $this->assertStringNotContainsString('UAE story', $qa);
        $this->assertStringContainsString('Qatar · Government', $qa, 'multi-edition sites show the edition in the kicker');
        $all = $theme->renderBody([['type' => 'news_feed', 'layout' => 'list', 'limit' => 12]], ['primary' => '#0038A8'], $website, ['slug' => 'government']);
        $this->assertStringContainsString('data-kb-edition', $all, 'open list feeds get edition chips'); $this->assertStringContainsString('UAE story', $all); $this->assertStringContainsString('Qatar story', $all);
        $this->assertStringContainsString('name="country"', $theme->renderBody([['type' => 'jobs_board', 'show_filters' => true, 'show_post_form' => true]], ['primary' => '#0038A8'], $website, ['slug' => 'jobs']), 'jobs filters + post form carry a country choice');
    }

    public function test_public_api_region_param(): void
    {
        $r = $this->getJson('/api/public/news/editionqa/stories?region=qa'); $r->assertStatus(200);
        $titles = array_column($r->json('posts'), 'title'); sort($titles);
        $this->assertSame(['Everywhere story', 'Qatar story'], $titles);
        $this->assertSame('QA', collect($r->json('posts'))->firstWhere('title', 'Qatar story')['region']);
        $this->assertCount(3, $this->getJson('/api/public/news/editionqa/stories')->json('posts'));
    }

    public function test_desk_exposes_regions_and_sets_story_edition(): void
    {
        $ctx = $this->api('GET', '/context'); $ctx->assertStatus(200)->assertJsonCount(2, 'regions')->assertJsonPath('regions.1.short', 'Qatar')->assertJsonPath('regions.1.tz_label', 'AST');
        $id = (int) DB::table('articles')->where('workspace_id', $this->ws)->where('title', 'Everywhere story')->value('id');
        $this->api('PUT', '/stories/' . $id, ['region' => 'qa'])->assertStatus(200)->assertJsonPath('story.region', 'QA');
        $this->api('PUT', '/stories/' . $id, ['region' => 'XX'])->assertStatus(200)->assertJsonPath('story.region', 'ALL', 'unknown codes fall back to every edition');
        $this->api('PUT', '/stories/' . $id, ['region' => 'QA']);
        $this->api('GET', '/stories?region=QA')->assertJsonPath('total', 2);
        $this->api('GET', '/stories?region=ALL')->assertJsonPath('total', 0);
        $this->api('GET', '/stories?region=AE')->assertJsonPath('total', 1);
    }

    public function test_jobs_country_is_an_edition_and_inbox_parses_country(): void
    {
        $j = $this->api('POST', '/jobs', ['title' => 'Nurse', 'company' => 'Doha Clinic', 'city' => 'Doha', 'country' => 'Qatar']);
        $j->assertStatus(200)->assertJsonPath('job.country', 'QA');
        $this->api('PUT', '/jobs/' . $j->json('job_id'), ['country' => 'ZZ'])->assertStatus(200)->assertJsonPath('job.country', 'QA', 'unknown country ignored');
        $lid = (int) DB::table('leads')->insertGetId(['workspace_id' => $this->ws, 'website_id' => $this->site, 'name' => 'Employer', 'email' => 'e@example.test', 'source' => 'job_post', 'status' => 'new', 'score' => 0, 'deal_value' => 0,
            'metadata_json' => json_encode(['first_message' => "JOB POST\nCompany: Lusail Co\nTitle: Driver\nCountry: Qatar\nCity: Lusail"]), 'created_at' => now(), 'updated_at' => now()]);
        $this->api('POST', '/inbox/' . $lid . '/job')->assertStatus(200)->assertJsonPath('job.country', 'QA')->assertJsonPath('job.city', 'Lusail');
    }
}
