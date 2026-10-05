{{-- Affiliates - /admin/partners (RFC-0026 section 9): applications, every affiliate, one affiliate in full.
     Data from /api/admin/partners/*. Changes are audited server-side (affiliates.*). --}}
@include('admin.pages.partners._helpers')
<script>

  pAdmin.decide = async function (id, action, name) {
    var note = null;
    if (action === 'reject' || action === 'suspend' || action === 'close') {
      note = await adminPrompt('Reason (the affiliate sees this for a rejection):', ({ reject: 'Reject ', suspend: 'Suspend ', close: 'Close ' })[action] + name);
      if (note === null) return;
    } else if (!(await adminConfirm(({ approve: 'Approve ', reinstate: 'Reinstate ' })[action] + name + '?\n\nTheir link and codes start working at once.', 'Confirm'))) return;
    var x = await pAdmin.req('/affiliates/' + id + '/decision', 'POST', { action: action, note: note });
    showAdminToast(x.ok ? 'Saved: ' + x.j.status : pAdmin.err(x), x.ok ? 'success' : 'error');
    if (x.ok) (pAdmin.detailId ? pAdmin.detail(pAdmin.detailId) : window.page());
  };

  pAdmin.detail = async function (id) {
    pAdmin.detailId = id;
    setContent('<div class="loading">Loading affiliate…</div>');
    var x = await pAdmin.req('/affiliates/' + id);
    if (!x.ok) { setContent('<div class="card">' + pAdmin.esc(pAdmin.err(x)) + '</div>'); return; }
    var d = x.j, a = d.affiliate, b = d.budgets, e = pAdmin.esc;
    var act = { pending: [['approve', 'Approve'], ['reject', 'Reject']], approved: [['suspend', 'Suspend'], ['close', 'Close']], suspended: [['reinstate', 'Reinstate'], ['close', 'Close']], rejected: [['approve', 'Approve']], closed: [] }[a.status] || [];
    var rows = function (list, cols) { return list.length ? '<table><thead><tr>' + cols.map(function (c) { return '<th>' + c[0] + '</th>'; }).join('') + '</tr></thead><tbody>' + list.map(function (r) { return '<tr>' + cols.map(function (c) { return '<td>' + c[1](r) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table>' : '<div style="color:var(--muted);padding:12px 0">None yet.</div>'; };
    setContent(
      '<div style="margin-bottom:14px"><button class="btn btn-ghost btn-sm" onclick="pAdmin.detailId=null;window.page()">&larr; All affiliates</button></div>' +
      '<div class="card"><div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start"><div>' +
        '<div class="card-title" style="margin-bottom:4px">' + e(a.display_name) + ' ' + pAdmin.st(a.status) + '</div>' +
        '<div style="color:var(--muted);font-size:13px">' + e(a.email) + ' · <code>levelupgrowth.io/r/' + e(a.handle) + '</code> · applied ' + ts(a.created_at) + (a.approved_at ? ' · approved ' + ts(a.approved_at) : '') + '</div>' +
        '<div style="font-size:13px;margin-top:6px">' + e(a.channel_url || '') + (a.audience ? '<div style="color:var(--muted)">' + e(a.audience) + '</div>' : '') + '</div>' +
        (a.decision_note ? '<div style="font-size:12px;color:var(--muted);margin-top:4px">Note: ' + e(a.decision_note) + '</div>' : '') +
      '</div><div style="display:flex;gap:8px;flex-wrap:wrap">' + act.map(function (k) { return '<button class="btn btn-sm' + (k[0] === 'approve' || k[0] === 'reinstate' ? '' : ' btn-danger') + '" onclick="pAdmin.decide(' + a.id + ',\'' + k[0] + '\',\'' + e(a.display_name).replace(/'/g, '') + '\')">' + k[1] + '</button>'; }).join('') + '</div></div></div>' +
      '<div class="stats-grid" style="margin:16px 0">' +
        [['Waiting', d.money.pending], ['Ready to pay', d.money.payable], ['Paid', d.money.paid]].map(function (s) { return '<div class="stat-card"><div class="stat-value">' + pAdmin.usd(s[1]) + '</div><div class="stat-label">' + s[0] + '</div></div>'; }).join('') +
        '<div class="stat-card"><div class="stat-value">' + d.codes.filter(function (c) { return c.status === 'active'; }).length + '</div><div class="stat-label">Active codes</div></div>' +
        '<div class="stat-card"><div class="stat-value">' + d.referrals.length + '</div><div class="stat-label">Referrals</div></div></div>' +
      '<div class="card"><div class="card-title">Budgets</div><div style="color:var(--muted);font-size:13px;margin-bottom:10px">What this affiliate splits between discount and commission. Raising one applies to new customers only.</div>' +
        '<div class="form-row" style="grid-template-columns:repeat(3,1fr)">' + [['monthly', 'Monthly plans (6 payments)'], ['yearly', 'Yearly plans (once)'], ['domain', 'Domains']].map(function (k) { return '<div class="form-group"><label>' + k[1] + ' %</label><input id="pb-' + k[0] + '" inputmode="numeric" value="' + (b[k[0]] / 100) + '"></div>'; }).join('') + '</div>' +
        '<button class="btn btn-sm" onclick="pAdmin.saveBudgets(' + a.id + ')">Save budgets</button></div>' +
      '<div class="card"><div class="card-title">Codes</div>' + rows(d.codes, [['Code', function (v) { return '<code>' + e(v.code) + '</code>'; }], ['Off monthly', function (v) { return pAdmin.pct(v.discount_monthly_bps); }], ['Off yearly', function (v) { return pAdmin.pct(v.discount_yearly_bps); }], ['Off domains', function (v) { return pAdmin.pct(v.discount_domain_bps); }], ['Used', function (v) { return v.redemptions + (v.max_redemptions ? ' / ' + v.max_redemptions : ''); }], ['Status', function (v) { return pAdmin.st(v.status); }]]) + '</div>' +
      '<div class="card"><div class="card-title">Referrals</div>' + rows(d.referrals, [['Business', function (r) { return e(r.workspace_name || ('ws ' + r.workspace_id)) + '<div style="font-size:11px;color:var(--muted)">' + e(r.email || '') + ' · ws ' + r.workspace_id + '</div>'; }], ['Via', function (r) { return e(r.source === 'voucher' ? 'code ' + (r.code || '') : 'link') + (r.sub_id ? ' · ' + e(r.sub_id) : ''); }], ['Terms', function (r) { return pAdmin.pct(r.discount_monthly_bps) + ' off / ' + pAdmin.pct(r.commission_monthly_bps) + ' to affiliate'; }], ['Payments', function (r) { return r.cycles_paid + ' of ' + r.months; }], ['Status', function (r) { return pAdmin.st(r.status) + (r.void_reason ? '<div style="font-size:11px;color:var(--muted)">' + e(r.void_reason) + '</div>' : ''); }], ['Earned', function (r) { return pAdmin.usd(r.earned_minor); }]]) + '</div>' +
      '<div class="card"><div class="card-title">Commissions</div>' + rows(d.commissions, [['Date', function (c) { return ts(c.created_at); }], ['For', function (c) { return e(c.source_type + ' ' + c.kind) + (c.note ? '<div style="font-size:11px;color:var(--muted)">' + e(c.note) + '</div>' : ''); }], ['Base', function (c) { return c.base_minor ? pAdmin.usd(c.base_minor) : '—'; }], ['Rate', function (c) { return c.rate_bps ? pAdmin.pct(c.rate_bps) : '—'; }], ['Amount', function (c) { return '<b style="color:' + (c.amount_minor < 0 ? 'var(--rd)' : 'inherit') + '">' + pAdmin.usd(c.amount_minor) + '</b>'; }], ['Status', function (c) { return pAdmin.st(c.status) + (c.status === 'pending' ? '<div style="font-size:11px;color:var(--muted)">until ' + ts(c.payable_at) + '</div>' : ''); }]]) +
        '<div style="margin-top:10px"><button class="btn btn-ghost btn-sm" onclick="pAdmin.adjust(' + a.id + ')">Add an adjustment</button></div></div>' +
      '<div class="card"><div class="card-title">Flags</div>' + rows(d.flags, [['When', function (f) { return tsTime(f.created_at); }], ['Kind', function (f) { return e(f.kind); }], ['Evidence', function (f) { return '<code style="font-size:11px">' + e(f.evidence_json) + '</code>'; }], ['Status', function (f) { return pAdmin.st(f.status) + (f.resolution ? '<div style="font-size:11px;color:var(--muted)">' + e(f.resolution) + '</div>' : ''); }]]) + '</div>' +
      '<div class="card"><div class="card-title">Internal notes</div><div class="form-group"><textarea id="p-notes" rows="4">' + e(a.notes || '') + '</textarea></div><button class="btn btn-sm" onclick="pAdmin.saveNotes(' + a.id + ')">Save notes</button></div>');
  };
  pAdmin.saveBudgets = async function (id) {
    var v = {}; ['monthly', 'yearly', 'domain'].forEach(function (k) { v[k] = Math.round(parseFloat(document.getElementById('pb-' + k).value || '0') * 100); });
    var x = await pAdmin.req('/affiliates/' + id + '/budgets', 'POST', v);
    showAdminToast(x.ok ? (x.j.note || 'Saved') : pAdmin.err(x), x.ok ? 'success' : 'error'); if (x.ok) pAdmin.detail(id);
  };
  pAdmin.saveNotes = async function (id) { var x = await pAdmin.req('/affiliates/' + id + '/notes', 'POST', { notes: document.getElementById('p-notes').value }); showAdminToast(x.ok ? 'Notes saved' : pAdmin.err(x), x.ok ? 'success' : 'error'); };
  pAdmin.adjust = async function (id) {
    var raw = await adminPrompt('Amount in dollars (negative to take back), e.g. 25 or -9.95:', 'Adjustment'); if (raw === null || raw === '') return;
    var minor = Math.round(parseFloat(raw) * 100); if (!minor) { showAdminToast('Enter an amount', 'error'); return; }
    var note = await adminPrompt('Why (kept on the ledger and in the audit log):', 'Reason'); if (!note) return;
    if (!(await adminConfirm('Add ' + pAdmin.usd(minor) + ' to this affiliate, ready to pay?\n\n' + note, 'Confirm adjustment'))) return;
    var x = await pAdmin.req('/affiliates/' + id + '/adjust', 'POST', { amount_minor: minor, note: note });
    showAdminToast(x.ok ? 'Adjustment added' : pAdmin.err(x), x.ok ? 'success' : 'error'); if (x.ok) pAdmin.detail(id);
  };

  window.page = (async function () {
    try { var qid = +new URLSearchParams(location.search).get('id'); if (qid && pAdmin.detailId === undefined) pAdmin.detailId = qid; } catch (e) {}
    if (pAdmin.detailId) return pAdmin.detail(pAdmin.detailId);
    setContent('<div class="loading">Loading affiliates…</div>');
    var s = await pAdmin.req('/summary'), l = await pAdmin.req('/affiliates');
    if (!s.ok || !l.ok) { renderApiError(); return; }
    var S = s.j, list = l.j.affiliates || [], pend = list.filter(function (a) { return a.status === 'pending'; }), e = pAdmin.esc;
    var stats = [['Affiliates', (S.affiliates.approved || 0)], ['Applications waiting', S.pending_applications], ['Referred businesses', S.referrals], ['Paying now', S.paying],
      ['Referred revenue', pAdmin.usd(S.referred_revenue_minor)], ['Commission earned', pAdmin.usd(S.commission_minor)], ['Discounts given', pAdmin.usd(S.discounts_minor)], ['Program cost, share of referred revenue', S.program_cost_pct + '%'], ['Open flags', S.open_flags]];
    var html = (S.enabled ? '' : '<div class="card" style="border-color:var(--am)"><b>The Affiliate Program is switched off</b> (storage/app/aff1.on is missing). Links, codes and commissions are inactive.</div>') +
      '<div class="stats-grid" style="margin-bottom:18px">' + stats.map(function (x) { return '<div class="stat-card"><div class="stat-value" style="font-size:20px">' + e(x[1]) + '</div><div class="stat-label">' + x[0] + '</div></div>'; }).join('') + '</div>' +
      '<div class="card"><div class="card-title">Applications waiting (' + pend.length + ')</div>' + (pend.length ? pend.map(function (a) {
        return '<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 0;border-top:1px solid var(--border)"><div><b>' + e(a.name) + '</b> <span style="color:var(--muted);font-size:12px">' + e(a.email) + ' · applied ' + ts(a.applied) + '</span><div style="font-size:13px">' + e(a.channel_url || '') + '</div>' + (a.audience ? '<div style="font-size:12px;color:var(--muted)">' + e(a.audience) + '</div>' : '') + '</div>' +
          '<div style="display:flex;gap:8px;align-items:center"><button class="btn btn-sm" onclick="pAdmin.decide(' + a.id + ',\'approve\',\'' + e(a.name).replace(/'/g, '') + '\')">Approve</button><button class="btn btn-sm btn-danger" onclick="pAdmin.decide(' + a.id + ',\'reject\',\'' + e(a.name).replace(/'/g, '') + '\')">Reject</button><button class="btn btn-ghost btn-sm" onclick="pAdmin.detail(' + a.id + ')">Open</button></div></div>';
      }).join('') : '<div style="color:var(--muted)">No applications waiting.</div>') + '</div>' +
      '<div class="card"><div class="card-title">All affiliates</div>' + adminTable({ id: 'partners', data: list, searchable: true, columns: [
        { key: 'name', label: 'Affiliate', sortable: true, render: function (v, r) { return '<a href="#" onclick="pAdmin.detail(' + r.id + ');return false" style="font-weight:600;color:var(--text);text-decoration:underline;text-underline-offset:3px">' + e(v) + '</a><div style="font-size:11px;color:var(--muted)">' + e(r.email) + ' · /r/' + e(r.handle) + '</div>'; } },
        { key: 'status', label: 'Status', sortable: true, render: function (v) { return pAdmin.st(v); } },
        { key: 'signups', label: 'Sign-ups', sortable: true }, { key: 'paying', label: 'Paying', sortable: true },
        { key: 'pending', label: 'Waiting', sortable: true, render: function (v) { return pAdmin.usd(v); } }, { key: 'payable', label: 'Ready', sortable: true, render: function (v) { return pAdmin.usd(v); } }, { key: 'paid', label: 'Paid', sortable: true, render: function (v) { return pAdmin.usd(v); } },
        { key: 'budgets', label: 'Budgets', render: function (v) { return pAdmin.pct(v.monthly) + ' / ' + pAdmin.pct(v.yearly) + ' / ' + pAdmin.pct(v.domain); } },
        { key: 'flags', label: 'Flags', sortable: true, render: function (v) { return v ? '<span class="badge badge-amber">' + v + '</span>' : '—'; } }
      ] }) + '</div>';
    setContent(html);
  }).bind(window.pages);
</script>
