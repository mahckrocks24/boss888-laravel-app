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
 * PHASE H1 CORRECTION (2026-07-25): EES now releases (not commits) and returns
 * success:false when StudioAiService returns success:false — including provider
 * failure and runtime_unavailable. The success path is unchanged. These
 * expectations were flipped from the Phase B baseline (which pinned the old bug).
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

    // ── B. Provider failure (returned, not thrown) — H1 corrected ───────

    /** @test */
    public function studio_provider_failure_releases_credit_and_reports_failure(): void
    {
        $this->bindRuntime(true, ['success' => false, 'error' => 'provider_down']);
        $wsId = $this->testWorkspace->id;

        $res = $this->ees()->execute($wsId, 'studio', 'generate_image', ['prompt' => 'doomed']);

        // H1: truthful failure surfaced to the caller.
        $this->assertFalse($res['success'] ?? true);
        $this->assertSame(0, DB::table('media')->where('workspace_id', $wsId)->where('source', 'studio-ai')->count());

        // Released, not charged.
        $this->assertCreditBalance(5000);
        $this->assertContains('release', $this->types());
        $this->assertNotContains('commit', $this->types());
    }

    // ── C. Absent runtime config → runtime_unavailable, no HTTP, no charge ─

    /** @test */
    public function studio_absent_runtime_config_reports_failure_with_safe_code_and_no_charge(): void
    {
        $this->bindRuntime(false, null); // isConfigured=false, imageGenerate must NOT be called
        $wsId = $this->testWorkspace->id;

        $res = $this->ees()->execute($wsId, 'studio', 'generate_image', ['prompt' => 'anything']);

        // H1: truthful failure + a safe, non-sensitive code (no charge, no HTTP).
        $this->assertFalse($res['success'] ?? true);
        $this->assertSame('RUNTIME_UNAVAILABLE', $res['code'] ?? null);
        $this->assertSame(0, DB::table('media')->where('workspace_id', $wsId)->where('source', 'studio-ai')->count());

        $this->assertCreditBalance(5000);
        $this->assertContains('release', $this->types());
        $this->assertNotContains('commit', $this->types());
    }
}
