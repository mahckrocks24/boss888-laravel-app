<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Core\Billing\CreditService;
use App\Models\CreditTransaction;

class CreditIntegrityTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function reserve_then_commit_on_success()
    {
        $cs = app(CreditService::class);

        $reservation = $cs->reserveCredits($this->testWorkspace->id, 20, 'Task', 1, 'rsv_commit');
        $this->assertEquals('pending', $reservation->reservation_status);
        $this->assertReservedBalance(20);

        $cs->commitReservedCredits('rsv_commit');

        $this->assertCreditBalance(5000 - 20);
        $this->assertReservedBalance(0);
    }

    /** @test */
    public function reserve_then_release_on_failure()
    {
        $cs = app(CreditService::class);

        $cs->reserveCredits($this->testWorkspace->id, 15, 'Task', 2, 'rsv_release');
        $this->assertReservedBalance(15);

        $cs->releaseReservedCredits('rsv_release');

        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);
    }

    /** @test */
    public function retry_does_not_double_charge()
    {
        $cs = app(CreditService::class);

        // First attempt: reserve → release (failure)
        $cs->reserveCredits($this->testWorkspace->id, 10, 'Task', 3, 'rsv_retry_1');
        $cs->releaseReservedCredits('rsv_retry_1');
        $this->assertCreditBalance(5000);

        // Retry: reserve → commit (success)
        $cs->reserveCredits($this->testWorkspace->id, 10, 'Task', 3, 'rsv_retry_2');
        $cs->commitReservedCredits('rsv_retry_2');
        $this->assertCreditBalance(5000 - 10);

        // Only one commit
        $commits = CreditTransaction::where('workspace_id', $this->testWorkspace->id)
            ->where('type', 'commit')->count();
        $this->assertEquals(1, $commits);
    }

    /** @test */
    public function duplicate_commit_is_safe()
    {
        $cs = app(CreditService::class);

        $cs->reserveCredits($this->testWorkspace->id, 10, 'Task', 4, 'rsv_dup');
        $cs->commitReservedCredits('rsv_dup');

        // Second commit should return null (already committed)
        $result = $cs->commitReservedCredits('rsv_dup');
        $this->assertNull($result);

        // Balance only deducted once
        $this->assertCreditBalance(5000 - 10);
    }

    /** @test */
    public function stale_recovery_releases_orphan_reservations()
    {
        // Create old pending reservation manually
        CreditTransaction::create([
            'workspace_id' => $this->testWorkspace->id,
            'type' => 'reserve',
            'amount' => 50,
            'reservation_status' => 'pending',
            'reservation_reference' => 'rsv_orphan',
            'created_at' => now()->subHour(),
        ]);

        // Manually increment reserved_balance to simulate
        $credit = $this->getCredit();
        $credit->increment('reserved_balance', 50);

        $cs = app(CreditService::class);
        $orphans = $cs->findOrphanedReservations(30);
        $this->assertEquals(1, $orphans->count());

        foreach ($orphans as $o) {
            $cs->releaseReservedCredits($o->reservation_reference);
        }

        $this->assertReservedBalance(0);
        $this->assertCreditBalance(5000);
    }

    /** @test */
    public function zero_cost_actions_skip_reservation()
    {
        $cs = app(CreditService::class);

        $reservation = $cs->reserveCredits($this->testWorkspace->id, 0, 'Task', 5);
        $this->assertEquals('committed', $reservation->reservation_status);
        $this->assertEquals('zero_cost', $reservation->reservation_reference);

        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);
    }

    /** @test */
    public function insufficient_credits_blocks_reservation()
    {
        $cs = app(CreditService::class);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $cs->reserveCredits($this->testWorkspace->id, 99999, 'Task', 6);
    }
}
