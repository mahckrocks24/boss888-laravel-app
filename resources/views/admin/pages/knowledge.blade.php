{{-- Global Knowledge — /admin/knowledge
     Renderer for the 'knowledge' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/global-knowledge');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'knowledge', data:items,
          columns:[
            {key:'key',label:'Key',sortable:true,render:function(v,row){return '<strong>'+(v||row.title||'-')+'</strong>';}},
            {key:'category',label:'Category',sortable:true,render:function(v,row){return badge(v||row.type||'-');}},
            {key:'value',label:'Value',render:function(v,row){var txt=v||row.content||'-'; return '<span style="color:var(--muted);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block">'+txt.substring(0,100)+'</span>';}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['key','title','category','type'],
          defaultSort:{key:'created_at',dir:'desc'}
        }));
      }).bind(window.pages);
</script>
