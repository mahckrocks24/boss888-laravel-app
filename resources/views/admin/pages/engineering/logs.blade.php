{{-- Engineering — Application Log — /admin/engineering/logs
     Renderer for the 'engLogs' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading application log...</div>';
        const qs = new URLSearchParams();
        qs.set('limit', '150');
        if (window._engLogTests) qs.set('filter[include_tests]', 'yes');
        if (window._engLogLevel) qs.set('filter[level]', window._engLogLevel);
        const d = await api('/engineering/logs?' + qs.toString());
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load the log.</div>'; return; }

        if (d.stream && d.stream.available === false) {
          content().innerHTML = '<div style="padding:30px"><strong style="color:#F59E0B">BLOCKED</strong><div style="margin-top:8px;color:var(--muted)">' + esc(d.stream.blocked_reason) + '</div></div>';
          return;
        }

        const c = (d.census && d.census.value) || {};
        const tail = d.tail || {};
        let h = '';

        // TD-24 stated up front, with real numbers.
        h += '<div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
        h += '<div style="font-weight:600;margin-bottom:6px">The test suite writes into this same file</div>';
        h += '<div style="font-size:12px;color:var(--muted);line-height:1.7">Of <strong>' + esc(c.total_lines) + '</strong> lines, <strong>' + esc(c.test_lines) + '</strong> are on a test channel (' + esc((c.test_channels||[]).join(', ')) + '). They are excluded by default so this view reflects production activity.</div>';
        h += '</div>';

        h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px">';
        h += '<div class="stat-card"><div class="stat-value">' + esc(c.total_lines || 0) + '</div><div class="stat-label">Lines in file</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(c.test_lines || 0) + '</div><div class="stat-label">Test-channel lines</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(tail.lines_read || 0) + '</div><div class="stat-label">Tail read</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(d.matched || 0) + '</div><div class="stat-label">Matched</div></div>';
        h += '</div>';

        h += '<div style="margin-bottom:12px;font-size:13px">';
        h += '<label style="cursor:pointer"><input type="checkbox"' + (window._engLogTests ? ' checked' : '') + ' onchange="window._engLogTests=this.checked;pages.engLogs()"> include test-channel entries</label>';
        h += ' &nbsp; level: <select onchange="window._engLogLevel=this.value||null;pages.engLogs()" style="background:var(--s2);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:5px 8px">';
        ['', 'ERROR', 'WARNING', 'INFO', 'DEBUG', 'CRITICAL'].forEach(function (l) {
          h += '<option value="' + l + '"' + (window._engLogLevel === l ? ' selected' : '') + '>' + (l || '(all)') + '</option>';
        });
        h += '</select></div>';

        const lc = { ERROR: '#F87171', CRITICAL: '#F87171', WARNING: '#F59E0B', INFO: '#6B7280', DEBUG: '#6B7280' };
        (d.entries || []).forEach(function (e) {
          h += '<div style="border-left:3px solid ' + (lc[e.level] || '#6B7280') + ';padding:6px 10px;margin-bottom:4px;background:var(--s2);border-radius:4px">';
          h += '<div style="font-size:11px;color:var(--muted)">' + esc(e.at) + ' &middot; <strong style="color:' + (lc[e.level] || '#6B7280') + '">' + esc(e.level) + '</strong> &middot; ' + esc(e.channel);
          if (e.is_test) h += ' <span style="background:#F59E0B22;color:#F59E0B;border-radius:3px;padding:1px 5px;font-size:10px">TEST</span>';
          h += '</div>';
          h += '<div style="font-size:12px;word-break:break-word">' + esc(e.message) + '</div></div>';
        });

        h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:12px 14px;margin-top:12px;font-size:12px;color:var(--muted);line-height:1.8">';
        (d.interpretation_limits || []).forEach(function (l) { h += '<div>&bull; ' + esc(l) + '</div>'; });
        h += '</div>';

        h += '<div style="margin-top:12px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">Read-only &middot; observed ' + esc(d.observed_at) + ' &middot; GET /api/admin/engineering/logs</div>';
        content().innerHTML = h;
      });
</script>
