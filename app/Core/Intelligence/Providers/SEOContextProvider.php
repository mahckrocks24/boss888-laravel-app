<?php

namespace App\Core\Intelligence\Providers;

use Illuminate\Support\Facades\DB;

/**
 * Reads workspace SEO posture for injection into agent meeting prompts.
 *
 * Owns the boundary to the SEO tables; AgentMeetingEngine must NOT
 * query SEO tables directly (architectural rule, Patch Intel Fix 5).
 *
 * 2026-05-12 Phase 0 rewrite: was returning 3 nulls (queried non-existent
 * `overall_score` + `critical_issues` columns). Now returns 11 real fields
 * covering audit, content, keywords, links, and recent activity.
 */
class SEOContextProvider
{
    public function get(int $workspaceId): array
    {
        // Latest audit — uses actual `score` column (not `overall_score`)
        $audit = DB::table('seo_audits')
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->first();

        // Critical issues = audit_items rows with status='error' for that audit
        $criticalCount = 0;
        if ($audit) {
            $criticalCount = (int) DB::table('seo_audit_items')
                ->where('audit_id', $audit->id)
                ->where('status', 'error')
                ->count();
        }

        // Content stats
        $contentStats = DB::table('seo_content_index')
            ->where('workspace_id', $workspaceId)
            ->selectRaw('COUNT(*) AS total_pages, AVG(content_score) AS avg_score')
            ->first();

        // Top keywords by search volume
        $topKeywords = DB::table('seo_keywords')
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('volume')
            ->limit(5)
            ->pluck('keyword')
            ->toArray();

        $keywordsTracked = (int) DB::table('seo_keywords')
            ->where('workspace_id', $workspaceId)
            ->count();

        // Pending link suggestions
        $pendingLinks = (int) DB::table('seo_links')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'suggested')
            ->count();

        // Broken outbound links (http_status >= 400 once health-check is wired)
        $brokenOutbound = (int) DB::table('seo_outbound_links')
            ->where('workspace_id', $workspaceId)
            ->where('http_status', '>=', 400)
            ->count();

        // Orphan pages — no internal links pointing in/out
        $orphanPages = (int) DB::table('seo_content_index')
            ->where('workspace_id', $workspaceId)
            ->where('internal_link_count', 0)
            ->count();

        // Recent activity (last 3 SEO actions)
        $recentActivity = DB::table('seo_activity_log')
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->limit(3)
            ->pluck('action')
            ->toArray();

        return [
            // Audit
            'seo_score'                => $audit->score      ?? null,
            'last_audit_at'            => $audit->created_at ?? null,
            'last_audit_url'           => $audit->url        ?? null,
            'critical_issues'          => $criticalCount,
            // Content
            'pages_indexed'            => (int) ($contentStats->total_pages ?? 0),
            'avg_content_score'        => isset($contentStats->avg_score)
                                          ? round((float) $contentStats->avg_score, 1)
                                          : null,
            // Keywords
            'keywords_tracked'         => $keywordsTracked,
            'top_keywords'             => $topKeywords,
            // Links
            'pending_link_suggestions' => $pendingLinks,
            'broken_outbound_links'    => $brokenOutbound,
            'orphan_pages'             => $orphanPages,
            // Activity
            'recent_seo_activity'      => $recentActivity,
        ];
    }
}
