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


// ── Chatbot888 public widget bootstrap (2026-05-09) ──────────────────────
// GET /chatbot.js?ws=N
// Returns vanilla-JS bootstrap that mounts a chat bubble in the bottom-right
// corner of the host page. Workspace gating + chatbot.enabled check + plan
// check (Pro+/Agency via FeatureGateService::canAccessChatbot) all happen
// here — if any check fails, we still return valid (empty) JS so the host
// page never sees a JS error in console.
Route::get('/chatbot.js', function (\Illuminate\Http\Request $r) {
    $wsId = (int) $r->query('ws', 0);
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

    $settings = \Illuminate\Support\Facades\DB::table('chatbot_settings')->where('workspace_id', $wsId)->first();
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
    if ($embedHostForLookup !== '' && ! in_array($embedHostForLookup, ['levelupgrowth.io', 'www.levelupgrowth.io', 'staging.levelupgrowth.io'], true)) {
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
    bubble.style.cssText = 'position:fixed;bottom:20px;right:20px;width:56px;height:56px;border-radius:50%;background:'+COLOR+';color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 32px rgba(0,0,0,.35);z-index:2147483647;font-size:24px;line-height:1;border:none;transition:transform .15s';
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
      '<div style="padding:14px 16px;background:'+COLOR+';color:#fff;display:flex;align-items:center;justify-content:space-between">' +
        '<div style="font-size:14px;font-weight:600">Chat with us</div>' +
        '<button id="lu-cb-close" style="background:none;border:none;color:#fff;cursor:pointer;font-size:18px;padding:0;line-height:1">×</button>' +
      '</div>' +
      '<div id="lu-cb-feed" style="flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;font-size:13px;line-height:1.5"></div>' +
      '<div style="padding:10px;border-top:1px solid '+bd+';display:flex;gap:8px">' +
        '<input id="lu-cb-input" placeholder="Type a message…" style="flex:1;background:transparent;border:1px solid '+bd+';border-radius:8px;color:'+fg+';padding:9px 12px;font-size:13px;font-family:inherit;outline:none">' +
        '<button id="lu-cb-send" style="background:'+COLOR+';color:#fff;border:none;border-radius:8px;padding:9px 14px;font-size:12px;font-weight:600;cursor:pointer">Send</button>' +
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
    d.style.cssText = 'max-width:80%;padding:8px 12px;border-radius:10px;align-self:'+(isUser?'flex-end':'flex-start')+';background:'+(isUser?COLOR:'rgba(127,127,127,0.12)')+';color:'+(isUser?'#fff':'inherit')+';white-space:pre-wrap';
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


// 2026-05-16 v1.1 — WP plugin OAuth-style connect flow.
Route::middleware('auth')->get('/plugin-connect', function (\Illuminate\Http\Request $r) {
    $redirect = (string) $r->query('redirect_uri', '');
    $user = auth()->user();
    $wsId = (int) \Illuminate\Support\Facades\DB::table('workspace_users')
        ->where('user_id', $user->id)
        ->orderBy('created_at')
        ->value('workspace_id');
    $ws = $wsId ? \Illuminate\Support\Facades\DB::table('workspaces')->find($wsId) : null;
    return view('plugin-connect', [
        'redirect_uri'   => $redirect,
        'user'           => $user,
        'workspace_name' => $ws->name ?? '(no workspace)',
        'workspace_id'   => $wsId,
    ]);
})->name('plugin.connect');

Route::middleware('auth')->post('/plugin-connect/authorize', function (\Illuminate\Http\Request $r) {
    $redirect = (string) $r->input('redirect_uri', '');
    $siteUrl  = (string) $r->input('site_url', '');
    $user     = auth()->user();
    $wsId     = (int) \Illuminate\Support\Facades\DB::table('workspace_users')
        ->where('user_id', $user->id)
        ->orderBy('created_at')
        ->value('workspace_id');
    if (!$wsId) {
        return back()->withErrors(['No workspace found for this user.']);
    }

    // Revoke + mint inline (same logic as POST /api/plugin/connect).
    \Illuminate\Support\Facades\DB::table('api_keys')
        ->where('workspace_id', $wsId)
        ->where('user_id', $user->id)
        ->where('type', 'plugin_user')
        ->delete();
    $rawKey = 'lgsc_' . bin2hex(random_bytes(32));
    \Illuminate\Support\Facades\DB::table('api_keys')->insert([
        'workspace_id' => $wsId,
        'user_id'      => $user->id,
        'key'          => $rawKey,
        'name'         => 'WP Plugin — ' . ($siteUrl ?: 'unknown'),
        'type'         => 'plugin_user',
        'scopes'       => json_encode(['plugin']),
        'expires_at'   => now()->addYear(),
        'is_active'    => 1,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    if ($redirect && str_contains($redirect, 'lgsc_connected=1')) {
        return redirect($redirect . '&lgsc_token=' . urlencode($rawKey));
    }
    return view('plugin-connect-success', ['token' => $rawKey]);
})->name('plugin.connect.authorize');
