{{-- Engineering — Overview — /admin/engineering/overview
     Renderer for the 'engOverview' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading overview...</div>';
        const d = await api('/engineering/overview');
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load the overview.</div>'; return; }

        let h = '';

        // What needs attention — the reason this screen exists.
        if (d.needs_attention && d.needs_attention.length) {
          h += '<div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.4);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
          h += '<div style="font-weight:600;margin-bottom:6px">Needs attention</div><ul style="margin:0;padding-left:18px;line-height:1.7">';
          d.needs_attention.forEach(function (a) { h += '<li>' + esc(a) + '</li>'; });
          h += '</ul></div>';
        } else {
          h += '<div style="background:rgba(16,185,129,.10);border:1px solid rgba(16,185,129,.35);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
          h += '<div style="font-weight:600">Nothing needs your attention</div>';
          h += '<div style="font-size:12px;color:var(--muted);margin-top:4px">Health, route integrity, protected-source integrity and the queue were all checked. See below for each.</div>';
          h += '</div>';
        }

        const hv = (d.health && d.health.status) || null;
        const wk = d.workers || {};
        const rt = d.routes || {};
        const si = d.source_integrity || {};

        h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px">';
        h += '<div class="stat-card"><div class="stat-value">' + esc((hv && hv.value) || '?') + '</div><div class="stat-label">Platform health</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + (wk.available ? esc(wk.value.running + '/' + wk.value.total) : 'BLOCKED') + '</div><div class="stat-label">Queue workers</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(rt.count || 0) + '</div><div class="stat-label">Routes' + (rt.matches === true ? ' &#10003;' : (rt.matches === false ? ' &#9888;' : '')) + '</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc((d.approvals && d.approvals.value && d.approvals.value.pending) || 0) + '</div><div class="stat-label">Approvals pending</div></div>';
        h += '</div>';

        const card = function (title, inner, src) {
          return '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:10px;background:var(--s2)">'
               + '<div style="font-weight:600;font-size:14px;margin-bottom:8px">' + esc(title) + '</div>'
               + '<div style="font-size:13px;line-height:1.8">' + inner + '</div>'
               + pages._engSource(src) + '</div>';
        };

        h += card('Source & route integrity',
          '<div>Protected paths: <strong>' + esc(si.protected_paths) + '</strong></div>'
          + '<div>Drift: ' + (si.clean ? '<span style="color:#10B981">none</span>' : '<span style="color:#F87171">' + esc((si.drifted || []).join(', ') || 'present') + '</span>') + '</div>'
          + '<div>Unmanaged backups: ' + ((si.unmanaged_backups || []).length ? '<span style="color:#F87171">' + esc(si.unmanaged_backups.join(', ')) + '</span>' : '<span style="color:#10B981">none</span>') + '</div>'
          + '<div>Live ownership locks: ' + esc((si.live_locks || []).length) + '</div>'
          + '<div>Route table matches governed baseline: ' + (rt.matches === true ? '<span style="color:#10B981">yes</span>' : (rt.comparable === false ? '<span style="color:#F59E0B">BLOCKED &mdash; ' + esc(rt.blocked_reason) + '</span>' : '<span style="color:#F87171">NO</span>')) + '</div>',
          si.source);

        const mt = (d.marketing_tasks_24h && d.marketing_tasks_24h.value) || {};
        h += card('Marketing tasks, last 24h  —  CUSTOMER work, not engineering execution',
          '<div>Created <strong>' + esc(mt.created || 0) + '</strong> &middot; completed <strong>' + esc(mt.completed || 0) + '</strong> &middot; failed <strong>' + esc(mt.failed || 0) + '</strong></div>',
          d.marketing_tasks_24h && d.marketing_tasks_24h.source);

        h += card('Queue', pages._engValue(d.queue), d.queue && d.queue.source);

        if (d.not_measured && d.not_measured.length) {
          h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:14px 16px;margin-top:14px">';
          h += '<div style="font-weight:600;font-size:13px;margin-bottom:6px">Not measured</div>';
          h += '<ul style="margin:0;padding-left:18px;font-size:12px;color:var(--muted);line-height:1.8">';
          d.not_measured.forEach(function (n) { h += '<li>' + esc(n) + '</li>'; });
          h += '</ul></div>';
        }

        h += '<div style="margin-top:16px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">Read-only &middot; observed ' + esc(d.observed_at) + ' &middot; GET /api/admin/engineering/overview</div>';
        content().innerHTML = h;
      });
</script>
