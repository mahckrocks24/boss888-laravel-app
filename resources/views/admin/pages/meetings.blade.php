{{-- Meetings — /admin/meetings
     Renderer for the 'meetingsAdmin' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/meetings-all');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'meetings', data:items,
          columns:[
            {key:'title',label:'Title',sortable:true,render:function(v,row){return '<strong>'+(v||row.topic||'-')+'</strong>';}},
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return '<span style="color:var(--muted)">'+(v||'-')+'</span>';}},
            {key:'type',label:'Type',render:function(v){return v||'-';}},
            {key:'status',label:'Status',sortable:true,render:function(v){return badge(v||'pending');}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['title','topic','workspace_name'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'status',label:'Status',options:[{value:'',label:'All'},{value:'pending',label:'Pending'},{value:'completed',label:'Completed'},{value:'cancelled',label:'Cancelled'}]}]
        }));
      }).bind(window.pages);
</script>
