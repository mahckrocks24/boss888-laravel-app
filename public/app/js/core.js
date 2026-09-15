// ═══════════════════════════════════════════════════════════════════
// LGSC Embed Mode Detection — MUST RUN FIRST, before any fetch() fires
// Reads ?lgsc_key=...&lgsc_ws=...&embed=1 from the URL query (hash fallback).
// Sets window._LGSC_EMBED so every auth helper below can switch to X-API-KEY.
// ═══════════════════════════════════════════════════════════════════
(function _lgscDetectEmbed() {
  var params = new URLSearchParams(window.location.search || '');
  var key    = params.get('lgsc_key');
  var wsId   = params.get('lgsc_ws');
  var embed  = params.get('embed');
  if (!key) {
    var hash = window.location.hash || '';
    var qIdx = hash.indexOf('?');
    if (qIdx !== -1) {
      var hp = new URLSearchParams(hash.substring(qIdx + 1));
      key   = hp.get('lgsc_key');
      wsId  = hp.get('lgsc_ws');
      embed = hp.get('embed');
    }
  }
  if (key && wsId && embed === '1') {
    window._LGSC_EMBED = {
      api_key:      key,
      workspace_id: parseInt(wsId, 10),
      embed:        true,
    };
    // Change 3: capture WP parent origin for strict-origin postMessage bridge
    var _lgscWpOriginParam = params.get('lgsc_wp_origin');
    if (!_lgscWpOriginParam) {
      var _hashStr = window.location.hash || '';
      var _qIdx    = _hashStr.indexOf('?');
      if (_qIdx >= 0) {
        _lgscWpOriginParam = new URLSearchParams(_hashStr.substring(_qIdx + 1)).get('lgsc_wp_origin');
      }
    }
    if (_lgscWpOriginParam) {
      try { window._LGSC_EMBED.wp_origin = decodeURIComponent(_lgscWpOriginParam); } catch (_e) {}
    }

    // Wave 47b — capture the WP install URL (lgsc_site or lgsc_origin) so
    // features like the AEO audit can default to the user's own site.
    var _lgscSiteParam = params.get('lgsc_site') || params.get('lgsc_origin');
    if (!_lgscSiteParam) {
      var _hs = window.location.hash || '';
      var _qi = _hs.indexOf('?');
      if (_qi >= 0) {
        var _hp = new URLSearchParams(_hs.substring(_qi + 1));
        _lgscSiteParam = _hp.get('lgsc_site') || _hp.get('lgsc_origin');
      }
    }
    if (_lgscSiteParam) {
      try { window._LGSC_EMBED.site_url = decodeURIComponent(_lgscSiteParam); } catch (_e) {}
    }
    if (typeof document !== 'undefined' && document.documentElement) {
      document.documentElement.classList.add('lgsc-embed-mode');
    }
    try { console.log('[LGSC] Embed mode detected, ws=' + wsId + ', key=' + key.substring(0, 12) + '…'); } catch (_) {}
  }
})();

// Wave 55 — Universal timestamp parser. Backend returns 'YYYY-MM-DD HH:MM:SS'
// without a TZ marker; per ECMAScript spec the browser would parse those as
// LOCAL time, so a UTC-stored value appears wrong by the device's UTC offset.
// _luParseTs treats marker-less strings as UTC. ISO strings (with Z or
// numeric offset) pass through unchanged. Numeric epochs also pass through.
window._luParseTs = function (s) {
  if (s === null || s === undefined) return null;
  if (typeof s !== 'string') return new Date(s);
  // Already has explicit TZ (Z, +HH:MM, -HH:MM after the time portion)
  if (/(Z|[+-]\d\d:?\d\d)$/.test(s)) return new Date(s);
  // ISO with T but no TZ — treat as UTC
  if (s.indexOf('T') >= 0) return new Date(s + 'Z');
  // SQL-style 'YYYY-MM-DD HH:MM:SS' — convert to ISO + Z
  return new Date(s.replace(' ', 'T') + 'Z');
};

// 2026-05-12 — embed-mode nav visibility: show Pipeline + Write tabs
// when ?lgsc_key&embed=1 is present in the URL (set by core.js's
// _lgscDetectEmbed IIFE above), regardless of plan slug. In direct SPA
// access these tabs follow the standard plan gating below.
(function _lgscApplyEmbedNav() {
  function show(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = '';
  }
  function applyOnReady() {
    if (window._LGSC_EMBED && window._LGSC_EMBED.api_key) {
      // ni-pipeline removed from sidebar — Pipeline is now a tab inside SEO.
      show('ni-write');
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyOnReady);
  } else {
    applyOnReady();
  }
})();

// 2026-05-16 v1.1 — WP bundle plan visibility gate.
// When the workspace plan slug starts with 'wp_', hide nav items the
// bundle doesn't include (builder/social/write/agents). Driven by
// data-section attributes on .nav-item elements. The flag is set by
// core.js itself after the workspace profile loads.
(function _lgscApplyBundleVisibility() {
  var WP_BUNDLE_VISIBLE = ['seo','chatbot','crm','calendar','account','billing','write'];
  window._lgsc_apply_bundle_gate = function (planSlug) {
    if (!planSlug || planSlug.indexOf('wp_') !== 0) { return; }
    window._lgsc_plan = planSlug;
    window._lgsc_is_wp_bundle = true;
    try {
      document.querySelectorAll('[data-section]').forEach(function (el) {
        var sec = el.getAttribute('data-section');
        if (WP_BUNDLE_VISIBLE.indexOf(sec) === -1) {
          el.style.display = 'none';
        }
      });
    } catch (e) { /* DOM not ready yet */ }
  };
})();

// LevelUp Core v3.2.2
// ═══════════════════════════════════════════════════════════════════
// LevelUp Core JS v3.0.2
// Engines: CRM, Marketing, Social, Calendar, SEO loaded on demand
// ═══════════════════════════════════════════════════════════════════
window.LU_LOADED_ENGINES = {};

// ── Missing global helpers (originally in WP core plugin) ──────────────────
function showPlanGate(message) {
  var overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center';
  overlay.innerHTML = '<div style="background:var(--s1,#171A21);border:1px solid var(--bd,#2a2d3e);border-radius:20px;padding:36px;max-width:440px;width:90%;text-align:center">'
    + '<div style="width:56px;height:56px;background:linear-gradient(135deg,#6C5CE7,#A78BFA);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:16px">\u2B50</div>'
    + '<div style="font-family:var(--fh,Syne),sans-serif;font-size:20px;font-weight:800;color:var(--t1,#E8EDF5);margin-bottom:8px">Upgrade Required</div>'
    + '<div style="font-size:14px;color:var(--t2,#8B97B0);line-height:1.6;margin-bottom:24px">' + message + '</div>'
    + '<div style="display:flex;gap:10px;justify-content:center">'
    + '<button onclick="this.closest(\u0027div[style*=fixed]\u0027).remove()" style="padding:10px 20px;border-radius:10px;border:1px solid var(--bd,#2a2d3e);background:transparent;color:var(--t2,#8B97B0);cursor:pointer;font-size:13px">Maybe Later</button>'
    + '<button onclick="window.open(\u0027/pricing\u0027,\u0027_blank\u0027);this.closest(\u0027div[style*=fixed]\u0027).remove()" style="padding:10px 24px;border-radius:10px;border:none;background:linear-gradient(135deg,#6C5CE7,#A78BFA);color:#fff;cursor:pointer;font-size:13px;font-weight:600">View Plans</button>'
    + '</div></div>';
  document.body.appendChild(overlay);
  overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });
}

function loadingCard(h) {
  return '<div style="display:flex;align-items:center;justify-content:center;min-height:' + (h || 200) + 'px;color:var(--t2,#8B97B0)"><div style="text-align:center"><div style="font-size:24px;margin-bottom:8px;animation:spin 1s linear infinite">⟳</div><div style="font-size:13px">Loading…</div></div></div>';
}

// ═══ P0-A (2026-08-30) — the ONE dialog + ONE toast layer of the customer SPA ══════════════════════════
// Before this, four definitions of luConfirm/luPrompt/luAlert (core, media-picker, studio ×2) with three
// different argument orders overrode each other at boot, ~45 overlays sat on 20 z-index values and six toast
// implementations existed (REPORT-0023 §2 UX-011/012/013). Everything below is tokenised, keyboard-operable
// (Escape, Enter, Tab trap, focus return), labelled (role=dialog / role=status) and contrast-safe.
(function () {
  var Z_DIALOG = 100000, Z_TOAST = 100001;
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function ensureCss() {
    if (document.getElementById('lu-dlg-css')) return;
    var st = document.createElement('style'); st.id = 'lu-dlg-css';
    st.textContent = [
      '.lu-dlg-overlay{position:fixed;inset:0;z-index:' + Z_DIALOG + ';background:rgba(0,0,0,.62);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:16px;font-family:var(--fb,"DM Sans",system-ui,sans-serif)}',
      '.lu-dlg{background:var(--s1,#171A21);border:1px solid var(--bd2,rgba(255,255,255,.13));border-radius:var(--rg,14px);width:100%;max-width:440px;max-height:calc(100vh - 32px);overflow:auto;box-shadow:0 24px 64px rgba(0,0,0,.6);color:var(--t1,#E8EDF5)}',
      '.lu-dlg-head{padding:20px 22px 6px;font-weight:700;font-size:16px;letter-spacing:-.01em;font-family:var(--fh,"Syne",sans-serif);text-wrap:balance}',
      '.lu-dlg-body{padding:6px 22px 18px;color:var(--t2,#8B97B0);font-size:13.5px;line-height:1.55;white-space:pre-line}',
      '.lu-dlg-input{width:100%;box-sizing:border-box;margin:0 0 18px;background:var(--s2,#1E2230);border:1px solid var(--bd2,rgba(255,255,255,.13));color:var(--t1,#E8EDF5);padding:11px 14px;border-radius:var(--r,10px);font-size:14px;font-family:inherit;min-height:44px}',
      '.lu-dlg-input:focus-visible{outline:2px solid var(--p,#6C5CE7);outline-offset:1px;border-color:var(--p,#6C5CE7)}',
      '.lu-dlg-foot{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;padding:12px 16px 16px;border-top:1px solid var(--bd,rgba(255,255,255,.07))}',
      '.lu-dlg-btn{min-height:44px;padding:0 18px;border-radius:var(--r,10px);font-size:13.5px;font-weight:600;font-family:inherit;cursor:pointer;border:1px solid transparent}',
      '.lu-dlg-btn:focus-visible{outline:2px solid var(--p,#6C5CE7);outline-offset:2px}',
      '.lu-dlg-btn.ghost{background:transparent;color:var(--t2,#8B97B0);border-color:var(--bd2,rgba(255,255,255,.13))}',
      '.lu-dlg-btn.primary{background:var(--p,#6C5CE7);color:#fff}',
      '.lu-dlg-btn.danger{background:var(--rd,#F87171);color:#1a0b0b}',
      '#lu-toast-stack{position:fixed;right:24px;bottom:24px;z-index:' + Z_TOAST + ';display:flex;flex-direction:column;gap:8px;max-width:min(420px,calc(100vw - 24px));pointer-events:none}',
      '@media (max-width:640px){#lu-toast-stack{right:12px;left:12px;bottom:12px;max-width:none}}',
      '.lu-toast{pointer-events:auto;display:flex;align-items:flex-start;gap:10px;background:var(--s2,#1E2230);color:var(--t1,#E8EDF5);border:1px solid var(--bd2,rgba(255,255,255,.13));border-left:4px solid var(--bl,#3B8BF5);border-radius:var(--r,10px);padding:11px 12px 11px 14px;font-size:13.5px;line-height:1.45;box-shadow:0 10px 30px rgba(0,0,0,.4);opacity:0;transform:translateY(6px);transition:opacity .2s,transform .2s;font-family:var(--fb,"DM Sans",system-ui,sans-serif)}',
      '.lu-toast.in{opacity:1;transform:none}',
      '.lu-toast.success{border-left-color:var(--ac,#00E5A8)}.lu-toast.error{border-left-color:var(--rd,#F87171)}.lu-toast.warning{border-left-color:var(--am,#F59E0B)}',
      '.lu-toast-msg{flex:1;min-width:0;word-break:break-word}',
      '.lu-toast-x{flex:none;width:32px;height:32px;margin:-6px -6px -6px 0;border:0;background:transparent;color:var(--t2,#8B97B0);font-size:16px;cursor:pointer;border-radius:8px}',
      '.lu-toast-x:hover{background:rgba(255,255,255,.06);color:var(--t1,#E8EDF5)}.lu-toast-x:focus-visible{outline:2px solid var(--p,#6C5CE7)}',
      '@media (prefers-reduced-motion:reduce){.lu-toast{transition:none}}'
    ].join('');
    document.head.appendChild(st);
  }

  // luDialog({type:'alert'|'confirm'|'prompt', title, message, okLabel, cancelLabel, danger|destructive,
  //           defaultValue, placeholder, inputType}) → Promise<true|false|string|null>
  window.luDialog = function (opts) {
    opts = opts || {};
    ensureCss();
    return new Promise(function (resolve) {
      var type = opts.type || 'alert', isPrompt = type === 'prompt', isConfirm = type === 'confirm';
      var prev = document.activeElement;
      var root = document.createElement('div'); root.className = 'lu-dlg-overlay';
      var id = 'lu-dlg-' + Date.now().toString(36);
      var shell = document.createElement('div'); shell.className = 'lu-dlg';
      shell.setAttribute('role', isPrompt || isConfirm ? 'dialog' : 'alertdialog'); shell.setAttribute('aria-modal', 'true');
      if (opts.title) shell.setAttribute('aria-labelledby', id + '-t');
      if (opts.message) shell.setAttribute('aria-describedby', id + '-b');
      shell.innerHTML =
        (opts.title ? '<div class="lu-dlg-head" id="' + id + '-t">' + esc(opts.title) + '</div>' : '') +
        (opts.message ? '<div class="lu-dlg-body" id="' + id + '-b">' + esc(opts.message) + '</div>' : '') +
        (isPrompt ? '<div style="padding:0 22px"><label for="' + id + '-i" style="position:absolute;left:-9999px">' + esc(opts.title || 'Value') + '</label><input class="lu-dlg-input" id="' + id + '-i" type="' + esc(opts.inputType || 'text') + '" placeholder="' + esc(opts.placeholder || '') + '" value="' + esc(opts.defaultValue || '') + '"></div>' : '') +
        '<div class="lu-dlg-foot">' +
          ((isConfirm || isPrompt) ? '<button type="button" class="lu-dlg-btn ghost" data-role="cancel">' + esc(opts.cancelLabel || 'Cancel') + '</button>' : '') +
          '<button type="button" class="lu-dlg-btn ' + ((opts.danger || opts.destructive) ? 'danger' : 'primary') + '" data-role="ok">' + esc(opts.okLabel || (isConfirm ? 'Confirm' : 'OK')) + '</button>' +
        '</div>';
      root.appendChild(shell); document.body.appendChild(root);
      var inp = shell.querySelector('input'), okBtn = shell.querySelector('[data-role=ok]'), cancelBtn = shell.querySelector('[data-role=cancel]');
      var closed = false;
      function done(val) {
        if (closed) return; closed = true;
        document.removeEventListener('keydown', onKey, true);
        try { root.remove(); } catch (e) {}
        try { if (prev && prev.focus && document.contains(prev)) prev.focus(); } catch (e) {}
        resolve(val);
      }
      function cancelValue() { return isConfirm ? false : (isPrompt ? null : true); }
      function focusables() { return Array.prototype.slice.call(shell.querySelectorAll('input,button')).filter(function (el) { return !el.disabled; }); }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(cancelValue()); return; }
        if (e.key === 'Enter' && (!inp || e.target === inp || e.target === okBtn)) { e.preventDefault(); okBtn.click(); return; }
        if (e.key === 'Tab') {
          var f = focusables(); if (!f.length) return;
          var i = f.indexOf(document.activeElement);
          if (e.shiftKey && (i <= 0)) { e.preventDefault(); f[f.length - 1].focus(); }
          else if (!e.shiftKey && (i === f.length - 1 || i === -1)) { e.preventDefault(); f[0].focus(); }
        }
      }
      document.addEventListener('keydown', onKey, true);
      okBtn.onclick = function () { done(isPrompt ? (inp ? inp.value : '') : true); };
      if (cancelBtn) cancelBtn.onclick = function () { done(cancelValue()); };
      root.addEventListener('mousedown', function (e) { if (e.target === root && (isConfirm || isPrompt)) done(cancelValue()); });
      setTimeout(function () { if (inp) { inp.focus(); inp.select(); } else if (isConfirm && cancelBtn && (opts.danger || opts.destructive)) { cancelBtn.focus(); } else { okBtn.focus(); } }, 20);
    });
  };

  // luConfirm accepts every argument order that shipped in the SPA so no caller can mislabel a dialog again:
  //   (title, message, opts)            — canonical
  //   (message, opts)                   — Studio order
  //   (message, title, okLabel, cancel) — original core order (2026-04 → 2026-08)
  window.luConfirm = function (a, b, c, d) {
    var o;
    if (b && typeof b === 'object') { o = Object.assign({ message: a }, b); }
    else if (typeof c === 'string' || typeof d === 'string') { o = { title: b, message: a, okLabel: c, cancelLabel: d }; }
    else { o = Object.assign({ title: a, message: b }, (c && typeof c === 'object') ? c : {}); }
    if (!o.title && o.message) { o.title = 'Please confirm'; }
    if (o.danger == null && o.destructive == null && /\b(delete|remove|permanently|revoke|disconnect|discard|expire)\b/i.test((o.okLabel || '') + ' ' + (o.title || ''))) o.danger = true;
    o.type = 'confirm';
    return window.luDialog(o);
  };
  // luPrompt(title, defaultValue, placeholder|opts) canonical; (message, defaultValue, {title,…}) Studio order.
  window.luPrompt = function (title, defaultValue, third) {
    var o = { type: 'prompt', title: title, defaultValue: defaultValue == null ? '' : String(defaultValue) };
    if (third && typeof third === 'object') { o = Object.assign(o, third); if (third.title) { o.message = title; o.title = third.title; } }
    else if (typeof third === 'string') { o.placeholder = third; }
    return window.luDialog(o);
  };
  // luAlert(title, message) canonical; (message, {title}) Studio order; a long first argument is treated as the message.
  window.luAlert = function (a, b) {
    var o = { type: 'alert' };
    if (b && typeof b === 'object') { o.message = a; Object.assign(o, b); }
    else if (typeof b === 'string' && (String(a).length > 60 || /\n/.test(String(a))) && b.length <= 60) { o.title = b; o.message = a; }
    else { o.title = a; o.message = b; }
    if (!o.title) o.title = 'Notice';
    return window.luDialog(o);
  };

  // showToast(msg, type='info'|'success'|'error'|'warning', opts{duration}) — stacked, announced, dismissible.
  window.showToast = function (msg, type, opts) {
    ensureCss(); opts = opts || {}; type = type || 'info';
    var stack = document.getElementById('lu-toast-stack');
    if (!stack) { stack = document.createElement('div'); stack.id = 'lu-toast-stack'; stack.setAttribute('role', 'status'); stack.setAttribute('aria-live', 'polite'); stack.setAttribute('aria-atomic', 'false'); document.body.appendChild(stack); }
    while (stack.children.length >= 4) stack.removeChild(stack.firstChild);
    var el = document.createElement('div'); el.className = 'lu-toast ' + type;
    el.innerHTML = '<div class="lu-toast-msg"></div><button type="button" class="lu-toast-x" aria-label="Dismiss notification">✕</button>';
    el.querySelector('.lu-toast-msg').textContent = String(msg == null ? '' : msg);
    stack.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('in'); });
    var ttl = opts.duration || (type === 'error' ? 7000 : 4000), t;
    function remove() { clearTimeout(t); el.classList.remove('in'); setTimeout(function () { try { el.remove(); } catch (e) {} }, 220); }
    el.querySelector('.lu-toast-x').onclick = remove;
    el.addEventListener('mouseenter', function () { clearTimeout(t); });
    el.addEventListener('mouseleave', function () { t = setTimeout(remove, 1500); });
    t = setTimeout(remove, ttl);
    return el;
  };
  window._luToast = function (msg, type) { return window.showToast(msg, type || 'info'); };
})();
function showToast(msg, type, opts) { return window.showToast(msg, type, opts); }
function luConfirm(a, b, c, d) { return window.luConfirm(a, b, c, d); }
function luPrompt(a, b, c) { return window.luPrompt(a, b, c); }
function luAlert(a, b) { return window.luAlert(a, b); }
function luDialog(o) { return window.luDialog(o); }

// ESCAPE FUNCTIONS (6 variants across files -- consolidation needed):
// core.js: _luEsc(s) -- full escape with &quot; | _escB(s) -- was missing &quot;, now fixed | _bldSafeText(t) -- no-op String()
// crm.js: _e(s) -- full escape with &quot; (CANNOT rename -- const in crm.js global scope)
// calendar.js: _escCal(s) -- full escape with &quot;
// marketing.js: _esc(s) -- full escape
function _bldSafeText(t) { return String(t || ''); }
function friendlyError(e) { return (e && e.message) ? e.message : String(e || "Unknown error"); }


// ── Missing function stubs (WP features not yet migrated) ──────────────
function loadCampaignsView() {
  var el = document.getElementById('view-campaigns');
  if (!el) return;
  var inner = el.querySelector('div') || el;
  inner.innerHTML = loadingCard(300);
  _luFetch('GET', '/marketing/campaigns').then(function(r) { return r.json(); }).then(function(data) {
    var campaigns = data.campaigns || [];
    if (campaigns.length === 0) {
      inner.innerHTML = '<div style="padding:60px;text-align:center;color:var(--t3)"><div style="margin-bottom:14px;color:var(--t2)">' + window.icon('rocket', 40) + '</div><h3 style="color:var(--t1);margin:0 0 8px">No campaigns yet</h3><p style="font-size:13px">Create your first campaign from the Marketing engine.</p></div>';
    } else {
      var h = '<div style="padding:24px"><h2 style="font-family:var(--fh);font-size:20px;margin:0 0 16px;color:var(--t1)">Campaigns</h2>';
      campaigns.forEach(function(c) { h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;margin-bottom:10px;font-size:13px"><strong style="color:var(--t1)">' + (c.name || c.title || 'Campaign') + '</strong><div style="color:var(--t2);margin-top:4px">' + (c.status || 'draft') + '</div></div>'; });
      h += '</div>';
      inner.innerHTML = h;
    }
  }).catch(function(e) { inner.innerHTML = '<div style="padding:40px;text-align:center;color:var(--rd)">Failed to load campaigns</div>'; });
}

async function saveApiKeys() {
  var msg = document.getElementById('st-ai-keys-msg');
  if (msg) msg.textContent = 'Saving...';
  var keys = {};
  var deepseek = document.getElementById('st-deepseek-key');
  var openai = document.getElementById('st-openai-key');
  var minimax = document.getElementById('st-minimax-key');
  var stability = document.getElementById('st-stability-key');
  if (deepseek && deepseek.value && !deepseek.value.startsWith('*')) keys['deepseek_key'] = deepseek.value;
  if (openai && openai.value && !openai.value.startsWith('*')) keys['openai_key'] = openai.value;
  if (minimax && minimax.value && !minimax.value.startsWith('*')) keys['minimax_key'] = minimax.value;
  if (stability && stability.value && !stability.value.startsWith('*')) keys['stability_key'] = stability.value;
  if (Object.keys(keys).length === 0) { if (msg) msg.textContent = 'No changes to save.'; return; }
  try {
    var r = await _luFetch('POST', '/admin/config', keys);
    var d = await r.json();
    if (d.success !== false) {
      showToast('AI keys saved!', 'success');
      if (msg) msg.textContent = 'Saved!';
      // Mask the fields after saving
      if (deepseek && deepseek.value) deepseek.value = '••••••••';
      if (openai && openai.value) openai.value = '••••••••';
      if (minimax && minimax.value) minimax.value = '••••••••';
      if (stability && stability.value) stability.value = '••••••••';
    } else {
      if (msg) msg.textContent = 'Save failed.';
      showToast('Failed to save keys.', 'error');
    }
  } catch(e) {
    if (msg) msg.textContent = 'Error: ' + e.message;
    showToast('Error saving keys.', 'error');
  }
}

// ── Team Management ──────────────────────────────────────────────
async function loadTeam() {
  var list = document.getElementById('team-members-list');
  var invites = document.getElementById('team-invites-list');
  var seats = document.getElementById('team-seats-info');
  if (!list) return;

  list.innerHTML = loadingCard(200);
  try {
    var r = await _luFetch('GET', '/team/members');
    var d = await r.json();
    var members = d.members || d || [];

    if (members.length === 0) {
      list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--t3)"><div style="font-size:32px;margin-bottom:8px">\U0001f465</div><p>No team members yet. Invite your first teammate!</p></div>';
    } else {
      var h = '<div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:14px">Members (' + members.length + ')</div>';
      h += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
      h += '<thead><tr><th style="text-align:left;padding:10px 12px;color:var(--t3);font-size:10px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--bd)">Name</th><th style="text-align:left;padding:10px 12px;color:var(--t3);font-size:10px;text-transform:uppercase;border-bottom:1px solid var(--bd)">Email</th><th style="padding:10px 12px;color:var(--t3);font-size:10px;text-transform:uppercase;border-bottom:1px solid var(--bd)">Role</th><th style="padding:10px 12px;border-bottom:1px solid var(--bd)"></th></tr></thead><tbody>';
      members.forEach(function(m) {
        var isOwner = m.role === 'owner';
        h += '<tr><td style="padding:10px 12px;color:var(--t1);border-bottom:1px solid rgba(255,255,255,.04)">' + (m.name || m.user_name || '\u2014') + '</td>';
        h += '<td style="padding:10px 12px;color:var(--t2);border-bottom:1px solid rgba(255,255,255,.04)">' + (m.email || m.user_email || '') + '</td>';
        h += '<td style="padding:10px 12px;text-align:center;border-bottom:1px solid rgba(255,255,255,.04)"><span style="font-size:11px;font-weight:600;color:' + (isOwner ? 'var(--p)' : 'var(--t2)') + ';text-transform:uppercase">' + m.role + '</span></td>';
        h += '<td style="padding:10px 12px;text-align:center;border-bottom:1px solid rgba(255,255,255,.04)">';
        if (!isOwner) h += '<button class="btn btn-outline btn-sm" style="font-size:10px;color:var(--rd)" onclick="removeMember(' + (m.user_id || m.id) + ')">Remove</button>';
        h += '</td></tr>';
      });
      h += '</tbody></table>';
      list.innerHTML = h;
    }

    // Load pending invites
    if (invites) {
      var ir = await _luFetch('GET', '/team/invites');
      var id = await ir.json();
      var inv = id.invites || id || [];
      if (inv.length > 0) {
        var ih = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:20px"><div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:14px">Pending Invites (' + inv.length + ')</div>';
        inv.forEach(function(i) {
          ih += '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:13px"><span style="color:var(--t2)">' + (i.email || '') + ' \u2014 ' + (i.role || 'member') + '</span><button class="btn btn-outline btn-sm" style="font-size:10px" onclick="cancelInvite(' + i.id + ')">Cancel</button></div>';
        });
        ih += '</div>';
        invites.innerHTML = ih;
      } else {
        invites.innerHTML = '';
      }
    }

    // Load seat info
    if (seats) {
      var sr = await _luFetch('GET', '/team/seats');
      var sd = await sr.json();
      seats.textContent = 'Seats: ' + (sd.used || members.length) + ' / ' + (sd.limit || 'unlimited');
    }
  } catch(e) {
    list.innerHTML = '<div style="padding:20px;color:var(--rd)">Failed to load team: ' + e.message + '</div>';
  }
}

function showInviteForm() {
  var form = document.getElementById('team-invite-form');
  if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

async function sendInvite() {
  var email = document.getElementById('invite-email');
  var role = document.getElementById('invite-role');
  var msg = document.getElementById('invite-msg');
  if (!email || !email.value) { showToast('Enter an email.', 'error'); return; }
  try {
    var r = await _luFetch('POST', '/team/invite', { email: email.value, role: role ? role.value : 'member' });
    var d = await r.json();
    if (r.ok) {
      showToast('Invite sent!', 'success');
      email.value = '';
      if (msg) msg.textContent = '';
      document.getElementById('team-invite-form').style.display = 'none';
      loadTeam();
    } else {
      if (msg) msg.textContent = d.message || d.error || 'Failed';
      showToast(d.message || 'Invite failed.', 'error');
    }
  } catch(e) { showToast('Error: ' + e.message, 'error'); }
}

async function removeMember(userId) {
  var ok = await luConfirm('Remove this member from your workspace?', 'Remove Member', 'Remove', 'Cancel');
  if (!ok) return;
  try {
    var r = await _luFetch('DELETE', '/team/members/' + userId);
    if (r.ok) { showToast('Member removed.', 'success'); loadTeam(); }
    else { var d = await r.json(); showToast(d.message || 'Failed.', 'error'); }
  } catch(e) { showToast('Error: ' + e.message, 'error'); }
}

async function cancelInvite(id) {
  try {
    var r = await _luFetch('DELETE', '/team/invites/' + id);
    if (r.ok) { showToast('Invite cancelled.', 'success'); loadTeam(); }
    else { showToast('Failed.', 'error'); }
  } catch(e) { showToast('Error.', 'error'); }
}

// ── PLATFORM888 Phase 6: governed background-service lifecycle (luBg) ──
// Non-critical pollers/badges REGISTER here and START only after the primary
// route is interactive (auth + workspace resolved), instead of firing during
// the blocking boot. Singleton per service; stopped on logout/session-loss.
// This is a start/stop registry, not a scheduler — no arbitrary setTimeouts.
window.luBg = window.luBg || (function(){
  var svcs = {}, interactive = false;
  var api = {
    register: function(name, def){ if(svcs[name]) return; svcs[name]={start:def.start, stop:def.stop||function(){}, started:false, auto:def.auto!==false}; if(interactive && svcs[name].auto) api.start(name); },
    start: function(name){ var x=svcs[name]; if(!x||x.started) return; x.started=true; try{ x.start(); }catch(e){ x.started=false; console.warn('[luBg] start '+name, e); } },
    stop: function(name){ var x=svcs[name]; if(!x||!x.started) return; x.started=false; try{ x.stop(); }catch(e){} },
    stopAll: function(){ Object.keys(svcs).forEach(function(n){ api.stop(n); }); },
    restartAll: function(){ Object.keys(svcs).forEach(function(n){ if(svcs[n].auto){ api.stop(n); api.start(n); } }); },
    markInteractive: function(){ if(interactive) return; interactive=true; Object.keys(svcs).forEach(function(n){ if(svcs[n].auto) api.start(n); }); },
    isInteractive: function(){ return interactive; },
    _svcs: svcs
  };
  return api;
})();

function luLogout() {
  if (window.luBg) window.luBg.stopAll();
  try {
    if (typeof _cmdcStopPolling === 'function') _cmdcStopPolling();
    if (typeof _acStopEventPoll === 'function') _acStopEventPoll();
    if (typeof _aqStopPolling === 'function') _aqStopPolling();
    if (typeof _previewAutoRefreshStop === 'function') _previewAutoRefreshStop();
    if (window._poller) { clearInterval(window._poller); window._poller = null; }
    if (window._luCreditPollTimer) { clearInterval(window._luCreditPollTimer); window._luCreditPollTimer = null; }
  } catch (_e) {}
  localStorage.removeItem('lu_token');
  localStorage.removeItem('lu_refresh_token');
  localStorage.removeItem('lu_user_id');
  localStorage.removeItem('lu_user_name');
  localStorage.removeItem('lu_workspace_id');
  localStorage.removeItem('lu_onboarded');
  // Strip any hash (e.g. #signup) so _appBootstrap routes to login, not signup.
  // Using location.replace so the logged-in URL isn't kept in history.
  window.location.replace(window.location.pathname + window.location.search);
}

function loadSettings() {
  var el = document.getElementById("view-settings");
  if (!el) return;
  // Populate profile fields from cached user data
  _populateProfileFields();
  // P0 (2026-08-30): Workspace + Billing credits from the pooled /workspace/status (was "—" / "0 / 0")
  _settingsFillCredits();
  // Wire the API Keys + WordPress Sites cards (renderers defined at file tail)
  var apkEl = document.getElementById('apk-section');
  if (apkEl && typeof window._renderApiKeys === 'function') {
    try { window._renderApiKeys(apkEl); } catch (_) {}
  }
  var wpsEl = document.getElementById('wps-section');
  if (wpsEl && typeof window._renderWpSites === 'function') {
    try { window._renderWpSites(wpsEl); } catch (_) {}
  }
  var bizEl = document.getElementById('biz-section');
  if (bizEl && typeof window._renderBusinesses === 'function') {
    try { window._renderBusinesses(bizEl); } catch (_) {}
  }
}

async function _settingsFillCredits() {
  var wsCr = document.getElementById('st-ws-credits'), wsPl = document.getElementById('st-ws-plan'), bill = document.getElementById('billing-credits');
  if (!wsCr && !bill && !wsPl) return;
  try {
    var r = await _luFetch('GET', '/workspace/status');
    if (!r.ok) return;
    var s = await r.json();
    var bal = Math.round(Number(s.credit_balance) || 0), lim = Number(s.monthly_credit_limit) || 0;
    var txt = lim > 0 ? bal + ' / ' + lim : String(bal);
    var planName = (s.plan && (s.plan.plan_name || s.plan.plan_slug)) ? String(s.plan.plan_name || s.plan.plan_slug) : '';
    var apply = function () {
      if (wsCr) wsCr.textContent = txt;
      if (bill) bill.textContent = txt;
      if (wsPl && planName) wsPl.textContent = planName.charAt(0).toUpperCase() + planName.slice(1) + (s.is_trial ? ' (trial)' : '');
    };
    apply();
    // index.html's inline /billing/status loader may land after us and write "0 / 0" — re-apply the pooled truth.
    setTimeout(apply, 1500); setTimeout(apply, 4000);
  } catch (e) {}
}

async function _populateProfileFields() {
  try {
    var r = await _luFetch("GET", "/auth/me");
    var d = await r.json();
    if (d && d.user) {
      var nameEl = document.getElementById("prof-name");
      var emailEl = document.getElementById("prof-email");
      if (nameEl) nameEl.value = d.user.name || "";
      if (emailEl) emailEl.value = d.user.email || "";
    }
  } catch(e) { console.warn("[LU] Failed to load profile:", e); }
}

async function saveProfile() {
  var msg = document.getElementById("prof-save-msg");
  var nameVal = (document.getElementById("prof-name") || {}).value;
  var emailVal = (document.getElementById("prof-email") || {}).value;
  if (!nameVal && !emailVal) { if (msg) msg.textContent = "Nothing to save."; return; }
  var body = {};
  if (nameVal) body.name = nameVal;
  if (emailVal) body.email = emailVal;
  if (msg) msg.textContent = "Saving...";
  try {
    var r = await _luFetch("PUT", "/auth/profile", body);
    var d = await r.json();
    if (r.ok) {
      showToast("Profile updated!", "success");
      if (msg) msg.textContent = "Saved!";
      // Update sidebar name if present
      var sn = document.getElementById("user-display-name");
      if (sn && body.name) sn.textContent = body.name;
    } else {
      if (msg) msg.textContent = d.message || "Save failed.";
      showToast(d.message || "Failed to save profile.", "error");
    }
  } catch(e) {
    if (msg) msg.textContent = "Error: " + e.message;
    showToast("Error saving profile.", "error");
  }
}

async function changePassword() {
  var msg = document.getElementById("prof-pw-msg");
  var curPw = (document.getElementById("prof-cur-pw") || {}).value;
  var newPw = (document.getElementById("prof-new-pw") || {}).value;
  var confPw = (document.getElementById("prof-conf-pw") || {}).value;
  if (!curPw || !newPw || !confPw) {
    if (msg) msg.textContent = "All password fields are required.";
    return;
  }
  if (newPw.length < 8) {
    if (msg) msg.textContent = "New password must be at least 8 characters.";
    return;
  }
  if (newPw !== confPw) {
    if (msg) msg.textContent = "New passwords do not match.";
    return;
  }
  if (msg) msg.textContent = "Changing...";
  try {
    var r = await _luFetch("PUT", "/auth/password", {
      current_password: curPw,
      password: newPw,
      password_confirmation: confPw
    });
    var d = await r.json();
    if (r.ok) {
      showToast("Password changed!", "success");
      if (msg) msg.textContent = "Password changed!";
      document.getElementById("prof-cur-pw").value = "";
      document.getElementById("prof-new-pw").value = "";
      document.getElementById("prof-conf-pw").value = "";
    } else {
      if (msg) msg.textContent = d.message || "Failed.";
      showToast(d.message || "Failed to change password.", "error");
    }
  } catch(e) {
    if (msg) msg.textContent = "Error: " + e.message;
    showToast("Error changing password.", "error");
  }
}

async function loadWorkerQueue() {
  var el = document.getElementById('view-queue');
  if (!el) return;
  el.innerHTML = '<div style="padding:24px"><h2 style="font-family:var(--fh);font-size:20px;margin:0 0 16px;color:var(--t1)">Worker Queue</h2><div style="text-align:center;padding:40px;color:var(--t3)"><div class="spinner"></div></div></div>';
  try {
    var qr = await _luFetch('GET', '/system/queue');
    var qd = await safeJson(qr);
    var tr = await _luFetch('GET', '/tasks?limit=50');
    var td = await safeJson(tr);
    var tasks = (td && td.tasks) ? td.tasks : (Array.isArray(td) ? td : []);
    var stats = qd || {};
    var pending = stats.pending || 0;
    var running = stats.running || 0;
    var completed = stats.completed || 0;
    var failed = stats.failed || 0;

    var statusIcons = {pending:'\ud83d\udfe0',queued:'\ud83d\udfe0',running:'\ud83d\udd35',in_progress:'\ud83d\udd35',completed:'\ud83d\udfe2',failed:'\u274c',cancelled:'\u26aa',blocked:'\ud83d\udfe1',degraded:'\ud83d\udfe1',awaiting_approval:'\ud83d\udfe1'};
    var h = '<div style="padding:24px"><h2 style="font-family:var(--fh);font-size:20px;margin:0 0 16px;color:var(--t1)">Worker Queue</h2>';
    h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--am)">' + pending + '</div><div style="font-size:11px;color:var(--t3);margin-top:4px">Pending</div></div>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--ac)">' + running + '</div><div style="font-size:11px;color:var(--t3);margin-top:4px">Running</div></div>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--gn)">' + completed + '</div><div style="font-size:11px;color:var(--t3);margin-top:4px">Completed</div></div>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:16px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--rd)">' + failed + '</div><div style="font-size:11px;color:var(--t3);margin-top:4px">Failed</div></div>';
    h += '</div>';

    h += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><div style="font-size:13px;font-weight:600;color:var(--t1)">Recent Tasks (' + tasks.length + ')</div><button onclick="loadWorkerQueue()" style="background:none;border:1px solid var(--bd);color:var(--t3);border-radius:7px;padding:5px 12px;font-size:11px;cursor:pointer">\u21bb Refresh</button></div>';

    if (tasks.length === 0) {
      h += '<div style="text-align:center;padding:40px;color:var(--t3);background:var(--s1);border:1px solid var(--bd);border-radius:10px"><div style="font-size:32px;margin-bottom:8px">'+window.icon('ai',18)+'</div><p>No tasks recorded yet.</p></div>';
    } else {
      // MOBILE-2: overflow:hidden CLIPPED the table on a phone; it must scroll instead, and the table needs a
      // min-width or the columns collapse into unreadable slivers.
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow-x:auto;-webkit-overflow-scrolling:touch"><table style="width:100%;min-width:640px;border-collapse:collapse;font-size:12px">';
      h += '<thead><tr style="border-bottom:1px solid var(--bd)">';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Status</th>';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Engine</th>';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Action</th>';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Credits</th>';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Created</th>';
      h += '<th style="text-align:left;padding:10px 14px;font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase">Why</th>';   /* REASON-2 */
      h += '<th style="padding:10px 14px"></th>';
      h += '</tr></thead><tbody>';
      tasks.forEach(function(t) {
        var icon = statusIcons[t.status] || '\u26aa';
        var dur = '';
        if (t.started_at && t.completed_at) {
          var ms = window._luParseTs(t.completed_at) - window._luParseTs(t.started_at);
          dur = ms < 1000 ? ms + 'ms' : (ms/1000).toFixed(1) + 's';
        }
        var retryBtn = t.status === 'failed' ? '<button onclick="wqRetryTask(' + t.id + ')" style="background:none;border:1px solid var(--bd);color:var(--am);border-radius:5px;padding:3px 8px;font-size:10px;cursor:pointer">\u21bb Retry</button>' : (dur ? '<span style="color:var(--t3);font-size:10px">' + dur + '</span>' : '');
        h += '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">';
        h += '<td style="padding:9px 14px;white-space:nowrap">' + icon + ' ' + (t.status||'—') + '</td>';
        h += '<td style="padding:9px 14px;color:var(--t2)">' + (t.engine||'—') + '</td>';
        h += '<td style="padding:9px 14px;color:var(--t1);font-family:monospace;font-size:11px">' + (t.action||'—') + '</td>';
        h += '<td style="padding:9px 14px;color:var(--t2)">' + (t.credit_cost||0) + '</td>';
        h += '<td style="padding:9px 14px;color:var(--t3)">' + (t.created_at ? window._luParseTs(t.created_at).toLocaleString([],{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : '—') + '</td>';
        // REASON-2: a failed row must say why. The cause is in error_text OR progress_message; a blank invites guessing.
        var whyCell = '<span style="color:var(--t3)">—</span>';
        if (t.status === 'failed') {
          var why = String(t.error_text || t.progress_message || '').trim();
          if (why === '') why = 'no reason recorded';
          whyCell = '<span title="' + _cmdcEsc(why) + '" style="color:var(--t2)">' + _cmdcEsc(why.length > 60 ? why.slice(0, 60) + '…' : why) + '</span>';
        }
        h += '<td style="padding:9px 14px;max-width:280px">' + whyCell + '</td>';
        h += '<td style="padding:9px 14px;text-align:right">' + retryBtn + '</td>';
        h += '</tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '</div>';
    el.innerHTML = h;
  } catch(e) {
    el.innerHTML = '<div style="padding:24px"><h2 style="font-family:var(--fh);font-size:20px;margin:0 0 16px;color:var(--t1)">Worker Queue</h2><div style="text-align:center;padding:40px;color:var(--rd)">Failed to load queue: ' + e.message + '</div></div>';
  }
}
window.wqRetryTask = async function(id) {
  try {
    var r = await _luFetch('POST', '/tasks/' + id + '/retry');
    if (r.ok) { showToast('Task queued for retry.', 'success'); loadWorkerQueue(); }
    else { var d = await safeJson(r); showToast((d && d.message) || 'Retry failed.', 'error'); }
  } catch(e) { showToast('Retry failed: ' + e.message, 'error'); }
};

function drawTaskLines() { /* no-op — canvas connector lines for kanban */ }


var _luEngineLoading = {};
// PATCH 10 Fix 1 — Define LU_API_BASE BEFORE any engine JS loads.
// Engine helpers (write.js _wrUrl, creative.js _crUrl, crm.js, etc.) build URLs
// as `(window.LU_API_BASE || '/api') + '/api/<engine>' + path`. The fallback
// '/api' produces double-/api like /api/api/write/articles → 404. Set to ''
// so the helpers produce the correct /api/write/articles path (the helpers
// always concatenate '/api/<engine>' themselves).
window.LU_API_BASE = '';
// Engine bust — file mtime from server so LiteSpeed cache can never serve stale engine JS
window.LU_ENGINE_BUST = (window.LU_CFG && window.LU_CFG.engineBust) ? window.LU_CFG.engineBust : (window.LU_CFG ? window.LU_CFG.version : '3.0.4');

// ═══════════════════════════════════════════════════════════════
// LU_humanize — schema-leakage scrubber (added v5.7.7)
// ═══════════════════════════════════════════════════════════════
// Mirrors the mobile companion's src/utils/humanize.ts. ANYWHERE a
// tool slug, task type, status enum, or other internal identifier
// could surface to a user, render `LU_humanize(slug)` instead of
// the raw value. Keeps mobile + web in lock-step on the no-schema-
// leakage rule.
;(function(){
  var SLUG_PHRASES = {
    write_article:'drafting an article', improve_draft:'polishing the draft',
    optimize_article:'optimising the article', generate_article:'drafting an article',
    create_campaign:'building a campaign', schedule_campaign:'scheduling the campaign',
    seo_content_generation:'writing SEO content', email_generation:'drafting an email',
    competitor_analysis:'reviewing competitor context', serp_analysis:'researching search rankings',
    keyword_research:'researching keywords', builder_generate:'designing the page',
    image_generation:'generating an image', scene_planning:'planning the scenes',
    blueprint_generate:'mapping the strategy', agent_meeting:'a team meeting',
    strategy_meeting:'a strategy session', before_after:'a before-and-after',
    chat_json:'a thoughtful reply', deep_audit:'a full SEO audit',
    generate_page_layout:'designing the page',
    waiting_approval:'waiting for sign-off', waiting_input:'waiting for input',
    queued:'queued up', running:'in progress', pending:'about to start',
    completed:'finished', failed:'hit a problem', cancelled:'cancelled',
    revision_requested:'sent back for revisions',
    blog_article:'blog article',
    landing_page:'landing page', seo_report:'SEO report'
  };
  var SORTED = Object.keys(SLUG_PHRASES).sort(function(a,b){return b.length-a.length;});
  var RX = new RegExp('\\b(' + SORTED.join('|') + ')\\b', 'g');
  // ── LU_statusLabel (2026-07-19) — proposal/approval status labels ───────
  // core.js:6769 rendered `_cmdcEsc(p.status || 'draft')`, i.e. the RAW enum,
  // as visible chip text: users saw "pending_approval", "superseded",
  // "executing". LU_humanize existed to prevent exactly this but was never
  // called on the approvals path, and its generic snake_case rule would only
  // yield "pending approval" anyway. This gives the statuses real copy and
  // falls back to LU_humanize for anything unmapped, so a NEW backend status
  // degrades to readable words instead of a raw enum.
  var STATUS_LABELS = {
    pending_approval: 'Awaiting your OK',
    pending:          'Awaiting your OK',
    approved:         'Approved',
    declined:         'Declined',
    rejected:         'Declined',
    executing:        'Running now',
    completed:        'Done',
    failed:           'Didn’t complete',
    superseded:       'No longer needed',
    acknowledged:     'Noted',
    expired:          'Expired',
    revised:          'Changes requested',
    draft:            'Draft',
    // Billing / subscription statuses (Stripe-style) — second chip site,
    // core.js ~7304. `past_due` / `incomplete_expired` would otherwise
    // render raw in the plan header.
    active:             'Active',
    trialing:           'Free trial',
    past_due:           'Payment overdue',
    unpaid:             'Unpaid',
    canceled:           'Cancelled',
    cancelled:          'Cancelled',
    incomplete:         'Setup incomplete',
    incomplete_expired: 'Setup expired',
    paused:             'Paused'
  };
  window.LU_statusLabel = function(status){
    if (status === undefined || status === null || status === '') return 'Draft';
    var k = String(status).toLowerCase().trim();
    return STATUS_LABELS[k] || window.LU_humanize(k).replace(/^./, function(c){ return c.toUpperCase(); });
  };

  window.LU_humanize = function(text){
    if (text === undefined || text === null) return '';
    var s = String(text).replace(RX, function(_, slug){ return SLUG_PHRASES[slug] || slug; });
    s = s.replace(/\b([a-z]{2,}(?:_[a-z]{2,})+)\b/g, function(_, slug){ return slug.replace(/_/g,' '); });
    return s;
  };
})();

// ── nav() queue stub ────────────────────────────────────────────────────────
// Defined immediately (top of file) so onclick="nav('...')" handlers that fire
// before the full script loads don't throw ReferenceError.
// The real nav() below replaces window.nav and drains this queue on load.
;(function() {
  var _navQueue = [];
  if (typeof window.nav !== 'function') {
    window.nav = function(view) {
      console.warn('[LevelUp] nav() called before core.js fully loaded — queuing:', view);
      _navQueue.push(view);
    };
    window._navQueue = _navQueue;
  }
})();

// ═══════════════════════════════════════════════════════════════════════════
// v5.7.19 (2026-05-31) — Phase 1.0 URL routing
// Wraps the SPA's existing nav() chokepoint with HTML5 History API so each
// section gets a real URL (/app/crm, /app/seo, /app/articles, …). Refresh,
// back/forward, and direct URL access all work. Existing hash and query-
// string deep links (#signup, #seo, /app/?tab=strategy, etc.) are NOT
// touched — they continue to resolve via their existing parsers for back-
// compat. The catch-all Laravel route at routes/web.php:33 already serves
// the SPA shell for any /app/{any?} path, so no backend work is needed.
//
// Behind LU_CFG.url_routing_enabled feature flag — flip to false for an
// instant revert.
// ═══════════════════════════════════════════════════════════════════════════
window._luRouter = (function () {
  // Canonical view registry — must match view-* IDs in index.html. Synced
  // 2026-05-31. If a new view is added to the SPA, add its key here.
  // Two redirect-only keys (governance, campaigns) are intentionally
  // omitted because canonical nav() redirects them to (approvals, marketing).
  // P1-U2 (2026-08-30): retired views dropped (automation, builder, blog, manualedit, marketing, mentions, tools —
  // they had no panel or were launch-scope removed); Basic surfaces added.
  var KNOWN_VIEWS = {
    sarah:1, attention:1, results:1, website:1, customers:1, account:1, aria:1,
    agents:1, approvals:1, billing:1,
    calendar:1, chatbot:1, command:1, crm:1,
    meeting:1, messages:1, projects:1, queue:1, reports:1, seo:1,
    settings:1, social:1, studio:1, websites:1, workspace:1,
    write:1, infrastructure:1,
  };

  // v5.7.23 (2026-05-31) — URL aliases. /app/{alias} resolves to the
  // mapped view. Used for friendly URLs that don't have a 1:1 view-*
  // panel (e.g. strategy proposals live INSIDE command center). Keeps
  // existing backend-generated notification URLs working with the new
  // path scheme.
  var URL_ALIASES = {
    strategy: 'command',  // proposals + Sarah's strategies surface here
    builder: 'websites', manualedit: 'studio', marketing: 'workspace', automation: 'workspace', mentions: 'workspace', tools: 'workspace', blog: 'write', creative: 'studio',
  };

  // v5.7.23 (2026-05-31) — per-view human titles for document.title.
  // Updated on every successful nav() call. Default is "LevelUpGrowth"
  // (the bare brand) for the workspace home; everything else gets a
  // suffix so browser tabs / bookmarks read meaningfully.
  var VIEW_TITLES = {
    sarah:      'Sarah',
    attention:  'Needs attention',
    results:    'Results',
    website:    'Website',
    customers:  'Customers',
    account:    'Account',
    aria:       'Aria',
    workspace:  'Workspace',
    infrastructure: 'Infrastructure',
    command:    'Command Center',
    crm:        'CRM',
    seo:        'SEO',
    write:      'Articles',
    blog:       'Blog',
    marketing:  'Marketing',
    social:     'Social',
    calendar:   'Calendar',
    creative:   'Creative',
    studio:     'Studio',
    builder:    'Builder',
    websites:   'Websites',
    agents:     'Agents',
    approvals:  'Approvals',
    automation: 'Automation',
    billing:    'Billing',
    chatbot:    'Chatbot',
    manualedit: 'Edit',
    mentions:   'Mentions',
    meeting:    'Strategy Room',
    messages:   'Messages',
    projects:   'Projects',
    queue:      'Queue',
    reports:    'Reports',
    settings:   'Settings',
    tools:      'Tools',
  };

  // workspace is the default landing — pushes to /app/ rather than
  // /app/workspace so the URL stays clean on first load.
  // P1-U2: the default landing depends on the mode — Basic lands on Sarah, Advanced on the workspace canvas.
  var DEFAULT_VIEW = (function(){ try { return localStorage.getItem('lu_visibility_mode') === 'advanced' ? 'workspace' : 'sarah'; } catch (e) { return 'sarah'; } })();
  var BASE = '/app/';

  function enabled() {
    return !!(window.LU_CFG && window.LU_CFG.url_routing_enabled);
  }
  function isKnown(view) {
    return !!(view && KNOWN_VIEWS[view]);
  }
  // v5.7.20 (2026-05-31) — Phase 2 path tails. pathToView returns
  // { view, tail } so /app/write/176 → { view:'write', tail:'176' }.
  // viewToPath + pushView accept an optional tail to build /app/write/176.
  // Tail charset is intentionally narrow (alphanumeric, dash, underscore,
  // dot) — IDs and slugs. Anything more exotic would suggest a Phase 3
  // sub-router. For now the router only resolves first-level tails;
  // /app/crm/leads/123 is treated as /app/crm/leads (tail='leads'),
  // intentionally — Phase 2 doesn't model two-segment sub-routes yet.
  function viewToPath(view, tail) {
    if (!isKnown(view)) return null;
    var base = (view === DEFAULT_VIEW && !tail) ? BASE : BASE + view;
    return tail ? base + '/' + encodeURIComponent(String(tail)) : base;
  }
  function pathToView(pathname) {
    // Accept /app/, /app/{view}, /app/{view}/, /app/{view}/{tail}, ...
    if (!pathname) return null;
    var p = pathname.replace(/\/+$/, '').toLowerCase();
    if (p === '/app' || p === '') return { view: DEFAULT_VIEW, tail: null };
    // Match /app/{view}[/{tail}] — case-insensitive on view, preserve raw tail.
    var m = p.match(/^\/app\/([a-z0-9_-]+)(?:\/([a-z0-9._-]+))?$/);
    if (!m) return null;
    // v5.7.23 — resolve alias before the registry check.
    var slug = URL_ALIASES[m[1]] || m[1];
    if (!isKnown(slug)) return null;
    return { view: slug, tail: m[2] || null };
  }

  // v5.7.23 (2026-05-31) — update document.title per route. Called from
  // nav() after the view dispatch. Keeps browser tabs + bookmarks readable.
  function setTitle(view) {
    if (typeof document === 'undefined') return;
    var brand = (window.LU_CFG && window.LU_CFG.bn) || 'LevelUpGrowth';
    var label = VIEW_TITLES[view];
    document.title = label ? (label + ' · ' + brand) : brand;
  }
  function pushView(view, tail) {
    if (!enabled()) return;
    var target = viewToPath(view, tail);
    if (!target) return;
    var curPath = window.location.pathname.replace(/\/+$/, '');
    var tgtPath = target.replace(/\/+$/, '');
    if (curPath === tgtPath) return;  // idempotent
    try {
      // Preserve query string + hash so embed mode + #signup etc. survive.
      var qs = window.location.search || '';
      var hash = window.location.hash || '';
      history.pushState({ view: view, tail: tail || null }, '', target + qs + hash);
    } catch (e) {
      console.warn('[LU Router] pushState failed:', e);
    }
  }
  function parseInitial() {
    return pathToView(window.location.pathname);
  }
  // popstate handler — back/forward triggers a nav with silent:true so we
  // don't re-push the URL we just landed on. Tail is passed through so
  // /app/write/176 → /app/write transition (or vice versa) re-opens or
  // closes the article.
  function onPopState() {
    if (!enabled()) return;
    var hit = pathToView(window.location.pathname);
    if (hit && typeof window.nav === 'function') {
      try { window.nav(hit.view, { silent: true, tail: hit.tail }); }
      catch (e) { console.warn('[LU Router] popstate nav failed:', e); }
    }
  }
  // Wire popstate once, regardless of when nav() loads.
  try { window.addEventListener('popstate', onPopState); }
  catch (_) { /* server-side or restricted context */ }

  return {
    enabled: enabled,
    isKnown: isKnown,
    pushView: pushView,
    parseInitial: parseInitial,
    viewToPath: viewToPath,
    pathToView: pathToView,
    onPopState: onPopState,
    setTitle: setTitle,
    KNOWN_VIEWS: KNOWN_VIEWS,
    URL_ALIASES: URL_ALIASES,
    DEFAULT_VIEW: DEFAULT_VIEW,
  };
})();

async function luLoadEngine(engine) {
  if (window.LU_LOADED_ENGINES[engine]) return;
  if (_luEngineLoading[engine]) return;
  _luEngineLoading[engine] = true;
  var urls = (window.LU_CFG && window.LU_CFG.engineUrls) || {};
  var base = (window.LU_CFG && window.LU_CFG.pluginUrl) ? window.LU_CFG.pluginUrl + '/assets/js/' : '';
  var lazy = ['crm','marketing','social','calendar','seo','write','creative','manualedit','blog','studio','studio-video','automation','projects','mentions','infrastructure'];
  if (lazy.indexOf(engine) === -1) { _luEngineLoading[engine] = false; return; }
  var src = urls[engine] || (base + engine + '.js');
  var isFallback = !urls[engine] && base;
  try {
    await new Promise(function(ok, fail) {
      var s = document.createElement('script');
      var bust = window.LU_ENGINE_BUST || (window.LU_CFG ? window.LU_CFG.version : '3.0.4');
      s.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'v=' + bust;
      s.onload = function() {
        if (isFallback) console.warn('[LevelUp] Engine JS fallback active for "' + engine + '" — engine plugin may not be loaded');
        ok();
      };
      s.onerror = function() { fail(new Error(engine + '.js failed')); };
      document.head.appendChild(s);
    });
  } catch(e) { console.error('[LU]', e); }
  _luEngineLoading[engine] = false;
}

// ═══════════════════════════════════════════════════════════════════
// ═══ Engine Execution Panel globals (from Phase 5) ═══
var ENG_AGENTS = {
  sarah:  { name:'Sarah',  role:'DMM',         color:'var(--p)' },
  james:  { name:'James',  role:'SEO',          color:'var(--bl)' },
  priya:  { name:'Priya',  role:'Content',      color:'var(--pu)' },
  elena:  { name:'Elena',  role:'CRM',          color:'var(--rd)' },
  alex:   { name:'Alex',   role:'Tech SEO',     color:'var(--ac)' },
};
var ENG_TOOL_AGENTS = {
  // prefill tool options per agent selection
  sarah:  ['autonomous_goal','list_goals','pause_goal'],
  james:  ['serp_analysis','ai_report','deep_audit'],
  priya:  ['write_article','improve_draft'],
  elena:  ['create_lead','update_lead','list_leads','move_lead','log_activity'],
  alex:   ['insert_link','dismiss_link','outbound_links','check_outbound','deep_audit'],
};
let _eng_selected_tools = new Set();
let _eng_current_output = null;
let _eng_current_plan   = null;
// Engine execution panel globals
// (duplicate ENG_AGENTS removed)

// ── Nav hook ────────────────────────────────────────────────────────

// (calendar footer removed — belongs in calendar.js only)



window.LU_CORE_VERSION = '3.0.0';
console.log('%c[LevelUp Core v3.2.2 LOADED]','background:#6C5CE7;color:#fff;padding:4px 12px;border-radius:4px;font-weight:bold');
var API=window.LU_CFG.api, NONCE=window.LU_CFG.nonce, BN=window.LU_CFG.bn, BU=window.LU_CFG.bu;
// ── authHeader — unified auth helper used by all fetch() calls ──────────────
// WP Admin context:   sends X-WP-Nonce (same as get()/post() helpers)
// Standalone SPA:     sends X-LevelUp-Token if window.LEVELUP_TOKEN is set
// Both can coexist — the REST auth filter handles either header.
function authHeader() {
    var h = { 'Accept': 'application/json' };
    // Embed mode (WP Connector iframe): always use API key auth — never JWT.
    // This single check fans out to every fetch() that uses authHeader().
    if (window._LGSC_EMBED && window._LGSC_EMBED.api_key) {
        h['X-API-KEY']      = window._LGSC_EMBED.api_key;
        h['X-Workspace-ID'] = String(window._LGSC_EMBED.workspace_id || '');
        return h;
    }
    var token = localStorage.getItem('lu_token');
    if (token) {
        h['Authorization'] = 'Bearer ' + token;
    }
    if (typeof NONCE !== 'undefined' && NONCE) {
        h['X-WP-Nonce'] = NONCE;
    }
    return h;
}

// Helper: embed-aware Authorization headers for inline fetch() calls that
// don't use authHeader() (post/get/put inline helpers and a few direct sites).
function _lgscAuthForFetch(extra) {
    extra = extra || {};
    if (window._LGSC_EMBED && window._LGSC_EMBED.api_key) {
        extra['X-API-KEY']      = window._LGSC_EMBED.api_key;
        extra['X-Workspace-ID'] = String(window._LGSC_EMBED.workspace_id || '');
    } else {
        extra['Authorization'] = 'Bearer ' + (localStorage.getItem('lu_token') || '');
    }
    return extra;
}
// ── safeJson — safe fetch wrapper preventing "Unexpected token <" ────────────
// Use instead of res.json() when response may be HTML (401 redirect etc).
// Returns parsed JSON on success, null on non-OK status (logs to console).
async function safeJson(response) {
    if (!response.ok) {
        var txt = await response.text().catch(() => '');
        console.warn('[LevelUp] API error ' + response.status + ' on ' + response.url +
            (txt.trim().startsWith('<') ? ' (HTML response — check auth)' : ': ' + txt.slice(0, 120)));
        return null;
    }
    try { return await response.json(); }
    catch(e) { console.warn('[LevelUp] JSON parse error on ' + response.url + ':', e.message); return null; }
}
// ── Single API constant — all modules reference this ───────────────────
// luApi is set late by builder IIFE; pre-declare here so governance never hits TDZ
var luApi = API;   // overwritten by builder IIFE if needed (same value)
// ── Engine API namespace — var-hoisted so all loaders can reference bare LuAPI ──
var LuAPI = {};
// ── Icon SVGs used by engine view templates ──────────────────────────────
var icons = {
  plus:   '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
  search: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
  trash:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>',
  edit:   '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
  chart:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
  zap:    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
  check:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>',
  target: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
};
var wpNonce_alias = NONCE; // alias used by some governance calls
var AGENTS={
  dmm: {name:'Sarah',role:'Digital Marketing Manager',emoji:'',color:'#8B93A7',expertise:['Strategy','Growth','Analytics','Content Planning']},
  james:{name:'James',role:'SEO Strategist',emoji:'',color:'#8B93A7',expertise:['Keyword Research','Search Intent','Topical Authority','SERP Features','Local SEO']},
  priya:{name:'Priya',role:'Content Manager',emoji:'',color:'#8B93A7',expertise:['Editorial Calendar','Brand Voice','Content Briefs','TOFU/MOFU/BOFU','Repurposing']},
  elena:{name:'Elena',role:'Lead & CRM Manager',emoji:'',color:'#8B93A7',expertise:['Lead Capture','Lead Nurture','CRM Segmentation','Lead Scoring','Attribution']},
  alex:{name:'Alex',role:'Technical SEO Engineer',emoji:'',color:'#8B93A7',expertise:['Core Web Vitals','Crawl Budget','Schema Markup','Site Architecture','Speed Optimisation']},
};
var PAIR_COLORS={'dmm-james':'var(--bl)','dmm-priya':'var(--pu)','dmm-elena':'var(--rd)','dmm-alex':'var(--ac)','james-priya':'#06B6D4','james-elena':'#EC4899','james-alex':'#10B981','priya-elena':'#EAB308','priya-alex':'#84CC16','elena-alex':'#818CF8'};
function pairColor(a,b){var k=[a,b].sort().join('-');return PAIR_COLORS[k]||'var(--t2)';}

let currentView='workspace',currentAgent=null,allTasks=[],pendingApprovalData=null,noteTargetConn=null;
let mid=null,pollT=null,seen=0,done=false,busy=false,lastSpk=null,lastPhase=null,selType='brainstorm';
let localUserMsgs=[]; // Persists user messages — runtime does NOT store them in Redis
var spoken=new Set(),phaseLog=new Set();
var intel={theme:'',seo:[],content:[],social:[],funnel:[]};
var PHASES=['briefing','idea_round','discussion_round','refinement_round','open','synthesis','complete'];
var THINK = {
    dmm:    ['Coordinating the team…','Synthesising insights…','Reviewing the strategy…','Connecting the dots…'],
    james:  ['Analysing keyword demand…','Checking search volume…','Mapping search intent…','Running competitor gap…','Pulling SERP features…','Checking keyword difficulty…','Reviewing topical authority…'],
    priya:  ['Drafting content brief…','Mapping the funnel stage…','Reviewing brand voice…','Structuring the content…','Checking repurpose formats…','Aligning with editorial calendar…'],
    elena:  ['Mapping the lead funnel…','Reviewing CRM triggers…','Building nurture sequence…','Checking lead scoring logic…','Reviewing conversion points…','Pulling attribution data…'],
    alex:   ['Running technical audit…','Checking Core Web Vitals…','Reviewing crawl budget…','Checking schema markup…','Auditing site architecture…','Reviewing canonical setup…'],
};

let agentActivityTimers = {};

function startAgentActivity(agentId) {
    stopAgentActivity(agentId);
    var el = document.getElementById('mthl-' + agentId);
    if (!el) return;
    var lines = THINK[agentId] || ['Working…'];
    let i = 0;
    el.textContent = lines[0];
    agentActivityTimers[agentId] = setInterval(() => {
        i = (i + 1) % lines.length;
        if (el) el.textContent = lines[i];
    }, 2000);
}

function stopAgentActivity(agentId) {
    if (agentActivityTimers[agentId]) {
        clearInterval(agentActivityTimers[agentId]);
        delete agentActivityTimers[agentId];
    }
}

// ── Routing ────────────────────────────────────────────────────────────────
async function nav(view, opts){
  // v5.7.19 — opts.silent skips history.pushState (used by popstate replays
  // and any internal call that doesn't represent a real navigation).
  opts = opts || {};
  // P1-U2 (2026-08-30): Basic surfaces are ALIASES of the authoritative views until P3 rebuilds their content —
  // attention→approvals, results→command, website→websites, customers→crm, account→settings. The URL keeps the
  // Basic name; the panel and its objects are the same ones Advanced shows.
  // P3: the Basic surfaces are real views now (basic.js). _requested is kept for nav highlighting/URL.
  var _requested = view;
  if (typeof window.sarahUnload === 'function' && view !== 'sarah') { try { window.sarahUnload(); } catch (_e) {} }
  document.querySelectorAll('.view').forEach(v=>{
    v.classList.remove('active');
    // SEO view uses visibility (not display:none) to keep iframe alive
    if(v.id==='view-seo'){ v.style.visibility='hidden'; v.style.pointerEvents='none'; v.style.position='absolute'; }
    else { v.style.display='none'; }
  });
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  var el=document.getElementById('view-'+view);
  if(!el){ console.error('[LevelUp] nav: view not found →', view); return; }
  // SEO view restored via visibility (not display) to keep iframe alive
  if(view==='seo'){
    el.style.visibility='visible';
    el.style.pointerEvents='auto';
    el.style.position='relative';
    el.style.display='flex';
  } else {
    el.style.display='flex';
  }
  el.classList.add('active');
  var ni=document.getElementById('ni-'+(_requested||view));if(ni)ni.classList.add('active');
  currentView=_requested||view;
  // v5.7.19 (2026-05-31) — Phase 1.0 URL routing. Push the URL after the
  // view has been resolved (so unknown views never pollute history), and
  // only when this nav() call represents a real navigation. Internal
  // switches and popstate replays pass {silent:true} to skip.
  if (!opts.silent && window._luRouter && window._luRouter.enabled()) {
    window._luRouter.pushView(_requested||view, opts.tail || null);
  }
  // v5.7.23 — update document.title for browser tab + bookmark labels.
  // Runs regardless of silent flag so popstate/initial-URL also update.
  if (window._luRouter && window._luRouter.enabled() && typeof window._luRouter.setTitle === 'function') {
    try { window._luRouter.setTitle(_requested||view); } catch (_e) {}
  }
  // 2026-05-15 — hide SEO AI Assistant FAB when navigating away from SEO.
  if (view !== 'seo' && typeof window._lgseHideFab === 'function') {
    window._lgseHideFab();
  }
  // Hide the Live Activity panel when in Strategy Room (it's a sibling of view-meeting, not a child of view-workspace)
  var _wsAct=document.getElementById('ws-activity-panel');
  if(_wsAct){ _wsAct.style.display = (view==='meeting') ? 'none' : ''; }
  if(view==='sarah')      { var _sr=document.getElementById('sarah-root'); if(_sr && typeof window.sarahLoad==='function') window.sarahLoad(_sr); }
  if(view==='attention')  { var _ar=document.getElementById('attention-root'); if(_ar && typeof window.basicAttentionLoad==='function') window.basicAttentionLoad(_ar); }
  if(view==='results')    { var _rr=document.getElementById('results-root'); if(_rr && typeof window.basicResultsLoad==='function') window.basicResultsLoad(_rr); }
  if(view==='website')    { var _wr=document.getElementById('website-root'); if(_wr && typeof window.basicWebsiteLoad==='function') window.basicWebsiteLoad(_wr); }
  if(view==='customers')  { var _cr=document.getElementById('customers-root'); if(_cr && typeof window.basicCustomersLoad==='function') window.basicCustomersLoad(_cr); }
  if(view==='account')    { var _acr=document.getElementById('account-root'); if(_acr && typeof window.basicAccountLoad==='function') window.basicAccountLoad(_acr); }
  if(view==='aria')       { var _arr=document.getElementById('aria-root'); if(_arr && typeof window.ariaLoad==='function') window.ariaLoad(_arr); }   /* ARIA888 DEC-0054 */
  if(view==='reports')    loadReports();
  if(view==='projects')   { await luLoadEngine('projects'); var _el=document.getElementById('projects-root'); if(_el && typeof projectsLoad==='function') projectsLoad(_el); }
  if(view==='infrastructure') { await luLoadEngine('infrastructure'); var _iel=document.getElementById('infrastructure-root'); if(_iel && typeof infraLoad==='function') infraLoad(_iel); }
  // P4-U1: mentions view retired.
  if(view==='tools')      { var _el=document.getElementById('tools-root'); if(_el) loadToolRegistry(_el); }
  if(view==='workspace')  {loadTasks();drawCanvas();drawZones(); if(typeof loadAgentStats==='function') loadAgentStats();}
  if(view==='agents')     { if (window.luRenderAgentsGrid) { try { luRenderAgentsGrid(); } catch (_e) {} } loadTasks(); loadAgentStats(); }
  if(view==='governance') loadGovernance();
  if(view==='previews')   { loadPreviews(); _previewAutoRefreshStart(); } else { _previewAutoRefreshStop(); }
  if(view==='settings') { loadSettings(); try{ if(window.luLoadWorkspaceProfile) window.luLoadWorkspaceProfile(); if(window.luGroupSettings) window.luGroupSettings(); }catch(e){} } /* P1R-6/7 */
  if(view==='builder') {
    // Builder engine loaded via builder-spa.js (injected by builder plugin)
    if (typeof _bldPrefetchDynamic === 'function') _bldPrefetchDynamic();
    var _bes = document.getElementById('bld-editor-state'); if(_bes) _bes.style.display = 'none';
    var _bls = document.getElementById('bld-list-state'); if(_bls) _bls.style.display = 'block';
    if (typeof arthurHide === 'function') arthurHide();
    if (typeof bldLoadPages === 'function') { try { bldLoadPages(); } catch(e) { console.error('[Builder]', e); } }
    else if (window._lu_engine_loaders && window._lu_engine_loaders['builder']) {
      var _el = document.getElementById('builder-root');
      if (_el) window._lu_engine_loaders['builder'](_el);
    }
  }
  if(view==='websites') {
    // Websites view — also handled by builder engine
    var _bes2 = document.getElementById('bld-editor-state'); if(_bes2) _bes2.style.display = 'none';
    var _bls2 = document.getElementById('bld-list-state'); if(_bls2) _bls2.style.display = 'block';
    var wsSitePages = document.getElementById('ws-site-pages');
    var wsSiteList = document.getElementById('ws-site-list');
    if (wsSitePages) wsSitePages.style.display = 'none';
    if (wsSiteList) wsSiteList.style.display = 'block';
    var _vb = document.getElementById('view-builder'); if(_vb) _vb.style.display = 'none';
    if (typeof wsLoadSites === 'function') await wsLoadSites();
    else if (window._lu_engine_loaders && window._lu_engine_loaders['websites']) {
      var _el = document.getElementById('websites-root');
      if (_el) window._lu_engine_loaders['websites'](_el);
    }
    // v5.7.21 (2026-05-31) — Phase 2 deep link. If the URL was
    // /app/websites/{siteId}, open that site's page list after sites load.
    if (opts.tail && typeof window.wsOpenSite === 'function') {
      var _sid = parseInt(opts.tail, 10);
      if (Number.isFinite(_sid) && _sid > 0) {
        try { await window.wsOpenSite(_sid); }
        catch (e) { console.warn('[Websites] deep-link open failed:', e); }
      }
    }
  }
  // ── Engine modules — dynamic dispatch via loader registry ──────────────
  if(view==='crm')        {
    await luLoadEngine('crm');
    var _el=document.getElementById('crm-root');
    if(_el && typeof crmLoad==='function') await crmLoad(_el);
    // v5.7.21 (2026-05-31) — Phase 2 deep link. If the URL was /app/crm/{leadId},
    // open that lead's detail drawer after the engine mounts.
    if (opts.tail && typeof window._crmOpenDetail === 'function') {
      var _lid = parseInt(opts.tail, 10);
      if (Number.isFinite(_lid) && _lid > 0) {
        try { await window._crmOpenDetail(_lid); }
        catch (e) { console.warn('[CRM] deep-link open failed:', e); }
      }
    }
  }
  // P4-U1: marketing view retired (DEC-0028).
  if(view==='social')     { await luLoadEngine('social'); var _el=document.getElementById('social-root'); if(_el && typeof socialLoad==='function') socialLoad(_el); }
  if(view==='calendar')   { await luLoadEngine('calendar'); var _el=document.getElementById('calendar-root'); if(_el && typeof calLoad==='function') calLoad(_el); }
  if(view==='write')      {
    await luLoadEngine('write');
    var _el=document.getElementById('write-root');
    if(_el && typeof writeLoad==='function') await writeLoad(_el);
    // v5.7.20 (2026-05-31) — Phase 2 deep link. If the URL was
    // /app/write/{articleId}, open that article after the engine mounts.
    if (opts.tail && typeof window._wrLoadAndOpenEditor === 'function') {
      var _aid = parseInt(opts.tail, 10);
      if (Number.isFinite(_aid) && _aid > 0) {
        try { await window._wrLoadAndOpenEditor(_aid); }
        catch (e) { console.warn('[Write] deep-link open failed:', e); }
      }
    }
  }
  // P4-U1: creative view retired (Studio is the single image/video surface).
  // RETIRED 2026-08-13 — /app/manualedit rendered a SECOND Studio (its own
  // Image Design / Video Editor tabs and its own design store). Its nav entry
  // went in 2026-04-20 but this router line kept it alive for every bookmark,
  // history entry and deep link. Studio is the single editing surface, so old
  // URLs now land there instead of executing retired code.
  if(view==='manualedit') { try { nav('studio'); } catch(_e) {} return; }
  // P4-U1: automation view retired.
  // P4-U1: blog view retired (Write covers articles).
  if(view==='studio')     {
    await luLoadEngine('studio');
    var _el=document.getElementById('studio-root');
    if(_el && typeof studioLoad==='function') await studioLoad(_el);
    // v5.7.22 (2026-05-31) — Phase 2 deep link. /app/studio/{designId} →
    // fetch design and mount editor. silentPush=true so we don't re-push
    // the URL we just read.
    if (opts.tail && typeof window.studioOpenDesign === 'function') {
      var _did = parseInt(opts.tail, 10);
      if (Number.isFinite(_did) && _did > 0) {
        try { await window.studioOpenDesign(_did, true); }
        catch (e) { console.warn('[Studio] deep-link open failed:', e); }
      }
    }
  }
  // 2026-05-12 — Pipeline moved into SEO engine as a tab. Sidebar dispatch removed.
  if(view==='chatbot')    { var _el=document.getElementById('chatbot-root'); if(_el && typeof chatbotLoad==='function') chatbotLoad(_el); }
  if(view==='messages')   { var _el=document.getElementById('messages-root'); if(_el && typeof messagesLoad==='function') messagesLoad(_el); }
  // Generic engine dispatch — external plugins register via window._lu_engine_loaders
  if (window._lu_engine_loaders && window._lu_engine_loaders[view]) {
    var _el = document.getElementById(view + '-root');
    if (_el) window._lu_engine_loaders[view](_el);
  }
  // Always hide Arthur when navigating away from builder
  if(view!=='builder') { try{arthurHidePanel();}catch(e){} }
  if(view==='seo') {
    var _el = document.getElementById('seo-root');
    if (_el) {
      _el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%';
      await luLoadEngine('seo'); if(typeof seoLoad==='function') seoLoad(_el); // lazy — window._seoIframe guards re-init
    }
  }
  // NAV-REDIRECTS-5.5.2 — route deprecated sections to canonical ones
  if (view === 'governance' || view === 'previews') { return nav('approvals'); }
  if (view === 'campaigns') { return nav('marketing'); }
  if(view==='command')    loadCommandCenter();
  if(view==='approvals')  loadApprovals();
  if(view==='billing')    loadBilling();
  if(view==='queue')      { if(typeof loadWorkerQueue==='function') loadWorkerQueue(); }

  // Inject policy badges into module action buttons after render (Builder plugin)
  if(typeof _injectModuleBadges==='function') setTimeout(_injectModuleBadges, 500);
  if(view==='team')       loadTeam();
  if(view==='campaigns')  loadCampaignsView();
  if(typeof updateAiContext==='function') setTimeout(updateAiContext, 50);
}

// ── Expose nav() to global scope explicitly ────────────────────────────────
// nav() is declared as a function declaration (auto-global in non-module scripts)
// but we pin it to window explicitly to guarantee availability for onclick="nav()"
// handlers regardless of any future script-wrapping or bundling.
window.nav = nav;
console.log('[LevelUp] nav available:', typeof window.nav);

// Drain any nav() calls that were queued before this line executed
if (window._navQueue && window._navQueue.length) {
  console.log('[LevelUp] Draining nav queue:', window._navQueue);
  window._navQueue.forEach(function(view) { nav(view); });
  window._navQueue = [];
}

// ── Tool Registry ──────────────────────────────────────────────────────────

var TOOL_ENGINE_COLORS = {
  seo:'var(--bl)', crm:'var(--rd)', marketing:'var(--pu)',
  social:'var(--am)', calendar:'var(--ac)', builder:'var(--p)', core:'#4a5568',
};

async function loadToolRegistry(el) {
  if (!el) return;
  el.innerHTML = loadingCard(200);
  try {
    var res   = await fetch(window.luApi + 'tools', { headers: authHeader() });
    var tools = await safeJson(res);  // returns the registry object keyed by name
    var list  = Object.values(tools || {});

    // Group by engine
    var byEngine = {};
    list.forEach(t => {
      var eng = t.engine || 'unknown';
      if (!byEngine[eng]) byEngine[eng] = [];
      byEngine[eng].push(t);
    });

    var engines = Object.keys(byEngine).sort();
    var totalDynamic = list.length;

    el.innerHTML = `
<div style="padding-bottom:32px">

  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px">
    <div>
      <h1 style="font-size:22px;font-weight:700;margin:0 0 4px;display:inline-flex;align-items:center;gap:8px">${window.icon('edit',20)} Tool Registry</h1>
      <p style="color:#8892a4;font-size:13px;margin:0">${totalDynamic} registered tool${totalDynamic!==1?'s':''} across ${engines.length} engine${engines.length!==1?'s':''}</p>
    </div>
    <button onclick="loadToolRegistry(document.getElementById('tools-root'))"
      style="background:none;border:1px solid #2a2f4a;color:#8892a4;border-radius:7px;padding:7px 14px;font-size:12px;cursor:pointer">↻ Refresh</button>
  </div>

  ${totalDynamic === 0 ? `
    <div style="background:#16192a;border:1px solid #2a2f4a;border-radius:12px;padding:60px;text-align:center;color:#4a5568">
      <div style="margin-bottom:12px;color:#6b7280">${window.icon('edit',36)}</div>
      <div style="font-size:14px;font-weight:600;margin-bottom:6px;color:#8892a4">No tools registered yet</div>
      <div style="font-size:12px">Engine plugins register tools via <code style="background:#0d1020;padding:2px 6px;border-radius:4px">add_action('lu_register_tools', ...)</code></div>
    </div>` : engines.map(eng => {
      var col   = TOOL_ENGINE_COLORS[eng] || 'var(--t2)';
      var eTools= byEngine[eng];
      return `
    <div style="margin-bottom:20px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <div style="width:10px;height:10px;border-radius:50%;background:${col};flex-shrink:0"></div>
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:${col}">${eng}</div>
        <div style="font-size:11px;color:#4a5568">${eTools.length} tool${eTools.length!==1?'s':''}</div>
      </div>
      <div style="background:#16192a;border:1px solid #2a2f4a;border-radius:10px;overflow:hidden">
        <table style="width:100%;border-collapse:collapse">
          <thead>
            <tr style="border-bottom:1px solid #1e2230">
              <th style="text-align:left;padding:10px 16px;font-size:11px;font-weight:600;color:#4a5568;text-transform:uppercase;letter-spacing:.5px">Tool</th>
              <th style="text-align:left;padding:10px 16px;font-size:11px;font-weight:600;color:#4a5568;text-transform:uppercase;letter-spacing:.5px">Description</th>
              <th style="text-align:left;padding:10px 16px;font-size:11px;font-weight:600;color:#4a5568;text-transform:uppercase;letter-spacing:.5px">Agents</th>
            </tr>
          </thead>
          <tbody>
            ${eTools.map((t, i) => {
              var agents = Array.isArray(t.agents) ? t.agents : [];
              var agentPills = agents.map(a => {
                var info = ENG_AGENTS[a] || { name: a, color: 'var(--t2)' };
                return `<span style="background:${info.color}22;color:${info.color};border-radius:20px;font-size:11px;font-weight:600;padding:2px 9px;white-space:nowrap">${info.name || a}</span>`;
              }).join(' ');
              var hasBorder = i < eTools.length - 1;
              return `<tr style="${hasBorder?'border-bottom:1px solid #1a1d2e':''}">
                <td style="padding:12px 16px;font-family:monospace;font-size:12px;color:#c8d0e0;white-space:nowrap">${t.name}</td>
                <td style="padding:12px 16px;font-size:12px;color:#8892a4;max-width:320px">${t.description || '—'}</td>
                <td style="padding:12px 16px">${agentPills || '<span style="color:#4a5568;font-size:11px">any</span>'}</td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>
    </div>`;
    }).join('')}

</div>`;

  } catch(e) {
    el.innerHTML = `<div style="padding:60px;text-align:center;color:#8892a4">
      <div style="margin-bottom:12px;color:var(--am,#F59E0B)">${window.icon('warning',32)}</div>
      <div>${e.message}</div>
    </div>`;
  }
}

// ── Governance ─────────────────────────────────────────────────────────────
var AGENT_COLORS = {dmm:'#8B93A7',james:'#8B93A7',priya:'#8B93A7',elena:'#8B93A7',alex:'#8B93A7'};   /* Owner 2026-09-15: no colour per agent */
var AGENT_NAMES  = {dmm:'Sarah',james:'James',priya:'Priya',elena:'Elena',alex:'Alex'};
let govHistory = [];

async function loadGovernance(){
  try {
    var d = await get(API+'governance/pending');
    var pending = d.pending || [];
    renderGovPending(pending);
    renderGovHistory();
    updateGovBadge(pending.length);
  } catch(e){ console.error('[GOV]',e); }
}

function renderGovPending(actions){
  var el = document.getElementById('gov-pending-list');
  if(!el) return;
  if(!actions.length){
    el.innerHTML='<div style="color:var(--muted);font-size:13px;padding:24px;text-align:center;background:var(--s2);border-radius:10px">No pending actions.</div>';
    return;
  }
  el.innerHTML = actions.map(a=>{
    var agentName  = AGENT_NAMES[a.agent_id] || a.agent_id;
    var agentColor = AGENT_COLORS[a.agent_id] || '#888';
    var ts = window._luParseTs(a.created_at).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
    return `<div class="gov-card" id="gov-${a.action_id}">
      <div class="gov-card-header">
        <div class="gov-agent-badge" style="background:${agentColor}20;color:${agentColor};border:1px solid ${agentColor}40">${agentName}</div>
        <div class="gov-tool-name">${a.tool_name}</div>
        <div class="gov-ts">${ts}</div>
      </div>
      <div class="gov-preview">${escHtml(a.preview)}</div>
      <div class="gov-actions">
        <button class="gov-btn gov-approve" onclick="govApprove('${a.action_id}',this)">✓ Approve &amp; Execute</button>
        <button class="gov-btn gov-reject"  onclick="govReject('${a.action_id}',this)">✕ Reject</button>
      </div>
    </div>`;
  }).join('');
}

function renderGovHistory(){
  var el = document.getElementById('gov-history-list');
  if(!el) return;
  if(!govHistory.length){
    el.innerHTML='<div style="color:var(--muted);font-size:13px;padding:24px;text-align:center;background:var(--s2);border-radius:10px">No action history yet.</div>';
    return;
  }
  el.innerHTML = govHistory.slice(0,20).map(h=>{
    var agentColor = AGENT_COLORS[h.agent_id] || '#888';
    var icon = h.status==='executed'?'✓':h.status==='rejected'?'✕':'✗';
    var col  = h.status==='executed'?'var(--ac)':h.status==='rejected'?'var(--muted)':'var(--rd)';
    return `<div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--s2);border-radius:8px;margin-bottom:6px;font-size:13px">
      <span style="color:${col};font-weight:700;font-size:15px">${icon}</span>
      <span style="color:${agentColor};font-weight:600">${AGENT_NAMES[h.agent_id]||h.agent_id}</span>
      <span style="color:var(--muted)">→</span>
      <span style="color:#fff">${escHtml(h.tool_name)}</span>
      <span style="color:var(--muted);flex:1;font-size:11px">${escHtml(h.preview||'')}</span>
      <span style="color:var(--muted);font-size:11px;text-transform:capitalize">${h.status}</span>
    </div>`;
  }).join('');
}

async function govApprove(actionId, btn){
  btn.disabled=true; btn.textContent='Approving…';
  try {
    var d = await post(API+'governance/approve',{action_id:actionId});
    if(d.success){
      var card = document.getElementById('gov-'+actionId);
      if(card){ card.style.opacity='0.4'; card.style.pointerEvents='none'; }
      govHistory.unshift({action_id:actionId,status:'executed',tool_name:'',preview:'Action executed.',agent_id:'',created_at:new Date().toISOString()});
      setTimeout(loadGovernance, 400);
    } else {
      btn.textContent='Error — retry'; btn.disabled=false;
      console.error('[GOV approve]', d);
    }
  } catch(e){ btn.textContent='Error'; btn.disabled=false; }
}

async function govReject(actionId, btn){
  btn.disabled=true; btn.textContent='Rejecting…';
  try {
    var d = await post(API+'governance/reject',{action_id:actionId});
    var card = document.getElementById('gov-'+actionId);
    if(card){ card.style.opacity='0.4'; card.style.pointerEvents='none'; }
    govHistory.unshift({action_id:actionId,status:'rejected',tool_name:'',preview:'Action rejected.',agent_id:'',created_at:new Date().toISOString()});
    setTimeout(loadGovernance, 400);
  } catch(e){ btn.textContent='Error'; btn.disabled=false; }
}

function updateGovBadge(count){
  var badge = document.getElementById('gov-badge');
  if(!badge) return;
  if(count>0){ badge.textContent=count; badge.style.display='block'; }
  else { badge.style.display='none'; }
}

function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Poll governance every 30 seconds for badge updates (with fail-safe)
;(function(){
  if(window.__lu_poll_gov) return; window.__lu_poll_gov=true;
  let _govFails=0;
  var _govPoll=setInterval(async()=>{
    if(_govFails>=3){console.warn('[LU] Gov poll disabled after 3 failures');clearInterval(_govPoll);return;}
    try{var d=await get(API+'governance/pending');_govFails=0;updateGovBadge((d.pending||[]).length);if(currentView==='governance') renderGovPending(d.pending||[]);}catch(e){_govFails++;}
  }, 30000);
})();

// ══════════════════════════════════════════════════════════════════════════
// PHASE 6.5 — PREVIEW PANEL JS
// ══════════════════════════════════════════════════════════════════════════
let _previewTab = 'pending';

async function loadPreviews() {
  var wrap = document.getElementById('preview-list');
  if (!wrap) return;
  wrap.innerHTML = '<div style="padding:24px;text-align:center;color:var(--t3)"><div class="spinner"></div></div>';
  try {
    var data = await get(API + 'previews?status=' + _previewTab);
    var items = Array.isArray(data) ? data : [];
    updatePreviewBadge(items.length);
    if (!items.length) {
      wrap.innerHTML = '<div style="color:var(--t3);font-size:13px;padding:24px;text-align:center;background:var(--s2);border-radius:10px">No ' + _previewTab + ' previews.</div>';
      return;
    }
    wrap.innerHTML = items.map(p => renderPreviewCard(p)).join('');
  } catch(e) {
    wrap.innerHTML = '<div style="color:var(--rd);font-size:13px;padding:24px;text-align:center">Failed to load previews: ' + (e.message||e) + '</div>';
  }
}
let _pvRefreshTimer = null;
function _previewAutoRefreshStart() { _previewAutoRefreshStop(); _pvRefreshTimer = setInterval(() => { if(currentView === 'previews') loadPreviews(); }, 15000); }
function _previewAutoRefreshStop() { if(_pvRefreshTimer) { clearInterval(_pvRefreshTimer); _pvRefreshTimer = null; } }

async function renderPreviewCard(p) {
  var preview = p.preview_output || {};
  var fields = preview.fields || [];
  var warnings = preview.warnings || [];
  var isPending = p.status === 'pending';
  var agent = AGENTS[p.agent_id] || {emoji:'🤖', name:p.agent_id, color:'var(--t2)'};
  var riskColors = {high:'var(--rd)', medium:'var(--am)', low:'var(--ac)'};
  var statusColors = {pending:'var(--am)', approved:'var(--ac)', rejected:'var(--rd)', executed:'var(--ac)', failed:'var(--rd)'};

  let html = `<div id="pv-${p.id}" style="background:var(--s2);border:1px solid var(--bd);border-radius:12px;padding:20px;margin-bottom:16px;transition:opacity .3s">`;

  // Header
  html += `<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
    <span style="font-size:22px">${agent.emoji}</span>
    <div style="flex:1">
      <div style="font-size:14px;font-weight:600;color:var(--t1)">${esc(preview.summary || LU_humanize(p.tool_id))}</div>
      <div style="font-size:11px;color:var(--t3)">${agent.name} · ${preview.domain || ''} · ${window._luParseTs(p.created_at).toLocaleString()}</div>
    </div>
    <span style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px;background:${(riskColors[preview.risk]||'var(--t3)')}22;color:${riskColors[preview.risk]||'var(--t3)'}">${(preview.risk||'').toUpperCase()}</span>
    <span style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px;background:${(statusColors[p.status]||'var(--t3)')}22;color:${statusColors[p.status]||'var(--t3)'}">${p.status.toUpperCase()}</span>
  </div>`;

  // Warnings
  if (warnings.length) {
    html += warnings.map(w => `<div style="background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:12px;color:var(--rd);display:flex;align-items:center;gap:6px">${window.icon('warning',14)} ${esc(w)}</div>`).join('');
  }

  // Editable fields
  if (fields.length) {
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">';
    fields.forEach(f => {
      var disabled = !isPending || !f.editable ? 'disabled' : '';
      var id = `pv-${p.id}-${f.key}`;
      if (f.multiline) {
        html += `<div style="grid-column:1/-1" class="form-group"><label class="form-label">${esc(f.label)}</label>
          <textarea class="form-input pv-field" data-key="${f.key}" id="${id}" ${disabled} style="min-height:80px;resize:vertical">${esc(f.value||'')}</textarea></div>`;
      } else if (f.options) {
        html += `<div class="form-group"><label class="form-label">${esc(f.label)}</label>
          <select class="form-select pv-field" data-key="${f.key}" id="${id}" ${disabled}>
            ${f.options.map(o => `<option value="${o}"${(f.value||'')=== o ? ' selected':''}>${o}</option>`).join('')}
          </select></div>`;
      } else {
        html += `<div class="form-group"><label class="form-label">${esc(f.label)}</label>
          <input class="form-input pv-field" data-key="${f.key}" id="${id}" value="${esc(f.value||'')}" ${disabled}></div>`;
      }
    });
    html += '</div>';
  }

  // Execution result (for executed/failed)
  if (p.execution_result) {
    var success = p.execution_result.success !== false;
    html += `<div style="background:${success?'rgba(0,229,168,.06)':'rgba(248,113,113,.06)'};border:1px solid ${success?'rgba(0,229,168,.15)':'rgba(248,113,113,.15)'};border-radius:8px;padding:10px 14px;font-size:12px;color:var(--t2);margin-bottom:10px">
      <strong style="color:${success?'var(--ac)':'var(--rd)'}">${success?'✓ Executed':'✗ Failed'}</strong>
      ${p.executed_at ? ' · ' + new Date(p.executed_at).toLocaleString() : ''}
    </div>`;
  }

  // Action buttons (pending only)
  if (isPending) {
    html += `<div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="previewReject(${p.id},this)">✕ Reject</button>
      <button class="btn btn-primary btn-sm" onclick="previewApprove(${p.id},this)">✓ Approve & Execute</button>
    </div>`;
  }

  html += '</div>';
  return html;
}

window.previewSetTab = function(tab) {
  _previewTab = tab;
  document.querySelectorAll('[data-ptab]').forEach(b => b.classList.toggle('active', b.dataset.ptab === tab));
  loadPreviews();
};

window.previewApprove = async function(id, btn) {
  btn.disabled = true; btn.textContent = 'Executing…';
  // Collect edited fields
  var card = document.getElementById('pv-' + id);
  var payload = {};
  if (card) {
    card.querySelectorAll('.pv-field').forEach(f => {
      var key = f.dataset.key;
      if (key) payload[key] = f.value;
    });
  }
  try {
    var r = await post(API + 'previews/' + id + '/approve', Object.keys(payload).length ? {payload} : {});
    if (r.success) {
      showToast('✓ Action executed!', 'success');
      if (card) { card.style.opacity = '0.3'; card.style.pointerEvents = 'none'; }
      setTimeout(loadPreviews, 500);
    } else {
      showToast('Execution failed: ' + (r.error || 'unknown'), 'error');
      btn.disabled = false; btn.textContent = '✓ Approve & Execute';
    }
  } catch(e) { showToast('Error: ' + e.message, 'error'); btn.disabled = false; btn.textContent = '✓ Approve & Execute'; }
};

window.previewReject = async function(id, btn) {
  var ok = await luConfirm('This action will be rejected and the agent notified.', 'Reject Action', 'Reject', 'Keep'); if (!ok) return;
  btn.disabled = true; btn.textContent = 'Rejecting…';
  try {
    await post(API + 'previews/' + id + '/reject', {});
    showToast('Action rejected.', 'info');
    var card = document.getElementById('pv-' + id);
    if (card) { card.style.opacity = '0.3'; card.style.pointerEvents = 'none'; }
    setTimeout(loadPreviews, 400);
  } catch(e) { showToast('Error: ' + e.message, 'error'); btn.disabled = false; btn.textContent = '✕ Reject'; }
};

function updatePreviewBadge(count) {
  var badge = document.getElementById('preview-badge');
  if (!badge) return;
  if (count > 0) { badge.textContent = count; badge.style.display = 'block'; }
  else { badge.style.display = 'none'; }
}

// Poll previews every 30 seconds (with fail-safe)
;(function(){
  if(window.__lu_poll_pv) return; window.__lu_poll_pv=true;
  let _pvFails=0;
  setInterval(async()=>{
    if(_pvFails>=3){return;} // silent stop
    try{
      var d=await get(API+'previews?status=pending');_pvFails=0;
      var items=Array.isArray(d)?d:[];
      updatePreviewBadge(items.length);
      if(currentView==='previews'&&_previewTab==='pending'){var wrap=document.getElementById('preview-list');if(wrap&&items.length) wrap.innerHTML=items.map(p=>renderPreviewCard(p)).join('');}
    }catch(e){_pvFails++;}
  }, 30000);
})();

// ── Canvas ─────────────────────────────────────────────────────────────────
var defaultPos={dmm:{x:450,y:280},james:{x:180,y:140},priya:{x:720,y:130},elena:{x:640,y:460},alex:{x:240,y:440}};
var nodePos={...Object.fromEntries(Object.entries(defaultPos).map(([k,v])=>([k,{...v}])))};
// Task node positions — overrides centroid when user has dragged them
var taskNodePos = {};

// ── Zone layout ────────────────────────────────────────────────────────────
var ZONE_DEFS = {
  leadership: { agents:['dmm'],                 color:'rgba(108,92,231,.03)' },
  strategy:   { agents:['james','priya','sofia'], color:'rgba(59,139,245,.025)' },
  execution:  { agents:['alex','elena','diana','ryan','nora','max'], color:'rgba(0,229,168,.02)' },
};

function drawZones() {
  var canvas = document.getElementById('canvas-agents');
  if (!canvas) return;

  Object.entries(ZONE_DEFS).forEach(([zoneId, def]) => {
    let minX=Infinity,minY=Infinity,maxX=-Infinity,maxY=-Infinity;
    def.agents.forEach(id => {
      if (!nodePos[id]) return;
      // Approx card size 160×120
      minX=Math.min(minX, nodePos[id].x-20);
      minY=Math.min(minY, nodePos[id].y-24);
      maxX=Math.max(maxX, nodePos[id].x+180);
      maxY=Math.max(maxY, nodePos[id].y+134);
    });
    if (minX===Infinity) return;
    var ov = document.getElementById('zone-'+zoneId);
    var lb = document.getElementById('zone-lbl-'+zoneId);
    if (ov) { ov.style.left=minX+'px'; ov.style.top=minY+'px'; ov.style.width=(maxX-minX)+'px'; ov.style.height=(maxY-minY)+'px'; }
    if (lb) { lb.style.left=(minX+8)+'px'; lb.style.top=(minY-16)+'px'; }
  });
}

// ── Selection state ────────────────────────────────────────────────────────
var selectedAgents = new Set();

function toggleAgentSelect(id, node) {
  if (selectedAgents.has(id)) { selectedAgents.delete(id); node.classList.remove('selected'); }
  else { selectedAgents.add(id); node.classList.add('selected'); }
  updateSelectionToolbar();
}
function clearSelection() {
  selectedAgents.clear();
  document.querySelectorAll('.agent-node.selected').forEach(n => n.classList.remove('selected'));
  updateSelectionToolbar();
}
function updateSelectionToolbar() {
  var tb = document.getElementById('selection-toolbar');
  var ct = document.getElementById('sel-count');
  if (!tb) return;
  if (selectedAgents.size > 0) {
    tb.classList.add('visible');
    if (ct) ct.textContent = selectedAgents.size;
  } else {
    tb.classList.remove('visible');
  }
}

// ── Drag + selection init ──────────────────────────────────────────────────
// [builder] extracted to builder.js (lines 630-755)
function resetLayout(){
  // Radial layout from canvas center — Sarah at center, others in rings
  var canvas=document.getElementById('canvas-agents');
  var cx=1500;
  var cy=1000;
  var cw=160, ch=140;
  var nodes=document.querySelectorAll('.agent-node');
  var sarahNode=null, inner=[], outer=[];
  nodes.forEach(function(n){
    var id=n.dataset.agent;
    if(id==='dmm'||id==='sarah') sarahNode=n;
    else if(['james','priya','sofia'].indexOf(id)>=0) inner.push(n);
    else outer.push(n);
  });
  // Sarah at center
  if(sarahNode){
    var sx=cx-cw/2, sy=cy-ch/2;
    nodePos[sarahNode.dataset.agent]={x:sx,y:sy};
    sarahNode.style.left=sx+'px'; sarahNode.style.top=sy+'px';
  }
  // Inner ring (strategy)
  inner.forEach(function(n,i){
    var angle=(i/inner.length)*2*Math.PI-Math.PI/2;
    var x=Math.round(cx+280*Math.cos(angle)-cw/2);
    var y=Math.round(cy+280*Math.sin(angle)-ch/2);
    nodePos[n.dataset.agent]={x:x,y:y};
    n.style.left=x+'px'; n.style.top=y+'px';
  });
  // Outer ring (execution)
  outer.forEach(function(n,i){
    var angle=(i/outer.length)*2*Math.PI-Math.PI/2;
    var x=Math.round(cx+520*Math.cos(angle)-cw/2);
    var y=Math.round(cy+520*Math.sin(angle)-ch/2);
    nodePos[n.dataset.agent]={x:x,y:y};
    n.style.left=x+'px'; n.style.top=y+'px';
  });
  clearSelection();
  drawCanvas(); drawZones();
}

// Fast SVG line redraw for a single dragged task node
function _redrawTaskLines(taskId, tx, ty, agents) {
  var svg = document.getElementById('canvas-svg');
  if (!svg) return;
  // Remove only the lines belonging to this task node
  svg.querySelectorAll('[data-tnode="' + taskId + '"]').forEach(el => el.remove());
  var tcx = tx + 70; // task node center x
  var tcy = ty + 44; // task node center y
  agents.forEach(id => {
    if (!nodePos[id]) return;
    var ax = nodePos[id].x + 80;
    var ay = nodePos[id].y + 60;
    var line = document.createElementNS('http://www.w3.org/2000/svg','line');
    line.setAttribute('x1', tcx); line.setAttribute('y1', tcy);
    line.setAttribute('x2', ax);  line.setAttribute('y2', ay);
    var ag = AGENTS[id] || {};
    line.setAttribute('stroke', ag.color || 'var(--t2)');
    line.setAttribute('stroke-width', '1.5');
    line.setAttribute('stroke-dasharray', '4,3');
    line.setAttribute('opacity', '0.55');
    line.setAttribute('data-tnode', taskId);
    line.style.pointerEvents = 'none';
    svg.appendChild(line);
  });
}

function drawCanvas(){
  var svg=document.getElementById('canvas-svg');
  if(!svg){console.warn('[drawCanvas] no SVG element');return;}
  svg.innerHTML='';

  // 2026-05-26 — FORCED position sync. Bug 1 in the forensic: index.html
  // writes dynamic positions to window.nodePos_dyn, but drawCanvas reads
  // from nodePos (file-local). The intended sync at index.html:2684 races
  // with drawCanvas calls. Force-sync EVERY draw so lines always land at
  // the correct node positions.
  if (window.nodePos_dyn) {
    Object.assign(nodePos, window.nodePos_dyn);
  }

  // Remove existing task nodes from canvas
  document.querySelectorAll('.task-node').forEach(n=>n.remove());

  // Build multi-assignee collision map from tasks
  var connMap={};

  var filtered=allTasks.filter(t=>['ongoing','upcoming','completed','in_progress'].includes(t.status));

  filtered.forEach(task=>{
    var involved = new Set();
    if (task.assignees && task.assignees.length) task.assignees.forEach(a=>involved.add(a));
    else if (task.assignee) involved.add(task.assignee);
    if (task.coordinator) involved.add(task.coordinator);

    var agents = [...involved];

    if (agents.length >= 2) {
      renderTaskNode(task, agents);
    }

    // Build all pairs for collaboration lines
    for (let i=0; i<agents.length; i++) {
      for (let j=i+1; j<agents.length; j++) {
        var pair=[agents[i],agents[j]].sort().join('-');
        if(!connMap[pair]) connMap[pair]=[];
        if(!connMap[pair].find(t=>t.id===task.id)) connMap[pair].push(task);
      }
    }
  });

  // Wave 85 — chain-aware coordination. Single-assignee chain tasks
  // (Sarahs chain refactor Wave 41) leave the canvas blank because
  // agents.length < 2 per task. Group by root parent_task_id and add
  // Sarah(dmm) ↔ agent pairs so coordination is visible.
  var chainGroups = {};
  filtered.forEach(t => {
    var rootId = t.parent_task_id || t.id;
    if (!chainGroups[rootId]) chainGroups[rootId] = { tasks: [], agents: new Set() };
    chainGroups[rootId].tasks.push(t);
    (t.assignees || []).forEach(a => chainGroups[rootId].agents.add(a));
    if (t.coordinator) chainGroups[rootId].agents.add(t.coordinator);
  });
  Object.values(chainGroups).forEach(chain => {
    // A chain is a coordination event if Sarah delegated OR >=2 distinct agents are involved.
    var sarahDelegated = chain.tasks.some(t => t.delegated_by === 'dmm');
    if (!sarahDelegated && chain.agents.size < 2) return;
    var agentList = [...chain.agents];
    // Ensure Sarah is in the set when she delegated
    if (sarahDelegated && !agentList.includes('dmm')) agentList.push('dmm');
    if (agentList.length < 2) return;
    // Build pairs — Sarah ↔ each delegated agent (the orchestrator view)
    var sarah = 'dmm';
    agentList.forEach(other => {
      if (other === sarah) return;
      var pair = [sarah, other].sort().join('-');
      if (!connMap[pair]) connMap[pair] = [];
      chain.tasks.forEach(t => {
        if (!connMap[pair].find(x => x.id === t.id)) connMap[pair].push(t);
      });
    });
  });

  var canvasEl=document.getElementById('canvas-agents');
  if(!canvasEl) return;

  // 2026-05-26 — instrument: log how many lines drew this pass + the pairs.
  // Helps users verify visually in DevTools whether the canvas line render
  // path is actually firing.
  var _drawnPairs = [];
  // Draw collaboration lines between agents
  Object.entries(connMap).forEach(([pair,tasks],pairIdx)=>{
    var [a,b]=pair.split('-');
    var nodeA=document.getElementById('node-'+a);
    var nodeB=document.getElementById('node-'+b);
    if(!nodeA||!nodeB) return;

    // 2026-05-26 — read positions DIRECTLY from agent-node DOM, not from
    // nodePos. nodePos can be stale (defaults from core.js init vs. IIFE
    // radial layout that overrides style.left/top). When they diverge,
    // lines render at the wrong coordinates. The DOM is the source of truth.
    function nodeCenter(n) {
      var x = parseInt(n.style.left, 10);
      var y = parseInt(n.style.top, 10);
      if (isNaN(x)) x = (nodePos[n.dataset.agent] || {}).x || 0;
      if (isNaN(y)) y = (nodePos[n.dataset.agent] || {}).y || 0;
      // +80 +60 = approx center of a 160×140 agent card
      return { x: x + 80, y: y + 60 };
    }
    var pA = nodeCenter(nodeA), pB = nodeCenter(nodeB);
    var x1 = pA.x, y1 = pA.y;
    var x2 = pB.x, y2 = pB.y;
    var mx=(x1+x2)/2, my=(y1+y2)/2;
    var dx=x2-x1, dy=y2-y1, len=Math.sqrt(dx*dx+dy*dy)||1;
    var nx=-dy/len, ny=dx/len;
    var offsetAmt=50*(pairIdx%2===0?1:-1);
    var cx=mx+nx*offsetAmt, cy=my+ny*offsetAmt;

    // 2026-05-26 — Bug 2: animation now fires for ANY non-terminal task in
    // the chain, not just running/verifying (which were too brief to ever
    // catch). Upcoming + ongoing both animate; only fully-settled chains
    // (all completed/cancelled/failed) stop animating.
    var hasOngoing  = tasks.some(t=>t.status==='ongoing'||t.status==='in_progress');
    var hasUpcoming = tasks.some(t=>t.status==='upcoming');
    var hasActive   = hasOngoing || hasUpcoming;
    var color = hasOngoing ? 'var(--ac)' : (hasUpcoming ? 'var(--am)' : 'var(--bl)');

    // 2026-05-26 — Bug 3: flash on new chain detected. Track previous
    // poll's pair set; pairs new in this draw get a 3s flash on top of
    // their normal class. Makes delegation visible even when tasks finish
    // between polls.
    var prevPairs = window._previousLinePairs || {};
    var isNewPair = !prevPairs[pair];

    var path=document.createElementNS('http://www.w3.org/2000/svg','path');
    path.setAttribute('d',`M ${x1} ${y1} Q ${cx} ${cy} ${x2} ${y2}`);
    path.setAttribute('stroke',color);path.setAttribute('stroke-width','2.5');
    path.setAttribute('stroke-dasharray','7,4');path.setAttribute('fill','none');
    path.setAttribute('opacity','0.95');
    path.style.filter='drop-shadow(0 0 4px rgba(0,0,0,0.55))';
    path.style.pointerEvents='stroke';path.style.cursor='pointer';
    path.setAttribute('class', hasActive ? 'canvas-line active' : 'canvas-line');
    if (isNewPair) {
      path.classList.add('flash');
      setTimeout(function(pe){ try { pe.classList.remove('flash'); } catch(_e){} }, 3000, path);
    }
    path.addEventListener('mouseenter',e=>showConnTooltip(e,a,b,tasks));
    path.addEventListener('mouseleave',hideConnTooltip);
    svg.appendChild(path);

    // Count label at midpoint
    var bg=document.createElementNS('http://www.w3.org/2000/svg','rect');
    bg.setAttribute('x',cx-22);bg.setAttribute('y',cy-11);bg.setAttribute('width','44');bg.setAttribute('height','18');
    bg.setAttribute('rx','9');bg.setAttribute('fill','#171A21');bg.setAttribute('stroke',color);bg.setAttribute('stroke-width','1');bg.setAttribute('opacity','.9');
    svg.appendChild(bg);
    var lbl=document.createElementNS('http://www.w3.org/2000/svg','text');
    lbl.setAttribute('x',cx);lbl.setAttribute('y',cy+1);lbl.setAttribute('text-anchor','middle');
    lbl.setAttribute('dominant-baseline','middle');lbl.setAttribute('font-size','9');
    lbl.setAttribute('font-family','Inter,sans-serif');lbl.setAttribute('font-weight','700');
    lbl.setAttribute('fill',color);lbl.textContent=tasks.length+' task'+(tasks.length>1?'s':'');
    lbl.style.pointerEvents='none'; svg.appendChild(lbl);
    _drawnPairs.push({ pair: pair, tasks: tasks.length, active: hasActive, flash: isNewPair });
  });
  // 2026-05-26 — bookkeeping + summary log so user can verify in DevTools.
  var newPairSet = {};
  _drawnPairs.forEach(function(p){ newPairSet[p.pair] = true; });
  window._previousLinePairs = newPairSet;
  console.log('[drawCanvas] paths drawn:', _drawnPairs.length, _drawnPairs);
}

function renderTaskNode(task, agents) {
  var canvasEl = document.getElementById('canvas-agents');
  if (!canvasEl) return;

  // Compute centroid of assigned agent nodes
  let sumX=0, sumY=0, count=0;
  agents.forEach(id => {
    if (!nodePos[id]) return;
    sumX += nodePos[id].x + 80;
    sumY += nodePos[id].y + 60;
    count++;
  });
  if (!count) return;
  // Use stored position if user has dragged the task node
  var centX = sumX/count - 70;
  var centY = sumY/count - 44;
  var stored = taskNodePos[task.id];
  var cx = stored ? stored.x : centX;
  var cy = stored ? stored.y : centY;

  var ag = agents.map(id=>AGENTS[id]).filter(Boolean);
  var priCls = task.priority==='high'?'high':task.priority==='low'?'low':'medium';
  var stLabel = task.status==='in_progress'?'In Progress':task.status==='ongoing'?'Active':task.status==='completed'?'Done':'Planned';

  // Wave 85 — red-blink when the task is awaiting approval (requires_approval && approval_status is pending or null)
  var awaiting = !!(task.requires_approval && (!task.approval_status || task.approval_status === 'pending'));
  var node = document.createElement('div');
  node.className = 'task-node' + (awaiting ? ' awaiting-approval' : '');
  node.id = 'tnode-'+task.id;
  node.style.left = cx+'px';
  node.style.top  = cy+'px';
  node.dataset.taskId = task.id;
  node.dataset.taskAgents = agents.join(',');
  node.innerHTML = `
    <div class="tn-title" title="${esc(task.title)}">${esc(task.title)}</div>
    <div class="tn-assignees">${ag.map(a=>`<div class="tn-av" style="background:transparent;border-color:transparent" title="${a.name}">${window.luAvatar?luAvatar(a.name,20):a.emoji}</div>`).join('')}</div>
    <div class="tn-meta">
      <span class="tn-pri ${priCls}">${task.priority||'medium'}</span>
      <span class="tn-status">${stLabel}</span>
    </div>`;
  node.addEventListener('click', () => openTaskDrawer && openTaskDrawer(task.id));
  canvasEl.appendChild(node);

  // Draw SVG lines from task node to each agent node
  var svg = document.getElementById('canvas-svg');
  if (!svg) return;
  agents.forEach(id => {
    if (!nodePos[id]) return;
    var ax = nodePos[id].x + 80;
    var ay = nodePos[id].y + 60;
    var tnp = taskNodePos[task.id] || {x: cx, y: cy};
    var tx = tnp.x + 70;
    var ty = tnp.y + 44;

    var line = document.createElementNS('http://www.w3.org/2000/svg','line');
    line.setAttribute('x1',tx); line.setAttribute('y1',ty);
    line.setAttribute('x2',ax); line.setAttribute('y2',ay);
    var ag = AGENTS[id]||{};
    line.setAttribute('stroke', ag.color||'var(--t2)');
    line.setAttribute('stroke-width','1.5');
    line.setAttribute('stroke-dasharray','4,3');
    line.setAttribute('opacity','0.55');
    line.setAttribute('data-tnode', task.id); // tag for surgical redraw
    line.style.pointerEvents = 'none';
    svg.appendChild(line);
  });
}

function showConnTooltip(e,agentA,agentB,tasks){
  noteTargetConn={agentA,agentB,tasks};
  var tt=document.getElementById('conn-tooltip');
  var aInfo=AGENTS[agentA]||{}, bInfo=AGENTS[agentB]||{};
  document.getElementById('ctt-agents').innerHTML=`<span class="ct-agent-av">${window.luAvatar?luAvatar(aInfo.name||agentA,20):(aInfo.emoji||'?')}</span><span style="color:${aInfo.color||'#fff'};font-family:var(--fh);font-size:12px;font-weight:700">${aInfo.name||agentA}</span><span class="ct-arrow">⟷</span><span class="ct-agent-av">${bInfo.emoji||'?'}</span><span style="color:${bInfo.color||'#fff'};font-family:var(--fh);font-size:12px;font-weight:700">${bInfo.name||agentB}</span>`;
  var list=document.getElementById('ctt-tasks');
  list.innerHTML=tasks.slice(0,3).map(t=>{
    var stClass=t.status==='ongoing'?'st-ongoing':t.status==='upcoming'?'st-upcoming':'st-completed';
    var timeInfo=t.status==='ongoing'?`${window.icon('clock',14)} ${t.estimated_time}min est`:t.status==='completed'?`✓ ${t.actual_time||t.estimated_time}min used`:t.status==='upcoming'?`Est. ${t.estimated_time}min`:'';
    var tokenInfo=t.status==='completed'?`${(t.actual_tokens||t.estimated_tokens/1000).toFixed(0)}k tokens used`:t.status==='upcoming'?`~${(t.estimated_tokens/1000).toFixed(0)}k tokens est`:`~${(t.estimated_tokens/1000).toFixed(0)}k tokens`;
    return `<div class="ct-task"><div class="ct-task-title">${esc(t.title)}</div><div class="ct-task-meta"><span class="ct-task-status ${stClass}">${t.status}</span><span class="ct-timer">${timeInfo}</span><span class="ct-timer">${tokenInfo}</span></div></div>`;
  }).join('');
  tt.style.left=(e.clientX+14)+'px'; tt.style.top=(e.clientY-20)+'px';
  tt.classList.add('visible');
}
function hideConnTooltip(){
  setTimeout(()=>{var tt=document.getElementById('conn-tooltip');if(tt)tt.classList.remove('visible');},200);
}

// ── Tasks ──────────────────────────────────────────────────────────────────
async function loadTasks(){
  try{
    var res=await get(API+'tasks');
    // Phase 5: DB response has {tasks:[], source:'db'|'legacy'}
    // Legacy response is a plain array
    var raw = Array.isArray(res) ? res : (res.tasks || []);
    // Normalise DB rows to SPA shape (DB uses agent_id, SPA uses assignee)
    allTasks = raw.map(t => ({
      ...t,
      id:       t.id || t.task_id,
      // v1.4.4 (2026-05-30) — preserve batch_id for client-side grouping
      // in the workspace task views (drawer task list + agent task board).
      batch_id: t.batch_id || null,
      action:   t.action || '',
      assignee: (function(){
        var a = t.assignee || t.agent_id || '';
        if(!a && t.assigned_agents_json){
          var p=t.assigned_agents_json;
          if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p=[]}}
          if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p=[]}}
          a=Array.isArray(p)&&p.length?p[0]:'';
        }
        return a==='sarah'?'dmm':a;
      })(),
      assignees: (function(){
        // Parse assignees from assigned_agents_json (may be string, double-encoded, or array)
        var raw = t.assignees || t.assigned_agents_json || (t.agent_id ? [t.agent_id] : []);
        if(typeof raw==='string'){try{raw=JSON.parse(raw)}catch(e){raw=[]}}
        if(typeof raw==='string'){try{raw=JSON.parse(raw)}catch(e){raw=[]}} // double-encoded
        if(!Array.isArray(raw)) raw=[];
        // Map sarah→dmm to match AGENTS map keys
        return raw.map(function(s){return s==='sarah'?'dmm':s;});
      })(),
      // Extract title/description/times from payload_json (stored as JSON in DB)
      title: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        return (p&&p.title) || t.progress_message || t.action || '—';
      })(),
      description: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        return (p&&p.description) || t.progress_message || '';
      })(),
      estimated_time: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        return (p&&p.estimated_time) || 60;
      })(),
      estimated_tokens: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        return (p&&p.estimated_tokens) || 4000;
      })(),
      success_metric: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        return (p&&p.success_metric) || '';
      })(),
      coordinator: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        var co = (p&&p.coordinator) || '';
        return co==='sarah'?'dmm':co;
      })(),
      // Wave 38h — capture creator (Sarah for delegated chain tasks).
      delegated_by: (function(){
        var p = t.payload_json;
        if(typeof p==='string'){try{p=JSON.parse(p)}catch(e){p={}}}
        var cv = (p&&p.created_via) || '';
        if (cv === 'sarah_chat' || cv === 'sarah_proactive') return 'dmm';
        return null;
      })(),
      notes: (function(){
        var r = t.result_json;
        if(typeof r==='string'){try{r=JSON.parse(r)}catch(e){r={}}}
        return (r&&r.notes) || [];
      })(),
      // Map DB statuses to canvas statuses so connection lines render
      status: ({
        'in_progress':'ongoing', 'running':'ongoing',
        'pending':'upcoming', 'queued':'upcoming', 'awaiting_approval':'upcoming',
        'completed':'completed',
        'failed':'upcoming',       // failed tasks go back to backlog for retry
        'cancelled':'completed',   // cancelled = done
        'verifying':'ongoing', 'blocked':'upcoming', 'degraded':'ongoing',
      })[t.status] || t.status,
    }));
    updateNodeCounts();
    updateWorkloadIndicators();
    updateActivityLabels();
    drawCanvas();
    drawZones();
    loadAgentStats();
    // If agent nodes weren't ready on first draw, retry after they load
    if(!document.querySelector('.agent-node')){
      var _retryDraw=setInterval(function(){
        if(document.querySelector('.agent-node')){
          clearInterval(_retryDraw);
          drawCanvas(); drawZones(); updateNodeCounts(); updateWorkloadIndicators();
        }
      },500);
      setTimeout(function(){clearInterval(_retryDraw)},10000); // give up after 10s
    }
  }catch(e){console.error('loadTasks:',e);}
}

function updateNodeCounts(){
  // 2026-05-25 — writes BOTH workspace-tab tc-* IDs AND agents-tab av-* IDs
  // from window._agentStatsData (populated by loadAgentStats from
  // /api/agents/dashboard). Earlier FIX 67 NEUTERED this and only wrote
  // the progress bar — that's why the workspace tab stayed empty: the
  // tc-* IDs (workspace-tab cards) and av-* IDs (agents-tab cards) are
  // DIFFERENT DOM elements on DIFFERENT views. They look identical but
  // live in separate HTML sections (view-workspace at index.html L2522
  // vs view-agents at L4274).
  //
  // Both sets now read from the same _agentStatsData dictionary — single
  // source of truth, no race with loadAgentStats.
  Object.keys(AGENTS).forEach(id=>{
    var stats = (window._agentStatsData && window._agentStatsData[id]) || null;
    if (!stats) return;
    var ongoing   = stats.ongoing   || 0;
    var upcoming  = stats.upcoming  || 0;
    var completed = stats.completed || 0;
    var tot = ongoing + upcoming + completed || 1;
    // workspace-tab cards
    setEl('tc-ongoing-'+id,   ongoing);
    setEl('tc-upcoming-'+id,  upcoming);
    setEl('tc-completed-'+id, completed);
    // agents-tab cards (kept in sync with loadAgentStats writes)
    setEl('av-ongoing-'+id,   ongoing);
    setEl('av-upcoming-'+id,  upcoming);
    setEl('av-completed-'+id, completed);
    var onBar = document.getElementById('tb-ongoing-'+id);
    if (onBar) onBar.style.width = Math.min(ongoing/tot*100*3, 100) + '%';
  });
}

function updateWorkloadIndicators(){
  Object.keys(AGENTS).forEach(id=>{
    var active=allTasks.filter(t=>(t.assignee===id||(t.assignees||[]).includes(id))&&(t.status==='ongoing'||t.status==='in_progress'||t.status==='upcoming')).length;
    var dot=document.getElementById('wl-dot-'+id);
    var lbl=document.getElementById('wl-label-'+id);
    if(!dot||!lbl) return;
    dot.className='wl-dot';
    if(active===0){dot.classList.add('available');lbl.textContent='Available';}
    else if(active<=3){dot.classList.add('moderate');lbl.textContent=active+' task'+(active>1?'s':'');}
    else{dot.classList.add('overloaded');lbl.textContent='Overloaded ('+active+')';}
  });
}

function updateActivityLabels(){
  Object.keys(AGENTS).forEach(id=>{
    var inProgress=allTasks.find(t=>(t.assignee===id||(t.assignees||[]).includes(id))&&(t.status==='in_progress'||t.status==='ongoing'));
    var el=document.getElementById('an-activity-'+id);
    if(!el) return;
    if(inProgress){
      el.textContent='▶ Working on: '+inProgress.title;
      el.classList.add('visible');
    } else {
      el.textContent='';
      el.classList.remove('visible');
    }
  });
}


// ── Task approval ──────────────────────────────────────────────────────────
async function checkPendingTasks(meetingId){
  // ── Safe tools that can auto-execute without user approval ────────
  var SAFE_AUTO_TOOLS = new Set([
    'serp_analysis','ai_report','deep_audit','ai_status','list_goals','agent_status',
    'list_leads','get_lead','list_campaigns','list_templates','list_posts','get_queue',
    'list_events','check_availability','list_builder_pages','get_builder_page',
    'link_suggestions','outbound_links','check_outbound','list_sequences',
    'get_site_pages','get_site_page','search_site_content','scan_site_url',
    'system_health_check','list_previews','proactive_status','memory_context',
    'analyze_funnel_structure','generate_funnel_blueprint',
  ]);

  try{
    var d=await get(API+'meeting/'+meetingId+'/pending-tasks');
    var links=document.getElementById('meeting-end-links');
    if(d&&d.tasks&&d.tasks.length){
      // Split into auto-approvable and needs-review
      var safeIds=[];
      var riskyTasks=[];
      d.tasks.forEach(t=>{
        var tools=(t.tools||[]).map(x=>typeof x==='string'?x:(x.tool_id||x.id||'')).filter(Boolean);
        var allSafe=tools.length>0&&tools.every(tid=>SAFE_AUTO_TOOLS.has(tid));
        if(allSafe) safeIds.push(t.id);
        else riskyTasks.push(t);
      });

      // Auto-approve safe tasks immediately
      if(safeIds.length>0){
        try{
          await post(API+'tasks/approve',{approved:safeIds,tasks:d.tasks.filter(t=>safeIds.includes(t.id)),meeting_id:meetingId});
        }catch(e){console.warn('Auto-approve safe tasks:',e);}
      }

      // Show modal only for risky tasks (or summary if all auto-approved)
      if(riskyTasks.length>0){
        pendingApprovalData={...d,tasks:riskyTasks,meeting_id:meetingId};
        if(links) links.innerHTML=`
          <div style="display:flex;flex-direction:column;gap:8px;align-items:center">
            ${safeIds.length>0?`<span style="font-size:12px;color:var(--ac);font-weight:600">✓ ${safeIds.length} safe tasks auto-dispatched</span>`:''}
            <span style="font-size:12px;font-weight:600;color:var(--am)">${riskyTasks.length} tasks need your approval</span>
            <button class="btn btn-primary btn-sm" onclick="document.getElementById('approval-backdrop').classList.add('visible')" style="font-size:11px">Review & Approve Tasks</button>
          </div>
        `;
        showApprovalModal({...d,tasks:riskyTasks});
      } else {
        // All tasks were safe and auto-approved
        if(links) links.innerHTML=`
          <div style="display:flex;flex-direction:column;gap:8px;align-items:center">
            <span style="font-size:13px;font-weight:600;color:var(--ac)">✓ ${safeIds.length} tasks auto-dispatched to agents</span>
            <div style="display:flex;gap:8px">
              <button class="btn btn-outline btn-sm" onclick="nav('workspace')" style="font-size:11px">${window.icon('more',14)} View Tasks</button>
              <button class="btn btn-outline btn-sm" onclick="nav('previews')" style="font-size:11px">${window.icon('eye',14)} Previews</button>
            </div>
          </div>
        `;
        showToast(`${safeIds.length} tasks auto-dispatched to agents!`, 'success');
      }
    } else {
      if(links) links.innerHTML=`
        <span style="font-size:12px;color:var(--t3)">No tasks generated this session.</span>
        <button class="btn btn-outline btn-sm" onclick="nav('previews')" style="font-size:11px">${window.icon('eye',14)} View Previews</button>
        <button class="btn btn-outline btn-sm" onclick="nav('workspace')" style="font-size:11px">${window.icon('more',14)} View Tasks</button>
      `;
    }
  }catch(e){
    console.warn('checkPending:',e);
    var links=document.getElementById('meeting-end-links');
    if(links) links.innerHTML='<span style="font-size:11px;color:var(--t3)">Could not load pending tasks.</span>';
  }
}
function showApprovalModal(data){
  var list=document.getElementById('am-tasks-list');
  var p=document.getElementById('am-sub');
  p.textContent=`From "${data.topic||'Strategy Session'}" — select which tasks to assign to your team.`;
  list.innerHTML=data.tasks.map((t,i)=>{
    var ag=AGENTS[t.assignee]||{};
    var co=t.coordinator?AGENTS[t.coordinator]:null;
    var priCls=t.priority==='high'?'ab-high':t.priority==='medium'?'ab-med':'ab-low';
    return `<div class="am-task selected" id="amt-${i}" onclick="toggleTask(${i})">
      <div class="am-task-top">
        <div class="am-check" id="amch-${i}">✓</div>
        <div class="am-task-body">
          <div class="am-task-title">${esc(t.title)}</div>
          <div class="am-task-desc">${esc(t.description)}</div>
          ${t.success_metric?`<div style="font-size:10px;color:var(--ac);margin-bottom:8px;display:flex;align-items:center;gap:5px"><span style="opacity:.6">${window.icon('more',14)} Success:</span> ${esc(t.success_metric)}</div>`:''}
          <div class="am-task-meta">
            <span class="am-badge ab-agent">${ag.emoji||''} ${ag.name||t.assignee}</span>
            ${co?`<span class="am-badge ab-agent" style="opacity:.7">↔ ${co.emoji||''} ${co.name||t.coordinator}</span>`:''}
            <span class="am-badge ${priCls}">${t.priority}</span>
            <span class="am-badge" style="background:rgba(139,151,176,.08);color:var(--t2);border-color:rgba(139,151,176,.2)">${window.icon('clock',14)} ${t.estimated_time}min</span>
            <span class="am-badge" style="background:rgba(139,151,176,.08);color:var(--t2);border-color:rgba(139,151,176,.2)">~${(t.estimated_tokens/1000).toFixed(0)}k tokens</span>
          </div>
        </div>
      </div>
    </div>`;
  }).join('');
  updateApprovalCount();
  document.getElementById('approval-backdrop').classList.add('visible');
}
function toggleTask(i){
  var el=document.getElementById('amt-'+i);
  var ch=document.getElementById('amch-'+i);
  el.classList.toggle('selected');
  ch.textContent=el.classList.contains('selected')?'✓':'';
  updateApprovalCount();
}
function updateApprovalCount(){
  var sel=document.querySelectorAll('.am-task.selected').length;
  var tot=pendingApprovalData?.tasks?.length||0;
  setEl('am-count',`${sel} of ${tot} selected`);
}
async function approveSelected(){
  if(!pendingApprovalData) return;
  var approved=[];
  pendingApprovalData.tasks.forEach((t,i)=>{if(document.getElementById('amt-'+i)?.classList.contains('selected')) approved.push(t.id);});
  var btn=document.querySelector('#approval-backdrop .btn-primary');
  if(btn){btn.disabled=true;btn.textContent='Processing…';}
  try{
    var result=await post(API+'tasks/approve',{approved,tasks:pendingApprovalData.tasks,meeting_id:pendingApprovalData.meeting_id});
    document.getElementById('approval-backdrop').classList.remove('visible');
    pendingApprovalData=null;

    // Phase 6.5: Show execution summary
    var ti=result.tasks_imported||0;
    var pc=result.previews_created||0;
    var total=result.saved||approved.length;

    // Update meeting end banner
    var links=document.getElementById('meeting-end-links');
    if(links){
      let html='<div style="display:flex;flex-direction:column;gap:8px;align-items:center;width:100%">';
      html+=`<div style="display:flex;gap:16px;font-size:13px">`;
      html+=`<span style="color:var(--ac);font-weight:600">✓ ${total} tasks approved</span>`;
      if(ti>0) html+=`<span style="color:var(--bl);display:inline-flex;align-items:center;gap:4px">${window.icon('more',13)} ${ti} sent to agents</span>`;
      if(pc>0) html+=`<span style="color:var(--am);display:inline-flex;align-items:center;gap:4px">${window.icon('eye',13)} ${pc} queued for preview</span>`;
      html+=`</div>`;
      html+=`<div style="display:flex;gap:8px">`;
      if(pc>0) html+=`<button class="btn btn-primary btn-sm" onclick="nav('previews')" style="font-size:11px">${window.icon('eye',14)} Review Previews (${pc})</button>`;
      html+=`<button class="btn btn-outline btn-sm" onclick="nav('workspace')" style="font-size:11px">${window.icon('more',14)} View All Tasks</button>`;
      html+=`</div></div>`;
      links.innerHTML=html;
    }

    // Toast summary
    if(pc>0) showToast(`${total} approved: ${ti} tasks started, ${pc} previews for your review`, 'success');
    else showToast(`${total} tasks approved and sent to agents!`, 'success');

    await loadTasks();
  }catch(e){
    showToast('Error: '+e.message, 'error');
    if(btn){btn.disabled=false;btn.textContent='Approve Selected';}
  }
}
async function dismissApproval(){
  if(!pendingApprovalData) return;
  try{await post(API+'tasks/approve',{approved:[],tasks:[],meeting_id:pendingApprovalData.meeting_id});}catch(e){}
  document.getElementById('approval-backdrop').classList.remove('visible');
  pendingApprovalData=null;
}

// ── Note modal ─────────────────────────────────────────────────────────────
function openNoteModal(){
  if(!noteTargetConn) return;
  var a=AGENTS[noteTargetConn.agentA]||{},b=AGENTS[noteTargetConn.agentB]||{};
  setEl('nm-sub',`Note for ${a.name||noteTargetConn.agentA} ↔ ${b.name||noteTargetConn.agentB} collaboration`);
  document.getElementById('note-ta').value='';
  document.getElementById('note-backdrop').classList.add('visible');
  document.getElementById('conn-tooltip').classList.remove('visible');
}
function closeNoteModal(){document.getElementById('note-backdrop').classList.remove('visible');}
async function saveNote(){
  var text=document.getElementById('note-ta').value.trim();
  if(!text||!noteTargetConn) return;
  var taskId=noteTargetConn.tasks[0]?.id;
  if(!taskId){closeNoteModal();return;}
  try{
    await post(API+'tasks/'+taskId+'/note',{text,author:'User'});
    closeNoteModal();
  }catch(e){showToast('Error: '+e.message,'error');}
}

// ── Agent drawer ───────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────
// Live agent-chat event stream (2026-06-15) — web parity with the mobile
// companion app's eventClient. The drawer previously only refreshed on open +
// a bounded 90s post-send poll, so async/proactive agent replies and task
// lifecycle never appeared live in the web chat. This wires the open drawer
// into the SAME brain stream the mobile app consumes — GET /api/agent/events
// (AgentDispatchController::events, already mounted under the SPA's auth.jwt
// group). No client-side intelligence: Laravel emits render-ready, humanized
// copy (no-schema-leakage); the client only places it. Dedup is shared with
// loadDrawerMessages + the two-phase poll via window._agentChatRendered so the
// final reply / ack / history never double-render. Scoped to the open drawer;
// stopped on close; self-disables after repeated failures.
// ─────────────────────────────────────────────────────────────────
function _acRenderedSet(slug){
  window._agentChatRendered = window._agentChatRendered || {};
  if(!window._agentChatRendered[slug]) window._agentChatRendered[slug] = new Set();
  return window._agentChatRendered[slug];
}
function _acIsRendered(slug, id){ return id!=null && _acRenderedSet(slug).has(String(id)); }
function _acMark(slug, id){ if(id!=null) _acRenderedSet(slug).add(String(id)); }
// Backend emits conversation_id='sarah'; the web AGENTS key for Sarah is 'dmm'.
function _acNorm(slug){ return slug === 'sarah' ? 'dmm' : (slug||''); }

function _acNearBottom(feed){ return (feed.scrollHeight - (feed.scrollTop + feed.clientHeight)) < 120; }

function _acRenderAgentBubble(slug, content, tsMs, isError){
  var feed=document.getElementById('msgs-feed-container'); if(!feed) return;
  var ag=AGENTS[slug]||{};
  var stick=_acNearBottom(feed);
  var div=document.createElement('div');
  div.className='msg-from-agent';
  div.style.alignSelf='flex-start';
  var c = isError ? 'var(--rd,#dc2626)' : (ag.color||'var(--t2)');
  div.innerHTML='<div style="font-size:9px;font-weight:700;color:'+c+';margin-bottom:3px">'+esc(ag.name||slug)+(isError?' · error':'')+'</div>'+(typeof fmt==='function'?fmt(content||''):esc(content||''))+'<div class="msg-ts">'+new Date(tsMs||Date.now()).toLocaleTimeString()+'</div>';
  feed.appendChild(div);
  if(stick) feed.scrollTop=feed.scrollHeight;
}

// Task-lifecycle card — natural-language, no technical kicker (mirrors the
// mobile StructuredCard). Uses existing design-system vars only.
function _acRenderActivityCard(ev){
  var feed=document.getElementById('msgs-feed-container'); if(!feed) return;
  var d = ev.data || {};
  var accent = (ev.type==='failure_notice') ? 'var(--rd,#dc2626)'
             : (ev.type==='approval_request') ? 'var(--p)'
             : (ev.type==='output_preview') ? 'var(--p)'
             : 'var(--am)';
  var pct = (typeof d.progress === 'number') ? Math.max(0,Math.min(100,Math.round(d.progress))) : null;
  var lead = esc(ev.content || 'Update');
  var html = '<span style="font-size:11px;color:var(--t2);line-height:1.5">'+lead+'</span>';
  if(pct!==null && ev.type==='progress_update'){
    html += '<div style="height:4px;background:var(--s3);border-radius:2px;overflow:hidden;margin-top:6px"><div style="height:100%;width:'+pct+'%;background:'+accent+'"></div></div>';
  }
  if(ev.type==='approval_request'){
    html += '<div style="margin-top:6px"><button onclick="if(typeof nav===\'function\')nav(\'command\')" style="background:var(--ps);border:1px solid var(--p);color:var(--pu);border-radius:var(--rg);padding:4px 10px;font-size:10px;font-weight:600;cursor:pointer">Review &amp; approve</button></div>';
  }
  var stick=_acNearBottom(feed);
  var div=document.createElement('div');
  div.className='msg-activity-card';
  div.style.cssText='align-self:flex-start;max-width:88%;background:var(--s1);border:1px solid var(--bd);border-left:3px solid '+accent+';border-radius:var(--rg);padding:8px 12px;margin:3px 0';
  div.innerHTML=html;
  feed.appendChild(div);
  if(stick) feed.scrollTop=feed.scrollHeight;
}

function _acHandleEvents(events, drawerSlug, primed){
  if(!Array.isArray(events)) return;
  for(var i=0;i<events.length;i++){
    var ev=events[i];
    if(!ev || !ev.id) continue;
    if(_acNorm(String(ev.conversation_id||'')) !== drawerSlug) continue; // only the open thread
    if(ev.type==='message' || ev.type==='agent_reply'){
      var rowId = String(ev.id).indexOf('am_')===0 ? String(ev.id).slice(3) : String(ev.id);
      if(_acIsRendered(drawerSlug,rowId) || _acIsRendered(drawerSlug,ev.id)) continue;
      _acMark(drawerSlug,rowId); _acMark(drawerSlug,ev.id);
      // a live reply means any in-flight two-phase "working…"/typing UI is done
      /* 1A.2: indicators are per-turn now — sweep by prefix, not by fixed id */
try{ document.querySelectorAll('[id^="agent-still-working"]').forEach(function(_w){ _w.remove(); }); }catch(_e){}
      var ti=document.getElementById('agent-typing-indicator'); if(ti) ti.remove();
      _acRenderAgentBubble(drawerSlug, ev.content, ev.timestamp?Date.parse(ev.timestamp):Date.now(), !!(ev.data&&ev.data.error));
      // D4 — rendered live in an open drawer, therefore read.
      try { if(typeof window._msgMarkRead==='function') window._msgMarkRead(_acNorm(drawerSlug)==='dmm'?'sarah':drawerSlug); } catch(e){}
    } else {
      // Task lifecycle. Only render cards for activity that happened AFTER the
      // drawer opened (primed) — the first poll's backlog (cursor=null returns
      // the last 5 min) is consumed to advance the cursor, not replayed.
      if(!primed) continue;
      if(_acIsRendered(drawerSlug,ev.id)) continue;
      _acMark(drawerSlug,ev.id);
      _acRenderActivityCard(ev);
    }
  }
}

function _acStartEventPoll(drawerSlug){
  _acStopEventPoll();
  var queryConv = (drawerSlug==='dmm') ? 'sarah' : drawerSlug;
  window._agentChatCursor = null;
  window._agentChatPollFails = 0;
  window._agentChatPrimed = false;
  var tick = async function(){
    if(document.hidden) return;
    if(window._agentDrawerOpen !== drawerSlug) { _acStopEventPoll(); return; }
    try{
      var url = API+'agent/events?conversation_id='+encodeURIComponent(queryConv)+(window._agentChatCursor?('&cursor='+encodeURIComponent(window._agentChatCursor)):'');
      var res = await get(url);
      if(res && Array.isArray(res.events)){
        if(res.cursor) window._agentChatCursor = res.cursor;
        if(res.events.length) _acHandleEvents(res.events, drawerSlug, window._agentChatPrimed);
      }
      window._agentChatPrimed = true;  // subsequent ticks render task cards live
      window._agentChatPollFails = 0;
    }catch(e){
      window._agentChatPollFails = (window._agentChatPollFails||0)+1;
      if(window._agentChatPollFails>=5){ console.warn('[AgentChat] live event poll disabled after 5 failures'); _acStopEventPoll(); }
    }
  };
  window._agentChatEventTimer = setInterval(tick, 2500);
  tick();
}
function _acStopEventPoll(){
  if(window._agentChatEventTimer){ clearInterval(window._agentChatEventTimer); window._agentChatEventTimer=null; }
}

function openAgentDrawer(id){
  console.log('[Drawer] openAgentDrawer called for', id);
  currentAgent=id;
  window._agentDrawerOpen=id;
  var ag=AGENTS[id]||(window.luAgent&&luAgent(id)?{name:luAgent(id).name,role:luAgent(id).title,color:luAgent(id).color,expertise:luAgent(id).skills||[],emoji:''}:{});   /* AVATAR888: every registry agent opens */
  console.log('[Drawer] AGENTS[' + id + '] =', ag.name || '(not in AGENTS map)');
  var col='var(--t1)';   /* Owner 2026-09-15: no colour per agent */

  // 2026-05-25 — each sub-render wrapped so one failure doesn't abort the
  // drawer open. Previously a throw in renderProfilePane / loadDrawerMessages
  // / etc. could leave the drawer half-open or hidden. Forensic logging
  // surfaces the actual error to the browser console.
  function tryRun(label, fn) {
    try { fn(); } catch (e) {
      console.error('[Drawer] ' + label + ' threw for agent=' + id + ':', e.message, e.stack ? e.stack.split('\n')[0] : '');
    }
  }
  tryRun('ad-av',           function () { var el = document.getElementById('ad-av'); if (el) el.innerHTML=(typeof buildAgentOrb==='function')?buildAgentOrb(id,'lg','idle'):('<div style="width:52px;height:52px;font-size:26px">'+(ag.emoji||'?')+'</div>'); });
  tryRun('ad-name',         function () { var el = document.getElementById('ad-name'); if (el) { el.textContent = ag.name || id; el.style.color = col; } });
  tryRun('ad-role',         function () { setEl('ad-role',ag.role||''); });
  tryRun('loadAgentStats',  function () { if (typeof loadAgentStats === 'function') loadAgentStats(); });
  tryRun('renderProfilePane', function () { renderProfilePane(id,ag); });
  tryRun('renderDrawerTasks', function () { renderDrawerTasks(id,'all'); });
  tryRun('loadDrawerMessages',function () { loadDrawerMessages(id); });
  tryRun('renderDocuments', function () { renderDocuments(id); });
  tryRun('drawerTab',       function () { drawerTab('profile'); });

  // Open the drawer DOM unconditionally — these elements are static.
  var bg = document.getElementById('drawer-bg');
  var dr = document.getElementById('agent-drawer');
  if (bg) bg.classList.add('visible'); else console.error('[Drawer] #drawer-bg not in DOM');
  if (dr) dr.classList.add('open');    else console.error('[Drawer] #agent-drawer not in DOM');
  console.log('[Drawer] open complete for', id);
  // 2026-05-25 — live refresh while drawer is open. Poll every 5s,
  // pause when tab is hidden. Cleared by closeAgentDrawer.
  if (window._agentDrawerTimer) { clearInterval(window._agentDrawerTimer); }
  window._agentDrawerTimer = setInterval(function(){
    if (document.hidden) return;
    if (!window._agentDrawerOpen) return;
    // 2026-05-25 — only loadAgentStats on the poll. It's the source of
    // truth for counter numbers AND its onSuccess re-renders the drawer's
    // counter strip + profile pane (see body above). Skipping loadTasks
    // here: its updateNodeCounts call was racing with loadAgentStats and
    // causing flicker/revert. The recent-activity list refreshes when the
    // user changes drawer tab or filters manually.
    if (typeof loadAgentStats === 'function') loadAgentStats();
  }, 5000);
  // 2026-06-15 — wire this thread into the live brain event stream (parity
  // with the mobile companion app). Renders async agent replies + task cards
  // inline while the drawer is open. Stopped by closeAgentDrawer.
  try { _acStartEventPoll(id); } catch (e) { console.warn('[AgentChat] live poll start failed', e); }
  // 2026-07-26 (chat forensic D3) — the drawer never marked anything read,
  // so the floater badge survived every conversation held here. Reading a
  // thread must clear it, exactly as the messages widget does.
  try {
    var _rdSlug = (id === 'dmm') ? 'sarah' : id;
    if (typeof window._msgMarkRead === 'function') window._msgMarkRead(_rdSlug);
  } catch (e) {}
}
function closeAgentDrawer(){
  document.getElementById('drawer-bg').classList.remove('visible');
  document.getElementById('agent-drawer').classList.remove('open');
  currentAgent=null;
  window._agentDrawerOpen=null;
  // 2026-05-25 — stop the polling timer.
  if (window._agentDrawerTimer) { clearInterval(window._agentDrawerTimer); window._agentDrawerTimer = null; }
  // 2026-06-15 — stop the live event poller.
  try { _acStopEventPoll(); } catch (e) {}
}
function drawerTab(tab){
  ['profile','tasks','messages','documents','board'].forEach(t=>{
    document.getElementById('dt-'+t)?.classList.toggle('active',t===tab);
    document.getElementById('dp-'+t)?.classList.toggle('active',t===tab);
  });
  if(tab==='board' && window._agentDrawerOpen) loadAgentStats(); if(tab==='tasks' && currentAgent){ if(typeof loadTasks==='function') loadTasks().then(function(){renderDrawerTasks(currentAgent,'all');}); else renderDrawerTasks(currentAgent,'all'); }
}
function renderProfilePane(id,ag){
  // 2026-05-25 — read from window._agentStatsData (same source as drawer
  // counter strip + agent grid card). loadAgentStats() populates it.
  var stats = (window._agentStatsData && window._agentStatsData[id]) || { ongoing: 0, upcoming: 0, completed: 0 };
  var ongoing  = stats.ongoing;
  var upcoming = stats.upcoming;
  var done     = stats.completed;
  var col=ag.color||'var(--t2)';
  document.getElementById('dp-profile').innerHTML=`
    <div class="profile-section">
      <div class="profile-sec-title">Expertise</div>
      <div class="profile-expertise">${((ag.expertise&&ag.expertise.length)?ag.expertise:(ag.skills||ag.capabilities||[])).map(e=>`<span class="pe-tag">${esc(e)}</span>`).join('')||'<span style="color:var(--t3);font-size:11px">No expertise data</span>'}</div>
    </div>
    <div class="profile-section">
      <div class="profile-sec-title">Task Overview</div>
      <div class="profile-stat-grid">
        <div class="psg-item"><div class="psg-val" style="color:${col}">${ongoing}</div><div class="psg-lbl">Ongoing</div></div>
        <div class="psg-item"><div class="psg-val" style="color:var(--am)">${upcoming}</div><div class="psg-lbl">Upcoming</div></div>
        <div class="psg-item"><div class="psg-val" style="color:var(--bl)">${done}</div><div class="psg-lbl">Completed</div></div>
      </div>
    </div>`;
}
// 2026-05-27 — Phase 2: per-agent category breakdown shown on the drawer's
// Tasks tab. Reads allTasks (recent 50), counts only non-terminal tasks for
// THIS agent, groups by task.category. Returns '' when agent has no active
// work so we don't render a meaningless empty strip.
function _drawerCategoryStripHtml(slug) {
  if (!Array.isArray(allTasks) || !allTasks.length) return '';
  var TERMINAL = { completed: 1, failed: 1, cancelled: 1, degraded: 1 };
  var counts = {};
  allTasks.forEach(function (t) {
    if (TERMINAL[t.status]) return;
    var as = t.assignees || [];
    var matches = (t.assignee === slug) || (as.indexOf(slug) !== -1)
      // dmm alias for Sarah
      || (slug === 'dmm' && (t.assignee === 'sarah' || as.indexOf('sarah') !== -1));
    if (!matches) return;
    var c = t.category || 'operations';
    counts[c] = (counts[c] | 0) + 1;
  });
  var keys = Object.keys(counts);
  if (!keys.length) return '';
  var ORDER = ['research', 'create', 'optimize', 'publish', 'crm', 'campaign', 'operations'];
  var COLOR = { research:'#3B82F6', create:'#7C3AED', optimize:'#00E5A8', publish:'#F59E0B', crm:'#EC4899', campaign:'#F97316', operations:'#6B7280' };
  var LABEL = { research:'Research', create:'Create', optimize:'Optimize', publish:'Publish', crm:'CRM', campaign:'Campaign', operations:'Ops' };
  var chips = ORDER.filter(function (k) { return counts[k]; }).map(function (k) {
    var c = COLOR[k];
    return '<span style="padding:3px 9px;background:' + c + '15;color:' + c + ';border:1px solid ' + c + '40;border-radius:99px;font-size:10px;font-weight:600;font-family:var(--fh);display:inline-flex;align-items:center;gap:5px">'
      + '<span style="width:6px;height:6px;border-radius:50%;background:' + c + '"></span>'
      + counts[k] + ' ' + LABEL[k]
      + '</span>';
  });
  return '<div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;padding:8px 10px;background:var(--s2);border:1px solid var(--bd);border-radius:8px">'
    + '<span style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;align-self:center;margin-right:4px;font-family:var(--fh)">Active by category</span>'
    + chips.join('')
    + '</div>';
}

// v1.4.4 (2026-05-30) — shared task-grouping helper.
// Collapses an array of task rows into "groups" keyed by (batch_id + action).
// A single-task group keeps the row as-is. Multi-task groups expose:
//   count, sample_titles[], all_completed bool, mixed_status bool,
//   primary (the canonical task row used for status + meta), tasks (all members).
// Used by both the agent drawer list and the agent task board so the same
// "10 articles — Priya" UX shows up wherever tasks render.
function _luGroupTasksByBatch(tasks) {
  var groups = [];
  var byKey = Object.create(null);
  for (var i = 0; i < tasks.length; i++) {
    var t = tasks[i];
    var key = (t.batch_id && t.action) ? ('batch:' + t.batch_id + ':' + t.action) : ('solo:' + (t.id || i));
    if (!byKey[key]) {
      var g = { key: key, batch_id: t.batch_id || null, action: t.action || '', tasks: [], sample_titles: [], primary: t };
      groups.push(g);
      byKey[key] = g;
    }
    var grp = byKey[key];
    grp.tasks.push(t);
    if (t.title && grp.sample_titles.length < 6 && grp.sample_titles.indexOf(t.title) === -1 && t.title !== '—') {
      grp.sample_titles.push(t.title);
    }
  }
  // Annotate groups
  return groups.map(function(g) {
    g.count = g.tasks.length;
    g.is_batch = g.count > 1;
    var statuses = {};
    var allCompleted = true;
    var anyOngoing = false;
    var anyFailed = false;
    var anyUpcoming = false;
    for (var j = 0; j < g.tasks.length; j++) {
      var s = g.tasks[j].status;
      statuses[s] = (statuses[s] || 0) + 1;
      if (s !== 'completed') allCompleted = false;
      if (s === 'ongoing' || s === 'in_progress') anyOngoing = true;
      if (s === 'failed') anyFailed = true;
      if (s === 'upcoming' || s === 'pending' || s === 'queued') anyUpcoming = true;
    }
    g.status_counts = statuses;
    g.all_completed = allCompleted;
    g.any_ongoing  = anyOngoing;
    g.any_failed   = anyFailed;
    g.any_upcoming = anyUpcoming;
    // Roll-up status for the batch card: ongoing wins, else upcoming, else failed, else completed
    g.rollup_status = anyOngoing ? 'ongoing' : (anyUpcoming ? 'upcoming' : (anyFailed ? 'failed' : 'completed'));
    // Total estimated time / tokens
    g.total_time   = g.tasks.reduce(function(a, t){ return a + (t.estimated_time   || 0); }, 0);
    g.total_tokens = g.tasks.reduce(function(a, t){ return a + (t.estimated_tokens || 0); }, 0);
    return g;
  });
}

// Friendly label for a batched group (e.g. "5 articles" / "3 internal links")
function _luBatchUnit(action, count) {
  var unitMap = {
    'write_article':   ['article', 'articles'],
    'generate_meta':   ['meta block', 'meta blocks'],
    'insert_link':     ['internal link', 'internal links'],
    'generate_image':       ['image', 'images'],
    'generate_image_mini':  ['image', 'images'],
    'generate_image_high':  ['image', 'images'],
    'social_create_post':   ['social post', 'social posts'],
    'social_schedule_post': ['scheduled post', 'scheduled posts'],
    'create_lead':     ['lead', 'leads'],
    'create_campaign': ['campaign', 'campaigns'],
    'create_event':    ['calendar event', 'calendar events'],
    'add_page_from_template': ['page', 'pages'],
    'publish_article': ['article', 'articles'],
    'delete_article':  ['article', 'articles'],
    'delete_post':     ['post', 'posts'],
    'delete_lead':     ['lead', 'leads'],
    'publish_website': ['site', 'sites'],
  };
  var unit = unitMap[action] || ['task', 'tasks'];
  return count + ' ' + (count === 1 ? unit[0] : unit[1]);
}

function renderDrawerTasks(id,filter){
  // 2026-05-25 — counter strip reads from window._agentStatsData (populated
  // by loadAgentStats() from /api/agents/dashboard). Same data source as
  // the agent grid card → numbers always agree. Task LIST below still uses
  // allTasks because /api/tasks returns the recent 50 actual rows.
  window._drawerTaskFilter = filter;
  var stats = (window._agentStatsData && window._agentStatsData[id]) || null;

  var nUpcoming = stats ? stats.upcoming  : '—';
  var nOngoing  = stats ? stats.ongoing   : '—';
  var nBlocked  = stats ? stats.blocked   : '—';
  var nDone     = stats ? stats.completed : '—';
  var nFailed   = stats ? stats.failed    : '—';
  // 2026-05-25 — "blocked" is now its own pill. Was previously folded into
  // "upcoming" which over-counted real ready-to-run work. Blocked = waiting
  // on external (rate limit, dependency, approval) not the same as queued.
  var stripHtml = '<div style="display:flex;gap:8px;margin-bottom:12px;font-size:11px;flex-wrap:wrap">' +
    '<span style="padding:4px 10px;background:rgba(245,158,11,.10);color:var(--am);border-radius:6px"><b>' + nUpcoming + '</b> upcoming</span>' +
    '<span style="padding:4px 10px;background:rgba(59,139,245,.10);color:var(--bl);border-radius:6px"><b>' + nOngoing + '</b> ongoing</span>' +
    (typeof nBlocked === 'number' && nBlocked > 0 ? '<span style="padding:4px 10px;background:rgba(167,139,250,.10);color:var(--pu);border-radius:6px"><b>' + nBlocked + '</b> blocked</span>' : '') +
    '<span style="padding:4px 10px;background:rgba(0,229,168,.10);color:var(--ac);border-radius:6px"><b>' + nDone + '</b> completed</span>' +
    (typeof nFailed === 'number' && nFailed > 0 ? '<span style="padding:4px 10px;background:rgba(248,113,113,.10);color:var(--rd);border-radius:6px"><b>' + nFailed + '</b> issues</span>' : '') +
    '<span style="margin-left:auto;color:var(--t3);align-self:center">auto-refresh 5s</span>' +
  '</div>';

  // 2026-05-27 — Phase 2: per-category breakdown strip for this agent.
  // Counts NON-terminal tasks (allTasks recent 50) where agent appears in
  // assignees or assignee. Empty when agent has no active work in the window.
  stripHtml += _drawerCategoryStripHtml(id);

  // Task list still uses allTasks (recent 50 from /api/tasks) so we can
  // show actual task rows. Filter to this agent's tasks. The COUNTER above
  // is the source of truth; this list is "recent activity".
  function matchesAgent(t) {
    var as = t.assignees || [];
    return t.assignee === id || as.indexOf(id) !== -1;
  }

  var scoped = allTasks.filter(matchesAgent);

  // Apply the user-selected status filter on top of the scoped set.
  var tasks = scoped.filter(function(t){
    if (filter === 'all') return true;
    if (filter === 'ongoing') return t.status==='ongoing'||t.status==='in_progress';
    return t.status === filter;
  });

  var list=document.getElementById('dp-tasks-list');
  if(!tasks.length){
    list.innerHTML = stripHtml + '<div style="text-align:center;padding:30px;font-size:12px;color:var(--t3)">No ' + (filter==='all'?'':filter+' ') + 'tasks for this agent.</div>';
    return;
  }
  var ag=AGENTS[id]||{};

  // v1.4.4 (2026-05-30) — collapse tasks by (batch_id + action) so a single
  // Sarah-prompt that emitted 10 articles renders as one "10 articles" card
  // instead of 10 individual rows. Solo tasks (no batch_id) render
  // identically to before.
  var groups = _luGroupTasksByBatch(tasks);

  list.innerHTML = stripHtml + groups.map(function(g) {
    if (!g.is_batch) {
      // Solo task — render exactly as before
      var t = g.primary;
      var stClass = t.status==='ongoing'?'st-ongoing':t.status==='upcoming'?'st-upcoming':'st-completed';
      var displayTitle = t.title;
      if (viewingOrchestrator && t.assignee && t.assignee !== 'dmm') {
        var delegateName = (AGENTS[t.assignee] && AGENTS[t.assignee].name) || t.assignee;
        displayTitle = '→ ' + delegateName + ': ' + (t.title || '(no title)');
      }
      var timeInfo  = t.status==='completed'?('✓ '+(t.actual_time||t.estimated_time)+'min used'):t.status==='upcoming'?('Est. '+t.estimated_time+'min'):t.status==='ongoing'?(window.icon('clock',14)+' '+t.estimated_time+'min est'):'';
      var tokenInfo = t.status==='completed'?(((t.actual_tokens||t.estimated_tokens)/1000).toFixed(0)+'k tokens'):t.status==='upcoming'?('~'+(t.estimated_tokens/1000).toFixed(0)+'k tokens est'):('~'+(t.estimated_tokens/1000).toFixed(0)+'k tokens');
      var coord = t.coordinator ? AGENTS[t.coordinator] : null;
      return '<div class="task-item">' +
        '<div class="ti-title">' + esc(displayTitle) + '</div>' +
        '<div class="ti-desc">' + esc(t.description) + '</div>' +
        '<div class="ti-meta">' +
          '<span class="ti-badge ' + stClass + '">' + t.status + '</span>' +
          (coord ? '<span class="ti-badge" style="background:rgba(108,92,231,.08);color:var(--pu);border-color:rgba(108,92,231,.2)">↔ ' + (coord.emoji||'') + ' ' + (coord.name||t.coordinator) + '</span>' : '') +
          '<span class="ti-time">' + window.icon('clock',14) + ' ' + timeInfo + '</span>' +
          '<span class="ti-tokens">🪙 ' + tokenInfo + '</span>' +
        '</div>' +
        (t.notes||[]).map(function(n){ return '<div style="margin-top:8px;padding:7px 9px;background:var(--s3);border-radius:6px;font-size:10px;color:var(--t2)">' + window.icon('edit',14) + ' ' + esc(n.text||'') + '</div>'; }).join('') +
      '</div>';
    }

    // Batched group — collapsed card with expand-to-details
    var stClassG = g.rollup_status==='ongoing'?'st-ongoing':g.rollup_status==='upcoming'?'st-upcoming':'st-completed';
    var label = _luBatchUnit(g.action, g.count);
    var samples = g.sample_titles.slice(0, 3).map(function(t){ return '<li style="font-size:11px;color:var(--t3);margin:2px 0;list-style:none;padding-left:8px;border-left:1px solid var(--bd)">' + esc(t) + '</li>'; }).join('');
    if (g.sample_titles.length < g.count) samples += '<li style="font-size:11px;color:var(--t3);opacity:.65;margin:2px 0;list-style:none;padding-left:8px">…and ' + (g.count - g.sample_titles.length) + ' more</li>';
    var statusBits = [];
    if (g.status_counts.completed) statusBits.push(g.status_counts.completed + ' done');
    if (g.any_ongoing) statusBits.push(g.status_counts.ongoing || g.status_counts.in_progress || 0 ? ((g.status_counts.ongoing||0)+(g.status_counts.in_progress||0)) + ' running' : '');
    if (g.any_upcoming) statusBits.push(((g.status_counts.upcoming||0)+(g.status_counts.pending||0)+(g.status_counts.queued||0)) + ' queued');
    if (g.any_failed) statusBits.push(g.status_counts.failed + ' failed');
    var subStatus = statusBits.filter(Boolean).join(' · ');
    var detailsId = 'batch-details-' + g.batch_id.replace(/[^a-z0-9]/gi, '');
    return '<div class="task-item" data-batch-id="' + g.batch_id + '">' +
      '<div class="ti-title" style="display:flex;align-items:center;gap:8px"><span style="display:inline-block;background:var(--p);color:#fff;font-size:9px;font-weight:800;padding:2px 7px;border-radius:99px;letter-spacing:.05em">×' + g.count + '</span>' + esc(label) + '</div>' +
      (g.primary.description ? '<div class="ti-desc">From one Sarah prompt</div>' : '') +
      '<div class="ti-meta">' +
        '<span class="ti-badge ' + stClassG + '">' + g.rollup_status + '</span>' +
        '<span class="ti-time">' + window.icon('clock',14) + ' ~' + g.total_time + 'min total</span>' +
        '<span class="ti-tokens">🪙 ~' + Math.round(g.total_tokens/1000) + 'k tokens</span>' +
        (subStatus ? '<span style="font-size:10px;color:var(--t3);margin-left:8px">' + subStatus + '</span>' : '') +
      '</div>' +
      '<ul style="margin:8px 0 0;padding:0">' + samples + '</ul>' +
      '<button onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\';this.textContent=this.nextElementSibling.style.display===\'none\'?\'Show all '+g.count+'\':\'Hide details\';" style="margin-top:8px;background:transparent;border:1px solid var(--bd);color:var(--t3);font-size:10px;padding:4px 10px;border-radius:6px;cursor:pointer">Show all ' + g.count + '</button>' +
      '<div id="' + detailsId + '" style="display:none;margin-top:8px;padding:8px;background:var(--s1);border-radius:6px">' +
        g.tasks.map(function(t){
          var st = t.status==='ongoing'?'st-ongoing':t.status==='upcoming'?'st-upcoming':'st-completed';
          return '<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:11px;border-bottom:1px solid var(--bd)"><span style="flex:1;color:var(--t1)">' + esc(t.title || '(untitled)') + '</span><span class="ti-badge ' + st + '">' + t.status + '</span></div>';
        }).join('') +
      '</div>' +
    '</div>';
  }).join('');
}
function filterTasks(filter,btn){
  document.querySelectorAll('.tf-tab').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
  if(currentAgent) renderDrawerTasks(currentAgent,filter);
}
async function loadDrawerMessages(id){
  // Inject typing animation if not already done
  if(!document.getElementById('agent-chat-styles')){
    var s=document.createElement('style');s.id='agent-chat-styles';
    s.textContent='@keyframes typePulse{0%,100%{opacity:.3;transform:scale(.8)}50%{opacity:1;transform:scale(1.1)}}';
    document.head.appendChild(s);
  }
  try{
    var msgs=await get(API+'agents/'+id+'/messages');
    var feed=document.getElementById('msgs-feed-container');
    // 2026-06-15 — history load is the canonical baseline for the live event
    // poller's dedup. Reset the per-thread rendered-id set here so reopening a
    // drawer starts clean, then register every loaded row id below.
    window._agentChatRendered = window._agentChatRendered || {};
    window._agentChatRendered[id] = new Set();
    if(!msgs||!msgs.length){feed.innerHTML=`<div style="text-align:center;padding:24px;font-size:12px;color:var(--t3)">No messages yet. Send a direct message to ${AGENTS[id]?.name||id}.</div>`;return;}
    var ag=AGENTS[id]||{};
    feed.innerHTML=msgs.map(m=>{
      var isUser=m.from==='user'||m.from==='User';
      // v1.4.4 — historical agent messages now go through fmt() so the
      // markdown + paragraph spacing matches new replies. User messages
      // stay plain (they're typed plain text, not markdown).
      var body = isUser ? esc(m.content) : (typeof fmt === 'function' ? fmt(m.content) : esc(m.content));
      return `<div class="${isUser?'msg-from-user':'msg-from-agent'}" style="align-self:${isUser?'flex-end':'flex-start'}"><div style="font-size:9px;font-weight:700;color:${isUser?'var(--pu)':ag.color||'var(--t2)'};margin-bottom:3px">${isUser?'You':ag.name||id}</div>${body}<div class="msg-ts">${window._luParseTs(m.ts).toLocaleTimeString()}</div></div>`;
    }).join('');
    // 2026-06-15 — register every loaded agent_messages row id so the live
    // event poller (/api/agent/events) won't re-render history as "new".
    try{ msgs.forEach(function(m){ if(m && m.id!=null) window._agentChatRendered[id].add(String(m.id)); }); }catch(_e){}
    feed.scrollTop=feed.scrollHeight;
  }catch(e){console.error('loadMsgs:',e);}
}
async function sendAgentMessage(quickAction, overrideMessage){
  // 2026-05-22 FIX 10 — defensive: surface ANY failure in this function as a
  // visible toast + console log instead of dying silently. Earlier the user
  // reported "doesn't save my message, no response, just refreshes" — that
  // signature is consistent with an uncaught exception before the fetch.
  console.log('[sendAgentMessage] start', {currentAgent, quickAction, overrideMessage});
  if(!currentAgent){ console.warn('[sendAgentMessage] no currentAgent — aborting'); return; }
  var ta=document.getElementById('agent-msg-input');
  if(!ta && !overrideMessage && !quickAction){
    console.warn('[sendAgentMessage] no textarea AND no override/quickAction — aborting');
    return;
  }
  var content=overrideMessage||(!quickAction?(ta?ta.value.trim():''):'');
  if(!content&&!quickAction){ console.warn('[sendAgentMessage] empty content — aborting'); return; }
  if(ta){ta.value='';ta.style.height='auto';}
  try {  // 2026-05-22 FIX 10 outer try — catch DOM/state errors before fetch

  var ag=AGENTS[currentAgent]||{};
  var feed=document.getElementById('msgs-feed-container');

  // Show user bubble immediately (unless quick action)
  if(content){
    var userDiv=document.createElement('div');
    userDiv.className='msg-from-user';
    userDiv.style.alignSelf='flex-end';
    userDiv.innerHTML='<div style="font-size:9px;font-weight:700;color:var(--pu);margin-bottom:3px">You</div>'+esc(content)+'<div class="msg-ts">'+new Date().toLocaleTimeString()+'</div>';
    feed.appendChild(userDiv);
    feed.scrollTop=feed.scrollHeight;
  }

  // Show typing indicator
  var typingDiv=document.createElement('div');
  typingDiv.className='msg-from-agent';
  typingDiv.id='agent-typing-indicator';
  typingDiv.style.alignSelf='flex-start';
  typingDiv.innerHTML='<div style="font-size:9px;font-weight:700;color:'+(ag.color||'var(--t2)')+';margin-bottom:3px">'+(ag.name||currentAgent)+'</div><div style="display:flex;gap:4px;padding:8px 0"><div style="width:6px;height:6px;border-radius:50%;background:'+(ag.color||'var(--t2)')+';animation:typePulse .6s ease-in-out infinite"></div><div style="width:6px;height:6px;border-radius:50%;background:'+(ag.color||'var(--t2)')+';animation:typePulse .6s ease-in-out .15s infinite"></div><div style="width:6px;height:6px;border-radius:50%;background:'+(ag.color||'var(--t2)')+';animation:typePulse .6s ease-in-out .3s infinite"></div></div>';
  feed.appendChild(typingDiv);
  feed.scrollTop=feed.scrollHeight;

  try{
    var payload={from:'User',content:content||quickAction};
    if(quickAction) payload.quick_action=quickAction;
    // v1.4.4 attach — fold any uploaded attachments into the dispatch payload, then clear chips
    if (window.LU_attachComposer) {
      var _atts = window.LU_attachComposer.getPending('agent-msg-input');
      if (_atts && _atts.length) payload.attachments = _atts;
      window.LU_attachComposer.clear('agent-msg-input');
    }
    var d=await post(API+'agents/'+currentAgent+'/messages',payload);
    // Wave 31 — Update chat counter from response.
    try {
      if (d && d.chat_meter && typeof window._lgseUpdateChatMeter === 'function') {
        window._lgseUpdateChatMeter(d.chat_meter.counter, !!d.chat_meter.debited);
      }
    } catch (_e) {}

    // v1.4.4 (2026-05-30) — Two-phase response handling.
    // If the server returned {pending:true, ack, ack_message_id, ...}, we
    // got the instant acknowledgment first and the final reply is being
    // computed in the background. Render the ack now, swap the typing
    // indicator to a smaller "still working" footer, and poll the GET
    // /agents/{slug}/messages endpoint for the final row (id > ack_message_id
    // with phase='final').
    if (d && d.pending && d.ack) {
      // Remove the bouncing-dots typing indicator
      var ti0 = document.getElementById('agent-typing-indicator');
      if (ti0) ti0.remove();

      // ── SARAH888 PHASE 1A SLICE 1A.2 — BIND THE REPLY TO ITS QUESTION ──
      // The server now stamps every row with the originating user_message_id
      // and an execution_id (slice 1A.1). This turn records its own anchor and
      // renders into a dedicated container, so a reply that completes late
      // lands next to the question that produced it instead of at the bottom
      // of the feed under whatever was asked most recently. That bottom-append
      // behaviour, combined with the "newest row after ack_id" match below, is
      // what produced the systematic one-turn lag in the F1 stress test.
      var myUserMessageId = d.user_message_id || null;
      var myExecutionId   = d.execution_id || null;
      var turnAnchor = document.createElement('div');
      turnAnchor.className = 'agent-turn-anchor';
      turnAnchor.style.cssText = 'display:flex;flex-direction:column;gap:6px;align-self:stretch';
      if (myUserMessageId) turnAnchor.setAttribute('data-user-message-id', String(myUserMessageId));
      if (myExecutionId)   turnAnchor.setAttribute('data-execution-id', myExecutionId);
      feed.appendChild(turnAnchor);

      // Render the ack as Sarah's first bubble
      var ackDiv = document.createElement('div');
      ackDiv.className = 'msg-from-agent msg-from-agent-ack';
      ackDiv.style.alignSelf = 'flex-start';
      ackDiv.style.opacity = '0.92';
      ackDiv.innerHTML = '<div style="font-size:9px;font-weight:700;color:'+(ag.color||'var(--t2)')+';margin-bottom:3px">'+(d.agent_name||ag.name||currentAgent)+'</div>'+fmt(d.ack)+'<div class="msg-ts">'+new Date().toLocaleTimeString()+'</div>';
      turnAnchor.appendChild(ackDiv);

      // Add a small "still working" footer with continuous pulse
      var workDiv = document.createElement('div');
      // Per-turn id. This was a fixed 'agent-still-working', so with two turns
      // in flight the second turn's poll removed the FIRST turn's indicator and
      // left the first turn looking finished while it was still running.
      workDiv.id = 'agent-still-working-' + (myUserMessageId || ('t' + Date.now()));
      workDiv.style.cssText = 'align-self:flex-start;display:flex;gap:6px;align-items:center;padding:6px 12px;font-size:11px;color:var(--t3);opacity:.8';
      workDiv.innerHTML = '<div style="display:flex;gap:3px"><div style="width:5px;height:5px;border-radius:50%;background:'+(ag.color||'var(--t2)')+';animation:typePulse .9s ease-in-out infinite"></div><div style="width:5px;height:5px;border-radius:50%;background:'+(ag.color||'var(--t2)')+';animation:typePulse .9s ease-in-out .2s infinite"></div><div style="width:5px;height:5px;border-radius:50%;background:'+(ag.color||'var(--t2)')+';animation:typePulse .9s ease-in-out .4s infinite"></div></div><span>working…</span>';
      turnAnchor.appendChild(workDiv);
      feed.scrollTop = feed.scrollHeight;

      // Start polling for the final reply
      var ackId = d.ack_message_id || 0;
      // 2026-06-15 — register the ack row id so the always-on event poller
      // (/api/agent/events) dedups it and never double-renders this turn.
      try{ if(ackId && window._agentChatRendered && window._agentChatRendered[currentAgent]) window._agentChatRendered[currentAgent].add(String(ackId)); }catch(_e){}
      var pollIntervalMs = d.poll_interval_ms || 2500;
      var maxPolls = Math.ceil(90000 / pollIntervalMs); // 90s safety cap
      var polls = 0;
      var pollHandle = null;
      // Cancel polling on new send (set sentinel)
      window._agentChatActivePoll = window._agentChatActivePoll || {};
      var pollKey = currentAgent + ':' + ackId;
      window._agentChatActivePoll[pollKey] = true;

      var pollOnce = async function() {
        if (!window._agentChatActivePoll[pollKey]) {
          if (pollHandle) { clearTimeout(pollHandle); pollHandle = null; }
          return;
        }
        polls++;
        if (polls > maxPolls) {
          // Timeout fallback
          var w = document.getElementById(workDiv.id);
          if (w) w.innerHTML = '<span style="color:var(--am)">Took longer than expected — refresh to see the latest reply if it arrived.</span>';
          delete window._agentChatActivePoll[pollKey];
          return;
        }
        try {
          // Slice 1A.5 — poll forward only. This used to refetch the newest 100
          // rows (bodies included) every 2.5s per in-flight turn; measured, that
          // load was itself inflating chat latency. after_id returns only what
          // has appeared since this turn's ack — normally nothing, then one row.
          var msgs = await get(API+'agents/'+currentAgent+'/messages?after_id='+ackId);
          if (Array.isArray(msgs)) {
            for (var i = 0; i < msgs.length; i++) {
              var m = msgs[i];
              if (!m || !m.id) continue;
              var looksFinal = (m.phase === 'final' || (!m.is_ack && (m.role === 'agent' || m.from !== 'user' && m.from !== 'User')));
              if (!looksFinal) continue;
              // Slice 1A.2 — correlation is authoritative when present.
              // A row carrying an envelope belongs to exactly one turn, so a
              // reply can no longer be claimed by a turn that did not cause it.
              var hasEnvelope = (m.execution_id != null) || (m.user_message_id != null);
              var mine;
              if (hasEnvelope) {
                mine = (myExecutionId && m.execution_id === myExecutionId) ||
                       (myUserMessageId && m.user_message_id === myUserMessageId);
              } else {
                // Legacy row written before 1A.1 shipped, or by a producer that
                // does not stamp (e.g. the daily brief). Fall back to the old
                // ordering heuristic ONLY here, and only if this turn itself has
                // no anchor to bind with — otherwise an unstamped row could
                // still be mis-claimed, which is the bug we are removing.
                mine = !myExecutionId && !myUserMessageId && m.id > ackId;
              }
              if (mine) {
                delete window._agentChatActivePoll[pollKey];
                // 2026-06-15 — register the final row id so the event poller
                // dedups it (whichever channel renders first wins).
                try{ if(window._agentChatRendered && window._agentChatRendered[currentAgent]) window._agentChatRendered[currentAgent].add(String(m.id)); }catch(_e){}
                var w = document.getElementById(workDiv.id);
                if (w) w.remove();
                var finalDiv = document.createElement('div');
                finalDiv.className = 'msg-from-agent';
                finalDiv.style.alignSelf = 'flex-start';
                var finalColor = m.error ? 'var(--rd,#dc2626)' : (ag.color||'var(--t2)');
                // If this reply arrives after the user has already asked
                // something else, say so rather than letting it read as an
                // answer to the newest question.
                var isLate = (turnAnchor.parentNode && turnAnchor !== feed.lastElementChild);
                var lateTag = isLate ? ' · reply to your earlier message' : '';
                var finalHtml = '<div style="font-size:9px;font-weight:700;color:'+finalColor+';margin-bottom:3px">'+(ag.name||currentAgent)+(m.error?' · error':'')+lateTag+'</div>'+fmt(m.content||'')+'<div class="msg-ts">'+new Date().toLocaleTimeString()+'</div>';
                finalDiv.innerHTML = finalHtml;
                // Render into THIS turn's anchor, not the end of the feed.
                turnAnchor.appendChild(finalDiv);
                if (!isLate) feed.scrollTop = feed.scrollHeight;
                return;
              }
            }
          }
        } catch (pe) {
          console.warn('[sendAgentMessage] poll error', pe);
        }
        pollHandle = setTimeout(pollOnce, pollIntervalMs);
      };
      pollHandle = setTimeout(pollOnce, pollIntervalMs);
      return;
    }

    // Remove typing indicator
    var ti=document.getElementById('agent-typing-indicator');
    if(ti) ti.remove();

    // Legacy synchronous path — server returned the full reply in one go
    if(d.reply){
      var agDiv=document.createElement('div');
      agDiv.className='msg-from-agent';
      agDiv.style.alignSelf='flex-start';
      var replyHtml='<div style="font-size:9px;font-weight:700;color:'+(ag.color||'var(--t2)')+';margin-bottom:3px">'+(d.agent_name||ag.name||currentAgent)+'</div>'+fmt(d.reply)+'<div class="msg-ts">'+new Date().toLocaleTimeString()+'</div>';

      // Sarah redirect button
      if(d.requires_sarah&&currentAgent!=='dmm'){
        replyHtml+='<button onclick="openSarahFromRedirect(\''+esc(d.sarah_context||'')+'\')" style="margin-top:8px;background:var(--ps);border:1px solid var(--p);color:var(--pu);border-radius:var(--r);padding:6px 14px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px">'+window.icon('message',14)+' Chat with Sarah</button>';
      }
      agDiv.innerHTML=replyHtml;
      feed.appendChild(agDiv);
      // 2026-05-22 FIX 11 — was scrollIntoView({block:'start'}) which scrolled the
      // agent bubble to the TOP of the feed, visually wiping the rest of the
      // chat. User reported this as "chat refreshes after typing stops". Use
      // feed.scrollTop=feed.scrollHeight (same pattern as user bubble + typing
      // indicator above) to keep the conversation flow at the bottom.
      feed.scrollTop = feed.scrollHeight;
    }
  }catch(e){
    var ti2=document.getElementById('agent-typing-indicator');
    if(ti2) ti2.remove();
    /* 1A.2: per-turn ids — sweep by prefix instead of a single fixed id */
    try{ document.querySelectorAll('[id^="agent-still-working"]').forEach(function(_w){ _w.remove(); }); }catch(_e){}
    var ws2 = null;
    if(ws2) ws2.remove();
    console.error('[sendAgentMessage] POST failed', e);
    // CHAT-402: an out-of-credits refusal is a real, saved reply — render it as the agent's bubble
    // instead of a passing toast (it used to appear only after a refresh).
    if (e && e.status === 402 && e.body && e.body.reason === 'insufficient_credits') {
      try {
        var feed402 = document.getElementById('msgs-feed-container');
        var ag402 = (window.agentsMeta && window.agentsMeta[currentAgent]) || {};
        if (feed402) {
          var nd = document.createElement('div');
          nd.className = 'msg-from-agent msg-from-agent-notice';
          nd.style.alignSelf = 'flex-start';
          nd.innerHTML = '<div style="font-size:9px;font-weight:700;color:'+(ag402.color||'var(--t2)')+';margin-bottom:3px">'+_luEsc(ag402.name||currentAgent)+'</div>'+fmt(e.body.error||e.message)
            + '<div style="margin-top:8px"><button class="btn btn-primary btn-sm" onclick="if(window.nav)nav(\'billing\')">'+_luEsc(e.body.action_label||'Top up credits')+'</button></div>'
            + '<div class="msg-ts">'+new Date().toLocaleTimeString()+'</div>';
          feed402.appendChild(nd); feed402.scrollTop = feed402.scrollHeight;
        }
      } catch(_n) {}
    } else
    showToast('Error: '+e.message,'error');
  }
  } catch (outerErr) {
    // 2026-05-22 FIX 10 — catch any pre-fetch DOM/state error so the user
    // sees what went wrong instead of a silent failure.
    console.error('[sendAgentMessage] uncaught error', outerErr);
    showToast('Chat error: ' + (outerErr && outerErr.message ? outerErr.message : outerErr), 'error');
  }
}

// Open Sarah's drawer with pre-filled context from agent redirect
window.openSarahFromRedirect=function(context){
  closeAgentDrawer();
  setTimeout(function(){
    openAgentDrawer('dmm');
    drawerTab('messages');
    setTimeout(function(){
      var ta=document.getElementById('agent-msg-input');
      if(ta&&context) ta.value=context;
    },200);
  },300);
};
async function renderDocuments(id){
  var list=document.getElementById('dp-docs-list');
  list.innerHTML='<div style="text-align:center;padding:20px;color:var(--t3);font-size:12px">Loading documents...</div>';
  try{
    var docs=await get(API+'agents/'+id+'/documents');
    var items=docs.documents||docs||[];
    if(!items.length){
      list.innerHTML=`<div style="text-align:center;padding:30px;font-size:12px;color:var(--t3)">No documents yet for ${AGENTS[id]?.name||id}.</div>`;
      return;
    }
    var typeIcons={image:'🖼',video:'🎬',document:'📄',presentation:'📊',audio:'🎵'};
    list.innerHTML=items.map(d=>{
      var icon=typeIcons[d.type]||'📁';
      var date=d.created_at?window._luParseTs(d.created_at).toLocaleDateString():'';
      return `<div style="display:flex;align-items:center;gap:10px;padding:10px;background:var(--s2);border:1px solid var(--bd);border-radius:8px">
        <span style="font-size:20px">${icon}</span>
        <div style="flex:1;min-width:0">
          <div style="font-size:12px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(d.title)}</div>
          <div style="font-size:10px;color:var(--t3)">${d.type||'file'} · ${date}</div>
        </div>
        ${d.url?`<a href="${d.url}" target="_blank" style="font-size:10px;color:var(--ac);text-decoration:none;font-weight:600">Open ↗</a>`:''}
      </div>`;
    }).join('');
  }catch(e){
    list.innerHTML=`<div style="text-align:center;padding:30px;font-size:12px;color:var(--t3)">Documents produced by ${AGENTS[id]?.name||id} will appear here.</div>`;
  }
}
let _agentStatsFails = 0;
async function loadAgentStats(){
  // 2026-05-25 — REMOVED updateNodeCounts() call here.
  // 2026-05-26 — instrumented heavily so we can SEE what's failing.
  console.log('[loadAgentStats] FIRING. _agentStatsFails =', _agentStatsFails);
  if (_agentStatsFails >= 3) {
    console.warn('[loadAgentStats] BLOCKED — 3 prior failures. Reset via: _agentStatsFails=0;loadAgentStats()');
    return;
  }

  try {
    var d = await get(API + 'agents/dashboard');
    console.log('[loadAgentStats] response received. agents in payload:', (d && d.agents ? d.agents.length : 'MISSING'));
    if (d && d.agents && d.agents[0]) {
      console.log('[loadAgentStats] first agent sample:', d.agents[0]);
    }
    _agentStatsFails = 0;
    var agents = d.agents || [];

    // 2026-05-25 — publish per-agent stats globally so the drawer + workspace
    // command center can read the SAME source the agent grid card uses.
    // Source of truth: /api/agents/dashboard (correct Sarah orchestrator
    // logic + all-status counting + no 50-row /api/tasks cap).
    window._agentStatsData = window._agentStatsData || {};
    agents.forEach(a => {
      // Sarah uses 'dmm' as DOM id alias (frontend convention) while
      // the API returns 'sarah'. Map for DOM lookup.
      var uiId = a.agent_id === 'sarah' ? 'dmm' : a.agent_id;
      var ag = AGENTS[a.agent_id] || AGENTS[uiId] || {};
      window._agentStatsData[uiId] = {
        ongoing:   a.executing || 0,
        upcoming:  a.pending || 0,
        blocked:   a.blocked || 0,
        completed: a.completed || 0,
        failed:    a.failed || 0,
        success_rate: a.success_rate || 0,
      };
      var ongoing = document.getElementById('av-ongoing-' + uiId);
      var upcoming = document.getElementById('av-upcoming-' + uiId);
      var completed = document.getElementById('av-completed-' + uiId);
      if (ongoing)   ongoing.textContent   = a.executing || 0;
      if (upcoming)  upcoming.textContent  = a.pending || 0;
      if (completed) completed.textContent = a.completed || 0;
    });

    // 2026-05-25 — re-render the drawer counter strip + profile tiles with
    // fresh data if the drawer is open.
    if (window._agentDrawerOpen) {
      if (typeof renderDrawerTasks === 'function') {
        renderDrawerTasks(window._agentDrawerOpen, window._drawerTaskFilter || 'all');
      }
      var openAg = AGENTS[window._agentDrawerOpen] || {};
      if (typeof renderProfilePane === 'function') {
        renderProfilePane(window._agentDrawerOpen, openAg);
      }
    }

    // 2026-05-25 — also refresh the workspace command center if it's on
    // screen. Stash the last d.agents list (set by _cmdcRenderAll) so we
    // can re-render with the now-populated _agentStatsData.
    if (window._cmdcLastAgents && typeof _cmdcRenderAgents === 'function') {
      _cmdcRenderAgents(window._cmdcLastAgents);
    }

    // 2026-05-25 — refresh the workspace-tab agent cards (tc-* IDs).
    // updateNodeCounts now reads from _agentStatsData (we populated it
    // above) and writes both tc-* (workspace) and av-* (agents page).
    if (typeof updateNodeCounts === 'function') updateNodeCounts();

    // Build detailed task board in drawer (if open)
    if (window._agentDrawerOpen) _renderAgentTaskBoard(window._agentDrawerOpen, agents);

  } catch(e) {
    _agentStatsFails++;
    console.error('[loadAgentStats] FAIL #'+_agentStatsFails+':', e.message, e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : '');
  }
}

// 2026-05-26 — INITIAL WORKSPACE BOOTSTRAP.
// The workspace tab is the DEFAULT active view, but nav('workspace') only
// fires on user click — not on page load. Without this, _agentStatsData
// stays undefined, allTasks stays empty, drawCanvas paints 0 paths, cards
// show 0/0/0. User has to click another tab + back to trigger the data
// load. This bootstrap forces it on initial DOMContentLoaded.
window.luDefer = window.luDefer || function (fn) {
  var run = function () { try { fn(); } catch (e) { console.warn('[luDefer]', e); } };
  if (typeof window.requestIdleCallback === 'function') { window.requestIdleCallback(run, { timeout: 3000 }); }
  else { setTimeout(run, 1500); }
};
document.addEventListener('DOMContentLoaded', function () {
  function bootWorkspace() {
    console.log('[bootstrap] firing initial workspace data load + agent-node loader');
    try { document.dispatchEvent(new CustomEvent('lu:bootstrap-complete')); } catch (e) {}
    try { if (typeof window._loadWorkspaceAgents === 'function') window._loadWorkspaceAgents(); } catch (e) { console.error('[bootstrap] _loadWorkspaceAgents threw:', e); }
    window.luDefer(function () { try { if (typeof loadAgentStats === 'function') loadAgentStats(); } catch (e) { console.error('[bootstrap] loadAgentStats threw:', e); } });
    window.luDefer(function () { try { if (typeof loadTasks === 'function') loadTasks(); } catch (e) { console.error('[bootstrap] loadTasks threw:', e); } });
  }
  setTimeout(bootWorkspace, 400);

  // 2026-05-26 — scroll-to-content. The workspace view auto-scrolled to
  // (-624, -844) — content positioned at SVG coords (0-720, 0-300) lands
  // off-screen upper-left. Force scroll to top-left so default-positioned
  // agent nodes are immediately visible.
  function focusOnContent() {
    var vw = document.getElementById('view-workspace');
    if (!vw) return;
    // Find bounding rect of all agent-nodes; scroll to roughly their centroid
    var nodes = vw.querySelectorAll('.agent-node');
    if (!nodes.length) return;
    var minX = Infinity, minY = Infinity, maxX = 0, maxY = 0;
    nodes.forEach(function (n) {
      var x = parseInt(n.style.left || n.getAttribute('data-x') || '0', 10);
      var y = parseInt(n.style.top  || n.getAttribute('data-y') || '0', 10);
      if (x < minX) minX = x; if (y < minY) minY = y;
      if (x > maxX) maxX = x; if (y > maxY) maxY = y;
    });
    if (minX === Infinity) return;
    // Scroll so that minX/minY is near the top-left of viewport with margin
    var targetX = Math.max(0, minX - 80);
    var targetY = Math.max(0, minY - 80);
    console.log('[bootstrap] focusing canvas: agents span (' + minX + ',' + minY + ')-(' + maxX + ',' + maxY + '), scrolling to (' + targetX + ',' + targetY + ')');
    vw.scrollTo(targetX, targetY);
  }

  setTimeout(function () {
    if (!document.querySelector('.agent-node')) {
      console.warn('[bootstrap] no .agent-node at 2s — retrying _loadWorkspaceAgents');
      try { if (typeof window._loadWorkspaceAgents === 'function') window._loadWorkspaceAgents(); } catch (e) {}
    }
    if (!window._agentStatsData) {
      console.warn('[bootstrap] _agentStatsData still empty at 2s — retrying loadAgentStats');
      try { if (typeof loadAgentStats === 'function') loadAgentStats(); } catch (e) {}
    }
    // Center viewport on the content.
    focusOnContent();
  }, 2000);
  // Once more at 4s after everything settles
  setTimeout(focusOnContent, 4000);
});

// 2026-05-26 — VISUAL FORCE: outline agent nodes + recolor lines so they
// are IMPOSSIBLE to miss. Auto-fires once at +3s after page load.
window.lu_visual_force = function () {
  var vw = document.getElementById('view-workspace');
  if (!vw) return console.warn('[visual_force] no view-workspace');
  // Force scroll to where agents are positioned
  var nodes = vw.querySelectorAll('.agent-node');
  if (nodes.length) {
    var minX = Infinity, minY = Infinity;
    nodes.forEach(function (n) {
      var x = parseInt(n.style.left || '0', 10);
      var y = parseInt(n.style.top  || '0', 10);
      if (x < minX) minX = x; if (y < minY) minY = y;
    });
    if (minX !== Infinity) vw.scrollTo(Math.max(0, minX - 100), Math.max(0, minY - 100));
  }
  // Outline every agent node in lime
  nodes.forEach(function (n) {
    n.style.outline = '6px solid #00FF00';
    n.style.outlineOffset = '2px';
    n.style.zIndex = '999';
  });
  // Recolor every SVG path to bright magenta + thick stroke
  document.querySelectorAll('.canvas-svg path').forEach(function (p) {
    p.setAttribute('stroke', '#FF00FF');
    p.setAttribute('stroke-width', '10');
    p.setAttribute('opacity', '1');
  });
  console.log('[visual_force] outlined', nodes.length, 'nodes (lime) + recolored paths (magenta)');
};
setTimeout(function(){ try { window.lu_visual_force(); } catch(e){} }, 3000);

// 2026-05-26 — Self-diagnostic. Call lu_diagnose() from console to dump
// everything that could be wrong with the workspace render.
window.lu_diagnose = function () {
  console.group('━━━━━━━ LU WORKSPACE DIAGNOSTIC ━━━━━━━');
  console.log('Build version:', (window.LU_CFG && window.LU_CFG.version) || 'unknown');
  console.log('Current view:', currentView);
  var vw = document.getElementById('view-workspace');
  console.log('view-workspace exists:', !!vw, 'display:', vw && getComputedStyle(vw).display, 'active class:', vw && vw.classList.contains('active'));
  var svg = document.getElementById('canvas-svg');
  console.log('canvas-svg exists:', !!svg, 'children:', svg ? svg.children.length : 'N/A');
  if (svg) {
    var rect = svg.getBoundingClientRect();
    console.log('canvas-svg bounding rect:', { x: rect.x, y: rect.y, w: rect.width, h: rect.height });
    console.log('canvas-svg visibility:', getComputedStyle(svg).visibility, 'opacity:', getComputedStyle(svg).opacity);
    Array.from(svg.children).forEach(function (el, i) {
      if (i < 5) {
        var r = el.getBoundingClientRect();
        console.log('  svg child', i, el.tagName, 'class=' + el.getAttribute('class'), 'stroke=' + el.getAttribute('stroke'), 'rect=', r);
      }
    });
  }
  var nodes = document.querySelectorAll('.agent-node');
  console.log('.agent-node count:', nodes.length);
  Array.from(nodes).slice(0, 6).forEach(function (n) {
    console.log('  node id=' + n.id + ' data-agent=' + n.dataset.agent + ' visible=' + (getComputedStyle(n).display !== 'none'));
  });
  console.log('window._agentStatsData:', window._agentStatsData);
  console.log('window.nodePos:', window.nodePos);
  console.log('window.nodePos_dyn:', window.nodePos_dyn);
  console.log('allTasks.length:', (typeof allTasks !== 'undefined' && allTasks) ? allTasks.length : 'undefined');
  if (typeof allTasks !== 'undefined' && allTasks && allTasks.length) {
    console.log('first allTask sample:', allTasks[0]);
  }
  console.log('AGENTS keys:', Object.keys(window.AGENTS || {}));
  console.log('_agentStatsFails:', _agentStatsFails);
  // Sample the workspace card numbers
  ['dmm','priya','james','elena','alex'].forEach(function (a) {
    var u = document.getElementById('tc-upcoming-' + a);
    var o = document.getElementById('tc-ongoing-' + a);
    var c = document.getElementById('tc-completed-' + a);
    console.log('  card', a, 'upcoming=' + (u ? u.textContent : 'NO DOM'), 'ongoing=' + (o ? o.textContent : 'NO DOM'), 'completed=' + (c ? c.textContent : 'NO DOM'));
  });
  console.groupEnd();
};
// Auto-run diagnostic 2s after page load so user always has a fresh snapshot
setTimeout(function(){ try { window.lu_diagnose(); } catch(e){} }, 2000);

function _renderAgentTaskBoard(agentId, allAgents) {
  // Wave 38g — Sarah's drawer uses 'dmm' but API uses 'sarah'. Normalize.
  var apiId = agentId === 'dmm' ? 'sarah' : agentId;
  var data = allAgents.find(a => a.agent_id === apiId || a.agent_id === agentId);
  if (!data) return;
  var ag = AGENTS[agentId] || {};
  var board = document.getElementById('agent-task-board');
  if (!board) return;

  var statusIcons = {pending:''+window.icon("info",14)+'',queued:'🟠',acknowledged:''+window.icon("info",14)+'',executing:''+window.icon("info",14)+'',in_progress:''+window.icon("info",14)+'',completed:''+window.icon("info",14)+'',failed:''+window.icon("close",14)+'',cancelled:''+window.icon("info",14)+''};

  let html = `<div style="margin-bottom:16px">
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">
      <div style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 14px;text-align:center;min-width:70px">
        <div style="font-size:20px;font-weight:700;color:var(--am)">${data.pending}</div><div style="font-size:9px;color:var(--t3);text-transform:uppercase">Pending</div>
      </div>
      <div style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 14px;text-align:center;min-width:70px">
        <div style="font-size:20px;font-weight:700;color:var(--bl)">${data.executing}</div><div style="font-size:9px;color:var(--t3);text-transform:uppercase">Executing</div>
      </div>
      <div style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 14px;text-align:center;min-width:70px">
        <div style="font-size:20px;font-weight:700;color:var(--ac)">${data.completed}</div><div style="font-size:9px;color:var(--t3);text-transform:uppercase">Completed</div>
      </div>
      <div style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 14px;text-align:center;min-width:70px">
        <div style="font-size:20px;font-weight:700;color:var(--rd)">${data.failed}</div><div style="font-size:9px;color:var(--t3);text-transform:uppercase">Failed</div>
      </div>
    </div>
  </div>`;

  // Recent tasks
  // v1.4.4 (2026-05-30) — collapse by batch_id (same helper as the drawer
  // task list). Solo tasks render identically to before.
  if (data.recent_tasks.length) {
    html += '<div style="font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Recent Tasks</div>';
    html += '<div style="display:flex;flex-direction:column;gap:6px;margin-bottom:16px">';
    var rtGroups = _luGroupTasksByBatch(data.recent_tasks);
    rtGroups.forEach(function(g) {
      var t = g.primary;
      var icon = statusIcons[t.status] || '⚪';
      if (!g.is_batch) {
        var tools = t.tools ? (typeof t.tools === 'string' ? JSON.parse(t.tools) : t.tools) : [];
        var creator = t.created_by ? (AGENTS[t.created_by]?.name || t.created_by) : 'Sarah';
        var timeline = [
          t.created_at ? (window.icon('more',14) + ' ' + window._luParseTs(t.created_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})) : '',
          t.acknowledged_at ? (window.icon('info',14) + ' ' + new Date(t.acknowledged_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})) : '',
          t.started_at ? (window.icon('edit',14) + ' ' + window._luParseTs(t.started_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})) : '',
          t.completed_at ? (window.icon('check',14) + ' ' + window._luParseTs(t.completed_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})) : '',
        ].filter(Boolean).join(' → ');
        html += '<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;background:var(--s2);border:1px solid var(--bd);border-radius:8px">' +
          '<span style="font-size:14px;margin-top:2px">' + icon + '</span>' +
          '<div style="flex:1">' +
            '<div style="font-size:12px;font-weight:600;color:var(--t1)">' + esc(t.title||'') + '</div>' +
            '<div style="font-size:10px;color:var(--t3)">Created by ' + creator + ' · ' + t.status + (tools.length?' · '+tools.join(', '):'') + (t.duration_ms?' · '+t.duration_ms+'ms':'') + '</div>' +
            (timeline?'<div style="font-size:9px;color:var(--t3);margin-top:2px">'+timeline+'</div>':'') +
          '</div>' +
          '<div style="font-size:10px;color:var(--t3)">' + (t.created_at?window._luParseTs(t.created_at).toLocaleDateString():'') + '</div>' +
        '</div>';
      } else {
        // Batched card
        var label = _luBatchUnit(g.action, g.count);
        var samples = g.sample_titles.slice(0, 3).map(function(s){ return '<li style="font-size:10px;color:var(--t3);margin:1px 0;list-style:none;padding-left:6px;border-left:1px solid var(--bd)">' + esc(s) + '</li>'; }).join('');
        if (g.sample_titles.length < g.count) samples += '<li style="font-size:10px;color:var(--t3);opacity:.65;margin:1px 0;list-style:none;padding-left:6px">…and ' + (g.count - g.sample_titles.length) + ' more</li>';
        var subStatus = [];
        if (g.status_counts.completed) subStatus.push(g.status_counts.completed + ' done');
        if (g.any_ongoing) subStatus.push(((g.status_counts.ongoing||0)+(g.status_counts.in_progress||0)) + ' running');
        if (g.any_upcoming) subStatus.push(((g.status_counts.upcoming||0)+(g.status_counts.pending||0)+(g.status_counts.queued||0)) + ' queued');
        if (g.any_failed) subStatus.push(g.status_counts.failed + ' failed');
        var rollupIcon = statusIcons[g.rollup_status] || '⚪';
        html += '<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;background:var(--s2);border:1px solid var(--bd);border-radius:8px" data-batch-id="' + g.batch_id + '">' +
          '<span style="font-size:14px;margin-top:2px">' + rollupIcon + '</span>' +
          '<div style="flex:1">' +
            '<div style="font-size:12px;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:6px"><span style="display:inline-block;background:var(--p);color:#fff;font-size:9px;font-weight:800;padding:2px 7px;border-radius:99px">×' + g.count + '</span>' + esc(label) + '</div>' +
            '<div style="font-size:10px;color:var(--t3)">From one Sarah prompt · ' + subStatus.join(' · ') + '</div>' +
            '<ul style="margin:4px 0 0;padding:0">' + samples + '</ul>' +
          '</div>' +
          '<div style="font-size:10px;color:var(--t3)">' + (t.created_at?window._luParseTs(t.created_at).toLocaleDateString():'') + '</div>' +
        '</div>';
      }
    });
    html += '</div>';
  }

  // Recent executions
  if (data.recent_exec.length) {
    html += '<div style="font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Recent Executions</div>';
    html += '<div style="display:flex;flex-direction:column;gap:6px">';
    data.recent_exec.forEach(e => {
      html += `<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:var(--s1);border-radius:6px;font-size:11px">
        <span>${parseInt(e.success)?''+window.icon("check",14)+'':''+window.icon("close",14)+''}</span>
        <span style="font-weight:600;color:var(--t1)">${esc(LU_humanize(e.tool_id))}</span>
        <span style="flex:1;color:var(--t3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc((e.result_summary||'').slice(0,60))}</span>
        <span style="color:var(--t3)">${e.duration_ms||0}ms</span>
      </div>`;
    });
    html += '</div>';
  }

  if (!data.recent_tasks.length && !data.recent_exec.length) {
    html += '<div style="text-align:center;padding:24px;color:var(--t3);font-size:12px">No tasks or executions yet for this agent.</div>';
  }

  board.innerHTML = html;
}

// ── Projects ────────────────────────────────────────────────────────────────
let allProjectTasks = [];
let projectViewMode = 'kanban';
let dragTaskId = null;
let dragFromStatus = null;
let activeTaskDrawer = null;

var STATUS_COLORS = {
    backlog:'var(--t3)', planned:'var(--am)', in_progress:'var(--ac)',
    review:'var(--pu)', completed:'var(--bl)',
};
var STATUS_LABELS = {
    backlog:'Backlog', planned:'Planned', in_progress:'In Progress',
    review:'Review', completed:'Completed',
};

async function loadProjects() {
    try {
        var r = await get(API + 'projects/tasks');
        allProjectTasks = r.tasks || [];
        renderKanban();
        if (projectViewMode === 'timeline') renderTimeline();

        var empty = document.getElementById('proj-empty');
        var kanban = document.getElementById('proj-kanban');
        if (allProjectTasks.length === 0) {
            if (empty) empty.style.display = 'flex';
            if (kanban) kanban.style.display = 'none';
        } else {
            if (empty) empty.style.display = 'none';
            if (kanban && projectViewMode === 'kanban') kanban.style.display = 'flex';
        }
    } catch(e) { console.error('loadProjects:', e); }
}

function setProjectView(mode, btn) {
    projectViewMode = mode;
    document.querySelectorAll('.pvt-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('proj-kanban').style.display  = mode === 'kanban'   ? 'flex' : 'none';
    document.getElementById('proj-timeline').style.display = mode === 'timeline' ? 'block' : 'none';
    if (mode === 'timeline') renderTimeline();
}

function renderKanban() {
    var cols = ['backlog','planned','in_progress','review','completed'];
    cols.forEach(status => {
        var cards = document.getElementById('kbc-cards-' + status);
        var empty = document.getElementById('kbc-empty-' + status);
        var count = document.getElementById('kbc-count-' + status);
        var tasks = allProjectTasks.filter(t => t.status === status);
        if (count) count.textContent = tasks.length;
        if (!cards) return;
        // Clear existing cards
        cards.querySelectorAll('.kb-card').forEach(c => c.remove());
        if (empty) empty.style.display = tasks.length ? 'none' : 'block';
        tasks.forEach(task => cards.appendChild(makeKbCard(task)));
    });
}

function makeKbCard(task) {
    var a   = AGENTS[task.assignee] || { emoji:'👤', color:'var(--t2)', name: task.assignee };
    var div = document.createElement('div');
    div.className = 'kb-card';
    div.id = 'kbc-' + task.id;
    div.draggable = true;
    div.dataset.taskId = task.id;
    div.dataset.status = task.status;
    div.innerHTML = `
        <div class="kb-card-title">${esc(task.title)}</div>
        ${task.success_metric ? `<div style="font-size:9px;color:var(--ac);margin-bottom:6px;line-height:1.4">${window.icon('more',14)} ${esc(task.success_metric)}</div>` : ''}
        <div class="kb-card-footer">
            <div class="kb-card-agent" style="background:transparent;border:none" title="${esc(a.name)}">${typeof buildAgentOrb==="function"?buildAgentOrb(task.assignee,"sm","idle"):a.emoji}</div>
            <span class="kb-card-priority ${task.priority||'medium'}">${task.priority||'medium'}</span>
            <span class="kb-card-time">${task.estimated_time||60}m</span>
        </div>`;
    div.addEventListener('dragstart', e => kbDragStart(e, task.id, task.status));
    div.addEventListener('dragend',   e => div.classList.remove('dragging'));
    div.addEventListener('click',     () => openTaskDrawer(task.id));
    return div;
}

// ── Drag & Drop ────────────────────────────────────────────────────────────
function kbDragStart(e, taskId, status) {
    dragTaskId = taskId; dragFromStatus = status;
    setTimeout(() => document.getElementById('kbc-' + taskId)?.classList.add('dragging'), 0);
    e.dataTransfer.effectAllowed = 'move';
}
function kbDragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
}
async function kbDrop(e, newStatus) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    document.querySelectorAll('.kb-col').forEach(c => c.classList.remove('drag-over'));
    if (!dragTaskId || dragFromStatus === newStatus) return;

    var taskId = dragTaskId; var fromStatus = dragFromStatus;
    dragTaskId = null; dragFromStatus = null;

    // Optimistic update
    var task = allProjectTasks.find(t => t.id === taskId);
    if (!task) return;
    task.status = newStatus;
    renderKanban();

    try {
        await put(API + 'tasks/' + taskId + '/status', { status: newStatus });
        // If moved to in_progress — agent starts working (handled server-side)
    } catch(err) {
        // Animate bounce-back
        task.status = fromStatus;
        renderKanban();
        var card = document.getElementById('kbc-' + taskId);
        if (card) card.classList.add('bounce-back');
        setTimeout(() => card?.classList.remove('bounce-back'), 500);
        console.error('Status update failed:', err);
    }
}

// ── Timeline ───────────────────────────────────────────────────────────────
function renderTimeline() {
    var header = document.getElementById('tl-header');
    var body   = document.getElementById('tl-body');
    if (!header || !body) return;

    var today = new Date(); today.setHours(0,0,0,0);
    var days = 30;
    // Header days
    header.innerHTML = '';
    for (let i = 0; i < days; i++) {
        var d = new Date(today); d.setDate(d.getDate() + i);
        var el = document.createElement('div');
        el.className = 'tl-day' + (i === 0 ? ' today' : '');
        el.textContent = d.getDate() + '/' + (d.getMonth()+1);
        el.style.minWidth = '28px'; el.style.flex = '1';
        header.appendChild(el);
    }

    // Group by assignee
    var byAgent = {};
    allProjectTasks.filter(t => t.status !== 'completed' && t.status !== 'backlog').forEach(t => {
        if (!byAgent[t.assignee]) byAgent[t.assignee] = [];
        byAgent[t.assignee].push(t);
    });

    body.innerHTML = '';
    if (!Object.keys(byAgent).length) {
        body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--t3);font-size:12px">No active tasks to display</div>';
        return;
    }

    var colW = Math.max(28, (body.clientWidth - 120) / days);
    Object.entries(byAgent).forEach(([agentId, tasks]) => {
        var a = AGENTS[agentId] || { emoji:'👤', name: agentId, color:'var(--t2)' };
        var row = document.createElement('div');
        row.className = 'tl-row';
        var lbl = document.createElement('div');
        lbl.className = 'tl-row-lbl';
        lbl.innerHTML = `${window.luAvatar?luAvatar(a.name,'xs'):''}<span>${esc(a.name)}</span>`;
        row.appendChild(lbl);
        var grid = document.createElement('div');
        grid.className = 'tl-grid';
        grid.style.cssText = `flex:1;position:relative;height:32px`;

        tasks.forEach((task, idx) => {
            var startOffset = idx * 2; // stagger tasks
            var dur = Math.max(1, Math.ceil((task.estimated_time || 60) / (60 * 8))); // days
            var bar = document.createElement('div');
            bar.className = 'tl-bar';
            bar.style.cssText = `left:${startOffset * colW}px;width:${dur * colW - 2}px;background:${a.color};top:${6 + (idx % 2) * 0}px`;
            bar.textContent = task.title;
            bar.title = task.title;
            bar.onclick = () => openTaskDrawer(task.id);
            grid.appendChild(bar);
        });

        row.appendChild(grid);
        body.appendChild(row);
    });
}

// ── Task Drawer ───────────────────────────────────────────────────────────
async function openTaskDrawer(taskId) {
    activeTaskDrawer = taskId;
    var drawer = document.getElementById('task-drawer');
    drawer.style.display = 'flex';

    // Load fresh from runtime
    try {
        var task = await get(API + 'projects/tasks/' + taskId);
        renderTaskDrawer(task);
    } catch(e) {
        // Fallback to local
        var task = allProjectTasks.find(t => t.id === taskId);
        if (task) renderTaskDrawer(task);
    }
}

function renderTaskDrawer(task) {
    var a = AGENTS[task.assignee] || { emoji:'👤', name: task.assignee, color:'var(--t2)', title:'' };
    (function(){ var e=document.getElementById('td-agent-em'); if(e) e.innerHTML = window.luAvatar ? luAvatar(a.name,'sm') : ''; })();
    setEl('td-title', task.title);
    document.getElementById('td-meta').innerHTML = `
        <span class="msg-badge" style="background:${STATUS_COLORS[task.status]}22;color:${STATUS_COLORS[task.status]};border:1px solid ${STATUS_COLORS[task.status]}44">${STATUS_LABELS[task.status]||task.status}</span>
        <span class="kb-card-priority ${task.priority||'medium'}">${task.priority||'medium'}</span>
        <span style="font-size:10px;color:var(--t3)">${task.estimated_time||60}m est</span>`;
    setEl('td-desc', task.description || '—');
    setEl('td-metric', task.success_metric || '—');
    setEl('td-assignee', `${a.name} — ${a.title}`);

    var coordWrap = document.getElementById('td-coord-wrap');
    if (task.coordinator && AGENTS[task.coordinator]) {
        var c = AGENTS[task.coordinator];
        setEl('td-coord', `${c.name} — ${c.title}`);
        coordWrap.style.display = 'block';
    } else { coordWrap.style.display = 'none'; }

    var mtgEl = document.getElementById('td-meeting');
    if (task.meeting_id) {
        mtgEl.innerHTML = `<span style="color:var(--ac);cursor:pointer" onclick="nav('reports')">${task.meeting_id}</span>`;
    } else { mtgEl.textContent = '—'; }

    // Deliverable
    // v1.4.4 (2026-05-30) — summary is server-built HTML (DeliverableSummary
    // uses htmlspecialchars on every dynamic value) so innerHTML is safe and
    // necessary — was being escaped as text by setEl(textContent).
    // The raw JSON envelope used to render unconditionally below the summary
    // and leaked engine/action slugs to end users; it's now collapsed behind
    // a "Show raw data" toggle for power users.
    var delEmpty   = document.getElementById('td-deliverable-empty');
    var delContent = document.getElementById('td-deliverable-content');
    if (task.deliverable) {
        delEmpty.style.display = 'none'; delContent.style.display = 'block';
        var sumEl = document.getElementById('td-del-summary');
        if (sumEl) sumEl.innerHTML = task.deliverable.summary || '';

        var json = task.deliverable.deliverable || task.deliverable;
        var jsonEl = document.getElementById('td-del-json');
        if (jsonEl) {
            jsonEl.style.display = 'none';
            jsonEl.textContent = JSON.stringify(json, null, 2);
            var toggleId = 'td-del-json-toggle';
            var prevToggle = document.getElementById(toggleId);
            if (prevToggle) prevToggle.remove();
            var toggle = document.createElement('button');
            toggle.id = toggleId;
            toggle.textContent = 'Show raw data';
            toggle.style.cssText = 'margin-top:10px;background:transparent;border:1px solid var(--bd);color:var(--t3);border-radius:6px;padding:6px 12px;font-size:11px;cursor:pointer';
            toggle.onclick = function() {
                if (jsonEl.style.display === 'none') {
                    jsonEl.style.display = 'block';
                    toggle.textContent = 'Hide raw data';
                } else {
                    jsonEl.style.display = 'none';
                    toggle.textContent = 'Show raw data';
                }
            };
            jsonEl.parentNode.insertBefore(toggle, jsonEl);
        }
    } else {
        delEmpty.style.display = 'block'; delContent.style.display = 'none';
    }

    // Notes
    var notesFeed = document.getElementById('td-notes-feed');
    notesFeed.innerHTML = '';
    (task.notes || []).forEach(n => {
        var div = document.createElement('div');
        div.className = `td-note-item type-${n.type||'user'}`;
        div.innerHTML = `<span class="tni-author">${esc(n.author_name||n.author)}</span>${esc(n.content)}<span class="tni-time" style="display:block;margin-top:3px">${n.at ? new Date(n.at).toLocaleString() : ''}</span>`;
        notesFeed.appendChild(div);
    });

    // History
    var histFeed = document.getElementById('td-history-feed');
    histFeed.innerHTML = '';
    (task.history || []).forEach(h => {
        var div = document.createElement('div');
        div.className = 'td-hist-item';
        div.innerHTML = `<div class="td-hist-dot" style="background:${STATUS_COLORS[h.status]||'var(--t3)'}"></div><div><div style="font-size:11px;color:var(--t1)">${STATUS_LABELS[h.status]||h.status}${h.from?` <span style="color:var(--t3)">from ${STATUS_LABELS[h.from]||h.from}</span>`:''}</div><div style="font-size:9px;color:var(--t3)">${h.at ? new Date(h.at).toLocaleString() : ''} · ${h.by||'system'}${h.note?` — ${esc(h.note)}`:''}</div></div>`;
        histFeed.appendChild(div);
    });

    // 2026-05-27 Phase 3 — Web Activity tab for category=research tasks.
    // Shows every URL the agent fetched / queries it searched during execution.
    var waTabBtn = document.getElementById('td-tab-webactivity');
    var waPane   = document.getElementById('tdp-webactivity');
    var isResearch = (task.category === 'research') || (task.task && task.task.category === 'research');
    var webRows = Array.isArray(task.web_activity) ? task.web_activity : [];
    if (waTabBtn) waTabBtn.style.display = isResearch ? 'inline-block' : 'none';
    if (waPane) {
        if (!isResearch) {
            waPane.innerHTML = '';
        } else if (!webRows.length) {
            waPane.innerHTML = '<div style="padding:32px;text-align:center;color:var(--t3);font-size:12px"><div style="font-size:28px;margin-bottom:8px">&#127760;</div>Agent did not access the web during this research task.</div>';
        } else {
            waPane.innerHTML = webRows.map(function (r) {
                var icon = r.action === 'search' ? '&#128269;' : '&#127760;';
                var col  = r.status === 'ok' ? 'var(--ac)' : (r.status === 'blocked' || r.status === 'capped' ? 'var(--am)' : 'var(--rd)');
                var t = esc(r.title || '');
                var u = esc(r.url_or_query || '');
                var dur = r.duration_ms ? (r.duration_ms + 'ms') : '';
                var bytes = r.content_length ? (Math.round(r.content_length / 1024) + 'KB') : '';
                var meta = [dur, bytes, (r.cost_credits + 'c'), (r.agent_slug ? '@' + esc(r.agent_slug) : '')].filter(Boolean).join(' · ');
                var hrefMaybe = r.action === 'fetch' && /^https?:\/\//.test(r.url_or_query || '')
                    ? '<a href="' + u + '" target="_blank" rel="noopener" style="color:var(--bl);text-decoration:none">' + u + ' &#8599;</a>'
                    : u;
                return '<div style="padding:10px 12px;border-bottom:1px solid var(--bd);display:flex;gap:10px;align-items:flex-start;font-size:12px">'
                    + '<div style="font-size:16px">' + icon + '</div>'
                    + '<div style="flex:1;min-width:0">'
                    + '  <div style="color:var(--t1);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + (t || u) + '</div>'
                    + '  <div style="color:var(--t3);font-size:10px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + hrefMaybe + '</div>'
                    + '  <div style="color:var(--t3);font-size:10px;margin-top:3px">' + esc(meta) + '</div>'
                    + (r.error ? '  <div style="color:var(--rd);font-size:10px;margin-top:3px">' + esc(r.error) + '</div>' : '')
                    + '</div>'
                    + '<span style="font-size:10px;color:' + col + ';text-transform:uppercase;font-weight:700;flex-shrink:0">' + esc(r.status || '') + '</span>'
                    + '</div>';
            }).join('');
        }
    }

    tdTab('details', document.querySelector('.td-tab'));
}

function closeTaskDrawer() {
    document.getElementById('task-drawer').style.display = 'none';
    activeTaskDrawer = null;
    loadProjects(); // refresh kanban after drawer closes
}

function tdTab(pane, btn) {
    document.querySelectorAll('.td-tab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    document.querySelectorAll('.td-pane').forEach(p => { p.style.display='none'; p.classList.remove('active'); });
    var el = document.getElementById('tdp-' + pane);
    if (el) { el.style.display = 'block'; el.classList.add('active'); }
}

async function submitTaskNote() {
    if (!activeTaskDrawer) return;
    var ta = document.getElementById('td-note-ta');
    var content = ta.value.trim();
    if (!content) return;
    ta.value = '';
    try {
        await post(API + 'projects/tasks/' + activeTaskDrawer + '/note', { content });
        openTaskDrawer(activeTaskDrawer); // refresh drawer
    } catch(e) { console.error('note failed:', e); }
}

// Helper: PUT request
async function put(url, data) {
    var r = await fetch(url, { method:'PUT', headers:_lgscAuthForFetch({'Content-Type':'application/json'}), body:JSON.stringify(data) });
    if (!r.ok) throw new Error('PUT failed: ' + r.status);
    return r.json();
}

// ── Reports ────────────────────────────────────────────────────────────────
let rptTab='meetings';
async function loadReports(){
  var grid=document.getElementById('rv-grid');
  grid.innerHTML=`
    <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;border-bottom:1px solid var(--bd);padding-bottom:1px">
      <button class="tab ${rptTab==='meetings'?'active':''}" onclick="rptTab='meetings';loadReports()" style="display:inline-flex;align-items:center;gap:6px">${window.icon('more',14)} Meetings</button>
      ${window._luIsAdmin?'<button class="tab ${rptTab===\'executions\'?\'active\':\'\'}" onclick="rptTab=\'executions\';loadReports()">'+window.icon("ai",14)+' Executions</button>':''}
      ${window._luIsAdmin?'<button class="tab ${rptTab===\'decisions\'?\'active\':\'\'}" onclick="rptTab=\'decisions\';loadReports()">'+window.icon("tag",14)+' Decisions</button>':''}
      <button class="tab ${rptTab==='tasks'?'active':''}" onclick="rptTab='tasks';loadReports()" style="display:inline-flex;align-items:center;gap:6px">${window.icon('more',14)} Tasks</button>
      <button class="tab ${rptTab==='categories'?'active':''}" onclick="rptTab='categories';loadReports()" style="display:inline-flex;align-items:center;gap:6px">${window.icon('tag',14)} Categories</button>
    </div>
    <div id="rpt-content" style="min-height:200px"><div style="text-align:center;padding:40px;color:var(--t3)">Loading…</div></div>
  `;
  var box=document.getElementById('rpt-content');
  try{
    if(rptTab==='meetings'){
      var h=await get(API+'history');
      if(!h||!h.length){box.innerHTML='<div class="rv-empty"><div class="rv-empty-icon" style="color:var(--t3)">'+window.icon('more',32)+'</div><div class="rv-empty-text">No meeting history yet.</div></div>';return;}
      box.innerHTML='<div class="rv-cards-grid">'+h.map(m=>`<div class="rv-card" onclick="showSummary('${esc(m.id)}','${esc(m.topic)}','${esc(m.date)}','${encodeURIComponent(m.summary||'')}')"><div class="rv-card-date">${new Date(m.date).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'})}</div><div class="rv-card-topic">${esc(m.topic)}</div><div class="rv-card-preview">${esc((m.summary||'').slice(0,200))}</div></div>`).join('')+'</div>';
    }
    else if(rptTab==='executions'){
      var d=await get(API+'exec/history?limit=50');
      var rows=d.history||[];
      if(!rows.length){box.innerHTML='<div class="rv-empty"><div class="rv-empty-icon">'+window.icon("ai",14)+'</div><div class="rv-empty-text">No executions recorded yet.</div></div>';return;}
      box.innerHTML='<div style="display:flex;flex-direction:column;gap:6px">'+rows.map(r=>{
        var ok=parseInt(r.success);var dt=window._luParseTs(r.created_at);
        return `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--s1);border:1px solid var(--bd);border-radius:8px">
          <div style="font-size:16px">${ok?''+window.icon("check",14)+'':''+window.icon("close",14)+''}</div>
          <div style="flex:1"><div style="font-size:12px;font-weight:600;color:var(--t1)">${esc(LU_humanize(r.tool_id))}</div><div style="font-size:10px;color:var(--t3)">${esc(r.agent_id)} · ${r.duration_ms||0}ms · ${r.mode||'?'}</div>${r.rationale?'<div style="font-size:10px;color:var(--t2);margin-top:2px">'+esc(r.rationale.slice(0,100))+'</div>':''}</div>
          <div style="font-size:10px;color:var(--t3);white-space:nowrap">${dt.toLocaleDateString()} ${dt.toLocaleTimeString()}</div>
          <div style="font-size:10px;max-width:200px;overflow:hidden;text-overflow:ellipsis;color:var(--t2)">${esc((r.result_summary||'').slice(0,80))}</div>
        </div>`;
      }).join('')+'</div>';
    }
    else if(rptTab==='decisions'){
      var d=await get(API+'decisions?limit=50');
      var rows=d.decisions||[];
      if(!rows.length){box.innerHTML='<div class="rv-empty"><div class="rv-empty-icon">'+window.icon("tag",14)+'</div><div class="rv-empty-text">No decisions recorded yet.</div></div>';return;}
      box.innerHTML='<div style="display:flex;flex-direction:column;gap:6px">'+rows.map(r=>{
        var st=r.status;var sc=st==='approved'?'var(--ac)':st==='rejected'?'#F87171':st==='proposed'?'var(--am)':'var(--t3)';
        return `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--s1);border:1px solid var(--bd);border-radius:8px">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:${sc};min-width:60px">${st}</div>
          <div style="flex:1"><div style="font-size:12px;font-weight:600;color:var(--t1)">${esc(r.title)}</div>${r.rationale?'<div style="font-size:10px;color:var(--t2);margin-top:2px">'+esc(r.rationale.slice(0,120))+'</div>':''}<div style="font-size:10px;color:var(--t3)">${esc(r.agent_id)} · ${r.decision_type}</div></div>
          <div style="font-size:10px;color:var(--t3);white-space:nowrap">${window._luParseTs(r.created_at).toLocaleDateString()}</div>
        </div>`;
      }).join('')+'</div>';
    }
    else if(rptTab==='tasks'){
      // RES-2 (2026-08-30): the workspace comes from the session (the old ?wsId=1 was ignored server-side);
      // tasks carry action/progress_message/assigned_agents_json, not title/agent_id — render those.
      var d=await get(API+'tasks');
      var rows=(d.tasks||d||[]).slice(0,50).map(function(r){
        var agents=[]; try{ agents=Array.isArray(r.assigned_agents_json)?r.assigned_agents_json:JSON.parse(r.assigned_agents_json||'[]'); }catch(_e){}
        r.title = r.title || ((typeof LU_humanize==='function'?LU_humanize(r.action||''):(r.action||'')) + (r.progress_message?' — '+String(r.progress_message).slice(0,90):''));
        r.agent_id = r.agent_id || (agents[0]||'');
        return r;
      });
      if(!rows.length){box.innerHTML='<div class="rv-empty"><div class="rv-empty-icon" style="color:var(--t3)">'+window.icon('more',32)+'</div><div class="rv-empty-text">No tasks yet.</div></div>';return;}
      box.innerHTML='<div style="display:flex;flex-direction:column;gap:6px">'+rows.map(r=>{
        var st=r.status||'pending';var sc=st==='completed'?'var(--ac)':st==='failed'?'#F87171':st==='in_progress'?'var(--am)':'var(--t3)';
        return `<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--s1);border:1px solid var(--bd);border-radius:8px">
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:${sc};min-width:70px">${st}</div>
          <div style="flex:1"><div style="font-size:12px;font-weight:600;color:var(--t1)">${esc(r.title)}</div><div style="font-size:10px;color:var(--t3)">${esc(r.agent_id||'')} · ${r.duration_ms?r.duration_ms+'ms':''}</div></div>
          <div style="font-size:10px;color:var(--t3);white-space:nowrap">${r.created_at?window._luParseTs(r.created_at).toLocaleDateString():''}</div>
        </div>`;
      }).join('')+'</div>';
    }
    else if(rptTab==='categories'){
      // 2026-05-27 — Phase 2: Work distribution + credit spend by category.
      // Pulls /api/workspace/state (which we already extended to include
      // category) and aggregates client-side. No new endpoint needed.
      var wd = await get(API+'workspace/state');
      var tasks = wd.tasks || [];
      if (!tasks.length) { box.innerHTML='<div class="rv-empty"><div class="rv-empty-icon">'+window.icon("tag",14)+'</div><div class="rv-empty-text">No tasks to categorize yet.</div></div>'; return; }
      // Aggregate
      var ORDER = ['research','create','optimize','publish','crm','campaign','operations'];
      var COLOR = { research:'#3B82F6', create:'#7C3AED', optimize:'#00E5A8', publish:'#F59E0B', crm:'#EC4899', campaign:'#F97316', operations:'#6B7280' };
      var LABEL = { research:'Research', create:'Create', optimize:'Optimize', publish:'Publish', crm:'CRM', campaign:'Campaign', operations:'Operations' };
      var counts = {}, completed = {}, failed = {}, byStatus = {};
      tasks.forEach(function(t){
        var c = t.category || 'operations';
        counts[c] = (counts[c]||0) + 1;
        if (t.status === 'completed') completed[c] = (completed[c]||0)+1;
        if (t.status === 'failed' || t.status === 'cancelled' || t.status === 'degraded') failed[c] = (failed[c]||0)+1;
      });
      var totalTasks = tasks.length;
      var maxCount = Math.max.apply(null, ORDER.map(function(k){ return counts[k]||0; })) || 1;
      // Section: Work distribution (horizontal bar chart)
      var distHtml = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:18px;margin-bottom:18px">'
        + '<div style="font-family:var(--fh);font-size:13px;font-weight:700;color:var(--t1);margin-bottom:14px">Work distribution — last '+totalTasks+' tasks</div>'
        + ORDER.filter(function(k){ return counts[k]; }).map(function(k){
            var n = counts[k]; var pct = Math.round((n/totalTasks)*100);
            var width = Math.max(2, Math.round((n/maxCount)*100));
            return '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">'
              + '<div style="width:100px;font-size:11px;font-family:var(--fh);font-weight:600;color:var(--t1);display:flex;align-items:center;gap:6px">'
              + '<span style="width:8px;height:8px;border-radius:50%;background:'+COLOR[k]+'"></span>'
              + LABEL[k] + '</div>'
              + '<div style="flex:1;background:var(--s3);height:18px;border-radius:9px;overflow:hidden;position:relative">'
              + '<div style="background:'+COLOR[k]+';height:100%;width:'+width+'%;border-radius:9px;transition:width .4s ease"></div>'
              + '</div>'
              + '<div style="min-width:80px;text-align:right;font-size:11px;color:var(--t2);font-family:var(--fb)"><b style="color:var(--t1)">'+n+'</b> · '+pct+'%</div>'
              + '</div>';
          }).join('')
        + '</div>';
      // Section: Web research footprint (Phase 3) — sums fetches/searches
      // across recent research tasks. Pulled from /api/web/activity (read-only).
      var researchHtml = '';
      try {
        var wa = await get(API+'web/activity?limit=200');
        var rows = wa.rows || wa.activity || wa.items || wa || [];
        if (Array.isArray(rows) && rows.length) {
          var nFetch = 0, nSearch = 0, totalCost = 0, totalBytes = 0;
          var domains = {};
          rows.forEach(function (r) {
            if (r.action === 'fetch') nFetch++;
            if (r.action === 'search') nSearch++;
            totalCost += (r.cost_credits | 0);
            totalBytes += (r.content_length | 0);
            if (r.action === 'fetch' && r.url_or_query) {
              try { var u = new URL(r.url_or_query); domains[u.hostname] = (domains[u.hostname]||0)+1; } catch(e){}
            }
          });
          var topDoms = Object.keys(domains).sort(function(a,b){return domains[b]-domains[a];}).slice(0,6);
          researchHtml = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:18px;margin-bottom:18px">'
            + '<div style="font-family:var(--fh);font-size:13px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;gap:8px">&#127760; Web research footprint &mdash; <span style="font-weight:500;color:var(--t3);font-size:11px">last '+rows.length+' web actions</span></div>'
            + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:14px">'
            + '  <div style="background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:11px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;font-family:var(--fh)">Pages fetched</div><div style="font-size:22px;font-weight:700;color:#3B82F6;font-family:var(--fh);margin-top:4px">'+nFetch+'</div></div>'
            + '  <div style="background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:11px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;font-family:var(--fh)">Searches</div><div style="font-size:22px;font-weight:700;color:#3B82F6;font-family:var(--fh);margin-top:4px">'+nSearch+'</div></div>'
            + '  <div style="background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:11px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;font-family:var(--fh)">Credits</div><div style="font-size:22px;font-weight:700;color:var(--am);font-family:var(--fh);margin-top:4px">'+totalCost+'</div></div>'
            + '  <div style="background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:11px"><div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;font-family:var(--fh)">Content read</div><div style="font-size:22px;font-weight:700;color:var(--ac);font-family:var(--fh);margin-top:4px">'+Math.round(totalBytes/1024)+'<span style="font-size:11px;color:var(--t3);font-weight:400"> KB</span></div></div>'
            + '</div>'
            + (topDoms.length ? '<div><div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;font-family:var(--fh);margin-bottom:8px">Top domains</div>' + topDoms.map(function(d){ return '<span style="display:inline-block;background:var(--s3);color:var(--t1);padding:4px 10px;border-radius:99px;font-size:11px;margin-right:5px;margin-bottom:5px">'+esc(d)+' <span style="color:var(--t3)">&middot; '+domains[d]+'</span></span>'; }).join('')+'</div>' : '')
            + '</div>';
        }
      } catch (e) { /* web activity may be empty / restricted */ }

      // Section: Success rate by category
      var successHtml = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:18px;margin-bottom:18px">'
        + '<div style="font-family:var(--fh);font-size:13px;font-weight:700;color:var(--t1);margin-bottom:14px">Success rate by category</div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px">'
        + ORDER.filter(function(k){ return counts[k]; }).map(function(k){
            var done = completed[k]||0; var fail = failed[k]||0;
            var denom = done + fail;
            var rate = denom > 0 ? Math.round((done/denom)*100) : null;
            var rateText = rate === null ? '—' : rate + '%';
            return '<div style="background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:11px">'
              + '<div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;font-family:var(--fh)">'+LABEL[k]+'</div>'
              + '<div style="font-size:22px;font-weight:700;color:'+COLOR[k]+';font-family:var(--fh);margin-top:4px">'+rateText+'</div>'
              + '<div style="font-size:10px;color:var(--t3);margin-top:2px">'+done+' ok · '+fail+' issues</div>'
              + '</div>';
          }).join('')
        + '</div></div>';
      box.innerHTML = distHtml + researchHtml + successHtml;
    }
  }catch(e){box.innerHTML='<div style="color:#F87171;padding:20px">Error loading: '+esc(e.message)+'</div>';}
}
function showSummary(id,topic,date,summaryEnc){
  setEl('sm-title',topic);
  setEl('sm-date',new Date(date).toLocaleString());
  document.getElementById('sm-body').innerHTML=fmt(decodeURIComponent(summaryEnc));
  document.getElementById('summary-backdrop').classList.add('visible');
}
function closeSummaryModal(){document.getElementById('summary-backdrop').classList.remove('visible');}

// ══ MEETING ROOM ═══════════════════════════════════════════════════════════
function setType(t,el){
  selType=t;
  document.querySelectorAll('#type-grid>div').forEach(b=>{b.style.border='1.5px solid var(--bd)';b.style.background='var(--s2)';});
  el.style.border='1.5px solid rgba(108,92,231,.5)';el.style.background='var(--ps)';
}
async function launchMeeting(){
  var topic=document.getElementById('topic-input').value.trim();
  if(!topic){document.getElementById('topic-input').focus();return;}
  document.getElementById('mtg-start-screen').style.display='none';
  document.getElementById('mtg-active').style.display='flex';
  document.getElementById('mtg-topic').textContent=topic.length>55?topic.slice(0,55)+'…':topic;
  document.getElementById('live-dot').classList.add('on');
  spoken.clear();phaseLog.clear();
  intel.theme='';intel.seo=[];intel.content=[];intel.social=[];intel.funnel=[];
  document.getElementById('disc-feed').innerHTML='';
  document.getElementById('mi-body').innerHTML='<div class="mi-ph" id="mi-ph"><div class="mi-ph-icon">'+window.icon("ai",14)+'</div><div class="mi-ph-txt">Intelligence builds as the team discusses.</div></div>';
  try{
    let r;
  try {
    r = await post(API+'meeting/start',{type:selType,topic,businessName:BN,website:BU});
    // Governance gate: if WP returns 422 (profile incomplete) redirect to Settings
    if (r && r.error === 'workspace_profile_incomplete') {
      showToast('Complete your Workspace Intelligence Profile in Settings before starting a meeting.', 'error');
      setTimeout(() => nav('settings'), 1200);
      return;
    }
  } catch(gateErr) {
    // 422 may throw in some fetch wrappers
    if (gateErr?.message?.includes('422') || gateErr?.message?.includes('workspace_profile')) {
      showToast('Complete your Workspace Intelligence Profile in Settings before starting a meeting.', 'error');
      setTimeout(() => nav('settings'), 1200);
      return;
    }
    throw gateErr;
  }
    mid=r.meeting_id;seen=0;done=false;_redisCount=0;localUserMsgs=[];
    document.getElementById('btn-wrap').disabled=false;
    pollT=setInterval(poll,4000);
  }catch(e){showMsgErr('Could not start: '+e.message);}
}
var _pollStaleCount=0;var _pollLastHash='';var _redisCount=0;var _pollFails=0;
async function poll(){
  if(!mid) return;
  if(_pollFails>=5){console.warn('[LU] Meeting poll disabled after 5 consecutive failures');if(pollT){clearInterval(pollT);pollT=null;}return;}
  try{
    var d=await get(API+'meeting/'+mid+'/status?_t='+Date.now());
    var redisMsgs=d.messages||[];
    _pollFails=0; // reset on success
    // Stale detection: if message count unchanged for 8+ consecutive polls, force re-fetch
    var curHash=redisMsgs.length+':'+(d.phase||'')+(d.status||'');
    if(curHash===_pollLastHash){_pollStaleCount++;}else{_pollStaleCount=0;}_pollLastHash=curHash;
    if(_pollStaleCount>8&&!done){console.warn('[LU] Poll stale for 8+ cycles, will retry with cache bust');_pollStaleCount=0;}

    // ── Merge Redis messages with local user messages ────────────────
    // Runtime does NOT store user messages in Redis. We merge them here
    // so they persist through polls and never disappear from the feed.
    var merged=[];
    let uidx=0;
    for(let i=0;i<redisMsgs.length;i++){
      // Insert any user messages that were sent AFTER the previous Redis message
      while(uidx<localUserMsgs.length && localUserMsgs[uidx]._afterRedis<=i){
        merged.push(localUserMsgs[uidx++]);
      }
      merged.push(redisMsgs[i]);
    }
    // Append remaining user messages (sent after all current Redis messages)
    while(uidx<localUserMsgs.length) merged.push(localUserMsgs[uidx++]);

    // Render new messages from the merged stream
    for(let i=seen;i<merged.length;i++) renderMsg(merged[i]);
    seen=merged.length;
    _redisCount=redisMsgs.length;

    // ── Defensive: verify all user messages are still in DOM ─────────
    // If any are missing (due to DOM manipulation, race condition, etc.),
    // re-inject them immediately.
    for(var um of localUserMsgs){
      if(um._umid && !document.querySelector('[data-umid="'+um._umid+'"]')){
        renderMsg(um);
      }
    }

    applyPhase(d.phase,d.status);
    applySpeaker(d.status,d.current_speaker,d.spokenAgents||[]);
    if(d.status==='complete'&&!done){
      done=true;clearInterval(pollT);removeTyping();
      document.getElementById('live-dot').classList.remove('on');
      document.getElementById('btn-wrap').disabled=true;
      document.getElementById('btn-wrap').textContent='✓ Meeting Ended';
      document.getElementById('btn-wrap').style.background='var(--ac)';
      document.getElementById('btn-wrap').style.borderColor='var(--ac)';
      document.getElementById('btn-wrap').style.color='#fff';
      // Save history
      var synMsg=redisMsgs.find(m=>m.role==='synthesis');
      if(synMsg) await post(API+'history',{meeting_id:mid,topic:d.topic||'',summary:synMsg.content});
      // Show meeting-ended banner in feed
      var feed=document.getElementById('disc-feed');
      if(feed){
        var banner=document.createElement('div');
        banner.style.cssText='margin:20px 0;padding:16px 20px;background:linear-gradient(135deg,rgba(108,92,231,.08),rgba(0,229,168,.06));border:1px solid rgba(108,92,231,.2);border-radius:12px;text-align:center';
        banner.innerHTML=`
          <div style="font-family:var(--fh);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:6px">🏁 Meeting Complete</div>
          <div style="font-size:12px;color:var(--t2);margin-bottom:12px">Sarah is generating tasks and action items…</div>
          <div id="meeting-end-links" style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
            <span style="font-size:11px;color:var(--t3)">Loading tasks…</span>
          </div>
        `;
        feed.appendChild(banner);
        scrollFeed();
      }
      // Check for pending tasks
      setTimeout(()=>checkPendingTasks(mid),2500);
    }
    if(d.status==='error'){clearInterval(pollT);showMsgErr(d.error||'Error');}
  }catch(e){_pollFails++;console.error('poll:',e);}
}
function applyPhase(phase){
  if(!phase)return;
  var idx=PHASES.indexOf(phase);
  PHASES.forEach((p,i)=>{var el=document.getElementById('ps-'+p);if(!el)return;el.classList.remove('active','done');if(i<idx)el.classList.add('done');else if(i===idx)el.classList.add('active');});
  if(phase!==lastPhase&&lastPhase&&seen>0&&!phaseLog.has(phase)){
    phaseLog.add(phase);
    var lbl={idea_round:''+window.icon("ai",14)+' Ideas Round',discussion_round:''+window.icon("message",14)+' Discussion Round',refinement_round:''+window.icon("edit",14)+' Refinement Round',synthesis:''+window.icon("more",14)+' Action Plan'};
    var css={idea_round:'pd-idea',discussion_round:'pd-disc',refinement_round:'pd-ref',synthesis:'pd-syn'};
    if(lbl[phase]){var d=document.createElement('div');d.className='phase-div';d.innerHTML=`<div class="pd-line"></div><div class="pd-pill ${css[phase]}">${lbl[phase]}</div><div class="pd-line"></div>`;document.getElementById('disc-feed').appendChild(d);scrollFeed();}
  }
  lastPhase=phase;
}
function applySpeaker(status,spk,spokenArr){
  if(spk&&spk!==lastSpk){showTyping(spk);lastSpk=spk;}
  if(!spk){removeTyping();lastSpk=null;}
  spokenArr.forEach(id=>spoken.add(id));
  let active=0;
  Object.keys(AGENTS).forEach(id=>{
    var el=document.getElementById('mac-'+id),st=document.getElementById('mst-'+id);
    if(!el)return;el.classList.remove('speaking','done');
    if(id===spk){
      el.classList.add('speaking');active++;
      if(st){st.className='mac-st st-thinking';st.textContent='Working…';}
      startAgentActivity(id);
    } else {
      stopAgentActivity(id);
      if(spoken.has(id)){el.classList.add('done');if(st){st.className='mac-st st-done';st.textContent='Contributed';}}
      else if(st){st.className='mac-st st-idle';st.textContent='Waiting';}
    }
  });
  setEl('agents-active',spoken.size+' active');
}
// ── Phase 6.5: Structured synthesis panel renderer ───────────────────────
function _renderSynthesisPanel(text) {
  if (!text) return '';
  // Split into sections by ## or ### headers
  var lines = text.split('\n');
  let sections = [];
  let current = { title: 'Summary', lines: [] };

  lines.forEach(line => {
    var h2 = line.match(/^##\s+(.+)/);
    var h3 = line.match(/^###\s+(.+)/);
    if (h2 || h3) {
      if (current.lines.length || current.title !== 'Summary') sections.push(current);
      current = { title: (h2 || h3)[1].replace(/\*\*/g, '').trim(), lines: [], level: h2 ? 2 : 3 };
    } else {
      current.lines.push(line);
    }
  });
  if (current.lines.length) sections.push(current);

  if (sections.length <= 1) return fmt(text); // No structure detected — fallback

  var domainIcons = {seo:'📊',content:'✍️',social:'📱',email:'📧',crm:'👥',marketing:'📣',builder:'🏗',technical:'⚙️',website:'🌐',lead:'🎯',strategy:'🧭'};

  let html = '';
  sections.forEach(sec => {
    var icon = Object.entries(domainIcons).find(([k]) => sec.title.toLowerCase().includes(k))?.[1] || '▪';
    var body = sec.lines.join('\n').trim();
    if (!body) return;

    // Check if body contains action items (numbered or bulleted lines)
    var actionLines = body.split('\n').filter(l => l.match(/^\s*[\d]+\.|^\s*[-•*]|^\s*✓|^\s*→/));
    var hasActions = actionLines.length >= 2;

    html += `<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;margin-bottom:10px">`;
    html += `<div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:8px;display:flex;align-items:center;gap:8px">
      <span style="font-size:16px">${icon}</span>${esc(sec.title)}
    </div>`;

    if (hasActions) {
      html += '<div style="display:flex;flex-direction:column;gap:4px">';
      body.split('\n').forEach(line => {
        var trimmed = line.replace(/^\s*[\d]+\.\s*|^\s*[-•*]\s*|^\s*[✓→]\s*/, '').trim();
        if (!trimmed) return;
        var isAction = line.match(/^\s*[\d]+\.|^\s*[-•*]|^\s*✓|^\s*→/);
        if (isAction) {
          // Detect agent mentions
          let agentBadge = '';
          ['James','Priya','Elena','Alex','Sarah'].forEach(n => {
            if (trimmed.includes(n)) {
              var id = n.toLowerCase();
              var ag = AGENTS[id] || AGENTS[n.toLowerCase().slice(0,1) + n.slice(1)];
              if (ag) agentBadge += `<span style="font-size:10px;background:${ag.color}15;color:${ag.color};padding:1px 6px;border-radius:3px;margin-left:6px">${ag.emoji} ${ag.name||n}</span>`;
            }
          });
          html += `<div style="font-size:12px;color:var(--t2);padding:5px 0;border-bottom:1px solid rgba(255,255,255,.03);display:flex;align-items:center;gap:6px">
            <span style="color:var(--ac);font-size:10px;flex-shrink:0">●</span>
            <span style="flex:1">${fmt(trimmed)}</span>${agentBadge}
          </div>`;
        } else {
          html += `<div style="font-size:12px;color:var(--t3);padding:3px 0">${fmt(trimmed)}</div>`;
        }
      });
      html += '</div>';
    } else {
      html += `<div style="font-size:12px;color:var(--t2);line-height:1.6">${fmt(body)}</div>`;
    }
    html += '</div>';
  });

  return html;
}

function renderMsg(msg){
  removeTyping();
  var feed=document.getElementById('disc-feed');
  var isU=msg.role==='user',isDMM=msg.agent_id==='dmm',isSyn=msg.role==='synthesis';
  var c=AGENTS[msg.agent_id]?.color||'var(--t2)',em=isU?'👤':(AGENTS[msg.agent_id]?.emoji||'🤖');
  if(!isU&&!spoken.has(msg.agent_id)){spoken.add(msg.agent_id);var jn=document.createElement('div');jn.className='joined-n';jn.innerHTML=`<div class="jn-chip" style="color:${c}">${em} ${msg.name} joined</div>`;feed.appendChild(jn);}
  if(!isU) extractIntel(msg);

  // Parse governance action metadata embedded by the runtime
  let govAction=null;
  let displayContent=msg.content||'';
  var govMatch=displayContent.match(/^__GOVERNANCE_ACTION__([\s\S]*?)__END_GOVERNANCE__\n?([\s\S]*)$/);
  if(govMatch){
    try{ govAction=JSON.parse(govMatch[1]); }catch(e){}
    displayContent=govMatch[2].trim();
  }

  var badge=msg.role==='opening'?'<span class="msg-badge b-opens">Opens</span>':msg.role==='checkin'?'<span class="msg-badge b-checkin">Check-in</span>':msg.role==='synthesis'?'<span class="msg-badge b-plan">Action Plan</span>':'';

  // Build governance approval card if present
  var govCard=govAction?`<div class="gov-inline-card" id="gov-${govAction.action_id}">
    <div style="font-size:10px;font-weight:700;color:var(--am);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;display:inline-flex;align-items:center;gap:5px">${window.icon('warning',12)} Action Requires Approval</div>
    <div style="font-size:12px;font-weight:700;color:#fff;margin-bottom:4px">${escHtml(govAction.tool_name)}</div>
    <div style="font-size:12px;color:var(--t2);margin-bottom:10px">${escHtml(govAction.preview)}</div>
    <div style="display:flex;gap:8px">
      <button class="gov-btn gov-approve" style="font-size:11px;padding:6px 14px" onclick="govApprove('${govAction.action_id}',this)">✓ Approve</button>
      <button class="gov-btn gov-reject"  style="font-size:11px;padding:6px 14px" onclick="govReject('${govAction.action_id}',this)">✕ Reject</button>
    </div>
  </div>`:''

  var bubContent=isSyn
    ?`<div>${_renderSynthesisPanel(displayContent)}</div>
      <div style="display:flex;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid var(--bd)">
        <button class="dl-btn" onclick="downloadTranscript()" style="font-size:11px">↓ Download</button>
        <button class="btn btn-outline btn-sm" onclick="nav('previews')" style="font-size:11px;border-color:var(--am)">${window.icon('eye',14)} Previews</button>
        <button class="btn btn-outline btn-sm" onclick="nav('workspace')" style="font-size:11px">${window.icon('more',14)} Tasks</button>
      </div>`
    :`${fmt(displayContent)}${govCard}`;

  var div=document.createElement('div');
  div.className=`msg-card${isU?' is-user':''}${isDMM&&!isU?' is-dmm':''}${isSyn?' is-synthesis':''}`;
  if(isU&&msg._umid) div.setAttribute('data-umid',msg._umid);
  div.innerHTML=`<div class="msg-av" style="background:transparent;border:none">${(!isU&&typeof buildAgentOrb==="function")?buildAgentOrb(msg.agent_id,"sm",isSyn?"success":"idle"):em}</div><div class="msg-body"><div class="msg-meta"><span class="msg-name" style="color:${c}">${msg.name}</span>${msg.title&&!isU?`<span class="msg-title-lbl">${msg.title}</span>`:''} ${badge}</div><div class="msg-bubble">${bubContent}</div></div>`;
  feed.appendChild(div);scrollFeed();

  // ── Execution Controller: detect actionable intent → inject buttons ──
  if(!isU && !isSyn && msg.role !== 'opening') execCtrl(div, msg);

  // Update badge count if a governance action arrived
  if(govAction){
    get(API+'governance/pending').then(d=>updateGovBadge((d.pending||[]).length)).catch(()=>{});
  }
}
function showTyping(aid){
  removeTyping();
  var c=AGENTS[aid]?.color||'var(--t2)',em=AGENTS[aid]?.emoji||'🤖',name=AGENTS[aid]?.name||aid;
  var t=(THINK[aid]||['Thinking…'])[Math.floor(Math.random()*(THINK[aid]?.length||1))];
  var div=document.createElement('div');div.id='typing-ind';div.className='typing-card';
  div.innerHTML=`<div class="msg-av" style="background:transparent;border:none">${(!isU&&typeof buildAgentOrb==="function")?buildAgentOrb(msg.agent_id,"sm",isSyn?"success":"idle"):em}</div><div><div style="font-size:9px;color:${c};font-weight:700;margin-bottom:3px">${name}</div><div class="ty-bub"><div class="ty-dots"><div class="ty-dot" style="background:${c}"></div><div class="ty-dot" style="background:${c}"></div><div class="ty-dot" style="background:${c}"></div></div><span class="ty-lbl">${t}</span></div></div>`;
  document.getElementById('disc-feed').appendChild(div);scrollFeed();
}
function removeTyping(){document.getElementById('typing-ind')?.remove();}
function scrollFeed(){var f=document.getElementById('disc-feed');if(f)f.scrollTop=f.scrollHeight;}
function extractIntel(msg){
  var text=msg.content,role=msg.agent_id;let updated=false;
  if(role==='dmm'&&!intel.theme){var m=text.match(/(?:strategy|campaign|focus).*?(?:around|on|for)\s+[""]?([^.,""\n]{10,50})/i);if(m){intel.theme=m[1].trim();updated=true;}}
  if(role==='james'){var w=text.match(/[""]([^""]{5,40})[""]/g)||[];w.forEach(x=>{var c=x.replace(/[""]/g,'');if(!intel.seo.includes(c)&&intel.seo.length<6){intel.seo.push(c);updated=true;}});}
  if(role==='priya'){var m=text.match(/\b([A-Z][a-z]+(?: [A-Z][a-z]+)?)\b(?= content| strategy| guide)/g)||[];m.forEach(p=>{if(!intel.content.includes(p)&&intel.content.length<5){intel.content.push(p);updated=true;}});}
  if(role==='elena'&&!intel.funnel.length){var s=[];if(/content|blog/i.test(text))s.push('Content');if(/landing page|form/i.test(text))s.push('Landing Page');if(/CRM|lead/i.test(text))s.push('CRM');if(/email|nurture/i.test(text))s.push('Email');if(s.length){intel.funnel=s;updated=true;}}
  if(updated)renderIntel();
}
function renderIntel(){
  var body=document.getElementById('mi-body');
  document.getElementById('mi-ph')?.remove();
  document.getElementById('mi-live')?.classList.add('on');
  body.innerHTML='';
  var mk=(icon,title,content,from)=>{var s=document.createElement('div');s.className='mi-sec';s.innerHTML=`<div class="mi-sec-title"><span>${icon}</span>${title}</div><div>${content}</div><div style="display:flex;align-items:center;gap:5px;font-size:9px;color:var(--t3);padding-top:5px;border-top:1px solid var(--bd);margin-top:6px"><div style="width:4px;height:4px;border-radius:50%;background:var(--ac)"></div>${from}</div>`;body.appendChild(s);};
  if(intel.theme) mk(''+window.icon("more",14)+'','Strategy Focus',`<div style="font-family:var(--fh);font-size:13px;font-weight:700;color:var(--t1)">${esc(intel.theme)}</div>`,'Live · Sarah');
  if(intel.seo.length) mk(''+window.icon("chart",14)+'','SEO Opportunities',intel.seo.map(k=>`<span class="mi-tag ac">${esc(k)}</span>`).join(''),'From James');
  if(intel.content.length) mk(''+window.icon("edit",14)+'','Content Pillars',intel.content.map(p=>`<span class="mi-tag">${esc(p)}</span>`).join(''),'From Priya');
  if(intel.funnel.length) mk(''+window.icon("more",14)+'','Lead Funnel',intel.funnel.map((st,i)=>`<span class="mi-tag">${esc(st)}</span>${i<intel.funnel.length-1?'<span style="color:var(--t3);font-size:10px;margin:0 2px">→</span>':''}`).join(''),'From Elena');
}
function prefill(text){var ta=document.getElementById('cmd-input');ta.value=text;ta.focus();ta.style.height='auto';ta.style.height=Math.min(ta.scrollHeight,80)+'px';}

// ═══════════════════════════════════════════════════════════════════════════
// EXECUTION CONTROLLER v3 — APPROVAL-FIRST + AUTOPILOT + PERSISTENT MEMORY
// DEFAULT: Agents propose actions → user approves → then execute
// AUTOPILOT: Only when user explicitly says "go on autopilot"
// Every execution persisted to lu_exec_history. Every decision to lu_decision_log.
// ═══════════════════════════════════════════════════════════════════════════

let execMode = 'approval'; // 'approval' or 'autopilot'

// Fetch mode from server on load
;window.luDefer(function(){(async function(){try{var r=await get(API+'exec/mode');execMode=r.mode||'approval';_updateModeUI();}catch(e){}})();});

function _updateModeUI(){
  // Badge removed per user request — mode still functional via Settings
  var ind=document.getElementById('exec-mode-ind');
  if(ind) ind.remove();
}
async function _setMode(mode){
  try{await post(API+'exec/mode',{mode});execMode=mode;_updateModeUI();
    showToast(mode==='autopilot'?''+window.icon("ai",14)+' Autopilot activated — safe tools will auto-execute':''+window.icon("lock",14)+' Approval mode — agents will ask before executing','success');
  }catch(e){showToast('Mode change failed','error');}
}

// ── Safe tools (can auto-run in autopilot) ───────────────────────────────
var EXEC_SAFE = new Set([
  'deep_audit','ai_report','ai_status','serp_analysis','scan_site_url',
  'outbound_links','check_outbound','link_suggestions',
  'list_leads','get_lead','list_campaigns','list_templates','list_posts',
  'get_queue','list_events','check_availability','list_builder_pages',
  'get_builder_page','list_goals','agent_status','list_sequences',
  'get_site_pages','get_site_page','search_site_content',
  'system_health_check','list_previews','proactive_status','memory_context',
  'analyze_funnel_structure','generate_funnel_blueprint','record_social_analytics',
  'record_metric','log_activity',
]);

// ── Full intent→tool mapping ─────────────────────────────────────────────
var EXEC_INTENTS = [
  {rx:/\b(?:run|need|do|perform|should|let me|i'?ll)\s+(?:a\s+)?(?:full\s+|site\s+)?(?:seo\s+)?audit/i, tool:'deep_audit',       label:'Run SEO Audit',       icon:''+window.icon("search",14)+'', paramFn:_pPostId},
  {rx:/\b(?:scan|check|crawl|analyze)\s+(?:the\s+|our\s+)?(?:site|website|homepage|url)/i,               tool:'scan_site_url',    label:'Scan Website',        icon:''+window.icon("globe",14)+'', paramFn:()=>({url:BU||location.origin})},
  {rx:/\b(?:analyze|research|check|find)\s+(?:the\s+)?(?:keyword|search\s+term|query|serp)/i,            tool:'serp_analysis',    label:'Analyze Keywords',    icon:''+window.icon("chart",14)+'', paramFn:_pKeyword},
  {rx:/\b(?:check|scan|find|fix)\s+(?:broken\s+)?(?:outbound|external)\s+link/i,                         tool:'outbound_links',   label:'Check Outbound Links',icon:''+window.icon("link",14)+'', paramFn:_pPostId},
  {rx:/\b(?:get|check|find|view)\s+(?:internal\s+)?link\s+(?:suggest|opportunit|recommend)/i,             tool:'link_suggestions', label:'Link Suggestions',    icon:''+window.icon("link",14)+'', paramFn:_pPostId},
  {rx:/\bgenerate\s+(?:a\s+)?(?:seo\s+)?report/i,                                                       tool:'ai_report',        label:'Generate SEO Report', icon:''+window.icon("more",14)+'', paramFn:_pPostId},
  {rx:/\b(?:add|insert|place)\s+(?:a\s+)?(?:internal\s+)?link/i,                                         tool:'insert_link',      label:'Insert Link',         icon:''+window.icon("link",14)+'', paramFn:_pId},
  {rx:/\b(?:write|create|generate|draft)\s+(?:a\s+)?(?:blog\s+|seo\s+)?(?:article|post|content)/i,      tool:'write_article',    label:'Generate Article',    icon:''+window.icon("edit",14)+'', paramFn:_pKeyword},
  {rx:/\b(?:improve|optimize|rewrite|enhance)\s+(?:the\s+)?(?:content|draft|copy|text)/i,                tool:'improve_draft',    label:'Improve Content',     icon:''+window.icon("edit",14)+'', paramFn:_pKeyword},
  {rx:/\b(?:create|write|draft)\s+(?:a\s+)?(?:social\s+)?(?:media\s+)?post/i,                           tool:'create_post',      label:'Create Social Post',  icon:''+window.icon("message",14)+'', paramFn:()=>({content:'',platforms:['facebook']})},
  {rx:/\bschedule\s+(?:a\s+)?(?:social\s+)?post/i,                                                      tool:'schedule_post',    label:'Schedule Post',       icon:''+window.icon("calendar",14)+'', paramFn:()=>({})},
  {rx:/\bpublish\s+(?:the\s+|a\s+)?post/i,                                                              tool:'publish_post',     label:'Publish Post',        icon:''+window.icon("rocket",14)+'', paramFn:()=>({})},
  {rx:/\b(?:show|list|view)\s+(?:all\s+)?posts/i,                                                       tool:'list_posts',       label:'View Posts',          icon:''+window.icon("more",14)+'', paramFn:()=>({})},
  {rx:/\b(?:create|add)\s+(?:a\s+)?(?:new\s+)?lead/i,                                                   tool:'create_lead',      label:'Create Lead',         icon:''+window.icon("more",14)+'', paramFn:()=>({name:'',email:'',stage:'new'})},
  {rx:/\b(?:check|view|list|show)\s+(?:all\s+)?lead/i,                                                  tool:'list_leads',       label:'View Leads',          icon:''+window.icon("more",14)+'', paramFn:()=>({})},
  {rx:/\b(?:create|add|schedule)\s+(?:a\s+)?event/i,                                                    tool:'create_event',     label:'Create Event',        icon:''+window.icon("calendar",14)+'', paramFn:()=>({})},
  {rx:/\b(?:show|list|view)\s+(?:all\s+)?events/i,                                                      tool:'list_events',      label:'View Events',         icon:''+window.icon("calendar",14)+'', paramFn:()=>({})},
  {rx:/\b(?:build|create|generate)\s+(?:a\s+)?(?:landing\s+)?page/i,                                    tool:'generate_page_layout',label:'Generate Page',     icon:''+window.icon("more",14)+'', paramFn:()=>({})},
  {rx:/\b(?:show|list|view)\s+(?:builder\s+)?pages/i,                                                   tool:'list_builder_pages',label:'View Pages',          icon:''+window.icon("more",14)+'', paramFn:()=>({})},
  {rx:/\bexport\s+(?:the\s+)?(?:web)?site/i,                                                            tool:'export_website',   label:'Export Website',      icon:''+window.icon("export",14)+'', paramFn:_pId},
  {rx:/\b(?:check|run)\s+(?:system\s+)?health/i,                                                        tool:'system_health_check',label:'System Health',      icon:''+window.icon("check",14)+'', paramFn:()=>({})},
  {rx:/\b(?:check|view)\s+proactive/i,                                                                  tool:'proactive_status', label:'Proactive Scan',      icon:''+window.icon("ai",14)+'', paramFn:()=>({})},
  {rx:/\b(?:analyze|check)\s+funnel/i,                                                                  tool:'analyze_funnel_structure',label:'Analyze Funnel', icon:''+window.icon("chart",14)+'', paramFn:()=>({})},
];

// ── LAUNCH SCOPE (W6) ────────────────────────────────────────────────────
// Quick actions are derived from an allowlist, not from label deletions. Even
// if a crafted agent reply matches a removed intent's regex, the action can no
// longer render, because the intent is not in the array at all. The same filter
// runs over chains so a multi-step flow cannot smuggle a removed step in.
if (window.LU_SCOPE) {
  EXEC_INTENTS = EXEC_INTENTS.filter(function (i) { return !window.LU_SCOPE.isRemovedAction(i.tool); });
}

// ── Chained flows ────────────────────────────────────────────────────────
var EXEC_CHAINS = [
  {rx:/\b(?:improve|fix|boost|optimize)\s+(?:my\s+|our\s+|the\s+)?seo\b/i, label:'SEO Improvement Flow', icon:''+window.icon("rocket",14)+'',
   steps:[{tool:'deep_audit',label:'Audit site',paramFn:_pPostId},{tool:'serp_analysis',label:'Keyword analysis',paramFn:_pKeyword},{tool:'link_suggestions',label:'Find link opportunities',paramFn:_pPostId}]},
  {rx:/\b(?:create|write|produce)\s+(?:new\s+)?content\b/i, label:'Content Creation Flow', icon:''+window.icon("edit",14)+'',
   steps:[{tool:'serp_analysis',label:'Research keywords',paramFn:_pKeyword},{tool:'write_article',label:'Generate article',paramFn:_pKeyword}]},
  {rx:/\b(?:fix|check|analyze)\s+(?:all\s+)?(?:broken\s+)?links\b/i, label:'Link Health Flow', icon:''+window.icon("link",14)+'',
   steps:[{tool:'outbound_links',label:'Check outbound links',paramFn:_pPostId},{tool:'link_suggestions',label:'Find link opportunities',paramFn:_pPostId}]},
];

// Drop any chain that contains a removed step, and any chain whose own label
if (window.LU_SCOPE) {
  EXEC_CHAINS = EXEC_CHAINS.filter(function (ch) {
    if (window.LU_SCOPE.isRemovedLabel(String(ch.label || '').replace(/\s*Flow$/i, ''))) return false;
    return (ch.steps || []).every(function (s) { return !window.LU_SCOPE.isRemovedAction(s.tool); });
  });
}

// ── Parameter extractors ─────────────────────────────────────────────────
function _pPostId(text){var m=text.match(/post[_\s]?id[:\s=]*(\d+)/i);if(m) return {post_id:parseInt(m[1])};var all=document.getElementById('disc-feed')?.textContent||'';var pm=all.match(/post_id=(\d+)/);return pm?{post_id:parseInt(pm[1])}:{post_id:0};}
function _pKeyword(text){var m=text.match(/[""\u201C\u201D]([^"""\u201C\u201D]{3,60})[""\u201C\u201D]/);if(m) return {keyword:m[1]};var m2=text.match(/(?:for|about|on|targeting|keyword[s]?\s*[:=])\s*["']?([a-zA-Z][a-zA-Z\s]{3,40}?)["']?(?:\.|,|\s+(?:in|for|to|and)|$)/i);return m2?{keyword:m2[1].trim()}:{keyword:''};}
function _pId(text){var m=text.match(/(?:id|#)\s*[:=]?\s*(\d+)/i);return m?{id:parseInt(m[1])}:{};}

// ── EXECUTION CONTROLLER — called for every agent message ────────────────
function execCtrl(div, msg){
  var text = msg.content || '';
  if(text.length < 15) return;
  if(/<tool_call>/i.test(text)) return;

  var isDMM = msg.agent_id === 'dmm';

  // ── DMM messages: full proposal cards with approval flow ───────────
  if(isDMM){
    // Check for chains first
    for(var chain of EXEC_CHAINS){
      if(chain.rx.test(text)){_renderProposal(div, chain.steps.map(s=>({...s,isSafe:EXEC_SAFE.has(s.tool)})), text, msg, chain.label, chain.icon);return;}
    }
    // Individual intents from DMM
    var matched=[];
    for(var intent of EXEC_INTENTS){if(intent.rx.test(text)&&!matched.find(m=>m.tool===intent.tool)){matched.push({...intent,isSafe:EXEC_SAFE.has(intent.tool)});}if(matched.length>=4) break;}
    if(matched.length) _renderProposal(div, matched, text, msg);
    return;
  }

  // ── Specialist messages: lightweight manual-only buttons ────────────
  // Specialists cannot auto-execute. Buttons are for the USER to trigger manually.
  var matched=[];
  for(var intent of EXEC_INTENTS){if(intent.rx.test(text)&&!matched.find(m=>m.tool===intent.tool)){matched.push(intent);}if(matched.length>=3) break;}
  if(!matched.length) return;

  var bar = document.createElement('div');
  bar.className = 'exec-btns';
  bar.style.cssText += ';border-top:1px dashed var(--bd);padding-top:8px;margin-top:8px';
  var label = document.createElement('div');
  label.style.cssText = 'font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;width:100%';
  label.textContent = 'Quick actions (manual)';
  bar.appendChild(label);
  matched.forEach(intent => {
    var btn = document.createElement('button');
    btn.className = 'exec-btn';
    btn.style.cssText += ';opacity:.8';
    btn.innerHTML = `<span class="eb-icon">${intent.icon||''+window.icon("ai",14)+''}</span> ${intent.label}`;
    btn.onclick = () => _execBtn(btn, intent, text, msg);
    bar.appendChild(btn);
  });
  var bubble = div.querySelector('.msg-bubble');
  if(bubble) bubble.appendChild(bar);
}

// ── Render proposal card (APPROVAL-FIRST or AUTOPILOT) ───────────────────
function _renderProposal(div, actions, text, msg, chainLabel, chainIcon){
  // LAUNCH SCOPE (W6): last line of defence — never render a removed action,
  // whatever produced it (agent text, cached payload, backend metadata).
  if (window.LU_SCOPE) {
    steps = window.LU_SCOPE.filterActions(steps);
    if (!steps.length) return;
  }

  var isAutopilot = execMode === 'autopilot';
  var bubble = div.querySelector('.msg-bubble');
  if(!bubble) return;

  var card = document.createElement('div');
  card.className = 'exec-proposal';
  card.style.cssText = 'margin-top:12px;padding:12px 14px;background:var(--s2);border:1px solid var(--bd2);border-radius:10px';

  // Header
  var hdr = document.createElement('div');
  hdr.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:8px';
  hdr.innerHTML = `<div style="font-size:11px;font-weight:700;color:${isAutopilot?'var(--ac)':'var(--p)'};text-transform:uppercase;letter-spacing:.06em">${isAutopilot?''+window.icon("ai",14)+' Sarah\'s Plan — Auto-Executing':'👩‍'+window.icon("more",14)+' Sarah\'s Plan — Awaiting Your Approval'}</div>${chainLabel?`<div style="font-size:10px;color:var(--t3)">${chainIcon||''} ${chainLabel}</div>`:''}`;
  card.appendChild(hdr);

  // Action list
  var list = document.createElement('div');
  list.style.cssText = 'display:flex;flex-direction:column;gap:6px;margin-bottom:10px';

  actions.forEach((a, i) => {
    var row = document.createElement('div');
    row.className = 'exec-action-row';
    row.dataset.idx = i;
    row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:6px;background:var(--s1);border:1px solid var(--bd)';
    var isProtected = _PROTECTED && _PROTECTED.has(a.tool);
    var risk = isProtected ? '<span style="color:var(--rd);font-size:9px;font-weight:700">'+window.icon("lock",14)+' PROTECTED</span>' : a.isSafe ? '<span style="color:var(--ac);font-size:9px;font-weight:700">'+window.icon("ai",14)+' AUTO</span>' : '<span style="color:var(--am);font-size:9px;font-weight:700">'+window.icon("lock",14)+' REVIEW</span>';
    row.innerHTML = `<span style="font-size:14px">${a.icon||''+window.icon("ai",14)+''}</span><div style="flex:1"><div style="font-size:12px;font-weight:600;color:var(--t1)">${a.label}</div><div style="font-size:10px;color:var(--t3)">Tool: ${a.tool} ${risk}</div></div><div class="exec-row-status" style="font-size:10px;color:var(--t3)">pending</div>`;
    list.appendChild(row);
  });
  card.appendChild(list);

  // Result container
  var resultBox = document.createElement('div');
  resultBox.className = 'exec-results-box';
  resultBox.style.cssText = 'display:none;flex-direction:column;gap:6px;margin-bottom:10px';
  card.appendChild(resultBox);

  if(isAutopilot){
    // AUTOPILOT: auto-execute safe, show button for risky
    var safeActions = actions.filter(a => a.isSafe);
    var riskyActions = actions.filter(a => !a.isSafe);

    if(safeActions.length){
      safeActions.forEach((a, idx) => {
        setTimeout(() => _execAction(a, text, msg, card, actions.indexOf(a), resultBox), idx * 2000);
      });
    }
    if(riskyActions.length){
      var riskBtns = document.createElement('div');
      riskBtns.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap';
      riskyActions.forEach(a => {
        var btn = document.createElement('button');
        btn.className = 'exec-btn';
        btn.innerHTML = `${a.icon||''+window.icon("ai",14)+''} Approve: ${a.label}`;
        btn.onclick = () => {btn.disabled=true;btn.textContent='Executing…';_execAction(a, text, msg, card, actions.indexOf(a), resultBox);};
        riskBtns.appendChild(btn);
      });
      card.appendChild(riskBtns);
    }
  } else {
    // APPROVAL-FIRST: show approve/reject buttons
    var btnRow = document.createElement('div');
    btnRow.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap';

    var approveAll = document.createElement('button');
    approveAll.className = 'exec-btn';
    approveAll.style.cssText += ';background:var(--p);color:#fff;border-color:var(--p)';
    approveAll.innerHTML = '✓ Approve All';
    approveAll.onclick = async () => {
      approveAll.disabled=true;approveAll.textContent='Executing…';
      for(let i=0;i<actions.length;i++){await _execAction(actions[i], text, msg, card, i, resultBox);await new Promise(r=>setTimeout(r,1500));}
      btnRow.remove();
    };
    btnRow.appendChild(approveAll);

    var approveSafe = document.createElement('button');
    approveSafe.className = 'exec-btn';
    approveSafe.innerHTML = '✓ Approve Safe Only';
    approveSafe.onclick = async () => {
      approveSafe.disabled=true;approveSafe.textContent='Running safe…';
      for(let i=0;i<actions.length;i++){if(actions[i].isSafe){await _execAction(actions[i], text, msg, card, i, resultBox);await new Promise(r=>setTimeout(r,1500));}}
      approveSafe.textContent='✓ Safe done';approveSafe.style.color='var(--ac)';
    };
    btnRow.appendChild(approveSafe);

    var reject = document.createElement('button');
    reject.className = 'exec-btn';
    reject.style.color = '#F87171';
    reject.innerHTML = '✕ Reject';
    reject.onclick = async () => {
      card.style.opacity='.5';
      try{await post(API+'decisions',{type:'tool_proposal',agent_id:msg.agent_id||'system',title:'Rejected: '+actions.map(a=>a.label).join(', '),status:'rejected',tools:actions.map(a=>a.tool)});}catch(e){}
    };
    btnRow.appendChild(reject);

    card.appendChild(btnRow);
  }

  bubble.appendChild(card);
}

// ── Execute a single action (used by both modes) ─────────────────────────
async function _execAction(action, msgText, msg, card, idx, resultBox){
  var row = card.querySelector(`.exec-action-row[data-idx="${idx}"]`);
  var statusEl = row?.querySelector('.exec-row-status');
  if(statusEl){statusEl.textContent='running…';statusEl.style.color='var(--am)';}

  var params = action.paramFn ? action.paramFn(msgText) : {};
  try{
    var t0=Date.now();
    var result = await post(API+'tools/run', {tool_id:action.tool, params, agent_id:msg.agent_id||'user', rationale:msgText.slice(0,200), approval_status:'approved'});
    var dur=Date.now()-t0;

    // Log to decision log
    try{await post(API+'decisions',{type:'tool_execution',agent_id:msg.agent_id||'system',title:action.label,rationale:msgText.slice(0,200),status:'approved',tools:[action.tool]});}catch(e){}

    if(statusEl){
      if(result.status==='preview_created'){statusEl.textContent='preview';statusEl.style.color='var(--am)';}
      else if(result.success!==false){statusEl.textContent=''+window.icon("check",14)+' done';statusEl.style.color='var(--ac)';}
      else{statusEl.textContent=''+window.icon("close",14)+' failed';statusEl.style.color='#F87171';}
    }

    // Show result inline
    resultBox.style.display='flex';
    var res=document.createElement('div');
    res.className='exec-result';
    if(result.status==='preview_created'){
      res.innerHTML=`<span style="display:inline-flex;align-items:center;gap:6px">${window.icon('eye',14)} <strong>${action.label}</strong></span> — <a href="#" onclick="nav('previews');return false" style="color:var(--p)">Review in Previews →</a>`;
    }else if(result.success!==false){
      res.innerHTML=_fmtResult(action.tool, result.data);
    }else{
      res.className='exec-result err';
      res.textContent=result.data?.error||'Failed';
    }
    resultBox.appendChild(res);
  }catch(e){
    if(statusEl){statusEl.textContent=''+window.icon("close",14)+' error';statusEl.style.color='#F87171';}
  }
}

// ── Format tool results ──────────────────────────────────────────────────
function _fmtResult(toolId, data){
  if(!data) return ''+window.icon("check",14)+' Done.';
  if(toolId==='deep_audit'||toolId==='ai_report'){var s=data.score??data.seo_score??data.overall_score;var m=data.message||'';if(s) return `<strong>SEO Score: ${s}/100</strong><br>${esc(m.slice(0,300))}`;if(m) return esc(m.slice(0,400));}
  if(toolId==='scan_site_url') return `${window.icon('check',14)} <strong>${esc(data.title||'')}</strong> — ${data.word_count||0} words, ${data.headers_found||0} headers, ${data.internal_links||0} links`;
  if(toolId==='serp_analysis'){var m=data.message||data.analysis||'';return m?esc(m.slice(0,400)):'SERP analysis complete.';}
  if(toolId==='outbound_links') return `Total: <strong>${data.total||0}</strong> | Broken: <strong style="color:#F87171">${data.broken||0}</strong> | Redirects: ${data.redirects||0}`;
  if(toolId==='link_suggestions'){var c=data.count??data.suggestions?.length??0;return `<strong>${c}</strong> link suggestions.`;}
  if(toolId==='list_leads'){var c=data.total??data.count??(data.leads?.length||0);return `<strong>${c}</strong> leads.`;}
  if(toolId==='list_campaigns'){return `<strong>${data.campaigns?.length||0}</strong> campaigns.`;}
  if(toolId==='list_posts'){return `<strong>${data.posts?.length||data.total||0}</strong> posts.`;}
  if(toolId==='list_events'){return `<strong>${data.events?.length||data.total||0}</strong> events.`;}
  if(toolId==='list_builder_pages'){return `<strong>${data.pages?.length||0}</strong> pages.`;}
  if(toolId==='system_health_check') return `Status: <strong style="color:var(--ac)">${data.status||'ok'}</strong> | Runtime: ${data.runtime_status||'?'}`;
  var keys=Object.keys(data).filter(k=>!k.startsWith('_')&&k!=='success').slice(0,4);
  return keys.map(k=>`<strong>${k}:</strong> ${typeof data[k]==='object'?JSON.stringify(data[k]).slice(0,80):String(data[k]).slice(0,80)}`).join(' | ')||'Done.';
}
// ── Off-topic messages — creative interstitial ───────────────────────────────
var OFF_TOPIC_MESSAGES = [
  "Your agents are laser-focused on the mission at hand. Questions outside the meeting topic might get lost in the strategy fog. "+window.icon('ai',14)+"",
  "Your team is deep in execution mode. Off-topic questions are like sending a chef a weather report mid-service. 👨‍🍳",
  "Your agents have their game faces on. They live and breathe this topic — unrelated questions might get a blank stare. "+window.icon('more',14)+"",
  "The team is in the zone! Think of them as surgeons mid-operation — best to keep it relevant. 🔬",
  "Your agents are fully locked in on the strategy. Side quests not currently supported! ⚔️",
  "Heads-down mode activated. Your team is built for this topic — anything else might bounce off their focus shields. "+window.icon('lock',14)+"",
];

function isOffTopicMessage(content, topic) {
  var lower = content.toLowerCase().trim();

  // Always allow: commands, @mentions, very short directing messages
  if (lower.length < 8) return false;
  if (/@\w/.test(lower)) return false;
  if (/^(stop|pause|continue|go|next|ok|yes|no|thanks|great|perfect|proceed)\b/.test(lower)) return false;

  // Clear off-topic patterns
  var OFF_TOPIC_PATTERNS = [
    /\brain\b|\bweather\b|\btemperature\b|\bsunny\b|\bcloudy\b|\bforecast\b/i,
    /\bfood\b|\bhungry\b|\blunch\b|\bdinner\b|\brestaurant\b/i,
    /\bjoke\b|\bfunny\b|\btell me a\b/i,
    /\bwhat time is it\b|\bwhat.*date\b|\bwhat.*day is/i,
    /\bhow are you\b|\bhow'?s it going\b/i,
    /\bdo you like\b|\bfavorite\b|\bfavourite\b/i,
    /\bsports\b|\bfootball\b|\bbasketball\b|\bsoccer\b/i,
    /\bmovie\b|\bfilm\b|\bnetflix\b|\bseries\b/i,
  ];

  // Check if it matches off-topic patterns
  for (var pattern of OFF_TOPIC_PATTERNS) {
    if (pattern.test(lower)) return true;
  }

  // Check relevance to meeting topic (if topic is set)
  if (topic) {
    var topicWords = topic.toLowerCase().split(/\W+/).filter(w => w.length > 3);
    var msgWords   = lower.split(/\W+/).filter(w => w.length > 3);
    // If message has zero overlap with topic and is a question, likely off-topic
    var hasTopicOverlap = topicWords.some(tw => msgWords.some(mw => mw.includes(tw) || tw.includes(mw)));
    var isQuestion = /\?$|^(what|who|where|when|why|how|is|are|can|will|does|did)\b/.test(lower);
    if (!hasTopicOverlap && isQuestion && lower.length > 20) return true;
  }

  return false;
}

function showOffTopicDialog(onProceed, onCancel) {
  var msg = OFF_TOPIC_MESSAGES[Math.floor(Math.random() * OFF_TOPIC_MESSAGES.length)];

  // Remove any existing dialog
  document.getElementById('off-topic-dialog')?.remove();

  var overlay = document.createElement('div');
  overlay.id = 'off-topic-dialog';
  overlay.style.cssText = `
    position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;
    display:flex;align-items:center;justify-content:center;animation:fadeIn .2s ease;
  `;

  overlay.innerHTML =
    '<div style="background:var(--s2);border:1px solid var(--bd2);border-radius:16px;padding:28px 32px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)">' +
      '<div style="font-size:40px;margin-bottom:12px">'+window.icon("more",14)+'</div>' +
      '<div style="font-family:var(--fh);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:10px">Heads in the Game</div>' +
      '<div style="font-size:13px;color:var(--t2);line-height:1.7;margin-bottom:22px">' + msg + '</div>' +
      '<div style="display:flex;gap:10px;justify-content:center">' +
        '<button id="offtopic-cancel" style="background:var(--s3);border:1px solid var(--bd2);color:var(--t2);border-radius:8px;padding:9px 20px;font-size:13px;cursor:pointer;font-family:var(--fh)">Got it</button>' +
        '<button id="offtopic-proceed" style="background:var(--p);border:none;color:#fff;border-radius:8px;padding:9px 20px;font-size:13px;cursor:pointer;font-family:var(--fh);font-weight:600">Send anyway</button>' +
      '</div>' +
    '</div>';

  document.body.appendChild(overlay);

  document.getElementById('offtopic-cancel').onclick  = () => { overlay.remove(); onCancel(); };
  document.getElementById('offtopic-proceed').onclick = () => { overlay.remove(); onProceed(); };
  overlay.onclick = (e) => { if (e.target === overlay) { overlay.remove(); onCancel(); } };
}

async function sendMessage(){
  var ta=document.getElementById('cmd-input');
  let content=ta.value.trim();
  if(!content||!mid||busy)return;

  // ── Autopilot mode detection ────────────────────────────────────────
  var lc = content.toLowerCase().trim();
  if(/\b(?:go\s+on\s+autopilot|enable\s+autopilot|autopilot\s+on|activate\s+autopilot)\b/i.test(lc)){
    _setMode('autopilot');
    // Still send the message so agents know
  }
  if(/\b(?:stop\s+autopilot|pause\s+autopilot|disable\s+autopilot|back\s+to\s+approval|approval\s+mode|wait\s+for\s+(?:my\s+)?approval)\b/i.test(lc)){
    _setMode('approval');
  }

  // ── Auto-detect mentions without @ prefix ──────────────────────────
  // Users naturally type "Hi everyone" or "James, what do you think?"
  // Runtime requires @everyone/@james — normalize before sending.
  var mentionNorm = [
    [/\beveryone\b/i, '@Everyone'],
    [/\ball agents?\b/i, '@Everyone'],
    [/\bteam\b(?!\s*(?:of|building|work|member|meeting))/i, '@Everyone'],
  ];
  var nameNorm = [
    [/\bsarah\b/i, '@Sarah'], [/\bjames\b/i, '@James'],
    [/\bpriya\b/i, '@Priya'],
    [/\belena\b/i, '@Elena'], [/\balex\b/i, '@Alex'],
  ];
  // Only normalize if no @ already present
  if(!content.includes('@')){
    for(var [rx,repl] of mentionNorm){ if(rx.test(content)){ content=content.replace(rx,repl); break; } }
    // Name detection for direct address patterns: "James, ..." or "Hey Priya ..."
    if(!content.includes('@')){
      for(var [rx,repl] of nameNorm){ if(rx.test(content)){ content=content.replace(rx,repl); break; } }
    }
  }

  // Check for off-topic before sending
  var topic = document.getElementById('mtg-topic')?.textContent || '';
  if (isOffTopicMessage(content, topic)) {
    showOffTopicDialog(
      // "Send anyway" — proceed with original content
      async () => {
        ta.value='';ta.style.height='auto';busy=true;
        var umid='u_'+Date.now();
        var umsg={agent_id:'user',name:'You',title:'',emoji:'👤',color:'var(--t2)',role:'user',content,_umid:umid,timestamp:new Date().toISOString(),_afterRedis:_redisCount};
        localUserMsgs.push(umsg);
        renderMsg(umsg);
        try{await post(API+'meeting/'+mid+'/message',{content});}catch(e){showMsgErr('Send failed: '+e.message);}finally{busy=false;}
      },
      // "Got it" — clear the input, don't send
      () => { ta.focus(); }
    );
    return;
  }

  ta.value='';ta.style.height='auto';busy=true;
  var umid='u_'+Date.now();
  var umsg={agent_id:'user',name:'You',title:'',emoji:'👤',color:'var(--t2)',role:'user',content,_umid:umid,timestamp:new Date().toISOString(),_afterRedis:_redisCount};
  localUserMsgs.push(umsg);
  renderMsg(umsg);
  try{await post(API+'meeting/'+mid+'/message',{content});}catch(e){showMsgErr('Send failed: '+e.message);}finally{busy=false;}
}
async function wrapUp(){if(!mid)return;document.getElementById('btn-wrap').disabled=true;try{await post(API+'meeting/'+mid+'/wrap',{topic:document.getElementById('mtg-topic').textContent});}catch(e){showMsgErr(e.message);}}
async function downloadTranscript(){
  var d=await get(API+'meeting/'+mid+'/status');
  var lines=[`LevelUpGrowth — Strategy Session\nTopic: ${d.topic}\nDate: ${new Date().toLocaleString()}\n\n${'─'.repeat(60)}\n\n`];
  (d.messages||[]).forEach(m=>{lines.push(`${m.name.toUpperCase()}\n${m.content}\n\n`);});
  var a=Object.assign(document.createElement('a'),{href:URL.createObjectURL(new Blob([lines.join('')],{type:'text/plain'})),download:`strategy-${mid}.txt`});a.click();
}
function exitMeeting(){
  clearInterval(pollT);mid=null;seen=0;done=false;busy=false;lastSpk=null;lastPhase=null;_redisCount=0;localUserMsgs=[];
  spoken.clear();phaseLog.clear();
  Object.keys(AGENTS).forEach(id=>stopAgentActivity(id));
  document.getElementById('mtg-start-screen').style.display='flex';
  document.getElementById('mtg-active').style.display='none';
  document.getElementById('disc-feed').innerHTML='';
  document.getElementById('btn-wrap').disabled=true;
  document.getElementById('live-dot').classList.remove('on');
  PHASES.forEach(p=>{var el=document.getElementById('ps-'+p);if(el)el.classList.remove('active','done');});
  Object.keys(AGENTS).forEach(id=>{var el=document.getElementById('mac-'+id),st=document.getElementById('mst-'+id);if(el)el.classList.remove('speaking','done');if(st){st.className='mac-st st-idle';st.textContent='Waiting';}});
  nav('workspace');
}
function showMsgErr(msg){var d=document.createElement('div');d.style.cssText='background:rgba(248,113,113,.06);border:1px solid rgba(248,113,113,.2);border-radius:var(--r);padding:10px 13px;color:#F87171;font-size:12px;animation:fadeUp .3s ease;display:flex;align-items:center;gap:6px';d.innerHTML=window.icon('warning',14)+'<span>'+esc(msg)+'</span>';document.getElementById('disc-feed').appendChild(d);scrollFeed();}

// ── Utils ──────────────────────────────────────────────────────────────────
function setEl(id,val){var e=document.getElementById(id);if(e)e.textContent=val;}

// ── Shared escape function (was in builder utils, needed by core views) ──
function esc(t){return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function escH(t){return esc(t).replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
// removed duplicate fmt — see line 2525

// PATCH (chat-markdown, 2026-05-09) — fmt() now handles bullet points
// (lines starting with `- ` or `• `). Consecutive bullet lines collapse
// into a single <ul>. Italic via *single-asterisk*. Order matters:
// process bullets BEFORE \n -> <br> conversion so list items stay
// structured.
function fmt(t){
  var s = _bldSafeText(t)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
    .replace(/(^|[\s])\*([^*\n]+)\*/g,'$1<em>$2</em>')
    .replace(/^## (.+)$/gm,'<h2>$1</h2>')
    .replace(/^### (.+)$/gm,'<h3 style="font-size:11px;color:var(--bl);margin:10px 0 4px;font-weight:700">$1</h3>');
  // Bullet handling: convert each `- ` or `• ` line to <li>, then wrap
  // contiguous <li>...</li> runs in a <ul>.
  s = s.replace(/^[\-•]\s+(.+)$/gm,'<li>$1</li>');
  s = s.replace(/(<li>[\s\S]*?<\/li>)(\s*<li>[\s\S]*?<\/li>)*/g, function(m){
    return '<ul style="margin:6px 0 8px;padding-left:18px;line-height:1.55">' + m + '</ul>';
  });
  // Newlines AFTER bullets are converted (don't insert <br> inside <ul>)
  // v1.4.4 — replace the old <br><br> trick (which renders as a tight,
  // barely-visible gap) with a real paragraph spacer so multi-paragraph
  // agent replies actually read as separate paragraphs in the drawer
  // and messages surfaces. Single \n inside a paragraph stays as <br>.
  s = s.replace(/<\/ul>\n+/g,'</ul>')
       .replace(/\n{2,}/g,'<div style="height:10px" aria-hidden="true"></div>')
       .replace(/\n/g,'<br>');
  return s;
}
async function post(url,data){var r=await fetch(url,{method:'POST',headers:_lgscAuthForFetch({'Content-Type':'application/json','Accept':'application/json'}),body:JSON.stringify(data)});var d=await r.json();if(!r.ok){if(d.code==='PLAN_GATED'||d.code==='NO_CREDITS'){showPlanGate(d.error||d.message||'This feature requires a plan upgrade.');return d;}var __pe=new Error(d.message||d.error||'Request failed');__pe.status=r.status;__pe.body=d;throw __pe;}return d;}
// ── PLATFORM888 Phase 2: in-flight GET coalescing (shared request registry) ──
// Concurrent GETs to the same URL within the SAME workspace reuse ONE in-flight
// Promise instead of issuing parallel requests. COALESCING ONLY — the registry
// entry is deleted the instant the request settles (success OR failure), so a
// failed request never poisons future calls and nothing is cached long-term.
// POST/PUT/DELETE are never coalesced (mutations, auth/refresh). Auth is
// preserved (each real request still sends current headers); tenancy is
// preserved (the key includes lu_workspace_id, so a workspace switch never
// shares a response across tenants).
window.__luInflight = window.__luInflight || new Map();
window.__luCoalesceStats = window.__luCoalesceStats || { hits: 0, misses: 0 };
function _luCoalesceGet(url, doFetch){
  var key = 'GET ' + url + ' ws=' + (localStorage.getItem('lu_workspace_id') || '');
  var m = window.__luInflight;
  if (m.has(key)) { window.__luCoalesceStats.hits++; return m.get(key); }
  window.__luCoalesceStats.misses++;
  var tracked = Promise.resolve().then(doFetch).then(
    function(v){ m.delete(key); return v; },
    function(e){ m.delete(key); throw e; }
  );
  m.set(key, tracked);
  return tracked;
}
async function get(url){ return _luCoalesceGet(url, function(){ return fetch(url,{cache:'no-store',headers:_lgscAuthForFetch({'Accept':'application/json'})}).then(function(r){ return r.json(); }); }); }

// ── File upload ────────────────────────────────────────────────────────────
let pendingAttachments = [];
function triggerUpload(){ document.getElementById('file-input')?.click(); }
async function handleFileUpload(e){
  var file = e.target.files[0]; if(!file||!mid) return;
  var prev = document.getElementById('upload-preview');
  prev.style.display='flex';
  prev.innerHTML=`<div class="spinner" style="width:14px;height:14px;border-width:1.5px"></div><span>Uploading ${esc(file.name)}…</span>`;
  try{
    var fd = new FormData(); fd.append('file', file);
    var r = await fetch(API+'meeting/'+mid+'/upload', {
      method:'POST', headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||'')}, body:fd,
    });
    var d = await r.json();
    if(!r.ok) throw new Error(d.error||'Upload failed');
    pendingAttachments.push(d.file);
    var isImg = d.file.type?.startsWith('image/');
    prev.innerHTML=`<span style="display:inline-flex;align-items:center;gap:6px">${isImg?window.icon('image',14):window.icon('attach',14)} <strong>${esc(d.file.name)}</strong></span> ready — ${isImg?'team will analyse this image':'file attached'} <span onclick="clearUpload()" style="cursor:pointer;opacity:.6;margin-left:8px">✕</span>`;
    // Show preview if image
    if(isImg){
      var img = document.createElement('img');
      img.src = d.file.url; img.style.cssText='max-width:120px;max-height:60px;border-radius:6px;margin-left:8px;border:1px solid var(--bd)';
      prev.appendChild(img);
    }
  }catch(err){
    prev.innerHTML=`<span style="color:var(--rd);display:inline-flex;align-items:center;gap:5px">${window.icon('warning',13)} ${esc(err.message)}</span>`;
    setTimeout(()=>{prev.style.display='none';},3000);
  }
  e.target.value=''; // reset
}
function clearUpload(){
  pendingAttachments=[];
  var p=document.getElementById('upload-preview');
  if(p){p.style.display='none';p.innerHTML='';}
}

// ── Vision analysis message render ─────────────────────────────────────────
// Handled by renderMsg — role=vision_analysis gets a special badge

// ── Patch sendMessage to include attachments ───────────────────────────────
var _origSend = sendMessage;
sendMessage = async function(){
  var ta=document.getElementById('cmd-input');
  var content=ta.value.trim();
  // If we have attachments and no text, use a default caption
  var caption = content || (pendingAttachments.length ? 'Analyse this' : '');
  if((!caption&&!pendingAttachments.length)||!mid||busy) return;
  ta.value=''; ta.style.height='auto'; busy=true;
  if(caption) renderMsg({agent_id:'user',name:'You',title:'',emoji:'👤',color:'var(--t2)',role:'user',content:caption,attachments:pendingAttachments});
  try{
    await post(API+'meeting/'+mid+'/message',{content:caption||'Analyse the uploaded file.',attachments:pendingAttachments});
    clearUpload();
  }catch(err){showMsgErr('Send failed: '+err.message);}finally{busy=false;}
};

// Patch renderMsg to show vision analysis badge and file attachments
var _origRender = renderMsg;
renderMsg = function(msg){
  // Add vision badge rendering
  if(msg.role==='vision_analysis'){
    var c=AGENTS[msg.agent_id]?.color||'var(--t2)',em=AGENTS[msg.agent_id]?.emoji||'🔍';
    var div=document.createElement('div');
    div.className='msg-card'; div.style.animation='fadeUp .3s ease';
    div.innerHTML=`<div class="msg-av" style="background:transparent;border:none">${(!isU&&typeof buildAgentOrb==="function")?buildAgentOrb(msg.agent_id,"sm",isSyn?"success":"idle"):em}</div><div class="msg-body"><div class="msg-meta"><span class="msg-name" style="color:${c}">${msg.name}</span><span class="msg-title-lbl">${msg.title}</span><span class="msg-badge" style="background:rgba(0,229,168,.1);color:var(--ac);border:1px solid rgba(0,229,168,.25)">Vision</span>${msg.analyzed_file?`<span class="msg-title-lbl" style="display:inline-flex;align-items:center;gap:4px">${window.icon('attach',12)} ${esc(msg.analyzed_file)}</span>`:''}</div><div class="msg-bubble">${fmt(msg.content)}</div></div>`;
    document.getElementById('disc-feed').appendChild(div); scrollFeed();
    return;
  }
  // Show attachments in user messages
  if(msg.attachments?.length && msg.role==='user'){
    msg.content += msg.attachments.map(a=>a.type?.startsWith('image/')?`\n<img src="${esc(a.url)}" style="max-width:200px;border-radius:6px;margin-top:6px;border:1px solid var(--bd);display:block">`:`\n<a href="${esc(a.url)}" target="_blank" style="color:var(--ac);font-size:10px;display:inline-flex;align-items:center;gap:4px">${window.icon('attach',12)} ${esc(a.name)}</a>`).join('');
  }
  _origRender(msg);
};

// ── DM Modal ────────────────────────────────────────────────────────────────
let dmAgentId = null;
let dmModal   = null;

function openDmModal(agentId, name, role, emoji, color, cardEl) {
    if (!mid) return; // only works inside an active meeting
    dmAgentId = agentId;
    var modal = document.getElementById('dm-modal');
    dmModal = modal;

    // Populate header
    var av = document.getElementById('dm-av');
    av.textContent  = emoji;
    av.style.cssText = `background:${color}22;border:1px solid ${color}44`;
    setEl('dm-name', name);
    setEl('dm-role', role);

    // Reset body
// Core shared functions restored from extraction
    var modal = document.getElementById('dm-modal');
    if (modal && !modal.contains(e.target) && !e.target.closest('.mac')) {
        closeDmModal();
    }
}

function closeDmModal() {
    var modal = document.getElementById('dm-modal');
    if (modal) modal.style.display = 'none';
    dmAgentId = null;
    document.removeEventListener('click', dmOutsideClick);
}

async function sendDm() {
    if (!dmAgentId || !mid) return;
    var ta  = document.getElementById('dm-ta');
    var btn = document.getElementById('dm-send');
    var content = ta?.value?.trim();
    if (!content) return;

    if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
    // v1.4.4 attach — fold any uploaded attachments into the DM payload, then clear chips
    var _dmPayload = { agentId: dmAgentId, content: content };
    if (window.LU_attachComposer) {
      var _dmAtts = window.LU_attachComposer.getPending('dm-ta');
      if (_dmAtts && _dmAtts.length) _dmPayload.attachments = _dmAtts;
      window.LU_attachComposer.clear('dm-ta');
    }
    try {
        await post(API + 'meeting/' + mid + '/dm', _dmPayload);
        // Show sent confirmation then close
        var body = document.getElementById('dm-body');
        if (body) body.innerHTML = `<div class="dm-sent">✓ Message sent to ${document.getElementById('dm-name')?.textContent || 'agent'}.<br><span style="color:var(--t3);font-size:9px">Reply will appear in the main feed.</span></div>`;
        setTimeout(closeDmModal, 1800);
    } catch(e) {
        if (btn) { btn.disabled = false; /* icon button: nothing to re-label */ }
        console.error('DM failed:', e);
    }
}

// Close DM modal on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDmModal(); });

// ── Direct assign modal ─────────────────────────────────────────────────────
function openDirectAssign(){
  if(!selectedAgents.size) return;
  var chips=document.getElementById('da-chips');
  chips.innerHTML=[...selectedAgents].map(id=>{
    var a=AGENTS[id]||{};
    return `<div class="da-chip">${a.emoji||'👤'} ${a.name||id}</div>`;
  }).join('');
  var sub=document.getElementById('da-sub');
  if(sub) sub.textContent=`Assigning to: ${[...selectedAgents].map(id=>AGENTS[id]?.name||id).join(', ')} — created immediately.`;
  document.getElementById('da-title').value='';
  document.getElementById('da-desc').value='';
  document.getElementById('da-metric').value='';
  document.getElementById('da-priority').value='medium';
  document.getElementById('da-time').value='60';
  document.getElementById('da-backdrop').classList.add('visible');
}
function closeDirectAssign(){
  document.getElementById('da-backdrop').classList.remove('visible');
}
async function createDirectTask(){
  // 2026-05-26 — REWRITE. Previously this built a TASK BRIEF markdown and
  // sent it as a chat message to Sarah, relying on her LLM to emit
  // create_tasks. Result: button labeled "Create Task" but no task was
  // ever created; user redirected to Sarah's chat with no action. User
  // expectation: button creates the task immediately with the selected
  // agents as assignees.
  //
  // New behavior: POST directly to /api/tasks with the selected agents,
  // a sensible engine derived from the first specialist, action=manual_brief,
  // requires_approval=false, credit_cost=0. Task lands as 'pending' →
  // visible on canvas → triggers a new-chain flash on the agent connection
  // line (Fix 3 from canvas-line patch).
  var title=document.getElementById('da-title').value.trim();
  if(!title||!selectedAgents.size){showToast('Task title and at least one agent are required.','warning');return;}
  var assignees=[...selectedAgents];
  var desc=document.getElementById('da-desc').value.trim();
  var priority=document.getElementById('da-priority').value;
  var estTime=parseInt(document.getElementById('da-time').value)||60;
  var metric=document.getElementById('da-metric').value.trim();

  // Normalize UI 'dmm' back to backend 'sarah'
  var realAssignees=assignees.map(function(a){return a==='dmm'?'sarah':a;});

  // Map agent slug → engine. Used for the task's engine field, which is
  // required by the API. We pick the FIRST non-orchestrator agent's
  // engine; if only Sarah is selected, fall back to 'write' (her default
  // delegation target). The action is generic 'manual_brief' so the
  // orchestrator doesn't auto-execute the task — it sits as a human-
  // managed TODO until someone picks it up.
  var engineMap={
    james:'seo',alex:'seo',diana:'seo',ryan:'seo',sofia:'seo',
    priya:'write',nora:'write',
    elena:'crm',max:'crm',sarah:'write'
  };
  var firstSpec=realAssignees.find(function(a){return a!=='sarah';})||realAssignees[0];
  var engine=engineMap[firstSpec]||'write';

  // Disable the submit button to prevent double-post
  var btn=document.getElementById('da-submit')||document.querySelector('#da-backdrop button.da-btn-primary');
  if(btn){btn.disabled=true;btn.textContent='Creating...';}

  try{
    var res=await post(API+'tasks',{
      engine:engine,
      action:'manual_brief',
      source:'manual',
      assigned_agents:realAssignees,
      priority:priority==='medium'?'normal':priority,
      requires_approval:false,
      credit_cost:0,
      payload:{
        title:title,
        description:desc||'',
        priority:priority,
        estimated_time:estTime,
        success_metric:metric||'',
        created_via:'manual_direct_assign',
        // 2026-05-26 — unique-suffix so the idempotency hash differs between
        // identical-titled direct posts. Without this, posting the same
        // task title twice causes a 500 (DB duplicate key on idempotency_key
        // unique index). Manual posts should never be auto-dedup'd.
        client_ts: Date.now(),
        nonce: Math.random().toString(36).slice(2, 10),
      },
    });
    if(res&&(res.task||res.id)){
      showToast('Task created: '+title+' → '+assignees.map(function(id){return AGENTS[id]&&AGENTS[id].name||id;}).join(', '),'success');
      closeDirectAssign();
      clearSelection();
      // Refresh the canvas — this triggers the line-draw + new-chain flash.
      if(typeof loadTasks==='function') await loadTasks();
      if(typeof loadAgentStats==='function') loadAgentStats();
    }else{
      throw new Error('unexpected response: '+JSON.stringify(res).slice(0,200));
    }
  }catch(e){
    console.error('[createDirectTask] failed:',e);
    showToast('Task creation failed: '+(e&&e.message||e),'error');
    if(btn){btn.disabled=false;btn.textContent='Create Task';}
  }
}

function startMeetingWithSelected(){
  // Pre-populate meeting topic with selected agents, navigate to Strategy Room
  var names=[...selectedAgents].map(id=>AGENTS[id]?.name||id).join(', ');
  clearSelection();
  nav('meeting');
  var ti=document.getElementById('topic-input');
  if(ti && !ti.value) ti.focus();
}

function analyzeWorkload(){
  // Show workload summary in a simple alert for now (Sprint G: modal)
  var lines=[...selectedAgents].map(id=>{
    var a=AGENTS[id]||{};
    var active=allTasks.filter(t=>(t.assignee===id||(t.assignees||[]).includes(id))&&(t.status==='ongoing'||t.status==='in_progress'||t.status==='upcoming')).length;
    var state=active===0?'Available':active<=3?'Moderate ('+active+' tasks)':'Overloaded ('+active+' tasks)';
    return `${a.emoji||''} ${a.name||id}: ${state}`;
  });
  luAlert("Workload Summary", lines.join("\n"));
}

// ── Init ───────────────────────────────────────────────────────────────────
// [builder] extracted to builder.js (lines 2754-2791)

// ══════════════════════════════════════════════════════════════
// GLOBAL AI ASSISTANT
// ══════════════════════════════════════════════════════════════

let aiOpen = false;
let aiHistory = [];
let aiBusy = false;
let aiUnread = 0;

var AI_QUICK_BY_VIEW = {
  workspace: [
    [''+window.icon("info",14)+' Who\'s overloaded?',       'Which agents are overloaded?'],
    [''+window.icon("more",14)+' Active tasks',             'What tasks are active right now?'],
    [''+window.icon("chart",14)+' Platform status',          'Give me a quick platform status summary'],
  ],
  meeting: [
    [''+window.icon("edit",14)+' Summarize meeting',        'Summarize this meeting so far'],
    [''+window.icon("check",14)+' What was agreed?',          'What has the team agreed on so far?'],
    [''+window.icon("ai",14)+' Action items',              'List the action items from this session'],
  ],
  projects: [
    [''+window.icon("warning",14)+' Behind schedule?',         'Which projects or tasks are behind schedule?'],
    [''+window.icon("more",14)+' In progress',              'List all tasks currently in progress'],
    [''+window.icon("back",14)+' High priority',            'Show me all high priority tasks'],
  ],
  agents: [
    [''+window.icon("more",14)+' Workload overview',        'Give me a workload overview of all agents'],
    [''+window.icon("star",14)+' Most active',              'Which agent has the most active tasks?'],
  ],
  reports: [
    [''+window.icon("chart",14)+' Performance summary',      'Summarize overall platform performance'],
    [''+window.icon("check",14)+' Completed work',           'What has been completed recently?'],
  ],
};

function toggleAssistant() { return; /* P4-U1: Aria retired */
  aiOpen = !aiOpen;
  document.getElementById('ai-panel').classList.toggle('open', aiOpen);
  document.getElementById('ai-fab').classList.toggle('open', aiOpen);
  if (aiOpen) {
    aiUnread = 0;
    var badge = document.getElementById('ai-fab-badge');
    if (badge) badge.classList.remove('visible');
    updateAiContext();
    document.getElementById('ai-input')?.focus();
  }
}

function updateAiContext() {
  var labels = {workspace:'Workspace',meeting:'Strategy Room',projects:'Projects',agents:'Agents',reports:'Reports & History'};
  var lbl = document.getElementById('ai-ctx-label');
  if (lbl) lbl.textContent = (labels[currentView]||currentView) + ' · ' + Object.keys(AGENTS).length + ' agents';

  // Update quick actions
  var quick = document.getElementById('ai-quick');
  if (!quick) return;
  var btns = AI_QUICK_BY_VIEW[currentView] || AI_QUICK_BY_VIEW.workspace;
  quick.innerHTML = btns.map(([label, prompt]) =>
    `<button class="ai-q-btn" onclick="aiQuick(${JSON.stringify(prompt).replace(/"/g,'&quot;')})">${label}</button>`
  ).join('');
}

function aiQuick(prompt) {
  var inp = document.getElementById('ai-input');
  if (inp) { inp.value = prompt; inp.style.height = 'auto'; }
  sendAssistant();
}

function buildAiContext() {
  var tasks = allTasks || [];
  var workload = Object.keys(AGENTS).map(id => {
    var active = tasks.filter(t =>
      (t.assignee === id || (t.assignees||[]).includes(id)) &&
      ['ongoing','in_progress','upcoming'].includes(t.status)
    ).length;
    var state = active === 0 ? 'available' : active <= 3 ? 'moderate' : 'overloaded';
    return { id, name: AGENTS[id]?.name||id, active, state };
  });
  var ctx = { view: currentView, workload };
  // Inject meeting context if in meeting view
  if (currentView === 'meeting' && mid) {
    ctx.meeting = { topic: document.getElementById('mtg-topic')?.textContent||'', message_count: document.querySelectorAll('.disc-msg').length };
  }
  return ctx;
}

function aiAddMsg(role, content, opts={}) {
  var feed = document.getElementById('ai-feed');
  if (!feed) return;

  var wrap = document.createElement('div');
  wrap.className = `ai-msg ai-msg-${role}`;

  if (role === 'assistant' || role === 'agent') {
    var name = opts.agentName ? `${opts.agentEmoji||'✦'} ${opts.agentName}` : '✦ Assistant';
    var color = opts.agentColor ? `style="color:${opts.agentColor}"` : '';
    wrap.innerHTML = `<span class="ai-msg-label" ${color}>${name}</span><div class="ai-msg-bubble" ${opts.agentColor?`style="border-color:${opts.agentColor}33"`:''}>${fmt(content)}</div>`;
  } else {
    wrap.innerHTML = `<span class="ai-msg-label">You</span><div class="ai-msg-bubble">${esc(content)}</div>`;
  }

  // If there's a tool action to show
  if (opts.toolCall) {
    var tc = opts.toolCall;
    var toolLabels = {
      assign_task:         ['＋','Assign Task',       `To: ${(tc.params?.assignees||[]).join(', ')} — "${tc.params?.title||''}"`],
      start_meeting:       [''+window.icon("more",14)+'','Start Meeting',      `Topic: "${tc.params?.topic||''}"`],
      navigate:            ['→', 'Navigate',           `Go to ${tc.params?.view||''}`],
      show_agent_workload: [''+window.icon("chart",14)+'','Show Workload',       'Viewing agent workload'],
      list_tasks:          [''+window.icon("more",14)+'','List Tasks',         'Filtering task list'],
      summarize_meeting:   [''+window.icon("edit",14)+'','Summarize Meeting',  'Pulling session summary'],
    };
    var [icon, label, desc] = toolLabels[tc.tool] || [''+window.icon("ai",14)+'', tc.tool, ''];
    var actionDiv = document.createElement('div');
    actionDiv.className = 'ai-tool-action';
    actionDiv.innerHTML = `<span class="ata-icon">${icon}</span><div><div class="ata-label">${label}</div><div class="ata-desc">${desc}</div></div>`;
    actionDiv.onclick = () => executeAiTool(tc);
    wrap.appendChild(actionDiv);
  }

  feed.appendChild(wrap);
  wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function aiShowTyping() {
  var feed = document.getElementById('ai-feed');
  if (!feed) return;
  var d = document.createElement('div');
  d.id = 'ai-typing';
  d.className = 'ai-msg ai-msg-assistant';
  d.innerHTML = `<span class="ai-msg-label">✦ Assistant</span><div class="ai-typing"><div class="ai-typing-d"></div><div class="ai-typing-d"></div><div class="ai-typing-d"></div></div>`;
  feed.appendChild(d);
  feed.scrollTop = feed.scrollHeight;
}
function aiHideTyping() { document.getElementById('ai-typing')?.remove(); }

async function sendAssistant() { return; /* P4-U1: Aria retired */
  var inp = document.getElementById('ai-input');
  var message = inp?.value?.trim();
  if (!message || aiBusy) return;

  inp.value = ''; inp.style.height = 'auto';
  aiBusy = true;
  document.getElementById('ai-send').disabled = true;

  aiAddMsg('user', message);
  aiHistory.push({ role:'user', content: message });
  aiShowTyping();

  // Open panel if closed (command via keyboard shortcut etc.)
  if (!aiOpen) toggleAssistant();

  try {
    var ctx = buildAiContext();
    // v1.4.4 attach — fold any uploaded attachments into the assistant payload, then clear chips
    var _aiPayload = { message: message, context: ctx, history: aiHistory.slice(-8) };
    if (window.LU_attachComposer) {
      var _aiAtts = window.LU_attachComposer.getPending('ai-input');
      if (_aiAtts && _aiAtts.length) _aiPayload.attachments = _aiAtts;
      window.LU_attachComposer.clear('ai-input');
    }
    var r = await post(API + 'assistant', _aiPayload);
    // Wave 31 — Update Aria's chat counter from response JSON.
    try {
      var cm = (r && r.chat_meter) || (r && r.data && r.data.chat_meter);
      if (cm && typeof window._lgseUpdateChatMeter === 'function') {
        window._lgseUpdateChatMeter(cm.counter, !!cm.debited);
      }
    } catch (_e) {}
    aiHideTyping();

    var resp = r.response || '';
    var opts = {};

    if (r.agent_response) {
      opts.agentName  = r.agent_name;
      opts.agentEmoji = r.agent_emoji;
      opts.agentColor = r.agent_color;
    }
    if (r.tool_call) {
      opts.toolCall = r.tool_call;
      // Auto-execute non-destructive navigations silently
      if (r.tool_call.tool === 'navigate') executeAiTool(r.tool_call);
    }

    if (resp) {
      aiAddMsg(r.agent_response ? 'agent' : 'assistant', resp, opts);
      aiHistory.push({ role:'assistant', content: resp });
    } else if (r.tool_call && !resp) {
      aiAddMsg('assistant', `I'll ${(r.tool_call.tool||'').replace(/_/g,' ')} that for you.`, opts);
    }

    // Unread badge if panel closed
    if (!aiOpen) {
      aiUnread++;
      var badge = document.getElementById('ai-fab-badge');
      if (badge) { badge.textContent = aiUnread; badge.classList.add('visible'); }
    }
  } catch(e) {
    aiHideTyping();
    aiAddMsg('assistant', ''+window.icon("warning",14)+' ' + (e.message||'Something went wrong. Please try again.'));
  } finally {
    aiBusy = false;
    document.getElementById('ai-send').disabled = false;
  }
}

function executeAiTool(tc) {
  if (!tc?.tool) return;
  switch(tc.tool) {
    case 'assign_task': {
      var assignees = tc.params?.assignees || [];
      assignees.forEach(id => { selectedAgents.add(id); document.getElementById('node-'+id)?.classList.add('selected'); });
      updateSelectionToolbar();
      if (currentView !== 'workspace') nav('workspace');
      setTimeout(() => {
        openDirectAssign();
        if (tc.params?.title)       setTimeout(()=>{ var el=document.getElementById('da-title'); if(el) el.value=tc.params.title; },100);
        if (tc.params?.description) setTimeout(()=>{ var el=document.getElementById('da-desc');  if(el) el.value=tc.params.description; },100);
        if (tc.params?.priority)    setTimeout(()=>{ var el=document.getElementById('da-priority'); if(el) el.value=tc.params.priority; },100);
      }, currentView !== 'workspace' ? 400 : 50);
      break;
    }
    case 'start_meeting': {
      nav('meeting');
      if (tc.params?.topic) setTimeout(()=>{ var el=document.getElementById('topic-input'); if(el){el.value=tc.params.topic;el.style.height='auto';el.style.height=Math.min(el.scrollHeight,120)+'px';} },300);
      break;
    }
    case 'navigate': {
      var v = tc.params?.view;
      if (v && ['workspace','meeting','projects','agents','reports'].includes(v)) nav(v);
      break;
    }
    case 'show_agent_workload': {
      nav('workspace');
      break;
    }
    case 'list_tasks': {
      nav('projects');
      break;
    }
    case 'summarize_meeting': {
      if (currentView !== 'meeting') nav('meeting');
      break;
    }
  }
}
// ════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════
// BUILDER GLOBALS — must be declared BEFORE the builder IIFE runs
// ═══════════════════════════════════════════════════════════════════
var bldNonce = (window.LU_CFG ? window.LU_CFG.nonce : '');
var wpNonce = bldNonce;
// ── ensureArray: normalise API responses that should be arrays ──────────────
// Handles PHP→JSON object {} instead of array [] when array keys are non-sequential.
function ensureArray(val) {
  if (Array.isArray(val)) return val;
  if (val && typeof val === 'object') return Object.values(val);
  return [];
}

var bldPages = [];
var bldCurrentPageId = null;
var bldCurrentPage = null;
var bldSelectedComp = null;
var bldDevice = 'desktop';
var bldDirty = false;
var bldLibraryItems = [];
var bldSavedItems = [];
var bldFilter = 'all';
var _bldWebsitePages = [];
var _bldSelSec = null;
var _bldSelCmp = null;
var _bldPageSource = 'standalone';
var _arthurSelection = null;
var _bldRealtimeTimer = null;
var arthurContext = null;
// External engine plugins register their loader JS via:
//   GET /api/engines/{slug}/loader
// The loader JS must call:
//   window._lu_engine_loaders['slug'] = async function(rootEl) { ... }
// Core built-in engines (crm, marketing, social, calendar, automation)
// are already registered in nav() — this only fetches external ones.
window._lu_engine_loaders = {};

document.addEventListener('DOMContentLoaded', function() { window.luDefer(async function() {
  try {
    // Fix: use authHeader() spread so X-WP-Nonce is sent correctly in WP Admin context
    var res = await fetch(window.luApi + 'engines', { headers: authHeader() });
    // Fix: guard against HTML 401/403 responses before parsing JSON
    if (!res.ok) {
      console.warn('[LevelUp] Engine bootstrap: /api/engines returned ' + res.status + ' — check authentication.');
      return;
    }
    var data = await safeJson(res);
    if (!data) return;
    var engines = data.engines || [];
    var BUILTIN = new Set(['crm','marketing','social','calendar','automation','builder','websites','governance','seo']);

    for (var eng of engines) {
      if (BUILTIN.has(eng.slug)) continue;  // already handled by nav()
      // Fetch engine loader metadata and load via safe script tag injection
      try {
        var lr = await fetch(window.luApi + 'engines/' + eng.slug + '/loader', { headers: authHeader() });
        if (!lr.ok) { console.warn('[LevelUp] Engine loader ' + eng.slug + ' returned ' + lr.status); continue; }
        var ld = await safeJson(lr);
        if (!ld) continue;
        if (ld.loader_url && typeof ld.loader_url === 'string') {
          // Preferred: load engine JS from a URL via script tag (CSP-safe)
          await new Promise(function(ok, fail) {
            var s = document.createElement('script');
            s.src = ld.loader_url;
            s.onload = ok;
            s.onerror = function() { fail(new Error('Failed to load ' + ld.loader_url)); };
            document.head.appendChild(s);
          });
          console.log('[LevelUp] Engine loader registered (URL):', eng.slug);
        } else if (ld.js && typeof ld.js === 'string') {
          // Fallback: load inline JS via Blob URL script tag (no eval/new Function)
          var blob = new Blob([ld.js], { type: 'application/javascript' });
          var blobUrl = URL.createObjectURL(blob);
          await new Promise(function(ok, fail) {
            var s = document.createElement('script');
            s.src = blobUrl;
            s.onload = function() { URL.revokeObjectURL(blobUrl); ok(); };
            s.onerror = function() { URL.revokeObjectURL(blobUrl); fail(new Error('Blob script failed for ' + eng.slug)); };
            document.head.appendChild(s);
          });
          console.log('[LevelUp] Engine loader registered (inline):', eng.slug);
        }
      } catch (e) {
        console.warn('[LevelUp] Failed to load engine:', eng.slug, e.message);
      }
    }
  } catch (e) {
    console.warn('[LevelUp] Engine bootstrap failed:', e.message);
  }
}); });

// ═══════════════════════════════════════════════════════════════════
// PHASE 3A — TASK 3: DESIGN TOKEN CONSUMPTION
// Fetch /api/design-tokens and inject as CSS custom properties.
// Runs once on boot. Fail-safe: if fetch fails, existing hardcoded
// tokens continue to work (no visual change).
// ═══════════════════════════════════════════════════════════════════
;(async function _luBootTokens() {
  try {
    var r = await fetch(window.luApi + 'design-tokens', { headers: authHeader() });
    if (!r.ok) return;
    var payload = await r.json();
    var tokens = payload?.data || payload;
    if (!tokens?.colors) return;
    var root = document.documentElement;
    // Inject color tokens
    if (tokens.colors) {
      Object.entries(tokens.colors).forEach(([k,v]) => { root.style.setProperty('--lu-' + k, v); });
    }
    // Inject radii
    if (tokens.radii) {
      Object.entries(tokens.radii).forEach(([k,v]) => { root.style.setProperty('--lu-radius-' + k, v); });
    }
    // Inject typography
    if (tokens.typography) {
      if (tokens.typography.font_heading) root.style.setProperty('--lu-font-heading', tokens.typography.font_heading);
      if (tokens.typography.font_body) root.style.setProperty('--lu-font-body', tokens.typography.font_body);
    }
    console.log('[LevelUp] Design tokens applied from /api/design-tokens');
    window._luTokensData = tokens;
    window._luTokensLoaded = true;
  } catch(e) { /* fail-safe: tokens already hardcoded in CSS */ }
})();

// ═══════════════════════════════════════════════════════════════════
// PHASE 3A — TASK 1+2: RENDER MANIFEST + SEO BRIDGE DATA
// Caches render manifest on first fetch. Used by seoLoad() to show
// bridge data above the iframe when available.
// ═══════════════════════════════════════════════════════════════════
window._luRenderManifest = null;
window._luManifestLoaded = false;

async function _luFetchManifest() {
  if (window._luManifestLoaded) return window._luRenderManifest;
  try {
    var r = await fetch(window.luApi + 'tools/render-manifest', { headers: authHeader() });
    if (!r.ok) return null;
    var payload = await r.json();
    window._luRenderManifest = payload?.data || payload || [];
    window._luManifestLoaded = true;
    console.log('[LevelUp] Render manifest loaded:', window._luRenderManifest.length, 'tools');
    return window._luRenderManifest;
  } catch(e) { return null; }
}

async function _luFetchBridgeData(toolKey) {
  try {
    var r = await fetch(window.luApi + 'seo/bridge/' + toolKey, { headers: authHeader() });
    if (!r.ok) return null;
    var payload = await r.json();
    if (payload?.success && payload?.data) return payload;
    return null;
  } catch(e) { return null; }
}

function _luRenderBridgePanel(container, bridgeData, toolLabel) {
  if (!bridgeData || !bridgeData.data) return;
  var data = bridgeData.data;
  var toolKey = bridgeData.meta?.tool || '';

  // Remove old panel
  var old = container.querySelector('#seo-bridge-panel');
  if (old) old.remove();

  var panel = document.createElement('div');
  panel.id = 'seo-bridge-panel';
  panel.style.cssText = 'background:var(--s1);border-bottom:1px solid var(--bd);flex-shrink:0;overflow:hidden;transition:max-height .3s ease';

  // ── Header bar ──
  var hdr = `<div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--bd)">
    <span style="background:var(--ag);color:var(--ac);padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;flex-shrink:0">BRIDGE</span>
    <span style="font-size:12px;font-weight:600;color:var(--t1);flex:1">${_escB(toolLabel)}</span>
    <span style="font-size:10px;color:var(--t3)">${_escB(toolKey)}</span>
    <button onclick="document.getElementById('seo-bridge-panel')?.remove()" style="background:transparent;border:none;color:var(--t3);cursor:pointer;font-size:14px;padding:2px 4px;line-height:1" title="Close preview">✕</button>
  </div>`;

  let body = '';

  // ── Type-aware rendering ──
  if (Array.isArray(data)) {
    // Array of items → table (max 10 rows)
    body = _luBridgeTable(data);
  } else if (typeof data === 'object' && data !== null) {
    // Check if it looks like metrics (mostly numbers/strings at top level)
    var entries = Object.entries(data);
    var numericCount = entries.filter(([,v]) => typeof v === 'number' || (typeof v === 'string' && !isNaN(v) && v.length < 15)).length;
    var hasArrays = entries.some(([,v]) => Array.isArray(v));

    if (numericCount >= 3 && numericCount >= entries.length * 0.4) {
      // Mostly metrics → stat cards
      body = _luBridgeStatCards(entries);
    } else if (hasArrays) {
      // Mixed with arrays → stat cards for scalars + table for first array
      var scalars = entries.filter(([,v]) => !Array.isArray(v) && typeof v !== 'object');
      var firstArr = entries.find(([,v]) => Array.isArray(v));
      body = _luBridgeStatCards(scalars);
      if (firstArr) body += `<div style="padding:0 16px 4px;font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em">${firstArr[0].replace(/_/g,' ')}</div>` + _luBridgeTable(firstArr[1]);
    } else {
      // General object → stat cards
      body = _luBridgeStatCards(entries);
    }
  } else if (typeof data === 'string') {
    body = `<div style="padding:12px 16px;font-size:12px;color:var(--t2);line-height:1.6">${_escB(data)}</div>`;
  }

  panel.innerHTML = hdr + `<div style="max-height:260px;overflow-y:auto">${body}</div>`;

  // Insert after nav bar
  var slot = container.querySelector('#seo-bridge-slot');
  if (slot) { slot.innerHTML = ''; slot.appendChild(panel); }
  else {
    var navBar = container.querySelector('#seo-embed-nav');
    if (navBar && navBar.nextSibling) container.insertBefore(panel, navBar.nextSibling);
    else container.prepend(panel);
  }
}

// ── Bridge render helpers ──
function _escB(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function _luBridgeStatCards(entries) {
  if (!entries.length) return '';
  var TS_KEYS = /created_at|updated_at|last_audit|last_scan|date|timestamp|_at$/i;
  var cards = entries.slice(0, 8).map(([k, v]) => {
    let display, color = 'var(--t1)';
    // Detect timestamp fields by key name
    if (TS_KEYS.test(k) && v) {
      var d = typeof v === 'number' ? new Date(v < 1e12 ? v * 1000 : v) : new Date(v);
      display = isNaN(d.getTime()) ? String(v) : d.toLocaleString(undefined, {year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
      color = 'var(--t2)';
    } else if (typeof v === 'number') { display = v.toLocaleString(); }
    else if (typeof v === 'boolean') { display = v ? '✓' : '✗'; color = v ? 'var(--ac)' : 'var(--rd)'; }
    else if (v === null || v === undefined) { display = '—'; color = 'var(--t3)'; }
    else if (typeof v === 'object') { display = Array.isArray(v) ? v.length + ' items' : Object.keys(v).length + ' fields'; color = 'var(--t2)'; }
    else {
      // String that looks like a date/timestamp
      if (TS_KEYS.test(k) || /^\d{4}-\d{2}-\d{2}/.test(String(v))) {
        var d = new Date(v);
        if (!isNaN(d.getTime())) { display = d.toLocaleString(undefined, {year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); color = 'var(--t2)'; }
        else { display = String(v).slice(0, 50); }
      } else { display = String(v).slice(0, 50); }
    }
    return `<div style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:10px 14px;min-width:110px">
      <div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px">${_escB(k.replace(/_/g,' '))}</div>
      <div style="font-size:15px;font-weight:600;color:${color}">${_escB(display)}</div>
    </div>`;
  }).join('');
  return `<div style="display:flex;gap:8px;padding:10px 16px;overflow-x:auto;flex-wrap:wrap">${cards}</div>`;
}

function _luBridgeTable(arr) {
  if (!arr.length) return '<div style="padding:12px 16px;font-size:11px;color:var(--t3)">No items</div>';
  var rows = arr.slice(0, 10);
  // Get column headers from first item
  var first = rows[0];
  if (typeof first !== 'object' || first === null) {
    // Simple array of strings/numbers
    return `<div style="padding:8px 16px;display:flex;flex-direction:column;gap:4px">${rows.map(r => `<div style="font-size:12px;color:var(--t2);padding:4px 0;border-bottom:1px solid var(--bd)">${_escB(r)}</div>`).join('')}</div>`;
  }
  var cols = Object.keys(first).filter(k => typeof first[k] !== 'object').slice(0, 5);
  if (!cols.length) return '';
  var thead = cols.map(c => `<th style="font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.04em;padding:6px 10px;text-align:left;border-bottom:1px solid var(--bd);background:var(--s2)">${_escB(c.replace(/_/g,' '))}</th>`).join('');
  var tbody = rows.map(r => '<tr>' + cols.map(c => {
    var v = r[c]; let display;
    var isTs = /created_at|updated_at|last_audit|last_scan|date|timestamp|_at$/i.test(c);
    if (v === null || v === undefined) { display = '—'; }
    else if (isTs) {
      var d = typeof v === 'number' ? new Date(v < 1e12 ? v * 1000 : v) : new Date(v);
      display = isNaN(d.getTime()) ? String(v).slice(0,60) : d.toLocaleString(undefined, {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
    } else { display = String(v).slice(0, 60); }
    return `<td style="font-size:11px;color:var(--t2);padding:6px 10px;border-bottom:1px solid rgba(255,255,255,.04)">${_escB(display)}</td>`;
  }).join('') + '</tr>').join('');
  return `<table style="width:100%;border-collapse:collapse"><thead><tr>${thead}</tr></thead><tbody>${tbody}</tbody></table>` +
    (arr.length > 10 ? `<div style="padding:6px 16px;font-size:10px;color:var(--t3)">Showing 10 of ${arr.length}</div>` : '');
}

// [builder] extracted to builder.js (lines 3306-3313)

// ── COMPONENT TYPE DEFINITIONS ────────────────────────────────────────
// [builder] extracted to builder.js (lines 3316-3332)

// ── API STATE ─────────────────────────────────────────────────────────
// [builder] extracted to builder.js (lines 3335-3335)

// ── LOAD PAGES (+ stats + library count) ──────────────────────────────
// [builder] extracted to builder.js (lines 3338-5263)
function arthurBindToComponent(cmp, si, ci, mi) {
  // REPORT-0023 (2026-08-30): the inline Arthur bubble posted to POST builder/arthur, which does not
  // exist. It never opens now; Arthur edits pages from the page editor (_t3ArthurSend). Markup stays.
  arthurHidePanel(); return;
  var typeLabels = {heading:'Heading',text:'Text Block',button:'Button',image:'Image',video:'Video',cta:'CTA Block',testimonial:'Testimonial',pricing:'Pricing Card',faq:'FAQ',form:'Form',list:'List',html:'Custom HTML',spacer:'Spacer',divider:'Divider',card:'Card'};
  var typeIcons  = {heading:'H',text:'¶',button:'⬜',image:'🖼',video:'▶',cta:'🚀',testimonial:'💬',pricing:'💰',faq:'❓',form:'📋',list:'≡',html:'<>',spacer:'↕',divider:'—',card:'🗂'};
  var cmpType = cmp.component_type || cmp.type || 'text';
  arthurContext = {si,ci,mi,type:cmpType,content:cmp.content||{},pageId:bldCurrentPageId};
  var bubble=document.getElementById('arthur-inline-panel');
  var typeEl=document.getElementById('arthur-el-type');
  var labelEl=document.getElementById('arthur-el-label');
  typeEl.textContent  = typeIcons[cmpType]||'?';
  labelEl.textContent = typeLabels[cmpType]||cmpType;
  var res=document.getElementById('arthur-result');
  res.classList.remove('visible'); res.innerHTML='';
  document.getElementById('arthur-input').value='';
  // Phase 6: show panel, then restore saved position or smart-position near component
  // FIX 2+3: Use individual style properties — cssText nukes transform (drag position)
  // and inline transitions (glow effect). Set only display/position, preserve transform.
  bubble.style.display   = 'flex';
  bubble.style.bottom    = '80px';
  bubble.style.right     = '20px';
  bubble.style.top       = 'auto';
  bubble.style.left      = 'auto';
  // Trigger open animation via class (not CSS animation property) so transform is preserved
  bubble.classList.add('arthur-opening');
  setTimeout(function() { bubble.classList.remove('arthur-opening'); }, 200);
  onArthurFinish(); // ensure no stale glow on open
  try {
    var saved = sessionStorage.getItem('lu_arthur_pos');
    if (saved) { _arthurPos = JSON.parse(saved); _arthurApplyPos(); }
    else { _arthurPositionNear(si, ci); }
  } catch(e) { _arthurPositionNear(si, ci); }
  setTimeout(()=>{
    var oh=function(e){if(!bubble.contains(e.target)&&!e.target.closest('.bld-cmp-wrap')){arthurHidePanel();document.removeEventListener('click',oh);}};
    document.addEventListener('click',oh);
  },200);
}

function arthurHidePanel(){
  var b=document.getElementById('arthur-inline-panel');
  if(b) b.style.display='none';
  arthurContext=null; arthurBusy=false;
}

function arthurQuickCommand(prompt){
  var inp=document.getElementById('arthur-input');
  if(inp) inp.value=prompt;
  arthurSendCommand();
}

async function arthurSendCommand(){
  // REPORT-0023 (2026-08-30): POST builder/arthur does not exist — no-op that closes the panel.
  arthurHidePanel(); return;
  if(!arthurContext||arthurBusy) return;
  var inp=document.getElementById('arthur-input');
  var command=inp.value.trim(); if(!command) return;
  arthurBusy=true;
  onArthurStart(); // Phase 6: enable glow
  var busyEl=document.getElementById('arthur-busy');
  var resEl=document.getElementById('arthur-result');
  var sendBtn=document.getElementById('arthur-send');
  busyEl.classList.add('visible'); resEl.classList.remove('visible');
  if(sendBtn) sendBtn.disabled=true;
  var instruction=`You are Arthur, the LevelUp AI builder assistant inside LevelUpGrowth Platform. The user selected a "${arthurContext.type}" element with content: ${JSON.stringify(arthurContext.content)}. Business: ${BN} (${BU}). Request: "${command}". Return ONLY a raw JSON object — the updated content. No markdown, no explanation. Match the existing content structure. For heading/text: {"text":"..."}. For button: {"label":"...","href":"#","variant":"primary"}. For testimonial: {"quote":"...","author":"...","role":"..."}.`;
  try{
    var r = await fetch(API + 'builder/arthur', {
      method: 'POST',
      headers: Object.assign({'Content-Type': 'application/json'}, authHeader()),
      body: JSON.stringify({
        mode: 'inline',
        command: command,
        element_type: arthurContext.type,
        existing_content: arthurContext.content,
        site_name: BN
      })
    });
    var d = await safeJson(r);
    if (!d) { if(resEl){ resEl.innerHTML='<div style="color:#F87171">Arthur is temporarily unavailable. Please try again.</div>'; resEl.classList.add('visible'); } return; }
    if (d.content && typeof d.content === 'object') {
      // Apply the AI-generated content to the element
      if(resEl) resEl.innerHTML = '<strong style="color:var(--ac)">\u2713 Applied:</strong> <pre style="font-size:11px;margin-top:4px;color:var(--t2)">' + JSON.stringify(d.content, null, 2) + '</pre>';
    } else {
      if(resEl) resEl.innerHTML = '<div style="background:var(--s2);border-radius:6px;padding:8px;font-size:11px;line-height:1.5;color:var(--t2)">' + (d.raw || d.reply || 'No response from Arthur.') + '</div>';
    }
    if(resEl) resEl.classList.add('visible');
    inp.value = '';
  }catch(e){ console.error('[Arthur]', e); if(resEl){ resEl.innerHTML='<div style="color:#F87171">Error: '+e.message+'</div>'; resEl.classList.add('visible'); } }
  finally{ arthurBusy=false; onArthurEnd(); if(busyEl) busyEl.classList.remove('visible'); if(sendBtn) sendBtn.disabled=false; }
}

// ======================================================================
// BUILDER SPA — EXTRACTED TO levelup-builder-engine/builder-spa.js
// Builder loads via lu_platform_scripts hook or dynamic engine loader.
// ======================================================================


// ═══════════════════════════════════════════════════════════════════════════════
// CREATIVE888 — postMessage bridge for iframe-based engines (SEO Suite)
//
// SEO Suite iframe sends: window.parent.postMessage({ type:'lu:creative:request', ... })
// This listener opens the LuCreative bridge modal on the parent platform page.
// Result is posted back into the iframe via frame.contentWindow.postMessage.
// ═══════════════════════════════════════════════════════════════════════════════
;(function() {
    window.addEventListener('message', function(event) {
        // Verify it came from the SEO iframe
        if (!event.data || event.data.type !== 'lu:creative:request') return;

        // ── Origin hardening: only accept from same origin or known SEO iframe src ──
        var allowedOrigins = [window.location.origin];
        // If SEO iframe is loaded from a different subdomain (e.g. app.staging1...), add it
        if (window._seoIframe && window._seoIframe.src) {
            try {
                var seoOrigin = new URL(window._seoIframe.src).origin;
                allowedOrigins.push(seoOrigin);
            } catch(e) {}
        }
        if (allowedOrigins.indexOf(event.origin) === -1) {
            console.warn('[LuCreative Bridge] Blocked postMessage from untrusted origin:', event.origin);
            return;
        }

        var payload = event.data;
        var ctx     = payload.context || 'blog_featured';
        var hint    = payload.prompt_hint || '';
        var target  = event.source; // iframe window reference

        if (typeof window.LuCreative === 'undefined' || typeof window.LuCreative.pickModal !== 'function') {
            console.warn('[LuCreative Bridge] LuCreative not loaded yet');
            return;
        }

        window.LuCreative.pickModal({
            context:    ctx,
            title:      payload.title || 'Generate Featured Image',
            promptHint: hint,
            onInsert:   function(asset) {
                // Post result back to the SEO iframe
                if (target && target.postMessage) {
                    target.postMessage({
                        type:  'lu:creative:result',
                        asset: {
                            id:            asset.id,
                            public_url:    asset.public_url,
                            web_url:       asset.web_url,
                            thumbnail_url: asset.thumbnail_url,
                            prompt:        asset.prompt,
                        }
                    }, event.origin);
                }
            }
        });
    });

    // Also listen for the lu:creative:insert event dispatched by Social/Marketing engines
    window.addEventListener('lu:creative:insert', function(e) {
        if (!e.detail || !e.detail.asset) return;
        var ctx = e.detail.context || 'default';
        // If the current view is Social or Marketing, the engine JS handles it via event listener
        console.log('[LuCreative Bridge] insert event for context:', ctx, e.detail.asset.id);
    });
})();

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3.3.0 — BOOTSTRAP + AUTH + ONBOARDING + NOTIFICATIONS + ANALYTICS
// ════════════════════════════════════════════════════════════════════════════

// ── Helpers ──────────────────────────────────────────────────────────────────
// _luBase = bare origin (https://staging1.shukranuae.com)
// Use URL constructor to reliably extract origin from any rest_url() format:
//   https://example.com/api/          → https://example.com
//   https://example.com/?rest_route=/api/      → https://example.com
//   https://example.com/index.php?rest_route=... → https://example.com
var _luBase = (function() {
    if (window.LU_CFG && window.LU_CFG.api) {
        try { return new URL(window.LU_CFG.api).origin; } catch(_) {}
    }
    return window.location.origin;
})();

async function _luFetch(method, path, body) {
  var token  = localStorage.getItem('lu_token');
  var nonce  = (window.LU_CFG && window.LU_CFG.nonce) ? window.LU_CFG.nonce : '';
  var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  // Embed mode: use API key auth (works against both /connector/* and any
  // JWT-protected route, since JwtAuthMiddleware now accepts X-API-KEY).
  if (window._LGSC_EMBED && window._LGSC_EMBED.api_key) {
    headers['X-API-KEY']      = window._LGSC_EMBED.api_key;
    headers['X-Workspace-ID'] = String(window._LGSC_EMBED.workspace_id || '');
  } else {
    if (token)  headers['Authorization']  = 'Bearer ' + token;
    if (nonce)  headers['X-WP-Nonce']     = nonce;
  }
  var opts = { method: method, headers: headers, cache: 'no-store' };
  if (body) opts.body = JSON.stringify(body);
  var r = await fetch(_luBase + '/api' + path, opts);
  if (r.status === 402 || r.status === 403) { try { var _pgd = await r.clone().json(); if (_pgd.code === 'PLAN_GATED' || _pgd.code === 'NO_CREDITS') { showPlanGate(_pgd.error || _pgd.message || 'This feature requires a plan upgrade.'); } } catch(_pge) {} }
  return r;
}

function _luEsc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── TASK 1.3: Bootstrap ───────────────────────────────────────────────────────
async function _appBootstrap() {
  // Skip entirely when running inside WordPress (WP Admin or WP-rendered page).
  // LU_CFG.nonce is injected by the PHP plugin only in WP context — never present
  // on the standalone SPA (app.levelupgrowth.ai) which has no PHP rendering.
  if (window.LU_CFG && window.LU_CFG.nonce) return; // WP-rendered context
  if (window.location.pathname.indexOf('/wp-admin') !== -1) return; // extra guard
  if (!document.getElementById('lu-auth-root')) return;

  // LGSC embed mode: skip login/refresh dance — we authenticate via X-API-KEY
  // on every API call. Jump straight to the dashboard.
  if (window._LGSC_EMBED && window._LGSC_EMBED.api_key) {
    window._currentWorkspaceId = window._LGSC_EMBED.workspace_id;
    try { _appEnterDashboard(); } catch (e) { console.warn('[LGSC] embed bootstrap fallback:', e); }
    return;
  }

  var token = localStorage.getItem('lu_token');
  // CROSS-TAB SESSION (2026-09-11): a missing access token with a live refresh token beside it is a session
  // waiting to be refreshed, not a visitor to send to the login card.
  if (!token && localStorage.getItem('lu_refresh_token')) token = 'refresh-pending';
  if (!token) {
    if (window.location.hash.indexOf('#signup') === 0) {
      // 2026-09-11 (Owner): account creation lives on the marketing site — /start/ — on every host. The old
      // in-app signup form is no longer a public door. ?plan= survives the hop.
      var _plan = (window.location.hash.match(/[?&]plan=([^&]+)/) || [])[1] || (new URLSearchParams(window.location.search).get('plan') || '');
      window.location.replace('/start/' + (_plan ? '?plan=' + encodeURIComponent(_plan) : ''));
      return;
    } else {
      _renderLogin();
    }
    return;
  }

  var refreshToken = localStorage.getItem('lu_refresh_token');
  if (!refreshToken) { _renderLogin(); return; }
  try {
    var r = await fetch(_luBase + '/api/auth/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
      cache: 'no-store',
    });
    if (!r.ok) throw new Error('expired');
    var d = await r.json();
    if (d.access_token) localStorage.setItem('lu_token', d.access_token);
    if (d.refresh_token) localStorage.setItem('lu_refresh_token', d.refresh_token);
  } catch(_) {
    localStorage.removeItem('lu_token');
    _renderLogin();
    return;
  }

  // 2026-09-10 — a visitor handed over by the marketing hero has ALREADY had this conversation:
  // they described their business to Arthur, he asked his follow-ups, and the brief crossed with
  // them. Running the new-account onboarding here asked the same questions a second time and, worse,
  // rendered "Meet Sarah" into #lu-auth-root at z-index 9999 on top of the Arthur wizard the boot
  // script had just opened — so the customer's answer to "finish my website" was a different agent
  // introducing herself. Enter the app and let Arthur have the screen.
  //
  // Deliberately NOT setting lu_onboarded here: nothing has been completed yet. The workspace earns
  // that flag the moment Arthur's build gives it a website, through the check just below.
  if (window.__luArthurHandoff) { _appEnterDashboard(); return; }

  // Check onboarding completion via new /onboarding/status endpoint.
  // Falls back to legacy /workspace/status path + lu_onboarded flag for back-compat.
  if (localStorage.getItem('lu_onboarded') === '1') {
    _appEnterDashboard(); return;
  }
  // PATCH (onboarding skip-existing): if workspace already has at least one
  // website, the wizard would just re-prompt for already-collected info.
  // Treat the existence of a website as "onboarded" and route straight to
  // dashboard. Caches the flag so subsequent loads skip the network call.
  try {
    var wsRes = await fetch(_luBase + '/api/builder/websites', {
      headers: _lgscAuthForFetch({ 'Accept': 'application/json' }),
      cache: 'no-store',
    });
    if (wsRes.ok) {
      var wsData = await wsRes.json();
      var sites = (wsData && (wsData.websites || wsData.data)) || (Array.isArray(wsData) ? wsData : []);
      sites = Array.isArray(sites) ? sites : [];

      // OWNER RULE (2026-09-10): "Sarah should only introduce herself once the website has been
      // published inside Laravel, never on the marketing website."
      //
      // The order used to be the reverse of that. A brand-new account has no website — because Arthur
      // has not built it yet, which is the entire reason the customer is standing here — and THAT was
      // the condition that triggered her introduction. So the first thing a new customer met was a
      // second agent introducing herself, before they had anything for her to manage.
      //
      // Published, not merely existing: a draft site is still Arthur's work in progress. Sarah runs
      // growth for something that is live, so that is the moment she has a reason to speak.
      var hasPublished = sites.some(function (w) { return w && String(w.status) === 'published'; });

      if (hasPublished) {
        localStorage.setItem('lu_onboarded', '1');
        // OWNER RULE (2026-09-15, EV-1043): Sarah never takes the screen. Her introduction is the first message of
        // her OWN thread (seeded at registration for new accounts), so it shows in the Basic Sarah view and in the
        // Advanced Messages floater alike. An existing account on a new device simply sees its thread — the old
        // full-screen "Meet Sarah" interview keyed on a device flag met every returning customer as if they were new.
        _appEnterDashboard();
        return;
      }

      if (sites.length > 0) {
        // Built but not published yet — the customer is mid-build with Arthur. Nothing to introduce.
        localStorage.setItem('lu_onboarded', '1');
        _appEnterDashboard();
        return;
      }

      // No website at all: Arthur's stage. Enter the app rather than hand the screen to Sarah.
      _appEnterDashboard();
      return;
    }
  } catch(_) { /* fall through to onboarding-status check */ }
  try {
    var sr = await fetch(_luBase + '/api/onboarding/status', {
      headers: _lgscAuthForFetch({ 'Accept': 'application/json' }),
      cache: 'no-store',
    });
    if (sr.ok) {
      var s = await sr.json();
      if (s.step === 'complete') {
        localStorage.setItem('lu_onboarded', '1');
        _appEnterDashboard();
        return;
      }
      if (s.step === 3) {
        _showOnboardingStep3();
        return;
      }
      // step 1 or 2 — the account has not finished onboarding, but under the Owner's rule that is NOT
      // a reason for Sarah to introduce herself: nothing has been published for her to run yet.
      // (Reached only when the websites call above failed, so we cannot see the sites.)
      _appEnterDashboard();
      return;
    }
  } catch(_) { /* fall through to legacy path */ }

  try {
    var ws = await _luFetch('GET', '/workspace/status').then(function(r){ return r.json(); });
    if (ws.industry && ws.website_count > 0) {
      localStorage.setItem('lu_onboarded', '1');
    }
    // Legacy fallback path. It cannot tell a published site from a draft, so under the Owner's rule it
    // does not introduce Sarah either — the published check above is the only place that may.
    _appEnterDashboard();
  } catch(_) { _appEnterDashboard(); }
}

function _appEnterDashboard() {
  // Show the SPA main app, hide auth overlay
  var authRoot = document.getElementById('lu-auth-root');
  if (authRoot) authRoot.style.display = 'none';
  var appShell = document.querySelector('.app');
  if (appShell) appShell.style.display = 'flex';
  // Run trial check once + poll every 30s for credit refresh (Wave 47g).
  // PLATFORM888 Phase 6: trial + notifications are background services that
  // start only after the primary route is interactive (governed by luBg).
  window.luBg.register('trial', {
    start: function () {
      _checkTrialStatus();
      if (!window._luCreditPollTimer) {
        window._luCreditPollTimer = setInterval(function () {
          if (document.visibilityState !== 'hidden') { _checkTrialStatus(); }
        }, 30000);
      }
    },
    stop: function () { if (window._luCreditPollTimer) { clearInterval(window._luCreditPollTimer); window._luCreditPollTimer = null; } }
  });
  window.luBg.register('notifications', {
    start: _notifStartPolling,
    stop: function () { if (_notifPollTimer) { clearInterval(_notifPollTimer); _notifPollTimer = null; } }
  });
  // Primary route is now interactive (auth + workspace resolved) — release the
  // governed background services on the next idle tick.
  (window.requestIdleCallback||function(f){setTimeout(f,400);})(function(){ if (window.luBg) window.luBg.markInteractive(); });
  // Signal that bootstrap is complete (consumers like the workspace canvas loader rely on this)
  document.dispatchEvent(new Event('lu:bootstrap-complete'));

  // v5.7.19 (2026-05-31) — Phase 1.0 URL routing.
  // v5.7.20 (2026-05-31) — Phase 2 path tails: pass tail through so
  // /app/write/176 lands at write engine + opens article 176.
  // v5.7.23 (2026-05-31) — 404 toast for unknown views.
  // LGSC embed mode skips this because it does its own
  // setTimeout(window.nav('seo'), 500) below.
  if (!window._LGSC_EMBED && window._luRouter && window._luRouter.enabled()) {
    var initialHit = window._luRouter.parseInitial();
    var rawPath = (window.location.pathname || '').replace(/\/+$/, '');
    var isAppPath = rawPath === '/app' || rawPath === '' || rawPath.indexOf('/app/') === 0;
    if (initialHit && (initialHit.view !== window._luRouter.DEFAULT_VIEW || initialHit.tail)) {
      // Defer one tick so the view-* DOM elements are mounted.
      setTimeout(function () {
        try {
          if (typeof window.nav === 'function') {
            window.nav(initialHit.view, { silent: true, tail: initialHit.tail });
          }
        } catch (e) { console.warn('[LU Router] initial nav failed:', e); }
      }, 0);
    } else if (!initialHit && isAppPath && rawPath !== '/app' && rawPath !== '') {
      // /app/{unknown} — toast + repath to /app/ so the URL bar matches
      // what the user sees (workspace default).
      setTimeout(function () {
        try {
          if (typeof showToast === 'function') {
            showToast('Section not found — showing your workspace.', 'warning');
          } else {
            console.warn('[LU Router] unknown section:', rawPath);
          }
          // Replace state — don't push, since user didn't actually navigate.
          try { history.replaceState(null, '', '/app/' + window.location.search + window.location.hash); } catch (_e) {}
          // Update title to match the workspace landing.
          if (window._luRouter && typeof window._luRouter.setTitle === 'function') {
            window._luRouter.setTitle(window._luRouter.DEFAULT_VIEW);
          }
        } catch (_e) {}
      }, 0);
    } else if (initialHit) {
      // /app/ direct landing — P1-U2: the default view is no longer pre-rendered in the markup (it depends on the
      // mode: Sarah in Basic, the workspace canvas in Advanced), so mount it explicitly.
      setTimeout(function () {
        try { if (typeof window.nav === 'function') { window.nav(initialHit.view, { silent: true }); } } catch (e) { console.warn('[LU Router] default nav failed:', e); }
      }, 0);
      try { window._luRouter.setTitle(initialHit.view); } catch (_e) {}
    }
  }
}

// ── Onboarding + auth views ──────────────────────────────────────────
// Extracted to onboarding.js (Phase O1 — 2026-05-10)


// ── TASK 2.1–2.3: 5-step onboarding ──────────────────────────────────────────
var _ob = {
  step:     parseInt(localStorage.getItem('lu_ob_step')||'0'),
  name:     localStorage.getItem('lu_ob_name')||'',
  industry: localStorage.getItem('lu_ob_industry')||'',
  services: JSON.parse(localStorage.getItem('lu_ob_services')||'[]'),
  goal:     localStorage.getItem('lu_ob_goal')||'',
  location: localStorage.getItem('lu_ob_location')||'',
  website_id: localStorage.getItem('lu_ob_website_id')||null,
};

function _obSave(key, val) {
  _ob[key] = val;
  localStorage.setItem('lu_ob_' + key, typeof val === 'object' ? JSON.stringify(val) : String(val));
}

function _renderOnboarding() {
  var root = document.getElementById('lu-auth-root');
  if (!root) return;
  root.style.display = 'flex';
  var appShell = document.querySelector('.app');
  if (appShell) appShell.style.display = 'none';

  var steps = ['Business', 'Industry', 'Goal', 'Generate', 'Done'];
  var pips = steps.map(function(s,i){ return '<div style="display:flex;flex-direction:column;align-items:center;gap:4px"><div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;background:' + (i<=_ob.step?'var(--p,#6C5CE7)':'var(--s2,#1E2230)') + ';color:' + (i<=_ob.step?'#fff':'var(--t3,#777)') + '">' + (i+1) + '</div><div style="font-size:10px;color:var(--t3,#777)">' + s + '</div></div>'; }).join('<div style="height:1px;background:var(--bd,#2a2d3e);flex:1;margin-top:14px"></div>');

  root.innerHTML = `
  <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg,#0F1117);padding:20px">
    <div style="width:100%;max-width:540px">
      <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:28px">${pips}</div>
      <div id="ob-step-area" style="background:var(--s1,#171A21);border:1px solid var(--bd,#2a2d3e);border-radius:16px;padding:32px"></div>
    </div>
  </div>`;
  _obRenderStep(_ob.step);
}

function _obRenderStep(step) {
  var area = document.getElementById('ob-step-area');
  if (!area) return;
  _obSave('step', step);

  if (step === 0) {
    area.innerHTML = `
      <h2 style="font-family:var(--fh,'Syne'),sans-serif;font-size:22px;font-weight:800;color:var(--t1,#e0e0e0);margin:0 0 6px">Tell us about your business</h2>
      <p style="color:var(--t3,#777);font-size:13px;margin:0 0 24px">Your AI team needs this context to deliver relevant work.</p>
      <div class="form-group" style="margin-bottom:14px"><label class="form-label">Business name *</label><input class="form-input" id="ob-name" value="${_luEsc(_ob.name)}" placeholder="e.g. Shukran Interiors"></div>
      <div class="form-group" style="margin-bottom:24px"><label class="form-label">Location <span style="font-size:10px;color:var(--t3)">optional</span></label><input class="form-input" id="ob-loc" value="${_luEsc(_ob.location)}" placeholder="e.g. Dubai, UAE"></div>
      <button class="btn btn-primary" style="width:100%;justify-content:center" onclick="_obNext0()">Continue →</button>`;
  } else if (step === 1) {
    area.innerHTML = `
      <h2 style="font-family:var(--fh,'Syne'),sans-serif;font-size:22px;font-weight:800;color:var(--t1,#e0e0e0);margin:0 0 6px">What industry are you in?</h2>
      <p style="color:var(--t3,#777);font-size:13px;margin:0 0 24px">This helps your AI team use the right language and strategy.</p>
      <div class="form-group" style="margin-bottom:14px"><label class="form-label">Industry *</label><input class="form-input" id="ob-ind" value="${_luEsc(_ob.industry)}" placeholder="e.g. Interior Design, SaaS, E-commerce"></div>
      <div class="form-group" style="margin-bottom:24px"><label class="form-label">Core services <span style="font-size:10px;color:var(--t3)">comma-separated</span></label><input class="form-input" id="ob-svc" value="${_luEsc(Array.isArray(_ob.services)?_ob.services.join(', '):_ob.services)}" placeholder="e.g. Interior design, Fit-out, Consultation"></div>
      <div style="display:flex;gap:10px"><button class="btn btn-outline" onclick="_obRenderStep(0)">← Back</button><button class="btn btn-primary" style="flex:1;justify-content:center" onclick="_obNext1()">Continue →</button></div>`;
  } else if (step === 2) {
    area.innerHTML = `
      <h2 style="font-family:var(--fh,'Syne'),sans-serif;font-size:22px;font-weight:800;color:var(--t1,#e0e0e0);margin:0 0 6px">What's your main goal?</h2>
      <p style="color:var(--t3,#777);font-size:13px;margin:0 0 24px">We'll build your first website around this.</p>
      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px">
        ${[['leads','Generate more leads',''+window.icon("chart",14)+''],['brand','Build brand credibility',''+window.icon("star",14)+''],['ecommerce','Sell products online','🛒'],['portfolio','Showcase my work',''+window.icon("ai",14)+'']].map(function(g){
          var sel = _ob.goal===g[0];
          return '<div onclick="_obSelectGoal(\''+g[0]+'\')" style="display:flex;align-items:center;gap:12px;padding:14px 16px;border:2px solid '+(sel?'var(--p,#6C5CE7)':'var(--bd,#2a2d3e)')+';border-radius:10px;cursor:pointer;background:'+(sel?'rgba(108,92,231,.1)':'var(--s2,#1E2230)')+'"><span style="font-size:22px">'+g[2]+'</span><div><div style="font-size:13px;font-weight:600;color:var(--t1,#e0e0e0)">'+g[1]+'</div></div></div>';
        }).join('')}
      </div>
      <div style="display:flex;gap:10px"><button class="btn btn-outline" onclick="_obRenderStep(1)">← Back</button><button class="btn btn-primary" id="ob-g-next" style="flex:1;justify-content:center;'+((!_ob.goal)?'opacity:.5;cursor:not-allowed':'')+'" onclick="_obNext2()">Next: Choose Style →</button></div>`;
  } else if (step === 3) {
    // Brand identity step
    var industry = (_ob.industry||'').toLowerCase();
    var palettes = {
      'tech':     [{p:'#6C5CE7',s:'#00CEC9',a:'#F4F7FB',n:'Purple-Teal'},{p:'#0D1B2A',s:'#00E5A8',a:'#E8EDF5',n:'Dark-Cyan'},{p:'#3B82F6',s:'#EFF6FF',a:'#1E293B',n:'Blue-White'}],
      'interior': [{p:'#2D5016',s:'#F5F0E8',a:'#8B7355',n:'Green-Cream'},{p:'#1B2838',s:'#D4A843',a:'#F4F7FB',n:'Navy-Gold'},{p:'#6B5B4B',s:'#E8DDD0',a:'#B87333',n:'Warm Copper'}],
      'food':     [{p:'#E8590C',s:'#FFF5E6',a:'#8B4513',n:'Warm Orange'},{p:'#5C3D2E',s:'#FFF8F0',a:'#D4A843',n:'Brown-Cream'},{p:'#1A472A',s:'#F0F7E6',a:'#D4A843',n:'Green-Gold'}],
      'health':   [{p:'#0891B2',s:'#F0FDFA',a:'#5EEAD4',n:'Blue-Mint'},{p:'#FFFFFF',s:'#14B8A6',a:'#F0FDFA',n:'White-Teal'},{p:'#8B5CF6',s:'#FAF5FF',a:'#C4B5FD',n:'Soft Purple'}],
      'legal':    [{p:'#1B2838',s:'#D4A843',a:'#F4F7FB',n:'Navy-Gold'},{p:'#374151',s:'#D1D5DB',a:'#F9FAFB',n:'Grey-Silver'},{p:'#1E3A5F',s:'#FFFFFF',a:'#60A5FA',n:'Deep Blue'}],
      'fashion':  [{p:'#EC4899',s:'#0F0F0F',a:'#FDF2F8',n:'Pink-Black'},{p:'#FFFFFF',s:'#F43F5E',a:'#FFF1F2',n:'White-Rose'},{p:'#DC2626',s:'#0F0F0F',a:'#FFFFFF',n:'Bold Red'}],
      'fitness':  [{p:'#DC2626',s:'#0F0F0F',a:'#FFFFFF',n:'Red-Black'},{p:'#EA580C',s:'#1C1917',a:'#FFF7ED',n:'Dark Orange'},{p:'#16A34A',s:'#0F0F0F',a:'#F0FDF4',n:'Green-Black'}],
    };
    var palKey='tech';
    if(/interior|real.estate|furniture|home/i.test(industry)) palKey='interior';
    else if(/food|restaurant|cafe|bakery|catering/i.test(industry)) palKey='food';
    else if(/health|medical|dental|clinic|pharma/i.test(industry)) palKey='health';
    else if(/law|legal|finance|accounting|bank/i.test(industry)) palKey='legal';
    else if(/fashion|retail|clothing|beauty|cosmetic/i.test(industry)) palKey='fashion';
    else if(/fitness|gym|sport|yoga|wellness/i.test(industry)) palKey='fitness';
    var pals=palettes[palKey]||palettes.tech;
    var selPal=parseInt(_ob.palette_idx)||0;
    area.innerHTML='<h2 style="font-family:var(--fh);font-size:22px;font-weight:800;color:var(--t1);margin:0 0 6px">Choose Your Brand Style</h2>'+
      '<p style="color:var(--t3);font-size:13px;margin:0 0 20px">Colors and fonts for your entire website.</p>'+
      '<div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Color Palette</div>'+
      '<div style="display:flex;gap:10px;margin-bottom:20px">'+
      pals.map(function(p,i){return '<div onclick="_obSelectPalette('+i+')" style="flex:1;padding:14px;border:2px solid '+(i===selPal?'var(--p)':'var(--bd)')+';border-radius:10px;cursor:pointer;background:'+(i===selPal?'rgba(108,92,231,.08)':'var(--s2)')+'"><div style="display:flex;gap:4px;margin-bottom:8px"><div style="width:32px;height:32px;border-radius:6px;background:'+p.p+'"></div><div style="width:32px;height:32px;border-radius:6px;background:'+p.s+'"></div><div style="width:32px;height:32px;border-radius:6px;background:'+p.a+';border:1px solid var(--bd)"></div></div><div style="font-size:11px;font-weight:600;color:var(--t1)">'+p.n+'</div></div>';}).join('')+
      '</div>'+
      '<div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Font Pairing</div>'+
      '<div style="display:flex;gap:10px;margin-bottom:24px">'+
      '<div onclick="_obSelectFont(0)" style="flex:1;padding:12px;border:2px solid '+(parseInt(_ob.font_idx||0)===0?'var(--p)':'var(--bd)')+';border-radius:10px;cursor:pointer;text-align:center"><div style="font-family:Syne;font-size:16px;font-weight:700;color:var(--t1)">Syne</div><div style="font-size:11px;color:var(--t3)">+ DM Sans</div></div>'+
      '<div onclick="_obSelectFont(1)" style="flex:1;padding:12px;border:2px solid '+(parseInt(_ob.font_idx||0)===1?'var(--p)':'var(--bd)')+';border-radius:10px;cursor:pointer;text-align:center"><div style="font-family:Georgia;font-size:16px;font-weight:700;color:var(--t1)">Playfair</div><div style="font-size:11px;color:var(--t3)">+ Inter</div></div>'+
      '<div onclick="_obSelectFont(2)" style="flex:1;padding:12px;border:2px solid '+(parseInt(_ob.font_idx||0)===2?'var(--p)':'var(--bd)')+';border-radius:10px;cursor:pointer;text-align:center"><div style="font-family:monospace;font-size:16px;font-weight:700;color:var(--t1)">Grotesk</div><div style="font-size:11px;color:var(--t3)">+ Manrope</div></div>'+
      '</div>'+
      '<div style="display:flex;gap:10px"><button class="btn btn-outline" onclick="_obRenderStep(2)">\u2190 Back</button><button class="btn btn-primary" style="flex:1;justify-content:center" onclick="_obNext3()">Generate My Website \u2192</button></div>';

  } else if (step === 4) {
    area.innerHTML = `
      <div style="text-align:center;padding:20px 0">
        <div style="font-size:48px;margin-bottom:16px">${window.icon('ai',14)}</div>
        <h2 style="font-family:var(--fh,'Syne'),sans-serif;font-size:22px;font-weight:800;color:var(--t1,#e0e0e0);margin:0 0 8px">Building your website…</h2>
        <p style="color:var(--t3,#777);font-size:13px;margin:0 0 24px">Your AI team is generating pages for <strong style="color:var(--t1)">${_luEsc(_ob.name)}</strong></p>
        <div style="background:var(--s2,#1E2230);border-radius:10px;padding:20px">
          <div id="ob-progress" style="font-size:13px;color:var(--p,#6C5CE7);font-weight:600">Starting generation…</div>
          <div style="height:4px;background:var(--bd,#2a2d3e);border-radius:2px;margin-top:12px;overflow:hidden"><div id="ob-prog-bar" style="height:100%;background:linear-gradient(90deg,var(--p,#6C5CE7),var(--ac,#00E5A8));border-radius:2px;width:5%;transition:width .5s"></div></div>
        </div>
      </div>`;
    _obGenerate();
  } else if (step === 5) {
    area.innerHTML = `
      <div style="text-align:center;padding:20px 0">
        <div style="font-size:56px;margin-bottom:16px">${window.icon('star',14)}</div>
        <h2 style="font-family:var(--fh,'Syne'),sans-serif;font-size:24px;font-weight:800;color:var(--t1,#e0e0e0);margin:0 0 8px">Your workspace is ready.</h2>
        <p style="color:var(--t2,#aaa);font-size:14px;margin:0 0 28px">Website generated for <strong style="color:var(--t1)">${_luEsc(_ob.name)}</strong>. Your AI marketing team is standing by.</p>
        <div style="background:var(--s2,#1E2230);border-radius:10px;padding:16px;margin-bottom:24px;text-align:left">
          <div style="font-size:12px;color:var(--t3,#777);margin-bottom:8px">WHAT'S READY</div>
          <div style="font-size:13px;color:var(--t1,#e0e0e0);line-height:1.8">${window.icon('check',14)} Website generated<br>${window.icon('check',14)} AI agents briefed<br>${window.icon('check',14)} Trial credits active (50 credits)</div>
        </div>
        <button class="btn btn-primary" style="width:100%;justify-content:center;font-size:15px;padding:14px" onclick="_obComplete()">Enter Dashboard →</button>
      </div>`;
  }
}

function _obSelectGoal(g) {
  _obSave('goal', g);
  _obRenderStep(2); // re-render to show selection
}

window._obSelectPalette=function(idx){
  _obSave('palette_idx',idx);
  _obRenderStep(3);
};
window._obSelectFont=function(idx){
  _obSave('font_idx',idx);
  _obRenderStep(3);
};
function _obNext3(){
  // Brand step done → go to generation (step 4)
  // Store selected palette + font
  var industry=(_ob.industry||'').toLowerCase();
  var palettes={tech:[{p:'#6C5CE7',s:'#00CEC9',a:'#F4F7FB'},{p:'#0D1B2A',s:'#00E5A8',a:'#E8EDF5'},{p:'#3B82F6',s:'#EFF6FF',a:'#1E293B'}],interior:[{p:'#2D5016',s:'#F5F0E8',a:'#8B7355'},{p:'#1B2838',s:'#D4A843',a:'#F4F7FB'},{p:'#6B5B4B',s:'#E8DDD0',a:'#B87333'}],food:[{p:'#E8590C',s:'#FFF5E6',a:'#8B4513'},{p:'#5C3D2E',s:'#FFF8F0',a:'#D4A843'},{p:'#1A472A',s:'#F0F7E6',a:'#D4A843'}],health:[{p:'#0891B2',s:'#F0FDFA',a:'#5EEAD4'},{p:'#FFFFFF',s:'#14B8A6',a:'#F0FDFA'},{p:'#8B5CF6',s:'#FAF5FF',a:'#C4B5FD'}],legal:[{p:'#1B2838',s:'#D4A843',a:'#F4F7FB'},{p:'#374151',s:'#D1D5DB',a:'#F9FAFB'},{p:'#1E3A5F',s:'#FFFFFF',a:'#60A5FA'}],fashion:[{p:'#EC4899',s:'#0F0F0F',a:'#FDF2F8'},{p:'#FFFFFF',s:'#F43F5E',a:'#FFF1F2'},{p:'#DC2626',s:'#0F0F0F',a:'#FFFFFF'}],fitness:[{p:'#DC2626',s:'#0F0F0F',a:'#FFFFFF'},{p:'#EA580C',s:'#1C1917',a:'#FFF7ED'},{p:'#16A34A',s:'#0F0F0F',a:'#F0FDF4'}]};
  var palKey='tech';
  if(/interior|real.estate|furniture|home/i.test(industry))palKey='interior';
  else if(/food|restaurant|cafe|bakery/i.test(industry))palKey='food';
  else if(/health|medical|dental|clinic/i.test(industry))palKey='health';
  else if(/law|legal|finance|accounting/i.test(industry))palKey='legal';
  else if(/fashion|retail|clothing|beauty/i.test(industry))palKey='fashion';
  else if(/fitness|gym|sport|yoga/i.test(industry))palKey='fitness';
  var pals=palettes[palKey]||palettes.tech;
  var sel=pals[_ob.palette_idx||0]||pals[0];
  _obSave('primary_color',sel.p);
  _obSave('secondary_color',sel.s);
  _obSave('accent_color',sel.a);
  var fonts=[['Syne','DM Sans'],['Playfair Display','Inter'],['Space Grotesk','Manrope']];
  var f=fonts[_ob.font_idx||0]||fonts[0];
  _obSave('font_heading',f[0]);
  _obSave('font_body',f[1]);
  _obRenderStep(4);
}
function _obNext0() {
  var name = (document.getElementById('ob-name')||{}).value||'';
  if (!name.trim()) { if(typeof showToast==='function') showToast('Enter your business name.','error'); return; }
  _obSave('name', name.trim());
  _obSave('location', (document.getElementById('ob-loc')||{}).value||'');
  _obRenderStep(1);
}

function _obNext1() {
  var ind = (document.getElementById('ob-ind')||{}).value||'';
  if (!ind.trim()) { if(typeof showToast==='function') showToast('Enter your industry.','error'); return; }
  _obSave('industry', ind.trim());
  var svc = (document.getElementById('ob-svc')||{}).value||'';
  _obSave('services', svc.split(',').map(function(s){ return s.trim(); }).filter(Boolean));
  _obRenderStep(2);
}

function _obNext2() {
  if (!_ob.goal) { if(typeof showToast==='function') showToast('Select a goal.','error'); return; }
  _obRenderStep(3);
}

async function _obGenerate() {
  try {
    // Save workspace settings (existing endpoint)
    await _luFetch('POST', '/workspace/settings', {
      business_name: _ob.name,
      industry:      _ob.industry,
      services:      Array.isArray(_ob.services) ? _ob.services.join(', ') : _ob.services,
      business_desc: _ob.goal,
      // LEAD-2: the owner's zone — bookings and form times are parsed in it, not UTC.
      timezone:      (function(){ try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch(_) { return ''; } })(),
    });

    // Page map by goal (task 2.2)
    var pageMap = {
      leads:     ['home','services','about','contact'],
      brand:     ['home','about','services','contact'],
      ecommerce: ['home','products','about','contact'],
      portfolio: ['home','portfolio','about','contact'],
    };

    // TASK 2.2: use existing POST api/websites/create
    var r = await _luFetch('POST', '/builder/wizard', {
      wizard_mode:   true,
      business_name: _ob.name,
      industry:      _ob.industry,
      goal:          _ob.goal,
      location:      _ob.location,
      services:      _ob.services,
      pages:         pageMap[_ob.goal] || ['home','about','services','contact'],
      primary_color: _ob.primary_color || '#6C5CE7',
      secondary_color: _ob.secondary_color || '#00E5A8',
      accent_color:  _ob.accent_color || '#F4F7FB',
      font_heading:  _ob.font_heading || 'Syne',
      font_body:     _ob.font_body || 'DM Sans',
    });
    var d = await safeJson(r);
    if (!d) throw new Error('Server error \u2014 could not start website generation. Please try again.');
    if (!d.website_id) throw new Error(d.error || d.message || 'Website creation failed');
    _obSave('website_id', d.website_id);

    // Poll until complete (task 2.2)
    var pct = 10;
    for (var i = 0; i < 30; i++) {
      await new Promise(function(res){ setTimeout(res, 2000); });
      var sr = await _luFetch('GET', '/builder/websites/' + d.website_id);
      var sd = await safeJson(sr);
      if (!sd) continue; // skip this poll cycle on error
      var pagesTotal = sd.pages_total || 4;
      var pagesDone  = sd.pages_generated || sd.pages_done || 0;
      pct = Math.max(pct, Math.round(10 + (pagesDone / pagesTotal) * 80));
      var bar = document.getElementById('ob-prog-bar');
      var lbl = document.getElementById('ob-progress');
      if (bar) bar.style.width = pct + '%';
      if (lbl) lbl.textContent = 'Generating… (' + pagesDone + '/' + pagesTotal + ' pages)';
      if (sd.status === 'complete' || sd.status === 'published') {
        var bar2 = document.getElementById('ob-prog-bar');
        if (bar2) bar2.style.width = '100%';
        _obRenderStep(5);
        return;
      }
    }
    // Timeout fallback — still show done
    _obRenderStep(5);
  } catch(e) {
    var lbl = document.getElementById('ob-progress');
    if (lbl) lbl.textContent = 'Generation issue: ' + e.message + ' — continuing anyway.';
    setTimeout(function(){ _obRenderStep(5); }, 2000);
  }
}

// TASK 2.3
function _obComplete() {
  localStorage.setItem('lu_onboarded', '1');
  // Clear onboarding state
  ['step','name','industry','services','goal','location','website_id'].forEach(function(k){
    localStorage.removeItem('lu_ob_' + k);
  });
  _appEnterDashboard();
  // Navigate to command center
  setTimeout(function(){ if (typeof nav === 'function') nav('command'); }, 300);
}


// Wave 47g — public credit-balance helpers so other modules can refresh
// the sidebar widget after credit-spending actions.
window.luRefreshCredits = function () {
  if (typeof _checkTrialStatus === 'function') _checkTrialStatus();
};
window.luSetCreditBalance = function (n) {
  var v = parseFloat(n);
  if (isNaN(v)) return;
  var valEl = document.getElementById('sb-credit-val');
  if (valEl) valEl.textContent = Math.round(v);
  // Update the bar width too, if we know the limit.
  var barEl = document.getElementById('sb-credit-bar');
  if (barEl && window._luCreditLimit) {
    var pct = Math.min(100, (v / window._luCreditLimit) * 100);
    barEl.style.width = pct + '%';
    barEl.style.background = pct < 20 ? 'var(--rd)' : pct < 50 ? 'var(--am)' : 'var(--ac)';
  }
};

// ── Trial credit warning ──────────────────────────────────────────────────────
async function _checkTrialStatus() {
  try {
    var r = await _luFetch('GET', '/workspace/status');
    if (!r.ok) return;
    var s = await r.json();
    var balance = s.credit_balance || 0;
    var limit = s.monthly_credit_limit || 0;
    window._luCreditLimit = limit;
    var plan = (s.plan && s.plan.plan_slug) ? s.plan.plan_slug : 'free';

    // Show credit widget in sidebar
    var widget = document.getElementById('sb-credit-widget');
    if (widget) {
      widget.style.display = 'block';
      var valEl = document.getElementById('sb-credit-val');
      var barEl = document.getElementById('sb-credit-bar');
      var subEl = document.getElementById('sb-credit-sub');
      var badgeEl = document.getElementById('sb-plan-badge');
      if (valEl) valEl.textContent = Math.round(balance);
      // MONEY-1: during the 3-day trial the meter is the trial grant, and the customer should see when it ends.
      if (subEl) subEl.textContent = s.is_trial
        ? ('trial credits · ends ' + (s.trial_expires_at ? new Date(s.trial_expires_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : 'soon'))
        : (limit > 0 ? 'of ' + limit + ' monthly limit' : 'credits available');
      if (barEl) {
        var pct = limit > 0 ? Math.min(100, (balance / limit) * 100) : (balance > 0 ? 100 : 0);
        barEl.style.width = pct + '%';
        barEl.style.background = pct < 20 ? 'var(--rd)' : pct < 50 ? 'var(--am)' : 'var(--ac)';
      }
      if (badgeEl) badgeEl.textContent = (plan.charAt(0).toUpperCase() + plan.slice(1)) + (s.is_trial ? ' trial' : '');
    }

    // Low credit warning (once per session)
    if (!sessionStorage.getItem('lu_trial_warned') && balance < 15 && balance > 0) {
      sessionStorage.setItem('lu_trial_warned', '1');
      if (typeof showToast === 'function') showToast(Math.round(balance) + ' credits remaining.', 'warning');
    }

    // ── NAV GATING: show/hide nav items based on plan features ──
    var features = (s.plan && s.plan.features) ? s.plan.features : {};
    document.querySelectorAll('[data-feature]').forEach(function(el) {
      var feat = el.getAttribute('data-feature');
      // 2026-05-12 — In embed mode (WP plugin iframe), force-show Pipeline
      // and Write regardless of plan feature flags. The bundle plans
      // already grant access; this is a belt-and-suspenders override so
      // a stale features_json or plan-data fetch race can't hide them.
      if (window._LGSC_EMBED && el.id === 'ni-write') {
        el.style.display = '';
        return;
      }
      el.style.display = features[feat] ? '' : 'none';
    });

    // ── ADMIN GATING: check cached user data (no extra API call) ──
    try {
      var cachedUser = localStorage.getItem('lu_user');
      var isAdmin = false;
      if (cachedUser) {
        try { isAdmin = JSON.parse(cachedUser).is_platform_admin; } catch(_) {}
      }
      if (!isAdmin) {
        // Fallback: check from auth/me only if not cached
        var meR = await _luFetch('GET', '/auth/me');
        if (meR.ok) {
          var meD = await meR.json();
          isAdmin = meD.user && meD.user.is_platform_admin;
          if (meD.user) localStorage.setItem('lu_user', JSON.stringify(meD.user));
        }
      }
      document.querySelectorAll('[data-admin-only]').forEach(function(el) {
        el.style.display = isAdmin ? '' : 'none';
      });
      window._luIsAdmin = isAdmin;
    } catch(_adminErr) {}

  } catch(_) {}
}

// ── TASK 4.4: Notification bell ───────────────────────────────────────────────
var _notifPollTimer = null;

function _notifStartPolling() {
  if (_notifPollTimer) return;
  _notifPoll();
  _notifPollTimer = setInterval(_notifPoll, 30000);
}

async function _notifPoll() {
  try {
    // PATCH 10 Fix 4 — Use the dedicated unread-count endpoint (returns
    // {count: N}). Previous version called /notifications?unread=true&limit=5
    // and read d.total_unread, which the controller never returns — bell
    // perpetually showed zero regardless of real unread state.
    // Backend: app/Http/Controllers/Api/NotificationController.php:43 unreadCount()
    var r = await _luFetch('GET', '/notifications/unread-count');
    if (!r.ok) return;
    var d = await r.json();
    var count = (typeof d.count === 'number') ? d.count : (d.total_unread || 0);
    var bell = document.getElementById('lu-notif-bell');
    if (!bell) return;
    if (count > 0) {
      bell.innerHTML = ''+window.icon("info",14)+' <span style="background:var(--rd,#F87171);color:#fff;border-radius:10px;padding:1px 5px;font-size:10px;font-weight:700;margin-left:2px">' + count + '</span>';
      bell.title = count + ' unread notification' + (count > 1 ? 's' : '');
    } else {
      bell.innerHTML = ''+window.icon("info",14)+'';
      bell.title = 'No new notifications';
    }
  } catch(_) {}
}

async function _openNotifications() {
  try {
    var r = await _luFetch('GET', '/notifications?limit=20');
    var d = await r.json();
    var items = d.notifications || [];
    var bd = document.createElement('div');
    bd.className = 'modal-backdrop';
    bd.onclick = function(e){ if(e.target===bd) bd.remove(); };
    bd.innerHTML = '<div class="modal" style="max-width:400px">'
      + '<div class="modal-header"><h3>Notifications</h3><button class="modal-close" onclick="this.closest(\'.modal-backdrop\').remove()">✕</button></div>'
      + '<div class="modal-body" style="padding:0;max-height:400px;overflow-y:auto">'
      + (items.length === 0
          ? '<div style="padding:32px;text-align:center;color:var(--t3);font-size:13px">No notifications</div>'
          : items.map(function(n){
              var unread = !n.read_at;
              return '<div style="display:flex;gap:10px;padding:12px 16px;border-bottom:1px solid var(--bd);background:' + (unread?'rgba(108,92,231,.06)':'') + ';cursor:pointer" onclick="_markNotifRead(' + n.id + ',this)">'
                + '<div style="width:8px;height:8px;border-radius:50%;background:' + (unread?'var(--p,#6C5CE7)':'transparent') + ';flex-shrink:0;margin-top:4px"></div>'
                + '<div style="flex:1"><div style="font-size:13px;font-weight:' + (unread?'600':'400') + ';color:var(--t1)">' + _luEsc(n.title || 'LevelUpGrowth') + '</div>'
                + (n.body ? '<div style="font-size:12px;color:var(--t2);margin-top:2px;line-height:1.4">' + _luEsc(n.body) + '</div>' : '')
                + '<div style="font-size:11px;color:var(--t3);margin-top:3px">' + window._luParseTs(n.created_at).toLocaleString() + '</div></div></div>';
            }).join(''))
      + '</div></div>';
    document.body.appendChild(bd);
    bd.style.opacity = '1'; bd.style.pointerEvents = 'all';
    // Mark all visible unread notifications
    _notifPoll();
  } catch(e) { if(typeof showToast==='function') showToast('Could not load notifications','error'); }
}

async function _markNotifRead(id, el) {
  try {
    await _luFetch('POST', '/notifications/' + id + '/read');
    if (el) { el.style.background = ''; el.querySelector('div[style*="border-radius:50%"]').style.background = 'transparent'; }
    _notifPoll();
  } catch(_) {}
}


// ── REVIEW QUEUE v5.5.1 ────────────────────────────────────────────────────
// Dedicated page for managing pending approvals. All data from /api/approvals.
// Reuses cmd-nav-badge-approvals counter + styles from cmd-* classes.

var _aqState = {
  status: 'pending',
  category: 'all',  // 2026-05-27 — Phase 2 category filter
  page: 1,
  perPage: 20,
  selected: new Set(),
  stats: null,
  pollTimer: null,
};

// 2026-05-27 — Phase 2: client-side filter pills above the approval list.
// Renders into #aq-category-filter (added by _aqRenderList).
function _aqCategoryFilterHtml() {
  var cats = [
    { slug: 'all',        label: 'All',        color: 'var(--p)' },
    { slug: 'research',   label: 'Research',   color: '#3B82F6' },
    { slug: 'create',     label: 'Create',     color: '#7C3AED' },
    { slug: 'optimize',   label: 'Optimize',   color: '#00E5A8' },
    { slug: 'publish',    label: 'Publish',    color: '#F59E0B' },
    { slug: 'crm',        label: 'CRM',        color: '#EC4899' },
    { slug: 'operations', label: 'Operations', color: '#6B7280' },
  ];
  return '<div id="aq-category-filter" style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">'
    + cats.map(function (c) {
        var active = _aqState.category === c.slug;
        return '<button onclick="_aqSetCategory(\'' + c.slug + '\')" '
          + 'style="padding:5px 11px;background:' + (active ? c.color : 'var(--s2)') + ';'
          + 'color:' + (active ? '#fff' : 'var(--t2)') + ';'
          + 'border:1px solid ' + (active ? c.color : 'var(--bd)') + ';'
          + 'border-radius:99px;cursor:pointer;font-size:11px;font-weight:600;font-family:var(--fh);'
          + 'transition:all .15s">'
          + (active ? '' : '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:' + c.color + ';margin-right:5px;vertical-align:middle"></span>')
          + c.label
          + '</button>';
      }).join('')
    + '</div>';
}

window._aqSetCategory = function (slug) {
  _aqState.category = slug;
  _aqState.page = 1;
  _aqFetchList();
};

window.loadApprovals = async function loadApprovals() {
  _aqState.selected = new Set();
  _aqBulkClear();
  _aqStartPolling();
  await Promise.all([_aqFetchStats(), _aqFetchList()]);
};

function _aqStartPolling() {
  if (_aqState.pollTimer) return;
  _aqState.pollTimer = setInterval(function() {
    if (document.hidden) return;
    if (!_aqIsView()) { _aqStopPolling(); return; }
    _aqFetchStats();
    if (_aqState.status === 'pending') _aqFetchList(true);
  }, 60000);
}
function _aqStopPolling() {
  if (_aqState.pollTimer) { clearInterval(_aqState.pollTimer); _aqState.pollTimer = null; }
}
function _aqIsView() {
  var v = document.getElementById('view-approvals');
  return v && v.classList.contains('active');
}
function _aqReload() { _aqFetchStats(); _aqFetchList(); }

async function _aqFetchStats() {
  try {
    var r = await _luFetch('GET', '/approvals/stats');
    if (!r.ok) return;
    var s = await r.json();
    _aqState.stats = s;
    _aqRenderStats(s);
    // Nav badges
    var nav = document.getElementById('nav-approval-badge');
    if (nav) { if (s.pending > 0) { nav.textContent = s.pending > 99 ? '99+' : s.pending; nav.classList.add('show'); } else nav.classList.remove('show'); }
    _cmdcSetNavBadge && _cmdcSetNavBadge(s.pending || 0);
  } catch(_) {}
}

function _aqRenderStats(s) {
  var byId = function(id){ return document.getElementById(id); };
  var pending = byId('aq-stat-pending'); if (pending) pending.textContent = s.pending;
  var tabc    = byId('aq-tabc-pending'); if (tabc)    tabc.textContent = s.pending;
  var approved = byId('aq-stat-approved'); if (approved) approved.textContent = s.approved_today;
  var approvedMeta = byId('aq-stat-approved-meta'); if (approvedMeta) approvedMeta.textContent = 'this week: ' + s.approved_this_week;
  var avg = byId('aq-stat-avg'); if (avg) avg.textContent = (s.avg_response_hours != null) ? s.avg_response_hours + ' hrs' : '—';
  var oldest = byId('aq-stat-oldest');
  var oldestMeta = byId('aq-stat-oldest-meta');
  var oldestCard = byId('aq-stat-oldest-card');
  if (oldest) {
    if (s.oldest_pending_hours > 0) {
      var h = s.oldest_pending_hours;
      var txt = h >= 24 ? Math.floor(h / 24) + 'd' : h + 'h';
      oldest.textContent = txt;
      if (oldestMeta) oldestMeta.textContent = h > 24 ? 'waiting more than a day' : 'waiting'; // P0-E: no invented SLA (REPORT-0023 UX-022)
    } else { oldest.textContent = '—'; if (oldestMeta) oldestMeta.textContent = 'no pending'; }
  }
  if (oldestCard) {
    if (s.is_overdue) oldestCard.classList.add('aq-overdue');
    else oldestCard.classList.remove('aq-overdue');
  }
  // Show Expire-stale button only if any pending >= 30 days
  var expBtn = byId('aq-expire-btn');
  if (expBtn) expBtn.style.display = (s.oldest_pending_hours >= 24 * 30) ? 'inline-block' : 'none';

  // Subhead summary
  var sub = byId('aq-subhead');
  if (sub) {
    var overCount = s.is_overdue ? (' · <strong style="color:var(--rd)">' + Math.floor(s.oldest_pending_hours / 24) + 'd overdue</strong>') : '';
    sub.innerHTML = (s.pending || 0) + ' pending' + overCount;
  }
}

async function _aqFetchList(silent) {
  var list = document.getElementById('aq-list');
  if (!silent && list) list.innerHTML = '<div class="cmd-empty">Loading…</div>';
  try {
    var qs = '?status=' + encodeURIComponent(_aqState.status) + '&page=' + _aqState.page + '&per_page=' + _aqState.perPage;
    var r = await _luFetch('GET', '/approvals' + qs);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    var d = await r.json();
    _aqRenderList(d);
  } catch(e) {
    if (list) list.innerHTML = '<div class="cmd-empty">Could not load approvals: ' + _cmdcEsc(e.message || '') + '</div>';
  }
}

function _aqRenderList(d) {
  var list = document.getElementById('aq-list');
  var pager = document.getElementById('aq-pager');
  if (!list) return;
  // 2026-05-27 — Phase 2: category filter row above the cards (always shown)
  var filterRow = _aqCategoryFilterHtml();
  if (!d.items.length) {
    list.innerHTML = filterRow + _aqEmptyStateFor(_aqState.status);
    if (pager) pager.style.display = 'none';
    return;
  }
  // Client-side category filter (server pagination still applies on status)
  var items = d.items;
  if (_aqState.category && _aqState.category !== 'all') {
    items = items.filter(function (it) {
      return it.task && it.task.category === _aqState.category;
    });
  }
  if (!items.length) {
    list.innerHTML = filterRow + '<div class="cmd-empty" style="padding:40px 16px">No ' + _cmdcEsc(_aqState.status) + ' approvals in the <b>' + _cmdcEsc(_aqState.category) + '</b> category on this page.</div>';
    if (pager) pager.style.display = 'flex';  // pagination may have more
    return;
  }
  list.innerHTML = filterRow + items.map(_aqCardHtml).join('');
  // Pagination
  if (pager) {
    if (d.pages > 1) {
      pager.style.display = 'flex';
      document.getElementById('aq-page-label').textContent = 'Page ' + d.page + ' of ' + d.pages;
      document.getElementById('aq-prev').disabled = d.page <= 1;
      document.getElementById('aq-next').disabled = d.page >= d.pages;
    } else pager.style.display = 'none';
  }
}

function _aqEmptyStateFor(status) {
  if (status === 'pending') {
    return '<div class="cmd-empty" style="padding:48px 16px">' +
      '<div style="font-size:28px;margin-bottom:8px;color:var(--ac)">✓</div>' +
      "<div style=\"color:var(--t1);font-weight:600;margin-bottom:4px\">You're all caught up.</div>" +
      '<div>Sarah will notify you when new approvals need your attention.</div></div>';
  }
  return '<div class="cmd-empty" style="padding:40px 16px">No ' + _cmdcEsc(status) + ' approvals in this workspace.</div>';
}

function _aqCardHtml(it) {
  var isPending = it.status === 'pending';
  var task = it.task;
  var label = task ? _cmdcEsc(task.label) : 'Orphan approval (no attached task)';
  var desc  = task && task.description ? '<div class="aq-card-desc">' + _cmdcEsc(task.description) + '</div>' : '';
  var agent = task ? task.agent : { name: 'Sarah', slug: 'sarah', color: '#F59E0B' };
  var engineBadge = task ? task.engine_badge : { name: 'system', color: '#8B97B0' };
  var orb = _cmdcOrbHtml(agent);
  var ageCls = it.age_hours < 1 ? 'aq-age-fresh' : (it.age_hours < 24 ? 'aq-age-warn' : 'aq-age-old');
  var ageTxt = _cmdcEsc(it.time_ago) + (it.is_overdue ? ' · overdue' : '');
  var orphanChip = it.is_orphan ? '<span class="aq-orphan-chip">Stale</span>' : '';
  // P1R-16: white ink is only legible on a DARK engine colour. Derive it from the colour the server sent.
  var _badgeInk = (function (hex) {
    try {
      var h = String(hex || '').replace('#', '');
      if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
      if (h.length !== 6) return '#fff';
      var lin = [0, 2, 4].map(function (i) {
        var v = parseInt(h.substr(i, 2), 16) / 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
      });
      var lum = 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
      var vsWhite = 1.05 / (lum + 0.05);            // white ink on this fill
      var vsInk   = (lum + 0.05) / 0.0555;          // #0F1117 on this fill
      if (vsInk >= 4.5 || vsWhite >= 4.5) return vsInk > vsWhite ? '#0F1117' : '#fff';
      return 'band';                                 // neither clears — caller darkens the fill
    } catch (e) { return '#fff'; }
  })(engineBadge.color);
  // The mid-tone band is narrow; darkening the fill 25% puts white ink safely over 4.5:1.
  var _badgeBg = engineBadge.color;
  if (_badgeInk === 'band') {
    _badgeInk = '#fff';
    try {
      var _bh = String(engineBadge.color || '').replace('#', '');
      if (_bh.length === 3) _bh = _bh[0] + _bh[0] + _bh[1] + _bh[1] + _bh[2] + _bh[2];
      if (_bh.length === 6) {
        _badgeBg = '#' + [0, 2, 4].map(function (i) {
          var v = Math.round(parseInt(_bh.substr(i, 2), 16) * 0.75);
          return ('0' + v.toString(16)).slice(-2);
        }).join('');
      }
    } catch (e) {}
  }
  var engineChip = '<span class="aq-engine-badge" style="background:' + _badgeBg + ';color:' + _badgeInk + '">' + _cmdcEsc(engineBadge.name) + '</span>';
  var agentLine  = '<span class="aq-agent-line"><span style="width:6px;height:6px;border-radius:50%;background:' + agent.color + '"></span>' + _cmdcEsc(agent.name) + '</span>';
  var creditLine = task && task.credit_cost ? '<span style="font-size:11px;color:var(--t3)">· ' + task.credit_cost + ' credits</span>' : '';
  // 2026-05-27 — meeting-origin chip: surface Strategy Room source so the user
  // can mentally connect this approval to the meeting that produced it. Click
  // opens a transcript modal via window._aqOpenMeetingModal.
  // 2026-05-27 — category chip (Phase 2 surface visibility)
  var categoryChip = '';
  if (task && task.category && task.category_color) {
    categoryChip = '<span class="aq-category-chip" '
      + 'data-category="' + _cmdcEsc(task.category) + '" '
      + 'title="' + _cmdcEsc(task.category_label || task.category) + ' task" '
      + 'style="background:' + task.category_color + '22;color:' + task.category_color + ';'
      + 'border:1px solid ' + task.category_color + '55;border-radius:99px;padding:2px 8px;'
      + 'font-size:10px;font-weight:600;font-family:var(--fh);display:inline-flex;align-items:center;gap:4px">'
      + '<span style="width:6px;height:6px;border-radius:50%;background:' + task.category_color + '"></span>'
      + _cmdcEsc(task.category_label || task.category)
      + '</span>';
  }
  var meetingChip = '';
  if (task && task.from_meeting && task.from_meeting.id) {
    var mt = task.from_meeting;
    var mtTitle = _cmdcEsc((mt.title || 'Strategy meeting').replace(/^Strategy:\s*/, ''));
    meetingChip = '<span class="aq-meeting-chip" onclick="_aqOpenMeetingModal(' + mt.id + ')" '
      + 'title="View meeting #' + mt.id + ' transcript" '
      + 'style="background:rgba(108,92,231,.12);color:var(--pu);border:1px solid rgba(108,92,231,.3);'
      + 'border-radius:99px;padding:2px 8px;font-size:10px;font-weight:600;cursor:pointer;'
      + 'display:inline-flex;align-items:center;gap:4px;font-family:var(--fh)">'
      + '✨ Meeting #' + mt.id + ' · ' + mtTitle + '</span>';
  }

  var payloadBits = '';
  if (task && Array.isArray(task.payload_keys) && task.payload_keys.length) {
    payloadBits = '<div class="aq-card-payload">' + task.payload_keys.map(function(k){ return '<code>' + _cmdcEsc(k) + '</code>'; }).join('') + '</div>';
  }

  var actions = '';
  if (isPending) {
    actions = '<div class="aq-card-actions">' +
      '<button class="aq-btn aq-btn-reject" onclick="_aqRejectToggle(' + it.id + ')">Reject</button>' +
      '<button class="aq-btn aq-btn-approve" onclick="_aqApprove(' + it.id + ')"' + (it.is_orphan ? ' disabled title="Orphan approvals cannot be approved — reject or expire"' : '') + '>Approve →</button>' +
      '</div>';
  } else {
    // Read-only footer
    var decidedAgo = it.decided_at ? ' · ' + _cmdcEsc(window._luParseTs(it.decided_at).toLocaleString()) : '';
    actions = '<div style="font-size:11.5px;color:var(--t3)">' + _cmdcEsc(it.status) + decidedAgo + '</div>';
  }

  var selCheckbox = isPending && !it.is_orphan ? '<input type="checkbox" class="aq-checkbox" data-id="' + it.id + '" onchange="_aqToggleSelect(' + it.id + ',this.checked)"' + (_aqState.selected.has(it.id) ? ' checked' : '') + '>' : '<span style="width:16px;flex-shrink:0"></span>';

  var noteBlock = it.decision_note ? '<div class="aq-decision-note"><strong>Note:</strong> ' + _cmdcEsc(it.decision_note) + '</div>' : '';

  return '<div class="aq-card" data-id="' + it.id + '">' +
    '<div class="aq-card-head">' +
      selCheckbox +
      orb +
      '<div class="aq-card-meta">' +
        '<div class="aq-card-title">' + label + orphanChip + '</div>' +
        '<div class="aq-card-sub">' + categoryChip + engineChip + agentLine + creditLine + meetingChip + '</div>' +
      '</div>' +
    '</div>' +
    desc +
    payloadBits +
    noteBlock +
    '<div class="aq-card-foot">' +
      '<span class="aq-age ' + ageCls + '">' + ageTxt + '</span>' +
      actions +
    '</div>' +
    '<div class="aq-reject-form" id="aq-reject-' + it.id + '" style="display:none">' +
      '<textarea id="aq-reject-text-' + it.id + '" placeholder="Why are you rejecting this? (required)"></textarea>' +
      '<div class="aq-reject-actions">' +
        '<button class="aq-btn aq-btn-reject" onclick="_aqRejectToggle(' + it.id + ')">Cancel</button>' +
        '<button class="aq-btn aq-btn-approve" style="background:var(--rd);border-color:var(--rd)" onclick="_aqReject(' + it.id + ')">Confirm Reject</button>' +
      '</div>' +
    '</div>' +
  '</div>';
}

// ── Meeting-source transcript modal (opened from approval card meeting chip) ─
// Fetches the meeting transcript and renders a read-only modal so the user
// can see what was discussed and decided before approving/rejecting the task
// it produced. No edit affordances — purely contextual.
window._aqOpenMeetingModal = async function(meetingId) {
  meetingId = parseInt(meetingId, 10);
  if (!meetingId) return;
  var bd = document.getElementById('aq-meeting-modal');
  if (!bd) {
    bd = document.createElement('div');
    bd.id = 'aq-meeting-modal';
    bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9000;display:none;align-items:center;justify-content:center;padding:20px';
    bd.innerHTML = '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);max-width:720px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.6)">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--bd)">'
      + '<div id="aq-mm-title" style="font-family:var(--fh);font-size:14px;font-weight:700;color:var(--t1)">Meeting transcript</div>'
      + '<button onclick="document.getElementById(\'aq-meeting-modal\').style.display=\'none\'" style="background:none;border:none;color:var(--t2);cursor:pointer;font-size:18px;padding:0 6px">×</button>'
      + '</div>'
      + '<div id="aq-mm-body" style="flex:1;overflow-y:auto;padding:14px 18px;font-family:var(--fb);font-size:13px;color:var(--t1);line-height:1.6">Loading…</div>'
      + '</div>';
    document.body.appendChild(bd);
    bd.addEventListener('click', function(e){ if (e.target === bd) bd.style.display = 'none'; });
  }
  bd.style.display = 'flex';
  var titleEl = document.getElementById('aq-mm-title');
  var bodyEl = document.getElementById('aq-mm-body');
  if (bodyEl) bodyEl.innerHTML = 'Loading meeting #' + meetingId + '…';

  try {
    var r = await _luFetch('GET', '/meeting/' + meetingId);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    var d = await r.json();
    if (titleEl) titleEl.textContent = (d.title || ('Meeting #' + meetingId)) + (d.status ? ' · ' + d.status : '');
    var msgs = Array.isArray(d.messages) ? d.messages : [];
    if (!msgs.length) {
      bodyEl.innerHTML = '<div style="color:var(--t3);text-align:center;padding:40px">No transcript stored for this meeting.</div>';
      return;
    }
    bodyEl.innerHTML = msgs.map(function(m){
      var name = _cmdcEsc(m.sender_name || m.agent_name || (m.sender_type === 'user' ? 'You' : 'Agent'));
      var phase = m.phase ? '<span style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.05em;margin-left:6px">' + _cmdcEsc(m.phase) + '</span>' : '';
      var col   = m.sender_type === 'user' ? 'var(--ac)' : 'var(--p)';
      return '<div style="margin-bottom:14px;padding:10px 12px;background:var(--s2);border-radius:var(--r);border-left:2px solid ' + col + '">'
        + '<div style="font-family:var(--fh);font-size:12px;font-weight:700;color:' + col + ';margin-bottom:4px">' + name + phase + '</div>'
        + '<div style="white-space:pre-wrap;color:var(--t1)">' + _cmdcEsc(m.message || '') + '</div>'
        + '</div>';
    }).join('');
  } catch(e) {
    bodyEl.innerHTML = '<div style="color:var(--rd);text-align:center;padding:40px">Could not load meeting: ' + _cmdcEsc(e.message || '') + '</div>';
  }
};

// ── Actions ─────────────────────────────────────────────────────────────
function _aqSwitchTab(status) {
  _aqState.status = status;
  _aqState.page = 1;
  _aqState.selected = new Set();
  _aqBulkClear();
  // Visual
  document.querySelectorAll('.aq-tab').forEach(function(el){
    if (el.dataset.status === status) el.classList.add('active'); else el.classList.remove('active');
  });
  _aqFetchList();
}

async function _aqApprove(id) {
  var card = document.querySelector('.aq-card[data-id="' + id + '"]');
  if (card) { card.classList.add('aq-done'); card.style.transition = 'all 260ms'; card.style.borderColor = 'rgba(0,229,168,.3)'; card.style.background = 'rgba(0,229,168,.05)'; }
  try {
    var r = await _luFetch('POST', '/approvals/' + id + '/approve');
    var d = await r.json();
    if (!r.ok) throw new Error(d.error || ('HTTP ' + r.status));
    _aqRemoveCard(id);
    if (typeof _luToast === 'function') _luToast(d.message || 'Approved.');
    _aqFetchStats();
  } catch(e) {
    if (card) { card.classList.remove('aq-done'); card.style.background = ''; card.style.borderColor = ''; }
    if (typeof _luToast === 'function') _luToast('Approve failed: ' + (e.message || 'unknown'));
  }
}

function _aqRejectToggle(id) {
  var form = document.getElementById('aq-reject-' + id);
  if (!form) return;
  var showing = form.style.display === 'block';
  form.style.display = showing ? 'none' : 'block';
  if (!showing) { var ta = document.getElementById('aq-reject-text-' + id); if (ta) ta.focus(); }
}

async function _aqReject(id) {
  var ta = document.getElementById('aq-reject-text-' + id);
  var reason = ta ? ta.value.trim() : '';
  if (!reason) { if (typeof _luToast === 'function') _luToast('A rejection reason is required.'); ta && ta.focus(); return; }
  var card = document.querySelector('.aq-card[data-id="' + id + '"]');
  if (card) { card.classList.add('aq-done'); card.style.transition = 'all 260ms'; card.style.borderColor = 'rgba(248,113,113,.3)'; card.style.background = 'rgba(248,113,113,.05)'; }
  try {
    var r = await _luFetch('POST', '/approvals/' + id + '/reject', { reason: reason });
    var d = await r.json();
    if (!r.ok) throw new Error(d.error || ('HTTP ' + r.status));
    _aqRemoveCard(id);
    if (typeof _luToast === 'function') _luToast(d.message || 'Rejected.');
    _aqFetchStats();
  } catch(e) {
    if (card) { card.classList.remove('aq-done'); card.style.background = ''; card.style.borderColor = ''; }
    if (typeof _luToast === 'function') _luToast('Reject failed: ' + (e.message || 'unknown'));
  }
}

function _aqRemoveCard(id) {
  var card = document.querySelector('.aq-card[data-id="' + id + '"]');
  if (!card) return;
  card.style.maxHeight = card.offsetHeight + 'px';
  setTimeout(function(){ card.style.maxHeight = '0'; card.style.padding = '0 16px'; card.style.margin = '0'; card.style.opacity = '0'; }, 20);
  setTimeout(function(){ card.remove(); }, 300);
  _aqState.selected.delete(id);
  _aqRenderBulkBar();
}

// ── Bulk ─────────────────────────────────────────────────────────────────
function _aqToggleSelect(id, on) {
  if (on) _aqState.selected.add(id); else _aqState.selected.delete(id);
  _aqRenderBulkBar();
}
function _aqRenderBulkBar() {
  var bar = document.getElementById('aq-bulk');
  if (!bar) return;
  var n = _aqState.selected.size;
  if (n > 0) {
    bar.style.display = 'flex';
    document.getElementById('aq-bulk-count').textContent = n;
  } else bar.style.display = 'none';
}
function _aqBulkClear() {
  _aqState.selected = new Set();
  document.querySelectorAll('.aq-checkbox').forEach(function(el){ el.checked = false; });
  _aqRenderBulkBar();
}
async function _aqBulkApprove() {
  var ids = Array.from(_aqState.selected);
  if (!ids.length) return;
  if (!await luConfirm('Approve ' + ids.length + ' selected item' + (ids.length === 1 ? '' : 's') + '?', 'Your team will run these right away.', { okLabel: 'Approve' })) return;
  try {
    var r = await _luFetch('POST', '/approvals/bulk-approve', { ids: ids });
    var d = await r.json();
    if (typeof _luToast === 'function') _luToast('Approved ' + d.approved + (d.failed ? ' · failed ' + d.failed : '') + (d.skipped ? ' · skipped ' + d.skipped : ''));
    ids.forEach(function(id){ _aqRemoveCard(id); });
    _aqFetchStats();
  } catch(e) {
    if (typeof _luToast === 'function') _luToast('Bulk approve failed: ' + (e.message || 'unknown'));
  }
}
async function _aqBulkRejectPrompt() {
  var ids = Array.from(_aqState.selected);
  if (!ids.length) return;
  // A reason is required (same rule as the single-reject form) — re-prompt while empty, abort on cancel.
  var reason;
  for (;;) {
    reason = await luPrompt('Reason for rejecting ' + ids.length + ' item' + (ids.length === 1 ? '' : 's') + ' (required)', '', 'Tell your team why these are being rejected');
    if (reason === null) return;            // cancelled
    reason = String(reason).trim();
    if (reason) break;
    showToast('A rejection reason is required.', 'warning');
  }
  _aqBulkReject(ids, reason);
}
async function _aqBulkReject(ids, reason) {
  try {
    var r = await _luFetch('POST', '/approvals/bulk-reject', { ids: ids, reason: reason });
    var d = await r.json();
    if (typeof _luToast === 'function') _luToast('Rejected ' + d.rejected + (d.failed ? ' · failed ' + d.failed : ''));
    ids.forEach(function(id){ _aqRemoveCard(id); });
    _aqFetchStats();
  } catch(e) {
    if (typeof _luToast === 'function') _luToast('Bulk reject failed: ' + (e.message || 'unknown'));
  }
}

async function _aqExpireStalePrompt() {
  if (!await luConfirm('Expire stale approvals?', 'All pending approvals older than 30 days in this workspace will be expired.', { okLabel: 'Expire', danger: true })) return;
  try {
    var r = await _luFetch('POST', '/approvals/expire-stale', { older_than_days: 30 });
    var d = await r.json();
    if (typeof _luToast === 'function') _luToast('Expired ' + (d.expired || 0) + ' stale approval' + (d.expired === 1 ? '' : 's') + '.');
    _aqReload();
  } catch(e) {
    if (typeof _luToast === 'function') _luToast('Expire failed: ' + (e.message || 'unknown'));
  }
}

function _aqPrev() { if (_aqState.page > 1) { _aqState.page--; _aqFetchList(); } }
function _aqNext() { _aqState.page++; _aqFetchList(); }

// Pause polling on tab hide
document.addEventListener('visibilitychange', function() {
  if (!document.hidden && _aqIsView()) _aqReload();
});

// ── COMMAND CENTER v5.5.0 — REAL DATA ONLY ──────────────────────────────────
// Replaces the placeholder loadCommandCenter. Every number from /api/dashboard/overview.
// Polls every 30s (feed + stats), 60s (approval count). Pauses when tab hidden.

var _cmdcPollTimer      = null;
var _cmdcBadgeTimer     = null;
var _cmdcLastFeedIds    = new Set();  // timestamps we've seen (so we can animate new ones)
var _cmdcLastApprovalN  = -1;

window.loadCommandCenter = async function loadCommandCenter() {
  // P0 (2026-08-30): index.html ships the literal "team\'s" in the loading subhead — fix the copy on entry.
  try { var _sub0 = document.getElementById('cmd-subhead'); if (_sub0 && _sub0.textContent.indexOf("\\'") !== -1) _sub0.textContent = 'Loading your team\u2019s activity\u2026'; } catch (_e0) {}
  _cmdcLastFeedIds = new Set(); // reset on full reload so nothing animates on first paint
  await _cmdcFetchAndRender();
  _cmdcStartPolling();
  _notifStartPolling();
};

async function _cmdcFetchAndRender(isPoll) {
  try {
    var r = await _luFetch('GET', '/dashboard/overview');
    if (!r.ok) throw new Error('HTTP ' + r.status);
    var d = await r.json();
    _cmdcRenderAll(d, !!isPoll);
  } catch(e) {
    if (!isPoll) {
      var root = document.getElementById('cmd-root');
      if (root) {
        var sub = document.getElementById('cmd-subhead');
        if (sub) sub.textContent = 'Could not load dashboard. ' + (e.message || '');
      }
    }
    // Silent on poll failures.
  }
}

function _cmdcRenderAll(d, isPoll) {
  // Greeting
  var greet = document.getElementById('cmd-greeting');
  var sub   = document.getElementById('cmd-subhead');
  if (greet) {
    var hour = new Date().getHours();
    var salutation = hour < 12 ? 'Good morning' : (hour < 18 ? 'Good afternoon' : 'Good evening');
    var name = d.first_name ? (', ' + d.first_name) : '';
    greet.textContent = salutation + name + '. Sarah is working.';
  }
  if (sub) {
    var when = new Date().toLocaleDateString(undefined, { weekday:'long', month:'long', day:'numeric' });
    var agentsN = (d.stats && d.stats.active_agents) || (d.agents || []).length;
    sub.textContent = when + ' · ' + agentsN + ' agent' + (agentsN === 1 ? '' : 's') + ' on your workspace';
  }

  // KPIs
  _cmdcKpi('tasks',    d.stats.tasks_completed, (d.stats.tasks_today > 0 ? '<strong>+' + d.stats.tasks_today + '</strong> today' : (d.stats.tasks_this_week > 0 ? '+' + d.stats.tasks_this_week + ' this week' : 'No activity yet')));
  _cmdcKpi('content',  d.stats.articles_published, (d.stats.articles_total > d.stats.articles_published ? (d.stats.articles_total - d.stats.articles_published) + ' in draft' : (d.stats.articles_total === 0 ? 'Priya hasn\'t written yet' : 'All published')));
  _cmdcKpi('leads',    d.stats.leads_captured, (d.stats.leads_this_week > 0 ? '<strong>+' + d.stats.leads_this_week + '</strong> this week' : (d.stats.leads_captured === 0 ? 'No leads captured yet' : 'No new this week')));
  _cmdcKpi('keywords', d.stats.keywords_tracked, (d.stats.keywords_tracked === 0 ? 'James hasn\'t tracked any yet' : 'Tracked by James daily'));

  // Feed
  _cmdcRenderFeed(d.activity_feed || [], isPoll);

  // Strategy
  _cmdcRenderStrategy(d.latest_strategy, d.strategy_proposals_total, d.strategy_proposals_global);

  // Approvals
  _cmdcRenderApprovals(d.pending_approvals || [], d.approvals_pending_total || 0);
  _cmdcSetNavBadge(d.approvals_pending_total || 0);

  // Agents
  window._cmdcLastAgents = d.agents || [];
  _cmdcRenderAgents(window._cmdcLastAgents);
  // 2026-05-25 — kick off fresh per-agent stats so the cards show real
  // ongoing/pending/completed numbers (the dashboard endpoint's
  // tasks_this_week count is a different metric and was showing 0s).
  if (typeof loadAgentStats === 'function') loadAgentStats();

  // Websites + Meetings
  _cmdcRenderWebsites(d.websites || []);
  _cmdcRenderMeetings(d.recent_meetings || []);
}

function _cmdcKpi(key, val, metaHtml) {
  var v = document.getElementById('cmd-kpi-' + key);
  var m = document.getElementById('cmd-kpi-' + key + '-meta');
  if (v) v.textContent = (val === 0 || val == null) ? '0' : String(val);
  if (m) m.innerHTML   = metaHtml || '&nbsp;';
}

function _cmdcRenderFeed(items, isPoll) {
  var el = document.getElementById('cmd-feed');
  var countEl = document.getElementById('cmd-feed-count');
  if (!el) return;
  if (countEl) countEl.textContent = items.length ? (items.length + ' events') : '';
  if (!items.length) {
    el.innerHTML = '<div class="cmd-empty">Your team is warming up.<br>Sarah runs her first analysis within 24 hours of signup.</div>';
    return;
  }
  var prevIds = _cmdcLastFeedIds;
  var newIds  = new Set(items.map(function(i){ return i.timestamp; }));
  var html = items.map(function(it) {
    var orb = _cmdcOrbHtml(it.agent);
    var isNew = isPoll && !prevIds.has(it.timestamp) && prevIds.size > 0;
    var cls = 'cmd-feed-row' + (isNew ? ' cmd-feed-new' : '');
    return '<div class="' + cls + '">' + orb +
      '<div class="cmd-feed-body">' +
        '<div class="cmd-feed-label">' + _cmdcEsc(it.label) + '</div>' +
        '<div class="cmd-feed-time">' + _cmdcEsc(it.time_ago) + '</div>' +
      '</div></div>';
  }).join('');
  el.innerHTML = html;
  _cmdcLastFeedIds = newIds;
}

function _cmdcRenderStrategy(p, totalWs, totalGlobal) {
  var el = document.getElementById('cmd-strategy');
  var footer = document.getElementById('cmd-strategy-footer');
  if (!el) return;
  if (!p) {
    el.innerHTML = '<div class="cmd-empty">Sarah will present her first strategy within 24 hours of onboarding.</div>';
    if (footer) footer.innerHTML = '&nbsp;';   // P0 (2026-08-30): no platform-wide (global) counts on a customer surface
    return;
  }
  var statusClass = p.status === 'approved' ? 'cmd-chip-approved' : (p.status === 'pending' ? 'cmd-chip-pending' : 'cmd-chip-default');
  var desc = p.description ? (p.description.length > 140 ? p.description.substr(0, 137) + '…' : p.description) : '';
  var chips = '<span class="cmd-chip ' + statusClass + '">' + _cmdcEsc(window.LU_statusLabel ? window.LU_statusLabel(p.status) : (p.status || 'draft')) + '</span>' +
              (p.total_credits ? '<span class="cmd-chip cmd-chip-default">' + p.total_credits + ' credits</span>' : '');
  var btn = p.meeting_id
    ? '<button class="cmd-btn cmd-btn-approve" style="width:100%" onclick="nav(\'meeting\');if(typeof _meetingOpen===\'function\')_meetingOpen(' + p.meeting_id + ')">Review strategy →</button>'
    : '<button class="cmd-btn cmd-btn-approve" style="width:100%" onclick="nav(\'meeting\')">Open Strategy Room →</button>';
  el.innerHTML =
    '<div class="cmd-strategy-title">' + _cmdcEsc(p.title || 'Untitled strategy') + '</div>' +
    (desc ? '<div class="cmd-strategy-desc">' + _cmdcEsc(desc) + '</div>' : '') +
    '<div class="cmd-strategy-chips">' + chips + '</div>' +
    btn;
  if (footer) {
    var txt = (totalWs || 0) + ' on this workspace' + (p.time_ago ? ' · ' + p.time_ago : '');
    footer.textContent = txt;
  }
}

function _cmdcRenderApprovals(list, total) {
  var el = document.getElementById('cmd-approvals');
  var badge = document.getElementById('cmd-approval-badge');
  if (badge) {
    if (total > 0) { badge.style.display = 'inline-block'; badge.textContent = total; }
    else { badge.style.display = 'none'; }
  }
  if (!el) return;
  if (!list.length) {
    el.innerHTML = '<div class="cmd-empty" style="color:var(--ac)">✓ All clear — no actions waiting</div>';
    return;
  }
  // v1.4.4 (2026-05-30) — Buttons are no longer gated on `task_id`.
  // ApprovalService::requestIfNeeded() creates approvals BEFORE the task,
  // so task_id can legitimately be null. The previous "Task no longer
  // exists" tooltip was wrong — the task was never CREATED. Reject works
  // for orphan approvals (server handles task_id=null directly). Approve
  // currently 422s on orphans; the error toast surfaces the reason.
  //
  // v1.4.4 (2026-05-30, later) — batched approvals. When a row has
  // batch_count > 1, render "Approve all N" / "Reject all N" with a
  // sample-titles preview. Backend cascades to all sibling tasks by
  // batch_id, so one click decides the whole group.
  el.innerHTML = list.map(function(a) {
    var orb = _cmdcOrbHtml(a.agent);
    var orphan = !a.task_id;
    var isBatch = (a.batch_count || 1) > 1;
    var n       = a.batch_count || 1;
    var totalCr = a.batch_total_credits != null ? a.batch_total_credits : a.credit_cost;
    var approveLabel = isBatch ? ('Approve all ' + n) : 'Approve';
    var rejectLabel  = isBatch ? ('Reject all ' + n)  : 'Reject';
    var approveTitle = orphan ? 'Approve — orphan approval, server will explain if it can\'t auto-execute'
                       : (isBatch ? ('Approve all ' + n + ' ' + (a.action || 'tasks') + ' tasks in this batch — one click decides the group') : '');
    var rejectTitle  = orphan ? 'Reject — clears this orphan approval'
                       : (isBatch ? ('Reject all ' + n + ' tasks in this batch — single rejection reason applies to the whole group') : '');
    var batchBadge = isBatch
      ? '<span style="display:inline-block;background:var(--p);color:#fff;font-size:9px;font-weight:800;padding:2px 7px;border-radius:99px;margin-right:6px;letter-spacing:.05em">×' + n + '</span>'
      : '';
    var samplesHtml = '';
    if (isBatch && a.sample_titles && a.sample_titles.length) {
      var shown = a.sample_titles.slice(0, 3).map(function(t){ return '<li style="font-size:11px;color:var(--t3);margin:2px 0">' + _cmdcEsc(t) + '</li>'; }).join('');
      var more = a.sample_titles.length < n ? '<li style="font-size:11px;color:var(--t3);opacity:.7;margin:2px 0">…and ' + (n - a.sample_titles.length) + ' more</li>' : '';
      samplesHtml = '<ul style="list-style:none;padding:0;margin:6px 0 0 0;border-left:2px solid var(--bd);padding-left:10px">' + shown + more + '</ul>';
    }
    var labelText = isBatch
      ? (batchBadge + _cmdcEsc(_cmdcBatchLabel(a)))
      : _cmdcEsc(a.label);
    var metaBits = [_cmdcEsc(a.time_ago)];
    if (isBatch) metaBits.push(n + ' ' + (a.action || 'tasks'));
    if (totalCr) metaBits.push(totalCr + ' cr total');
    if (orphan)  metaBits.push('<span style="color:var(--am);opacity:.8">no attached task</span>');
    return '<div class="cmd-approval-row" data-id="' + a.id + '" data-batch="' + (a.batch_id||'') + '" data-count="' + n + '">' +
      '<div class="cmd-approval-head">' + orb + '<div class="cmd-approval-label">' + labelText + '</div></div>' +
      '<div class="cmd-approval-meta">' + metaBits.join(' · ') + '</div>' +
      samplesHtml +
      '<div class="cmd-approval-actions">' +
        '<button class="cmd-btn cmd-btn-approve" onclick="_cmdcApprovalAction(' + a.id + ',\'approve\',' + n + ')" title="' + approveTitle + '">' + approveLabel + '</button>' +
        '<button class="cmd-btn cmd-btn-reject"  onclick="_cmdcApprovalAction(' + a.id + ',\'reject\','  + n + ')"  title="' + rejectTitle + '">' + rejectLabel + '</button>' +
      '</div>' +
    '</div>';
  }).join('');
}

// v1.4.4 (2026-05-30) — friendly batched label
function _cmdcBatchLabel(a) {
  var n = a.batch_count || 1;
  var action = (a.action || '').toLowerCase();
  var unitMap = {
    'write_article':   ['article', 'articles'],
    'generate_meta':   ['meta block', 'meta blocks'],
    'insert_link':     ['internal link', 'internal links'],
    'generate_image':       ['image', 'images'],
    'generate_image_mini':  ['image', 'images'],
    'generate_image_high':  ['image', 'images'],
    'social_create_post':   ['social post', 'social posts'],
    'social_schedule_post': ['scheduled post', 'scheduled posts'],
    'create_lead':     ['lead', 'leads'],
    'create_campaign': ['campaign', 'campaigns'],
    'create_event':    ['calendar event', 'calendar events'],
    'add_page_from_template': ['page', 'pages'],
    'publish_article': ['article publish', 'article publishes'],
    'delete_article':  ['article delete', 'article deletes'],
    'delete_post':     ['post delete', 'post deletes'],
    'delete_lead':     ['lead delete', 'lead deletes'],
    'publish_website': ['site publish', 'site publishes'],
  };
  var unit = unitMap[action] || ['task', 'tasks'];
  var label = unit[n === 1 ? 0 : 1];
  // Include the agent's first name if known so the user sees who's doing it
  var agentName = (a.agent && a.agent.name) ? a.agent.name : '';
  return n + ' ' + label + (agentName ? ' — ' + agentName : '');
}

async function _cmdcApprovalAction(id, which, batchCount) {
  // Reject requires a reason. For a batch, mention the count so the user
  // knows their reason applies to all N tasks.
  var n = batchCount || 1;
  var body = null;
  if (which === 'reject') {
    var promptMsg = n > 1
      ? 'Reason for rejecting all ' + n + ' tasks in this batch (required):'
      : 'Reason for rejection (required):';
    var reason = await luPrompt(promptMsg.replace(/:$/, ''), '', 'Why is this being rejected?');
    if (reason === null) return;            // cancelled
    reason = (reason || '').trim();
    if (!reason) { if (typeof _luToast === 'function') _luToast('A rejection reason is required.'); return; }
    body = { reason: reason };
  }
  var row = document.querySelector('.cmd-approval-row[data-id="' + id + '"]');
  if (row) { row.style.transition = 'opacity 200ms,max-height 300ms'; row.style.opacity = '.3'; row.style.pointerEvents = 'none'; }
  try {
    var r = await _luFetch('POST', '/approvals/' + id + '/' + which, body);
    var payload = null;
    try { payload = await r.json(); } catch (_) { /* non-JSON */ }
    if (!r.ok) {
      var srvMsg = (payload && (payload.message || payload.hint || payload.error)) || ('HTTP ' + r.status);
      throw new Error(srvMsg);
    }
    if (row) { row.style.maxHeight = '0'; row.style.padding = '0'; row.style.margin = '0'; setTimeout(function(){ row.remove(); }, 280); }
    // Server returns a contextual message that includes cascade counts when
    // this was a batch. Fall back to a generic message if the server didn't
    // send one (older deploys).
    var toast = (payload && payload.message) || (which === 'approve'
        ? (n > 1 ? ('Approved — all ' + n + ' tasks will proceed.') : 'Approved — the task will proceed.')
        : (n > 1 ? ('Rejected — all ' + n + ' tasks cancelled.')     : 'Rejected — the task was cancelled.'));
    if (typeof _luToast === 'function') _luToast(toast);
    setTimeout(_cmdcFetchAndRender, 400);
  } catch(e) {
    if (row) { row.style.opacity = '1'; row.style.pointerEvents = ''; }
    if (typeof _luToast === 'function') _luToast('Action failed: ' + (e.message || 'unknown'));
  }
}

function _cmdcRenderAgents(list) {
  var el = document.getElementById('cmd-agents');
  var count = document.getElementById('cmd-agents-count');
  if (!el) return;
  if (count) count.textContent = list.length ? (list.length + ' active') : '';
  if (!list.length) {
    el.innerHTML = '<div class="cmd-empty">Your team activates after onboarding. <a href="javascript:void(0)" onclick="nav(\'agents\')" style="color:var(--p)">Complete setup →</a></div>';
    return;
  }
  el.innerHTML = list.map(function(a) {
    var orb = _cmdcOrbHtml({ name: a.name, slug: a.slug, color: a.color }, true);
    var titleLine = a.title || (a.is_dmm ? 'Digital Marketing Manager' : (a.category || 'Specialist'));
    var metaBits = [];
    metaBits.push('<span class="cmd-agent-dot"></span>' + (a.status === 'active' ? 'Active' : (a.status || 'idle')));
    // 2026-05-25 — prefer all-time stats from window._agentStatsData when
    // available (populated by loadAgentStats from /api/agents/dashboard).
    // Falls back to server-rendered tasks_this_week when stats aren't loaded yet.
    var uiId = (a.is_dmm || a.slug === 'sarah') ? 'dmm' : a.slug;
    var live = window._agentStatsData && window._agentStatsData[uiId];
    if (live) {
      var liveTotal = (live.ongoing || 0) + (live.upcoming || 0) + (live.completed || 0);
      // 2026-05-25 — blocked is its own pill, only shown when > 0 to keep
      // the meta line clean. Distinct from upcoming (which is now correct —
      // queued/pending only, not blocked).
      var bits = live.ongoing + ' ongoing · ' + live.upcoming + ' pending';
      if ((live.blocked || 0) > 0) bits += ' · ' + live.blocked + ' blocked';
      bits += ' · ' + live.completed + ' done';
      metaBits.push(bits);
    } else {
      metaBits.push(a.tasks_this_week + ' task' + (a.tasks_this_week === 1 ? '' : 's') + ' this week');
    }
    if (a.last_action_ago) metaBits.push('Last: ' + a.last_action_ago);
    var nav = _cmdcAgentNav(a);
    return '<div class="cmd-agent-card" onclick="' + nav + '">' + orb +
      '<div class="cmd-agent-body">' +
        '<div class="cmd-agent-name">' + _cmdcEsc(a.name) + (a.is_dmm ? ' <span style="color:var(--am);font-size:10px;font-weight:800">DMM</span>' : '') + '</div>' +
        '<div class="cmd-agent-title">' + _cmdcEsc(titleLine) + '</div>' +
        '<div class="cmd-agent-meta">' + metaBits.join(' · ') + '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

function _cmdcAgentNav(a) {
  // Wave 39 — open the agent drawer (profile + tasks + messages + board)
  // rather than navigating to their engine. Matches the Agents-tab UX where
  // clicking an agent card shows that agent's detail panel.
  // The dmm alias maps to sarah for the drawer ID.
  var drawerId = (a.is_dmm || a.slug === 'sarah') ? 'dmm' : (a.slug || 'sarah');
  return "nav('agents');setTimeout(function(){openAgentDrawer('" + drawerId + "')},80)";
}

function _cmdcRenderWebsites(list) {
  var el = document.getElementById('cmd-websites');
  if (!el) return;
  if (!list.length) {
    el.innerHTML = '<div class="cmd-empty">No websites yet. <a href="javascript:void(0)" onclick="nav(\'websites\')" style="color:var(--p)">Build your first site →</a></div>';
    return;
  }
  el.innerHTML = list.map(function(w) {
    var statusClass = w.status === 'published' ? 'cmd-status-published' : 'cmd-status-draft';
    var hostLine = w.host ? w.host : (w.status || 'draft');
    var viewBtn = w.status === 'published' && w.host
      ? '<button class="cmd-site-btn" onclick="window.open(\'https://' + w.host + '\',\'_blank\')">View live</button>'
      : '';
    return '<div class="cmd-site-row">' +
      '<span class="cmd-status-dot ' + statusClass + '"></span>' +
      '<div class="cmd-site-body">' +
        '<div class="cmd-site-name">' + _cmdcEsc(w.name || 'Untitled site') + '</div>' +
        '<div class="cmd-site-host">' + _cmdcEsc(hostLine) + (w.time_ago ? ' · ' + _cmdcEsc(w.time_ago) : '') + '</div>' +
      '</div>' +
      '<div class="cmd-site-actions">' +
        '<button class="cmd-site-btn" onclick="nav(\'websites\');if(typeof _wsOpen===\'function\')_wsOpen(' + w.id + ')">Edit</button>' +
        viewBtn +
      '</div>' +
    '</div>';
  }).join('');
}

function _cmdcRenderMeetings(list) {
  var el = document.getElementById('cmd-meetings');
  if (!el) return;
  if (!list.length) {
    el.innerHTML = '<div class="cmd-empty">No strategy meetings yet. <a href="javascript:void(0)" onclick="nav(\'meeting\')" style="color:var(--p)">Open Strategy Room →</a></div>';
    return;
  }
  var sarahOrb = _cmdcOrbHtml({ name: 'Sarah', slug: 'sarah', color: '#F59E0B' });
  el.innerHTML = list.map(function(m) {
    var statusChipCls = m.status === 'completed' ? 'cmd-chip-approved' : (m.status === 'in_progress' ? 'cmd-chip-pending' : 'cmd-chip-default');
    var mid = m.id || 0;
    return '<div class="cmd-site-row" style="cursor:pointer" onclick="nav(\'meeting\');if(typeof _meetingOpen===\'function\')setTimeout(function(){_meetingOpen(' + mid + ')},80)">' +
      sarahOrb +
      '<div class="cmd-site-body">' +
        '<div class="cmd-site-name">' + _cmdcEsc(m.title || 'Strategy meeting') + '</div>' +
        '<div class="cmd-site-host"><span class="cmd-chip ' + statusChipCls + '" style="margin-right:6px">' + _cmdcEsc(m.status || 'draft') + '</span>' + _cmdcEsc(m.time_ago || '') + (m.credits_used ? ' · ' + m.credits_used + ' credits' : '') + '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

// ── Polling + visibility ─────────────────────────────────────────────────────
function _cmdcStartPolling() {
  _cmdcStopPolling();
  // Full feed + stats every 30s
  _cmdcPollTimer = setInterval(function(){
    if (document.hidden) return;
    if (!_cmdcIsCommandView()) return;
    _cmdcFetchAndRender(true);
  }, 30000);
  // Lightweight approval count every 60s (for nav badge when viewing other engines)
  _cmdcBadgeTimer = setInterval(function(){
    if (document.hidden) return;
    _cmdcRefreshApprovalCount();
  }, 60000);
  // Kick off badge immediately so nav badge is right even on pages other than command
  _cmdcRefreshApprovalCount();
}
function _cmdcStopPolling() {
  if (_cmdcPollTimer)  { clearInterval(_cmdcPollTimer);  _cmdcPollTimer = null; }
  if (_cmdcBadgeTimer) { clearInterval(_cmdcBadgeTimer); _cmdcBadgeTimer = null; }
}
function _cmdcIsCommandView() {
  var v = document.getElementById('view-command');
  return v && v.classList.contains('active');
}
async function _cmdcRefreshApprovalCount() {
  try {
    var r = await _luFetch('GET', '/approvals/count');
    if (!r.ok) return;
    var d = await r.json();
    _cmdcSetNavBadge(d.pending || 0);
  } catch(_) {}
}
function _cmdcSetNavBadge(n) {
  var b = document.getElementById('cmd-nav-badge-approvals');
  if (!b) return;
  if (n > 0) { b.textContent = n > 99 ? '99+' : String(n); b.classList.add('show'); }
  else       { b.classList.remove('show'); }
  _cmdcLastApprovalN = n;
}

// Pause/resume on tab visibility
document.addEventListener('visibilitychange', function() {
  if (!document.hidden && _cmdcIsCommandView()) {
    _cmdcFetchAndRender(true);
  }
});

// Utility
function _cmdcOrbHtml(agent, big) {
  var a = agent || { name: '?', color: '#6C5CE7' };
  // AVATAR888 (DEC-0055): the portrait, not an initial. The feed carries agent_id/slug or just a name; all resolve.
  if (window.luAvatar && window.luAgent && (window.luAgent(a.slug || a.agent_id || a.id || '') || window.luAgent(a.name || ''))) {
    var _k = window.luAgent(a.slug || a.agent_id || a.id || '') ? (a.slug || a.agent_id || a.id) : a.name;
    return luAvatar(_k, big ? 44 : 28, 'idle', { cls: 'cmd-orb-face' });
  }
  var initials = (a.name || '?').substr(0, 1).toUpperCase();
  var color = a.color || '#6C5CE7';
  var cls = 'cmd-orb' + (big ? ' cmd-orb-lg' : '');
  return '<span class="' + cls + '" style="background:' + color + ';color:#fff" title="' + _cmdcEsc(a.name || '') + '">' + _cmdcEsc(initials) + '</span>';
}
function _cmdcEsc(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
}

// Kick off approval badge on page load (even before Command Center opened)
document.addEventListener('DOMContentLoaded', function() {
  setTimeout(function(){
    if (localStorage.getItem('lu_token')) _cmdcRefreshApprovalCount();
  }, 1200);
});


// ── Inject auth overlay + notification bell into WP admin SPA ────────────────
// Only injects on standalone (non-WP-admin) usage; no-op in WP admin.
document.addEventListener('DOMContentLoaded', function() {
  // Inject auth root overlay if not present (standalone SPA)
  if (!document.getElementById('lu-auth-root')) {
    var overlay = document.createElement('div');
    overlay.id = 'lu-auth-root';
    overlay.style.cssText = 'display:none;position:fixed;inset:0;z-index:9999;background:var(--bg,#0F1117);align-items:center;justify-content:center';
    document.body.appendChild(overlay);
  }

  // Inject insights panel into command center if missing
  var cmdView = document.getElementById('view-command');
  if (cmdView && !document.getElementById('cmd-insights-panel')) {
    var insPanel = document.createElement('div');
    insPanel.id = 'cmd-insights-panel';
    insPanel.style.cssText = 'margin-top:16px;max-width:1400px;width:100%;margin-left:auto;margin-right:auto';
    var header = cmdView.querySelector('.page-header, [style*="margin-bottom:24px"]');
    if (header && header.parentNode) {
      header.parentNode.insertBefore(insPanel, header.nextSibling);
    } else {
      var innerDiv = cmdView.querySelector('div');
      if (innerDiv) innerDiv.appendChild(insPanel);
    }
  }

  // Inject notification bell into top nav if not present
  var topActions = document.querySelector('.top-nav, .page-header-actions, #lu-top-bar');
  if (topActions && !document.getElementById('lu-notif-bell')) {
    var bell = document.createElement('button');
    bell.id = 'lu-notif-bell';
    bell.style.cssText = 'background:none;border:none;font-size:16px;cursor:pointer;color:var(--t2,#aaa);padding:6px 8px;border-radius:6px;position:relative';
    bell.innerHTML = ''+window.icon("info",14)+'';
    bell.onclick = _openNotifications;
    topActions.appendChild(bell);
  }

  // Run bootstrap — will short-circuit if not on standalone SPA
  // BOOT-VEIL (2026-09-11): the shell stays hidden until bootstrap has chosen login / signup / dashboard.
  Promise.resolve().then(function () { return _appBootstrap(); }).catch(function (e) { try { console.warn('[boot] bootstrap failed', e); } catch (_) {} })
    .then(function () { document.documentElement.classList.remove('lu-booting'); });
});

console.log('[LevelUp] v3.3.0 — auth, onboarding, notifications, analytics loaded');

// LGSC embed mode: force-route to the SEO engine after bootstrap completes.
// The SPA's real router is `window.nav(viewName)` (core.js:669 / window.nav
// alias at line 775). It already handles #view-seo resolution, lazy bundle
// load via luLoadEngine('seo'), and the seoLoad(_el) handoff. Use it.
if (window._LGSC_EMBED) {
  setTimeout(function() {
    try {
      // Method 1: use the SPA's own router
      if (typeof window.nav === 'function') {
        window.nav('seo');
        console.log('[LGSC] navigated via window.nav');
        return;
      }
      // Method 2: hash fallback (resilient against future router refactors)
      window.location.hash = '#seo';
      window.dispatchEvent(new HashChangeEvent('hashchange'));
      console.log('[LGSC] navigated via hash');
    } catch (e) {
      console.log('[LGSC] nav error: ' + e.message);
    }
  }, 500);
}

// ── BILLING v5.5.4 ─────────────────────────────────────────────────────────
window.loadBilling = async function loadBilling() {
  _billCheckReturnFlags();
  await _billFetchAndRender();
};

async function _billFetchAndRender() {
  var root = document.getElementById('bill-root');
  if (!root) return;
  try {
    var [statusR, plansR] = await Promise.all([
      _luFetch('GET', '/billing/status').then(r => r.json()),
      _luFetch('GET', '/billing/plans').then(r => r.json()),
    ]);
    _billRender(statusR || {}, (plansR && plansR.plans) || []);
    _billRenderWorkspaceUsage();
  } catch (e) {
    root.querySelector('#bill-current').innerHTML = '<div class="cmd-empty">Could not load billing status: ' + _cmdcEsc(e.message || '') + '</div>';
  }
}

// 2026-06-24 — Agency billing: one shared credit pool, usage broken down PER
// website-workspace, with an optional per-workspace allocation cap so you don't
// overspend on any one client. Backed by GET /billing/workspace-usage +
// POST /workspaces/{id}/allocation. Injected after the current-plan card.
async function _billRenderWorkspaceUsage() {
  var anchor = document.getElementById('bill-current');
  if (!anchor) return;
  var holder = document.getElementById('bill-ws-usage');
  if (!holder) {
    holder = document.createElement('div');
    holder.id = 'bill-ws-usage';
    anchor.parentNode.insertBefore(holder, anchor.nextSibling);
  }
  try {
    var d = await _luFetch('GET', '/billing/workspace-usage').then(r => r.json());
    var list = (d && d.workspaces) || [];
    // Single-workspace accounts don't need the breakdown.
    if (list.length < 2) { holder.innerHTML = ''; return; }
    var rows = list.map(function (w) {
      var allocVal = (w.allocation === null || w.allocation === undefined) ? '' : w.allocation;
      var rem = (w.allocation_remaining === null || w.allocation_remaining === undefined) ? '∞' : w.allocation_remaining;
      return '<tr>' +
        '<td style="padding:8px 10px;color:var(--t1);font-size:13px">' + _cmdcEsc(w.name || ('Workspace ' + w.workspace_id)) + '</td>' +
        '<td style="padding:8px 10px;color:var(--t2);font-size:13px;text-align:right">' + (w.used_this_cycle || 0) + '</td>' +
        '<td style="padding:8px 10px;text-align:right">' +
          '<input id="alloc-' + w.workspace_id + '" type="number" min="0" placeholder="∞" value="' + allocVal + '" ' +
          'style="width:90px;background:var(--s2);color:var(--t1);border:1px solid var(--bd);border-radius:6px;padding:5px 8px;font-size:12px;text-align:right">' +
        '</td>' +
        '<td style="padding:8px 10px;color:var(--t3);font-size:12px;text-align:right">' + rem + '</td>' +
        '<td style="padding:8px 10px;text-align:right">' +
          '<button class="aq-btn aq-btn-approve" style="padding:5px 10px;font-size:12px" onclick="_billSetAllocation(' + w.workspace_id + ')">Save</button>' +
        '</td></tr>';
    }).join('');
    holder.innerHTML =
      '<div style="margin-top:16px;padding:16px;border:1px solid var(--bd);border-radius:10px;background:var(--s1)">' +
        '<div style="font-family:var(--fh);font-weight:700;color:var(--t1);font-size:15px">Per-website usage</div>' +
        '<div style="font-size:12px;color:var(--t3);margin:4px 0 12px">' +
          'One shared credit pool across all your websites · ' + (d.pool_available || 0) + ' credits available · cycle from ' + _cmdcEsc(d.cycle_start || '') +
          '. Set an allocation to cap any one website\'s spend.' +
        '</div>' +
        '<table style="width:100%;border-collapse:collapse">' +
          '<thead><tr style="border-bottom:1px solid var(--bd)">' +
            '<th style="padding:6px 10px;text-align:left;font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.04em">Website</th>' +
            '<th style="padding:6px 10px;text-align:right;font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.04em">Used (cycle)</th>' +
            '<th style="padding:6px 10px;text-align:right;font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.04em">Allocation</th>' +
            '<th style="padding:6px 10px;text-align:right;font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.04em">Remaining</th>' +
            '<th style="padding:6px 10px"></th>' +
          '</tr></thead><tbody>' + rows + '</tbody>' +
        '</table>' +
      '</div>';
  } catch (e) {
    holder.innerHTML = '';
  }
}

window._billSetAllocation = function (wsId) {
  var inp = document.getElementById('alloc-' + wsId);
  if (!inp) return;
  var raw = String(inp.value).trim();
  var body = { credit_allocation: (raw === '' ? null : Math.max(0, parseInt(raw, 10) || 0)) };
  _luFetch('POST', '/workspaces/' + wsId + '/allocation', body)
    .then(r => r.json())
    .then(function () { _billRenderWorkspaceUsage(); })
    .catch(function () {});
};

function _billRender(status, plans) {
  var curEl = document.getElementById('bill-current');
  var plansEl = document.getElementById('bill-plans');
  var subEl = document.getElementById('bill-sub');

  // Subhead
  if (subEl) {
    if (status.stripe_configured === false) {
      subEl.innerHTML = '<span style="color:var(--am)">Stripe is not configured on this workspace yet.</span>';
    } else {
      subEl.textContent = status.has_subscription ? ('Current plan: ' + (status.plan || 'Free')) : 'No active paid plan — you are on the Free tier.';
    }
  }

  // Current plan card
  if (curEl) {
    var statusClass = status.status === 'trialing' ? 'cmd-chip-pending' : (status.status === 'active' ? 'cmd-chip-approved' : 'cmd-chip-default');
    var renewalLine = '';
    if (status.trial_ends_at) {
      renewalLine = '<span style="color:var(--am)">Trial ends ' + new Date(status.trial_ends_at).toLocaleDateString() + (status.is_platform_trial ? ' — upgrade to keep Sarah and your AI team' : '') + '</span>';
    } else if (status.status === 'past_due') {
      // MONEY-1: say what happened and what restores access — "Renews 9/29" was the only line shown.
      renewalLine = '<span style="color:var(--rd)">Your last payment failed — AI features are paused. Update your card under Manage billing to restore them.</span>';
    } else if (status.cancel_at_period_end) {
      renewalLine = '<span style="color:var(--rd)">Cancels on ' + (status.current_period_end ? new Date(status.current_period_end).toLocaleDateString() : '—') + '</span>';
    } else if (status.current_period_end) {
      renewalLine = 'Renews ' + new Date(status.current_period_end).toLocaleDateString();
    } else if (status.ends_at) {
      renewalLine = 'Ends ' + new Date(status.ends_at).toLocaleDateString();
    }
    var credits = Math.max(0, (status.credit_available || 0));
    var creditLimit = status.monthly_credit_limit || 0;
    var pct = creditLimit > 0 ? Math.min(100, Math.round(credits / creditLimit * 100)) : 0;

    curEl.innerHTML =
      '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">' +
        '<div style="flex:1;min-width:220px">' +
          '<div style="font-family:var(--fh);font-size:18px;font-weight:700;color:var(--t1);letter-spacing:-0.01em">' + _cmdcEsc(status.plan || 'Free') +
          ' <span class="cmd-chip ' + statusClass + '" style="margin-left:6px">' + _cmdcEsc(window.LU_statusLabel ? window.LU_statusLabel(status.status || 'active') : (status.status || 'active')) + '</span></div>' +
          '<div style="font-size:13px;color:var(--t2);margin-top:4px">' +
            (status.is_platform_trial
              ? 'Free 3-day trial · ' + creditLimit + ' trial credits · nothing to pay'
              : (status.plan_price ? '$' + status.plan_price + '/month · ' : '') + (creditLimit ? creditLimit + ' credits/month' : 'Free tier')) +
          '</div>' +
          (renewalLine ? '<div style="font-size:12px;color:var(--t3);margin-top:6px">' + renewalLine + '</div>' : '') +
        '</div>' +
        '<div>' +
          (status.stripe_customer_id
            ? '<button class="aq-btn aq-btn-approve" onclick="_billOpenPortal()">Manage billing →</button>'
            : '<span style="font-size:11px;color:var(--t3)">No Stripe customer yet</span>') +
        '</div>' +
      '</div>' +
      (creditLimit > 0 ? (
        '<div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--bd)">' +
          '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px">' +
            '<span style="font-size:12px;color:var(--t2)">' + (status.is_platform_trial ? 'Trial credits left' : 'Credits this month') + '</span>' +
            '<span style="font-size:12px;font-weight:700;color:var(--t1)">' + credits + ' / ' + creditLimit + '</span>' +
          '</div>' +
          '<div style="height:6px;background:var(--s2);border-radius:3px;overflow:hidden"><div style="height:100%;width:' + pct + '%;background:' + (pct < 20 ? 'var(--rd)' : pct < 50 ? 'var(--am)' : 'var(--ac)') + '"></div></div>' +
        '</div>'
      ) : '');
  }

  // Upgrade plan cards — show every plan except current
  if (plansEl) {
    var currentSlug = status.plan_slug || 'free';
    var paid = (plans || []).filter(function(p) { return p.slug !== currentSlug; });
    if (!paid.length) {
      plansEl.innerHTML = '<div class="cmd-empty">You are on the highest plan.</div>';
    } else {
      plansEl.innerHTML = paid.map(function(p) {
        var features = _billPlanFeatures(p);
        var isFree = p.slug === 'free';
        var canStripe = !!p.stripe_price_id && !isFree;
        var btn = isFree
          ? '<button class="aq-btn aq-btn-reject" onclick="_billDowngradeToFree()">Downgrade to Free</button>'
          : (canStripe
            ? '<button class="aq-btn aq-btn-approve" onclick="_billCheckout(' + p.id + ',\'' + _cmdcEsc(p.slug) + '\')">Upgrade to ' + _cmdcEsc(p.name) + ' →</button>'
            : '<button class="aq-btn aq-btn-approve" disabled style="opacity:.5;cursor:not-allowed">Not yet configured</button>');
        return '<div class="cmd-panel" style="padding:16px 18px">' +
          '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px">' +
            '<div style="font-family:var(--fh);font-size:16px;font-weight:700;color:var(--t1)">' + _cmdcEsc(p.name) + '</div>' +
            '<div style="font-size:16px;font-weight:800;color:var(--t1)">$' + p.price + '<span style="font-size:11px;color:var(--t3);font-weight:400">/mo</span></div>' +
          '</div>' +
          '<ul style="list-style:none;padding:0;margin:10px 0;font-size:12.5px;color:var(--t2)">' + features + '</ul>' +
          '<div style="margin-top:10px">' + btn + '</div>' +
        '</div>';
      }).join('');
    }
  }
}

function _billPlanFeatures(p) {
  // MONEY-1 (2026-08-29): the cards said "1 website" and nothing else for Free AND Starter — the
  // customer could not see what $19 buys, nor that $49 is the full AI Growth OS.
  var items = [];
  var f = p.features_json || {};
  if (+p.max_websites)    items.push((+p.max_websites) + ' website' + (+p.max_websites === 1 ? '' : 's'));
  if (f.custom_domain)    items.push('Custom domain');
  else                    items.push('LevelUp subdomain');
  if (p.ai_access === 'full') {
    items.push('Sarah + specialist AI agents');
    items.push('All AI tools (SEO, content, images, video, social, chatbot)'); // zz: video + social are AI tools from $49 up (ADR-0012)
  } else if (p.ai_access === 'research') {
    items.push('AI research tools');
  } else {
    items.push('No ongoing AI (build with Arthur only)');
  }
  if (+p.credit_limit > 0) items.push((+p.credit_limit) + ' AI credits/month');
  if (+p.agent_count)     items.push((+p.agent_count) + ' specialist agent' + (+p.agent_count === 1 ? '' : 's'));
  items.push(p.companion_app ? 'Mobile companion app' : 'No companion app');
  if (p.white_label)      items.push('White-label');
  if (p.priority_processing) items.push('Priority processing');
  if (!items.length)      items.push('Free tier');
  return items.map(function(t) { return '<li style="padding:3px 0;position:relative;padding-left:16px"><span style="position:absolute;left:0;color:var(--ac)">✓</span>' + _cmdcEsc(t) + '</li>'; }).join('');
}

async function _billCheckout(planId, planSlug) {
  try {
    showToast('Opening checkout…', 'info');
    var r = await _luFetch('POST', '/billing/checkout', { plan_id: planId });
    var d = await r.json();
    if (d.checkout_url) {
      window.location.href = d.checkout_url;
      return;
    }
    if (d.success && d.dev_mode) {
      showToast('Dev mode — plan activated without Stripe.', 'success');
      _billFetchAndRender();
      return;
    }
    showToast('Checkout failed: ' + (d.error || 'Unknown error'), 'error');
  } catch (e) {
    showToast('Checkout error: ' + (e.message || 'Unknown'), 'error');
  }
}

async function _billOpenPortal() {
  try {
    var r = await _luFetch('GET', '/billing/portal');
    var d = await r.json();
    if (d.portal_url) { window.open(d.portal_url, '_blank'); return; }
    showToast('Portal failed: ' + (d.error || 'Unknown error'), 'error');
  } catch (e) {
    showToast('Portal error: ' + (e.message || 'Unknown'), 'error');
  }
}

async function _billDowngradeToFree() {
  // MONEY-1 (2026-08-29): the old copy promised "at the end of the current period" and toasted
  // "Cancellation scheduled" — but POST /billing/cancel cancels and downgrades to Free NOW (the
  // Stripe subscription is cancelled immediately; AI access and paid credits stop). Say so, and
  // use the app dialog (a native confirm dialog deadlocks automation and breaks the shell's look).
  var ok = await _billConfirm('Cancel your plan and move to Free now?',
    'Your paid plan ends immediately: AI agents, tools and paid credits stop right away and your website(s) stay on the Free tier limits. This cannot be undone from here — you would need to subscribe again.',
    'Cancel plan now', 'Keep my plan');
  if (!ok) return;
  try {
    var r = await _luFetch('POST', '/billing/cancel');
    var d = await r.json();
    if (d.success) { showToast('Your plan is cancelled — you are now on Free.', 'success'); _billFetchAndRender(); }
    else showToast('Cancel failed: ' + (d.error || 'Unknown'), 'error');
  } catch (e) {
    showToast('Cancel error: ' + (e.message || 'Unknown'), 'error');
  }
}

function _billConfirm(title, message, okLabel, cancelLabel) {
  // P0-A (2026-08-30): one dialog layer (core.js luDialog) — no native fallback.
  return luConfirm(title, message, { okLabel: okLabel, cancelLabel: cancelLabel, danger: true });
}

async function _billConfirmUpgradeLanded() {
  showToast('Payment received — confirming your plan with Stripe…', 'info');
  var landed = null;
  for (var i = 0; i < 8; i++) {                       // ~16 s: Stripe webhooks usually land in < 5 s
    try {
      var r = await _luFetch('GET', '/billing/status');
      var st = await r.json();
      if (st && st.stripe_connected && st.plan_slug && st.plan_slug !== 'free') { landed = st; break; }
    } catch (e) {}
    await new Promise(function (res) { setTimeout(res, 2000); });
  }
  if (landed) {
    showToast('Your ' + landed.plan + ' plan is active' + (landed.trial_ends_at ? ' (trial until ' + new Date(landed.trial_ends_at).toLocaleDateString() + ')' : '') + '.', 'success');
    if (typeof _billFetchAndRender === 'function') _billFetchAndRender();
    if (typeof loadWorkspaceStatus === 'function') { try { loadWorkspaceStatus(); } catch (e) {} }
  } else {
    showToast('Stripe accepted the payment but we have not received its confirmation yet. Your plan will update within a few minutes — refresh this page to check.', 'warning');
  }
}

function _billCheckReturnFlags() {
  // Handle return from Stripe Checkout via hash query params.
  // URL shape: /app/#billing?success=1&session=cs_test_abc
  // MONEY-1: Stripe now returns to /app/billing?checkout=success|cancelled (path form); the legacy
  // hash form is still honoured for any session created before the change.
  var params = new URLSearchParams(window.location.search || '');
  var hash = window.location.hash || '';
  var qIx = hash.indexOf('?');
  if (!params.get('checkout') && qIx >= 0) params = new URLSearchParams(hash.substring(qIx + 1));
  if (!params.get('checkout') && !params.get('success') && !params.get('cancelled')) return;
  if (params.get('checkout') === 'success' || params.get('success') === '1') {
    // MONEY-1 (2026-08-29): the URL only proves Stripe redirected us back. The plan changes when
    // the checkout.session.completed webhook lands — poll /billing/status and tell the truth.
    _billConfirmUpgradeLanded();
  } else if (params.get('checkout') === 'cancelled' || params.get('cancelled') === '1') {
    showToast('Checkout cancelled — nothing was charged.', 'info');
  }
  // Clean the URL so a refresh doesn't re-toast
  history.replaceState(null, '', '/app/billing');
}



// ═══════════════════════════════════════════════════════════════════════════
// Settings: API Keys + WordPress Sites renderers (2026-05-11)
// These are designed to be called against any container element (e.g.
// document.getElementById('view-settings-apikeys')). The HTML wiring of
// new tabs in the settings view is a separate UI task — these globals
// stand on their own and can be invoked programmatically.
// ═══════════════════════════════════════════════════════════════════════════
// BIZ-1 (2026-08-31): the businesses this account belongs to, and the one it is currently working in.
// Switching changes the workspace for the WHOLE app, so it is stated plainly and never happens on its own.
window._renderBusinesses = async function _renderBusinesses(el) {
  if (!el) return;
  try {
    var r = await _luFetch('GET', '/workspaces');
    var d = await r.json();
    var list = (d && (d.workspaces || d.data)) || [];
    if (!Array.isArray(list) || list.length < 2) { el.style.display = 'none'; return; }   // one business needs no switcher
    var cur = 0;
    try {
      var rs = await _luFetch('GET', '/workspace/status');
      var ds = await rs.json();
      cur = parseInt((ds && ds.workspace && ds.workspace.id) || 0, 10) || 0;
    } catch (_) {}
    var rows = list.map(function (w) {
      var id = parseInt(w.id || w.workspace_id, 10) || 0;
      var name = w.business_name || w.name || ('Business ' + id);
      var here = (id === cur);
      return '<div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-top:1px solid var(--bd)">'
        + '<div style="flex:1;min-width:0">'
        +   '<div style="font-size:13px;font-weight:600;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + _cmdcEsc(name) + '</div>'
        +   (w.plan ? '<div style="font-size:11px;color:var(--t3);margin-top:2px">' + _cmdcEsc(String(w.plan)) + ' plan</div>' : '')
        + '</div>'
        + (here
            ? '<span style="font-size:11px;font-weight:700;color:var(--ac);white-space:nowrap">You are here</span>'
            : '<button type="button" class="btn btn-outline" style="font-size:12px;padding:6px 14px;white-space:nowrap" onclick="_switchBusiness(' + id + ',this)">Switch</button>')
        + '</div>';
    }).join('');
    el.style.display = '';
    el.innerHTML = '<div style="padding:24px">'
      + '<h3 style="font:700 18px Manrope,sans-serif;color:var(--t1);margin:0 0 6px">Your businesses</h3>'
      + '<p style="font:400 13px Inter,sans-serif;color:var(--t3);margin:0 0 4px">'
      +   'Switching changes the business you are working in everywhere — Sarah, your websites, SEO and all your data.'
      + '</p>' + rows + '</div>';
  } catch (e) { el.style.display = 'none'; }
};

window._switchBusiness = async function _switchBusiness(wsId, btn) {
  wsId = parseInt(wsId, 10); if (!wsId) return;
  if (btn) { btn.disabled = true; btn.textContent = 'Switching…'; }
  try {
    var r = await fetch(window.location.origin + '/api/auth/switch-workspace', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ workspace_id: wsId }), cache: 'no-store',
    });
    var d = await r.json();
    if (d && d.access_token) {
      localStorage.setItem('lu_token', d.access_token);
      if (d.refresh_token) localStorage.setItem('lu_refresh_token', d.refresh_token);
      try { localStorage.setItem('lu_workspace_id', String(d.current_workspace_id || wsId)); } catch (_) {}
      location.reload();
      return;
    }
    throw new Error('no token');
  } catch (e) {
    if (typeof showToast === 'function') showToast("Couldn't switch business — try again.", 'error');
    if (btn) { btn.disabled = false; btn.textContent = 'Switch'; }
  }
};

window._renderApiKeys = async function _renderApiKeys(el) {
  if (!el) return;
  el.innerHTML = '<div style="padding:24px"><div id="apk-wrap"><div class="lu-loading">Loading…</div></div></div>';
  var wrap = el.querySelector('#apk-wrap');
  try {
    var r = await _luFetch('GET', '/settings/api-keys');
    var d = await r.json();
    if (!d.success) { wrap.innerHTML = '<div style="color:#EF4444">Failed to load keys.</div>'; return; }
    var keys = d.keys || [];
    var html =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">' +
        '<h3 style="font:700 18px Manrope,sans-serif;color:var(--t1);margin:0">API Keys</h3>' +
        '<button onclick="_generateApiKey()" class="btn btn-primary" style="font-size:13px;padding:8px 16px">+ Generate Key</button>' +
      '</div>' +
      '<p style="font:400 13px Inter,sans-serif;color:var(--t3);margin-bottom:20px">' +
        'Use these keys to connect your WordPress site with the LevelUpGrowth SEO Connector plugin.' +
      '</p>';
    if (keys.length === 0) {
      html += '<div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:32px;text-align:center;color:#6B7280">No API keys yet. Generate one to connect your WordPress site.</div>';
    } else {
      html += '<div style="border:1px solid rgba(255,255,255,0.08);border-radius:12px;overflow:hidden">';
      keys.forEach(function (k) {
        html +=
          '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--s2)">' +
            '<div>' +
              '<div style="font:600 14px Inter,sans-serif;color:var(--t1)">' + (k.name || 'API Key') + '</div>' +
              '<div style="font:400 12px Inter,sans-serif;color:#6B7280;margin-top:2px">' +
                '<code style="background:var(--s2);padding:2px 6px;border-radius:4px;font-size:11px">' + k.key_preview + '</code>' +
                ' · Created ' + (k.created_at ? String(k.created_at).split(' ')[0] : '—') +
                (k.last_used_at ? ' · Last used ' + String(k.last_used_at).split(' ')[0] : ' · Never used') +
              '</div>' +
            '</div>' +
            '<button onclick="_revokeApiKey(' + k.id + ')" style="background:rgba(239,68,68,0.1);color:#EF4444;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:6px 12px;font:500 12px Inter,sans-serif;cursor:pointer">Revoke</button>' +
          '</div>';
      });
      html += '</div>';
    }
    wrap.innerHTML = html;
  } catch (e) { wrap.innerHTML = '<div style="color:#EF4444">Error: ' + (e.message || e) + '</div>'; }
};

window._generateApiKey = async function _generateApiKey() {
  var name = await luPrompt('Name this key', 'WP Connector', 'e.g. "shukranuae.com connector"');
  if (!name) return;
  try {
    var r = await _luFetch('POST', '/settings/api-keys', { name: name, type: 'connector' });
    var d = await r.json();
    if (!d.success || !d.key) {
      if (typeof showToast === 'function') showToast('Failed to generate key', 'error');
      return;
    }
    // Modal — display key ONCE
    var modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px';
    modal.innerHTML =
      '<div style="background:#121826;border:1px solid var(--bd);border-radius:16px;padding:32px;max-width:520px;width:100%">' +
        '<h3 style="font:700 18px Manrope,sans-serif;color:var(--t1);margin:0 0 8px">API Key Generated</h3>' +
        '<p style="font:400 13px Inter,sans-serif;color:#F59E0B;margin:0 0 16px">⚠ Copy this key now — it will not be shown again.</p>' +
        '<div id="apk-new-value" style="background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:12px 16px;font:500 13px/1.5 monospace;color:var(--t1);word-break:break-all;margin-bottom:16px">' + d.key + '</div>' +
        '<div style="display:flex;gap:8px">' +
          '<button id="apk-copy" class="btn btn-primary" style="flex:1">Copy Key</button>' +
          '<button id="apk-done" style="background:var(--s2);color:var(--t1);border:1px solid var(--bd);border-radius:8px;padding:10px 16px;cursor:pointer;flex:1">Done</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);
    modal.querySelector('#apk-copy').addEventListener('click', function () {
      navigator.clipboard.writeText(d.key);
      if (typeof showToast === 'function') showToast('Copied', 'info');
    });
    modal.querySelector('#apk-done').addEventListener('click', function () {
      modal.remove();
      // Re-render the current API Keys container if visible
      var wrap = document.querySelector('#apk-wrap');
      if (wrap) window._renderApiKeys(wrap.parentElement);
    });
  } catch (e) {
    if (typeof showToast === 'function') showToast('Error: ' + (e.message || e), 'error');
  }
};

window._revokeApiKey = async function _revokeApiKey(id) {
  if (!await luConfirm('Revoke this key?', 'Any connected sites using it will stop working.', { okLabel: 'Revoke', danger: true })) return;
  try {
    var r = await _luFetch('DELETE', '/settings/api-keys/' + id);
    var d = await r.json();
    if (d.success) {
      if (typeof showToast === 'function') showToast('Key revoked', 'info');
      var wrap = document.querySelector('#apk-wrap');
      if (wrap) window._renderApiKeys(wrap.parentElement);
    }
  } catch (e) {
    if (typeof showToast === 'function') showToast('Error: ' + (e.message || e), 'error');
  }
};

window._renderWpSites = async function _renderWpSites(el) {
  if (!el) return;
  el.innerHTML = '<div style="padding:24px"><div id="wps-wrap"><div class="lu-loading">Loading…</div></div></div>';
  var wrap = el.querySelector('#wps-wrap');
  try {
    var r = await _luFetch('GET', '/settings/wp-sites');
    var d = await r.json();
    if (!d.success) { wrap.innerHTML = '<div style="color:#EF4444">Failed to load sites.</div>'; return; }
    var sites = d.sites || [];
    var html =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">' +
        '<h3 style="font:700 18px Manrope,sans-serif;color:var(--t1);margin:0">WordPress Sites</h3>' +
      '</div>' +
      '<p style="font:400 13px Inter,sans-serif;color:var(--t3);margin-bottom:20px">' +
        'Connect your WordPress site by installing the LevelUp SEO Connector plugin and entering your API key + webhook secret.' +
      '</p>';
    if (sites.length === 0) {
      html +=
        '<div style="background:rgba(255,255,255,0.03);border:1px dashed rgba(255,255,255,0.12);border-radius:12px;padding:32px;text-align:center">' +
          '<div style="font-size:32px;margin-bottom:12px">🔗</div>' +
          '<div style="font:600 15px Manrope,sans-serif;color:var(--t1);margin-bottom:8px">No WordPress site connected</div>' +
          '<div style="font:400 13px Inter,sans-serif;color:#6B7280;max-width:340px;margin:0 auto;line-height:1.6">' +
            '1. Install the LevelUp SEO Connector plugin on your WP site<br>' +
            '2. Generate an API key above<br>' +
            '3. Paste the key + your webhook secret into the plugin settings' +
          '</div>' +
        '</div>';
    } else {
      sites.forEach(function (s) {
        html +=
          '<div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px;margin-bottom:12px">' +
            '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">' +
              '<div>' +
                '<div style="font:600 15px Manrope,sans-serif;color:var(--t1)">' + (s.name || s.url) + '</div>' +
                '<div style="font:400 12px Inter,sans-serif;color:#6B7280;margin-top:4px">' + s.url + '</div>' +
                '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">' +
                  '<span style="background:rgba(0,229,168,0.1);color:#00E5A8;border:1px solid rgba(0,229,168,0.2);border-radius:6px;padding:3px 8px;font:500 11px Inter,sans-serif">✓ Connected</span>' +
                  '<span style="background:var(--s2);color:var(--t3);border-radius:6px;padding:3px 8px;font:500 11px Inter,sans-serif">' + s.pages_indexed + ' pages indexed</span>' +
                '</div>' +
              '</div>' +
              '<button onclick="_disconnectWpSite()" style="background:rgba(239,68,68,0.1);color:#EF4444;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:6px 12px;font:500 12px Inter,sans-serif;cursor:pointer;flex-shrink:0">Disconnect</button>' +
            '</div>' +
            '<div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--s2)">' +
              '<div style="font:500 12px Inter,sans-serif;color:#6B7280;margin-bottom:6px">Webhook Secret</div>' +
              '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">' +
                '<code style="background:var(--s2);padding:6px 10px;border-radius:6px;font-size:11px;color:var(--t3);flex:1;min-width:200px;word-break:break-all">' + (s.webhook_secret || '—') + '</code>' +
                '<button onclick="navigator.clipboard.writeText(\'' + (s.webhook_secret || '') + '\');if(typeof showToast===\'function\')showToast(\'Copied\',\'info\')" style="background:var(--s2);color:var(--t1);border:1px solid var(--bd);border-radius:6px;padding:6px 10px;font:500 11px Inter,sans-serif;cursor:pointer">Copy</button>' +
                '<button onclick="_rotateWebhookSecret()" style="background:var(--s2);color:var(--t3);border:1px solid var(--bd);border-radius:6px;padding:6px 10px;font:500 11px Inter,sans-serif;cursor:pointer">Rotate</button>' +
              '</div>' +
            '</div>' +
          '</div>';
      });
    }
    wrap.innerHTML = html;
  } catch (e) { wrap.innerHTML = '<div style="color:#EF4444">Error: ' + (e.message || e) + '</div>'; }
};

window._disconnectWpSite = async function _disconnectWpSite() {
  if (!await luConfirm('Disconnect this WordPress site?', 'SEO data will be kept but the site will stop syncing.', { okLabel: 'Disconnect', danger: true })) return;
  try {
    var r = await _luFetch('DELETE', '/settings/wp-sites');
    var d = await r.json();
    if (d.success) {
      if (typeof showToast === 'function') showToast('Site disconnected', 'info');
      var wrap = document.querySelector('#wps-wrap');
      if (wrap) window._renderWpSites(wrap.parentElement);
    }
  } catch (e) {
    if (typeof showToast === 'function') showToast('Error: ' + (e.message || e), 'error');
  }
};

window._rotateWebhookSecret = async function _rotateWebhookSecret() {
  if (!await luConfirm('Rotate webhook secret?', 'You will need to update the secret in your WP plugin settings.', { okLabel: 'Rotate secret', danger: true })) return;
  try {
    var r = await _luFetch('POST', '/settings/wp-sites/rotate-secret');
    var d = await r.json();
    if (d.success) {
      if (typeof showToast === 'function') showToast('Secret rotated — update your WP plugin settings', 'info');
      var wrap = document.querySelector('#wps-wrap');
      if (wrap) window._renderWpSites(wrap.parentElement);
    }
  } catch (e) {
    if (typeof showToast === 'function') showToast('Error: ' + (e.message || e), 'error');
  }
};


// Wave 26 — Restore toggleActivityPanel that was lost when builder.js was
// retired 2026-04-17. Two onclick handlers in index.html (lines 2830 + 2858)
// still reference it. Minimal stub: open/close the slide-up panel + fetch
// recent activity from /api/activity/feed.
(function () {
  var _open = false;
  var _poller = null;

  window.toggleActivityPanel = function () {
    var panel = document.getElementById('ws-activity-panel');
    if (!panel) return;
    _open = !_open;
    panel.style.transform = _open ? 'translateY(0)' : 'translateY(100%)';
    var btn = document.getElementById('ct-activity');
    if (btn) btn.style.background = _open ? 'rgba(124,58,237,0.18)' : '';
    if (_open) {
      _loadActivityFeed();
      if (!_poller) _poller = setInterval(_loadActivityFeed, 5000);
    } else if (_poller) {
      clearInterval(_poller);
      _poller = null;
    }
  };

  function _loadActivityFeed() {
    var feed = document.getElementById('ws-activity-feed');
    if (!feed) return;
    var t = localStorage.getItem('lu_token') || '';
    fetch('/api/activity/feed?limit=30', {
      headers: {
        'Authorization': 'Bearer ' + t,
        'Accept': 'application/json',
      },
      cache: 'no-store',
    }).then(function (r) { return r.ok ? r.json() : { feed: [] }; }).then(function (d) {
      var events = (d && d.feed) || [];
      var dot = document.getElementById('ws-activity-dot');
      if (dot) dot.style.background = events.length ? '#10B981' : 'var(--t3)';
      if (!events.length) {
        feed.innerHTML = '<div style="font-size:11px;color:var(--t3);text-align:center;padding:16px">No activity yet.</div>';
        return;
      }
      feed.innerHTML = events.map(function (ev) {
        var when = ev.ts ? new Date(ev.ts * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : '';
        var title = (ev.data && ev.data.title) || ev.event || 'event';
        var engine = (ev.data && ev.data.engine) || '';
        var safe = function (s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
        return '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 8px;background:var(--s2);border-radius:6px;font-size:11.5px;color:var(--t1)">'
             +   '<div><span style="color:var(--t3);margin-right:8px;font-size:10px">[' + safe(engine) + ']</span>' + safe(title) + '</div>'
             +   '<div style="color:var(--t3);font-size:10px">' + when + '</div>'
             + '</div>';
      }).join('');
    }).catch(function () {
      feed.innerHTML = '<div style="font-size:11px;color:var(--t3);text-align:center;padding:16px">Activity feed unavailable.</div>';
    });
  }
})();

/* Wave 31 — Chat counter widget (global). Auto-injects a 💬 badge near
   any known chat input. window._lgseUpdateChatMeter(counter, debited) is
   called by each chat sender from its response handler. Effective price
   0.1 cr per chat (10 chats = 1 credit), batched in CreditService::meterChat
   via workspaces.chat_meter. */
(function () {
  if (window._lgseChatMeterAutoInject) return;
  window._lgseChatMeterAutoInject = true;

  window._lgseUpdateChatMeter = function (counter, debited) {
    var c = (counter === null || counter === undefined) ? null : parseInt(counter, 10);
    var els = document.querySelectorAll('.lgse-chat-meter');
    Array.prototype.forEach.call(els, function (el) {
      if (debited) {
        el.innerHTML = '<span style="color:#10B981;font-weight:600">✓ 1 credit charged — next 10 chats free</span>';
        setTimeout(function () { window._lgseUpdateChatMeter(0, false); }, 4000);
        return;
      }
      if (c === null) {
        el.innerHTML = '<span style="font-weight:500">💬 10 chats = 1 credit · 0.1 cr each</span>';
        return;
      }
      el.innerHTML = '<span style="font-weight:500">💬 ' + c + ' / 10 chats toward next credit</span>';
    });
  };

  function inject() {
    var inputs = [
      // Laravel app shell — ARIA (canonical AI Assistant)
      document.getElementById('ai-input'),
      // Laravel app shell agent drawer
      document.getElementById('agent-msg-input'),
      // SEO engine slide-in AI Assistant drawer (also used by WP plugin embed)
      document.getElementById('lgse-drawer-input'),
      // Floating messages modal (messages-ui.js)
      document.querySelector('#lu-msg-input'),
      document.querySelector('[data-chat-input]'),
    ].filter(Boolean);

    inputs.forEach(function (inp) {
      if (!inp || inp.dataset.lgseMeterAttached) return;
      var row = inp.parentElement;
      var wrapper = row ? row.parentElement : null;
      if (!row) return;
      var meter = document.createElement('div');
      meter.className = 'lgse-chat-meter';
      meter.style.cssText = 'font-size:11px;color:#A78BFA;text-align:right;padding:8px 12px;margin-top:8px;border-radius:8px;background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.2)';
      meter.innerHTML = '<span style="font-weight:500">💬 10 chats = 1 credit · 0.1 cr each</span>';
      if (wrapper) wrapper.appendChild(meter);
      else if (row.insertAdjacentElement) row.insertAdjacentElement('afterend', meter);
      else row.appendChild(meter);
      inp.dataset.lgseMeterAttached = '1';
      try { console.log('[LU CHAT METER] badge attached to #' + inp.id + ' (wrapper=' + (wrapper ? wrapper.tagName : 'none') + ')'); } catch (_e) {}
    });
  }

  if (document.readyState !== 'loading') inject();
  document.addEventListener('DOMContentLoaded', inject);
  setInterval(inject, 1000);
  window._lgseChatMeterReinject = inject;
})();
