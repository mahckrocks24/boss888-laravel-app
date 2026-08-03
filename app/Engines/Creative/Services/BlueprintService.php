<?php

namespace App\Engines\Creative\Services;

use App\Connectors\DeepSeekConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BlueprintService — Blueprint Strategy Engine
 *
 * Produces pre-generation context ("blueprints") that every other engine
 * injects into its LLM prompts before generating content.
 *
 * A blueprint answers: What is this for? Who is the audience? What has
 * worked before? What brand constraints apply? What is the goal?
 *
 * All engines call generateThroughBlueprint() in CreativeService which
 * routes here. No engine generates content without a blueprint first.
 *
 * PATCHED: Now uses BlueprintRetrieverService + BlueprintInfluenceService
 * for image blueprints when stored blueprints are available.
 */
class BlueprintService
{
    public function __construct(
        private DeepSeekConnector $llm,
        private CimsService $cims,
        private BlueprintRetrieverService $retriever,
        private BlueprintInfluenceService $influence,
        private \App\Connectors\RuntimeClient $runtime,
    ) {}

    // ═══════════════════════════════════════════════════════
    // PUBLIC BLUEPRINT METHODS (one per content domain)
    // ═══════════════════════════════════════════════════════

    /**
     * Content blueprint for the Write engine.
     * Used by: articles, blog posts, long-form copy, web copy.
     */
    public function getContentBlueprint(int $wsId, array $context = []): array
    {
        $brand    = $this->cims->buildBrandContext($wsId);
        $memory   = $this->cims->buildMemoryContext($wsId, 'article');
        $identity = $this->cims->getBrandIdentity($wsId);
        $goal     = $context['goal'] ?? 'Inform and engage the target audience';
        $audience = $context['audience'] ?? $identity['target_audience'] ?? 'business owners';
        $funnel   = $context['funnel_stage'] ?? 'awareness';
        $topic    = $context['topic'] ?? '';

        $systemPrompt = "You are a content strategy expert. Generate a concise content blueprint (max 200 words) as a JSON object. No markdown, no explanation — only valid JSON.";

        $userPrompt = <<<EOT
Generate a content blueprint for this request:

Brand context: {$brand}
Goal: {$goal}
Topic: {$topic}
Audience: {$audience}
Funnel stage: {$funnel}
Industry: {$identity['industry']}
{$memory}

Return JSON with these keys:
- angle: the specific angle or hook for this piece
- structure: brief outline (3–5 points)
- tone_instructions: how to write (voice, formality, energy)
- seo_focus: primary keyword intent if applicable
- cta_suggestion: suggested call to action
- avoid: things to avoid based on brand/history
- confidence: 0.0–1.0 how well this blueprint fits the request
EOT;

        return $this->generate('content', $wsId, $systemPrompt, $userPrompt, $context);
    }

    /**
     * Email blueprint for the Marketing engine.
     * Used by: campaign emails, newsletters, drip sequences.
     */
    public function getEmailBlueprint(int $wsId, array $context = []): array
    {
        $brand    = $this->cims->buildBrandContext($wsId);
        $memory   = $this->cims->buildMemoryContext($wsId, 'email');
        $identity = $this->cims->getBrandIdentity($wsId);
        $goal     = $context['goal'] ?? 'Drive action';
        $segment  = $context['segment'] ?? 'all subscribers';
        $campaign = $context['campaign_name'] ?? '';

        $systemPrompt = "You are an email marketing strategist. Generate a concise email blueprint as a JSON object. No markdown, no explanation — only valid JSON.";

        $userPrompt = <<<EOT
Generate an email blueprint:

Brand context: {$brand}
Campaign: {$campaign}
Goal: {$goal}
Segment: {$segment}
Industry: {$identity['industry']}
{$memory}

Return JSON with these keys:
- subject_line_angle: the emotion or hook to use in the subject
- preview_text_hint: what the preview text should accomplish
- structure: array of sections (e.g. ["hook", "problem", "solution", "cta"])
- tone_instructions: voice and energy level
- personalization_hooks: where to insert dynamic content
- cta_text: suggested CTA button text and destination type
- avoid: things to avoid
- confidence: 0.0–1.0
EOT;

        return $this->generate('email', $wsId, $systemPrompt, $userPrompt, $context);
    }

    /**
     * Social blueprint for the Social engine.
     * Used by: posts, captions, stories, threads.
     */
    public function getSocialBlueprint(int $wsId, array $context = []): array
    {
        $brand    = $this->cims->buildBrandContext($wsId);
        $memory   = $this->cims->buildMemoryContext($wsId, 'post');
        $identity = $this->cims->getBrandIdentity($wsId);
        $platform = $context['platform'] ?? 'instagram';
        $goal     = $context['goal'] ?? 'Increase engagement';

        $systemPrompt = "You are a social media strategist. Generate a social media content blueprint as a JSON object. No markdown, no explanation — only valid JSON.";

        $userPrompt = <<<EOT
Generate a social blueprint:

Brand context: {$brand}
Platform: {$platform}
Goal: {$goal}
Industry: {$identity['industry']}
{$memory}

Return JSON with these keys:
- hook: the opening line strategy (question, stat, story, bold claim)
- format: content format best for this goal on this platform
- hashtag_strategy: type and count of hashtags
- visual_direction: what the accompanying image/video should show
- tone_instructions: platform-appropriate voice
- engagement_prompt: how to end to drive comments/saves/shares
- optimal_length: ideal character/word count range
- avoid: platform-specific things to avoid
- confidence: 0.0–1.0
EOT;

        return $this->generate('social', $wsId, $systemPrompt, $userPrompt, $context);
    }

    /**
     * Landing page / web page blueprint for the Builder engine.
     * Used by: landing pages, hero sections, website pages.
     */
    public function getPageBlueprint(int $wsId, array $context = []): array
    {
        $brand    = $this->cims->buildBrandContext($wsId);
        $memory   = $this->cims->buildMemoryContext($wsId, 'page');
        $identity = $this->cims->getBrandIdentity($wsId);
        $goal     = $context['goal'] ?? 'Convert visitors';
        $audience = $context['audience'] ?? $identity['target_audience'] ?? 'potential customers';
        $pageType = $context['page_type'] ?? 'landing';

        $systemPrompt = "You are a conversion-focused web copywriter and UX strategist. Generate a page blueprint as a JSON object. No markdown, no explanation — only valid JSON.";

        $userPrompt = <<<EOT
Generate a page blueprint:

Brand context: {$brand}
Page type: {$pageType}
Goal: {$goal}
Audience: {$audience}
Industry: {$identity['industry']}
{$memory}

Return JSON with these keys:
- headline_angle: the primary message and emotional hook
- subheadline_hint: supporting line direction
- sections: ordered array of page sections with purpose for each
- hero_visual_direction: what the hero image/video should communicate
- trust_signals: types of social proof to include
- cta_primary: primary CTA text and placement
- cta_secondary: secondary CTA if applicable
- copy_tone: writing style for this page
- avoid: conversion killers to avoid
- confidence: 0.0–1.0
EOT;

        return $this->generate('page', $wsId, $systemPrompt, $userPrompt, $context);
    }

    /**
     * Outreach blueprint for the CRM engine.
     * Used by: lead outreach emails, follow-ups, sales sequences.
     */
    public function getOutreachBlueprint(int $wsId, array $context = []): array
    {
        $brand    = $this->cims->buildBrandContext($wsId);
        $memory   = $this->cims->buildMemoryContext($wsId, 'outreach');
        $identity = $this->cims->getBrandIdentity($wsId);
        $leadCtx  = $context['lead_context'] ?? 'unknown lead';
        $stage    = $context['pipeline_stage'] ?? 'new';
        $goal     = $context['goal'] ?? 'Book a meeting';

        $systemPrompt = "You are a B2B sales strategist. Generate an outreach blueprint as a JSON object. No markdown, no explanation — only valid JSON.";

        $userPrompt = <<<EOT
Generate an outreach blueprint:

Brand context: {$brand}
Lead context: {$leadCtx}
Pipeline stage: {$stage}
Goal: {$goal}
Industry: {$identity['industry']}
{$memory}

Return JSON with these keys:
- opening_strategy: how to open (shared connection, problem, insight, compliment)
- value_proposition: the core offer to lead with
- personalization_hooks: what to customize per lead
- cta: specific ask (meeting, call, reply, resource download)
- follow_up_timing: when to follow up if no response
- tone_instructions: professional level and warmth
- avoid: things that kill deals at this stage
- confidence: 0.0–1.0
EOT;

        return $this->generate('outreach', $wsId, $systemPrompt, $userPrompt, $context);
    }

    /**
     * Ad creative blueprint for paid media campaigns.
     * Used by: Meta Ads, Google Ads, LinkedIn Ads copy.
     */
    public function getAdBlueprint(int $wsId, array $context = []): array
    {
        $brand    = $this->cims->buildBrandContext($wsId);
        $identity = $this->cims->getBrandIdentity($wsId);
        $platform = $context['platform'] ?? 'meta';
        $objective= $context['objective'] ?? 'conversions';
        $audience = $context['audience'] ?? $identity['target_audience'] ?? 'potential customers';

        $systemPrompt = "You are a paid media copywriter. Generate an ad creative blueprint as a JSON object. No markdown, no explanation — only valid JSON.";

        $userPrompt = <<<EOT
Generate an ad creative blueprint:

Brand context: {$brand}
Platform: {$platform}
Objective: {$objective}
Audience: {$audience}
Industry: {$identity['industry']}

Return JSON with these keys:
- headline_variants: 3 headline angles to test
- primary_text_angle: the hook and problem/solution framing
- visual_direction: what the creative should show
- format_recommendation: image, video, carousel — and why
- cta_button: recommended CTA button text
- targeting_notes: audience signals to consider
- avoid: platform policy risks and creative fatigue patterns
- confidence: 0.0–1.0
EOT;

        return $this->generate('ad', $wsId, $systemPrompt, $userPrompt, $context);
    }

    /**
     * Image generation blueprint.
     * Enhances the user's prompt with brand context before image generation.
     * PATCHED: Now uses stored blueprint retrieval + influence when available.
     */
    public function getImageBlueprint(int $wsId, string $prompt, array $context = []): array
    {
        $brand    = $this->cims->buildBrandContext($wsId);
        $identity = $this->cims->getBrandIdentity($wsId);

        $enhancedPrompt = $prompt;
        $additions = [];

        if (!empty($identity['visual_style'])) {
            $additions[] = $identity['visual_style'];
        }
        if (!empty($identity['tone']) && $identity['tone'] !== 'professional') {
            $additions[] = "Style: {$identity['tone']}";
        }
        if (!empty($identity['colors'])) {
            $colorList = implode(', ', array_slice($identity['colors'], 0, 2));
            $additions[] = "Color palette: {$colorList}";
        }
        if (!empty($context['style'])) {
            $additions[] = $context['style'];
        }

        if (!empty($additions)) {
            $enhancedPrompt = $prompt . '. ' . implode('. ', $additions);
        }

        // PATCHED: Apply blueprint influence from stored blueprints
        $storedBlueprints = [];
        $influenceApplied = false;
        try {
            $analysis = [
                'layout'           => $context['layout'] ?? '',
                'dominant_subject' => $context['subject'] ?? $context['subject_type'] ?? '',
                'style'            => $context['style'] ?? '',
            ];

            $storedBlueprints = $this->retriever->getRelevant($wsId, $analysis, 3);

            if (!empty($storedBlueprints)) {
                $influenceResult = $this->influence->apply($enhancedPrompt, $analysis, $storedBlueprints);
                if ($influenceResult['modified']) {
                    $enhancedPrompt = $influenceResult['final_prompt'];
                    $influenceApplied = true;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('[BlueprintService] Blueprint influence skipped: ' . $e->getMessage());
        }

        // ── WAVE 2 PHASE 1 (2026-08-03): enrich the Creative888 image brief so
        // Studio has the complete creative direction it needs and never has to
        // reason itself. ADDITIVE ONLY — every pre-existing key below is unchanged,
        // so existing callers (which read `enhanced_prompt`) are byte-unaffected.
        // Deterministic (no LLM, no runtime); brand from CimsService, memory from
        // BlueprintRetrieverService, learning from BlueprintInfluenceService (above).
        $assetType = strtolower((string) ($context['asset_type'] ?? 'social_post'));
        $platform  = (string) ($context['platform'] ?? '');
        $colors    = array_values(array_filter((array) ($identity['colors'] ?? [])));
        $audience  = trim((string) ($identity['target_audience'] ?? '')) ?: 'general business audience';
        $direction = $this->imageCreativeDirection($assetType, $platform);
        $dims      = $this->imageDimensions($assetType, $platform, $context);

        $rq       = strtolower((string) ($context['requested_quality'] ?? $context['quality'] ?? ''));
        $quality  = in_array($rq, ['low', 'medium', 'high'], true) ? $rq : 'medium';

        $headline = trim((string) ($context['headline'] ?? ''));
        $copy     = array_values(array_filter(array_map('strval', (array) ($context['supporting_copy'] ?? []))));
        $tsMode   = $headline !== '' ? 'separate_overlay' : 'none';

        $brandApplication = $colors
            ? ('Apply the brand palette (' . implode(', ', array_slice($colors, 0, 3)) . ') through composition, props, surfaces, and lighting — never as drawn text'
                . (!empty($identity['visual_style']) ? '; visual style: ' . $identity['visual_style'] : '') . '.')
            : 'No brand kit configured — neutral professional treatment.';

        return [
            // ── existing contract (UNCHANGED — callers depend on these) ──
            'type'                => 'image',
            'enhanced_prompt'     => $enhancedPrompt,
            'original_prompt'     => $prompt,
            'brand_context'       => $brand,
            'visual_style'        => $identity['visual_style'] ?? null,
            'workspace_id'        => $wsId,
            'confidence'          => 0.85,
            'stored_blueprint_ids'=> array_column($storedBlueprints, '_id'),
            'influence_applied'   => $influenceApplied,

            // ── WAVE 2 PHASE 1 additive creative brief (Studio-execution ready) ──
            'intent'              => 'Creative888 image brief',
            'subject'             => $prompt,
            'audience'            => $audience,
            'platform'            => $platform,
            'asset_type'          => $assetType,
            'provider_prompt'     => $enhancedPrompt,
            'aspect_ratio'        => $dims['aspect_ratio'],
            'dimensions'          => ['width' => $dims['width'], 'height' => $dims['height']],
            'quality'             => $quality,
            'composition'         => $direction['composition'],
            'visual_hierarchy'    => $direction['visual_hierarchy'],
            'lighting'            => $direction['lighting'],
            'mood'                => $direction['mood'],
            'color_palette'       => $colors,
            'brand_application'   => $brandApplication,
            'negative_constraints'=> ['no text', 'no watermark', 'no logos'],
            'typography_strategy' => [
                'mode'            => $tsMode,
                'reason'          => $tsMode === 'separate_overlay'
                    ? 'copy provided — render exact typography in the Studio layer over a text-free image'
                    : 'no copy provided — text-free visual with reserved negative space',
                'headline'        => $headline,
                'supporting_copy' => $copy,
                'placement'       => (string) ($context['copy_placement'] ?? 'upper-left negative space'),
                'style'           => '',
            ],
        ];
    }

    /**
     * WAVE 2 PHASE 1 — deterministic creative direction by asset category.
     * Creative888-owned art direction (no LLM, no runtime): composition, lighting
     * and mood guidance Studio proved necessary, so execution never reasons it.
     * Pure function of asset_type + platform.
     */
    private function imageCreativeDirection(string $assetType, string $platform): array
    {
        $t   = strtolower($assetType . ' ' . $platform);
        $has = static fn(string ...$n): bool => (bool) array_filter($n, static fn($x) => str_contains($t, $x));

        if ($has('food', 'dish', 'recipe', 'meal', 'menu', 'cuisine')) {
            $d = ['composition' => 'hero subject slightly off-centre on the rule of thirds', 'lighting' => 'soft directional light with gentle falloff', 'mood' => 'warm, premium, appetising'];
        } elseif ($assetType === 'ad' || $has('poster', 'promo', 'advertisement', 'flyer', 'campaign')) {
            $d = ['composition' => 'bold rule-of-thirds subject with strong leading lines and room for a headline', 'lighting' => 'dramatic high-contrast light', 'mood' => 'confident, energetic, premium'];
        } elseif ($has('story', 'reel', 'vertical', 'tiktok')) {
            $d = ['composition' => 'strong vertical composition, subject in the lower-middle third', 'lighting' => 'natural, atmospheric light', 'mood' => 'immersive, authentic, aspirational'];
        } elseif ($has('cover', 'banner', 'header')) {
            $d = ['composition' => 'wide cinematic framing, subject offset to one third', 'lighting' => 'soft cinematic light', 'mood' => 'calm, established, trustworthy'];
        } elseif ($has('portrait', 'headshot', 'team', 'staff', 'founder')) {
            $d = ['composition' => 'head-and-shoulders subject framed slightly off-centre', 'lighting' => 'soft flattering key with gentle fill', 'mood' => 'approachable, credible, human'];
        } else {
            $d = ['composition' => 'single clear focal subject on the rule of thirds', 'lighting' => 'soft professional studio light', 'mood' => 'polished, premium, inviting'];
        }
        $d['visual_hierarchy'] = 'subject dominant; clean supporting background with reserved negative space for typography';
        return $d;
    }

    /**
     * WAVE 2 PHASE 1 — deterministic dimensions guidance, snapped to the
     * provider-supported set (Studio's compiler independently re-validates size).
     */
    private function imageDimensions(string $assetType, string $platform, array $context): array
    {
        if (!empty($context['canvas_width']) && !empty($context['canvas_height'])) {
            $w = (int) $context['canvas_width'];
            $h = (int) $context['canvas_height'];
        } else {
            $t = strtolower($assetType . ' ' . $platform);
            if (str_contains($t, 'story') || str_contains($t, 'reel') || str_contains($t, 'vertical') || str_contains($t, 'portrait') || str_contains($t, 'pinterest')) {
                $w = 1024; $h = 1536;
            } elseif (str_contains($t, 'cover') || str_contains($t, 'banner') || str_contains($t, 'header') || str_contains($t, 'landscape') || str_contains($t, 'youtube') || str_contains($t, 'blog') || str_contains($t, 'featured')) {
                $w = 1536; $h = 1024;
            } else {
                $w = 1024; $h = 1024;
            }
        }
        $ar        = $h > 0 ? $w / $h : 1.0;
        $supported = [[1024, 1024], [1024, 1536], [1536, 1024]];
        $best      = [1024, 1024];
        $bestDiff  = PHP_FLOAT_MAX;
        foreach ($supported as [$sw, $sh]) {
            $diff = abs(($sw / $sh) - $ar);
            if ($diff < $bestDiff) { $bestDiff = $diff; $best = [$sw, $sh]; }
        }
        [$w, $h] = $best;
        return ['width' => $w, 'height' => $h, 'aspect_ratio' => $w === $h ? '1:1' : ($w > $h ? '3:2' : '2:3')];
    }

    /**
     * Video generation blueprint.
     * Structures multi-scene video prompt with brand context.
     */
    public function getVideoBlueprint(int $wsId, string $prompt, array $context = []): array
    {
        $brand    = $this->cims->buildBrandContext($wsId);
        $identity = $this->cims->getBrandIdentity($wsId);
        $duration = $context['duration'] ?? 10;
        $scenes   = max(1, (int) round($duration / 5));

        $styleAdditions = [];
        if (!empty($identity['visual_style'])) {
            $styleAdditions[] = $identity['visual_style'];
        }
        $styleStr = implode(', ', $styleAdditions);

        return [
            'type'            => 'video',
            'prompt'          => $prompt,
            'style_additions' => $styleStr,
            'duration'        => $duration,
            'scene_count'     => $scenes,
            'aspect_ratio'    => $context['aspect_ratio'] ?? '16:9',
            'brand_context'   => $brand,
            'workspace_id'    => $wsId,
            'confidence'      => 0.8,
        ];
    }

    // ═══════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════

    /**
     * Core LLM blueprint generation.
     * Returns a structured blueprint array. Never throws — on LLM failure,
     * returns a safe minimal blueprint so engines can still proceed.
     *
     * REFACTORED 2026-04-12 (Phase 2A.0 / doc 14): now routes through
     * RuntimeClient::aiRun('competitor_analysis', ...) instead of direct
     * DeepSeekConnector. The blueprint system prompt is folded into the user
     * prompt — runtime task type's preset prompt is wrong but the LLM still
     * gets the full instructions via the user message. Migrate to chat_json
     * task type when Phase 0.17 ships for cleaner system prompt override.
     *
     * **R5 KEYSTONE REFACTOR**: this is the single LLM call site shared by
     * all 6 public blueprint methods. Refactoring it eliminates 1 LLM bypass
     * site that's upstream of every engine that uses the blueprint pattern.
     */
    private function generate(string $type, int $wsId, string $systemPrompt, string $userPrompt, array $context): array
    {
        try {
            // MIGRATED 2026-04-13 (Phase 0.17b): switched from aiRun fold-pattern
            // to chatJson. The blueprint system prompt now goes through directly,
            // the runtime forces JSON mode + parses server-side, and we read
            // the parsed object straight into the return shape. R5 keystone path.
            $sysWithJsonHint = $systemPrompt
                             . "\n\nOutput ONLY a valid JSON object — no markdown, no commentary.";

            $result = $this->runtime->chatJson($sysWithJsonHint, $userPrompt, [
                'task'           => 'blueprint_' . $type,
                'workspace_id'   => (string) $wsId,
                'blueprint_type' => $type,
            ], 800);

            if (($result['success'] ?? false) && is_array($result['parsed'] ?? null) && !empty($result['parsed'])) {
                return array_merge([
                    'type'        => $type,
                    'workspace_id'=> $wsId,
                    'generated'   => true,
                    'context'     => $context,
                ], $result['parsed']);
            }
        } catch (\Throwable $e) {
            Log::warning("BlueprintService::generate({$type}) runtime call failed", ['error' => $e->getMessage()]);
        }

        // Safe fallback — minimal blueprint so engines can still proceed
        return $this->fallbackBlueprint($type, $wsId, $context);
    }

    private function fallbackBlueprint(string $type, int $wsId, array $context): array
    {
        $brand = $this->cims->buildBrandContext($wsId);

        return [
            'type'             => $type,
            'workspace_id'     => $wsId,
            'generated'        => false,
            'fallback'         => true,
            'brand_context'    => $brand,
            'tone_instructions'=> 'Professional, clear, and audience-focused.',
            'avoid'            => 'Jargon, vague claims, and off-brand language.',
            'confidence'       => 0.4,
            'context'          => $context,
        ];
    }
}
