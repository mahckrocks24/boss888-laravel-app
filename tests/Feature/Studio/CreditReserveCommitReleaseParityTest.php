<?php

namespace Tests\Feature\Studio;

use App\Core\Billing\CreditService;
use App\Models\CreditTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — CREDIT RESERVE/COMMIT/RELEASE PARITY (test-only).
 *
 * Characterizes both CreditService method families that the two execution
 * engines use, and proves they share one ledger with identical semantics:
 *   - Orchestrator family: reserveCredits() / commitReservedCredits() / releaseReservedCredits()
 *   - EES family (wrappers): reserve() / commit() / release()  → delegate to the above
 *
 * Ledger model (credit_transactions + credits.reserved_balance/balance):
 *   reserve  → +reserved_balance,  txn(type=reserve, status=pending)
 *   commit   → -reserved_balance, -balance, txn(type=commit,  status=committed)
 *   release  → -reserved_balance,           txn(type=release, status=released)
 *   amount<=0 → zero-cost dummy, no db write.
 */
class CreditReserveCommitReleaseParityTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    private function cs(): CreditService
    {
        return app(CreditService::class);
    }

    private function typeCount(string $type): int
    {
        return DB::table('credit_transactions')
            ->where('workspace_id', $this->testWorkspace->id)
            ->where('type', $type)->count();
    }

    // ── 1. One reservation per attempt (both families) ──────────────────

    /** @test */
    public function reserve_increments_reserved_only_and_records_one_pending_txn(): void
    {
        // Orchestrator family
        $txn = $this->cs()->reserveCredits($this->testWorkspace->id, 2, 'Task', 1, 'ref_orch');
        $this->assertInstanceOf(CreditTransaction::class, $txn);
        $this->assertCreditBalance(5000);   // balance untouched by reserve
        $this->assertReservedBalance(2);    // only reserved rises
        $this->assertSame(1, $this->typeCount('reserve'));

        // EES wrapper family
        $ref = $this->cs()->reserve($this->testWorkspace->id, 3, 'engine/x');
        $this->assertIsString($ref);
        $this->assertReservedBalance(5);
        $this->assertSame(2, $this->typeCount('reserve'));
    }

    // ── 2 & 3. Commit and release effects ───────────────────────────────

    /** @test */
    public function commit_moves_reserved_to_spent_release_returns_reserved(): void
    {
        $ref1 = $this->cs()->reserve($this->testWorkspace->id, 4, 'a');
        $ref2 = $this->cs()->reserve($this->testWorkspace->id, 6, 'b');
        $this->assertReservedBalance(10);

        // commit ref1 → -reserved -balance
        $this->cs()->commit($this->testWorkspace->id, $ref1, 4);
        $this->assertCreditBalance(5000 - 4);
        $this->assertReservedBalance(6);
        $this->assertSame(1, $this->typeCount('commit'));

        // release ref2 → -reserved only, balance unchanged
        $this->cs()->release($this->testWorkspace->id, $ref2);
        $this->assertCreditBalance(5000 - 4);
        $this->assertReservedBalance(0);
        $this->assertSame(1, $this->typeCount('release'));
    }

    // ── 4. No commit after release ──────────────────────────────────────

    /** @test */
    public function commit_after_release_is_a_noop_no_double_spend(): void
    {
        $ref = $this->cs()->reserve($this->testWorkspace->id, 5, 'x');
        $this->cs()->release($this->testWorkspace->id, $ref);
        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);

        // committing the already-released ref must not spend anything
        $result = $this->cs()->commitReservedCredits($ref);
        $this->assertNull($result);
        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);
        $this->assertSame(0, $this->typeCount('commit'));
    }

    // ── 5. No release after commit ──────────────────────────────────────

    /** @test */
    public function release_after_commit_is_a_noop_no_refund(): void
    {
        $ref = $this->cs()->reserve($this->testWorkspace->id, 5, 'x');
        $this->cs()->commit($this->testWorkspace->id, $ref, 5);
        $this->assertCreditBalance(5000 - 5);

        // releasing the already-committed ref must not refund
        $result = $this->cs()->releaseReservedCredits($ref);
        $this->assertNull($result);
        $this->assertCreditBalance(5000 - 5);
        $this->assertReservedBalance(0);
        $this->assertSame(0, $this->typeCount('release'));
    }

    // ── 7. Double commit on one ref → single charge ─────────────────────

    /** @test */
    public function double_commit_on_one_reference_charges_once(): void
    {
        $ref = $this->cs()->reserve($this->testWorkspace->id, 7, 'x');
        $this->cs()->commit($this->testWorkspace->id, $ref, 7);
        $this->cs()->commit($this->testWorkspace->id, $ref, 7); // second call no-ops

        $this->assertCreditBalance(5000 - 7);
        $this->assertReservedBalance(0);
        $this->assertSame(1, $this->typeCount('commit'));
    }

    // ── 6/8. Zero-cost path reserves and charges nothing ────────────────

    /** @test */
    public function zero_cost_reservation_writes_no_ledger_and_charges_nothing(): void
    {
        $txn = $this->cs()->reserveCredits($this->testWorkspace->id, 0, 'Task', 1, 'zero');
        $this->assertSame('zero_cost', $txn->reservation_reference);
        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);
        $this->assertSame(0, $this->typeCount('reserve'));

        // commit/release of a zero-cost ref are safe no-ops
        $this->assertNull($this->cs()->commitReservedCredits('zero'));
        $this->assertNull($this->cs()->releaseReservedCredits('zero'));
        $this->assertCreditBalance(5000);
    }

    // ── Parity: both families produce identical ledger effects ──────────

    /** @test */
    public function ees_and_orchestrator_families_are_ledger_equivalent(): void
    {
        // EES family: reserve → commit (charge 3)
        $ref = $this->cs()->reserve($this->testWorkspace->id, 3, 'ees');
        $this->cs()->commit($this->testWorkspace->id, $ref, 3);
        $afterEes = ['balance' => $this->getCredit()->fresh()->balance, 'reserved' => $this->getCredit()->fresh()->reserved_balance];

        // Orchestrator family: reserve → commit (charge 3), same net effect
        $txn = $this->cs()->reserveCredits($this->testWorkspace->id, 3, 'Task', 2, 'orch');
        $this->cs()->commitReservedCredits($txn->reservation_reference);
        $afterOrch = ['balance' => $this->getCredit()->fresh()->balance, 'reserved' => $this->getCredit()->fresh()->reserved_balance];

        // Each family charged exactly 3; reserved returns to 0 both times.
        $this->assertEquals((float) $afterEes['balance'] - 3, (float) $afterOrch['balance']);
        $this->assertEquals(0, (int) $afterOrch['reserved']);
        $this->assertSame(2, $this->typeCount('commit'));
        $this->assertSame(2, $this->typeCount('reserve'));
    }
}
