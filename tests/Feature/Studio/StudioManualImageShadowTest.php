<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\Billing\CreditService;
use App\Core\ImageIntelligence\ImageIntelligenceService;
use App\Core\ImageIntelligence\ImageOverlayRenderer;
use App\Core\ImageIntelligence\ImagePromptCompiler;
use App\Core\ImageIntelligence\ImageReasoningService;
use App\Engines\Creative\Services\CreativeService;
use Mockery;
use Tests\TestCase;

/**
 * WAVE 4A — manual Studio image SHADOW migration (default OFF).
 *
 * Proves the shadow control defaults OFF (byte-identical production), the shadow
 * hook in generate() is path-specific + guarded + wrapped, the shadow performs no
 * side effects (no provider/credit/asset/creative_job/persistence), the legacy
 * authoritative path is unchanged, and the plan comparison classifies differences.
 * Pure: no DB, no provider, no credits.
 */
class StudioManualImageShadowTest extends TestCase
{
    private function svc(): ImageIntelligenceService
    {
        // compareImagePlans() uses no dependencies — mocks are never called.
        return new ImageIntelligenceService(
            Mockery::mock(RuntimeClient::class),
            Mockery::mock(ImageReasoningService::class),
            Mockery::mock(ImagePromptCompiler::class),
            Mockery::mock(CreditService::class),
            Mockery::mock(CreativeService::class),
            Mockery::mock(ImageOverlayRenderer::class),
        );
    }

    private function src(): string
    {
        return file_get_contents(base_path('app/Core/ImageIntelligence/ImageIntelligenceService.php'));
    }

    public function test_control_defaults_off(): void
    {
        $this->assertFalse((bool) config('studio_image_migration.manual_shadow_enabled'),
            'the manual-image shadow control must default OFF (byte-identical production).');
    }

    public function test_control_is_not_the_cross_engine_flag(): void
    {
        // must READ its own path-specific env var, and must NOT reuse the
        // mis-scoped cross-engine flag as its source (comment references aside).
        $cfg = file_get_contents(base_path('config/studio_image_migration.php'));
        $this->assertStringContainsString("env('STUDIO_MANUAL_IMAGE_SHADOW', false)", $cfg);
        $this->assertStringNotContainsString("env('STUDIO_IMAGE_INTELLIGENCE_ENABLED'", $cfg);
    }

    public function test_compare_identical_plans_all_equivalent(): void
    {
        $bp = ['audience' => 'chefs', 'subject' => 'taco', 'composition' => 'c', 'lighting' => 'l', 'mood' => 'm', 'brand_application' => 'b', 'negative_constraints' => ['no text']];
        $c  = ['provider_prompt' => 'p', 'typography' => ['mode' => 'none'], 'size' => '1024x1024', 'quality' => 'medium', 'provider' => 'openai', 'model' => 'gpt-image-1'];
        $out = $this->svc()->compareImagePlans($bp, $c, $bp, $c);
        $this->assertSame(0, $out['summary']['differing']);
        foreach ($out['fields'] as $f) {
            $this->assertSame('equivalent', $f['classification']);
        }
    }

    public function test_compare_classifies_differences(): void
    {
        $legacyBp = ['audience' => '', 'composition' => '', 'negative_constraints' => ['no text']];
        $legacyC  = ['provider_prompt' => 'legacy prompt', 'typography' => ['mode' => 'none'], 'size' => '1024x1024', 'quality' => 'medium', 'provider' => 'openai', 'model' => 'gpt-image-1'];
        $shadowBp = ['audience' => 'chefs', 'composition' => 'rule of thirds', 'negative_constraints' => ['no text', 'no watermark']];
        $shadowC  = ['provider_prompt' => 'shadow prompt', 'typography' => ['mode' => 'separate_overlay'], 'size' => '1024x1536', 'quality' => 'high', 'provider' => 'openai', 'model' => 'gpt-image-1'];

        $out = $this->svc()->compareImagePlans($legacyBp, $legacyC, $shadowBp, $shadowC);
        $this->assertSame('architectural', $out['fields']['audience']['classification']);       // brief now carries it
        $this->assertSame('architectural', $out['fields']['composition']['classification']);
        $this->assertSame('equivalent_wording', $out['fields']['provider_prompt']['classification']);
        $this->assertSame('compiler_difference', $out['fields']['negative_constraints']['classification']);
        $this->assertSame('regression_risk', $out['fields']['typography_mode']['classification']); // MUST be reviewed
        $this->assertSame('regression_risk', $out['fields']['dimensions']['classification']);
        $this->assertSame('regression_risk', $out['fields']['requested_quality']['classification']);
        $this->assertTrue($out['fields']['provider']['equal']);
        $this->assertTrue($out['fields']['model']['equal']);
    }

    public function test_compare_missing_field_classification(): void
    {
        $legacyBp = ['mood' => 'warm premium'];
        $c = ['provider_prompt' => 'p', 'typography' => ['mode' => 'none'], 'size' => '1024x1024', 'quality' => 'medium', 'provider' => 'openai', 'model' => 'gpt-image-1'];
        $out = $this->svc()->compareImagePlans($legacyBp, $c, ['mood' => ''], $c);
        $this->assertSame('missing_field', $out['fields']['mood']['classification']);
    }

    public function test_shadow_hook_is_path_specific_and_default_off_guarded(): void
    {
        $src = $this->src();
        $this->assertStringContainsString('if ($this->manualImageShadowEnabled($ctx))', $src);
        $this->assertStringContainsString("config('studio_image_migration.manual_shadow_enabled', false)", $src);
        $this->assertStringContainsString("(\$ctx['source'] ?? '') === 'studio'", $src); // manual route only
    }

    public function test_shadow_hook_is_wrapped_and_cannot_disturb_legacy(): void
    {
        $src = $this->src();
        $block = substr($src, strpos($src, 'if ($this->manualImageShadowEnabled($ctx))'), 220);
        $this->assertStringContainsString('try {', $block);
        $this->assertStringContainsString('catch (\Throwable', $block);
    }

    public function test_shadow_performs_no_side_effects(): void
    {
        $body = substr($this->src(), strpos($this->src(), 'function runManualImageShadow'), 1400);
        foreach (['imageGenerate', '->reserve(', '->commit(', '->debit(', '->release(', 'createAsset', 'completeAsset', 'DB::table'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                "shadow must not '$forbidden' — comparison only (no provider/credit/asset/persistence).");
        }
        $this->assertStringContainsString('getImageBlueprint', $body); // reads Creative888 brief
        $this->assertStringContainsString('compileFromBrief', $body);  // compiles it (Wave 3 path)
    }

    public function test_legacy_authoritative_path_unchanged(): void
    {
        $src = $this->src();
        $this->assertStringContainsString('$this->reasoner->reason($ctx)', $src);   // legacy reasoning still runs
        $this->assertStringContainsString('$this->compiler->compile($blueprint)', $src);
        $this->assertStringContainsString('$this->credits->reserve(', $src);        // one reservation
        $this->assertStringContainsString('$this->runtime->imageGenerate(', $src);  // one provider call
    }
}
