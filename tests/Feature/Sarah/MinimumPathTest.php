<?php

namespace Tests\Feature\Sarah;

use App\Core\Orchestration\ToolSchemaService;
use App\Core\Sarah888\MinimumPath;
use App\Core\Sarah888\RouterIntent;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DEC-0029 §5 — the minimum execution path, pinned from the live turns that were too long (EV-0901):
 * "Hi Sarah." 17.5s through the full pipeline; "List the pages on Fable QA Cafe Two." 23-32s through a
 * classifier, a 20k-token model call, the tool and a follow-up call; every turn carrying a 28k tool block.
 */
class MinimumPathTest extends TestCase
{
    private function runtimeConfigured(): void
    {
        foreach (['RUNTIME_URL' => 'https://runtime.test', 'RUNTIME_SECRET' => 'test-secret'] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
    }

    /*──────────────────────────── DEC-0030: truthful work state for the ack of a complex turn */

    public function test_simple_turns_get_no_work_state(): void
    {
        // The ack wiring gates on isSimpleTurn: simple => work_state is null (no progress theatre).
        foreach (['Hi Sarah.', 'Thanks!', 'How many articles do I have?', 'List the pages on the bakery site', 'What is my business name?'] as $msg) {
            $this->assertTrue(MinimumPath::isSimpleTurn($msg), "simple: {$msg}");
            $ackWorkState = MinimumPath::isSimpleTurn($msg) ? null : MinimumPath::workState($msg, MinimumPath::keywordDomains($msg));
            $this->assertNull($ackWorkState, "no work state on a simple turn: {$msg}");
        }
    }

    public function test_complex_turns_get_a_truthful_present_continuous_label(): void
    {
        $seo = MinimumPath::workState('Why are my rankings dropping and what should I fix?', MinimumPath::keywordDomains('Why are my rankings dropping and what should I fix?'));
        $this->assertNotNull($seo);
        $this->assertStringContainsString('search visibility', $seo);
        $this->assertMatchesRegularExpression('/^(Looking at|Working)/', $seo, 'present continuous, not a completed claim');
        $this->assertStringNotContainsString('I checked', $seo);
        $this->assertStringNotContainsString('I reviewed', $seo);

        $plan = MinimumPath::workState('Create a growth strategy for the next 30 days', ['content', 'crm']);
        $this->assertSame('Working through your growth plan', $plan);
    }

    public function test_work_state_is_generic_when_no_domain_is_specific(): void
    {
        // nothing matched → keywordDomains returns the full set → do not claim to be looking at any one thing
        $this->assertSame('Working on that', MinimumPath::workState('Give me your honest read on where things stand', RouterIntent::DOMAINS));
    }

    public function test_is_simple_turn_treats_ambiguous_as_complex(): void
    {
        $this->assertFalse(MinimumPath::isSimpleTurn('Which of my websites needs SEO work most, and why?'));
        $this->assertFalse(MinimumPath::isSimpleTurn('What should I focus on this week?'));
    }

    /*──────────────────────────── A1: one classifier call, two answers */

    public function test_classify_returns_mode_and_domains_from_a_single_call(): void
    {
        $this->runtimeConfigured();
        Http::fake(['*/ai/run' => Http::response(['success' => true, 'output' => '{}', 'duration_ms' => 3,
            'parsed' => ['mode' => 'analysis', 'why' => 'needs judgement', 'domains' => ['SEO', 'content', 'unicorns']]], 200)]);

        $msg = 'Which of my websites needs SEO work most, and why? ' . uniqid();
        $ri = app(RouterIntent::class);
        $verdict = $ri->classify($msg, 999993);
        $domains = $ri->domains($msg, 999993);

        $this->assertSame(RouterIntent::ANALYSIS, $verdict['mode']);
        $this->assertSame(['seo', 'content'], $domains, 'unknown domains are dropped, known ones lower-cased');
        Http::assertSentCount(1);
    }

    public function test_a_greeting_needs_no_domains_and_no_call(): void
    {
        Http::fake();
        $ri = app(RouterIntent::class);
        $ri->classify('Hi Sarah.', 999993);
        $this->assertSame([], $ri->domains('Hi Sarah.', 999993));
        Http::assertNothingSent();
    }

    /** A8: shapes that cannot be read two ways skip the classifier call; ambiguous ones still go to the model. */
    public function test_deterministic_mode_for_unambiguous_shapes(): void
    {
        Http::fake();
        $ri = app(RouterIntent::class);
        $this->assertSame(RouterIntent::STATUS, $ri->classify('What is pending my approval?', 1)['mode']);
        $this->assertSame('deterministic', $ri->classify('How many articles do we have?', 1)['source']);
        $this->assertSame(RouterIntent::ANALYSIS, $ri->classify('Why did the audit fail?', 1)['mode']);
        $this->assertSame(RouterIntent::ANALYSIS, $ri->classify('What should I focus on this week?', 1)['mode']);
        $this->assertContains('tasks', $ri->domains('What is pending my approval?', 1));
        Http::assertNothingSent();
        $this->assertNull(RouterIntent::deterministicMode('Which website has the most articles?'), 'a comparison can be a lookup or a judgement — the model decides');
        $this->assertNull(RouterIntent::deterministicMode('What is the best keyword to target?'), 'a lookup opener with a judgement word is not a lookup');
    }

    /*──────────────────────────── A2: the light lane */

    public function test_light_turn_detection(): void
    {
        $this->assertTrue(MinimumPath::isLightTurn('Hi Sarah.'));
        $this->assertTrue(MinimumPath::isLightTurn('Thanks!'));
        $this->assertFalse(MinimumPath::isLightTurn('Hi Sarah.', true), 'an attachment is never a light turn');
        $this->assertFalse(MinimumPath::isLightTurn('Hi Sarah.', false, 'my_tasks'), 'a quick action is never a light turn');
        $this->assertFalse(MinimumPath::isLightTurn('Hi Sarah, why did the audit fail?'));
    }

    public function test_light_prompt_is_compact_and_forbids_work_offers(): void
    {
        $identity = str_repeat('You are Sarah, the Digital Marketing Manager. ', 40);
        $brand = "AUTHORITATIVE WORKSPACE FACTS\n- Business name: Fable QA Bakery\n";
        $history = "User: hello\nSarah: hi\nUser: publish the drafts\nSarah: done\nUser: thanks\nSarah: welcome";
        $p = MinimumPath::lightSystemPrompt($identity, $brand, 'Talk like a person.', $history);

        $this->assertLessThan(4000, mb_strlen($p), 'about 1k tokens, not 80k characters');
        $this->assertStringContainsString('Fable QA Bakery', $p);
        $this->assertStringContainsString('Do not offer to queue, publish or start anything', $p);
        $this->assertStringContainsString("Sarah: welcome", $p, 'the last exchange travels');
        $this->assertStringNotContainsString('User: hello', $p, 'only the last four lines travel');
    }

    /*──────────────────────────── A3: the read lane */

    public function test_read_intent_detects_list_requests_and_nothing_else(): void
    {
        $this->assertSame('platform.list_pages', MinimumPath::readIntent('List the pages on Fable QA Cafe Two.')['tool']);
        $this->assertSame('platform.list_pages', MinimumPath::readIntent('Show me the pages on the bakery site')['tool']);
        $this->assertSame('platform.list_articles', MinimumPath::readIntent('list my articles')['tool']);
        $this->assertSame('draft', MinimumPath::readIntent('Show me the drafts')['params']['status'] ?? null);
        $this->assertSame('crm.list_leads', MinimumPath::readIntent('What are my leads?')['tool']);
        $this->assertSame('seo.list_keywords', MinimumPath::readIntent('Show me the keywords we track')['tool']);

        $this->assertNull(MinimumPath::readIntent('Which of my websites needs SEO work most, and why?'), 'judgement is not a read');
        $this->assertNull(MinimumPath::readIntent('Create a page about catering'), 'work is not a read');
        $this->assertNull(MinimumPath::readIntent('Show me the pages and then fix the broken ones'), 'a read joined to work is not a read');
        $this->assertNull(MinimumPath::readIntent('How many articles do we have, and which website has the most?'));
        $this->assertNull(MinimumPath::readIntent('Hi Sarah.'));
    }

    /*──────────────────────────── A4: task-specific tool schema */

    /** 2026-09-02 05:08: a work turn paid a separate 7s domains() call; keyword domains seed the memo instead. */
    public function test_keyword_domains_for_work_turns(): void
    {
        $this->assertSame(['content'], MinimumPath::keywordDomains('Write a short article about our weekend sourdough special for the Fable QA Bakery site.'));
        $this->assertSame(['seo', 'content'], MinimumPath::keywordDomains('Fix the orphan pages'));
        $this->assertContains('crm', MinimumPath::keywordDomains('Follow up with the new leads'));
        $this->assertSame(RouterIntent::DOMAINS, MinimumPath::keywordDomains('Go ahead'), 'nothing matched → every domain (over-inclusion is the safe failure)');
        $this->assertNotContains('commercial', MinimumPath::keywordDomains('Publish the planning article'), 'word boundaries: "planning" is not "plan"');
    }

    public function test_tool_engines_follow_domains_and_keywords(): void
    {
        $this->assertSame(['seo'], MinimumPath::toolEngines(['seo'], 'why are rankings down?'));
        $this->assertContains('builder', MinimumPath::toolEngines([], 'change the homepage headline'));
        $this->assertContains('social', MinimumPath::toolEngines([], 'post it on instagram'));
        $this->assertSame([], MinimumPath::toolEngines(['tasks'], 'what is pending?'));
        $this->assertContains('crm', MinimumPath::toolEngines(['crm'], 'who should I chase?'));
    }

    public function test_tool_schema_shrinks_to_the_turn_and_keeps_platform_reads(): void
    {
        $svc = app(ToolSchemaService::class);
        $full = $svc->getToolSchemaPrompt('sarah');
        $seo  = $svc->getToolSchemaPrompt('sarah', ['seo'], 'why are rankings down?');
        $none = $svc->getToolSchemaPrompt('sarah', ['tasks'], 'what is pending?');

        $this->assertLessThan(mb_strlen($full) * 0.85, mb_strlen($seo), 'measured 2026-09-02: seo-scoped 21,836 vs catalog 28,276 chars — the platform reads and their fixed guidance are most of what remains (RFC-0007 §4 trims those next)');
        $this->assertStringContainsString('seo.serp_analysis', $seo);
        $this->assertStringNotContainsString('crm.create_lead', $seo);
        $this->assertStringContainsString('platform.list_pages', $none, 'platform reads always travel');
        $this->assertStringNotContainsString('write.write_article', $none);
        $this->assertStringContainsString('seo.serp_analysis', $full, 'no domains = the full catalog, unchanged');
    }
}
