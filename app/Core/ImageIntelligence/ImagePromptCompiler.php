<?php

namespace App\Core\ImageIntelligence;

/**
 * ImagePromptCompiler — turns a validated ImageBlueprint into a concrete
 * provider request (final prompt + size + quality) and enforces the typography
 * policy deterministically on top of the LLM's provider_prompt.
 *
 * Typography modes:
 *  - none / separate_overlay → the image must be TEXT-FREE with reserved
 *    negative space; overlay copy is returned for the Studio typography engine.
 *  - baked_in → the short headline is embedded (quoted) in the prompt.
 */
class ImagePromptCompiler
{
    private const NO_TEXT = ' Strict rule: NO text, NO words, NO letters, NO numbers, NO logos, NO watermarks, NO captions, NO typography of any kind — pure visual composition only, with clean deliberate negative space reserved for text to be added later.';

    /**
     * @return array{
     *   provider_prompt:string, size:string, quality:string, provider:string, model:string,
     *   typography:array, overlay:?array
     * }
     */
    public function compile(array $bp): array
    {
        // F-STUDIO-PS-PROMPT-ASSEMBLY (2026-09-03): the LLM reasoner is instructed
        // (system prompt) to make provider_prompt a typography/NO-TEXT note for mode
        // none/separate_overlay, so it frequently returns ONLY "…NO text… reserve
        // negative space" and DROPS the actual scene. Trusting that verbatim sent an
        // essentially subject-less brief to the image model — the rich reasoning
        // (subject/composition/scene/lighting/mood/colour/brand) was computed then
        // discarded, gutting the "one prompt → professional result" promise. Fix:
        // assemble the provider prompt DETERMINISTICALLY from the structured blueprint
        // fields (parity with compileFromBrief), independent of whether the LLM put a
        // rich string in provider_prompt. The LLM's own provider_prompt is folded in
        // ONLY when it adds scene detail beyond a NO-TEXT note (avoids duplication).
        $llmPrompt = trim((string) ($bp['provider_prompt'] ?? ''));
        $llmIsThin = $llmPrompt === ''
            || (stripos($llmPrompt, 'no text') !== false || stripos($llmPrompt, 'no words') !== false || stripos($llmPrompt, 'no letters') !== false)
            && stripos($llmPrompt, 'composition') === false && stripos($llmPrompt, 'lighting') === false;
        $parts = [];
        if (! $llmIsThin) { $parts[] = $llmPrompt; }
        if (($v = trim((string) ($bp['subject'] ?? ''))) !== '')          { $parts[] = $v; }
        if (($v = trim((string) ($bp['composition'] ?? ''))) !== '')      { $parts[] = 'Composition: ' . $v; }
        if (($v = trim((string) ($bp['scene'] ?? ''))) !== '')            { $parts[] = 'Scene: ' . $v; }
        if (($v = trim((string) ($bp['visual_hierarchy'] ?? ''))) !== '') { $parts[] = 'Visual hierarchy: ' . $v; }
        if (($v = trim((string) ($bp['lighting'] ?? ''))) !== '')         { $parts[] = 'Lighting: ' . $v; }
        if (($v = trim((string) ($bp['mood'] ?? ''))) !== '')             { $parts[] = 'Mood: ' . $v; }
        $__colors = array_values(array_filter(array_map('strval', (array) ($bp['color_palette'] ?? []))));
        if ($__colors) { $parts[] = 'Colour palette: ' . implode(', ', array_slice($__colors, 0, 5)); }
        if (($v = trim((string) ($bp['brand_application'] ?? ''))) !== '') { $parts[] = $v; }
        $prompt = implode('. ', array_filter($parts));
        if ($prompt === '') { $prompt = $llmPrompt; } // absolute fallback — never send empty
        // Fold blueprint negative_constraints into the sent prompt (compile() previously
        // dropped them; only compileFromBrief surfaced them). These genuinely steer the model.
        $__neg = array_values(array_filter(array_map('strval', (array) ($bp['negative_constraints'] ?? []))));
        if ($__neg) { $prompt = rtrim($prompt, '. ') . '. Avoid: ' . implode('; ', $__neg) . '.'; }

        $ts     = $bp['typography_strategy'] ?? ['mode' => 'none'];
        $mode   = $ts['mode'] ?? 'none';

        if ($mode === 'baked_in') {
            $headline = trim((string) ($ts['headline'] ?? ''));
            if ($headline !== '' && stripos($prompt, $headline) === false) {
                $prompt = rtrim($prompt, '. ') . '. Render the exact short headline text "' . $headline . '" '
                    . (($ts['placement'] ?? '') !== '' ? 'placed ' . $ts['placement'] . ', ' : '')
                    . 'in a clean, correctly-spelled, high-contrast, professionally-kerned '
                    . (($ts['style'] ?? '') !== '' ? $ts['style'] . ' ' : '') . 'typeface. Do not add any other text.';
            }
        } else {
            // none OR separate_overlay → force text-free image (only append if not already stated)
            if (stripos($prompt, 'no text') === false && stripos($prompt, 'no words') === false) {
                $prompt = rtrim($prompt, '. ') . '.' . self::NO_TEXT;
            }
        }

        // overlay instructions returned for the design/typography layer (fidelity path)
        $overlay = null;
        if ($mode === 'separate_overlay') {
            $overlay = [
                'headline'        => (string) ($ts['headline'] ?? ''),
                'supporting_copy' => array_values((array) ($ts['supporting_copy'] ?? [])),
                'placement'       => (string) ($ts['placement'] ?? 'upper-left negative space'),
                'style'           => (string) ($ts['style'] ?? ''),
                'color_palette'   => array_values((array) ($bp['color_palette'] ?? [])),
                'note'            => 'Render this copy with real HTML/canvas/SVG typography in the Studio layer for exact fidelity — do NOT ask the image model to draw it.',
            ];
        }

        return [
            'provider_prompt' => $prompt,
            'size'            => $this->size($bp),
            'quality'         => in_array(strtolower((string) ($bp['quality'] ?? 'medium')), ['low','medium','high'], true) ? strtolower((string) $bp['quality']) : 'medium',
            'provider'        => (string) ($bp['provider'] ?? 'openai'),
            'model'           => (string) ($bp['model'] ?? 'gpt-image-1'),
            'typography'      => $ts,
            'overlay'         => $overlay,
        ];
    }

    /**
     * WAVE 3 — compile DIRECTLY from a Creative888 image brief (the enriched
     * BlueprintService::getImageBlueprint output), with NO second creative
     * reasoning. Studio technical realization only: fold the brief's
     * already-reasoned creative direction (composition / lighting / mood / brand
     * application) into ONE provider prompt, then run the SAME deterministic
     * compile() above (typography enforcement, size snap, quality). Additive and
     * dormant — the live generate() path is unchanged, so external behavior,
     * providers, credits and pricing are untouched. Proves Studio can execute
     * from Creative888 without ImageReasoningService.
     */
    public function compileFromBrief(array $brief): array
    {
        $parts = [trim((string) ($brief['provider_prompt'] ?? $brief['enhanced_prompt'] ?? ''))];
        if (($c = trim((string) ($brief['composition'] ?? ''))) !== '')       $parts[] = 'Composition: ' . $c;
        if (($l = trim((string) ($brief['lighting'] ?? ''))) !== '')          $parts[] = 'Lighting: ' . $l;
        if (($m = trim((string) ($brief['mood'] ?? ''))) !== '')              $parts[] = 'Mood: ' . $m;
        if (($b = trim((string) ($brief['brand_application'] ?? ''))) !== '') $parts[] = $b;

        $bp = [
            'provider_prompt'     => implode('. ', array_filter($parts)),
            'quality'             => $brief['quality'] ?? 'medium',
            'dimensions'          => $brief['dimensions'] ?? ['width' => 1024, 'height' => 1024],
            'typography_strategy' => $brief['typography_strategy'] ?? ['mode' => 'none'],
            'color_palette'       => $brief['color_palette'] ?? [],
            'provider'            => $brief['provider'] ?? 'openai',
            'model'               => $brief['model'] ?? 'gpt-image-1',
        ];

        $out = $this->compile($bp);
        // Surface the Creative888-owned negative constraints (text-free enforcement
        // is already applied deterministically by compile()'s typography policy).
        $out['negative_constraints'] = array_values(array_filter(
            array_map('strval', (array) ($brief['negative_constraints'] ?? []))
        ));
        $out['from_brief'] = true;
        return $out;
    }

    private function size(array $bp): string
    {
        $w = (int) ($bp['dimensions']['width'] ?? 1024);
        $h = (int) ($bp['dimensions']['height'] ?? 1024);
        $supported = ['1024x1024', '1024x1536', '1536x1024'];
        $s = $w . 'x' . $h;
        return in_array($s, $supported, true) ? $s : '1024x1024';
    }
}
