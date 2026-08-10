{{-- Queue Monitor — /admin/queue
     Renderer for the 'queue' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading queue...</div>');
        const [queueData, failedData] = await Promise.all([api('/queue'), api('/failed-jobs')]);
        if (!queueData) return;
        const jobs = failedData?.jobs || [];
        const jobRows = jobs.map(j =>
          '<tr>' +
            '<td style="font-size:11px;color:var(--muted)">#' + j.id + '</td>' +
            '<td>' + (j.queue || '\u2014') + '</td>' +
            '<td style="color:var(--muted);font-size:11px" title="' + (j.exception_preview || '').replace(/"/g, '&quot;') + '">' + truncate(j.exception_preview, 80) + '</td>' +
            '<td style="font-size:12px">' + tsTime(j.failed_at) + '</td>' +
            '<td style="white-space:nowrap">' +
              '<button class="btn btn-ghost btn-sm" onclick="retryFailedJob(' + j.id + ')">Retry</button> ' +
              '<button class="btn btn-danger btn-sm" onclick="deleteFailedJob(' + j.id + ')">Delete</button>' +
            '</td>' +
          '</tr>').join('');
        setContent(
          '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + (queueData.pending || 0) + '</div><div class="stat-label">Pending</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + (queueData.running || 0) + '</div><div class="stat-label">Running</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--rd)">' + (queueData.failed_today || 0) + '</div><div class="stat-label">Failed Today</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:' + (queueData.stale > 0 ? 'var(--rd)' : 'var(--ac)') + '">' + (queueData.stale || 0) + '</div><div class="stat-label">Stale</div></div>' +
          '</div>' +
          '<div class="card">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">' +
              '<div class="card-title" style="margin:0">Queue Health</div>' +
              '<div style="display:flex;gap:8px">' +
                '<button class="btn btn-ghost btn-sm" onclick="recoverStale()">Recover Stale</button>' +
                '<span style="color:var(--muted);font-size:12px;line-height:28px">Driver: <strong>' + (queueData.queue_driver || '\u2014') + '</strong></span>' +
              '</div>' +
            '</div>' +
          '</div>' +
          '<div class="card">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">' +
              '<div class="card-title" style="margin:0">Failed Jobs (' + jobs.length + ')</div>' +
              (jobs.length > 0 ? '<button class="btn btn-danger btn-sm" onclick="purgeFailedJobs()">Purge All Failed</button>' : '') +
            '</div>' +
            '<table><thead><tr><th>ID</th><th>Queue</th><th>Error</th><th>Failed At</th><th>Actions</th></tr></thead>' +
            '<tbody>' + (jobRows || '<tr><td colspan="5" style="color:var(--muted)">No failed jobs</td></tr>') + '</tbody></table>' +
          '</div>');
      }).bind(window.pages);
</script>
