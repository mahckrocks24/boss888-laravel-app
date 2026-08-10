{{-- Settings — /admin/settings
     Renderer for the 'settings' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading settings...</div>');
        const data = await api('/config');
        if (!data) { renderApiError(); return; }
        const settings = data.settings || [];
        const groups = [...new Set(settings.map(s => s.group))];
        const sections = groups.map(g =>
          '<div class="card">' +
            '<div class="card-title">' + g + '</div>' +
            settings.filter(s => s.group === g).map(s =>
              '<div class="form-group">' +
                '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">' +
                  '<label style="margin:0">' + s.key + (s.is_sensitive ? ' \ud83d\udd12' : '') + '</label>' +
                  (s.is_set ? '<span style="font-size:10px;font-weight:700;color:var(--ac);background:rgba(0,229,168,.1);border:1px solid rgba(0,229,168,.25);padding:1px 8px;border-radius:4px">Set \u2713</span>' : '<span style="font-size:10px;font-weight:700;color:var(--rd);background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.25);padding:1px 8px;border-radius:4px">Not Set</span>') +
                '</div>' +
                '<input type="' + (s.is_sensitive ? 'password' : 'text') + '" id="setting_' + s.key + '" placeholder="' + (s.is_set ? 'Enter new value to replace' : 'Enter value') + '" value="' + (!s.is_sensitive && s.is_set && s.value ? s.value : '') + '">' +
                '<div style="font-size:11px;color:var(--muted);margin-top:4px">' + (s.description || '') + '</div>' +
              '</div>').join('') +
          '</div>').join('');
        setContent(
          (sections || '<div class="card"><div class="card-title">No settings configured</div></div>') +
          '<button class="btn" onclick="saveSettings()">Save Settings</button>' +
          '<div style="margin-top:16px;padding:12px 16px;background:var(--s2);border-radius:8px;font-size:12px;color:var(--muted)">' +
            '<strong>Environment:</strong> ' + (data.env?.app_env || '\u2014') + ' | ' +
            '<strong>Queue:</strong> ' + (data.env?.queue_driver || '\u2014') + ' | ' +
            '<strong>Cache:</strong> ' + (data.env?.cache_driver || '\u2014') + ' | ' +
            '<strong>DB:</strong> ' + (data.env?.db_driver || '\u2014') +
          '</div>');
      }).bind(window.pages);
</script>
