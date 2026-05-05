<?php

namespace App\Engines\Studio\Services;

use App\Connectors\DeepSeekConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * StudioAiService — Arthur design intelligence (Phase 4).
 *
 * Endpoints this powers:
 *   POST /api/studio/ai/generate-design     → generateDesign()
 *   POST /api/studio/ai/generate-image      → generateImage()
 *   POST /api/studio/ai/suggest-copy        → suggestCopy()
 *   POST /api/studio/ai/chat                → chat()
 *
 * All LLM calls use DeepSeekConnector::chatJson() directly. All image calls
 * go direct to OpenAI DALL-E 3. Every method checks for the required API key
 * and returns a structured "api_key_missing" error instead of throwing.
 */
class StudioAiService
{
    public function __construct(
        private DeepSeekConnector $llm,
        private StudioService     $studio,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    // GENERATE-DESIGN
    // ═══════════════════════════════════════════════════════════════════

    public function generateDesign(int $wsId, array $params): array
    {
        if (!$this->llm->isConfigured()) {
            return ['success' => false, 'error' => 'api_key_missing', 'message' => 'DEEPSEEK_API_KEY not set in .env'];
        }

        $prompt    = (string) ($params['prompt']    ?? 'Modern social media post');
        $format    = (string) ($params['format']    ?? 'square');
        $style     = (string) ($params['style']     ?? 'bold');
        $brand     = $this->studio->getBrandKit($wsId);

        // Resolve canvas dims from format if not given explicitly
        $formats = StudioService::FORMATS;
        $dims    = $formats[$format] ?? $formats['square'];
        $W = (int) ($params['canvas_width']  ?? $dims['w']);
        $H = (int) ($params['canvas_height'] ?? $dims['h']);

        $templateList = $this->buildTemplateDigest();
        if (empty($templateList)) {
            return ['success' => false, 'error' => 'no_templates', 'message' => 'No templates with semantic profiles available'];
        }

        $system = "You are Arthur, an expert creative director with 20 years of experience designing social media content. "
                . "You understand hierarchy, contrast, whitespace, color theory, and typography. "
                . "You create designs that look professional, modern, and on-brand. "
                . "Return valid JSON only, no markdown.";

        $user = "Create a {$format} social media design ({$W}x{$H}px) for this request:\n\n"
              . "REQUEST: {$prompt}\n\n"
              . "STYLE PREFERENCE: {$style}\n\n"
              . "BRAND:\n"
              . "Name: " . ($brand['brand_name'] ?: '(unspecified)') . "\n"
              . "Primary color: " . ($brand['primary_color'] ?? '#6C5CE7') . "\n"
              . "Secondary color: " . ($brand['secondary_color'] ?? '#00E5A8') . "\n"
              . "Heading font: " . ($brand['heading_font'] ?? 'Syne') . "\n"
              . "Has logo: " . (!empty($brand['logo_url']) ? 'yes' : 'no') . "\n\n"
              . "AVAILABLE TEMPLATES (pick the best fit — NOT by industry but by mood, purpose, and composition):\n"
              . $templateList . "\n\n"
              . "Return JSON:\n"
              . "{\n"
              . '  "template_slug": "<exact slug from list>",' . "\n"
              . '  "reasoning": "<one sentence why this template fits>",' . "\n"
              . '  "fields": { "<field_name>": "<generated value>", ... },' . "\n"
              . '  "hero_image_prompt": "<DALL-E prompt if a hero image is needed, or null>",' . "\n"
              . '  "brand_colors_applied": true,' . "\n"
              . '  "suggested_copy": { "headline": "...", "subheadline": "...", "cta": "..." }' . "\n"
              . "}";

        $result = $this->llm->chatJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ], ['temperature' => 0.8, 'max_tokens' => 2000]);

        if (empty($result['success']) || empty($result['parsed'])) {
            return ['success' => false, 'error' => 'ai_failed', 'message' => $result['error'] ?? ($result['parse_error'] ?? 'parse failed'), 'raw' => $result['content'] ?? null];
        }

        $parsed = $result['parsed'];
        $slug   = (string) ($parsed['template_slug'] ?? '');

        // Verify slug is real — fall back to first available if Arthur picked something that doesn't exist
        $manifests = $this->loadAllManifests();
        if (!isset($manifests[$slug])) {
            $slug = array_key_first($manifests);
            $parsed['reasoning'] = ($parsed['reasoning'] ?? '') . ' (Template fallback.)';
        }

        // Create a blank design in the chosen format
        $design = $this->studio->createDesign($wsId, [
            'name'   => Str::limit($prompt, 50),
            'format' => $format,
            'canvas_width'  => $W,
            'canvas_height' => $H,
            'template_category' => $manifests[$slug]['category'] ?? null,
        ]);
        $designId = $design['design_id'];

        // Merge generated fields with manifest defaults and persist as metadata
        DB::table('studio_designs')->where('id', $designId)->update([
            'template_id'       => $manifests[$slug]['id'] ?? null,
            'content_html'      => json_encode([
                'template_slug' => $slug,
                'fields'        => $parsed['fields'] ?? [],
                'arthur_meta'   => [
                    'reasoning'       => $parsed['reasoning'] ?? '',
                    'suggested_copy'  => $parsed['suggested_copy'] ?? [],
                    'hero_prompt'     => $parsed['hero_image_prompt'] ?? null,
                    'generated_at'    => now()->toIso8601String(),
                ],
            ]),
            'updated_at' => now(),
        ]);

        return [
            'success'        => true,
            'design_id'      => $designId,
            'template_slug'  => $slug,
            'reasoning'      => $parsed['reasoning'] ?? '',
            'fields'         => $parsed['fields'] ?? [],
            'suggested_copy' => $parsed['suggested_copy'] ?? [],
            'hero_image_prompt' => $parsed['hero_image_prompt'] ?? null,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // GENERATE-IMAGE (DALL-E 3)
    // ═══════════════════════════════════════════════════════════════════

    public function generateImage(int $wsId, array $params): array
    {
        $key = (string) env('OPENAI_API_KEY', '');
        if ($key === '') {
            return ['success' => false, 'error' => 'api_key_missing', 'message' => 'OPENAI_API_KEY not set in .env'];
        }

        $userPrompt = (string) ($params['prompt'] ?? '');
        if (trim($userPrompt) === '') return ['success' => false, 'error' => 'prompt_required'];
        $style      = (string) ($params['style'] ?? 'cinematic');

        $styleAdd = match ($style) {
            'cinematic' => 'Cinematic lighting, shallow depth of field, color graded.',
            'minimal'   => 'Clean minimal aesthetic, lots of whitespace, neutral palette.',
            'bold'      => 'High contrast, vibrant colors, strong composition.',
            'elegant'   => 'Soft lighting, luxury aesthetic, refined details.',
            'editorial' => 'Magazine editorial style, sharp focus.',
            default     => 'High quality, professional.',
        };
        $fullPrompt = "Professional {$style} image: {$userPrompt}. {$styleAdd} "
                    . "No text, no logos, no watermarks, social media ready.";

        try {
            $resp = Http::withToken($key)->timeout(90)->post('https://api.openai.com/v1/images/generations', [
                'model'   => 'dall-e-3',
                'prompt'  => $fullPrompt,
                'size'    => '1024x1024',
                'quality' => 'standard',
                'n'       => 1,
            ]);
            if (!$resp->successful()) {
                Log::warning('dall-e call failed', ['status' => $resp->status(), 'body' => $resp->body()]);
                return ['success' => false, 'error' => 'dalle_failed', 'message' => 'DALL-E returned ' . $resp->status()];
            }
            $body = $resp->json();
            $url  = $body['data'][0]['url'] ?? null;
            if (!$url) return ['success' => false, 'error' => 'no_image_url'];

            // Download + save locally
            $dir = storage_path('app/public/ai-generated/' . $wsId);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $hash = substr(md5($fullPrompt . microtime(true)), 0, 12);
            $path = $dir . '/' . $hash . '.png';
            $png  = Http::timeout(60)->get($url);
            if (!$png->successful()) return ['success' => false, 'error' => 'download_failed'];
            file_put_contents($path, $png->body());
            $publicUrl = '/storage/ai-generated/' . $wsId . '/' . $hash . '.png';

            // Insert into media library if table exists
            if (\Schema::hasTable('media')) {
                $mediaCols = [
                    'workspace_id' => $wsId,
                    'url'          => $publicUrl,
                    'asset_type'   => 'image',
                    'mime_type'    => 'image/png',
                    'width'        => 1024,
                    'height'       => 1024,
                    'source'       => 'studio-ai',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ];
                // Filter by existing columns
                $exists = \Schema::getColumnListing('media');
                $insert = array_intersect_key($mediaCols, array_flip($exists));
                try { DB::table('media')->insert($insert); } catch (\Throwable $e) {}
            }

            return ['success' => true, 'image_url' => $publicUrl, 'width' => 1024, 'height' => 1024];
        } catch (\Throwable $e) {
            Log::error('studio.ai.generateImage', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // SUGGEST-COPY
    // ═══════════════════════════════════════════════════════════════════

    public function suggestCopy(int $wsId, array $params): array
    {
        if (!$this->llm->isConfigured()) {
            return ['success' => false, 'error' => 'api_key_missing', 'message' => 'DEEPSEEK_API_KEY not set'];
        }
        $context    = (string) ($params['context']    ?? '');
        $fieldType  = (string) ($params['field_type'] ?? 'headline');
        $brand      = $this->studio->getBrandKit($wsId);
        $brandName  = (string) ($params['brand_name'] ?? $brand['brand_name'] ?? 'Your Brand');
        $industry   = (string) ($params['industry']   ?? 'general');
        $tone       = (string) ($params['tone']       ?? 'professional');

        $system = "You are an expert copywriter. Return JSON only, no markdown.";
        $user   = "Write 5 different {$fieldType} options for {$brandName} ({$industry}).\n"
                . "Tone: {$tone}.\n"
                . "Context: {$context}\n\n"
                . "Return: " . '{"suggestions":["...","...","...","...","..."]}';

        $result = $this->llm->chatJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ], ['temperature' => 0.85, 'max_tokens' => 500]);

        if (empty($result['success']) || empty($result['parsed']['suggestions'])) {
            return ['success' => false, 'error' => 'ai_failed', 'message' => $result['error'] ?? 'parse failed'];
        }
        return ['success' => true, 'suggestions' => array_values($result['parsed']['suggestions'])];
    }

    // ═══════════════════════════════════════════════════════════════════
    // CHAT (context-aware design editing)
    // ═══════════════════════════════════════════════════════════════════

    public function chat(int $wsId, array $params): array
    {
        if (!$this->llm->isConfigured()) {
            return ['success' => false, 'error' => 'api_key_missing', 'message' => 'DEEPSEEK_API_KEY not set'];
        }
        $message    = (string) ($params['message'] ?? '');
        $designId   = (int)    ($params['design_id'] ?? 0);
        $selected   = $params['selected_element_id'] ?? null;
        $state      = $params['current_design_state'] ?? [];

        $brand = $this->studio->getBrandKit($wsId);

        $system = "You are Arthur, an AI design assistant. When the user asks you to change a design, "
                . "return JSON with an `actions` array and a friendly `reply` string. "
                . "Supported actions: update_field (field, value), apply_palette (vars {var:color}), "
                . "generate_image (prompt, target_field), add_text (content, style {font_size,color}), "
                . "message (text). Always ground palette choices in the user's brand colors when relevant. "
                . "Return JSON only.";

        $user = "DESIGN STATE: " . json_encode($state) . "\n"
              . "SELECTED ELEMENT: " . ($selected !== null ? json_encode($selected) : 'none') . "\n"
              . "BRAND: primary=" . ($brand['primary_color'] ?? '#6C5CE7') . " secondary=" . ($brand['secondary_color'] ?? '#00E5A8') . " heading_font=" . ($brand['heading_font'] ?? 'Syne') . "\n"
              . "USER MESSAGE: {$message}\n\n"
              . 'Return JSON: {"actions":[...],"reply":"..."}';

        $result = $this->llm->chatJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ], ['temperature' => 0.6, 'max_tokens' => 1000]);

        if (empty($result['success']) || empty($result['parsed'])) {
            return ['success' => false, 'error' => 'ai_failed', 'message' => $result['error'] ?? 'parse failed', 'raw' => $result['content'] ?? null];
        }
        $parsed = $result['parsed'];
        return [
            'success' => true,
            'actions' => $parsed['actions'] ?? [],
            'reply'   => (string) ($parsed['reply'] ?? 'Done.'),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // INTERNALS
    // ═══════════════════════════════════════════════════════════════════

    /** Load all template manifests that have a semantic profile. Indexed by slug. */
    private function loadAllManifests(): array
    {
        $dir = storage_path('templates/studio');
        $out = [];
        if (!is_dir($dir)) return $out;
        foreach (scandir($dir) as $slug) {
            if ($slug === '.' || $slug === '..') continue;
            $mf = $dir . '/' . $slug . '/manifest.json';
            if (!is_file($mf)) continue;
            $d = json_decode(file_get_contents($mf), true);
            if (!is_array($d) || empty($d['semantic'])) continue;
            $d['slug'] = $slug;
            $out[$slug] = $d;
        }
        return $out;
    }

    /** Compact digest for prompt — one line per template with mood/best_for/layout/instructions. */
    private function buildTemplateDigest(): string
    {
        $mfs = $this->loadAllManifests();
        if (!$mfs) return '';
        $lines = [];
        foreach ($mfs as $slug => $d) {
            $s = $d['semantic'];
            $lines[] = '- ' . $slug
                     . ' | mood:' . implode(',', $s['mood'] ?? [])
                     . ' | best_for:' . implode(',', array_slice($s['best_for'] ?? [], 0, 4))
                     . ' | layout:' . ($s['layout_type'] ?? 'centered')
                     . ' | use: ' . Str::limit($s['arthur_instructions'] ?? '', 90);
        }
        return implode("\n", $lines);
    }
}
