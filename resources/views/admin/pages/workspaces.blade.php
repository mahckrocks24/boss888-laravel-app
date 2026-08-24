{{-- Workspaces — /admin/workspaces
     Renderer for the 'workspaces' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading workspaces...</div>');
        const data = await api('/workspaces?per_page=200');
        if (!data) { renderApiError(); return; }
        const workspaces = (data.data || []).map(function(w){ w._status = w.onboarded ? 'onboarded' : 'setup'; return w; });
        setContent(adminTable({
          id: 'workspaces',
          data: workspaces,
          columns: [
            {key: 'name', label: 'Workspace', sortable: true, render: function(v,row){ return '<strong>' + v + '</strong>' + (row.business_name ? '<br><span style="font-size:11px;color:var(--muted)">' + row.business_name + '</span>' : ''); }},
            {key: 'industry', label: 'Industry', render: function(v){ return '<span style="color:var(--muted)">' + (v || '\u2014') + '</span>'; }},
            {key: 'users_count', label: 'Team', sortable: true, render: function(v){ return (v || 0) + ' members'; }},
            {key: '_status', label: 'Status', render: function(v){ return v === 'onboarded' ? '<span class="badge badge-green">Onboarded</span>' : '<span class="badge badge-amber">Setup</span>'; }},
            {key: 'created_at', label: 'Created', sortable: true, render: function(v){ return ts(v); }},
            {key: '_actions', label: '', render: function(v,row){ return '<button class="btn btn-ghost btn-sm" onclick="viewWorkspace(' + row.id + ')">View</button>'; }}
          ],
          searchFields: ['name', 'business_name', 'industry'],
          defaultSort: {key: 'created_at', dir: 'desc'},
          filters: [{key: '_status', label: 'Status', options: [{value:'', label:'All'}, {value:'onboarded', label:'Onboarded'}, {value:'setup', label:'Setup'}]}]
        }));
      }).bind(window.pages);
</script>
