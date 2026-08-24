<?php

namespace App\Core\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * P2-B — the smallest possible production integration point.
 *
 * A surface that already owns its own persistence, provider call and credit
 * metering does not need to be rewritten to gain idempotency. It needs one
 * thing: a guard placed BEFORE its metering, so that a duplicate submission
 * returns the first answer instead of running the whole pipeline again.
 *
 * That ordering is the entire point. Because the gate sits in front of the
 * surface's own meter, a replay never ticks the meter, never calls the provider
 * and never persists a second message — INV-02, INV-04, INV-05, INV-06 and
 * INV-10 all follow from position rather than from rewriting the surface.
 *
 * DELEGATED CHARGING
 * Where the surface already owns the money (SEO's meterChat, the chatbot's
 * reserve/commit), the charge record is opened as `not_chargeable` with reason
 * `delegated_to_surface_meter`. The record still captures linkage and
 * correlation; it deliberately does NOT reserve a second time. Double-charging
 * is prevented by the gate refusing to re-run the handler, not by taking the
 * money away from the surface that already handles it correctly.
 */
class ChatIdempotencyGate
{
    public function __construct(
        private ChatIdempotencyService $idempotency,
        private MessageChargeService $charges,
    ) {}

    /** Is the gate switched on for this surface? Default: off. */
    public static function enabledFor(string $surface): bool
    {
        if (!filter_var(env('CHAT_IDEMPOTENCY_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        $allow = array_filter(array_map('trim', explode(',', (string) env('CHAT_IDEMPOTENCY_SURFACES', ''))));

        return in_array($surface, $allow, true);
    }

    /**
     * Wrap one surface handler.
     *
     * Returns null when the gate is not engaged (no key supplied, or disabled),
     * so the caller simply proceeds exactly as it does today. That null is the
     * compatibility guarantee: every existing client keeps working unchanged.
     *
     * @param callable():array $handler produces the surface's normal response body
     * @return array{body:array,status:int,replay:bool,correlation_id:string}|null
     */
    public function wrap(Request $r, array $ctx, callable $handler): ?array
    {
        $key = trim((string) $r->input('idempotency_key', ''));
        $surface = (string) $ctx['surface'];

        if ($key === '' || !self::enabledFor($surface)) {
            return null;   // opted out — legacy path, byte-identical behaviour
        }

        $ctx['idempotency_key']     = $key;
        $ctx['client_message_id']   = $r->input('client_message_id');
        $ctx['request_fingerprint'] = ChatRequestFingerprint::compute($ctx);

        $acq = $this->idempotency->acquire($ctx);
        $cid = $acq['correlation_id'];

        // INV-10 — the answer already exists. Return it; run nothing.
        if ($acq['state'] === 'replay') {
            $stored = $acq['response'];
            Log::info('chat.idempotency.replay', ['surface' => $surface, 'correlation_id' => $cid]);

            // INV-10 — replay the ORIGINAL outcome, including its status. A
            // settled 402 refusal must replay as a 402; forcing 409 here would
            // change what the customer sees on a safe retry.
            if ($acq['error_code'] !== null) {
                $body = is_array($stored['body'] ?? null) && $stored['body'] !== []
                    ? $stored['body']
                    : $this->errorBody($acq['error_code'], $cid, false);
                $body['idempotent_replay'] = true;
                $body['correlation_id']    = $cid;

                return ['body' => $body, 'status' => (int) ($stored['status'] ?? 409),
                        'replay' => true, 'correlation_id' => $cid];
            }

            $body = is_array($stored['body'] ?? null) ? $stored['body'] : [];
            $body['idempotent_replay'] = true;
            $body['correlation_id']    = $cid;

            return ['body' => $body, 'status' => 200, 'replay' => true, 'correlation_id' => $cid];
        }

        // INV-03 — same key, different question.
        if ($acq['state'] === 'conflict') {
            return ['body' => $this->errorBody('CHAT_CONVERSATION_CONFLICT', $cid, false,
                        'This idempotency key was already used for a different message.'),
                    'status' => 409, 'replay' => false, 'correlation_id' => $cid];
        }

        // INV-02 — already running. Do not start a second execution.
        if ($acq['state'] === 'in_progress') {
            return ['body' => $this->errorBody('CHAT_CONVERSATION_CONFLICT', $cid, true,
                        'An identical request is already being processed.'),
                    'status' => 409, 'replay' => false, 'correlation_id' => $cid];
        }

        if ($acq['state'] !== 'acquired' || !$acq['record']) {
            return null;   // could not establish identity — fail open to the legacy path
        }

        $recordId = (int) $acq['record']->id;

        // Linkage only. The surface keeps ownership of its own metering.
        $chargeId = $this->charges->open([
            'workspace_id'          => (int) $ctx['workspace_id'],
            'user_id'               => $ctx['user_id'] ?? null,
            'surface'               => $surface,
            'conversation_id'       => $ctx['conversation_id'] ?? null,
            'idempotency_record_id' => $recordId,
            'idempotency_key'       => $key,
            'correlation_id'        => $cid,
            'estimated_credits'     => 0,
        ]);
        $this->charges->find($chargeId) && \Illuminate\Support\Facades\DB::table(MessageChargeService::TABLE)
            ->where('id', $chargeId)
            ->update(['status' => MessageChargeService::NOT_CHARGEABLE,
                      'metadata' => json_encode(['reason' => 'delegated_to_surface_meter'])]);

        $this->idempotency->markProcessing($recordId);

        try {
            $result = $handler($cid);          // the surface's own pipeline, run ONCE
            $status = (int) ($result['status'] ?? 200);
            $body   = (array) ($result['body'] ?? []);

            if ($status >= 200 && $status < 300) {
                $this->idempotency->markCompleted($recordId, ['body' => $body, 'status' => $status, 'correlation_id' => $cid]);
            } else {
                // A refusal is a settled outcome; replaying it must not re-run
                // the handler and tick the meter again.
                $this->idempotency->markFailed($recordId, $this->codeFor($status), $status >= 500, ['body' => $body, 'status' => $status]);
            }

            $body['correlation_id'] = $cid;

            return ['body' => $body, 'status' => $status, 'replay' => false, 'correlation_id' => $cid];
        } catch (\Throwable $e) {
            $this->idempotency->markFailed($recordId, 'CHAT_INTERNAL_ERROR', true);
            Log::error('chat.idempotency.handler_exception', [
                'surface' => $surface, 'correlation_id' => $cid, 'exception' => $e->getMessage(),
            ]);
            throw $e;   // preserve the surface's existing error behaviour
        }
    }

    private function codeFor(int $status): string
    {
        return match (true) {
            $status === 402 => 'CHAT_INSUFFICIENT_CREDITS',
            $status === 409 => 'CHAT_CONVERSATION_CONFLICT',
            $status === 422 => 'CHAT_VALIDATION_FAILED',
            $status === 503 => 'CHAT_PROVIDER_UNAVAILABLE',
            default         => 'CHAT_INTERNAL_ERROR',
        };
    }

    /** Errors come only from the frozen v1 taxonomy (INV-15). */
    private function errorBody(string $code, string $cid, bool $retryable, ?string $message = null): array
    {
        $msg = $message ?? 'That request could not be completed.';

        return [
            'success' => false,
            'error'   => $msg,
            'correlation_id' => $cid,
            'chat_error' => [
                'code' => $code, 'message' => $msg, 'retryable' => $retryable,
                'correlation_id' => $cid, 'provider_called' => false,
            ],
        ];
    }
}
