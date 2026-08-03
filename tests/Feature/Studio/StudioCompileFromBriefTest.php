<?php

namespace Tests\Feature\Studio;

use App\Connectors\DeepSeekConnector;
use App\Connectors\RuntimeClient;
use App\Core\ImageIntelligence\ImagePromptCompiler;
use App\Engines\Creative\Services\BlueprintInfluenceService;
use App\Engines\Creative\Services\BlueprintRetrieverService;
use App\Engines\Creative\Services\BlueprintService;
use App\Engines\Creative\Services\CimsService;
use Mockery;
use Tests\TestCase;

/**
 * WAVE 3 — Studio compiles DIRECTLY from the Creative888 image brief.
 *
 * Proves Studio's compile stage (ImagePromptCompiler::compileFromBrief) can turn a
 * Creative888 getImageBlueprint brief into a provider-ready request with NO second
 * creative reasoning (no ImageReasoningService / LLM), no provider execution, no
 * credits — pure technical realization. Additive + dormant: the live compile()/
 * generate() paths are unchanged, so external behavior/providers/billing are the same.
 */
class StudioCompileFromBriefTest extends TestCase
{
    /** A representative enriched Creative888 brief (shape of getImageBlueprint output). */
    private function brief(array $o = []): array
    {
        return array_merge([
            'type'                => 'image',
            'enhanced_prompt'     => 'A gourmet taco. editorial. Color palette: #111111, #e5b80b',
            'provider_prompt'     => 'A gourmet taco. editorial. Color palette: #111111, #e5b80b',
            'composition'         => 'hero subject slightly off-centre on the rule of thirds',
            'lighting'            => 'soft directional light with gentle falloff',
            'mood'                => 'warm, premium, appetising',
            'brand_application'   => 'Apply the brand palette (#111111, #e5b80b) through composition and lighting — never as drawn text.',
            'color_palette'       => ['#111111', '#e5b80b'],
            'negative_constraints'=> ['no text', 'no watermark', 'no logos'],
            'quality'             => 'high',
            'dimensions'          => ['width' => 1024, 'height' => 1536],
            'typography_strategy' => ['mode' => 'none', 'headline' => '', 'supporting_copy' => []],
        ], $o);
    }

    public function test_compiles_provider_ready_request_from_brief(): void
    {
        $out = (new ImagePromptCompiler())->compileFromBrief($this->brief());
        foreach (['provider_prompt', 'size', 'quality', 'provider', 'model', 'typography', 'overlay', 'negative_constraints', 'from_brief'] as $k) {
            $this->assertArrayHasKey($k, $out, "compiled request must expose '$k'");
        }
        $this->assertTrue($out['from_brief']);
        $this->assertSame('1024x1536', $out['size']);   // dimensions → provider size (technical)
        $this->assertSame('high', $out['quality']);      // quality carried from the brief
        $this->assertSame('openai', $out['provider']);
    }

    public function test_consumes_brief_creative_direction_fields(): void
    {
        $out = (new ImagePromptCompiler())->compileFromBrief($this->brief());
        // The single provider prompt is assembled from the brief's already-reasoned fields.
        $this->assertStringContainsString('A gourmet taco', $out['provider_prompt']);          // provider_prompt
        $this->assertStringContainsString('Composition: hero subject', $out['provider_prompt']); // composition
        $this->assertStringContainsString('Lighting: soft directional', $out['provider_prompt']);// lighting
        $this->assertStringContainsString('Mood: warm, premium', $out['provider_prompt']);       // mood
        $this->assertStringContainsString('Apply the brand palette', $out['provider_prompt']);   // brand application
        $this->assertSame(['no text', 'no watermark', 'no logos'], $out['negative_constraints']);// negative constraints
    }

    public function test_typography_none_keeps_image_text_free(): void
    {
        $out = (new ImagePromptCompiler())->compileFromBrief($this->brief());
        $this->assertStringContainsString('NO text', $out['provider_prompt']); // compile()'s deterministic policy applied
        $this->assertNull($out['overlay']);
    }

    public function test_typography_separate_overlay_hands_copy_to_studio(): void
    {
        $out = (new ImagePromptCompiler())->compileFromBrief($this->brief([
            'typography_strategy' => ['mode' => 'separate_overlay', 'headline' => 'Taco Tuesday', 'supporting_copy' => ['Half price'], 'placement' => 'upper-left'],
        ]));
        $this->assertStringContainsString('NO text', $out['provider_prompt']); // image stays text-free
        $this->assertIsArray($out['overlay']);
        $this->assertSame('Taco Tuesday', $out['overlay']['headline']);         // copy to the Studio typography layer
    }

    public function test_compileFromBrief_performs_no_reasoning_no_provider_no_billing(): void
    {
        // Source-level guard: the compile-from-brief path is pure technical realization.
        $src = file_get_contents(base_path('app/Core/ImageIntelligence/ImagePromptCompiler.php'));
        $body = substr($src, strpos($src, 'function compileFromBrief'), 1600);
        foreach (['chatJson', 'imageGenerate', 'runtime', 'reason(', 'ImageReasoning', '->reserve(', '->debit(', '->commit('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                "compileFromBrief must not '$forbidden' — technical compilation only.");
        }
    }

    public function test_live_compile_signature_unchanged(): void
    {
        // The pre-existing compile() path (used by the live generate()) is untouched.
        $out = (new ImagePromptCompiler())->compile([
            'provider_prompt' => 'x', 'dimensions' => ['width' => 1024, 'height' => 1024],
            'quality' => 'medium', 'typography_strategy' => ['mode' => 'none'],
        ]);
        $this->assertArrayHasKey('size', $out);
        $this->assertArrayNotHasKey('from_brief', $out); // live compile() unchanged (no brief marker)
    }

    public function test_end_to_end_creative888_brief_to_studio_compile(): void
    {
        // Full migrated path: Creative888 getImageBlueprint (brand/memory/learning via
        // its own services, all mocked — no DB/provider) → Studio compileFromBrief →
        // provider-ready request. NO second reasoning anywhere.
        $cims = Mockery::mock(CimsService::class);
        $cims->shouldReceive('buildBrandContext')->andReturn('BRAND');
        $cims->shouldReceive('getBrandIdentity')->andReturn(['visual_style' => 'editorial', 'tone' => 'bold', 'colors' => ['#111111'], 'target_audience' => 'chefs']);
        $retr = Mockery::mock(BlueprintRetrieverService::class);
        $retr->shouldReceive('getRelevant')->andReturn([]);
        $infl = Mockery::mock(BlueprintInfluenceService::class);
        $infl->shouldReceive('apply')->andReturn([]);
        $llm = Mockery::mock(DeepSeekConnector::class);
        $rt  = Mockery::mock(RuntimeClient::class);
        $rt->shouldNotReceive('imageGenerate');
        $rt->shouldNotReceive('chatJson');

        $blueprint = new BlueprintService($llm, $cims, $retr, $infl, $rt);
        $brief = $blueprint->getImageBlueprint(2, 'a gourmet taco', ['asset_type' => 'poster', 'platform' => 'instagram', 'requested_quality' => 'high']);

        $compiled = (new ImagePromptCompiler())->compileFromBrief($brief);

        $this->assertTrue($compiled['from_brief']);
        $this->assertSame('high', $compiled['quality']);                              // Creative888 quality → Studio
        $this->assertContains($compiled['size'], ['1024x1024', '1024x1536', '1536x1024']);
        $this->assertStringContainsString('a gourmet taco', $compiled['provider_prompt']); // Creative888 prompt → Studio
        $this->assertStringContainsString('Composition:', $compiled['provider_prompt']);   // Creative888 direction consumed
    }
}
