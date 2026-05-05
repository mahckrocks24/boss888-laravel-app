<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Task;
use App\Models\CreditTransaction;

class CliValidationTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function recover_stale_command_resets_stuck_tasks()
    {
        $timeout = config('queue_control.stale_task_timeout', 600);

        $staleTask = $this->createTask([
            'status' => 'running',
            'started_at' => now()->subSeconds($timeout + 200),
            'idempotency_key' => 'stale_test_key',
        ]);

        $this->artisan('boss888:recover-stale', ['--timeout' => $timeout])
            ->assertExitCode(0);

        $staleTask->refresh();
        $this->assertContains($staleTask->status, ['queued', 'failed']);
    }

    /** @test */
    public function recover_stale_releases_orphan_credits()
    {
        CreditTransaction::create([
            'workspace_id' => $this->testWorkspace->id,
            'type' => 'reserve',
            'amount' => 30,
            'reservation_status' => 'pending',
            'reservation_reference' => 'rsv_cli_orphan',
            'created_at' => now()->subHour(),
        ]);

        $credit = $this->getCredit();
        $credit->increment('reserved_balance', 30);

        $this->artisan('boss888:recover-stale')
            ->assertExitCode(0);

        $this->assertReservedBalance(0);
    }

    /** @test */
    public function recover_stale_dry_run_makes_no_changes()
    {
        $timeout = config('queue_control.stale_task_timeout', 600);

        $staleTask = $this->createTask([
            'status' => 'running',
            'started_at' => now()->subSeconds($timeout + 200),
        ]);

        $this->artisan('boss888:recover-stale', ['--dry-run' => true])
            ->assertExitCode(0);

        $staleTask->refresh();
        $this->assertEquals('running', $staleTask->status); // Unchanged
    }

    /** @test */
    public function queue_health_command_outputs_metrics()
    {
        // Create some tasks for metrics
        $this->createTask(['status' => 'queued']);
        $this->createTask(['status' => 'running', 'started_at' => now()]);
        $this->createTask(['status' => 'completed', 'completed_at' => now()]);

        $this->artisan('boss888:queue-health')
            ->assertExitCode(0)
            ->expectsOutputToContain('Queue Pressure')
            ->expectsOutputToContain('Circuit Breakers')
            ->expectsOutputToContain('Report complete');
    }
}
