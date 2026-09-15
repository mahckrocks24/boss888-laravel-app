<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Core\Auth\AuthService;
use App\Core\Auth\WorkspaceSwitchService;

class AuthController
{
    public function __construct(
        private AuthService $authService,
        private WorkspaceSwitchService $switchService,
    ) {}

    public function register(Request $request): JsonResponse
    {
        // MISSION-018 WS launch-switch (2026-08-24): the pre-launch signup gate
        // is now driven by config('marketing.public_launched') — the flag the
        // whole launch is premised on, which until now was read by NO code, so
        // flipping PLATFORM_PUBLIC_LAUNCHED=true changed nothing. Semantics:
        // once launched, public signups are open everywhere; before launch they
        // are closed on the public marketing hostnames but stay open on staging
        // and other hosts for testing (preserving the 2026-05-11 behaviour).
        // This is the actual launch switch: flip the flag and signups open.
        $launched = (bool) config('marketing.public_launched');
        $onPublicHost = in_array($request->getHost(), ['levelupgrowth.io', 'www.levelupgrowth.io'], true);
        if (! $launched && $onPublicHost) {
            abort(403, 'Signups are temporarily disabled.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
            ],
            'workspace_name' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:120',   // EV-1043: from the sign-up page
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter and one number.',
            'password.confirmed' => 'Passwords do not match.',
        ]);

        $result = $this->authService->register($data);

        // T_NOTIF — notify platform admin (user_id=1) of new signup. Wrapped in try/catch
        // so a notification failure never blocks user registration.
        try {
            app(\App\Core\Notifications\NotificationService::class)->dispatch(
                type: \App\Core\Notifications\NotificationTypes::SYSTEM_USER_SIGNUP,
                userId: 1,
                title: 'New user registered',
                body: "{$data['email']} just signed up.",
                severity: 'info'
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('User signup notification failed', ['error' => $e->getMessage()]);
        }

        return response()->json($result, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $result = $this->authService->login(
            $request->input('email'),
            $request->input('password'),
            $request->ip(),
            $request->userAgent(),
        );

        // PLATFORM SECURITY 1.0 — server-side identity for admin PAGE requests.
        //
        // The JSON response is unchanged; the client keeps storing its token
        // exactly as before. This adds the SAME access token as an HttpOnly
        // cookie so that a browser document request to /admin/* carries an
        // identity the server can read. Until then the server rendered every
        // admin page without knowing who asked, and the only guard was a line
        // of JavaScript the anonymous visitor had already downloaded.
        //
        // Not a second credential: one issuer, one decoder, two transports.
        return $this->withAdminIdentityCookie(response()->json($result), $result);
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => 'required|string']);
        // ADMIN COOKIE ON REFRESH (2026-09-11): the page-identity cookie must follow the token it carries, or
        // server-rendered admin pages start answering 302 twelve hours after login while the console works on.
        $result = $this->authService->refresh($request->input('refresh_token'));
        return $this->withAdminIdentityCookie(response()->json($result), $result);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => 'required|string']);
        $this->authService->logout($request->input('refresh_token'));

        // The page-identity cookie dies with the session that issued it.
        // Forgetting this would leave a browser able to render admin pages
        // after the user believed they had signed out.
        // withoutCookie() defaults the forget-cookie's path to "/", which cannot
        // remove a cookie scoped to /admin — the browser simply keeps both. The
        // deletion must carry the same attributes as the issuance.
        return response()->json(['message' => 'Logged out'])
            ->withCookie(\App\Http\Middleware\AdminSessionIdentity::forgetCookie());
    }

    /**
     * Attach the admin page-identity cookie to a login response.
     *
     * Scoped to /admin so it is never sent with API or marketing requests.
     * HttpOnly puts it beyond JavaScript's reach, which is strictly better than
     * the localStorage token it sits alongside. Its lifetime is read from the
     * token's own `exp` claim rather than a second configured value — two
     * lifetimes would eventually disagree, and the one that outlived the other
     * would be the security hole.
     *
     * Failing to attach it is never fatal: the JSON contract the client depends
     * on is already built, and a login that succeeds must not be turned into a
     * failure by a cookie problem.
     */
    private function withAdminIdentityCookie(JsonResponse $response, mixed $result): JsonResponse
    {
        try {
            $payload = is_array($result) ? $result : (array) $result;
            $token = $payload['access_token'] ?? $payload['token'] ?? ($payload['data']['access_token'] ?? null);

            if (! is_string($token) || substr_count($token, '.') !== 2) {
                return $response;
            }

            $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')) ?: '{}', true);
            $seconds = max(60, (int) (($claims['exp'] ?? 0) - time()));

            return $response->cookie(
                \App\Http\Middleware\AdminSessionIdentity::COOKIE,
                $token,
                (int) ceil($seconds / 60),
                \App\Http\Middleware\AdminSessionIdentity::PATH, // never sent to the API or marketing site
                null,          // domain — current host only
                true,          // secure
                true,          // httpOnly — JavaScript must not be able to read it
                false,
                'Lax'          // allows top-level navigation, blocks cross-site POST
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('admin identity cookie not attached', [
                'error' => $e->getMessage(),
            ]);

            return $response;
        }
    }

    public function me(Request $request): JsonResponse
    {
        // Carry the request's resolved workspace through. JwtAuthMiddleware has
        // already decoded the token's `ws` claim and checked it against live
        // membership, so it is the single authoritative answer to "which
        // workspace am I in?" — me() must not re-derive it from the membership
        // list, which is what let the UI name a different tenant than the one
        // the API was scoped to.
        $activeWs = $request->attributes->get('workspace_id');

        return response()->json($this->authService->me(
            $request->user(),
            is_numeric($activeWs) ? (int) $activeWs : null,
        ));
    }

    public function switchWorkspace(Request $request): JsonResponse
    {
        $request->validate(['workspace_id' => 'required|integer']);
        $result = $this->switchService->switchWorkspace(
            $request->user(),
            $request->input('workspace_id'),
        );
        return response()->json($result);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        return response()->json(
            $this->authService->forgotPassword($request->input('email'))
        );
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        return response()->json(
            $this->authService->resetPassword(
                $request->input('token'),
                $request->input('password')
            )
        );
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
        ]);

        $user = $request->user();

        // Check email uniqueness if changing email
        if (isset($data['email']) && $data['email'] !== $user->email) {
            $exists = \App\Models\User::where('email', $data['email'])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'Email already in use'], 422);
            }
        }

        $user->update($data);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! \Illuminate\Support\Facades\Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        $user->update([
            'password' => \Illuminate\Support\Facades\Hash::make($request->input('password')),
        ]);

        return response()->json(['message' => 'Password changed successfully']);
    }
}
