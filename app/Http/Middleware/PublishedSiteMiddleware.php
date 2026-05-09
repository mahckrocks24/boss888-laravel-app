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

        // Platform house domains are NEVER builder-rendered — pass to Laravel/SPA.
        // Tenant builder render is for *.levelupgrowth.io subdomains and verified customer custom domains only.
        if (in_array($host, ['levelupgrowth.io', 'www.levelupgrowth.io'], true)) {
            return $next($request);
        }

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

        // Static template serve — bypass BuilderRenderer when a pre-rendered HTML
        // file exists for this site. Runs BEFORE the single-segment slug regex
        // so nested paths like /blog/{post}/ can be served from disk.
        // Path-traversal-safe: input restricted to alphanumerics + / - . _ and
        // explicitly rejects ".." segments.
        $website = $website ?? DB::table('websites')
            ->where('subdomain', $subdomain . '.levelupgrowth.io')
            ->where('status', 'published')
            ->first();

        // T3.2 Phase 4 — Blog gating: Growth+ plans only.
        // Workspace 1 (platform's own LevelUp Growth content) is exempt.
        // Triggers on /blog or /blog/<anything>; if the workspace plan does
        // not include content_writing, render404 (don't expose tenant's
        // blog content publicly when their plan doesn't pay for it).
        if ($website
            && (int) $website->workspace_id !== 1
            && (str_starts_with($slug, 'blog') || str_starts_with($path, 'blog/'))) {
            $sub = DB::table('subscriptions')
                ->where('workspace_id', $website->workspace_id)
                ->whereIn('status', ['active', 'trialing'])
                ->latest()
                ->first();
            $allowsBlog = false;
            if ($sub) {
                $plan = DB::table('plans')->where('id', $sub->plan_id)->first();
                if ($plan && $plan->features_json) {
                    $features = is_string($plan->features_json)
                        ? json_decode($plan->features_json, true)
                        : (array) $plan->features_json;
                    $allowsBlog = ! empty($features['content_writing']);
                }
            }
            if (! $allowsBlog) {
                return $this->render404();
            }
        }

        if ($website && preg_match('#^[a-z0-9/_\-.]*$#i', $path) && !str_contains($path, '..')) {
            $siteRoot = storage_path('app/public/sites/' . $website->id);
            $staticCandidates = array_unique([
                $siteRoot . '/' . $slug . '.html',
                $siteRoot . '/' . $slug . '/index.html',
                $siteRoot . '/' . $path . '/index.html',
                $siteRoot . '/' . $path . '.html',
            ]);
            foreach ($staticCandidates as $staticPath) {
                if (is_file($staticPath)) {
                    $html = file_get_contents($staticPath);
                    $html = $this->injectChatbotWidget($html, (int) ($website->workspace_id ?? 0), (int) $website->id);
                    return response($html, 200)
                        ->header('Content-Type', 'text/html; charset=utf-8')
                        ->header('Cache-Control', 'public, max-age=300, s-maxage=300')
                        ->header('X-Served-By', 'static-template');
                }
            }
        }

        // Validate slug format (only single-segment slugs reach BuilderRenderer)
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

        $html = $this->injectChatbotWidget($html, (int) ($website->workspace_id ?? 0), (int) ($website->id ?? 0));

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300, s-maxage=300');
    }

    /**
     * Inject the chatbot bootstrap script before </body> when the workspace
     * has the chatbot enabled. Runs AFTER the cached HTML is retrieved so a
     * settings toggle takes effect without needing to bust the page cache.
     */
    private function injectChatbotWidget(string $html, int $workspaceId, int $websiteId): string
    {
        if ($workspaceId <= 0) return $html;
        if (str_contains($html, 'chatbot.js?ws=')) return $html; // already injected

        // Cheap workspace-scoped lookup, cached 60s so we don't hit the DB
        // on every page render.
        $key = "chatbot_enabled_ws_{$workspaceId}";
        $enabled = Cache::remember($key, 60, function () use ($workspaceId) {
            $row = DB::table('chatbot_settings')->where('workspace_id', $workspaceId)->first();
            return (bool) ($row && $row->enabled);
        });
        if (! $enabled) return $html;

        // Use the staging/production platform domain explicitly. config('app.url')
        // can return the bare IP on this droplet which would cause mixed-content
        // and CORS failures from tenant subdomains.
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
        $origin = (str_contains($appHost, 'levelupgrowth.io'))
            ? 'https://' . ($appHost ?: 'staging.levelupgrowth.io')
            : 'https://staging.levelupgrowth.io';
        $tag = '<script src="' . $origin . '/chatbot.js?ws=' . $workspaceId . '" async></script>';

        $needle = '</body>';
        $pos = strripos($html, $needle);
        if ($pos === false) return $html . "\n" . $tag;
        return substr($html, 0, $pos) . $tag . substr($html, $pos);
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
