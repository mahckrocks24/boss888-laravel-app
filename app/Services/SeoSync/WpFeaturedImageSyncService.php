<?php

namespace App\Services\SeoSync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WpFeaturedImageSyncService
 *
 * Pulls WordPress posts via the connector's GET /lgsc/v1/posts endpoint
 * and back-fills seo_content_index.featured_image_url + wp_post_id for
 * matching rows.
 *
 * Connector returns posts already shaped:
 *   { id, title, url, status, date, modified, excerpt,
 *     featured_image_url, meta_title, meta_description, lgsc_score }
 *
 * Match strategy (URL normalization):
 *   1. Exact URL match
 *   2. Trailing-slash variant
 *   3. Permalink-with-?p=N variant (some old links)
 *
 * Idempotent. Safe to re-run. Only updates rows where featured_image_url
 * is currently NULL/empty OR the wp_post_id has changed.
 */
class WpFeaturedImageSyncService
{
    private const POSTS_PER_PAGE = 100;
    private const MAX_PAGES      = 20;   // hard cap 2000 posts/sync
    private const HTTP_TIMEOUT_S = 30;

    public function sync(int $wsId): array
    {
        $siteUrl = (string) DB::table('seo_settings')
            ->where('workspace_id', $wsId)
            ->where('key', 'site_url')
            ->value('value');
        $secret = (string) DB::table('seo_settings')
            ->where('workspace_id', $wsId)
            ->where('key', 'webhook_secret')
            ->value('value');

        if ($siteUrl === '' || $secret === '') {
            return [
                'success' => false,
                'error'   => 'no_connector_config',
                'message' => 'Workspace has no connected WordPress site.',
            ];
        }

        $base = rtrim($siteUrl, '/');
        $allPosts = [];
        $pagesFetched = 0;
        $errors = [];

        // Page through connector's /posts route. The endpoint returns
        // posts[] without pagination metadata in v1.3.0, but the same
        // path with ?page=N is conventional.
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $resp = Http::timeout(self::HTTP_TIMEOUT_S)
                    ->withHeaders(['User-Agent' => 'LevelUpGrowth/1.0 (featured-sync)'])
                    ->get($base . '/wp-json/lgsc/v1/posts', [
                        'secret'   => $secret,
                        'per_page' => self::POSTS_PER_PAGE,
                        'page'     => $page,
                    ]);
            } catch (\Throwable $e) {
                $errors[] = 'transport_failure_page_' . $page . ': ' . $e->getMessage();
                Log::warning('[SEO][featured-sync] transport failure', [
                    'ws_id' => $wsId, 'page' => $page, 'err' => $e->getMessage(),
                ]);
                break;
            }

            if (! $resp->successful()) {
                $body = $resp->json() ?: [];
                $errors[] = 'connector_http_' . $resp->status() . '_' . ($body['code'] ?? 'unknown');
                Log::warning('[SEO][featured-sync] connector non-2xx', [
                    'ws_id' => $wsId, 'page' => $page, 'http' => $resp->status(),
                ]);
                break;
            }

            $body = $resp->json() ?: [];
            $posts = (array) ($body['posts'] ?? []);
            if (empty($posts)) {
                break; // end of pagination
            }
            $allPosts = array_merge($allPosts, $posts);
            $pagesFetched++;

            // Stop early if we got fewer than a full page
            if (count($posts) < self::POSTS_PER_PAGE) {
                break;
            }
        }

        if (empty($allPosts)) {
            return [
                'success'         => true,
                'posts_fetched'   => 0,
                'images_synced'   => 0,
                'pages_matched'   => 0,
                'pages_fetched'   => $pagesFetched,
                'errors'          => $errors,
                'message'         => empty($errors)
                    ? 'No posts returned by connector (site may have no posts).'
                    : 'Connector errors during fetch — see errors[].',
            ];
        }

        // Build a fast URL → post-data map (with variants for matching)
        $urlMap = [];
        foreach ($allPosts as $p) {
            $url = trim((string) ($p['url'] ?? ''));
            if ($url === '') continue;
            $featured = trim((string) ($p['featured_image_url'] ?? ''));
            $wpId     = (int)  ($p['id'] ?? 0);
            // Skip posts with no featured image — nothing to back-fill
            if ($featured === '' || $wpId === 0) continue;

            // Index all reasonable URL variants for matching
            $variants = $this->urlVariants($url, $wpId);
            foreach ($variants as $v) {
                if (! isset($urlMap[$v])) {
                    $urlMap[$v] = [
                        'wp_post_id'         => $wpId,
                        'featured_image_url' => $featured,
                    ];
                }
            }
        }

        // Pull all seo_content_index rows for the workspace
        $rows = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->get(['id', 'url', 'featured_image_url', 'wp_post_id', 'has_featured_image']);

        $imagesSynced  = 0;
        $pagesMatched  = 0;
        $skippedAlreadyHas = 0;
        $sampleApplied = [];

        foreach ($rows as $row) {
            $rowUrl = trim((string) $row->url);
            if ($rowUrl === '') continue;

            // Try direct + trailing-slash variants of the row URL
            $tries = $this->urlVariants($rowUrl, (int) ($row->wp_post_id ?? 0));
            $match = null;
            foreach ($tries as $t) {
                if (isset($urlMap[$t])) {
                    $match = $urlMap[$t];
                    break;
                }
            }
            if (! $match) continue;

            $pagesMatched++;

            // Determine if we need to write
            $hasUrl = trim((string) ($row->featured_image_url ?? '')) !== '';
            $sameUrl = $hasUrl
                && $row->featured_image_url === $match['featured_image_url'];
            $sameWpId = ((int) ($row->wp_post_id ?? 0)) === $match['wp_post_id'];

            if ($sameUrl && $sameWpId) {
                $skippedAlreadyHas++;
                continue;
            }

            // Write — only update fields that need updating (idempotency)
            $update = [
                'featured_image_url' => $match['featured_image_url'],
                'has_featured_image' => 1,
                'wp_post_id'         => $match['wp_post_id'],
                'updated_at'         => now(),
            ];
            DB::table('seo_content_index')
                ->where('id', $row->id)
                ->update($update);

            $imagesSynced++;
            if (count($sampleApplied) < 5) {
                $sampleApplied[] = [
                    'url'                => $rowUrl,
                    'wp_post_id'         => $match['wp_post_id'],
                    'featured_image_url' => $match['featured_image_url'],
                ];
            }
        }

        return [
            'success'              => true,
            'posts_fetched'        => count($allPosts),
            'pages_in_audit'       => $rows->count(),
            'pages_matched'        => $pagesMatched,
            'images_synced'        => $imagesSynced,
            'skipped_already_set'  => $skippedAlreadyHas,
            'pages_fetched_count'  => $pagesFetched,
            'sample_applied'       => $sampleApplied,
            'errors'               => $errors,
        ];
    }

    /**
     * Produce URL variants for fuzzy matching:
     *   - exact
     *   - with/without trailing slash
     *   - with/without query params
     *   - permalink-style ?p=N (some WP sites mix permalinks)
     */
    private function urlVariants(string $url, int $wpPostId): array
    {
        $url = trim($url);
        $variants = [$url];

        // Trailing-slash toggle
        if (substr($url, -1) === '/') {
            $variants[] = rtrim($url, '/');
        } else {
            $variants[] = $url . '/';
        }

        // Strip query/fragment
        $clean = preg_replace('/[?#].*$/', '', $url) ?? $url;
        if ($clean !== $url) {
            $variants[] = $clean;
            $variants[] = rtrim($clean, '/');
            $variants[] = $clean . '/';
        }

        // Lower-case host comparison
        $parts = parse_url($url);
        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
                        . (isset($parts['path']) ? $parts['path'] : '');
            $variants[] = $normalized;
            $variants[] = rtrim($normalized, '/');
            $variants[] = $normalized . '/';
        }

        // ?p=N permalink (some sites)
        if ($wpPostId > 0 && is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $variants[] = strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . '/?p=' . $wpPostId;
        }

        return array_values(array_unique($variants));
    }
}
