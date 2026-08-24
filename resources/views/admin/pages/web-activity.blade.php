{{-- Agent Web Activity — /admin/web-activity
     Renderer for the 'webActivity' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading agent web activity...</div>';
        try {
          // Read filter values (default empty)
          const ws     = document.getElementById('wa-ws')?.value || '';
          const agent  = document.getElementById('wa-agent')?.value || '';
          const action = document.getElementById('wa-action')?.value || '';
          const status = document.getElementById('wa-status')?.value || '';
          const qs     = new URLSearchParams();
          if (ws)     qs.set('workspace_id', ws);
          if (agent)  qs.set('agent', agent);
          if (action) qs.set('action', action);
          if (status) qs.set('status', status);
          qs.set('limit', '300');

          const d = await api('/web-activity' + (qs.toString() ? '?' + qs.toString() : ''));
          if (!d) return;

          const rows = d.rows || [];
          const today = d.today || {};
          const byStatus = d.by_status_7d || [];
          const statusMap = byStatus.reduce(function (m, r) { m[r.status] = r.count; return m; }, {});

          // Distinct agents for dropdown
          let agentList = [];
          try { const ad = await api('/web-activity/agents'); agentList = (ad && ad.agents) || []; } catch (_) {}

          // -- Header stats --
          let h = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">';
          h += '<div class="stat-card"><div class="stat-value">' + (today.total || 0) + '</div><div class="stat-label">Calls Today</div></div>';
          h += '<div class="stat-card"><div class="stat-value">' + (today.credits || 0) + '</div><div class="stat-label">Credits Today</div></div>';
          h += '<div class="stat-card"><div class="stat-value">' + (today.workspaces || 0) + '</div><div class="stat-label">Workspaces Active</div></div>';
          h += '<div class="stat-card"><div class="stat-value">' + (today.agents || 0) + '</div><div class="stat-label">Agents Active</div></div>';
          h += '</div>';

          // -- 7-day status pill row --
          h += '<div style="margin-bottom:16px;font-size:12px;color:var(--muted)">Last 7 days: ';
          ['ok', 'blocked', 'capped', 'error', 'pending'].forEach(function (s) {
            if (statusMap[s]) h += '<span style="margin-right:14px"><b style="color:var(--text)">' + statusMap[s] + '</b> ' + s + '</span>';
          });
          h += '</div>';

          // -- Filter bar --
          const agentOpts = ['<option value="">All agents</option>']
            .concat(agentList.map(function (a) {
              const sel = (a.agent_slug === agent) ? ' selected' : '';
              return '<option value="' + a.agent_slug + '"' + sel + '>' + a.agent_slug + ' (' + a.count + ')</option>';
            }))
            .join('');
          h += '<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:end">';
          h += '<div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px">Workspace ID</label><input id="wa-ws" value="' + ws + '" placeholder="any" style="padding:6px 10px;background:var(--s2);border:1px solid var(--bd);color:var(--text);border-radius:6px;width:100px" /></div>';
          h += '<div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px">Agent</label><select id="wa-agent" style="padding:6px 10px;background:var(--s2);border:1px solid var(--bd);color:var(--text);border-radius:6px">' + agentOpts + '</select></div>';
          h += '<div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px">Action</label><select id="wa-action" style="padding:6px 10px;background:var(--s2);border:1px solid var(--bd);color:var(--text);border-radius:6px"><option value="">all</option><option value="fetch"' + (action === 'fetch' ? ' selected' : '') + '>fetch</option><option value="search"' + (action === 'search' ? ' selected' : '') + '>search</option></select></div>';
          h += '<div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px">Status</label><select id="wa-status" style="padding:6px 10px;background:var(--s2);border:1px solid var(--bd);color:var(--text);border-radius:6px"><option value="">all</option><option value="ok"' + (status === 'ok' ? ' selected' : '') + '>ok</option><option value="blocked"' + (status === 'blocked' ? ' selected' : '') + '>blocked</option><option value="error"' + (status === 'error' ? ' selected' : '') + '>error</option><option value="capped"' + (status === 'capped' ? ' selected' : '') + '>capped</option></select></div>';
          h += '<button onclick="pages.webActivity()" style="padding:7px 14px;background:var(--p);color:white;border:none;border-radius:6px;cursor:pointer;font-weight:600">Apply</button>';
          h += '</div>';

          // -- Table --
          h += '<div style="background:var(--s1);border:1px solid var(--border);border-radius:10px;overflow:hidden">';
          h += '<table class="data-table" style="margin:0"><thead><tr>';
          h += '<th>Time</th><th>WS</th><th>Workspace</th><th>Agent</th><th>Action</th><th>Target</th><th>Status</th><th>Title</th><th>Cost</th><th>Dur</th><th></th>';
          h += '</tr></thead><tbody>';

          if (!rows.length) {
            h += '<tr><td colspan="11" style="text-align:center;padding:30px;color:var(--muted)">No activity matches the current filters.</td></tr>';
          }
          rows.forEach(function (r) {
            const statusColor = { ok: 'var(--ac)', blocked: 'var(--rd)', capped: 'var(--am)', error: 'var(--rd)', pending: 'var(--bl)' }[r.status] || 'var(--muted)';
            const target = (r.url_or_query || '').length > 60 ? r.url_or_query.substring(0, 57) + '…' : (r.url_or_query || '');
            const title  = (r.title || '').length > 50 ? r.title.substring(0, 47) + '…' : (r.title || '—');
            h += '<tr>';
            h += '<td style="white-space:nowrap">' + tsTime(r.created_at) + '</td>';
            h += '<td>' + (r.workspace_id || '—') + '</td>';
            h += '<td>' + truncate(r.workspace_name || '—', 18) + '</td>';
            h += '<td style="font-weight:600">' + r.agent_slug + '</td>';
            h += '<td>' + r.action + '</td>';
            h += '<td title="' + (r.url_or_query || '').replace(/"/g, '&quot;') + '" style="font-family:monospace;font-size:11px;color:var(--muted)">' + target + '</td>';
            h += '<td><span style="color:' + statusColor + '">●</span> ' + r.status + '</td>';
            h += '<td>' + title + '</td>';
            h += '<td>' + (r.cost_credits || 0) + '</td>';
            h += '<td>' + (r.duration_ms || '—') + (r.duration_ms ? 'ms' : '') + '</td>';
            h += '<td><button onclick="_waShowDetail(' + r.id + ')" style="padding:3px 8px;background:var(--s2);color:var(--text);border:1px solid var(--bd);border-radius:4px;cursor:pointer;font-size:11px">view</button></td>';
            h += '</tr>';
          });

          h += '</tbody></table></div>';
          h += '<div style="margin-top:12px;font-size:11px;color:var(--muted)">Showing ' + rows.length + ' rows. Filter to narrow.</div>';

          // Hidden modal area for detail-view
          h += '<div id="wa-detail-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.65);z-index:1000;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display=\'none\'"></div>';

          // Stash the rows so the detail-view modal can read them client-side
          window._waRows = rows.reduce(function (m, r) { m[r.id] = r; return m; }, {});

          content().innerHTML = h;
        } catch (e) {
          content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Failed to load: ' + e.message + '</div>';
        }
      });
</script>
