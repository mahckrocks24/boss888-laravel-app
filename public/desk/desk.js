/* PUBLISHER888 — desk.js: the Publisher Desk app (vanilla, hash-routed). Talks to /api/desk/* on the site's own host. */
(function () {
  'use strict';
  var D = window.DESK || {}; var esc = dui.esc, el = dui.el;
  var K = { t: 'desk_token_' + D.website_id, r: 'desk_refresh_' + D.website_id };
  var S = { ctx: null, token: null, refresh: null, route: null, dirtyGuard: null, pollTimer: null };
  var root = document.getElementById('desk');
  try { S.token = localStorage.getItem(K.t); S.refresh = localStorage.getItem(K.r); } catch (e) {}

  /* ---------- api ---------- */
  var refreshing = null;
  function api(path, method, body, opts) {
    opts = opts || {};
    var h = { 'Accept': 'application/json', 'X-Desk-Website': String(D.website_id) };
    if (S.token) h['Authorization'] = 'Bearer ' + S.token;
    var init = { method: method || 'GET', headers: h };
    if (body instanceof FormData) init.body = body; else if (body != null) { h['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
    return fetch(D.api + path.replace(/^\//, ''), init).then(function (r) {
      if (r.status === 401 && !opts._retried && S.refresh && !/^auth\//.test(path)) {
        return doRefresh().then(function (ok) { if (!ok) { logout('Session expired. Sign in again.'); throw new Error('unauthenticated'); } return api(path, method, body, { _retried: true }); });
      }
      if (r.status === 429) { dui.toast('Slow down — too many requests. Try again in a minute.', 'warning'); }
      return r.json().catch(function () { return { success: false, error: 'BAD_JSON', message: 'Unexpected response (' + r.status + ')' }; }).then(function (j) { j.__status = r.status; if (r.status >= 500 && j.request_id) j.message = (j.message || 'Server error') + ' (ref ' + j.request_id.slice(0, 8) + ')'; return j; });
    });
  }
  /* Unit 2 — idempotency keys for creates (safe retries on flaky networks) */
  function idem(prefix) { return prefix + '-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10); }
  function apiCreate(path, body, key) { var h = { 'Accept': 'application/json', 'X-Desk-Website': String(D.website_id), 'Content-Type': 'application/json', 'Idempotency-Key': key }; if (S.token) h['Authorization'] = 'Bearer ' + S.token; return fetch(D.api + path, { method: 'POST', headers: h, body: JSON.stringify(body) }).then(function (r) { return r.json().catch(function () { return { success: false, error: 'BAD_JSON' }; }).then(function (j) { j.__status = r.status; return j; }); }); }
  /* Unit 2 — idle sign-out (default 30 min; configurable in More) */
  var idleMs = 30 * 60000; try { var im = parseInt(localStorage.getItem('desk_idle_min') || '30', 10); idleMs = im > 0 ? im * 60000 : 0; } catch (e) {}
  var idleTimer = null; function touchIdle() { if (!idleMs) return; clearTimeout(idleTimer); idleTimer = setTimeout(function () { if (S.token) { api('auth/logout', 'POST', { refresh_token: S.refresh }).catch(function () {}); logout('Signed out after ' + Math.round(idleMs / 60000) + ' minutes of inactivity.'); } }, idleMs); }
  ['click', 'keydown', 'touchstart', 'mousemove', 'scroll'].forEach(function (ev) { document.addEventListener(ev, dui.debounce(touchIdle, 1000), { passive: true }); }); touchIdle();
  function doRefresh() {
    if (refreshing) return refreshing;
    refreshing = fetch(D.api + 'auth/refresh', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ refresh_token: S.refresh }) })
      .then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { if (j && j.access_token) { setTokens(j.access_token, j.refresh_token || S.refresh); return true; } return false; }).catch(function () { return false; })
      .finally(function () { refreshing = null; });
    return refreshing;
  }
  function setTokens(t, r) { S.token = t; S.refresh = r; try { localStorage.setItem(K.t, t); if (r) localStorage.setItem(K.r, r); } catch (e) {} }
  function logout(msg) { S.token = null; S.refresh = null; S.ctx = null; try { localStorage.removeItem(K.t); localStorage.removeItem(K.r); } catch (e) {} renderLogin(msg); }
  function fail(j, fallback) { dui.toast((j && (j.message || j.error)) || fallback || 'Something went wrong', 'error'); }

  /* ---------- icons ---------- */
  var I = {
    home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 11 12 4l9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>',
    stories: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
    spark: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/></svg>',
    sections: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="8" height="7" rx="1.5"/><rect x="13" y="4" width="8" height="7" rx="1.5"/><rect x="3" y="13" width="8" height="7" rx="1.5"/><rect x="13" y="13" width="8" height="7" rx="1.5"/></svg>',
    jobs: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"/></svg>',
    inbox: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 13h5l2 3h4l2-3h5"/><path d="M5 5h14l2 8v6H3v-6z"/></svg>',
    members: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-5-6.3"/></svg>',
    more: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>',
    back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 5-7 7 7 7"/></svg>',
    ext: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
    plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>'
  };

  /* ---------- login ---------- */
  function renderLogin(msg) {
    root.dataset.state = 'login';
    root.innerHTML = '<div class="desk-login"><form class="desk-login-card" id="desk-login" novalidate>' +
      '<div class="desk-plate"><div class="desk-mark">' + esc((D.site_name || 'D').charAt(0)) + '</div><div><b>' + esc(D.site_name) + '</b><small>Publisher Desk</small></div></div>' +
      '<h1 style="font-size:20px;margin-bottom:14px">Sign in</h1>' +
      '<div class="desk-err' + (msg ? '' : ' hide') + '" id="desk-login-err">' + esc(msg || '') + '</div>' +
      '<div class="dui-field"><label class="dui-label" for="dl-email">Email</label><input class="dui-input" id="dl-email" type="email" autocomplete="username" inputmode="email" required></div>' +
      '<div class="dui-field"><label class="dui-label" for="dl-pass">Password</label><input class="dui-input" id="dl-pass" type="password" autocomplete="current-password" required></div>' +
      '<button class="dui-btn dui-btn--primary" type="submit" style="width:100%;min-height:46px">Sign in</button>' +
      '<p class="dui-help" style="margin-top:14px">Same account as LevelUp Growth. Access is limited to members of the ' + esc(D.site_name) + ' workspace.</p></form></div>';
    var f = document.getElementById('desk-login'), err = document.getElementById('desk-login-err');
    f.onsubmit = function (e) {
      e.preventDefault(); var b = f.querySelector('button'); b.disabled = true; err.classList.add('hide');
      var email = f.querySelector('#dl-email').value.trim(), pass = f.querySelector('#dl-pass').value;
      fetch(D.api + 'auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ email: email, password: pass }) })
        .then(function (r) { return r.json().then(function (j) { j.__status = r.status; return j; }); })
        .then(function (j) {
          if (!j.access_token) throw new Error(j.message || j.error || (j.__status === 429 ? 'Too many attempts. Wait a few minutes.' : 'Wrong email or password.'));
          setTokens(j.access_token, j.refresh_token);
          if (Number(j.current_workspace_id) === Number(D.workspace_id)) return j;
          var member = (j.workspaces || []).some(function (w) { return Number(w.id) === Number(D.workspace_id); });
          if (!member) throw new Error('This account is not a member of the ' + D.site_name + ' workspace.');
          return api('auth/switch-workspace', 'POST', { workspace_id: D.workspace_id }).then(function (s) { if (!s.access_token) throw new Error(s.message || 'Could not open the workspace.'); setTokens(s.access_token, s.refresh_token || S.refresh); return s; });
        })
        .then(function () { return boot(); })
        .catch(function (e) { S.token = null; err.textContent = e.message || 'Sign-in failed.'; err.classList.remove('hide'); b.disabled = false; });
    };
  }

  /* ---------- shell ---------- */
  var NAV = [
    { r: '', label: 'Dashboard', ic: 'home' }, { group: 'Newsroom' }, { r: 'stories', label: 'Stories', ic: 'stories', ab: 'stories.read' }, { r: 'commission', label: 'Commission Sarah', ic: 'spark', ab: 'commission', badge: 'commissions_open' }, { r: 'sections', label: 'Sections', ic: 'sections', ab: 'stories.read' },
    { group: 'Site' }, { r: 'jobs', label: 'Jobs', ic: 'jobs', ab: 'jobs.read', badge: 'jobs_draft' }, { r: 'inbox', label: 'Inbox', ic: 'inbox', ab: 'inbox.read', badge: 'inbox_new' }, { group: 'Desk' }, { r: 'members', label: 'Members', ic: 'members', ab: 'members.read' }, { r: 'activity', label: 'Activity', ic: 'stories', ab: 'audit.read' }, { r: 'settings', label: 'Settings', ic: 'more' }
  ];
  function can(ab) { return !ab || (S.ctx && S.ctx.abilities.indexOf(ab) >= 0); }
  function badge(k) { var c = S.ctx && S.ctx.counts; if (!c) return 0; if (k === 'inbox_new') return c.inbox_new.total; if (k === 'jobs_draft') return c.jobs.draft; if (k === 'commissions_open') return c.commissions_open; return 0; }
  function renderShell() {
    root.dataset.state = 'app';
    var u = S.ctx.user, ini = (u.name || u.email || '?').split(/\s+/).map(function (p) { return p.charAt(0); }).join('').slice(0, 2).toUpperCase();
    root.innerHTML = '<div class="desk-app"><aside class="desk-side">' +
      '<div class="desk-plate"><div class="desk-mark">' + esc((D.site_name || 'D').charAt(0)) + '</div><div><b>' + esc(D.site_name) + '</b><small>Publisher Desk</small></div></div>' +
      '<nav class="desk-nav" id="desk-nav"></nav>' +
      '<div class="desk-side-foot"><a class="dui-btn dui-btn--quiet" style="justify-content:flex-start" href="' + esc(D.origin) + '" target="_blank" rel="noopener">' + I.ext + ' View site</a>' +
      '<div class="desk-user"><div class="desk-av">' + esc(ini) + '</div><div><b>' + esc(u.name || u.email) + '</b><small>' + esc(S.ctx.role) + '</small></div></div>' +
      '<button class="dui-btn dui-btn--quiet dui-btn--sm" type="button" id="desk-logout" style="justify-content:flex-start">Sign out</button></div></aside>' +
      '<div class="desk-main"><header class="desk-top"><button class="dui-btn dui-btn--quiet dui-btn--icon desk-back" type="button" id="desk-back" aria-label="Back">' + I.back + '</button><h1 id="desk-title">Dashboard</h1><div id="desk-top-actions" class="desk-inline"></div></header>' +
      '<main class="desk-content" id="desk-main" tabindex="-1"></main></div></div><nav class="desk-bnav" id="desk-bnav" aria-label="Desk"></nav><div class="desk-progress" id="desk-progress"></div>';
    document.getElementById('desk-logout').onclick = function () { api('auth/logout', 'POST', { refresh_token: S.refresh }).catch(function () {}); logout(); };
    document.getElementById('desk-back').onclick = function () { history.length > 1 ? history.back() : go(''); };
    renderNav();
  }
  function renderNav() {
    var nav = document.getElementById('desk-nav'), bn = document.getElementById('desk-bnav'); if (!nav) return;
    var cur = (S.route && S.route.name) || '';
    nav.innerHTML = NAV.map(function (n) { if (n.group) return '<div class="desk-nav-group">' + esc(n.group) + '</div>'; if (!can(n.ab)) return ''; var b = n.badge ? badge(n.badge) : 0; return '<a href="#/' + n.r + '" class="' + (cur === n.r ? 'is-on' : '') + '">' + I[n.ic] + '<span>' + esc(n.label) + '</span>' + (b ? '<em>' + b + '</em>' : '') + '</a>'; }).join('');
    var bottom = [{ r: '', label: 'Home', ic: 'home' }, { r: 'stories', label: 'Stories', ic: 'stories', ab: 'stories.read' }, { r: 'jobs', label: 'Jobs', ic: 'jobs', ab: 'jobs.read', badge: 'jobs_draft' }, { r: 'inbox', label: 'Inbox', ic: 'inbox', ab: 'inbox.read', badge: 'inbox_new' }, { r: 'settings', label: 'More', ic: 'more' }];
    bn.innerHTML = bottom.filter(function (n) { return can(n.ab); }).map(function (n) { var b = n.badge ? badge(n.badge) : 0; var on = cur === n.r || (n.r === 'settings' && ['members', 'sections', 'commission'].indexOf(cur) >= 0); return '<a href="#/' + n.r + '" class="' + (on ? 'is-on' : '') + '">' + I[n.ic] + '<span>' + esc(n.label) + '</span>' + (b ? '<em>' + b + '</em>' : '') + '</a>'; }).join('');
  }
  function setTitle(t, actions) { var h = document.getElementById('desk-title'); if (h) h.textContent = t; document.title = t + ' — ' + D.site_name + ' Desk'; var a = document.getElementById('desk-top-actions'); if (a) { a.innerHTML = ''; (actions || []).forEach(function (x) { a.appendChild(x); }); } }
  function btn(label, cls, fn, opts) { var b = el('button', 'dui-btn ' + (cls || ''), label); b.type = 'button'; b.onclick = fn; if (opts && opts.title) b.title = opts.title; return b; }
  function main() { return document.getElementById('desk-main'); }
  function progress(on) { var p = document.getElementById('desk-progress'); if (!p) return; if (on) { p.classList.add('is-on'); p.style.width = '70%'; } else { p.style.width = '100%'; setTimeout(function () { p.classList.remove('is-on'); p.style.width = '0'; }, 200); } }
  function refreshCounts() { return api('desk/context').then(function (j) { if (j.success) { S.ctx = j; renderNav(); } }); }

  /* ---------- router ---------- */
  function go(hash) { location.hash = '#/' + hash; }
  function parse() { var h = location.hash.replace(/^#\/?/, ''); var q = {}; var qi = h.indexOf('?'); if (qi >= 0) { h.slice(qi + 1).split('&').forEach(function (p) { var kv = p.split('='); q[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || ''); }); h = h.slice(0, qi); } var parts = h.split('/').filter(Boolean); return { name: parts[0] || '', id: parts[1] || null, sub: parts[2] || null, q: q }; }
  var VIEWS = {};
  var lastHash = location.hash;
  function route() {
    /* Unit 2 — unsaved-changes guard on in-app navigation (beforeunload only covers tab close) */
    if (S.dirtyGuard && S.dirtyGuard() && location.hash !== lastHash && !S.__leaving) {
      var target = location.hash; history.replaceState(null, '', lastHash);
      dui.confirm('Leave without saving?', 'You have unsaved changes on this page.', { okLabel: 'Leave', danger: true }).then(function (ok) { if (ok) { S.dirtyGuard = null; S.__leaving = true; location.hash = target; } });
      return;
    }
    S.__leaving = false; lastHash = location.hash; S.dirtyGuard = null;
    var r = parse(); S.route = r; renderNav(); window.scrollTo(0, 0);
    var v = VIEWS[r.name] || VIEWS['']; var m = main(); if (!m) return; m.innerHTML = '';
    try { v(r); } catch (e) { console.error(e); m.innerHTML = '<div class="desk-empty"><b>Could not render this page</b>' + esc(e.message) + '</div>'; }
  }
  window.addEventListener('hashchange', route);

  /* ---------- helpers ---------- */
  function pill(s, label) { return '<span class="pill pill--' + esc(s) + '">' + esc(label || String(s || '').replace('_', ' ')) + '</span>'; }
  function sectionOpts(withAll) { var o = (S.ctx.sections || []).map(function (s) { return { value: s.slug, label: s.name, hint: s.stories + ' stories' }; }); if (withAll) o.unshift({ value: '', label: withAll }); return o; }
  /* QATAR-1 — editions (site regions) */
  function multiEdition() { return (S.ctx.regions || []).length > 1; }
  function regionOpts(withAll) { var o = (S.ctx.regions || []).map(function (r) { return { value: r.code, label: r.short || r.code, hint: r.name }; }); if (withAll) o.unshift({ value: '', label: withAll }); return o; }
  function regionLabel(code) { if (!code || code === 'ALL') return multiEdition() ? 'All editions' : ''; var r = (S.ctx.regions || []).filter(function (x) { return x.code === code; })[0]; return r ? r.short : code; }
  function empty(title, body, action) { var d = el('div', 'desk-empty', '<b>' + esc(title) + '</b>' + esc(body || '')); if (action) { d.appendChild(document.createElement('br')); action.style.marginTop = '12px'; d.appendChild(action); } return d; }
  function field(label, inner, help) { return '<div class="dui-field"><label class="dui-label">' + esc(label) + '</label>' + inner + (help ? '<div class="dui-help">' + esc(help) + '</div>' : '') + '</div>'; }
  function inp(name, val, ph, type) { return '<input class="dui-input" name="' + name + '" type="' + (type || 'text') + '" value="' + esc(val == null ? '' : val) + '" placeholder="' + esc(ph || '') + '">'; }
  function ta(name, val, ph, rows) { return '<textarea class="dui-textarea" name="' + name + '" placeholder="' + esc(ph || '') + '"' + (rows ? ' style="min-height:' + rows * 24 + 'px"' : '') + '>' + esc(val == null ? '' : val) + '</textarea>'; }
  function vals(form) { var o = {}; form.querySelectorAll('input[name],textarea[name]').forEach(function (i) { o[i.name] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value; }); form.querySelectorAll('.dui-lb[data-name]').forEach(function (b) { o[b.dataset.name] = b.__val; }); return o; }
  function lb(host, o) { var a = dui.listbox(host, o); host.querySelector('.dui-lb').__val = a.get(); var oc = o.onChange; a.el.__api = a; var orig = a.set; return a; }
  function uploadImage() {
    return new Promise(function (resolve) {
      var i = document.createElement('input'); i.type = 'file'; i.accept = 'image/*';
      i.onchange = function () { var f = i.files[0]; if (!f) return resolve(null); var fd = new FormData(); fd.append('file', f); fd.append('kind', 'image'); dui.toast('Uploading ' + f.name + '…'); api('media/upload', 'POST', fd).then(function (j) { var u = j.url || (j.media && (j.media.original_url || j.media.preview_url)); if (!u) { fail(j, 'Upload failed'); return resolve(null); } resolve(u); }); };
      i.click();
    });
  }
  function pickImage() { return dui.dialog({ title: 'Insert image', body: '<div class="dui-field"><label class="dui-label">Image URL</label><input class="dui-input" name="u" placeholder="https://…"></div><button type="button" class="dui-btn" id="pi-up">Upload from device</button>', okLabel: 'Insert', onOpen: function (box, close) { box.querySelector('#pi-up').onclick = function () { uploadImage().then(function (u) { if (u) close(u); }); }; }, onOk: function (box) { var u = box.querySelector('input').value.trim(); return u || false; } }).then(function (v) { return v || null; }); }

  /* ---------- dashboard ---------- */
  VIEWS[''] = function () {
    setTitle('Dashboard', [can('stories.write') ? btn('New story', 'dui-btn--primary', function () { go('stories/new'); }) : null].filter(Boolean));
    var m = main(); var c = S.ctx.counts;
    m.innerHTML = '<div class="desk-stats">' +
      '<a class="desk-stat" href="#/stories?status=published"><b>' + c.stories.published + '</b><span>Published stories</span></a>' +
      '<a class="desk-stat" href="#/stories?status=draft"><b>' + c.stories.draft + '</b><span>Drafts</span></a>' +
      '<a class="desk-stat' + (c.stories.scheduled ? ' is-warn' : '') + '" href="#/stories?status=scheduled"><b>' + c.stories.scheduled + '</b><span>Scheduled</span></a>' +
      (can('commission') ? '<a class="desk-stat' + (c.commissions_open ? ' is-warn' : '') + '" href="#/commission"><b>' + c.commissions_open + '</b><span>Sarah writing now</span></a>' : '') +
      (can('jobs.read') ? '<a class="desk-stat" href="#/jobs?status=published"><b>' + c.jobs.published + '</b><span>Live jobs' + (c.jobs.expiring_7d ? ' · ' + c.jobs.expiring_7d + ' expiring' : '') + '</span></a>' : '') +
      (can('inbox.read') ? '<a class="desk-stat' + (c.inbox_new.total ? ' is-new' : '') + '" href="#/inbox?status=new"><b>' + c.inbox_new.total + '</b><span>New in inbox</span></a>' : '') + '</div>';
    var quick = el('div', 'desk-quick');
    if (can('commission')) quick.appendChild(btn('Commission Sarah', 'dui-btn--accent', function () { go('commission'); }));
    if (can('jobs.write')) quick.appendChild(btn('Post a job', '', function () { go('jobs/new'); }));
    if (can('sections.write')) quick.appendChild(btn('Sections', '', function () { go('sections'); }));
    quick.appendChild(btn('Open site', '', function () { window.open(D.origin, '_blank', 'noopener'); }));
    m.appendChild(quick);
    var g = el('div', 'desk-grid2'); m.appendChild(g);
    var recent = el('div', 'desk-card', '<h3>Recent stories <a href="#/stories">All stories</a></h3><div class="desk-list" id="dash-recent"><div class="muted small">Loading…</div></div>'); g.appendChild(recent);
    var side = el('div', 'desk-card', '<h3>Sections</h3><div class="desk-list" id="dash-sections"></div>'); g.appendChild(side);
    api('desk/stories?limit=6').then(function (j) { var h = document.getElementById('dash-recent'); if (!h) return; h.innerHTML = j.success && j.stories.length ? j.stories.map(storyItem).join('') : ''; if (!(j.stories || []).length) { h.innerHTML = ''; h.appendChild(empty('No stories yet', 'Write one yourself or commission Sarah.')); } });
    var sh = document.getElementById('dash-sections');
    sh.innerHTML = (S.ctx.sections || []).map(function (s) { return '<a class="desk-item" href="#/stories?section=' + esc(s.slug) + '" style="min-height:48px;padding:8px 12px"><span class="pill pill--section">' + esc(s.slug) + '</span><div class="desk-item-b"><b>' + esc(s.name) + '</b></div><div class="desk-item-r"><span>' + s.published + ' live · ' + (s.stories - s.published) + ' unpublished</span></div></a>'; }).join('') || '';
    if (!(S.ctx.sections || []).length) sh.appendChild(empty('No sections', 'Create your first section.'));
  };

  /* ---------- stories ---------- */
  function storyItem(s) {
    return '<a class="desk-item" href="#/stories/' + s.id + '">' + (s.featured_image_url ? '<img class="desk-thumb" src="' + esc(s.featured_image_url) + '" alt="" loading="lazy">' : '<div class="desk-thumb">no image</div>') +
      '<div class="desk-item-b"><b>' + esc(s.title || '(untitled)') + '</b><small>' + (multiEdition() ? esc(regionLabel(s.region)) + ' · ' : '') + esc(s.section_name || 'No section') + ' · ' + esc(s.author || '') + (s.word_count ? ' · ' + s.word_count + ' words' : '') + '</small></div>' +
      '<div class="desk-item-r">' + pill(s.status) + '<span>' + (s.status === 'scheduled' && s.scheduled_at ? 'goes live ' + dui.fmt(s.scheduled_at) : dui.rel(s.updated_at)) + '</span></div></a>';
  }
  VIEWS.stories = function (r) {
    if (r.id === 'new') return VIEWS.storyEditor(null);
    if (r.id) return VIEWS.storyEditor(Number(r.id));
    setTitle('Stories', [can('stories.write') ? btn('New story', 'dui-btn--primary', function () { go('stories/new'); }) : null].filter(Boolean));
    var m = main(); var f = { status: r.q.status || 'all', section: r.q.section || '', region: r.q.region || '', q: r.q.q || '', offset: 0 };
    var chipsHost = el('div'); var tb = el('div', 'desk-toolbar'); var lbHost = el('div', '', ''); lbHost.style.minWidth = '200px'; var search = el('input', 'dui-input'); search.placeholder = 'Search titles…'; search.value = f.q; search.type = 'search';
    tb.appendChild(lbHost); var regHost = null; if (multiEdition()) { regHost = el('div'); regHost.style.minWidth = '150px'; tb.appendChild(regHost); } tb.appendChild(search); m.appendChild(chipsHost); m.appendChild(tb);
    if (regHost) dui.listbox(regHost, { options: [{ value: '', label: 'Every edition' }, { value: 'ALL', label: 'Marked "all editions"' }].concat(regionOpts()), value: f.region, placeholder: 'Edition', onChange: function (v) { f.region = v; f.offset = 0; nav(); load(true); } });
    var list = el('div', 'desk-list'); m.appendChild(list); var more = el('div', 'desk-more'); m.appendChild(more);
    var c = S.ctx.counts.stories;
    function nav() { var q = []; if (f.status !== 'all') q.push('status=' + f.status); if (f.section) q.push('section=' + encodeURIComponent(f.section)); if (f.region) q.push('region=' + encodeURIComponent(f.region)); if (f.q) q.push('q=' + encodeURIComponent(f.q)); history.replaceState(null, '', '#/stories' + (q.length ? '?' + q.join('&') : '')); }
    var chipItems = [{ value: 'all', label: 'All' }, { value: 'draft', label: 'Drafts', count: c.draft }, { value: 'scheduled', label: 'Scheduled', count: c.scheduled }, { value: 'published', label: 'Published', count: c.published }, { value: 'trash', label: 'Bin' }];
    function chipRender() { dui.chips(chipsHost, chipItems, f.status, function (v) { f.status = v; f.offset = 0; nav(); chipRender(); load(true); }); } chipRender();
    dui.listbox(lbHost, { options: sectionOpts('All sections'), value: f.section, placeholder: 'Section', onChange: function (v) { f.section = v; f.offset = 0; nav(); load(true); } });
    search.oninput = dui.debounce(function () { f.q = search.value.trim(); f.offset = 0; nav(); load(true); }, 350);
    function load(reset) {
      if (reset) { list.innerHTML = '<div class="muted small">Loading…</div>'; more.innerHTML = ''; }
      progress(true);
      api('desk/stories?status=' + f.status + '&section=' + encodeURIComponent(f.section) + '&region=' + encodeURIComponent(f.region) + '&q=' + encodeURIComponent(f.q) + '&offset=' + f.offset + '&limit=30').then(function (j) {
        progress(false); if (!j.success) return fail(j);
        if (reset) list.innerHTML = '';
        if (!j.stories.length && reset) { list.appendChild(empty(f.q || f.section || f.status !== 'all' ? 'Nothing matches' : 'No stories yet', f.q ? 'Try another search.' : 'Write one or commission Sarah.', can('stories.write') ? btn('New story', 'dui-btn--primary', function () { go('stories/new'); }) : null)); return; }
        if (f.status === 'trash') {
          j.stories.forEach(function (s) { var row = el('div', 'desk-item', '<div class="desk-thumb">bin</div><div class="desk-item-b"><b>' + esc(s.title || '(untitled)') + '</b><small>Deleted ' + esc(dui.rel(s.deleted_at)) + ' · ' + esc(s.section_name || 'No section') + '</small></div><div class="desk-item-r"></div>'); if (can('stories.write')) row.lastElementChild.appendChild(btn('Restore', 'dui-btn--sm', function () { api('desk/stories/' + s.id + '/restore', 'POST').then(function (r) { if (!r.success) return fail(r); dui.toast('Restored as a draft', 'success'); refreshCounts(); load(true); }); })); list.appendChild(row); });
        } else list.insertAdjacentHTML('beforeend', j.stories.map(storyItem).join(''));
        more.innerHTML = ''; if (j.has_more) more.appendChild(btn('Load more', '', function () { f.offset += j.limit; load(false); }));
      });
    }
    load(true);
  };

  VIEWS.storyEditor = function (id) {
    var m = main(); var isNew = !id; var story = null; var dirty = false; var ed;
    setTitle(isNew ? 'New story' : 'Story');
    m.innerHTML = '<div class="muted small">Loading…</div>';
    function markDirty() { dirty = true; var d = document.getElementById('st-dirty'); if (d) d.textContent = 'Unsaved changes'; }
    function render() {
      var s = story || { status: 'draft', type: 'article', section: '', author: S.ctx.site.name + ' Desk', sources: [], tags: [] };
      var canPub = can('stories.publish'), canW = can('stories.write');
      m.innerHTML = '<div class="desk-editor"><div class="desk-editor-main">' +
        '<textarea class="dui-input dui-input--title" id="st-title" rows="1" placeholder="Headline"' + (canW ? '' : ' readonly') + '>' + esc(s.title || '') + '</textarea>' +
        '<div class="dui-row" style="margin:6px 0 14px"><div>' + field('Section', '<div id="st-section"></div>') + '</div>' + (multiEdition() ? '<div>' + field('Edition', '<div id="st-region"></div>') + '</div>' : '') + '<div>' + field('Type', '<div id="st-type"></div>') + '</div><div>' + field('Byline', inp('author', s.author, 'Author name')) + '</div></div>' +
        '<div class="dui-field"><label class="dui-label">Story</label><div id="st-body"></div><div class="dui-help"><span id="st-words">0</span> words · at least 80 to publish</div></div>' +
        field('Excerpt', ta('excerpt', s.excerpt, 'One or two sentences shown in lists and search (auto-filled from the story if empty).', 3)) +
        '<div class="desk-card" style="margin-bottom:14px"><h3>Sources</h3><div class="desk-srcs" id="st-sources"></div><button type="button" class="dui-btn dui-btn--sm" id="st-addsrc" style="margin-top:8px">Add source</button></div>' +
        '<div class="desk-card"><h3>Search &amp; sharing</h3>' + field('Meta title', inp('meta_title', s.meta_title, s.title || 'Defaults to the headline'), 'Up to 60 characters.') + field('Meta description', ta('meta_description', s.meta_description, 'Defaults to the excerpt.', 2)) + field('Tags', inp('tags', (s.tags || []).join(', '), 'owwa, dubai, guide')) + '</div></div>' +
        '<aside class="desk-editor-side">' +
        '<div class="desk-card"><h3>Status ' + pill(s.status) + '</h3>' + (s.status === 'published' && s.url ? '<p class="small" style="margin:0 0 10px"><a href="' + esc(s.url) + '" target="_blank" rel="noopener">View live ↗</a></p>' : '') + (s.status === 'scheduled' ? '<p class="small">Goes live ' + esc(dui.fmt(s.scheduled_at)) + '</p>' : '') +
        '<div class="desk-status" id="st-actions"></div><div class="desk-dirty" id="st-dirty" style="margin-top:8px"></div></div>' +
        '<div class="desk-card"><h3>Featured image</h3>' + (s.featured_image_url ? '<img class="desk-imgprev" id="st-imgprev" src="' + esc(s.featured_image_url) + '" alt="">' : '<div class="desk-imgprev" id="st-imgprev"></div>') +
        '<div class="desk-inline" style="margin-bottom:10px"><button type="button" class="dui-btn dui-btn--sm" id="st-upimg">Upload</button><button type="button" class="dui-btn dui-btn--sm" id="st-urlimg">Use URL</button>' + (isNew ? '' : '<button type="button" class="dui-btn dui-btn--sm" id="st-genimg" title="1 credit">Generate with AI</button>') + (s.featured_image_url ? '<button type="button" class="dui-btn dui-btn--sm dui-btn--quiet" id="st-rmimg">Remove</button>' : '') + '</div>' +
        '<input type="hidden" name="featured_image_url" value="' + esc(s.featured_image_url || '') + '">' + field('Alt text', inp('featured_image_alt', s.featured_image_alt, 'Describe the image')) + field('Caption', inp('image_caption', s.image_caption, '')) + field('Credit', inp('image_credit', s.image_credit, 'Photo: …')) + '</div>' +
        (isNew ? '' : '<div class="desk-card small muted">Created ' + esc(dui.fmt(s.created_at)) + '<br>Updated ' + esc(dui.rel(s.updated_at)) + (s.published_at ? '<br>Published ' + esc(dui.fmt(s.published_at)) : '') + '<br>Slug <span class="mono">' + esc(s.slug || '—') + '</span></div>') +
        (isNew ? '' : '<div class="desk-card"><h3>History <button type="button" class="dui-btn dui-btn--sm dui-btn--quiet" id="st-hist">Show</button></h3><div id="st-versions" class="small muted">Every save keeps a version. Restore any of them.</div></div>') +
        '</aside></div>';
      var hb = document.getElementById('st-hist'); if (hb) hb.onclick = function () { hb.disabled = true; api('desk/stories/' + id + '/versions').then(function (j) { hb.disabled = false; var h = document.getElementById('st-versions'); if (!j.success) return fail(j); if (!j.versions.length) { h.textContent = 'No earlier versions yet.'; return; } h.innerHTML = ''; j.versions.forEach(function (v) { var row = el('div', 'desk-inline', '<span>v' + v.version + ' · ' + esc(dui.fmt(v.created_at)) + ' · ' + v.words + ' words' + (v.by ? ' · ' + esc(v.by) : '') + '</span>'); row.style.justifyContent = 'space-between'; row.style.marginBottom = '6px'; if (can('stories.write')) row.appendChild(btn('Restore', 'dui-btn--sm', function () { dui.confirm('Restore version ' + v.version + '?', 'The current text becomes a new version first, so nothing is lost.', { okLabel: 'Restore' }).then(function (ok) { if (!ok) return; api('desk/stories/' + id + '/versions/' + v.id + '/restore', 'POST').then(function (r) { if (!r.success) return fail(r); story = r.story; dirty = false; clearAutosave(); dui.toast('Version restored', 'success'); render(); }); }); })); h.appendChild(row); }); }); };
      var secApi = dui.listbox(document.getElementById('st-section'), { options: sectionOpts('No section'), value: s.section || '', placeholder: 'Section', onChange: markDirty });
      var typeApi = dui.listbox(document.getElementById('st-type'), { options: S.ctx.enums.story_types.map(function (t) { return { value: t, label: t.charAt(0).toUpperCase() + t.slice(1) }; }), value: s.type || 'article', onChange: markDirty });
      var regionApi = multiEdition() ? dui.listbox(document.getElementById('st-region'), { options: regionOpts('All editions'), value: s.region && s.region !== 'ALL' ? s.region : '', onChange: markDirty }) : null;
      ed = dui.editor(document.getElementById('st-body'), s.content || '', { pickImage: pickImage, onChange: function () { markDirty(); document.getElementById('st-words').textContent = ed.words(); } });
      document.getElementById('st-words').textContent = ed.words();
      if (!canW) ed.el.contentEditable = 'false';
      m.querySelectorAll('input,textarea').forEach(function (i) { i.addEventListener('input', markDirty); });
      var titleEl = document.getElementById('st-title'); function growTitle() { titleEl.style.height = 'auto'; titleEl.style.height = titleEl.scrollHeight + 'px'; } titleEl.addEventListener('input', growTitle); titleEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); ed.el.focus(); } }); requestAnimationFrame(growTitle);
      // sources
      var srcHost = document.getElementById('st-sources');
      function addSrc(v) { v = v || {}; var row = el('div', 'desk-src', '<input class="dui-input" placeholder="Label (e.g. DMW advisory)" value="' + esc(v.label || '') + '"><input class="dui-input" placeholder="https://…" value="' + esc(v.url || '') + '"><button type="button" class="dui-btn dui-btn--quiet dui-btn--sm" aria-label="Remove">✕</button>'); row.querySelector('button').onclick = function () { row.remove(); markDirty(); }; row.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', markDirty); }); srcHost.appendChild(row); }
      (s.sources || []).forEach(addSrc); document.getElementById('st-addsrc').onclick = function () { addSrc(); };
      function getSources() { return Array.prototype.map.call(srcHost.querySelectorAll('.desk-src'), function (r) { var i = r.querySelectorAll('input'); return { label: i[0].value.trim(), url: i[1].value.trim() }; }).filter(function (x) { return x.label || x.url; }); }
      // image
      function setImg(u) { m.querySelector('[name=featured_image_url]').value = u || ''; var p = document.getElementById('st-imgprev'); if (u) { if (p.tagName !== 'IMG') { var im = document.createElement('img'); im.className = 'desk-imgprev'; im.id = 'st-imgprev'; im.alt = ''; p.replaceWith(im); p = im; } p.src = u; } else if (p.tagName === 'IMG') { var dv = document.createElement('div'); dv.className = 'desk-imgprev'; dv.id = 'st-imgprev'; p.replaceWith(dv); } markDirty(); }
      document.getElementById('st-upimg').onclick = function () { uploadImage().then(function (u) { if (u) { setImg(u); dui.toast('Image uploaded', 'success'); } }); };
      document.getElementById('st-urlimg').onclick = function () { dui.prompt('Featured image', 'Image URL', m.querySelector('[name=featured_image_url]').value, { required: true }).then(function (u) { if (u) setImg(u); }); };
      var rm = document.getElementById('st-rmimg'); if (rm) rm.onclick = function () { setImg(''); };
      var gen = document.getElementById('st-genimg'); if (gen) gen.onclick = function () { dui.confirm('Generate a featured image', 'Sarah\'s studio will create an image for this headline. Costs 1 credit.', { okLabel: 'Generate' }).then(function (ok) { if (!ok) return; gen.disabled = true; dui.toast('Generating image…'); api('write/articles/' + id + '/generate-featured-image', 'POST', {}).then(function (j) { gen.disabled = false; if (j.success === false || j.__status >= 400) return fail(j, 'Could not generate'); var u = j.featured_image_url || (j.article && j.article.featured_image_url) || j.url || (j.data && (j.data.url || j.data.featured_image_url)); if (u) { setImg(u); dirty = false; document.getElementById('st-dirty').textContent = ''; dui.toast('Image ready', 'success'); } else { dui.toast('Image is being generated; reload in a moment.', 'warning'); } }); }); };
      // actions
      var acts = document.getElementById('st-actions');
      function collect() { var v = vals(m); v.title = document.getElementById('st-title').value.trim(); v.content = ed.getHTML(); v.section = secApi.get(); v.type = typeApi.get(); if (regionApi) v.region = regionApi.get() || 'ALL'; v.sources = getSources(); v.tags = (v.tags || '').split(',').map(function (t) { return t.trim(); }).filter(Boolean); return v; }
      var createKey = idem('story');
      function save(then, overwrite) {
        var v = collect(); if (!v.title) { dui.toast('Add a headline first', 'warning'); document.getElementById('st-title').focus(); return Promise.resolve(false); }
        if (!isNew && story && story.updated_at && !overwrite) v.expected_updated_at = story.updated_at; // Unit 2 optimistic lock
        progress(true);
        var req = isNew ? apiCreate('desk/stories', v, createKey) : api('desk/stories/' + id, 'PUT', v);
        return req.then(function (j) {
          progress(false);
          if (j.__status === 409 && j.error === 'STALE') { return dui.dialog({ title: 'Someone else saved this story', body: '<p class="dui-p">' + esc(j.message) + '</p>', okLabel: 'Overwrite with mine', cancelLabel: 'Reload theirs', danger: true }).then(function (ok) { if (ok) return save(then, true); dirty = false; story = j.story; clearAutosave(); render(); return false; }); }
          if (!j.success) { if (j.errors) { var first = Object.keys(j.errors)[0]; var fld = m.querySelector('[name="' + first + '"]'); if (fld) fld.focus(); } fail(j, 'Could not save'); return false; }
          dirty = false; clearAutosave(); document.getElementById('st-dirty').textContent = 'Saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); if (isNew) { id = j.id; isNew = false; history.replaceState(null, '', '#/stories/' + id); } story = j.story; if (then) then(); else { render(); } refreshCounts(); return true;
        });
      }
      /* Unit 2 — local autosave every few seconds while dirty; offered back after a crash or lost session */
      var asKey = 'desk_autosave_' + D.website_id + '_' + (isNew ? 'new' : id);
      var autosave = dui.debounce(function () { if (!dirty) return; try { localStorage.setItem(asKey, JSON.stringify({ at: Date.now(), v: collect() })); } catch (e) {} }, 3000);
      function clearAutosave() { try { localStorage.removeItem(asKey); } catch (e) {} }
      m.addEventListener('input', autosave); ed.el.addEventListener('input', autosave);
      try { var saved = JSON.parse(localStorage.getItem(asKey) || 'null'); if (saved && saved.v && (!story || !story.updated_at || saved.at > new Date(story.updated_at.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(story.updated_at) ? '' : 'Z')).getTime())) { dui.confirm('Recover unsaved work?', 'A local draft from ' + dui.rel(new Date(saved.at).toISOString()) + ' was found on this device.', { okLabel: 'Recover' }).then(function (ok) { if (!ok) { clearAutosave(); return; } var sv = saved.v; document.getElementById('st-title').value = sv.title || ''; ed.setHTML(sv.content || ''); if (sv.excerpt != null) m.querySelector('[name=excerpt]').value = sv.excerpt; if (sv.section) secApi.set(sv.section); if (regionApi && sv.region) regionApi.set(sv.region === 'ALL' ? '' : sv.region); markDirty(); growTitle(); }); } } catch (e) {}
      if (canW) acts.appendChild(btn('Save', 'dui-btn--primary', function () { save(); }));
      if (canPub && s.status !== 'published') acts.appendChild(btn('Publish', 'dui-btn--accent', function () { save(function () { api('desk/stories/' + id + '/publish', 'POST').then(function (j) { if (!j.success) { fail(j, 'Not publishable'); render(); return; } story = j.story; dui.toast('Published', 'success'); render(); refreshCounts(); }); }); }));
      if (canPub && s.status !== 'published') acts.appendChild(btn('Schedule', '', function () { save(function () { scheduleDialog(); }); }));
      if (canPub && (s.status === 'published' || s.status === 'scheduled')) acts.appendChild(btn(s.status === 'scheduled' ? 'Cancel schedule' : 'Unpublish', '', function () { api('desk/stories/' + id + '/unpublish', 'POST').then(function (j) { if (!j.success) return fail(j); story = j.story; dui.toast('Back to draft', 'success'); render(); refreshCounts(); }); }));
      if (canW && !isNew) acts.appendChild(btn('Delete', 'dui-btn--danger dui-btn--sm', function () { dui.confirm('Delete this story?', 'It disappears from the site and the desk. This can be undone by an engineer only.', { danger: true, okLabel: 'Delete' }).then(function (ok) { if (!ok) return; api('desk/stories/' + id, 'DELETE').then(function (j) { if (!j.success) return fail(j); dui.toast('Deleted', 'success'); dirty = false; refreshCounts(); go('stories'); }); }); }));
      function scheduleDialog() {
        var d = new Date(Date.now() + 3600e3); var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        dui.dialog({ title: 'Schedule publication', body: '<div class="dui-row"><div>' + field('Date', '<input class="dui-input" name="d" placeholder="YYYY-MM-DD" value="' + d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + '">') + '</div><div>' + field('Time (24h, your local time)', '<input class="dui-input" name="t" placeholder="HH:MM" value="' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + '">') + '</div></div><p class="dui-help">The story goes live automatically at that moment.</p>', okLabel: 'Schedule',
          onOk: function (box) { var dv = box.querySelector('[name=d]').value.trim(), tv = box.querySelector('[name=t]').value.trim(); var when = new Date(dv + 'T' + tv + ':00'); if (isNaN(when) || when.getTime() < Date.now() + 60e3) { dui.toast('Pick a valid future date and time', 'warning'); return false; } return when.toISOString(); } })
          .then(function (iso) { if (!iso) return; api('desk/stories/' + id + '/schedule', 'POST', { at: iso }).then(function (j) { if (!j.success) return fail(j, 'Could not schedule'); story = j.story; dui.toast('Scheduled', 'success'); render(); refreshCounts(); }); });
      }
      document.addEventListener('keydown', keySave); function keySave(e) { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); if (canW) save(); } }
      S.dirtyGuard = function () { return dirty; };
      window.onbeforeunload = function () { return dirty ? 'Unsaved changes' : undefined; };
      var offRoute = function () { document.removeEventListener('keydown', keySave); window.onbeforeunload = null; window.removeEventListener('hashchange', offRoute); }; window.addEventListener('hashchange', offRoute);
    }
    if (isNew) { if (!can('stories.write')) { m.innerHTML = ''; m.appendChild(empty('Read-only', 'Your role cannot create stories.')); return; } render(); return; }
    api('desk/stories/' + id).then(function (j) { if (!j.success) { m.innerHTML = ''; m.appendChild(empty('Story not found', '', btn('Back to stories', '', function () { go('stories'); }))); return; } story = j.story; setTitle(story.title || 'Story'); render(); });
  };

  /* ---------- commission ---------- */
  VIEWS.commission = function () {
    setTitle('Commission Sarah'); var m = main();
    if (!can('commission')) { m.appendChild(empty('Read-only', 'Your role cannot commission stories.')); return; }
    m.innerHTML = '<div class="desk-grid2"><div class="desk-card"><h3>Brief a story</h3><form id="cm-form" novalidate>' +
      field('Working title', inp('title', '', 'e.g. How to renew an OWWA membership from Dubai')) +
      field('Brief for the writer', ta('brief', '', 'What the story must cover, the angle, what to avoid, who it is for. Facts you want checked. Sarah will not invent names, prices or dates.', 6)) +
      '<div class="dui-row"><div>' + field('Section', '<div id="cm-section"></div>') + '</div>' + (multiEdition() ? '<div>' + field('Edition', '<div id="cm-region"></div>') + '</div>' : '') + '<div>' + field('Type', '<div id="cm-type"></div>') + '</div><div>' + field('Length', '<div id="cm-len"></div>') + '</div></div>' +
      field('Tone', inp('tone', 'clear, warm, factual, kabayan-to-kabayan', '')) +
      '<button class="dui-btn dui-btn--accent" type="submit" style="min-height:46px">Send to Sarah</button><div class="dui-help" style="margin-top:8px">Uses the workspace\'s writing credits. The draft lands in Stories when it is ready; nothing publishes without you.</div></form></div>' +
      '<div class="desk-card"><h3>Commissions</h3><div class="desk-list" id="cm-list"><div class="muted small">Loading…</div></div></div></div>';
    var sec = dui.listbox(document.getElementById('cm-section'), { options: sectionOpts('Pick a section'), value: '', placeholder: 'Section' });
    var typ = dui.listbox(document.getElementById('cm-type'), { options: S.ctx.enums.story_types.map(function (t) { return { value: t, label: t.charAt(0).toUpperCase() + t.slice(1) }; }), value: 'article' });
    var len = dui.listbox(document.getElementById('cm-len'), { options: [{ value: 500, label: 'Short (~500 words)' }, { value: 800, label: 'Standard (~800 words)' }, { value: 1200, label: 'Long read (~1,200 words)' }], value: 800 });
    var cmRegion = multiEdition() ? dui.listbox(document.getElementById('cm-region'), { options: regionOpts('All editions'), value: '' }) : null;
    var f = document.getElementById('cm-form');
    f.onsubmit = function (e) { e.preventDefault(); var v = vals(f); v.section = sec.get(); v.type = typ.get(); v.length = Number(len.get()); if (cmRegion) v.region = cmRegion.get() || 'ALL'; if (!v.title && !v.brief) return dui.toast('Give Sarah a title or a brief', 'warning'); var b = f.querySelector('button'); b.disabled = true; apiCreate('desk/commissions', v, idem('cm')).then(function (j) { b.disabled = false; if (!j.success) return fail(j, 'Could not commission'); dui.toast(j.status === 'awaiting_approval' ? 'Sent — waiting for approval in LevelUp' : 'Sent to Sarah', 'success'); f.reset(); f.querySelector('[name=tone]').value = 'clear, warm, factual, kabayan-to-kabayan'; loadList(); refreshCounts(); }); };
    function loadList() {
      api('desk/commissions').then(function (j) { var h = document.getElementById('cm-list'); if (!h) return; if (!j.success) return fail(j); if (!j.commissions.length) { h.innerHTML = ''; h.appendChild(empty('Nothing commissioned yet', 'Your briefs and their progress show here.')); return; }
        h.innerHTML = j.commissions.map(function (c) { var st = c.status === 'queued' && c.task_status ? c.task_status : c.status; return '<div class="desk-item" style="grid-template-columns:1fr auto"><div class="desk-item-b"><b>' + esc(c.title) + '</b><small>' + (multiEdition() ? esc(regionLabel(c.region)) + ' · ' : '') + esc(c.section || 'no section') + ' · ' + esc(c.type) + ' · ' + esc(dui.rel(c.created_at)) + (c.progress ? ' · ' + esc(c.progress) : '') + (c.error ? ' · <span style="color:var(--rd)">' + esc(c.error) + '</span>' : '') + '</small></div><div class="desk-item-r">' + pill(c.status, st.replace('_', ' ')) + (c.article_id ? '<a href="#/stories/' + c.article_id + '">Open draft</a>' : '') + '</div></div>'; }).join('');
        var open = j.commissions.some(function (c) { return c.status === 'queued' || c.status === 'awaiting_approval'; });
        clearTimeout(S.pollTimer); if (open && S.route && S.route.name === 'commission') S.pollTimer = setTimeout(loadList, 15000);
      });
    }
    loadList();
  };

  /* ---------- sections ---------- */
  VIEWS.sections = function () {
    setTitle('Sections', [can('sections.write') ? btn('New section', 'dui-btn--primary', function () { dui.dialog({ title: 'New section', body: field('Name', inp('name', '', 'e.g. Entertainment')) + field('Slug (URL)', inp('slug', '', 'auto from name'), 'Lowercase, dashes. Becomes /{slug} once the page exists.'), okLabel: 'Create', onOk: function (b) { var v = vals(b); if (!v.name) return false; return v; } }).then(function (v) { if (!v) return; api('desk/sections', 'POST', v).then(function (j) { if (!j.success) return fail(j); dui.toast('Section created', 'success'); refreshCounts().then(route); }); }); }) : null].filter(Boolean));
    var m = main(); var secs = S.ctx.sections || [];
    if (!secs.length) { m.appendChild(empty('No sections yet', 'Sections group stories and map to a page on the site.')); return; }
    m.innerHTML = '<div class="desk-tblwrap"><table class="desk-tbl"><thead><tr><th>Section</th><th>Slug</th><th>Stories</th><th>Page</th><th></th></tr></thead><tbody>' + secs.map(function (s) { return '<tr><td><b>' + esc(s.name) + '</b></td><td class="mono">' + esc(s.slug) + '</td><td>' + s.published + ' live · ' + (s.stories - s.published) + ' draft</td><td>' + (s.has_page ? '<a href="' + esc(D.origin + '/' + s.slug) + '" target="_blank" rel="noopener">/' + esc(s.slug) + ' ↗</a>' : '<span class="muted small">no page yet</span>') + '</td><td style="text-align:right">' + (can('sections.write') ? '<button class="dui-btn dui-btn--sm" data-edit="' + s.id + '">Edit</button> <button class="dui-btn dui-btn--sm dui-btn--quiet" data-del="' + s.id + '">Delete</button>' : '') + '</td></tr>'; }).join('') + '</tbody></table></div>' +
      '<p class="desk-note" style="margin-top:14px">A section page (hero, feed, nav link) is laid out by Arthur / the site builder. Creating a section here makes it available to stories immediately; ask Sarah or Arthur to add its page and navigation link.</p>';
    m.querySelectorAll('[data-edit]').forEach(function (b) { b.onclick = function () { var s = secs.filter(function (x) { return x.id === Number(b.dataset.edit); })[0]; dui.dialog({ title: 'Edit section', body: field('Name', inp('name', s.name)) + field('Slug', inp('slug', s.slug), 'Changing the slug moves its stories with it. The site page keeps its own slug.'), okLabel: 'Save', onOk: function (x) { return vals(x); } }).then(function (v) { if (!v) return; api('desk/sections/' + s.id, 'PUT', v).then(function (j) { if (!j.success) return fail(j); dui.toast('Saved', 'success'); refreshCounts().then(route); }); }); }; });
    m.querySelectorAll('[data-del]').forEach(function (b) { b.onclick = function () { var s = secs.filter(function (x) { return x.id === Number(b.dataset.del); })[0]; dui.confirm('Delete "' + s.name + '"?', s.stories ? s.stories + ' stories use it; move them first.' : 'It has no stories.', { danger: true, okLabel: 'Delete' }).then(function (ok) { if (!ok) return; api('desk/sections/' + s.id, 'DELETE').then(function (j) { if (!j.success) return fail(j); dui.toast('Deleted', 'success'); refreshCounts().then(route); }); }); }; });
  };

  /* ---------- jobs ---------- */
  function jobItem(j) {
    return '<a class="desk-item" href="#/jobs/' + j.id + '">' + (j.company_logo_url ? '<img class="desk-thumb" src="' + esc(j.company_logo_url) + '" alt="" style="width:42px;object-fit:contain">' : '<div class="desk-thumb" style="width:42px;font-size:16px;font-weight:700;color:var(--t2)">' + esc((j.company || '?').charAt(0).toUpperCase()) + '</div>') +
      '<div class="desk-item-b"><b>' + esc(j.title) + '</b><small>' + esc(j.company) + ' · ' + esc(j.city || (j.is_remote ? 'Remote' : '')) + (multiEdition() && j.country ? ', ' + esc(regionLabel(j.country)) : '') + ' · ' + esc((j.employment_type || '').replace('_', ' ')) + (j.source === 'employer' ? ' · from form' : '') + '</small></div>' +
      '<div class="desk-item-r">' + pill(j.status) + '<span>' + (j.status === 'published' && j.days_left != null ? (j.days_left <= 0 ? 'expires today' : j.days_left + ' days left') : dui.rel(j.updated_at)) + '</span></div></a>';
  }
  VIEWS.jobs = function (r) {
    if (r.id === 'new') return VIEWS.jobEditor(null);
    if (r.id) return VIEWS.jobEditor(Number(r.id));
    setTitle('Jobs', [can('jobs.write') ? btn('Post a job', 'dui-btn--primary', function () { go('jobs/new'); }) : null].filter(Boolean));
    var m = main(); var f = { status: r.q.status || 'all', q: '', offset: 0 }; var c = S.ctx.counts.jobs;
    var chips = el('div'); m.appendChild(chips); var tb = el('div', 'desk-toolbar'); var search = el('input', 'dui-input'); search.type = 'search'; search.placeholder = 'Search title, company, city…'; tb.appendChild(search); m.appendChild(tb);
    var list = el('div', 'desk-list'); m.appendChild(list); var more = el('div', 'desk-more'); m.appendChild(more);
    var items = [{ value: 'all', label: 'All' }, { value: 'draft', label: 'To review', count: c.draft }, { value: 'published', label: 'Live', count: c.published }, { value: 'expired', label: 'Expired', count: c.expired }];
    function chipRender() { dui.chips(chips, items, f.status, function (v) { f.status = v; f.offset = 0; chipRender(); load(true); }); } chipRender();
    search.oninput = dui.debounce(function () { f.q = search.value.trim(); f.offset = 0; load(true); }, 350);
    function load(reset) { if (reset) { list.innerHTML = '<div class="muted small">Loading…</div>'; more.innerHTML = ''; } api('desk/jobs?status=' + f.status + '&q=' + encodeURIComponent(f.q) + '&offset=' + f.offset + '&limit=50').then(function (j) { if (!j.success) return fail(j); if (reset) list.innerHTML = ''; if (!j.jobs.length && reset) { list.appendChild(empty('No jobs here', 'Employer submissions arrive in the Inbox; approve them into listings, or post one yourself.', can('jobs.write') ? btn('Post a job', 'dui-btn--primary', function () { go('jobs/new'); }) : null)); return; } list.insertAdjacentHTML('beforeend', j.jobs.map(jobItem).join('')); more.innerHTML = ''; if (j.has_more) more.appendChild(btn('Load more', '', function () { f.offset += j.limit; load(false); })); }); }
    load(true);
  };
  VIEWS.jobEditor = function (id) {
    var m = main(); var isNew = !id; var job = null; setTitle(isNew ? 'Post a job' : 'Job');
    m.innerHTML = '<div class="muted small">Loading…</div>';
    function render() {
      var j = job || { status: 'draft', country: 'AE', employment_type: 'full_time', category_slug: 'other', city: 'Dubai' }; var canW = can('jobs.write'), canP = can('jobs.publish');
      m.innerHTML = '<div class="desk-editor"><div><form id="jb-form" novalidate>' +
        '<div class="dui-row">' + field('Job title', inp('title', j.title, 'e.g. Barista')) + field('Company', inp('company', j.company, 'Employer name')) + '</div>' +
        '<div class="dui-row"><div>' + field('Category', '<div id="jb-cat"></div>') + '</div><div>' + field('Type', '<div id="jb-type"></div>') + '</div></div>' +
        '<div class="dui-row"><div>' + field('Country', '<div id="jb-country"></div>') + '</div>' + field('City', inp('city', j.city, 'Dubai or Doha')) + field('Region / Emirate / Municipality', inp('region', j.region, 'Dubai')) + '</div>' +
        '<div class="dui-row">' + field('Salary (text shown on the site)', inp('salary_text', j.salary_text, 'AED 3,500–4,500 + accommodation')) + field('Company website', inp('company_url', j.company_url, 'https://')) + '</div>' +
        field('Summary (one line)', inp('summary', j.summary, 'Shown in lists')) +
        '<div class="dui-field"><label class="dui-label">Description</label><div id="jb-desc"></div></div>' +
        '<div class="dui-row">' + field('Requirements (one per line)', ta('requirements', Array.isArray(j.requirements) ? j.requirements.join('\n') : (j.requirements || ''), '', 4)) + field('Benefits (one per line)', ta('benefits', (j.benefits || []).join('\n'), '', 4)) + '</div>' +
        '<div class="dui-row">' + field('Apply URL', inp('apply_url', j.apply_url, 'https://…')) + field('Apply email', inp('apply_email', j.apply_email, 'jobs@company.com', 'email')) + '</div>' +
        field('Apply instructions', inp('apply_instructions', j.apply_instructions, 'e.g. Subject line: Barista – Dubai')) +
        field('Verification source', inp('verification_source', j.verification_source, 'Link to the official posting, or how the desk confirmed the vacancy'), 'Required to publish. Readers are told every listing is verified.') +
        '<div class="dui-row">' + field('Company logo URL', inp('company_logo_url', j.company_logo_url, 'https://')) + field('Remote?', '<label class="desk-inline" style="min-height:42px"><input type="checkbox" name="is_remote"' + (j.is_remote ? ' checked' : '') + '> Fully remote</label>') + '</div>' +
        '</form></div><aside class="desk-editor-side"><div class="desk-card"><h3>Status ' + pill(j.status) + '</h3>' + (j.url ? '<p class="small" style="margin:0 0 10px"><a href="' + esc(j.url) + '" target="_blank" rel="noopener">View live ↗</a></p>' : '') + (j.expires_at ? '<p class="small muted">Expires ' + esc(dui.fmt(j.expires_at)) + '</p>' : '') + '<div class="desk-status" id="jb-actions"></div></div>' + (j.source === 'employer' && j.lead_id ? '<div class="desk-card small">Submitted through the site form. <a href="#/inbox/' + j.lead_id + '">Open the submission</a></div>' : '') + '</aside></div>';
      var cat = dui.listbox(document.getElementById('jb-cat'), { options: S.ctx.enums.job_categories, value: j.category_slug || 'other' });
      var typ = dui.listbox(document.getElementById('jb-type'), { options: S.ctx.enums.job_types, value: j.employment_type || 'full_time' });
      var regs = (S.ctx.regions && S.ctx.regions.length) ? S.ctx.regions : [{ code: 'AE', short: 'UAE', name: 'United Arab Emirates' }];
      var country = dui.listbox(document.getElementById('jb-country'), { options: regs.map(function (r) { return { value: r.code, label: r.short, hint: r.name }; }), value: j.country || regs[0].code });
      var ed = dui.editor(document.getElementById('jb-desc'), j.description || '', { placeholder: 'Duties, hours, who it suits…', pickImage: pickImage });
      var f = document.getElementById('jb-form'); if (!canW) f.querySelectorAll('input,textarea').forEach(function (i) { i.readOnly = true; });
      function collect() { var v = vals(f); v.category = cat.get(); v.category_slug = cat.get(); v.employment_type = typ.get(); v.country = country.get(); v.description = ed.getHTML(); v.requirements = v.requirements.split('\n').map(function (x) { return x.trim(); }).filter(Boolean); v.benefits = v.benefits.split('\n').map(function (x) { return x.trim(); }).filter(Boolean); return v; }
      var jobKey = idem('job');
      function save(then, overwrite) { var v = collect(); if (!v.title || !v.company) { dui.toast('Title and company are required', 'warning'); return; } if (!isNew && job && job.updated_at && !overwrite) v.expected_updated_at = job.updated_at; progress(true); (isNew ? apiCreate('desk/jobs', v, jobKey) : api('desk/jobs/' + id, 'PUT', v)).then(function (r) { progress(false); if (r.__status === 409) { return dui.dialog({ title: 'Someone else saved this listing', body: '<p class="dui-p">' + esc(r.message) + '</p>', okLabel: 'Overwrite with mine', cancelLabel: 'Reload theirs', danger: true }).then(function (ok) { if (ok) return save(then, true); job = r.job; render(); }); } if (!r.success) return fail(r, 'Could not save'); if (isNew) { id = r.job_id; isNew = false; history.replaceState(null, '', '#/jobs/' + id); } job = r.job; refreshCounts(); if (then) then(); else { dui.toast('Saved', 'success'); render(); } }); }
      var acts = document.getElementById('jb-actions');
      if (canW) acts.appendChild(btn('Save', 'dui-btn--primary', function () { save(); }));
      if (canP && j.status !== 'published') acts.appendChild(btn('Publish', 'dui-btn--accent', function () { save(function () { api('desk/jobs/' + id + '/publish', 'POST').then(function (r) { if (!r.success) { fail(r, 'Not publishable'); render(); return; } job = r.job; dui.toast('Job is live (45 days)', 'success'); render(); refreshCounts(); }); }); }));
      if (canP && j.status === 'published') acts.appendChild(btn('Expire now', '', function () { dui.confirm('Expire this listing?', 'It leaves the job board immediately.', { okLabel: 'Expire' }).then(function (ok) { if (!ok) return; api('desk/jobs/' + id + '/status', 'POST', { status: 'expired' }).then(function (r) { if (!r.success) return fail(r); job = r.job; render(); refreshCounts(); }); }); }));
      if (canP && (j.status === 'expired' || j.status === 'archived')) acts.appendChild(btn('Back to draft', '', function () { api('desk/jobs/' + id + '/status', 'POST', { status: 'draft' }).then(function (r) { if (!r.success) return fail(r); job = r.job; render(); refreshCounts(); }); }));
    }
    if (isNew) { if (!can('jobs.write')) { m.innerHTML = ''; m.appendChild(empty('Read-only', 'Your role cannot post jobs.')); return; } render(); return; }
    api('desk/jobs/' + id).then(function (r) { if (!r.success) { m.innerHTML = ''; m.appendChild(empty('Job not found', '', btn('Back to jobs', '', function () { go('jobs'); }))); return; } job = r.job; setTitle(job.title); render(); });
  };

  /* ---------- inbox ---------- */
  var SRC_LABEL = { newsletter: 'Newsletter', job_post: 'Job posts', website_form: 'Contact', contact: 'Contact', spot_submission: 'Spot tips' };
  VIEWS.inbox = function (r) {
    if (r.id) return VIEWS.inboxItem(Number(r.id));
    setTitle('Inbox'); var m = main(); var f = { source: r.q.source || 'all', status: r.q.status || 'all', q: '', offset: 0 };
    var chips = el('div'); m.appendChild(chips); var tb = el('div', 'desk-toolbar'); var stHost = el('div'); stHost.style.minWidth = '170px'; var search = el('input', 'dui-input'); search.type = 'search'; search.placeholder = 'Search name, email, company…'; tb.appendChild(stHost); tb.appendChild(search); m.appendChild(tb);
    var list = el('div', 'desk-list'); m.appendChild(list); var more = el('div', 'desk-more'); m.appendChild(more);
    dui.listbox(stHost, { options: [{ value: 'all', label: 'Any status' }].concat(S.ctx.enums.lead_statuses.map(function (s) { return { value: s, label: s.charAt(0).toUpperCase() + s.slice(1) }; })), value: f.status, onChange: function (v) { f.status = v; f.offset = 0; load(true); } });
    search.oninput = dui.debounce(function () { f.q = search.value.trim(); f.offset = 0; load(true); }, 350);
    function chipRender(sources) { var items = [{ value: 'all', label: 'All' }].concat(Object.keys(sources || {}).map(function (k) { return { value: k, label: SRC_LABEL[k] || k, count: sources[k] }; })); dui.chips(chips, items, f.source, function (v) { f.source = v; f.offset = 0; load(true); }); }
    function load(reset) { if (reset) { list.innerHTML = '<div class="muted small">Loading…</div>'; more.innerHTML = ''; } api('desk/inbox?source=' + f.source + '&status=' + f.status + '&q=' + encodeURIComponent(f.q) + '&offset=' + f.offset + '&limit=50').then(function (j) { if (!j.success) return fail(j); chipRender(j.sources); if (reset) list.innerHTML = ''; if (!j.items.length && reset) { list.appendChild(empty('Inbox is clear', 'Newsletter sign-ups, job posts and contact messages from the site land here.')); return; } list.insertAdjacentHTML('beforeend', j.items.map(function (l) { return '<a class="desk-item" href="#/inbox/' + l.id + '" style="grid-template-columns:1fr auto"><div class="desk-item-b"><b>' + esc(l.name || l.email) + '</b><small>' + esc(SRC_LABEL[l.source] || l.source) + ' · ' + esc(l.email || '') + (l.company ? ' · ' + esc(l.company) : '') + (l.message ? ' · ' + esc(l.message.slice(0, 80)) : '') + '</small></div><div class="desk-item-r">' + pill(l.status) + '<span>' + esc(dui.rel(l.created_at)) + (l.activities ? ' · ' + l.activities + ' notes' : '') + '</span></div></a>'; }).join('')); more.innerHTML = ''; if (j.has_more) more.appendChild(btn('Load more', '', function () { f.offset += j.limit; load(false); })); }); }
    load(true);
  };
  VIEWS.inboxItem = function (id) {
    setTitle('Submission'); var m = main(); m.innerHTML = '<div class="muted small">Loading…</div>';
    function render(it) {
      m.innerHTML = '<div class="desk-editor"><div><div class="desk-card" style="margin-bottom:14px"><h3>' + esc(it.name || it.email) + ' ' + pill(it.status) + '</h3><dl class="desk-kv"><dt>Source</dt><dd>' + esc(SRC_LABEL[it.source] || it.source) + '</dd><dt>Email</dt><dd><a href="mailto:' + esc(it.email) + '">' + esc(it.email) + '</a></dd>' + (it.phone ? '<dt>Phone</dt><dd><a href="tel:' + esc(it.phone) + '">' + esc(it.phone) + '</a></dd>' : '') + (it.company ? '<dt>Company</dt><dd>' + esc(it.company) + '</dd>' : '') + '<dt>Received</dt><dd>' + esc(dui.fmt(it.submitted_at)) + '</dd></dl>' + (it.message ? '<h3 style="margin-top:14px">Message</h3><div class="desk-msg">' + esc(it.message) + '</div>' : '') + '</div>' +
        '<div class="desk-card"><h3>Timeline</h3><ul class="desk-tl">' + ((it.timeline || []).map(function (t) { return '<li><b>' + esc(t.subject || t.type) + '</b>' + (t.description ? '<div>' + esc(t.description) + '</div>' : '') + '<small>' + esc(dui.fmt(t.created_at)) + '</small></li>'; }).join('') || '<li class="muted">No notes yet.</li>') + '</ul></div></div>' +
        '<aside class="desk-editor-side"><div class="desk-card"><h3>Handle</h3>' + (can('inbox.write') ? field('Status', '<div id="ib-status"></div>') + field('Add a note', ta('note', '', 'What was done, who replied…', 3)) + '<button class="dui-btn dui-btn--primary" type="button" id="ib-save">Save</button>' : '<p class="muted small">Read-only role.</p>') + '</div>' +
        (it.source === 'job_post' && can('jobs.write') ? '<div class="desk-card"><h3>Job post</h3><p class="small muted">Turn this submission into a draft listing, then verify and publish it from Jobs.</p><button class="dui-btn dui-btn--accent" type="button" id="ib-job">Create draft listing</button></div>' : '') + '</aside></div>';
      var st; if (can('inbox.write')) { st = dui.listbox(document.getElementById('ib-status'), { options: S.ctx.enums.lead_statuses.map(function (s) { return { value: s, label: s.charAt(0).toUpperCase() + s.slice(1) }; }), value: it.status }); document.getElementById('ib-save').onclick = function () { api('desk/inbox/' + id, 'PUT', { status: st.get(), note: m.querySelector('[name=note]').value }).then(function (j) { if (!j.success) return fail(j); dui.toast('Saved', 'success'); refreshCounts(); render(j.item); }); }; }
      var jb = document.getElementById('ib-job'); if (jb) jb.onclick = function () { api('desk/inbox/' + id + '/job', 'POST').then(function (j) { if (!j.success) return fail(j, 'Could not create the listing'); dui.toast('Draft listing created', 'success'); go('jobs/' + j.job_id); }); };
    }
    api('desk/inbox/' + id).then(function (j) { if (!j.success) { m.innerHTML = ''; m.appendChild(empty('Not found', '', btn('Back to inbox', '', function () { go('inbox'); }))); return; } render(j.item); });
  };

  /* ---------- members ---------- */
  VIEWS.members = function () {
    setTitle('Members', [can('members.write') ? btn('Invite', 'dui-btn--primary', function () { dui.dialog({ title: 'Invite to the desk', body: field('Email', inp('email', '', 'name@company.com', 'email')) + field('Desk role', '<div id="iv-role"></div>') + '<p class="dui-help">They get a LevelUp Growth invitation to this workspace; the desk role applies on their first sign-in here.</p>', okLabel: 'Send invite', onOpen: function (b) { b.__role = dui.listbox(b.querySelector('#iv-role'), { options: ROLE_OPTS, value: 'editor' }); }, onOk: function (b) { var v = vals(b); v.role = b.__role.get(); if (!v.email) return false; return v; } }).then(function (v) { if (!v) return; api('desk/members/invite', 'POST', v).then(function (j) { if (!j.success) return fail(j, 'Could not invite'); dui.toast('Invitation sent', 'success'); route(); }); }); }) : null].filter(Boolean));
    var m = main(); m.innerHTML = '<div class="muted small">Loading…</div>';
    api('desk/members').then(function (j) {
      if (!j.success) return fail(j);
      m.innerHTML = '<div class="desk-tblwrap"><table class="desk-tbl"><thead><tr><th>Person</th><th>Workspace</th><th>Desk role</th></tr></thead><tbody id="mb-rows"></tbody></table></div>' +
        (j.preassigned && j.preassigned.length ? '<div class="desk-card" style="margin-top:14px"><h3>Waiting to accept</h3>' + j.preassigned.map(function (p) { return '<div class="small">' + esc(p.email) + ' · ' + esc(p.role) + '</div>'; }).join('') + '</div>' : '') +
        '<div class="desk-card" style="margin-top:14px"><h3>Roles</h3><dl class="desk-kv"><dt>Owner</dt><dd>Everything, including members.</dd><dt>Editor</dt><dd>Writes, commissions and publishes stories; drafts jobs; handles the inbox.</dd><dt>Moderator</dt><dd>Reviews, publishes and expires jobs; handles the inbox; reads stories.</dd><dt>Viewer</dt><dd>Read-only.</dd></dl></div>';
      var rows = document.getElementById('mb-rows');
      j.members.forEach(function (u) { var tr = el('tr', '', '<td><b>' + esc(u.name || u.email) + '</b><br><span class="small muted">' + esc(u.email) + '</span></td><td class="small">' + esc(u.workspace_role) + (u.is_workspace_owner ? ' (owner)' : '') + '</td><td></td>'); var cell = tr.lastElementChild; if (can('members.write') && !u.is_workspace_owner) { var h = el('div'); h.style.maxWidth = '200px'; cell.appendChild(h); dui.listbox(h, { options: ROLE_OPTS, value: u.desk_role, small: true, onChange: function (v) { api('desk/members/' + u.user_id, 'PUT', { role: v }).then(function (r) { if (!r.success) return fail(r); dui.toast('Role updated', 'success'); }); } }); } else cell.innerHTML = pill(u.desk_role, u.desk_role); rows.appendChild(tr); });
    });
  };
  var ROLE_OPTS = [{ value: 'owner', label: 'Owner' }, { value: 'editor', label: 'Editor' }, { value: 'moderator', label: 'Jobs moderator' }, { value: 'viewer', label: 'Viewer' }];

  /* ---------- activity (audit trail) ---------- */
  VIEWS.activity = function () {
    setTitle('Activity'); var m = main();
    if (!can('audit.read')) { m.appendChild(empty('Owners and editors only', 'The activity log is restricted.')); return; }
    var f = { entity_type: '', offset: 0 }; var tb = el('div', 'desk-toolbar'); var lb = el('div'); lb.style.minWidth = '180px'; tb.appendChild(lb); m.appendChild(tb); var list = el('div', 'desk-list'); m.appendChild(list); var more = el('div', 'desk-more'); m.appendChild(more);
    dui.listbox(lb, { options: [{ value: '', label: 'Everything' }, { value: 'story', label: 'Stories' }, { value: 'job', label: 'Jobs' }, { value: 'lead', label: 'Inbox' }, { value: 'section', label: 'Sections' }, { value: 'user', label: 'Members & sessions' }, { value: 'commission', label: 'Commissions' }], value: '', onChange: function (v) { f.entity_type = v; f.offset = 0; load(true); } });
    function load(reset) { if (reset) list.innerHTML = '<div class="muted small">Loading…</div>'; api('desk/audit?entity_type=' + f.entity_type + '&offset=' + f.offset + '&limit=50').then(function (j) { if (!j.success) return fail(j); if (reset) list.innerHTML = ''; if (!j.items.length && reset) { list.appendChild(empty('Nothing yet', 'Every change made in the desk is recorded here.')); return; } list.insertAdjacentHTML('beforeend', j.items.map(function (a) { var link = a.entity_type === 'story' && a.entity_id ? '#/stories/' + a.entity_id : a.entity_type === 'job' && a.entity_id ? '#/jobs/' + a.entity_id : a.entity_type === 'lead' && a.entity_id ? '#/inbox/' + a.entity_id : null; var diff = ''; if (a.before || a.after) diff = '<small class="mono">' + esc(JSON.stringify(a.before || {})) + ' → ' + esc(JSON.stringify(a.after || {})) + '</small>'; return '<div class="desk-item" style="grid-template-columns:1fr auto"><div class="desk-item-b"><b>' + esc(a.action.replace('.', ' · ')) + (a.summary ? ' — ' + esc(a.summary) : '') + '</b><small>' + esc((a.user && (a.user.name || a.user.email)) || 'system') + ' (' + esc(a.role || '') + ') · ' + esc(a.ip || '') + ' · ref ' + esc((a.request_id || '').slice(0, 8)) + '</small>' + diff + '</div><div class="desk-item-r"><span>' + esc(dui.fmt(a.created_at)) + '</span>' + (link ? '<a href="' + link + '">Open</a>' : '') + '</div></div>'; }).join('')); more.innerHTML = ''; if (j.has_more) more.appendChild(btn('Load more', '', function () { f.offset += j.limit; load(false); })); }); }
    load(true);
  };

  /* ---------- settings / more ---------- */
  VIEWS.settings = function () {
    setTitle('More'); var m = main();
    var theme = document.documentElement.getAttribute('data-theme') || 'dark';
    m.innerHTML = '<div class="desk-list" style="margin-bottom:16px">' + [['commission', 'Commission Sarah', 'commission'], ['sections', 'Sections', 'stories.read'], ['members', 'Members', 'members.read'], ['activity', 'Activity log', 'audit.read']].filter(function (x) { return can(x[2]); }).map(function (x) { return '<a class="desk-item" href="#/' + x[0] + '" style="grid-template-columns:1fr auto;min-height:52px"><div class="desk-item-b"><b>' + x[1] + '</b></div><div class="desk-item-r">›</div></a>'; }).join('') + '</div>' +
      '<div class="desk-card" style="margin-bottom:14px"><h3>Security</h3><div class="dui-row"><div>' + field('Sign out automatically after', '<div id="set-idle"></div>') + '</div></div><div id="set-sessions" class="small muted">Loading sessions…</div><button class="dui-btn dui-btn--sm" type="button" id="set-revoke" style="margin-top:8px">Sign out all other devices</button></div>' +
      (S.ctx.role === 'owner' ? '<div class="desk-card" style="margin-bottom:14px"><h3>System health <button type="button" class="dui-btn dui-btn--sm dui-btn--quiet" id="set-health">Check</button></h3><div id="set-health-out" class="small muted">Database, scheduler, queue and overdue stories.</div></div>' : '') +
      '<div class="desk-card" style="margin-bottom:14px"><h3>Appearance</h3><div id="set-theme" style="max-width:220px"></div></div>' +
      '<div class="desk-card" style="margin-bottom:14px"><h3>Site</h3><dl class="desk-kv"><dt>Public URL</dt><dd><a href="' + esc(D.origin) + '" target="_blank" rel="noopener">' + esc(D.origin) + '</a></dd><dt>Stories base</dt><dd class="mono">/' + esc(D.article_base) + '/</dd><dt>Theme</dt><dd class="mono">' + esc(D.theme) + '</dd><dt>Workspace</dt><dd class="mono">#' + D.workspace_id + '</dd></dl><p class="small muted" style="margin:10px 0 0">Layout, navigation and section pages are edited by Arthur in LevelUp Growth. Ads and the Spots directory arrive in later desk releases.</p></div>' +
      '<div class="desk-card"><h3>Account</h3><p class="small">' + esc(S.ctx.user.email) + ' · ' + esc(S.ctx.role) + '</p><button class="dui-btn" type="button" id="set-out">Sign out</button></div>';
    dui.listbox(document.getElementById('set-theme'), { options: [{ value: 'dark', label: 'Dark' }, { value: 'light', label: 'Light' }], value: theme, onChange: function (v) { document.documentElement.setAttribute('data-theme', v); try { localStorage.setItem('desk_theme', v); } catch (e) {} } });
    document.getElementById('set-out').onclick = function () { api('auth/logout', 'POST', { refresh_token: S.refresh }).catch(function () {}); logout(); };
    var curIdle = '30'; try { curIdle = localStorage.getItem('desk_idle_min') || '30'; } catch (e) {}
    dui.listbox(document.getElementById('set-idle'), { options: [{ value: '15', label: '15 minutes' }, { value: '30', label: '30 minutes' }, { value: '60', label: '1 hour' }, { value: '0', label: 'Never (not recommended)' }], value: curIdle, onChange: function (v) { try { localStorage.setItem('desk_idle_min', v); } catch (e) {} idleMs = parseInt(v, 10) > 0 ? parseInt(v, 10) * 60000 : 0; touchIdle(); dui.toast('Saved', 'success'); } });
    api('desk/sessions').then(function (j) { var h = document.getElementById('set-sessions'); if (!h || !j.success) return; h.innerHTML = '<b style="color:var(--t1)">' + j.sessions.length + ' active session' + (j.sessions.length === 1 ? '' : 's') + '</b><br>' + j.sessions.slice(0, 8).map(function (s) { return (s.current ? '● This device · ' : '○ ') + esc(s.ip || '') + ' · ' + esc((s.user_agent || '').slice(0, 60)) + ' · ' + esc(dui.rel(s.created_at)); }).join('<br>'); });
    document.getElementById('set-revoke').onclick = function () { dui.confirm('Sign out other devices?', 'Every other browser or phone signed in as you will need to sign in again.', { okLabel: 'Sign them out' }).then(function (ok) { if (!ok) return; api('desk/sessions/revoke-others', 'POST').then(function (j) { if (!j.success) return fail(j); dui.toast(j.revoked + ' session(s) signed out', 'success'); route(); }); }); };
    var hb = document.getElementById('set-health'); if (hb) hb.onclick = function () { hb.disabled = true; api('desk/health').then(function (j) { hb.disabled = false; var o = document.getElementById('set-health-out'); if (!j.success) return fail(j); var c = j.checks; o.innerHTML = '<span class="pill pill--' + (j.status === 'ok' ? 'published' : j.status === 'degraded' ? 'scheduled' : 'failed') + '">' + esc(j.status) + '</span> <span class="mono">' + esc(j.checked_at) + '</span><dl class="desk-kv" style="margin-top:8px"><dt>Database</dt><dd>' + esc(c.database) + '</dd><dt>Scheduler</dt><dd>' + esc(c.scheduler.state) + (c.scheduler.age_seconds != null ? ' (' + c.scheduler.age_seconds + 's ago)' : '') + '</dd><dt>Overdue stories</dt><dd>' + c.stories_overdue + '</dd><dt>Open commissions</dt><dd>' + c.commissions.open + (c.commissions.oldest_open_minutes ? ' · oldest ' + c.commissions.oldest_open_minutes + ' min' : '') + '</dd><dt>Queue</dt><dd>' + c.queue.pending_tasks + ' pending · ' + c.queue.running_tasks + ' running · ' + c.queue.failed_24h + ' failed (24h)</dd><dt>Audit rows (24h)</dt><dd>' + c.audit.rows_24h + '</dd></dl>'; }); };
  };

  /* ---------- boot ---------- */
  function boot() {
    if (!S.token) { renderLogin(); return Promise.resolve(); }
    root.dataset.state = 'boot';
    return api('desk/context').then(function (j) {
      if (!j.success) { if (j.__status === 403 || j.__status === 404) { logout(j.message || 'This account has no access to this desk.'); } else if (j.__status === 401) { logout(); } else { renderLogin(j.message || 'Could not load the desk.'); } return; }
      S.ctx = j; renderShell(); route();
    }).catch(function () { renderLogin('Network error. Try again.'); });
  }
  boot();
})();
