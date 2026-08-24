<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AdSettingsService — read/write the operational knobs, with caching and audit.
 *
 * READS are cached for 60s. That TTL is deliberate and matches the plan's
 * commitment: a settings change (including the master kill-switch) takes effect
 * "within 60 seconds", never "instantly", because Cloudflare's edge cache on
 * tenant HTML sets the same floor. Promising instant would be a lie we could
 * not keep.
 *
 * WRITES are audited. Every change records actor, before and after. An ad
 * platform where someone can silently widen `eligible_plan_slugs` and start
 * serving ads to paying customers is not an enterprise system.
 */
final class AdSettingsService
{
    private const CACHE_PREFIX = 'ads:setting:';
    private const CACHE_TTL    = 60;

    /** Read one setting, falling back to the code-defined default. */
    public function get(string $key): mixed
    {
        if (! AdSettings::isKnown($key)) {
            // Unknown keys are a programming error, not a runtime condition.
            // Returning null silently would make a typo look like "feature off".
            throw new \InvalidArgumentException("Unknown ad setting: {$key}");
        }

        try {
            return Cache::remember(
                self::CACHE_PREFIX . $key,
                self::CACHE_TTL,
                function () use ($key) {
                    $row = DB::table('ad_settings')->where('key', $key)->first();

                    if (! $row || $row->value === null) {
                        return AdSettings::default($key);
                    }

                    $decoded = json_decode((string) $row->value, true);

                    return json_last_error() === JSON_ERROR_NONE
                        ? $decoded
                        : AdSettings::default($key);
                }
            );
        } catch (Throwable) {
            // Fail SAFE, not open: if settings cannot be read we fall back to
            // the code defaults, and the master default is `false`.
            return AdSettings::default($key);
        }
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    /** @return array<int,mixed> */
    public function array(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : [];
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /** Write a setting and record who changed it from what to what. */
    public function set(string $key, mixed $value, ?int $actorId = null, ?string $actorLabel = null): void
    {
        if (! AdSettings::isKnown($key)) {
            throw new \InvalidArgumentException("Unknown ad setting: {$key}");
        }

        $before = $this->get($key);

        DB::table('ad_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value'       => json_encode($value, JSON_UNESCAPED_SLASHES),
                'description' => AdSettings::describe($key),
                'updated_by'  => $actorId,
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );

        Cache::forget(self::CACHE_PREFIX . $key);

        app(AdAuditService::class)->record(
            action: 'settings.update',
            subjectType: 'ad_setting',
            subjectId: null,
            before: [$key => $before],
            after: [$key => $value],
            actorId: $actorId,
            actorLabel: $actorLabel,
        );
    }

    /** Every setting with its effective value and whether it is overridden. */
    public function all(): array
    {
        $overrides = DB::table('ad_settings')->pluck('value', 'key')->toArray();

        $out = [];
        foreach (AdSettings::definitions() as $key => [$default, $description]) {
            $out[$key] = [
                'value'       => $this->get($key),
                'default'     => $default,
                'overridden'  => array_key_exists($key, $overrides),
                'description' => $description,
            ];
        }

        return $out;
    }

    /** Drop the cached copy of every setting. */
    public function flush(): void
    {
        foreach (array_keys(AdSettings::definitions()) as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }
}
