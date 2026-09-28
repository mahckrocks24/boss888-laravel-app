/* RFC-0011 U5b (Owner, 2026-09-22): the businesses of ONE workspace, managed from Settings › Business on Basic and
   Advanced — cards, not a switcher. Add / edit / set default / assign websites / delete. The default business's
   profile is the one the workspace profile card below mirrors. Data: /api/businesses (U5a). */
window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['businesses'] = true;
(function () {
  var S = { data: null, root: null };
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function auth() { return { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json', 'Content-Type': 'application/json' }; }
  async function api(method, path, body) {
    var r = await fetch('/api' + path, { method: method, headers: auth(), body: body ? JSON.stringify(body) : undefined });
    var j = null; try { j = await r.json(); } catch (e) {}
    if (!r.ok) throw new Error((j && (j.error || j.message)) || ('HTTP ' + r.status));
    return j;
  }

  function css() {
    if (document.getElementById('biz-cards-css')) return;
    var st = document.createElement('style'); st.id = 'biz-cards-css';
    st.textContent = ':is(#businesses-section,.bz-host){background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:20px;margin-bottom:16px}'
      + ':is(#businesses-section,.bz-host) .bz-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}'
      + ':is(#businesses-section,.bz-host) .bz-title{font-size:13px;font-weight:700;color:var(--t1)}'
      + ':is(#businesses-section,.bz-host) .bz-sub{font-size:11.5px;color:var(--t3);margin-top:2px}'
      + ':is(#businesses-section,.bz-host) .bz-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}'
      + ':is(#businesses-section,.bz-host) .bz-card{background:var(--s2);border:1px solid var(--bd);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:8px;min-width:0}'
      + ':is(#businesses-section,.bz-host) .bz-card.is-default{border-color:var(--p)}'
      + ':is(#businesses-section,.bz-host) .bz-name{font-size:14px;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px;min-width:0}'
      + ':is(#businesses-section,.bz-host) .bz-name span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
      + ':is(#businesses-section,.bz-host) .bz-star{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--p);background:rgba(108,92,231,.12);border-radius:999px;padding:2px 8px;flex-shrink:0}'
      + ':is(#businesses-section,.bz-host) .bz-meta{font-size:12px;color:var(--t3);line-height:1.5}'
      + ':is(#businesses-section,.bz-host) .bz-sites{font-size:12px;color:var(--t2)}'
      + ':is(#businesses-section,.bz-host) .bz-sites b{color:var(--t1)}'
      + ':is(#businesses-section,.bz-host) .bz-acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:auto}'
      + ':is(#businesses-section,.bz-host) .bz-empty{font-size:12px;color:var(--t3);padding:10px 0}'
      + '.bz-form{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:0 22px 8px}'
      + '.bz-form label{display:flex;flex-direction:column;gap:4px;font-size:11px;color:var(--t3);min-width:0}'
      + '.bz-form input,.bz-form textarea{width:100%;box-sizing:border-box;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);padding:8px 10px;font:inherit;font-size:13px}'
      + '.bz-form .full{grid-column:1/-1}'
      + '.bz-sitelist{display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:auto;padding:0 22px 8px;font-size:12px;color:var(--t2)}'
      + '.bz-sitelist label{display:flex;align-items:center;gap:8px}'
      + '.bz-form label{font-weight:600}'
      + '.bz-form .bz-sec{grid-column:1/-1;font:700 10.5px var(--fb);letter-spacing:.07em;text-transform:uppercase;color:var(--t3);margin:6px 0 -2px;padding-top:6px;border-top:1px solid var(--bd)}'
      + '.bz-form .bz-sec.first{border-top:0;padding-top:0;margin-top:0}'
      + '.bz-form input:focus,.bz-form textarea:focus{outline:none;border-color:var(--p);box-shadow:0 0 0 3px var(--ps,rgba(108,92,231,.18))}'
      + '.bz-ov{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.62);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:16px;font-family:var(--fb,"DM Sans",system-ui,sans-serif)}'
      + '.lu-dlg.bz-dlg{display:flex;flex-direction:column;overflow:hidden;max-width:600px;width:calc(100% - 24px);max-height:calc(100vh - 32px);background:var(--s1,#171A21);border:1px solid var(--bd2,rgba(255,255,255,.13));border-radius:var(--rg,14px);box-shadow:0 24px 64px rgba(0,0,0,.6);color:var(--t1,#E8EDF5)}'
      + '.bz-dlg .lu-dlg-head{padding:20px 22px 6px;font-weight:700;font-size:16px;letter-spacing:-.01em;font-family:var(--fh,"Syne",sans-serif)}'
      + '.bz-dlg .lu-dlg-body{padding:6px 22px 14px;color:var(--t2,#8B97B0);font-size:13.5px;line-height:1.55}'
      + '.bz-dlg .lu-dlg-foot{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;padding:12px 16px 16px;border-top:1px solid var(--bd,rgba(255,255,255,.07))}'
      + '.bz-dlg .lu-dlg-btn{min-height:44px;padding:0 18px;border-radius:var(--r,10px);font-size:13.5px;font-weight:600;font-family:inherit;cursor:pointer;border:1px solid transparent}'
      + '.bz-dlg .lu-dlg-btn.ghost{background:transparent;color:var(--t2,#8B97B0);border-color:var(--bd2,rgba(255,255,255,.13))}'
      + '.bz-dlg .lu-dlg-btn.primary{background:var(--p,#6C5CE7);color:#fff}'
      + '.bz-dlg .lu-dlg-btn:focus-visible{outline:2px solid var(--p,#6C5CE7);outline-offset:2px}'
      + '.bz-dlg .lu-dlg-head{flex:0 0 auto}'
      + '.bz-dlg .lu-dlg-body{flex:0 0 auto}'
      + '.bz-dlg .bz-form{flex:1 1 auto;min-height:0;overflow-y:auto;padding-top:6px;padding-bottom:16px}'
      + '.bz-dlg .lu-dlg-foot{flex:0 0 auto;background:var(--s1);box-shadow:0 -8px 18px -10px rgba(0,0,0,.55)}'
      + '@media (max-width:767px){'
      +   '.bz-form{grid-template-columns:1fr;padding-left:16px;padding-right:16px}'
      +   ':is(#businesses-section,.bz-host) .bz-grid{grid-template-columns:1fr}'
      +   '.bz-form input,.bz-form textarea{font-size:16px;padding:12px 12px;border-radius:10px}'
      +   '.lu-dlg.bz-dlg{width:100%;max-width:none;max-height:calc(100dvh - 32px)}'
      +   '.bz-dlg .lu-dlg-head{padding-left:16px;padding-right:16px;padding-top:18px}'
      +   '.bz-dlg .lu-dlg-body{padding-left:16px;padding-right:16px}'
      +   '.bz-dlg .lu-dlg-foot{padding:12px 16px 14px}'
      +   '.bz-dlg .lu-dlg-btn{flex:1 1 auto;min-width:120px;min-height:46px}'
      + '}';
    document.head.appendChild(st);
  }

  function initials(n) { return String(n || '?').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join(''); }

  function card(b) {
    var sites = (b.websites || []);
    return '<div class="bz-card' + (b.is_default ? ' is-default' : '') + '" data-id="' + b.id + '">'
      + '<div class="bz-name"><span class="bz-av" aria-hidden="true">' + esc(initials(b.name)) + '</span><span title="' + esc(b.name) + '">' + esc(b.name) + '</span>' + (b.is_default ? '<span class="bz-star">Default</span>' : '') + '</div>'
      + '<div class="bz-meta">' + esc([b.industry, b.location].filter(Boolean).join(' · ') || 'No industry or location yet') + (b.pricing_anchor ? '<br>' + esc(b.pricing_anchor) : '') + '</div>'
      + '<div class="bz-sites">' + (sites.length ? '<b>' + sites.length + '</b> website' + (sites.length === 1 ? '' : 's') + ': ' + esc(sites.map(function (s) { return s.name; }).slice(0, 3).join(', ')) + (sites.length > 3 ? ' +' + (sites.length - 3) : '') : 'No website yet') + (b.counts && b.counts.leads ? ' · <b>' + b.counts.leads + '</b> enquir' + (b.counts.leads === 1 ? 'y' : 'ies') : '') + '</div>'
      + '<div class="bz-acts">'
      + '<button type="button" class="lu-btn lu-btn--sm" data-a="edit">Edit profile &amp; brand</button>'
      + '</div></div>';
  }

  function render() {
    var root = S.root; if (!root) return;
    var d = S.data || { businesses: [] };
    var list = d.businesses || [];
    root.innerHTML = tip() + '<div class="bz-head"><div><div class="bz-title">Your businesses</div><div class="bz-sub">'
      + (list.length > 1 ? 'Several businesses, one workspace. Each has its own profile and brand; Sarah keeps them apart and asks which one when it isn\'t clear — no switching.' : 'One business today. Add another when you run more than one — Sarah will keep them apart and ask which one when it isn\'t clear.')
      + '</div></div></div>'
      + (list.length ? '<div class="bz-grid">' + list.map(card).join('') + '</div>' : '<div class="bz-empty">No business profile yet — add one.</div>')
      + (d.unassigned_websites && d.unassigned_websites.length ? '<div class="bz-empty">' + d.unassigned_websites.length + ' website' + (d.unassigned_websites.length === 1 ? '' : 's') + ' not attached to a business yet: ' + esc(d.unassigned_websites.map(function (s) { return s.name; }).join(', ')) + ' — use Websites on a card to attach.</div>' : '');
    root.querySelectorAll('.bz-card').forEach(function (c) {
      var id = parseInt(c.getAttribute('data-id'), 10); var b = list.filter(function (x) { return x.id === id; })[0];
      var q = function (a) { return c.querySelector('[data-a=' + a + ']'); };
      if (q('edit')) q('edit').onclick = function () { form(b, 'profile'); };
    });
  }

  function formCss() {
    if (document.getElementById('bz-pf-css')) return;
    var st = document.createElement('style'); st.id = 'bz-pf-css';
    st.textContent = ''
      + '.bz-av{flex:0 0 auto;width:28px;height:28px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font:700 11px var(--fb,system-ui);color:#fff;background:var(--p,#6C5CE7)}'
      + '.lu-dlg.bz-pf{max-width:980px;width:calc(100% - 32px);height:min(860px,calc(100vh - 32px));display:flex;flex-direction:column;padding:0}'
      + '.bz-pf-top{display:flex;align-items:center;gap:14px;padding:20px 24px 0}'
      + '.bz-pf-av{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font:700 17px var(--fh,system-ui);color:#fff;flex:0 0 auto;box-shadow:inset 0 0 0 1px rgba(255,255,255,.12)}'
      + '.bz-pf-id{min-width:0;flex:1}.bz-pf-id h2{margin:0;font:700 18px var(--fh,system-ui);letter-spacing:-.01em;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
      + '.bz-pf-id p{margin:3px 0 0;font-size:12.5px;color:var(--t3)}'
      + '.bz-pf-x{width:40px;height:40px;border-radius:10px;border:1px solid var(--bd2,rgba(127,127,127,.25));background:transparent;color:var(--t2);font-size:18px;cursor:pointer;flex:0 0 auto}.bz-pf-x:hover{background:var(--s2)}'
      + '.bz-pf-top{flex:0 0 auto}.bz-pf-tabs{flex:0 0 auto;display:flex;gap:4px;padding:16px 24px 0;border-bottom:1px solid var(--bd,rgba(127,127,127,.18));overflow-x:auto;scrollbar-width:none}'
      + '.bz-pf-tab{position:relative;background:none;border:0;padding:10px 14px 12px;font:600 13px var(--fb,system-ui);color:var(--t3);cursor:pointer;white-space:nowrap;border-radius:8px 8px 0 0}'
      + '.bz-pf-tab:hover{color:var(--t1)}.bz-pf-tab[aria-selected=true]{color:var(--t1)}.bz-pf-tab[aria-selected=true]::after{content:"";position:absolute;left:10px;right:10px;bottom:-1px;height:2px;border-radius:2px;background:var(--p,#6C5CE7)}'
      + '.bz-pf-tab:focus-visible{outline:2px solid var(--p,#6C5CE7);outline-offset:-2px}'
      + '.bz-pf-body{flex:1 1 auto;min-height:0;overflow-y:auto;padding:20px 24px 24px}'
      + '.bz-pf-pane[hidden]{display:none!important}'
      + '.bz-pf-intro{font-size:13px;color:var(--t2);line-height:1.55;margin:0 0 16px;max-width:680px}'
      + '.bz-pf-grp{border:1px solid var(--bd,rgba(127,127,127,.18));border-radius:14px;padding:18px;margin-bottom:14px;background:var(--s1)}'
      + '.bz-pf-grp h3{margin:0 0 4px;font:700 13.5px var(--fb,system-ui);color:var(--t1)}.bz-pf-grp .d{margin:0 0 14px;font-size:12.5px;color:var(--t3);line-height:1.5}'
      + '.bz-pf .bz-form{padding:0;grid-template-columns:1fr 1fr;gap:14px}'
      + '.bz-pf .bz-form label{font-size:12px;color:var(--t2);font-weight:600}'
      + '.bz-pf .bz-form input{min-height:42px;font-size:13.5px;border-radius:10px;padding:9px 12px}'
      + '.bz-pf .bz-form .hint{font-weight:400;color:var(--t3);font-size:11.5px}'
      + '.bz-pf-foot{display:flex;align-items:center;gap:12px;padding:14px 24px;border-top:1px solid var(--bd,rgba(127,127,127,.18));background:var(--s1);flex:0 0 auto}'
      + '.bz-pf-foot .note{flex:1;font-size:12px;color:var(--t3);min-width:0}'
      + '.bz-pf-foot .lu-dlg-btn{min-height:44px;padding:0 20px;border-radius:10px;font-size:13.5px;font-weight:600;cursor:pointer;border:1px solid transparent}'
      + '.bz-pf-foot .ghost{background:transparent;color:var(--t2);border-color:var(--bd2,rgba(127,127,127,.25))}.bz-pf-foot .primary{background:var(--p,#6C5CE7);color:#fff}.bz-pf-foot .primary:disabled{opacity:.6;cursor:default}'
      + '.bz-pf .lbc{border-radius:14px}'
      + '@media (max-width:767px){.lu-dlg.bz-pf{width:100%;height:calc(100dvh - 16px);border-radius:16px}.bz-pf-top,.bz-pf-tabs,.bz-pf-body,.bz-pf-foot{padding-left:16px;padding-right:16px}.bz-pf .bz-form{grid-template-columns:1fr}.bz-pf-foot .note{display:none}.bz-pf-foot .lu-dlg-btn{flex:1}}';
    document.head.appendChild(st);
  }

  /* BIZ-BRAND-2 (Owner 2026-09-28: "The Business profile has to have them inside individually … Expand Edit profile to
     accommodate the brand preferences. Make it enterprise saas quality"): one profile per business, in tabs —
     Profile · Brand · Design styles · Rules & inspiration. Save covers the profile and the colours; styles, rules and
     inspirations save as they are picked. */
  function form(b, tab) {
    if (typeof window.luEnsureDialogCss === 'function') window.luEnsureDialogCss();
    formCss();
    var v = function (k) { return b ? esc(b[k] || '') : ''; };
    var TABS = b ? [['profile', 'Profile'], ['brand', 'Brand'], ['styles', 'Design styles'], ['rules', 'Rules & inspiration']] : [['profile', 'Profile']];
    tab = tab || 'profile';
    var ov = document.createElement('div'); ov.className = 'lu-dlg-overlay bz-ov';
    ov.innerHTML = '<div class="lu-dlg bz-dlg bz-pf" role="dialog" aria-modal="true" aria-labelledby="bz-t">'
      + '<div class="bz-pf-top"><div class="bz-pf-av" data-av style="background:var(--p,#6C5CE7)">' + esc(b ? initials(b.name) : '+') + '</div>'
      + '<div class="bz-pf-id"><h2 id="bz-t">' + (b ? esc(b.name) : 'Add a business') + '</h2><p>' + (b ? esc([b.industry, b.location].filter(Boolean).join(' · ') || 'Business profile') : 'Its own profile, websites, brand and memory — in the same workspace, no switching.') + '</p></div>'
      + '<button type="button" class="bz-pf-x" data-role="cancel" aria-label="Close">✕</button></div>'
      + '<div class="bz-pf-tabs" role="tablist">' + TABS.map(function (t) { return '<button type="button" class="bz-pf-tab" role="tab" id="bz-tab-' + t[0] + '" aria-controls="bz-pane-' + t[0] + '" aria-selected="' + (t[0] === tab) + '" data-tab="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>'
      + '<div class="bz-pf-body">'
      +   '<div class="bz-pf-pane" id="bz-pane-profile" role="tabpanel" aria-labelledby="bz-tab-profile">'
      +     '<p class="bz-pf-intro">What Sarah and the studio know about ' + (b ? esc(b.name) : 'this business') + '. She uses it in every post, article and reply — keep it true and specific.</p>'
      +     '<div class="bz-pf-grp"><h3>Identity</h3><p class="d">Who the business is and where it serves.</p><div class="bz-form">'
      +       '<label class="full">Business name<input autocomplete="off" id="bz-name" value="' + v('name') + '" placeholder="e.g. Boss Mac Gym"></label>'
      +       '<label>Industry<input autocomplete="off" id="bz-industry" value="' + v('industry') + '" placeholder="e.g. gym"></label>'
      +       '<label>Location<input autocomplete="off" id="bz-location" value="' + v('location') + '" placeholder="e.g. Jersey City, NJ"></label>'
      +       '<label class="full">Services <span class="hint">— separate with commas</span><input autocomplete="off" id="bz-services" value="' + esc((b && b.services || []).join(', ')) + '" placeholder="e.g. Memberships, Personal training"></label>'
      +     '</div></div>'
      +     '<div class="bz-pf-grp"><h3>How Sarah talks about it</h3><p class="d">The facts and voice she may use with customers.</p><div class="bz-form">'
      +       '<label class="full">Prices Sarah may quote<input autocomplete="off" id="bz-pricing" value="' + v('pricing_anchor') + '" placeholder="e.g. $49/month, personal training $60/session"></label>'
      +       '<label>Tone<input autocomplete="off" id="bz-tone" value="' + v('tone') + '" placeholder="e.g. energetic, plain"></label>'
      +       '<label>Target audience<input autocomplete="off" id="bz-audience" value="' + v('target_audience') + '" placeholder="e.g. busy professionals 25–45"></label>'
      +       '<label class="full">What sets it apart<input autocomplete="off" id="bz-diff" value="' + v('differentiators') + '" placeholder="e.g. open 24/7, coaches certified in sports nutrition"></label>'
      +       '<label class="full">Also called <span class="hint">— what you call it when you talk to Sarah</span><input autocomplete="off" id="bz-aliases" value="' + esc((b && b.aliases || []).join(', ')) + '" placeholder="e.g. the gym"></label>'
      +     '</div></div>'
      +   '</div>'
      +   (b ? '<div class="bz-pf-pane" id="bz-pane-brand" role="tabpanel" aria-labelledby="bz-tab-brand" hidden></div><div class="bz-pf-pane" id="bz-pane-styles" role="tabpanel" aria-labelledby="bz-tab-styles" hidden></div><div class="bz-pf-pane" id="bz-pane-rules" role="tabpanel" aria-labelledby="bz-tab-rules" hidden></div>' : '')
      + '</div>'
      + '<div class="bz-pf-foot"><div class="note" data-note></div><button type="button" class="lu-dlg-btn ghost" data-role="cancel">Cancel</button><button type="button" class="lu-dlg-btn primary" data-role="ok">' + (b ? 'Save changes' : 'Add business') + '</button></div>'
      + '</div>';
    document.body.appendChild(ov);
    var prevFocus = document.activeElement;
    var close = function () { document.removeEventListener('keydown', onKey, true); try { ov.remove(); } catch (e) {} try { if (prevFocus && prevFocus.focus) prevFocus.focus(); } catch (e) {} };
    function onKey(e) { if (e.key !== 'Escape') return; var top = document.querySelector('.lucp-actions, .lu-sel-menu.open, .lu-dlg-overlay:not(.bz-ov)'); if (top && top.offsetParent !== null) return; e.preventDefault(); close(); }   // a picker or dialog on top closes first
    document.addEventListener('keydown', onKey, true);
    ov.querySelectorAll('[data-role=cancel]').forEach(function (x) { x.onclick = close; });
    ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(); });
    var note = ov.querySelector('[data-note]');
    var NOTES = { profile: '', brand: 'Colours save with Save changes.', styles: 'Design styles save as you pick them.', rules: 'Rules and inspirations save as you add them.' };
    function show(t) {
      ov.querySelectorAll('.bz-pf-tab').forEach(function (x) { x.setAttribute('aria-selected', String(x.getAttribute('data-tab') === t)); });
      ov.querySelectorAll('.bz-pf-pane').forEach(function (p) { p.hidden = p.id !== 'bz-pane-' + t; });
      note.textContent = NOTES[t] || '';
    }
    ov.querySelectorAll('.bz-pf-tab').forEach(function (x) {
      x.onclick = function () { show(x.getAttribute('data-tab')); };
      x.addEventListener('keydown', function (e) { if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return; var all = [].slice.call(ov.querySelectorAll('.bz-pf-tab')); var i = all.indexOf(x) + (e.key === 'ArrowRight' ? 1 : -1); var n = all[(i + all.length) % all.length]; n.focus(); n.click(); });
    });
    // the brand parts: one shared panel, split across the Brand, Design styles and Rules tabs
    if (b && window.LU_brandCard) {
      window.LU_brandCard.settings(ov.querySelector('.bz-pf-body'), b.id, { single: true, embedded: true, panes: { brand: ov.querySelector('#bz-pane-brand'), styles: ov.querySelector('#bz-pane-styles'), rules: ov.querySelector('#bz-pane-rules') },
        onColours: function (c) { var av = ov.querySelector('[data-av]'); if (av && c.primary) av.style.background = c.primary; } });
    } else if (b) {
      ov.querySelector('#bz-pane-brand').innerHTML = '<p class="bz-pf-intro">Reload the page to edit the brand.</p>';
    }
    show(tab);
    var ok = ov.querySelector('[data-role=ok]');
    ok.onclick = async function () {
      var g = function (id) { return (ov.querySelector('#' + id).value || '').trim(); };
      var body = { name: g('bz-name'), industry: g('bz-industry'), location: g('bz-location'), services: g('bz-services'), pricing_anchor: g('bz-pricing'), tone: g('bz-tone'), target_audience: g('bz-audience'), differentiators: g('bz-diff'), aliases: g('bz-aliases') };
      if (!body.name) { show('profile'); showToast('A business needs a name.', 'warning'); try { ov.querySelector('#bz-name').focus(); } catch (e) {} return; }
      ok.disabled = true; ok.textContent = 'Saving…';
      try {
        if (b) await api('PUT', '/businesses/' + b.id, body); else await api('POST', '/businesses', body);
        var bodyEl = ov.querySelector('.bz-pf-body'); var cols = b && bodyEl.__lbcColours ? bodyEl.__lbcColours() : null;
        if (cols) await api('PUT', '/workspace/brand', Object.assign({ business_id: b.id }, cols));
        close(); await load();
        showToast(b ? (cols ? 'Profile and colours saved.' : 'Profile saved.') : body.name + ' added — open it to set its brand.', 'success');
        if (window.luLoadWorkspaceProfile) { try { window.luLoadWorkspaceProfile(); } catch (_e) {} }
      } catch (e) { ok.disabled = false; ok.textContent = b ? 'Save changes' : 'Add business'; showToast(e.message, 'error'); }
    };
    setTimeout(function () { try { (tab === 'profile' ? ov.querySelector('#bz-name') : ov.querySelector('.bz-pf-tab[aria-selected=true]')).focus(); } catch (e) {} }, 30);
  }

  /* which websites belong to this business */
  function sites(b) {
    var all = []; (S.data.businesses || []).forEach(function (x) { (x.websites || []).forEach(function (s) { all.push({ id: s.id, name: s.name, owner: x.id }); }); });
    (S.data.unassigned_websites || []).forEach(function (s) { all.push({ id: s.id, name: s.name, owner: null }); });
    all.sort(function (a, c) { return a.name.localeCompare(c.name); });
    var ov = document.createElement('div'); ov.className = 'lu-dlg-overlay';
    ov.innerHTML = '<div class="lu-dlg" role="dialog" aria-modal="true" aria-labelledby="bz-st" style="max-width:520px;width:calc(100% - 24px)">'
      + '<div class="lu-dlg-head" id="bz-st">Websites of ' + esc(b.name) + '</div>'
      + '<div class="lu-dlg-body">Tick the websites this business owns. A website belongs to exactly one business; unticking moves it to the default.</div>'
      + '<div class="bz-sitelist">' + (all.length ? all.map(function (s) { return '<label><input type="checkbox" value="' + s.id + '"' + (s.owner === b.id ? ' checked' : '') + '> ' + esc(s.name) + (s.owner && s.owner !== b.id ? ' <span style="color:var(--t3)">(' + esc((S.data.businesses.filter(function (x) { return x.id === s.owner; })[0] || {}).name || '') + ')</span>' : '') + '</label>'; }).join('') : '<div>No websites in this workspace yet.</div>') + '</div>'
      + '<div class="lu-dlg-foot"><button type="button" class="lu-dlg-btn ghost" data-role="cancel">Cancel</button><button type="button" class="lu-dlg-btn primary" data-role="ok">Save</button></div></div>';
    document.body.appendChild(ov);
    var close = function () { try { ov.remove(); } catch (e) {} };
    ov.querySelector('[data-role=cancel]').onclick = close;
    ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(); });
    ov.querySelector('[data-role=ok]').onclick = async function () {
      var ids = [].slice.call(ov.querySelectorAll('input[type=checkbox]:checked')).map(function (i) { return parseInt(i.value, 10); });
      try { await api('PUT', '/businesses/' + b.id + '/websites', { website_ids: ids }); close(); await load(); showToast('Websites updated.', 'success'); } catch (e) { showToast(e.message, 'error'); }
    };
  }

  async function load() {
    try { S.data = await api('GET', '/businesses'); } catch (e) { S.data = { businesses: [], unassigned_websites: [] }; if (S.root) S.root.innerHTML = '<div class="bz-empty">Could not load businesses: ' + esc(e.message) + '</div>'; return; }
    render();
  }

  function tip() {
    try { if (localStorage.getItem('lu_tip_biz') === '1') return ''; } catch (e) {}
    return '<div id="bz-tip" style="position:relative;background:rgba(108,92,231,.08);border:1px solid rgba(108,92,231,.28);border-radius:12px;padding:12px 40px 12px 14px;margin-bottom:14px;font-size:12.5px;color:var(--t2);line-height:1.55">'
      + '<b style="color:var(--t1)">How your businesses work.</b> Each website you <b>publish</b> becomes its own business here, with its own profile. Sarah uses these to keep your businesses apart \u2014 ask her about any one by name and she answers for that one only, never mixing them. Delete a website and its profile goes with it. You never switch between them.'
      + '<button type="button" aria-label="Dismiss" onclick="try{localStorage.setItem(&#39;lu_tip_biz&#39;,&#39;1&#39;)}catch(e){}; var t=document.getElementById(&#39;bz-tip&#39;); if(t) t.remove();" style="position:absolute;top:8px;right:8px;background:none;border:none;color:var(--t3);font-size:18px;line-height:1;cursor:pointer;padding:2px 6px">\u00d7</button></div>';
  }

  window.businessesLoad = function (root) { css(); S.root = root; root.innerHTML = '<div class="lu-skel" style="width:40%"></div>'; load(); };
  window.businessesList = async function () { try { return (await api('GET', '/businesses')).businesses || []; } catch (e) { return []; } };
})();
