<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BellaController
{
    /**
     * ─────────────────────────────────────────────────────────────────────
     * GD-002 MIGRATION NOTE — `query_database` REMOVED 2026-07-26 (P0-B)
     * ─────────────────────────────────────────────────────────────────────
     * The `query_database` action, its handler `actionQueryDatabase()`, the
     * SQL executor `executeSafeQuery()`, and `getSchemaMap()` were REMOVED by
     * Platform Owner decision GD-002.
     *
     * WHY, precisely:
     *   The action accepted arbitrary SELECT behind a keyword DENYLIST with no
     *   table or column restriction and no tenant scoping, so
     *   `SELECT password, mfa_secret_encrypted FROM users` and
     *   `SELECT key FROM api_keys` were structurally permitted.
     *
     *   It was non-functional only by ACCIDENT: `DB::select(DB::raw($sql))`
     *   raises a TypeError on Laravel 11 ("Argument #1 ($query) must be of
     *   type string, Illuminate\Database\Query\Expression given"), which the
     *   catch block converted into a harmless error string. That is a
     *   coincidence of a framework upgrade, NOT a security control.
     *
     * ⚠️ DO NOT REPAIR. DO NOT REPLACE WITH UNRESTRICTED SQL.
     * ⚠️ DO NOT REINTRODUCE UNDER ANOTHER NAME.
     *   The broken line looks like a trivial bug. Repairing it would arm
     *   unrestricted database reads. The correct action is deletion.
     *
     * Future governed data access occurs through dedicated services and
     * approved runtime tools with explicit table/column allowlists and tenant
     * scoping — see CAPABILITY-REGISTRY.md `data.governed_read`.
     *
     * Bella remains DORMANT and is unchanged in every other respect (GD-001:
     * govern and gate — never retire, never expose).
     * ─────────────────────────────────────────────────────────────────────
     */
    /**
     * Hands-vs-brain (PATCH 4, 2026-05-08): DeepSeekConnector injection
     * removed — `$this->llm` was never actually called in this class.
     * `$this->runtime` has been the active LLM path since Phase 2L.5.
     */
    public function __construct(
        private \App\Connectors\RuntimeClient $runtime,
    ) {}

    /**
     * POST /api/admin/bella
     *
     * Bella — AI admin assistant with full platform visibility,
     * persistent memory, learning, and dynamic database querying.
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message'         => 'required|string|max:2000',
            'history'         => 'nullable|array|max:20',
            'history.*.role'  => 'required_with:history|string|in:user,assistant',
            'history.*.content' => 'required_with:history|string',
            'context_request' => 'nullable|string|max:500',
            'session_id'      => 'nullable|string|max:100',
        ]);

        $message        = $request->input('message');
        $history        = $request->input('history', []);
        $contextRequest = $request->input('context_request');
        $sessionId      = $request->input('session_id', Str::uuid()->toString());
        $adminUser      = $request->user();
        $userId         = $adminUser ? $adminUser->id : null;
        $memoryUpdated  = false;

        // ── Bella Session 3: intercept image generation intent ──────────
        // Detect "generate a image about:", "generate an image of...",
        // "create an image showing...", "make a picture of..." and route
        // through CreativeConnector::execute('generate_image') directly.
        $imagePrompt = $this->extractImagePrompt($message);
        if ($imagePrompt !== null) {
            return $this->handleImageGeneration($imagePrompt, $sessionId, $userId);
        }

        // ── Bella Session 4: intercept document generation intent ────
        $docPrompt = $this->extractDocumentPrompt($message);
        if ($docPrompt !== null) {
            return $this->handleDocumentGeneration($docPrompt, $sessionId, $userId);
        }

        // ── Bella Session 5: intercept presentation generation intent ─
        $pptPrompt = $this->extractPresentationPrompt($message);
        if ($pptPrompt !== null) {
            return $this->handlePresentationGeneration($pptPrompt, $sessionId, $userId);
        }

        // ── Bella Session 6: intercept video generation intent ────────
        $videoPrompt = $this->extractVideoPrompt($message);
        if ($videoPrompt !== null) {
            return $this->handleVideoGeneration($videoPrompt, $sessionId, $userId);
        }

        // Trim history to last 10 turns
        $history = array_slice($history, -10);

        try {
            // ── 1. Load persistent conversation history ──────────────────
            $persistedHistory = $this->loadConversationHistory($userId);

            // ── 2. Load Bella's persistent memory ────────────────────────
            $memoryEntries = $this->loadMemory();

            // ── 3. Gather real-time platform context ─────────────────────
            $context = $this->gatherPlatformContext();

            // ── 4. Schema map REMOVED (GD-002, 2026-07-26) ───────────────
            // The schema map existed solely so the model could author SQL for
            // injecting the database schema into the prompt is now pure
            // information disclosure with no function.
            $schemaMap = '';

            // ── 5. Build system prompt ───────────────────────────────────
            $systemPrompt = $this->buildSystemPrompt($context, $contextRequest, $memoryEntries, $schemaMap);

            // ── 6. Assemble messages array ───────────────────────────────
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];

            // Inject persisted conversation history (older context)
            foreach ($persistedHistory as $turn) {
                $messages[] = [
                    'role'    => $turn->role === 'bella' ? 'assistant' : 'user',
                    'content' => $turn->content,
                ];
            }

            // Inject frontend-provided history (current session context)
            foreach ($history as $turn) {
                $messages[] = [
                    'role'    => $turn['role'],
                    'content' => $turn['content'],
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $message];

            // ── 7. Call runtime ──────────────────────────────────────────
            // MIGRATED 2026-04-13 (Phase 0.17b): switched from aiRun fold-pattern
            // to chatJson. Bella's full system prompt is now passed through
            // properly; conversation history + the user's current message becomes
            // the user prompt as a [ROLE] transcript. The LLM is asked to return
            // {"reply":"<response with optional embedded action_call/memory_save blocks>"}
            // so the existing extractActionBlock + parseAndStoreMemory pipeline
            // continues to operate on the reply text unchanged.
            $sysWithJsonHint = $systemPrompt
                             . "\n\nOutput ONLY a valid JSON object of the form {\"reply\":\"<your full response, including any action_call or memory_save blocks>\"}. "
                             . 'Do NOT wrap action_call/memory_save blocks separately — leave them embedded in the reply string. '
                             . 'No markdown fences around the JSON itself.';

            $transcript = '';
            // Skip the first message because it's the system prompt — already passed via chatJson
            foreach (array_slice($messages, 1) as $msg) {
                $role = strtoupper($msg['role']);
                $transcript .= "[{$role}]\n{$msg['content']}\n\n";
            }

            $result = $this->runtime->chatJson(
                $sysWithJsonHint,
                $transcript,
                ['agent' => 'bella', 'session_id' => $sessionId],
                1500
            );

            if (!($result['success'] ?? false) || !is_array($result['parsed'] ?? null)) {
                Log::error('Bella: runtime call failed', [
                    'error'       => $result['error'] ?? 'unknown',
                    'parse_error' => $result['parse_error'] ?? null,
                ]);
                return response()->json([
                    'reply'           => 'I apologize — I was unable to process your request at the moment. Please try again shortly.',
                    'action_executed' => null,
                    'action_result'   => null,
                    'session_id'      => $sessionId,
                    'memory_updated'  => false,
                    'error'           => $result['error'] ?? 'LLM call failed',
                ], 502);
            }

            $llmContent = $result['parsed']['reply'] ?? $result['text'] ?? '';

            // ── 8. Check for tool/action calls in the response ───────────
            $actionExecuted = null;
            $actionResult   = null;

            $actionBlock = $this->extractActionBlock($llmContent);

            if ($actionBlock) {
                $actionName   = $actionBlock['action'] ?? null;
                $actionParams = $actionBlock['params'] ?? [];

                if ($actionName && in_array($actionName, $this->allowedActions(), true)) {
                    $actionExecuted = $actionName;
                    $actionResult   = $this->executeAction($actionName, $actionParams);

                    // Call DeepSeek again with the action result for a natural summary
                    $messages[] = ['role' => 'assistant', 'content' => $llmContent];
                    $messages[] = [
                        'role'    => 'user',
                        'content' => "Here is the result of the action \"{$actionName}\":\n\n"
                                   . json_encode($actionResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                   . "\n\nPlease provide a clear, natural language summary of this data for the administrator. If you learned any new facts, include a memory_save block.",
                    ];

                    // MIGRATED 2026-04-13 (Phase 0.17b BELLA2): runtime call via chatJson
                    $summaryTranscript = '';
                    foreach (array_slice($messages, 1) as $msg) {
                        $role = strtoupper($msg['role']);
                        $summaryTranscript .= "[{$role}]\n{$msg['content']}\n\n";
                    }

                    $summaryResult = $this->runtime->chatJson(
                        $sysWithJsonHint,
                        $summaryTranscript,
                        ['agent' => 'bella', 'session_id' => $sessionId, 'phase' => 'action_summary'],
                        1500
                    );

                    if (($summaryResult['success'] ?? false) && is_array($summaryResult['parsed'] ?? null)) {
                        $llmContent = $summaryResult['parsed']['reply'] ?? $summaryResult['text'] ?? $llmContent;
                    }
                }
            }

            // ── 9. Parse and store memory_save blocks ────────────────────
            $memoryUpdated = $this->parseAndStoreMemory($llmContent);

            // ── 10. Persist conversation ─────────────────────────────────
            $this->saveConversationTurn($sessionId, $userId, 'user', $message);
            $this->saveConversationTurn($sessionId, $userId, 'bella', $llmContent, $actionExecuted, $actionResult);

            // ── 11. Audit log ────────────────────────────────────────────
            DB::table('audit_logs')->insert([
                'workspace_id'  => null,
                'user_id'       => $userId,
                'action'        => 'bella_chat',
                'entity_type'   => 'bella',
                'entity_id'     => null,
                'metadata_json' => json_encode([
                    'message'         => mb_substr($message, 0, 200),
                    'action_executed' => $actionExecuted,
                    'session_id'      => $sessionId,
                    'memory_updated'  => $memoryUpdated,
                    'tokens_used'     => $result['usage'] ?? [],
                    'ip_address'      => $request->ip(),
                ]),
                'created_at'    => now(),
            ]);

            return response()->json([
                'reply'           => $llmContent,
                'action_executed' => $actionExecuted,
                'action_result'   => $actionResult,
                'session_id'      => $sessionId,
                'memory_updated'  => $memoryUpdated,
            ]);

        } catch (\Throwable $e) {
            Log::error('Bella: Unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'reply'           => 'I encountered an unexpected error. The engineering team has been notified.',
                'action_executed' => null,
                'action_result'   => null,
                'session_id'      => $sessionId,
                'memory_updated'  => false,
                'error'           => $e->getMessage(),
            ], 500);
        }
    }

    // =====================================================================
    //  PERSISTENT CONVERSATION
    // =====================================================================

    private function loadConversationHistory(?int $userId): array
    {
        if (!$userId) {
            return [];
        }

        return DB::table('bella_conversations')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['role', 'content', 'created_at'])
            ->reverse()
            ->values()
            ->toArray();
    }

    private function saveConversationTurn(
        string $sessionId,
        ?int $userId,
        string $role,
        string $content,
        ?string $actionExecuted = null,
        ?array $actionResult = null
    ): void {
        DB::table('bella_conversations')->insert([
            'session_id'      => $sessionId,
            'user_id'         => $userId,
            'role'            => $role,
            'content'         => $content,
            'action_executed' => $actionExecuted,
            'action_result'   => $actionResult ? json_encode($actionResult) : null,
            'created_at'      => now(),
        ]);
    }

    // =====================================================================
    //  PERSISTENT MEMORY
    // =====================================================================

    private function loadMemory(): array
    {
        return DB::table('bella_memory')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->orderBy('category')
            ->orderByDesc('confidence')
            ->get()
            ->toArray();
    }

    private function storeMemory(string $category, string $key, string $value, string $source = 'learned', float $confidence = 1.00): bool
    {
        try {
            DB::table('bella_memory')->updateOrInsert(
                ['category' => $category, 'key' => $key],
                [
                    'value'      => $value,
                    'confidence' => $confidence,
                    'source'     => $source,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            return true;
        } catch (\Throwable $e) {
            Log::warning('Bella: Failed to store memory', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function deleteMemory(string $category, string $key): bool
    {
        return DB::table('bella_memory')
            ->where('category', $category)
            ->where('key', $key)
            ->delete() > 0;
    }

    private function parseAndStoreMemory(string $content): bool
    {
        $updated = false;

        // Look for memory_save blocks: {"memory_save": {"category": "...", "key": "...", "value": "..."}}
        if (preg_match_all('/\{"memory_save"\s*:\s*\{[^}]+\}\}/s', $content, $matches)) {
            foreach ($matches[0] as $block) {
                $decoded = json_decode($block, true);
                if ($decoded && isset($decoded['memory_save'])) {
                    $mem = $decoded['memory_save'];
                    $cat = $mem['category'] ?? 'fact';
                    $key = $mem['key'] ?? null;
                    $val = $mem['value'] ?? null;
                    if ($key && $val) {
                        $stored = $this->storeMemory($cat, $key, $val, 'learned', (float) ($mem['confidence'] ?? 1.00));
                        if ($stored) {
                            $updated = true;
                        }
                    }
                }
            }
        }

        // Also check inside ```json blocks
        if (preg_match_all('/```json\s*(\{.*?\})\s*```/s', $content, $jsonMatches)) {
            foreach ($jsonMatches[1] as $jsonStr) {
                $decoded = json_decode($jsonStr, true);
                if ($decoded && isset($decoded['memory_save'])) {
                    $mem = $decoded['memory_save'];
                    $cat = $mem['category'] ?? 'fact';
                    $key = $mem['key'] ?? null;
                    $val = $mem['value'] ?? null;
                    if ($key && $val) {
                        $stored = $this->storeMemory($cat, $key, $val, 'learned', (float) ($mem['confidence'] ?? 1.00));
                        if ($stored) {
                            $updated = true;
                        }
                    }
                }
            }
        }

        return $updated;
    }

    private function formatMemoryForPrompt(array $memoryEntries): string
    {
        if (empty($memoryEntries)) {
            return "  (no memories stored yet)\n";
        }

        $grouped = [];
        foreach ($memoryEntries as $entry) {
            $entry = (object) $entry;
            $grouped[$entry->category][] = $entry;
        }

        $output = '';
        foreach ($grouped as $category => $entries) {
            $output .= "  [{$category}]\n";
            foreach ($entries as $entry) {
                $entry = (object) $entry;
                $conf = $entry->confidence < 1.0 ? " (confidence: {$entry->confidence})" : '';
                $output .= "    - {$entry->key}: {$entry->value}{$conf}\n";
            }
        }

        return $output;
    }

    // =====================================================================
    //  SCHEMA MAP FOR DYNAMIC QUERYING
    // =====================================================================

    // =====================================================================
    //  PLATFORM CONTEXT GATHERING
    // =====================================================================

    private function gatherPlatformContext(): array
    {
        $ctx = [];

        // Users
        $ctx['users_total']    = DB::table('users')->count();
        $ctx['users_recent']   = DB::table('users')->where('created_at', '>=', now()->subDays(7))->count();

        // Workspaces
        $ctx['workspaces_total'] = DB::table('workspaces')->count();

        // Active sessions
        $ctx['active_sessions'] = DB::table('sessions')
            ->where('updated_at', '>=', now()->subHours(1))
            ->count();

        // Tasks
        $ctx['tasks_pending']   = DB::table('tasks')->where('status', 'pending')->count();
        $ctx['tasks_running']   = DB::table('tasks')->where('status', 'running')->count();
        $ctx['tasks_completed'] = DB::table('tasks')->where('status', 'completed')->count();
        $ctx['tasks_failed']    = DB::table('tasks')->where('status', 'failed')->count();

        // Credits — total balance across platform
        $ctx['credits_total_balance'] = (int) DB::table('credits')->sum('balance');

        // Queue — pending & failed jobs
        $ctx['queue_pending_jobs'] = DB::table('jobs')->count();
        $ctx['queue_failed_jobs']  = DB::table('failed_jobs')->count();

        // Recent audit logs (last 5)
        $ctx['recent_audit_logs'] = DB::table('audit_logs')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['action', 'entity_type', 'user_id', 'created_at'])
            ->toArray();

        // Engine registry
        $ctx['engines_active'] = DB::table('engine_registry')->where('status', 'active')->count();
        $ctx['engines_total']  = DB::table('engine_registry')->count();

        // Subscriptions by plan
        $ctx['subscriptions'] = DB::table('subscriptions')
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->where('subscriptions.status', 'active')
            ->select('plans.name', DB::raw('COUNT(*) as count'))
            ->groupBy('plans.name')
            ->get()
            ->toArray();

        // Recent errors from laravel.log (last 3 ERROR lines)
        $ctx['recent_errors'] = $this->getRecentLogErrors(3);

        return $ctx;
    }

    /**
     * E0.5 — log content is UNTRUSTED DATA.
     *
     * These lines can contain arbitrary strings a customer caused to be logged.
     * They are wrapped and labelled before entering the prompt so the model
     * treats them as evidence to read, never as instructions to follow. This is
     * defence in depth only — `gateAction()` is the actual control, because a
     * prompt instruction is not a security boundary.
     */
    private function getRecentLogErrors(int $limit): array
    {
        $logPath = storage_path('logs/laravel.log');
        if (!file_exists($logPath)) {
            return [];
        }

        $errors = [];
        try {
            $lines = [];
            $fp = fopen($logPath, 'r');
            if ($fp) {
                fseek($fp, 0, SEEK_END);
                $pos = ftell($fp);
                $lineCount = 0;
                $buffer = '';

                while ($pos > 0 && $lineCount < 200) {
                    $pos--;
                    fseek($fp, $pos);
                    $char = fgetc($fp);
                    if ($char === "\n" && $buffer !== '') {
                        $lines[] = $buffer;
                        $buffer = '';
                        $lineCount++;
                    } else {
                        $buffer = $char . $buffer;
                    }
                }
                if ($buffer !== '') {
                    $lines[] = $buffer;
                }
                fclose($fp);
            }

            foreach ($lines as $line) {
                if (str_contains($line, '.ERROR:') && count($errors) < $limit) {
                    $errors[] = mb_substr(trim($line), 0, 200);
                }
            }
        } catch (\Throwable $e) {
            // Non-critical — skip
        }

        return $errors;
    }

    // =====================================================================
    //  SYSTEM PROMPT BUILDER
    // =====================================================================

    private function buildSystemPrompt(array $ctx, ?string $contextRequest, array $memoryEntries, string $schemaMap): string
    {
        $subscriptionsSummary = '';
        if (!empty($ctx['subscriptions'])) {
            foreach ($ctx['subscriptions'] as $sub) {
                $sub = (object) $sub;
                $subscriptionsSummary .= "  - {$sub->name}: {$sub->count} active\n";
            }
        } else {
            $subscriptionsSummary = "  (no active subscriptions)\n";
        }

        $auditSummary = '';
        if (!empty($ctx['recent_audit_logs'])) {
            foreach ($ctx['recent_audit_logs'] as $log) {
                $log = (object) $log;
                $auditSummary .= "  - [{$log->created_at}] {$log->action} on {$log->entity_type} (user #{$log->user_id})\n";
            }
        } else {
            $auditSummary = "  (no recent audit entries)\n";
        }

        $errorSummary = '';
        if (!empty($ctx['recent_errors'])) {
            foreach ($ctx['recent_errors'] as $err) {
                $errorSummary .= "  - {$err}\n";
            }
        } else {
            $errorSummary = "  (no recent errors)\n";
        }

        $memorySummary = $this->formatMemoryForPrompt($memoryEntries);

        $prompt = <<<SYSTEM
You are Bella, the AI administrator assistant for LevelUp Growth Platform. You have full visibility into the operating system. You can read all data, generate reports, interpret analytics, execute admin tasks, and query the database directly. You are speaking with the platform administrator.

Be warm but professional. You are an executive assistant — precise, helpful, proactive. When quoting data, use the exact numbers provided. When generating reports, use formatted markdown.

## Your Capabilities

You have PERSISTENT MEMORY. You remember facts across conversations. When you learn something new about the platform, users, or administrator preferences, save it to memory.

You can QUERY THE DATABASE directly using SQL SELECT statements when you need data not available in your current context. This is especially useful for looking up specific users, payments, subscriptions, or any detailed data.

## Bella's Memory (persistent across conversations)

{$memorySummary}

## Current Platform State (real-time)

**Users:** {$ctx['users_total']} total, {$ctx['users_recent']} new in the last 7 days
**Workspaces:** {$ctx['workspaces_total']} total
**Active Sessions (last hour):** {$ctx['active_sessions']}

**Tasks:**
  - Pending: {$ctx['tasks_pending']}
  - Running: {$ctx['tasks_running']}
  - Completed: {$ctx['tasks_completed']}
  - Failed: {$ctx['tasks_failed']}

**Credits:** {$ctx['credits_total_balance']} total balance across all workspaces

**Queue Health:**
  - Pending jobs: {$ctx['queue_pending_jobs']}
  - Failed jobs: {$ctx['queue_failed_jobs']}

**Engine Registry:** {$ctx['engines_active']} active out of {$ctx['engines_total']} total

**Active Subscriptions:**
{$subscriptionsSummary}
**Recent Audit Log:**
{$auditSummary}
**Recent Errors:**
{$errorSummary}

## Available Actions

You can execute admin actions by including a JSON block in your response like this:
```json
{"action": "action_name", "params": {"key": "value"}}
```

Available actions:
- **list_users** — List users (params: limit, search)
- **get_analytics** — Get platform analytics overview (params: days)
- **get_workspace** — Get workspace detail (params: id)
- **get_queue** — Get queue health stats
- **generate_report** — Generate a platform report (params: type — one of: overview, users, tasks, credits, engines)
- **get_audit_logs** — Get recent audit logs (params: limit)
- **get_engine_status** — Get engine registry status
- **remember** — Store a fact in persistent memory (params: category [fact/preference/insight/note], key, value)
- **forget** — Remove a memory entry (params: category, key)

### Database Querying Guidelines


**Database Schema (all tables and columns):**
{$schemaMap}

### Memory & Learning

After answering any question where you learned a new fact about the platform, a user, or a preference, include a memory_save block in your response:
```json
{"memory_save": {"category": "fact", "key": "descriptive_key", "value": "what you learned"}}
```

Categories: fact, preference, insight, note. This allows you to remember things across conversations.

Only use actions when the administrator's request requires data retrieval or task execution. For general questions, respond directly from the context above.
SYSTEM;

        if ($contextRequest) {
            $prompt .= "\n\n## Additional Context Requested\n{$contextRequest}";
        }

        return $prompt;
    }

    // =====================================================================
    //  ACTION EXECUTION
    // =====================================================================

    /**
     * ─────────────────────────────────────────────────────────────────────
     * E0.5 — CAPABILITIES REMOVED 2026-07-27
     * ─────────────────────────────────────────────────────────────────────
     * `adjust_credits` and `suspend_user` were removed. They mutated billing
     * (`credits.balance` + `credit_transactions`) and user access
     * (`users.status`) with NO approval, NO governance call, NO MFA and NO cap,
     * and were dispatched by regex-scanning model output while customer-
     * influenceable log content sat in the prompt.
     *
     * PermissionRegistry already declared both as CRITICAL / approval-required /
     * MFA-required and machine-may-request-but-never-execute. This controller
     * never called GovernanceService, so that policy was entirely bypassed.
     *
     * Removal is therefore HARD, not policy-based: it does not depend on
     * GOVERNANCE_MODE and holds in shadow, enforce, disabled, missing and
     * malformed configuration alike.
     *
     * ⚠️ DO NOT REINTRODUCE. DO NOT ADD AN ALIAS. DO NOT "FIX" THIS.
     * Legitimate human admin equivalents remain, correctly gated, at
     *   POST /api/admin/workspaces/{id}/credits  (AdminController@adjustCredits)
     *   POST /api/admin/users/{id}/suspend       (AdminController@suspendUser)
     * ─────────────────────────────────────────────────────────────────────
     */
    private const REMOVED_CAPABILITIES = [
        'adjust_credits', 'adjustcredits', 'adjust_credit', 'credit_adjust',
        'add_credits', 'addcredits', 'deduct_credits', 'deductcredits',
        'modify_balance', 'modifybalance', 'set_credits', 'setcredits',
        'update_credits', 'updatecredits', 'grant_credits', 'refund_credits',
        'suspend_user', 'suspenduser', 'suspend_account', 'suspendaccount',
        'disable_user', 'disableuser', 'deactivate_user', 'deactivateuser',
        'ban_user', 'banuser', 'block_user', 'blockuser', 'revoke_user',
    ];

    /**
     * Normalise a model-supplied action name before any comparison.
     *
     * The model is never the authority on whether a capability exists. Casing,
     * whitespace, punctuation and separator variants all collapse to one form
     * so that "Adjust Credits", "adjust-credits" and " ADJUST_CREDITS " cannot
     * evade the check.
     */
    private function normaliseActionName(mixed $action): string
    {
        if (!is_string($action)) {
            return '';
        }

        $n = strtolower(trim($action));
        $n = preg_replace('/[\s\-.]+/', '_', $n);      // spaces, hyphens, dots → _
        $n = preg_replace('/[^a-z0-9_]/', '', $n);      // drop anything else
        $n = preg_replace('/_+/', '_', $n);             // collapse repeats

        return trim((string) $n, '_');
    }

    /**
     * Authoritative backend gate. Runs AFTER model-output interpretation and
     * BEFORE any execution. Fails closed on anything it does not recognise.
     *
     * @return array{ok:bool, action:string, code:?string, message:?string}
     */
    private function gateAction(mixed $rawAction): array
    {
        $action = $this->normaliseActionName($rawAction);

        if ($action === '') {
            return ['ok' => false, 'action' => '', 'code' => 'CAPABILITY_INVALID',
                    'message' => 'No valid capability was requested.'];
        }

        if (in_array($action, self::REMOVED_CAPABILITIES, true)) {
            return ['ok' => false, 'action' => $action, 'code' => 'CAPABILITY_DISABLED',
                    'message' => 'That capability has been permanently disabled and cannot be performed here.'];
        }

        if (!in_array($action, $this->allowedActions(), true)) {
            return ['ok' => false, 'action' => $action, 'code' => 'CAPABILITY_UNKNOWN',
                    'message' => 'That capability is not available.'];
        }

        return ['ok' => true, 'action' => $action, 'code' => null, 'message' => null];
    }

    /** Audit a refused capability without mutating anything. */
    private function auditRefusal(string $action, string $code, ?int $userId, string $sessionId): void
    {
        try {
            DB::table('audit_logs')->insert([
                'workspace_id'  => null,
                'user_id'       => $userId,
                'action'        => 'bella.capability_refused',
                'entity_type'   => 'bella_action',
                'entity_id'     => null,
                'metadata_json' => json_encode([
                    'attempted_capability' => $action,
                    'code'                 => $code,
                    'session_id'           => $sessionId,
                    'phase'                => 'E0.5',
                ]),
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Bella: refusal audit failed', ['error' => $e->getMessage()]);
        }
    }

    private function allowedActions(): array
    {
        return [
            'list_users',
            'get_analytics',
            'get_workspace',
            'get_queue',
            'generate_report',
            'get_audit_logs',
            'get_engine_status',
            'remember',
            'forget',
        ];
    }

    /**
     * E0.5 — extraction is deliberately CONSERVATIVE.
     *
     * The model's text is a suggestion, never an authority. If the response is
     * ambiguous — more than one action block, or a nested action object — we
     * refuse rather than guess, because guessing is how an injected string
     * becomes an executed capability. `gateAction()` is still the final word.
     */
    private function extractActionBlock(string $content): ?array
    {
        // More than one action block in a single response is ambiguous. Refuse.
        if (preg_match_all('/"action"\s*:/', $content) > 1) {
            Log::warning('Bella: multiple action blocks in one response — refused');
            return null;
        }

        // Look for JSON block in ```json ... ``` or raw { ... }
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $decoded = json_decode($matches[1], true);
            if ($decoded && isset($decoded['action']) && is_string($decoded['action'])) {
                return $decoded;
            }
        }

        // Fallback: look for {"action": ...} pattern anywhere
        if (preg_match('/\{"action"\s*:\s*"[^"]+"\s*(?:,\s*"params"\s*:\s*\{[^}]*\})?\s*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['action'])) {
                return $decoded;
            }
        }

        return null;
    }

    private function executeAction(string $action, array $params): array
    {
        // E0.5: the gate is authoritative. Anything not explicitly allowed
        // fails closed here, before any handler is reached.
        $gate = $this->gateAction($action);
        if (!$gate['ok']) {
            return ['error' => $gate['message'], 'code' => $gate['code'], 'executed' => false];
        }
        $action = $gate['action'];

        try {
            return match ($action) {
                'list_users'       => $this->actionListUsers($params),
                'get_analytics'    => $this->actionGetAnalytics($params),
                'get_workspace'    => $this->actionGetWorkspace($params),
                'get_queue'        => $this->actionGetQueue(),
                'generate_report'  => $this->actionGenerateReport($params),
                'get_audit_logs'   => $this->actionGetAuditLogs($params),
                'get_engine_status'=> $this->actionGetEngineStatus(),
                'remember'         => $this->actionRemember($params),
                'forget'           => $this->actionForget($params),
                default            => ['error' => "Unknown action: {$action}"],
            };
        } catch (\Throwable $e) {
            Log::error("Bella: Action '{$action}' failed", ['error' => $e->getMessage()]);
            return ['error' => "Action failed: {$e->getMessage()}"];
        }
    }

    // ── New Action Handlers ──────────────────────────────────────────────

    private function actionRemember(array $params): array
    {
        $category = $params['category'] ?? 'fact';
        $key      = $params['key'] ?? null;
        $value    = $params['value'] ?? null;

        if (!$key || !$value) {
            return ['error' => 'key and value are required'];
        }

        if (!in_array($category, ['fact', 'preference', 'insight', 'note'], true)) {
            return ['error' => 'Invalid category. Use: fact, preference, insight, note'];
        }

        $stored = $this->storeMemory($category, $key, $value, 'conversation');
        return $stored
            ? ['success' => true, 'message' => "Remembered: [{$category}] {$key}"]
            : ['error' => 'Failed to store memory'];
    }

    private function actionForget(array $params): array
    {
        $category = $params['category'] ?? '';
        $key      = $params['key'] ?? '';

        if (!$category || !$key) {
            return ['error' => 'category and key are required'];
        }

        $deleted = $this->deleteMemory($category, $key);
        return $deleted
            ? ['success' => true, 'message' => "Forgot: [{$category}] {$key}"]
            : ['error' => "Memory entry [{$category}] {$key} not found"];
    }

    // ── Original Action Handlers (unchanged) ─────────────────────────────

    private function actionListUsers(array $params): array
    {
        $limit  = min((int) ($params['limit'] ?? 20), 50);
        $search = $params['search'] ?? null;

        $query = DB::table('users')->select('id', 'name', 'email', 'is_platform_admin', 'created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('created_at')->limit($limit)->get();

        return [
            'total_count' => DB::table('users')->count(),
            'returned'    => $users->count(),
            'users'       => $users->toArray(),
        ];
    }

    private function actionGetAnalytics(array $params): array
    {
        $days  = min((int) ($params['days'] ?? 30), 90);
        $since = now()->subDays($days)->startOfDay();

        $userGrowth = DB::table('users')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $since)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        $tasksByEngine = DB::table('tasks')
            ->select('engine', DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $since)
            ->groupBy('engine')
            ->orderByDesc('count')
            ->get();

        $creditConsumption = DB::table('credit_transactions')
            ->where('created_at', '>=', $since)
            ->whereIn('type', ['debit', 'commit'])
            ->sum(DB::raw('ABS(amount)'));

        return [
            'period_days'        => $days,
            'user_growth'        => $userGrowth->toArray(),
            'tasks_by_engine'    => $tasksByEngine->toArray(),
            'credit_consumption' => (float) $creditConsumption,
            'total_users'        => DB::table('users')->count(),
            'total_workspaces'   => DB::table('workspaces')->count(),
        ];
    }

    private function actionGetWorkspace(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if (!$id) {
            return ['error' => 'Workspace ID is required'];
        }

        $workspace = DB::table('workspaces')->where('id', $id)->first();
        if (!$workspace) {
            return ['error' => "Workspace #{$id} not found"];
        }

        $owner   = DB::table('users')->where('id', $workspace->created_by)->first(['id', 'name', 'email']);
        $credits = DB::table('credits')->where('workspace_id', $id)->first();
        $tasks   = DB::table('tasks')
            ->where('workspace_id', $id)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            )
            ->first();

        return [
            'workspace' => $workspace,
            'owner'     => $owner,
            'credits'   => $credits,
            'tasks'     => $tasks,
        ];
    }


    private function actionGetQueue(): array
    {
        return [
            'pending_jobs'     => DB::table('jobs')->count(),
            'failed_jobs'      => DB::table('failed_jobs')->count(),
            'failed_recent'    => DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(5)
                ->get(['id', 'queue', 'payload', 'failed_at'])
                ->map(function ($job) {
                    $payload = json_decode($job->payload, true);
                    return [
                        'id'        => $job->id,
                        'queue'     => $job->queue,
                        'job_class' => $payload['displayName'] ?? 'unknown',
                        'failed_at' => $job->failed_at,
                    ];
                })
                ->toArray(),
        ];
    }

    private function actionGenerateReport(array $params): array
    {
        $type = $params['type'] ?? 'overview';

        return match ($type) {
            'users' => [
                'report_type'   => 'users',
                'total'         => DB::table('users')->count(),
                'admins'        => DB::table('users')->where('is_platform_admin', true)->count(),
                'new_7d'        => DB::table('users')->where('created_at', '>=', now()->subDays(7))->count(),
                'new_30d'       => DB::table('users')->where('created_at', '>=', now()->subDays(30))->count(),
                'with_workspaces' => DB::table('workspace_users')->distinct('user_id')->count('user_id'),
            ],
            'tasks' => [
                'report_type' => 'tasks',
                'total'       => DB::table('tasks')->count(),
                'pending'     => DB::table('tasks')->where('status', 'pending')->count(),
                'running'     => DB::table('tasks')->where('status', 'running')->count(),
                'completed'   => DB::table('tasks')->where('status', 'completed')->count(),
                'failed'      => DB::table('tasks')->where('status', 'failed')->count(),
                'by_engine'   => DB::table('tasks')
                    ->select('engine', DB::raw('COUNT(*) as count'))
                    ->groupBy('engine')
                    ->orderByDesc('count')
                    ->get()
                    ->toArray(),
            ],
            'credits' => [
                'report_type'     => 'credits',
                'total_balance'   => (int) DB::table('credits')->sum('balance'),
                'workspaces_with_credits' => DB::table('credits')->where('balance', '>', 0)->count(),
                'total_transactions_30d'  => DB::table('credit_transactions')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
                'total_debited_30d' => (float) DB::table('credit_transactions')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->whereIn('type', ['debit', 'commit'])
                    ->sum(DB::raw('ABS(amount)')),
                'total_credited_30d' => (float) DB::table('credit_transactions')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->where('type', 'credit')
                    ->sum('amount'),
            ],
            'engines' => [
                'report_type'  => 'engines',
                'total'        => DB::table('engine_registry')->count(),
                'active'       => DB::table('engine_registry')->where('status', 'active')->count(),
                'inactive'     => DB::table('engine_registry')->where('status', '!=', 'active')->count(),
                'registry'     => DB::table('engine_registry')
                    ->select('slug', 'name', 'status', 'version')
                    ->orderBy('name')
                    ->get()
                    ->toArray(),
            ],
            default => [
                'report_type'     => 'overview',
                'generated_at'    => now()->toISOString(),
                'users_total'     => DB::table('users')->count(),
                'users_new_7d'    => DB::table('users')->where('created_at', '>=', now()->subDays(7))->count(),
                'workspaces'      => DB::table('workspaces')->count(),
                'tasks_total'     => DB::table('tasks')->count(),
                'tasks_completed' => DB::table('tasks')->where('status', 'completed')->count(),
                'tasks_failed'    => DB::table('tasks')->where('status', 'failed')->count(),
                'credits_balance' => (int) DB::table('credits')->sum('balance'),
                'queue_pending'   => DB::table('jobs')->count(),
                'queue_failed'    => DB::table('failed_jobs')->count(),
                'active_subscriptions' => DB::table('subscriptions')->where('status', 'active')->count(),
                'engines_active'  => DB::table('engine_registry')->where('status', 'active')->count(),
            ],
        };
    }

    private function actionGetAuditLogs(array $params): array
    {
        $limit = min((int) ($params['limit'] ?? 20), 100);

        $logs = DB::table('audit_logs')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'count' => $logs->count(),
            'logs'  => $logs->toArray(),
        ];
    }

    private function actionGetEngineStatus(): array
    {
        $engines = DB::table('engine_registry')
            ->select('slug', 'name', 'status', 'version', 'updated_at')
            ->orderBy('name')
            ->get();

        return [
            'total'   => $engines->count(),
            'active'  => $engines->where('status', 'active')->count(),
            'engines' => $engines->toArray(),
        ];
    }


    // ═══════════════════════════════════════════════════════════════════════
    // BELLA SESSION 3 — Image generation intent detection + execution
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Extract image generation prompt from natural language variants.
     * Returns the topic string if matched, null otherwise.
     *
     * Matched patterns:
     *   - "Generate a image about: ..."
     *   - "Generate an image about: ..."
     *   - "Generate an image of ..."
     *   - "Create an image showing ..."
     *   - "Create an image of ..."
     *   - "Make a picture of ..."
     *   - "Make an image of ..."
     *   - "Draw ..." / "Draw me ..."
     */
    private function extractImagePrompt(string $message): ?string
    {
        $patterns = [
            '/^generate\s+(?:a|an)\s+image\s+(?:about|of|showing|for|depicting)\s*:?\s*(.+)/i',
            '/^create\s+(?:a|an)\s+image\s+(?:about|of|showing|for|depicting)\s*:?\s*(.+)/i',
            '/^make\s+(?:a|an)\s+(?:image|picture)\s+(?:about|of|showing|for|depicting)\s*:?\s*(.+)/i',
            '/^draw\s+(?:me\s+)?(?:a|an)?\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($message), $m)) {
                $topic = trim($m[1]);
                if ($topic !== '') return $topic;
            }
        }

        return null;
    }

    /**
     * Generate an image via CreativeConnector (DALL-E 3) and return a
     * special response shape that the Bella widget renders as an inline image.
     */
    private function handleImageGeneration(string $prompt, string $sessionId, ?int $userId): JsonResponse
    {
        try {
            // Use CreativeService (not CreativeConnector) so the image is
            // persisted to the assets table + CIMS memory. The connector only
            // generates; the service handles the full lifecycle.
            $svc = app(\App\Engines\Creative\Services\CreativeService::class);
            $result = $svc->generateImage(1, [  // workspace_id=1 for admin
                'prompt' => $prompt,
            ]);

            $url     = $result['url'] ?? null;
            $assetId = $result['asset_id'] ?? null;
            $status  = $result['status'] ?? 'unknown';

            if ($url && $status === 'completed') {
                $reply = "Here's the image I generated for \"{$prompt}\":";

                // Persist conversation turns
                $this->saveConversationTurn($sessionId, $userId, 'user', "Generate an image of: {$prompt}");
                $this->saveConversationTurn($sessionId, $userId, 'bella', $reply);

                return response()->json([
                    'reply'           => $reply,
                    'type'            => 'image',
                    'image_url'       => $url,
                    'asset_id'        => $assetId,
                    'prompt'          => $prompt,
                    'action_executed' => 'generate_image',
                    'action_result'   => $result,
                    'session_id'      => $sessionId,
                    'memory_updated'  => false,
                ]);
            }

            // Generation failed
            $error = $result['error'] ?? 'Image generation failed (status: ' . $status . ')';
            return response()->json([
                'reply'           => "I tried to generate an image for \"{$prompt}\" but it didn't work: {$error}",
                'action_executed' => 'generate_image',
                'action_result'   => null,
                'session_id'      => $sessionId,
                'memory_updated'  => false,
                'error'           => $error,
            ]);

        } catch (\Throwable $e) {
            Log::error('Bella image generation failed', ['prompt' => $prompt, 'error' => $e->getMessage()]);
            return response()->json([
                'reply'           => "Sorry, I couldn't generate that image right now. Error: {$e->getMessage()}",
                'action_executed' => null,
                'action_result'   => null,
                'session_id'      => $sessionId,
                'memory_updated'  => false,
                'error'           => $e->getMessage(),
            ], 502);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // BELLA SESSION 4 — Document generation (DOCX + PDF)
    // ═══════════════════════════════════════════════════════════════════════

    private function extractDocumentPrompt(string $message): ?string
    {
        $patterns = [
            '/^generate\s+(?:a\s+)?document\s+(?:about|on|for)\s*:?\s*(.+)/i',
            '/^create\s+(?:a\s+)?(?:report|document|doc)\s+(?:about|on|for)\s*:?\s*(.+)/i',
            '/^write\s+(?:a\s+)?(?:report|document|doc)\s+(?:about|on|for)\s*:?\s*(.+)/i',
            '/^make\s+(?:a\s+)?(?:word\s+doc|document|report|pdf)\s+(?:about|on|for)\s*:?\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($message), $m)) {
                $topic = trim($m[1]);
                if ($topic !== '') return $topic;
            }
        }

        return null;
    }

    private function handleDocumentGeneration(string $topic, string $sessionId, ?int $userId): JsonResponse
    {
        try {
            // Step 1: LLM generates structured document content
            $runtime = app(\App\Connectors\RuntimeClient::class);
            $systemPrompt = 'You are a professional document writer. Generate a structured business document. '
                . 'Return ONLY valid JSON with this shape: '
                . '{"title":"<document title>","executive_summary":"<2-3 sentences>","sections":[{"heading":"<section heading>","body":"<2-4 paragraphs of detailed content>"}],"conclusion":"<closing paragraph>"}. '
                . 'Generate 4-6 sections. Make the content substantive, professional, and actionable. No markdown fences.';

            $result = $runtime->chatJson($systemPrompt, "Write a comprehensive document about: {$topic}", [], 3000);

            if (!($result['success'] ?? false) || !is_array($result['parsed'] ?? null)) {
                return response()->json([
                    'reply' => "I couldn't generate the document content. " . ($result['error'] ?? $result['parse_error'] ?? 'LLM failed.'),
                    'action_executed' => 'generate_document',
                    'session_id' => $sessionId,
                    'memory_updated' => false,
                ]);
            }

            $doc = $result['parsed'];
            $title    = $doc['title'] ?? $topic;
            $summary  = $doc['executive_summary'] ?? '';
            $sections = $doc['sections'] ?? [];
            $conclusion = $doc['conclusion'] ?? '';

            // Step 2: Generate DOCX
            $slug = \Illuminate\Support\Str::slug($title);
            $ts   = now()->format('Ymd-His');
            $baseName = "{$slug}-{$ts}";
            $docxPath = "bella-docs/{$baseName}.docx";
            $pdfPath  = "bella-docs/{$baseName}.pdf";

            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $phpWord->getDefaultFontName('Calibri');
            $phpWord->getDefaultFontSize(11);

            // Title page section
            $sec = $phpWord->addSection();
            $sec->addText($title, ['size' => 24, 'bold' => true, 'color' => '6C5CE7']);
            $sec->addTextBreak(1);
            $sec->addText('Generated by Bella — LevelUp Growth Platform', ['size' => 10, 'color' => '888888', 'italic' => true]);
            $sec->addText(now()->format('F j, Y'), ['size' => 10, 'color' => '888888']);
            $sec->addTextBreak(2);

            if ($summary) {
                $sec->addText('Executive Summary', ['size' => 16, 'bold' => true, 'color' => '333333']);
                $sec->addTextBreak(1);
                $sec->addText($summary, ['size' => 11]);
                $sec->addTextBreak(2);
            }

            foreach ($sections as $s) {
                $sec->addText($s['heading'] ?? 'Section', ['size' => 14, 'bold' => true, 'color' => '333333']);
                $sec->addTextBreak(1);
                foreach (explode("\n\n", $s['body'] ?? '') as $para) {
                    $para = trim($para);
                    if ($para !== '') {
                        $sec->addText($para, ['size' => 11]);
                        $sec->addTextBreak(1);
                    }
                }
                $sec->addTextBreak(1);
            }

            if ($conclusion) {
                $sec->addText('Conclusion', ['size' => 14, 'bold' => true, 'color' => '333333']);
                $sec->addTextBreak(1);
                $sec->addText($conclusion, ['size' => 11]);
            }

            $docxFull = storage_path("app/public/{$docxPath}");
            $phpWord->save($docxFull, 'Word2007');

            // Step 3: Generate PDF from same content using DomPDF
            $html = '<html><head><meta charset="UTF-8"><style>'
                . 'body{font-family:Helvetica,Arial,sans-serif;font-size:11pt;color:#222;line-height:1.6;margin:40px}'
                . 'h1{color:#6C5CE7;font-size:22pt;margin-bottom:4px}'
                . '.meta{color:#888;font-size:9pt;margin-bottom:24px}'
                . 'h2{color:#333;font-size:14pt;margin-top:20px;border-bottom:1px solid #ddd;padding-bottom:4px}'
                . 'h3{color:#333;font-size:12pt;margin-top:16px}'
                . 'p{margin:8px 0}'
                . '</style></head><body>';
            $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
            $html .= '<div class="meta">Generated by Bella — LevelUp Growth Platform &middot; ' . now()->format('F j, Y') . '</div>';

            if ($summary) {
                $html .= '<h2>Executive Summary</h2><p>' . htmlspecialchars($summary) . '</p>';
            }

            foreach ($sections as $s) {
                $html .= '<h2>' . htmlspecialchars($s['heading'] ?? 'Section') . '</h2>';
                foreach (explode("\n\n", $s['body'] ?? '') as $para) {
                    $para = trim($para);
                    if ($para !== '') $html .= '<p>' . htmlspecialchars($para) . '</p>';
                }
            }

            if ($conclusion) {
                $html .= '<h2>Conclusion</h2><p>' . htmlspecialchars($conclusion) . '</p>';
            }
            $html .= '</body></html>';

            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $pdfFull = storage_path("app/public/{$pdfPath}");
            file_put_contents($pdfFull, $dompdf->output());

            // Step 4: Persist to assets table
            $docxUrl = url("storage/{$docxPath}");
            $pdfUrl  = url("storage/{$pdfPath}");

            $docxAssetId = DB::table('assets')->insertGetId([
                'workspace_id' => 1,
                'type' => 'document',
                'title' => $title . ' (DOCX)',
                'prompt' => $topic,
                'provider' => 'LevelUp AI',
                'model' => 'bella-docgen',
                'status' => 'completed',
                'url' => $docxUrl,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'file_size' => filesize($docxFull),
                'metadata_json' => json_encode(['format' => 'docx', 'sections' => count($sections)]),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $pdfAssetId = DB::table('assets')->insertGetId([
                'workspace_id' => 1,
                'type' => 'document',
                'title' => $title . ' (PDF)',
                'prompt' => $topic,
                'provider' => 'LevelUp AI',
                'model' => 'bella-docgen',
                'status' => 'completed',
                'url' => $pdfUrl,
                'mime_type' => 'application/pdf',
                'file_size' => filesize($pdfFull),
                'metadata_json' => json_encode(['format' => 'pdf', 'sections' => count($sections)]),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $reply = "I've generated your document: **{$title}**";

            $this->saveConversationTurn($sessionId, $userId, 'user', "Generate a document about: {$topic}");
            $this->saveConversationTurn($sessionId, $userId, 'bella', $reply);

            return response()->json([
                'reply'           => $reply,
                'type'            => 'document',
                'title'           => $title,
                'docx_url'        => $docxUrl,
                'pdf_url'         => $pdfUrl,
                'docx_asset_id'   => $docxAssetId,
                'pdf_asset_id'    => $pdfAssetId,
                'sections'        => count($sections),
                'action_executed' => 'generate_document',
                'session_id'      => $sessionId,
                'memory_updated'  => false,
            ]);

        } catch (\Throwable $e) {
            Log::error('Bella document generation failed', ['topic' => $topic, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'reply'           => "Sorry, I couldn't generate that document. Error: {$e->getMessage()}",
                'action_executed' => null,
                'session_id'      => $sessionId,
                'memory_updated'  => false,
                'error'           => $e->getMessage(),
            ], 502);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // BELLA SESSION 5 — Presentation generation (PPTX + PDF)
    // ═══════════════════════════════════════════════════════════════════════

    private function extractPresentationPrompt(string $message): ?string
    {
        $patterns = [
            '/^generate\s+(?:a\s+)?presentation\s+(?:about|on|for)\s*:?\s*(.+)/i',
            '/^create\s+(?:a\s+)?(?:presentation|deck|slides|ppt)\s+(?:about|on|for)\s*:?\s*(.+)/i',
            '/^make\s+(?:a\s+)?(?:powerpoint|presentation|deck|ppt)\s+(?:about|on|for)\s*:?\s*(.+)/i',
            '/^build\s+(?:a\s+)?(?:presentation|deck|slides)\s+(?:about|on|for)\s*:?\s*(.+)/i',
            '/^create\s+slides\s+(?:about|on|for)\s*:?\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($message), $m)) {
                $topic = trim($m[1]);
                if ($topic !== '') return $topic;
            }
        }

        return null;
    }

    private function handlePresentationGeneration(string $topic, string $sessionId, ?int $userId): JsonResponse
    {
        try {
            // Step 1: LLM generates structured slide content
            $runtime = app(\App\Connectors\RuntimeClient::class);
            $systemPrompt = 'You are a professional presentation designer. Generate slide content for a business presentation. '
                . 'Return ONLY valid JSON with this shape: '
                . '{"title":"<presentation title>","subtitle":"<one-line subtitle>","slides":[{"slide_number":1,"heading":"<slide heading>","bullet_points":["<point 1>","<point 2>","<point 3>"],"speaker_notes":"<2-3 sentences of speaker notes>"}]}. '
                . 'Generate 6-8 slides. First slide is opening/agenda, last is summary/call-to-action. '
                . 'Each slide has 3-4 concise bullet points. Be specific and actionable. No markdown fences.';

            $result = $runtime->chatJson($systemPrompt, "Create a presentation about: {$topic}", [], 2500);

            if (!($result['success'] ?? false) || !is_array($result['parsed'] ?? null)) {
                return response()->json([
                    'reply' => "I couldn't generate the presentation content. " . ($result['error'] ?? $result['parse_error'] ?? 'LLM failed.'),
                    'action_executed' => 'generate_presentation',
                    'session_id' => $sessionId,
                    'memory_updated' => false,
                ]);
            }

            $pres = $result['parsed'];
            $title    = $pres['title'] ?? $topic;
            $subtitle = $pres['subtitle'] ?? '';
            $slides   = $pres['slides'] ?? [];

            // Step 2: Generate PPTX with PhpPresentation
            $slug = \Illuminate\Support\Str::slug(substr($title, 0, 60));
            $ts   = now()->format('Ymd-His');
            $baseName = "{$slug}-{$ts}";
            $pptxPath = "bella-presentations/{$baseName}.pptx";
            $pdfPath  = "bella-presentations/{$baseName}.pdf";

            $php = new \PhpOffice\PhpPresentation\PhpPresentation();
            // Remove the default first slide — we'll build our own
            $php->removeSlideByIndex(0);

            $brandPurple = new \PhpOffice\PhpPresentation\Style\Color('FF7C3AED');
            $white       = new \PhpOffice\PhpPresentation\Style\Color('FFFFFFFF');
            $darkText    = new \PhpOffice\PhpPresentation\Style\Color('FF222222');
            $mutedText   = new \PhpOffice\PhpPresentation\Style\Color('FF666666');

            // Title slide
            $titleSlide = $php->createSlide();
            $titleSlide->setBackground((new \PhpOffice\PhpPresentation\Slide\Background\Color())->setColor($brandPurple));
            $titleShape = $titleSlide->createRichTextShape()->setOffsetX(60)->setOffsetY(180)->setWidth(840)->setHeight(200);
            $titleRun = $titleShape->createTextRun($title);
            $titleRun->getFont()->setSize(36)->setBold(true)->setColor($white)->setName('Calibri');
            if ($subtitle) {
                $subShape = $titleSlide->createRichTextShape()->setOffsetX(60)->setOffsetY(380)->setWidth(840)->setHeight(80);
                $subRun = $subShape->createTextRun($subtitle);
                $subRun->getFont()->setSize(18)->setColor($white)->setName('Calibri');
            }
            $footShape = $titleSlide->createRichTextShape()->setOffsetX(60)->setOffsetY(500)->setWidth(840)->setHeight(40);
            $footRun = $footShape->createTextRun('Generated by Bella — LevelUp Growth Platform • ' . now()->format('F j, Y'));
            $footRun->getFont()->setSize(10)->setColor($white)->setName('Calibri')->setItalic(true);

            // Content slides
            foreach ($slides as $s) {
                $slide = $php->createSlide();

                // Heading
                $headShape = $slide->createRichTextShape()->setOffsetX(50)->setOffsetY(30)->setWidth(860)->setHeight(60);
                $headRun = $headShape->createTextRun($s['heading'] ?? 'Slide ' . ($s['slide_number'] ?? ''));
                $headRun->getFont()->setSize(24)->setBold(true)->setColor($brandPurple)->setName('Calibri');

                // Purple accent line
                $line = $slide->createLineShape(50, 95, 250, 95);
                $line->getBorder()->setColor($brandPurple)->setLineWidth(3);

                // Bullet points
                $bullets = $s['bullet_points'] ?? [];
                $bodyShape = $slide->createRichTextShape()->setOffsetX(60)->setOffsetY(110)->setWidth(840)->setHeight(370);
                foreach ($bullets as $i => $point) {
                    if ($i > 0) $bodyShape->createBreak();
                    $para = $bodyShape->createParagraph();
                    $para->getAlignment()->setMarginLeft(20);
                    $bulletRun = $para->createTextRun('• ' . $point);
                    $bulletRun->getFont()->setSize(16)->setColor($darkText)->setName('Calibri');
                }

                // Speaker notes
                if (!empty($s['speaker_notes'])) {
                    $note = $slide->getNote();
                    $noteShape = $note->createRichTextShape();
                    $noteShape->createTextRun($s['speaker_notes']);
                }
            }

            // Save PPTX
            $pptxFull = storage_path("app/public/{$pptxPath}");
            $writer = \PhpOffice\PhpPresentation\IOFactory::createWriter($php, 'PowerPoint2007');
            $writer->save($pptxFull);

            // Generate PDF from same content using DomPDF (slide-per-page layout)
            $html = '<html><head><meta charset="UTF-8"><style>'
                . '@page{size:landscape;margin:40px}'
                . 'body{font-family:Helvetica,Arial,sans-serif;color:#222}'
                . '.slide{page-break-after:always;padding:30px;min-height:440px;position:relative}'
                . '.slide:last-child{page-break-after:auto}'
                . '.title-slide{background:#7C3AED;color:#fff;padding:60px;display:flex;flex-direction:column;justify-content:center;min-height:500px}'
                . '.title-slide h1{font-size:32pt;margin-bottom:8px}'
                . '.title-slide .sub{font-size:16pt;opacity:.85;margin-bottom:24px}'
                . '.title-slide .foot{font-size:9pt;opacity:.6;position:absolute;bottom:30px}'
                . 'h2{color:#7C3AED;font-size:20pt;border-bottom:3px solid #7C3AED;padding-bottom:6px;margin-bottom:16px}'
                . 'ul{list-style:none;padding:0}li{font-size:14pt;padding:6px 0;padding-left:20px;position:relative}'
                . 'li:before{content:"•";color:#7C3AED;position:absolute;left:0;font-weight:bold}'
                . '</style></head><body>';

            $html .= '<div class="slide title-slide">'
                . '<h1>' . htmlspecialchars($title) . '</h1>'
                . ($subtitle ? '<div class="sub">' . htmlspecialchars($subtitle) . '</div>' : '')
                . '<div class="foot">Generated by Bella — LevelUp Growth Platform • ' . now()->format('F j, Y') . '</div>'
                . '</div>';

            foreach ($slides as $s) {
                $html .= '<div class="slide">';
                $html .= '<h2>' . htmlspecialchars($s['heading'] ?? '') . '</h2>';
                $html .= '<ul>';
                foreach ($s['bullet_points'] ?? [] as $point) {
                    $html .= '<li>' . htmlspecialchars($point) . '</li>';
                }
                $html .= '</ul></div>';
            }
            $html .= '</body></html>';

            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            $pdfFull = storage_path("app/public/{$pdfPath}");
            file_put_contents($pdfFull, $dompdf->output());

            // Persist to assets table
            $pptxUrl = url("storage/{$pptxPath}");
            $pdfUrl  = url("storage/{$pdfPath}");

            $pptxAssetId = DB::table('assets')->insertGetId([
                'workspace_id' => 1,
                'type'         => 'presentation',
                'title'        => $title . ' (PPTX)',
                'prompt'       => $topic,
                'provider'     => 'LevelUp AI',
                'model'        => 'bella-pptgen',
                'status'       => 'completed',
                'url'          => $pptxUrl,
                'mime_type'    => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'file_size'    => filesize($pptxFull),
                'metadata_json' => json_encode(['format' => 'pptx', 'slides' => count($slides)]),
                'created_at'   => now(), 'updated_at' => now(),
            ]);

            $pdfAssetId = DB::table('assets')->insertGetId([
                'workspace_id' => 1,
                'type'         => 'presentation',
                'title'        => $title . ' (PDF)',
                'prompt'       => $topic,
                'provider'     => 'LevelUp AI',
                'model'        => 'bella-pptgen',
                'status'       => 'completed',
                'url'          => $pdfUrl,
                'mime_type'    => 'application/pdf',
                'file_size'    => filesize($pdfFull),
                'metadata_json' => json_encode(['format' => 'pdf', 'slides' => count($slides)]),
                'created_at'   => now(), 'updated_at' => now(),
            ]);

            $reply = "I've generated your presentation: **{$title}** ({$this->count($slides)} slides)";

            $this->saveConversationTurn($sessionId, $userId, 'user', "Create a presentation about: {$topic}");
            $this->saveConversationTurn($sessionId, $userId, 'bella', $reply);

            return response()->json([
                'reply'           => $reply,
                'type'            => 'presentation',
                'title'           => $title,
                'pptx_url'        => $pptxUrl,
                'pdf_url'         => $pdfUrl,
                'pptx_asset_id'   => $pptxAssetId,
                'pdf_asset_id'    => $pdfAssetId,
                'slide_count'     => count($slides),
                'action_executed' => 'generate_presentation',
                'session_id'      => $sessionId,
                'memory_updated'  => false,
            ]);

        } catch (\Throwable $e) {
            Log::error('Bella presentation generation failed', ['topic' => $topic, 'error' => $e->getMessage()]);
            return response()->json([
                'reply'           => "Sorry, I couldn't generate that presentation. Error: {$e->getMessage()}",
                'action_executed' => null,
                'session_id'      => $sessionId,
                'memory_updated'  => false,
                'error'           => $e->getMessage(),
            ], 502);
        }
    }

    private function count(array $a): int { return count($a); }

    // ═══════════════════════════════════════════════════════════════════════
    // BELLA SESSION 6 — Video generation (Hailuo → Runway → Mock waterfall)
    // ═══════════════════════════════════════════════════════════════════════

    private function extractVideoPrompt(string $message): ?string
    {
        $patterns = [
            '/^generate\s+(?:a\s+)?video\s+(?:about|of|showing|on|for)\s*:?\s*(.+)/i',
            '/^create\s+(?:a\s+)?video\s+(?:about|of|showing|on|for)\s*:?\s*(.+)/i',
            '/^make\s+(?:a\s+)?video\s+(?:about|of|showing|on|for)\s*:?\s*(.+)/i',
            '/^produce\s+(?:a\s+)?video\s+(?:about|of|showing|on|for)\s*:?\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($message), $m)) {
                $topic = trim($m[1]);
                if ($topic !== '') return $topic;
            }
        }

        return null;
    }

    private function handleVideoGeneration(string $topic, string $sessionId, ?int $userId): JsonResponse
    {
        try {
            // Use CreativeService (full lifecycle: scene planning → dispatch → persist)
            $svc = app(\App\Engines\Creative\Services\CreativeService::class);
            $result = $svc->generateVideo(1, [
                'prompt'   => $topic,
                'duration' => 10,
            ]);

            $assetId = $result['asset_id'] ?? null;
            $status  = $result['status'] ?? 'unknown';
            $scenes  = $result['scene_count'] ?? 0;

            if (! $assetId) {
                return response()->json([
                    'reply'           => "I couldn't start the video generation. No asset was created.",
                    'action_executed' => 'generate_video',
                    'session_id'      => $sessionId,
                    'memory_updated'  => false,
                ]);
            }

            // Synchronous poll — wait up to 30s for the mock/fast provider
            // to complete. Real providers (Hailuo/Runway) will still be
            // in_progress and we return the pending status with the poll endpoint.
            $videoUrl = null;
            $finalStatus = $status;

            for ($i = 0; $i < 6; $i++) {
                if ($i > 0) usleep(5_000_000); // 5s between polls

                $pollResult = $svc->pollVideoJob($assetId);
                $finalStatus = $pollResult['status'] ?? $finalStatus;

                if ($finalStatus === 'completed') {
                    $videoUrl = $pollResult['url'] ?? $pollResult['video_url'] ?? null;
                    // Also check assets table directly
                    if (! $videoUrl) {
                        $row = DB::table('assets')->where('id', $assetId)->first();
                        $videoUrl = $row->url ?? null;
                    }
                    break;
                }
                if (in_array($finalStatus, ['failed', 'timed_out'])) break;
            }

            $reply = $videoUrl
                ? "Here's your video for \"{$topic}\":"
                : ($finalStatus === 'in_progress'
                    ? "Your video is being generated. It will appear in Artifacts when ready. (Asset #{$assetId})"
                    : "Video generation completed but no URL was returned. The provider may have used a mock path. (Status: {$finalStatus})");

            $this->saveConversationTurn($sessionId, $userId, 'user', "Generate a video about: {$topic}");
            $this->saveConversationTurn($sessionId, $userId, 'bella', $reply);

            return response()->json([
                'reply'           => $reply,
                'type'            => 'video',
                'video_url'       => $videoUrl,
                'asset_id'        => $assetId,
                'status'          => $finalStatus,
                'scene_count'     => $scenes,
                'prompt'          => $topic,
                'action_executed' => 'generate_video',
                'session_id'      => $sessionId,
                'memory_updated'  => false,
            ]);

        } catch (\Throwable $e) {
            Log::error('Bella video generation failed', ['topic' => $topic, 'error' => $e->getMessage()]);
            return response()->json([
                'reply'           => "Sorry, I couldn't generate that video. Error: {$e->getMessage()}",
                'action_executed' => null,
                'session_id'      => $sessionId,
                'memory_updated'  => false,
                'error'           => $e->getMessage(),
            ], 502);
        }
    }
}
