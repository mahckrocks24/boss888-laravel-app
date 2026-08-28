<?php

namespace App\Core\Orchestration;

use App\Core\Agent\AgentCapabilityService;
use App\Core\Billing\CreditService;
use App\Core\EngineKernel\EngineExecutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ToolSchemaService — closed function-calling schema for Sarah and agents.
 *
 * Eliminates LLM tool hallucination by advertising a finite, named list of
 * tools (with parameters and descriptions) in the system prompt. Each tool
 * ID maps to either:
 *   - a `platform.*` info tool — answered directly from the DB / services
 *     (no engine pipeline, no approval gate, no credit cost)
 *   - an engine action — routed through EngineExecutionService::execute()
 *
 * Action keys mirror CapabilityMapService keys exactly; an unknown tool ID
 * returns INVALID_TOOL rather than triggering an engine round-trip.
 *
 * Patched 2026-05-10 (Phase 2 — tool schema enforcement).
 */
class ToolSchemaService
{
    /**
     * Tool definitions. Engine actions reuse exact CapabilityMapService keys.
     * Platform info tools are handled directly in executeToolCall().
     */
    private const TOOL_DEFINITIONS = [
        // ─── PLATFORM INFO (direct, no engine pipeline) ──────────────
        'platform.get_website_count' => [
            'description' => 'Return the total number of websites in the workspace.',
            'parameters'  => [],
            'engine'      => 'platform',
            'action'      => 'get_website_count',
            'approval'    => 'auto',
        ],
        'platform.get_published_websites' => [
            'description' => 'Return the list of published websites with names + subdomains.',
            'parameters'  => [],
            'engine'      => 'platform',
            'action'      => 'get_published_websites',
            'approval'    => 'auto',
        ],
        'platform.get_credit_balance' => [
            'description' => 'Return the current credit balance for the workspace.',
            'parameters'  => [],
            'engine'      => 'platform',
            'action'      => 'get_credit_balance',
            'approval'    => 'auto',
        ],
        'platform.get_task_status' => [
            'description' => 'Return a task-activity report. FINISHED work (completed/failed/cancelled) is counted over a rolling window that DEFAULTS to the last 7 days — report it that way ("28 completed in the last 7 days"), NOT as an all-time total. OPEN work (pending/queued/running) is always the live current count. Only pass a wider window when the user EXPLICITLY asks for it: "how many this month" → window="30d"; "how many since the beginning / all time / total ever" → window="all". Use this for high-level counts ONLY. If the user asks "WHAT are those N tasks" or "WHY did they fail", call platform.list_tasks next to enumerate them.',
            'parameters'  => ['window' => 'string? (7d | 30d | all — default 7d. Use 30d ONLY if the user says "this month"; use "all" ONLY if the user explicitly asks for the all-time / since-the-beginning total)'],
            'engine'      => 'platform',
            'action'      => 'get_task_status',
            'approval'    => 'auto',
        ],
        // v1.4.4 (2026-05-30) — Sarah was returning "There are 4 pending
        // tasks. They are tasks that are waiting to be processed." because
        // platform.get_task_status only gives counts. These two surface
        // the individual rows so she can actually answer specifics.
        'platform.list_tasks' => [
            'description' => 'List individual task rows in the workspace with their engine/action/status/age/error. Use this WHENEVER the user asks "what are those tasks", "which jobs are pending", "why did X fail", "show me the failed work", or any question that needs to name SPECIFIC tasks. The histogram tool (platform.get_task_status) is for counts only; this one tells you what each row actually is. Returns id, engine, action, status, created_at, updated_at, age_minutes, payload_summary (first 160 chars), error_text (first 240 chars, only for failed). Filter by status to focus the query — e.g. status="failed" when investigating a regression.',
            'parameters'  => ['status' => 'string? (pending|queued|running|completed|failed|all, default all)', 'limit' => 'int? (default 10, max 30)'],
            'engine'      => 'platform',
            'action'      => 'list_tasks',
            'approval'    => 'auto',
        ],
        'platform.read_task' => [
            'description' => 'Read the FULL detail of ONE task by id: complete payload_json, complete error_text + stacktrace, all timing fields, retry_count, agent assignment, parent_task_id. Use this AFTER platform.list_tasks when the user wants the full picture of a specific failure or pending job.',
            'parameters'  => ['task_id' => 'int (required, get from platform.list_tasks)'],
            'engine'      => 'platform',
            'action'      => 'read_task',
            'approval'    => 'auto',
        ],
        // 2026-06-10 — Sarah was blind to SEO health: she had no tool for orphan
        // pages / internal-link health / the SEO Overview report, so she never
        // surfaced or explained a high orphan count. This read-only tool exposes
        // the same live snapshot the Overview dashboard shows (SeoService::getKnowledge).
        'platform.seo_health' => [
            'description' => 'Live SEO health snapshot for this workspace: pages indexed, average page score, internal-link health including how many ORPHAN pages have no internal links pointing to them, missing meta descriptions, low-scoring pages, and top tracked-keyword positions. Use this WHENEVER the user asks about SEO health, orphan pages, internal linking, "why is my SEO score low", the audit/overview summary, or what to improve. Returns live counts (not a stale audit). To FIX orphan pages, delegate a fix_orphans task to James (it inserts internal links to them, ~2 credits per link).',
            'parameters'  => [],
            'engine'      => 'platform',
            'action'      => 'seo_health',
            'approval'    => 'auto',
        ],
        // 2026-06-12 — closes the GSC/GA alignment gap: agents can now READ
        // real Search Console rankings + Analytics traffic for strategy.
        'platform.search_performance' => [
            'description' => 'Live Google Search Console + Google Analytics for this workspace. Returns Search Console (clicks, impressions, CTR, average position, top queries with their position, position distribution) and Analytics traffic (visitors, sessions, pageviews, engagement). Call this WHENEVER the user asks about rankings, search performance, organic traffic, clicks, impressions, "what do we rank for", CTR, or visitor numbers — answer with the real numbers, do not guess. Reports if a source is not connected. Drive strategy from it: striking-distance queries (position 5-15) to push onto page one, and high-impression low-CTR queries to improve titles/meta.',
            'parameters'  => [],
            'engine'      => 'platform',
            'action'      => 'search_performance',
            'approval'    => 'auto',
        ],
        // 2026-05-27 — Internal content read tools. Closes the gap where
        // Sarah was telling users "I can't read draft blog posts" despite
        // the DB having full article content. Read-only, auto-approve.
        'platform.list_articles' => [
            'description' => 'List articles (blog posts, marketing content) in the workspace. Filter by status. Use this BEFORE answering questions about content, drafts, or "what blog posts do we have". Returns id, title, status, type, focus_keyword, excerpt, featured_image_url (NULL = missing), featured_image_alt, word_count, updated_at. Response also includes summary: {total, with_featured_image, missing_featured_image} — read it before saying "no images". When the user asks about featured images, use featured_image_url as ground truth.',
            'parameters'  => ['status' => 'string? (draft|published|all, default all)', 'limit' => 'int? (default 50)'],
            'engine'      => 'platform',
            'action'      => 'list_articles',
            'approval'    => 'auto',
        ],
        'platform.read_article' => [
            'description' => 'Read FULL content of one article by id. Use this when the user asks "what does article X say" / "summarize the draft" / "read the blog post". Returns title, status, content (HTML), excerpt, focus_keyword, meta_title, meta_description, featured_image_url (NULL = no AI image yet), featured_image_alt, word_count.',
            'parameters'  => ['article_id' => 'int (required, get from platform.list_articles)'],
            'engine'      => 'platform',
            'action'      => 'read_article',
            'approval'    => 'auto',
        ],
        'platform.list_pages' => [
            'description' => 'List website pages (Home, About, services, etc.). Use this BEFORE answering "what pages does the site have". Note: blog posts are NOT pages — use platform.list_articles for those. Returns id, website_id, title, slug, type, status, is_homepage, updated_at.',
            'parameters'  => ['website_id' => 'int?', 'status' => 'string? (draft|published|all, default all)', 'limit' => 'int? (default 50)'],
            'engine'      => 'platform',
            'action'      => 'list_pages',
            'approval'    => 'auto',
        ],
        'platform.read_page' => [
            'description' => 'Read FULL content of one website page by id (Home, About, etc.). Returns sections_json (the page body — may be empty `[]` for new sites that have not been populated yet — in that case, tell the user the page has no content stored). Also returns meta_title, meta_description.',
            'parameters'  => ['page_id' => 'int (required, get from platform.list_pages)'],
            'engine'      => 'platform',
            'action'      => 'read_page',
            'approval'    => 'auto',
        ],
        // 2026-05-27 — AgentBrowser tools. Already wired at HTTP + runtime
        // layers; this exposes them to Sarah's prompt schema so she stops
        // saying "I can't read the web." Tied to Research category in Phase 3.
        'web.fetch' => [
            'description' => 'Fetch the structured content (title, meta, headings, body, links) of any public URL via the AgentBrowser. Cost: 1 credit. Audit-logged to agent_web_activity.',
            'parameters'  => ['url' => 'string (required, https://… absolute URL)'],
            'engine'      => 'platform',
            'action'      => 'web_fetch',
            'approval'    => 'auto',
        ],
        'web.search' => [
            'description' => 'Run a web search and return organic results (title, snippet, url). Cost: 2 credits. Audit-logged.',
            'parameters'  => ['query' => 'string (required)'],
            'engine'      => 'platform',
            'action'      => 'web_search',
            'approval'    => 'auto',
        ],

        // ─── SEO ─────────────────────────────────────────────────────
        'seo.serp_analysis' => [
            'description' => 'Run SERP analysis for a keyword (rankings, competition, volume).',
            'parameters'  => ['keyword' => 'string', 'location' => 'string?'],
            'engine'      => 'seo', 'action' => 'serp_analysis', 'approval' => 'auto',
        ],
        'seo.deep_audit' => [
            'description' => 'Run a full technical SEO audit on a workspace URL.',
            'parameters'  => ['url' => 'string'],
            'engine'      => 'seo', 'action' => 'deep_audit', 'approval' => 'auto',
        ],
        'seo.ai_report' => [
            'description' => 'Generate an AI-written SEO report for the workspace.',
            'parameters'  => ['url' => 'string?'],
            'engine'      => 'seo', 'action' => 'ai_report', 'approval' => 'auto',
        ],
        'seo.link_suggestions' => [
            'description' => 'Suggest internal links for an article or page.',
            'parameters'  => ['article_id' => 'int?'],
            'engine'      => 'seo', 'action' => 'link_suggestions', 'approval' => 'auto',
        ],
        // 2026-05-22 FIX — keyword + link tools were missing from the catalog.
        // Sarah needs these to plan keyword-driven article generation.
        // REGRESSION GUARD: run `php artisan tools:audit` to verify ToolSchema
        // stays in sync with CapabilityMapService (Wave 80 will replace this
        // static map with dynamic discovery — until then, keep them aligned).
        'seo.add_keyword' => [
            'description' => 'Track a keyword for SEO monitoring on the workspace.',
            'parameters'  => ['keyword' => 'string'],
            'engine'      => 'seo', 'action' => 'add_keyword', 'approval' => 'auto',
        ],
        'seo.list_keywords' => [
            'description' => 'List all keywords tracked for the workspace, with current rank, volume, difficulty, target URL. Use this to ANSWER list/show/which-keywords questions.',
            'parameters'  => ['status' => 'string?', 'search' => 'string?'],
            'engine'      => 'seo', 'action' => 'list_keywords', 'approval' => 'auto',
        ],
        // 2026-07-07 — REMOVED from the catalog: seo.keyword_research,
        // keywords_suggest, keyword_check, generate_links. Their Orchestrator
        // handlers return not_implemented (deliberate stubs), so advertising them
        // meant Sarah proposed/called work that was guaranteed to fail. For
        // keyword needs she still has list_keywords + add_keyword + serp_analysis
        // (all wired and working). Re-add these only when a real handler exists.

        // ─── WRITE / CONTENT ─────────────────────────────────────────
        'write.write_article' => [
            'description' => 'Write a full blog article on a topic.',
            'parameters'  => ['topic' => 'string', 'keywords' => 'array?', 'word_count' => 'int?'],
            'engine'      => 'write', 'action' => 'write_article', 'approval' => 'review',
        ],
        'write.fill_missing_images' => [
            'description' => 'Generate featured images for ALL articles that are missing one. The backend finds the articles itself — do NOT pass or invent article ids. Use this whenever the user says "add / fix / generate the missing featured images" across the site. Optional `limit` (default 10) caps how many per run.',
            'parameters'  => ['limit' => 'int?'],
            'engine'      => 'write', 'action' => 'fill_missing_images', 'approval' => 'auto',
        ],
        'write.improve_draft' => [
            'description' => 'Improve an existing article draft.',
            'parameters'  => ['article_id' => 'int', 'instructions' => 'string?'],
            'engine'      => 'write', 'action' => 'improve_draft', 'approval' => 'review',
        ],
        'write.generate_headlines' => [
            'description' => 'Generate headline options for a topic.',
            'parameters'  => ['topic' => 'string', 'count' => 'int?'],
            'engine'      => 'write', 'action' => 'generate_headlines', 'approval' => 'auto',
        ],
        'write.generate_outline' => [
            'description' => 'Generate an article outline for a topic.',
            'parameters'  => ['topic' => 'string'],
            'engine'      => 'write', 'action' => 'generate_outline', 'approval' => 'auto',
        ],

        // ─── SOCIAL ──────────────────────────────────────────────────
        'social.create_post' => [
            'description' => 'Create a social media post draft.',
            'parameters'  => ['platform' => 'string (linkedin|instagram|tiktok|x)', 'content' => 'string'],
            'engine'      => 'social', 'action' => 'social_create_post', 'approval' => 'review',
        ],
        'social.list_posts' => [
            'description' => 'List recent social posts.',
            'parameters'  => ['limit' => 'int?', 'platform' => 'string?'],
            'engine'      => 'social', 'action' => 'list_posts', 'approval' => 'auto',
        ],

        // ─── CRM ─────────────────────────────────────────────────────
        'crm.create_lead' => [
            'description' => 'Create a new lead in the CRM.',
            'parameters'  => ['first_name' => 'string', 'last_name' => 'string?', 'email' => 'string?', 'source' => 'string?'],
            'engine'      => 'crm', 'action' => 'create_lead', 'approval' => 'review',
        ],
        'crm.list_leads' => [
            'description' => 'List leads from the CRM.',
            'parameters'  => ['limit' => 'int?', 'status' => 'string?'],
            'engine'      => 'crm', 'action' => 'list_leads', 'approval' => 'auto',
        ],
        'crm.update_lead' => [
            'description' => 'Update a lead.',
            'parameters'  => ['lead_id' => 'int', 'status' => 'string?', 'notes' => 'string?'],
            'engine'      => 'crm', 'action' => 'update_lead', 'approval' => 'auto',
        ],

        // ─── MARKETING ───────────────────────────────────────────────
        'marketing.create_campaign' => [
            'description' => 'Create an email marketing campaign.',
            'parameters'  => ['name' => 'string', 'subject' => 'string', 'audience' => 'string?'],
            'engine'      => 'marketing', 'action' => 'create_campaign', 'approval' => 'review',
        ],
        'marketing.list_campaigns' => [
            'description' => 'List existing email campaigns.',
            'parameters'  => ['limit' => 'int?'],
            'engine'      => 'marketing', 'action' => 'list_campaigns', 'approval' => 'auto',
        ],

        // ─── CREATIVE ────────────────────────────────────────────────
        'creative.generate_image' => [
            'description' => 'Generate an image with the creative engine.',
            'parameters'  => ['prompt' => 'string', 'style' => 'string?'],
            'engine'      => 'creative', 'action' => 'generate_image', 'approval' => 'auto',
        ],

        // ═══════════════════════════════════════════════════════════════
        // v1.4.4 (2026-05-30) — DMM intelligence expansion
        // Only tools with REAL backend implementations are exposed here.
        // Probed each candidate against the engine dispatcher; aspirational
        // entries (without handlers) were withheld to prevent Sarah from
        // proposing tool calls that error.
        //
        // Phase B candidates (need backend implementation before exposing):
        //   crm.move_lead, crm.list_sequences, crm.enroll_sequence,
        //   crm.log_activity, crm.add_note,
        //   marketing.list_templates, marketing.create_template, marketing.record_metric,
        //   social.get_queue, social.record_social_analytics,
        //   calendar.list_events, calendar.check_availability,
        //   builder.list_builder_pages, builder.get_builder_page,
        //   seo.competitor_serp, seo.competitor_gaps
        // (These actions exist in CapabilityMapService + AgentCapabilityService
        //  but no engine handler responds. Adding handlers = Phase B work.)
        // ═══════════════════════════════════════════════════════════════

        // ─── MARKETING ops (verified working backend) ────────────────
        'marketing.schedule_campaign' => [
            'description' => 'Schedule a campaign for a specific send time. Use when seasonal cadence or audience timezone matters.',
            'parameters'  => ['campaign_id' => 'int (required)', 'send_at' => 'string (required, ISO 8601)'],
            'engine'      => 'marketing', 'action' => 'schedule_campaign', 'approval' => 'review',
        ],
        'marketing.send_campaign' => [
            'description' => 'Send a campaign immediately. Reserve for time-critical sends — usually prefer schedule_campaign.',
            'parameters'  => ['campaign_id' => 'int (required)'],
            'engine'      => 'marketing', 'action' => 'send_campaign', 'approval' => 'review',
        ],
        'marketing.create_automation' => [
            'description' => 'Create a marketing automation (trigger → conditions → actions). Use for lead nurturing, abandonment recovery, win-back flows.',
            'parameters'  => ['name' => 'string (required)', 'trigger' => 'string (required)', 'steps_json' => 'string (required, JSON array of steps)'],
            'engine'      => 'marketing', 'action' => 'create_automation', 'approval' => 'review',
        ],

        // ─── SOCIAL ops (verified working backend) ────────────────────
        'social.schedule_post' => [
            'description' => 'Schedule a social post for a later time. Prefer over create_post + send-now for campaigns with timing strategy.',
            'parameters'  => ['platform' => 'string (linkedin|instagram|tiktok|x)', 'content' => 'string', 'scheduled_at' => 'string (ISO 8601)'],
            'engine'      => 'social', 'action' => 'schedule_post', 'approval' => 'review',
        ],

        // ─── CALENDAR (verified working backend) ─────────────────────
        'calendar.create_event' => [
            'description' => 'Create a calendar event (campaign launch, content drop, webinar). Anchors the team to a date.',
            'parameters'  => ['title' => 'string (required)', 'start_at' => 'string (required, ISO 8601)', 'notes' => 'string?'],
            'engine'      => 'calendar', 'action' => 'create_event', 'approval' => 'review',
        ],

        // ─── CRM pipeline / lifecycle (Phase B wired 2026-05-30) ─────
        'crm.move_lead' => [
            'description' => 'Move a lead to a different pipeline stage. Use to reorganise the funnel or progress a qualified lead.',
            'parameters'  => ['lead_id' => 'int (required)', 'stage' => 'string (required, e.g. new|contacted|qualified|won|lost)'],
            'engine'      => 'crm', 'action' => 'move_lead', 'approval' => 'auto',
        ],
        'crm.list_sequences' => [
            'description' => 'List nurture / drip sequences configured in CRM. Use BEFORE proposing new nurture flows so you reuse existing ones.',
            'parameters'  => [],
            'engine'      => 'crm', 'action' => 'list_sequences', 'approval' => 'auto',
        ],

        // ─── MARKETING ops (Phase B wired 2026-05-30) ────────────────
        'marketing.list_templates' => [
            'description' => 'List email templates. Use BEFORE creating a new template — reuse existing ones when brand voice is established.',
            'parameters'  => [],
            'engine'      => 'marketing', 'action' => 'list_templates', 'approval' => 'auto',
        ],
        'marketing.create_template' => [
            'description' => 'Create a reusable email template. Use when none of the existing templates match the campaign brief.',
            'parameters'  => ['name' => 'string (required)', 'subject' => 'string (required)', 'body_html' => 'string (required)'],
            'engine'      => 'marketing', 'action' => 'create_template', 'approval' => 'review',
        ],

        // ─── SOCIAL ops (Phase B wired 2026-05-30) ───────────────────
        'social.get_queue' => [
            'description' => 'View the upcoming social post queue (scheduled + recently published). Use BEFORE scheduling new posts so you do not stack the same platform / same hour.',
            'parameters'  => ['from' => 'string? (ISO date)', 'to' => 'string? (ISO date)'],
            'engine'      => 'social', 'action' => 'get_queue', 'approval' => 'auto',
        ],

        // ─── CALENDAR (Phase B wired 2026-05-30) ─────────────────────
        'calendar.list_events' => [
            'description' => 'List events in a date range. Use to align campaign timing with promo periods, launches, holidays.',
            'parameters'  => ['from' => 'string? (ISO date)', 'to' => 'string? (ISO date)', 'category' => 'string?'],
            'engine'      => 'calendar', 'action' => 'list_events', 'approval' => 'auto',
        ],

        // ─── BUILDER / landing pages (Phase B wired 2026-05-30) ──────
        'builder.list_builder_pages' => [
            'description' => 'List landing pages across all websites in the workspace. Use when planning a campaign that needs a destination URL.',
            'parameters'  => ['status' => 'string? (draft|published|all)', 'limit' => 'int? (default 50)'],
            'engine'      => 'builder', 'action' => 'list_builder_pages', 'approval' => 'auto',
        ],
        'builder.get_builder_page' => [
            'description' => 'Read structure + content of a landing page. Use to verify CTAs / messaging match the campaign you are planning.',
            'parameters'  => ['page_id' => 'int (required, get from builder.list_builder_pages)'],
            'engine'      => 'builder', 'action' => 'get_builder_page', 'approval' => 'auto',
        ],
        // RISK-0088 (2026-08-25) — Sarah can initiate a brand-new full website build.
        'builder.full_site_generation' => [
            'description' => 'Build a brand-new multi-page website from scratch via Arthur (real LLM copy + generated images, pages persisted). Use when the customer wants a NEW website built. Gather the business details first. Review-gated: the customer approves before the build runs.',
            'parameters'  => ['build_data' => 'object (required; must include business_name; recommended: industry, description, target_audience, services)', 'colors' => 'object? ({primary,secondary,accent} hex)'],
            'engine'      => 'builder', 'action' => 'full_site_generation', 'approval' => 'review',
        ],
        // v1.4.4 (2026-05-30) — page editing
        'builder.edit_page_with_arthur' => [
            'description' => 'Hand off a page edit to Arthur, the AI website-builder assistant. Arthur loads the page\'s sections_json, proposes structured actions (max 5 per response), validates against SectionSchema, and applies atomically with auto-snapshot for undo. Use this for ALL substantive page changes — copy rewrites, CTA edits, new section additions — rather than crafting raw sections_json yourself.',
            'parameters'  => ['page_id' => 'int (required, get from builder.list_builder_pages)', 'command' => 'string (required, plain-English brief for Arthur, e.g. "Update the hero headline to focus on sustainable seafood")', 'section_index' => 'int? (optional — focus Arthur on a specific section)'],
            'engine'      => 'builder', 'action' => 'ai_builder_action', 'approval' => 'review',
        ],
        'builder.update_page' => [
            'description' => 'Directly update page fields (title, slug, status, sections_json, seo). Use ONLY for surgical metadata edits where no AI reasoning is needed — for content edits always prefer builder.edit_page_with_arthur so Arthur snapshots and validates. Auto-snapshots before+after every mutation.',
            'parameters'  => ['page_id' => 'int (required)', 'title' => 'string?', 'slug' => 'string?', 'status' => 'string? (draft|published)', 'sections' => 'array? (full sections array)', 'seo' => 'array? ({meta_title, meta_description})'],
            'engine'      => 'builder', 'action' => 'update_page', 'approval' => 'review',
        ],
        // v1.4.4 Phase D-1 → D-5 (2026-05-30) — adding new pages
        'builder.add_page_from_template' => [
            'description' => "Add a new page to an existing website using one of the industry-aware page templates. Pulls business_name/industry/services/location from workspace_memory and emits a full section stack. After creation, refine copy via builder.edit_page_with_arthur.\n\nAVAILABLE TEMPLATES (17 + home/blog):\n- Universal: about, services, pricing, contact, faq, legal (privacy/terms)\n- Bookings + events: booking, events — for clinics, salons, restaurants (reservations), event_venue, training_center, gym, hotel/resort\n- Listings: listing_browser, listing_detail, locations — for real_estate_agency, short_term_rental, ecommerce, hotel, automotive, online_courses\n- Visual portfolios: before_after, menu, portfolio — for aesthetic_clinic/beauty_salon/dental (before_after), restaurant/cafe/catering (menu), architecture/interior_design/marketing_agency (portfolio)\n- Commerce + account: cart, checkout, account — for ecommerce, retail_shop, online_courses, short_term_rental\n\nALIASES (use full name in calls, listed here for matching user intent): booking={book,book_now,appointments,reservations}; events={event,classes,schedule,whats_on}; listing_browser={listings,properties,rooms,products,shop,catalogue,fleet,courses}; listing_detail={property,product,room,course}; locations={location,branches,find_us,store_finder,stores}; before_after={transformations,results,case_studies}; menu={food_menu,dishes,drinks,wine_list}; portfolio={work,projects,gallery_page,showcase}; cart={basket,shopping_cart}; checkout={checkout_page}; account={my_account,dashboard,profile_page}.",
            'parameters'  => ['website_id' => 'int (required)', 'page_template' => 'string (required) — one of: about, services, pricing, contact, faq, legal, booking, events, listing_browser, listing_detail, locations, before_after, menu, portfolio, cart, checkout, account, blog, home', 'title' => 'string? (default derived from template)', 'slug' => 'string? (default derived from template)'],
            'engine'      => 'builder', 'action' => 'add_page_from_template', 'approval' => 'review',
        ],
        'builder.create_page' => [
            'description' => 'Add a new page to an existing website with raw user-provided content. Use only when none of the universal templates fit (prefer builder.add_page_from_template when possible). The new page lands as a draft.',
            'parameters'  => ['website_id' => 'int (required)', 'title' => 'string (required)', 'slug' => 'string?', 'sections' => 'array? (full sections array)', 'is_homepage' => 'bool?'],
            'engine'      => 'builder', 'action' => 'generate_page', 'approval' => 'review',
        ],

        // ─── COMPETITIVE intelligence (Phase B wired 2026-05-30) ─────
        'seo.competitor_serp' => [
            'description' => 'Pull cached competitor SERP positions for a tracked keyword. Use to answer "what are competitors doing for keyword X" with real data.',
            'parameters'  => ['keyword' => 'string (required)', 'location' => 'string? (default workspace location)'],
            'engine'      => 'seo', 'action' => 'competitor_serp', 'approval' => 'auto',
        ],
        'seo.competitor_gaps' => [
            'description' => 'Find keywords where competitors rank in the top 10 but the workspace does not. Use to discover content roadmap items with highest leverage.',
            'parameters'  => ['competitor_domain' => 'string?', 'limit' => 'int? (default 25)'],
            'engine'      => 'seo', 'action' => 'competitor_gaps', 'approval' => 'auto',
        ],

        // ─── FUNNEL intelligence (custom Laravel impl below) ─────────
        'platform.generate_funnel_blueprint' => [
            'description' => 'Generate a funnel blueprint for the workspace (TOFU → MOFU → BOFU stages, channels, content types, KPIs). Use BEFORE proposing big multi-channel pushes so the plan respects the funnel.',
            'parameters'  => ['goal' => 'string? (e.g. "increase qualified leads in NJ", default = active workspace goal)'],
            'engine'      => 'platform', 'action' => 'generate_funnel_blueprint', 'approval' => 'auto',
        ],
        'platform.analyze_funnel_structure' => [
            'description' => 'Analyse the workspace current funnel: which articles target TOFU vs MOFU vs BOFU, gaps per stage. Use BEFORE recommending new content so the suggestion fixes a real funnel gap.',
            'parameters'  => [],
            'engine'      => 'platform', 'action' => 'analyze_funnel_structure', 'approval' => 'auto',
        ],
    ];

    /**
     * Tools an agent is allowed to invoke. Platform info tools are always
     * allowed; engine tools are gated by AgentCapabilityService.
     */
    public function getAgentTools(string $agentSlug): array
    {
        $cap = app(AgentCapabilityService::class);
        $out = [];
        foreach (self::TOOL_DEFINITIONS as $toolId => $def) {
            if ($def['engine'] === 'platform' || $cap->canUse($agentSlug, $def['action'])) {
                $out[$toolId] = $def;
            }
        }
        return $out;
    }

    /**
     * Render the schema as a system-prompt block. Sarah / agents see this
     * INSTEAD of the loose prose roster they had before.
     */
    public function getToolSchemaPrompt(string $agentSlug): string
    {
        $tools = $this->getAgentTools($agentSlug);
        $lines = ['AVAILABLE TOOLS — you may ONLY call these exact tool IDs:'];
        foreach ($tools as $toolId => $def) {
            $params = empty($def['parameters'])
                ? '(no parameters)'
                : implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($def['parameters']), $def['parameters']));
            $lines[] = "- {$toolId}: {$def['description']} | params: {$params} | approval: {$def['approval']}";
        }
        $lines[] = '';
        $lines[] = 'To CALL a tool, include this in your JSON output:';
        $lines[] = '"tool_calls": [{"tool": "<exact tool id from list above>", "params": {...}, "reason": "why"}]';
        $lines[] = 'Rules: NEVER invent tool names. ONLY use IDs from the list above. If no tool fits, leave tool_calls as [] and answer in prose.';
        // v1.4.4 (2026-05-30) — Anti-hallucination examples. Sarah was emitting
        // tool calls like crm.get_status, crm.find_agent, builder.delegate_task
        // — none of which exist. The dispatcher would 3x-retry then fail. Be
        // explicit about which IDs are real and what to do instead.
        $lines[] = 'Tools that DO NOT EXIST (do NOT call these, they are hallucinations from previous sessions): crm.get_status, crm.find_agent, builder.delegate_task, crm.delegate_task, platform.find_agent, platform.list_agents, write.delegate.';
        $lines[] = '  • To check what other agents are doing: there is NO tool for this yet — answer in prose ("James is on the SEO side, let me check what he\'s working on") rather than invent one.';
        $lines[] = '  • To delegate work: you do NOT need a tool. Sarah orchestrates by recommending specialist actions; the user or the orchestrator routes the actual task. NEVER emit a synthetic "delegate" tool call.';
        $lines[] = '  • To get workspace status: call platform.get_task_status for task counts, or crm.list_leads / seo.list_keywords / social.get_queue for engine-specific state. There is no single "get_status" tool.';
        // 2026-05-27 — proactive-read nudges. Sarah was telling users "I can\'t
        // read drafts/pages" when she had read tools available; the LLM was
        // too conservative without explicit triggers.
        $lines[] = '';
        $lines[] = 'CRITICAL — proactive reading rules:';
        $lines[] = '- When the user asks "can you read X" / "what does X say" / "show me X" / "summarize X" / "review the draft" / "look at the content" — DO NOT answer "I can\'t". CALL platform.list_articles or platform.list_pages first, then platform.read_article or platform.read_page on a specific id.';
        $lines[] = '- When the user asks about "the website content" or "blog posts" — list_articles is for blog posts; list_pages is for website pages (Home/About/etc). Blog posts are stored as ARTICLES, not pages.';
        $lines[] = '- When user asks about external info / competitors / industry news / "what does this page say" with a URL — call web.fetch on the URL or web.search for queries.';
        $lines[] = '- If a tool returns empty content (e.g. sections_json=[]), say so explicitly — do not generalise to "I can\'t read content".';
        $lines[] = '- FEATURED IMAGES: when the user asks which articles have/lack featured images, READ the featured_image_url field on each row (NULL or empty = no image). Use the response summary {with_featured_image, missing_featured_image} as the authoritative count. NEVER say "all articles lack images" without checking the field — past failure mode (2026-05-30) where Sarah reported "all 30 drafts lack featured image" when 7 actually had AI-generated images.';
        $lines[] = '- When drafts are missing featured images and the user wants to fix that, you HAVE creative.generate_image available — propose it with a prompt derived from the article title + focus_keyword. Do NOT tell the user "you would need a tool" — you ARE the tool.';
        $lines[] = '- SEO HEALTH / ORPHAN PAGES: when the user asks about SEO health, orphan pages, internal linking, the audit/overview summary, why the SEO score is low, or what to improve — CALL platform.seo_health and answer with the live numbers (orphan pages, internal-link health, missing meta, average score). Do NOT guess or say you cannot see it. If there are orphan pages and the user asks you to fix them (incl. "can you fix them?", "fix the orphans", "fix it"), DO IT THIS TURN — emit a create_tasks entry with engine="seo", action="fix_orphans", agent="james". Do NOT merely say "James can fix it" — actually emit the task. It inserts internal links to the orphan pages and charges ~2 credits per link.';
        $lines[] = '- SEARCH PERFORMANCE / RANKINGS / TRAFFIC: when the user asks about rankings, search performance, organic traffic, clicks, impressions, CTR, "what do we rank for", or visitor numbers — CALL platform.search_performance and answer with the real Google Search Console + Analytics numbers. Do NOT guess or say you cannot see it. Use the numbers for strategy: striking-distance queries (position 5-15) to push onto page one, and high-impression low-CTR pages to retitle. Only say a source is not connected if the tool says so.';
        // v1.4.4 (2026-05-30) — Re-query on specifics. Past failure: Sarah
        // reported "4 pending tasks" from an earlier histogram call, then
        // when the user asked "what are those 4?" she just repeated the
        // count instead of re-querying. By the time the user asked, all 4
        // had already failed. The histogram was stale.
        $lines[] = '- TASK COUNTS — ALWAYS USE platform.get_task_status, NEVER COUNT list_tasks ROWS. For ANY "how many tasks / how many completed / how many failed" question, you MUST call platform.get_task_status — that is the ONLY correct source for totals. NEVER answer a count by calling platform.list_tasks and counting the rows it returns: list_tasks returns at most a 30-row SAMPLE and will undercount badly (e.g. "20 tasks, 18 completed" when the real 7-day number is far higher). list_tasks is ONLY for naming specific rows / explaining individual failures, never for totals.';
        $lines[] = '- TASK COUNTS — DEFAULT TO THE LAST 7 DAYS. platform.get_task_status defaults to the last 7 days for finished work and gives the live count for currently-open work. Report it that way and SAY THE WINDOW: "28 tasks completed in the last 7 days (2 failed)". Do NOT quote an ever-growing all-time total — it is meaningless to the user. Only report a longer span when the user explicitly asks: "how many this month" → call get_task_status with window="30d"; "how many since the beginning / in total / all time / ever" → window="all". Never volunteer the all-time number unprompted, and never silently mix windows.';
        $lines[] = '- TASK QUESTIONS — RE-QUERY ON SPECIFICS. When you reported a count earlier in the conversation (e.g. "4 pending tasks") and the user then asks WHICH ones / WHAT they are / WHY they are slow / WHY they failed → ALWAYS call platform.list_tasks NOW. Do NOT recite the older count. State can change between turns; a task can go pending → failed in seconds. If you said "4 pending" 3 minutes ago, those 4 may have all crashed by the time the user follows up. Verify before you answer.';
        $lines[] = '- TASK QUESTIONS — DRILL IN ON FAILURES. If platform.list_tasks shows failed rows, READ the error_text on each and tell the user what failed and why — do NOT just say "some failed" or "they are still being processed". Give the actual reason, but ALWAYS in plain English. NEVER show the raw action slug or status enum in your reply (no write_article, generate_image_mini, competitor_gaps, aeo_enrich, awaiting_approval, etc., and no backtick code spans). Translate every internal name to natural language: write_article → "writing the article", generate_image / generate_image_mini → "the featured image", competitor_gaps → "the competitor gap analysis", aeo_enrich → "the AI-search optimisation", link_suggestions / insert_link → "internal links". So say "The featured image for the Essex County article failed — it was missing a prompt" — NOT "generate_image for article 176 was missing its prompt parameter". Use platform.read_task on any failure that needs the full detail.';
        $lines[] = '- TASK QUESTIONS — NEVER SAY "they are waiting to be processed" AS YOUR FINAL ANSWER when the user is asking for specifics. That is a non-answer. List each one in plain English — what it is plus its plain-English status (e.g. "the Catering article — still writing", "the Essex County featured image — done", "the competitor gap analysis — failed, not yet supported") — NEVER as raw id+slug+enum. Or admit you cannot find them after calling list_tasks.';
        // 2026-06-09 — no-schema-leakage reinforcement (fixes Sarah surfacing
        // raw action slugs like "competitor_gaps" in chat). Applies to ALL replies.
        $lines[] = '- NEVER LEAK INTERNAL NAMES. In anything you say to the user, never expose raw action slugs, engine names, status enums, or schema/field names (e.g. write_article, generate_image_mini, competitor_gaps, aeo_enrich, awaiting_approval, payload_json). Always use plain, natural English. These names are internal only — they must never appear in your reply, not even in quotes or backticks.';

        // v1.4.4 (2026-05-30) — DMM thinking rules. Transfers digital-marketing-manager
        // reasoning into Sarah\'s decision-making so she stops behaving like a
        // task router and starts behaving like a Director of Marketing.
        $lines[] = '';
        // W6 (2026-07-22) — REFUSAL LANGUAGE.
        // The launch-scope rule below already said "never propose social/email".
        // It did not say how to DECLINE when a user asks directly, so the model
        // filled the gap with plausible-sounding framing: "social media isn't
        // connected here — Marcus can't publish there." That is two untruths:
        // it describes a REMOVED product as a DISCONNECTED integration, and it
        // presents a removed specialist as merely unavailable. These lines make
        // the refusal wording explicit rather than leaving it to inference.
        $lines[] = 'HOW TO DECLINE REMOVED CAPABILITIES (say it exactly like this):';
        $lines[] = '- Social media posting/management/analytics, comment replies, engagement, inbox, listening, competitor monitoring, email marketing, campaigns, newsletters and sequences are NOT part of the LevelUp Growth product. They are not missing, not disconnected, not unconfigured, and not gated behind a plan.';
        $lines[] = '- Use this wording: "This capability is not part of the current LevelUp Growth product." Then offer ONE retained alternative.';
        $lines[] = '- NEVER say: "not connected", "no accounts are linked", "not configured", "no email service", "connect an account first", "upgrade to unlock", "not on your plan", "coming soon", or "not yet available". Those all imply the feature exists and could be switched on. It cannot.';
        $lines[] = '- NEVER name a removed specialist (Marcus, Jordan, Tyler, Zara, Zoe, Maya, Vera, Kai, Chris, Leo) — not to assign work, not to explain why work cannot happen, not as someone who "would" do it. They are not part of the product.';
        $lines[] = '- NEVER promise or hint at future availability of social publishing or email marketing.';
        $lines[] = '- Retained alternatives you MAY offer: write or improve a blog article; create an image or video in AI Studio; upload and organise media; add a CRM lead or follow-up task; improve website copy or a landing page; work on SEO (keywords, audit, internal links); or prepare copy the user can post manually themselves.';
        $lines[] = 'CRITICAL — DMM thinking discipline (apply every time you recommend ANY action):';
        $lines[] = '- PERSONA FIRST. Before suggesting content, campaigns, or copy, anchor to the workspace target_audience (workspace_memory). State the persona explicitly when proposing: "for {persona} who is {context}". If target_audience is missing, ASK the user to clarify ONCE — then proceed.';
        $lines[] = '- FUNNEL STAGE AWARENESS. Every content/campaign idea must name a funnel stage: TOFU (awareness — broad informational), MOFU (consideration — comparison, case studies, calculators), BOFU (decision — pricing, trust signals, demos). Don\'t propose another TOFU article if the workspace already has 20 TOFU and 0 BOFU.';
        $lines[] = '- COMPETITIVE LENS. Before recommending an SEO push or a campaign theme, call seo.competitor_serp or seo.competitor_gaps for the seed keyword. Frame the recommendation as "competitors X/Y are ranking for {keyword} with {angle} — our gap is {gap}".';
        $lines[] = '- BRAND VOICE CONSISTENCY. Read workspace_memory.tone before drafting any user-facing copy or campaign brief. Mirror the tone in your proposed copy. If tone is missing, ASK.';
        $lines[] = '- ATTRIBUTION THINKING. For every content/SEO/CRM proposal name (a) the KPI it moves (organic traffic, MQLs, demo bookings, revenue), (b) which CRM stage or SEO metric you\'ll track afterward. If you can\'t name the KPI, you don\'t have a proposal — you have a wish.';
        $lines[] = '- DELEGATION DISCIPLINE. You ORCHESTRATE. Execution actions are assigned to specialists: write_article/improve_draft/generate_meta → Priya (or Nora). deep_audit/insert_link/link_suggestions → James; technical SEO → Alex. CRM leads/follow-ups → Elena. NEVER set yourself as the agent on an execution action. LAUNCH SCOPE: social-media posting/management and email marketing are NOT part of this product — never propose them, never assign them to anyone, and never name a social/email specialist; the system will refuse them.';
        $lines[] = '- BUDGET AWARENESS. Quote credit cost and impact when proposing. If today\'s burn is over 90% of pro-rata, propose only the highest-leverage action.';
        $lines[] = '- LIFECYCLE / CRM. When the user asks "what about our leads", call crm.list_leads + crm.list_sequences FIRST to see real pipeline state before suggesting nurturing flows. Don\'t hallucinate sequences that don\'t exist.';
        $lines[] = '- READ STATE BEFORE PROPOSING. For any "what should we do about X" question, list/read the relevant resource FIRST (articles, pages, keywords, leads, campaigns, builder pages, calendar events). A proposal grounded in real state beats a generic checklist every time.';
        $lines[] = '- PAGE EDITS via ARTHUR. For ANY edit to a static page (homepage, About, Services, landing page, etc.) — copy rewrites, CTA tweaks, new sections, hero updates — call builder.edit_page_with_arthur with a plain-English brief. Arthur is the LLM specialist who actually mutates sections_json; he validates against SectionSchema and snapshots before/after for undo. ONLY use builder.update_page for surgical metadata edits (title/slug/status) where no AI reasoning is needed. NEVER craft raw sections_json yourself — that bypasses Arthur\'s validation and breaks undo history.';
        $lines[] = '- ADDING NEW PAGES. When the user asks to add a page, call builder.add_page_from_template with the matching page_template. 17 templates cover most asks: about, services, pricing, contact, faq, legal (universal); booking, events (clinics/salons/restaurants/venues); listing_browser, listing_detail, locations (real_estate/hotel/ecommerce/automotive); before_after, menu, portfolio (visual portfolios); cart, checkout, account (ecommerce). Match the user\'s phrasing to the closest template — e.g. "book a table" → booking, "our menu" → menu, "property listings" → listing_browser, "transformations" → before_after. After it lands, refine the copy via builder.edit_page_with_arthur. Use builder.create_page ONLY when the page is fully custom (no template fits).';
        $lines[] = '- INDUSTRY DEFAULT PAGES (when scaffolding a new site). If a tenant has just registered and the only page is home, proactively suggest the right 3-5 default pages for their industry — don\'t just wait to be asked. Defaults by industry: real_estate_agency/short_term_rental → home, listing_browser, listing_detail, locations, contact; hotel/resort → home, listing_browser (rooms), booking, locations, contact; restaurant/cafe → home, menu, booking, locations, contact; aesthetic_clinic/dental/beauty_salon/medical_clinic/barbershop → home, services, before_after, booking, contact; ecommerce/retail_shop → home, listing_browser (products), cart, checkout, account, contact; event_venue/training_center/online_courses/tutoring → home, events, listing_browser (courses), booking, contact; architecture/interior_design/marketing_agency/photography → home, services, portfolio, about, contact; consulting/it_services/home_services/automotive (services) → home, services, about, contact, faq; gym → home, services, booking, events (classes), contact. Always propose, never silently create.';
        return implode("\n", $lines);
    }

    /**
     * Execute a single tool call. Platform info → direct DB read.
     * Engine tools → EngineExecutionService::execute() with correct argument order.
     */
    /** RISK-0105 S3 — site-specific tools that MUST resolve an unambiguous website target. */
    private const SITE_SCOPED_TOOLS = [
        'builder.edit_page_with_arthur',
        'builder.update_page',
        'builder.add_page_from_template',
        'builder.create_page',
        'publish_website',
    ];

    /** Per-workspace opt-in (workspaces.settings_json.sarah_target_resolution === true). Default OFF. */
    private function targetResolutionEnabled(int $wsId): bool
    {
        if ($wsId <= 0) return false;
        try {
            $raw = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->value('settings_json');
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_string($raw) || $raw === '') return false;
        $decoded = json_decode($raw, true);
        return is_array($decoded) && (($decoded['sarah_target_resolution'] ?? false) === true);
    }

    /**
     * For a site-specific tool: resolve a unique website_id (pinned into $params) or return a
     * CLARIFY result that short-circuits execution. Never guesses. RISK-0105.
     * @return array<string,mixed>|null null => proceed; array => clarify (do not execute).
     */
    private function enforceTarget(string $toolId, array &$params, int $wsId, string $agentSlug, array $context): ?array
    {
        // RISK-0105 S4b — per-conversation active target (cache; TTL = staleness window).
        $convKey = 'sarah:tgt:ws' . $wsId . ':' . $agentSlug;
        $websites = \Illuminate\Support\Facades\DB::table('websites')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'subdomain', 'custom_domain'])
            ->map(fn ($w) => (array) $w)->all();

        // Explicit id from params, or derived from an explicit page_id (validated to this workspace).
        $explicitId = (int) ($params['website_id'] ?? 0);
        if ($explicitId <= 0 && !empty($params['page_id'])) {
            $wid = (int) \Illuminate\Support\Facades\DB::table('pages')
                ->join('websites as w', 'w.id', '=', 'pages.website_id')
                ->where('pages.id', (int) $params['page_id'])
                ->where('w.workspace_id', $wsId)
                ->value('pages.website_id');
            if ($wid > 0) { $explicitId = $wid; }
        }

        $signals = [
            'explicit_id'       => $explicitId ?: null,
            'explicit_name'     => $context['explicit_name'] ?? null,
            'active_website_id' => isset($context['active_website_id']) ? (int) $context['active_website_id'] : $this->cachedActiveTarget($convKey),
            'ui_site_url'       => $context['ui_site_url'] ?? $this->requestSiteUrl(),
        ];

        $res = app(\App\Core\Sarah888\WebsiteTargetResolver::class)->resolve($websites, $signals);

        if (($res['status'] ?? '') === \App\Core\Sarah888\WebsiteTargetResolver::CLARIFY) {
            $names = array_map(static fn ($c) => (string) ($c['name'] ?? ''), $res['candidates'] ?? []);
            $names = array_values(array_filter($names));
            $ask = empty($names)
                ? 'Which website would you like me to work on? I could not find an eligible website.'
                : ('Which website would you like me to update — ' . implode(', ', $names) . '?');
            return [
                'success'    => false,
                'code'       => 'CLARIFY_TARGET',
                'error'      => $ask,
                'candidates' => $res['candidates'] ?? [],
                'reason'     => $res['reason'] ?? '',
            ];
        }

        // Resolved: pin the website_id as a hard scope for the tool, and remember it as this
        // conversation's active target for implicit continuation next turn. RISK-0105 S4b.
        $params['website_id'] = (int) $res['website_id'];
        try {
            \Illuminate\Support\Facades\Cache::put($convKey, (int) $res['website_id'], now()->addMinutes(30));
        } catch (\Throwable $e) {
        }
        return null;
    }

    /** Most-recent resolved target for this conversation (cache; null if none/expired). RISK-0105 S4b. */
    private function cachedActiveTarget(string $convKey): ?int
    {
        try {
            $v = \Illuminate\Support\Facades\Cache::get($convKey);
            return (is_numeric($v) && (int) $v > 0) ? (int) $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * UI/site context from the current HTTP request — the site the customer is working on
     * (site_url input or X-Lgse-Active-Site header). Null when absent (CLI/jobs/no value).
     * RISK-0105 S4a. Read defensively so a missing request never throws.
     */
    private function requestSiteUrl(): ?string
    {
        try {
            $req = app('request');
            if (!$req) { return null; }
            $v = $req->input('site_url');
            if (!is_string($v) || $v === '') { $v = $req->header('X-Lgse-Active-Site'); }
            return (is_string($v) && $v !== '') ? $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function executeToolCall(string $toolId, array $params, int $wsId, string $agentSlug, array $context = []): array
    {
        if (!isset(self::TOOL_DEFINITIONS[$toolId])) {
            return [
                'success' => false,
                'error'   => "Unknown tool id: {$toolId}",
                'code'    => 'INVALID_TOOL',
            ];
        }

        $def = self::TOOL_DEFINITIONS[$toolId];

        // RISK-0105 S3 — deterministic execution-target resolution for site-specific tools.
        // Flag-gated per workspace (default OFF => unchanged behaviour). When ON, a site-specific
        // tool with an ambiguous target returns CLARIFY instead of executing on a guessed site.
        if ($this->targetResolutionEnabled($wsId) && in_array($toolId, self::SITE_SCOPED_TOOLS, true)) {
            $clarify = $this->enforceTarget($toolId, $params, $wsId, $agentSlug, $context);
            if ($clarify !== null) {
                return $clarify;
            }
        }

        if ($def['engine'] === 'platform') {
            return $this->executePlatformTool($toolId, $params, $wsId);
        }

        // Authorization check before routing to engine.
        if (!app(AgentCapabilityService::class)->canUse($agentSlug, $def['action'])) {
            return [
                'success' => false,
                'error'   => "{$agentSlug} is not authorised to call {$toolId}",
                'code'    => 'AGENT_NOT_AUTHORIZED',
            ];
        }

        $exec = app(EngineExecutionService::class);
        return $exec->execute($wsId, $def['engine'], $def['action'], $params, [
            'agent_id' => $agentSlug,
            'source'   => 'agent',
        ]);
    }

    // 2026-06-09 — no-schema-leakage at the source. platform.list_tasks /
    // read_task used to hand the agent raw action slugs ('competitor_gaps') in
    // the `action` field and inside error_text ("Step 1 (competitor_gaps)
    // failed: ..."), and the agent then echoed them to the user. A prompt rule
    // alone wasn't enough — the LLM repeated whatever the tool returned. So we
    // humanise the slugs in the tool OUTPUT itself: the agent literally never
    // sees the raw name, so it cannot leak it.
    private const ACTION_LABELS = [
        'write_article'        => 'writing the article',
        'generate_meta'        => 'the SEO meta',
        'aeo_enrich'           => 'the AI-search optimisation',
        'generate_image'       => 'the featured image',
        'generate_image_mini'  => 'the featured image',
        'generate_image_high'  => 'the featured image',
        'link_suggestions'     => 'finding internal links',
        'insert_link'          => 'inserting internal links',
        'competitor_gaps'      => 'the competitor gap analysis',
        'deep_audit'           => 'the SEO audit',
        'serp_analysis'        => 'the search-ranking analysis',
        'keyword_research'     => 'keyword research',
        'add_keyword'          => 'adding a keyword',
        'create_lead'          => 'creating a lead',
        'social_create_post'   => 'a social post',
        'create_campaign'      => 'a campaign',
        'schedule_campaign'    => 'scheduling a campaign',
        'publish_article'      => 'publishing the article',
        'publish_website'      => 'publishing the site',
    ];

    private static function humanizeAction(?string $slug): string
    {
        $slug = trim((string) $slug);
        if ($slug === '') return 'a task';
        return self::ACTION_LABELS[$slug] ?? str_replace('_', ' ', $slug);
    }

    /** Replace any internal slug (action names + bare snake_case tokens) in free text with plain English. */
    private static function scrubInternalNames(?string $text): ?string
    {
        if ($text === null || $text === '') return $text;
        // Drop the technical "Step N (slug) failed:" prefix entirely.
        $text = preg_replace('/\bStep\s+\d+\s*\([a-z0-9_]+\)\s*failed:\s*/i', '', $text);
        // Map known action slugs (in or out of parens) to friendly labels.
        foreach (self::ACTION_LABELS as $slug => $label) {
            $text = preg_replace('/\(?\b' . preg_quote($slug, '/') . '\b\)?/', $label, $text);
        }
        // Any remaining snake_case token → spaced words (e.g. payload_json → "payload json").
        $text = preg_replace_callback('/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b/', fn ($m) => str_replace('_', ' ', $m[0]), $text);
        return trim($text);
    }

    private function executePlatformTool(string $toolId, array $params, int $wsId): array
    {
        try {
            switch ($toolId) {
                case 'platform.get_website_count':
                    // Wave 54 — filter soft-deleted rows so the count reflects
                    // the user's view (deletes from the UI only set deleted_at).
                    $count = DB::table('websites')
                        ->where('workspace_id', $wsId)
                        ->whereNull('deleted_at')
                        ->count();
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => "{$count} websites total in this workspace.",
                        'data'    => ['count' => $count],
                    ];

                case 'platform.get_published_websites':
                    $rows = DB::table('websites')
                        ->where('workspace_id', $wsId)
                        ->where('status', 'published')
                        ->whereNull('deleted_at')
                        ->select('name', 'subdomain', 'custom_domain', 'published_at')
                        ->orderByDesc('published_at')
                        ->limit(50)
                        ->get();
                    if ($rows->isEmpty()) {
                        return ['success' => true, 'tool' => $toolId, 'result' => 'No websites are currently published.', 'data' => ['count' => 0]];
                    }
                    $list = $rows->map(fn($r) => trim($r->name ?? 'untitled') . ($r->subdomain ? " ({$r->subdomain})" : ''))->implode(', ');
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => "{$rows->count()} published: {$list}.",
                        'data'    => ['count' => $rows->count(), 'items' => $rows],
                    ];

                case 'platform.get_credit_balance':
                    $balance = app(CreditService::class)->getBalance($wsId);
                    $value = is_array($balance)
                        ? ($balance['balance'] ?? $balance['available'] ?? $balance['total'] ?? 0)
                        : $balance;
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => "Credit balance: {$value}.",
                        'data'    => is_array($balance) ? $balance : ['balance' => $value],
                    ];

                case 'platform.get_task_status': {
                    // 2026-06-10 — rolling-window report. An ever-growing all-time
                    // total ("630 completed") is noise — the user wants recent
                    // activity. Finished work (completed/failed/cancelled) DEFAULTS
                    // to the last 7 days; open work (pending/queued/running) is
                    // always live (age is irrelevant to "what's still going").
                    // Widen ONLY when the user explicitly asks: "this month" → 30d,
                    // "since the beginning"/"all time"/"total ever" → all.
                    $window = strtolower(trim((string) ($params['window'] ?? $params['range'] ?? '7d')));
                    $days = 7; $windowLabel = 'in the last 7 days';
                    if (in_array($window, ['all', 'alltime', 'all_time', 'total', 'beginning', 'since_beginning', 'ever'], true)) {
                        $days = null; $windowLabel = 'all time';
                    } elseif (in_array($window, ['30d', '30', 'month', 'this_month', 'monthly'], true)) {
                        $days = 30; $windowLabel = 'in the last 30 days';
                    }

                    // Open work — live, unwindowed.
                    $live = DB::table('tasks')
                        ->where('workspace_id', $wsId)
                        ->whereIn('status', ['pending', 'queued', 'running'])
                        ->selectRaw('status, COUNT(*) as n')
                        ->groupBy('status')->pluck('n', 'status')->all();

                    // Finished work — windowed by created_at.
                    $doneQ = DB::table('tasks')
                        ->where('workspace_id', $wsId)
                        ->whereIn('status', ['completed', 'failed', 'cancelled']);
                    if ($days !== null) $doneQ->where('created_at', '>=', now()->subDays($days));
                    $done = $doneQ->selectRaw('status, COUNT(*) as n')
                        ->groupBy('status')->pluck('n', 'status')->all();

                    $rows = array_merge((array) $live, (array) $done);

                    $doneParts = [];
                    foreach (['completed', 'failed', 'cancelled'] as $s) {
                        if (($rows[$s] ?? 0) > 0) $doneParts[] = ($rows[$s]) . ' ' . $s;
                    }
                    $openParts = [];
                    foreach (['pending', 'queued', 'running'] as $s) {
                        if (($rows[$s] ?? 0) > 0) $openParts[] = ($rows[$s]) . ' ' . $s;
                    }
                    $result = 'Tasks ' . $windowLabel . ': '
                        . (count($doneParts) ? implode(', ', $doneParts) : 'no finished tasks') . '.'
                        . (count($openParts) ? ' Currently open: ' . implode(', ', $openParts) . '.' : ' No open tasks right now.');

                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => $result,
                        'data'    => array_merge($rows, ['window' => $windowLabel, 'window_days' => $days]),
                    ];
                }

                // 2026-06-10 — live SEO health snapshot (orphans / link health /
                // meta / scores). Same data the Overview dashboard shows. Output
                // is conversational — no raw column/enum names (no-schema-leakage).
                case 'platform.seo_health': {
                    $k = app(\App\Engines\SEO\Services\SeoService::class)->getKnowledge($wsId);
                    $lh   = $k['link_health']    ?? [];
                    $ch   = $k['content_health'] ?? [];
                    $pages    = (int) ($ch['total_pages']        ?? 0);
                    $orphans  = (int) ($lh['orphan_count']       ?? 0);
                    $linkScr  = $lh['score']                     ?? null;
                    $health   = $k['health_score']               ?? null;
                    $missMeta = (int) ($ch['missing_meta_count'] ?? 0);
                    $below50  = (int) ($ch['below_50_count']     ?? 0);

                    if ($pages === 0) {
                        $msg = 'No pages are indexed yet, so there is no SEO health data — run a scan first.';
                    } else {
                        $parts = ["{$pages} pages indexed"];
                        if ($health !== null)  $parts[] = "average page score {$health}/100";
                        $parts[] = $orphans > 0
                            ? "{$orphans} orphan pages (no internal links pointing to them)"
                            : "no orphan pages";
                        if ($linkScr !== null) $parts[] = "internal-link health {$linkScr}/100";
                        if ($missMeta > 0)     $parts[] = "{$missMeta} pages missing a meta description";
                        if ($below50 > 0)      $parts[] = "{$below50} pages scoring below 50";
                        $msg = ucfirst(implode(', ', $parts)) . '.';
                        if ($orphans > 0) {
                            $msg .= ' Orphan pages do not build ranking authority — James can fix them by inserting internal links from related content.';
                        }
                    }

                    // Fold in real Google Search Console performance so the SEO
                    // snapshot reflects actual Google rankings, not just on-site health.
                    $gscT = app(\App\Engines\SEO\Services\GscClient::class)->quickTotals($wsId, 28);
                    if ($gscT && ($gscT['impressions'] > 0 || $gscT['clicks'] > 0)) {
                        $msg .= " In Google Search (last 28 days): {$gscT['clicks']} clicks, {$gscT['impressions']} impressions, average position {$gscT['position']} — call platform.search_performance for the queries and ranking opportunities.";
                    }

                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => $msg,
                        'data'    => [
                            'pages_indexed'         => $pages,
                            'avg_page_score'        => $health,
                            'orphan_pages'          => $orphans,
                            'internal_link_score'   => $linkScr,
                            'pages_missing_meta'    => $missMeta,
                            'pages_below_50'        => $below50,
                            'search_console'        => $gscT,
                            'summary'               => $k['summary'] ?? null,
                        ],
                    ];
                }

                // 2026-06-12 — real Google Search Console + Analytics for agents.
                case 'platform.search_performance': {
                    $gsc = app(\App\Engines\SEO\Services\GscClient::class);
                    $ga  = app(\App\Engines\SEO\Services\GaClient::class);
                    $parts = [];
                    $data = ['search_console' => null, 'analytics' => null];

                    if ($gsc->isConnected($wsId)) {
                        try {
                            $rep = $gsc->report($wsId, 28);
                            $t = $rep['totals'];
                            $striking = 0;
                            foreach ($rep['top_queries'] as $q) {
                                if ($q['position'] >= 5 && $q['position'] <= 15) { $striking++; }
                            }
                            $topQ = array_slice(array_map(
                                fn ($q) => $q['label'] . " (pos {$q['position']}, {$q['clicks']} clicks)",
                                $rep['top_queries']
                            ), 0, 5);
                            $parts[] = "Search Console (last 28 days): {$t['clicks']} clicks, {$t['impressions']} impressions, {$t['ctr']}% CTR, average position {$t['position']}."
                                . (! empty($topQ) ? ' Top queries: ' . implode('; ', $topQ) . '.' : '')
                                . ($striking > 0 ? " {$striking} of those sit at striking distance (position 5-15) — strong candidates to push onto page one." : '');
                            $data['search_console'] = [
                                'totals' => $t,
                                'top_queries' => $rep['top_queries'],
                                'position_buckets' => $rep['position_buckets'],
                            ];
                        } catch (\Throwable $e) {
                            $parts[] = 'Search Console is connected but its data could not be loaded right now.';
                        }
                    } else {
                        $parts[] = 'Search Console is not connected — connect it in Insights → Search Console.';
                    }

                    $gat = $ga->quickTotals($wsId, 28);
                    if ($gat) {
                        $parts[] = "Google Analytics (last 28 days): {$gat['users']} visitors, {$gat['sessions']} sessions, {$gat['pageviews']} pageviews, {$gat['engagement']}% engagement.";
                        $data['analytics'] = $gat;
                    } else {
                        $parts[] = 'Google Analytics is not connected — connect it in Insights → Google Analytics.';
                    }

                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => implode(' ', $parts),
                        'data'    => $data,
                    ];
                }

                // v1.4.4 (2026-05-30) — Task introspection. Closes the gap where
                // Sarah could only report counts. Now she can name specific rows
                // and surface error_text for failed ones.
                case 'platform.list_tasks': {
                    $statusF = strtolower((string) ($params['status'] ?? 'all'));
                    $limit   = max(1, min((int) ($params['limit'] ?? 10), 30));
                    $q = DB::table('tasks')->where('workspace_id', $wsId);
                    if ($statusF !== 'all') $q->where('status', $statusF);
                    $rows = $q->orderByDesc('updated_at')
                        ->limit($limit)
                        ->get(['id', 'engine', 'action', 'status', 'created_at', 'updated_at',
                               'payload_json', 'error_text']);
                    $now = now();
                    $list = $rows->map(function ($r) use ($now) {
                        $ageMin = (int) \Carbon\Carbon::parse($r->updated_at ?? $r->created_at)->diffInMinutes($now);
                        $payloadSummary = $r->payload_json
                            ? substr(preg_replace('/\s+/', ' ', $r->payload_json), 0, 160)
                            : null;
                        $errorSummary = ($r->status === 'failed' && !empty($r->error_text))
                            ? substr(preg_replace('/\s+/', ' ', $r->error_text), 0, 240)
                            : null;
                        return [
                            'id'              => $r->id,
                            'engine'          => $r->engine,
                            'action'          => self::humanizeAction($r->action),
                            'status'          => $r->status,
                            'created_at'      => $r->created_at,
                            'updated_at'      => $r->updated_at,
                            'age_minutes'     => $ageMin,
                            'payload_summary' => self::scrubInternalNames($payloadSummary),
                            'error_text'      => self::scrubInternalNames($errorSummary),
                        ];
                    })->values()->all();
                    $byStatus = [];
                    foreach ($rows as $r) $byStatus[$r->status] = ($byStatus[$r->status] ?? 0) + 1;
                    $resultLine = 'Listed ' . count($list) . ' task' . (count($list) === 1 ? '' : 's')
                        . ($statusF !== 'all' ? " with status={$statusF}" : '')
                        . '. By status: ' . json_encode($byStatus) . '.';
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => $resultLine,
                        'data'    => $list,
                        'summary' => ['total' => count($list), 'by_status' => $byStatus, 'filter' => $statusF],
                    ];
                }

                case 'platform.read_task': {
                    $taskId = (int) ($params['task_id'] ?? 0);
                    if ($taskId <= 0) {
                        return ['success' => false, 'tool' => $toolId, 'error' => 'task_id required (positive int)'];
                    }
                    $t = DB::table('tasks')
                        ->where('workspace_id', $wsId)
                        ->where('id', $taskId)
                        ->first();
                    if (!$t) {
                        return ['success' => false, 'tool' => $toolId, 'error' => "Task {$taskId} not found in this workspace"];
                    }
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => "Task {$taskId}: " . self::humanizeAction($t->action) . " — status {$t->status}"
                                     . (!empty($t->error_text) ? ' (failed)' : ''),
                        'data'    => [
                            'id'                  => $t->id,
                            'engine'              => $t->engine,
                            'action'              => self::humanizeAction($t->action),
                            'status'              => $t->status,
                            'approval_status'     => $t->approval_status ?? null,
                            'source'              => $t->source ?? null,
                            'category'            => $t->category ?? null,
                            'priority'            => $t->priority ?? null,
                            'retry_count'         => $t->retry_count ?? null,
                            'max_attempts'        => $t->max_attempts ?? null,
                            'parent_task_id'      => $t->parent_task_id ?? null,
                            'credit_cost'         => $t->credit_cost ?? null,
                            'payload_json'        => $t->payload_json,
                            'result_json'         => $t->result_json,
                            'error_text'          => self::scrubInternalNames($t->error_text),
                            'error_trace'         => $t->error_trace,
                            'created_at'          => $t->created_at,
                            'started_at'          => $t->started_at,
                            'completed_at'        => $t->completed_at,
                            'failed_at'           => $t->failed_at,
                            'duration_ms'         => $t->duration_ms ?? null,
                            'progress_message'    => $t->progress_message ?? null,
                            'current_step'        => $t->current_step ?? null,
                            'total_steps'         => $t->total_steps ?? null,
                        ],
                    ];
                }

                // 2026-05-27 — internal content read tools
                // v1.4.4 (2026-05-30) — surface featured_image_url + word_count
                // so Sarah can actually answer "which drafts are missing
                // featured images" instead of reporting blanks for everything.
                // Previous SELECT omitted these → she was structurally blind.
                case 'platform.list_articles': {
                    $status = strtolower((string)($params['status'] ?? 'all'));
                    $limit  = (int)($params['limit'] ?? 50);

                    // 2026-07-07 — REAL totals across the WHOLE workspace, not the
                    // returned sample. The old code reported $rows->count() (capped
                    // at 50) as the total, so Sarah quoted "50 articles / 23 missing"
                    // for a 154-article workspace. Counts are now authoritative;
                    // the sample rows (real ids) are still returned in `data`.
                    $countBase = DB::table('articles')->where('workspace_id', $wsId);
                    if ($status !== 'all') $countBase->where('status', $status);
                    $totalCount   = (clone $countBase)->count();
                    $missingCount = (clone $countBase)->where(function ($w) {
                        $w->whereNull('featured_image_url')->orWhere('featured_image_url', '');
                    })->count();
                    $withCount = $totalCount - $missingCount;

                    // 2026-07-23 — when unfiltered, break the missing count down by
                    // status so Sarah can answer "how many DRAFTS are missing an image"
                    // (subset) vs "how many total" without flip-flopping between them.
                    $missBreak = ''; $mDraft = null; $mPub = null;
                    if ($status === 'all' && $missingCount > 0) {
                        $mDraft = (int) DB::table('articles')->where('workspace_id', $wsId)
                            ->where('status', 'draft')->where(function ($w) {
                                $w->whereNull('featured_image_url')->orWhere('featured_image_url', '');
                            })->count();
                        $mPub = $missingCount - $mDraft;
                        $missBreak = " ({$mDraft} of them drafts, {$mPub} published)";
                    }

                    $q = DB::table('articles')->where('workspace_id', $wsId);
                    if ($status !== 'all') $q->where('status', $status);
                    $rows = $q->orderByDesc('updated_at')
                        ->limit(min(max($limit, 1), 100))
                        ->get([
                            'id', 'title', 'status', 'type', 'focus_keyword', 'excerpt',
                            'featured_image_url', 'featured_image_alt', 'featured_image_error',
                            'word_count', 'updated_at',
                        ]);
                    $sampleNote = $rows->count() < $totalCount
                        ? " (showing the {$rows->count()} most recently updated of {$totalCount}; ask me to include more or focus on a specific status if you need others)"
                        : '';
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => $totalCount . ' article' . ($totalCount === 1 ? '' : 's')
                                     . ($status !== 'all' ? " in status '{$status}'" : '')
                                     . " — {$withCount} with a featured image, {$missingCount} without{$missBreak}.{$sampleNote}",
                        'data'    => $rows->toArray(),
                        'summary' => [
                            'total'                  => $totalCount,
                            'with_featured_image'    => $withCount,
                            'missing_featured_image' => $missingCount,
                            'missing_drafts'         => $mDraft,
                            'missing_published'      => $mPub,
                            'sample_returned'        => $rows->count(),
                        ],
                    ];
                }

                case 'platform.read_article': {
                    $id = (int)($params['article_id'] ?? 0);
                    if ($id <= 0) return ['success' => false, 'error' => 'article_id is required', 'code' => 'BAD_PARAMS'];
                    $row = DB::table('articles')
                        ->where('id', $id)
                        ->where('workspace_id', $wsId)
                        ->first([
                            'id', 'title', 'status', 'type', 'content', 'excerpt',
                            'focus_keyword', 'meta_title', 'meta_description',
                            'featured_image_url', 'featured_image_alt', 'featured_image_error',
                            'word_count', 'created_at', 'updated_at',
                        ]);
                    if (!$row) return ['success' => false, 'error' => "Article #{$id} not found in this workspace", 'code' => 'NOT_FOUND'];
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => "Article #{$row->id} '{$row->title}' (status: {$row->status}"
                                     . (!empty($row->featured_image_url) ? ', has featured image' : ', no featured image')
                                     . ').',
                        'data'    => (array) $row,
                    ];
                }

                case 'platform.list_pages': {
                    $status = strtolower((string)($params['status'] ?? 'all'));
                    $limit  = (int)($params['limit'] ?? 50);
                    $q = DB::table('pages as p')
                        ->join('websites as w', 'w.id', '=', 'p.website_id')
                        ->where('w.workspace_id', $wsId);
                    if (!empty($params['website_id'])) $q->where('p.website_id', (int)$params['website_id']);
                    if ($status !== 'all') $q->where('p.status', $status);
                    $rows = $q->orderByDesc('p.updated_at')
                        ->limit(min(max($limit, 1), 100))
                        ->get(['p.id', 'p.website_id', 'p.title', 'p.slug', 'p.type', 'p.status', 'p.is_homepage', 'p.updated_at']);
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => $rows->count() . ' page' . ($rows->count() === 1 ? '' : 's')
                                     . ($status !== 'all' ? " in status '{$status}'" : '') . '.',
                        'data'    => $rows->toArray(),
                    ];
                }

                case 'platform.read_page': {
                    $id = (int)($params['page_id'] ?? 0);
                    if ($id <= 0) return ['success' => false, 'error' => 'page_id is required', 'code' => 'BAD_PARAMS'];
                    $row = DB::table('pages as p')
                        ->join('websites as w', 'w.id', '=', 'p.website_id')
                        ->where('p.id', $id)
                        ->where('w.workspace_id', $wsId)
                        ->first(['p.id', 'p.website_id', 'p.title', 'p.slug', 'p.type', 'p.status', 'p.sections_json', 'p.meta_title', 'p.meta_description', 'p.is_homepage', 'p.updated_at']);
                    if (!$row) return ['success' => false, 'error' => "Page #{$id} not found in this workspace", 'code' => 'NOT_FOUND'];
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => "Page #{$row->id} '{$row->title}' (status: {$row->status})",
                        'data'    => (array) $row,
                    ];
                }

                // 2026-05-27 — AgentBrowser tools. Route through WebActivityService
                // so credit + capability gates + audit trail apply uniformly.
                // taskId is null when invoked from chat (not from a research task);
                // when invoked from a research-task execution the engine layer
                // forwards the real task_id (Phase 3 wiring).
                case 'web.fetch': {
                    $url = trim((string)($params['url'] ?? ''));
                    if ($url === '') return ['success' => false, 'error' => 'url is required', 'code' => 'BAD_PARAMS'];
                    $agentSlug = $params['_agent_slug'] ?? 'sarah';
                    $userId    = $params['_user_id'] ?? null;
                    $taskId    = isset($params['task_id']) ? (int)$params['task_id'] : null;
                    return app(\App\Engines\Web\Services\WebActivityService::class)
                        ->fetch($wsId, $agentSlug, $userId, $url, $taskId);
                }

                case 'web.search': {
                    $query = trim((string)($params['query'] ?? ''));
                    if ($query === '') return ['success' => false, 'error' => 'query is required', 'code' => 'BAD_PARAMS'];
                    $agentSlug = $params['_agent_slug'] ?? 'sarah';
                    $userId    = $params['_user_id'] ?? null;
                    $taskId    = isset($params['task_id']) ? (int)$params['task_id'] : null;
                    return app(\App\Engines\Web\Services\WebActivityService::class)
                        ->search($wsId, $agentSlug, $userId, $query, $taskId);
                }

                // ─── v1.4.4 (2026-05-30) — Funnel intelligence ────────────
                // Cheap, deterministic reads from the workspace's own content.
                // Helps Sarah ground multi-channel pushes in the funnel rather
                // than guessing or assuming TOFU-only.
                case 'platform.analyze_funnel_structure': {
                    $articles = DB::table('articles')->where('workspace_id', $wsId)
                        ->select(['id', 'title', 'type', 'status', 'focus_keyword'])
                        ->limit(200)->get();
                    $stageOf = function (string $title, string $kw): string {
                        $t = strtolower($title . ' ' . $kw);
                        if (preg_match('/\b(pricing|cost|review|vs|alternative|compare|comparison|best.+for|case study)\b/', $t)) return 'BOFU';
                        if (preg_match('/\b(how to|guide|tutorial|template|checklist|tips|examples|playbook)\b/', $t)) return 'MOFU';
                        return 'TOFU';
                    };
                    $byStage = ['TOFU' => 0, 'MOFU' => 0, 'BOFU' => 0];
                    foreach ($articles as $a) { $byStage[$stageOf((string)$a->title, (string)($a->focus_keyword ?? ''))]++; }
                    $total = array_sum($byStage);
                    $weakStage = array_keys($byStage, min($byStage))[0] ?? 'BOFU';
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => "{$total} articles — TOFU {$byStage['TOFU']}, MOFU {$byStage['MOFU']}, BOFU {$byStage['BOFU']}. Thinnest stage: {$weakStage}.",
                        'data'    => ['by_stage' => $byStage, 'total' => $total, 'thinnest_stage' => $weakStage],
                    ];
                }

                case 'platform.generate_funnel_blueprint': {
                    // Build a per-stage blueprint from workspace context: target_audience,
                    // services, current keywords. Deterministic skeleton — Sarah's reasoning
                    // layer can dress it with persona / brand voice on top.
                    $mem = DB::table('workspace_memory')->where('workspace_id', $wsId)
                        ->pluck('value_json', 'key')->toArray();
                    $audience = is_string($mem['target_audience'] ?? null) ? trim($mem['target_audience'], '"') : '';
                    $services = is_string($mem['services'] ?? null) ? trim($mem['services'], '"') : '';
                    $industry = is_string($mem['industry'] ?? null) ? trim($mem['industry'], '"') : '';
                    $goal = (string)($params['goal'] ?? 'increase qualified leads');
                    $blueprint = [
                        'goal' => $goal,
                        'audience' => $audience,
                        'industry' => $industry,
                        'tofu' => [
                            'intent' => 'awareness',
                            'channels' => ['organic search (informational)', 'social (educational)', 'PR / brand mentions'],
                            'content_types' => ['blog: "what is X / why X matters"', 'short-form social', 'PR pitches'],
                            'kpis' => ['organic impressions', 'social reach', 'brand search volume'],
                        ],
                        'mofu' => [
                            'intent' => 'consideration',
                            'channels' => ['organic search (commercial)', 'email nurture', 'paid retargeting'],
                            'content_types' => ['comparison articles', 'case studies', 'webinars / guides', 'newsletter'],
                            'kpis' => ['email opens/clicks', 'demo signups', 'time on site', 'MQL conversion'],
                        ],
                        'bofu' => [
                            'intent' => 'decision',
                            'channels' => ['organic search (transactional)', 'email sales sequence', 'paid brand'],
                            'content_types' => ['pricing pages', 'service detail pages', 'reviews / testimonials', 'free trial / demo CTAs'],
                            'kpis' => ['demo bookings', 'leads created in CRM', 'revenue', 'CAC'],
                        ],
                        'note' => 'Use platform.analyze_funnel_structure to identify the thinnest stage; prioritise filling that first.',
                    ];
                    return [
                        'success' => true,
                        'tool'    => $toolId,
                        'result'  => 'Funnel blueprint generated for goal "' . $goal . '".',
                        'data'    => $blueprint,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("ToolSchemaService::executePlatformTool {$toolId} failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Tool execution failed: ' . $e->getMessage(), 'code' => 'TOOL_EXEC_ERROR'];
        }

        return ['success' => false, 'error' => "Platform tool not implemented: {$toolId}", 'code' => 'NOT_IMPLEMENTED'];
    }

    /**
     * For diagnostics / UI surfaces.
     */
    public function getAllToolIds(): array
    {
        return array_keys(self::TOOL_DEFINITIONS);
    }
}
