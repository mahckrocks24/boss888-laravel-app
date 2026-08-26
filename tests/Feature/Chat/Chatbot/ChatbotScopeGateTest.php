<?php

namespace Tests\Feature\Chat\Chatbot;

use App\Engines\Chatbot\Services\ChatbotResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * BUILDER888 B13 regression lock — the chatbot scope gate (EV-0778).
 *
 * The rule-first ChatbotIntentClassifier matches a commitment intent on wording
 * ("appointment") and returns before the LLM scope-guardrail runs, so a dental bot
 * once booked a dog's appointment (Boss "dog dentist"). The fix is a scope gate on
 * commitment intents that short-circuits an out-of-scope request to out_of_scope with
 * NO lead capture. These tests lock that behaviour deterministically (the runtime is
 * mocked) so the fix can never silently regress the way EV-0761's prompt-only guardrail
 * did.
 */
class ChatbotScopeGateTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();

        // A people-serving business — a pet request must be out of scope.
        DB::table('workspaces')->where('id', $this->testWorkspace->id)
            ->update(['industry' => 'dental clinic']);

        $tokenId = DB::table('chatbot_widget_tokens')->insertGetId([
            'workspace_id' => $this->testWorkspace->id,
            'token_hash'   => hash('sha256', 'scopegate'),
            'token_prefix' => 'sg_',
            'label'        => 'sg',
            'status'       => 'active',
            'created_at'   => now(), 'updated_at' => now(),
        ]);
        $this->sessionId = (int) DB::table('chatbot_sessions')->insertGetId([
            'workspace_id'    => $this->testWorkspace->id,
            'widget_token_id' => $tokenId,
            'message_count'   => 0,
            'created_at'      => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Mock the runtime so the scope gate returns $inScope, and any other chatJson
     * call (e.g. the answer path) returns a benign FAQ answer.
     */
    private function mockRuntimeScope(bool $inScope, string $redirect = ''): void
    {
        $this->mock(\App\Connectors\RuntimeClient::class, function ($m) use ($inScope, $redirect) {
            $m->shouldIgnoreMissing();
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('chatJson')->andReturnUsing(function ($system, $user, $meta = [], $tokens = null) use ($inScope, $redirect) {
                if (($meta['task'] ?? null) === 'chatbot_scope_gate') {
                    return ['success' => true, 'parsed' => ['in_scope' => $inScope, 'redirect' => $redirect]];
                }
                return ['success' => true, 'parsed' => ['answer' => 'We are open 9 to 5.', 'intent' => 'faq']];
            });
        });
    }

    private function svc(): ChatbotResponseService
    {
        return app(ChatbotResponseService::class);
    }

    private function lastAssistantIntent(): ?string
    {
        return DB::table('chatbot_messages')->where('session_id', $this->sessionId)
            ->where('role', 'assistant')->orderByDesc('id')->value('intent');
    }

    /** @test An out-of-scope commitment (a pet at a people-serving clinic) must NOT book or capture a lead. */
    public function out_of_scope_commitment_is_redirected_without_capturing_a_lead(): void
    {
        $this->mockRuntimeScope(false, "We provide dental care for people, not pets — please see a veterinary dentist.");

        $res  = $this->svc()->handleMessage($this->sessionId, "I'd like to book an appointment for my dog to see the dentist.");
        $data = $res['data'] ?? $res;

        $this->assertSame('out_of_scope', $data['intent'] ?? null, 'a pet request at a people-serving clinic must be out_of_scope, not a booking');
        $this->assertSame('out_of_scope', $this->lastAssistantIntent(), 'the persisted assistant intent must be out_of_scope');
        $this->assertFalse((bool) ($data['needs_contact'] ?? false), 'must NOT ask for contact details for an out-of-scope request');
        $this->assertEmpty($data['capture_fields'] ?? [], 'must NOT capture any fields for an out-of-scope request');
        $this->assertStringContainsStringIgnoringCase('pet', $data['message'] ?? '', 'the reply should redirect, referencing the out-of-scope subject');
        // No lead row should have been created.
        if (\Illuminate\Support\Facades\Schema::hasTable('chatbot_leads')) {
            $this->assertSame(0, (int) DB::table('chatbot_leads')->where('workspace_id', $this->testWorkspace->id)->count(),
                'an out-of-scope request must not create a lead');
        }
    }

    /** @test A genuine in-scope commitment must still proceed to the booking flow (no false-positive block). */
    public function in_scope_commitment_still_proceeds_to_booking(): void
    {
        $this->mockRuntimeScope(true);

        $res  = $this->svc()->handleMessage($this->sessionId, "I'd like to book a teeth cleaning for myself next Tuesday.");
        $data = $res['data'] ?? $res;

        $this->assertNotSame('out_of_scope', $data['intent'] ?? null, 'a real in-scope booking must NOT be blocked as out-of-scope');
        $this->assertSame('booking', $this->lastAssistantIntent(), 'a real dental booking must remain a booking');
    }
}
