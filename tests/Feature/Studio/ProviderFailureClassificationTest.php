<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\TaskSystem\Orchestrator;
use App\Services\ExecutionRateLimiterService;
use App\Services\QueueControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — PHASE H2 PROVIDER FAILURE CLASSIFICATION (test-only).
 *
 * The Orchestrator already classifies provider errors (transientRequeueDelay):
 *   TERMINAL (permanent)  → quota / invalid / unauthorized / auth / unknown → do NOT requeue.
 *   RETRYABLE (transient) → timeout / 5xx / 429 / connection → requeue with backoff.
 *
 * DEFECT (pre-H2): TaskService::markFailed re-queued EVERY failure up to 4× on a
 * generic retry budget, so terminal errors the Orchestrator had already ruled
 * non-retryable were re-dispatched anyway. H2 makes the Orchestrator's terminal
 * path fail IMMEDIATELY (markFailed terminal:true), so terminal failures stop
 * requeuing. Credits are released in both cases (commit XOR release preserved).
 */
class ProviderFailureClassificationTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    private function allowGuards(): void
    {
        $rl = \Mockery::mock(ExecutionRateLimiterService::class)->makePartial();
        $rl->shouldReceive('check')->andReturn(['allowed' => true, 'reason' => '']);
        $rl->shouldReceive('record')->andReturnNull();
        $this->app->instance(ExecutionRateLimiterService::class, $rl);

        $qc = \Mockery::mock(QueueControlService::class)->makePartial();
        $qc->shouldReceive('canWorkspaceExecute')->andReturn(true);
        $this->app->instance(QueueControlService::class, $qc);
    }

    private function runWithProviderError(string $error): \App\Models\Task
    {
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('aiRun')->andReturn(['text' => '']);
        $rt->shouldReceive('imageGenerate')->andReturn(['success' => false, 'error' => $error]);
        $this->app->instance(RuntimeClient::class, $rt);

        $task = $this->createTask([
            'engine' => 'creative', 'action' => 'generate_image',
            'payload_json' => ['prompt' => 'classify me'], 'credit_cost' => 2,
        ]);
        app(Orchestrator::class)->execute($task);
        return $task->fresh();
    }

    private function assertTerminal(\App\Models\Task $task): void
    {
        $this->assertSame('failed', $task->status, 'terminal error must fail immediately (no requeue)');
        $this->assertCreditBalance(5000);          // released, not charged
        $this->assertReservedBalance(0);
        $this->assertContains('release', $this->txnTypes());
        $this->assertNotContains('commit', $this->txnTypes());
    }

    private function assertRetryable(\App\Models\Task $task): void
    {
        $this->assertSame('queued', $task->status, 'transient error must requeue for retry');
        $this->assertCreditBalance(5000);          // no charge on requeue
        $this->assertReservedBalance(0);
        $this->assertNotContains('commit', $this->txnTypes());
    }

    private function txnTypes(): array
    {
        return \Illuminate\Support\Facades\DB::table('credit_transactions')
            ->where('workspace_id', $this->testWorkspace->id)->pluck('type')->all();
    }

    // ── TERMINAL ────────────────────────────────────────────────────────

    /** @test */
    public function quota_exhausted_is_terminal(): void
    {
        $this->allowGuards();
        $this->assertTerminal($this->runWithProviderError('quota exceeded'));
    }

    /** @test */
    public function invalid_request_is_terminal(): void
    {
        $this->allowGuards();
        $this->assertTerminal($this->runWithProviderError('invalid request: bad parameters'));
    }

    /** @test */
    public function invalid_model_is_terminal(): void
    {
        $this->allowGuards();
        $this->assertTerminal($this->runWithProviderError('invalid model: gpt-nonexistent'));
    }

    /** @test */
    public function authentication_failure_is_terminal(): void
    {
        $this->allowGuards();
        $this->assertTerminal($this->runWithProviderError('unauthorized: bad api key'));
    }

    // ── RETRYABLE ───────────────────────────────────────────────────────

    /** @test */
    public function upstream_timeout_is_retryable(): void
    {
        $this->allowGuards();
        $this->assertRetryable($this->runWithProviderError('request_timeout 503'));
    }

    /** @test */
    public function http_429_is_retryable(): void
    {
        $this->allowGuards();
        $this->assertRetryable($this->runWithProviderError('429 rate limit exceeded'));
    }

    /** @test */
    public function connection_reset_is_retryable(): void
    {
        $this->allowGuards();
        $this->assertRetryable($this->runWithProviderError('econnreset connection dropped'));
    }

    // ── UNKNOWN → fails safe (terminal) ─────────────────────────────────

    /** @test */
    public function unknown_provider_error_follows_the_terminal_fallback(): void
    {
        // Documented rule: an unclassified error is NOT retried (safest — avoids
        // burning the retry budget + credits on an unknown permanent condition).
        $this->allowGuards();
        $this->assertTerminal($this->runWithProviderError('some_unrecognized_glitch_zzz'));
    }
}
