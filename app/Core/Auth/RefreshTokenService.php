<?php

namespace App\Core\Auth;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Session;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;

class RefreshTokenService
{
    private string $jwtSecret;
    private int $accessTtl;
    private int $refreshTtl;

    public function __construct()
    {
        $this->jwtSecret = config('app.jwt_secret', config('app.key'));
        // FIX 2026-04-11: honor JWT_TTL / JWT_REFRESH_TTL env instead of hardcoded 15min
        $this->accessTtl  = (int) env('JWT_TTL', 43200);
        $this->refreshTtl = (int) env('JWT_REFRESH_TTL', 2592000);
    }

    /**
     * $via records HOW this session was established (e.g. 'shared_admin_token').
     * It is persisted on the SERVER-SIDE session so it survives rotation, and is
     * never accepted from client input.
     */
    public function issueTokenPair(User $user, ?Workspace $workspace, ?string $ip = null, ?string $ua = null, ?string $via = null): array
    {
        $refreshToken = Str::random(64);

        // b20 — the session is created FIRST so its id can be stamped into the
        // access token as `sid`. That claim is what lets device registrations
        // bind to a specific sign-in, so logging out on one phone can revoke
        // push for that phone alone.
        $session = Session::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace?->id,
            'auth_via' => $via,
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'ip_address' => $ip,
            'user_agent' => $ua,
            'expires_at' => now()->addSeconds($this->refreshTtl),
        ]);

        return [
            'access_token' => $this->issueAccessToken($user, $workspace, $via, (int) $session->id),
            'refresh_token' => $refreshToken,
            'session_id' => (int) $session->id,
        ];
    }

    /**
     * $via records HOW the token was obtained (e.g. 'shared_admin_token').
     * Optional and additive: existing callers keep the original claim set, so no
     * previously issued token is invalidated.
     */
    public function issueAccessToken(User $user, ?Workspace $workspace = null, ?string $via = null, ?int $sessionId = null): string
    {
        $payload = [
            'sub' => $user->id,
            'ws' => $workspace?->id,
            'iat' => time(),
            'exp' => time() + $this->accessTtl,
        ];

        if ($via !== null) {
            $payload['via'] = $via;
        }

        // b20 — session binding. Additive and optional: tokens minted without it
        // (internal/service callers) behave exactly as before.
        if ($sessionId !== null) {
            $payload['sid'] = $sessionId;
        }

        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    public function decodeAccessToken(string $token): object
    {
        return JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
    }

    public function validateAndRotate(string $refreshToken): array
    {
        $hash = hash('sha256', $refreshToken);
        $session = Session::where('refresh_token_hash', $hash)->first();

        if (! $session || ! $session->isValid()) {
            // 2026-06-08 — refresh-token reuse handling. Single-use rotating
            // tokens + the mobile app firing refreshes concurrently (separate
            // code paths, multiple devices) means the LOSER of a benign race
            // presents a just-rotated token. The original code treated ANY
            // reuse as token theft and nuked EVERY session for the user — which
            // logged them out everywhere (the repeated-logout bug). Only treat
            // reuse of a token revoked OUTSIDE a short grace window as a real
            // stolen-token replay; a token revoked seconds ago is a race, so we
            // just reject THIS request — the client's winning refresh already
            // holds valid tokens, so the account stays signed in.
            $graceSeconds = 60;
            if ($session && $session->revoked_at
                && $session->revoked_at->lt(now()->subSeconds($graceSeconds))) {
                Session::where('user_id', $session->user_id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }
            abort(401, 'Invalid refresh token');
        }

        $session->update(['revoked_at' => now()]);

        $newRefreshToken = Str::random(64);
        $newSession = Session::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            // Provenance is inherited from the rotated session. A restricted
            // session must never become unrestricted by refreshing.
            'auth_via' => $session->auth_via,
            'refresh_token_hash' => hash('sha256', $newRefreshToken),
            'ip_address' => $session->ip_address,
            'user_agent' => $session->user_agent,
            'expires_at' => now()->addSeconds($this->refreshTtl),
        ]);

        // b20 — CARRY THE DEVICE LINK ACROSS ROTATION.
        //
        // Refreshing revokes the old session and mints a new one, so a device
        // bound to the old id would look signed-out within one refresh cycle and
        // its push would go silent — the opposite failure to the one we just
        // fixed. Re-point the registration at the successor session so the link
        // follows the device for as long as it stays signed in.
        \Illuminate\Support\Facades\DB::table('device_tokens')
            ->where('session_id', $session->id)
            ->update(['session_id' => $newSession->id, 'updated_at' => now()]);

        return [
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'auth_via' => $session->auth_via,
            'refresh_token' => $newRefreshToken,
            'session_id' => (int) $newSession->id,
        ];
    }

    public function revoke(string $refreshToken): void
    {
        $hash = hash('sha256', $refreshToken);
        Session::where('refresh_token_hash', $hash)->update(['revoked_at' => now()]);
    }
}
