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

    /** The host a websites row answers to — the same derivation the /seo/sites picker uses. */
    public static function hostOfWebsite(object $row): string
    {
        $host = '';
        if (! empty($row->custom_domain))      $host = $row->custom_domain;
        elseif (! empty($row->domain))         $host = $row->domain;
        elseif (! empty($row->subdomain))      $host = str_contains((string) $row->subdomain, '.') ? (string) $row->subdomain : $row->subdomain . '.levelupgrowth.io';
        return strtolower(preg_replace('#^www\.#', '', trim((string) $host)));
    }

    /**
     * 2026-09-21 (Owner: Chef Red's stats on every website). Is $host the workspace's FIRST website? Rows that name no
     * site (keywords with no target_url) belong to that site only — the rule ArticleScope set for articles (RISK-0198).
     */
    public static function isPrimaryHost(int $wsId, string $host): bool
    {
        $host = strtolower(preg_replace('#^www\.#', '', trim($host)));
        if ($host === '') return false;
        $id = \App\Engines\Builder\Support\ArticleScope::primaryWebsite($wsId);
        if ($id <= 0) return true;   // a workspace with no website: nothing to hide behind
        $row = \Illuminate\Support\Facades\DB::table('websites')->where('id', $id)->first(['custom_domain', 'domain', 'subdomain']);
        return $row ? self::hostOfWebsite($row) === $host : false;
    }

    /** LIKE pattern for a host inside a stored URL. */
    public static function likeFor(string $host): string
    {
        return '%//' . strtolower(preg_replace('#^www\.#', '', trim($host))) . '%';
    }
}
