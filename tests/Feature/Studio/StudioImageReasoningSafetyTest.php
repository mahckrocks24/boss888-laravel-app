<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\Brand\WorkspaceBrandKitResolver;
use App\Core\ImageIntelligence\ImageIntelligenceService;
use App\Engines\Creative\Services\BlueprintService;
use App\Engines\Studio\Services\StudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * CRP-001 WP2 Phase 2.1A — Studio image-reasoning SAFETY SURFACE (characterization).
 *
 * These tests PIN the current (pre-Phase-2.1) baseline so the migration has a
 * reversible, evidence-backed gate. They follow the codebase's existing
 * "Characterization" convention (see CreativeExecutionCharacterizationTest):
 * they assert what the system does TODAY and document what Phase 2.1 must flip.
 *
 * NO routing migration and NO production behaviour change is performed here.
 * The RuntimeClient is forced to the deterministic fallback (isConfigured=false)
 * so no real LLM call is made and no RUNTIME secret is required.
 */
class StudioImageReasoningSafetyTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** Force the canonical reasoner onto its documented deterministic fallback. */
    private function bindFallbackRuntime(): void
    {
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(false);   // → ImageReasoningService fallback; chatJson never called
        $this->app->instance(RuntimeClient::class, $rt);
    }

    /**
     * PART 1 — the localized reversible gate exists and is DORMANT by default.
     * @test
     */
    public function gate_defaults_off_and_is_dormant(): void
    {
        $this->assertFalse(
            (bool) config('studio.image_intelligence_enabled'),
            'STUDIO_IMAGE_INTELLIGENCE_ENABLED must default OFF (mirrors ai_provenance; dormant until Phase 2.1 wires it).'
        );
    }

    /**
     * PART 2 — structural parity: legacy vs canonical reasoning differ BY DESIGN.
     * Legacy = flat string-concat {enhanced_prompt}; canonical = structured
     * 22-field ImageBlueprint with a typography strategy. Documents the intended
     * difference (not a regression).
     * @test
     */
    public function legacy_and_canonical_reasoning_have_the_expected_structural_difference(): void
    {
        $this->bindFallbackRuntime();
        $wsId = $this->testWorkspace->id;
        $brief = 'A LinkedIn post about Imhotep, the world\'s first architect';

        // Legacy reasoner (Creative888 BlueprintService::getImageBlueprint) — string-concat.
        $legacy = app(BlueprintService::class)->getImageBlueprint($wsId, $brief, []);
        $this->assertArrayHasKey('enhanced_prompt', $legacy);
        $this->assertIsString($legacy['enhanced_prompt']);
        $this->assertArrayNotHasKey('typography_strategy', $legacy, 'Legacy path has no typography strategy (by design).');

        // Canonical reasoner (ImageIntelligence::plan) — structured blueprint.
        $plan = app(ImageIntelligenceService::class)->plan([
            'source' => 'studio', 'platform' => 'linkedin', 'asset_type' => 'social_post',
            'workspace_id' => $wsId, 'user_prompt' => $brief,
        ]);
        $bp = $plan['blueprint'];
        foreach (['intent','subject','platform','dimensions','typography_strategy','provider_prompt','negative_constraints'] as $k) {
            $this->assertArrayHasKey($k, $bp, "Canonical blueprint must carry structured field: {$k}");
        }
        $this->assertArrayHasKey('mode', $bp['typography_strategy']);
        $this->assertContains($bp['typography_strategy']['mode'], ['none','separate_overlay','baked_in']);
        $this->assertArrayHasKey('compiled', $plan);
        $this->assertArrayHasKey('provider_prompt', $plan['compiled']);
    }

    /**
     * PART 3 — BASELINE GAP (D2): ImageIntelligence currently sources brand via
     * StudioService::getBrandKit and does NOT use the canonical
     * WorkspaceBrandKitResolver. This test PINS the bypass. Phase 2.1 (WP3) must
     * invert it: resolver called, getBrandKit not.
     * @test
     */
    public function image_intelligence_currently_bypasses_workspace_brand_resolver_D2_baseline(): void
    {
        $this->bindFallbackRuntime();

        $studio = \Mockery::mock(StudioService::class)->makePartial();
        $studio->shouldReceive('getBrandKit')->atLeast()->once()
               ->andReturn(['primary_color' => '#123456', 'heading_font' => 'Syne', 'body_font' => 'DM Sans']);
        $this->app->instance(StudioService::class, $studio);

        $resolver = \Mockery::spy(WorkspaceBrandKitResolver::class);
        $this->app->instance(WorkspaceBrandKitResolver::class, $resolver);

        app(ImageIntelligenceService::class)->plan([
            'source' => 'studio', 'platform' => 'linkedin', 'asset_type' => 'social_post',
            'workspace_id' => $this->testWorkspace->id, 'user_prompt' => 'brand test',
        ]);

        // CURRENT (non-conformant) behaviour — the D2 bypass:
        $studio->shouldHaveReceived('getBrandKit');
        $resolver->shouldNotHaveReceived('resolve');
        $this->addToAssertionCount(1);
    }

    /**
     * PART 3b — the canonical read authority itself is sound (used by 14 other
     * surfaces). Proves the resolver returns a normalized kit with the
     * white-label neutral flag — the target Phase 2.1 will route image brand to.
     * @test
     */
    public function workspace_brand_resolver_returns_normalized_kit_with_neutral_flag(): void
    {
        $kit = app(WorkspaceBrandKitResolver::class)->resolve($this->testWorkspace->id);
        foreach (['brand_name','primary_color','voice','is_neutral','sources_present'] as $k) {
            $this->assertArrayHasKey($k, $kit, "Resolver must return canonical key: {$k}");
        }
        $this->assertIsBool($kit['is_neutral']);
    }

    /**
     * PART 5 — ROBUSTNESS PROOF (WP2 Phase 2.1B): a reasoning THROW must NOT
     * break image reasoning; it must degrade to the deterministic fallback.
     * This pins the hardening added to ImageReasoningService::reason().
     * @test
     */
    public function reasoning_throw_degrades_to_fallback_and_does_not_propagate(): void
    {
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);          // not the unconfigured short-circuit
        $rt->shouldReceive('chatJson')->andThrow(new \RuntimeException('simulated reasoning outage'));
        $this->app->instance(RuntimeClient::class, $rt);

        // Must NOT throw:
        $plan = app(ImageIntelligenceService::class)->plan([
            'source' => 'studio', 'platform' => 'linkedin', 'asset_type' => 'social_post',
            'workspace_id' => $this->testWorkspace->id, 'user_prompt' => 'robustness test',
        ]);

        $this->assertTrue($plan['reasoning']['fallback'], 'A reasoning throw must degrade to the fallback path.');
        $this->assertArrayHasKey('provider_prompt', $plan['compiled']);
        $this->assertNotSame('', trim((string) $plan['blueprint']['provider_prompt']), 'Fallback must still yield a usable provider prompt.');
        $this->assertArrayHasKey('typography_strategy', $plan['blueprint']);
    }
}
