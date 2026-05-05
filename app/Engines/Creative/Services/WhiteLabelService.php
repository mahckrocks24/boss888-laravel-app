<?php

namespace App\Engines\Creative\Services;

/**
 * WhiteLabelService
 *
 * Strips all third-party provider names from any string or array before
 * it is returned to the user. Called via CreativeService::sanitize() which
 * is invoked by every engine before returning its response.
 *
 * Rule: No provider name (OpenAI, MiniMax, DeepSeek, Runway …) ever
 * surfaces to an end-user. All attribution resolves to "LevelUp AI".
 */
class WhiteLabelService
{
    /**
     * Provider names that must never be visible to end-users.
     * Ordered longest-first to prevent partial matches leaving fragments.
     */
    private const PROVIDER_MAP = [
        // Full product / model names
        'MiniMax Hailuo-02'     => 'LevelUp AI',
        'MiniMax Hailuo'        => 'LevelUp AI',
        'Hailuo-02'             => 'LevelUp AI',
        'Hailuo'                => 'LevelUp AI',
        'gpt-image-1'           => 'LevelUp AI Image',
        'dall-e-3'              => 'LevelUp AI Image',
        'dall-e-2'              => 'LevelUp AI Image',
        'DALL-E 3'              => 'LevelUp AI Image',
        'DALL-E 2'              => 'LevelUp AI Image',
        'DALL·E 3'              => 'LevelUp AI Image',
        'DALL·E 2'              => 'LevelUp AI Image',
        'deepseek-chat'         => 'LevelUp AI',
        'deepseek-coder'        => 'LevelUp AI',
        'claude-3'              => 'LevelUp AI',
        'claude-sonnet'         => 'LevelUp AI',
        'claude-opus'           => 'LevelUp AI',
        'gpt-4o'                => 'LevelUp AI',
        'gpt-4'                 => 'LevelUp AI',
        'gpt-3.5'               => 'LevelUp AI',
        // Company / brand names
        'OpenAI'                => 'LevelUp AI',
        'Anthropic'             => 'LevelUp AI',
        'DeepSeek'              => 'LevelUp AI',
        'MiniMax'               => 'LevelUp AI',
        'Runway ML'             => 'LevelUp AI',
        'Runway'                => 'LevelUp AI',
        'Stability AI'          => 'LevelUp AI',
        'Stable Diffusion'      => 'LevelUp AI',
        'Midjourney'            => 'LevelUp AI',
        // Generic model references
        'powered by GPT'        => 'powered by LevelUp AI',
        'powered by OpenAI'     => 'powered by LevelUp AI',
        'via OpenAI'            => 'via LevelUp AI',
        'via DeepSeek'          => 'via LevelUp AI',
        'via MiniMax'           => 'via LevelUp AI',
        'via Runway'            => 'via LevelUp AI',
        'using OpenAI'          => 'using LevelUp AI',
        'using DeepSeek'        => 'using LevelUp AI',
    ];

    /**
     * Sanitize a string. Replaces all provider names with "LevelUp AI".
     */
    public function sanitizeString(string $text): string
    {
        foreach (self::PROVIDER_MAP as $provider => $replacement) {
            // Case-insensitive replacement preserving surrounding whitespace
            $text = str_ireplace($provider, $replacement, $text);
        }
        return $text;
    }

    /**
     * Recursively sanitize an array or scalar value.
     * Handles nested arrays (e.g., API response payloads).
     * Skips keys that should never be exposed to users (url, storage_path, etc.)
     * but sanitizes all visible content fields.
     */
    public function sanitize(mixed $data, bool $sanitizeKeys = false): mixed
    {
        if (is_string($data)) {
            return $this->sanitizeString($data);
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                // Never sanitize these keys — they are internal references
                $skipSanitizeValueKeys = ['url', 'storage_path', 'thumbnail_url', 'job_id', 'asset_id', 'id', 'workspace_id', 'task_id', 'memory_id'];
                $sanitizedKey = $sanitizeKeys ? $this->sanitizeString((string) $key) : $key;
                if (in_array($key, $skipSanitizeValueKeys, true)) {
                    $result[$sanitizedKey] = $value;
                } else {
                    $result[$sanitizedKey] = $this->sanitize($value, $sanitizeKeys);
                }
            }
            return $result;
        }

        // int, float, bool, null — pass through unchanged
        return $data;
    }

    /**
     * Sanitize the 'model' field specifically — used in asset records.
     * Replaces provider model strings with LevelUp AI equivalents.
     */
    public function sanitizeModelName(string $model): string
    {
        $modelMap = [
            'gpt-image-1'   => 'LevelUp AI Image v1',
            'dall-e-3'      => 'LevelUp AI Image v3',
            'hailuo-02'     => 'LevelUp AI Video v2',
            'hailuo'        => 'LevelUp AI Video',
            'deepseek-chat' => 'LevelUp AI Chat',
            'gpt-4o'        => 'LevelUp AI Pro',
            'gpt-4'         => 'LevelUp AI Pro',
        ];

        return $modelMap[strtolower($model)] ?? 'LevelUp AI';
    }

    /**
     * Sanitize provider field — used in asset and job records.
     */
    public function sanitizeProvider(string $provider): string
    {
        $providerMap = [
            'openai'    => 'LevelUp AI',
            'minimax'   => 'LevelUp AI',
            'runway'    => 'LevelUp AI',
            'deepseek'  => 'LevelUp AI',
            'anthropic' => 'LevelUp AI',
            'stability' => 'LevelUp AI',
            'mock'      => 'LevelUp AI',
        ];

        return $providerMap[strtolower($provider)] ?? 'LevelUp AI';
    }
}
