{{-- Notifications — /admin/notifications
     Renderer for the 'notificationsAdmin' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/notifications-all');
        if(!data) return;
        const items = (data.data||[]).map(function(n){ n._read = n.read_at ? 'read' : 'unread'; return n; });
        setContent(adminTable({
          id:'notifications', data:items,
          columns:[
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return v||'-';}},
            {key:'type',label:'Type',render:function(v){return badge(v||'-');}},
            {key:'title',label:'Title',sortable:true,render:function(v,row){return v||row.message||'-';}},
            {key:'_read',label:'Read',render:function(v){return v==='read'?'<span class="badge badge-green">Read</span>':'<span class="badge badge-amber">Unread</span>';}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['workspace_name','title','message','type'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'_read',label:'Status',options:[{value:'',label:'All'},{value:'read',label:'Read'},{value:'unread',label:'Unread'}]}]
        }));
      }).bind(window.pages);
</script>
