{{-- Engineering — Backups — /admin/engineering/backups
     Renderer for the 'engBackups' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc, src = pages._engSource;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading backups...</div>';
        const d = await api('/engineering/backups');
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load backups.</div>'; return; }

        let h = '';
        h += '<div style="background:rgba(108,92,231,.10);border:1px solid rgba(108,92,231,.35);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
        h += '<div style="font-weight:600;margin-bottom:4px">Four separate backup systems</div>';
        h += '<div style="font-size:12px;color:var(--muted);line-height:1.7">They are reported separately and never summed. A single total would be wrong in both directions &mdash; the source store is dominated by test captures, and the scheduled database and file backups live elsewhere entirely.</div>';
        h += '</div>';

        const sys = d.systems || {};
        Object.keys(sys).forEach(function (k) {
          const s = sys[k], r = s.result || {};
          const ok = r.available !== false;
          h += '<div style="border:1px solid var(--border);border-left:3px solid ' + (ok ? '#10B981' : '#F59E0B') + ';border-radius:8px;padding:14px 16px;margin-bottom:10px;background:var(--s2)">';
          h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px"><strong style="font-size:14px">' + esc(s.title) + '</strong>';
          h += '<span style="font-size:11px;color:' + (ok ? '#10B981' : '#F59E0B') + '">' + (ok ? 'MEASURED' : 'BLOCKED') + '</span></div>';
          h += '<div style="font-size:12px;color:var(--muted);margin-bottom:8px">Covers: ' + esc(s.covers) + '</div>';

          if (!ok) {
            h += '<div style="font-size:12px;color:#F59E0B;line-height:1.6">' + esc(r.blocked_reason) + '</div>';
          } else {
            const v = r.value || {};
            if (k === 'source_manifest') {
              h += '<div style="font-size:13px;line-height:1.8">';
              h += '<div>Deploy captures: <strong>' + esc(v.deploy_captures) + '</strong></div>';
              h += '<div>Test captures: <strong>' + esc(v.test_captures) + '</strong> <span style="font-size:11px;color:var(--muted)">(created by the test suite &mdash; not deploy evidence)</span></div>';
              h += '<div>Total directories: ' + esc(v.total) + '</div></div>';
              if ((v.deploys || []).length) {
                h += '<div style="margin-top:8px;font-size:12px;color:var(--muted)">Recent deploy captures:</div>';
                v.deploys.slice(0, 8).forEach(function (b) {
                  h += '<div style="font-size:12px;color:var(--muted)">&bull; ' + esc(b.label) + ' &mdash; ' + esc(b.captured_at) + ' &mdash; ' + esc(b.route_count) + ' routes &mdash; ' + esc(b.owner) + '</div>';
                });
              }
            } else if (k === 'quarantine') {
              const dirs = v.directories || {};
              Object.keys(dirs).forEach(function (dir) {
                h += '<div style="font-size:12px;line-height:1.7">' + esc(dir) + ': <strong>' + esc(dirs[dir].count) + '</strong> entries</div>';
              });
              if ((v.unreadable || []).length) h += '<div style="font-size:12px;color:#F59E0B">Unreadable: ' + esc(v.unreadable.join(', ')) + '</div>';
            } else {
              h += '<div style="font-size:12px;line-height:1.8">';
              if (v.schedule)  h += '<div><strong>Schedule:</strong> ' + esc(v.schedule) + '</div>';
              if (v.retention) h += '<div><strong>Retention:</strong> ' + esc(v.retention) + '</div>';
              if (v.groups_ok) h += '<div style="color:#10B981">' + esc(v.groups_ok) + '</div>';
              if (v.groups_failed) h += '<div>' + esc(v.groups_failed) + '</div>';
              (v.last_runs || []).slice(-3).forEach(function (l) { h += '<div style="color:var(--muted);font-size:11px">' + esc(l) + '</div>'; });
              (v.verifications || []).slice(-2).forEach(function (l) { h += '<div style="color:#10B981;font-size:11px">' + esc(l) + '</div>'; });
              (v.last_offsite || []).slice(-2).forEach(function (l) { h += '<div style="color:var(--muted);font-size:11px">' + esc(l) + '</div>'; });
              h += '</div>';
            }
            h += src(r.source);
          }
          h += '</div>';
        });

        if (d.rules) {
          h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:12px 14px;margin-top:12px">';
          h += '<div style="font-weight:600;font-size:13px;margin-bottom:6px">Rules</div><div style="font-size:12px;color:var(--muted);line-height:1.8">';
          d.rules.forEach(function (r) { h += '<div>&bull; ' + esc(r) + '</div>'; });
          h += '</div></div>';
        }
        if (d.not_shown) {
          h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:12px 14px;margin-top:10px">';
          h += '<div style="font-weight:600;font-size:13px;margin-bottom:6px">Not shown here</div><div style="font-size:12px;color:var(--muted);line-height:1.8">';
          d.not_shown.forEach(function (r) { h += '<div>&bull; ' + esc(r) + '</div>'; });
          h += '</div></div>';
        }

        h += '<div style="margin-top:14px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">Read-only &middot; no restore can be initiated here &middot; observed ' + esc(d.observed_at) + ' &middot; GET /api/admin/engineering/backups</div>';
        content().innerHTML = h;
      });
</script>
