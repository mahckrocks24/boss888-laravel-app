{{-- Dashboard — /admin/dashboard
     Renderer for the 'dashboard' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading stats...</div>');
        const [stats, queue] = await Promise.all([api('/stats'), api('/queue')]);
        if (!stats) return;
        document.getElementById('topbar-status').textContent = 'Queue: ' + (queue?.pending || 0) + ' pending';
        setContent(
          '<div class="stats-grid" style="margin-bottom:24px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + (stats.total_users||0) + '</div><div class="stat-label">Active Users</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (stats.total_workspaces||0) + '</div><div class="stat-label">Workspaces</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + (stats.tasks_today||0) + '</div><div class="stat-label">Tasks Today</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + (stats.active_subscriptions||0) + '</div><div class="stat-label">Active Subs</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">$' + Math.round(stats.total_revenue||0) + '</div><div class="stat-label">MRR</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:' + (queue?.stale>0?'var(--rd)':'var(--ac)') + '">' + (queue?.stale||0) + '</div><div class="stat-label">Stale Tasks</div></div>' +
          '</div>' +
          '<div class="card">' +
            '<div class="card-title">Queue Status</div>' +
            '<div style="display:flex;gap:24px;font-size:13px">' +
              '<div><span style="color:var(--muted)">Pending:</span> <strong>' + (queue?.pending||0) + '</strong></div>' +
              '<div><span style="color:var(--muted)">Running:</span> <strong>' + (queue?.running||0) + '</strong></div>' +
              '<div><span style="color:var(--muted)">Failed today:</span> <strong style="color:var(--rd)">' + (queue?.failed_today||0) + '</strong></div>' +
              '<div><span style="color:var(--muted)">Driver:</span> <strong>' + (queue?.queue_driver||'\u2014') + '</strong></div>' +
            '</div>' +
          '</div>'
        );
      }).bind(window.pages);
</script>
