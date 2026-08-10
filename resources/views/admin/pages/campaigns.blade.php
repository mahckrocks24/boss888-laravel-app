{{-- Campaigns — /admin/campaigns
     Renderer for the 'campaignsAdmin' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/campaigns-all');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'campaigns', data:items,
          columns:[
            {key:'name',label:'Name',sortable:true,render:function(v,row){return '<strong>'+(v||row.title||'-')+'</strong>';}},
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return '<span style="color:var(--muted)">'+(v||'-')+'</span>';}},
            {key:'status',label:'Status',sortable:true,render:function(v){return badge(v||'draft');}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['name','title','workspace_name'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'status',label:'Status',options:[{value:'',label:'All'},{value:'draft',label:'Draft'},{value:'active',label:'Active'},{value:'completed',label:'Completed'}]}]
        }));
      }).bind(window.pages);
</script>
