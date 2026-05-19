<?php

namespace App\Http\Middleware;

use App\Support\AgentNameScrub;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wave 36a — Apply AgentNameScrub to all JSON responses in the WP
 * connector group. In the WP context there is exactly one AI identity:
 * "AI SEO Assistant". No agent names leak through.
 */
class ConnectorBrandFilter
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only touch JSON responses — leave file downloads, redirects, etc. alone.
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $data = AgentNameScrub::scrubArray($data);
                $response->setData($data);
            } elseif (is_string($data)) {
                $response->setData(AgentNameScrub::scrub($data));
            }
        }

        return $response;
    }
}