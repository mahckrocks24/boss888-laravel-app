{{-- Engine Registry — /admin/engines
     Renderer for the 'engines' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading engine registry...</div>');
        const data = await api('/engines/registry');
        if (!data) { renderApiError(); return; }
        const engines = data.engines || [];
        const statsHtml = '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + engines.length + '</div><div class="stat-label">Total Engines</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + engines.filter(function(e){ return e.status === 'active'; }).length + '</div><div class="stat-label">Active</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + engines.reduce(function(s,e){ return s + (e.route_count||0); }, 0) + '</div><div class="stat-label">Total Routes</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + engines.reduce(function(s,e){ return s + (e.total_tasks||0); }, 0) + '</div><div class="stat-label">Total Tasks</div></div>' +
          '</div>';
        setContent(adminTable({
          id: 'engines',
          data: engines,
          extraHtml: statsHtml,
          columns: [
            {key: 'name', label: 'Engine', sortable: true, render: function(v,row){ var c = row.status === 'active' ? 'var(--ac)' : 'var(--rd)'; return '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + c + ';margin-right:6px"></span><strong>' + v + '</strong>'; }},
            {key: 'version', label: 'Version', render: function(v){ return '<span class="badge badge-purple">v' + (v || '1.0') + '</span>'; }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ return badge(v || 'active'); }},
            {key: 'route_count', label: 'Routes', sortable: true, render: function(v){ return '<strong>' + (v || 0) + '</strong>'; }},
            {key: 'total_tasks', label: 'Tasks', sortable: true, render: function(v){ return '<strong>' + (v || 0) + '</strong>'; }},
            {key: 'failed_tasks', label: 'Failed', sortable: true, render: function(v){ return '<strong style="color:' + ((v||0) > 0 ? 'var(--rd)' : 'var(--ac)') + '">' + (v || 0) + '</strong>'; }},
            {key: 'last_execution', label: 'Last Run', sortable: true, render: function(v){ return v ? ts(v) : '\u2014'; }}
          ],
          searchFields: ['name'],
          defaultSort: {key: 'name', dir: 'asc'},
          filters: [{key: 'status', label: 'Status', options: [{value:'', label:'All'}, {value:'active', label:'Active'}, {value:'inactive', label:'Inactive'}]}]
        }));
      }).bind(window.pages);
</script>
