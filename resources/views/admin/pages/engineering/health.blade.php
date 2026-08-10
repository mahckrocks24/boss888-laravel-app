{{-- Engineering — Health — /admin/engineering/health
     Renderer for the 'engHealth' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading health...</div>';
        const d = await api('/engineering/health');
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load health.</div>'; return; }

        let h = '';
        const colour = { ok: '#10B981', degraded: '#F59E0B', error: '#F87171' }[d.overall] || '#6B7280';
        h += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">';
        h += '<div class="stat-card"><div class="stat-value" style="color:' + colour + '">' + esc(d.overall || 'unknown') + '</div><div class="stat-label">Overall</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(d.probe_count) + '</div><div class="stat-label">Probes</div></div>';
        h += '<div class="stat-card"><div class="stat-value" style="color:' + (d.blocked_count ? '#F59E0B' : '#10B981') + '">' + esc(d.blocked_count) + '</div><div class="stat-label">Blocked</div></div>';
        h += '</div>';

        (d.probes || []).forEach(function (p) {
          const r = p.result || {};
          const ok = r.available !== false;
          h += '<div style="border:1px solid var(--border);border-left:3px solid ' + (ok ? '#10B981' : '#F59E0B') + ';border-radius:8px;padding:12px 14px;margin-bottom:8px;background:var(--s2)">';
          h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><strong style="font-size:13px">' + esc(p.label) + '</strong>';
          h += '<span style="font-size:11px;color:' + (ok ? '#10B981' : '#F59E0B') + '">' + (ok ? 'MEASURED' : 'BLOCKED') + '</span></div>';
          if (!ok) {
            h += '<div style="font-size:12px;color:var(--muted)">' + esc(r.blocked_reason) + '</div>';
          } else {
            h += '<pre style="margin:0;font-size:11px;color:var(--muted);white-space:pre-wrap;word-break:break-word;max-height:160px;overflow:auto">' + esc(JSON.stringify(r.value, null, 1)) + '</pre>';
            h += pages._engSource(r.source);
          }
          h += '</div>';
        });

        h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:14px 16px;margin-top:12px">';
        h += '<div style="font-weight:600;font-size:13px;margin-bottom:4px">No history</div>';
        h += '<div style="font-size:12px;color:var(--muted);line-height:1.7">' + esc(d.history_note) + '</div></div>';

        h += '<div style="margin-top:14px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">Read-only &middot; observed ' + esc(d.observed_at) + ' &middot; probes cached ' + esc(d.cache_seconds) + 's &middot; GET /api/admin/engineering/health</div>';
        content().innerHTML = h;
      });
</script>
