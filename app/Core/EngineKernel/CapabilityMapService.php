<?php

namespace App\Core\EngineKernel;

use App\Models\EngineRegistry;
use App\Connectors\ConnectorResolver;

class CapabilityMapService
{
    private array $capabilityMap = [
        // ── CRM Engine (internal) ────────────────────────────────
        'create_lead'         => ['engine'=>'crm',       'connector'=>null,       'action'=>'create_lead',         'approval_mode'=>'review',    'credit_cost'=>0],  // FIX-7: runtime requires_approval:true → review
        'update_lead'         => ['engine'=>'crm',       'connector'=>null,       'action'=>'update_lead',         'approval_mode'=>'auto',      'credit_cost'=>0],
        'delete_lead'         => ['engine'=>'crm',       'connector'=>null,       'action'=>'delete_lead',         'approval_mode'=>'auto',      'credit_cost'=>0],
        'import_leads'        => ['engine'=>'crm',       'connector'=>null,       'action'=>'import_leads',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'create_deal'         => ['engine'=>'crm',       'connector'=>null,       'action'=>'create_deal',         'approval_mode'=>'auto',      'credit_cost'=>0],
        'update_deal_stage'   => ['engine'=>'crm',       'connector'=>null,       'action'=>'update_deal_stage',   'approval_mode'=>'auto',      'credit_cost'=>0],
        'create_contact'      => ['engine'=>'crm',       'connector'=>null,       'action'=>'create_contact',      'approval_mode'=>'auto',      'credit_cost'=>0],
        'log_activity'        => ['engine'=>'crm',       'connector'=>null,       'action'=>'log_activity',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'score_lead'          => ['engine'=>'crm',       'connector'=>null,       'action'=>'score_lead',          'approval_mode'=>'auto',      'credit_cost'=>1],

        // ── SEO Engine (15 tools) ────────────────────────────────
        'serp_analysis'       => ['engine'=>'seo',       'connector'=>null,       'action'=>'serp_analysis',       'approval_mode'=>'auto',      'credit_cost'=>5],
        'ai_report'           => ['engine'=>'seo',       'connector'=>null,       'action'=>'ai_report',           'approval_mode'=>'auto',      'credit_cost'=>10],
        'deep_audit'          => ['engine'=>'seo',       'connector'=>null,       'action'=>'deep_audit',          'approval_mode'=>'auto',      'credit_cost'=>15],
        'improve_draft'       => ['engine'=>'write',     'connector'=>null,       'action'=>'improve_draft',       'approval_mode'=>'review',    'credit_cost'=>5],
        'write_article'       => ['engine'=>'write',     'connector'=>null,       'action'=>'write_article',       'approval_mode'=>'review',    'credit_cost'=>10],
        'ai_status'           => ['engine'=>'seo',       'connector'=>null,       'action'=>'ai_status',           'approval_mode'=>'auto',      'credit_cost'=>0],
        'link_suggestions'    => ['engine'=>'seo',       'connector'=>null,       'action'=>'link_suggestions',    'approval_mode'=>'auto',      'credit_cost'=>3],
        'insert_link'         => ['engine'=>'seo',       'connector'=>null,       'action'=>'insert_link',         'approval_mode'=>'review',    'credit_cost'=>2],
        'dismiss_link'        => ['engine'=>'seo',       'connector'=>null,       'action'=>'dismiss_link',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'outbound_links'      => ['engine'=>'seo',       'connector'=>null,       'action'=>'outbound_links',      'approval_mode'=>'auto',      'credit_cost'=>2],
        'check_outbound'      => ['engine'=>'seo',       'connector'=>null,       'action'=>'check_outbound',      'approval_mode'=>'auto',      'credit_cost'=>2],
        'autonomous_goal'     => ['engine'=>'seo',       'connector'=>null,       'action'=>'autonomous_goal',     'approval_mode'=>'protected', 'credit_cost'=>20],
        'agent_status'        => ['engine'=>'seo',       'connector'=>null,       'action'=>'agent_status',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'list_goals'          => ['engine'=>'seo',       'connector'=>null,       'action'=>'list_goals',          'approval_mode'=>'auto',      'credit_cost'=>0],
        'pause_goal'          => ['engine'=>'seo',       'connector'=>null,       'action'=>'pause_goal',          'approval_mode'=>'auto',      'credit_cost'=>0],
'resume_goal'         => ['engine'=>'seo',       'connector'=>null,       'action'=>'resume_goal',         'approval_mode'=>'auto',      'credit_cost'=>0],        'add_keyword'         => ['engine'=>'seo',       'connector'=>null,       'action'=>'add_keyword',         'approval_mode'=>'auto',      'credit_cost'=>0],        'generate_links'      => ['engine'=>'seo',       'connector'=>null,       'action'=>'generate_links',      'approval_mode'=>'auto',      'credit_cost'=>3],

        // ── Write / Content Engine ───────────────────────────────
        // PATCH 2026-04-19: create_article was called from WriteController::createArticle
        // (manual "+ New Post" button in the blog engine) but missing from this map,
        // causing "Unknown action: write/create_article" on every new-post click.
        // Auto-approved, zero-credit — creating an empty draft shouldn't charge anyone.
        'create_article'      => ['engine'=>'write',     'connector'=>null,       'action'=>'create_article',      'approval_mode'=>'auto',      'credit_cost'=>0],
        'generate_outline'    => ['engine'=>'write',     'connector'=>null,       'action'=>'generate_outline',    'approval_mode'=>'auto',      'credit_cost'=>3],
        'generate_headlines'  => ['engine'=>'write',     'connector'=>null,       'action'=>'generate_headlines',  'approval_mode'=>'auto',      'credit_cost'=>2],
        'generate_meta'       => ['engine'=>'write',     'connector'=>null,       'action'=>'generate_meta',       'approval_mode'=>'auto',      'credit_cost'=>2],

        // ── Creative Engine (native AI) ──────────────────────────
        'generate_image'      => ['engine'=>'creative',  'connector'=>'creative', 'action'=>'generate_image',      'approval_mode'=>'auto',      'credit_cost'=>10],
        'generate_video'      => ['engine'=>'creative',  'connector'=>'creative', 'action'=>'generate_video',      'approval_mode'=>'review',    'credit_cost'=>25],
        // Phase 2A: removed 6 unimplemented aspirational creative actions that had
        // registered capabilities but no CreativeService implementation. Leaving them
        // registered caused Sarah's planner to include them in plans, then
        // EngineExecutionService threw "Unknown Creative action: upscale_image" at
        // execution time. These can be re-added when the implementations ship.
        //   REMOVED: edit_image, upscale_image, remove_background, generate_variations,
        //            create_scene_plan, stitch_video

        // ── Builder Engine ───────────────────────────────────────
        'create_website'      => ['engine'=>'builder',   'connector'=>null,       'action'=>'create_website',      'approval_mode'=>'auto',      'credit_cost'=>0],
        'generate_page'       => ['engine'=>'builder',   'connector'=>null,       'action'=>'generate_page',       'approval_mode'=>'auto',    'credit_cost'=>10],
        'publish_website'     => ['engine'=>'builder',   'connector'=>null,       'action'=>'publish_website',     'approval_mode'=>'auto', 'credit_cost'=>0],
        // PATCH v1.0.1: wizard_generate was missing — hit fallback (was zero-cost passthrough, now INVALID_ACTION)
        'wizard_generate'     => ['engine'=>'builder',   'connector'=>null,       'action'=>'wizard_generate',     'approval_mode'=>'auto',      'credit_cost'=>1],

        // ── Marketing Engine ─────────────────────────────────────
        'create_campaign'     => ['engine'=>'marketing', 'connector'=>null,       'action'=>'create_campaign',     'approval_mode'=>'review',    'credit_cost'=>5],
        'send_campaign'       => ['engine'=>'marketing', 'connector'=>'email',    'action'=>'send_campaign',       'approval_mode'=>'protected', 'credit_cost'=>10],
        'schedule_campaign'   => ['engine'=>'marketing', 'connector'=>null,       'action'=>'schedule_campaign',   'approval_mode'=>'auto',      'credit_cost'=>5],  // FIX-7: runtime requires_approval:false → auto
        // Phase 3 fix: removed 'send_email' (1cr) — no MarketingService::sendEmail() method exists.
        // Re-add when single-email sending is implemented (separate from campaign sends).
        'create_automation'   => ['engine'=>'marketing', 'connector'=>null,       'action'=>'create_automation',   'approval_mode'=>'review',    'credit_cost'=>5],

        // ── Social Engine ────────────────────────────────────────
        'social_create_post'  => ['engine'=>'social',    'connector'=>'social',   'action'=>'create_post',         'approval_mode'=>'review',    'credit_cost'=>3],
        'social_publish_post' => ['engine'=>'social',    'connector'=>'social',   'action'=>'publish_post',        'approval_mode'=>'protected', 'credit_cost'=>2],
        'social_schedule_post'=> ['engine'=>'social',    'connector'=>null,       'action'=>'schedule_post',       'approval_mode'=>'review',    'credit_cost'=>2],

        // ── Calendar Engine (internal) ───────────────────────────
        'create_event'        => ['engine'=>'calendar',  'connector'=>null,       'action'=>'create_event',        'approval_mode'=>'review',    'credit_cost'=>0],  // FIX-7: runtime requires_approval:true → review

        // ── BeforeAfter Engine ───────────────────────────────────
        'ba_transform'        => ['engine'=>'beforeafter','connector'=>'creative','action'=>'ba_transform',        'approval_mode'=>'auto',      'credit_cost'=>15],
        'ba_design_report'    => ['engine'=>'beforeafter','connector'=>null,      'action'=>'ba_design_report',    'approval_mode'=>'auto',      'credit_cost'=>10],

        // ── Traffic Defense Engine ────────────────────────────────
        // PATCH v1.0.1: create_rule was missing — hit fallback (was zero-cost passthrough, now INVALID_ACTION)
        'create_rule'         => ['engine'=>'traffic',   'connector'=>null,       'action'=>'create_rule',         'approval_mode'=>'auto',      'credit_cost'=>0],

        // ── ManualEdit Engine ─────────────────────────────────────
        // PATCH v1.0.1: create_canvas was missing — hit fallback (was zero-cost passthrough, now INVALID_ACTION)
        'create_canvas'       => ['engine'=>'manualedit','connector'=>null,       'action'=>'create_canvas',       'approval_mode'=>'auto',      'credit_cost'=>0],

        // -- CRM Engine (reads + sequences) -- PATCH v1.0.2 -----------
        'get_lead'              => ['engine'=>'crm',       'connector'=>null,       'action'=>'get_lead',              'approval_mode'=>'auto',      'credit_cost'=>0],
        'list_leads'            => ['engine'=>'crm',       'connector'=>null,       'action'=>'list_leads',            'approval_mode'=>'auto',      'credit_cost'=>0],
        'move_lead'             => ['engine'=>'crm',       'connector'=>null,       'action'=>'move_lead',             'approval_mode'=>'auto',      'credit_cost'=>0],
        'add_note'              => ['engine'=>'crm',       'connector'=>null,       'action'=>'add_note',              'approval_mode'=>'auto',      'credit_cost'=>0],
        // Phase 3 fix: removed 'enroll_sequence' (2cr) — no CrmService::enrollSequence() method exists.
        'list_sequences'        => ['engine'=>'crm',       'connector'=>null,       'action'=>'list_sequences',        'approval_mode'=>'auto',      'credit_cost'=>0],

        // -- Marketing Engine (reads + templates) -- PATCH v1.0.2 -----
        'update_campaign'       => ['engine'=>'marketing', 'connector'=>null,       'action'=>'update_campaign',       'approval_mode'=>'auto',      'credit_cost'=>0],
        'list_campaigns'        => ['engine'=>'marketing', 'connector'=>null,       'action'=>'list_campaigns',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'create_template'       => ['engine'=>'marketing', 'connector'=>null,       'action'=>'create_template',       'approval_mode'=>'auto',      'credit_cost'=>0],
        'list_templates'        => ['engine'=>'marketing', 'connector'=>null,       'action'=>'list_templates',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'record_metric'         => ['engine'=>'marketing', 'connector'=>null,       'action'=>'record_metric',         'approval_mode'=>'auto',      'credit_cost'=>0],
        // Phase 3 fix: removed 'test_send_email' (1cr) — no MarketingService::testSendEmail() method exists.

        // -- Social Engine (reads + queue) -- PATCH v1.0.2 ------------
        'update_post'           => ['engine'=>'social',    'connector'=>null,       'action'=>'update_post',           'approval_mode'=>'auto',      'credit_cost'=>0],
        'list_posts'            => ['engine'=>'social',    'connector'=>null,       'action'=>'list_posts',            'approval_mode'=>'auto',      'credit_cost'=>0],
        'get_queue'             => ['engine'=>'social',    'connector'=>null,       'action'=>'get_queue',             'approval_mode'=>'auto',      'credit_cost'=>0],
        'record_social_analytics' => ['engine'=>'social',  'connector'=>null,       'action'=>'record_social_analytics','approval_mode'=>'auto',     'credit_cost'=>0],

        // -- Calendar Engine (reads + booking) -- PATCH v1.0.2 --------
        'list_events'           => ['engine'=>'calendar',  'connector'=>null,       'action'=>'list_events',           'approval_mode'=>'auto',      'credit_cost'=>0],
        'update_event'          => ['engine'=>'calendar',  'connector'=>null,       'action'=>'update_event',          'approval_mode'=>'auto',      'credit_cost'=>0],
        'check_availability'    => ['engine'=>'calendar',  'connector'=>null,       'action'=>'check_availability',    'approval_mode'=>'auto',      'credit_cost'=>0],
        'create_booking_slot'   => ['engine'=>'calendar',  'connector'=>null,       'action'=>'create_booking_slot',   'approval_mode'=>'review',    'credit_cost'=>0],  // FIX-7: runtime requires_approval:true → review

        // -- Builder Engine (reads + AI) -- PATCH v1.0.2 --------------
        'list_builder_pages'    => ['engine'=>'builder',   'connector'=>null,       'action'=>'list_builder_pages',    'approval_mode'=>'auto',      'credit_cost'=>0],
        'get_builder_page'      => ['engine'=>'builder',   'connector'=>null,       'action'=>'get_builder_page',      'approval_mode'=>'auto',      'credit_cost'=>0],
        // Phase 3 fix: removed 'ai_builder_action' (5cr), 'generate_page_layout' (10cr) —
        // no BuilderService methods exist for either. Re-add when builder AI features ship.
        'publish_builder_page'=> ['engine'=>'builder',   'connector'=>null,       'action'=>'publish_builder_page','approval_mode'=>'auto',    'credit_cost'=>0],
        'import_html_page'      => ['engine'=>'builder',   'connector'=>null,       'action'=>'import_html_page',      'approval_mode'=>'auto',      'credit_cost'=>0],

        // -- Site Engine -- PATCH v1.0.2 ------------------------------
        'get_site_pages'        => ['engine'=>'site',      'connector'=>null,       'action'=>'get_site_pages',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'get_site_page'         => ['engine'=>'site',      'connector'=>null,       'action'=>'get_site_page',         'approval_mode'=>'auto',      'credit_cost'=>0],
        'search_site_content'   => ['engine'=>'site',      'connector'=>null,       'action'=>'search_site_content',   'approval_mode'=>'auto',      'credit_cost'=>0],
        // Phase 3 fix: removed 'scan_site_url' (2cr) — no 'site' engine dispatcher exists in EES.

        // Phase 3 fix: removed 'generate_funnel_blueprint' (10cr), 'analyze_funnel_structure' (5cr) —
        // no 'funnel' engine or dispatcher exists. These were aspirational from the original seed.
        // Re-add when funnel engine ships.
    ];

    public function __construct(private ConnectorResolver $connectorResolver) {}

    public function resolve(string $action): ?array
    {
        return $this->capabilityMap[$action] ?? null;
    }

    public function resolveEngine(string $action): ?EngineRegistry
    {
        $cap = $this->resolve($action);
        if (! $cap) {
            return null;
        }
        return EngineRegistry::where('slug', $cap['engine'])->where('status', 'active')->first();
    }

    public function getValidationRules(string $action): array
    {
        $cap = $this->resolve($action);
        if (! $cap || ! $cap['connector']) {
            return [];
        }
        if (! $this->connectorResolver->has($cap['connector'])) {
            return [];
        }
        $connector = $this->connectorResolver->resolve($cap['connector']);
        return $connector->validationRules($cap['action']);
    }

    public function getApprovalMode(string $action): string
    {
        $cap = $this->resolve($action);
        return $cap['approval_mode'] ?? 'review';
    }

    public function getCreditCost(string $action): int
    {
        $cap = $this->resolve($action);
        return $cap['credit_cost'] ?? 0;
    }

    public function isConnectorAvailable(string $action): bool
    {
        $cap = $this->resolve($action);
        if (! $cap) {
            return false;
        }
        if (! $cap['connector']) {
            return true;
        }
        return $this->connectorResolver->has($cap['connector']);
    }

    public function getAllCapabilities(): array
    {
        return $this->capabilityMap;
    }

    /**
     * Resolve an engine/action pair for EngineExecutionService.
     * Returns capability config or null if unknown.
     */
    public function resolveAction(string $engine, string $action): ?array
    {
        // Try exact match first
        $cap = $this->resolve($action);
        if ($cap) {
            return array_merge($cap, [
                'credit_cost'    => $this->getCreditCost($action),
                'approval_level' => $this->getApprovalMode($action),
            ]);
        }

        // Try engine-prefixed (e.g. 'social_create_post' for engine=social, action=create_post)
        $prefixed = "{$engine}_{$action}";
        $cap = $this->resolve($prefixed);
        if ($cap) {
            return array_merge($cap, [
                'credit_cost'    => $this->getCreditCost($prefixed),
                'approval_level' => $this->getApprovalMode($prefixed),
            ]);
        }

        // PATCH v1.0.2: Name aliases for tools known by alternate names in runtime
        static $aliases = [
            'schedule_post'     => 'social_schedule_post',
            'create_post'       => 'social_create_post',
            'publish_post'      => 'social_publish_post',
            'schedule_social'   => 'social_schedule_post',
            'builder_generate'  => 'wizard_generate',
            'publish_page'      => 'publish_builder_page',
            'site_scan'         => 'scan_site_url',
        ];
        if (isset($aliases[$action])) {
            $cap = $this->resolve($aliases[$action]);
            if ($cap) {
                return array_merge($cap, [
                    'credit_cost'    => $this->getCreditCost($aliases[$action]),
                    'approval_level' => $this->getApprovalMode($aliases[$action]),
                ]);
            }
        }

        // PATCH v1.0.1: was returning a zero-cost auto-approve default for any unknown action.
        // This bypassed credit deduction and approval gating for anything not in the map —
        // a silent monetization leak and a governance hole.
        //
        // Correct behaviour: return null. EngineExecutionService::execute() checks for null
        // and returns ['success' => false, 'code' => 'INVALID_ACTION'] before any credit or
        // approval logic runs. Unknown actions must be registered here before they work.
        //
        // To add a new action: add an entry to $this->capabilityMap above.
        return null;
    }
}
