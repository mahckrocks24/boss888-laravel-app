{{-- Plans — /admin/plans
     Renderer for the 'plans' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading plans...</div>');
        const data = await api('/plans');
        if (!data) { renderApiError(); return; }
        const rows = (data.plans || []).map(p =>
          '<tr>' +
            '<td><strong>' + p.name + '</strong></td>' +
            '<td>$' + p.price + '/mo</td>' +
            '<td>' + (p.credit_limit || 0) + ' cr</td>' +
            '<td>' + badge(p.ai_access || 'none') + '</td>' +
            '<td>' + (p.agent_count || 0) + '</td>' +
            '<td>' + (p.max_websites || 1) + '</td>' +
            '<td>' + (p.companion_app ? '\u2705' : '\u2014') + '</td>' +
          '</tr>').join('');
        setContent(
          '<div class="card">' +
            '<div class="card-title">Plans</div>' +
            '<table><thead><tr><th>Plan</th><th>Price</th><th>Credits</th><th>AI Access</th><th>Agents</th><th>Sites</th><th>APP888</th></tr></thead>' +
            '<tbody>' + rows + '</tbody></table>' +
          '</div>');
      }).bind(window.pages);
</script>
