<?php

namespace App\Engines\Studio\Compiler;

/**
 * STUDIO888 Phase J — Prompt Compiler (SHADOW MODE).
 *
 * Deterministic, pure, local. Same input ⇒ same output. NO LLM, NO network, NO
 * provider calls, NO randomness / timestamps / UUIDs. It NEVER influences
 * execution — the compiled prompt is persisted for observation only; the provider
 * continues to receive the exact original prompt production sends today.
 */
class PromptCompilerService
{
    public const VERSION = '1.0.0-shadow';

    private const MAX_PROMPT = 2000;
    private const MIN_PROMPT = 3;

    public function enabled(): bool
    {
        return (bool) config('studio.prompt_compiler_shadow', true);
    }

    /**
     * @param array{prompt?:string,capability?:string,provider?:?string,model?:?string,reference_images?:array,workspace_id?:int} $input
     */
    public function compile(array $input): PromptCompilerResult
    {
        $original = (string) ($input['prompt'] ?? '');
        $warnings = [];

        // ── Stage 1: normalize (whitespace + Unicode NFC + trim) ──
        $normalized = $this->normalize($original);

        // ── Stage 2: determine capability ──
        $capability = $this->resolveCapability((string) ($input['capability'] ?? ''), $warnings);

        // ── Stage 3: extract compiler metadata ──
        $meta = $this->extractMetadata($normalized, $input);

        // prompt-length warnings (deterministic)
        if ($normalized === '') {
            $warnings[] = 'prompt_empty';
        } elseif (mb_strlen($normalized) < self::MIN_PROMPT) {
            $warnings[] = 'prompt_short';
        }
        if (mb_strlen($normalized) > self::MAX_PROMPT) {
            $warnings[] = 'prompt_too_long';
        }

        // ── Stage 4: generation spec (deterministic inference only) ──
        $spec = $this->buildSpec($capability, $normalized, $input, $warnings);

        // ── Stage 5: compile prompt (deterministic; v1 = normalized form) ──
        $compiled = $normalized;

        // shadow comparison + confidence (both deterministic)
        $comparison = $this->compare($original, $normalized, $compiled);
        $confidence = max(0.0, min(1.0, round(1.0 - 0.15 * count($warnings), 4)));

        return new PromptCompilerResult(
            $original,
            $compiled,
            $spec,
            $meta,
            $confidence,
            array_values(array_unique($warnings)),
            self::VERSION,
            $comparison,
        );
    }

    private function normalize(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($n)) {
                $s = $n;
            }
        }
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[ \t]+/', ' ', $s) ?? $s;      // collapse spaces/tabs
        $s = preg_replace('/ *\n */', "\n", $s) ?? $s;      // trim around newlines
        $s = preg_replace('/\n{3,}/', "\n\n", $s) ?? $s;    // cap blank lines
        return trim($s);
    }

    private function resolveCapability(string $raw, array &$warnings): string
    {
        $map = [
            'generate_image' => 'image', 'generate_image_mini' => 'image', 'generate_image_high' => 'image',
            'generate_design' => 'image', 'social_image' => 'image', 'builder_page_image' => 'image',
            'generate_video' => 'video',
            'edit_image' => 'edit', 'variation' => 'variation', 'upscale_image' => 'upscale',
            'create_canvas' => 'canvas',
        ];
        $c = strtolower(trim($raw));
        if (isset($map[$c])) {
            return $map[$c];
        }
        if (str_contains($c, 'video')) return 'video';
        if (str_contains($c, 'upscale')) return 'upscale';
        if (str_contains($c, 'variation')) return 'variation';
        if (str_contains($c, 'canvas')) return 'canvas';
        if (str_contains($c, 'edit')) return 'edit';
        if (str_contains($c, 'image') || str_contains($c, 'design')) return 'image';

        $warnings[] = 'unknown_capability';
        return 'image'; // safe deterministic default
    }

    private function extractMetadata(string $prompt, array $input): array
    {
        $refs = is_array($input['reference_images'] ?? null) ? $input['reference_images'] : [];
        $lower = mb_strtolower($prompt);
        $editing = (bool) preg_match('/\b(edit|change|replace|remove|erase|add|modify|adjust|retouch)\b/', $lower);

        return [
            'language'          => $this->guessLanguage($prompt),
            'char_length'       => mb_strlen($prompt),
            'word_count'        => $prompt === '' ? 0 : count(preg_split('/\s+/', trim($prompt)) ?: []),
            'image_count'       => count($refs),
            'reference_count'   => count($refs),
            'editing_intent'    => $editing,
            'generation_intent' => ! $editing,
        ];
    }

    private function guessLanguage(string $s): string
    {
        if (trim($s) === '') return 'unknown';
        return preg_match('/[^\x00-\x7F]/', $s) ? 'non-latin' : 'en';
    }

    private function buildSpec(string $capability, string $prompt, array $input, array &$warnings): GenerationSpec
    {
        $spec = new GenerationSpec();
        $spec->capability = $capability;
        $spec->provider = $input['provider'] ?? null;
        $spec->model = $input['model'] ?? null;
        $spec->reference_images = is_array($input['reference_images'] ?? null) ? $input['reference_images'] : [];

        $ar = $this->inferAspectRatio($prompt, $warnings);
        if ($ar !== null) {
            $spec->aspect_ratio = $ar;
            [$spec->width, $spec->height] = $this->aspectRatioToSize($ar);
        }

        $spec->style = $this->inferStyle($prompt);

        if ($capability === 'video') {
            $spec->video_duration = 10;
            $spec->fps = 24;
        }
        if (in_array($capability, ['edit', 'variation'], true)) {
            $spec->editing_mode = 'inpaint';
        }

        $spec->metadata = ['inferred' => true];
        return $spec;
    }

    private function inferAspectRatio(string $prompt, array &$warnings): ?string
    {
        $lower = mb_strtolower($prompt);
        $found = [];
        foreach (['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3'] as $r) {
            if (str_contains($lower, $r)) {
                $found[] = $r;
            }
        }
        // word-based hints (deterministic mapping)
        $words = ['square' => '1:1', 'widescreen' => '16:9', 'landscape' => '16:9', 'portrait' => '9:16', 'vertical' => '9:16'];
        foreach ($words as $w => $r) {
            if (preg_match('/\b' . $w . '\b/', $lower)) {
                $found[] = $r;
            }
        }
        $distinct = array_values(array_unique($found));
        if (count($distinct) === 0) {
            return null;
        }
        if (count($distinct) > 1) {
            $warnings[] = 'multiple_conflicting_aspect_ratios';
        }
        return $distinct[0]; // first occurrence — deterministic
    }

    private function aspectRatioToSize(string $ar): array
    {
        return match ($ar) {
            '16:9', '3:2' => [1792, 1024],
            '9:16', '2:3' => [1024, 1792],
            '4:3' => [1024, 768],
            '3:4' => [768, 1024],
            default => [1024, 1024],
        };
    }

    private function inferStyle(string $prompt): ?string
    {
        $lower = mb_strtolower($prompt);
        foreach (['cinematic', 'minimal', 'bold', 'elegant', 'editorial', 'photorealistic', 'watercolor', 'anime'] as $style) {
            if (str_contains($lower, $style)) {
                return $style;
            }
        }
        return null;
    }

    private function compare(string $original, string $normalized, string $compiled): array
    {
        if ($original === $compiled) {
            $result = 'identical';
        } elseif ($normalized === $compiled) {
            $result = 'normalized_only';
        } else {
            $result = 'structural_changes';
        }

        return [
            'result'          => $result,
            'changed'         => $original !== $compiled,
            'original_length' => mb_strlen($original),
            'compiled_length' => mb_strlen($compiled),
        ];
    }
}
