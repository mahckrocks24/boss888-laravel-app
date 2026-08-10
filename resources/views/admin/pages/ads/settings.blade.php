{{-- Advertising — Settings — /admin/ads/settings
     Renderer for the 'adsSettings' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._adsEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading settings...</div>';
        const d = await api('/ads/settings');
        if (!d) { content().innerHTML = '<div style="color:#F87171">Could not load settings.</div>'; return; }

        let h = '';
        if (window._adsMsg) { h += pages._adsNotice(esc(window._adsMsg.text), window._adsMsg.tone); window._adsMsg = null; }
        h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:var(--muted)">'
          + esc(d.note) + '</div>';

        h += '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:18px">'
          + '<thead><tr style="text-align:left;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em">'
          + '<th style="padding:8px 6px">Setting</th><th>Value</th><th>Default</th><th>Changed</th><th></th>'
          + '</tr></thead><tbody>';

        Object.keys(d.settings || {}).forEach(function (k) {
          const s = d.settings[k];
          const val = typeof s.value === 'object' ? JSON.stringify(s.value) : String(s.value);
          const def = typeof s.default === 'object' ? JSON.stringify(s.default) : String(s.default);
          h += '<tr style="border-top:1px solid var(--border);vertical-align:top">'
            + '<td style="padding:8px 6px"><div style="font-family:monospace;font-size:12px">' + esc(k) + '</div>'
            + '<div style="color:var(--muted);font-size:11px;line-height:1.6;max-width:520px">' + esc(s.description) + '</div></td>'
            + '<td style="font-family:monospace;font-size:12px;color:var(--t1)">' + esc(val) + '</td>'
            + '<td style="font-family:monospace;font-size:12px;color:var(--muted)">' + esc(def) + '</td>'
            + '<td style="color:' + (s.overridden ? '#F59E0B' : 'var(--muted)') + '">' + (s.overridden ? 'yes' : '') + '</td>' + '<td style="text-align:right;white-space:nowrap">' + (d.editable ? pages._adsBtn('Edit', "pages._adsSettingsEdit('" + k + "')") : '') + '</td>' + '</tr><tr id="set-row-' + k + '" data-value="' + esc(val) + '"><td colspan="5" style="padding:0 6px"><div id="set-edit-' + k + '"></div></td></tr>';
        });
        h += '</tbody></table>';

        // Answering "why can't I change viewability" in the UI, not in someone's memory.
        const fixed = d.fixed_by_design || {};
        if (Object.keys(fixed).length) {
          h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:12px 14px">'
            + '<div style="font-weight:600;font-size:13px;margin-bottom:8px">Deliberately not configurable</div>';
          Object.keys(fixed).forEach(function (k) {
            h += '<div style="margin-bottom:8px"><div style="font-family:monospace;font-size:12px;color:var(--t1)">' + esc(k) + '</div>'
              + '<div style="font-size:12px;color:var(--muted);line-height:1.7">' + esc(fixed[k]) + '</div></div>';
          });
          h += '</div>';
        }

        h += '<div style="margin-top:14px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">'
          + 'Read-only &middot; GET /api/admin/ads/settings &middot; edit with <code>php artisan ads:settings --key=&lt;key&gt; --value=&lt;value&gt;</code></div>';

        content().innerHTML = h;
      });
</script>
