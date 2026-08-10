{{-- Task Monitor — /admin/tasks
     Renderer for the 'tasks' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading tasks...</div>');
        const data = await api('/tasks?per_page=200');
        if (!data) { renderApiError(); return; }
        const tasks = data.data || [];
        setContent(adminTable({
          id: 'tasks',
          data: tasks,
          extraHtml: '<div style="margin-bottom:12px;text-align:right"><button class="btn btn-ghost btn-sm" onclick="recoverStale()">Recover Stale</button></div>',
          columns: [
            {key: 'id', label: 'ID', sortable: true, render: function(v){ return '<span style="font-size:11px;color:var(--muted)">#' + v + '</span>'; }},
            {key: 'engine', label: 'Engine / Action', sortable: true, render: function(v,row){ return (row.engine || '\u2014') + ' / ' + (row.action || '\u2014'); }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ return badge(v); }},
            {key: 'created_at', label: 'Created', sortable: true, render: function(v){ return '<span style="color:var(--muted)">' + ts(v) + '</span>'; }},
            {key: '_actions', label: '', render: function(v,row){ return row.status === 'failed' ? '<button class="btn btn-ghost btn-sm" onclick="retryTask(' + row.id + ')">Retry</button>' : ''; }}
          ],
          searchFields: ['engine', 'action'],
          defaultSort: {key: 'created_at', dir: 'desc'},
          filters: [{key: 'status', label: 'Status', options: [{value:'', label:'All Statuses'}, {value:'pending', label:'Pending'}, {value:'running', label:'Running'}, {value:'completed', label:'Completed'}, {value:'failed', label:'Failed'}]}]
        }));
      }).bind(window.pages);
</script>
