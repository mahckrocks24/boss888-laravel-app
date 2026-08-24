<?php

namespace App\Core\Chat;

use Illuminate\Support\Facades\Log;

/**
 * P2-B — orders the steps of one logical chat request.
 *
 * THIS IS NOT THE UNIFIED CHAT CORE. It does not own conversations, transports,
 * rendering or routing, and it does not know what any surface's message looks
 * like. It knows one thing: the order in which idempotency, credit eligibility,
 * persistence, provider execution and charge settlement must happen so that the
 * guarantee holds:
 *
 *   ONE LOGICAL REQUEST → ONE PROVIDER EXECUTION → ONE USER MESSAGE
 *   → ONE FINAL ASSISTANT MESSAGE → AT MOST ONE CHARGE → ONE TRACEABLE OUTCOME
 *
 * Surfaces keep their own persistence and their own provider calls; they hand
 * those in as callbacks. That is deliberate: it is the smallest thing that can
 * prove the primitive without migrating anyone's controller.
 */
class ChatExecutionCoordinator
{
    public function __construct(
        private ChatIdempotencyService $idempotency,
        private MessageChargeService $charges,
    ) {}

    /**
     * @param array $ctx    workspace_id, surface, idempotency_key, content, …
     * @param array $hooks  persistUserMessage, execute, persistFinalMessage
     *
     * @return array{
     *   ok:bool, replay:bool, correlation_id:string, result:mixed,
     *   error_code:?string, http_status:int, record_id:?int
     * }
     */
    public function run(array $ctx, array $hooks): array
    {
        $surface = (string) $ctx['surface'];
        $wsId    = (int) $ctx['workspace_id'];

        // ── 4. fingerprint ───────────────────────────────────────────────────
        $ctx['request_fingerprint'] = ChatRequestFingerprint::compute($ctx);

        // ── 5/6/7. acquire · replay · conflict ───────────────────────────────
        $acq = $this->idempotency->acquire($ctx);
        $correlation = $acq['correlation_id'];

        if ($acq['state'] === 'replay') {
            // INV-10 — the authoritative earlier outcome. Nothing is re-executed,
            // re-persisted or re-charged.
            return [
                'ok' => $acq['error_code'] === null, 'replay' => true,
                'correlation_id' => $correlation,
                'result' => $acq['response'], 'error_code' => $acq['error_code'],
                'http_status' => $acq['error_code'] === null ? 200 : 409,
                'record_id' => $acq['record']->id ?? null,
            ];
        }

        if ($acq['state'] === 'conflict') {
            // INV-03 — same key, different question. Serving the stored answer
            // would be answering something the customer did not ask.
            return $this->fail($correlation, 'CHAT_CONVERSATION_CONFLICT', 409,
                'This idempotency key was already used for a different message.', $acq['record']->id ?? null);
        }

        if ($acq['state'] === 'in_progress') {
            // INV-02 — already running. Retryable, but not now.
            return $this->fail($correlation, 'CHAT_CONVERSATION_CONFLICT', 409,
                'An identical request is already being processed.', $acq['record']->id ?? null, true);
        }

        if ($acq['state'] !== 'acquired' || !$acq['record']) {
            return $this->fail($correlation, 'CHAT_INTERNAL_ERROR', 500,
                'Could not establish request identity.', null, true);
        }

        $recordId = (int) $acq['record']->id;

        // ── 8. chargeability ─────────────────────────────────────────────────
        $cost     = (int) ($ctx['estimated_credits'] ?? 0);
        $chargeId = $this->charges->open([
            'workspace_id' => $wsId, 'user_id' => $ctx['user_id'] ?? null,
            'surface' => $surface, 'agent_id' => $ctx['agent_id'] ?? null,
            'conversation_id' => $ctx['conversation_id'] ?? null,
            'client_message_id' => $ctx['client_message_id'] ?? null,
            'idempotency_record_id' => $recordId,
            'idempotency_key' => $ctx['idempotency_key'] ?? null,
            'correlation_id' => $correlation,
            'estimated_credits' => $cost,
            // P2-C: when the surface already owns a correct reserve/commit/
            // release cycle, the coordinator records linkage but does NOT take
            // the money — otherwise one request is billed twice.
            'delegated' => ($ctx['delegated'] ?? false) === true,
        ]);
        $chargeable = $this->charges->classify($surface)['chargeable'] && ($ctx['delegated'] ?? false) !== true;

        // ── 9. eligibility BEFORE the provider (INV-09) ──────────────────────
        if ($chargeable && $cost > 0 && !$this->charges->isEligible($wsId, $cost)) {
            $this->charges->release($chargeId, 'CHAT_INSUFFICIENT_CREDITS');
            $this->idempotency->markFailed($recordId, 'CHAT_INSUFFICIENT_CREDITS', false);

            return $this->fail($correlation, 'CHAT_INSUFFICIENT_CREDITS', 402,
                'This workspace is out of credits.', $recordId);
        }

        // ── 10. reserve ──────────────────────────────────────────────────────
        if ($chargeable && $cost > 0) {
            if ($this->charges->reserve($chargeId, $wsId, $cost, 'chat_' . $surface) === null) {
                $this->idempotency->markFailed($recordId, 'CHAT_INSUFFICIENT_CREDITS', false);

                return $this->fail($correlation, 'CHAT_INSUFFICIENT_CREDITS', 402,
                    'This workspace is out of credits.', $recordId);
            }
        }

        if (!$this->idempotency->markProcessing($recordId)) {
            // Another worker claimed it between acquire and here.
            $this->charges->release($chargeId, 'CHAT_CONVERSATION_CONFLICT');

            return $this->fail($correlation, 'CHAT_CONVERSATION_CONFLICT', 409,
                'An identical request is already being processed.', $recordId, true);
        }

        $userMessageId = null;

        try {
            // ── 11. persist the user message ONCE (INV-04) ───────────────────
            if (isset($hooks['persistUserMessage'])) {
                $userMessageId = $hooks['persistUserMessage']($correlation);
            }

            // ── 12/13. execute the provider ONCE (INV-02) ────────────────────
            $this->charges->markExecuting($chargeId, $ctx['provider'] ?? null, $ctx['model'] ?? null);
            $exec = $hooks['execute']($correlation);

            if (!is_array($exec) || ($exec['ok'] ?? false) !== true) {
                // INV-07 — a failed execution never commits.
                $code = $exec['error_code'] ?? 'CHAT_PROVIDER_UNAVAILABLE';
                $this->charges->release($chargeId, $code);
                $this->idempotency->markFailed($recordId, $code, (bool) ($exec['retryable'] ?? true), [
                    'user_message_id' => $userMessageId,
                ]);

                return $this->fail($correlation, $code, (int) ($exec['http_status'] ?? 503),
                    $exec['message'] ?? 'The assistant is unavailable right now.', $recordId,
                    (bool) ($exec['retryable'] ?? true));
            }

            // ── 14/15. record usage · persist the final ONCE (INV-05) ────────
            $finalMessageId = null;
            if (isset($hooks['persistFinalMessage'])) {
                $finalMessageId = $hooks['persistFinalMessage']($exec, $correlation);
            }

            $this->charges->attachMessage($chargeId, (string) ($ctx['message_store'] ?? $surface), $finalMessageId, [
                'tokens'   => $exec['usage']['tokens'] ?? null,
                'provider' => $exec['provider'] ?? null,
                'model'    => $exec['model'] ?? null,
            ]);

            // ── 16. commit ONCE (INV-06) ─────────────────────────────────────
            $this->charges->commit($chargeId);

            // ── 17. complete ─────────────────────────────────────────────────
            $reference = [
                'user_message_id'  => $userMessageId,
                'final_message_id' => $finalMessageId,
                'body'             => $exec['body'] ?? null,
                'correlation_id'   => $correlation,
            ];
            $this->idempotency->markCompleted($recordId, $reference);

            // ── 18. canonical result ─────────────────────────────────────────
            return ['ok' => true, 'replay' => false, 'correlation_id' => $correlation,
                    'result' => $reference, 'error_code' => null, 'http_status' => 200,
                    'record_id' => $recordId];
        } catch (\Throwable $e) {
            // Anything unexpected: release, record, never commit, never fabricate.
            $this->charges->release($chargeId, 'CHAT_INTERNAL_ERROR');
            $this->idempotency->markFailed($recordId, 'CHAT_INTERNAL_ERROR', true, [
                'user_message_id' => $userMessageId,
            ]);
            Log::error('chat.coordinator.exception', [
                'correlation_id' => $correlation, 'surface' => $surface,
                'workspace_id' => $wsId, 'exception' => $e->getMessage(),
            ]);

            return $this->fail($correlation, 'CHAT_INTERNAL_ERROR', 500,
                'Something went wrong handling that message.', $recordId, true);
        }
    }

    private function fail(string $correlation, string $code, int $status, string $message, ?int $recordId, bool $retryable = false): array
    {
        return [
            'ok' => false, 'replay' => false, 'correlation_id' => $correlation,
            'result' => null, 'error_code' => $code, 'http_status' => $status,
            'record_id' => $recordId,
            'error' => [
                'code' => $code, 'message' => $message, 'retryable' => $retryable,
                'correlation_id' => $correlation, 'provider_called' => false,
            ],
        ];
    }
}
