/* lu-tour.js — TOUR-1 (RFC-0013, Owner go 2026-09-23): a spotlight-and-coach-mark product tour in the app's own tokens.
   luTour.start(steps, opts) · luTour.next() · luTour.back() · luTour.skip() · luTour.end()
   step: { title, body, target?: selector | fn -> element, placement?: 'bottom'|'top', waitFor?: message type, tryLabel?: string }
   opts: { onEvent(name, info), onFinish(kind, lastStep), version } */
(function () {
  if (window.luTour) return;
  var S = { steps: [], i: 0, opts: {}, active: false, els: null, paused: false, waitHandler: null };

  function css() {
    if (document.getElementById('lu-tour-css')) return;
    var st = document.createElement('style'); st.id = 'lu-tour-css';
    st.textContent =
      '.lu-tour-pane{position:fixed;transition:none!important;background:rgba(6,8,14,.6);z-index:100200;left:0;top:0;width:0;height:0}' +
      '.lu-tour-pane.free{pointer-events:none}' +
      '.lu-tour-ring{position:fixed;transition:none!important;z-index:100201;border:2px solid var(--p,#6C5CE7);border-radius:10px;box-shadow:0 0 0 4px rgba(108,92,231,.28);pointer-events:none;display:none;animation:luTourPulse 1.6s ease-in-out infinite}' +
      '@keyframes luTourPulse{0%,100%{box-shadow:0 0 0 4px rgba(108,92,231,.28)}50%{box-shadow:0 0 0 10px rgba(108,92,231,.1)}}' +
      '.lu-tour-card{position:fixed;transition:none!important;z-index:100202;width:min(360px,calc(100vw - 24px));background:var(--s1,#171A21);color:var(--t1,#E8EDF5);border:1px solid var(--bd,rgba(255,255,255,.1));border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.45);padding:16px 16px 12px;font-family:var(--fb,system-ui,sans-serif);box-sizing:border-box}' +
      '.lu-tour-card h3{margin:0 0 6px;font:700 15px var(--fh,inherit);letter-spacing:-.01em}' +
      '.lu-tour-card p{margin:0 0 12px;font-size:13px;line-height:1.55;color:var(--t2,#B6BFCF)}' +
      '.lu-tour-dots{display:flex;gap:5px;margin-bottom:10px}.lu-tour-dots i{width:6px;height:6px;border-radius:50%;background:var(--bd,#333)}.lu-tour-dots i.on{background:var(--p,#6C5CE7)}' +
      '.lu-tour-foot{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.lu-tour-step{font-size:11px;color:var(--t3,#8B97B0);flex:1 1 auto}' +
      '.lu-tour-btn{border:1px solid var(--bd,rgba(255,255,255,.12));background:var(--s2,#1E2230);color:var(--t1,#E8EDF5);border-radius:8px;padding:7px 12px;font:inherit;font-size:12.5px;cursor:pointer;min-height:36px}' +
      '.lu-tour-btn.primary{background:var(--p,#6C5CE7);border-color:var(--p,#6C5CE7);color:#fff}.lu-tour-btn.ghost{background:none;border-color:transparent;color:var(--t3,#8B97B0)}' +
      '.lu-tour-btn:focus-visible{outline:2px solid var(--p,#6C5CE7);outline-offset:2px}' +
      '@media (max-width:820px){.lu-tour-card{left:8px!important;right:8px!important;width:auto!important;top:auto!important;bottom:calc(8px + env(safe-area-inset-bottom))!important;max-height:46dvh;overflow:auto}}' +
      '@media (prefers-reduced-motion:reduce){.lu-tour-ring{animation:none}}' +
      'html.lu-kb-open .lu-tour-pane,html.lu-kb-open .lu-tour-ring,html.lu-kb-open .lu-tour-card{display:none!important}';
    document.head.appendChild(st);
  }

  function build() {
    css();
    var mk = function (cls) { var d = document.createElement('div'); d.className = cls; document.body.appendChild(d); return d; };
    S.els = { panes: [mk('lu-tour-pane'), mk('lu-tour-pane'), mk('lu-tour-pane'), mk('lu-tour-pane')], ring: mk('lu-tour-ring'), card: mk('lu-tour-card') };
    S.els.card.setAttribute('role', 'dialog'); S.els.card.setAttribute('aria-modal', 'true'); S.els.card.setAttribute('aria-labelledby', 'lu-tour-title');
    S.els.panes.forEach(function (p) { p.addEventListener('click', function (e) { e.stopPropagation(); }); });
  }

  function destroy() {
    if (!S.els) return;
    S.els.panes.forEach(function (p) { try { p.remove(); } catch (e) {} }); try { S.els.ring.remove(); } catch (e) {} try { S.els.card.remove(); } catch (e) {}
    S.els = null;
  }

  function resolveTarget(step) {
    var t = null;
    try { t = typeof step.target === 'function' ? step.target() : (step.target ? document.querySelector(step.target) : null); } catch (e) { t = null; }
    if (t && t.getBoundingClientRect().width === 0 && t.getBoundingClientRect().height === 0) t = null;
    return t;
  }

  function layout() {
    if (!S.active || !S.els) return;
    var step = S.steps[S.i], t = resolveTarget(step), vw = window.innerWidth, vh = window.innerHeight;
    var panes = S.els.panes, ring = S.els.ring, card = S.els.card;
    var free = !!step.waitFor;
    panes.forEach(function (p) { p.classList.toggle('free', free); });
    if (t) {
      try { t.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (e) {}
      var r = t.getBoundingClientRect(), x = Math.max(0, r.left - 6), y = Math.max(0, r.top - 6), w = Math.min(vw - x, r.width + 12), h = Math.min(vh - y, r.height + 12);
      var set = function (p, l, tp, ww, hh) { p.style.left = l + 'px'; p.style.top = tp + 'px'; p.style.width = Math.max(0, ww) + 'px'; p.style.height = Math.max(0, hh) + 'px'; p.style.display = 'block'; };
      set(panes[0], 0, 0, vw, y); set(panes[1], 0, y, x, h); set(panes[2], x + w, y, vw - x - w, h); set(panes[3], 0, y + h, vw, vh - y - h);
      ring.style.left = x + 'px'; ring.style.top = y + 'px'; ring.style.width = w + 'px'; ring.style.height = h + 'px'; ring.style.display = 'block';
      var mobile = window.matchMedia && window.matchMedia('(max-width:820px)').matches;
      if (!mobile) {
        card.style.visibility = 'hidden'; card.style.display = 'block';
        var cw = card.offsetWidth, ch = card.offsetHeight;
        var below = y + h + 12, above = y - 12 - ch;
        var top = (step.placement === 'top' || below + ch > vh - 8) && above >= 8 ? above : Math.min(below, vh - ch - 8);
        var left = Math.min(Math.max(12, x + w / 2 - cw / 2), vw - cw - 12);
        card.style.top = top + 'px'; card.style.left = left + 'px'; card.style.bottom = ''; card.style.visibility = '';
      } else { card.style.top = ''; card.style.left = ''; card.style.display = 'block'; }
    } else {
      panes[0].style.left = '0'; panes[0].style.top = '0'; panes[0].style.width = vw + 'px'; panes[0].style.height = vh + 'px'; panes[0].style.display = 'block';
      panes.slice(1).forEach(function (p) { p.style.display = 'none'; }); ring.style.display = 'none';
      var mob = window.matchMedia && window.matchMedia('(max-width:820px)').matches;
      card.style.display = 'block';
      if (!mob) { card.style.visibility = 'hidden'; var cw2 = card.offsetWidth, ch2 = card.offsetHeight; card.style.left = Math.max(12, (vw - cw2) / 2) + 'px'; card.style.top = Math.max(12, (vh - ch2) / 2) + 'px'; card.style.bottom = ''; card.style.visibility = ''; }
    }
  }

  function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  function render() {
    var step = S.steps[S.i], n = S.steps.length, last = S.i === n - 1, card = S.els.card;
    var dots = ''; for (var k = 0; k < n; k++) dots += '<i class="' + (k === S.i ? 'on' : '') + '"></i>';
    card.innerHTML = '<h3 id="lu-tour-title">' + esc(step.title) + '</h3><p>' + (step.html ? step.html : esc(step.body)) + '</p>'
      + '<div class="lu-tour-dots" aria-hidden="true">' + dots + '</div>'
      + '<div class="lu-tour-foot"><span class="lu-tour-step">' + (S.i + 1) + ' of ' + n + '</span>'
      + (S.i > 0 ? '<button type="button" class="lu-tour-btn" data-tour="back">Back</button>' : '')
      + (last ? '' : '<button type="button" class="lu-tour-btn ghost" data-tour="skip">Skip tour</button>')
      + (step.waitFor ? '<button type="button" class="lu-tour-btn" data-tour="next">' + esc(step.tryLabel ? 'Skip this' : 'Next') + '</button>' : '<button type="button" class="lu-tour-btn primary" data-tour="next">' + (last ? 'Done' : 'Next') + '</button>')
      + '</div>';
    if (step.waitFor && step.tryLabel) { var hint = document.createElement('div'); hint.style.cssText = 'margin-top:8px;font-size:12px;color:var(--p,#6C5CE7);font-weight:600'; hint.textContent = step.tryLabel; card.querySelector('.lu-tour-foot').before(hint); }
    card.querySelectorAll('[data-tour]').forEach(function (b) { b.addEventListener('click', function () { var a = b.getAttribute('data-tour'); if (a === 'next') last ? end('completed') : next(); else if (a === 'back') back(); else skip(); }); });
    var prim = card.querySelector('.primary') || card.querySelector('[data-tour="next"]'); if (prim) setTimeout(function () { try { prim.focus({ preventScroll: true }); } catch (e) {} }, 30);
    if (S.waitHandler) { window.removeEventListener('message', S.waitHandler); S.waitHandler = null; }
    if (step.waitFor) { S.waitHandler = function (e) { if (e.data && e.data.type === step.waitFor) { window.removeEventListener('message', S.waitHandler); S.waitHandler = null; setTimeout(function () { if (S.active && S.steps[S.i] === step) next(); }, 350); } }; window.addEventListener('message', S.waitHandler); }
    emit('step', { index: S.i });
    layout();
  }

  function emit(name, info) { try { if (S.opts.onEvent) S.opts.onEvent(name, Object.assign({ index: S.i, total: S.steps.length }, info || {})); } catch (e) {} }
  function next() { if (!S.active) return; if (S.i < S.steps.length - 1) { S.i++; render(); } else end('completed'); }
  function back() { if (!S.active) return; if (S.i > 0) { S.i--; render(); } }
  function skip() { end('skipped'); }
  function end(kind) {
    if (!S.active) return; S.active = false;
    var last = S.i; destroy();
    document.removeEventListener('keydown', onKey, true); window.removeEventListener('resize', onResize); try { window.visualViewport && window.visualViewport.removeEventListener('resize', onResize); } catch (e) {}
    if (S.waitHandler) { window.removeEventListener('message', S.waitHandler); S.waitHandler = null; }
    emit(kind, { index: last });
    try { if (S.opts.onFinish) S.opts.onFinish(kind, last); } catch (e) {}
  }
  function onKey(e) {
    if (!S.active) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); skip(); return; }
    if (e.key === 'ArrowRight') { e.preventDefault(); next(); return; }
    if (e.key === 'ArrowLeft') { e.preventDefault(); back(); return; }
    if (e.key === 'Tab' && S.els) { var f = S.els.card.querySelectorAll('button'); if (!f.length) return; var first = f[0], lastB = f[f.length - 1]; if (e.shiftKey && document.activeElement === first) { e.preventDefault(); lastB.focus(); } else if (!e.shiftKey && document.activeElement === lastB) { e.preventDefault(); first.focus(); } }
  }
  var rt = null; function onResize() { clearTimeout(rt); rt = setTimeout(layout, 80); }

  function start(steps, opts) {
    if (S.active) end('replaced');
    S.steps = (steps || []).filter(Boolean); S.opts = opts || {}; S.i = 0; if (!S.steps.length) return;
    S.active = true; build();
    document.addEventListener('keydown', onKey, true); window.addEventListener('resize', onResize); try { window.visualViewport && window.visualViewport.addEventListener('resize', onResize); } catch (e) {}
    emit('started', {}); render();
  }

  window.luTour = { start: start, next: next, back: back, skip: skip, end: end, active: function () { return S.active; }, layout: layout, version: 1 };
})();
