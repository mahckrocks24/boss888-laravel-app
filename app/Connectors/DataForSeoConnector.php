<?php

namespace App\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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

    private string $login;
    private string $password;
    private int    $timeout;

    public function __construct()
    {
        $this->login    = (string) env('DATAFORSEO_LOGIN', '');
        $this->password = (string) env('DATAFORSEO_PASSWORD', '');
        $this->timeout  = (int)    env('DATAFORSEO_TIMEOUT', 30);
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

        $payload = [[
            'keyword'       => $keyword,
            'location_code' => $locationCode,
            'language_code' => $language,
            'depth'         => 20,  // top-20 results is enough for analysis
            'device'        => 'desktop',
        ]];

        try {
            $resp = $this->request('POST', '/v3/serp/google/organic/live/advanced', $payload);
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
            $apiMessage = $body['status_message'] ?? null;
            $apiCode    = $body['status_code'] ?? null;
            $errorMsg = $apiMessage
                ? ($apiCode ? "DataForSEO {$apiCode}: {$apiMessage}" : $apiMessage)
                : ('http_' . $resp->status());
            return ['success' => false, 'error' => $errorMsg, 'raw' => $body];
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

        $payload = [[
            'keywords'      => array_values(array_slice($keywords, 0, 100)),  // API max 100 per call
            'location_code' => $locationCode,
            'language_code' => $language,
        ]];

        try {
            $resp = $this->request('POST', '/v3/keywords_data/google_ads/search_volume/live', $payload);
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
            $apiMessage = $body['status_message'] ?? null;
            $apiCode    = $body['status_code'] ?? null;
            $errorMsg = $apiMessage
                ? ($apiCode ? "DataForSEO {$apiCode}: {$apiMessage}" : $apiMessage)
                : ('http_' . $resp->status());
            return ['success' => false, 'error' => $errorMsg, 'raw' => $body];
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

        $payload = [[
            'target'        => $domain,
            'location_code' => $locationCode,
            'language_code' => $language,
            'limit'         => max(1, min(1000, $limit)),
            'order_by'      => ['keyword_data.keyword_info.search_volume,desc'],
        ]];

        try {
            $resp = $this->request('POST', '/v3/dataforseo_labs/google/keywords_for_site/live', $payload);
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
            $apiMessage = $body['status_message'] ?? null;
            $apiCode    = $body['status_code'] ?? null;
            $errorMsg = $apiMessage
                ? ($apiCode ? "DataForSEO {$apiCode}: {$apiMessage}" : $apiMessage)
                : ('http_' . $resp->status());
            return ['success' => false, 'error' => $errorMsg, 'raw' => $body];
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
            $resp = $this->request('POST', '/v3/serp/google/organic/live/advanced', $payload);
        } catch (ConnectionException $e) {
            Log::warning('DataForSeoConnector::trackKeywordRank connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (!$resp->successful() || (isset($body['status_code']) && $body['status_code'] !== 20000)) {
            $apiMessage = $body['status_message'] ?? null;
            $apiCode    = $body['status_code'] ?? null;
            return ['success' => false, 'error' => $apiMessage ? "DataForSEO {$apiCode}: {$apiMessage}" : 'http_' . $resp->status()];
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
     * Internal HTTP wrapper — shared auth + headers + timeout.
     */
    private function request(string $method, string $path, array $payload = []): Response
    {
        $req = Http::withBasicAuth($this->login, $this->password)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        return $method === 'POST'
            ? $req->post(self::BASE_URL . $path, $payload)
            : $req->get(self::BASE_URL . $path, $payload);
    }
}
