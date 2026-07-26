<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\EngineKernel\EngineExecutionService;
use App\Engines\Studio\Compiler\PromptCompilerV2Service;
use App\Engines\Studio\Readiness\CompilerReadinessService;
use App\Models\CreativeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 Phase N — Compiler V2 production-parity SHADOW integration.
 * Proves V2 reaches parity (IDENTICAL) where V1 diverges (PRODUCTION_ENHANCED),
 * both persisted independently, and V2 never touches execution.
 */
class PromptCompilerV2ShadowTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private ?string $providerPrompt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '204800'])]);
    }

    private function fakeRuntime(): void
    {
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('aiRun')->andReturn(['text' => '']);
        $rt->shouldReceive('imageGenerate')->andReturnUsing(function ($prompt) {
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
    public function v2_reaches_parity_where_v1_diverges_and_both_are_persisted(): void
    {
        $this->fakeRuntime();
        $this->ees()->execute($this->testWorkspace->id, 'creative', 'generate_image', ['prompt' => 'a red bicycle']);

        $job = CreativeJob::first();
        $m = $job->metadata;

        // both compilers persisted independently
        $this->assertSame('1.0.0-shadow', $m['compiler']['version']);
        $this->assertSame('2.0.0-parity', $m['compiler_v2']['version']);
        $this->assertNotSame($m['compiler_v2']['compiled_prompt'], $job->compiled_prompt); // V1 column ≠ V2 output

        // V1 (normalize-only) diverges from the production-enhanced provider prompt
        $this->assertSame('PRODUCTION_ENHANCED', $m['compiler_readiness']['divergence_class']);

        // V2 (parity) matches the production provider prompt
        $this->assertSame('IDENTICAL', $m['compiler_readiness_v2']['divergence_class']);
        $this->assertSame(100, $m['compiler_readiness_v2']['readiness_score']);
        $this->assertTrue($m['compiler_readiness_v2']['byte_identical']);

        // byte-parity: V2's compiled prompt equals what the provider actually received
        $this->assertSame($this->providerPrompt, $m['compiler_v2']['compiled_prompt']);
    }

    /** @test */
    public function readiness_by_version_reports_v1_and_v2_separately(): void
    {
        $this->fakeRuntime();
        $this->ees()->execute($this->testWorkspace->id, 'creative', 'generate_image', ['prompt' => 'a red bicycle']);

        $rep = app(CompilerReadinessService::class)->readinessByVersion($this->testWorkspace->id);
        $this->assertArrayHasKey('v1', $rep);
        $this->assertArrayHasKey('v2', $rep);
        $this->assertSame(0.0, $rep['v1']['normalized_agreement_rate']);    // V1 never matches
        $this->assertSame(100.0, $rep['v2']['normalized_agreement_rate']);  // V2 matches
        $this->assertSame(1, $rep['v2']['divergence_distribution']['IDENTICAL']);
    }

    /** @test */
    public function v2_compiler_failure_never_affects_generation_or_v1(): void
    {
        $broken = \Mockery::mock(PromptCompilerV2Service::class);
        $broken->shouldReceive('enabled')->andReturn(true);
        $broken->shouldReceive('compile')->andThrow(new \RuntimeException('v2 boom'));
        $this->app->instance(PromptCompilerV2Service::class, $broken);

        $this->fakeRuntime();
        $wsId = $this->testWorkspace->id;
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'resilient']);

        // generation + billing + V1 unaffected; V2 omitted
        $this->assertSame(1, DB::table('assets')->where('workspace_id', $wsId)->where('status', 'completed')->count());
        $this->assertCreditBalance(5000 - 2);
        $job = CreativeJob::first();
        $this->assertNotNull($job->compiled_prompt);                       // V1 intact
        $this->assertArrayHasKey('compiler', $job->metadata);              // V1 metadata intact
        $this->assertArrayNotHasKey('compiler_v2', $job->metadata);        // V2 omitted safely
    }

    /** @test */
    public function v2_flag_off_runs_only_v1(): void
    {
        config(['studio.prompt_compiler_v2_shadow' => false]);
        $this->fakeRuntime();
        $this->ees()->execute($this->testWorkspace->id, 'creative', 'generate_image', ['prompt' => 'a red bicycle']);

        $m = CreativeJob::first()->metadata;
        $this->assertArrayHasKey('compiler', $m);          // V1 present
        $this->assertArrayNotHasKey('compiler_v2', $m);    // V2 disabled
    }
}
