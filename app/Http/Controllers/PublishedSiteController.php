<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cache invalidation helper for published websites.
 * Actual serving is handled by PublishedSiteMiddleware.
 */
class PublishedSiteController
{
    /**
     * Invalidate Redis cache for all published pages of a website.
     * Called from API publish/unpublish routes.
     */
    public static function invalidateCache(int $websiteId): void
    {
        $website = DB::table('websites')->where('id', $websiteId)->first();
        if (!$website || !$website->subdomain) return;

        $subdomain = str_replace('.levelupgrowth.io', '', $website->subdomain);

        $pages = DB::table('pages')
            ->where('website_id', $websiteId)
            ->pluck('slug');

        foreach ($pages as $slug) {
            Cache::forget("published_site:{$subdomain}:{$slug}");
        }
        Cache::forget("published_site:{$subdomain}:home");
    }
}
