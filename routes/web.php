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

// ── Admin Panel ──────────────────────────────────────────────────────────────
Route::prefix('admin')->group(function () {
    Route::get('/login', function () {
        return view('admin.login');
    })->name('admin.login');

    Route::get('/{any?}', function () {
        return view('admin.app');
    })->where('any', '.*')->name('admin.app');
});

// ── SaaS App (React SPA) ──────────────────────────────────────────────────────
Route::get('/app/{any?}', function () {
    return response()->file(public_path('app/index.html'));
})->where('any', '.*')->name('app');

// ── Marketing Site (static HTML from plugin) ──────────────────────────────────
// Homepage
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
    return response()->file(public_path('marketing/pages/specialists.html'));
})->name('specialists');

Route::get('/faq', function () {
    return response()->file(public_path('marketing/pages/faq.html'));
})->name('faq');

Route::get('/sign-up', function () {
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
    return response()->file(public_path('marketing/pages/ai-assistant.html'));
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
    return response()->file(public_path('marketing/pages/email.html'));
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
    return response()->file(public_path('marketing/pages/assistant.html'));
})->name('assistant');

// Pages catch-all (handles /pages/pricing/, /pages/how-it-works/ etc. from nav links)
Route::get('/pages/{slug}', function (string $slug) {
    $file = public_path('marketing/pages/' . basename($slug) . '.html');
    if (file_exists($file)) {
        return response()->file($file);
    }
    abort(404);
})->where('slug', '[a-z0-9-]+')->name('marketing.page');

// Invite acceptance page (public)
Route::get('/invite/{token}', function (string $token) {
    return view('invite', ['token' => $token]);
})->name('invite');

// Published sites now served via PublishedSiteMiddleware (not domain routes)


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
