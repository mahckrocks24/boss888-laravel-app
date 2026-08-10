<?php

namespace App\Http\Controllers\Admin;

use App\Core\Auth\RefreshTokenService;
use App\Http\Middleware\AdminSessionIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ending an admin session, server-side.
 *
 * WHY THIS EXISTS.
 * The console's Sign out cleared two localStorage keys and navigated away. That
 * ended nothing: the HttpOnly lu_admin_at cookie is what grants page access, and
 * JavaScript cannot clear it. A "signed out" browser kept receiving the full
 * admin shell — window.ADMIN_PAGES and all — for the remaining 12 hours of the
 * token's life. Found in a live browser on 2026-08-04, after a test suite that
 * called the API endpoint directly had reported logout working.
 *
 * The API route /api/auth/logout cannot serve the console: it requires a refresh
 * token in the body, and the admin console never stores one — it keeps only the
 * access token. So the console needs a route that ends a session using nothing
 * but the cookie the browser already holds.
 *
 * POST, NEVER GET. A GET that destroys a session can be fired by any image tag
 * on any page on the internet. The web group's CSRF validation applies here, so
 * only the console's own origin can call it.
 *
 * DELIBERATELY UNGATED. It carries no AdminSessionIdentity middleware. Gating it
 * would mean an expired or already-cleared session could not sign out — the one
 * moment the route matters most. Anonymous callers get the same redirect as
 * everyone else and learn nothing: no identity is confirmed or denied, no state
 * is disclosed, and nothing happens that had not already happened.
 */
class AdminLogoutController
{
    public function logout(Request $request)
    {
        $this->revokeSessionBehind($request);

        // Always the named route, never anything the caller supplied. An open
        // redirect on a logout endpoint is a phishing primitive: the victim
        // signs out and lands wherever the attacker chose.
        return redirect()
            ->to(route('admin.login'))
            ->withCookie(AdminSessionIdentity::forgetCookie());
    }

    /**
     * Revoke the server-side session this cookie belongs to, when it names one.
     *
     * The cookie is a bearer of a signed claim; the sessions table is the
     * server's own record. Clearing the cookie stops this browser. Revoking the
     * session stops every copy of that token, which is the difference between
     * signing out and merely forgetting.
     *
     * Failure is never fatal. A logout that errors is a logout that did not
     * happen, and the cookie must come off regardless.
     */
    private function revokeSessionBehind(Request $request): void
    {
        try {
            $token = $request->cookie(AdminSessionIdentity::COOKIE);

            if (! is_string($token) || trim($token) === '') {
                return;
            }

            $payload = app(RefreshTokenService::class)->decodeAccessToken($token);
            $sessionId = isset($payload->sid) ? (int) $payload->sid : null;

            if ($sessionId === null) {
                return;
            }

            DB::table('sessions')->where('id', $sessionId)->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Never the token, never the cookie, never the user's identity —
            // only that a revocation attempt did not complete.
            Log::warning('admin logout could not revoke the server session', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
