/*
 * INC-0006 — carry the selected website into the direct tools that act on one.
 *
 * The alternative was editing the fetch calls inside nine engine bundles, which would have put the same
 * decision in ~200 places and guaranteed drift the first time someone added a tenth. This does it once,
 * against an explicit allowlist, so what is scoped and what is not can be read in one screen and argued
 * with — rather than being an emergent property of whoever last touched seo.js.
 *
 * The allowlist is the point. A tool is listed here only when its operation genuinely CHANGES per website:
 * a chatbot's greeting, a site's WordPress endpoint, a scan's progress. Everything else is business-wide and
 * must stay that way.
 *
 * Deliberately NOT scoped, and this is not an oversight:
 *
 *   /api/agents/*, /api/sarah/*   Sarah is executive intelligence for the whole business. She reasons across
 *                                 the portfolio and resolves her own execution target from what the owner
 *                                 actually said — an explicit site, a continuation, a switch, or a question
 *                                 when it is ambiguous. Narrowing her to whatever the sidebar happens to
 *                                 show would replace her judgement with a dropdown.
 *   /api/credits, /api/subscription, /api/workspace/*
 *                                 One business, one wallet, one plan. There is nothing per-site here.
 *   /api/website-context, /api/websites, /api/builder/websites
 *                                 These enumerate the websites. Filtering them by the current website would
 *                                 be circular.
 *
 * Nothing is appended when no website is selected. The server then applies its own rule — the only site for
 * a one-site business, or WEBSITE_REQUIRED when there are several — and the client never invents an answer
 * the server would have refused.
 */
(function () {
  'use strict';

  if (!window.fetch || window.__luWebsiteBridge) { return; }
  window.__luWebsiteBridge = true;

  /* Paths whose behaviour genuinely differs per website. Matched as a prefix against the pathname. */
  var SCOPED = [
    '/api/chatbot/settings',
    '/api/chatbot/status',
    '/api/chatbot/conversations',
    '/api/chatbot/escalations',
    '/api/chatbot/knowledge',
    '/api/chatbot/crawl',
    '/api/admin/chatbot/',
    '/api/seo/settings',
    '/api/seo/scan',
    '/api/seo/scan-status',
    '/api/seo/index-pages',
    '/api/seo/indexed-content',
    '/api/seo/image-issues',
    '/api/seo/chatbot/status',
    '/api/seo/gsc/',
    '/api/seo/ga/',
    '/api/settings/connector/connections'
  ];

  /* Explicitly business-wide. Listed so a future prefix match can never quietly capture them. */
  var NEVER = [
    '/api/agents',
    '/api/sarah',
    '/api/credits',
    '/api/subscription',
    '/api/billing',
    '/api/workspace',
    '/api/workspaces',
    '/api/website-context',
    '/api/websites',
    '/api/builder/websites'
  ];

  function pathOf(input) {
    try {
      var raw = (typeof input === 'string') ? input : (input && input.url) || '';
      if (!raw) { return ''; }
      return new URL(raw, window.location.origin).pathname;
    } catch (e) { return ''; }
  }

  function isScoped(path) {
    if (!path) { return false; }
    for (var i = 0; i < NEVER.length; i++) {
      if (path === NEVER[i] || path.indexOf(NEVER[i] + '/') === 0) { return false; }
    }
    for (var j = 0; j < SCOPED.length; j++) {
      if (path.indexOf(SCOPED[j]) === 0) { return true; }
    }
    return false;
  }

  var nativeFetch = window.fetch.bind(window);

  window.fetch = function (input, init) {
    try {
      var W = window.LU_Website;
      var id = W && W.current();
      if (!id) { return nativeFetch(input, init); }

      var path = pathOf(input);
      if (!isScoped(path)) { return nativeFetch(input, init); }

      var raw = (typeof input === 'string') ? input : input.url;
      var url = new URL(raw, window.location.origin);

      // An explicit website_id from the caller always wins — a tool that knows better than the sidebar
      // (opening a specific site's editor, say) must not have its choice overwritten.
      if (!url.searchParams.has('website_id')) {
        url.searchParams.set('website_id', String(id));
      }

      var next = (typeof input === 'string')
        ? url.pathname + url.search + url.hash
        : new Request(url.toString(), input);

      return nativeFetch(next, init);
    } catch (e) {
      return nativeFetch(input, init);
    }
  };

  /*
   * Changing website re-runs whatever the user is looking at. Engines that already listen to the context
   * event handle it themselves; this covers the rest by re-entering the current view, which is how the app
   * refreshes everywhere else. It is deliberately a re-render, never a workspace change.
   */
  /*
   * The SEO engine shipped its own site picker long before there was a shared context, and it keys its
   * choice on a URL in localStorage rather than a website id. Left alone, the two controls disagree in
   * front of the user: the shell says one website, the engine's own dropdown says another, and only the
   * data underneath reveals which one actually won.
   *
   * Rather than reach into 600KB of engine code, the two are kept in step at their edges: the engine's
   * select follows the shared context, and a change made in the engine is reflected back. Matching is by
   * HOST, because that is the only identity the engine's options carry.
   */
  function seoSiteSelect() {
    var view = document.getElementById('view-seo');
    if (!view) { return null; }
    var selects = view.querySelectorAll('select');
    for (var i = 0; i < selects.length; i++) {
      var s = selects[i];
      // The site picker is the one whose options are URLs.
      if (s.options.length && /^https?:\/\//.test(s.options[0].value || '')) { return s; }
    }
    return null;
  }

  function hostOf(value) {
    try {
      var h = new URL(String(value), window.location.origin).hostname;
      return h.toLowerCase().replace(/^www\./, '');
    } catch (e) { return ''; }
  }

  /* Point the engine's own picker at whatever the shell context currently says. */
  function pushContextIntoSeo() {
    var sel = seoSiteSelect();
    var W = window.LU_Website;
    if (!sel || !W) { return; }

    var site = W.currentSite();
    if (!site || !site.host) { return; }

    var want = String(site.host).toLowerCase().replace(/^www\./, '');
    for (var i = 0; i < sel.options.length; i++) {
      if (hostOf(sel.options[i].value) === want) {
        if (sel.value !== sel.options[i].value) {
          sel.value = sel.options[i].value;
          // Fire the real event so the engine reloads exactly as it would for a human choosing it.
          try { sel.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
        }
        return;
      }
    }
  }

  /* And when someone uses the engine's picker, the shell must not be left showing the old site. */
  function pullSeoIntoContext(e) {
    var sel = e.target;
    var W = window.LU_Website;
    if (!W || !sel || sel.tagName !== 'SELECT') { return; }
    if (!/^https?:\/\//.test(String(sel.value || ''))) { return; }
    if (!document.getElementById('view-seo') || !document.getElementById('view-seo').contains(sel)) { return; }

    var host = hostOf(sel.value);
    var hit = W.list().filter(function (w) {
      return String(w.host || '').toLowerCase().replace(/^www\./, '') === host;
    });
    if (hit.length && hit[0].id !== W.current()) { W.set(hit[0].id); }
  }

  document.addEventListener('change', pullSeoIntoContext, true);

  /*
   * The engine builds its picker lazily, only once the SEO view is opened, and it restores its own last
   * choice from localStorage while doing so. Reconciling only on context CHANGE therefore left the two
   * controls disagreeing on arrival — the shell naming one website, the engine's dropdown another, which
   * is precisely the ambiguity this work exists to remove. So watch for the picker appearing (or being
   * rebuilt) and bring it into line the moment it does.
   */
  function watchForSeoPicker() {
    var view = document.getElementById('view-seo');
    if (!view || !window.MutationObserver) { return; }

    var settle = null;
    var obs = new MutationObserver(function () {
      clearTimeout(settle);
      // Debounced: the engine mutates this subtree heavily while rendering.
      settle = setTimeout(function () { try { pushContextIntoSeo(); } catch (e) {} }, 250);
    });
    obs.observe(view, { childList: true, subtree: true });
  }

  if (window.LU_Website) {
    window.LU_Website.ready().then(function () {
      try { pushContextIntoSeo(); } catch (e) {}
      watchForSeoPicker();
      // The view may still be rendering on a cold load; a couple of bounded retries cover it without
      // turning into a polling loop.
      [700, 1800, 3500].forEach(function (ms) {
        setTimeout(function () { try { pushContextIntoSeo(); } catch (e) {} }, ms);
      });
    });
  }

  if (window.LU_Website) {
    window.LU_Website.onChange(function () {
      try {
        if (typeof window.nav === 'function' && window.currentView) {
          window.nav(window.currentView, { silent: true });
        }
      } catch (e) { /* a view that cannot re-enter simply keeps what it has until the user moves */ }

      // The engine renders its picker asynchronously, so try immediately and once more after it settles.
      try { pushContextIntoSeo(); } catch (e) {}
      setTimeout(function () { try { pushContextIntoSeo(); } catch (e) {} }, 900);
    });
  }
})();
