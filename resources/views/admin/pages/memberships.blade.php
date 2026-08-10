{{-- Memberships — /admin/memberships
     Renderer for the 'memberships' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading memberships...</div>');
        const data = await api('/memberships?per_page=200');
        if (!data) { renderApiError(); return; }
        const memberships = data.data || [];
        const roles = ['owner','admin','member','viewer'];
        setContent(adminTable({
          id: 'memberships',
          data: memberships,
          columns: [
            {key: 'user_name', label: 'User', sortable: true},
            {key: 'user_email', label: 'Email', render: function(v){ return '<span style="color:var(--muted)">' + v + '</span>'; }},
            {key: 'workspace_name', label: 'Workspace', sortable: true},
            {key: 'role', label: 'Role', render: function(v,row){ return '<select id="role_' + row.id + '" style="width:auto;padding:4px 8px">' + roles.map(function(r){ return '<option value="' + r + '"' + (row.role===r?' selected':'') + '>' + r + '</option>'; }).join('') + '</select>'; }},
            {key: 'created_at', label: 'Joined', sortable: true, render: function(v){ return ts(v); }},
            {key: '_actions', label: '', render: function(v,row){ return '<button class="btn btn-sm" onclick="saveMemberRole(' + row.id + ')">Save</button>'; }}
          ],
          searchFields: ['user_name', 'user_email', 'workspace_name'],
          defaultSort: {key: 'created_at', dir: 'desc'},
          filters: [{key: 'role', label: 'Role', options: [{value:'', label:'All Roles'}, {value:'owner', label:'Owner'}, {value:'admin', label:'Admin'}, {value:'member', label:'Member'}, {value:'viewer', label:'Viewer'}]}]
        }));
      }).bind(window.pages);
</script>
