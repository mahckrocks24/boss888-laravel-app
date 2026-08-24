{{-- Engineering — Source & Route Ownership — /admin/engineering/ownership
     Renderer for the 'engOwnership' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc, val = pages._engValue, src = pages._engSource;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading ownership...</div>';
        const d = await api('/engineering/ownership');
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load ownership.</div>'; return; }

        const ig = d.integrity || {};
        let h = '';

        if (!ig.clean) {
          h += '<div style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.45);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
          h += '<div style="font-weight:600">Protected-path drift detected</div>';
          h += '<div style="font-size:12px;margin-top:6px;line-height:1.7">Drifted: ' + esc((ig.drifted||[]).join(', ') || 'none') + '<br>Added: ' + esc((ig.added||[]).join(', ') || 'none') + '<br>Removed: ' + esc((ig.removed||[]).join(', ') || 'none') + '</div>';
          h += '<div style="font-size:12px;color:var(--muted);margin-top:6px">Anything listed here was written outside governed tooling and must be recorded as an operational-policy violation.</div>';
          h += '</div>';
        }

        h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">';
        h += '<div class="stat-card"><div class="stat-value">' + esc(ig.protected_paths || 0) + '</div><div class="stat-label">Protected paths</div></div>';
        h += '<div class="stat-card"><div class="stat-value" style="color:' + (ig.clean ? '#10B981' : '#F87171') + '">' + (ig.clean ? 'clean' : 'DRIFT') + '</div><div class="stat-label">Integrity</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc((ig.live_locks||[]).length) + '</div><div class="stat-label">Live locks</div></div>';
        h += '<div class="stat-card"><div class="stat-value" style="color:' + ((ig.unmanaged_backups||[]).length ? '#F87171' : '#10B981') + '">' + esc((ig.unmanaged_backups||[]).length) + '</div><div class="stat-label">Unmanaged backups</div></div>';
        h += '</div>';

        const card = function (title, inner, s) {
          return '<div style="border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:10px;background:var(--s2)">'
               + '<div style="font-weight:600;font-size:14px;margin-bottom:8px">' + esc(title) + '</div>'
               + '<div style="font-size:13px;line-height:1.7">' + inner + '</div>' + src(s) + '</div>';
        };

        // live locks
        let li = (ig.live_locks || []).length
          ? (ig.live_locks || []).map(function (l) { return '<div>' + esc(l.scope) + ' &mdash; ' + esc(l.owner) + ' (' + esc(l.phase) + ')</div>'; }).join('')
          : '<div style="color:#10B981">No locks held. This is a normal, healthy state.</div>';
        h += card('Ownership locks', li, ig.source);

        // audit
        if (d.audit && d.audit.available === false) {
          h += card('Ownership audit trail', val(d.audit), null);
        } else if (d.audit) {
          const a = d.audit.value || {};
          let ai = '<div>Total events: <strong>' + esc(a.total_events) + '</strong></div><div style="margin-top:6px">';
          Object.keys(a.by_event || {}).forEach(function (k) {
            const warn = (k === 'write_refused_hash_mismatch');
            ai += '<div>' + esc(k) + ': <strong' + (warn ? ' style="color:#F59E0B"' : '') + '>' + esc(a.by_event[k]) + '</strong>' + (warn ? ' <span style="font-size:11px;color:var(--muted)">(the control refusing a stale write &mdash; working as designed)</span>' : '') + '</div>';
          });
          ai += '</div>';
          h += card('Ownership audit trail', ai, d.audit.source);
        }

        // registry
        if (d.registry && d.registry.available !== false) {
          const r = d.registry.value || {};
          const mods = r.modules || {};
          let ri = '<div>' + esc(Object.keys(mods).length) + ' owned route modules</div><div style="margin-top:6px;font-size:12px;color:var(--muted)">';
          Object.keys(mods).slice(0, 20).forEach(function (k) {
            ri += '<div>' + esc(k) + ' &mdash; ' + esc(mods[k].owner) + ' (' + esc(mods[k].routes) + ' routes)</div>';
          });
          ri += '</div>';
          h += card('Route module ownership', ri, d.registry.source);
        } else if (d.registry) {
          h += card('Route module ownership', val(d.registry), null);
        }

        // seal
        if (d.seal && d.seal.available !== false) {
          const s = d.seal.value || {};
          h += card('Protected-path seal',
            '<div>Sealed at: ' + esc(s.sealed_at) + '</div><div>By: ' + esc(s.owner) + '</div><div>Reason: ' + esc(s.reason) + '</div><div>Files sealed: <strong>' + esc(Object.keys(s.files || {}).length) + '</strong></div>',
            d.seal.source);
        } else if (d.seal) {
          h += card('Protected-path seal', val(d.seal), null);
        }

        if (d.notes && d.notes.length) {
          h += '<div style="border:1px dashed var(--border);border-radius:8px;padding:12px 14px;margin-top:12px;font-size:12px;color:var(--muted);line-height:1.8">';
          d.notes.forEach(function (n) { h += '<div>&bull; ' + esc(n) + '</div>'; });
          h += '</div>';
        }

        h += '<div style="margin-top:14px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">Read-only &middot; observed ' + esc(d.observed_at) + ' &middot; GET /api/admin/engineering/ownership</div>';
        content().innerHTML = h;
      });
</script>
