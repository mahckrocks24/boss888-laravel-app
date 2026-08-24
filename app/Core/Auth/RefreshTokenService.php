<?php

namespace App\Core\Auth;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Session;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RefreshTokenService
{
    /**
     * How long a just-rotated refresh token may still be replayed by a client
     * that was holding an older copy of it. See validateAndRotate().
     */
    private const REPLAY_WINDOW_SECONDS = 60;
    private const REPLAY_CACHE_PREFIX   = 'auth:refresh_replay:';

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
        $hash      = hash('sha256', $refreshToken);
        $replayKey = self::REPLAY_CACHE_PREFIX . $hash;

        return DB::transaction(function () use ($hash, $replayKey) {
            // 2026-08-02 — lockForUpdate serialises concurrent refreshes of the
            // SAME token. Without it two simultaneous calls both passed the
            // validity check below and each minted a session, forking one
            // sign-in into two independent chains (reproduced on staging: one
            // token produced sessions 1390 AND 1391).
            $session = Session::where('refresh_token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $session || ! $session->isValid()) {
                // 2026-08-02 — BENIGN REPLAY, NOT THEFT.
                //
                // Every browser tab and every device keeps its own copy of the
                // refresh token in localStorage, and the SPA rotates on each
                // bootstrap. A client presenting a copy the winner already
                // rotated is the ordinary case, not an attack. Serve it the
                // SAME successor pair the winner received so it stays signed in
                // rather than being bounced to the login screen.
                //
                // This REPLACES the previous reuse handling, which revoked
                // EVERY session belonging to the user whenever a token was
                // replayed more than 60s after rotation. That branch fired 36
                // times on real traffic and destroyed 336 sessions — it was the
                // direct cause of the "signed out everywhere" reports, and no
                // occurrence corresponded to an actual stolen token. A rejected
                // refresh now affects ONLY the request that made it.
                $replay = $this->readReplay($replayKey);
                if ($replay !== null) {
                    Log::info('[Auth] refresh replay served inside window', [
                        'user_id'    => $session?->user_id,
                        'session_id' => $replay['session_id'] ?? null,
                    ]);

                    return $replay;
                }

                Log::info('[Auth] refresh token rejected (no replay entry)', [
                    'user_id'     => $session?->user_id,
                    'had_session' => (bool) $session,
                    'revoked_at'  => $session?->revoked_at?->toIso8601String(),
                    'expired'     => $session ? ! $session->expires_at->isFuture() : null,
                ]);
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
            DB::table('device_tokens')
                ->where('session_id', $session->id)
                ->update(['session_id' => $newSession->id, 'updated_at' => now()]);

            $result = [
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'auth_via' => $session->auth_via,
                'refresh_token' => $newRefreshToken,
                'session_id' => (int) $newSession->id,
            ];

            // Written INSIDE the transaction deliberately: a caller blocked on
            // the row lock above resumes only once this commits, so the entry is
            // guaranteed to be visible by the time it looks for one.
            $this->writeReplay($replayKey, $result);

            return $result;
        });
    }

    /**
     * The replay payload carries a live refresh token, so it is encrypted at
     * rest and kept only for REPLAY_WINDOW_SECONDS. The sessions table stores
     * hashes only by design; this cache entry is the single place a plaintext
     * refresh token exists, and it is short-lived, node-local and encrypted.
     */
    private function writeReplay(string $key, array $result): void
    {
        try {
            Cache::put($key, Crypt::encrypt($result), self::REPLAY_WINDOW_SECONDS);
        } catch (\Throwable $e) {
            // A cache failure must never break an otherwise valid refresh.
            Log::warning('[Auth] could not write refresh replay entry', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function readReplay(string $key): ?array
    {
        try {
            $raw = Cache::get($key);
            if (! is_string($raw) || $raw === '') {
                return null;
            }
            $decoded = Crypt::decrypt($raw);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning('[Auth] could not read refresh replay entry', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function revoke(string $refreshToken): void
    {
        $hash = hash('sha256', $refreshToken);
        Session::where('refresh_token_hash', $hash)->update(['revoked_at' => now()]);
    }
}
