<?php

namespace App\Services\SeoOptimization;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ConnectorCapabilityProbe (v2 — ARCHITECTURE-CORRECT 2026-05-16)
 *
 * Probes the workspace's connector to determine if the TRANSPORT layer
 * for optimization is available — fetch + replace endpoints reachable
 * and uploads writable. Does NOT probe for any third-party plugin.
 *
 * Laravel does the compression. Connector is dumb transport.
 *
 * Status enum:
 *   'ok'                              — connector v1.3.0+, transport ready
 *   'no_connector'                    — workspace has no site_url + secret
 *   'capability_endpoint_missing'     — connector pre-v1.3.0
 *   'capability_unknown'              — transient probe failure
 */
class ConnectorCapabilityProbe
{
    private const CACHE_TTL_OK   = 300;
    private const CACHE_TTL_FAIL = 60;
    private const HTTP_TIMEOUT   = 8;

    public function probe(int $wsId, bool $force = false): array
    {
        $key = "seo:capability:ws:{$wsId}";

        if (! $force) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $siteUrl = DB::table('seo_settings')
            ->where('workspace_id', $wsId)
            ->where('key', 'site_url')
            ->value('value');
        $secret = DB::table('seo_settings')
            ->where('workspace_id', $wsId)
            ->where('key', 'webhook_secret')
            ->value('value');

        if (! $siteUrl || ! $secret) {
            $result = [
                'status'                 => 'no_connector',
                'optimization_available' => false,
                'transport_available'    => false,
                'reason'                 => 'Workspace has no connector site_url or webhook_secret configured',
            ];
            Cache::put($key, $result, self::CACHE_TTL_OK);
            return $result;
        }

        $base = rtrim((string) $siteUrl, '/');

        try {
            $resp = Http::timeout(self::HTTP_TIMEOUT)
                ->get($base . '/wp-json/lgsc/v1/capability', ['secret' => $secret]);

            if (! $resp->successful()) {
                $body = $resp->json() ?: [];
                if ($resp->status() === 404 && ($body['code'] ?? '') === 'rest_no_route') {
                    $health = Http::timeout(5)->get($base . '/wp-json/lgsc/v1/health');
                    $ver    = $health->successful() ? (string) ($health->json('version') ?? 'unknown') : 'unreachable';
                    $result = [
                        'status'                 => 'capability_endpoint_missing',
                        'optimization_available' => false,
                        'transport_available'    => false,
                        'plugin_version'         => $ver,
                        'reason'                 => 'Connector v1.3.0+ required for optimization transport.',
                    ];
                    Cache::put($key, $result, self::CACHE_TTL_OK);
                    return $result;
                }
                $result = [
                    'status'                 => 'capability_unknown',
                    'optimization_available' => false,
                    'transport_available'    => false,
                    'reason'                 => 'Capability probe HTTP ' . $resp->status(),
                ];
                Cache::put($key, $result, self::CACHE_TTL_FAIL);
                return $result;
            }

            $body = $resp->json() ?: [];
            $transport = (bool) ($body['transport_available'] ?? false);
            $result = [
                'status'                 => 'ok',
                'optimization_available' => $transport,
                'transport_available'    => $transport,
                'uploads_writable'       => (bool) ($body['uploads_writable'] ?? false),
                'max_upload_size_bytes'  => (int)  ($body['max_upload_size_bytes'] ?? 0),
                'memory_limit'           => (string) ($body['memory_limit'] ?? ''),
                'supported_mimes'        => (array) ($body['supported_mimes'] ?? []),
                'plugin_version'         => (string) ($body['plugin_version'] ?? ''),
                'reason'                 => null,
            ];
            Cache::put($key, $result, self::CACHE_TTL_OK);
            return $result;
        } catch (\Throwable $e) {
            Log::warning('[SEO][capability] probe exception', [
                'ws_id' => $wsId, 'err' => $e->getMessage(),
            ]);
            $result = [
                'status'                 => 'capability_unknown',
                'optimization_available' => false,
                'transport_available'    => false,
                'reason'                 => 'Probe failed: ' . $e->getMessage(),
            ];
            Cache::put($key, $result, self::CACHE_TTL_FAIL);
            return $result;
        }
    }

    public function invalidate(int $wsId): void
    {
        Cache::forget("seo:capability:ws:{$wsId}");
    }
}
