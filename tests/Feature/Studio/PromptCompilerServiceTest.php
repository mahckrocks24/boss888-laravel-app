<?php

namespace Tests\Feature\Studio;

use App\Engines\Studio\Compiler\PromptCompilerService;
use Tests\TestCase;

/**
 * STUDIO888 Phase J — Prompt Compiler UNIT characterization.
 * Pure, deterministic, local — no DB, no network, no AI.
 */
class PromptCompilerServiceTest extends TestCase
{
    private function svc(): PromptCompilerService
    {
        return new PromptCompilerService();
    }

    /** @test */
    public function same_input_produces_identical_output(): void
    {
        $in = ['prompt' => '  A cinematic 16:9 sunset  ', 'capability' => 'generate_image'];
        $a = $this->svc()->compile($in)->toArray();
        $b = $this->svc()->compile($in)->toArray();
        // Deterministic: byte-identical. A timestamp/uuid/random value would differ here.
        $this->assertSame($a, $b);
    }

    /** @test */
    public function whitespace_normalization_is_classified_as_normalized_only(): void
    {
        $r = $this->svc()->compile(['prompt' => "A   messy\n\n\n\nprompt   ", 'capability' => 'generate_image']);
        $this->assertSame('normalized_only', $r->comparison['result']);
        $this->assertSame("A messy\n\nprompt", $r->compiledPrompt);
    }

    /** @test */
    public function already_clean_prompt_is_identical(): void
    {
        $r = $this->svc()->compile(['prompt' => 'A clean prompt', 'capability' => 'generate_image']);
        $this->assertSame('identical', $r->comparison['result']);
        $this->assertSame('A clean prompt', $r->compiledPrompt);
    }

    /** @test */
    public function empty_prompt_warns_and_lowers_confidence(): void
    {
        $r = $this->svc()->compile(['prompt' => '', 'capability' => 'generate_image']);
        $this->assertContains('prompt_empty', $r->warnings);
        $this->assertLessThan(1.0, $r->confidence);
    }

    /** @test */
    public function overlong_prompt_warns_too_long(): void
    {
        $r = $this->svc()->compile(['prompt' => str_repeat('x', 2100), 'capability' => 'generate_image']);
        $this->assertContains('prompt_too_long', $r->warnings);
    }

    /** @test */
    public function capability_maps_deterministically(): void
    {
        $this->assertSame('image', $this->svc()->compile(['prompt' => 'p', 'capability' => 'generate_image'])->spec->capability);
        $this->assertSame('video', $this->svc()->compile(['prompt' => 'p', 'capability' => 'generate_video'])->spec->capability);
        $this->assertSame('edit', $this->svc()->compile(['prompt' => 'p', 'capability' => 'edit_image'])->spec->capability);

        $unknown = $this->svc()->compile(['prompt' => 'p', 'capability' => 'totally_unknown_action']);
        $this->assertSame('image', $unknown->spec->capability);            // safe default
        $this->assertContains('unknown_capability', $unknown->warnings);
    }

    /** @test */
    public function generation_spec_infers_aspect_ratio_style_and_video_defaults(): void
    {
        $img = $this->svc()->compile(['prompt' => 'a 16:9 cinematic shot', 'capability' => 'generate_image'])->spec;
        $this->assertSame('16:9', $img->aspect_ratio);
        $this->assertSame(1792, $img->width);
        $this->assertSame(1024, $img->height);
        $this->assertSame('cinematic', $img->style);

        $vid = $this->svc()->compile(['prompt' => 'waves', 'capability' => 'generate_video'])->spec;
        $this->assertSame('video', $vid->capability);
        $this->assertSame(10, $vid->video_duration);
        $this->assertSame(24, $vid->fps);
    }

    /** @test */
    public function conflicting_aspect_ratios_are_warned(): void
    {
        $r = $this->svc()->compile(['prompt' => 'make it 16:9 but also 9:16', 'capability' => 'generate_image']);
        $this->assertContains('multiple_conflicting_aspect_ratios', $r->warnings);
        $this->assertSame('16:9', $r->spec->aspect_ratio); // first occurrence — deterministic
    }

    /** @test */
    public function reference_images_populate_image_count(): void
    {
        $r = $this->svc()->compile(['prompt' => 'edit this', 'capability' => 'edit_image', 'reference_images' => ['a.png', 'b.png']]);
        $this->assertSame(2, $r->metadata['image_count']);
        $this->assertTrue($r->metadata['editing_intent']);
        $this->assertSame(['a.png', 'b.png'], $r->spec->reference_images);
    }

    /** @test */
    public function compiler_version_is_stamped(): void
    {
        $this->assertSame('1.0.0-shadow', $this->svc()->compile(['prompt' => 'p', 'capability' => 'generate_image'])->compilerVersion);
        $this->assertSame('1.0.0-shadow', PromptCompilerService::VERSION);
    }
}
