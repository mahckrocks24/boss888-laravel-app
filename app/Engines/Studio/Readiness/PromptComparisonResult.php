<?php

namespace App\Engines\Studio\Readiness;

/**
 * STUDIO888 Phase L — immutable result of a deterministic shadow comparison
 * between the compiled prompt and the observed production provider prompt.
 * Pure data; observational only. `activation_eligible` activates nothing.
 */
class PromptComparisonResult
{
    public function __construct(
        public readonly string $comparisonVersion,
        public readonly string $divergenceClass,
        public readonly bool $byteIdentical,
        public readonly bool $normalizedIdentical,
        public readonly ?string $originalHash,
        public readonly ?string $compiledHash,
        public readonly ?string $providerPromptHash,
        public readonly int $originalLength,
        public readonly int $compiledLength,
        public readonly int $providerPromptLength,
        public readonly int $wordDeltaCompilerVsProvider,
        public readonly array $addedTokens,
        public readonly array $removedTokens,
        public readonly bool $aspectRatioMatch,
        public readonly bool $styleMatch,
        public readonly bool $capabilityMatch,
        public readonly bool $referenceCountMatch,
        public readonly ?string $guardrailVerdict,
        public readonly ?string $guardrailSeverity,
        public readonly bool $wouldPass,
        public readonly bool $wouldReview,
        public readonly bool $wouldFail,
        public readonly int $readinessScore,
        public readonly bool $activationEligible,
        public readonly array $reasons,
        public readonly array $metadata,
    ) {}

    public function toArray(): array
    {
        return [
            'comparison_version'             => $this->comparisonVersion,
            'divergence_class'               => $this->divergenceClass,
            'byte_identical'                 => $this->byteIdentical,
            'normalized_identical'           => $this->normalizedIdentical,
            'original_hash'                  => $this->originalHash,
            'compiled_hash'                  => $this->compiledHash,
            'provider_prompt_hash'           => $this->providerPromptHash,
            'original_length'                => $this->originalLength,
            'compiled_length'                => $this->compiledLength,
            'provider_prompt_length'         => $this->providerPromptLength,
            'word_delta_compiler_vs_provider'=> $this->wordDeltaCompilerVsProvider,
            'added_tokens'                   => $this->addedTokens,
            'removed_tokens'                 => $this->removedTokens,
            'aspect_ratio_match'             => $this->aspectRatioMatch,
            'style_match'                    => $this->styleMatch,
            'capability_match'               => $this->capabilityMatch,
            'reference_count_match'          => $this->referenceCountMatch,
            'guardrail_verdict'              => $this->guardrailVerdict,
            'guardrail_severity'             => $this->guardrailSeverity,
            'would_pass'                     => $this->wouldPass,
            'would_review'                   => $this->wouldReview,
            'would_fail'                     => $this->wouldFail,
            'readiness_score'                => $this->readinessScore,
            'activation_eligible'            => $this->activationEligible,
            'reasons'                        => $this->reasons,
            'metadata'                       => $this->metadata,
        ];
    }
}
