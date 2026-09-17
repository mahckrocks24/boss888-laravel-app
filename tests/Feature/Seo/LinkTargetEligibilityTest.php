<?php

namespace Tests\Feature\Seo;

use App\Engines\SEO\Services\SeoService;
use App\Engines\SEO\Support\LinkTargetEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * RISK-0189 (2026-09-17) — a draft Sarah's QA rejected is never an internal-link destination: not suggested, not rescued
 * as an orphan, not inserted from an earlier suggestion, and withdrawn from the graph by the verdict itself.
 */
class LinkTargetEligibilityTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private int $ws; private int $site; private string $host = 'https://qa-bakery.levelupgrowth.io';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        $this->ws = $this->testWorkspace->id;
        $this->site = (int) DB::table('websites')->insertGetId(['workspace_id' => $this->ws, 'name' => 'QA Bakery', 'subdomain' => 'qa-bakery.levelupgrowth.io', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function article(string $slug, string $title, ?string $qa = null, string $body = ''): int
    {
        $body = $body ?: str_repeat('<p>Sourdough starters, flour, hydration and patience make a loaf worth waiting for. </p>', 12);
        $brief = $qa ? ['qa' => ['status' => $qa, 'reasons' => ['Off-topic'], 'at' => now()->toIso8601String()]] : [];
        $id = (int) DB::table('articles')->insertGetId(['workspace_id' => $this->ws, 'website_id' => $this->site, 'title' => $title, 'slug' => $slug, 'content' => $body, 'status' => 'draft', 'type' => 'blog_post',
            'brief_json' => json_encode($brief), 'focus_keyword' => explode(' ', strtolower($title))[0], 'word_count' => 200, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seo_content_index')->insert(['workspace_id' => $this->ws, 'website_id' => $this->site, 'url' => $this->host . '/blog/' . $slug, 'url_hash' => sha1($this->host . '/blog/' . $slug), 'title' => $title, 'h1' => $title,
            'meta_description' => $title . ' — sourdough baking guide', 'word_count' => 200, 'inbound_links' => 0, 'internal_link_count' => 0, 'authority_score' => 0.5, 'indexed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function test_eligibility_reads_the_qa_verdict_on_the_article_behind_the_url(): void
    {
        $this->article('sourdough-starters-guide-abc1', 'Sourdough Starters Guide');
        $this->article('morning-vinyasa-beginners-xyz9', 'Morning Vinyasa for Beginners', 'rejected');
        $this->article('needs-owner-piece-q1', 'Needs Owner Piece', 'needs_owner');
        $this->assertTrue(LinkTargetEligibility::isEligibleTarget($this->ws, $this->host . '/blog/sourdough-starters-guide-abc1'));
        $this->assertSame('qa_rejected', LinkTargetEligibility::assess($this->ws, $this->host . '/blog/morning-vinyasa-beginners-xyz9')['reason']);
        $this->assertFalse(LinkTargetEligibility::isEligibleTarget($this->ws, $this->host . '/blog/needs-owner-piece-q1'));
        $this->assertTrue(LinkTargetEligibility::isEligibleTarget($this->ws, $this->host . '/'), 'non-article pages are unchanged');
        $this->assertTrue(LinkTargetEligibility::isEligibleTarget($this->ws, $this->host . '/blog/never-indexed-slug'), 'an unknown slug is not this predicate\'s concern');
    }

    public function test_a_rejected_draft_is_not_suggested_and_an_accepted_one_still_is(): void
    {
        $src = $this->article('sourdough-scoring-tips-src1', 'Sourdough Scoring Tips');
        $this->article('sourdough-starters-guide-abc1', 'Sourdough Starters Guide');
        $this->article('sourdough-vinyasa-mix-bad1', 'Sourdough Morning Vinyasa for Beginners', 'rejected');
        $r = app(SeoService::class)->generateLinkSuggestions($this->ws, ['article_id' => $src]);
        $targets = DB::table('seo_links')->where('workspace_id', $this->ws)->pluck('target_url')->all();
        $this->assertContains($this->host . '/blog/sourdough-starters-guide-abc1', $targets, json_encode($r));
        $this->assertNotContains($this->host . '/blog/sourdough-vinyasa-mix-bad1', $targets, 'the rejected draft is never offered');
    }

    public function test_a_suggestion_made_before_the_verdict_cannot_be_inserted_afterwards(): void
    {
        $src = $this->article('sourdough-scoring-tips-src1', 'Sourdough Scoring Tips');
        $tgt = $this->article('sourdough-vinyasa-mix-bad1', 'Sourdough Morning Vinyasa for Beginners');
        $linkId = (int) DB::table('seo_links')->insertGetId(['workspace_id' => $this->ws, 'source_url' => $this->host . '/blog/sourdough-scoring-tips-src1', 'target_url' => $this->host . '/blog/sourdough-vinyasa-mix-bad1',
            'anchor_text' => 'sourdough starters', 'type' => 'internal', 'status' => 'suggested', 'priority_score' => 0.9, 'created_at' => now(), 'updated_at' => now()]);
        // the verdict lands after the suggestion was cached
        DB::table('articles')->where('id', $tgt)->update(['brief_json' => json_encode(['qa' => ['status' => 'rejected', 'reasons' => ['Off-topic']]])]);
        $before = DB::table('articles')->where('id', $src)->value('content');
        $r = app(SeoService::class)->aiApplyLinkInsertion($this->ws, $linkId);
        $this->assertFalse((bool) ($r['success'] ?? false));
        $this->assertSame('target_ineligible', $r['error']);
        $this->assertSame('dismissed', DB::table('seo_links')->where('id', $linkId)->value('status'));
        $this->assertSame($before, DB::table('articles')->where('id', $src)->value('content'), 'nothing was written into the source');
        $this->assertSame(0, DB::table('seo_link_graph')->where('workspace_id', $this->ws)->count());
    }

    public function test_orphan_rescue_never_rescues_a_rejected_draft(): void
    {
        $this->article('sourdough-scoring-tips-src1', 'Sourdough Scoring Tips');
        $this->article('sourdough-vinyasa-mix-bad1', 'Sourdough Morning Vinyasa for Beginners', 'rejected');
        $r = app(SeoService::class)->fixOrphans($this->ws, []);
        $targets = DB::table('seo_links')->where('workspace_id', $this->ws)->pluck('target_url')->all();
        $this->assertNotContains($this->host . '/blog/sourdough-vinyasa-mix-bad1', $targets, json_encode($r));
        $this->assertStringNotContainsString('sourdough-vinyasa-mix-bad1', (string) DB::table('articles')->where('slug', 'sourdough-scoring-tips-src1')->value('content'));
    }

    public function test_the_verdict_withdraws_the_draft_from_the_graph_and_dismisses_pending_suggestions(): void
    {
        $tgt = $this->article('morning-vinyasa-beginners-xyz9', 'Morning Vinyasa for Beginners');
        $this->article('rye-bread-guide-r1', 'Rye Bread Guide');
        DB::table('seo_links')->insert(['workspace_id' => $this->ws, 'source_url' => $this->host . '/blog/rye-bread-guide-r1', 'target_url' => $this->host . '/blog/morning-vinyasa-beginners-xyz9', 'anchor_text' => 'x', 'type' => 'internal', 'status' => 'suggested', 'priority_score' => 0.5, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seo_links')->insert(['workspace_id' => $this->ws, 'source_url' => $this->host . '/blog/rye-bread-guide-r1', 'target_url' => $this->host . '/blog/morning-vinyasa-beginners-xyz9', 'anchor_text' => 'y', 'type' => 'internal', 'status' => 'inserted', 'priority_score' => 0.5, 'created_at' => now(), 'updated_at' => now()]);
        $wd = LinkTargetEligibility::withdrawArticle($this->ws, $tgt);
        $this->assertSame(['index_rows' => 1, 'suggestions_dismissed' => 1], $wd);
        $this->assertSame(0, DB::table('seo_content_index')->where('workspace_id', $this->ws)->where('url', 'like', '%morning-vinyasa%')->count());
        $this->assertSame('inserted', DB::table('seo_links')->where('workspace_id', $this->ws)->where('anchor_text', 'y')->value('status'), 'an inserted link is another article\'s content — not touched here');
        $this->assertSame(1, DB::table('seo_content_index')->where('workspace_id', $this->ws)->where('url', 'like', '%rye-bread%')->count(), 'other pages stay indexed');
    }
}
