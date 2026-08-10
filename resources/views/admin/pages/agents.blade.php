{{-- Agents — /admin/agents
     Renderer for the 'agents' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading agents...</div>');
        const data = await api('/agents');
        if (!data) { renderApiError(); return; }
        const agents = data.agents || [];
        setContent(adminTable({
          id: 'agents',
          data: agents,
          columns: [
            {key: 'name', label: 'Name', sortable: true, render: function(v){ return '<strong>' + v + '</strong>'; }},
            {key: 'slug', label: 'Slug', sortable: true, render: function(v){ return '<span style="color:var(--muted)">' + v + '</span>'; }},
            {key: 'category', label: 'Domain', sortable: true, render: function(v){ return v || '\u2014'; }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ return badge(v || 'active'); }},
            {key: 'is_dmm', label: 'Role', render: function(v){ return v ? '<span class="badge badge-amber">DMM</span>' : '\u2014'; }}
          ],
          searchFields: ['name', 'slug', 'category'],
          defaultSort: {key: 'name', dir: 'asc'},
          filters: [
            {key: 'status', label: 'Status', options: [{value:'', label:'All Statuses'}, {value:'active', label:'Active'}, {value:'inactive', label:'Inactive'}]},
            {key: 'category', label: 'Domain', options: [{value:'', label:'All Domains'}].concat(
              [...new Set(agents.map(function(a){ return a.category; }).filter(Boolean))].sort().map(function(c){ return {value:c, label:c}; })
            )}
          ]
        }));
      }).bind(window.pages);
</script>
