<?php

namespace App\Core\Intelligence\Providers;

use Illuminate\Support\Facades\DB;

/**
 * Reads workspace published-content posture for agent context.
 *
 * Owns the boundary to articles + websites tables — agents should know
 * how much content the workspace already has before proposing more.
 */
class ContentContextProvider
{
    public function get(int $workspaceId): array
    {
        return [
            'published_articles' => DB::table('articles')
                ->where('workspace_id', $workspaceId)
                ->where('status', 'published')
                ->count(),
            'published_websites' => DB::table('websites')
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->count(),
        ];
    }
}
