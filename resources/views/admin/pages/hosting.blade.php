{{-- Hosting — /admin/hosting
     Renderer for the 'hostingAdmin' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading hosting...</div>';
        const d = await api('/hosting');
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load hosting.</div>'; return; }

        const F = function (m) {
          if (!m) return '<span style="color:#6B7280">&mdash;</span>';
          if (m.available === false) return '<span style="color:#F59E0B">BLOCKED</span> <span style="font-size:11px;color:var(--muted)">' + esc(m.blocked_reason) + '</span>';
          return null;
        };

        let h = '';
        h += '<div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
        h += '<div style="font-weight:600;margin-bottom:6px">Read-only. Hosting is operated manually.</div>';
        h += '<div style="font-size:12px;color:var(--muted);line-height:1.7">';
        (d.interpretation_limits || []).forEach(function (l) { h += '<div>&bull; ' + esc(l) + '</div>'; });
        h += '</div></div>';

        (d.accounts || []).forEach(function (a) {
          h += '<div style="border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px">';
          h += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">';
          h += '<span style="font-weight:600;font-size:15px">' + esc(a.name) + '</span>';
          const st = (a.status && a.status.value) ? a.status.value.state : '?';
          const col = st === 'active' ? '#34D399' : '#F59E0B';
          h += '<span style="background:' + col + '22;color:' + col + ';border-radius:3px;padding:1px 8px;font-size:11px">' + esc(st) + '</span>';
          h += '<span style="font-size:11px;color:var(--muted)">workspace ' + esc(a.workspace_id) + ' ' + esc(a.workspace || '') + '</span>';
          h += '</div>';

          h += '<table style="width:100%;border-collapse:collapse;font-size:12px"><tbody>';
          const row = function (k, v) { h += '<tr style="border-bottom:1px solid var(--border)"><td style="padding:6px;color:var(--muted);width:170px;vertical-align:top">' + k + '</td><td style="padding:6px">' + v + '</td></tr>'; };

          // plan
          let pv = F(a.plan);
          if (pv === null) { const p = a.plan.value;
            pv = esc(p.name) + ' &middot; $' + esc(p.price) + ' &middot; sub ' + esc(p.subscription_status)
               + '<br><span style="font-size:11px;color:' + (p.chargeable ? '#34D399' : '#F87171') + '">'
               + (p.chargeable ? 'chargeable' : 'NOT chargeable &mdash; no Stripe price mapped') + '</span>'; }
          row('Plan', pv);

          // provider
          let vv = F(a.provider);
          if (vv === null) { const p = a.provider.value;
            vv = esc(p.effective_provider || p.connected_providers)
               + '<br><span style="font-size:11px;color:' + (p.automated_provisioning ? '#34D399' : '#F59E0B') + '">'
               + (p.automated_provisioning ? 'automated' : 'manual &mdash; no provider connection') + '</span>'; }
          row('Provider', vv);

          // domains
          let dv = F(a.domains);
          if (dv === null) { const dd = a.domains.value;
            dv = (dd.websites || []).map(function (w) {
              return esc(w.levelup_address || '(no address)') + (w.custom_domain ? ' &middot; custom: ' + esc(w.custom_domain) : ' &middot; <span style="color:var(--muted)">no custom domain</span>') + ' <span style="color:var(--muted)">(' + esc(w.status) + ')</span>';
            }).join('<br>') || '<span style="color:var(--muted)">no websites</span>';
            dv += '<br><span style="font-size:11px;color:var(--muted)">' + esc(dd.custom_domain_capability) + '</span>'; }
          row('Domains', dv);

          row('SSL', F(a.ssl) === null ? (a.ssl.value || []).map(function (c) { return esc(c.name); }).join(', ') : F(a.ssl));

          // backups
          const ab = a.backups || {};
          let bv = '';
          if (ab.account_backup_state && ab.account_backup_state.value) {
            bv += 'state: ' + esc(ab.account_backup_state.value.backup_state) + ' &middot; last: ' + esc(ab.account_backup_state.value.last_backup_at || 'never recorded');
          }
          bv += '<br>' + (F(ab.platform_backup_log) === null ? '<code style="font-size:11px">' + esc(String(ab.platform_backup_log.value).slice(0, 220)) + '</code>' : F(ab.platform_backup_log));
          bv += '<br><span style="font-size:11px;color:var(--muted)">' + esc(ab.note || '') + '</span>';
          row('Backups', bv);

          row('Last restore', F(a.last_restore) === null ? esc(a.last_restore.value) : F(a.last_restore));
          row('Notes', F(a.notes) === null ? '<span style="font-size:12px">' + esc(JSON.stringify(a.notes.value)) + '</span>' : F(a.notes));
          row('Support history', F(a.support_history) === null ? 'recorded' : F(a.support_history));

          let mv = F(a.migration_status);
          if (mv === null) { const m = a.migration_status.value;
            mv = 'provisioned by control plane: <strong>' + (m.provisioned_by_control_plane ? 'yes' : 'NO') + '</strong>'
               + '<br><span style="font-size:11px;color:var(--muted)">' + esc(m.note) + '</span>'; }
          row('Migration status', mv);

          let av = F(a.manual_actions);
          if (av === null) { av = (a.manual_actions.value || []).map(function (x) {
            return '&bull; ' + esc(x.action) + ' &mdash; <span style="color:var(--muted)">' + esc(x.how) + '</span>'; }).join('<br>'); }
          row('Manual actions', av);

          h += '</tbody></table></div>';
        });

        h += '<div style="margin-top:12px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">Read-only &middot; ' + esc(d.returned) + ' account(s) &middot; observed ' + esc(d.observed_at) + ' &middot; GET /api/admin/hosting</div>';
        content().innerHTML = h;
      });
</script>
