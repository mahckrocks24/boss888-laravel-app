{{-- Email Templates — /admin/email-templates
     Renderer for the 'emailTemplatesAdmin' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading email templates…</div>');
        const data = await api('/email-templates');
        if (!data || (!data.templates && !Array.isArray(data))) {
          setContent('<div class="loading">Failed to load email templates.</div>');
          return;
        }
        const all = data.templates || data || [];
        window._admEmailTpls = all;
        const sysCount  = all.filter(function(t){ return t.is_system == 1; }).length;
        const userCount = all.filter(function(t){ return t.is_system != 1; }).length;

        const stats =
          '<div class="stats-grid" style="margin-bottom:16px">' +
            '<div class="stat-card"><div class="stat-value" style="color:var(--p)">' + all.length + '</div><div class="stat-label">Total</div></div>' +
            '<div class="stat-card"><div class="stat-value" style="color:#00E5A8">' + sysCount + '</div><div class="stat-label">System (Library)</div></div>' +
            '<div class="stat-card"><div class="stat-value">' + userCount + '</div><div class="stat-label">User-created</div></div>' +
          '</div>';

        const actions =
          '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px;flex-wrap:wrap">' +
            '<div style="display:flex;gap:6px">' +
              '<button id="ema-f-all"  onclick="_admEmailFilter(\'all\')"  style="background:var(--p);color:#fff;border:none;padding:6px 12px;font-size:12px;font-weight:600;border-radius:6px;cursor:pointer">All <span style="opacity:.7">' + all.length + '</span></button>' +
              '<button id="ema-f-sys"  onclick="_admEmailFilter(\'sys\')"  style="background:transparent;color:var(--muted);border:1px solid var(--border);padding:6px 12px;font-size:12px;font-weight:600;border-radius:6px;cursor:pointer">System <span style="opacity:.7">' + sysCount + '</span></button>' +
              '<button id="ema-f-user" onclick="_admEmailFilter(\'user\')" style="background:transparent;color:var(--muted);border:1px solid var(--border);padding:6px 12px;font-size:12px;font-weight:600;border-radius:6px;cursor:pointer">User <span style="opacity:.7">' + userCount + '</span></button>' +
            '</div>' +
            '<button onclick="_admEmailNew()" style="background:var(--p);color:#fff;border:none;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">+ New System Template</button>' +
          '</div>';

        setContent(stats + actions + '<div id="ema-grid"></div>');
        _admEmailRender('all');
      }).bind(window.pages);
</script>
