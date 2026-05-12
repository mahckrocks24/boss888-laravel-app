<?php

namespace App\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * RuntimeClient
 *
 * Thin outbound HTTP client for the LevelUp Node.js runtime
 * (https://levelup-runtime2-production.up.railway.app).
 *
 * Created: 2026-04-11 as part of Phase 0.6 (Laravel↔Runtime bridge).
 *
 * Auth model:
 *   - Runtime's requireSecret middleware (index.js:433) checks
 *     `req.headers['x-levelup-secret'] === process.env.WP_SECRET`.
 *   - Laravel sends header `X-LevelUp-Secret: env('RUNTIME_SECRET')`,
 *     where RUNTIME_SECRET holds the same value as Railway's WP_SECRET.
 *
 * Scope (Phase 0.6):
 *   - Outbound only. Laravel → runtime.
 *   - Health check + generic post()/get() + aiRun() convenience.
 *   - Multi-agent meeting wiring is DEFERRED to Phase 0.6b — runtime
 *     currently registers only 6 agents vs. the 21 the master context
 *     expects, so plumbing AgentMeetingEngine through here would cause
 *     silent regressions in Sarah's orchestration.
 *
 * Not a BaseConnector subclass on purpose:
 *   - BaseConnector is the engine-facing execute/validate/verify pattern.
 *   - RuntimeClient is a transport-level client, not an engine.
 *
 * @see boss888-audit/logs/2026-04-11-phase-0-foundation/01-runtime-contract.md
 */
class RuntimeClient
{
    private string $baseUrl;
    private string $secret;
    private int    $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) env('RUNTIME_URL', ''), '/');
        $this->secret  = (string) env('RUNTIME_SECRET', '');
        $this->timeout = (int) env('RUNTIME_TIMEOUT', 30);
    }

    /**
     * Is the client configured well enough to make authenticated calls?
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->secret !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * POST to a runtime endpoint with the shared secret header.
     *
     * Returns the raw Response so callers can inspect status + body.
     * Does NOT throw on non-2xx — callers decide how to handle failures
     * (engine actions may want to record intelligence data on failure
     * rather than abort).
     */
    public function post(string $path, array $payload = [], ?int $timeout = null): Response
    {
        $this->assertConfigured();

        return Http::withHeaders([
                'X-LevelUp-Secret' => $this->secret,
            ])
            ->acceptJson()
            ->asJson()
            ->timeout($timeout ?? $this->timeout)
            ->post($this->baseUrl . $this->normalisePath($path), $payload);
    }

    /**
     * GET from a runtime endpoint with the shared secret header.
     */
    public function get(string $path, array $query = []): Response
    {
        $this->assertConfigured();

        return Http::withHeaders([
                'X-LevelUp-Secret' => $this->secret,
            ])
            ->acceptJson()
            ->timeout($this->timeout)
            ->get($this->baseUrl . $this->normalisePath($path), $query);
    }

    /**
     * Hit the public /health endpoint (no auth).
     * Useful as a smoke test before we trust the secret.
     *
     * @return array{ok: bool, status?: string, version?: string, error?: string, http_code?: int}
     */
    public function health(): array
    {
        if ($this->baseUrl === '') {
            return ['ok' => false, 'error' => 'RUNTIME_URL not configured'];
        }

        try {
            $resp = Http::acceptJson()
                ->timeout($this->timeout)
                ->get($this->baseUrl . '/health');
        } catch (ConnectionException $e) {
            return ['ok' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];
        return [
            'ok'        => $resp->successful(),
            'http_code' => $resp->status(),
            'status'    => $body['status'] ?? null,
            'version'   => $body['version'] ?? null,
            'phase'     => $body['phase'] ?? null,
            'agents'    => $body['agents'] ?? [],
            'tools'     => $body['tools'] ?? [],
            'config'    => $body['config'] ?? [],
        ];
    }

    /**
     * Hit /internal/health (auth-protected).
     * Confirms the shared secret is valid end-to-end.
     *
     * @return array{ok: bool, http_code?: int, body?: array, error?: string}
     */
    public function internalHealth(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'RuntimeClient not configured (missing URL or secret)'];
        }

        try {
            $resp = $this->get('/internal/health');
        } catch (ConnectionException $e) {
            return ['ok' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        return [
            'ok'        => $resp->successful(),
            'http_code' => $resp->status(),
            'body'      => $resp->json() ?? [],
        ];
    }

    /**
     * POST /ai/run — unified LLM dispatcher on the runtime side.
     *
     * Supported task types (from runtime index.js SYSTEM_PROMPTS map):
     *   seo_content_generation, image_generation, builder_generate,
     *   competitor_analysis, email_generation, social_post,
     *   write_article, improve_draft, serp_analysis, competitor_keywords.
     *
     * @return array{success: bool, text?: string, raw?: array, error?: string}
     */
    public function aiRun(string $task, string $prompt, array $context = [], int $maxTokens = 1200): array
    {
        try {
            // /ai/run can run 30-60s for long generations (builder per-page, scene plans).
            // Use a generous timeout — most calls return faster anyway.
            $resp = $this->post('/ai/run', [
                'task'       => $task,
                'prompt'     => $prompt,
                'context'    => $context,
                'max_tokens' => $maxTokens,
            ], 90);
        } catch (ConnectionException $e) {
            Log::warning('RuntimeClient::aiRun connection failed', ['task' => $task, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (! $resp->successful()) {
            Log::warning('RuntimeClient::aiRun non-2xx', [
                'task' => $task, 'http_code' => $resp->status(), 'body' => $body,
            ]);
            return [
                'success' => false,
                'error'   => $body['error'] ?? 'http_' . $resp->status(),
                'raw'     => $body,
            ];
        }

        return [
            'success' => $body['success'] ?? true,
            // FIX 2026-04-12: runtime /ai/run returns the LLM text in `output` (verified
            // via curl). Earlier code only checked `text` / `content`, so every aiRun
            // call returned null text and downstream callers fell through to fallbacks.
            // Order: output (canonical) → text → content (defensive aliases).
            'text'    => $body['output'] ?? ($body['text'] ?? ($body['content'] ?? null)),
            'raw'     => $body,
        ];
    }

    /**
     * POST /ai/run with task=chat_json — generic JSON-mode escape hatch.
     *
     * ADDED 2026-04-13 (Phase 0.17b): cleaner alternative to the fold-pattern
     * workaround that ~12 refactored Laravel sites used. Caller passes a custom
     * `system` prompt directly (instead of folding it into the user prompt) and
     * the runtime forces DeepSeek into JSON mode + parses the output server-side.
     *
     * Returned shape:
     *   - success     : bool
     *   - parsed      : the decoded JSON object (or null if parse failed)
     *   - text        : raw output string (always present on success)
     *   - parse_error : string explaining why parse failed (only when parsed === null)
     *   - error       : connection/HTTP error message (only when success === false)
     *
     * Use this whenever a Laravel call site needs structured JSON output and
     * none of the engine-specific task types (email_generation, social_post,
     * seo_content_generation, etc.) is the right semantic match.
     *
     * @param string $system    The custom system prompt — MUST instruct the model
     *                          to respond with valid JSON in the exact shape needed.
     * @param string $userPrompt The user message (just the request, no system prefix).
     * @param array  $context    Optional context dict that gets appended as "key: value" lines.
     * @param int    $maxTokens  Default 1200, bump for larger structured outputs.
     */
    public function chatJson(string $system, string $userPrompt, array $context = [], int $maxTokens = 1200): array
    {
        // PATCH (Intel Fix 7) — DeepSeek's `response_format: json_object` rejects
        // any call where the literal word "json" doesn't appear in either
        // system or user prompt. Some upstream callers (Sarah chat, plan
        // extraction) didn't include it, producing 6 silent failures in
        // laravel.log on 2026-05-08. Enforce here so all callers benefit.
        if (stripos($system, 'json') === false && stripos($userPrompt, 'json') === false) {
            $system = trim($system) . "\n\nRespond with valid JSON only. No prose, no markdown fences.";
        }

        try {
            $resp = $this->post('/ai/run', [
                'task'       => 'chat_json',
                'system'     => $system,
                'prompt'     => $userPrompt,
                'context'    => $context,
                'max_tokens' => $maxTokens,
            ], 90);
        } catch (ConnectionException $e) {
            Log::warning('RuntimeClient::chatJson connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (! $resp->successful() || !($body['success'] ?? false)) {
            Log::warning('RuntimeClient::chatJson non-2xx or success=false', [
                'http_code' => $resp->status(), 'body' => $body,
            ]);
            return [
                'success' => false,
                'error'   => $body['error'] ?? 'http_' . $resp->status(),
                'raw'     => $body,
            ];
        }

        $out = [
            'success' => true,
            'parsed'  => $body['parsed'] ?? null,
            'text'    => $body['output'] ?? '',
            'raw'     => $body,
        ];

        if (array_key_exists('parse_error', $body)) {
            $out['parse_error'] = $body['parse_error'];
        }

        $textLen = strlen($body['output'] ?? '');
        $estTokens = (int) ceil($textLen / 4);
        $this->logApiUsage('deepseek', $body['model'] ?? 'deepseek-chat', '/ai/run:chat_json', [
            'tokens_used' => $estTokens,
            'tokens_in' => (int) ceil(strlen($userPrompt . $system) / 4),
            'tokens_out' => $estTokens,
        ], $body['duration_ms'] ?? 0, $context['workspace_id'] ?? null);

        return $out;
    }

    /**
     * POST /internal/assistant — full intelligent assistant endpoint.
     *
     * ADDED (Patch Assistant, 2026-05-09): wires Laravel into the runtime's
     * /internal/assistant module which provides:
     *   - Workspace context (from WP REST + Redis lu-memory long-term, merged
     *     by lu-context.js::getWorkspaceContext, 15-min cache)
     *   - Conversation history persistence (./conversation, keyed by
     *     conversation_id)
     *   - Tool routing (./assistant-tool-router, 58+ tools)
     *   - Strategic mode (multi-specialist consultation when intent calls for it)
     *
     * Runtime body shape (per index.js:1167-1169):
     *   { message, context={}, conversation_id='default', agent_id='dmm' }
     *
     * Response shape: `{response: "...", ...other fields}`. Some response
     * variants (action-routed) MAY include `create_tasks: [...]` — callers
     * should pass that through to TaskService if present.
     *
     * Returns the parsed JSON body. On failure returns
     * ['response' => null, 'error' => true, 'reason' => ...].
     */
    public function assistant(
        string $message,
        array $context = [],
        string $conversationId = 'default',
        string $agentId = 'dmm',
        int $timeout = 60
    ): array {
        if (! $this->isConfigured()) {
            return ['response' => null, 'error' => true, 'reason' => 'runtime_not_configured'];
        }
        try {
            $resp = Http::withHeaders([
                'X-LevelUp-Secret' => $this->secret,
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
            ])
            ->timeout($timeout)
            ->post($this->baseUrl . '/internal/assistant', [
                'message'         => $message,
                'context'         => $context,
                'conversation_id' => $conversationId,
                'agent_id'        => $agentId,
            ]);

            if (! $resp->successful()) {
                Log::warning('RuntimeClient::assistant non-2xx', [
                    'http_code' => $resp->status(),
                    'body'      => substr((string) $resp->body(), 0, 400),
                ]);
                return ['response' => null, 'error' => true, 'reason' => 'http_' . $resp->status()];
            }

            $body = $resp->json() ?? [];
            // Normalise: ensure callers always see at least a `response` key.
            if (!isset($body['response']) && isset($body['reply']))   $body['response'] = $body['reply'];
            if (!isset($body['response']) && isset($body['content'])) $body['response'] = $body['content'];

            return $body;
        } catch (ConnectionException $e) {
            Log::warning('RuntimeClient::assistant connection failed', ['error' => $e->getMessage()]);
            return ['response' => null, 'error' => true, 'reason' => 'connection_failed'];
        } catch (\Throwable $e) {
            Log::error('RuntimeClient::assistant unexpected error', ['error' => $e->getMessage()]);
            return ['response' => null, 'error' => true, 'reason' => 'exception'];
        }
    }

    /**
     * POST /internal/write/draft — generate a new AI article draft.
     *
     * ADDED 2026-04-12 (Phase 2C-W1 / doc 14): replaces direct DeepSeekConnector
     * use in WriteService::writeArticle(). Runtime has its own type-aware prompt
     * builder (buildWritePrompt) — passes title/brief/keywords/tone/length/context
     * and the runtime side handles the system prompt + LLM call.
     *
     * @return array{success: bool, content?: string, meta?: array, error?: string}
     */
    public function writeDraft(array $params): array
    {
        try {
            // Long-form article generation can run 60-90s — override the default 30s timeout.
            $resp = $this->post('/internal/write/draft', $params, 120);
        } catch (ConnectionException $e) {
            Log::warning('RuntimeClient::writeDraft connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (! $resp->successful() || !($body['success'] ?? false)) {
            Log::warning('RuntimeClient::writeDraft non-2xx or success=false', [
                'http_code' => $resp->status(), 'body' => $body,
            ]);
            return [
                'success' => false,
                'error'   => $body['error'] ?? 'http_' . $resp->status(),
                'raw'     => $body,
            ];
        }

        return [
            'success' => true,
            'content' => $body['content'] ?? '',
            'meta'    => $body['meta'] ?? [],
        ];
    }

    /**
     * POST /internal/write/improve — improve existing content.
     *
     * ADDED 2026-04-12 (Phase 2C-W2 / doc 14): replaces direct DeepSeekConnector
     * use in WriteService::improveDraft().
     *
     * @return array{success: bool, content?: string, meta?: array, error?: string}
     */
    public function writeImprove(string $content, array $params = []): array
    {
        try {
            // Improve passes can also run long on multi-thousand-word inputs.
            $resp = $this->post('/internal/write/improve', array_merge($params, [
                'content' => $content,
            ]), 120);
        } catch (ConnectionException $e) {
            Log::warning('RuntimeClient::writeImprove connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (! $resp->successful() || !($body['success'] ?? false)) {
            Log::warning('RuntimeClient::writeImprove non-2xx or success=false', [
                'http_code' => $resp->status(), 'body' => $body,
            ]);
            return [
                'success' => false,
                'error'   => $body['error'] ?? 'http_' . $resp->status(),
                'raw'     => $body,
            ];
        }

        return [
            'success' => true,
            'content' => $body['content'] ?? '',
            'meta'    => $body['meta'] ?? [],
        ];
    }

    /**
     * POST /internal/image/generate — gpt-image-1 image generation.
     *
     * MIGRATED 2026-05-13 (gpt-image-1): OpenAI deprecated dall-e-3 and
     * dall-e-2 on this account. Runtime now uses gpt-image-1 which only
     * returns b64_json (no hosted URL). This method decodes the base64,
     * writes it to public storage, and returns a public URL to keep every
     * downstream caller (CreativeConnector, generate-article, regenerate-image,
     * Bella, etc.) on the same `url` contract.
     *
     * Supported $options:
     *   - style        (string) folded into the prompt as "Style: ..."
     *   - size         (string) '1024x1024' | '1024x1536' | '1536x1024' | 'auto'
     *                  (legacy dall-e-3 sizes alias on the runtime side)
     *   - workspace_id (int)    namespaces the storage path; falls back to 0
     *
     * Storage layout: storage/app/public/ai-images/{workspace_id}/{md5}.png
     * Public URL via Storage::disk('public')->url() → /storage/ai-images/...
     *
     * CREATIVE888 LAW: runtime hardcodes quality='low' for every auto-gen
     * regardless of what we send. Any `quality` we pass is ignored upstream.
     *
     * @return array{
     *   success: bool,
     *   url?: string,
     *   revised_prompt?: ?string,
     *   size?: string,
     *   quality?: string,
     *   model?: string,
     *   provider?: string,
     *   storage_path?: string,
     *   duration_ms?: int,
     *   error?: string,
     *   raw?: array,
     * }
     */
    public function imageGenerate(string $prompt, array $options = []): array
    {
        try {
            // gpt-image-1 can take 30-60s; allow up to 120s.
            $resp = $this->post('/internal/image/generate', array_filter([
                'prompt' => $prompt,
                'style'  => $options['style'] ?? null,
                'size'   => $options['size']  ?? null,
            ], fn($v) => $v !== null && $v !== ''), 120);
        } catch (ConnectionException $e) {
            Log::warning('RuntimeClient::imageGenerate connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (! $resp->successful() || !($body['success'] ?? false)) {
            Log::warning('RuntimeClient::imageGenerate non-2xx or success=false', [
                'http_code' => $resp->status(), 'body' => $body,
            ]);
            return [
                'success' => false,
                'error'   => $body['error'] ?? 'http_' . $resp->status(),
                'raw'     => $body,
            ];
        }

        // Prefer b64_json (gpt-image-1). Fall back to url (legacy dall-e path,
        // kept for safety if a future env override puts the runtime back on
        // dall-e-3). Either way callers receive a `url`.
        $url          = null;
        $storagePath  = null;

        if (! empty($body['b64_json'])) {
            $bytes = base64_decode($body['b64_json'], true);
            if ($bytes === false || strlen($bytes) === 0) {
                Log::warning('RuntimeClient::imageGenerate base64 decode failed', [
                    'bytes_in' => strlen($body['b64_json']),
                ]);
                return [
                    'success' => false,
                    'error'   => 'base64_decode_failed',
                    'raw'     => $body,
                ];
            }

            // workspace_id sources, in priority order:
            //   1. explicit $options['workspace_id'] (cleanest, when caller knows)
            //   2. X-Workspace-ID request header (connector API path)
            //   3. authenticated user's workspace_id (admin/Bella path)
            //   4. 0 (unscoped — files still namespaced by md5 hash)
            // Steps 2-4 are wrapped in try/catch because RuntimeClient can be
            // invoked outside a request lifecycle (e.g., scheduled commands).
            $wsId = (int) ($options['workspace_id'] ?? 0);
            if ($wsId === 0) {
                try {
                    $hdrWs = (int) request()->header('X-Workspace-ID', 0);
                    if ($hdrWs > 0) {
                        $wsId = $hdrWs;
                    } elseif (auth()->check() && ! empty(auth()->user()->workspace_id)) {
                        $wsId = (int) auth()->user()->workspace_id;
                    }
                } catch (\Throwable) {
                    // No request/auth context (cron, queue worker, etc.) — leave as 0.
                }
            }

            $filename    = md5($prompt . microtime(true) . random_int(0, PHP_INT_MAX)) . '.png';
            $storagePath = 'ai-images/' . $wsId . '/' . $filename;

            try {
                Storage::disk('public')->put($storagePath, $bytes);
                $url = Storage::disk('public')->url($storagePath);
            } catch (\Throwable $e) {
                Log::warning('RuntimeClient::imageGenerate storage put failed', [
                    'path' => $storagePath, 'err' => $e->getMessage(),
                ]);
                return [
                    'success' => false,
                    'error'   => 'storage_write_failed: ' . $e->getMessage(),
                ];
            }
        } elseif (! empty($body['url'])) {
            // Legacy URL path (dall-e-3 / dall-e-2). Still supported defensively.
            $url = $body['url'];
        } else {
            return [
                'success' => false,
                'error'   => 'runtime returned neither b64_json nor url',
                'raw'     => $body,
            ];
        }

        return [
            'success'        => true,
            'url'            => $url,
            'revised_prompt' => $body['revised_prompt'] ?? null,
            'size'           => $body['size'] ?? null,
            'quality'        => $body['quality'] ?? 'low',
            'model'          => $body['model'] ?? 'gpt-image-1',
            'provider'       => $body['provider'] ?? 'openai',
            'storage_path'   => $storagePath,
            'duration_ms'    => $body['duration_ms'] ?? null,
        ];
    }

    /**
     * POST /internal/vision/analyze — GPT-4o vision analysis.
     *
     * ADDED 2026-04-14 (Bella Session 2): sends a base64 image + text prompt
     * to the runtime's GPT-4o vision endpoint. Used by Bella's admin chat for
     * screenshot analysis, design review, and visual QA.
     *
     * @param string $prompt  The analysis instruction (max 4000 chars on runtime side)
     * @param string $image   Base64-encoded image data (PNG/JPG/WEBP, max ~10MB)
     * @param string|null $imageUrl  Optional URL to an image (used instead of base64)
     * @return array{success: bool, analysis?: string, tokens_used?: int, model?: string, duration_ms?: int, error?: string}
     */
    public function visionAnalyze(string $prompt, string $image = '', ?string $imageUrl = null): array
    {
        $payload = ['prompt' => $prompt];
        if ($imageUrl) {
            $payload['image_url'] = $imageUrl;
        } elseif ($image !== '') {
            $payload['image'] = $image;
        } else {
            return ['success' => false, 'error' => 'image (base64) or image_url required'];
        }

        try {
            // Vision can take 30-60s for complex images — 120s timeout.
            $resp = $this->post('/internal/vision/analyze', $payload, 120);
        } catch (ConnectionException $e) {
            Log::warning('RuntimeClient::visionAnalyze connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'connection_failed: ' . $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (! $resp->successful() || !($body['success'] ?? false)) {
            Log::warning('RuntimeClient::visionAnalyze non-2xx or success=false', [
                'http_code' => $resp->status(), 'body_keys' => array_keys($body),
            ]);
            return [
                'success' => false,
                'error'   => $body['error'] ?? 'http_' . $resp->status(),
            ];
        }

        return [
            'success'     => true,
            'analysis'    => $body['analysis'] ?? '',
            'tokens_used' => $body['tokens_used'] ?? 0,
            'provider' => 'openai',
            'model'       => $body['model'] ?? 'gpt-4o',
            'duration_ms' => $body['duration_ms'] ?? null,
        ];
    }

    // ─── internal helpers ────────────────────────────────────────────────

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(
                'RuntimeClient not configured. Set RUNTIME_URL and RUNTIME_SECRET in .env'
            );
        }
    }

    private function normalisePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    // ═══════════════════════════════════════════════════════════���═══
    // API USAGE TRACKING
    // ═══════════════════════════════════════════════════════════════

    private function logApiUsage(string $provider, string $model, string $endpoint, array $response, int $durationMs, ?int $wsId = null, string $status = 'success', ?string $error = null): void
    {
        try {
            $tokensIn = $response['usage']['prompt_tokens'] ?? $response['tokens_in'] ?? 0;
            $tokensOut = $response['usage']['completion_tokens'] ?? $response['tokens_out'] ?? 0;
            $totalTokens = $response['tokens_used'] ?? $response['usage']['total_tokens'] ?? ($tokensIn + $tokensOut);

            DB::table('api_usage_logs')->insert([
                'workspace_id' => $wsId,
                'provider'     => $provider,
                'model'        => $model,
                'endpoint'     => $endpoint,
                'tokens_in'    => $tokensIn,
                'tokens_out'   => $tokensOut,
                'total_tokens' => $totalTokens,
                'cost_usd'     => $this->estimateApiCost($provider, $model, $tokensIn, $tokensOut),
                'duration_ms'  => $durationMs,
                'status'       => $status,
                'error_message'=> $error,
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('[ApiUsage] Log failed: ' . $e->getMessage());
        }
    }

    private function estimateApiCost(string $provider, string $model, int $tokensIn, int $tokensOut): float
    {
        // Pricing per 1M tokens (approximate)
        return match ($provider) {
            'deepseek' => ($tokensIn * 0.14 + $tokensOut * 0.28) / 1_000_000, // DeepSeek V3: $0.14/1M in, $0.28/1M out
            'openai' => match (true) {
                str_contains($model, 'dall-e') => 0.040, // $0.04 per standard image
                str_contains($model, 'gpt-4o') => ($tokensIn * 2.50 + $tokensOut * 10.00) / 1_000_000, // GPT-4o
                default => ($tokensIn * 0.15 + $tokensOut * 0.60) / 1_000_000,
            },
            'minimax' => 0.01, // ~$0.01 per video second (rough estimate)
            'dataforseo' => 0.002, // ~$0.002 per API call
            default => 0.0,
        };
    }

}
