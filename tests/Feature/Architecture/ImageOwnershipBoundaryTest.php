<?php

namespace Tests\Feature\Architecture;

use App\Core\ImageIntelligence\ImagePromptCompiler;
use Tests\TestCase;

/**
 * WAVE 0-1 · Studio EXECUTION boundary + Creative→Studio separation (pure).
 *
 * Proves Studio's ImagePromptCompiler is a technical realization step that
 * consumes a supplied blueprint and produces a provider-ready request WITHOUT
 * any independent creative reasoning, LLM call, provider execution, credits or
 * DB. Also proves the Creative888 brief reads brand/memory/learning.
 * Phase 4 note: the compiler is fed a MOCK rich brief here because the current
 * Creative888 getImageBlueprint does not yet emit compiler-ready fields (the
 * documented Wave-2 gap).
 */
class ImageOwnershipBoundaryTest extends TestCase
{
    private function richBrief(array $overrides = []): array
    {
        return array_merge([
            'provider_prompt'     => 'A gourmet taco on matte ceramic, soft window light',
            'dimensions'          => ['width' => 1024, 'height' => 1536],
            'quality'             => 'medium',
            'provider'            => 'openai',
            'model'               => 'gpt-image-1',
            'color_palette'       => ['#111111'],
            'typography_strategy' => ['mode' => 'none', 'headline' => '', 'supporting_copy' => []],
        ], $overrides);
    }

    public function test_compiler_is_pure_technical_realization(): void
    {
        $out = (new ImagePromptCompiler())->compile($this->richBrief());
        foreach (['provider_prompt', 'size', 'quality', 'provider', 'model', 'typography', 'overlay'] as $k) {
            $this->assertArrayHasKey($k, $out);
        }
        $this->assertSame('1024x1536', $out['size']);   // Studio maps dimensions → provider size
        $this->assertSame('medium', $out['quality']);
        $this->assertSame('openai', $out['provider']);
    }

    public function test_typography_none_forces_text_free_image(): void
    {
        $out = (new ImagePromptCompiler())->compile($this->richBrief(['typography_strategy' => ['mode' => 'none']]));
        $this->assertStringContainsString('NO text', $out['provider_prompt']);
        $this->assertNull($out['overlay']);
    }

    public function test_typography_separate_overlay_hands_copy_to_studio(): void
    {
        $out = (new ImagePromptCompiler())->compile($this->richBrief([
            'typography_strategy' => ['mode' => 'separate_overlay', 'headline' => 'Taco Tuesday', 'supporting_copy' => ['Half price'], 'placement' => 'upper-left'],
        ]));
        $this->assertStringContainsString('NO text', $out['provider_prompt']); // image stays text-free
        $this->assertIsArray($out['overlay']);
        $this->assertSame('Taco Tuesday', $out['overlay']['headline']);         // copy handed to Studio typography engine
    }

    public function test_typography_baked_in_embeds_headline(): void
    {
        $out = (new ImagePromptCompiler())->compile($this->richBrief([
            'typography_strategy' => ['mode' => 'baked_in', 'headline' => '50% OFF'],
        ]));
        $this->assertStringContainsString('50% OFF', $out['provider_prompt']);
        $this->assertNull($out['overlay']);
    }

    public function test_compiler_source_contains_no_creative_reasoning(): void
    {
        $src = file_get_contents(base_path('app/Core/ImageIntelligence/ImagePromptCompiler.php'));
        // technical-only: no independent LLM call, no AI persona, no provider exec
        $this->assertStringNotContainsString('chatJson', $src);
        $this->assertStringNotContainsString('You are Arthur', $src);
        $this->assertStringNotContainsString('imageGenerate', $src);
    }

    public function test_creative_brief_reads_brand_memory_and_learning(): void
    {
        $src = file_get_contents(base_path('app/Engines/Creative/Services/BlueprintService.php'));
        $img = substr($src, strpos($src, 'function getImageBlueprint'), 2500);
        $this->assertStringContainsString('buildBrandContext', $img, 'Creative brief reads brand (CimsService).');
        $this->assertStringContainsString('getRelevant', $img, 'Creative brief reads creative memory (retriever).');
        $this->assertStringContainsString('influence', $img, 'Creative brief applies creative learning (influence).');
    }
}
