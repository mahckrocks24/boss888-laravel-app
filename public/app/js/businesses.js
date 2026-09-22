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
    st.textContent = '#businesses-section{background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:20px;margin-bottom:16px}'
      + '#businesses-section .bz-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}'
      + '#businesses-section .bz-title{font-size:13px;font-weight:700;color:var(--t1)}'
      + '#businesses-section .bz-sub{font-size:11.5px;color:var(--t3);margin-top:2px}'
      + '#businesses-section .bz-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}'
      + '#businesses-section .bz-card{background:var(--s2);border:1px solid var(--bd);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:8px;min-width:0}'
      + '#businesses-section .bz-card.is-default{border-color:var(--p)}'
      + '#businesses-section .bz-name{font-size:14px;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px;min-width:0}'
      + '#businesses-section .bz-name span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
      + '#businesses-section .bz-star{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--p);background:rgba(108,92,231,.12);border-radius:999px;padding:2px 8px;flex-shrink:0}'
      + '#businesses-section .bz-meta{font-size:12px;color:var(--t3);line-height:1.5}'
      + '#businesses-section .bz-sites{font-size:12px;color:var(--t2)}'
      + '#businesses-section .bz-sites b{color:var(--t1)}'
      + '#businesses-section .bz-acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:auto}'
      + '#businesses-section .bz-empty{font-size:12px;color:var(--t3);padding:10px 0}'
      + '.bz-form{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:0 22px 8px}'
      + '.bz-form label{display:flex;flex-direction:column;gap:4px;font-size:11px;color:var(--t3);min-width:0}'
      + '.bz-form input,.bz-form textarea{width:100%;box-sizing:border-box;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);padding:8px 10px;font:inherit;font-size:13px}'
      + '.bz-form .full{grid-column:1/-1}'
      + '.bz-sitelist{display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:auto;padding:0 22px 8px;font-size:12px;color:var(--t2)}'
      + '.bz-sitelist label{display:flex;align-items:center;gap:8px}'
      + '@media (max-width:767px){.bz-form{grid-template-columns:1fr}#businesses-section .bz-grid{grid-template-columns:1fr}}';
    document.head.appendChild(st);
  }

  function card(b) {
    var sites = (b.websites || []);
    return '<div class="bz-card' + (b.is_default ? ' is-default' : '') + '" data-id="' + b.id + '">'
      + '<div class="bz-name"><span title="' + esc(b.name) + '">' + esc(b.name) + '</span>' + (b.is_default ? '<span class="bz-star">Default</span>' : '') + '</div>'
      + '<div class="bz-meta">' + esc([b.industry, b.location].filter(Boolean).join(' · ') || 'No industry or location yet') + (b.pricing_anchor ? '<br>' + esc(b.pricing_anchor) : '') + '</div>'
      + '<div class="bz-sites">' + (sites.length ? '<b>' + sites.length + '</b> website' + (sites.length === 1 ? '' : 's') + ': ' + esc(sites.map(function (s) { return s.name; }).slice(0, 3).join(', ')) + (sites.length > 3 ? ' +' + (sites.length - 3) : '') : 'No website yet') + (b.counts && b.counts.leads ? ' · <b>' + b.counts.leads + '</b> enquir' + (b.counts.leads === 1 ? 'y' : 'ies') : '') + '</div>'
      + '<div class="bz-acts">'
      + '<button type="button" class="lu-btn lu-btn--sm" data-a="edit">Edit</button>'
      + '<button type="button" class="lu-btn lu-btn--sm" data-a="sites">Websites</button>'
      + (b.is_default ? '' : '<button type="button" class="lu-btn lu-btn--sm" data-a="default">Make default</button>')
      + (b.is_default || sites.length ? '' : '<button type="button" class="lu-btn lu-btn--sm" data-a="delete" style="color:#F87171">Delete</button>')
      + '</div></div>';
  }

  function render() {
    var root = S.root; if (!root) return;
    var d = S.data || { businesses: [] };
    var list = d.businesses || [];
    root.innerHTML = '<div class="bz-head"><div><div class="bz-title">Your businesses</div><div class="bz-sub">'
      + (list.length > 1 ? 'Several businesses, one workspace. Sarah keeps them apart and asks which one when it isn\'t clear — no switching. The default business is the one the profile below describes.' : 'One business today. Add another when you run more than one — Sarah will keep them apart and ask which one when it isn\'t clear.')
      + '</div></div><button type="button" class="lu-btn lu-btn--primary lu-btn--sm" data-a="add">+ Add a business</button></div>'
      + (list.length ? '<div class="bz-grid">' + list.map(card).join('') + '</div>' : '<div class="bz-empty">No business profile yet — add one.</div>')
      + (d.unassigned_websites && d.unassigned_websites.length ? '<div class="bz-empty">' + d.unassigned_websites.length + ' website' + (d.unassigned_websites.length === 1 ? '' : 's') + ' not attached to a business yet: ' + esc(d.unassigned_websites.map(function (s) { return s.name; }).join(', ')) + ' — use Websites on a card to attach.</div>' : '');
    root.querySelector('[data-a=add]').onclick = function () { form(null); };
    root.querySelectorAll('.bz-card').forEach(function (c) {
      var id = parseInt(c.getAttribute('data-id'), 10); var b = list.filter(function (x) { return x.id === id; })[0];
      var q = function (a) { return c.querySelector('[data-a=' + a + ']'); };
      if (q('edit')) q('edit').onclick = function () { form(b); };
      if (q('sites')) q('sites').onclick = function () { sites(b); };
      if (q('default')) q('default').onclick = async function () { if (!(await luConfirm('Make ' + b.name + ' the default business?', 'Its profile becomes the workspace profile Sarah and the engines fall back to.', { okLabel: 'Make default' }))) return; try { await api('POST', '/businesses/' + b.id + '/default'); await load(); showToast(b.name + ' is now the default business.', 'success'); } catch (e) { showToast(e.message, 'error'); } };
      if (q('delete')) q('delete').onclick = async function () { if (!(await luConfirm('Delete ' + b.name + '?', 'Its profile is removed. Websites are never deleted here.', { okLabel: 'Delete', danger: true }))) return; try { await api('DELETE', '/businesses/' + b.id); await load(); showToast(b.name + ' removed.', 'success'); } catch (e) { showToast(e.message, 'error'); } };
    });
  }

  /* the add/edit form — an app dialog built on the shell's dialog classes (never native) */
  function form(b) {
    var ov = document.createElement('div'); ov.className = 'lu-dlg-overlay';
    var v = function (k) { return b ? esc(b[k] || '') : ''; };
    ov.innerHTML = '<div class="lu-dlg" role="dialog" aria-modal="true" aria-labelledby="bz-t" style="max-width:640px;width:calc(100% - 24px)">'
      + '<div class="lu-dlg-head" id="bz-t">' + (b ? 'Edit ' + esc(b.name) : 'Add a business') + '</div>'
      + '<div class="lu-dlg-body">' + (b ? 'What Sarah and the engines know about this business.' : 'A second business in this workspace: its own profile, its own websites, its own memory — no switching.') + '</div>'
      + '<div class="bz-form">'
      + '<label class="full">Name<input id="bz-name" value="' + v('name') + '" placeholder="e.g. Boss Mac Gym"></label>'
      + '<label>Industry<input id="bz-industry" value="' + v('industry') + '" placeholder="e.g. gym"></label>'
      + '<label>Location<input id="bz-location" value="' + v('location') + '" placeholder="e.g. Jersey City, NJ"></label>'
      + '<label class="full">Services (comma separated)<input id="bz-services" value="' + esc((b && b.services || []).join(', ')) + '" placeholder="e.g. Memberships, Personal training"></label>'
      + '<label class="full">Prices Sarah may quote<input id="bz-pricing" value="' + v('pricing_anchor') + '" placeholder="e.g. $49/month, personal training $60/session"></label>'
      + '<label>Tone<input id="bz-tone" value="' + v('tone') + '" placeholder="e.g. energetic, plain"></label>'
      + '<label>Target audience<input id="bz-audience" value="' + v('target_audience') + '" placeholder="e.g. busy professionals 25–45"></label>'
      + '<label class="full">What sets it apart<input id="bz-diff" value="' + v('differentiators') + '"></label>'
      + '<label class="full">Also called (comma separated — what you say to Sarah)<input id="bz-aliases" value="' + esc((b && b.aliases || []).join(', ')) + '" placeholder="e.g. the gym"></label>'
      + '</div>'
      + '<div class="lu-dlg-foot"><button type="button" class="lu-dlg-btn ghost" data-role="cancel">Cancel</button><button type="button" class="lu-dlg-btn primary" data-role="ok">' + (b ? 'Save' : 'Add business') + '</button></div></div>';
    document.body.appendChild(ov);
    var close = function () { try { ov.remove(); } catch (e) {} };
    ov.querySelector('[data-role=cancel]').onclick = close;
    ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(); });
    ov.querySelector('[data-role=ok]').onclick = async function () {
      var g = function (id) { return (ov.querySelector('#' + id).value || '').trim(); };
      var body = { name: g('bz-name'), industry: g('bz-industry'), location: g('bz-location'), services: g('bz-services'), pricing_anchor: g('bz-pricing'), tone: g('bz-tone'), target_audience: g('bz-audience'), differentiators: g('bz-diff'), aliases: g('bz-aliases') };
      if (!body.name) { showToast('A business needs a name.', 'warning'); return; }
      try { if (b) await api('PUT', '/businesses/' + b.id, body); else await api('POST', '/businesses', body); close(); await load(); showToast(b ? 'Saved.' : body.name + ' added.', 'success'); if (window.luLoadWorkspaceProfile) { try { window.luLoadWorkspaceProfile(); } catch (_e) {} } }
      catch (e) { showToast(e.message, 'error'); }
    };
    setTimeout(function () { try { ov.querySelector('#bz-name').focus(); } catch (e) {} }, 30);
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

  window.businessesLoad = function (root) { css(); S.root = root; root.innerHTML = '<div class="lu-skel" style="width:40%"></div>'; load(); };
  window.businessesList = async function () { try { return (await api('GET', '/businesses')).businesses || []; } catch (e) { return []; } };
})();
