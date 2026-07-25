<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\TaskSystem\Orchestrator;
use App\Services\ExecutionRateLimiterService;
use App\Services\QueueControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — SARAH / ORCHESTRATOR IMAGE LIFECYCLE CHARACTERIZATION (test-only).
 *
 * The async Orchestrator path for generate_image / _mini / _high resolves the
 * capability (connector='creative') and dispatches via the CONNECTOR path in
 * executeStep — i.e. CreativeConnector::execute('generate_image') → runtime,
 * NOT CreativeService. Confirmed consequences vs the EES path:
 *   - NO `assets` row is written (result lives in result_json.data, asset_id 'dalle3-...').
 *   - NO prompt enhancement: ensureImagePrompt passes a present prompt through,
 *     and getImageBlueprint / the no-text rule (both inside CreativeService) never run.
 *     The raw user prompt reaches the provider verbatim.
 *   - Credit: reserveCredits upfront; on the Orchestrator's "STATUS != TRUTH" guard a
 *     returned/failed step throws → releaseReservedCredits (NO charge). This is the
 *     CORRECT behaviour that the EES path lacks (see EesCreativeLifecycleTest).
 *
 * Sections 4 (A/B/C) + 5 (double-enhancement baseline). Deferral (D/E) is already
 * covered by CreativeExecutionCharacterizationTest.
 */
class SarahCreativeImageLifecycleTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private int $imageCalls = 0;
    private ?string $capturedPrompt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '204800'])]);
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

    private function fakeRuntime(bool $success, string $error = 'provider_overloaded'): void
    {
        $this->imageCalls = 0;
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('aiRun')->andReturn(['text' => '']);
        $rt->shouldReceive('chatJson')->never(); // image path invokes NO LLM enhancer
        $rt->shouldReceive('imageGenerate')->andReturnUsing(function ($prompt, $opts) use ($success, $error) {
            $this->imageCalls++;
            $this->capturedPrompt = $prompt;
            return $success
                ? ['success' => true, 'url' => 'https://cdn.test/i.png', 'size' => '1792x1024',
                   'quality' => 'standard', 'storage_path' => 'ai-images/1/i.png', 'model' => 'gpt-image-1', 'provider' => 'openai']
                : ['success' => false, 'error' => $error];
        });
        $this->app->instance(RuntimeClient::class, $rt);
    }

    private function imageTask(string $action, array $extra = [], int $cost = 2): \App\Models\Task
    {
        return $this->createTask([
            'engine' => 'creative', 'action' => $action,
            'payload_json' => array_merge(['prompt' => 'A sunset over Dubai Marina', 'aspect_ratio' => '16:9'], $extra),
            'credit_cost' => $cost,
        ]);
    }

    private function txnTypes(): array
    {
        return DB::table('credit_transactions')->where('workspace_id', $this->testWorkspace->id)->pluck('type')->all();
    }

    // ── A. Success ──────────────────────────────────────────────────────

    /**
     * PHASE H2 (2026-07-26): Sarah/Orchestrator now persists an assets row for a
     * successful image, at parity with the EES path (was: connector-only, no asset).
     *
     * @test
     */
    public function successful_generate_image_completes_commits_and_persists_one_asset(): void
    {
        $this->allowGuards();
        $this->fakeRuntime(true);

        $task = $this->imageTask('generate_image', [], 2);
        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertSame('completed', $task->status);
        $this->assertTrue(($task->result_json['success'] ?? false));
        $this->assertSame('https://cdn.test/i.png', $task->result_json['data']['url'] ?? null);

        // H2 parity: exactly one asset persisted, correctly attributed, white-labelled.
        $assets = DB::table('assets')->where('workspace_id', $this->testWorkspace->id)->get();
        $this->assertCount(1, $assets);
        $asset = $assets->first();
        $this->assertSame('image', $asset->type);
        $this->assertSame('completed', $asset->status);
        $this->assertSame('https://cdn.test/i.png', $asset->url);
        $this->assertSame('LevelUp AI', $asset->provider);   // identical to EES metadata
        $this->assertSame('LevelUp AI', $asset->model);
        $this->assertSame($task->id, (int) $asset->task_id);  // task linkage

        // Charge committed once, dispatched once.
        $this->assertCreditBalance(5000 - 2);
        $this->assertReservedBalance(0);
        $this->assertContains('commit', $this->txnTypes());
        $this->assertNotContains('release', $this->txnTypes());
        $this->assertSame(1, $this->imageCalls);
    }

    /** @test */
    public function re_executing_a_completed_image_task_does_not_duplicate_the_asset(): void
    {
        $this->allowGuards();
        $this->fakeRuntime(true);

        $task = $this->imageTask('generate_image', [], 2);
        app(Orchestrator::class)->execute($task);
        // idempotent replay
        app(Orchestrator::class)->execute($task->fresh());

        $this->assertSame(1, DB::table('assets')->where('task_id', $task->id)->count());
    }

    // ── B1. Retryable provider failure → requeued ('queued') ────────────

    /** @test */
    public function retryable_provider_failure_requeues_the_task_and_does_not_commit(): void
    {
        $this->allowGuards();
        $this->fakeRuntime(false, 'provider_overloaded'); // retryable error keyword

        $task = $this->imageTask('generate_image', [], 2);
        app(Orchestrator::class)->execute($task);
        $task->refresh();

        // A retryable execution error requeues rather than terminally failing.
        $this->assertSame('queued', $task->status);
        // No charge on a requeue; reservation not committed.
        $this->assertReservedBalance(0);
        $this->assertNotContains('commit', $this->txnTypes());
        $this->assertCreditBalance(5000);
    }

    // ── B2. Terminal provider error fails immediately (H2) ──────────────

    /** @test */
    public function quota_provider_error_terminally_fails_and_releases_credit(): void
    {
        // PHASE H2: quota is a terminal (non-retryable) provider error — the task
        // fails immediately instead of being requeued 4× on the generic retry budget.
        $this->allowGuards();
        $this->fakeRuntime(false, 'quota exceeded');

        $task = $this->imageTask('generate_image', [], 2);
        app(Orchestrator::class)->execute($task);
        $task->refresh();

        $this->assertSame('failed', $task->status);
        $this->assertReservedBalance(0);
        $this->assertNotContains('commit', $this->txnTypes());
        $this->assertCreditBalance(5000);
    }

    // ── C. mini / high route with quality and charge task cost ──────────

    /** @test */
    public function generate_image_mini_and_high_route_through_connector_and_charge_task_cost(): void
    {
        $this->allowGuards();
        $this->fakeRuntime(true);

        $mini = $this->imageTask('generate_image_mini', [], 1);
        app(Orchestrator::class)->execute($mini);
        $mini->refresh();
        $this->assertSame('completed', $mini->status);
        $this->assertCreditBalance(5000 - 1);

        $this->fakeRuntime(true);
        $high = $this->imageTask('generate_image_high', [], 4);
        app(Orchestrator::class)->execute($high);
        $high->refresh();
        $this->assertSame('completed', $high->status);
        $this->assertCreditBalance(5000 - 1 - 4);
    }

    // ── Section 5: double-enhancement baseline (Sarah path = no enhancement) ──

    /** @test */
    public function sarah_image_path_sends_raw_prompt_with_no_llm_enhancer(): void
    {
        $this->allowGuards();
        $this->fakeRuntime(true);

        $task = $this->imageTask('generate_image', ['prompt' => 'A red bicycle beside the "Blue Door" cafe'], 2);
        app(Orchestrator::class)->execute($task);

        // Subject + exact quoted text preserved; no stage mutated/invented anything;
        // exactly one provider dispatch; chatJson (LLM enhancer) never called (mock ->never()).
        $this->assertSame('A red bicycle beside the "Blue Door" cafe', $this->capturedPrompt);
        $this->assertSame(1, $this->imageCalls);
    }
}
