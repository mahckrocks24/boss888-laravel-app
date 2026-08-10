/* LevelUp Growth — Bella assistant widget (enhanced).
 * Extracted from resources/views/admin/app.blade.php on 2026-07-29.
 */
  // ── Bella Session 2: enhanced widget JS ──────────────────────────────────
  // Platform context injection, vision/image upload, generate menu, artifacts.
  // All existing bellaChat/bellaSend/bellaAddMessage functions are preserved
  // above (lines ~1607-1825). This block adds the new features only.

  // Platform context — fetched once when panel opens, injected into every message
  let _bellaContext = '';
  async function _bellaFetchContext() {
    try {
      const r = await fetch('/api/admin/stats', {
        headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
      });
      const s = await r.json();
      _bellaContext = `Current platform state: ${s.total_workspaces??s.workspaces??'?'} workspaces, `
        + `${s.total_users??s.users??'?'} users, ${s.tasks_today??0} tasks today, `
        + `MRR $${s.total_revenue??0}, active subs: ${s.active_subscriptions??0}.`;
    } catch(e) { _bellaContext = ''; }
  }

  // Patch toggleBella to fetch context on open
  const _origToggleBella = toggleBella;
  toggleBella = function() {
    _origToggleBella();
    if (bellaOpen && !_bellaContext) _bellaFetchContext();
    // Close gen menu + artifacts on toggle
    document.getElementById('bella-gen-menu').style.display = 'none';
  };

  // Patch bellaChat to inject platform context
  const _origBellaChat = bellaChat;
  bellaChat = async function(message) {
    // If there's an attached image, route through vision instead
    if (window._bellaImageBase64) {
      const img64 = window._bellaImageBase64;
      const imgName = window._bellaImageName || 'image';
      bellaClearImage();
      bellaAddMessage('user', '📎 ' + imgName + '\n' + message);
      window.bellaHistory.push({ role: 'user', content: message });
      bellaShowTyping();
      const input = document.getElementById('bella-input');
      const sendBtn = document.getElementById('bella-send-btn');
      input.disabled = true;
      sendBtn.disabled = true;
      try {
        const r = await fetch('/api/admin/bella/vision', {
          method: 'POST',
          headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
          body: JSON.stringify({ prompt: message, image: img64 })
        });
        bellaHideTyping();
        const d = await r.json();
        const reply = d.analysis || d.error || 'No analysis returned.';
        bellaAddMessage('bella', reply);
        window.bellaHistory.push({ role: 'assistant', content: reply });
      } catch(e) {
        bellaHideTyping();
        bellaAddMessage('error', 'Vision error: ' + e.message);
      }
      input.disabled = false;
      sendBtn.disabled = false;
      input.focus();
      return;
    }

    // Inject platform context silently via context_request field (not visible in chat)
    return _origBellaChat(message);
  };

  // Image upload handling
  window._bellaImageBase64 = null;
  window._bellaImageName = null;

  function bellaFileSelected(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) {
      alert('Image must be under 10MB');
      input.value = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = function(e) {
      window._bellaImageBase64 = e.target.result.replace(/^data:image\/[a-z]+;base64,/i, '');
      window._bellaImageName = file.name;
      document.getElementById('bella-preview-thumb').src = e.target.result;
      document.getElementById('bella-preview-name').textContent = file.name + ' (' + (file.size/1024).toFixed(0) + 'KB)';
      document.getElementById('bella-image-preview').style.display = 'block';
    };
    reader.readAsDataURL(file);
    input.value = '';
  }

  function bellaClearImage() {
    window._bellaImageBase64 = null;
    window._bellaImageName = null;
    document.getElementById('bella-image-preview').style.display = 'none';
  }

  // Generate menu
  function bellaToggleGenMenu() {
    const m = document.getElementById('bella-gen-menu');
    m.style.display = m.style.display === 'none' ? 'block' : 'none';
  }
  function bellaGenerate(type) {
    document.getElementById('bella-gen-menu').style.display = 'none';
    const input = document.getElementById('bella-input');
    input.value = 'Generate a ' + type.toLowerCase() + ' about: ';
    input.focus();
    // Place cursor at end
    input.setSelectionRange(input.value.length, input.value.length);
  }

  // Artifacts panel
  function bellaToggleArtifacts() {
    const ap = document.getElementById('bella-artifacts-panel');
    const ca = document.getElementById('bella-chat-area');
    if (ap.style.display === 'none') {
      ap.style.display = 'flex';
      ap.style.flexDirection = 'column';
      ca.style.display = 'none';
      bellaLoadArtifacts();
    } else {
      ap.style.display = 'none';
      ca.style.display = 'flex';
    }
  }

  async function bellaLoadArtifacts() {
    const list = document.getElementById('bella-artifacts-list');
    list.innerHTML = '<span style="color:var(--muted)">Loading...</span>';
    try {
      const r = await fetch('/api/admin/bella/artifacts?per_page=30', {
        headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
      });
      const d = await r.json();
      const items = d.data || [];
      if (items.length === 0) {
        list.innerHTML = '<span style="color:var(--muted)">No artifacts yet.</span>';
        return;
      }
      list.innerHTML = items.map(a => {
        const url = a.url || '#';
        const name = a.title || a.prompt || 'Untitled';
        const type = a.type || '?';
        const date = a.created_at ? new Date(a.created_at).toLocaleDateString() : '';
        return '<div style="padding:8px 0;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px">'
          + '<span style="font-size:16px">' + (type==='image'?'&#127912;':type==='video'?'&#127909;':'&#128196;') + '</span>'
          + '<div style="flex:1;min-width:0"><div style="color:var(--text);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + name.replace(/</g,'&lt;').slice(0,60) + '</div>'
          + '<div style="font-size:10px;color:var(--muted)">' + type + ' &middot; ' + date + '</div></div>'
          + (url!=='#'?'<a href="'+url+'" target="_blank" style="color:var(--p);font-size:11px;flex-shrink:0">Open &#8599;</a>':'')
          + '</div>';
      }).join('');
    } catch(e) {
      list.innerHTML = '<span style="color:var(--rd)">Failed to load artifacts.</span>';
    }
  }

  // Close gen menu on outside click
  document.addEventListener('click', function(e) {
    const menu = document.getElementById('bella-gen-menu');
    const btn = document.getElementById('bella-gen-btn');
    if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
      menu.style.display = 'none';
    }
  });

  // Bella Session 6: video generation poll
  function bellaStartVideoPoll(assetId) {
    let attempts = 0;
    const maxAttempts = 24; // 2 minutes max (24 × 5s)
    const statusEl = document.getElementById('bella-video-status-' + assetId);
    const interval = setInterval(async () => {
      attempts++;
      if (attempts >= maxAttempts) {
        clearInterval(interval);
        if (statusEl) statusEl.textContent = 'Timed out — check Artifacts panel.';
        return;
      }
      try {
        const r = await fetch('/api/admin/bella/video-status/' + assetId, {
          headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' }
        });
        const d = await r.json();
        if (d.status === 'completed' && d.url) {
          clearInterval(interval);
          if (statusEl) {
            statusEl.parentElement.parentElement.parentElement.innerHTML =
              '<video src="' + d.url + '" controls style="width:100%;border-radius:6px;max-height:240px" preload="metadata"></video>' +
              '<div style="display:flex;gap:8px;margin-top:8px">' +
              '<a href="' + d.url + '" target="_blank" download style="display:inline-flex;align-items:center;gap:4px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text);text-decoration:none;font-weight:500">&#11015; Download</a>' +
              '<span style="font-size:10px;color:var(--muted);align-self:center">Asset #' + assetId + '</span></div>';
          }
        } else if (d.status === 'failed' || d.status === 'timed_out') {
          clearInterval(interval);
          if (statusEl) statusEl.textContent = 'Generation failed (' + d.status + ')';
        } else if (statusEl) {
          statusEl.textContent = '\u23F3 Generating... (' + (attempts * 5) + 's)';
        }
      } catch(e) { /* ignore poll errors */ }
    }, 5000);
  }
  
    // ── Admin Email Templates helpers ─────────────────────
    function _admEmailFilter(which){
      ['all','sys','user'].forEach(function(k){
        var b = document.getElementById('ema-f-' + k);
        if (!b) return;
        if (k === which){ b.style.background = 'var(--p)'; b.style.color = '#fff'; b.style.borderColor = 'var(--p)'; }
        else { b.style.background = 'transparent'; b.style.color = 'var(--muted)'; b.style.borderColor = 'var(--border)'; }
      });
      _admEmailRender(which);
    }
    function _admEmailRender(which){
      var all = window._admEmailTpls || [];
      var rows = which === 'sys'  ? all.filter(function(t){ return t.is_system == 1; })
               : which === 'user' ? all.filter(function(t){ return t.is_system != 1; })
               : all;
      var grid = document.getElementById('ema-grid');
      if (!grid) return;
      if (!rows.length) { grid.innerHTML = '<div class="loading" style="padding:60px;text-align:center">No templates in this view.</div>'; return; }
      grid.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px">' +
        rows.map(function(t){
          var sys = t.is_system == 1;
          var safeName = (t.name || '').replace(/'/g, '');
          var thumb = t.thumbnail_url
            ? '<img src="' + t.thumbnail_url + '" alt="" style="width:100%;height:100%;object-fit:cover;object-position:top" loading="lazy"/>'
            : '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:11px;text-transform:uppercase">' + (t.category||'preview') + '</div>';
          return '<div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;background:var(--s2)">' +
                   '<div style="aspect-ratio:3/4;background:var(--s3);overflow:hidden;position:relative">' +
                     thumb +
                     (sys ? '<div style="position:absolute;top:8px;left:8px;background:rgba(108,92,231,.85);color:#fff;font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:.06em">SYSTEM</div>' : '') +
                   '</div>' +
                   '<div style="padding:10px 12px">' +
                     '<div style="font-weight:600;font-size:13px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + (t.name||'') + '">' + (t.name || 'Untitled') + '</div>' +
                     '<div style="font-size:10px;color:var(--muted);margin-bottom:10px;text-transform:capitalize">' + (t.category || 'general') + ' · #' + t.id + '</div>' +
                     '<div style="display:flex;gap:4px;flex-wrap:wrap">' +
                       '<button onclick="_admEmailEdit(' + t.id + ')" style="flex:1;background:transparent;border:1px solid var(--border);color:var(--text);padding:5px 8px;font-size:11px;border-radius:4px;cursor:pointer">Edit</button>' +
                       '<button onclick="_admEmailToggleSys(' + t.id + ',' + (sys ? 'false' : 'true') + ')" style="background:transparent;border:1px solid var(--border);color:var(--muted);padding:5px 8px;font-size:11px;border-radius:4px;cursor:pointer">' + (sys ? 'Unmark' : 'Mark sys') + '</button>' +
                       '<button onclick="_admEmailRegen(' + t.id + ')" title="Regenerate thumbnail" style="background:transparent;border:1px solid var(--border);color:var(--muted);padding:5px 8px;font-size:11px;border-radius:4px;cursor:pointer">↻</button>' +
                       '<button onclick="_admEmailDelete(' + t.id + ',\'' + safeName + '\')" style="background:transparent;border:1px solid var(--border);color:#EF4444;padding:5px 8px;font-size:11px;border-radius:4px;cursor:pointer">×</button>' +
                     '</div>' +
                   '</div>' +
                 '</div>';
        }).join('') +
        '</div>';
    }
    async function _admEmailEdit(id){
      var t = (window._admEmailTpls || []).find(function(x){ return x.id == id; });
      if (!t) return;
      var name     = prompt('Name', t.name || '');                                  if (name === null) return;
      var category = prompt('Category', t.category || 'general');                   if (category === null) return;
      var subject  = prompt('Subject', t.subject || '');                            if (subject === null) return;
      var brand    = prompt('Brand color (hex)', t.brand_color || '#5B5BD6');       if (brand === null) return;
      var r = await api('/email-templates/' + id, 'PUT', { name: name, category: category, subject: subject, brand_color: brand });
      if (r && r.success) { alert('Updated'); pages.emailTemplatesAdmin(); }
      else alert('Update failed: ' + (r && r.error || 'unknown'));
    }
    async function _admEmailToggleSys(id, makeSys){
      var r = await api('/email-templates/' + id, 'PUT', { is_system: makeSys ? 1 : 0 });
      if (r && r.success) pages.emailTemplatesAdmin();
      else alert('Toggle failed');
    }
    async function _admEmailRegen(id){
      var r = await api('/email-templates/' + id + '/regen-thumbnail', 'POST');
      if (r && r.thumbnail_url) { alert('Thumbnail regenerated'); pages.emailTemplatesAdmin(); }
      else alert('Regen failed: ' + (r && r.error || 'unknown'));
    }
    async function _admEmailDelete(id, name){
      if (!confirm('Hard-delete template "' + name + '" (id ' + id + ')? This removes the row, its blocks, and its thumbnail file.')) return;
      var r = await api('/email-templates/' + id, 'DELETE');
      if (r && r.deleted) pages.emailTemplatesAdmin();
      else alert('Delete failed: ' + (r && r.error || 'unknown'));
    }
    async function _admEmailNew(){
      var name     = prompt('Template name', 'New System Template'); if (!name) return;
      var category = prompt('Category', 'general'); if (!category) return;
      var source   = prompt('Source: blank or html', 'blank');       if (!source) return;
      var html     = '';
      if (source === 'html') {
        html = prompt('Paste raw HTML:', '');
        if (!html) return;
      }
      var r = await api('/email-templates', 'POST', { name: name, category: category, source: source, html_content: html });
      if (r && r.success) { alert('Created (id ' + r.template_id + ')'); pages.emailTemplatesAdmin(); }
      else alert('Create failed: ' + (r && r.error || 'unknown'));
    }

