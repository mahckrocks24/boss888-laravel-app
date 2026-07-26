<?php

namespace App\Engines\Studio\Readiness;

/**
 * STUDIO888 Phase L — deterministic shadow comparison of the compiled prompt vs
 * the observed production provider prompt. Pure, local, side-effect free. NO AI,
 * NO embeddings, NO network. "Semantic" here means deterministic token/structure
 * comparison only. Never influences execution.
 */
class PromptComparisonService
{
    public const VERSION = '1.0.0-shadow';

    // Divergence classes (stable).
    public const IDENTICAL              = 'IDENTICAL';
    public const NORMALIZATION_ONLY     = 'NORMALIZATION_ONLY';
    public const PRODUCTION_ENHANCED    = 'PRODUCTION_ENHANCED';
    public const COMPILER_ENHANCED      = 'COMPILER_ENHANCED';
    public const BOTH_DIVERGED          = 'BOTH_DIVERGED';
    public const CONFLICTING            = 'CONFLICTING_INSTRUCTIONS';
    public const MISSING_COMPILED       = 'MISSING_COMPILED_PROMPT';
    public const MISSING_OBSERVATION    = 'MISSING_PROVIDER_OBSERVATION';
    public const UNCOMPARABLE           = 'UNCOMPARABLE';

    // Readiness scoring weights (documented; sum = 1.0).
    private const W_NORMALIZED = 0.30;
    private const W_STRUCTURAL = 0.20;
    private const W_GUARDRAIL  = 0.20;
    private const W_CONFIDENCE = 0.15;
    private const W_CONFLICT   = 0.15;

    public function enabled(): bool
    {
        return (bool) config('studio.compiler_readiness_shadow', true);
    }

    /**
     * @param array{original_prompt?:?string,compiled_prompt?:?string,actual_provider_prompt?:?string,
     *              generation_spec?:array,compiler?:array,guardrail?:array,capability?:?string,
     *              provider?:?string,model?:?string} $in
     */
    public function compare(array $in): PromptComparisonResult
    {
        $original = $in['original_prompt'] ?? null;
        $compiled = $in['compiled_prompt'] ?? null;
        $observed = $in['actual_provider_prompt'] ?? null;
        $spec     = is_array($in['generation_spec'] ?? null) ? $in['generation_spec'] : [];
        $compiler = is_array($in['compiler'] ?? null) ? $in['compiler'] : [];
        $guardrail= is_array($in['guardrail'] ?? null) ? $in['guardrail'] : [];

        $reasons = [];

        $byteIdentical = is_string($compiled) && is_string($observed) && $compiled === $observed;
        $normIdentical = is_string($compiled) && is_string($observed) && $this->norm($compiled) === $this->norm($observed);

        $addedTokens = [];
        $removedTokens = [];
        $aspectMatch = $this->aspectMatch($compiled, $observed, $spec);
        $styleMatch  = $this->styleMatch($compiled, $observed);
        $capMatch    = true;   // capability is compiler-derived; production doesn't re-declare it — treated as matched unless observation missing
        $refMatch    = true;

        if ($compiled !== null && $observed !== null) {
            $ct = $this->tokens($compiled);
            $ot = $this->tokens($observed);
            $addedTokens   = array_values(array_diff($ot, $ct)); // production has, compiler doesn't
            $removedTokens = array_values(array_diff($ct, $ot)); // compiler has, production doesn't
        }

        $class = $this->classify($compiled, $observed, $byteIdentical, $normIdentical, $addedTokens, $removedTokens, $aspectMatch, $reasons);

        if ($class === self::MISSING_OBSERVATION) { $capMatch = false; $refMatch = false; }

        $verdict  = $guardrail['verdict'] ?? null;
        $severity = $guardrail['severity'] ?? null;
        $wouldPass   = in_array($verdict, ['PASS', 'PASS_WITH_WARNINGS'], true);
        $wouldReview = $verdict === 'REVIEW';
        $wouldFail   = $verdict === 'FAIL';

        $confidence = (float) ($compiler['confidence'] ?? 0.0);
        $readiness  = $this->readinessScore($class, $normIdentical, $ct ?? [], $ot ?? [], $aspectMatch, $styleMatch, $capMatch, $refMatch, $verdict, $confidence);

        $eligible = $readiness >= 90
            && in_array($class, [self::IDENTICAL, self::NORMALIZATION_ONLY], true)
            && $verdict !== 'FAIL'
            && $class !== self::CONFLICTING;
        if ($eligible) {
            $reasons[] = 'meets_normalized_agreement_and_guardrail_threshold';
        }

        return new PromptComparisonResult(
            self::VERSION,
            $class,
            $byteIdentical,
            $normIdentical,
            is_string($original) ? hash('sha256', $original) : null,
            is_string($compiled) ? hash('sha256', $compiled) : null,
            is_string($observed) ? hash('sha256', $observed) : null,
            (int) mb_strlen((string) $original),
            (int) mb_strlen((string) $compiled),
            (int) mb_strlen((string) $observed),
            $this->wordCount($observed) - $this->wordCount($compiled),
            $addedTokens,
            $removedTokens,
            $aspectMatch,
            $styleMatch,
            $capMatch,
            $refMatch,
            $verdict,
            $severity,
            $wouldPass,
            $wouldReview,
            $wouldFail,
            $readiness,
            $eligible,
            array_values(array_unique($reasons)),
            [
                'added_token_count'   => count($addedTokens),
                'removed_token_count' => count($removedTokens),
                'observation_truncated' => (bool) ($in['observation_truncated'] ?? false),
            ],
        );
    }

    private function classify(?string $compiled, ?string $observed, bool $byte, bool $norm, array $added, array $removed, bool $aspectMatch, array &$reasons): string
    {
        if ($compiled === null || trim((string) $compiled) === '') {
            $reasons[] = 'compiled_prompt_missing';
            return self::MISSING_COMPILED;
        }
        if ($observed === null) {
            $reasons[] = 'provider_observation_missing';
            return self::MISSING_OBSERVATION;
        }
        if (trim($observed) === '') {
            $reasons[] = 'observed_prompt_empty';
            return self::UNCOMPARABLE;
        }
        if ($byte) {
            return self::IDENTICAL;
        }
        if ($norm) {
            $reasons[] = 'whitespace_only_difference';
            return self::NORMALIZATION_ONLY;
        }
        if (! $aspectMatch) {
            $reasons[] = 'conflicting_aspect_ratio';
            return self::CONFLICTING;
        }
        $hasAdded = ! empty($added);
        $hasRemoved = ! empty($removed);
        if ($hasAdded && $hasRemoved) {
            $reasons[] = 'both_sides_diverged';
            return self::BOTH_DIVERGED;
        }
        if ($hasAdded) {
            $reasons[] = 'production_added_tokens';
            return self::PRODUCTION_ENHANCED;
        }
        if ($hasRemoved) {
            $reasons[] = 'compiler_added_tokens';
            return self::COMPILER_ENHANCED;
        }
        return self::UNCOMPARABLE;
    }

    private function readinessScore(string $class, bool $norm, array $ct, array $ot, bool $aspectMatch, bool $styleMatch, bool $capMatch, bool $refMatch, ?string $verdict, float $confidence): int
    {
        if (in_array($class, [self::MISSING_COMPILED, self::MISSING_OBSERVATION, self::UNCOMPARABLE], true)) {
            return 0;
        }

        $normalizedAgreement = $norm ? 100 : (int) round($this->jaccard($ct, $ot) * 100);
        $structural = (int) round((($aspectMatch ? 100 : 0) + ($styleMatch ? 100 : 0) + ($capMatch ? 100 : 0) + ($refMatch ? 100 : 0)) / 4);
        $guardrailScore = match ($verdict) {
            'PASS' => 100, 'PASS_WITH_WARNINGS' => 75, 'REVIEW' => 40, 'FAIL' => 0, default => 50,
        };
        $conflictScore = ($class === self::CONFLICTING) ? 0 : 100;
        $confidenceScore = (int) round(max(0.0, min(1.0, $confidence)) * 100);

        $score = self::W_NORMALIZED * $normalizedAgreement
            + self::W_STRUCTURAL * $structural
            + self::W_GUARDRAIL * $guardrailScore
            + self::W_CONFIDENCE * $confidenceScore
            + self::W_CONFLICT * $conflictScore;

        return max(0, min(100, (int) round($score)));
    }

    private function norm(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', mb_strtolower($s)) ?? $s;
        return trim($s);
    }

    /** @return array<int,string> unique lowercased word tokens (len>2) */
    private function tokens(string $s): array
    {
        $words = preg_split('/[^\p{L}\p{N}:]+/u', mb_strtolower($s)) ?: [];
        $words = array_filter($words, fn ($w) => mb_strlen($w) > 2);
        return array_values(array_unique($words));
    }

    private function wordCount(?string $s): int
    {
        if (! is_string($s) || trim($s) === '') return 0;
        return count(preg_split('/\s+/', trim($s)) ?: []);
    }

    private function jaccard(array $a, array $b): float
    {
        if (empty($a) && empty($b)) return 1.0;
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union === 0 ? 0.0 : $inter / $union;
    }

    private function extractRatio(?string $s): ?string
    {
        if (! is_string($s)) return null;
        foreach (['16:9', '9:16', '4:3', '3:4', '1:1', '3:2', '2:3'] as $r) {
            if (str_contains($s, $r)) return $r;
        }
        return null;
    }

    private function aspectMatch(?string $compiled, ?string $observed, array $spec): bool
    {
        $c = $this->extractRatio($compiled) ?? ($spec['aspect_ratio'] ?? null);
        $o = $this->extractRatio($observed);
        if ($c === null || $o === null) return true; // no explicit ratio on one side ⇒ not a conflict
        return $c === $o;
    }

    private function styleMatch(?string $compiled, ?string $observed): bool
    {
        $styles = ['cinematic', 'minimal', 'bold', 'elegant', 'editorial', 'photorealistic', 'watercolor', 'anime'];
        $cs = $os = null;
        foreach ($styles as $st) {
            if (is_string($compiled) && str_contains(mb_strtolower($compiled), $st)) $cs = $cs ?? $st;
            if (is_string($observed) && str_contains(mb_strtolower($observed), $st)) $os = $os ?? $st;
        }
        if ($cs === null || $os === null) return true;
        return $cs === $os;
    }
}
