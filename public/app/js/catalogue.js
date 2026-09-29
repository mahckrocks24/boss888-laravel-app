/* RFC-0012 P1 — CATALOGUE INVENTORY (Owner 2026-09-24: "RFC-0012 go"; the plan of 2026-09-22 after
   "I am not quite satisfied with the catalogues, It's not enterprise level. It must be!").

   WHAT CHANGED. CAT-2 put the editor's compact panel inside a page: a flat list with Edit / Status / ×.
   This is the page the plan describes — the whole inventory at a glance and every change in one place:

     header      the industry word, the company switcher (segmented at three or fewer, a picker beyond),
                 the KPI strip, Add and Settings
     inventory   Grid or Table (one state, remembered), search, filter chips with their counts, sort,
                 the "on the home page" marker, and on a phone a table that scrolls sideways
     drawer      the kind's own form, the photo manager (upload · address · cover · order · remove),
                 status with its closed note, the public address with a link, and Remove
     states      empty, skeleton, error with retry, and the banner when the catalogue is switched off

   NOT IN P1, and therefore NOT SHOWN rather than shown empty: enquiries per item and views per item.
   Enquiries need a server stamp on the public enquiry path (P2); views need traffic_logs (P3). A KPI
   this page cannot compute honestly is absent, never a zero.

   Data: GET /api/catalogue/summary for the workspace, then the same per-website endpoints the editor
   uses — nothing new on the server, so every write lands on the live site exactly as it does today. */
window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['catalogue'] = true;
(function () {
  'use strict';

  var S = {
    summary: null, group: null, siteId: 0, kind: null,
    spec: null, items: [], loading: false, error: null,
    q: '', filter: 'all', sort: 'page', view: 'grid',
    drawer: null, lastFocus: null
  };

  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function keep(k, v) { try { localStorage.setItem('lu_cat_' + k, String(v)); } catch (_e) {} }
  function recall(k, d) { try { var v = localStorage.getItem('lu_cat_' + k); return v === null ? d : v; } catch (_e) { return d; } }
  function auth() { return { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' }; }
  function toast(m, t) { if (typeof showToast === 'function') showToast(m, t || 'success'); }
  function plural(n, one, many) { return n === 1 ? one : many; }

  /* One request helper for every write: returns the parsed body or throws with the server's own wording. */
  async function send(method, path, body, isForm) {
    var opt = { method: method, headers: auth() };
    if (body !== undefined && body !== null) {
      if (isForm) { opt.body = body; }
      else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    }
    var r = await fetch(API + 'builder/websites/' + S.siteId + '/' + path, opt);
    var j = null; try { j = await r.json(); } catch (_e) {}
    if (!r.ok || !j || j.success === false) { throw new Error((j && (j.message || j.error)) || ('HTTP ' + r.status)); }
    return j;
  }

  /* ─────────────────────────────── styles ─────────────────────────────── */
  function css() {
    if (document.getElementById('cat-view-css')) return;
    var st = document.createElement('style'); st.id = 'cat-view-css';
    st.textContent = [
      '#catalogue-root{padding:24px 24px 64px;max-width:1280px}',
      '#catalogue-root .cat-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}',
      '#catalogue-root h1{font:700 24px/1.2 var(--fh);color:var(--t1);margin:0 0 4px}',
      '#catalogue-root .cat-sub{font-size:13px;color:var(--t3);max-width:62ch}',
      '#catalogue-root .cat-acts{display:flex;gap:8px;align-items:center;flex-wrap:wrap}',

      /* company switcher */
      '#catalogue-root .cat-co{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 16px}',
      '#catalogue-root .cat-co-lab{font-size:12px;color:var(--t3)}',
      '#catalogue-root .cat-seg{display:inline-flex;background:var(--s2);border:1px solid var(--bd);border-radius:var(--r,10px);padding:3px;gap:2px;max-width:100%;overflow-x:auto}',
      '#catalogue-root .cat-seg button{font:600 12.5px/1 var(--fb);color:var(--t2);background:none;border:0;border-radius:calc(var(--r,10px) - 3px);padding:9px 14px;cursor:pointer;white-space:nowrap}',
      '#catalogue-root .cat-seg button[aria-pressed=true]{background:var(--p);color:#fff}',

      /* KPI strip — the Command Center card idiom */
      '#catalogue-root .cat-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:0 0 18px}',
      '#catalogue-root .cat-kpi{background:var(--s1);border:1px solid var(--bd);border-radius:var(--r,12px);padding:14px 16px}',
      '#catalogue-root .cat-kpi b{display:block;font:700 24px/1 var(--fh);color:var(--t1);margin-bottom:5px;font-variant-numeric:tabular-nums}',
      '#catalogue-root .cat-kpi span{font-size:12px;color:var(--t3)}',
      '#catalogue-root .cat-kpi.warn b{color:var(--am,#F59E0B)}',
      '#catalogue-root .cat-kpi button{all:unset;cursor:pointer;display:block;width:100%}',
      '#catalogue-root .cat-kpi button:focus-visible{outline:2px solid var(--p);outline-offset:2px}',

      /* kind tabs */
      '#catalogue-root .cat-kinds{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px;border-bottom:1px solid var(--bd);padding-bottom:0}',
      '#catalogue-root .cat-kind{background:none;border:0;border-bottom:2px solid transparent;color:var(--t3);font:600 13px/1 var(--fb);padding:10px 12px;cursor:pointer}',
      '#catalogue-root .cat-kind[aria-selected=true]{color:var(--t1);border-bottom-color:var(--p)}',

      /* toolbar */
      '#catalogue-root .cat-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 14px}',
      '#catalogue-root .cat-search{flex:1 1 240px;min-width:0;max-width:360px;position:relative}',
      '#catalogue-root .cat-search input{width:100%;box-sizing:border-box;min-height:40px;background:var(--s2);border:1px solid var(--bd2,var(--bd));border-radius:var(--r,10px);color:var(--t1);padding:9px 34px 9px 12px;font:400 13.5px var(--fb)}',
      '#catalogue-root .cat-search button{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;color:var(--t3);font-size:16px;cursor:pointer;padding:4px 6px;line-height:1}',
      '#catalogue-root .cat-chips{display:flex;gap:6px;flex-wrap:wrap}',
      '#catalogue-root .cat-chip{font:600 12px/1 var(--fb);color:var(--t2);background:var(--s2);border:1px solid var(--bd);border-radius:999px;padding:8px 12px;cursor:pointer}',
      '#catalogue-root .cat-chip[aria-pressed=true]{background:var(--p);border-color:var(--p);color:#fff}',
      '#catalogue-root .cat-chip .n{opacity:.7;margin-left:5px;font-variant-numeric:tabular-nums}',
      '#catalogue-root .cat-right{margin-left:auto;display:flex;gap:8px;align-items:center}',
      '#catalogue-root .cat-view{display:inline-flex;background:var(--s2);border:1px solid var(--bd);border-radius:var(--r,10px);padding:3px;gap:2px}',
      '#catalogue-root .cat-view button{background:none;border:0;border-radius:calc(var(--r,10px) - 3px);color:var(--t3);padding:7px 11px;cursor:pointer;font:600 12px/1 var(--fb);display:inline-flex;align-items:center;gap:5px}',
      '#catalogue-root .cat-view button[aria-pressed=true]{background:var(--s1);color:var(--t1);box-shadow:0 1px 3px rgba(0,0,0,.18)}',

      /* off banner */
      '#catalogue-root .cat-off{display:flex;gap:12px;align-items:center;flex-wrap:wrap;background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);border-radius:var(--r,12px);padding:12px 14px;margin:0 0 14px;font-size:13px;color:var(--t2)}',
      '#catalogue-root .cat-off b{color:var(--t1)}',

      /* grid */
      '#catalogue-root .cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}',
      '#catalogue-root .cat-card{position:relative;text-align:left;background:var(--s1);border:1px solid var(--bd);border-radius:var(--r,12px);overflow:hidden;cursor:pointer;padding:0;font:inherit;color:inherit;display:flex;flex-direction:column;transition:border-color .15s,transform .15s}',
      '#catalogue-root .cat-card:hover{border-color:var(--p);transform:translateY(-2px)}',
      '#catalogue-root .cat-card:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '#catalogue-root .cat-card .ph{aspect-ratio:4/3;background:var(--s2);position:relative;overflow:hidden}',
      '#catalogue-root .cat-card .ph img{width:100%;height:100%;object-fit:cover;display:block}',
      '#catalogue-root .cat-card .ph .none{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:11.5px;color:var(--t3);text-align:center;padding:10px;gap:6px;flex-direction:column}',
      '#catalogue-root .cat-card .body{padding:12px 13px 13px;display:flex;flex-direction:column;gap:4px;flex:1}',
      '#catalogue-root .cat-card .ttl{font:600 13.5px/1.35 var(--fb);color:var(--t1);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
      '#catalogue-root .cat-card .price{font:700 14px/1 var(--fh);color:var(--t1);font-variant-numeric:tabular-nums}',
      '#catalogue-root .cat-card .meta{font-size:11.5px;color:var(--t3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '#catalogue-root .cat-flags{position:absolute;top:8px;left:8px;right:8px;display:flex;gap:5px;align-items:flex-start;flex-wrap:wrap;pointer-events:none}',
      '#catalogue-root .cat-pill{font:700 10px/1 var(--fb);letter-spacing:.08em;text-transform:uppercase;padding:5px 8px;border-radius:999px;background:var(--p);color:#fff}',
      '#catalogue-root .cat-pill.off{background:var(--s3,#3a3f52);color:var(--t2)}',
      '#catalogue-root .cat-pill.closed{background:#4b5563;color:#fff}',
      '#catalogue-root .cat-pill.star{background:#F59E0B;color:#1a1400;margin-left:auto}',
      '#catalogue-root .cat-pill.home{background:rgba(0,0,0,.55);color:#fff;backdrop-filter:blur(4px)}',

      /* table */
      '#catalogue-root .cat-tw{border:1px solid var(--bd);border-radius:var(--r,12px);overflow:hidden;background:var(--s1)}',
      '#catalogue-root table.cat-t{width:100%;border-collapse:collapse;font-size:13px;min-width:720px}',
      '#catalogue-root .cat-t th{text-align:left;font:600 11.5px/1 var(--fb);letter-spacing:.04em;text-transform:uppercase;color:var(--t3);padding:12px 12px;border-bottom:1px solid var(--bd);white-space:nowrap;background:var(--s2)}',
      '#catalogue-root .cat-t th button{all:unset;cursor:pointer;display:inline-flex;gap:4px;align-items:center}',
      '#catalogue-root .cat-t th button:focus-visible{outline:2px solid var(--p);outline-offset:2px}',
      '#catalogue-root .cat-t td{padding:10px 12px;border-bottom:1px solid var(--bd);color:var(--t2);vertical-align:middle}',
      '#catalogue-root .cat-t tr:last-child td{border-bottom:0}',
      '#catalogue-root .cat-t tbody tr{cursor:pointer}',
      '#catalogue-root .cat-t tbody tr:hover{background:var(--s2)}',
      '#catalogue-root .cat-t tbody tr:focus-visible{outline:2px solid var(--p);outline-offset:-2px}',
      '#catalogue-root .cat-t .th{width:46px;height:46px;border-radius:8px;object-fit:cover;background:var(--s2);display:block}',
      '#catalogue-root .cat-t .nm{color:var(--t1);font-weight:600}',
      '#catalogue-root .cat-t .num{font-variant-numeric:tabular-nums;white-space:nowrap}',

      /* drawer */
      '.cat-scrim{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(3px);z-index:var(--z-topbar,9000);opacity:0;transition:opacity .2s}',
      '.cat-scrim.in{opacity:1}',
      /* the drawer is modal: the Sarah floater must not sit over its Remove button */
      'body:has(.cat-dr) #lu-messages-floater,body:has(.cat-dr) .ai-fab{display:none!important}',
      '.cat-dr{position:fixed;top:0;right:0;bottom:0;width:min(520px,100vw);background:var(--s1);border-left:1px solid var(--bd);z-index:calc(var(--z-topbar,9000) + 1);display:flex;flex-direction:column;transform:translateX(100%);transition:transform .25s cubic-bezier(.4,0,.2,1)}',
      '.cat-dr.in{transform:none}',
      '.cat-dr-h{display:flex;align-items:center;gap:10px;padding:16px 18px;border-bottom:1px solid var(--bd);flex-shrink:0}',
      '.cat-dr-h .t{font:700 15px var(--fh);color:var(--t1);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '.cat-dr-x{background:none;border:0;color:var(--t2);font-size:20px;line-height:1;cursor:pointer;padding:4px 8px;border-radius:8px}',
      '.cat-dr-x:hover{background:var(--s2);color:var(--t1)}',
      '.cat-dr-b{flex:1;overflow-y:auto;padding:16px 18px 20px}',
      '.cat-dr-f{flex-shrink:0;display:flex;gap:8px;align-items:center;padding:12px 18px;border-top:1px solid var(--bd);background:var(--s1)}',
      '.cat-dr-b label{display:block;font:600 11.5px/1 var(--fb);color:var(--t2);margin:14px 0 5px;letter-spacing:.03em}',
      '.cat-dr-b input[type=text],.cat-dr-b input[type=number],.cat-dr-b textarea,.cat-dr-b select{width:100%;box-sizing:border-box;background:var(--s2);border:1px solid var(--bd2,var(--bd));border-radius:var(--r,10px);color:var(--t1);padding:10px 12px;font:400 13.5px var(--fb);min-height:42px}',
      '.cat-dr-b textarea{min-height:82px;resize:vertical;line-height:1.5}',
      '.cat-dr-b input:focus,.cat-dr-b textarea:focus,.cat-dr-b select:focus{outline:none;border-color:var(--p)}',
      '.cat-dr-b .row3{display:grid;grid-template-columns:1.4fr .8fr 1fr;gap:8px}',
      '.cat-dr-b .seg{display:flex;flex-wrap:wrap;gap:5px}',
      '.cat-dr-b .seg button{background:var(--s2);border:1px solid var(--bd);border-radius:999px;color:var(--t2);font:600 12px/1 var(--fb);padding:9px 13px;cursor:pointer}',
      '.cat-dr-b .seg button[aria-pressed=true]{background:var(--p);border-color:var(--p);color:#fff}',
      '.cat-dr-b .sw{display:flex;align-items:center;gap:9px;font-size:13px;color:var(--t2);margin:16px 0 0;cursor:pointer}',
      '.cat-dr-b .hint{font-size:11.5px;color:var(--t3);margin:5px 0 0;line-height:1.5}',
      '.cat-dr-b .err{font-size:12.5px;color:#F87171;margin:10px 0 0;min-height:16px}',
      '.cat-ph{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:8px;margin-top:6px}',
      '.cat-ph figure{position:relative;margin:0;aspect-ratio:1;border-radius:10px;overflow:hidden;background:var(--s2);border:1px solid var(--bd)}',
      '.cat-ph figure.cover{border-color:var(--p);box-shadow:0 0 0 1px var(--p)}',
      '.cat-ph img{width:100%;height:100%;object-fit:cover;display:block}',
      '.cat-ph .tools{position:absolute;inset:auto 0 0 0;display:flex;background:rgba(0,0,0,.62);backdrop-filter:blur(3px)}',
      '.cat-ph .tools button{flex:1;background:none;border:0;color:#fff;font-size:12px;line-height:1;padding:6px 0;cursor:pointer}',
      '.cat-ph .tools button:hover{background:rgba(255,255,255,.18)}',
      '.cat-ph .tools button[disabled]{opacity:.35;cursor:default}',
      '.cat-ph .badge{position:absolute;top:5px;left:5px;font:700 9px/1 var(--fb);letter-spacing:.08em;text-transform:uppercase;background:var(--p);color:#fff;padding:4px 6px;border-radius:5px}',
      '.cat-link{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--p);text-decoration:none;word-break:break-all}',
      '.cat-link:hover{text-decoration:underline}',
      '.cat-danger{color:#F87171!important;border-color:rgba(248,113,113,.4)!important}',

      /* phone */
      '@media (max-width:767px){',
      '  #catalogue-root{padding:14px 12px 72px}',
      '  #catalogue-root h1{font-size:20px}',
      '  #catalogue-root .cat-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}',
      '  #catalogue-root .cat-kpi{padding:11px 12px}#catalogue-root .cat-kpi b{font-size:19px}',
      '  #catalogue-root .cat-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}',
      '  #catalogue-root .cat-search{max-width:none;flex-basis:100%}',
      '  #catalogue-root .cat-right{margin-left:0;width:100%;justify-content:space-between}',
      '  #catalogue-root .cat-tw{overflow-x:auto;-webkit-overflow-scrolling:touch}',
      '  .cat-dr{width:100vw;border-left:0}',
      '  .cat-dr-b .row3{grid-template-columns:1fr}',
      '}'
    ].join('');
    document.head.appendChild(st);
  }

  /* ─────────────────────────────── data ─────────────────────────────── */
  function groupOf(slug) {
    var gs = (S.summary && S.summary.groups) || [];
    return gs.filter(function (g) { return g.slug === slug; })[0] || gs[0] || null;
  }
  function siteById(id) { return ((S.summary && S.summary.websites) || []).filter(function (s) { return s.id === id; })[0] || null; }

  async function loadItems() {
    S.loading = true; S.error = null; paint();
    try {
      var r = await fetch(API + 'builder/websites/' + S.siteId + '/catalogue', { headers: auth(), cache: 'no-store' });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      var d = await r.json();
      var kinds = d && d.catalogues ? Object.keys(d.catalogues) : [];
      S.allKinds = kinds.map(function (k) { return { kind: k, label: d.catalogues[k].label, count: (d.catalogues[k].items || []).length }; });
      if (!kinds.length) { S.spec = null; S.items = []; }
      else {
        if (!S.kind || kinds.indexOf(S.kind) < 0) S.kind = kinds[0];
        var c = d.catalogues[S.kind];
        S.spec = c; S.items = c.items || [];
      }
    } catch (e) { S.error = e.message; S.spec = null; S.items = []; }
    S.loading = false; paint();
  }

  /* ─────────────────────────────── derived ─────────────────────────────── */
  function isClosed(it) { return ((S.spec.closed_statuses || []).indexOf(it.status) >= 0); }
  function isLive(it) { return ((S.spec.open || []).indexOf(it.status) >= 0); }
  function isHidden(it) { return !isLive(it) && !isClosed(it); }
  function hasPhoto(it) { return !!(it.photos && it.photos.length); }

  /* The home page shows the first `slots` of the live items, in the order the server delivers them. */
  function homeIds() {
    var n = parseInt(S.spec.slots, 10) || 0;
    return S.items.filter(isLive).slice(0, n).map(function (it) { return it.id; });
  }

  function counts() {
    var c = { all: S.items.length, live: 0, hidden: 0, closed: 0, featured: 0, nophoto: 0 };
    S.items.forEach(function (it) {
      if (isLive(it)) c.live++; else if (isClosed(it)) c.closed++; else c.hidden++;
      if (it.featured) c.featured++;
      if (!hasPhoto(it)) c.nophoto++;
    });
    return c;
  }

  function haystack(it) {
    var bits = [it.title, it.summary, it.specs_display, it.price_display, it.status_label];
    Object.keys(it.attrs || {}).forEach(function (k) { var v = it.attrs[k]; if (v !== null && v !== undefined && typeof v !== 'object') bits.push(String(v)); });
    return bits.filter(Boolean).join(' ').toLowerCase();
  }

  function visible() {
    var q = S.q.trim().toLowerCase();
    var out = S.items.filter(function (it) {
      if (S.filter === 'live' && !isLive(it)) return false;
      if (S.filter === 'hidden' && !isHidden(it)) return false;
      if (S.filter === 'closed' && !isClosed(it)) return false;
      if (S.filter === 'featured' && !it.featured) return false;
      if (S.filter === 'nophoto' && hasPhoto(it)) return false;
      if (q && haystack(it).indexOf(q) < 0) return false;
      return true;
    });
    var byNum = function (a, b, dir) { var x = a === null ? null : parseFloat(a), y = b === null ? null : parseFloat(b); if (x === null && y === null) return 0; if (x === null) return 1; if (y === null) return -1; return dir * (x - y); };
    if (S.sort === 'name') out.sort(function (a, b) { return String(a.title).localeCompare(String(b.title)); });
    else if (S.sort === 'price_hi') out.sort(function (a, b) { return byNum(a.price, b.price, -1); });
    else if (S.sort === 'price_lo') out.sort(function (a, b) { return byNum(a.price, b.price, 1); });
    else if (S.sort === 'updated') out.sort(function (a, b) { return String(b.updated_at || '').localeCompare(String(a.updated_at || '')); });
    return out;   /* 'page' = the order the server gave us: featured first, then the page order */
  }

  function publicUrl(it) {
    var site = siteById(S.siteId); if (!site || !S.spec) return '';
    var host = site.host ? ('https://' + site.host) : '';
    if (!host) return '';
    if (S.spec.pages === 'index+detail' && it && it.slug) return host + '/' + S.spec.detail_prefix + '-' + it.slug + '/';
    return host + '/' + S.spec.page_slug + '/';
  }

  /* ─────────────────────────────── paint ─────────────────────────────── */
  var ROOT = null;

  function paint() {
    if (!ROOT) return;
    var d = S.summary, gs = (d && d.groups) || [];
    if (!gs.length) {
      ROOT.innerHTML = head('Catalogue', 'What your websites sell — properties, packages, a menu, treatments — managed in one place.')
        + '<div class="lu-empty"><b>Nothing to manage yet</b>None of your websites carries a catalogue. Designs that sell something get one automatically; ask Sarah or Arthur to add one to a site.</div>';
      document.title = 'Catalogue · ' + ((window.LU_CFG && window.LU_CFG.bn) || 'LevelUpGrowth');
      return;
    }
    var g = S.group; var ids = g.websites || [];
    window._luCatalogueGroup = g.slug;
    document.querySelectorAll('.nav-item[data-cat-group]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-cat-group') === g.slug); });
    if (!S.siteId || ids.indexOf(S.siteId) < 0) {
      var last = parseInt(recall(g.slug, '0'), 10) || 0;
      S.siteId = ids.indexOf(last) >= 0 ? last : ids[0];
    }
    var site = siteById(S.siteId);
    if (!site) { ROOT.innerHTML = head(g.label, '') + '<div class="lu-empty"><b>That website is gone</b>Pick another company above.</div>'; return; }
    document.title = g.label + ' · ' + ((window.LU_CFG && window.LU_CFG.bn) || 'LevelUpGrowth');

    var h = head(g.label, subtitle(g, ids, site));
    h += company(g, ids);
    if (S.loading) { h += skeleton(); ROOT.innerHTML = h; wireShell(g); return; }
    if (S.error) {
      h += '<div class="lu-empty"><b>Couldn’t load this catalogue</b>' + esc(S.error) + '<div style="margin-top:12px"><button type="button" class="lu-btn lu-btn--sm" id="cat-retry">Try again</button></div></div>';
      ROOT.innerHTML = h; wireShell(g); var rb = ROOT.querySelector('#cat-retry'); if (rb) rb.onclick = loadItems; return;
    }
    if (!S.spec) {
      h += '<div class="lu-empty"><b>No catalogue on this website’s design</b>This design has no list of things to sell. Ask Arthur for a design that carries one, or pick another company.</div>';
      ROOT.innerHTML = h; wireShell(g); return;
    }

    h += kinds();
    h += kpis();
    if (!S.spec.enabled) h += offBanner();
    h += toolbar();
    var rows = visible();
    if (!S.items.length) h += emptyAll();
    else if (!rows.length) h += emptyFiltered();
    else h += (S.view === 'table' ? table(rows) : grid(rows));

    ROOT.innerHTML = h;
    wireShell(g); wireBody();
  }

  function head(title, sub) {
    return '<div class="cat-top"><div><h1 id="cat-title">' + esc(title) + '</h1>'
      + (sub ? '<div class="cat-sub">' + sub + '</div>' : '') + '</div>'
      + '<div class="cat-acts" id="cat-acts"></div></div>';
  }

  function subtitle(g, ids, site) {
    if (ids.length > 1) return esc(String(ids.length)) + ' of your websites sell ' + esc(g.label.toLowerCase()) + '. Each company keeps its own list, and every change shows on its live site right away.';
    return 'Everything ' + esc(site.name) + ' sells. Every change shows on the live site right away — the home page and the ' + esc(g.label.toLowerCase()) + ' page.';
  }

  /* Owner's choice (RFC-0012 decision 3): segmented at three or fewer, the picker beyond. */
  function company(g, ids) {
    if (ids.length < 2) return '';
    if (ids.length <= 3) {
      return '<div class="cat-co"><span class="cat-co-lab">Company</span><div class="cat-seg" role="group" aria-label="Company">'
        + ids.map(function (id) { var s = siteById(id); return s ? '<button type="button" data-site="' + s.id + '" aria-pressed="' + (s.id === S.siteId ? 'true' : 'false') + '">' + esc(s.name) + '</button>' : ''; }).join('')
        + '</div></div>';
    }
    return '<div class="cat-co"><label class="cat-co-lab" for="cat-site">Company</label><select id="cat-site" style="min-width:240px">'
      + ids.map(function (id) { var s = siteById(id); return s ? '<option value="' + s.id + '"' + (s.id === S.siteId ? ' selected' : '') + '>' + esc(s.name) + '</option>' : ''; }).join('')
      + '</select></div>';
  }

  function kinds() {
    var ks = S.allKinds || [];
    if (ks.length < 2) return '';
    return '<div class="cat-kinds" role="tablist">' + ks.map(function (k) {
      return '<button type="button" role="tab" class="cat-kind" data-kind="' + esc(k.kind) + '" aria-selected="' + (k.kind === S.kind ? 'true' : 'false') + '">' + esc(k.label) + ' (' + k.count + ')</button>';
    }).join('') + '</div>';
  }

  /* Only what this page can count honestly. Enquiries (P2) and views (P3) are absent, not zero. */
  function kpis() {
    var c = counts();
    var cell = function (filter, n, label, warn) {
      return '<div class="cat-kpi' + (warn && n > 0 ? ' warn' : '') + '"><button type="button" data-kpi="' + filter + '" aria-label="Show ' + esc(label) + '">'
        + '<b>' + n + '</b><span>' + esc(label) + '</span></button></div>';
    };
    return '<div class="cat-kpis">'
      + cell('live', c.live, 'Live on the site')
      + cell('hidden', c.hidden, 'Hidden')
      + (S.spec.closed_statuses && S.spec.closed_statuses.length ? cell('closed', c.closed, S.spec.closed_label || 'Closed') : '')
      + cell('featured', c.featured, 'Featured')
      /* Owner 2026-09-24: not a fault. An item with no photo of its own renders as a clean text row on the
         live site (or keeps the design's own picture), so this count is an opportunity, not a warning. */
      + cell('nophoto', c.nophoto, 'Missing a photo')
      + '</div>';
  }

  function offBanner() {
    return '<div class="cat-off"><div style="flex:1;min-width:200px"><b>' + esc(S.spec.label) + ' are switched off for this website.</b><br>'
      + 'The ' + esc(S.spec.label.toLowerCase()) + ' page is removed from the live site until you switch it back on. Your items are kept.</div>'
      + '<button type="button" class="lu-btn lu-btn--sm" data-a="turn-on">Show on the site</button></div>';
  }

  function toolbar() {
    var c = counts();
    var chip = function (k, label, n) {
      return '<button type="button" class="cat-chip" data-filter="' + k + '" aria-pressed="' + (S.filter === k ? 'true' : 'false') + '">' + esc(label) + '<span class="n">' + n + '</span></button>';
    };
    return '<div class="cat-bar">'
      + '<div class="cat-search"><input type="search" id="cat-q" value="' + esc(S.q) + '" placeholder="Search ' + esc(S.spec.label.toLowerCase()) + '…" aria-label="Search ' + esc(S.spec.label.toLowerCase()) + '" autocomplete="off">'
      + (S.q ? '<button type="button" id="cat-q-x" aria-label="Clear search">×</button>' : '') + '</div>'
      + '<div class="cat-chips">'
      + chip('all', 'All', c.all) + chip('live', 'Live', c.live) + chip('hidden', 'Hidden', c.hidden)
      + (c.closed || (S.spec.closed_statuses || []).length ? chip('closed', S.spec.closed_label || 'Closed', c.closed) : '')
      + chip('featured', 'Featured', c.featured)
      + (c.nophoto ? chip('nophoto', 'No photo', c.nophoto) : '')
      + '</div>'
      + '<div class="cat-right">'
      + '<select id="cat-sort" aria-label="Sort" style="min-height:40px">'
      + ['page:Page order', 'name:Name A–Z', 'price_hi:Price, high first', 'price_lo:Price, low first', 'updated:Recently changed'].map(function (o) {
        var p = o.split(':'); return '<option value="' + p[0] + '"' + (S.sort === p[0] ? ' selected' : '') + '>' + esc(p[1]) + '</option>';
      }).join('') + '</select>'
      + '<div class="cat-view" role="group" aria-label="View">'
      + '<button type="button" data-view="grid" aria-pressed="' + (S.view === 'grid' ? 'true' : 'false') + '">Grid</button>'
      + '<button type="button" data-view="table" aria-pressed="' + (S.view === 'table' ? 'true' : 'false') + '">Table</button>'
      + '</div></div></div>';
  }

  function skeleton() {
    var one = '<div class="lu-skel" style="height:190px;border-radius:12px"></div>';
    return '<div class="cat-kpis">' + [1, 2, 3, 4].map(function () { return '<div class="lu-skel" style="height:72px;border-radius:12px"></div>'; }).join('') + '</div>'
      + '<div class="lu-skel" style="height:40px;width:60%;margin-bottom:14px"></div>'
      + '<div class="cat-grid">' + [1, 2, 3, 4, 5, 6, 7, 8].map(function () { return one; }).join('') + '</div>';
  }

  function emptyAll() {
    return '<div class="lu-empty"><b>No ' + esc(S.spec.label.toLowerCase()) + ' yet</b>'
      + 'Add your first ' + esc(S.spec.singular) + ' and it appears on the home page and on the ' + esc(S.spec.label.toLowerCase()) + ' page straight away. You can also tell Arthur: “Add a ' + esc(S.spec.singular) + ': …”.'
      + '<div style="margin-top:14px"><button type="button" class="lu-btn" data-a="add">+ Add ' + esc(S.spec.singular) + '</button></div></div>';
  }

  function emptyFiltered() {
    return '<div class="lu-empty"><b>Nothing matches</b>No ' + esc(S.spec.label.toLowerCase()) + ' match this search or filter.'
      + '<div style="margin-top:14px"><button type="button" class="lu-btn lu-btn--sm" data-a="clear">Clear search and filters</button></div></div>';
  }

  function flags(it, home) {
    var f = '';
    if (isClosed(it)) f += '<span class="cat-pill closed">' + esc(it.status_label) + '</span>';
    else if (isHidden(it)) f += '<span class="cat-pill off">' + esc(it.status_label) + '</span>';
    else if (it.status !== S.spec.default_status || S.spec.kind === 'listing') f += '<span class="cat-pill">' + esc(it.status_label) + '</span>';
    if (home) f += '<span class="cat-pill home" title="Shown on the home page">Home</span>';
    if (it.featured) f += '<span class="cat-pill star" title="Featured">★</span>';
    return f;
  }

  function grid(rows) {
    var home = homeIds();
    return '<div class="cat-grid">' + rows.map(function (it) {
      var photo = (it.photos && it.photos[0]) || '';
      var meta = [it.attrs && it.attrs.location ? it.attrs.location : '', it.specs_display || ''].filter(Boolean).join(' · ') || (it.summary || '');
      return '<button type="button" class="cat-card" data-id="' + it.id + '">'
        + '<div class="ph">' + (photo ? '<img src="' + esc(photo) + '" alt="" loading="lazy">' : '<div class="none"><img src="/img/logo-icon-40.png" alt="" width="28" height="28" style="width:28px;height:28px;object-fit:contain;opacity:.35;display:block;margin:0 auto 6px">No photo</div>')
        + '<div class="cat-flags">' + flags(it, home.indexOf(it.id) >= 0) + '</div></div>'
        + '<div class="body">'
        + (it.price !== null || it.price_label ? '<div class="price">' + esc(it.price_display) + '</div>' : '')
        + '<div class="ttl">' + esc(it.title) + '</div>'
        + (meta ? '<div class="meta">' + esc(meta) + '</div>' : '')
        + '</div></button>';
    }).join('') + '</div>';
  }

  function table(rows) {
    var home = homeIds();
    var th = function (key, label, cls) {
      var on = S.sort === key || (key === 'price' && (S.sort === 'price_hi' || S.sort === 'price_lo'));
      return '<th' + (cls ? ' class="' + cls + '"' : '') + '><button type="button" data-sort="' + key + '">' + esc(label) + (on ? ' <span aria-hidden="true">▾</span>' : '') + '</button></th>';
    };
    return '<div class="cat-tw lu-tscroll"><table class="cat-t"><thead><tr>'
      + '<th style="width:58px"><span aria-label="Photo"></span></th>'
      + th('name', 'Name') + '<th>Status</th>' + th('price', 'Price') + '<th>Details</th>'
      + th('updated', 'Changed') + '<th style="width:86px">On site</th>'
      + '</tr></thead><tbody>'
      + rows.map(function (it) {
        var photo = (it.photos && it.photos[0]) || '';
        var when = it.updated_at ? new Date(String(it.updated_at).replace(' ', 'T')) : null;
        var whenTxt = when && !isNaN(when) ? when.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '—';
        return '<tr data-id="' + it.id + '" tabindex="0">'
          + '<td>' + (photo ? '<img class="th' + (photo ? '' : ' nophoto') + '" src="' + esc(photo || '/img/logo-icon-40.png') + '" alt="" loading="lazy">' : '<span class="th" style="display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--t3)">🖼</span>') + '</td>'
          + '<td><span class="nm">' + esc(it.title) + '</span>' + (it.featured ? ' <span title="Featured">★</span>' : '') + '</td>'
          + '<td>' + flags(it, false) + '</td>'
          + '<td class="num">' + esc(it.price !== null || it.price_label ? it.price_display : '—') + '</td>'
          + '<td>' + esc(it.specs_display || (it.attrs && it.attrs.location) || '—') + '</td>'
          + '<td class="num">' + esc(whenTxt) + '</td>'
          + '<td>' + (home.indexOf(it.id) >= 0 ? '<span class="cat-pill home">Home</span>' : (isLive(it) ? '<span style="font-size:12px;color:var(--t3)">Page</span>' : '<span style="font-size:12px;color:var(--t3)">—</span>')) + '</td>'
          + '</tr>';
      }).join('')
      + '</tbody></table></div>';
  }

  /* ─────────────────────────────── wiring ─────────────────────────────── */
  function wireShell(g) {
    var acts = ROOT.querySelector('#cat-acts');
    if (acts && S.spec) {
      acts.innerHTML = '<button type="button" class="lu-btn lu-btn--sm" data-a="settings">Settings</button>'
        + '<button type="button" class="lu-btn" data-a="add"' + (S.spec.enabled ? '' : ' disabled title="Switch the catalogue on first"') + '>+ Add ' + esc(S.spec.singular) + '</button>';
    }
    ROOT.querySelectorAll('[data-site]').forEach(function (b) {
      b.onclick = function () { pickSite(parseInt(b.getAttribute('data-site'), 10), g); };
    });
    var sel = ROOT.querySelector('#cat-site');
    if (sel) sel.onchange = function () { pickSite(parseInt(sel.value, 10), g); };
    ROOT.querySelectorAll('.cat-kind[data-kind]').forEach(function (b) {
      b.onclick = function () { S.kind = b.getAttribute('data-kind'); S.q = ''; S.filter = 'all'; loadItems(); };
    });
  }

  function pickSite(id, g) {
    if (!id || id === S.siteId) return;
    S.siteId = id; S.kind = null; S.q = ''; S.filter = 'all';
    keep(g.slug, id);
    loadItems();
  }

  function wireBody() {
    ROOT.querySelectorAll('[data-kpi]').forEach(function (b) {
      b.onclick = function () { S.filter = b.getAttribute('data-kpi'); paint(); };
    });
    ROOT.querySelectorAll('[data-filter]').forEach(function (b) {
      b.onclick = function () { S.filter = b.getAttribute('data-filter'); paint(); };
    });
    var q = ROOT.querySelector('#cat-q');
    if (q) {
      q.oninput = function () {
        S.q = q.value;
        var rows = visible();
        var host = ROOT.querySelector('.cat-grid') || ROOT.querySelector('.cat-tw') || ROOT.querySelector('.lu-empty');
        /* repaint only the list, so the cursor stays in the box */
        var fresh = !S.items.length ? emptyAll() : (!rows.length ? emptyFiltered() : (S.view === 'table' ? table(rows) : grid(rows)));
        if (host) { var tmp = document.createElement('div'); tmp.innerHTML = fresh; host.replaceWith(tmp.firstElementChild); wireRows(); }
        var x = ROOT.querySelector('#cat-q-x');
        if (S.q && !x) { var b = document.createElement('button'); b.type = 'button'; b.id = 'cat-q-x'; b.setAttribute('aria-label', 'Clear search'); b.textContent = '×'; b.onclick = clearSearch; q.parentNode.appendChild(b); }
        if (!S.q && x) x.remove();
      };
    }
    var qx = ROOT.querySelector('#cat-q-x'); if (qx) qx.onclick = clearSearch;
    var sort = ROOT.querySelector('#cat-sort');
    if (sort) sort.onchange = function () { S.sort = sort.value; keep('sort', S.sort); paint(); };
    ROOT.querySelectorAll('[data-view]').forEach(function (b) {
      b.onclick = function () { S.view = b.getAttribute('data-view'); keep('view', S.view); paint(); };
    });
    ROOT.querySelectorAll('[data-sort]').forEach(function (b) {
      b.onclick = function () {
        var k = b.getAttribute('data-sort');
        if (k === 'price') S.sort = S.sort === 'price_hi' ? 'price_lo' : 'price_hi';
        else if (k === 'name') S.sort = S.sort === 'name' ? 'page' : 'name';
        else if (k === 'updated') S.sort = S.sort === 'updated' ? 'page' : 'updated';
        keep('sort', S.sort); paint();
      };
    });
    ROOT.querySelectorAll('[data-a=add]').forEach(function (b) { b.onclick = function () { openDrawer(null); }; });
    var clr = ROOT.querySelector('[data-a=clear]'); if (clr) clr.onclick = function () { S.q = ''; S.filter = 'all'; paint(); };
    var on = ROOT.querySelector('[data-a=turn-on]'); if (on) on.onclick = function () { setEnabled(true, on); };
    var set = ROOT.querySelector('[data-a=settings]'); if (set) set.onclick = openSettings;
    wireRows();
  }

  function clearSearch() { S.q = ''; paint(); var q = ROOT.querySelector('#cat-q'); if (q) q.focus(); }

  function wireRows() {
    ROOT.querySelectorAll('.cat-card[data-id]').forEach(function (c) {
      c.onclick = function () { openDrawer(itemById(parseInt(c.getAttribute('data-id'), 10))); };
    });
    ROOT.querySelectorAll('.cat-t tbody tr[data-id]').forEach(function (r) {
      var go = function () { openDrawer(itemById(parseInt(r.getAttribute('data-id'), 10))); };
      r.onclick = go;
      r.onkeydown = function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } };
    });
  }

  function itemById(id) { return S.items.filter(function (x) { return x.id === id; })[0] || null; }

  async function setEnabled(on, btn) {
    if (btn) { btn.disabled = true; btn.textContent = on ? 'Switching on…' : 'Switching off…'; }
    try {
      var j = await send('PUT', 'catalogue/' + S.kind + '/settings', { enabled: !!on });
      toast(j.message || (on ? 'Now shown on the site.' : 'Hidden from the site.'));
      await loadItems();
    } catch (e) { toast('Couldn’t change that — ' + e.message, 'error'); if (btn) { btn.disabled = false; btn.textContent = on ? 'Show on the site' : 'Hide'; } }
  }

  /* ─────────────────────────────── drawer ─────────────────────────────── */
  function closeDrawer() {
    if (!S.drawer) return;
    var d = S.drawer; S.drawer = null;
    d.panel.classList.remove('in'); d.scrim.classList.remove('in');
    document.removeEventListener('keydown', d.onKey, true);
    setTimeout(function () { try { d.panel.remove(); d.scrim.remove(); } catch (_e) {} }, 240);
    if (S.lastFocus && S.lastFocus.focus) { try { S.lastFocus.focus(); } catch (_e) {} }
  }

  function shell(title) {
    var scrim = document.createElement('div'); scrim.className = 'cat-scrim';
    var panel = document.createElement('div'); panel.className = 'cat-dr'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'true'); panel.setAttribute('aria-label', title);
    panel.innerHTML = '<div class="cat-dr-h"><div class="t">' + esc(title) + '</div><button type="button" class="cat-dr-x" aria-label="Close">×</button></div>'
      + '<div class="cat-dr-b"></div><div class="cat-dr-f"></div>';
    document.body.appendChild(scrim); document.body.appendChild(panel);
    var onKey = function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeDrawer(); } };
    document.addEventListener('keydown', onKey, true);
    scrim.onclick = closeDrawer;
    panel.querySelector('.cat-dr-x').onclick = closeDrawer;
    /* Chrome throttles requestAnimationFrame when the tab is not focused; the drawer must never be left
       off-screen behind its own transform. Force layout, then reveal synchronously. */
    void panel.offsetWidth;
    scrim.classList.add('in'); panel.classList.add('in');
    S.lastFocus = document.activeElement;
    S.drawer = { panel: panel, scrim: scrim, onKey: onKey };
    return { body: panel.querySelector('.cat-dr-b'), foot: panel.querySelector('.cat-dr-f'), panel: panel };
  }

  function openSettings() {
    var sp = S.spec; if (!sp) return;
    var d = shell('Settings · ' + sp.label);
    d.body.innerHTML = '<label class="sw"><input type="checkbox" id="cat-s-on"' + (sp.enabled ? ' checked' : '') + '> Show ' + esc(sp.label.toLowerCase()) + ' on this website</label>'
      + '<p class="hint">When this is off the ' + esc(sp.label.toLowerCase()) + ' page is removed from the live site and the home block stops showing them. Nothing is deleted.</p>'
      + '<label>Where they appear</label>'
      + '<p class="hint">Home page: the first <b>' + esc(String(sp.slots)) + '</b> live ' + esc(sp.label.toLowerCase()) + ', featured ones first.<br>'
      + 'Its own page: <b>/' + esc(sp.page_slug) + '/</b>' + (sp.pages === 'index+detail' ? ', and one page per ' + esc(sp.singular) + ' at <b>/' + esc(sp.detail_prefix) + '-…/</b>' : '') + '</p>'
      + (publicUrl(null) ? '<label>Live address</label><a class="cat-link" href="' + esc(publicUrl(null)) + '" target="_blank" rel="noopener">' + esc(publicUrl(null)) + ' ↗</a>' : '')
      + '<label>Statuses this kind uses</label><div class="seg">' + Object.keys(sp.statuses || {}).map(function (k) { return '<button type="button" disabled aria-pressed="false" style="cursor:default">' + esc(sp.statuses[k]) + '</button>'; }).join('') + '</div>'
      + '<p class="hint">Page name, the button wording and the enquiry form are set by the design. Ask Arthur to change them.</p>';
    d.foot.innerHTML = '<button type="button" class="lu-btn" data-a="done">Done</button>';
    d.foot.querySelector('[data-a=done]').onclick = closeDrawer;
    d.body.querySelector('#cat-s-on').onchange = function () { setEnabled(this.checked, null); closeDrawer(); };
  }

  function openDrawer(it) {
    var sp = S.spec; if (!sp) return;
    var isNew = !it;
    var d = shell(isNew ? ('New ' + sp.singular) : it.title);
    var photos = it && it.photos ? it.photos.slice() : [];
    var attrs = it && it.attrs ? Object.assign({}, it.attrs) : {};
    var status = it ? it.status : sp.default_status;
    var period = (it && it.price_period) || '';
    var isListing = sp.kind === 'listing';

    var val = function (k, dflt) { return it && it[k] !== null && it[k] !== undefined ? it[k] : (dflt === undefined ? '' : dflt); };

    var h = '<label for="cat-f-title">Name</label><input type="text" id="cat-f-title" data-f="title" maxlength="190" value="' + esc(val('title')) + '" placeholder="' + esc(isListing ? '3-bed townhouse on Mill Lane' : 'Deep tissue massage') + '">';

    h += '<label>Status</label><div class="seg" id="cat-f-status">' + Object.keys(sp.statuses || {}).map(function (k) {
      return '<button type="button" data-s="' + esc(k) + '" aria-pressed="' + (k === status ? 'true' : 'false') + '">' + esc(sp.statuses[k]) + '</button>';
    }).join('') + '</div>';

    h += '<div id="cat-f-closed"' + ((sp.closed_statuses || []).indexOf(status) >= 0 ? '' : ' hidden') + '>'
      + '<label for="cat-f-note">Note shown on the site (optional)</label>'
      + '<input type="text" id="cat-f-note" data-f="closed_note" maxlength="190" value="' + esc(val('closed_note')) + '" placeholder="Sold in five days, over asking"></div>';

    h += '<div class="row3"><div><label for="cat-f-price">Price</label><input type="number" id="cat-f-price" data-f="price" min="0" step="0.01" value="' + esc(val('price')) + '" placeholder="' + (isListing ? '925000' : '90') + '"></div>'
      + '<div><label for="cat-f-cur">Currency</label><input type="text" id="cat-f-cur" data-f="currency" maxlength="3" value="' + esc(val('currency', sp.currency || 'USD')) + '"></div>'
      + '<div><label>Per</label><div class="seg" id="cat-f-period">' + ['', 'night', 'month', 'year', 'person', 'hour'].map(function (p) {
        return '<button type="button" data-p="' + esc(p) + '" aria-pressed="' + (p === period ? 'true' : 'false') + '">' + esc(p || 'once') + '</button>';
      }).join('') + '</div></div></div>';

    h += '<label for="cat-f-plabel">Price wording (optional — replaces the number)</label><input type="text" id="cat-f-plabel" data-f="price_label" maxlength="80" value="' + esc(val('price_label')) + '" placeholder="From $120 · POA">';

    (sp.attrs || []).forEach(function (a) {
      var v = attrs[a.key] !== undefined && attrs[a.key] !== null ? attrs[a.key] : (a.default || '');
      var id = 'cat-a-' + a.key;
      if (a.type === 'select') {
        h += '<label>' + esc(a.label) + '</label><div class="seg" data-attr-seg="' + esc(a.key) + '">' + (a.options || []).map(function (o) {
          return '<button type="button" data-o="' + esc(o) + '" aria-pressed="' + (String(v) === o ? 'true' : 'false') + '">' + esc(o) + '</button>';
        }).join('') + '</div>';
      } else if (a.type === 'textarea') {
        h += '<label for="' + id + '">' + esc(a.label) + '</label><textarea id="' + id + '" data-attr="' + esc(a.key) + '">' + esc(String(v)) + '</textarea>';
      } else {
        h += '<label for="' + id + '">' + esc(a.label) + '</label><input type="' + (a.type === 'number' ? 'number' : 'text') + '" id="' + id + '" data-attr="' + esc(a.key) + '"' + (a.type === 'number' ? ' step="0.5" min="0"' : ' maxlength="190"') + ' value="' + esc(String(v)) + '">';
      }
    });

    h += '<label for="cat-f-sum">Short text (on the card)</label><input type="text" id="cat-f-sum" data-f="summary" maxlength="300" value="' + esc(val('summary')) + '">'
      + '<label for="cat-f-desc">Full description (on its page)</label><textarea id="cat-f-desc" data-f="description" maxlength="6000">' + esc(val('description')) + '</textarea>'
      + '<label for="cat-f-feat">Features (one per line)</label><textarea id="cat-f-feat" data-f="features" style="min-height:60px">' + esc((val('features', []) || []).join('\n')) + '</textarea>';

    h += '<label>Photos</label><p class="hint" style="margin:0 0 6px">The first photo is the one on the card. Use <b>Cover</b> to promote any photo to the front.</p>'
      + '<div class="cat-ph" id="cat-f-photos"></div>'
      + '<div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap"><input type="text" id="cat-f-purl" placeholder="Paste a photo address (https://…)" style="flex:1;min-width:150px">'
      + '<button type="button" class="lu-btn lu-btn--sm" id="cat-f-padd">Add</button>'
      + '<button type="button" class="lu-btn lu-btn--sm" id="cat-f-pup">Upload</button>'
      + '<input type="file" id="cat-f-pfile" accept="image/*" hidden></div>';

    h += '<label class="sw"><input type="checkbox" id="cat-f-star" data-f="featured"' + (val('featured', false) ? ' checked' : '') + '> Featured — shown first on the site</label>';

    if (!isNew) {
      var url = publicUrl(it);
      if (url) h += '<label>Live address</label><a class="cat-link" href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(url) + ' ↗</a>';
    }
    h += '<div class="err" id="cat-f-err"></div>';
    d.body.innerHTML = h;

    /* photo manager */
    var drawPhotos = function () {
      var box = d.body.querySelector('#cat-f-photos');
      if (!photos.length) { box.innerHTML = '<p class="hint" style="grid-column:1/-1;margin:0">No photos yet. A ' + esc(sp.singular) + ' with a photo gets far more enquiries.</p>'; return; }
      box.innerHTML = photos.map(function (p, i) {
        return '<figure' + (i === 0 ? ' class="cover"' : '') + '><img src="' + esc(p) + '" alt="">'
          + (i === 0 ? '<span class="badge">Cover</span>' : '')
          + '<div class="tools">'
          + '<button type="button" data-p="up" data-i="' + i + '" aria-label="Move earlier"' + (i === 0 ? ' disabled' : '') + '>◀</button>'
          + '<button type="button" data-p="cover" data-i="' + i + '" aria-label="Make cover"' + (i === 0 ? ' disabled' : '') + '>★</button>'
          + '<button type="button" data-p="del" data-i="' + i + '" aria-label="Remove photo">×</button>'
          + '<button type="button" data-p="down" data-i="' + i + '" aria-label="Move later"' + (i === photos.length - 1 ? ' disabled' : '') + '>▶</button>'
          + '</div></figure>';
      }).join('');
      box.querySelectorAll('button[data-p]').forEach(function (b) {
        b.onclick = function () {
          var i = parseInt(b.getAttribute('data-i'), 10), op = b.getAttribute('data-p');
          if (op === 'del') photos.splice(i, 1);
          else if (op === 'cover') { var c = photos.splice(i, 1)[0]; photos.unshift(c); }
          else if (op === 'up' && i > 0) { var a = photos[i - 1]; photos[i - 1] = photos[i]; photos[i] = a; }
          else if (op === 'down' && i < photos.length - 1) { var z = photos[i + 1]; photos[i + 1] = photos[i]; photos[i] = z; }
          drawPhotos();
        };
      });
    };
    drawPhotos();

    var err = function (m) { d.body.querySelector('#cat-f-err').textContent = m || ''; };
    d.body.querySelector('#cat-f-padd').onclick = function () {
      var u = d.body.querySelector('#cat-f-purl').value.trim();
      if (!u) return;
      if (!/^(https?:\/\/|\/storage\/)/i.test(u)) { err('A photo address starts with https:// — paste the address of the image itself.'); return; }
      photos.push(u); d.body.querySelector('#cat-f-purl').value = ''; err(''); drawPhotos();
    };
    d.body.querySelector('#cat-f-pup').onclick = function () { d.body.querySelector('#cat-f-pfile').click(); };
    d.body.querySelector('#cat-f-pfile').onchange = async function (e) {
      var f = e.target.files && e.target.files[0]; if (!f) return;
      var b = d.body.querySelector('#cat-f-pup'); b.disabled = true; b.textContent = 'Uploading…'; err('');
      try {
        var fd = new FormData(); fd.append('photo', f);
        var j = await send('POST', 'catalogue/photo', fd, true);
        photos.push(j.url); drawPhotos();
      } catch (e2) { err('Couldn’t upload — ' + e2.message); }
      finally { b.disabled = false; b.textContent = 'Upload'; e.target.value = ''; }
    };

    /* segmented controls */
    var seg = function (sel, pick) {
      d.body.querySelectorAll(sel + ' button').forEach(function (b) {
        b.onclick = function () {
          d.body.querySelectorAll(sel + ' button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
          pick(b);
        };
      });
    };
    seg('#cat-f-status', function (b) {
      status = b.getAttribute('data-s');
      d.body.querySelector('#cat-f-closed').hidden = (sp.closed_statuses || []).indexOf(status) < 0;
    });
    seg('#cat-f-period', function (b) { period = b.getAttribute('data-p'); });
    d.body.querySelectorAll('[data-attr-seg]').forEach(function (s) {
      var key = s.getAttribute('data-attr-seg');
      seg('[data-attr-seg="' + key + '"]', function (b) { attrs[key] = b.getAttribute('data-o'); });
    });

    /* The footer is rebuilt by bindFoot, so a cancelled Remove restores working buttons. */
    var bindFoot = function () {
      d.foot.innerHTML = '<button type="button" class="lu-btn" id="cat-f-save">' + (isNew ? 'Add ' + esc(sp.singular) : 'Save changes') + '</button>'
        + '<button type="button" class="lu-btn lu-btn--sm" id="cat-f-cancel">Cancel</button>'
        + (isNew ? '' : '<button type="button" class="lu-btn lu-btn--sm cat-danger" id="cat-f-del" style="margin-left:auto">Remove</button>');
      d.foot.querySelector('#cat-f-save').onclick = doSave;
      d.foot.querySelector('#cat-f-cancel').onclick = closeDrawer;
      var del = d.foot.querySelector('#cat-f-del');
      if (del) del.onclick = askDelete;
    };

    var askDelete = function () {
      d.foot.innerHTML = '<span style="flex:1;font-size:12.5px;color:var(--t2)">Remove “' + esc(it.title.length > 24 ? it.title.slice(0, 24) + '…' : it.title) + '”? It comes off the live site.</span>'
        + '<button type="button" class="lu-btn lu-btn--sm cat-danger" data-a="yes">Remove</button>'
        + '<button type="button" class="lu-btn lu-btn--sm" data-a="no">Keep</button>';
      d.foot.querySelector('[data-a=no]').onclick = bindFoot;
      d.foot.querySelector('[data-a=yes]').onclick = async function () {
        var b = this; b.disabled = true; b.textContent = 'Removing…';
        try {
          var j = await send('DELETE', 'catalogue/' + sp.kind + '/' + it.id);
          toast(j.message || 'Removed.');
          closeDrawer(); await loadItems();
        } catch (e) { b.disabled = false; b.textContent = 'Remove'; toast('Couldn’t remove — ' + e.message, 'error'); }
      };
    };

    var doSave = async function () {
      var b = this && this.id === 'cat-f-save' ? this : d.foot.querySelector('#cat-f-save');
      err('');
      var g = function (k) { var el = d.body.querySelector('[data-f=' + k + ']'); return el ? (el.type === 'checkbox' ? el.checked : el.value) : ''; };
      if (!String(g('title')).trim()) { err('Give it a name.'); var t = d.body.querySelector('#cat-f-title'); if (t) t.focus(); return; }
      d.body.querySelectorAll('[data-attr]').forEach(function (el) { attrs[el.getAttribute('data-attr')] = el.value; });
      var payload = {
        title: String(g('title')).trim(), status: status,
        price: g('price'), currency: String(g('currency')).trim().toUpperCase() || (sp.currency || 'USD'),
        price_period: period, price_label: g('price_label'),
        summary: g('summary'), description: g('description'), features: g('features'),
        photos: photos, attrs: attrs, featured: !!g('featured')
      };
      if ((sp.closed_statuses || []).indexOf(status) >= 0) payload.closed_note = g('closed_note');
      b.disabled = true; b.textContent = 'Saving…';
      try {
        var j = await send(isNew ? 'POST' : 'PUT', 'catalogue/' + sp.kind + (isNew ? '' : '/' + it.id), payload);
        toast(j.message || (isNew ? 'Added.' : 'Saved.'));
        closeDrawer(); await loadItems();
      } catch (e3) {
        b.disabled = false; b.textContent = isNew ? 'Add ' + sp.singular : 'Save changes';
        err(e3.message);
      }
    };

    bindFoot();
    setTimeout(function () { var t = d.body.querySelector('#cat-f-title'); if (t) t.focus(); }, 260);
  }

  /* ─────────────────────────────── entry ─────────────────────────────── */
  window.catalogueLoad = async function (root, opts) {
    css(); opts = opts || {};
    ROOT = root;
    S.view = recall('view', 'grid') === 'table' ? 'table' : 'grid';
    S.sort = recall('sort', 'page');
    root.innerHTML = '<div class="lu-skel" style="width:38%;height:26px"></div><div class="lu-skel" style="width:64%;margin-top:10px"></div>';
    try {
      var r = await _luFetch('GET', '/catalogue/summary');
      S.summary = r.ok ? await r.json() : { has_catalogue: false, groups: [], websites: [] };
    } catch (e) { S.summary = { has_catalogue: false, groups: [], websites: [] }; }
    S.group = groupOf(String(opts.tail || (S.group && S.group.slug) || ''));
    if (opts.site) S.siteId = parseInt(opts.site, 10) || 0;
    if (S.group) { S.kind = S.group.kind || null; }
    paint();
    if (S.group && (S.group.websites || []).length) await loadItems();
    if (window._luCatalogueApplyNav) { try { window._luCatalogueApplyNav(S.summary); } catch (_e) {} }
  };

  window.catalogueUnload = function () {
    closeDrawer();
    window._luCatalogueMount = false;
    ROOT = null;
  };
})();
