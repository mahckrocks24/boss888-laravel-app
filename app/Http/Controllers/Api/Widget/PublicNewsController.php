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

        $q = DB::table('articles')
            ->where('workspace_id', $website->workspace_id)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereIn('type', ['news', 'blog_post', 'article']);
        if ($category !== '' && $category !== 'all') {
            $q->where('blog_category', $category);
        }

        $rows = $q->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id', 'title', 'slug', 'excerpt', 'blog_category',
                'featured_image_url', 'read_time', 'brief_json', 'published_at',
            ]);

        $posts = $rows->map(function ($a) {
            $brief = is_string($a->brief_json) ? json_decode($a->brief_json, true) : null;
            $brief = is_array($brief) ? $brief : [];
            $publishedTs = $a->published_at ? strtotime($a->published_at) : null;
            return [
                'id'                 => (int) $a->id,
                'title'              => (string) ($a->title ?? ''),
                'slug'               => (string) ($a->slug ?? ''),
                'excerpt'            => (string) ($a->excerpt ?? ''),
                'category'           => (string) ($a->blog_category ?? 'News'),
                'featured_image_url' => (string) ($a->featured_image_url ?? ''),
                'read_time'          => $this->formatReadTime($a->read_time, $brief),
                'author'             => (string) ($brief['author'] ?? 'Staff Reporter'),
                'published_at'       => $a->published_at,
                'published_iso'      => $publishedTs ? gmdate('c', $publishedTs) : null,
            ];
        })->values()->all();

        return response()->json(['posts' => $posts, 'total' => count($posts)]);
    }

    /**
     * GET /api/public/news/{subdomain}/categories
     * Returns the list of distinct blog_category values for this tenant's
     * published articles (used by the category strip section).
     */
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
