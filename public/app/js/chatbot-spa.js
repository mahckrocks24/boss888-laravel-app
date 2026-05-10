/**
 * CHATBOT888 — Admin SPA
 * 4 tabs: Setup / Knowledge / Conversations / Embed
 *
 * All endpoints already exist server-side (AdminChatbotController):
 *   GET  /api/chatbot/settings                  -> getSettings
 *   PUT  /api/chatbot/settings                  -> updateSettings
 *   GET  /api/chatbot/knowledge                 -> listKnowledge
 *   POST /api/chatbot/knowledge/text            -> patchKnowledgeText
 *   DELETE /api/chatbot/knowledge/{id}          -> deleteKnowledge
 *   GET  /api/chatbot/conversations             -> listConversations
 *   GET  /api/chatbot/conversations/{id}        -> getConversation
 *   GET  /api/chatbot/widget-tokens             -> listWidgetTokens
 *   POST /api/chatbot/widget-tokens             -> mintWidgetToken
 *
 * Plan-gated by FeatureGateService::canAccessChatbot (Pro+ / Agency).
 * Lower-tier workspaces get a 403 PLAN_REQUIRED — SPA shows an Upgrade
 * card instead of the tabs.
 */
(function () {
  'use strict';

  function _h(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function _token() { return localStorage.getItem('lu_token') || ''; }
  function _api(method, path, body) {
    var opts = {
      method: method,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + _token() },
    };
    if (body) opts.body = JSON.stringify(body);
    return fetch('/api/chatbot' + path, opts).then(function(r){
      return r.json().catch(function(){ return { success:false, error:'Bad JSON' }; }).then(function(j){
        return { status: r.status, data: j };
      });
    });
  }

  var state = {
    activeTab: 'setup',
    settings: null,
    knowledge: [],
    conversations: [],
    selectedConv: null,
    widgetToken: null,
    planLocked: false,
  };

  // ── Entry — called by core.js nav('chatbot') ─────────────────────
  window.chatbotLoad = function (root) {
    if (!root) return;
    root.innerHTML =
      '<div style="padding:24px 28px;display:flex;flex-direction:column;gap:18px;height:100%;overflow-y:auto">' +
        '<div style="display:flex;align-items:center;gap:12px">' +
          '<div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#6C5CE7,#A855F7);display:flex;align-items:center;justify-content:center;font-size:22px">💬</div>' +
          '<div><div style="font-family:var(--fh,inherit);font-size:20px;font-weight:700;color:var(--t1)">Chatbot</div>' +
          '<div style="font-size:12px;color:var(--t3)">AI front desk for every page of your site</div></div>' +
        '</div>' +

        '<div id="cb-tabs" style="display:flex;gap:4px;border-bottom:1px solid var(--bd);padding-bottom:0">' +
          _tabBtn('setup', 'Setup') +
          _tabBtn('knowledge', 'Knowledge') +
          _tabBtn('conversations', 'Conversations') +
          _tabBtn('embed', 'Embed') +
        '</div>' +

        '<div id="cb-body" style="flex:1;min-height:0">' +
          '<div style="padding:40px;text-align:center;color:var(--t3);font-size:13px">Loading…</div>' +
        '</div>' +
      '</div>';

    document.querySelectorAll('#cb-tabs button').forEach(function(b){
      b.onclick = function(){ _switchTab(b.getAttribute('data-tab')); };
    });

    _loadSettingsThenRoute();
  };

  function _tabBtn(key, label) {
    var active = state.activeTab === key;
    return '<button data-tab="' + key + '" style="' +
      'background:transparent;border:none;border-bottom:2px solid ' + (active ? '#6C5CE7' : 'transparent') + ';' +
      'color:' + (active ? '#fff' : 'var(--t3)') + ';' +
      'padding:10px 16px;font-size:13px;font-weight:' + (active ? '600' : '500') + ';' +
      'cursor:pointer;font-family:inherit;transition:all 0.15s">' +
      _h(label) + '</button>';
  }

  function _switchTab(key) {
    state.activeTab = key;
    document.querySelectorAll('#cb-tabs button').forEach(function(b){
      var active = b.getAttribute('data-tab') === key;
      b.style.borderBottom = '2px solid ' + (active ? '#6C5CE7' : 'transparent');
      b.style.color = active ? '#fff' : 'var(--t3)';
      b.style.fontWeight = active ? '600' : '500';
    });
    if (state.planLocked) return _renderUpgrade();
    if (key === 'setup') _renderSetup();
    if (key === 'knowledge') _renderKnowledge();
    if (key === 'conversations') _renderConversations();
    if (key === 'embed') _renderEmbed();
  }

  // ── Initial load — getSettings doubles as plan check ─────────────
  function _loadSettingsThenRoute() {
    _api('GET', '/settings').then(function(r){
      if (r.status === 403 && r.data && r.data.code === 'PLAN_REQUIRED') {
        state.planLocked = true;
        return _renderUpgrade();
      }
      state.settings = (r.data && r.data.data) || null;
      _renderSetup();
    });
  }

  function _renderUpgrade() {
    document.getElementById('cb-body').innerHTML =
      '<div style="padding:60px 24px;text-align:center;background:rgba(108,92,231,0.05);border:1px solid rgba(108,92,231,0.2);border-radius:14px">' +
        '<div style="font-size:42px;margin-bottom:12px">🔒</div>' +
        '<div style="font-size:18px;font-weight:600;color:var(--t1);margin-bottom:6px">Chatbot is a Pro feature</div>' +
        '<div style="font-size:13px;color:var(--t3);margin-bottom:20px;max-width:420px;margin-left:auto;margin-right:auto">' +
          'Add a smart AI front desk to every page of your website. Capture leads, book appointments, answer FAQs 24/7. ' +
          'Available on the Pro ($199/mo) and Agency ($399/mo) plans.' +
        '</div>' +
        '<button onclick="nav(\'billing\')" style="background:linear-gradient(135deg,#6C5CE7,#A855F7);color:#fff;border:none;border-radius:10px;padding:12px 24px;font-size:13px;font-weight:600;cursor:pointer">Upgrade to Pro</button>' +
      '</div>';
  }

  // ── TAB 1 — Setup ────────────────────────────────────────────────
  function _renderSetup() {
    var s = state.settings || {};
    document.getElementById('cb-body').innerHTML =
      '<div style="display:flex;flex-direction:column;gap:18px;max-width:680px">' +
        '<div style="background:#15151A;border:1px solid #2A2A33;border-radius:12px;padding:18px 20px">' +
          '<label style="display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer">' +
            '<div><div style="font-size:14px;font-weight:600;color:var(--t1)">Enabled</div>' +
            '<div style="font-size:12px;color:var(--t3);margin-top:2px">When off, the widget will not appear on any of your sites.</div></div>' +
            '<input type="checkbox" id="cb-enabled" ' + (s.enabled ? 'checked' : '') + ' style="width:36px;height:20px;cursor:pointer">' +
          '</label>' +
        '</div>' +

        _formRow('cb-greeting', 'Welcome message',
          '<input type="text" id="cb-greeting" value="' + _h(s.greeting || '') + '" maxlength="500" placeholder="Hi! How can I help you today?" style="width:100%;background:#0d0d0d;border:1px solid #2A2A33;border-radius:8px;padding:10px 12px;color:#fff;font-size:13px;font-family:inherit;outline:none">') +

        _formRow('cb-fallback-email', 'Fallback email — receives leads / escalations',
          '<input type="email" id="cb-fallback-email" value="' + _h(s.fallback_email || '') + '" maxlength="255" placeholder="hello@yourbusiness.com" style="width:100%;background:#0d0d0d;border:1px solid #2A2A33;border-radius:8px;padding:10px 12px;color:#fff;font-size:13px;font-family:inherit;outline:none">') +

        _formRow('cb-context', 'Business context (helps the AI answer accurately)',
          '<textarea id="cb-context" maxlength="8000" rows="6" placeholder="Tell the bot about your business — services, hours, policies, prices, anything customers commonly ask about." style="width:100%;background:#0d0d0d;border:1px solid #2A2A33;border-radius:8px;padding:10px 12px;color:#fff;font-size:13px;font-family:inherit;resize:vertical;outline:none">' + _h(s.business_context_text || '') + '</textarea>') +

        '<div style="display:flex;gap:18px;flex-wrap:wrap">' +
          _formRow('cb-color', 'Primary color',
            '<input type="color" id="cb-color" value="' + _h(s.primary_color || '#6C5CE7') + '" style="width:48px;height:36px;border:none;border-radius:8px;cursor:pointer;padding:2px;background:transparent">') +
          _formRow('cb-theme', 'Theme',
            '<select id="cb-theme" style="background:#0d0d0d;border:1px solid #2A2A33;border-radius:8px;padding:9px 12px;color:#fff;font-size:13px;font-family:inherit;outline:none">' +
              ['auto','light','dark'].map(function(t){ return '<option value="'+t+'"'+(s.theme===t?' selected':'')+'>'+t+'</option>'; }).join('') +
            '</select>') +
        '</div>' +

        '<div style="display:flex;gap:10px;justify-content:flex-end;padding-top:8px">' +
          '<button id="cb-save" style="background:linear-gradient(135deg,#6C5CE7,#A855F7);color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:13px;font-weight:600;cursor:pointer">Save Settings</button>' +
          '<span id="cb-save-status" style="font-size:12px;color:var(--t3);align-self:center"></span>' +
        '</div>' +
      '</div>';

    document.getElementById('cb-save').onclick = _saveSettings;
  }

  function _formRow(id, label, controlHtml) {
    return '<div>' +
      '<label for="' + id + '" style="display:block;font-size:12px;color:var(--t3);margin-bottom:6px">' + _h(label) + '</label>' +
      controlHtml +
    '</div>';
  }

  function _saveSettings() {
    var status = document.getElementById('cb-save-status');
    status.textContent = 'Saving…';
    status.style.color = 'var(--t3)';
    var body = {
      enabled:                document.getElementById('cb-enabled').checked,
      greeting:               document.getElementById('cb-greeting').value,
      fallback_email:         document.getElementById('cb-fallback-email').value || null,
      primary_color:          document.getElementById('cb-color').value,
      theme:                  document.getElementById('cb-theme').value,
      business_context_text:  document.getElementById('cb-context').value || null,
    };
    _api('PUT', '/settings', body).then(function(r){
      if (r.data && r.data.success) {
        status.textContent = '✓ Saved';
        status.style.color = '#10b981';
        state.settings = body;
        setTimeout(function(){ status.textContent = ''; }, 2500);
      } else {
        status.textContent = (r.data && r.data.message) || (r.data && r.data.error) || 'Save failed';
        status.style.color = '#F87171';
      }
    });
  }

  // ── TAB 2 — Knowledge ────────────────────────────────────────────
  function _renderKnowledge() {
    document.getElementById('cb-body').innerHTML =
      '<div style="display:flex;flex-direction:column;gap:18px;max-width:780px">' +
        '<div style="background:#15151A;border:1px solid #2A2A33;border-radius:12px;padding:18px 20px">' +
          '<div style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:6px">Add knowledge</div>' +
          '<div style="font-size:12px;color:var(--t3);margin-bottom:12px">Paste any FAQ, policy, or info the bot should know. Each entry is split into chunks for retrieval.</div>' +
          '<input type="text" id="cb-kb-title" placeholder="Title (e.g. Pricing FAQ)" style="width:100%;background:#0d0d0d;border:1px solid #2A2A33;border-radius:8px;padding:10px 12px;color:#fff;font-size:13px;font-family:inherit;outline:none;margin-bottom:8px">' +
          '<textarea id="cb-kb-text" rows="5" placeholder="Paste content here…" style="width:100%;background:#0d0d0d;border:1px solid #2A2A33;border-radius:8px;padding:10px 12px;color:#fff;font-size:13px;font-family:inherit;resize:vertical;outline:none"></textarea>' +
          '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px">' +
            '<button id="cb-kb-add" style="background:#6C5CE7;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:600;cursor:pointer">Add Entry</button>' +
            '<span id="cb-kb-status" style="font-size:11px;color:var(--t3);align-self:center"></span>' +
          '</div>' +
        '</div>' +
        '<div id="cb-kb-list" style="display:flex;flex-direction:column;gap:8px">' +
          '<div style="padding:24px;text-align:center;color:var(--t3);font-size:12px">Loading…</div>' +
        '</div>' +
      '</div>';

    document.getElementById('cb-kb-add').onclick = _addKnowledge;
    _refreshKnowledge();
  }

  function _refreshKnowledge() {
    _api('GET', '/knowledge').then(function(r){
      var list = (r.data && r.data.data) || [];
      state.knowledge = list;
      var el = document.getElementById('cb-kb-list');
      if (!el) return;
      if (!list.length) {
        el.innerHTML = '<div style="padding:24px;text-align:center;color:var(--t3);font-size:12px">No entries yet. Add one above.</div>';
        return;
      }
      el.innerHTML = list.map(function(k){
        return '<div style="background:#15151A;border:1px solid #2A2A33;border-radius:10px;padding:14px 16px;display:flex;align-items:flex-start;gap:12px">' +
          '<div style="flex:1;min-width:0">' +
            '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:4px">' + _h(k.title || '(untitled)') + '</div>' +
            '<div style="font-size:11px;color:var(--t3)">' + _h(k.source_type || 'text') + ' · ' + (k.chunk_count || 0) + ' chunks · added ' + _h((k.created_at || '').substring(0,10)) + '</div>' +
          '</div>' +
          '<button data-id="' + _h(k.id) + '" class="cb-kb-del" style="background:none;border:1px solid #444;color:#aaa;border-radius:6px;padding:5px 10px;font-size:11px;cursor:pointer">Delete</button>' +
        '</div>';
      }).join('');
      el.querySelectorAll('.cb-kb-del').forEach(function(b){
        b.onclick = function(){
          if (!confirm('Delete this knowledge entry?')) return;
          _api('DELETE', '/knowledge/' + b.getAttribute('data-id')).then(function(){ _refreshKnowledge(); });
        };
      });
    });
  }

  function _addKnowledge() {
    var title = document.getElementById('cb-kb-title').value.trim();
    var text  = document.getElementById('cb-kb-text').value.trim();
    var status = document.getElementById('cb-kb-status');
    if (!title || !text) { status.textContent = 'Title + text required'; status.style.color = '#F87171'; return; }
    status.textContent = 'Adding…'; status.style.color = 'var(--t3)';
    _api('POST', '/knowledge/text', { title: title, text: text }).then(function(r){
      if (r.data && r.data.success) {
        status.textContent = '✓ Added'; status.style.color = '#10b981';
        document.getElementById('cb-kb-title').value = '';
        document.getElementById('cb-kb-text').value = '';
        _refreshKnowledge();
        setTimeout(function(){ status.textContent = ''; }, 2000);
      } else {
        status.textContent = (r.data && r.data.message) || 'Failed'; status.style.color = '#F87171';
      }
    });
  }

  // ── TAB 3 — Conversations ────────────────────────────────────────
  function _renderConversations() {
    document.getElementById('cb-body').innerHTML =
      '<div style="display:grid;grid-template-columns:' + (window.innerWidth < 768 ? '1fr' : '300px 1fr') + ';gap:16px;height:100%;min-height:480px">' +
        '<div id="cb-conv-list" style="background:#15151A;border:1px solid #2A2A33;border-radius:12px;overflow-y:auto;padding:8px">' +
          '<div style="padding:24px;text-align:center;color:var(--t3);font-size:12px">Loading…</div>' +
        '</div>' +
        '<div id="cb-conv-detail" style="background:#15151A;border:1px solid #2A2A33;border-radius:12px;padding:18px;overflow-y:auto">' +
          '<div style="padding:24px;text-align:center;color:var(--t3);font-size:12px">Select a conversation</div>' +
        '</div>' +
      '</div>';
    _api('GET', '/conversations').then(function(r){
      var list = (r.data && r.data.data) || [];
      state.conversations = list;
      var el = document.getElementById('cb-conv-list');
      if (!list.length) {
        el.innerHTML = '<div style="padding:24px;text-align:center;color:var(--t3);font-size:12px">No conversations yet.</div>';
        return;
      }
      el.innerHTML = list.map(function(c){
        return '<div data-id="' + _h(c.id) + '" class="cb-conv-row" style="padding:12px;border-radius:8px;cursor:pointer;border-bottom:1px solid #1f1f25">' +
          '<div style="font-size:12px;color:var(--t1);font-weight:500">' + _h(c.page_url ? c.page_url.replace(/^https?:\/\//, '').slice(0,40) : 'Visitor') + '</div>' +
          '<div style="font-size:11px;color:var(--t3);margin-top:2px">' + (c.message_count || 0) + ' msgs · ' + _h((c.created_at || '').substring(0,16).replace('T',' ')) + '</div>' +
        '</div>';
      }).join('');
      el.querySelectorAll('.cb-conv-row').forEach(function(b){
        b.onclick = function(){ _loadConversation(b.getAttribute('data-id')); };
      });
    });
  }

  function _loadConversation(id) {
    _api('GET', '/conversations/' + id).then(function(r){
      var conv = (r.data && r.data.data) || {};
      var msgs = conv.messages || [];
      var detail = document.getElementById('cb-conv-detail');
      detail.innerHTML =
        '<div style="font-size:12px;color:var(--t3);margin-bottom:12px;border-bottom:1px solid #2A2A33;padding-bottom:10px">' +
          'Session #' + _h(id) + ' · ' + msgs.length + ' messages' +
        '</div>' +
        msgs.map(function(m){
          var isUser = m.role === 'user' || m.role === 'visitor';
          return '<div style="margin-bottom:10px;display:flex;' + (isUser ? 'justify-content:flex-end' : 'justify-content:flex-start') + '">' +
            '<div style="max-width:80%;padding:8px 12px;border-radius:10px;font-size:12px;line-height:1.45;' +
              (isUser ? 'background:#6C5CE7;color:#fff' : 'background:#1f1f25;color:#ddd') + '">' +
              _h(m.content || '') + '</div>' +
          '</div>';
        }).join('');
    });
  }

  // ── TAB 4 — Embed ────────────────────────────────────────────────
  function _renderEmbed() {
    document.getElementById('cb-body').innerHTML =
      '<div style="display:flex;flex-direction:column;gap:18px;max-width:780px">' +
        '<div style="background:#15151A;border:1px solid #2A2A33;border-radius:12px;padding:20px">' +
          '<div style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:6px">Embed snippet</div>' +
          '<div style="font-size:12px;color:var(--t3);margin-bottom:14px">Paste this single tag before <code>&lt;/body&gt;</code> on any external site. ' +
          'Sites built by Arthur can have the chatbot enabled automatically — toggle it on the Setup tab.</div>' +
          '<div id="cb-embed-snippet-box" style="background:#0a0a0f;border:1px solid #2A2A33;border-radius:8px;padding:14px;font-family:ui-monospace,monospace;font-size:12px;color:#a78bfa;word-break:break-all;line-height:1.5;min-height:60px">Loading workspace token…</div>' +
          '<div style="display:flex;gap:8px;margin-top:10px">' +
            '<button id="cb-embed-copy" style="background:#6C5CE7;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:600;cursor:pointer">📋 Copy snippet</button>' +
            '<span id="cb-embed-status" style="font-size:11px;color:var(--t3);align-self:center"></span>' +
          '</div>' +
        '</div>' +
        '<div style="background:rgba(108,92,231,0.05);border:1px solid rgba(108,92,231,0.2);border-radius:12px;padding:16px 20px">' +
          '<div style="font-size:12px;color:var(--t3);line-height:1.6">' +
            '<strong style="color:var(--t1)">How it works:</strong> the script fetches your chatbot configuration on page load (greeting, brand color, theme), ' +
            'mounts a chat bubble in the bottom-right corner, and routes messages to your AI front desk. Visitor data, leads, and bookings ' +
            'show up under the <strong>Conversations</strong> tab and the workspace CRM.' +
          '</div>' +
        '</div>' +
      '</div>';

    // Resolve workspace ID by reading the user's current workspace from localStorage,
    // or fall back to fetching widget tokens (which are workspace-scoped).
    var wsId = parseInt(localStorage.getItem('lu_workspace_id') || '0', 10) || null;
    _api('GET', '/widget-tokens').then(function(r){
      var tokens = (r.data && r.data.data) || [];
      // Prefer an active token already minted; otherwise mint a fresh one.
      var active = tokens.filter(function(t){ return t.status === 'active'; })[0];
      if (active) {
        if (!wsId && active.workspace_id) wsId = active.workspace_id;
        _showEmbed(wsId);
      } else {
        _api('POST', '/widget-tokens', { label: 'Default site' }).then(function(m){
          if (m.data && m.data.success && m.data.data && m.data.data.workspace_id) {
            wsId = m.data.data.workspace_id;
          }
          _showEmbed(wsId);
        });
      }
    });
  }

  function _showEmbed(wsId) {
    var box = document.getElementById('cb-embed-snippet-box');
    if (!box) return;
    if (!wsId) {
      box.textContent = '⚠️ Could not resolve workspace. Save your settings on the Setup tab first.';
      box.style.color = '#F87171';
      return;
    }
    var origin = window.location.origin; // e.g. https://staging.levelupgrowth.io
    var snippet = '<script src="' + origin + '/chatbot.js?ws=' + wsId + '" async></​script>';
    box.textContent = snippet;
    box.style.color = '#a78bfa';
    document.getElementById('cb-embed-copy').onclick = function(){
      navigator.clipboard.writeText(snippet.replace('​', '')).then(function(){
        var st = document.getElementById('cb-embed-status');
        st.textContent = '✓ Copied'; st.style.color = '#10b981';
        setTimeout(function(){ st.textContent = ''; }, 2000);
      });
    };
  }
})();
