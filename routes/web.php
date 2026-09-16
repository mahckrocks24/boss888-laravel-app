<?php

use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| Admin panel — served at /admin/* (requires is_platform_admin)
| SaaS app    — served at /app/* (React SPA, auth via JWT in localStorage)
| Marketing   — served at / and /pages/* (public static HTML from plugin)
*/

// ── On-the-fly thumbnails (T3.1D) ────────────────────────────────────────────
// Self-healing cache: nginx try_files misses on first request, falls through
// to PHP, controller generates + saves to disk. Subsequent requests served
// directly by nginx. NO catchall consumes /storage paths above this.
Route::get('/storage/thumbnails/{path}', [\App\Http\Controllers\ThumbnailController::class, 'serve'])
    ->where('path', '.*');

// ── Admin Panel ──────────────────────────────────────────────────────────────
// RESUME888 — signed PDF download + magic "continue" link (site host). Names are referenced by PublicResumeController.
Route::get('/resume-download/{resume}', [\App\Http\Controllers\Api\Widget\PublicResumeController::class, 'download'])->whereNumber('resume')->name('resume.download');
Route::get('/resume/continue/{session}', [\App\Http\Controllers\Api\Widget\PublicResumeController::class, 'continueLink'])->whereNumber('session')->name('resume.resume');

Route::prefix('admin')->group(function () {
    Route::get('/login', function () {
        return view('admin.login');
    })->name('admin.login');

    // PLATFORM SECURITY 1.0 (2026-08-04) — ending the session server-side.
    //
    // POST, never GET: a GET that destroys a session can be fired by any <img>
    // on any site. The web group's CSRF validation covers this route, so only
    // the console's own origin can call it.
    //
    // Declared ABOVE the catchall and carrying no AdminSessionIdentity: an
    // expired or already-cleared session must still be able to sign out, which
    // is the moment this route matters most.
    Route::post('/logout', [\App\Http\Controllers\Admin\AdminLogoutController::class, 'logout'])
        ->name('admin.logout');

    // ── Multi-page admin (2026-07-29) ────────────────────────────────────────
    // Every menu item is its own URL, resolved from config/admin_pages.php.
    //
    // This replaces a catchall that returned the same 359KB view for every
    // /admin/* URL and booted a hardcoded dashboard: /admin/users returned 200
    // and showed the dashboard, and so did /admin/nonsense. An unknown slug now
    // 404s. `.*` is retained so nested slugs (ads/status, engineering/health)
    // match; the optional parameter lets bare /admin redirect to the dashboard.
    // PLATFORM SECURITY 1.0 (2026-08-03) — server-side identity, applied here.
    //
    // /admin/login is declared ABOVE this line and stays outside the middleware
    // on purpose: gating the login page behind identity is how a platform locks
    // everybody out. Route declaration order is what keeps it outside, so the
    // login route must never be moved below this one.
    //
    // The cookie this reads is written by /api/auth/login (api group) and read
    // here (web group). Those groups disagree about cookie encryption, which is
    // why bootstrap/app.php exempts lu_admin_at from EncryptCookies. Remove that
    // exemption and every request below becomes an infinite redirect to login.
// ── Template design gallery (2026-09-10, Owner) ───────────────────────────────────────────────
// "I need to see all of the templates and tell you exactly what is nice and not and needs replaced."
// A table of names cannot answer that, so this renders every template live, side by side, with the
// forensic flags that explain why several of them look the same.
// Registered BEFORE the /{slug?} catch-all below, which would otherwise swallow both paths.

// Replacement for the /admin/template-preview/{slug} and /admin/template-gallery route bodies.
// Two changes over the first version:
//   1. The preview seeds the manifest's own defaults before rendering. render() only substitutes
//      the variables it is handed, so the previous gallery was showing the Owner near-blank pages
//      with the layout but none of the words — which is not something you can judge a design on.
//   2. The gallery groups by INDUSTRY, because the question in front of the Owner is "which of the
//      three restaurant designs do I keep", not "here are ninety-one cards in signature order".

Route::middleware(\App\Http\Middleware\AdminSessionIdentity::class)
    ->get('/template-preview/{slug}', function (string $slug) {
        if (! preg_match('/^[a-z0-9_]{1,40}$/', $slug)) { abort(404); }
        $svc = app(\App\Engines\Builder\Services\TemplateService::class);
        $manifest = $svc->getManifest($slug);
        if (! $manifest) { abort(404); }

        // Seed every declared default, then overlay one neutral business so the copy does not
        // flatter one template over another where it matters (the name on the door).
        $vars = [];
        foreach (($manifest['variables'] ?? []) as $key => $spec) {
            $vars[$key] = is_array($spec) ? (string) ($spec['default'] ?? '') : (string) $spec;
        }
        $vars = array_merge($vars, [
            'business_name' => 'Northgate & Co', 'logo' => 'Northgate & Co', 'city' => 'Bristol',
            'location' => 'Bristol', 'phone' => '0117 496 0100', 'contact_phone' => '0117 496 0100',
            'email' => 'hello@northgate.example', 'contact_email' => 'hello@northgate.example',
            'contact_address' => '14 Colston Yard, Bristol BS1 5BD',
        ]);

        $html = $svc->render($slug, $vars);
        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    })->name('admin.template.preview');

Route::middleware(\App\Http\Middleware\AdminSessionIdentity::class)
    ->get('/template-gallery', function (\Illuminate\Http\Request $request) {
        $root = storage_path('templates');
        $rows = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) as $dir) {
            $slug = basename($dir);
            $tpl = $dir . '/template.html';
            if (! is_file($tpl)) { continue; }
            $html = (string) file_get_contents($tpl);
            $man = is_file($dir . '/manifest.json')
                ? (json_decode((string) file_get_contents($dir . '/manifest.json'), true) ?: []) : [];

            // Structural signature — the set of CSS class names, order-independent. Two templates
            // with the same signature are the same design wearing different words.
            preg_match_all('/\.([a-z][a-z0-9_-]{2,})\s*\{/i', $html, $cls);
            $classes = array_values(array_unique($cls[1] ?? []));
            sort($classes);

            preg_match_all('/data-field="([a-z0-9_]+)"/i', $html, $f);
            preg_match_all('/data-block="([a-z0-9_]+)"/i', $html, $b);

            $design = $man['design'] ?? null;
            $rows[$slug] = [
                'slug' => $slug,
                'name' => $man['name'] ?? $slug,
                'industry' => $man['industry'] ?? $slug,
                'variation' => $man['variation'] ?? '',
                'desc' => $man['description'] ?? '',
                'sig' => substr(sha1(implode(',', $classes)), 0, 10),
                'kb' => round(strlen($html) / 1024, 1),
                'fields' => count(array_unique($f[1] ?? [])),
                'blocks' => count(array_unique($b[1] ?? [])),
                'medical' => preg_match_all('/\b(medical|doctor|patient|dental|clinic|hygienist)\b/i', $html),
                'cert' => substr_count($html, 'cert-check'),
                'new' => isset($design['generator']),
                // Only ACTIVE templates are offered to customers; the gallery shows both.
                'active' => array_key_exists('is_active', $man) ? (bool) $man['is_active'] : true,
                'archetypes' => $design['archetypes'] ?? null,
                'palette' => $design['palette'] ?? null,
                'type' => $design['type'] ?? null,
            ];
        }
        $bySig = [];
        foreach ($rows as $s => $r) { $bySig[$r['sig']][] = $s; }
        foreach ($rows as $s => $r) {
            $rows[$s]['group'] = $bySig[$r['sig']];
            $rows[$s]['clone'] = count($bySig[$r['sig']]) > 1;
        }

        $filter = (string) $request->query('show', 'all');   // all | new | original | clones
        $rows = array_filter($rows, function ($r) use ($filter) {
            return match ($filter) {
                'new' => $r['new'], 'original' => ! $r['new'], 'clones' => $r['clone'], default => true,
            };
        });

        // Grouped by industry, clones first inside each group: the decision the Owner is making is
        // per industry — which of these designs do we keep for a restaurant.
        $byIndustry = [];
        foreach ($rows as $r) { $byIndustry[$r['industry']][] = $r; }
        ksort($byIndustry);
        foreach ($byIndustry as $k => $g) {
            usort($g, fn($a, $b) => [$b['clone'], $a['slug']] <=> [$a['clone'], $b['slug']]);
            $byIndustry[$k] = $g;
        }

        $distinct = count(array_unique(array_column($rows, 'sig')));
        $cloneGroups = count(array_filter($bySig, fn($g) => count($g) > 1));
        $medicalOnNonMedical = count(array_filter($rows, fn($r) => $r['medical'] > 20
            && ! in_array($r['industry'], ['dental', 'medical_clinic', 'aesthetic_clinic'], true)));
        $newCount = count(array_filter($rows, fn($r) => $r['new']));

        return view('admin.template-gallery', compact(
            'rows', 'byIndustry', 'distinct', 'cloneGroups', 'medicalOnNonMedical', 'newCount', 'filter'));
    })->name('admin.template.gallery');

    Route::middleware(\App\Http\Middleware\AdminSessionIdentity::class)
        ->get('/{slug?}', [\App\Http\Controllers\Admin\AdminPageController::class, 'show'])
        ->where('slug', '.*')->name('admin.page');
});

// ── Email verification (MISSION-018 WS-2, 2026-08-24) ────────────────────────
// Clicked from the address-confirmation email queued at registration. The
// 'signed' middleware validates the 72-hour temporary signature; the hash
// pins the link to the address it was issued for, so a changed email
// invalidates old links. Idempotent: a second click is a friendly no-op.
Route::get('/verify-email/{id}/{hash}', function (\Illuminate\Http\Request $request, int $id, string $hash) {
    $user = \App\Models\User::findOrFail($id);
    if (!hash_equals(sha1($user->email), $hash)) {
        abort(403, 'This verification link does not match this account.');
    }
    if ($user->email_verified_at === null) {
        $user->email_verified_at = now();
        $user->save();
    }
    return redirect('/app?verified=1');
})->middleware('signed')->name('verification.verify');

// ── SaaS App (React SPA) ──────────────────────────────────────────────────────
Route::get('/app/{any?}', function (\Illuminate\Http\Request $request) {
    // MISSION-018 WS launch-switch (2026-08-24): the SPA-shell guard the
    // marketing config names ("re-enable the SPA-shell guard flag") did not
    // exist — /app served the shell unconditionally, so on a production host
    // pre-launch anyone typing levelupgrowth.io/app entered the app, bypassing
    // the marketing gate the blades enforce on their own CTAs. Now: pre-launch,
    // the production marketing hosts redirect /app to the marketing home;
    // staging, the server IP and customer subdomains are unaffected, and once
    // public_launched flips the app is reachable everywhere.
    $launched = (bool) config('marketing.public_launched', false);
    $productionHosts = (array) config('marketing.production_hosts', []);
    if (! $launched && in_array($request->getHost(), $productionHosts, true)) {
        return redirect('/');
    }
    return response()->file(public_path('app/index.html'), ['Cache-Control' => 'no-cache, must-revalidate']);
})->where('any', '.*')->name('app');

// ── Marketing Site (static HTML from plugin) ──────────────────────────────────
// Homepage
// 2026-05-19 (Wave 32e) — Platform sitemap for the LevelUp Growth
// marketing pages. Auto-discovered routes; updated_at = today.
Route::get('/sitemap.xml', function (\Illuminate\Http\Request $r) {
    $base = $r->getSchemeAndHttpHost();  // e.g. https://staging.levelupgrowth.io
    $today = date('Y-m-d');
    $pages = [
        ['loc' => '/',                       'priority' => '1.0', 'changefreq' => 'weekly'],
        ['loc' => '/pages/why-levelup/',     'priority' => '0.9', 'changefreq' => 'monthly'],
        ['loc' => '/pages/pricing/',         'priority' => '0.9', 'changefreq' => 'monthly'],
        ['loc' => '/pages/builder/',         'priority' => '0.9', 'changefreq' => 'monthly'],
        ['loc' => '/pages/seo/',             'priority' => '0.8', 'changefreq' => 'monthly'],
        ['loc' => '/pages/automation/',      'priority' => '0.7', 'changefreq' => 'monthly'],
        ['loc' => '/pages/crm/',             'priority' => '0.8', 'changefreq' => 'monthly'],
        ['loc' => '/pages/ai-agents/',       'priority' => '0.8', 'changefreq' => 'monthly'],
        ['loc' => '/pages/ai-assistant/',   'priority' => '0.8', 'changefreq' => 'monthly'],
        ['loc' => '/pages/creative/',        'priority' => '0.7', 'changefreq' => 'monthly'],
        ['loc' => '/pages/video/',           'priority' => '0.7', 'changefreq' => 'monthly'],
        ['loc' => '/pages/calendar/',        'priority' => '0.7', 'changefreq' => 'monthly'],
        ['loc' => '/pages/how-it-works/',    'priority' => '0.7', 'changefreq' => 'monthly'],
        ['loc' => '/pages/use-cases/',       'priority' => '0.7', 'changefreq' => 'monthly'],
        ['loc' => '/pages/comparison/',      'priority' => '0.7', 'changefreq' => 'monthly'],
        ['loc' => '/pages/faq/',             'priority' => '0.7', 'changefreq' => 'monthly'],
        ['loc' => '/pages/results/',         'priority' => '0.5', 'changefreq' => 'monthly'],
        ['loc' => '/blog/',                  'priority' => '0.7', 'changefreq' => 'weekly'],
    ];
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "
";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "
";
    foreach ($pages as $p) {
        $xml .= '  <url>' . "
";
        $xml .= '    <loc>' . htmlspecialchars($base . $p['loc']) . '</loc>' . "
";
        $xml .= '    <lastmod>' . $today . '</lastmod>' . "
";
        $xml .= '    <changefreq>' . $p['changefreq'] . '</changefreq>' . "
";
        $xml .= '    <priority>' . $p['priority'] . '</priority>' . "
";
        $xml .= '  </url>' . "
";
    }
    $xml .= '</urlset>';
    return response($xml, 200)
        ->header('Content-Type', 'application/xml; charset=UTF-8')
        ->header('Cache-Control', 'public, max-age=3600');
});

Route::get('/robots.txt', function (\Illuminate\Http\Request $r) {
    $base = $r->getSchemeAndHttpHost();
    $content = "User-agent: *
Allow: /
Disallow: /app
Disallow: /app/

Sitemap: https://levelupgrowth.io/sitemap.xml
";
    return response($content, 200)->header('Content-Type', 'text/plain');
});

Route::get('/', function () {
    return response()->file(public_path('marketing/index.html'));
})->name('home');

// Core page aliases (SEO-friendly URLs)
Route::get('/pricing', function () {
    return response()->file(public_path('marketing/pages/pricing.html'));
})->name('pricing');

Route::get('/features', function () {
    return response()->file(public_path('marketing/pages/ai-agents.html'));
})->name('features');

Route::get('/specialists', function () {
    // MW-1b P4 - AI Workforce consolidation
    return redirect('/pages/ai-agents/', 301);
})->name('specialists');

Route::get('/faq', function () {
    return response()->file(public_path('marketing/pages/faq.html'));
})->name('faq');

Route::get('/sign-up', function () {
    // 2026-05-11: production hostnames bounce to homepage; staging + others
    // continue to serve the pricing page that drives the signup flow.
    if (in_array(request()->getHost(), ['levelupgrowth.io', 'www.levelupgrowth.io'], true)) {
        return redirect('/', 302);
    }
    return response()->file(public_path('marketing/pages/pricing.html'));
})->name('signup');

// All marketing pages (direct URL access)
Route::get('/how-it-works', function () {
    return response()->file(public_path('marketing/pages/how-it-works.html'));
})->name('how-it-works');

Route::get('/ai-agents', function () {
    return response()->file(public_path('marketing/pages/ai-agents.html'));
})->name('ai-agents');

Route::get('/ai-assistant', function () {
    // MRC-1: Aria restored as a distinct page (Platform Intelligence).
    return redirect('/pages/ai-assistant/', 301);
})->name('ai-assistant');

Route::get('/builder', function () {
    return response()->file(public_path('marketing/pages/builder.html'));
})->name('builder');

Route::get('/calendar', function () {
    return response()->file(public_path('marketing/pages/calendar.html'));
})->name('calendar');

Route::get('/comparison', function () {
    return response()->file(public_path('marketing/pages/comparison.html'));
})->name('comparison');

Route::get('/creative', function () {
    return response()->file(public_path('marketing/pages/creative.html'));
})->name('creative');

Route::get('/crm', function () {
    return response()->file(public_path('marketing/pages/crm.html'));
})->name('crm');

Route::get('/email', function () {
    return redirect('/pages/crm/', 301);
})->name('email');

Route::get('/results', function () {
    return response()->file(public_path('marketing/pages/results.html'));
})->name('results');

Route::get('/use-cases', function () {
    return response()->file(public_path('marketing/pages/use-cases.html'));
})->name('use-cases');

Route::get('/video', function () {
    return response()->file(public_path('marketing/pages/video.html'));
})->name('video');

Route::get('/assistant', function () {
    // MRC-1: legacy /assistant now points to Aria (Platform Intelligence).
    return redirect('/pages/ai-assistant/', 301);
})->name('assistant');

// Pages catch-all (handles /pages/pricing/, /pages/how-it-works/ etc. from nav links)
Route::get('/pages/{slug}', function (string $slug) {
    // MRC-1: Aria restored as a distinct page. 'assistant' (legacy) -> Aria;
    // 'specialists' stays consolidated into the AI Workforce hub.
    $consolidated = ['assistant' => '/pages/ai-assistant/', 'specialists' => '/pages/ai-agents/',
        // Email Marketing removed from launch scope 2026-07-24; CRM now owns the
        // approved one-to-one and workflow-triggered email capability.
        'email' => '/pages/crm/'];
    if (isset($consolidated[$slug])) { return redirect($consolidated[$slug], 301); }
    $file = public_path('marketing/pages/' . basename($slug) . '.html');
    if (file_exists($file)) {
        return response()->file($file);
    }
    abort(404);
})->where('slug', '[a-z0-9-]+')->name('marketing.page');

// ── INFRA888 · E7.3 — Business Email mailbox setup (public, token-bound) ──────
//
// The customer follows a LEVELUP link and sets their own password on a LEVELUP
// page. The email provider is never in the conversation — its own invitation
// email names the vendor, which is why this exists.
//
// Unauthenticated by necessity: the recipient has no LevelUp account. The token
// carries the tenancy, is single-use, expires in 72 hours, and is rate limited.
// `web` middleware only — no session dependency, no CSRF token to leak into the
// page, because the POST is authorised by the bearer-like token in the URL.
Route::get('/business-email/setup/{token}', [\App\Http\Controllers\MailboxSetupController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')
    ->name('business-email.setup');

Route::post('/business-email/setup/{token}', [\App\Http\Controllers\MailboxSetupController::class, 'store'])
    ->where('token', '[a-f0-9]{64}')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('business-email.setup.store');

// Invite acceptance page (public)
Route::get('/invite/{token}', function (string $token) {
    return view('invite', ['token' => $token]);
})->name('invite');

// Published sites now served via PublishedSiteMiddleware (not domain routes)


// ── Chatbot888 public widget bootstrap (2026-05-09) ──────────────────────
// GET /chatbot.js?ws=N
// Returns vanilla-JS bootstrap that mounts a chat bubble in the bottom-right
// corner of the host page. Workspace gating + chatbot.enabled check + plan
// check (Pro+/Agency via FeatureGateService::canAccessChatbot) all happen
// here — if any check fails, we still return valid (empty) JS so the host
// page never sees a JS error in console.
Route::get('/chatbot.js', function (\Illuminate\Http\Request $r) {
    $wsId = (int) $r->query('ws', 0);
    // INC-0006: which website this widget is being embedded on. Older embeds predate the
    // parameter and resolve to the business-wide default row, exactly as they did before.
    $cbWebsiteId = (int) $r->query('w', 0);
    $reject = function (string $reason) {
        $body = "/* LevelUp chatbot — disabled: {$reason} */";
        return response($body, 200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=60');
    };

    if ($wsId <= 0) return $reject('missing_ws');

    // Plan check + chatbot enabled check
    $gate = app(\App\Core\Billing\FeatureGateService::class);
    if (! $gate->canAccessChatbot($wsId)) return $reject('plan_required');

    $settings = \App\Core\Tenancy\WebsiteScope::settingsRow('chatbot_settings', $wsId, $cbWebsiteId);
    if (! $settings || ! $settings->enabled) return $reject('chatbot_disabled');

    // Discover the embed host from Origin (cross-origin) or Referer.
    // Without it we can't allowlist anything; reject so we don't accumulate
    // domain-less tokens.
    $hostHeader = $r->header('Origin') ?: $r->header('Referer') ?: '';
    $embedHost = '';
    if ($hostHeader) {
        $parsed = parse_url($hostHeader);
        $embedHost = strtolower($parsed['host'] ?? '');
    }
    if ($embedHost === '') return $reject('no_origin');
    // F-CB-B1 (2026-09-06): only the workspace's own sites (and platform previews) may embed its widget. A stranger's
    // page gets nothing and the allow-list is never touched — the Origin allow-list is the boundary, so it must not be
    // self-service. Proven live: a foreign origin got itself allow-listed on ws 999993 and opened sessions.
    if (! app(\App\Engines\Chatbot\Services\ChatbotWidgetTokenService::class)->hostBelongsToWorkspace($wsId, $embedHost)) {
        \Illuminate\Support\Facades\Log::info('[chatbot] loader refused a foreign embed host', ['ws' => $wsId, 'host' => $embedHost, 'ip' => $r->ip()]);
        return $reject('host_not_allowed');
    }

    // Token policy: the chatbot widget token is PUBLIC by design (it ships
    // in the script tag). Domain allowlist + revocation are the security
    // boundary. To avoid token-table bloat, auto-bootstrap mints only one
    // token per (workspace, label='Auto-bootstrap'); subsequent calls
    // reuse it and ADD the new host to its allowed_domains list.
    $tokenSvc = app(\App\Engines\Chatbot\Services\ChatbotWidgetTokenService::class);
    $existing = \Illuminate\Support\Facades\DB::table('chatbot_widget_tokens')
        ->where('workspace_id', $wsId)
        ->where('status', 'active')
        ->where('label', 'Auto-bootstrap')
        ->orderByDesc('id')
        ->first();

    $rawToken = null;
    if ($existing) {
        // Token hash is one-way; we don't have the plaintext anymore. To
        // keep the embed snippet stable, we encode the plaintext in
        // settings_json on first mint. If it's missing, we'll mint anew.
        $allowedDomains = json_decode($existing->allowed_domains_json ?: '[]', true) ?: [];
        if (! in_array($embedHost, $allowedDomains, true)) {
            $allowedDomains[] = $embedHost;
            \Illuminate\Support\Facades\DB::table('chatbot_widget_tokens')
                ->where('id', $existing->id)
                ->update(['allowed_domains_json' => json_encode($allowedDomains), 'updated_at' => now()]);
        }
        // Token plaintext is cached in workspace meta the first time we mint.
        $cachedRow = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->first();
        $meta = is_string($cachedRow->settings_json ?? null) ? json_decode($cachedRow->settings_json, true) : ($cachedRow->settings_json ?? []);
        $meta = is_array($meta) ? $meta : [];
        $rawToken = $meta['chatbot_bootstrap_token'] ?? null;
    }
    if (! $rawToken) {
        try {
            $minted = $tokenSvc->mint($wsId, null, null, [$embedHost], 'Auto-bootstrap');
            $rawToken = $minted['plain'] ?? null;
            // Cache plaintext in workspaces.settings_json so we can reuse it
            // without re-minting (and without an extra column).
            if ($rawToken) {
                $wsRow = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->first();
                $meta = is_string($wsRow->settings_json ?? null) ? json_decode($wsRow->settings_json, true) : ($wsRow->settings_json ?? []);
                $meta = is_array($meta) ? $meta : [];
                $meta['chatbot_bootstrap_token'] = $rawToken;
                \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)
                    ->update(['settings_json' => json_encode($meta), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            return $reject('token_mint_failed: ' . $e->getMessage());
        }
    }
    if (! $rawToken) return $reject('no_token');

    // API_BASE = the scheme+host of the request (so the widget calls back
    // to the same domain it was served from). Cloudflare/nginx pass this
    // through correctly. Falls back to staging if request data is absent.
    $apiBase = rtrim($r->getSchemeAndHttpHost() ?: 'https://staging.levelupgrowth.io', '/');
    // Force https in case Laravel sees http behind Cloudflare's proxy.
    $apiBase = preg_replace('#^http://#', 'https://', $apiBase);

    // PATCH (chatbot bootstrap business name + brand color, 2026-05-09) —
    // Resolve the workspace's primary website so we can substitute
    // {{business}} in the greeting AND inject the website's
    // template_variables.primary_color before baking the bootstrap JS.
    // Order: try Origin/Referer host match first (correct site even
    // when ws has multiple sites), fall back to most-recent-published.
    $hostHeader = $r->header('Origin') ?: $r->header('Referer') ?: '';
    $embedHostForLookup = '';
    if ($hostHeader) {
        $parsedH = parse_url($hostHeader);
        $embedHostForLookup = strtolower($parsedH['host'] ?? '');
    }
    $websiteForWs = null;
    // 2026-09-14: the page says which website it is (w=), and that beats any host or recency guess.
    if ($cbWebsiteId > 0) {
        $websiteForWs = \Illuminate\Support\Facades\DB::table('websites')
            ->where('id', $cbWebsiteId)->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->first(['id', 'name', 'template_variables']);
    }
    if (! $websiteForWs && $embedHostForLookup !== '' && ! in_array($embedHostForLookup, ['levelupgrowth.io', 'www.levelupgrowth.io', 'staging.levelupgrowth.io'], true)) {
        $websiteForWs = \Illuminate\Support\Facades\DB::table('websites')
            ->where('workspace_id', $wsId)
            ->where(function ($q) use ($embedHostForLookup) {
                $q->where('subdomain', $embedHostForLookup)
                  ->orWhere('subdomain', explode('.', $embedHostForLookup)[0])
                  ->orWhere('custom_domain', $embedHostForLookup);
            })
            ->whereNull('deleted_at')
            ->first(['id', 'name', 'template_variables']);
    }
    if (! $websiteForWs) {
        $websiteForWs = \Illuminate\Support\Facades\DB::table('websites')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('status', 'published')->orWhere('status', 'draft');
            })
            ->orderByRaw("CASE WHEN status='published' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->first(['id', 'name', 'template_variables']);
    }

    $workspaceRow = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->first(['business_name', 'name']);
    $businessName = (string) ($websiteForWs->name ?? $workspaceRow->business_name ?? $workspaceRow->name ?? 'us');

    // Pull primary_color from website's template_variables, fall back
    // to chatbot_settings.primary_color, fall back to platform purple.
    $primaryColor = null;
    if ($websiteForWs && ! empty($websiteForWs->template_variables)) {
        $tv = is_string($websiteForWs->template_variables) ? json_decode($websiteForWs->template_variables, true) : null;
        if (is_array($tv) && ! empty($tv['primary_color'])) {
            $primaryColor = (string) $tv['primary_color'];
        }
    }
    if (! $primaryColor) $primaryColor = (string) ($settings->primary_color ?? '#6C5CE7');

    // Substitute {{business}} / {{business_name}} in the greeting before
    // baking. Without this, the widget renders the literal token in its
    // first bubble (the widget JS uses GREETING constant directly, no
    // re-fetch from /config — that flow only fires on widget-open).
    $rawGreeting = (string) ($settings->greeting ?? 'Hi! Welcome to {{business}}. How can I help you today?');
    $greeting    = str_replace(['{{business}}', '{{business_name}}'], $businessName, $rawGreeting);

    $color = $primaryColor;
    $theme = (string) ($settings->theme ?? 'auto');

    // Bootstrap JS — embeds token + greeting; talks to /api/public/chatbot/*
    $tokenJs    = json_encode($rawToken, JSON_UNESCAPED_SLASHES);
    $apiBaseJs  = json_encode($apiBase, JSON_UNESCAPED_SLASHES);
    $greetingJs = json_encode($greeting, JSON_UNESCAPED_UNICODE);
    $colorJs    = json_encode($color);
    $themeJs    = json_encode($theme);
    $wsIdJs     = (int) $wsId;

    $js = <<<JS
/* LevelUp Chatbot888 widget — auto-generated for workspace {$wsIdJs} */
(function(){
  if (window.__luChatbotMounted) return; window.__luChatbotMounted = true;
  var TOKEN    = {$tokenJs};
  var API_BASE = {$apiBaseJs};
  var GREETING = {$greetingJs};
  var COLOR    = {$colorJs};
  function cbLum(h){h=String(h||'').replace('#','');if(h.length===3){h=h[0]+h[0]+h[1]+h[1]+h[2]+h[2];}if(h.length!==6){return 0.5;}var r=parseInt(h.slice(0,2),16)/255,g=parseInt(h.slice(2,4),16)/255,b=parseInt(h.slice(4,6),16)/255;var lf=function(c){return c<=0.03928?c/12.92:Math.pow((c+0.055)/1.055,2.4);};return 0.2126*lf(r)+0.7152*lf(g)+0.0722*lf(b);}
  function cbOn(h){var L=cbLum(h);return ((L+0.05)/0.05)>=(1.05/(L+0.05))?'#111111':'#ffffff';}
  var FGON=cbOn(COLOR);
  var CBLIGHT=cbLum(COLOR)>0.82;
  var THEME    = {$themeJs};

  function api(method, path, body){
    return fetch(API_BASE + '/api/public/chatbot' + path, {
      method: method,
      headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CHATBOT-TOKEN': TOKEN },
      body: body ? JSON.stringify(body) : undefined,
    }).then(function(r){ return r.json().catch(function(){ return {success:false}; }); });
  }

  var session = null;
  var bubble, panel, feed, input, sendBtn;

  function makeBubble(){
    bubble = document.createElement('div');
    bubble.id = 'lu-cb-bubble';
    bubble.style.cssText = 'position:fixed;bottom:20px;right:20px;width:56px;height:56px;border-radius:50%;background:'+COLOR+';color:'+FGON+';display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 32px rgba(0,0,0,.35);z-index:2147483647;font-size:24px;line-height:1;border:'+(CBLIGHT?'1px solid rgba(0,0,0,.18)':'none')+';transition:transform .15s';
    bubble.innerHTML = '\u{1F4AC}';
    bubble.onmouseenter = function(){ bubble.style.transform = 'scale(1.06)'; };
    bubble.onmouseleave = function(){ bubble.style.transform = 'scale(1)'; };
    bubble.onclick = openPanel;
    document.body.appendChild(bubble);
  }

  function openPanel(){
    if (panel) { panel.style.display = 'flex'; bubble.style.display = 'none'; if (input) input.focus(); return; }
    panel = document.createElement('div');
    panel.id = 'lu-cb-panel';
    var dark = (THEME === 'dark') || (THEME === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    var bg   = dark ? '#15151A' : '#ffffff';
    var fg   = dark ? '#ffffff' : '#111111';
    var muted= dark ? '#9aa0aa' : '#666666';
    var bd   = dark ? '#2a2a33' : '#e5e7eb';
    panel.style.cssText = 'position:fixed;bottom:20px;right:20px;width:360px;max-width:calc(100vw - 32px);height:520px;max-height:calc(100vh - 60px);background:'+bg+';color:'+fg+';border:1px solid '+bd+';border-radius:14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.4);z-index:2147483647;font-family:system-ui,-apple-system,sans-serif';
    panel.innerHTML =
      '<div style="padding:14px 16px;background:'+COLOR+';color:'+FGON+';display:flex;align-items:center;justify-content:space-between">' +
        '<div style="font-size:14px;font-weight:600">Chat with us</div>' +
        '<button id="lu-cb-close" style="background:none;border:none;color:'+FGON+';cursor:pointer;font-size:18px;padding:0;line-height:1">×</button>' +
      '</div>' +
      '<div id="lu-cb-feed" style="flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;font-size:13px;line-height:1.5"></div>' +
      '<div style="padding:10px;border-top:1px solid '+bd+';display:flex;gap:8px">' +
        '<input id="lu-cb-input" placeholder="Type a message…" style="flex:1;background:transparent;border:1px solid '+bd+';border-radius:8px;color:'+fg+';padding:9px 12px;font-size:13px;font-family:inherit;outline:none">' +
        '<button id="lu-cb-send" aria-label="Send" title="Send" style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;flex:0 0 38px;padding:0;background:'+COLOR+';color:'+FGON+';border:none;border-radius:10px;cursor:pointer"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"></path><path d="m22 2-7 20-4-9-9-4Z"></path></svg></button>' +
      '</div>';
    document.body.appendChild(panel);
    bubble.style.display = 'none';
    feed   = panel.querySelector('#lu-cb-feed');
    input  = panel.querySelector('#lu-cb-input');
    sendBtn= panel.querySelector('#lu-cb-send');
    panel.querySelector('#lu-cb-close').onclick = function(){ panel.style.display = 'none'; bubble.style.display = 'flex'; };
    sendBtn.onclick = sendMsg;
    input.onkeydown = function(e){ if (e.key === 'Enter') sendMsg(); };
    addBubble('bot', GREETING);
    startSession();
    setTimeout(function(){ input.focus(); }, 50);
  }

  function startSession(){
    if (session) return;
    api('POST', '/session/start', { page_url: location.href, fingerprint: '' }).then(function(j){
      if (j && j.success && j.data && j.data.session_id) session = j.data.session_id;
    });
  }

  function addBubble(who, text){
    var d = document.createElement('div');
    var isUser = (who === 'user');
    d.style.cssText = 'max-width:80%;padding:8px 12px;border-radius:10px;align-self:'+(isUser?'flex-end':'flex-start')+';background:'+(isUser?COLOR:'rgba(127,127,127,0.12)')+';color:'+(isUser?FGON:'inherit')+';white-space:pre-wrap';
    d.textContent = text;
    feed.appendChild(d);
    feed.scrollTop = feed.scrollHeight;
    return d;
  }

  function sendMsg(){
    var t = (input.value || '').trim();
    if (!t || !session) return;
    input.value = '';
    addBubble('user', t);
    var typing = addBubble('bot', '…');
    api('POST', '/message', { session_id: session, message: t }).then(function(j){
      typing.remove();
      // PATCH (chatbot-widget-reply, 2026-05-09) — PublicChatbotController
      // returns the reply under data.message (per its JSON schema:
      // {success, data:{message, intent, needs_contact, ...}}).
      // Widget was reading data.answer / data.reply, falling through to
      // "Sorry, something went wrong." even when the LLM produced a
      // perfect response. data.message added as the primary read.
      var reply = (j && j.success && j.data && (j.data.message || j.data.answer || j.data.reply)) || (j && j.error) || 'Sorry, something went wrong.';
      addBubble('bot', reply);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', makeBubble);
  } else {
    makeBubble();
  }
})();
JS;

    return response($js, 200)
        ->header('Content-Type', 'application/javascript; charset=utf-8')
        ->header('Cache-Control', 'private, max-age=60'); // short cache; settings can change
})->name('chatbot.bootstrap');


// ── Blog ─────────────────────────────────────────────────────
Route::get("/blog", fn() => response()->file(public_path("marketing/pages/blog.html")));
Route::get("/blog/{slug}", fn() => response()->file(public_path("marketing/pages/blog-post.html")))->where("slug", "[a-z0-9\-]+");


// ── Public booking submissions (POST /book on any published subdomain) ──
Route::post('/book', function (\Illuminate\Http\Request $request) {
    $host = $request->getHost();
    $subdomain = null;
    if (str_ends_with($host, '.levelupgrowth.io')) {
        $subdomain = explode('.', $host)[0] ?? '';
        if (in_array($subdomain, ['staging', 'www', 'app', 'api'], true)) {
            $subdomain = null;
        }
    }
    $website = null;
    if ($subdomain) {
        $website = \Illuminate\Support\Facades\DB::table('websites')
            ->where('subdomain', $subdomain . '.levelupgrowth.io')
            ->orWhere('subdomain', $subdomain)
            ->first();
    }
    if (!$website) {
        $website = \Illuminate\Support\Facades\DB::table('websites')->where('custom_domain', $host)->where('domain_verified', true)->first();
    }
    if (!$website) {
        return response()->json(['ok' => false, 'error' => 'unknown site'], 404);
    }
    $svc = app(\App\Engines\Builder\Services\BookingService::class);
    return response()->json($svc->store((int)$website->id, $request->all()));
});


// ── Public template preview (no auth, cached 1h) ──
Route::get('/templates/{industry}/preview', function (string $industry) {
    // Basic validation
    if (!preg_match('/^[a-z0-9_\-]+$/', $industry)) abort(404);
    $tplPath = storage_path('templates/' . $industry);
    if (!is_dir($tplPath) || !is_file($tplPath . '/manifest.json')) abort(404);

    $raw = (bool) request()->query('raw', false);
    $cacheKey = "tpl_preview:{$industry}:" . ($raw ? 'raw' : 'wrap');

    $html = \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function () use ($industry, $raw, $tplPath) {
        $manifest = json_decode(file_get_contents($tplPath . '/manifest.json'), true) ?: [];
        $vars = [];
        foreach (($manifest['variables'] ?? []) as $k => $v) $vars[$k] = $v['default'] ?? '';
        $svc = app(\App\Engines\Builder\Services\TemplateService::class);
        $rendered = $svc->render($industry, $vars);
        // PREVIEW PARITY (2026-09-11): a customer's export gets the phone menu and the mobile-safety guard at
        // deploy; the preview must show the same page or a phone-width judgement is made on a fiction.
        $rendered = \App\Engines\Builder\Support\ResponsiveNav::inject($rendered);
        if (method_exists($svc, 'injectMobileSafetyPublic')) { $rendered = $svc->injectMobileSafetyPublic($rendered); }

        if ($raw) {
            return $rendered;
        }

        $tplName = $manifest['name'] ?? ucfirst($industry);
        $useUrl  = '/app/?template=' . urlencode($industry);
        $rawUrl  = '/templates/' . $industry . '/preview?raw=1';

        return '<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Preview · ' . htmlspecialchars($tplName) . '</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #fff; overflow: hidden; height: 100vh; }
.lu-bar { position: fixed; top: 0; left: 0; right: 0; height: 52px; background: #0f172a; border-bottom: 1px solid rgba(255,255,255,.08); z-index: 99999; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; font-size: 13px; }
.lu-bar-left { display: flex; align-items: center; gap: 12px; }
.lu-bar-title { font-weight: 600; letter-spacing: 0.01em; }
.lu-bar-badge { font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color: #fff; background: rgba(108,92,231,.85); padding: 4px 8px; border-radius: 3px; font-weight: 600; }
.lu-bar-center { display: flex; gap: 4px; background: rgba(255,255,255,.06); padding: 4px; border-radius: 7px; }
.lu-dev { background: transparent; border: none; color: #c3c7d0; padding: 6px 14px; border-radius: 5px; font-size: 12px; font-weight: 500; cursor: pointer; letter-spacing: 0.02em; }
.lu-dev.active { background: rgba(255,255,255,.12); color: #fff; }
.lu-bar-right { display: flex; align-items: center; gap: 10px; }
.lu-use { background: #6C5CE7; border: none; color: #fff; padding: 9px 16px; border-radius: 6px; font-size: 12px; font-weight: 600; letter-spacing: 0.02em; cursor: pointer; text-decoration: none; transition: background .2s; }
.lu-use:hover { background: #5A4BD3; }
.lu-close { background: transparent; border: 1px solid rgba(255,255,255,.15); color: #c3c7d0; padding: 8px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; text-decoration: none; }
.lu-stage { position: absolute; top: 52px; left: 0; right: 0; bottom: 0; background: #0f172a; display: flex; align-items: flex-start; justify-content: center; padding: 0; overflow: auto; }
.lu-frame { width: 100%; max-width: none; height: 100%; border: none; background: #fff; transition: max-width .35s cubic-bezier(.2,.8,.2,1); display: block; }
.lu-stage[data-dev="mobile"] .lu-frame { max-width: 420px; box-shadow: 0 12px 48px rgba(0,0,0,.5); margin-top: 20px; height: calc(100vh - 92px); border-radius: 16px; }
.lu-stage[data-dev="mobile"] { padding: 0 24px; }
</style></head>
<body>
<div class="lu-bar">
  <div class="lu-bar-left"><span class="lu-bar-title">' . htmlspecialchars($tplName) . '</span><span class="lu-bar-badge">' . htmlspecialchars($industry) . '</span></div>
  <div class="lu-bar-center">
    <button class="lu-dev active" data-dev="desktop" onclick="luDev(\'desktop\')">Desktop</button>
    <button class="lu-dev" data-dev="mobile" onclick="luDev(\'mobile\')">Mobile</button>
  </div>
  <div class="lu-bar-right">
    <a class="lu-close" href="/app/">Close</a>
    <a class="lu-use" href="' . htmlspecialchars($useUrl) . '">Use This Template →</a>
  </div>
</div>
<div class="lu-stage" id="luStage" data-dev="desktop"><iframe class="lu-frame" id="luFrame" src="' . htmlspecialchars($rawUrl) . '"></iframe></div>
<script>
function luDev(mode) {
  document.getElementById("luStage").setAttribute("data-dev", mode);
  document.querySelectorAll(".lu-dev").forEach(function(b){ b.classList.toggle("active", b.dataset.dev === mode); });
}
</script>
</body></html>';
    });

    return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
});


// ── v1.4.4 (2026-05-30) — Public page-template preview ──────────────
// Mirrors /templates/{industry}/preview but for Arthur's code-defined
// page templates (booking, events, listing_browser, …). Renders the
// section stack with sample data via BuilderRenderer and wraps the
// output in the same desktop/mobile chrome bar.
Route::get('/page-templates/{slug}/preview', function (string $slug) {
    if (!preg_match('/^[a-z0-9_\-]+$/', $slug)) abort(404);

    $catalogue = \App\Engines\Builder\Services\ArthurService::PAGE_TEMPLATE_CATALOGUE;
    if (!isset($catalogue[$slug])) abort(404);

    $raw = (bool) request()->query('raw', false);
    $industryOverride = (string) request()->query('industry', '');
    $cacheKey = "page_tpl_preview:{$slug}:" . ($raw ? 'raw' : 'wrap') . ':' . ($industryOverride ?: '_');

    $html = \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function () use ($slug, $raw, $catalogue, $industryOverride) {
        $meta     = $catalogue[$slug];
        $arthur   = app(\App\Engines\Builder\Services\ArthurService::class);
        $renderer = app(\App\Engines\Builder\Services\BuilderRenderer::class);

        // Pick a representative industry from the template's recommended set
        // so the preview reads like a real tenant (e.g. listing_browser →
        // "Browse our properties" rather than "Browse our listings").
        $industry = $industryOverride !== '' ? $industryOverride
                 : (($meta['industries'][0] ?? '*') !== '*' ? $meta['industries'][0] : 'consulting');

        $sampleByIndustry = [
            'real_estate_agency' => ['business_name' => 'Marina Prime Realty',  'core_service' => 'sales and rentals',          'services' => ['Sales', 'Rentals', 'Off-plan', 'Property management'], 'location' => 'Dubai Marina'],
            'short_term_rental'  => ['business_name' => 'Marina Stays',          'core_service' => 'holiday rentals',             'services' => ['Studio', 'One-bedroom', 'Two-bedroom', 'Penthouse'],      'location' => 'Dubai Marina'],
            'ecommerce'          => ['business_name' => 'Aurora Style',          'core_service' => 'curated fashion',             'services' => ['New arrivals', 'Best sellers', 'On sale', 'Accessories'], 'location' => 'Dubai'],
            'retail_shop'        => ['business_name' => 'Aurora Style',          'core_service' => 'curated fashion',             'services' => ['New arrivals', 'Best sellers', 'On sale', 'Accessories'], 'location' => 'Dubai'],
            'hotel'              => ['business_name' => 'The Marina Hotel',      'core_service' => 'boutique hospitality',        'services' => ['Deluxe room', 'Suite', 'Junior suite', 'Penthouse'],      'location' => 'Dubai Marina'],
            'resort'             => ['business_name' => 'Palm Cove Resort',      'core_service' => 'beachfront escapes',          'services' => ['Beach villa', 'Garden villa', 'Pool villa', 'Family suite'], 'location' => 'Ras Al Khaimah'],
            'restaurant'         => ['business_name' => 'Mira',                  'core_service' => 'modern Levantine cuisine',    'services' => ['Mezze', 'Mains', 'Desserts', 'Wine pairings'],            'location' => 'Dubai DIFC'],
            'cafe'               => ['business_name' => 'Mira',                  'core_service' => 'specialty coffee + brunch',   'services' => ['Espresso', 'Filter', 'Brunch', 'Pastries'],               'location' => 'Dubai DIFC'],
            'aesthetic_clinic'   => ['business_name' => 'Bella Aesthetic Clinic','core_service' => 'aesthetic skin treatments',   'services' => ['Botox', 'Fillers', 'Laser', 'Skin booster'],              'location' => 'Dubai Marina'],
            'dental'             => ['business_name' => 'Smile Dental Studio',   'core_service' => 'cosmetic and family dentistry','services' => ['Hygiene', 'Whitening', 'Veneers', 'Implants'],            'location' => 'Dubai JLT'],
            'beauty_salon'       => ['business_name' => 'Lumen Salon',           'core_service' => 'hair colour and styling',     'services' => ['Cut', 'Colour', 'Treatment', 'Bridal'],                  'location' => 'Dubai Marina'],
            'barbershop'         => ['business_name' => 'Bond & Sons',           'core_service' => 'classic barbering',           'services' => ['Cut', 'Shave', 'Beard', 'Hot towel'],                    'location' => 'Dubai JLT'],
            'gym'                => ['business_name' => 'Iron + Sky',            'core_service' => 'strength + conditioning',     'services' => ['Strength', 'Conditioning', 'Mobility', 'PT'],            'location' => 'Dubai Marina'],
            'event_venue'        => ['business_name' => 'The Marina Hall',       'core_service' => 'event hire',                  'services' => ['Weddings', 'Corporate', 'Private', 'Pop-up'],            'location' => 'Dubai Marina'],
            'training_center'    => ['business_name' => 'Skillstation Academy',  'core_service' => 'professional courses',        'services' => ['Leadership', 'Sales', 'Tech', 'Communication'],          'location' => 'Dubai Knowledge Park'],
            'online_courses'     => ['business_name' => 'Skillstation Academy',  'core_service' => 'self-paced online learning',  'services' => ['Foundations', 'Advanced', 'Certification', 'Coaching'],  'location' => 'Online'],
            'tutoring'           => ['business_name' => 'Bright Path Tutors',    'core_service' => 'one-on-one tutoring',         'services' => ['Maths', 'Science', 'English', 'Exam prep'],              'location' => 'Dubai'],
            'automotive'         => ['business_name' => 'Apex Auto',             'core_service' => 'vehicle sales and service',   'services' => ['Sedan', 'SUV', 'Electric', 'Service'],                   'location' => 'Dubai SZR'],
            'architecture'       => ['business_name' => 'Form & Frame Studio',   'core_service' => 'residential architecture',    'services' => ['Concept', 'Schematic', 'Detailed design', 'Site supervision'], 'location' => 'Dubai'],
            'interior_design'    => ['business_name' => 'Form & Frame Studio',   'core_service' => 'interior design',             'services' => ['Concept', 'Procurement', 'Styling', 'Project management'], 'location' => 'Dubai'],
            'marketing_agency'   => ['business_name' => 'Northwind Marketing',   'core_service' => 'growth marketing',            'services' => ['Brand', 'SEO', 'Performance', 'Content'],                'location' => 'Dubai Internet City'],
            'consulting'         => ['business_name' => 'Pinnacle Consulting',   'core_service' => 'strategy consulting',         'services' => ['Strategy', 'Operations', 'Org design', 'Coaching'],      'location' => 'Dubai DIFC'],
            'it_services'        => ['business_name' => 'BlueByte IT',           'core_service' => 'managed IT services',         'services' => ['Cloud', 'Security', 'Helpdesk', 'Networking'],           'location' => 'Dubai Silicon Oasis'],
            'home_services'      => ['business_name' => 'HomePros',              'core_service' => 'home maintenance',            'services' => ['Cleaning', 'Plumbing', 'Electrical', 'HVAC'],            'location' => 'Dubai'],
            'construction'       => ['business_name' => 'Steelcraft Build',      'core_service' => 'residential construction',    'services' => ['New build', 'Renovation', 'Extension', 'Fit-out'],       'location' => 'Dubai'],
            'catering'           => ['business_name' => 'Hosted by Mira',        'core_service' => 'event catering',              'services' => ['Canapés', 'Buffets', 'Plated dinners', 'Drinks'],        'location' => 'Dubai'],
            'medical_clinic'     => ['business_name' => 'Cedar Medical',         'core_service' => 'family medicine',             'services' => ['GP', 'Paediatrics', 'Women\'s health', 'Wellness checks'], 'location' => 'Dubai JLT'],
            'news_channel'       => ['business_name' => 'Daily Beacon',          'core_service' => 'independent news',            'services' => ['News', 'Opinion', 'Features', 'Podcast'],                'location' => 'Online'],
            'pet_services'       => ['business_name' => 'Paws & Co',             'core_service' => 'pet care services',           'services' => ['Grooming', 'Boarding', 'Walking', 'Training'],           'location' => 'Dubai'],
            'travel_agency'      => ['business_name' => 'Lattitude Travel',      'core_service' => 'bespoke travel planning',     'services' => ['Honeymoons', 'Family', 'Adventure', 'Business'],         'location' => 'Dubai DIFC'],
            'childcare'          => ['business_name' => 'Little Acorns',         'core_service' => 'early-years childcare',       'services' => ['Nursery', 'Pre-school', 'After-school', 'Holiday camp'], 'location' => 'Dubai'],
        ];

        $sample = ($sampleByIndustry[$industry] ?? [
            'business_name' => 'Sample Business',
            'core_service'  => 'our core service',
            'services'      => ['Service one', 'Service two', 'Service three', 'Service four'],
            'location'      => 'Dubai',
        ]) + ['industry' => $industry];

        $sections = $arthur->buildDefaultSectionsForPage($slug, $sample);
        $brand = [
            'primary'       => '#6C5CE7',
            'primary_color' => '#6C5CE7',
            'secondary'     => '#3D34A0',
            'accent'        => '#F4F7FB',
            'font_heading'  => 'Syne',
            'font_body'     => 'DM Sans',
        ];

        $allPages = [
            ['slug' => 'home',     'title' => 'Home'],
            ['slug' => 'about',    'title' => 'About'],
            ['slug' => 'services', 'title' => 'Services'],
            ['slug' => 'contact',  'title' => 'Contact'],
        ];

        $body = '';
        foreach ($sections as $sec) {
            $body .= $renderer->renderSection($sec, $brand, ['name' => $sample['business_name']], $allPages, $slug);
        }

        $rendered = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($meta['label']) . ' — preview</title>'
            . '<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">'
            . '<style>html,body{margin:0;padding:0;font-family:\'DM Sans\',system-ui,-apple-system,sans-serif;background:#fff;color:#111}h1,h2,h3{font-family:\'Syne\',sans-serif}</style>'
            . '</head><body>' . $body . '</body></html>';

        if ($raw) return $rendered;

        $tplName = $meta['label'] ?? ucfirst($slug);
        $rawUrl  = '/page-templates/' . $slug . '/preview?raw=1' . ($industryOverride ? '&industry=' . urlencode($industryOverride) : '');
        $useUrl  = '/app/?page_template=' . urlencode($slug);

        return '<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Preview · ' . htmlspecialchars($tplName) . '</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #fff; overflow: hidden; height: 100vh; }
.lu-bar { position: fixed; top: 0; left: 0; right: 0; height: 52px; background: #0f172a; border-bottom: 1px solid rgba(255,255,255,.08); z-index: 99999; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; font-size: 13px; }
.lu-bar-left { display: flex; align-items: center; gap: 12px; }
.lu-bar-title { font-weight: 600; letter-spacing: 0.01em; }
.lu-bar-badge { font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color: #fff; background: rgba(108,92,231,.85); padding: 4px 8px; border-radius: 3px; font-weight: 600; }
.lu-bar-center { display: flex; gap: 4px; background: rgba(255,255,255,.06); padding: 4px; border-radius: 7px; }
.lu-dev { background: transparent; border: none; color: #c3c7d0; padding: 6px 14px; border-radius: 5px; font-size: 12px; font-weight: 500; cursor: pointer; letter-spacing: 0.02em; }
.lu-dev.active { background: rgba(255,255,255,.12); color: #fff; }
.lu-bar-right { display: flex; align-items: center; gap: 10px; }
.lu-use { background: #6C5CE7; border: none; color: #fff; padding: 9px 16px; border-radius: 6px; font-size: 12px; font-weight: 600; letter-spacing: 0.02em; cursor: pointer; text-decoration: none; transition: background .2s; }
.lu-use:hover { background: #5A4BD3; }
.lu-close { background: transparent; border: 1px solid rgba(255,255,255,.15); color: #c3c7d0; padding: 8px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; text-decoration: none; }
.lu-stage { position: absolute; top: 52px; left: 0; right: 0; bottom: 0; background: #0f172a; display: flex; align-items: flex-start; justify-content: center; padding: 0; overflow: auto; }
.lu-frame { width: 100%; max-width: none; height: 100%; border: none; background: #fff; transition: max-width .35s cubic-bezier(.2,.8,.2,1); display: block; }
.lu-stage[data-dev="mobile"] .lu-frame { max-width: 420px; box-shadow: 0 12px 48px rgba(0,0,0,.5); margin-top: 20px; height: calc(100vh - 92px); border-radius: 16px; }
.lu-stage[data-dev="mobile"] { padding: 0 24px; }
</style></head>
<body>
<div class="lu-bar">
  <div class="lu-bar-left"><span class="lu-bar-title">' . htmlspecialchars($tplName) . '</span><span class="lu-bar-badge">page template</span></div>
  <div class="lu-bar-center">
    <button class="lu-dev active" data-dev="desktop" onclick="luDev(\'desktop\')">Desktop</button>
    <button class="lu-dev" data-dev="mobile" onclick="luDev(\'mobile\')">Mobile</button>
  </div>
  <div class="lu-bar-right">
    <a class="lu-close" href="/admin/">Close</a>
    <a class="lu-use" href="' . htmlspecialchars($useUrl) . '">Use This Template →</a>
  </div>
</div>
<div class="lu-stage" id="luStage" data-dev="desktop"><iframe class="lu-frame" id="luFrame" src="' . htmlspecialchars($rawUrl) . '"></iframe></div>
<script>
function luDev(mode) {
  document.getElementById("luStage").setAttribute("data-dev", mode);
  document.querySelectorAll(".lu-dev").forEach(function(b){ b.classList.toggle("active", b.dataset.dev === mode); });
}
</script>
</body></html>';
    });

    return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
});


// 2026-05-16 v1.1 — WP plugin OAuth-style connect flow.
// No web-session auth: the SPA stores its JWT in localStorage; the
// /plugin-connect page reads it client-side and POSTs to /api/plugin/connect.
// This route is public — auth is enforced at /api/plugin/connect (auth.jwt).
Route::get('/plugin-connect', function (\Illuminate\Http\Request $r) {
    return view('plugin-connect', [
        'redirect_uri' => (string) $r->query('redirect_uri', ''),
        'site_url'     => (string) $r->query('site_url', ''),
    ]);
})->name('plugin.connect');

// CUTOVER (2026-09-10) — the rebuilt marketing site is served at the root from
// routes/marketing-next.php. Included LAST on purpose: Laravel's RouteCollection keys on
// method+uri and OVERWRITES, so for a URI defined in both places the LAST registration wins.
// Included first, every legacy page below silently re-claimed / and /pricing. Deleting these
// five lines is the complete rollback: the legacy routes above are untouched.
if (file_exists(__DIR__ . '/marketing-next.php')) { require __DIR__ . '/marketing-next.php'; }
