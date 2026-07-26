<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\EngineKernel\EngineExecutionService;
use App\Engines\Studio\Guardrail\PromptGuardrailService;
use App\Models\CreativeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 Phase K — Prompt Guardrail SHADOW integration.
 *
 * Proves the guardrail report is persisted onto the CreativeJob, is flag- and
 * failure-isolated, and never touches execution / provider / billing / assets /
 * the compiler output.
 */
class PromptGuardrailShadowTest extends TestCase
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
    public function generation_persists_a_guardrail_report(): void
    {
        $this->fakeRuntime();
        $this->ees()->execute($this->testWorkspace->id, 'creative', 'generate_image',
            ['prompt' => 'A cinematic 16:9 sunset over Dubai Marina at golden hour']);

        $g = CreativeJob::first()->metadata['guardrails'];
        $this->assertSame('1.0.0-shadow', $g['guardrail_version']);
        $this->assertSame('PASS', $g['verdict']);
        $this->assertSame('INFO', $g['severity']);
        $this->assertIsInt($g['overall_score']);
        $this->assertArrayHasKey('policy_score', $g);
        $this->assertArrayHasKey('quality_score', $g);
        $this->assertArrayHasKey('consistency_score', $g);
        $this->assertArrayHasKey('violations', $g);
        $this->assertArrayHasKey('recommendations', $g);
    }

    /** @test */
    public function guardrails_flag_off_writes_no_report_but_keeps_the_compiler(): void
    {
        config(['studio.prompt_guardrails_shadow' => false]);
        $this->fakeRuntime();

        $this->ees()->execute($this->testWorkspace->id, 'creative', 'generate_image', ['prompt' => 'a scene']);

        $meta = CreativeJob::first()->metadata;
        $this->assertArrayHasKey('compiler', $meta);      // compiler still ran
        $this->assertArrayNotHasKey('guardrails', $meta);  // guardrails did not
    }

    /** @test */
    public function guardrail_failure_never_affects_generation_or_compiler(): void
    {
        $broken = \Mockery::mock(PromptGuardrailService::class);
        $broken->shouldReceive('enabled')->andReturn(true);
        $broken->shouldReceive('evaluate')->andThrow(new \RuntimeException('guardrail boom'));
        $this->app->instance(PromptGuardrailService::class, $broken);

        $this->fakeRuntime();
        $wsId = $this->testWorkspace->id;
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'resilient scene']);

        // generation + compiler unaffected; guardrail report omitted
        $this->assertSame(1, DB::table('assets')->where('workspace_id', $wsId)->where('status', 'completed')->count());
        $this->assertCreditBalance(5000 - 2);
        $job = CreativeJob::first();
        $this->assertNotNull($job->compiled_prompt);              // compiler persisted
        $this->assertArrayNotHasKey('guardrails', $job->metadata); // guardrail omitted
    }

    /** @test */
    public function guardrails_do_not_change_the_provider_prompt(): void
    {
        $this->fakeRuntime();
        $wsId = $this->testWorkspace->id;
        $prompt = 'A cinematic 16:9 sunset over the marina';

        config(['studio.prompt_guardrails_shadow' => true]);
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => $prompt]);
        $on = $this->providerPrompt;

        config(['studio.prompt_guardrails_shadow' => false]);
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => $prompt]);
        $off = $this->providerPrompt;

        $this->assertNotNull($on);
        $this->assertSame($off, $on); // provider prompt identical regardless of guardrails
    }
}
