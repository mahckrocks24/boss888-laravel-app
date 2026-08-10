{{-- Advertising — Inventory — /admin/ads/inventory
     Renderer for the 'adsInventory' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._adsEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading inventory...</div>';
        const d = await api('/ads/inventory?days=30');
        if (!d) { content().innerHTML = '<div style="color:#F87171">Could not load inventory.</div>'; return; }

        let h = pages._adsFresh(d);

        h += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">';
        h += pages._adsKpi('Sites profiled', pages._adsNum(d.site_count));
        h += pages._adsKpi('Sellable', pages._adsNum(d.sellable), 'good');
        h += pages._adsKpi('House only', pages._adsNum(d.site_count - d.sellable),
          (d.site_count - d.sellable) > 0 ? 'warn' : null);
        h += '</div>';

        h += '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px">'
          + '<thead><tr style="text-align:left;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em">'
          + '<th style="padding:8px 6px">Site</th><th>Industry</th><th>Country</th><th>Conf</th><th>Quality</th><th>Sellable</th><th style="text-align:right">Impr</th>'
          + '</tr></thead><tbody>';
        (d.sites || []).forEach(function (s) {
          h += '<tr style="border-top:1px solid var(--border)">'
            + '<td style="padding:8px 6px">' + esc(s.subdomain || ('#' + s.website_id)) + '</td>'
            + '<td>' + esc(s.industry || '—') + '</td>'
            + '<td>' + esc(s.country || '—') + '</td>'
            + '<td>' + Number(s.confidence).toFixed(2) + '</td>'
            + '<td>' + Number(s.quality).toFixed(2) + '</td>'
            + '<td style="color:' + (s.sellable ? '#00E5A8' : '#F59E0B') + '">' + (s.sellable ? 'yes' : 'house only') + '</td>'
            + '<td style="text-align:right">' + pages._adsNum(s.impressions) + '</td></tr>';
        });
        h += '</tbody></table>';

        [['by_archetype', 'By archetype'], ['by_country', 'By country'], ['by_industry', 'By industry']].forEach(function (pair) {
          const group = d[pair[0]] || {};
          const keys = Object.keys(group);
          if (!keys.length) return;
          h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:12px 14px;margin-bottom:10px">'
            + '<div style="font-weight:600;font-size:13px;margin-bottom:6px">' + pair[1] + '</div>'
            + '<div style="font-size:12px;color:var(--muted);line-height:1.9">';
          keys.forEach(function (k) {
            h += '<div>' + esc(k) + ' &mdash; <strong style="color:var(--t1)">' + group[k].sites + '</strong> site(s), '
              + pages._adsNum(group[k].impressions) + ' impressions</div>';
          });
          h += '</div></div>';
        });

        if ((d.unclassified || []).length) {
          h += '<div style="border:1px solid #F59E0B;border-radius:8px;padding:12px 14px;margin-top:6px">'
            + '<div style="font-weight:600;font-size:13px;color:#F59E0B;margin-bottom:6px">'
            + d.unclassified.length + ' site(s) cannot be sold to a targeted campaign</div>'
            + '<div style="font-size:12px;color:var(--muted)">They serve house ads only. Resolve with an admin override, '
            + 'or by populating the workspace industry and location.</div></div>';
        }

        h += '<div style="margin-top:14px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">'
          + 'Read-only &middot; GET /api/admin/ads/inventory &middot; this view is the rate card</div>';

        content().innerHTML = h;
      });
</script>
