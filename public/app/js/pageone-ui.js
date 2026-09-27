/* PAGE-ONE-1 (RFC-0020) — the website's Search view and Heatmaps, inside SEO → Insights.
 *   luPageOneView(el, siteUrl)  how the site does in Google: pages in the sitemap and indexed, what is on page one and
 *                               close to it, the Search Roadmap week by week, the searches Sarah targets, who ranks today
 *   luHeatmapView(el, siteUrl)  where visitors tap and how far they scroll, per page and device, drawn over the live page
 * Styles use the SEO page's own tokens (--lgse-*), so both views sit in the theme in light and dark. */
(function () {
  'use strict';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function get(path) { return fetch(window.luApi + path, { headers: authHeader(), cache: 'no-store' }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); }); }
  function post(path, body) { var h = authHeader(); h['Content-Type'] = 'application/json'; return fetch(window.luApi + path, { method: 'POST', headers: h, body: JSON.stringify(body || {}) }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); }); }
  function q(site) { return site ? '?site_url=' + encodeURIComponent(site) : ''; }
  function toast(m, k) { if (typeof showToast === 'function') showToast(m, k || 'info'); }

  var CSS = '.p1{font-size:12.5px;color:var(--lgse-t1)}.p1 h3{font-size:13px;margin:22px 0 10px;font-weight:600;color:var(--lgse-t1)}'
    + '.p1-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.p1-kpi{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:12px;padding:12px 14px}'
    + '.p1-kpi b{display:block;font-size:22px;font-variant-numeric:tabular-nums;line-height:1.2}.p1-kpi span{color:var(--lgse-t3);font-size:11.5px}'
    + '.p1-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:12px;padding:12px 14px;margin-bottom:12px}'
    + '.p1-btn{border:1px solid var(--lgse-border);background:var(--lgse-bg2);color:var(--lgse-t1);border-radius:9px;padding:7px 12px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}'
    + '.p1-btn.pri{background:var(--lgse-accent,#6C5CE7);border-color:transparent;color:#fff}.p1-btn[aria-pressed=true]{background:var(--lgse-accent,#6C5CE7);color:#fff;border-color:transparent}'
    + '.p1-tbl{width:100%;border-collapse:collapse}.p1-tbl th{font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--lgse-t3);text-align:left;padding:8px 10px;border-bottom:1px solid var(--lgse-border)}'
    + '.p1-tbl td{padding:9px 10px;border-bottom:1px solid var(--lgse-border);vertical-align:top}.p1-tbl td.n{font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}.p1-tbl a{color:var(--lgse-t1)}'
    + '.p1-wrap{overflow-x:auto;border:1px solid var(--lgse-border);border-radius:12px;background:var(--lgse-bg2)}'
    + '.p1-pill{display:inline-block;font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:999px;white-space:nowrap;background:rgba(127,127,127,.14);color:var(--lgse-t2)}'
    + '.p1-pill.g{background:rgba(46,204,113,.15);color:#1f9d57}.p1-pill.a{background:rgba(108,92,231,.15);color:#6C5CE7}.p1-pill.w{background:rgba(245,158,11,.16);color:#b7791f}.p1-pill.r{background:rgba(231,76,60,.14);color:#c0392b}'
    + '.p1-muted{color:var(--lgse-t3)}.p1-chips{display:flex;flex-wrap:wrap;gap:6px}.p1-chip{border:1px solid var(--lgse-border);border-radius:999px;padding:3px 10px;font-size:11.5px;color:var(--lgse-t2)}'
    + '.p1-hm{display:grid;grid-template-columns:260px 1fr;gap:14px}@media(max-width:900px){.p1-hm{grid-template-columns:1fr}}'
    + '.p1-list{border:1px solid var(--lgse-border);border-radius:12px;background:var(--lgse-bg2);max-height:640px;overflow:auto}.p1-li{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid var(--lgse-border);background:transparent;color:var(--lgse-t1);padding:10px 12px;cursor:pointer;font-size:12px}'
    + '.p1-li[aria-current=true]{background:rgba(108,92,231,.12)}.p1-li small{display:block;color:var(--lgse-t3);margin-top:2px}'
    + '.p1-stage{position:relative;border:1px solid var(--lgse-border);border-radius:12px;background:#fff;overflow:auto;max-height:720px}.p1-stage iframe{border:0;display:block;pointer-events:none;transform-origin:0 0}.p1-stage canvas{position:absolute;left:0;top:0;pointer-events:none}'
    + '.p1-reach{position:absolute;right:0;top:0;width:6px;pointer-events:none}.p1-side{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}@media(max-width:700px){.p1-side{grid-template-columns:1fr}}';
  function css() { if (!document.getElementById('p1-css')) { var s = document.createElement('style'); s.id = 'p1-css'; s.textContent = CSS; document.head.appendChild(s); } }

  var BUCKET = { winning: ['Top 3', 'g'], page_one: ['Page one', 'g'], seen_not_clicked: ['Seen, few clicks', 'w'], striking: ['Close to page one', 'a'], deep: ['Page 3–5', ''], slipping: ['Slipped', 'r'], not_indexed: ['Not in Google yet', 'r'], invisible: ['Not found yet', ''], new: ['New', ''], growing: ['Growing', ''] };
  function bucket(b) { var x = BUCKET[b] || [b || '—', '']; return '<span class="p1-pill ' + x[1] + '">' + esc(x[0]) + '</span>'; }
  var STEP = { planned: ['Planned', ''], in_progress: ['Writing', 'a'], needs_you: ['Needs you', 'w'], done: ['Live', 'g'], failed: ['Could not run', 'r'], held: ['Held', 'w'], skipped: ['Skipped', ''] };
  function fmtDate(iso) { try { return new Date(iso).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }); } catch (e) { return ''; } }

  window.luPageOneView = function (el, siteUrl) {
    css();
    el.innerHTML = '<div class="p1 p1-muted" style="padding:24px;text-align:center">Loading…</div>';
    get('search/overview' + q(siteUrl)).then(function (r) {
      var d = r.json || {};
      if (!r.ok || !d.success) { el.innerHTML = '<div class="p1 p1-muted" style="padding:24px">' + esc(d.error || 'Pick a website to see how it does in Google.') + '</div>'; return; }
      var b = (d.pages && d.pages.buckets) || {}, onOne = (b.winning || 0) + (b.page_one || 0) + (b.seen_not_clicked || 0);
      var h = '<div class="p1">';
      h += '<div class="p1-bar"><div><b>' + esc(d.website.name) + '</b> <span class="p1-muted">' + esc(d.website.host || '') + '</span></div>'
        + (d.search_console ? '<span class="p1-pill g">Search Console connected</span>' : '<a class="p1-btn pri" href="' + esc(d.gsc_link) + '" target="_blank" rel="noopener">Connect Google Search Console</a>') + '</div>';
      h += '<div class="p1-kpis">'
        + kpi(d.pages.total, 'pages and articles tracked') + kpi(d.pages.in_sitemap + ' / ' + d.pages.total, 'in the sitemap')
        + kpi(d.pages.checked ? d.pages.indexed + ' / ' + d.pages.checked : '—', d.pages.checked ? 'confirmed in Google' : 'indexing checked after 7 days')
        + kpi(d.search_console ? onOne : '—', 'on Google’s first page') + kpi(d.search_console ? (b.striking || 0) : '—', 'close to page one') + '</div>';
      function kpi(v, l) { return '<div class="p1-kpi"><b>' + esc(v) + '</b><span>' + esc(l) + '</span></div>'; }
      h += '<h3>Search Roadmap</h3>';
      if (d.roadmap && d.roadmap.steps && d.roadmap.steps.length) {
        h += '<div class="p1-bar" style="margin-bottom:8px"><div>' + esc(d.roadmap.title) + ' <span class="p1-muted">' + esc(fmtDate(d.roadmap.starts_on)) + ' – ' + esc(fmtDate(d.roadmap.ends_on)) + '</span></div>'
          + '<span class="p1-pill ' + (d.roadmap.status === 'active' ? 'g' : d.roadmap.status === 'idea' ? 'w' : '') + '">' + esc(d.roadmap.status === 'idea' ? 'Waiting for your OK' : d.roadmap.status === 'active' ? 'Running' : d.roadmap.status) + '</span></div>';
        h += '<div class="p1-wrap"><table class="p1-tbl"><thead><tr><th>Week of</th><th>Article</th><th>Built for the search</th><th>Status</th></tr></thead><tbody>';
        d.roadmap.steps.forEach(function (s) { var m = /Target search:\s*([^.]+)/i.exec(s.brief || ''); var st = STEP[s.status] || [s.status, '']; h += '<tr><td class="n" style="text-align:left">' + esc(fmtDate(s.scheduled_at)) + '</td><td>' + esc(s.title) + '</td><td class="p1-muted">' + esc(m ? m[1] : '') + '</td><td><span class="p1-pill ' + st[1] + '">' + esc(st[0]) + '</span></td></tr>'; });
        h += '</tbody></table></div>';
      } else {
        h += '<div class="p1-bar"><div class="p1-muted">No roadmap yet. Sarah researches what people search for around this business and who ranks today, then plans one article a week to reach page one — included in your plan.</div><button class="p1-btn pri" type="button" id="p1-build">Build my Search Roadmap</button></div>';
      }
      if (d.top_pages && d.top_pages.length) {
        h += '<h3>How your pages do in Google <span class="p1-muted" style="font-weight:400">· last 28 days</span></h3><div class="p1-wrap"><table class="p1-tbl"><thead><tr><th>Page</th><th>Top search</th><th style="text-align:right">Position</th><th style="text-align:right">Seen</th><th style="text-align:right">Clicks</th><th>Status</th></tr></thead><tbody>';
        d.top_pages.forEach(function (p) { h += '<tr><td><a href="' + esc(p.url) + '" target="_blank" rel="noopener">' + esc(p.title || p.url) + '</a></td><td class="p1-muted">' + esc(p.top_query || '') + '</td><td class="n">' + esc(Number(p.position_28).toFixed(1)) + '</td><td class="n">' + esc(p.impressions_28) + '</td><td class="n">' + esc(p.clicks_28) + '</td><td>' + bucket(p.bucket) + '</td></tr>'; });
        h += '</tbody></table></div>';
      } else if (d.search_console) {
        h += '<h3>How your pages do in Google</h3><div class="p1-muted">Sarah reads Search Console every Monday; the first reading appears here after it.</div>';
      }
      if (d.targets && d.targets.length) {
        h += '<h3>Searches Sarah is targeting</h3><div class="p1-wrap"><table class="p1-tbl"><thead><tr><th>Search</th><th style="text-align:right">Searches / month</th><th>Intent</th><th>Status</th></tr></thead><tbody>';
        var TS = { target: ['Target', ''], planned: ['Planned', 'a'], written: ['Published', 'g'], ranking: ['On page one', 'g'] };
        d.targets.forEach(function (t) { var s = TS[t.status] || [t.status, '']; h += '<tr><td>' + esc(t.keyword) + '</td><td class="n">' + esc(t.volume == null ? '—' : Number(t.volume).toLocaleString()) + '</td><td class="p1-muted">' + esc(t.intent || '') + '</td><td><span class="p1-pill ' + s[1] + '">' + esc(s[0]) + (t.position ? ' · #' + Math.round(t.position) : '') + '</span></td></tr>'; });
        h += '</tbody></table></div>';
      }
      var comp = Object.keys(d.competitors || {});
      if (comp.length) h += '<h3>Who ranks for your searches today</h3><div class="p1-chips">' + comp.map(function (c) { return '<span class="p1-chip">' + esc(c) + '</span>'; }).join('') + '</div>';
      h += '</div>';
      el.innerHTML = h;
      var bb = document.getElementById('p1-build');
      if (bb) bb.addEventListener('click', function () { bb.disabled = true; bb.textContent = 'Researching…'; post('search/roadmap' + q(siteUrl), {}).then(function (x) { toast((x.json && (x.json.message || x.json.error)) || 'Done', x.ok ? 'success' : 'error'); if (!x.ok) { bb.disabled = false; bb.textContent = 'Build my Search Roadmap'; } }); });
    }).catch(function () { el.innerHTML = '<div class="p1 p1-muted" style="padding:24px">Could not load the search view. Try again.</div>'; });
  };

  window.luHeatmapView = function (el, siteUrl) {
    css();
    var state = { path: null, device: 'm', host: null, frame: null };
    el.innerHTML = '<div class="p1 p1-muted" style="padding:24px;text-align:center">Loading…</div>';
    get('search/heatmap/pages' + q(siteUrl)).then(function (r) {
      var d = r.json || {};
      if (!r.ok || !d.success) { el.innerHTML = '<div class="p1 p1-muted" style="padding:24px">' + esc(d.error || 'Pick a website.') + '</div>'; return; }
      state.host = d.website.host; state.frame = d.website.frame_host || d.website.host;
      var h = '<div class="p1"><div class="p1-bar"><div>Where visitors tap and how far they scroll — no cookies, no personal data. <span class="p1-muted">Last 30 days.</span></div>'
        + '<div style="display:flex;gap:6px;align-items:center"><button class="p1-btn" type="button" data-dev="m" aria-pressed="true">Phone</button><button class="p1-btn" type="button" data-dev="d" aria-pressed="false">Desktop</button>'
        + '<button class="p1-btn" type="button" id="p1-hm-toggle">' + (d.enabled ? 'Turn off' : 'Turn on') + '</button></div></div>';
      if (!d.pages.length) {
        h += '<div class="p1-bar"><div class="p1-muted">' + (d.enabled ? 'No visits recorded yet. As people visit your website, their taps and scrolling show up here.' : 'Heatmaps are off for this website.') + '</div></div></div>';
        el.innerHTML = h; wireToggle(d.enabled); return;
      }
      h += '<div class="p1-hm"><div class="p1-list" role="list">' + d.pages.map(function (p, i) { return '<button class="p1-li" type="button" role="listitem" data-path="' + esc(p.path) + '" aria-current="' + (i === 0) + '">' + esc(p.path) + '<small>' + esc(p.views) + ' visits · ' + esc(p.avg_depth_pct) + '% of the page read on average</small></button>'; }).join('') + '</div>'
        + '<div><div class="p1-stage" id="p1-stage"></div><div class="p1-side" id="p1-side"></div></div></div></div>';
      el.innerHTML = h; wireToggle(d.enabled);
      Array.prototype.forEach.call(el.querySelectorAll('.p1-li'), function (b) { b.addEventListener('click', function () { Array.prototype.forEach.call(el.querySelectorAll('.p1-li'), function (x) { x.setAttribute('aria-current', 'false'); }); b.setAttribute('aria-current', 'true'); state.path = b.getAttribute('data-path'); draw(); }); });
      Array.prototype.forEach.call(el.querySelectorAll('[data-dev]'), function (b) { b.addEventListener('click', function () { state.device = b.getAttribute('data-dev'); Array.prototype.forEach.call(el.querySelectorAll('[data-dev]'), function (x) { x.setAttribute('aria-pressed', String(x === b)); }); draw(); }); });
      state.path = d.pages[0].path; draw();
    });
    function wireToggle(on) {
      var t = document.getElementById('p1-hm-toggle'); if (!t) return;
      t.addEventListener('click', function () { post('search/heatmap/toggle' + q(siteUrl), { on: !on }).then(function (x) { toast(x.ok ? (on ? 'Heatmaps are off for this website.' : 'Heatmaps are on.') : 'Could not change it.', x.ok ? 'success' : 'error'); if (x.ok) window.luHeatmapView(el, siteUrl); }); });
    }
    function draw() {
      var stage = document.getElementById('p1-stage'), side = document.getElementById('p1-side');
      if (!stage) return;
      stage.innerHTML = '<div class="p1-muted" style="padding:24px">Loading…</div>'; side.innerHTML = '';
      get('search/heatmap' + q(siteUrl) + '&path=' + encodeURIComponent(state.path) + '&device=' + state.device).then(function (r) {
        var d = r.json || {};
        if (!d.views && !(d.clicks || []).length) { stage.innerHTML = '<div class="p1-muted" style="padding:24px">No ' + (state.device === 'm' ? 'phone' : 'desktop') + ' visits on this page yet.</div>'; return; }
        var W = state.device === 'm' ? 390 : 1280, avail = Math.max(320, stage.clientWidth - 2), scale = Math.min(1, avail / W), H = Math.max(900, Math.min(9000, d.doc_h || 3000));
        stage.innerHTML = '';
        var fr = document.createElement('iframe'); fr.title = 'Your page'; fr.setAttribute('tabindex', '-1'); fr.width = W; fr.height = H; fr.style.width = W + 'px'; fr.style.height = H + 'px'; fr.style.transform = 'scale(' + scale + ')';
        fr.src = 'https://' + state.frame + state.path + (state.path.indexOf('?') >= 0 ? '&' : '?') + 'lug_frame=1';
        var holder = document.createElement('div'); holder.style.width = Math.round(W * scale) + 'px'; holder.style.height = Math.round(H * scale) + 'px'; holder.style.position = 'relative'; holder.style.overflow = 'hidden';
        holder.appendChild(fr);
        var cv = document.createElement('canvas'); cv.width = Math.round(W * scale); cv.height = Math.round(H * scale); holder.appendChild(cv);
        stage.appendChild(holder);
        var ctx = cv.getContext('2d');
        // scroll reach: a band that fades where readers stop
        var g = ctx.createLinearGradient(0, 0, 0, cv.height);
        (d.reach || []).forEach(function (p) { g.addColorStop(Math.min(1, p.at / 100), 'rgba(15,23,42,' + (0.55 * (1 - p.pct / 100)).toFixed(3) + ')'); });
        ctx.fillStyle = g; ctx.fillRect(0, 0, cv.width, cv.height);
        // taps
        ctx.globalCompositeOperation = 'lighter';
        (d.clicks || []).forEach(function (c) { var x = c[0] / 1000 * cv.width, y = c[1] / 1000 * cv.height, rad = 26 * Math.max(0.6, scale * 1.4); var gr = ctx.createRadialGradient(x, y, 0, x, y, rad); gr.addColorStop(0, 'rgba(255,72,56,.55)'); gr.addColorStop(0.5, 'rgba(255,170,40,.25)'); gr.addColorStop(1, 'rgba(255,200,40,0)'); ctx.fillStyle = gr; ctx.beginPath(); ctx.arc(x, y, rad, 0, Math.PI * 2); ctx.fill(); });
        ctx.globalCompositeOperation = 'source-over';
        (d.reach || []).forEach(function (p) { if (p.at % 20 || !p.at) return; var y = p.at / 100 * cv.height; ctx.font = '600 11px system-ui,sans-serif'; var lbl = p.pct + '% of visitors got here', lw = ctx.measureText(lbl).width + 16; ctx.fillStyle = 'rgba(15,23,42,.78)'; ctx.fillRect(cv.width - lw - 6, y - 11, lw, 22); ctx.fillStyle = '#fff'; ctx.fillText(lbl, cv.width - lw + 2, y + 4); });
        var top = (d.top_clicked || []).map(function (t) { return '<tr><td>' + esc(t.what) + '</td><td class="n">' + esc(t.clicks) + '</td></tr>'; }).join('');
        var dead = (d.dead_clicks || []).map(function (t) { return '<tr><td>' + esc(t.what) + '</td><td class="n">' + esc(t.clicks) + '</td></tr>'; }).join('');
        side.innerHTML = '<div class="p1-wrap"><table class="p1-tbl"><thead><tr><th>Most tapped</th><th style="text-align:right">Taps</th></tr></thead><tbody>' + (top || '<tr><td class="p1-muted" colspan="2">No taps yet</td></tr>') + '</tbody></table></div>'
          + '<div class="p1-wrap"><table class="p1-tbl"><thead><tr><th>Tapped but not a link</th><th style="text-align:right">Taps</th></tr></thead><tbody>' + (dead || '<tr><td class="p1-muted" colspan="2">None — good</td></tr>') + '</tbody></table></div>';
        side.insertAdjacentHTML('afterbegin', '<div class="p1-kpi" style="grid-column:1/-1"><b>' + esc(d.views) + ' visits · ' + esc(d.avg_depth_pct) + '% read on average</b><span>' + esc((d.reach[5] || {}).pct) + '% of visitors reach the middle of the page, ' + esc((d.reach[9] || {}).pct) + '% reach the end.</span></div>');
      });
    }
  };
})();
