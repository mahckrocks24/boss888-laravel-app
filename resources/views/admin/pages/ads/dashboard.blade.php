{{-- Advertising — Delivery — /admin/ads/dashboard
     Renderer for the 'adsDashboard' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._adsEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading delivery...</div>';
        const d = await api('/ads/dashboard?days=30');
        if (!d) { content().innerHTML = '<div style="color:#F87171">Could not load the dashboard.</div>'; return; }

        const t = d.totals || {};
        let h = pages._adsFresh(d);

        h += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">';
        h += pages._adsKpi('Requests', pages._adsNum(t.requests));
        h += pages._adsKpi('Impressions (net)', pages._adsNum(t.impressions_net));
        h += pages._adsKpi('Viewable', pages._adsNum(t.viewable));
        h += pages._adsKpi('Clicks', pages._adsNum(t.clicks));
        h += pages._adsKpi('Revenue', pages._adsMoney(t.revenue_micros), 'good');
        h += '</div>';

        // Gross / invalid / net always as three figures — never netted silently.
        h += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">';
        h += pages._adsKpi('Impressions gross', pages._adsNum(t.impressions_gross));
        h += pages._adsKpi('Invalid (excluded)', pages._adsNum(t.impressions_invalid),
          Number(t.impressions_invalid) > 0 ? 'warn' : null);
        h += pages._adsKpi('Invalid rate', pages._adsPct(t.invalid_rate),
          Number(t.invalid_rate) > 0.2 ? 'bad' : null);
        h += pages._adsKpi('CTR', pages._adsPct(t.ctr));
        h += pages._adsKpi('Viewability', pages._adsPct(t.viewability_rate));
        h += '</div>';

        const f = d.fill || {};
        h += '<div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:14px">'
          + '<div style="font-weight:600;font-size:13px;margin-bottom:8px">Fill split &mdash; the best single measure of inventory health</div>'
          + '<div style="font-size:13px;color:var(--muted);line-height:1.9">'
          + 'Paid impressions: <strong style="color:var(--t1)">' + pages._adsNum(f.paid_impressions) + '</strong><br>'
          + 'House impressions: <strong style="color:var(--t1)">' + pages._adsNum(f.house_impressions) + '</strong><br>'
          + 'Paid share: <strong style="color:var(--t1)">' + pages._adsPct(f.paid_share) + '</strong>'
          + '</div></div>';

        if ((d.alerts || []).length) {
          h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:12px 14px">'
            + '<div style="font-weight:600;font-size:13px;margin-bottom:6px">Attention</div>';
          d.alerts.forEach(function (a) {
            const col = a.level === 'action' ? '#F59E0B' : 'var(--muted)';
            h += '<div style="font-size:12px;color:' + col + ';line-height:1.8">&bull; ' + esc(a.message) + '</div>';
          });
          h += '</div>';
        }

        h += '<div style="margin-top:14px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">'
          + 'Read-only &middot; GET /api/admin/ads/dashboard &middot; reads ad_stats_daily, never raw events</div>';

        content().innerHTML = h;
      });
</script>
