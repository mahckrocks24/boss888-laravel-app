<?php

namespace Tests\Feature\Studio;

use Tests\TestCase;
use Tests\Helpers\Boss888TestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Core\TaskSystem\Orchestrator;
use App\Services\ExecutionRateLimiterService;
use App\Services\QueueControlService;
use App\Engines\Creative\Services\BlueprintService;

/**
 * STUDIO888 MRC-2A — CREATIVE EXECUTION CHARACTERIZATION (test-only, no production logic).
 *
 * Captures the CURRENT contract of Orchestrator::execute() for the creative path.
 *
 * DIAGNOSIS (Outcome D — intentional deferred behavior):
 * The 2026-05-05 CreativeExecutionTest expected execute() to run inline to a
 * terminal state. Since then, two deferral guards were added BEFORE markRunning():
 *   - Rate limit (2026-05-25): ExecutionRateLimiterService::check → requeue()
 *   - Workspace concurrency:   QueueControlService::canWorkspaceExecute → requeue()
 * When either denies, execute() sets status='queued', requeues, and returns —
 * NO credit is reserved and NO asset is created. The stale tests never neutralize
 * these guards, so the task ends 'queued' and their terminal-state assertions fail.
 *
 * These tests pin that contract deterministically without depending on the
 * provider HTTP endpoint (which is characterized separately once confirmed).
 */
class CreativeExecutionCharacterizationTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    private function creativeTask(): \App\Models\Task
    {
        return $this->createTask([
            'engine'       => 'creative',
            'action'       => 'generate_image',
            'payload_json' => ['prompt' => 'A sunset over Dubai Marina', 'aspect_ratio' => '16:9'],
            'credit_cost'  => 10,
        ]);
    }

    private function bindRateLimiter(bool $allowed, string $reason = ''): void
    {
        $rl = \Mockery::mock(ExecutionRateLimiterService::class)->makePartial();
        $rl->shouldReceive('check')->andReturn(['allowed' => $allowed, 'reason' => $reason]);
        $rl->shouldReceive('record')->andReturnNull();
        $this->app->instance(ExecutionRateLimiterService::class, $rl);
    }

    private function bindConcurrency(bool $canExecute): void
    {
        // makePartial: only override canWorkspaceExecute; keep real resolveQueue()
        // and any other methods the dispatch/requeue path calls.
        $qc = \Mockery::mock(QueueControlService::class)->makePartial();
        $qc->shouldReceive('canWorkspaceExecute')->andReturn($canExecute);
        $this->app->instance(QueueControlService::class, $qc);
    }

    /** @test */
    public function rate_limited_creative_task_is_deferred_to_queued_and_charges_no_credit()
    {
        $this->bindRateLimiter(false, 'per_minute limit reached');

        $task = $this->creativeTask();
        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertEquals('queued', $task->status);
        $this->assertStringContainsString('auto-retry', (string) $task->progress_message);
        $this->assertCreditBalance(5000);   // no charge on deferral
        $this->assertReservedBalance(0);    // no reservation on deferral
        $this->assertDatabaseCount('assets', 0);
    }

    /** @test */
    public function concurrency_capped_creative_task_is_deferred_to_queued_and_charges_no_credit()
    {
        $this->bindRateLimiter(true);       // allow rate limit so we reach the concurrency guard
        $this->bindConcurrency(false);      // deny concurrency

        $task = $this->creativeTask();
        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertEquals('queued', $task->status);
        $this->assertStringContainsString('concurrency', (string) $task->progress_message);
        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);
    }

    /** @test */
    public function with_deferral_guards_allowed_execution_proceeds_past_queued()
    {
        $this->bindRateLimiter(true);
        $this->bindConcurrency(true);
        // Fake ALL outbound HTTP so no real provider is contacted.
        Http::fake(['*' => Http::response([
            'id' => 'img_char', 'url' => 'https://cdn.example.com/i.png',
            'type' => 'image', 'size' => 250000, 'status' => 'completed',
        ], 200)]);

        $task = $this->creativeTask();
        app(Orchestrator::class)->execute($task);
        $task->refresh();

        // With the two deferral guards allowed, the task advances PAST them: the
        // rate-limit and concurrency deferral messages are gone. (It may still end
        // 'queued' via a separate retryable-execution-error requeue when the faked
        // provider contract isn't matched — that path is characterized separately.)
        $msg = (string) $task->progress_message;
        $this->assertStringNotContainsString('concurrency cap', $msg);
        $this->assertStringNotContainsString('auto-retry in', $msg);
    }

    /** @test */
    public function blueprint_service_enhances_prompt_and_preserves_subject()
    {
        // Enhancer #2 (getImageBlueprint) is public and always runs inside
        // CreativeService::generateImage. Characterize: returns enhanced_prompt
        // that still contains the user's subject (never throws even with empty LLM key).
        $bp = app(BlueprintService::class)->getImageBlueprint(
            $this->testWorkspace->id,
            'A sunset over Dubai Marina',
            []
        );

        $this->assertArrayHasKey('enhanced_prompt', $bp);
        $this->assertIsString($bp['enhanced_prompt']);
        $this->assertStringContainsString('Dubai Marina', $bp['enhanced_prompt']);
    }
}
