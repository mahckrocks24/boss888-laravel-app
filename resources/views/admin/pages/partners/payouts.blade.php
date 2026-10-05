{{-- Partners: payouts - /admin/partners/payouts (RFC-0026 section 7). The monthly run: preview, create the payouts,
     send PayPal / Wise payments by hand and record their reference; bank payouts go by Stripe once Connect is enabled. --}}
@include('admin.pages.partners._helpers')
<script>
  pAdmin.payRun = async function (period, n, total) {
    if (!(await adminConfirm('Create ' + period + ' payouts for ' + n + ' partners, ' + total + '?\n\nBank partners are paid at once. PayPal and Wise payouts wait here for you to send and record.', 'Run payouts'))) return;
    var x = await pAdmin.req('/payouts/run', 'POST', { period: period });
    showAdminToast(x.ok ? ('Sent ' + x.j.sent + ', waiting ' + x.j.waiting + ', failed ' + x.j.failed) : pAdmin.err(x), x.ok ? 'success' : 'error'); window.page();
  };
  pAdmin.payRecord = async function (id, who, amount) {
    var ref = await adminPrompt('Send ' + amount + ' to ' + who + ', then paste the PayPal or Wise reference here:', 'Record payment'); if (!ref) return;
    var x = await pAdmin.req('/payouts/' + id + '/record', 'POST', { reference: ref });
    showAdminToast(x.ok ? 'Recorded. The partner has been told.' : pAdmin.err(x), x.ok ? 'success' : 'error'); window.page();
  };
  pAdmin.payCancel = async function (id) {
    if (!(await adminConfirm('Cancel this payout? Its commissions go back to ready and are included in the next run.', 'Cancel payout'))) return;
    var x = await pAdmin.req('/payouts/' + id + '/cancel', 'POST', {}); showAdminToast(x.ok ? 'Cancelled' : pAdmin.err(x), x.ok ? 'success' : 'error'); window.page();
  };
  window.page = (async function () {
    setContent('<div class="loading">Loading payouts…</div>');
    var x = await pAdmin.req('/payouts');
    if (!x.ok) { renderApiError(); return; }
    var d = x.j, e = pAdmin.esc, pv = d.preview || [], ok = pv.filter(function (p) { return p.eligible; }), tot = ok.reduce(function (t, p) { return t + p.amount_minor; }, 0);
    var waiting = (d.history || []).filter(function (p) { return p.status === 'draft'; });
    var methodWord = { stripe: 'Bank (Stripe)', paypal: 'PayPal', wise: 'Wise' };
    setContent(
      (d.bank_available ? '' : '<div class="card" style="border-color:var(--am)"><b>Bank payouts are off.</b> Stripe Connect is not enabled on the platform account yet, so partners are paid by PayPal or Wise, sent by hand and recorded here.</div>') +
      '<div class="stats-grid" style="margin-bottom:16px">' +
        '<div class="stat-card"><div class="stat-value">' + ok.length + '</div><div class="stat-label">Partners to pay</div></div>' +
        '<div class="stat-card"><div class="stat-value">' + pAdmin.usd(tot) + '</div><div class="stat-label">Ready to pay</div></div>' +
        '<div class="stat-card"><div class="stat-value">' + waiting.length + '</div><div class="stat-label">Waiting to be sent</div></div>' +
        '<div class="stat-card"><div class="stat-value">' + pAdmin.usd(d.min_minor) + '</div><div class="stat-label">Minimum payout</div></div></div>' +
      '<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><div class="card-title" style="margin:0">Ready balances</div>' +
        (ok.length ? '<button class="btn" onclick="pAdmin.payRun(\'' + d.period + '\',' + ok.length + ',\'' + pAdmin.usd(tot) + '\')">Create ' + e(d.period) + ' payouts</button>' : '') + '</div>' +
        (pv.length ? '<table style="margin-top:12px"><thead><tr><th>Partner</th><th>Method</th><th>Pays to</th><th>Commissions</th><th>Amount</th><th>Status</th></tr></thead><tbody>' + pv.map(function (p) {
          return '<tr><td><a href="/admin/partners?id=' + p.affiliate_id + '" style="color:var(--text)">' + e(p.name) + '</a><div style="font-size:11px;color:var(--muted)">' + e(p.email) + '</div></td><td>' + e(methodWord[p.method] || '—') + '</td><td style="font-size:12px">' + e(p.to || '—') + '</td><td>' + p.commissions + '</td><td><b>' + pAdmin.usd(p.amount_minor) + '</b></td><td>' + (p.eligible ? '<span class="badge badge-green">will be paid</span>' : '<span class="badge badge-amber">' + e(p.reason) + '</span>') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div style="color:var(--muted);padding:12px 0">Nothing ready to pay.</div>') + '</div>' +
      '<div class="card"><div class="card-title">Payouts</div>' + ((d.history || []).length ? '<table><thead><tr><th>Period</th><th>Partner</th><th>Method</th><th>Amount</th><th>Reference</th><th>Status</th><th></th></tr></thead><tbody>' + d.history.map(function (p) {
          var act = p.status === 'draft' ? '<button class="btn btn-sm" onclick="pAdmin.payRecord(' + p.id + ',\'' + e((p.payout_email || p.partner || '')).replace(/'/g, '') + '\',\'' + pAdmin.usd(p.amount_minor) + '\')">Record payment</button> <button class="btn btn-ghost btn-sm" onclick="pAdmin.payCancel(' + p.id + ')">Cancel</button>' : '';
          return '<tr><td>' + e(p.period) + '</td><td>' + e(p.partner || ('#' + p.affiliate_id)) + '</td><td>' + e(methodWord[p.method] || p.method) + (p.payout_email && p.method !== 'stripe' ? '<div style="font-size:11px;color:var(--muted)">' + e(p.payout_email) + '</div>' : '') + '</td><td><b>' + pAdmin.usd(p.amount_minor) + '</b></td><td style="font-size:12px">' + e(p.reference || p.stripe_transfer_id || '—') + (p.note ? '<div style="font-size:11px;color:var(--rd)">' + e(p.note) + '</div>' : '') + '</td><td>' + pAdmin.st(p.status === 'draft' ? 'pending' : p.status) + '</td><td>' + act + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div style="color:var(--muted);padding:12px 0">No payouts yet.</div>') + '</div>');
  }).bind(window.pages);
</script>
