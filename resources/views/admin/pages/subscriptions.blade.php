{{-- Subscriptions — /admin/subscriptions
     Renderer for the 'subscriptions' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading subscriptions...</div>');
        const data = await api('/subscriptions?per_page=200');
        if (!data) { renderApiError(); return; }
        const subs = data.data || [];
        setContent(adminTable({
          id: 'subscriptions',
          data: subs,
          columns: [
            {key: 'workspace_name', label: 'Workspace', sortable: true, render: function(v){ return v || '\u2014'; }},
            {key: 'plan_name', label: 'Plan', sortable: true, render: function(v){ return v || '\u2014'; }},
            {key: 'status', label: 'Status', sortable: true, render: function(v){ var map = {active:'green', past_due:'red', cancelled:'amber', trialing:'blue', expired:'red', superseded:'purple'}; return '<span class="badge badge-' + (map[v]||'blue') + '">' + v + '</span>'; }},
            {key: 'stripe_customer_id', label: 'Stripe Customer', render: function(v){ return '<span style="color:var(--muted);font-size:11px">' + (v || '\u2014') + '</span>'; }},
            {key: 'starts_at', label: 'Period Start', sortable: true, render: function(v){ return ts(v); }},
            {key: 'ends_at', label: 'Period End', sortable: true, render: function(v){ return ts(v); }}
          ],
          searchFields: ['workspace_name', 'plan_name', 'stripe_customer_id'],
          defaultSort: {key: 'starts_at', dir: 'desc'},
          filters: [
            {key: 'status', label: 'Status', options: [{value:'', label:'All Statuses'}, {value:'active', label:'Active'}, {value:'trialing', label:'Trialing'}, {value:'past_due', label:'Past Due'}, {value:'cancelled', label:'Cancelled'}, {value:'expired', label:'Expired'}]},
            {key: 'plan_name', label: 'Plan', options: [{value:'', label:'All Plans'}].concat(
              [...new Set(subs.map(function(s){ return s.plan_name; }).filter(Boolean))].sort().map(function(p){ return {value:p, label:p}; })
            )}
          ]
        }));
      }).bind(window.pages);
</script>
