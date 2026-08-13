{{--
    Admin shell — the layout every admin page is rendered into.

    Replaces the single 359KB app.blade.php that served all 54 menu items from
    one URL. Each menu item is now its own route, resolved from
    config/admin_pages.php by AdminPageController.

    Shared CSS and JS are static files so the browser caches them once instead
    of re-parsing them on every navigation. They are versioned by mtime, which
    matters here: Cloudflare holds assets for 4h and would otherwise serve stale
    JS after a deploy.

    Page data still comes from the /api/admin/* JSON API — unchanged.

    @param array  $pages   the registry
    @param string $key     current page key
    @param array  $page    current registry entry
    @param string $slugMap key => slug, for client-side nav()
    @param int    $v       asset cache-busting version
--}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" onload="this.rel='stylesheet'">
  <noscript><link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"></noscript>
  <title>{{ $page['title'] }} — LevelUp Admin</title>
  <link rel="stylesheet" href="/css/admin.css?v={{ $v }}">
  <style>
    /* Engineer888 is the specialist on its own pages. Bella stays available and
       unchanged - same button, same panel, same permissions - but stops
       competing for attention while Boss is talking to Engineering. Smallest
       mechanism the shell already offers: one body class, one opacity rule. */
    body.e888-workspace .bella-toggle{opacity:.28;transform:scale(.82);
      transition:opacity .2s,transform .2s}
    body.e888-workspace .bella-toggle:hover{opacity:1;transform:none}
  </style>
</head>
<body>

  @include('admin.partials.sidebar', ['pages' => $pages, 'currentKey' => $key])

  <div class="main">
    <div class="topbar">
      <div class="page-title" id="page-title">{{ $page['title'] }}</div>
      <div class="topbar-right">
        <span id="bella-topbar-placeholder" style="width:0;height:0;display:none"></span>
        <span id="topbar-status" style="font-size:12px;color:var(--muted)">Loading...</span>
      </div>
    </div>
    <div class="content" id="content">
      <div class="loading">Loading...</div>
    </div>
  </div>

  @include('admin.partials.bella')

  <script>
    // Server-provided routing table. nav('key') resolves through this, so all
    // 54 existing onclick="nav('x')" call sites keep working unchanged.
    window.ADMIN_PAGES    = {!! json_encode($slugMap, JSON_UNESCAPED_SLASHES) !!};
    window.ADMIN_PAGE_KEY = {!! json_encode($key) !!};
  </script>
  {{-- Order matters: core installs window.pages, shared populates it with the
       helpers and global handlers, then the page view sets window.page. --}}
  <script src="/js/admin-core.js?v={{ $v }}"></script>
  <script src="/js/admin-shared.js?v={{ $v }}"></script>
  <script src="/js/admin-bella.js?v={{ $v }}"></script>

  {{-- This page's renderer, and only this page's — resources/views/admin/pages/**.
       The whole 274KB bundle used to ship on every navigation. --}}
  @include($viewPath)
  <script>
    // Boot this page.
    //
    // The old model called pages[page]?.() — optional chaining, so a menu item
    // with no renderer behind it did nothing at all: no error, no console
    // warning, the previous page just stayed on screen. That is precisely how a
    // dead House Account menu item survived in production. Here a missing
    // renderer is stated plainly, on the page and in the console.
    (function () {
      function renderFailure(e) {
        console.error('page renderer failed', e);
        setContent('<div class="card" style="padding:32px;text-align:center">' +
          '<div style="font-size:15px;font-weight:600;margin-bottom:6px">This page failed to render</div>' +
          '<div style="color:var(--muted);font-size:13px">' + (e && e.message ? e.message : e) + '</div></div>');
      }

      if (typeof window.page === 'function') {
        try {
          // Every renderer is `async`, so it returns a promise and a rejection
          // sails straight past a synchronous catch — it would surface as an
          // unhandled rejection and leave the page stuck on "Loading...".
          // Both paths have to be handled.
          var out = window.page();
          if (out && typeof out.catch === 'function') { out.catch(renderFailure); }
        } catch (e) {
          renderFailure(e);
        }
        return;
      }
      console.error('No renderer for page key "' + window.ADMIN_PAGE_KEY + '".');
      setContent('<div class="card" style="padding:32px;text-align:center">' +
        '<div style="font-size:15px;font-weight:600;margin-bottom:6px">This page has no renderer yet</div>' +
        '<div style="color:var(--muted);font-size:13px">Page key <code>' + window.ADMIN_PAGE_KEY +
        '</code> is registered in config/admin_pages.php but no renderer is loaded for it.</div></div>');
    })();
  </script>

  <script>
    // Path-based, so it covers every /admin/engineer888/* page rather than only
    // the one view that happened to carry the rule.
    if (location.pathname.indexOf('/admin/engineer888') === 0) {
      document.body.classList.add('e888-workspace');
    }
  </script>
</body>
</html>
