<?php

namespace Tests\Feature\Studio;

use App\Engines\Studio\Readiness\PromptComparisonService;
use App\Engines\Studio\Readiness\PromptSanitizer;
use Tests\TestCase;

/**
 * STUDIO888 Phase L — deterministic prompt-comparison UNIT characterization.
 * Pure, local — no DB, no network, no AI.
 */
class PromptComparisonServiceTest extends TestCase
{
    private function svc(): PromptComparisonService
    {
        return new PromptComparisonService();
    }

    private function cmp(?string $compiled, ?string $observed, array $g = ['verdict' => 'PASS', 'severity' => 'INFO'], float $conf = 1.0)
    {
        return $this->svc()->compare([
            'original_prompt' => 'orig', 'compiled_prompt' => $compiled, 'actual_provider_prompt' => $observed,
            'generation_spec' => [], 'compiler' => ['confidence' => $conf], 'guardrail' => $g, 'capability' => 'image',
        ]);
    }

    /** @test */ public function identical_prompts_classify_identical(): void
    {
        $r = $this->cmp('a red bicycle', 'a red bicycle');
        $this->assertSame('IDENTICAL', $r->divergenceClass);
        $this->assertTrue($r->byteIdentical);
        $this->assertSame(100, $r->readinessScore);
    }

    /** @test */ public function whitespace_only_classifies_normalization_only(): void
    {
        $r = $this->cmp('a red bicycle', "a   red\n\nbicycle");
        $this->assertSame('NORMALIZATION_ONLY', $r->divergenceClass);
        $this->assertTrue($r->normalizedIdentical);
        $this->assertFalse($r->byteIdentical);
    }

    /** @test */ public function production_only_enhancement_classifies_production_enhanced(): void
    {
        $r = $this->cmp('a red bicycle', 'a red bicycle with cinematic lighting and no text');
        $this->assertSame('PRODUCTION_ENHANCED', $r->divergenceClass);
        $this->assertNotEmpty($r->addedTokens);
        $this->assertEmpty($r->removedTokens);
    }

    /** @test */ public function compiler_only_enhancement_classifies_compiler_enhanced(): void
    {
        $r = $this->cmp('a red bicycle with cinematic lighting', 'a red bicycle');
        $this->assertSame('COMPILER_ENHANCED', $r->divergenceClass);
        $this->assertEmpty($r->addedTokens);
        $this->assertNotEmpty($r->removedTokens);
    }

    /** @test */ public function both_diverged_classifies_both_diverged(): void
    {
        $r = $this->cmp('a blue bicycle extra', 'a red bicycle other');
        $this->assertSame('BOTH_DIVERGED', $r->divergenceClass);
    }

    /** @test */ public function conflicting_aspect_ratio_classifies_conflicting(): void
    {
        $r = $this->cmp('scene 16:9', 'scene 9:16');
        $this->assertSame('CONFLICTING_INSTRUCTIONS', $r->divergenceClass);
        $this->assertFalse($r->aspectRatioMatch);
    }

    /** @test */ public function missing_compiled_prompt_is_handled(): void
    {
        $r = $this->cmp(null, 'anything');
        $this->assertSame('MISSING_COMPILED_PROMPT', $r->divergenceClass);
        $this->assertSame(0, $r->readinessScore);
    }

    /** @test */ public function missing_provider_observation_is_handled(): void
    {
        $r = $this->cmp('anything', null);
        $this->assertSame('MISSING_PROVIDER_OBSERVATION', $r->divergenceClass);
        $this->assertSame(0, $r->readinessScore);
    }

    /** @test */ public function comparison_and_scores_are_deterministic(): void
    {
        $a = $this->cmp('a red bicycle', 'a red bicycle with cinematic lighting')->toArray();
        $b = $this->cmp('a red bicycle', 'a red bicycle with cinematic lighting')->toArray();
        $this->assertSame($a, $b);
    }

    /** @test */ public function readiness_score_within_range_and_hashes_stable(): void
    {
        $r1 = $this->cmp('a red bicycle', 'a red bicycle. cinematic');
        $r2 = $this->cmp('a red bicycle', 'a red bicycle. cinematic');
        $this->assertGreaterThanOrEqual(0, $r1->readinessScore);
        $this->assertLessThanOrEqual(100, $r1->readinessScore);
        $this->assertSame($r1->compiledHash, $r2->compiledHash);        // stable
        $this->assertSame(64, strlen($r1->providerPromptHash));         // sha256 hex
    }

    /** @test */ public function guardrail_verdict_drives_would_tallies(): void
    {
        $fail = $this->cmp('x', 'x', ['verdict' => 'FAIL', 'severity' => 'CRITICAL']);
        $this->assertTrue($fail->wouldFail);
        $this->assertFalse($fail->wouldPass);

        $review = $this->cmp('x', 'y', ['verdict' => 'REVIEW', 'severity' => 'HIGH']);
        $this->assertTrue($review->wouldReview);
    }

    /** @test */ public function activation_eligible_only_for_agreeing_non_failing_jobs(): void
    {
        $this->assertTrue($this->cmp('a red bicycle', 'a red bicycle')->activationEligible);
        $this->assertFalse($this->cmp('a red bicycle', 'a red bicycle enhanced heavily by production')->activationEligible);
        // eligible never true when guardrail FAILs, even if identical
        $this->assertFalse($this->cmp('a red bicycle', 'a red bicycle', ['verdict' => 'FAIL', 'severity' => 'CRITICAL'])->activationEligible);
    }

    /** @test */ public function sanitizer_redacts_secrets_and_leaves_clean_text(): void
    {
        $dirty = PromptSanitizer::sanitize('use https://cdn/x.png?signature=SECRET and Bearer aa.bb.cc and sk-ABCDEFGHIJKLMNOP');
        $this->assertTrue($dirty['redacted']);
        $this->assertStringNotContainsString('SECRET', $dirty['text']);
        $this->assertStringNotContainsString('sk-ABCDEFGHIJKLMNOP', $dirty['text']);

        $clean = PromptSanitizer::sanitize('a red bicycle beside a blue door');
        $this->assertFalse($clean['redacted']);
        $this->assertSame('a red bicycle beside a blue door', $clean['text']);
    }
}
