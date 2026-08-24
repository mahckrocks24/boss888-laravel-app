{{-- House Account — /admin/house-account
     Renderer for the 'houseAccount' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading house accounts...</div>');
        const data = await api('/house-accounts');
        if (!data) { renderApiError(); return; }
        const rows = data.house_accounts || [];

        if (!rows.length) {
          setContent(
            '<div class="card" style="padding:36px;text-align:center">' +
              '<div style="font-size:15px;font-weight:600;margin-bottom:8px">No house accounts</div>' +
              '<div style="color:var(--muted);font-size:13px;max-width:520px;margin:0 auto;line-height:1.6">' +
                'A workspace becomes a house account when <code>is_house_account</code> is set on it. ' +
                'Internal, demo and partner workspaces are billed this way so their credit burn is never ' +
                'counted as customer revenue.' +
              '</div>' +
            '</div>');
          return;
        }

        const sum = (k) => rows.reduce((t, r) => t + (Number(r[k]) || 0), 0);
        const autoOn = rows.filter(r => r.auto_replenish).length;

        const statsHtml = '<div class="stats-grid" style="margin-bottom:20px">' +
            '<div class="stat-card"><div class="stat-value">' + rows.length + '</div><div class="stat-label">House Accounts</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--ac)">' + sum('balance') + '</div><div class="stat-label">Credits Held</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--am)">' + sum('reserved') + '</div><div class="stat-label">Reserved</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--bl)">' + sum('monthly_allowance') + '</div><div class="stat-label">Monthly Allowance</div></div>' +
            '<div class="stat-card"><div class="stat-value">' + autoOn + ' / ' + rows.length + '</div><div class="stat-label">Auto-Replenish On</div></div>' +
          '</div>';

        const esc = (s) => String(s == null ? '' : s).replace(/'/g, "\\'");

        const table = adminTable({
          id: 'house-accounts',
          data: rows,
          columns: [
            {key: 'name', label: 'Workspace', sortable: true, render: function(v, row){
              return '<span style="font-weight:600">' + (v || '—') + '</span>' +
                     '<div style="font-size:11px;color:var(--muted)">id=' + row.id + '</div>';
            }},
            {key: 'plan', label: 'Plan', render: function(v){ return badge(String(v || 'None').toLowerCase()); }},
            {key: 'balance', label: 'Balance', sortable: true, render: function(v){
              return '<span style="font-weight:600;color:' + ((Number(v) || 0) > 0 ? 'var(--ac)' : 'var(--rd)') + '">' + (v || 0) + '</span>';
            }},
            {key: 'reserved', label: 'Reserved', sortable: true, render: function(v){
              return '<span style="color:var(--am)">' + (v || 0) + '</span>';
            }},
            {key: 'monthly_allowance', label: 'Allowance', sortable: true, render: function(v){
              return '<span>' + (v || 0) + '</span><span style="font-size:11px;color:var(--muted)">/mo</span>';
            }},
            {key: 'auto_replenish', label: 'Auto-Replenish', sortable: true, render: function(v){
              return v ? '<span class="badge badge-green">on</span>' : '<span class="badge badge-amber">off</span>';
            }},
            {key: 'last_replenish', label: 'Last Replenish', sortable: true, render: function(v){
              return '<span style="font-size:12px;color:var(--muted)">' + (v ? tsTime(v) : 'never') + '</span>';
            }},
            {key: '_actions', label: 'Actions', render: function(v, row){
              const n = esc(row.name);
              return '<div style="white-space:nowrap">' +
                '<button class="btn btn-ghost btn-sm" onclick="houseTopUp(' + row.id + ',\'' + n + '\')">Top Up</button> ' +
                '<button class="btn btn-ghost btn-sm" onclick="houseAllowance(' + row.id + ',\'' + n + '\',' + (Number(row.monthly_allowance) || 0) + ')">Allowance</button> ' +
                '<button class="btn btn-ghost btn-sm" onclick="houseToggleReplenish(' + row.id + ',\'' + n + '\',' + (row.auto_replenish ? 'true' : 'false') + ')">' +
                  (row.auto_replenish ? 'Disable Auto' : 'Enable Auto') +
                '</button>' +
              '</div>';
            }}
          ],
          searchFields: ['name', 'plan'],
          defaultSort: {key: 'balance', dir: 'desc'}
        });

        setContent(statsHtml + table);
      }).bind(window.pages);
</script>
