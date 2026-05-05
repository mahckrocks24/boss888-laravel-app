<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BlueprintInfluenceService — Creative888 Blueprint Influence Builder
 *
 * Ported from WP class-lucreative-blueprint-influence.php (Phase 2C).
 *
 * Applies soft pattern guidance from stored blueprints to generation prompts.
 *
 * KEY CONSTRAINTS (Non-aggressive influence):
 * - NEVER replaces original prompt
 * - NEVER injects hard layout rules
 * - NEVER overrides user intent
 * - Original prompt ALWAYS remains the first/dominant segment
 * - Influence is appended as a soft, brief advisory note only
 * - If no clear patterns emerge: returns original prompt unchanged
 *
 * NEVER throws — returns original prompt on any failure.
 */
class BlueprintInfluenceService
{
    // Maximum characters to append (prevents prompt bloat)
    const MAX_INFLUENCE_CHARS = 400;

    /**
     * Apply soft blueprint influence to an enriched prompt.
     *
     * @param  string $prompt      Enriched generation prompt.
     * @param  array  $analysis    Analysis for the current request.
     * @param  array  $blueprints  Array of decoded blueprint objects from retriever.
     * @return array  { final_prompt: string, modified: bool }
     */
    public function apply(string $prompt, array $analysis, array $blueprints): array
    {
        $base = ['final_prompt' => $prompt, 'modified' => false];

        if (empty($blueprints) || empty($prompt)) {
            return $base;
        }

        try {
            return $this->applyInfluence($prompt, $analysis, $blueprints, $base);
        } catch (\Throwable $e) {
            Log::warning('[CREATIVE888 BlueprintInfluence] Exception (using original): ' . $e->getMessage());
            return $base;
        }
    }

    // =========================================================================
    // INTERNAL BUILDER
    // =========================================================================

    private function applyInfluence(string $prompt, array $analysis, array $blueprints, array $base): array
    {
        // Extract useful patterns from the blueprint set
        $layoutCounts  = [];
        $styleCounts   = [];
        $timingCounts  = [];
        $ctaCounts     = [];

        foreach ($blueprints as $bp) {
            $l = strtolower(trim($bp['structure']['layout'] ?? ''));
            $s = strtolower(trim($bp['visual_style'] ?? ''));
            $t = strtolower(trim($bp['timing'] ?? ''));
            $c = strtolower(trim($bp['cta_type'] ?? ''));

            if ($l && $l !== 'unknown') $layoutCounts[$l] = ($layoutCounts[$l] ?? 0) + 1;
            if ($s && $s !== 'unknown') $styleCounts[$s]  = ($styleCounts[$s] ?? 0) + 1;
            if ($t && $t !== 'evergreen' && $t) $timingCounts[$t] = ($timingCounts[$t] ?? 0) + 1;
            if ($c && $c !== 'none') $ctaCounts[$c] = ($ctaCounts[$c] ?? 0) + 1;
        }

        // Build pattern bullets (max 3, most frequent first)
        $patterns = [];

        // Pattern 1: Layout
        arsort($layoutCounts);
        $topLayout = array_key_first($layoutCounts);
        if ($topLayout) {
            $layoutPhrases = [
                'centered' => 'Strong central focal point with balanced negative space',
                'hero'     => 'Full-bleed composition with clear visual hierarchy',
                'split'    => 'Clear left-right visual division for contrast and flow',
                'grid'     => 'Consistent spacing with unified visual rhythm',
                'overlay'  => 'Distinct foreground-background layering for readability',
            ];
            if (isset($layoutPhrases[$topLayout])) {
                $patterns[] = $layoutPhrases[$topLayout];
            }
        }

        // Pattern 2: Visual style
        arsort($styleCounts);
        $topStyle = array_key_first($styleCounts);
        if ($topStyle && count($patterns) < 3) {
            $stylePhrases = [
                'luxury'    => 'Premium quality with rich detail and refined lighting',
                'minimal'   => 'Clean with generous negative space and single hero element',
                'modern'    => 'Crisp contemporary aesthetic with geometric balance',
                'corporate' => 'Professional, structured with neutral authoritative tone',
                'lifestyle' => 'Natural, authentic energy with real-world context',
            ];
            if (isset($stylePhrases[$topStyle])) {
                $patterns[] = $stylePhrases[$topStyle];
            }
        }

        // Pattern 3: CTA or timing context
        arsort($ctaCounts);
        $topCta = array_key_first($ctaCounts);
        arsort($timingCounts);
        $topTiming = array_key_first($timingCounts);

        if ($topCta && count($patterns) < 3) {
            $ctaPhrases = [
                'shop_now'   => 'Product clearly visible with space for action button placement',
                'learn_more' => 'Informative composition with clear visual entry point',
                'sign_up'    => 'Inviting, accessible framing with open space for form area',
                'contact'    => 'Professional, approachable visual tone',
                'watch'      => 'Dynamic composition suggesting movement or story',
            ];
            if (isset($ctaPhrases[$topCta])) {
                $patterns[] = $ctaPhrases[$topCta];
            }
        } elseif ($topTiming && count($patterns) < 3) {
            $timingPhrases = [
                'seasonal' => 'Seasonal atmosphere reinforced through lighting and colour palette',
                'campaign' => 'Campaign-ready with bold visual energy and clear focal hierarchy',
            ];
            if (isset($timingPhrases[$topTiming])) {
                $patterns[] = $timingPhrases[$topTiming];
            }
        }

        // If no meaningful patterns: return unchanged
        if (empty($patterns)) {
            return $base;
        }

        // Build influence block
        $bulletLines = array_map(fn ($p) => '- ' . $p, array_slice($patterns, 0, 3));
        $influence   = ' Similar high-performing composition patterns: ' . implode('; ', $bulletLines);

        // Safety: cap appended length
        if (strlen($influence) > self::MAX_INFLUENCE_CHARS) {
            $influence = substr($influence, 0, self::MAX_INFLUENCE_CHARS);
        }

        // Safety: cap total prompt length (never exceed 3000 chars)
        $final = $prompt . $influence;
        if (strlen($final) > 3000) {
            return $base;
        }

        return ['final_prompt' => $final, 'modified' => true];
    }
}
