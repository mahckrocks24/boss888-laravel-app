(function () {
  'use strict';

  // --- Discover own <script> tag ---
  var script = document.currentScript || (function () {
    var s = document.getElementsByTagName('script');
    return s[s.length - 1];
  })();

  var TOKEN    = script.getAttribute('data-token') || '';
  var COLOR    = script.getAttribute('data-color') || '#6C5CE7';
  var THEME    = script.getAttribute('data-theme') || 'auto';
  var POSITION = script.getAttribute('data-position') || 'bottom-right';
  var API      = script.getAttribute('data-api') || (window.location.origin + '/api/public/chatbot');

  if (!TOKEN) { console.warn('[CHATBOT888] No data-token provided.'); return; }

  // --- State ---
  var sessionId = null;
  var isOpen    = false;
  var isLoading = false;
  var startedAt = Date.now(); // ms epoch when widget rendered (for anti-bot check)
  var dark      = (THEME === 'dark') || (THEME === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);

  // --- API helper (sends X-CHATBOT-TOKEN header, not body/query) ---
  function api(method, path, body) {
    var opts = {
      method: method,
      headers: { 'X-CHATBOT-TOKEN': TOKEN, 'Accept': 'application/json' },
      credentials: 'omit'
    };
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(API + path, opts).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
    });
  }

  // --- Styles ---
  var bg     = dark ? '#1a1a1a' : '#fff';
  var fg     = dark ? '#fff' : '#1a1a1a';
  var msgBg  = dark ? '#2a2a2a' : '#f0f0f0';
  var border = dark ? '#333' : '#eee';
  var inputBg = dark ? '#2a2a2a' : '#fff';
  var inputBorder = dark ? '#444' : '#ddd';
  var poweredColor = dark ? '#555' : '#bbb';
  var typingDot = dark ? '#888' : '#aaa';
  var posSide = (POSITION === 'bottom-left') ? 'left' : 'right';

  var css = [
    '#cb888-bubble{position:fixed;bottom:24px;' + posSide + ':24px;z-index:99999;width:56px;height:56px;border-radius:50%;background:' + COLOR + ';cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);transition:transform .2s;}',
    '#cb888-bubble:hover{transform:scale(1.08);}',
    '#cb888-bubble svg{width:26px;height:26px;fill:#fff;}',
    '#cb888-panel{position:fixed;bottom:92px;' + posSide + ':24px;z-index:99998;width:360px;max-width:calc(100vw - 48px);height:520px;max-height:calc(100vh - 120px);background:' + bg + ';border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,0.25);display:none;flex-direction:column;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:' + fg + ';}',
    '#cb888-header{background:' + COLOR + ';padding:16px 20px;display:flex;align-items:center;justify-content:space-between;}',
    '#cb888-header-title{color:#fff;font-weight:600;font-size:15px;}',
    '#cb888-close{background:none;border:none;color:#fff;cursor:pointer;font-size:20px;line-height:1;padding:0;opacity:.85;}',
    '#cb888-close:hover{opacity:1;}',
    '#cb888-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;}',
    '.cb888-msg{max-width:80%;padding:10px 14px;border-radius:12px;font-size:14px;line-height:1.5;word-break:break-word;white-space:pre-wrap;}',
    '.cb888-msg.user{align-self:flex-end;background:' + COLOR + ';color:#fff;border-bottom-right-radius:4px;}',
    '.cb888-msg.assistant{align-self:flex-start;background:' + msgBg + ';color:' + fg + ';border-bottom-left-radius:4px;}',
    '.cb888-msg.error{align-self:center;background:transparent;color:#c0392b;font-size:12px;font-style:italic;}',
    '.cb888-typing{display:flex;gap:4px;align-items:center;padding:10px 14px;background:' + msgBg + ';border-radius:12px;border-bottom-left-radius:4px;align-self:flex-start;}',
    '.cb888-typing span{width:7px;height:7px;border-radius:50%;background:' + typingDot + ';animation:cb888-bounce .9s infinite;}',
    '.cb888-typing span:nth-child(2){animation-delay:.15s;}',
    '.cb888-typing span:nth-child(3){animation-delay:.3s;}',
    '@keyframes cb888-bounce{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)}}',
    '#cb888-input-row{padding:12px 16px;display:flex;gap:8px;border-top:1px solid ' + border + ';}',
    '#cb888-input{flex:1;border:1px solid ' + inputBorder + ';border-radius:8px;padding:10px 14px;font-size:14px;background:' + inputBg + ';color:' + fg + ';outline:none;resize:none;font-family:inherit;}',
    '#cb888-input:focus{border-color:' + COLOR + ';}',
    '#cb888-send{background:' + COLOR + ';border:none;border-radius:8px;width:40px;height:40px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;}',
    '#cb888-send:disabled{opacity:.5;cursor:not-allowed;}',
    '#cb888-send svg{width:18px;height:18px;fill:#fff;}',
    '#cb888-powered{text-align:center;font-size:10px;color:' + poweredColor + ';padding:6px 0;letter-spacing:.04em;}',
    '#cb888-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0;}'
  ].join('\n');

  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  // --- DOM ---
  var bubble = document.createElement('div');
  bubble.id = 'cb888-bubble';
  bubble.setAttribute('aria-label', 'Open chat');
  bubble.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';

  var panel = document.createElement('div');
  panel.id = 'cb888-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Chat with us');
  panel.innerHTML = [
    '<div id="cb888-header">',
    '  <span id="cb888-header-title">Chat with us</span>',
    '  <button id="cb888-close" aria-label="Close chat">&#x2715;</button>',
    '</div>',
    '<div id="cb888-messages" aria-live="polite"></div>',
    '<div id="cb888-input-row">',
    '  <textarea id="cb888-input" rows="1" placeholder="Type a message..." aria-label="Message"></textarea>',
    '  <input id="cb888-hp" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">',
    '  <button id="cb888-send" aria-label="Send message"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>',
    '</div>',
    '<div id="cb888-powered">Powered by LevelUp Growth</div>'
  ].join('\n');

  document.body.appendChild(bubble);
  document.body.appendChild(panel);

  var $ = function (id) { return document.getElementById(id); };

  // --- UI helpers ---
  function addMsg(role, text) {
    var box = $('cb888-messages');
    var d = document.createElement('div');
    d.className = 'cb888-msg ' + role;
    d.textContent = text;
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
    return d;
  }

  function showTyping() {
    var box = $('cb888-messages');
    if ($('cb888-typing')) return;
    var d = document.createElement('div');
    d.className = 'cb888-typing';
    d.id = 'cb888-typing';
    d.innerHTML = '<span></span><span></span><span></span>';
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
  }

  function hideTyping() {
    var t = $('cb888-typing');
    if (t) t.remove();
  }

  // --- API flow ---
  function loadConfig() {
    return api('GET', '/config').then(function (res) {
      if (!res.ok) {
        console.warn('[CHATBOT888] config error', res.status, res.body);
        return null;
      }
      var d = (res.body && res.body.data) || {};
      if (d.greeting) addMsg('assistant', d.greeting);
      return d;
    }).catch(function (e) {
      console.warn('[CHATBOT888] config network error', e);
      return null;
    });
  }

  function startSession() {
    return api('POST', '/session/start', { page_url: window.location.href }).then(function (res) {
      if (!res.ok) {
        addMsg('error', 'Could not start chat (' + (res.body && res.body.error || res.status) + ')');
        return null;
      }
      sessionId = (res.body && res.body.data && res.body.data.session_id) || null;
      return sessionId;
    }).catch(function (e) {
      console.warn('[CHATBOT888] session error', e);
      addMsg('error', 'Connection error.');
      return null;
    });
  }

  function sendMessage(text) {
    text = (text || '').trim();
    if (!text || isLoading) return;
    if (!sessionId) {
      addMsg('error', 'Not connected. Please reopen the chat.');
      return;
    }
    var hp = $('cb888-hp').value;
    addMsg('user', text);
    $('cb888-input').value = '';
    $('cb888-send').disabled = true;
    isLoading = true;
    showTyping();

    api('POST', '/message', {
      session_id: sessionId,
      message: text,
      hp: hp,
      started_at: startedAt
    }).then(function (res) {
      hideTyping();
      if (!res.ok) {
        var errCode = (res.body && res.body.error) || ('HTTP ' + res.status);
        addMsg('error', 'Error: ' + errCode);
        return;
      }
      var d = (res.body && res.body.data) || {};
      var reply = d.message || 'Sorry, I could not process that.';
      addMsg('assistant', reply);
    }).catch(function (e) {
      hideTyping();
      console.warn('[CHATBOT888] message error', e);
      addMsg('error', 'Connection error. Please try again.');
    }).finally(function () {
      isLoading = false;
      $('cb888-send').disabled = false;
      $('cb888-input').focus();
    });
  }

  // --- Open/close ---
  function open() {
    if (isOpen) return;
    isOpen = true;
    panel.style.display = 'flex';
    bubble.style.display = 'none';
    if (!sessionId) {
      loadConfig().then(function () { return startSession(); });
    }
    setTimeout(function () { $('cb888-input').focus(); }, 60);
  }

  function close() {
    if (!isOpen) return;
    isOpen = false;
    panel.style.display = 'none';
    bubble.style.display = 'flex';
  }

  bubble.addEventListener('click', open);
  $('cb888-close').addEventListener('click', close);

  $('cb888-send').addEventListener('click', function () {
    sendMessage($('cb888-input').value);
  });

  $('cb888-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage(this.value);
    }
  });

  // Auto-grow textarea up to 4 lines
  $('cb888-input').addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 96) + 'px';
  });

})();
