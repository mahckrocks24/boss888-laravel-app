<?php

namespace Tests\Feature\Chat\Idempotency;

use App\Core\Chat\ChatIdempotencyService;
use App\Core\Chat\ChatRequestFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * P2-B — idempotency primitive. Proves INV-01, INV-02, INV-03, INV-10, INV-12.
 */
class ChatIdempotencyTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private ChatIdempotencyService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        $this->svc = app(ChatIdempotencyService::class);
    }

    private function ctx(array $over = []): array
    {
        $base = [
            'workspace_id'    => $this->testWorkspace->id,
            'user_id'         => $this->testUser->id,
            'surface'         => 's8_public_chatbot',
            'conversation_id' => 'sess_1',
            'idempotency_key' => 'key_alpha',
            'content'         => 'What are your opening hours?',
        ];
        $c = array_merge($base, $over);
        $c['request_fingerprint'] = ChatRequestFingerprint::compute($c);

        return $c;
    }

    /** @test INV-01 */
    public function the_first_request_acquires_the_record(): void
    {
        $r = $this->svc->acquire($this->ctx());

        $this->assertSame('acquired', $r['state']);
        $this->assertNotEmpty($r['correlation_id']);
        $this->assertSame(1, DB::table('chat_idempotency_records')->count());
    }

    /** @test INV-01 — the DATABASE is the arbiter, not application logic. */
    public function the_unique_constraint_is_enforced_by_the_database(): void
    {
        $this->svc->acquire($this->ctx());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('chat_idempotency_records')->insert([
            'workspace_id' => $this->testWorkspace->id, 'surface' => 's8_public_chatbot',
            'idempotency_key' => 'key_alpha', 'request_fingerprint' => 'x',
            'correlation_id' => 'y', 'status' => 'acquired',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @test INV-02 — a duplicate while in flight must not start a second execution. */
    public function an_in_flight_duplicate_is_reported_in_progress(): void
    {
        $first = $this->svc->acquire($this->ctx());
        $this->svc->markProcessing((int) $first['record']->id);

        $second = $this->svc->acquire($this->ctx());

        $this->assertSame('in_progress', $second['state']);
        $this->assertSame('CHAT_CONVERSATION_CONFLICT', $second['error_code']);
        $this->assertSame(1, DB::table('chat_idempotency_records')->count());
    }

    /** @test INV-10 — a completed duplicate returns the stored outcome, unchanged. */
    public function a_completed_duplicate_replays_the_stored_outcome(): void
    {
        $first = $this->svc->acquire($this->ctx());
        $id = (int) $first['record']->id;
        $this->svc->markProcessing($id);
        $this->svc->markCompleted($id, ['final_message_id' => 42, 'body' => ['reply' => 'We open at 9.']]);

        $completedAt = DB::table('chat_idempotency_records')->where('id', $id)->value('completed_at');

        $second = $this->svc->acquire($this->ctx());

        $this->assertSame('replay', $second['state']);
        $this->assertSame(42, $second['response']['final_message_id']);
        $this->assertSame('We open at 9.', $second['response']['body']['reply']);
        $this->assertSame(
            $completedAt,
            DB::table('chat_idempotency_records')->where('id', $id)->value('completed_at'),
            'a replay must not move completed_at (INV-10)'
        );
    }

    /** @test INV-03 — same key, different question is a conflict, never a wrong answer. */
    public function a_conflicting_fingerprint_returns_a_structured_conflict(): void
    {
        $this->svc->acquire($this->ctx());

        $second = $this->svc->acquire($this->ctx(['content' => 'Something completely different']));

        $this->assertSame('conflict', $second['state']);
        $this->assertSame('CHAT_CONVERSATION_CONFLICT', $second['error_code']);
    }

    /** @test INV-12 — the same key in two workspaces are independent requests. */
    public function the_same_key_in_two_workspaces_does_not_collide(): void
    {
        $a = $this->svc->acquire($this->ctx());
        $b = $this->svc->acquire($this->ctx(['workspace_id' => 999123]));

        $this->assertSame('acquired', $a['state']);
        $this->assertSame('acquired', $b['state'], 'a second workspace must not be blocked by the first workspace\'s key');
        $this->assertSame(2, DB::table('chat_idempotency_records')->count());
        $this->assertNotSame($a['correlation_id'], $b['correlation_id']);
    }

    /** @test INV-12 — and one workspace can never read the other's outcome. */
    public function a_workspace_cannot_replay_another_workspaces_outcome(): void
    {
        $a = $this->svc->acquire($this->ctx());
        $this->svc->markProcessing((int) $a['record']->id);
        $this->svc->markCompleted((int) $a['record']->id, ['body' => ['reply' => 'TENANT A SECRET']]);

        $b = $this->svc->acquire($this->ctx(['workspace_id' => 999123]));

        $this->assertSame('acquired', $b['state']);
        $this->assertNull($b['response'], 'workspace B must never receive workspace A\'s stored outcome');
    }

    /** @test A settled final failure replays rather than re-executing. */
    public function a_final_failure_replays_its_error(): void
    {
        $first = $this->svc->acquire($this->ctx());
        $this->svc->markFailed((int) $first['record']->id, 'CHAT_INSUFFICIENT_CREDITS', false);

        $second = $this->svc->acquire($this->ctx());

        $this->assertSame('replay', $second['state']);
        $this->assertSame('CHAT_INSUFFICIENT_CREDITS', $second['error_code']);
    }

    /** @test A retryable failure may be attempted again. */
    public function a_retryable_failure_can_be_reclaimed(): void
    {
        $first = $this->svc->acquire($this->ctx());
        $this->svc->markFailed((int) $first['record']->id, 'CHAT_PROVIDER_TIMEOUT', true);

        $second = $this->svc->acquire($this->ctx());

        $this->assertSame('acquired', $second['state']);
        $this->assertSame(1, DB::table('chat_idempotency_records')->count(), 'reclaim must reuse the record, not create a second');
    }

    /** @test A worker that died mid-flight must not block the key forever. */
    public function a_stale_processing_record_is_reclaimable(): void
    {
        $first = $this->svc->acquire($this->ctx());
        $id = (int) $first['record']->id;
        $this->svc->markProcessing($id);

        // Simulate the worker dying: age the lease past its limit.
        DB::table('chat_idempotency_records')->where('id', $id)->update([
            'updated_at' => now()->subSeconds(ChatIdempotencyService::LEASE_SECONDS + 60),
        ]);

        $second = $this->svc->acquire($this->ctx());

        $this->assertSame('acquired', $second['state'], 'a lapsed lease must be reclaimable or the conversation is stuck forever');
    }

    /** @test The stale sweep marks abandoned records retryable. */
    public function the_stale_sweep_recovers_abandoned_records(): void
    {
        $first = $this->svc->acquire($this->ctx());
        $id = (int) $first['record']->id;
        $this->svc->markProcessing($id);
        DB::table('chat_idempotency_records')->where('id', $id)->update([
            'updated_at' => now()->subSeconds(ChatIdempotencyService::LEASE_SECONDS + 60),
        ]);

        $n = $this->svc->recoverStale();

        $this->assertSame(1, $n);
        $this->assertSame('failed_retryable', DB::table('chat_idempotency_records')->where('id', $id)->value('status'));
    }

    /** @test Only one caller may transition acquired → processing. */
    public function only_one_caller_can_start_processing(): void
    {
        $first = $this->svc->acquire($this->ctx());
        $id = (int) $first['record']->id;

        $this->assertTrue($this->svc->markProcessing($id));
        $this->assertFalse($this->svc->markProcessing($id), 'a second worker must not be able to start the same work');
    }

    /** @test INV-05 — completion is conditional, so only one final can be written. */
    public function only_one_completion_can_win(): void
    {
        $first = $this->svc->acquire($this->ctx());
        $id = (int) $first['record']->id;
        $this->svc->markProcessing($id);

        $this->assertTrue($this->svc->markCompleted($id, ['final_message_id' => 1]));
        $this->assertFalse($this->svc->markCompleted($id, ['final_message_id' => 2]),
            'a second completion must not overwrite the authoritative outcome');
        $ref = json_decode(DB::table('chat_idempotency_records')->where('id', $id)->value('response_reference'), true);
        $this->assertSame(1, $ref['final_message_id']);
    }

    /** @test Whitespace differences are the same question; different words are not. */
    public function the_fingerprint_normalises_noise_but_not_meaning(): void
    {
        $a = ChatRequestFingerprint::compute(['workspace_id' => 1, 'surface' => 's', 'content' => "hello   world\n"]);
        $b = ChatRequestFingerprint::compute(['workspace_id' => 1, 'surface' => 's', 'content' => 'hello world']);
        $c = ChatRequestFingerprint::compute(['workspace_id' => 1, 'surface' => 's', 'content' => 'HELLO WORLD']);

        $this->assertSame($a, $b, 'trailing whitespace must not make an identical submission look different');
        $this->assertNotSame($a, $c, 'case may carry intent and must not be collapsed');
    }

    /** @test Attachment order must not change request identity. */
    public function attachment_order_does_not_change_the_fingerprint(): void
    {
        $a = ChatRequestFingerprint::compute(['workspace_id' => 1, 'surface' => 's', 'content' => 'x', 'attachment_ids' => ['b', 'a']]);
        $b = ChatRequestFingerprint::compute(['workspace_id' => 1, 'surface' => 's', 'content' => 'x', 'attachment_ids' => ['a', 'b']]);

        $this->assertSame($a, $b);
    }
}
