<?php

namespace App\Http\Middleware;

use App\Core\Auth\RefreshTokenService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Server-side identity for admin PAGE requests.
 *
 * The admin console authenticates its API calls with a bearer token from
 * localStorage. A browser document request carries no such header, so until now
 * the server rendered every admin page without knowing who asked — and the
 * "auth guard" was a line of JavaScript the anonymous visitor had already
 * downloaded.
 *
 * NOT A SECOND CREDENTIAL. The cookie carries the SAME access token the API
 * already trusts, decoded by the SAME service, so there is one issuer and one
 * decoder. A separate Laravel auth identity would be a second source of truth
 * that could disagree with the first — and the disagreement would be discovered
 * by whoever ended up with more access than they should have.
 *
 * NOT WIRED BY THIS SPRINT'S FIRST STEPS. It is inert until it is attached to
 * the admin route group, which is the one change that forces a re-login.
 */
final class AdminSessionIdentity
{
    /** The cookie the login endpoint sets alongside its unchanged JSON. */
    public const COOKIE = 'lu_admin_at';

    /**
     * The path the cookie is scoped to.
     *
     * A CONSTANT BECAUSE DELETION MUST MATCH ISSUANCE. A cookie is identified by
     * name, domain AND path; a "forget" cookie written to / does not remove one
     * living at /admin, it just adds a second, empty cookie the browser never
     * sends. Every withoutCookie() call in this codebase did exactly that until
     * 2026-08-04, so no stale admin cookie was ever actually cleared — not on
     * logout, and not on the refusals below.
     */
    public const PATH = '/admin';

    /**
     * The cookie that removes the cookie, with every attribute matched.
     *
     * One place, so issuance and deletion cannot drift apart again.
     */
    public static function forgetCookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie(
            self::COOKIE,
            '',
            -2628000,   // long expired
            self::PATH,
            null,       // domain — current host, as issued
            true,       // secure
            true,       // httpOnly
            false,
            'Lax'
        );
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || trim($token) === '') {
            return $this->reauthenticate($request);
        }

        try {
            $payload = app(RefreshTokenService::class)->decodeAccessToken($token);
        } catch (\Throwable) {
            // Tampered, expired, or signed with a key we no longer honour. The
            // reason is never returned; it would tell an attacker which of the
            // three they achieved.
            return $this->reauthenticate($request);
        }

        $user = User::find($payload->sub ?? null);

        // Every reason the identity may have stopped being valid since the
        // token was issued. A token is a claim about the past; the database is
        // the present, and the present wins.
        if ($user === null
            || ! ($user->is_platform_admin ?? false)
            || strtolower((string) ($user->status ?? 'active')) !== 'active') {
            return $this->reauthenticate($request);
        }

        // The session behind the token must still be live.
        //
        // Without this, signing out was not terminal: the cookie came off the
        // browser but the token itself stayed cryptographically valid for the
        // rest of its 12 hours, so any copy of it still opened the console. The
        // sessions table is the server's own record of which sign-ins are still
        // in force, and logout revokes the row.
        //
        // A token carrying no `sid` predates the claim (b20) or was minted by an
        // internal caller; those are allowed through exactly as before, so this
        // check is additive rather than a new way to be locked out.
        if (isset($payload->sid)) {
            $live = \Illuminate\Support\Facades\DB::table('sessions')
                ->where('id', (int) $payload->sid)
                ->whereNull('revoked_at')
                ->exists();

            if (! $live) {
                return $this->reauthenticate($request);
            }
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_via', 'jwt_cookie');
        $request->attributes->set('auth_via_claim', $payload->via ?? null);
        $request->attributes->set('session_id', isset($payload->sid) ? (int) $payload->sid : null);

        return $next($request);
    }

    /**
     * One consistent answer for every unauthenticated page request.
     *
     * A redirect rather than a 404: this is the shell boundary, the caller is a
     * browser, and sending it somewhere useful is the correct behaviour. The
     * 404 convention belongs to hidden MODULES — an authenticated admin asking
     * for a page they may not see gets 404 from the registry, which is a
     * different question with a different answer.
     *
     * The stale cookie is cleared on the way out so a browser holding an
     * expired token does not loop.
     */
    private function reauthenticate(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401)
                ->withCookie(self::forgetCookie());
        }

        return redirect()->guest(route('admin.login'))->withCookie(self::forgetCookie());
    }
}
