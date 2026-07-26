<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\EngineKernel\EngineExecutionService;
use App\Engines\Studio\Readiness\PromptComparisonService;
use App\Models\CreativeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 Phase L — execution shadow observation + comparison integration.
 * Proves the actual provider prompt is observed and compared without altering
 * execution / provider / billing / assets.
 */
class ExecutionShadowComparisonTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private ?string $providerPrompt = null;
    private int $dispatches = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '204800'])]);
    }

    private function fakeRuntime(): void
    {
        $this->dispatches = 0;
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('aiRun')->andReturn(['text' => '']);
        $rt->shouldReceive('imageGenerate')->andReturnUsing(function ($prompt) {
            $this->dispatches++;
            $this->providerPrompt = $prompt;
            return ['success' => true, 'url' => 'https://cdn.test/i.png', 'size' => '1024x1024',
                    'quality' => 'standard', 'storage_path' => null, 'model' => 'gpt-image-1', 'provider' => 'openai'];
        });
        $this->app->instance(RuntimeClient::class, $rt);
    }

    private function ees(): EngineExecutionService
    {
        return app(EngineExecutionService::class);
    }

    /** @test */
    public function actual_provider_prompt_is_observed_and_compared_and_dispatched_once(): void
    {
        $this->fakeRuntime();
        $this->ees()->execute($this->testWorkspace->id, 'creative', 'generate_image', ['prompt' => 'a red bicycle']);

        $this->assertSame(1, $this->dispatches);                          // dispatch exactly once

        $meta = CreativeJob::first()->metadata;
        $obs = $meta['execution_observation'];
        $this->assertNotEmpty($obs['actual_provider_prompt']);
        $this->assertSame(64, strlen($obs['actual_provider_prompt_hash']));
        $this->assertSame('assets.prompt', $obs['source']);

        $r = $meta['compiler_readiness'];
        // production enhances (blueprint + no-text) → compiler (normalize-only) diverges.
        $this->assertSame('PRODUCTION_ENHANCED', $r['divergence_class']);
        $this->assertIsInt($r['readiness_score']);
        $this->assertSame($meta['guardrails']['verdict'], $r['guardrail_verdict']); // guardrail correlated
    }

    /** @test */
    public function observation_does_not_change_the_provider_prompt(): void
    {
        $this->fakeRuntime();
        $wsId = $this->testWorkspace->id;
        $p = 'a red bicycle beside a blue door';

        config(['studio.execution_prompt_observation' => true, 'studio.compiler_readiness_shadow' => true]);
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => $p]);
        $on = $this->providerPrompt;

        config(['studio.execution_prompt_observation' => false, 'studio.compiler_readiness_shadow' => false]);
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => $p]);
        $off = $this->providerPrompt;

        $this->assertNotNull($on);
        $this->assertSame($off, $on);
    }

    /** @test */
    public function observation_flag_off_writes_no_observation_or_readiness(): void
    {
        config(['studio.execution_prompt_observation' => false]);
        $this->fakeRuntime();
        $this->ees()->execute($this->testWorkspace->id, 'creative', 'generate_image', ['prompt' => 'a red bicycle']);

        $meta = CreativeJob::first()->metadata;
        $this->assertArrayNotHasKey('execution_observation', $meta);
        $this->assertArrayNotHasKey('compiler_readiness', $meta);
    }

    /** @test */
    public function comparison_failure_is_isolated_and_recorded_safely(): void
    {
        $broken = \Mockery::mock(PromptComparisonService::class);
        $broken->shouldReceive('compare')->andThrow(new \RuntimeException('cmp boom'));
        $this->app->instance(PromptComparisonService::class, $broken);

        $this->fakeRuntime();
        $wsId = $this->testWorkspace->id;
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'a red bicycle']);

        // generation + billing + asset unaffected
        $this->assertSame(1, DB::table('assets')->where('workspace_id', $wsId)->where('status', 'completed')->count());
        $this->assertCreditBalance(5000 - 2);
        // observation still captured; comparison marked failed safely
        $meta = CreativeJob::first()->metadata;
        $this->assertArrayHasKey('execution_observation', $meta);
        $this->assertTrue($meta['compiler_readiness']['comparison_failed']);
    }

    /** @test */
    public function successful_generation_still_charges_exactly_once_and_persists_one_asset(): void
    {
        $this->fakeRuntime();
        $wsId = $this->testWorkspace->id;
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'a red bicycle']);

        $this->assertCreditBalance(5000 - 2);
        $this->assertReservedBalance(0);
        $this->assertSame(1, DB::table('assets')->where('workspace_id', $wsId)->count());
        $this->assertSame(1, DB::table('credit_transactions')->where('workspace_id', $wsId)->where('type', 'commit')->count());
    }
}
