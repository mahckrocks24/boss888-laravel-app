<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Core\Auth\ApiCredentialResolver;
use App\Core\Auth\RefreshTokenService;
use App\Models\User;

/**
 * JWT / API-key authentication.
 *
 * 2026-07-18 SECURITY REMEDIATION (INFRA888 Phase 1B)
 * ---------------------------------------------------
 * REMOVED: the unbound-key fallback that selected the first row of
 * workspace_users (ordered by id — typically the workspace OWNER) and executed
 * the request as that user. An integration key with no bound principal silently
 * gained owner-equivalent identity. API-key resolution now runs through
 * ApiCredentialResolver, which fails closed.
 *
 * REMOVED: the null-`ws`-claim fallback that picked the first workspace_users
 * row for the user, in arbitrary id order. Staging has a user belonging to more
 * than one workspace, so that choice was genuinely ambiguous. A single
 * membership now resolves deterministically; an ambiguous one is denied and the
 * caller must re-authenticate against an explicit workspace.
 *
 * ADDED: membership verification for the JWT `ws` claim. A token minted before a
 * user was removed from a workspace no longer grants access for the remainder of
 * its 12-hour life.
 *
 * ADDED: request attribute `auth_via` (jwt|api_key) so routes that must never be
 * reachable by machine credentials can reject them explicitly.
 */
class JwtAuthMiddleware
{
    public function __construct(private RefreshTokenService $tokenService) {}

    public function handle(Request $request, Closure $next)
    {
        $token  = $request->bearerToken();
        $apiKey = $request->header('X-API-KEY');

        // ---------------------------------------------------------------- API key
        // Retained for the WP Connector embed contract (2026-05-11), but the
        // credential must now name its principal explicitly.
        if ($apiKey) {
            $resolved = app(ApiCredentialResolver::class)->resolve($apiKey);

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

            app(ApiCredentialResolver::class)->touch($resolved['api_key_id']);

            return $next($request);
        }

        // -------------------------------------------------------------------- JWT
        if (! $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $payload = $this->tokenService->decodeAccessToken($token);
        } catch (\Throwable) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        $user = User::find($payload->sub ?? null);
        if (! $user) {
            return response()->json(['error' => 'User not found'], 401);
        }

        $wsId = $payload->ws ?? null;

        if ($wsId) {
            // The claim must still reflect reality.
            $role = DB::table('workspace_users')
                ->where('user_id', (int) $user->id)
                ->where('workspace_id', (int) $wsId)
                ->value('role');

            if (! $role) {
                return response()->json([
                    'error' => 'You no longer have access to this workspace.',
                    'code'  => 'workspace_access_revoked',
                ], 403);
            }

            $request->attributes->set('workspace_role', $role);
        } else {
            // Stale token with no workspace claim. Resolve ONLY when unambiguous.
            $memberships = DB::table('workspace_users')
                ->where('user_id', (int) $user->id)
                ->orderBy('workspace_id')
                ->get(['workspace_id', 'role']);

            if ($memberships->count() === 1) {
                $wsId = (int) $memberships->first()->workspace_id;
                $request->attributes->set('workspace_role', $memberships->first()->role);
            } else {
                // Zero memberships, or more than one: never guess.
                return response()->json([
                    'error' => 'Your session does not specify a workspace. Please sign in again.',
                    'code'  => 'workspace_unresolved',
                ], 401);
            }
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('workspace_id', (int) $wsId);
        $request->attributes->set('auth_via', 'jwt');
        // Provenance of the token itself (e.g. the shared BELLA_ADMIN_TOKEN path),
        // so routes needing individual attribution can refuse it.
        $request->attributes->set('auth_via_claim', $payload->via ?? null);
        // b20 (2026-07-24) — which sign-in this token belongs to. Device push
        // registrations bind to it so signing out on one phone can revoke push
        // for that phone alone. Null for tokens minted before the claim existed.
        $request->attributes->set('session_id', isset($payload->sid) ? (int) $payload->sid : null);

        return $next($request);
    }
}
