<?php

namespace App\Core\Intelligence\Providers;

use Illuminate\Support\Facades\DB;

/**
 * Reads workspace social-channel posture for agent context.
 *
 * Owns the boundary to social_posts. Note: SocialConnector publishing is
 * currently stubbed (Patch 12 territory) so `published` counts are
 * intent-not-execution.
 */
class SocialContextProvider
{
    public function get(int $workspaceId): array
    {
        $thirtyDaysAgo = now()->subDays(30);

        $posts30d = DB::table('social_posts')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        $publishedTotal = DB::table('social_posts')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'published')
            ->count();

        return [
            'social_posts_30d'        => $posts30d,
            'social_published_total'  => $publishedTotal,
        ];
    }
}
