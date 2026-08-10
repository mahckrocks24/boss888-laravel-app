{{-- Advertising — Status — /admin/ads/status
     Renderer for the 'adsStatus' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._adsEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading advertising status...</div>';
        const d = await api('/ads/status');
        if (!d) { content().innerHTML = '<div style="color:#F87171">Could not load advertising status.</div>'; return; }

        const live = d.serving === true;
        let h = '';

        h += '<div style="background:' + (live ? 'rgba(0,229,168,.10)' : 'var(--s2)')
          + ';border:1px solid ' + (live ? 'rgba(0,229,168,.35)' : 'var(--border)')
          + ';border-radius:12px;padding:16px 18px;margin-bottom:16px">'
          + '<div style="font-size:16px;font-weight:600;color:' + (live ? '#00E5A8' : 'var(--t1)') + '">'
          + (live ? 'Advertising is LIVE' : 'Advertising is OFF') + '</div>'
          + '<div style="font-size:13px;color:var(--muted);margin-top:4px">' + esc(d.note) + '</div></div>';

        h += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">';
        h += pages._adsKpi('Master switch', d.master_enabled ? 'ON' : 'OFF', d.master_enabled ? 'good' : null);
        h += pages._adsKpi('Interstitial', d.modal_enabled ? 'ON' : 'OFF', d.modal_enabled ? 'good' : null);
        h += pages._adsKpi('Video', d.video_enabled ? 'ON' : 'OFF', d.video_enabled ? 'good' : null);
        h += pages._adsKpi('Active slots', (d.active_slots || []).join(', ') || 'none');
        h += '</div>';

        h += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">';
        h += pages._adsKpi('Active campaigns', pages._adsNum(d.campaigns.active));
        h += pages._adsKpi('Paid campaigns', pages._adsNum(d.campaigns.paid));
        h += pages._adsKpi('Creatives awaiting review', pages._adsNum(d.creatives_pending),
          d.creatives_pending > 0 ? 'warn' : null);
        h += '</div>';

        h += '<div style="display:flex;gap:10px;flex-wrap:wrap">';
        h += pages._adsKpi('Sites profiled', pages._adsNum(d.inventory.profiled));
        h += pages._adsKpi('Sellable to paid targeting', pages._adsNum(d.inventory.sellable), 'good');
        h += pages._adsKpi('House ads only', pages._adsNum(d.inventory.house_only),
          d.inventory.house_only > 0 ? 'warn' : null);
        h += '</div>';

        h += '<div style="margin-top:16px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">'
          + 'Read-only &middot; GET /api/admin/ads/status &middot; changes are made with <code>php artisan ads:settings</code></div>';

        content().innerHTML = h;
      });
</script>
