<?php

namespace Tests\Feature\Chat\Regression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * CR-03 — Studio chat fabricated replies (P2-A).
 *
 * WHAT IT USED TO DO
 * When the runtime was unreachable, POST /api/studio/chat invented an answer:
 *   "luxury" → a hard-coded gold palette, "Applied a luxury gold-on-black palette."
 *   "brand"  → hard-coded brand colours
 *   "color"  → a palette chosen with array_rand(), reported as a design decision
 * …all returned with success:true. A customer could not distinguish a canned
 * guess from the AI, and the design was silently recoloured.
 *
 * CHAT-CONTRACT-v1 clause F-06 prohibits this outright. The surface must say it
 * cannot reach the model.
 *
 * Also covered here (same sprint): SEO now persists the user's message when it
 * refuses on credits (clause P-02), and the Studio validation/refusal paths
 * carry structured errors from the closed taxonomy (clause X-01).
 */
class Cr03FabricatedReplyTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    private function src(): string
    {
        return \Tests\Support\RouteSource::all();
    }

    /** @test */
    public function studio_chat_cannot_fabricate_a_palette_reply(): void
    {
        $src = $this->src();

        foreach ([
            'Applied a luxury gold-on-black palette.' => 'the "luxury" keyword branch',
            'Applied your default brand colors.'      => 'the "brand" keyword branch',
            'Swapped to a fresh palette'              => 'the "color" keyword branch',
        ] as $needle => $what) {
            $this->assertStringNotContainsString(
                $needle,
                $src,
                "{$what} still fabricates an assistant reply with no model call (clause F-06)"
            );
        }
    }

    /** @test */
    public function no_design_action_is_chosen_at_random(): void
    {
        // The worst of the three: array_rand() picked a palette and the reply
        // presented it as a considered decision.
        $this->assertStringNotContainsString(
            'palettes[array_rand($palettes)]',
            $this->src(),
            'a design action is still being chosen at random and reported as an AI decision (clause F-06)'
        );
    }

    /** @test */
    public function studio_chat_returns_an_honest_provider_unavailable_error(): void
    {
        $src = $this->src();
        $this->assertStringContainsString(
            "'code'            => 'CHAT_PROVIDER_UNAVAILABLE'",
            $src,
            'Studio chat does not classify a runtime failure with the closed taxonomy (clause X-01/F-06)'
        );
    }

    /** @test */
    public function studio_validation_failure_carries_a_structured_error(): void
    {
        $this->assertStringContainsString(
            "'code'            => 'CHAT_VALIDATION_FAILED'",
            $this->src(),
            'the Studio validation failure carries no structured error code (clause X-01)'
        );
    }

    /** @test */
    public function studio_invalid_input_is_rejected_with_a_structured_body(): void
    {
        $r = $this->withHeaders($this->authHeaders())
            ->postJson('/api/studio/chat', ['message' => '', 'design_id' => 0]);

        $r->assertStatus(422);
        $body = $r->json();
        $this->assertFalse($body['success'] ?? true);
        $this->assertSame('CHAT_VALIDATION_FAILED', $body['chat_error']['code'] ?? null);
        $this->assertArrayHasKey('retryable', $body['chat_error']);
        $this->assertArrayHasKey('provider_called', $body['chat_error']);
    }

    /** @test */
    public function the_studio_credit_failure_path_is_no_longer_silent(): void
    {
        $this->assertStringContainsString(
            'studio.chat credit deduct failed',
            $this->src(),
            'the Studio credit deduction still swallows its exception (clause O-04)'
        );
    }

    /** @test */
    public function seo_persists_the_user_message_when_it_refuses_on_credits(): void
    {
        // meterChat() batches — it charges on every tenth chat and returns
        // sufficient=true for the nine between. A zero balance alone proves
        // nothing; the counter must also be about to roll over.
        DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->update(['balance' => 0]);
        DB::table('workspaces')->where('id', $this->testWorkspace->id)->update(['chat_meter' => 9]);

        $before = DB::table('seo_assistant_messages')
            ->where('workspace_id', $this->testWorkspace->id)->count();

        $marker = 'cr03-seo-refusal-' . uniqid();
        // ApiKeyAuth falls through to JwtAuthMiddleware when no X-API-KEY is
        // present but a Bearer token is — so the SPA's own JWT reaches this
        // connector route. (P1 assumed it did not; that was wrong.)
        $r = $this->withHeaders($this->authHeaders())
            ->postJson('/api/connector/assistant/message', ['message' => $marker]);

        $this->assertSame(402, $r->getStatusCode(), 'expected a credit refusal');
        $this->assertTrue($r->json('chat_error.persistence.user_message_saved'),
            'the refusal does not report the message as saved (clause X-05)');

        $after = DB::table('seo_assistant_messages')
            ->where('workspace_id', $this->testWorkspace->id)->count();
        $this->assertSame($before + 1, $after,
            "the user's message was discarded when SEO refused on credits (clause P-02)");
    }

    /** @test */
    public function the_seo_refusal_persistence_failure_is_logged_not_swallowed(): void
    {
        $this->assertMatchesRegularExpression(
            "/Log::error\('chat\.persist_failed',\s*\[\s*'stage'\s*=>\s*'seo_assistant_refusal'/s",
            $this->src(),
            'the SEO refusal persistence failure is swallowed silently (clause O-04)'
        );
    }

    /** @test */
    public function aria_states_honestly_that_nothing_was_persisted(): void
    {
        // Aria has no store at all — its only client holds history in
        // bld_aiHistory. Reporting user_message_saved:false is ACCURATE, and
        // the code must say why so it is not "fixed" by mistake later.
        $src = $this->src();
        $this->assertStringContainsString(
            'CR-04 (Aria half) and deferred to P2-B',
            $src,
            'the Aria refusal does not record why it cannot persist (clause C-19/P-02 documentation)'
        );
    }
}
