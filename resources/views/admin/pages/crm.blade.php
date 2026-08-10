{{-- CRM Overview — /admin/crm
     Renderer for the 'crmAdmin' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () { setContent('<div class="loading">Loading...</div>'); const data = await api('/crm-overview'); if(!data) return; setContent('<div class="stats-grid" style="margin-bottom:16px"><div class="stat-card"><div class="stat-value" style="color:var(--ac)">'+(data.total_contacts||0)+'</div><div class="stat-label">Contacts</div></div><div class="stat-card"><div class="stat-value" style="color:var(--bl)">'+(data.total_leads||0)+'</div><div class="stat-label">Leads</div></div><div class="stat-card"><div class="stat-value" style="color:var(--p)">'+(data.total_deals||0)+'</div><div class="stat-label">Deals</div></div></div><div class="card"><div class="card-title">Recent Contacts</div>'+((data.recent_contacts||[]).length===0?'<div style="padding:10px;color:var(--muted)">No contacts</div>':'<table><thead><tr><th>Name</th><th>Email</th><th>Created</th></tr></thead><tbody>'+(data.recent_contacts||[]).map(c=>'<tr><td>'+(c.name||'-')+'</td><td style="color:var(--muted)">'+(c.email||'-')+'</td><td>'+ts(c.created_at)+'</td></tr>').join('')+'</tbody></table>')+'</div>'); }).bind(window.pages);
</script>
