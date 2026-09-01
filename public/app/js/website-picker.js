/*
 * INC-0006 — the website selector.
 *
 * It sits in the shell rather than inside any one engine, because the context it controls is shared: SEO,
 * the chatbot, WordPress, the builder and publishing all act on the same chosen website. A selector that
 * lived in the SEO engine would have made the same mistake in a nicer wrapper.
 *
 * Two rules from the brief shape the whole thing:
 *
 *   A one-site business is never given a choice to make. The control renders nothing at all — no label, no
 *   disabled dropdown, no "1 of 1". There is one website and everything acts on it.
 *
 *   A multi-site business must always be able to see which website is active. When nothing is chosen the
 *   control says so plainly and asks, because the alternative — showing a site that was picked for them —
 *   is the defect this incident is about.
 *
 * The menu is built from site CSS, not a native <select>: native controls cannot be styled to match the
 * shell on every platform, and this one has to sit inside the sidebar looking like it belongs there.
 */
(function () {
  'use strict';

  var MOUNT_ID = 'lu-website-picker';
  var open = false;

  function css() {
    if (document.getElementById('lu-wp-css')) { return; }
    var s = document.createElement('style');
    s.id = 'lu-wp-css';
    s.textContent = [
      '#' + MOUNT_ID + '{padding:8px 10px;border-bottom:1px solid var(--bd);position:relative}',
      '#' + MOUNT_ID + '[hidden]{display:none}',
      '.luwp-label{font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;',
      '  letter-spacing:.06em;margin-bottom:5px;display:block}',
      '.luwp-btn{width:100%;display:flex;align-items:center;gap:8px;background:var(--s3);',
      '  border:1px solid var(--bd);border-radius:6px;padding:7px 9px;cursor:pointer;text-align:left;',
      '  color:var(--t1);font:inherit;font-size:12px;line-height:1.3;min-height:34px}',
      '.luwp-btn:hover{border-color:var(--ac)}',
      '.luwp-btn:focus-visible{outline:2px solid var(--ac);outline-offset:2px}',
      '.luwp-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}',
      '.luwp-none{color:var(--t3);font-weight:500;font-style:italic}',
      '.luwp-chev{flex-shrink:0;opacity:.6;transition:transform .18s}',
      '.luwp-btn[aria-expanded="true"] .luwp-chev{transform:rotate(180deg)}',
      '.luwp-menu{position:absolute;left:10px;right:10px;top:calc(100% - 4px);z-index:var(--z-dropdown,100);',
      '  background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:4px;',
      '  box-shadow:0 12px 32px rgba(0,0,0,.35);max-height:280px;overflow-y:auto}',
      '.luwp-menu[hidden]{display:none}',
      '.luwp-item{width:100%;display:flex;align-items:center;gap:8px;background:none;border:0;',
      '  border-radius:5px;padding:8px 9px;cursor:pointer;text-align:left;color:var(--t1);',
      '  font:inherit;font-size:12px;min-height:34px}',
      '.luwp-item:hover{background:var(--s3)}',
      '.luwp-item:focus-visible{outline:2px solid var(--ac);outline-offset:-2px}',
      '.luwp-item[aria-selected="true"]{background:var(--ps);color:var(--p);font-weight:700}',
      '.luwp-host{display:block;font-size:10px;color:var(--t3);font-weight:400;margin-top:1px;',
      '  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '.luwp-item[aria-selected="true"] .luwp-host{color:var(--p);opacity:.75}',
      '.luwp-warn{margin-top:6px;font-size:11px;color:var(--dg,#e5484d);line-height:1.35}'
    ].join('');
    document.head.appendChild(s);
  }

  function chevron() {
    return '<svg class="luwp-chev" width="12" height="12" viewBox="0 0 20 20" fill="none" ' +
           'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' +
           'aria-hidden="true"><path d="M5 8l5 5 5-5"/></svg>';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function mount() {
    var el = document.getElementById(MOUNT_ID);
    if (el) { return el; }

    var toggle = document.getElementById('sb-mode-toggle');
    if (!toggle || !toggle.parentNode) { return null; }

    el = document.createElement('div');
    el.id = MOUNT_ID;
    el.setAttribute('data-adv', '1');   // an Advanced-shell control; Basic hides it with the rest
    el.hidden = true;
    toggle.parentNode.insertBefore(el, toggle.nextSibling);
    return el;
  }

  function render() {
    var el = mount();
    if (!el || !window.LU_Website) { return; }

    var W = window.LU_Website;
    var sites = W.list();

    // One website is not a decision. Render nothing rather than a control with a single option.
    if (sites.length < 2) { el.hidden = true; el.innerHTML = ''; return; }

    var cur = W.currentSite();
    var err = W.lastError();

    el.hidden = false;
    el.innerHTML =
      '<span class="luwp-label" id="luwp-lbl">Working on</span>' +
      '<button type="button" class="luwp-btn" id="luwp-trigger" aria-haspopup="listbox" ' +
        'aria-expanded="false" aria-labelledby="luwp-lbl luwp-current">' +
        '<span class="luwp-name' + (cur ? '' : ' luwp-none') + '" id="luwp-current">' +
          (cur ? esc(cur.name) : 'Choose a website') +
        '</span>' + chevron() +
      '</button>' +
      '<div class="luwp-menu" id="luwp-menu" role="listbox" aria-labelledby="luwp-lbl" hidden>' +
        sites.map(function (w) {
          return '<button type="button" class="luwp-item" role="option" data-id="' + w.id + '" ' +
                 'aria-selected="' + (cur && cur.id === w.id ? 'true' : 'false') + '">' +
                 '<span style="flex:1;min-width:0"><span class="luwp-name">' + esc(w.name) + '</span>' +
                 (w.host ? '<span class="luwp-host">' + esc(w.host) + '</span>' : '') +
                 '</span></button>';
        }).join('') +
      '</div>' +
      (err === 'WEBSITE_NOT_IN_WORKSPACE'
        ? '<p class="luwp-warn" role="status">That website isn’t part of this business, so nothing was ' +
          'selected. Pick one above.</p>'
        : '');

    wire(el);
  }

  function wire(el) {
    var trigger = el.querySelector('#luwp-trigger');
    var menu = el.querySelector('#luwp-menu');
    if (!trigger || !menu) { return; }

    function setOpen(v) {
      open = v;
      menu.hidden = !v;
      trigger.setAttribute('aria-expanded', v ? 'true' : 'false');
      if (v) {
        var sel = menu.querySelector('[aria-selected="true"]') || menu.querySelector('.luwp-item');
        if (sel) { sel.focus(); }
      }
    }

    trigger.addEventListener('click', function (e) { e.stopPropagation(); setOpen(!open); });

    menu.addEventListener('click', function (e) {
      var item = e.target.closest ? e.target.closest('.luwp-item') : null;
      if (!item) { return; }
      e.stopPropagation();
      window.LU_Website.set(parseInt(item.getAttribute('data-id'), 10));
      setOpen(false);
      trigger.focus();
    });

    // Keyboard: the menu is a listbox, so arrows move and Escape closes back onto the trigger.
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && open) { e.preventDefault(); setOpen(false); trigger.focus(); return; }
      if (!open) {
        if (e.target === trigger && (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ')) {
          e.preventDefault(); setOpen(true);
        }
        return;
      }
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') { return; }
      e.preventDefault();
      var items = Array.prototype.slice.call(menu.querySelectorAll('.luwp-item'));
      var i = items.indexOf(document.activeElement);
      var next = e.key === 'ArrowDown' ? (i + 1) % items.length : (i <= 0 ? items.length - 1 : i - 1);
      items[next].focus();
    });

    document.addEventListener('click', function () { if (open) { setOpen(false); } });
  }

  function boot() {
    css();
    if (!window.LU_Website) { return; }
    window.LU_Website.ready().then(render);
    window.LU_Website.onChange(render);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  window.LU_WebsitePicker = { render: render };
})();
