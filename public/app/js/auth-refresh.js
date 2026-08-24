/**
 * auth-refresh.js — 401 -> refresh -> retry-once interceptor.  v1.0.0
 * ---------------------------------------------------------------------------
 * 2026-08-02. The SPA calls /api/auth/refresh in exactly ONE place: the
 * bootstrap in core.js. Nothing renews the access token while a tab is open,
 * so when the 12h JWT expires mid-session every subsequent call just 401s and
 * the UI silently stops updating — observed live as /api/workspace/state
 * returning 401 every 5s for ~40s until someone reloaded the page. get() in
 * core.js calls r.json() regardless of status, so the 401 never even surfaces
 * as an error.
 *
 * This file wraps window.fetch so an expired access token is renewed once and
 * the request retried, in place. It is a SEPARATE FILE on purpose: core.js
 * currently carries uncommitted PLATFORM888 Phase-6b work (luBg / luDefer /
 * _luCoalesceGet) belonging to another work package, and this must not be
 * interleaved with it. Nothing here edits that code.
 *
 * It sits BENEATH the Phase-2 GET coalescing layer: _luCoalesceGet caches the
 * promise returned by fetch(), so a coalesced set of callers all receive the
 * retried response. Load order therefore does not matter to coalescing, only
 * that this runs before the first authenticated request.
 *
 * Deliberate non-goals:
 *   - It never logs the user out. A refresh that genuinely fails leaves the
 *     original 401 to the caller; the bootstrap still decides on next load.
 *     Forcing a logout here would recreate the bug this replaces.
 *   - It never touches /api/auth/refresh or /api/auth/login (no recursion).
 *   - It never intercepts embed/API-key traffic, which has no refresh token.
 */
(function () {
  'use strict';

  if (window.__luAuthRetryInstalled) { return; }
  window.__luAuthRetryInstalled = true;

  var REFRESH_PATH = '/api/auth/refresh';
  var LOGIN_PATH   = '/api/auth/login';
  // After a genuinely failed refresh, stop trying for a while. Without this a
  // dead session turns one 401 storm into a refresh storm.
  var COOLDOWN_MS  = 30000;

  var nativeFetch   = window.fetch.bind(window);
  var inflight      = null;   // single-flight: N concurrent 401s -> ONE refresh
  var lastFailureAt = 0;

  var stats = window.__luAuthRetryStats = {
    intercepted: 0, refreshed: 0, retried: 0, failed: 0, cooldownSkips: 0
  };

  function apiBase() {
    return window._luBase || location.origin;
  }

  function urlOf(input) {
    try {
      if (typeof input === 'string') { return input; }
      if (typeof Request !== 'undefined' && input instanceof Request) { return input.url; }
      if (input && input.url) { return input.url; }
      return String(input || '');
    } catch (e) { return ''; }
  }

  function eligible(rawUrl) {
    if (!rawUrl) { return false; }
    var u;
    try { u = new URL(rawUrl, location.origin); } catch (e) { return false; }
    if (u.origin !== location.origin) { return false; }
    if (u.pathname.indexOf('/api/') !== 0) { return false; }
    if (u.pathname.indexOf(REFRESH_PATH) === 0) { return false; }
    if (u.pathname.indexOf(LOGIN_PATH) === 0) { return false; }
    // Embed context authenticates with X-API-KEY and has no refresh token.
    if (window._LGSC_EMBED && window._LGSC_EMBED.api_key) { return false; }
    try { if (!localStorage.getItem('lu_refresh_token')) { return false; } } catch (e) { return false; }
    return true;
  }

  function refreshOnce() {
    if (inflight) { return inflight; }
    if (Date.now() - lastFailureAt < COOLDOWN_MS) {
      stats.cooldownSkips++;
      return Promise.resolve(false);
    }

    inflight = nativeFetch(apiBase() + REFRESH_PATH, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ refresh_token: localStorage.getItem('lu_refresh_token') || '' }),
      cache: 'no-store'
    }).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    }).then(function (d) {
      if (!d || !d.access_token) { throw new Error('no access_token in response'); }
      localStorage.setItem('lu_token', d.access_token);
      if (d.refresh_token) { localStorage.setItem('lu_refresh_token', d.refresh_token); }
      stats.refreshed++;
      console.info('[luAuth] access token renewed after 401');
      return true;
    }).catch(function (e) {
      lastFailureAt = Date.now();
      stats.failed++;
      console.warn('[luAuth] refresh failed, leaving the 401 to the caller:', e && e.message);
      return false;
    }).then(function (ok) {
      inflight = null;
      return ok;
    });

    return inflight;
  }

  function withFreshToken(headersInit) {
    var h;
    try { h = new Headers(headersInit || {}); } catch (e) { h = new Headers(); }
    h.set('Authorization', 'Bearer ' + (localStorage.getItem('lu_token') || ''));
    return h;
  }

  window.fetch = function (input, init) {
    var rawUrl = urlOf(input);
    if (!eligible(rawUrl)) { return nativeFetch(input, init); }

    var isRequest = (typeof Request !== 'undefined') && (input instanceof Request);
    // A Request body is single-use, so the replayable copy must be taken
    // BEFORE the first send or the retry would go out with an empty body.
    var replay = null;
    if (isRequest) {
      try { replay = input.clone(); } catch (e) { replay = null; }
    }

    // Which token this request actually went out with. Used below to tell a
    // stale-token 401 apart from one that some other caller has already fixed.
    var tokenAtSend = null;
    try { tokenAtSend = localStorage.getItem('lu_token'); } catch (e) {}

    stats.intercepted++;

    function resend() {
      stats.retried++;
      if (isRequest) {
        return nativeFetch(new Request(replay, { headers: withFreshToken(replay.headers) }));
      }
      var nextInit = {};
      var k;
      if (init) { for (k in init) { if (Object.prototype.hasOwnProperty.call(init, k)) { nextInit[k] = init[k]; } } }
      nextInit.headers = withFreshToken(init && init.headers);
      return nativeFetch(rawUrl, nextInit);
    }

    return nativeFetch(input, init).then(function (res) {
      if (res.status !== 401) { return res; }
      if (isRequest && !replay) { return res; }   // cannot safely replay

      // Requests already in flight when a refresh completed will still come
      // back 401, because they were sent with the superseded token. Rotating
      // again for each of them turns one expiry into a burst of refreshes (8
      // concurrent 401s produced 2 rotations before this check). If the stored
      // token has moved on since we sent, someone already did the work — just
      // resend with the current one.
      var current = null;
      try { current = localStorage.getItem('lu_token'); } catch (e) {}
      if (current && current !== tokenAtSend) { return resend(); }

      return refreshOnce().then(function (ok) {
        if (!ok) { return res; }
        return resend();
      });
    });
  };

  console.info('[luAuth] 401 refresh-and-retry interceptor installed');
})();
