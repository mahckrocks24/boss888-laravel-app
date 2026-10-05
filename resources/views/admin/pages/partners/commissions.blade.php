{{-- Affiliates: commissions - /admin/partners/commissions (RFC-0026 sections 6 and 9). The whole ledger: hold, release or
     void with a reason (audited). Paid rows are never edited: an adjustment on the affiliate nets them. --}}
@include('admin.pages.partners._helpers')
<script>
  pAdmin.cFilter = pAdmin.cFilter || '';
  pAdmin.cAct = async function (id, action) {
    var note = await adminPrompt('Why (kept on the row and in the audit log):', ({ hold: 'Hold', release: 'Release now', void: 'Void' })[action] + ' commission #' + id);
    if (!note) return;
    var x = await pAdmin.req('/commissions/' + id, 'POST', { action: action, note: note });
    showAdminToast(x.ok ? 'Saved' : pAdmin.err(x), x.ok ? 'success' : 'error'); if (x.ok) window.page();
  };
  window.page = (async function () {
    setContent('<div class="loading">Loading commissions…</div>');
    var x = await pAdmin.req('/commissions' + (pAdmin.cFilter ? '?status=' + pAdmin.cFilter : ''));
    if (!x.ok) { renderApiError(); return; }
    var e = pAdmin.esc, list = x.j.commissions || [];
    var tot = function (s) { return list.filter(function (c) { return !s || c.status === s; }).reduce(function (t, c) { return t + (+c.amount_minor || 0); }, 0); };
    var tabs = [['', 'All'], ['pending', 'Waiting'], ['payable', 'Ready to pay'], ['paid', 'Paid'], ['void', 'Void']];
    setContent(
      '<div class="stats-grid" style="margin-bottom:16px">' + [['Waiting', 'pending'], ['Ready to pay', 'payable'], ['Paid', 'paid']].map(function (s) { return '<div class="stat-card"><div class="stat-value">' + pAdmin.usd(tot(s[1])) + '</div><div class="stat-label">' + s[0] + (pAdmin.cFilter && pAdmin.cFilter !== s[1] ? ' (filtered out)' : '') + '</div></div>'; }).join('') + '</div>' +
      '<div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap">' + tabs.map(function (t) { return '<button class="btn btn-sm' + (pAdmin.cFilter === t[0] ? '' : ' btn-ghost') + '" onclick="pAdmin.cFilter=\'' + t[0] + '\';window.page()">' + t[1] + '</button>'; }).join('') + '</div>' +
      '<div class="card">' + adminTable({ id: 'pcomm', data: list, searchable: true, columns: [
        { key: 'created_at', label: 'Date', sortable: true, render: function (v) { return ts(v); } },
        { key: 'partner', label: 'Affiliate', sortable: true, render: function (v, r) { return '<a href="/admin/partners?id=' + r.affiliate_id + '">' + e(v || ('#' + r.affiliate_id)) + '</a>'; } },
        { key: 'workspace_name', label: 'Business', render: function (v, r) { return e(v || (r.workspace_id ? 'ws ' + r.workspace_id : '—')); } },
        { key: 'kind', label: 'For', render: function (v, r) { return e(r.source_type + ' · ' + v) + (r.note ? '<div style="font-size:11px;color:var(--muted)">' + e(r.note) + '</div>' : ''); } },
        { key: 'base_minor', label: 'Base', sortable: true, render: function (v) { return +v ? pAdmin.usd(v) : '—'; } },
        { key: 'rate_bps', label: 'Rate', render: function (v) { return +v ? pAdmin.pct(v) : '—'; } },
        { key: 'amount_minor', label: 'Amount', sortable: true, render: function (v) { return '<b style="color:' + (v < 0 ? 'var(--rd)' : 'inherit') + '">' + pAdmin.usd(v) + '</b>'; } },
        { key: 'status', label: 'Status', sortable: true, render: function (v, r) { return pAdmin.st(v) + (v === 'pending' ? '<div style="font-size:11px;color:var(--muted)">until ' + ts(r.payable_at) + '</div>' : ''); } },
        { key: 'id', label: '', render: function (v, r) { if (r.status === 'paid' || r.status === 'void') return ''; var b = []; if (r.status === 'pending') b.push(['release', 'Release']); if (r.status === 'payable') b.push(['hold', 'Hold']); b.push(['void', 'Void']);
          return b.map(function (k) { return '<button class="btn btn-ghost btn-sm" onclick="pAdmin.cAct(' + v + ',\'' + k[0] + '\')">' + k[1] + '</button>'; }).join(' '); } }
      ] }) + '</div>');
  }).bind(window.pages);
</script>
