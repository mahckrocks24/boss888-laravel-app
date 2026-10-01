// FIX-ALL (2026-10-01): builder.js — Fonts panel (FONTS-7). Run on staging with node; exact anchors, one hit each.
const fs = require('fs');
const P = '/var/www/levelup-staging/public/app/js/builder.js';
let s = fs.readFileSync(P, 'utf8');
fs.writeFileSync(P + '.before-fixall', s);
function rep(oldS, newS, expect = 1) {
  const n = s.split(oldS).length - 1;
  if (n !== expect) { console.error('ANCHOR x' + n + ' (want ' + expect + '): ' + oldS.slice(0, 120)); process.exit(1); }
  s = s.split(oldS).join(newS);
  console.log('ok x' + n + ': ' + oldS.slice(0, 60).replace(/\n/g, ' '));
}

// 1. the two Colours buttons get a Fonts neighbour (shown when the flag is on)
rep(String.raw`'<button type="button" onclick="wsOpenPalettes(' + wsId + ')" title="Colour palettes — hover to preview, click to apply" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12.5px;font-family:var(--fb)">Colours</button>' +`,
    String.raw`'<button type="button" onclick="wsOpenPalettes(' + wsId + ')" title="Colour palettes — hover to preview, click to apply" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12.5px;font-family:var(--fb)">Colours</button>' +
      '<button type="button" class="t3-fonts-btn" onclick="wsOpenFonts(' + wsId + ')" title="Fonts — hover to preview, click to apply" style="' + ((window._t3Flags && window._t3Flags.fonts) ? '' : 'display:none;') + 'background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12.5px;font-family:var(--fb)">Fonts</button>' +`);
rep(String.raw`'<button type="button" onclick="wsOpenPalettes(' + (site.id || 0) + ')" title="Colour palettes — click to apply" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:13px">Colours</button>' +`,
    String.raw`'<button type="button" onclick="wsOpenPalettes(' + (site.id || 0) + ')" title="Colour palettes — click to apply" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:13px">Colours</button>' +
        '<button type="button" class="t3-fonts-btn" onclick="wsOpenFonts(' + (site.id || 0) + ')" title="Fonts — click to apply" style="' + ((window._t3Flags && window._t3Flags.fonts) ? '' : 'display:none;') + 'background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:13px">Fonts</button>' +`);

// 2. the flags loader shows the button once it knows
rep(String.raw`.then(function (j) { window._t3Flags = j || {}; window._t3IndustrySlug = (j && j.industry) || ''; return window._t3Flags; })`,
    String.raw`.then(function (j) { window._t3Flags = j || {}; window._t3IndustrySlug = (j && j.industry) || ''; try { document.querySelectorAll('.t3-fonts-btn').forEach(function (b) { b.style.display = (j && j.fonts) ? '' : 'none'; }); } catch (_f) {} return window._t3Flags; })`);

// 3. one floating surface at a time
rep(String.raw`  if (except !== 't3-lay') {
    var l = document.getElementById('t3-lay');`,
    String.raw`  if (except !== 't3-fonts') {
    var ft = document.getElementById('t3-fonts');
    if (ft) { ft.remove(); try { _t3FontRestore(); } catch (e) {} }
  }
  if (except !== 't3-lay') {
    var l = document.getElementById('t3-lay');`);

// 4. the panel itself, next to the palette panel
rep(String.raw`/* Three-way exit choice, in site CSS (never a native dialog). Resolves 'save' | 'discard' | 'stay'. */`,
    String.raw`/* FONTS-7 (RFC-0021 closure, 2026-10-01): curated font pairings. Hover writes the pairing's own layer into the preview frame
   (the very bytes the server will write), leave takes it out, click applies — free, snapshotted, Undo puts the old fonts back.
   "The design's own" returns to the typography the design was made with. */
var _t3FontPrevOn = false;
function _t3FontFrameDoc() {
  var f = document.getElementById('t3-preview');
  var doc = null; try { doc = f && f.contentDocument; } catch (_e) {}
  return (doc && doc.head) ? doc : null;
}
function _t3FontPreview(layer) {
  var doc = _t3FontFrameDoc(); if (!doc) return;
  _t3FontRestore();
  var base = doc.getElementById('lug-design-style'); if (base) { base.disabled = true; base.setAttribute('data-t3-off', '1'); }
  var box = doc.createElement('div'); box.innerHTML = layer || '';
  Array.prototype.slice.call(box.querySelectorAll('link,style')).forEach(function (el) {
    var n = doc.createElement(el.tagName.toLowerCase());
    Array.prototype.slice.call(el.attributes).forEach(function (a) { if (a.name !== 'id') n.setAttribute(a.name, a.value); });
    if (el.tagName.toLowerCase() === 'style') n.textContent = el.textContent;
    n.setAttribute('data-t3-fp', '1'); doc.head.appendChild(n);
  });
  _t3FontPrevOn = true;
}
function _t3FontRestore() {
  var doc = _t3FontFrameDoc(); if (!doc) { _t3FontPrevOn = false; return; }
  Array.prototype.slice.call(doc.querySelectorAll('[data-t3-fp]')).forEach(function (el) { el.remove(); });
  var base = doc.getElementById('lug-design-style'); if (base && base.getAttribute('data-t3-off')) { base.disabled = false; base.removeAttribute('data-t3-off'); }
  _t3FontPrevOn = false;
}
window.wsOpenFonts = async function (siteId) {
  var old = document.getElementById('t3-fonts');
  if (old) { old.remove(); _t3FontRestore(); return; }
  _t3CloseFloating('t3-fonts');
  var stage = document.querySelector('#template-editor-view .pe-stage') || document.getElementById('pe-frame-wrap') || document.body;
  var panel = document.createElement('div');
  panel.id = 't3-fonts'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Fonts');
  panel.style.cssText = 'position:absolute;top:10px;right:10px;width:min(360px,calc(100% - 20px));max-height:calc(100% - 20px);overflow:auto;z-index:120;background:var(--s1);border:1px solid var(--bd2);border-radius:var(--rg,12px);box-shadow:0 18px 48px rgba(0,0,0,.28);padding:14px;font-family:var(--fb)';
  panel.innerHTML = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:2px"><div style="font:700 14px var(--fh);color:var(--t1);flex:1">Fonts</div><button type="button" id="t3-fonts-x" aria-label="Close" style="background:none;border:1px solid var(--bd);color:var(--t2);border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:16px;line-height:1">×</button></div>'
    + '<div id="t3-fonts-sub" style="font-size:12px;color:var(--t3);margin-bottom:12px">Hover to preview, click to apply. The design keeps its own fonts until you choose a pairing. Undo puts the old fonts back.</div>'
    + '<div id="t3-fonts-list"><div class="lu-skel" style="width:80%"></div><div class="lu-skel" style="width:60%;margin-top:8px"></div></div>';
  stage.appendChild(panel);
  panel.querySelector('#t3-fonts-x').addEventListener('click', function () { panel.remove(); _t3FontRestore(); });
  var auth = { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' };
  var list = panel.querySelector('#t3-fonts-list');
  var data = null;
  try {
    var r = await fetch(API + 'builder/websites/' + siteId + '/fonts', { headers: auth, cache: 'no-store' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    data = await r.json();
  } catch (e) {
    list.innerHTML = '<div class="lu-empty"><b>Couldn’t load fonts</b>' + bld_escH(e.message) + '</div>';
    return;
  }
  var pairs = (data && data.pairs) || [];
  var current = (data && data.current) || 'design';
  if (!data || !data.success || !pairs.length) { list.innerHTML = '<div class="lu-empty"><b>' + ((data && data.error === 'off') ? 'Font pairings are not available yet' : 'No font pairings for this site') + '</b></div>'; return; }
  if (data.preview_css && !document.getElementById('t3-fonts-css')) { var lk = document.createElement('link'); lk.id = 't3-fonts-css'; lk.rel = 'stylesheet'; lk.href = data.preview_css; document.head.appendChild(lk); }
  var canPreview = !!data.is_static;
  if (!canPreview) { var sub = panel.querySelector('#t3-fonts-sub'); if (sub) sub.textContent = 'This site is rendered live, so its fonts are set in the design settings.'; }
  list.innerHTML = '';
  list.style.cssText = 'display:grid;grid-template-columns:1fr;gap:8px';
  var applying = false;
  function tagFor(p) { return current === p.id ? '✓ Current' : (p.recommended ? 'Recommended for you' : ''); }
  pairs.forEach(function (p) {
    var card = document.createElement('button');
    card.type = 'button';
    card.setAttribute('data-pair', p.id);
    var isCur = current === p.id;
    card.style.cssText = 'text-align:left;padding:10px 12px;background:var(--s2);border:1px solid ' + (isCur ? 'var(--p)' : 'var(--bd)') + ';border-radius:10px;cursor:pointer;color:var(--t1);font-family:var(--fb)';
    var sample = p.display ? '<div style="font-family:\'' + bld_escH(p.display) + '\',Georgia,serif;font-size:22px;line-height:1.1;color:var(--t1)">' + bld_escH(p.label) + '</div><div style="font-family:\'' + bld_escH(p.body) + '\',system-ui,sans-serif;font-size:12.5px;color:var(--t2);margin-top:4px">' + bld_escH(p.display) + ' with ' + bld_escH(p.body) + ' — ' + bld_escH(p.note) + '</div>'
                           : '<div style="font-size:15px;font-weight:600;line-height:1.2">' + bld_escH(p.label) + '</div><div style="font-size:12.5px;color:var(--t2);margin-top:4px">' + bld_escH(p.note) + '</div>';
    card.innerHTML = sample + '<div class="t3-fonts-tag" style="font-size:10.5px;color:var(--t3);margin-top:5px;min-height:13px">' + tagFor(p) + '</div>';
    if (canPreview) {
      card.addEventListener('mouseenter', function () { if (!applying) _t3FontPreview(p.layer || ''); });
      card.addEventListener('mouseleave', function () { if (!applying) _t3FontRestore(); });
    }
    card.addEventListener('click', async function () {
      if (applying) return;
      applying = true;
      var tag = card.querySelector('.t3-fonts-tag'); if (tag) tag.textContent = 'Applying…';
      try {
        var rr = await fetch(API + 'builder/websites/' + siteId + '/fonts', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, auth), body: JSON.stringify({ pair: p.id }) });
        var jj = null; try { jj = await rr.json(); } catch (_e) {}
        if (!rr.ok || !jj || !jj.success) throw new Error((jj && (jj.message || jj.error)) || ('HTTP ' + rr.status));
        current = p.id;
        _t3FontRestore();
        list.querySelectorAll('button[data-pair]').forEach(function (b) {
          var q = pairs.filter(function (x) { return x.id === b.getAttribute('data-pair'); })[0] || {};
          b.style.borderColor = q.id === p.id ? 'var(--p)' : 'var(--bd)';
          var t = b.querySelector('.t3-fonts-tag'); if (t) t.textContent = tagFor(q);
        });
        if (typeof showToast === 'function') showToast(jj.message || ('Switched to ' + p.label), 'success');
        _t3ReloadPreview();
      } catch (e) {
        _t3FontRestore();
        if (tag) tag.textContent = tagFor(p);
        if (typeof showToast === 'function') showToast("Couldn’t switch fonts — " + e.message, 'error');
      } finally { applying = false; }
    });
    list.appendChild(card);
  });
};

/* Three-way exit choice, in site CSS (never a native dialog). Resolves 'save' | 'discard' | 'stay'. */`);

fs.writeFileSync(P, s);
console.log('builder.js patched');
