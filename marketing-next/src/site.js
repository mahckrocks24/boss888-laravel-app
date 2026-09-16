// levelupgrowth.io — behaviour that is not decoration.
(function () {
  var toggle = document.querySelector('.nav-toggle');
  var mobile = document.getElementById('mobile-nav');
  var backdrop = document.getElementById('nav-backdrop');
  var setMenu = function (open) {
    toggle.setAttribute('aria-expanded', String(open));
    mobile.hidden = !open;
    if (backdrop) { backdrop.hidden = !open; }
    document.documentElement.classList.toggle('menu-open', open);
  };
  if (toggle && mobile) {
    toggle.addEventListener('click', function () { setMenu(toggle.getAttribute('aria-expanded') !== 'true'); });
    if (backdrop) { backdrop.addEventListener('click', function () { setMenu(false); }); }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setMenu(false); } });
    window.addEventListener('resize', function () { if (window.innerWidth > 900) { setMenu(false); } });
  }

  // Pricing: monthly / annual. Prices come from data attributes the build wrote from the plans endpoint.
  var billing = document.querySelector('.billing-toggle');
  if (billing) {
    var money = function (n) { n = Number(n); return '$' + (Math.floor(n) === n ? n.toLocaleString('en-US') : n.toFixed(2)); };
    var apply = function (period) {
      billing.querySelectorAll('.toggle-btn').forEach(function (b) {
        var on = b.getAttribute('data-period') === period;
        b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', String(on));
      });
      document.querySelectorAll('[data-monthly][data-annual]').forEach(function (el) {
        var v = el.getAttribute(period === 'annual' ? 'data-annual' : 'data-monthly');
        var amount = el.querySelector('.amount');
        if (amount) { amount.textContent = money(v); } else { el.textContent = money(v); }
        var small = el.querySelector('small');
        if (small) { small.textContent = period === 'annual' ? '/month, billed annually' : '/month'; }
      });
      try { localStorage.setItem('lug.billing', period); } catch (e) {}
    };
    billing.addEventListener('click', function (e) {
      var b = e.target.closest('.toggle-btn'); if (b) { apply(b.getAttribute('data-period')); }
    });
    var saved = null; try { saved = localStorage.getItem('lug.billing'); } catch (e) {}
    if (saved === 'annual') { apply('annual'); }
  }
})();

// Waitlist form: posts JSON to the platform, shows the reference, never leaves the page.
(function () {
  var form = document.getElementById('waitlist');
  if (!form) { return; }
  var params = new URLSearchParams(location.search);
  var plan = params.get('plan');
  if (plan) { var sel = form.querySelector('[name=plan]'); if (sel) { sel.value = plan; } }
  var err = document.getElementById('wl-error');
  var show = function (msg) { err.textContent = msg; err.hidden = !msg; };
  form.addEventListener('submit', function (e) {
    e.preventDefault(); show('');
    var btn = form.querySelector('button[type=submit]'); btn.disabled = true;
    var body = {};
    new FormData(form).forEach(function (v, k) { body[k] = v; });
    body.consent = form.querySelector('[name=consent]').checked ? 1 : 0;
    body.referrer = document.referrer || '';
    form.querySelectorAll('[aria-invalid]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
    fetch(form.action, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); })
      .then(function (res) {
        if (res.status === 200 && res.json.ok) {
          document.getElementById('wl-ref').textContent = res.json.reference;
          form.classList.add('is-done');
          return;
        }
        if (res.status === 422 && res.json.errors) {
          var first = null;
          Object.keys(res.json.errors).forEach(function (k) { var i = form.querySelector('[name=' + k + ']'); if (i) { i.setAttribute('aria-invalid', 'true'); if (!first) { first = k; } } });
          show(res.json.errors[first] ? res.json.errors[first][0] : 'Please check the highlighted fields.');
        } else {
          show(res.json.message || 'Something went wrong. Please email hello@levelupgrowth.io.');
        }
      })
      .catch(function () { show('Network problem. Please try again.'); })
      .then(function () { btn.disabled = false; });
  });
})();

// Generic site forms (contact): post JSON to data-api, show errors inline, show the reference on success.
(function () {
  document.querySelectorAll('form[data-api]').forEach(function (form) {
    var err = form.querySelector('.form-error');
    var show = function (msg) { if (err) { err.textContent = msg; err.hidden = !msg; } };
    form.addEventListener('submit', function (e) {
      e.preventDefault(); show('');
      var btn = form.querySelector('button[type=submit]'); btn.disabled = true;
      var body = {}; new FormData(form).forEach(function (v, k) { body[k] = v; });
      var consent = form.querySelector('[name=consent]'); if (consent) { body.consent = consent.checked ? 1 : 0; }
      form.querySelectorAll('[aria-invalid]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
      fetch(form.getAttribute('data-api'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); })
        .then(function (res) {
          if (res.status === 200 && res.json.ok) { var ref = form.querySelector('.ref'); if (ref) { ref.textContent = res.json.reference; } form.classList.add('is-done'); return; }
          if (res.status === 422 && res.json.errors) { var first = null; Object.keys(res.json.errors).forEach(function (k) { var i = form.querySelector('[name=' + k + ']'); if (i) { i.setAttribute('aria-invalid', 'true'); if (!first) { first = k; } } }); show(res.json.errors[first] ? res.json.errors[first][0] : 'Please check the highlighted fields.'); }
          else { show(res.json.message || 'Something went wrong. Please email hello@levelupgrowth.io.'); }
        })
        .catch(function () { show('Network problem. Please try again.'); })
        .then(function () { btn.disabled = false; });
    });
  });
})();

// Status page: refresh from the live endpoint.
(function () {
  var list = document.getElementById('status-list');
  if (!list) { return; }
  var refresh = function () {
    fetch(list.getAttribute('data-api'), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
      var overall = document.getElementById('status-overall'); if (overall) { overall.textContent = (j.overall || 'unknown').replace(/_/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); }); }
      Object.keys(j.components || {}).forEach(function (k) {
        var li = list.querySelector('[data-component="' + k + '"]'); if (!li) { return; }
        var c = j.components[k]; var dot = li.querySelector('.status-dot'); dot.className = 'status-dot ' + c.state;
        var extra = (c.latency_ms !== undefined ? ' · ' + c.latency_ms + ' ms' : '') + (c.depth !== undefined ? ' · ' + c.depth + ' queued' : '');
        li.querySelector('.status-state').textContent = String(c.state).replace(/_/g, ' ') + extra;
      });
      var checked = document.getElementById('status-checked'); if (checked && j.checked_at) { checked.textContent = 'Last checked ' + new Date(j.checked_at).toUTCString().replace(' GMT', ' UTC') + '.'; }
    }).catch(function () {});
  };
  refresh(); setInterval(refresh, 60000);
})();

// Desktop dropdowns: click to open, click outside or Escape to close, hover on pointer devices, one open at a time.
(function () {
  var wraps = Array.prototype.slice.call(document.querySelectorAll('.nav-dd-wrap'));
  if (!wraps.length) { return; }
  var hoverable = window.matchMedia && window.matchMedia('(hover: hover)').matches;
  var closeAll = function (except) {
    wraps.forEach(function (w) {
      if (w === except) { return; }
      w.querySelector('.nav-trigger').setAttribute('aria-expanded', 'false');
      w.querySelector('.mega, .nav-dd').hidden = true;
    });
  };
  wraps.forEach(function (w) {
    var t = w.querySelector('.nav-trigger'); var p = w.querySelector('.mega, .nav-dd');
    t.addEventListener('click', function (e) { e.stopPropagation(); var open = t.getAttribute('aria-expanded') === 'true'; closeAll(w); t.setAttribute('aria-expanded', String(!open)); p.hidden = open; });
    p.addEventListener('click', function (e) { e.stopPropagation(); });
    if (hoverable) {
      // Opening is instant; closing waits, so a diagonal move towards the far side of the panel does not
      // shut it on the way. Re-entering cancels the pending close.
      var shut = null;
      w.addEventListener('mouseenter', function () {
        if (shut) { clearTimeout(shut); shut = null; }
        closeAll(w); t.setAttribute('aria-expanded', 'true'); p.hidden = false;
      });
      w.addEventListener('mouseleave', function () {
        if (shut) { clearTimeout(shut); }
        shut = setTimeout(function () { t.setAttribute('aria-expanded', 'false'); p.hidden = true; shut = null; }, 140);
      });
    }
  });
  document.addEventListener('click', function () { closeAll(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAll(null); } });
})();





/* The replays (2026-09-08). A short simulation should not need a button — it loops on its own while it is on
   screen, and stops when it is not, so nothing runs in a tab nobody is looking at.

   Progressive as before: the whole transcript and the result are in the HTML and visible. This only adds the reveal
   when JavaScript and motion are both available, and every state ends visible whatever happens. */
(function () {
  var sims = document.querySelectorAll('[data-sim]');
  if (!sims.length) return;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;

  Array.prototype.forEach.call(sims, function (sim) {
    var steps = sim.querySelectorAll('[data-sim-step]');
    var subs = sim.querySelectorAll('[data-sim-substep]');
    var result = sim.querySelector('[data-sim-result]');
    var play = sim.querySelector('[data-sim-play]');
    var timers = [];
    var loopTimer = null;
    var visible = false;
    var started = false;
    if (!steps.length || !result) return;
    sim.classList.add('sim-armed');
    if (play) play.remove();          // a four-line simulation does not need a control

    function reset() {
      timers.forEach(clearTimeout); timers = [];
      Array.prototype.forEach.call(steps, function (s) { s.classList.remove('on'); });
      Array.prototype.forEach.call(subs, function (s) { s.classList.remove('on'); });
      result.classList.remove('on');
    }
    function at(ms, fn) { timers.push(setTimeout(fn, ms)); }
    function showAll() {
      Array.prototype.forEach.call(steps, function (s) { s.classList.add('on'); });
      Array.prototype.forEach.call(subs, function (s) { s.classList.add('on'); });
      result.classList.add('on');
    }

    // one pass, returning how long it takes
    function run() {
      reset();
      var t = 260;
      Array.prototype.forEach.call(steps, function (s) {
        var isBuild = s.classList.contains('sim-build');
        at(t, function () { s.classList.add('on'); });
        t += isBuild ? 260 : (s.classList.contains('sim-arthur') || s.classList.contains('sim-sarah') || s.classList.contains('sim-bot') ? 900 : 620);
      });
      Array.prototype.forEach.call(subs, function (s, i) { at(t + i * 260, function () { s.classList.add('on'); }); });
      t += subs.length * 260 + 200;
      at(t, function () { result.classList.add('on'); });
      return t;
    }

    function cycle() {
      var total = run();
      clearTimeout(loopTimer);
      // hold the finished state for a beat so it can be read, then go round again
      loopTimer = setTimeout(function () { if (visible) { cycle(); } }, total + 3200);
    }

    // Nothing may stay invisible because an observer never fired.
    setTimeout(function () { if (!started) showAll(); }, 6000);

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          visible = e.isIntersecting;
          if (visible) { started = true; cycle(); }
          else { clearTimeout(loopTimer); timers.forEach(clearTimeout); timers = []; showAll(); }
        });
      }, { threshold: 0.25 }).observe(sim);
    } else { started = true; visible = true; cycle(); }
  });
})();

/* The loop lights along as you reach it. One pass, no controls, no repetition — it is a diagram, not a video. */
(function () {
  var loops = document.querySelectorAll('.loop');
  if (!loops.length || !('IntersectionObserver' in window)) {
    Array.prototype.forEach.call(document.querySelectorAll('.loop-step'), function (s) { s.classList.add('lit'); });
    return;
  }
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  Array.prototype.forEach.call(loops, function (loop) {
    var steps = loop.querySelectorAll('.loop-step');
    if (reduce) { Array.prototype.forEach.call(steps, function (s) { s.classList.add('lit'); }); return; }
    var done = false;
    new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting || done) return;
        done = true;
        Array.prototype.forEach.call(steps, function (s, i) { setTimeout(function () { s.classList.add('lit'); }, i * 420); });
      });
    }, { threshold: 0.35 }).observe(loop);
  });
})();

/* ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
   ARTHUR IN THE HERO — the app's own wizard, not a copy of it (Owner, 2026-09-08:
   "the arthur on the outside must be the same as the inside arthur wizard... the only difference is the sign up
   after the summary").

   REPORT-0056 audited the two and found eight differences, all of them because the hero was a SECOND interface
   calling the same API. Rather than port six hundred lines that would drift the moment the app changed, this loads
   /app/js/arthur-chat.js — the actual wizard — and changes exactly three things around it:

     1. THREE SHIMS. The wizard expects window.icon, bld_escH and showToast from the app shell. They are provided
        here with the same signatures and the same eight icon paths it actually uses.
     2. ONE REDIRECT. Before signup there is no token, so a POST to /api/builder/arthur/message is rewritten to the
        public chat route. Everything after signup goes to the real endpoint with the real token.
     3. ONE INTERPOSITION. _arthurShowConfirmActions is wrapped: signed out it shows the signup card and remembers
        the summary; signed in it calls straight through to the app's own panel. That single wrap IS the Owner's
        "sign up after the summary", and it is also the only order that works, because the panel's logo and photo
        steps upload to an authenticated endpoint.

   Everything else on screen — greeting, bubbles, typing state, summary, finishing touches, build animation — is the
   app's code running unmodified.
   ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════ */
/* ── SIGNED-IN VISITOR (2026-09-11) ───────────────────────────────────────────────────────────────────────────
   A live session is decided by the server (/api/auth/me), never by the presence of a token. The answer is
   published on <html data-lu-session="in|out|unknown"> and as window.__luSession, and the page's account CTAs
   follow it: signed in, "Log in" is hidden and "Start free" becomes "Dashboard" — one door. */
(function () {
  var doc = document.documentElement;
  if (!doc.hasAttribute('data-lu-session')) doc.setAttribute('data-lu-session', 'unknown');   // <head> may already say 'likely'
  window.__luSession = { state: 'unknown', user: null, ready: null };
  var token = null;
  try { token = localStorage.getItem('lu_token'); } catch (e) {}
  var swapNav = function (user) {
    var first = (user && user.name ? String(user.name).split(' ')[0] : '');
    // Signed in, one door is enough: the login link goes away and the primary button reads "Dashboard" (Owner, 2026-09-11).
    document.querySelectorAll('a.lu-login').forEach(function (a) { a.hidden = true; a.style.display = 'none'; a.setAttribute('aria-hidden', 'true'); });
    document.querySelectorAll('a[href^="/start/"], a[href^="/next/start/"]').forEach(function (a) {
      var t = (a.textContent || '').trim().toLowerCase();
      if (t.indexOf('start') === 0 || a.hasAttribute('data-lu-signup')) { if (!a.dataset.hrefOut) a.dataset.hrefOut = a.getAttribute('href'); a.setAttribute('href', '/app/'); }   // labels are CSS-driven now
    });
  };
  if (token) { try { swapNav(null); } catch (e) {} }   // hrefs point at the app right away; the probe may still say 'out' (then labels revert, hrefs harmlessly stay /app/ → login)
  var unswapNav = function () {
    document.querySelectorAll('a.lu-login').forEach(function (a) { a.hidden = false; a.style.display = ''; a.removeAttribute('aria-hidden'); });
    document.querySelectorAll('a[data-href-out]').forEach(function (a) { a.setAttribute('href', a.dataset.hrefOut); });
  };
  var settle = function (state, user) {
    window.__luSession.state = state; window.__luSession.user = user || null;
    if (state === 'out') unswapNav();   // the head hint was wrong: put the signed-out doors back
    doc.setAttribute('data-lu-session', state);
    if (state === 'in') { if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { swapNav(user); }); } else { swapNav(user); } }
    document.dispatchEvent(new CustomEvent('lu:session', { detail: window.__luSession }));
  };
  // CROSS-TAB SESSION (2026-09-11): the answer can change while this page is open (a login or logout in another
  // tab of the same site), and an access token the server no longer honours may still have a live refresh token
  // beside it. probe() is the single place that decides, and it is safe to call any number of times.
  var me = function (t) {
    return fetch('/api/auth/me', { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (r) { if (r.ok) return r.json(); if (r.status === 401 || r.status === 403) return { __dead: true }; throw new Error('me_' + r.status); });
  };
  var refresh = function () {
    var rt = null; try { rt = localStorage.getItem('lu_refresh_token'); } catch (e) {}
    if (!rt) return Promise.resolve(null);
    return fetch('/api/auth/refresh', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ refresh_token: rt }), cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.access_token) return null;
        try { localStorage.setItem('lu_token', d.access_token); if (d.refresh_token) localStorage.setItem('lu_refresh_token', d.refresh_token); } catch (e) {}
        return d.access_token;
      }).catch(function () { return null; });
  };
  var probing = null;
  var probe = function () {
    if (probing) return probing;
    var t = null; try { t = localStorage.getItem('lu_token'); } catch (e) {}
    var run = (t ? me(t) : Promise.resolve({ __dead: true, __none: true }))
      .then(function (j) {
        if (j && !j.__dead) return j;
        // Dead or missing access token: a live refresh token can still mint a session.
        return refresh().then(function (nt) { return nt ? me(nt) : { __dead: true }; });
      })
      .then(function (j) {
        var user = j && !j.__dead && (j.user || j);
        if (user && (user.email || user.name)) { settle('in', { name: user.name || '', email: user.email || '' }); return 'in'; }
        // Neither token is honoured: this browser has no session. Drop both so nothing else trusts them.
        try { localStorage.removeItem('lu_token'); localStorage.removeItem('lu_refresh_token'); } catch (e) {}
        settle('out', null); return 'out';
      })
      .catch(function () { settle(window.__luSession.state === 'in' ? 'in' : 'unknown', window.__luSession.user); return window.__luSession.state; })
      .then(function (st) { probing = null; return st; });
    probing = run;
    return run;
  };
  window.__luSession.recheck = probe;
  window.__luSession.ready = probe();
  // Another tab signed in or out: follow it. Coming back to this tab: look again (cheap, one GET).
  try {
    window.addEventListener('storage', function (e) { if (!e.key || e.key === 'lu_token' || e.key === 'lu_refresh_token') probe(); });
    var last = Date.now();
    var again = function () { if (Date.now() - last > 15000) { last = Date.now(); probe(); } };
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') again(); });
    window.addEventListener('pageshow', function (e) { if (e.persisted) again(); });
  } catch (e) {}
})();
(function () {
  var host = document.getElementById('ax');
  if (!host) return;
  /* SIGNED-IN VISITOR (2026-09-11): the wizard is for people who do not have an account yet. */
  var axSignedInCard = function (user) {
    var first = user && user.name ? window.bld_escH ? window.bld_escH(String(user.name).split(' ')[0]) : String(user.name).split(' ')[0] : '';
    host.innerHTML =
      '<div class="ax-signed-in" role="status">' +
        '<div class="ax-si-head"><span class="ax-si-dot" aria-hidden="true"></span>' + (first ? "You're signed in as " + first + '.' : "You're signed in.") + '</div>' +
        '<p class="ax-si-body">Arthur builds inside the app, where your account, your sites and your uploads live. Nothing is built from this page.</p>' +
        '<div class="ax-si-actions"><a class="btn btn-primary" href="/app/">Open the app and build with Arthur &rarr;</a>' +
        '<a class="ax-si-link" href="/app/">Dashboard</a></div>' +
      '</div>';
    host.classList.add('ax-has-session');
  };
  var axStyle = document.createElement('style');
  axStyle.textContent = '.ax-signed-in{padding:28px 26px;border:1px solid rgba(255,255,255,.12);border-radius:16px;background:rgba(255,255,255,.04)}'
    + '.ax-si-head{font-weight:600;font-size:17px;display:flex;align-items:center;gap:10px}'
    + '.ax-si-dot{width:9px;height:9px;border-radius:2px;background:#00E5A8;display:inline-block}'
    + '.ax-si-body{margin:10px 0 18px;opacity:.8;max-width:56ch}'
    + '.ax-si-actions{display:flex;gap:16px;align-items:center;flex-wrap:wrap}'
    + '.ax-si-link{opacity:.8;text-decoration:underline;text-underline-offset:3px}';
  document.head.appendChild(axStyle);

  function mountWizard() {
  var ICONS = {
    ai: '<path d="M10 2l1.5 4.5L16 8l-4.5 1.5L10 14l-1.5-4.5L4 8l4.5-1.5L10 2z"/><path d="M16 14l.75 2.25L19 17l-2.25.75L16 20l-.75-2.25L13 17l2.25-.75L16 14z"/>',
    check: '<path d="M4 10l4 4 8-8"/>',
    edit: '<path d="M13 3l4 4-9 9H4v-4L13 3z"/>',
    info: '<circle cx="10" cy="10" r="7"/><path d="M10 9v5M10 7v.5"/>',
    eye: '<path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z"/><circle cx="10" cy="10" r="2.5"/>',
    link: '<path d="M8 12a4 4 0 005.657 0l2.828-2.828a4 4 0 00-5.657-5.657L9.172 5.17"/><path d="M12 8a4 4 0 00-5.657 0L3.515 10.83a4 4 0 005.657 5.657l1.656-1.657"/>',
    more: '<circle cx="5" cy="10" r="1"/><circle cx="10" cy="10" r="1"/><circle cx="15" cy="10" r="1"/>',
    attach: '<path d="M15 8l-6.5 6.5a3 3 0 01-4.24-4.24l7.07-7.07a2 2 0 012.83 2.83L7.07 13.07a1 1 0 01-1.41-1.41L11 6.3"/>'
  };

  if (typeof window.icon !== 'function') {
    window.icon = function (name, size) {
      var p = ICONS[name] || ICONS.info;
      var s = size || 16;
      return '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="' + s + '" height="' + s + '" class="lu-icon">' + p + '</svg>';
    };
  }

  if (typeof window.bld_escH !== 'function') {
    window.bld_escH = function (t) {
      return String(t == null ? '' : t)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };
  }

  if (typeof window.showToast !== 'function') {
    window.showToast = function (msg, kind) {
      var t = document.createElement('div');
      t.className = 'ax-toast' + (kind === 'error' ? ' err' : '');
      t.textContent = msg;
      document.body.appendChild(t);
      setTimeout(function () { t.classList.add('go'); }, 3200);
      setTimeout(function () { t.remove(); }, 3900);
    };
  }

  /* ── 2. before signup there is no token, so the wizard's own call goes to the public chat route ─────────────── */
  // A token's PRESENCE is not its validity (2026-09-11): an expired lu_token sent the Owner's messages to the
  // authenticated route, which answered 401, and the widget went silent. Once a 401 is seen, this visit is
  // treated as signed out and the dead token is cleared so nothing else on the page trusts it either.
  var tokenDead = false;
  var signedIn = function () { try { return !tokenDead && !!localStorage.getItem('lu_token'); } catch (e) { return false; } };
  var nativeFetch = window.fetch.bind(window);
  var publicChat = function (parsed) {
    return nativeFetch('/api/public/builder/arthur/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ message: parsed.message || '', history: parsed.history || [] })
    });
  };
  // CONVERSATION MEMORY (2026-09-12): after any Arthur exchange, remember the wizard's history for 24 h.
  var axEpoch = 0;   // bumped by ↻ new conversation
  window.addEventListener('lu:ax-restart', function () { axEpoch++; });
  var rememberAt = function (epoch) { return function (res) { setTimeout(function () { try { if (epoch !== axEpoch) return; var h = window._arthur && window._arthur.history; if (h && h.length) localStorage.setItem('lu_arthur_mkt', JSON.stringify({ history: h, at: Date.now() })); if (window.__axSync) window.__axSync(); } catch (e) {} }, 400); return res; }; };
  var remember = rememberAt(0);
  var markSignedOut = function () {
    tokenDead = true;
    // The session probe owns the tokens: it will refresh them if it can, or drop them if it cannot. If it comes
    // back 'in', this page trusts the token again.
    try { if (window.__luSession && window.__luSession.recheck) window.__luSession.recheck().then(function (st) { if (st === 'in') tokenDead = false; }); } catch (e) {}
  };
  window.fetch = function (input, init) {
    var url = (typeof input === 'string') ? input : (input && input.url) || '';
    if (url.indexOf('/api/builder/arthur/message') === -1) return nativeFetch(input, init);

    var body = (init && init.body) || '{}';
    var remember = rememberAt(axEpoch);
    var parsed = {};
    try { parsed = JSON.parse(body); } catch (e) {}

    if (!signedIn()) {
      // A build can only ever be reached with an account; the public route cannot build, and refusing here means
      // a confirm that somehow fired while signed out fails loudly instead of silently doing nothing.
      if (parsed.confirm) {
        return Promise.resolve(new Response(
          JSON.stringify({ type: 'error', message: 'Create your account first and Arthur will build it.' }),
          { status: 401, headers: { 'Content-Type': 'application/json' } }
        ));
      }
      return publicChat(parsed).then(remember);
    }

    // Believed signed in: try the real route, but a 401 means the token is dead — fall back, do not go quiet.
    return nativeFetch(input, init).then(remember).then(function (res) {
      if (res && res.status === 401) {
        markSignedOut();
        if (parsed.confirm) {
          return new Response(
            JSON.stringify({ type: 'error', message: 'Your session has expired — sign in again and Arthur will build it.' }),
            { status: 401, headers: { 'Content-Type': 'application/json' } }
          );
        }
        return publicChat(parsed).then(remember);
      }
      return res;
    });
  };

  /* ── 3. the one difference: the account is asked for between the summary and the finishing touches ──────────── */
  var pendingBuildData = null;

  function signupCard() {
    var feed = document.getElementById('arthur-feed');
    if (!feed || document.getElementById('ax-signup-card')) return;
    var biz = (pendingBuildData && pendingBuildData.business_name) ? window.bld_escH(pendingBuildData.business_name) : 'your website';
    var card = document.createElement('div');
    card.id = 'ax-signup-card';
    card.className = 'ar-steps';
    card.innerHTML =
      '<div class="ar-steps-head"><div>' +
        '<div class="ar-steps-title">Create your account to build ' + biz + '</div>' +
        '<div class="ar-steps-sub">Free plan, no card. Your brief is saved and Arthur picks up exactly here.</div>' +
      '</div></div>' +
      '<div style="padding:16px 18px">' +
        '<div class="ax-grid2">' +
          '<input id="ax-name" type="text" autocomplete="name" placeholder="Your name">' +
          '<input id="ax-email" type="email" autocomplete="email" placeholder="you@business.co.uk">' +
        '</div>' +
        '<input id="ax-pass" type="password" autocomplete="new-password" placeholder="Password - 8+ characters, one capital, one number">' +
        '<div class="ar-actions"><button type="button" class="ar-btn primary" id="ax-do-signup">Create account and continue</button></div>' +
        '<div class="ax-err" id="ax-err"></div>' +
      '</div>';
    feed.appendChild(card);
    // bring it into view inside the feed (the page itself must not move)
    var toCard = function () { try { feed.scrollTop = feed.scrollHeight; } catch (e) {} };   // the card is the last child
    toCard(); setTimeout(toCard, 250); setTimeout(toCard, 900);
    document.getElementById('ax-do-signup').addEventListener('click', doSignup);
    document.getElementById('ax-pass').addEventListener('keydown', function (e) { if (e.key === 'Enter') doSignup(); });
    var chatInput = document.getElementById('arthur-chat-input');
    if (chatInput) chatInput.placeholder = 'Create your account above to carry on';
    setTimeout(function () { var n = document.getElementById('ax-name'); if (n) n.focus(); }, 120);
  }

  function doSignup() {
    var e = document.getElementById('ax-err');
    var name = (document.getElementById('ax-name').value || '').trim();
    var email = (document.getElementById('ax-email').value || '').trim();
    var pass = document.getElementById('ax-pass').value || '';
    function bad(m) { e.textContent = m; }
    if (!name) return bad('Your name, so Arthur knows who he is building for.');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return bad('That email address does not look right.');
    if (pass.length < 8 || !/[A-Z]/.test(pass) || !/[0-9]/.test(pass)) {
      return bad('Password needs at least 8 characters, one capital letter and one number.');
    }
    bad('');
    var btn = document.getElementById('ax-do-signup');
    btn.disabled = true; btn.textContent = 'Creating your account...';
    nativeFetch('/api/auth/register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        name: name, email: email, password: pass, password_confirmation: pass,
        workspace_name: (pendingBuildData && pendingBuildData.business_name) || null
      })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, j: j }; }); })
      .then(function (r) {
        btn.disabled = false; btn.textContent = 'Create account and continue';
        if (!r.ok) {
          if (r.status === 403) return bad('Signups are not open on this address yet.');
          if (r.j && r.j.errors) { var k = Object.keys(r.j.errors)[0]; return bad(r.j.errors[k][0]); }
          return bad((r.j && r.j.message) || 'That did not work. Please try again.');
        }
        try {
          localStorage.setItem('lu_token', r.j.access_token);
          if (r.j.refresh_token) localStorage.setItem('lu_refresh_token', r.j.refresh_token);
          if (r.j.current_workspace_id) localStorage.setItem('lu_workspace_id', String(r.j.current_workspace_id));
          localStorage.setItem('lu_visibility_mode', 'advanced');
        } catch (_e) {}
        var card = document.getElementById('ax-signup-card');
        if (card) card.remove();
        if (typeof window._arthurAddMsg === 'function') {
          window._arthurAddMsg('arthur', "You're in, " + window.bld_escH(name.split(' ')[0])
            + '. Taking you into the builder to finish this off…');
        }
        // The brief crosses with them, so the wizard opens mid-conversation instead of asking twice.
        try {
          localStorage.setItem('lu_arthur_handoff', JSON.stringify({
            build_data:  pendingBuildData || {},
            history:     (window._arthur && window._arthur.history) || history || [],
            themes:      window._arthurThemes || null,
            themes_all:  window._arthurThemesAll || null,
            user_name:   name
          }));
          try{for(var ci=localStorage.length-1;ci>=0;ci--){var ck=localStorage.key(ci);if(ck&&ck.indexOf('lu_website_ctx_')===0)localStorage.removeItem(ck);}}catch(_c){} localStorage.setItem('lu_boot_action', 'wizard'); // 2026-09-12: a fresh sign-up must not inherit a website selection left by an earlier session
          localStorage.setItem('lu_visibility_mode', 'advanced');
        } catch (_e) {}
        setTimeout(function () { window.location.href = '/app/'; }, 700);
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = 'Create account and continue';
        bad('Could not reach the server. Please try again.');
      });
  }

  var showConfirm = null;   // the app's real panel, captured once its script has loaded

  /* ── 4. load the real wizard, then open it inline in the hero rather than as a full-screen modal ────────────── */
  function boot() {
    showConfirm = window._arthurShowConfirmActions;
    if (typeof showConfirm !== 'function' || typeof window.wsShowArthurWizard !== 'function') {
      host.innerHTML = '<div class="ax-fallback">Arthur could not load here. '
        + '<a href="/app/">Open the builder</a> and he is waiting inside.</div>';
      return;
    }

    // The interposition. Signed out: remember the summary and ask for the account instead.
    window._arthurShowConfirmActions = function (buildData) {
      pendingBuildData = buildData || pendingBuildData;
      // Nothing is built from this page (EV-0987). The only question is whether an account exists — asked of the
      // server now, because another tab may have signed in since this page loaded.
      var decide = (window.__luSession && window.__luSession.recheck) ? window.__luSession.recheck() : Promise.resolve(signedIn() ? 'in' : 'out');
      decide.then(function (state) {
        if (state === 'in') { handoffToApp(window.__luSession.user); return; }
        signupCard();
      });
    };
    // The brief crosses into the app, exactly as it does after a fresh sign-up, and the wizard resumes there.
    function handoffToApp(user) {
      var first = user && user.name ? String(user.name).split(' ')[0] : '';
      if (typeof window._arthurAddMsg === 'function') {
        window._arthurAddMsg('arthur', (first ? "You're signed in as " + window.bld_escH(first) + '. ' : "You're signed in. ")
          + 'Taking you into the builder to finish this off…');
      }
      try {
        localStorage.setItem('lu_arthur_handoff', JSON.stringify({
          build_data:  pendingBuildData || {},
          history:     (window._arthur && window._arthur.history) || [],
          themes:      window._arthurThemes || null,
          themes_all:  window._arthurThemesAll || null,
          user_name:   (user && user.name) || ''
        }));
        try{for(var ci=localStorage.length-1;ci>=0;ci--){var ck=localStorage.key(ci);if(ck&&ck.indexOf('lu_website_ctx_')===0)localStorage.removeItem(ck);}}catch(_c){} localStorage.setItem('lu_boot_action', 'wizard'); // 2026-09-12: a fresh sign-up must not inherit a website selection left by an earlier session
        localStorage.setItem('lu_visibility_mode', 'advanced');
      } catch (_e) {}
      setTimeout(function () { window.location.href = '/app/'; }, 700);
    }

    // Inside the app the wizard owns the screen, so scrolling a message into view is free. On a marketing page
    // it drags the page down and the visitor loses the headline. Messages still scroll to the TOP of the feed,
    // exactly as the app does it, but the scrolling is confined there. This must be in place BEFORE the wizard
    // opens, because its greeting scrolls too.
    var nativeSIV = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function () {
      var feed = this.closest && this.closest('#arthur-feed');
      if (feed && this !== feed) { feed.scrollTop = this.offsetTop - feed.offsetTop; return; }
      return nativeSIV.apply(this, arguments);
    };

    try { window.wsShowArthurWizard(); } catch (e) { try { console.error('[ax] wizard failed to open', e); } catch (_) {} }
    // CONVERSATION MEMORY (2026-09-12): replay a remembered conversation into the fresh wizard, and offer a way to start over.
    try {
      var rawMem = localStorage.getItem('lu_arthur_mkt'); var mem = rawMem ? JSON.parse(rawMem) : null;
      if (mem && mem.at && Date.now() - mem.at < 86400000 && Array.isArray(mem.history) && mem.history.length && typeof window._arthurAddMsg === 'function') {
        // The wizard has already drawn its greeting; replay from the first thing the visitor said.
        var firstUser = mem.history.findIndex(function (m) { return m && m.role === 'user'; });
        mem.history.slice(firstUser < 0 ? 0 : firstUser).forEach(function (m) { if (m && m.content) window._arthurAddMsg(m.role === 'user' ? 'user' : 'arthur', m.content); });
        if (window._arthur) window._arthur.history = mem.history;
      }
      var headEl = document.querySelector('#arthur-modal .lu-arthur-modal > div');
      if (headEl && !document.getElementById('ax-restart')) {
        var restart = document.createElement('button'); restart.type = 'button'; restart.id = 'ax-restart'; restart.className = 'ax-restart';
        restart.setAttribute('aria-label', 'Start a new conversation'); restart.title = 'New conversation';
        restart.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';
        restart.addEventListener('click', function () {
          try { localStorage.removeItem('lu_arthur_mkt'); localStorage.removeItem('lu_arthur_handoff'); } catch (e) {}
          var m = document.getElementById('arthur-modal'); if (m) m.remove();
          pendingBuildData = null; grew = false;
          try { window.dispatchEvent(new CustomEvent('lu:ax-restart')); document.documentElement.classList.remove('ax-active'); } catch (e) {}
          mountWizard();
        });
        headEl.appendChild(restart);
      }
    } catch (e) {}
    // Self-check: an empty panel is worse than an honest fallback (Owner, 2026-09-12).
    setTimeout(function () {
      if (!document.getElementById('arthur-chat-input') || !document.getElementById('arthur-feed')) {
        try { console.error('[ax] wizard did not mount; page build ' + document.documentElement.getAttribute('data-build')); } catch (_) {}
        host.innerHTML = '<div class="ax-fallback">Arthur could not load here. <a href="/app/">Open the builder</a> and he is waiting inside.</div>';
      }
    }, 2500);

    // One sentence out here. The app keeps the longer version, where the guidance is useful and the
    // visitor has already chosen to open the builder.
    // (first-child, not last-child: a remembered conversation may already have been replayed after the greeting.)
    var firstMsg = document.querySelector('#arthur-feed > div:first-child > div:last-child');
    if (firstMsg) { firstMsg.textContent = "Hi! I'm Arthur, and I'll be building your website today."; }

    // The wizard builds itself as a fixed overlay. In the hero it is a panel, so it is re-parented and the overlay
    // chrome is dropped. Nothing inside the modal is touched.
    var ov = document.getElementById('arthur-modal');
    if (!ov) return;
    ov.style.cssText = 'position:static;inset:auto;z-index:auto;background:none;backdrop-filter:none;display:block';
    var inner = ov.querySelector('.lu-arthur-modal');
    if (inner) {
      inner.style.width = '100%';
      inner.style.maxWidth = 'none';
      inner.style.height = 'min(72vh, 620px)';
      inner.style.maxHeight = 'none';
      inner.style.boxShadow = 'none';
      inner.style.border = 'none';
      inner.style.background = 'transparent';
      inner.style.borderRadius = '0';
    }
    var close = ov.querySelector('button[onclick*="arthur-modal"]');
    if (close) close.style.display = 'none';
    host.innerHTML = '';
    host.appendChild(ov);
    // The wizard focuses its input 200ms after opening, and focusing scrolls. Put the page back where it was.
    // Mobile: the panel opens short so the headline survives, and grows the moment the visitor sends
    // anything. One-way — it never shrinks back mid-conversation.
    var grew = false;
    var axScrollY = 0;
    function growPanel() {
      if (grew) return;
      grew = true;
      host.classList.add('ax-tall');
      try { document.documentElement.classList.add('ax-active'); } catch (e) {}
      // Back should minimise the chat, not leave the site: the grown panel is one history entry (2026-09-11).
      try { history.pushState({ axTall: true }, '', location.href); } catch (_) {}
      // The lock is real only where the panel actually expands. Locking a desktop page that looks unchanged
      // would be a bug, so the breakpoint is checked here as well as in the stylesheet.
      if (window.matchMedia('(max-width:760px)').matches) {
        axScrollY = window.scrollY || window.pageYOffset || 0;
        document.documentElement.classList.add('ax-locked');
        document.body.classList.add('ax-locked');
        document.body.style.top = (-axScrollY) + 'px';
      }
    }
    // A way back out. Without it the expanded panel is a dialog with no dismiss, and the only exit is the site
    // menu, which is navigation rather than a way to return to what you were reading.
    var axHead = ov.querySelector('.lu-arthur-modal > div');
    if (axHead) {
      var axBack = document.createElement('button');
      axBack.type = 'button';
      axBack.className = 'ax-collapse';
      axBack.setAttribute('aria-label', 'Collapse the chat');
      axBack.title = 'Collapse';
      axBack.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>';
      var collapsePanel = function () {
        host.classList.remove('ax-tall');
        try { if (!(window._arthur && window._arthur.history && window._arthur.history.length)) document.documentElement.classList.remove('ax-active'); } catch (e) {}
        document.documentElement.classList.remove('ax-locked');
        document.body.classList.remove('ax-locked');   // the page scrolls again only once the chat lets go
        document.body.style.top = '';
        window.scrollTo(0, axScrollY);                 // undo the fixed-body offset before anything measures
        grew = false;                                  // and it may take the screen again on the next message
        host.scrollIntoView({ block: 'center' });
      };
      axBack.addEventListener('click', function () {
        // The grown panel owns one history entry; walking it back collapses via popstate and keeps history clean.
        if (history.state && history.state.axTall) { history.back(); return; }
        collapsePanel();
      });
      window.addEventListener('popstate', function () {
        if (host.classList.contains('ax-tall') && !(history.state && history.state.axTall)) collapsePanel();
      });
      axHead.appendChild(axBack);
    }
    var axInput = ov.querySelector('#arthur-chat-input');
    var axSend  = ov.querySelector('#arthur-chat-send-btn');
    // Chatbot yields to Arthur (Owner, 2026-09-12): while the visitor types here, the panel is grown, or a message
    // has been sent, the house chatbot's floater and panel are hidden (html.ax-active, see site.css).
    var axUsed = !!(window._arthur && window._arthur.history && window._arthur.history.length);
    var axFocused = false;
    var axSync = function () { document.documentElement.classList.toggle('ax-active', axUsed || axFocused || host.classList.contains('ax-tall')); };
    if (axInput) {
      // A visitor's tap or keystroke counts; the wizard's own programmatic focus on mount does not.
      ['pointerdown', 'touchstart', 'keydown'].forEach(function (ev) { axInput.addEventListener(ev, function () { axFocused = true; axSync(); }, { passive: true }); });
      axInput.addEventListener('blur', function () { axFocused = false; axSync(); });
      axInput.addEventListener('input', function () { if (axInput.value.trim()) { axFocused = true; axSync(); } });
    }
    if (axSend) axSend.addEventListener('click', function () { if (axInput && axInput.value.trim()) { axUsed = true; axSync(); } }, true);
    if (axInput) axInput.addEventListener('keydown', function (e) { if (e.key === 'Enter' && axInput.value.trim()) { axUsed = true; axSync(); } }, true);
    window.addEventListener('lu:ax-restart', function () { axUsed = false; axFocused = false; axSync(); });
    window.__axSync = function () { axUsed = axUsed || !!(window._arthur && window._arthur.history && window._arthur.history.length); axSync(); };
    axSync();
    // Capture phase: the inline onkeydown/onclick call _arthurSend, which clears the field before a
    // normally-registered listener would ever see it.
    if (axSend)  axSend.addEventListener('click', function () { if (axInput && axInput.value.trim()) growPanel(); }, true);
    if (axInput) axInput.addEventListener('keydown', function (e) { if (e.key === 'Enter' && axInput.value.trim()) growPanel(); }, true);
    // 2026-09-12 (Owner): grow-on-tap reverted — an accidental tap opened the full screen; the panel grows only
    // when the first message is sent (the send/Enter listeners above).

    // Phones: the wizard focuses its input 200 ms after opening and the browser smooth-scrolls the input into view,
    // outlasting the restores below — so on phones the focus itself must not scroll (2026-09-11).
    if (window.matchMedia('(max-width:760px)').matches) {
      var inp0 = document.getElementById('arthur-chat-input');
      if (inp0) { var nf0 = inp0.focus; inp0.focus = function (o) { return nf0.call(inp0, Object.assign({ preventScroll: true }, o || {})); }; }
    }
    var y = window.scrollY, touched = false;
    // The restores exist to undo the wizard's own focus-scroll; the moment the visitor scrolls, taps or presses a key, they stop.
    ['wheel', 'touchstart', 'pointerdown', 'keydown'].forEach(function (ev) { window.addEventListener(ev, function () { touched = true; }, { passive: true, once: true }); });
    [0, 260, 600, 1000, 1500].forEach(function (d) { setTimeout(function () { if (!touched) window.scrollTo(0, y); }, d); });
  }

  // When the app announces a finished build, offer the way in. The wizard has already drawn its own result card.
  window.addEventListener('lu:website-generated', function () {
    var feed = document.getElementById('arthur-feed');
    if (!feed || document.getElementById('ax-open-row')) return;
    var row = document.createElement('div');
    row.id = 'ax-open-row';
    row.className = 'ar-actions';
    row.style.padding = '0 4px 8px';
    row.innerHTML = '<button type="button" class="ar-btn primary" id="ax-open-app">Open it in the builder</button>';
    feed.appendChild(row);
    row.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.getElementById('ax-open-app').addEventListener('click', function () {
      try {
        localStorage.setItem('lu_visibility_mode', 'advanced');
        try{for(var ci=localStorage.length-1;ci>=0;ci--){var ck=localStorage.key(ci);if(ck&&ck.indexOf('lu_website_ctx_')===0)localStorage.removeItem(ck);}}catch(_c){} localStorage.setItem('lu_boot_action', 'wizard'); // 2026-09-12: a fresh sign-up must not inherit a website selection left by an earlier session
      } catch (_e) {}
      window.location.href = '/app/';
    });
  });

  var s = document.createElement('script');
  // Pinned to the same version the app requests, so the hero and the app can never run different builds of
  // the wizard, and so a 4-hour edge cache cannot serve the hero a stale one.
  s.src = '/app/js/arthur-chat.js?v=nofocus-20260912';
  s.onload = boot;
  s.onerror = function () {
    host.innerHTML = '<div class="ax-fallback">Arthur could not load here. '
      + '<a href="/app/">Open the builder</a> and he is waiting inside.</div>';
  };
  document.head.appendChild(s);
  } /* end mountWizard */

  // Owner, 2026-09-11: no "you are signed in" card — the header says Dashboard, people understand. Everyone gets
  // Arthur; a signed-in visitor is carried into the app at the confirm step with the brief (EV-0995).
  mountWizard();
})();

/* ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════
   DOMAIN SEARCH AND CART (2026-09-09) — a registrar's shape: the name you asked for, what else is free, and a cart
   you fill before you have an account.

   The cart is the SAME SHAPE the app uses ({domain, years, price}) but written to a PENDING key, because the app
   scopes its cart per workspace and a visitor has no workspace until they sign up. The app adopts it on first load.

   The price travels for display only. The server re-prices at order time and its figure wins.
   ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════ */
(function () {
  var form = document.getElementById('dsearch-form');
  if (!form) return;

  var root  = document.getElementById('dsearch');
  var input = document.getElementById('dsearch-q');
  var go    = document.getElementById('dsearch-go');
  var msg   = document.getElementById('dsearch-msg');
  var exact = document.getElementById('dsearch-exact');
  var recs  = document.getElementById('dsearch-recs');
  var list  = document.getElementById('dsearch-list');
  var cart  = document.getElementById('dcart');
  var lines = document.getElementById('dcart-lines');
  var total = document.getElementById('dcart-total');
  var pay   = document.getElementById('dcart-go');
  var busy  = false;

  // Rendered by signup_href(): /app/#signup once launched, /next/start/ before. Never hardcoded here.
  var signup = (root && root.dataset && root.dataset.signup) ? root.dataset.signup : '/next/start/';
  var PENDING = 'lu.domains.cart.pending';

  function esc(t) {
    return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
    });
  }
  function note(t) { msg.textContent = t || ''; msg.hidden = !t; }

  function read() {
    try { var r = JSON.parse(localStorage.getItem(PENDING) || '[]'); return Array.isArray(r) ? r : []; }
    catch (e) { return []; }
  }
  function write(items) {
    try { localStorage.setItem(PENDING, JSON.stringify(items)); } catch (e) { /* private mode */ }
  }
  function has(d) { return read().some(function (x) { return x.domain === d; }); }

  function money(items) {
    // Display only, and only when every line carries a figure — a partial total would be a wrong total.
    var sum = 0;
    for (var i = 0; i < items.length; i++) {
      var m = /([0-9]+(?:\.[0-9]{2})?)/.exec(items[i].price || '');
      if (!m) { return null; }
      sum += parseFloat(m[1]);
    }
    return '$' + sum.toFixed(2);
  }

  function paintCart() {
    var items = read();
    if (!items.length) { cart.hidden = true; paintButtons(); return; }
    lines.innerHTML = items.map(function (x) {
      return '<div class="dcart-line"><span class="dsearch-name">' + esc(x.domain) + '</span>'
           + (x.price ? '<span class="dsearch-price">' + esc(x.price) + ' first year</span>' : '')
           + '<button type="button" class="dcart-x" data-x="' + esc(x.domain) + '" aria-label="Remove ' + esc(x.domain) + '">&times;</button></div>';
    }).join('');
    var t = money(items);
    total.textContent = items.length + (items.length === 1 ? ' domain' : ' domains') + (t ? ' · ' + t + ' first year' : '');
    cart.hidden = false;
    paintButtons();
  }

  function paintButtons() {
    [].forEach.call(document.querySelectorAll('[data-add]'), function (b) {
      var inCart = has(b.getAttribute('data-add'));
      b.classList.toggle('in', inCart);
      b.textContent = inCart ? 'In cart' : 'Add to cart';
    });
  }

  function addBtn(d) {
    return '<button type="button" class="dsearch-add" data-add="' + esc(d.domain) + '"'
         + ' data-price="' + esc(d.retail || '') + '">Add to cart</button>';
  }
  function price(d) {
    if (!d.retail) return '';
    return '<span class="dsearch-price">' + esc(d.retail) + ' first year'
         + (d.renewal ? ', then ' + esc(d.renewal) + ' a year' : '') + '</span>';
  }

  document.addEventListener('click', function (e) {
    var add = e.target.closest && e.target.closest('[data-add]');
    if (add) {
      var d = add.getAttribute('data-add');
      var items = read();
      if (has(d)) { items = items.filter(function (x) { return x.domain !== d; }); }
      else { items.push({ domain: d, years: 1, price: add.getAttribute('data-price') || null }); }
      write(items);
      paintCart();
      return;
    }
    var x = e.target.closest && e.target.closest('[data-x]');
    if (x) {
      var gone = x.getAttribute('data-x');
      write(read().filter(function (i) { return i.domain !== gone; }));
      paintCart();
    }
  });

  pay.addEventListener('click', function () {
    if (!read().length) return;
    // The cart is already in localStorage; signup is the next step and the app adopts it on the other side.
    window.location.href = signup;
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) return;
    var q = (input.value || '').trim();
    if (q.length < 2) { note('Type a name to search.'); return; }

    busy = true; go.disabled = true; go.textContent = 'Checking…';
    note(''); exact.hidden = true; recs.hidden = true;

    fetch('/api/public/domains/search?domain=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (r) {
        busy = false; go.disabled = false; go.textContent = 'Search';
        if (!r.ok) { note((r.j && r.j.error) || 'Could not search just now. Please try again.'); return; }

        var d = r.j.exact || {};
        var free = d.available === true;
        var unknown = d.available === null || d.available === undefined;

        exact.className = 'dsearch-exact' + (free ? '' : ' taken');
        exact.innerHTML =
          '<span class="dsearch-name">' + esc(d.domain || q) + '</span>'
          + (unknown ? '<span class="dsearch-tag no">Could not check</span>'
             : free   ? '<span class="dsearch-tag yes">Available</span>'
                      : '<span class="dsearch-tag no">Taken</span>')
          + price(d)
          + (free ? addBtn(d) : '')
          + (free || unknown ? '' : '<p class="dsearch-sub">That one has gone. These are free right now:</p>');
        exact.hidden = false;

        var rows = (r.j.recommendations || []).map(function (y) {
          return '<li><span class="dsearch-name">' + esc(y.domain) + '</span>'
               + (y.premium ? '<span class="dsearch-tag no">Premium</span>' : '')
               + price(y) + addBtn(y) + '</li>';
        }).join('');
        if (rows) { list.innerHTML = rows; recs.hidden = false; }

        paintCart();
      })
      .catch(function () {
        busy = false; go.disabled = false; go.textContent = 'Search';
        note('Could not reach the search just now. Please try again.');
      });
  });

  paintCart();
})();

/* ── TEMPLATE GALLERY (2026-09-11): an endless scroll-snap track with a 3D tilt on the neighbours of the centred
   card. Native scrolling does the dragging and the snapping. The last N cards are cloned before the first and the
   first N after the last, so the edges always have neighbours; when a scroll settles on a clone the track jumps
   silently to the real card. Opens on the featured design (data-featured). Nothing here is a link. ─────────── */
(function () {
  var root = document.querySelector('[data-tg-gallery]');
  if (!root) return;
  var track = root.querySelector('.tg-track');
  var real = Array.prototype.slice.call(root.querySelectorAll('.tg-card'));
  var count = root.querySelector('.tg-count');
  if (!track || real.length < 4) return;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var L = real.length, N = 3;

  // Clones for the loop. aria-hidden so the list reads once to assistive tech.
  function clone(c) { var k = c.cloneNode(true); k.dataset.clone = '1'; k.setAttribute('aria-hidden', 'true');
    var im = k.querySelector('img'); if (im) { im.loading = 'lazy'; im.removeAttribute('fetchpriority'); } return k; }
  real.slice(-N).forEach(function (c) { track.insertBefore(clone(c), real[0]); });
  real.slice(0, N).forEach(function (c) { track.appendChild(clone(c)); });
  var cards = Array.prototype.slice.call(track.querySelectorAll('.tg-card'));   // N clones + L real + N clones
  var pos = N, timer = null, paused = false, raf = null, settle = null, jumping = false;
  var realOf = function (p) { return ((p - N) % L + L) % L; };

  function centrePos() {
    var mid = track.scrollLeft + track.clientWidth / 2, best = 0, bd = Infinity;
    cards.forEach(function (c, i) { var d = Math.abs((c.offsetLeft + c.offsetWidth / 2) - mid); if (d < bd) { bd = d; best = i; } });
    return best;
  }
  function paint() {
    raf = null;
    var p = centrePos(), r = realOf(p);
    cards.forEach(function (c, i) {
      c.classList.remove('is-center', 'is-left', 'is-right', 'is-far', 'is-far-l', 'is-far-r');
      if (i === p) c.classList.add('is-center');
      else if (i === p - 1) c.classList.add('is-left');
      else if (i === p + 1) c.classList.add('is-right');
      else c.classList.add('is-far', i < p ? 'is-far-l' : 'is-far-r');
    });
    if (p !== pos) { pos = p; if (count) count.textContent = (r + 1) + ' / ' + L; }
  }
  function leftFor(p) { var c = cards[p]; return c.offsetLeft + c.offsetWidth / 2 - track.clientWidth / 2; }
  function jump(p) { jumping = true; var prev = track.style.scrollBehavior; track.style.scrollBehavior = 'auto';
    track.scrollLeft = leftFor(p); track.style.scrollBehavior = prev; pos = p; paint(); setTimeout(function () { jumping = false; }, 60); }
  function goTo(p, smooth) {
    if (smooth === false) return jump(p);
    track.scrollTo({ left: leftFor(p), behavior: reduced ? 'auto' : 'smooth' });
  }
  // When the scroll comes to rest on a clone, relocate to the real card at the same visual position.
  function settled() {
    settle = null;
    if (jumping) return;
    var p = centrePos();
    if (p < N) jump(p + L); else if (p >= N + L) jump(p - L);
  }
  function schedule() { if (!raf) raf = requestAnimationFrame(paint); if (settle) clearTimeout(settle); settle = setTimeout(settled, 140); }
  track.addEventListener('scroll', schedule, { passive: true });
  window.addEventListener('resize', function () { jump(pos); });

  // Auto-advance: only while visible, unhovered, unfocused, and motion is allowed.
  function tick() { if (!paused && !document.hidden) goTo(pos + 1); }
  function start() { if (reduced || timer) return; timer = setInterval(tick, 3600); }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  root.addEventListener('mouseenter', function () { paused = true; });
  root.addEventListener('mouseleave', function () { paused = false; });
  root.addEventListener('focusin', function () { paused = true; });
  root.addEventListener('focusout', function () { paused = false; });
  track.addEventListener('pointerdown', function () { paused = true; });
  track.addEventListener('pointerup', function () { setTimeout(function () { paused = root.matches(':hover'); }, 600); });
  document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) start(); else stop(); }); }, { threshold: .25 }).observe(root);
  } else { start(); }

  var prev = root.querySelector('.tg-prev'), next = root.querySelector('.tg-next');
  if (prev) prev.addEventListener('click', function () { goTo(pos - 1); });
  if (next) next.addEventListener('click', function () { goTo(pos + 1); });
  track.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight') { e.preventDefault(); goTo(pos + 1); }
    if (e.key === 'ArrowLeft')  { e.preventDefault(); goTo(pos - 1); }
  });

  // Open on the featured design (Technology / SaaS), else the first card.
  var featured = real.findIndex(function (c) { return c.hasAttribute('data-featured'); });
  jump(N + (featured >= 0 ? featured : 0));
  if (count) count.textContent = (realOf(pos) + 1) + ' / ' + L;
})();

/* SCROLL CUE (2026-09-11): one screen down, smoothly. */
(function () {
  var wire = function () {
    var cue = document.querySelector('[data-scroll-cue]');
    if (!cue) return;
    cue.addEventListener('click', function () {
      var nav = document.querySelector('header'); var off = nav ? nav.getBoundingClientRect().height : 64;
      window.scrollBy({ top: window.innerHeight - off, left: 0, behavior: 'smooth' });
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wire); else wire();
})();

/* ── LOGIN / FORGOT / RESET pages (2026-09-11) ─────────────────────────────────────────────────────────────── */
(function () {
  var wire = function () {
    var store = function (d) {
      try {
        localStorage.setItem('lu_token', d.access_token);
        if (d.refresh_token) localStorage.setItem('lu_refresh_token', d.refresh_token);
        if (d.user && d.user.id) localStorage.setItem('lu_user_id', String(d.user.id));
        if (d.current_workspace_id) localStorage.setItem('lu_workspace_id', String(d.current_workspace_id));
      } catch (e) {}
    };
    var nextUrl = function () {
      var n = new URLSearchParams(location.search).get('next') || '';
      return (/^\/(?!\/)/.test(n) && n.indexOf('\n') === -1) ? n : '/app/';
    };
    document.querySelectorAll('[data-pw-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        var i = b.parentNode.querySelector('input'); if (!i) return;
        var show = i.type === 'password'; i.type = show ? 'text' : 'password'; b.textContent = show ? 'Hide' : 'Show';
        b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      });
    });
    var post = function (url, body) {
      return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body), cache: 'no-store' })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, j: j }; }); });
    };
    var firstError = function (j, fallback) {
      if (j && j.errors) { for (var k in j.errors) { if (j.errors[k] && j.errors[k][0]) return j.errors[k][0]; } }
      return (j && (j.message || j.error)) || fallback;
    };

    var login = document.getElementById('login');
    if (login) {
      // Already signed in? There is nothing to do here.
      var sess = window.__luSession;
      var go = function (st) { if (st === 'in') location.replace(nextUrl()); };
      if (sess && sess.ready && sess.ready.then) sess.ready.then(go); else if (sess && sess.state === 'in') go('in');

      var err = document.getElementById('li-error'), ok = document.getElementById('li-success'), btn = document.getElementById('li-submit');
      var email = document.getElementById('li-email'), pass = document.getElementById('li-pass');
      var setMode = function (m) {
        login.setAttribute('data-mode', m); err.hidden = true; ok.hidden = true;
        btn.innerHTML = m === 'forgot' ? 'Send reset link' : btn.getAttribute('data-label-login');
        var f = login.querySelector('[data-forgot]'), b = login.querySelector('[data-back-to-login]');
        if (f) f.hidden = m === 'forgot'; if (b) b.hidden = m !== 'forgot';
        pass.required = m !== 'forgot';
        (m === 'forgot' ? email : (email.value ? pass : email)).focus();
      };
      btn.setAttribute('data-label-login', btn.innerHTML);
      login.querySelector('[data-forgot]').addEventListener('click', function (e) { e.preventDefault(); setMode('forgot'); });
      login.querySelector('[data-back-to-login]').addEventListener('click', function (e) { e.preventDefault(); setMode('login'); });
      login.addEventListener('submit', function (e) {
        e.preventDefault(); err.hidden = true;
        var mode = login.getAttribute('data-mode');
        if (!email.value.trim() || (mode === 'login' && !pass.value)) { err.textContent = 'Enter your email' + (mode === 'login' ? ' and password.' : '.'); err.hidden = false; return; }
        btn.disabled = true;
        if (mode === 'forgot') {
          post('/api/auth/forgot-password', { email: email.value.trim() }).then(function (r) {
            btn.disabled = false;
            if (!r.ok && r.status !== 200) { err.textContent = r.status === 429 ? 'Too many requests. Try again in a few minutes.' : firstError(r.j, 'Could not send the link. Please try again.'); err.hidden = false; return; }
            ok.hidden = false;
          }).catch(function () { btn.disabled = false; err.textContent = 'Could not reach the server. Please try again.'; err.hidden = false; });
          return;
        }
        post('/api/auth/login', { email: email.value.trim(), password: pass.value }).then(function (r) {
          if (!r.ok || !r.j.access_token) {
            btn.disabled = false;
            err.textContent = r.status === 401 || r.status === 422 ? 'That email and password do not match.' : (r.status === 429 ? 'Too many attempts. Try again in a few minutes.' : firstError(r.j, 'Could not sign you in. Please try again.'));
            err.hidden = false; return;
          }
          store(r.j);
          btn.innerHTML = 'Signed in — opening your workspace…';
          location.replace(nextUrl());
        }).catch(function () { btn.disabled = false; err.textContent = 'Could not reach the server. Please try again.'; err.hidden = false; });
      });
    }

    var reset = document.getElementById('reset');
    if (reset) {
      var q = new URLSearchParams(location.search); var token = q.get('token') || '';
      var rerr = document.getElementById('rp-error'), rok = document.getElementById('rp-success'), rbtn = document.getElementById('rp-submit');
      if (!token) { rerr.textContent = 'This link is missing its token. Open the link from your email again, or request a new one from the sign-in page.'; rerr.hidden = false; rbtn.disabled = true; }
      reset.addEventListener('submit', function (e) {
        e.preventDefault(); rerr.hidden = true;
        var p1 = document.getElementById('rp-pass').value, p2 = document.getElementById('rp-pass2').value;
        if (p1.length < 8) { rerr.textContent = 'Eight characters or more.'; rerr.hidden = false; return; }
        if (p1 !== p2) { rerr.textContent = 'The two passwords do not match.'; rerr.hidden = false; return; }
        rbtn.disabled = true;
        post('/api/auth/reset-password', { token: token, email: q.get('email') || '', password: p1, password_confirmation: p2 }).then(function (r) {
          if (!r.ok || (r.j && r.j.success === false)) { rbtn.disabled = false; rerr.textContent = firstError(r.j, 'That link is no longer valid. Request a new one from the sign-in page.'); rerr.hidden = false; return; }
          reset.querySelectorAll('.form-row, #rp-submit, .form-links').forEach(function (el) { el.hidden = true; });
          rok.hidden = false;
        }).catch(function () { rbtn.disabled = false; rerr.textContent = 'Could not reach the server. Please try again.'; rerr.hidden = false; });
      });
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wire); else wire();
})();

/* NO ZOOM (Owner, 2026-09-12): pinch-zoom is off via the viewport meta; on desktop the keyboard and ctrl+wheel zoom
   gestures are swallowed. Best effort — browser menu zoom cannot be blocked by a page. */
(function () {
  window.addEventListener('wheel', function (e) { if (e.ctrlKey || e.metaKey) e.preventDefault(); }, { passive: false });
  window.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === '+' || e.key === '-' || e.key === '=' || e.key === '0' || e.code === 'NumpadAdd' || e.code === 'NumpadSubtract')) e.preventDefault();
  });
  document.addEventListener('gesturestart', function (e) { e.preventDefault(); }, { passive: false });
})();

/* STALE PAGE GUARD (2026-09-12): the page carries the build id of the site.js it was built with; if this site.js is a
   different build, the HTML came from a cache and the pair may not match — reload once from the server. */
(function () {
  try {
    var me = document.currentScript || Array.prototype.slice.call(document.scripts).filter(function (s) { return /site\.js\?v=/.test(s.src); }).pop();
    var mine = me && (me.src.match(/site\.js\?v=([a-f0-9]{8})/) || [])[1];
    var page = document.documentElement.getAttribute('data-build');
    if (mine && page && mine !== page && !sessionStorage.getItem('lu_reloaded_' + mine)) {
      sessionStorage.setItem('lu_reloaded_' + mine, '1');
      location.reload();
    }
  } catch (e) {}
})();
