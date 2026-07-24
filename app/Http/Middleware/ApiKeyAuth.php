<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Auth\ApiCredentialResolver;

/**
 * ApiKeyAuth — workspace-scoped API key authentication for inbound integrations
 * (e.g. the SEO WP Connector plugin).
 *
 * 2026-07-18 SECURITY REMEDIATION (INFRA888 Phase 1B)
 * ---------------------------------------------------
 * Resolution now delegates to ApiCredentialResolver, so this middleware and
 * JwtAuthMiddleware can no longer disagree about what a credential means.
 *
 * FIXED: this middleware previously set workspace_id but NEVER set a user
 * resolver. Downstream, $request->user() was null — so audit rows written by
 * API-key traffic recorded user_id = NULL and TeamRoleMiddleware 401'd. The
 * bound principal is now attached, which makes API-key actions attributable.
 *
 * Unchanged: when no X-API-KEY is present but a Bearer token is, the request
 * falls through to JwtAuthMiddleware. That preserves the SPA's direct-mode
 * contract and is not a privilege path — the JWT is validated normally.
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-API-KEY') ?? $request->query('api_key');

        if (! $key) {
            if ($request->bearerToken()) {
                return app(JwtAuthMiddleware::class)->handle($request, $next);
            }

            return response()->json(['error' => 'api_key_required'], 401);
        }

        $resolver = app(ApiCredentialResolver::class);
        $resolved = $resolver->resolve($key);

        if ($resolved['denied']) {
            return response()->json([
                'error' => $resolved['message'],
                'code'  => $resolved['code'],
            ], 403);
        }

        $user = $resolved['user'];
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('workspace_id', $resolved['workspace_id']);
        $request->attributes->set('api_key_id', $resolved['api_key_id']);
        $request->attributes->set('workspace_role', $resolved['role']);
        $request->attributes->set('auth_via', 'api_key');

        $resolver->touch($resolved['api_key_id']);

        return $next($request);
    }
}
