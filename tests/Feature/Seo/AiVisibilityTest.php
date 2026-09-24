<?php

namespace Tests\Feature\Seo;

use App\Core\Business\AiVisibilityService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * K11 (2026-09-25): report what happened, never what it means.
 *
 * The distinction a raw crawler count destroys: ChatGPT-User and
 * Perplexity-User fire when a PERSON asked something and the assistant went and
 * read the page. GPTBot and CCBot read it to build a corpus. Counting them
 * together produces a number that looks like reach and is not.
 *
 * And the harder discipline: none of it is a citation, and the report says so
 * every time.
 */
class AiVisibilityTest extends TestCase
{
    private int $user;
    private int $ws;
    private int $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = (int) DB::table('users')->insertGetId([
            'name' => 'K11 vis', 'email' => 'k11-' . uniqid() . '@example.test',
            'password' => password_hash('K11-Vis-Password-1', PASSWORD_BCRYPT),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'K11 Workspace', 'slug' => 'k11-' . uniqid(), 'timezone' => 'UTC',
            'created_by' => $this->user, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->site = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $this->ws, 'name' => 'K11 Site',
            'subdomain' => 'k11-' . uniqid() . '.levelupgrowth.io', 'status' => 'published',
            'domain_verified' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('aeo_traffic')->where('website_id', $this->site)->delete();
        DB::table('websites')->where('id', $this->site)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        DB::table('users')->where('id', $this->user)->delete();
        parent::tearDown();
    }

    private function hit(string $type, ?string $source, ?string $referer = null, int $daysAgo = 1): void
    {
        DB::table('aeo_traffic')->insert([
            'workspace_id' => $this->ws, 'website_id' => $this->site, 'type' => $type,
            'source' => $source, 'url' => 'https://example.test/', 'referer' => $referer,
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function service(): AiVisibilityService
    {
        return app(AiVisibilityService::class);
    }

    /** @dataProvider sources */
    public function test_a_source_is_classified_by_what_caused_the_fetch(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->service()->classify($source));
    }

    public static function sources(): array
    {
        return [
            'a person asked ChatGPT' => ['ChatGPT-User', AiVisibilityService::ANSWER_ENGINE_USER_FETCH],
            'a person asked Perplexity' => ['Perplexity-User', AiVisibilityService::ANSWER_ENGINE_USER_FETCH],
            'OpenAI search fetch' => ['OAI-SearchBot', AiVisibilityService::ANSWER_ENGINE_USER_FETCH],
            'OpenAI corpus crawl' => ['GPTBot', AiVisibilityService::AI_TRAINING_CRAWL],
            'Anthropic corpus crawl' => ['ClaudeBot', AiVisibilityService::AI_TRAINING_CRAWL],
            'Perplexity index crawl' => ['PerplexityBot', AiVisibilityService::AI_TRAINING_CRAWL],
            'Common Crawl' => ['CCBot', AiVisibilityService::AI_TRAINING_CRAWL],
            'ordinary search' => ['Bingbot', AiVisibilityService::SEARCH_CRAWL],
        ];
    }

    public function test_user_fetches_are_counted_apart_from_corpus_crawls(): void
    {
        $this->hit('crawler', 'ChatGPT-User');
        $this->hit('crawler', 'ChatGPT-User');
        $this->hit('crawler', 'GPTBot');
        $this->hit('crawler', 'Bingbot');

        $observed = $this->service()->forWebsite($this->site, 30)['observed'];

        $this->assertSame(2, array_sum(array_column($observed['answer_engine_user_fetches'], 'hits')));
        $this->assertSame(1, array_sum(array_column($observed['ai_training_crawls'], 'hits')));
        $this->assertSame(1, array_sum(array_column($observed['search_crawls'], 'hits')));
    }

    public function test_a_referral_from_an_assistant_is_separated_from_other_traffic(): void
    {
        $this->hit("referral", "referral", 'https://chatgpt.com/c/abc');
        $this->hit("referral", "referral", 'https://www.perplexity.ai/search/x');
        $this->hit("referral", "referral", 'https://news.ycombinator.com/item?id=1');

        $observed = $this->service()->forWebsite($this->site, 30)['observed'];

        $this->assertSame(2, array_sum(array_column($observed['answer_engine_referrals'], 'visits')));
        $this->assertSame(1, $observed['other_referrals']);
    }

    public function test_a_subdomain_of_an_answer_engine_still_counts(): void
    {
        $this->assertTrue($this->service()->isAnswerEngineHost('chatgpt.com'));
        $this->assertTrue($this->service()->isAnswerEngineHost('www.perplexity.ai'));
        $this->assertFalse($this->service()->isAnswerEngineHost('notchatgpt.com'));
        $this->assertFalse($this->service()->isAnswerEngineHost('example.test'));
    }

    public function test_the_window_is_respected(): void
    {
        $this->hit('crawler', 'ChatGPT-User', null, 2);
        $this->hit('crawler', 'ChatGPT-User', null, 99);

        $observed = $this->service()->forWebsite($this->site, 30)['observed'];

        $this->assertSame(1, array_sum(array_column($observed['answer_engine_user_fetches'], 'hits')));
    }

    public function test_one_website_never_reports_anothers_traffic(): void
    {
        $other = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $this->ws, 'name' => 'Other',
            'subdomain' => 'k11other-' . uniqid() . '.levelupgrowth.io', 'status' => 'published',
            'domain_verified' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aeo_traffic')->insert([
            'workspace_id' => $this->ws, 'website_id' => $other, 'type' => 'crawler',
            'source' => 'ChatGPT-User', 'url' => 'https://other.test/', 'created_at' => now()->subDay(),
        ]);

        $observed = $this->service()->forWebsite($this->site, 30)['observed'];
        $this->assertSame(0, array_sum(array_column($observed['answer_engine_user_fetches'], 'hits')));

        DB::table('aeo_traffic')->where('website_id', $other)->delete();
        DB::table('websites')->where('id', $other)->delete();
    }

    // ---- the limits travel with the numbers ------------------------------

    public function test_every_report_carries_what_it_does_not_prove(): void
    {
        $this->hit('crawler', 'ChatGPT-User');

        foreach ([$this->service()->forWebsite($this->site, 30), $this->service()->forWorkspace($this->ws, 30)] as $report) {
            $this->assertNotEmpty($report['not_observable']);
            $text = strtolower(implode(' ', $report['not_observable']));
            $this->assertStringContainsString('citation', $text, 'a fetch is a read, not a citation');
            $this->assertStringContainsString('rank', $text);
        }
    }

    public function test_the_report_contains_no_score_or_ranking(): void
    {
        $this->hit('crawler', 'ChatGPT-User');
        $report = $this->service()->forWebsite($this->site, 30);

        $flat = strtolower(json_encode(array_keys($report) + array_keys($report['observed'])));
        foreach (['score', 'rank', 'visibility_index', 'estimate'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat, "no invented {$forbidden}");
        }
    }

    public function test_search_console_figures_say_they_are_not_ai_overview(): void
    {
        $report = $this->service()->forWorkspace($this->ws, 30);

        $this->assertStringContainsString('AI Overview', $report['search_console']['note']);
    }
}
