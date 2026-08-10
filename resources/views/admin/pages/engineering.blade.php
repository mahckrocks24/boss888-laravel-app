{{-- Engineering Operations — /admin/engineering
     Renderer for the 'engineering' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading engineering manifest...</div>';
        const d = await api('/engineering/manifest');
        if (!d) {
          content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load the engineering manifest.</div>';
          return;
        }

        const esc = function (s) {
          return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
          });
        };
        const c = d.counts || {};

        let h = '';

        // Honest banner — this is the point of M0.
        h += '<div style="background:rgba(108,92,231,.10);border:1px solid rgba(108,92,231,.35);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
        h += '<div style="font-weight:600;margin-bottom:6px">Engineering Operations &mdash; read only</div>';
        h += '<div style="font-size:13px;color:var(--muted);line-height:1.6">' + esc(d.note) + '</div>';
        h += '<div style="font-size:12px;color:var(--muted);margin-top:8px">Phase ' + esc(d.phase) + ' &middot; milestone ' + esc(d.milestone) + ' &middot; observed ' + esc(d.observed_at) + '</div>';
        h += '</div>';

        h += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px">';
        h += '<div class="stat-card"><div class="stat-value">' + (d.total || 0) + '</div><div class="stat-label">Screens in scope</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + (c.planned || 0) + '</div><div class="stat-label">Planned (E1)</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + (c.not_built || 0) + '</div><div class="stat-label">Not built yet</div></div>';
        h += '</div>';

        const badge = function (status) {
          const m = {
            live:      ['#10B981', 'LIVE'],
            partial:   ['#F59E0B', 'PARTIAL'],
            planned:   ['#6C5CE7', 'PLANNED'],
            not_built: ['#6B7280', 'NOT BUILT']
          }[status] || ['#6B7280', String(status).toUpperCase()];
          return '<span style="background:' + m[0] + '22;color:' + m[0] + ';border:1px solid ' + m[0] + '55;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:600;letter-spacing:.03em">' + m[1] + '</span>';
        };

        const row = function (s) {
          let x = '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:10px;background:var(--s2)">';
          x += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">';
          x += '<div style="font-weight:600;font-size:14px">' + esc(s.title) + '</div>';
          x += badge(s.status);
          if (s.milestone) x += '<span style="font-size:11px;color:var(--muted)">' + esc(s.milestone) + '</span>';
          x += '<span style="margin-left:auto;font-size:11px;color:var(--muted)">phase ' + esc(s.phase) + '</span>';
          x += '</div>';
          x += '<div style="font-size:13px;color:var(--muted);margin-bottom:8px;line-height:1.5">' + esc(s.purpose) + '</div>';

          if (s.status === 'not_built') {
            x += '<div style="border-left:3px solid #6B7280;padding-left:12px;font-size:12px;line-height:1.7">';
            x += '<div><strong>Why empty:</strong> ' + esc(s.why_unavailable) + '</div>';
            x += '<div><strong>Introduced in:</strong> ' + esc(s.phase) + '</div>';
            x += '<div><strong>Will show:</strong> ' + esc(s.expected_evidence) + '</div>';
            if (s.alternative) x += '<div><strong>Today, use:</strong> ' + esc(s.alternative) + '</div>';
            if (s.missing_tables && s.missing_tables.length) {
              x += '<div style="color:var(--muted);margin-top:4px">Verified against the live schema &mdash; missing: <code>' + esc(s.missing_tables.join(', ')) + '</code></div>';
            }
            x += '</div>';
          } else {
            x += '<div style="font-size:12px;color:var(--muted);line-height:1.7">';
            x += '<div><strong>Data source:</strong> ' + esc(s.data_source) + '</div>';
            if (s.evidence_source) x += '<div><strong>Evidence:</strong> ' + esc(s.evidence_source) + '</div>';
            if (s.alternative) x += '<div><strong>Available now:</strong> ' + esc(s.alternative) + '</div>';
            x += '</div>';
          }
          x += '</div>';
          return x;
        };

        const planned  = (d.screens || []).filter(function (s) { return s.status !== 'not_built'; });
        const notBuilt = (d.screens || []).filter(function (s) { return s.status === 'not_built'; });

        h += '<div style="font-size:12px;font-weight:600;color:var(--muted);letter-spacing:.06em;margin:18px 0 8px">APPROVED FOR E1</div>';
        planned.forEach(function (s) { h += row(s); });

        h += '<div style="font-size:12px;font-weight:600;color:var(--muted);letter-spacing:.06em;margin:22px 0 8px">CAPABILITY DOES NOT EXIST YET</div>';
        h += '<div style="font-size:12px;color:var(--muted);margin-bottom:10px;line-height:1.6">These are shown deliberately. Hiding them would make the section look complete when it is not.</div>';
        notBuilt.forEach(function (s) { h += row(s); });

        h += '<div style="margin-top:20px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">';
        h += 'Source: GET /api/admin/engineering/manifest &middot; every screen above declares its data source, evidence source and owning phase.';
        h += '</div>';

        content().innerHTML = h;
      });
</script>
