<?php

namespace App\Engines\Studio\Compiler;

/**
 * STUDIO888 Phase N — Prompt Compiler V2 (PRODUCTION PARITY, SHADOW MODE).
 *
 * V1 (PromptCompilerService) normalizes only. V2 deterministically REPRODUCES the
 * production image-enhancement pipeline so the shadow compiled_prompt can be
 * compared to the actual provider prompt for byte-parity. It reproduces ONLY the
 * two deterministic, source-proven stages of the image path:
 *
 *   A. BlueprintService::getImageBlueprint brand additions   (byte-exact assembly)
 *   B. CreativeService::generateImage no-text rule           (byte-exact string)
 *
 * The workspace-blueprint-influence step (retriever/influence) is NON-deterministic
 * (depends on stored blueprints) and is intentionally NOT reproduced. Non-image
 * capabilities have no proven deterministic enhancement and are left as the raw
 * prompt. Pure, local, no LLM/network/randomness. NEVER transmitted to a provider;
 * NEVER influences execution.
 */
class PromptCompilerV2Service
{
    public const VERSION = '2.0.0-parity';

    /**
     * Byte-exact copy of CreativeService::generateImage $noTextRule
     * (leading space; \u{2014} == the em-dash "—" between "only" and "no readable").
     */
    private const NO_TEXT_RULE = " Strict rule: NO TEXT, NO WORDS, NO LETTERS, NO NUMBERS, "
        . "NO LOGOS, NO WATERMARKS, NO CAPTIONS, NO TYPOGRAPHY of any kind. "
        . "Pure visual composition only \u{2014} no readable characters anywhere "
        . "in the image.";

    public function enabled(): bool
    {
        return (bool) config('studio.prompt_compiler_v2_shadow', true);
    }

    public function compile(array $input): PromptCompilerResult
    {
        $original = (string) ($input['prompt'] ?? '');

        // Reuse V1 for the deterministic spec / metadata / warnings / confidence.
        $v1 = app(PromptCompilerService::class)->compile($input);
        $capability = (string) $v1->spec->capability;

        $compiled = ($capability === 'image')
            ? $this->reproduceProductionImagePrompt($original, $input)
            : $original; // no proven deterministic production enhancement for non-image → leave raw

        return new PromptCompilerResult(
            $original,
            $compiled,
            $v1->spec,
            $v1->metadata,
            $v1->confidence,
            $v1->warnings,
            self::VERSION,
            $this->selfComparison($original, $compiled),
        );
    }

    /**
     * Reproduce production's image provider prompt byte-for-byte from the raw prompt
     * plus (optional) brand context. Mirrors getImageBlueprint + CreativeService.
     */
    private function reproduceProductionImagePrompt(string $prompt, array $input): string
    {
        // ── Stage A: getImageBlueprint brand additions ─────────────────────────
        $brand = is_array($input['brand'] ?? null) ? $input['brand'] : [];
        $additions = [];
        if (! empty($brand['visual_style'])) {
            $additions[] = $brand['visual_style'];
        }
        if (! empty($brand['tone']) && $brand['tone'] !== 'professional') {
            $additions[] = "Style: {$brand['tone']}";
        }
        if (! empty($brand['colors']) && is_array($brand['colors'])) {
            $additions[] = 'Color palette: ' . implode(', ', array_slice($brand['colors'], 0, 2));
        }
        if (! empty($input['style'])) {
            $additions[] = (string) $input['style'];
        }
        $enhanced = ! empty($additions) ? $prompt . '. ' . implode('. ', $additions) : $prompt;

        // ── Stage B: CreativeService no-text rule ──────────────────────────────
        if (stripos($enhanced, 'no text') === false) {
            $enhanced = rtrim($enhanced, '. ') . '.' . self::NO_TEXT_RULE;
        }

        return $enhanced;
    }

    private function selfComparison(string $original, string $compiled): array
    {
        return [
            'result'          => $original === $compiled ? 'identical' : 'production_parity_applied',
            'changed'         => $original !== $compiled,
            'original_length' => mb_strlen($original),
            'compiled_length' => mb_strlen($compiled),
        ];
    }
}
