{{-- System Health — /admin/health
     Renderer for the 'health' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading health...</div>');
        const [health, queue] = await Promise.all([api('/health'), api('/queue')]);
        if (!health) return;
        const checks = Object.entries(health.checks || health || {}).map(([k, v]) =>
          '<tr><td>' + k + '</td><td>' + (typeof v === 'object' ? JSON.stringify(v) : v) + '</td></tr>').join('');
        setContent(
          '<div class="card">' +
            '<div class="card-title">System Health</div>' +
            '<table><thead><tr><th>Check</th><th>Value</th></tr></thead>' +
            '<tbody>' + (checks || '<tr><td colspan="2" style="color:var(--muted)">No health data</td></tr>') + '</tbody></table>' +
          '</div>' +
          '<div class="card">' +
            '<div class="card-title">Queue</div>' +
            '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px">' +
              ['pending','running','stale','failed_today'].map(k =>
                '<div class="stat-card"><div class="stat-value" style="font-size:24px">' + (queue?.[k]||0) + '</div><div class="stat-label">' + k.replace('_',' ') + '</div></div>').join('') +
            '</div>' +
          '</div>');
      }).bind(window.pages);
</script>
