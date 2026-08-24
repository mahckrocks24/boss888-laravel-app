{{-- Websites — /admin/websites
     Renderer for the 'websitesAdmin' page, extracted from the single-file admin
     on 2026-07-29. Data comes from the /api/admin/* JSON API, unchanged.
     Shared helpers and global handlers live in /js/admin-shared.js. --}}
<script>
  window.page = (async function () {
        setContent('<div class="loading">Loading...</div>');
        const data = await api('/websites-all');
        if(!data) return;
        const items = data.data||[];
        setContent(adminTable({
          id: 'websites', data: items,
          columns: [
            {key:'name',label:'Name',sortable:true,render:function(v,row){
              // PATCH (clickable site names, 2026-05-09) — open the live site
              // in a new tab. URL precedence:
              //   custom_domain (verified) > subdomain (when published)
              //   > /storage/sites/{id}/index.html (works for drafts too,
              //     served by PublishedSiteMiddleware static-template path)
              var url;
              if (row && row.custom_domain) {
                url = 'https://' + row.custom_domain;
              } else if (row && row.subdomain && row.status === 'published') {
                url = 'https://' + (String(row.subdomain).indexOf('.') === -1
                  ? row.subdomain + '.levelupgrowth.io'
                  : row.subdomain);
              } else {
                url = '/storage/sites/' + (row && row.id ? row.id : 0) + '/index.html';
              }
              var name = (v || row && row.title) || '-';
              return '<a href="' + url + '" target="_blank" rel="noopener" '
                + 'style="color:inherit;text-decoration:none;border-bottom:1px dashed rgba(255,255,255,0.25);cursor:pointer" '
                + 'onmouseover="this.style.color=\'var(--p)\';this.style.borderBottomColor=\'var(--p)\'" '
                + 'onmouseout="this.style.color=\'inherit\';this.style.borderBottomColor=\'rgba(255,255,255,0.25)\'">'
                + '<strong>' + name + '</strong></a>';
            }},
            {key:'workspace_name',label:'Workspace',sortable:true,render:function(v){return '<span style="color:var(--muted)">'+(v||'-')+'</span>';}},
            {key:'domain',label:'Domain',render:function(v){return v||'-';}},
            {key:'status',label:'Status',sortable:true,render:function(v){return badge(v||'draft');}},
            {key:'page_count',label:'Pages',sortable:true,render:function(v){return (v||0)+'';} },
            {key:'created_at',label:'Created',sortable:true,render:function(v){return ts(v);}}
          ],
          searchFields:['name','workspace_name','domain'],
          defaultSort:{key:'created_at',dir:'desc'},
          filters:[{key:'status',label:'Status',options:[{value:'',label:'All'},{value:'draft',label:'Draft'},{value:'published',label:'Published'},{value:'active',label:'Active'}]}]
        }));
      }).bind(window.pages);
</script>
