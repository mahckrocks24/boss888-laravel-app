{{-- Partners: codes and promotions - /admin/partners/codes (RFC-0026 section 9). Every partner code, and house
     promotions (discount only, no partner) created here. --}}
@include('admin.pages.partners._helpers')
<script>
  pAdmin.newPromo = async function () {
    var g = function (id) { return document.getElementById(id).value.trim(); };
    var pc = function (id) { return Math.round(parseFloat(g(id) || '0') * 100); };
    var body = { code: g('hp-code'), label: g('hp-label') || null, monthly: pc('hp-m'), yearly: pc('hp-y'), domain: pc('hp-d'), months: parseInt(g('hp-months') || '6', 10),
      max: g('hp-max') ? parseInt(g('hp-max'), 10) : null, starts_at: g('hp-start') || null, ends_at: g('hp-end') || null, new_customers_only: document.getElementById('hp-new').value === '1' };
    if (!(await adminConfirm('Create house promotion ' + body.code.toUpperCase() + '?\n\n' + (body.monthly / 100) + '% off the first ' + body.months + ' monthly payments, ' + (body.yearly / 100) + '% off yearly, ' + (body.domain / 100) + '% off new domains.', 'Create promotion'))) return;
    var x = await pAdmin.req('/codes', 'POST', body);
    showAdminToast(x.ok ? 'Created ' + x.j.code : pAdmin.err(x), x.ok ? 'success' : 'error'); if (x.ok) window.page();
  };
  pAdmin.hpNew = function (v) { document.getElementById('hp-new').value = String(v); document.querySelectorAll('[data-hpn]').forEach(function (b) { b.classList.toggle('btn-ghost', b.getAttribute('data-hpn') !== String(v)); }); };
  pAdmin.codeStatus = async function (id, to, code) {
    if (!(await adminConfirm((to === 'active' ? 'Resume ' : to === 'paused' ? 'Pause ' : 'Archive ') + code + '?', 'Confirm'))) return;
    var x = await pAdmin.req('/codes/' + id, 'PUT', { status: to });
    showAdminToast(x.ok ? 'Saved' : pAdmin.err(x), x.ok ? 'success' : 'error'); if (x.ok) window.page();
  };
  window.page = (async function () {
    setContent('<div class="loading">Loading codes…</div>');
    var x = await pAdmin.req('/codes');
    if (!x.ok) { renderApiError(); return; }
    var e = pAdmin.esc, list = x.j.codes || [];
    var f = function (id, label, ph, extra) { return '<div class="form-group"><label for="' + id + '">' + label + '</label><input id="' + id + '" placeholder="' + (ph || '') + '" ' + (extra || '') + '></div>'; };
    setContent(
      '<div class="card"><div class="card-title">New house promotion</div><div style="color:var(--muted);font-size:13px;margin-bottom:12px">A code from LevelUpGrowth itself, with no partner: a discount only. Domains are capped at 10% (their margin is about 23%).</div>' +
        '<div class="form-row" style="grid-template-columns:repeat(4,1fr)">' + f('hp-code', 'Code', 'LAUNCH5', 'maxlength="20" style="text-transform:uppercase"') + f('hp-label', 'Label', 'Launch week') + f('hp-months', 'Monthly payments discounted', '6', 'inputmode="numeric" value="6"') +
          '<div class="form-group"><label>Who can use it</label><input type="hidden" id="hp-new" value="1"><div style="display:flex;gap:6px"><button type="button" class="btn btn-sm" data-hpn="1" onclick="pAdmin.hpNew(1)">New customers</button><button type="button" class="btn btn-sm btn-ghost" data-hpn="0" onclick="pAdmin.hpNew(0)">Anyone</button></div></div></div>' +
        '<div class="form-row" style="grid-template-columns:repeat(4,1fr)">' + f('hp-m', '% off monthly plans', '5', 'inputmode="decimal"') + f('hp-y', '% off yearly plans', '0', 'inputmode="decimal"') + f('hp-d', '% off new domains', '0', 'inputmode="decimal"') + f('hp-max', 'Use limit', 'No limit', 'inputmode="numeric"') + '</div>' +
        '<div class="form-row" style="grid-template-columns:repeat(4,1fr)">' + f('hp-start', 'Starts', 'YYYY-MM-DD') + f('hp-end', 'Ends', 'YYYY-MM-DD') + '</div>' +
        '<button class="btn" onclick="pAdmin.newPromo()">Create promotion</button></div>' +
      '<div class="card"><div class="card-title">Every code (' + list.length + ')</div>' + adminTable({ id: 'pcodes', data: list, searchable: true, columns: [
        { key: 'code', label: 'Code', sortable: true, render: function (v, r) { return '<code style="font-weight:700">' + e(v) + '</code><div style="font-size:11px;color:var(--muted)">' + (r.partner ? 'Partner: ' + e(r.partner) : 'House promotion') + (r.label ? ' · ' + e(r.label) : '') + '</div>'; } },
        { key: 'describe', label: 'Customer gets', render: function (v) { return '<span style="font-size:12px">' + e(v) + '</span>'; } },
        { key: 'redemptions', label: 'Used', sortable: true, render: function (v, r) { return v + (r.max_redemptions ? ' / ' + r.max_redemptions : ''); } },
        { key: 'saved_minor', label: 'Saved customers', sortable: true, render: function (v) { return pAdmin.usd(v); } },
        { key: 'ends_at', label: 'Ends', sortable: true, render: function (v) { return v ? ts(v) : '—'; } },
        { key: 'status', label: 'Status', sortable: true, render: function (v) { return pAdmin.st(v); } },
        { key: 'id', label: '', render: function (v, r) { var b = []; if (r.status !== 'active') b.push(['active', 'Resume']); if (r.status === 'active') b.push(['paused', 'Pause']); if (r.status !== 'archived') b.push(['archived', 'Archive']);
          return b.map(function (k) { return '<button class="btn btn-ghost btn-sm" onclick="pAdmin.codeStatus(' + v + ',\'' + k[0] + '\',\'' + e(r.code) + '\')">' + k[1] + '</button>'; }).join(' '); } }
      ] }) + '</div>');
  }).bind(window.pages);
</script>
