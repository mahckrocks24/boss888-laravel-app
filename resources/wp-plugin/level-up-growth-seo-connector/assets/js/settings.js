/* LevelUp Growth SEO Connector — Settings page JS. */
(function () {
  'use strict';

  if (typeof window.LGSC_SETTINGS === 'undefined') return;

  var DATA = window.LGSC_SETTINGS;
  var btn  = document.getElementById('lgsc-test-btn');
  var box  = document.getElementById('lgsc-test-result');
  if (!btn || !box) return;

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function show(html, kind) {
    box.className = 'lgsc-test-result lgsc-test-' + (kind || 'info');
    box.innerHTML = html;
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    show((DATA.strings && DATA.strings.testing) || 'Testing connection...', 'info');

    var fd = new FormData();
    fd.append('action', 'lgsc_test_connection');
    fd.append('nonce', DATA.nonce);

    fetch(DATA.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, body: j }; });
    }).then(function (resp) {
      btn.disabled = false;
      if (!resp.ok || !resp.body || !resp.body.success) {
        var msg = resp.body && resp.body.data && resp.body.data.message
          ? resp.body.data.message
          : ((DATA.strings && DATA.strings.failed) || 'Connection failed.');
        show('<strong>' + ((DATA.strings && DATA.strings.failed) || 'Connection failed.') + '</strong> ' + escHtml(msg), 'error');
        return;
      }
      var d = resp.body.data || {};
      var html = '<strong>' + ((DATA.strings && DATA.strings.success) || 'Connected.') + '</strong>';
      html += '<ul class="lgsc-test-stats">';
      html += '<li><span>' + ((DATA.strings && DATA.strings.workspace) || 'Workspace') + ':</span> ' + escHtml(d.workspace_name || '—') + '</li>';
      html += '<li><span>' + ((DATA.strings && DATA.strings.plan) || 'Plan') + ':</span> ' + escHtml(d.plan || '—') + '</li>';
      html += '<li><span>' + ((DATA.strings && DATA.strings.credits) || 'Credits') + ':</span> ' + escHtml(String(d.credits_remaining != null ? d.credits_remaining : 0)) + '</li>';
      html += '<li><span>' + ((DATA.strings && DATA.strings.pages) || 'Pages indexed') + ':</span> ' + escHtml(String(d.seo_pages_indexed != null ? d.seo_pages_indexed : 0)) + '</li>';
      html += '</ul>';
      show(html, 'success');
    }).catch(function (err) {
      btn.disabled = false;
      show('<strong>' + ((DATA.strings && DATA.strings.failed) || 'Connection failed.') + '</strong> ' + escHtml((err && err.message) || ''), 'error');
    });
  });
})();
