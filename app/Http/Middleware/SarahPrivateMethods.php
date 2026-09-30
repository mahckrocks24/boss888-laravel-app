<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECRET-1 (Owner 2026-09-27): "we do not want the users to see the intelligence. It's a hidden secret of sarah. Just make
 * sure she uses the design and brand guideline, never the codes nor the prompts."
 *
 * The net under every customer API response (the admin console is exempt): the prompts Sarah and the image system write,
 * the internal style codes and recipes, and the reasoning behind an image never reach a browser. Sources are also fixed
 * at their origin (plan-preview, media library, inspiration card); this guard catches anything added later.
 */
class SarahPrivateMethods
{
    /** Keys that are always private. */
    private const PRIVATE_KEYS = ['provider_prompt', 'enhanced_prompt', 'revised_prompt', 'reasoning_summary', 'design_direction', 'design_direction_id',
        'inspiration_ids', 'prompt_skeleton', 'recipe', 'directions_json', 'analysis_json', 'typography_strategy', 'historical_or_factual_constraints',
        'brand_context', 'scene_prompt', 'style_additions', 'prompt_template', 'recipe_json', 'painter_prompt'];   // DESIGN-LIBRARY-2   // VIDEO-F1 (2026-09-27): the video recipe

    private const FAST = '/"(provider_prompt|enhanced_prompt|revised_prompt|reasoning_summary|design_direction(_id)?|inspiration_ids|prompt_skeleton|recipe|directions_json|analysis_json|typography_strategy|historical_or_factual_constraints|prompt|brand_context|scene_prompt|style_additions|prompt_template|recipe_json|painter_prompt)\\\\?"\s*:/';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        try {
            if (! $request->is('api/*') || $request->is('api/admin/*')) return $response;
            if (stripos((string) $response->headers->get('Content-Type', ''), 'json') === false) return $response;
            $raw = (string) $response->getContent();
            if ($raw === '' || ! preg_match(self::FAST, $raw)) return $response;
            $data = json_decode($raw, true);
            if (! is_array($data)) return $response;
            $removed = 0;
            $clean = $this->walk($data, $removed);
            if ($removed > 0) {
                $response->setContent(json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                Log::info('[SECRET-1] private method detail removed from a customer response', ['path' => $request->path(), 'removed' => $removed]);
            }
        } catch (\Throwable $e) {
            Log::warning('[SECRET-1] guard skipped: ' . $e->getMessage());
        }
        return $response;
    }

    private function walk(array $node, int &$removed): array
    {
        // a stored generated asset (it has a model, provider or mime type) keeps its prompt column private
        $isAsset = array_key_exists('prompt', $node) && (array_key_exists('model', $node) || array_key_exists('mime_type', $node) || array_key_exists('provider', $node));
        foreach ($node as $k => $v) {
            if (is_string($k) && (in_array(strtolower($k), self::PRIVATE_KEYS, true) || ($isAsset && $k === 'prompt'))) { unset($node[$k]); $removed++; continue; }
            if (is_string($k) && strtolower($k) === 'blueprint' && is_array($v) && (isset($v['provider_prompt']) || isset($v['typography_strategy']) || isset($v['composition']))) { unset($node[$k]); $removed++; continue; }
            if (is_array($v)) $node[$k] = $this->walk($v, $removed);
            // VIDEO-F1: JSON stored as text (metadata_json …) is opened, cleaned and closed again
            elseif (is_string($v) && is_string($k) && (str_ends_with($k, '_json') || $k === 'metadata') && str_starts_with(ltrim($v), '{') && preg_match(self::FAST, $v)) {
                $inner = json_decode($v, true);
                if (is_array($inner)) { $before = $removed; $inner = $this->walk($inner, $removed); if ($removed > $before) $node[$k] = json_encode($inner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
            }
        }
        return $node;
    }
}
