{{-- Workspace Memory — /admin/memory
     Renderer for the 'memory' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/workspace-memory');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'memory', data:items,
          columns:[
            {key:'workspace_name',label:'Workspace',sortable:true},
            {key:'key',label:'Key',sortable:true,render:function(v){return '<strong>'+(v||'-')+'</strong>';}},
            {key:'value',label:'Value',render:function(v){return '<span style="color:var(--muted);max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block">'+(v||'-').substring(0,80)+'</span>';}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['workspace_name','key','value'],
          defaultSort:{key:'created_at',dir:'desc'}
        }));
      }).bind(window.pages);
</script>
