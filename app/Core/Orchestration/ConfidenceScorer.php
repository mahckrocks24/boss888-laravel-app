<?php

namespace App\Core\Orchestration;

use Illuminate\Support\Facades\DB;

/**
 * ConfidenceScorer — heuristic 0.0..1.0 confidence per (engine, action,
 * payload, workspace).
 *
 * Read-only / introspection actions score high. External-facing writes
 * score low. Workspace history nudges the score up. Bulk operations and
 * payloads with unusual signatures nudge it down. The output `approval_mode`
 * is informational — the canonical gate remains CapabilityMapService —
 * but downstream callers may downgrade to `protected` on very low scores.
 */
class ConfidenceScorer
{
    /** Read-only actions: high confidence. */
    private const READ_ONLY = [
        'serp_analysis', 'deep_audit', 'ai_report', 'ai_status',
        'list_goals', 'agent_status', 'list_leads', 'get_lead',
        'list_campaigns', 'list_templates', 'list_posts', 'get_queue',
        'list_events', 'check_availability', 'list_builder_pages',
        'get_builder_page', 'get_site_pages', 'get_site_page',
        'search_site_content', 'analyze_funnel_structure',
        'list_sequences', 'record_metric', 'record_social_analytics',
    ];

    /** External-facing writes: lower default confidence. */
    private const EXTERNAL_WRITES = [
        'create_post', 'social_create_post', 'social_publish_post',
        'publish_post', 'send_campaign', 'schedule_campaign',
        'publish_builder_page', 'publish_website',
    ];

    public function score(string $engine, string $action, array $payload, int $wsId): array
    {
        $score = 0.70;
        $reasons = [];

        // Action class
        if (in_array($action, self::READ_ONLY, true)) {
            $score = 0.95;
            $reasons[] = 'read-only';
        } elseif (in_array($action, self::EXTERNAL_WRITES, true)) {
            $score = 0.40;
            $reasons[] = 'external-facing write';
        }

        // Workspace history with this engine — proven track record nudges up.
        try {
            $completed = DB::table('tasks')
                ->where('workspace_id', $wsId)
                ->where('engine', $engine)
                ->where('status', 'completed')
                ->count();
            if ($completed >= 25) {
                $score = min(1.0, $score + 0.10);
                $reasons[] = "engine has {$completed} completed runs in this workspace";
            } elseif ($completed >= 10) {
                $score = min(1.0, $score + 0.05);
                $reasons[] = "engine has {$completed} completed runs in this workspace";
            }
        } catch (\Throwable $e) {
            // Non-critical — score without history bump.
        }

        // Bulk hint nudges down.
        if (!empty($payload['bulk']) || (isset($payload['count']) && (int)$payload['count'] > 10)) {
            $score = max(0.0, $score - 0.20);
            $reasons[] = 'bulk operation';
        }

        // External recipient list nudges down further (email blast, mass post).
        if (!empty($payload['recipients']) && is_array($payload['recipients']) && count($payload['recipients']) > 50) {
            $score = max(0.0, $score - 0.15);
            $reasons[] = 'large recipient list (' . count($payload['recipients']) . ')';
        }

        $approval = match (true) {
            $score >= 0.90 => 'auto',
            $score >= 0.60 => 'review',
            default        => 'protected',
        };

        return [
            'score'         => round($score, 3),
            'reason'        => implode('; ', $reasons) ?: 'baseline',
            'approval_mode' => $approval,
        ];
    }
}
