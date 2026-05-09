<?php

namespace App\Http\Controllers\Api\Widget;

use App\Core\Billing\FeatureGateService;
use App\Engines\Chatbot\Services\ChatbotResponseService;
use App\Engines\Chatbot\Services\ChatbotWidgetTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * CHATBOT888 — PUBLIC widget endpoints.
 *
 * NO JWT, NO API KEY. Authentication is via the public widget token
 * (cwt_*) presented in the X-CHATBOT-TOKEN header. Token validates the
 * Origin header against an allowlist on every request.
 *
 * Workspace_id is ALWAYS resolved from the token, NEVER from the request
 * body. This is the workspace-isolation invariant — a malicious widget
 * cannot impersonate another workspace.
 *
 * Rate limits are enforced atomically via Illuminate\\Support\\Facades\\
 * RateLimiter (Redis INCR under the hood — same primitive as P0 hardening).
 */
class PublicChatbotController
{
    public function __construct(
        private FeatureGateService $gate,
        private ChatbotResponseService $responder,
        private ChatbotWidgetTokenService $tokens,
    ) {}

    /**
     * GET /api/public/chatbot/config
     * Returns minimal branding/greeting for the widget. Workspace bound by token.
     */
    public function getConfig(Request $r): JsonResponse
    {
        [$tokenRow, $err] = $this->authToken($r);
        if ($err) return $err;

        // 30 reqs/min/IP
        if ($this->ipRateLimited($r, 'cb:config', 30, 60)) return $this->rate();

        $wsId = (int) $tokenRow->workspace_id;
        if (! $this->gate->canAccessChatbot($wsId)) {
            return response()->json(['success' => false, 'error' => 'PLAN_REQUIRED'], 403);
        }

        $settings = DB::table('chatbot_settings')->where('workspace_id', $wsId)->first();
        if (! $settings || ! $settings->enabled) {
            return response()->json(['success' => false, 'error' => 'CHATBOT_DISABLED'], 403);
        }

        // PATCH (per-website chatbot greeting, 2026-05-09) — Resolve the
        // tenant website by Origin/Referer header so the greeting can
        // identify the actual business the visitor is on, not the
        // workspace name. Substitutes {{business}} in chatbot_settings.
        // greeting with the resolved website name (falls back to
        // workspace name if no tenant subdomain match).
        $businessName = $this->resolveBusinessName($r, $wsId);
        $greeting = $settings->greeting ?: 'Hi! Welcome to {{business}}. How can I help you today?';
        $greeting = str_replace(['{{business}}', '{{business_name}}'], $businessName, $greeting);

        return response()->json([
            'success' => true,
            'data' => [
                'greeting'      => $greeting,
                'business_name' => $businessName,
                'primary_color' => $settings->primary_color ?: '#6C5CE7',
                'theme'         => $settings->theme ?: 'auto',
            ],
        ]);
    }

    /**
     * PATCH (per-website chatbot context, 2026-05-09) — Resolve the
     * business identity for a request based on Origin / Referer header.
     * Tenant subdomains -> the website's own name. Platform host or
     * unknown -> workspace business_name / name. Used for greeting
     * substitution; the LLM-side context derives independently in
     * ChatbotContextBuilder via session.page_url.
     */
    private function resolveBusinessName(Request $r, int $workspaceId): string
    {
        $origin = (string) ($r->header('Origin') ?: $r->header('Referer') ?: '');
        $host = $origin ? strtolower((string) parse_url($origin, PHP_URL_HOST)) : '';

        if ($host !== '' && ! in_array($host, ['levelupgrowth.io', 'www.levelupgrowth.io', 'staging.levelupgrowth.io'], true)) {
            $row = DB::table('websites')
                ->where('workspace_id', $workspaceId)
                ->where(function ($q) use ($host) {
                    $q->where('subdomain', $host)
                      ->orWhere('subdomain', explode('.', $host)[0])
                      ->orWhere('custom_domain', $host);
                })
                ->whereNull('deleted_at')
                ->value('name');
            if ($row) return (string) $row;
        }

        // Workspace fallback
        $ws = DB::table('workspaces')->where('id', $workspaceId)->first(['business_name', 'name']);
        return (string) ($ws->business_name ?? $ws->name ?? 'this business');
    }

    /**
     * POST /api/public/chatbot/session/start
     * Body: { page_url, fingerprint }
     * Returns: { session_id }
     */
    public function startSession(Request $r): JsonResponse
    {
        [$tokenRow, $err] = $this->authToken($r);
        if ($err) return $err;
        if ($this->ipRateLimited($r, 'cb:start', 30, 60)) return $this->rate();

        $wsId = (int) $tokenRow->workspace_id;
        if (! $this->gate->canAccessChatbot($wsId)) {
            return response()->json(['success' => false, 'error' => 'PLAN_REQUIRED'], 403);
        }

        $data = $r->validate([
            'page_url'    => 'nullable|string|max:1024',
            'fingerprint' => 'nullable|string|max:128',
        ]);

        $sessionId = DB::table('chatbot_sessions')->insertGetId([
            'workspace_id'         => $wsId,
            'widget_token_id'      => $tokenRow->id,
            'page_url'             => $data['page_url'] ?? null,
            'visitor_fingerprint'  => $data['fingerprint'] ?? null,
            'message_count'        => 0,
            'created_at'           => now(),
        ]);
        return response()->json([
            'success' => true,
            'data'    => ['session_id' => $sessionId],
        ]);
    }

    /**
     * POST /api/public/chatbot/message
     * Body: { session_id, message }
     */
    public function postMessage(Request $r): JsonResponse
    {
        [$tokenRow, $err] = $this->authToken($r);
        if ($err) return $err;
        if ($this->ipRateLimited($r, 'cb:msg-ip', 60, 60)) return $this->rate();

        $data = $r->validate([
            'session_id' => 'required|integer',
            'message'    => 'required|string|max:4000',
            'hp'         => 'nullable|string|max:255',  // honeypot — must be empty
            'started_at' => 'nullable|integer',         // ms epoch widget rendered
        ]);

        // Honeypot: if filled, drop silently (return generic 200 to fool bots).
        if (! empty(trim((string) ($data['hp'] ?? '')))) {
            Log::info('[chatbot] honeypot triggered', ['ip' => $r->ip(), 'token_id' => $tokenRow->id]);
            return response()->json(['success' => true, 'data' => ['message' => '...']]);
        }
        // Min interaction time: bots submit immediately. Reject < 1.5s.
        if (! empty($data['started_at'])) {
            $age = (int) (now()->getTimestampMs() - $data['started_at']);
            if ($age < 1500) {
                Log::info('[chatbot] sub-second submit dropped', ['ip' => $r->ip(), 'age_ms' => $age]);
                return response()->json(['success' => false, 'error' => 'TOO_FAST'], 429);
            }
        }

        // Per-session limiter: 20 msgs/min/session
        if (RateLimiter::tooManyAttempts('cb:msg-session:' . $data['session_id'], 20)) {
            return $this->rate();
        }
        RateLimiter::hit('cb:msg-session:' . $data['session_id'], 60);

        $session = DB::table('chatbot_sessions')->where('id', $data['session_id'])->first();
        if (! $session || (int) $session->workspace_id !== (int) $tokenRow->workspace_id) {
            return response()->json(['success' => false, 'error' => 'SESSION_NOT_FOUND'], 404);
        }

        return response()->json($this->responder->handleMessage((int) $data['session_id'], $data['message']));
    }

    /**
     * POST /api/public/chatbot/lead
     * Body: { session_id, name, email, phone, notes, hp, started_at }
     */
    public function captureLead(Request $r): JsonResponse
    {
        [$tokenRow, $err] = $this->authToken($r);
        if ($err) return $err;
        if ($this->ipRateLimited($r, 'cb:lead-ip', 20, 60)) return $this->rate();

        $data = $r->validate([
            'session_id' => 'required|integer',
            'name'       => 'nullable|string|max:255',
            'email'      => 'nullable|email|max:255',
            'phone'      => 'nullable|string|max:50',
            'notes'      => 'nullable|string|max:2000',
            'hp'         => 'nullable|string|max:255',
            'started_at' => 'nullable|integer',
        ]);
        if (! empty(trim((string) ($data['hp'] ?? '')))) {
            Log::info('[chatbot] honeypot triggered (lead)', ['ip' => $r->ip()]);
            return response()->json(['success' => true]);
        }

        // Per-session limiter: 5 lead submits/min
        if (RateLimiter::tooManyAttempts('cb:lead-session:' . $data['session_id'], 5)) return $this->rate();
        RateLimiter::hit('cb:lead-session:' . $data['session_id'], 60);

        $session = DB::table('chatbot_sessions')->where('id', $data['session_id'])->first();
        if (! $session || (int) $session->workspace_id !== (int) $tokenRow->workspace_id) {
            return response()->json(['success' => false, 'error' => 'SESSION_NOT_FOUND'], 404);
        }
        return response()->json($this->responder->captureLead((int) $data['session_id'], $data));
    }

    /**
     * POST /api/public/chatbot/booking-request
     * Body: { session_id, name, email, phone, date, time, service, notes }
     */
    public function bookingRequest(Request $r): JsonResponse
    {
        return $this->bookingOrCallback($r, 'booking');
    }

    /**
     * POST /api/public/chatbot/callback-request
     */
    public function callbackRequest(Request $r): JsonResponse
    {
        return $this->bookingOrCallback($r, 'callback');
    }

    private function bookingOrCallback(Request $r, string $kind): JsonResponse
    {
        [$tokenRow, $err] = $this->authToken($r);
        if ($err) return $err;
        if ($this->ipRateLimited($r, 'cb:bk-ip', 10, 60)) return $this->rate();

        $data = $r->validate([
            'session_id' => 'required|integer',
            'name'       => 'nullable|string|max:255',
            'email'      => 'nullable|email|max:255',
            'phone'      => 'nullable|string|max:50',
            'date'       => 'nullable|string|max:32',
            'time'       => 'nullable|string|max:16',
            'service'    => 'nullable|string|max:120',
            'notes'      => 'nullable|string|max:2000',
            'hp'         => 'nullable|string|max:255',
            'started_at' => 'nullable|integer',
        ]);
        if (! empty(trim((string) ($data['hp'] ?? '')))) {
            Log::info('[chatbot] honeypot triggered (booking)', ['ip' => $r->ip()]);
            return response()->json(['success' => true]);
        }

        if (RateLimiter::tooManyAttempts('cb:bk-session:' . $data['session_id'], 3)) return $this->rate();
        RateLimiter::hit('cb:bk-session:' . $data['session_id'], 60);

        $session = DB::table('chatbot_sessions')->where('id', $data['session_id'])->first();
        if (! $session || (int) $session->workspace_id !== (int) $tokenRow->workspace_id) {
            return response()->json(['success' => false, 'error' => 'SESSION_NOT_FOUND'], 404);
        }
        return response()->json($this->responder->createBookingOrCallback((int) $data['session_id'], $kind, $data));
    }

    // ── Private helpers ──────────────────────────────────────

    /**
     * Validate widget token + Origin header in one shot. Returns
     * [tokenRow, null] on success or [null, JsonResponse] on failure.
     */
    private function authToken(Request $r): array
    {
        $plain = $r->header('X-CHATBOT-TOKEN');
        if (! $plain) {
            return [null, response()->json(['success' => false, 'error' => 'TOKEN_MISSING'], 401)];
        }
        $tokenRow = $this->tokens->verify($plain);
        if (! $tokenRow) {
            return [null, response()->json(['success' => false, 'error' => 'TOKEN_INVALID'], 401)];
        }

        // Domain origin check — fail-closed.
        $origin = $r->header('Origin');
        if (! $this->tokens->originAllowed($tokenRow, $origin)) {
            Log::info('[chatbot] origin denied', [
                'token_id' => $tokenRow->id,
                'origin'   => $origin,
                'ip'       => $r->ip(),
            ]);
            return [null, response()->json(['success' => false, 'error' => 'DOMAIN_NOT_ALLOWED'], 403)];
        }
        return [$tokenRow, null];
    }

    private function ipRateLimited(Request $r, string $bucket, int $limit, int $windowSecs): bool
    {
        $key = $bucket . ':' . $r->ip();
        if (RateLimiter::tooManyAttempts($key, $limit)) return true;
        RateLimiter::hit($key, $windowSecs);
        return false;
    }

    private function rate(): JsonResponse
    {
        return response()->json([
            'success' => false, 'error' => 'RATE_LIMITED',
            'message' => 'Too many requests. Please wait a moment.',
        ], 429);
    }
}
