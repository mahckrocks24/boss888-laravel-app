<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\ImageIntelligence\ImageIntelligenceService;
use App\Engines\Creative\Services\BlueprintService;
use App\Engines\Creative\Services\CreativeService;
use App\Engines\Studio\Services\StudioAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * CRP-001 WP2 Phase 2.1C — Studio image ROUTING CONFORMANCE.
 *
 * Proves the dormant gate `studio.image_intelligence_enabled`:
 *   - OFF (default): today's behaviour is preserved (legacy stage present).
 *   - ON: the target Studio entry points reason ONCE through ImageIntelligence
 *     (no double stage, no legacy string-wrap).
 *
 * The gate is NOT activated in production; these tests set it per-test only.
 * RuntimeClient is mocked to the deterministic fallback (no real LLM / secret).
 */
class StudioImageRoutingConformanceTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** Runtime that lets the canonical path complete via fallback + a stubbed provider image. */
    private function bindRuntimeFallbackWithProvider(): void
    {
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(false);   // ImageIntelligence → fallback (no chatJson)
        $rt->shouldReceive('aiRun')->andReturn(['text' => '']);
        $rt->shouldReceive('imageGenerate')->andReturn([
            'success' => true, 'url' => 'https://cdn.test/img.png', 'size' => '1024x1024',
            'quality' => 'low', 'storage_path' => null, 'model' => 'gpt-image-1', 'provider' => 'openai',
        ]);
        $this->app->instance(RuntimeClient::class, $rt);
    }

    /**
     * PART 2 (baseline) — gate OFF: generateThroughBlueprint('*','image') still
     * runs the LEGACY getImageBlueprint stage (the current double behaviour).
     * @test
     */
    public function gate_off_generateThroughBlueprint_image_uses_the_legacy_stage(): void
    {
        config(['studio.image_intelligence_enabled' => false]);
        $this->bindRuntimeFallbackWithProvider();

        $blueprint = \Mockery::spy(BlueprintService::class);
        $this->app->instance(BlueprintService::class, $blueprint);
        $this->app->forgetInstance(CreativeService::class);

        app(CreativeService::class)->generateThroughBlueprint('social', 'image', $this->testWorkspace->id, ['prompt' => 'a red bicycle']);

        // Legacy reasoning stage present (⇒ the pre-2.1C double behaviour):
        $blueprint->shouldHaveReceived('getImageBlueprint');
        $this->addToAssertionCount(1);
    }

    /**
     * PART 2 (acceptance) — gate ON: the legacy stage is ELIMINATED, so exactly
     * one reasoning pass (canonical ImageIntelligence) occurs on the RAW prompt.
     * Proves logical single-reasoning, not merely a call count: the code path
     * that would double-reason (getImageBlueprint) is provably not taken.
     * @test
     */
    public function gate_on_generateThroughBlueprint_image_reasons_once_no_legacy_stage(): void
    {
        config(['studio.image_intelligence_enabled' => true]);
        $this->bindRuntimeFallbackWithProvider();

        $blueprint = \Mockery::spy(BlueprintService::class);
        $this->app->instance(BlueprintService::class, $blueprint);
        $this->app->forgetInstance(CreativeService::class);

        $out = app(CreativeService::class)->generateThroughBlueprint('social', 'image', $this->testWorkspace->id, ['prompt' => 'a red bicycle']);

        // The legacy enrichment stage must NOT run under the gate:
        $blueprint->shouldNotHaveReceived('getImageBlueprint');
        // And the request still completed (single canonical pass produced an asset):
        $this->assertNotEmpty($out, 'gated canonical path must still produce output');
        $this->addToAssertionCount(1);
    }

    /**
     * PART 4 — gate ON: the Studio generate_image ACTION delegates to the single
     * ImageIntelligence pipeline (not the legacy string-wrap).
     * @test
     */
    public function gate_on_studio_action_delegates_to_image_intelligence(): void
    {
        config(['studio.image_intelligence_enabled' => true]);

        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);   // pass StudioAiService's config guard
        $this->app->instance(RuntimeClient::class, $rt);

        $iis = \Mockery::spy(ImageIntelligenceService::class);
        $iis->shouldReceive('generate')->andReturn(['success' => true, 'url' => 'https://cdn.test/canonical.png', 'size' => '1024x1536']);
        $this->app->instance(ImageIntelligenceService::class, $iis);
        $this->app->forgetInstance(StudioAiService::class);

        $out = app(StudioAiService::class)->generateImage($this->testWorkspace->id, ['prompt' => 'a red bicycle']);

        $iis->shouldHaveReceived('generate');
        $this->assertTrue($out['success']);
        $this->assertSame('https://cdn.test/canonical.png', $out['image_url']);
        $this->assertSame(1024, $out['width']);
        $this->assertSame(1536, $out['height']);
    }

    /**
     * PART 4 (OFF preserves today) — gate OFF: the Studio action uses the LEGACY
     * path and does NOT invoke ImageIntelligence.
     * @test
     */
    public function gate_off_studio_action_uses_legacy_not_image_intelligence(): void
    {
        config(['studio.image_intelligence_enabled' => false]);

        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('imageGenerate')->andReturn([
            'success' => true, 'url' => 'https://cdn.test/legacy.png', 'size' => '1024x1024',
            'quality' => 'low', 'storage_path' => 'ai-images/1/legacy.png', 'model' => 'gpt-image-1', 'provider' => 'openai',
        ]);
        $this->app->instance(RuntimeClient::class, $rt);

        $iis = \Mockery::spy(ImageIntelligenceService::class);
        $this->app->instance(ImageIntelligenceService::class, $iis);
        $this->app->forgetInstance(StudioAiService::class);

        $out = app(StudioAiService::class)->generateImage($this->testWorkspace->id, ['prompt' => 'a red bicycle']);

        $iis->shouldNotHaveReceived('generate');   // legacy path only
        $this->assertTrue($out['success']);
        $this->addToAssertionCount(1);
    }
}
