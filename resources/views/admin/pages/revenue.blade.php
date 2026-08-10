{{-- Revenue — /admin/revenue
     Renderer for the 'revenue' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () { setContent('<div class="loading">Loading...</div>'); const data = await api('/revenue'); if(!data) return; setContent('<div class="stats-grid" style="margin-bottom:16px"><div class="stat-card"><div class="stat-value" style="color:var(--ac)">$'+(data.mrr||0)+'</div><div class="stat-label">MRR</div></div><div class="stat-card"><div class="stat-value" style="color:var(--bl)">'+(data.active_subscriptions||0)+'</div><div class="stat-label">Active Subs</div></div><div class="stat-card"><div class="stat-value" style="color:var(--rd)">'+(data.churn_30d||0)+'</div><div class="stat-label">Churn (30d)</div></div></div><div class="card"><div class="card-title">Subscriptions by Plan</div>'+((data.subscriptions_by_plan||[]).length===0?'<div style="padding:10px;color:var(--muted)">No subscriptions</div>':'<table><thead><tr><th>Plan</th><th>Count</th><th>Price</th></tr></thead><tbody>'+(data.subscriptions_by_plan||[]).map(s=>'<tr><td>'+badge(s.plan||s.name||'-')+'</td><td>'+(s.count||0)+'</td><td>$'+(s.price||0)+'</td></tr>').join('')+'</tbody></table>')+'</div>'); }).bind(window.pages);
</script>
