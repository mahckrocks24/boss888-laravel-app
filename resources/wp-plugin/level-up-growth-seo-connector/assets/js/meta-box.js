/* LevelUp Growth SEO Connector — Meta Box JS (vanilla, no jQuery). */
(function () {
  'use strict';

  if (typeof window.LGSC_DATA === 'undefined') {
    return;
  }

  var DATA = window.LGSC_DATA;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function setStatus(msg, kind) {
    var el = $('.lgsc-panel .lgsc-status');
    if (!el) return;
    el.textContent = msg || '';
    el.classList.remove('lgsc-status-ok', 'lgsc-status-err');
    if (kind === 'ok') el.classList.add('lgsc-status-ok');
    if (kind === 'err') el.classList.add('lgsc-status-err');
  }

  function scoreColor(score) {
    if (score >= 90) return '#3b82f6';
    if (score >= 70) return '#10b981';
    if (score >= 50) return '#f59e0b';
    return '#dc2626';
  }

  function renderGauge(score) {
    var gauge = $('.lgsc-panel .lgsc-gauge');
    var val = $('.lgsc-panel .lgsc-gauge-value');
    if (!gauge || !val) return;
    var n = Math.max(0, Math.min(100, parseInt(score, 10) || 0));
    val.textContent = n > 0 ? String(n) : '—';
    gauge.style.background =
      'conic-gradient(' + scoreColor(n) + ' ' + (n * 3.6) + 'deg, rgba(255,255,255,.08) 0deg)';
  }

  function impactBadge(impact) {
    var label = '';
    var color = '#6b7280';
    var n = parseInt(impact, 10) || 0;
    if (n >= 18 || impact === 'high' || impact === 'HIGH') {
      label = (DATA.strings && DATA.strings.high) || 'HIGH';
      color = '#dc2626';
    } else if (n >= 10 || impact === 'medium' || impact === 'MEDIUM') {
      label = (DATA.strings && DATA.strings.medium) || 'MEDIUM';
      color = '#f59e0b';
    } else {
      label = (DATA.strings && DATA.strings.low) || 'LOW';
      color = '#10b981';
    }
    return '<span class="lgsc-impact" style="background:' + color + '20;color:' + color + '">' + label + '</span>';
  }

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderQuickWins(list) {
    var box = $('.lgsc-panel .lgsc-quickwins-list');
    if (!box) return;
    if (!Array.isArray(list) || list.length === 0) {
      box.innerHTML = '<div class="lgsc-empty">' + ((DATA.strings && DATA.strings.noWins) || 'No quick wins.') + '</div>';
      return;
    }
    var html = '';
    list.slice(0, 5).forEach(function (w) {
      var label = w.issue_label || w.issue || w.gap || w.description || '';
      var fix = w.fix || w.recommendation || '';
      var imp = w.impact != null ? w.impact : (w.priority || 'medium');
      html += '<div class="lgsc-quickwin">';
      html += '<div class="lgsc-quickwin-row"><span class="lgsc-quickwin-text">' + escHtml(label) + '</span>' + impactBadge(imp) + '</div>';
      if (fix) {
        html += '<div class="lgsc-quickwin-fix">' + escHtml(fix) + '</div>';
      }
      html += '</div>';
    });
    box.innerHTML = html;
  }

  function renderLinks(list) {
    var box = $('.lgsc-panel .lgsc-linksuggestions-list');
    if (!box) return;
    if (!Array.isArray(list) || list.length === 0) {
      box.innerHTML = '<div class="lgsc-empty">' + ((DATA.strings && DATA.strings.noLinks) || 'No suggestions yet.') + '</div>';
      return;
    }
    var html = '';
    list.slice(0, 3).forEach(function (l) {
      var anchor = l.suggested_anchor || l.anchor || '';
      var target = l.target_url || l.to_url || '';
      var score = l.score != null ? l.score : '';
      html += '<div class="lgsc-linksug" tabindex="0" data-anchor="' + escHtml(anchor) + '" data-target="' + escHtml(target) + '">';
      html += '<div class="lgsc-linksug-anchor">' + escHtml(anchor) + '</div>';
      html += '<div class="lgsc-linksug-target">→ ' + escHtml(target) + '</div>';
      if (score !== '') html += '<div class="lgsc-linksug-score">score ' + escHtml(String(score)) + '</div>';
      html += '</div>';
    });
    box.innerHTML = html;

    // Click-to-copy.
    $$('.lgsc-panel .lgsc-linksug').forEach(function (el) {
      el.addEventListener('click', function () {
        var a = el.getAttribute('data-anchor') || '';
        var t = el.getAttribute('data-target') || '';
        var snippet = '<a href="' + t + '">' + a + '</a>';
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(snippet);
          setStatus('Copied: ' + snippet, 'ok');
        }
      });
    });
  }

  function ajax(action, body) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', DATA.nonce);
    if (body && typeof body === 'object') {
      Object.keys(body).forEach(function (k) {
        fd.append(k, body[k] == null ? '' : String(body[k]));
      });
    }
    return fetch(DATA.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
    });
  }

  function analyze() {
    if (!DATA.isConfigured) return;
    setStatus((DATA.strings && DATA.strings.analyzing) || 'Analyzing...');
    var btn = $('.lgsc-panel .lgsc-btn-analyze');
    if (btn) btn.disabled = true;

    ajax('lgsc_analyze_page', { post_id: DATA.postId }).then(function (resp) {
      if (btn) btn.disabled = false;
      if (!resp.ok || !resp.body || !resp.body.success) {
        var msg = resp.body && resp.body.data && resp.body.data.message ? resp.body.data.message : 'failed';
        setStatus(((DATA.strings && DATA.strings.failed) || 'Failed: ') + msg, 'err');
        return;
      }
      var data = resp.body.data || {};
      var score = data.score != null ? parseInt(data.score, 10) : 0;
      renderGauge(score);
      renderQuickWins(data.quick_wins || []);
      renderLinks(data.link_suggestions || []);
      setStatus('Updated.', 'ok');
      var lastEl = $('.lgsc-panel .lgsc-last-analyzed');
      if (lastEl && data.analyzed_at) lastEl.textContent = 'Last analyzed: ' + data.analyzed_at;
    }).catch(function (err) {
      if (btn) btn.disabled = false;
      setStatus(((DATA.strings && DATA.strings.failed) || 'Failed: ') + (err && err.message ? err.message : ''), 'err');
    });
  }

  function saveMeta() {
    if (!DATA.isConfigured) return;
    var titleEl = $('.lgsc-panel .lgsc-meta-title');
    var descEl = $('.lgsc-panel .lgsc-meta-desc');
    var btn = $('.lgsc-panel .lgsc-btn-save');
    if (!titleEl || !descEl) return;
    setStatus((DATA.strings && DATA.strings.saving) || 'Saving...');
    if (btn) btn.disabled = true;

    ajax('lgsc_save_meta', {
      post_id: DATA.postId,
      meta_title: titleEl.value || '',
      meta_description: descEl.value || '',
    }).then(function (resp) {
      if (btn) btn.disabled = false;
      if (!resp.ok || !resp.body || !resp.body.success) {
        var msg = resp.body && resp.body.data && resp.body.data.message ? resp.body.data.message : 'failed';
        setStatus(((DATA.strings && DATA.strings.failed) || 'Failed: ') + msg, 'err');
        return;
      }
      setStatus((DATA.strings && DATA.strings.saved) || 'Saved.', 'ok');
    }).catch(function (err) {
      if (btn) btn.disabled = false;
      setStatus(((DATA.strings && DATA.strings.failed) || 'Failed: ') + (err && err.message ? err.message : ''), 'err');
    });
  }

  function bindCounter(target, soft, hard) {
    var inputSel = target === 'title' ? '.lgsc-meta-title' : '.lgsc-meta-desc';
    var counterSel = '.lgsc-counter[data-target="' + target + '"]';
    var input = $('.lgsc-panel ' + inputSel);
    var counter = $('.lgsc-panel ' + counterSel);
    if (!input || !counter) return;
    function update() {
      var n = (input.value || '').length;
      counter.firstElementChild.textContent = String(n);
      counter.classList.remove('lgsc-counter-ok', 'lgsc-counter-warn', 'lgsc-counter-bad');
      if (n <= soft) counter.classList.add('lgsc-counter-ok');
      else if (n <= hard) counter.classList.add('lgsc-counter-warn');
      else counter.classList.add('lgsc-counter-bad');
    }
    input.addEventListener('input', update);
    update();
  }

  function init() {
    if (!$('.lgsc-panel')) return;

    var btnA = $('.lgsc-panel .lgsc-btn-analyze');
    if (btnA) btnA.addEventListener('click', analyze);

    var btnS = $('.lgsc-panel .lgsc-btn-save');
    if (btnS) btnS.addEventListener('click', saveMeta);

    bindCounter('title', 60, 70);
    bindCounter('desc', 155, 165);

    // Hydrate gauge from server-rendered data attribute on first paint.
    var gauge = $('.lgsc-panel .lgsc-gauge');
    if (gauge) {
      var initial = parseInt(gauge.getAttribute('data-score') || '0', 10);
      if (initial > 0) renderGauge(initial);
    }

    if (DATA.autoOnLoad && DATA.isConfigured) {
      // Defer slightly so the editor finishes rendering.
      setTimeout(analyze, 800);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
