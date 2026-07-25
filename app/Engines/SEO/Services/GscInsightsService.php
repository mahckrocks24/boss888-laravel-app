<?php

namespace App\Engines\SEO\Services;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * GscInsightsService — the single home for Search Console "ranking
 * opportunities".
 *
 * Architecture (hands-vs-brain, unchanged): Laravel reads the RAW synced
 * gsc_metrics rows and hands them to the runtime gsc_intelligence analyzer.
 * ALL scoring — striking-distance, CTR-gap, cannibalization, decline,
 * opportunity ranking — lives in the runtime. Laravel only orchestrates the
 * read, the runtime round-trip, persistence of nothing new, and a short cache.
 *
 * Used by BOTH the GET /api/seo/gsc/insights route and the SEO Assistant's
 * live context, so the (LLM-free, deterministic) analysis is computed once
 * per snapshot and shared. Extracting it here removes the previous inline
 * copy in the route closure (DRY) without changing its response shape.
 *
 * 2026-06-12 — SEO Assistant ↔ GSC/GA alignment.
 */
class GscInsightsService
{
    /** Cache TTL for a computed snapshot's insights payload (6h). */
    private const CACHE_TTL_S = 21_600;

    public function __construct(
        private GscClient $gsc,
        private RuntimeClient $runtime,
    ) {}

    /**
     * Build the insights payload for a workspace.
     *
     * The shape is byte-identical to the original /gsc/insights route body so
     * the route can delegate with no client-visible change. When the runtime
     * call fails the payload carries a private '_http' => 502 hint that the
     * route strips and maps to the HTTP status; all other callers ignore it.
     *
     * @return array<string,mixed>
     */
    public function insights(int $wsId): array
    {
        if (! $this->gsc->isConnected($wsId)) {
            return [
                'success' => true, 'connected' => false, 'opportunities' => [],
                'message' => 'Connect Google Search Console to see ranking opportunities.',
            ];
        }

        $latest = DB::table('gsc_metrics')->where('workspace_id', $wsId)->max('date');
        if (! $latest) {
            return [
                'success' => true, 'connected' => true, 'opportunities' => [], 'summary' => null,
                'message' => 'No Search Console data synced yet — run a sync to see opportunities.',
            ];
        }

        // Serve from cache when this exact snapshot was already analysed. Keyed
        // by snapshot date so a fresh sync (new date) recomputes automatically.
        $cacheKey = "seo_ws_gsc_insights_{$wsId}_{$latest}";
        try {
            $cached = Redis::get($cacheKey);
            if ($cached !== null && $cached !== false) {
                $payload = json_decode($cached, true);
                if (is_array($payload)) {
                    return $payload;
                }
            }
        } catch (\Throwable $e) {
            // Cache optional — fall through to a live computation.
        }

        $rows = DB::table('gsc_metrics')
            ->where('workspace_id', $wsId)->where('date', $latest)
            ->get(['page', 'query', 'clicks', 'impressions', 'ctr', 'position'])
            ->map(fn ($x) => (array) $x);
        $prevDate = DB::table('gsc_metrics')
            ->where('workspace_id', $wsId)->where('date', '<', $latest)->max('date');
        $prevRows = $prevDate
            ? DB::table('gsc_metrics')
                ->where('workspace_id', $wsId)->where('date', $prevDate)
                ->get(['page', 'query', 'clicks'])->map(fn ($x) => (array) $x)
            : [];

        try {
            $resp = $this->runtime->post('/internal/seo/gsc-intelligence', [
                'workspace_id'  => $wsId,
                'site_url'      => (string) optional($this->gsc->getConnection($wsId))->site_url,
                'rows'          => $rows,
                'previous_rows' => $prevRows,
            ], 30);
            if ($resp->successful()) {
                $payload = array_merge(
                    ['success' => true, 'connected' => true, 'snapshot' => $latest],
                    $resp->json() ?: []
                );
                try {
                    Redis::setex($cacheKey, self::CACHE_TTL_S, json_encode($payload));
                } catch (\Throwable $e) {
                    // Cache write best-effort.
                }
                return $payload;
            }
        } catch (\Throwable $e) {
            Log::warning('[GSC] insights runtime call failed: ' . $e->getMessage());
        }

        return [
            'success' => false, 'connected' => true, 'opportunities' => [], 'snapshot' => $latest,
            'message' => 'Ranking analysis is temporarily unavailable — please try again shortly.',
            '_http'   => 502,
        ];
    }
}
