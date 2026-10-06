/* DESIGN-UPDATES-1 (Owner 2026-10-06): "whenever we are pushing updates, we need to inform them using a design preview of what
   the website will look and the possible changes on their customizations, with and agree or cancel option and a revert back
   option if needed."
   window.luDesignUpdate.open(id)              the preview screen: now / after, desktop / phone frames, what changes for the
                                               owner's customisations (each one shown on the preview), Agree / Cancel
   window.luDesignUpdate.siteSection(id, el)   the Design part of Site settings: an offer to preview, or Revert for 30 days
   Nothing changes on the website until the owner agrees. Site CSS controls only, glass tokens, phones first. */
(function () {
  'use strict';
  var API_BASE = (typeof API !== 'undefined' ? API : '/api/');
  function auth(json) { var h = { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' }; if (json) h['Content-Type'] = 'application/json'; return h; }
  function call(method, path) { return fetch(API_BASE + path, { method: method, headers: auth(method !== 'GET'), cache: 'no-store', body: method !== 'GET' ? '{}' : undefined }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, json: j || {} }; }); }); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(m, k) { if (typeof window.showToast === 'function') window.showToast(m, k || 'info'); }
  function day(s) { if (!s) return ''; try { var d = window._luParseTs ? window._luParseTs(s) : new Date(s); return d.toLocaleDateString([], { day: 'numeric', month: 'long' }); } catch (e) { return String(s); } }

  function ensureCss() {
    if (document.getElementById('lu-dupd-css')) return;
    var st = document.createElement('style'); st.id = 'lu-dupd-css';
    st.textContent = [
      '#lu-dupd{position:fixed;inset:0;z-index:100050;display:flex;flex-direction:column;background:var(--lg-scrim,rgba(20,18,40,.32));-webkit-backdrop-filter:blur(18px) saturate(160%);backdrop-filter:blur(18px) saturate(160%);font-family:var(--lg-font,inherit);color:var(--lg-ink,var(--t1,#0D0F1C))}',
      '#lu-dupd .dp-shell{margin:auto;width:min(1360px,calc(100% - 32px));height:min(900px,calc(100% - 32px));display:flex;flex-direction:column;background:var(--lg-glass-thick,rgba(255,255,255,.86));border:1px solid var(--lg-rim,rgba(255,255,255,.75));border-radius:22px;box-shadow:var(--lg-shadow-3,0 28px 64px rgba(30,24,80,.18));overflow:hidden}',
      '#lu-dupd .dp-hd{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--lg-hairline,rgba(18,16,48,.08))}',
      '#lu-dupd .dp-ic{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;background:var(--lg-brand-grad,linear-gradient(135deg,#8C25D2,#4C86DE 55%,#3FDFDF));color:#fff;flex:none}',
      '#lu-dupd .dp-t{font-size:var(--lg-fs-title3,18px);line-height:var(--lg-lh-title3,24px);font-weight:700;margin:0}',
      '#lu-dupd .dp-s{font-size:var(--lg-fs-footnote,13px);color:var(--lg-ink-3,#62677F);margin:2px 0 0}',
      '#lu-dupd .dp-x{margin-left:auto;width:40px;height:40px;border-radius:12px;border:0;background:transparent;color:inherit;font-size:22px;cursor:pointer}',
      '#lu-dupd .dp-x:hover{background:var(--lg-fill-hover,rgba(18,16,48,.05))}',
      '#lu-dupd .dp-bar{display:flex;align-items:center;gap:10px;padding:10px 20px;flex-wrap:wrap}',
      '#lu-dupd .dp-seg{display:inline-flex;padding:3px;border-radius:12px;background:var(--lg-fill-hover,rgba(18,16,48,.05));gap:2px}',
      '#lu-dupd .dp-seg button{border:0;background:transparent;color:var(--lg-ink-2,#454A61);font:600 13px var(--lg-font,inherit);padding:8px 14px;border-radius:9px;cursor:pointer;min-height:36px}',
      '#lu-dupd .dp-seg button[aria-pressed="true"]{background:var(--lg-raised,#fff);color:var(--lg-ink,#0D0F1C);box-shadow:var(--lg-shadow-1,0 1px 2px rgba(30,24,80,.08))}',
      '#lu-dupd .dp-stage[data-show="now"],#lu-dupd .dp-stage[data-show="after"]{grid-template-columns:1fr}#lu-dupd .dp-stage[data-show="now"] .dp-pane.after,#lu-dupd .dp-stage[data-show="after"] .dp-pane.now{display:none}',
      '#lu-dupd .dp-body{flex:1;min-height:0;display:grid;grid-template-columns:1fr 340px}',
      '#lu-dupd .dp-stage{min-width:0;min-height:0;display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:4px 14px 14px 20px;overflow:auto}',
      '#lu-dupd .dp-pane{min-width:0;display:flex;flex-direction:column;gap:8px}',
      '#lu-dupd .dp-lbl{font:600 12px var(--lg-font,inherit);letter-spacing:.04em;text-transform:uppercase;color:var(--lg-ink-3,#62677F);display:flex;align-items:center;gap:8px}',
      '#lu-dupd .dp-lbl i{width:8px;height:8px;border-radius:50%;background:var(--lg-ink-3,#62677F)}#lu-dupd .dp-pane.after .dp-lbl i{background:var(--lg-action,#6B3BDF)}',
      '#lu-dupd .dp-frame{position:relative;overflow:hidden;border-radius:14px;background:var(--lg-raised,#fff);box-shadow:0 0 0 1px var(--lg-edge,rgba(18,16,48,.07)),var(--lg-shadow-2,0 12px 32px rgba(30,24,80,.08))}',
      '#lu-dupd .dp-frame.phone{border-radius:34px;box-shadow:0 0 0 8px #0D0F1C,0 0 0 9px rgba(255,255,255,.12),var(--lg-shadow-2,0 12px 32px rgba(30,24,80,.08));margin:8px auto}',
      '#lu-dupd .dp-frame iframe{position:absolute;top:0;left:0;border:0;transform-origin:0 0;background:#fff}',
      '#lu-dupd .dp-side{border-left:1px solid var(--lg-hairline,rgba(18,16,48,.08));display:flex;flex-direction:column;min-height:0}',
      '#lu-dupd .dp-list{flex:1;overflow:auto;padding:16px 18px;display:flex;flex-direction:column;gap:12px}',
      '#lu-dupd .dp-h{font:700 14px var(--lg-font,inherit);margin:0}',
      '#lu-dupd .dp-ok{display:flex;gap:10px;align-items:flex-start;padding:12px;border-radius:14px;background:var(--lg-success-soft,rgba(19,135,92,.12));color:var(--lg-ink,#0D0F1C);font-size:13.5px;line-height:1.45}',
      '#lu-dupd .dp-ok b{color:var(--lg-success,#13875C)}',
      '#lu-dupd .dp-note{padding:12px;border-radius:14px;background:var(--lg-tint,rgba(107,59,223,.10));box-shadow:inset 0 0 0 1px var(--lg-tint-rim,rgba(107,59,223,.16));font-size:13.5px;line-height:1.45}',
      '#lu-dupd .dp-item{padding:12px;border-radius:14px;background:var(--lg-glass,rgba(255,255,255,.58));box-shadow:inset 0 0 0 1px var(--lg-edge,rgba(18,16,48,.07));font-size:13.5px;line-height:1.45}',
      '#lu-dupd .dp-item .k{display:inline-block;font:600 11px var(--lg-font,inherit);letter-spacing:.03em;text-transform:uppercase;color:var(--lg-accent-text,#6A22C2);margin-bottom:4px}',
      '#lu-dupd .dp-item.warn .k{color:var(--lg-warning,#9A5B00)}',
      '#lu-dupd .dp-item button{margin-top:8px}',
      '#lu-dupd .dp-ft{display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;border-top:1px solid var(--lg-hairline,rgba(18,16,48,.08))}',
      '#lu-dupd .dp-btn{min-height:44px;padding:0 18px;border-radius:12px;border:0;font:600 14px var(--lg-font,inherit);cursor:pointer;color:var(--lg-ink,#0D0F1C);background:var(--lg-glass,rgba(255,255,255,.7));box-shadow:inset 0 1px 0 var(--lg-rim-top,rgba(255,255,255,.95)),0 0 0 1px var(--lg-edge,rgba(18,16,48,.1))}',
      '#lu-dupd .dp-btn.primary{color:#fff;background:var(--lg-action-grad,linear-gradient(180deg,#8A3BEA,#6A45E4));box-shadow:var(--lg-glow,0 8px 24px -6px rgba(107,59,223,.45))}',
      '#lu-dupd .dp-btn.sm{min-height:34px;padding:0 12px;font-size:12.5px;border-radius:10px}',
      '#lu-dupd .dp-btn[disabled]{opacity:.6;cursor:default}',
      '#lu-dupd .dp-done{margin:auto;max-width:440px;text-align:center;padding:32px}',
      '#lu-dupd .dp-done .dp-ic{margin:0 auto 14px;width:56px;height:56px;border-radius:18px;font-size:26px}',
      '#lu-dupd .dp-skel{margin:auto;color:var(--lg-ink-3,#62677F);font-size:14px}',
      '@media (max-width:899px){#lu-dupd .dp-shell{width:100%;height:100%;border-radius:0;border:0}#lu-dupd .dp-hd{padding:12px 14px}#lu-dupd .dp-bar{padding:8px 14px}',
      '#lu-dupd .dp-views [data-view="both"]{display:none}#lu-dupd .dp-body{grid-template-columns:1fr;grid-template-rows:minmax(300px,1fr) auto;overflow:auto}',
      '#lu-dupd .dp-stage{grid-template-columns:1fr;padding:4px 14px 10px}#lu-dupd .dp-stage[data-show="now"] .dp-pane.after,#lu-dupd .dp-stage[data-show="after"] .dp-pane.now{display:none}',
      '#lu-dupd .dp-side{border-left:0;border-top:1px solid var(--lg-hairline,rgba(18,16,48,.08))}#lu-dupd .dp-list{overflow:visible}',
      '#lu-dupd .dp-ft{position:sticky;bottom:0;background:var(--lg-glass-thick,rgba(255,255,255,.92));padding-bottom:max(14px,env(safe-area-inset-bottom))}#lu-dupd .dp-ft .dp-btn{flex:1}}',
      '.lu-dupd-site{margin-top:18px;padding-top:16px;border-top:1px solid var(--bd,rgba(18,16,48,.08))}',
      '.lu-dupd-site .t{font:600 13px var(--fh,inherit);color:var(--t1,inherit)}.lu-dupd-site .s{font-size:12px;color:var(--t3,#62677F);margin:2px 0 10px;line-height:1.45}'
    ].join('\n');
    document.head.appendChild(st);
  }

  var SPARK = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.8 4.6L18 9.4l-4.2 1.8L12 16l-1.8-4.8L6 9.4l4.2-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>';
  var KIND = { kept_custom: 'Kept as you have it', removed_by_design: 'No longer in the design', new_section: 'New from the design', added_kept: 'Your added section' };

  function close() { var o = document.getElementById('lu-dupd'); if (o) o.remove(); document.removeEventListener('keydown', onKey); document.documentElement.style.overflow = ''; }
  function onKey(e) { if (e.key === 'Escape') close(); }

  function open(id) {
    ensureCss(); close();
    var ov = document.createElement('div'); ov.id = 'lu-dupd'; ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', 'Design update preview');
    ov.innerHTML = '<div class="dp-shell"><div class="dp-skel">Getting the preview ready…</div></div>';
    document.body.appendChild(ov); document.documentElement.style.overflow = 'hidden'; document.addEventListener('keydown', onKey);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    function fail(title, line) {
      ov.querySelector('.dp-shell').innerHTML = '<div class="dp-done"><p class="dp-t">' + esc(title) + '</p><p class="dp-s">' + esc(line) + '</p><div style="display:flex;gap:10px;justify-content:center;margin-top:14px">' + (title.indexOf('load') > -1 ? '<button type="button" class="dp-btn primary" data-retry>Try again</button>' : '') + '<button type="button" class="dp-btn" data-x>Close</button></div></div>';
      ov.querySelector('[data-x]').onclick = close; var rt = ov.querySelector('[data-retry]'); if (rt) rt.onclick = function () { open(id); };
    }
    function load(tries) {
      call('GET', 'builder/design-updates/' + Number(id)).then(function (r) {
        if (r.ok && r.json.update) { draw(ov, r.json.update); return; }
        if (r.status === 404) { fail('This update is not available', r.json.message || 'It may have been decided already.'); return; }
        if (tries > 0) { setTimeout(function () { load(tries - 1); }, 1500); return; }   // a token refresh or a busy moment: one more try
        fail('Couldn’t load the preview', 'Please try again in a moment.');
      }).catch(function () { if (tries > 0) setTimeout(function () { load(tries - 1); }, 1500); else fail('Couldn’t load the preview', 'Please try again in a moment.'); });
    }
    load(1);
  }

  function draw(ov, u) {
    var shell = ov.querySelector('.dp-shell'); var waiting = u.status === 'ready';
    var items = (u.changes || []).map(function (c, i) {
      return '<div class="dp-item' + (c.kind === 'kept_custom' || c.kind === 'removed_by_design' ? ' warn' : '') + '"><span class="k">' + esc(KIND[c.kind] || 'Change') + '</span><div>' + esc(c.text) + '</div>'
        + (c.kind !== 'removed_by_design' ? '<button type="button" class="dp-btn sm" data-show="' + esc(c.block) + '">Show me on the preview</button>' : '<button type="button" class="dp-btn sm" data-show-now="' + esc(c.block) + '">Show me where it is now</button>') + '</div>';
    }).join('');
    var notes = (u.notes || []).map(function (n) { return '<div class="dp-item"><span class="k">' + (n.kind === 'draft' ? 'Your draft' : 'Your pages') + '</span><div>' + esc(n.text) + '</div></div>'; }).join('');
    var content = u.content ? '<div class="dp-ok"><span aria-hidden="true">✓</span><div><b>Your content is safe.</b> ' + esc(u.content.text) + '</div></div>' : '';
    shell.innerHTML = '<div class="dp-hd"><div class="dp-ic">' + SPARK + '</div><div style="min-width:0"><h2 class="dp-t">A design improvement for ' + esc(u.website) + '</h2><p class="dp-s">' + (waiting ? 'Nothing changes until you agree. You can go back afterwards for 30 days.' : 'This update is ' + esc(u.status) + '.') + '</p></div><button type="button" class="dp-x" aria-label="Close">×</button></div>'
      + '<div class="dp-bar"><div class="dp-seg dp-dev" role="group" aria-label="Device"><button type="button" data-dev="desk" aria-pressed="true">Desktop</button><button type="button" data-dev="phone" aria-pressed="false">Phone</button></div>'
      + '<div class="dp-seg dp-views" role="group" aria-label="Which version"><button type="button" data-view="both" aria-pressed="true">Side by side</button><button type="button" data-view="now" aria-pressed="false">Now</button><button type="button" data-view="after" aria-pressed="false">After the update</button></div></div>'
      + '<div class="dp-body"><div class="dp-stage" data-show="both">'
      + '<div class="dp-pane now"><div class="dp-lbl"><i></i>Now</div><div class="dp-frame"><iframe title="Your website now" sandbox="allow-scripts" loading="lazy" src="' + esc(u.now_url) + '"></iframe></div></div>'
      + '<div class="dp-pane after"><div class="dp-lbl"><i></i>After the update</div><div class="dp-frame"><iframe title="Your website after the update" sandbox="allow-scripts" src="' + esc(u.after_url) + '"></iframe></div></div></div>'
      + '<aside class="dp-side"><div class="dp-list"><h3 class="dp-h">What changes for you</h3>'
      + (u.note ? '<div class="dp-note"><b>What improves.</b> ' + esc(u.note) + '</div>' : '<div class="dp-note">Arthur improved this design. The look changes; your words, pictures, colours and menu stay yours.</div>')
      + content + (u.carried ? '<div class="dp-item"><span class="k">Your customisations</span><div>' + esc(u.carried) + '</div></div>' : '')
      + (items || (u.carried ? '' : '<div class="dp-item"><span class="k">Your customisations</span><div>Everything you changed carries across as it is. Only the design’s look changes.</div></div>')) + notes + '</div>'
      + (waiting ? '<div class="dp-ft"><button type="button" class="dp-btn" data-cancel>Cancel</button><button type="button" class="dp-btn primary" data-agree>Agree and update</button></div>' : '<div class="dp-ft"><button type="button" class="dp-btn" data-x>Close</button></div>')
      + '</aside></div>';
    shell.querySelector('.dp-x').onclick = close; var cx = shell.querySelector('[data-x]'); if (cx) cx.onclick = close;
    var stage = shell.querySelector('.dp-stage'); var dev = 'desk';
    if (window.matchMedia('(max-width:899px)').matches) { stage.setAttribute('data-show', 'after'); seg('.dp-views', 'data-view', 'after'); }
    function fit() {
      shell.querySelectorAll('.dp-frame').forEach(function (fr) {
        var ifr = fr.querySelector('iframe'); var pane = fr.parentNode; var W = dev === 'desk' ? 1280 : 390, H = dev === 'desk' ? 800 : 844;
        var avail = Math.max(120, pane.clientWidth - (dev === 'phone' ? 20 : 0)); var availH = Math.max(260, stage.clientHeight - (dev === 'phone' ? 64 : 40));
        var s = dev === 'desk' ? Math.min(1, avail / W) : Math.min(1, avail / W, availH / H);
        if (dev === 'desk') H = Math.max(800, Math.min(1600, Math.floor(availH / s)));   // the frame fills the stage: more of the page, same scale
        fr.classList.toggle('phone', dev === 'phone'); fr.style.width = Math.round(W * s) + 'px'; fr.style.height = Math.round(H * s) + 'px';
        ifr.style.width = W + 'px'; ifr.style.height = H + 'px'; ifr.style.transform = 'scale(' + s + ')';
      });
    }
    function seg(sel, attr, val) { shell.querySelectorAll(sel + ' button').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute(attr) === val ? 'true' : 'false'); }); }
    shell.querySelectorAll('[data-dev]').forEach(function (b) { b.onclick = function () { dev = b.getAttribute('data-dev'); seg('.dp-dev', 'data-dev', dev); fit(); }; });
    shell.querySelectorAll('[data-view]').forEach(function (b) { b.onclick = function () { stage.setAttribute('data-show', b.getAttribute('data-view')); seg('.dp-views', 'data-view', b.getAttribute('data-view')); fit(); }; });
    function show(which, block) {
      if (window.matchMedia('(max-width:899px)').matches || stage.getAttribute('data-show') !== 'both') { stage.setAttribute('data-show', which); seg('.dp-views', 'data-view', which); fit(); stage.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      var f = shell.querySelector('.dp-pane.' + which + ' iframe'); try { f.contentWindow.postMessage({ lu_dupd_show: block }, '*'); } catch (e) {}
      if (which === 'after') { var n = shell.querySelector('.dp-pane.now iframe'); try { n.contentWindow.postMessage({ lu_dupd_show: block }, '*'); } catch (e) {} }
    }
    shell.querySelectorAll('[data-show]').forEach(function (b) { b.onclick = function () { show('after', b.getAttribute('data-show')); }; });
    shell.querySelectorAll('[data-show-now]').forEach(function (b) { b.onclick = function () { show('now', b.getAttribute('data-show-now')); }; });
    window.addEventListener('resize', fit); requestAnimationFrame(fit);
    var ag = shell.querySelector('[data-agree]'), cn = shell.querySelector('[data-cancel]');
    if (cn) cn.onclick = function () {
      cn.disabled = true; if (ag) ag.disabled = true;
      call('POST', 'builder/design-updates/' + u.id + '/cancel').then(function (r) {
        toast((r.json && r.json.message) || 'Nothing changed.', r.ok ? 'info' : 'error'); close(); refresh();
      }).catch(function () { cn.disabled = false; if (ag) ag.disabled = false; toast('Couldn’t reach the server — try again.', 'error'); });
    };
    if (ag) ag.onclick = function () {
      ag.disabled = true; cn.disabled = true; ag.textContent = 'Updating…';
      call('POST', 'builder/design-updates/' + u.id + '/agree').then(function (r) {
        var j = r.json || {};
        if (r.status === 409 && j.update) { toast(j.message, 'warning'); draw(ov, j.update); return; }
        if (!r.ok || !j.success) { ag.disabled = false; cn.disabled = false; ag.textContent = 'Agree and update'; toast(j.message || 'The update could not be applied. Nothing changed.', 'error'); return; }
        shell.innerHTML = '<div class="dp-done"><div class="dp-ic">✓</div><h2 class="dp-t">Done</h2><p class="dp-s" style="font-size:14px;line-height:1.5">' + esc(j.message) + '</p><div style="display:flex;gap:10px;justify-content:center;margin-top:18px"><button type="button" class="dp-btn primary" data-x>Close</button></div></div>';
        shell.querySelector('[data-x]').onclick = close; refresh();
        try { if (typeof window._t3ReloadPreview === 'function') window._t3ReloadPreview(); } catch (e) {}
      }).catch(function () { ag.disabled = false; cn.disabled = false; ag.textContent = 'Agree and update'; toast('Couldn’t reach the server — try again.', 'error'); });
    };
  }

  function refresh() { try { if (window.SH && typeof window.SH.loadRail === 'function') window.SH.loadRail(); } catch (e) {} try { document.dispatchEvent(new CustomEvent('lu:design-update')); } catch (e) {} }

  function siteSection(siteId, panel) {
    ensureCss();
    var box = document.createElement('div'); box.className = 'lu-dupd-site'; box.id = 'lu-dupd-site'; box.hidden = true;
    var before = panel.querySelector('#t3-site-status'); if (before) panel.insertBefore(box, before); else panel.appendChild(box);
    function paint() {
      call('GET', 'builder/websites/' + Number(siteId) + '/design-update').then(function (r) {
        var d = r.json || {}; if (!r.ok || !d.on || (!d.offer && !d.applied)) { box.hidden = true; return; }
        box.hidden = false; var h = '<div class="t">Design</div>';
        if (d.offer) h += '<div class="s">Arthur improved this website’s design. Preview it next to what you have now; nothing changes until you agree.</div><button type="button" class="lu-btn lu-btn--sm" data-prev>Preview the update</button>';
        if (d.applied && d.applied.can_revert) h += (d.offer ? '<div style="height:12px"></div>' : '') + '<div class="s">Updated on ' + esc(day(d.applied.decided_at)) + '. You can go back to the previous design until ' + esc(day(d.applied.revert_until)) + '.</div><button type="button" class="lu-btn lu-btn--sm" data-rev>Revert to previous design</button>';
        box.innerHTML = h;
        var p = box.querySelector('[data-prev]'); if (p) p.onclick = function () { open(d.offer.id); };
        var rv = box.querySelector('[data-rev]'); if (rv) rv.onclick = function () {
          window.luConfirm('Go back to the previous design?', 'Your website returns exactly to how it was before the update. Anything you changed since then is kept in Versions.', { okLabel: 'Revert', cancelLabel: 'Keep the new design' }).then(function (ok) {
            if (!ok) return; rv.disabled = true; rv.textContent = 'Reverting…';
            call('POST', 'builder/design-updates/' + d.applied.id + '/revert').then(function (x) {
              toast((x.json && x.json.message) || (x.ok ? 'Reverted.' : 'Could not revert.'), x.ok && x.json.success ? 'success' : 'error');
              try { if (typeof window._t3ReloadPreview === 'function') window._t3ReloadPreview(); } catch (e) {}
              paint(); refresh();
            }).catch(function () { rv.disabled = false; rv.textContent = 'Revert to previous design'; toast('Couldn’t reach the server — try again.', 'error'); });
          });
        };
      }).catch(function () { box.hidden = true; });
    }
    paint(); document.addEventListener('lu:design-update', paint);
  }

  window.luDesignUpdate = { open: open, siteSection: siteSection, close: close };
})();
