{{-- Capability Map — /admin/capabilities
     Renderer for the 'capabilities' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading capability map...</div>');
        const data = await api('/engines/capabilities');
        if (!data) { renderApiError(); return; }
        const caps = data.capabilities || [];
        const enginesSet = [...new Set(caps.map(function(c){ return c.engine; }))];
        const autoCount = caps.filter(function(c){ return c.approval_mode === 'auto'; }).length;
        const reviewCount = caps.filter(function(c){ return c.approval_mode === 'review'; }).length;
        const protectedCount = caps.filter(function(c){ return c.approval_mode === 'protected'; }).length;
        const statsHtml = '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + caps.length + '</div><div class="stat-label">Total Capabilities</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + enginesSet.length + '</div><div class="stat-label">Engines</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + autoCount + '</div><div class="stat-label">Auto-Approved</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + reviewCount + '</div><div class="stat-label">Review Required</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + protectedCount + '</div><div class="stat-label">Protected</div></div>' +
          '</div>';
        setContent(adminTable({
          id: 'capabilities',
          data: caps,
          extraHtml: statsHtml,
          columns: [
            {key: 'action', label: 'Action', sortable: true, render: function(v){ return '<strong>' + (v || '\u2014') + '</strong>'; }},
            {key: 'engine', label: 'Engine', sortable: true, render: function(v){ return v || '\u2014'; }},
            {key: 'credit_cost', label: 'Credits', sortable: true, render: function(v){ var c = parseInt(v) || 0; if (c === 0) return '<span class="badge" style="background:rgba(148,163,184,.12);color:var(--muted)">0</span>'; if (c <= 5) return '<span class="badge badge-green">' + c + '</span>'; if (c <= 15) return '<span class="badge badge-blue">' + c + '</span>'; return '<span class="badge badge-purple">' + c + '</span>'; }},
            {key: 'approval_mode', label: 'Approval', sortable: true, render: function(v){ var map = {auto:'green', review:'blue', protected:'amber'}; return '<span class="badge badge-' + (map[v] || 'blue') + '">' + (v || 'auto') + '</span>'; }},
            {key: 'connector', label: 'Connector', render: function(v){ return '<span style="color:var(--muted)">' + (v || '\u2014') + '</span>'; }}
          ],
          searchFields: ['action', 'engine', 'connector'],
          defaultSort: {key: 'engine', dir: 'asc'},
          filters: [
            {key: 'engine', label: 'Engine', options: [{value:'', label:'All Engines'}].concat(enginesSet.sort().map(function(e){ return {value:e, label:e}; }))},
            {key: 'approval_mode', label: 'Approval', options: [{value:'', label:'All'}, {value:'auto', label:'Auto'}, {value:'review', label:'Review'}, {value:'protected', label:'Protected'}]}
          ]
        }));
      }).bind(window.pages);
</script>
