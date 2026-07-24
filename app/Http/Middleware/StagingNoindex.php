<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MW-1a — SEO isolation. Returns `X-Robots-Tag: noindex, nofollow` on the
 * staging host (and direct server IP) so pre-launch / in-development pages can
 * be crawled but never indexed. Crawlable + noindex is the correct combination
 * to keep staging out of search results (a Disallow would leave stale URLs
 * indexable without content).
 *
 * Scoped to the EXACT staging/IP hosts only — customer published sites on
 * *.levelupgrowth.io and the production marketing hosts are never touched.
 */
class StagingNoindex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($request->getHost(), config('marketing.noindex_hosts', []), true)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
