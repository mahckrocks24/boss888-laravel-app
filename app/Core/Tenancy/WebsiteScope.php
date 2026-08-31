<?php

namespace App\Core\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * INC-0006 — the single place that answers "which website does this row belong to?".
 *
 * The canonical model is USER != WORKSPACE != WEBSITE: one business gets one workspace, and that workspace
 * may hold many websites. Two rules follow, and both live here so they cannot drift apart across call sites:
 *
 *  1. A website is never guessed. If the caller does not name one and the workspace holds more than one, the
 *     answer is WEBSITE_REQUIRED — never "the first site". An arbitrary pick silently writes one website's
 *     configuration onto another, which is the class of defect INC-0006 exists to remove.
 *
 *  2. Settings tables resolve most-specific-first: the row for this website, else the business-wide default
 *     row (website_id = 0), else nothing. That keeps every pre-existing row working untouched while allowing
 *     a second website to override without disturbing the first.
 */
final class WebsiteScope
{
    /** Sentinel for "applies to the whole business, not one website". */
    public const BUSINESS_DEFAULT = 0;

    public const NOT_IN_WORKSPACE = 'WEBSITE_NOT_IN_WORKSPACE';
    public const REQUIRED         = 'WEBSITE_REQUIRED';

    /**
     * Resolve the website a request is talking about.
     *
     * @return array{0:?int,1:?string} [websiteId, errorCode] — exactly one is non-null, except in an empty
     *                                 workspace where both are null (nothing to scope to yet).
     */
    public static function resolve(int $wsId, ?int $given): array
    {
        $sites = self::idsIn($wsId);

        if ($given !== null && $given > 0) {
            return in_array($given, $sites, true) ? [$given, null] : [null, self::NOT_IN_WORKSPACE];
        }

        return match (count($sites)) {
            1       => [$sites[0], null],   // unambiguous
            0       => [null, null],        // nothing built yet
            default => [null, self::REQUIRED],
        };
    }

    /** Website ids live in this workspace, oldest first. */
    public static function idsIn(int $wsId): array
    {
        return DB::table('websites')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    /** True when this workspace holds more than one website — the case every legacy assumption gets wrong. */
    public static function isMultiSite(int $wsId): bool
    {
        return count(self::idsIn($wsId)) > 1;
    }

    /**
     * Read a website-scoped settings row, falling back to the business-wide default.
     *
     * @param  array<string,mixed>  $extra  additional column filters (e.g. ['key' => 'site_url'])
     */
    public static function settingsRow(string $table, int $wsId, ?int $websiteId, array $extra = []): ?object
    {
        $base = fn () => DB::table($table)->where('workspace_id', $wsId)->where($extra);

        if ($websiteId !== null && $websiteId > 0) {
            if ($row = $base()->where('website_id', $websiteId)->first()) {
                return $row;
            }
        }

        return $base()->where('website_id', self::BUSINESS_DEFAULT)->first();
    }

    /** seo_settings value for a website, falling back to the business-wide default. Null when unset. */
    public static function seo(int $wsId, ?int $websiteId, string $key): ?string
    {
        $row = self::settingsRow('seo_settings', $wsId, $websiteId, ['key' => $key]);

        return $row === null ? null : (string) $row->value;
    }

    /**
     * Write a seo_settings value. Passing website_id = 0 deliberately targets the business-wide default;
     * anything else targets that one website and leaves the others alone.
     */
    public static function putSeo(int $wsId, int $websiteId, string $key, ?string $value, string $group = 'general'): void
    {
        DB::table('seo_settings')->updateOrInsert(
            ['workspace_id' => $wsId, 'website_id' => $websiteId, 'key' => $key],
            ['value' => $value, 'group' => $group, 'updated_at' => now()],
        );
    }

    /**
     * Suffix for a cache key or a job lock so that two websites in one workspace never collide. Crawls, scans
     * and progress state were all keyed on the workspace alone, which meant starting a crawl of site B
     * overwrote — and reported as its own — the progress of a crawl already running on site A.
     */
    public static function cacheSuffix(?int $websiteId): string
    {
        return ($websiteId !== null && $websiteId > 0) ? ':w' . $websiteId : '';
    }
}
