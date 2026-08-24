{{-- Engineering — Marketing Tasks — /admin/engineering/tasks
     Renderer for the 'engTasks' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading marketing tasks...</div>';
        const qs = new URLSearchParams(); qs.set('limit', '100');
        if (window._engTaskStatus) qs.set('filter[status]', window._engTaskStatus);
        const d = await api('/engineering/marketing-tasks?' + qs.toString());
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load tasks.</div>'; return; }
        const p = (d.profile && d.profile.value) || {};
        let h = '';

        h += '<div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.30);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
        h += '<div style="font-weight:600;margin-bottom:6px">Customer marketing work &mdash; not engineering execution</div>';
        h += '<div style="font-size:12px;color:var(--muted);line-height:1.7">';
        (d.interpretation_limits || []).forEach(function (l) { h += '<div>&bull; ' + esc(l) + '</div>'; });
        h += '</div></div>';

        h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">';
        h += '<div class="stat-card"><div class="stat-value">' + esc(p.total_tasks || 0) + '</div><div class="stat-label">Tasks</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc((p.by_status || {}).completed || 0) + '</div><div class="stat-label">Completed</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc((p.by_status || {}).failed || 0) + '</div><div class="stat-label">Failed</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(p.task_events || 0) + '</div><div class="stat-label">Task events</div></div>';
        h += '</div>';

        h += '<div style="margin-bottom:12px;font-size:13px">Status: <select onchange="window._engTaskStatus=this.value||null;pages.engTasks()" style="background:var(--s2);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:5px 8px">';
        h += '<option value="">(all)</option>';
        Object.keys(p.by_status || {}).forEach(function (s) {
          h += '<option value="' + esc(s) + '"' + (window._engTaskStatus === s ? ' selected' : '') + '>' + esc(s) + ' (' + esc(p.by_status[s]) + ')</option>';
        });
        h += '</select> <span style="font-size:11px;color:var(--muted);margin-left:8px">' + esc((d.index_use.applied || []).join(', ') || 'no filter') + '</span></div>';

        h += '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">';
        h += '<thead><tr style="text-align:left;color:var(--muted);border-bottom:1px solid var(--border)"><th style="padding:6px">id</th><th>engine</th><th>action</th><th>cat</th><th>status</th><th>appr</th><th>cr</th><th>completed</th></tr></thead><tbody>';
        (d.entries || []).forEach(function (e) {
          const sc = e.status === 'completed' ? '#34D399' : (e.status === 'failed' ? '#F87171' : '#F59E0B');
          h += '<tr style="border-bottom:1px solid var(--border)">';
          h += '<td style="padding:6px;color:var(--muted)">' + esc(e.id) + '</td>';
          h += '<td>' + esc(e.engine) + '</td>';
          h += '<td><code>' + esc(e.action) + '</code></td>';
          h += '<td>' + esc(e.category || '') + '</td>';
          h += '<td style="color:' + sc + '">' + esc(e.status) + '</td>';
          h += '<td style="color:var(--muted)">' + esc(e.approval_status || '&mdash;') + '</td>';
          h += '<td>' + esc(e.credit_cost === null ? '' : e.credit_cost) + '</td>';
          h += '<td style="white-space:nowrap;color:var(--muted)">' + esc(e.completed_at || '') + '</td>';
          h += '</tr>';
        });
        h += '</tbody></table></div>';
        h += '<div style="margin-top:12px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">Showing ' + esc(d.returned) + ' of ' + esc(d.matched) + ' &middot; Read-only &middot; observed ' + esc(d.observed_at) + '</div>';
        content().innerHTML = h;
      });
</script>
