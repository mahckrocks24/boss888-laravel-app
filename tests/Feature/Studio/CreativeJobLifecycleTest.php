<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\EngineKernel\EngineExecutionService;
use App\Core\PlanGating\PlanGatingService;
use App\Core\TaskSystem\Orchestrator;
use App\Models\CreativeJob;
use App\Services\ExecutionRateLimiterService;
use App\Services\QueueControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — PHASE I: creative_jobs observational lifecycle (test-only).
 *
 * Proves that every Studio generation records exactly one CreativeJob, links the
 * asset/task/workspace/user, stores provider metadata, mirrors completion/failure,
 * NEVER affects execution, and is fully gated by the studio.creative_jobs flag.
 */
class CreativeJobLifecycleTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '204800'])]);
    }

    private function fakeRuntime(bool $success = true): void
    {
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('aiRun')->andReturn(['text' => '']);
        $rt->shouldReceive('imageGenerate')->andReturn($success
            ? ['success' => true, 'url' => 'https://cdn.test/i.png', 'size' => '1024x1024',
               'quality' => 'standard', 'storage_path' => null, 'model' => 'gpt-image-1', 'provider' => 'openai']
            : ['success' => false, 'error' => 'provider_down']);
        $this->app->instance(RuntimeClient::class, $rt);
    }

    private function ees(): EngineExecutionService
    {
        return app(EngineExecutionService::class);
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

    // 1,5,6,7,8,10 — EES creative generation
    /** @test */
    public function ees_creative_generation_creates_a_completed_creative_job_and_links_everything(): void
    {
        $this->fakeRuntime(true);
        $wsId = $this->testWorkspace->id;

        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'a serene lake', 'user_id' => $this->testUser->id]);

        $jobs = CreativeJob::all();
        $this->assertCount(1, $jobs);
        $job = $jobs->first();

        $this->assertSame('completed', $job->status);                 // completion status
        $this->assertSame('generation', $job->type);
        $this->assertSame('generate_image', $job->capability);
        $this->assertSame($wsId, (int) $job->workspace_id);           // workspace linked
        $this->assertSame('a serene lake', $job->original_prompt);
        $this->assertNull($job->compiled_prompt);                     // Phase J placeholder stays NULL
        $this->assertNotNull($job->asset_id);                         // asset linked
        $this->assertNotNull($job->provider);                         // provider metadata stored

        // reverse linkage: assets.creative_job_id populated
        $asset = DB::table('assets')->where('id', $job->asset_id)->first();
        $this->assertSame((int) $job->id, (int) $asset->creative_job_id);
        // relationship resolves
        $this->assertSame($wsId, (int) $job->workspace->id);
        $this->assertSame((int) $job->asset_id, (int) $job->asset->id);
    }

    // 4 — direct Studio generation without a task
    /** @test */
    public function ees_studio_generation_without_task_creates_a_taskless_creative_job(): void
    {
        $this->fakeRuntime(true);
        $wsId = $this->testWorkspace->id;

        $this->ees()->execute($wsId, 'studio', 'generate_image', ['prompt' => 'a bold poster']);

        $jobs = CreativeJob::all();
        $this->assertCount(1, $jobs);
        $this->assertNull($jobs->first()->task_id);              // direct — no task
        $this->assertSame('completed', $jobs->first()->status);
        $this->assertSame('generate_image', $jobs->first()->capability);
    }

    // 2 — video creates a CreativeJob (type=video) via the Orchestrator
    //     (EES generate_video is approval-gated 'review', so it returns before
    //     generation — the real video generation path is the async Orchestrator).
    /** @test */
    public function video_generation_creates_a_video_type_creative_job(): void
    {
        $this->allowGuards();
        $this->fakeRuntime(true);
        // video needs Pro+; bypass plan gating so generation reaches the CreativeJob hook
        $pg = \Mockery::mock(PlanGatingService::class)->makePartial();
        $pg->shouldReceive('check')->andReturn(['allowed' => true, 'reason' => '']);
        $this->app->instance(PlanGatingService::class, $pg);

        $task = $this->createTask([
            'engine' => 'creative', 'action' => 'generate_video',
            'payload_json' => ['prompt' => 'waves rolling in'], 'credit_cost' => 8,
        ]);
        app(Orchestrator::class)->execute($task);

        $job = CreativeJob::where('task_id', $task->id)->first();
        $this->assertNotNull($job, 'video generation must record a CreativeJob');
        $this->assertSame('video', $job->type);
        $this->assertSame('generate_video', $job->capability);
    }

    // 3,10 — task-linked generation via the Orchestrator
    /** @test */
    public function orchestrator_task_generation_links_the_creative_job_to_the_task(): void
    {
        $this->allowGuards();
        $this->fakeRuntime(true);

        $task = $this->createTask([
            'engine' => 'creative', 'action' => 'generate_image',
            'payload_json' => ['prompt' => 'mountain sunrise'], 'credit_cost' => 2,
        ]);
        app(Orchestrator::class)->execute($task);

        $job = CreativeJob::where('task_id', $task->id)->first();
        $this->assertNotNull($job, 'task-linked CreativeJob must exist');
        $this->assertSame('completed', $job->status);
        $this->assertSame($task->id, (int) $task->fresh()->creativeJob->task_id); // Task->creativeJob relationship
        // asset produced by the Orchestrator path is linked
        $this->assertNotNull($job->asset_id);
    }

    // 9 — failure updates status
    /** @test */
    public function failed_generation_marks_the_creative_job_failed(): void
    {
        $this->fakeRuntime(false);
        $wsId = $this->testWorkspace->id;

        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'doomed']);

        $job = CreativeJob::first();
        $this->assertNotNull($job);
        $this->assertSame('failed', $job->status);
        $this->assertNotNull($job->failed_at);
    }

    // 11 — CreativeJob failure does NOT stop execution
    /** @test */
    public function creative_job_hook_failure_never_breaks_generation(): void
    {
        // A completely broken CreativeJobService (throws on every call).
        $broken = \Mockery::mock(\App\Engines\Studio\Services\CreativeJobService::class);
        $broken->shouldReceive('begin')->andThrow(new \RuntimeException('boom'));
        $broken->shouldReceive('complete')->andThrow(new \RuntimeException('boom'));
        $broken->shouldReceive('fail')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(\App\Engines\Studio\Services\CreativeJobService::class, $broken);

        $this->fakeRuntime(true);
        $wsId = $this->testWorkspace->id;

        // Generation must still succeed end-to-end despite the broken observer.
        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'resilient']);

        $this->assertSame(1, DB::table('assets')->where('workspace_id', $wsId)->where('status', 'completed')->count());
        $this->assertCreditBalance(5000 - 2);   // billing unaffected
        $this->assertSame(0, CreativeJob::count());
    }

    // 12 — feature flag OFF preserves previous behavior
    /** @test */
    public function feature_flag_off_writes_no_creative_job_and_preserves_behavior(): void
    {
        config(['studio.creative_jobs' => false]);
        $this->fakeRuntime(true);
        $wsId = $this->testWorkspace->id;

        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'flag off']);

        $this->assertSame(0, CreativeJob::count());                    // no job written
        $this->assertSame(1, DB::table('assets')->where('workspace_id', $wsId)->where('status', 'completed')->count());
        $this->assertCreditBalance(5000 - 2);                          // identical billing
    }
}
