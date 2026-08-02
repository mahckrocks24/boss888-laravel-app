<?php

namespace App\Core\ImageIntelligence;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\Log;

/**
 * ImageReasoningService — the LLM "Arthur" reasoning stage of the canonical
 * image intelligence pipeline. Turns a resolved ImageGenerationContext into a
 * strictly-validated structured ImageBlueprint using the configured reasoning
 * model (via RuntimeClient::chatJson → runtime /ai/run).
 *
 * It reasons about: intent, subject, audience, platform, asset_type, aspect
 * ratio, composition, scene, visual hierarchy, lighting, mood, colour, brand
 * application, factual constraints, negative constraints, typography strategy,
 * quality and cost. It never forwards the raw user prompt unchanged.
 */
class ImageReasoningService
{
    public function __construct(private RuntimeClient $runtime) {}

    /** Required ImageBlueprint fields — strict schema. */
    private const REQUIRED = [
        'intent', 'subject', 'audience', 'platform', 'asset_type', 'aspect_ratio',
        'dimensions', 'composition', 'scene', 'visual_hierarchy', 'lighting', 'mood',
        'color_palette', 'brand_application', 'historical_or_factual_constraints',
        'negative_constraints', 'typography_strategy', 'provider_prompt', 'quality',
    ];

    /**
     * @return array{success:bool, blueprint?:array, reasoning_summary?:string, model?:string, fallback?:bool, error?:string}
     */
    public function reason(array $context): array
    {
        if (!$this->runtime->isConfigured()) {
            return $this->fallback($context, 'runtime_unconfigured');
        }

        [$system, $user] = $this->buildPrompt($context);

        // Robustness (WP2 Phase 2.1B): the reasoning call must NEVER break image
        // generation. isConfigured + returned-failure already fall back; this
        // try/catch also guarantees that ANY throw from chatJson/parsing (not
        // just the ConnectionException chatJson handles internally) degrades to
        // the documented deterministic fallback rather than propagating.
        try {
            $res = $this->runtime->chatJson($system, $user, [], 1400);
            $parsed = $res['parsed'] ?? ($res['data'] ?? null);

            if (empty($res['success']) || !is_array($parsed)) {
                Log::warning('[ImageIntelligence] reasoning call failed', ['res' => $res]);
                return $this->fallback($context, 'reasoning_failed:' . ($res['error'] ?? 'no_parsed'));
            }

            $bp = $this->normalizeAndValidate($parsed, $context);
            if ($bp === null) {
                Log::warning('[ImageIntelligence] blueprint schema invalid', ['parsed' => $parsed]);
                return $this->fallback($context, 'schema_invalid');
            }

            return [
                'success'           => true,
                'blueprint'         => $bp,
                'reasoning_summary' => mb_substr((string) ($parsed['reasoning_summary'] ?? $bp['intent']), 0, 400),
                'model'             => $res['model'] ?? 'runtime-chat',
                'fallback'          => false,
            ];
        } catch (\Throwable $e) {
            Log::warning('[ImageIntelligence] reasoning threw; degrading to fallback', ['error' => $e->getMessage()]);
            return $this->fallback($context, 'reasoning_threw');
        }
    }

    /** Build the reasoning system + user prompts from the context. */
    private function buildPrompt(array $c): array
    {
        $platform   = $c['platform'] ?? 'null';
        $assetType  = $c['asset_type'] ?? 'social_post';
        $source     = $c['source'] ?? 'studio';
        $brand      = $c['brand'] ?? [];
        $hasBrand   = !empty($brand['brand_name']) || !empty($brand['colors']) || !empty($brand['visual_style']);
        $textPref   = $c['include_text_preference'] ?? 'auto'; // auto | require_text | no_text
        $lang       = $c['language'] ?? 'en';

        $brandJson = $hasBrand ? json_encode($brand, JSON_UNESCAPED_SLASHES) : 'NONE (no brand kit configured — use a sensible neutral strategy and set brand_application to note that no brand context was available)';

        $system = "You are Arthur, Level Up Growth's senior AI creative director and image prompt engineer. "
            . "You transform a short customer request into a rigorous, production-ready IMAGE BLUEPRINT for the OpenAI gpt-image-1 model. "
            . "You reason about intent, destination platform, audience, campaign objective, brand, composition, subject matter, historical/factual accuracy, lighting, colour, and typography. "
            . "You NEVER pass the user's sentence through unchanged. You output STRICT JSON only — no prose, no markdown.\n\n"
            . "PLATFORM RULES (choose aspect_ratio + dimensions to fit the platform and asset_type):\n"
            . "- linkedin: professional editorial tone, business audience, strong scroll-stopping hook, portrait 4:5 feed (1024x1536) unless a landscape hero is requested.\n"
            . "- instagram: visual-impact first, mobile-safe, 4:5 portrait (1024x1536) or 1:1 square (1024x1024), concise.\n"
            . "- facebook: broad readability, feed-safe, 1:1 or landscape (1536x1024).\n"
            . "- x: landscape 16:9 (1536x1024), punchy.\n"
            . "- pinterest: vertical (1024x1536), title-safe top area, strong visual-search relevance.\n"
            . "- null / seo / blog: subject clarity, wide featured-image (1536x1024) by default, NO baked-in text by default, search-preview suitable.\n"
            . "gpt-image-1 ONLY supports these dimensions: 1024x1024 (1:1), 1024x1536 (2:3 portrait), 1536x1024 (3:2 landscape). Map every aspect_ratio to the nearest of these.\n\n"
            . "TYPOGRAPHY POLICY — decide typography_strategy.mode per request:\n"
            . "- 'baked_in': the model renders short text INTO the image. Use ONLY for very short copy (a few words) when the user explicitly wants finished artwork with text and accepts that exact fidelity may vary.\n"
            . "- 'separate_overlay': the model renders a TEXT-FREE visual with deliberate negative space; exact copy + layout are returned separately to be rendered by Studio's real typography engine. PREFER this whenever exact spelling, substantial copy, or exact brand typography is required.\n"
            . "- 'none': no text at all. Use for SEO/blog featured images, visual-only requests, or when platform strategy calls for no embedded copy.\n"
            . "gpt-image-1 CANNOT reliably render long or multi-line text — never choose baked_in for more than a few words.\n"
            . "For the provider_prompt: if mode is 'separate_overlay' or 'none', you MUST instruct the model to include NO text/letters/words and to reserve clean negative space; if 'baked_in', embed the exact short headline in quotes.\n\n"
            . "Respond with ONLY this JSON object (all fields required):\n"
            . '{"intent":"","subject":"","audience":"","platform":"","asset_type":"","aspect_ratio":"","dimensions":{"width":0,"height":0},"composition":"","scene":"","visual_hierarchy":"","lighting":"","mood":"","color_palette":[],"brand_application":"","historical_or_factual_constraints":[],"negative_constraints":[],"typography_strategy":{"mode":"","reason":"","headline":"","supporting_copy":[],"placement":"","style":""},"provider_prompt":"","quality":"","reasoning_summary":""}';

        $user = "CUSTOMER REQUEST: \"" . trim((string) ($c['user_prompt'] ?? '')) . "\"\n\n"
            . "CONTEXT:\n"
            . "- source: {$source}\n"
            . "- platform: {$platform}\n"
            . "- asset_type: {$assetType}\n"
            . "- requested_dimensions: " . json_encode($c['requested_dimensions'] ?? null) . "\n"
            . "- requested_quality: " . ($c['requested_quality'] ?? 'auto') . "\n"
            . "- include_text_preference: {$textPref}\n"
            . "- language: {$lang}\n"
            . "- brand_kit: {$brandJson}\n"
            . ($assetType === 'featured_image' || $source === 'seo' || $source === 'blog'
                ? "- NOTE: this is an SEO/blog featured image — do NOT bake in text unless explicitly requested; prefer mode 'none' or 'separate_overlay'.\n"
                : "")
            . "\nProduce the IMAGE BLUEPRINT JSON. Reason about the destination platform, audience and objective; ground any historical/factual subject accurately; and choose typography_strategy.mode deliberately for this specific task.";

        return [$system, $user];
    }

    /** Coerce + strictly validate the model output into a blueprint; return null if unrecoverable. */
    private function normalizeAndValidate(array $p, array $context): ?array
    {
        // dimensions must be one of the supported sizes; coerce to nearest.
        $dims = $p['dimensions'] ?? [];
        $w = (int) ($dims['width'] ?? 0);
        $h = (int) ($dims['height'] ?? 0);
        [$w, $h, $ar] = $this->snapDimensions($w, $h, $p['aspect_ratio'] ?? '', $context);
        $p['dimensions']  = ['width' => $w, 'height' => $h];
        $p['aspect_ratio'] = $ar;

        // typography strategy mode must be valid
        $ts = $p['typography_strategy'] ?? [];
        $mode = strtolower((string) ($ts['mode'] ?? ''));
        if (!in_array($mode, ['baked_in', 'separate_overlay', 'none'], true)) {
            $mode = 'none';
        }
        $ts['mode'] = $mode;
        $ts['headline'] = (string) ($ts['headline'] ?? '');
        $ts['supporting_copy'] = array_values(array_filter((array) ($ts['supporting_copy'] ?? [])));
        $ts['placement'] = (string) ($ts['placement'] ?? '');
        $ts['style'] = (string) ($ts['style'] ?? '');
        $ts['reason'] = (string) ($ts['reason'] ?? '');
        $p['typography_strategy'] = $ts;

        // quality: honour requested_quality when explicitly set, else model's, else medium.
        $rq = strtolower((string) ($context['requested_quality'] ?? 'auto'));
        $q  = in_array($rq, ['low','medium','high'], true) ? $rq : strtolower((string) ($p['quality'] ?? 'medium'));
        if (!in_array($q, ['low','medium','high'], true)) $q = 'medium';
        $p['quality'] = $q;

        // arrays
        foreach (['color_palette','historical_or_factual_constraints','negative_constraints'] as $k) {
            $p[$k] = array_values(array_filter((array) ($p[$k] ?? [])));
        }
        // strings
        $p['platform']  = (string) ($p['platform'] ?? ($context['platform'] ?? 'null'));
        $p['asset_type'] = (string) ($p['asset_type'] ?? ($context['asset_type'] ?? 'social_post'));

        foreach (self::REQUIRED as $field) {
            if (!array_key_exists($field, $p)) return null;
        }
        if (trim((string) $p['provider_prompt']) === '' || trim((string) $p['subject']) === '') return null;

        $p['provider'] = 'openai';
        $p['model']    = 'gpt-image-1';
        return $p;
    }

    /** Snap arbitrary dimensions/aspect to gpt-image-1's three supported sizes. */
    private function snapDimensions(int $w, int $h, string $ar, array $context): array
    {
        // explicit requested dims win if supported
        $req = $context['requested_dimensions'] ?? null;
        if (is_array($req) && !empty($req['width']) && !empty($req['height'])) {
            $w = (int) $req['width']; $h = (int) $req['height'];
        }
        $arNum = ($w > 0 && $h > 0) ? $w / $h : $this->arToNum($ar);
        if ($arNum <= 0) $arNum = 1.0;
        if ($arNum < 0.85)      return [1024, 1536, '2:3'];  // portrait
        if ($arNum > 1.18)      return [1536, 1024, '3:2'];  // landscape
        return [1024, 1024, '1:1'];                          // square
    }

    private function arToNum(string $ar): float
    {
        if (preg_match('/(\d+)\s*[:x\/]\s*(\d+)/', $ar, $m) && (int) $m[2] > 0) {
            return (int) $m[1] / (int) $m[2];
        }
        $s = strtolower($ar);
        if (str_contains($s, 'portrait') || str_contains($s, 'vertical')) return 0.66;
        if (str_contains($s, 'landscape') || str_contains($s, 'wide') || str_contains($s, 'horizontal')) return 1.5;
        return 1.0;
    }

    /**
     * Explicit, documented fallback when reasoning is unavailable — NOT a raw
     * pass-through. Deterministically composes a rich, CATEGORY-AWARE,
     * BRAND-GROUNDED provider prompt so that when the reasoning model times out
     * the image is still professional, well-composed and on-brand (not a generic
     * stock visual). Studio-owned quality lever; no runtime dependency.
     */
    private function fallback(array $c, string $why): array
    {
        $prompt    = trim((string) ($c['user_prompt'] ?? ''));
        $platform  = (string) ($c['platform'] ?? '');
        $assetType = strtolower((string) ($c['asset_type'] ?? 'social_post'));
        $style     = strtolower((string) ($c['style'] ?? 'natural'));
        $isSeo     = in_array(($c['source'] ?? ''), ['seo','blog'], true) || $assetType === 'featured_image';
        [$w, $h, $ar] = $this->snapDimensions(0, 0, $isSeo ? 'landscape' : 'portrait', $c);

        // WP2 Phase 2.1D-B — brand (resolved + request-overridden) is never lost
        // when the LLM is unavailable.
        $brand  = is_array($c['brand'] ?? null) ? $c['brand'] : [];
        $colors = array_values(array_filter((array) ($brand['colors'] ?? [])));
        $voice  = trim((string) ($brand['voice'] ?? ''));
        $vstyle = trim((string) ($brand['visual_style'] ?? ''));

        // Category-aware framing is the biggest quality lever on the deterministic
        // path: a food photo, a menu background and a promotional poster each need
        // different composition, lighting and subject treatment.
        $cat         = $this->fallbackFraming($assetType, $platform);
        $styleClause = $this->fallbackStyle($style);

        // Brand-visual clause folded INTO the scene (palette + visual style) — as
        // composition/props/lighting, never as drawn text.
        $brandVisual = '';
        if ($colors) $brandVisual .= ' Incorporate the brand colour palette (' . implode(', ', array_slice($colors, 0, 4)) . ') naturally through props, surfaces, lighting and accents — never as text.';
        if ($vstyle) $brandVisual .= ' Overall visual style: ' . $vstyle . '.';

        // Compose the subject naturally, tolerating empty and verb-leading prompts.
        $subject   = $prompt !== '' ? $prompt : $cat['default_subject'];
        $directive = $prompt !== '' && (mb_strlen($prompt) > 120
            || (bool) preg_match('/^(create|generate|make|design|show|produce|depict|render|illustrate|draw|craft|compose|capture|build)\b/i', $prompt));

        $tail = sprintf(
            ' Composition: %s, with clean deliberate negative space reserved for text to be added later. Lighting: %s. Mood: %s.%s%s Ultra-detailed, photorealistic, professional %s quality, sharp focus, natural textures, high dynamic range. No text, no letters, no words, no logos, no watermark.',
            $cat['composition'], $cat['lighting'], $cat['mood'], $styleClause, $brandVisual, $cat['quality_word']
        );

        $providerPrompt = $directive
            ? ($subject . '. ' . $cat['enhance'] . $tail)
            : ($cat['lead'] . ' of ' . $subject . ', ' . $cat['scene_note'] . '.' . $tail);

        $bp = [
            'intent'     => 'Deterministic fallback (reasoning unavailable): ' . $why,
            'subject'    => $prompt !== '' ? $prompt : $cat['default_subject'],
            'audience'   => 'general business audience',
            'platform'   => $platform,
            'asset_type' => $assetType,
            'aspect_ratio' => $ar,
            'dimensions' => ['width' => $w, 'height' => $h],
            'composition' => $cat['composition'],
            'scene'      => $subject,
            'visual_hierarchy' => 'subject dominant; clean supporting background with reserved negative space',
            'lighting'   => $cat['lighting'],
            'mood'       => $cat['mood'] . ($voice ? '; brand voice ' . $voice : ''),
            'color_palette' => $colors,
            'brand_application' => $colors
                ? ('Brand palette applied through composition: ' . implode(', ', array_slice($colors, 0, 3)) . ($voice ? '; voice: ' . $voice : '') . ($vstyle ? '; style: ' . $vstyle : ''))
                : 'no brand context available — neutral professional treatment',
            'historical_or_factual_constraints' => [],
            'negative_constraints' => ['no text', 'no watermark', 'no logos', 'no distorted anatomy', 'no artificial-looking composites'],
            'typography_strategy' => ['mode' => 'none', 'reason' => 'fallback path — text handled by the Studio design layer', 'headline' => '', 'supporting_copy' => [], 'placement' => '', 'style' => ''],
            'provider_prompt' => $providerPrompt,
            'quality'    => in_array(strtolower((string) ($c['requested_quality'] ?? '')), ['low','medium','high'], true) ? strtolower((string) $c['requested_quality']) : 'medium',
            'provider'   => 'openai',
            'model'      => 'gpt-image-1',
        ];

        return ['success' => true, 'blueprint' => $bp, 'reasoning_summary' => 'FALLBACK: ' . $why, 'model' => 'fallback', 'fallback' => true];
    }

    /** Category-aware framing for the deterministic fallback (Studio-owned). */
    private function fallbackFraming(string $assetType, string $platform): array
    {
        $t = $assetType . ' ' . $platform;
        $has = fn(string ...$n) => (bool) array_filter($n, fn($x) => str_contains($t, $x));

        if ($has('food', 'dish', 'recipe', 'meal', 'cuisine')) return [
            'lead' => 'A close-up, appetising food photograph', 'default_subject' => 'a beautifully plated gourmet dish',
            'scene_note' => 'styled on matte ceramic over a dark rustic surface, with fresh garnish, a glossy sauce detail and soft background bokeh',
            'composition' => 'hero dish slightly off-centre on the rule of thirds', 'lighting' => 'soft directional window light with gentle falloff',
            'mood' => 'warm, premium, mouth-watering', 'quality_word' => 'editorial food-photography',
            'enhance' => 'Render it as a close-up, appetising, professionally styled food photograph.',
        ];
        if ($has('menu')) return [
            'lead' => 'An elegant, minimal fine-dining background', 'default_subject' => 'a refined tasting-menu backdrop',
            'scene_note' => 'abstract culinary textures with muted premium tones and generous empty space',
            'composition' => 'generous negative space in the upper two-thirds for menu text', 'lighting' => 'soft, even studio light',
            'mood' => 'refined, understated, upscale', 'quality_word' => 'fine-dining art-direction',
            'enhance' => 'Render it as a refined, minimal fine-dining background with generous empty space.',
        ];
        if ($has('poster', 'promotion', 'promo', 'advertisement', 'ad', 'flyer', 'campaign')) return [
            'lead' => 'A bold, eye-catching promotional key visual', 'default_subject' => 'a striking promotional hero scene',
            'scene_note' => 'a dynamic focal subject with strong depth, dramatic contrast and room for a headline',
            'composition' => 'a bold rule-of-thirds subject with strong leading lines', 'lighting' => 'dramatic high-contrast light with a bright key',
            'mood' => 'confident, energetic, premium', 'quality_word' => 'advertising-campaign',
            'enhance' => 'Render it as a bold, high-impact advertising key visual with room for a headline.',
        ];
        if ($has('story', 'reel', 'vertical', 'tiktok')) return [
            'lead' => 'A vertical, lifestyle-led hero image', 'default_subject' => 'an immersive vertical lifestyle scene',
            'scene_note' => 'immersive full-bleed vertical framing with depth and atmosphere',
            'composition' => 'a strong vertical composition with the subject in the lower-middle third', 'lighting' => 'natural, atmospheric light',
            'mood' => 'immersive, authentic, aspirational', 'quality_word' => 'social-lifestyle',
            'enhance' => 'Render it as an immersive, full-bleed vertical lifestyle image.',
        ];
        if ($has('cover', 'banner', 'header')) return [
            'lead' => 'A wide, cinematic brand banner', 'default_subject' => 'an atmospheric wide brand scene',
            'scene_note' => 'wide panoramic framing with the subject to one side and calm background on the other',
            'composition' => 'wide cinematic framing with the subject offset to one third', 'lighting' => 'soft cinematic light',
            'mood' => 'calm, established, trustworthy', 'quality_word' => 'brand-banner',
            'enhance' => 'Render it as a wide, cinematic brand banner with offset composition.',
        ];
        if ($has('portrait', 'headshot', 'team', 'staff', 'founder')) return [
            'lead' => 'A professional environmental portrait', 'default_subject' => 'a confident professional in their working environment',
            'scene_note' => 'shallow depth of field, flattering soft light and an authentic setting',
            'composition' => 'a head-and-shoulders subject framed slightly off-centre', 'lighting' => 'a soft flattering key with gentle fill',
            'mood' => 'approachable, credible, human', 'quality_word' => 'editorial-portrait',
            'enhance' => 'Render it as a flattering, professional environmental portrait.',
        ];
        // default: social post / product hero
        return [
            'lead' => 'A polished, scroll-stopping social hero image', 'default_subject' => 'an on-brand hero product scene',
            'scene_note' => 'a clean styled surface with considered props and soft background separation',
            'composition' => 'a single clear focal subject on the rule of thirds', 'lighting' => 'soft professional studio light',
            'mood' => 'polished, premium, inviting', 'quality_word' => 'commercial social-media',
            'enhance' => 'Render it as a polished, scroll-stopping social hero image.',
        ];
    }

    /** Style modifier for the deterministic fallback (Studio-owned). */
    private function fallbackStyle(string $style): string
    {
        return match ($style) {
            'cinematic' => ' Cinematic colour grade, shallow depth of field, film-like tonality.',
            'minimal'   => ' Minimal styling, lots of clean negative space, restrained neutral palette.',
            'bold'      => ' High contrast, vibrant saturated colour, strong graphic composition.',
            'elegant'   => ' Soft refined lighting, luxury aesthetic, delicate premium details.',
            'editorial' => ' Magazine-editorial styling, crisp focus, considered art-direction.',
            'vibrant'   => ' Bright, vivid, punchy colour with lively energy.',
            default     => ' Clean, natural, professional styling.',
        };
    }
}
