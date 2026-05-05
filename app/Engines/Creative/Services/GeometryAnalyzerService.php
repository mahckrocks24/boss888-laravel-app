<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GeometryAnalyzerService — Creative888 Geometry Analyzer Layer
 *
 * Ported from WP class-lucreative-geometry-analyzer.php.
 *
 * Extracts spatial layout information from an uploaded image using GPT-4o Vision
 * and injects structured geometry constraints into the final generation prompt.
 *
 * This is an ADDITIVE layer — it does not modify existing generation logic.
 * It runs BEFORE the generation call and enriches the prompt only.
 *
 * Caching: Laravel Cache, 8-minute TTL, keyed by image URL hash.
 * Fail-safe: any error returns empty array — generation always continues.
 */
class GeometryAnalyzerService
{
    const CACHE_TTL_MINUTES = 8;

    /**
     * Analyze image geometry using GPT-4o Vision.
     * Returns structured JSON array describing spatial layout.
     * Caches result for 8 minutes per unique image URL.
     * Fails silently — always returns an array.
     */
    public function analyzeGeometry(string $imageUrl): array
    {
        if (empty($imageUrl)) {
            return ['room_type' => 'unknown', '_source' => 'empty_url'];
        }

        // Cache check
        $cacheKey = 'creative_geo_' . md5($imageUrl);
        $cached   = Cache::get($cacheKey);
        if (is_array($cached) && !empty($cached)) {
            Log::debug('[CREATIVE888 Geometry] Cache hit for: ' . substr($imageUrl, -40));
            return $cached;
        }

        // Get API key from platform_settings
        $apiKey = $this->getOpenAiKey();
        if (empty($apiKey)) {
            Log::info('[CREATIVE888 Geometry] No API key — skipping vision analysis.');
            return ['room_type' => 'unknown', '_source' => 'no_api_key'];
        }

        // Vision prompt
        $visionPrompt = 'Analyze this image and return ONLY structured JSON with:
{
  "room_type": "",
  "camera_angle": "front | angled left | angled right | top-down",
  "layout_type": "single wall | corner | open space | corridor",
  "windows": [ {"position": "left|center|right", "size": "small|medium|large"} ],
  "doors": [ {"position": "left|center|right", "size": "small|medium|large"} ],
  "fixed_elements": ["sofa", "bed", "table", "none"],
  "notes": "short spatial description only"
}

Rules:
- NO design suggestions
- NO styling
- ONLY geometry and structure';

        try {
            $response = Http::timeout(6)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'      => 'gpt-4o',
                    'max_tokens' => 500,
                    'messages'   => [
                        [
                            'role'    => 'user',
                            'content' => [
                                [
                                    'type'      => 'image_url',
                                    'image_url' => [
                                        'url'    => $imageUrl,
                                        'detail' => 'low',
                                    ],
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $visionPrompt,
                                ],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                $err = $response->json('error.message', 'HTTP ' . $response->status());
                Log::warning('[CREATIVE888 Geometry] Vision API error: ' . $err);
                return ['room_type' => 'unknown', '_source' => 'api_error', '_error' => $err];
            }

            $content = $response->json('choices.0.message.content', '');

            // Strip markdown fences if model added them
            $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
            $content = preg_replace('/\s*```$/m', '', $content);

            $geo = json_decode($content, true);
            if (!is_array($geo)) {
                Log::warning('[CREATIVE888 Geometry] Non-JSON response: ' . substr($content, 0, 150));
                $geo = ['room_type' => 'unknown', '_source' => 'parse_fail'];
            } else {
                $geo['_source'] = 'vision';
                Log::debug('[CREATIVE888 Geometry] Analysis complete: room=' . ($geo['room_type'] ?? '?') . ' camera=' . ($geo['camera_angle'] ?? '?'));
            }

            // Cache result
            Cache::put($cacheKey, $geo, now()->addMinutes(self::CACHE_TTL_MINUTES));

            return $geo;

        } catch (\Throwable $e) {
            Log::warning('[CREATIVE888 Geometry] Vision call failed: ' . $e->getMessage());
            return ['room_type' => 'unknown', '_source' => 'exception'];
        }
    }

    /**
     * Convert geometry data array into a structured constraint block for AI prompt.
     * Returns empty string if no meaningful data.
     */
    public function buildGeometryPrompt(array $geo): string
    {
        if (empty($geo) || (($geo['room_type'] ?? '') === 'unknown' && empty($geo['camera_angle']))) {
            return '';
        }

        $room    = $geo['room_type'] ?? 'interior space';
        $camera  = $geo['camera_angle'] ?? '';
        $layout  = $geo['layout_type'] ?? '';
        $notes   = $geo['notes'] ?? '';
        $windows = $geo['windows'] ?? [];
        $doors   = $geo['doors'] ?? [];
        $fixed   = $geo['fixed_elements'] ?? [];

        // Build window descriptions
        $winLines = [];
        foreach ($windows as $w) {
            if (!empty($w['position'])) {
                $size = !empty($w['size']) ? ' (' . $w['size'] . ')' : '';
                $winLines[] = '  - ' . $w['position'] . ' window' . $size;
            }
        }

        // Build door descriptions
        $doorLines = [];
        foreach ($doors as $d) {
            if (!empty($d['position'])) {
                $size = !empty($d['size']) ? ' (' . $d['size'] . ')' : '';
                $doorLines[] = '  - ' . $d['position'] . ' door' . $size;
            }
        }

        // Build fixed elements list
        $fixedClean = array_filter($fixed, fn ($el) => !empty($el) && strtolower($el) !== 'none');

        // Assemble the block
        $block  = "========================================\n";
        $block .= "GEOMETRY ANALYSIS — EXTRACTED FROM SOURCE IMAGE\n";
        $block .= "========================================\n";
        $block .= "Room type:      " . ucfirst($room) . "\n";

        if ($camera)  $block .= "Camera angle:   " . $camera . "\n";
        if ($layout)  $block .= "Layout:         " . $layout . "\n";
        if ($notes)   $block .= "Spatial notes:  " . $notes . "\n";

        if (!empty($winLines)) {
            $block .= "Windows detected:\n" . implode("\n", $winLines) . "\n";
        } else {
            $block .= "Windows:        none detected\n";
        }

        if (!empty($doorLines)) {
            $block .= "Doors detected:\n" . implode("\n", $doorLines) . "\n";
        } else {
            $block .= "Doors:          none detected\n";
        }

        if (!empty($fixedClean)) {
            $block .= "Fixed elements: " . implode(', ', array_slice($fixedClean, 0, 4)) . "\n";
        }

        $block .= "\n";
        $block .= "STRICT GEOMETRY ENFORCEMENT:\n";
        $block .= "All windows and doors listed above MUST remain in the EXACT same\n";
        $block .= "position, size, and orientation in the output image.\n";
        $block .= "Camera angle and perspective MUST remain IDENTICAL to the source.\n";
        $block .= "Room layout and spatial geometry MUST NOT change in any way.\n";
        $block .= "Violation of any geometry constraint is a generation failure.\n";
        $block .= "========================================";

        return $block;
    }

    /**
     * Run geometry analysis and inject the constraint block into the final prompt.
     * If analysis fails, original prompt is returned unchanged.
     */
    public function injectGeometry(string $prompt, string $imageUrl): string
    {
        $geo      = $this->analyzeGeometry($imageUrl);
        $geoBlock = $this->buildGeometryPrompt($geo);

        if (empty($geoBlock)) {
            return $prompt;
        }

        // Find injection point: before USER PREFERENCES or AUTO MODE markers
        $injectionMarkers = ['USER PREFERENCES:', 'AUTO MODE:'];

        foreach ($injectionMarkers as $marker) {
            $pos = strpos($prompt, $marker);
            if ($pos !== false) {
                $prompt = substr($prompt, 0, $pos)
                    . $geoBlock . "\n\n"
                    . substr($prompt, $pos);
                Log::debug('[CREATIVE888 Geometry] Injected at marker: ' . $marker);
                return $prompt;
            }
        }

        // Fallback: append at end
        $prompt .= "\n\n" . $geoBlock;
        Log::debug('[CREATIVE888 Geometry] Injected at end (no marker found).');
        return $prompt;
    }

    /**
     * Get OpenAI API key from platform_settings table.
     */
    private function getOpenAiKey(): string
    {
        try {
            $row = DB::table('platform_settings')
                ->where('key', 'openai_api_key')
                ->first();

            return $row->value ?? '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
