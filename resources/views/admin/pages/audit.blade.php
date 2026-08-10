{{-- Audit Logs — /admin/audit
     Renderer for the 'audit' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading audit logs...</div>');
        const data = await api('/audit-logs?per_page=200');
        if (!data) { renderApiError(); return; }
        const logs = data.data || [];
        setContent(adminTable({
          id: 'audit',
          data: logs,
          columns: [
            {key: 'created_at', label: 'Time', sortable: true, render: function(v){ return '<span style="font-size:11px;color:var(--muted)">' + tsTime(v) + '</span>'; }},
            {key: 'action', label: 'Action', sortable: true, render: function(v){ return v || '\u2014'; }},
            {key: 'workspace_id', label: 'Workspace', render: function(v){ return v || '\u2014'; }},
            {key: 'user_id', label: 'User', render: function(v){ return v || '\u2014'; }}
          ],
          searchFields: ['action', 'user_id', 'workspace_id'],
          defaultSort: {key: 'created_at', dir: 'desc'}
        }));
      }).bind(window.pages);
</script>
