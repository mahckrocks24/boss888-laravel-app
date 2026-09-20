<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\DB;

/**
 * RISK-0198 (2026-09-20): an article belongs to ONE website. An article with no website (NULL — written before websites
 * were targeted, or by an older batch) belongs to the workspace's FIRST website only, never to every site of the workspace.
 * The Owner's event-venue site (911, ws 2) listed, served and sitemapped Chef Red's articles. Single-website workspaces
 * (the majority) are unchanged. Every article query for a served site goes through here.
 */
final class ArticleScope
{
    private static array $primary = [];

    public static function primaryWebsite(int $workspaceId): int
    {
        if (!array_key_exists($workspaceId, self::$primary)) {
            self::$primary[$workspaceId] = (int) DB::table('websites')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->orderBy('id')->value('id');
        }
        return self::$primary[$workspaceId];
    }

    /** Apply to a query already limited to the workspace. */
    public static function forWebsite($q, int $workspaceId, int $websiteId): void
    {
        if ($websiteId <= 0) return;
        $primary = self::primaryWebsite($workspaceId);
        $q->where(function ($w) use ($websiteId, $primary) {
            $w->where('website_id', $websiteId);
            if ($primary === $websiteId) $w->orWhereNull('website_id');
        });
    }

    /** Tests and one-off scripts: forget the per-request cache. */
    public static function reset(): void { self::$primary = []; }
}
