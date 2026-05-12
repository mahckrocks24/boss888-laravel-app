<?php

namespace App\Core\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AgentCapabilityService — Ported from runtime capability-map.js
 *
 * Defines which tools each agent is permitted to use.
 * This is the Laravel-side single source of truth for agent permissions,
 * matching the Node.js runtime capability-map.js exactly.
 *
 * Agents:
 *   dmm     → Sarah  (DMM Director — orchestration + goals)
 *   james   → James  (SEO Specialist)
 *   priya   → Priya  (Content Specialist)
 *   marcus  → Marcus (Social Specialist)
 *   elena   → Elena  (CRM Specialist)
 *   alex    → Alex   (Technical SEO Specialist)
 *
 * Source: runtime/capability-map.js (v2.25.3)
 * Ported: 2026-04-09
 */
class AgentCapabilityService
{
    /**
     * Slug aliases — Phase 1.5b fix.
     *
     * The runtime capability-map.js uses `dmm` as the canonical key for the
     * lead agent. The Laravel `agents` table uses `sarah` (the actual agent
     * slug). The orchestrator passes `sarah` into canUse(), so without an
     * alias every Sarah-assigned task hits AGENT_NOT_AUTHORIZED.
     *
     * This map is applied BEFORE the lookup in canUse() / getCapabilities()
     * so the rest of the file stays runtime-compatible.
     */
    private const SLUG_ALIASES = [
        'sarah' => 'dmm',
    ];

    /**
     * Static capability map: agent_slug → [allowed_tool_ids]
     * Matches runtime capability-map.js CAPABILITY_MAP closely; expanded
     * 2026-04-13 (Phase 1.5b) to grant Sarah creative engine actions as the
     * lead orchestrator (no other agent owns image / video generation).
     */
    private const CAPABILITY_MAP = [

        // Sarah — DMM Director
        'dmm' => [
            // SEO
            'autonomous_goal', 'list_goals', 'agent_status', 'pause_goal', 'ai_status',
            // CRM — full access including sequence discovery
            'create_lead', 'get_lead', 'update_lead', 'list_leads', 'move_lead', 'log_activity', 'add_note', 'enroll_sequence', 'list_sequences',
            // Marketing — full access including schedule + sequences
            'create_campaign', 'update_campaign', 'list_campaigns', 'schedule_campaign',
            'create_template', 'list_templates', 'create_automation', 'record_metric',
            // Marketing — execution tools
            'send_campaign', 'test_send_email',
            // Social
            'create_post', 'schedule_post', 'publish_post', 'list_posts', 'update_post', 'get_queue', 'record_social_analytics',
            // Calendar
            'create_event', 'list_events', 'update_event', 'check_availability', 'create_booking_slot',
            // Builder
            'list_builder_pages', 'get_builder_page', 'ai_builder_action', 'generate_page_layout', 'publish_builder_page', 'import_html_page',
            // Site intelligence
            'get_site_pages', 'get_site_page', 'search_site_content', 'scan_site_url',
            // Funnel intelligence
            'generate_funnel_blueprint', 'analyze_funnel_structure',
            // System intelligence
            'system_health_check', 'list_previews', 'proactive_status', 'memory_context',
            // Creative engine — Phase 1.5b: Sarah owns image/video/asset generation
            // as the orchestrator (no dedicated creative specialist in the team).
            // Phase 2A: removed 6 aspirational actions (edit_image, upscale_image,
            // remove_background, generate_variations, create_scene_plan, stitch_video)
            // that were also removed from CapabilityMapService — only register
            // capabilities that have real implementations.
            'generate_image', 'generate_video', 'create_asset',
        ],

        // James — SEO Specialist
        'james' => [
            // SEO — full access including link analysis
            'serp_analysis', 'ai_report', 'deep_audit', 'ai_status', 'list_goals', 'agent_status', 'pause_goal',
            'link_suggestions', 'outbound_links', 'check_outbound',
            // CRM — read only
            'get_lead', 'list_leads',
            // Marketing — read only
            'list_campaigns', 'record_metric',
            // Social — read only
            'list_posts', 'get_queue',
            // Calendar
            'list_events', 'check_availability', 'create_event', 'update_event',
            // Builder — read only
            'list_builder_pages', 'get_builder_page',
            // Site intelligence
            'get_site_pages', 'get_site_page', 'search_site_content', 'scan_site_url',
            // Funnel analysis
            'analyze_funnel_structure',
        ],

        // Priya — Content Specialist
        'priya' => [
            // SEO
            'write_article', 'improve_draft', 'ai_report', 'ai_status', 'list_goals', 'agent_status',
            // Marketing — content agents write and send campaigns
            'create_campaign', 'update_campaign', 'list_campaigns', 'schedule_campaign',
            'create_template', 'list_templates', 'create_automation',
            'send_campaign', 'test_send_email',
            // Social
            'create_post', 'update_post', 'list_posts',
            // Calendar
            'list_events', 'check_availability', 'create_event', 'update_event',
            // Builder — content generation
            'list_builder_pages', 'get_builder_page', 'ai_builder_action', 'generate_page_layout',
            // Site intelligence — read only
            'get_site_pages', 'get_site_page', 'search_site_content',
            // Funnel
            'generate_funnel_blueprint', 'analyze_funnel_structure',
        ],

        // Marcus — Social Media Specialist
        'marcus' => [
            // SEO — utility only
            'ai_status', 'list_goals', 'agent_status',
            // Marketing — read only
            'list_campaigns',
            // Social — full access including publish
            'create_post', 'schedule_post', 'publish_post', 'list_posts', 'update_post', 'get_queue', 'record_social_analytics',
            // Calendar
            'list_events', 'check_availability', 'create_event', 'update_event',
            // Builder — landing page for social campaigns
            'list_builder_pages', 'generate_page_layout',
            // System
            'system_health_check', 'memory_context',
        ],

        // Elena — CRM Specialist
        'elena' => [
            // SEO — utility only
            'ai_status', 'list_goals', 'agent_status',
            // CRM — full access including sequence discovery
            'create_lead', 'get_lead', 'update_lead', 'list_leads', 'move_lead', 'log_activity', 'add_note', 'enroll_sequence', 'list_sequences',
            // Marketing — CRM agents need to create and send campaigns for leads
            'create_campaign', 'update_campaign', 'list_campaigns', 'list_templates',
            'send_campaign', 'test_send_email',
            // Calendar — full access
            'create_event', 'list_events', 'update_event', 'check_availability', 'create_booking_slot',
        ],

        // Alex — Technical SEO Specialist
        'alex' => [
            // SEO — full technical access
            'deep_audit', 'link_suggestions', 'insert_link', 'dismiss_link', 'outbound_links', 'check_outbound',
            'ai_status', 'list_goals', 'agent_status', 'pause_goal',
            // Calendar
            'list_events', 'check_availability', 'create_event', 'update_event',
            // Builder — full technical access
            'list_builder_pages', 'get_builder_page', 'import_html_page',
            'hydrate_page', 'export_page', 'export_website', 'publish_builder_page', 'ai_builder_action',
            // Site intelligence — full access for technical SEO
            'get_site_pages', 'get_site_page', 'search_site_content', 'scan_site_url',
        ],

        // ─── PATCH 2026-05-09 — 14 missing specialists ────────────────
        // The 6-agent map (dmm/james/priya/marcus/elena/alex) left the
        // remaining 14 specialists in the DB with zero capabilities, so
        // every Sarah delegation to them returned AGENT_NOT_AUTHORIZED.
        // Tool sets below are aligned to each specialist's title and to
        // the bare tool IDs from runtime /health (capability-map.js).

        // Diana — Local SEO Specialist
        'diana' => [
            'serp_analysis', 'ai_report', 'ai_status', 'list_goals', 'agent_status',
            'get_lead', 'list_leads', 'log_activity', 'add_note',
            'list_events', 'check_availability', 'create_event', 'update_event',
            'list_builder_pages', 'get_builder_page',
            'get_site_pages', 'get_site_page', 'search_site_content', 'scan_site_url',
        ],

        // Ryan — Link Building Specialist
        'ryan' => [
            'link_suggestions', 'insert_link', 'dismiss_link', 'outbound_links', 'check_outbound',
            'serp_analysis', 'ai_report', 'ai_status', 'list_goals', 'agent_status',
            'improve_draft',
            'list_builder_pages', 'get_builder_page',
            'get_site_pages', 'get_site_page', 'search_site_content',
        ],

        // Sofia — International SEO Director
        'sofia' => [
            'serp_analysis', 'ai_report', 'deep_audit', 'ai_status', 'list_goals', 'agent_status',
            'write_article', 'improve_draft',
            'create_post', 'list_posts', 'update_post',
            'list_builder_pages', 'get_builder_page', 'generate_page_layout',
            'get_site_pages', 'get_site_page', 'search_site_content', 'scan_site_url',
            'analyze_funnel_structure',
        ],

        // Leo — Brand Copywriter
        'leo' => [
            'write_article', 'improve_draft',
            'list_campaigns', 'create_template', 'list_templates',
            'create_post', 'update_post', 'list_posts',
            'generate_image', 'create_asset',
            'list_builder_pages', 'get_builder_page', 'ai_builder_action',
            'get_site_pages', 'get_site_page', 'search_site_content',
        ],

        // Maya — Social Content Writer
        'maya' => [
            'write_article', 'improve_draft',
            'create_post', 'schedule_post', 'list_posts', 'update_post', 'get_queue',
            'list_events', 'check_availability',
            'list_builder_pages', 'get_builder_page',
            'get_site_pages', 'get_site_page', 'search_site_content',
        ],

        // Chris — Video Script Writer
        'chris' => [
            'write_article', 'improve_draft',
            'create_post', 'list_posts', 'update_post',
            'generate_image', 'generate_video', 'create_asset',
            'list_events', 'check_availability',
            'get_site_pages', 'get_site_page', 'search_site_content',
        ],

        // Nora — Content Strategy Director
        'nora' => [
            'write_article', 'improve_draft',
            'ai_report', 'ai_status', 'list_goals', 'agent_status',
            'create_campaign', 'update_campaign', 'list_campaigns',
            'create_template', 'list_templates', 'create_automation',
            'list_posts',
            'list_builder_pages', 'get_builder_page', 'generate_page_layout',
            'get_site_pages', 'get_site_page', 'search_site_content',
            'generate_funnel_blueprint', 'analyze_funnel_structure',
        ],

        // Zara — Instagram Growth Specialist
        'zara' => [
            'create_post', 'schedule_post', 'publish_post', 'list_posts', 'update_post',
            'get_queue', 'record_social_analytics',
            'generate_image', 'create_asset',
            'list_events', 'check_availability', 'create_event',
            'list_builder_pages', 'get_builder_page',
        ],

        // Tyler — LinkedIn Marketing Expert
        'tyler' => [
            'create_post', 'schedule_post', 'publish_post', 'list_posts', 'update_post',
            'get_queue', 'record_social_analytics',
            'write_article', 'improve_draft',
            'list_campaigns', 'create_template', 'list_templates',
            'list_events', 'check_availability', 'create_event',
        ],

        // Zoe — TikTok & Reels Creator (renamed from "aria" 2026-05-12;
        // "Aria" is reserved for the platform-assistant persona)
        'zoe' => [
            'create_post', 'schedule_post', 'publish_post', 'list_posts', 'update_post',
            'get_queue', 'record_social_analytics',
            'generate_image', 'generate_video', 'create_asset',
            'list_events', 'check_availability',
        ],

        // Jordan — Social Analytics Director
        'jordan' => [
            'list_posts', 'get_queue', 'record_social_analytics',
            'list_campaigns', 'record_metric',
            'ai_status', 'list_goals', 'agent_status',
            'list_events', 'check_availability',
            'analyze_funnel_structure',
        ],

        // Kai — Lead Nurturing Specialist
        'kai' => [
            'create_lead', 'get_lead', 'update_lead', 'list_leads', 'move_lead',
            'log_activity', 'add_note', 'enroll_sequence', 'list_sequences',
            'create_campaign', 'update_campaign', 'list_campaigns',
            'create_template', 'list_templates', 'create_automation',
            'send_campaign', 'test_send_email',
            'list_events', 'check_availability', 'create_event', 'update_event',
        ],

        // Vera — Marketing Automation Expert
        'vera' => [
            'create_campaign', 'update_campaign', 'list_campaigns', 'schedule_campaign',
            'create_template', 'list_templates', 'create_automation', 'record_metric',
            'send_campaign', 'test_send_email',
            'enroll_sequence', 'list_sequences',
            'get_lead', 'list_leads', 'update_lead', 'log_activity', 'add_note',
            'list_events', 'check_availability', 'create_event', 'update_event',
            'analyze_funnel_structure',
        ],

        // Max — Growth & CRO Director
        'max' => [
            // SEO read for funnel/audit insight
            'serp_analysis', 'ai_report', 'deep_audit', 'ai_status', 'list_goals', 'agent_status',
            // CRM full
            'create_lead', 'get_lead', 'update_lead', 'list_leads', 'move_lead',
            'log_activity', 'add_note', 'enroll_sequence', 'list_sequences',
            // Marketing — campaigns + automation for growth experiments
            'create_campaign', 'update_campaign', 'list_campaigns', 'schedule_campaign',
            'create_template', 'list_templates', 'create_automation', 'record_metric',
            'send_campaign', 'test_send_email',
            // Builder — landing-page experiments
            'list_builder_pages', 'get_builder_page', 'ai_builder_action', 'generate_page_layout',
            // Funnel intelligence
            'generate_funnel_blueprint', 'analyze_funnel_structure',
            'get_site_pages', 'get_site_page', 'search_site_content', 'scan_site_url',
        ],
    ];

    /**
     * Check if an agent can use a specific tool.
     *
     * Phase 1.5b made this tolerant of TWO long-standing format mismatches:
     *   1. SLUG ALIASES — `sarah` → `dmm` so the orchestrator's slug works
     *      against the runtime-derived capability map.
     *   2. ENGINE-PREFIXED TOOL IDS — Laravel's CapabilityMapService keys
     *      some tools with an engine prefix (`social_create_post`) while the
     *      runtime capability map uses the bare tool name (`create_post`).
     *      The check now tries both forms so Marcus's `create_post` matches
     *      Sarah's `social_create_post` request transparently. Same for any
     *      `<engine>_<action>` pattern.
     *
     * @param string $agentSlug  Agent slug (sarah, james, priya, marcus, elena, alex)
     * @param string $toolId     Tool ID (e.g. serp_analysis, create_lead, social_create_post)
     * @return bool
     */
    public function canUse(string $agentSlug, string $toolId): bool
    {
        $key = self::SLUG_ALIASES[$agentSlug] ?? $agentSlug;

        // Phase 2D — DB-first dynamic registry. Cached for 5 min so a hot
        // request path doesn't hammer MySQL. Static map remains as a runtime
        // safety net (used when agent_capabilities is empty / missing).
        if ($this->dbRegistryAvailable()) {
            $cacheKey = "agent_cap:v1:{$key}:{$toolId}";
            $hit = Cache::remember($cacheKey, 300, function () use ($key, $toolId) {
                if (DB::table('agent_capabilities')
                    ->where('agent_slug', $key)
                    ->where('tool_id', $toolId)
                    ->where('is_active', true)
                    ->exists()) {
                    return 1;
                }
                if (str_contains($toolId, '_')) {
                    $bare = preg_replace('/^[a-z]+_/', '', $toolId, 1);
                    if ($bare && $bare !== $toolId) {
                        return DB::table('agent_capabilities')
                            ->where('agent_slug', $key)
                            ->where('tool_id', $bare)
                            ->where('is_active', true)
                            ->exists() ? 1 : 0;
                    }
                }
                return 0;
            });
            if ($hit === 1) return true;
            // DB said no — but still fall through to static map so a
            // half-seeded table never breaks an agent.
        }

        $capabilities = self::CAPABILITY_MAP[$key] ?? [];
        if (in_array($toolId, $capabilities, true)) return true;
        if (str_contains($toolId, '_')) {
            $bare = preg_replace('/^[a-z]+_/', '', $toolId, 1);
            if ($bare && $bare !== $toolId && in_array($bare, $capabilities, true)) return true;
        }
        return false;
    }

    /**
     * Get all tool IDs an agent is permitted to use. Reads the DB registry
     * when present; falls back to the static map.
     */
    public function getCapabilities(string $agentSlug): array
    {
        $key = self::SLUG_ALIASES[$agentSlug] ?? $agentSlug;

        if ($this->dbRegistryAvailable()) {
            $tools = Cache::remember("agent_cap:v1:list:{$key}", 300, function () use ($key) {
                return DB::table('agent_capabilities')
                    ->where('agent_slug', $key)
                    ->where('is_active', true)
                    ->pluck('tool_id')
                    ->all();
            });
            if (!empty($tools)) return $tools;
        }
        return self::CAPABILITY_MAP[$key] ?? [];
    }

    public function getAgentsForTool(string $toolId): array
    {
        if ($this->dbRegistryAvailable()) {
            $rows = DB::table('agent_capabilities')
                ->where('tool_id', $toolId)
                ->where('is_active', true)
                ->pluck('agent_slug')
                ->all();
            if (!empty($rows)) return $rows;
        }
        $agents = [];
        foreach (self::CAPABILITY_MAP as $agentSlug => $tools) {
            if (in_array($toolId, $tools, true)) $agents[] = $agentSlug;
        }
        return $agents;
    }

    public function getAllCapabilities(): array
    {
        return self::CAPABILITY_MAP;
    }

    public function getAgentSlugs(): array
    {
        if ($this->dbRegistryAvailable()) {
            $slugs = DB::table('agent_capabilities')
                ->where('is_active', true)
                ->pluck('agent_slug')
                ->unique()
                ->values()
                ->all();
            if (!empty($slugs)) return $slugs;
        }
        return array_keys(self::CAPABILITY_MAP);
    }

    /**
     * Phase 2D — admin grant. Bumps the cache so the change is reflected
     * within seconds without waiting for the 5-min TTL.
     */
    public function grant(string $agentSlug, string $toolId, ?string $grantedBy = null): bool
    {
        $key = self::SLUG_ALIASES[$agentSlug] ?? $agentSlug;
        DB::table('agent_capabilities')->updateOrInsert(
            ['agent_slug' => $key, 'tool_id' => $toolId],
            [
                'is_active'  => true,
                'granted_at' => now(),
                'granted_by' => $grantedBy,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        Cache::forget("agent_cap:v1:{$key}:{$toolId}");
        Cache::forget("agent_cap:v1:list:{$key}");
        Log::info("AgentCapability grant: {$key} -> {$toolId}", ['by' => $grantedBy]);
        return true;
    }

    public function revoke(string $agentSlug, string $toolId, ?string $revokedBy = null): bool
    {
        $key = self::SLUG_ALIASES[$agentSlug] ?? $agentSlug;
        $n = DB::table('agent_capabilities')
            ->where('agent_slug', $key)
            ->where('tool_id', $toolId)
            ->update(['is_active' => false, 'updated_at' => now()]);
        Cache::forget("agent_cap:v1:{$key}:{$toolId}");
        Cache::forget("agent_cap:v1:list:{$key}");
        Log::info("AgentCapability revoke: {$key} -> {$toolId}", ['by' => $revokedBy, 'rows' => $n]);
        return $n > 0;
    }

    /**
     * Cheap (cached 60s) check that the registry table exists and has rows.
     * Avoids per-request schema introspection while still giving the static
     * fallback a chance if the table is dropped or empty.
     */
    private function dbRegistryAvailable(): bool
    {
        return (bool) Cache::remember('agent_cap:v1:registry_ready', 60, function () {
            try {
                if (!Schema::hasTable('agent_capabilities')) return false;
                return DB::table('agent_capabilities')->where('is_active', true)->exists();
            } catch (\Throwable $e) {
                return false;
            }
        });
    }
}
