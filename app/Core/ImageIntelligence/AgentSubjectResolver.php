<?php

namespace App\Core\ImageIntelligence;

use Illuminate\Support\Facades\DB;

/**
 * AgentSubjectResolver — canonical identity for a platform agent named in a customer request
 * (RFC-0009 P2, 2026-09-16).
 *
 * "Sarah, our AI growth manager" is a real, registered agent with an approved portrait (DEC-0055),
 * not a person to invent. When the request names a registry agent, this resolver returns her
 * canonical facts — name, title, description, portrait — and says, truthfully, whether the image
 * provider path can consume the portrait as a reference (today it cannot: RuntimeClient::imageGenerate
 * sends prompt/style/size/quality only; the edit path takes a source image + mask, which is
 * inpainting of an existing asset, not a reference for a new generation).
 *
 * No LLM. No invented appearance, wardrobe, age or setting — those are never in the output.
 */
final class AgentSubjectResolver
{
    /**
     * Whether the CURRENT image generation path can attach a reference image. Kept as one
     * constant so the day a connector gains reference support, this is the one line to flip
     * and the compiled prompt starts promising likeness only when it is true.
     */
    public const GENERATION_SUPPORTS_REFERENCE_IMAGES = false;

    /** @return array<string,mixed>|null */
    public static function resolve(string $userPrompt): ?array
    {
        $prompt = trim($userPrompt);
        if ($prompt === '') return null;
        try {
            $agents = DB::table('agents')->whereNotNull('name')->get(['slug', 'name', 'title', 'description', 'avatar_url']);
        } catch (\Throwable $e) {
            return null;
        }
        $hit = null;
        foreach ($agents as $a) {
            $name = trim((string) $a->name);
            if ($name === '' || mb_strlen($name) < 3) continue;
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $prompt)) {
                // longest name wins if several match (e.g. "Sarah" inside a longer registered name)
                if ($hit === null || mb_strlen($name) > mb_strlen($hit->name)) $hit = $a;
            }
        }
        if ($hit === null) return null;

        $portrait = (string) ($hit->avatar_url ?? '');
        $portraitAbs = $portrait !== '' ? (preg_match('#^https?://#', $portrait) ? $portrait : rtrim((string) config('app.url'), '/') . '/' . ltrim($portrait, '/')) : null;
        $portraitOnDisk = $portrait !== '' && ! preg_match('#^https?://#', $portrait) && is_file(public_path(ltrim($portrait, '/')));

        return [
            'kind'          => 'platform_agent',
            'slug'          => (string) $hit->slug,
            'name'          => (string) $hit->name,
            'title'         => (string) ($hit->title ?? ''),
            'description'   => (string) ($hit->description ?? ''),
            'portrait_url'  => $portraitAbs,
            'portrait_authorised' => $portraitOnDisk || ($portraitAbs !== null && preg_match('#^https?://#', $portrait)),
            'portrait_on_disk'    => $portraitOnDisk,
            'reference_supported' => self::GENERATION_SUPPORTS_REFERENCE_IMAGES,
            // The only sentence the prompt may say about her: facts from the registry, nothing invented.
            // prompt_facts = identity only (name, title); the operational description is context for the reasoner, never prompt text.
            'prompt_facts'  => trim($hit->name . ($hit->title ? ', ' . $hit->title : '') . ' of LevelUpGrowth'),
            'limitation'    => self::GENERATION_SUPPORTS_REFERENCE_IMAGES
                ? null
                : 'The current image provider path cannot take a reference portrait, so ' . $hit->name . "'s likeness will not be reproduced; the image will represent her by name and role only.",
        ];
    }
}
