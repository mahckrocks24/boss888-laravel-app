/* ═══════════════════════════════════════════════════════════════
   studio-video.js — Slice B (v4.1.2)
   v4.1.2 fixes:
     • BUG 2 (take 2) — playhead CSS `pointer-events:none` was blocking the
       mousedown handler added in 4.1.1. Widened hit-area to 12px and split
       the red line / round handle into ::after/::before pseudo-elements so
       the parent div can receive pointer events.
   v4.1.1 fixes:
     • BUG 1 — play button resets to ▶ when playhead hits end; rewinds to 0
     • BUG 2 — red playhead line is draggable; ruler supports click + drag
   Lazy-loaded sibling to studio.js, reusing the Studio host pattern.
   Enters via window.studioVideoLoad(rootEl).

   Slice B additions:
     • True 30fps canvas playback (HTML5 <video> element pool)
     • Canvas transitions (fade / slide_left / slide_right / slide_up / zoom_in)
     • Ken Burns effect (canvas preview + FFmpeg zoompan)
     • Text animations: slide_up + scale_pop in FFmpeg
     • Elements tab: logo / lower_third / badge / countdown
     • Audio upload endpoint (MP3/AAC/WAV/M4A)
     • Timeline ripple edit (right-edge drag shifts following clips)

   Screens:
     1) Template picker (gallery of video templates + blank options)
     2) Editor: left tools · center canvas preview · right props · timeline
   Export: server-side FFmpeg job polled via /export-status.
   ═══════════════════════════════════════════════════════════════ */
(function(){
  if (window._studioVideoInit) return;
  window._studioVideoInit = true;

  // ── State ─────────────────────────────────────────────────────
  var _rootEl     = null;
  var _designId   = null;
  var _designName = 'Untitled Video';
  var _vd         = null;   // video_data
  var _selectedId = null;   // selected timeline item: {kind:'clip'|'text'|'element', id, handle?}
  // ── v4.4.1 canvas-drag + resize state ─────────────────────────
  var _svDragging     = false;
  var _svResizing     = false;
  var _svResizeHandle = null;
  var _svDragStart    = {x: 0, y: 0};
  var _svElementStart = {};
  var _svInteractWired = false;
  var _svZoom = 1;                // user zoom factor applied to .sv-canvas-frame
  var _svZoomWired = false;       // once-per-mount guard
  var _currentTab = 'clips';
  var _playhead   = 0;
  var _myClips    = [];     // uploaded / MiniMax clips { id, url, duration, type, width, height }
  var _saveTimer  = null;
  var _pollTimer  = null;

  // ── Helpers ──────────────────────────────────────────────────
  function _tok() { return localStorage.getItem('lu_token') || ''; }
  function _esc(s){ var d = document.createElement('div'); d.textContent = (s==null?'':String(s)); return d.innerHTML; }
  function _apiBase(){ return (window.LU_CFG && window.LU_API_BASE) ? window.LU_API_BASE : '/api'; }
  function _toast(m, t){ if (typeof showToast==='function') showToast(m, t||'info'); }
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
  function _host(){
    var parent = _rootEl || document.getElementById('studio-root') || document.body;
    try { parent.style.position = parent.style.position || 'relative'; } catch(_){}
    var h = parent.querySelector('#sv-host');
    if (!h) {
      h = document.createElement('div');
      h.id = 'sv-host';
      h.className = 'sv-host';
      parent.appendChild(h);
    }
    return h;
  }

  // ── Template → client schema normalizer (safety net) ──────────
  // The server-side POST /video/designs now normalizes on create, so newly
  // created designs already use the canonical shape. This defensive pass
  // covers designs that were created against an older server (pre-4.0.1)
  // or against a template with mixed naming conventions.
  function _normalizeVideoData(vd) {
    if (!vd || typeof vd !== 'object') return vd;
    // clip_slots[] → clips[] placeholders (only if clips is empty)
    if ((!vd.clips || !vd.clips.length) && Array.isArray(vd.clip_slots)) {
      vd.clips = vd.clip_slots.map(function(s, i){
        var start = +(s.start != null ? s.start : (s.start_time || 0));
        var end   = +(s.end   != null ? s.end   : (s.end_time   || start + 3));
        return {
          id:          s.id || 'slot_' + (i+1),
          type:        'placeholder',
          source_url:  null,
          label:       s.label || ('Clip ' + (i+1)),
          start_time:  start,
          end_time:    end,
          duration:    Math.max(0.5, end - start),
          transition_in: vd.transitions_default || { type:'fade', duration:0.5 },
        };
      });
    }
    // clips[] — promote short names
    (vd.clips || []).forEach(function(c){
      if (c.start_time == null && c.start != null) c.start_time = +c.start;
      if (c.end_time   == null && c.end   != null) c.end_time   = +c.end;
      if (c.duration   == null && c.start_time != null && c.end_time != null) c.duration = Math.max(0.5, c.end_time - c.start_time);
    });
    // text_overlays[] — canonicalize every field
    (vd.text_overlays || []).forEach(function(t, i){
      if (!t.id) t.id = 'text_' + (i+1);
      if (t.start_time == null && t.start != null) t.start_time = +t.start;
      if (t.end_time   == null && t.end   != null) t.end_time   = +t.end;
      if (t.font_size  == null && t.size  != null) t.font_size  = +t.size;
      if (t.font_weight== null && t.weight!= null) t.font_weight= String(t.weight);
      if (t.font_family== null && t.font  != null) t.font_family= String(t.font);
      if (!t.position && (t.x != null || t.y != null)) t.position = { x:+(t.x||0), y:+(t.y||0) };
      if (typeof t.animation_in === 'string') t.animation_in = { type: t.animation_in, duration: 0.4 };
      if (t.animation_in && t.animation_in.duration == null) t.animation_in.duration = 0.4;
      // drop short names so saved data is canonical
      ['start','end','size','weight','font','x','y'].forEach(function(k){ delete t[k]; });
    });
    if (!vd.audio || typeof vd.audio !== 'object') vd.audio = { url:null, volume:0.8, fade_in:0.5, fade_out:1.0 };
    return vd;
  }

  // ── Public entry ──────────────────────────────────────────────
  window.studioVideoLoad = function(rootEl) {
    _rootEl = rootEl || document.getElementById('studio-root') || document.body;
    try { _rootEl.style.position = 'relative'; } catch(_){}
    _mountGallery();
  };
  window.openStudioVideo = function(){ window.studioVideoLoad(null); };

  // ── CSS ──────────────────────────────────────────────────────
  (function injectCss(){
    if (document.getElementById('sv-css')) return;
    var s = document.createElement('style');
    s.id = 'sv-css';
    s.textContent =
      '.sv-host{position:absolute;inset:0;background:#0B0B10;color:#fff;font-family:var(--fb,system-ui,-apple-system,sans-serif);display:flex;flex-direction:column;overflow:hidden}' +
      '.sv-topbar{height:54px;display:flex;align-items:center;gap:12px;padding:0 20px;border-bottom:1px solid rgba(255,255,255,0.08);flex-shrink:0}' +
      '.sv-topbar button{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.15);color:#fff;padding:7px 14px;border-radius:6px;cursor:pointer;font:500 13px/1 inherit}' +
      '.sv-topbar button:hover{background:rgba(255,255,255,0.09)}' +
      '.sv-topbar button.primary{background:#6C5CE7;border-color:#6C5CE7}' +
      '.sv-topbar button.primary:hover{background:#5B4FD4}' +
      '.sv-topbar .title{font-weight:600;font-size:14px}' +
      '.sv-topbar .spacer{flex:1}' +
      /* Gallery */
      '.sv-gallery{flex:1;overflow:auto;padding:40px 60px}' +
      '.sv-gallery h1{font-size:26px;font-weight:700;margin-bottom:6px}' +
      '.sv-gallery .sub{color:rgba(255,255,255,0.55);font-size:13px;margin-bottom:24px}' +
      '.sv-format-row{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}' +
      '.sv-format{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#fff;padding:8px 18px;border-radius:18px;cursor:pointer;font-size:13px}' +
      '.sv-format.active{background:#6C5CE7;border-color:#6C5CE7}' +
      '.sv-blank-row{display:flex;gap:12px;margin-bottom:30px;flex-wrap:wrap}' +
      '.sv-blank{background:linear-gradient(180deg,#1a1a24,#14141c);border:1px dashed rgba(255,255,255,0.15);color:#fff;padding:16px 22px;border-radius:10px;cursor:pointer;font:500 13px/1.3 inherit;display:flex;align-items:center;gap:10px}' +
      '.sv-blank:hover{border-color:#6C5CE7;background:rgba(108,92,231,0.1)}' +
      '.sv-blank .ic{font-size:20px}' +
      '#sv-grid{display:grid;grid-template-columns:repeat(auto-fill,260px);gap:20px;justify-content:start}' +
      '.sv-card{width:260px;background:#1a1a24;border:1px solid rgba(255,255,255,0.08);border-radius:10px;overflow:hidden;cursor:pointer;transition:transform .15s,border-color .15s}' +
      '.sv-card:hover{transform:translateY(-2px);border-color:#6C5CE7}' +
      '.sv-card-preview{position:relative;background:#050810;display:flex;align-items:center;justify-content:center;overflow:hidden}' +
      '.sv-card-preview.reels{aspect-ratio:9/16}' +
      '.sv-card-preview.square{aspect-ratio:1/1}' +
      '.sv-card-preview.landscape{aspect-ratio:16/9}' +
      '.sv-card-fmt-badge{position:absolute;top:10px;left:10px;background:rgba(0,0,0,0.6);color:#fff;font:600 10px/1 inherit;padding:5px 10px;border-radius:10px;letter-spacing:1.5px;text-transform:uppercase}' +
      '.sv-card-dur-badge{position:absolute;top:10px;right:10px;background:#6C5CE7;color:#fff;font:600 10px/1 inherit;padding:5px 10px;border-radius:10px}' +
      '.sv-card-play{width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,0.14);display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff}' +
      '.sv-card-meta{padding:12px 14px}' +
      '.sv-card-name{font-weight:600;font-size:13px;line-height:1.3}' +
      '.sv-card-cat{color:rgba(255,255,255,0.45);font-size:10px;text-transform:uppercase;letter-spacing:2px;margin-top:4px}' +
      /* Editor shell */
      '.sv-shell{flex:1;display:grid;grid-template-columns:340px 1fr 300px;grid-template-rows:1fr 220px;grid-template-areas:"tools canvas props" "timeline timeline timeline";overflow:hidden}' +
      '.sv-tools{grid-area:tools;background:#14161C;border-right:1px solid rgba(255,255,255,0.08);display:flex;flex-direction:column;overflow:hidden}' +
      '.sv-tabs{display:flex;border-bottom:1px solid rgba(255,255,255,0.08);flex-wrap:wrap}' +
      '.sv-tab{flex:1 1 33%;padding:11px 4px;text-align:center;color:rgba(255,255,255,0.65);cursor:pointer;font:600 10px/1 inherit;letter-spacing:1px;text-transform:uppercase;border-bottom:2px solid transparent;min-width:80px}' +
      '.sv-tab:hover{color:#fff;background:rgba(255,255,255,0.03)}' +
      '.sv-tab.active{color:#fff;border-bottom-color:#6C5CE7}' +
      '.sv-tab-body{flex:1;overflow-y:auto;padding:14px}' +
      '.sv-tab-head{font-size:10px;text-transform:uppercase;letter-spacing:3px;color:rgba(255,255,255,0.4);margin:8px 0 10px}' +
      '.sv-btn{display:block;width:100%;background:#6C5CE7;border:0;color:#fff;padding:11px;border-radius:8px;font:600 12px/1 inherit;cursor:pointer;margin-bottom:8px}' +
      '.sv-btn.secondary{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12)}' +
      '.sv-btn:hover{filter:brightness(1.1)}' +
      '.sv-clip-thumb{background:#1a1a24;border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:8px 10px;margin-bottom:6px;cursor:grab;display:flex;gap:10px;align-items:center}' +
      '.sv-clip-thumb:hover{border-color:#6C5CE7}' +
      '.sv-clip-thumb .th{width:46px;height:46px;border-radius:6px;background:#2a2a35;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:18px}' +
      '.sv-clip-thumb .th video,.sv-clip-thumb .th img{width:100%;height:100%;object-fit:cover}' +
      '.sv-clip-thumb .meta{flex:1;min-width:0}' +
      '.sv-clip-thumb .nm{font:500 12px/1.3 inherit;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.sv-clip-thumb .dur{font:400 10px/1 inherit;color:rgba(255,255,255,0.45);margin-top:3px}' +
      '.sv-filter-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:6px}' +
      '.sv-filter{background:#1a1a24;border:1px solid rgba(255,255,255,0.08);border-radius:6px;padding:8px 10px;cursor:pointer;text-align:center;font:500 11px/1 inherit}' +
      '.sv-filter:hover,.sv-filter.active{border-color:#6C5CE7;background:rgba(108,92,231,0.15)}' +
      '.sv-field{margin-bottom:14px}' +
      '.sv-field label{display:block;font:600 10px/1 inherit;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,0.55);margin-bottom:6px}' +
      '.sv-field input,.sv-field select,.sv-field textarea{width:100%;background:rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.12);color:#fff;border-radius:6px;padding:8px 10px;font:400 13px/1.3 inherit;outline:0;box-sizing:border-box}' +
      '.sv-field input:focus,.sv-field select:focus,.sv-field textarea:focus{border-color:#6C5CE7}' +
      /* Canvas */
      '.sv-canvas-wrap{grid-area:canvas;background:#0D0D0F;background-image:radial-gradient(circle,rgba(255,255,255,0.06) 1px,transparent 1px);background-size:24px 24px;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative}' +
      '.sv-canvas-frame{background:#000;box-shadow:0 20px 80px rgba(0,0,0,0.6),0 0 0 1px rgba(255,255,255,0.06);position:relative}' +
      '.sv-canvas-frame canvas{display:block}' +
      '.sv-canvas-play-btn{position:absolute;bottom:16px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.7);border:1px solid rgba(255,255,255,0.2);color:#fff;border-radius:20px;padding:8px 18px;font:600 12px/1 inherit;cursor:pointer;display:flex;gap:8px;align-items:center;backdrop-filter:blur(6px)}' +
      '.sv-canvas-play-btn:hover{background:rgba(0,0,0,0.9)}' +
      /* Props */
      '.sv-props{grid-area:props;background:#14161C;border-left:1px solid rgba(255,255,255,0.08);overflow-y:auto;padding:16px}' +
      /* Timeline */
      '.sv-timeline{grid-area:timeline;background:#0F1016;border-top:1px solid rgba(255,255,255,0.08);display:flex;flex-direction:column;overflow:hidden}' +
      '.sv-tl-ruler{height:26px;background:#0A0B10;border-bottom:1px solid rgba(255,255,255,0.06);position:relative;overflow:hidden}' +
      '.sv-tl-ruler .tick{position:absolute;top:0;bottom:0;border-left:1px solid rgba(255,255,255,0.08);font:500 9px/26px inherit;color:rgba(255,255,255,0.45);padding-left:4px}' +
      '.sv-tl-tracks{flex:1;overflow:auto;padding:6px 0;position:relative}' +
      '.sv-tl-playhead{position:absolute;top:0;bottom:0;width:12px;margin-left:-5px;z-index:20;pointer-events:auto;cursor:ew-resize}' +
      '.sv-tl-playhead::after{content:"";position:absolute;top:0;bottom:0;left:5px;width:2px;background:#FF3B5C;pointer-events:none}' +
      '.sv-tl-playhead::before{content:"";position:absolute;top:-2px;left:0;width:12px;height:12px;background:#FF3B5C;border-radius:50%;pointer-events:none}' +
      '.sv-tl-track{position:relative;min-height:60px;margin:0 8px 6px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.05);border-radius:6px}' +
      '.sv-tl-track.text-track,.sv-tl-track.audio-track{min-height:32px}' +
      '.sv-tl-row-sep{position:absolute;left:0;right:0;height:1px;background:rgba(255,255,255,0.04);pointer-events:none}' +
      '.sv-tl-label{position:absolute;top:50%;left:8px;transform:translateY(-50%);font:500 10px/1 inherit;color:rgba(255,255,255,0.3);letter-spacing:2px;text-transform:uppercase;pointer-events:none}' +
      '.sv-tl-block{position:absolute;top:4px;bottom:4px;background:linear-gradient(180deg,#6C5CE7,#4c3fc0);border-radius:5px;cursor:grab;overflow:hidden;display:flex;align-items:center;padding:0 10px;color:#fff;font:600 11px/1 inherit;user-select:none;border:1px solid rgba(255,255,255,0.18)}' +
      '.sv-tl-block.selected{outline:2px solid #FFD600;outline-offset:-1px}' +
      '.sv-tl-block.clip{background:linear-gradient(180deg,#6C5CE7,#4c3fc0)}' +
      '.sv-tl-block.text{background:linear-gradient(180deg,#22C55E,#15803d)}' +
      '.sv-tl-block.audio{background:linear-gradient(180deg,#F59E0B,#b45309)}' +
      '.sv-tl-trim-l,.sv-tl-trim-r{position:absolute;top:0;bottom:0;width:6px;cursor:ew-resize;background:rgba(255,255,255,0.15)}' +
      '.sv-tl-trim-l{left:0;border-radius:5px 0 0 5px} .sv-tl-trim-r{right:0;border-radius:0 5px 5px 0}' +
      '.sv-tl-block:hover .sv-tl-trim-l,.sv-tl-block:hover .sv-tl-trim-r{background:rgba(255,255,255,0.35)}' +
      '.sv-tl-durcount{position:absolute;right:12px;top:4px;font:600 10px/1 inherit;color:rgba(255,255,255,0.5);letter-spacing:1px;z-index:15;background:#0A0B10;padding:4px 8px;border-radius:10px;border:1px solid rgba(255,255,255,0.08)}' +
      /* Export status overlay */
      '.sv-export-overlay{position:absolute;inset:0;background:rgba(5,7,12,0.85);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);display:flex;align-items:center;justify-content:center;z-index:100;font-family:var(--fb,"DM Sans",system-ui,sans-serif)}' +
      '.sv-export-box{background:var(--s1,#171A21);border:1px solid var(--bd,rgba(255,255,255,.07));border-radius:12px;padding:32px 40px;min-width:360px;text-align:center;color:var(--t1,#E8EDF5);box-shadow:0 30px 80px rgba(0,0,0,.65)}' +
      '.sv-export-box h3{font-family:var(--fh,Syne,sans-serif);font-size:16px;font-weight:600;letter-spacing:-0.01em;margin-bottom:8px;color:var(--t1,#E8EDF5)}' +
      '.sv-export-box .msg{color:var(--t2,#8B97B0);font-size:13px;margin-bottom:20px}' +
      '.sv-progress{width:100%;height:6px;background:var(--s3,#252A3A);border-radius:3px;overflow:hidden;margin-bottom:16px}' +
      '.sv-progress-fill{height:100%;background:linear-gradient(90deg,var(--p,#6C5CE7),var(--ac,#00E5A8));transition:width .3s ease}';
    document.head.appendChild(s);
  })();

  // ═══════════════════════════════════════════════════════════════
  // SCREEN 1 — GALLERY
  // ═══════════════════════════════════════════════════════════════
  function _mountGallery(){
    var host = _host();
    host.innerHTML =
      '<div class="sv-topbar">' +
        '<button onclick="_svBackToStudio()">\u2190 Studio</button>' +
        '<span class="title">Video</span>' +
        '<span class="spacer"></span>' +
      '</div>' +
      '<div class="sv-gallery">' +
        '<h1>Create a Video</h1>' +
        '<div class="sub">Start from a template, upload your clips, or generate with AI.</div>' +
        '<div class="sv-format-row" id="sv-format-row">' +
          '<button class="sv-format active" data-fmt="reels">Reels 9:16</button>' +
          '<button class="sv-format" data-fmt="square">Square 1:1</button>' +
          '<button class="sv-format" data-fmt="landscape">Landscape 16:9</button>' +
        '</div>' +
        '<div class="sv-blank-row">' +
          '<button class="sv-blank" data-start="blank"><span class="ic">\u2795</span> Blank canvas</button>' +
          '<button class="sv-blank" data-start="clips"><span class="ic">\u{1F3AC}</span> Upload video clips</button>' +
          '<button class="sv-blank" data-start="images"><span class="ic">\u{1F5BC}</span> Upload images (slideshow)</button>' +
          '<button class="sv-blank" data-start="ai"><span class="ic">\u2728</span> Generate with AI</button>' +
        '</div>' +
        '<div class="sv-tab-head" style="display:flex;align-items:center;gap:12px">' +
          '<span>Templates</span>' +
          '<div class="sv-type-row" style="display:inline-flex;gap:4px;margin-left:auto">' +
            '<button class="sv-type-tab active" data-type="all"           onclick="window._svSetTypeFilter(\'all\')"           style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#fff;padding:4px 10px;border-radius:12px;cursor:pointer;font:500 11px/1 inherit">All</button>' +
            '<button class="sv-type-tab"        data-type="clip_json"     onclick="window._svSetTypeFilter(\'clip_json\')"     style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#fff;padding:4px 10px;border-radius:12px;cursor:pointer;font:500 11px/1 inherit">Templates</button>' +
            '<button class="sv-type-tab"        data-type="html_animated" onclick="window._svSetTypeFilter(\'html_animated\')" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#fff;padding:4px 10px;border-radius:12px;cursor:pointer;font:500 11px/1 inherit">\u2726 Animated</button>' +
          '</div>' +
        '</div>' +
        '<style>.sv-type-tab.active{background:#6C5CE7!important;border-color:#6C5CE7!important}</style>' +
        '<div id="sv-grid"><div style="padding:40px;text-align:center;color:rgba(255,255,255,0.4)">Loading templates\u2026</div></div>' +
      '</div>';

    document.querySelectorAll('.sv-format').forEach(function(btn){
      btn.onclick = function(){
        document.querySelectorAll('.sv-format').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
        _renderGallery();
      };
    });
    document.querySelectorAll('.sv-blank').forEach(function(btn){
      btn.onclick = function(){ _newBlankDesign(btn.getAttribute('data-start')); };
    });
    _renderGallery();
  }

  function _activeFormat(){
    var el = document.querySelector('.sv-format.active');
    return el ? el.getAttribute('data-fmt') : 'reels';
  }

  var _typeFilter = 'all'; // 'all' | 'clip_json' | 'html_animated'

  function _renderGallery(){
    var fmt = _activeFormat();
    _fetchJson('/studio/video/templates?format=' + encodeURIComponent(fmt)).then(function(d){
      var grid = document.getElementById('sv-grid');
      if (!grid) return;
      var rows = d.templates || [];
      if (_typeFilter === 'clip_json')     rows = rows.filter(function(t){ return (t.template_type || 'clip_json') === 'clip_json'; });
      else if (_typeFilter === 'html_animated') rows = rows.filter(function(t){ return t.template_type === 'html_animated'; });
      if (!rows.length){ grid.innerHTML = '<div style="padding:20px;color:rgba(255,255,255,0.4)">No templates for this format/type.</div>'; return; }
      grid.innerHTML = rows.map(function(t){
        var fmtClass = t.format;
        var isAnim = t.template_type === 'html_animated';
        var thumbStyle = t.thumbnail_url
          ? ' style="background-image:url(' + JSON.stringify(t.thumbnail_url).slice(1,-1) + ');background-size:cover;background-position:center"'
          : '';
        var animBadge = isAnim
          ? '<span class="sv-card-anim-badge" style="position:absolute;top:8px;right:8px;background:linear-gradient(135deg,#7C3AED,#A78BFA);color:#fff;font:700 9px/1 inherit;padding:4px 7px;border-radius:10px;letter-spacing:.5px;text-transform:uppercase;box-shadow:0 2px 8px rgba(124,58,237,.4);z-index:2">\u2726 Animated</span>'
          : '';
        return '<div class="sv-card" data-slug="' + _esc(t.slug) + '" data-name="' + _esc(t.name) + '" data-type="' + _esc(t.template_type || 'clip_json') + '">' +
          '<div class="sv-card-preview ' + fmtClass + '"' + thumbStyle + '>' +
            animBadge +
            '<span class="sv-card-fmt-badge">' + _esc(t.format) + '</span>' +
            '<span class="sv-card-dur-badge">' + _esc(t.duration_seconds) + 's</span>' +
            (t.thumbnail_url ? '' : '<div class="sv-card-play">\u25B6</div>') +
          '</div>' +
          '<div class="sv-card-meta">' +
            '<div class="sv-card-name">' + _esc(t.name) + '</div>' +
            '<div class="sv-card-cat">' + _esc(t.category || '') + '</div>' +
          '</div>' +
        '</div>';
      }).join('');
      grid.onclick = function(ev){
        var card = ev.target.closest && ev.target.closest('.sv-card');
        if (!card) return;
        _newTemplateDesign(card.getAttribute('data-slug'), card.getAttribute('data-name'));
      };
    }).catch(function(err){
      var grid = document.getElementById('sv-grid');
      if (grid) grid.innerHTML = '<div style="padding:20px;color:#FFA4A4">Failed: ' + _esc(err.message) + '</div>';
    });
  }

  // Expose type-filter setter so inline HTML can bind to it
  window._svSetTypeFilter = function(t){
    _typeFilter = t;
    var tabs = document.querySelectorAll('.sv-type-tab');
    tabs.forEach(function(el){ el.classList.toggle('active', el.getAttribute('data-type') === t); });
    _renderGallery();
  };

  window._svBackToStudio = function(){
    // Return to main Studio image gallery
    var host = document.getElementById('sv-host');
    if (host && host.parentNode) host.parentNode.removeChild(host);
    if (typeof window.studioLoad === 'function') {
      window.studioLoad(document.getElementById('studio-root') || _rootEl);
    }
  };

  function _newTemplateDesign(slug, name){
    // First resolve template type so we can branch the editor surface.
    _fetchJson('/studio/video/templates/' + encodeURIComponent(slug)).then(function(td){
      if (!td.success) throw new Error(td.error || 'template_not_found');
      var tpl = td.template || {};
      return _fetchJson('/studio/video/designs', {
        method:'POST',
        body: JSON.stringify({ template_slug: slug, name: name })
      }).then(function(d){
        if (!d.success) throw new Error(d.error || 'create_failed');
        _designId = d.design_id;
        _designName = name;
        if (tpl.template_type === 'html_animated') {
          _mountHtmlAnimatedEditor(tpl);
        } else if (typeof window._svOpenClipEditor === 'function') {
          window._svOpenClipEditor(_designId);
        } else {
          _vd = _normalizeVideoData(d.video_data);
          _mountEditor();
        }
      });
    }).catch(function(err){ _toast('Create failed: ' + err.message, 'error'); });
  }

  // ── HTML Animated editor surface — iframe + postMessage bridge ──
  // Template.html runs CSS animations natively in the iframe; the bridge
  // script inside the template handles inline text edits and palette swaps.
  // ── v4.4.2 state for two-pane animated editor ────────────────
  var _svAnimTpl      = null;
  var _svAnimManifest = null;
  var _svAnimTab      = 'content';   // 'content' | 'colors'
  var _svAnimSaveTmr  = null;

  // Built-in palette presets. Each one targets the standard Studio aliases
  // (--primary, --secondary, --bg, --text, --text-muted) so any template that
  // maps to them gets a coherent swap.
  var _svAnimPresets = [
    { name: 'Dark Violet',  vars: { '--primary': '#7C3AED', '--secondary': '#3B82F6', '--bg': '#04050F', '--text': '#F8F9FF', '--text-muted': 'rgba(248,249,255,0.70)' } },
    { name: 'Dark Blue',    vars: { '--primary': '#3B82F6', '--secondary': '#06B6D4', '--bg': '#040A14', '--text': '#F1F5F9', '--text-muted': 'rgba(241,245,249,0.65)' } },
    { name: 'Dark Gold',    vars: { '--primary': '#F59E0B', '--secondary': '#D97706', '--bg': '#0A0700', '--text': '#FFF9E6', '--text-muted': 'rgba(255,249,230,0.62)' } },
    { name: 'Light Minimal',vars: { '--primary': '#111827', '--secondary': '#6B7280', '--bg': '#F8FAFC', '--text': '#0F172A', '--text-muted': 'rgba(15,23,42,0.60)' } },
    { name: 'Dark Red',     vars: { '--primary': '#EF4444', '--secondary': '#F87171', '--bg': '#0A0305', '--text': '#FFF1F2', '--text-muted': 'rgba(255,241,242,0.60)' } },
  ];

  function _mountHtmlAnimatedEditor(tpl){
    _svAnimTpl      = tpl;
    _svAnimManifest = null;
    _svAnimTab      = 'content';

    var host = _host();
    var w = tpl.canvas_width || 1080, h = tpl.canvas_height || 1920;
    var url = tpl.template_html_path || '';

    // Reuse the REGULAR video editor's shell: .sv-topbar + .sv-shell grid.
    // Grid override collapses to 2 columns (tools | canvas) and single row —
    // animated templates don't need the props panel or timeline.
    host.innerHTML =
      _svAnimStyles() +
      '<div class="sv-topbar">' +
        '<button onclick="window._svAnimBack()">\u2190 Back</button>' +
        '<span class="title" id="sv-design-name" contenteditable="true" spellcheck="false" style="padding:3px 6px;border-radius:4px">' + _esc(_designName || tpl.name) + '</span>' +
        '<span class="spacer"></span>' +
        '<button id="sv-anim-reload">\u21BB Replay</button>' +
        '<button id="sv-anim-save">\u{1F4BE} Save</button>' +
        '<button class="primary" id="sv-anim-export">\u2B07 Export MP4</button>' +
      '</div>' +
      '<div class="sv-shell sv-shell-anim">' +
        '<div class="sv-tools">' +
          '<div class="sv-tabs" id="sv-anim-tabs">' +
            '<div class="sv-tab active" data-tab="content">Content</div>' +
            '<div class="sv-tab"        data-tab="colors">Colors</div>' +
            '<div class="sv-tab"        data-tab="info">Info</div>' +
            '<div class="sv-tab"        data-tab="ai">AI</div>' +
          '</div>' +
          '<div class="sv-tab-body" id="sv-anim-body"><div style="color:rgba(255,255,255,0.4);padding:10px">Loading manifest\u2026</div></div>' +
        '</div>' +
        '<div class="sv-canvas-wrap" id="sv-canvas-wrap">' +
          '<div class="sv-canvas-frame" id="sv-canvas-frame" style="overflow:hidden">' +
            '<iframe id="sv-anim-iframe" src="' + _esc(url) + '" style="width:' + w + 'px;height:' + h + 'px;border:0;display:block;transform-origin:top left"></iframe>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div id="sv-anim-export-status" class="sv-anim-status"></div>';

    // Topbar controls
    document.getElementById('sv-anim-reload').onclick = _svAnimReplay;
    document.getElementById('sv-anim-save').onclick   = function(){ _svAnimSaveNow(true); };
    document.getElementById('sv-anim-export').onclick = _svAnimExport;
    var nm = document.getElementById('sv-design-name');
    if (nm) nm.addEventListener('blur', function(){
      _designName = (nm.textContent || '').trim() || 'Animated Design';
      _svAnimSaveNow(false);
    });

    // Tab switching (reuses the video editor's .sv-tab styling)
    var tabs = document.getElementById('sv-anim-tabs');
    if (tabs) tabs.onclick = function(ev){
      var el = ev.target.closest && ev.target.closest('.sv-tab');
      if (!el) return;
      window._svAnimSetTab(el.getAttribute('data-tab'));
    };

    // Fit the iframe frame. Runs now, on iframe load, and on window resize.
    _svAnimFit();
    var ifr = document.getElementById('sv-anim-iframe');
    if (ifr) ifr.addEventListener('load', _svAnimFit);
    // requestAnimationFrame pass to catch late layout (sv-tools mounting, etc.)
    requestAnimationFrame(function(){ _svAnimFit(); });
    if (!window._svAnimResizeWired){
      window._svAnimResizeWired = true;
      window.addEventListener('resize', function(){
        if (document.getElementById('sv-anim-iframe')) _svAnimFit();
      });
    }

    // Fetch manifest + render panel body + push saved state into iframe
    _svAnimLoadManifest(tpl.slug).then(function(){
      _svAnimRenderPanel();
      _svAnimApplySavedToIframe();
    });

    // Listen to postMessage from iframe (field-changed)
    window.addEventListener('message', _svAnimOnMessage);
  }

  // Scale the iframe to fit the canvas viewport.
  //
  // Key insight: we must NOT transform the frame — flex-centering in
  // .sv-canvas-wrap uses the frame's LAYOUT size, not its visual size. If
  // the frame stays 1080×1920 in layout and we scale its appearance with a
  // transform, the flex container centers based on the huge un-scaled size
  // and the visible iframe ends up off-screen, swallowing clicks.
  //
  // Instead: shrink the frame's layout dimensions to the scaled size and
  // clip; keep the iframe at its native 1080×1920 (so the template inside
  // renders at full resolution); scale the iframe down with transform.
  function _svAnimFit(){
    var wrap   = document.getElementById('sv-canvas-wrap');
    var frame  = document.getElementById('sv-canvas-frame');
    var iframe = document.getElementById('sv-anim-iframe');
    if (!wrap || !frame || !iframe || !_svAnimTpl) return;
    var W = _svAnimTpl.canvas_width  || 1080;
    var H = _svAnimTpl.canvas_height || 1920;
    var availW = wrap.clientWidth  - 60;
    var availH = wrap.clientHeight - 60;
    var s = Math.min(availW / W, availH / H, 1);
    if (!isFinite(s) || s <= 0) s = 1;

    // Frame: scaled layout size (flex centers this correctly), clip excess
    frame.style.width        = (W * s) + 'px';
    frame.style.height       = (H * s) + 'px';
    frame.style.overflow     = 'hidden';
    frame.style.transform    = '';
    frame.style.transformOrigin = '';

    // Iframe: native resolution internally, scaled down with top-left origin
    iframe.style.width           = W + 'px';
    iframe.style.height          = H + 'px';
    iframe.style.transformOrigin = 'top left';
    iframe.style.transform       = 'scale(' + s + ')';
  }

  function _svAnimStyles(){
    // Only content-specific CSS — shell/topbar/tabs/canvas-wrap are inherited
    // from the regular video editor's styles (injected at mount time in _start).
    return '<style>' +
      // 2-column grid override: tools | canvas, no props, no timeline
      '.sv-shell.sv-shell-anim{grid-template-columns:340px 1fr;grid-template-rows:1fr;grid-template-areas:"tools canvas"}' +
      // Info stripe under status when shown
      '.sv-anim-status{display:none;padding:10px 16px;border-top:1px solid rgba(255,255,255,0.08);background:#0F0F16;color:rgba(255,255,255,0.7);font-size:12px;position:absolute;left:0;right:0;bottom:0;z-index:50}' +
      // Field form inside the left tools tab body — reuses .sv-tab-body padding
      '.sv-anim-field{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}' +
      '.sv-anim-field label{font-size:10px;font-weight:600;color:rgba(255,255,255,0.5);letter-spacing:1.2px;text-transform:uppercase}' +
      '.sv-anim-field input,.sv-anim-field textarea{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:#fff;font:500 13px/1.3 inherit;padding:8px 10px;width:100%;box-sizing:border-box;resize:vertical;font-family:inherit}' +
      '.sv-anim-field input:focus,.sv-anim-field textarea:focus{border-color:rgba(108,92,231,0.6);outline:none;background:rgba(108,92,231,0.05)}' +
      '.sv-anim-color{display:flex;align-items:center;gap:10px;padding:6px 0}' +
      '.sv-anim-color label{flex:1;font:500 12px/1.2 inherit;color:rgba(255,255,255,0.7);font-family:monospace}' +
      '.sv-anim-color input[type="color"]{width:36px;height:28px;border:1px solid rgba(255,255,255,0.12);border-radius:6px;background:transparent;cursor:pointer;padding:0}' +
      '.sv-anim-preset-row{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-bottom:10px}' +
      '.sv-anim-preset{border:1px solid rgba(255,255,255,0.1);border-radius:6px;height:40px;cursor:pointer;display:flex;align-items:center;justify-content:center;font:600 9px/1 inherit;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.6);text-align:center;padding:2px}' +
      '.sv-anim-preset:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.4)}' +
      '.sv-anim-section{font:700 10px/1 inherit;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,0.4);padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.06);margin-bottom:8px;margin-top:4px}' +
      '.sv-anim-info-card{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:4px}' +
      '.sv-anim-info-item{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:10px 12px}' +
      '.sv-anim-info-item .lbl{font:600 9px/1 inherit;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,0.4)}' +
      '.sv-anim-info-item .val{font:700 16px/1.2 inherit;color:#fff;margin-top:4px}' +
      '.sv-anim-save-flash{animation:sv-save-flash .8s ease}' +
      '@keyframes sv-save-flash{0%{color:#00E5A8}100%{color:inherit}}' +
    '</style>';
  }

  function _svAnimLoadManifest(slug){
    var url = '/storage/studio-video-templates/' + encodeURIComponent(slug) + '/manifest.json?t=' + Date.now();
    return fetch(url).then(function(r){
      if (!r.ok) throw new Error('manifest_http_' + r.status);
      return r.json();
    }).then(function(m){
      _svAnimManifest = m || {};
    }).catch(function(_){
      _svAnimManifest = { variables: {}, css_vars: {} };
    });
  }

  function _svAnimRenderPanel(){
    var body = document.getElementById('sv-anim-body');
    if (!body) return;
    if      (_svAnimTab === 'content') body.innerHTML = _svAnimRenderContent();
    else if (_svAnimTab === 'colors')  body.innerHTML = _svAnimRenderColors();
    else if (_svAnimTab === 'info')    body.innerHTML = _svAnimRenderInfo();
    else if (_svAnimTab === 'ai')      body.innerHTML = _svAnimRenderAI();
    // Update active tab UI
    document.querySelectorAll('#sv-anim-tabs .sv-tab').forEach(function(el){
      el.classList.toggle('active', el.getAttribute('data-tab') === _svAnimTab);
    });
  }

  function _svAnimRenderInfo(){
    var tpl  = _svAnimTpl || {};
    var m    = _svAnimManifest || {};
    var dur  = m.animation_duration || tpl.duration_seconds || 12;
    var fps  = (_vd && _vd.fps) || 30;
    var w    = m.canvas_width  || tpl.canvas_width  || 1080;
    var h    = m.canvas_height || tpl.canvas_height || 1920;
    var varCount = Object.keys((m.variables || {})).length;
    var varsWithSaved = Object.keys((_vd && _vd.fields) || {}).length;
    return '<div class="sv-tab-head">Template Info</div>' +
      '<div class="sv-anim-info-card">' +
        '<div class="sv-anim-info-item"><div class="lbl">Duration</div><div class="val">' + dur + 's</div></div>' +
        '<div class="sv-anim-info-item"><div class="lbl">FPS</div><div class="val">' + fps + '</div></div>' +
        '<div class="sv-anim-info-item"><div class="lbl">Canvas</div><div class="val">' + w + '\u00D7' + h + '</div></div>' +
        '<div class="sv-anim-info-item"><div class="lbl">Format</div><div class="val">' + _esc(tpl.format || '—') + '</div></div>' +
        '<div class="sv-anim-info-item"><div class="lbl">Fields</div><div class="val">' + varCount + '</div></div>' +
        '<div class="sv-anim-info-item"><div class="lbl">Edited</div><div class="val">' + varsWithSaved + '</div></div>' +
      '</div>' +
      '<div class="sv-anim-section" style="margin-top:18px">Source</div>' +
      '<div style="font-size:11px;color:rgba(255,255,255,0.55);word-break:break-all">' + _esc(tpl.template_html_path || '') + '</div>';
  }

  function _svAnimRenderAI(){
    return '<div class="sv-tab-head">AI Copy Assistant</div>' +
      '<div style="font-size:12px;color:rgba(255,255,255,0.55);line-height:1.5">Describe your brand in a sentence and Arthur will fill in the template fields automatically.</div>' +
      '<textarea id="sv-anim-ai-brief" rows="5" placeholder="e.g. We\u2019re a B2B SaaS that helps marketing teams automate reporting. Audience: mid-market CMOs. Tone: confident, technical, dry humour." style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:#fff;font:500 13px/1.4 inherit;padding:10px;width:100%;box-sizing:border-box;margin-top:10px"></textarea>' +
      '<button class="sv-btn" id="sv-anim-ai-run" style="margin-top:10px">Generate fields</button>' +
      '<div id="sv-anim-ai-status" style="font-size:11px;color:rgba(255,255,255,0.5);margin-top:10px"></div>';
  }

  function _svAnimRenderContent(){
    var vars = (_svAnimManifest && _svAnimManifest.variables) || {};
    var keys = Object.keys(vars);
    if (!keys.length){
      return '<div style="padding:20px;color:rgba(255,255,255,0.4);font-size:12px">This template exposes no editable fields. Edit the manifest.json to declare variables.</div>';
    }
    var saved = (_vd && _vd.fields) || {};
    var html  = '<div class="sv-anim-section">Text Fields</div>';
    keys.forEach(function(k){
      var def   = vars[k] || {};
      var label = _esc(def.label || k);
      var val   = saved[k] != null ? saved[k] : (def.default != null ? def.default : '');
      var isLong = (def.type === 'textarea') ||
                   (typeof val === 'string' && (val.length > 80 || /\n/.test(val))) ||
                   /subtext|description|body|copy/i.test(k);
      var elTag = isLong
        ? '<textarea rows="3" data-field="' + _esc(k) + '" oninput="window._svAnimFieldChange(this)">' + _esc(val) + '</textarea>'
        : '<input type="text" data-field="' + _esc(k) + '" value="' + _esc(val).replace(/"/g,'&quot;') + '" oninput="window._svAnimFieldChange(this)">';
      html += '<div class="sv-anim-field"><label>' + label + '</label>' + elTag + '</div>';
    });
    return html;
  }

  function _svAnimRenderColors(){
    var css = (_svAnimManifest && _svAnimManifest.css_vars) || {};
    // Presets
    var html = '<div class="sv-anim-section">Palette Presets</div>' +
      '<div class="sv-anim-preset-row">' +
      _svAnimPresets.map(function(p, i){
        var bg = p.vars['--bg'] || '#111';
        var fg = p.vars['--primary'] || '#fff';
        return '<div class="sv-anim-preset" onclick="window._svAnimApplyPreset(' + i + ')" style="background:linear-gradient(135deg,' + bg + ' 60%,' + fg + ' 100%)" title="' + _esc(p.name) + '">' + _esc(p.name.split(' ')[1] || p.name) + '</div>';
      }).join('') +
      '</div>';
    // Individual CSS var pickers
    html += '<div class="sv-anim-section">Custom Colors</div>';
    var keys = Object.keys(css);
    if (!keys.length){
      html += '<div style="color:rgba(255,255,255,0.4);font-size:12px">No CSS variables declared in manifest.css_vars.</div>';
    } else {
      keys.forEach(function(k){
        var raw = (_vd && _vd.palette_vars && _vd.palette_vars[k]) || css[k] || '#000000';
        var hex = _svAnimToHex(raw);
        html += '<div class="sv-anim-color">' +
          '<label>' + _esc(k) + '</label>' +
          '<input type="color" data-var="' + _esc(k) + '" value="' + _esc(hex) + '" oninput="window._svAnimColorChange(this)">' +
          '</div>';
      });
    }
    return html;
  }

  // rgba(..) or named → #rrggbb hex fallback (#000000 if not parseable)
  function _svAnimToHex(v){
    if (!v) return '#000000';
    if (v[0] === '#' && (v.length === 7 || v.length === 4)) return v.length === 7 ? v : ('#' + v[1]+v[1] + v[2]+v[2] + v[3]+v[3]);
    var m = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i.exec(v);
    if (m){
      var to = function(n){ n = parseInt(n,10); return ('0' + n.toString(16)).slice(-2); };
      return '#' + to(m[1]) + to(m[2]) + to(m[3]);
    }
    return '#000000';
  }

  window._svAnimSetTab = function(t){
    _svAnimTab = t;
    _svAnimRenderPanel();
  };

  window._svAnimFieldChange = function(inp){
    var field = inp.getAttribute('data-field');
    var val   = inp.value;
    if (!_vd.fields) _vd.fields = {};
    _vd.fields[field] = val;
    // Push to iframe
    var f = document.getElementById('sv-anim-iframe');
    if (f && f.contentWindow){
      try { f.contentWindow.postMessage({ type: 'studio-update-field', field: field, value: val }, '*'); } catch(_){}
    }
    _svAnimDebouncedSave();
  };

  window._svAnimColorChange = function(inp){
    var k = inp.getAttribute('data-var');
    var v = inp.value;
    if (!_vd.palette_vars) _vd.palette_vars = {};
    _vd.palette_vars[k] = v;
    var f = document.getElementById('sv-anim-iframe');
    if (f && f.contentWindow){
      var vars = {}; vars[k] = v;
      try { f.contentWindow.postMessage({ type: 'apply-palette', vars: vars }, '*'); } catch(_){}
    }
    _svAnimDebouncedSave();
  };

  window._svAnimApplyPreset = function(idx){
    var preset = _svAnimPresets[idx]; if (!preset) return;
    if (!_vd.palette_vars) _vd.palette_vars = {};
    Object.keys(preset.vars).forEach(function(k){ _vd.palette_vars[k] = preset.vars[k]; });
    var f = document.getElementById('sv-anim-iframe');
    if (f && f.contentWindow){
      try { f.contentWindow.postMessage({ type: 'apply-palette', vars: preset.vars }, '*'); } catch(_){}
    }
    // Re-render colors tab so picker inputs reflect the new values
    if (_svAnimTab === 'colors') _svAnimRenderPanel();
    _svAnimDebouncedSave();
  };

  function _svAnimDebouncedSave(){
    clearTimeout(_svAnimSaveTmr);
    _svAnimSaveTmr = setTimeout(function(){ _svAnimSaveNow(false); }, 800);
  }

  function _svAnimSaveNow(flash){
    if (!_designId || !_vd) return Promise.resolve();
    var btn = document.getElementById('sv-anim-save');
    if (btn && flash){ btn.textContent = 'Saving\u2026'; btn.disabled = true; }
    return _fetchJson('/studio/video/designs/' + _designId, {
      method: 'PUT',
      body: JSON.stringify({ video_data: _vd, name: _designName })
    }).then(function(){
      if (btn){
        btn.classList.add('sv-anim-save-flash');
        btn.textContent = 'Saved \u2713';
        setTimeout(function(){ btn.textContent = 'Save'; btn.disabled = false; btn.classList.remove('sv-anim-save-flash'); }, 1200);
      }
    }).catch(function(){
      if (btn){ btn.textContent = 'Save failed'; setTimeout(function(){ btn.textContent = 'Save'; btn.disabled = false; }, 1500); }
    });
  }

  function _svAnimReplay(){
    var f = document.getElementById('sv-anim-iframe');
    var tpl = _svAnimTpl; if (!f || !tpl) return;
    f.src = (tpl.template_html_path || '') + '?t=' + Date.now();
    f.addEventListener('load', function onload(){
      f.removeEventListener('load', onload);
      _svAnimApplySavedToIframe();
    });
  }

  // Push saved fields + palette into the iframe once it's ready.
  function _svAnimApplySavedToIframe(){
    var f = document.getElementById('sv-anim-iframe');
    if (!f || !f.contentWindow) return;
    var send = function(){
      try {
        var fields = (_vd && _vd.fields) || {};
        Object.keys(fields).forEach(function(k){
          f.contentWindow.postMessage({ type: 'studio-update-field', field: k, value: fields[k] }, '*');
        });
        var vars = (_vd && _vd.palette_vars) || {};
        if (Object.keys(vars).length){
          f.contentWindow.postMessage({ type: 'apply-palette', vars: vars }, '*');
        }
      } catch(_){}
    };
    // Run once now + once on next load just in case
    setTimeout(send, 300);
  }

  function _svAnimOnMessage(e){
    if (!e.data || !e.data.type) return;
    if (e.data.type === 'field-changed'){
      var input = document.querySelector(
        '.sv-anim-field input[data-field="' + e.data.field + '"], ' +
        '.sv-anim-field textarea[data-field="' + e.data.field + '"]'
      );
      if (input) input.value = e.data.value;
      if (!_vd.fields) _vd.fields = {};
      _vd.fields[e.data.field] = e.data.value;
      _svAnimDebouncedSave();
    }
    // image-clicked: future — would trigger openMediaPicker here
  }

  window._svAnimBack = function(){
    window.removeEventListener('message', _svAnimOnMessage);
    var host = _host();
    host.innerHTML = '';
    studioVideoLoad(_rootEl);
  };

  function _svAnimExport(){
    var s = document.getElementById('sv-anim-export-status');
    if (s) { s.style.display = 'block'; s.textContent = 'Queuing export\u2026'; }
    _fetchJson('/studio/video/designs/' + _designId + '/export', { method: 'POST' }).then(function(d){
      if (!d.success) throw new Error(d.error || 'export_failed');
      if (s) s.textContent = 'Rendering\u2026 (screen-record can take 1\u20132 minutes for a ' + (d.duration || 12) + 's reel)';
      _svAnimPoll();
    }).catch(function(err){
      if (s) { s.style.color = '#FFA4A4'; s.textContent = 'Export failed: ' + err.message; }
    });
  }
  function _svAnimPoll(){
    clearTimeout(_pollTimer);
    _pollTimer = setTimeout(function(){
      _fetchJson('/studio/video/designs/' + _designId + '/export-status').then(function(d){
        var s = document.getElementById('sv-anim-export-status');
        if (!s) return;
        var st = (d.export_status || '').toLowerCase();
        var pct = d.export_progress_pct || 0;
        if (st === 'done' && d.exported_video_url) {
          s.style.color = '#9AE6B4';
          s.innerHTML = '\u2714 Exported. <a href="' + _esc(d.exported_video_url) + '" download style="color:#6C5CE7;font-weight:600">Download MP4</a>';
          return;
        }
        if (st === 'failed') {
          s.style.color = '#FFA4A4';
          s.textContent = 'Render failed: ' + (d.export_error || 'unknown');
          return;
        }
        s.textContent = 'Rendering\u2026 ' + pct + '% (may take 1\u20132 minutes)';
        _svAnimPoll();
      }).catch(function(){ _svAnimPoll(); });
    }, 2500);
  }

  function _newBlankDesign(start){
    var fmt = _activeFormat();
    _fetchJson('/studio/video/designs', {
      method:'POST',
      body: JSON.stringify({ format: fmt, name: 'Untitled Video' })
    }).then(function(d){
      if (!d.success) throw new Error(d.error || 'create_failed');
      _designId = d.design_id;
      _designName = 'Untitled Video';
      _vd = _normalizeVideoData(d.video_data);
      _mountEditor();
      // Preselect a tab based on how they started
      if (start === 'clips')  _switchTab('clips');
      if (start === 'images') _switchTab('clips');
      if (start === 'ai')     _switchTab('ai');
    }).catch(function(err){ _toast('Create failed: ' + err.message, 'error'); });
  }

  // ═══════════════════════════════════════════════════════════════
  // SCREEN 2 — EDITOR
  // ═══════════════════════════════════════════════════════════════
  function _mountEditor(){
    var host = _host();
    host.innerHTML =
      '<div class="sv-topbar">' +
        '<button onclick="_svBackToGallery()">\u2190 Back</button>' +
        '<span class="title" id="sv-design-name" contenteditable="true" spellcheck="false" style="padding:3px 6px;border-radius:4px">' + _esc(_designName) + '</span>' +
        '<span class="spacer"></span>' +
        '<button onclick="_svSave()">\u{1F4BE} Save</button>' +
        '<button class="primary" onclick="_svExport()">\u2B07 Export MP4</button>' +
      '</div>' +
      '<div class="sv-shell">' +
        '<div class="sv-tools">' +
          '<div class="sv-tabs" id="sv-tabs"></div>' +
          '<div class="sv-tab-body" id="sv-tab-body"><div style="color:rgba(255,255,255,0.4);padding:10px">Loading\u2026</div></div>' +
        '</div>' +
        '<div class="sv-canvas-wrap" id="sv-canvas-wrap">' +
          '<div class="sv-canvas-frame" id="sv-canvas-frame">' +
            '<canvas id="sv-canvas"></canvas>' +
          '</div>' +
          '<button class="sv-canvas-play-btn" onclick="_svPlayToggle()"><span id="sv-play-icon">\u25B6</span><span id="sv-play-time">0.0s / ' + (_vd && _vd.duration || 0) + 's</span></button>' +
        '</div>' +
        '<div class="sv-props" id="sv-props"><div style="color:rgba(255,255,255,0.4)">Select a clip or text to edit.</div></div>' +
        '<div class="sv-timeline">' +
          '<div class="sv-tl-durcount" id="sv-tl-dur">0.0s / 60s</div>' +
          '<div class="sv-tl-ruler" id="sv-tl-ruler"></div>' +
          '<div class="sv-tl-tracks" id="sv-tl-tracks"></div>' +
        '</div>' +
      '</div>';

    var nm = document.getElementById('sv-design-name');
    if (nm) nm.addEventListener('blur', function(){ _designName = (nm.textContent||'').trim()||'Untitled Video'; _queueSave(); });

    _renderTabs();
    _switchTab('clips');

    // Auto-seek the playhead to a moment where most overlays are active, so
    // the user sees every element immediately instead of a blank BG at t=0.
    // Picks the LATEST start_time across text_overlays+elements (plus 0.3s).
    if (_vd) {
      var starts = [];
      (_vd.text_overlays || []).forEach(function(t){ if (typeof t.start_time === 'number') starts.push(t.start_time); });
      (_vd.elements      || []).forEach(function(e){ if (typeof e.start_time === 'number') starts.push(e.start_time); });
      if (starts.length) {
        var latestStart = Math.max.apply(null, starts);
        var target = Math.min((_vd.duration || 12) - 0.1, latestStart + 0.3);
        if (target > _playhead) _playhead = target;
      }
    }

    _drawCanvas();
    _renderTimeline();
    if (typeof _updatePlayheadUI === 'function') _updatePlayheadUI();
    // Wire canvas-wrap zoom (wheel + touch pinch) — once per editor mount
    _svWireCanvasZoom();
  }

  window._svBackToGallery = function(){
    var proceed = function(ok){
      if (!ok) return;
      _stopPlayback();
      _designId = null; _vd = null; _selectedId = null;
      _mountGallery();
    };
    if (typeof window.luConfirm === 'function') {
      window.luConfirm('Leave the editor? Unsaved changes will be lost.', {
        title: 'Leave editor?', okLabel: 'Leave', cancelLabel: 'Stay',
        destructive: true
      }).then(proceed);
    } else {
      proceed(confirm('Leave the editor? Unsaved changes will be lost.'));
    }
  };

  // ── Tabs ──────────────────────────────────────────────────────
  var _TABS = [
    { id:'clips',    label:'Clips'    },
    { id:'text',     label:'Text'     },
    { id:'elements', label:'Elements' },
    { id:'filters',  label:'Filters'  },
    { id:'audio',    label:'Audio'    },
    { id:'ai',       label:'AI'       },
  ];
  function _renderTabs(){
    var tabs = document.getElementById('sv-tabs');
    tabs.innerHTML = _TABS.map(function(t){
      return '<div class="sv-tab' + (t.id===_currentTab ? ' active':'') + '" data-tab="' + t.id + '">' + t.label + '</div>';
    }).join('');
    tabs.onclick = function(ev){
      var el = ev.target.closest && ev.target.closest('.sv-tab');
      if (el) _switchTab(el.getAttribute('data-tab'));
    };
  }
  function _switchTab(id){
    _currentTab = id; _renderTabs();
    var body = document.getElementById('sv-tab-body');
    if (!body) return;
    if (id==='clips')    return _renderClipsTab(body);
    if (id==='text')     return _renderTextTab(body);
    if (id==='elements') return _renderElementsTab(body);
    if (id==='filters')  return _renderFiltersTab(body);
    if (id==='audio')    return _renderAudioTab(body);
    if (id==='ai')       return _renderAITab(body);
  }

  function _renderClipsTab(body){
    var html = '<div class="sv-tab-head">Add Clips</div>' +
      '<button class="sv-btn" id="sv-upload-video">\u{1F3AC} Upload video</button>' +
      '<button class="sv-btn secondary" id="sv-upload-image">\u{1F5BC} Upload image</button>' +
      '<input type="file" id="sv-file-video" accept="video/*" style="display:none">' +
      '<input type="file" id="sv-file-image" accept="image/*" multiple style="display:none">' +
      '<div class="sv-tab-head" style="margin-top:18px">My Clips</div>' +
      '<div id="sv-my-clips"></div>';
    body.innerHTML = html;
    document.getElementById('sv-upload-video').onclick = function(){ document.getElementById('sv-file-video').click(); };
    document.getElementById('sv-upload-image').onclick = function(){ document.getElementById('sv-file-image').click(); };
    document.getElementById('sv-file-video').onchange = function(e){ _handleVideoUpload(e.target.files[0]); e.target.value=''; };
    document.getElementById('sv-file-image').onchange = function(e){ _handleImagesUpload(e.target.files); e.target.value=''; };
    _renderMyClips();
  }

  function _renderMyClips(){
    var el = document.getElementById('sv-my-clips');
    if (!el) return;
    if (!_myClips.length){ el.innerHTML = '<div style="color:rgba(255,255,255,0.4);font-size:12px">No clips yet. Upload or generate with AI.</div>'; return; }
    el.innerHTML = _myClips.map(function(c){
      var thumb = c.type==='image'
        ? '<img src="' + _esc(c.url) + '" alt="">'
        : '<video src="' + _esc(c.url) + '" muted></video>';
      return '<div class="sv-clip-thumb" data-id="' + _esc(c.id) + '">' +
        '<div class="th">' + thumb + '</div>' +
        '<div class="meta">' +
          '<div class="nm">' + _esc(c.name||c.type) + '</div>' +
          '<div class="dur">' + (c.duration||0).toFixed(1) + 's \u00b7 ' + _esc(c.type) + '</div>' +
        '</div>' +
        '<button class="sv-btn secondary" style="width:auto;padding:5px 10px;margin:0;font-size:10px" data-add="' + _esc(c.id) + '">+ Add</button>' +
      '</div>';
    }).join('');
    el.onclick = function(ev){
      var btn = ev.target.closest && ev.target.closest('[data-add]');
      if (btn){ _addClipToTimeline(btn.getAttribute('data-add')); return; }
    };
  }

  function _handleVideoUpload(file){
    if (!file) return;
    if (file.size > 100*1024*1024){ _toast('Max 100MB', 'error'); return; }
    var fd = new FormData(); fd.append('file', file);
    _toast('Uploading\u2026', 'info');
    fetch(_apiBase() + '/studio/video/upload-clip', {
      method:'POST', headers: { 'Authorization':'Bearer ' + _tok() }, body: fd
    }).then(function(r){ return r.json(); }).then(function(d){
      if (!d.success) throw new Error(d.error || 'upload_failed');
      _myClips.push({ id:'c_'+Date.now(), name:file.name, url:d.clip_url, duration:d.duration||3, type:'video', width:d.width, height:d.height });
      _renderMyClips();
      _toast('Uploaded', 'success');
    }).catch(function(err){ _toast('Upload failed: ' + err.message, 'error'); });
  }

  function _handleImagesUpload(files){
    if (!files || !files.length) return;
    var arr = Array.from(files);
    var uploads = arr.map(function(file){
      var fd = new FormData(); fd.append('file', file);
      return fetch(_apiBase() + '/studio/video/upload-image', {
        method:'POST', headers:{'Authorization':'Bearer '+_tok()}, body:fd
      }).then(function(r){ return r.json(); }).then(function(d){
        if (!d.success) throw new Error(d.error||'image_upload_failed');
        _myClips.push({ id:'i_'+Date.now()+'_'+Math.random().toString(36).slice(2,5), name:file.name, url:d.image_url, duration:3, type:'image', width:d.width, height:d.height });
      });
    });
    _toast('Uploading ' + arr.length + ' image' + (arr.length===1?'':'s') + '\u2026', 'info');
    Promise.all(uploads).then(function(){
      _renderMyClips();
      _toast('Uploaded ' + arr.length + ' image' + (arr.length===1?'':'s'), 'success');
      // If user hasn't added any clips yet and uploaded ≥2 images, offer auto-slideshow
      if ((!_vd.clips || !_vd.clips.length) && arr.length >= 2) {
        var msg = 'Build a slideshow from these ' + arr.length + ' images automatically?';
        if (typeof window.luConfirm === 'function') {
          window.luConfirm(msg, { title:'Auto-slideshow', okLabel:'Build', cancelLabel:'Skip' }).then(function(ok){ if (ok) _autoSlideshow(); });
        } else {
          if (confirm(msg)) _autoSlideshow();
        }
      }
    }).catch(function(err){ _toast('Upload failed: ' + err.message, 'error'); });
  }

  function _autoSlideshow(){
    // Place all uploaded images at 3s each with fade transitions
    _vd.clips = []; var t = 0;
    _myClips.filter(function(c){ return c.type==='image'; }).forEach(function(c, i){
      _vd.clips.push({
        id:'clip_'+Date.now()+'_'+i, type:'image', source_url:c.url,
        start_time: t, end_time: t+3, duration: 3,
        transition_in: { type: i===0?'fade':'fade', duration: 0.4 },
        ken_burns: { enabled: true, start_scale:1.0, end_scale:1.08, start_x:0, end_x:0 },
      });
      t += 3;
    });
    _vd.duration = Math.max(_vd.duration||15, Math.ceil(t));
    _renderTimeline(); _drawCanvas(); _queueSave();
  }

  function _addClipToTimeline(clipId){
    var c = _myClips.find(function(x){ return x.id===clipId; });
    if (!c) return;
    _vd.clips = _vd.clips || [];
    // If a template placeholder slot is open, FILL that slot in place
    // (preserves the template's timing + transitions). Otherwise append.
    var slot = _vd.clips.find(function(x){ return x.type === 'placeholder' || !x.source_url; });
    if (slot) {
      slot.type        = c.type;
      slot.source_url  = c.url;
      slot.trim_start  = 0;
      slot.trim_end    = c.duration || (slot.end_time - slot.start_time);
      delete slot.label;
      _renderTimeline(); _drawCanvas(); _queueSave();
      return;
    }
    // Append at end
    var last = _vd.clips.slice(-1)[0];
    var start = last ? last.end_time : 0;
    var dur = Math.min(c.duration||3, Math.max(1, 60 - start));
    if (start >= 60){ _toast('60 second limit reached', 'error'); return; }
    _vd.clips.push({
      id:'clip_'+Date.now(), type:c.type, source_url:c.url,
      start_time: start, end_time: start+dur, duration: dur,
      trim_start: 0, trim_end: c.duration||dur,
      transition_in: { type:'fade', duration: 0.5 },
    });
    _vd.duration = Math.max(_vd.duration||15, Math.ceil(start+dur));
    _renderTimeline(); _drawCanvas(); _queueSave();
  }

  // ── Text tab ────────────────────────────────────────────────
  // Type presets: per element_type defaults used when user clicks an
  // "+ Add <type>" button. Keeps newly-added overlays pre-styled.
  var _ET_PRESETS = {
    brand_name:      { label:'Brand name',      font_size:32,  font_weight:'700', anchor:'left',   color:'#FFFFFF', anim:'fade',      content:'Brand Name' },
    brand_pill:      { label:'Brand pill',      font_size:18,  font_weight:'600', anchor:'right',  color:'#FFFFFF', anim:'fade',      content:'Free Trial \u2192' },
    eyebrow:         { label:'Eyebrow',         font_size:20,  font_weight:'600', anchor:'left',   color:'#FFFFFF', anim:'slide_up',  content:'PRE-HEADLINE' },
    headline:        { label:'Headline',        font_size:128, font_weight:'900', anchor:'left',   color:'#FFFFFF', anim:'slide_up',  content:'Headline' },
    headline_accent: { label:'Headline accent', font_size:128, font_weight:'900', anchor:'left',   color:'#FF5757', anim:'slide_up',  content:'Accent.',    outline:true },
    subtext:         { label:'Subtext',         font_size:28,  font_weight:'400', anchor:'left',   color:'#FFFFFF', anim:'fade',      content:'Short description copy that sits under the headline.' },
    feature_pill:    { label:'Feature pill',    font_size:20,  font_weight:'600', anchor:'left',   color:'#FFFFFF', anim:'fade',      content:'Feature' },
    cta:             { label:'CTA button',      font_size:28,  font_weight:'700', anchor:'center', color:'#FFFFFF', anim:'scale_pop', content:'Get Started' },
    cta_ghost:       { label:'CTA ghost',       font_size:20,  font_weight:'500', anchor:'left',   color:'#FFFFFF', anim:'fade',      content:'See how it works \u2193' },
    stat_value:      { label:'Stat value',      font_size:72,  font_weight:'900', anchor:'center', color:'#FFFFFF', anim:'scale_pop', content:'99%' },
    stat_label:      { label:'Stat label',      font_size:16,  font_weight:'500', anchor:'center', color:'#FFFFFF', anim:'fade',      content:'Label' },
    price_value:     { label:'Price',           font_size:96,  font_weight:'900', anchor:'left',   color:'#FFFFFF', anim:'scale_pop', content:'$49' },
    price_period:    { label:'Price period',    font_size:24,  font_weight:'500', anchor:'left',   color:'#FFFFFF', anim:'fade',      content:'/month' },
    quote:           { label:'Quote',           font_size:36,  font_weight:'400', anchor:'left',   color:'#FFFFFF', anim:'fade',      content:'\u201cShort testimonial.\u201d' },
    badge_float:     { label:'Floating badge',  font_size:18,  font_weight:'600', anchor:'left',   color:'#FFFFFF', anim:'slide_up',  content:'\ud83d\udd25 Just launched' },
    badge_live:      { label:'Live badge',      font_size:14,  font_weight:'700', anchor:'left',   color:'#FFFFFF', anim:'fade',      content:'LIVE', loop:'pulse' },
    listing_line:    { label:'Listing row',     font_size:20,  font_weight:'500', anchor:'left',   color:'#FFFFFF', anim:'slide_up',  content:'Listing item \u2014 $1,200' },
    url:             { label:'URL',             font_size:18,  font_weight:'400', anchor:'left',   color:'#FFFFFF', anim:'fade',      content:'companyname.com' },
    handle:          { label:'Handle',          font_size:18,  font_weight:'500', anchor:'right',  color:'#FFFFFF', anim:'fade',      content:'@handle' },
  };
  // Visual grouping for the Add panel — 5 clusters, 19 types.
  var _ET_GROUPS = [
    ['Headlines',  ['eyebrow','headline','headline_accent','subtext']],
    ['Actions',    ['cta','cta_ghost','feature_pill']],
    ['Stats',      ['stat_value','stat_label']],
    ['Pricing',    ['price_value','price_period']],
    ['Brand',      ['brand_name','brand_pill','url','handle']],
    ['Callouts',   ['quote','badge_float','badge_live','listing_line']],
  ];

  function _renderTextTab(body){
    var groupsHtml = _ET_GROUPS.map(function(g){
      var label = g[0], types = g[1];
      var btns = types.map(function(t){
        var p = _ET_PRESETS[t] || { label: t };
        return '<button class="sv-btn secondary sv-et-btn" data-et="' + t + '" style="text-align:left;margin:3px 3px 0 0;padding:6px 10px;font-size:12px">+ ' + _esc(p.label) + '</button>';
      }).join('');
      return '<div style="margin-top:10px;color:rgba(255,255,255,.55);font-size:10px;letter-spacing:.08em;text-transform:uppercase">' + label + '</div>' +
             '<div style="display:flex;flex-wrap:wrap;gap:2px;margin-top:4px">' + btns + '</div>';
    }).join('');
    body.innerHTML =
      '<div class="sv-tab-head">Add Text</div>' +
      '<button class="sv-btn" id="sv-add-text">+ Generic text overlay</button>' +
      groupsHtml +
      '<div class="sv-tab-head" style="margin-top:14px">Overlays</div>' +
      '<div id="sv-text-list"></div>';
    document.getElementById('sv-add-text').onclick = _addTextOverlay;
    body.querySelectorAll('.sv-et-btn').forEach(function(btn){
      btn.onclick = function(){ _addTypedTextOverlay(btn.getAttribute('data-et')); };
    });
    _renderTextList();
  }

  function _addTypedTextOverlay(type){
    var p = _ET_PRESETS[type] || _ET_PRESETS.subtext;
    var id = type + '_' + Date.now();
    _vd.text_overlays = _vd.text_overlays || [];
    var t = {
      id: id,
      element_type: type,
      content: p.content || 'Your Text',
      start_time: 0,
      end_time: Math.min(_vd.duration || 12, 6),
      position: { x: _vd.canvas_width/2, y: _vd.canvas_height/2 },
      anchor: p.anchor || 'left',
      font_family: 'Inter',
      font_size: p.font_size || 32,
      font_weight: p.font_weight || '700',
      color: p.color || '#FFFFFF',
      animation_in: { type: p.anim || 'fade', duration: 0.5 },
    };
    if (p.outline) t.outline = true;
    if (p.loop)    t.animation_loop = { type: p.loop, period: 1.2 };
    _vd.text_overlays.push(t);
    _selectedId = { kind:'text', id: id };
    _renderTimeline(); _drawCanvas(); _renderProps(); _renderTextList(); _queueSave();
  }
  function _renderTextList(){
    var el = document.getElementById('sv-text-list'); if (!el) return;
    var list = _vd.text_overlays || [];
    if (!list.length){ el.innerHTML = '<div style="color:rgba(255,255,255,0.4);font-size:12px">No text yet.</div>'; return; }
    el.innerHTML = list.map(function(t){
      var et = t.element_type || 'subtext';
      var chip = '<span data-sv-type-chip style="display:inline-block;padding:1px 6px;margin-right:6px;border-radius:3px;font-size:10px;line-height:14px;font-weight:600;background:rgba(200,255,87,.12);color:#C8FF57;border:1px solid rgba(200,255,87,.35)">' + _esc(et) + '</span>';
      return '<div class="sv-clip-thumb" data-id="' + _esc(t.id) + '">' +
        '<div class="th" style="font-size:14px;color:#C8FF57">T</div>' +
        '<div class="meta"><div class="nm">' + chip + _esc((t.content||'').substring(0,40)) + '</div>' +
        '<div class="dur">' + (t.start_time||0) + 's \u2192 ' + (t.end_time||0) + 's</div></div>' +
      '</div>';
    }).join('');
    el.onclick = function(ev){
      var item = ev.target.closest && ev.target.closest('[data-id]');
      if (!item) return;
      _selectedId = { kind:'text', id: item.getAttribute('data-id') };
      _renderProps(); _renderTimeline(); _drawCanvas();
    };
  }
  function _addTextOverlay(){
    var id = 'text_'+Date.now();
    _vd.text_overlays = _vd.text_overlays || [];
    _vd.text_overlays.push({
      id: id, element_type:'subtext', content:'Your Text', start_time:0, end_time:3,
      position:{ x: _vd.canvas_width/2, y: _vd.canvas_height/2 },
      anchor:'center', font_family:'Inter', font_size:72, font_weight:'700',
      color:'#FFFFFF', animation_in:{ type:'fade', duration:0.4 },
    });
    _selectedId = { kind:'text', id: id };
    _renderTimeline(); _drawCanvas(); _renderProps(); _renderTextList(); _queueSave();
  }

  // ── Elements tab (Slice A: simple shapes + upload logo) ───
  function _renderElementsTab(body){
    body.innerHTML =
      '<div class="sv-tab-head">Add Element</div>' +
      '<button class="sv-btn" id="sv-el-logo">\u{1F3F7} Logo</button>' +
      '<button class="sv-btn secondary" id="sv-el-lowerthird">\u{1F4CA} Lower Third</button>' +
      '<button class="sv-btn secondary" id="sv-el-badge">\u{1F4CC} Badge</button>' +
      '<button class="sv-btn secondary" id="sv-el-countdown">\u23F3 Countdown</button>' +
      '<input type="file" id="sv-el-file" accept="image/*" style="display:none">' +
      '<div class="sv-tab-head" style="margin-top:16px">On this video</div>' +
      '<div id="sv-el-list"></div>';

    document.getElementById('sv-el-logo').onclick = function(){
      document.getElementById('sv-el-file').click();
    };
    document.getElementById('sv-el-file').onchange = function(e){
      var f = e.target.files[0]; if (!f) return;
      var fd = new FormData(); fd.append('file', f);
      _toast('Uploading logo\u2026', 'info');
      fetch(_apiBase() + '/studio/video/upload-image', {
        method:'POST', headers:{'Authorization':'Bearer '+_tok()}, body:fd
      }).then(function(r){ return r.json(); }).then(function(d){
        if (!d.success) throw new Error(d.error||'upload_failed');
        _addElement({ type:'logo', content:{ url:d.image_url }, size:{ width:200, height:80 }, position:{ x:80, y:80 } });
        _toast('Logo added', 'success');
      }).catch(function(err){ _toast('Upload failed: ' + err.message, 'error'); });
      e.target.value = '';
    };
    document.getElementById('sv-el-lowerthird').onclick = function(){
      _addElement({ type:'lower_third', content:{ name:'Sarah Chen', title:'Founder, Studio Forty', color:'#C9A56A' }, position:{ x:80, y:_vd.canvas_height - 240 }, size:{ width: _vd.canvas_width * 0.6 } });
    };
    document.getElementById('sv-el-badge').onclick = function(){
      _addElement({ type:'badge', content:{ text:'NEW', bg_color:'#6C5CE7', text_color:'#FFFFFF' }, position:{ x:_vd.canvas_width - 200, y:80 } });
    };
    document.getElementById('sv-el-countdown').onclick = function(){
      _addElement({ type:'countdown', content:{ end_text:'GO!', color:'#FFFFFF' }, size:{ font_size:260 }, start_time:0, end_time:4 });
    };
    _renderElementList();
  }
  function _addElement(spec){
    _vd.elements = _vd.elements || [];
    var el = Object.assign({
      id: spec.type + '_' + Date.now(),
      start_time: 0,
      end_time: Math.min(_vd.duration || 15, (spec.end_time != null) ? spec.end_time : (_vd.duration || 15)),
      opacity: 1,
    }, spec);
    _vd.elements.push(el);
    _selectedId = { kind:'element', id: el.id };
    _renderTimeline(); _drawCanvas(); _renderProps(); _renderElementList(); _queueSave();
  }
  function _renderElementList(){
    var el = document.getElementById('sv-el-list'); if (!el) return;
    var list = _vd.elements || [];
    if (!list.length){ el.innerHTML = '<div style="color:rgba(255,255,255,0.4);font-size:12px">No elements yet.</div>'; return; }
    el.innerHTML = list.map(function(e){
      var icon = e.type==='logo' ? '\u{1F3F7}' : e.type==='lower_third' ? '\u{1F4CA}' : e.type==='badge' ? '\u{1F4CC}' : '\u23F3';
      var label = e.type==='logo' ? 'Logo' : e.type==='lower_third' ? ((e.content&&e.content.name)||'Lower third') : e.type==='badge' ? ((e.content&&e.content.text)||'Badge') : 'Countdown';
      return '<div class="sv-clip-thumb" data-id="' + _esc(e.id) + '">' +
        '<div class="th" style="font-size:16px">' + icon + '</div>' +
        '<div class="meta"><div class="nm">' + _esc(label) + '</div>' +
        '<div class="dur">' + (e.start_time||0) + 's \u2192 ' + (e.end_time||0) + 's</div></div>' +
      '</div>';
    }).join('');
    el.onclick = function(ev){
      var item = ev.target.closest && ev.target.closest('[data-id]');
      if (!item) return;
      _selectedId = { kind:'element', id: item.getAttribute('data-id') };
      _renderProps(); _drawCanvas();
    };
  }

  // ── Filters tab ───────────────────────────────────────────
  var _FILTERS = ['none','warm','cool','bw','fade','vivid','cinematic','matte','film','neon'];
  function _renderFiltersTab(body){
    var current = _vd.global_filter || 'none';
    body.innerHTML = '<div class="sv-tab-head">Global filter</div>' +
      '<div class="sv-filter-grid">' +
        _FILTERS.map(function(f){
          return '<div class="sv-filter' + (f===current?' active':'') + '" data-f="' + f + '">' + _esc(f==='bw'?'B&W':(f[0].toUpperCase()+f.slice(1))) + '</div>';
        }).join('') +
      '</div>' +
      '<div style="color:rgba(255,255,255,0.4);font-size:11px;margin-top:14px;line-height:1.5">Applied to the final exported video. Canvas preview shows a CSS approximation.</div>';
    body.querySelector('.sv-filter-grid').onclick = function(ev){
      var el = ev.target.closest('[data-f]'); if (!el) return;
      _vd.global_filter = el.getAttribute('data-f');
      _renderFiltersTab(body); _drawCanvas(); _queueSave();
    };
  }

  // ── Audio tab ─────────────────────────────────────────────
  function _renderAudioTab(body){
    var a = _vd.audio || {};
    body.innerHTML = '<div class="sv-tab-head">Audio</div>' +
      '<button class="sv-btn secondary" id="sv-upload-audio">\u{1F3B5} Upload audio file</button>' +
      '<input type="file" id="sv-file-audio" accept="audio/*" style="display:none">' +
      '<div style="margin-top:10px;font-size:12px;color:rgba(255,255,255,0.6)">' +
        (a.url ? 'Loaded: ' + _esc(a.url.split('/').pop()) : 'No audio') +
      '</div>' +
      '<div class="sv-field" style="margin-top:14px"><label>Volume</label>' +
        '<input type="range" min="0" max="1" step="0.05" value="' + (a.volume==null?0.8:a.volume) + '" id="sv-audio-vol"></div>' +
      '<div class="sv-field"><label>Fade in (s)</label>' +
        '<input type="number" min="0" step="0.1" value="' + (a.fade_in||0) + '" id="sv-audio-fin"></div>' +
      '<div class="sv-field"><label>Fade out (s)</label>' +
        '<input type="number" min="0" step="0.1" value="' + (a.fade_out||0) + '" id="sv-audio-fout"></div>';
    document.getElementById('sv-upload-audio').onclick = function(){ document.getElementById('sv-file-audio').click(); };
    document.getElementById('sv-file-audio').onchange = function(e){
      var f = e.target.files[0]; if (!f) return;
      if (f.size > 50 * 1024 * 1024) { _toast('Max 50 MB', 'error'); return; }
      var fd = new FormData(); fd.append('file', f);
      _toast('Uploading audio\u2026', 'info');
      fetch(_apiBase() + '/studio/video/upload-audio', {
        method:'POST', headers:{'Authorization':'Bearer '+_tok()}, body:fd
      }).then(function(r){ return r.json(); }).then(function(d){
        if (!d.success) throw new Error(d.error||'audio_upload_failed');
        _vd.audio = _vd.audio || {};
        _vd.audio.url = d.audio_url;
        _vd.audio.duration = d.duration_seconds;
        _toast('Audio ready', 'success');
        _renderAudioTab(body);
        _renderTimeline();
        _queueSave();
      }).catch(function(err){ _toast('Upload failed: ' + err.message, 'error'); });
      e.target.value = '';
    };
    document.getElementById('sv-audio-vol').oninput  = function(e){ _vd.audio = _vd.audio||{}; _vd.audio.volume  = parseFloat(e.target.value); _queueSave(); };
    document.getElementById('sv-audio-fin').oninput  = function(e){ _vd.audio = _vd.audio||{}; _vd.audio.fade_in = parseFloat(e.target.value); _queueSave(); };
    document.getElementById('sv-audio-fout').oninput = function(e){ _vd.audio = _vd.audio||{}; _vd.audio.fade_out= parseFloat(e.target.value); _queueSave(); };
  }

  // ── AI tab ────────────────────────────────────────────────
  function _renderAITab(body){
    body.innerHTML =
      '<div class="sv-tab-head">Generate with AI (MiniMax)</div>' +
      '<div class="sv-field"><label>Prompt</label>' +
        '<textarea id="sv-ai-prompt" rows="4" placeholder="A cinematic close-up of a coffee being poured, warm light">A cinematic close-up of a coffee being poured, warm golden light</textarea></div>' +
      '<div class="sv-field"><label>Duration</label>' +
        '<select id="sv-ai-dur"><option value="5">5 seconds</option><option value="6" selected>6 seconds</option><option value="10">10 seconds</option></select></div>' +
      '<button class="sv-btn" id="sv-ai-gen">\u2726 Generate video</button>' +
      '<div id="sv-ai-status" style="margin-top:14px;font-size:12px;color:rgba(255,255,255,0.6)"></div>';
    document.getElementById('sv-ai-gen').onclick = function(){
      var prompt = document.getElementById('sv-ai-prompt').value.trim();
      var dur = parseInt(document.getElementById('sv-ai-dur').value, 10) || 6;
      if (!prompt){ _toast('Prompt required', 'error'); return; }
      var st = document.getElementById('sv-ai-status');
      st.textContent = '\u2726 Generating\u2026 (45\u2013120s typical)';
      _fetchJson('/studio/video/generate-minimax', {
        method:'POST', body: JSON.stringify({ prompt: prompt, duration_seconds: dur })
      }).then(function(d){
        if (!d.success) throw new Error(d.error + (d.detail ? ': ' + d.detail : ''));
        _myClips.push({ id:'ai_'+Date.now(), name:'AI: ' + prompt.substring(0,30), url:d.clip_url, duration:d.duration||dur, type:'video', width:d.width, height:d.height });
        st.textContent = '\u2713 Generated \u2014 added to My Clips';
        _switchTab('clips');
        _toast('AI video ready', 'success');
      }).catch(function(err){
        st.textContent = '\u2717 ' + err.message;
        _toast('AI gen failed: ' + err.message, 'error');
      });
    };
  }

  // ── Properties panel ─────────────────────────────────────────
  function _renderProps(){
    var el = document.getElementById('sv-props'); if (!el) return;
    if (!_selectedId){
      el.innerHTML =
        '<div class="sv-tab-head">Video settings</div>' +
        '<div class="sv-field"><label>Format</label><input type="text" value="' + _esc(_vd.format) + '" disabled></div>' +
        '<div class="sv-field"><label>Dimensions</label><input type="text" value="' + _vd.canvas_width + ' \u00d7 ' + _vd.canvas_height + '" disabled></div>' +
        '<div class="sv-field"><label>Duration (s)</label><input type="number" min="1" max="60" value="' + (_vd.duration||15) + '" id="sv-prop-dur"></div>' +
        '<div class="sv-field"><label>FPS</label><input type="number" min="15" max="60" value="' + (_vd.fps||30) + '" id="sv-prop-fps"></div>' +
        '<div class="sv-field"><label>Background</label><input type="color" value="' + _esc(_vd.background_color||'#000000') + '" id="sv-prop-bg"></div>';
      document.getElementById('sv-prop-dur').oninput = function(e){ _vd.duration = Math.min(60, Math.max(1, parseInt(e.target.value,10)||15)); _renderTimeline(); _queueSave(); };
      document.getElementById('sv-prop-fps').oninput = function(e){ _vd.fps = parseInt(e.target.value,10)||30; _queueSave(); };
      document.getElementById('sv-prop-bg').oninput  = function(e){ _vd.background_color = e.target.value; _drawCanvas(); _queueSave(); };
      return;
    }
    if (_selectedId.kind === 'clip')    return _renderClipProps(el);
    if (_selectedId.kind === 'text')    return _renderTextProps(el);
    if (_selectedId.kind === 'element') return _renderElementProps(el);
  }

  function _renderElementProps(el){
    var e = (_vd.elements||[]).find(function(x){ return x.id===_selectedId.id; });
    if (!e){ el.innerHTML = '<div>Element not found</div>'; return; }
    var c = e.content || (e.content = {});
    var pos = e.position || (e.position = { x:80, y:80 });
    var html = '<div class="sv-tab-head">Element · ' + _esc(e.type.replace('_',' ')) + '</div>' +
      '<div class="sv-field"><label>Start (s) → End (s)</label>' +
        '<div style="display:flex;gap:6px"><input type="number" step="0.1" min="0" value="' + e.start_time + '" id="sv-el-start">' +
        '<input type="number" step="0.1" min="0" value="' + e.end_time + '" id="sv-el-end"></div></div>' +
      '<div class="sv-field"><label>Position (x, y)</label>' +
        '<div style="display:flex;gap:6px"><input type="number" value="' + (+pos.x||0) + '" id="sv-el-x">' +
        '<input type="number" value="' + (+pos.y||0) + '" id="sv-el-y"></div></div>';
    if (e.type === 'logo') {
      var sz = e.size || (e.size = { width:200, height:80 });
      html += '<div class="sv-field"><label>Size (w × h)</label>' +
        '<div style="display:flex;gap:6px"><input type="number" value="' + (+sz.width||200) + '" id="sv-el-w">' +
        '<input type="number" value="' + (+sz.height||80) + '" id="sv-el-h"></div></div>' +
        '<div class="sv-field"><label>Opacity</label><input type="range" min="0" max="1" step="0.05" value="' + (e.opacity==null?1:e.opacity) + '" id="sv-el-op"></div>';
    }
    if (e.type === 'lower_third') {
      html += '<div class="sv-field"><label>Name</label><input type="text" value="' + _esc(c.name||'') + '" id="sv-el-name"></div>' +
        '<div class="sv-field"><label>Title</label><input type="text" value="' + _esc(c.title||'') + '" id="sv-el-title"></div>' +
        '<div class="sv-field"><label>Accent color</label><input type="color" value="' + _esc(c.color||'#C9A56A') + '" id="sv-el-color"></div>';
    }
    if (e.type === 'badge') {
      html += '<div class="sv-field"><label>Text</label><input type="text" value="' + _esc(c.text||'NEW') + '" id="sv-el-text"></div>' +
        '<div class="sv-field"><label>Background</label><input type="color" value="' + _esc(c.bg_color||'#6C5CE7') + '" id="sv-el-bg"></div>' +
        '<div class="sv-field"><label>Text color</label><input type="color" value="' + _esc(c.text_color||'#FFFFFF') + '" id="sv-el-fg"></div>';
    }
    if (e.type === 'countdown') {
      html += '<div class="sv-field"><label>End text</label><input type="text" value="' + _esc(c.end_text||'GO!') + '" id="sv-el-end-text"></div>' +
        '<div class="sv-field"><label>Color</label><input type="color" value="' + _esc(c.color||'#FFFFFF') + '" id="sv-el-cd-color"></div>';
    }
    html += '<button class="sv-btn secondary" onclick="_svDeleteSelected()" style="background:#4c1d24;border-color:#7c2d2d;color:#FFD1D1">Delete element</button>';
    el.innerHTML = html;
    var $ = function(id){ return document.getElementById(id); };
    $('sv-el-start').oninput = function(ev){ e.start_time = Math.max(0, parseFloat(ev.target.value)); _renderTimeline(); _queueSave(); };
    $('sv-el-end').oninput   = function(ev){ e.end_time   = Math.max(e.start_time+0.1, parseFloat(ev.target.value)); _renderTimeline(); _queueSave(); };
    $('sv-el-x').oninput     = function(ev){ e.position.x = parseFloat(ev.target.value); _drawCanvas(); _queueSave(); };
    $('sv-el-y').oninput     = function(ev){ e.position.y = parseFloat(ev.target.value); _drawCanvas(); _queueSave(); };
    if ($('sv-el-w'))     $('sv-el-w').oninput     = function(ev){ e.size.width  = parseFloat(ev.target.value); _drawCanvas(); _queueSave(); };
    if ($('sv-el-h'))     $('sv-el-h').oninput     = function(ev){ e.size.height = parseFloat(ev.target.value); _drawCanvas(); _queueSave(); };
    if ($('sv-el-op'))    $('sv-el-op').oninput    = function(ev){ e.opacity     = parseFloat(ev.target.value); _drawCanvas(); _queueSave(); };
    if ($('sv-el-name'))  $('sv-el-name').oninput  = function(ev){ c.name = ev.target.value; _drawCanvas(); _queueSave(); };
    if ($('sv-el-title')) $('sv-el-title').oninput = function(ev){ c.title = ev.target.value; _drawCanvas(); _queueSave(); };
    if ($('sv-el-color')) $('sv-el-color').oninput = function(ev){ c.color = ev.target.value; _drawCanvas(); _queueSave(); };
    if ($('sv-el-text'))  $('sv-el-text').oninput  = function(ev){ c.text = ev.target.value; _drawCanvas(); _queueSave(); _renderElementList(); };
    if ($('sv-el-bg'))    $('sv-el-bg').oninput    = function(ev){ c.bg_color = ev.target.value; _drawCanvas(); _queueSave(); };
    if ($('sv-el-fg'))    $('sv-el-fg').oninput    = function(ev){ c.text_color = ev.target.value; _drawCanvas(); _queueSave(); };
    if ($('sv-el-end-text')) $('sv-el-end-text').oninput = function(ev){ c.end_text = ev.target.value; _drawCanvas(); _queueSave(); };
    if ($('sv-el-cd-color')) $('sv-el-cd-color').oninput = function(ev){ c.color = ev.target.value; _drawCanvas(); _queueSave(); };
  }
  function _renderClipProps(el){
    var c = (_vd.clips||[]).find(function(x){ return x.id===_selectedId.id; });
    if (!c){ el.innerHTML = '<div>Clip not found</div>'; return; }
    var trans = c.transition_in || { type:'fade', duration:0.5 };
    el.innerHTML =
      '<div class="sv-tab-head">Clip</div>' +
      '<div class="sv-field"><label>Type</label><input type="text" value="' + _esc(c.type) + '" disabled></div>' +
      '<div class="sv-field"><label>Start time (s)</label><input type="number" min="0" step="0.1" value="' + c.start_time + '" id="sv-clip-start"></div>' +
      '<div class="sv-field"><label>Duration (s)</label><input type="number" min="0.5" step="0.1" value="' + (c.end_time-c.start_time).toFixed(1) + '" id="sv-clip-dur"></div>' +
      '<div class="sv-field"><label>Transition in</label>' +
        '<select id="sv-clip-trans">' +
          ['fade','slide_left','slide_right','slide_up','slide_down','zoom_in','dissolve','wipe'].map(function(t){
            return '<option value="' + t + '"' + (t===trans.type?' selected':'') + '>' + t + '</option>';
          }).join('') +
        '</select></div>' +
      '<div class="sv-field"><label>Transition duration (s)</label><input type="number" min="0.1" step="0.1" value="' + trans.duration + '" id="sv-clip-trans-dur"></div>' +
      '<button class="sv-btn secondary" onclick="_svDeleteSelected()" style="background:#4c1d24;border-color:#7c2d2d;color:#FFD1D1">Delete clip</button>';
    document.getElementById('sv-clip-start').oninput = function(e){ c.start_time = Math.max(0, parseFloat(e.target.value)); c.end_time = c.start_time + (c.duration||3); _renderTimeline(); _drawCanvas(); _queueSave(); };
    document.getElementById('sv-clip-dur').oninput = function(e){ var d = Math.max(0.5, parseFloat(e.target.value)); c.duration = d; c.end_time = c.start_time + d; _renderTimeline(); _drawCanvas(); _queueSave(); };
    document.getElementById('sv-clip-trans').oninput = function(e){ c.transition_in = c.transition_in || {}; c.transition_in.type = e.target.value; _queueSave(); };
    document.getElementById('sv-clip-trans-dur').oninput = function(e){ c.transition_in = c.transition_in || {}; c.transition_in.duration = Math.max(0.1, parseFloat(e.target.value)); _queueSave(); };
  }
  function _renderTextProps(el){
    var t = (_vd.text_overlays||[]).find(function(x){ return x.id===_selectedId.id; });
    if (!t){ el.innerHTML = '<div>Text not found</div>'; return; }
    var anim = t.animation_in || { type:'fade', duration:0.4 };
    var _et = t.element_type || 'subtext';
    var _ET_LIST = ['brand_name','brand_pill','eyebrow','headline','headline_accent','subtext','feature_pill','cta','cta_ghost','stat_value','stat_label','price_value','price_period','quote','badge_float','badge_live','listing_line','url','handle'];
    el.innerHTML =
      '<div class="sv-tab-head">Text</div>' +
      '<div class="sv-field"><label>Type</label><select id="sv-txt-type">' + _ET_LIST.map(function(v){ return '<option value="'+v+'"'+(v===_et?' selected':'')+'>'+v+'</option>'; }).join('') + '</select></div>' +
      '<div class="sv-field"><label>Content</label><textarea id="sv-txt-content" rows="3">' + _esc(t.content) + '</textarea></div>' +
      '<div class="sv-field"><label>Start (s) → End (s)</label>' +
        '<div style="display:flex;gap:6px"><input type="number" step="0.1" min="0" value="' + t.start_time + '" id="sv-txt-start">' +
        '<input type="number" step="0.1" min="0" value="' + t.end_time + '" id="sv-txt-end"></div></div>' +
      '<div class="sv-field"><label>Position (x, y)</label>' +
        '<div style="display:flex;gap:6px"><input type="number" value="' + (t.position?t.position.x:540) + '" id="sv-txt-x">' +
        '<input type="number" value="' + (t.position?t.position.y:960) + '" id="sv-txt-y"></div></div>' +
      '<div class="sv-field"><label>Size</label><input type="number" min="10" max="400" value="' + t.font_size + '" id="sv-txt-size"></div>' +
      '<div class="sv-field"><label>Color</label><input type="color" value="' + _esc(t.color) + '" id="sv-txt-color"></div>' +
      '<div class="sv-field"><label>Animation in</label>' +
        '<select id="sv-txt-anim">' +
          ['fade','slide_up','slide_down','fade_rise','scale_pop','scale_in','slide_left','typewriter','glitch'].map(function(a){
            return '<option value="' + a + '"' + (a===anim.type?' selected':'') + '>' + a + '</option>';
          }).join('') +
        '</select></div>' +
      '<div class="sv-field"><label>Animation loop</label>' +
        '<select id="sv-txt-loop">' +
          [['','none'],['pulse','pulse']].map(function(pair){
            var cur = (t.animation_loop && t.animation_loop.type) || '';
            return '<option value="' + pair[0] + '"' + (pair[0]===cur?' selected':'') + '>' + pair[1] + '</option>';
          }).join('') +
        '</select></div>' +
      '<button class="sv-btn secondary" onclick="_svDeleteSelected()" style="background:#4c1d24;border-color:#7c2d2d;color:#FFD1D1">Delete text</button>';
    document.getElementById('sv-txt-type').oninput = function(e){ t.element_type = e.target.value; _renderTextList(); _queueSave(); };
    document.getElementById('sv-txt-content').oninput = function(e){ t.content = e.target.value; _drawCanvas(); _queueSave(); _renderTextList(); };
    document.getElementById('sv-txt-start').oninput = function(e){ t.start_time = Math.max(0, parseFloat(e.target.value)); _renderTimeline(); _queueSave(); };
    document.getElementById('sv-txt-end').oninput = function(e){ t.end_time = Math.max(t.start_time+0.1, parseFloat(e.target.value)); _renderTimeline(); _queueSave(); };
    document.getElementById('sv-txt-x').oninput = function(e){ t.position = t.position||{}; t.position.x = parseFloat(e.target.value); _drawCanvas(); _queueSave(); };
    document.getElementById('sv-txt-y').oninput = function(e){ t.position = t.position||{}; t.position.y = parseFloat(e.target.value); _drawCanvas(); _queueSave(); };
    document.getElementById('sv-txt-size').oninput = function(e){ t.font_size = parseInt(e.target.value,10)||48; _drawCanvas(); _queueSave(); };
    document.getElementById('sv-txt-color').oninput = function(e){ t.color = e.target.value; _drawCanvas(); _queueSave(); };
    document.getElementById('sv-txt-anim').oninput = function(e){ t.animation_in = t.animation_in||{}; t.animation_in.type = e.target.value; _drawCanvas(); _queueSave(); };
    document.getElementById('sv-txt-loop').oninput = function(e){
      var v = e.target.value;
      if (v) t.animation_loop = { type: v, period: 1.2 };
      else delete t.animation_loop;
      _drawCanvas(); _queueSave();
    };
  }
  window._svDeleteSelected = function(){
    if (!_selectedId) return;
    if (_selectedId.kind==='clip')    _vd.clips        = (_vd.clips||[]).filter(function(x){ return x.id!==_selectedId.id; });
    if (_selectedId.kind==='text')    _vd.text_overlays= (_vd.text_overlays||[]).filter(function(x){ return x.id!==_selectedId.id; });
    if (_selectedId.kind==='element') _vd.elements     = (_vd.elements||[]).filter(function(x){ return x.id!==_selectedId.id; });
    _selectedId = null; _renderTimeline(); _drawCanvas(); _renderProps();
    _renderTextList(); _renderElementList();
    _queueSave();
  };

  // ── Canvas (preview renders the frame at _playhead) ──────────
  function _drawCanvas(){
    var canvas = document.getElementById('sv-canvas'); if (!canvas) return;
    var W = _vd.canvas_width, H = _vd.canvas_height;
    canvas.width = W; canvas.height = H;
    var wrap = document.getElementById('sv-canvas-wrap');
    var frame = document.getElementById('sv-canvas-frame');
    if (wrap && frame){
      var availW = wrap.clientWidth - 60, availH = wrap.clientHeight - 80;
      var scale = Math.min(availW/W, availH/H, 1);
      frame.style.width = (W*scale)+'px'; frame.style.height=(H*scale)+'px';
      canvas.style.width = (W*scale)+'px'; canvas.style.height=(H*scale)+'px';
    }
    var ctx = canvas.getContext('2d');
    // Dark-gradient fallback for pure-black / missing backgrounds.
    var bg = (_vd.background_color || '').toLowerCase();
    if (!bg || bg === '#000' || bg === '#000000') {
      var g = ctx.createLinearGradient(0, 0, 0, H);
      g.addColorStop(0, '#0D0D1A'); g.addColorStop(1, '#1A1A2E');
      ctx.fillStyle = g;
    } else {
      ctx.fillStyle = _vd.background_color;
    }
    ctx.fillRect(0, 0, W, H);

    var clips = (_vd.clips || []).slice().sort(function(a,b){ return a.start_time - b.start_time; });

    // Active + previous clip (transition window uses both)
    var activeIdx = -1;
    for (var i = 0; i < clips.length; i++) {
      if (_playhead >= clips[i].start_time && _playhead <= clips[i].end_time) { activeIdx = i; break; }
    }
    var active = activeIdx >= 0 ? clips[activeIdx] : null;
    var prev   = activeIdx > 0 ? clips[activeIdx - 1] : null;

    // Active playback: drive <video> elements for video clips
    _updatePlaybackState(active);

    if (active) {
      var trans = active.transition_in || { type:'fade', duration:0.5 };
      var tDur  = Math.max(0.05, Math.min(1.5, +trans.duration || 0.5));
      var inWindow = prev && _playhead >= active.start_time && _playhead < active.start_time + tDur;
      if (inWindow) {
        var progress = Math.max(0, Math.min(1, (_playhead - active.start_time) / tDur));
        _drawTransition(ctx, prev, active, progress, trans.type || 'fade', W, H);
      } else {
        _drawClipFrame(ctx, active, W, H);
      }
    }

    // Element overlays (logos, lower thirds, badges, countdowns)
    (_vd.elements || []).forEach(function(el){
      if (_playhead < (el.start_time||0) || _playhead > (el.end_time||_vd.duration||0)) return;
      _drawElement(ctx, el, W, H);
    });

    // Text overlays
    (_vd.text_overlays || []).forEach(function(t){
      if (_playhead < t.start_time || _playhead > t.end_time) return;
      _drawText(ctx, t, W, H);
    });

    canvas.style.filter = _cssFilterFor(_vd.global_filter || 'none');

    // ── v4.4.1 selection overlay + event wiring ────────────────
    _svWireCanvasInteraction(canvas);
    if (_selectedId) _svDrawSelection(ctx, W, H);
  }

  // ══════════════════════════════════════════════════════════════
  // v4.4.1 — Canvas drag + resize interaction
  // ══════════════════════════════════════════════════════════════

  function _svMouseToCanvas(e){
    var canvas = document.getElementById('sv-canvas');
    if (!canvas || !_vd) return {x:0, y:0};
    var rect = canvas.getBoundingClientRect();
    if (!rect.width || !rect.height) return {x:0, y:0};
    var sx = (_vd.canvas_width  || 1080) / rect.width;
    var sy = (_vd.canvas_height || 1920) / rect.height;
    return { x: (e.clientX - rect.left) * sx, y: (e.clientY - rect.top) * sy };
  }

  // Wheel + pinch zoom on .sv-canvas-wrap. Scales the .sv-canvas-frame
  // via CSS transform. _svMouseToCanvas uses getBoundingClientRect which
  // already reflects the zoomed size, so hit-testing stays accurate.
  function _svWireCanvasZoom(){
    if (_svZoomWired) return;
    var wrap = document.getElementById('sv-canvas-wrap');
    if (!wrap) return;
    _svZoomWired = true;

    function applyZoom(){
      var frame = document.getElementById('sv-canvas-frame');
      if (!frame) return;
      frame.style.transformOrigin = 'center center';
      frame.style.transform = 'scale(' + _svZoom + ')';
    }

    // Ctrl/Cmd + wheel = zoom (native browser pinch gesture on trackpads
    // dispatches wheel with ctrlKey=true). Plain wheel scrolls the wrap
    // normally (so long timelines / tall canvases still pan).
    wrap.addEventListener('wheel', function(e){
      if (!e.ctrlKey && !e.metaKey) return;
      e.preventDefault();
      var delta = -e.deltaY;
      var factor = Math.pow(1.0015, delta);
      _svZoom = Math.max(0.2, Math.min(4, _svZoom * factor));
      applyZoom();
    }, { passive: false });

    // Touch: 2-finger pinch
    var pinchStart = null;
    wrap.addEventListener('touchstart', function(e){
      if (e.touches.length === 2){
        var a = e.touches[0], b = e.touches[1];
        var d = Math.hypot(b.clientX - a.clientX, b.clientY - a.clientY);
        pinchStart = { dist: d, zoom: _svZoom };
        e.preventDefault();
      }
    }, { passive: false });
    wrap.addEventListener('touchmove', function(e){
      if (e.touches.length !== 2 || !pinchStart) return;
      var a = e.touches[0], b = e.touches[1];
      var d = Math.hypot(b.clientX - a.clientX, b.clientY - a.clientY);
      _svZoom = Math.max(0.2, Math.min(4, pinchStart.zoom * (d / pinchStart.dist)));
      applyZoom();
      e.preventDefault();
    }, { passive: false });
    wrap.addEventListener('touchend', function(e){
      if (e.touches.length < 2) pinchStart = null;
    });

    // Double-click on empty canvas = reset zoom to 1
    wrap.addEventListener('dblclick', function(e){
      if (e.target && e.target.id === 'sv-canvas') return; // don't steal canvas dblclicks
      _svZoom = 1; applyZoom();
    });
  }

  function _svGetElement(kind, id){
    if (kind === 'text')    return (_vd.text_overlays || []).find(function(t){ return t.id === id; });
    if (kind === 'element') return (_vd.elements      || []).find(function(e){ return e.id === id; });
    if (kind === 'clip')    return (_vd.clips         || []).find(function(c){ return c.id === id; });
    return null;
  }

  // Return canvas-space bounding box {x, y, w, h} for an element.
  // Text uses anchor-based positioning — convert to top-left box.
  function _svGetElementBox(kind, el){
    if (!el) return null;
    if (kind === 'text'){
      var fs = el.font_size || 72;
      // Rough width estimate: measureText would be ideal but we don't have
      // a ctx in this scope; use length-based approximation.
      var txt = (el.content || '');
      var estW = Math.max(80, txt.length * fs * 0.55);
      var h    = Math.max(30, fs * 1.4);
      var anchor = el.anchor || 'center';
      var px = (el.position && el.position.x != null) ? el.position.x : (_vd.canvas_width/2);
      var py = (el.position && el.position.y != null) ? el.position.y : (_vd.canvas_height/2);
      var x  = anchor === 'left'  ? px
             : anchor === 'right' ? (px - estW)
             : (px - estW/2);
      var y  = py - h/2;
      return { x: x, y: y, w: estW, h: h };
    }
    if (kind === 'element'){
      var w = (el.size && el.size.width)  || 200;
      var h = (el.size && el.size.height) || 80;
      var x = (el.position && el.position.x) || 0;
      var y = (el.position && el.position.y) || 0;
      return { x: x, y: y, w: w, h: h };
    }
    return null;
  }

  function _svCheckResizeHandle(cx, cy, box){
    var x = box.x, y = box.y, w = box.w, h = box.h;
    var handles = {
      tl: {hx: x,       hy: y      },
      tr: {hx: x + w,   hy: y      },
      bl: {hx: x,       hy: y + h  },
      br: {hx: x + w,   hy: y + h  },
      t:  {hx: x + w/2, hy: y      },
      b:  {hx: x + w/2, hy: y + h  },
      l:  {hx: x,       hy: y + h/2},
      r:  {hx: x + w,   hy: y + h/2},
    };
    var HIT = 18;
    for (var name in handles){
      var p = handles[name];
      if (Math.abs(cx - p.hx) < HIT && Math.abs(cy - p.hy) < HIT) return name;
    }
    return null;
  }

  function _svHitTest(cx, cy){
    // Test against ALL text overlays (not just those active at _playhead).
    // Otherwise nothing's clickable before their start_time — blank canvas
    // at t=0 becomes useless for editing.
    var texts = (_vd.text_overlays || []);
    for (var i = texts.length - 1; i >= 0; i--){
      var t = texts[i];
      var box = _svGetElementBox('text', t);
      if (!box) continue;
      var h = _svCheckResizeHandle(cx, cy, box);
      if (h) return { kind:'text', id: t.id, handle: h };
      if (cx >= box.x && cx <= box.x + box.w && cy >= box.y && cy <= box.y + box.h){
        return { kind:'text', id: t.id, handle: null };
      }
    }
    // All elements — same reason (hit-test independent of playhead).
    var els = (_vd.elements || []);
    for (var j = els.length - 1; j >= 0; j--){
      var el = els[j];
      var box2 = _svGetElementBox('element', el);
      if (!box2) continue;
      var h2 = _svCheckResizeHandle(cx, cy, box2);
      if (h2) return { kind:'element', id: el.id, handle: h2 };
      if (cx >= box2.x && cx <= box2.x + box2.w && cy >= box2.y && cy <= box2.y + box2.h){
        return { kind:'element', id: el.id, handle: null };
      }
    }
    return null;
  }

  function _svDrawSelection(ctx, W, H){
    if (!_selectedId) return;
    if (_selectedId.kind === 'clip') return; // no canvas handle for clips
    var el = _svGetElement(_selectedId.kind, _selectedId.id);
    var box = _svGetElementBox(_selectedId.kind, el);
    if (!box) return;
    ctx.save();
    ctx.strokeStyle = 'rgba(108,92,231,1)';
    ctx.lineWidth = 2;
    ctx.setLineDash([6, 3]);
    ctx.strokeRect(box.x, box.y, box.w, box.h);
    ctx.setLineDash([]);
    var handles = [
      [box.x,            box.y        ],
      [box.x + box.w/2,  box.y        ],
      [box.x + box.w,    box.y        ],
      [box.x + box.w,    box.y + box.h/2],
      [box.x + box.w,    box.y + box.h],
      [box.x + box.w/2,  box.y + box.h],
      [box.x,            box.y + box.h],
      [box.x,            box.y + box.h/2],
    ];
    ctx.lineWidth = 1.5;
    handles.forEach(function(pt){
      ctx.fillStyle   = '#fff';
      ctx.strokeStyle = 'rgba(108,92,231,1)';
      ctx.fillRect(pt[0]-5, pt[1]-5, 10, 10);
      ctx.strokeRect(pt[0]-5, pt[1]-5, 10, 10);
    });
    ctx.restore();
  }

  function _svWireCanvasInteraction(canvas){
    if (!canvas || _svInteractWired) return;
    _svInteractWired = true;

    // Mouse down → hit-test + select + start drag/resize
    canvas.addEventListener('mousedown', function(e){
      if (_playing) return;
      var cp = _svMouseToCanvas(e);
      var hit = _svHitTest(cp.x, cp.y);
      if (!hit){
        if (_selectedId) { _selectedId = null; _drawCanvas(); if (typeof _renderProps === 'function') _renderProps(); }
        return;
      }
      _selectedId = { kind: hit.kind, id: hit.id };
      _svDragStart = cp;
      var el = _svGetElement(hit.kind, hit.id);
      _svElementStart = {
        x: (el.position && el.position.x) || 0,
        y: (el.position && el.position.y) || 0,
        width:  el.size ? el.size.width  : null,
        height: el.size ? el.size.height : null,
        font_size: el.font_size || null,
      };
      if (hit.handle){ _svResizing = true; _svResizeHandle = hit.handle; }
      else           { _svDragging = true; }
      if (typeof _renderTimeline === 'function') _renderTimeline();
      if (typeof _renderProps    === 'function') _renderProps();
      _drawCanvas();
      e.preventDefault();
    });

    // Mouse move → drag or resize
    document.addEventListener('mousemove', function(e){
      // Cursor feedback when idle
      if (!_svDragging && !_svResizing){
        if (!_vd || _playing) { canvas.style.cursor = 'default'; return; }
        var cp0 = _svMouseToCanvas(e);
        // Only set cursor if mouse is actually over the canvas.
        var r = canvas.getBoundingClientRect();
        var over = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
        if (!over){ canvas.style.cursor = 'default'; return; }
        var hit0 = _svHitTest(cp0.x, cp0.y);
        if (!hit0) canvas.style.cursor = 'default';
        else if (hit0.handle){
          var cursors = { tl:'nw-resize', tr:'ne-resize', bl:'sw-resize', br:'se-resize',
                          t:'n-resize',  b:'s-resize',   l:'w-resize',   r:'e-resize' };
          canvas.style.cursor = cursors[hit0.handle] || 'pointer';
        } else canvas.style.cursor = 'move';
        return;
      }
      if (!_selectedId) return;
      var cp = _svMouseToCanvas(e);
      var dx = cp.x - _svDragStart.x;
      var dy = cp.y - _svDragStart.y;
      var el = _svGetElement(_selectedId.kind, _selectedId.id);
      if (!el) return;

      if (_svDragging){
        el.position = el.position || { x:0, y:0 };
        el.position.x = _svElementStart.x + dx;
        el.position.y = _svElementStart.y + dy;
        var box = _svGetElementBox(_selectedId.kind, el);
        if (box){
          el.position.x -= Math.max(0, (box.x + box.w) - _vd.canvas_width);
          el.position.y -= Math.max(0, (box.y + box.h) - _vd.canvas_height);
          el.position.x += Math.max(0, -box.x);
          el.position.y += Math.max(0, -box.y);
        }
      }

      if (_svResizing){
        var nx = _svElementStart.x, ny = _svElementStart.y;
        var nw = _svElementStart.width || 200, nh = _svElementStart.height || 80;
        switch (_svResizeHandle){
          case 'br': nw += dx;          nh += dy;          break;
          case 'bl': nx += dx; nw -= dx; nh += dy;          break;
          case 'tr':          nw += dx; ny += dy; nh -= dy; break;
          case 'tl': nx += dx; ny += dy; nw -= dx; nh -= dy; break;
          case 'r':  nw += dx;                               break;
          case 'l':  nx += dx; nw -= dx;                     break;
          case 'b':                     nh += dy;            break;
          case 't':           ny += dy; nh -= dy;            break;
        }
        nw = Math.max(80, nw); nh = Math.max(30, nh);
        el.position = el.position || { x:0, y:0 };
        el.position.x = nx;
        el.position.y = ny;
        if (el.size){ el.size.width = nw; el.size.height = nh; }
        if (_selectedId.kind === 'text' && nh > 30){
          el.font_size = Math.round(nh / 1.4);
        }
      }

      _drawCanvas();
    });

    // Mouse up → commit + auto-save
    document.addEventListener('mouseup', function(){
      if (_svDragging || _svResizing){
        _svDragging = false;
        _svResizing = false;
        _svResizeHandle = null;
        if (typeof _renderProps    === 'function') _renderProps();
        if (typeof _renderTimeline === 'function') _renderTimeline();
        if (typeof _queueSave      === 'function') _queueSave();
      }
    });

    // Touch → mouse bridge
    canvas.addEventListener('touchstart', function(e){
      if (!e.touches || !e.touches[0]) return;
      var t = e.touches[0];
      canvas.dispatchEvent(new MouseEvent('mousedown', { clientX: t.clientX, clientY: t.clientY, bubbles: true, cancelable: true }));
      e.preventDefault();
    }, { passive: false });
    document.addEventListener('touchmove', function(e){
      if (!_svDragging && !_svResizing) return;
      if (!e.touches || !e.touches[0]) return;
      var t = e.touches[0];
      document.dispatchEvent(new MouseEvent('mousemove', { clientX: t.clientX, clientY: t.clientY, bubbles: true }));
      e.preventDefault();
    }, { passive: false });
    document.addEventListener('touchend', function(){
      document.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
    });

    // Keyboard — nudge / delete / escape
    document.addEventListener('keydown', function(e){
      if (!_selectedId) return;
      // Ignore if user is typing into a field
      var tgt = e.target;
      if (tgt && (tgt.tagName === 'INPUT' || tgt.tagName === 'TEXTAREA' || tgt.isContentEditable)) return;
      var el = _svGetElement(_selectedId.kind, _selectedId.id);
      if (!el) return;
      var step = e.shiftKey ? 10 : 1;
      var changed = false;
      if (e.key === 'Escape'){ _selectedId = null; _drawCanvas(); if (typeof _renderProps==='function') _renderProps(); changed = true; }
      else if (e.key === 'Delete' || e.key === 'Backspace'){
        if (_selectedId.kind === 'text'){
          _vd.text_overlays = (_vd.text_overlays || []).filter(function(t){ return t.id !== _selectedId.id; });
        } else if (_selectedId.kind === 'element'){
          _vd.elements = (_vd.elements || []).filter(function(x){ return x.id !== _selectedId.id; });
        }
        _selectedId = null;
        changed = true;
      }
      else if (e.key === 'ArrowLeft'){  el.position = el.position || {x:0,y:0}; el.position.x -= step; changed = true; }
      else if (e.key === 'ArrowRight'){ el.position = el.position || {x:0,y:0}; el.position.x += step; changed = true; }
      else if (e.key === 'ArrowUp'){    el.position = el.position || {x:0,y:0}; el.position.y -= step; changed = true; }
      else if (e.key === 'ArrowDown'){  el.position = el.position || {x:0,y:0}; el.position.y += step; changed = true; }
      if (changed){
        e.preventDefault();
        _drawCanvas();
        if (typeof _renderProps === 'function') _renderProps();
        if (typeof _renderTimeline === 'function') _renderTimeline();
        if (typeof _queueSave === 'function') _queueSave();
      }
    });
  }

  // ── Video element pool ───────────────────────────────────────
  var _imgCache = {}, _vidCache = {};
  var _activeVidSrc = null;   // source_url of the video currently playing
  function _getVideo(src){
    if (!src) return null;
    var v = _vidCache[src];
    if (!v) {
      v = document.createElement('video');
      v.crossOrigin = 'anonymous';
      v.muted = true;
      v.playsInline = true;
      v.preload = 'auto';
      v.src = src;
      v.addEventListener('loadeddata', function(){ _drawCanvas(); });
      _vidCache[src] = v;
    }
    return v;
  }
  function _updatePlaybackState(active){
    // Playback: when _playing and the active clip is a video, ensure its
    // <video> element is playing (muted). When clip changes or playback
    // stops, pause everything.
    if (!_playing || !active || active.type !== 'video' || !active.source_url) {
      if (_activeVidSrc) {
        var prev = _vidCache[_activeVidSrc];
        if (prev && !prev.paused) { try { prev.pause(); } catch(_){} }
        _activeVidSrc = null;
      }
      return;
    }
    var v = _getVideo(active.source_url);
    if (!v) return;
    if (_activeVidSrc && _activeVidSrc !== active.source_url) {
      var old = _vidCache[_activeVidSrc];
      if (old && !old.paused) { try { old.pause(); } catch(_){} }
    }
    if (v.paused) {
      var localTime = Math.max(0, _playhead - active.start_time + (active.trim_start||0));
      try { v.currentTime = localTime; } catch(_){}
      var p = v.play();
      if (p && p.catch) p.catch(function(){});
    }
    _activeVidSrc = active.source_url;
  }

  // ── Draw a single clip frame ────────────────────────────────
  function _drawClipFrame(ctx, clip, W, H){
    if (clip.type === 'placeholder' || !clip.source_url) {
      _drawPlaceholder(ctx, clip, W, H); return;
    }
    if (clip.type === 'image') { _drawImageClip(ctx, clip, W, H); return; }
    if (clip.type === 'video') { _drawVideoClip(ctx, clip, W, H); return; }
  }

  function _drawPlaceholder(ctx, clip, W, H){
    ctx.save();
    ctx.fillStyle = 'rgba(108,92,231,0.08)';
    ctx.fillRect(0, 0, W, H);
    ctx.strokeStyle = 'rgba(255,255,255,0.35)';
    ctx.lineWidth = 4; ctx.setLineDash([18, 12]);
    var pad = 40; ctx.strokeRect(pad, pad, W - pad*2, H - pad*2);
    ctx.setLineDash([]);
    ctx.fillStyle = 'rgba(255,255,255,0.65)';
    ctx.font = '600 48px Inter, system-ui, sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(clip.label || 'Drop a clip here', W/2, H/2 - 30);
    ctx.fillStyle = 'rgba(255,255,255,0.4)';
    ctx.font = '400 28px Inter, system-ui, sans-serif';
    var dur = (clip.end_time - clip.start_time).toFixed(1);
    ctx.fillText(dur + 's slot · tap Clips tab to add', W/2, H/2 + 30);
    ctx.restore();
  }

  function _drawImageClip(ctx, clip, W, H){
    var img = _imgCache[clip.source_url];
    if (!img) {
      img = new Image(); img.crossOrigin = 'anonymous'; img.src = clip.source_url;
      img.onload = function(){ _imgCache[clip.source_url] = img; _drawCanvas(); };
      return;
    }
    // Cover-fit source rect
    var srcRatio = img.naturalWidth / img.naturalHeight;
    var dstRatio = W / H;
    var sx=0, sy=0, sw=img.naturalWidth, sh=img.naturalHeight;
    if (srcRatio > dstRatio) { sw = img.naturalHeight * dstRatio; sx = (img.naturalWidth - sw) / 2; }
    else                     { sh = img.naturalWidth / dstRatio;  sy = (img.naturalHeight - sh) / 2; }

    // Ken Burns — animate scale + translate through the clip's life
    var kb = clip.ken_burns;
    if (kb && kb.enabled) {
      var dur = Math.max(0.01, clip.end_time - clip.start_time);
      var prog = Math.max(0, Math.min(1, (_playhead - clip.start_time) / dur));
      var s  = (kb.start_scale != null ? +kb.start_scale : 1) + (((kb.end_scale != null ? +kb.end_scale : 1.1)) - (kb.start_scale != null ? +kb.start_scale : 1)) * prog;
      var tx = (+kb.start_x || 0) + ((+kb.end_x || 0) - (+kb.start_x || 0)) * prog;
      var ty = (+kb.start_y || 0) + ((+kb.end_y || 0) - (+kb.start_y || 0)) * prog;
      ctx.save();
      ctx.translate(W/2 + tx, H/2 + ty);
      ctx.scale(s, s);
      ctx.drawImage(img, sx, sy, sw, sh, -W/2, -H/2, W, H);
      ctx.restore();
      return;
    }
    ctx.drawImage(img, sx, sy, sw, sh, 0, 0, W, H);
  }

  function _drawVideoClip(ctx, clip, W, H){
    var v = _getVideo(clip.source_url);
    if (!v || v.readyState < 2) return;  // wait for 'loadeddata'
    // Scrub when not playing: seek to local time (lazy — we don't await
    // the seeked event). When playing, _updatePlaybackState keeps the
    // element playing, so we just draw whatever frame is current.
    if (!_playing) {
      var localTime = Math.max(0, _playhead - clip.start_time + (clip.trim_start||0));
      if (Math.abs(v.currentTime - localTime) > 0.15) {
        try { v.currentTime = localTime; } catch(_){}
      }
    }
    try { ctx.drawImage(v, 0, 0, W, H); } catch(_){}
  }

  // ── Transitions (canvas crossfade between prev → current) ──
  function _drawTransition(ctx, prevClip, nextClip, progress, type, W, H){
    progress = Math.max(0, Math.min(1, progress));
    if (type === 'slide_left') {
      ctx.save(); ctx.translate(-progress * W, 0); _drawClipFrame(ctx, prevClip, W, H); ctx.restore();
      ctx.save(); ctx.translate((1 - progress) * W, 0); _drawClipFrame(ctx, nextClip, W, H); ctx.restore();
      return;
    }
    if (type === 'slide_right') {
      ctx.save(); ctx.translate(progress * W, 0); _drawClipFrame(ctx, prevClip, W, H); ctx.restore();
      ctx.save(); ctx.translate(-(1 - progress) * W, 0); _drawClipFrame(ctx, nextClip, W, H); ctx.restore();
      return;
    }
    if (type === 'slide_up') {
      ctx.save(); ctx.translate(0, -progress * H); _drawClipFrame(ctx, prevClip, W, H); ctx.restore();
      ctx.save(); ctx.translate(0, (1 - progress) * H); _drawClipFrame(ctx, nextClip, W, H); ctx.restore();
      return;
    }
    if (type === 'zoom_in') {
      // Outgoing stays static, incoming scales 1.2 → 1.0 while fading in
      _drawClipFrame(ctx, prevClip, W, H);
      ctx.save();
      var s = 1.2 - progress * 0.2;
      ctx.translate(W/2, H/2); ctx.scale(s, s); ctx.translate(-W/2, -H/2);
      ctx.globalAlpha = progress;
      _drawClipFrame(ctx, nextClip, W, H);
      ctx.restore();
      return;
    }
    // Fade / dissolve / default: crossfade
    _drawClipFrame(ctx, prevClip, W, H);
    ctx.save();
    ctx.globalAlpha = progress;
    _drawClipFrame(ctx, nextClip, W, H);
    ctx.restore();
  }

  // ── Element rendering (logo / lower_third / badge / countdown) ──
  function _drawElement(ctx, el, W, H){
    if (el.type === 'logo')        return _drawElementLogo(ctx, el, W, H);
    if (el.type === 'lower_third') return _drawElementLowerThird(ctx, el, W, H);
    if (el.type === 'badge')       return _drawElementBadge(ctx, el, W, H);
    if (el.type === 'countdown')   return _drawElementCountdown(ctx, el, W, H);
  }

  function _drawElementLogo(ctx, el, W, H){
    var src = el.content && el.content.url;
    if (!src) return;
    var img = _imgCache[src];
    if (!img) {
      img = new Image(); img.crossOrigin = 'anonymous'; img.src = src;
      img.onload = function(){ _imgCache[src] = img; _drawCanvas(); };
      return;
    }
    var x = (el.position && +el.position.x) || 80;
    var y = (el.position && +el.position.y) || 80;
    var w = (el.size && +el.size.width)  || 200;
    var h = (el.size && +el.size.height) || 80;
    ctx.save();
    ctx.globalAlpha = el.opacity != null ? +el.opacity : 1;
    ctx.drawImage(img, x, y, w, h);
    ctx.restore();
  }

  function _drawElementLowerThird(ctx, el, W, H){
    var c = el.content || {};
    var name = c.name || 'Your Name';
    var title = c.title || 'Your Title';
    var color = c.color || '#C9A56A';
    var x = (el.position && +el.position.x) || 80;
    var y = (el.position && +el.position.y) || (H - 240);
    var w = (el.size && +el.size.width) || (W * 0.6);
    var h = 160;
    ctx.save();
    // Animate slide_left on entry (first 0.5s of the element's life)
    var prog = Math.max(0, Math.min(1, (_playhead - el.start_time) / 0.5));
    ctx.translate((1 - prog) * -w, 0);
    // Dark bar
    ctx.fillStyle = 'rgba(8,8,12,0.85)';
    ctx.fillRect(x, y, w, h);
    // Accent stripe (left edge)
    ctx.fillStyle = color;
    ctx.fillRect(x, y, 8, h);
    // Name
    ctx.fillStyle = '#FFFFFF';
    ctx.textBaseline = 'alphabetic';
    ctx.textAlign = 'left';
    ctx.font = '700 44px Inter, system-ui, sans-serif';
    ctx.fillText(name, x + 32, y + 72);
    // Title
    ctx.fillStyle = color;
    ctx.font = '500 22px Inter, system-ui, sans-serif';
    ctx.fillText(title, x + 32, y + 112);
    ctx.restore();
  }

  function _drawElementBadge(ctx, el, W, H){
    var c = el.content || {};
    var text = c.text || 'NEW';
    var bg = c.bg_color || '#6C5CE7';
    var fg = c.text_color || '#FFFFFF';
    var x = (el.position && +el.position.x) || 80;
    var y = (el.position && +el.position.y) || 80;
    ctx.save();
    ctx.font = '800 28px Inter, system-ui, sans-serif';
    var tw = ctx.measureText(text).width;
    var pad = 22;
    var h = 56;
    var w = tw + pad * 2;
    // rounded pill
    ctx.fillStyle = bg;
    if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(x, y, w, h, h/2); ctx.fill(); }
    else                { ctx.fillRect(x, y, w, h); }
    ctx.fillStyle = fg;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText(text, x + pad, y + h/2 + 2);
    ctx.restore();
  }

  function _drawElementCountdown(ctx, el, W, H){
    var c = el.content || {};
    var endText = c.end_text || 'GO!';
    var size = (el.size && +el.size.font_size) || 260;
    var color = c.color || '#FFFFFF';
    var startT = +el.start_time || 0;
    var elapsed = _playhead - startT;
    if (elapsed < 0 || elapsed > 4) return;
    var txt;
    if (elapsed < 1)      txt = '3';
    else if (elapsed < 2) txt = '2';
    else if (elapsed < 3) txt = '1';
    else                  txt = endText;
    // Scale pop per-number
    var beatProg = elapsed - Math.floor(elapsed);
    var s = 0.7 + Math.min(1, beatProg * 3) * 0.3;
    ctx.save();
    ctx.translate(W/2, H/2);
    ctx.scale(s, s);
    ctx.globalAlpha = Math.min(1, (1 - Math.pow(beatProg, 2)) + 0.4);
    ctx.fillStyle = color;
    ctx.font = '900 ' + size + 'px Inter, system-ui, sans-serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(txt, 0, 0);
    ctx.restore();
  }

  function _cssFilterFor(name){
    switch ((name||'').toLowerCase()){
      case 'warm': return 'sepia(0.3) saturate(1.4) hue-rotate(-10deg)';
      case 'cool': return 'hue-rotate(20deg) saturate(0.9) brightness(1.05)';
      case 'bw': case 'b&w': return 'grayscale(1) contrast(1.1)';
      case 'fade': return 'brightness(1.1) contrast(0.85) saturate(0.8)';
      case 'vivid': return 'saturate(1.8) contrast(1.15)';
      case 'cinematic': return 'contrast(1.2) brightness(0.9) saturate(0.85)';
      case 'matte': return 'contrast(0.85) brightness(1.05) saturate(0.7)';
      case 'film': return 'sepia(0.2) contrast(1.1) brightness(0.95)';
      case 'neon': return 'saturate(2.5) contrast(1.3) hue-rotate(10deg)';
      default: return 'none';
    }
  }

  function _drawText(ctx, t, W, H){
    ctx.save();
    ctx.font = (t.font_weight||'700') + ' ' + (t.font_size||72) + 'px ' + (t.font_family||'Inter');
    ctx.fillStyle = t.color || '#FFFFFF';
    ctx.textBaseline = 'middle';
    var x = (t.position && t.position.x) != null ? t.position.x : W/2;
    var y = (t.position && t.position.y) != null ? t.position.y : H/2;
    var anchor = t.anchor || 'center';
    ctx.textAlign = anchor==='left' ? 'left' : anchor==='right' ? 'right' : 'center';
    // Animation-in: fade/slide_up/scale_pop preview
    var anim = t.animation_in || { type:'fade', duration:0.4 };
    var elapsed = _playhead - t.start_time;
    var p = Math.max(0, Math.min(1, elapsed / (anim.duration||0.4)));
    var alpha = p;
    if (anim.type === 'slide_up')   y += (1-p) * 60;
    if (anim.type === 'slide_down') y -= (1-p) * 60;
    if (anim.type === 'fade_rise')  y += (1-p) * 24;
    if (anim.type === 'scale_pop')  { var s1 = 0.6 + p*0.4;  ctx.translate(x, y); ctx.scale(s1, s1); x=0; y=0; }
    if (anim.type === 'scale_in')   { var ep = Math.pow(p, 0.5); var s2 = 0.4 + ep*0.6; ctx.translate(x, y); ctx.scale(s2, s2); x=0; y=0; }

    // animation_loop: pulse (opacity oscillation during visible window)
    var loop = t.animation_loop;
    if (loop && loop.type === 'pulse') {
      var per = Math.max(0.2, loop.period || 1.2);
      var osc = 0.7 + 0.3 * (0.5 + 0.5 * Math.sin(2 * Math.PI * _playhead / per));
      alpha *= osc;
    }
    ctx.globalAlpha = alpha;

    ctx.fillText(t.content || '', x, y);
    ctx.restore();
  }

  // ── Timeline ────────────────────────────────────────────────
  var _TL = { pxPerSec: 20 };  // 20 px = 1s → 60s = 1200px

  function _renderTimeline(){
    var ruler = document.getElementById('sv-tl-ruler'); if (!ruler) return;
    var maxSec = Math.max(_vd.duration||15, 60);
    var totalW = maxSec * _TL.pxPerSec;
    var html = '';
    for (var s=0; s<=maxSec; s+=5){
      html += '<div class="tick" style="left:' + (s*_TL.pxPerSec) + 'px;width:' + (_TL.pxPerSec*5) + 'px">' + s + 's</div>';
    }
    ruler.innerHTML = html;
    ruler.style.width = totalW + 'px';
    ruler.style.cursor = 'pointer';
    // Click on ruler → jump playhead. Mousedown → start scrub.
    ruler.onmousedown = function(ev){
      if (ev.target && ev.target.id === 'sv-tl-playhead') return; // handle via playhead drag
      var rect = ruler.getBoundingClientRect();
      _playhead = Math.max(0, Math.min(_vd.duration||15, (ev.clientX - rect.left) / _TL.pxPerSec));
      _updatePlayheadUI();
      _drawCanvas();
      if (_playing) window._svPlayToggle();
      _scrubbing = true;
      document.addEventListener('mousemove', _scrubMove);
      document.addEventListener('mouseup', _scrubEnd);
      ev.preventDefault();
    };

    var tracks = document.getElementById('sv-tl-tracks');
    tracks.innerHTML =
      '<div class="sv-tl-track clip-track"     id="sv-track-clips"    style="width:' + totalW + 'px"><div class="sv-tl-label">CLIPS</div></div>' +
      '<div class="sv-tl-track text-track"     id="sv-track-text"     style="width:' + totalW + 'px"><div class="sv-tl-label">TEXT</div></div>' +
      '<div class="sv-tl-track text-track"     id="sv-track-elements" style="width:' + totalW + 'px"><div class="sv-tl-label">ELEMENTS</div></div>' +
      '<div class="sv-tl-track audio-track"    id="sv-track-audio"    style="width:' + totalW + 'px">' +
        '<div class="sv-tl-label">AUDIO</div>' +
        ((_vd.audio && _vd.audio.url) ? '<div class="sv-tl-block audio" style="left:0;right:0;width:auto;opacity:0.75"><div class="sv-tl-trim-l"></div><span style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis;padding-left:30px">\u266B ' + _esc((_vd.audio.url||'').split("/").pop()) + '</span><div class="sv-tl-trim-r"></div></div>' : '') +
      '</div>' +
      '<div class="sv-tl-playhead" id="sv-tl-playhead" style="left:' + (_playhead * _TL.pxPerSec) + 'px;height:100%"></div>';

    // ── Render each track with lane-packing so overlapping items stack
    //    vertically on sub-rows (CapCut-style multi-lane). Lane is persisted
    //    on the item so user-assigned rows survive reloads.
    var LANE_H = { clip: 52, text: 28, element: 28 };
    var LANE_GAP = 4;

    _svRenderLaneTrack('sv-track-clips',    _vd.clips         || [], 'clip',    LANE_H.clip,    LANE_GAP);
    _svRenderLaneTrack('sv-track-text',     _vd.text_overlays || [], 'text',    LANE_H.text,    LANE_GAP);
    _svRenderLaneTrack('sv-track-elements', _vd.elements      || [], 'element', LANE_H.element, LANE_GAP);

    var dur = document.getElementById('sv-tl-dur');
    if (dur) dur.textContent = (_vd.duration||0).toFixed(1) + 's / 60s';
  }

  // ── Greedy interval-packing into lanes ───────────────────────────
  // Returns the max lane count used. Assigns `item.lane` (0-indexed).
  // If item.lane is already set AND that lane is free for its interval,
  // the existing assignment is preserved — otherwise it's reassigned to the
  // lowest free lane. This keeps user drags sticky but stops overlaps.
  function _svAssignLanes(items){
    var sorted = items.slice().sort(function(a, b){
      var ds = (a.start_time || 0) - (b.start_time || 0);
      return ds !== 0 ? ds : ((a.lane || 0) - (b.lane || 0));
    });
    var laneEnds = {};  // lane → latest end_time occupying it
    sorted.forEach(function(it){
      var s = it.start_time || 0;
      var e = it.end_time   || s + 1;
      var preferred = (typeof it.lane === 'number') ? it.lane : null;
      if (preferred != null && preferred >= 0 && (laneEnds[preferred] == null || laneEnds[preferred] <= s)){
        laneEnds[preferred] = e;
        return; // keep preferred lane
      }
      // Find lowest lane whose latest end_time ≤ this start_time
      var lane = 0;
      while (laneEnds[lane] != null && laneEnds[lane] > s) lane++;
      it.lane = lane;
      laneEnds[lane] = e;
    });
    var maxLane = -1;
    Object.keys(laneEnds).forEach(function(k){ if (+k > maxLane) maxLane = +k; });
    return maxLane + 1;
  }

  // Render one track with multi-lane layout.
  function _svRenderLaneTrack(trackId, items, kind, rowH, gap){
    var track = document.getElementById(trackId);
    if (!track) return;
    var laneCount = Math.max(1, _svAssignLanes(items));
    // Add space for the track label (left side) + top/bottom padding
    var height = laneCount * (rowH + gap) + gap + 2;
    track.style.height = height + 'px';

    // Draw faint row separators for every additional lane
    for (var L = 1; L < laneCount; L++){
      var sep = document.createElement('div');
      sep.className = 'sv-tl-row-sep';
      sep.style.top = (L * (rowH + gap) + gap/2) + 'px';
      track.appendChild(sep);
    }

    // Selected kinds map to _selectedId.kind
    var selKind = kind;
    items.forEach(function(item){
      var dur = (item.end_time || 0) - (item.start_time || 0);
      var lane = Math.max(0, item.lane || 0);
      var b = document.createElement('div');
      b.className = 'sv-tl-block ' + (kind === 'clip' ? 'clip' : 'text') +
                    (_selectedId && _selectedId.kind === selKind && _selectedId.id === item.id ? ' selected' : '');
      b.style.left   = (item.start_time * _TL.pxPerSec) + 'px';
      b.style.width  = Math.max(18, dur * _TL.pxPerSec) + 'px';
      b.style.top    = (lane * (rowH + gap) + gap) + 'px';
      b.style.height = rowH + 'px';
      b.style.bottom = 'auto';
      b.dataset.id   = item.id;
      b.dataset.kind = kind;
      if (kind === 'element'){
        b.style.background = 'linear-gradient(180deg,#C9A56A,#7a5f2e)';
      }
      var label = _svBlockLabel(kind, item, dur);
      b.innerHTML = '<div class="sv-tl-trim-l"></div><span style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis">' + _esc(label) + '</span><div class="sv-tl-trim-r"></div>';
      track.appendChild(b);
      _wireBlockDrag(b, item, kind, rowH, gap);
    });
  }

  function _svBlockLabel(kind, item, dur){
    if (kind === 'clip') return (item.type || 'clip') + ' \u00b7 ' + dur.toFixed(1) + 's';
    if (kind === 'text') return (item.content || '').substring(0, 20);
    // element
    return item.type === 'logo' ? '\u{1F3F7} Logo' :
           item.type === 'lower_third' ? '\u{1F4CA} ' + ((item.content && item.content.name) || 'Lower third') :
           item.type === 'badge' ? '\u{1F4CC} ' + ((item.content && item.content.text) || 'Badge') :
           '\u23F3 Countdown';
  }

  function _wireBlockDrag(el, item, kind, rowH, gap){
    // rowH/gap are passed by _svRenderLaneTrack so body-drag can compute
    // the vertical lane delta. For audio or other legacy calls without
    // these args, default to no vertical lane reassign.
    var LANE_STEP = (rowH && gap != null) ? (rowH + gap) : 0;

    el.addEventListener('click', function(ev){
      ev.stopPropagation();
      _selectedId = { kind: kind, id: item.id };
      _renderProps(); _renderTimeline();
    });
    var trimL = el.querySelector('.sv-tl-trim-l');
    var trimR = el.querySelector('.sv-tl-trim-r');
    [ ['body', el], ['l', trimL], ['r', trimR] ].forEach(function(pair){
      var role = pair[0], target = pair[1];
      target.addEventListener('mousedown', function(ev){
        ev.preventDefault(); ev.stopPropagation();
        var startX = ev.clientX, startY = ev.clientY;
        var s0 = item.start_time, e0 = item.end_time;
        var lane0 = item.lane || 0;
        var didVerticalSnap = false;
        // Snapshot follow-on clips for ripple edit on right-edge drag.
        var followers = [];
        if (role === 'r' && kind === 'clip') {
          var sorted = (_vd.clips||[]).slice().sort(function(a,b){ return a.start_time - b.start_time; });
          var myIdx = sorted.indexOf(item);
          if (myIdx >= 0) {
            followers = sorted.slice(myIdx + 1).map(function(c){
              return { clip: c, s0: c.start_time, e0: c.end_time };
            });
          }
        }
        function onMove(m){
          var dx = (m.clientX - startX) / _TL.pxPerSec;
          var dy = m.clientY - startY;
          if (role === 'body') {
            // Horizontal move → time shift
            item.start_time = Math.max(0, s0 + dx);
            item.end_time   = e0 + dx;
            // Vertical move → lane reassign (CapCut-style)
            if (LANE_STEP > 0) {
              var laneDelta = Math.round(dy / LANE_STEP);
              var newLane = Math.max(0, lane0 + laneDelta);
              if (newLane !== (item.lane || 0)) {
                item.lane = newLane;
                didVerticalSnap = true;
              }
            }
          } else if (role === 'l') {
            item.start_time = Math.max(0, Math.min(e0 - 0.5, s0 + dx));
          } else if (role === 'r') {
            item.end_time = Math.max(s0 + 0.5, Math.min(60, e0 + dx));
            var delta = item.end_time - e0;
            followers.forEach(function(f){
              f.clip.start_time = Math.max(0, f.s0 + delta);
              f.clip.end_time   = f.e0 + delta;
            });
          }
          if (kind === 'clip') item.duration = item.end_time - item.start_time;
          if (kind === 'clip' && followers.length) {
            var lastEnd = followers[followers.length-1].clip.end_time;
            _vd.duration = Math.max(_vd.duration || 15, Math.ceil(lastEnd));
          }
          _renderTimeline(); _drawCanvas();
        }
        function onUp(){
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup', onUp);
          _queueSave();
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
      });
    });

    // Wire draggable red playhead (BUG 2 fix — v4.1.1)
    if (typeof _wirePlayheadDrag === 'function') _wirePlayheadDrag();
  }

  // ── Play (advances playhead, redraws) ───────────
  var _playing = false, _playStart = 0, _playOrigin = 0, _rafId = 0;
  function _setPlayIcon(on){
    var ic = document.getElementById('sv-play-icon');
    if (ic) ic.textContent = on ? '\u23F8' : '\u25B6';
  }
  function _updatePlayheadUI(){
    var ph = document.getElementById('sv-tl-playhead');
    if (ph) ph.style.left = (_playhead * _TL.pxPerSec) + 'px';
    var tm = document.getElementById('sv-play-time');
    if (tm) tm.textContent = _playhead.toFixed(1) + 's / ' + (_vd.duration||0) + 's';
  }
  window._svPlayToggle = function(){
    if (_playing){
      _playing = false;
      if (_rafId) { cancelAnimationFrame(_rafId); _rafId = 0; }
      _setPlayIcon(false);
      _updatePlaybackState(null);
      return;
    }
    var dur = _vd.duration || 15;
    if (_playhead >= dur) _playhead = 0;
    _playing = true;
    _playStart = performance.now();
    _playOrigin = _playhead;
    _setPlayIcon(true);
    _rafId = requestAnimationFrame(_playLoop);
  };
  function _playLoop(){
    if (!_playing) { _rafId = 0; return; }
    var dur = _vd.duration || 15;
    var t = (performance.now() - _playStart) / 1000;
    _playhead = _playOrigin + t;
    if (_playhead >= dur){
      // End reached: stop loop, rewind, reset button, redraw frame 0.
      if (_rafId) { cancelAnimationFrame(_rafId); _rafId = 0; }
      _playing = false;
      _playhead = 0;
      _setPlayIcon(false);
      _updatePlaybackState(null);
      _updatePlayheadUI();
      _drawCanvas();
      return;
    }
    _drawCanvas();
    _updatePlayheadUI();
    _rafId = requestAnimationFrame(_playLoop);
  }
  function _stopPlayback(){
    _playing = false;
    if (_rafId) { cancelAnimationFrame(_rafId); _rafId = 0; }
    _setPlayIcon(false);
    if (_activeVidSrc) {
      var v = _vidCache[_activeVidSrc];
      if (v && !v.paused) { try { v.pause(); } catch(_){} }
      _activeVidSrc = null;
    }
  }

  // ── Playhead scrub (drag the red line OR click anywhere on the ruler) ──
  var _scrubbing = false;
  function _scrubMove(e){
    if (!_scrubbing) return;
    var ruler = document.getElementById('sv-tl-ruler');
    if (!ruler) return;
    var rect = ruler.getBoundingClientRect();
    var x = Math.max(0, Math.min(e.clientX - rect.left, rect.width));
    var dur = _vd.duration || 15;
    var t = (x / _TL.pxPerSec);
    _playhead = Math.max(0, Math.min(t, dur));
    _updatePlayheadUI();
    _drawCanvas();
  }
  function _scrubEnd(){
    _scrubbing = false;
    document.removeEventListener('mousemove', _scrubMove);
    document.removeEventListener('mouseup', _scrubEnd);
  }
  function _wirePlayheadDrag(){
    var ph = document.getElementById('sv-tl-playhead');
    if (ph && !ph._svWired){
      ph._svWired = true;
      ph.style.cursor = 'ew-resize';
      ph.addEventListener('mousedown', function(e){
        e.stopPropagation();
        e.preventDefault();
        if (_playing) window._svPlayToggle(); // pause while scrubbing
        _scrubbing = true;
        document.addEventListener('mousemove', _scrubMove);
        document.addEventListener('mouseup', _scrubEnd);
      });
    }
  }

  // ── Save / Export ────────────────────────────────────────
  function _queueSave(){
    clearTimeout(_saveTimer);
    _saveTimer = setTimeout(_svSaveNow, 800);
  }
  window._svSave = function(){ _svSaveNow(true); };
  function _svSaveNow(manual){
    if (!_designId) return;
    _fetchJson('/studio/video/designs/' + _designId, {
      method:'PUT',
      body: JSON.stringify({ name: _designName, video_data: _vd })
    }).then(function(d){
      if (manual && d.success) _toast('Saved', 'success');
    }).catch(function(err){
      if (manual) _toast('Save failed: ' + err.message, 'error');
    });
  }

  window._svExport = function(){
    if (!_designId) return;
    _svSaveNow(false);
    _fetchJson('/studio/video/designs/' + _designId + '/export', { method:'POST' })
      .then(function(d){
        if (!d.success) throw new Error(d.error || 'export_failed');
        _showExportOverlay();
        _pollExport();
      }).catch(function(err){ _toast('Export failed: ' + err.message, 'error'); });
  };

  function _showExportOverlay(){
    var wrap = document.getElementById('sv-canvas-wrap');
    if (!wrap || wrap.querySelector('.sv-export-overlay')) return;
    var ov = document.createElement('div');
    ov.className = 'sv-export-overlay';
    ov.innerHTML =
      '<div class="sv-export-box">' +
        '<h3>Rendering your video</h3>' +
        '<div class="msg" id="sv-export-msg">Processing with FFmpeg\u2026</div>' +
        '<div class="sv-progress"><div class="sv-progress-fill" id="sv-export-fill" style="width:0%"></div></div>' +
        '<div id="sv-export-actions"><button class="sv-btn secondary" onclick="_svCancelExport()">Cancel</button></div>' +
      '</div>';
    wrap.appendChild(ov);
  }
  window._svCancelExport = function(){
    if (_pollTimer) clearTimeout(_pollTimer);
    var ov = document.querySelector('.sv-export-overlay');
    if (ov) ov.remove();
  };
  function _pollExport(){
    _fetchJson('/studio/video/designs/' + _designId + '/export-status')
      .then(function(d){
        var fill = document.getElementById('sv-export-fill');
        var msg  = document.getElementById('sv-export-msg');
        if (d.status === 'processing' && fill){ fill.style.width = Math.max(d.progress_pct||5, 5) + '%'; msg.textContent='Processing with FFmpeg\u2026 (' + (d.progress_pct||0) + '%)'; _pollTimer = setTimeout(_pollExport, 2000); return; }
        if (d.status === 'pending') { if (fill) fill.style.width='3%'; msg.textContent='Queued\u2026'; _pollTimer = setTimeout(_pollExport, 2000); return; }
        if (d.status === 'done'){
          if (fill) fill.style.width='100%'; msg.textContent='\u2713 Ready';
          document.getElementById('sv-export-actions').innerHTML =
            '<a class="sv-btn" href="' + _esc(d.video_url) + '" download target="_blank" style="text-decoration:none;text-align:center">\u2B07 Download MP4</a>' +
            '<button class="sv-btn secondary" onclick="_svCancelExport()">Close</button>';
          _toast('Video ready', 'success');
          return;
        }
        if (d.status === 'failed'){
          if (fill) fill.style.width='100%'; msg.innerHTML = '<span style="color:#FF8080">Export failed: ' + _esc(d.error||'unknown') + '</span>';
          document.getElementById('sv-export-actions').innerHTML = '<button class="sv-btn secondary" onclick="_svCancelExport()">Close</button>';
          return;
        }
        _pollTimer = setTimeout(_pollExport, 2000);
      }).catch(function(err){
        var msg = document.getElementById('sv-export-msg');
        if (msg) msg.innerHTML = '<span style="color:#FF8080">Poll failed: ' + _esc(err.message) + '</span>';
      });
  }
})();

// ═══════════════════════════════════════════════════════════════════
// STUDIO VIDEO EDITOR v5.2.0 — Phase 3 rewrite
// True 30fps canvas playback. Video element pool. Ken Burns.
// 8 transitions. 10 text animations. Timeline with ripple trim.
// Mounts from the video gallery when user clicks a clip_json design.
// ═══════════════════════════════════════════════════════════════════
(function(){
  'use strict';

  // ── STATE ──────────────────────────────────────────────────
  var VE = {
    vd: null,              // full video_data JSON (clips, text_overlays, elements, audio)
    designId: null,
    playing: false,
    playhead: 0,
    rafId: null,
    lastFrameTime: 0,
    videoPool: {},         // clipId → HTMLVideoElement
    imageCache: {},        // url → HTMLImageElement
    seekRequested: {},     // clipId → bool (throttle seek events)
    selected: null,        // { kind:'clip'|'text'|'element', id }
    dirty: false,
    saving: false,
    history: [],           // snapshots
    historyIndex: -1,
    zoom: 1.0,             // canvas display zoom (not timeline)
    tlZoom: 1.0,           // timeline zoom 1x = 60s across width
    canvas: null,
    ctx: null,
    root: null,
    tabActive: 'clips',
    dragState: null,
    scrubbing: false,
    trimState: null,
    autoSaveTimer: null,
    cssInjected: false,
    viewMode: 'reels',     // reels|square|landscape
    needsRedraw: true,     // on when selection changes or zoom pan
  };
  window._svVE = VE;

  var MAX_DURATION = 60;
  var FPS_TARGET = 30;

  var TRANSITION_TYPES = ['fade','slide_left','slide_right','slide_up','slide_down','zoom_in','zoom_out','dissolve','glitch'];
  var TEXT_ANIM_TYPES  = ['fade','slide_up','slide_down','slide_left','scale_pop','scale_in','typewriter','fade_rise','word_by_word','glitch'];

  var FILTERS = {
    none:       '',
    warm:       'sepia(0.3) saturate(1.4) hue-rotate(-10deg)',
    cool:       'hue-rotate(20deg) saturate(0.9) brightness(1.05)',
    bw:         'grayscale(1) contrast(1.1)',
    fade:       'brightness(1.1) contrast(0.85) saturate(0.8)',
    vivid:      'saturate(1.8) contrast(1.15)',
    cinematic:  'contrast(1.2) brightness(0.9) saturate(0.85)',
    matte:      'contrast(0.85) brightness(1.05) saturate(0.7)',
    film:       'sepia(0.2) contrast(1.1) brightness(0.95)',
    neon:       'saturate(2.5) contrast(1.3) hue-rotate(10deg)',
  };

  var KEN_BURNS_PRESETS = {
    'zoom_in_right':  { start_scale: 1.0,  end_scale: 1.15, start_x: 0,   end_x: -30, start_y: 0, end_y: 0 },
    'zoom_in_left':   { start_scale: 1.0,  end_scale: 1.15, start_x: 0,   end_x:  30, start_y: 0, end_y: 0 },
    'zoom_out_right': { start_scale: 1.15, end_scale: 1.0,  start_x: -30, end_x:  0,  start_y: 0, end_y: 0 },
    'zoom_out_left':  { start_scale: 1.15, end_scale: 1.0,  start_x:  30, end_x:  0,  start_y: 0, end_y: 0 },
    'static':         { start_scale: 1.05, end_scale: 1.0,  start_x: 0,   end_x:  0,  start_y: 0, end_y: 0 },
  };

  // Entry point — called by the existing gallery when user clicks a clip_json design.
  // Swap in as window._svOpenClipEditor so the gallery routes here.
  function _svOpenClipEditor(designId){
    if (!designId) { _svToast('No design id', 'error'); return; }
    VE.designId = designId;
    _svInjectCss();
    _fetchJson('/studio/designs/' + designId).then(function(r){
      var row = r.design || r; // handle both response shapes
      if (!row || !row.id) throw new Error('not_found');
      var vd = {};
      try { vd = typeof row.video_data === 'string' ? JSON.parse(row.video_data || '{}') : (row.video_data || {}); } catch(_){}
      if (row.layers_json && !vd.clips) { try { vd = JSON.parse(row.layers_json); } catch(_){} }
      VE.vd = _svNormalizeVd(vd, row);
      _svMountEditor(row);
    }).catch(function(e){ _svToast('Load failed: ' + e.message, 'error'); });
  }
  window._svOpenClipEditor = _svOpenClipEditor;

  function _svNormalizeVd(vd, row){
    vd = vd || {};
    vd.canvas_width   = vd.canvas_width   || row.canvas_width   || 1080;
    vd.canvas_height  = vd.canvas_height  || row.canvas_height  || 1920;
    vd.duration       = Math.min(MAX_DURATION, vd.duration || 12);
    vd.fps            = vd.fps || FPS_TARGET;
    vd.background_color = vd.background_color || '#0B0C11';
    vd.global_filter  = vd.global_filter || 'none';
    vd.clips          = Array.isArray(vd.clips)          ? vd.clips          : [];
    vd.text_overlays  = Array.isArray(vd.text_overlays)  ? vd.text_overlays  : [];
    vd.elements       = Array.isArray(vd.elements)       ? vd.elements       : [];
    vd.audio          = vd.audio || null;

    // Ensure every clip has start_time/end_time/duration consistency
    vd.clips.forEach(function(c, i){
      c.id          = c.id || ('clip_' + i + '_' + Date.now());
      c.type        = c.type || (c.source_url ? 'video' : 'placeholder');
      c.start_time  = c.start_time != null ? Number(c.start_time) : 0;
      c.duration    = Number(c.duration || c.end_time - c.start_time || 3);
      c.end_time    = c.end_time   != null ? Number(c.end_time)   : c.start_time + c.duration;
      if (c.transition_in) c.transition_in.duration = Number(c.transition_in.duration || 0.4);
    });
    vd.text_overlays.forEach(function(t, i){
      t.id = t.id || ('text_' + i + '_' + Date.now());
      t.animation_in = t.animation_in || { type: 'fade', duration: 0.4 };
    });
    vd.elements.forEach(function(el, i){
      el.id = el.id || ('el_' + i + '_' + Date.now());
    });
    return vd;
  }

  // ── MOUNT ─────────────────────────────────────────────────
  function _svMountEditor(row){
    var host = _svHost();
    host.innerHTML = _svEditorHtml(row);
    VE.root   = host.querySelector('.sv-editor-root');
    VE.canvas = host.querySelector('#sv-canvas');
    VE.ctx    = VE.canvas ? VE.canvas.getContext('2d') : null;
    if (VE.canvas) { VE.canvas.width = VE.vd.canvas_width; VE.canvas.height = VE.vd.canvas_height; }

    _svInitVideoPool();
    _svPreloadImages();
    _svFitCanvasToViewport();

    _svWireTopbar();
    _svWireLeftTabs();
    _svRenderLeftPanel(VE.tabActive);
    _svWirePlaybackControls();
    _svWireCanvasClicks();
    _svRenderTimeline();
    _svRenderProps();
    _svInitHistory();

    // Auto-save 30s
    if (VE.autoSaveTimer) clearInterval(VE.autoSaveTimer);
    VE.autoSaveTimer = setInterval(function(){ if (VE.dirty) _svAutoSave(); }, 30000);

    // Draw first frame
    _svDrawFrame(0);
  }

  function _svEditorHtml(row){
    var n = _svEsc(row.name || 'Untitled video');
    return (
      '<div class="sv-editor-root">' +
        '<div class="sv-topbar">' +
          '<button class="sv-back" id="sv-back">\u2190 Back</button>' +
          '<input class="sv-design-name" id="sv-design-name" value="' + n + '"/>' +
          '<div class="sv-topbar-center">' +
            '<span id="sv-duration-label">0.0s / ' + VE.vd.duration.toFixed(1) + 's</span>' +
          '</div>' +
          '<div class="sv-topbar-right">' +
            '<button class="sv-icon-btn" id="sv-undo" title="Undo">\u21a9</button>' +
            '<button class="sv-icon-btn" id="sv-redo" title="Redo">\u21aa</button>' +
            '<span id="sv-save-status" class="sv-save-status"></span>' +
            '<button class="sv-btn-ghost" id="sv-save-btn">Save</button>' +
            '<button class="sv-btn-primary" id="sv-export-btn">Export MP4</button>' +
          '</div>' +
        '</div>' +
        '<div class="sv-shell">' +
          '<div class="sv-left">' +
            '<div class="sv-tabs">' +
              ['clips','text','elements','transitions','filters','audio','ai'].map(function(t){
                var lbl = { clips:'Clips', text:'Text', elements:'Elements', transitions:'Transitions', filters:'Filters', audio:'Audio', ai:'AI' }[t];
                return '<button class="sv-tab' + (t === VE.tabActive ? ' active' : '') + '" data-tab="' + t + '">' + lbl + '</button>';
              }).join('') +
            '</div>' +
            '<div class="sv-tab-body" id="sv-tab-body"></div>' +
          '</div>' +
          '<div class="sv-canvas-wrap" id="sv-canvas-wrap">' +
            '<div class="sv-canvas-toolbar">' +
              '<button class="sv-view-btn active" data-view="reels">9:16</button>' +
              '<button class="sv-view-btn" data-view="square">1:1</button>' +
              '<button class="sv-view-btn" data-view="landscape">16:9</button>' +
              '<div class="sv-toolbar-spacer"></div>' +
              '<button class="sv-icon-btn" id="sv-fit-btn">Fit</button>' +
            '</div>' +
            '<div class="sv-canvas-frame" id="sv-canvas-frame">' +
              '<canvas id="sv-canvas" width="' + VE.vd.canvas_width + '" height="' + VE.vd.canvas_height + '"></canvas>' +
              '<div id="sv-canvas-overlay" class="sv-canvas-overlay"></div>' +
            '</div>' +
            '<div class="sv-playback">' +
              '<button class="sv-icon-btn" id="sv-back-frame">\u23EA</button>' +
              '<button class="sv-play-btn" id="sv-play-btn">\u25B6</button>' +
              '<button class="sv-icon-btn" id="sv-fwd-frame">\u23E9</button>' +
              '<span id="sv-time-label">0.0s / ' + VE.vd.duration.toFixed(1) + 's</span>' +
            '</div>' +
          '</div>' +
          '<div class="sv-right" id="sv-right-panel">' +
            '<div id="sv-props"><div class="sv-hint">Select a clip or element</div></div>' +
          '</div>' +
        '</div>' +
        '<div class="sv-timeline" id="sv-timeline">' +
          '<div class="sv-tl-left-col">' +
            '<div class="sv-tl-hdr-spacer"></div>' +
            '<div class="sv-track-label">Clips</div>' +
            '<div class="sv-track-label">Text</div>' +
            '<div class="sv-track-label">Elements</div>' +
            '<div class="sv-track-label">Audio</div>' +
          '</div>' +
          '<div class="sv-tl-scroll" id="sv-tl-scroll">' +
            '<div class="sv-tl-inner" id="sv-tl-inner">' +
              '<div class="sv-tl-ruler" id="sv-tl-ruler"></div>' +
              '<div class="sv-tl-track" id="sv-track-clips"></div>' +
              '<div class="sv-tl-track" id="sv-track-text"></div>' +
              '<div class="sv-tl-track" id="sv-track-elements"></div>' +
              '<div class="sv-tl-track" id="sv-track-audio"></div>' +
              '<div class="sv-tl-playhead" id="sv-tl-playhead"></div>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
  }

  function _svInitVideoPool(){
    VE.videoPool = {};
    (VE.vd.clips || []).forEach(function(c){
      if (c.type === 'video' && c.source_url) {
        var v = document.createElement('video');
        v.src = c.source_url;
        v.muted = true;
        v.preload = 'auto';
        v.crossOrigin = 'anonymous';
        v.playsInline = true;
        VE.videoPool[c.id] = v;
        try { v.load(); } catch(_){}
      }
    });
  }

  function _svPreloadImages(){
    (VE.vd.clips || []).forEach(function(c){
      if (c.type === 'image' && c.source_url && !VE.imageCache[c.source_url]) {
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function(){ VE.needsRedraw = true; };
        img.src = c.source_url;
        VE.imageCache[c.source_url] = img;
      }
    });
    // Cache background images in elements
    (VE.vd.elements || []).forEach(function(e){
      var url = (e.content && (e.content.logo_url || e.content.image_url)) || null;
      if (url && !VE.imageCache[url]) {
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function(){ VE.needsRedraw = true; };
        img.src = url;
        VE.imageCache[url] = img;
      }
    });
  }

  function _svHost(){
    var el = document.getElementById('sv-host');
    if (el) return el;
    // Re-create host in the studio root
    var root = document.getElementById('studio-root') || document.body;
    el = document.createElement('div');
    el.id = 'sv-host';
    root.appendChild(el);
    return el;
  }

  // ── Utility helpers ────────────────────────────────────────
  function _svTok(){ return localStorage.getItem('lu_token') || ''; }
  function _svEsc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function _svApi(){ return (window.LU_CFG && window.LU_API_BASE) ? window.LU_API_BASE : '/api'; }
  function _svToast(m, t){ if (typeof showToast === 'function') showToast(m, t || 'info'); }

  // Keep local _fetchJson in scope separate from studio.js's (this file
  // executes before studio.js on pages where the video editor stands alone).
  function _fetchJson(url, opts){
    opts = opts || {}; opts.headers = Object.assign({ 'Authorization': 'Bearer ' + _svTok(), 'Content-Type':'application/json' }, opts.headers || {});
    return fetch(_svApi() + url, opts).then(function(r){ return r.json(); });
  }

  // ── CONTINUED IN PART 2 ──

  // ═══════════════════════════════════════════════════════════════
  // PART 2 — Playback loop, drawFrame, clips, Ken Burns
  // ═══════════════════════════════════════════════════════════════

  function _svTotalDuration(){
    var last = 0;
    (VE.vd.clips || []).forEach(function(c){ if (c.end_time > last) last = c.end_time; });
    (VE.vd.text_overlays || []).forEach(function(t){ if (t.end_time > last) last = t.end_time; });
    return Math.min(MAX_DURATION, Math.max(VE.vd.duration || 0, last));
  }

  function _svPlay(){
    if (VE.playhead >= _svTotalDuration()) VE.playhead = 0;
    VE.playing = true;
    document.getElementById('sv-play-btn').textContent = '\u23F8';
    VE.lastFrameTime = performance.now();
    // Start all video elements that are active right now
    _svSyncVideoPool(VE.playhead, true);
    VE.rafId = requestAnimationFrame(_svRafLoop);
  }

  function _svStop(){
    VE.playing = false;
    if (VE.rafId) cancelAnimationFrame(VE.rafId);
    VE.rafId = null;
    document.getElementById('sv-play-btn').textContent = '\u25B6';
    Object.values(VE.videoPool).forEach(function(v){ try { v.pause(); } catch(_){} });
  }

  function _svRafLoop(ts){
    if (!VE.playing) return;
    var dt = (ts - VE.lastFrameTime) / 1000;
    VE.lastFrameTime = ts;
    VE.playhead += dt;
    var total = _svTotalDuration();
    if (VE.playhead >= total) {
      VE.playhead = total;
      _svDrawFrame(VE.playhead);
      _svUpdatePlayheadUI();
      _svStop();
      return;
    }
    _svDrawFrame(VE.playhead);
    _svUpdatePlayheadUI();
    VE.rafId = requestAnimationFrame(_svRafLoop);
  }

  // Ensure active clips' video elements are playing; inactive ones paused
  function _svSyncVideoPool(t, forcePlay){
    (VE.vd.clips || []).forEach(function(c){
      var v = VE.videoPool[c.id]; if (!v) return;
      var active = t >= c.start_time && t <= c.end_time;
      if (active) {
        var clipTime = t - c.start_time + (c.trim_start || 0);
        if (Math.abs(v.currentTime - clipTime) > 0.2) { try { v.currentTime = clipTime; } catch(_){} }
        if (forcePlay || VE.playing) { var p = v.play(); if (p && p.catch) p.catch(function(){}); }
      } else {
        try { v.pause(); } catch(_){}
      }
    });
  }

  // ── DRAW FRAME ─────────────────────────────────────────────
  function _svDrawFrame(t){
    var ctx = VE.ctx; if (!ctx) return;
    var W = VE.vd.canvas_width, H = VE.vd.canvas_height;
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = VE.vd.background_color || '#000';
    ctx.fillRect(0, 0, W, H);

    // Sync video pool to current time
    _svSyncVideoPool(t, false);

    // Find active + adjacent clips
    var active = _svClipAtTime(t);
    var prev   = active ? _svClipAt(_svClipIndex(active) - 1) : null;
    var next   = active ? _svClipAt(_svClipIndex(active) + 1) : null;

    if (active) {
      // Transition IN (overlap with previous clip)
      var tin = active.transition_in || null;
      var tinDur = tin ? Number(tin.duration || 0.4) : 0;
      var tinEnd = active.start_time + tinDur;

      // Transition OUT of active (blend into next clip)
      var tout = next ? (next.transition_in || active.transition_out) : null;
      var toutDur = tout ? Number(tout.duration || 0.4) : 0;
      var toutStart = active.end_time - toutDur;

      if (prev && t < tinEnd && tinDur > 0) {
        var progress = Math.max(0, Math.min(1, (t - active.start_time) / tinDur));
        _svDrawTransition(ctx, prev, active, progress, (tin && tin.type) || 'fade', W, H, t);
      } else if (next && t > toutStart && toutDur > 0) {
        var progressOut = Math.max(0, Math.min(1, (t - toutStart) / toutDur));
        _svDrawTransition(ctx, active, next, progressOut, (tout && tout.type) || 'fade', W, H, t);
      } else {
        _svDrawClip(ctx, active, t, W, H);
      }
    } else {
      // No clip at this time — dotted placeholder
      ctx.fillStyle = 'rgba(108,92,231,.08)';
      ctx.fillRect(0, 0, W, H);
    }

    // Global filter — CSS-based (fast, no getImageData)
    VE.canvas.style.filter = FILTERS[VE.vd.global_filter] || '';

    // Active text overlays
    (VE.vd.text_overlays || []).forEach(function(ov){
      if (t >= ov.start_time && t <= ov.end_time) _svDrawText(ctx, ov, t, W, H);
    });

    // Active elements
    (VE.vd.elements || []).forEach(function(el){
      if (t >= (el.start_time || 0) && t <= (el.end_time || VE.vd.duration)) _svDrawElement(ctx, el, t, W, H);
    });
  }

  function _svClipAtTime(t){
    var clips = (VE.vd.clips || []).slice().sort(function(a,b){ return a.start_time - b.start_time; });
    for (var i = 0; i < clips.length; i++){
      if (t >= clips[i].start_time && t <= clips[i].end_time) return clips[i];
    }
    return null;
  }
  function _svClipIndex(c){
    var sorted = (VE.vd.clips || []).slice().sort(function(a,b){ return a.start_time - b.start_time; });
    for (var i = 0; i < sorted.length; i++) if (sorted[i].id === c.id) return i;
    return -1;
  }
  function _svClipAt(i){
    var sorted = (VE.vd.clips || []).slice().sort(function(a,b){ return a.start_time - b.start_time; });
    return sorted[i] || null;
  }

  function _svDrawClip(ctx, clip, t, W, H){
    if (clip.type === 'video') {
      var v = VE.videoPool[clip.id];
      if (!v || v.readyState < 2) return _svDrawPlaceholderClip(ctx, clip, W, H);
      if (clip.ken_burns && clip.ken_burns.enabled) _svDrawKenBurns(ctx, v, clip, t, W, H);
      else ctx.drawImage(v, 0, 0, W, H);
    } else if (clip.type === 'image') {
      var img = VE.imageCache[clip.source_url];
      if (!img || !img.complete || !img.naturalWidth) return _svDrawPlaceholderClip(ctx, clip, W, H);
      if (clip.ken_burns && clip.ken_burns.enabled) _svDrawKenBurns(ctx, img, clip, t, W, H);
      else {
        var scale = Math.max(W / img.naturalWidth, H / img.naturalHeight);
        var sw = img.naturalWidth * scale;
        var sh = img.naturalHeight * scale;
        ctx.drawImage(img, (W - sw) / 2, (H - sh) / 2, sw, sh);
      }
    } else {
      _svDrawPlaceholderClip(ctx, clip, W, H);
    }
    // Per-clip filter
    if (clip.filter && clip.filter !== 'none' && FILTERS[clip.filter]) {
      // apply filter as CSS via canvas filter — temporary approach: we draw
      // into an offscreen canvas with filter applied. For speed, we rely on
      // the global CSS filter for previews and defer per-clip to export.
    }
  }
  function _svDrawPlaceholderClip(ctx, clip, W, H){
    ctx.fillStyle = 'rgba(108,92,231,.12)';
    ctx.fillRect(0, 0, W, H);
    ctx.strokeStyle = 'rgba(108,92,231,.55)'; ctx.lineWidth = 2; ctx.setLineDash([10, 6]);
    ctx.strokeRect(20, 20, W - 40, H - 40); ctx.setLineDash([]);
    ctx.fillStyle = 'rgba(108,92,231,.9)';
    ctx.font = 'bold 36px "DM Sans", sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(clip.label || clip.type || 'Empty slot', W/2, H/2 - 20);
    ctx.font = '20px "DM Sans", sans-serif';
    ctx.fillText(clip.duration.toFixed(1) + 's', W/2, H/2 + 30);
  }

  function _svDrawKenBurns(ctx, src, clip, t, W, H){
    var kb = clip.ken_burns || KEN_BURNS_PRESETS.static;
    var progress = (t - clip.start_time) / Math.max(0.001, clip.duration);
    progress = Math.max(0, Math.min(1, progress));
    var scale = _svLerp(kb.start_scale || 1.0, kb.end_scale || 1.1, progress);
    var panX  = _svLerp(kb.start_x || 0, kb.end_x || 0, progress);
    var panY  = _svLerp(kb.start_y || 0, kb.end_y || 0, progress);
    ctx.save();
    ctx.translate(W / 2 + panX, H / 2 + panY);
    ctx.scale(scale, scale);
    if (src instanceof HTMLVideoElement) {
      ctx.drawImage(src, -W / 2, -H / 2, W, H);
    } else {
      var s = Math.max(W / src.naturalWidth, H / src.naturalHeight);
      var sw = src.naturalWidth * s, sh = src.naturalHeight * s;
      ctx.drawImage(src, -sw / 2, -sh / 2, sw, sh);
    }
    ctx.restore();
  }

  function _svLerp(a, b, p){ return a + (b - a) * p; }

  // ── CONTINUED IN PART 3 ──

  // ═══════════════════════════════════════════════════════════════
  // PART 3 — Transitions, text animations, element drawing
  // ═══════════════════════════════════════════════════════════════

  function _svDrawTransition(ctx, fromClip, toClip, progress, type, W, H, t){
    // Always draw the "from" clip first as the base, then overlay the "to" clip
    // with the transition effect. For performance, each clip is drawn straight
    // onto the main canvas — no offscreen composites.
    switch (type) {
      case 'fade':
        _svDrawClip(ctx, fromClip, Math.min(fromClip.end_time, t), W, H);
        ctx.save(); ctx.globalAlpha = progress;
        _svDrawClip(ctx, toClip, toClip.start_time, W, H);
        ctx.restore();
        return;
      case 'slide_left':
        // From clip slides LEFT off screen, To clip enters from RIGHT
        ctx.save(); ctx.translate(-progress * W, 0);
        _svDrawClip(ctx, fromClip, Math.min(fromClip.end_time, t), W, H);
        ctx.restore();
        ctx.save(); ctx.translate((1 - progress) * W, 0);
        _svDrawClip(ctx, toClip, toClip.start_time, W, H);
        ctx.restore();
        return;
      case 'slide_right':
        ctx.save(); ctx.translate(progress * W, 0);
        _svDrawClip(ctx, fromClip, Math.min(fromClip.end_time, t), W, H);
        ctx.restore();
        ctx.save(); ctx.translate(-(1 - progress) * W, 0);
        _svDrawClip(ctx, toClip, toClip.start_time, W, H);
        ctx.restore();
        return;
      case 'slide_up':
        ctx.save(); ctx.translate(0, -progress * H);
        _svDrawClip(ctx, fromClip, Math.min(fromClip.end_time, t), W, H);
        ctx.restore();
        ctx.save(); ctx.translate(0, (1 - progress) * H);
        _svDrawClip(ctx, toClip, toClip.start_time, W, H);
        ctx.restore();
        return;
      case 'slide_down':
        ctx.save(); ctx.translate(0, progress * H);
        _svDrawClip(ctx, fromClip, Math.min(fromClip.end_time, t), W, H);
        ctx.restore();
        ctx.save(); ctx.translate(0, -(1 - progress) * H);
        _svDrawClip(ctx, toClip, toClip.start_time, W, H);
        ctx.restore();
        return;
      case 'zoom_in':
        _svDrawClip(ctx, fromClip, Math.min(fromClip.end_time, t), W, H);
        ctx.save(); ctx.globalAlpha = progress;
        var zi = 1.2 - progress * 0.2;
        ctx.translate(W / 2, H / 2); ctx.scale(zi, zi); ctx.translate(-W / 2, -H / 2);
        _svDrawClip(ctx, toClip, toClip.start_time, W, H);
        ctx.restore();
        return;
      case 'zoom_out':
        _svDrawClip(ctx, fromClip, Math.min(fromClip.end_time, t), W, H);
        ctx.save(); ctx.globalAlpha = progress;
        var zo = 0.8 + progress * 0.2;
        ctx.translate(W / 2, H / 2); ctx.scale(zo, zo); ctx.translate(-W / 2, -H / 2);
        _svDrawClip(ctx, toClip, toClip.start_time, W, H);
        ctx.restore();
        return;
      case 'dissolve':
        _svDrawClip(ctx, fromClip, Math.min(fromClip.end_time, t), W, H);
        ctx.save(); ctx.globalAlpha = progress; ctx.globalCompositeOperation = 'lighter';
        _svDrawClip(ctx, toClip, toClip.start_time, W, H);
        ctx.restore();
        return;
      case 'glitch':
        _svDrawClip(ctx, fromClip, Math.min(fromClip.end_time, t), W, H);
        // RGB-split crossfade with 3 flash bursts
        var burst = Math.floor(progress * 6) % 2 === 0;
        if (burst) {
          ctx.save();
          // Red channel offset right
          ctx.globalAlpha = 0.6 * progress; ctx.globalCompositeOperation = 'screen';
          ctx.translate(4, 0); _svDrawClip(ctx, toClip, toClip.start_time, W, H);
          ctx.restore();
          ctx.save();
          // Blue channel offset left
          ctx.globalAlpha = 0.6 * progress;
          ctx.translate(-4, 0); _svDrawClip(ctx, toClip, toClip.start_time, W, H);
          ctx.restore();
        }
        ctx.save(); ctx.globalAlpha = progress;
        _svDrawClip(ctx, toClip, toClip.start_time, W, H);
        ctx.restore();
        return;
      default:
        ctx.save(); ctx.globalAlpha = progress;
        _svDrawClip(ctx, toClip, toClip.start_time, W, H);
        ctx.restore();
    }
  }

  // ── TEXT OVERLAY DRAW ──────────────────────────────────────
  function _svDrawText(ctx, text, t, W, H){
    var animIn   = text.animation_in  || { type:'fade', duration: 0.4 };
    var animOut  = text.animation_out || { type:'fade', duration: 0.3 };
    var inDur    = Number(animIn.duration  || 0.4);
    var outDur   = Number(animOut.duration || 0.3);
    var phase = 'hold';
    var progress = 1;
    if (t < text.start_time + inDur) { phase = 'in';  progress = Math.max(0, Math.min(1, (t - text.start_time) / inDur)); }
    else if (t > text.end_time - outDur) { phase = 'out'; progress = Math.max(0, Math.min(1, (text.end_time - t) / outDur)); }

    var x = (text.position && text.position.x != null) ? text.position.x : W / 2;
    var y = (text.position && text.position.y != null) ? text.position.y : H / 2;
    var fontSize   = text.font_size || 48;
    var fontFamily = text.font_family || 'DM Sans';
    var fontWeight = text.font_weight || '700';
    var color      = text.color || '#FFFFFF';
    var content    = text.content || '';
    var anchor     = text.anchor || 'center';

    ctx.save();
    ctx.font = fontWeight + ' ' + fontSize + 'px "' + fontFamily + '", "DM Sans", sans-serif';
    ctx.textAlign = anchor === 'center' ? 'center' : anchor === 'right' ? 'right' : 'left';
    ctx.textBaseline = 'top';
    ctx.fillStyle = color;

    var animType = (phase === 'out') ? (animOut.type || 'fade') : (animIn.type || 'fade');

    // Optional background box (drawn first behind text)
    if (text.background_color) {
      var metrics = ctx.measureText(content);
      var padding = text.background_padding || 8;
      var bgX = anchor === 'center' ? x - metrics.width / 2 - padding : (anchor === 'right' ? x - metrics.width - padding : x - padding);
      var bgW = metrics.width + padding * 2;
      var bgH = fontSize * 1.3 + padding * 2;
      var bgY = y - padding;
      ctx.save(); ctx.globalAlpha = progress * 0.88; ctx.fillStyle = text.background_color;
      if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(bgX, bgY, bgW, bgH, 8); ctx.fill(); }
      else ctx.fillRect(bgX, bgY, bgW, bgH);
      ctx.restore();
    }

    // Pulse loop animation (always-on when in hold phase)
    var loopMul = 1;
    if (phase === 'hold' && text.animation_loop && text.animation_loop.type === 'pulse') {
      var period = text.animation_loop.period || 1.2;
      loopMul = 0.7 + 0.3 * (1 + Math.sin(2 * Math.PI * t / period)) / 2;
    }

    switch (animType) {
      case 'fade':
        ctx.globalAlpha = progress * loopMul;
        _svFillTextMulti(ctx, content, x, y, fontSize, text.line_height);
        break;
      case 'slide_up': {
        var oy = (1 - progress) * 60;
        ctx.globalAlpha = progress * loopMul;
        _svFillTextMulti(ctx, content, x, y + oy, fontSize, text.line_height);
        break;
      }
      case 'slide_down': {
        var od = -(1 - progress) * 60;
        ctx.globalAlpha = progress * loopMul;
        _svFillTextMulti(ctx, content, x, y + od, fontSize, text.line_height);
        break;
      }
      case 'slide_left': {
        var ox = -(1 - progress) * 80;
        ctx.globalAlpha = progress * loopMul;
        _svFillTextMulti(ctx, content, x + ox, y, fontSize, text.line_height);
        break;
      }
      case 'scale_pop': {
        var scale = progress < 0.8 ? (0.5 + progress * 0.8) : (1.05 - (progress - 0.8) * 0.25);
        ctx.globalAlpha = Math.min(1, progress * 2) * loopMul;
        ctx.translate(x, y + fontSize / 2); ctx.scale(scale, scale); ctx.translate(-x, -(y + fontSize / 2));
        _svFillTextMulti(ctx, content, x, y, fontSize, text.line_height);
        break;
      }
      case 'scale_in': {
        var s = Math.pow(progress, 0.5);
        ctx.globalAlpha = progress * loopMul;
        ctx.translate(x, y + fontSize / 2); ctx.scale(s, s); ctx.translate(-x, -(y + fontSize / 2));
        _svFillTextMulti(ctx, content, x, y, fontSize, text.line_height);
        break;
      }
      case 'typewriter': {
        var cnt = Math.floor(progress * content.length);
        var visible = content.substring(0, cnt);
        ctx.globalAlpha = loopMul;
        ctx.fillText(visible, x, y);
        if (cnt < content.length && Math.floor(t * 2) % 2 === 0) {
          var w = ctx.measureText(visible).width;
          var cx = anchor === 'center' ? x + w / 2 - 1 : x + w + 2;
          ctx.fillRect(cx, y, 2, fontSize);
        }
        break;
      }
      case 'fade_rise': {
        var oyf = (1 - progress) * 24;
        ctx.globalAlpha = progress * loopMul;
        _svFillTextMulti(ctx, content, x, y + oyf, fontSize, text.line_height);
        break;
      }
      case 'word_by_word': {
        var words = content.split(' ');
        var n = Math.ceil(progress * words.length);
        ctx.globalAlpha = loopMul;
        _svFillTextMulti(ctx, words.slice(0, n).join(' '), x, y, fontSize, text.line_height);
        break;
      }
      case 'glitch': {
        var phaseG = Math.floor(t * 20) % 5;
        ctx.globalAlpha = progress * loopMul;
        if (phaseG < 2) {
          ctx.save(); ctx.fillStyle = 'rgba(255,50,50,.85)';
          ctx.translate(3, 0); ctx.fillText(content, x, y); ctx.restore();
          ctx.save(); ctx.fillStyle = 'rgba(60,60,255,.85)';
          ctx.translate(-3, 0); ctx.fillText(content, x, y); ctx.restore();
        }
        ctx.fillStyle = color;
        ctx.fillText(content, x, y);
        break;
      }
      default:
        ctx.globalAlpha = progress * loopMul;
        _svFillTextMulti(ctx, content, x, y, fontSize, text.line_height);
    }

    // Optional outline
    if (text.outline) {
      ctx.strokeStyle = text.outline_color || '#000';
      ctx.lineWidth = text.outline_width || 2;
      ctx.globalAlpha = progress * loopMul;
      ctx.strokeText(content, x, y);
    }

    ctx.restore();
  }

  function _svFillTextMulti(ctx, content, x, y, fontSize, lineHeightMul){
    var lh = (lineHeightMul || 1.15) * fontSize;
    var lines = String(content).split('\n');
    lines.forEach(function(line, i){ ctx.fillText(line, x, y + i * lh); });
  }

  // ── ELEMENT DRAW (on canvas) ──────────────────────────────
  function _svDrawElement(ctx, el, t, W, H){
    var animIn = el.animation_in || { type:'fade', duration: 0.4 };
    var inDur  = Number(animIn.duration || 0.4);
    var progress = 1;
    if (t < el.start_time + inDur) progress = Math.max(0, Math.min(1, (t - el.start_time) / inDur));

    ctx.save();
    ctx.globalAlpha = progress;

    var pos  = el.position || {};
    var size = el.size || {};
    var x = pos.x || 80, y = pos.y || 80, w = size.width || 200, h = size.height || 60;

    var typ = el.type || 'logo';
    if (typ === 'logo') {
      var url = (el.content && el.content.url) || el.content?.logo_url;
      var img = url ? VE.imageCache[url] : null;
      if (!img) { if (url) { img = new Image(); img.crossOrigin='anonymous'; img.src = url; VE.imageCache[url] = img; } }
      if (img && img.complete && img.naturalWidth) ctx.drawImage(img, x, y, w, h);
    } else if (typ === 'lower_third') {
      var c = el.content || {};
      ctx.fillStyle = c.bar_color || 'rgba(0,0,0,.75)';
      ctx.fillRect(x, y, w, h);
      ctx.fillStyle = c.color || c.accent_color || '#6C5CE7';
      ctx.fillRect(x, y, 5, h);
      ctx.fillStyle = c.text_color || '#FFFFFF';
      ctx.font = 'bold 28px "DM Sans", sans-serif'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
      ctx.fillText(c.name || '', x + 16, y + h * 0.35);
      ctx.fillStyle = c.color || c.accent_color || '#A78BFA';
      ctx.font = '18px "DM Sans", sans-serif';
      ctx.fillText(c.title || '', x + 16, y + h * 0.72);
    } else if (typ === 'badge') {
      var bc = el.content || {};
      var r = Math.min(h / 2, 20);
      ctx.fillStyle = bc.bg_color || '#6C5CE7';
      if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(x, y, w, h, r); ctx.fill(); } else ctx.fillRect(x, y, w, h);
      ctx.fillStyle = bc.text_color || '#FFFFFF';
      ctx.font = 'bold 18px "DM Sans", sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(bc.text || '', x + w / 2, y + h / 2);
    } else if (typ === 'countdown') {
      var cc = el.content || {};
      var numbers = ['3','2','1', cc.end_text || 'GO!'];
      var elapsed = t - (el.start_time || 0);
      var idx = Math.min(Math.floor(elapsed), numbers.length - 1);
      if (idx < 0) idx = 0;
      var num = numbers[idx];
      ctx.fillStyle = cc.color || '#FFFFFF';
      ctx.font = 'bold ' + ((cc.font_size || 180)) + 'px "Unbounded", "DM Sans", sans-serif';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(num, x + w / 2, y + h / 2);
    } else if (typ === 'progress_bar') {
      var dur = el.end_time ? (el.end_time - (el.start_time || 0)) : 5;
      var pct = Math.min(1, (t - (el.start_time || 0)) / Math.max(0.001, dur));
      ctx.fillStyle = 'rgba(255,255,255,.15)'; ctx.fillRect(x, y, w, h);
      ctx.fillStyle = (el.content && el.content.fill_color) || '#6C5CE7';
      ctx.fillRect(x, y, w * pct, h);
    }
    ctx.restore();
  }

  // ── CONTINUED IN PART 4 ──

  // ═══════════════════════════════════════════════════════════════
  // PART 4 — Timeline, playhead, trim, left panel tabs, right panel
  // ═══════════════════════════════════════════════════════════════

  function _svRenderTimeline(){
    var ruler = document.getElementById('sv-tl-ruler');
    var clips = document.getElementById('sv-track-clips');
    var text  = document.getElementById('sv-track-text');
    var els   = document.getElementById('sv-track-elements');
    var aud   = document.getElementById('sv-track-audio');
    var inner = document.getElementById('sv-tl-inner');
    if (!ruler || !clips || !inner) return;
    var total = _svTotalDuration();
    var pxPerSecond = 50 * VE.tlZoom;
    var width = Math.max(800, total * pxPerSecond + 40);
    inner.style.width = width + 'px';

    // Ruler ticks
    ruler.innerHTML = '';
    for (var s = 0; s <= Math.ceil(total); s++) {
      var tick = document.createElement('div');
      tick.className = 'sv-tl-tick' + (s % 5 === 0 ? ' major' : '');
      tick.style.left = (s * pxPerSecond) + 'px';
      if (s % 5 === 0) tick.textContent = s + 's';
      ruler.appendChild(tick);
    }

    // Clips
    clips.innerHTML = '';
    (VE.vd.clips || []).forEach(function(c){
      var el = _svMakeBlock(c, 'clip');
      el.style.left  = (c.start_time * pxPerSecond) + 'px';
      el.style.width = (c.duration   * pxPerSecond) + 'px';
      clips.appendChild(el);
    });
    text.innerHTML = '';
    (VE.vd.text_overlays || []).forEach(function(ov){
      var el = _svMakeBlock(ov, 'text');
      var dur = ov.end_time - ov.start_time;
      el.style.left  = (ov.start_time * pxPerSecond) + 'px';
      el.style.width = (dur           * pxPerSecond) + 'px';
      text.appendChild(el);
    });
    els.innerHTML = '';
    (VE.vd.elements || []).forEach(function(ee){
      var el = _svMakeBlock(ee, 'element');
      var dur = (ee.end_time || total) - (ee.start_time || 0);
      el.style.left  = ((ee.start_time || 0) * pxPerSecond) + 'px';
      el.style.width = (dur * pxPerSecond) + 'px';
      els.appendChild(el);
    });
    // Audio
    aud.innerHTML = '';
    if (VE.vd.audio && VE.vd.audio.url) {
      var a = document.createElement('div');
      a.className = 'sv-tl-block audio';
      a.style.left = '0px'; a.style.width = (total * pxPerSecond) + 'px';
      a.innerHTML = '<span>' + _svEsc(VE.vd.audio.name || 'audio') + '</span>';
      aud.appendChild(a);
    }

    _svUpdatePlayheadUI();
    _svWirePlayheadScrub();
    _svHighlightSelection();
  }

  function _svMakeBlock(item, kind){
    var el = document.createElement('div');
    el.className = 'sv-tl-block ' + kind;
    el.setAttribute('data-kind', kind);
    el.setAttribute('data-id', item.id);
    var label = kind === 'clip' ? (item.type || 'clip')
              : kind === 'text' ? ((item.content || '').substring(0, 24) || 'text')
              : (item.type || 'element');
    el.innerHTML =
      '<span class="sv-tl-trim l" data-trim="l"></span>' +
      '<span class="sv-tl-label">' + _svEsc(label) + '</span>' +
      '<span class="sv-tl-trim r" data-trim="r"></span>';

    el.addEventListener('mousedown', function(e){
      if (e.target.classList.contains('sv-tl-trim')) return _svStartTrim(e, item, kind, e.target.getAttribute('data-trim'));
      _svSelect(kind, item.id);
      _svStartBlockDrag(e, item, kind);
    });
    return el;
  }

  function _svUpdatePlayheadUI(){
    var total = _svTotalDuration();
    var pxPerSecond = 50 * VE.tlZoom;
    var head = document.getElementById('sv-tl-playhead');
    if (head) head.style.left = (VE.playhead * pxPerSecond) + 'px';
    var tl = document.getElementById('sv-time-label');
    if (tl) tl.textContent = VE.playhead.toFixed(1) + 's / ' + total.toFixed(1) + 's';
    var dl = document.getElementById('sv-duration-label');
    if (dl) dl.textContent = VE.playhead.toFixed(1) + 's / ' + total.toFixed(1) + 's';
  }

  function _svWirePlayheadScrub(){
    var ruler = document.getElementById('sv-tl-ruler');
    if (!ruler || ruler._bound) return;
    ruler._bound = true;
    ruler.addEventListener('mousedown', function(e){
      VE.scrubbing = true; _svSeekTo(e);
      document.addEventListener('mousemove', _svScrubMove);
      document.addEventListener('mouseup', _svScrubEnd);
    });
  }
  function _svScrubMove(e){ if (VE.scrubbing) _svSeekTo(e); }
  function _svScrubEnd(){
    VE.scrubbing = false;
    document.removeEventListener('mousemove', _svScrubMove);
    document.removeEventListener('mouseup', _svScrubEnd);
  }
  function _svSeekTo(e){
    var ruler = document.getElementById('sv-tl-ruler');
    var r = ruler.getBoundingClientRect();
    var pxPerSecond = 50 * VE.tlZoom;
    var sec = Math.max(0, Math.min(_svTotalDuration(), (e.clientX - r.left) / pxPerSecond));
    VE.playhead = sec;
    _svDrawFrame(sec); _svUpdatePlayheadUI();
  }

  // ── Trim handles (with ripple on right edge) ─────────────
  function _svStartTrim(e, item, kind, side){
    e.preventDefault(); e.stopPropagation();
    var pxPerSecond = 50 * VE.tlZoom;
    VE.trimState = {
      item: item, kind: kind, side: side,
      startClient: e.clientX,
      initial: { start_time: item.start_time, end_time: item.end_time, duration: item.end_time - item.start_time },
    };
    document.addEventListener('mousemove', _svOnTrim);
    document.addEventListener('mouseup', _svOnTrimEnd);
  }
  function _svOnTrim(e){
    var s = VE.trimState; if (!s) return;
    var pxPerSecond = 50 * VE.tlZoom;
    var dSec = (e.clientX - s.startClient) / pxPerSecond;
    if (s.side === 'l') {
      var newStart = Math.max(0, s.initial.start_time + dSec);
      if (newStart < s.initial.end_time - 0.3) {
        s.item.start_time = newStart;
        s.item.duration   = s.item.end_time - s.item.start_time;
        if (s.item.trim_start != null) s.item.trim_start = Math.max(0, (s.initial.trim_start || 0) + dSec);
      }
    } else {
      var newEnd = Math.max(s.initial.start_time + 0.3, s.initial.end_time + dSec);
      var delta = newEnd - s.item.end_time;
      s.item.end_time = newEnd;
      s.item.duration = s.item.end_time - s.item.start_time;
      // Ripple: shift clips starting at/after the ORIGINAL end_time by delta
      if (s.kind === 'clip') {
        (VE.vd.clips || []).forEach(function(other){
          if (other.id !== s.item.id && other.start_time >= s.initial.end_time) {
            other.start_time += delta; other.end_time += delta;
          }
        });
        var total = _svTotalDuration();
        if (total > MAX_DURATION) {
          // clamp last clip
          var lastClip = (VE.vd.clips || []).slice().sort(function(a,b){ return a.end_time - b.end_time; }).pop();
          if (lastClip) lastClip.end_time -= (total - MAX_DURATION);
          _svToast('60s limit reached', 'warning');
        }
      }
    }
    _svRenderTimeline(); _svDrawFrame(VE.playhead); _svMarkDirty();
  }
  function _svOnTrimEnd(){
    document.removeEventListener('mousemove', _svOnTrim);
    document.removeEventListener('mouseup', _svOnTrimEnd);
    if (VE.trimState) { _svSaveHistory(); _svAutoSaveSoon(); }
    VE.trimState = null;
  }

  // ── Drag block to reorder ─────────────────────────────────
  function _svStartBlockDrag(e, item, kind){
    e.preventDefault();
    VE.dragState = { item: item, kind: kind, startClient: e.clientX, initialStart: item.start_time, moved: false };
    document.addEventListener('mousemove', _svOnBlockDrag);
    document.addEventListener('mouseup', _svOnBlockDragEnd);
  }
  function _svOnBlockDrag(e){
    var s = VE.dragState; if (!s) return;
    var pxPerSecond = 50 * VE.tlZoom;
    var dSec = (e.clientX - s.startClient) / pxPerSecond;
    var dur = s.item.end_time - s.item.start_time;
    var newStart = Math.max(0, s.initialStart + dSec);
    if (newStart + dur > MAX_DURATION) newStart = MAX_DURATION - dur;
    s.item.start_time = newStart;
    s.item.end_time   = newStart + dur;
    s.moved = true;
    _svRenderTimeline(); _svDrawFrame(VE.playhead); _svMarkDirty();
  }
  function _svOnBlockDragEnd(){
    document.removeEventListener('mousemove', _svOnBlockDrag);
    document.removeEventListener('mouseup', _svOnBlockDragEnd);
    if (VE.dragState && VE.dragState.moved) { _svSaveHistory(); _svAutoSaveSoon(); }
    VE.dragState = null;
  }

  // ── Selection ─────────────────────────────────────────────
  function _svSelect(kind, id){
    VE.selected = { kind: kind, id: id };
    _svHighlightSelection();
    _svRenderProps();
    VE.needsRedraw = true;
  }
  function _svHighlightSelection(){
    document.querySelectorAll('.sv-tl-block').forEach(function(n){ n.classList.remove('selected'); });
    if (!VE.selected) return;
    var el = document.querySelector('.sv-tl-block[data-kind="' + VE.selected.kind + '"][data-id="' + VE.selected.id + '"]');
    if (el) el.classList.add('selected');
  }

  // ── LEFT PANEL TABS ───────────────────────────────────────
  function _svWireLeftTabs(){
    VE.root.querySelectorAll('.sv-tab').forEach(function(t){
      t.onclick = function(){
        VE.root.querySelectorAll('.sv-tab').forEach(function(x){ x.classList.remove('active'); });
        t.classList.add('active');
        VE.tabActive = t.getAttribute('data-tab');
        _svRenderLeftPanel(VE.tabActive);
      };
    });
  }

  function _svRenderLeftPanel(tab){
    var h = document.getElementById('sv-tab-body'); if (!h) return;
    if (tab === 'clips')       return _svTabClips(h);
    if (tab === 'text')        return _svTabText(h);
    if (tab === 'elements')    return _svTabElements(h);
    if (tab === 'transitions') return _svTabTransitions(h);
    if (tab === 'filters')     return _svTabFilters(h);
    if (tab === 'audio')       return _svTabAudio(h);
    if (tab === 'ai')          return _svTabAi(h);
  }

  function _svTabClips(h){
    h.innerHTML =
      '<button class="sv-btn-wide" id="sv-upload-video">Upload video</button>' +
      '<button class="sv-btn-wide" id="sv-upload-image">Upload images</button>' +
      '<button class="sv-btn-wide" id="sv-upload-slideshow">+ Slideshow from images</button>' +
      '<div class="sv-section-label">Clips in this design</div>' +
      '<div id="sv-my-clips" class="sv-my-clips"></div>';
    var list = document.getElementById('sv-my-clips');
    list.innerHTML = (VE.vd.clips || []).map(function(c, i){
      var t = c.type || 'placeholder';
      var title = c.label || t;
      var dur = (c.duration || 0).toFixed(1) + 's';
      return '<div class="sv-clip-thumb" data-id="' + _svEsc(c.id) + '" data-kind="clip">' +
               '<div class="th">' + (t === 'video' ? '\u25B6' : t === 'image' ? '\u2B50' : '\u25A2') + '</div>' +
               '<div class="meta"><div class="nm">' + _svEsc(title) + '</div><div class="dur">' + dur + '</div></div>' +
             '</div>';
    }).join('') || '<div class="sv-empty">No clips yet.</div>';
    list.querySelectorAll('.sv-clip-thumb').forEach(function(n){
      n.onclick = function(){ _svSelect('clip', n.getAttribute('data-id')); };
    });
    document.getElementById('sv-upload-video').onclick = function(){
      if (typeof window.openMediaPicker === 'function') {
        window.openMediaPicker({ type:'video' }, function(file){ if (file && file.url) _svAddClip('video', file.url, file.duration_seconds); });
      } else { _svToast('Media picker unavailable', 'error'); }
    };
    document.getElementById('sv-upload-image').onclick = function(){
      if (typeof window.openMediaPicker === 'function') {
        window.openMediaPicker({ type:'image' }, function(file){ if (file && file.url) _svAddClip('image', file.url, 3); });
      }
    };
    document.getElementById('sv-upload-slideshow').onclick = function(){
      if (typeof window.openMediaPicker === 'function') {
        window.openMediaPicker({ type:'image', multiple:true }, function(files){
          (files || []).forEach(function(file, i){ _svAddClip('image', file.url, 3, { ken_burns: Object.assign({ enabled: true }, _svKenBurnsPresetByIndex(i)) }); });
        });
      }
    };
  }

  function _svKenBurnsPresetByIndex(i){
    var keys = ['zoom_in_right','zoom_in_left','zoom_out_right','zoom_out_left'];
    return KEN_BURNS_PRESETS[keys[i % keys.length]];
  }

  function _svAddClip(type, url, dur, extra){
    var total = _svTotalDuration();
    var newStart = total;
    dur = Number(dur) || 3;
    if (newStart + dur > MAX_DURATION) { _svToast('60s limit', 'warning'); return; }
    var clip = Object.assign({
      id: 'clip_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
      type: type, source_url: url,
      start_time: newStart, end_time: newStart + dur, duration: dur,
      transition_in: { type:'fade', duration: 0.4 },
    }, extra || {});
    VE.vd.clips.push(clip);
    VE.vd.duration = Math.min(MAX_DURATION, newStart + dur);
    if (type === 'video') { var v = document.createElement('video'); v.src = url; v.muted = true; v.preload='auto'; v.crossOrigin='anonymous'; v.playsInline=true; VE.videoPool[clip.id] = v; v.load(); }
    if (type === 'image') { var img = new Image(); img.crossOrigin='anonymous'; img.src = url; VE.imageCache[url] = img; img.onload = function(){ VE.needsRedraw = true; }; }
    _svSaveHistory(); _svMarkDirty(); _svAutoSaveSoon();
    _svRenderTimeline(); _svRenderLeftPanel('clips'); _svDrawFrame(VE.playhead);
  }

  function _svTabText(h){
    h.innerHTML =
      '<button class="sv-btn-wide" id="sv-add-text">+ Add text</button>' +
      '<div class="sv-section-label">Overlays</div>' +
      '<div id="sv-text-list"></div>';
    document.getElementById('sv-add-text').onclick = function(){
      var total = _svTotalDuration();
      var t = {
        id: 'text_' + Date.now(),
        content: 'Your text',
        font_family: 'DM Sans', font_size: 72, font_weight: '700', color: '#FFFFFF',
        anchor: 'center',
        position: { x: VE.vd.canvas_width / 2, y: VE.vd.canvas_height / 2 },
        start_time: VE.playhead, end_time: Math.min(MAX_DURATION, VE.playhead + 3),
        animation_in: { type: 'fade', duration: 0.4 },
      };
      VE.vd.text_overlays.push(t);
      _svSaveHistory(); _svMarkDirty(); _svAutoSaveSoon();
      _svSelect('text', t.id); _svRenderTimeline(); _svRenderLeftPanel('text'); _svDrawFrame(VE.playhead);
    };
    var list = document.getElementById('sv-text-list');
    list.innerHTML = (VE.vd.text_overlays || []).map(function(t){
      return '<div class="sv-clip-thumb" data-id="' + _svEsc(t.id) + '" data-kind="text">' +
               '<div class="th">T</div>' +
               '<div class="meta"><div class="nm">' + _svEsc((t.content||'').substring(0,30)) + '</div><div class="dur">' + (t.start_time||0).toFixed(1) + '-' + (t.end_time||0).toFixed(1) + 's</div></div>' +
             '</div>';
    }).join('') || '<div class="sv-empty">No text yet.</div>';
    list.querySelectorAll('.sv-clip-thumb').forEach(function(n){ n.onclick = function(){ _svSelect('text', n.getAttribute('data-id')); }; });
  }

  function _svTabElements(h){
    h.innerHTML =
      '<button class="sv-btn-wide" data-el="logo">+ Logo</button>' +
      '<button class="sv-btn-wide" data-el="lower_third">+ Lower Third</button>' +
      '<button class="sv-btn-wide" data-el="badge">+ Badge</button>' +
      '<button class="sv-btn-wide" data-el="countdown">+ Countdown</button>' +
      '<button class="sv-btn-wide" data-el="progress_bar">+ Progress Bar</button>' +
      '<div class="sv-section-label">Elements on timeline</div>' +
      '<div id="sv-el-list"></div>';
    h.querySelectorAll('[data-el]').forEach(function(b){
      b.onclick = function(){ _svAddDefaultElement(b.getAttribute('data-el')); };
    });
    var list = document.getElementById('sv-el-list');
    list.innerHTML = (VE.vd.elements || []).map(function(e){
      return '<div class="sv-clip-thumb" data-id="' + _svEsc(e.id) + '" data-kind="element"><div class="th">E</div><div class="meta"><div class="nm">' + _svEsc(e.type) + '</div><div class="dur">' + (e.start_time||0).toFixed(1) + '-' + (e.end_time||0).toFixed(1) + 's</div></div></div>';
    }).join('') || '<div class="sv-empty">No elements yet.</div>';
    list.querySelectorAll('.sv-clip-thumb').forEach(function(n){ n.onclick = function(){ _svSelect('element', n.getAttribute('data-id')); }; });
  }

  function _svAddDefaultElement(type){
    var H = VE.vd.canvas_height;
    var content = type === 'logo'         ? { logo_url: (VE.brand && VE.brand.logo_url) || '' }
                : type === 'lower_third'  ? { name: 'Your Name', title: 'Your title', bar_color:'rgba(0,0,0,.78)', text_color:'#fff', accent_color:'#6C5CE7' }
                : type === 'badge'        ? { text: 'NEW', bg_color:'#6C5CE7', text_color:'#fff' }
                : type === 'countdown'    ? { end_text:'GO!', color:'#fff', font_size: 220 }
                :                           { fill_color:'#6C5CE7' };
    var size = type === 'logo' ? { width: 240, height: 96 }
             : type === 'lower_third' ? { width: VE.vd.canvas_width - 160, height: 140 }
             : type === 'badge' ? { width: 220, height: 60 }
             : type === 'countdown' ? { width: 400, height: 400 }
             :                        { width: VE.vd.canvas_width - 120, height: 12 };
    var pos = type === 'lower_third' ? { x: 80, y: H - 240 }
            : type === 'countdown'   ? { x: (VE.vd.canvas_width - size.width)/2, y: (H - size.height)/2 }
            :                          { x: 80, y: 80 };
    var el = {
      id: 'el_' + Date.now(),
      type: type, content: content, size: size, position: pos,
      start_time: VE.playhead, end_time: Math.min(MAX_DURATION, VE.playhead + (type === 'countdown' ? 4 : 5)),
      animation_in: { type:'slide_left', duration: 0.5 },
    };
    VE.vd.elements.push(el);
    _svSaveHistory(); _svMarkDirty(); _svAutoSaveSoon();
    _svSelect('element', el.id); _svRenderTimeline(); _svRenderLeftPanel('elements'); _svDrawFrame(VE.playhead);
  }

  function _svTabTransitions(h){
    h.innerHTML = '<div class="sv-section-label">Default transition</div>' +
      '<div class="sv-trans-grid">' +
        TRANSITION_TYPES.map(function(t){
          return '<button class="sv-trans-btn" data-trans="' + t + '">' + t.replace('_', ' ') + '</button>';
        }).join('') +
      '</div>' +
      '<div class="sv-section-label">Duration</div>' +
      '<div class="sv-dur-row">' +
        [0.3, 0.5, 0.8, 1.0].map(function(d){ return '<button class="sv-dur-btn" data-dur="' + d + '">' + d + 's</button>'; }).join('') +
      '</div>';
    h.querySelectorAll('.sv-trans-btn').forEach(function(b){
      b.onclick = function(){
        var t = b.getAttribute('data-trans');
        (VE.vd.clips || []).forEach(function(c, i){ if (i > 0) c.transition_in = { type: t, duration: (c.transition_in && c.transition_in.duration) || 0.4 }; });
        _svSaveHistory(); _svMarkDirty(); _svAutoSaveSoon();
        _svRenderTimeline(); _svDrawFrame(VE.playhead);
        _svToast('Applied ' + t + ' between all clips');
      };
    });
    h.querySelectorAll('.sv-dur-btn').forEach(function(b){
      b.onclick = function(){
        var d = parseFloat(b.getAttribute('data-dur'));
        (VE.vd.clips || []).forEach(function(c, i){ if (i > 0 && c.transition_in) c.transition_in.duration = d; });
        _svSaveHistory(); _svMarkDirty(); _svAutoSaveSoon();
        _svToast('Transition duration ' + d + 's');
      };
    });
  }

  function _svTabFilters(h){
    h.innerHTML =
      '<div class="sv-section-label">Global filter</div>' +
      '<div class="sv-filter-grid">' +
        Object.keys(FILTERS).map(function(f){
          return '<button class="sv-filter-btn' + (VE.vd.global_filter === f ? ' active' : '') + '" data-f="' + f + '">' + f + '</button>';
        }).join('') +
      '</div>';
    h.querySelectorAll('.sv-filter-btn').forEach(function(b){
      b.onclick = function(){
        var f = b.getAttribute('data-f');
        _svSaveHistory();
        VE.vd.global_filter = f;
        _svRenderLeftPanel('filters');
        _svMarkDirty(); _svAutoSaveSoon();
        _svDrawFrame(VE.playhead);
      };
    });
  }

  function _svTabAudio(h){
    var a = VE.vd.audio || {};
    h.innerHTML =
      '<button class="sv-btn-wide" id="sv-upload-audio">Upload audio (MP3/AAC/WAV)</button>' +
      (a.url ? '<div class="sv-audio-info">' +
        '<div><strong>' + _svEsc(a.name || 'audio') + '</strong></div>' +
        '<div class="sv-audio-waveform"></div>' +
        '<div class="sv-props-row"><label>Volume</label><input type="range" min="0" max="1" step="0.05" value="' + (a.volume != null ? a.volume : 1) + '" id="sv-aud-vol"/></div>' +
        '<div class="sv-props-row"><label>Fade in (s)</label><input type="number" step="0.1" value="' + (a.fade_in || 0) + '" id="sv-aud-fi"/></div>' +
        '<div class="sv-props-row"><label>Fade out (s)</label><input type="number" step="0.1" value="' + (a.fade_out || 0) + '" id="sv-aud-fo"/></div>' +
        '<button class="sv-btn-wide sv-btn-danger" id="sv-aud-remove">Remove audio</button>' +
      '</div>' : '<div class="sv-empty">No audio yet.</div>');
    document.getElementById('sv-upload-audio').onclick = function(){
      if (typeof window.openMediaPicker === 'function') {
        window.openMediaPicker({ type:'audio' }, function(file){
          if (file && file.url) {
            VE.vd.audio = { url: file.url, name: file.name || 'audio', volume: 1, fade_in: 0, fade_out: 0, duration: file.duration_seconds || 0 };
            _svSaveHistory(); _svMarkDirty(); _svAutoSaveSoon();
            _svRenderLeftPanel('audio'); _svRenderTimeline();
          }
        });
      }
    };
    if (a.url) {
      document.getElementById('sv-aud-vol').oninput = function(e){ VE.vd.audio.volume = parseFloat(e.target.value); _svMarkDirty(); _svAutoSaveSoon(); };
      document.getElementById('sv-aud-fi').oninput  = function(e){ VE.vd.audio.fade_in = parseFloat(e.target.value); _svMarkDirty(); _svAutoSaveSoon(); };
      document.getElementById('sv-aud-fo').oninput  = function(e){ VE.vd.audio.fade_out= parseFloat(e.target.value); _svMarkDirty(); _svAutoSaveSoon(); };
      document.getElementById('sv-aud-remove').onclick = function(){
        _svSaveHistory(); VE.vd.audio = null; _svMarkDirty(); _svAutoSaveSoon();
        _svRenderLeftPanel('audio'); _svRenderTimeline();
      };
    }
  }

  function _svTabAi(h){
    h.innerHTML =
      '<div class="sv-empty">MiniMax / Arthur AI video generation ships in Phase 4.</div>' +
      '<textarea rows="3" class="sv-ai-prompt" placeholder="e.g. cinematic shot of a sunrise over mountains..." disabled></textarea>' +
      '<button class="sv-btn-wide" disabled>\u2726 Generate Video (Phase 4)</button>';
  }

  // ── CONTINUED IN PART 5 ──

  // ═══════════════════════════════════════════════════════════════
  // PART 5 — Right panel, history, save, export, playback wiring, CSS
  // ═══════════════════════════════════════════════════════════════

  function _svRenderProps(){
    var h = document.getElementById('sv-props'); if (!h) return;
    if (!VE.selected) { h.innerHTML = _svPropsCanvas(); _svWirePropsCanvas(); return; }
    if (VE.selected.kind === 'clip')    return _svPropsClip(h);
    if (VE.selected.kind === 'text')    return _svPropsText(h);
    if (VE.selected.kind === 'element') return _svPropsElement(h);
  }

  function _svPropsCanvas(){
    return (
      '<div class="sv-props-group">' +
        '<div class="sv-props-title">Canvas</div>' +
        '<div class="sv-props-row"><label>Size</label><span>' + VE.vd.canvas_width + '\u00d7' + VE.vd.canvas_height + '</span></div>' +
        '<div class="sv-props-row"><label>Duration</label><input type="number" step="0.1" id="sv-p-dur" value="' + VE.vd.duration.toFixed(1) + '"/></div>' +
        '<div class="sv-props-row"><label>FPS</label><span>' + VE.vd.fps + '</span></div>' +
        '<div class="sv-props-row"><label>BG color</label><input type="color" id="sv-p-bg" value="' + _svEsc(VE.vd.background_color) + '"/></div>' +
        '<div class="sv-props-row"><label>Filter</label><select id="sv-p-filter">' +
          Object.keys(FILTERS).map(function(f){ return '<option value="' + f + '"' + (VE.vd.global_filter === f ? ' selected' : '') + '>' + f + '</option>'; }).join('') +
        '</select></div>' +
      '</div>'
    );
  }
  function _svWirePropsCanvas(){
    var dur = document.getElementById('sv-p-dur');
    if (dur) dur.oninput = function(e){ var d = parseFloat(e.target.value) || 1; VE.vd.duration = Math.min(MAX_DURATION, d); _svMarkDirty(); _svAutoSaveSoon(); _svRenderTimeline(); };
    var bg  = document.getElementById('sv-p-bg');
    if (bg)  bg.oninput  = function(e){ VE.vd.background_color = e.target.value; _svMarkDirty(); _svAutoSaveSoon(); _svDrawFrame(VE.playhead); };
    var f   = document.getElementById('sv-p-filter');
    if (f)   f.onchange  = function(e){ VE.vd.global_filter = e.target.value; _svMarkDirty(); _svAutoSaveSoon(); _svDrawFrame(VE.playhead); };
  }

  function _svPropsClip(h){
    var c = (VE.vd.clips || []).find(function(x){ return x.id === VE.selected.id; });
    if (!c) { h.innerHTML = '<div class="sv-hint">Clip not found</div>'; return; }
    var kb = c.ken_burns || { enabled:false };
    h.innerHTML =
      '<div class="sv-props-group">' +
        '<div class="sv-props-title">Clip</div>' +
        '<div class="sv-props-row"><label>Type</label><span>' + _svEsc(c.type) + '</span></div>' +
        '<div class="sv-props-row"><label>Start</label><input type="number" step="0.1" data-k="start_time" value="' + (c.start_time || 0).toFixed(1) + '"/></div>' +
        '<div class="sv-props-row"><label>Duration</label><input type="number" step="0.1" data-k="duration" value="' + (c.duration || 0).toFixed(1) + '"/></div>' +
        '<div class="sv-props-row"><label>Filter</label><select data-k="filter">' +
          Object.keys(FILTERS).map(function(f){ return '<option value="' + f + '"' + (c.filter === f ? ' selected' : '') + '>' + f + '</option>'; }).join('') +
        '</select></div>' +
      '</div>' +
      '<div class="sv-props-group">' +
        '<div class="sv-props-title">Ken Burns</div>' +
        '<div class="sv-props-row"><label>Enabled</label><input type="checkbox" id="sv-kb-on"' + (kb.enabled ? ' checked' : '') + '/></div>' +
        '<div class="sv-kb-row">' +
          ['zoom_in_right','zoom_in_left','zoom_out_right','zoom_out_left','static'].map(function(p){
            return '<button class="sv-kb-btn" data-kb="' + p + '">' + p.replace(/_/g,' ') + '</button>';
          }).join('') +
        '</div>' +
      '</div>' +
      '<div class="sv-props-group">' +
        '<div class="sv-props-title">Transition IN</div>' +
        '<div class="sv-props-row"><label>Type</label><select data-tr="type">' +
          TRANSITION_TYPES.map(function(tp){ return '<option value="' + tp + '"' + ((c.transition_in && c.transition_in.type) === tp ? ' selected' : '') + '>' + tp + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="sv-props-row"><label>Duration</label><input type="number" step="0.1" data-tr="duration" value="' + ((c.transition_in && c.transition_in.duration) || 0.4) + '"/></div>' +
      '</div>' +
      '<button class="sv-btn-wide sv-btn-danger" id="sv-clip-del">Delete clip</button>';

    // Wire
    h.querySelectorAll('[data-k]').forEach(function(inp){
      var k = inp.getAttribute('data-k');
      inp.oninput = function(){
        var v = inp.type === 'number' ? parseFloat(inp.value) : inp.value;
        if (k === 'start_time')    { c.start_time = Math.max(0, v); c.end_time = c.start_time + (c.duration || 0); }
        else if (k === 'duration') { c.duration = Math.max(0.3, v); c.end_time = c.start_time + c.duration; }
        else c[k] = v;
        _svMarkDirty(); _svAutoSaveSoon(); _svRenderTimeline(); _svDrawFrame(VE.playhead);
      };
    });
    var kbOn = document.getElementById('sv-kb-on');
    kbOn.onchange = function(){ c.ken_burns = c.ken_burns || {}; c.ken_burns.enabled = kbOn.checked; _svMarkDirty(); _svAutoSaveSoon(); _svDrawFrame(VE.playhead); };
    h.querySelectorAll('.sv-kb-btn').forEach(function(b){
      b.onclick = function(){ c.ken_burns = Object.assign({ enabled: true }, KEN_BURNS_PRESETS[b.getAttribute('data-kb')] || {}); kbOn.checked = true; _svMarkDirty(); _svAutoSaveSoon(); _svDrawFrame(VE.playhead); };
    });
    h.querySelectorAll('[data-tr]').forEach(function(inp){
      var k = inp.getAttribute('data-tr');
      inp.oninput = inp.onchange = function(){
        c.transition_in = c.transition_in || {};
        c.transition_in[k] = inp.type === 'number' ? parseFloat(inp.value) : inp.value;
        _svMarkDirty(); _svAutoSaveSoon(); _svDrawFrame(VE.playhead);
      };
    });
    document.getElementById('sv-clip-del').onclick = function(){
      _svSaveHistory();
      VE.vd.clips = VE.vd.clips.filter(function(x){ return x.id !== c.id; });
      delete VE.videoPool[c.id];
      VE.selected = null; _svMarkDirty(); _svAutoSaveSoon();
      _svRenderTimeline(); _svRenderLeftPanel(VE.tabActive); _svRenderProps(); _svDrawFrame(VE.playhead);
    };
  }

  function _svPropsText(h){
    var t = (VE.vd.text_overlays || []).find(function(x){ return x.id === VE.selected.id; });
    if (!t) { h.innerHTML = '<div class="sv-hint">Text not found</div>'; return; }
    h.innerHTML =
      '<div class="sv-props-group">' +
        '<div class="sv-props-title">Text</div>' +
        '<textarea data-k="content" rows="3" class="sv-textarea">' + _svEsc(t.content || '') + '</textarea>' +
        '<div class="sv-props-row"><label>Font</label><input data-k="font_family" value="' + _svEsc(t.font_family || 'DM Sans') + '"/></div>' +
        '<div class="sv-props-row"><label>Size</label><input type="number" data-k="font_size" value="' + (t.font_size || 48) + '"/></div>' +
        '<div class="sv-props-row"><label>Weight</label><select data-k="font_weight">' +
          ['300','400','500','600','700','800','900'].map(function(w){ return '<option value="' + w + '"' + (String(t.font_weight) === w ? ' selected' : '') + '>' + w + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="sv-props-row"><label>Color</label><input type="color" data-k="color" value="' + _svEsc(t.color || '#FFFFFF') + '"/></div>' +
        '<div class="sv-props-row"><label>Anchor</label><select data-k="anchor">' +
          ['left','center','right'].map(function(a){ return '<option value="' + a + '"' + (t.anchor === a ? ' selected' : '') + '>' + a + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="sv-props-row"><label>X</label><input type="number" data-pos="x" value="' + (t.position ? t.position.x : 0) + '"/><label>Y</label><input type="number" data-pos="y" value="' + (t.position ? t.position.y : 0) + '"/></div>' +
        '<div class="sv-props-row"><label>Start</label><input type="number" step="0.1" data-k="start_time" value="' + (t.start_time || 0).toFixed(1) + '"/><label>End</label><input type="number" step="0.1" data-k="end_time" value="' + (t.end_time || 0).toFixed(1) + '"/></div>' +
      '</div>' +
      '<div class="sv-props-group">' +
        '<div class="sv-props-title">Animation IN</div>' +
        '<div class="sv-props-row"><label>Type</label><select data-anim="type">' +
          TEXT_ANIM_TYPES.map(function(a){ return '<option value="' + a + '"' + ((t.animation_in && t.animation_in.type) === a ? ' selected' : '') + '>' + a + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="sv-props-row"><label>Duration</label><input type="number" step="0.1" data-anim="duration" value="' + ((t.animation_in && t.animation_in.duration) || 0.4) + '"/></div>' +
      '</div>' +
      '<button class="sv-btn-wide sv-btn-danger" id="sv-text-del">Delete text</button>';

    h.querySelectorAll('[data-k]').forEach(function(inp){
      var k = inp.getAttribute('data-k');
      inp.oninput = function(){
        var v = inp.type === 'number' ? parseFloat(inp.value) : inp.value;
        t[k] = v;
        _svMarkDirty(); _svAutoSaveSoon(); _svRenderTimeline(); _svDrawFrame(VE.playhead);
      };
    });
    h.querySelectorAll('[data-pos]').forEach(function(inp){
      var p = inp.getAttribute('data-pos');
      inp.oninput = function(){ t.position = t.position || {}; t.position[p] = parseFloat(inp.value); _svMarkDirty(); _svAutoSaveSoon(); _svDrawFrame(VE.playhead); };
    });
    h.querySelectorAll('[data-anim]').forEach(function(inp){
      var p = inp.getAttribute('data-anim');
      inp.oninput = inp.onchange = function(){ t.animation_in = t.animation_in || {}; t.animation_in[p] = inp.type === 'number' ? parseFloat(inp.value) : inp.value; _svMarkDirty(); _svAutoSaveSoon(); _svDrawFrame(VE.playhead); };
    });
    document.getElementById('sv-text-del').onclick = function(){
      _svSaveHistory();
      VE.vd.text_overlays = VE.vd.text_overlays.filter(function(x){ return x.id !== t.id; });
      VE.selected = null; _svMarkDirty(); _svAutoSaveSoon();
      _svRenderTimeline(); _svRenderLeftPanel(VE.tabActive); _svRenderProps(); _svDrawFrame(VE.playhead);
    };
  }

  function _svPropsElement(h){
    var e = (VE.vd.elements || []).find(function(x){ return x.id === VE.selected.id; });
    if (!e) { h.innerHTML = '<div class="sv-hint">Element not found</div>'; return; }
    var c = e.content || {};
    var fields = '';
    if (e.type === 'lower_third') {
      fields = '<div class="sv-props-row"><label>Name</label><input data-c="name" value="' + _svEsc(c.name||'') + '"/></div>' +
               '<div class="sv-props-row"><label>Title</label><input data-c="title" value="' + _svEsc(c.title||'') + '"/></div>' +
               '<div class="sv-props-row"><label>Accent</label><input type="color" data-c="accent_color" value="' + _svEsc(c.accent_color||'#6C5CE7') + '"/></div>';
    } else if (e.type === 'badge') {
      fields = '<div class="sv-props-row"><label>Text</label><input data-c="text" value="' + _svEsc(c.text||'') + '"/></div>' +
               '<div class="sv-props-row"><label>BG</label><input type="color" data-c="bg_color" value="' + _svEsc(c.bg_color||'#6C5CE7') + '"/></div>';
    } else if (e.type === 'countdown') {
      fields = '<div class="sv-props-row"><label>End text</label><input data-c="end_text" value="' + _svEsc(c.end_text||'GO!') + '"/></div>' +
               '<div class="sv-props-row"><label>Color</label><input type="color" data-c="color" value="' + _svEsc(c.color||'#FFFFFF') + '"/></div>';
    } else if (e.type === 'logo') {
      fields = '<div class="sv-props-row"><label>URL</label><input data-c="logo_url" value="' + _svEsc(c.logo_url||'') + '"/></div>';
    } else if (e.type === 'progress_bar') {
      fields = '<div class="sv-props-row"><label>Fill</label><input type="color" data-c="fill_color" value="' + _svEsc(c.fill_color||'#6C5CE7') + '"/></div>';
    }
    h.innerHTML =
      '<div class="sv-props-group">' +
        '<div class="sv-props-title">Element \u2014 ' + _svEsc(e.type) + '</div>' +
        fields +
        '<div class="sv-props-row"><label>Start</label><input type="number" step="0.1" data-k="start_time" value="' + (e.start_time || 0).toFixed(1) + '"/><label>End</label><input type="number" step="0.1" data-k="end_time" value="' + (e.end_time || 0).toFixed(1) + '"/></div>' +
        '<div class="sv-props-row"><label>X</label><input type="number" data-pos="x" value="' + (e.position ? e.position.x : 0) + '"/><label>Y</label><input type="number" data-pos="y" value="' + (e.position ? e.position.y : 0) + '"/></div>' +
        '<div class="sv-props-row"><label>W</label><input type="number" data-sz="width" value="' + (e.size ? e.size.width : 0) + '"/><label>H</label><input type="number" data-sz="height" value="' + (e.size ? e.size.height : 0) + '"/></div>' +
      '</div>' +
      '<button class="sv-btn-wide sv-btn-danger" id="sv-el-del">Delete element</button>';
    h.querySelectorAll('[data-k]').forEach(function(inp){ var k = inp.getAttribute('data-k'); inp.oninput = function(){ e[k] = parseFloat(inp.value); _svMarkDirty(); _svAutoSaveSoon(); _svRenderTimeline(); _svDrawFrame(VE.playhead); }; });
    h.querySelectorAll('[data-pos]').forEach(function(inp){ var p = inp.getAttribute('data-pos'); inp.oninput = function(){ e.position = e.position||{}; e.position[p] = parseFloat(inp.value); _svMarkDirty(); _svAutoSaveSoon(); _svDrawFrame(VE.playhead); }; });
    h.querySelectorAll('[data-sz]').forEach(function(inp){ var p = inp.getAttribute('data-sz'); inp.oninput = function(){ e.size = e.size||{}; e.size[p] = parseFloat(inp.value); _svMarkDirty(); _svAutoSaveSoon(); _svDrawFrame(VE.playhead); }; });
    h.querySelectorAll('[data-c]').forEach(function(inp){ var p = inp.getAttribute('data-c'); inp.oninput = function(){ e.content = e.content||{}; e.content[p] = inp.value; _svMarkDirty(); _svAutoSaveSoon(); _svDrawFrame(VE.playhead); }; });
    document.getElementById('sv-el-del').onclick = function(){
      _svSaveHistory();
      VE.vd.elements = VE.vd.elements.filter(function(x){ return x.id !== e.id; });
      VE.selected = null; _svMarkDirty(); _svAutoSaveSoon();
      _svRenderTimeline(); _svRenderLeftPanel(VE.tabActive); _svRenderProps(); _svDrawFrame(VE.playhead);
    };
  }

  // ── Top bar wiring ────────────────────────────────────────
  function _svWireTopbar(){
    document.getElementById('sv-back').onclick = _svRequestExit;
    var nm = document.getElementById('sv-design-name');
    nm.oninput = function(e){ VE.vd.name = e.target.value; _svMarkDirty(); _svAutoSaveSoon(); };
    document.getElementById('sv-undo').onclick = _svUndo;
    document.getElementById('sv-redo').onclick = _svRedo;
    document.getElementById('sv-save-btn').onclick = function(){ _svAutoSave(false); };
    document.getElementById('sv-export-btn').onclick = _svExport;
  }
  function _svWirePlaybackControls(){
    document.getElementById('sv-play-btn').onclick = function(){ VE.playing ? _svStop() : _svPlay(); };
    document.getElementById('sv-back-frame').onclick = function(){ VE.playhead = Math.max(0, VE.playhead - 1 / FPS_TARGET * 5); _svDrawFrame(VE.playhead); _svUpdatePlayheadUI(); };
    document.getElementById('sv-fwd-frame').onclick  = function(){ VE.playhead = Math.min(_svTotalDuration(), VE.playhead + 1 / FPS_TARGET * 5); _svDrawFrame(VE.playhead); _svUpdatePlayheadUI(); };
    document.getElementById('sv-fit-btn').onclick    = _svFitCanvasToViewport;
    VE.root.querySelectorAll('.sv-view-btn').forEach(function(b){
      b.onclick = function(){
        VE.root.querySelectorAll('.sv-view-btn').forEach(function(x){ x.classList.remove('active'); });
        b.classList.add('active');
        VE.viewMode = b.getAttribute('data-view');
        _svFitCanvasToViewport();
      };
    });
  }
  function _svWireCanvasClicks(){
    // Click canvas to deselect (future: hit-test for elements/text)
    VE.canvas.addEventListener('click', function(){ VE.selected = null; _svHighlightSelection(); _svRenderProps(); });
  }

  function _svFitCanvasToViewport(){
    var wrap = document.getElementById('sv-canvas-wrap'); if (!wrap) return;
    var frame = document.getElementById('sv-canvas-frame'); if (!frame) return;
    var avail = { w: wrap.clientWidth - 40, h: wrap.clientHeight - 40 - 42 - 40 }; // minus playback bar + toolbar
    var W = VE.vd.canvas_width, H = VE.vd.canvas_height;
    var z = Math.min(avail.w / W, avail.h / H, 1);
    VE.canvas.style.width  = (W * z) + 'px';
    VE.canvas.style.height = (H * z) + 'px';
  }

  // ── History ───────────────────────────────────────────────
  function _svInitHistory(){ VE.history = [_svSnap()]; VE.historyIndex = 0; _svSyncUndoRedo(); }
  function _svSnap(){ return JSON.parse(JSON.stringify(VE.vd)); }
  function _svSaveHistory(){
    VE.history = VE.history.slice(0, VE.historyIndex + 1);
    VE.history.push(_svSnap());
    if (VE.history.length > 50) VE.history.shift();
    VE.historyIndex = VE.history.length - 1;
    _svSyncUndoRedo();
  }
  function _svUndo(){ if (VE.historyIndex <= 0) return; VE.historyIndex--; VE.vd = JSON.parse(JSON.stringify(VE.history[VE.historyIndex])); _svApplyVdChange(); }
  function _svRedo(){ if (VE.historyIndex >= VE.history.length - 1) return; VE.historyIndex++; VE.vd = JSON.parse(JSON.stringify(VE.history[VE.historyIndex])); _svApplyVdChange(); }
  function _svApplyVdChange(){
    _svInitVideoPool(); _svPreloadImages();
    _svRenderTimeline(); _svRenderLeftPanel(VE.tabActive); _svRenderProps(); _svDrawFrame(VE.playhead);
    _svSyncUndoRedo(); _svMarkDirty();
  }
  function _svSyncUndoRedo(){
    var u = document.getElementById('sv-undo'), r = document.getElementById('sv-redo');
    if (u) u.disabled = VE.historyIndex <= 0;
    if (r) r.disabled = VE.historyIndex >= VE.history.length - 1;
  }

  // ── Save + export ─────────────────────────────────────────
  function _svMarkDirty(){
    VE.dirty = true; var s = document.getElementById('sv-save-status'); if (s) s.textContent = 'Unsaved';
  }
  function _svAutoSaveSoon(){ clearTimeout(VE._deb); VE._deb = setTimeout(function(){ _svAutoSave(true); }, 1500); }
  function _svAutoSave(silent){
    if (VE.saving) return Promise.resolve();
    VE.saving = true;
    var s = document.getElementById('sv-save-status'); if (s) s.textContent = 'Saving\u2026';
    return _fetchJson('/studio/designs/' + VE.designId, {
      method: 'PUT',
      body: JSON.stringify({
        layers_json: VE.vd,     // reuse existing column for backwards compat
        canvas_width: VE.vd.canvas_width, canvas_height: VE.vd.canvas_height,
      }),
    }).then(function(){
      VE.dirty = false; if (s) s.textContent = 'Saved';
      setTimeout(function(){ if (s && s.textContent === 'Saved') s.textContent = ''; }, 1500);
    }).catch(function(){ if (s) s.textContent = 'Save failed'; }).then(function(){ VE.saving = false; });
  }
  function _svExport(){
    _svAutoSave(true).then(function(){
      _svToast('Exporting MP4...', 'info');
      return fetch(_svApi() + '/studio/video/designs/' + VE.designId + '/export', {
        method:'POST', headers:{ 'Authorization': 'Bearer ' + _svTok(), 'Content-Type':'application/json' },
      });
    }).then(function(r){ return r ? r.json() : null; }).then(function(d){
      if (!d) return;
      if (d.success) _svToast('Export queued. Check the gallery when done.', 'success');
      else _svToast('Export failed: ' + (d.error || 'unknown'), 'error');
    }).catch(function(e){ _svToast('Export error: ' + e.message, 'error'); });
  }

  function _svRequestExit(){
    if (VE.dirty) {
      var ok = window.luConfirm ? window.luConfirm('Unsaved changes. Leave anyway?', 'Unsaved changes', 'Leave', 'Stay') : Promise.resolve(confirm('Unsaved changes. Leave anyway?'));
      Promise.resolve(ok).then(function(confirmed){ if (confirmed) _svTeardown(); });
    } else _svTeardown();
  }
  function _svTeardown(){
    _svStop();
    if (VE.autoSaveTimer) clearInterval(VE.autoSaveTimer);
    Object.values(VE.videoPool).forEach(function(v){ try { v.pause(); v.src = ''; } catch(_){} });
    VE.videoPool = {}; VE.imageCache = {};
    var host = document.getElementById('sv-host'); if (host) host.innerHTML = '';
    // Return to gallery
    if (typeof window.studioVideoLoad === 'function') window.studioVideoLoad(null);
    else if (typeof window.studioLoad === 'function') window.studioLoad(null);
  }

  // ── CSS ───────────────────────────────────────────────────
  function _svInjectCss(){
    if (VE.cssInjected) return; VE.cssInjected = true;
    var css = [
      '.sv-editor-root{position:fixed;inset:0;z-index:890;background:var(--bg,#0B0C11);color:var(--t1,#F1F5F9);font-family:"DM Sans","Inter",sans-serif;display:flex;flex-direction:column}',
      '.sv-topbar{height:52px;border-bottom:1px solid var(--bd,#1f2330);display:flex;align-items:center;padding:0 16px;gap:12px;background:var(--s1,#0F1218);flex-shrink:0}',
      '.sv-back{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;font-family:inherit}',
      '.sv-back:hover{background:var(--s2,#171b23)}',
      '.sv-design-name{flex:1;max-width:280px;background:transparent;border:none;font-size:15px;font-weight:600;color:var(--t1,#F1F5F9);outline:none}',
      '.sv-topbar-center{flex:1;display:flex;justify-content:center;font-size:12px;color:var(--t3,#64748B)}',
      '.sv-topbar-right{display:flex;gap:8px;align-items:center}',
      '.sv-icon-btn{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:5px 10px;border-radius:5px;cursor:pointer;font-size:12px;font-family:inherit}',
      '.sv-icon-btn:hover:not(:disabled){background:var(--s2,#171b23);color:var(--t1,#F1F5F9)}',
      '.sv-icon-btn:disabled{opacity:.35;cursor:default}',
      '.sv-btn-ghost{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:6px 14px;border-radius:6px;font-size:13px;cursor:pointer;font-family:inherit}',
      '.sv-btn-primary{background:var(--p,#6C5CE7);border:none;color:#fff;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}',
      '.sv-btn-primary:hover{background:var(--p-dark,#5849d3)}',
      '.sv-save-status{font-size:11px;color:var(--t3,#64748B)}',
      '.sv-btn-wide{width:100%;padding:8px 12px;margin-top:6px;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);color:var(--t1,#F1F5F9);border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;text-align:left}',
      '.sv-btn-wide:hover{border-color:var(--p,#6C5CE7)}',
      '.sv-btn-wide:disabled{opacity:.5;cursor:default}',
      '.sv-btn-danger{color:#EF4444;border-color:rgba(239,68,68,.4);background:transparent}',

      '.sv-shell{flex:1;display:grid;grid-template-columns:240px 1fr 300px;min-height:0;overflow:hidden}',
      '.sv-left{border-right:1px solid var(--bd,#1f2330);background:var(--s1,#0F1218);overflow:hidden;display:flex;flex-direction:column}',
      '.sv-tabs{display:flex;border-bottom:1px solid var(--bd,#1f2330);flex-shrink:0;overflow-x:auto}',
      '.sv-tab{flex:1;background:transparent;border:none;color:var(--t3,#64748B);padding:10px 4px;font-size:10px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;font-family:inherit;white-space:nowrap}',
      '.sv-tab.active{color:var(--t1,#F1F5F9);border-bottom-color:var(--p,#6C5CE7)}',
      '.sv-tab-body{padding:10px;overflow-y:auto;flex:1}',
      '.sv-section-label{font-size:10px;font-weight:700;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em;margin:12px 0 6px}',
      '.sv-my-clips{display:flex;flex-direction:column;gap:4px}',
      '.sv-clip-thumb{display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid var(--bd,#2a2f3a);border-radius:6px;cursor:pointer;background:var(--s2,#171b23)}',
      '.sv-clip-thumb:hover{border-color:var(--p,#6C5CE7)}',
      '.sv-clip-thumb .th{width:30px;height:30px;display:flex;align-items:center;justify-content:center;background:rgba(108,92,231,.15);border-radius:4px;font-size:14px;color:var(--p,#6C5CE7)}',
      '.sv-clip-thumb .meta{flex:1;min-width:0}',
      '.sv-clip-thumb .nm{font-size:12px;color:var(--t1,#F1F5F9);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.sv-clip-thumb .dur{font-size:10px;color:var(--t3,#64748B)}',
      '.sv-empty{padding:20px 10px;text-align:center;color:var(--t3,#64748B);font-size:12px}',
      '.sv-hint{padding:20px 10px;text-align:center;color:var(--t3,#64748B);font-size:12px}',
      '.sv-trans-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px}',
      '.sv-trans-btn,.sv-filter-btn,.sv-dur-btn,.sv-kb-btn{background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);color:var(--t2,#CBD5E1);padding:6px 8px;font-size:10px;border-radius:4px;cursor:pointer;font-family:inherit;text-transform:capitalize}',
      '.sv-trans-btn:hover,.sv-filter-btn:hover,.sv-dur-btn:hover,.sv-kb-btn:hover{border-color:var(--p,#6C5CE7);color:var(--p,#6C5CE7)}',
      '.sv-filter-btn.active{border-color:var(--p,#6C5CE7);background:rgba(108,92,231,.12);color:var(--p,#6C5CE7)}',
      '.sv-dur-row{display:flex;gap:4px}',
      '.sv-kb-row{display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-top:6px}',
      '.sv-filter-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px}',

      '.sv-canvas-wrap{display:flex;flex-direction:column;background:#0a0a18;overflow:hidden;min-width:0}',
      '.sv-canvas-toolbar{height:40px;border-bottom:1px solid var(--bd,#1f2330);display:flex;align-items:center;padding:0 10px;gap:6px;background:var(--s1,#0F1218);flex-shrink:0}',
      '.sv-view-btn{background:transparent;border:1px solid var(--bd,#2a2f3a);color:var(--t3,#64748B);padding:4px 10px;font-size:11px;cursor:pointer;border-radius:4px;font-family:inherit}',
      '.sv-view-btn.active{background:var(--p,#6C5CE7);color:#fff;border-color:var(--p,#6C5CE7)}',
      '.sv-toolbar-spacer{flex:1}',
      '.sv-canvas-frame{flex:1;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative}',
      '#sv-canvas{background:#000;box-shadow:0 0 0 1px rgba(108,92,231,.3),0 16px 40px rgba(0,0,0,.5);max-width:100%;max-height:100%;transition:filter .15s}',
      '.sv-playback{height:42px;border-top:1px solid var(--bd,#1f2330);display:flex;align-items:center;justify-content:center;gap:8px;background:var(--s1,#0F1218);flex-shrink:0}',
      '.sv-play-btn{background:var(--p,#6C5CE7);border:none;color:#fff;width:36px;height:36px;border-radius:50%;cursor:pointer;font-size:14px;font-family:inherit}',
      '.sv-play-btn:hover{background:var(--p-dark,#5849d3)}',

      '.sv-right{border-left:1px solid var(--bd,#1f2330);background:var(--s1,#0F1218);overflow-y:auto;padding:10px}',
      '.sv-props-group{background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:8px;padding:10px;margin-bottom:8px}',
      '.sv-props-title{font-size:10px;font-weight:700;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}',
      '.sv-props-row{display:flex;align-items:center;gap:6px;margin-bottom:6px;flex-wrap:wrap}',
      '.sv-props-row label{font-size:11px;color:var(--t3,#64748B);min-width:50px}',
      '.sv-props-row input,.sv-props-row select,.sv-props-row span{flex:1;min-width:0;background:var(--s3,#1e2230);border:1px solid var(--bd,#2a2f3a);color:var(--t1,#F1F5F9);padding:4px 6px;font-size:11px;border-radius:4px;font-family:inherit}',
      '.sv-props-row input[type=color]{flex:0 0 32px;padding:0;height:24px}',
      '.sv-props-row input[type=checkbox]{flex:0 0 16px;padding:0}',
      '.sv-props-row input[type=range]{padding:0}',
      '.sv-textarea{width:100%;background:var(--s3,#1e2230);border:1px solid var(--bd,#2a2f3a);color:var(--t1,#F1F5F9);padding:6px;font-size:11px;border-radius:4px;font-family:inherit;box-sizing:border-box;resize:vertical;min-height:60px}',

      '.sv-timeline{height:220px;border-top:1px solid var(--bd,#1f2330);background:var(--s1,#0F1218);display:flex;flex-shrink:0}',
      '.sv-tl-left-col{width:90px;flex-shrink:0;display:flex;flex-direction:column;background:var(--s1,#0F1218);border-right:1px solid var(--bd,#1f2330)}',
      '.sv-tl-hdr-spacer{height:26px;border-bottom:1px solid var(--bd,#1f2330)}',
      '.sv-track-label{flex:1;display:flex;align-items:center;padding:0 10px;font-size:10px;color:var(--t3,#64748B);text-transform:uppercase;letter-spacing:.08em;border-bottom:1px solid var(--bd,#1f2330)}',
      '.sv-tl-scroll{flex:1;overflow-x:auto;overflow-y:hidden;position:relative}',
      '.sv-tl-inner{position:relative;height:100%}',
      '.sv-tl-ruler{position:relative;height:26px;border-bottom:1px solid var(--bd,#1f2330);cursor:ew-resize}',
      '.sv-tl-tick{position:absolute;top:0;bottom:0;border-left:1px solid rgba(255,255,255,.08);padding-left:3px;font-size:9px;color:var(--t3,#64748B);line-height:26px}',
      '.sv-tl-tick.major{border-left-color:rgba(255,255,255,.2)}',
      '.sv-tl-track{height:calc((100% - 26px) / 4);border-bottom:1px solid var(--bd,#1f2330);position:relative}',
      '.sv-tl-block{position:absolute;top:4px;bottom:4px;background:var(--s2,#171b23);border:1px solid rgba(108,92,231,.45);border-radius:4px;cursor:move;font-size:10px;color:var(--t1,#F1F5F9);padding:0 8px;display:flex;align-items:center;overflow:hidden;white-space:nowrap}',
      '.sv-tl-block.selected{border-color:var(--p,#6C5CE7);box-shadow:0 0 0 1px var(--p,#6C5CE7)}',
      '.sv-tl-block.clip{background:rgba(108,92,231,.25)}',
      '.sv-tl-block.text{background:rgba(0,229,168,.22)}',
      '.sv-tl-block.element{background:rgba(245,158,11,.22)}',
      '.sv-tl-block.audio{background:rgba(244,114,182,.22)}',
      '.sv-tl-label{flex:1;overflow:hidden;text-overflow:ellipsis;pointer-events:none}',
      '.sv-tl-trim{position:absolute;top:0;bottom:0;width:6px;cursor:ew-resize;background:rgba(255,255,255,.3)}',
      '.sv-tl-trim.l{left:0;border-radius:4px 0 0 4px}',
      '.sv-tl-trim.r{right:0;border-radius:0 4px 4px 0}',
      '.sv-tl-trim:hover{background:#fff}',
      '.sv-tl-playhead{position:absolute;top:0;bottom:0;width:2px;background:#EF4444;pointer-events:none;z-index:10;box-shadow:0 0 6px rgba(239,68,68,.5)}',
      '.sv-tl-playhead::before{content:"";position:absolute;top:-4px;left:-5px;width:12px;height:12px;background:#EF4444;border-radius:50%;box-shadow:0 0 8px rgba(239,68,68,.6)}',

      '.sv-ai-prompt{width:100%;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);color:var(--t1,#F1F5F9);padding:8px;font-size:12px;border-radius:4px;resize:vertical;box-sizing:border-box;font-family:inherit}',
      '.sv-audio-info{margin-top:8px;padding:8px;background:var(--s2,#171b23);border:1px solid var(--bd,#2a2f3a);border-radius:6px}',
      '.sv-audio-waveform{height:40px;margin:8px 0;background:linear-gradient(90deg,rgba(108,92,231,.15) 0%,rgba(108,92,231,.35) 50%,rgba(108,92,231,.15) 100%);border-radius:4px}',
    ].join('\n');
    var s = document.createElement('style'); s.id = 'sv-p3-styles'; s.textContent = css;
    document.head.appendChild(s);
  }

  // ── Wire into gallery ─────────────────────────────────────
  // When the existing gallery clicks a clip_json design, route here.
  // Legacy entry: gallery calls _mountEditor / _newTemplateDesign / opens by id.
  // We override by exposing a shim the gallery can call after design load.
  var _origMountEditor = window._svMountClipEditor;
  window._svMountClipEditor = function(designId){ _svOpenClipEditor(designId); };
  // (Canvas editor entry is window._svOpenClipEditor(id) — _newTemplateDesign wires it directly.)

  // Video gallery click handler — replace old iframe mount with canvas editor.
  // The existing gallery in studio-video.js calls `_openVideoDesign(id)` or
  // similar when a user clicks a clip_json row. We shim it here so either
  // path works.
  window._svEditorEntry = _svOpenClipEditor;

})();
