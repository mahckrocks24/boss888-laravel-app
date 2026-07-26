<?php

namespace App\Engines\Studio\Guardrail;

/**
 * STUDIO888 Phase K — immutable result of a shadow guardrail evaluation.
 * Pure data; carries no behaviour and never touches execution.
 */
class GuardrailReport
{
    /**
     * @param array<int,Violation> $violations
     * @param array<int,string>    $recommendations
     * @param array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $verdict,          // PASS | PASS_WITH_WARNINGS | REVIEW | FAIL
        public readonly int $overallScore,        // 0..100
        public readonly int $policyScore,
        public readonly int $qualityScore,
        public readonly int $consistencyScore,
        public readonly string $severity,         // INFO|LOW|MEDIUM|HIGH|CRITICAL
        public readonly array $violations,
        public readonly array $recommendations,
        public readonly string $guardrailVersion,
        public readonly array $metadata,
    ) {}

    public function toArray(): array
    {
        return [
            'guardrail_version' => $this->guardrailVersion,
            'verdict'           => $this->verdict,
            'overall_score'     => $this->overallScore,
            'policy_score'      => $this->policyScore,
            'quality_score'     => $this->qualityScore,
            'consistency_score' => $this->consistencyScore,
            'severity'          => $this->severity,
            'violations'        => array_map(fn (Violation $v) => $v->toArray(), $this->violations),
            'recommendations'   => $this->recommendations,
            'metadata'          => $this->metadata,
        ];
    }
}
