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
  var COST = { generate_image: 4, generate_image_mini: 2, generate_image_high: 21, edit_image: 6 };   // PRICE-1 (2026-09-28)
  var VIDEO_COST = 28;   // PRICE-1: a 6-second video

  // Video is entitlement-gated (Pro+ / companion_app). The one interface offers a Video mode
  // only when the workspace is entitled — nano-banana chat-first, consolidated (Owner decision 2).
  function videoEnabled() {
    try { return !!(S.caps && S.caps.engines && S.caps.engines.creative && S.caps.engines.creative.video); }
    catch (e) { return false; }
  }

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
      '.ps-root{position:absolute;inset:0;display:flex;flex-direction:column;background:var(--bg,#F7F6F3);color:var(--t1,#111);font:400 14px/1.5 var(--fb,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif)}',
      '.ps-head{display:flex;align-items:center;gap:14px;padding:20px 28px 16px;flex-shrink:0;border-bottom:1px solid var(--bd,rgba(0,0,0,.08))}',
      '.ps-head h2{font:700 22px/1.2 var(--fh,inherit);margin:0;letter-spacing:-.01em;color:var(--t1,#111)}',
      '.ps-head .ps-sub{color:var(--t3,#777);font-size:13px;margin-top:3px}',
      '.ps-costpill{margin-left:auto;font-size:12px;color:var(--t2,#555);background:var(--s1,#fff);border:1px solid var(--bd,rgba(0,0,0,.1));border-radius:999px;padding:6px 12px;white-space:nowrap}',
      '.ps-body{flex:1;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;display:grid;grid-template-columns:minmax(320px,400px) minmax(0,1fr);gap:24px;padding:24px 28px 32px;align-items:start}',
      '.ps-panel{background:var(--s1,#fff);border:1px solid var(--bd,rgba(0,0,0,.1));border-radius:16px;padding:20px;display:flex;flex-direction:column;gap:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);position:sticky;top:0}',
      '.ps-lbl{font:600 12px/1 var(--fb,inherit);letter-spacing:.06em;text-transform:uppercase;color:var(--t3,#777);margin:0 0 -6px}',
      '.ps-hint{font-size:12px;color:var(--t3,#777);margin-top:-8px}',
      /* type switch */
      '.ps-mode{display:grid;grid-template-columns:1fr 1fr;gap:6px;background:var(--s2,#F1F0EC);border:1px solid var(--bd,rgba(0,0,0,.08));border-radius:12px;padding:4px}',
      '.ps-modebtn{display:flex;align-items:center;justify-content:center;gap:8px;background:transparent;border:0;color:var(--t2,#555);font:600 13.5px/1 var(--fb,inherit);padding:11px 12px;border-radius:9px;cursor:pointer;transition:background .15s,color .15s}',
      '.ps-modebtn svg{width:16px;height:16px}',
      '.ps-modebtn[aria-pressed="true"]{background:var(--s1,#fff);color:var(--t1,#111);box-shadow:0 1px 3px rgba(0,0,0,.12)}',
      /* prompt */
      '.ps-box{background:var(--bg,#F7F6F3);border:1px solid var(--bd2,rgba(0,0,0,.14));border-radius:12px;padding:12px 14px;transition:border-color .15s,box-shadow .15s}',
      '.ps-box.focused{border-color:var(--p,#6C5CE7);box-shadow:0 0 0 3px color-mix(in srgb,var(--p,#6C5CE7) 18%,transparent)}',
      '.ps-input{width:100%;box-sizing:border-box;background:transparent;color:var(--t1,#111);border:0;outline:0;resize:none;font:400 15px/1.55 var(--fb,inherit);min-height:96px;max-height:240px;padding:0}',
      '.ps-input::placeholder{color:var(--t3,#999)}',
      '.ps-examples{display:flex;flex-wrap:wrap;gap:6px;align-items:center}',
      '.ps-examples>span{font-size:12px;color:var(--t3,#777);margin-right:2px}',
      '.ps-ex{background:var(--s2,#F1F0EC);border:1px solid var(--bd,rgba(0,0,0,.08));color:var(--t2,#444);font:500 12.5px/1.3 var(--fb,inherit);padding:7px 11px;border-radius:999px;cursor:pointer;text-align:left}',
      '.ps-ex:hover{border-color:var(--p,#6C5CE7);color:var(--t1,#111)}',
      /* size tiles */
      '.ps-chips{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}',
      '.ps-chip{display:flex;flex-direction:column;align-items:center;gap:6px;background:var(--bg,#F7F6F3);border:1px solid var(--bd,rgba(0,0,0,.1));color:var(--t2,#555);font:600 12.5px/1.2 var(--fb,inherit);padding:12px 6px 10px;border-radius:12px;cursor:pointer;transition:border-color .15s,background .15s}',
      '.ps-chip small{font:400 11px/1.2 var(--fb,inherit);color:var(--t3,#888)}',
      '.ps-chip i{display:block;border:2px solid currentColor;border-radius:3px;opacity:.7}',
      '.ps-chip[aria-pressed="true"]{border-color:var(--p,#6C5CE7);background:color-mix(in srgb,var(--p,#6C5CE7) 8%,var(--s1,#fff));color:var(--t1,#111)}',
      '.ps-chip[aria-pressed="true"] i{opacity:1;border-color:var(--p,#6C5CE7)}',
      /* actions */
      '.ps-actions{display:flex;flex-direction:column;gap:8px}',
      '.ps-btn{border:0;border-radius:12px;font:600 14.5px/1 var(--fb,inherit);padding:14px 16px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;white-space:nowrap;transition:filter .15s,background .15s}',
      '.ps-btn:disabled{opacity:.55;cursor:not-allowed}',
      '.ps-btn-primary{background:var(--p,#6C5CE7);color:#fff;width:100%}',
      '.ps-btn-primary:hover:not(:disabled){filter:brightness(1.07)}',
      '.ps-btn-ghost{background:transparent;border:1px solid var(--bd2,rgba(0,0,0,.14));color:var(--t1,#111);padding:11px 14px;font-size:13.5px}',
      '.ps-btn-ghost:hover:not(:disabled){background:var(--s2,#F1F0EC)}',
      '.ps-cost{font-size:12px;color:var(--t3,#777);text-align:center;margin-top:-6px}',
      '.ps-cost b{color:var(--t1,#111);font-weight:600}',
      '.ps-err{background:color-mix(in srgb,#EF4444 10%,var(--s1,#fff));border:1px solid color-mix(in srgb,#EF4444 40%,transparent);color:var(--t1,#111);border-radius:10px;padding:10px 12px;font-size:13px}',
      /* how we will make it */
      '.ps-enh{border:1px solid var(--bd,rgba(0,0,0,.1));background:var(--bg,#F7F6F3);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:8px}',
      '.ps-enh-title{font:600 13px/1.2 var(--fb,inherit);color:var(--t1,#111);display:flex;align-items:center;gap:6px}',
      '.ps-enh-row{font-size:12.5px;color:var(--t2,#555)}',
      '.ps-enh-row b{color:var(--t3,#888);font-weight:500;margin-right:6px}',
      '.ps-swatches{display:flex;gap:6px;flex-wrap:wrap}',
      '.ps-swatch{width:20px;height:20px;border-radius:6px;border:1px solid var(--bd2,rgba(0,0,0,.14))}',
      '.ps-tags{display:flex;gap:6px;flex-wrap:wrap}',
      '.ps-tag{font-size:11px;background:var(--s1,#fff);border:1px solid var(--bd,rgba(0,0,0,.1));border-radius:999px;padding:3px 9px;color:var(--t2,#555)}',
      /* canvas */
      '.ps-canvas{display:flex;flex-direction:column;gap:14px;min-width:0}',
      '.ps-result{width:100%;max-width:760px;margin:0 auto;aspect-ratio:1/1;border-radius:18px;overflow:hidden;background:var(--s1,#fff);border:1px solid var(--bd,rgba(0,0,0,.1));display:flex;align-items:center;justify-content:center;position:relative;box-shadow:0 1px 2px rgba(0,0,0,.04)}',
      '.ps-result.wide{aspect-ratio:16/9}.ps-result.tall{aspect-ratio:9/16;max-width:420px;max-height:78vh}',
      '.ps-result img,.ps-result video{width:100%;height:100%;object-fit:contain;display:block}',
      '.ps-result video{background:#000}',
      '.ps-empty{color:var(--t3,#888);text-align:center;padding:32px;max-width:320px;display:flex;flex-direction:column;align-items:center;gap:10px}',
      '.ps-empty .ps-empty-ic{width:56px;height:56px;border-radius:16px;background:var(--s2,#F1F0EC);display:flex;align-items:center;justify-content:center;color:var(--t2,#555)}',
      '.ps-empty b{color:var(--t1,#111);font:600 15px/1.3 var(--fb,inherit)}',
      '.ps-empty span{font-size:13px;line-height:1.5}',
      '.ps-busy{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;background:color-mix(in srgb,var(--s1,#fff) 82%,transparent);backdrop-filter:blur(6px);color:var(--t2,#555);font-size:13px}',
      '.ps-spin{width:36px;height:36px;border-radius:50%;border:3px solid var(--bd,rgba(0,0,0,.1));border-top-color:var(--p,#6C5CE7);animation:psSpin .9s linear infinite}',
      '@keyframes psSpin{to{transform:rotate(360deg)}}',
      '.ps-ractions{display:flex;gap:8px;flex-wrap:wrap;justify-content:center}',
      '.ps-raction{background:var(--s1,#fff);border:1px solid var(--bd2,rgba(0,0,0,.14));color:var(--t1,#111);font:600 13px/1 var(--fb,inherit);padding:11px 16px;border-radius:10px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}',
      '.ps-raction:hover{background:var(--s2,#F1F0EC)}',
      '.ps-versions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:center}',
      '.ps-versions-lbl{font-size:12px;color:var(--t3,#777)}',
      '.ps-versions-hint{font-size:11px;color:var(--t3,#999)}',
      '.ps-vchip{background:var(--s1,#fff);border:1px solid var(--bd,rgba(0,0,0,.1));color:var(--t2,#555);font:600 12px/1 var(--fb,inherit);padding:7px 11px;border-radius:10px;cursor:pointer;min-width:38px}',
      '.ps-vchip[aria-pressed="true"]{border-color:var(--p,#6C5CE7);color:var(--t1,#111)}',
      /* phone: canvas first, one column */
      '@media(max-width:899px){.ps-head{padding:14px 16px 12px}.ps-head h2{font-size:19px}.ps-body{grid-template-columns:1fr;padding:14px 12px 96px;gap:14px}.ps-body.has-result .ps-canvas{order:-1}.ps-body:not(.has-result) .ps-result{aspect-ratio:auto;min-height:0}.ps-body:not(.has-result) .ps-empty{flex-direction:row;text-align:left;padding:16px;max-width:none;gap:14px}.ps-body:not(.has-result) .ps-empty-ic{flex:0 0 44px;width:44px;height:44px}.ps-body:not(.has-result) .ps-empty b{font-size:14px}.ps-panel{position:static;padding:16px}.ps-costpill{display:none}}',
      /* STUDIO-CERT-2: one input box in the glass design (the glass textarea rule drew a second box inside it); the ghost button keeps its outline */
      'html.lg-ui .main .ps-box .ps-input,html.lg-ui .main .ps-box .ps-input:focus{background:transparent;border:0;box-shadow:none;border-radius:0;padding:0;min-height:96px}',
      'html.lg-ui .ps-btn-ghost{border:1px solid var(--lg-hairline,rgba(120,120,160,.3))}',
    ].join('');
    var s = document.createElement('style'); s.id = 'ps-css'; s.textContent = css;
    document.head.appendChild(s);
  }

  function resultClass() {
    if (S.mode === 'video') return 'ps-result wide';
    if (S.aspectId === 'landscape') return 'ps-result wide';
    if (S.aspectId === 'story') return 'ps-result tall';
    return 'ps-result';
  }

  function render() {
    var host = S.host;
    var video = S.mode === 'video';
    // LAUNCH-STUDIO-1 (Owner 2026-09-28: "We are launching just image and video AI generation"): no editing —
    // after a result the composer generates a new image (or another take of the same prompt); edit mode is off.
    var editing = false;
    var cost = video ? VIDEO_COST : (editing ? COST.edit_image : COST.generate_image);
    var placeholder = video
      ? 'Describe a short video — e.g. “a 5-second clip of waves rolling onto a beach at sunset”…'
      : (editing ? 'Describe a change — e.g. “make the background warmer”, “remove the person”…'
                 : 'e.g. A latte on a wooden table, morning light');
    var actions = video
      ? '<button type="button" class="ps-btn ps-btn-primary" id="ps-genvideo" ' + (S.busy ? 'disabled' : '') + '>Generate video</button>'
      : (editing
        ? '<button type="button" class="ps-btn ps-btn-ghost" id="ps-new" ' + (S.busy ? 'disabled' : '') + '>＋ New</button>' +
          '<button type="button" class="ps-btn ps-btn-primary" id="ps-edit" ' + (S.busy ? 'disabled' : '') + '>✎ Apply edit</button>'
        : '<button type="button" class="ps-btn ps-btn-ghost" id="ps-enhance" ' + (S.busy ? 'disabled' : '') + '>✦ Enhance</button>' +
          '<button type="button" class="ps-btn ps-btn-primary" id="ps-generate" ' + (S.busy ? 'disabled' : '') + '>Generate</button>');
    var costLine = video
      ? 'Video costs <b>' + VIDEO_COST + ' credits</b> · takes a minute or two'
      : (editing
        ? 'Each edit costs <b>' + cost + ' credit' + (cost === 1 ? '' : 's') + '</b> · non-destructive — earlier versions are kept'
        : 'Generation costs <b>' + cost + ' credit' + (cost === 1 ? '' : 's') + '</b> · Enhance is free');
    // Consolidated one-interface mode toggle — only when the workspace is video-entitled (Owner decision 2).
    var modeToggle = videoEnabled()
      ? '<div class="ps-mode" role="group" aria-label="Output type">' +
          '<button type="button" class="ps-modebtn" data-mode="image" aria-pressed="' + (!video ? 'true' : 'false') + '">Image</button>' +
          '<button type="button" class="ps-modebtn" data-mode="video" aria-pressed="' + (video ? 'true' : 'false') + '">Video</button>' +
        '</div>'
      : '';
    var ICON_IMG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
    var ICON_VID = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="15" height="14" rx="3"/><path d="M17 10l5-3v10l-5-3"/></svg>';
    var typeSwitch = videoEnabled()
      ? '<div class="ps-mode" role="group" aria-label="What to create">' +
          '<button type="button" class="ps-modebtn" data-mode="image" aria-pressed="' + (!video) + '">' + ICON_IMG + 'Image</button>' +
          '<button type="button" class="ps-modebtn" data-mode="video" aria-pressed="' + video + '">' + ICON_VID + 'Video</button>' +
        '</div>'
      : '';
    var EX = video
      ? ['Waves rolling onto a beach at sunset, slow pan', 'Steam rising from a fresh coffee, close-up', 'A city street at night with neon reflections']
      : ['Fresh pastries on a marble counter, soft morning light', 'A bright modern office with a team at work, candid', 'A gift box with a ribbon on a pastel background, flat lay'];
    var examples = (S.prompt || '').trim() ? '' :
      '<div class="ps-examples"><span>Try</span>' + EX.map(function (t) { return '<button type="button" class="ps-ex" data-ex="' + esc(t) + '">' + esc(t) + '</button>'; }).join('') + '</div>';
    var buttons = video
      ? '<button type="button" class="ps-btn ps-btn-primary" id="ps-genvideo" ' + (S.busy ? 'disabled' : '') + '>' + ICON_VID.replace('<svg ', '<svg width="18" height="18" ') + 'Generate video</button>'
      : '<button type="button" class="ps-btn ps-btn-primary" id="ps-generate" ' + (S.busy ? 'disabled' : '') + '>' + ICON_IMG.replace('<svg ', '<svg width="18" height="18" ') + 'Generate image</button>' +
        '<button type="button" class="ps-btn ps-btn-ghost" id="ps-enhance" ' + (S.busy ? 'disabled' : '') + '>✦ Enhance my prompt · free</button>';
    host.innerHTML =
      '<div class="ps-root">' +
        '<div class="ps-head"><div><h2>Studio</h2><div class="ps-sub">Describe it and we create it — images' + (videoEnabled() ? ' and short videos' : '') + ' for your business.</div></div>' +
          '<span class="ps-costpill">' + (video ? VIDEO_COST + ' credits per video' : COST.generate_image + ' credits per image') + '</span></div>' +
        '<div class="ps-body' + ((S.busy || S.imageUrl || S.videoUrl) ? ' has-result' : '') + '">' +
          '<section class="ps-panel" aria-label="Create">' +
            typeSwitch +
            '<label class="ps-lbl" for="ps-input">Describe your ' + (video ? 'video' : 'image') + '</label>' +
            '<div class="ps-box" id="ps-box"><textarea class="ps-input" id="ps-input" rows="4" placeholder="' + esc(placeholder) + '" aria-label="' + (video ? 'Describe the video' : 'Describe the image') + '">' + esc(S.prompt || '') + '</textarea></div>' +
            '<div class="ps-hint">Name the subject, the setting, the light and the mood.</div>' +
            examples +
            (video ? '' : '<div class="ps-lbl">Size</div><div class="ps-chips" role="group" aria-label="Size">' + chips() + '</div>') +
            '<div class="ps-actions">' + buttons + '</div>' +
            '<div class="ps-cost">' + costLine + '</div>' +
            (!video && S.enh ? enhancedPanel(S.enh) : '') +
            (S.error ? '<div class="ps-err" role="alert">' + esc(S.error) + '</div>' : '') +
          '</section>' +
          '<section class="ps-canvas" aria-label="Result">' +
            '<div class="' + resultClass() + '" id="ps-result">' + resultInner() + '</div>' +
            (video ? '' : resultActions() + versionChips()) +
          '</section>' +
        '</div>' +
      '</div>';
    wire();
    // example prompts fill the box
    Array.prototype.forEach.call(host.querySelectorAll('.ps-ex'), function (b) {
      b.addEventListener('click', function () { S.prompt = b.getAttribute('data-ex'); S.enh = null; render(); var i = document.getElementById('ps-input'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } });
    });
  }

  function resultActions() {
    if (!S.imageUrl || S.busy) return '';
    var canVary = !!S.genPrompt;
    return '<div class="ps-ractions">' +
      '<button type="button" class="ps-raction" id="ps-download">⬇ Save image</button>' +
      (canVary ? '<button type="button" class="ps-raction" id="ps-vary">↻ Make another</button>' : '') +
      '</div>';
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
    if (S.busy && S.busyKind === 'video') {
      return '<div class="ps-busy"><div class="ps-spin"></div><div>Creating your video — this can take a minute or two</div></div>';
    }
    if (S.busy && S.busyKind === 'generate') {
      return '<div class="ps-busy"><div class="ps-spin"></div><div>Creating your image…</div></div>';
    }
    if (S.mode === 'video' && S.videoUrl) {
      return '<video src="' + esc(S.videoUrl) + '" controls playsinline style="width:100%;height:100%;object-fit:contain;background:#000"></video>';
    }
    if (S.mode !== 'video' && S.imageUrl) {
      return '<img src="' + esc(S.imageUrl) + '" alt="' + esc(S.prompt || 'Generated image') + '">';
    }
    if (S.mode === 'video') {
      return '<div class="ps-empty"><div class="ps-empty-ic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="5" width="15" height="14" rx="3"/><path d="M17 10l5-3v10l-5-3"/></svg></div>' +
        '<b>Your video appears here</b><span>Describe a short scene and press Generate video. It takes a minute or two.</span></div>';
    }
    return '<div class="ps-empty"><div class="ps-empty-ic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></div>' +
      '<b>Your image appears here</b><span>Describe what you want, pick a size and press Generate image.</span></div>';
  }

  function chips() {
    var SHAPE = { square: [18, 18], story: [12, 20], landscape: [22, 13] }, NAME = { square: 'Feed post', story: 'Story', landscape: 'Cover' };
    return ASPECTS.map(function (a) {
      var on = (S.aspectId || 'square') === a.id, sh = SHAPE[a.id] || [18, 18];
      return '<button type="button" class="ps-chip" data-aspect="' + a.id + '" aria-pressed="' + (on ? 'true' : 'false') + '">' +
        '<i style="width:' + sh[0] + 'px;height:' + sh[1] + 'px"></i>' + esc(NAME[a.id] || a.label) + '<small>' + esc(a.ar) + '</small></button>';
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
      '<div class="ps-enh-title">✦ How we will make it</div>' +
      (subject ? '<div class="ps-enh-row"><b>Subject</b>' + esc(subject) + '</div>' : '') +
      (audience ? '<div class="ps-enh-row"><b>For</b>' + esc(audience) + '</div>' : '') +
      (aspect ? '<div class="ps-enh-row"><b>Aspect</b>' + esc(aspect) + (size ? ' · ' + esc(size) : '') + '</div>' : '') +
      (tagsHtml ? '<div class="ps-tags">' + tagsHtml + '</div>' : '') +
      (palette ? '<div class="ps-swatches">' + palette + '</div>' : '') +
      // RFC-0009: honest state of the preview — bound to this exact request, or not.
      (planTokenFor((S.prompt || '').trim())
        ? '<div class="ps-enh-row ps-enh-bound">✓ Generate will follow this.</div>'
        : '<div class="ps-enh-row ps-enh-bound">You changed the description — enhance again to update this.</div>') +
      ((e.flags || []).indexOf('logo_requested_no_logo_asset') >= 0 ? '<div class="ps-enh-row"><b>Note</b>No logo file is in your brand kit yet, so no logo can be drawn.</div>' : '') +
      ((e.exact_text_missing || []).length ? '<div class="ps-enh-row"><b>Note</b>Your quoted text was not carried verbatim: ' + esc(e.exact_text_missing.join(' · ')) + '</div>' : '') +
      // RFC-0009 P2: a named platform agent — say who was resolved and, truthfully, what the provider can do with it.
      (e.subject_identity && e.subject_identity.name ? '<div class="ps-enh-row"><b>Who</b>' + esc(e.subject_identity.name + (e.subject_identity.title ? ', ' + e.subject_identity.title : '')) + (e.subject_identity.limitation ? ' — ' + esc(e.subject_identity.limitation) : '') + '</div>' : '') +
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
        input.style.height = Math.max(96, Math.min(240, input.scrollHeight)) + 'px';
        // RFC-0009 P1: keep the preview-binding note truthful while the customer types (no full re-render).
        var bound = document.querySelector('.ps-enh-bound');
        if (bound) bound.innerHTML = planTokenFor((S.prompt || '').trim())
          ? '✓ Generate will follow this.'
          : 'You changed the description — enhance again to update this.';
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
    var db = document.getElementById('ps-download');
    if (db) db.addEventListener('click', doDownload);
    var vb = document.getElementById('ps-vary');
    if (vb) vb.addEventListener('click', doVariation);
    Array.prototype.forEach.call(document.querySelectorAll('.ps-modebtn'), function (b) {
      b.addEventListener('click', function () { switchMode(b.getAttribute('data-mode')); });
    });
    var gv = document.getElementById('ps-genvideo');
    if (gv) gv.addEventListener('click', doGenerateVideo);
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
      if (r && r.success) {
        S.enh = r;
        // RFC-0009 P1 (2026-09-16): the preview token binds THIS prompt + aspect to the exact
        // compiled prompt the server previewed. Generate sends it only while both are unchanged.
        S.planToken = r.plan_token || null; S.planPrompt = p; S.planAspect = currentAspect().ar;
      }
      else { S.error = (r && r.error === 'prompt_required') ? 'Type a description first.' : 'Could not enhance the prompt.'; }
      render();
    }).catch(function (err) {
      S.busy = false; S.error = 'Enhance failed (' + (err.status || 'network') + ').'; render();
    });
  }

  // RFC-0009 P1: the token is valid for exactly the previewed request; anything else means the
  // customer must preview again — the server enforces the same rule (PREVIEW_REQUIRED).
  function planTokenFor(p) {
    return (S.planToken && S.planPrompt === p && S.planAspect === currentAspect().ar) ? S.planToken : null;
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
    S.genPrompt = p; // remembered so "Make another" can produce a fresh variation
    S.busy = true; S.busyKind = 'generate'; S.error = null; S.imageUrl = null; render();
    var tok = planTokenFor(p);
    // STUDIO-CERT-2: the Studio makes what the owner described in the size they picked - never the chat design-look painter
    var body = { prompt: p, aspect_ratio: currentAspect().ar, no_recipe: true };
    if (tok) body.plan_token = tok; // RFC-0009 P1: generate exactly what was previewed
    jfetch('/creative/generate/image', {
      method: 'POST',
      body: JSON.stringify(body)
    }).then(function (r) {
      // Gate rejection (NO_CREDITS / PLAN_GATED) — surface the real message.
      if (r && r.success === false) { S.busy = false; if (r.code === 'PREVIEW_REQUIRED') { S.planToken = null; S.enh = null; } S.error = friendlyErr(r) || 'Generation was blocked.'; render(); return; }
      var d = unwrap(r);
      if (d && d.code === 'PREVIEW_REQUIRED') { S.busy = false; S.planToken = null; S.enh = null; S.error = d.error || 'Please preview again before generating.'; render(); return; }
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
    S.imageUrl = null; S.enh = null; S.prompt = ''; S.error = null; S.genPrompt = null; render();
  }

  // Make another: a fresh variation from the SAME generation prompt (new image, not an edit).
  function doVariation() {
    if (!S.genPrompt) return;
    S.currentAssetId = null; S.versions = []; S.rootAssetId = null;
    S.prompt = S.genPrompt;
    doGenerate();
  }

  // Save the current image. Fetch as a blob so the browser downloads rather than navigates
  // (cross-path same-origin), with a filename derived from the prompt.
  function doDownload() {
    if (!S.imageUrl) return;
    var name = (S.genPrompt || S.prompt || 'prompt-studio').toLowerCase()
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 48) || 'image';
    fetch(S.imageUrl).then(function (r) { return r.blob(); }).then(function (b) {
      var u = URL.createObjectURL(b);
      var a = document.createElement('a');
      a.href = u; a.download = name + '.png';
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(u); a.remove(); }, 1500);
    }).catch(function () {
      // Fallback: open in a new tab so the user can long-press / right-click save.
      window.open(S.imageUrl, '_blank');
    });
  }

  // Consolidated one-interface: switch between Image and Video output (Owner decision 2).
  function switchMode(m) {
    if (S.mode === m || (m === 'video' && !videoEnabled())) return;
    S.mode = m; S.error = null; S.enh = null;
    render();
  }

  function pickVideoUrl(d) { return d && (d.video_url || d.url || (d.asset && d.asset.url)); }

  // Video-by-prompt (async): prompt → generate_video → poll until the clip is ready → play inline.
  function doGenerateVideo() {
    var p = (S.prompt || '').trim();
    if (!p) { S.error = 'Describe the video you want.'; render(); return; }
    S.busy = true; S.busyKind = 'video'; S.error = null; S.videoUrl = null; render();
    jfetch('/creative/generate/video', { method: 'POST', body: JSON.stringify({ prompt: p }) })
      .then(function (r) {
        if (r && r.success === false) { S.busy = false; S.error = friendlyErr(r) || 'Video was blocked.'; render(); return; }
        var d = unwrap(r);
        if (d && d.success === false) { S.busy = false; S.error = friendlyErr(d) || 'Video failed.'; render(); return; }
        var url = pickVideoUrl(d), assetId = pickId(d);
        if (url) { S.busy = false; S.videoUrl = url; render(); return; }
        if (assetId) { pollVideo(assetId, 0); return; }
        S.busy = false; S.error = 'Video did not start.'; render();
      })
      .catch(function (err) {
        S.busy = false;
        var pl = err.payload && (err.payload.data || err.payload);
        S.error = friendlyErr(pl) || ('Video failed (' + (err.status || 'network') + ').');
        render();
      });
  }

  function pollVideo(id, tries) {
    if (tries > 90) { S.busy = false; S.error = 'Video is taking a while — it will appear in Results when ready.'; render(); return; }
    jfetch('/creative/assets/' + id + '/poll', { method: 'GET' }).then(function (r) {
      var d = unwrap(r);
      var url = pickVideoUrl(d);
      var status = d && (d.status || (d.asset && d.asset.status));
      if (url && (status === 'completed' || status === 'success' || !status)) { S.busy = false; S.videoUrl = url; render(); return; }
      if (status === 'failed' || status === 'error') { S.busy = false; S.error = 'Video generation failed.'; render(); return; }
      setTimeout(function () { pollVideo(id, tries + 1); }, 3000);
    }).catch(function () { setTimeout(function () { pollVideo(id, tries + 1); }, 3500); });
  }

  window._mountPromptStudio = function (rootEl, caps) {
    injectCss();
    var host = rootEl || document.getElementById('studio-root') || document.body;
    try { host.style.position = 'relative'; } catch (_) {}
    // Robustness: .ps-root fills the host via absolute inset:0, so the host MUST have
    // height. If the host would collapse (no height from its container — e.g. a bare
    // mount), fall back to the viewport so the surface never squashes into a sliver.
    try { if (host.getBoundingClientRect().height < 80) { host.style.height = '100dvh'; host.style.minHeight = '100vh'; } } catch (_) {}
    S = { host: host, caps: caps || {}, mode: 'image', prompt: '', aspectId: 'square', enh: null, imageUrl: null, videoUrl: null, busy: false, error: null, currentAssetId: null, versions: [], rootAssetId: null, genPrompt: null };
    render();
    return true;
  };
})();
