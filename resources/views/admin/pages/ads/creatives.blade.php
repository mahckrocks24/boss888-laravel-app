{{-- Advertising — Creative Review — /admin/ads/creatives
     Renderer for the 'adsCreatives' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._adsEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading creatives...</div>';

        const r = await pages._adsCall('/ads/creatives');
        if (!r.ok) { content().innerHTML = '<div style="color:#F87171">' + esc(r.error) + '</div>'; return; }

        const all = (r.data && r.data.creatives) || [];
        const pending = all.filter(function (c) { return c.review_state === 'pending'; });
        const rest = all.filter(function (c) { return c.review_state !== 'pending'; });

        let h = '';
        if (window._adsMsg) { h += pages._adsNotice(esc(window._adsMsg.text), window._adsMsg.tone); window._adsMsg = null; }

        h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:var(--muted)">'
          + 'Nothing serves until it is approved &mdash; including house creatives. Approval requires a fresh MFA step-up.</div>';

        h += '<div style="font-weight:600;font-size:14px;margin-bottom:10px">Awaiting review ('
          + pending.length + ')</div>';

        if (!pending.length) {
          h += '<div style="color:var(--muted);font-size:13px;margin-bottom:20px">Nothing waiting.</div>';
        }

        pending.forEach(function (c) { h += pages._adsCreativeCard(c, true); });

        if (rest.length) {
          h += '<div style="font-weight:600;font-size:14px;margin:20px 0 10px">Reviewed (' + rest.length + ')</div>';
          rest.forEach(function (c) { h += pages._adsCreativeCard(c, false); });
        }

        content().innerHTML = h;
      });
</script>
