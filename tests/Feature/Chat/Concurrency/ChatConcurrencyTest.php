<?php

namespace Tests\Feature\Chat\Concurrency;

use App\Core\Chat\ChatExecutionCoordinator;
use App\Core\Chat\ChatRequestFingerprint;
use App\Core\Chat\MessageChargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * P2-B — the whole guarantee, end to end, under contention.
 *
 *   ONE LOGICAL REQUEST → ONE PROVIDER EXECUTION → ONE USER MESSAGE
 *   → ONE FINAL ASSISTANT MESSAGE → AT MOST ONE CHARGE → ONE TRACEABLE OUTCOME
 *
 * Concurrency is exercised against REAL database constraints. PHPUnit is
 * single-threaded, so genuinely simultaneous OS processes are not available
 * here; instead every test interleaves the steps in the order a race would
 * produce them, which is what the unique index and the conditional updates
 * actually have to survive. Where the interleaving is the point, it is stated.
 */
class ChatConcurrencyTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private ChatExecutionCoordinator $coord;
    private int $providerCalls = 0;
    private int $userPersists = 0;
    private int $finalPersists = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        $this->coord = app(ChatExecutionCoordinator::class);
        $this->providerCalls = $this->userPersists = $this->finalPersists = 0;
    }

    private function ctx(array $over = []): array
    {
        return array_merge([
            'workspace_id'      => $this->testWorkspace->id,
            'user_id'           => $this->testUser->id,
            'surface'           => 's8_public_chatbot',
            'conversation_id'   => 'sess_1',
            'idempotency_key'   => 'race_key',
            'content'           => 'Do you deliver on Sundays?',
            'estimated_credits' => 1,
            'message_store'     => 'agent_messages',
        ], $over);
    }

    /**
     * Hooks that count every side effect, so duplicates are provable.
     *
     * The store used here is incidental: persistence is supplied BY the surface,
     * so what is under test is the coordinator's ordering and the database
     * constraints, not any one table. agent_messages is used simply because it
     * carries no foreign keys, keeping the fixture from testing the fixture.
     */
    private function hooks(bool $providerFails = false): array
    {
        return [
            'persistUserMessage' => function () {
                $this->userPersists++;

                return DB::table('agent_messages')->insertGetId([
                    'workspace_id' => $this->testWorkspace->id, 'agent_slug' => 'sarah',
                    'sender' => 'user', 'role' => 'user', 'content' => 'Do you deliver on Sundays?',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            },
            'execute' => function () use ($providerFails) {
                $this->providerCalls++;
                if ($providerFails) {
                    return ['ok' => false, 'error_code' => 'CHAT_PROVIDER_UNAVAILABLE', 'retryable' => true, 'http_status' => 503];
                }

                return ['ok' => true, 'body' => ['reply' => 'Yes, we do.'],
                        'provider' => 'deepseek', 'model' => 'deepseek-v4-flash',
                        'usage' => ['tokens' => 120]];
            },
            'persistFinalMessage' => function ($exec) {
                $this->finalPersists++;

                return DB::table('agent_messages')->insertGetId([
                    'workspace_id' => $this->testWorkspace->id, 'agent_slug' => 'sarah',
                    'sender' => 'agent', 'role' => 'agent', 'content' => $exec['body']['reply'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            },
        ];
    }

    private function balance(): float
    {
        return (float) DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->value('balance');
    }

    /** @test Two identical requests: one of everything. */
    public function two_identical_requests_produce_one_of_everything(): void
    {
        $before = $this->balance();

        $a = $this->coord->run($this->ctx(), $this->hooks());
        $b = $this->coord->run($this->ctx(), $this->hooks());

        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertFalse($a['replay']);
        $this->assertTrue($b['replay'], 'the second identical request must be a replay');

        $this->assertSame(1, $this->providerCalls, 'ONE provider execution');
        $this->assertSame(1, $this->userPersists, 'ONE user message');
        $this->assertSame(1, $this->finalPersists, 'ONE final assistant message');
        $this->assertSame(1, DB::table('message_charges')->where('status', 'committed')->count(), 'AT MOST ONE charge');
        $this->assertSame($before - 1, $this->balance());
        $this->assertSame($a['correlation_id'], $b['correlation_id'], 'ONE traceable outcome');
    }

    /** @test Five identical requests: still one of everything. */
    public function five_identical_requests_produce_one_of_everything(): void
    {
        $before = $this->balance();
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->coord->run($this->ctx(), $this->hooks());
        }

        $this->assertSame(1, $this->providerCalls);
        $this->assertSame(1, $this->userPersists);
        $this->assertSame(1, $this->finalPersists);
        $this->assertSame(1, DB::table('message_charges')->where('status', 'committed')->count());
        $this->assertSame($before - 1, $this->balance(), 'five submissions must cost exactly one credit');

        $replays = count(array_filter($results, fn ($r) => $r['replay']));
        $this->assertSame(4, $replays);
        $ids = array_unique(array_map(fn ($r) => $r['correlation_id'], $results));
        $this->assertCount(1, $ids, 'all five must share one correlation id');
    }

    /** @test Same key, different payload — conflict, and nothing is executed. */
    public function the_same_key_with_a_different_payload_conflicts(): void
    {
        $this->coord->run($this->ctx(), $this->hooks());
        $callsAfterFirst = $this->providerCalls;

        $b = $this->coord->run($this->ctx(['content' => 'Totally different question']), $this->hooks());

        $this->assertFalse($b['ok']);
        $this->assertSame('CHAT_CONVERSATION_CONFLICT', $b['error_code']);
        $this->assertSame(409, $b['http_status']);
        $this->assertSame($callsAfterFirst, $this->providerCalls, 'a conflict must not execute the provider');
    }

    /** @test The same key in two workspaces are two independent requests. */
    public function the_same_key_in_two_workspaces_both_execute(): void
    {
        $this->coord->run($this->ctx(), $this->hooks());
        $this->coord->run($this->ctx(['workspace_id' => 999123, 'estimated_credits' => 0]), $this->hooks());

        $this->assertSame(2, $this->providerCalls, 'workspaces must not share an idempotency namespace');
        $this->assertSame(2, DB::table('chat_idempotency_records')->count());
    }

    /** @test INV-07/INV-08 — provider failure after reservation releases, never commits. */
    public function a_provider_failure_after_reservation_releases_exactly_once(): void
    {
        $before = $this->balance();

        $r = $this->coord->run($this->ctx(), $this->hooks(providerFails: true));

        $this->assertFalse($r['ok']);
        $this->assertSame('CHAT_PROVIDER_UNAVAILABLE', $r['error_code']);
        $this->assertSame(0, $this->finalPersists, 'no assistant message may be persisted for a failed generation');
        $this->assertSame(0, DB::table('message_charges')->where('status', 'committed')->count());
        $this->assertSame(1, DB::table('message_charges')->where('status', 'released')->count());
        $this->assertSame($before, $this->balance(), 'a failed generation must cost nothing');
    }

    /** @test A retry after a retryable failure executes once more — and only once. */
    public function a_retry_after_a_retryable_failure_executes_once_more(): void
    {
        $this->coord->run($this->ctx(), $this->hooks(providerFails: true));
        $this->assertSame(1, $this->providerCalls);

        $r = $this->coord->run($this->ctx(), $this->hooks());

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $this->providerCalls, 'a retryable failure may be retried exactly once more');
        $this->assertSame(1, $this->finalPersists);
        $this->assertSame(1, DB::table('chat_idempotency_records')->count(), 'the retry reuses the record');
    }

    /** @test INV-09 — insufficient credits stops before the provider. */
    public function insufficient_credits_prevents_any_provider_call(): void
    {
        DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->update(['balance' => 0]);

        $r = $this->coord->run($this->ctx(['estimated_credits' => 5]), $this->hooks());

        $this->assertFalse($r['ok']);
        $this->assertSame('CHAT_INSUFFICIENT_CREDITS', $r['error_code']);
        $this->assertSame(402, $r['http_status']);
        $this->assertSame(0, $this->providerCalls, 'no provider call may occur after an insufficient-credit decision');
        $this->assertSame(0, $this->userPersists);
    }

    /** @test Provider completes after the client gave up: the answer is recoverable, not regenerated. */
    public function a_retry_after_client_timeout_returns_the_stored_answer(): void
    {
        $first = $this->coord->run($this->ctx(), $this->hooks());   // server finished
        $before = $this->balance();

        $retry = $this->coord->run($this->ctx(), $this->hooks());   // client had timed out and retried

        $this->assertTrue($retry['replay']);
        $this->assertSame($first['result']['final_message_id'], $retry['result']['final_message_id']);
        $this->assertSame(1, $this->providerCalls);
        $this->assertSame($before, $this->balance(), 'a retry after completion must not charge again');
    }

    /** @test A process that dies after persisting the user message does not lose or duplicate it. */
    public function a_crash_after_user_persistence_is_recoverable_without_duplication(): void
    {
        // Simulate: the record was acquired and the user message stored, then the
        // worker vanished before the provider returned.
        $ctx = $this->ctx();
        $ctx['request_fingerprint'] = ChatRequestFingerprint::compute($ctx);
        $idem = app(\App\Core\Chat\ChatIdempotencyService::class);
        $acq  = $idem->acquire($ctx);
        $idem->markProcessing((int) $acq['record']->id);
        DB::table('agent_messages')->insert([
            'workspace_id' => $this->testWorkspace->id, 'agent_slug' => 'sarah',
            'sender' => 'user', 'role' => 'user', 'content' => 'Do you deliver on Sundays?',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chat_idempotency_records')->where('id', $acq['record']->id)->update([
            'updated_at' => now()->subSeconds(\App\Core\Chat\ChatIdempotencyService::LEASE_SECONDS + 60),
        ]);

        $r = $this->coord->run($this->ctx(), $this->hooks());

        $this->assertTrue($r['ok'], 'a lapsed lease must be recoverable');
        $this->assertSame(1, DB::table('chat_idempotency_records')->count(), 'recovery must reuse the record');
        $this->assertSame(1, DB::table('message_charges')->where('status', 'committed')->count());
    }

    /** @test Studio runs through the coordinator and is never charged. */
    public function studio_executes_but_is_never_charged(): void
    {
        $before = $this->balance();
        $ledger = DB::table('credit_transactions')->count();

        $r = $this->coord->run($this->ctx([
            'surface' => 's5_studio_chat', 'idempotency_key' => 'studio_1', 'estimated_credits' => 1,
        ]), $this->hooks());

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $this->providerCalls);
        $this->assertSame(MessageChargeService::NOT_CHARGEABLE,
            DB::table('message_charges')->latest('id')->value('status'));
        $this->assertSame($before, $this->balance(), 'Studio must remain free (INV-13)');
        $this->assertSame($ledger, DB::table('credit_transactions')->count());
    }

    /** @test INV-11 — one correlation id links record, charge and messages. */
    public function one_correlation_id_links_the_whole_exchange(): void
    {
        $r = $this->coord->run($this->ctx(), $this->hooks());
        $cid = $r['correlation_id'];

        $this->assertSame(1, DB::table('chat_idempotency_records')->where('correlation_id', $cid)->count());
        $this->assertSame(1, DB::table('message_charges')->where('correlation_id', $cid)->count());

        $charge = DB::table('message_charges')->where('correlation_id', $cid)->first();
        $this->assertSame($r['result']['final_message_id'], (int) $charge->message_id,
            'the charge must point at the message it paid for');
    }
}
