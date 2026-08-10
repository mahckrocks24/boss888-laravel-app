{{-- Engineering — Incidents — /admin/engineering/incidents
     Renderer for the 'engIncidents' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading incidents...</div>';
        const d = await api('/engineering/incidents?limit=50');
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load incidents.</div>'; return; }
        let h = '';

        h += '<div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
        h += '<div style="font-weight:600;margin-bottom:6px">Three sources, never merged into one number</div>';
        h += '<div style="font-size:12px;color:var(--muted);line-height:1.7">' + esc(d.why_no_combined_total) + '</div>';
        h += '</div>';

        (d.sources || []).forEach(function (s) {
          h += '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:14px">';
          h += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">';
          h += '<span style="font-weight:600">' + esc(s.title) + '</span>';
          if (s.available) {
            h += '<span style="background:#34D39922;color:#34D399;border-radius:3px;padding:1px 7px;font-size:11px">' + esc(s.count) + ' record(s)</span>';
          } else {
            h += '<span style="background:#F59E0B22;color:#F59E0B;border-radius:3px;padding:1px 7px;font-size:11px">BLOCKED</span>';
          }
          h += '</div>';

          if (!s.available) {
            h += '<div style="font-size:12px;color:var(--muted)">' + esc(s.blocked_reason) + '</div>';
            if (s.expected_evidence) h += '<div style="font-size:11px;color:var(--muted);margin-top:6px">Would provide: ' + esc(s.expected_evidence) + '</div>';
          } else {
            if (s.what_it_is) h += '<div style="font-size:12px;margin-bottom:4px">' + esc(s.what_it_is) + '</div>';
            if (s.what_it_is_not) h += '<div style="font-size:12px;color:#F59E0B;margin-bottom:8px">' + esc(s.what_it_is_not) + '</div>';
            h += '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px"><tbody>';
            (s.entries || []).slice(0, 25).forEach(function (e) {
              h += '<tr style="border-bottom:1px solid var(--border)">';
              if (e.incident) {
                h += '<td style="padding:5px"><code>' + esc(e.incident) + '</code></td><td style="color:var(--muted)">in ' + esc(e.mentioned_in) + ' document(s)</td><td style="color:var(--muted);font-size:11px">' + esc((e.documents || []).slice(0, 2).join(', ')) + '</td>';
              } else {
                h += '<td style="padding:5px;color:var(--muted)">' + esc(e.id) + '</td><td>' + esc(e.title) + '</td><td>' + esc(e.severity) + '</td><td style="color:#34D399">' + esc(e.lifecycle_state) + '</td><td style="color:var(--muted);white-space:nowrap">' + esc(e.detected_at) + '</td>';
              }
              h += '</tr>';
            });
            h += '</tbody></table></div>';
            h += '<div style="font-size:11px;color:var(--muted);margin-top:8px">Evidence: ' + esc(s.evidence_source) + '</div>';
          }
          h += '</div>';
        });

        h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--muted);line-height:1.8">';
        (d.interpretation_limits || []).forEach(function (l) { h += '<div>&bull; ' + esc(l) + '</div>'; });
        h += '</div>';
        h += '<div style="margin-top:12px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">Read-only &middot; observed ' + esc(d.observed_at) + '</div>';
        content().innerHTML = h;
      });
</script>
