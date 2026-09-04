<?php

namespace App\Http\Middleware;

use App\Engines\Publisher\Services\DeskService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PUBLISHER888 Unit 1 — resolves the desk website + the caller's desk role. Runs AFTER auth.jwt.
 * Website comes from the request host (PublishedSiteMiddleware stamps `published_website_id` on
 * tenant hosts), else from `X-Desk-Website` / `?website_id`. Must belong to the JWT workspace and
 * be on a theme that ships a desk.
 */
class DeskContext
{
    public function __construct(private DeskService $desk) {}

    public function handle(Request $request, Closure $next)
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        $userId = (int) ($request->user()?->id ?? $request->attributes->get('user_id') ?? 0);
        if ($wsId <= 0 || $userId <= 0) return response()->json(['success' => false, 'error' => 'UNAUTHENTICATED'], 401);

        $wid = (int) ($request->attributes->get('published_website_id') ?: $request->header('X-Desk-Website') ?: $request->query('website_id') ?: 0);
        if ($wid <= 0) return response()->json(['success' => false, 'error' => 'DESK_WEBSITE_REQUIRED', 'message' => 'Open the desk from the site host or send X-Desk-Website.'], 400);

        $website = DB::table('websites')->where('id', $wid)->whereNull('deleted_at')->first();
        if (!$website || (int) $website->workspace_id !== $wsId) return response()->json(['success' => false, 'error' => 'DESK_WEBSITE_NOT_FOUND'], 404);
        if (!DeskService::themeHasDesk($website)) return response()->json(['success' => false, 'error' => 'DESK_NOT_AVAILABLE', 'message' => 'This site\'s theme has no publisher desk.'], 404);

        $role = $this->desk->resolveRole($wsId, $wid, $userId, $request->attributes->get('workspace_role'));
        if ($role === null) return response()->json(['success' => false, 'error' => 'DESK_ACCESS_DENIED'], 403);

        $request->attributes->set('desk_website', $website);
        $request->attributes->set('desk_role', $role);
        return $next($request);
    }
}
