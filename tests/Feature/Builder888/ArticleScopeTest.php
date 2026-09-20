<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\ArticleScope;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RISK-0198 (2026-09-20) — an article with no website belongs to the workspace's first website only; a second website
 * of the same workspace never lists, serves or sitemaps it. Bound articles stay with their website.
 */
class ArticleScopeTest extends TestCase
{
    private int $ws = 998878; private array $sites = []; private array $articles = [];

    protected function setUp(): void
    {
        parent::setUp(); ArticleScope::reset();
        DB::table('articles')->where('workspace_id', $this->ws)->delete(); DB::table('websites')->where('workspace_id', $this->ws)->delete(); DB::table('workspaces')->where('id', $this->ws)->delete();
        $uid = (int) (DB::table('users')->min('id') ?: 0); if ($uid <= 0) { $uid = (int) DB::table('users')->insertGetId(['name' => 'Scope QA', 'email' => 'scopeqa-' . $this->ws . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]); }
        DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'Scope QA', 'slug' => 'scope-qa-' . $this->ws, 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['first', 'second'] as $n) { $this->sites[$n] = (int) DB::table('websites')->insertGetId(['workspace_id' => $this->ws, 'name' => "Scope $n", 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]); }
        $row = fn($title, $wid) => ['workspace_id' => $this->ws, 'website_id' => $wid, 'title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title)), 'content' => '<p>x</p>', 'status' => 'published', 'is_marketing_blog' => 1, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        $this->articles['unbound'] = (int) DB::table('articles')->insertGetId($row('Unbound article', null));
        $this->articles['first'] = (int) DB::table('articles')->insertGetId($row('First site article', $this->sites['first']));
        $this->articles['second'] = (int) DB::table('articles')->insertGetId($row('Second site article', $this->sites['second']));
    }

    protected function tearDown(): void
    {
        DB::table('articles')->where('workspace_id', $this->ws)->delete(); DB::table('websites')->where('workspace_id', $this->ws)->delete(); DB::table('workspaces')->where('id', $this->ws)->delete(); ArticleScope::reset();
        parent::tearDown();
    }

    private function titlesFor(int $websiteId): array
    {
        $q = DB::table('articles')->where('workspace_id', $this->ws)->where('status', 'published')->whereNull('deleted_at');
        $q->where(fn ($w) => ArticleScope::forWebsite($w, $this->ws, $websiteId));
        return $q->orderBy('id')->pluck('title')->all();
    }

    public function test_an_unbound_article_belongs_to_the_first_website_only(): void
    {
        $this->assertSame($this->sites['first'], ArticleScope::primaryWebsite($this->ws));
        $this->assertSame(['Unbound article', 'First site article'], $this->titlesFor($this->sites['first']));
        $this->assertSame(['Second site article'], $this->titlesFor($this->sites['second']), 'the second website never sees the unbound article');
    }

    public function test_a_deleted_first_website_hands_the_unbound_articles_to_the_next_one(): void
    {
        DB::table('websites')->where('id', $this->sites['first'])->update(['deleted_at' => now()]); ArticleScope::reset();
        $this->assertSame($this->sites['second'], ArticleScope::primaryWebsite($this->ws));
        $this->assertSame(['Unbound article', 'Second site article'], $this->titlesFor($this->sites['second']));
    }

    public function test_no_website_means_no_narrowing_for_workspace_level_callers(): void
    {
        $q = DB::table('articles')->where('workspace_id', $this->ws); $q->where(fn ($w) => ArticleScope::forWebsite($w, $this->ws, 0));
        $this->assertCount(3, $q->get());
    }
}
