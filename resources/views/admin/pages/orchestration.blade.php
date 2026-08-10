{{-- Orchestration Health — /admin/orchestration
     Renderer for the 'orchestration' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading orchestration health...</div>');
        const data = await api('/orchestration/health');
        if (!data) { renderApiError('orchestration health'); return; }

        const statusColor = {
          completed:'#10b981', running:'#3b82f6', queued:'#8b5cf6',
          pending:'#f59e0b',   awaiting_approval:'#f59e0b',
          failed:'#ef4444',    cancelled:'#6b7280',
          blocked:'#fb923c',   degraded:'#fb923c',
          verifying:'#06b6d4',
        };
        const statusBadge = function(s){
          var c = statusColor[s] || '#6b7280';
          return '<span style="background:'+c+';color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;text-transform:uppercase;letter-spacing:0.04em">'+s+'</span>';
        };
        const fmtMs = function(ms){
          if (ms === null || ms === undefined) return '—';
          if (ms < 1000) return ms + 'ms';
          return (ms/1000).toFixed(1) + 's';
        };

        var html = '<div style="padding:0 4px">';

        // Stat cards — task histogram
        html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:24px">';
        (data.task_stats || []).forEach(function(s){
          html += '<div style="background:var(--s1);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center">'
                + '<div style="font-size:22px;font-weight:700;color:var(--p)">' + s.count + '</div>'
                + '<div style="font-size:11px;color:var(--muted);margin-top:4px;text-transform:uppercase;letter-spacing:0.05em">' + s.status + '</div>'
                + (s.avg_duration_ms ? '<div style="font-size:10px;color:var(--muted);margin-top:2px">avg ' + fmtMs(s.avg_duration_ms) + '</div>' : '')
                + '</div>';
        });
        html += '</div>';

        // Two-column: queue depth + agent perf
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">';

        // Queue depth (Redis-backed)
        html += '<div><h4 style="font-size:13px;font-weight:700;margin:0 0 10px">Queue Depth (Redis)</h4>'
              + '<div style="background:var(--s1);border:1px solid var(--border);border-radius:10px;overflow:hidden">';
        var qd = data.queue_depth || {};
        Object.keys(qd).forEach(function(name){
          var v = qd[name];
          var label, value;
          if (typeof v === 'number' || typeof v === 'string') {
            label = name; value = v; // failed_jobs_db
          } else if (v && typeof v === 'object') {
            var total = (v.ready||0) + (v.delayed||0) + (v.reserved||0);
            label = name + ' <span style="color:var(--muted);font-size:10px">(ready ' + (v.ready||0) + ' · delayed ' + (v.delayed||0) + ' · reserved ' + (v.reserved||0) + ')</span>';
            value = total;
          } else {
            label = name; value = '—';
          }
          var color = (typeof value === 'number' && value > 0) ? '#ef4444' : '#10b981';
          html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid var(--border)">'
                + '<span style="font-size:12px">' + label + '</span>'
                + '<span style="font-size:13px;font-weight:600;color:' + color + '">' + value + '</span>'
                + '</div>';
        });
        var orphColor = (data.orphans||0) > 0 ? '#ef4444' : '#10b981';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px">'
              + '<span style="font-size:12px;color:var(--muted)">Orphans detected</span>'
              + '<span style="font-size:13px;font-weight:600;color:' + orphColor + '">' + (data.orphans||0) + '</span>'
              + '</div>';
        html += '</div></div>';

        // Agent perf
        html += '<div><h4 style="font-size:13px;font-weight:700;margin:0 0 10px">Agent Performance</h4>'
              + '<div style="background:var(--s1);border:1px solid var(--border);border-radius:10px;overflow:hidden">';
        var agents = (data.agent_perf || []).slice(0, 10);
        if (!agents.length) {
          html += '<div style="padding:14px;text-align:center;color:var(--muted);font-size:12px">No agent task history yet</div>';
        } else {
          agents.forEach(function(a){
            html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 14px;border-bottom:1px solid var(--border)">'
                  + '<span style="font-size:12px"><strong>' + a.agent_name + '</strong> <span style="color:var(--muted);font-size:10px">' + a.agent_slug + '</span></span>'
                  + '<div style="display:flex;gap:10px;font-size:11px">'
                  +   '<span style="color:#10b981">' + (a.completed||0) + '✓</span>'
                  +   '<span style="color:#ef4444">' + (a.failed||0) + '✗</span>'
                  +   '<span style="color:var(--muted)">' + (a.avg_ms ? fmtMs(a.avg_ms) : '—') + '</span>'
                  + '</div></div>';
          });
        }
        html += '</div></div>';

        html += '</div>'; // end two-col

        // Recover-orphans button + state machine link
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">'
              + '<h4 style="font-size:13px;font-weight:700;margin:0">Recent Tasks</h4>'
              + '<button class="btn btn-ghost btn-sm" onclick="recoverOrphansAdmin()">Recover Orphans</button>'
              + '</div>';

        // Recent tasks table — recent_tasks already has `agent` extracted by OrchestrationHealthService
        html += adminTable({
          id: 'orchestration_recent',
          data: data.recent_tasks || [],
          columns: [
            {key: 'id', label: 'ID', sortable: true, render: function(v){ return '<span style="font-size:11px;color:var(--muted)">#' + v + '</span>'; }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ return statusBadge(v); }},
            {key: 'engine', label: 'Action', sortable: true, render: function(v,row){ return (row.engine||'—') + '/' + (row.action||'—'); }},
            {key: 'agent', label: 'Agent', sortable: true, render: function(v){ return v || '—'; }},
            {key: 'duration_ms', label: 'Duration', sortable: true, render: function(v){ return fmtMs(v); }},
            {key: 'retry_count', label: 'Retries', render: function(v){ return v ? v : '—'; }},
            {key: 'updated_at', label: 'Updated', sortable: true, render: function(v){ return '<span style="color:var(--muted);font-size:11px">' + tsTime(v) + '</span>'; }},
          ],
          searchFields: ['engine','action','agent','status'],
          defaultSort: {key: 'updated_at', dir: 'desc'},
        });

        html += '<div style="margin-top:18px;font-size:10px;color:var(--muted);text-align:right">Snapshot: ' + (data.generated_at || new Date().toISOString()) + '</div>';
        html += '</div>';

        setContent(html);
      }).bind(window.pages);
</script>
