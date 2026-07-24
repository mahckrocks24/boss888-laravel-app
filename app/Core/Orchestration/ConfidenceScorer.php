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

    /**
     * Wave 88 — Public router. Routes to runtime where the governance
     * scoring algorithm now lives. On failure, returns null (no local
     * fallback — runtime is canonical).
     */
    public function score(string $engine, string $action, array $payload, int $wsId): array
    {
        $rt = app(\App\Connectors\RuntimeClient::class);
        if ($rt->isIntelligenceRuntimeEnabled()) {
            // Workspace history count is the only external data point —
            // load it here (DB read, not IP) and pass to runtime.
            $completed = 0;
            try {
                $completed = (int) DB::table('tasks')
                    ->where('workspace_id', $wsId)
                    ->where('engine', $engine)
                    ->where('status', 'completed')
                    ->count();
            } catch (\Throwable $e) { /* non-critical */ }
            $result = $rt->computeConfidenceScore($engine, $action, $payload, $wsId, $completed);
            if ($result !== null) return $result;
        }
        // Wave 88 Phase D — runtime is canonical. On unreachable runtime,
        // return a safe-permissive default (review tier, score 0.70).
        return [
            'score'         => 0.70,
            'reason'        => 'runtime_unavailable',
            'approval_mode' => 'review',
        ];
    }

}
