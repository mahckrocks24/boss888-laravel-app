<?php

namespace App\Engines\SEO\Support;

use Illuminate\Http\Request;

/**
 * Wave 16 (2026-05-19). Helper for resolving the "active site" filter
 * scope from an incoming SEO request.
 *
 * The frontend's global site dropdown (rendered in buildShell, line ~1386
 * of seo.js) sends `site_url` either as a query string parameter or in
 * the request body. This helper extracts the host so a query can apply
 * `WHERE url LIKE '%//<host>%'` to scope SEO data to one website.
 *
 * Phase A (Wave 16) — URL-LIKE filter at query time (no schema change).
 * Phase B (Wave 17) — proper `site_id` foreign-key migration replaces this.
 *
 * Returns the lowercased host (without scheme/path) or '' when no site
 * is being filtered (which means "no filter — full workspace scope",
 * preserving backward compatibility for callers that don't pass site_url).
 */
class SiteScope
{
    public static function hostFromRequest(Request $request): string
    {
        // 2026-06-24 — NO-OP under the website=workspace architecture. The
        // WORKSPACE is now the hard separation boundary (each website is its own
        // workspace with isolated SEO/CRM/blog/tasks), so in-workspace host
        // filtering is obsolete and would wrongly hide data. Returning '' makes
        // every caller fall back to full workspace scope (its single site).
        // Kept as a no-op (not deleted) so the 29 call-sites keep compiling;
        // site selection is now the workspace switcher. hostFromUrl() remains
        // for any explicit host parsing needs.
        return '';
    }

    public static function hostFromUrl(string $url): string
    {
        if ($url === '') return '';
        // Allow callers to pass either a full URL or a bare host.
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        return strtolower(trim($host));
    }
}
