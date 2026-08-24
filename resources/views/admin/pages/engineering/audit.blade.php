{{-- Engineering — Audit Trail — /admin/engineering/audit
     Renderer for the 'engAudit' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async () => {
        const esc = pages._engEsc, src = pages._engSource;
        content().innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Loading audit trail...</div>';
        const qs = new URLSearchParams();
        qs.set('limit', String(window._engAuditLimit || 100));
        if (window._engAuditAction) qs.set('filter[action]', window._engAuditAction);
        const d = await api('/engineering/audit?' + qs.toString());
        if (!d) { content().innerHTML = '<div style="color:#F87171;padding:40px;text-align:center">Could not load the audit trail.</div>'; return; }

        const p = (d.profile && d.profile.value) || {};
        let h = '';

        // The single most important thing about this data.
        h += '<div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);border-radius:8px;padding:14px 16px;margin-bottom:16px">';
        h += '<div style="font-weight:600;margin-bottom:6px">This is not a "who did what" record</div>';
        h += '<div style="font-size:12px;color:var(--muted);line-height:1.7">';
        (d.interpretation_limits || []).forEach(function (l) { h += '<div>&bull; ' + esc(l) + '</div>'; });
        h += '</div></div>';

        h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">';
        h += '<div class="stat-card"><div class="stat-value">' + esc(p.total_rows || 0) + '</div><div class="stat-label">Entries</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(p.with_user || 0) + '</div><div class="stat-label">With a user</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(p.without_user || 0) + '</div><div class="stat-label">System / agent</div></div>';
        h += '<div class="stat-card"><div class="stat-value">' + esc(p.distinct_actions || 0) + '</div><div class="stat-label">Distinct actions</div></div>';
        h += '</div>';

        // action filter
        h += '<div style="margin-bottom:12px;font-size:13px">Filter by action: ';
        h += '<select onchange="window._engAuditAction=this.value||null;pages.engAudit()" style="background:var(--s2);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:5px 8px">';
        h += '<option value="">(all)</option>';
        ((d.top_actions && d.top_actions.value) || []).forEach(function (a) {
          const sel = (window._engAuditAction === a.action) ? ' selected' : '';
          h += '<option value="' + esc(a.action) + '"' + sel + '>' + esc(a.action) + ' (' + esc(a.c) + ')</option>';
        });
        h += '</select>';
        h += ' <span style="font-size:11px;color:var(--muted);margin-left:8px">action is indexed; date is not (TD-22)</span></div>';

        h += '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">';
        h += '<thead><tr style="text-align:left;color:var(--muted);border-bottom:1px solid var(--border)">'
           + '<th style="padding:6px">id</th><th>when</th><th>action</th><th>actor</th><th>entity</th><th>ws</th></tr></thead><tbody>';
        (d.entries || []).forEach(function (e) {
          h += '<tr style="border-bottom:1px solid var(--border)">';
          h += '<td style="padding:6px;color:var(--muted)">' + esc(e.id) + '</td>';
          h += '<td style="white-space:nowrap">' + esc(e.created_at) + '</td>';
          h += '<td><code>' + esc(e.action) + '</code></td>';
          h += '<td>' + (e.user_id ? ('user ' + esc(e.user_id)) : '<span style="color:var(--muted)">system</span>') + '</td>';
          h += '<td>' + esc(e.entity_type || '') + (e.entity_id ? (' #' + esc(e.entity_id)) : '') + '</td>';
          h += '<td style="color:var(--muted)">' + esc(e.workspace_id || '') + '</td>';
          h += '</tr>';
        });
        h += '</tbody></table></div>';

        h += '<div style="margin-top:12px;font-size:11px;color:var(--muted);border-top:1px solid var(--border);padding-top:10px">';
        h += 'Showing ' + esc(d.returned) + ' of ' + esc(p.total_rows) + ' &middot; range ' + esc(p.earliest) + ' &rarr; ' + esc(p.latest);
        h += '<br>Indexes used: ' + esc((d.index_use.applied || []).join(', ') || 'none (id order only)') + ' &middot; missing: ' + esc((d.index_use.missing || []).join(', '));
        h += '<br>Read-only &middot; observed ' + esc(d.observed_at) + ' &middot; GET /api/admin/engineering/audit</div>';
        content().innerHTML = h;
      });
</script>
