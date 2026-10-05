{{-- Affiliates: flags - /admin/partners/flags (RFC-0026 section 10): self-referral, click bursts, disputes. --}}
@include('admin.pages.partners._helpers')
<script>
  pAdmin.fFilter = pAdmin.fFilter || 'open';
  pAdmin.fAct = async function (id, to) {
    var why = await adminPrompt('What you found / what was done:', (to === 'resolved' ? 'Resolve' : 'Dismiss') + ' flag #' + id);
    if (!why) return;
    var x = await pAdmin.req('/flags/' + id, 'POST', { status: to, resolution: why });
    showAdminToast(x.ok ? 'Saved' : pAdmin.err(x), x.ok ? 'success' : 'error'); if (x.ok) window.page();
  };
  window.page = (async function () {
    setContent('<div class="loading">Loading flags…</div>');
    var x = await pAdmin.req('/flags?status=' + pAdmin.fFilter);
    if (!x.ok) { renderApiError(); return; }
    var e = pAdmin.esc, list = x.j.flags || [];
    var words = { self_referral: 'Self-referral', click_burst: 'Click burst', dispute: 'Payment dispute' };
    setContent(
      '<div style="display:flex;gap:6px;margin-bottom:12px">' + [['open', 'Open'], ['resolved', 'Resolved'], ['dismissed', 'Dismissed'], ['all', 'All']].map(function (t) { return '<button class="btn btn-sm' + (pAdmin.fFilter === t[0] ? '' : ' btn-ghost') + '" onclick="pAdmin.fFilter=\'' + t[0] + '\';window.page()">' + t[1] + '</button>'; }).join('') + '</div>' +
      '<div class="card">' + (list.length ? adminTable({ id: 'pflags', data: list, columns: [
        { key: 'created_at', label: 'When', sortable: true, render: function (v) { return tsTime(v); } },
        { key: 'partner', label: 'Affiliate', render: function (v, r) { return '<a href="/admin/partners?id=' + r.affiliate_id + '">' + e(v || ('#' + r.affiliate_id)) + '</a>'; } },
        { key: 'kind', label: 'What', render: function (v) { return '<b>' + e(words[v] || v) + '</b>'; } },
        { key: 'workspace_id', label: 'Business', render: function (v) { return v ? 'ws ' + v : '—'; } },
        { key: 'evidence_json', label: 'Evidence', render: function (v) { return '<code style="font-size:11px">' + e(v) + '</code>'; } },
        { key: 'status', label: 'Status', render: function (v, r) { return pAdmin.st(v) + (r.resolution ? '<div style="font-size:11px;color:var(--muted)">' + e(r.resolution) + '</div>' : ''); } },
        { key: 'id', label: '', render: function (v, r) { return r.status === 'open' ? '<button class="btn btn-sm" onclick="pAdmin.fAct(' + v + ',\'resolved\')">Resolve</button> <button class="btn btn-ghost btn-sm" onclick="pAdmin.fAct(' + v + ',\'dismissed\')">Dismiss</button>' : ''; } }
      ] }) : '<div style="color:var(--muted);padding:12px 0">Nothing here.</div>') + '</div>');
  }).bind(window.pages);
</script>
