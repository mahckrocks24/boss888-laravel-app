{{-- Credits & Transactions — /admin/credits
     Renderer for the 'credits' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading credits...</div>');
        const data = await api('/credits');
        if (!data) { renderApiError(); return; }
        const summary = data.summary || {};
        const credits = data.credits || [];
        const transactions = data.transactions || [];
        const statsHtml = '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + (summary.total_balance || 0) + '</div><div class="stat-label">Total Credits (Platform)</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + (summary.total_reserved || 0) + '</div><div class="stat-label">Reserved Credits</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + (summary.transactions_today || 0) + '</div><div class="stat-label">Transactions Today</div></div>' +
          '</div>';
        const creditsTable = adminTable({
          id: 'credits',
          data: credits,
          columns: [
            {key: 'workspace_name', label: 'Workspace', sortable: true},
            {key: 'balance', label: 'Balance', sortable: true, render: function(v){ return '<span style="font-weight:600">' + v + '</span>'; }},
            {key: 'reserved_balance', label: 'Reserved', sortable: true, render: function(v){ return '<span style="color:var(--am)">' + v + '</span>'; }},
            {key: '_actions', label: 'Actions', render: function(v,row){ return '<button class="btn btn-ghost btn-sm" onclick="adjustWorkspaceCredits(' + row.workspace_id + ',\'' + (row.workspace_name||'').replace(/'/g, "\\'") + '\')">Adjust</button>'; }}
          ],
          searchFields: ['workspace_name'],
          defaultSort: {key: 'balance', dir: 'desc'}
        });
        const txTable = adminTable({
          id: 'transactions',
          data: transactions,
          columns: [
            {key: 'workspace_name', label: 'Workspace', sortable: true},
            {key: 'type', label: 'Type', render: function(v){ return badge(v || 'debit'); }},
            {key: 'amount', label: 'Amount', sortable: true, render: function(v){ return '<span style="font-weight:600;color:' + (v >= 0 ? 'var(--ac)' : 'var(--rd)') + '">' + (v >= 0 ? '+' : '') + v + '</span>'; }},
            {key: 'reference_type', label: 'Reference', render: function(v){ return '<span style="color:var(--muted)">' + (v || '\u2014') + '</span>'; }},
            {key: 'created_at', label: 'Date', sortable: true, render: function(v){ return '<span style="font-size:12px">' + tsTime(v) + '</span>'; }}
          ],
          searchFields: ['workspace_name', 'reference_type'],
          defaultSort: {key: 'created_at', dir: 'desc'}
        });
        setContent(statsHtml + creditsTable + txTable);
      }).bind(window.pages);
</script>
