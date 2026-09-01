<?php

namespace App\Http\Controllers\Api\Admin;

use App\Core\Billing\FeatureGateService;
use App\Engines\Chatbot\Services\ChatbotKnowledgeService;
use App\Engines\Chatbot\Services\ChatbotWidgetTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CHATBOT888 — Authenticated admin endpoints for the SPA Chatbot panel.
 *
 * All endpoints are gated by:
 *   1. JWT auth (workspace_id bound on request via auth.jwt middleware)
 *   2. FeatureGateService::canAccessChatbot — enforced inside each handler
 *      because we want a clean PLAN_REQUIRED 403 instead of a generic deny.
 *
 * Workspace isolation: every DB read/write uses $wsId from request attributes.
 */
class AdminChatbotController
{
    public function __construct(
        private FeatureGateService $gate,
        private ChatbotKnowledgeService $kb,
        private ChatbotWidgetTokenService $tokens,
    ) {}

    public function getSettings(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        // INC-0006: settings belong to a website. An explicit website_id edits that site; without one
        // the caller is editing the business-wide default that every site inherits.
        [$websiteId, $wsErr] = \App\Core\Tenancy\WebsiteScope::resolve($wsId, (int) $r->input('website_id', 0));
        if ($wsErr === \App\Core\Tenancy\WebsiteScope::NOT_IN_WORKSPACE) {
            return response()->json(['success' => false, 'error' => $wsErr], 422);
        }
        $row = \App\Core\Tenancy\WebsiteScope::settingsRow('chatbot_settings', $wsId, $websiteId);
        if (! $row) {
            $row = (object) [
                'workspace_id' => $wsId,
                'website_id'   => $websiteId ?? 0,
                'enabled'      => false,
                'greeting'     => 'Hi! How can I help you today?',
                'fallback_email' => null,
                'primary_color'  => '#6C5CE7',
                'theme'         => 'auto',
                'business_hours_json' => null,
                'timezone'      => 'UTC',
                'business_context_text' => null,
            ];
        }
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function updateSettings(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        $data = $r->validate([
            'enabled'                => 'sometimes|boolean',
            'greeting'               => 'sometimes|nullable|string|max:500',
            'fallback_email'         => 'sometimes|nullable|email|max:255',
            'primary_color'          => 'sometimes|nullable|string|max:10',
            'theme'                  => 'sometimes|in:light,dark,auto',
            'business_hours'         => 'sometimes|nullable|array',
            'timezone'               => 'sometimes|nullable|string|max:64',
            'business_context_text'  => 'sometimes|nullable|string|max:8000',
            'website_id'             => 'sometimes|nullable|integer',
        ]);

        $update = [];
        foreach (['enabled','greeting','fallback_email','primary_color','theme','timezone','business_context_text'] as $k) {
            if (array_key_exists($k, $data)) $update[$k] = $data[$k];
        }
        if (array_key_exists('business_hours', $data)) {
            $update['business_hours_json'] = $data['business_hours'] ? json_encode($data['business_hours']) : null;
        }
        $update['updated_at'] = now();

        // INC-0006: target exactly ONE row. The previous update() matched on workspace_id alone, which
        // now that the table has a website dimension would rewrite every website's settings at once.
        [$websiteId, $wsErr] = \App\Core\Tenancy\WebsiteScope::resolve($wsId, (int) $r->input('website_id', 0));
        if ($wsErr === \App\Core\Tenancy\WebsiteScope::NOT_IN_WORKSPACE) {
            return response()->json(['success' => false, 'error' => $wsErr], 422);
        }
        $target = (int) ($websiteId ?? \App\Core\Tenancy\WebsiteScope::BUSINESS_DEFAULT);
        DB::table('chatbot_settings')->updateOrInsert(
            ['workspace_id' => $wsId, 'website_id' => $target],
            $update + ['created_at' => now()],
        );

        // T2.3 — settings change affects every published page in the workspace
        $this->bustPublishedSiteCache($wsId);

        return response()->json([
            'success' => true,
            'data'    => \App\Core\Tenancy\WebsiteScope::settingsRow('chatbot_settings', $wsId, $target),
        ]);
    }

    /**
     * RISK-0118 (2026-08-29) — which website does a knowledge source belong to?
     * explicit website_id (must be this workspace's) > the workspace's only website > null.
     * In a multi-site workspace the caller must choose (WEBSITE_REQUIRED) — never shared by accident.
     * @return array{0:?int,1:?string}
     */
    private function websiteForSource(Request $r, int $wsId): array
    {
        $sites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $given = (int) $r->input('website_id', 0);
        if ($given > 0) return in_array($given, $sites, true) ? [$given, null] : [null, 'WEBSITE_NOT_IN_WORKSPACE'];
        if (count($sites) === 1) return [$sites[0], null];
        if (count($sites) === 0) return [null, null];
        return [null, 'WEBSITE_REQUIRED'];
    }

    public function uploadKnowledge(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        $r->validate([
            'file'  => 'required|file|max:10240',  // 10 MB
            'label' => 'nullable|string|max:255',
            'website_id' => 'nullable|integer',    // 2026-07-02 — per-website KB tagging
        ]);

        // Per-workspace doc cap.
        $count = (int) DB::table('chatbot_knowledge_sources')
            ->where('workspace_id', $wsId)->count();
        $limit = $this->gate->chatbotKbDocLimit($wsId);
        if ($limit > 0 && $count >= $limit) {
            return response()->json([
                'success' => false,
                'error'   => 'KB_LIMIT_REACHED',
                'message' => "Knowledge base limit reached ({$limit} documents). Delete an existing document or upgrade your plan.",
            ], 403);
        }

        try {
            [$__wid, $__werr] = $this->websiteForSource($r, (int) $wsId);
            if ($__werr) return response()->json(['success' => false, 'error' => $__werr, 'message' => 'Choose which website this knowledge belongs to.'], 422);
            $sourceId = $this->kb->ingestFile($wsId, $r->file('file'), $r->input('label'), $__wid);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => 'VALIDATION', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('[chatbot] uploadKnowledge failed', ['workspace_id' => $wsId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'INTERNAL', 'message' => 'Could not ingest document.'], 500);
        }
        return response()->json([
            'success' => true,
            'data'    => DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->first(),
        ]);
    }

    public function patchKnowledgeText(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        // The SPA's Knowledge tab sent `title`; the contract required `label` -> every add failed 422
        // and the tab was dead UI. Accept both.
        if (! $r->filled('label') && $r->filled('title')) $r->merge(['label' => $r->input('title')]);
        $data = $r->validate([
            'label' => 'required|string|max:255',
            'text'  => 'required|string|max:200000',
            'website_id' => 'nullable|integer',    // 2026-07-02 — per-website KB tagging
        ]);
        [$__wid, $__werr] = $this->websiteForSource($r, (int) $wsId);
        if ($__werr) return response()->json(['success' => false, 'error' => $__werr, 'message' => 'Choose which website this knowledge belongs to.'], 422);
        $sourceId = $this->kb->ingestText($wsId, $data['label'], $data['text'], $__wid);
        return response()->json([
            'success' => true,
            'data'    => DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->first(),
        ]);
    }

    public function listKnowledge(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        // RISK-0118 — each source is shown with the website it belongs to; websites listed for the picker.
        $sources = DB::table('chatbot_knowledge_sources as s')
            ->leftJoin('websites as w', 'w.id', '=', 's.website_id')
            ->where('s.workspace_id', $wsId)
            ->orderByDesc('s.created_at')
            ->get(['s.id','s.label','s.source_type','s.mime_type','s.size_bytes','s.chunk_count','s.status','s.error_message','s.created_at','s.website_id','w.name as website_name']);
        $websites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderBy('id')->get(['id','name']);
        return response()->json([
            'success' => true,
            'data'    => [
                'sources'   => $sources,
                'websites'  => $websites,
                'doc_count' => $sources->count(),
                'doc_limit' => $this->gate->chatbotKbDocLimit($wsId),
            ],
        ]);
    }

    public function deleteKnowledge(Request $r, int $id): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;
        $deleted = $this->kb->deleteSource($wsId, $id);
        return response()->json(['success' => $deleted]);
    }

    public function listConversations(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        // INC-0006: a conversation happened on ONE website. Listing by workspace alone put every
        // site's visitors in every site's inbox — the sibling contamination this incident is about.
        [$websiteId, $wsErr] = \App\Core\Tenancy\WebsiteScope::resolve($wsId, (int) $r->input('website_id', 0));
        if ($wsErr === \App\Core\Tenancy\WebsiteScope::NOT_IN_WORKSPACE) {
            return response()->json(['success' => false, 'error' => $wsErr], 422);
        }

        $sessions = DB::table('chatbot_sessions')
            ->where('workspace_id', $wsId)
            ->when($websiteId, fn ($q) => $q->where('website_id', $websiteId))
            ->orderByDesc('created_at')
            ->limit((int) $r->input('limit', 100))
            ->get(['id','website_id','page_url','visitor_name','visitor_email','visitor_phone','message_count','lead_id','created_at','ended_at']);

        return response()->json(['success' => true, 'website_id' => $websiteId, 'data' => $sessions]);
    }

    public function getConversation(Request $r, int $id): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        $session = DB::table('chatbot_sessions')->where('id', $id)->where('workspace_id', $wsId)->first();
        if (! $session) return response()->json(['success' => false, 'error' => 'NOT_FOUND'], 404);
        $messages = DB::table('chatbot_messages')
            ->where('session_id', $id)->where('workspace_id', $wsId)
            ->orderBy('id')->get(['id','role','content','intent','credits_used','created_at']);
        return response()->json(['success' => true, 'data' => ['session' => $session, 'messages' => $messages]]);
    }

    public function listLeads(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        $leads = DB::table('leads')
            ->where('workspace_id', $wsId)
            ->where('source', 'chatbot888')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit((int) $r->input('limit', 100))
            ->get(['id','name','email','phone','status','score','created_at']);
        return response()->json(['success' => true, 'data' => $leads]);
    }

    public function listBookings(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        $events = DB::table('calendar_events')
            ->where('workspace_id', $wsId)
            ->where('engine', 'chatbot888')
            ->orderByDesc('starts_at')
            ->limit((int) $r->input('limit', 100))
            ->get(['id','title','category','starts_at','ends_at','reference_id','reference_type','description']);
        return response()->json(['success' => true, 'data' => $events]);
    }

    public function listEscalations(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        // INC-0006: an escalation inherits its website from the session it came out of. The join is
        // deliberate — the website is NOT copied onto the escalation, because the session already owns
        // that fact and duplicating it would create a second version of the truth that can drift.
        [$websiteId, $wsErr] = \App\Core\Tenancy\WebsiteScope::resolve($wsId, (int) $r->input('website_id', 0));
        if ($wsErr === \App\Core\Tenancy\WebsiteScope::NOT_IN_WORKSPACE) {
            return response()->json(['success' => false, 'error' => $wsErr], 422);
        }

        $items = DB::table('chatbot_escalations as e')
            ->leftJoin('chatbot_sessions as s', 's.id', '=', 'e.session_id')
            ->where('e.workspace_id', $wsId)
            ->when($websiteId, fn ($q) => $q->where('s.website_id', $websiteId))
            ->orderByDesc('e.created_at')
            ->limit((int) $r->input('limit', 100))
            ->get(['e.id','e.session_id','e.question','e.reason','e.status','e.created_at','e.updated_at',
                   's.website_id as website_id', 's.page_url as page_url']);

        return response()->json(['success' => true, 'website_id' => $websiteId, 'data' => $items]);
    }

    public function listWidgetTokens(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;
        $tokens = DB::table('chatbot_widget_tokens')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->get(['id','token_prefix','label','site_connection_id','website_id','allowed_domains_json','status','last_used_at','revoked_at','created_at']);
        return response()->json(['success' => true, 'data' => $tokens]);
    }

    public function mintWidgetToken(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;
        $data = $r->validate([
            'site_connection_id' => 'nullable|integer',
            'website_id'         => 'nullable|integer',
            'allowed_domains'    => 'required|array|min:1|max:10',
            'allowed_domains.*'  => 'required|string|max:255',
            'label'              => 'nullable|string|max:255',
        ]);
        $result = $this->tokens->mint(
            $wsId,
            $data['site_connection_id'] ?? null,
            $data['website_id'] ?? null,
            $data['allowed_domains'],
            $data['label'] ?? null
        );

        // T2.3 — mint just wrote settings_json.chatbot_widget_token; bust render cache
        $this->bustPublishedSiteCache($wsId, $data['website_id'] ?? null);

        return response()->json([
            'success' => true,
            'data' => [
                'token'   => $result['plain'],
                'prefix'  => $result['prefix'],
                'id'      => $result['id'],
                'note'    => 'Save this token — it will never be shown again.',
            ],
        ]);
    }

    public function revokeWidgetToken(Request $r, int $id): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;
        $row = DB::table('chatbot_widget_tokens')
            ->where('id', $id)->where('workspace_id', $wsId)->first();
        if (! $row) return response()->json(['success' => false, 'error' => 'NOT_FOUND'], 404);
        $this->tokens->revoke($id);

        // T2.3 — revoked token's website should re-render without the widget script
        $this->bustPublishedSiteCache($wsId, $row->website_id ?? null);

        return response()->json(['success' => true]);
    }

    /**
     * 2026-05-28 — Dispatch a website crawl for this workspace. Async via
     * CrawlChatbotKnowledgeJob; returns 202 immediately. Refuses overlap.
     */
    public function crawlSite(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        $data = $r->validate([
            'max_pages'  => 'nullable|integer|min:1|max:200',
            'website_id' => 'nullable|integer',
        ]);
        $maxPages = (int) ($data['max_pages'] ?? \App\Engines\Chatbot\Services\ChatbotWebsiteCrawler::DEFAULT_MAX_PAGES);

        // INC-0006: a crawl targets one website. Its progress, and the ALREADY_RUNNING guard,
        // are keyed to that website so a business can crawl a second site while the first runs.
        [$__crawlWid, $__crawlErr] = \App\Core\Tenancy\WebsiteScope::resolve((int) $wsId, (int) $r->input('website_id', 0));
        if ($__crawlErr !== null) {
            return response()->json([
                'success' => false,
                'error'   => $__crawlErr,
                'message' => 'Choose which website to crawl.',
            ], 422);
        }

        if (\App\Jobs\CrawlChatbotKnowledgeJob::isRunning($wsId, $__crawlWid)) {
            return response()->json([
                'success' => false,
                'error'   => 'ALREADY_RUNNING',
                'message' => 'A crawl is already in progress for this website.',
                'status'  => Cache::get(\App\Jobs\CrawlChatbotKnowledgeJob::statusKey($wsId, $__crawlWid)),
            ], 409);
        }

        \App\Jobs\CrawlChatbotKnowledgeJob::dispatch($wsId, $maxPages, $__crawlWid);

        return response()->json([
            'success'   => true,
            'message'   => 'Crawl queued — pages will appear in the knowledge base shortly.',
            'max_pages' => $maxPages,
        ], 202);
    }

    public function crawlStatus(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;
        [$__crawlWid] = \App\Core\Tenancy\WebsiteScope::resolve((int) $wsId, (int) $r->query('website_id', 0));
        $status = Cache::get(\App\Jobs\CrawlChatbotKnowledgeJob::statusKey($wsId, $__crawlWid));
        return response()->json(['success' => true, 'status' => $status]);
    }

    // ── Private helpers ──────────────────────────────────────

    private function wsId(Request $r): int
    {
        return (int) $r->attributes->get('workspace_id');
    }

    private function planDeny(int $wsId): ?JsonResponse
    {
        if (! $this->gate->canAccessChatbot($wsId)) {
            $eligible = $this->gate->chatbotAddonEligible($wsId);
            return response()->json([
                'success' => false,
                'error'   => 'PLAN_REQUIRED',
                'code'    => 'PLAN_REQUIRED',
                'message' => $eligible
                    ? 'Chatbot888 is available as a $39/month add-on or by upgrading to Pro.'
                    : 'Chatbot888 requires a plan upgrade.',
                'addon_eligible' => $eligible,
            ], 403);
        }
        return null;
    }

    /**
     * Bust published_site:* render cache for a workspace's websites. Mirrors
     * the existing cache-bust pattern in BuilderService::publishWebsite() —
     * iterates published pages and Cache::forget()s each {subdomain}:{slug},
     * plus a belt-and-suspenders forget on {subdomain}:home.
     *
     * If $websiteId is null, busts every published website in the workspace
     * (used when workspace-scoped settings change). If provided, only that
     * site (used for token mint/revoke).
     */
    private function bustPublishedSiteCache(int $wsId, ?int $websiteId = null): void
    {
        $sites = DB::table('websites')
            ->where('workspace_id', $wsId)
            ->where('status', 'published')
            ->when($websiteId, fn($q) => $q->where('id', $websiteId))
            ->get(['id', 'subdomain']);

        foreach ($sites as $site) {
            $subdomain = str_replace('.levelupgrowth.io', '', $site->subdomain ?? '');
            if ($subdomain === '') continue;

            $slugs = DB::table('pages')
                ->where('website_id', $site->id)
                ->where('status', 'published')
                ->pluck('slug');

            foreach ($slugs as $slug) {
                Cache::forget("published_site:{$subdomain}:{$slug}");
            }
            Cache::forget("published_site:{$subdomain}:home");
        }
    }
}
