<?php

namespace Tests\Feature\Chat\Charges;

use App\Core\Chat\ChatIdempotencyService;
use App\Core\Chat\ChatRequestFingerprint;
use App\Core\Chat\MessageChargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * P2-B — charge lifecycle. Proves INV-06, INV-07, INV-08, INV-09, INV-13,
 * and that the existing credit ledger remains authoritative.
 */
class MessageChargeLifecycleTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private MessageChargeService $charges;
    private ChatIdempotencyService $idem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        $this->charges = app(MessageChargeService::class);
        $this->idem    = app(ChatIdempotencyService::class);
    }

    private function openCharge(string $surface = 's8_public_chatbot', int $cost = 1, string $key = 'k1'): array
    {
        $ctx = [
            'workspace_id' => $this->testWorkspace->id, 'surface' => $surface,
            'idempotency_key' => $key, 'content' => 'hello',
        ];
        $ctx['request_fingerprint'] = ChatRequestFingerprint::compute($ctx);
        $acq = $this->idem->acquire($ctx);

        $chargeId = $this->charges->open([
            'workspace_id' => $this->testWorkspace->id, 'surface' => $surface,
            'idempotency_record_id' => (int) $acq['record']->id,
            'idempotency_key' => $key,
            'correlation_id' => $acq['correlation_id'],
            'estimated_credits' => $cost,
        ]);

        return [$chargeId, (int) $acq['record']->id];
    }

    private function balance(): float
    {
        return (float) DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->value('balance');
    }

    /** @test INV-06 — one charge row per logical request, enforced by the database. */
    public function only_one_charge_can_exist_per_idempotency_record(): void
    {
        [$chargeId, $recordId] = $this->openCharge();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('message_charges')->insert([
            'workspace_id' => $this->testWorkspace->id, 'surface' => 's8_public_chatbot',
            'idempotency_record_id' => $recordId, 'correlation_id' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @test A normal reserve → commit debits exactly once. */
    public function reserve_then_commit_charges_once(): void
    {
        $before = $this->balance();
        [$chargeId] = $this->openCharge(cost: 5);

        $ref = $this->charges->reserve($chargeId, $this->testWorkspace->id, 5);
        $this->assertNotNull($ref);
        $this->charges->markExecuting($chargeId, 'deepseek', 'deepseek-v4-flash');
        $this->assertTrue($this->charges->commit($chargeId));

        $this->assertSame(MessageChargeService::COMMITTED, $this->charges->find($chargeId)->status);
        $this->assertSame($before - 5, $this->balance());
        $this->assertSame(1, DB::table('credit_transactions')
            ->where('reservation_reference', $ref)->where('type', 'commit')->count());
    }

    /** @test INV-06 — a second commit must not reach the ledger. */
    public function a_second_commit_is_refused_and_does_not_double_charge(): void
    {
        $before = $this->balance();
        [$chargeId] = $this->openCharge(cost: 5);
        $ref = $this->charges->reserve($chargeId, $this->testWorkspace->id, 5);
        $this->charges->commit($chargeId);

        $second = $this->charges->commit($chargeId);

        $this->assertFalse($second, 'the second commit must be refused');
        $this->assertSame($before - 5, $this->balance(), 'the customer must be charged once, not twice');
        $this->assertSame(1, DB::table('credit_transactions')
            ->where('reservation_reference', $ref)->where('type', 'commit')->count());
    }

    /** @test INV-07 — a failed provider execution never commits. */
    public function a_provider_failure_releases_and_never_commits(): void
    {
        $before = $this->balance();
        [$chargeId] = $this->openCharge(cost: 5);
        $ref = $this->charges->reserve($chargeId, $this->testWorkspace->id, 5);
        $this->charges->markExecuting($chargeId);

        $this->assertTrue($this->charges->release($chargeId, 'CHAT_PROVIDER_UNAVAILABLE'));

        $charge = $this->charges->find($chargeId);
        $this->assertSame(MessageChargeService::RELEASED, $charge->status);
        $this->assertSame(0, (int) $charge->charged_credits);
        $this->assertSame($before, $this->balance(), 'a failed generation must cost the customer nothing');
        $this->assertSame(0, DB::table('credit_transactions')
            ->where('reservation_reference', $ref)->where('type', 'commit')->count());
    }

    /** @test INV-08 — a double release must not create credits from nothing. */
    public function a_double_release_releases_exactly_once(): void
    {
        $before = $this->balance();
        [$chargeId] = $this->openCharge(cost: 5);
        $ref = $this->charges->reserve($chargeId, $this->testWorkspace->id, 5);

        $this->assertTrue($this->charges->release($chargeId, 'CHAT_PROVIDER_TIMEOUT'));
        $this->assertFalse($this->charges->release($chargeId, 'CHAT_PROVIDER_TIMEOUT'),
            'a second release must be refused — otherwise the balance inflates');

        $this->assertSame($before, $this->balance());
        $this->assertSame(1, DB::table('credit_transactions')
            ->where('reservation_reference', $ref)->where('type', 'release')->count());
    }

    /** @test Commit after release is impossible. */
    public function a_released_charge_cannot_later_commit(): void
    {
        $before = $this->balance();
        [$chargeId] = $this->openCharge(cost: 5);
        $this->charges->reserve($chargeId, $this->testWorkspace->id, 5);
        $this->charges->release($chargeId, 'CHAT_PROVIDER_UNAVAILABLE');

        $this->assertFalse($this->charges->commit($chargeId));
        $this->assertSame($before, $this->balance());
    }

    /** @test INV-09 — eligibility is decided before any provider call. */
    public function an_ineligible_workspace_is_detected_before_execution(): void
    {
        DB::table('credits')->where('workspace_id', $this->testWorkspace->id)->update(['balance' => 0]);

        $this->assertFalse($this->charges->isEligible($this->testWorkspace->id, 5));
        $this->assertTrue($this->charges->isEligible($this->testWorkspace->id, 0),
            'a zero-cost request is always eligible');
    }

    /** @test INV-13 — Studio is classified not_chargeable, explicitly. */
    public function studio_surfaces_are_classified_not_chargeable(): void
    {
        foreach (['s5_studio_chat', 's6_studio_ai', 's7_builder_arthur'] as $surface) {
            $c = $this->charges->classify($surface);
            $this->assertFalse($c['chargeable'], "{$surface} must not be chargeable (INV-13)");
            $this->assertSame('studio_unmetered_cr23', $c['reason']);
        }
        $this->assertTrue($this->charges->classify('s8_public_chatbot')['chargeable']);
    }

    /** @test INV-13 — a Studio request writes no ledger row at all. */
    public function a_studio_request_never_touches_the_credit_ledger(): void
    {
        $before  = $this->balance();
        $ledgerBefore = DB::table('credit_transactions')->count();

        [$chargeId] = $this->openCharge('s5_studio_chat', 5, 'studio_key');
        $ref = $this->charges->reserve($chargeId, $this->testWorkspace->id, 5);
        $this->charges->commit($chargeId);

        $this->assertNull($ref, 'a not_chargeable surface must not reserve');
        $this->assertSame(MessageChargeService::NOT_CHARGEABLE, $this->charges->find($chargeId)->status);
        $this->assertSame($before, $this->balance());
        $this->assertSame($ledgerBefore, DB::table('credit_transactions')->count(),
            'Studio must write nothing to the authoritative ledger (CR-23 is a pricing decision)');
    }

    /** @test K-07 — a charge is joinable to the message that caused it. */
    public function a_charge_links_to_its_message_and_to_the_ledger(): void
    {
        [$chargeId] = $this->openCharge(cost: 1);
        $ref = $this->charges->reserve($chargeId, $this->testWorkspace->id, 1);
        $this->charges->attachMessage($chargeId, 'chatbot_messages', 4242, [
            'tokens' => 813, 'provider' => 'deepseek', 'model' => 'deepseek-v4-flash',
        ]);
        $this->charges->commit($chargeId);

        $c = $this->charges->find($chargeId);
        $this->assertSame(4242, (int) $c->message_id);
        $this->assertSame('chatbot_messages', $c->message_store);
        $this->assertSame(813, (int) $c->usage_units);
        $this->assertSame('deepseek-v4-flash', $c->model);
        $this->assertSame($ref, $c->existing_credit_transaction_reference);

        // The join that clause K-07 says does not exist today.
        $row = DB::table('message_charges as mc')
            ->join('credit_transactions as ct', 'ct.reservation_reference', '=', 'mc.existing_credit_transaction_reference')
            ->where('mc.id', $chargeId)->where('ct.type', 'commit')
            ->select('mc.message_id', 'ct.amount')->first();
        $this->assertNotNull($row, 'a billing dispute about one message must be answerable');
        $this->assertSame(4242, (int) $row->message_id);
    }

    /** @test An undeclared transition is refused rather than silently applied. */
    public function an_invalid_transition_is_refused(): void
    {
        [$chargeId] = $this->openCharge(cost: 1);
        // pending → committed is not a declared transition (must reserve first).
        $this->assertFalse($this->charges->commit($chargeId));
        $this->assertSame(MessageChargeService::PENDING, $this->charges->find($chargeId)->status);
    }

    /** @test Zero-cost requests are explicitly classified, not silently charged. */
    public function a_zero_cost_request_is_marked_not_chargeable(): void
    {
        [$chargeId] = $this->openCharge(cost: 0);
        $ref = $this->charges->reserve($chargeId, $this->testWorkspace->id, 0);

        $this->assertNull($ref);
        $this->assertSame(MessageChargeService::NOT_CHARGEABLE, $this->charges->find($chargeId)->status);
    }
}
