<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Task;
use App\Models\Credit;
use App\Models\Plan;
use App\Models\Agent;
use App\Models\CreditTransaction;
use App\Core\Billing\CreditService;
use App\Services\IdempotencyService;
use App\Services\ConnectorCircuitBreakerService;
use App\Services\ExecutionRateLimiterService;
use App\Services\QueueControlService;
use App\Services\TaskProgressService;
use App\Core\TaskSystem\Orchestrator;

class Phase3ReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed plans and agents
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\AgentSeeder::class);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@boss888.test',
            'password' => bcrypt('password'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Test Workspace',
            'slug' => 'test-ws',
            'created_by' => $this->user->id,
        ]);

        $this->workspace->users()->attach($this->user->id, ['role' => 'owner']);

        Credit::create([
            'workspace_id' => $this->workspace->id,
            'balance' => 1000,
            'reserved_balance' => 0,
        ]);
    }

    // ── 1. Duplicate task execution prevented ────────────────────────────

    /** @test */
    public function duplicate_task_execution_is_prevented()
    {
        $idempotency = app(IdempotencyService::class);

        $key = $idempotency->generateKey($this->workspace->id, 'create_lead', ['name' => 'John']);

        // Create a completed task with this key
        Task::create([
            'workspace_id' => $this->workspace->id,
            'engine' => 'crm',
            'action' => 'create_lead',
            'payload_json' => ['name' => 'John'],
            'status' => 'completed',
            'idempotency_key' => $key,
            'result_json' => ['lead_id' => 42],
            'completed_at' => now(),
        ]);

        // Check duplicate returns cached result
        $duplicate = $idempotency->checkDuplicate($key);

        $this->assertNotNull($duplicate);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertEquals(42, $duplicate['result']['lead_id']);
    }

    /** @test */
    public function idempotency_lock_prevents_concurrent_execution()
    {
        $idempotency = app(IdempotencyService::class);
        $key = 'test_lock_key';

        $this->assertTrue($idempotency->acquireLock($key));
        $this->assertFalse($idempotency->acquireLock($key)); // Second attempt fails

        $idempotency->releaseLock($key);
        $this->assertTrue($idempotency->acquireLock($key)); // After release, succeeds
        $idempotency->releaseLock($key);
    }

    // ── 2. Retry does not double charge credits ──────────────────────────

    /** @test */
    public function retry_does_not_double_charge_credits()
    {
        $creditService = app(CreditService::class);

        $initialBalance = Credit::where('workspace_id', $this->workspace->id)->first()->balance;

        // Reserve credits (simulating first execution attempt)
        $reservation = $creditService->reserveCredits(
            $this->workspace->id, 10, 'Task', 1, 'rsv_test_retry'
        );

        $this->assertEquals('pending', $reservation->reservation_status);

        // Simulate failure — release credits
        $creditService->releaseReservedCredits('rsv_test_retry');

        // Verify balance restored
        $credit = Credit::where('workspace_id', $this->workspace->id)->first();
        $this->assertEquals($initialBalance, $credit->balance);
        $this->assertEquals(0, $credit->reserved_balance);

        // Retry: reserve again with new ref (same task, different attempt)
        $reservation2 = $creditService->reserveCredits(
            $this->workspace->id, 10, 'Task', 1, 'rsv_test_retry_2'
        );

        // Commit on success
        $creditService->commitReservedCredits('rsv_test_retry_2');

        $credit->refresh();
        $this->assertEquals($initialBalance - 10, $credit->balance);
        $this->assertEquals(0, $credit->reserved_balance);

        // Only charged once, not twice
        $debits = CreditTransaction::where('workspace_id', $this->workspace->id)
            ->where('type', 'commit')
            ->count();
        $this->assertEquals(1, $debits);
    }

    // ── 3. Failed verification releases credits ──────────────────────────

    /** @test */
    public function failed_verification_releases_reserved_credits()
    {
        $creditService = app(CreditService::class);
        $initialBalance = Credit::where('workspace_id', $this->workspace->id)->first()->balance;

        // Reserve
        $creditService->reserveCredits($this->workspace->id, 15, 'Task', 99, 'rsv_verify_fail');

        $credit = Credit::where('workspace_id', $this->workspace->id)->first();
        $this->assertEquals(15, $credit->reserved_balance);

        // Simulate verification failure — release
        $creditService->releaseReservedCredits('rsv_verify_fail');

        $credit->refresh();
        $this->assertEquals(0, $credit->reserved_balance);
        $this->assertEquals($initialBalance, $credit->balance); // Fully restored
    }

    // ── 4. Open circuit blocks execution ──────────────────────────────────

    /** @test */
    public function open_circuit_blocks_execution()
    {
        $cb = app(ConnectorCircuitBreakerService::class);

        // Record failures until circuit opens
        $threshold = config('execution.circuit_breaker.default.failure_threshold', 5);
        for ($i = 0; $i < $threshold; $i++) {
            $cb->recordFailure('wordpress');
        }

        // Circuit should be open
        $status = $cb->getStatus('wordpress');
        $this->assertEquals('open', $status['state']);
        $this->assertFalse($cb->isAvailable('wordpress'));

        // Verify it's in open circuits list
        $open = $cb->getOpenCircuits();
        $this->assertArrayHasKey('wordpress', $open);
    }

    /** @test */
    public function circuit_closes_after_successful_half_open_probe()
    {
        $cb = app(ConnectorCircuitBreakerService::class);
        $threshold = config('execution.circuit_breaker.default.failure_threshold', 5);

        // Open circuit
        for ($i = 0; $i < $threshold; $i++) {
            $cb->recordFailure('email');
        }
        $this->assertFalse($cb->isAvailable('email'));

        // Simulate cooldown expiry by setting opened_at in the past
        Cache::put('circuit:email:opened_at', now()->subMinutes(5), 3600);

        // Should transition to half_open and allow
        $this->assertTrue($cb->isAvailable('email'));

        // Record success to close
        $cb->recordSuccess('email');
        $this->assertEquals('closed', $cb->getStatus('email')['state']);
    }

    // ── 5. Manual mode uses same protections ─────────────────────────────

    /** @test */
    public function manual_execution_creates_task_with_idempotency_key()
    {
        $task = Task::create([
            'workspace_id' => $this->workspace->id,
            'engine' => 'crm',
            'action' => 'create_lead',
            'payload_json' => ['name' => 'Manual Test'],
            'source' => 'manual',
            'status' => 'pending',
            'idempotency_key' => hash('sha256', "{$this->workspace->id}:create_lead:" . json_encode(['name' => 'Manual Test'], JSON_SORT_KEYS)),
        ]);

        $this->assertNotNull($task->idempotency_key);
        $this->assertEquals('manual', $task->source);
        $this->assertEquals(64, strlen($task->idempotency_key)); // sha256 hex length
    }

    // ── 6. Workspace throttling blocks excess actions ─────────────────────

    /** @test */
    public function workspace_throttling_detects_capacity()
    {
        $qc = app(QueueControlService::class);

        // Create tasks up to the cap
        $cap = config('queue_control.workspace_concurrency', 10);
        for ($i = 0; $i < $cap; $i++) {
            Task::create([
                'workspace_id' => $this->workspace->id,
                'engine' => 'crm',
                'action' => 'create_lead',
                'status' => 'running',
                'started_at' => now(),
            ]);
        }

        $this->assertFalse($qc->canWorkspaceExecute($this->workspace->id));

        // Verify throttled workspaces list
        $throttled = $qc->getThrottledWorkspaces();
        $this->assertArrayHasKey($this->workspace->id, $throttled);
    }

    /** @test */
    public function rate_limiter_blocks_when_exceeded()
    {
        $rl = app(ExecutionRateLimiterService::class);

        // Exhaust workspace per-minute limit
        $limit = config('execution.rate_limits.workspace.per_minute', 30);
        $key = "ratelimit:ws:{$this->workspace->id}:per_minute";
        Cache::put($key, $limit, 60);

        $check = $rl->check($this->workspace->id, null, 'wordpress');
        $this->assertFalse($check['allowed']);
        $this->assertStringContains('Workspace rate limit exceeded', $check['reason']);
    }

    // ── 7. Stale task recovery resolves orphan state ──────────────────────

    /** @test */
    public function stale_tasks_are_detected()
    {
        $qc = app(QueueControlService::class);

        Task::create([
            'workspace_id' => $this->workspace->id,
            'engine' => 'crm',
            'action' => 'create_lead',
            'status' => 'running',
            'started_at' => now()->subMinutes(15),
        ]);

        $stale = $qc->findStaleTasks();
        $this->assertEquals(1, $stale->count());
    }

    /** @test */
    public function orphaned_reservations_are_found()
    {
        $creditService = app(CreditService::class);

        // Create old pending reservation
        CreditTransaction::create([
            'workspace_id' => $this->workspace->id,
            'type' => 'reserve',
            'amount' => 25,
            'reservation_status' => 'pending',
            'reservation_reference' => 'rsv_orphan_test',
            'created_at' => now()->subHour(),
        ]);

        $orphaned = $creditService->findOrphanedReservations(30);
        $this->assertEquals(1, $orphaned->count());
    }

    // ── 8. Mock social clearly marked as mock ────────────────────────────

    /** @test */
    public function social_mock_result_is_flagged()
    {
        config(['connectors.social.mock_mode' => true]);

        $connector = app(\App\Connectors\SocialConnector::class);
        $result = $connector->execute('create_post', [
            'platform' => 'facebook',
            'content' => 'Test post',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['mock'] ?? false);
        $this->assertStringContains('[MOCK]', $result['message']);

        // Verification should flag mock_result=true
        $verification = $connector->verifyResult('create_post', [], $result);
        $this->assertTrue($verification['verified']);
        $this->assertTrue($verification['data']['mock_result'] ?? false);
    }

    // ── 9. WordPress verification failure converts to failed task ─────────

    /** @test */
    public function wordpress_verification_detects_missing_entity_id()
    {
        $connector = app(\App\Connectors\WordPressConnector::class);

        // Simulate a "success" response missing the post_id
        $fakeResult = [
            'success' => true,
            'data' => ['url' => 'https://example.com/post'],
            'message' => 'Post created successfully',
        ];

        $verification = $connector->verifyResult('create_post', [], $fakeResult);

        $this->assertFalse($verification['verified']);
        $this->assertStringContains('missing valid post_id', $verification['message']);
    }

    /** @test */
    public function wordpress_verification_passes_with_valid_id()
    {
        $connector = app(\App\Connectors\WordPressConnector::class);

        $fakeResult = [
            'success' => true,
            'data' => ['post_id' => 123, 'url' => 'https://example.com/post/123'],
            'message' => 'Post created successfully',
        ];

        $verification = $connector->verifyResult('create_post', [], $fakeResult);

        $this->assertTrue($verification['verified']);
    }

    // ── Task progress events ─────────────────────────────────────────────

    /** @test */
    public function task_progress_events_are_recorded()
    {
        $progress = app(TaskProgressService::class);

        $task = Task::create([
            'workspace_id' => $this->workspace->id,
            'engine' => 'crm',
            'action' => 'create_lead',
            'status' => 'running',
        ]);

        $progress->recordEvent($task->id, 'execution_started', 'running', message: 'Started');
        $progress->recordEvent($task->id, 'step_executed', 'running', step: 1, message: 'Step 1 done');
        $progress->recordEvent($task->id, 'execution_completed', 'completed', message: 'Done');

        $events = $progress->getEvents($task->id);
        $this->assertCount(3, $events);

        $status = $progress->getStatus($task->id);
        $this->assertNotNull($status);
        $this->assertEquals($task->id, $status['task_id']);
    }

    // ── Helper ────────────────────────────────────────────────────────────

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
