/*
 * LU select — every <select> in the app shell becomes the site's own listbox (2026-09-21).
 *
 * Standing house rule: every dropdown, modal and scroller is the site's own CSS, never the browser's. Styling the
 * closed box of a <select> does not satisfy it: the open option list is drawn by the OS. The engines carry ~70
 * <select> elements between them (SEO, CRM, Write, Studio, Projects, Calendar, Blog, Domains, Settings…), so this
 * file upgrades ALL of them in one place, progressively:
 *
 *   - the native <select> stays in the DOM as the source of truth (forms, validation, every existing onchange /
 *     addEventListener('change') handler keeps working — we set .value and dispatch input + change);
 *   - a button shows the current option; a role="listbox" panel lists the options (optgroups become headings,
 *     disabled options stay disabled); arrows / Home / End / Enter / Space / Escape / type-ahead on the button;
 *   - the panel is position:fixed so it is never clipped by an overflow:hidden card or cut by a scroller, flips
 *     upward when there is no room below, closes on outside click, Escape, scroll and resize;
 *   - the button inherits the select's own computed box (font, padding, radius, height, colours) so each engine
 *     keeps its look; the panel is the LUC listbox panel (lu-controls.js tokens), correct in light and dark;
 *   - a MutationObserver enhances selects rendered later and re-reads options/values the engines rewrite.
 *
 * Opt out per element with data-native="1". <select multiple> and size>1 are left native (a different control).
 */
(function (w, d) {
  'use strict';
  if (w.LUSelect) { return; }
  var Z = 10070; /* above the editor's pickers (10050) and every engine modal */
  var css = '' +
    '.lu-sel{position:relative;display:inline-flex;vertical-align:middle;max-width:100%;min-width:0;box-sizing:border-box}' +
    '.lu-sel>select{position:absolute!important;opacity:0!important;pointer-events:none!important;width:1px!important;height:1px!important;margin:0!important;padding:0!important;border:0!important;left:0;top:0;overflow:hidden!important}' +
    '.lu-sel-btn{width:100%;box-sizing:border-box;display:inline-flex;align-items:center;gap:8px;cursor:pointer;text-align:left;min-width:0;' +
      'background:var(--s2,#151a26);border:1px solid var(--bd2,rgba(255,255,255,.12));border-radius:var(--r,10px);padding:8px 12px;color:var(--t1,inherit);font:inherit;line-height:1.3;-webkit-appearance:none;appearance:none}' +
    '.lu-sel-btn:hover{border-color:var(--p,#6C5CE7)}' +
    '.lu-sel-btn:focus-visible{outline:2px solid var(--p,#6C5CE7);outline-offset:1px}' +
    '.lu-sel-btn[disabled]{opacity:.55;cursor:not-allowed}' +
    '.lu-sel-btn .lu-sel-cur{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
    '.lu-sel-btn .lu-sel-cur.lu-sel-none{color:var(--t3,#7b8496)}' +
    '.lu-sel-btn .lu-sel-chev{width:14px;height:14px;flex-shrink:0;opacity:.6}' +
    '.lu-sel-menu{position:fixed;z-index:' + Z + ';box-sizing:border-box;background:var(--s1,#0f131c);border:1px solid var(--bd2,rgba(255,255,255,.12));border-radius:var(--r,10px);padding:4px;' +
      'max-height:min(280px,50vh);overflow-y:auto;overflow-x:hidden;box-shadow:0 12px 32px rgba(0,0,0,.28);scrollbar-width:thin;scrollbar-color:var(--bd2,rgba(255,255,255,.2)) transparent}' +
    '.lu-sel-menu::-webkit-scrollbar{width:8px}.lu-sel-menu::-webkit-scrollbar-thumb{background:var(--bd2,rgba(255,255,255,.2));border-radius:8px}.lu-sel-menu::-webkit-scrollbar-track{background:transparent}' +
    '.lu-sel-grp{padding:8px 10px 4px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--t3,#7b8496)}' +
    '.lu-sel-opt{width:100%;box-sizing:border-box;display:block;background:none;border:none;border-radius:8px;padding:8px 10px;color:var(--t1,inherit);font:inherit;font-size:13px;cursor:pointer;text-align:left;white-space:normal;line-height:1.35}' +
    '.lu-sel-opt:hover,.lu-sel-opt.lu-sel-active{background:var(--s3,rgba(255,255,255,.06));outline:none}' +
    '.lu-sel-opt[aria-selected="true"]{background:var(--s3,rgba(255,255,255,.06));box-shadow:inset 2px 0 0 var(--p,#6C5CE7)}' +
    '.lu-sel-opt[aria-disabled="true"]{opacity:.45;cursor:not-allowed}' +
    '@media (max-width:640px){.lu-sel-menu{max-height:min(300px,55vh)}.lu-sel-opt{padding:10px 12px;font-size:14px}}';
  var st = d.createElement('style'); st.id = 'lu-select-css'; st.textContent = css; (d.head || d.documentElement).appendChild(st);

  var CHEV = '<svg class="lu-sel-chev" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8l5 5 5-5"/></svg>';
  var open = null; /* { sel, btn, menu } */
  /* geometry only: colours are the shell's tokens (Owner 2026-09-21: a white listbox on the dark SEO page — the select's own
     colour tokens were unresolved at enhance time and the browser default was copied) */
  var COPY = ['font-size', 'font-weight', 'font-family', 'letter-spacing', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius', 'border-top-width', 'border-top-style', 'min-height', 'height'];

  function skip(sel) {
    return sel.multiple || sel.size > 1 || sel.getAttribute('data-native') === '1' || sel.closest('.lu-sel, .luc-lb') || sel.__luSel;
  }
  function label(sel) {
    var o = sel.options[sel.selectedIndex];
    return o ? (o.textContent || '').trim() : '';
  }
  function refresh(sel) {
    var b = sel.__luSel.btn, cur = b.querySelector('.lu-sel-cur'), t = label(sel);
    var o = sel.options[sel.selectedIndex];
    var placeholder = o && (o.value === '' || o.disabled) && sel.selectedIndex === 0 && t;
    cur.textContent = t || (sel.getAttribute('data-placeholder') || 'Choose…');
    cur.classList.toggle('lu-sel-none', !t || !!placeholder);
    b.disabled = sel.disabled;
    /* an engine that hides its select (style/hidden/class) hides the control too */
    var hide = sel.hidden || sel.style.display === 'none' || sel.style.visibility === 'hidden';
    sel.__luSel.wrap.style.display = hide ? 'none' : (sel.__luSel.disp || '');
    b.setAttribute('aria-label', sel.getAttribute('aria-label') || (sel.labels && sel.labels[0] ? sel.labels[0].textContent.trim() : '') || sel.title || sel.name || 'Select');
  }
  var LIGHT = ['font-size', 'font-weight', 'font-family', 'letter-spacing', 'color'];
  function sizeFrom(sel, wrap, btn, light) {
    /* the button takes the select's own box so each engine keeps its look; the wrapper takes the select's width rule.
       light = the select is already wrapped and 1px (measured late): only its type and colour are still true */
    var cs = w.getComputedStyle(sel), props = light ? LIGHT : COPY;
    if (light) { for (var q = 0; q < props.length; q++) { var lv = cs.getPropertyValue(props[q]); if (lv) { btn.style.setProperty(props[q], lv); } } return; }
    for (var i = 0; i < COPY.length; i++) {
      var v = cs.getPropertyValue(COPY[i]);
      if (!v || v === 'auto' || v === 'normal') { continue; }
      if (COPY[i] === 'height' && (v === 'auto' || sel.getAttribute('data-h') === 'auto')) { continue; }
      if (COPY[i] === 'background-color' && /rgba\(0, 0, 0, 0\)|transparent/.test(v)) { continue; }
      if (COPY[i] === 'border-top-width' && v === '0px') { btn.style.border = 'none'; continue; }
      btn.style.setProperty(COPY[i] === 'border-top-color' ? 'border-color' : COPY[i] === 'border-top-width' ? 'border-width' : COPY[i] === 'border-top-style' ? 'border-style' : COPY[i], v);
    }
    var inline = sel.style.width;
    if (inline) { wrap.style.width = inline; }
    else {
      var pw = sel.parentElement ? sel.parentElement.getBoundingClientRect().width : 0, sw = sel.getBoundingClientRect().width;
      if (cs.width && /%/.test(cs.width)) { wrap.style.width = cs.width; }
      else if (pw && Math.abs(pw - sw) < 3) { wrap.style.width = '100%'; }
      else if (sw > 0) { wrap.style.width = Math.ceil(sw) + 'px'; }
      else { wrap.style.width = '100%'; }
    }
    if (cs.display === 'block' || cs.display === 'flex') { wrap.style.display = 'flex'; sel.__luSel.disp = 'flex'; }
    if (cs.flexGrow && cs.flexGrow !== '0') { wrap.style.flex = cs.flex; }
    if (cs.marginTop !== '0px' || cs.marginBottom !== '0px' || cs.marginLeft !== '0px' || cs.marginRight !== '0px') { wrap.style.margin = cs.margin; }
  }
  function enhance(sel) {
    if (skip(sel)) { return; }
    var wrap = d.createElement('span'); wrap.className = 'lu-sel';
    var btn = d.createElement('button'); btn.type = 'button'; btn.className = 'lu-sel-btn'; btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span class="lu-sel-cur"></span>' + CHEV;
    sel.__luSel = { btn: btn, wrap: wrap };
    var laidOut = sel.getBoundingClientRect().width > 0;
    if (laidOut) { sizeFrom(sel, wrap, btn); } else { wrap.style.width = '100%'; wrap.__luSelUnsized = true; }
    sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(sel); wrap.appendChild(btn);
    if (!laidOut && io) { io.observe(wrap); }
    sel.tabIndex = -1;
    refresh(sel);
    btn.addEventListener('click', function (e) { e.stopPropagation(); if (open && open.sel === sel) { close(); } else { show(sel); } });
    btn.addEventListener('keydown', function (e) { onKey(e, sel); });
    sel.addEventListener('change', function () { refresh(sel); });
    new MutationObserver(function () { refresh(sel); if (open && open.sel === sel) { render(sel); } }).observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'selected', 'value', 'aria-label', 'style', 'hidden', 'class'] });
  }
  function render(sel) {
    var m = open.menu, html = '', idx = 0;
    function opt(o) {
      html += '<button type="button" class="lu-sel-opt" role="option" data-i="' + idx + '" aria-selected="' + (o.index === sel.selectedIndex ? 'true' : 'false') + '"' + (o.disabled ? ' aria-disabled="true"' : '') + '>' + esc((o.textContent || '').trim() || ' ') + '</button>';
      idx++;
    }
    Array.prototype.forEach.call(sel.children, function (c) {
      if (c.tagName === 'OPTGROUP') { html += '<div class="lu-sel-grp">' + esc(c.label) + '</div>'; Array.prototype.forEach.call(c.children, opt); }
      else if (c.tagName === 'OPTION') { opt(c); }
    });
    m.innerHTML = html;
  }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function place() {
    if (!open) { return; }
    var r = open.btn.getBoundingClientRect(), m = open.menu, vh = w.innerHeight, vw = w.innerWidth;
    m.style.minWidth = Math.max(160, r.width) + 'px'; m.style.width = 'auto'; m.style.maxWidth = Math.min(vw - 16, Math.max(r.width, 360)) + 'px';
    var mh = m.offsetHeight, below = vh - r.bottom - 8, above = r.top - 8;
    var top = (mh <= below || below >= above) ? r.bottom + 4 : Math.max(8, r.top - 4 - mh);
    if (mh > below && below < above) { m.style.maxHeight = Math.min(mh, above) + 'px'; top = Math.max(8, r.top - 4 - m.offsetHeight); }
    else if (mh > below) { m.style.maxHeight = Math.max(120, below) + 'px'; }
    var left = Math.min(r.left, vw - m.offsetWidth - 8);
    m.style.top = Math.round(top) + 'px'; m.style.left = Math.round(Math.max(8, left)) + 'px';
  }
  function show(sel) {
    close();
    if (sel.disabled) { return; }
    var m = d.createElement('div'); m.className = 'lu-sel-menu'; m.setAttribute('role', 'listbox');
    open = { sel: sel, btn: sel.__luSel.btn, menu: m };
    render(sel);
    d.body.appendChild(m);
    open.btn.setAttribute('aria-expanded', 'true');
    m.style.maxHeight = ''; place();
    var cur = m.querySelector('[aria-selected="true"]') || m.querySelector('.lu-sel-opt'); if (cur) { setActive(cur); cur.scrollIntoView({ block: 'nearest' }); }
    m.addEventListener('click', function (e) { var o = e.target.closest('.lu-sel-opt'); if (!o) { return; } e.stopPropagation(); if (o.getAttribute('aria-disabled') === 'true') { return; } choose(sel, +o.getAttribute('data-i')); });
    m.addEventListener('mousemove', function (e) { var o = e.target.closest('.lu-sel-opt'); if (o) { setActive(o); } });
    m.addEventListener('keydown', function (e) { onKey(e, sel); });
    setTimeout(function () { d.addEventListener('click', onDoc, true); w.addEventListener('scroll', onScroll, true); w.addEventListener('resize', close); }, 0);
  }
  function onDoc(e) { if (open && !open.menu.contains(e.target) && !open.btn.contains(e.target)) { close(); } }
  function onScroll(e) { if (open && !(e.target && e.target.closest && e.target.closest('.lu-sel-menu'))) { place(); } }
  function close() {
    if (!open) { return; }
    open.btn.setAttribute('aria-expanded', 'false'); open.menu.remove();
    d.removeEventListener('click', onDoc, true); w.removeEventListener('scroll', onScroll, true); w.removeEventListener('resize', close);
    open = null;
  }
  function setActive(o) { if (!open) { return; } Array.prototype.forEach.call(open.menu.querySelectorAll('.lu-sel-active'), function (x) { x.classList.remove('lu-sel-active'); }); o.classList.add('lu-sel-active'); o.focus({ preventScroll: true }); }
  function choose(sel, i) {
    var opts = optionList(sel), o = opts[i]; if (!o || o.disabled) { return; }
    var changed = sel.selectedIndex !== o.index;
    sel.selectedIndex = o.index;
    var btn = sel.__luSel.btn; close(); refresh(sel); btn.focus();
    if (changed) { sel.dispatchEvent(new Event('input', { bubbles: true })); sel.dispatchEvent(new Event('change', { bubbles: true })); }
  }
  function optionList(sel) { var a = []; Array.prototype.forEach.call(sel.children, function (c) { if (c.tagName === 'OPTGROUP') { Array.prototype.push.apply(a, c.children); } else if (c.tagName === 'OPTION') { a.push(c); } }); return a; }
  var typed = '', typedAt = 0;
  function onKey(e, sel) {
    var k = e.key;
    if (!open || open.sel !== sel) {
      if (k === 'ArrowDown' || k === 'ArrowUp' || k === 'Enter' || k === ' ') { e.preventDefault(); show(sel); }
      return;
    }
    var items = Array.prototype.slice.call(open.menu.querySelectorAll('.lu-sel-opt')), cur = open.menu.querySelector('.lu-sel-active'), i = items.indexOf(cur);
    if (k === 'Escape') { e.preventDefault(); close(); sel.__luSel.btn.focus(); return; }
    if (k === 'Tab') { close(); return; }
    if (k === 'ArrowDown') { e.preventDefault(); setActive(items[Math.min(items.length - 1, i + 1)]); return; }
    if (k === 'ArrowUp') { e.preventDefault(); setActive(items[Math.max(0, i - 1)]); return; }
    if (k === 'Home') { e.preventDefault(); setActive(items[0]); return; }
    if (k === 'End') { e.preventDefault(); setActive(items[items.length - 1]); return; }
    if (k === 'Enter' || k === ' ') { e.preventDefault(); if (cur) { choose(sel, +cur.getAttribute('data-i')); } return; }
    if (k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
      var now = Date.now(); typed = (now - typedAt < 700 ? typed : '') + k.toLowerCase(); typedAt = now;
      var hit = items.filter(function (x) { return x.textContent.trim().toLowerCase().indexOf(typed) === 0; })[0]; if (hit) { setActive(hit); hit.scrollIntoView({ block: 'nearest' }); }
    }
  }


  /* ---- input[type=color]: the OS colour dialog is replaced by LUColorPicker (lu-colorpicker.js) ---------------- */
  var CCSS = '.lu-color{position:relative;display:inline-flex;vertical-align:middle;max-width:100%}' +
    '.lu-color>input[type=color]{position:absolute!important;opacity:0!important;pointer-events:none!important;width:1px!important;height:1px!important;left:0;top:0}' +
    '.lu-color-btn{display:inline-flex;align-items:center;gap:8px;cursor:pointer;background:var(--s2,#151a26);border:1px solid var(--bd2,rgba(255,255,255,.12));border-radius:var(--r,10px);padding:4px 10px 4px 4px;color:var(--t1,inherit);font:inherit;font-size:12px;min-height:32px}' +
    '.lu-color-btn:hover{border-color:var(--p,#6C5CE7)}.lu-color-btn:focus-visible{outline:2px solid var(--p,#6C5CE7);outline-offset:1px}' +
    '.lu-color-btn i{display:block;width:24px;height:24px;border-radius:7px;border:1px solid rgba(255,255,255,.18)}' +
    '.lu-color-btn code{font:inherit;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.02em;opacity:.85}';
  st.textContent += CCSS;
  function colorRefresh(inp) { var b = inp.__luColor; if (!b) { return; } b.querySelector('i').style.background = inp.value || '#000000'; b.querySelector('code').textContent = (inp.value || '').toUpperCase(); b.disabled = inp.disabled; b.parentElement.style.display = (inp.hidden || inp.style.display === 'none') ? 'none' : ''; }
  function colorEnhance(inp) {
    if (inp.__luColor || inp.getAttribute('data-native') === '1' || inp.closest('.lu-color, .lucp, .luc-sc')) { return; }
    var wrap = d.createElement('span'); wrap.className = 'lu-color';
    var btn = d.createElement('button'); btn.type = 'button'; btn.className = 'lu-color-btn'; btn.setAttribute('aria-haspopup', 'dialog');
    btn.setAttribute('aria-label', (inp.labels && inp.labels[0] ? inp.labels[0].textContent.trim() + ' — ' : '') + 'choose colour');
    btn.innerHTML = '<i aria-hidden="true"></i><code></code>';
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp); wrap.appendChild(btn); inp.tabIndex = -1; inp.__luColor = btn;
    colorRefresh(inp);
    inp.addEventListener('input', function () { colorRefresh(inp); }); inp.addEventListener('change', function () { colorRefresh(inp); });
    new MutationObserver(function () { colorRefresh(inp); }).observe(inp, { attributes: true, attributeFilter: ['value', 'disabled', 'style', 'hidden'] });
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (!w.LUColorPicker || !w.LUColorPicker.open) { return; }
      /* the brand trio edits together; any other input edits alone (its value rides the primary role) */
      var m = /^(.*?)(primary|secondary|accent)(.*)$/.exec(inp.id || ''), trio = null;
      if (m) { trio = { primary: d.getElementById(m[1] + 'primary' + m[3]), secondary: d.getElementById(m[1] + 'secondary' + m[3]), accent: d.getElementById(m[1] + 'accent' + m[3]) }; if (!trio.primary || !trio.secondary || !trio.accent) { trio = null; } }
      var colors = trio ? { primary: trio.primary && trio.primary.value, secondary: trio.secondary && trio.secondary.value, accent: trio.accent && trio.accent.value } : { primary: inp.value, secondary: inp.value, accent: inp.value };
      w.LUColorPicker.open({ colors: colors, onSave: function (out) {
        var targets = trio ? trio : { primary: inp };
        Object.keys(targets).forEach(function (role) { var t = targets[role]; if (!t || !out || !out[role]) { return; } var hex = String(out[role]).slice(0, 7); if (t.value !== hex) { t.value = hex; t.dispatchEvent(new Event('input', { bubbles: true })); t.dispatchEvent(new Event('change', { bubbles: true })); } colorRefresh(t); });
        btn.focus();
      }, onCancel: function () { btn.focus(); } });
    });
  }

  /* Cheap by construction: no polling; only added subtrees are scanned, after the mutation burst settles. A select that is
     not laid out yet (a hidden view or tab) is enhanced now and measured the first time it is on screen. */
  var io = ('IntersectionObserver' in w) ? new IntersectionObserver(function (entries) { for (var i = 0; i < entries.length; i++) { var wr = entries[i].target; if (entries[i].isIntersecting && wr.__luSelUnsized) { var sel = wr.querySelector('select'); wr.__luSelUnsized = false; io.unobserve(wr); if (sel) { sizeFrom(sel, wr, sel.__luSel.btn, true); refresh(sel); } } } }) : null;
  function scan(root) {
    if (!root || root.nodeType !== 1) { return; }
    var list = root.querySelectorAll ? root.querySelectorAll('select') : [];
    for (var i = 0; i < list.length; i++) { if (!skip(list[i])) { enhance(list[i]); } }
    if (root.tagName === 'SELECT' && !skip(root)) { enhance(root); }
    var cl = root.querySelectorAll ? root.querySelectorAll('input[type=color]') : [];
    for (var j = 0; j < cl.length; j++) { colorEnhance(cl[j]); }
    if (root.tagName === 'INPUT' && root.type === 'color') { colorEnhance(root); }
  }
  var queue = [], timer = null;
  function schedule() { if (timer) { clearTimeout(timer); } timer = setTimeout(function () { timer = null; var q = queue; queue = []; for (var i = 0; i < q.length; i++) { if (q[i].isConnected) { scan(q[i]); } } }, 100); }
  function start() {
    scan(d.body);
    new MutationObserver(function (muts) {
      /* enhanced inside the callback (a microtask, before paint): no native box flashes before the listbox */
      for (var i = 0; i < muts.length; i++) { var m = muts[i]; for (var k = 0; k < m.addedNodes.length; k++) { var n = m.addedNodes[k]; if (n.nodeType === 1 && n.isConnected && !n.closest('.lu-sel, .lu-sel-menu, .lu-color, .lucp')) { scan(n); } } }
    }).observe(d.body, { childList: true, subtree: true });
  }
  if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', start); } else { start(); }
  w.LUSelect = { enhance: enhance, scan: scan, close: close, refresh: function (sel) { if (sel && sel.__luSel) { refresh(sel); } } };
})(window, document);
