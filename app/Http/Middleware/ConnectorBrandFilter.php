<?php

namespace App\Http\Middleware;

use App\Support\AgentNameScrub;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wave 36a (+36a.1) — Apply AgentNameScrub to JSON responses when the
 * request is coming from a WP-plugin / connector context.
 *
 * Signal: presence of X-API-KEY header. The WP plugin always uses
 * X-API-KEY auth; the Laravel app shell always uses JWT bearer. This
 * lets us attach the middleware to BOTH route groups (connector and
 * auth.jwt) safely — JWT-only callers keep seeing agent names, X-API-KEY
 * callers get the unified "AI SEO Assistant" identity.
 */
class ConnectorBrandFilter
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only scrub WP-plugin requests (X-API-KEY auth).
        if ($request->header('X-API-KEY') === null) {
            return $response;
        }

        // Only touch JSON responses — leave file downloads, redirects alone.
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $response->setData(AgentNameScrub::scrubArray($data));
            } elseif (is_string($data)) {
                $response->setData(AgentNameScrub::scrub($data));
            }
        }

        return $response;
    }
}