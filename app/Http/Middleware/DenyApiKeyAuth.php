<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Rejects SHARED / UNATTRIBUTABLE credentials on routes that must be driven by an
 * identifiable person.
 *
 * INFRA888 control C6 + directive 1C §5. Infrastructure operations create
 * financial obligations and destroy customer resources, so every one of them must
 * be attributable to a real actor.
 *
 * Two credential classes are refused:
 *
 *  1. `api_key` — long-lived integration keys (e.g. embedded in a WordPress
 *     plugin). Even correctly bound, they represent a machine, not a person.
 *
 *  2. `shared_admin_token` — the legacy BELLA_ADMIN_TOKEN path
 *     (routes/api.php:91) trades a single static env secret for a JWT issued to
 *     hardcoded User::find(1), with the in-code justification "whoever has it is
 *     authorized". Every action taken through it attributes to user 1, so an
 *     audit row naming the real administrator is impossible. That is acceptable
 *     for read-only admin panels; it is not acceptable for approving, overriding,
 *     retrying, cancelling or force-transitioning infrastructure operations.
 *
 * A platform administrator may still perform INFRA888 admin actions — by signing
 * in as themselves, so the audit trail names them.
 *
 * Runs AFTER auth.jwt, which sets `auth_via` and `auth_via_claim`.
 */
class DenyApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->attributes->get('auth_via') === 'api_key') {
            return response()->json([
                'error' => 'Infrastructure actions require a signed-in user session.',
                'code'  => 'api_key_not_permitted',
            ], 403);
        }

        if ($request->attributes->get('auth_via_claim') === 'shared_admin_token') {
            return response()->json([
                'error' => 'Infrastructure actions require an individually authenticated administrator. '
                         . 'Please sign in with your own account.',
                'code'  => 'shared_admin_token_not_permitted',
            ], 403);
        }

        return $next($request);
    }
}
