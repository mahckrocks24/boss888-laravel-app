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

    public function issueTokenPair(User $user, ?Workspace $workspace, ?string $ip = null, ?string $ua = null): array
    {
        $accessToken = $this->issueAccessToken($user, $workspace);
        $refreshToken = Str::random(64);

        Session::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace?->id,
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'ip_address' => $ip,
            'user_agent' => $ua,
            'expires_at' => now()->addSeconds($this->refreshTtl),
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ];
    }

    public function issueAccessToken(User $user, ?Workspace $workspace = null): string
    {
        $payload = [
            'sub' => $user->id,
            'ws' => $workspace?->id,
            'iat' => time(),
            'exp' => time() + $this->accessTtl,
        ];

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
            if ($session && $session->revoked_at) {
                Session::where('user_id', $session->user_id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }
            abort(401, 'Invalid refresh token');
        }

        $session->update(['revoked_at' => now()]);

        $newRefreshToken = Str::random(64);
        Session::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'refresh_token_hash' => hash('sha256', $newRefreshToken),
            'ip_address' => $session->ip_address,
            'user_agent' => $session->user_agent,
            'expires_at' => now()->addSeconds($this->refreshTtl),
        ]);

        return [
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'refresh_token' => $newRefreshToken,
        ];
    }

    public function revoke(string $refreshToken): void
    {
        $hash = hash('sha256', $refreshToken);
        Session::where('refresh_token_hash', $hash)->update(['revoked_at' => now()]);
    }
}
