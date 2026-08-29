(function () {
  'use strict';

  if (typeof LGSC_SHELL === 'undefined') return;

  function postJson(action, data, cb) {
    var body = new URLSearchParams();
    body.append('action', action);
    body.append('nonce', LGSC_SHELL.nonce);
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    fetch(LGSC_SHELL.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) { cb(null, j); })
      .catch(function (e) { cb(e, null); });
  }

  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function renderLoading(el, msg) {
    el.innerHTML = '<div class="lgsc-card lgsc-card-loading"><div class="lgsc-spinner"></div><div class="lgsc-card-loading-text">' + escHtml(msg || 'Loading…') + '</div></div>';
  }

  function renderError(el, msg) {
    el.innerHTML = '<div class="lgsc-notice lgsc-notice-error"><strong>Error:</strong> ' + escHtml(msg || 'Request failed.') + '</div>';
  }

  // ─── Dashboard ──────────────────────────────────────────────────────────
  function loadDashboard() {
    var grid = document.querySelector('.lgsc-shell-grid');
    if (!grid) return;
    postJson('lgsc_shell_dashboard', {}, function (err, res) {
      if (err || !res || !res.success) {
        var msg = (res && res.data && res.data.message) ? res.data.message : 'request failed';
        grid.innerHTML = '<div class="lgsc-notice lgsc-notice-error"><strong>Could not load workspace:</strong> ' + escHtml(msg) + '</div>';
        return;
      }
      var d = res.data || {};
      grid.innerHTML = ''
        + kpiCard('Workspace', escHtml(d.workspace_name || '—'))
        + kpiCard('Plan', escHtml(d.plan || 'free'))
        + kpiCard('Credits remaining', String(d.credits_remaining != null ? d.credits_remaining.toLocaleString() : '—'))
        + kpiCard('Pages indexed', String(d.seo_pages_indexed != null ? d.seo_pages_indexed : 0));
    });
  }

  function kpiCard(label, value) {
    return '<div class="lgsc-card lgsc-kpi-card">'
      + '<div class="lgsc-kpi-label">' + escHtml(label) + '</div>'
      + '<div class="lgsc-kpi-value">' + value + '</div>'
      + '</div>';
  }

  // ─── Page Analyzer ─────────────────────────────────────────────────────
  function wirePageAnalyzer() {
    var btn = document.getElementById('lgsc-page-analyzer-run');
    var sel = document.getElementById('lgsc-page-analyzer-select');
    var out = document.getElementById('lgsc-page-analyzer-result');
    if (!btn || !sel || !out) return;
    btn.addEventListener('click', function () {
      var id = sel.value;
      if (!id) { renderError(out, 'Pick a post first.'); return; }
      btn.disabled = true; btn.textContent = 'Analyzing…';
      renderLoading(out, 'Sending page to LevelUp Growth…');
      postJson('lgsc_shell_page_analyze', { post_id: id }, function (err, res) {
        btn.disabled = false; btn.textContent = 'Analyze';
        if (err || !res || !res.success) {
          renderError(out, (res && res.data && res.data.message) || 'Analyze failed.');
          return;
        }
        var d = res.data || {};
        var score = d.score && d.score.total != null ? d.score.total : (typeof d.score === 'number' ? d.score : 0);
        var breakdown = (d.score && d.score.breakdown) || [];
        var html = '<div class="lgsc-card"><h3>Score: ' + score + ' / 100</h3>';
        if (breakdown.length) {
          html += '<table class="widefat striped"><thead><tr><th>Factor</th><th>Weight</th><th>Score</th><th>Details</th></tr></thead><tbody>';
          breakdown.forEach(function (f) {
            html += '<tr><td>' + escHtml(f.factor) + '</td><td>' + escHtml(f.weight) + '</td><td>' + escHtml(f.score) + '</td><td>' + escHtml(f.details) + '</td></tr>';
          });
          html += '</tbody></table>';
        }
        html += '</div>';
        if (d.quick_wins && d.quick_wins.length) {
          html += '<div class="lgsc-card"><h3>Quick wins for this URL</h3><ul>';
          d.quick_wins.forEach(function (w) {
            html += '<li>' + escHtml(w.fix || w.issue || '—') + ' <em>(impact ' + escHtml(w.impact || 0) + ')</em></li>';
          });
          html += '</ul></div>';
        }
        out.innerHTML = html;
      });
    });
  }

  // ─── Quick Wins ─────────────────────────────────────────────────────────
  function wireQuickWins() {
    var btn = document.getElementById('lgsc-wins-run');
    var sel = document.getElementById('lgsc-wins-select');
    var out = document.getElementById('lgsc-wins-result');
    if (!btn || !sel || !out) return;
    btn.addEventListener('click', function () {
      var url = sel.value;
      if (!url) { renderError(out, 'Pick a post first.'); return; }
      btn.disabled = true; btn.textContent = 'Loading…';
      renderLoading(out, 'Fetching quick wins from LevelUp Growth…');
      postJson('lgsc_shell_quick_wins', { url: url }, function (err, res) {
        btn.disabled = false; btn.textContent = 'Show wins';
        if (err || !res || !res.success) {
          renderError(out, (res && res.data && res.data.message) || 'Request failed.');
          return;
        }
        var wins = (res.data && res.data.quick_wins) || [];
        if (!wins.length) {
          out.innerHTML = '<div class="lgsc-notice lgsc-notice-info">No quick wins found for this URL. The page may not be indexed yet — try analyzing it first.</div>';
          return;
        }
        var html = '<div class="lgsc-card"><h3>' + wins.length + ' quick win' + (wins.length === 1 ? '' : 's') + '</h3>';
        html += '<table class="widefat striped"><thead><tr><th>Issue</th><th>Fix</th><th>Impact</th><th>Time</th></tr></thead><tbody>';
        wins.forEach(function (w) {
          html += '<tr><td>' + escHtml(w.issue || '—') + '</td><td>' + escHtml(w.fix || '—') + '</td><td>' + escHtml(w.impact || 0) + '</td><td>' + escHtml(w.fix_minutes || '—') + ' min</td></tr>';
        });
        html += '</tbody></table></div>';
        out.innerHTML = html;
      });
    });
  }

  // ─── Internal Links ─────────────────────────────────────────────────────
  function wireLinkOpps() {
    var btn = document.getElementById('lgsc-links-run');
    var sel = document.getElementById('lgsc-links-select');
    var out = document.getElementById('lgsc-links-result');
    if (!btn || !sel || !out) return;
    btn.addEventListener('click', function () {
      var url = sel.value;
      if (!url) { renderError(out, 'Pick a post first.'); return; }
      btn.disabled = true; btn.textContent = 'Loading…';
      renderLoading(out, 'Fetching link opportunities from LevelUp Growth…');
      postJson('lgsc_shell_link_opps', { url: url }, function (err, res) {
        btn.disabled = false; btn.textContent = 'Find opportunities';
        if (err || !res || !res.success) {
          renderError(out, (res && res.data && res.data.message) || 'Request failed.');
          return;
        }
        var d = res.data || {};
        var opps = d.opportunities || d.data || [];
        if (!opps.length) {
          out.innerHTML = '<div class="lgsc-notice lgsc-notice-info">No internal-link opportunities for this URL yet. Build a link graph first via your LevelUp Growth dashboard → Links → Rebuild graph.</div>';
          return;
        }
        var html = '<div class="lgsc-card"><h3>' + opps.length + ' opportunit' + (opps.length === 1 ? 'y' : 'ies') + '</h3>';
        html += '<table class="widefat striped"><thead><tr><th>From</th><th>Anchor</th><th>To</th></tr></thead><tbody>';
        opps.forEach(function (o) {
          html += '<tr><td>' + escHtml(o.source_url || '—') + '</td><td>' + escHtml(o.anchor_text || o.anchor || '—') + '</td><td>' + escHtml(o.target_url || '—') + '</td></tr>';
        });
        html += '</tbody></table></div>';
        out.innerHTML = html;
      });
    });
  }

  // ─── Site Audit ─────────────────────────────────────────────────────────
  function loadAudits() {
    var card = document.querySelector('.lgsc-card[data-load="audits"]');
    var out  = document.getElementById('lgsc-audits-result');
    if (!card || !out) return;
    postJson('lgsc_shell_audits', { per_page: 10 }, function (err, res) {
      if (err || !res || !res.success) {
        card.outerHTML = '<div class="lgsc-notice lgsc-notice-error"><strong>Could not load audits:</strong> ' + escHtml((res && res.data && res.data.message) || 'request failed') + '</div>';
        return;
      }
      var data = (res.data && res.data.data) || res.data || {};
      var summary = data.summary || {};
      var audits = data.audits || [];
      card.remove();
      var html = '<div class="lgsc-shell-grid">';
      html += kpiCard('Total audits', String(summary.total_audits != null ? summary.total_audits : 0));
      html += kpiCard('Avg score', summary.avg_score != null ? String(summary.avg_score) : '—');
      html += kpiCard('Pages indexed', String(summary.total_pages_indexed != null ? summary.total_pages_indexed : 0));
      html += kpiCard('Last audit', summary.last_audit_at ? escHtml(summary.last_audit_at.slice(0, 16).replace('T', ' ')) : '—');
      html += '</div>';
      if (!audits.length) {
        html += '<div class="lgsc-notice lgsc-notice-info">No audits yet for this workspace. Run an audit from your LevelUp Growth dashboard.</div>';
      } else {
        html += '<div class="lgsc-card"><h3>' + audits.length + ' recent audits</h3>';
        html += '<table class="widefat striped"><thead><tr><th>ID</th><th>URL</th><th>Type</th><th>Status</th><th>Score</th><th>Issues</th><th>Created</th></tr></thead><tbody>';
        audits.forEach(function (a) {
          var iss = a.issues_summary || {};
          var issText = iss.total != null ? (iss.total + ' total') : '—';
          html += '<tr>'
            + '<td>' + escHtml(a.id) + '</td>'
            + '<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escHtml(a.url) + '</td>'
            + '<td>' + escHtml(a.type) + '</td>'
            + '<td>' + escHtml(a.status) + '</td>'
            + '<td>' + (a.score != null ? escHtml(a.score) : '—') + '</td>'
            + '<td>' + escHtml(issText) + '</td>'
            + '<td>' + escHtml((a.created_at || '').slice(0, 16)) + '</td>'
            + '</tr>';
        });
        html += '</tbody></table></div>';
      }
      out.innerHTML = html;
    });
  }

  // ─── Indexed Content ────────────────────────────────────────────────────
  function loadIndexed(opts) {
    var card = document.querySelector('.lgsc-card[data-load="indexed"]');
    var out  = document.getElementById('lgsc-indexed-result');
    if (!card && !out) return;
    if (card) card.style.display = '';
    if (out) out.innerHTML = '';
    var params = { per_page: 25, page: 1 };
    if (opts && opts.filter) params.filter = opts.filter;
    if (opts && opts.q)      params.q = opts.q;
    postJson('lgsc_shell_indexed', params, function (err, res) {
      if (card) card.style.display = 'none';
      if (err || !res || !res.success) {
        if (out) out.innerHTML = '<div class="lgsc-notice lgsc-notice-error"><strong>Could not load indexed pages:</strong> ' + escHtml((res && res.data && res.data.message) || 'request failed') + '</div>';
        return;
      }
      var data = (res.data && res.data.data) || res.data || {};
      var items = data.items || [];
      var meta = (res.data && res.data.meta) || {};
      var html = '';
      if (!items.length) {
        html = '<div class="lgsc-notice lgsc-notice-info">No indexed pages match. Try a different filter, or scan pages from your LevelUp Growth dashboard.</div>';
      } else {
        html = '<div class="lgsc-card"><h3>' + items.length + ' page' + (items.length === 1 ? '' : 's') + (meta.total ? ' of ' + meta.total : '') + '</h3>';
        html += '<table class="widefat striped"><thead><tr><th>Title</th><th>URL</th><th>Score</th><th>Words</th><th>Last analyzed</th></tr></thead><tbody>';
        items.forEach(function (p) {
          var url = p.url || '';
          html += '<tr>'
            + '<td>' + escHtml(p.title || '(no title)') + '</td>'
            + '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><a href="' + escHtml(url) + '" target="_blank" rel="noopener">' + escHtml(url) + '</a></td>'
            + '<td>' + (p.content_score != null ? escHtml(p.content_score) : '—') + '</td>'
            + '<td>' + escHtml(p.word_count || 0) + '</td>'
            + '<td>' + escHtml((p.indexed_at || p.updated_at || '').slice(0, 16)) + '</td>'
            + '</tr>';
        });
        html += '</tbody></table></div>';
      }
      if (out) out.innerHTML = html;
    });
  }

  function wireIndexed() {
    var btn = document.getElementById('lgsc-indexed-refresh');
    var qIn = document.getElementById('lgsc-indexed-q');
    var fSel = document.getElementById('lgsc-indexed-filter');
    if (!btn) return;
    btn.addEventListener('click', function () {
      loadIndexed({ q: qIn ? qIn.value : '', filter: fSel ? fSel.value : '' });
    });
  }

  // ─── Keywords ───────────────────────────────────────────────────────────
  function loadKeywords() {
    var card = document.querySelector('.lgsc-card[data-load="keywords"]');
    var out  = document.getElementById('lgsc-keywords-result');
    if (!card || !out) return;
    postJson('lgsc_shell_keywords', { per_page: 50 }, function (err, res) {
      if (err || !res || !res.success) {
        card.outerHTML = '<div class="lgsc-notice lgsc-notice-error"><strong>Could not load keywords:</strong> ' + escHtml((res && res.data && res.data.message) || 'request failed') + '</div>';
        return;
      }
      var data = (res.data && res.data.data) || res.data || {};
      var keywords = data.keywords || [];
      card.remove();
      if (!keywords.length) {
        out.innerHTML = '<div class="lgsc-notice lgsc-notice-info">No keywords tracked yet. Add keywords in your LevelUp Growth dashboard.</div>';
        return;
      }
      var html = '<div class="lgsc-card"><h3>' + keywords.length + ' tracked keyword' + (keywords.length === 1 ? '' : 's') + '</h3>';
      html += '<table class="widefat striped"><thead><tr><th>Keyword</th><th>Volume</th><th>Difficulty</th><th>Position</th><th>Δ</th><th>Status</th><th>Last check</th></tr></thead><tbody>';
      keywords.forEach(function (k) {
        var pos = k.current_rank;
        var change = k.rank_change;
        var changeStr = change == null ? '—' : (change > 0 ? '↑' + change : (change < 0 ? '↓' + Math.abs(change) : '0'));
        html += '<tr>'
          + '<td><strong>' + escHtml(k.keyword || '') + '</strong></td>'
          + '<td>' + (k.volume != null ? escHtml(k.volume) : '—') + '</td>'
          + '<td>' + (k.difficulty != null ? escHtml(k.difficulty) : '—') + '</td>'
          + '<td>' + (pos != null ? '#' + escHtml(pos) : '—') + '</td>'
          + '<td>' + escHtml(changeStr) + '</td>'
          + '<td>' + escHtml(k.status || '—') + '</td>'
          + '<td>' + escHtml((k.last_rank_check || '').slice(0, 16)) + '</td>'
          + '</tr>';
      });
      html += '</tbody></table></div>';
      out.innerHTML = html;
    });
  }

  // ─── Competitors ────────────────────────────────────────────────────────
  function loadCompetitors() {
    var card = document.querySelector('.lgsc-card[data-load="competitors"]');
    var out  = document.getElementById('lgsc-competitors-result');
    if (!card || !out) return;
    postJson('lgsc_shell_competitors', {}, function (err, res) {
      if (err || !res || !res.success) {
        card.outerHTML = '<div class="lgsc-notice lgsc-notice-error"><strong>Could not load competitors:</strong> ' + escHtml((res && res.data && res.data.message) || 'request failed') + '</div>';
        return;
      }
      var data = (res.data && res.data.data) || res.data || {};
      var comps = data.competitors || [];
      card.remove();
      if (!comps.length) {
        out.innerHTML = '<div class="lgsc-notice lgsc-notice-info">No tracked competitors yet. The endpoint to track competitors is coming next — for now, use the Competitors tab in your LevelUp Growth dashboard.</div>';
        return;
      }
      var html = '<div class="lgsc-card"><h3>' + comps.length + ' tracked competitor' + (comps.length === 1 ? '' : 's') + '</h3>';
      html += '<table class="widefat striped"><thead><tr><th>Domain</th><th>Tracked keywords</th><th>Last analyzed</th></tr></thead><tbody>';
      comps.forEach(function (c) {
        var tk = c.tracked_keywords_json ? (typeof c.tracked_keywords_json === 'string' ? c.tracked_keywords_json : JSON.stringify(c.tracked_keywords_json)) : '';
        html += '<tr>'
          + '<td>' + escHtml(c.competitor_domain || '') + '</td>'
          + '<td>' + escHtml(tk) + '</td>'
          + '<td>' + escHtml((c.last_analyzed_at || '').slice(0, 16)) + '</td>'
          + '</tr>';
      });
      html += '</tbody></table></div>';
      out.innerHTML = html;
    });
  }

  // ─── Reports ────────────────────────────────────────────────────────────
  function loadReports() {
    var card = document.querySelector('.lgsc-card[data-load="reports"]');
    var out  = document.getElementById('lgsc-reports-result');
    if (!card || !out) return;
    postJson('lgsc_shell_reports', {}, function (err, res) {
      if (err || !res || !res.success) {
        card.outerHTML = '<div class="lgsc-notice lgsc-notice-error"><strong>Could not load report:</strong> ' + escHtml((res && res.data && res.data.message) || 'request failed') + '</div>';
        return;
      }
      var report = (res.data && res.data.data) || res.data || {};
      card.remove();
      var html = '';
      // Top KPIs
      var snap = report.audit_snapshot || {};
      var ks = report.keyword_summary || {};
      var cs = report.content_summary || {};
      var ls = report.link_summary || {};
      html += '<div class="lgsc-shell-grid">';
      html += kpiCard('SEO health score', String(report.health_score != null ? report.health_score : '—'));
      html += kpiCard('Keywords tracking', String(ks.tracking != null ? ks.tracking : 0));
      html += kpiCard('Pages avg score', String(cs.avg_score != null ? cs.avg_score : '—'));
      html += kpiCard('Internal links', String(ls.internal != null ? ls.internal : 0));
      html += '</div>';
      // Audit snapshot
      html += '<div class="lgsc-card"><h3>Latest audit snapshot</h3>';
      if (Object.keys(snap).length) {
        html += '<dl class="lgsc-dl">';
        ['score','previous_score','delta','errors','warnings','critical_issues','created_at'].forEach(function (k) {
          if (snap[k] != null) html += '<dt>' + escHtml(k) + '</dt><dd>' + escHtml(snap[k]) + '</dd>';
        });
        html += '</dl>';
      } else {
        html += '<p class="lgsc-muted">No audits yet.</p>';
      }
      html += '</div>';
      // Quick wins
      var wins = report.quick_wins || [];
      if (wins.length) {
        html += '<div class="lgsc-card"><h3>Top ' + Math.min(wins.length, 5) + ' quick wins</h3>';
        html += '<table class="widefat striped"><thead><tr><th>URL</th><th>Issue</th><th>Fix</th><th>Impact</th></tr></thead><tbody>';
        wins.slice(0, 5).forEach(function (w) {
          html += '<tr><td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escHtml(w.url || w.title || '—') + '</td><td>' + escHtml(w.issue || '—') + '</td><td>' + escHtml(w.fix || '—') + '</td><td>' + escHtml(w.impact || 0) + '</td></tr>';
        });
        html += '</tbody></table></div>';
      }
      out.innerHTML = html;
    });
  }

  // ─── AI Assistant ───────────────────────────────────────────────────────
  function appendChatMsg(role, text, suggestions) {
    var thread = document.getElementById('lgsc-chat-thread');
    if (!thread) return;
    var div = document.createElement('div');
    div.className = 'lgsc-chat-msg lgsc-chat-msg-' + role;
    var html = '<div class="lgsc-chat-bubble">' + escHtml(text) + '</div>';
    if (role === 'bot' && suggestions && suggestions.length) {
      html += '<div class="lgsc-chat-suggestions-inline">';
      suggestions.forEach(function (s) {
        html += '<button class="button lgsc-chat-suggest" type="button">' + escHtml(s) + '</button>';
      });
      html += '</div>';
    }
    div.innerHTML = html;
    thread.appendChild(div);
    thread.scrollTop = thread.scrollHeight;
  }

  function sendChatMessage(text) {
    var input = document.getElementById('lgsc-chat-input');
    var sendBtn = document.getElementById('lgsc-chat-send');
    appendChatMsg('user', text);
    if (input) input.value = '';
    appendChatMsg('bot', '…thinking…');
    if (sendBtn) sendBtn.disabled = true;
    postJson('lgsc_shell_assistant', { message: text }, function (err, res) {
      if (sendBtn) sendBtn.disabled = false;
      // Replace the "…thinking…" placeholder (last bot msg).
      var thread = document.getElementById('lgsc-chat-thread');
      if (thread && thread.lastChild) thread.removeChild(thread.lastChild);
      if (err || !res || !res.success) {
        appendChatMsg('bot', 'Sorry — request failed: ' + ((res && res.data && res.data.message) || 'unknown error'));
        return;
      }
      var data = (res.data && res.data.data) || res.data || {};
      var resp = data.response || '(empty response)';
      var sugs = data.suggestions || [];
      appendChatMsg('bot', resp, sugs);
    });
  }

  function wireChat() {
    var form = document.getElementById('lgsc-chat-form');
    var input = document.getElementById('lgsc-chat-input');
    if (!form || !input) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text) return;
      sendChatMessage(text);
    });
    document.addEventListener('click', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('lgsc-chat-suggest')) {
        var t = e.target.textContent.trim();
        if (t) sendChatMessage(t);
      }
    });
  }

  // ─── Boot ───────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    if (document.querySelector('.lgsc-card[data-load="dashboard"]'))    loadDashboard();
    if (document.querySelector('.lgsc-card[data-load="audits"]'))       loadAudits();
    if (document.querySelector('.lgsc-card[data-load="indexed"]'))     { loadIndexed(); wireIndexed(); }
    if (document.querySelector('.lgsc-card[data-load="keywords"]'))     loadKeywords();
    if (document.querySelector('.lgsc-card[data-load="competitors"]'))  loadCompetitors();
    if (document.querySelector('.lgsc-card[data-load="reports"]'))      loadReports();
    if (document.getElementById('lgsc-chat-form'))                      wireChat();
    wirePageAnalyzer();
    wireQuickWins();
    wireLinkOpps();
  });
})();
