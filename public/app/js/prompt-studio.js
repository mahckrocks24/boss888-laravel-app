/*
 * prompt-studio.js — Prompt Studio surface (STUDIO888 redesign, 2026-09-03)
 *
 * A mobile-first, prompt-first creative surface: type a prompt -> (free) see how
 * the engine enhanced it -> generate -> refine by prompting. Reuses the CERTIFIED
 * backend only:
 *   - POST /api/creative/plan-preview     (FREE enhancement preview — f5ee9bb)
 *   - POST /api/creative/generate/image   (governed generate_image — 2cr)
 *   - POST /api/creative/edit             (governed edit_image — 2cr; PS-5)
 *
 * Self-contained IIFE. Exposes window._mountPromptStudio(rootEl, caps). Gated by
 * studioLoad on capabilities.features.prompt_studio (OFF by default) — this file
 * is inert unless that flag is ON, so shipping it changes nothing for users yet.
 */
(function () {
  'use strict';

  function tok() { return localStorage.getItem('lu_token') || ''; }
  function apiBase() { return (window.LU_CFG && window.LU_API_BASE) ? window.LU_API_BASE : '/api'; }
  function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

  function jfetch(url, opts) {
    opts = opts || {};
    opts.headers = opts.headers || {};
    opts.headers['Authorization'] = 'Bearer ' + tok();
    if (opts.body && !(opts.body instanceof FormData)) opts.headers['Content-Type'] = 'application/json';
    return fetch(apiBase() + url, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error('HTTP ' + r.status); e.status = r.status; e.payload = j; throw e; }
        return j;
      });
    });
  }

  // Display-only credit costs (authoritative gate is server-side CapabilityMapService).
  var COST = { generate_image: 2, generate_image_mini: 1, generate_image_high: 4, edit_image: 2 };

  // Aspect chips -> the aspect_ratio the certified F-STUDIO-B-ASPECT snap understands.
  var ASPECTS = [
    { id: 'square',    label: 'Square',    ar: '1:1',  hint: 'Feed / post' },
    { id: 'story',     label: 'Story',     ar: '9:16', hint: 'Reel / story' },
    { id: 'landscape', label: 'Landscape', ar: '16:9', hint: 'Hero / cover' }
  ];

  var S = {}; // per-mount state

  function injectCss() {
    if (document.getElementById('ps-css')) return;
    var css = [
      '.ps-root{position:absolute;inset:0;height:100%;min-height:100%;display:flex;flex-direction:column;background:#0E0F14;color:#fff;font:400 15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}',
      '.ps-head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0}',
      '.ps-head h2{font:600 17px/1 inherit;margin:0}',
      '.ps-head .ps-sub{color:rgba(255,255,255,.5);font-size:12px}',
      '.ps-scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:16px;display:flex;flex-direction:column;gap:16px}',
      '.ps-stage{width:100%;max-width:640px;margin:0 auto;display:flex;flex-direction:column;gap:16px}',
      // result
      '.ps-result{width:100%;aspect-ratio:1/1;flex-shrink:0;min-height:220px;border-radius:16px;overflow:hidden;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;position:relative}',
      '.ps-result.wide{aspect-ratio:16/9}.ps-result.tall{aspect-ratio:9/16;max-height:70vh}',
      '.ps-result img{width:100%;height:100%;object-fit:contain;display:block}',
      '.ps-empty{color:rgba(255,255,255,.4);text-align:center;padding:24px;font-size:14px}',
      '.ps-empty svg{opacity:.5;margin-bottom:10px}',
      '.ps-busy{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;background:rgba(14,15,20,.75);backdrop-filter:blur(6px)}',
      '.ps-spin{width:34px;height:34px;border-radius:50%;border:3px solid rgba(255,255,255,.15);border-top-color:#6C5CE7;animation:psSpin .9s linear infinite}',
      '@keyframes psSpin{to{transform:rotate(360deg)}}',
      // enhanced panel
      '.ps-enh{border:1px solid rgba(108,92,231,.35);background:rgba(108,92,231,.08);border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:10px}',
      '.ps-enh-title{font:600 13px/1 inherit;color:#B0A4FF;display:flex;align-items:center;gap:6px}',
      '.ps-enh-row{font-size:13px;color:rgba(255,255,255,.82)}',
      '.ps-enh-row b{color:rgba(255,255,255,.55);font-weight:500;margin-right:6px}',
      '.ps-swatches{display:flex;gap:6px;flex-wrap:wrap}',
      '.ps-swatch{width:22px;height:22px;border-radius:6px;border:1px solid rgba(255,255,255,.2)}',
      '.ps-tags{display:flex;gap:6px;flex-wrap:wrap}',
      '.ps-tag{font-size:11px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:3px 8px;color:rgba(255,255,255,.75)}',
      // version chips
      '.ps-versions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}',
      '.ps-versions-lbl{font-size:12px;color:rgba(255,255,255,.5);margin-right:2px}',
      '.ps-versions-hint{font-size:11px;color:rgba(255,255,255,.35)}',
      '.ps-vchip{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);color:rgba(255,255,255,.8);font:600 12px/1 inherit;padding:7px 11px;border-radius:10px;cursor:pointer;min-width:38px}',
      '.ps-vchip[aria-pressed="true"]{background:rgba(108,92,231,.25);border-color:#6C5CE7;color:#fff}',
      // composer
      '.ps-composer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 16px;background:#0E0F14;display:flex;flex-direction:column;gap:10px}',
      '.ps-composer-inner{width:100%;max-width:640px;margin:0 auto;display:flex;flex-direction:column;gap:10px}',
      '.ps-chips{display:flex;gap:8px;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:2px}',
      '.ps-chip{flex-shrink:0;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);color:rgba(255,255,255,.8);font:500 13px/1 inherit;padding:9px 14px;border-radius:20px;cursor:pointer;transition:.12s}',
      '.ps-chip[aria-pressed="true"]{background:rgba(108,92,231,.25);border-color:#6C5CE7;color:#fff}',
      '.ps-box{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.14);border-radius:16px;padding:10px 12px;display:flex;align-items:flex-end;gap:10px;transition:border-color .15s}',
      '.ps-box.focused{border-color:rgba(108,92,231,.6)}',
      '.ps-input{flex:1;background:transparent;color:#fff;border:0;outline:0;resize:none;font:400 15px/1.45 inherit;min-height:24px;max-height:120px;padding:2px 0}',
      '.ps-input::placeholder{color:rgba(255,255,255,.4)}',
      '.ps-actions{display:flex;gap:8px;align-items:center}',
      '.ps-btn{border:0;border-radius:12px;font:600 14px/1 inherit;padding:12px 16px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}',
      '.ps-btn:disabled{opacity:.5;cursor:not-allowed}',
      '.ps-btn-primary{background:linear-gradient(180deg,#6C5CE7,#5A4BD1);color:#fff;flex:1;justify-content:center}',
      '.ps-btn-primary:hover:not(:disabled){background:linear-gradient(180deg,#7B6BF0,#6358DE)}',
      '.ps-btn-ghost{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.16);color:rgba(255,255,255,.85)}',
      '.ps-cost{font-size:12px;color:rgba(255,255,255,.55)}',
      '.ps-cost b{color:#F5C451}',
      '.ps-err{background:rgba(60,10,10,.5);border:1px solid rgba(255,80,80,.4);color:#FFD1D1;border-radius:10px;padding:10px 12px;font-size:13px}',
      // desktop widen
      '@media(min-width:900px){.ps-scroll{padding:24px}}'
    ].join('');
    var s = document.createElement('style'); s.id = 'ps-css'; s.textContent = css;
    document.head.appendChild(s);
  }

  function resultClass() {
    if (S.aspectId === 'landscape') return 'ps-result wide';
    if (S.aspectId === 'story') return 'ps-result tall';
    return 'ps-result';
  }

  function render() {
    var host = S.host;
    var editing = !!S.currentAssetId;
    var cost = editing ? COST.edit_image : COST.generate_image;
    var placeholder = editing
      ? 'Describe a change — e.g. “make the background warmer”, “remove the person”…'
      : 'A cozy coffee shop latte on a wooden table, morning light…';
    var actions = editing
      ? '<button type="button" class="ps-btn ps-btn-ghost" id="ps-new" ' + (S.busy ? 'disabled' : '') + '>＋ New</button>' +
        '<button type="button" class="ps-btn ps-btn-primary" id="ps-edit" ' + (S.busy ? 'disabled' : '') + '>✎ Apply edit</button>'
      : '<button type="button" class="ps-btn ps-btn-ghost" id="ps-enhance" ' + (S.busy ? 'disabled' : '') + '>✦ Enhance</button>' +
        '<button type="button" class="ps-btn ps-btn-primary" id="ps-generate" ' + (S.busy ? 'disabled' : '') + '>Generate</button>';
    var costLine = editing
      ? 'Each edit costs <b>' + cost + ' credit' + (cost === 1 ? '' : 's') + '</b> · non-destructive — earlier versions are kept'
      : 'Generation costs <b>' + cost + ' credit' + (cost === 1 ? '' : 's') + '</b> · Enhance is free';
    host.innerHTML =
      '<div class="ps-root">' +
        '<div class="ps-head">' +
          '<h2>Prompt Studio</h2>' +
          '<span class="ps-sub">Describe it. We enhance it. Refine by prompting.</span>' +
        '</div>' +
        '<div class="ps-scroll"><div class="ps-stage">' +
          '<div class="' + resultClass() + '" id="ps-result">' + resultInner() + '</div>' +
          versionChips() +
          (S.enh && !editing ? enhancedPanel(S.enh) : '') +
          (S.error ? '<div class="ps-err">' + esc(S.error) + '</div>' : '') +
        '</div></div>' +
        '<div class="ps-composer"><div class="ps-composer-inner">' +
          (editing ? '' : '<div class="ps-chips" role="group" aria-label="Aspect ratio">' + chips() + '</div>') +
          '<div class="ps-box" id="ps-box">' +
            '<textarea class="ps-input" id="ps-input" rows="1" placeholder="' + esc(placeholder) + '" ' +
              'aria-label="' + (editing ? 'Describe a change' : 'Describe the image') + '">' + esc(S.prompt || '') + '</textarea>' +
          '</div>' +
          '<div class="ps-actions">' + actions + '</div>' +
          '<div class="ps-cost">' + costLine + '</div>' +
        '</div></div>' +
      '</div>';
    wire();
  }

  function versionChips() {
    var v = S.versions || [];
    if (v.length < 2) return '';
    var chips = v.map(function (row) {
      var on = String(row.id) === String(S.currentAssetId);
      return '<button type="button" class="ps-vchip" data-vid="' + esc(row.id) + '" aria-pressed="' + (on ? 'true' : 'false') + '" ' +
        'title="Version ' + esc(row.version) + '">v' + esc(row.version) + '</button>';
    }).join('');
    return '<div class="ps-versions"><span class="ps-versions-lbl">Versions</span>' + chips +
      '<span class="ps-versions-hint">tap to go back</span></div>';
  }

  function resultInner() {
    if (S.busy && S.busyKind === 'generate') {
      return '<div class="ps-busy"><div class="ps-spin"></div><div style="color:rgba(255,255,255,.7);font-size:13px">Creating your image…</div></div>';
    }
    if (S.imageUrl) {
      return '<img src="' + esc(S.imageUrl) + '" alt="' + esc(S.prompt || 'Generated image') + '">';
    }
    return '<div class="ps-empty">' +
      '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>' +
      '<div>Describe an image below, then Generate.</div></div>';
  }

  function chips() {
    return ASPECTS.map(function (a) {
      var on = (S.aspectId || 'square') === a.id;
      return '<button type="button" class="ps-chip" data-aspect="' + a.id + '" aria-pressed="' + (on ? 'true' : 'false') + '">' +
        esc(a.label) + '</button>';
    }).join('');
  }

  // Guard against null-ish values arriving as strings ("null"/"undefined") or empties,
  // so the enhanced panel never renders a stray "null" chip/row.
  function clean(v) {
    if (v == null) return '';
    var s = String(v).trim();
    if (s === '' || s.toLowerCase() === 'null' || s.toLowerCase() === 'undefined') return '';
    return s;
  }

  function enhancedPanel(e) {
    var sum = e.summary || {};
    var palette = (sum.color_palette || []).filter(function (c) { return clean(c); }).slice(0, 6).map(function (c) {
      return '<span class="ps-swatch" style="background:' + esc(c) + '" title="' + esc(c) + '"></span>';
    }).join('');
    var tags = [sum.mood, sum.lighting, sum.platform].map(clean).filter(Boolean);
    var tagsHtml = tags.map(function (t) { return '<span class="ps-tag">' + esc(t) + '</span>'; }).join('');
    var subject = clean(sum.subject), audience = clean(sum.audience), aspect = clean(sum.aspect_ratio), size = clean(e.size);
    return '<div class="ps-enh">' +
      '<div class="ps-enh-title">✦ Enhanced your prompt</div>' +
      (subject ? '<div class="ps-enh-row"><b>Subject</b>' + esc(subject) + '</div>' : '') +
      (audience ? '<div class="ps-enh-row"><b>For</b>' + esc(audience) + '</div>' : '') +
      (aspect ? '<div class="ps-enh-row"><b>Aspect</b>' + esc(aspect) + (size ? ' · ' + esc(size) : '') + '</div>' : '') +
      (tagsHtml ? '<div class="ps-tags">' + tagsHtml + '</div>' : '') +
      (palette ? '<div class="ps-swatches">' + palette + '</div>' : '') +
      '</div>';
  }

  function currentAspect() {
    var a = ASPECTS.filter(function (x) { return x.id === (S.aspectId || 'square'); })[0];
    return a || ASPECTS[0];
  }

  function wire() {
    var input = document.getElementById('ps-input');
    var box = document.getElementById('ps-box');
    if (input) {
      input.addEventListener('input', function () {
        S.prompt = input.value;
        input.style.height = 'auto';
        input.style.height = Math.min(120, input.scrollHeight) + 'px';
      });
      input.addEventListener('focus', function () { box && box.classList.add('focused'); });
      input.addEventListener('blur', function () { box && box.classList.remove('focused'); });
    }
    Array.prototype.forEach.call(document.querySelectorAll('.ps-chip'), function (c) {
      c.addEventListener('click', function () {
        S.aspectId = c.getAttribute('data-aspect');
        S.enh = null; // aspect changed — prior enhancement stale
        render();
      });
    });
    var eb = document.getElementById('ps-enhance');
    if (eb) eb.addEventListener('click', doEnhance);
    var gb = document.getElementById('ps-generate');
    if (gb) gb.addEventListener('click', doGenerate);
    var edb = document.getElementById('ps-edit');
    if (edb) edb.addEventListener('click', doEdit);
    var nb = document.getElementById('ps-new');
    if (nb) nb.addEventListener('click', startNew);
    Array.prototype.forEach.call(document.querySelectorAll('.ps-vchip'), function (c) {
      c.addEventListener('click', function () { selectVersion(c.getAttribute('data-vid')); });
    });
  }

  function doEnhance() {
    var p = (S.prompt || '').trim();
    if (!p) { S.error = 'Type a description first.'; render(); return; }
    S.busy = true; S.busyKind = 'enhance'; S.error = null; render();
    var eb = document.getElementById('ps-enhance');
    if (eb) { eb.disabled = true; eb.textContent = '✦ Enhancing…'; }
    jfetch('/creative/plan-preview', {
      method: 'POST',
      body: JSON.stringify({ prompt: p, aspect_ratio: currentAspect().ar })
    }).then(function (r) {
      S.busy = false;
      if (r && r.success) { S.enh = r; }
      else { S.error = (r && r.error === 'prompt_required') ? 'Type a description first.' : 'Could not enhance the prompt.'; }
      render();
    }).catch(function (err) {
      S.busy = false; S.error = 'Enhance failed (' + (err.status || 'network') + ').'; render();
    });
  }

  // The EngineKernel wraps every service result in an envelope
  // { success, data:<service result>, credits_used }. Errors may sit at the top
  // level (gate rejections like NO_CREDITS) or nested under data (service errors).
  function unwrap(r) { return (r && typeof r === 'object' && r.data && typeof r.data === 'object') ? r.data : r; }
  function pickUrl(d) { return d && (d.url || d.image_url || (d.asset && d.asset.url)); }
  function pickId(d) { return d && (d.asset_id || d.id || (d.asset && d.asset.id)); }
  function friendlyErr(o) {
    if (!o) return null;
    if (o.code === 'NO_CREDITS') return o.error || 'Not enough credits to generate.';
    return o.error || o.message || null;
  }

  function doGenerate() {
    var p = (S.prompt || '').trim();
    if (!p) { S.error = 'Type a description first.'; render(); return; }
    S.busy = true; S.busyKind = 'generate'; S.error = null; S.imageUrl = null; render();
    jfetch('/creative/generate/image', {
      method: 'POST',
      body: JSON.stringify({ prompt: p, aspect_ratio: currentAspect().ar })
    }).then(function (r) {
      // Gate rejection (NO_CREDITS / PLAN_GATED) — surface the real message.
      if (r && r.success === false) { S.busy = false; S.error = friendlyErr(r) || 'Generation was blocked.'; render(); return; }
      var d = unwrap(r);
      if (d && d.success === false) { S.busy = false; S.error = friendlyErr(d) || 'Generation failed.'; render(); return; }
      var url = pickUrl(d), assetId = pickId(d);
      if (url) { onImage(url, assetId); return; }
      if (assetId) { pollAsset(assetId, 0); return; }
      S.busy = false; S.error = 'Generation returned no image.'; render();
    }).catch(function (err) {
      S.busy = false;
      var pl = err.payload && (err.payload.data || err.payload);
      S.error = friendlyErr(pl) || ('Generation failed (' + (err.status || 'network') + ').');
      render();
    });
  }

  // Shared success handler for a produced image (generate OR edit): show it, make it the
  // current asset (so the next prompt edits THIS image), and refresh the version lineage.
  function onImage(url, assetId) {
    S.busy = false; S.imageUrl = url;
    if (assetId) { S.currentAssetId = assetId; S.lastAssetId = assetId; }
    render();
    if (assetId) fetchVersions(assetId);
  }

  function pollAsset(id, tries) {
    if (tries > 60) { S.busy = false; S.error = 'Generation timed out.'; render(); return; }
    jfetch('/creative/assets/' + id + '/poll', { method: 'GET' }).then(function (r) {
      var d = unwrap(r);
      var url = pickUrl(d);
      var status = d && (d.status || (d.asset && d.asset.status));
      if (url && (status === 'completed' || status === 'success' || !status)) { onImage(url, id); return; }
      if (status === 'failed' || status === 'error') { S.busy = false; S.error = 'Generation failed.'; render(); return; }
      setTimeout(function () { pollAsset(id, tries + 1); }, 2000);
    }).catch(function () { setTimeout(function () { pollAsset(id, tries + 1); }, 2500); });
  }

  // Edit-via-prompt (Builder model): apply a natural-language change to the current image.
  // Non-destructive — creates a new version child; earlier versions are kept.
  function doEdit() {
    var p = (S.prompt || '').trim();
    if (!p) { S.error = 'Describe the change you want.'; render(); return; }
    if (!S.currentAssetId) { S.error = 'Generate an image first.'; render(); return; }
    S.busy = true; S.busyKind = 'generate'; S.error = null; render();
    var idem = 'ps-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
    jfetch('/creative/edit', {
      method: 'POST',
      body: JSON.stringify({ source_asset_id: S.currentAssetId, prompt: p, idempotency_key: idem })
    }).then(function (r) {
      if (r && r.success === false) { S.busy = false; S.error = friendlyErr(r) || 'Edit was blocked.'; render(); return; }
      var d = unwrap(r);
      if (d && d.success === false) { S.busy = false; S.error = friendlyErr(d) || 'Edit failed.'; render(); return; }
      var url = pickUrl(d), assetId = pickId(d);
      if (url) { S.prompt = ''; onImage(url, assetId); return; }
      if (assetId) { S.prompt = ''; pollAsset(assetId, 0); return; }
      S.busy = false; S.error = 'Edit returned no image.'; render();
    }).catch(function (err) {
      S.busy = false;
      var pl = err.payload && (err.payload.data || err.payload);
      S.error = friendlyErr(pl) || ('Edit failed (' + (err.status || 'network') + ').');
      render();
    });
  }

  // Refresh the non-destructive version lineage for the chips (server is source of truth).
  function fetchVersions(assetId) {
    jfetch('/creative/assets/' + assetId + '/versions', { method: 'GET' }).then(function (r) {
      var d = unwrap(r);
      S.versions = (d && d.versions) ? d.versions : [];
      S.rootAssetId = d && d.root_asset_id;
      render();
    }).catch(function () { /* chips are a nicety — never block on them */ });
  }

  // Undo / go back: select an earlier (or later) version; further edits branch from it.
  function selectVersion(id) {
    var row = (S.versions || []).filter(function (v) { return String(v.id) === String(id); })[0];
    if (!row) return;
    S.currentAssetId = row.id; S.imageUrl = row.url; S.error = null; render();
  }

  function startNew() {
    S.currentAssetId = null; S.versions = []; S.rootAssetId = null;
    S.imageUrl = null; S.enh = null; S.prompt = ''; S.error = null; render();
  }

  window._mountPromptStudio = function (rootEl, caps) {
    injectCss();
    var host = rootEl || document.getElementById('studio-root') || document.body;
    try { host.style.position = 'relative'; } catch (_) {}
    // Robustness: .ps-root fills the host via absolute inset:0, so the host MUST have
    // height. If the host would collapse (no height from its container — e.g. a bare
    // mount), fall back to the viewport so the surface never squashes into a sliver.
    try { if (host.getBoundingClientRect().height < 80) { host.style.height = '100dvh'; host.style.minHeight = '100vh'; } } catch (_) {}
    S = { host: host, caps: caps || {}, prompt: '', aspectId: 'square', enh: null, imageUrl: null, busy: false, error: null, currentAssetId: null, versions: [], rootAssetId: null };
    render();
    return true;
  };
})();
