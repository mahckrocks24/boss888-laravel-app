<?php

namespace Tests\Feature\Chat\Chatbot;

use App\Engines\Chatbot\Services\ChatbotResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * P2-C — the P2-B primitive, adopted inside ChatbotResponseService.
 *
 * Proves the two targeted criticals (I-01, K-06) through the CHATBOT
 * integration, not merely through the shared service. Every invariant marked
 * "passed" in the revalidation table is evidenced here or in
 * ProcessParallelTest.
 */
class ChatbotIdempotencyTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        $this->enableGate(true);

        // phpunit.chat.xml blanks RUNTIME_URL so no test can reach a provider —
        // the real client therefore THROWS "RuntimeClient not configured", the
        // pipeline fails, and the coordinator correctly treats that as a
        // retryable failure (so a retry re-executes, by design). That is right
        // behaviour, but it means a FAILED exchange is what gets measured.
        // Stub the client so these tests exercise the SUCCESS path, which is
        // where duplicate-suppression is observable.
        $this->mock(\App\Connectors\RuntimeClient::class, function ($m) {
            $m->shouldIgnoreMissing();
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('chatJson')->andReturn([
                'success' => true,
                'parsed'  => ['answer' => 'We are open 9 to 5.', 'intent' => 'faq'],
            ]);
            $m->shouldReceive('classifyIntent')->andReturn(['intent' => 'faq', 'confidence' => 0.95]);
        });

        $tokenId = DB::table('chatbot_widget_tokens')->insertGetId([
            'workspace_id' => $this->testWorkspace->id,
            'token_hash'   => hash('sha256', 'p2c'),
            'token_prefix' => 'p2c_',
            'label'        => 'p2c',
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

    protected function tearDown(): void
    {
        $this->enableGate(false);
        parent::tearDown();
    }

    private function enableGate(bool $on): void
    {
        $repo = \Illuminate\Support\Env::getRepository();
        $repo->set('CHAT_IDEMPOTENCY_ENABLED', $on ? 'true' : 'false');
        $repo->set('CHAT_IDEMPOTENCY_SURFACES', 's8_public_chatbot,s4_seo_assistant');
    }

    private function svc(): ChatbotResponseService
    {
        return app(ChatbotResponseService::class);
    }

    private function balance(): float
    {
        return (float) DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->value('balance');
    }

    private function userMsgs(): int
    {
        return DB::table('chatbot_messages')->where('session_id', $this->sessionId)->where('role', 'user')->count();
    }

    /** @test INV-01 — a keyed chatbot request creates exactly one record on the chatbot surface. */
    public function a_chatbot_request_creates_one_idempotency_record(): void
    {
        $this->svc()->handleMessage($this->sessionId, 'What are your hours?', 'cb_key_1');

        $rec = DB::table('chat_idempotency_records')->first();
        $this->assertNotNull($rec, 'the chatbot must now create an idempotency record');
        $this->assertSame('s8_public_chatbot', $rec->surface);
        $this->assertSame($this->testWorkspace->id, (int) $rec->workspace_id);
        $this->assertSame((string) $this->sessionId, $rec->conversation_id);
        $this->assertNotEmpty($rec->correlation_id);
    }

    /** @test I-01 — a duplicate submission persists ONE user message. */
    public function a_duplicate_chatbot_request_persists_one_user_message(): void
    {
        $this->svc()->handleMessage($this->sessionId, 'Do you deliver?', 'cb_key_2');
        $after = $this->userMsgs();

        $this->svc()->handleMessage($this->sessionId, 'Do you deliver?', 'cb_key_2');

        $this->assertSame($after, $this->userMsgs(),
            'a duplicate chatbot submission persisted a second user message (clause I-01)');
        $this->assertSame(1, DB::table('chat_idempotency_records')->count());
    }

    /** @test K-06 — a duplicate submission charges at most once. */
    public function a_duplicate_chatbot_request_charges_once(): void
    {
        $before = $this->balance();

        $this->svc()->handleMessage($this->sessionId, 'How much is delivery?', 'cb_key_3');
        $afterFirst = $this->balance();

        $this->svc()->handleMessage($this->sessionId, 'How much is delivery?', 'cb_key_3');

        $this->assertSame($afterFirst, $this->balance(),
            'a retried chatbot message charged the workspace twice (clause K-06)');
        $this->assertLessThanOrEqual(1.0, $before - $this->balance(),
            'one logical request must cost at most one credit');
    }

    /** @test INV-10 — the replay returns the original payload, flagged. */
    public function a_duplicate_returns_the_original_payload(): void
    {
        $first = $this->svc()->handleMessage($this->sessionId, 'Where are you based?', 'cb_key_4');

        $second = $this->svc()->handleMessage($this->sessionId, 'Where are you based?', 'cb_key_4');

        $this->assertTrue($second['idempotent_replay'] ?? false, 'the duplicate must be flagged as a replay');
        $this->assertSame($first['success'] ?? null, $second['success'] ?? null);
        $this->assertNotEmpty($second['correlation_id'] ?? null);
    }

    /** @test INV-03 — the same key with a different message conflicts. */
    public function the_same_key_with_a_different_message_conflicts(): void
    {
        $this->svc()->handleMessage($this->sessionId, 'first question', 'cb_key_5');

        $second = $this->svc()->handleMessage($this->sessionId, 'a totally different question', 'cb_key_5');

        $this->assertFalse($second['success'] ?? true);
        $this->assertSame('CHAT_CONVERSATION_CONFLICT', $second['error'] ?? null);
    }

    /** @test E-5 — a widget that sends NO key is still protected by a canonical key. */
    public function a_request_without_a_key_is_still_deduplicated(): void
    {
        $this->svc()->handleMessage($this->sessionId, 'no key supplied here');
        $after = $this->userMsgs();

        $this->svc()->handleMessage($this->sessionId, 'no key supplied here');

        $this->assertSame($after, $this->userMsgs(),
            'the canonical server-side key must deduplicate a widget that sends none');
        $this->assertSame(1, DB::table('chat_idempotency_records')->count());
        $rec = DB::table('chat_idempotency_records')->first();
        $this->assertStringStartsWith('cb:', $rec->idempotency_key, 'a derived key must be identifiable as such');
    }

    /** @test Different messages in the same session are different requests. */
    public function different_messages_are_not_deduplicated(): void
    {
        $this->svc()->handleMessage($this->sessionId, 'first distinct message');
        $this->svc()->handleMessage($this->sessionId, 'second distinct message');

        $this->assertSame(2, DB::table('chat_idempotency_records')->count(),
            'distinct questions must never be collapsed into one request');
    }

    /** @test INV-12 — the chatbot record is workspace-scoped. */
    public function the_chatbot_record_is_workspace_scoped(): void
    {
        $this->svc()->handleMessage($this->sessionId, 'scoped question', 'cb_key_6');

        DB::table('chat_idempotency_records')->insert([
            'workspace_id' => 555111, 'surface' => 's8_public_chatbot',
            'idempotency_key' => 'cb_key_6', 'request_fingerprint' => 'other',
            'correlation_id' => 'cor_other', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('chat_idempotency_records')->where('idempotency_key', 'cb_key_6')->count(),
            'the same key must coexist across workspaces');
    }

    /** @test INV-09 — insufficient credits must not reach the provider. */
    public function insufficient_credits_prevents_execution(): void
    {
        DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->update(['balance' => 0]);

        $r = $this->svc()->handleMessage($this->sessionId, 'will be refused', 'cb_key_7');

        $this->assertFalse($r['success'] ?? true);
        $this->assertSame(0, DB::table('chatbot_messages')
            ->where('session_id', $this->sessionId)->where('role', 'assistant')->count(),
            'no assistant message may exist when the request was refused');
    }

    /** @test INV-06/INV-08 — no orphan reservation is left behind. */
    public function no_orphan_reservation_remains(): void
    {
        $this->svc()->handleMessage($this->sessionId, 'reservation check', 'cb_key_8');

        $orphans = DB::table('message_charges')
            ->where('workspace_id', $this->testWorkspace->id)
            ->whereIn('status', ['pending', 'reserved', 'executing'])->count();

        $this->assertSame(0, $orphans, 'every charge must reach a terminal state');
    }

    /** @test INV-11 — record, charge and correlation id are linked. */
    public function the_chatbot_exchange_shares_one_correlation_id(): void
    {
        $r = $this->svc()->handleMessage($this->sessionId, 'correlation check', 'cb_key_9');
        $cid = $r['correlation_id'] ?? null;

        $this->assertNotEmpty($cid);
        $this->assertSame(1, DB::table('chat_idempotency_records')->where('correlation_id', $cid)->count());
        $this->assertSame(1, DB::table('message_charges')->where('correlation_id', $cid)->count());
    }

    /** @test With the flag off, the chatbot behaves exactly as before. */
    public function the_flag_disables_chatbot_idempotency_entirely(): void
    {
        $this->enableGate(false);

        $this->svc()->handleMessage($this->sessionId, 'flag off', 'cb_key_10');

        $this->assertSame(0, DB::table('chat_idempotency_records')->count(),
            'with the flag off nothing may be recorded — rollback must be a flag flip');
    }

    /** @test The service creates no second idempotency or charge implementation. */
    public function the_chatbot_uses_the_shared_primitive_not_a_copy(): void
    {
        $src = (string) file_get_contents(base_path('app/Engines/Chatbot/Services/ChatbotResponseService.php'));

        $this->assertStringContainsString('App\Core\Chat\ChatExecutionCoordinator', $src,
            'the chatbot must use the shared coordinator');
        $this->assertDoesNotMatchRegularExpression('/class\s+\w*Idempotency\w*Service/', $src,
            'no second idempotency implementation may exist');
        $this->assertDoesNotMatchRegularExpression("/DB::table\(\s*'chat_idempotency_records'\s*\)/", $src,
            'the chatbot must go through the service, not write the table directly');
    }
}
