<?php

namespace App\Engines\SEO\Services;

use Illuminate\Support\Facades\DB;

/**
 * PageDiscoveryService — workspace-scoped page URL enumerator.
 *
 * Architecture: DB-first when the platform owns the content, crawler/sitemap
 * supplemental for what's not in DB. Per-workspace strategy detection.
 *
 * Strategies (ordered, additive — multiple may apply per workspace):
 *   1. articles_blog   — articles table → siteUrl/blog/{slug}     (Laravel-served blog)
 *   2. builder_pages   — pages table via websites JOIN             (Laravel builder sites)
 *   3. seo_settings    — explicit additional URLs configured       (manual additions)
 *
 * Returns deduplicated absolute URLs. Caller (typically /scan-pages) then
 * supplements with sitemap walk + link crawl for anything DB didn't know.
 *
 * Phase P1+ (2026-05-15) — replaces crawl-first design per architectural review.
 */
class PageDiscoveryService
{
    /**
     * Discover all known page URLs for a workspace via DB sources.
     * Crawler supplementing happens at the route layer, not here.
     *
     * @return array<string>
     */
    public function discover(int $wsId): array
    {
        $siteUrl = $this->siteUrlFor($wsId);
        if ($siteUrl === '') {
            return [];
        }

        $urls = [];
        // Always include homepage as the canonical entry point.
        $urls[] = $siteUrl;

        // Strategy 1: articles → /blog/{slug} (Laravel blog convention).
        // P0-B2 (2026-05-15) — mirrors BlogController::getPost AND the route
        // regex at routes/web.php:418 (slug must be [a-z0-9-]+). Without
        // is_marketing_blog the route serves the HTML shell but the
        // /api/blog/posts/{slug} fetch returns 404, so the page is content-
        // less. Without the slug regex the route 404s outright. We mirror
        // BOTH so the audit only emits URLs that will actually serve content.
        $articleSlugs = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('is_marketing_blog', true)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->pluck('slug')
            ->toArray();
        // PHP-side route regex check — avoids MySQL REGEXP cost and matches
        // the route's '[a-z0-9\-]+' constraint exactly.
        $articleSlugs = array_filter(
            $articleSlugs,
            fn ($s) => is_string($s) && preg_match('/^[a-z0-9\-]+$/', $s) === 1
        );
        foreach ($articleSlugs as $slug) {
            $urls[] = $siteUrl . '/blog/' . trim((string) $slug, '/');
        }

        // Strategy 2: builder pages via websites.workspace_id JOIN.
        // pages.is_homepage rows skipped (homepage already added above).
        // Tenant pages are typically published under siteUrl/{slug} but
        // could differ if the website has a custom_domain — we use the
        // workspace's seo_settings.site_url as the canonical host for now.
        $pageSlugs = DB::table('pages')
            ->join('websites', 'pages.website_id', '=', 'websites.id')
            ->where('websites.workspace_id', $wsId)
            ->where('pages.status', 'published')
            ->where('pages.is_homepage', 0)
            ->whereNotNull('pages.slug')
            ->where('pages.slug', '!=', '')
            ->pluck('pages.slug')
            ->toArray();
        foreach ($pageSlugs as $slug) {
            $urls[] = $siteUrl . '/' . trim((string) $slug, '/');
        }

        // Strategy 3: explicit URLs from seo_settings (operator-supplied).
        // Stored as JSON array under key 'extra_urls' if present.
        $extraJson = DB::table('seo_settings')
            ->where('workspace_id', $wsId)
            ->where('key', 'extra_urls')
            ->value('value');
        if ($extraJson) {
            $extras = json_decode((string) $extraJson, true);
            if (is_array($extras)) {
                foreach ($extras as $u) {
                    if (is_string($u) && $u !== '') {
                        $urls[] = $u;
                    }
                }
            }
        }

        // Dedupe (URLs as-is — caller may further normalize).
        return array_values(array_unique($urls));
    }

    /**
     * Resolve workspace's primary site URL from seo_settings.
     */
    private function siteUrlFor(int $wsId): string
    {
        $url = (string) (DB::table('seo_settings')
            ->where('workspace_id', $wsId)
            ->where('key', 'site_url')
            ->value('value') ?? '');
        return rtrim($url, '/');
    }

    /**
     * Diagnostic: explain what discover() found and from which strategy.
     * Useful for the SPA's "Scan Pages" results modal.
     *
     * @return array{
     *   site_url: string,
     *   counts: array<string, int>,
     *   total: int,
     *   urls: array<string>
     * }
     */
    public function discoverWithStats(int $wsId): array
    {
        $siteUrl = $this->siteUrlFor($wsId);
        if ($siteUrl === '') {
            return ['site_url' => '', 'counts' => [], 'total' => 0, 'urls' => []];
        }

        // P0-B2 — count must match what discover() actually emits.
        $articleSlugsForCount = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->where('is_marketing_blog', true)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->pluck('slug')
            ->toArray();
        $articleCount = count(array_filter(
            $articleSlugsForCount,
            fn ($s) => is_string($s) && preg_match('/^[a-z0-9\-]+$/', $s) === 1
        ));

        $pageCount = (int) DB::table('pages')
            ->join('websites', 'pages.website_id', '=', 'websites.id')
            ->where('websites.workspace_id', $wsId)
            ->where('pages.status', 'published')
            ->where('pages.is_homepage', 0)
            ->whereNotNull('pages.slug')
            ->where('pages.slug', '!=', '')
            ->count();

        $extraJson = DB::table('seo_settings')
            ->where('workspace_id', $wsId)
            ->where('key', 'extra_urls')
            ->value('value');
        $extraCount = 0;
        if ($extraJson) {
            $extras = json_decode((string) $extraJson, true);
            $extraCount = is_array($extras) ? count($extras) : 0;
        }

        $urls = $this->discover($wsId);

        return [
            'site_url' => $siteUrl,
            'counts' => [
                'homepage'        => 1,
                'articles_blog'   => $articleCount,
                'builder_pages'   => $pageCount,
                'extra_urls'      => $extraCount,
            ],
            'total' => count($urls),
            'urls'  => $urls,
        ];
    }
}
