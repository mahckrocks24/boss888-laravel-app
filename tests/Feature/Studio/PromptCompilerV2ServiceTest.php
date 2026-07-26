<?php

namespace Tests\Feature\Studio;

use App\Engines\Studio\Compiler\PromptCompilerV2Service;
use Tests\TestCase;

/**
 * STUDIO888 Phase N — Compiler V2 (production parity) UNIT characterization.
 * Deterministic, local — reproduces the production image enhancement byte-for-byte.
 */
class PromptCompilerV2ServiceTest extends TestCase
{
    private function v2(): PromptCompilerV2Service
    {
        return new PromptCompilerV2Service();
    }

    /** @test */
    public function output_is_deterministic(): void
    {
        $in = ['prompt' => 'a red bicycle', 'capability' => 'generate_image'];
        $this->assertSame($this->v2()->compile($in)->toArray(), $this->v2()->compile($in)->toArray());
    }

    /** @test */
    public function image_prompt_reproduces_the_no_text_rule_byte_exact(): void
    {
        $r = $this->v2()->compile(['prompt' => 'a red bicycle', 'capability' => 'generate_image']);
        $expected = "a red bicycle." . " Strict rule: NO TEXT, NO WORDS, NO LETTERS, NO NUMBERS, "
            . "NO LOGOS, NO WATERMARKS, NO CAPTIONS, NO TYPOGRAPHY of any kind. "
            . "Pure visual composition only \u{2014} no readable characters anywhere "
            . "in the image.";
        $this->assertSame($expected, $r->compiledPrompt);           // byte-for-byte
        $this->assertStringContainsString("\u{2014}", $r->compiledPrompt); // em-dash present
        $this->assertSame('2.0.0-parity', $r->compilerVersion);
    }

    /** @test */
    public function brand_additions_are_reproduced_before_the_no_text_rule(): void
    {
        $r = $this->v2()->compile([
            'prompt' => 'a lake', 'capability' => 'generate_image',
            'brand' => ['visual_style' => 'moody film grain', 'tone' => 'playful', 'colors' => ['#fff', '#000', '#f00']],
        ]);
        // getImageBlueprint order: visual_style, "Style: {tone}", "Color palette: c1, c2"
        $this->assertStringContainsString('a lake. moody film grain. Style: playful. Color palette: #fff, #000.', $r->compiledPrompt);
        $this->assertStringContainsString('NO TYPOGRAPHY', $r->compiledPrompt); // no-text still appended
    }

    /** @test */
    public function professional_tone_and_empty_brand_add_nothing(): void
    {
        $r = $this->v2()->compile(['prompt' => 'a lake', 'capability' => 'generate_image', 'brand' => ['tone' => 'professional']]);
        $this->assertStringStartsWith('a lake.', $r->compiledPrompt);           // no "Style: professional"
        $this->assertStringNotContainsString('Style: professional', $r->compiledPrompt);
    }

    /** @test */
    public function existing_no_text_instruction_is_not_duplicated(): void
    {
        $r = $this->v2()->compile(['prompt' => 'a sign with no text please', 'capability' => 'generate_image']);
        $this->assertSame('a sign with no text please', $r->compiledPrompt); // rule NOT appended
    }

    /** @test */
    public function non_image_capability_is_left_as_raw_prompt(): void
    {
        $r = $this->v2()->compile(['prompt' => 'ocean waves', 'capability' => 'generate_video']);
        $this->assertSame('ocean waves', $r->compiledPrompt);   // no proven video enhancement to reproduce
    }
}
