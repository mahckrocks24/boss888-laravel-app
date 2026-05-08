<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SceneValidatorService — Creative888 Scene Validator
 *
 * Ported from WP class-lucreative-scene-validator.php.
 *
 * After an image is generated and downloaded, the validator uses GPT-4o Vision
 * to compare the generated image against the original scene blueprint and flag
 * geometry mismatches.
 *
 * Validation checks:
 *   - camera_angle_match
 *   - window_alignment
 *   - floor_direction
 *   - wall_layout
 *   - ceiling_match
 *
 * Pass threshold: score >= 0.55 (lenient geometry validation).
 */
class SceneValidatorService
{
    /**
     * Validate a generated image against an original scene blueprint.
     *
     * @param  string $generatedUrl  Public URL of the generated image.
     * @param  array  $blueprint     Scene blueprint from geometry analysis.
     * @return array  { pass: bool, score: float, issues: array, skipped: bool }
     */
    public function validate(string $generatedUrl, array $blueprint): array
    {
        if (($blueprint['confidence'] ?? 0) < 0.2) {
            return ['pass' => true, 'score' => 1.0, 'issues' => [], 'skipped' => true, 'reason' => 'Blueprint confidence too low to validate.'];
        }

        if (empty($generatedUrl)) {
            return ['pass' => true, 'score' => 1.0, 'issues' => [], 'skipped' => true, 'reason' => 'No URL — validation skipped.'];
        }

        try {
            return $this->validateWithVision($generatedUrl, $blueprint);
        } catch (\Throwable $e) {
            Log::warning('[CREATIVE888 SceneValidator] Validation failed: ' . $e->getMessage());
            return ['pass' => true, 'score' => 0.5, 'issues' => [], 'skipped' => true, 'reason' => $e->getMessage()];
        }
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function validateWithVision(string $generatedUrl, array $blueprint): array
    {
        // PATCH 4 (2026-05-08): vision now via RuntimeClient (was direct
        // Http::post to api.openai.com — hands-vs-brain bypass).
        $runtime = app(\App\Connectors\RuntimeClient::class);
        if (!$runtime->isConfigured()) {
            return ['pass' => true, 'score' => 1.0, 'issues' => [], 'skipped' => true, 'reason' => 'Runtime not configured — validation skipped.'];
        }

        $expected = $this->blueprintToExpectationText($blueprint);

        $prompt = "You are a geometry consistency checker for AI-generated interior images.

EXPECTED geometry from the original image:
{$expected}

Look at the provided generated image and check:
1. Camera angle (is it the same: {$blueprint['camera_angle']}?)
2. Window positions (check each listed window is in the same position)
3. Floor direction ({$blueprint['floor_direction']})
4. Wall layout ({$blueprint['wall_layout']})
5. Ceiling type ({$blueprint['ceiling_type']})

Return ONLY valid JSON (no markdown) in this exact format:
{
  \"camera_angle_match\": true,
  \"window_alignment\": true,
  \"floor_direction_match\": true,
  \"wall_layout_match\": true,
  \"ceiling_match\": true,
  \"overall_score\": 0.0,
  \"issues\": [\"list of specific mismatch descriptions\"],
  \"note\": \"one sentence\"
}
overall_score: 0.0 (complete mismatch) to 1.0 (perfect match).
Be strict. If the camera moved significantly or windows shifted, mark as false.";

        $resp = $runtime->visionAnalyze($prompt, '', $generatedUrl);

        if (empty($resp['success'])) {
            throw new \RuntimeException('Validation runtime error: ' . ($resp['error'] ?? 'unknown'));
        }

        $content = (string) ($resp['analysis'] ?? '');
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);
        $result  = json_decode($content, true);

        if (!is_array($result)) {
            return ['pass' => true, 'score' => 0.5, 'issues' => [], 'skipped' => true, 'reason' => 'Could not parse validation response.'];
        }

        $score  = min(1.0, max(0.0, (float) ($result['overall_score'] ?? 0.5)));
        $issues = is_array($result['issues'] ?? null) ? $result['issues'] : [];

        // Pass threshold: score >= 0.55
        $pass = ($score >= 0.55);

        return [
            'pass'    => $pass,
            'score'   => $score,
            'issues'  => $issues,
            'skipped' => false,
            'detail'  => $result,
        ];
    }

    private function blueprintToExpectationText(array $bp): string
    {
        $lines = [];
        if (!empty($bp['camera_angle']))    $lines[] = '- Camera angle: ' . $bp['camera_angle'];
        if (!empty($bp['wall_layout']))     $lines[] = '- Wall layout: ' . $bp['wall_layout'];
        if (!empty($bp['ceiling_type']))    $lines[] = '- Ceiling: ' . $bp['ceiling_type'];
        if (!empty($bp['floor_direction'])) $lines[] = '- Floor: ' . $bp['floor_direction'];
        if (!empty($bp['dominant_light']))  $lines[] = '- Lighting: ' . $bp['dominant_light'];

        if (!empty($bp['windows'])) {
            foreach ($bp['windows'] as $w) {
                $lines[] = '- Window at: ' . ($w['position'] ?? 'unknown') . ' (' . ($w['approximate_size'] ?? '') . ')';
            }
        }
        if (!empty($bp['doors'])) {
            foreach ($bp['doors'] as $d) {
                $lines[] = '- Door at: ' . ($d['position'] ?? 'unknown');
            }
        }
        if (!empty($bp['fixed_objects'])) {
            $lines[] = '- Fixed objects: ' . implode(', ', array_slice($bp['fixed_objects'], 0, 3));
        }

        return implode("\n", $lines);
    }

    // getOpenAiKey() removed PATCH 4 (2026-05-08) — auth handled by runtime.
}
