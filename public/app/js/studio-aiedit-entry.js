/**
 * STUDIO888 Phase P — first-class AI Edit entry inside Studio.
 *
 * Studio is now the single editing surface. This adds `studioAiEditPicker()`
 * (wired to the Studio gallery topbar "✦ AI Edit" button): it lists the
 * workspace's own creative images (tenancy-scoped via /creative/dashboard) and
 * opens the PROVEN AI-edit overlay (window.meOpenAiEdit) on the chosen image.
 * No new editor — it reuses manualedit-aiedit.js. Self-contained; no Fabric.
 */
(function () {
  'use strict';
  function tok() { return localStorage.getItem('lu_token') || ''; }

  window.studioAiEditPicker = function () {
    if (typeof window.meOpenAiEdit !== 'function') {
      showToast('AI editor is still loading — try again in a moment.', 'info');
      return;
    }
    var host = document.createElement('div');
    host.id = 'st-aiedit-picker';
    host.setAttribute('role', 'dialog');
    host.setAttribute('aria-label', 'AI Edit — choose an image');
    host.style.cssText = 'position:fixed;inset:0;z-index:8500;background:#0b0f1af2;display:flex;flex-direction:column;color:#e6ebf5;font:14px system-ui,sans-serif';
    host.innerHTML =
      '<div style="display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #1c2740">' +
        '<strong style="font-size:16px">✦ AI Edit — choose an image</strong>' +
        '<span style="color:#8ea3c6;font-size:12px">Pick one of your images to edit with AI. Your original is always preserved.</span>' +
        '<button id="st-aiedit-close" aria-label="Close" style="margin-left:auto;background:#182338;color:#e6ebf5;border:1px solid #29354f;border-radius:8px;padding:6px 14px;cursor:pointer">Close</button>' +
      '</div>' +
      '<div id="st-aiedit-grid" style="flex:1;overflow:auto;padding:18px;display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;align-content:start">' +
        '<div style="grid-column:1/-1;color:#8ea3c6;text-align:center;padding:40px">Loading your images…</div>' +
      '</div>';
    document.body.appendChild(host);
    host.querySelector('#st-aiedit-close').onclick = function () { host.remove(); };
    host.addEventListener('keydown', function (e) { if (e.key === 'Escape') host.remove(); });

    fetch('/api/creative/dashboard', { headers: { Authorization: 'Bearer ' + tok(), Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var recent = (d && (d.recent || (d.data && d.data.recent))) || [];
        var imgs = recent.filter(function (a) { return a && a.type === 'image' && a.status === 'completed' && a.url; });
        var grid = host.querySelector('#st-aiedit-grid');
        if (!imgs.length) {
          grid.innerHTML = '<div style="grid-column:1/-1;color:#8ea3c6;text-align:center;padding:40px">No editable images yet. Generate an image first, then AI-edit it here.</div>';
          return;
        }
        grid.innerHTML = imgs.map(function (a, i) {
          return '<button data-i="' + i + '" style="background:#131c30;border:1px solid #263349;border-radius:10px;overflow:hidden;cursor:pointer;padding:0;text-align:left" ' +
            'onmouseover="this.style.borderColor=\'#2d5cff\'" onmouseout="this.style.borderColor=\'#263349\'">' +
            '<img src="' + a.url + '" alt="" loading="lazy" style="width:100%;aspect-ratio:1;object-fit:cover;display:block">' +
            '<div style="padding:6px 8px;font-size:11px;color:#9fb0cc">' + (a.parent_asset_id ? 'Edit v' + (a.version || '?') : 'Original') + ' · #' + a.id + '</div>' +
            '</button>';
        }).join('');
        grid.querySelectorAll('[data-i]').forEach(function (b) {
          b.onclick = function () { var a = imgs[+b.getAttribute('data-i')]; host.remove(); window.meOpenAiEdit(a); };
        });
      })
      .catch(function () {
        host.querySelector('#st-aiedit-grid').innerHTML = '<div style="grid-column:1/-1;color:#ffb4b4;text-align:center;padding:40px">Could not load your images. Please try again.</div>';
      });
  };
})();
