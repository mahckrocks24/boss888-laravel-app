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
        '<div style="color:var(--muted);font-size:13px">' + e(a.email) + ' · ' + (d.team.active ? '<span class="badge badge-purple">Team Leader</span> · ' : '') + 'applied ' + ts(a.created_at) + (a.approved_at ? ' · approved ' + ts(a.approved_at) : '') + '</div>' +
        '<div style="font-size:13px;margin-top:6px">' + e(a.channel_url || '') + (a.audience ? '<div style="color:var(--muted)">' + e(a.audience) + '</div>' : '') + '</div>' +
        (a.decision_note ? '<div style="font-size:12px;color:var(--muted);margin-top:4px">Note: ' + e(a.decision_note) + '</div>' : '') +
      '</div><div style="display:flex;gap:8px;flex-wrap:wrap">' + act.map(function (k) { return '<button class="btn btn-sm' + (k[0] === 'approve' || k[0] === 'reinstate' ? '' : ' btn-danger') + '" onclick="pAdmin.decide(' + a.id + ',\'' + k[0] + '\',\'' + e(a.display_name).replace(/'/g, '') + '\')">' + k[1] + '</button>'; }).join('') + '</div></div></div>' +
      '<div class="stats-grid" style="margin:16px 0">' +
        [['Waiting', d.money.pending], ['Ready to pay', d.money.payable], ['Paid', d.money.paid]].map(function (s) { return '<div class="stat-card"><div class="stat-value">' + pAdmin.usd(s[1]) + '</div><div class="stat-label">' + s[0] + '</div></div>'; }).join('') +
        '<div class="stat-card"><div class="stat-value">' + d.codes.filter(function (c) { return c.status === 'active'; }).length + '</div><div class="stat-label">Active codes</div></div>' +
        '<div class="stat-card"><div class="stat-value">' + d.referrals.length + '</div><div class="stat-label">Referrals</div></div></div>' +
      pAdmin.reviewCard(a, d.review) + pAdmin.teamCard(a, d.team) +
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
  // RFC-0028: what Bella found, and the Team Leader side of an affiliate
  pAdmin.reviewCard = function (a, rv) {
    var e = pAdmin.esc;
    if (!rv && a.status !== 'pending') return '';
    return '<div class="card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div class="card-title" style="margin:0">Application review</div>' + (a.status === 'pending' ? '<button class="btn btn-ghost btn-sm" onclick="pAdmin.askBella(' + a.id + ')">Ask Bella</button>' : '') + '</div>' +
      (rv ? '<div style="font-size:13px;margin-top:8px">Bella: <b>' + e({ approve: 'approved', reject: 'rejected', manual: 'left for a person' }[rv.decision] || rv.decision) + '</b>' + (rv.model ? ' <span style="color:var(--muted)">(model ' + e(rv.model.decision) + ', ' + Math.round(rv.model.confidence * 100) + '%)</span>' : '') + ' · channel ' + (rv.channel && rv.channel.opens ? 'opens' : 'did not open') + (rv.channel && rv.channel.title ? ': ' + e(rv.channel.title) : '') +
        '<ul style="margin:6px 0 0;padding-left:18px">' + (rv.reasons || []).map(function (x) { return '<li>' + e(x) + '</li>'; }).join('') + '</ul></div>' : '<div style="color:var(--muted);font-size:13px;margin-top:8px">Not reviewed by Bella yet.</div>') +
      (a.leader_recommendation ? '<div style="font-size:13px;margin-top:8px">Team Leader ' + (a.leader_recommendation === 'recommend' ? 'recommends' : 'advises against') + (a.leader_rec_note ? ': ' + e(a.leader_rec_note) : '') + '</div>' : '') + '</div>';
  };
  pAdmin.teamCard = function (a, t) {
    var e = pAdmin.esc; if (!t) return '';
    var h = '<div class="card"><div class="card-title">Team</div>';
    if (a.tier === 'leader') {
      h += '<div style="font-size:13px">Team Leader since ' + ts(a.leader_since) + ' · subscription ' + e(a.leader_status || '') + (a.leader_cancel_at_end ? ' · <b>ends ' + ts(a.leader_until) + '</b>' : ' · renews ' + ts(a.leader_until)) + (t.in_grace ? ' · <span class="badge badge-amber">payment overdue, team dissolves ' + ts(a.leader_grace_until) + '</span>' : '') + ' · team code <code>' + e(a.team_code || '') + '</code></div>' +
        '<div style="margin-top:10px">' + (t.members.length ? '<table><thead><tr><th>Member</th><th>Status</th><th>Joined</th></tr></thead><tbody>' + t.members.map(function (m) { return '<tr><td><a href="#" onclick="pAdmin.detail(' + m.id + ');return false">' + e(m.display_name) + '</a></td><td>' + pAdmin.st(m.status) + '</td><td>' + ts(m.created_at) + '</td></tr>'; }).join('') + '</tbody></table>' : '<div style="color:var(--muted)">No members yet.</div>') + '</div>' +
        (t.fees.length ? '<div style="margin-top:10px;font-size:13px"><b>Fees</b> ' + t.fees.map(function (f) { return ts(f.created_at) + ': ' + pAdmin.usd(f.from_commission_minor) + ' from commissions' + (f.card_minor ? ' + ' + pAdmin.usd(f.card_minor) + ' card' : '') + ' (' + e(f.status) + ')'; }).join(' · ') + '</div>' : '') +
        '<div style="margin-top:12px"><button class="btn btn-sm btn-danger" onclick="pAdmin.endLeader(' + a.id + ')">End Team Leader and dissolve the team</button></div>';
    } else {
      h += '<div style="font-size:13px">' + (t.leader ? 'On <a href="#" onclick="pAdmin.detail(' + t.leader.id + ');return false">' + e(t.leader.display_name) + '</a>\'s team.' : 'Not on a team.') + '</div>' +
        (t.leaders.length || t.leader ? '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;align-items:center"><span style="font-size:12px;color:var(--muted)">Move to</span>' + t.leaders.filter(function (l) { return !t.leader || l.id !== t.leader.id; }).map(function (l) { return '<button class="btn btn-ghost btn-sm" onclick="pAdmin.moveTeam(' + a.id + ',' + l.id + ',\'' + e(l.display_name).replace(/'/g, '') + '\')">' + e(l.display_name) + '</button>'; }).join('') + (t.leader ? '<button class="btn btn-ghost btn-sm" onclick="pAdmin.moveTeam(' + a.id + ',null,\'no team\')">Off the team</button>' : '') + '</div>' : '');
    }
    return h + '</div>';
  };
  pAdmin.askBella = async function (id) { var x = await pAdmin.req('/affiliates/' + id + '/review', 'POST', {}); showAdminToast(x.ok ? 'Bella: ' + ((x.j.review || {}).decision || 'done') : pAdmin.err(x), x.ok ? 'success' : 'error'); pAdmin.detail(id); };
  pAdmin.moveTeam = async function (id, to, name) {
    if (!(await adminConfirm('Move this affiliate to ' + name + '?\n\nFrom now on that leader gets 5% of their customers\' payments (none for no team).', 'Move'))) return;
    var x = await pAdmin.req('/affiliates/' + id + '/team', 'POST', { leader_id: to }); showAdminToast(x.ok ? 'Moved' : pAdmin.err(x), x.ok ? 'success' : 'error'); pAdmin.detail(id);
  };
  pAdmin.endLeader = async function (id) {
    var note = await adminPrompt('Why (audited; the leader is emailed):', 'End Team Leader'); if (!note) return;
    if (!(await adminConfirm('End Team Leader now?\n\nThe subscription is cancelled, they become a regular affiliate and their team dissolves. Money already earned stays.', 'End Team Leader'))) return;
    var x = await pAdmin.req('/affiliates/' + id + '/end-leader', 'POST', { note: note }); showAdminToast(x.ok ? 'Team Leader ended' : pAdmin.err(x), x.ok ? 'success' : 'error'); pAdmin.detail(id);
  };
  pAdmin.setMode = async function (mode) {
    if (!(await adminConfirm(mode === 'bella' ? 'Let Bella decide applications?\n\nShe approves clear good ones, rejects clear spam, and leaves anything unsure to you with her reasons. You can reverse any decision.' : 'Decide every application by hand?', 'Confirm'))) return;
    var x = await pAdmin.req('/approvals', 'POST', { mode: mode }); showAdminToast(x.ok ? (mode === 'bella' ? 'Bella decides applications' : 'Applications are decided by hand') : pAdmin.err(x), x.ok ? 'success' : 'error'); window.page();
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
      ['Referred revenue', pAdmin.usd(S.referred_revenue_minor)], ['Commission earned', pAdmin.usd(S.commission_minor)], ['Discounts given', pAdmin.usd(S.discounts_minor)], ['Program cost, share of referred revenue', S.program_cost_pct + '%'], ['Open flags', S.open_flags],
      ['Team Leaders', S.leaders + (S.leaders_in_grace ? ' (' + S.leaders_in_grace + ' overdue)' : '')], ['Team Leader 5% earned', pAdmin.usd(S.team_share_minor)], ['Fees paid from commissions', pAdmin.usd(S.leader_fees_from_commissions_minor)]];
    var html = (S.enabled ? '' : '<div class="card" style="border-color:var(--am)"><b>The Affiliate Program is switched off</b> (storage/app/aff1.on is missing). Links, codes and commissions are inactive.</div>') +
      '<div class="stats-grid" style="margin-bottom:18px">' + stats.map(function (x) { return '<div class="stat-card"><div class="stat-value" style="font-size:20px">' + e(x[1]) + '</div><div class="stat-label">' + x[0] + '</div></div>'; }).join('') + '</div>' +
      '<div class="card" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center"><div><div class="card-title" style="margin:0">Who decides applications</div><div style="font-size:13px;color:var(--muted)">' + (S.approval_mode === 'bella' ? 'Bella approves clear good applications and rejects clear spam; anything unsure waits here with her reasons.' : 'Every application waits for a person.') + '</div></div>' +
        '<div style="display:flex;gap:6px"><button class="btn btn-sm' + (S.approval_mode === 'bella' ? '' : ' btn-ghost') + '" onclick="pAdmin.setMode(\'bella\')">Bella</button><button class="btn btn-sm' + (S.approval_mode === 'manual' ? '' : ' btn-ghost') + '" onclick="pAdmin.setMode(\'manual\')">Manual</button></div></div>' +
      '<div class="card"><div class="card-title">Applications waiting (' + pend.length + ')</div>' + (pend.length ? pend.map(function (a) {
        return '<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 0;border-top:1px solid var(--border)"><div><b>' + e(a.name) + '</b> <span style="color:var(--muted);font-size:12px">' + e(a.email) + ' · applied ' + ts(a.applied) + '</span><div style="font-size:13px">' + e(a.channel_url || '') + '</div>' + (a.audience ? '<div style="font-size:12px;color:var(--muted)">' + e(a.audience) + '</div>' : '') +
          (a.leader ? '<div style="font-size:12px">Recruit of <b>' + e(a.leader) + '</b>' + (a.recommendation ? ' · leader ' + (a.recommendation === 'recommend' ? 'recommends' : 'advises against') + (a.rec_note ? ': ' + e(a.rec_note) : '') : '') + '</div>' : '') +
          (a.review ? '<div style="font-size:12px;color:var(--muted)">Bella: ' + e(({ manual: 'left for you', approve: 'approved', reject: 'rejected' })[a.review.decision] || a.review.decision) + ' · ' + e((a.review.reasons || []).slice(0, 2).join(' ')) + '</div>' : '') + '</div>' +
          '<div style="display:flex;gap:8px;align-items:center"><button class="btn btn-sm" onclick="pAdmin.decide(' + a.id + ',\'approve\',\'' + e(a.name).replace(/'/g, '') + '\')">Approve</button><button class="btn btn-sm btn-danger" onclick="pAdmin.decide(' + a.id + ',\'reject\',\'' + e(a.name).replace(/'/g, '') + '\')">Reject</button><button class="btn btn-ghost btn-sm" onclick="pAdmin.detail(' + a.id + ')">Open</button></div></div>';
      }).join('') : '<div style="color:var(--muted)">No applications waiting.</div>') + '</div>' +
      '<div class="card"><div class="card-title">All affiliates</div>' + adminTable({ id: 'partners', data: list, searchable: true, columns: [
        { key: 'name', label: 'Affiliate', sortable: true, render: function (v, r) { return '<a href="#" onclick="pAdmin.detail(' + r.id + ');return false" style="font-weight:600;color:var(--text);text-decoration:underline;text-underline-offset:3px">' + e(v) + '</a><div style="font-size:11px;color:var(--muted)">' + e(r.email) + (r.leader ? ' · team of ' + e(r.leader) : '') + '</div>'; } },
        { key: 'tier', label: 'Tier', sortable: true, render: function (v, r) { return v === 'leader' ? '<span class="badge badge-purple">Team Leader</span><div style="font-size:11px;color:var(--muted)">' + r.team + ' member' + (r.team === 1 ? '' : 's') + (r.grace_until ? ' · overdue' : '') + '</div>' : 'Affiliate'; } },
        { key: 'status', label: 'Status', sortable: true, render: function (v) { return pAdmin.st(v); } },
        { key: 'signups', label: 'Sign-ups', sortable: true }, { key: 'paying', label: 'Paying', sortable: true },
        { key: 'pending', label: 'Waiting', sortable: true, render: function (v) { return pAdmin.usd(v); } }, { key: 'payable', label: 'Ready', sortable: true, render: function (v) { return pAdmin.usd(v); } }, { key: 'paid', label: 'Paid', sortable: true, render: function (v) { return pAdmin.usd(v); } },
        { key: 'budgets', label: 'Budgets', render: function (v) { return pAdmin.pct(v.monthly) + ' / ' + pAdmin.pct(v.yearly) + ' / ' + pAdmin.pct(v.domain); } },
        { key: 'flags', label: 'Flags', sortable: true, render: function (v) { return v ? '<span class="badge badge-amber">' + v + '</span>' : '—'; } }
      ] }) + '</div>';
    setContent(html);
  }).bind(window.pages);
</script>
