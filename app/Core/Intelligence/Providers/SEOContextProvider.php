<?php

namespace App\Core\Intelligence\Providers;

use Illuminate\Support\Facades\DB;

/**
 * Reads workspace SEO posture for injection into agent meeting prompts.
 *
 * Owns the boundary to the seo_audits table; AgentMeetingEngine must NOT
 * query SEO tables directly (architectural rule, Patch Intel Fix 5).
 */
class SEOContextProvider
{
    public function get(int $workspaceId): array
    {
        $audit = DB::table('seo_audits')
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->first();

        return [
            'seo_score'      => $audit->overall_score      ?? null,
            'seo_issues'     => $audit->critical_issues    ?? null,
            'last_audit_at'  => $audit->created_at         ?? null,
        ];
    }
}
