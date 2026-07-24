<?php

namespace App\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DataForSEO REST API connector — Phase 2E.1.
 *
 * Wraps the DataForSEO API endpoints used by SeoService for real SERP /
 * keyword / competitor analysis (replacing the rand()-based fakes documented
 * in logs/2026-04-13-seo-audit/01-seoservice-audit.md).
 *
 * Auth: HTTP Basic with the login + password from DATAFORSEO_LOGIN /
 * DATAFORSEO_PASSWORD env vars (Laravel auto-base64s the credentials).
 *
 * Endpoints used:
 *   - POST /v3/serp/google/organic/live/advanced  (SERP analysis)
 *   - POST /v3/keywords_data/google_ads/search_volume/live (volume/CPC)
 *   - POST /v3/dataforseo_labs/google/keywords_for_site/live (competitor keywords)
 *
 * Default location: 21048 = United Arab Emirates (per planner instruction —
 * MENA-first). Override per call when needed.
 *
 * All methods return a normalized array shape so SeoService can consume them
 * without caring about the DataForSEO response envelope. On failure, return
 * `['success' => false, 'error' => '...']` — never throw.
 */
class DataForSeoConnector
{
    private const BASE_URL = 'https://api.dataforseo.com';

    /** DataForSEO location codes (verified 2026-04-13 from /v3/serp/google/locations). */
    public const LOCATION_UAE = 2784;  // United Arab Emirates
    public const LOCATION_USA = 2840;
    public const LOCATION_UK  = 2826;

    /**
     * Map a workspace's free-text location (e.g. "New Jersey, USA (serving NJ,
     * NY, CT)", "Dubai, UAE") to a DataForSEO country location_code. Deterministic
     * keyword detection only — country-level is enough for SMB rank tracking and
     * matches our LOCATION_* constants. Unknown/empty → USA (largest SERP market).
     * 2026-07-01 — replaces the hardcoded LOCATION_UAE that made track-ranks query
     * the wrong country for every non-UAE workspace.
     */
    public static function locationCodeFromText(?string $text): int
    {
        $t = ' ' . strtolower((string) $text) . ' ';
        if (preg_match('/\b(uae|u\.a\.e|united arab emirates|dubai|abu dhabi|sharjah)\b/', $t)) {
            return self::LOCATION_UAE;
        }
        if (preg_match('/\b(uk|u\.k|united kingdom|england|scotland|wales|london)\b/', $t)) {
            return self::LOCATION_UK;
        }
        // United States — country tokens plus common state names/abbreviations.
        if (preg_match('/\b(usa|u\.s\.a|u\.s|united states|america|new jersey|new york|california|nj|ny|ct|texas|florida)\b/', $t)) {
            return self::LOCATION_USA;
        }
        return self::LOCATION_USA;
    }

    // ── DFS-F2 (2026-07-18) — response cache ──────────────────────────────
    //
    // Every endpoint here is the paid `/live/` tier. Before this patch the
    // connector had no cache at all: the same keyword queried twice cost
    // twice. Cache is keyed ONLY on the query parameters DataForSEO actually
    // varies its answer by (keyword/domain, location, language, limit) —
    // responses contain no tenant data, so the cache is deliberately
    // workspace-AGNOSTIC and two workspaces tracking the same keyword share
    // one paid call. Do NOT add workspace_id to the key: it would defeat the
    // saving, and the ambient `lu.current_workspace_id` binding is null under
    // cron, which would split the namespace between web and cron.
    //
    // Uses the Cache facade (Redis DB 1, prefix boss888_cache_) — NOT the raw
    // Redis facade, which is DB 0 where the queue, sessions and Sarah's
    // sarah_state_blob_* live. Keeping to Cache:: guarantees no collision.
    private const CACHE_PREFIX = 'dfs';
    private const CACHE_VER    = 'v1';   // bump to bust every entry at once

    // TTLs. TTL_SERP MUST stay < 24h: SeoSerpRefresh appends time-stamped
    // rows to seo_serp_results on a 24h cadence, so a >=24h TTL would write
    // a fresh checked_at with stale positions and corrupt SERP history.
    private const TTL_SERP       = 21600;    // 6h
    private const TTL_KEYWORD    = 604800;   // 7d — Google Ads volume is a monthly metric
    private const TTL_RELATED    = 86400;    // 24h
    private const TTL_COMPETITOR = 86400;    // 24h

    private string $login;
    private string $password;
    private int    $timeout;
    private bool   $cacheEnabled;

    public function __construct()
    {
        $this->login    = (string) env('DATAFORSEO_LOGIN', '');
        $this->password = (string) env('DATAFORSEO_PASSWORD', '');
        $this->timeout  = (int)    env('DATAFORSEO_TIMEOUT', 30);
        // Kill switch — set DATAFORSEO_CACHE=false in .env to disable the
        // cache instantly without a deploy.
        $this->cacheEnabled = filter_var(env('DATAFORSEO_CACHE', true), FILTER_VALIDATE_BOOLEAN);
    }

    /** Normalize a keyword for cache keying: DFS treats these as equivalent. */
    private function normalizeKeyword(string $keyword): string
    {
        return md5(mb_strtolower(trim($keyword)));
    }

    /** Build a collision-free cache key. Convention: colon-delimited, matches app/ house style. */
    private function cacheKey(string $bucket, array $parts): string
    {
        return self::CACHE_PREFIX . ':' . $bucket . ':' . self::CACHE_VER . ':' . implode(':', $parts);
    }

    /**
     * Cache wrapper. Only SUCCESSFUL responses are stored — every failure
     * branch in this connector is transient (auth, balance, timeout, rate
     * limit), and caching one would turn a 30-second blip into a TTL-long
     * outage. Worse, SeoRankTrack writes current_rank => null on failure, so
     * a cached failure would pin ranks to NULL for the whole TTL.
     */
    private function cached(string $key, int $ttl, string $feature, string $endpoint, callable $fetch): array
    {
        if (! $this->cacheEnabled) {
            return $fetch();
        }

        try {
            $hit = Cache::get($key);
        } catch (\Throwable) {
            $hit = null;   // cache backend down — degrade to a live call
        }

        if (is_array($hit)) {
            $this->logUsage($feature, $endpoint, 0.0, null, null, true, true);
            return $hit;
        }

        $res = $fetch();

        if (($res['success'] ?? false) === true) {
            try {
                Cache::put($key, $res, $ttl);
            } catch (\Throwable) {
                // Cache write failure must never break the response.
            }
        }

        return $res;
    }

    public function isConfigured(): bool
    {
        return $this->login !== '' && $this->password !== '';
    }

    /**
     * SERP analysis for a single keyword. Returns top results + SERP features
     * + estimated metrics in a normalized shape.
     *
     * Endpoint: POST /v3/serp/google/organic/live/advanced
     * Response shape (normalized):
     *   - success            bool
     *   - keyword            string
     *   - location_code      int
     *   - language           string
     *   - total_results      int            (estimated total results count)
     *   - serp_features      array          (featured_snippet, people_also_ask, etc.)
     *   - top_results        array of {position, url, title, domain, snippet}
     *   - error              string         (only on failure)
     */
    public function serpAnalysis(string $keyword, int $locationCode = self::LOCATION_UAE, string $language = 'en'): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'DATAFORSEO_LOGIN/PASSWORD not configured'];
        }

        // depth (20) and device ('desktop') are hardcoded below, so they are
        // not key components today — CACHE_VER covers us if that changes.
        return $this->cached(
            $this->cacheKey('serp', [$locationCode, $language, $this->normalizeKeyword($keyword)]),
            self::TTL_SERP,
            'serp_analysis',
            '/v3/serp/google/organic/live/advanced',
            fn (): array => $this->serpAnalysisLive($keyword, $locationCode, $language)
        );
    }

    /** Uncached live SERP fetch. Do not call directly — go through serpAnalysis(). */
    private function serpAnalysisLive(string $keyword, int $locationCode, string $language): array
    {
        $payload = [[
            'keyword'       => $keyword,
            'location_code' => $locationCode,
            'language_code' => $language,
            'depth'         => 20,  // top-20 results is enough for analysis
            'device'        => 'desktop',
        ]];

        try {
            $resp = $this->request('POST', '/v3/serp/google/organic/live/advanced', $payload, 'serp_analysis');
        } catch (ConnectionException $e) {
            Log::warning('DataForSeoConnector::serpAnalysis connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        // DataForSEO returns the actual error reason in the body even on non-2xx
        // (e.g. HTTP 403 + status_code 40104 "Please verify your account..." for
        // unverified accounts). Surface the body message first; fall back to HTTP
        // status only when the body is truly empty.
        if (!$resp->successful() || (isset($body['status_code']) && $body['status_code'] !== 20000)) {
            return $this->failureFrom($resp, $body);
        }

        $task = $body['tasks'][0] ?? null;
        if (!$task || ($task['status_code'] ?? 0) !== 20000) {
            return [
                'success' => false,
                'error'   => $task['status_message'] ?? ('task_status_' . ($task['status_code'] ?? 'unknown')),
                'raw'     => $body,
            ];
        }

        $result = $task['result'][0] ?? [];
        $items  = $result['items'] ?? [];

        // Extract organic results + SERP features
        $topResults  = [];
        $serpFeatures = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'organic' && count($topResults) < 10) {
                $topResults[] = [
                    'position' => $item['rank_absolute'] ?? null,
                    'url'      => $item['url'] ?? null,
                    'title'    => $item['title'] ?? null,
                    'domain'   => $item['domain'] ?? null,
                    'snippet'  => $item['description'] ?? null,
                ];
            } elseif ($type !== 'organic' && $type !== 'paid') {
                // Track non-organic SERP features (featured_snippet, people_also_ask, local_pack, etc.)
                if (!in_array($type, $serpFeatures, true)) {
                    $serpFeatures[] = $type;
                }
            }
        }

        return [
            'success'        => true,
            'keyword'        => $result['keyword'] ?? $keyword,
            'location_code'  => $result['location_code'] ?? $locationCode,
            'language'       => $result['language_code'] ?? $language,
            'total_results'  => (int) ($result['se_results_count'] ?? 0),
            'serp_features'  => $serpFeatures,
            'top_results'    => $topResults,
        ];
    }

    /**
     * Keyword volume + CPC + difficulty for a list of keywords.
     *
     * Endpoint: POST /v3/keywords_data/google_ads/search_volume/live
     * Response shape (normalized):
     *   - success bool
     *   - keywords array of {keyword, volume, cpc, competition, competition_index}
     */
    public function keywordData(array $keywords, int $locationCode = self::LOCATION_UAE, string $language = 'en'): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'DATAFORSEO_LOGIN/PASSWORD not configured'];
        }
        if (empty($keywords)) {
            return ['success' => true, 'keywords' => []];
        }

        // Slice FIRST so the cache reflects what would actually be requested.
        $sliced = array_values(array_slice($keywords, 0, 100));

        if (! $this->cacheEnabled) {
            return $this->keywordDataLive($sliced, $locationCode, $language);
        }

        // PER-KEYWORD caching (not per-batch). This is deliberate: the monthly
        // batched seo:refresh-volume warms up to 100 entries in one call, and
        // the single-keyword callers (SeoRankTrack, SeoService) then hit those
        // entries for free. A per-batch key would never match across the two
        // because the array ordering differs. This endpoint is the expensive
        // one (~$0.05/call) — it was the dominant cost leak DFS-F1 addressed by
        // cadence; DFS-F2 addresses it by reuse.
        $cachedRows = [];
        $missing    = [];
        foreach ($sliced as $kw) {
            if (! is_string($kw) || trim($kw) === '') {
                continue;
            }
            try {
                $hit = Cache::get($this->cacheKey('kw', [$locationCode, $language, $this->normalizeKeyword($kw)]));
            } catch (\Throwable) {
                $hit = null;
            }
            if (is_array($hit)) {
                $cachedRows[] = $hit;
            } else {
                $missing[] = $kw;
            }
        }

        if (empty($missing)) {
            $this->logUsage('keyword_volume', '/v3/keywords_data/google_ads/search_volume/live', 0.0, null, null, true, true);
            return ['success' => true, 'keywords' => $cachedRows];
        }

        $live = $this->keywordDataLive($missing, $locationCode, $language);

        if (! ($live['success'] ?? false)) {
            // Live half failed. If we still have cache hits, serve them rather
            // than failing the whole batch — callers key results by keyword and
            // simply skip what is absent (RefreshKeywordVolumeCommand builds a
            // lookup map; SeoRankTrack falls back to the stored volume).
            if (! empty($cachedRows)) {
                return ['success' => true, 'keywords' => $cachedRows, 'partial' => true, 'error' => $live['error'] ?? null];
            }
            return $live;
        }

        foreach (($live['keywords'] ?? []) as $row) {
            $kw = $row['keyword'] ?? null;
            if (! $kw) {
                continue;
            }
            try {
                Cache::put(
                    $this->cacheKey('kw', [$locationCode, $language, $this->normalizeKeyword((string) $kw)]),
                    $row,
                    self::TTL_KEYWORD
                );
            } catch (\Throwable) {
            }
        }

        return ['success' => true, 'keywords' => array_merge($cachedRows, $live['keywords'] ?? [])];
    }

    /** Uncached live volume fetch. Do not call directly — go through keywordData(). */
    private function keywordDataLive(array $keywords, int $locationCode, string $language): array
    {
        if (empty($keywords)) {
            return ['success' => true, 'keywords' => []];
        }

        $payload = [[
            'keywords'      => array_values(array_slice($keywords, 0, 100)),  // API max 100 per call
            'location_code' => $locationCode,
            'language_code' => $language,
        ]];

        try {
            $resp = $this->request('POST', '/v3/keywords_data/google_ads/search_volume/live', $payload, 'keyword_volume');
        } catch (ConnectionException $e) {
            Log::warning('DataForSeoConnector::keywordData connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        // DataForSEO returns the actual error reason in the body even on non-2xx
        // (e.g. HTTP 403 + status_code 40104 "Please verify your account..." for
        // unverified accounts). Surface the body message first; fall back to HTTP
        // status only when the body is truly empty.
        if (!$resp->successful() || (isset($body['status_code']) && $body['status_code'] !== 20000)) {
            return $this->failureFrom($resp, $body);
        }

        $task = $body['tasks'][0] ?? null;
        if (!$task || ($task['status_code'] ?? 0) !== 20000) {
            return [
                'success' => false,
                'error'   => $task['status_message'] ?? ('task_status_' . ($task['status_code'] ?? 'unknown')),
                'raw'     => $body,
            ];
        }

        $items = $task['result'] ?? [];
        $rows  = [];
        foreach ($items as $row) {
            $rows[] = [
                'keyword'           => $row['keyword'] ?? null,
                'volume'            => $row['search_volume'] ?? null,
                'cpc'               => isset($row['cpc']) ? round((float) $row['cpc'], 2) : null,
                'competition'       => $row['competition'] ?? null,           // LOW | MEDIUM | HIGH
                'competition_index' => isset($row['competition_index']) ? (int) $row['competition_index'] : null,  // 0-100
            ];
        }

        return ['success' => true, 'keywords' => $rows];
    }

    /**
     * Competitor keywords for a domain — what keywords does this domain rank
     * for? Used to feed real competitor identification.
     *
     * Endpoint: POST /v3/dataforseo_labs/google/keywords_for_site/live
     * Response shape (normalized):
     *   - success bool
     *   - domain string
     *   - keywords array of {keyword, position, volume, cpc}
     */
    public function competitorKeywords(string $domain, int $locationCode = self::LOCATION_UAE, string $language = 'en', int $limit = 50): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'DATAFORSEO_LOGIN/PASSWORD not configured'];
        }
        if ($domain === '') {
            return ['success' => false, 'error' => 'domain required'];
        }

        // `limit` IS a key component — a 50-row result must not serve a
        // 1000-row request.
        return $this->cached(
            $this->cacheKey('comp', [$locationCode, $language, max(1, min(1000, $limit)), md5(mb_strtolower(trim($domain)))]),
            self::TTL_COMPETITOR,
            'competitor_keywords',
            '/v3/dataforseo_labs/google/keywords_for_site/live',
            fn (): array => $this->competitorKeywordsLive($domain, $locationCode, $language, $limit)
        );
    }

    /** Uncached live fetch. Do not call directly — go through competitorKeywords(). */
    private function competitorKeywordsLive(string $domain, int $locationCode, string $language, int $limit): array
    {
        $payload = [[
            'target'        => $domain,
            'location_code' => $locationCode,
            'language_code' => $language,
            'limit'         => max(1, min(1000, $limit)),
            'order_by'      => ['keyword_data.keyword_info.search_volume,desc'],
        ]];

        try {
            $resp = $this->request('POST', '/v3/dataforseo_labs/google/keywords_for_site/live', $payload, 'competitor_keywords');
        } catch (ConnectionException $e) {
            Log::warning('DataForSeoConnector::competitorKeywords connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        // DataForSEO returns the actual error reason in the body even on non-2xx
        // (e.g. HTTP 403 + status_code 40104 "Please verify your account..." for
        // unverified accounts). Surface the body message first; fall back to HTTP
        // status only when the body is truly empty.
        if (!$resp->successful() || (isset($body['status_code']) && $body['status_code'] !== 20000)) {
            return $this->failureFrom($resp, $body);
        }

        $task = $body['tasks'][0] ?? null;
        if (!$task || ($task['status_code'] ?? 0) !== 20000) {
            return [
                'success' => false,
                'error'   => $task['status_message'] ?? ('task_status_' . ($task['status_code'] ?? 'unknown')),
                'raw'     => $body,
            ];
        }

        $items = $task['result'][0]['items'] ?? [];
        $kws   = [];
        foreach ($items as $item) {
            $info = $item['keyword_data']['keyword_info'] ?? [];
            $kws[] = [
                'keyword'  => $item['keyword_data']['keyword'] ?? null,
                'position' => $item['ranked_serp_element']['serp_item']['rank_absolute'] ?? null,
                'volume'   => $info['search_volume'] ?? null,
                'cpc'      => isset($info['cpc']) ? round((float) $info['cpc'], 2) : null,
            ];
        }

        return ['success' => true, 'domain' => $domain, 'keywords' => $kws];
    }


    /**
     * Track a keyword's rank for a specific domain.
     *
     * Calls the SERP API, scans organic results for the target domain,
     * returns the position (or null if not in top N).
     *
     * @param string $keyword    Keyword to check
     * @param string $domain     Target domain (e.g. "mr-marketing.levelupgrowth.io")
     * @param int    $locationCode DataForSEO location code
     * @param int    $depth      How deep to scan (default 100)
     * @return array {success, keyword, domain, position, url, title, serp_features}
     */
    /**
     * DFS-F2 (2026-07-18) — DELIBERATELY NOT CACHED. Do not add a cache here.
     *
     * This is the rank-tracking path. TrackKeywordRanksCommand computes
     * previous_rank / current_rank / rank_change from consecutive calls and
     * raises seo_activity_log alerts on movement >= 3. A cache hit returns an
     * identical position, so rank_change computes to 0 and previous_rank is
     * overwritten with the same value — real ranking movement would be
     * silently erased and the alerts would never fire. The callers also use
     * different depths (20 weekly vs 100 manual), so a shared entry would be
     * wrong for one of them regardless. Cadence, not caching, is the cost
     * control for this endpoint (weekly — see bootstrap/app.php).
     */
    public function trackKeywordRank(string $keyword, string $domain, int $locationCode = self::LOCATION_UAE, int $depth = 100): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'DATAFORSEO_LOGIN/PASSWORD not configured'];
        }

        $payload = [[
            'keyword'       => $keyword,
            'location_code' => $locationCode,
            'language_code' => 'en',
            'depth'         => $depth,
            'device'        => 'desktop',
        ]];

        try {
            $resp = $this->request('POST', '/v3/serp/google/organic/live/advanced', $payload, 'rank_track');
        } catch (ConnectionException $e) {
            Log::warning('DataForSeoConnector::trackKeywordRank connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (!$resp->successful() || (isset($body['status_code']) && $body['status_code'] !== 20000)) {
            return $this->failureFrom($resp, $body);
        }

        $task = $body['tasks'][0] ?? null;
        if (!$task || ($task['status_code'] ?? 0) !== 20000) {
            return ['success' => false, 'error' => $task['status_message'] ?? 'task_failed'];
        }

        $result = $task['result'][0] ?? [];
        $items  = $result['items'] ?? [];

        // Normalize domain for matching (strip www.)
        $targetDomain = ltrim(strtolower($domain), 'www.');

        $position = null;
        $rankUrl  = null;
        $rankTitle = null;

        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'organic') continue;
            $itemDomain = ltrim(strtolower($item['domain'] ?? ''), 'www.');
            if ($itemDomain === $targetDomain || str_ends_with($itemDomain, '.' . $targetDomain)) {
                $position = $item['rank_absolute'] ?? null;
                $rankUrl  = $item['url'] ?? null;
                $rankTitle = $item['title'] ?? null;
                break;
            }
        }

        // Extract SERP features
        $serpFeatures = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            if ($type !== 'organic' && $type !== 'paid' && !in_array($type, $serpFeatures, true)) {
                $serpFeatures[] = $type;
            }
        }

        return [
            'success'       => true,
            'keyword'       => $keyword,
            'domain'        => $domain,
            'position'      => $position,
            'url'           => $rankUrl,
            'title'         => $rankTitle,
            'serp_features' => $serpFeatures,
            'depth_scanned' => $depth,
            'cost'          => $task['cost'] ?? null,
        ];
    }

    /**
     * Related keywords for a seed — used for the Keywords Research tool.
     *
     * Endpoint: POST /v3/dataforseo_labs/google/related_keywords/live
     * Returns a list of semantically related keywords with their search volume,
     * CPC, and competition_index (used to derive a difficulty label).
     *
     * Response shape (normalized):
     *   - success bool
     *   - keyword string (the seed)
     *   - items  array of {keyword, volume, cpc, competition, difficulty}
     */
    public function relatedKeywords(string $keyword, int $locationCode = self::LOCATION_UAE, string $language = 'en', int $limit = 30): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'serp_provider_not_configured'];
        }

        return $this->cached(
            $this->cacheKey('rel', [$locationCode, $language, max(1, min(100, $limit)), $this->normalizeKeyword($keyword)]),
            self::TTL_RELATED,
            'related_keywords',
            '/v3/dataforseo_labs/google/related_keywords/live',
            fn (): array => $this->relatedKeywordsLive($keyword, $locationCode, $language, $limit)
        );
    }

    /** Uncached live fetch. Do not call directly — go through relatedKeywords(). */
    private function relatedKeywordsLive(string $keyword, int $locationCode, string $language, int $limit): array
    {
        $payload = [[
            'keyword'       => $keyword,
            'location_code' => $locationCode,
            'language_code' => $language,
            'depth'         => 1,
            'limit'         => max(1, min(100, $limit)),
            'include_seed_keyword' => true,
        ]];

        try {
            $resp = $this->request('POST', '/v3/dataforseo_labs/google/related_keywords/live', $payload, 'related_keywords');
        } catch (ConnectionException $e) {
            Log::warning('DataForSeoConnector::relatedKeywords connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (!$resp->successful() || (isset($body['status_code']) && $body['status_code'] !== 20000)) {
            $apiMessage = $body['status_message'] ?? null;
            $apiCode    = $body['status_code'] ?? null;
            // DFS-F2 — error STRING kept byte-identical (the caller at
            // routes/api.php only tests `success`, but do not churn it), while
            // http_status/api_code are added so every failure shape in this
            // connector now carries them uniformly.
            return [
                'success'     => false,
                'error'       => $apiMessage ? "serp_provider_{$apiCode}: {$apiMessage}" : 'http_' . $resp->status(),
                'http_status' => $resp->status(),
                'api_code'    => $apiCode,
            ];
        }

        $task = $body['tasks'][0] ?? null;
        if (!$task || ($task['status_code'] ?? 0) !== 20000) {
            return ['success' => false, 'error' => $task['status_message'] ?? 'task_failed'];
        }

        $result = $task['result'][0] ?? [];
        $items  = $result['items'] ?? [];

        $rows = [];
        foreach ($items as $item) {
            $kwData = $item['keyword_data'] ?? [];
            $kw     = $kwData['keyword'] ?? null;
            if (!$kw) continue;
            $kwInfo = $kwData['keyword_info'] ?? [];
            $volume = $kwInfo['search_volume'] ?? null;
            $cpc    = isset($kwInfo['cpc']) ? round((float) $kwInfo['cpc'], 2) : null;
            $compIdx = $kwInfo['competition_index'] ?? null;

            // Derive difficulty label from competition_index (0-100).
            $difficulty = null;
            if ($compIdx !== null) {
                $difficulty = $compIdx >= 67 ? 'hard' : ($compIdx >= 34 ? 'medium' : 'easy');
            }

            $rows[] = [
                'keyword'           => $kw,
                'volume'            => $volume,
                'cpc'               => $cpc,
                'competition'       => $kwInfo['competition'] ?? null,
                'competition_index' => $compIdx,
                'difficulty'        => $difficulty,
            ];
        }

        return [
            'success' => true,
            'keyword' => $keyword,
            'items'   => $rows,
        ];
    }

        /**
     * Internal HTTP wrapper — shared auth + headers + timeout.
     */
    /**
     * Build an HONEST failure array from a DataForSEO response.
     *
     * DataForSEO wraps task-level failures — 40200 "Payment Required" (account
     * out of balance), 40104 "Please verify your account", etc. — inside a
     * TOP-LEVEL 20000 "Ok." envelope while returning a NON-2xx HTTP status.
     * Reading only the envelope produced the misleading "DataForSEO 20000: Ok."
     * (which cost real debugging time on 2026-07-01). Prefer the TASK
     * status_message and always include the HTTP status so billing/auth
     * failures are unmistakable in logs.
     */
    private function failureFrom(Response $resp, array $body, string $prefix = 'DataForSEO'): array
    {
        $task   = $body['tasks'][0] ?? null;
        $code   = $task['status_code']    ?? ($body['status_code']    ?? null);
        $msg    = $task['status_message'] ?? ($body['status_message'] ?? null);
        $reason = $msg ? ($code ? "{$code}: {$msg}" : $msg) : 'no detail';
        return [
            'success'     => false,
            'error'       => "{$prefix} HTTP {$resp->status()} / {$reason}",
            'http_status' => $resp->status(),
            'api_code'    => $code,
            'raw'         => $body,
        ];
    }

    private function request(string $method, string $path, array $payload = [], ?string $feature = null): Response
    {
        $req = Http::withBasicAuth($this->login, $this->password)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        $resp = $method === 'POST'
            ? $req->post(self::BASE_URL . $path, $payload)
            : $req->get(self::BASE_URL . $path, $payload);

        // DFS-F1 (2026-06-21) — cost telemetry. Persist the DFS-returned cost
        // for every call so spend is auditable (previously the `cost` field was
        // read but never stored). Best-effort workspace via the orchestrator's
        // ambient binding; null for direct cron calls. NEVER fatal.
        //
        // DFS-F2 (2026-07-18) — also persist http_status / api_code / ok.
        // Before this, a FAILED call logged estimated_cost 0.00 and was
        // indistinguishable from a free one. 171 consecutive rows read $0.00
        // while the account was actually returning 402 Payment Required, so
        // the spend log looked healthy through a two-day outage.
        try {
            $body = $resp->json() ?? [];
            // DFS returns cost at top level (sum) and per task; read whichever is set.
            $cost = (float) ($body['cost'] ?? 0);
            if ($cost <= 0 && ! empty($body['tasks'][0]['cost'])) {
                $cost = (float) $body['tasks'][0]['cost'];
            }

            // DataForSEO wraps task-level failures inside a top-level 20000
            // envelope, so the TASK code is the honest one — prefer it.
            $apiCode = $body['tasks'][0]['status_code'] ?? ($body['status_code'] ?? null);
            $ok      = $resp->successful() && (int) ($apiCode ?? 0) === 20000;

            $this->logUsage(
                $feature ?? 'unknown',
                $path,
                $ok ? $cost : 0.0,
                $resp->status(),
                $apiCode !== null ? (int) $apiCode : null,
                $ok,
                false
            );

            if (! $ok) {
                Log::warning('[DFS] paid call failed', [
                    'endpoint'    => $path,
                    'feature'     => $feature ?? 'unknown',
                    'http_status' => $resp->status(),
                    'api_code'    => $apiCode,
                    'message'     => $body['tasks'][0]['status_message'] ?? ($body['status_message'] ?? null),
                    // 40200 = account out of balance. This is the one that
                    // silently degraded SEO to structural fallback for ~2 days.
                    'billing'     => (int) $apiCode === 40200 ? 'PAYMENT_REQUIRED — top up DataForSEO' : null,
                ]);
            }
        } catch (\Throwable) {
            // Telemetry must never break the upstream API call.
        }

        return $resp;
    }

    /**
     * Single writer for dfs_usage_log. Used by request() for live calls and by
     * cached() for cache hits (cost 0, cache_hit 1) so "calls avoided" is
     * measurable. Best-effort — never throws.
     */
    private function logUsage(
        string $feature,
        string $endpoint,
        float $cost,
        ?int $httpStatus,
        ?int $apiCode,
        bool $ok,
        bool $cacheHit
    ): void {
        try {
            $wsId = null;
            if (app()->bound('lu.current_workspace_id')) {
                $wsId = ((int) app('lu.current_workspace_id')) ?: null;
            }
            \Illuminate\Support\Facades\DB::table('dfs_usage_log')->insert([
                'workspace_id'   => $wsId,
                'feature'        => $feature,
                'endpoint'       => $endpoint,
                'estimated_cost' => $cost,
                'http_status'    => $httpStatus,
                'api_code'       => $apiCode,
                'ok'             => $ok ? 1 : 0,
                'cache_hit'      => $cacheHit ? 1 : 0,
                'created_at'     => now(),
            ]);
        } catch (\Throwable) {
            // Telemetry must never break the upstream API call.
        }
    }
}
