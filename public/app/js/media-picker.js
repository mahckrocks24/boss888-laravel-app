/**
 * Unified Media Picker — BOSS888 / LevelUp Growth
 * Added 2026-04-19 as part of Phase 3.
 *
 * Public entry point:
 *   window.openMediaPicker(options, callback)
 *
 * options:
 *   type:     'image' | 'video' | 'document' | 'any'   (default 'image')
 *   multiple: boolean                                   (default false)
 *   context:  'builder' | 'blog' | 'social' |           (used for usage tracking + defaults)
 *             'marketing' | 'email' | 'write' | 'any'
 *   field:    optional — name of the field being populated (builder use case)
 *
 * callback(result):
 *   single mode → {id, url, thumbnail_url, mime_type, asset_type, width, height, duration_seconds, filename}
 *   multiple    → [{...}, ...]
 *   cancel      → null
 *
 * API dependencies (all under auth.jwt):
 *   GET  /api/media/library?type=&platform=&search=&page=
 *   GET  /api/media/access
 *   POST /api/media/use       {media_id, context}
 *   POST /api/media/upload    (multipart "file")
 *
 * Auth token: reads localStorage.lu_token (standard app-side token).
 */
(function () {
  'use strict';

  var MODAL_ID = 'lu-mp-modal';
  var STATE = null; // freshly created per open()
  var CB    = null;
  var ACCESS = null; // cached snapshot for the lifetime of the modal

  // ── public entry ────────────────────────────────────────────────
  window.openMediaPicker = function (options, callback) {
    options = options || {};
    STATE = {
      type:     options.type     || 'image',
      multiple: !!options.multiple,
      context:  options.context  || 'any',
      field:    options.field    || null,
      tab:      'library',           // library | uploads | upload
      page:     1,
      search:   '',
      selected: [],                  // array of media objects
      typeFilter: options.type === 'any' ? '' : (options.type || 'image'),
    };
    CB = (typeof callback === 'function') ? callback : function () {};
    ACCESS = null;
    _mpRender();
  };

  // ── render root modal ───────────────────────────────────────────
  function _mpRender() {
    var existing = document.getElementById(MODAL_ID);
    if (existing) existing.remove();
    var ov = document.createElement('div');
    ov.id = MODAL_ID;
    ov.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.72);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;padding:24px';
    ov.innerHTML =
      '<style>' + _mpCss() + '</style>' +
      '<div class="lu-mp-shell" onclick="event.stopPropagation()">' +
        '<div class="lu-mp-head">' +
          '<div class="lu-mp-title">Media Library</div>' +
          '<button class="lu-mp-x" onclick="window._mpClose()">&#10005;</button>' +
        '</div>' +
        '<div class="lu-mp-tabs">' +
          _mpTab('library', '\uD83C\uDF10 Platform Library') +
          _mpTab('uploads', '\uD83D\uDC64 My Uploads') +
          _mpTab('upload',  '\u2B06 Upload New') +
        '</div>' +
        '<div class="lu-mp-body" id="lu-mp-body">Loading\u2026</div>' +
        '<div class="lu-mp-foot" id="lu-mp-foot"></div>' +
      '</div>';
    ov.addEventListener('click', function (e) { if (e.target === ov) _mpClose(); });
    document.body.appendChild(ov);
    _mpEscKey(true);
    _mpLoadAccess().then(_mpLoadTab);
  }

  function _mpTab(id, label) {
    var active = STATE.tab === id;
    return '<button class="lu-mp-tab' + (active ? ' active' : '') + '" onclick="window._mpSwitchTab(\'' + id + '\')">' + label + '</button>';
  }

  window._mpSwitchTab = function (id) {
    STATE.tab = id; STATE.page = 1;
    _mpRerenderTabs();
    _mpLoadTab();
  };

  function _mpRerenderTabs() {
    var shell = document.querySelector('.lu-mp-tabs');
    if (!shell) return;
    shell.innerHTML = _mpTab('library', '\uD83C\uDF10 Platform Library') +
                      _mpTab('uploads', '\uD83D\uDC64 My Uploads') +
                      _mpTab('upload',  '\u2B06 Upload New');
  }

  function _mpLoadTab() {
    if (STATE.tab === 'library') return _mpLoadLibrary(true);
    if (STATE.tab === 'uploads') return _mpLoadLibrary(false);
    if (STATE.tab === 'upload')  return _mpRenderUpload();
  }

  // ── access snapshot (cached for modal lifetime) ─────────────────
  async function _mpLoadAccess() {
    if (ACCESS) return ACCESS;
    try {
      var r = await _mpFetch('/api/media/access');
      var d = await r.json();
      ACCESS = d && d.success ? d : { can_access: false, limit: 0, usage: 0, unlimited: false };
    } catch (e) {
      ACCESS = { can_access: false, limit: 0, usage: 0, unlimited: false };
    }
    return ACCESS;
  }

  // ── library / uploads tabs share this renderer ─────────────────
  async function _mpLoadLibrary(platform) {
    var body = document.getElementById('lu-mp-body');
    if (!body) return;
    body.innerHTML = '<div class="lu-mp-loading">Loading media\u2026</div>';

    var params = new URLSearchParams({
      platform: platform ? '1' : '0',
      type:     STATE.typeFilter || 'image',
      search:   STATE.search || '',
      page:     STATE.page || 1,
      per_page: 24,
    });
    try {
      var r = await _mpFetch('/api/media/library?' + params.toString());
      var d = await r.json();
      if (!d || !d.success) { body.innerHTML = '<div class="lu-mp-error">Failed to load.</div>'; return; }

      // Plan-gate locked state (platform library only, limit=0)
      if (d.locked) {
        body.innerHTML = _mpLockedOverlayHtml(d.access || ACCESS);
        _mpRenderFooter();
        return;
      }

      // Plan-gate header (for AI Lite / Growth to show usage bar)
      var gateBar = (platform && d.access && !d.access.unlimited && d.access.limit > 0)
        ? _mpUsageBarHtml(d.access)
        : (platform && d.access && d.access.unlimited ? '<div class="lu-mp-unlim">\u2728 Unlimited access</div>' : '');

      // Filter + search bar
      var filterBar =
        '<div class="lu-mp-filter">' +
          '<input type="search" id="lu-mp-search" placeholder="Search filename, description, tags\u2026" value="' + _mpEsc(STATE.search) + '" onkeydown="if(event.key===\'Enter\')window._mpApplySearch()">' +
          (STATE.type === 'any' ?
            '<select onchange="window._mpSetTypeFilter(this.value)">' +
              '<option value="">All types</option>' +
              '<option value="image"' + (STATE.typeFilter==='image'?' selected':'') + '>Images</option>' +
              '<option value="video"' + (STATE.typeFilter==='video'?' selected':'') + '>Videos</option>' +
              '<option value="document"' + (STATE.typeFilter==='document'?' selected':'') + '>Documents</option>' +
            '</select>' : '') +
        '</div>';

      // Grid of files
      var gridHtml = (d.files || []).length
        ? '<div class="lu-mp-grid">' + d.files.map(function (f) { return _mpTileHtml(f, platform, d.access); }).join('') + '</div>'
        : '<div class="lu-mp-empty">No media found.</div>';

      var pager = _mpPagerHtml(d);

      body.innerHTML = gateBar + filterBar + gridHtml + pager;
      _mpRenderFooter();
    } catch (e) {
      body.innerHTML = '<div class="lu-mp-error">' + _mpEsc(String(e && e.message || e)) + '</div>';
    }
  }

  function _mpTileHtml(f, platform, access) {
    var id = Number(f.id);
    var url = f.url || f.file_url || '';
    var thumb = f.thumbnail_url || (f.asset_type === 'image' ? url : '');
    var selected = STATE.selected.some(function (s) { return s.id === id; });
    var isVideo  = f.asset_type === 'video';
    var isDoc    = f.asset_type === 'document';
    var media;
    if (thumb) {
      media = '<img src="' + _mpEsc(thumb) + '" alt="' + _mpEsc(f.filename || '') + '" loading="lazy">';
    } else if (isVideo && url) {
      media = '<video src="' + _mpEsc(url) + '" preload="metadata" muted></video>';
    } else if (isDoc) {
      media = '<div class="lu-mp-icon">\uD83D\uDCC4</div>';
    } else {
      media = '<div class="lu-mp-icon">?</div>';
    }
    var duration = (isVideo && f.duration_seconds) ? _mpDurFmt(f.duration_seconds) : '';
    var durBadge = duration ? '<div class="lu-mp-dur">\u25B6 ' + duration + '</div>' : '';

    // Plan-gate: if at/over limit and platform, this tile is "locked" (can still preview but can't select)
    var overLimit = platform && access && !access.unlimited && access.limit > 0 && access.usage >= access.limit && !selected;
    var lockedUi = overLimit ? '<div class="lu-mp-lock"><span>\uD83D\uDD12</span><span>Upgrade</span></div>' : '';
    var clickHandler = overLimit
      ? 'onclick="alert(\'You\\u2019ve reached your library limit. Upgrade to unlock more.\')"'
      : 'onclick="window._mpToggleSelect(' + id + ')"';

    return '<div class="lu-mp-tile ' + (selected ? 'selected' : '') + ' ' + (overLimit ? 'locked' : '') + '" ' + clickHandler + ' data-media-id="' + id + '">' +
             '<div class="lu-mp-thumb">' + media + durBadge + lockedUi + '</div>' +
             '<div class="lu-mp-meta" title="' + _mpEsc(f.filename || '') + '">' + _mpEsc(_mpTruncate(f.filename || '', 22)) + '</div>' +
             (selected ? '<div class="lu-mp-check">\u2713</div>' : '') +
           '</div>';
  }

  window._mpApplySearch = function () {
    var el = document.getElementById('lu-mp-search');
    STATE.search = el ? el.value : '';
    STATE.page = 1;
    _mpLoadTab();
  };
  window._mpSetTypeFilter = function (v) {
    STATE.typeFilter = v; STATE.page = 1; _mpLoadTab();
  };
  window._mpGoPage = function (p) { STATE.page = p; _mpLoadTab(); };

  function _mpPagerHtml(d) {
    var total = d.total || 0;
    var pp = d.per_page || 24;
    var pages = Math.max(1, Math.ceil(total / pp));
    if (pages <= 1) return '';
    var p = d.page || 1;
    return '<div class="lu-mp-pager">' +
             '<button ' + (p <= 1 ? 'disabled' : '') + ' onclick="window._mpGoPage(' + (p - 1) + ')">\u2190</button>' +
             '<span>' + p + ' / ' + pages + ' (' + total + ' total)</span>' +
             '<button ' + (p >= pages ? 'disabled' : '') + ' onclick="window._mpGoPage(' + (p + 1) + ')">\u2192</button>' +
           '</div>';
  }

  // ── selection ──────────────────────────────────────────────────
  window._mpToggleSelect = function (id) {
    if (!STATE.multiple) {
      STATE.selected = [{ id: id }];
      _mpFillSelectedFromDOM();
      _mpRenderFooter();
      _mpRepaintSelection();
      return;
    }
    var idx = STATE.selected.findIndex(function (s) { return s.id === id; });
    if (idx >= 0) STATE.selected.splice(idx, 1);
    else STATE.selected.push({ id: id });
    _mpFillSelectedFromDOM();
    _mpRenderFooter();
    _mpRepaintSelection();
  };

  // Sync our slim {id} entries with full file objects from the current grid DOM
  function _mpFillSelectedFromDOM() {
    STATE.selected = STATE.selected.map(function (s) {
      var tile = document.querySelector('.lu-mp-tile[data-media-id="' + s.id + '"]');
      if (!tile) return s;
      var img = tile.querySelector('img');
      var vid = tile.querySelector('video');
      var thumbEl = img || vid;
      return Object.assign({}, s, {
        url:         thumbEl ? thumbEl.getAttribute('src') : null,
        filename:    tile.querySelector('.lu-mp-meta') ? tile.querySelector('.lu-mp-meta').textContent : '',
        thumbnail_url: img ? img.getAttribute('src') : null,
        asset_type:  vid ? 'video' : (img ? 'image' : 'other'),
      });
    });
  }

  function _mpRepaintSelection() {
    document.querySelectorAll('.lu-mp-tile').forEach(function (el) {
      var id = Number(el.getAttribute('data-media-id'));
      var on = STATE.selected.some(function (s) { return s.id === id; });
      el.classList.toggle('selected', on);
      var existing = el.querySelector('.lu-mp-check');
      if (on && !existing) {
        var d = document.createElement('div');
        d.className = 'lu-mp-check';
        d.textContent = '\u2713';
        el.appendChild(d);
      } else if (!on && existing) {
        existing.remove();
      }
    });
  }

  // ── footer: select counter + CTA ────────────────────────────────
  function _mpRenderFooter() {
    var foot = document.getElementById('lu-mp-foot');
    if (!foot) return;
    var n = STATE.selected.length;
    var cta = STATE.multiple ? ('Use ' + (n || '') + (n === 1 ? ' file' : ' files')) : 'Use this file';
    foot.innerHTML =
      '<div class="lu-mp-footL">' + (n ? (n + ' selected') : 'Pick ' + (STATE.multiple ? 'one or more files' : 'a file')) + '</div>' +
      '<div class="lu-mp-footR">' +
        '<button class="lu-mp-btn-ghost" onclick="window._mpClose()">Cancel</button>' +
        '<button class="lu-mp-btn-primary" ' + (n ? '' : 'disabled') + ' onclick="window._mpConfirm()">' + cta + '</button>' +
      '</div>';
  }

  window._mpConfirm = async function () {
    if (!STATE.selected.length) return;
    _mpFillSelectedFromDOM();
    // Record usage server-side (best-effort, fire-and-forget)
    STATE.selected.forEach(function (s) {
      if (s && s.id) _mpFetch('/api/media/use', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ media_id: s.id, context: STATE.context || 'any' }) }).catch(function(){});
    });
    var result = STATE.multiple ? STATE.selected.slice() : STATE.selected[0];
    // Capture the real callback BEFORE _mpClose nukes it — then null out
    // CB so _mpClose's cancel-path (CB(null)) doesn't pre-empt our call.
    var cb = CB;
    CB = null;
    _mpClose();
    try { if (cb) cb(result); } catch (e) { console.warn('[MediaPicker] callback threw', e); }
  };

  window._mpClose = function () {
    var el = document.getElementById(MODAL_ID);
    if (el) el.remove();
    _mpEscKey(false);
    // Cancel callback gets null so callers can distinguish from no-op
    if (CB) { try { CB(null); } catch (e) {} }
    CB = null; STATE = null; ACCESS = null;
  };

  function _mpEscKey(on) {
    if (on) { window.addEventListener('keydown', _mpOnEsc); }
    else { window.removeEventListener('keydown', _mpOnEsc); }
  }
  function _mpOnEsc(e) { if (e.key === 'Escape') _mpClose(); }

  // ── upload tab ─────────────────────────────────────────────────
  function _mpRenderUpload() {
    var body = document.getElementById('lu-mp-body');
    if (!body) return;
    body.innerHTML =
      '<div class="lu-mp-drop" id="lu-mp-drop">' +
        '<div class="lu-mp-dropIcon">\u2B06</div>' +
        '<div class="lu-mp-dropMain">Drop a file here, or <label class="lu-mp-dropClick" for="lu-mp-input">click to browse</label></div>' +
        '<div class="lu-mp-dropSub">Images up to 100 MB, videos up to 100 MB. Accepted: jpg, png, webp, gif, mp4, webm, mov, pdf</div>' +
        '<input id="lu-mp-input" type="file" style="display:none" accept="' + _mpAcceptForType() + '">' +
      '</div>' +
      '<div id="lu-mp-progress" style="display:none"><div class="lu-mp-progressBar"><span id="lu-mp-progressFill"></span></div><div id="lu-mp-progressText">Uploading\u2026</div></div>' +
      '<div id="lu-mp-uploadErr" class="lu-mp-error" style="display:none"></div>';
    var inp = document.getElementById('lu-mp-input');
    var drop = document.getElementById('lu-mp-drop');
    if (inp) inp.addEventListener('change', function (e) { if (e.target.files[0]) _mpDoUpload(e.target.files[0]); });
    if (drop) {
      drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('over'); });
      drop.addEventListener('dragleave', function () { drop.classList.remove('over'); });
      drop.addEventListener('drop', function (e) {
        e.preventDefault();
        drop.classList.remove('over');
        if (e.dataTransfer.files[0]) _mpDoUpload(e.dataTransfer.files[0]);
      });
    }
    _mpRenderFooter();
  }

  function _mpAcceptForType() {
    if (STATE.type === 'image') return 'image/*';
    if (STATE.type === 'video') return 'video/*';
    if (STATE.type === 'document') return '.pdf,.doc,.docx,application/pdf';
    return 'image/*,video/*,.pdf,.doc,.docx';
  }

  async function _mpDoUpload(file) {
    var err = document.getElementById('lu-mp-uploadErr');
    var prog = document.getElementById('lu-mp-progress');
    var fill = document.getElementById('lu-mp-progressFill');
    var ptext = document.getElementById('lu-mp-progressText');
    if (err) err.style.display = 'none';
    if (prog) prog.style.display = 'block';
    if (ptext) ptext.textContent = 'Uploading ' + file.name + '\u2026';

    try {
      var fd = new FormData();
      fd.append('file', file);

      // XHR for progress events
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/api/media/upload');
      xhr.setRequestHeader('Authorization', 'Bearer ' + (localStorage.getItem('lu_token') || ''));
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable && fill) fill.style.width = Math.round((e.loaded / e.total) * 100) + '%';
      };
      xhr.onload = async function () {
        if (xhr.status >= 200 && xhr.status < 300) {
          // Switch to uploads tab and auto-select the newly-uploaded file.
          STATE.tab = 'uploads'; STATE.page = 1;
          _mpRerenderTabs();
          await _mpLoadLibrary(false);
          // Best-effort: find the newest tile and auto-select
          var first = document.querySelector('.lu-mp-tile[data-media-id]');
          if (first) {
            var id = Number(first.getAttribute('data-media-id'));
            STATE.selected = [{ id: id }];
            _mpFillSelectedFromDOM();
            _mpRepaintSelection();
            _mpRenderFooter();
          }
        } else {
          if (err) { err.textContent = 'Upload failed (HTTP ' + xhr.status + ')'; err.style.display = 'block'; }
          if (prog) prog.style.display = 'none';
        }
      };
      xhr.onerror = function () {
        if (err) { err.textContent = 'Network error during upload.'; err.style.display = 'block'; }
        if (prog) prog.style.display = 'none';
      };
      xhr.send(fd);
    } catch (e) {
      if (err) { err.textContent = 'Upload failed: ' + (e && e.message || e); err.style.display = 'block'; }
      if (prog) prog.style.display = 'none';
    }
  }

  // ── plan-gate UI ───────────────────────────────────────────────
  function _mpLockedOverlayHtml(access) {
    var up = (access && access.upgrade_to) || 'AI Lite';
    // Blurred 6-tile sample grid sits behind the centered card as UX preview.
    var sampleGrid = '<div class="lu-mp-sample-grid" aria-hidden="true">' +
      '<div class="lu-mp-sample-tile"></div><div class="lu-mp-sample-tile"></div><div class="lu-mp-sample-tile"></div>' +
      '<div class="lu-mp-sample-tile"></div><div class="lu-mp-sample-tile"></div><div class="lu-mp-sample-tile"></div>' +
    '</div>';
    return '<div class="lu-mp-locked-wrap">' +
             sampleGrid +
             '<div class="lu-mp-locked">' +
               '<div class="lu-mp-lockIcon">\uD83D\uDD12</div>' +
               '<div class="lu-mp-lockTitle">Platform Media Library</div>' +
               '<div class="lu-mp-lockSub">Access 500+ professional images, photos and videos for your website, blog, social posts and emails.</div>' +
               '<div class="lu-mp-lockPlanLine">Available on ' + _mpEsc(up) + ' plan and above.</div>' +
               '<button class="lu-mp-btn-primary" onclick="window._mpOpenBilling()">View Plans</button>' +
               '<div class="lu-mp-lockHint">Your own uploads are always free \u2014 switch to the <strong>My Uploads</strong> tab.</div>' +
             '</div>' +
           '</div>';
  }
  function _mpUsageBarHtml(a) {
    var pct = a.limit > 0 ? Math.min(100, Math.round((a.usage / a.limit) * 100)) : 0;
    var atLimit = a.limit > 0 && a.usage >= a.limit;
    var color = atLimit ? '#DC2626' : (pct > 80 ? '#F59E0B' : 'var(--p, #6C5CE7)');
    var upLabel = a.upgrade_to ? (' \u00B7 <a onclick="window._mpOpenBilling()" style="color:var(--p);cursor:pointer">Upgrade to ' + _mpEsc(a.upgrade_to) + '</a>') : '';
    var msg = atLimit
      ? '<strong>You\u2019ve reached your ' + a.limit + '-image limit.</strong>' + upLabel
      : a.usage + ' of ' + a.limit + ' library images used' + upLabel;
    return '<div class="lu-mp-usage">' +
             '<div class="lu-mp-usageBar"><span style="width:' + pct + '%;background:' + color + '"></span></div>' +
             '<div class="lu-mp-usageText">' + msg + '</div>' +
           '</div>';
  }
  window._mpOpenBilling = function () {
    // Try to route into the main app billing/plans view. Falls back to a
    // nav event callers can listen for.
    if (typeof window.wsNavigate === 'function') { window.wsNavigate('billing'); _mpClose(); return; }
    if (typeof window.nav === 'function') { window.nav('plans'); _mpClose(); return; }
    window.location.hash = '#billing';
    _mpClose();
  };

  // ── util ───────────────────────────────────────────────────────
  function _mpFetch(url, opts) {
    opts = opts || {};
    opts.headers = opts.headers || {};
    opts.headers['Accept'] = 'application/json';
    opts.headers['Authorization'] = 'Bearer ' + (localStorage.getItem('lu_token') || '');
    return fetch(url, opts);
  }
  function _mpEsc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }
  function _mpTruncate(s, n) { if (!s) return ''; return s.length > n ? s.slice(0, n - 1) + '\u2026' : s; }
  function _mpDurFmt(s) {
    if (!s || s <= 0) return '';
    var m = Math.floor(s / 60), ss = s % 60;
    return m + ':' + (ss < 10 ? '0' : '') + ss;
  }

  function _mpCss() {
    // Refit 2026-04-19 — use the app's CSS tokens (--bg, --s1..s3, --p, --t1..t3, --bd, --rd, --ac, --fh, --fb)
    // with sensible fallbacks so the picker matches the host app's theme.
    // Tokens are defined in public/app/index.html :root and mirror what the
    // rest of the main-app UI uses.
    return [
      '.lu-mp-shell{background:var(--bg,#0F1117);border:1px solid var(--bd,rgba(255,255,255,.1));border-radius:14px;width:92%;max-width:1120px;max-height:88vh;display:flex;flex-direction:column;color:var(--t1,#E8EDF5);font-family:var(--fb,"DM Sans",system-ui,sans-serif);overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.6)}',
      '.lu-mp-head{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--bd,rgba(255,255,255,.07))}',
      '.lu-mp-title{font-size:17px;font-weight:700;letter-spacing:-.01em;font-family:var(--fh,"Syne",sans-serif);color:var(--t1,#E8EDF5)}',
      '.lu-mp-x{background:none;border:none;color:var(--t2,#8B97B0);font-size:20px;cursor:pointer;padding:4px 8px;line-height:1;border-radius:6px}',
      '.lu-mp-x:hover{color:var(--t1,#fff);background:var(--s2,#1E2230)}',
      '.lu-mp-tabs{display:flex;gap:4px;padding:10px 22px 0;border-bottom:1px solid var(--bd,rgba(255,255,255,.07));background:var(--s1,#171A21)}',
      '.lu-mp-tab{padding:10px 18px;background:transparent;border:none;color:var(--t2,#8B97B0);font-size:13px;font-weight:500;cursor:pointer;border-bottom:2px solid transparent;letter-spacing:.02em;font-family:inherit}',
      '.lu-mp-tab:hover{color:var(--t1,#fff)}',
      '.lu-mp-tab.active{color:var(--t1,#fff);border-bottom-color:var(--p,#6C5CE7)}',
      '.lu-mp-body{flex:1;overflow-y:auto;padding:20px 22px;min-height:320px}',
      '.lu-mp-foot{display:flex;justify-content:space-between;align-items:center;padding:14px 22px;border-top:1px solid var(--bd,rgba(255,255,255,.07));background:var(--s1,#171A21)}',
      '.lu-mp-footL{color:var(--t2,#8B97B0);font-size:13px}',
      '.lu-mp-footR{display:flex;gap:10px}',
      '.lu-mp-btn-primary{background:var(--p,#6C5CE7);color:#fff;border:none;padding:10px 22px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;font-family:inherit;transition:transform .12s,box-shadow .12s}',
      '.lu-mp-btn-primary:disabled{opacity:.35;cursor:not-allowed}',
      '.lu-mp-btn-primary:hover:not(:disabled){box-shadow:0 4px 18px var(--pg,rgba(108,92,231,.22));transform:translateY(-1px)}',
      '.lu-mp-btn-ghost{background:transparent;color:var(--t2,#8B97B0);border:1px solid var(--bd,rgba(255,255,255,.13));padding:10px 18px;border-radius:8px;cursor:pointer;font-size:13px;font-family:inherit}',
      '.lu-mp-btn-ghost:hover{border-color:var(--t1,#fff);color:var(--t1,#fff)}',
      '.lu-mp-btn-danger{background:var(--rd,#F87171);color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-size:13px;font-family:inherit;font-weight:600}',
      '.lu-mp-loading,.lu-mp-empty,.lu-mp-error{padding:48px;text-align:center;color:var(--t2,#8B97B0);font-size:14px}',
      '.lu-mp-error{color:var(--rd,#F87171)}',
      '.lu-mp-filter{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}',
      '.lu-mp-filter input,.lu-mp-filter select{background:var(--s2,#1E2230);border:1px solid var(--bd,rgba(255,255,255,.13));color:var(--t1,#E8EDF5);padding:8px 12px;border-radius:6px;font-size:13px;font-family:inherit;outline:none;transition:border-color .15s}',
      '.lu-mp-filter input:focus,.lu-mp-filter select:focus{border-color:var(--p,#6C5CE7)}',
      '.lu-mp-filter input{flex:1;min-width:200px}',
      '.lu-mp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}',
      '.lu-mp-tile{background:var(--s2,#1E2230);border:1px solid var(--bd,rgba(255,255,255,.07));border-radius:10px;overflow:hidden;cursor:pointer;position:relative;transition:transform .15s,border-color .15s,box-shadow .15s}',
      '.lu-mp-tile:hover{border-color:var(--p,#6C5CE7);transform:translateY(-1px);box-shadow:0 4px 16px rgba(0,0,0,.3)}',
      '.lu-mp-tile.selected{border-color:var(--p,#6C5CE7);box-shadow:0 0 0 2px var(--p,#6C5CE7)}',
      '.lu-mp-tile.locked{opacity:.55;cursor:not-allowed}',
      '.lu-mp-thumb{aspect-ratio:4/3;position:relative;background:var(--s3,#252A3A);overflow:hidden}',
      '.lu-mp-thumb img,.lu-mp-thumb video{width:100%;height:100%;object-fit:cover;display:block}',
      '.lu-mp-icon{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:var(--t3,#4A566B);font-size:32px}',
      '.lu-mp-dur{position:absolute;top:6px;right:6px;background:rgba(0,0,0,.72);color:#fff;font-size:10px;padding:3px 7px;border-radius:4px;font-weight:600;letter-spacing:.05em}',
      '.lu-mp-lock{position:absolute;inset:0;background:rgba(15,17,23,.7);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:4px;color:#fff;font-size:11px;letter-spacing:.1em;text-transform:uppercase;font-weight:600}',
      '.lu-mp-lock span:first-child{font-size:20px}',
      '.lu-mp-check{position:absolute;top:6px;left:6px;width:22px;height:22px;border-radius:50%;background:var(--p,#6C5CE7);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;border:2px solid #fff}',
      '.lu-mp-meta{padding:8px 10px;font-size:11px;color:var(--t2,#8B97B0);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.lu-mp-pager{display:flex;gap:10px;justify-content:center;align-items:center;margin:20px 0 10px;font-size:13px;color:var(--t2,#8B97B0)}',
      '.lu-mp-pager button{background:var(--s2,#1E2230);border:1px solid var(--bd,rgba(255,255,255,.13));color:var(--t1,#E8EDF5);padding:6px 14px;border-radius:6px;cursor:pointer;font-family:inherit}',
      '.lu-mp-pager button:hover:not(:disabled){border-color:var(--p,#6C5CE7)}',
      '.lu-mp-pager button:disabled{opacity:.35;cursor:not-allowed}',
      '.lu-mp-usage{background:var(--s2,#1E2230);border:1px solid var(--bd,rgba(255,255,255,.07));border-radius:10px;padding:10px 14px;margin-bottom:14px}',
      '.lu-mp-usageBar{height:6px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden;margin-bottom:8px}',
      '.lu-mp-usageBar span{display:block;height:100%;transition:width .3s ease}',
      '.lu-mp-usageText{font-size:12px;color:var(--t2,#8B97B0)}',
      '.lu-mp-unlim{background:linear-gradient(135deg,var(--ps,rgba(108,92,231,.1)),var(--pg,rgba(108,92,231,.22)));border:1px solid var(--bd2,rgba(255,255,255,.13));color:var(--pu,#A78BFA);padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;font-weight:600}',
      '.lu-mp-locked-wrap{position:relative;min-height:420px;display:flex;align-items:center;justify-content:center}',
      '.lu-mp-sample-grid{position:absolute;inset:0;display:grid;grid-template-columns:repeat(3,1fr);grid-template-rows:repeat(2,1fr);gap:8px;padding:16px;filter:blur(4px);opacity:.4;pointer-events:none}',
      '.lu-mp-sample-tile{background:linear-gradient(135deg,var(--s2,#2a2a3a) 0%,var(--s3,#3a3a4a) 100%);border-radius:8px}',
      '.lu-mp-locked{position:relative;z-index:1;padding:40px 32px;max-width:480px;text-align:center;background:var(--s1,#14171F);border:1px solid var(--bd,rgba(255,255,255,.13));border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.45)}',
      '.lu-mp-lockIcon{font-size:44px;margin-bottom:14px}',
      '.lu-mp-lockTitle{font-size:19px;color:var(--t1,#fff);font-weight:700;margin-bottom:10px;font-family:var(--fh,"Syne",sans-serif)}',
      '.lu-mp-lockSub{font-size:13.5px;color:var(--t2,#8B97B0);line-height:1.65;max-width:420px;margin:0 auto 10px}',
      '.lu-mp-lockPlanLine{font-size:12.5px;color:var(--t3,#8B97B0);margin-bottom:18px;font-style:italic}',
      '.lu-mp-lockHint{margin-top:16px;font-size:12px;color:var(--t3,#4A566B)}',
      '.lu-mp-drop{border:2px dashed var(--bd,rgba(255,255,255,.13));border-radius:12px;padding:48px 24px;text-align:center;background:var(--s2,#1E2230);transition:border-color .2s,background .2s}',
      '.lu-mp-drop.over{border-color:var(--p,#6C5CE7);background:var(--ps,rgba(108,92,231,.1))}',
      '.lu-mp-dropIcon{font-size:32px;margin-bottom:10px;color:var(--p,#6C5CE7)}',
      '.lu-mp-dropMain{font-size:14px;color:var(--t1,#E8EDF5);margin-bottom:6px}',
      '.lu-mp-dropClick{color:var(--p,#6C5CE7);cursor:pointer;text-decoration:underline}',
      '.lu-mp-dropSub{font-size:12px;color:var(--t3,#4A566B)}',
      '.lu-mp-progressBar{height:8px;background:var(--s2,#1E2230);border-radius:4px;margin:20px 0 8px;overflow:hidden}',
      '.lu-mp-progressBar span{display:block;height:100%;background:var(--p,#6C5CE7);width:0;transition:width .2s}',
      '#lu-mp-progressText{font-size:13px;color:var(--t2,#8B97B0);text-align:center}',
      /* Branded prompt/confirm dialog (replaces native prompt/confirm) */
      '.lu-dlg-overlay{position:fixed;inset:0;z-index:10050;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:20px;font-family:var(--fb,"DM Sans",system-ui,sans-serif)}',
      '.lu-dlg{background:var(--bg,#0F1117);border:1px solid var(--bd,rgba(255,255,255,.1));border-radius:12px;width:100%;max-width:440px;box-shadow:0 24px 64px rgba(0,0,0,.6);color:var(--t1,#E8EDF5);overflow:hidden}',
      '.lu-dlg-head{padding:18px 20px 6px;font-weight:700;font-size:16px;letter-spacing:-.01em;font-family:var(--fh,"Syne",sans-serif)}',
      '.lu-dlg-body{padding:6px 20px 18px;color:var(--t2,#8B97B0);font-size:13px;line-height:1.55}',
      '.lu-dlg-input{width:100%;margin-top:12px;background:var(--s2,#1E2230);border:1px solid var(--bd,rgba(255,255,255,.13));color:var(--t1,#E8EDF5);padding:10px 14px;border-radius:8px;font-size:14px;font-family:inherit;outline:none}',
      '.lu-dlg-input:focus{border-color:var(--p,#6C5CE7)}',
      '.lu-dlg-foot{display:flex;gap:8px;justify-content:flex-end;padding:12px 16px;background:var(--s1,#171A21);border-top:1px solid var(--bd,rgba(255,255,255,.07))}',
      '@media (max-width:640px){.lu-mp-grid{grid-template-columns:repeat(auto-fill,minmax(120px,1fr))}.lu-mp-tabs{padding:10px 12px 0;overflow-x:auto}.lu-mp-tab{white-space:nowrap}}',
    ].join('');
  }

  // ── Branded prompt/confirm/alert — replaces native browser dialogs ─
  // Used by blog.js (_blInsertLink), media-picker internals (_mpCopyUrl,
  // _mpDelete), and anywhere else the app needs a modal input/confirm
  // that matches the rest of the UI. Same CSS tokens as the picker.
  window.luDialog = function (opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var root = document.createElement('div');
      root.className = 'lu-dlg-overlay';
      var style = document.createElement('style');
      style.textContent = _mpCss();
      root.appendChild(style);

      var isPrompt  = opts.type === 'prompt';
      var isConfirm = opts.type === 'confirm';
      var shell = document.createElement('div');
      shell.className = 'lu-dlg';
      shell.innerHTML =
        (opts.title    ? '<div class="lu-dlg-head">' + _mpEsc(opts.title) + '</div>' : '') +
        (opts.message  ? '<div class="lu-dlg-body">' + _mpEsc(opts.message) + '</div>' : '') +
        (isPrompt ? '<div style="padding:0 20px 18px"><input class="lu-dlg-input" type="' + (opts.inputType || 'text') + '" placeholder="' + _mpEsc(opts.placeholder || '') + '" value="' + _mpEsc(opts.defaultValue || '') + '"></div>' : '') +
        '<div class="lu-dlg-foot">' +
          (isConfirm || isPrompt ? '<button class="lu-mp-btn-ghost" data-role="cancel">' + _mpEsc(opts.cancelLabel || 'Cancel') + '</button>' : '') +
          '<button class="' + (opts.danger ? 'lu-mp-btn-danger' : 'lu-mp-btn-primary') + '" data-role="ok">' + _mpEsc(opts.okLabel || 'OK') + '</button>' +
        '</div>';
      root.appendChild(shell);
      document.body.appendChild(root);

      var inp = shell.querySelector('input');
      if (inp) { inp.focus(); inp.select(); }

      var done = function (val) {
        try { root.remove(); } catch (e) {}
        window.removeEventListener('keydown', onKey);
        resolve(val);
      };
      var onKey = function (e) {
        if (e.key === 'Escape') done(isConfirm ? false : null);
        if (e.key === 'Enter')  { var okBtn = shell.querySelector('[data-role=ok]'); if (okBtn) okBtn.click(); }
      };
      window.addEventListener('keydown', onKey);

      shell.querySelector('[data-role=ok]').onclick = function () {
        if (isPrompt)  return done(inp ? inp.value : '');
        if (isConfirm) return done(true);
        done(true);
      };
      var cancelBtn = shell.querySelector('[data-role=cancel]');
      if (cancelBtn) cancelBtn.onclick = function () { done(isConfirm ? false : null); };
      root.addEventListener('click', function (e) { if (e.target === root) done(isConfirm ? false : null); });
    });
  };
  window.luPrompt  = function (title, defaultValue, placeholder) { return window.luDialog({ type: 'prompt', title: title, defaultValue: defaultValue, placeholder: placeholder }); };
  window.luConfirm = function (title, message, opts) { return window.luDialog(Object.assign({ type: 'confirm', title: title, message: message }, opts || {})); };
  window.luAlert   = function (title, message) { return window.luDialog({ type: 'alert', title: title, message: message }); };
})();
