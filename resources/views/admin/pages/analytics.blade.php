{{-- Platform Analytics — /admin/analytics
     Renderer for the 'analytics' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading analytics...</div>');
        const data = await api('/analytics');
        if (!data) { renderApiError(); return; }
        const ug = data.user_growth || {};
        const tv = data.task_volume || {};
        const cc = data.credit_consumption || {};
        const eu = data.engine_usage || [];
        const tw = data.top_workspaces || [];
        const rev = data.revenue || {};

        // Top stat cards
        let html = '<div class="stats-grid" style="margin-bottom:24px">' +
          '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + (ug.total_users || 0) + '</div><div class="stat-label">Total Users</div></div>' +
          '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + (ug.active_7d || 0) + '</div><div class="stat-label">Active (7d)</div></div>' +
          '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + (tv.today || 0) + '</div><div class="stat-label">Tasks Today</div></div>' +
          '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + (cc.today || 0) + '</div><div class="stat-label">Credits Used Today</div></div>' +
          '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">$' + Math.round(rev.mrr || 0) + '</div><div class="stat-label">MRR</div></div>' +
        '</div>';

        // Engine usage bar chart
        if (eu.length > 0) {
          const maxTasks = Math.max(...eu.map(e => e.tasks || 0), 1);
          const bars = eu.map(e => {
            const pct = Math.round(((e.tasks || 0) / maxTasks) * 100);
            return '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">' +
              '<div style="width:120px;font-size:13px;text-align:right;color:var(--muted);flex-shrink:0">' + e.engine + '</div>' +
              '<div style="flex:1;background:var(--s3);border-radius:4px;height:24px;overflow:hidden">' +
                '<div style="width:' + pct + '%;height:100%;background:linear-gradient(90deg,var(--p),var(--bl));border-radius:4px;transition:width .3s"></div>' +
              '</div>' +
              '<div style="width:60px;font-size:13px;font-weight:600">' + (e.tasks || 0) + '</div>' +
            '</div>';
          }).join('');
          html += '<div class="card"><div class="card-title">Engine Usage</div>' + bars + '</div>';
        }

        // Task volume summary
        html += '<div class="card"><div class="card-title">Task Volume</div>' +
          '<div style="display:flex;gap:24px;font-size:13px;flex-wrap:wrap">' +
            '<div><span style="color:var(--muted)">Today:</span> <strong>' + (tv.today || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">This Week:</span> <strong>' + (tv.this_week || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">This Month:</span> <strong>' + (tv.this_month || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">Completed:</span> <strong style="color:var(--ac)">' + (tv.completed || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">Failed:</span> <strong style="color:var(--rd)">' + (tv.failed || 0) + '</strong></div>' +
          '</div></div>';

        // Credit consumption
        html += '<div class="card"><div class="card-title">Credit Consumption</div>' +
          '<div style="display:flex;gap:24px;font-size:13px;flex-wrap:wrap">' +
            '<div><span style="color:var(--muted)">Today:</span> <strong>' + (cc.today || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">This Week:</span> <strong>' + (cc.this_week || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">This Month:</span> <strong>' + (cc.this_month || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">Platform Total:</span> <strong style="color:var(--am)">' + (cc.total || 0) + '</strong></div>' +
          '</div></div>';

        // User growth sparkline
        const daily = ug.daily || [];
        if (daily.length > 0) {
          const maxDaily = Math.max(...daily.map(d => d.count || 0), 1);
          const sparkBars = daily.map(d => {
            const h = Math.max(Math.round(((d.count || 0) / maxDaily) * 60), 2);
            return '<div style="display:flex;flex-direction:column;align-items:center;gap:4px">' +
              '<div style="font-size:11px;font-weight:600">' + (d.count || 0) + '</div>' +
              '<div style="width:20px;height:' + h + 'px;background:var(--ac);border-radius:3px"></div>' +
              '<div style="font-size:10px;color:var(--muted)">' + (d.date ? d.date.slice(5) : '') + '</div>' +
            '</div>';
          }).join('');
          html += '<div class="card"><div class="card-title">User Growth (Daily Signups)</div>' +
            '<div style="display:flex;align-items:flex-end;gap:8px;padding-top:8px">' + sparkBars + '</div></div>';
        }

        // Top workspaces table
        if (tw.length > 0) {
          const wsRows = tw.map(w =>
            '<tr>' +
              '<td><strong>' + (w.name || '\u2014') + '</strong></td>' +
              '<td>' + badge(w.plan || 'free') + '</td>' +
              '<td>' + (w.tasks || 0) + '</td>' +
              '<td>' + (w.credits_used || 0) + '</td>' +
            '</tr>').join('');
          html += '<div class="card"><div class="card-title">Top Workspaces</div>' +
            '<table><thead><tr><th>Workspace</th><th>Plan</th><th>Tasks</th><th>Credits Used</th></tr></thead>' +
            '<tbody>' + wsRows + '</tbody></table></div>';
        }

        // Revenue
        html += '<div class="card"><div class="card-title">Revenue</div>' +
          '<div style="display:flex;gap:24px;font-size:13px;flex-wrap:wrap">' +
            '<div><span style="color:var(--muted)">MRR:</span> <strong style="color:var(--ac)">$' + Math.round(rev.mrr || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">Active Subs:</span> <strong>' + (rev.active_subscriptions || 0) + '</strong></div>' +
            '<div><span style="color:var(--muted)">Trialing:</span> <strong>' + (rev.trialing || 0) + '</strong></div>' +
          '</div></div>';

        setContent(html);
      }).bind(window.pages);
</script>
