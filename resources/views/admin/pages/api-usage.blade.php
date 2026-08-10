{{-- API Usage & Costs — /admin/api-usage
     Renderer for the 'apiUsage' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading API usage...</div>';
        try {
          var d = await api('/api-usage');
          if (!d) return;
          var s = d.summary || {};
          var today = s.today || {}; var month = s.this_month || {}; var total = s.total || {};
          var providers = d.by_provider || [];
          var recent = d.recent || [];
          var h = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px">';
          h += '<div class="stat-card"><div class="stat-value">' + (today.calls||0) + '</div><div class="stat-label">Calls Today</div></div>';
          h += '<div class="stat-card"><div class="stat-value">' + (today.tokens||0).toLocaleString() + '</div><div class="stat-label">Tokens Today</div></div>';
          h += '<div class="stat-card"><div class="stat-value">$' + parseFloat(today.cost_usd||0).toFixed(4) + '</div><div class="stat-label">Cost Today</div></div>';
          h += '<div class="stat-card"><div class="stat-value">$' + parseFloat(month.cost_usd||0).toFixed(4) + '</div><div class="stat-label">Cost This Month</div></div>';
          h += '</div>';
          h += '<h4 style="font-size:14px;font-weight:700;margin:0 0 12px">Provider Breakdown</h4>';
          h += '<table class="data-table" style="margin-bottom:24px"><thead><tr><th>Provider</th><th>Calls</th><th>Tokens</th><th>Cost</th><th>Avg Response</th></tr></thead><tbody>';
          providers.forEach(function(p) {
            h += '<tr><td style="font-weight:600">' + p.provider + '</td><td>' + p.calls + '</td><td>' + (p.tokens||0).toLocaleString() + '</td><td>$' + parseFloat(p.cost_usd||0).toFixed(4) + '</td><td>' + (p.avg_ms||0) + 'ms</td></tr>';
          });
          if (!providers.length) h += '<tr><td colspan="5" style="text-align:center;color:#6B7280;padding:20px">No API calls logged yet</td></tr>';
          h += '</tbody></table>';
          h += '<h4 style="font-size:14px;font-weight:700;margin:0 0 12px">Recent Calls (' + recent.length + ')</h4>';
          h += '<div style="max-height:400px;overflow-y:auto"><table class="data-table"><thead><tr><th>Time</th><th>Provider</th><th>Model</th><th>Tokens</th><th>Cost</th><th>Duration</th><th>Status</th></tr></thead><tbody>';
          recent.forEach(function(r) {
            var ok = r.status === 'success';
            h += '<tr><td>' + new Date(r.created_at).toLocaleString() + '</td><td>' + r.provider + '</td><td>' + (r.model||'—') + '</td><td>' + (r.total_tokens||0) + '</td><td>$' + parseFloat(r.cost_usd||0).toFixed(4) + '</td><td>' + (r.duration_ms||0) + 'ms</td><td>' + (ok?'✅':'❌') + '</td></tr>';
          });
          if (!recent.length) h += '<tr><td colspan="7" style="text-align:center;color:#6B7280;padding:20px">No calls yet</td></tr>';
          h += '</tbody></table></div>';
          h += '<div style="margin-top:16px;font-size:11px;color:#4A566B">Total lifetime: ' + (total.calls||0) + ' calls · ' + (total.tokens||0).toLocaleString() + ' tokens · $' + parseFloat(total.cost_usd||0).toFixed(4) + '</div>';
          content().innerHTML = h;
        } catch(e) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Failed to load: ' + e.message + '</div>'; }
      });
</script>
