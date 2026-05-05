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
        $token = $request->bearerToken();

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
