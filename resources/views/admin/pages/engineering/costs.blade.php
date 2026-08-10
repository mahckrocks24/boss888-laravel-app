{{-- Engineering — Costs — /admin/engineering/costs
     Renderer for the 'engCosts' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc, src = pages._engSource;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading costs...</div>';
        const d = await api('/engineering/costs');
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load costs.</div>'; return; }
        const s = (d.spend && d.spend.value) || {};
        let h = '';

        h += '<div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
        h += '<div style="font-weight:600;margin-bottom:6px">Customer credits only &mdash; not money, not provider cost</div>';
        h += '<div style="font-size:12px;color:var(--muted);line-height:1.7">' + esc(d.arithmetic_note) + '</div>';
        h += '</div>';

        h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">';
        h += '<div class="stat-card"><div class="stat-value">' + esc(s.credits_committed) + '</div><div class="stat-label">Credits committed (spend)</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(s.credits_reserved) + '</div><div class="stat-label">Reserved</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(s.credits_released) + '</div><div class="stat-label">Released back</div></div>';
        h += '<div class="stat-card"><div class="stat-value" style="color:#6B7280">' + esc(s.naive_sum_all_rows) + '</div><div class="stat-label">Naive sum (wrong)</div></div>';
        h += '</div>';

        h += '<div style="border:1px solid rgba(248,113,113,.35);background:rgba(248,113,113,.07);border-radius:8px;padding:12px 14px;margin-bottom:16px">';
        h += '<div style="font-weight:600;font-size:13px;margin-bottom:4px">Provider cost: BLOCKED</div>';
        h += '<div style="font-size:12px;color:var(--muted)">' + esc(d.provider_cost.blocked_reason) + '</div></div>';

        h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">';
        h += '<div><div style="font-weight:600;margin-bottom:8px;font-size:13px">By reference type</div><table style="width:100%;border-collapse:collapse;font-size:12px"><tbody>';
        ((d.by_reference_type && d.by_reference_type.value) || []).forEach(function (r) {
          h += '<tr style="border-bottom:1px solid var(--border)"><td style="padding:5px"><code>' + esc(r.reference_type) + '</code></td><td style="text-align:right">' + esc(r.credits_committed) + '</td><td style="text-align:right;color:var(--muted)">' + esc(r.rows) + ' rows</td></tr>';
        });
        h += '</tbody></table></div>';
        h += '<div><div style="font-weight:600;margin-bottom:8px;font-size:13px">By workspace</div><table style="width:100%;border-collapse:collapse;font-size:12px"><tbody>';
        ((d.by_workspace && d.by_workspace.value) || []).forEach(function (r) {
          h += '<tr style="border-bottom:1px solid var(--border)"><td style="padding:5px">ws ' + esc(r.workspace_id) + '</td><td style="text-align:right">' + esc(r.credits_committed) + '</td><td style="text-align:right;color:var(--muted)">' + esc(r.commits) + '</td></tr>';
        });
        h += '</tbody></table></div></div>';

        h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:12px 14px;margin-top:16px;font-size:12px;color:var(--muted);line-height:1.8">';
        (d.interpretation_limits || []).forEach(function (l) { h += '<div>&bull; ' + esc(l) + '</div>'; });
        h += '</div>';
        h += '<div style="margin-top:12px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">' + esc(src(d.spend.source)) + ' &middot; Read-only &middot; observed ' + esc(d.observed_at) + '</div>';
        content().innerHTML = h;
      });
</script>
