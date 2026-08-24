<?php

namespace App\Core\Email888\Http\Middleware;

use App\Core\Email888\Models\WebhookReceipt;
use App\Core\Email888\WebhookIngressRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Symfony\Component\HttpFoundation\Response;

/**
 * EMAIL888 EM-6 — a rate-limited webhook is still a webhook we were told about.
 *
 * `throttle:` aborts by THROWING, which unwinds past the controller straight to
 * the exception handler. So a 429 left no receipt: if our own rate limiter ever
 * started turning Postmark away, delivery observability would stop and the only
 * evidence would be its absence.
 *
 * This sits OUTSIDE the throttle middleware so that it catches what the throttle
 * throws, records it, and rethrows unchanged — the limiter's behaviour, status
 * and Retry-After header are untouched. 429 is deliberately preserved rather
 * than masked as 404: Postmark retries non-2xx, and a message we were too busy
 * to accept is one we want back.
 *
 * The recorder coalesces, so being flooded costs a bounded number of rows.
 */
class RecordThrottledIngress
{
    public function __construct(private readonly WebhookIngressRecorder $recorder)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (ThrottleRequestsException $e) {
            $this->recorder->refuse(
                request:        $request,
                reason:         WebhookReceipt::AUTH_RATE_LIMITED,
                httpStatus:     429,
                operatorReason: 'Refused by our own rate limiter, not by authentication. If this coincides with missing delivery events, the limit is too low for current volume.',
            );

            throw $e;
        }
    }
}
