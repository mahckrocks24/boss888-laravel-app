<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Engines\Creative\Services\CreativeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — CREATIVE ASSET LIFECYCLE CHARACTERIZATION (test-only).
 *
 * Pins the current `assets` contract for CreativeService (the EES/CreativeService
 * path — the Orchestrator connector path writes NO asset row, see
 * SarahCreativeImageLifecycleTest). Documents the PRE-linkage baseline: there is
 * no creative_job_id column yet (Fix will add it later; not in this phase).
 */
class CreativeAssetLifecycleTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '204800'])]);
    }

    private function bindRuntime(bool $success): void
    {
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('aiRun')->andReturn(['text' => '']);
        $rt->shouldReceive('imageGenerate')->andReturn($success
            ? ['success' => true, 'url' => 'https://cdn.test/a.png', 'size' => '1024x1024',
               'quality' => 'standard', 'storage_path' => null, 'model' => 'gpt-image-1', 'provider' => 'openai']
            : ['success' => false, 'error' => 'provider_down']);
        $this->app->instance(RuntimeClient::class, $rt);
    }

    /** @test */
    public function completed_image_asset_has_the_expected_attribution_and_status(): void
    {
        $this->bindRuntime(true);
        $wsId = $this->testWorkspace->id;

        $out = $this->svcGenerate($wsId, ['prompt' => 'A tidy asset', 'task_id' => 4242]);

        $assets = DB::table('assets')->where('workspace_id', $wsId)->get();
        $this->assertCount(1, $assets);            // exactly one, no duplicates
        $a = $assets->first();
        $this->assertSame($wsId, (int) $a->workspace_id);
        $this->assertSame('image', $a->type);
        $this->assertSame('completed', $a->status);
        $this->assertSame('https://cdn.test/a.png', $a->url);
        $this->assertSame('LevelUp AI', $a->provider);        // white-labelled provider
        $this->assertSame('LevelUp AI', $a->model);
        $this->assertSame(4242, (int) $a->task_id);           // task linkage persisted when provided
    }

    /** @test */
    public function failed_generation_leaves_one_failed_asset_with_no_url(): void
    {
        $this->bindRuntime(false);
        $wsId = $this->testWorkspace->id;

        $this->svcGenerate($wsId, ['prompt' => 'doomed asset']);

        $assets = DB::table('assets')->where('workspace_id', $wsId)->get();
        $this->assertCount(1, $assets);                       // no orphan/duplicate
        $this->assertSame('failed', $assets->first()->status);
        $this->assertNull($assets->first()->url);             // no successful URL on failure
    }

    /** @test */
    public function assets_table_has_the_creative_job_id_linkage_column(): void
    {
        // PHASE I (2026-07-26): the creative_job_id linkage column is now present.
        $this->assertTrue(Schema::hasColumn('assets', 'creative_job_id'));
    }

    private function svcGenerate(int $wsId, array $params): array
    {
        return app(CreativeService::class)->generateImage($wsId, $params);
    }
}
