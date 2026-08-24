{{-- Advertising — Campaigns — /admin/ads/campaigns
     Renderer for the 'adsCampaigns' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._adsEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading campaigns...</div>';

        const camps = await pages._adsCall('/ads/campaigns');
        const advs  = await pages._adsCall('/ads/advertisers');
        if (!camps.ok) { content().innerHTML = '<div style="color:#F87171">' + esc(camps.error) + '</div>'; return; }

        let h = '';
        if (window._adsMsg) { h += pages._adsNotice(esc(window._adsMsg.text), window._adsMsg.tone); window._adsMsg = null; }

        h += '<div style="margin-bottom:14px">'
          + pages._adsBtn('+ New campaign', 'pages._adsShowCampaignForm()', 'primary')
          + pages._adsBtn('+ New advertiser', 'pages._adsShowAdvertiserForm()')
          + pages._adsBtn('Reach estimator', 'pages._adsShowReach()')
          + '</div><div id="ads-form"></div>';

        const rows = (camps.data && camps.data.campaigns) || [];
        h += '<table style="width:100%;border-collapse:collapse;font-size:13px">'
          + '<thead><tr style="text-align:left;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em">'
          + '<th style="padding:8px 6px">Campaign</th><th>Advertiser</th><th>Kind</th><th>Status</th>'
          + '<th>Model</th><th>Rate</th><th>Creatives</th><th></th></tr></thead><tbody>';

        rows.forEach(function (c) {
          const statusColour = c.status === 'active' ? '#00E5A8' : (c.status === 'draft' ? 'var(--muted)' : '#F59E0B');
          const canActivate = c.status !== 'active' && Number(c.approved_count) > 0;
          h += '<tr style="border-top:1px solid var(--border)">'
            + '<td style="padding:8px 6px">' + esc(c.name) + '</td>'
            + '<td>' + esc(c.advertiser) + '</td>'
            + '<td>' + esc(c.kind) + '</td>'
            + '<td style="color:' + statusColour + '">' + esc(c.status) + '</td>'
            + '<td>' + esc(c.pricing_model) + '</td>'
            + '<td>' + pages._adsMoney(c.rate_micros) + '</td>'
            + '<td>' + esc(c.approved_count) + ' / ' + esc(c.creative_count) + ' approved</td>'
            + '<td style="text-align:right;white-space:nowrap">';
          if (canActivate) h += pages._adsBtn('Activate', "pages._adsSetCampaignStatus(" + c.id + ",'active')", 'primary');
          if (c.status === 'active') h += pages._adsBtn('Pause', "pages._adsSetCampaignStatus(" + c.id + ",'paused')");
          if (!canActivate && c.status !== 'active') {
            h += '<span style="font-size:11px;color:var(--muted)">needs an approved creative</span>';
          }
          h += '</td></tr>';
        });
        h += '</tbody></table>';

        if (!rows.length) h += '<div style="color:var(--muted);font-size:13px;padding:20px 0">No campaigns yet.</div>';

        window._adsAdvertisers = (advs.ok && advs.data && advs.data.advertisers) || [];
        h += '<div style="margin-top:14px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">'
          + 'A campaign is created as a draft and cannot go active until one of its creatives is approved.</div>';

        content().innerHTML = h;
      });
</script>
