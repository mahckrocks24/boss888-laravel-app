<?php

namespace Tests\Feature\Studio;

use App\Engines\Studio\Compiler\PromptCompilerService;
use App\Engines\Studio\Guardrail\GuardrailReport;
use App\Engines\Studio\Guardrail\PromptGuardrailService;
use Tests\TestCase;

/**
 * STUDIO888 Phase K — Prompt Guardrail UNIT characterization.
 * Pure, deterministic, local — no DB, no network, no AI, no moderation.
 */
class PromptGuardrailServiceTest extends TestCase
{
    private function eval(string $prompt, string $capability, array $refs = []): GuardrailReport
    {
        $compiled = (new PromptCompilerService())->compile([
            'prompt' => $prompt, 'capability' => $capability, 'reference_images' => $refs,
        ]);
        return (new PromptGuardrailService())->evaluate($compiled);
    }

    /** @test */
    public function a_clean_prompt_passes(): void
    {
        $r = $this->eval('A cinematic 16:9 sunset over Dubai Marina at golden hour', 'generate_image');
        $this->assertSame('PASS', $r->verdict);
        $this->assertSame('INFO', $r->severity);
        $this->assertSame([], $r->violations);
    }

    /** @test */
    public function a_conflicting_prompt_passes_with_warnings(): void
    {
        $r = $this->eval('make a 16:9 and 9:16 sunset over the marina landscape scene', 'generate_image');
        $this->assertSame('PASS_WITH_WARNINGS', $r->verdict);
        $this->assertSame('MEDIUM', $r->severity);
        $this->assertNotEmpty($r->violations);
    }

    /** @test */
    public function an_edit_without_reference_is_review(): void
    {
        $r = $this->eval('edit this', 'edit_image');
        $this->assertSame('REVIEW', $r->verdict);
        $this->assertSame('HIGH', $r->severity);
        $types = array_map(fn ($v) => $v->type, $r->violations);
        $this->assertContains('unsupported_combination', $types);
    }

    /** @test */
    public function an_empty_prompt_fails(): void
    {
        $r = $this->eval('', 'generate_image');
        $this->assertSame('FAIL', $r->verdict);
        $this->assertSame('CRITICAL', $r->severity);
        $this->assertContains('empty_prompt', array_map(fn ($v) => $v->type, $r->violations));
    }

    /** @test */
    public function scores_violations_and_recommendations_are_deterministic(): void
    {
        $a = $this->eval('edit this', 'edit_image')->toArray();
        $b = $this->eval('edit this', 'edit_image')->toArray();
        $this->assertSame($a, $b);   // byte-identical — no randomness/timestamps/uuids
    }

    /** @test */
    public function scores_are_integers_within_range(): void
    {
        $r = $this->eval('a decent prompt of moderate length describing a scene', 'generate_image');
        foreach ([$r->overallScore, $r->policyScore, $r->qualityScore, $r->consistencyScore] as $s) {
            $this->assertIsInt($s);
            $this->assertGreaterThanOrEqual(0, $s);
            $this->assertLessThanOrEqual(100, $s);
        }
    }

    /** @test */
    public function recommendations_are_deterministic_and_deduped(): void
    {
        $r = $this->eval('edit this', 'edit_image');
        $this->assertContains('Provide a reference image for an edit/variation.', $r->recommendations);
        $this->assertSame(count($r->recommendations), count(array_unique($r->recommendations)));
    }

    /** @test */
    public function guardrail_version_is_stamped(): void
    {
        $this->assertSame('1.0.0-shadow', $this->eval('a prompt', 'generate_image')->guardrailVersion);
        $this->assertSame('1.0.0-shadow', PromptGuardrailService::VERSION);
    }
}
