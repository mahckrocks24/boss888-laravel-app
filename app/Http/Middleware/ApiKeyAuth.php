<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ApiKeyAuth — workspace-scoped API key authentication for inbound integrations
 * (e.g. the LUSEO WP Connector plugin v1.0.5).
 *
 * Reads key from `X-API-KEY` header or `?api_key=` query param. Resolves the
 * workspace from `api_keys.workspace_id` and attaches to the request the same
 * way JwtAuthMiddleware does. Bumps `last_used_at` on every authenticated call.
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-API-KEY') ?? $request->query('api_key');

        if (! $key) {
            return response()->json(['error' => 'api_key_required'], 401);
        }

        $record = DB::table('api_keys')
            ->where('key', $key)
            ->where('is_active', true)
            ->first();

        if (! $record) {
            return response()->json(['error' => 'invalid_api_key'], 403);
        }

        if ($record->expires_at && now()->isAfter($record->expires_at)) {
            return response()->json(['error' => 'api_key_expired'], 403);
        }

        // Attach workspace_id (same contract as JwtAuthMiddleware so downstream
        // queries that read $request->attributes->get('workspace_id') work).
        $request->attributes->set('workspace_id', $record->workspace_id);
        $request->attributes->set('api_key_id', $record->id);

        // Bump last_used_at (fire-and-forget — failure here must not block the request)
        try {
            DB::table('api_keys')->where('id', $record->id)->update(['last_used_at' => now()]);
        } catch (\Throwable $e) {
            // swallow — observability handled elsewhere
        }

        return $next($request);
    }
}
