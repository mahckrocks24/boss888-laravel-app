<?php

namespace Tests\Feature\Studio;

use App\Core\EngineKernel\EngineExecutionService;
use App\Engines\Creative\Services\CreativeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — CREATIVE VIDEO SCENE LIFECYCLE CHARACTERIZATION (test-only).
 *
 * CreativeService::generateVideo is ASYNC: it plans scenes (LLM via runtime, else a
 * single-scene fallback), creates a `video` asset (in_progress), and dispatches one
 * creative_video_jobs row per scene through a provider waterfall [minimax, runway, mock].
 * `mock` always succeeds, so dispatch never fails outright. pollVideoJob drives jobs to
 * completion and stitches the first scene URL onto the asset.
 *
 * MiniMax T2V contract (confirmed): POST api.minimax.chat/v1/text/video_generation
 * {model:'T2V-01', prompt} → {task_id}; absent key → no HTTP, provider falls to mock.
 *
 * generate_video capability: approval=review, credit_cost=8 → the EES entry is
 * approval-gated (no inline execution, reservation released until approved).
 *
 * No real paid video generation.
 */
class CreativeVideoLifecycleTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    private function svc(): CreativeService
    {
        return app(CreativeService::class);
    }

    private function jobs(int $assetId): \Illuminate\Support\Collection
    {
        return DB::table('creative_video_jobs')->where('asset_id', $assetId)->orderBy('scene_index')->get();
    }

    // ── A. Absent provider keys → mock fallback dispatch ────────────────

    /** @test */
    public function generate_video_creates_in_progress_asset_and_dispatches_scene_via_mock_fallback(): void
    {
        Http::fake(['*' => Http::response('', 200)]); // no keys → minimax/runway fail, mock wins
        $wsId = $this->testWorkspace->id;

        $res = $this->svc()->generateVideo($wsId, ['prompt' => 'a robot dancing in the rain', 'duration' => 10]);

        $this->assertSame('in_progress', $res['status']);
        $assetId = $res['asset_id'];

        // One video asset, in_progress.
        $asset = DB::table('assets')->where('id', $assetId)->first();
        $this->assertSame('video', $asset->type);
        $this->assertSame('in_progress', $asset->status);

        // One scene job dispatched via the mock fallback, with a UUID job_ref.
        $jobs = $this->jobs($assetId);
        $this->assertCount(1, $jobs);
        $job = $jobs->first();
        $this->assertSame('mock', $job->provider);
        $this->assertSame('in_progress', $job->status);
        $this->assertNotEmpty($job->provider_job_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $job->job_ref); // uuid
    }

    // ── B. MiniMax key present → dispatch via minimax with task_id ──────

    /** @test */
    public function generate_video_dispatches_via_minimax_when_key_present(): void
    {
        config(['services.minimax.api_key' => 'mmx-test-key']);
        Http::fake([
            'api.minimax.chat/v1/text/video_generation' => Http::response(['task_id' => 'mmx-task-77'], 200),
            '*' => Http::response('', 200),
        ]);
        $wsId = $this->testWorkspace->id;

        $res = $this->svc()->generateVideo($wsId, ['prompt' => 'ocean waves at dawn', 'duration' => 5]);
        $job = $this->jobs($res['asset_id'])->first();

        $this->assertSame('minimax', $job->provider);
        $this->assertSame('mmx-task-77', $job->provider_job_id);
        $this->assertSame('in_progress', $job->status);
    }

    // ── C. Polling drives mock scene to completion + stitches asset ─────

    /** @test */
    public function polling_completes_the_mock_scene_and_finishes_the_asset(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $wsId = $this->testWorkspace->id;

        $res = $this->svc()->generateVideo($wsId, ['prompt' => 'a comet streaking by', 'duration' => 5]);
        $assetId = $res['asset_id'];

        $poll = $this->svc()->pollVideoJob($assetId);

        $this->assertSame('completed', $poll['status']);
        $this->assertNotEmpty($poll['url']);

        $asset = DB::table('assets')->where('id', $assetId)->first();
        $this->assertSame('completed', $asset->status);
        $this->assertNotEmpty($asset->url);

        $this->assertSame('completed', $this->jobs($assetId)->first()->status);
    }

    // ── D. EES video entry is plan-gated on the growth plan — no charge ─

    /** @test */
    public function ees_generate_video_is_plan_gated_before_any_reservation(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $wsId = $this->testWorkspace->id; // growth plan (below Pro)

        $res = app(EngineExecutionService::class)->execute($wsId, 'creative', 'generate_video', [
            'prompt' => 'requires pro plan',
        ]);

        // Plan gating (Step 2) runs BEFORE the credit reservation (Step 3):
        // video needs Pro+, so a growth workspace is rejected with no side effects.
        $this->assertFalse($res['success'] ?? true);
        $this->assertSame('PLAN_GATED', $res['code'] ?? null);

        // No reservation, no charge, no asset, no scene jobs.
        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);
        $this->assertSame(0, DB::table('assets')->where('workspace_id', $wsId)->where('type', 'video')->count());
        $this->assertSame(0, DB::table('creative_video_jobs')->where('workspace_id', $wsId)->count());
    }
}
