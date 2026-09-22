/* CAT-2 (Owner 2026-09-22): the catalogue as a section of the app, on Basic and Advanced, named by industry.
   THE RULE (Owner): one sidebar entry per industry word — two realty companies share "Properties" with a dropdown
   inside to pick the company; realty + travel = "Properties" and "Packages", each its own entry.
   Data: GET /api/catalogue/summary (workspace: websites + groups) + the editor's per-website endpoints, unchanged.
   The panel is builder.js's (wsOpenCatalogue → _t3CatLoad …): with window._luCatalogueMount set it renders inline. */
window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['catalogue'] = true;
(function () {
  var S = { summary: null, group: null, siteId: 0 };
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function remember(slug, id) { try { localStorage.setItem('lu_cat_' + slug, String(id)); } catch (_e) {} }
  function recall(slug) { try { return parseInt(localStorage.getItem('lu_cat_' + slug) || '0', 10) || 0; } catch (_e) { return 0; } }

  function css() {
    if (document.getElementById('cat-view-css')) return;
    var st = document.createElement('style'); st.id = 'cat-view-css';
    st.textContent = '#catalogue-root{padding:24px;max-width:1100px}'
      + '#catalogue-root .cat-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px}'
      + '#catalogue-root h1{font:700 22px var(--fh);color:var(--t1);margin:0 0 4px}'
      + '#catalogue-root .cat-sub{font-size:13px;color:var(--t3)}'
      + '#catalogue-root .cat-pick{display:flex;align-items:center;gap:10px;flex-wrap:wrap}'
      + '#catalogue-root .cat-pick label{font-size:12px;color:var(--t3)}'
      + '#catalogue-root .cat-pick select{min-width:240px}'
      + '#catalogue-root .cat-chips{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}'
      + '#catalogue-root .cat-chip{font-size:12px;color:var(--t2);background:var(--s2);border:1px solid var(--bd);border-radius:999px;padding:4px 10px}'
      + '#catalogue-root .cat-tab{cursor:pointer;font:inherit;font-size:12px;line-height:1.3}#catalogue-root .cat-tab:hover{color:var(--t1);border-color:var(--t3)}#catalogue-root .cat-tab.active{color:#fff;background:var(--p);border-color:var(--p)}'
      + '#catalogue-mount{position:relative;min-height:120px}'
      + '#catalogue-mount #t3-cat{position:static!important;width:100%!important;max-width:none!important;max-height:none!important;top:auto!important;right:auto!important;bottom:auto!important;left:auto!important;box-shadow:none!important;padding:16px!important}'
      + '#catalogue-mount #t3-cat-x{display:none!important}'
      + '@media (max-width:767px){#catalogue-root{padding:14px 12px}#catalogue-root .cat-pick select{min-width:0;width:100%}}';
    document.head.appendChild(st);
  }

  function groupOf(slug) {
    var gs = (S.summary && S.summary.groups) || [];
    return gs.filter(function (g) { return g.slug === slug; })[0] || gs[0] || null;
  }
  function siteById(id) { return ((S.summary && S.summary.websites) || []).filter(function (s) { return s.id === id; })[0] || null; }

  function render(root) {
    var d = S.summary; var gs = (d && d.groups) || [];
    if (!gs.length) {
      root.innerHTML = '<div class="cat-head"><div><h1>Catalogue</h1><div class="cat-sub">What your websites sell — properties, packages, a menu, treatments — managed in one place.</div></div></div>'
        + '<div class="lu-empty"><b>Nothing to manage yet</b>None of your websites carries a catalogue. Designs that sell something get one automatically; ask Sarah or Arthur to add one to a site.</div>';
      document.title = 'Catalogue · ' + ((window.LU_CFG && window.LU_CFG.bn) || 'LevelUpGrowth');
      return;
    }
    var g = S.group; var ids = g.websites || [];
    window._luCatalogueGroup = g.slug;
    document.querySelectorAll('.nav-item[data-cat-group]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-cat-group') === g.slug); });
    if (!S.siteId || ids.indexOf(S.siteId) < 0) { var last = recall(g.slug); S.siteId = ids.indexOf(last) >= 0 ? last : ids[0]; }
    var site = siteById(S.siteId); if (!site) { root.innerHTML = '<div class="lu-empty"><b>That website is gone</b></div>'; return; }
    var kind = (site.kinds || []).filter(function (k) { return k.kind === (S.kind || g.kind); })[0] || site.kinds[0];
    root.innerHTML = '<div class="cat-head"><div><h1 id="cat-title">' + esc(g.label) + '</h1><div class="cat-sub">'
      + (ids.length > 1 ? esc(String(ids.length)) + ' of your websites sell ' + esc(g.label.toLowerCase()) + ' — pick the company; each keeps its own list. Changes show on its live site right away.'
                        : 'Changes show on the live site right away — the home page and the ' + esc(g.label.toLowerCase()) + ' page.')
      + '</div></div>'
      + (ids.length > 1
          ? '<div class="cat-pick"><label for="cat-site">Company</label><select id="cat-site">' + ids.map(function (id) { var s = siteById(id); return s ? '<option value="' + s.id + '"' + (s.id === S.siteId ? ' selected' : '') + '>' + esc(s.name) + '</option>' : ''; }).join('') + '</select></div>'
          : '<div class="cat-pick"><span class="cat-chip">' + esc(site.name) + '</span></div>')
      + '</div>'
      + '<div class="cat-chips" role="tablist">' + (site.kinds || []).map(function (k) { return '<button type="button" role="tab" class="cat-chip cat-tab' + (kind && k.kind === kind.kind ? ' active' : '') + '" data-kind="' + esc(k.kind) + '" aria-selected="' + (kind && k.kind === kind.kind ? 'true' : 'false') + '">' + esc(k.label) + ' · ' + k.count + (k.enabled ? '' : ' · off') + '</button>'; }).join('') + (site.host ? '<span class="cat-chip">' + esc(site.host) + '</span>' : '') + '</div>'
      + '<div id="catalogue-mount"></div>';
    document.title = g.label + ' · ' + ((window.LU_CFG && window.LU_CFG.bn) || 'LevelUpGrowth');
    var sel = root.querySelector('#cat-site');
    if (sel) sel.addEventListener('change', function () { S.siteId = parseInt(sel.value, 10) || S.siteId; S.kind = null; remember(g.slug, S.siteId); render(root); });
    root.querySelectorAll('.cat-tab[data-kind]').forEach(function (t) { t.onclick = function () { S.kind = t.getAttribute('data-kind'); render(root); }; });
    mount(site, kind ? kind.kind : undefined);
  }

  function mount(site, kind) {
    var host = document.getElementById('catalogue-mount'); if (!host) return;
    if (typeof window.wsOpenCatalogue !== 'function') { host.innerHTML = '<div class="lu-empty"><b>The catalogue editor is still loading</b>Give it a second and open this section again.</div>'; return; }
    window._luCatalogueMount = true;
    try { window.wsOpenCatalogue(site.id, kind); }
    catch (e) { host.innerHTML = '<div class="lu-empty"><b>Couldn’t open the catalogue</b>' + esc(e.message) + '</div>'; }
  }

  /* root, opts.tail = the group slug from the sidebar entry or the URL (/app/catalogue/properties) */
  window.catalogueLoad = async function (root, opts) {
    css(); opts = opts || {};
    root.innerHTML = '<div class="lu-skel" style="width:40%;height:24px"></div><div class="lu-skel" style="width:70%;margin-top:10px"></div>';
    try {
      var r = await _luFetch('GET', '/catalogue/summary');
      S.summary = r.ok ? await r.json() : { has_catalogue: false, groups: [], websites: [] };
    } catch (e) { S.summary = { has_catalogue: false, groups: [], websites: [] }; }
    S.group = groupOf(String(opts.tail || (S.group && S.group.slug) || ''));
    if (opts.site) { S.siteId = parseInt(opts.site, 10) || 0; }
    render(root);
    if (window._luCatalogueApplyNav) { try { window._luCatalogueApplyNav(S.summary); } catch (_e) {} }
  };

  window.catalogueUnload = function () { window._luCatalogueMount = false; var p = document.getElementById('t3-cat'); if (p && p.closest('#catalogue-mount')) p.remove(); };
})();
