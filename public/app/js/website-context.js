/*
 * INC-0006 — the website a business is currently operating on.
 *
 * A workspace is a business; a website is one of the things that business owns. Direct tools — SEO, Search
 * Console, the chatbot, WordPress, the builder, publishing — each act on exactly ONE website, and until now
 * the app had no shared idea of which, so every one of them either guessed or silently used the first.
 *
 * Design rules, all of them consequences of the incident:
 *
 *   - The context NEVER changes workspace. Selecting a website is not switching tenant, and nothing here
 *     touches the session, the token, or the business.
 *   - It NEVER silently falls back. An unknown or foreign id is cleared and reported, not quietly replaced
 *     with a sibling — substituting one of a business's websites for another is the whole defect.
 *   - A one-site business is never asked to choose. There is only one answer, so it is not a question.
 *   - Sarah is deliberately NOT a consumer. She reasons across the whole portfolio and resolves her own
 *     target from what the owner actually said; binding her to this selector would narrow her to one site.
 *
 * Persistence is layered so a deep link beats a memory and a memory beats nothing:
 *   1. ?w= in the URL          (authoritative — shareable, survives reload and back/forward)
 *   2. localStorage per workspace (a returning user lands where they left off)
 *   3. the only website        (single-site businesses)
 *   4. nothing                 (multi-site, nothing chosen — tools ask rather than guess)
 */
(function () {
  'use strict';

  var QUERY_KEY = 'w';
  var STORE_PREFIX = 'lu_website_ctx_';
  var EVENT = 'lu:website-changed';

  var state = {
    workspaceId: null,
    websites: null,      // null = not loaded yet; [] = loaded and empty
    currentId: null,
    loading: null,       // in-flight promise, so concurrent callers share one request
    lastError: null
  };

  /* ─────────────────────────────────────────────────────────── storage */

  function storeKey() {
    return STORE_PREFIX + (state.workspaceId || 'unknown');
  }

  function remember(id) {
    try {
      id ? localStorage.setItem(storeKey(), String(id)) : localStorage.removeItem(storeKey());
    } catch (e) { /* private mode, quota, or storage disabled — the URL still carries it */ }
  }

  function recalled() {
    try {
      var v = parseInt(localStorage.getItem(storeKey()) || '', 10);
      return isNaN(v) ? null : v;
    } catch (e) { return null; }
  }

  function fromUrl() {
    try {
      var v = parseInt(new URLSearchParams(window.location.search).get(QUERY_KEY) || '', 10);
      return isNaN(v) ? null : v;
    } catch (e) { return null; }
  }

  /*
   * Keep ?w= in the address bar in step with the context, so a reload or a shared link lands on the same
   * website. push=true creates a history entry, which is what makes browser Back walk between websites;
   * everything else replaces, so restoring state on load does not litter the stack.
   */
  function writeUrl(id, push) {
    try {
      var url = new URL(window.location.href);
      if (id) { url.searchParams.set(QUERY_KEY, String(id)); }
      else { url.searchParams.delete(QUERY_KEY); }
      if (url.href === window.location.href) { return; }
      history[push ? 'pushState' : 'replaceState'](
        Object.assign({}, history.state || {}, { websiteId: id || null }),
        '',
        url.pathname + url.search + url.hash
      );
    } catch (e) { /* a restricted context still works, it just cannot deep-link */ }
  }

  /* ─────────────────────────────────────────────────────────── loading */

  function currentWorkspaceId() {
    try {
      if (window.LU_CFG && window.LU_CFG.workspace_id) { return parseInt(window.LU_CFG.workspace_id, 10); }
      var v = parseInt(localStorage.getItem('lu_workspace_id') || '', 10);
      return isNaN(v) ? null : v;
    } catch (e) { return null; }
  }

  function load(force) {
    if (state.loading && !force) { return state.loading; }
    if (state.websites && !force) { return Promise.resolve(state.websites); }

    state.loading = fetch(window.location.origin + '/api/website-context', {
      headers: (typeof window.authHeader === 'function') ? window.authHeader() : {},
      credentials: 'include'
    })
      .then(function (r) { return r.ok ? r.json() : { success: false }; })
      .then(function (j) {
        state.websites = (j && j.success && Array.isArray(j.websites)) ? j.websites : [];
        if (j && j.workspace_id) { state.workspaceId = parseInt(j.workspace_id, 10); }
        resolveInitial();
        return state.websites;
      })
      .catch(function () { state.websites = []; return state.websites; })
      .finally(function () { state.loading = null; });

    return state.loading;
  }

  /* Decide the starting website once the list is known. Order is deliberate; see the header. */
  function resolveInitial() {
    var ids = state.websites.map(function (w) { return w.id; });

    var wanted = fromUrl();
    if (wanted && ids.indexOf(wanted) !== -1) { state.currentId = wanted; remember(wanted); return; }
    if (wanted) {
      // A deep link naming a website this business does not own. Refuse it visibly; do not substitute.
      state.lastError = 'WEBSITE_NOT_IN_WORKSPACE';
      state.currentId = null;
      writeUrl(null, false);
      broadcast();
      return;
    }

    var saved = recalled();
    if (saved && ids.indexOf(saved) !== -1) { state.currentId = saved; writeUrl(saved, false); return; }
    if (saved) { remember(null); }   // the site was deleted, or the workspace changed under us

    // One website is not a choice, so make it without asking and without putting it in the URL.
    state.currentId = (ids.length === 1) ? ids[0] : null;
  }

  /* ─────────────────────────────────────────────────────────── events */

  function broadcast() {
    try {
      window.dispatchEvent(new CustomEvent(EVENT, {
        detail: { websiteId: state.currentId, website: api.currentSite(), error: state.lastError }
      }));
    } catch (e) { /* older engines simply do not get the notification */ }
  }

  /* ─────────────────────────────────────────────────────────── api */

  var api = {
    EVENT: EVENT,

    /** Resolves once the website list is known. Every consumer should await this before reading. */
    ready: function () { return load(false); },

    /** Reload from the server — after creating or deleting a website, or switching workspace. */
    refresh: function () {
      state.currentId = null;
      state.lastError = null;
      return load(true).then(function (list) { broadcast(); return list; });
    },

    list: function () { return state.websites || []; },
    count: function () { return (state.websites || []).length; },
    isMultiSite: function () { return (state.websites || []).length > 1; },
    current: function () { return state.currentId; },
    lastError: function () { return state.lastError; },

    currentSite: function () {
      var id = state.currentId;
      if (!id) { return null; }
      var hit = (state.websites || []).filter(function (w) { return w.id === id; });
      return hit.length ? hit[0] : null;
    },

    /**
     * Choose the website being operated on. Returns true when it took effect.
     *
     * Refuses an id this workspace does not own rather than falling back — the caller gets false and the
     * context is left as it was, so a bad deep link or a stale tab cannot quietly redirect work onto a
     * sibling site.
     */
    set: function (id, opts) {
      opts = opts || {};
      id = id ? parseInt(id, 10) : null;

      if (id && (state.websites || []).map(function (w) { return w.id; }).indexOf(id) === -1) {
        state.lastError = 'WEBSITE_NOT_IN_WORKSPACE';
        broadcast();
        return false;
      }

      if (id === state.currentId) { return true; }

      state.currentId = id;
      state.lastError = null;
      remember(id);
      writeUrl(id, opts.push !== false);
      broadcast();
      return true;
    },

    /** Subscribe to context changes. Returns an unsubscribe function. */
    onChange: function (fn) {
      var h = function (e) { fn(e.detail || {}); };
      window.addEventListener(EVENT, h);
      return function () { window.removeEventListener(EVENT, h); };
    },

    /**
     * Append the current website to a URL a direct tool is about to call.
     *
     * Adds nothing when no website is selected, which is correct: the server then applies its own rule —
     * the only site for a one-site business, or WEBSITE_REQUIRED when the business has several. The client
     * never invents an answer the server would have refused.
     */
    withWebsite: function (url, explicitId) {
      var id = (explicitId !== undefined && explicitId !== null) ? explicitId : state.currentId;
      if (!id) { return url; }
      return url + (url.indexOf('?') === -1 ? '?' : '&') + 'website_id=' + encodeURIComponent(id);
    },

    /** The same value as a body field, for POSTs. Returns {} when nothing is selected. */
    body: function (extra) {
      var out = extra ? Object.assign({}, extra) : {};
      if (state.currentId) { out.website_id = state.currentId; }
      return out;
    }
  };

  // Back/forward moves between websites, because ?w= is part of the address.
  window.addEventListener('popstate', function () {
    if (!state.websites) { return; }
    var wanted = fromUrl();
    var ids = state.websites.map(function (w) { return w.id; });

    if (wanted && ids.indexOf(wanted) !== -1) {
      if (wanted !== state.currentId) { state.currentId = wanted; remember(wanted); broadcast(); }
    } else if (!wanted && state.currentId && ids.length > 1) {
      state.currentId = null;
      remember(null);
      broadcast();
    }
  });

  state.workspaceId = currentWorkspaceId();
  window.LU_Website = api;
})();
