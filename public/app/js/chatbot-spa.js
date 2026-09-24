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
    // Carry this page's website scope on every call. The shell's scope bridge would otherwise
    // decide it, and since the picker was removed it has nothing to say.
    if (state.websiteId) {
      path += (path.indexOf('?') === -1 ? '?' : '&') + 'website_id=' + encodeURIComponent(state.websiteId);
    }
    return fetch('/api/chatbot' + path, opts).then(function(r){
      return r.json().catch(function(){ return { success:false, error:'Bad JSON' }; }).then(function(j){
        return { status: r.status, data: j };
      });
    });
  }

  var state = {
    activeTab: 'setup',
    // INC-0006 / 2026-09-10 — which website this page is editing. The shell no longer carries a
    // website selector, and the endpoints already accept an explicit website_id: getSettings and
    // listKnowledge resolve it through WebsiteScope, and knowledge/text binds the source to it.
    // null = the business-wide default row every site inherits until it has its own.
    websiteId: null,
    websites: [],
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
          '<div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,var(--p),var(--pu));display:flex;align-items:center;justify-content:center;color:#fff">' + (window.icon ? window.icon('message', 22) : '') + '</div>' +
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

    _loadWebsitesThenSettings();
  };

  /* The canonical list, from the shared context endpoint rather than a chatbot-only query, so this
     page and the rest of the app always agree on what the business owns. */
  function _loadWebsitesThenSettings() {
    fetch('/api/website-context', { headers: { Accept: 'application/json', Authorization: 'Bearer ' + _token() } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { state.websites = (j && j.success && j.websites) ? j.websites : []; })
      .catch(function () { state.websites = []; })
      .then(function () { _loadSettingsThenRoute(); });
  }

  /* The scope control. Rendered only when the business owns more than one website — one site is not
     a decision. "All sites" is the business-wide default row, which is what every site inherits
     until it is given its own, so it is a real choice rather than a null option. */
  function _scopeHtml(idSuffix) {
    if (!state.websites || state.websites.length < 2 || !window.LUC) { return ''; }
    var items = [{ value: '', label: 'All sites', hint: 'the default every site inherits' }].concat(
      state.websites.map(function (w) { return { value: String(w.id), label: w.name, hint: w.host || '' }; }));
    return '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--s1);' +
      'border:1px solid var(--bd2);border-radius:var(--rg,14px);padding:12px 14px">' +
      '<span style="font-size:12px;color:var(--t2);white-space:nowrap">Applies to</span>' +
      '<div style="flex:1;min-width:200px;max-width:340px">' +
        window.LUC.listboxHtml({ id: 'cb-scope-' + idSuffix, value: state.websiteId || '',
                                 items: items, label: 'Website this applies to' }) +
      '</div></div>';
  }

  function _wireScope(idSuffix, onPicked) {
    if (!window.LUC) { return; }
    var el = document.getElementById('cb-scope-' + idSuffix);
    if (!el) { return; }
    window.LUC.wire(el.parentNode.parentNode, { onChange: function (id, v) {
      state.websiteId = v ? parseInt(v, 10) : null;
      onPicked();
    }});
  }

  function _tabBtn(key, label) {
    var active = state.activeTab === key;
    return '<button data-tab="' + key + '" style="' +
      'background:transparent;border:none;border-bottom:2px solid ' + (active ? 'var(--p)' : 'transparent') + ';' +
      'color:' + (active ? 'var(--t1)' : 'var(--t3)') + ';' +
      'padding:10px 16px;font-size:13px;font-weight:' + (active ? '600' : '500') + ';' +
      'cursor:pointer;font-family:inherit;transition:all 0.15s">' +
      _h(label) + '</button>';
  }

  function _switchTab(key) {
    state.activeTab = key;
    document.querySelectorAll('#cb-tabs button').forEach(function(b){
      var active = b.getAttribute('data-tab') === key;
      b.style.borderBottom = '2px solid ' + (active ? 'var(--p)' : 'transparent');
      b.style.color = active ? 'var(--t1)' : 'var(--t3)';
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
        '<div style="margin-bottom:12px;color:var(--t3)">' + (window.icon ? window.icon('lock', 42) : '') + '</div>' +
        '<div style="font-size:18px;font-weight:600;color:var(--t1);margin-bottom:6px">Chatbot is a Pro feature</div>' +
        '<div style="font-size:13px;color:var(--t3);margin-bottom:20px;max-width:420px;margin-left:auto;margin-right:auto">' +
          'Add a smart AI front desk to every page of your website. Capture leads, book appointments, answer FAQs 24/7. ' +
          'Available on the Pro ($199/mo) and Agency ($399/mo) plans.' +
        '</div>' +
        '<button onclick="nav(\'billing\')" style="background:linear-gradient(135deg,var(--p),var(--pu));color:#fff;border:none;border-radius:10px;padding:12px 24px;font-size:13px;font-weight:600;cursor:pointer">Upgrade to Pro</button>' +
      '</div>';
  }

  // ── TAB 1 — Setup ────────────────────────────────────────────────
  function _renderSetup() {
    var s = state.settings || {};
    document.getElementById('cb-body').innerHTML =
      '<div style="display:flex;flex-direction:column;gap:18px;max-width:680px">' +
        _scopeHtml('setup') +
        '<div class="luc-card">' +
          '<label style="display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer">' +
            '<div><div style="font-size:14px;font-weight:600;color:var(--t1)">Enabled</div>' +
            '<div id="cb-enabled-d" style="font-size:12px;color:var(--t3);margin-top:2px">' +
              (state.websiteId ? 'When off, the widget will not appear on this website.'
                               : 'When off, the widget will not appear on any of your sites.') + '</div></div>' +
            window.LUC.switchHtml('cb-enabled', !!s.enabled, 'cb-enabled-d') +
          '</label>' +
        '</div>' +

        _formRow('cb-greeting', 'Welcome message',
          '<input type="text" id="cb-greeting" value="' + _h(s.greeting || '') + '" maxlength="500" placeholder="Hi! How can I help you today?" class="luc-in">') +

        _formRow('cb-fallback-email', 'Fallback email — receives leads / escalations',
          '<input type="email" id="cb-fallback-email" value="' + _h(s.fallback_email || '') + '" maxlength="255" placeholder="hello@yourbusiness.com" class="luc-in">') +

        _formRow('cb-context', 'Business context (helps the AI answer accurately)',
          '<textarea id="cb-context" maxlength="8000" rows="6" placeholder="Tell the bot about your business — services, hours, policies, prices, anything customers commonly ask about." class="luc-in" style="resize:vertical">' + _h(s.business_context_text || '') + '</textarea>') +

        '<div style="display:flex;gap:18px;flex-wrap:wrap">' +
          _formRow('cb-color', 'Primary color',
            window.LUC.swatchHtml('cb-color', s.primary_color || '#6C5CE7')) +
          _formRow('cb-theme', 'Theme',
            '<div style="min-width:170px">' + window.LUC.listboxHtml({ id: 'cb-theme', value: s.theme || 'auto',
              label: 'Widget theme',
              items: [{ value: 'auto', label: 'Auto', hint: 'follows the visitor' },
                      { value: 'light', label: 'Light' }, { value: 'dark', label: 'Dark' }] }) + '</div>') +
        '</div>' +

        _lookCardHtml() +

        '<div style="display:flex;gap:10px;justify-content:flex-end;padding-top:8px">' +
          '<button id="cb-save" style="background:linear-gradient(135deg,var(--p),var(--pu));color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:13px;font-weight:600;cursor:pointer">Save Settings</button>' +
          '<span id="cb-save-status" style="font-size:12px;color:var(--t3);align-self:center"></span>' +
        '</div>' +
      '</div>';

    window.LUC.wire(document.getElementById('cb-body'), {});
    Array.prototype.forEach.call(document.querySelectorAll('[data-cb-look]'), function (b) {   // CHATBOT-PAGE-1
      b.onclick = function () { if (typeof window._t3ChatbotLookDialog === 'function') { window._t3ChatbotLookDialog(parseInt(b.getAttribute('data-cb-look'), 10)); } };
    });
    _wireScope('setup', function () {
      document.getElementById('cb-body').innerHTML =
        '<div style="padding:40px;text-align:center;color:var(--t3);font-size:13px">Loading…</div>';
      _api('GET', '/settings').then(function (r) {
        state.settings = (r.data && r.data.data) || null;
        _renderSetup();
      });
    });
    document.getElementById('cb-save').onclick = _saveSettings;
  }

  /* CHATBOT-PAGE-1 (Owner 2026-09-24): the icon-and-colour choices a long press offers on the chat button in the editor,
     here per website. The scope picker narrows the list to one site; 'All sites' lists every site the business owns. */
  function _lookCardHtml() {
    var sites = (state.websites || []).filter(function (w) { return !state.websiteId || Number(w.id) === Number(state.websiteId); });
    var btn = 'background:var(--s2);color:var(--t1);border:1px solid var(--bd2,var(--bd));border-radius:8px;padding:8px 14px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;font-family:inherit';
    var rows = sites.length ? sites.map(function (w) {
      return '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 0;border-top:1px solid var(--bd)">' +
        '<div style="min-width:0"><div style="font-size:13px;font-weight:600;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + _h(w.name || ('Website ' + w.id)) + '</div>' +
        (w.host ? '<div style="font-size:11px;color:var(--t3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + _h(w.host) + '</div>' : '') + '</div>' +
        '<button type="button" data-cb-look="' + _h(w.id) + '" style="' + btn + '">Icon &amp; colour</button></div>';
    }).join('') : '<div style="font-size:12px;color:var(--t3);padding:6px 0">Build a website first — the chat button lives on it.</div>';
    return '<div class="luc-card"><div style="font-size:14px;font-weight:600;color:var(--t1)">Chat button</div>' +
      '<div style="font-size:12px;color:var(--t3);margin:2px 0 8px">The icon and colour of the button visitors tap — the same choices as a long press on it in the editor.</div>' + rows + '</div>';
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
      primary_color:          window.LUC.value('cb-color'),
      theme:                  window.LUC.value('cb-theme'),
      business_context_text:  document.getElementById('cb-context').value || null,
    };
    _api('PUT', '/settings', body).then(function(r){
      if (r.data && r.data.success) {
        status.textContent = '✓ Saved';
        status.style.color = 'var(--ac)';
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
        '<div style="background:var(--s1);border:1px solid var(--bd2);border-radius:12px;padding:18px 20px">' +
          '<div style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:6px">Add knowledge</div>' +
          '<div style="font-size:12px;color:var(--t3);margin-bottom:12px">Upload a document or paste text. Either way it is converted to Markdown and split into chunks the bot retrieves from.</div>' +
          (state.websites && state.websites.length > 1
            ? '<div style="margin-bottom:8px"><label class="luc-lbl" for="cb-kb-website">Website this knowledge belongs to</label>' +
              window.LUC.listboxHtml({ id: 'cb-kb-website', value: state.websiteId || '',
                label: 'Website this knowledge belongs to',
                items: [{ value: '', label: 'All sites', hint: 'shared by every website' }].concat(
                  state.websites.map(function (w) { return { value: String(w.id), label: w.name, hint: w.host || '' }; })) }) +
              '</div>'
            : '') +
          /* The file input is kept in the DOM and focusable — it is the real control and the keyboard
             path — but the OS "Choose file" chrome is replaced by our own button and drop zone. */
          '<div id="cb-kb-drop" tabindex="0" role="button" aria-label="Upload a document to the knowledge base" ' +
            'style="border:1.5px dashed var(--bd2);border-radius:var(--r,10px);background:var(--s2);padding:18px 16px;' +
            'text-align:center;cursor:pointer;margin-bottom:10px;transition:border-color .15s,background .15s">' +
            '<div style="color:var(--t2);font-size:13px;display:flex;align-items:center;justify-content:center;gap:8px">' +
              (window.icon ? window.icon('attach', 18) : '') + '<span><strong style="color:var(--t1)">Upload a document</strong> or drop it here</span>' +
            '</div>' +
            '<div style="font-size:11px;color:var(--t3);margin-top:6px">PDF, Word (.docx, .doc), Excel (.xlsx), CSV, ODT, RTF, TXT or Markdown \u00b7 up to 10 MB</div>' +
            '<input type="file" id="cb-kb-file" accept=".pdf,.doc,.docx,.xlsx,.csv,.odt,.rtf,.txt,.md,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,application/vnd.oasis.opendocument.text,application/rtf,text/plain,text/markdown" ' +
              'style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none">' +
            '<div id="cb-kb-upstatus" style="font-size:12px;margin-top:8px;display:none"></div>' +
          '</div>' +
          '<div style="text-align:center;font-size:11px;color:var(--t3);margin:-2px 0 10px">or type it in</div>' +
          '<input type="text" id="cb-kb-title" placeholder="Title (e.g. Pricing FAQ)" class="luc-in" style="margin-bottom:8px">' +
          '<textarea id="cb-kb-text" rows="5" placeholder="Paste content here…" class="luc-in" style="resize:vertical"></textarea>' +
          '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px">' +
            '<button id="cb-kb-add" style="background:var(--p);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:600;cursor:pointer">Add Entry</button>' +
            '<span id="cb-kb-status" style="font-size:11px;color:var(--t3);align-self:center"></span>' +
          '</div>' +
        '</div>' +
        '<div id="cb-kb-head" style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-top:4px">' +
          '<div style="font-size:14px;font-weight:600;color:var(--t1)">Knowledge library</div>' +
          '<div id="cb-kb-count" style="font-size:11px;color:var(--t3)"></div>' +
        '</div>' +
        '<div id="cb-kb-list" style="display:flex;flex-direction:column;gap:8px">' +
          '<div style="padding:24px;text-align:center;color:var(--t3);font-size:12px">Loading…</div>' +
        '</div>' +
      '</div>';

    if (window.LUC) {
      window.LUC.wire(document.getElementById('cb-body'), { onChange: function (id, v) {
        if (id !== 'cb-kb-website') { return; }
        state.websiteId = v ? parseInt(v, 10) : null;   // shared with Setup: one scope for the page
        _refreshKnowledge();
      }});
    }
    _wireUpload();
    document.getElementById('cb-kb-add').onclick = _addKnowledge;
    _refreshKnowledge();
  }

  function _refreshKnowledge() {
    _api('GET', '/knowledge').then(function(r){
      // RISK-0118 — the route returns {data:{sources,websites,...}}; the tab read r.data.data as the
      // list and always showed "No entries yet". Sources carry website_id/website_name now.
      var d = (r.data && r.data.data) || {};
      var list = Array.isArray(d) ? d : (d.sources || []);
      state.knowledge = list;
      state.kbWebsites = d.websites || [];
      // listKnowledge has always returned these two and the tab has always ignored them, so a customer
      // had no idea they were approaching a plan limit until an upload was refused.
      state.docCount = (typeof d.doc_count === 'number') ? d.doc_count : list.length;
      state.docLimit = (typeof d.doc_limit === 'number') ? d.doc_limit : 0;
      // The scope control is rendered with its options by _renderKnowledge; nothing to populate here.
      var el = document.getElementById('cb-kb-list');
      if (!el) return;
      // Filter to the chosen website. "All sites" shows everything, with each row already labelled
      // by the site it belongs to, so the answer to "where does this bot's knowledge come from" is
      // visible rather than inferred.
      if (state.websiteId) {
        list = list.filter(function (k) { return parseInt(k.website_id, 10) === parseInt(state.websiteId, 10); });
      }
      if (!list.length) {
        el.innerHTML = '<div style="padding:24px;text-align:center;color:var(--t3);font-size:12px">' + (state.websiteId ? 'No entries for this website yet. Add one above, or switch to All sites.' : 'No entries yet. Add one above.') + '</div>';
        return;
      }
      // the count line, now that we know how many are shown vs held
      var head = document.getElementById('cb-kb-count');
      if (head) {
        var shown = list.length, total = state.docCount || 0;
        var txt = state.websiteId && shown !== total ? (shown + ' of ' + total + ' documents') : (total + ' document' + (total === 1 ? '' : 's'));
        if (state.docLimit > 0) { txt += ' \u00b7 ' + Math.max(0, state.docLimit - total) + ' of ' + state.docLimit + ' remaining on your plan'; }
        head.textContent = txt;
      }
      el.innerHTML = list.map(function(k){
        return '<div style="background:var(--s1);border:1px solid var(--bd2);border-radius:10px;padding:14px 16px;display:flex;align-items:flex-start;gap:12px">' +
          '<div style="flex:1;min-width:0">' +
            /* listKnowledge returns `label` (the filename for an upload, the typed title otherwise).
               The row used to read k.title, which that payload has never carried, so every uploaded
               document displayed as "(untitled)". */
            '<div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:4px">' + _h(k.label || k.title || '(untitled)') + '</div>' +
            '<div style="font-size:11px;color:var(--t3)">' + _h(_kbKind(k)) + (_kbSize(k) ? ' · ' + _kbSize(k) : '') + ' · ' +
              (k.website_name ? '<span style="color:var(--pu)">' + _h(k.website_name) + '</span> · '
                              : '<span style="color:#F59E0B">unassigned website</span> · ') +
              (function(n){ return n + (n === 1 ? ' chunk' : ' chunks'); })(parseInt(k.chunk_count || 0, 10)) +
              ' · added ' + _h((k.created_at || '').substring(0,10)) +
              /* A document whose extraction failed looked identical to one that worked. */
              (k.status === 'failed'
                ? ' · <span style="color:#F87171">could not be read' + (k.error_message ? ': ' + _h(k.error_message) : '') + '</span>'
                : (k.status === 'processing' ? ' · <span style="color:#F59E0B">still processing</span>' : '')) +
            '</div>' +
          '</div>' +
          '<div style="display:flex;gap:6px;flex-shrink:0">' +
            '<button data-id="' + _h(k.id) + '" class="cb-kb-view" style="background:none;border:1px solid var(--bd2);color:var(--t2);border-radius:6px;padding:5px 10px;font-size:11px;cursor:pointer">View</button>' +
            '<button data-id="' + _h(k.id) + '" class="cb-kb-del" style="background:none;border:1px solid var(--bd2);color:var(--t3);border-radius:6px;padding:5px 10px;font-size:11px;cursor:pointer">Delete</button>' +
          '</div>' +
        '</div>';
      }).join('');
      el.querySelectorAll('.cb-kb-view').forEach(function(b){
        b.onclick = function(){ _viewKnowledge(b.getAttribute('data-id')); };
      });
      el.querySelectorAll('.cb-kb-del').forEach(function(b){
        b.onclick = function(){
          var go = window.luConfirm('Delete knowledge', 'Remove this entry from the chatbot knowledge base?', { okLabel: 'Delete', cancelLabel: 'Keep', danger: true });
          Promise.resolve(go).then(function(ok){ if (!ok) return; _api('DELETE', '/knowledge/' + b.getAttribute('data-id')).then(function(){ _refreshKnowledge(); }); });
        };
      });
    });
  }

  /*
   * Document upload. Multipart, so it cannot go through _api (which is JSON) — the website scope is
   * therefore attached to the FormData by hand rather than to the query string.
   * The server converts PDF / Word / Excel / CSV / ODT / RTF to Markdown before chunking, so what is
   * stored is one predictable shape whatever the customer happened to have.
   */
  function _wireUpload() {
    var drop = document.getElementById('cb-kb-drop');
    var input = document.getElementById('cb-kb-file');
    if (!drop || !input) { return; }

    drop.addEventListener('click', function () { input.click(); });
    drop.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    input.addEventListener('change', function () {
      if (input.files && input.files[0]) { _uploadKnowledge(input.files[0]); }
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) {
        e.preventDefault(); e.stopPropagation();
        drop.style.borderColor = 'var(--p)'; drop.style.background = 'var(--s3)';
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) {
        e.preventDefault(); e.stopPropagation();
        drop.style.borderColor = 'var(--bd2)'; drop.style.background = 'var(--s2)';
      });
    });
    drop.addEventListener('drop', function (e) {
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) { _uploadKnowledge(f); }
    });
  }

  function _uploadKnowledge(file) {
    var st = document.getElementById('cb-kb-upstatus');
    function say(msg, colour) {
      if (!st) { return; }
      st.style.display = 'block';
      st.textContent = msg;
      st.style.color = colour || 'var(--t3)';
    }
    if (file.size > 10 * 1024 * 1024) {
      return say(file.name + ' is larger than 10 MB.', '#F87171');
    }
    say('Reading ' + file.name + '…');

    var fd = new FormData();
    fd.append('file', file);
    fd.append('label', file.name);
    var chosen = window.LUC ? window.LUC.value('cb-kb-website') : null;
    if (chosen) { fd.append('website_id', chosen); }

    fetch('/api/chatbot/knowledge/upload', {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: 'Bearer ' + _token() },
      body: fd   // no Content-Type: the browser sets the multipart boundary
    }).then(function (r) {
      return r.json().catch(function () { return { success: false, message: 'Upload failed.' }; })
        .then(function (j) { return { status: r.status, data: j }; });
    }).then(function (r) {
      var d = r.data || {};
      if (r.status >= 200 && r.status < 300 && d.success) {
        var st2 = d.data && d.data.status;
        if (st2 === 'failed') {
          // The row exists but extraction did not work — say what the server said, do not claim success.
          say((d.data.error_message || 'That document could not be read.'), '#F87171');
        } else {
          say('\u2713 ' + file.name + ' added', 'var(--ac)');
          setTimeout(function () { if (st) { st.style.display = 'none'; } }, 3000);
        }
        _refreshKnowledge();
      } else {
        say(d.message || d.error || 'Upload failed.', '#F87171');
      }
      var input = document.getElementById('cb-kb-file');
      if (input) { input.value = ''; }
    }).catch(function () {
      say('Upload failed — check your connection and try again.', '#F87171');
    });
  }

  /* A readable kind from the mime type, falling back to how the source arrived. */
  function _kbKind(k) {
    var m = String(k.mime_type || '');
    if (m.indexOf('pdf') !== -1) return 'PDF';
    if (m.indexOf('wordprocessingml') !== -1) return 'Word';
    if (m === 'application/msword') return 'Word';
    if (m.indexOf('spreadsheetml') !== -1) return 'Excel';
    if (m.indexOf('csv') !== -1) return 'CSV';
    if (m.indexOf('opendocument.text') !== -1) return 'ODT';
    if (m.indexOf('rtf') !== -1) return 'RTF';
    if (m.indexOf('markdown') !== -1) return 'Markdown';
    if (m === 'text/plain') return 'Text';
    if (k.source_type === 'website_crawl') return 'Crawled page';
    if (k.source_type === 'text') return 'Typed';
    return k.source_type || 'File';
  }
  function _kbSize(k) {
    var b = parseInt(k.size_bytes || 0, 10);
    if (!b) return '';
    if (b < 1024) return b + ' B';
    if (b < 1024 * 1024) return Math.round(b / 1024) + ' KB';
    return (b / 1048576).toFixed(1) + ' MB';
  }

  /*
   * View what the bot actually read. The point of the library is not the filename — it is being able
   * to check that the price list survived the conversion, and to see WHY a document failed. Rendered
   * in our own panel, not a browser dialog.
   */
  function _viewKnowledge(id) {
    var ov = document.createElement('div');
    ov.id = 'cb-kb-viewer';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', 'Knowledge document');
    ov.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.55);display:flex;' +
      'align-items:center;justify-content:center;padding:24px';
    ov.innerHTML =
      '<div style="background:var(--s1);border:1px solid var(--bd2);border-radius:var(--rg,14px);' +
        'width:min(820px,100%);max-height:82vh;display:flex;flex-direction:column;overflow:hidden;' +
        'box-shadow:0 24px 60px rgba(0,0,0,.4)">' +
        '<div style="display:flex;align-items:flex-start;gap:12px;padding:16px 18px;border-bottom:1px solid var(--bd)">' +
          '<div style="flex:1;min-width:0">' +
            '<div id="cb-v-title" style="font-size:14px;font-weight:600;color:var(--t1)">Loading…</div>' +
            '<div id="cb-v-meta" style="font-size:11px;color:var(--t3);margin-top:3px"></div>' +
          '</div>' +
          '<button id="cb-v-close" style="background:none;border:1px solid var(--bd2);color:var(--t2);' +
            'border-radius:6px;padding:5px 10px;font-size:11px;cursor:pointer">Close</button>' +
        '</div>' +
        '<div id="cb-v-body" style="flex:1;min-height:0;overflow:auto;padding:16px 18px;font:400 12.5px ui-monospace,monospace;' +
          'color:var(--t2);white-space:pre-wrap;word-break:break-word;scrollbar-width:thin;scrollbar-color:var(--bd2) transparent"></div>' +
      '</div>';
    document.body.appendChild(ov);

    function close() { if (ov.parentNode) { ov.parentNode.removeChild(ov); } document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
    ov.querySelector('#cb-v-close').onclick = close;
    ov.querySelector('#cb-v-close').focus();

    _api('GET', '/knowledge/' + id).then(function (r) {
      var d = (r.data && r.data.data) || null;
      if (!d) {
        ov.querySelector('#cb-v-title').textContent = 'Could not open this document';
        ov.querySelector('#cb-v-body').textContent = (r.data && r.data.message) || '';
        return;
      }
      ov.querySelector('#cb-v-title').textContent = d.label || '(untitled)';
      var bits = [_kbKind(d)];
      if (_kbSize(d)) { bits.push(_kbSize(d)); }
      bits.push((d.chunk_count || 0) + (parseInt(d.chunk_count || 0, 10) === 1 ? ' chunk' : ' chunks'));
      bits.push(d.website_name ? d.website_name : 'unassigned website');
      bits.push('added ' + String(d.created_at || '').substring(0, 10));
      if (d.char_count) { bits.push(d.char_count.toLocaleString() + ' characters of Markdown'); }
      ov.querySelector('#cb-v-meta').textContent = bits.join(' \u00b7 ');

      var body = ov.querySelector('#cb-v-body');
      if (d.status === 'failed') {
        body.style.color = '#F87171';
        body.textContent = 'This document could not be read.\n\n' + (d.error_message || '') +
          '\n\nNothing from it is in the chatbot\u2019s knowledge. Fix the file and upload it again.';
        return;
      }
      body.textContent = (d.raw_text || '(empty)') + (d.truncated ? '\n\n… truncated for display; the full document is indexed.' : '');
    });
  }

  function _addKnowledge() {
    var title = document.getElementById('cb-kb-title').value.trim();
    var text  = document.getElementById('cb-kb-text').value.trim();
    var status = document.getElementById('cb-kb-status');
    if (!title || !text) { status.textContent = 'Title + text required'; status.style.color = '#F87171'; return; }
    status.textContent = 'Adding…'; status.style.color = 'var(--t3)';
    var chosen = window.LUC ? window.LUC.value('cb-kb-website') : null;
    var payload = { label: title, title: title, text: text };
    if (chosen) payload.website_id = parseInt(chosen, 10);
    _api('POST', '/knowledge/text', payload).then(function(r){
      if (r.data && r.data.success) {
        status.textContent = '✓ Added'; status.style.color = 'var(--ac)';
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
        '<div id="cb-conv-list" style="background:var(--s1);border:1px solid var(--bd2);border-radius:12px;overflow-y:auto;padding:8px">' +
          '<div style="padding:24px;text-align:center;color:var(--t3);font-size:12px">Loading…</div>' +
        '</div>' +
        '<div id="cb-conv-detail" style="background:var(--s1);border:1px solid var(--bd2);border-radius:12px;padding:18px;overflow-y:auto">' +
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
        return '<div data-id="' + _h(c.id) + '" class="cb-conv-row" style="padding:12px;border-radius:8px;cursor:pointer;border-bottom:1px solid var(--s2)">' +
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
        '<div style="font-size:12px;color:var(--t3);margin-bottom:12px;border-bottom:1px solid var(--bd2);padding-bottom:10px">' +
          'Session #' + _h(id) + ' · ' + msgs.length + ' messages' +
        '</div>' +
        msgs.map(function(m){
          var isUser = m.role === 'user' || m.role === 'visitor';
          return '<div style="margin-bottom:10px;display:flex;' + (isUser ? 'justify-content:flex-end' : 'justify-content:flex-start') + '">' +
            '<div style="max-width:80%;padding:8px 12px;border-radius:10px;font-size:12px;line-height:1.45;' +
              (isUser ? 'background:var(--p);color:var(--t1)' : 'background:var(--s2);color:var(--t2)') + '">' +
              _h(m.content || '') + '</div>' +
          '</div>';
        }).join('');
    });
  }

  // ── TAB 4 — Embed ────────────────────────────────────────────────
  function _renderEmbed() {
    document.getElementById('cb-body').innerHTML =
      '<div style="display:flex;flex-direction:column;gap:18px;max-width:780px">' +
        '<div style="background:var(--s1);border:1px solid var(--bd2);border-radius:12px;padding:20px">' +
          '<div style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:6px">Embed snippet</div>' +
          '<div style="font-size:12px;color:var(--t3);margin-bottom:14px">Paste this single tag before <code>&lt;/body&gt;</code> on any external site. ' +
          'Sites built by Arthur can have the chatbot enabled automatically — toggle it on the Setup tab.</div>' +
          '<div id="cb-embed-snippet-box" style="background:var(--s2);border:1px solid var(--bd2);border-radius:8px;padding:14px;font-family:ui-monospace,monospace;font-size:12px;color:var(--pu);word-break:break-all;line-height:1.5;min-height:60px">Loading workspace token…</div>' +
          '<div style="display:flex;gap:8px;margin-top:10px">' +
            '<button id="cb-embed-copy" style="background:var(--p);color:var(--t1);border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:600;cursor:pointer">Copy snippet</button>' +
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
      box.textContent = 'Could not resolve workspace. Save your settings on the Setup tab first.';
      box.style.color = '#F87171';
      return;
    }
    var origin = window.location.origin; // e.g. https://staging.levelupgrowth.io
    var snippet = '<script src="' + origin + '/chatbot.js?ws=' + wsId + '" async></​script>';
    box.textContent = snippet;
    box.style.color = 'var(--pu)';
    document.getElementById('cb-embed-copy').onclick = function(){
      navigator.clipboard.writeText(snippet.replace('​', '')).then(function(){
        var st = document.getElementById('cb-embed-status');
        st.textContent = '✓ Copied'; st.style.color = 'var(--ac)';
        setTimeout(function(){ st.textContent = ''; }, 2000);
      });
    };
  }
})();
