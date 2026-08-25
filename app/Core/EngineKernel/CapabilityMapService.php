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
        // 2026-05-30 — escalated auto → review. Lead deletion is irreversible
        // and the previous auto-approval meant any agent-driven path bypassed
        // the human gate. The CRM UI's _crmDelLead already has a button-level
        // luConfirm; this brings chat/agent paths in line.
        'delete_lead'         => ['engine'=>'crm',       'connector'=>null,       'action'=>'delete_lead',         'approval_mode'=>'review',    'credit_cost'=>0],
        'import_leads'        => ['engine'=>'crm',       'connector'=>null,       'action'=>'import_leads',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'create_deal'         => ['engine'=>'crm',       'connector'=>null,       'action'=>'create_deal',         'approval_mode'=>'auto',      'credit_cost'=>0],
        'update_deal_stage'   => ['engine'=>'crm',       'connector'=>null,       'action'=>'update_deal_stage',   'approval_mode'=>'auto',      'credit_cost'=>0],
        'create_contact'      => ['engine'=>'crm',       'connector'=>null,       'action'=>'create_contact',      'approval_mode'=>'auto',      'credit_cost'=>0],
        'log_activity'        => ['engine'=>'crm',       'connector'=>null,       'action'=>'log_activity',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'score_lead'          => ['engine'=>'crm',       'connector'=>null,       'action'=>'score_lead',          'approval_mode'=>'auto',      'credit_cost'=>1],

        // ── SEO Engine (15 tools) ────────────────────────────────
        'serp_analysis'       => ['engine'=>'seo',       'connector'=>null,       'action'=>'serp_analysis',       'approval_mode'=>'auto',      'credit_cost'=>1],
        'ai_report'           => ['engine'=>'seo',       'connector'=>null,       'action'=>'ai_report',           'approval_mode'=>'auto',      'credit_cost'=>2],
        'deep_audit'          => ['engine'=>'seo',       'connector'=>null,       'action'=>'deep_audit',          'approval_mode'=>'auto',      'credit_cost'=>3],
        'improve_draft'       => ['engine'=>'write',     'connector'=>null,       'action'=>'improve_draft',       'approval_mode'=>'review',    'credit_cost'=>2],
        'write_article'       => ['engine'=>'write',     'connector'=>null,       'action'=>'write_article',       'approval_mode'=>'review',    'credit_cost'=>1],
        'fill_missing_images' => ['engine'=>'write',     'connector'=>null,       'action'=>'fill_missing_images', 'approval_mode'=>'auto',      'credit_cost'=>0],
        // 2026-07-23 — was MISSING; every publish_article task died at
        // "No capability mapped" before executing. See fix note in git/backup.
        'publish_article'     => ['engine'=>'write',     'connector'=>null,       'action'=>'publish_article',     'approval_mode'=>'protected', 'credit_cost'=>0],
        // 2026-07-23 — was MISSING; 'tasks/retry_blocked' has an executor
        // (TaskRetryService) + category 'operations' + is in Sarah's prompt,
        // but resolved null here -> "No capability mapped" (ws2 task 2164 failed).
        // Retries ALREADY-blocked (already-approved) tasks, so approval_mode=auto.
        'retry_blocked'       => ['engine'=>'tasks',     'connector'=>null,       'action'=>'retry_blocked',       'approval_mode'=>'auto',      'credit_cost'=>0],
        'ai_status'           => ['engine'=>'seo',       'connector'=>null,       'action'=>'ai_status',           'approval_mode'=>'auto',      'credit_cost'=>0],
        'link_suggestions'    => ['engine'=>'seo',       'connector'=>null,       'action'=>'link_suggestions',    'approval_mode'=>'auto',      'credit_cost'=>1],
        'insert_link'         => ['engine'=>'seo',       'connector'=>null,       'action'=>'insert_link',         'approval_mode'=>'review',    'credit_cost'=>2],
        // 2026-06-11 — first-class orphan fix Sarah can delegate as one task.
        // credit_cost=0 here because fixOrphans SELF-BILLS the exact applied count
        // (2cr/insert) via the atomic pipeline — a fixed upfront reservation would
        // over/under-charge a variable bulk op. auto so Sarah can trigger directly.
        'fix_orphans'         => ['engine'=>'seo',       'connector'=>null,       'action'=>'fix_orphans',         'approval_mode'=>'auto',      'credit_cost'=>0],
        // GSC data pull (not generation) — free, auto so agents can refresh rankings directly.
        'gsc_sync'            => ['engine'=>'seo',       'connector'=>null,       'action'=>'gsc_sync',            'approval_mode'=>'auto',      'credit_cost'=>0],
        'dismiss_link'        => ['engine'=>'seo',       'connector'=>null,       'action'=>'dismiss_link',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'outbound_links'      => ['engine'=>'seo',       'connector'=>null,       'action'=>'outbound_links',      'approval_mode'=>'auto',      'credit_cost'=>2],
        'check_outbound'      => ['engine'=>'seo',       'connector'=>null,       'action'=>'check_outbound',      'approval_mode'=>'auto',      'credit_cost'=>2],
        'autonomous_goal'     => ['engine'=>'seo',       'connector'=>null,       'action'=>'autonomous_goal',     'approval_mode'=>'protected', 'credit_cost'=>5],
        'agent_status'        => ['engine'=>'seo',       'connector'=>null,       'action'=>'agent_status',        'approval_mode'=>'auto',      'credit_cost'=>0],
        'list_goals'          => ['engine'=>'seo',       'connector'=>null,       'action'=>'list_goals',          'approval_mode'=>'auto',      'credit_cost'=>0],
        'pause_goal'          => ['engine'=>'seo',       'connector'=>null,       'action'=>'pause_goal',          'approval_mode'=>'auto',      'credit_cost'=>0],
'resume_goal'         => ['engine'=>'seo',       'connector'=>null,       'action'=>'resume_goal',         'approval_mode'=>'auto',      'credit_cost'=>0],        'add_keyword'         => ['engine'=>'seo',       'connector'=>null,       'action'=>'add_keyword',         'approval_mode'=>'auto',      'credit_cost'=>0],        'generate_links'      => ['engine'=>'seo',       'connector'=>null,       'action'=>'generate_links',      'approval_mode'=>'auto',      'credit_cost'=>3],
        // Wave 21 — credit-charged SEO actions.
        'list_keywords'       => ['engine'=>'seo',       'connector'=>null,       'action'=>'list_keywords',       'approval_mode'=>'auto',      'credit_cost'=>0],
        'keyword_research'    => ['engine'=>'seo',       'connector'=>null,       'action'=>'keyword_research',    'approval_mode'=>'auto',      'credit_cost'=>1],
        'keywords_suggest'    => ['engine'=>'seo',       'connector'=>null,       'action'=>'keywords_suggest',    'approval_mode'=>'auto',      'credit_cost'=>1],
        'keyword_check'       => ['engine'=>'seo',       'connector'=>null,       'action'=>'keyword_check',       'approval_mode'=>'auto',      'credit_cost'=>1],
        'competitor_serp'     => ['engine'=>'seo',       'connector'=>null,       'action'=>'competitor_serp',     'approval_mode'=>'auto',      'credit_cost'=>1],
        'competitor_gaps'     => ['engine'=>'seo',       'connector'=>null,       'action'=>'competitor_gaps',     'approval_mode'=>'auto',      'credit_cost'=>3],
        // Wave 22 — Chat metering + strategy meeting.
        // assistant_message + agent_message are batched 10:1 via CreditService::meterChat() — effective 0.1 cr/chat. credit_cost here is the threshold debit, not the per-call charge.
        'assistant_message'   => ['engine'=>'sarah',     'connector'=>null,       'action'=>'assistant_message',   'approval_mode'=>'auto',      'credit_cost'=>1],
        'agent_message'       => ['engine'=>'sarah',     'connector'=>null,       'action'=>'agent_message',       'approval_mode'=>'auto',      'credit_cost'=>1],
        'strategy_meeting'    => ['engine'=>'sarah',     'connector'=>null,       'action'=>'strategy_meeting',    'approval_mode'=>'auto',      'credit_cost'=>8],
        // Sarah cross-engine campaign drafting (Batch 4 — activates ContentPackService) /* b4-sarah-capmap */
        'sarah_draft_campaign' => ['engine'=>'sarah',     'connector'=>null,       'action'=>'draft_campaign',      'approval_mode'=>'auto',      'credit_cost'=>0],
        // Wave 23 — Canonical realignment additions.
        'generate_image_mini' => ['engine'=>'creative',  'connector'=>null,       'action'=>'generate_image_mini', 'approval_mode'=>'auto',      'credit_cost'=>1],
        'generate_image_high' => ['engine'=>'creative',  'connector'=>null,       'action'=>'generate_image_high', 'approval_mode'=>'auto',      'credit_cost'=>4],
        // [Phase G Fix 3 · 2026-07-25] Disabled dead capability: no engine implements the
        // upscale_image action (EngineExecutionService throws "Unknown Creative action: upscale_image").
        // Re-added by Wave 23 in error; see the Phase 2A removal note below. Reversible — uncomment to restore.
        // 'upscale_image'       => ['engine'=>'creative',  'connector'=>'creative', 'action'=>'upscale_image',       'approval_mode'=>'auto',      'credit_cost'=>1],
        'social_ai_post'      => ['engine'=>'social',    'connector'=>null,       'action'=>'social_ai_post',      'approval_mode'=>'review',    'credit_cost'=>1],
        'social_image'        => ['engine'=>'social',    'connector'=>'creative', 'action'=>'social_image',        'approval_mode'=>'auto',      'credit_cost'=>1],
        'hashtag_suggestions' => ['engine'=>'social',    'connector'=>null,       'action'=>'hashtag_suggestions', 'approval_mode'=>'auto',      'credit_cost'=>1],
        'ai_followup_draft'   => ['engine'=>'crm',       'connector'=>null,       'action'=>'ai_followup_draft',   'approval_mode'=>'review',    'credit_cost'=>1],
        // Sarah × CRM Phase 1 — generate_outreach is the lead-stage entry point (Elena drafts cold outreach) /* b5-crm-capmap */
        'generate_outreach'   => ['engine'=>'crm',       'connector'=>null,       'action'=>'generate_outreach',   'approval_mode'=>'review',    'credit_cost'=>1],
        'ai_reply_suggestion' => ['engine'=>'crm',       'connector'=>null,       'action'=>'ai_reply_suggestion', 'approval_mode'=>'auto',      'credit_cost'=>1],
        'ai_lead_scoring'     => ['engine'=>'crm',       'connector'=>null,       'action'=>'ai_lead_scoring',     'approval_mode'=>'auto',      'credit_cost'=>1],
        'ai_campaign_copy'    => ['engine'=>'marketing', 'connector'=>null,       'action'=>'ai_campaign_copy',    'approval_mode'=>'review',    'credit_cost'=>1],
        'full_site_generation'=> ['engine'=>'builder',   'connector'=>null,       'action'=>'full_site_generation','approval_mode'=>'review',    'credit_cost'=>10],
        'chatbot_ai_session'  => ['engine'=>'chatbot',   'connector'=>null,       'action'=>'chatbot_ai_session',  'approval_mode'=>'auto',      'credit_cost'=>1],
        // Sarah × Studio wiring 2026-06-03 — generate_design produces drafts; review before social publish.
        'studio_generate_design'   => ['engine'=>'studio',    'connector'=>null,       'action'=>'generate_design',     'approval_mode'=>'auto',      'credit_cost'=>5],
        'studio_generate_image'    => ['engine'=>'studio',    'connector'=>null,       'action'=>'generate_image',      'approval_mode'=>'auto',      'credit_cost'=>3],
        'studio_suggest_copy'      => ['engine'=>'studio',    'connector'=>null,       'action'=>'suggest_copy',        'approval_mode'=>'auto',      'credit_cost'=>1],
        // Sarah × Email Phase 1 wiring 2026-06-04 — all AI surfaces produce drafts; send is approval-gated separately.
        'marketing_email_ai_generate'      => ['engine'=>'marketing', 'connector'=>null,       'action'=>'email_ai_generate',      'approval_mode'=>'review',    'credit_cost'=>3],
        'marketing_email_block_rewrite'    => ['engine'=>'marketing', 'connector'=>null,       'action'=>'email_block_rewrite',    'approval_mode'=>'auto',      'credit_cost'=>1],
        'marketing_email_subject_suggest'  => ['engine'=>'marketing', 'connector'=>null,       'action'=>'email_subject_suggest',  'approval_mode'=>'auto',      'credit_cost'=>1],
        'marketing_email_spam_check'       => ['engine'=>'marketing', 'connector'=>null,       'action'=>'email_spam_check',       'approval_mode'=>'auto',      'credit_cost'=>0],
        'marketing_email_preview_template' => ['engine'=>'marketing', 'connector'=>null,       'action'=>'email_preview_template', 'approval_mode'=>'auto',      'credit_cost'=>0],
        'marketing_email_send_test'        => ['engine'=>'marketing', 'connector'=>null,       'action'=>'email_send_test',        'approval_mode'=>'review',    'credit_cost'=>0],
        'marketing_email_validate_campaign'=> ['engine'=>'marketing', 'connector'=>null,       'action'=>'email_validate_campaign','approval_mode'=>'auto',      'credit_cost'=>0],
        'marketing_email_use_template'     => ['engine'=>'marketing', 'connector'=>null,       'action'=>'email_use_template',     'approval_mode'=>'auto',      'credit_cost'=>0],
        'marketing_email_template_picker'  => ['engine'=>'marketing', 'connector'=>null,       'action'=>'email_template_picker',  'approval_mode'=>'auto',      'credit_cost'=>1],

        // ── Content Pack Engine (H1 — cross-engine campaign orchestrator) /* h1-batch3-capmap */
        'content_create_pack'  => ['engine'=>'content',  'connector'=>null,       'action'=>'create_pack',         'approval_mode'=>'auto',      'credit_cost'=>0],
        'content_add_asset'    => ['engine'=>'content',  'connector'=>null,       'action'=>'add_asset',           'approval_mode'=>'auto',      'credit_cost'=>0],
        'content_get_pack'     => ['engine'=>'content',  'connector'=>null,       'action'=>'get_pack',            'approval_mode'=>'auto',      'credit_cost'=>0],
        'content_list_packs'   => ['engine'=>'content',  'connector'=>null,       'action'=>'list_packs',          'approval_mode'=>'auto',      'credit_cost'=>0],
        'content_publish_pack' => ['engine'=>'content',  'connector'=>null,       'action'=>'publish_pack',        'approval_mode'=>'protected', 'credit_cost'=>0],

        // ── Write / Content Engine ───────────────────────────────
        // PATCH 2026-04-19: create_article was called from WriteController::createArticle
        // (manual "+ New Post" button in the blog engine) but missing from this map,
        // causing "Unknown action: write/create_article" on every new-post click.
        // Auto-approved, zero-credit — creating an empty draft shouldn't charge anyone.
        'create_article'      => ['engine'=>'write',     'connector'=>null,       'action'=>'create_article',      'approval_mode'=>'auto',      'credit_cost'=>0],
        'generate_outline'    => ['engine'=>'write',     'connector'=>null,       'action'=>'generate_outline',    'approval_mode'=>'auto',      'credit_cost'=>1],
        'generate_headlines'  => ['engine'=>'write',     'connector'=>null,       'action'=>'generate_headlines',  'approval_mode'=>'auto',      'credit_cost'=>1],
        'generate_meta'       => ['engine'=>'write',     'connector'=>null,       'action'=>'generate_meta',       'approval_mode'=>'auto',      'credit_cost'=>1],
        // Wave 45 — Answer Engine Optimization enrichment. 1cr standalone;
        // 0cr when bundled in a Sarah chain (Wave 42 chain-bundle logic).
        'aeo_enrich'          => ['engine'=>'write',     'connector'=>null,       'action'=>'aeo_enrich',          'approval_mode'=>'auto',      'credit_cost'=>1],

        // ── Creative Engine (native AI) ──────────────────────────
        'generate_image'      => ['engine'=>'creative',  'connector'=>'creative', 'action'=>'generate_image',      'approval_mode'=>'auto',      'credit_cost'=>2],
        'generate_video'      => ['engine'=>'creative',  'connector'=>'creative', 'action'=>'generate_video',      'approval_mode'=>'review',    'credit_cost'=>8],
        // STUDIO888 Phase O (2026-07-26): edit_image RE-ADDED with a real
        // implementation — CreativeService::editImage() performs masked
        // inpainting via OpenAI gpt-image-1 (/v1/images/edits) and creates a
        // non-destructive child version. Same cost as generation.
        'edit_image'          => ['engine'=>'creative',  'connector'=>'creative', 'action'=>'edit_image',          'approval_mode'=>'auto',      'credit_cost'=>2],
        // Phase 2A: removed 6 unimplemented aspirational creative actions that had
        // registered capabilities but no CreativeService implementation. Leaving them
        // registered caused Sarah's planner to include them in plans, then
        // EngineExecutionService threw "Unknown Creative action: upscale_image" at
        // execution time. These can be re-added when the implementations ship.
        //   REMOVED: upscale_image, remove_background, generate_variations,
        //            create_scene_plan, stitch_video

        // ── Builder Engine ───────────────────────────────────────
        'create_website'      => ['engine'=>'builder',   'connector'=>null,       'action'=>'create_website',      'approval_mode'=>'auto',      'credit_cost'=>0],
        'generate_page'       => ['engine'=>'builder',   'connector'=>null,       'action'=>'generate_page',       'approval_mode'=>'auto',    'credit_cost'=>5], // G15 2026-06-24: add-a-page = 5cr (Boss)
        // 2026-05-30 — escalated auto → protected. Publishing pushes content
        // live to customers; the re-publish path in the UI had ZERO confirm
        // before this change, and there was no agent-side gate either. Now
        // gated at backend + frontend + Sarah's destructive list.
        'publish_website'     => ['engine'=>'builder',   'connector'=>null,       'action'=>'publish_website',     'approval_mode'=>'protected', 'credit_cost'=>0],
        // PATCH v1.0.1: wizard_generate was missing — hit fallback (was zero-cost passthrough, now INVALID_ACTION)
        'wizard_generate'     => ['engine'=>'builder',   'connector'=>null,       'action'=>'wizard_generate',     'approval_mode'=>'auto',      'credit_cost'=>1],

        // -- INFRA888 (2026-07-18) ------------------------------------------
        // Infrastructure mutations are ALWAYS protected and NEVER cost AI
        // credits. Canonical metadata lives in InfrastructureCapabilityRegistry;
        // a drift test asserts these rows stay in sync with it.
        'provision_hosting'   => ['engine'=>'infrastructure', 'connector'=>null,     'action'=>'provision_hosting',   'approval_mode'=>'protected', 'credit_cost'=>0],
        // -- INFRA888 Phase 2A-2 catalog authoring ---------------------------
        // Commercial changes: what customers can buy, and what existing
        // subscribers are on. Draft editing is deliberately UNGOVERNED (a
        // draft is not sellable); publishing/retiring is protected.
        // Canonical metadata lives in InfrastructureCapabilityRegistry.
        'publish_product'     => ['engine'=>'infrastructure', 'connector'=>null,     'action'=>'publish_product',     'approval_mode'=>'protected', 'credit_cost'=>0],
        'deprecate_product'   => ['engine'=>'infrastructure', 'connector'=>null,     'action'=>'deprecate_product',   'approval_mode'=>'protected', 'credit_cost'=>0],
        'retire_product'      => ['engine'=>'infrastructure', 'connector'=>null,     'action'=>'retire_product',      'approval_mode'=>'protected', 'credit_cost'=>0],
        'publish_plan'        => ['engine'=>'infrastructure', 'connector'=>null,     'action'=>'publish_plan',        'approval_mode'=>'protected', 'credit_cost'=>0],
        'withdraw_plan'       => ['engine'=>'infrastructure', 'connector'=>null,     'action'=>'withdraw_plan',       'approval_mode'=>'protected', 'credit_cost'=>0],
        'plan_subscriber_migration' => ['engine'=>'infrastructure', 'connector'=>null, 'action'=>'plan_subscriber_migration', 'approval_mode'=>'protected', 'credit_cost'=>0],

        // ── Marketing Engine ─────────────────────────────────────
        'create_campaign'     => ['engine'=>'marketing', 'connector'=>null,       'action'=>'create_campaign',     'approval_mode'=>'review',    'credit_cost'=>1],
        'send_campaign'       => ['engine'=>'marketing', 'connector'=>'email',    'action'=>'send_campaign',       'approval_mode'=>'protected', 'credit_cost'=>0],
        // 2026-05-30 — pricing fix. Implementation is two DB columns
        // (status='scheduled', scheduled_at=?); zero AI / no email send /
        // no API hit. The previous 5 cr was leftover from a Wave-7
        // approval-mode fix. Scheduling is cheaper than sending (which is
        // 0 cr), so scheduling should not cost more.
        'schedule_campaign'   => ['engine'=>'marketing', 'connector'=>null,       'action'=>'schedule_campaign',   'approval_mode'=>'auto',      'credit_cost'=>0],
        // Phase 3 fix: removed 'send_email' (1cr) — no MarketingService::sendEmail() method exists.
        // Re-add when single-email sending is implemented (separate from campaign sends).
        'create_automation'   => ['engine'=>'marketing', 'connector'=>null,       'action'=>'create_automation',   'approval_mode'=>'review',    'credit_cost'=>5],

        // ── Social Engine ────────────────────────────────────────
        // v1.4.4 (2026-05-30) — repriced 3cr → 1cr to match write_article and
        // peer single-task AI ops. Social post generation is a single LLM call
        // producing one social post; 3× write_article (1cr) was unjustified.
        'social_create_post'  => ['engine'=>'social',    'connector'=>'social',   'action'=>'create_post',         'approval_mode'=>'review',    'credit_cost'=>1],
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
        // 2026-05-30 — escalated auto → protected. The page editor UI's
        // bldPublish already has a button-level luConfirm and the
        // frontend _PROTECTED set already includes this; this brings the
        // backend cap-map and Sarah's destructive list in line.
        'publish_builder_page'=> ['engine'=>'builder',   'connector'=>null,       'action'=>'publish_builder_page','approval_mode'=>'protected', 'credit_cost'=>0],
        'import_html_page'      => ['engine'=>'builder',   'connector'=>null,       'action'=>'import_html_page',      'approval_mode'=>'auto',      'credit_cost'=>0],
        // v1.4.4 (2026-05-30) — page-edit actions re-added now that the
        // service methods exist (BuilderService::updatePage,
        // ArthurEditService::editPage). Previously removed in Phase 3
        // because no implementations were wired.
        'update_page'           => ['engine'=>'builder',   'connector'=>null,       'action'=>'update_page',           'approval_mode'=>'review',    'credit_cost'=>0],
        // v1.4.4 (2026-05-30) — repriced 5cr → 1cr. The previous 5cr was
        // 5× builder_page_copy + builder_page_image (the conceptual peers),
        // 5× wizard_generate (which builds an ENTIRE website), and 5×
        // write_article (full 1000+ word draft). Sarah's recommended page-edit
        // path is to delegate to Arthur — keeping it at 5cr taxed that pattern
        // disproportionately. 1cr aligns with the system's per-AI-task baseline.
        'ai_builder_action'     => ['engine'=>'builder',   'connector'=>null,       'action'=>'ai_builder_action',     'approval_mode'=>'review',    'credit_cost'=>1],
        // v1.4.4 Phase D-1 (2026-05-30) — add a new page from a universal
        // template (about / services / pricing / contact / faq / legal / blog).
        // Industry-aware via workspace_memory. Cheap: just a structured DB insert.
        'add_page_from_template'=> ['engine'=>'builder',   'connector'=>null,       'action'=>'add_page_from_template','approval_mode'=>'review',    'credit_cost'=>5], // G15 2026-06-24: add-a-page = 5cr (Boss)

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
        // engine-prefixed-first — 2026-06-03 — prevent cross-engine name collisions
        // (e.g. Studio's generate_image vs Creative's generate_image).
        // Try engine-prefixed FIRST so each engine owns its action namespace cleanly.
        $prefixed = "{$engine}_{$action}";
        $cap = $this->resolve($prefixed);
        if ($cap) {
            return array_merge($cap, [
                'credit_cost'    => $this->getCreditCost($prefixed),
                'approval_level' => $this->getApprovalMode($prefixed),
            ]);
        }

        // Fall back to unprefixed (existing actions stored without engine prefix)
        $cap = $this->resolve($action);
        if ($cap) {
            return array_merge($cap, [
                'credit_cost'    => $this->getCreditCost($action),
                'approval_level' => $this->getApprovalMode($action),
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
