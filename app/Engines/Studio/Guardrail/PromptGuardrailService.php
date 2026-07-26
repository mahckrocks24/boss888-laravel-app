<?php

namespace App\Engines\Studio\Guardrail;

use App\Engines\Studio\Compiler\PromptCompilerResult;

/**
 * STUDIO888 Phase K — Prompt Guardrails (SHADOW MODE).
 *
 * Deterministic, pure, local structural analysis of the compiler output. Same
 * input ⇒ same output. NO LLM, NO network, NO moderation AI, NO provider-policy
 * enforcement, NO randomness / timestamps / UUIDs. It NEVER blocks, modifies,
 * rewrites, repairs, or rejects a prompt and NEVER influences execution — the
 * report is persisted for observation only.
 */
class PromptGuardrailService
{
    public const VERSION = '1.0.0-shadow';

    public const VERDICT_PASS   = 'PASS';
    public const VERDICT_WARN   = 'PASS_WITH_WARNINGS';
    public const VERDICT_REVIEW = 'REVIEW';
    public const VERDICT_FAIL   = 'FAIL';

    private const SEV_RANK   = ['INFO' => 0, 'LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3, 'CRITICAL' => 4];
    private const SEV_WEIGHT = ['INFO' => 0, 'LOW' => 5, 'MEDIUM' => 15, 'HIGH' => 30, 'CRITICAL' => 50];

    private const MAX_PROMPT = 2000;

    public function enabled(): bool
    {
        return (bool) config('studio.prompt_guardrails_shadow', true);
    }

    public function evaluate(PromptCompilerResult $c): GuardrailReport
    {
        $violations = $this->detect($c);

        $policyScore      = $this->scoreExcluding($violations, ['empty_prompt', 'prompt_too_long', 'unknown_capability', 'unsupported_combination', 'too_many_reference_images']);
        $consistencyScore = $this->scoreExcluding($violations, ['conflicting_aspect_ratio', 'conflicting_styles', 'conflicting_duration', 'video_image_conflict', 'duplicate_tokens']);
        $capabilityFit    = $this->scoreExcluding($violations, ['unknown_capability', 'unsupported_combination', 'video_image_conflict']);

        $words        = (int) ($c->metadata['word_count'] ?? 0);
        $completeness = min(100, (int) round(($words / 12) * 100));
        $confidence   = (int) round($c->confidence * 100);
        $refQuality   = $this->referenceQuality((string) $c->spec->capability, (int) ($c->metadata['reference_count'] ?? 0));

        $qualityScore = max(0, min(100, (int) round(0.6 * $completeness + 0.4 * $confidence)
            - $this->penalty($violations, ['prompt_too_short', 'prompt_too_long', 'missing_subject', 'duplicate_tokens'])));

        $overall = (int) round(
            0.30 * $completeness
            + 0.20 * $consistencyScore
            + 0.20 * $capabilityFit
            + 0.10 * $refQuality
            + 0.20 * $confidence
        );
        $overall = max(0, min(100, $overall));

        $severity = $this->maxSeverity($violations);
        $verdict  = $this->verdict($violations, $overall, $severity);

        return new GuardrailReport(
            $verdict,
            $overall,
            $policyScore,
            $qualityScore,
            $consistencyScore,
            $severity,
            $violations,
            $this->recommendations($violations, $c),
            self::VERSION,
            [
                'components' => [
                    'completeness'     => $completeness,
                    'capability_fit'   => $capabilityFit,
                    'reference_quality'=> $refQuality,
                    'compiler_confidence' => $confidence,
                ],
                'violation_count' => count($violations),
            ],
        );
    }

    /** @return array<int,Violation> */
    private function detect(PromptCompilerResult $c): array
    {
        $v = [];
        $prompt = $c->compiledPrompt;
        $trimmed = trim($prompt);
        $len = mb_strlen($prompt);
        $words = (int) ($c->metadata['word_count'] ?? 0);
        $refs = (int) ($c->metadata['reference_count'] ?? 0);
        $cap = (string) $c->spec->capability;

        if ($trimmed === '') {
            $v[] = new Violation('empty_prompt', 'CRITICAL', 'Prompt is empty.');
        } elseif ($len < 3) {
            $v[] = new Violation('prompt_too_short', 'MEDIUM', 'Prompt is extremely short.');
        } elseif ($words < 3) {
            $v[] = new Violation('prompt_too_short', 'LOW', 'Prompt has very few words.');
        }

        if ($len > self::MAX_PROMPT) {
            $v[] = new Violation('prompt_too_long', 'MEDIUM', 'Prompt exceeds the recommended length.');
        }

        if ($trimmed !== '' && $words > 0 && $words <= 2) {
            $v[] = new Violation('missing_subject', 'LOW', 'Prompt may lack a clear primary subject.');
        }

        if ($this->hasDuplicateTokens($prompt)) {
            $v[] = new Violation('duplicate_tokens', 'LOW', 'Prompt repeats the same token several times.');
        }

        if (in_array('multiple_conflicting_aspect_ratios', $c->warnings, true)) {
            $v[] = new Violation('conflicting_aspect_ratio', 'MEDIUM', 'More than one aspect ratio is specified.');
        }

        if ($this->conflictingStyles($prompt) >= 2) {
            $v[] = new Violation('conflicting_styles', 'MEDIUM', 'Multiple conflicting styles are specified.');
        }

        if (in_array('unknown_capability', $c->warnings, true)) {
            $v[] = new Violation('unknown_capability', 'MEDIUM', 'The capability could not be determined.');
        }

        if ($refs > 4) {
            $v[] = new Violation('too_many_reference_images', 'MEDIUM', 'More reference images than supported.');
        }

        if (in_array($cap, ['edit', 'variation'], true) && $refs === 0) {
            $v[] = new Violation('unsupported_combination', 'HIGH', 'Edit/variation requested without a reference image.');
        }

        if ($cap === 'video' && $c->spec->video_duration === null) {
            $v[] = new Violation('conflicting_duration', 'LOW', 'Video duration is missing.');
        }

        if ($cap === 'video' && preg_match('/\b(static image|still image|photo only|single frame)\b/i', $prompt)) {
            $v[] = new Violation('video_image_conflict', 'MEDIUM', 'Video capability paired with static-image wording.');
        }

        return $v;
    }

    private function hasDuplicateTokens(string $prompt): bool
    {
        $tokens = preg_split('/\s+/', mb_strtolower(trim($prompt))) ?: [];
        $tokens = array_filter($tokens, fn ($t) => mb_strlen($t) > 3);
        if (empty($tokens)) {
            return false;
        }
        $counts = array_count_values($tokens);
        foreach ($counts as $n) {
            if ($n >= 3) {
                return true;
            }
        }
        return false;
    }

    private function conflictingStyles(string $prompt): int
    {
        $lower = mb_strtolower($prompt);
        $styles = ['cinematic', 'minimal', 'bold', 'elegant', 'editorial', 'photorealistic', 'watercolor', 'anime', 'cartoon', 'realistic'];
        $found = 0;
        foreach ($styles as $s) {
            if (str_contains($lower, $s)) {
                $found++;
            }
        }
        return $found;
    }

    private function referenceQuality(string $capability, int $refs): int
    {
        if (in_array($capability, ['edit', 'variation'], true)) {
            return $refs > 0 ? 100 : 40;
        }
        return match (true) {
            $refs === 0 => 100,
            $refs <= 2  => 90,
            $refs <= 4  => 70,
            default     => 40,
        };
    }

    /** @param array<int,Violation> $violations */
    private function penalty(array $violations, array $types): int
    {
        $sum = 0;
        foreach ($violations as $v) {
            if (in_array($v->type, $types, true)) {
                $sum += self::SEV_WEIGHT[$v->severity] ?? 0;
            }
        }
        return $sum;
    }

    /** 100 minus the weighted penalty of the given violation types. */
    private function scoreExcluding(array $violations, array $types): int
    {
        return max(0, min(100, 100 - $this->penalty($violations, $types)));
    }

    /** @param array<int,Violation> $violations */
    private function maxSeverity(array $violations): string
    {
        $max = 'INFO';
        foreach ($violations as $v) {
            if ((self::SEV_RANK[$v->severity] ?? 0) > (self::SEV_RANK[$max] ?? 0)) {
                $max = $v->severity;
            }
        }
        return $max;
    }

    /** @param array<int,Violation> $violations */
    private function verdict(array $violations, int $overall, string $severity): string
    {
        if ($severity === 'CRITICAL') {
            return self::VERDICT_FAIL;
        }
        if ($severity === 'HIGH') {
            return self::VERDICT_REVIEW;
        }
        if ($overall < 50) {
            return self::VERDICT_REVIEW;
        }
        if (! empty($violations)) {
            return self::VERDICT_WARN;
        }
        return self::VERDICT_PASS;
    }

    /**
     * @param array<int,Violation> $violations
     * @return array<int,string>
     */
    private function recommendations(array $violations, PromptCompilerResult $c): array
    {
        $map = [
            'empty_prompt'              => 'Provide a prompt describing what to generate.',
            'prompt_too_short'          => 'Add more detail — describe the subject, setting, and style.',
            'prompt_too_long'           => 'Prompt exceeds the recommended length; trim to the essentials.',
            'missing_subject'           => 'Specify a primary subject.',
            'duplicate_tokens'          => 'Remove repeated words to sharpen the prompt.',
            'conflicting_aspect_ratio'  => 'Aspect ratio appears more than once; keep a single one.',
            'conflicting_styles'        => 'Multiple styles conflict; choose one primary style.',
            'unknown_capability'        => 'Capability is ambiguous; confirm image/video/edit.',
            'too_many_reference_images' => 'Reduce the number of reference images.',
            'unsupported_combination'   => 'Provide a reference image for an edit/variation.',
            'conflicting_duration'      => 'Specify a video duration.',
            'video_image_conflict'      => 'Wording implies a static image for a video request.',
        ];

        $recs = [];
        foreach ($violations as $v) {
            if (isset($map[$v->type])) {
                $recs[$v->type] = $map[$v->type]; // dedupe by type, deterministic order
            }
        }
        return array_values($recs);
    }
}
