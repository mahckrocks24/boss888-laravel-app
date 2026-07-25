<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\EngineKernel\EngineExecutionService;
use App\Engines\Studio\Compiler\PromptCompilerService;
use App\Models\CreativeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 Phase J — Prompt Compiler SHADOW integration.
 *
 * Proves the compiler persists compiled_prompt/generation_spec/metadata onto the
 * CreativeJob, is fully flag- and failure-isolated, and — critically — NEVER
 * changes the prompt the provider receives.
 */
class PromptCompilerShadowTest extends TestCase
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
    public function generation_persists_compiled_prompt_spec_and_metadata(): void
    {
        $this->fakeRuntime();
        $this->ees()->execute($this->testWorkspace->id, 'creative', 'generate_image',
            ['prompt' => "  A 16:9   cinematic  shot  "]);

        $job = CreativeJob::first();
        $this->assertNotNull($job);
        $this->assertSame('A 16:9 cinematic shot', $job->compiled_prompt);      // normalized
        $this->assertIsArray($job->generation_spec);
        $this->assertSame('image', $job->generation_spec['capability']);
        $this->assertSame('16:9', $job->generation_spec['aspect_ratio']);
        $this->assertSame('cinematic', $job->generation_spec['style']);

        $compiler = $job->metadata['compiler'];
        $this->assertSame('1.0.0-shadow', $compiler['version']);
        $this->assertArrayHasKey('confidence', $compiler);
        $this->assertArrayHasKey('warnings', $compiler);
        $this->assertSame('normalized_only', $compiler['comparison']['result']);
    }

    /** @test */
    public function compiler_does_not_change_the_prompt_the_provider_receives(): void
    {
        $this->fakeRuntime();
        $wsId = $this->testWorkspace->id;
        $prompt = 'A cinematic 16:9 sunset over the marina';

        config(['studio.prompt_compiler_shadow' => true]);
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => $prompt]);
        $withCompiler = $this->providerPrompt;

        config(['studio.prompt_compiler_shadow' => false]);
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => $prompt]);
        $withoutCompiler = $this->providerPrompt;

        $this->assertNotNull($withCompiler);
        // Provider prompt is byte-for-byte identical whether the compiler runs or not.
        $this->assertSame($withoutCompiler, $withCompiler);
        // And it is NOT the compiler's normalized/compiled string — the provider gets the
        // production (CreativeService-enhanced) prompt, untouched by the compiler.
        $this->assertNotSame(CreativeJob::whereNotNull('compiled_prompt')->value('compiled_prompt'), $withCompiler);
    }

    /** @test */
    public function feature_flag_off_writes_no_compiled_prompt_but_still_generates(): void
    {
        config(['studio.prompt_compiler_shadow' => false]);
        $this->fakeRuntime();
        $wsId = $this->testWorkspace->id;

        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => '  spacey  prompt  ']);

        $job = CreativeJob::first();
        $this->assertNotNull($job);                       // Phase I job still created
        $this->assertNull($job->compiled_prompt);         // compiler did nothing
        $this->assertNull($job->generation_spec);
        $this->assertSame(1, DB::table('assets')->where('workspace_id', $wsId)->where('status', 'completed')->count());
        $this->assertCreditBalance(5000 - 2);
    }

    /** @test */
    public function compiler_failure_never_affects_generation(): void
    {
        $broken = \Mockery::mock(PromptCompilerService::class);
        $broken->shouldReceive('enabled')->andReturn(true);
        $broken->shouldReceive('compile')->andThrow(new \RuntimeException('compiler boom'));
        $this->app->instance(PromptCompilerService::class, $broken);

        $this->fakeRuntime();
        $wsId = $this->testWorkspace->id;

        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'resilient']);

        // generation unaffected
        $this->assertSame(1, DB::table('assets')->where('workspace_id', $wsId)->where('status', 'completed')->count());
        $this->assertCreditBalance(5000 - 2);
        // job created; compiled_prompt stays NULL because the compiler threw
        $job = CreativeJob::first();
        $this->assertNotNull($job);
        $this->assertNull($job->compiled_prompt);
    }
}
