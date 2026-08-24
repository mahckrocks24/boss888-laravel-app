<?php

namespace Tests\Feature\Sarah;

use App\Core\Strategy\ProactiveRuleSet;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SARAH888 · SBS-001 · Task T0.1 — Fault 1 regression guard.
 *
 * WHAT BROKE
 * WorkspaceStateGatherer::readSeoState() builds `stale_articles` with
 * Collection::toArray() on a query-builder result, which yields an array of
 * stdClass rows — not an array of arrays. ProactiveRuleSet RULE 2 read those
 * rows with array syntax, so every workspace holding at least one published
 * article older than 60 days raised
 *
 *     Error: Cannot use object of type stdClass as array
 *
 * inside emit(), which is called unguarded by SarahDailyOrchestrator::runDaily().
 * The whole daily cycle aborted. ws1 produced no morning brief from 2026-07-15,
 * ws2 from 2026-07-22 — silently, because SarahMorningBriefCommand reports the
 * per-workspace exception through $this->error() only and the scheduler entry
 * runs with runInBackground(), which discards stdout.
 *
 * WHAT THIS TEST FIXES IN PLACE
 * It pins the consumption boundary: RULE 2 must accept BOTH shapes, must emit
 * the intended candidate, must not emit one when there is nothing stale, and
 * must not disturb RULE 3 / RULE 4, whose inputs are genuine arrays.
 *
 * NO DATABASE. emit() touches the database only through RULE 15, which is
 * entered solely when $state['goals'] is non-empty. Every case here passes an
 * empty goal set, and testEmitPerformsNoDatabaseQueries proves the claim rather
 * than asserting it. That keeps this suite safe to run beside other engineers'
 * suites (SBS-Q-041) — no migration, no RefreshDatabase, no shared-database
 * mutation of any kind.
 *
 * Traces: MP Law 10 (Silence Is a Defect) · SBS-Q-021(d) · SBS-M-003.
 */
class ProactiveRuleSetStaleArticleTest extends TestCase
{
    private ProactiveRuleSet $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new ProactiveRuleSet();
    }

    /**
     * A state in which no rule should fire. Every test starts here and adds
     * only the signal it is exercising, so an assertion can never be satisfied
     * by an unrelated rule.
     */
    private function quietState(): array
    {
        return [
            'seo' => [
                'orphan_pages'             => 0,
                'stale_articles'           => [],
                'opportunity_keywords'     => [],
                'rank_deltas_7d'           => [],
                'missing_meta'             => 0,
                'thin_pages'               => 0,
                // RULE 7 fires on a null or >7d audit date; keep it fresh so the
                // baseline is genuinely silent.
                'last_audit_at'            => now()->toDateTimeString(),
                'pending_link_suggestions' => 0,
            ],
            'chatbot'    => ['unanswered_questions' => []],
            'pipeline'   => ['stuck_tasks' => 0],
            'tier_state' => ['burn_rate_status' => 'on_track'],
            'goals'      => [],
        ];
    }

    /** The exact row shape WorkspaceStateGatherer produces: stdClass, not array. */
    private function gathererStaleRow(int $id, string $title, string $updatedAt): \stdClass
    {
        $row = new \stdClass();
        $row->id           = $id;
        $row->title        = $title;
        $row->updated_at   = $updatedAt;
        $row->published_at = $updatedAt;

        return $row;
    }

    private function candidatesForRule(array $candidates, string $rule): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (array $c): bool => ($c['rule'] ?? null) === $rule
        ));
    }

    /**
     * THE REGRESSION. Before the T0.1 fix this raised
     * "Cannot use object of type stdClass as array" at ProactiveRuleSet.php:63
     * and no candidates were returned at all.
     */
    public function testStdClassStaleArticleRowsDoNotFatal(): void
    {
        $state = $this->quietState();
        $state['seo']['stale_articles'] = [
            $this->gathererStaleRow(53, 'How Much Does a Personal Chef Cost in New Jersey?', '2026-05-22 14:53:59'),
        ];

        $candidates = $this->rules->emit(2, $state);

        $this->assertIsArray($candidates);
        $this->assertNotEmpty(
            $this->candidatesForRule($candidates, 'stale_article'),
            'RULE 2 must survive the stdClass row shape the gatherer actually emits.'
        );
    }

    /** Acceptance 3 — a stale published article yields the intended candidate. */
    public function testStaleArticleProducesIntendedRuleTwoCandidate(): void
    {
        $state = $this->quietState();
        $state['seo']['stale_articles'] = [
            $this->gathererStaleRow(62, 'The Benefits of an In-Home Chef', '2026-05-23 01:14:19'),
        ];

        $matched = $this->candidatesForRule($this->rules->emit(2, $state), 'stale_article');

        $this->assertCount(1, $matched);
        $this->assertSame('refresh_article', $matched[0]['action']);
        $this->assertSame('priya', $matched[0]['agent']);
        $this->assertSame('medium', $matched[0]['priority_hint']);
        $this->assertSame(3, $matched[0]['estimated_credits']);
        $this->assertSame(62, $matched[0]['params']['article_id']);
        $this->assertStringContainsString('The Benefits of an In-Home Chef', $matched[0]['evidence']);
        $this->assertStringContainsString('2026-05-23 01:14:19', $matched[0]['evidence']);
        $this->assertStringContainsString('The Benefits of an In-Home Chef', $matched[0]['title_hint']);
    }

    /** RULE 2 is capped at three candidates however many rows arrive. */
    public function testStaleArticleCandidatesAreCappedAtThree(): void
    {
        $state = $this->quietState();
        $state['seo']['stale_articles'] = [
            $this->gathererStaleRow(1, 'One',   '2026-05-01 00:00:00'),
            $this->gathererStaleRow(2, 'Two',   '2026-05-02 00:00:00'),
            $this->gathererStaleRow(3, 'Three', '2026-05-03 00:00:00'),
            $this->gathererStaleRow(4, 'Four',  '2026-05-04 00:00:00'),
            $this->gathererStaleRow(5, 'Five',  '2026-05-05 00:00:00'),
        ];

        $this->assertCount(3, $this->candidatesForRule($this->rules->emit(2, $state), 'stale_article'));
    }

    /**
     * Back-compatibility: an array-shaped row must keep working. Any caller or
     * future gatherer change that hands over arrays must not regress.
     */
    public function testArrayShapedStaleRowsRemainSupported(): void
    {
        $state = $this->quietState();
        $state['seo']['stale_articles'] = [
            ['id' => 44, 'title' => 'The Mediterranean Way of Eating', 'updated_at' => '2026-05-23 19:28:47'],
        ];

        $matched = $this->candidatesForRule($this->rules->emit(2, $state), 'stale_article');

        $this->assertCount(1, $matched);
        $this->assertSame(44, $matched[0]['params']['article_id']);
    }

    /** Acceptance 4 — no stale articles must not manufacture a RULE 2 candidate. */
    public function testWorkspaceWithoutStaleArticlesProducesNoRuleTwoCandidate(): void
    {
        $candidates = $this->rules->emit(2, $this->quietState());

        $this->assertSame([], $this->candidatesForRule($candidates, 'stale_article'));
        $this->assertSame([], $candidates, 'The quiet baseline must fire no rule at all.');
    }

    /** Acceptance 5a — RULE 3 (opportunity zone) behaviour is untouched. */
    public function testRuleThreeOpportunityKeywordsUnchanged(): void
    {
        $state = $this->quietState();
        $state['seo']['opportunity_keywords'] = [
            ['keyword' => 'private chef nj', 'rank' => 14, 'volume' => 320],
            ['keyword' => 'chef catering nj', 'rank' => 27, 'volume' => 110],
        ];

        $matched = $this->candidatesForRule($this->rules->emit(2, $state), 'opportunity_zone');

        $this->assertCount(2, $matched);
        $this->assertSame('target_keyword_article', $matched[0]['action']);
        $this->assertSame('high', $matched[0]['priority_hint'], 'rank <= 20 stays high priority');
        $this->assertSame('medium', $matched[1]['priority_hint'], 'rank > 20 stays medium priority');
        $this->assertSame('private chef nj', $matched[0]['params']['target_keyword']);
    }

    /** Acceptance 5b — RULE 4 (rank deltas) behaviour is untouched, both polarities. */
    public function testRuleFourRankDeltasUnchanged(): void
    {
        $state = $this->quietState();
        $state['seo']['rank_deltas_7d'] = [
            ['keyword' => 'private chef cost', 'from_rank' => 82, 'to_rank' => 66, 'delta' => 16],
            ['keyword' => 'hire chef nj',      'from_rank' => 12, 'to_rank' => 30, 'delta' => -18],
            ['keyword' => 'ignored small',     'from_rank' => 10, 'to_rank' => 12, 'delta' => -2],
        ];

        $candidates = $this->rules->emit(2, $state);

        $wins   = $this->candidatesForRule($candidates, 'rank_delta_win');
        $losses = $this->candidatesForRule($candidates, 'rank_delta_loss');

        $this->assertCount(1, $wins);
        $this->assertSame('amplify_winner', $wins[0]['action']);
        $this->assertSame('priya', $wins[0]['agent']);

        $this->assertCount(1, $losses);
        $this->assertSame('investigate_drop', $losses[0]['action']);
        $this->assertSame('james', $losses[0]['agent']);

        $this->assertStringNotContainsString(
            'ignored small',
            json_encode($candidates),
            'A delta under 5 positions must still be ignored.'
        );
    }

    /**
     * Acceptance 7 (unit half) — emit() issues no database query for a state
     * with no goals, so this suite cannot mutate any database. Proven by
     * counting queries rather than by assertion in prose.
     */
    public function testEmitPerformsNoDatabaseQueries(): void
    {
        $state = $this->quietState();
        $state['seo']['stale_articles'] = [
            $this->gathererStaleRow(53, 'Stale piece', '2026-05-22 14:53:59'),
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->rules->emit(2, $state);

        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            [],
            $queries,
            'emit() must not query the database when the goal set is empty.'
        );
    }
}
