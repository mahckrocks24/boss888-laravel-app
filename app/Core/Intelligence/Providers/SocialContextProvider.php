<?php

namespace App\Core\Intelligence\Providers;

use App\Engines\Social\Services\SocialInsightsService;

/**
 * Reads workspace social-channel posture for agent context.
 *
 * SOC-P1-5 (2026-09-04): this now DELEGATES to SocialInsightsService — the ONE authoritative
 * Social Insights contract — so Sarah reasons on exactly the same data Manual Mode shows. It no
 * longer computes its own counts (which drifted: the old comment admitted "intent-not-execution").
 *
 * HONESTY: engagement metrics (impressions/reach/likes/clicks) are provider-sourced and are NOT
 * available until a social account is connected + platform review clears. This provider exposes
 * `social_engagement_available=false` + a note so Sarah never claims a metric that does not exist.
 */
class SocialContextProvider
{
    public function get(int $workspaceId): array
    {
        $o  = app(SocialInsightsService::class)->overview($workspaceId, ['website_id' => 'all', 'period_days' => 30]);
        $t  = $o['own_data']['totals'] ?? [];
        $pe = $o['provider_engagement'] ?? [];

        return [
            // backward-compatible keys (existing consumers keep working)
            'social_posts_30d'        => $t['period_posts'] ?? 0,
            'social_published_total'  => $t['published'] ?? 0,

            // authoritative own-data (source: levelup_records)
            'social_scheduled'        => $t['scheduled'] ?? 0,
            'social_drafts'           => $t['draft'] ?? 0,
            'social_by_platform'      => $o['own_data']['by_platform'] ?? [],
            'social_format_mix'       => $o['own_data']['format_mix'] ?? [],

            // HONESTY GUARD for the agent: do NOT claim engagement metrics that are not available.
            'social_engagement_available' => (bool) ($pe['available'] ?? false),
            'social_engagement_note'      => $pe['message'] ?? null,
            'social_insight_sample'       => $o['sample'] ?? [],
            'social_insight_source'       => 'SocialInsightsService',
        ];
    }
}
