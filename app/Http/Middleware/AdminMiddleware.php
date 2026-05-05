<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if user is platform admin (is_platform_admin flag on users table)
        if (! $user->is_platform_admin) {
            return response()->json(['error' => 'Platform admin access required'], 403);
        }

        return $next($request);
    }
}
