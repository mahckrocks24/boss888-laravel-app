<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RuntimeSecretMiddleware
 *
 * Guards all /api/internal/* routes that are called by the Railway Node.js runtime
 * or internal cron jobs. Checks a shared secret header against the
 * RUNTIME_SECRET / LARAVEL_RUNTIME_SECRET env vars.
 *
 * This middleware is intentionally simple — no session, no JWT, just a shared secret.
 * The secret must be rotated in both Laravel env and Railway env simultaneously.
 *
 * UPDATED 2026-04-12 (Phase 0.6b prep / doc 11):
 *   - Now also reads RUNTIME_SECRET env (the canonical name set in 2026-04-11)
 *     in addition to the legacy LARAVEL_RUNTIME_SECRET.
 *   - Now accepts FOUR header aliases for the secret:
 *     X-Runtime-Secret, X-Internal-Secret, X-LU-Secret, X-LevelUp-Secret.
 *     The runtime sends 'X-LU-Secret' on outbound calls and 'X-LevelUp-Secret'
 *     on some other paths — Laravel needs to accept both for the inbound
 *     callback path to work.
 *   - Plus a `_runtime_secret` form/query input alias as a last-resort fallback.
 */
class RuntimeSecretMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Try the canonical RUNTIME_SECRET (set 2026-04-11) first, fall back to
        // the legacy LARAVEL_RUNTIME_SECRET name for backward compat.
        $secret = config('app.runtime_secret')
            ?: env('RUNTIME_SECRET', '')
            ?: env('LARAVEL_RUNTIME_SECRET', '');

        if (empty($secret)) {
            // No secret configured — deny all internal requests in production
            if (app()->isProduction()) {
                return response()->json(['error' => 'Internal endpoint not configured'], 503);
            }
            // In local/staging: allow without secret (for development convenience)
            return $next($request);
        }

        // Accept any of the 4 header aliases that the runtime might send.
        // Different runtime code paths use different header names — see doc 11
        // for the full breakdown of which path uses which header.
        $provided = $request->header('X-Runtime-Secret')
            ?? $request->header('X-Internal-Secret')
            ?? $request->header('X-LU-Secret')
            ?? $request->header('X-LevelUp-Secret')
            ?? $request->input('_runtime_secret');

        if (!$provided || !hash_equals($secret, $provided)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
