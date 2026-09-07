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

        // PUBLISHER888 Unit 1 (2026-09-04) — the Publisher Desk lives at /admin on the site's OWN host
        // (custom domain or tenant subdomain) for themes that ship a desk (DeskService::THEMES).
        // GET /admin[/…] returns the desk shell here, BEFORE Laravel routing, so the platform admin
        // panel (routes/web.php `admin` prefix) is never reached on such hosts. /api/* requests on the
        // same host get `published_website_id` stamped so DeskContext can resolve the site from the host.
        $deskPath = trim($request->getPathInfo(), '/');
        if ($deskPath === 'admin' || str_starts_with($deskPath, 'admin/') || $deskPath === 'api' || str_starts_with($deskPath, 'api/')) {
            $deskSite = $website ?? DB::table('websites')->where('subdomain', $subdomain . '.levelupgrowth.io')->where('status', 'published')->first();
            if ($deskSite && \App\Engines\Publisher\Services\DeskService::themeHasDesk($deskSite)) {
                $request->attributes->set('published_website_id', (int) $deskSite->id);
                if (!str_starts_with($deskPath, 'api') && in_array($request->method(), ['GET', 'HEAD'], true)) {
                    return app(\App\Engines\Publisher\Services\DeskService::class)->shell($deskSite, $request);
                }
            }
        }

        // 2026-05-23 FIX 40 — auto-redirect tenant subdomain requests
        // to the verified custom_domain so search engines see exactly
        // ONE canonical URL per page. Without this we ran two public
        // domains side-by-side (chef-red.levelupgrowth.io AND
        // chefredraymundo.com) serving identical content with a
        // canonical tag pointing at the custom domain — Google would
        // eventually dedupe but the window is weeks and bookmarks /
        // backlinks landing on the subdomain bypass the brand domain.
        // A 301 forces the merge immediately and protects link equity.
        //
        // Applies AUTOMATICALLY to every workspace where:
        //   websites.custom_domain IS NOT NULL
        //   websites.domain_verified = true
        //   websites.status = 'published'
        //
        // Admin tooling paths (/api, /admin, /app) are NEVER redirected
        // so the SEO dashboard and login flows stay reachable via the
        // subdomain (useful when a custom domain has a transient DNS
        // problem and the user needs to reach the dashboard).
        //
        // Slug-restricted exemption: the IndexNow key file (a 32-char
        // hex.txt) is hosted per origin and MUST remain reachable on
        // both hosts during the redirect transition so search engines
        // can verify ownership.
        if (!$isCustomDomain) {
            $reqPath = trim($request->getPathInfo(), '/');
            $isAdminPath = str_starts_with($reqPath, 'api/')
                        || str_starts_with($reqPath, 'admin/')
                        || str_starts_with($reqPath, 'app/');
            $isIndexNowKey = (bool) preg_match('/^[A-Fa-f0-9]{8,128}\.txt$/', $reqPath);
            if (!$isAdminPath && !$isIndexNowKey) {
                $w = DB::table('websites')
                    ->where('subdomain', $subdomain . '.levelupgrowth.io')
                    ->where('status', 'published')
                    ->whereNull('deleted_at')
                    ->first(['custom_domain', 'domain_verified']);
                if ($w
                    && !empty($w->custom_domain)
                    && (bool) ($w->domain_verified ?? false)) {
                    $cleanHost = strtolower(trim((string) $w->custom_domain, ' /'));
                    $target = 'https://' . $cleanHost . $request->getRequestUri();
                    return redirect($target, 301)
                        ->header('Cache-Control', 'public, max-age=86400')
                        ->header('X-Redirect-Reason', 'canonical-custom-domain');
                }
            }
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

        // 2026-05-23 FIX 25 — IndexNow key file. Search engines (Bing,
        // Yandex, DuckDuckGo) verify ownership by GETting {host}/{key}.txt
        // and expect the response body to equal the key. Without this we
        // cannot submit URLs to IndexNow for platform-hosted tenants.
        if (preg_match('/^([A-Fa-f0-9]{8,128})\.txt$/', $slug, $keyMatch)) {
            return $this->serveIndexNowKey($subdomain, $keyMatch[1]);
        }

        // Wave 46 — Answer Engine Optimization: serve llms.txt per tenant.
        // Convention: https://llmstxt.org — markdown index of canonical pages
        // for LLM-based search engines. Auto-regenerated daily; cached in
        // aeo_settings.llms_txt_cache.
        if ($slug === 'llms.txt') {
            return $this->serveLlmsTxt($subdomain);
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

        // Wave 48 — Log AI crawler / AI referrer traffic. Only when this is
        // a real page request (not robots/sitemap/llms.txt/api/admin paths,
        // already filtered above) and the workspace is resolvable.
        if ($website && isset($website->workspace_id)) {
            try {
                $fullUrl = 'https://' . $request->getHost() . '/' . ltrim($slug ?? '', '/');
                app(\App\Engines\SEO\Services\AeoTrafficLogger::class)
                    ->log($request, (int) $website->workspace_id, $website->id ?? null, $fullUrl);
            } catch (\Throwable $_aeoTrafErr) {
                // never block render
            }
        }

        // T3.2 Phase 4 — Blog gating: Growth+ plans only.
        // Workspace 1 (platform's own LevelUp Growth content) is exempt.
        // Triggers on /blog or /blog/<anything>; if the workspace plan does
        // not include content_writing, render404 (don't expose tenant's
        // blog content publicly when their plan doesn't pay for it).
        // PATCH (news_channel exemption, 2026-05-09) — news templates are
        // built around their blog/news feed by definition; gating it on a
        // separate content_writing plan flag would block their core
        // functionality. Skip the gate when the website is a news_channel.
        $isNewsChannel = $website && ($website->template_industry ?? null) === 'news_channel';
        if ($website
            && (int) $website->workspace_id !== 1
            && !$isNewsChannel
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
                    // Wave 63 — auto-inject DB articles into static blog index.
                    if (preg_match('#/blog/index\.html$|/blog\.html$#i', $staticPath)) {
                        $html = $this->injectDynamicBlogPosts($html, (int) ($website->workspace_id ?? 0), 0, false, (int) $website->id);
                    } elseif ($slug === 'home') {
                        // RISK-0101 — the home "From the Blog" preview: show up to 3
                        // recent real articles (blog-card branch only) instead of the
                        // empty-state line when the workspace has articles.
                        $html = $this->injectDynamicBlogPosts($html, (int) ($website->workspace_id ?? 0), 3, true, (int) $website->id);
                    }
                    // Wave 73b — guarantee related-articles internal links on every blog post.
                    if (preg_match('#/blog/[^/]+/(?:index\.html)?$#i', $staticPath) && !preg_match('#/blog/(?:index\.html)?$#i', $staticPath)) {
                        $html = $this->injectRelatedArticles($html, (int) ($website->workspace_id ?? 0), $staticPath);
                        $html = $this->stripDuplicateAeoFaq($html);
                        $html = $this->stripDuplicateBackLinks($html);
                        $html = $this->normalizeFaqSections($html);
                    }
                    $html = $this->injectBlogLinkStyling($html);
                    $html = $this->injectBookingForms($html); // LEAD-1
                    $html = $this->injectChatbotWidget($html, (int) ($website->workspace_id ?? 0), (int) $website->id);
                    $html = app(\App\Engines\Ads\Services\AdSlotInjector::class)->inject($html, (int) $website->id);
                    $html = $this->absolutizeSocialMeta($html, $website, (string) $slug);
                    $html = $this->injectLandmarks($html);
                    $html = $this->injectA11yNames($html);
                    $html = $this->injectFormLabels($html);
                    $html = $this->injectAccentContrast($html);
                    $html = $this->injectMobileNav($html);
                    return response($html, 200)
                        ->header('Content-Type', 'text/html; charset=utf-8')
                        ->header('Cache-Control', 'public, max-age=60, s-maxage=60')
                        ->header('X-Served-By', 'static-template');
                }
            }

            // Wave 64 — no static file matched. If the path is /blog/{slug}
            // and the slug corresponds to a published article in DB, render
            // it dynamically using an existing article static file as the
            // theme template (substituting title, image, body, meta).
            // RESUME888 — reserved slugs under /jobs are pages, not listings: /jobs/resume → pages.slug 'resume'.
            if (preg_match('#^jobs/(resume|post|saved)/?$#i', $path, $rm)) {
                $rHtml = app(\App\Engines\Builder\Services\BuilderRenderer::class)->renderWebsite($subdomain, strtolower($rm[1]));
                if ($rHtml !== null && $rHtml !== '') return response($rHtml, 200)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store')->header('X-Served-By', 'reserved-page');
            }
            // RESUME888 — signed download + magic link live in routes/web.php; let them through on the site host.
            if (str_starts_with($path, 'resume-download/') || str_starts_with($path, 'resume/continue/')) return $next($request);
            // KABAYAN888 JOBS-1 (2026-09-04) — /jobs/{slug} → a job listing page (renderer sites).
            if (preg_match('#^jobs/([a-z0-9\-]+)/?$#i', $path, $jm)) {
                $jobHtml = app(\App\Engines\Builder\Services\BuilderRenderer::class)->renderJob($subdomain, $jm[1]);
                if ($jobHtml !== null) {
                    $jobHtml = app(\App\Engines\Ads\Services\AdSlotInjector::class)->inject($jobHtml, (int) $website->id);
                    return response($jobHtml, 200)->header('Content-Type', 'text/html; charset=utf-8')
                        ->header('Cache-Control', 'public, max-age=60, s-maxage=60')->header('X-Served-By', 'dynamic-job');
                }
                return redirect('/jobs', 302)->header('X-Served-By', 'job-not-found');
            }
            // KABAYAN888 G7a (2026-09-03) — /news/{slug} is an article path too (magazine
            // sites set settings_json.article_base = 'news'); /blog/{slug} keeps working.
            if (preg_match('#^(?:blog|news)/([a-z0-9\-]+)/?$#i', $path, $bm)) {
                $articleSlug = $bm[1];
                $dynHtml = $this->renderDynamicArticlePage(
                    (int) ($website->workspace_id ?? 0),
                    (int) $website->id,
                    $articleSlug
                );
                // No static article template (builder/themed sites have none) →
                // render the post through BuilderRenderer so it keeps the
                // tenant's brand instead of falling through to the platform's
                // LevelUp blog-post template.
                if ($dynHtml === null) {
                    $dynHtml = app(\App\Engines\Builder\Services\BuilderRenderer::class)
                        ->renderArticle($subdomain, $articleSlug);
                }
                if ($dynHtml !== null) {
                    $dynHtml = $this->injectChatbotWidget($dynHtml, (int) ($website->workspace_id ?? 0), (int) $website->id);
                    $dynHtml = app(\App\Engines\Ads\Services\AdSlotInjector::class)->inject($dynHtml, (int) $website->id);
                    return response($dynHtml, 200)
                        ->header('Content-Type', 'text/html; charset=utf-8')
                        ->header('Cache-Control', 'public, max-age=60, s-maxage=60')
                        ->header('X-Served-By', 'dynamic-article');
                }

                // b25 (2026-07-24) — SERVE seo_redirects.
                //
                // The SEO engine has always let a workspace CREATE redirects
                // (seo_redirects + the management UI), but nothing in the request
                // path ever read the table, so every stored redirect was inert and
                // a moved URL simply 404'd. This runs only once an article has
                // failed to resolve, so it costs a query on 404s alone and can
                // never shadow a live post.
                $redirect = $this->lookupRedirect((int) ($website->workspace_id ?? 0), $request->path());
                if ($redirect !== null) {
                    return redirect($redirect['target'], $redirect['code'])
                        ->header('X-Served-By', 'seo-redirect');
                }

                // No published article for this slug and no redirect. Do NOT fall
                // through to $next — that serves the PLATFORM blog SPA and leaks the
                // platform brand ("LevelUpGrowth Blog") onto the tenant's domain.
                // Send the visitor to the tenant's OWN on-brand blog index instead.
                return redirect('/blog', 302)->header('X-Served-By', 'blog-article-not-found');
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

        $html = $this->injectBookingForms($html); // LEAD-1
        $html = $this->injectChatbotWidget($html, (int) ($website->workspace_id ?? 0), (int) ($website->id ?? 0));
        $html = app(\App\Engines\Ads\Services\AdSlotInjector::class)->inject($html, (int) ($website->id ?? 0));
        $html = $this->absolutizeSocialMeta($html, $website, (string) $slug);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=60, s-maxage=60');
    }

    /**
     * Wave 63 — Inject DB-tracked blog articles into a bespoke static blog
     * index HTML. Theme-agnostic: detects the first existing post-card-style
     * block and uses it as a template to clone for any article not already
     * mentioned in the static file. CSS classes are preserved verbatim, so
     * the tenant's custom theme is untouched.
     *
     * Fires only when the static path is /blog/index.html or /blog.html.
     * Skips silently if the file's structure doesn't contain a recognizable
     * card template.
     */
    /**
     * A11y landmarks for static-template output. Templates are uniformly
     * <body><nav>...</nav> ...content... <footer>...</footer></body> with no
     * <main>/<header> (EV-0737 / EV-0680). Wrap the single nav in <header> and the
     * content between it and the footer in <main>. Skipped when the page already has a
     * <main> (the dynamic BuilderRenderer and the amg bespoke theme emit their own).
     */
    /**
     * RISK-0102 (WCAG 1.4.3 AA) — ensure the .eyebrow accent labels meet 4.5:1 contrast.
     * They use var(--medical), a bright brand accent that often fails on light grounds.
     * If the tenant's --medical fails AA vs white, derive a darker same-hue variant and
     * override .eyebrow color. Only the eyebrow text changes; the accent stays for
     * buttons/fills. Fires only on failure; fail-open.
     */
    /**
     * RISK-0108 (B5 responsive) — generated templates ship a desktop-only .nav-links row
     * with no mobile breakpoint and no hamburger, so every served site overflows
     * horizontally on phones. Inject a max-width:820px rule that lets the nav wrap onto
     * tidy rows. flex-wrap only reflows when the links do not fit, so wide navs are
     * untouched. Additive, idempotent, fail-open.
     */
    private function injectMobileNav(string $html): string
    {
        // MOBILE-4: one source of truth, shared with the static-export writer so both paths emit the same rule.
        return \App\Engines\Builder\Support\ResponsiveNav::inject($html);
    }

    private function injectAccentContrast(string $html): string
    {
        try {
            if (stripos($html, 'eyebrow') === false || stripos($html, '--medical') === false) {
                return $html;
            }
            if (! preg_match('/--medical:\s*(#[0-9a-fA-F]{6})/', $html, $m)) return $html;
            $hex = $m[1];
            if ($this->contrastVsWhite($hex) >= 4.5) return $html; // already accessible
            $safe = $this->darkenToContrast($hex, 4.6); // small margin for near-white grounds
            if ($safe === null) return $html;
            $css = '<style id="lu-a11y-accent">.eyebrow{color:' . $safe . ' !important}</style>';
            $out = preg_replace('#</head>#i', $css . '</head>', $html, 1, $n);
            return ($n && $out !== null) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /** WCAG relative luminance of a #rrggbb colour. */
    private function relLum(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $out = 0.0; $coef = [0.2126, 0.7152, 0.0722];
        foreach ([0, 2, 4] as $i => $off) {
            $v = hexdec(substr($hex, $off, 2)) / 255;
            $lin = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
            $out += $coef[$i] * $lin;
        }
        return $out;
    }

    /** Contrast ratio of a colour against white. */
    private function contrastVsWhite(string $hex): float
    {
        return round(1.05 / ($this->relLum($hex) + 0.05), 2);
    }

    /** Darken a colour (uniform RGB scale -> hue preserved) until it meets the target
     *  contrast vs white; near-black fallback always passes. */
    private function darkenToContrast(string $hex, float $target): ?string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
        for ($k = 100; $k >= 0; $k -= 2) {
            $f = $k / 100;
            $nhex = sprintf('#%02x%02x%02x', (int) round($r * $f), (int) round($g * $f), (int) round($b * $f));
            if ($this->contrastVsWhite($nhex) >= $target) return $nhex;
        }
        return '#0f172a';
    }

    /**
     * RISK-0102 (WCAG 4.1.2) — give unnamed carousel/indicator dot buttons an accessible
     * name. Generated templates emit empty <button class="...dot..." onclick="goToSlide(i)">
     * with no text/aria-label, so screen readers announce a bare "button". Additive and
     * idempotent (skips any button that already has aria-label). Fail-open on error.
     */
    /**
     * RISK-0115 (WCAG 4.1.2/3.3.2) — generated forms use visual labels not associated with
     * their inputs, so date/time/select fields have no accessible name. Add aria-label
     * (humanised from the name attribute) to id-less unlabelled form controls. Skips controls
     * that already have aria-label, have an id (likely a <label for>), or are hidden/submit/
     * button. Additive, fail-open.
     */
    private function injectFormLabels(string $html): string
    {
        try {
            if (stripos($html, '<input') === false && stripos($html, '<select') === false && stripos($html, '<textarea') === false) {
                return $html;
            }
            $out = preg_replace_callback('#<(input|select|textarea)\b([^>]*)>#i', function (array $m): string {
                $tag = $m[1];
                $attrs = $m[2];
                if (preg_match('#\baria-label\s*=#i', $attrs)) return $m[0];
                if (preg_match('#\bid\s*=#i', $attrs)) return $m[0];
                if (preg_match('#\btype\s*=\s*"(hidden|submit|button|image|reset)"#i', $attrs)) return $m[0];
                if (! preg_match('#\bname\s*=\s*"([^"]+)"#i', $attrs, $nm)) return $m[0];
                $label = ucfirst(trim((string) preg_replace('/[_\-]+/', ' ', $nm[1])));
                if ($label === '') return $m[0];
                return '<' . $tag . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"' . $attrs . '>';
            }, $html);

            // RISK-0115 residual — name-less <select>: label from its first (placeholder) option.
            $out = preg_replace_callback(
                '#<select\\b(?![^>]*aria-label)(?![^>]*\\bid\\s*=)(?![^>]*\\bname\\s*=)([^>]*)>(\\s*<option[^>]*>)([^<]{1,60})(</option>)#i',
                function ($mm) {
                    $label = trim($mm[3]);
                    if ($label === '') return $mm[0];
                    return '<select aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"' . $mm[1] . '>' . $mm[2] . $mm[3] . $mm[4];
                },
                $out ?? $html
            ) ?? ($out ?? $html);

            // RISK-0115 residual — name-less typed inputs (date/time/email/tel): label from type.
            $out = preg_replace_callback(
                '#<input\\b(?![^>]*aria-label)(?![^>]*\\bid\\s*=)(?![^>]*\\bname\\s*=)([^>]*\\btype\\s*=\\s*"(date|time|datetime-local|email|tel|search)"[^>]*)>#i',
                function ($mm) {
                    $map = ['date' => 'Date', 'time' => 'Time', 'datetime-local' => 'Date and time', 'email' => 'Email', 'tel' => 'Phone', 'search' => 'Search'];
                    $label = $map[strtolower($mm[2])] ?? 'Field';
                    return '<input aria-label="' . $label . '"' . $mm[1] . '>';
                },
                $out
            ) ?? $out;

            return $out ?? $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    private function injectA11yNames(string $html): string
    {
        try {
            if (stripos($html, 'dot') === false && stripos($html, 'indicator') === false) {
                return $html;
            }
            $out = preg_replace_callback(
                '#<button\b(?![^>]*aria-label)([^>]*class="[^"]*(?:dot|indicator)[^"]*"[^>]*)>(\s*)</button>#i',
                function ($m) {
                    $attrs = $m[1];
                    $n = 1;
                    if (preg_match('#goToSlide\((\d+)\)#i', $attrs, $gm)) { $n = (int) $gm[1] + 1; }
                    return '<button aria-label="Go to slide ' . $n . '"' . $attrs . '>' . $m[2] . '</button>';
                },
                $html
            );
            return $out ?? $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    private function injectLandmarks(string $html): string
    {
        if (stripos($html, '<main') !== false) { return $html; }
        if (stripos($html, '<nav') === false || stripos($html, '<footer') === false) { return $html; }
        $n = 0;
        $out = preg_replace('#(<nav\\b[^>]*>.*?</nav>)#is', '<header>$1</header>', $html, 1, $n);
        if ($n === 0 || $out === null) { return $html; }
        $out = preg_replace('#</header>#i', '</header><main>', $out, 1) ?? $out;
        $out = preg_replace('#(<footer\\b)#i', '</main>$1', $out, 1) ?? $out;
        return $out;
    }

    private function injectDynamicBlogPosts(string $html, int $workspaceId, int $cardLimit = 0, bool $blogCardOnly = false, int $websiteId = 0): string
    {
        if ($workspaceId <= 0) return $html;

        try {
            $articles = DB::table('articles')
                ->where('workspace_id', $workspaceId)
                ->where('is_marketing_blog', 1)
                ->where('status', 'published')
                ->whereNull('deleted_at')
                // CONTENT-2: an article belongs to ONE website of the workspace (NULL = legacy/unassigned).
                ->where(function ($q) use ($websiteId) { if ($websiteId > 0) { $q->where('website_id', $websiteId)->orWhereNull('website_id'); } })
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get(['id', 'title', 'slug', 'featured_image_url', 'meta_description', 'excerpt', 'blog_category', 'word_count', 'content', 'published_at']);
        } catch (\Throwable $e) {
            return $html;
        }
        if ($articles->isEmpty()) {
            // RISK-0109 — div-card templates have no lu-blog-card-tpl, so a 0-article site would
            // show empty placeholder blog cards. On the HOME preview, hide the empty blog section.
            // a-card sites carry the honest empty-state (lu-blog-card-tpl present) and are untouched.
            if ($blogCardOnly
                && stripos($html, 'lu-blog-card-tpl') === false
                && stripos($html, 'blog-card') !== false
                && stripos($html, 'data-block="blog"') !== false) {
                $css = '<style id="lu-blog-empty-hide">[data-block="blog"]{display:none}</style>';
                $out = preg_replace('#</head>#i', $css . '</head>', $html, 1, $n);
                if ($n && $out !== null) return $out;
            }
            // RISK-0109 — the /blog INDEX (not the home preview) of a 0-article div-card site:
            // hide the placeholder cards and show an honest empty-state in the grid.
            if (! $blogCardOnly
                && stripos($html, 'lu-blog-card-tpl') === false
                && stripos($html, 'blog-card') !== false
                && stripos($html, 'blog-grid') !== false) {
                $msg = '<p class="lu-blog-empty" style="grid-column:1/-1;text-align:center;padding:40px 0;color:#64748b;font-size:1.05rem">New articles are on the way — check back soon.</p>';
                $withMsg = preg_replace('#(<div[^>]*class="[^"]*blog-grid[^"]*"[^>]*>)#i', '$1' . $msg, $html, 1, $g);
                if ($g && $withMsg !== null) {
                    $css2 = '<style id="lu-blog-empty-hide">.blog-grid .blog-card{display:none}</style>';
                    $out2 = preg_replace('#</head>#i', $css2 . '</head>', $withMsg, 1, $n2);
                    return ($n2 && $out2 !== null) ? $out2 : $withMsg;
                }
            }
            return $html;
        }

        $latest = $articles->first();

        // RISK-0101 — blog-card template family (generated Builder sites have no
        // post-card markup). The deploy step embeds a hidden <template
        // class="lu-blog-card-tpl"> styled card; populate it from live articles and
        // replace the honest empty-state line. Fresh per request (no re-deploy).
        if (preg_match('#<template[^>]*class="[^"]*lu-blog-card-tpl[^"]*"[^>]*>(.*?)</template>#is', $html, $tm)) {
            $tpl = $tm[1];
            $cards = '';
            $list = $cardLimit > 0 ? $articles->take($cardLimit) : $articles;
            foreach ($list as $a) {
                $c = $tpl;
                $c = preg_replace('#href="/blog/[^"]*"#i', 'href="/blog/' . e($a->slug) . '"', $c, 1);
                $c = preg_replace_callback('#(<h3[^>]*class="[^"]*blog-card-title[^"]*"[^>]*>).*?(</h3>)#is',
                    fn($mm) => $mm[1] . e((string) $a->title) . $mm[2], $c, 1);
                $c = preg_replace_callback('#(<p[^>]*class="[^"]*blog-card-excerpt[^"]*"[^>]*>).*?(</p>)#is',
                    fn($mm) => $mm[1] . e((string) ($a->excerpt ?? $a->meta_description ?? '')) . $mm[2], $c, 1);
                $cards .= $c;
            }
            if ($cards !== '') {
                $out = preg_replace('#<p[^>]*class="[^"]*blog-empty[^"]*"[^>]*>.*?</p>#is', $cards, $html, 1, $g);
                if ($g && $out !== null) return $out;
                // No empty line to replace — inject the cards just before the template.
                $out = preg_replace('#(<template[^>]*class="[^"]*lu-blog-card-tpl)#i', $cards . '$1', $html, 1, $g2);
                return ($g2 && $out !== null) ? $out : $html;
            }
            return $html;
        }

        // RISK-0109 (aspect 2) — div-card templates use indexed placeholders
        // data-field="blog_N_title/excerpt/cat/image/link" (N=1..3) with no lu-blog-card-tpl.
        // Populate the pre-built cards from live articles; hide unused ones. Targeted
        // single-element regexes (the card fields hold single text nodes, no nesting).
        if (stripos($html, 'lu-blog-card-tpl') === false
            && preg_match('#data-field="blog_1_title"#i', $html)) {
            $list = $articles->take(3)->values();
            $any = false;
            for ($i = 1; $i <= 3; $i++) {
                $a = $list->get($i - 1);
                if (! $a) { continue; }
                $any = true;
                $slug    = e((string) $a->slug);
                $title   = e((string) $a->title);
                $excerpt = e((string) ($a->excerpt ?? $a->meta_description ?? ''));
                $cat     = e((string) ($a->blog_category ?? 'Article'));
                $img     = e((string) ($a->featured_image_url ?? ''));
                $html = preg_replace('#(data-field="blog_' . $i . '_title"[^>]*>).*?(</[a-z0-9]+>)#is',  '${1}' . $title . '${2}', $html, 1);
                $html = preg_replace('#(data-field="blog_' . $i . '_excerpt"[^>]*>).*?(</[a-z0-9]+>)#is', '${1}' . $excerpt . '${2}', $html, 1);
                // category: cafe uses blog_N_cat; event_venue/it_services use blog_N_category.
                $html = preg_replace('#(data-field="blog_' . $i . '_cat"[^>]*>).*?(</[a-z0-9]+>)#is',      '${1}' . $cat . '${2}', $html, 1);
                $html = preg_replace('#(data-field="blog_' . $i . '_category"[^>]*>).*?(</[a-z0-9]+>)#is', '${1}' . $cat . '${2}', $html, 1);
                // link: only where the template has a blog_N_link anchor (cafe/training_center).
                $html = preg_replace('#<a[^>]*data-field="blog_' . $i . '_link"[^>]*>.*?</a>#is', '<a href="/blog/' . $slug . '" class="blog-link" data-field="blog_' . $i . '_link">Read more</a>', $html, 1);
                // image: <img data-field src> (event_venue/it_services), a background-image div
                // (cafe), or an <img> with no src.
                if ($img !== '') {
                    $html = preg_replace_callback(
                        '#<(img|div|span)([^>]*\bdata-field="blog_' . $i . '_image"[^>]*)>#i',
                        function ($mm) use ($img) {
                            $attrs = $mm[2];
                            if (preg_match('#\bsrc="#i', $attrs)) {
                                $attrs = preg_replace('#\bsrc="[^"]*"#i', 'src="' . $img . '"', $attrs, 1);
                            } elseif (stripos($attrs, 'background-image') !== false) {
                                $attrs = preg_replace('#background-image:url\([^)]*\)#i', "background-image:url('" . $img . "')", $attrs, 1);
                            } elseif (strtolower($mm[1]) === 'img') {
                                $attrs .= ' src="' . $img . '"';
                            }
                            return '<' . $mm[1] . $attrs . '>';
                        },
                        $html, 1
                    );
                }
            }
            if ($any) {
                // hide unpopulated cards robustly (across all 4 div-card templates) by targeting
                // each unused card via its title data-field, which every div-card template has.
                $hide = [];
                for ($j = 1; $j <= 3; $j++) {
                    if (! $list->get($j - 1)) { $hide[] = '.blog-card:has([data-field="blog_' . $j . '_title"])'; }
                }
                if (! empty($hide)) {
                    $css = '<style id="lu-blog-divcard">' . implode(',', $hide) . '{display:none}</style>';
                    $out = preg_replace('#</head>#i', $css . '</head>', $html, 1, $n);
                    if ($n && $out !== null) return $out;
                }
                return $html;
            }
        }

        // Home preview requests only the contained blog-card branch — never the
        // post-card featured/grid rotation below.
        if ($blogCardOnly) return $html;

        // Detect post-card template (for grid injection).
        $cardTpl = null;
        if (preg_match('#(<a\s[^>]*href="/blog/[^"]+/?"[^>]*class="post-card-link"[^>]*>\s*<article\s[^>]*class="post-card"[^>]*>.*?</article>\s*</a>)#is', $html, $m)) {
            $cardTpl = $m[1];
        }

        // Detect featured-card template + extract its current slug.
        $featTpl = null;
        $oldFeaturedSlug = null;
        if (preg_match('#(<a\s[^>]*href="/blog/([^"/]+)/?"[^>]*class="post-featured-link"[^>]*>\s*<article\s[^>]*class="post-featured"[^>]*>.*?</article>\s*</a>)#is', $html, $fm)) {
            $featTpl = $fm[1];
            $oldFeaturedSlug = $fm[2];
        }

        // ── 1. Rotate the featured card to the latest article if needed ──
        if ($featTpl && $latest && $latest->slug !== $oldFeaturedSlug) {
            $newFeatured = $this->renderArticleIntoTemplate($featTpl, $latest);
            $html = str_replace($featTpl, $newFeatured, $html);
        }

        if (!$cardTpl) return $html;

        // ── 2. Determine which articles still need to appear in the grid ──
        // Skip the now-featured latest. Inject every other DB article whose
        // slug isn't already in the static grid.
        // ALSO inject the OLD featured slug (the one we just demoted) so it
        // doesn't vanish from the page.
        $needToInject = [];
        foreach ($articles as $a) {
            if ($latest && $a->slug === $latest->slug) continue; // it's featured now
            if ($a->slug && stripos($html, '/blog/' . $a->slug) === false) {
                $needToInject[] = $a;
            }
        }

        // The OLD featured article — if we swapped, it needs to appear in grid.
        // Look it up in DB to clone with full data.
        if ($oldFeaturedSlug && $latest && $latest->slug !== $oldFeaturedSlug) {
            $alreadyIn = false;
            foreach ($needToInject as $a) { if ($a->slug === $oldFeaturedSlug) { $alreadyIn = true; break; } }
            // Also skip if grid already has it (some sites duplicate-list).
            if (!$alreadyIn && stripos($html, '/blog/' . $oldFeaturedSlug) === false) {
                $oldRow = DB::table('articles')
                    ->where('workspace_id', $workspaceId)
                    ->where('slug', $oldFeaturedSlug)
                    ->whereNull('deleted_at')
                    ->first(['id', 'title', 'slug', 'featured_image_url', 'meta_description', 'excerpt', 'blog_category', 'word_count', 'content', 'published_at']);
                if ($oldRow) {
                    // Insert at the FRONT so the demoted featured shows just
                    // after the new articles in the grid.
                    array_unshift($needToInject, $oldRow);
                }
            }
        }

        if (empty($needToInject)) return $html;

        // ── 3. Build grid cards ──
        $newCards = '';
        foreach ($needToInject as $a) {
            $newCards .= $this->renderArticleIntoTemplate($cardTpl, $a) . "\n";
        }

        // Inject before the FIRST existing post-card.
        $injected = preg_replace('#(<a\s[^>]*href="/blog/[^"]+/?"[^>]*class="post-card-link"[^>]*>\s*<article\s)#is', $newCards . '$1', $html, 1);
        return $injected ?: $html;
    }

    /**
     * Wave 63d helper — clone a template (post-card or post-featured) and
     * substitute an article's data. Shared by featured-rotation and grid-injection.
     */
    /**
     * b25 (2026-07-24) — resolve a stored redirect for a 404'ing path.
     *
     * Matches with and without a leading slash, since the UI stores both forms.
     * Regex rows (is_regex=1) are supported but evaluated last and guarded, so a
     * malformed pattern can never take a customer site down.
     *
     * @return array{target:string,code:int}|null
     */
    private function lookupRedirect(int $wsId, string $path): ?array
    {
        if ($wsId <= 0) return null;

        $path  = '/' . ltrim($path, '/');
        $alt   = ltrim($path, '/');

        try {
            $rows = \Illuminate\Support\Facades\DB::table('seo_redirects')
                ->where('workspace_id', $wsId)
                ->where('is_active', 1)
                ->where('status', 'active')
                ->whereNotNull('source_url')
                ->where('source_url', '!=', '')
                ->get(['id', 'source_url', 'target_url', 'type', 'status_code', 'is_regex']);
        } catch (\Throwable $e) {
            return null;   // never break the site over a redirect lookup
        }

        $exact = null; $regex = null;
        foreach ($rows as $r) {
            $src = trim((string) $r->source_url);
            if ($src === '' || trim((string) $r->target_url) === '') continue;

            if ((int) $r->is_regex === 1) {
                if ($regex === null) {
                    try {
                        if (@preg_match($src, $path) === 1) $regex = $r;
                    } catch (\Throwable $e) { /* bad pattern — skip */ }
                }
                continue;
            }
            $s = '/' . ltrim($src, '/');
            if ($s === $path || $src === $alt) { $exact = $r; break; }
        }

        $hit = $exact ?: $regex;
        if (!$hit) return null;

        $code = (int) ($hit->status_code ?: $hit->type ?: 301);
        if (!in_array($code, [301, 302, 307], true)) $code = 301;

        try {
            \Illuminate\Support\Facades\DB::table('seo_redirects')
                ->where('id', $hit->id)->increment('hit_count');
        } catch (\Throwable $e) { /* counting must never block the redirect */ }

        return ['target' => (string) $hit->target_url, 'code' => $code];
    }

    private function renderArticleIntoTemplate(string $tpl, object $article): string
    {
        $card = $tpl;

        // href
        $card = preg_replace('#href="/blog/[^"]+"#i', 'href="/blog/' . e($article->slug) . '"', $card, 1);

        // image src + alt
        if (!empty($article->featured_image_url)) {
            $card = preg_replace('#<img\s([^>]*)src="[^"]+"#i', '<img $1src="' . e($article->featured_image_url) . '"', $card, 1);
        }
        $card = preg_replace('#(<img[^>]*\s)alt="[^"]*"#i', '$1alt="' . e($article->title) . '"', $card, 1);

        // title — any h2/h3 inside the card (handles featured uses h2, post-card uses h3)
        $card = preg_replace_callback('#(<h2[^>]*>)(.+?)(</h2>)|(<h3[^>]*>)(.+?)(</h3>)#is',
            function ($mm) use ($article) {
                if (!empty($mm[1])) return $mm[1] . e($article->title) . $mm[3];
                return $mm[4] . e($article->title) . $mm[6];
            }, $card, 1);

        // excerpt: prefer explicit > meta > derived from content
        $excerpt = '';
        if (!empty($article->excerpt))                    $excerpt = $article->excerpt;
        elseif (!empty($article->meta_description))       $excerpt = $article->meta_description;
        elseif (!empty($article->content)) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($article->content)));
            $excerpt = mb_substr($text, 0, 200);
            if (mb_strlen($text) > 200) $excerpt = rtrim($excerpt, ',. !?:;') . "\u{2026}";
        }
        if ($excerpt !== '') {
            $card = preg_replace('#(<p[^>]*class="[^"]*excerpt[^"]*"[^>]*>).+?(</p>)#is', '$1' . e($excerpt) . '$2', $card, 1);
        }

        // category
        $cat = $article->blog_category ?: '';
        if ($cat !== '') {
            $card = preg_replace('#(<div[^>]*class="[^"]*(?:post-cat|post-card-cat)[^"]*"[^>]*>).+?(</div>)#is', '$1' . e($cat) . '$2', $card, 1);
        } else {
            // Drop the category div entirely so no stale label.
            $card = preg_replace('#<div[^>]*class="[^"]*(?:post-cat|post-card-cat)[^"]*"[^>]*>.+?</div>#is', '', $card, 1);
        }

        // date
        if ($article->published_at) {
            $when = \Carbon\Carbon::parse($article->published_at)->format('F Y');
            $card = preg_replace('#(<span[^>]*class="[^"]*post-date[^"]*"[^>]*>).+?(</span>)#is', '$1' . e($when) . '$2', $card, 1);
        }

        // read time
        $wc = (int) ($article->word_count ?? 0);
        if ($wc > 0) {
            $rt = max(1, (int) round($wc / 200));
            $card = preg_replace('#(<span[^>]*class="[^"]*post-read-time[^"]*"[^>]*>).+?(</span>)#is', '$1' . e($rt . ' min read') . '$2', $card, 1);
        }

        return $card;
    }

    /**
     * Wave 64 — Render a blog article page dynamically when no static
     * file exists. Uses an EXISTING per-article static file as the theme
     * template, then swaps title, image, body, meta to the requested
     * article's data. Returns null if no template or no matching article.
     */
    /**
     * CONTENT-2 — build an article-page template from a template site's blog/index.html: keep everything
     * outside <main>…</main> (nav, header, footer, styles) and put an article skeleton inside.
     */
    private function articleChromeFromBlogIndex(string $indexPath): ?string
    {
        $html = @file_get_contents($indexPath);
        if (!$html) return null;
        $skeleton = '<main><article class="lu-post" style="max-width:820px;margin:0 auto;padding:72px 24px 56px;line-height:1.75">'
            . '<div class="post-page-cat" style="font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;opacity:.7;margin-bottom:12px">Article</div>'
            . '<h1 class="post-page-title" style="font-size:2.4rem;line-height:1.15;margin:0 0 14px">Article</h1>'
            . '<div class="post-page-meta" style="font-size:.9rem;opacity:.75;margin-bottom:26px"><span class="post-date">Today</span></div>'
            . '<img class="post-hero-img" src="" alt="" style="width:100%;height:auto;border-radius:14px;margin:0 0 30px;display:block">'
            . '<div class="post-page-body">Body</div>'
            . '<a class="post-page-back" href="/blog" style="display:inline-block;margin-top:40px;text-decoration:none;font-weight:600">&larr; Back to the blog</a>'
            . '</article></main>';
        $out = preg_replace('#<main\b[^>]*>.*?</main>#is', $skeleton, $html, 1, $n);
        if ($n === 1 && $out !== null) return $out;
        // No <main>: wrap the article between the first </nav>/</header> and <footer>.
        $out = preg_replace('#(</header>|</nav>)(.*?)(<footer\b)#is', '$1' . $skeleton . '$3', $html, 1, $n);
        return ($n === 1 && $out !== null) ? $out : null;
    }

    private function renderDynamicArticlePage(int $workspaceId, int $websiteId, string $slug): ?string
    {
        if ($workspaceId <= 0 || $websiteId <= 0 || $slug === '') return null;

        try {
            $article = DB::table('articles')
                ->where('workspace_id', $workspaceId)
                ->where('slug', $slug)
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($websiteId) { if ($websiteId > 0) { $q->where('website_id', $websiteId)->orWhereNull('website_id'); } })
                ->first(['id', 'title', 'slug', 'content', 'featured_image_url', 'featured_image_alt',
                         'meta_title', 'meta_description', 'seo_json', 'jsonld_json',
                         'blog_category', 'word_count', 'published_at', 'updated_at']);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$article) return null;

        // Find any existing static article file to use as theme template.
        $siteRoot = storage_path('app/public/sites/' . $websiteId . '/blog');
        if (!is_dir($siteRoot)) return null;
        $candidates = glob($siteRoot . '/*/index.html');
        $tpl = null;
        if (!empty($candidates)) {
            $tpl = @file_get_contents($candidates[0]);
        }
        if (!$tpl) {
            // CONTENT-2 (2026-08-29): template sites have no per-article static file. Use the site's OWN
            // blog index page as chrome (nav/header/footer, fonts, colours) and swap its <main> for a real
            // article layout carrying the hooks the substitutions below expect. Before this the article
            // fell through to a bare BuilderRenderer section: duplicate H1, no chrome, no featured image.
            $tpl = $this->articleChromeFromBlogIndex($siteRoot . '/index.html');
        }
        if (!$tpl) return null;

        $html = $tpl;
        $title = $article->title ?: $slug;
        $metaTitle = $article->meta_title ?: $title;
        $metaDesc = $article->meta_description ?: '';
        if (!$metaDesc && $article->seo_json) {
            $seo = json_decode($article->seo_json, true);
            if (is_array($seo)) {
                $metaTitle = $metaTitle ?: ($seo['title'] ?? $title);
                $metaDesc  = $seo['description'] ?? '';
            }
        }
        $imgUrl = $article->featured_image_url ?: '';
        $imgAlt = $article->featured_image_alt ?: $title;

        // <title>
        $html = preg_replace('#<title>.*?</title>#is', '<title>' . e($metaTitle) . '</title>', $html, 1);
        // meta description
        $html = preg_replace('#(<meta\s+name="description"\s+content=)"[^"]*"#i', '$1"' . e($metaDesc) . '"', $html, 1);
        // canonical link if present
        $canonical = 'https://' . (request()->getHost() ?: 'levelupgrowth.io') . '/blog/' . $slug;
        $html = preg_replace('#(<link\s+rel="canonical"\s+href=)"[^"]*"#i', '$1"' . e($canonical) . '"', $html, 1);

        // og: tags
        $html = preg_replace('#(<meta\s+property="og:title"\s+content=)"[^"]*"#i', '$1"' . e($metaTitle) . '"', $html, 1);
        $html = preg_replace('#(<meta\s+property="og:description"\s+content=)"[^"]*"#i', '$1"' . e($metaDesc) . '"', $html, 1);
        if ($imgUrl) {
            $html = preg_replace('#(<meta\s+property="og:image"\s+content=)"[^"]*"#i', '$1"' . e($imgUrl) . '"', $html, 1);
        }

        // Featured/hero image: <img class="post-hero-img">
        if ($imgUrl) {
            $html = preg_replace('#(<img[^>]*class="[^"]*post-hero-img[^"]*"[^>]*src=)"[^"]*"#i', '$1"' . e($imgUrl) . '"', $html, 1);
            // Some templates put class after src — try the alt form.
            $html = preg_replace('#(<img[^>]*src=)"[^"]*"([^>]*class="[^"]*post-hero-img[^"]*")#i', '$1"' . e($imgUrl) . '"$2', $html, 1);
        }
        $html = preg_replace('#(<img[^>]*class="[^"]*post-hero-img[^"]*"[^>]*\s)alt="[^"]*"#i', '$1alt="' . e($imgAlt) . '"', $html, 1);
        if (!$imgUrl) {
            // CONTENT-2: no featured image → no empty/broken hero.
            $html = preg_replace('#<img[^>]*class="[^"]*post-hero-img[^"]*"[^>]*>#i', '', $html, 1);
        }

        // Article title <h1 class="post-page-title">
        $html = preg_replace('#(<h1[^>]*class="[^"]*post-page-title[^"]*"[^>]*>).+?(</h1>)#is', '$1' . e($title) . '$2', $html, 1);
        // Category <div class="post-page-cat">
        $cat = $article->blog_category ?: '';
        if ($cat !== '') {
            $html = preg_replace('#(<div[^>]*class="[^"]*post-page-cat[^"]*"[^>]*>).+?(</div>)#is', '$1' . e($cat) . '$2', $html, 1);
        } else {
            $html = preg_replace('#<div[^>]*class="[^"]*post-page-cat[^"]*"[^>]*>.+?</div>#is', '', $html, 1);
        }
        // Date in <span class="post-date"> if present
        if ($article->published_at) {
            $when = \Carbon\Carbon::parse($article->published_at)->format('F j, Y');
            $html = preg_replace('#(<span[^>]*class="[^"]*post-date[^"]*"[^>]*>).+?(</span>)#is', '$1' . e($when) . '$2', $html, 1);
        }

        // Wave 73b — append "Related Reading" section before body injection
        // so dynamic-rendered articles get guaranteed internal links too.
        $bodyAppend = $this->buildRelatedArticlesHtml($workspaceId, $slug, $websiteId);
        $article->content = ((string) $article->content) . $bodyAppend;

        // Article body — REPLACE everything inside <div class="post-page-body">.
        // Wave 73 — the previous regex anchored on </div></div> failed for
        // chef-red's structure which uses </div><a class="post-page-back">
        // and other templates may vary. Anchor on either the post-page-back
        // back-link OR the closing </article> tag instead, which are
        // universally present in any blog-post template.
        // CONTENT-2: the page renders the title once; drop the body's own leading <h1>.
        $bodyContent = preg_replace('#^\s*<h1\b[^>]*>.*?</h1>\s*#is', '', (string) $article->content, 1) ?? (string) $article->content;
        $replaced = false;
        // 1st attempt: anchor on post-page-back link.
        $tmp = preg_replace_callback(
            '#(<div[^>]*class="[^"]*post-page-body[^"]*"[^>]*>)(.+?)(</div>\s*<a[^>]*class="[^"]*post-page-back)#is',
            function ($m) use ($bodyContent) { return $m[1] . $bodyContent . '</div><a ' . substr($m[3], strpos($m[3], 'class=')); },
            $html, 1, $count
        );
        if ($count > 0 && $tmp !== null) { $html = $tmp; $replaced = true; }
        if (!$replaced) {
            // 2nd attempt: anchor on </article>.
            $tmp = preg_replace_callback(
                '#(<div[^>]*class="[^"]*post-page-body[^"]*"[^>]*>)(.+?)(</article>)#is',
                function ($m) use ($bodyContent) { return $m[1] . $bodyContent . '</div>' . $m[3]; },
                $html, 1, $count
            );
            if ($count > 0 && $tmp !== null) { $html = $tmp; $replaced = true; }
        }
        if (!$replaced) {
            // 3rd attempt: original anchor as last-ditch.
            $tmp = preg_replace(
                '#(<div[^>]*class="[^"]*post-page-body[^"]*"[^>]*>).+?(</div>\s*</div>)#is',
                '$1' . $bodyContent . '$2',
                $html, 1
            );
            if ($tmp !== null && $tmp !== $html) { $html = $tmp; $replaced = true; }
        }
        \Illuminate\Support\Facades\Log::info('[renderDynamicArticlePage] body inject', [
            'slug' => $slug, 'replaced' => $replaced,
        ]);

        // Inject AEO JSON-LD if present
        if (!empty($article->jsonld_json)) {
            $jsonldTag = '<script type="application/ld+json">' . $article->jsonld_json . '</script>';
            $html = preg_replace('#</head>#i', $jsonldTag . "\n</head>", $html, 1);
        }

        // Wave 74c — strip TEMPLATE-leftover aeo-faq sections that appear
        // AFTER the post-page-back link. Those are always wrong content
        // from the original template article — the current article's
        // FAQ (if any) is in the injected body BEFORE the back link.
        $parts = preg_split('#(<a[^>]*class="[^"]*post-page-back)#i', $html, 2, PREG_SPLIT_DELIM_CAPTURE);
        if (is_array($parts) && count($parts) >= 3) {
            // parts[0] = head + body up to back link, parts[1] = back-link prefix, parts[2] = remainder
            $remainder = $parts[1] . $parts[2];
            $remainder = preg_replace('#<section[^>]*class="[^"]*aeo-faq[^"]*"[^>]*>.*?</section>#is', '', $remainder) ?? $remainder;
            $html = $parts[0] . $remainder;
        } else {
            // No back-link found — fall back to keep-first dedupe.
            $html = $this->stripDuplicateAeoFaq($html);
        }

        // Wave 75 — strip duplicate/malformed back-links.
        $html = $this->stripDuplicateBackLinks($html);

        // Wave 76 — normalize FAQ markup into standard structure.
        $html = $this->normalizeFaqSections($html);

        // Wave 73b — universal blog-link styling via the shared helper.
        $html = $this->injectBlogLinkStyling($html);

        return $html;
    }

    /**
     * Wave 73b — Build a "Related Reading" HTML block linking to 3 other
     * published articles in the workspace. Returns empty string if there
     * are fewer than 1 other articles to link to.
     */
    private function buildRelatedArticlesHtml(int $workspaceId, ?string $excludeSlug = null, int $websiteId = 0): string
    {
        if ($workspaceId <= 0) return '';
        try {
            $q = DB::table('articles')
                ->where('workspace_id', $workspaceId)
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($websiteId) { if ($websiteId > 0) { $q->where('website_id', $websiteId)->orWhereNull('website_id'); } });
            if ($excludeSlug) $q->where('slug', '!=', $excludeSlug);
            $related = $q->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(3)
                ->get(['title', 'slug']);
        } catch (\Throwable $e) {
            return '';
        }
        if ($related->isEmpty()) return '';

        $items = '';
        foreach ($related as $r) {
            if (empty($r->slug)) continue;
            $items .= '<li style="margin:.4rem 0"><a href="/blog/' . e($r->slug) . '">' . e($r->title) . '</a></li>';
        }
        if ($items === '') return '';

        return '<div class="post-related" style="margin-top:3rem;padding-top:1.5rem;border-top:1px solid rgba(255,255,255,.08)">'
             . '<h3 style="font-size:1.1rem;margin-bottom:.8rem">Related Reading</h3>'
             . '<ul style="list-style:none;padding:0;margin:0">' . $items . '</ul>'
             . '</div>';
    }

    /**
     * Wave 73b — Inject related-articles list into a static blog-post HTML
     * file just before the closing </article> tag. Skips silently if no
     * recognizable article structure or already injected.
     */
    private function injectRelatedArticles(string $html, int $workspaceId, string $staticPath): string
    {
        if ($workspaceId <= 0) return $html;
        if (stripos($html, 'class="post-related"') !== false) return $html; // already injected
        // Derive slug from path (e.g. .../blog/my-slug/index.html → my-slug).
        $slug = null;
        if (preg_match('#/blog/([^/]+)/(?:index\.html)?$#i', $staticPath, $m)) {
            $slug = $m[1];
        }
        $related = $this->buildRelatedArticlesHtml($workspaceId, $slug);
        if ($related === '') return $html;
        // Inject before </article>; fall back to before </body>.
        if (stripos($html, '</article>') !== false) {
            return preg_replace('#</article>#i', $related . '</article>', $html, 1);
        }
        return preg_replace('#</body>#i', $related . '</body>', $html, 1);
    }

    /**
     * Wave 76 — Normalize FAQ markup into a single standard structure:
     *   <section class="lu-faq">
     *     <h2 class="lu-faq-title">Frequently Asked Questions</h2>
     *     <div class="lu-faq-item">
     *       <h3 class="lu-faq-q">Q</h3>
     *       <p class="lu-faq-a">A</p>
     *     </div>
     *   </section>
     * Strips inline styles. Converts inline <h2>FAQ</h2>+Q/A paragraphs to
     * the same structure. Dedupes when both forms exist.
     */
    private function normalizeFaqSections(string $html): string
    {
        // 1) Strip inline styles from existing aeo-faq sections; replace
        //    its outer class with lu-faq and inner classes too.
        $html = preg_replace_callback(
            '#<section[^>]*class="[^"]*aeo-faq[^"]*"[^>]*>(.*?)</section>#is',
            function ($m) {
                $inner = $m[1];
                // h2 title
                $inner = preg_replace('#<h2[^>]*>(.*?)</h2>#is', '<h2 class="lu-faq-title">$1</h2>', $inner, 1);
                // each Q/A wrapper div
                $inner = preg_replace('#<div[^>]*style="[^"]*"[^>]*>(.*?)</div>#is', '<div class="lu-faq-item">$1</div>', $inner);
                // q and a
                $inner = preg_replace('#<h3[^>]*>(.*?)</h3>#is', '<h3 class="lu-faq-q">$1</h3>', $inner);
                $inner = preg_replace('#<p[^>]*>(.*?)</p>#is', '<p class="lu-faq-a">$1</p>', $inner);
                return '<section class="lu-faq">' . $inner . '</section>';
            },
            $html
        ) ?? $html;

        // 2) Detect inline <h2>FAQ</h2> or <h2>Frequently Asked Questions</h2>
        //    followed by Q/A paragraphs. If a lu-faq section already exists,
        //    strip the inline block. Otherwise, convert it.
        $hasNormalized = preg_match('#<section class="lu-faq">#i', $html);

        // Inline FAQ pattern: <h2>FAQ</h2> ... up to next <h2> or </article>.
        // Wave 76c — exclude h2 with class="lu-faq-title" so we don't match
        // the just-converted lu-faq section's own title.
        if (preg_match('#(<h2(?![^>]*class="[^"]*lu-faq)[^>]*>\s*(?:FAQ|Frequently Asked Questions?)\s*</h2>)(.+?)(?=<h2[^>]*>|</article>|<section\s+class="lu-faq")#is', $html, $m)) {
            $inlineWhole = $m[0];
            $afterHeading = $m[2];
            if ($hasNormalized) {
                // Strip inline block (duplicate).
                $html = str_replace($inlineWhole, '', $html);
            } else {
                // Convert paragraphs to lu-faq items.
                $items = '';
                if (preg_match_all('#<p[^>]*>\s*<strong>\s*Q:\s*(.+?)\s*</strong>\s*A?:?\s*(.+?)</p>#is', $afterHeading, $pms, PREG_SET_ORDER)) {
                    foreach ($pms as $pm) {
                        $items .= '<div class="lu-faq-item"><h3 class="lu-faq-q">' . trim($pm[1]) . '</h3><p class="lu-faq-a">' . trim($pm[2]) . '</p></div>';
                    }
                } else {
                    // Fallback A: each <p> becomes a Q+A pair split on ' A:'.
                    if (preg_match_all('#<p[^>]*>(.+?)</p>#is', $afterHeading, $pms)) {
                        foreach ($pms[1] as $para) {
                            if (stripos($para, 'A:') !== false) {
                                list($q, $a) = preg_split('#\bA:\s*#i', strip_tags($para), 2);
                                $q = preg_replace('#^Q:\s*#i', '', trim($q));
                                $items .= '<div class="lu-faq-item"><h3 class="lu-faq-q">' . e(trim($q)) . '</h3><p class="lu-faq-a">' . e(trim($a)) . '</p></div>';
                            }
                        }
                    }
                    // Fallback B: <h3>Q?</h3><p>A</p> sequences (Wave 76b).
                    if ($items === '' && preg_match_all('#<h3[^>]*>(.+?)</h3>\s*<p[^>]*>(.+?)</p>#is', $afterHeading, $pms, PREG_SET_ORDER)) {
                        foreach ($pms as $pm) {
                            $q = trim(strip_tags($pm[1]));
                            $a = trim(strip_tags($pm[2]));
                            if ($q !== '' && $a !== '') {
                                $items .= '<div class="lu-faq-item"><h3 class="lu-faq-q">' . e($q) . '</h3><p class="lu-faq-a">' . e($a) . '</p></div>';
                            }
                        }
                    }
                }
                if ($items !== '') {
                    $replacement = '<section class="lu-faq"><h2 class="lu-faq-title">Frequently Asked Questions</h2>' . $items . '</section>';
                    $html = str_replace($inlineWhole, $replacement, $html);
                }
            }
        }
        return $html;
    }

    /**
     * Wave 75 — Dedupe <a class="post-page-back"> back-links: keep the
     * first valid one, strip everything else. Also removes malformed
     * <aclass="post-page-back"> (missing space) artifacts created by an
     * earlier buggy body-injection regex.
     */
    private function stripDuplicateBackLinks(string $html): string
    {
        // Remove every malformed <aclass="...post-page-back...">...</a>.
        $html = preg_replace('#<aclass="[^"]*post-page-back[^"]*"[^>]*>.*?</a>#is', '', $html) ?? $html;
        // Dedupe valid <a class="post-page-back">: keep first only.
        $count = 0;
        $out = preg_replace_callback(
            '#<a[^>]*class="[^"]*post-page-back[^"]*"[^>]*>.*?</a>#is',
            function ($m) use (&$count) { $count++; return $count === 1 ? $m[0] : ''; },
            $html
        );
        return $out ?? $html;
    }

    /**
     * Wave 74b — Dedupe <section class="aeo-faq"> blocks: keep the FIRST
     * one only and remove subsequent duplicates. The article's body
     * content carries its own FAQ; subsequent occurrences are template
     * leftovers from duplicate AEO enrichment runs.
     */
    private function stripDuplicateAeoFaq(string $html): string
    {
        $pattern = '#<section[^>]*class="[^"]*aeo-faq[^"]*"[^>]*>.*?</section>#is';
        $count = 0;
        $out = preg_replace_callback($pattern, function ($m) use (&$count) {
            $count++;
            return $count === 1 ? $m[0] : '';
        }, $html);
        return $out ?? $html;
    }

    /**
     * Wave 73b — Inject CSS that underlines/accents body anchors so they
     * are visible across every theme. Universal selectors target common
     * blog body containers.
     */
    private function injectBlogLinkStyling(string $html): string
    {
        if (stripos($html, 'lu-blog-link-styling') !== false) return $html;
        $css = '<style id="lu-blog-link-styling">'
             // Link styling (Wave 73b)
             // Wave 79c — underline only, no color override.
             . '.post-page-body a, .post-content a, article.post a, .post-body a, .post-related a, .post-page-inner a:not(.post-page-back):not(.post-tag) {'
             . 'text-decoration:underline !important;text-underline-offset:3px;text-decoration-thickness:1px;color:inherit !important;'
             . '} .post-page-body a:hover, .post-content a:hover, article.post a:hover, .post-related a:hover {opacity:.8;}'
             // Standard FAQ block (Wave 76)
             . '.lu-faq{margin:3rem 0;padding-top:2rem;border-top:1px solid rgba(255,255,255,.1)}'
             . '.lu-faq-title{font-size:1.6rem;margin-bottom:1.5rem;font-weight:500}'
             . '.lu-faq-item{margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid rgba(255,255,255,.06)}'
             . '.lu-faq-item:last-child{border-bottom:none}'
             . '.lu-faq-q{font-size:1.05rem;font-weight:500;margin:0 0 .6rem 0;line-height:1.4}'
             . '.lu-faq-a{margin:0;line-height:1.7;opacity:.9}'
             . '</style>';
        if (stripos($html, '</head>') !== false) {
            return preg_replace('#</head>#i', $css . "\n</head>", $html, 1);
        }
        return $css . $html;
    }

    /**
     * Inject the chatbot bootstrap script before </body> when the workspace
     * has the chatbot enabled. Runs AFTER the cached HTML is retrieved so a
     * settings toggle takes effect without needing to bust the page cache.
     */
    /**
     * SEO/social: fill empty canonical + og:url with the page's canonical URL and make
     * relative og:image/twitter:image absolute (Open Graph requires absolute URLs).
     * Generated sites served empty canonical + relative og:image -> broken previews.
     * Canonical host = custom domain if set, else the *.levelupgrowth.io subdomain.
     */
    private function absolutizeSocialMeta(string $html, $website, string $slug): string
    {
        $host = '';
        if (! empty($website->custom_domain)) { $host = strtolower(trim((string) $website->custom_domain, ' /')); }
        elseif (! empty($website->subdomain)) { $host = strtolower(trim((string) $website->subdomain, ' /')); }
        if ($host === '') { return $html; }
        $base = 'https://' . $host;
        $pageUrl = $base . (($slug === '' || $slug === 'home') ? '/' : '/' . ltrim($slug, '/'));
        $html = preg_replace('#(<link\\s+rel=["\']canonical["\']\\s+href=)["\'][^"\']*["\']#i', '$1"' . e($pageUrl) . '"', $html, 1);
        $html = preg_replace('#(<meta\\s+property=["\']og:url["\']\\s+content=)["\'][^"\']*["\']#i', '$1"' . e($pageUrl) . '"', $html, 1);
        $html = preg_replace_callback('#(<meta\\s+(?:property|name)=["\'](?:og:image|twitter:image)["\']\\s+content=)["\'](/[^"\']*)["\']#i', function ($m) use ($base) { return $m[1] . '"' . $base . $m[2] . '"'; }, $html);
        return $html;
    }

    /**
     * LEAD-1 (2026-08-29, EV-0870) — template booking / reservation / quote forms shipped with
     * `onsubmit="event.preventDefault();alert('Your table is reserved! …')"`: a fabricated success for
     * the customer's visitors (no lead, no booking, no email). Every served page rewrites those
     * handlers to a real submission (POST /api/public/booking/by-host → lead + pending booking +
     * owner notification) with an honest inline reply. Covers sites exported before the template
     * sources were fixed; new exports carry `onsubmit="return luSubmitBooking(event)"` already.
     */
    private function injectBookingForms(string $html): string
    {
        if (stripos($html, '<form') === false) return $html;
        $html = preg_replace_callback(
            '/onsubmit="event\x2epreventDefault\x28\x29;\s*alert\x28\x27((?:[^\x27\x5c]|\x5c.)*)\x27\x29;?"/i',
            function ($m) {
                $thanks = htmlspecialchars(stripslashes($m[1]), ENT_QUOTES, 'UTF-8');
                return 'onsubmit="return luSubmitBooking(event)" data-lu-form="booking" data-lu-thanks="' . $thanks . '"';
            },
            $html
        ) ?? $html;
        if (! str_contains($html, 'luSubmitBooking')) return $html;
        if (str_contains($html, 'id="lu-booking-js"')) return $html;
        $js = <<<'JS'
<script id="lu-booking-js">
(function(){
  if (window.luSubmitBooking) return;
  function fieldKey(el, idx){
    var n = (el.getAttribute('name') || el.id || '').toLowerCase();
    var t = (el.getAttribute('type') || el.tagName).toLowerCase();
    var ph = (el.getAttribute('placeholder') || '').toLowerCase();
    var lab = '';
    try { var l = el.closest('div,label'); lab = l ? (l.querySelector('label') ? l.querySelector('label').textContent : '').toLowerCase() : ''; } catch(e){}
    var hint = n + ' ' + ph + ' ' + lab;
    if (n === 'email' || t === 'email' || /e-?mail/.test(hint)) return 'email';
    if (n === 'phone' || t === 'tel' || /phone|mobile|tel/.test(hint)) return 'phone';
    if (n === 'name' || /\bname\b|full name|your name/.test(hint) && !/company|business/.test(hint)) return 'name';
    if (t === 'date' || /date/.test(hint)) return 'preferred_date';
    if (t === 'time' || /time/.test(hint)) return 'preferred_time';
    if (/party|guests|people|size/.test(hint)) return 'party_size';
    if (/service|occasion|treatment|package|interest|reason/.test(hint)) return 'service';
    if (el.tagName === 'TEXTAREA' || /note|message|details|comments/.test(hint)) return 'notes';
    return 'x_' + (n || (t + '_' + idx));
  }
  window.luSubmitBooking = function(e){
    e.preventDefault();
    var form = e.target, btn = form.querySelector('button[type=submit],button:not([type]),input[type=submit]');
    var payload = { form: form.getAttribute('data-lu-form') || 'booking', extra: {}, hp: '' };
    var els = form.querySelectorAll('input,select,textarea');
    for (var i = 0; i < els.length; i++) {
      var el = els[i]; if (!el || el.type === 'submit' || el.type === 'button') continue;
      var k = fieldKey(el, i), v = (el.value || '').trim(); if (!v) continue;
      if (k.indexOf('x_') === 0) payload.extra[k.slice(2)] = v; else if (!payload[k]) payload[k] = v; else payload.extra[k + '_' + i] = v;
    }
    if (!payload.email && !payload.phone) {
      var need = form.querySelector('[data-lu-contact]');
      if (!need) {
        need = document.createElement('div'); need.setAttribute('data-lu-contact', '1'); need.style.cssText = 'margin:8px 0;display:flex;flex-direction:column;gap:6px';
        need.innerHTML = '<label style="font-size:.85em">Email or phone so we can confirm</label><input type="email" name="email" placeholder="you@example.com" required style="padding:.6em;border:1px solid #ccc;border-radius:6px">';
        (btn && btn.parentNode === form ? form.insertBefore(need, btn) : form.appendChild(need));
        need.querySelector('input').focus();
        return false;
      }
    }
    var old = btn ? btn.textContent : ''; if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
    var msg = form.querySelector('[data-lu-msg]');
    if (!msg) { msg = document.createElement('div'); msg.setAttribute('data-lu-msg', '1'); msg.setAttribute('role', 'status'); msg.style.cssText = 'margin-top:10px;font-size:.95em'; form.appendChild(msg); }
    fetch('/api/public/booking/by-host', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) })
      .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
      .then(function(res){
        if (res.ok && res.j && res.j.success) {
          msg.style.color = '#15803d'; msg.textContent = res.j.message || 'Request received — we will confirm with you shortly.';
          form.reset(); if (btn) { btn.textContent = 'Request sent'; }
        } else {
          msg.style.color = '#b91c1c'; msg.textContent = (res.j && res.j.message) || 'We could not send your request — please call or email us.';
          if (btn) { btn.disabled = false; btn.textContent = old; }
        }
      })
      .catch(function(){ msg.style.color = '#b91c1c'; msg.textContent = 'We could not send your request — please call or email us.'; if (btn) { btn.disabled = false; btn.textContent = old; } });
    return false;
  };
})();
</script>
JS;
        return str_contains($html, '</body>') ? str_replace('</body>', $js . "
</body>", $html) : $html . $js;
    }

    private function injectChatbotWidget(string $html, int $workspaceId, int $websiteId): string
    {
        if ($workspaceId <= 0) return $html;
        // Already has a chatbot widget? Don't add a second. Covers BOTH the
        // dynamic loader (chatbot.js?ws=) and the static/baked widget
        // (chatbot-widget.js) so static-served sites don't double up.
        //
        // INC-0006: a site exported before websites were separable carries a tag naming only the
        // workspace. Returning here left those pages permanently on the old behaviour — the widget
        // could not tell which of the business's sites it was on, and a per-site opt-out was ignored
        // because the injection path that reads it never ran. So upgrade the tag in place instead.
        if (str_contains($html, 'chatbot-widget.js')) return $html;
        if (str_contains($html, 'chatbot.js?ws=')) {
            return $websiteId > 0
                ? $this->bindBakedChatbotTagToWebsite($html, $workspaceId, $websiteId)
                : $html;
        }

        // Cheap workspace-scoped lookup, cached 60s so we don't hit the DB
        // on every page render.
        // INC-0006: keyed per WEBSITE. One business holds several sites, each with its own chatbot
        // row and its own opt-out; a workspace-only key served site A the answer cached for site B.
        $key = "chatbot_enabled_ws_{$workspaceId}_w{$websiteId}";
        $enabled = Cache::remember($key, 60, function () use ($workspaceId, $websiteId) {
            // CHATBOT888 entitlement-first (DEC-0027 / Owner): the chatbot is included
            // on the $49+ tier, so an entitled workspace gets it BY DEFAULT — including
            // fresh sites with no chatbot_settings row yet. A workspace can opt out with
            // chatbot_settings.enabled = 0; a non-entitled (< $49) workspace never gets it
            // even if a stale enabled=1 row lingers after a downgrade.
            if (! app(\App\Core\Billing\FeatureGateService::class)->canAccessChatbot($workspaceId)) {
                return false;
            }
            $row = \App\Core\Tenancy\WebsiteScope::settingsRow('chatbot_settings', $workspaceId, $websiteId);
            return $row === null ? true : (bool) $row->enabled;
        });
        if (! $enabled) return $html;

        // Use the staging/production platform domain explicitly. config('app.url')
        // can return the bare IP on this droplet which would cause mixed-content
        // and CORS failures from tenant subdomains.
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
        $origin = (str_contains($appHost, 'levelupgrowth.io'))
            ? 'https://' . ($appHost ?: 'staging.levelupgrowth.io')
            : 'https://staging.levelupgrowth.io';
        // INC-0006: carry the website id too. A business can run several sites from one workspace,
        // and the bootstrap has to know which one it is being embedded on to serve that site
        // its own greeting and colour rather than a sibling's.
        $tag = '<script src="' . $origin . '/chatbot.js?ws=' . $workspaceId
             . '&w=' . $websiteId . '" async></script>';

        $needle = '</body>';
        $pos = strripos($html, $needle);
        if ($pos === false) return $html . "\n" . $tag;
        return substr($html, 0, $pos) . $tag . substr($html, $pos);
    }

    /**
     * INC-0006 — bring a legacy baked bootstrap tag up to the per-website contract.
     *
     * Exported pages carry `chatbot.js?ws=N` with no website. Two things follow: the widget cannot
     * identify the site it is on, and this website's own opt-out is never consulted, because the check
     * lives on the injection path that a baked tag skips. Both are fixed here — the tag gains the
     * website, and a site that has switched its chatbot off has the tag removed entirely.
     */
    private function bindBakedChatbotTagToWebsite(string $html, int $workspaceId, int $websiteId): string
    {
        $key = "chatbot_enabled_ws_{$workspaceId}_w{$websiteId}";
        $enabled = Cache::remember($key, 60, function () use ($workspaceId, $websiteId) {
            if (! app(\App\Core\Billing\FeatureGateService::class)->canAccessChatbot($workspaceId)) {
                return false;
            }
            $row = \App\Core\Tenancy\WebsiteScope::settingsRow('chatbot_settings', $workspaceId, $websiteId);

            return $row === null ? true : (bool) $row->enabled;
        });

        // Matches the baked tag whether or not it already carries a website, so this is idempotent.
        $pattern = '#<script[^>]+chatbot\.js\?ws=' . $workspaceId . '(?:&(?:amp;)?w=\d+)?"[^>]*></script>#i';

        if (! $enabled) {
            return (string) preg_replace($pattern, '', $html);
        }

        if (str_contains($html, 'chatbot.js?ws=' . $workspaceId . '&w=' . $websiteId)) {
            return $html;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
        $origin = (str_contains($appHost, 'levelupgrowth.io'))
            ? 'https://' . ($appHost ?: 'staging.levelupgrowth.io')
            : 'https://staging.levelupgrowth.io';
        $tag = '<script src="' . $origin . '/chatbot.js?ws=' . $workspaceId
             . '&w=' . $websiteId . '" async></script>';

        return (string) preg_replace($pattern, $tag, $html, 1);
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

        // 2026-05-23 FIX 26 — use the site's canonical public host. If the
        // workspace has a custom_domain configured, the sitemap should
        // advertise that as the canonical URL (matches seo_content_index
        // entries written by the publish flow, and is the host the user
        // actually wants Google to associate with their content). Falls
        // back to the platform subdomain if no custom domain set.
        $canonHost = !empty($website->custom_domain) ? strtolower(trim($website->custom_domain, ' /')) : $fullSub;

        $pages = DB::table('pages')
            ->where('website_id', $website->id)
            ->where('status', 'published')
            ->orderBy('position')
            ->get(['slug', 'updated_at']);

        // 2026-05-23 FIX 25 — include published articles. Previously only the
        // pages table was queried, so blog posts (which live in articles)
        // never appeared in the per-tenant sitemap and were invisible to
        // search engines. Chef-red had 32 articles in the DB but the sitemap
        // listed only "/". Now we emit /blog/{slug} entries for every
        // published article in this workspace.
        $articles = DB::table('articles')
            ->where('workspace_id', $website->workspace_id)
            ->where('status', 'published')
            ->whereNotNull('slug')
            ->orderByDesc('published_at')
            ->get(['slug', 'updated_at', 'published_at']);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($pages as $page) {
            $loc = "https://{$canonHost}/" . ($page->slug === 'home' ? '' : $page->slug);
            $lastmod = $page->updated_at ? date('Y-m-d', strtotime($page->updated_at)) : date('Y-m-d');
            $priority = $page->slug === 'home' ? '1.0' : '0.8';
            $xml .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$lastmod}</lastmod>\n    <changefreq>weekly</changefreq>\n    <priority>{$priority}</priority>\n  </url>\n";
        }

        // KABAYAN888 G7b/JOBS-1 — article base from settings_json.article_base; job listings after articles.
        $__set = $website->settings_json ?? '{}'; if (is_string($__set)) $__set = json_decode($__set, true) ?: [];
        $__base = preg_match('/^[a-z0-9\-]{1,40}$/', (string) ($__set['article_base'] ?? '')) ? $__set['article_base'] : 'blog';
        try {
            foreach (app(\App\Engines\Jobs\Services\JobsService::class)->publicQuery((int) $website->id)->orderByDesc('posted_at')->limit(500)->get(['slug', 'updated_at', 'posted_at']) as $__job) {
                $__ref = $__job->updated_at ?: $__job->posted_at; $__lm = $__ref ? date('Y-m-d', strtotime($__ref)) : date('Y-m-d');
                $xml .= "  <url>\n    <loc>https://{$canonHost}/jobs/" . e($__job->slug) . "</loc>\n    <lastmod>{$__lm}</lastmod>\n    <changefreq>daily</changefreq>\n    <priority>0.6</priority>\n  </url>\n";
            }
        } catch (\Throwable) {}
        foreach ($articles as $article) {
            $slug = trim((string) $article->slug, '/');
            if ($slug === '') continue;
            $loc = "https://{$canonHost}/{$__base}/" . $slug;
            $ref = $article->published_at ?: $article->updated_at;
            $lastmod = $ref ? date('Y-m-d', strtotime($ref)) : date('Y-m-d');
            $xml .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$lastmod}</lastmod>\n    <changefreq>weekly</changefreq>\n    <priority>0.7</priority>\n  </url>\n";
        }

        $xml .= '</urlset>';

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * 2026-05-23 FIX 25 — serve the IndexNow key file at {host}/{key}.txt.
     * IndexNow ownership verification: the key file body must equal the key.
     * Reads the key from seo_settings.indexnow_key for this workspace; 404
     * if no key configured OR the requested key doesn't match.
     */
    private function serveIndexNowKey(string $subdomain, string $requestedKey): \Illuminate\Http\Response
    {
        $fullSub = $subdomain . '.levelupgrowth.io';
        $website = DB::table('websites')
            ->where('subdomain', $fullSub)
            ->where('status', 'published')
            ->first(['id', 'workspace_id']);
        if (!$website) {
            return response('Not Found', 404)->header('Content-Type', 'text/plain');
        }
        // INC-0006: an IndexNow key proves ownership of ONE host, so it is this website's key.
        // Reading the workspace-wide row made a business's second site serve the first site's
        // key at its own /{key}.txt, which fails verification for both.
        $storedKey = \App\Core\Tenancy\WebsiteScope::seo(
            (int) $website->workspace_id, (int) $website->id, 'indexnow_key'
        );
        if (!$storedKey || !hash_equals((string) $storedKey, $requestedKey)) {
            return response('Not Found', 404)->header('Content-Type', 'text/plain');
        }
        return response($storedKey, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Serve robots.txt for a published website.
     */
    private function serveRobots(string $subdomain): \Illuminate\Http\Response
    {
        $fullSub = $subdomain . '.levelupgrowth.io';

        // 2026-06-11 — robots.txt must advertise the sitemap on the site's
        // CANONICAL public host (the custom domain if set), NOT the internal
        // *.levelupgrowth.io subdomain. Google IGNORES a Sitemap: directive on
        // a different host than the site being crawled, so chef-red's
        // chefredraymundo.com/robots.txt pointing at chef-red.levelupgrowth.io
        // meant Google never used the sitemap. Mirrors FIX 26 in serveSitemap().
        $website = \Illuminate\Support\Facades\DB::table('websites')
            ->where('subdomain', $fullSub)
            ->where('status', 'published')
            ->first(['workspace_id', 'custom_domain']);
        $canonHost = (!empty($website) && !empty($website->custom_domain))
            ? strtolower(trim($website->custom_domain, ' /'))
            : $fullSub;
        $sitemapUrl = "https://{$canonHost}/sitemap.xml";

        // Wave 46 — per-tenant AI-crawler directives via aeo_settings.
        // Falls back to permissive default if workspace lookup fails.
        try {
            if ($website) {
                $svc = app(\App\Engines\SEO\Services\AeoSettingsService::class);
                $content = $svc->renderRobotsTxt((int) $website->workspace_id, $sitemapUrl);
                return response($content, 200)
                    ->header('Content-Type', 'text/plain; charset=utf-8')
                    ->header('Cache-Control', 'public, max-age=86400');
            }
        } catch (\Throwable $e) {
            // fall through to default
        }

        $content = "User-agent: *\nAllow: /\n\nSitemap: {$sitemapUrl}\n";
        return response($content, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Wave 46 — serve llms.txt for a tenant subdomain.
     */
    private function serveLlmsTxt(string $subdomain): \Illuminate\Http\Response
    {
        $fullSub = $subdomain . '.levelupgrowth.io';

        try {
            $website = \Illuminate\Support\Facades\DB::table('websites')
                ->where('subdomain', $fullSub)
                ->where('status', 'published')
                ->first(['workspace_id']);
            if ($website) {
                $svc = app(\App\Engines\SEO\Services\AeoSettingsService::class);
                $body = $svc->getLlmsTxt((int) $website->workspace_id);
                return response($body, 200)
                    ->header('Content-Type', 'text/markdown; charset=utf-8')
                    ->header('Cache-Control', 'public, max-age=21600'); // 6h
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return response("# Site not found\n\nThis subdomain has no published content.\n", 404)
            ->header('Content-Type', 'text/markdown; charset=utf-8');
    }
}
