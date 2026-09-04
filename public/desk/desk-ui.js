/* PUBLISHER888 — desk-ui.js: site-styled controls (dialogs, toasts, listbox, sheet). No native selects, no window.alert/confirm. */
(function () {
  'use strict';
  var dui = window.dui = {};
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  dui.el = el; dui.esc = esc;

  /* ---------- toast ---------- */
  var toastHost;
  dui.toast = function (msg, type, opts) {
    type = type || 'info'; opts = opts || {};
    if (!toastHost) { toastHost = el('div', 'dui-toasts'); toastHost.setAttribute('role', 'status'); toastHost.setAttribute('aria-live', 'polite'); document.body.appendChild(toastHost); }
    var t = el('div', 'dui-toast dui-toast--' + type, '<span class="dui-toast-dot"></span><span class="dui-toast-msg">' + esc(msg) + '</span>');
    toastHost.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-in'); });
    var ms = opts.duration || (type === 'error' ? 6000 : 3200);
    setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 250); }, ms);
  };

  /* ---------- dialog / sheet ---------- */
  var openDialogs = [];
  function trapTab(e, box) {
    if (e.key !== 'Tab') return;
    var f = box.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"]),[contenteditable="true"]');
    if (!f.length) return; var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
    else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
  }
  /** dui.dialog({title, body(html|Node), okLabel, cancelLabel, danger, wide, noButtons, onOpen(box)}) → Promise<true|false|value> */
  dui.dialog = function (o) {
    o = o || {};
    return new Promise(function (resolve) {
      var prev = document.activeElement;
      var ov = el('div', 'dui-ov'); var box = el('div', 'dui-dlg' + (o.wide ? ' dui-dlg--wide' : '') + (o.sheet ? ' dui-dlg--sheet' : ''));
      box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true');
      var head = el('div', 'dui-dlg-head', '<h2 class="dui-dlg-title">' + esc(o.title || '') + '</h2><button type="button" class="dui-x" aria-label="Close">&#x2715;</button>');
      var body = el('div', 'dui-dlg-body'); if (o.body instanceof Node) body.appendChild(o.body); else body.innerHTML = o.body || '';
      box.appendChild(head); box.appendChild(body);
      var foot;
      if (!o.noButtons) {
        foot = el('div', 'dui-dlg-foot');
        if (o.cancelLabel !== false) { var c = el('button', 'dui-btn dui-btn--quiet', esc(o.cancelLabel || 'Cancel')); c.type = 'button'; c.onclick = function () { close(false); }; foot.appendChild(c); }
        var ok = el('button', 'dui-btn ' + (o.danger ? 'dui-btn--danger' : 'dui-btn--primary'), esc(o.okLabel || 'OK')); ok.type = 'button';
        ok.onclick = function () { var v = o.onOk ? o.onOk(box) : true; if (v === false) return; close(v === undefined ? true : v); };
        foot.appendChild(ok); box.appendChild(foot);
      }
      ov.appendChild(box); document.body.appendChild(ov); document.body.classList.add('dui-lock');
      function close(v) { ov.classList.remove('is-in'); setTimeout(function () { ov.remove(); }, 180); openDialogs.pop(); if (!openDialogs.length) document.body.classList.remove('dui-lock'); document.removeEventListener('keydown', key); if (prev && prev.focus) prev.focus(); resolve(v); }
      function key(e) { if (e.key === 'Escape') { e.preventDefault(); close(false); } else trapTab(e, box); }
      head.querySelector('.dui-x').onclick = function () { close(false); };
      ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(false); });
      document.addEventListener('keydown', key); openDialogs.push(close);
      box.close = close;
      requestAnimationFrame(function () { ov.classList.add('is-in'); var f = box.querySelector('input,textarea,[contenteditable="true"],button.dui-btn--primary,button'); if (f) f.focus(); });
      if (o.onOpen) o.onOpen(box, close);
    });
  };
  dui.confirm = function (title, msg, opts) { opts = opts || {}; return dui.dialog({ title: title, body: '<p class="dui-p">' + esc(msg) + '</p>', okLabel: opts.okLabel || 'Confirm', danger: !!opts.danger }); };
  dui.alert = function (title, msg) { return dui.dialog({ title: title, body: '<p class="dui-p">' + esc(msg) + '</p>', cancelLabel: false, okLabel: 'OK' }); };
  dui.prompt = function (title, label, value, opts) {
    opts = opts || {};
    return dui.dialog({ title: title, body: '<label class="dui-label">' + esc(label) + '</label><input class="dui-input" type="' + (opts.type || 'text') + '" value="' + esc(value || '') + '" placeholder="' + esc(opts.placeholder || '') + '">', okLabel: opts.okLabel || 'Save',
      onOk: function (box) { var v = box.querySelector('input').value.trim(); if (opts.required && !v) { box.querySelector('input').focus(); return false; } return v; } }).then(function (v) { return v === false ? null : v; });
  };

  /* ---------- listbox (site-styled select) ---------- */
  var openList = null;
  function closeList() { if (openList) { openList(); openList = null; } }
  document.addEventListener('mousedown', function (e) { if (openList && !e.target.closest('.dui-lb, .dui-lb-pop')) closeList(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openList) closeList(); });
  /** dui.listbox(host, {options:[{value,label,hint}], value, placeholder, onChange, small, name}) → api {get,set,setOptions,el} */
  dui.listbox = function (host, o) {
    o = o || {}; var opts = o.options || []; var value = o.value == null ? '' : String(o.value);
    var btn = el('button', 'dui-lb' + (o.small ? ' dui-lb--sm' : '')); btn.type = 'button'; btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false');
    if (o.name) btn.dataset.name = o.name;
    host.innerHTML = ''; host.appendChild(btn);
    function label() { var f = opts.filter(function (x) { return String(x.value) === value; })[0]; return f ? f.label : (o.placeholder || 'Select'); }
    function render() { btn.innerHTML = '<span class="dui-lb-txt' + (opts.some(function (x) { return String(x.value) === value; }) ? '' : ' is-ph') + '">' + esc(label()) + '</span><span class="dui-lb-chev" aria-hidden="true"></span>'; }
    render();
    function open() {
      closeList();
      var pop = el('div', 'dui-lb-pop'); pop.setAttribute('role', 'listbox'); var r = btn.getBoundingClientRect();
      var below = window.innerHeight - r.bottom > 260 || r.top < 260;
      pop.style.minWidth = Math.max(r.width, 180) + 'px';
      var mobile = window.innerWidth < 640;
      if (mobile) pop.classList.add('dui-lb-pop--sheet'); else { pop.style.left = Math.min(r.left, window.innerWidth - Math.max(r.width, 180) - 12) + 'px'; if (below) pop.style.top = (r.bottom + 6) + 'px'; else pop.style.bottom = (window.innerHeight - r.top + 6) + 'px'; }
      if (mobile) pop.innerHTML = '<div class="dui-lb-sheet-head">' + esc(o.placeholder || 'Choose') + '</div>';
      var items = [];
      opts.forEach(function (x, i) {
        var it = el('div', 'dui-lb-opt' + (String(x.value) === value ? ' is-sel' : ''), '<span>' + esc(x.label) + '</span>' + (x.hint ? '<small>' + esc(x.hint) + '</small>' : '')); it.setAttribute('role', 'option'); it.tabIndex = -1; it.setAttribute('aria-selected', String(x.value) === value ? 'true' : 'false');
        it.onclick = function () { set(x.value, true); closeList(); btn.focus(); };
        pop.appendChild(it); items.push(it);
      });
      var ovl = mobile ? el('div', 'dui-lb-ovl') : null; if (ovl) document.body.appendChild(ovl);
      document.body.appendChild(pop); btn.setAttribute('aria-expanded', 'true'); requestAnimationFrame(function () { pop.classList.add('is-in'); if (ovl) ovl.classList.add('is-in'); });
      var idx = Math.max(0, opts.findIndex(function (x) { return String(x.value) === value; })); if (items[idx]) items[idx].focus();
      function nav(e) { if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(items.length - 1, idx + 1); items[idx].focus(); } else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(0, idx - 1); items[idx].focus(); } else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); items[idx].click(); } else if (e.key === 'Tab') { closeList(); } }
      pop.addEventListener('keydown', nav); if (ovl) ovl.onclick = closeList;
      openList = function () { pop.classList.remove('is-in'); if (ovl) ovl.classList.remove('is-in'); setTimeout(function () { pop.remove(); if (ovl) ovl.remove(); }, 150); btn.setAttribute('aria-expanded', 'false'); };
    }
    function set(v, fire) { var prevV = value; value = v == null ? '' : String(v); render(); if (fire && o.onChange && prevV !== value) o.onChange(value); }
    btn.onclick = function () { if (openList && btn.getAttribute('aria-expanded') === 'true') closeList(); else open(); };
    btn.onkeydown = function (e) { if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(); } };
    return { get: function () { return value; }, set: function (v) { set(v, false); }, setOptions: function (n) { opts = n; render(); }, el: btn };
  };

  /* ---------- chips (segmented filter) ---------- */
  dui.chips = function (host, items, value, onChange) {
    host.innerHTML = ''; host.className += ' dui-chips'; host.setAttribute('role', 'tablist');
    items.forEach(function (it) { var b = el('button', 'dui-chip' + (it.value === value ? ' is-on' : ''), esc(it.label) + (it.count != null ? ' <em>' + it.count + '</em>' : '')); b.type = 'button'; b.setAttribute('role', 'tab'); b.setAttribute('aria-selected', it.value === value ? 'true' : 'false'); b.onclick = function () { onChange(it.value); }; host.appendChild(b); });
  };

  /* ---------- rich text editor (contenteditable) ---------- */
  dui.editor = function (host, html, o) {
    o = o || {};
    host.innerHTML = '';
    var wrap = el('div', 'dui-ed');
    var tb = el('div', 'dui-ed-tb'); tb.setAttribute('role', 'toolbar');
    var tools = [['bold', 'B', 'Bold'], ['italic', 'I', 'Italic'], ['h2', 'H2', 'Heading'], ['h3', 'H3', 'Subheading'], ['p', '¶', 'Paragraph'], ['ul', '•', 'Bullet list'], ['ol', '1.', 'Numbered list'], ['quote', '”', 'Quote'], ['link', '🔗', 'Link'], ['image', '🖼', 'Image'], ['hr', '—', 'Divider'], ['clear', '⌫', 'Clear formatting']];
    tools.forEach(function (t) { var b = el('button', 'dui-ed-btn dui-ed-btn--' + t[0], t[1]); b.type = 'button'; b.title = t[2]; b.setAttribute('aria-label', t[2]); b.dataset.cmd = t[0]; b.onmousedown = function (e) { e.preventDefault(); }; b.onclick = function () { cmd(t[0]); }; tb.appendChild(b); });
    var area = el('div', 'dui-ed-area'); area.contentEditable = 'true'; area.setAttribute('role', 'textbox'); area.setAttribute('aria-multiline', 'true'); area.innerHTML = html || '<p></p>'; area.dataset.placeholder = o.placeholder || 'Write the story…';
    wrap.appendChild(tb); wrap.appendChild(area); host.appendChild(wrap);
    function cmd(c) {
      area.focus();
      if (c === 'bold' || c === 'italic') document.execCommand(c);
      else if (c === 'h2' || c === 'h3' || c === 'p') document.execCommand('formatBlock', false, c === 'p' ? 'p' : c);
      else if (c === 'ul') document.execCommand('insertUnorderedList');
      else if (c === 'ol') document.execCommand('insertOrderedList');
      else if (c === 'quote') document.execCommand('formatBlock', false, 'blockquote');
      else if (c === 'hr') document.execCommand('insertHorizontalRule');
      else if (c === 'clear') { document.execCommand('removeFormat'); document.execCommand('formatBlock', false, 'p'); }
      else if (c === 'link') { var sel = window.getSelection(); var saved = sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null; dui.prompt('Add link', 'URL', 'https://', { required: true }).then(function (u) { if (!u) return; area.focus(); if (saved) { sel.removeAllRanges(); sel.addRange(saved); } if (sel.isCollapsed) document.execCommand('insertHTML', false, '<a href="' + esc(u) + '">' + esc(u) + '</a>'); else document.execCommand('createLink', false, u); fire(); }); return; }
      else if (c === 'image') { var sel2 = window.getSelection(); var saved2 = sel2.rangeCount ? sel2.getRangeAt(0).cloneRange() : null; (o.pickImage ? o.pickImage() : dui.prompt('Insert image', 'Image URL', '', { required: true })).then(function (u) { if (!u) return; area.focus(); if (saved2) { sel2.removeAllRanges(); sel2.addRange(saved2); } document.execCommand('insertHTML', false, '<figure><img src="' + esc(u) + '" alt=""><figcaption></figcaption></figure><p></p>'); fire(); }); return; }
      fire();
    }
    function fire() { if (o.onChange) o.onChange(); }
    area.addEventListener('input', fire);
    area.addEventListener('paste', function (e) { e.preventDefault(); var h = (e.clipboardData || window.clipboardData).getData('text/html'); var t = (e.clipboardData || window.clipboardData).getData('text/plain'); if (h) { var d = document.createElement('div'); d.innerHTML = h; d.querySelectorAll('script,style,meta,link,iframe').forEach(function (n) { n.remove(); }); d.querySelectorAll('*').forEach(function (n) { Array.prototype.slice.call(n.attributes).forEach(function (a) { if (!/^(href|src|alt)$/i.test(a.name)) n.removeAttribute(a.name); }); }); document.execCommand('insertHTML', false, d.innerHTML); } else document.execCommand('insertText', false, t); fire(); });
    area.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') { e.preventDefault(); cmd('bold'); } if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'i') { e.preventDefault(); cmd('italic'); } if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); cmd('link'); } });
    return { getHTML: function () { return area.innerHTML.replace(/<p><\/p>$/, ''); }, setHTML: function (h) { area.innerHTML = h || '<p></p>'; }, words: function () { return (area.innerText || '').trim().split(/\s+/).filter(Boolean).length; }, el: area };
  };

  /* ---------- helpers ---------- */
  dui.rel = function (iso) { if (!iso) return ''; var d = new Date(iso.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(iso) ? '' : 'Z')); if (isNaN(d)) d = new Date(iso); var s = (Date.now() - d.getTime()) / 1000; if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago'; if (s < 86400) return Math.floor(s / 3600) + ' h ago'; if (s < 86400 * 7) return Math.floor(s / 86400) + ' d ago'; return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: d.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined }); };
  dui.fmt = function (iso) { if (!iso) return ''; var d = new Date(iso.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(iso) ? '' : 'Z')); if (isNaN(d)) d = new Date(iso); return d.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  dui.debounce = function (fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms || 300); }; };
})();
