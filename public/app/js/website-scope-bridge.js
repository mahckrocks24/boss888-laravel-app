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
  if (window.LU_Website) {
    window.LU_Website.onChange(function () {
      try {
        if (typeof window.nav === 'function' && window.currentView) {
          window.nav(window.currentView, { silent: true });
        }
      } catch (e) { /* a view that cannot re-enter simply keeps what it has until the user moves */ }
    });
  }
})();
