/**
 * BLOG888 — Blog Management Engine v2.0.0
 * Pattern: matches write.js exactly (shell → dashboard → editor)
 * Uses /api/write/articles endpoints with type=blog_post filter
 */
window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['blog'] = true;

var _bl = {
  items: [], view: 'dashboard', currentItem: null, filterStatus: 'all', saving: false,
  isDirty: false, rootEl: null, isHouseAccount: false, wsId: null,
  categories: ['AI Marketing','SEO','Social Media','Content Marketing','Case Studies','Growth','Technology','Dubai Business'],
};

var _BL_STATUS = {published:'#00E5A8', draft:'#F59E0B', scheduled:'#3B8BF5'};

// ── Helpers ────────────────────────────────────────────────────────────────────
function _blE(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function _blDate(d){if(!d)return'\u2014';var dt=new Date((d+'').replace(' ','T'));return isNaN(dt)?d:dt.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});}
function _blReadTime(wc){return Math.max(1,Math.ceil((wc||0)/200));}

function _blApi(method, path, body) {
  var t=localStorage.getItem('lu_token')||'';
  var o={method:method,headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+t},cache:'no-store'};
  if(body)o.body=JSON.stringify(body);
  return fetch('/api/write'+path,o).then(function(r){return r.json();});
}

function _blBtnStyle(type){
  if(type==='primary')return'background:var(--p,#6C5CE7);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;';
  if(type==='danger')return'background:rgba(248,113,113,.1);color:#F87171;border:1px solid rgba(248,113,113,.2);border-radius:8px;padding:8px 12px;font-size:12px;cursor:pointer;font-family:inherit;';
  return'background:var(--s2,#1e2030);color:var(--t2,#aaa);border:1px solid var(--bd,#2a2d3e);border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;font-family:inherit;transition:all .15s;';
}
function _blInputStyle(){return'width:100%;box-sizing:border-box;background:var(--s2,#1e2030);border:1px solid var(--bd,#2a2d3e);border-radius:8px;color:var(--t1,#e0e0e0);font-size:14px;padding:10px 14px;outline:none;font-family:inherit;';}
function _blSelectStyle(){return'background:var(--s2,#1e2030);border:1px solid var(--bd,#2a2d3e);border-radius:8px;color:var(--t1,#e0e0e0);font-size:13px;padding:8px 12px;outline:none;font-family:inherit;cursor:pointer;';}
function _blLabelStyle(){return'display:block;font-size:11px;font-weight:600;color:var(--t3,#777);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;';}

// ── Entry point ────────────────────────────────────────────────────────────────
window.blogLoad = async function(el) {
  if(!el)return;
  _bl.rootEl=el;
  _bl.view='dashboard';
  _bl.currentItem=null;
  el.innerHTML='<div style="display:flex;align-items:center;justify-content:center;height:300px;color:var(--t2);gap:10px"><span style="animation:spin 1s linear infinite;display:inline-block;font-size:20px">&#x27F3;</span> Loading Blog...</div>';
  try {
    // Check house account
    try{var ws=await fetch('/api/workspace/status',{headers:{'Authorization':'Bearer '+(localStorage.getItem('lu_token')||''),'Accept':'application/json'}}).then(function(r){return r.json();});_bl.isHouseAccount=!!(ws.workspace&&ws.workspace.is_house_account);_bl.wsId=ws.workspace?ws.workspace.id:null;}catch(e){}
    await _blFetch();
    _blRender();
  }catch(e){el.innerHTML='<div style="padding:40px;color:var(--rd)">Blog failed: '+_blE(e.message)+'</div>';}
};

async function _blFetch(){
  var p='?type=blog_post&limit=100';
  if(_bl.filterStatus&&_bl.filterStatus!=='all')p+='&status='+_bl.filterStatus;
  var d=await _blApi('GET','/articles'+p).catch(function(){return{articles:[]};});
  _bl.items=d.articles||d.items||[];
}

function _blRender(){
  if(!_bl.rootEl)return;
  if(_bl.view==='editor')_blRenderEditor();
  else _blRenderDashboard();
}

// ═══════════════════════════════════════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════════
function _blRenderDashboard(){
  var el=_bl.rootEl;
  var items=_bl.items;
  var total=items.length;
  var pub=items.filter(function(i){return i.status==='published';}).length;
  var drafts=items.filter(function(i){return i.status==='draft';}).length;
  var words=items.reduce(function(a,i){return a+(i.word_count||0);},0);

  // Stats
  var stats=[
    {label:'Total Posts',value:total,color:'var(--p,#6C5CE7)'},
    {label:'Published',value:pub,color:'var(--ac,#00E5A8)'},
    {label:'Drafts',value:drafts,color:'var(--am,#F59E0B)'},
    {label:'Total Words',value:words.toLocaleString(),color:'var(--pu,#A78BFA)'},
  ];
  var statsHtml='<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">'+stats.map(function(s){
    return'<div style="background:var(--s1,#161927);border:1px solid var(--bd);border-radius:10px;padding:14px 16px"><div style="font-size:22px;font-weight:700;color:'+s.color+'">'+s.value+'</div><div style="font-size:12px;color:var(--t3);margin-top:4px">'+s.label+'</div></div>';
  }).join('')+'</div>';

  // Filters
  var filters=[{id:'all',l:'All',c:total},{id:'published',l:'Published',c:pub},{id:'draft',l:'Drafts',c:drafts}];
  var filterHtml='<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'+filters.map(function(f){
    var active=_bl.filterStatus===f.id;
    return'<button onclick="_blFilter(\''+f.id+'\')" style="'+_blBtnStyle(active?'primary':'secondary')+(active?'':'background:transparent;')+';padding:7px 14px;font-size:12px;">'+f.l+' <span style="opacity:.6">('+f.c+')</span></button>';
  }).join('')+'<div style="margin-left:auto"><button onclick="_blNewPost()" style="'+_blBtnStyle('primary')+'">+ New Post</button></div></div>';

  // Table
  var rows;
  if(items.length===0){
    rows='<tr><td colspan="6" style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:48px;margin-bottom:16px">'+window.icon("edit",14)+'</div><div style="font-size:16px;font-weight:600;color:var(--t1);margin-bottom:8px">No articles yet</div><div style="font-size:13px;margin-bottom:20px">Create your first blog post to start engaging your audience.</div><button onclick="_blNewPost()" style="'+_blBtnStyle('primary')+'">+ Create First Post</button></td></tr>';
  }else{
    rows=items.map(function(a){
      var sc=_BL_STATUS[a.status]||'var(--t3)';
      var readTime=_blReadTime(a.word_count);
      var catHtml=a.blog_category?'<span style="font-size:10px;padding:2px 8px;border-radius:4px;background:rgba(108,92,231,.1);color:var(--pu)">'+_blE(a.blog_category)+'</span>':'';
      var mktgBadge=a.is_marketing_blog?'<span style="font-size:10px;padding:2px 6px;border-radius:4px;background:rgba(0,229,168,.1);color:var(--ac)">Featured</span>':'';
      return'<tr style="border-bottom:1px solid var(--bd);cursor:pointer;transition:background .15s" onmouseover="this.style.background=\'var(--s2)\'" onmouseout="this.style.background=\'transparent\'" onclick="_blOpenEditor('+a.id+')">'
        +'<td style="padding:12px 14px"><div style="font-weight:600;color:var(--t1);margin-bottom:3px">'+_blE(a.title||'Untitled')+'</div><div style="font-size:11px;color:var(--t3)">'+_blE((a.excerpt||'').substring(0,80))+'</div></td>'
        +'<td style="padding:12px 14px;font-size:12px">'+catHtml+' '+mktgBadge+'</td>'
        +'<td style="padding:12px 14px;font-size:12px;color:var(--t2)">Sarah</td>'
        +'<td style="padding:12px 14px;font-size:12px;color:var(--t3)">'+_blDate(a.published_at||a.updated_at)+'<br><span style="font-size:10px">'+readTime+' min read</span></td>'
        +'<td style="padding:12px 14px"><span style="font-size:11px;padding:3px 8px;border-radius:20px;background:'+sc+'22;color:'+sc+';border:1px solid '+sc+'44">'+(a.status||'draft')+'</span></td>'
        +'<td style="padding:12px 14px;text-align:right" onclick="event.stopPropagation()">'
          +'<button onclick="_blOpenEditor('+a.id+')" style="'+_blBtnStyle('secondary')+';padding:5px 10px;font-size:12px" title="Edit">'+window.icon("edit",14)+'</button> '
          +'<button onclick="_blDeletePost('+a.id+')" style="'+_blBtnStyle('danger')+';padding:5px 10px;font-size:12px" title="Delete">'+window.icon("delete",14)+'</button>'
        +'</td></tr>';
    }).join('');
  }

  var thStyle='padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.05em;';

  el.innerHTML='<div style="padding:24px;max-width:1200px;">'
    +'<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px"><div><h2 style="margin:0;font-size:20px;font-weight:700;color:var(--t1);font-family:var(--fh)">'+window.icon("edit",14)+' Blog</h2><p style="margin:4px 0 0;font-size:13px;color:var(--t3)">Create and manage your blog content</p></div></div>'
    +statsHtml
    +'<div style="margin:16px 0">'+filterHtml+'</div>'
    +'<div style="background:var(--s1);border:1px solid var(--bd);border-radius:12px;overflow:hidden">'
      +'<table style="width:100%;border-collapse:collapse"><thead><tr style="background:var(--s2);border-bottom:1px solid var(--bd)">'
        +'<th style="'+thStyle+'">Title</th>'
        +'<th style="'+thStyle+'">Category</th>'
        +'<th style="'+thStyle+'">Author</th>'
        +'<th style="'+thStyle+'">Date</th>'
        +'<th style="'+thStyle+'">Status</th>'
        +'<th style="'+thStyle+';text-align:right">Actions</th>'
      +'</tr></thead><tbody>'+rows+'</tbody></table></div></div>';
}

// ═══════════════════════════════════════════════════════════════════════════════
// EDITOR
// ═══════════════════════════════════════════════════════════════════════════════
async function _blOpenEditor(id){
  try{
    var d=await _blApi('GET','/articles/'+id);
    var item=d.article||d;
    _bl.currentItem=item;
    _bl.view='editor';
    _bl.isDirty=false;
    _blRender();
  }catch(e){if(typeof showToast==='function')showToast('Failed to load article','error');}
}

function _blRenderEditor(){
  var el=_bl.rootEl;
  var a=_bl.currentItem;
  if(!a){el.innerHTML='<div style="padding:40px;color:var(--t3)">No article loaded. <a href="#" onclick="_blBackToDash()" style="color:var(--p)">\u2190 Back</a></div>';return;}

  var seoJson=a.seo_json;
  if(typeof seoJson==='string')try{seoJson=JSON.parse(seoJson);}catch(e){seoJson={};}
  if(!seoJson)seoJson={};

  var metaTitle=a.meta_title||seoJson.title||'';
  var metaDesc=a.meta_description||seoJson.description||'';
  var focusKw=a.focus_keyword||seoJson.keyword||'';
  var readTime=_blReadTime(a.word_count);

  // Top bar
  var topBar='<div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--bd);flex-shrink:0;background:var(--s1)">'
    +'<button onclick="_blBackToDash()" style="'+_blBtnStyle('secondary')+';padding:6px 12px;font-size:12px">\u2190 Back</button>'
    +'<div style="flex:1;font-size:14px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+_blE(a.title||'Untitled')+'</div>'
    +'<span id="bl-dirty" style="font-size:11px;color:var(--am);display:none">Unsaved changes</span>'
    +'<button onclick="_blSaveDraft()" style="'+_blBtnStyle('secondary')+'">Save Draft</button>'
    +'<button onclick="_blPublish()" style="'+_blBtnStyle('primary')+'">Publish \u2192</button>'
  +'</div>';

  // Editor area (left) + settings sidebar (right)
  var catOptions=_bl.categories.map(function(c){return'<option value="'+_blE(c)+'"'+(a.blog_category===c?' selected':'')+'>'+_blE(c)+'</option>';}).join('');

  var editor='<div style="flex:1;min-width:0;padding:24px;overflow-y:auto">'
    +'<input id="bl-title" type="text" value="'+_blE(a.title||'')+'" placeholder="Article title..." style="'+_blInputStyle()+'font-size:24px;font-weight:700;font-family:var(--fh);border:none;background:transparent;padding:0 0 12px;margin-bottom:16px;border-bottom:1px solid var(--bd)" oninput="_blMarkDirty()">'
    // TipTap toolbar (2026-04-19 rewrite) — onclick calls go to _blCmd + helpers
    +'<div id="bl-toolbar">'
      +'<button onclick="_blCmd(\'toggleBold\')" data-cmd="bold" title="Bold"><b>B</b></button>'
      +'<button onclick="_blCmd(\'toggleItalic\')" data-cmd="italic" title="Italic"><i>I</i></button>'
      +'<button onclick="_blCmd(\'toggleUnderline\')" data-cmd="underline" title="Underline"><u>U</u></button>'
      +'<button onclick="_blCmd(\'toggleStrike\')" data-cmd="strike" title="Strikethrough"><s>S</s></button>'
      +'<button onclick="_blCmd(\'toggleCode\')" data-cmd="code" title="Inline code">&lt;&gt;</button>'
      +'<div class="bl-toolbar-divider"></div>'
      +'<button onclick="_blCmd(\'toggleHeading\', {level:1})" data-cmd="heading-1" title="Heading 1">H1</button>'
      +'<button onclick="_blCmd(\'toggleHeading\', {level:2})" data-cmd="heading-2" title="Heading 2">H2</button>'
      +'<button onclick="_blCmd(\'toggleHeading\', {level:3})" data-cmd="heading-3" title="Heading 3">H3</button>'
      +'<div class="bl-toolbar-divider"></div>'
      +'<button onclick="_blCmd(\'toggleBulletList\')" data-cmd="bulletList" title="Bullet list">&bull; List</button>'
      +'<button onclick="_blCmd(\'toggleOrderedList\')" data-cmd="orderedList" title="Numbered list">1. List</button>'
      +'<div class="bl-toolbar-divider"></div>'
      +'<button onclick="_blCmd(\'toggleBlockquote\')" data-cmd="blockquote" title="Quote">\u201C Quote</button>'
      +'<button onclick="_blCmd(\'toggleCodeBlock\')" data-cmd="codeBlock" title="Code block">{ Code }</button>'
      +'<button onclick="_blCmd(\'setHorizontalRule\')" title="Divider">\u2014 HR</button>'
      +'<div class="bl-toolbar-divider"></div>'
      +'<button onclick="_blInsertLink()" title="Insert link">'+window.icon('link',18)+' Link</button>'
      +'<button onclick="_blInsertImage()" title="Insert image">\uD83D\uDDBC Image</button>'
      +'<div class="bl-toolbar-divider"></div>'
      +'<button onclick="_blCmd(\'undo\')" title="Undo">\u21A9 Undo</button>'
      +'<button onclick="_blCmd(\'redo\')" title="Redo">\u21AA Redo</button>'
    +'</div>'
    // Empty mount point — TipTap attaches here after render (_blInitTipTap)
    +'<div id="bl-content"></div>'
    +'<div style="display:flex;gap:16px;margin-top:12px;font-size:12px;color:var(--t3)">'
      +'<span id="bl-wordcount">'+(a.word_count||0)+' words</span>'
      +'<span id="bl-readtime">'+readTime+' min read</span>'
    +'</div>'
  +'</div>';

  // Sidebar
  var sidebar='<div style="width:280px;flex-shrink:0;border-left:1px solid var(--bd);padding:16px;overflow-y:auto;display:flex;flex-direction:column;gap:14px">'
    // Status
    +'<div style="background:var(--s2);border-radius:8px;padding:12px"><div style="'+_blLabelStyle()+'">Status</div><select id="bl-status" style="'+_blSelectStyle()+'width:100%"><option value="draft"'+(a.status==='draft'?' selected':'')+'>Draft</option><option value="published"'+(a.status==='published'?' selected':'')+'>Published</option></select></div>'
    // Category
    +'<div style="background:var(--s2);border-radius:8px;padding:12px"><div style="'+_blLabelStyle()+'">Category</div><select id="bl-category" style="'+_blSelectStyle()+'width:100%"><option value="">None</option>'+catOptions+'</select></div>'
    // Excerpt
    +'<div style="background:var(--s2);border-radius:8px;padding:12px"><div style="'+_blLabelStyle()+'">Excerpt</div><textarea id="bl-excerpt" rows="3" style="'+_blInputStyle()+'font-size:12px;resize:vertical" oninput="_blMarkDirty()">'+_blE(a.excerpt||'')+'</textarea></div>'
    // Featured Image — Phase 3 (2026-04-19): adds [${window.icon('image',14)} Choose from Library]
    // button next to the URL input. The URL input stays as a manual fallback.
    +'<div style="background:var(--s2);border-radius:8px;padding:12px"><div style="'+_blLabelStyle()+'">Featured Image</div>'
      +'<div style="display:flex;gap:6px;margin-bottom:8px">'
        +'<input id="bl-featured-img" type="url" value="'+_blE(a.featured_image_url||'')+'" placeholder="https://..." style="'+_blInputStyle()+'font-size:12px;flex:1" oninput="_blMarkDirty();_blPreviewImg()">'
        +'<button type="button" onclick="_blPickFeaturedImage()" style="background:var(--p,#6C5CE7);color:#fff;border:none;border-radius:6px;padding:0 12px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap" title="Choose from Media Library">\uD83D\uDCF7 Library</button>'
      +'</div>'
      +'<div id="bl-img-preview" style="margin-top:8px;border-radius:6px;overflow:hidden">'+(a.featured_image_url?'<img src="'+_blE(a.featured_image_url)+'" style="width:100%;height:120px;object-fit:cover">':'')+'</div></div>'
    // SEO
    +'<div style="background:var(--s2);border-radius:8px;padding:12px"><div style="'+_blLabelStyle()+'">SEO Settings</div>'
      +'<div style="margin-bottom:8px"><label style="font-size:10px;color:var(--t3)">Meta title</label><input id="bl-meta-title" type="text" value="'+_blE(metaTitle)+'" style="'+_blInputStyle()+'font-size:12px;padding:6px 10px" oninput="_blMarkDirty()"><div style="font-size:10px;color:var(--t3);text-align:right" id="bl-meta-title-count">'+metaTitle.length+'/60</div></div>'
      +'<div style="margin-bottom:8px"><label style="font-size:10px;color:var(--t3)">Meta description</label><textarea id="bl-meta-desc" rows="2" style="'+_blInputStyle()+'font-size:12px;padding:6px 10px;resize:vertical" oninput="_blMarkDirty()">'+_blE(metaDesc)+'</textarea><div style="font-size:10px;color:var(--t3);text-align:right" id="bl-meta-desc-count">'+metaDesc.length+'/160</div></div>'
      +'<div><label style="font-size:10px;color:var(--t3)">Focus keyword</label><input id="bl-focus-kw" type="text" value="'+_blE(focusKw)+'" style="'+_blInputStyle()+'font-size:12px;padding:6px 10px" oninput="_blMarkDirty()"></div>'
    +'</div>';

  // House account toggle
  if(_bl.isHouseAccount){
    sidebar+='<div style="background:var(--s2);border-radius:8px;padding:12px"><div style="'+_blLabelStyle()+'">House Account</div>'
      +'<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--t2)"><input type="checkbox" id="bl-marketing-blog" '+(a.is_marketing_blog?'checked':'')+' onchange="_blMarkDirty()" style="accent-color:var(--p)"> Feature on levelupgrowth.io/blog</label></div>';
  }

  sidebar+='</div>';

  el.innerHTML='<div style="display:flex;flex-direction:column;height:100%;min-height:0">'+topBar+'<div style="display:flex;flex:1;min-height:0">'+editor+sidebar+'</div></div>';

  // SEO counter listeners
  var mtEl=document.getElementById('bl-meta-title');
  var mdEl=document.getElementById('bl-meta-desc');
  if(mtEl)mtEl.addEventListener('input',function(){document.getElementById('bl-meta-title-count').textContent=this.value.length+'/60';});
  if(mdEl)mdEl.addEventListener('input',function(){document.getElementById('bl-meta-desc-count').textContent=this.value.length+'/160';});

  // TipTap init (2026-04-19). Requires the 6 CDN bundles in index.html.
  // Falls back to a plain contenteditable if TipTap didn't load.
  requestAnimationFrame(function(){ _blInitTipTap(a.content || ''); });
}

// ═══════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ═══════════════════════════════════════════════════════════════════════════════
// Phase 3 — open unified media picker for featured image.
// Falls back to URL input if the picker script didn't load.
window._blPickFeaturedImage = function() {
    if (typeof window.openMediaPicker !== 'function') {
        alert('Media picker is not loaded. Paste a URL instead.');
        return;
    }
    window.openMediaPicker({ type:'image', context:'blog', multiple:false }, function(file) {
        if (!file || !file.url) return;
        var inp = document.getElementById('bl-featured-img');
        if (inp) {
            inp.value = file.url;
            inp.dispatchEvent(new Event('input', { bubbles:true }));
        }
        if (typeof _blPreviewImg === 'function') _blPreviewImg();
        if (typeof _blMarkDirty === 'function') _blMarkDirty();
    });
};
window._blFilter=function(s){_bl.filterStatus=s;_blFetch().then(_blRender);};

window._blNewPost=async function(){
  var title = (typeof window.luPrompt === 'function')
    ? await window.luPrompt('New article', '', 'Enter article title…')
    : prompt('Enter article title:');
  if(!title)return;
  try{
    var d=await _blApi('POST','/articles',{title:title,type:'blog_post',status:'draft',blog_category:'',content:'<p>Start writing...</p>'});
    // Response shape after BaseEngineController::executeAction is nested:
    //   {success:true, data:{article_id:N, status:'draft'}, credits_used:0, ...}
    // Older direct-service shape was flat {article_id:N, id:N}. Handle both.
    var id=(d&&d.data&&(d.data.article_id||d.data.id))||d.article_id||d.id;
    if(id){await _blFetch();_blOpenEditor(id);}
    else{if(typeof showToast==='function')showToast((d&&(d.message||d.error))||'Failed to create','error');}
  }catch(e){if(typeof showToast==='function')showToast('Error: '+e.message,'error');}
};

window._blDeletePost=async function(id){
  if(!confirm('Delete this article?'))return;
  try{
    await _blApi('DELETE','/articles/'+id);
    _bl.items=_bl.items.filter(function(i){return i.id!==id;});
    if(_bl.currentItem&&_bl.currentItem.id===id){_bl.currentItem=null;_bl.view='dashboard';}
    _blRender();
    if(typeof showToast==='function')showToast('Article deleted','success');
  }catch(e){if(typeof showToast==='function')showToast('Delete failed','error');}
};

window._blBackToDash=function(){
  if(_bl.isDirty&&!confirm('You have unsaved changes. Discard?'))return;
  if(typeof window._blDestroyEditor==='function') window._blDestroyEditor();
  _bl.view='dashboard';_bl.currentItem=null;_bl.isDirty=false;_blFetch().then(_blRender);
};

window._blMarkDirty=function(){
  _bl.isDirty=true;
  var d=document.getElementById('bl-dirty');if(d)d.style.display='inline';
};

window._blUpdateWordCount=function(){
  var wc = 0;
  if (window._blEditor) {
    // Prefer CharacterCount extension if enabled
    try { if (window._blEditor.storage && window._blEditor.storage.characterCount) wc = window._blEditor.storage.characterCount.words(); } catch(e){}
    if (!wc) {
      var text = window._blEditor.getText() || '';
      wc = text.trim() ? text.trim().split(/\s+/).length : 0;
    }
  } else {
    var ce=document.getElementById('bl-content');
    if (ce) { var text=ce.innerText||''; wc=text.trim()?text.trim().split(/\s+/).length:0; }
  }
  var wcEl=document.getElementById('bl-wordcount');if(wcEl)wcEl.textContent=wc+' words';
  var rtEl=document.getElementById('bl-readtime');if(rtEl)rtEl.textContent=_blReadTime(wc)+' min read';
};

window._blPreviewImg=function(){
  var url=(document.getElementById('bl-featured-img')||{}).value||'';
  var prev=document.getElementById('bl-img-preview');
  if(prev)prev.innerHTML=url?'<img src="'+_blE(url)+'" style="width:100%;height:120px;object-fit:cover" onerror="this.style.display=\'none\'">':'';
};

window._blSaveDraft=async function(){
  if(!_bl.currentItem||_bl.saving)return;
  _bl.saving=true;
  var data=_blGatherEditorData();
  data.status='draft';
  try{
    await _blApi('PUT','/articles/'+_bl.currentItem.id,data);
    Object.assign(_bl.currentItem,data);
    _bl.isDirty=false;
    var d=document.getElementById('bl-dirty');if(d)d.style.display='none';
    if(typeof showToast==='function')showToast('Draft saved','success');
  }catch(e){if(typeof showToast==='function')showToast('Save failed: '+e.message,'error');}
  _bl.saving=false;
};

window._blPublish=async function(){
  if(!_bl.currentItem||_bl.saving)return;
  _bl.saving=true;
  var data=_blGatherEditorData();
  data.status='published';
  data.published_at=new Date().toISOString().slice(0,19).replace('T',' ');
  try{
    await _blApi('PUT','/articles/'+_bl.currentItem.id,data);
    Object.assign(_bl.currentItem,data);
    _bl.isDirty=false;
    if(typeof showToast==='function')showToast('Article published!','success');
    _bl.view='dashboard';_bl.currentItem=null;_blFetch().then(_blRender);
  }catch(e){if(typeof showToast==='function')showToast('Publish failed: '+e.message,'error');}
  _bl.saving=false;
};

function _blGatherEditorData(){
  // Prefer TipTap's getHTML + getText; fall back to raw DOM if editor missing.
  var content, text;
  if (window._blEditor) {
    content = window._blEditor.getHTML();
    text    = window._blEditor.getText() || '';
  } else {
    var ce=document.getElementById('bl-content');
    content = ce ? ce.innerHTML : '';
    text    = ce ? (ce.innerText||'') : '';
  }
  var wc=text.trim()?text.trim().split(/\s+/).length:0;
  return {
    title:(document.getElementById('bl-title')||{}).value||'Untitled',
    content:content,
    excerpt:(document.getElementById('bl-excerpt')||{}).value||'',
    blog_category:(document.getElementById('bl-category')||{}).value||'',
    featured_image_url:(document.getElementById('bl-featured-img')||{}).value||'',
    meta_title:(document.getElementById('bl-meta-title')||{}).value||'',
    meta_description:(document.getElementById('bl-meta-desc')||{}).value||'',
    focus_keyword:(document.getElementById('bl-focus-kw')||{}).value||'',
    is_marketing_blog:_bl.isHouseAccount&&document.getElementById('bl-marketing-blog')?document.getElementById('bl-marketing-blog').checked:(_bl.currentItem?_bl.currentItem.is_marketing_blog:false),
    word_count:wc,
    read_time:_blReadTime(wc),
    type:'blog_post',
  };
}

// TipTap editor integration (rewrite 2026-04-19) ─────────────────────────
window._blEditor = null;

function _blInitTipTap(initialContent) {
  var mount = document.getElementById('bl-content');
  if (!mount) return;

  // Destroy any prior editor (re-render / fresh article)
  if (window._blEditor) {
    try { window._blEditor.destroy(); } catch (e) {}
    window._blEditor = null;
  }

  // Graceful fallback + retry — if TipTap is still loading (ESM from esm.sh),
  // wait for the 'tiptap-ready' event once and re-attempt. If it never fires
  // or fails, fall back to a plain contenteditable so the editor still works.
  if (!window.tiptap || !window.tiptap.Editor) {
    mount.setAttribute('contenteditable', 'true');
    mount.oninput = function(){ _blMarkDirty(); _blUpdateWordCount(); };
    mount.innerHTML = initialContent || '<p>Start writing your article…</p>';
    var _blTiptapWaiter = function () {
      if (_bl.view !== 'editor') return;
      window.removeEventListener('tiptap-ready', _blTiptapWaiter);
      // Capture the current (possibly edited) content before TipTap takes over
      var cur = mount.innerHTML;
      mount.removeAttribute('contenteditable');
      mount.oninput = null;
      mount.innerHTML = '';
      _blInitTipTap(cur);
    };
    window.addEventListener('tiptap-ready', _blTiptapWaiter, { once: true });
    return;
  }

  var T = window.tiptap;
  var exts = [];
  if (T.StarterKit) exts.push(T.StarterKit);

  // Custom Image extension: adds `width` (px or %) + `align` (left|center|right)
  // attributes for Word-like resize + text wrap. inline:true makes the image
  // live inside paragraphs so float:left/float:right causes adjacent text to
  // genuinely wrap around it (block images don't wrap surrounding prose).
  if (T.Image) {
    var ResizableImage = T.Image.extend({
      name: 'image',
      inline: true,          // 2026-04-19: inline so text wrapping works
      group: 'inline',
      draggable: true,       // enable drag-to-reposition inside the doc
      addAttributes: function () {
        var parent = (this.parent && this.parent()) || {};
        return Object.assign({}, parent, {
          width: {
            default: null,
            parseHTML: function (el) {
              return el.getAttribute('width') || (el.style && el.style.width) || null;
            },
            renderHTML: function (attrs) {
              if (!attrs.width) return {};
              var w = String(attrs.width).match(/\d/) ? String(attrs.width) : null;
              if (!w) return {};
              if (!/%|px/.test(w)) w = w + 'px';
              return { style: 'width:' + w };
            },
          },
          align: {
            default: 'center',
            parseHTML: function (el) { return el.getAttribute('data-align') || 'center'; },
            renderHTML: function (attrs) {
              return { 'data-align': attrs.align || 'center' };
            },
          },
        });
      },
    });
    exts.push(ResizableImage.configure({ inline: true, HTMLAttributes: { class: 'bl-inline-image' } }));
  }

  if (T.Link)        exts.push(T.Link.configure({ openOnClick: false, HTMLAttributes: { class: 'bl-link', target: '_blank', rel: 'noopener noreferrer' } }));
  if (T.Placeholder) exts.push(T.Placeholder.configure({ placeholder: 'Start writing your article…' }));
  if (T.CharacterCount) exts.push(T.CharacterCount);
  if (T.Underline)   exts.push(T.Underline);

  window._blEditor = new T.Editor({
    element: mount,
    extensions: exts,
    content: initialContent || '<p></p>',
    onUpdate: function (ctx) {
      _blMarkDirty();
      _blUpdateWordCount();
      _blUpdateToolbarState();
    },
    onSelectionUpdate: function () {
      _blUpdateToolbarState();
      _blUpdateImageMenu();
    },
  });
  _blUpdateWordCount();
  _blUpdateToolbarState();
  _blInstallImageInteractions(mount);
}

// ── Image interactions: drag-resize + floating align/wrap menu ───────
// Gives the blog editor Word-like image behaviour without a third-party
// extension. Drag any corner handle to resize (persists via `width` attr).
// A floating mini-toolbar appears above the selected image with align
// buttons (left / center / right / wide) and a remove button.
function _blInstallImageInteractions(root) {
  if (!root || root.__blImgWired) return;
  root.__blImgWired = true;

  // Floating menu singleton (created lazily, reused across selections)
  var menu = document.createElement('div');
  menu.id = 'bl-img-menu';
  menu.style.cssText = 'position:absolute;display:none;z-index:50;background:var(--s1,#171A21);border:1px solid var(--bd,rgba(255,255,255,.13));border-radius:8px;padding:4px;box-shadow:0 8px 24px rgba(0,0,0,.4);gap:2px;align-items:center';
  menu.innerHTML = ''
    + _blImgMenuBtn('left',   '&#11013;', 'Wrap left — text flows right of image')
    + _blImgMenuBtn('center', '&#9776;',  'Center (no wrap)')
    + _blImgMenuBtn('right',  '&#10145;', 'Wrap right — text flows left of image')
    + '<span style="width:1px;height:16px;background:var(--bd,rgba(255,255,255,.13));margin:0 4px"></span>'
    + '<button data-action="25"  title="25% width"  style="' + _blImgMenuStyle() + '">25%</button>'
    + '<button data-action="50"  title="50% width"  style="' + _blImgMenuStyle() + '">50%</button>'
    + '<button data-action="75"  title="75% width"  style="' + _blImgMenuStyle() + '">75%</button>'
    + '<button data-action="100" title="100% width" style="' + _blImgMenuStyle() + '">100%</button>'
    + '<span style="width:1px;height:16px;background:var(--bd,rgba(255,255,255,.13));margin:0 4px"></span>'
    + '<button data-action="remove" title="Remove image" style="' + _blImgMenuStyle('danger') + '">&#128465;</button>';
  document.body.appendChild(menu);

  menu.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-action]'); if (!btn) return;
    var a = btn.getAttribute('data-action');
    if (!window._blEditor) return;
    var chain = window._blEditor.chain().focus();
    if (a === 'left' || a === 'right' || a === 'center') {
      chain.updateAttributes('image', { align: a }).run();
    } else if (a === 'remove') {
      chain.deleteSelection().run();
    } else if (/^\d+$/.test(a)) {
      chain.updateAttributes('image', { width: a + '%' }).run();
    }
    _blMarkDirty();
    _blUpdateImageMenu();
  });

  // Four corner drag handles — like MS Word. Each resizes the width;
  // NW/SW drag leftward grows the image; NE/SE drag rightward grows it.
  // (Height scales automatically because image has height:auto.)
  var handles = {};
  ['nw','ne','sw','se'].forEach(function (pos) {
    var h = document.createElement('div');
    h.id = 'bl-img-handle-' + pos;
    h.className = 'bl-img-handle';
    h.dataset.pos = pos;
    var cur = (pos === 'nw' || pos === 'se') ? 'nwse-resize' : 'nesw-resize';
    h.style.cssText = 'position:absolute;width:12px;height:12px;background:var(--p,#6C5CE7);border:2px solid #fff;border-radius:3px;cursor:' + cur + ';z-index:49;display:none;box-shadow:0 2px 6px rgba(0,0,0,.4)';
    document.body.appendChild(h);
    handles[pos] = h;

    h.addEventListener('mousedown', function (ev) {
      if (!window._blEditor) return;
      var imgEl = _blCurrentSelectedImg(root);
      if (!imgEl) return;
      ev.preventDefault();
      ev.stopPropagation();
      var startX = ev.clientX;
      var startW = imgEl.getBoundingClientRect().width;
      var containerW = root.getBoundingClientRect().width;
      // Direction: NE/SE add on rightward drag, NW/SW add on leftward drag
      var dir = (pos === 'ne' || pos === 'se') ? 1 : -1;
      var onMove = function (e) {
        var dx = e.clientX - startX;
        var newW = Math.max(80, startW + dx * dir);
        var pct = Math.min(100, Math.max(10, Math.round((newW / containerW) * 100)));
        imgEl.style.width = pct + '%';
        _blPositionImageMenu(imgEl);
      };
      var onUp = function () {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        var pct = Math.min(100, Math.max(10, Math.round((imgEl.getBoundingClientRect().width / containerW) * 100)));
        window._blEditor.chain().focus().updateAttributes('image', { width: pct + '%' }).run();
        _blMarkDirty();
      };
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });
  });

  // Hide menu + handles when clicking outside the editor
  document.addEventListener('click', function (e) {
    if (menu.contains(e.target)) return;
    var onHandle = false;
    Object.keys(handles).forEach(function (k) { if (handles[k].contains(e.target)) onHandle = true; });
    if (onHandle) return;
    if (!root.contains(e.target)) _blHideImageMenu();
  });
  window.addEventListener('scroll', _blUpdateImageMenu, true);
  window.addEventListener('resize', _blUpdateImageMenu);
}

function _blImgMenuBtn(action, icon, title) {
  return '<button data-action="' + action + '" title="' + title + '" style="' + _blImgMenuStyle() + '">' + icon + '</button>';
}
function _blImgMenuStyle(kind) {
  return 'background:transparent;border:none;color:' + (kind === 'danger' ? 'var(--rd,#F87171)' : 'var(--t1,#E8EDF5)') + ';cursor:pointer;padding:5px 8px;border-radius:5px;font-size:13px;font-family:inherit;min-width:28px;display:inline-flex;align-items:center;justify-content:center;line-height:1';
}

function _blCurrentSelectedImg(root) {
  // TipTap marks node selection on the wrapper — find its <img>
  var selNode = root.querySelector('.ProseMirror-selectednode');
  if (!selNode) return null;
  return selNode.tagName === 'IMG' ? selNode : selNode.querySelector('img');
}

function _blUpdateImageMenu() {
  var menu = document.getElementById('bl-img-menu');
  if (!menu || !window._blEditor) return;
  var mount = document.getElementById('bl-content');
  if (!mount) return;
  if (!window._blEditor.isActive('image')) { _blHideImageMenu(); return; }
  var img = _blCurrentSelectedImg(mount);
  if (!img) { _blHideImageMenu(); return; }
  _blPositionImageMenu(img);
  menu.style.display = 'flex';
  ['nw','ne','sw','se'].forEach(function (pos) {
    var h = document.getElementById('bl-img-handle-' + pos);
    if (h) h.style.display = 'block';
  });
}
function _blPositionImageMenu(img) {
  var menu = document.getElementById('bl-img-menu');
  if (!menu || !img) return;
  var r = img.getBoundingClientRect();
  var menuTop = r.top + window.scrollY - 44;
  if (menuTop < 8) menuTop = r.bottom + window.scrollY + 8; // flip below if no headroom
  menu.style.left = (r.left + window.scrollX) + 'px';
  menu.style.top  = menuTop + 'px';
  // Position the 4 corner handles at the image corners
  var map = {
    nw: { left: r.left  + window.scrollX - 6, top: r.top    + window.scrollY - 6 },
    ne: { left: r.right + window.scrollX - 6, top: r.top    + window.scrollY - 6 },
    sw: { left: r.left  + window.scrollX - 6, top: r.bottom + window.scrollY - 6 },
    se: { left: r.right + window.scrollX - 6, top: r.bottom + window.scrollY - 6 },
  };
  Object.keys(map).forEach(function (pos) {
    var h = document.getElementById('bl-img-handle-' + pos);
    if (!h) return;
    h.style.left = map[pos].left + 'px';
    h.style.top  = map[pos].top  + 'px';
  });
}
function _blHideImageMenu() {
  var menu = document.getElementById('bl-img-menu');
  if (menu) menu.style.display = 'none';
  ['nw','ne','sw','se'].forEach(function (pos) {
    var h = document.getElementById('bl-img-handle-' + pos);
    if (h) h.style.display = 'none';
  });
}

// Highlight active formatting on the toolbar buttons
function _blUpdateToolbarState() {
  if (!window._blEditor) return;
  var ed = window._blEditor;
  var map = {
    'bold': ['bold'], 'italic': ['italic'], 'underline': ['underline'], 'strike': ['strike'], 'code': ['code'],
    'heading-1': ['heading', { level: 1 }], 'heading-2': ['heading', { level: 2 }], 'heading-3': ['heading', { level: 3 }],
    'bulletList': ['bulletList'], 'orderedList': ['orderedList'],
    'blockquote': ['blockquote'], 'codeBlock': ['codeBlock'],
  };
  document.querySelectorAll('#bl-toolbar button[data-cmd]').forEach(function (btn) {
    var key = btn.getAttribute('data-cmd');
    var args = map[key];
    if (!args) return;
    try {
      var active = ed.isActive.apply(ed, args);
      btn.classList.toggle('is-active', !!active);
    } catch (e) { /* TipTap not fully ready */ }
  });
}

// Unified command dispatcher — runs a chain method on the editor
window._blCmd = function (cmd, params) {
  if (!window._blEditor || !window._blEditor.chain) return;
  try {
    var c = window._blEditor.chain().focus();
    if (typeof c[cmd] !== 'function') { console.warn('[Blog] Unknown TipTap command', cmd); return; }
    (params !== undefined ? c[cmd](params) : c[cmd]()).run();
  } catch (e) { console.error('[Blog] command failed', cmd, e); }
};

// Image insert — routes through the unified media picker
window._blInsertImage = function () {
  if (typeof window.openMediaPicker !== 'function') {
    var url = prompt('Paste an image URL:');
    if (url && window._blEditor) window._blEditor.chain().focus().setImage({ src: url }).run();
    return;
  }
  window.openMediaPicker({ type: 'image', multiple: false, context: 'blog' }, function (file) {
    if (!file || !file.url || !window._blEditor) return;
    window._blEditor.chain().focus()
      .setImage({
        src: file.url,
        alt: file.filename || 'Article image',
        title: file.description || '',
      })
      .run();
    _blMarkDirty();
  });
};

// Link insert — branded luPrompt dialog; preselects the editor's current selection
window._blInsertLink = async function () {
  if (!window._blEditor) return;
  var current = window._blEditor.getAttributes('link').href || '';
  var url = (typeof window.luPrompt === 'function')
    ? await window.luPrompt('Insert link', current, 'https://example.com')
    : prompt('Enter URL:', current);
  if (url === null) return;
  if (url === '') { window._blEditor.chain().focus().unsetLink().run(); return; }
  if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
  window._blEditor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
  _blMarkDirty();
};

// Safety cleanup when leaving the editor view
window._blDestroyEditor = function () {
  if (window._blEditor) { try { window._blEditor.destroy(); } catch (e) {} window._blEditor = null; }
  var m = document.getElementById('bl-img-menu'); if (m) m.remove();
  ['nw','ne','sw','se'].forEach(function (pos) {
    var h = document.getElementById('bl-img-handle-' + pos); if (h) h.remove();
  });
  window.removeEventListener('scroll', _blUpdateImageMenu, true);
  window.removeEventListener('resize', _blUpdateImageMenu);
};

// Legacy aliases — any stale onclick="_blExecCmd(...)" in cached pages keeps working
window._blExecCmd = function (cmd /*, val */) {
  var map = { bold:'toggleBold', italic:'toggleItalic', underline:'toggleUnderline' };
  if (map[cmd]) return window._blCmd(map[cmd]);
};
window._blInsertHeading = function (tag) {
  var lvl = (tag === 'h1') ? 1 : (tag === 'h3' ? 3 : 2);
  window._blCmd('toggleHeading', { level: lvl });
};
