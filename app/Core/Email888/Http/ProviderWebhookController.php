<?php

namespace App\Core\Email888\Http;

use App\Core\Email888\Contracts\ProviderEvent;
use App\Core\Email888\DeliveryLedger;
use App\Core\Email888\Models\WebhookReceipt;
use App\Core\Email888\Providers\WebhookNormalizerRegistry;
use App\Core\Email888\WebhookIngressRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * EMAIL888 — the provider webhook endpoint. Provider-AGNOSTIC (EM-8).
 *
 * This was PostmarkWebhookController, and it read `RecordType`, `MessageID`,
 * `DeliveredAt` and `BouncedAt` inline. That worked and would have kept working
 * — right up until a second provider arrived, at which point the mapping would
 * have been copied rather than reused, and the copy would have drifted. Vendor
 * vocabulary now lives in a WebhookNormalizer; this class knows only
 * ProviderEvent.
 *
 * AUTHENTICATION IS A PROVIDER CAPABILITY, NOT AN ARCHITECTURE.
 * Postmark cannot sign its webhooks, so its authentication is a high-entropy
 * path secret plus HTTP Basic. That is Postmark's answer, not a universal one:
 * another vendor may offer HMAC, a signature header, or nothing at all. What the
 * core consumes is an authentication OUTCOME. Generalising "path secret + Basic"
 * into the contract would bake one vendor's limitation into the platform.
 *
 * HTTP SEMANTICS
 * Providers retry non-2xx, so the status code has to mean something:
 *   404  bad credentials / unknown provider  - never retry, never confirm the path
 *   400  body is not an event we understand  - retrying will not help
 *   200  understood
 *   500  our storage failed                  - please retry, we want it
 *
 * EVERY ATTEMPT IS RECORDED (EM-6), including refused ones, through
 * WebhookIngressRecorder - which also serves the router-level and rate-limiter
 * refusals this class never sees.
 */
class ProviderWebhookController
{
    public function __construct(
        private readonly DeliveryLedger $ledger,
        private readonly WebhookIngressRecorder $recorder,
        private readonly WebhookNormalizerRegistry $registry,
    ) {
    }

    public function __invoke(Request $request, string $provider, string $secret): JsonResponse
    {
        $t0        = microtime(true);
        $requestId = (string) Str::uuid();
        $endpoint  = '/api/webhooks/email/{provider}/{secret}';

        $receipt = [
            'received_at' => now(),
            'provider'    => substr(strtolower($provider), 0, 32),
            'endpoint'    => $endpoint,
            'request_id'  => $requestId,
            'client_ip'   => substr((string) $request->ip(), 0, 45),
        ];

        // ── is this a provider we speak for? ──────────────────────────
        $normalizer = $this->registry->for($provider);

        if ($normalizer === null) {
            $this->recorder->refuse(
                request:        $request,
                reason:         WebhookReceipt::AUTH_UNKNOWN_PROVIDER,
                httpStatus:     404,
                operatorReason: 'Rejected: no adapter is registered for that provider. The URL named a provider this installation does not speak for.',
                endpoint:       $endpoint,
                latencyMs:      $this->ms($t0),
                provider:       $provider,
            );

            return $this->refused();
        }

        // ── authentication ────────────────────────────────────────────
        $auth = $this->authenticate($request, $secret);

        if ($auth !== WebhookReceipt::AUTH_ACCEPTED) {
            $this->recorder->refuse(
                request:        $request,
                reason:         $auth,
                httpStatus:     404,
                operatorReason: match ($auth) {
                    WebhookReceipt::AUTH_UNCONFIGURED => 'Endpoint refused: no webhook secret is configured on this installation.',
                    WebhookReceipt::AUTH_BAD_BASIC    => 'Rejected: path secret correct but HTTP Basic credentials wrong or absent.',
                    default                           => 'Rejected: incorrect path secret.',
                },
                endpoint:       $endpoint,
                latencyMs:      $this->ms($t0),
                provider:       $provider,
            );

            return $this->refused();
        }

        // ── validation ────────────────────────────────────────────────
        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            $this->recorder->record($receipt + [
                'authentication_result'   => $auth,
                'validation_result'       => WebhookReceipt::INVALID_EMPTY,
                'processing_stage'        => WebhookReceipt::STAGE_VALIDATE,
                'http_status'             => 400,
                'processing_latency_ms'   => $this->ms($t0),
                'operator_visible_reason' => 'Rejected: the request body was empty or not JSON.',
            ]);

            return response()->json(['error' => 'empty_payload'], 400);
        }

        // A vendor may batch as a bare array OR wrap it in an envelope; only
        // the adapter knows which, so both are handed over whole.
        $candidates = array_is_list($payload) ? $payload : [$payload];

        /** @var list<ProviderEvent> $events */
        $events = [];
        foreach ($candidates as $c) {
            if (is_array($c)) {
                $events = array_merge($events, $normalizer->normalize($c));
            }
        }

        $results = [];
        foreach ($events as $event) {
            $results[] = $this->apply($event);
        }

        // No event survived normalisation: understood as "not an event we know".
        if ($events === []) {
            $this->recorder->record($receipt + [
                'authentication_result'   => $auth,
                'validation_result'       => WebhookReceipt::INVALID_SHAPE,
                'processing_stage'        => WebhookReceipt::STAGE_VALIDATE,
                'http_status'             => 400,
                'payload_digest'          => hash('sha256', (string) json_encode($payload)),
                'processing_latency_ms'   => $this->ms($t0),
                'operator_visible_reason' => 'Rejected: no recognisable event in the body for this provider.',
            ]);

            return response()->json(['error' => 'no_events'], 400);
        }

        $status = in_array('error', $results, true) ? 500 : 200;
        $first  = $events[0];

        $this->recorder->record($receipt + [
            'authentication_result'   => $auth,
            'validation_result'       => WebhookReceipt::VALID,
            'processing_stage'        => WebhookReceipt::STAGE_COMPLETE,
            'processing_result'       => count($results) === 1 ? $results[0] : 'batch:' . implode(',', array_unique($results)),
            'stream'                  => $this->streamOf($candidates[0] ?? []),
            'provider_message_id'     => $first->providerMessageId,
            'event_type'              => substr($first->rawEventType, 0, 48) ?: null,
            'payload_digest'          => hash('sha256', (string) json_encode($payload)),
            'http_status'             => $status,
            'processing_latency_ms'   => $this->ms($t0),
            'operator_visible_reason' => $this->explain($results, $status),
        ]);

        return response()->json(['results' => $results, 'request_id' => $requestId], $status);
    }

    /** Apply ONE neutral event. Nothing here knows a vendor. */
    private function apply(ProviderEvent $event): string
    {
        if (! $event->isActionable()) {
            return 'ignored';
        }

        try {
            return $this->ledger->applyProviderEvent(
                provider:          $event->provider,
                providerMessageId: $event->providerMessageId,
                state:             $event->state,
                rawEventType:      $event->rawEventType,
                payloadDigest:     $event->digest(),
                evidence:          $event->evidence,
                occurredAt:        $event->occurredAt,
            );
        } catch (Throwable $e) {
            Log::error('email888.webhook.handle_failed', ['error' => $e->getMessage()]);

            return 'error';
        }
    }

    /**
     * Identical refusal for every rejected reason: an attacker must not be able
     * to tell an unknown provider from a wrong secret from wrong Basic auth.
     */
    private function refused(): JsonResponse
    {
        return response()->json(['error' => 'not_found'], 404);
    }

    /** Vendor stream naming is evidence only, and is optional. */
    private function streamOf(array $raw): ?string
    {
        foreach (['MessageStream', 'stream', 'channel'] as $k) {
            if (isset($raw[$k]) && is_string($raw[$k]) && $raw[$k] !== '') {
                return substr($raw[$k], 0, 64);
            }
        }

        return null;
    }

    private function explain(array $results, int $status): string
    {
        if ($status === 500) {
            return 'Storage failed while recording the event. The provider will retry.';
        }

        return match ($results[0] ?? 'none') {
            'applied'      => 'Applied: the delivery ledger was updated.',
            'duplicate'    => 'Already recorded. Redelivery of an event we hold; no change made.',
            'out_of_order' => 'Ignored: a later state is already recorded. States never move backwards.',
            'unmatched'    => 'Kept as evidence: no delivery in the ledger carries that message id.',
            'ignored'      => 'Acknowledged: understood, but this event type changes nothing.',
            default        => 'Processed. See the results field for per-event outcomes.',
        };
    }

    private function ms(float $t0): int
    {
        return (int) ((microtime(true) - $t0) * 1000);
    }

    /** @return string one of WebhookReceipt::AUTH_* */
    private function authenticate(Request $request, string $secret): string
    {
        $expected = (string) config('email888.webhook.secret', '');

        if ($expected === '' || strlen($expected) < 24) {
            Log::warning('email888.webhook.refused_unconfigured');

            return WebhookReceipt::AUTH_UNCONFIGURED;
        }

        if (! hash_equals($expected, $secret)) {
            return WebhookReceipt::AUTH_BAD_SECRET;
        }

        $user = (string) config('email888.webhook.basic_user', '');
        $pass = (string) config('email888.webhook.basic_password', '');

        if ($user !== '' || $pass !== '') {
            $ok = hash_equals($user, (string) $request->getUser())
                && hash_equals($pass, (string) $request->getPassword());

            if (! $ok) {
                return WebhookReceipt::AUTH_BAD_BASIC;
            }
        }

        return WebhookReceipt::AUTH_ACCEPTED;
    }
}
