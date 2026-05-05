<?php

namespace App\Core\Admin;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * SettingsService — Encrypted platform settings storage
 *
 * Stores API keys and system config in `platform_settings` table.
 * Sensitive values (API keys) are encrypted with Laravel's Crypt facade.
 * Non-sensitive values stored as plain text.
 *
 * Groups:
 *   llm      — DeepSeek, OpenAI, Anthropic keys
 *   creative — MiniMax, Runway, Stability keys
 *   email    — Postmark, SendGrid keys
 *   payment  — Stripe keys
 *   general  — App URL, debug flags, feature flags
 */
class SettingsService
{
    /**
     * Get a setting value. Returns decrypted value for sensitive keys.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = DB::table('platform_settings')->where('key', $key)->first();

        if (!$row) return $default;

        if ($row->is_sensitive && $row->value) {
            try {
                return Crypt::decryptString($row->value);
            } catch (\Throwable) {
                return null;
            }
        }

        return $row->value;
    }

    /**
     * Set a setting value. Sensitive values are encrypted.
     */
    public function set(string $key, mixed $value, bool $sensitive = false, string $group = 'general', string $description = ''): void
    {
        $stored = $sensitive && $value ? Crypt::encryptString((string) $value) : $value;

        DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value'        => $stored,
                'is_sensitive' => $sensitive,
                'group'        => $group,
                'description'  => $description,
                'updated_at'   => now(),
                'created_at'   => now(),
            ]
        );
    }

    /**
     * Get an API key — always sensitive.
     */
    public function getApiKey(string $provider): ?string
    {
        return $this->get("api_key_{$provider}");
    }

    /**
     * Set an API key — always encrypted.
     */
    public function setApiKey(string $provider, string $key, string $group = 'llm'): void
    {
        $this->set("api_key_{$provider}", $key, true, $group, "{$provider} API key");
    }

    /**
     * Get all settings for display (sensitive values masked).
     */
    public function all(string $group = null): array
    {
        $q = DB::table('platform_settings');
        if ($group) $q->where('group', $group);

        return $q->orderBy('group')->orderBy('key')->get()->map(function ($row) {
            return [
                'key'          => $row->key,
                'value'        => $row->is_sensitive ? $this->mask($row->value ? '****' : null) : $row->value,
                'is_sensitive' => (bool) $row->is_sensitive,
                'is_set'       => !empty($row->value),
                'group'        => $row->group,
                'description'  => $row->description,
                'updated_at'   => $row->updated_at,
            ];
        })->toArray();
    }

    /**
     * Delete a setting.
     */
    public function delete(string $key): void
    {
        DB::table('platform_settings')->where('key', $key)->delete();
    }

    /**
     * Check if a key is set and non-empty.
     */
    public function has(string $key): bool
    {
        return DB::table('platform_settings')
            ->where('key', $key)
            ->whereNotNull('value')
            ->exists();
    }

    private function mask(?string $value): string
    {
        if (!$value) return '';
        return '••••••••';
    }
}
