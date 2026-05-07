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

        $row = DB::table('chatbot_settings')->where('workspace_id', $wsId)->first();
        if (! $row) {
            $row = (object) [
                'workspace_id' => $wsId,
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
        ]);

        $update = [];
        foreach (['enabled','greeting','fallback_email','primary_color','theme','timezone','business_context_text'] as $k) {
            if (array_key_exists($k, $data)) $update[$k] = $data[$k];
        }
        if (array_key_exists('business_hours', $data)) {
            $update['business_hours_json'] = $data['business_hours'] ? json_encode($data['business_hours']) : null;
        }
        $update['updated_at'] = now();

        $existing = DB::table('chatbot_settings')->where('workspace_id', $wsId)->first();
        if ($existing) {
            DB::table('chatbot_settings')->where('workspace_id', $wsId)->update($update);
        } else {
            $update['workspace_id'] = $wsId;
            $update['created_at']   = now();
            DB::table('chatbot_settings')->insert($update);
        }

        // T2.3 — settings change affects every published page in the workspace
        $this->bustPublishedSiteCache($wsId);

        return response()->json([
            'success' => true,
            'data'    => DB::table('chatbot_settings')->where('workspace_id', $wsId)->first(),
        ]);
    }

    public function uploadKnowledge(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        $r->validate([
            'file'  => 'required|file|max:10240',  // 10 MB
            'label' => 'nullable|string|max:255',
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
            $sourceId = $this->kb->ingestFile($wsId, $r->file('file'), $r->input('label'));
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

        $data = $r->validate([
            'label' => 'required|string|max:255',
            'text'  => 'required|string|max:200000',
        ]);
        $sourceId = $this->kb->ingestText($wsId, $data['label'], $data['text']);
        return response()->json([
            'success' => true,
            'data'    => DB::table('chatbot_knowledge_sources')->where('id', $sourceId)->first(),
        ]);
    }

    public function listKnowledge(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        if ($denial = $this->planDeny($wsId)) return $denial;

        $sources = DB::table('chatbot_knowledge_sources')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->get(['id','label','source_type','mime_type','size_bytes','chunk_count','status','error_message','created_at']);
        return response()->json([
            'success' => true,
            'data'    => [
                'sources'   => $sources,
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

        $sessions = DB::table('chatbot_sessions')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->limit((int) $r->input('limit', 100))
            ->get(['id','page_url','visitor_name','visitor_email','visitor_phone','message_count','lead_id','created_at','ended_at']);
        return response()->json(['success' => true, 'data' => $sessions]);
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

        $items = DB::table('chatbot_escalations')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->limit((int) $r->input('limit', 100))
            ->get();
        return response()->json(['success' => true, 'data' => $items]);
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
