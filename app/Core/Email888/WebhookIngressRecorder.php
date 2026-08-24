<?php

namespace App\Core\Email888;

use App\Core\Email888\Models\WebhookReceipt;
use App\Core\Email888\OutboundPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * EMAIL888 EM-6 — the single writer of webhook ingress receipts.
 *
 * WHY THIS IS A SERVICE AND NOT THREE COPIES OF `create()`
 * Ingress is refused in three different places — the router (a path that does
 * not match the secret's shape), the rate limiter (429, thrown as an exception
 * before any controller runs) and the controller itself (wrong secret, wrong
 * basic auth). Each one used to be a different kind of silence. They now share
 * one writer so that "every attempt leaves a receipt" is a property of one
 * class rather than a promise repeated in three.
 *
 * THE RECEIPT MUST NOT BECOME THE ATTACK
 * A durable row per refused request is, by construction, unauthenticated write
 * amplification: anyone who can reach the URL can make us write. Two bounds:
 *
 *   1. Refusals are COALESCED — at most COALESCE_LIMIT rows per refusal reason
 *      per minute. Buckets are per reason, deliberately, so a flood of garbage
 *      paths can never crowd out the one `refused_bad_basic` row that says
 *      Postmark itself is being turned away.
 *
 *   2. When a bucket fills, one final row is written saying so, and then the
 *      minute goes quiet. An operator sees "this is a flood", which is the
 *      actual signal; rows 6 through 6,000 of a flood carry no more meaning
 *      than row 5 did.
 *
 * ACCEPTED ATTEMPTS ARE NEVER COALESCED. Real provider traffic is bounded by
 * Postmark's own volume, and dropping one is dropping evidence of real mail.
 *
 * NEVER WRITTEN HERE: the path secret, the basic-auth password, the
 * Authorization header, the provider token, or the raw request body.
 */
class WebhookIngressRecorder
{
    /** Rows per refusal reason per minute before coalescing. */
    private const COALESCE_LIMIT = 5;

    /**
     * Record a refused attempt, subject to the coalescing bound.
     *
     * @param string $reason one of WebhookReceipt::AUTH_*
     */
    public function refuse(
        Request $request,
        string $reason,
        int $httpStatus,
        string $operatorReason,
        ?string $endpoint = null,
        ?int $latencyMs = null,
        // EM-8: which provider the URL named. Null falls back to the
        // configured one - a refusal still has to say who it was for.
        ?string $provider = null,
    ): void {
        $bucket = 'email888:wh:refuse:' . $reason . ':' . now()->format('YmdHi');

        // Cache::add + increment keeps the window at exactly one minute even if
        // the flood spans a minute boundary; a missing cache store degrades to
        // "always record", which is the safe direction for an audit trail.
        try {
            Cache::add($bucket, 0, 90);
            $seen = (int) Cache::increment($bucket);
        } catch (Throwable) {
            $seen = 1;
        }

        if ($seen > self::COALESCE_LIMIT + 1) {
            return; // the minute is already on the record as a flood
        }

        if ($seen === self::COALESCE_LIMIT + 1) {
            $operatorReason = sprintf(
                'Further "%s" refusals in this minute are being coalesced — more than %d were received. This is a flood, not a misconfiguration.',
                $reason,
                self::COALESCE_LIMIT,
            );
        }

        $this->write([
            'received_at'             => now(),
            'provider'                => substr(strtolower($provider ?? OutboundPolicy::provider()), 0, 32),
            'endpoint'                => $endpoint ?? $this->safeEndpoint($request),
            'request_id'              => (string) Str::uuid(),
            'client_ip'               => substr((string) $request->ip(), 0, 45),
            'authentication_result'   => $reason,
            'processing_stage'        => WebhookReceipt::STAGE_AUTH,
            'http_status'             => $httpStatus,
            'processing_latency_ms'   => $latencyMs,
            'operator_visible_reason' => $operatorReason,
        ]);
    }

    /**
     * Record an attempt that got past authentication. Never coalesced.
     *
     * @param array<string,mixed> $attrs
     */
    public function record(array $attrs): void
    {
        $this->write($attrs);
    }

    /**
     * A lost receipt must never cost us the event it describes. Ingestion
     * continues; the failure itself is logged so the gap is not silent either.
     *
     * @param array<string,mixed> $attrs
     */
    private function write(array $attrs): void
    {
        try {
            WebhookReceipt::create($attrs);
        } catch (Throwable $e) {
            Log::warning('email888.webhook.receipt_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The route pattern, never the resolved URI — the resolved URI contains the
     * path secret, which is a credential and must not reach the database, the
     * admin screen, or a log line.
     */
    private function safeEndpoint(Request $request): string
    {
        $pattern = optional($request->route())->uri();

        // The fallback is a PATTERN, and names no vendor: the live route
        // supplies the real one, and inventing a vendor here would put a
        // guess into the audit trail.
        return '/' . ltrim((string) ($pattern ?: 'api/webhooks/email/{provider}/{secret}'), '/');
    }
}
