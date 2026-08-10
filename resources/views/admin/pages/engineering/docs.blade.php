{{-- Engineering — Documentation — /admin/engineering/docs
     Renderer for the 'engDocs' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading documentation...</div>';
        const d = await api('/engineering/documentation');
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load the documentation.</div>'; return; }

        let h = '';

        h += '<div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.30);border-radius:8px;padding:14px 16px;margin-bottom:18px">';
        h += '<div style="font-weight:600;margin-bottom:6px">' + esc(d.purpose) + '</div>';
        h += '<div style="font-size:12px;color:var(--muted);line-height:1.8">';
        (d.reading_rules || []).forEach(function (r) { h += '<div>&bull; ' + esc(r) + '</div>'; });
        h += '</div></div>';

        // the four concepts
        (d.concepts || []).forEach(function (c) {
          h += '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:12px;background:var(--s2)">';
          h += '<div style="font-weight:600;margin-bottom:6px">' + esc(c.title) + '</div>';
          h += '<div style="font-size:13px;line-height:1.7;color:var(--text)">' + esc(c.body) + '</div>';
          if (c.example) {
            h += '<div style="margin-top:8px;padding-left:10px;border-left:2px solid var(--border);font-size:12px;color:var(--muted);line-height:1.6"><em>' + esc(c.example) + '</em></div>';
          }
          h += '</div>';
        });

        // per-screen guidance
        h += '<div style="margin:20px 0 10px;font-weight:600">Screen by screen</div>';
        (d.screens || []).forEach(function (s) {
          const col = s.status === 'live' ? '#34D399' : (s.status === 'partial' ? '#F59E0B' : '#6B7280');
          h += '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:12px">';
          h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">';
          h += '<span style="font-weight:600">' + esc(s.title) + '</span>';
          h += '<span style="background:' + col + '22;color:' + col + ';border-radius:3px;padding:1px 7px;font-size:11px">' + esc(s.status) + '</span>';
          h += '<span style="font-size:11px;color:var(--muted)">' + esc(s.milestone) + '</span>';
          if (s.route) h += '<code style="font-size:11px;color:var(--muted);margin-left:auto">' + esc((s.route.methods||[]).join(',')) + ' ' + esc(s.route.uri) + '</code>';
          h += '</div>';

          h += '<div style="font-size:12px;margin-bottom:8px"><span style="color:var(--muted)">Measures:</span> ' + esc(s.measures) + '</div>';

          h += '<div style="font-size:12px;margin-bottom:8px"><span style="color:var(--muted)">Cannot prove:</span>';
          h += '<div style="margin-top:4px;line-height:1.7">';
          (s.cannot_prove || []).forEach(function (c) { h += '<div style="color:#F59E0B">&bull; <span style="color:var(--text)">' + esc(c) + '</span></div>'; });
          h += '</div></div>';

          h += '<div style="font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:8px">';
          h += 'Evidence: ' + esc(s.evidence_source) + '<br>Data source: ' + esc(s.data_source) + '</div>';
          h += '</div>';
        });

        const cov = (d.coverage && d.coverage.value) || {};
        h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:12px 14px;margin-top:14px;font-size:12px;color:var(--muted);line-height:1.8">';
        h += '<div>Documented ' + esc(cov.screens_documented) + ' of ' + esc(cov.screens_total) + ' screens (' + esc(cov.screens_reachable) + ' currently reachable).</div>';
        if ((cov.screens_without_guidance || []).length) {
          h += '<div style="color:#F87171">Undocumented reachable screens: ' + esc((cov.screens_without_guidance||[]).join(', ')) + '</div>';
        }
        (d.interpretation_limits || []).forEach(function (l) { h += '<div>&bull; ' + esc(l) + '</div>'; });
        h += '</div>';

        h += '<div style="margin-top:12px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">Read-only &middot; observed ' + esc(d.observed_at) + ' &middot; GET /api/admin/engineering/documentation</div>';
        content().innerHTML = h;
      });
</script>
