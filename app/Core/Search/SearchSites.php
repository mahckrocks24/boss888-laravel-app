<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PAGE-ONE-1: the websites Sarah looks after for search, and every public URL each one serves — the same set the live
 * sitemap lists (pages, then articles in the site's article scope), so "in the sitemap" can be checked against it.
 */
final class SearchSites
{
    /** Published, not deleted, with a public host. */
    public static function managed(?int $wsId = null)
    {
        $q = DB::table('websites')->where('status', 'published')->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNotNull('subdomain')->orWhereNotNull('custom_domain')->orWhereNotNull('external_url'));
        if ($wsId) $q->where('workspace_id', $wsId);
        return $q->orderBy('id')->get();
    }

    public static function isWp(object $w): bool { return strtolower((string) ($w->platform ?? '')) === 'wordpress'; }

    /** The host search engines should see: the verified custom domain, else the platform subdomain (or the WP site's URL). */
    public static function host(object $w): ?string
    {
        if (! empty($w->custom_domain) && (bool) ($w->domain_verified ?? false)) return strtolower(trim((string) $w->custom_domain, ' /'));
        if (self::isWp($w)) {
            $u = (string) ($w->external_url ?: ($w->domain ?? ''));
            $h = parse_url(str_contains($u, '://') ? $u : 'https://' . $u, PHP_URL_HOST);
            return $h ? strtolower($h) : null;
        }
        return ! empty($w->subdomain) ? strtolower(trim((string) $w->subdomain, ' /')) : null;
    }

    public static function business(object $w): ?object
    {
        if (! empty($w->business_id)) { $b = DB::table('businesses')->where('id', $w->business_id)->whereNull('deleted_at')->first(); if ($b) return $b; }
        return DB::table('businesses')->where('workspace_id', $w->workspace_id)->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** Where the business is, in words for searches ("New Jersey", "Austin"). */
    public static function place(?object $biz): string
    {
        if (! $biz) return '';
        $loc = trim((string) ($biz->location ?? ''));
        if ($loc === '' && ! empty($biz->address_json)) { $a = json_decode((string) $biz->address_json, true) ?: []; $loc = (string) ($a['addressLocality'] ?? $a['addressRegion'] ?? ''); }
        $loc = preg_replace('/\(.*?\)/', '', $loc);
        return trim(explode(',', $loc)[0] ?? '');
    }

    /** The country's search-data location code (defaults to the US). */
    public static function locationCode(?object $biz, int $wsId): int
    {
        $txt = strtolower(trim(($biz->location ?? '') . ' ' . ($biz->address_json ?? '') . ' ' . (string) DB::table('workspaces')->where('id', $wsId)->value('timezone')));
        $map = [
            2784 => ['uae', 'united arab emirates', 'dubai', 'abu dhabi', 'sharjah', 'asia/dubai'],
            2826 => ['united kingdom', ' uk', 'england', 'london', 'scotland', 'europe/london'],
            2608 => ['philippines', 'manila', 'cebu', 'davao', 'asia/manila'],
            2036 => ['australia', 'sydney', 'melbourne', 'australia/'],
            2124 => ['canada', 'toronto', 'vancouver', 'america/toronto'],
            2702 => ['singapore', 'asia/singapore'],
            2682 => ['saudi', 'riyadh', 'jeddah', 'asia/riyadh'],
            2356 => ['india', 'mumbai', 'delhi', 'asia/kolkata'],
        ];
        foreach ($map as $code => $words) foreach ($words as $w) if (str_contains($txt, $w)) return $code;
        return 2840;
    }

    /**
     * Every public URL of a LevelUp-built site: [url => [kind, ref_id, title]]. Mirrors PublishedSiteMiddleware::serveSitemap.
     * A WordPress site's URLs come from its own sitemap.
     */
    public static function urls(object $w): array
    {
        $host = self::host($w);
        if (! $host) return [];
        if (self::isWp($w)) return self::wpUrls($host);
        $out = [];
        foreach (DB::table('pages')->where('website_id', $w->id)->where('status', 'published')->orderBy('position')->get(['id', 'slug', 'title']) as $p) {
            $out['https://' . $host . '/' . ($p->slug === 'home' ? '' : $p->slug)] = ['kind' => 'page', 'ref_id' => (int) $p->id, 'title' => $p->title];
        }
        $set = json_decode((string) ($w->settings_json ?? '{}'), true) ?: [];
        $base = preg_match('/^[a-z0-9\-]{1,40}$/', (string) ($set['article_base'] ?? '')) ? $set['article_base'] : 'blog';
        $csBase = preg_match('/^[a-z0-9\-]{1,40}$/', (string) ($set['case_study_base'] ?? '')) ? $set['case_study_base'] : 'case-studies';
        $aq = DB::table('articles')->where('workspace_id', $w->workspace_id)->where('status', 'published')->whereNull('deleted_at')->whereNotNull('slug')
            ->where(fn ($q) => \App\Http\Middleware\PublishedSiteMiddleware::articleWebsiteScope($q, (int) $w->workspace_id, (int) $w->id));
        foreach ($aq->orderByDesc('published_at')->get(['id', 'slug', 'title', 'blog_category']) as $a) {
            $slug = trim((string) $a->slug, '/'); if ($slug === '') continue;
            $cat = strtolower(str_replace(' ', '-', (string) ($a->blog_category ?? '')));
            $out['https://' . $host . '/' . (in_array($cat, ['case-studies', 'case-study'], true) ? $csBase : $base) . '/' . $slug] = ['kind' => 'article', 'ref_id' => (int) $a->id, 'title' => $a->title];
        }
        return $out;
    }

    private static function wpUrls(string $host): array
    {
        $out = [];
        foreach (['https://' . $host . '/wp-sitemap.xml', 'https://' . $host . '/sitemap_index.xml', 'https://' . $host . '/sitemap.xml'] as $idx) {
            $locs = self::sitemapLocs($idx, 2);
            if ($locs) { foreach (array_slice($locs, 0, 800) as $u) $out[$u] = ['kind' => 'wp', 'ref_id' => null, 'title' => null]; break; }
        }
        return $out;
    }

    /** <loc> values of a sitemap (follows a sitemap index one level down). */
    public static function sitemapLocs(string $url, int $depth = 1): array
    {
        try {
            $r = Http::timeout(12)->withHeaders(['User-Agent' => 'LevelUpGrowth-SitemapCheck/1.0'])->get($url);
            if (! $r->successful()) return [];
            preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $r->body(), $m);
            $locs = array_map(fn ($u) => html_entity_decode(trim($u)), $m[1] ?? []);
            if ($depth > 1 && stripos($r->body(), '<sitemapindex') !== false) {
                $all = [];
                foreach (array_slice($locs, 0, 12) as $child) $all = array_merge($all, self::sitemapLocs($child, 1));
                return $all;
            }
            return $locs;
        } catch (\Throwable $e) {
            Log::info('[PAGE-ONE-1] sitemap read failed', ['url' => $url, 'e' => $e->getMessage()]);
            return [];
        }
    }

    public static function norm(string $u): string
    {
        $u = strtolower(trim($u));
        $u = preg_replace('#^http://#', 'https://', $u);
        $u = preg_replace('#^https://www\.#', 'https://', $u);
        return rtrim($u, '/');
    }
}
