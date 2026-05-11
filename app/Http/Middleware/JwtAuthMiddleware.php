<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Auth\RefreshTokenService;
use App\Models\User;

class JwtAuthMiddleware
{
    public function __construct(private RefreshTokenService $tokenService) {}

    public function handle(Request $request, Closure $next)
    {
        $token  = $request->bearerToken();
        $apiKey = $request->header('X-API-KEY');

        // PATCH (2026-05-11): embed-mode fallback — accept X-API-KEY as
        // alternative auth so the WP Connector iframe's SPA can call any
        // route the SPA normally calls (JWT-protected) using just the
        // workspace-scoped API key. The api_keys row resolves both the
        // workspace and a user.
        if ($apiKey) {
            $record = \Illuminate\Support\Facades\DB::table('api_keys')
                ->where('key', $apiKey)
                ->where('is_active', true)
                ->first();
            if (! $record) {
                return response()->json(['error' => 'Invalid api_key'], 403);
            }
            if ($record->expires_at && now()->isAfter($record->expires_at)) {
                return response()->json(['error' => 'api_key_expired'], 403);
            }
            $user = $record->user_id
                ? \App\Models\User::find($record->user_id)
                : null;
            // If the key has no user, fall through to first workspace admin
            if (! $user) {
                $userId = \Illuminate\Support\Facades\DB::table('workspace_users')
                    ->where('workspace_id', $record->workspace_id)
                    ->orderBy('id')
                    ->value('user_id');
                if ($userId) $user = \App\Models\User::find($userId);
            }
            if (! $user) {
                return response()->json(['error' => 'No user bound to api_key'], 403);
            }
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('workspace_id', $record->workspace_id);
            $request->attributes->set('api_key_id', $record->id);
            // Bump last_used_at (fire-and-forget)
            try {
                \Illuminate\Support\Facades\DB::table('api_keys')
                    ->where('id', $record->id)->update(['last_used_at' => now()]);
            } catch (\Throwable $e) {}
            return $next($request);
        }

        if (! $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $payload = $this->tokenService->decodeAccessToken($token);
        } catch (\Throwable) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        $user = User::find($payload->sub);
        if (! $user) {
            return response()->json(['error' => 'User not found'], 401);
        }

        $request->setUserResolver(fn () => $user);
        $wsId = $payload->ws ?? null;
        // Resolve ws from workspace_users when JWT claim is null (stale tokens)
        if (!$wsId && ($payload->sub ?? null)) {
            $wsRow = \Illuminate\Support\Facades\DB::table('workspace_users')->where('user_id', (int) $payload->sub)->first();
            if ($wsRow) $wsId = $wsRow->workspace_id;
        }
        $request->attributes->set('workspace_id', $wsId);

        return $next($request);
    }
}
