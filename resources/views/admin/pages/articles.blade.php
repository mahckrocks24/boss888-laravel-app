{{-- Articles — /admin/articles
     Renderer for the 'articles' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/articles');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id:'articles', data:items,
          columns:[
            {key:'title',label:'Title',sortable:true,render:function(v){return '<strong>'+(v||'-')+'</strong>';}},
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return '<span style="color:var(--muted)">'+(v||'-')+'</span>';}},
            {key:'status',label:'Status',sortable:true,render:function(v){return badge(v||'draft');}},
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['title','workspace_name'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'status',label:'Status',options:[{value:'',label:'All'},{value:'draft',label:'Draft'},{value:'published',label:'Published'}]}]
        }));
      }).bind(window.pages);
</script>
