{{-- Users — /admin/users
     Renderer for the 'users' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading users...</div>');
        const data = await api('/users?per_page=200');
        if (!data) { renderApiError(); return; }
        const users = data.data || [];
        setContent(adminTable({
          id: 'users',
          data: users,
          columns: [
            {key: 'name', label: 'Name', sortable: true},
            {key: 'email', label: 'Email', sortable: true, render: function(v){ return '<span style="color:var(--muted)">' + (v||'\u2014') + '</span>'; }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ return badge(v || 'active'); }},
            {key: 'is_platform_admin', label: 'Role', render: function(v){ return v ? '<span class="badge badge-purple">Admin</span>' : '\u2014'; }},
            {key: 'created_at', label: 'Joined', sortable: true, render: function(v){ return ts(v); }},
            {key: '_actions', label: 'Actions', render: function(v,row){ return '<button class="btn btn-ghost btn-sm" onclick="suspendUser('+row.id+',\''+((row.status||'active')).replace(/'/g,"\\'")+'\')">' + (row.status==='suspended'?'Unsuspend':'Suspend') + '</button>'; }}
          ],
          searchFields: ['name', 'email'],
          defaultSort: {key: 'created_at', dir: 'desc'},
          filters: [{key: 'status', label: 'Status', options: [{value:'', label:'All Statuses'}, {value:'active', label:'Active'}, {value:'suspended', label:'Suspended'}]}]
        }));
      }).bind(window.pages);
</script>
