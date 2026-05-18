<?php

namespace App\Engines\SEO\Services;

use Illuminate\Support\Facades\Cache;

/**
 * ScanProgressService — lightweight in-Redis progress publisher for long
 * synchronous SEO scan routes.
 *
 * Writes happen from the scan POST handler; reads happen from the polling
 * GET /scan-status endpoint served by a different PHP-FPM worker. Redis
 * makes the cross-request visibility immediate and reliable.
 *
 * Per-workspace, per-scan-type. TTL = 600s (any scan exceeding this is
 * considered crashed and its key expires automatically).
 *
 * Type values: 'pages' (full sitemap+crawl scan) | 'images' (bulk-analyze).
 */
class ScanProgressService
{
    private const TTL = 600;

    public static function key(int $wsId, string $type): string
    {
        return "seo:scan-progress:ws:{$wsId}:{$type}";
    }

    /** Initialise a new scan. Wipes any prior state for the same (ws, type). */
    public static function start(int $wsId, string $type, array $meta = []): void
    {
        Cache::put(self::key($wsId, $type), [
            'state'         => 'running',
            'stage'         => 'starting',
            'started_at'    => time(),
            'updated_at'    => time(),
            'processed'     => 0,
            'total'         => 0,
            'current_url'   => null,
            'tier1_done'    => 0,
            'tier2_attempts'=> 0,
            'tier2_done'    => 0,
            'errors'        => 0,
            'error_samples' => [],
            'meta'          => $meta,
            'summary'       => null,
        ], self::TTL);
    }

    /** Patch the in-flight state. */
    public static function update(int $wsId, string $type, array $patch): void
    {
        $existing = Cache::get(self::key($wsId, $type), []);
        if (! is_array($existing)) { $existing = []; }
        $patch['updated_at'] = time();
        Cache::put(self::key($wsId, $type), array_merge($existing, $patch), self::TTL);
    }

    /** Mark scan as finished and attach a summary. */
    public static function finish(int $wsId, string $type, array $summary): void
    {
        $existing = Cache::get(self::key($wsId, $type), []);
        if (! is_array($existing)) { $existing = []; }
        Cache::put(self::key($wsId, $type), array_merge($existing, [
            'state'       => 'done',
            'stage'       => 'done',
            'finished_at' => time(),
            'updated_at'  => time(),
            'summary'     => $summary,
        ]), self::TTL);
    }

    /** Mark scan as failed with a message. */
    public static function fail(int $wsId, string $type, string $msg): void
    {
        $existing = Cache::get(self::key($wsId, $type), []);
        if (! is_array($existing)) { $existing = []; }
        Cache::put(self::key($wsId, $type), array_merge($existing, [
            'state'       => 'failed',
            'stage'       => 'failed',
            'finished_at' => time(),
            'updated_at'  => time(),
            'error'       => $msg,
        ]), self::TTL);
    }

    /** Read current state. Null when no scan key exists. */
    public static function get(int $wsId, string $type): ?array
    {
        $val = Cache::get(self::key($wsId, $type));
        return is_array($val) ? $val : null;
    }

    /** Append a single error sample (capped at 5 to keep cache value small). */
    public static function recordError(int $wsId, string $type, string $url, string $err): void
    {
        $existing = Cache::get(self::key($wsId, $type), []);
        if (! is_array($existing)) { $existing = []; }
        $samples = is_array($existing['error_samples'] ?? null) ? $existing['error_samples'] : [];
        if (count($samples) < 5) {
            $samples[] = ['url' => $url, 'error' => mb_substr($err, 0, 200)];
        }
        $existing['error_samples'] = $samples;
        $existing['errors']        = (int) ($existing['errors'] ?? 0) + 1;
        $existing['updated_at']    = time();
        Cache::put(self::key($wsId, $type), $existing, self::TTL);
    }
}
