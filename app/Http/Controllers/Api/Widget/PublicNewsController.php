<?php

namespace App\Http\Controllers\Api\Widget;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public news channel widget endpoints.
 *
 * Auth: NONE — these are read-only public endpoints scoped to a tenant
 * subdomain. Returns only published, non-deleted articles. The data is
 * already publicly visible on the tenant's own subdomain (the news site)
 * so this surface adds no new exposure.
 *
 * Subdomain resolution:
 *   - 'thegulftribune' → matches websites.subdomain ending in
 *     '.levelupgrowth.io' OR matching custom_domain
 *   - Failure modes return empty arrays (never 500) so the JS
 *     progressive-enhancement layer in news_channel/template.html
 *     can silently fall back to the static seed.
 */
class PublicNewsController
{
    /**
     * GET /api/public/news/{subdomain}/stories?limit=12&category=Politics
     */
    public function stories(Request $r, string $subdomain): JsonResponse
    {
        $website = $this->resolveWebsite($subdomain);
        if (!$website) return response()->json(['posts' => [], 'total' => 0]);

        $limit    = max(1, min(50, (int) $r->query('limit', 12)));
        $category = trim((string) $r->query('category', ''));
        // KABAYAN888 UX-1 (2026-09-03): offset + q (search) power "Load more" and site search;
        // website scoping mirrors BuilderRenderer (site-bound OR unbound articles of the workspace).
        $offset   = max(0, min(5000, (int) $r->query('offset', 0)));
        $search   = mb_substr(trim((string) $r->query('q', '')), 0, 80);
        $wid      = (int) ($website->id ?? 0);

        $q = DB::table('articles')
            ->where('workspace_id', $website->workspace_id)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereIn('type', ['news', 'blog_post', 'article']);
        if ($wid > 0) { $q->where(function ($w) use ($wid) { $w->where('website_id', $wid)->orWhereNull('website_id'); }); }
        if ($category !== '' && $category !== 'all') {
            $q->where(function ($w) use ($category) {
                $w->where('blog_category', $category)->orWhereRaw('LOWER(REPLACE(blog_category, " ", "-")) = ?', [strtolower($category)]);
            });
        }
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $q->where(function ($w) use ($like) { $w->where('title', 'like', $like)->orWhere('excerpt', 'like', $like); });
        }
        $total = (clone $q)->count();

        $rows = $q->orderByDesc('published_at')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get([
                'id', 'title', 'slug', 'excerpt', 'blog_category',
                'featured_image_url', 'read_time', 'brief_json', 'published_at',
            ]);

        // KABAYAN888 UX-1 — display names for category slugs (blog_categories), used by search/load-more rows.
        $catNames = [];
        try { foreach (DB::table('blog_categories')->where('workspace_id', $website->workspace_id)->get(['name', 'slug']) as $c) { $catNames[strtolower((string) $c->slug)] = (string) $c->name; $catNames[strtolower((string) $c->name)] = (string) $c->name; } } catch (\Throwable) {}
        $posts = $rows->map(function ($a) use ($catNames) {
            $brief = is_string($a->brief_json) ? json_decode($a->brief_json, true) : null;
            $brief = is_array($brief) ? $brief : [];
            $publishedTs = $a->published_at ? strtotime($a->published_at) : null;
            return [
                'id'                 => (int) $a->id,
                'title'              => (string) ($a->title ?? ''),
                'slug'               => (string) ($a->slug ?? ''),
                'excerpt'            => (string) ($a->excerpt ?? ''),
                'category'           => (string) ($a->blog_category ?? 'News'),
                'category_name'      => $catNames[strtolower((string) ($a->blog_category ?? ''))] ?? ucwords(str_replace('-', ' ', (string) ($a->blog_category ?? 'News'))),
                'featured_image_url' => (string) ($a->featured_image_url ?? ''),
                'read_time'          => $this->formatReadTime($a->read_time, $brief),
                'author'             => (string) ($brief['author'] ?? 'Staff Reporter'),
                'published_at'       => $a->published_at,
                'published_iso'      => $publishedTs ? gmdate('c', $publishedTs) : null,
            ];
        })->values()->all();

        return response()->json(['posts' => $posts, 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'has_more' => ($offset + count($posts)) < $total]) // KABAYAN888 UX-1
            ->header('Cache-Control', 'public, max-age=60, s-maxage=60');
    }

    /**
     * GET /api/public/news/{subdomain}/categories
     * Returns the list of distinct blog_category values for this tenant's
     * published articles (used by the category strip section).
     */
    /**
     * GET /api/public/news/{subdomain}/jobs?q=&category=&city=&type=&limit=&offset=
     * KABAYAN888 JOBS-1 — published, unexpired job listings for the site (search, filters, load-more).
     */
    public function jobs(Request $r, string $subdomain): JsonResponse
    {
        $website = $this->resolveWebsite($subdomain);
        if (!$website) return response()->json(['jobs' => [], 'total' => 0]);
        $limit = max(1, min(50, (int) $r->query('limit', 20))); $offset = max(0, min(5000, (int) $r->query('offset', 0)));
        $q = app(\App\Engines\Jobs\Services\JobsService::class)->publicQuery((int) $website->id);
        foreach (['category' => 'category_slug', 'city' => 'city', 'type' => 'employment_type', 'country' => 'country'] as $param => $col) {
            $v = trim((string) $r->query($param, '')); if ($v !== '' && $v !== 'all') $q->where($col, $v);
        }
        $search = mb_substr(trim((string) $r->query('q', '')), 0, 80);
        if ($search !== '') { $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%'; $q->where(function ($w) use ($like) { $w->where('title', 'like', $like)->orWhere('company', 'like', $like)->orWhere('city', 'like', $like)->orWhere('summary', 'like', $like); }); }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('is_featured')->orderByDesc('posted_at')->orderByDesc('id')->offset($offset)->limit($limit)
            ->get(['id', 'slug', 'title', 'company', 'company_logo_url', 'category_slug', 'employment_type', 'city', 'region', 'country', 'is_remote', 'salary_text', 'summary', 'is_featured', 'posted_at', 'expires_at']);
        $cats = \App\Engines\Jobs\Services\JobsService::CATEGORIES; $types = \App\Engines\Jobs\Services\JobsService::TYPES;
        $jobs = $rows->map(fn ($j) => [
            'id' => (int) $j->id, 'slug' => (string) $j->slug, 'title' => (string) $j->title, 'company' => (string) $j->company, 'logo' => (string) ($j->company_logo_url ?? ''),
            'category' => (string) ($j->category_slug ?? ''), 'category_name' => $cats[$j->category_slug ?? ''] ?? '', 'type' => (string) $j->employment_type, 'type_name' => $types[$j->employment_type] ?? '',
            'city' => (string) ($j->city ?? ''), 'region' => (string) ($j->region ?? ''), 'country' => (string) $j->country, 'remote' => (bool) $j->is_remote, 'salary' => (string) ($j->salary_text ?? ''),
            'summary' => (string) ($j->summary ?? ''), 'featured' => (bool) $j->is_featured, 'posted_at' => $j->posted_at, 'posted_iso' => $j->posted_at ? gmdate('c', strtotime($j->posted_at)) : null, 'expires_at' => $j->expires_at,
        ])->values()->all();
        return response()->json(['jobs' => $jobs, 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'has_more' => ($offset + count($jobs)) < $total])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=60');
    }

    public function categories(string $subdomain): JsonResponse
    {
        $website = $this->resolveWebsite($subdomain);
        if (!$website) return response()->json(['categories' => []]);

        $cats = DB::table('articles')
            ->where('workspace_id', $website->workspace_id)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNotNull('blog_category')
            ->where('blog_category', '!=', '')
            ->whereIn('type', ['news', 'blog_post', 'article'])
            ->distinct()
            ->pluck('blog_category');

        return response()->json(['categories' => $cats->values()->all()]);
    }

    private function resolveWebsite(string $subdomain): ?object
    {
        $sub = preg_replace('/[^a-z0-9\-]/i', '', $subdomain);
        if ($sub === '') return null;

        // Try exact subdomain match first ('foo.levelupgrowth.io'), then
        // custom_domain, then bare-subdomain prefix.
        return DB::table('websites')
            ->where(function ($q) use ($sub) {
                $q->where('subdomain', $sub . '.levelupgrowth.io')
                  ->orWhere('subdomain', $sub)
                  ->orWhere('custom_domain', $sub);
            })
            ->whereIn('status', ['published', 'draft']) // draft acceptable so the news API works during dev
            ->first();
    }

    private function formatReadTime($rt, array $brief): string
    {
        if (isset($brief['read_time']) && is_string($brief['read_time']) && $brief['read_time'] !== '') {
            return $brief['read_time'];
        }
        $minutes = (int) ($rt ?? 0);
        if ($minutes <= 0) return '3 min read';
        return $minutes . ' min read';
    }
}
