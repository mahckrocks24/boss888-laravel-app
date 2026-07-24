<?php

namespace App\Core\TaskSystem;

/**
 * Single source of truth for task categorization.
 *
 * Categories answer the semantic question "what kind of work is this?" — a
 * dimension distinct from engine/action (the executor) and priority/status
 * (the lifecycle). 7-category taxonomy from the 2026-05-27 masterplan.
 *
 * Used by:
 *   - TaskService::create() to auto-set category on insert
 *   - WorkspaceStateController to surface category metadata in API responses
 *   - workspace-v2 frontend to render category color on task-node cards
 *   - Reports / Pipeline / Approval Queue / Agent drawer (Phase 2+)
 *
 * To add a new dispatchable action: list it under EXPLICIT_MAP below. If
 * unmapped, ::for() falls through to ENGINE_FALLBACK then 'operations'.
 */
class TaskCategoryService
{
    public const CATEGORIES = [
        'research'   => [
            'label'            => 'Research',
            'color'            => '#3B82F6',
            'icon'             => 'search',
            'default_approval' => 'auto',
            'description'      => 'Information gathering, audits, web reading',
        ],
        'create'     => [
            'label'            => 'Create',
            'color'            => '#7C3AED',
            'icon'             => 'sparkles',
            'default_approval' => 'review',
            'description'      => 'Generative output: text, image, page, video',
        ],
        'optimize'   => [
            'label'            => 'Optimize',
            'color'            => '#00E5A8',
            'icon'             => 'tune',
            'default_approval' => 'auto',
            'description'      => 'Adjustments to live assets',
        ],
        'publish'    => [
            'label'            => 'Publish',
            'color'            => '#F59E0B',
            'icon'             => 'send',
            'default_approval' => 'protected',
            'description'      => 'Going live or distribution',
        ],
        'crm'        => [
            'label'            => 'CRM',
            'color'            => '#EC4899',
            'icon'             => 'users',
            'default_approval' => 'review',
            'description'      => 'Lead, contact, deal lifecycle',
        ],
        'campaign'   => [
            'label'            => 'Campaign',
            'color'            => '#F97316',
            'icon'             => 'megaphone',
            'default_approval' => 'review',
            'description'      => 'Multi-step marketing orchestration',
        ],
        'operations' => [
            'label'            => 'Operations',
            'color'            => '#6B7280',
            'icon'             => 'cog',
            'default_approval' => 'auto',
            'description'      => 'System housekeeping, governance',
        ],
    ];

    /**
     * Explicit (engine, action) → category map. Order matches the SQL
     * backfill in the masterplan §3 for consistency.
     */
    private const EXPLICIT_MAP = [
        // ── Research (information / audit / web) ─────────────────────
        'seo/serp_analysis'    => 'research',
        'seo/keyword_research' => 'research',
        'seo/keywords_suggest' => 'research',
        'seo/keyword_check'    => 'research',
        'seo/ai_report'        => 'research',
        'seo/deep_audit'       => 'research',
        'seo/list_keywords'    => 'research',
        'seo/list_goals'       => 'research',
        'seo/agent_status'     => 'research',
        'seo/ai_status'        => 'research',
        'crm/list_leads'       => 'research',
        'web/fetch'            => 'research',
        'web/search'           => 'research',
        // ── Optimize (adjustments to live assets) ─────────────────────
        'seo/insert_link'      => 'optimize',
        'seo/dismiss_link'     => 'optimize',
        'seo/generate_links'   => 'optimize',
        'seo/check_outbound'   => 'optimize',
        'seo/outbound_links'   => 'optimize',
        'seo/add_keyword'      => 'optimize',
        'seo/autonomous_goal'  => 'optimize',
        'seo/pause_goal'       => 'optimize',
        'seo/resume_goal'      => 'optimize',
        'seo/improve_draft'    => 'optimize',
        'write/improve_draft'  => 'optimize',
        'write/aeo_enrich'     => 'optimize',
        'write/generate_outline'   => 'optimize',
        'write/generate_headlines' => 'optimize',
        'write/generate_meta'  => 'optimize',
        // ── Create (generative output) ────────────────────────────────
        'write/write_article'  => 'create',
        'write/create_article' => 'create',
        'seo/write_article'    => 'create',
        'social/create_post'        => 'create',
        'social/social_create_post' => 'create',
        'creative/generate_image'      => 'create',
        'creative/generate_image_mini' => 'create',
        'creative/generate_image_high' => 'create',
        'builder/create_website'  => 'create',
        'builder/generate_page'   => 'create',
        'builder/wizard_generate' => 'create',
        'beforeafter/ba_transform'  => 'create',
        'beforeafter/create_design' => 'create',
        'manualedit/create_canvas'  => 'create',
        // ── Publish (live distribution) ───────────────────────────────
        'write/publish_article'       => 'publish',
        'social/social_publish_post'  => 'publish',
        'social/social_schedule_post' => 'publish',
        'builder/publish_website'     => 'publish',
        // ── CRM ─────────────────────────────────────────────────────────
        'crm/create_lead'        => 'crm',
        'crm/update_lead'        => 'crm',
        'crm/delete_lead'        => 'crm',
        'crm/score_lead'         => 'crm',
        'crm/assign_lead'        => 'crm',
        'crm/import_leads'       => 'crm',
        'crm/create_contact'     => 'crm',
        'crm/create_deal'        => 'crm',
        'crm/update_deal_stage'  => 'crm',
        'crm/log_activity'       => 'crm',
        'crm/add_note'           => 'crm',
        // ── Campaign (orchestrated marketing) ─────────────────────────
        'marketing/create_campaign'   => 'campaign',
        'marketing/schedule_campaign' => 'campaign',
        'marketing/create_automation' => 'campaign',
        'marketing/list_campaigns'    => 'campaign',
        // ── Operations (governance, housekeeping, destructive) ────────
        'write/delete_article'  => 'operations',
        'social/delete_post'    => 'operations',
        'tasks/retry_blocked'   => 'operations',
        'traffic/create_rule'   => 'operations',
        'calendar/create_event' => 'operations',
    ];

    /**
     * Engine-level fallback used when (engine, action) isn't in EXPLICIT_MAP.
     */
    private const ENGINE_FALLBACK = [
        'web'         => 'research',
        'agent_browser' => 'research',
        'seo'         => 'optimize',
        'write'       => 'create',
        'creative'    => 'create',
        'social'      => 'create',
        'builder'     => 'create',
        'beforeafter' => 'create',
        'manualedit'  => 'create',
        'crm'         => 'crm',
        'marketing'   => 'campaign',
        'calendar'    => 'operations',
        'traffic'     => 'operations',
        'tasks'       => 'operations',
        'system'      => 'operations',
    ];

    /**
     * Derive category from (engine, action).
     *
     * Precedence: EXPLICIT_MAP[engine/action] → ENGINE_FALLBACK[engine] → 'operations'.
     */
    public function for(string $engine, string $action): string
    {
        $key = $engine . '/' . $action;
        if (isset(self::EXPLICIT_MAP[$key])) {
            return self::EXPLICIT_MAP[$key];
        }
        return self::ENGINE_FALLBACK[$engine] ?? 'operations';
    }

    public function metadata(string $category): array
    {
        return self::CATEGORIES[$category] ?? self::CATEGORIES['operations'];
    }

    public function all(): array
    {
        return self::CATEGORIES;
    }

    public function isValid(string $category): bool
    {
        return isset(self::CATEGORIES[$category]);
    }
}
