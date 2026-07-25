<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\EngineKernel\EngineExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — EES STUDIO IMAGE LIFECYCLE CHARACTERIZATION (test-only).
 *
 * The Studio image action diverges sharply from the Creative one:
 *   - Dispatch owner: EES executeStudioAction → StudioAiService::generateImage
 *     (NOT StudioService, which owns design CRUD; NOT CreativeService/CreativeConnector).
 *   - Guard: StudioAiService requires runtime->isConfigured(); absent → 'runtime_unavailable'.
 *   - Enhancement: a style `match()` + "No text..." suffix (its own, not BlueprintService).
 *   - Provider: DIRECT runtime->imageGenerate (bypasses CreativeConnector).
 *   - Persistence: inserts into `media` (source='studio-ai'), NOT the `assets` table.
 *   - Result schema: {success, image_url, width, height} (NOT {status, url, asset_id}).
 *   - Capability studio_generate_image: credit_cost=3, approval=auto.
 *
 * The EES commit-on-non-throw defect (see EesCreativeLifecycleTest) applies here too:
 * StudioAiService RETURNS success:false (never throws) on provider failure AND on
 * misconfiguration, so EES commits the 3-credit charge in both failure cases.
 */
class EesStudioImageLifecycleTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private const COST = 3; // studio_generate_image credit_cost

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '2048'])]);
    }

    private function ees(): EngineExecutionService
    {
        return app(EngineExecutionService::class);
    }

    private function bindRuntime(bool $configured, ?array $imageResult): void
    {
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn($configured);
        if ($imageResult !== null) {
            $rt->shouldReceive('imageGenerate')->andReturn($imageResult);
        } else {
            $rt->shouldReceive('imageGenerate')->never();
        }
        $this->app->instance(RuntimeClient::class, $rt);
    }

    private function types(): array
    {
        return DB::table('credit_transactions')
            ->where('workspace_id', $this->testWorkspace->id)
            ->orderBy('id')->pluck('type')->all();
    }

    // ── A. Success ──────────────────────────────────────────────────────

    /** @test */
    public function successful_studio_image_inserts_media_row_and_commits_charge(): void
    {
        $this->bindRuntime(true, [
            'success'      => true,
            'url'          => 'https://cdn.test/studio.png',
            'storage_path' => 'ai-images/9/studio.png', // present → no re-download branch
            'size'         => '1024x1024',
        ]);
        $wsId = $this->testWorkspace->id;

        $res = $this->ees()->execute($wsId, 'studio', 'generate_image', ['prompt' => 'A neon poster', 'style' => 'bold']);

        // Studio result schema — image_url, not status/asset_id.
        $this->assertTrue($res['success'] ?? false);

        // Persisted to media (source=studio-ai), NOT assets.
        $this->assertSame(0, DB::table('assets')->where('workspace_id', $wsId)->count());
        $this->assertSame(1, DB::table('media')->where('workspace_id', $wsId)->where('source', 'studio-ai')->count());

        // Charge committed once.
        $this->assertCreditBalance(5000 - self::COST);
        $this->assertReservedBalance(0);
        $this->assertContains('commit', $this->types());
        $this->assertNotContains('release', $this->types());
    }

    // ── B. Provider failure (returned, not thrown) — DEFECT ─────────────

    /** @test */
    public function studio_provider_failure_writes_no_media_but_still_commits_charge_defect(): void
    {
        $this->bindRuntime(true, ['success' => false, 'error' => 'provider_down']);
        $wsId = $this->testWorkspace->id;

        $res = $this->ees()->execute($wsId, 'studio', 'generate_image', ['prompt' => 'doomed']);

        // DEFECT (masking): a returned (non-thrown) failure is reported as success:true
        // by EES — the inner failure is not surfaced to the caller.
        $this->assertTrue($res['success'] ?? false);
        $this->assertSame(0, DB::table('media')->where('workspace_id', $wsId)->where('source', 'studio-ai')->count());

        // DEFECT: charged despite failure (StudioAiService returns, never throws).
        $this->assertCreditBalance(5000 - self::COST);
        $this->assertContains('commit', $this->types());
        $this->assertNotContains('release', $this->types());
    }

    // ── C. Absent runtime config → returns runtime_unavailable, no HTTP ──

    /** @test */
    public function studio_absent_runtime_config_makes_no_provider_call_yet_still_commits_charge_defect(): void
    {
        $this->bindRuntime(false, null); // isConfigured=false, imageGenerate must NOT be called
        $wsId = $this->testWorkspace->id;

        $res = $this->ees()->execute($wsId, 'studio', 'generate_image', ['prompt' => 'anything']);

        // DEFECT (masking): misconfiguration is reported as success:true to the caller.
        $this->assertTrue($res['success'] ?? false);
        $this->assertSame(0, DB::table('media')->where('workspace_id', $wsId)->where('source', 'studio-ai')->count());

        // DEFECT: even a misconfiguration commits the charge (returned success:false, no throw).
        $this->assertCreditBalance(5000 - self::COST);
        $this->assertContains('commit', $this->types());
        $this->assertNotContains('release', $this->types());
    }
}
