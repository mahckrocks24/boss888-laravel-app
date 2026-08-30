/* ═══════════════════════════════════════════════════════════════
   lu-attach-composer.js — universal attachment widget (v1.4.4)
   ═══════════════════════════════════════════════════════════════
   Retrofits any chat textarea with an enterprise-grade attach UX:
     • Paperclip button placed adjacent to the textarea (auto-layout)
     • Hidden file input — image / video / pdf / docx / xlsx / csv / txt
     • Chip preview row above the textarea (auto-created)
     • Per-file upload to {uploadUrl} with progress overlay
     • Size caps enforced client-side BEFORE upload
     • Public API:
         LU_attachComposer.bind('textareaId', { uploadUrl, headers, accept })
         LU_attachComposer.getPending('textareaId') -> uploaded refs [{media_id, kind, ...}]
         LU_attachComposer.hasUploads('textareaId')
         LU_attachComposer.isBusy('textareaId')   // any still uploading
         LU_attachComposer.clear('textareaId')

   Used by:
     • Web SPA  — index.html (agent-msg-input, dm-ta, ai-input)
     • WP admin — Connector v1.3.8 admin-shell.js (lgsc-chat-input)

   Honors locked design system: uses :root vars when present.
   ═══════════════════════════════════════════════════════════════ */
;(function () {
  'use strict';
  if (window.LU_attachComposer) return;

  var DEFAULT_ACCEPT = 'image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt';
  var CAPS = { image: 100 * 1024 * 1024, video: 200 * 1024 * 1024, document: 50 * 1024 * 1024, audio: 100 * 1024 * 1024 };

  var _pending = {};
  var _bound   = {};
  var _opts    = {};

  function _ensureStyles() {
    if (document.getElementById('lu-attach-composer-css')) return;
    var css = ''
      + '.lu-att-paperclip{flex-shrink:0;width:34px;height:34px;border-radius:50%;'
      + 'background:var(--s2,#1A1B24);border:1px solid var(--bd,rgba(255,255,255,.08));'
      + 'color:var(--t2,#C8CCD8);cursor:pointer;display:inline-flex;align-items:center;'
      + 'justify-content:center;transition:all .15s ease;padding:0}'
      + '.lu-att-paperclip:hover{border-color:var(--p,#6C5CE7);color:var(--pu,#8B7CF0);'
      + 'background:rgba(108,92,231,.08)}'
      + '.lu-att-paperclip svg{width:15px;height:15px;display:block}'
      + '.lu-att-chiprow{display:flex;flex-wrap:wrap;gap:6px;padding:6px 0;'
      + 'max-height:124px;overflow-y:auto}'
      + '.lu-att-chiprow:empty{display:none;padding:0}'
      + '.lu-att-chip{position:relative;display:inline-flex;align-items:center;gap:8px;'
      + 'background:var(--s3,#22232E);border:1px solid var(--bd,rgba(255,255,255,.08));'
      + 'border-radius:10px;padding:5px 9px 5px 6px;font-size:11px;'
      + 'color:var(--t1,#fff);max-width:220px;min-width:86px;overflow:hidden}'
      + '.lu-att-chip.uploading{opacity:.88}'
      + '.lu-att-chip.failed{border-color:rgba(239,68,68,.45);'
      + 'background:rgba(239,68,68,.08)}'
      + '.lu-att-chip .lu-att-thumb{width:26px;height:26px;border-radius:6px;'
      + 'background:var(--s4,#2A2C38);flex-shrink:0;overflow:hidden;'
      + 'display:flex;align-items:center;justify-content:center;'
      + 'font-size:9px;font-weight:800;color:var(--t2,#C8CCD8);letter-spacing:.3px}'
      + '.lu-att-chip .lu-att-thumb img{width:100%;height:100%;object-fit:cover}'
      + '.lu-att-chip .lu-att-meta{flex:1;min-width:0;line-height:1.2}'
      + '.lu-att-chip .lu-att-name{font-weight:600;color:var(--t1,#fff);'
      + 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px}'
      + '.lu-att-chip .lu-att-sub{font-size:10px;color:var(--t3,#8A8FA0);margin-top:2px}'
      + '.lu-att-chip.failed .lu-att-sub{color:#ef4444}'
      + '.lu-att-chip .lu-att-x{flex-shrink:0;width:18px;height:18px;border-radius:50%;'
      + 'background:rgba(255,255,255,.08);color:var(--t2,#C8CCD8);cursor:pointer;'
      + 'display:flex;align-items:center;justify-content:center;font-size:10px;'
      + 'border:none;padding:0}'
      + '.lu-att-chip .lu-att-x:hover{background:rgba(239,68,68,.18);color:#ef4444}'
      + '.lu-att-chip .lu-att-bar{position:absolute;left:0;bottom:0;height:2px;'
      + 'background:var(--p,#6C5CE7);border-bottom-left-radius:10px;'
      + 'border-bottom-right-radius:10px;transition:width .2s ease}'
      + '/* WP admin overrides (light theme) */'
      + '.wp-admin .lu-att-paperclip{background:#fff;border:1px solid #c3c4c7;color:#50575e}'
      + '.wp-admin .lu-att-paperclip:hover{border-color:#6C5CE7;color:#6C5CE7;background:#f6f3ff}'
      + '.wp-admin .lu-att-chip{background:#f6f7f7;border:1px solid #dcdcde;color:#1d2327}'
      + '.wp-admin .lu-att-chip .lu-att-thumb{background:#dcdcde;color:#50575e}'
      + '.wp-admin .lu-att-chip .lu-att-sub{color:#646970}'
      + '.wp-admin .lu-att-chip .lu-att-x{background:#dcdcde;color:#50575e}';
    var s = document.createElement('style');
    s.id = 'lu-attach-composer-css';
    s.textContent = css;
    document.head.appendChild(s);
  }

  var PAPERCLIP_SVG =
    '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" '
    + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M14.5 8.5l-6 6a2.5 2.5 0 01-3.54-3.54l7.07-7.07a1.5 1.5 0 012.12 2.12L7.09 13.07a.5.5 0 01-.71-.71L10.5 8.25"/>'
    + '</svg>';

  function _kindFromMime(mime) {
    mime = (mime || '').toLowerCase();
    if (mime.indexOf('image/') === 0) return 'image';
    if (mime.indexOf('video/') === 0) return 'video';
    if (mime.indexOf('audio/') === 0) return 'audio';
    return 'document';
  }
  function _humanSize(n) {
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
    return (n / 1073741824).toFixed(2) + ' GB';
  }
  function _esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]);
    });
  }
  function _extLabel(name, mime) {
    var n = (name || '').toLowerCase();
    var m = (mime || '').toLowerCase();
    if (n.endsWith('.pdf') || m === 'application/pdf') return 'PDF';
    if (n.endsWith('.doc') || n.endsWith('.docx')) return 'DOC';
    if (n.endsWith('.xls') || n.endsWith('.xlsx')) return 'XLS';
    if (n.endsWith('.csv')) return 'CSV';
    if (n.endsWith('.txt')) return 'TXT';
    if (n.endsWith('.md'))  return 'MD';
    return 'FILE';
  }
  function _toast(msg) {
    window.showToast(msg, 'warning');
  }

  function bind(textareaId, opts) {
    opts = opts || {};
    if (_bound[textareaId]) return;
    var ta = document.getElementById(textareaId);
    if (!ta) return;

    _ensureStyles();
    _pending[textareaId] = [];
    _opts[textareaId] = {
      uploadUrl: opts.uploadUrl || '/api/media/upload',
      headers:   opts.headers || {},
      accept:    opts.accept || DEFAULT_ACCEPT,
      onChange:  typeof opts.onChange === 'function' ? opts.onChange : null
    };
    _bound[textareaId] = true;

    var parent = ta.parentElement;
    if (!parent) return;

    var parentStyle = window.getComputedStyle(parent);
    var isColumn = parentStyle.flexDirection === 'column' || parentStyle.flexDirection === 'column-reverse';

    var chipMountTarget;
    var btnInsertBefore;

    if (isColumn) {
      var row = document.createElement('div');
      row.style.cssText = 'display:flex;gap:6px;align-items:flex-end;width:100%';
      parent.insertBefore(row, ta);
      row.appendChild(ta);
      btnInsertBefore = ta;
      chipMountTarget = parent;
    } else {
      btnInsertBefore = ta;
      chipMountTarget = parent.parentElement || parent;
    }

    var chipRow = document.createElement('div');
    chipRow.className = 'lu-att-chiprow';
    chipRow.id = 'lu-att-chiprow-' + textareaId;
    if (isColumn) {
      parent.insertBefore(chipRow, parent.firstChild);
    } else {
      var anchor = parent;
      chipMountTarget.insertBefore(chipRow, anchor);
    }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lu-att-paperclip';
    btn.title = 'Attach a file';
    btn.setAttribute('aria-label', 'Attach a file');
    btn.innerHTML = PAPERCLIP_SVG;

    var fileEl = document.createElement('input');
    fileEl.type = 'file';
    fileEl.accept = _opts[textareaId].accept;
    fileEl.multiple = true; // ATTACH-1: several at once
    fileEl.style.display = 'none';
    _fileEl[textareaId] = fileEl;

    btn.onclick = function (e) { e.preventDefault(); fileEl.click(); };
    fileEl.onchange = function (e) {
      addFiles(textareaId, e.target.files);
      e.target.value = '';
    };

    btnInsertBefore.parentElement.insertBefore(btn, btnInsertBefore);
    btnInsertBefore.parentElement.appendChild(fileEl);
  }

  /* ATTACH-1: programmatic entry points — a host's own button opens the native chooser; a host can hand over files. */
  var _fileEl = {};
  function pick(textareaId) {
    if (!_bound[textareaId] && document.getElementById(textareaId)) bind(textareaId, _observed[textareaId] || {});
    var el = _fileEl[textareaId];
    if (!el) return false;
    el.click();
    return true;
  }
  function addFiles(textareaId, files) {
    if (!files || !files.length) return 0;
    var n = 0;
    for (var i = 0; i < files.length; i++) { if (files[i]) { _addAttachment(textareaId, files[i]); n++; } }
    return n;
  }

  function getPending(textareaId) {
    var list = _pending[textareaId] || [];
    var out = [];
    for (var i = 0; i < list.length; i++) {
      var a = list[i];
      if (a.status === 'uploaded') {
        out.push({
          media_id: a.mediaId,
          kind:     a.kind,
          name:     a.name,
          size:     a.size,
          mime:     a.mime,
          url:      a.previewUrl || null
        });
      }
    }
    return out;
  }
  function hasUploads(textareaId) {
    var list = _pending[textareaId] || [];
    for (var i = 0; i < list.length; i++) if (list[i].status === 'uploaded') return true;
    return false;
  }
  function isBusy(textareaId) {
    var list = _pending[textareaId] || [];
    for (var i = 0; i < list.length; i++) if (list[i].status === 'uploading') return true;
    return false;
  }
  function clear(textareaId) {
    _pending[textareaId] = [];
    _renderChips(textareaId);
  }

  function _addAttachment(textareaId, file) {
    var kind = _kindFromMime(file.type);
    var cap = CAPS[kind] || CAPS.document;
    if (file.size > cap) {
      _toast(file.name + ' is ' + _humanSize(file.size) + ' — cap for ' + kind + ' is ' + _humanSize(cap) + '.');
      return;
    }
    var att = {
      id:       'a-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6),
      kind:     kind,
      name:     file.name,
      size:     file.size,
      mime:     file.type || 'application/octet-stream',
      file:     file,
      dataUrl:  null,
      status:   'uploading',
      progress: 0,
      mediaId:  null,
      previewUrl: null,
      error:    null
    };
    _pending[textareaId].push(att);
    if (kind === 'image') {
      var r = new FileReader();
      r.onload = function () { att.dataUrl = r.result; _renderChips(textareaId); };
      r.readAsDataURL(file);
    }
    _renderChips(textareaId);
    _upload(textareaId, att);
  }

  function _upload(textareaId, att) {
    var conf = _opts[textareaId];
    var fd = new FormData();
    fd.append('file', att.file);
    fd.append('kind', att.kind);
    fd.append('source', 'web-spa');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', conf.uploadUrl);
    var hdrs = conf.headers || {};
    for (var k in hdrs) if (hdrs.hasOwnProperty(k) && hdrs[k]) xhr.setRequestHeader(k, hdrs[k]);
    try {
      var tok = localStorage.getItem('lu_token') || localStorage.getItem('jwt') || '';
      if (tok && !hdrs['Authorization']) xhr.setRequestHeader('Authorization', 'Bearer ' + tok);
    } catch (_) {}
    xhr.upload.onprogress = function (e) {
      if (e.lengthComputable) {
        att.progress = e.loaded / e.total;
        _renderChips(textareaId);
      }
    };
    xhr.onload = function () {
      if (xhr.status >= 200 && xhr.status < 300) {
        var json = {};
        try { json = JSON.parse(xhr.responseText); } catch (_) {}
        var media = (json && json.media) || json || {};
        att.status   = 'uploaded';
        att.progress = 1;
        att.mediaId  = (media && (media.id || media.media_id)) || null;
        att.previewUrl = (media && (media.preview_url || media.url || media.file_url)) || null;
        if (_opts[textareaId].onChange) try { _opts[textareaId].onChange(textareaId); } catch (_) {}
      } else {
        att.status = 'failed';
        var msg = 'Upload failed';
        try {
          var j = JSON.parse(xhr.responseText);
          msg = j.error || (j.message) || msg;
        } catch (_) {}
        att.error = msg;
      }
      _renderChips(textareaId);
    };
    xhr.onerror = function () {
      att.status = 'failed';
      att.error  = 'Network error';
      _renderChips(textareaId);
    };
    xhr.send(fd);
  }

  function _renderChips(textareaId) {
    var row = document.getElementById('lu-att-chiprow-' + textareaId);
    if (!row) return;
    var list = _pending[textareaId] || [];
    var html = '';
    for (var i = 0; i < list.length; i++) html += _chipHtml(list[i]);
    row.innerHTML = html;
    var xs = row.querySelectorAll('[data-x]');
    for (var j = 0; j < xs.length; j++) {
      xs[j].onclick = (function (id) {
        return function () {
          _pending[textareaId] = (_pending[textareaId] || []).filter(function (x) { return x.id !== id; });
          _renderChips(textareaId);
        };
      })(xs[j].getAttribute('data-x'));
    }
  }

  function _chipHtml(a) {
    var klass = 'lu-att-chip' + (a.status === 'uploading' ? ' uploading' : a.status === 'failed' ? ' failed' : '');
    var thumb;
    if (a.kind === 'image' && a.dataUrl) {
      thumb = '<div class="lu-att-thumb"><img src="' + a.dataUrl + '" alt=""></div>';
    } else {
      var label =
        a.kind === 'image' ? 'IMG' :
        a.kind === 'video' ? 'VID' :
        a.kind === 'audio' ? 'AUD' :
        _extLabel(a.name, a.mime);
      thumb = '<div class="lu-att-thumb">' + label + '</div>';
    }
    var sub = a.status === 'failed'
      ? _esc(a.error || 'Failed')
      : a.status === 'uploading'
        ? Math.round((a.progress || 0) * 100) + '% · uploading…'
        : _humanSize(a.size);
    var bar = a.status === 'uploading'
      ? '<div class="lu-att-bar" style="width:' + Math.round((a.progress || 0) * 100) + '%"></div>'
      : '';
    return ''
      + '<div class="' + klass + '">'
      +   thumb
      +   '<div class="lu-att-meta">'
      +     '<div class="lu-att-name" title="' + _esc(a.name) + '">' + _esc(a.name) + '</div>'
      +     '<div class="lu-att-sub">' + sub + '</div>'
      +   '</div>'
      +   '<button type="button" class="lu-att-x" data-x="' + a.id + '" title="Remove" aria-label="Remove">&times;</button>'
      +   bar
      + '</div>';
  }

  // ── Lazy / observed binding for dynamically-rendered chat surfaces ──
  // Some surfaces (lu-msg-input + lu-msg-page-input from messages-ui.js)
  // are created via createElement + innerHTML AFTER DOMContentLoaded,
  // so a plain bind() call at boot can't find them. observe() registers
  // an ID + options and uses a MutationObserver to bind the element as
  // soon as it appears anywhere in the document.
  var _observed = {};
  var _mo = null;

  function _tryBindAllObserved() {
    Object.keys(_observed).forEach(function (id) {
      if (_bound[id]) return;
      if (document.getElementById(id)) bind(id, _observed[id]);
    });
  }

  function observe(textareaId, opts) {
    _observed[textareaId] = opts || {};
    if (document.getElementById(textareaId)) {
      bind(textareaId, opts || {});
      return;
    }
    if (!_mo) {
      _mo = new MutationObserver(function () { _tryBindAllObserved(); });
      _mo.observe(document.body || document.documentElement, { childList: true, subtree: true });
    }
  }

  window.LU_attachComposer = {
    bind:        bind,
    observe:     observe,
    pick:        pick,
    addFiles:    addFiles,
    getPending:  getPending,
    hasUploads:  hasUploads,
    isBusy:      isBusy,
    clear:       clear,
    _kindFromMime: _kindFromMime
  };
})();
