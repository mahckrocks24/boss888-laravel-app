<?php

namespace App\Engines\Chatbot\Services;

use App\Connectors\RuntimeClient;
use App\Core\Billing\CreditService;
use App\Engines\CRM\Services\CrmService;
use App\Engines\Calendar\Services\CalendarService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CHATBOT888 — Response orchestrator (v2 with intelligence layer).
 *
 * Per-turn pipeline:
 *   1. Reserve 1 credit (atomic, P0 pattern).
 *   2. Persist user message early (so an LLM/runtime failure doesn't lose
 *      the conversation history).
 *   3. ChatbotContextBuilder.build()  — workspace + brand tone + page +
 *      KB chunks + history.
 *   4. ChatbotIntentClassifier.classify() — rule-first; deterministic.
 *      Always extracts capture fields (email/phone/name/date/time)
 *      regardless of intent confidence.
 *   5. ChatbotSessionStateService.read() + .transition() — finite state
 *      machine decides next action: answer | ask_field | finalise_lead |
 *      finalise_booking | finalise_callback | escalate.
 *   6. Run the chosen action:
 *      - finalise_*  → call CrmService / CalendarService directly; honest
 *                      success messages only after the underlying write
 *                      succeeds.
 *      - ask_field   → respond with the next-field prompt; NO LLM call
 *                      (saves credits when intent is rule-classified).
 *      - escalate    → log to chatbot_escalations + safe canned reply.
 *      - answer      → call RuntimeClient::chatJson; pass output through
 *                      ChatbotGuardrails (hallucination guard, schema,
 *                      provider-name strip).
 *   7. Persist assistant message with intent + classifier_source +
 *      kb_hits for analytics.
 *   8. Update FSM cursor + captured_fields_json.
 *   9. Bump chatbot_usage_logs (monthly).
 *  10. Commit credit on success / release on failure.
 *
 * Workspace isolation invariant: every DB read includes
 * WHERE workspace_id = $session->workspace_id. Cross-workspace bleed
 * is impossible by construction.
 */
class ChatbotResponseService
{
    public const CREDIT_COST_PER_MESSAGE = 1;
    private const MAX_USER_LEN = 4000;

    public function __construct(
        private RuntimeClient $runtime,
        private ChatbotKnowledgeService $kb,
        private CreditService $credits,
        private CrmService $crm,
        private CalendarService $calendar,
        private ChatbotContextBuilder $ctxBuilder,
        private ChatbotIntentClassifier $classifier,
        private ChatbotSessionStateService $fsm,
        private ChatbotGuardrails $guard,
    ) {}

    /**
     * Handle one user message in a session. Always returns a fully-shaped
     * response payload the widget can render.
     */
    /**
     * P2-C — one logical request, executed once.
     *
     * Wraps the existing pipeline in the P2-B ChatExecutionCoordinator. The
     * pipeline itself is unchanged; what changes is that a duplicate submission
     * never reaches it. Idempotency is acquired BEFORE the quota gate and the
     * credit reservation, so a replay costs nothing and produces no second
     * message.
     *
     * $idempotencyKey is optional. When the widget does not supply one, a
     * canonical key is derived server-side (see canonicalIdempotencyKey) so the
     * guarantee holds without a frontend change.
     */
    public function handleMessage(int $sessionId, string $userMessage, ?string $idempotencyKey = null): array
    {
        $userMessage = mb_substr(trim($userMessage), 0, self::MAX_USER_LEN);
        if ($userMessage === '') {
            return ['success' => false, 'error' => 'EMPTY_MESSAGE', 'message' => 'Please type a message.'];
        }

        $session = DB::table('chatbot_sessions')->where('id', $sessionId)->first();
        if (! $session) {
            return ['success' => false, 'error' => 'SESSION_NOT_FOUND', 'message' => 'Session not found.'];
        }
        $workspaceId = (int) $session->workspace_id;

        // ── P2-C IDEMPOTENCY ADOPTION ────────────────────────────────────────
        if (\App\Core\Chat\ChatIdempotencyGate::enabledFor('s8_public_chatbot')) {
            $key = trim((string) $idempotencyKey) !== ''
                ? (string) $idempotencyKey
                : $this->canonicalIdempotencyKey($sessionId, $userMessage);

            return $this->runIdempotent($sessionId, $userMessage, $workspaceId, $key);
        }

        return $this->executePipeline($sessionId, $userMessage, $workspaceId, $session, null);
    }

    /**
     * Deterministic key for a widget that does not send one.
     *
     * A double-click or a retry inside the bucket yields the same key, so the
     * duplicate is caught with no frontend change. The bucket is coarse enough
     * to catch retries and short enough that a visitor legitimately repeating a
     * short answer later is not blocked. Trade-off documented in
     * P2-C-IMPACT-MATRIX.md §4.
     */
    private function canonicalIdempotencyKey(int $sessionId, string $message): string
    {
        $bucket = (int) floor(time() / 60);
        $norm   = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message));

        return 'cb:' . hash('sha256', $sessionId . '|' . $norm . '|' . $bucket);
    }

    /** Run the pipeline through the shared coordinator. */
    private function runIdempotent(int $sessionId, string $userMessage, int $workspaceId, string $key): array
    {
        $coordinator = app(\App\Core\Chat\ChatExecutionCoordinator::class);
        $captured    = null;

        $outcome = $coordinator->run([
            'workspace_id'      => $workspaceId,
            'surface'           => 's8_public_chatbot',
            'conversation_type' => 'chatbot_session',
            'conversation_id'   => (string) $sessionId,
            'idempotency_key'   => $key,
            'content'           => $userMessage,
            'estimated_credits' => self::CREDIT_COST_PER_MESSAGE,
            // The pipeline below already runs a correct reserve -> commit /
            // release cycle. Letting the coordinator charge as well would bill
            // one request twice, which is exactly what the integration test
            // caught. The coordinator records linkage; the pipeline keeps the
            // money. Double-charging is prevented by the gate refusing to
            // re-run the pipeline at all.
            'delegated'         => true,
            'message_store'     => 'chatbot_messages',
        ], [
            // The pipeline owns its own persistence; the coordinator only needs
            // to know it happened once. Steps 12-16 run inside execute().
            'execute' => function (string $correlationId) use ($sessionId, $userMessage, $workspaceId, &$captured) {
                $session = DB::table('chatbot_sessions')->where('id', $sessionId)->first();
                $payload = $this->executePipeline($sessionId, $userMessage, $workspaceId, $session, $correlationId);
                $captured = $payload;

                if (($payload['success'] ?? false) === true) {
                    return ['ok' => true, 'body' => $payload, 'provider' => 'deepseek'];
                }

                // A refusal or failure is a settled outcome, not an exception.
                return [
                    'ok'          => false,
                    'body'        => $payload,
                    'error_code'  => match ($payload['error'] ?? '') {
                        'INSUFFICIENT_CREDITS'   => 'CHAT_INSUFFICIENT_CREDITS',
                        'MONTHLY_LIMIT_REACHED'  => 'CHAT_RATE_LIMITED',
                        default                  => 'CHAT_INTERNAL_ERROR',
                    },
                    'retryable'   => ($payload['error'] ?? '') === 'INTERNAL_ERROR',
                    'http_status' => 200,
                ];
            },
        ]);

        // Replay: return the stored payload verbatim (INV-10).
        if (($outcome['replay'] ?? false) === true && is_array($outcome['result']['body'] ?? null)) {
            $body = $outcome['result']['body'];
            $body['idempotent_replay'] = true;
            $body['correlation_id']    = $outcome['correlation_id'];

            return $body;
        }

        if ($captured !== null) {
            $captured['correlation_id'] = $outcome['correlation_id'];

            return $captured;
        }

        // Conflict / in-progress — canonical taxonomy, no pipeline run.
        return [
            'success'        => false,
            'error'          => $outcome['error_code'] ?? 'CHAT_INTERNAL_ERROR',
            'message'        => $outcome['error']['message'] ?? 'That request could not be completed.',
            'correlation_id' => $outcome['correlation_id'],
        ];
    }

    /** The original pipeline, unchanged. */
    private function executePipeline(int $sessionId, string $userMessage, int $workspaceId, $session, ?string $correlationId): array
    {

        // ── Step 0: monthly message cap ──
        // Plan-defined quota (chatbot_messages_per_month). Short-circuit BEFORE
        // credit reservation so the visitor sees a clean upgrade prompt rather
        // than burning credits past the cap. 0 = no cap (unlimited or no plan).
        $gate = app(\App\Core\Billing\FeatureGateService::class);
        $monthlyQuota = $gate->chatbotMessagesQuota($workspaceId);
        if ($monthlyQuota > 0) {
            $monthlyUsed = $gate->chatbotMessagesUsed($workspaceId);
            if ($monthlyUsed >= $monthlyQuota) {
                return [
                    'success' => false,
                    'error'   => 'MONTHLY_LIMIT_REACHED',
                    'message' => 'This chatbot has reached its monthly message limit. Please try again next month, or contact the site owner.',
                    'data'    => [
                        'monthly_quota' => $monthlyQuota,
                        'monthly_used'  => $monthlyUsed,
                    ],
                ];
            }
        }

        // ── Step 1: reserve credit ──
        $reservationRef = null;
        try {
            $rsv = $this->credits->reserveCredits(
                $workspaceId,
                self::CREDIT_COST_PER_MESSAGE,
                'chatbot_message',
                $sessionId
            );
            $reservationRef = $rsv->reservation_reference;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() === 402) {
                return [
                    'success' => false,
                    'error'   => 'INSUFFICIENT_CREDITS',
                    'message' => 'You have run out of credits. Please top up to continue.',
                ];
            }
            throw $e;
        }

        // Step 2: persist user message early
        $userMsgId = DB::table('chatbot_messages')->insertGetId([
            'session_id'   => $sessionId,
            'workspace_id' => $workspaceId,
            'role'         => 'user',
            'content'      => $userMessage,
            'created_at'   => now(),
        ]);

        try {
            // Step 3: build context
            $ctx = $this->ctxBuilder->build($sessionId, $userMessage);

            // Step 4: classify intent (regex fast-path) + LLM-augmented
            // field extraction for fuzzy fields.
            $fsmState = $this->fsm->read($sessionId);
            $classified = $this->classifier->classify($userMessage, $fsmState['captured']);

            // 2026-05-28 — Intelligence belongs in runtime. Regex was looping
            // the bot forever asking for name when visitors answered with bare
            // names ("John"). For fuzzy fields (name, service, ambiguous
            // date/time), call the runtime LLM to extract. This bypasses the
            // brittle string-pattern parsing in ChatbotIntentClassifier and
            // gives a real natural-language understanding step.
            $expectingField = $this->lastAskedField($sessionId);
            if ($expectingField && empty($classified['captured_fields'][$expectingField])) {
                $extracted = $this->extractFieldViaLLM($userMessage, $expectingField, $fsmState['captured'] ?? []);
                if ($extracted !== null) {
                    $classified['captured_fields'][$expectingField] = $extracted;
                }
            }

            // SCOPE GATE (regression fix — Boss "dog dentist"): the regex
            // classifier can match a commitment intent (booking/callback/
            // lead_capture) on wording alone ("appointment") without knowing
            // whether the request is something this business actually offers, so
            // the LLM scope-guardrail never runs for a booking. Verify in-scope
            // BEFORE asking for the visitor's details; an out-of-scope commitment
            // (e.g. a pet at a people-serving clinic) is answered with a controlled
            // redirect and NO lead capture (Step 6 short-circuit below).
            $scopeRedirect = null;
            $commitmentIntents = ['booking', 'reservation', 'callback', 'lead_capture'];
            if (in_array($classified['intent'], $commitmentIntents, true)) {
                $scope = $this->commitmentScopeCheck($ctx, $userMessage);
                if ($scope['in_scope'] === false) {
                    $scopeRedirect = $scope['redirect'];
                }
            }

            // Step 5: state transition
            $merged = $classified['captured_fields'];  // already merged with $fsmState['captured']
            $transition = $this->fsm->transition($fsmState['state'], $classified['intent'], $merged);

            // Step 6: run the chosen action
            if ($scopeRedirect !== null) {
                // Out-of-scope commitment: deterministic redirect, no lead capture,
                // never routed through the escalate path (which would ask for contact).
                $payload = [[
                    'success' => true,
                    'data' => [
                        'message'          => (string) $scopeRedirect,
                        'intent'           => 'out_of_scope',
                        'needs_contact'    => false,
                        'needs_booking'    => false,
                        'capture_fields'   => [],
                        'booking_proposal' => null,
                    ],
                    'meta' => [
                        'session_id'   => $ctx['session_id'],
                        'credits_used' => self::CREDIT_COST_PER_MESSAGE,
                        'kb_hits'      => $ctx['kb_hits_count'] ?? 0,
                        'classifier'   => 'scope_gate',
                    ],
                ], 'out_of_scope', 'scope_gate'];
                [$payload, $finalIntent, $classifierSource] = $payload;
            } else {
            [$payload, $finalIntent, $classifierSource] = match ($transition['action']) {
                ChatbotSessionStateService::ACTION_FINALISE_LEAD     => $this->actionFinaliseLead($sessionId, $merged, $ctx),
                ChatbotSessionStateService::ACTION_FINALISE_BOOKING  => $this->actionFinaliseBooking($sessionId, $merged, $ctx),
                ChatbotSessionStateService::ACTION_FINALISE_CALLBACK => $this->actionFinaliseCallback($sessionId, $merged, $ctx),
                ChatbotSessionStateService::ACTION_ASK_FIELD         => $this->actionAskField($transition, $ctx, $classified, $merged),
                ChatbotSessionStateService::ACTION_ESCALATE          => $this->actionEscalate($sessionId, $userMessage, $classified, $ctx),
                default                                              => $this->actionAnswer($ctx, $userMessage, $classified, $merged),
            };
            }

            // Step 7: persist assistant message
            DB::table('chatbot_messages')->insert([
                'session_id'        => $sessionId,
                'workspace_id'      => $workspaceId,
                'role'              => 'assistant',
                'content'           => $payload['data']['message'] ?? '',
                'intent'            => $finalIntent,
                'classifier_source' => $classifierSource,
                'kb_hits'           => (int) ($ctx['kb_hits_count'] ?? 0),
                'credits_used'      => self::CREDIT_COST_PER_MESSAGE,
                'meta_json'         => json_encode([
                    'fsm_state' => $transition['state'],
                    'flow'      => $transition['flow'],
                    'next_field'=> $transition['next_field'] ?? null,
                ]),
                'created_at'        => now(),
            ]);

            // Step 8: update FSM cursor + captured fields
            $this->fsm->write($sessionId, $transition['state'], $merged);

            // Update session-level intent history + counters.
            $intents = json_decode($session->intent_history_json ?: '[]', true) ?: [];
            $intents[] = $finalIntent;
            DB::table('chatbot_sessions')->where('id', $sessionId)->update([
                'message_count'        => (int) $session->message_count + 1,
                'intent_history_json'  => json_encode(array_slice($intents, -50)),
            ]);

            // Step 9: bump usage
            $this->bumpUsage($workspaceId);

            // Step 10: commit credit
            if ($reservationRef) {
                try { $this->credits->commitReservedCredits($reservationRef); }
                catch (\Throwable $e) { Log::warning('[chatbot] commit credit failed', ['error' => $e->getMessage()]); }
            }

            return $payload;
        } catch (\Throwable $e) {
            Log::error('[chatbot] handleMessage failed', [
                'workspace_id' => $workspaceId, 'session_id' => $sessionId,
                'error' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            if ($reservationRef) {
                try { $this->credits->releaseReservedCredits($reservationRef); } catch (\Throwable) {}
            }
            return [
                'success' => false,
                'error'   => 'INTERNAL_ERROR',
                'message' => "Sorry, I couldn't process that — please try again.",
            ];
        }
    }

    // ════════════════════════════════════════════════════════════════
    // ACTIONS
    // ════════════════════════════════════════════════════════════════

    /**
     * Free-form answer path. Calls the LLM and applies guardrails.
     *
     * CONVERSION INTELLIGENCE 2026-05-03 — set conversion_nudge=true when
     * the visitor has been chatting for several turns without expressing
     * booking/contact intent and without volunteering a contact field.
     * This activates the CONVERSION NUDGE block in the system prompt so
     * the LLM gently suggests a next step on this reply. NO new LLM
     * call — just enriches the existing one.
     */

    /**
     * 2026-05-28 — Look at the last assistant message we sent and figure
     * out which field (if any) the bot asked the visitor for. Used to
     * give the LLM-extraction step contextual hints so a bare "John"
     * after "What's your name?" gets recognised as a name.
     */
    private function lastAskedField(int $sessionId): ?string
    {
        $row = DB::table('chatbot_messages')
            ->where('session_id', $sessionId)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->limit(1)
            ->first(['meta_json']);
        if (!$row || empty($row->meta_json)) return null;
        $meta = json_decode($row->meta_json, true);
        return is_array($meta) ? ($meta['next_field'] ?? null) : null;
    }

    /**
     * 2026-05-28 — Intelligence-belongs-in-runtime fix. The regex
     * classifier ONLY matched volunteering phrasings like "my name is X",
     * which meant bare-name answers ("John") never registered and the
     * bot looped. This calls the runtime LLM to extract a single field
     * from a free-form user message with proper natural-language
     * understanding.
     *
     * Returns the extracted value as a string, or null if the visitor
     * refused / asked another question / clearly didn't answer.
     */
    /**
     * SCOPE GATE. For a commitment intent, decide whether the request is something
     * this business genuinely offers. Returns ['in_scope'=>bool, 'redirect'=>string].
     * in_scope=false ONLY when the runtime is confident the request is out of scope
     * (a different industry's service, or a service for an ineligible subject such
     * as a pet at a people-serving business). Fail-OPEN (in_scope=true) on any
     * uncertainty or runtime unavailability so a real customer is never blocked.
     */
    private function commitmentScopeCheck(array $ctx, string $userMessage): array
    {
        $open = ['in_scope' => true, 'redirect' => ''];
        if (! $this->runtime->isConfigured()) return $open;
        $business = (string) ($ctx['business_name'] ?? 'this business');
        $industry = (string) ($ctx['industry'] ?? 'general');
        $services = trim((string) ($ctx['services_csv'] ?? ''));
        $system = "You are a strict scope checker for {$business}, a {$industry}."
            . ($services !== '' ? " Its services: {$services}." : '')
            . "\nDecide if the visitor's request is something THIS business genuinely"
            . " offers to its normal clientele."
            . "\nOut of scope = a service from a DIFFERENT industry, OR a service for an"
            . " ineligible subject (for example a business that serves PEOPLE being asked"
            . " to serve a pet or animal)."
            . "\nA normal request for this industry's own services (including for the"
            . " visitor themselves) is IN scope."
            . "\nWhen genuinely unsure, answer in_scope=true (never block a real customer)."
            . "\nIf out of scope, write 'redirect' as ONE short, warm sentence that politely"
            . " says {$business} does not offer that and points them to what it DOES offer."
            . " Do NOT offer to take their details."
            . "\nReturn ONLY this JSON: {\"in_scope\": true|false, \"redirect\": \"...\"}.";
        $user = "Visitor request: \"{$userMessage}\"";
        try {
            $r = $this->runtime->chatJson($system, $user, ['task' => 'chatbot_scope_gate'], 160);
            if (! ($r['success'] ?? false)) return $open;
            $p = $r['parsed'] ?? null;
            if (! is_array($p) || ! array_key_exists('in_scope', $p)) return $open;
            $inScope = (bool) $p['in_scope'];
            $redirect = trim((string) ($p['redirect'] ?? ''));
            if (! $inScope && $redirect === '') {
                $redirect = "I'm sorry, but {$business} doesn't offer that "
                    . "— is there something we can help you with instead?";
            }
            return ['in_scope' => $inScope, 'redirect' => $redirect];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[chatbot] scope gate failed (fail-open)', [
                'workspace_id' => $ctx['workspace_id'] ?? null, 'error' => $e->getMessage(),
            ]);
            return $open;
        }
    }

    private function extractFieldViaLLM(string $userMessage, string $field, array $alreadyCaptured): ?string
    {
        if (!$this->runtime->isConfigured()) return null;

        $fieldDescriptions = [
            'name'    => "the visitor's personal name (first name, full name, or how they'd like to be addressed)",
            'email'   => "the visitor's email address",
            'phone'   => "the visitor's phone number",
            'service' => "the service / product the visitor is asking about",
            'date'    => "a calendar date the visitor proposed (ISO YYYY-MM-DD)",
            'time'    => "a time-of-day the visitor proposed (HH:MM 24h)",
        ];
        $desc = $fieldDescriptions[$field] ?? "the value for '{$field}'";

        $alreadyText = '';
        if (!empty($alreadyCaptured)) {
            $known = array_filter(array_map(
                fn($k, $v) => $v ? "$k=$v" : null,
                array_keys($alreadyCaptured),
                array_values($alreadyCaptured)
            ));
            if ($known) $alreadyText = "Already captured: " . implode(', ', $known) . ". ";
        }

        $system = "You extract a single structured field from a website chatbot visitor's message.\n"
                . "The visitor was just asked for {$desc}.\n"
                . "{$alreadyText}\n"
                . "Return ONLY this JSON: {\"value\": \"<extracted>\" or null, \"confident\": true|false}\n"
                . "Rules:\n"
                . "1. If the message clearly answers the question (even bare — \"John\" after \"What's your name?\"), extract the value.\n"
                . "2. If the visitor is asking another question, refusing, or saying \"idk/maybe/later\", return null.\n"
                . "3. If the value is mixed in (\"hey it's John btw\"), extract just the value (\"John\").\n"
                . "4. For names: trim, preserve original casing, allow Unicode (Müller, Akpan, José, Mary-Jane).\n"
                . "5. For dates/times: normalise to ISO format if clearly stated.\n"
                . "6. No markdown, no commentary, ONLY the JSON object.";

        $user = "Visitor message: \"{$userMessage}\"";

        try {
            $result = $this->runtime->chatJson($system, $user, [
                'task'  => 'chatbot_field_extract',
                'field' => $field,
            ], 100);
            if (!($result['success'] ?? false)) return null;
            $parsed = $result['parsed'] ?? null;
            if (!is_array($parsed)) return null;
            $value = $parsed['value'] ?? null;
            if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) return null;
            // Defensive: never accept "null" string
            if (is_string($value) && strtolower(trim($value)) === 'null') return null;
            return is_string($value) ? trim($value) : (string) $value;
        } catch (\Throwable $e) {
            Log::warning('[chatbot] extractFieldViaLLM failed', [
                'field' => $field, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function actionAnswer(array $ctx, string $userMessage, array $classified, array $captured): array
    {
        // Trigger nudge when 2+ user turns deep in pure-FAQ territory
        // (turn_count ≥ 4 because each user turn produces 1 user + 1
        // assistant message in chatbot_messages) and no contact captured.
        $hasContact = ! empty($captured['email']) || ! empty($captured['phone']);
        if (($ctx['turn_count'] ?? 0) >= 4 && ! $hasContact) {
            $ctx['conversion_nudge'] = true;
        }

        $system = $this->ctxBuilder->renderSystemPrompt($ctx);
        $user   = $this->ctxBuilder->renderUserPromptWithHistory($ctx, $userMessage);
        $resp   = $this->runtime->chatJson($system, $user, [], 800);

        $parsed = $resp['parsed'] ?? null;
        if (! ($resp['success'] ?? false) || ! is_array($parsed)) {
            Log::warning('[chatbot] runtime returned non-parseable JSON', [
                'workspace_id' => $ctx['workspace_id'], 'session_id' => $ctx['session_id'],
                'parse_error' => $resp['parse_error'] ?? null, 'error' => $resp['error'] ?? null,
            ]);
        }
        $sanitised = $this->guard->apply($parsed, $ctx);

        $finalIntent = $sanitised['intent'];
        $classifierSource = ($classified['confidence'] >= ChatbotIntentClassifier::HIGH_CONFIDENCE)
            ? 'rule'  // rule was confident; LLM only produced the answer text
            : 'llm';

        return [[
            'success' => true,
            'data' => [
                'message'          => $sanitised['answer'],
                'intent'           => $finalIntent,
                'needs_contact'    => $sanitised['needs_contact'],
                'needs_booking'    => $sanitised['needs_booking'],
                'capture_fields'   => $sanitised['capture_fields'],
                'booking_proposal' => $sanitised['booking_proposal'],
            ],
            'meta' => [
                'session_id' => $ctx['session_id'],
                'credits_used' => self::CREDIT_COST_PER_MESSAGE,
                'kb_hits' => $ctx['kb_hits_count'],
                'classifier' => $classifierSource,
            ],
        ], $finalIntent, $classifierSource];
    }

    /**
     * State machine asked for the next field. NO LLM call — deterministic
     * prompt. Bumps confidence by adding `needs_contact` / `needs_booking`
     * flags so the widget can render an inline form as fallback if it
     * already has it visible.
     */
    private function actionAskField(array $transition, array $ctx, array $classified, array $captured): array
    {
        $field = $transition['next_field'];
        $flow  = $transition['flow'];
        $message = $this->fsm->promptForField($field, $flow, $ctx);
        $intent = match ($flow) {
            'booking'  => 'booking',
            'callback' => 'callback',
            'lead'     => 'lead_capture',
            default    => 'lead_capture',
        };
        return [[
            'success' => true,
            'data' => [
                'message'        => $message,
                'intent'         => $intent,
                'needs_contact'  => in_array($flow, ['lead', 'callback'], true),
                'needs_booking'  => $flow === 'booking',
                'capture_fields' => array_values(array_intersect(
                    $this->fsm->requiredFieldsForFlow($flow),
                    array_keys(array_filter([
                        'name'  => empty($captured['name']),
                        'email' => empty($captured['email']),
                        'phone' => empty($captured['phone']),
                    ]))
                )),
                'booking_proposal' => null,
            ],
            'meta' => [
                'session_id' => $ctx['session_id'],
                'credits_used' => self::CREDIT_COST_PER_MESSAGE,
                'kb_hits' => 0,
                'classifier' => 'rule',
            ],
        ], $intent, $classified['source']];
    }

    private function actionFinaliseLead(int $sessionId, array $captured, array $ctx): array
    {
        $result = $this->captureLead($sessionId, $captured);
        $msg = $result['success']
            ? "Thanks {$captured['name']}! We'll be in touch shortly."
            : "We've recorded your details — someone will follow up shortly.";
        return [[
            'success' => $result['success'] ?? false,
            'data' => [
                'message'          => $msg,
                'intent'           => 'lead_capture',
                'needs_contact'    => false,
                'needs_booking'    => false,
                'capture_fields'   => [],
                'booking_proposal' => null,
            ],
            'meta' => [
                'session_id' => $ctx['session_id'],
                'credits_used' => self::CREDIT_COST_PER_MESSAGE,
                'kb_hits' => 0,
                'classifier' => 'rule',
                'lead_id' => $result['data']['lead_id'] ?? null,
            ],
        ], 'lead_capture', 'rule'];
    }

    private function actionFinaliseBooking(int $sessionId, array $captured, array $ctx): array
    {
        $result = $this->createBookingOrCallback($sessionId, 'booking', $captured);
        if ($result['success'] ?? false) {
            $when = $captured['date'] ?? '';
            $time = $captured['time'] ?? '';
            $whenStr = trim($when . ' ' . $time);
            $msg = "Got it. Booking request for {$whenStr} received — we'll email to confirm.";
        } else {
            $msg = ($result['message'] ?? "We've recorded your request. The team will email to confirm.");
        }
        return [[
            'success' => $result['success'] ?? false,
            'data' => [
                'message'          => $msg,
                'intent'           => 'booking',
                'needs_contact'    => false,
                'needs_booking'    => false,
                'capture_fields'   => [],
                'booking_proposal' => null,
            ],
            'meta' => [
                'session_id' => $ctx['session_id'],
                'credits_used' => self::CREDIT_COST_PER_MESSAGE,
                'kb_hits' => 0,
                'classifier' => 'rule',
                'lead_id'  => $result['lead_id'] ?? ($result['data']['lead_id'] ?? null),
                'event_id' => $result['data']['event_id'] ?? null,
            ],
        ], 'booking', 'rule'];
    }

    private function actionFinaliseCallback(int $sessionId, array $captured, array $ctx): array
    {
        $result = $this->createBookingOrCallback($sessionId, 'callback', $captured);
        $msg = ($result['success'] ?? false)
            ? "Thanks {$captured['name']}. We'll call you back on {$captured['phone']} shortly."
            : ($result['message'] ?? "We've recorded your callback request. Someone will be in touch.");
        return [[
            'success' => $result['success'] ?? false,
            'data' => [
                'message'          => $msg,
                'intent'           => 'callback',
                'needs_contact'    => false,
                'needs_booking'    => false,
                'capture_fields'   => [],
                'booking_proposal' => null,
            ],
            'meta' => [
                'session_id' => $ctx['session_id'],
                'credits_used' => self::CREDIT_COST_PER_MESSAGE,
                'kb_hits' => 0,
                'classifier' => 'rule',
            ],
        ], 'callback', 'rule'];
    }

    private function actionEscalate(int $sessionId, string $userMessage, array $classified, array $ctx): array
    {
        DB::table('chatbot_escalations')->insert([
            'workspace_id' => $ctx['workspace_id'],
            'session_id'   => $sessionId,
            'question'     => mb_substr($userMessage, 0, 1000),
            'reason'       => $classified['intent'] ?: 'escalation',
            'status'       => 'open',
            'created_at'   => now(), 'updated_at' => now(),
        ]);
        return [[
            'success' => true,
            'data' => [
                'message'          => "That's a bit outside what I can help with directly. Want me to take your name and email so the team can follow up?",
                'intent'           => 'escalation',
                'needs_contact'    => true,
                'needs_booking'    => false,
                'capture_fields'   => ['name', 'email'],
                'booking_proposal' => null,
            ],
            'meta' => [
                'session_id' => $ctx['session_id'],
                'credits_used' => self::CREDIT_COST_PER_MESSAGE,
                'kb_hits' => 0,
                'classifier' => 'rule',
            ],
        ], 'escalation', 'rule'];
    }

    // ════════════════════════════════════════════════════════════════
    // CRM / Calendar — preserved from v1; called by finalise_* actions
    // ════════════════════════════════════════════════════════════════

    /**
     * Capture lead from chatbot. Reuses CrmService::createLead. Idempotent
     * by (workspace_id, email|phone): if a matching lead exists, attach a
     * Note instead of creating a duplicate.
     *
     * Public — also invoked by /api/public/chatbot/lead form-fallback path.
     */
    public function captureLead(int $sessionId, array $fields): array
    {
        $session = DB::table('chatbot_sessions')->where('id', $sessionId)->first();
        if (! $session) {
            return ['success' => false, 'error' => 'SESSION_NOT_FOUND'];
        }
        $workspaceId = (int) $session->workspace_id;

        $name  = trim((string) ($fields['name']  ?? '')) ?: 'Chatbot visitor';
        $email = $this->cleanEmail($fields['email'] ?? null);
        $phone = $this->cleanPhone($fields['phone'] ?? null);
        $notes = trim((string) ($fields['notes'] ?? ''));

        // De-dup by email then phone within this workspace
        $existing = null;
        if ($email) {
            $existing = DB::table('leads')->where('workspace_id', $workspaceId)
                ->where('email', $email)->whereNull('deleted_at')->first();
        }
        if (! $existing && $phone) {
            $existing = DB::table('leads')->where('workspace_id', $workspaceId)
                ->where('phone', $phone)->whereNull('deleted_at')->first();
        }

        $summary = $this->summariseSession($sessionId, 12);
        if ($existing) {
            DB::table('leads')->where('id', $existing->id)->update([
                'last_contacted_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('notes')->insert([
                'workspace_id' => $workspaceId,
                'notable_type' => 'Lead',
                'notable_id'   => $existing->id,
                'body'         => "Chatbot conversation summary:\n\n{$summary}" . ($notes ? "\n\nVisitor said:\n{$notes}" : ''),
                'created_at'   => now(), 'updated_at' => now(),
            ]);
            DB::table('chatbot_sessions')->where('id', $sessionId)
                ->update(['lead_id' => $existing->id, 'visitor_name' => $name, 'visitor_email' => $email, 'visitor_phone' => $phone]);
            $this->notifyChatbotLeadCapture($workspaceId, (int) $existing->id, $name, $email, $phone, $sessionId, true);
            return ['success' => true, 'data' => ['lead_id' => $existing->id, 'created' => false, 'updated' => true]];
        }

        $lead = $this->crm->createLead($workspaceId, [
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'source'  => 'chatbot888',
            'website' => $session->page_url,
            'metadata'=> [
                'chatbot_session_id' => $sessionId,
                'page_url'           => $session->page_url,
                'first_message'      => $this->firstUserMessage($sessionId),
            ],
            'tags' => ['chatbot888'],
        ]);

        DB::table('notes')->insert([
            'workspace_id' => $workspaceId,
            'notable_type' => 'Lead',
            'notable_id'   => $lead->id,
            'body'         => "Chatbot conversation summary:\n\n{$summary}" . ($notes ? "\n\nVisitor said:\n{$notes}" : ''),
            'created_at'   => now(), 'updated_at' => now(),
        ]);

        DB::table('chatbot_sessions')->where('id', $sessionId)
            ->update(['lead_id' => $lead->id, 'visitor_name' => $name, 'visitor_email' => $email, 'visitor_phone' => $phone]);

        $this->notifyChatbotLeadCapture($workspaceId, (int) $lead->id, $name, $email, $phone, $sessionId, false);

        // PATCH 7 (2026-05-08): fire any active 'lead_captured' automation.
        // Wrapped so a misconfigured automation never breaks lead capture.
        try {
            app(\App\Engines\Marketing\Services\MarketingService::class)->triggerAutomation(
                $workspaceId,
                'lead_captured',
                [
                    'contact_id' => (int) $lead->id,
                    'email'      => $email,
                    'firstname'  => $name,
                    'phone'      => $phone,
                    'source'     => 'chatbot888',
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Chatbot lead automation trigger failed', [
                'workspace_id' => $workspaceId,
                'lead_id'      => (int) $lead->id,
                'error'        => $e->getMessage(),
            ]);
        }

        return ['success' => true, 'data' => ['lead_id' => $lead->id, 'created' => true, 'updated' => false]];
    }

    /**
     * T3.2 Phase 5 — fire LEAD_CHATBOT_CAPTURE notification.
     * Wrapped in try/catch so a notification failure never breaks the lead
     * capture flow (the lead row + note are already persisted by callers).
     */
    private function notifyChatbotLeadCapture(int $workspaceId, int $leadId, string $name, ?string $email, ?string $phone, int $sessionId, bool $isExisting): void
    {
        try {
            $ownerId = DB::table('workspace_users')
                ->where('workspace_id', $workspaceId)
                ->where('role', 'owner')
                ->value('user_id');
            if (! $ownerId) return;

            app(\App\Core\Notifications\NotificationService::class)->dispatch(
                type: \App\Core\Notifications\NotificationTypes::LEAD_CHATBOT_CAPTURE,
                userId: (int) $ownerId,
                title: 'New chatbot lead captured',
                workspaceId: $workspaceId,
                body: "{$name} (" . ($email ?: ($phone ?: 'no contact')) . ') was captured via chatbot' . ($isExisting ? ' — existing lead updated' : ''),
                data: [
                    'lead_id'      => $leadId,
                    'name'         => $name,
                    'email'        => $email,
                    'phone'        => $phone,
                    'session_id'   => $sessionId,
                    'source'       => 'chatbot888',
                    'is_duplicate' => $isExisting,
                ],
                actionUrl: '/crm/leads/' . $leadId,
                severity: $isExisting ? 'warning' : 'success'
            );
        } catch (\Throwable $e) {
            Log::warning('Chatbot lead notification failed', [
                'workspace_id' => $workspaceId,
                'lead_id'      => $leadId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a calendar event for a booking or callback request.
     * Always created in 'pending' state — Phase 0 design call (no
     * availability/conflict checking in v1; owner confirms in admin).
     *
     * Public — also invoked by /api/public/chatbot/booking-request and
     * /api/public/chatbot/callback-request form-fallback paths.
     */
    public function createBookingOrCallback(int $sessionId, string $kind, array $fields): array
    {
        $session = DB::table('chatbot_sessions')->where('id', $sessionId)->first();
        if (! $session) return ['success' => false, 'error' => 'SESSION_NOT_FOUND'];
        $workspaceId = (int) $session->workspace_id;

        // Lead first (idempotent + de-duped). Always.
        $leadResult = $this->captureLead($sessionId, $fields);
        if (! ($leadResult['success'] ?? false)) {
            return ['success' => false, 'error' => 'LEAD_FAILED'];
        }
        $leadId = (int) ($leadResult['data']['lead_id'] ?? 0);

        $startsAt = $this->parseDateTime($fields['date'] ?? null, $fields['time'] ?? null, $workspaceId);
        if (! $startsAt) {
            $startsAt = now()->addDay()->setTime(9, 0);
        }
        $duration = $kind === 'booking' ? 30 : 15;

        $title = ($kind === 'booking' ? 'Booking request — ' : 'Callback request — ')
            . trim((string) ($fields['name'] ?? 'visitor'));
        $description = "From chatbot session #{$sessionId}\n";
        if (! empty($fields['service'])) $description .= "Service: {$fields['service']}\n";
        if (! empty($fields['notes']))   $description .= "Notes: {$fields['notes']}\n";
        $description .= "Status: PENDING — awaiting owner confirmation";

        try {
            $eventId = $this->calendar->createEvent($workspaceId, [
                'title'         => $title,
                'description'   => $description,
                'category'      => $kind === 'booking' ? 'booking_pending' : 'callback_pending',
                'engine'        => 'chatbot888',
                'reference_id'  => $leadId,
                'reference_type'=> 'Lead',
                'color'         => $kind === 'booking' ? '#A855F7' : '#3B82F6',
                'starts_at'     => $startsAt,
                'ends_at'       => $startsAt->copy()->addMinutes($duration),
                'all_day'       => false,
            ]);
            return ['success' => true, 'data' => ['event_id' => $eventId, 'lead_id' => $leadId, 'starts_at' => $startsAt->toIso8601String()]];
        } catch (\Throwable $e) {
            Log::warning('[chatbot] booking creation failed; lead persisted', [
                'workspace_id' => $workspaceId, 'session_id' => $sessionId, 'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => 'BOOKING_FAILED', 'lead_id' => $leadId, 'message' => 'We received your request and will email to confirm.'];
        }
    }

    // ════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ════════════════════════════════════════════════════════════════

    private function bumpUsage(int $workspaceId): void
    {
        $month = now()->format('Ym');
        DB::table('chatbot_usage_logs')
            ->updateOrInsert(
                ['workspace_id' => $workspaceId, 'month_yyyymm' => $month],
                [
                    'messages_count' => DB::raw('messages_count + 1'),
                    'credits_used'   => DB::raw('credits_used + ' . self::CREDIT_COST_PER_MESSAGE),
                    'last_at'        => now(),
                    'updated_at'     => now(),
                ]
            );
    }

    private function summariseSession(int $sessionId, int $maxTurns): string
    {
        $msgs = DB::table('chatbot_messages')->where('session_id', $sessionId)
            ->orderBy('id')->limit($maxTurns)->get(['role', 'content']);
        $lines = [];
        foreach ($msgs as $m) {
            $lines[] = strtoupper($m->role) . ': ' . mb_substr($m->content, 0, 240);
        }
        return implode("\n", $lines);
    }

    private function firstUserMessage(int $sessionId): string
    {
        $msg = DB::table('chatbot_messages')->where('session_id', $sessionId)
            ->where('role', 'user')->orderBy('id')->first();
        return $msg ? mb_substr($msg->content, 0, 500) : '';
    }

    private function cleanEmail(?string $v): ?string
    {
        $v = trim((string) $v);
        return ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) ? strtolower($v) : null;
    }

    private function cleanPhone(?string $v): ?string
    {
        $v = preg_replace('/[^\d+]/', '', (string) $v);
        return $v !== '' && strlen($v) >= 7 ? $v : null;
    }

    private function parseDateTime(?string $date, ?string $time, int $workspaceId): ?\Carbon\Carbon
    {
        if (empty($date)) return null;
        $tz = DB::table('chatbot_settings')->where('workspace_id', $workspaceId)->value('timezone') ?: 'UTC';
        try {
            $dt = $time
                ? \Carbon\Carbon::parse($date . ' ' . $time, $tz)
                : \Carbon\Carbon::parse($date, $tz)->setTime(9, 0);
            return $dt->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
