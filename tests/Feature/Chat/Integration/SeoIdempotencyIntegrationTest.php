<?php

namespace Tests\Feature\Chat\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * P2-B — production integration proof for the SEO assistant (deployment step 3).
 *
 * The gate sits in front of the surface's own meterChat. These tests prove that
 * position does the work: a duplicate submission returns the first answer
 * without ticking the meter, and without running the pipeline a second time.
 */
class SeoIdempotencyIntegrationTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private const ROUTE = '/api/connector/assistant/message';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        $this->enableGate(true);
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
        $repo->set('CHAT_IDEMPOTENCY_SURFACES', 's4_seo_assistant');
    }

    private function meter(): int
    {
        return (int) DB::table('workspaces')->where('id', $this->testWorkspace->id)->value('chat_meter');
    }

    private function send(string $key, string $message = 'How is my SEO doing?')
    {
        return $this->withHeaders($this->authHeaders())
            ->postJson(self::ROUTE, ['message' => $message, 'idempotency_key' => $key]);
    }

    /** @test Without a key, behaviour is exactly as before — the compatibility guarantee. */
    public function a_request_without_an_idempotency_key_uses_the_legacy_path(): void
    {
        $before = $this->meter();

        $r = $this->withHeaders($this->authHeaders())
            ->postJson(self::ROUTE, ['message' => 'no key supplied']);

        $this->assertContains($r->getStatusCode(), [200, 402]);
        $this->assertSame(0, DB::table('chat_idempotency_records')->count(),
            'no key means no idempotency record — existing clients must be untouched');
        $this->assertNotSame($before, $this->meter(), 'the legacy path still meters');
    }

    /** @test INV-01/INV-04 — a first keyed request creates exactly one record. */
    public function a_keyed_request_creates_one_idempotency_record(): void
    {
        $this->send('seo_key_1');

        $this->assertSame(1, DB::table('chat_idempotency_records')->count());
        $rec = DB::table('chat_idempotency_records')->first();
        $this->assertSame('s4_seo_assistant', $rec->surface);
        $this->assertSame($this->testWorkspace->id, (int) $rec->workspace_id);
        $this->assertNotEmpty($rec->correlation_id);
    }

    /** @test INV-06/INV-10 — a duplicate does NOT tick the meter again. */
    public function a_duplicate_submission_does_not_meter_twice(): void
    {
        $this->send('seo_key_2');
        $afterFirst = $this->meter();

        $second = $this->send('seo_key_2');

        $this->assertSame($afterFirst, $this->meter(),
            'a duplicate submission ticked the credit meter a second time — this is the double-charge K-06 describes');
        $this->assertTrue((bool) ($second->json('idempotent_replay') ?? false),
            'the duplicate should be reported as a replay');
        $this->assertSame(1, DB::table('chat_idempotency_records')->count());
    }

    /** @test INV-02 — the pipeline is not run twice, so no second message is written. */
    public function a_duplicate_submission_does_not_persist_a_second_message(): void
    {
        // Force the refusal path so persistence is deterministic without a provider.
        DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->update(['balance' => 0]);
        DB::table('workspaces')->where('id', $this->testWorkspace->id)->update(['chat_meter' => 9]);

        $first = $this->send('seo_key_3', 'refused once');
        $this->assertSame(402, $first->getStatusCode());
        $countAfterFirst = DB::table('seo_assistant_messages')->count();
        $this->assertSame(1, $countAfterFirst, 'P2-A: a refused message is still persisted');

        $second = $this->send('seo_key_3', 'refused once');

        $this->assertSame($countAfterFirst, DB::table('seo_assistant_messages')->count(),
            'the refusal was replayed but the message was persisted a second time');
        $this->assertSame(402, $second->getStatusCode(), 'a settled refusal must replay as a refusal');
    }

    /** @test INV-03 — the same key with a different question is a conflict. */
    public function the_same_key_with_a_different_message_conflicts(): void
    {
        $this->send('seo_key_4', 'first question');

        $second = $this->send('seo_key_4', 'a completely different question');

        $this->assertSame(409, $second->getStatusCode());
        $this->assertSame('CHAT_CONVERSATION_CONFLICT', $second->json('chat_error.code'));
        $this->assertFalse($second->json('chat_error.retryable'));
    }

    /** @test INV-12 — two workspaces may use the same key independently. */
    public function the_same_key_in_another_workspace_is_independent(): void
    {
        $this->send('shared_key');

        DB::table('chat_idempotency_records')->insert([
            'workspace_id' => 999321, 'surface' => 's4_seo_assistant',
            'idempotency_key' => 'shared_key', 'request_fingerprint' => 'other',
            'correlation_id' => 'cor_other', 'status' => 'completed',
            'response_reference' => json_encode(['body' => ['secret' => 'TENANT B DATA']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $again = $this->send('shared_key');

        $this->assertStringNotContainsString('TENANT B DATA', $again->getContent(),
            'a replay must never surface another workspace\'s stored outcome');
        $this->assertSame(2, DB::table('chat_idempotency_records')->count());
    }

    /** @test INV-11 — every gated response carries a correlation id. */
    public function every_gated_response_carries_a_correlation_id(): void
    {
        $r = $this->send('seo_key_5');

        $cid = $r->json('correlation_id');
        $this->assertNotEmpty($cid);
        $this->assertSame(1, DB::table('chat_idempotency_records')->where('correlation_id', $cid)->count());
        $this->assertSame(1, DB::table('message_charges')->where('correlation_id', $cid)->count());
    }

    /** @test The charge record records that the surface still owns its own metering. */
    public function the_charge_record_declares_delegated_metering(): void
    {
        $this->send('seo_key_6');

        $charge = DB::table('message_charges')->latest('id')->first();
        $this->assertSame('not_chargeable', $charge->status);
        $this->assertStringContainsString('delegated_to_surface_meter', (string) $charge->metadata,
            'the charge record must say the surface meter owns the money, not that the request was free');
        $this->assertSame(0, (int) $charge->charged_credits);
    }

    /** @test With the flag off, the key is ignored entirely. */
    public function the_flag_disables_the_gate_completely(): void
    {
        $this->enableGate(false);

        $this->send('seo_key_7');

        $this->assertSame(0, DB::table('chat_idempotency_records')->count(),
            'with the flag off nothing may be recorded — rollback must be a flag flip');
    }
}
