{{-- Template Library — /admin/templates
     Renderer for the 'templatesAdmin' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        var tab = window._admTemplatesTab || 'websites';
        var tabBar =
          '<div style="display:flex;gap:6px;margin-bottom:18px;border-bottom:1px solid var(--border)">' +
            '<button onclick="window._admTemplatesTab=\'websites\';pages.templatesAdmin()" style="padding:10px 18px;background:' + (tab==='websites'?'var(--p)':'transparent') + ';color:' + (tab==='websites'?'#fff':'var(--muted)') + ';border:none;border-radius:6px 6px 0 0;cursor:pointer;font-size:13px;font-weight:600;letter-spacing:.02em;border-bottom:2px solid ' + (tab==='websites'?'var(--p)':'transparent') + ';margin-bottom:-1px">Website Templates</button>' +
            '<button onclick="window._admTemplatesTab=\'pages\';pages.templatesAdmin()" style="padding:10px 18px;background:' + (tab==='pages'?'var(--p)':'transparent') + ';color:' + (tab==='pages'?'#fff':'var(--muted)') + ';border:none;border-radius:6px 6px 0 0;cursor:pointer;font-size:13px;font-weight:600;letter-spacing:.02em;border-bottom:2px solid ' + (tab==='pages'?'var(--p)':'transparent') + ';margin-bottom:-1px">Page Templates</button>' +
          '</div>';
        setContent(tabBar + '<div id="tpl-tab-body"><div class="loading">Loading...</div></div>');
        if (tab === 'pages') { await this._templatesAdminPages(); } else { await this._templatesAdminWebsites(); }
      }).bind(window.pages);
</script>
