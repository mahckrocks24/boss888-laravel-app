{{-- Sessions — /admin/sessions
     Renderer for the 'sessions' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading sessions...</div>');
        const data = await api('/sessions');
        if (!data) { renderApiError(); return; }
        const summary = data.summary || {};
        const sessions = (data.sessions?.data || []).map(function(s) {
          if (s.revoked_at) s._status = 'revoked';
          else if (new Date(s.expires_at) < new Date()) s._status = 'expired';
          else s._status = 'active';
          return s;
        });
        const statsHtml = '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + (summary.total_active || 0) + '</div><div class="stat-label">Active Sessions</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--rd)">' + (summary.total_revoked || 0) + '</div><div class="stat-label">Revoked Sessions</div></div>' +
          '</div>';
        setContent(adminTable({
          id: 'sessions',
          data: sessions,
          extraHtml: statsHtml,
          columns: [
            {key: 'user_name', label: 'User', sortable: true, render: function(v,row){ return (v || '\u2014') + '<br><span style="font-size:11px;color:var(--muted)">' + (row.user_email || '') + '</span>'; }},
            {key: 'ip_address', label: 'IP', render: function(v){ return '<span style="color:var(--muted);font-size:12px">' + (v || '\u2014') + '</span>'; }},
            {key: 'user_agent', label: 'Device', render: function(v){ return '<span style="color:var(--muted);font-size:11px" title="' + (v || '').replace(/"/g, '&quot;') + '">' + truncate(v, 40) + '</span>'; }},
            {key: 'created_at', label: 'Created', sortable: true, render: function(v){ return '<span style="font-size:12px">' + tsTime(v) + '</span>'; }},
            {key: 'last_used_at', label: 'Last Active', sortable: true, render: function(v){ return '<span style="font-size:12px">' + tsTime(v) + '</span>'; }},
            {key: 'expires_at', label: 'Expires', render: function(v){ return '<span style="font-size:12px">' + tsTime(v) + '</span>'; }},
            {key: '_status', label: 'Status', sortable: true, render: function(v){ return badge(v); }},
            {key: '_actions', label: '', render: function(v,row){ return row._status === 'active' ? '<button class="btn btn-danger btn-sm" onclick="revokeSession(' + row.session_id + ')">Revoke</button>' : ''; }}
          ],
          searchFields: ['user_name', 'user_email', 'ip_address'],
          defaultSort: {key: 'last_used_at', dir: 'desc'},
          filters: [{key: '_status', label: 'Status', options: [{value:'', label:'All'}, {value:'active', label:'Active'}, {value:'expired', label:'Expired'}, {value:'revoked', label:'Revoked'}]}]
        }));
      }).bind(window.pages);
</script>
