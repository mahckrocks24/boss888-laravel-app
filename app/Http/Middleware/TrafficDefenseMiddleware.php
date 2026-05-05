<?php

namespace App\Http\Middleware;

use App\Engines\TrafficDefense\Services\TrafficDefenseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * TrafficDefenseMiddleware
 *
 * BUILT 2026-04-12 (Phase 2J / doc 12).
 *
 * Wires the previously-orphan `TrafficDefenseService` into the request pipeline.
 * Before this middleware existed, `TrafficDefenseService` was fully implemented
 * (bot detection, referrer blocking, custom rules, rate limiting, traffic logging,
 * stats aggregation) but `evaluateTraffic()` was never called from anywhere —
 * 0 rules / 0 logs on staging because the engine was unwired.
 *
 * Behavior:
 *   - Runs only on requests that have been workspace-scoped (i.e. after auth.jwt
 *     has set workspace_id on the request attributes). Non-workspace requests
 *     pass through untouched.
 *   - Calls TrafficDefenseService::evaluateTraffic($wsId, $request) to get a
 *     quality score + action (allowed / flagged / blocked).
 *   - On 'blocked' → returns 403 with a generic message + the rule name (if any)
 *   - On 'flagged' or 'allowed' → request proceeds normally; the score + flags
 *     are written to traffic_logs by the service itself.
 *   - On internal failure → logs a warning and FAILS OPEN (allows the request
 *     through). Traffic defense should never bring down the API.
 *
 * Apply via: Route::middleware(['auth.jwt', 'traffic.defense'])->group(...)
 * Alias registered in bootstrap/app.php.
 */
class TrafficDefenseMiddleware
{
    public function __construct(private TrafficDefenseService $defense) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only run on workspace-scoped requests. If auth.jwt hasn't set
        // workspace_id (admin routes, public routes, runtime callbacks),
        // skip this middleware entirely.
        $wsId = $request->attributes->get('workspace_id');
        if (! $wsId) {
            return $next($request);
        }

        try {
            $result = $this->defense->evaluateTraffic($wsId, [
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent() ?? '',
                'referrer'   => $request->headers->get('referer', '') ?? '',
                // Cloudflare-style country header. Falls back to empty if absent.
                'country'    => $request->headers->get('cf-ipcountry', '') ?? '',
            ]);
        } catch (\Throwable $e) {
            // FAIL OPEN — never let traffic defense bring down the API
            Log::warning('TrafficDefenseMiddleware: evaluation failed, allowing request', [
                'workspace_id' => $wsId,
                'ip'           => $request->ip(),
                'error'        => $e->getMessage(),
            ]);
            return $next($request);
        }

        $action = $result['action'] ?? 'allowed';

        if ($action === 'blocked') {
            return response()->json([
                'error' => 'Request blocked',
                'reason' => 'This request was blocked by traffic defense rules.',
                'flags'  => $result['flags'] ?? [],
            ], 403);
        }

        // 'flagged' or 'allowed' — let the request proceed.
        // The service has already logged it to traffic_logs.
        return $next($request);
    }
}
