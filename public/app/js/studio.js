/* ═══════════════════════════════════════════════════════════════
   studio.js — v3.9.5 — HTML template editor
   Pattern: iframe + postMessage (same as builder.js)

   Screens:
     1) Gallery — grid of templates, each card shows iframe preview
        of /storage/studio-previews/{slug}.html scaled down.
     2) Editor — left tool panel (Content / Images / Colors / Export)
        + center iframe showing /api/studio/designs/{id}/preview.

   Save mechanism:
     The injected editor script in the iframe serializes its own
     document.documentElement.outerHTML and posts it back; studio.js
     PUTs {content_html: <html>} to /api/studio/designs/{id}.
   ═══════════════════════════════════════════════════════════════ */
(function(){
  if (window._studioInit) return;
  window._studioInit = true;

  var _designId   = null;
  var _designName = 'Untitled Design';
  var _currentTab = 'content';
  var _fieldList  = [];     // last reported list of data-field elements from iframe
  var _saveTimer  = null;
  var _pendingSerialize = null;
  var _rootEl     = null;   // #studio-root — passed by core.js nav dispatcher

  // ── Public entry points ───────────────────────────────────────
  // core.js calls studioLoad(el) via its engine-dispatch pattern.
  window.studioLoad = function (rootEl) {
    _rootEl = rootEl || document.getElementById('studio-root') || document.body;
    try { _rootEl.style.position = 'relative'; } catch(_){}
    _mountGallery();
  };
  // Manual invocation fallback — same as studioLoad with auto-resolved root.
  window.openStudio = function () { window.studioLoad(null); };

  // ── Helpers ───────────────────────────────────────────────────
  function _tok() { return localStorage.getItem('lu_token') || ''; }
  function _esc(s){ var d = document.createElement('div'); d.textContent = (s==null?'':String(s)); return d.innerHTML; }
  function _apiBase(){ return (window.LU_CFG && window.LU_API_BASE) ? window.LU_API_BASE : '/api'; }
  function _fetchJson(url, opts){
    opts = opts || {};
    opts.headers = opts.headers || {};
    opts.headers['Authorization'] = 'Bearer ' + _tok();
    if (opts.body && !(opts.body instanceof FormData)) opts.headers['Content-Type'] = 'application/json';
    return fetch(_apiBase() + url, opts).then(function(r){
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json().catch(function(){ return {}; });
    });
  }
  function _fetchText(url, opts){
    opts = opts || {};
    opts.headers = opts.headers || {};
    opts.headers['Authorization'] = 'Bearer ' + _tok();
    return fetch(_apiBase() + url, opts).then(function(r){
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    });
  }
  function _postToIframe(msg){
    var ifr = document.getElementById('st-iframe');
    if (!ifr || !ifr.contentWindow) return;
    ifr.contentWindow.postMessage(msg, '*');
  }
  function _toast(m, t){ if (typeof showToast==='function') showToast(m, t||'info'); }

  // ── Inject CSS once ──────────────────────────────────────────
  (function injectCss(){
    if (document.getElementById('st-css')) return;
    var s = document.createElement('style');
    s.id = 'st-css';
    s.textContent =
      '.st-host{position:absolute;inset:0;background:#0F1117;color:#fff;font-family:var(--fb,system-ui,-apple-system,sans-serif);display:flex;flex-direction:column;overflow:hidden}' +
      '.st-topbar{height:54px;display:flex;align-items:center;gap:14px;padding:0 20px;border-bottom:1px solid rgba(255,255,255,0.08);flex-shrink:0}' +
      '.st-topbar button{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.15);color:#fff;padding:7px 14px;border-radius:6px;cursor:pointer;font:500 13px/1 inherit}' +
      '.st-topbar button:hover{background:rgba(255,255,255,0.09)}' +
      '.st-topbar button.primary{background:#6C5CE7;border-color:#6C5CE7}' +
      '.st-topbar button.primary:hover{background:#5B4FD4}' +
      '.st-topbar .title{font-weight:600;font-size:14px}' +
      '.st-topbar .spacer{flex:1}' +
      /* Gallery */
      '.st-gallery{flex:1;overflow:auto;padding:40px 60px}' +
      '.st-gallery h1{font-size:26px;font-weight:700;margin-bottom:6px}' +
      '.st-gallery .sub{color:rgba(255,255,255,0.55);font-size:13px;margin-bottom:28px}' +
      '.st-filters{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}' +
      '.st-filter{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#fff;padding:6px 14px;border-radius:16px;cursor:pointer;font-size:12px}' +
      '.st-filter.active{background:#6C5CE7;border-color:#6C5CE7}' +
      '#st-grid{display:grid;grid-template-columns:repeat(auto-fill,260px);gap:20px;justify-content:start}' +
      '.st-card{width:260px;background:#1a1a24;border:1px solid rgba(255,255,255,0.08);border-radius:10px;overflow:hidden;cursor:pointer;transition:transform .15s ease, border-color .15s ease}' +
      '.st-card:hover{transform:translateY(-2px);border-color:#6C5CE7}' +
      '.st-card-preview{position:relative;width:260px;height:260px;background:#050810;overflow:hidden}' +
      '.st-card-preview iframe{position:absolute;top:0;left:0;width:1080px;height:1080px;transform:scale(calc(260px / 1080px));transform-origin:top left;pointer-events:none;border:0}' +
      '.st-card-meta{padding:12px 14px}' +
      '.st-card-name{font-weight:600;font-size:13px;line-height:1.3}' +
      '.st-card-fmt{color:rgba(255,255,255,0.45);font-size:10px;text-transform:uppercase;letter-spacing:2px;margin-top:4px}' +
      /* Editor */
      '.st-main{flex:1;display:flex;overflow:hidden}' +
      '.st-tools{width:320px;background:#14161C;border-right:1px solid rgba(255,255,255,0.08);display:flex;flex-direction:column;flex-shrink:0}' +
      '.st-tabs{display:flex;border-bottom:1px solid rgba(255,255,255,0.08)}' +
      '.st-tab{flex:1;padding:12px 6px;text-align:center;color:rgba(255,255,255,0.65);cursor:pointer;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;border-bottom:2px solid transparent}' +
      '.st-tab:hover{color:#fff;background:rgba(255,255,255,0.03)}' +
      '.st-tab.active{color:#fff;border-bottom-color:#6C5CE7}' +
      '.st-tab-body{flex:1;overflow-y:auto;padding:16px}' +
      '.st-tab-head{font-size:10px;text-transform:uppercase;letter-spacing:3px;color:rgba(255,255,255,0.4);margin-bottom:12px}' +
      '.st-empty{padding:20px;text-align:center;color:rgba(255,255,255,0.4);font-size:12px}' +
      '.st-field{background:#1a1a24;border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:10px 12px;margin-bottom:8px;cursor:pointer;transition:border-color .12s}' +
      '.st-field:hover,.st-field.active{border-color:#6C5CE7}' +
      '.st-field-label{font-size:10px;text-transform:uppercase;letter-spacing:2px;color:rgba(255,255,255,0.5);margin-bottom:4px}' +
      '.st-field-val{font-size:13px;color:#fff;word-break:break-word;max-height:60px;overflow:hidden}' +
      '.st-img-field{display:flex;gap:10px;align-items:center}' +
      '.st-img-thumb{width:48px;height:48px;border-radius:6px;background:#2a2a35 no-repeat center/cover;flex-shrink:0;border:1px solid rgba(255,255,255,0.1)}' +
      '.st-img-name{font-size:12px;color:#fff;flex:1}' +
      '.st-pal-grid{display:flex;flex-direction:column;gap:8px}' +
      '.st-pal{display:flex;align-items:center;gap:4px;padding:10px 12px;background:#1a1a24;border:1px solid rgba(255,255,255,0.08);border-radius:8px;cursor:pointer}' +
      '.st-pal:hover{border-color:#6C5CE7}' +
      '.st-swatch{width:22px;height:22px;border-radius:50%;border:1px solid rgba(255,255,255,0.12)}' +
      '.st-pal-name{margin-left:auto;font-size:11px;color:rgba(255,255,255,0.85);letter-spacing:1px}' +
      '.st-btn-lg{display:block;width:100%;background:#6C5CE7;border:0;color:#fff;padding:14px;border-radius:8px;font:600 13px/1 inherit;cursor:pointer;margin-bottom:10px}' +
      '.st-btn-lg:hover{background:#5B4FD4}' +
      '.st-btn-lg.secondary{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12)}' +
      '.st-btn-lg.secondary:hover{background:rgba(255,255,255,0.10)}' +
      /* Canvas workspace (infinite pan + zoom) */
      '.st-canvas-wrap{flex:1;position:relative;overflow:hidden;background:#0D0D0F;background-image:radial-gradient(circle, rgba(255,255,255,0.08) 1px, transparent 1px);background-size:24px 24px;background-position:0 0;cursor:grab;user-select:none}' +
      '.st-canvas-wrap.panning{cursor:grabbing}' +
      '#st-canvas-transform{position:absolute;top:0;left:0;transform-origin:0 0;will-change:transform}' +
      '.st-canvas-frame{position:relative;background:#fff;box-shadow:0 20px 80px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.06);flex-shrink:0}' +
      '#st-iframe{display:block;border:0;background:#fff}' +
      /* Zoom controls — bottom-right of workspace */
      '.st-zoom{position:absolute;bottom:24px;right:24px;z-index:60;display:flex;align-items:center;gap:2px;background:rgba(20,22,30,0.88);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,0.12);border-radius:18px;padding:4px;font:500 12px/1 inherit;color:#fff}' +
      '.st-zoom button{background:transparent;border:0;color:#fff;width:30px;height:30px;cursor:pointer;border-radius:14px;font-size:15px;display:inline-flex;align-items:center;justify-content:center}' +
      '.st-zoom button:hover{background:rgba(255,255,255,0.08)}' +
      '.st-zoom-pct{min-width:52px;text-align:center;cursor:pointer;padding:6px 4px;border-radius:12px;color:rgba(255,255,255,0.85)}' +
      '.st-zoom-pct:hover{background:rgba(255,255,255,0.08)}' +
      '.st-zoom-fit{padding:0 12px !important;width:auto !important;gap:4px;font-size:12px;display:inline-flex !important;align-items:center !important}' +
      '#st-save-indicator{position:fixed;top:70px;right:20px;background:#00E5A8;color:#000;padding:6px 14px;border-radius:16px;font:600 11px/1 inherit;display:none;z-index:9001}' +
      /* Floating AI chat */
      '.st-chat{position:absolute;bottom:24px;left:50%;transform:translateX(-50%);width:600px;max-width:calc(100% - 60px);z-index:50;display:flex;flex-direction:column;gap:10px;pointer-events:none}' +
      '.st-chat *{pointer-events:auto}' +
      '.st-chat-msgs{display:flex;flex-direction:column;gap:8px;max-height:220px;overflow-y:auto;padding-right:4px}' +
      '.st-chat-msg{max-width:86%;padding:10px 14px;border-radius:14px;font:400 14px/1.5 inherit;white-space:pre-wrap;word-break:break-word;backdrop-filter:blur(12px)}' +
      '.st-chat-msg.user{align-self:flex-end;background:#6C5CE7;color:#fff;border-bottom-right-radius:4px}' +
      '.st-chat-msg.ai{align-self:flex-start;background:rgba(20,22,30,0.90);color:#fff;border:1px solid rgba(255,255,255,0.10);border-bottom-left-radius:4px}' +
      '.st-chat-msg.ai::before{content:"\\2726 ";color:#B0A4FF;font-weight:700}' +
      '.st-chat-msg.error{align-self:flex-start;background:rgba(60,10,10,0.92);border:1px solid rgba(255,80,80,0.35);color:#FFD1D1}' +
      '.st-chat-loading{align-self:flex-start;background:rgba(20,22,30,0.90);color:#B0A4FF;border:1px solid rgba(255,255,255,0.10);padding:10px 14px;border-radius:14px;font:400 14px/1.5 inherit;backdrop-filter:blur(12px)}' +
      '.st-chat-loading span{display:inline-block;animation:stDot 1.2s infinite}' +
      '.st-chat-loading span:nth-child(2){animation-delay:.18s}' +
      '.st-chat-loading span:nth-child(3){animation-delay:.36s}' +
      '@keyframes stDot{0%,60%,100%{opacity:.25}30%{opacity:1}}' +
      '.st-chat-quick{display:flex;flex-wrap:wrap;gap:6px}' +
      '.st-chat-quick-btn{background:rgba(20,22,30,0.85);border:1px solid rgba(255,255,255,0.12);color:rgba(255,255,255,0.85);font:500 12px/1 inherit;padding:8px 12px;border-radius:14px;cursor:pointer;backdrop-filter:blur(12px);transition:background .12s}' +
      '.st-chat-quick-btn:hover{background:rgba(108,92,231,0.25);border-color:rgba(108,92,231,0.5)}' +
      '.st-chat-box{background:rgba(15,15,20,0.85);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,0.12);border-radius:16px;padding:14px 16px;display:flex;align-items:flex-end;gap:10px;transition:border-color .15s}' +
      '.st-chat-box.error{border-color:rgba(255,80,80,0.45)}' +
      '.st-chat-box.focused{border-color:rgba(108,92,231,0.6)}' +
      '.st-chat-input{flex:1;background:transparent;color:#fff;border:0;outline:0;resize:none;font:400 15px/1.5 inherit;min-height:24px;max-height:72px;padding:0;width:100%}' +
      '.st-chat-input::placeholder{color:rgba(255,255,255,0.45)}' +
      '.st-chat-send{background:linear-gradient(180deg,#6C5CE7,#5A4BD1);border:0;color:#fff;font:600 13px/1 inherit;padding:10px 16px;border-radius:10px;cursor:pointer;display:none;align-items:center;gap:6px;flex-shrink:0}' +
      '.st-chat-send.visible{display:inline-flex}' +
      '.st-chat-send:hover{background:linear-gradient(180deg,#7B6BF0,#6358DE)}' +
      '.st-chat-send::after{content:"\\2192";font-size:16px;line-height:1}' +
      /* Themed modal dialogs (replaces native alert/confirm/prompt) — uses
         LevelUp app CSS vars so Studio dialogs match the rest of the UI. */
      '.st-dlg-overlay{position:fixed;inset:0;background:rgba(5,7,12,0.72);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:10000;display:flex;align-items:center;justify-content:center;font-family:var(--fb,"DM Sans",system-ui,sans-serif);animation:stDlgFade .12s ease-out}' +
      '@keyframes stDlgFade{from{opacity:0}to{opacity:1}}' +
      '@keyframes stDlgPop{from{transform:scale(0.96);opacity:0}to{transform:scale(1);opacity:1}}' +
      '.st-dlg{background:var(--s1,#171A21);border:1px solid var(--bd,rgba(255,255,255,.07));border-radius:12px;padding:24px;min-width:360px;max-width:520px;box-shadow:0 30px 80px rgba(0,0,0,0.65);color:var(--t1,#E8EDF5);animation:stDlgPop .14s ease-out}' +
      '.st-dlg-title{font-family:var(--fh,Syne,sans-serif);font-size:16px;font-weight:600;letter-spacing:-0.01em;color:var(--t1,#E8EDF5);margin-bottom:8px}' +
      '.st-dlg-msg{font-family:var(--fb,"DM Sans",system-ui,sans-serif);font-size:13px;line-height:1.55;color:var(--t2,#8B97B0);margin-bottom:16px;white-space:pre-wrap;word-break:break-word}' +
      '.st-dlg-input{display:block;width:100%;background:var(--s2,#1E2230);border:1px solid var(--bd2,rgba(255,255,255,.13));border-radius:8px;padding:10px 12px;color:var(--t1,#E8EDF5);font:500 14px/1.3 var(--fb,"DM Sans",system-ui,sans-serif);outline:0;margin-bottom:18px;transition:border-color .12s, background .12s;box-sizing:border-box}' +
      '.st-dlg-input:focus{border-color:var(--p,#6C5CE7);background:var(--ps,rgba(108,92,231,.1))}' +
      '.st-dlg-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}' +
      '.st-dlg-btn{background:var(--s2,#1E2230);border:1px solid var(--bd,rgba(255,255,255,.07));color:var(--t1,#E8EDF5);font:500 13px/1 var(--fb,"DM Sans",system-ui,sans-serif);padding:10px 18px;border-radius:8px;cursor:pointer;transition:background .12s, border-color .12s;min-width:88px}' +
      '.st-dlg-btn:hover{background:var(--s3,#252A3A);border-color:var(--bd2,rgba(255,255,255,.13))}' +
      '.st-dlg-btn.primary{background:var(--p,#6C5CE7);border-color:var(--p,#6C5CE7);color:#fff;font-weight:600;box-shadow:0 4px 14px var(--pg,rgba(108,92,231,.22))}' +
      '.st-dlg-btn.primary:hover{background:#7B6BF0;border-color:#7B6BF0}' +
      '.st-dlg-btn.danger{background:var(--rd,#F87171);border-color:var(--rd,#F87171);color:#fff;font-weight:600}' +
      '.st-dlg-btn.danger:hover{filter:brightness(1.1)}';
    document.head.appendChild(s);
  })();

  // ═══════════════════════════════════════════════════════════════
  // SCREEN 1 — GALLERY
  // ═══════════════════════════════════════════════════════════════
  // studio-phase1-gallery — full-width SPA gallery (v5.0.0)
  // Replaces the single-list template picker with a Canva-style landing:
  //   top bar + AI Create + My Designs + Templates (tab-categorized)
  function _mountGallery() {
    window.removeEventListener('message', _handleIframeMsg);
    var host = _getOrCreateHost();
    host.innerHTML =
      '<div class="st2-root" id="st2-root">' +
        '<div class="st2-topbar">' +
          '<div class="st2-title">Studio</div>' +
          '<div class="st2-create-wrap">' +
            '<button class="st2-btn-primary" id="st2-create-btn">+ Create new <span style="opacity:.7">\u25be</span></button>' +
            '<div class="st2-menu" id="st2-create-menu" style="display:none">' +
              '<button data-action="image" class="st2-menu-item">\u{1F5BC} Image design</button>' +
              '<button data-action="video" class="st2-menu-item">\u{1F3AC} Video</button>' +
              '<button data-action="ai" class="st2-menu-item">\u2726 Generate with AI</button>' +
            '</div>' +
          '</div>' +
          '<input class="st2-search" id="st2-search" placeholder="Search designs and templates..."/>' +
          '<div class="st2-seg" id="st2-filter">' +
            '<button class="active" data-f="all">All</button>' +
            '<button data-f="image">Images</button>' +
            '<button data-f="video">Videos</button>' +
            '<button data-f="templates">Templates</button>' +
          '</div>' +
          '<button class="st2-btn-ghost" onclick="_studioClose()">Close</button>' +
        '</div>' +

        '<div class="st2-ai-card">' +
          '<div class="st2-ai-head">' +
            '<span class="st2-ai-sparkle">\u2726</span>' +
            '<div>' +
              '<div class="st2-ai-title">Describe what you want to create</div>' +
              '<div class="st2-ai-sub">Arthur will pick a template and fill in the copy. Images generated on demand.</div>' +
            '</div>' +
          '</div>' +
          '<textarea class="st2-ai-prompt" id="st2-ai-prompt" rows="2" ' +
            'placeholder="e.g. Instagram post for a new fitness studio grand opening this Saturday"></textarea>' +
          '<div class="st2-ai-row">' +
            '<div class="st2-ai-formats" id="st2-ai-formats"></div>' +
            '<button class="st2-btn-primary" id="st2-ai-go">\u2726 Generate</button>' +
          '</div>' +
        '</div>' +

        '<div class="st2-section" id="st2-my-designs-section">' +
          '<div class="st2-section-head">' +
            '<h2>My designs</h2>' +
            '<span class="st2-section-count" id="st2-my-count">\u2014</span>' +
          '</div>' +
          '<div class="st2-grid" id="st2-my-grid"><div class="st2-empty">Loading\u2026</div></div>' +
        '</div>' +

        '<div class="st2-section" id="st2-tpl-section">' +
          '<div class="st2-section-head">' +
            '<h2>Templates</h2>' +
            '<div class="st2-tpl-tabs" id="st2-tpl-tabs"></div>' +
          '</div>' +
          '<div class="st2-grid" id="st2-tpl-grid"><div class="st2-empty">Loading templates\u2026</div></div>' +
        '</div>' +
      '</div>';

    _st2WireTopbar();
    _st2WireAiFormats();
    _st2LoadDesigns();
    _st2LoadTemplates();
  }

  // ── Top bar wiring ─────────────────────────────────────────
  function _st2WireTopbar(){
    var createBtn = document.getElementById('st2-create-btn');
    var menu      = document.getElementById('st2-create-menu');
    createBtn.onclick = function(e){
      e.stopPropagation();
      menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
    };
    document.addEventListener('click', function(){ menu.style.display = 'none'; });
    menu.querySelectorAll('.st2-menu-item').forEach(function(b){
      b.onclick = function(){
        menu.style.display = 'none';
        var act = b.getAttribute('data-action');
        if (act === 'image') _st2OpenFormatPicker('image');
        else if (act === 'video') _studioOpenVideo();
        else if (act === 'ai') document.getElementById('st2-ai-prompt').focus();
      };
    });
    var filter = document.getElementById('st2-filter');
    filter.querySelectorAll('button').forEach(function(b){
      b.onclick = function(){
        filter.querySelectorAll('button').forEach(function(x){ x.classList.remove('active'); });
        b.classList.add('active');
        _st2ApplyFilter(b.getAttribute('data-f'));
      };
    });
    var search = document.getElementById('st2-search');
    var dt;
    search.oninput = function(){
      clearTimeout(dt);
      dt = setTimeout(function(){ _st2ApplyFilter(document.querySelector('#st2-filter .active').getAttribute('data-f')); }, 200);
    };
    document.getElementById('st2-ai-go').onclick = _st2RunAi;
  }

  // ── AI format chips ────────────────────────────────────────
  var _st2Formats = [
    { slug:'square',    label:'Square',    w:1080, h:1080 },
    { slug:'portrait',  label:'Portrait',  w:1080, h:1350 },
    { slug:'story',     label:'Story',     w:1080, h:1920 },
    { slug:'landscape', label:'Landscape', w:1920, h:1080 },
  ];
  var _st2SelectedFormat = 'square';

  function _st2WireAiFormats(){
    var holder = document.getElementById('st2-ai-formats');
    holder.innerHTML = _st2Formats.map(function(f){
      return '<button class="st2-fmt-chip' + (f.slug===_st2SelectedFormat?' active':'') + '" data-fmt="' + f.slug + '">' + f.label + '</button>';
    }).join('');
    holder.querySelectorAll('button').forEach(function(b){
      b.onclick = function(){
        holder.querySelectorAll('button').forEach(function(x){ x.classList.remove('active'); });
        b.classList.add('active');
        _st2SelectedFormat = b.getAttribute('data-fmt');
      };
    });
  }

  // ── AI generate ────────────────────────────────────────────
  async function _st2RunAi(){
    var prompt = (document.getElementById('st2-ai-prompt').value || '').trim();
    if (!prompt) { _toast('Describe what you want to create first.', 'warning'); return; }
    var btn = document.getElementById('st2-ai-go');
    btn.disabled = true; btn.textContent = '\u2726 Arthur is designing...';
    try {
      var fmt = _st2Formats.find(function(f){ return f.slug === _st2SelectedFormat; }) || _st2Formats[0];
      var d = await _fetchJson('/studio/ai/generate-design', {
        method: 'POST',
        body: JSON.stringify({
          prompt: prompt,
          format: fmt.slug,
          canvas_width: fmt.w, canvas_height: fmt.h,
          style: 'bold',
        })
      });
      if (!d.success) {
        if (d.error === 'api_key_missing') _toast('AI needs DEEPSEEK_API_KEY in .env', 'error');
        else _toast('Arthur failed: ' + (d.message || d.error || 'unknown'), 'error');
        return;
      }
      _toast('\u2726 Arthur picked ' + d.template_slug + '. Opening...', 'success');
      _designId = d.design_id; _designName = prompt.substring(0, 60);
      _mountEditor();
    } catch(e){
      _toast('Failed: ' + e.message, 'error');
    } finally {
      btn.disabled = false; btn.textContent = '\u2726 Generate';
    }
  }

  // ── Format picker for "New image design" ────────────────────
  function _st2OpenFormatPicker(kind){
    var bd = document.createElement('div');
    bd.className = 'modal-backdrop';
    bd.onclick = function(e){ if (e.target === bd) bd.remove(); };
    bd.innerHTML =
      '<div class="modal" style="max-width:520px">' +
        '<div class="modal-header"><h3>New ' + _esc(kind) + ' design</h3><button class="modal-close" onclick="this.closest(\'.modal-backdrop\').remove()">\u2715</button></div>' +
        '<div class="modal-body">' +
          '<div class="form-group"><label class="form-label">Name</label><input class="form-input" id="st2-new-name" placeholder="Untitled design"></div>' +
          '<div class="form-group"><label class="form-label">Format</label>' +
            '<div class="st2-fmt-grid" id="st2-new-fmts">' +
              _st2Formats.map(function(f, i){
                return '<label class="st2-fmt-tile' + (i===0?' active':'') + '"><input type="radio" name="st2fmt" value="' + f.slug + '"' + (i===0?' checked':'') + '><div class="st2-fmt-preview" style="aspect-ratio:' + f.w + '/' + f.h + '"></div><div class="st2-fmt-label">' + f.label + '<span>' + f.w + '\u00d7' + f.h + '</span></div></label>';
              }).join('') +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="modal-footer">' +
          '<button class="btn btn-outline" onclick="this.closest(\'.modal-backdrop\').remove()">Cancel</button>' +
          '<button class="btn btn-primary" id="st2-new-go">Create</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(bd);
    // Wire tile selection visual
    bd.querySelectorAll('.st2-fmt-tile').forEach(function(t){
      t.onclick = function(){
        bd.querySelectorAll('.st2-fmt-tile').forEach(function(x){ x.classList.remove('active'); });
        t.classList.add('active');
      };
    });
    bd.querySelector('#st2-new-go').onclick = async function(){
      var name = bd.querySelector('#st2-new-name').value.trim() || 'Untitled design';
      var fmtSlug = bd.querySelector('input[name="st2fmt"]:checked').value;
      var fmt = _st2Formats.find(function(f){ return f.slug === fmtSlug; });
      try {
        var d = await _fetchJson('/studio/designs', {
          method:'POST',
          body: JSON.stringify({ name: name, format: fmt.slug, canvas_width: fmt.w, canvas_height: fmt.h, design_type:'image' })
        });
        var id = d.design_id || (d.design && d.design.id);
        bd.remove();
        _designId = id; _designName = name;
        _mountEditor();
      } catch(e){ _toast(e.message, 'error'); }
    };
  }

  // ── Load my designs ────────────────────────────────────────
  async function _st2LoadDesigns(){
    var holder = document.getElementById('st2-my-grid');
    var count  = document.getElementById('st2-my-count');
    try {
      var d = await _fetchJson('/studio/designs');
      var designs = (d.designs || []);
      window._st2Designs = designs;
      count.textContent = designs.length;
      if (!designs.length) {
        holder.innerHTML = '<div class="st2-empty">No designs yet. Create your first one with [+ Create new] above.</div>';
      } else {
        holder.innerHTML = designs.map(_st2DesignCard).join('');
        holder.querySelectorAll('.st2-card').forEach(function(c){
          c.onclick = function(ev){
            if (ev.target.closest('.st2-dots')) return; // let menu handle
            var id = c.getAttribute('data-id');
            _designId = Number(id);
            _designName = c.getAttribute('data-name');
            _mountEditor();
          };
        });
        holder.querySelectorAll('.st2-dots').forEach(function(dots){
          dots.onclick = function(ev){
            ev.stopPropagation();
            _st2OpenDesignMenu(dots);
          };
        });
      }
    } catch(e){
      holder.innerHTML = '<div class="st2-empty">Failed to load designs: ' + _esc(e.message) + '</div>';
    }
  }

  function _st2DesignCard(d){
    var initial = ((d.name || 'D').trim().charAt(0) || 'D').toUpperCase();
    var bgHue   = ((d.id || 0) * 47) % 360;
    var thumb = d.thumbnail_url
      ? '<img src="' + _esc(d.thumbnail_url) + '" alt="" style="width:100%;height:100%;object-fit:cover"/>'
      : '<div class="st2-thumb-fallback" style="background:linear-gradient(135deg,hsl(' + bgHue + ',55%,32%),hsl(' + ((bgHue+40)%360) + ',55%,18%));color:#fff;font-size:48px;font-weight:800;letter-spacing:0;text-transform:none;font-family:Syne,Inter,sans-serif">' + _esc(initial) + '</div>';
    var updated = d.updated_at ? (new Date(d.updated_at)).toLocaleDateString() : '';
    return '<div class="st2-card" data-id="' + d.id + '" data-name="' + _esc(d.name || 'Untitled') + '" data-type="' + _esc(d.design_type || 'image') + '">' +
             '<div class="st2-thumb">' + thumb +
               '<div class="st2-badge">' + _esc((d.format || '').toUpperCase()) + '</div>' +
             '</div>' +
             '<div class="st2-card-meta">' +
               '<div class="st2-card-name" title="' + _esc(d.name || '') + '">' + _esc(d.name || 'Untitled') + '</div>' +
               '<div class="st2-card-sub">' + _esc(updated) + '</div>' +
             '</div>' +
             '<button class="st2-dots" title="More">\u22ef</button>' +
           '</div>';
  }

  function _st2OpenDesignMenu(btn){
    // Remove any open menu first
    var existing = document.querySelector('.st2-popup'); if (existing) existing.remove();
    var card = btn.closest('.st2-card');
    var id   = Number(card.getAttribute('data-id'));
    var name = card.getAttribute('data-name');
    var pop = document.createElement('div');
    pop.className = 'st2-popup';
    var r = btn.getBoundingClientRect();
    pop.style.top  = (r.bottom + 4 + window.scrollY) + 'px';
    pop.style.left = (r.right - 140 + window.scrollX) + 'px';
    pop.innerHTML =
      '<button data-act="edit">Edit</button>' +
      '<button data-act="duplicate">Duplicate</button>' +
      '<button data-act="download">Download</button>' +
      '<button data-act="publish">Publish to Social</button>' +
      '<button data-act="delete" class="danger">Delete</button>';
    document.body.appendChild(pop);
    setTimeout(function(){
      document.addEventListener('click', function close(){ pop.remove(); document.removeEventListener('click', close); }, { once: true });
    }, 10);
    pop.querySelectorAll('button').forEach(function(b){
      b.onclick = function(e){
        e.stopPropagation();
        var act = b.getAttribute('data-act');
        pop.remove();
        _st2DesignAction(id, name, act);
      };
    });
  }

  async function _st2DesignAction(id, name, act){
    if (act === 'edit'){ _designId = id; _designName = name; _mountEditor(); return; }
    if (act === 'duplicate'){
      try { await _fetchJson('/studio/designs/' + id + '/duplicate', { method:'POST' });
            _toast('Duplicated', 'success'); _st2LoadDesigns(); }
      catch(e){ _toast(e.message, 'error'); } return;
    }
    if (act === 'delete'){
      var ok = await (window.luConfirm ? window.luConfirm('Delete "' + name + '"?', 'Delete design', 'Delete', 'Cancel') : Promise.resolve(confirm('Delete "' + name + '"?')));
      if (!ok) return;
      try { await _fetchJson('/studio/designs/' + id, { method:'DELETE' }); _toast('Deleted'); _st2LoadDesigns(); }
      catch(e){ _toast(e.message, 'error'); } return;
    }
    if (act === 'download'){
      // Download goes through the existing export route (kept intact)
      window.open('/api/studio/designs/' + id + '/render-png', '_blank');
      return;
    }
    if (act === 'publish'){
      _st2OpenPublishModal(id, name);
      return;
    }
  }

  // ── Load + render templates, tabbed by category ─────────────
  async function _st2LoadTemplates(){
    var holder = document.getElementById('st2-tpl-grid');
    var tabsHolder = document.getElementById('st2-tpl-tabs');
    try {
      var results = await Promise.all([
        _fetchJson('/studio/templates/html').catch(function(){ return { templates: [] }; }),
        _fetchJson('/studio/video/templates').catch(function(){ return { templates: [] }; }),
      ]);
      var imgTpls = (results[0].templates || []).map(function(t){ return Object.assign({}, t, { kind: 'image' }); });
      var vidTpls = (results[1].templates || []).map(function(t){
        // Video template shape differs — normalize so the grid can render
        return {
          slug:         t.slug || ('video-' + (t.id || '')),
          name:         t.name || 'Video template',
          format:       t.canvas_width && t.canvas_height && t.canvas_width < t.canvas_height ? 'reels' : 'video',
          category:     t.category || 'video',
          canvas_width: t.canvas_width  || 1080,
          canvas_height:t.canvas_height || 1920,
          preview_url:  t.thumbnail_url || t.preview_url || '',
          template_type: t.template_type || 'clip_json',
          id:           t.id,
          kind:         'video',
        };
      });
      var tpls = imgTpls.concat(vidTpls);
      window._st2Templates = tpls;
      // Derive category list
      var cats = { all: tpls.length };
      tpls.forEach(function(t){ var c = t.category || 'uncategorized'; cats[c] = (cats[c]||0) + 1; });
      var order = ['all','social','marketing','real-estate','food','fashion','fitness','corporate'];
      var extra = Object.keys(cats).filter(function(k){ return order.indexOf(k) < 0; });
      var display = order.concat(extra);
      tabsHolder.innerHTML = display.filter(function(k){ return cats[k]; }).map(function(k, i){
        return '<button class="st2-tpl-tab' + (i===0?' active':'') + '" data-cat="' + _esc(k) + '">' + _esc(k) + ' <span>' + cats[k] + '</span></button>';
      }).join('');
      tabsHolder.querySelectorAll('button').forEach(function(b){
        b.onclick = function(){
          tabsHolder.querySelectorAll('button').forEach(function(x){ x.classList.remove('active'); });
          b.classList.add('active');
          _st2RenderTemplateGrid(b.getAttribute('data-cat'));
        };
      });
      _st2RenderTemplateGrid('all');
    } catch(e){
      holder.innerHTML = '<div class="st2-empty">Failed to load templates: ' + _esc(e.message) + '</div>';
    }
  }

  function _st2CurrentKind(){
    var a = document.querySelector('#st2-filter .active');
    if (!a) return null;
    var f = a.getAttribute('data-f');
    return (f === 'image') ? 'image' : (f === 'video') ? 'video' : null;
  }
  function _st2RenderTemplateGrid(category, kind){
    if (kind === undefined) kind = _st2CurrentKind();
    var holder = document.getElementById('st2-tpl-grid');
    var tpls = window._st2Templates || [];
    if (kind === 'image') tpls = tpls.filter(function(t){ return t.kind === 'image'; });
    if (kind === 'video') tpls = tpls.filter(function(t){ return t.kind === 'video'; });
    if (category && category !== 'all') tpls = tpls.filter(function(t){ return (t.category || 'uncategorized') === category; });
    if (!tpls.length) { holder.innerHTML = '<div class="st2-empty">No templates in this category.</div>'; return; }
    holder.innerHTML = tpls.map(function(t){
      return '<div class="st2-card st2-tpl-card" data-slug="' + _esc(t.slug) + '" data-name="' + _esc(t.name) + '">' +
               '<div class="st2-thumb">' +
                 (t.kind === 'video'
                   ? '<img src="' + _esc(t.preview_url) + '" alt="" style="width:100%;height:100%;object-fit:cover" loading="lazy"/>'
                   : '<iframe src="' + _esc(t.preview_url) + '" scrolling="no" loading="lazy"></iframe>') +
                 '<div class="st2-badge">' + _esc((t.format || '').toUpperCase()) + '</div>' +
               '</div>' +
               '<div class="st2-card-meta">' +
                 '<div class="st2-card-name">' + _esc(t.name) + '</div>' +
                 '<div class="st2-card-sub">' + _esc(t.category || '') + (t.sub_category ? ' \u00b7 ' + _esc(t.sub_category) : '') + '</div>' +
               '</div>' +
               '<button class="st2-use-btn">Use template</button>' +
             '</div>';
    }).join('');
    holder.querySelectorAll('.st2-tpl-card').forEach(function(c){
      c.onclick = function(){
        var slug = c.getAttribute('data-slug'); var name = c.getAttribute('data-name');
        window._studioUseTemplate(slug, name);
      };
    });
  }

  // ── Filter bar (All / Images / Videos / Templates) ──────────
  function _st2ApplyFilter(which){
    var q = (document.getElementById('st2-search').value || '').toLowerCase();
    var mySec  = document.getElementById('st2-my-designs-section');
    var tplSec = document.getElementById('st2-tpl-section');

    // Templates-only mode — my-designs hidden
    if (which === 'templates'){
      mySec.style.display = 'none'; tplSec.style.display = '';
      _st2RenderTemplateGrid('all', null);
      return;
    }

    mySec.style.display = ''; tplSec.style.display = '';

    // Determine the type filter for BOTH sections
    var kind = null;
    if (which === 'image') kind = 'image';
    else if (which === 'video') kind = 'video';

    // Filter my-designs by explicit design_type + search query
    var all = window._st2Designs || [];
    var designs = all.filter(function(d){
      var dt = (d.design_type || 'image').toLowerCase();
      var typeOk = (kind === 'image') ? (dt === 'image') :
                   (kind === 'video') ? (dt === 'video') :
                   true;
      var qOk = !q || (d.name || '').toLowerCase().indexOf(q) >= 0;
      return typeOk && qOk;
    });
    var holder = document.getElementById('st2-my-grid');
    holder.innerHTML = designs.length ? designs.map(_st2DesignCard).join('') : '<div class="st2-empty">No matching designs.</div>';
    holder.querySelectorAll('.st2-card').forEach(function(c){
      c.onclick = function(ev){
        if (ev.target.closest('.st2-dots')) return;
        _designId = Number(c.getAttribute('data-id'));
        _designName = c.getAttribute('data-name');
        _mountEditor();
      };
    });

    // Re-render templates section using the same kind filter
    _st2RenderTemplateGrid('all', kind);
  }

  // ── CSS for the new gallery ────────────────────────────────
  (function injectGalleryCss(){
    if (document.getElementById('st2-styles')) return;
    var s = document.createElement('style');
    s.id = 'st2-styles';
    s.textContent = [
      '.st2-root{padding:24px 32px 48px;max-width:1400px;margin:0 auto;color:var(--t1,#F1F5F9);width:100%;height:100%;overflow-y:auto;overflow-x:hidden;box-sizing:border-box}',
      '.st2-topbar{display:flex;align-items:center;gap:14px;margin-bottom:24px;flex-wrap:wrap}',
      '.st2-title{font-family:Syne,Inter,sans-serif;font-size:28px;font-weight:800;letter-spacing:-0.5px}',
      '.st2-create-wrap{position:relative}',
      '.st2-btn-primary{background:var(--p,#6C5CE7);border:none;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}',
      '.st2-btn-primary:hover:not(:disabled){background:var(--p-dark,#5849d3)}',
      '.st2-btn-primary:disabled{opacity:.6;cursor:default}',
      '.st2-btn-ghost{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:7px 14px;border-radius:8px;font-size:13px;cursor:pointer;font-family:inherit}',
      '.st2-btn-ghost:hover{background:var(--s2,#171b23)}',
      '.st2-menu{position:absolute;top:calc(100% + 6px);left:0;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:8px;min-width:220px;padding:4px;z-index:100;box-shadow:0 8px 28px rgba(0,0,0,.4)}',
      '.st2-menu-item{display:block;width:100%;text-align:left;background:transparent;border:none;color:var(--t1,#F1F5F9);padding:9px 12px;font-size:13px;border-radius:5px;cursor:pointer;font-family:inherit}',
      '.st2-menu-item:hover{background:var(--s3,#1e2230)}',
      '.st2-search{flex:1;min-width:240px;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:8px;color:var(--t1,#F1F5F9);padding:8px 12px;font-size:13px;font-family:inherit;outline:none}',
      '.st2-search:focus{border-color:var(--p,#6C5CE7)}',
      '.st2-seg{display:flex;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:8px;padding:2px}',
      '.st2-seg button{background:transparent;border:none;color:var(--t3,#64748B);padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;border-radius:6px;font-family:inherit}',
      '.st2-seg button.active{background:var(--p,#6C5CE7);color:#fff}',

      '.st2-ai-card{background:linear-gradient(135deg,rgba(108,92,231,.15),rgba(167,139,250,.08));border:1px solid rgba(108,92,231,.35);border-radius:16px;padding:22px 26px;margin-bottom:32px}',
      '.st2-ai-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:14px}',
      '.st2-ai-sparkle{font-size:28px;line-height:1;color:var(--ac,#A78BFA)}',
      '.st2-ai-title{font-size:16px;font-weight:700;color:var(--t1,#F1F5F9);margin-bottom:2px}',
      '.st2-ai-sub{font-size:12px;color:var(--t3,#64748B)}',
      '.st2-ai-prompt{width:100%;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:8px;color:var(--t1,#F1F5F9);padding:10px 12px;font-size:14px;font-family:inherit;resize:none;outline:none;box-sizing:border-box}',
      '.st2-ai-prompt:focus{border-color:var(--p,#6C5CE7)}',
      '.st2-ai-row{display:flex;align-items:center;justify-content:space-between;margin-top:10px;gap:12px}',
      '.st2-ai-formats{display:flex;gap:6px;flex-wrap:wrap}',
      '.st2-fmt-chip{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t3,#64748B);padding:5px 12px;border-radius:20px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit}',
      '.st2-fmt-chip.active{border-color:var(--p,#6C5CE7);color:var(--p,#6C5CE7);background:rgba(108,92,231,.08)}',

      '.st2-section{margin-bottom:40px}',
      '.st2-section-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:14px;gap:14px;flex-wrap:wrap}',
      '.st2-section-head h2{font-family:Syne,Inter,sans-serif;font-size:20px;font-weight:700;margin:0;color:var(--t1,#F1F5F9)}',
      '.st2-section-count{font-size:12px;color:var(--t3,#64748B);font-weight:500}',
      '.st2-tpl-tabs{display:flex;gap:4px;flex-wrap:wrap}',
      '.st2-tpl-tab{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t3,#64748B);padding:5px 12px;border-radius:20px;font-size:11px;font-weight:600;cursor:pointer;text-transform:capitalize;font-family:inherit}',
      '.st2-tpl-tab.active{border-color:var(--p,#6C5CE7);color:var(--p,#6C5CE7);background:rgba(108,92,231,.08)}',
      '.st2-tpl-tab span{opacity:.6;margin-left:3px}',

      '.st2-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:18px}',
      '.st2-card{position:relative;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:12px;overflow:hidden;cursor:pointer;transition:all .15s}',
      '.st2-card:hover{border-color:var(--p,#6C5CE7);transform:translateY(-2px);box-shadow:0 12px 28px rgba(0,0,0,.3)}',
      '.st2-thumb{aspect-ratio:1/1;background:var(--s3,#1e2230);position:relative;overflow:hidden}',
      '.st2-thumb iframe{border:0;width:100%;height:100%;transform:scale(.4);transform-origin:top left;width:250%;height:250%;pointer-events:none}',
      '.st2-thumb-fallback{display:flex;align-items:center;justify-content:center;height:100%;color:var(--t3,#64748B);font-size:11px;text-transform:uppercase;letter-spacing:.1em}',
      '.st2-badge{position:absolute;top:8px;left:8px;background:rgba(0,0,0,.7);color:#fff;font-size:9px;padding:2px 6px;border-radius:4px;font-weight:700;letter-spacing:.06em}',
      '.st2-card-meta{padding:10px 12px}',
      '.st2-card-name{font-size:13px;font-weight:600;color:var(--t1,#F1F5F9);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.st2-card-sub{font-size:11px;color:var(--t3,#64748B);margin-top:2px;text-transform:capitalize}',
      '.st2-use-btn{position:absolute;top:8px;right:8px;background:var(--p,#6C5CE7);color:#fff;border:none;padding:5px 10px;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;opacity:0;transition:opacity .15s;font-family:inherit}',
      '.st2-card:hover .st2-use-btn{opacity:1}',
      '.st2-dots{position:absolute;top:6px;right:6px;background:rgba(0,0,0,.6);border:none;color:#fff;width:26px;height:26px;border-radius:50%;font-size:16px;line-height:1;cursor:pointer;opacity:0;transition:opacity .15s;font-family:inherit}',
      '.st2-card:hover .st2-dots{opacity:1}',
      '.st2-dots:hover{background:rgba(0,0,0,.9)}',
      '.st2-empty{grid-column:1 / -1;padding:32px;text-align:center;color:var(--t3,#64748B);font-size:13px}',
      '.st2-popup{position:absolute;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:8px;padding:4px;min-width:160px;z-index:200;box-shadow:0 12px 28px rgba(0,0,0,.4)}',
      '.st2-popup button{display:block;width:100%;text-align:left;background:transparent;border:none;color:var(--t1,#F1F5F9);padding:7px 12px;font-size:12px;border-radius:5px;cursor:pointer;font-family:inherit}',
      '.st2-popup button:hover{background:var(--s3,#1e2230)}',
      '.st2-popup button.danger{color:#EF4444}',
      '.st2-fmt-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px}',
      '.st2-fmt-tile{display:block;cursor:pointer;border:1px solid var(--bd,#2a2f3a);border-radius:8px;padding:10px;text-align:center;transition:all .15s}',
      '.st2-fmt-tile.active,.st2-fmt-tile:has(input:checked){border-color:var(--p,#6C5CE7);background:rgba(108,92,231,.06)}',
      '.st2-fmt-tile input{display:none}',
      '.st2-fmt-preview{background:var(--s2,#171b23);border-radius:4px;margin-bottom:8px;max-height:100px;border:1px solid var(--bd,#2a2f3a)}',
      '.st2-fmt-label{font-size:12px;font-weight:600;color:var(--t1,#F1F5F9)}',
      '.st2-fmt-label span{display:block;font-size:10px;color:var(--t3,#64748B);margin-top:2px;font-weight:400}',
    ].join('\n');
    document.head.appendChild(s);
  })();

  window._studioUseTemplate = function(slug, name) {
    _fetchJson('/studio/designs', {
      method: 'POST',
      body: JSON.stringify({ template_slug: slug, name: name })
    }).then(function(d){
      if (!d.success) throw new Error(d.error || 'create_failed');
      _designId   = d.design_id;
      _designName = name;
      _mountEditor();
    }).catch(function(err){
      _toast('Failed to create design: ' + err.message, 'error');
    });
  };

  // Hand-off to the video editor (loads studio-video.js lazily, then
  // lets it replace the Studio host with its own gallery).
  window._studioOpenVideo = function() {
    var rootEl = _rootEl || document.getElementById('studio-root');
    // Remove the image-studio host so the video module's host can take over.
    var host = document.getElementById('st-host');
    if (host && host.parentNode) host.parentNode.removeChild(host);
    function go(){
      if (typeof window.studioVideoLoad === 'function') { window.studioVideoLoad(rootEl); return; }
      setTimeout(go, 80);
    }
    if (typeof window.studioVideoLoad === 'function') { go(); return; }
    var s = document.createElement('script');
    var bust = (window.LU_CFG && window.LU_CFG.version) || Date.now();
    s.src = '/app/js/studio-video.js?v=' + bust;
    s.onload = go;
    s.onerror = function(){ if (typeof showToast==='function') showToast('Failed to load video editor','error'); };
    document.head.appendChild(s);
  };

  window._studioClose = function() {
    var parent = _rootEl || document.getElementById('studio-root') || document.body;
    var host = parent.querySelector('#st-host');
    if (host) host.parentNode.removeChild(host);
    window.removeEventListener('message', _handleIframeMsg);
    // Return to dashboard view.
    if (typeof window.nav === 'function') window.nav('dashboard');
  };

  // ═══════════════════════════════════════════════════════════════
  // SCREEN 2 — EDITOR
  // ═══════════════════════════════════════════════════════════════
  function _mountEditor() {
    var host = _getOrCreateHost();
    host.innerHTML =
      '<div class="st-topbar">' +
        '<button onclick="_studioBack()">\u2190 Back</button>' +
        '<span class="title" id="st-design-name">' + _esc(_designName) + '</span>' +
        '<span class="spacer"></span>' +
        '<button onclick="_studioSave()">\u{1F4BE} Save</button>' +
        '<button class="primary" onclick="_studioExport()">\u2B07 Export PNG</button>' +
      '</div>' +
      '<div class="st-main">' +
        '<div class="st-tools">' +
          '<div class="st-tabs">' +
            '<div class="st-tab active" data-tab="content" onclick="_studioSwitchTab(\'content\')">Content</div>' +
            '<div class="st-tab" data-tab="images" onclick="_studioSwitchTab(\'images\')">Images</div>' +
            '<div class="st-tab" data-tab="colors" onclick="_studioSwitchTab(\'colors\')">Colors</div>' +
            '<div class="st-tab" data-tab="export" onclick="_studioSwitchTab(\'export\')">Export</div>' +
          '</div>' +
          '<div class="st-tab-body" id="st-tab-body"><div class="st-empty">Loading\u2026</div></div>' +
        '</div>' +
        '<div class="st-canvas-wrap" id="st-workspace">' +
          '<div id="st-canvas-transform">' +
            '<div class="st-canvas-frame" id="st-canvas-frame">' +
              '<iframe id="st-iframe" onload="_studioIframeReady(this)"></iframe>' +
            '</div>' +
          '</div>' +
          '<div class="st-chat" id="st-chat">' +
            '<div class="st-chat-msgs" id="st-chat-msgs" style="display:none"></div>' +
            '<div class="st-chat-quick" id="st-chat-quick">' +
              '<button class="st-chat-quick-btn" data-msg="Apply my brand colors and make the design feel on-brand.">\u2726 Apply my brand</button>' +
              '<button class="st-chat-quick-btn" data-msg="Rewrite the copy to be more punchy and engaging.">\u2726 Rewrite copy</button>' +
              '<button class="st-chat-quick-btn" data-msg="Make this design look more luxury — premium gold and dark tones.">\u2726 Make it luxury</button>' +
              '<button class="st-chat-quick-btn" data-msg="Change the color palette — surprise me.">\u2726 Change colors</button>' +
            '</div>' +
            '<div class="st-chat-box" id="st-chat-box">' +
              '<textarea class="st-chat-input" id="st-chat-input" rows="1" placeholder="Ask AI to edit your design..."></textarea>' +
              '<button class="st-chat-send" id="st-chat-send" type="button">Send</button>' +
            '</div>' +
          '</div>' +
          '<div class="st-zoom" id="st-zoom">' +
            '<button title="Zoom out" onclick="_studioZoomBy(-0.1)">\u2212</button>' +
            '<span class="st-zoom-pct" id="st-zoom-pct" onclick="_studioZoomPrompt()">100%</span>' +
            '<button title="Zoom in" onclick="_studioZoomBy(0.1)">+</button>' +
            '<button class="st-zoom-fit" title="Fit to viewport" onclick="_studioFit()">\u229E Fit</button>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div id="st-save-indicator">\u2713 Saved</div>';

    window.addEventListener('message', _handleIframeMsg);

    // Preview route is behind the auth.jwt middleware, so an <iframe src=…>
    // would hit it with no Bearer header and get 401. Fetch with the token,
    // then inject via srcdoc — same-origin iframe, postMessage still works.
    _fetchText('/studio/designs/' + _designId + '/preview')
      .then(function(html){
        var ifr = document.getElementById('st-iframe');
        if (ifr) ifr.srcdoc = html;
      })
      .catch(function(err){
        _toast('Could not load preview: ' + err.message, 'error');
        var ifr = document.getElementById('st-iframe');
        if (ifr) ifr.srcdoc = '<html><body style="background:#1a1a1a;color:#fff;font-family:system-ui;padding:40px">Could not load preview: ' + _esc(err.message) + '</body></html>';
      });
  }

  // ── Pan + zoom state ───────────────────────────────────────
  var _zoom = 1;
  var _panX = 0;
  var _panY = 0;
  var _cw = 1080;      // template canvas width
  var _ch = 1080;      // template canvas height
  var _isPanning = false;
  var _panStart = null;

  window._studioIframeReady = function(iframe) {
    _measureAndFit();
    window.addEventListener('resize', _measureAndFit);
    _wireWorkspaceEvents();
    _wireChat();
    // Ask iframe for its field list so the Content tab can populate.
    setTimeout(function(){ _postToIframe({ type: 'list-fields' }); }, 200);
  };

  function _measureAndFit() {
    var iframe = document.getElementById('st-iframe');
    if (!iframe) return;
    var cw = 1080, ch = 1080;
    try {
      var doc = iframe.contentDocument;
      var el = doc && (doc.querySelector('.canvas') || doc.body);
      if (el) { cw = el.offsetWidth || cw; ch = el.offsetHeight || ch; }
    } catch(_e) {}
    _cw = cw; _ch = ch;
    iframe.style.width  = cw + 'px';
    iframe.style.height = ch + 'px';
    var frame = document.getElementById('st-canvas-frame');
    if (frame) { frame.style.width = cw + 'px'; frame.style.height = ch + 'px'; }
    _fitToViewport();
  }

  function _fitToViewport() {
    var w = document.getElementById('st-workspace');
    if (!w) return;
    var ww = Math.max(200, w.clientWidth  - 120);
    var wh = Math.max(200, w.clientHeight - 120);
    _zoom = Math.min(ww / _cw, wh / _ch, 1);
    _panX = (w.clientWidth  - _cw * _zoom) / 2;
    _panY = (w.clientHeight - _ch * _zoom) / 2;
    _applyTransform();
  }

  function _applyTransform() {
    var t = document.getElementById('st-canvas-transform');
    if (t) t.style.transform = 'translate(' + _panX + 'px, ' + _panY + 'px) scale(' + _zoom + ')';
    var pct = document.getElementById('st-zoom-pct');
    if (pct) pct.textContent = Math.round(_zoom * 100) + '%';
  }

  // Zoom toward a workspace-local point so content under the cursor stays put.
  function _zoomAtPoint(mx, my, delta) {
    var oldZoom = _zoom;
    _zoom = Math.min(2, Math.max(0.1, +( _zoom + delta ).toFixed(4)));
    if (_zoom === oldZoom) return;
    var k = _zoom / oldZoom;
    _panX = mx - (mx - _panX) * k;
    _panY = my - (my - _panY) * k;
    _applyTransform();
  }

  function _wireWorkspaceEvents() {
    var w = document.getElementById('st-workspace');
    if (!w || w._studioWired) return;
    w._studioWired = true;

    // Mouse wheel → zoom (with Ctrl/Cmd) or pan vertically (without)
    w.addEventListener('wheel', function(e){
      var rect = w.getBoundingClientRect();
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        _zoomAtPoint(e.clientX - rect.left, e.clientY - rect.top, e.deltaY > 0 ? -0.1 : 0.1);
      } else {
        _panY -= e.deltaY;
        _panX -= e.deltaX;
        _applyTransform();
      }
    }, { passive: false });

    // Mouse drag → pan (only when clicking workspace bg or middle button)
    w.addEventListener('mousedown', function(e){
      // Middle mouse button → always pan
      var isMiddle = e.button === 1;
      // Ignore clicks inside the transform layer, chat, or zoom controls.
      var inCanvas = e.target.closest && e.target.closest('#st-canvas-transform');
      var inChat   = e.target.closest && e.target.closest('.st-chat');
      var inZoom   = e.target.closest && e.target.closest('.st-zoom');
      if (!isMiddle && (inCanvas || inChat || inZoom)) return;
      e.preventDefault();
      _isPanning = true;
      _panStart = { x: e.clientX, y: e.clientY, px: _panX, py: _panY };
      w.classList.add('panning');
    });
    document.addEventListener('mousemove', function(e){
      if (!_isPanning || !_panStart) return;
      _panX = _panStart.px + (e.clientX - _panStart.x);
      _panY = _panStart.py + (e.clientY - _panStart.y);
      _applyTransform();
    });
    document.addEventListener('mouseup', function(){
      if (!_isPanning) return;
      _isPanning = false;
      _panStart = null;
      var ws = document.getElementById('st-workspace');
      if (ws) ws.classList.remove('panning');
    });
  }

  window._studioZoomBy = function(delta) {
    _zoom = Math.min(2, Math.max(0.1, +( _zoom + delta ).toFixed(4)));
    _applyTransform();
  };
  window._studioZoomPrompt = function() {
    _stPrompt('Zoom level (10 – 200):', Math.round(_zoom * 100), { title: 'Set zoom' }).then(function(v){
      if (v == null) return;
      var n = parseFloat(v);
      if (!isFinite(n)) return;
      _zoom = Math.min(2, Math.max(0.1, n / 100));
      _applyTransform();
    });
  };
  window._studioFit = function() { _fitToViewport(); };

  window._studioBack = function() {
    _stConfirm('Leave the editor? Unsaved changes will be lost.', {
      title: 'Leave editor?',
      okLabel: 'Leave',
      cancelLabel: 'Keep editing',
      destructive: true
    }).then(function(ok){
      if (!ok) return;
      window.removeEventListener('resize', _measureAndFit);
      _mountGallery();
    });
  };

  // ── Tab switching ────────────────────────────────────────────
  window._studioSwitchTab = function(tab) {
    _currentTab = tab;
    document.querySelectorAll('.st-tab').forEach(function(el){
      el.classList.toggle('active', el.getAttribute('data-tab') === tab);
    });
    var body = document.getElementById('st-tab-body');
    if (tab === 'content') _renderContentTab(body);
    else if (tab === 'images') _renderImagesTab(body);
    else if (tab === 'colors') _renderColorsTab(body);
    else if (tab === 'export') _renderExportTab(body);
  };

  function _renderContentTab(body) {
    var fields = _fieldList.filter(function(f){ return f.kind === 'text'; });
    if (!fields.length) {
      body.innerHTML = '<div class="st-tab-head">Text content</div><div class="st-empty">No editable text fields found. Double-click any text in the canvas to edit directly.</div>';
      return;
    }
    var html = '<div class="st-tab-head">Text content \u00b7 ' + fields.length + ' fields</div>';
    fields.forEach(function(f){
      html += '<div class="st-field" onclick="_studioFocusField(\'' + _esc(f.name) + '\')">' +
        '<div class="st-field-label">' + _esc(f.name.replace(/_/g,' ')) + '</div>' +
        '<div class="st-field-val">' + _esc((f.value || '').substring(0, 120)) + '</div>' +
      '</div>';
    });
    body.innerHTML = html;
  }

  function _renderImagesTab(body) {
    var fields = _fieldList.filter(function(f){ return f.kind === 'image'; });
    if (!fields.length) {
      body.innerHTML = '<div class="st-tab-head">Image fields</div><div class="st-empty">No image fields in this template.</div>';
      return;
    }
    var html = '<div class="st-tab-head">Image fields \u00b7 ' + fields.length + ' images</div>';
    fields.forEach(function(f){
      var thumbBg = f.src ? 'background-image:url(' + JSON.stringify(f.src).slice(1,-1) + ')' : '';
      html += '<div class="st-field st-img-field" onclick="_studioReplaceImage(\'' + _esc(f.name) + '\')">' +
        '<div class="st-img-thumb" style="' + thumbBg + '"></div>' +
        '<div class="st-img-name">' + _esc(f.name.replace(/_/g,' ')) + '</div>' +
      '</div>';
    });
    body.innerHTML = html;
  }

  function _renderColorsTab(body) {
    var palettes = [
      { name:'Corporate Dark', vars:{'--primary':'#FF8C00','--bg':'#1E2128','--text':'#FFFFFF','--accent':'#FFB84D'} },
      { name:'Blue Burst',     vars:{'--primary':'#FFD600','--bg':'#1565C0','--text':'#FFFFFF','--accent':'#FFD600'} },
      { name:'Forest Gold',    vars:{'--primary':'#C9943A','--bg':'#0D2818','--text':'#FAF7F2','--accent':'#FFFFFF'} },
      { name:'Minimal Dark',   vars:{'--primary':'#C9943A','--bg':'#0A0A0A','--text':'#FFFFFF','--accent':'#C9943A'} },
      { name:'Purple Wave',    vars:{'--primary':'#B983FF','--bg':'#3D1B6E','--text':'#FFFFFF','--accent':'#FFE14D'} },
      { name:'Coral Sand',     vars:{'--primary':'#FF6B6B','--bg':'#FFF5E1','--text':'#2B1A1A','--accent':'#FFB84D'} },
      { name:'Electric Lime',  vars:{'--primary':'#CDFF00','--bg':'#0E1411','--text':'#FFFFFF','--accent':'#00E5A8'} },
      { name:'Cherry Blossom', vars:{'--primary':'#FF6EC4','--bg':'#1A0A1F','--text':'#FFE9F3','--accent':'#FFD1E6'} },
      { name:'Ocean Deep',     vars:{'--primary':'#00E5FF','--bg':'#0A1929','--text':'#FFFFFF','--accent':'#FFB3B3'} },
      { name:'Sunset Amber',   vars:{'--primary':'#FFC857','--bg':'#2B1E2E','--text':'#FFF1E6','--accent':'#FF6B6B'} }
    ];
    var html = '<div class="st-tab-head">Color Palettes</div><div class="st-pal-grid">';
    palettes.forEach(function(p){
      var swatches = Object.keys(p.vars).map(function(k){
        return '<span class="st-swatch" style="background:' + p.vars[k] + '"></span>';
      }).join('');
      html += '<div class="st-pal" data-vars=\'' + _esc(JSON.stringify(p.vars)) + '\' onclick="_studioApplyPaletteFromEl(this)">' +
        swatches +
        '<span class="st-pal-name">' + _esc(p.name) + '</span>' +
      '</div>';
    });
    html += '</div>';
    body.innerHTML = html;
  }

  function _renderExportTab(body) {
    body.innerHTML =
      '<div class="st-tab-head">Save & Export</div>' +
      '<button class="st-btn-lg" onclick="_studioExport()">\u2B07 Download PNG</button>' +
      '<button class="st-btn-lg secondary" onclick="_studioSave()">\u{1F4BE} Save to workspace</button>' +
      '<div class="st-empty" style="text-align:left;padding:10px 2px;line-height:1.5">Exports a native-resolution PNG rendered server-side by headless Chrome. Fonts, object-fit and effects match the editor exactly.</div>';
  }

  // ── Tab actions ──────────────────────────────────────────────
  window._studioFocusField = function(name) {
    _postToIframe({ type: 'focus-field', field: name });
  };
  window._studioReplaceImage = function(name) {
    if (typeof window.openMediaPicker !== 'function') { _toast('Media picker unavailable', 'error'); return; }
    window.openMediaPicker({ type: 'image', context: 'studio', field: name }, function(file){
      if (!file) return;
      var url = file.file_url || file.url || file.src || '';
      if (!url) return;
      _postToIframe({ type: 'lu-update-image', field: name, url: url });
      // Queue a save so the HTML reflects the new src
      _queueAutosave();
    });
  };
  window._studioApplyPaletteFromEl = function(el) {
    try {
      var vars = JSON.parse(el.getAttribute('data-vars'));
      _postToIframe({ type: 'apply-palette', vars: vars });
      _queueAutosave();
    } catch(_e){}
  };

  // ── Save / Export ────────────────────────────────────────────
  window._studioSave = function() {
    _pendingSerialize = { kind: 'manual' };
    _postToIframe({ type: 'serialize-html' });
  };

  function _queueAutosave() {
    clearTimeout(_saveTimer);
    _saveTimer = setTimeout(function(){
      _pendingSerialize = { kind: 'auto' };
      _postToIframe({ type: 'serialize-html' });
    }, 1500);
  }

  function _showSavedIndicator() {
    var el = document.getElementById('st-save-indicator');
    if (!el) return;
    el.style.display = 'block';
    setTimeout(function(){ el.style.display = 'none'; }, 1800);
  }

  window._studioExport = function() {
    if (!_designId) { _toast('No design to export', 'error'); return; }
    var iframe = document.getElementById('st-iframe');
    if (!iframe || !iframe.contentDocument) { _toast('Canvas not ready', 'error'); return; }
    var doc = iframe.contentDocument;
    var target = doc.querySelector('.canvas') || doc.querySelector('.post') || doc.body;
    if (!target) { _toast('Canvas not ready', 'error'); return; }

    var w = target.offsetWidth  || 1080;
    var h = target.offsetHeight || 1080;

    // Serialize the CURRENT editor state (so unsaved edits render too).
    // Strip contenteditable before serialising — the attr shouldn't carry
    // into the exported HTML.
    doc.querySelectorAll('[contenteditable="true"]').forEach(function(el){
      el.removeAttribute('contenteditable');
    });
    var html = '<!DOCTYPE html>\n' + doc.documentElement.outerHTML;

    _toast('Rendering PNG\u2026', 'info');

    fetch(_apiBase() + '/studio/designs/' + _designId + '/render-png', {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + _tok(),
        'Content-Type':  'application/json',
        'Accept':        'image/png'
      },
      body: JSON.stringify({ content_html: html, width: w, height: h })
    }).then(function(r){
      if (!r.ok) {
        return r.text().then(function(t){
          throw new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 300) : ''));
        });
      }
      return r.blob();
    }).then(function(blob){
      if (!blob || blob.size < 100) throw new Error('empty response');
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = (_designName || 'design').replace(/[^a-z0-9-]+/gi, '-') + '.png';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function(){ URL.revokeObjectURL(url); }, 2000);
      _toast('PNG exported (' + Math.round(blob.size / 1024) + ' KB, ' + w + '\u00d7' + h + ')', 'success');
    }).catch(function(err){
      _toast('Export failed: ' + (err && err.message ? err.message : String(err)), 'error');
      try { console.error('[studio export]', err); } catch(_e) {}
    });
  };

  // ── postMessage handler ──────────────────────────────────────
  function _handleIframeMsg(e) {
    if (!e.data || typeof e.data !== 'object' || !e.data.type) return;
    switch (e.data.type) {
      case 'fields-list':
        _fieldList = Array.isArray(e.data.fields) ? e.data.fields : [];
        if (_currentTab === 'content') _renderContentTab(document.getElementById('st-tab-body'));
        else if (_currentTab === 'images') _renderImagesTab(document.getElementById('st-tab-body'));
        break;
      case 'field-changed':
        // iframe reports live text/html change; queue autosave.
        _queueAutosave();
        break;
      case 'image-clicked':
        _studioReplaceImage(e.data.field);
        break;
      case 'html-serialized':
        _stPersistHtml(e.data.html, _pendingSerialize);
        _pendingSerialize = null;
        break;
      case 'studio-wheel':
        // Wheel event forwarded from the iframe so pinch/zoom/pan keeps
        // working when the cursor is over the template canvas.
        // Iframe coords (clientX/Y) are iframe-viewport-local; convert
        // to workspace-local using current pan + zoom.
        var d = e.data;
        if (d.ctrlKey || d.metaKey) {
          var mx = _panX + (d.clientX || 0) * _zoom;
          var my = _panY + (d.clientY || 0) * _zoom;
          _zoomAtPoint(mx, my, d.deltaY > 0 ? -0.1 : 0.1);
        } else {
          _panY -= (d.deltaY || 0);
          _panX -= (d.deltaX || 0);
          _applyTransform();
        }
        break;
    }
  }

  // ═══════════════════════════════════════════════════════════════
  // AI CHAT
  // ═══════════════════════════════════════════════════════════════
  var _chatHistory = [];
  var _chatLoading = false;

  function _wireChat() {
    var input = document.getElementById('st-chat-input');
    var send  = document.getElementById('st-chat-send');
    var box   = document.getElementById('st-chat-box');
    if (!input || !send || !box) return;

    function onInput(){
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 72) + 'px';
      send.classList.toggle('visible', input.value.trim().length > 0);
    }
    input.addEventListener('input', onInput);
    input.addEventListener('focus', function(){ box.classList.add('focused'); });
    input.addEventListener('blur',  function(){ box.classList.remove('focused'); });
    input.addEventListener('keydown', function(e){
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        _sendChat(input.value);
      }
    });
    send.addEventListener('click', function(){ _sendChat(input.value); });

    // Quick action pills — delegate.
    var quick = document.getElementById('st-chat-quick');
    if (quick) {
      quick.addEventListener('click', function(e){
        var btn = e.target.closest && e.target.closest('.st-chat-quick-btn');
        if (!btn) return;
        var msg = btn.getAttribute('data-msg') || btn.textContent;
        _sendChat(msg);
      });
    }
  }

  function _sendChat(raw) {
    if (_chatLoading) return;
    var text = (raw || '').trim();
    if (!text) return;
    var input = document.getElementById('st-chat-input');
    if (input) { input.value = ''; input.style.height = 'auto'; }
    var send = document.getElementById('st-chat-send');
    if (send) send.classList.remove('visible');

    _chatPush({ role: 'user', content: text });
    _hideQuickActions();
    _chatLoading = true;
    _chatRenderLoading(true);

    _fetchJson('/studio/chat', {
      method: 'POST',
      body: JSON.stringify({
        design_id: _designId,
        message: text,
        history: _chatHistory.slice(-6) // last 3 pairs
      })
    }).then(function(d){
      _chatLoading = false;
      _chatRenderLoading(false);
      if (!d.success) throw new Error(d.error || 'chat_failed');
      _chatPush({ role: 'assistant', content: d.reply || '' });
      if (Array.isArray(d.actions) && d.actions.length) {
        _applyChatActions(d.actions);
      }
    }).catch(function(err){
      _chatLoading = false;
      _chatRenderLoading(false);
      _chatPush({ role: 'error', content: 'Could not reach AI: ' + err.message });
      var box = document.getElementById('st-chat-box');
      if (box) { box.classList.add('error'); setTimeout(function(){ box.classList.remove('error'); }, 2500); }
    });
  }

  function _chatPush(msg) {
    _chatHistory.push(msg);
    var list = document.getElementById('st-chat-msgs');
    if (!list) return;
    list.style.display = 'flex';
    var el = document.createElement('div');
    var cls = 'st-chat-msg ' + (msg.role === 'user' ? 'user' : msg.role === 'error' ? 'error' : 'ai');
    el.className = cls;
    el.textContent = msg.content;
    list.appendChild(el);
    // Keep scroll pinned to bottom.
    list.scrollTop = list.scrollHeight;
  }

  function _chatRenderLoading(show) {
    var list = document.getElementById('st-chat-msgs');
    if (!list) return;
    var existing = list.querySelector('.st-chat-loading');
    if (show) {
      list.style.display = 'flex';
      if (!existing) {
        var el = document.createElement('div');
        el.className = 'st-chat-loading';
        el.innerHTML = '\u2726 <span>\u00b7</span><span>\u00b7</span><span>\u00b7</span>';
        list.appendChild(el);
        list.scrollTop = list.scrollHeight;
      }
    } else if (existing) {
      existing.remove();
    }
  }

  function _hideQuickActions() {
    var q = document.getElementById('st-chat-quick');
    if (q) q.style.display = 'none';
  }

  function _applyChatActions(actions) {
    actions.forEach(function(a){
      if (!a || !a.type) return;
      if (a.type === 'apply_palette' && a.vars) {
        _postToIframe({ type: 'apply-palette', vars: a.vars });
      } else if (a.type === 'update_field' && a.name) {
        _postToIframe({ type: 'update-field-text', field: a.name, value: String(a.value || '') });
      } else if (a.type === 'update_image' && a.name && a.url) {
        _postToIframe({ type: 'lu-update-image', field: a.name, url: a.url });
      }
    });
    // After AI edits, queue a save.
    _queueAutosave();
  }

  function _stPersistHtml(html, info) {
    if (!_designId) return;
    _fetchJson('/studio/designs/' + _designId, {
      method: 'PUT',
      body: JSON.stringify({ content_html: html })
    }).then(function(d){
      if (d.success) {
        if (info && info.kind === 'manual') _toast('Saved', 'success');
        _showSavedIndicator();
      }
    }).catch(function(err){
      _toast('Save failed: ' + err.message, 'error');
    });
  }

  // ═══════════════════════════════════════════════════════════════
  // THEMED DIALOGS — replace native alert/confirm/prompt so dialogs
  // inherit the Studio dark theme. Promise-based API.
  // ═══════════════════════════════════════════════════════════════
  function _dialog(opts) {
    opts = opts || {};
    return new Promise(function(resolve){
      var overlay = document.createElement('div');
      overlay.className = 'st-dlg-overlay';
      var dlg = document.createElement('div');
      dlg.className = 'st-dlg';

      var html = '';
      if (opts.title)   html += '<div class="st-dlg-title">' + _esc(opts.title) + '</div>';
      if (opts.message) html += '<div class="st-dlg-msg">'   + _esc(opts.message) + '</div>';
      if (opts.type === 'prompt') {
        var val = opts.defaultValue == null ? '' : String(opts.defaultValue);
        html += '<input type="text" class="st-dlg-input" value="' + _esc(val) + '" />';
      }
      var okLabel     = opts.okLabel     || 'OK';
      var cancelLabel = opts.cancelLabel || 'Cancel';
      var okClass     = opts.destructive ? 'danger' : 'primary';
      html += '<div class="st-dlg-actions">';
      if (opts.type !== 'alert') {
        html += '<button type="button" class="st-dlg-btn" data-v="0">' + _esc(cancelLabel) + '</button>';
      }
      html += '<button type="button" class="st-dlg-btn ' + okClass + '" data-v="1">' + _esc(okLabel) + '</button>';
      html += '</div>';
      dlg.innerHTML = html;
      overlay.appendChild(dlg);
      document.body.appendChild(overlay);

      function done(result) {
        document.removeEventListener('keydown', onKey, true);
        overlay.parentNode && overlay.parentNode.removeChild(overlay);
        resolve(result);
      }
      function okValue() {
        if (opts.type === 'prompt') {
          var inp = dlg.querySelector('.st-dlg-input');
          return inp ? inp.value : '';
        }
        return opts.type === 'alert' ? true : true;
      }
      function cancelValue() {
        return opts.type === 'prompt' ? null : false;
      }
      function onKey(e){
        if (e.key === 'Escape') { e.preventDefault(); done(cancelValue()); }
        else if (e.key === 'Enter' && !e.shiftKey) {
          // In prompt the input is focused; Enter submits.
          e.preventDefault();
          done(okValue());
        }
      }
      dlg.addEventListener('click', function(ev){
        var btn = ev.target.closest && ev.target.closest('.st-dlg-btn');
        if (!btn) return;
        if (btn.getAttribute('data-v') === '1') done(okValue());
        else done(cancelValue());
      });
      overlay.addEventListener('click', function(ev){
        // click on backdrop (not dialog) = cancel
        if (ev.target === overlay) done(cancelValue());
      });
      document.addEventListener('keydown', onKey, true);
      // Focus input if prompt, else the primary button.
      setTimeout(function(){
        var inp = dlg.querySelector('.st-dlg-input');
        if (inp) { inp.focus(); inp.select(); }
        else {
          var p = dlg.querySelector('.st-dlg-btn.primary') || dlg.querySelector('.st-dlg-btn.danger');
          if (p) p.focus();
        }
      }, 30);
    });
  }
  function _stConfirm(message, opts){
    opts = opts || {};
    return _dialog({
      type: 'confirm',
      title: opts.title || '',
      message: message,
      okLabel: opts.okLabel || 'Continue',
      cancelLabel: opts.cancelLabel || 'Cancel',
      destructive: !!opts.destructive
    });
  }
  function _stPrompt(message, defaultValue, opts){
    opts = opts || {};
    return _dialog({
      type: 'prompt',
      title: opts.title || '',
      message: message,
      defaultValue: defaultValue == null ? '' : String(defaultValue),
      okLabel: opts.okLabel || 'OK',
      cancelLabel: opts.cancelLabel || 'Cancel'
    });
  }
  function _stAlert(message, opts){
    opts = opts || {};
    return _dialog({
      type: 'alert',
      title: opts.title || '',
      message: message,
      okLabel: opts.okLabel || 'OK'
    });
  }

  // Expose themed dialog helpers globally so sibling Studio modules
  // (studio-video.js, etc.) can use them instead of native confirm/prompt.
  window.luDialog  = _dialog;
  window.luConfirm = _stConfirm;
  window.luPrompt  = _stPrompt;
  window.luAlert   = _stAlert;

  function _getOrCreateHost() {
    var parent = _rootEl || document.getElementById('studio-root') || document.body;
    try { parent.style.position = parent.style.position || 'relative'; } catch(_){}
    var host = parent.querySelector('#st-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'st-host';
      host.className = 'st-host';
      parent.appendChild(host);
    }
    return host;
  }
})();

// ═══════════════════════════════════════════════════════════════════
// STUDIO IMAGE EDITOR v5.1.0 — Native canvas+DOM editor (Phase 2)
// Replaces the iframe-based _mountEditor with a true element-level editor.
// Mounted via: _mountEditor() (reused by the gallery)
// ═══════════════════════════════════════════════════════════════════
(function(){
  'use strict';

  // ── STATE ──────────────────────────────────────────────────
  var EDT = {
    design: null,
    elements: [],
    selected: [],      // array of element ids
    history: [],       // array of {elements, design_meta}
    historyIndex: -1,
    zoom: 1.0,
    panX: 0,
    panY: 0,
    dirty: false,
    saving: false,
    root: null,
    frame: null,
    layer: null,        // #st-elements-layer
    canvasArea: null,
    transform: null,
    overlay: null,
    propsPanel: null,
    leftPanel: null,
    guidesEl: null,
    marqueeEl: null,
    dragState: null,
    resizeState: null,
    rotateState: null,
    marqueeState: null,
    panState: null,
    inlineEditingId: null,
    clipboard: null,
    snapEnabled: true,
    gridEnabled: false,
    layersVisible: false,
    autoSaveTimer: null,
    saveHistoryDebounce: null,
    cssInjected: false,
    activeLeftTab: 'design',
    brandKit: null,
    templates: null,
    lastClickTime: 0,
    lastClickId: null,
  };
  // Expose for debugging + cross-function access
  window._stEDT = EDT;

  var FONTS = [
    'Syne','DM Sans','Inter','Montserrat','Playfair Display','Oswald','Raleway',
    'Bebas Neue','Lato','Roboto','Open Sans','Cormorant Garamond','Unbounded',
    'Figtree','DM Mono','Space Grotesk','Plus Jakarta Sans','Nunito','Poppins','Work Sans',
  ];

  var BRAND_SHAPES = {
    rectangle: '<rect x="2" y="2" width="96" height="96" rx="8" ry="8" fill="currentColor"/>',
    circle:    '<circle cx="50" cy="50" r="48" fill="currentColor"/>',
    triangle:  '<polygon points="50,4 96,92 4,92" fill="currentColor"/>',
    star:      '<polygon points="50,5 61,39 97,39 68,61 79,95 50,75 21,95 32,61 3,39 39,39" fill="currentColor"/>',
    arrow:     '<path d="M10 50 L70 50 M55 30 L70 50 L55 70" stroke="currentColor" stroke-width="10" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
    heart:     '<path d="M50 85 L15 55 Q5 42 15 30 Q28 20 50 40 Q72 20 85 30 Q95 42 85 55 Z" fill="currentColor"/>',
    pentagon:  '<polygon points="50,5 95,38 78,92 22,92 5,38" fill="currentColor"/>',
    diamond:   '<polygon points="50,5 95,50 50,95 5,50" fill="currentColor"/>',
  };

  var GRADIENT_PRESETS = [
    ['#6C5CE7','#A78BFA'], ['#00E5A8','#0284C7'], ['#F59E0B','#EF4444'],
    ['#EC4899','#8B5CF6'], ['#0F172A','#6C5CE7'], ['#FEF3C7','#F59E0B'],
    ['#DBEAFE','#3B82F6'], ['#FCE7F3','#EC4899'], ['#F1F5F9','#94A3B8'],
    ['#FF006E','#FB5607'], ['#3A86FF','#8338EC'], ['#2D1B69','#F72585'],
  ];

  // Entry point — rebinds window._mountEditor to this implementation.
  function _stOpenImageEditor(designId){
    var id = designId || window._designId || null;
    if (!id) { _toast('No design id', 'error'); return; }
    _stInjectCss();

    // Fresh fetch of design + elements (don't trust stale cache)
    _fetchJson('/studio/designs/' + id).then(function(d){
      if (d.error || (!d.success && !d.design)) throw new Error(d.error || 'load_failed');
      EDT.design = d.design || d; // route returns {success, design}
      // getDesign on our new service returns {design, elements}; old route returns the row directly.
      return _fetchJson('/studio/designs/' + id + '/elements').then(function(er){
        EDT.elements = (er && er.elements) ? er.elements : [];
        // If NO elements exist yet, try seeding from the template's parsed content (fallback to empty canvas)
        if (!EDT.elements.length && EDT.design.template_id) {
          return _stSeedFromLegacyTemplate(EDT.design).then(function(){
            return _stMountEditorShell();
          });
        }
        return _stMountEditorShell();
      });
    }).catch(function(err){
      _toast('Editor load failed: ' + err.message, 'error');
    });

    // Also load brand kit in background
    _fetchJson('/studio/brand-kit').then(function(bk){ EDT.brandKit = (bk && bk.brand_kit) || {}; }).catch(function(){});
  }

  // If a design has legacy layers_json, convert to elements rows on first open.
  function _stSeedFromLegacyTemplate(design){
    try {
      var layers = typeof design.layers_json === 'string'
        ? JSON.parse(design.layers_json || '[]')
        : (design.layers_json || []);
      if (!Array.isArray(layers) || !layers.length) return Promise.resolve();
      var ops = layers.map(function(l, i){
        return _fetchJson('/studio/designs/' + design.id + '/elements', {
          method:'POST',
          body: JSON.stringify({
            element_type: l.element_type || l.type || 'text',
            properties_json: l.properties_json || l.properties || l,
            layer_order: i + 1,
          })
        });
      });
      return Promise.all(ops).then(function(){
        return _fetchJson('/studio/designs/' + design.id + '/elements').then(function(er){
          EDT.elements = er.elements || [];
        });
      });
    } catch (_) { return Promise.resolve(); }
  }

  // ── DOM MOUNT ──────────────────────────────────────────────
  function _stMountEditorShell(){
    window.removeEventListener('message', _handleIframeMsg);
    var host = _getOrCreateHost();
    host.innerHTML = _stEditorRootHtml();
    EDT.root         = host.querySelector('.st-editor-root');
    EDT.frame        = host.querySelector('#st-frame');
    EDT.layer        = host.querySelector('#st-elements-layer');
    EDT.canvasArea   = host.querySelector('#st-canvas-area');
    EDT.transform    = host.querySelector('#st-canvas-transform');
    EDT.overlay      = host.querySelector('#st-selection-overlay');
    EDT.propsPanel   = host.querySelector('#st-props-panel');
    EDT.leftPanel    = host.querySelector('#st-panel-body');
    EDT.guidesEl     = host.querySelector('#st-guides');

    _stApplyFrameSize();
    _stApplyBackground();
    _stRenderAllElements();
    _stWireTopbar();
    _stWireLeftPanelTabs();
    _stRenderLeftPanel(EDT.activeLeftTab);
    _stWireCanvas();
    _stWireGlobalKeys();
    _stRenderProps();
    _stInitHistory();
    _stFitToViewport();

    // Auto-save every 30s if dirty
    if (EDT.autoSaveTimer) clearInterval(EDT.autoSaveTimer);
    EDT.autoSaveTimer = setInterval(function(){ if (EDT.dirty) _stAutoSave(); }, 30000);

    // beforeunload warning
    if (!window._stBeforeUnloadBound) {
      window._stBeforeUnloadBound = true;
      window.addEventListener('beforeunload', function(e){
        if (EDT.dirty) { e.preventDefault(); e.returnValue = ''; }
      });
    }
  }

  function _stEditorRootHtml(){
    var d = EDT.design || {};
    var name = _esc(d.name || 'Untitled');
    return (
      '<div id="st-editor" class="st-editor-root">' +
        _stTopBarHtml(name) +
        '<div class="st-shell">' +
          _stLeftPanelHtml() +
          _stCanvasAreaHtml(d) +
          _stRightPanelHtml() +
        '</div>' +
        _stAiFabHtml() +
      '</div>'
    );
  }

  function _stTopBarHtml(name){
    return (
      '<div class="st-topbar">' +
        '<button class="st-back" id="st-back-btn">\u2190 Back</button>' +
        '<input class="st-design-name" id="st-design-name" value="' + name + '"/>' +
        '<div class="st-topbar-center">' +
          '<button class="st-icon-btn" id="st-undo" title="Undo (Ctrl+Z)">\u21a9</button>' +
          '<button class="st-icon-btn" id="st-redo" title="Redo (Ctrl+Y)">\u21aa</button>' +
          '<span id="st-save-status" class="st-save-status"></span>' +
        '</div>' +
        '<div class="st-topbar-right">' +
          '<button class="st-btn-ghost" id="st-resize-btn">Resize</button>' +
          '<div class="st-download-wrap">' +
            '<button class="st-btn-ghost" id="st-download-btn">Download \u25be</button>' +
            '<div class="st-download-menu hidden" id="st-download-menu">' +
              '<div data-fmt="png">PNG</div>' +
              '<div data-fmt="jpg">JPG</div>' +
            '</div>' +
          '</div>' +
          '<button class="st-btn-primary" id="st-publish-btn">Publish</button>' +
        '</div>' +
      '</div>'
    );
  }

  function _stLeftPanelHtml(){
    return (
      '<div class="st-left" id="st-left-panel">' +
        '<div class="st-panel-tabs">' +
          ['design','elements','text','photos','brand','bg'].map(function(t, i){
            var label = { design:'Design', elements:'Elements', text:'Text', photos:'Photos', brand:'Brand', bg:'Background' }[t];
            return '<button class="st-ptab' + (t === EDT.activeLeftTab ? ' active' : '') + '" data-tab="' + t + '">' + label + '</button>';
          }).join('') +
        '</div>' +
        '<div class="st-panel-body" id="st-panel-body"></div>' +
      '</div>'
    );
  }

  function _stCanvasAreaHtml(d){
    var w = d.canvas_width || 1080, h = d.canvas_height || 1080;
    return (
      '<div class="st-canvas-area" id="st-canvas-area">' +
        '<div class="st-canvas-outer" id="st-canvas-outer">' +
          '<div class="st-canvas-transform" id="st-canvas-transform">' +
            '<div class="st-frame" id="st-frame" style="width:' + w + 'px;height:' + h + 'px;">' +
              '<div class="st-bg" id="st-bg"></div>' +
              '<div class="st-elements-layer" id="st-elements-layer"></div>' +
              '<div class="st-selection-overlay hidden" id="st-selection-overlay"></div>' +
              '<div class="st-guides" id="st-guides"></div>' +
              '<div class="st-marquee hidden" id="st-marquee"></div>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="st-canvas-toolbar">' +
          '<button class="st-icon-btn" id="st-zoom-out" title="Zoom out">\u2212</button>' +
          '<span id="st-zoom-label">100%</span>' +
          '<button class="st-icon-btn" id="st-zoom-in" title="Zoom in">+</button>' +
          '<button class="st-icon-btn" id="st-zoom-fit" title="Fit">Fit</button>' +
          '<div class="st-canvas-toolbar-sep"></div>' +
          '<button class="st-icon-btn" id="st-grid-toggle" title="Grid (G)">Grid</button>' +
          '<button class="st-icon-btn" id="st-layers-toggle" title="Layers">Layers</button>' +
        '</div>' +
        '<div class="st-layers-panel hidden" id="st-layers-panel">' +
          '<div class="st-layers-header">Layers <button id="st-layers-close">\u2715</button></div>' +
          '<div class="st-layers-list" id="st-layers-list"></div>' +
        '</div>' +
      '</div>'
    );
  }

  function _stRightPanelHtml(){
    return (
      '<div class="st-right" id="st-right-panel">' +
        '<div id="st-props-panel"></div>' +
      '</div>'
    );
  }

  function _stAiFabHtml(){
    return (
      '<button class="st-ai-fab" id="st-ai-fab" title="Arthur AI">\u2726</button>' +
      '<div class="st-ai-drawer hidden" id="st-ai-drawer">' +
        '<div class="st-ai-header">Arthur \u2014 Design AI<button id="st-ai-close">\u2715</button></div>' +
        '<div class="st-ai-chat" id="st-ai-chat"><div class="st-ai-welcome">Hi, I\u2019m Arthur. Select an element or ask me to edit the whole design.</div></div>' +
        '<div class="st-ai-input-wrap"><textarea id="st-ai-input" rows="2" placeholder="Ask Arthur..."></textarea><button id="st-ai-send">Send</button></div>' +
        '<div class="st-ai-quick">' +
          ['Apply my brand colors','Make the design more bold','Make it luxury and premium','Change to dark theme','Rewrite all the text'].map(function(p){
            return '<button data-p="' + _esc(p) + '">' + _esc(p) + '</button>';
          }).join('') +
        '</div>' +
      '</div>'
    );
  }

  // Expose entry point globally — bind INSIDE the IIFE so _mountEditor sees it.
  window._stOpenImageEditor = _stOpenImageEditor;

  // ── CONTINUED IN PART 2 ──

  // ═══════════════════════════════════════════════════════════════
  // PART 2 — Element rendering, selection, drag, resize, rotate
  // ═══════════════════════════════════════════════════════════════

  function _stApplyFrameSize(){
    var d = EDT.design || {};
    if (EDT.frame) {
      EDT.frame.style.width  = (d.canvas_width  || 1080) + 'px';
      EDT.frame.style.height = (d.canvas_height || 1080) + 'px';
    }
  }

  function _stApplyBackground(){
    var d = EDT.design || {};
    var bg = document.getElementById('st-bg'); if (!bg) return;
    var type = d.background_type || 'color';
    var val  = d.background_value || '#FFFFFF';
    if (type === 'color')    bg.style.background = val || '#FFFFFF';
    else if (type === 'image')   bg.style.background = 'url(' + val + ') center/cover no-repeat';
    else if (type === 'gradient'){
      try { var g = JSON.parse(val); bg.style.background = 'linear-gradient(' + (g.angle||135) + 'deg, ' + g.colors.join(', ') + ')'; }
      catch (_) { bg.style.background = val; }
    }
  }

  // ── Element rendering ──────────────────────────────────────
  function _stRenderAllElements(){
    if (!EDT.layer) return;
    EDT.layer.innerHTML = '';
    // Sort by layer_order — lowest first → drawn first → behind
    EDT.elements.slice().sort(function(a,b){ return (a.layer_order||0) - (b.layer_order||0); })
      .forEach(function(el){ EDT.layer.appendChild(_stRenderElement(el)); });
  }

  function _stRenderElement(el){
    var node = document.createElement('div');
    node.className = 'st-element st-element-' + el.element_type;
    node.setAttribute('data-id', String(el.id));
    node.setAttribute('data-type', el.element_type);
    var p = el.properties_json || {};
    node.style.cssText = _stStyleForElement(el);

    if (el.element_type === 'text') {
      var t = document.createElement('div');
      t.className = 'st-text-content';
      t.contentEditable = 'false';
      t.style.cssText = _stTextStyle(p);
      t.innerText = p.content == null ? 'Your text' : String(p.content);
      node.appendChild(t);
    } else if (el.element_type === 'image') {
      var img = document.createElement('img');
      img.src = p.src_url || p.url || '';
      img.draggable = false;
      img.style.cssText =
        'width:100%;height:100%;' +
        'object-fit:' + (p.object_fit || 'cover') + ';' +
        'object-position:' + (p.object_position || 'center') + ';' +
        (p.filter ? 'filter:' + p.filter + ';' :
          'filter:brightness(' + ((p.brightness || 0) / 100 + 1) + ') contrast(' + ((p.contrast || 0) / 100 + 1) + ') saturate(' + ((p.saturation || 0) / 100 + 1) + ') blur(' + (p.blur || 0) + 'px);') +
        (p.border ? 'border:' + p.border + ';' : '') +
        'border-radius:' + (p.border_radius || 0) + 'px;';
      node.appendChild(img);
    } else if (el.element_type === 'shape') {
      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 100 100');
      svg.setAttribute('preserveAspectRatio', 'none');
      svg.style.width = '100%'; svg.style.height = '100%';
      svg.style.color = p.fill || '#6C5CE7';
      var shapeType = p.shape_type || 'rectangle';
      svg.innerHTML = BRAND_SHAPES[shapeType] || BRAND_SHAPES.rectangle;
      if (p.stroke && p.stroke_width) {
        var paths = svg.querySelectorAll('rect,circle,polygon,path');
        paths.forEach(function(pth){
          pth.setAttribute('stroke', p.stroke);
          pth.setAttribute('stroke-width', String(p.stroke_width));
          if (p.fill === 'none') pth.setAttribute('fill', 'none');
        });
      }
      node.appendChild(svg);
    } else if (el.element_type === 'line') {
      var ln = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      ln.setAttribute('viewBox', '0 0 100 100');
      ln.setAttribute('preserveAspectRatio', 'none');
      ln.style.width = '100%'; ln.style.height = '100%';
      var stroke = p.stroke || p.color || '#0F172A';
      var sw = p.stroke_width || 3;
      var dasharray = p.style === 'dashed' ? 'stroke-dasharray="8 6"' : '';
      ln.innerHTML = '<line x1="0" y1="50" x2="100" y2="50" stroke="' + stroke + '" stroke-width="' + sw + '" ' + dasharray + '/>';
      node.appendChild(ln);
    } else if (el.element_type === 'icon' || el.element_type === 'sticker') {
      // Emoji-based icon (simple, fast)
      var ic = document.createElement('div');
      ic.style.cssText = 'width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:' + (p.font_size || 80) + 'px';
      ic.textContent = p.content || '\u2b50';
      node.appendChild(ic);
    } else {
      // Fallback placeholder
      node.textContent = el.element_type;
      node.style.background = '#F1F5F9';
      node.style.color = '#64748B';
      node.style.display = 'flex';
      node.style.alignItems = 'center';
      node.style.justifyContent = 'center';
    }

    // Events
    node.addEventListener('mousedown', function(e){ _stElementMouseDown(e, el.id); });
    node.addEventListener('dblclick',  function(e){ e.stopPropagation(); if (el.element_type === 'text') _stEnterTextEdit(el.id); });
    node.addEventListener('touchstart', function(e){
      if (!e.touches || !e.touches.length) return;
      var t = e.touches[0];
      var fake = { clientX: t.clientX, clientY: t.clientY, shiftKey: false, preventDefault: function(){ e.preventDefault(); }, stopPropagation: function(){ e.stopPropagation(); }, button: 0 };
      _stElementMouseDown(fake, el.id);
    }, { passive: false });

    if (p.hidden) node.style.display = 'none';

    return node;
  }

  function _stStyleForElement(el){
    var p = el.properties_json || {};
    var x = p.x || 0, y = p.y || 0;
    var w = p.width  != null ? p.width  : 200;
    var h = p.height != null ? p.height : 80;
    var rot = p.rotation || 0;
    var op  = p.opacity != null ? p.opacity : 1;
    var z   = p.z_index != null ? p.z_index : el.layer_order || 0;
    var flipX = p.flip_x ? ' scaleX(-1)' : '';
    var flipY = p.flip_y ? ' scaleY(-1)' : '';
    return (
      'position:absolute;' +
      'left:' + x + 'px;top:' + y + 'px;' +
      'width:' + w + 'px;height:' + h + 'px;' +
      'transform:rotate(' + rot + 'deg)' + flipX + flipY + ';' +
      'opacity:' + op + ';' +
      'z-index:' + z + ';' +
      (p.locked ? 'pointer-events:auto;cursor:default;' : '') +
      (p.background_color ? 'background-color:' + p.background_color + ';' : '') +
      (p.padding ? 'padding:' + p.padding + 'px;box-sizing:border-box;' : '') +
      (p.border_radius ? 'border-radius:' + p.border_radius + 'px;' : '') +
      (p.shadow ? 'box-shadow:' + p.shadow + ';' : '')
    );
  }

  function _stTextStyle(p){
    return (
      'font-family:' + (p.font_family || 'Inter') + ';' +
      'font-size:' + (p.font_size || 32) + 'px;' +
      'font-weight:' + (p.font_weight || '400') + ';' +
      'font-style:' + (p.font_style || 'normal') + ';' +
      'color:' + (p.color || '#000000') + ';' +
      'text-align:' + (p.text_align || 'left') + ';' +
      'line-height:' + (p.line_height || 1.3) + ';' +
      'letter-spacing:' + (p.letter_spacing != null ? p.letter_spacing + 'px' : 'normal') + ';' +
      'text-decoration:' + (p.text_decoration || 'none') + ';' +
      'text-transform:' + (p.text_transform || 'none') + ';' +
      'width:100%;height:100%;' +
      'display:flex;flex-direction:column;' +
      'justify-content:' + (p.vertical_align === 'top' ? 'flex-start' : p.vertical_align === 'bottom' ? 'flex-end' : 'center') + ';' +
      'word-break:break-word;outline:none;' +
      (p.text_shadow ? 'text-shadow:' + p.text_shadow + ';' : '')
    );
  }

  function _stUpdateElementDom(el){
    var node = EDT.layer.querySelector('[data-id="' + el.id + '"]');
    if (!node) return;
    node.style.cssText = _stStyleForElement(el);
    var p = el.properties_json || {};
    if (el.element_type === 'text') {
      var t = node.querySelector('.st-text-content');
      if (t) { t.style.cssText = _stTextStyle(p); if (!EDT.inlineEditingId || Number(EDT.inlineEditingId) !== Number(el.id)) t.innerText = p.content == null ? '' : String(p.content); }
    } else if (el.element_type === 'image') {
      var img = node.querySelector('img');
      if (img && img.src !== (p.src_url || '')) img.src = p.src_url || '';
      if (img) {
        img.style.objectFit = p.object_fit || 'cover';
        img.style.filter = 'brightness(' + ((p.brightness || 0) / 100 + 1) + ') contrast(' + ((p.contrast || 0) / 100 + 1) + ') saturate(' + ((p.saturation || 0) / 100 + 1) + ') blur(' + (p.blur || 0) + 'px)';
        img.style.borderRadius = (p.border_radius || 0) + 'px';
        if (p.border) img.style.border = p.border; else img.style.border = '';
      }
    } else if (el.element_type === 'shape') {
      var svg = node.querySelector('svg');
      if (svg) svg.style.color = p.fill || '#6C5CE7';
      var shape = BRAND_SHAPES[p.shape_type] || BRAND_SHAPES.rectangle;
      if (svg && svg.innerHTML !== shape) svg.innerHTML = shape;
    }
    if (p.hidden) node.style.display = 'none'; else node.style.display = '';
    _stUpdateSelectionOverlay();
  }

  // ── Selection ──────────────────────────────────────────────
  function _stSelectElement(id, additive){
    var numId = Number(id);
    if (additive) {
      var idx = EDT.selected.indexOf(numId);
      if (idx >= 0) EDT.selected.splice(idx, 1);
      else EDT.selected.push(numId);
    } else {
      EDT.selected = [numId];
    }
    _stSyncSelectionClasses();
    _stUpdateSelectionOverlay();
    _stRenderProps();
    _stRenderLayers();
  }

  function _stDeselectAll(){
    EDT.selected = [];
    _stSyncSelectionClasses();
    _stUpdateSelectionOverlay();
    _stRenderProps();
    _stRenderLayers();
  }

  function _stSyncSelectionClasses(){
    if (!EDT.layer) return;
    EDT.layer.querySelectorAll('.st-element').forEach(function(n){ n.classList.remove('selected'); });
    EDT.selected.forEach(function(id){
      var n = EDT.layer.querySelector('[data-id="' + id + '"]');
      if (n) n.classList.add('selected');
    });
  }

  function _stUpdateSelectionOverlay(){
    if (!EDT.overlay) return;
    if (!EDT.selected.length) { EDT.overlay.classList.add('hidden'); EDT.overlay.innerHTML = ''; return; }
    // Compute combined bounding box in frame coords
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    EDT.selected.forEach(function(id){
      var el = _stGetEl(id); if (!el) return;
      var p = el.properties_json || {};
      var x = p.x || 0, y = p.y || 0;
      var w = p.width != null ? p.width : 200;
      var h = p.height != null ? p.height : 80;
      if (x < minX) minX = x;
      if (y < minY) minY = y;
      if (x + w > maxX) maxX = x + w;
      if (y + h > maxY) maxY = y + h;
    });
    if (minX === Infinity) { EDT.overlay.classList.add('hidden'); return; }

    EDT.overlay.classList.remove('hidden');
    EDT.overlay.style.left = minX + 'px';
    EDT.overlay.style.top  = minY + 'px';
    EDT.overlay.style.width  = (maxX - minX) + 'px';
    EDT.overlay.style.height = (maxY - minY) + 'px';

    // Apply rotation only for SINGLE selection
    var rot = 0;
    if (EDT.selected.length === 1) {
      var one = _stGetEl(EDT.selected[0]);
      rot = (one && one.properties_json && one.properties_json.rotation) || 0;
    }
    EDT.overlay.style.transform = 'rotate(' + rot + 'deg)';

    // Render 8 handles + 1 rotate handle
    EDT.overlay.innerHTML =
      ['tl','t','tr','r','br','b','bl','l'].map(function(h){
        return '<div class="st-handle st-handle-' + h + '" data-handle="' + h + '"></div>';
      }).join('') +
      '<div class="st-rotate-handle" data-handle="rotate"></div>';

    // Wire handles
    EDT.overlay.querySelectorAll('.st-handle').forEach(function(h){
      h.addEventListener('mousedown', function(e){ e.stopPropagation(); _stStartResize(e, h.getAttribute('data-handle')); });
    });
    var rh = EDT.overlay.querySelector('.st-rotate-handle');
    if (rh) rh.addEventListener('mousedown', function(e){ e.stopPropagation(); _stStartRotate(e); });
  }

  // ── Drag to move ───────────────────────────────────────────
  function _stElementMouseDown(e, id){
    if (EDT.inlineEditingId && Number(EDT.inlineEditingId) === Number(id)) return;
    // Selection
    var additive = e.shiftKey;
    if (!additive && EDT.selected.indexOf(Number(id)) < 0) {
      _stSelectElement(id, false);
    } else if (additive) {
      _stSelectElement(id, true);
    }
    // Start drag
    var el = _stGetEl(id); if (!el) return;
    if (el.properties_json && el.properties_json.locked) return;
    e.preventDefault();

    EDT.dragState = {
      startX: e.clientX, startY: e.clientY,
      initial: EDT.selected.map(function(sid){
        var se = _stGetEl(sid);
        return { id: sid, x: (se && se.properties_json && se.properties_json.x) || 0, y: (se && se.properties_json && se.properties_json.y) || 0 };
      }),
      moved: false,
    };
    document.addEventListener('mousemove', _stOnDragMove);
    document.addEventListener('mouseup', _stOnDragEnd);
  }

  function _stOnDragMove(e){
    if (!EDT.dragState) return;
    var dx = (e.clientX - EDT.dragState.startX) / EDT.zoom;
    var dy = (e.clientY - EDT.dragState.startY) / EDT.zoom;
    EDT.dragState.moved = true;

    EDT.dragState.initial.forEach(function(entry){
      var el = _stGetEl(entry.id); if (!el) return;
      var newX = entry.x + dx;
      var newY = entry.y + dy;
      // Snap only when single-element drag
      if (EDT.snapEnabled && EDT.dragState.initial.length === 1) {
        var w = el.properties_json.width || 200;
        var h = el.properties_json.height || 80;
        var sX = _stSnapX(newX, w, entry.id);
        var sY = _stSnapY(newY, h, entry.id);
        newX = sX; newY = sY;
      }
      el.properties_json.x = Math.round(newX);
      el.properties_json.y = Math.round(newY);
      _stUpdateElementDom(el);
    });
    _stUpdateSelectionOverlay();
  }

  function _stOnDragEnd(e){
    document.removeEventListener('mousemove', _stOnDragMove);
    document.removeEventListener('mouseup', _stOnDragEnd);
    _stClearGuides();
    if (EDT.dragState && EDT.dragState.moved) {
      _stSaveHistory();
      EDT.dragState.initial.forEach(function(entry){ _stPersistElement(entry.id); });
      _stMarkDirty();
    }
    EDT.dragState = null;
  }

  // ── Resize handles ─────────────────────────────────────────
  function _stStartResize(e, handle){
    if (EDT.selected.length !== 1) return;
    var el = _stGetEl(EDT.selected[0]); if (!el) return;
    if (el.properties_json && el.properties_json.locked) return;
    e.preventDefault();
    var p = el.properties_json || {};
    EDT.resizeState = {
      handle: handle,
      startX: e.clientX, startY: e.clientY,
      initial: { x: p.x || 0, y: p.y || 0, w: p.width || 200, h: p.height || 80 },
      aspect: (p.width || 200) / Math.max(1, (p.height || 80)),
      shift: e.shiftKey,
      moved: false,
    };
    document.addEventListener('mousemove', _stOnResize);
    document.addEventListener('mouseup', _stOnResizeEnd);
  }

  function _stOnResize(e){
    var rs = EDT.resizeState; if (!rs) return;
    var el = _stGetEl(EDT.selected[0]); if (!el) return;
    rs.moved = true;
    var dx = (e.clientX - rs.startX) / EDT.zoom;
    var dy = (e.clientY - rs.startY) / EDT.zoom;
    var nx = rs.initial.x, ny = rs.initial.y, nw = rs.initial.w, nh = rs.initial.h;

    switch (rs.handle) {
      case 'tl': nx = rs.initial.x + dx; ny = rs.initial.y + dy; nw = rs.initial.w - dx; nh = rs.initial.h - dy; break;
      case 't':                             ny = rs.initial.y + dy;                         nh = rs.initial.h - dy; break;
      case 'tr':                             ny = rs.initial.y + dy; nw = rs.initial.w + dx; nh = rs.initial.h - dy; break;
      case 'r':                                                     nw = rs.initial.w + dx;                         break;
      case 'br':                                                     nw = rs.initial.w + dx; nh = rs.initial.h + dy; break;
      case 'b':                                                                               nh = rs.initial.h + dy; break;
      case 'bl': nx = rs.initial.x + dx;                             nw = rs.initial.w - dx; nh = rs.initial.h + dy; break;
      case 'l':  nx = rs.initial.x + dx;                             nw = rs.initial.w - dx;                         break;
    }
    // Maintain aspect ratio if Shift or originally shift-started
    if (e.shiftKey || rs.shift) {
      if (rs.handle.length === 2) {
        // Corner — use the larger delta
        var ratio = rs.aspect;
        if (Math.abs(dx) > Math.abs(dy)) { nh = nw / ratio; } else { nw = nh * ratio; }
        if (rs.handle.indexOf('l') >= 0) nx = rs.initial.x + rs.initial.w - nw;
        if (rs.handle.indexOf('t') >= 0) ny = rs.initial.y + rs.initial.h - nh;
      }
    }
    // Minimum size
    if (nw < 10) nw = 10;
    if (nh < 10) nh = 10;

    el.properties_json.x = Math.round(nx);
    el.properties_json.y = Math.round(ny);
    el.properties_json.width  = Math.round(nw);
    el.properties_json.height = Math.round(nh);
    _stUpdateElementDom(el);
    _stUpdateSelectionOverlay();
  }

  function _stOnResizeEnd(){
    document.removeEventListener('mousemove', _stOnResize);
    document.removeEventListener('mouseup', _stOnResizeEnd);
    if (EDT.resizeState && EDT.resizeState.moved) {
      _stSaveHistory();
      _stPersistElement(EDT.selected[0]);
      _stMarkDirty();
      _stRenderProps(); // refresh W/H inputs
    }
    EDT.resizeState = null;
  }

  // ── Rotate handle ──────────────────────────────────────────
  function _stStartRotate(e){
    if (EDT.selected.length !== 1) return;
    var el = _stGetEl(EDT.selected[0]); if (!el) return;
    if (el.properties_json && el.properties_json.locked) return;
    e.preventDefault();
    var p = el.properties_json || {};
    var cx = (p.x || 0) + (p.width || 200) / 2;
    var cy = (p.y || 0) + (p.height || 80) / 2;
    // Convert mouse coords → frame coords
    var fr = EDT.frame.getBoundingClientRect();
    var mx = (e.clientX - fr.left) / EDT.zoom;
    var my = (e.clientY - fr.top)  / EDT.zoom;
    var startAngle = Math.atan2(my - cy, mx - cx);
    var startRot   = p.rotation || 0;
    EDT.rotateState = { cx: cx, cy: cy, startAngle: startAngle, startRot: startRot, moved: false, shift: e.shiftKey };
    document.addEventListener('mousemove', _stOnRotate);
    document.addEventListener('mouseup', _stOnRotateEnd);
  }
  function _stOnRotate(e){
    var rs = EDT.rotateState; if (!rs) return;
    var el = _stGetEl(EDT.selected[0]); if (!el) return;
    rs.moved = true;
    var fr = EDT.frame.getBoundingClientRect();
    var mx = (e.clientX - fr.left) / EDT.zoom;
    var my = (e.clientY - fr.top)  / EDT.zoom;
    var angle = Math.atan2(my - rs.cy, mx - rs.cx);
    var deg = rs.startRot + (angle - rs.startAngle) * 180 / Math.PI;
    // Snap to 45deg if Shift
    if (e.shiftKey || rs.shift) deg = Math.round(deg / 45) * 45;
    el.properties_json.rotation = Math.round(deg);
    _stUpdateElementDom(el);
    _stUpdateSelectionOverlay();
  }
  function _stOnRotateEnd(){
    document.removeEventListener('mousemove', _stOnRotate);
    document.removeEventListener('mouseup', _stOnRotateEnd);
    if (EDT.rotateState && EDT.rotateState.moved) {
      _stSaveHistory();
      _stPersistElement(EDT.selected[0]);
      _stMarkDirty();
      _stRenderProps();
    }
    EDT.rotateState = null;
  }

  // ── Inline text editing ────────────────────────────────────
  function _stEnterTextEdit(id){
    var el = _stGetEl(id); if (!el || el.element_type !== 'text') return;
    var node = EDT.layer.querySelector('[data-id="' + id + '"] .st-text-content');
    if (!node) return;
    node.contentEditable = 'true';
    node.focus();
    EDT.inlineEditingId = id;
    // Place cursor at end
    var range = document.createRange();
    range.selectNodeContents(node); range.collapse(false);
    var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);

    var finish = function(){
      node.contentEditable = 'false';
      EDT.inlineEditingId = null;
      var old = el.properties_json.content || '';
      var nev = node.innerText || '';
      if (old !== nev) {
        _stSaveHistory();
        el.properties_json.content = nev;
        _stPersistElement(id);
        _stMarkDirty();
      }
      node.removeEventListener('blur', finish);
    };
    node.addEventListener('blur', finish);
  }

  function _stGetEl(id){
    var numId = Number(id);
    for (var i = 0; i < EDT.elements.length; i++) {
      if (Number(EDT.elements[i].id) === numId) return EDT.elements[i];
    }
    return null;
  }

  // ── Server persist for a single element ───────────────────
  function _stPersistElement(id){
    var el = _stGetEl(id); if (!el) return;
    _fetchJson('/studio/designs/' + EDT.design.id + '/elements/' + el.id, {
      method: 'PUT',
      body: JSON.stringify({
        properties_json: el.properties_json,
        layer_order: el.layer_order,
        element_type: el.element_type,
      }),
    }).catch(function(err){ /* swallow — auto-save retries */ });
  }

  // ── CONTINUED IN PART 3 ──

  // ═══════════════════════════════════════════════════════════════
  // PART 3 — Keyboard, history, snapping, zoom/pan, canvas wiring
  // ═══════════════════════════════════════════════════════════════

  function _stWireTopbar(){
    document.getElementById('st-back-btn').onclick = _stRequestExit;

    var nameInput = document.getElementById('st-design-name');
    var nameDeb;
    nameInput.oninput = function(e){
      clearTimeout(nameDeb);
      EDT.design.name = e.target.value;
      _stMarkDirty();
      nameDeb = setTimeout(function(){ _stAutoSave(); }, 800);
    };

    document.getElementById('st-undo').onclick = _stUndo;
    document.getElementById('st-redo').onclick = _stRedo;

    document.getElementById('st-resize-btn').onclick = _stOpenResizeModal;
    document.getElementById('st-publish-btn').onclick = function(){
      _toast('Publish to Social ships in Phase 5.', 'info');
    };

    // Download dropdown
    var dlBtn  = document.getElementById('st-download-btn');
    var dlMenu = document.getElementById('st-download-menu');
    dlBtn.onclick = function(e){ e.stopPropagation(); dlMenu.classList.toggle('hidden'); };
    document.addEventListener('click', function(){ dlMenu.classList.add('hidden'); });
    dlMenu.querySelectorAll('[data-fmt]').forEach(function(d){
      d.onclick = function(){ dlMenu.classList.add('hidden'); _stDownload(d.getAttribute('data-fmt')); };
    });

    // AI FAB
    document.getElementById('st-ai-fab').onclick = function(){
      document.getElementById('st-ai-drawer').classList.toggle('hidden');
    };
    document.getElementById('st-ai-close').onclick = function(){
      document.getElementById('st-ai-drawer').classList.add('hidden');
    };
    document.getElementById('st-ai-send').onclick = _stAiSend;
    document.getElementById('st-ai-input').addEventListener('keydown', function(e){
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); _stAiSend(); }
    });
    EDT.root.querySelectorAll('.st-ai-quick button').forEach(function(b){
      b.onclick = function(){ document.getElementById('st-ai-input').value = b.getAttribute('data-p'); _stAiSend(); };
    });
  }

  function _stWireLeftPanelTabs(){
    EDT.root.querySelectorAll('.st-ptab').forEach(function(t){
      t.onclick = function(){
        EDT.root.querySelectorAll('.st-ptab').forEach(function(x){ x.classList.remove('active'); });
        t.classList.add('active');
        EDT.activeLeftTab = t.getAttribute('data-tab');
        _stRenderLeftPanel(EDT.activeLeftTab);
      };
    });
  }

  function _stWireCanvas(){
    var area = EDT.canvasArea;
    // Click empty canvas → deselect
    area.addEventListener('mousedown', function(e){
      if (e.target.closest('.st-element') || e.target.closest('.st-selection-overlay')) return;
      if (e.target.closest('.st-canvas-toolbar') || e.target.closest('.st-layers-panel')) return;
      // Middle mouse or space+drag for pan
      if (e.button === 1 || EDT._spaceDown) {
        e.preventDefault();
        EDT.panState = { startX: e.clientX, startY: e.clientY, initial: { panX: EDT.panX, panY: EDT.panY } };
        document.addEventListener('mousemove', _stOnPan);
        document.addEventListener('mouseup', _stOnPanEnd);
        return;
      }
      // Marquee select
      _stStartMarquee(e);
    });

    // Zoom controls
    document.getElementById('st-zoom-in').onclick  = function(){ _stSetZoom(EDT.zoom * 1.25); };
    document.getElementById('st-zoom-out').onclick = function(){ _stSetZoom(EDT.zoom / 1.25); };
    document.getElementById('st-zoom-fit').onclick = _stFitToViewport;
    document.getElementById('st-grid-toggle').onclick = function(){
      EDT.gridEnabled = !EDT.gridEnabled;
      EDT.frame.classList.toggle('grid', EDT.gridEnabled);
    };
    document.getElementById('st-layers-toggle').onclick = function(){
      EDT.layersVisible = !EDT.layersVisible;
      document.getElementById('st-layers-panel').classList.toggle('hidden', !EDT.layersVisible);
      if (EDT.layersVisible) _stRenderLayers();
    };
    document.getElementById('st-layers-close').onclick = function(){
      EDT.layersVisible = false;
      document.getElementById('st-layers-panel').classList.add('hidden');
    };

    // Ctrl+wheel zoom at mouse pos
    area.addEventListener('wheel', function(e){
      if (!(e.ctrlKey || e.metaKey)) return;
      e.preventDefault();
      var delta = -e.deltaY / 500;
      _stZoomAtPoint(e.clientX, e.clientY, delta);
    }, { passive: false });
  }

  function _stStartMarquee(e){
    EDT._marqueeStartFrame = _stClientToFrame(e.clientX, e.clientY);
    var m = document.getElementById('st-marquee');
    m.classList.remove('hidden');
    m.style.left = EDT._marqueeStartFrame.x + 'px';
    m.style.top  = EDT._marqueeStartFrame.y + 'px';
    m.style.width = '0px'; m.style.height = '0px';
    document.addEventListener('mousemove', _stOnMarquee);
    document.addEventListener('mouseup', _stEndMarquee);
  }
  function _stOnMarquee(e){
    var cur = _stClientToFrame(e.clientX, e.clientY);
    var s = EDT._marqueeStartFrame;
    var l = Math.min(s.x, cur.x), t = Math.min(s.y, cur.y);
    var w = Math.abs(cur.x - s.x), h = Math.abs(cur.y - s.y);
    var m = document.getElementById('st-marquee');
    m.style.left = l + 'px'; m.style.top = t + 'px';
    m.style.width = w + 'px'; m.style.height = h + 'px';
  }
  function _stEndMarquee(e){
    document.removeEventListener('mousemove', _stOnMarquee);
    document.removeEventListener('mouseup', _stEndMarquee);
    var m = document.getElementById('st-marquee');
    var rect = { l: parseFloat(m.style.left), t: parseFloat(m.style.top), w: parseFloat(m.style.width), h: parseFloat(m.style.height) };
    m.classList.add('hidden');
    if (rect.w < 5 || rect.h < 5) { _stDeselectAll(); return; }
    // Select all elements intersecting
    var ids = [];
    EDT.elements.forEach(function(el){
      var p = el.properties_json || {};
      var ex = p.x || 0, ey = p.y || 0, ew = p.width || 0, eh = p.height || 0;
      if (ex < rect.l + rect.w && ex + ew > rect.l && ey < rect.t + rect.h && ey + eh > rect.t) {
        ids.push(Number(el.id));
      }
    });
    EDT.selected = ids;
    _stSyncSelectionClasses();
    _stUpdateSelectionOverlay();
    _stRenderProps();
  }

  function _stClientToFrame(cx, cy){
    var fr = EDT.frame.getBoundingClientRect();
    return { x: (cx - fr.left) / EDT.zoom, y: (cy - fr.top) / EDT.zoom };
  }

  // ── Pan ────────────────────────────────────────────────────
  function _stOnPan(e){
    if (!EDT.panState) return;
    var dx = e.clientX - EDT.panState.startX;
    var dy = e.clientY - EDT.panState.startY;
    EDT.panX = EDT.panState.initial.panX + dx;
    EDT.panY = EDT.panState.initial.panY + dy;
    _stApplyTransform();
  }
  function _stOnPanEnd(){
    document.removeEventListener('mousemove', _stOnPan);
    document.removeEventListener('mouseup', _stOnPanEnd);
    EDT.panState = null;
  }

  // ── Zoom ────────────────────────────────────────────────────
  function _stSetZoom(z){
    z = Math.max(0.1, Math.min(4, z));
    EDT.zoom = z;
    _stApplyTransform();
    var lbl = document.getElementById('st-zoom-label');
    if (lbl) lbl.textContent = Math.round(z * 100) + '%';
  }
  function _stZoomAtPoint(clientX, clientY, delta){
    var oldZoom = EDT.zoom;
    var newZoom = Math.max(0.1, Math.min(4, oldZoom * (1 + delta)));
    // Keep the mouse-point stable while zooming
    var rect = EDT.canvasArea.getBoundingClientRect();
    var mx = clientX - rect.left;
    var my = clientY - rect.top;
    EDT.panX = mx - (mx - EDT.panX) * (newZoom / oldZoom);
    EDT.panY = my - (my - EDT.panY) * (newZoom / oldZoom);
    _stSetZoom(newZoom);
  }
  function _stApplyTransform(){
    if (!EDT.transform) return;
    EDT.transform.style.transform = 'translate(' + EDT.panX + 'px,' + EDT.panY + 'px) scale(' + EDT.zoom + ')';
    EDT.transform.style.transformOrigin = '0 0';
  }
  function _stFitToViewport(){
    var area = EDT.canvasArea;
    var w = EDT.design.canvas_width  || 1080;
    var h = EDT.design.canvas_height || 1080;
    var pad = 80;
    var areaW = area.clientWidth - pad;
    var areaH = area.clientHeight - pad - 40; // 40 for bottom toolbar
    var sx = areaW / w, sy = areaH / h;
    var z = Math.min(sx, sy, 1);
    EDT.zoom = z;
    // Center
    EDT.panX = (area.clientWidth  - w * z) / 2;
    EDT.panY = (area.clientHeight - h * z - 40) / 2 + 20;
    _stApplyTransform();
    var lbl = document.getElementById('st-zoom-label'); if (lbl) lbl.textContent = Math.round(z * 100) + '%';
  }

  // ── Keyboard shortcuts ─────────────────────────────────────
  function _stWireGlobalKeys(){
    if (window._stKeysBound) return;
    window._stKeysBound = true;
    window.addEventListener('keydown', _stOnKey);
    window.addEventListener('keyup', function(e){ if (e.code === 'Space') EDT._spaceDown = false; });
  }
  function _stOnKey(e){
    if (!document.getElementById('st-editor')) return;
    // Don't intercept when editing text in inputs or contenteditable
    var tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || e.target.contentEditable === 'true') return;

    if (e.code === 'Space' && !EDT._spaceDown) { EDT._spaceDown = true; return; }

    // Ctrl/Cmd shortcuts
    if (e.ctrlKey || e.metaKey) {
      if (e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); _stUndo(); return; }
      if (e.key.toLowerCase() === 'z' &&  e.shiftKey) { e.preventDefault(); _stRedo(); return; }
      if (e.key.toLowerCase() === 'y')                { e.preventDefault(); _stRedo(); return; }
      if (e.key.toLowerCase() === 'c') { _stCopy(); return; }
      if (e.key.toLowerCase() === 'v') { _stPaste(); return; }
      if (e.key.toLowerCase() === 'd') { e.preventDefault(); _stDuplicate(); return; }
      if (e.key.toLowerCase() === 'a') { e.preventDefault(); _stSelectAll(); return; }
      if (e.key === '[')               { e.preventDefault(); _stSendBackward(); return; }
      if (e.key === ']')               { e.preventDefault(); _stBringForward(); return; }
      if (e.key.toLowerCase() === 's') { e.preventDefault(); _stAutoSave(); return; }
      if (e.key === '0')               { e.preventDefault(); _stFitToViewport(); return; }
      if (e.key === '1')               { e.preventDefault(); _stSetZoom(1); return; }
      if (e.key === '=')               { e.preventDefault(); _stSetZoom(EDT.zoom * 1.25); return; }
      if (e.key === '-')               { e.preventDefault(); _stSetZoom(EDT.zoom / 1.25); return; }
      return;
    }

    if (e.key === 'Escape') { _stDeselectAll(); return; }
    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); _stDeleteSelected(); return; }

    // Nudge
    if (e.key.startsWith('Arrow') && EDT.selected.length) {
      e.preventDefault();
      var amt = e.shiftKey ? 10 : 1;
      var dx = 0, dy = 0;
      if (e.key === 'ArrowUp')    dy = -amt;
      if (e.key === 'ArrowDown')  dy =  amt;
      if (e.key === 'ArrowLeft')  dx = -amt;
      if (e.key === 'ArrowRight') dx =  amt;
      EDT.selected.forEach(function(id){
        var el = _stGetEl(id); if (!el) return;
        el.properties_json.x = (el.properties_json.x || 0) + dx;
        el.properties_json.y = (el.properties_json.y || 0) + dy;
        _stUpdateElementDom(el);
        _stPersistElement(id);
      });
      _stUpdateSelectionOverlay();
      _stMarkDirty();
      // debounce a history save
      clearTimeout(EDT._nudgeDeb);
      EDT._nudgeDeb = setTimeout(_stSaveHistory, 500);
      return;
    }

    // Quick-add shortcuts
    if (e.key === 't' || e.key === 'T') { _stAddTextElement({ content: 'Text', font_size: 48 }); return; }
    if (e.key === 'r' || e.key === 'R') { _stAddShape('rectangle'); return; }
    if (e.key === 'c' || e.key === 'C') { _stAddShape('circle'); return; }
    if (e.key === 's' || e.key === 'S') { EDT.snapEnabled = !EDT.snapEnabled; _toast('Snap ' + (EDT.snapEnabled ? 'on' : 'off'), 'info'); return; }
  }

  // ── History ────────────────────────────────────────────────
  function _stInitHistory(){
    EDT.history = [_stSnapshot()];
    EDT.historyIndex = 0;
    _stSyncUndoRedo();
  }
  function _stSnapshot(){
    return {
      elements: EDT.elements.map(function(e){ return { id: e.id, element_type: e.element_type, layer_order: e.layer_order, properties_json: JSON.parse(JSON.stringify(e.properties_json || {})) }; }),
      design_meta: { name: EDT.design.name, background_type: EDT.design.background_type, background_value: EDT.design.background_value, canvas_width: EDT.design.canvas_width, canvas_height: EDT.design.canvas_height },
    };
  }
  function _stSaveHistory(){
    EDT.history = EDT.history.slice(0, EDT.historyIndex + 1);
    EDT.history.push(_stSnapshot());
    if (EDT.history.length > 50) EDT.history.shift();
    EDT.historyIndex = EDT.history.length - 1;
    _stSyncUndoRedo();
    clearTimeout(EDT.saveHistoryDebounce);
    EDT.saveHistoryDebounce = setTimeout(function(){
      _fetchJson('/studio/designs/' + EDT.design.id + '/history', { method:'POST', body: JSON.stringify({ snapshot: _stSnapshot() }) }).catch(function(){});
    }, 2000);
  }
  function _stUndo(){
    if (EDT.historyIndex <= 0) return;
    EDT.historyIndex--;
    _stRestoreSnapshot(EDT.history[EDT.historyIndex]);
    _stSyncUndoRedo();
  }
  function _stRedo(){
    if (EDT.historyIndex >= EDT.history.length - 1) return;
    EDT.historyIndex++;
    _stRestoreSnapshot(EDT.history[EDT.historyIndex]);
    _stSyncUndoRedo();
  }
  function _stSyncUndoRedo(){
    var u = document.getElementById('st-undo'); var r = document.getElementById('st-redo');
    if (u) u.disabled = EDT.historyIndex <= 0;
    if (r) r.disabled = EDT.historyIndex >= EDT.history.length - 1;
  }
  function _stRestoreSnapshot(s){
    // Merge snapshot into live elements, preserving ids
    s.elements.forEach(function(se){
      var live = _stGetEl(se.id);
      if (live) {
        live.layer_order = se.layer_order;
        live.element_type = se.element_type;
        live.properties_json = JSON.parse(JSON.stringify(se.properties_json));
      }
    });
    if (s.design_meta) {
      EDT.design.name = s.design_meta.name;
      EDT.design.background_type  = s.design_meta.background_type;
      EDT.design.background_value = s.design_meta.background_value;
      document.getElementById('st-design-name').value = s.design_meta.name || '';
    }
    _stApplyBackground();
    _stRenderAllElements();
    _stSyncSelectionClasses();
    _stUpdateSelectionOverlay();
    _stRenderProps();
    _stRenderLayers();
    // Persist each element
    EDT.elements.forEach(function(e){ _stPersistElement(e.id); });
    _stMarkDirty();
  }

  // ── Snapping ───────────────────────────────────────────────
  function _stSnapX(x, width, excludeId){
    var w = EDT.design.canvas_width;
    var snapPoints = [0, w / 2, w, w / 2 - width / 2, w - width];
    EDT.elements.forEach(function(e){
      if (Number(e.id) === Number(excludeId)) return;
      var p = e.properties_json || {};
      var ex = p.x || 0; var ew = p.width || 0;
      snapPoints.push(ex, ex + ew, ex + ew / 2 - width / 2);
    });
    var threshold = 5 / EDT.zoom;
    for (var i = 0; i < snapPoints.length; i++) {
      var pt = snapPoints[i];
      if (Math.abs(x - pt) < threshold)         { _stShowGuideV(pt); return pt; }
      if (Math.abs(x + width - pt) < threshold) { _stShowGuideV(pt); return pt - width; }
      if (Math.abs(x + width / 2 - pt) < threshold) { _stShowGuideV(pt); return pt - width / 2; }
    }
    _stClearGuidesH(false);
    return x;
  }
  function _stSnapY(y, height, excludeId){
    var h = EDT.design.canvas_height;
    var snapPoints = [0, h / 2, h, h / 2 - height / 2, h - height];
    EDT.elements.forEach(function(e){
      if (Number(e.id) === Number(excludeId)) return;
      var p = e.properties_json || {};
      var ey = p.y || 0; var eh = p.height || 0;
      snapPoints.push(ey, ey + eh, ey + eh / 2 - height / 2);
    });
    var threshold = 5 / EDT.zoom;
    for (var i = 0; i < snapPoints.length; i++) {
      var pt = snapPoints[i];
      if (Math.abs(y - pt) < threshold)          { _stShowGuideH(pt); return pt; }
      if (Math.abs(y + height - pt) < threshold) { _stShowGuideH(pt); return pt - height; }
      if (Math.abs(y + height / 2 - pt) < threshold) { _stShowGuideH(pt); return pt - height / 2; }
    }
    _stClearGuidesH(true);
    return y;
  }
  function _stShowGuideV(x){
    var g = document.getElementById('st-guides'); if (!g) return;
    var existing = g.querySelector('.v'); if (existing) existing.style.left = x + 'px';
    else g.insertAdjacentHTML('beforeend', '<div class="st-guide v" style="left:' + x + 'px"></div>');
  }
  function _stShowGuideH(y){
    var g = document.getElementById('st-guides'); if (!g) return;
    var existing = g.querySelector('.h'); if (existing) existing.style.top = y + 'px';
    else g.insertAdjacentHTML('beforeend', '<div class="st-guide h" style="top:' + y + 'px"></div>');
  }
  function _stClearGuides(){ var g = document.getElementById('st-guides'); if (g) g.innerHTML = ''; }
  function _stClearGuidesH(vertical){
    var g = document.getElementById('st-guides'); if (!g) return;
    var sel = vertical ? '.v' : '.h'; // actually invert — see caller
    g.querySelectorAll(sel).forEach(function(n){ n.remove(); });
  }

  // ── CONTINUED IN PART 4 ──

  // ═══════════════════════════════════════════════════════════════
  // PART 4 — Left panel tabs + Right properties panel
  // ═══════════════════════════════════════════════════════════════

  function _stRenderLeftPanel(tab){
    var h = EDT.leftPanel; if (!h) return;
    if (tab === 'design')   return _stRenderDesignTab(h);
    if (tab === 'elements') return _stRenderElementsTab(h);
    if (tab === 'text')     return _stRenderTextTab(h);
    if (tab === 'photos')   return _stRenderPhotosTab(h);
    if (tab === 'brand')    return _stRenderBrandTab(h);
    if (tab === 'bg')       return _stRenderBgTab(h);
  }

  function _stRenderDesignTab(h){
    h.innerHTML = '<div class="st-empty-small">Loading templates...</div>';
    _fetchJson('/studio/templates/html').then(function(d){
      var tpls = d.templates || [];
      EDT.templates = tpls;
      h.innerHTML =
        '<div class="st-panel-grid">' +
          tpls.slice(0, 40).map(function(t){
            return '<div class="st-tpl-thumb" data-slug="' + _esc(t.slug) + '" data-name="' + _esc(t.name) + '">' +
                     '<iframe src="' + _esc(t.preview_url) + '" scrolling="no" loading="lazy"></iframe>' +
                     '<div class="st-tpl-name">' + _esc(t.name) + '</div>' +
                   '</div>';
          }).join('') +
        '</div>';
      h.querySelectorAll('.st-tpl-thumb').forEach(function(n){
        n.onclick = async function(){
          var ok = await (window.luConfirm ? window.luConfirm('Apply this template? Your current design will be replaced.', 'Apply template', 'Apply', 'Cancel') : Promise.resolve(confirm('Apply template?')));
          if (!ok) return;
          _stApplyTemplate(n.getAttribute('data-slug'), n.getAttribute('data-name'));
        };
      });
    });
  }

  function _stApplyTemplate(slug, name){
    // Create a fresh design from this template, then open it. Keeps user's
    // history by producing a new row instead of overwriting.
    _fetchJson('/studio/designs', {
      method:'POST', body: JSON.stringify({ template_slug: slug, name: name })
    }).then(function(d){
      var newId = d.design_id || (d.design && d.design.id);
      if (!newId) throw new Error('Template apply failed');
      _toast('Template applied as new design', 'success');
      EDT.dirty = false; // prevent beforeunload from blocking
      _stOpenImageEditor(newId);
    }).catch(function(e){ _toast(e.message, 'error'); });
  }

  function _stRenderElementsTab(h){
    h.innerHTML =
      '<div class="st-panel-section-label">Shapes</div>' +
      '<div class="st-panel-grid shapes">' +
        Object.keys(BRAND_SHAPES).map(function(sh){
          return '<button class="st-shape-btn" data-shape="' + sh + '" title="' + sh + '">' +
                   '<svg viewBox="0 0 100 100" style="width:36px;height:36px;color:#6C5CE7">' + BRAND_SHAPES[sh] + '</svg>' +
                 '</button>';
        }).join('') +
      '</div>' +
      '<div class="st-panel-section-label">Lines</div>' +
      '<div class="st-panel-grid shapes">' +
        '<button class="st-shape-btn" data-line="straight"><div style="width:60px;height:2px;background:#0F172A"></div></button>' +
        '<button class="st-shape-btn" data-line="dashed"><div style="width:60px;height:2px;background:repeating-linear-gradient(to right,#0F172A 0 6px,transparent 6px 12px)"></div></button>' +
      '</div>' +
      '<div class="st-panel-section-label">Icons</div>' +
      '<div class="st-panel-grid icons">' +
        '\u2b50 \u2764 \u2728 \u2753 \u2755 \u2b50 \u2139 \u26a0 \u2600 \u2601 \u2614 \u2615 \u2708 \u2693 \u26f5 \u26f7 \u26a1 \u2604 \u2615 \u2b55 \u274c \u2705 \u270f \u2712 \u2702 \u2697 \u2699 \u2764'
          .split(' ').map(function(icon){
            return '<button class="st-icon-insert" data-icon="' + _esc(icon) + '">' + _esc(icon) + '</button>';
          }).join('') +
      '</div>';

    h.querySelectorAll('.st-shape-btn').forEach(function(b){
      b.onclick = function(){
        var shape = b.getAttribute('data-shape');
        var line  = b.getAttribute('data-line');
        if (shape) _stAddShape(shape);
        else if (line) _stAddLine(line);
      };
    });
    h.querySelectorAll('.st-icon-insert').forEach(function(b){
      b.onclick = function(){
        _stAddElement('icon', { content: b.getAttribute('data-icon'), font_size: 120, x: 100, y: 100, width: 160, height: 160 });
      };
    });
  }

  function _stRenderTextTab(h){
    h.innerHTML =
      '<button class="st-btn-wide" data-role="heading">+ Add heading</button>' +
      '<button class="st-btn-wide" data-role="subheading">+ Add subheading</button>' +
      '<button class="st-btn-wide" data-role="body">+ Add body text</button>' +
      '<div class="st-panel-section-label">Text style presets</div>' +
      '<div class="st-text-presets">' +
        [
          { name:'Bold Impact',    font:'Syne',           weight:900, size:72, transform:'uppercase' },
          { name:'Elegant Serif',  font:'Cormorant Garamond', weight:300, size:64, style:'italic' },
          { name:'Modern Sans',    font:'Inter',          weight:600, size:48 },
          { name:'Playful',        font:'Nunito',         weight:700, size:48 },
          { name:'Corporate',      font:'Work Sans',      weight:500, size:40 },
          { name:'Display',        font:'Unbounded',      weight:700, size:56 },
          { name:'Editorial',      font:'Playfair Display', weight:400, size:56, style:'italic' },
          { name:'Minimal',        font:'DM Sans',        weight:300, size:36, transform:'uppercase' },
        ].map(function(p){
          return '<button class="st-text-preset" data-preset=\'' + _esc(JSON.stringify(p)) + '\' style="font-family:' + p.font + ';font-weight:' + p.weight + ';' + (p.style?'font-style:'+p.style+';':'') + (p.transform?'text-transform:'+p.transform+';':'') + '">' + _esc(p.name) + '</button>';
        }).join('') +
      '</div>';
    h.querySelectorAll('[data-role]').forEach(function(b){
      b.onclick = function(){
        var r = b.getAttribute('data-role');
        var spec = r === 'heading'    ? { content:'Your heading',    font_family:'Syne',   font_weight:700, font_size:72, text_align:'left' }
                  : r === 'subheading' ? { content:'Your subheading', font_family:'DM Sans', font_weight:600, font_size:40, text_align:'left' }
                  :                       { content:'Body text',      font_family:'DM Sans', font_weight:400, font_size:20, text_align:'left' };
        _stAddTextElement(spec);
      };
    });
    h.querySelectorAll('.st-text-preset').forEach(function(b){
      b.onclick = function(){
        var p = JSON.parse(b.getAttribute('data-preset'));
        _stAddTextElement({
          content: p.name.toUpperCase(),
          font_family: p.font,
          font_weight: String(p.weight),
          font_size: p.size,
          font_style: p.style || 'normal',
          text_transform: p.transform || 'none',
        });
      };
    });
  }

  function _stRenderPhotosTab(h){
    h.innerHTML =
      '<button class="st-btn-wide st-btn-outline" id="st-upload-photo">Upload image</button>' +
      '<button class="st-btn-wide st-btn-outline" id="st-ai-image">\u2726 Generate with AI (Phase 4)</button>' +
      '<div class="st-panel-section-label">Workspace media</div>' +
      '<div id="st-media-grid" class="st-panel-grid media"><div class="st-empty-small">Loading...</div></div>';
    h.querySelector('#st-upload-photo').onclick = function(){
      if (typeof window.openMediaPicker === 'function') {
        window.openMediaPicker({ type:'image', context:'studio' }, function(file){
          if (file && file.url) _stAddImageElement(file.url);
        });
      } else {
        _toast('Media picker unavailable', 'error');
      }
    };
    h.querySelector('#st-ai-image').onclick = function(){
      _toast('AI image generation ships in Phase 4.', 'info');
    };
    // Load media library via existing endpoint (if available)
    _fetchJson('/media?type=image&limit=30').then(function(d){
      var items = (d && (d.media || d.files || d.items)) || [];
      var g = h.querySelector('#st-media-grid');
      if (!items.length) { g.innerHTML = '<div class="st-empty-small">No images in workspace library yet.</div>'; return; }
      g.innerHTML = items.map(function(m){
        var url = m.url || m.image_url || m.path;
        return '<button class="st-media-thumb" data-url="' + _esc(url) + '"><img src="' + _esc(url) + '" alt=""/></button>';
      }).join('');
      g.querySelectorAll('.st-media-thumb').forEach(function(b){
        b.onclick = function(){ _stAddImageElement(b.getAttribute('data-url')); };
      });
    }).catch(function(){ h.querySelector('#st-media-grid').innerHTML = '<div class="st-empty-small">Library unavailable.</div>'; });
  }

  function _stRenderBrandTab(h){
    var bk = EDT.brandKit || {};
    var colorRow = function(key, color, label){
      return '<button class="st-brand-color" data-color="' + _esc(color || '') + '" title="' + _esc(label) + '" style="background:' + _esc(color || '#EEE') + '"></button>';
    };
    h.innerHTML =
      '<div class="st-panel-section-label">Brand colors</div>' +
      '<div class="st-brand-colors">' +
        colorRow('primary',    bk.primary_color,    'Primary') +
        colorRow('secondary',  bk.secondary_color,  'Secondary') +
        colorRow('accent',     bk.accent_color,     'Accent') +
        colorRow('background', bk.background_color, 'Background') +
        colorRow('text',       bk.text_color,       'Text') +
      '</div>' +
      '<div class="st-panel-section-label">Fonts</div>' +
      '<div class="st-brand-font">Heading: <strong>' + _esc(bk.heading_font || 'Syne') + '</strong></div>' +
      '<div class="st-brand-font">Body: <strong>' + _esc(bk.body_font || 'DM Sans') + '</strong></div>' +
      (bk.logo_url ? '<div class="st-panel-section-label">Logo</div><button class="st-brand-logo"><img src="' + _esc(bk.logo_url) + '"/></button>' : '') +
      '<button class="st-btn-wide" id="st-apply-brand">Apply brand to design</button>' +
      '<button class="st-btn-wide st-btn-outline" id="st-edit-brand">Edit brand kit</button>';

    h.querySelectorAll('.st-brand-color').forEach(function(b){
      b.onclick = function(){
        var c = b.getAttribute('data-color');
        if (!c) return;
        _stAddShape('rectangle', { fill: c, width: 400, height: 400 });
      };
    });
    var logo = h.querySelector('.st-brand-logo');
    if (logo) logo.onclick = function(){ _stAddImageElement(bk.logo_url); };
    h.querySelector('#st-apply-brand').onclick = _stApplyBrand;
    h.querySelector('#st-edit-brand').onclick = _stEditBrandKit;
  }

  function _stApplyBrand(){
    var bk = EDT.brandKit || {};
    if (!bk.primary_color) return;
    _stSaveHistory();
    EDT.elements.forEach(function(el){
      if (el.element_type === 'text') {
        el.properties_json.color = bk.text_color || el.properties_json.color;
        // First heading gets heading_font
        if ((el.properties_json.font_size || 0) >= 48) {
          el.properties_json.font_family = bk.heading_font || 'Syne';
        } else {
          el.properties_json.font_family = bk.body_font || 'DM Sans';
        }
      } else if (el.element_type === 'shape') {
        el.properties_json.fill = bk.primary_color;
      }
      _stUpdateElementDom(el);
      _stPersistElement(el.id);
    });
    _stMarkDirty();
    _toast('Brand applied', 'success');
  }

  function _stEditBrandKit(){
    var bk = EDT.brandKit || {};
    var bd = _mktModalEditor('Edit brand kit', [
      ['primary_color',    'Primary color',    'color', bk.primary_color],
      ['secondary_color',  'Secondary color',  'color', bk.secondary_color],
      ['accent_color',     'Accent color',     'color', bk.accent_color],
      ['background_color', 'Background color', 'color', bk.background_color],
      ['text_color',       'Text color',       'color', bk.text_color],
      ['heading_font',     'Heading font',     'select', bk.heading_font, FONTS],
      ['body_font',        'Body font',        'select', bk.body_font, FONTS],
      ['brand_name',       'Brand name',       'text',  bk.brand_name],
      ['logo_url',         'Logo URL',         'text',  bk.logo_url],
    ], async function(values){
      try { await _fetchJson('/studio/brand-kit', { method:'PUT', body: JSON.stringify(values) });
            EDT.brandKit = Object.assign({}, EDT.brandKit, values);
            _stRenderLeftPanel('brand');
            _toast('Brand kit saved', 'success'); }
      catch(e) { _toast(e.message, 'error'); }
    });
  }

  function _stRenderBgTab(h){
    var d = EDT.design || {};
    var bgVal = d.background_type === 'color' ? (d.background_value || '#FFFFFF') : '#FFFFFF';
    h.innerHTML =
      '<div class="st-panel-section-label">Solid color</div>' +
      '<input type="color" id="st-bg-color" value="' + _esc(bgVal) + '"/>' +
      '<div class="st-panel-section-label">Gradients</div>' +
      '<div class="st-panel-grid gradients">' +
        GRADIENT_PRESETS.map(function(g, i){
          return '<button class="st-grad-btn" data-idx="' + i + '" style="background:linear-gradient(135deg,' + g[0] + ',' + g[1] + ')"></button>';
        }).join('') +
      '</div>' +
      '<button class="st-btn-wide" id="st-bg-image">Choose image...</button>' +
      '<button class="st-btn-wide st-btn-outline" id="st-bg-clear">Transparent</button>';

    h.querySelector('#st-bg-color').oninput = function(e){
      _stSaveHistory();
      EDT.design.background_type = 'color';
      EDT.design.background_value = e.target.value;
      _stApplyBackground(); _stMarkDirty(); _stAutoSaveSoon();
    };
    h.querySelectorAll('.st-grad-btn').forEach(function(b){
      b.onclick = function(){
        var g = GRADIENT_PRESETS[Number(b.getAttribute('data-idx'))];
        _stSaveHistory();
        EDT.design.background_type = 'gradient';
        EDT.design.background_value = JSON.stringify({ angle: 135, colors: g });
        _stApplyBackground(); _stMarkDirty(); _stAutoSaveSoon();
      };
    });
    h.querySelector('#st-bg-image').onclick = function(){
      if (typeof window.openMediaPicker === 'function') {
        window.openMediaPicker({ type:'image', context:'studio' }, function(file){
          if (file && file.url) {
            _stSaveHistory();
            EDT.design.background_type = 'image';
            EDT.design.background_value = file.url;
            _stApplyBackground(); _stMarkDirty(); _stAutoSaveSoon();
          }
        });
      }
    };
    h.querySelector('#st-bg-clear').onclick = function(){
      _stSaveHistory();
      EDT.design.background_type = 'color';
      EDT.design.background_value = 'transparent';
      _stApplyBackground(); _stMarkDirty(); _stAutoSaveSoon();
    };
  }

  // ── RIGHT PANEL — PROPERTIES ───────────────────────────────
  function _stRenderProps(){
    var holder = EDT.propsPanel; if (!holder) return;
    if (!EDT.selected.length) { holder.innerHTML = _stPropsEmptyHtml(); _stWirePropsEmpty(); return; }
    if (EDT.selected.length > 1) { holder.innerHTML = _stPropsMultiHtml(); _stWirePropsMulti(); return; }
    var el = _stGetEl(EDT.selected[0]); if (!el) { holder.innerHTML = ''; return; }
    if (el.element_type === 'text')  { holder.innerHTML = _stPropsTextHtml(el);  _stWirePropsText(el);  }
    else if (el.element_type === 'image') { holder.innerHTML = _stPropsImageHtml(el); _stWirePropsImage(el); }
    else if (el.element_type === 'shape') { holder.innerHTML = _stPropsShapeHtml(el); _stWirePropsShape(el); }
    else                              { holder.innerHTML = _stPropsCommonHtml(el); _stWirePropsCommon(el); }
  }

  function _stPropsEmptyHtml(){
    var d = EDT.design || {};
    return (
      '<div class="st-props-group"><div class="st-props-title">Canvas</div>' +
        '<div class="st-props-row"><label>Size</label><span>' + d.canvas_width + '\u00d7' + d.canvas_height + 'px</span></div>' +
        '<div class="st-props-row"><label>Format</label><span>' + _esc(d.format || '') + '</span></div>' +
      '</div>' +
      '<div class="st-props-group"><div class="st-props-title">Background</div>' +
        '<button class="st-btn-wide" id="st-open-bg-tab">Open Background panel</button>' +
      '</div>' +
      '<div class="st-props-tip">Click an element to edit it. Press <strong>T</strong> to add text, <strong>R</strong> rect, <strong>C</strong> circle.</div>'
    );
  }
  function _stWirePropsEmpty(){
    var b = document.getElementById('st-open-bg-tab');
    if (b) b.onclick = function(){
      EDT.root.querySelectorAll('.st-ptab').forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-tab') === 'bg'); });
      EDT.activeLeftTab = 'bg'; _stRenderLeftPanel('bg');
    };
  }

  function _stPropsMultiHtml(){
    return (
      '<div class="st-props-group"><div class="st-props-title">' + EDT.selected.length + ' selected</div>' +
        '<div class="st-align-row">' +
          ['align-l','align-ch','align-r','align-t','align-cv','align-b'].map(function(a){
            var labels = { 'align-l':'\u2b0c','align-ch':'\u2b1a','align-r':'\u2b0e','align-t':'\u2b06','align-cv':'\u2b1b','align-b':'\u2b07' };
            return '<button data-act="' + a + '" title="' + a + '">' + labels[a] + '</button>';
          }).join('') +
        '</div>' +
        '<button class="st-btn-wide" data-act="distribute-h">Distribute horizontally</button>' +
        '<button class="st-btn-wide" data-act="distribute-v">Distribute vertically</button>' +
        '<button class="st-btn-wide st-btn-danger" data-act="delete">Delete all</button>' +
      '</div>'
    );
  }
  function _stWirePropsMulti(){
    EDT.propsPanel.querySelectorAll('[data-act]').forEach(function(b){
      b.onclick = function(){ _stDoAlign(b.getAttribute('data-act')); };
    });
  }

  function _stPropsCommonRow(el){
    var p = el.properties_json || {};
    return (
      '<div class="st-props-group">' +
        '<div class="st-props-title">Transform</div>' +
        '<div class="st-props-row"><label>X</label><input type="number" data-k="x" value="' + (p.x || 0) + '"/>' +
          '<label>Y</label><input type="number" data-k="y" value="' + (p.y || 0) + '"/></div>' +
        '<div class="st-props-row"><label>W</label><input type="number" data-k="width" value="' + (p.width || 0) + '"/>' +
          '<label>H</label><input type="number" data-k="height" value="' + (p.height || 0) + '"/></div>' +
        '<div class="st-props-row"><label>Rotation</label><input type="number" data-k="rotation" value="' + (p.rotation || 0) + '"/></div>' +
        '<div class="st-props-row"><label>Opacity</label><input type="range" min="0" max="1" step="0.05" data-k="opacity" value="' + (p.opacity != null ? p.opacity : 1) + '"/></div>' +
      '</div>' +
      '<div class="st-props-group">' +
        '<div class="st-props-title">Order</div>' +
        '<div class="st-align-row">' +
          '<button data-act="back">To back</button>' +
          '<button data-act="backward">Back</button>' +
          '<button data-act="forward">Forward</button>' +
          '<button data-act="front">To front</button>' +
        '</div>' +
        '<div class="st-props-row"><label>Locked</label><input type="checkbox" data-k="locked"' + (p.locked ? ' checked' : '') + '/></div>' +
        '<div class="st-props-row"><label>Hidden</label><input type="checkbox" data-k="hidden"' + (p.hidden ? ' checked' : '') + '/></div>' +
        '<button class="st-btn-wide" data-act="duplicate">Duplicate</button>' +
        '<button class="st-btn-wide st-btn-danger" data-act="delete">Delete</button>' +
      '</div>'
    );
  }

  function _stWirePropsCommon(el){
    EDT.propsPanel.querySelectorAll('[data-k]').forEach(function(inp){
      var k = inp.getAttribute('data-k');
      inp.oninput = function(){
        var v = inp.type === 'checkbox' ? inp.checked : (inp.type === 'number' ? parseFloat(inp.value) : inp.value);
        if (inp.type === 'range') v = parseFloat(inp.value);
        el.properties_json[k] = v;
        _stUpdateElementDom(el);
        _stUpdateSelectionOverlay();
        _stMarkDirty();
        clearTimeout(inp._deb);
        inp._deb = setTimeout(function(){ _stPersistElement(el.id); _stSaveHistory(); }, 500);
      };
    });
    EDT.propsPanel.querySelectorAll('[data-act]').forEach(function(b){
      b.onclick = function(){
        var act = b.getAttribute('data-act');
        if (act === 'duplicate') return _stDuplicate();
        if (act === 'delete')    return _stDeleteSelected();
        if (act === 'forward')   return _stBringForward();
        if (act === 'backward')  return _stSendBackward();
        if (act === 'front')     return _stBringToFront();
        if (act === 'back')      return _stSendToBack();
      };
    });
  }

  function _stPropsCommonHtml(el){
    return '<div class="st-props-group"><div class="st-props-title">Element</div></div>' + _stPropsCommonRow(el);
  }

  function _stPropsTextHtml(el){
    var p = el.properties_json || {};
    return (
      '<div class="st-props-group">' +
        '<div class="st-props-title">Text</div>' +
        '<div class="st-props-row"><label>Font</label><select data-k="font_family">' +
          FONTS.map(function(f){ return '<option value="' + f + '"' + (p.font_family === f ? ' selected' : '') + '>' + f + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="st-props-row"><label>Size</label><input type="number" data-k="font_size" min="8" max="400" value="' + (p.font_size || 32) + '"/></div>' +
        '<div class="st-props-row"><label>Weight</label><select data-k="font_weight">' +
          ['300','400','500','600','700','800','900'].map(function(w){ return '<option value="' + w + '"' + (String(p.font_weight) === w ? ' selected' : '') + '>' + w + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="st-props-row"><label>Color</label><input type="color" data-k="color" value="' + _esc(p.color || '#000000') + '"/></div>' +
        '<div class="st-align-row">' +
          ['left','center','right','justify'].map(function(a){ return '<button data-style="text-align" data-val="' + a + '"' + (p.text_align === a ? ' class="active"' : '') + '>' + a[0].toUpperCase() + '</button>'; }).join('') +
        '</div>' +
        '<div class="st-align-row">' +
          '<button data-toggle="bold"' + (p.font_weight == 700 || p.font_weight === '700' ? ' class="active"' : '') + '><b>B</b></button>' +
          '<button data-toggle="italic"' + (p.font_style === 'italic' ? ' class="active"' : '') + '><i>I</i></button>' +
          '<button data-toggle="underline"' + ((p.text_decoration || '').indexOf('underline') >= 0 ? ' class="active"' : '') + '><u>U</u></button>' +
          '<button data-toggle="line-through"' + ((p.text_decoration || '').indexOf('line-through') >= 0 ? ' class="active"' : '') + '>S</button>' +
        '</div>' +
        '<div class="st-props-row"><label>Line height</label><input type="number" step="0.1" data-k="line_height" value="' + (p.line_height || 1.3) + '"/></div>' +
        '<div class="st-props-row"><label>Letter sp.</label><input type="number" step="0.5" data-k="letter_spacing" value="' + (p.letter_spacing || 0) + '"/></div>' +
        '<div class="st-props-row"><label>Case</label><select data-k="text_transform">' +
          ['none','uppercase','lowercase','capitalize'].map(function(v){ return '<option value="' + v + '"' + (p.text_transform === v ? ' selected' : '') + '>' + v + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="st-props-row"><label>BG color</label><input type="color" data-k="background_color" value="' + _esc(p.background_color || '#FFFFFF') + '"/></div>' +
      '</div>' +
      _stPropsCommonRow(el)
    );
  }

  function _stWirePropsText(el){
    _stWirePropsCommon(el);
    EDT.propsPanel.querySelectorAll('[data-style]').forEach(function(b){
      b.onclick = function(){
        var k = b.getAttribute('data-style'); var v = b.getAttribute('data-val');
        el.properties_json[k] = v;
        _stUpdateElementDom(el); _stPersistElement(el.id); _stMarkDirty(); _stSaveHistory();
        _stRenderProps();
      };
    });
    EDT.propsPanel.querySelectorAll('[data-toggle]').forEach(function(b){
      b.onclick = function(){
        var t = b.getAttribute('data-toggle');
        if (t === 'bold') el.properties_json.font_weight = (el.properties_json.font_weight == 700 || el.properties_json.font_weight === '700') ? '400' : '700';
        if (t === 'italic') el.properties_json.font_style = el.properties_json.font_style === 'italic' ? 'normal' : 'italic';
        if (t === 'underline' || t === 'line-through') {
          var cur = el.properties_json.text_decoration || 'none';
          el.properties_json.text_decoration = cur.indexOf(t) >= 0 ? cur.replace(t, '').trim() || 'none' : (cur === 'none' ? t : cur + ' ' + t);
        }
        _stUpdateElementDom(el); _stPersistElement(el.id); _stMarkDirty(); _stSaveHistory();
        _stRenderProps();
      };
    });
  }

  function _stPropsImageHtml(el){
    var p = el.properties_json || {};
    return (
      '<div class="st-props-group">' +
        '<div class="st-props-title">Image</div>' +
        '<button class="st-btn-wide" data-act="replace">Replace image</button>' +
        '<div class="st-align-row">' +
          '<button data-toggle="flip_x">Flip H</button>' +
          '<button data-toggle="flip_y">Flip V</button>' +
        '</div>' +
        '<div class="st-props-row"><label>Fit</label><select data-k="object_fit">' +
          ['cover','contain','fill','none','scale-down'].map(function(v){ return '<option value="' + v + '"' + (p.object_fit === v ? ' selected' : '') + '>' + v + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="st-props-row"><label>Brightness</label><input type="range" min="-100" max="100" data-k="brightness" value="' + (p.brightness || 0) + '"/></div>' +
        '<div class="st-props-row"><label>Contrast</label><input type="range" min="-100" max="100" data-k="contrast" value="' + (p.contrast || 0) + '"/></div>' +
        '<div class="st-props-row"><label>Saturation</label><input type="range" min="-100" max="100" data-k="saturation" value="' + (p.saturation || 0) + '"/></div>' +
        '<div class="st-props-row"><label>Blur</label><input type="range" min="0" max="20" data-k="blur" value="' + (p.blur || 0) + '"/></div>' +
        '<div class="st-props-row"><label>Radius</label><input type="number" min="0" max="400" data-k="border_radius" value="' + (p.border_radius || 0) + '"/></div>' +
      '</div>' +
      _stPropsCommonRow(el)
    );
  }

  function _stWirePropsImage(el){
    _stWirePropsCommon(el);
    EDT.propsPanel.querySelectorAll('[data-toggle]').forEach(function(b){
      b.onclick = function(){
        var t = b.getAttribute('data-toggle');
        el.properties_json[t] = !el.properties_json[t];
        _stUpdateElementDom(el); _stPersistElement(el.id); _stMarkDirty(); _stSaveHistory();
      };
    });
    var rep = EDT.propsPanel.querySelector('[data-act="replace"]');
    if (rep) rep.onclick = function(){
      if (typeof window.openMediaPicker === 'function') {
        window.openMediaPicker({ type:'image', context:'studio' }, function(file){
          if (file && file.url) {
            _stSaveHistory();
            el.properties_json.src_url = file.url;
            _stUpdateElementDom(el); _stPersistElement(el.id); _stMarkDirty();
          }
        });
      }
    };
  }

  function _stPropsShapeHtml(el){
    var p = el.properties_json || {};
    return (
      '<div class="st-props-group">' +
        '<div class="st-props-title">Shape</div>' +
        '<div class="st-props-row"><label>Fill</label><input type="color" data-k="fill" value="' + _esc(p.fill || '#6C5CE7') + '"/></div>' +
        '<div class="st-props-row"><label>Stroke</label><input type="color" data-k="stroke" value="' + _esc(p.stroke || '#000000') + '"/></div>' +
        '<div class="st-props-row"><label>Stroke W</label><input type="number" min="0" max="50" data-k="stroke_width" value="' + (p.stroke_width || 0) + '"/></div>' +
        '<div class="st-props-row"><label>Radius</label><input type="number" min="0" max="400" data-k="border_radius" value="' + (p.border_radius || 0) + '"/></div>' +
      '</div>' +
      _stPropsCommonRow(el)
    );
  }

  function _stWirePropsShape(el){
    _stWirePropsCommon(el);
  }

  // ── CONTINUED IN PART 5 ──

  // ═══════════════════════════════════════════════════════════════
  // PART 5 — Design tab action glue, layers, export, save, AI, CSS
  // ═══════════════════════════════════════════════════════════════

  // ── Element creation helpers ───────────────────────────────
  function _stAddElement(type, propsOverride){
    var w = EDT.design.canvas_width;
    var h = EDT.design.canvas_height;
    var props = Object.assign({
      x: Math.round(w / 2 - 100),
      y: Math.round(h / 2 - 50),
      width: 200,
      height: 100,
      rotation: 0,
      opacity: 1,
    }, propsOverride || {});

    _fetchJson('/studio/designs/' + EDT.design.id + '/elements', {
      method: 'POST',
      body: JSON.stringify({ element_type: type, properties_json: props }),
    }).then(function(d){
      // Add to local state
      var newEl = {
        id: d.element_id || d.id,
        element_type: type,
        properties_json: props,
        layer_order: d.layer_order || EDT.elements.length + 1,
      };
      EDT.elements.push(newEl);
      EDT.layer.appendChild(_stRenderElement(newEl));
      _stSelectElement(newEl.id, false);
      _stSaveHistory();
      _stMarkDirty();
    }).catch(function(e){ _toast('Add failed: ' + e.message, 'error'); });
  }

  function _stAddTextElement(spec){
    _stAddElement('text', Object.assign({
      content: 'Your text',
      font_family: 'Inter',
      font_size: 48,
      font_weight: '700',
      color: '#0F172A',
      text_align: 'left',
      line_height: 1.2,
      width: 500,
      height: Math.round((spec.font_size || 48) * 1.4),
    }, spec || {}));
  }
  function _stAddShape(shape, spec){
    _stAddElement('shape', Object.assign({
      shape_type: shape,
      fill: '#6C5CE7',
      width: 200, height: 200,
    }, spec || {}));
  }
  function _stAddLine(style){
    _stAddElement('line', {
      style: style || 'straight',
      stroke: '#0F172A',
      stroke_width: 3,
      width: 300, height: 6,
    });
  }
  function _stAddImageElement(url){
    _stAddElement('image', {
      src_url: url,
      width: 400, height: 400,
      object_fit: 'cover',
    });
  }

  // ── Common actions ────────────────────────────────────────
  function _stCopy(){
    if (!EDT.selected.length) return;
    EDT.clipboard = EDT.selected.map(function(id){
      var el = _stGetEl(id); if (!el) return null;
      return { element_type: el.element_type, properties_json: JSON.parse(JSON.stringify(el.properties_json || {})) };
    }).filter(Boolean);
    _toast(EDT.clipboard.length + ' copied', 'info');
  }
  function _stPaste(){
    if (!EDT.clipboard || !EDT.clipboard.length) return;
    EDT.clipboard.forEach(function(spec){
      var p = Object.assign({}, spec.properties_json);
      p.x = (p.x || 0) + 20; p.y = (p.y || 0) + 20;
      _stAddElement(spec.element_type, p);
    });
  }
  function _stDuplicate(){ _stCopy(); _stPaste(); }

  function _stDeleteSelected(){
    if (!EDT.selected.length) return;
    _stSaveHistory();
    var ids = EDT.selected.slice();
    ids.forEach(function(id){
      _fetchJson('/studio/designs/' + EDT.design.id + '/elements/' + id, { method: 'DELETE' }).catch(function(){});
      EDT.elements = EDT.elements.filter(function(e){ return Number(e.id) !== Number(id); });
      var node = EDT.layer.querySelector('[data-id="' + id + '"]');
      if (node) node.remove();
    });
    EDT.selected = [];
    _stUpdateSelectionOverlay();
    _stRenderProps();
    _stRenderLayers();
    _stMarkDirty();
  }

  function _stSelectAll(){
    EDT.selected = EDT.elements.map(function(e){ return Number(e.id); });
    _stSyncSelectionClasses();
    _stUpdateSelectionOverlay();
    _stRenderProps();
  }

  function _stBringForward(){ _stShiftOrder(+1); }
  function _stSendBackward(){ _stShiftOrder(-1); }
  function _stBringToFront(){ _stShiftOrderAbs('top'); }
  function _stSendToBack(){ _stShiftOrderAbs('bottom'); }

  function _stShiftOrder(delta){
    if (EDT.selected.length !== 1) return;
    var el = _stGetEl(EDT.selected[0]); if (!el) return;
    var sorted = EDT.elements.slice().sort(function(a,b){ return a.layer_order - b.layer_order; });
    var idx = sorted.findIndex(function(e){ return Number(e.id) === Number(el.id); });
    var target = idx + delta;
    if (target < 0 || target >= sorted.length) return;
    var other = sorted[target];
    var tmp = el.layer_order; el.layer_order = other.layer_order; other.layer_order = tmp;
    _stPersistElement(el.id); _stPersistElement(other.id);
    _stSaveHistory(); _stMarkDirty();
    _stRenderAllElements();
    _stSyncSelectionClasses();
    _stUpdateSelectionOverlay();
    _stRenderLayers();
  }
  function _stShiftOrderAbs(where){
    if (EDT.selected.length !== 1) return;
    var el = _stGetEl(EDT.selected[0]); if (!el) return;
    var sorted = EDT.elements.slice().sort(function(a,b){ return a.layer_order - b.layer_order; });
    var maxOrder = Math.max.apply(Math, sorted.map(function(e){ return e.layer_order; }));
    var minOrder = Math.min.apply(Math, sorted.map(function(e){ return e.layer_order; }));
    el.layer_order = where === 'top' ? maxOrder + 1 : minOrder - 1;
    _stPersistElement(el.id);
    _stSaveHistory(); _stMarkDirty();
    _stRenderAllElements(); _stSyncSelectionClasses(); _stUpdateSelectionOverlay(); _stRenderLayers();
  }

  function _stDoAlign(act){
    if (EDT.selected.length < 2 && act.indexOf('distribute') < 0) {
      if (act === 'delete') return _stDeleteSelected();
    }
    _stSaveHistory();
    var sels = EDT.selected.map(_stGetEl).filter(Boolean);
    if (act === 'delete') return _stDeleteSelected();
    if (act === 'align-l') {
      var minX = Math.min.apply(Math, sels.map(function(e){ return e.properties_json.x || 0; }));
      sels.forEach(function(e){ e.properties_json.x = minX; _stUpdateElementDom(e); _stPersistElement(e.id); });
    }
    else if (act === 'align-r') {
      var maxR = Math.max.apply(Math, sels.map(function(e){ return (e.properties_json.x || 0) + (e.properties_json.width || 0); }));
      sels.forEach(function(e){ e.properties_json.x = maxR - (e.properties_json.width || 0); _stUpdateElementDom(e); _stPersistElement(e.id); });
    }
    else if (act === 'align-ch') {
      var midX = (Math.max.apply(Math, sels.map(function(e){ return (e.properties_json.x || 0) + (e.properties_json.width || 0); })) +
                  Math.min.apply(Math, sels.map(function(e){ return e.properties_json.x || 0; }))) / 2;
      sels.forEach(function(e){ e.properties_json.x = Math.round(midX - (e.properties_json.width || 0) / 2); _stUpdateElementDom(e); _stPersistElement(e.id); });
    }
    else if (act === 'align-t') {
      var minY = Math.min.apply(Math, sels.map(function(e){ return e.properties_json.y || 0; }));
      sels.forEach(function(e){ e.properties_json.y = minY; _stUpdateElementDom(e); _stPersistElement(e.id); });
    }
    else if (act === 'align-b') {
      var maxB = Math.max.apply(Math, sels.map(function(e){ return (e.properties_json.y || 0) + (e.properties_json.height || 0); }));
      sels.forEach(function(e){ e.properties_json.y = maxB - (e.properties_json.height || 0); _stUpdateElementDom(e); _stPersistElement(e.id); });
    }
    else if (act === 'align-cv') {
      var midY = (Math.max.apply(Math, sels.map(function(e){ return (e.properties_json.y || 0) + (e.properties_json.height || 0); })) +
                  Math.min.apply(Math, sels.map(function(e){ return e.properties_json.y || 0; }))) / 2;
      sels.forEach(function(e){ e.properties_json.y = Math.round(midY - (e.properties_json.height || 0) / 2); _stUpdateElementDom(e); _stPersistElement(e.id); });
    }
    else if (act === 'distribute-h' && sels.length >= 3) {
      var sX = sels.slice().sort(function(a,b){ return a.properties_json.x - b.properties_json.x; });
      var spanL = sX[0].properties_json.x;
      var spanR = sX[sX.length-1].properties_json.x;
      var step = (spanR - spanL) / (sX.length - 1);
      sX.forEach(function(e, i){ e.properties_json.x = Math.round(spanL + step * i); _stUpdateElementDom(e); _stPersistElement(e.id); });
    }
    else if (act === 'distribute-v' && sels.length >= 3) {
      var sY = sels.slice().sort(function(a,b){ return a.properties_json.y - b.properties_json.y; });
      var spanT = sY[0].properties_json.y;
      var spanB = sY[sY.length-1].properties_json.y;
      var stepY = (spanB - spanT) / (sY.length - 1);
      sY.forEach(function(e, i){ e.properties_json.y = Math.round(spanT + stepY * i); _stUpdateElementDom(e); _stPersistElement(e.id); });
    }
    _stUpdateSelectionOverlay(); _stMarkDirty();
  }

  // ── Layers panel ───────────────────────────────────────────
  function _stRenderLayers(){
    if (!EDT.layersVisible) return;
    var holder = document.getElementById('st-layers-list'); if (!holder) return;
    var sorted = EDT.elements.slice().sort(function(a,b){ return (b.layer_order || 0) - (a.layer_order || 0); });
    holder.innerHTML = sorted.map(function(e){
      var p = e.properties_json || {};
      var name = p.content ? p.content.substring(0, 20) : (p.shape_type || e.element_type);
      var sel = EDT.selected.indexOf(Number(e.id)) >= 0 ? ' selected' : '';
      return '<div class="st-layer' + sel + '" data-id="' + e.id + '">' +
               '<span class="st-layer-type">' + e.element_type.charAt(0).toUpperCase() + '</span>' +
               '<span class="st-layer-name">' + _esc(name) + '</span>' +
               '<button class="st-layer-btn" data-act="vis" title="Visibility">' + (p.hidden ? '\u2050' : '\u25c9') + '</button>' +
               '<button class="st-layer-btn" data-act="lock" title="Lock">' + (p.locked ? '\u2702' : ' ') + '</button>' +
             '</div>';
    }).join('');
    holder.querySelectorAll('.st-layer').forEach(function(row){
      row.onclick = function(e){
        if (e.target.closest('.st-layer-btn')) return;
        _stSelectElement(row.getAttribute('data-id'), e.shiftKey);
      };
    });
    holder.querySelectorAll('.st-layer-btn').forEach(function(b){
      b.onclick = function(e){
        e.stopPropagation();
        var row = b.closest('.st-layer'); var id = row.getAttribute('data-id');
        var el = _stGetEl(id); if (!el) return;
        var act = b.getAttribute('data-act');
        if (act === 'vis') el.properties_json.hidden = !el.properties_json.hidden;
        if (act === 'lock') el.properties_json.locked = !el.properties_json.locked;
        _stUpdateElementDom(el); _stPersistElement(id); _stMarkDirty();
        _stRenderLayers();
      };
    });
  }

  // ── Export ────────────────────────────────────────────────
  function _stDownload(format){
    _toast('Exporting ' + format.toUpperCase() + '...', 'info');
    // Ensure latest state is saved before render
    _stAutoSave(true).then(function(){
      var url = '/api/studio/designs/' + EDT.design.id + '/render-png' + (format === 'jpg' ? '?format=jpg&quality=90' : '');
      // Use a fetch so we can wait for ready then open
      fetch(url, { method:'POST', headers:{ 'Authorization': 'Bearer ' + _tok() } })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (d.success && d.png_url) {
            var a = document.createElement('a'); a.href = d.png_url; a.download = (EDT.design.name || 'design') + '.' + format;
            document.body.appendChild(a); a.click(); a.remove();
            _toast('Download ready', 'success');
          } else {
            _toast('Export failed: ' + (d.error || 'unknown'), 'error');
          }
        }).catch(function(e){ _toast('Export failed: ' + e.message, 'error'); });
    });
  }

  // ── Auto-save ─────────────────────────────────────────────
  function _stMarkDirty(){
    EDT.dirty = true;
    var s = document.getElementById('st-save-status');
    if (s) s.textContent = 'Unsaved';
  }
  function _stAutoSaveSoon(){
    clearTimeout(EDT._autoSaveSoon);
    EDT._autoSaveSoon = setTimeout(function(){ _stAutoSave(); }, 1500);
  }
  function _stAutoSave(silent){
    if (EDT.saving) return Promise.resolve();
    EDT.saving = true;
    var s = document.getElementById('st-save-status');
    if (s) s.textContent = 'Saving...';
    return _fetchJson('/studio/designs/' + EDT.design.id, {
      method:'PUT',
      body: JSON.stringify({
        name: EDT.design.name,
        background_type: EDT.design.background_type,
        background_value: EDT.design.background_value,
        canvas_width: EDT.design.canvas_width,
        canvas_height: EDT.design.canvas_height,
      }),
    }).then(function(){
      EDT.dirty = false;
      if (s) s.textContent = 'Saved';
      setTimeout(function(){ if (s && s.textContent === 'Saved') s.textContent = ''; }, 2000);
    }).catch(function(){
      if (s) s.textContent = 'Save failed';
    }).then(function(){ EDT.saving = false; });
  }

  // ── AI drawer ─────────────────────────────────────────────
  function _stAiSend(){
    // studio-p4p5-frontend
    var ta = document.getElementById('st-ai-input');
    var msg = (ta.value || '').trim(); if (!msg) return;
    ta.value = '';
    _stAiBubble(msg, 'user');
    var loading = _stAiBubble('Thinking...', 'ai-loading');

    _fetchJson('/studio/ai/chat', {
      method: 'POST',
      body: JSON.stringify({
        message: msg,
        design_id: EDT.design.id,
        selected_element_id: EDT.selected[0] || null,
        current_design_state: {
          element_count: EDT.elements.length,
          background_type: EDT.design.background_type,
          canvas_width: EDT.design.canvas_width,
          canvas_height: EDT.design.canvas_height,
        }
      })
    }).then(function(data){
      var chat = document.getElementById('st-ai-chat');
      chat.querySelectorAll('.ai-loading').forEach(function(n){ n.remove(); });
      if (!data.success) {
        var errMsg = data.error === 'api_key_missing'
          ? 'AI requires a DeepSeek API key in .env.'
          : ('Arthur failed: ' + (data.message || data.error || 'unknown'));
        _stAiBubble(errMsg, 'ai');
        return;
      }
      (data.actions || []).forEach(_stApplyAction);
      _stAiBubble(data.reply || 'Done.', 'ai');
    }).catch(function(e){
      var chat = document.getElementById('st-ai-chat');
      chat.querySelectorAll('.ai-loading').forEach(function(n){ n.remove(); });
      _stAiBubble('Error: ' + e.message, 'ai');
    });
  }

  function _stApplyAction(a){
    if (!a || !a.type) return;
    if (a.type === 'update_field') {
      // Find the text element whose data-field matches (if any), else the first text el
      var target = EDT.elements.find(function(e){ return e.element_type === 'text' && (e.properties_json.field === a.field || e.properties_json.content === a.field); }) || EDT.elements.find(function(e){ return e.element_type === 'text'; });
      if (target) { _stSaveHistory(); target.properties_json.content = String(a.value); _stUpdateElementDom(target); _stPersistElement(target.id); _stMarkDirty(); }
    } else if (a.type === 'apply_palette') {
      // Apply CSS vars to the design frame element + swap ALL text colors to new primary if close to old one
      _stSaveHistory();
      Object.keys(a.vars || {}).forEach(function(k){ if (EDT.frame) EDT.frame.style.setProperty(k, a.vars[k]); });
      var bgNew = a.vars['--clr-bg'] || a.vars['--bg'] || null;
      var textNew = a.vars['--clr-text'] || a.vars['--text'] || null;
      if (bgNew) { EDT.design.background_type = 'color'; EDT.design.background_value = bgNew; _stApplyBackground(); }
      if (textNew) {
        EDT.elements.forEach(function(el){
          if (el.element_type === 'text') { el.properties_json.color = textNew; _stUpdateElementDom(el); _stPersistElement(el.id); }
        });
      }
      _stMarkDirty();
    } else if (a.type === 'generate_image') {
      _stAiBubble('Generating image: ' + a.prompt, 'ai-loading');
      _fetchJson('/studio/ai/generate-image', {
        method: 'POST', body: JSON.stringify({ prompt: a.prompt, style: a.style || 'cinematic' })
      }).then(function(r){
        var chat = document.getElementById('st-ai-chat');
        chat.querySelectorAll('.ai-loading').forEach(function(n){ n.remove(); });
        if (!r.success) { _stAiBubble('Image failed: ' + (r.message || r.error), 'ai'); return; }
        // If a specific target element was named, replace it; else add as new
        var target = a.target_field ? EDT.elements.find(function(e){ return e.element_type === 'image' && e.properties_json.field === a.target_field; }) : EDT.elements.find(function(e){ return e.element_type === 'image'; });
        if (target) {
          _stSaveHistory();
          target.properties_json.src_url = r.image_url;
          _stUpdateElementDom(target); _stPersistElement(target.id); _stMarkDirty();
          _stAiBubble('Image replaced.', 'ai');
        } else {
          _stAddImageElement(r.image_url);
          _stAiBubble('Image added.', 'ai');
        }
      });
    } else if (a.type === 'add_text') {
      _stAddTextElement(Object.assign({ content: a.content || 'Text' }, a.style || {}));
    } else if (a.type === 'message') {
      // inline — reply is shown separately; no-op here
    }
  }
  function _stAiBubble(text, kind){
    var chat = document.getElementById('st-ai-chat');
    var welcome = chat.querySelector('.st-ai-welcome'); if (welcome) welcome.remove();
    var b = document.createElement('div');
    b.className = 'st-ai-bubble ' + (kind || 'ai') + (kind === 'ai-loading' ? ' ai-loading' : '');
    b.textContent = text;
    chat.appendChild(b);
    chat.scrollTop = chat.scrollHeight;
  }

  // ── Modal helpers ─────────────────────────────────────────
  function _stOpenResizeModal(){
    var formats = [
      ['square',1080,1080],['portrait',1080,1350],['story',1080,1920],['landscape',1920,1080],
      ['pinterest',1000,1500],['facebook_cover',820,312],['twitter_header',1500,500],
    ];
    var bd = document.createElement('div');
    bd.className = 'modal-backdrop';
    bd.onclick = function(e){ if (e.target === bd) bd.remove(); };
    bd.innerHTML =
      '<div class="modal" style="max-width:520px">' +
        '<div class="modal-header"><h3>Resize design</h3><button class="modal-close" onclick="this.closest(\'.modal-backdrop\').remove()">\u2715</button></div>' +
        '<div class="modal-body">' +
          '<div class="form-group"><label class="form-label">Preset</label>' +
            '<select class="form-input" id="st-resize-preset">' +
              formats.map(function(f){ return '<option value="' + f[0] + '" data-w="' + f[1] + '" data-h="' + f[2] + '">' + f[0] + ' (' + f[1] + '\u00d7' + f[2] + ')</option>'; }).join('') +
              '<option value="custom">Custom</option>' +
            '</select></div>' +
          '<div class="form-group" style="display:flex;gap:8px">' +
            '<div style="flex:1"><label class="form-label">Width</label><input class="form-input" type="number" id="st-resize-w" value="' + EDT.design.canvas_width + '"/></div>' +
            '<div style="flex:1"><label class="form-label">Height</label><input class="form-input" type="number" id="st-resize-h" value="' + EDT.design.canvas_height + '"/></div>' +
          '</div>' +
        '</div>' +
        '<div class="modal-footer">' +
          '<button class="btn btn-outline" onclick="this.closest(\'.modal-backdrop\').remove()">Cancel</button>' +
          '<button class="btn btn-primary" id="st-resize-apply">Resize</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(bd);
    bd.querySelector('#st-resize-preset').onchange = function(e){
      var opt = e.target.selectedOptions[0];
      var w = opt.getAttribute('data-w'), h = opt.getAttribute('data-h');
      if (w && h) { bd.querySelector('#st-resize-w').value = w; bd.querySelector('#st-resize-h').value = h; }
    };
    bd.querySelector('#st-resize-apply').onclick = function(){
      var w = parseInt(bd.querySelector('#st-resize-w').value, 10);
      var h = parseInt(bd.querySelector('#st-resize-h').value, 10);
      if (w > 0 && h > 0) {
        _stSaveHistory();
        EDT.design.canvas_width = w; EDT.design.canvas_height = h;
        _stApplyFrameSize(); _stFitToViewport(); _stMarkDirty(); _stAutoSave();
      }
      bd.remove();
    };
  }

  function _mktModalEditor(title, fieldDefs, onSave){
    var bd = document.createElement('div');
    bd.className = 'modal-backdrop';
    bd.onclick = function(e){ if (e.target === bd) bd.remove(); };
    var body = fieldDefs.map(function(f){
      var key = f[0], label = f[1], type = f[2], val = f[3] || '';
      if (type === 'select') {
        return '<div class="form-group"><label class="form-label">' + _esc(label) + '</label><select class="form-input" id="stbk-' + key + '">' +
          f[4].map(function(o){ return '<option value="' + _esc(o) + '"' + (val === o ? ' selected' : '') + '>' + _esc(o) + '</option>'; }).join('') +
        '</select></div>';
      }
      return '<div class="form-group"><label class="form-label">' + _esc(label) + '</label><input class="form-input" type="' + type + '" id="stbk-' + key + '" value="' + _esc(val) + '"/></div>';
    }).join('');
    bd.innerHTML =
      '<div class="modal" style="max-width:520px">' +
        '<div class="modal-header"><h3>' + _esc(title) + '</h3><button class="modal-close" onclick="this.closest(\'.modal-backdrop\').remove()">\u2715</button></div>' +
        '<div class="modal-body">' + body + '</div>' +
        '<div class="modal-footer"><button class="btn btn-outline" onclick="this.closest(\'.modal-backdrop\').remove()">Cancel</button><button class="btn btn-primary" id="stbk-save">Save</button></div>' +
      '</div>';
    document.body.appendChild(bd);
    bd.querySelector('#stbk-save').onclick = function(){
      var values = {};
      fieldDefs.forEach(function(f){ values[f[0]] = bd.querySelector('#stbk-' + f[0]).value; });
      if (onSave) onSave(values);
      bd.remove();
    };
  }

  // ── Exit handling ─────────────────────────────────────────
  function _stRequestExit(){
    if (EDT.dirty) {
      var ok = window.luConfirm ? window.luConfirm('Unsaved changes. Leave anyway?', 'Unsaved changes', 'Leave', 'Stay') : Promise.resolve(confirm('Unsaved changes. Leave anyway?'));
      Promise.resolve(ok).then(function(confirmed){ if (confirmed) _stTeardownEditor(); });
    } else {
      _stTeardownEditor();
    }
  }
  function _stTeardownEditor(){
    if (EDT.autoSaveTimer) clearInterval(EDT.autoSaveTimer);
    var host = document.getElementById('st-host');
    if (host) host.innerHTML = '';
    EDT.design = null; EDT.elements = []; EDT.selected = [];
    EDT.dirty = false;
    // Re-mount gallery (Phase 1)
    if (typeof _mountGallery === 'function') _mountGallery();
    else if (typeof window.studioLoad === 'function') window.studioLoad(window._rootEl || null);
  }

  // ── Override window._mountEditor so the gallery enters the new editor ──
  window._mountEditor = function(){ _stOpenImageEditor(window._designId); };

  // ── CSS ───────────────────────────────────────────────────
  function _stInjectCss(){
    if (EDT.cssInjected) return; EDT.cssInjected = true;
    var css = [
      '.st-editor-root{position:fixed;inset:0;z-index:900;background:var(--bg,#0B0C11);color:var(--t1,#F1F5F9);font-family:"DM Sans","Inter",Arial,sans-serif;display:flex;flex-direction:column}',
      '.st-topbar{height:52px;border-bottom:1px solid var(--bd,#1f2330);display:flex;align-items:center;padding:0 16px;gap:12px;flex-shrink:0;background:var(--s1,#0F1218)}',
      '.st-back{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;font-family:inherit}',
      '.st-back:hover{background:var(--s2,#171b23)}',
      '.st-design-name{flex:1;max-width:320px;background:transparent;border:none;font-size:15px;font-weight:600;color:var(--t1,#F1F5F9);outline:none;padding:4px 8px}',
      '.st-design-name:focus{background:var(--s2,#171b23);border-radius:4px}',
      '.st-topbar-center{display:flex;align-items:center;gap:6px}',
      '.st-topbar-right{display:flex;gap:8px;align-items:center;margin-left:auto}',
      '.st-icon-btn{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:5px 10px;border-radius:5px;cursor:pointer;font-size:12px;font-family:inherit}',
      '.st-icon-btn:hover:not(:disabled){background:var(--s2,#171b23);color:var(--t1,#F1F5F9)}',
      '.st-icon-btn:disabled{opacity:.35;cursor:default}',
      '.st-save-status{font-size:11px;color:var(--t3,#64748B);margin-left:6px}',
      '.st-btn-primary{background:var(--p,#6C5CE7);border:none;color:#fff;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}',
      '.st-btn-primary:hover{background:var(--p-dark,#5849d3)}',
      '.st-btn-ghost{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:6px 14px;border-radius:6px;font-size:13px;cursor:pointer;font-family:inherit}',
      '.st-btn-ghost:hover{background:var(--s2,#171b23)}',
      '.st-btn-wide{width:100%;padding:8px 12px;margin-top:6px;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);color:var(--t1,#F1F5F9);border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;text-align:left}',
      '.st-btn-wide:hover{border-color:var(--p,#6C5CE7)}',
      '.st-btn-outline{background:transparent;color:var(--p,#6C5CE7);border-color:var(--p,#6C5CE7)}',
      '.st-btn-danger{background:transparent;color:#EF4444;border-color:rgba(239,68,68,.4)}',
      '.st-download-wrap{position:relative}',
      '.st-download-menu{position:absolute;top:calc(100% + 4px);right:0;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:6px;min-width:140px;padding:4px;z-index:1000}',
      '.st-download-menu.hidden{display:none}',
      '.st-download-menu div{padding:8px 12px;font-size:12px;cursor:pointer;border-radius:4px}',
      '.st-download-menu div:hover{background:var(--s3,#1e2230)}',

      '.st-shell{flex:1;display:grid;grid-template-columns:260px 1fr 300px;overflow:hidden;min-height:0}',
      '.st-left{border-right:1px solid var(--bd,#1f2330);background:var(--s1,#0F1218);overflow-y:auto;display:flex;flex-direction:column}',
      '.st-panel-tabs{display:flex;border-bottom:1px solid var(--bd,#1f2330);flex-shrink:0;overflow-x:auto}',
      '.st-ptab{flex:1;background:transparent;border:none;color:var(--t3,#64748B);padding:10px 4px;font-size:11px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;white-space:nowrap}',
      '.st-ptab.active{color:var(--t1,#F1F5F9);border-bottom-color:var(--p,#6C5CE7)}',
      '.st-panel-body{padding:12px;flex:1;overflow-y:auto}',
      '.st-panel-section-label{font-size:10px;font-weight:700;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em;margin:14px 0 8px}',
      '.st-panel-section-label:first-child{margin-top:0}',
      '.st-panel-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px}',
      '.st-panel-grid.shapes{grid-template-columns:repeat(4,1fr)}',
      '.st-panel-grid.icons{grid-template-columns:repeat(6,1fr);gap:4px}',
      '.st-panel-grid.gradients{grid-template-columns:repeat(4,1fr);gap:6px}',
      '.st-panel-grid.media{grid-template-columns:repeat(3,1fr);gap:4px}',
      '.st-tpl-thumb{background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:6px;cursor:pointer;overflow:hidden;aspect-ratio:1/1;position:relative}',
      '.st-tpl-thumb:hover{border-color:var(--p,#6C5CE7)}',
      '.st-tpl-thumb iframe{width:400%;height:400%;transform:scale(.25);transform-origin:top left;border:0;pointer-events:none}',
      '.st-tpl-name{position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.75);color:#fff;font-size:10px;padding:4px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.st-shape-btn{display:flex;align-items:center;justify-content:center;padding:10px;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:6px;cursor:pointer;color:var(--t2,#CBD5E1)}',
      '.st-shape-btn:hover{border-color:var(--p,#6C5CE7);color:var(--p,#6C5CE7)}',
      '.st-icon-insert{padding:8px;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:4px;cursor:pointer;color:var(--t1,#F1F5F9);font-size:18px;line-height:1;font-family:inherit}',
      '.st-icon-insert:hover{border-color:var(--p,#6C5CE7)}',
      '.st-text-presets{display:flex;flex-direction:column;gap:6px}',
      '.st-text-preset{text-align:left;padding:10px 12px;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);color:var(--t1,#F1F5F9);border-radius:6px;cursor:pointer;font-size:14px}',
      '.st-text-preset:hover{border-color:var(--p,#6C5CE7)}',
      '.st-media-thumb{padding:0;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:4px;cursor:pointer;overflow:hidden;aspect-ratio:1/1}',
      '.st-media-thumb img{width:100%;height:100%;object-fit:cover}',
      '.st-media-thumb:hover{border-color:var(--p,#6C5CE7)}',
      '.st-brand-colors{display:flex;gap:6px;flex-wrap:wrap}',
      '.st-brand-color{width:38px;height:38px;border-radius:6px;border:2px solid var(--bd,#2a2f3a);cursor:pointer}',
      '.st-brand-color:hover{border-color:var(--p,#6C5CE7);transform:scale(1.05)}',
      '.st-brand-font{font-size:12px;color:var(--t2,#CBD5E1);padding:4px 0}',
      '.st-brand-font strong{color:var(--t1,#F1F5F9)}',
      '.st-brand-logo{background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:6px;padding:10px;cursor:pointer;width:100%}',
      '.st-brand-logo img{max-width:100%;max-height:60px;display:block;margin:0 auto}',
      '.st-grad-btn{aspect-ratio:1/1;border:1px solid var(--bd,#2a2f3a);border-radius:6px;cursor:pointer}',
      '.st-grad-btn:hover{transform:scale(1.05)}',

      '.st-canvas-area{position:relative;background:#1a1a2e;overflow:hidden;display:flex;flex-direction:column}',
      '.st-canvas-outer{flex:1;overflow:hidden;position:relative}',
      '.st-canvas-transform{transform-origin:0 0;position:absolute;top:0;left:0;will-change:transform}',
      '.st-frame{position:relative;background:#FFFFFF;box-shadow:0 0 0 1px rgba(108,92,231,.3),0 20px 60px rgba(0,0,0,.5);overflow:hidden}',
      '.st-frame.grid{background-image:linear-gradient(to right,rgba(108,92,231,.1) 1px,transparent 1px),linear-gradient(to bottom,rgba(108,92,231,.1) 1px,transparent 1px);background-size:40px 40px;background-position:0 0}',
      '.st-bg{position:absolute;inset:0;pointer-events:none}',
      '.st-elements-layer{position:absolute;inset:0}',
      '.st-element{cursor:move;box-sizing:border-box}',
      '.st-element.selected{outline:2px solid rgba(108,92,231,.6);outline-offset:1px}',
      '.st-element-text .st-text-content{box-sizing:border-box;overflow:hidden}',
      '.st-element-text .st-text-content[contenteditable="true"]{cursor:text;outline:2px solid var(--p,#6C5CE7);background:rgba(108,92,231,.05)}',
      '.st-selection-overlay{position:absolute;pointer-events:none;border:2px dashed #6C5CE7;box-sizing:border-box;z-index:9999}',
      '.st-selection-overlay.hidden{display:none}',
      '.st-handle{position:absolute;width:10px;height:10px;background:#fff;border:2px solid #6C5CE7;border-radius:2px;pointer-events:all;cursor:pointer;transform:translate(-50%,-50%)}',
      '.st-handle-tl{top:0;left:0;cursor:nwse-resize}',
      '.st-handle-t {top:0;left:50%;cursor:ns-resize}',
      '.st-handle-tr{top:0;left:100%;cursor:nesw-resize}',
      '.st-handle-r {top:50%;left:100%;cursor:ew-resize}',
      '.st-handle-br{top:100%;left:100%;cursor:nwse-resize}',
      '.st-handle-b {top:100%;left:50%;cursor:ns-resize}',
      '.st-handle-bl{top:100%;left:0;cursor:nesw-resize}',
      '.st-handle-l {top:50%;left:0;cursor:ew-resize}',
      '.st-rotate-handle{position:absolute;top:-28px;left:50%;width:14px;height:14px;background:#fff;border:2px solid #6C5CE7;border-radius:50%;pointer-events:all;cursor:grab;transform:translateX(-50%)}',
      '.st-guide{position:absolute;background:#6C5CE7;z-index:9998;pointer-events:none}',
      '.st-guide.v{width:1px;top:-5000px;bottom:-5000px;height:10000px}',
      '.st-guide.h{height:1px;left:-5000px;right:-5000px;width:10000px}',
      '.st-marquee{position:absolute;border:1px dashed #6C5CE7;background:rgba(108,92,231,.08);pointer-events:none;z-index:9997}',
      '.st-marquee.hidden{display:none}',

      '.st-canvas-toolbar{position:absolute;bottom:10px;left:50%;transform:translateX(-50%);background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:8px;padding:4px;display:flex;align-items:center;gap:4px;z-index:10;box-shadow:0 4px 14px rgba(0,0,0,.4)}',
      '.st-canvas-toolbar span{font-size:11px;color:var(--t2,#CBD5E1);min-width:40px;text-align:center}',
      '.st-canvas-toolbar-sep{width:1px;height:16px;background:var(--bd,#2a2f3a);margin:0 4px}',

      '.st-layers-panel{position:absolute;bottom:60px;left:12px;width:240px;max-height:360px;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:10px;display:flex;flex-direction:column;z-index:10;box-shadow:0 4px 14px rgba(0,0,0,.4)}',
      '.st-layers-panel.hidden{display:none}',
      '.st-layers-header{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--bd,#2a2f3a);font-size:12px;font-weight:700;color:var(--t1,#F1F5F9)}',
      '.st-layers-header button{background:transparent;border:none;color:var(--t3,#64748B);cursor:pointer;font-size:14px;font-family:inherit}',
      '.st-layers-list{overflow-y:auto;padding:4px}',
      '.st-layer{display:flex;align-items:center;gap:6px;padding:6px 8px;border-radius:4px;cursor:pointer;font-size:11px;color:var(--t2,#CBD5E1)}',
      '.st-layer:hover{background:var(--s3,#1e2230)}',
      '.st-layer.selected{background:rgba(108,92,231,.15);color:var(--t1,#F1F5F9)}',
      '.st-layer-type{background:var(--p,#6C5CE7);color:#fff;font-weight:700;width:22px;height:22px;display:flex;align-items:center;justify-content:center;border-radius:3px;font-size:10px}',
      '.st-layer-name{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.st-layer-btn{background:transparent;border:none;color:var(--t3,#64748B);padding:3px;font-size:10px;cursor:pointer;font-family:inherit}',

      '.st-right{border-left:1px solid var(--bd,#1f2330);background:var(--s1,#0F1218);overflow-y:auto;padding:12px}',
      '.st-props-empty{text-align:center;color:var(--t3,#64748B);padding:20px}',
      '.st-props-group{background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:8px;padding:12px;margin-bottom:10px}',
      '.st-props-title{font-size:10px;font-weight:700;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px}',
      '.st-props-row{display:flex;align-items:center;gap:8px;margin-bottom:8px}',
      '.st-props-row label{font-size:11px;color:var(--t3,#64748B);min-width:56px}',
      '.st-props-row input[type=number],.st-props-row input[type=text],.st-props-row select{flex:1;background:var(--s3,#1e2230);border:1px solid var(--bd,#2a2f3a);color:var(--t1,#F1F5F9);padding:4px 6px;font-size:11px;border-radius:4px;min-width:0;font-family:inherit}',
      '.st-props-row input[type=color]{width:32px;height:24px;padding:0;border:1px solid var(--bd,#2a2f3a);border-radius:3px}',
      '.st-props-row input[type=range]{flex:1;min-width:0}',
      '.st-align-row{display:flex;gap:3px;margin-bottom:8px}',
      '.st-align-row button{flex:1;background:var(--s3,#1e2230);border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:5px;font-size:10px;cursor:pointer;border-radius:3px;font-family:inherit}',
      '.st-align-row button:hover,.st-align-row button.active{border-color:var(--p,#6C5CE7);color:var(--p,#6C5CE7)}',
      '.st-props-tip{font-size:10px;color:var(--t3,#64748B);padding:14px 4px;line-height:1.5}',
      '.st-empty-small{color:var(--t3,#64748B);font-size:12px;padding:20px;text-align:center}',

      '.st-ai-fab{position:fixed;bottom:20px;right:20px;width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#6C5CE7,#A78BFA);border:none;color:#fff;font-size:22px;cursor:pointer;box-shadow:0 8px 22px rgba(108,92,231,.5);z-index:950;font-family:inherit}',
      '.st-ai-fab:hover{transform:scale(1.1)}',
      '.st-ai-drawer{position:fixed;bottom:80px;right:20px;width:340px;height:500px;max-height:70vh;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:12px;display:flex;flex-direction:column;z-index:950;box-shadow:0 14px 40px rgba(0,0,0,.5)}',
      '.st-ai-drawer.hidden{display:none}',
      '.st-ai-header{padding:12px 14px;border-bottom:1px solid var(--bd,#2a2f3a);display:flex;align-items:center;justify-content:space-between;font-size:13px;font-weight:700;color:var(--t1,#F1F5F9)}',
      '.st-ai-header button{background:transparent;border:none;color:var(--t3,#64748B);cursor:pointer;font-size:14px;font-family:inherit}',
      '.st-ai-chat{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;min-height:0}',
      '.st-ai-welcome{color:var(--t3,#64748B);font-size:12px;line-height:1.6;text-align:center;padding:14px}',
      '.st-ai-bubble{padding:8px 12px;border-radius:10px;font-size:12px;line-height:1.5;max-width:90%}',
      '.st-ai-bubble.user{background:var(--p,#6C5CE7);color:#fff;margin-left:auto;border-bottom-right-radius:3px}',
      '.st-ai-bubble.ai{background:var(--s3,#1e2230);color:var(--t1,#F1F5F9);border:1px solid var(--bd,#2a2f3a);border-bottom-left-radius:3px}',
      '.st-ai-bubble.ai-loading{font-style:italic;color:var(--t3,#64748B)}',
      '.st-ai-input-wrap{padding:10px;border-top:1px solid var(--bd,#2a2f3a);display:flex;gap:6px}',
      '.st-ai-input-wrap textarea{flex:1;background:var(--s3,#1e2230);border:1px solid var(--bd,#2a2f3a);color:var(--t1,#F1F5F9);padding:7px 9px;font-size:12px;resize:none;border-radius:6px;font-family:inherit;outline:none}',
      '.st-ai-input-wrap button{background:var(--p,#6C5CE7);color:#fff;border:none;padding:0 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;font-family:inherit}',
      '.st-ai-quick{padding:6px 10px;display:flex;flex-wrap:wrap;gap:3px;border-top:1px solid var(--bd,#2a2f3a)}',
      '.st-ai-quick button{font-size:10px;padding:4px 9px;border-radius:14px;background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t3,#64748B);cursor:pointer;font-family:inherit}',
      '.st-ai-quick button:hover{border-color:var(--p,#6C5CE7);color:var(--p,#6C5CE7)}',

      '.hidden{display:none!important}',
    ].join('\n');
    var s = document.createElement('style');
    s.id = 'st-p2-styles'; s.textContent = css;
    document.head.appendChild(s);
  }

})();
