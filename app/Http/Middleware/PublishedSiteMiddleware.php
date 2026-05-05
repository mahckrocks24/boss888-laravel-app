<?php

namespace App\Http\Middleware;

use App\Engines\Builder\Services\BuilderRenderer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Intercepts requests to *.levelupgrowth.io subdomains (and verified custom domains)
 * and serves published websites. Runs early in the middleware stack.
 */
class PublishedSiteMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $host = $request->getHost();

        // Try subdomain match first
        $subdomain = null;
        $isCustomDomain = false;

        if (str_ends_with($host, '.levelupgrowth.io')) {
            $subdomain = explode('.', $host)[0] ?? '';

            // Skip known internal subdomains
            if (in_array($subdomain, ['staging', 'www', 'app', 'api', ''], true)) {
                return $next($request);
            }
        } else {
            // Check if this is a verified custom domain
            $website = DB::table('websites')
                ->where('custom_domain', $host)
                ->where('domain_verified', true)
                ->where('status', 'published')
                ->first();

            if ($website) {
                $subdomain = str_replace('.levelupgrowth.io', '', $website->subdomain ?? '');
                $isCustomDomain = true;
            } else {
                return $next($request);
            }
        }

        if (!$subdomain) {
            return $next($request);
        }

        // Extract slug from path
        $path = trim($request->getPathInfo(), '/');
        $slug = $path ?: 'home';

        /* T4: POST passthrough — let POSTs (form submissions, /book) reach the regular router */
        if ($request->isMethod('POST')) { return $next($request); }

        // ── Sitemap + Robots — handle before slug validation ────────
        if ($slug === 'sitemap.xml') {
            return $this->serveSitemap($subdomain);
        }
        if ($slug === 'robots.txt') {
            return $this->serveRobots($subdomain);
        }

                // Pass through API/admin/app requests
        if (str_starts_with($slug, 'api/') || str_starts_with($slug, 'admin/') || str_starts_with($slug, 'app/')) {
            return $next($request);
        }

        // Validate slug format
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return $next($request);
        }

        $cacheKey = "published_site:{$subdomain}:{$slug}";

        $html = Cache::remember($cacheKey, 300, function () use ($subdomain, $slug) {
            $renderer = app(BuilderRenderer::class);
            return $renderer->renderWebsite($subdomain, $slug);
        });

        if (!$html) {
            Cache::forget($cacheKey);
            return $this->render404();
        }

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300, s-maxage=300');
    }

    private function render404()
    {
        if (view()->exists('errors.site-not-found')) {
            return response()->view('errors.site-not-found', [], 404);
        }

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Page Not Found</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:#0B0E14;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center}
h1{font-family:'Syne',sans-serif;font-size:120px;font-weight:700;background:linear-gradient(135deg,#6C5CE7,#00E5A8);-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1}
p{color:rgba(255,255,255,.6);font-size:16px;margin-top:12px}
a{color:#6C5CE7;text-decoration:none;font-weight:600}a:hover{text-decoration:underline}
</style>
</head>
<body>
<div>
<h1>404</h1>
<p>This page doesn't exist yet.</p>
<p style="margin-top:24px"><a href="https://levelupgrowth.io">Build your own website with LevelUp</a></p>
</div>
</body>
</html>
HTML;
        return response($html, 404)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Serve sitemap.xml for a published website.
     */
    private function serveSitemap(string $subdomain): \Illuminate\Http\Response
    {
        $fullSub = $subdomain . '.levelupgrowth.io';
        $website = DB::table('websites')
            ->where('subdomain', $fullSub)
            ->where('status', 'published')
            ->first();

        if (!$website) {
            return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>', 404)
                ->header('Content-Type', 'application/xml');
        }

        $pages = DB::table('pages')
            ->where('website_id', $website->id)
            ->where('status', 'published')
            ->orderBy('position')
            ->get(['slug', 'updated_at']);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($pages as $page) {
            $loc = "https://{$fullSub}/" . ($page->slug === 'home' ? '' : $page->slug);
            $lastmod = $page->updated_at ? date('Y-m-d', strtotime($page->updated_at)) : date('Y-m-d');
            $priority = $page->slug === 'home' ? '1.0' : '0.8';
            $xml .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$lastmod}</lastmod>\n    <changefreq>weekly</changefreq>\n    <priority>{$priority}</priority>\n  </url>\n";
        }

        $xml .= '</urlset>';

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Serve robots.txt for a published website.
     */
    private function serveRobots(string $subdomain): \Illuminate\Http\Response
    {
        $fullSub = $subdomain . '.levelupgrowth.io';
        $content = "User-agent: *\nAllow: /\n\nSitemap: https://{$fullSub}/sitemap.xml\n";

        return response($content, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
