<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * WAVE 0-1 · LEGACY image-drift characterization (migration-safety evidence).
 *
 * These tests PIN the CURRENT, constitutionally-INCORRECT image behavior so a
 * Restoration wave can detect exactly when it changes. They are named LEGACY and
 * do NOT endorse the drift as correct architecture. Pure source characterization
 * — no DB, no provider, no credits.
 */
class LegacyImageDriftCharacterizationTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(base_path($rel));
    }

    /** #1 Studio manual image route currently calls Core ImageIntelligence, ungated. */
    public function test_LEGACY_studio_generate_image_route_calls_imageintelligence_ungated(): void
    {
        $src = $this->src('routes/api/authenticated/studio-02.php');
        $routePos = strpos($src, "'/ai/generate-image'");
        $this->assertNotFalse($routePos, 'generate-image route must exist');
        // The ImageIntelligence call sits inside this route body (after idempotency logic).
        $iiPos = strpos($src, 'ImageIntelligenceService', $routePos);
        $this->assertNotFalse($iiPos, 'LEGACY: route calls ImageIntelligence directly (bypasses Creative888).');
        $this->assertLessThan(5000, $iiPos - $routePos, 'II call is within the generate-image route body.');
        // Ungated: no image_intelligence flag guards the call between route def and II call.
        $between = substr($src, $routePos, $iiPos - $routePos);
        $this->assertStringNotContainsString('image_intelligence_enabled', $between,
            'LEGACY: the route is ungated — no flag guards the ImageIntelligence call.');
    }

    /** #2 CreativeService::generateImage delegates reasoning to ImageIntelligence. */
    public function test_LEGACY_creative_generateImage_delegates_to_imageintelligence(): void
    {
        $src = $this->src('app/Engines/Creative/Services/CreativeService.php');
        $fnPos = strpos($src, 'function generateImage');
        $this->assertNotFalse($fnPos);
        $iiPos = strpos($src, 'ImageIntelligenceService', $fnPos);
        $planPos = strpos($src, '->plan(', $fnPos);
        $this->assertNotFalse($iiPos, 'LEGACY: Creative888 delegated its image reasoning to ImageIntelligence.');
        $this->assertNotFalse($planPos);
        $this->assertLessThan(3000, $iiPos - $fnPos, 'II call is within generateImage().');
    }

    /** #6 ImageReasoningService independently prompts an LLM as a creative director (Arthur). */
    public function test_LEGACY_imagereasoning_independently_prompts_llm_as_creative_director(): void
    {
        $src = $this->src('app/Core/ImageIntelligence/ImageReasoningService.php');
        $this->assertStringContainsString('chatJson', $src, 'LEGACY: independent LLM reasoning call.');
        $this->assertMatchesRegularExpression('/You are Arthur\b/i', $src, 'LEGACY: Arthur persona outside Builder.');
        foreach (['audience', 'campaign objective', 'brand', 'typography'] as $creative) {
            $this->assertStringContainsString($creative, $src,
                "LEGACY: reasons about '$creative' — Creative888 territory.");
        }
    }

    /** #6b It does NOT consume Creative888 memory/scoring/blueprint — proven duplicate brain. */
    public function test_LEGACY_imagereasoning_bypasses_creative888_memory_and_scoring(): void
    {
        $src = $this->src('app/Core/ImageIntelligence/ImageReasoningService.php');
        foreach (['Cims', 'ScoreEngine', 'BlueprintService', 'BlueprintInfluence', 'recordGeneration'] as $creative) {
            $this->assertStringNotContainsString($creative, $src,
                "LEGACY: image reasoning bypasses Creative888 '$creative' (duplicate creative brain, not composition).");
        }
    }

    /** #5 The "studio"-named flag is read inside CreativeService — governs cross-engine callers. */
    public function test_LEGACY_studio_named_flag_governs_cross_engine(): void
    {
        $creative = $this->src('app/Engines/Creative/Services/CreativeService.php');
        $this->assertStringContainsString("config('studio.image_intelligence_enabled'", $creative,
            'LEGACY: a "studio"-named flag is read inside CreativeService (not truly Studio-scoped).');
    }
}
