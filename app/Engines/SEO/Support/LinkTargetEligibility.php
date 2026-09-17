<?php

namespace App\Engines\SEO\Support;

use Illuminate\Support\Facades\DB;

/**
 * RISK-0189 (2026-09-17) — which pages may be offered or chosen as internal-link DESTINATIONS.
 *
 * The link graph reads seo_content_index, and a draft is indexed the moment it is written — before Sarah's QA has
 * looked at it. A draft QA later REJECTS (SARAH-QA-1 writes brief_json.qa.status) stayed in the index, so the misplaced
 * yoga article on the bakery site (1058) was linked from two bakery articles (EV-1056, EV-1058). One predicate, applied
 * at every door a target passes through: suggestion candidates, orphan rescue, and the moment a suggestion is applied
 * (so a suggestion cached before the verdict cannot land afterwards). QA rejection also drops the draft from the index
 * and dismisses its pending suggestions, so no other discovery path or cache re-offers it.
 */
final class LinkTargetEligibility
{
    public const INELIGIBLE_QA = ['rejected', 'needs_owner'];

    /** The article a /blog/{slug} URL points at, in this workspace (slug or slug-prefix match as the graph does). */
    public static function articleForUrl(int $wsId, string $url): ?object
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (! preg_match('#/blog/([^/]+)/?$#i', $path, $m)) return null;
        $slug = $m[1];
        $a = DB::table('articles')->where('workspace_id', $wsId)->where('slug', $slug)->first(['id', 'slug', 'status', 'brief_json', 'deleted_at']);
        if (! $a) $a = DB::table('articles')->where('workspace_id', $wsId)->where('slug', 'like', $slug . '%')->orderBy('id')->first(['id', 'slug', 'status', 'brief_json', 'deleted_at']);
        return $a ?: null;
    }

    public static function qaStatus(?object $article): ?string
    {
        if (! $article) return null;
        $b = json_decode((string) ($article->brief_json ?? ''), true) ?: [];
        $s = $b['qa']['status'] ?? null;
        return $s === null ? null : strtolower((string) $s);
    }

    /** @return array{eligible:bool, reason:string, article_id:?int} */
    public static function assess(int $wsId, string $url): array
    {
        $a = self::articleForUrl($wsId, $url);
        if (! $a) return ['eligible' => true, 'reason' => 'not_an_article_url', 'article_id' => null];   // pages, WP posts: unchanged
        if (! empty($a->deleted_at)) return ['eligible' => false, 'reason' => 'article_deleted', 'article_id' => (int) $a->id];
        $qa = self::qaStatus($a);
        if ($qa !== null && in_array($qa, self::INELIGIBLE_QA, true)) return ['eligible' => false, 'reason' => 'qa_' . $qa, 'article_id' => (int) $a->id];
        return ['eligible' => true, 'reason' => 'ok', 'article_id' => (int) $a->id];
    }

    public static function isEligibleTarget(int $wsId, string $url): bool
    {
        return self::assess($wsId, $url)['eligible'];
    }

    /**
     * Called when QA rejects a draft: take it out of the link graph (index row) and dismiss any pending suggestion that
     * points at it. Existing INSERTED links are left alone — removing them is an edit of another article, done through
     * the product, not silently here. Returns what was removed.
     */
    public static function withdrawArticle(int $wsId, int $articleId): array
    {
        $a = DB::table('articles')->where('id', $articleId)->where('workspace_id', $wsId)->first(['slug']);
        if (! $a) return ['index_rows' => 0, 'suggestions_dismissed' => 0];
        $like = '%/blog/' . $a->slug . '%';
        $idx = DB::table('seo_content_index')->where('workspace_id', $wsId)->where('url', 'like', $like)->delete();
        $sug = DB::table('seo_links')->where('workspace_id', $wsId)->where('status', 'suggested')->where('target_url', 'like', $like)
            ->update(['status' => 'dismissed', 'updated_at' => now()]);
        return ['index_rows' => (int) $idx, 'suggestions_dismissed' => (int) $sug];
    }
}
