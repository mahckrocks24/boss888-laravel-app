<?php

namespace App\Http\Middleware;

use App\Engines\Publisher\Services\DeskAudit;
use App\Engines\Publisher\Services\DeskService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PUBLISHER888 — resolves the desk website + the caller's desk role. Runs AFTER auth.jwt.
 * Website comes from the request host (PublishedSiteMiddleware stamps `published_website_id` on
 * tenant hosts), else from `X-Desk-Website` / `?website_id`. Must belong to the JWT workspace and
 * be on a theme that ships a desk. Unit 2: request id, audit context, host/header agreement,
 * API-key callers refused (the desk is a human surface), security headers on every response.
 */
class DeskContext
{
    public function __construct(private DeskService $desk, private DeskAudit $audit) {}

    public function handle(Request $request, Closure $next)
    {
        $requestId = DeskAudit::newRequestId($request->header('X-Request-Id'));
        $request->attributes->set('desk_request_id', $requestId);
        $deny = function (int $code, string $error, ?string $message = null) use ($requestId) {
            return response()->json(array_filter(['success' => false, 'error' => $error, 'message' => $message, 'request_id' => $requestId]), $code)->header('X-Request-Id', $requestId);
        };

        $wsId = (int) $request->attributes->get('workspace_id');
        $userId = (int) ($request->user()?->id ?? $request->attributes->get('user_id') ?? 0);
        if ($wsId <= 0 || $userId <= 0) return $deny(401, 'UNAUTHENTICATED');
        if ($request->attributes->get('auth_via') === 'api_key') return $deny(403, 'DESK_HUMAN_ONLY', 'The desk accepts signed-in people, not API keys.');

        $hostWid = (int) $request->attributes->get('published_website_id');
        $hdrWid = (int) ($request->header('X-Desk-Website') ?: $request->query('website_id') ?: 0);
        if ($hostWid > 0 && $hdrWid > 0 && $hostWid !== $hdrWid) return $deny(400, 'DESK_WEBSITE_MISMATCH', 'The requested website does not match this host.');
        $wid = $hostWid ?: $hdrWid;
        if ($wid <= 0) return $deny(400, 'DESK_WEBSITE_REQUIRED', 'Open the desk from the site host or send X-Desk-Website.');

        $website = DB::table('websites')->where('id', $wid)->whereNull('deleted_at')->first();
        if (!$website || (int) $website->workspace_id !== $wsId) return $deny(404, 'DESK_WEBSITE_NOT_FOUND');
        if (!DeskService::themeHasDesk($website)) return $deny(404, 'DESK_NOT_AVAILABLE', "This site's theme has no publisher desk.");

        $role = $this->desk->resolveRole($wsId, $wid, $userId, $request->attributes->get('workspace_role'));
        if ($role === null) return $deny(403, 'DESK_ACCESS_DENIED');

        $request->attributes->set('desk_website', $website);
        $request->attributes->set('desk_role', $role);
        $this->audit->setContext(['workspace_id' => $wsId, 'website_id' => $wid, 'user_id' => $userId, 'role' => $role, 'ip' => $request->ip(), 'user_agent' => $request->userAgent(), 'request_id' => $requestId, 'session_id' => $request->attributes->get('session_id')]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
