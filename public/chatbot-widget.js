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
  var ICON     = script.getAttribute('data-icon') || '';
  var BUBBLE   = script.getAttribute('data-bubble') || '';
  var GRAD     = script.getAttribute('data-gradient') || '';
  var PANEL    = script.getAttribute('data-panel') || '';     // optional: the panel's surface colour
  var BACKDROP = script.getAttribute('data-backdrop') || '';  // optional: veil + blur the page while open  // optional: a CSS gradient for header, send and the visitor's bubbles  // optional: the floater's own background (defaults to data-color)   // optional: an image for the floater instead of the chat glyph

  if (!TOKEN) { console.warn('[CHATBOT888] No data-token provided.'); return; }

  // --- State ---
  var sessionId = null;
  // CONVERSATION MEMORY (2026-09-12): the last 24 h of this chat survive a refresh.
  var STORE_KEY = 'cb888:' + TOKEN.slice(0, 12);
  var store = { sessionId: null, msgs: [], at: 0 };
  var restored = false;
  try { var raw = localStorage.getItem(STORE_KEY); if (raw) { var parsed = JSON.parse(raw); if (parsed && parsed.at && Date.now() - parsed.at < 86400000 && parsed.msgs && parsed.msgs.length) { store = parsed; sessionId = parsed.sessionId || null; restored = true; } } } catch (e) {}
  function persist() { try { store.at = Date.now(); store.sessionId = sessionId; if (store.msgs.length > 60) store.msgs = store.msgs.slice(-60); localStorage.setItem(STORE_KEY, JSON.stringify(store)); } catch (e) {} }
  function forget() { try { localStorage.removeItem(STORE_KEY); } catch (e) {} store = { sessionId: null, msgs: [], at: 0 }; sessionId = null; restored = false; }
  var greetingText = '';
  var isOpen    = false;
  var isLoading = false;
  var startedAt = Date.now(); // ms epoch when widget rendered (for anti-bot check)
  var dark      = (THEME === 'dark') || (THEME === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);

  // --- Contrast intelligence (Owner): text on the brand color must stay readable, and
  // a very-light brand color must not vanish on a white host page. WCAG-optimal choice
  // of black/white text by contrast ratio; a light-color guard adds a subtle border. ---
  function cb888Lum(hex) {
    hex = String(hex || '').replace('#', '');
    if (hex.length === 3) { hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2]; }
    if (hex.length !== 6 || /[^0-9a-fA-F]/.test(hex)) { return 0.5; }
    var r = parseInt(hex.slice(0,2),16)/255, g = parseInt(hex.slice(2,4),16)/255, b = parseInt(hex.slice(4,6),16)/255;
    var f = function (c) { return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); };
    return 0.2126*f(r) + 0.7152*f(g) + 0.0722*f(b);
  }
  function cb888On(hex) {
    var L = cb888Lum(hex);
    var whiteRatio = 1.05 / (L + 0.05);      // contrast of #fff on hex
    var blackRatio = (L + 0.05) / 0.05;      // contrast of #000 on hex
    return blackRatio >= whiteRatio ? '#111111' : '#ffffff';
  }
  var FG_ON = cb888On(COLOR);
  var LIGHT = cb888Lum(COLOR) > 0.82;
  var bubbleBorder = LIGHT ? '1px solid rgba(0,0,0,.18)' : 'none';

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
  if (PANEL) { bg = PANEL; msgBg = 'rgba(255,255,255,.07)'; border = 'rgba(255,255,255,.10)'; inputBg = 'rgba(255,255,255,.05)'; inputBorder = 'rgba(255,255,255,.16)'; }
  var posSide = (POSITION === 'bottom-left') ? 'left' : 'right';

  var css = [
    '#cb888-bubble{position:fixed;bottom:24px;' + posSide + ':24px;z-index:99999;width:56px;height:56px;border-radius:50%;background:' + (BUBBLE || COLOR) + ';border:' + bubbleBorder + ';cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);transition:transform .2s;}',
    '#cb888-bubble:hover{transform:scale(1.08);}',
    '#cb888-bubble svg{width:26px;height:26px;fill:' + FG_ON + ';}',
    '#cb888-panel{position:fixed;bottom:92px;' + posSide + ':24px;z-index:99998;width:360px;max-width:calc(100vw - 48px);height:520px;max-height:calc(100vh - 120px);background:' + bg + ';border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,0.25);display:none;flex-direction:column;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:' + fg + ';}',
    '#cb888-header-actions{display:flex;align-items:center;gap:6px;}#cb888-reset{background:rgba(255,255,255,.14);border:0;border-radius:8px;width:30px;height:30px;color:inherit;font-size:16px;line-height:1;cursor:pointer;}#cb888-reset:hover{background:rgba(255,255,255,.26);}',
    '#cb888-header{background:' + (GRAD || COLOR) + ';padding:16px 20px;display:flex;align-items:center;justify-content:space-between;}',
    '#cb888-header-title{color:' + FG_ON + ';font-weight:600;font-size:15px;}',
    '#cb888-close{background:none;border:none;color:' + FG_ON + ';cursor:pointer;font-size:20px;line-height:1;padding:0;opacity:.85;}',
    '#cb888-close:hover{opacity:1;}',
    '#cb888-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;}',
    '.cb888-msg{max-width:80%;padding:10px 14px;border-radius:12px;font-size:14px;line-height:1.5;word-break:break-word;white-space:pre-wrap;}',
    '.cb888-msg.user{align-self:flex-end;background:' + (GRAD || COLOR) + ';color:' + FG_ON + ';border-bottom-right-radius:4px;}',
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
    '#cb888-send{background:' + (GRAD || COLOR) + ';border:none;border-radius:8px;width:40px;height:40px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;}',
    '#cb888-send:disabled{opacity:.5;cursor:not-allowed;}',
    '#cb888-send svg{width:18px;height:18px;fill:' + FG_ON + ';}',
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
  bubble.innerHTML = ICON
    ? '<img src="' + ICON.replace(/"/g, '&quot;') + '" alt="" aria-hidden="true" style="width:32px;height:32px;object-fit:contain;display:block;pointer-events:none">'
    : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>';
  // CHATBOT-LOOK-1 (2026-09-23): the owner's chosen launcher icon arrives with /config
  function setIcon(u) { try { bubble.innerHTML = '<img src="' + String(u).replace(/"/g, '&quot;') + '" alt="" aria-hidden="true" style="width:32px;height:32px;object-fit:contain;display:block;pointer-events:none">'; } catch (e) {} }

  var panel = document.createElement('div');
  panel.id = 'cb888-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Chat with us');
  panel.innerHTML = [
    '<div id="cb888-header">',
    '  <span id="cb888-header-title">Chat with us</span>',
    '  <span id="cb888-header-actions"><button id="cb888-reset" type="button" aria-label="Start a new conversation" title="New conversation">&#x21bb;</button>',
    '  <button id="cb888-close" aria-label="Close chat">&#x2715;</button></span>',
    '</div>',
    '<div id="cb888-messages" aria-live="polite"></div>',
    '<div id="cb888-input-row">',
    '  <textarea id="cb888-input" rows="1" placeholder="Type a message..." aria-label="Message"></textarea>',
    '  <input id="cb888-hp" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">',
    '  <button id="cb888-send" aria-label="Send message"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>',
    '</div>',
    '<div id="cb888-powered">Powered by LevelUpGrowth</div>'
  ].join('\n');

  document.body.appendChild(bubble);
  document.body.appendChild(panel);

  var $ = function (id) { return document.getElementById(id); };

  // --- UI helpers ---
  function addMsg(role, text, silent) {
    var box = $('cb888-messages');
    var d = document.createElement('div');
    d.className = 'cb888-msg ' + role;
    d.textContent = text;
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
    if (!silent && (role === 'user' || role === 'assistant')) { store.msgs.push({ role: role, text: text }); persist(); }
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
  // 2026-05-28 — Re-paint the widget when /config returns a primary_color
  // that differs from the script-tag/default. The bubble + header + send
  // button + outgoing-message background and input focus border all use
  // the brand color; .cb888-msg.user uses a class selector so an inline
  // style on the element won't override it — append a small <style> with
  // !important so the override beats the original rule from line 53+.
  function applyColor(c) {
    if (!c || c === COLOR) return;
    var on = cb888On(c);
    var light = cb888Lum(c) > 0.82;
    var s = document.createElement('style');
    s.textContent =
      '#cb888-bubble,#cb888-header,#cb888-send,.cb888-msg.user{background:' + c + ' !important}' +
      (BUBBLE ? '#cb888-bubble{background:' + BUBBLE + ' !important}' : '') +
      (GRAD ? '#cb888-header,#cb888-send,.cb888-msg.user{background:' + GRAD + ' !important}' : '') +
      '#cb888-header-title,#cb888-close,.cb888-msg.user{color:' + on + ' !important}' +
      '#cb888-bubble svg,#cb888-send svg{fill:' + on + ' !important}' +
      '#cb888-bubble{border:' + (light ? '1px solid rgba(0,0,0,.18)' : 'none') + ' !important}' +
      '#cb888-input:focus{border-color:' + c + ' !important}';
    document.head.appendChild(s);
    COLOR = c; FG_ON = on;
  }

  function loadConfig() {
    return api('GET', '/config').then(function (res) {
      if (!res.ok) {
        console.warn('[CHATBOT888] config error', res.status, res.body);
        return null;
      }
      var d = (res.body && res.body.data) || {};
      if (d.greeting) greetingText = d.greeting;
      if (restored) { if (d.greeting) addMsg('assistant', d.greeting, true); store.msgs.forEach(function (m) { addMsg(m.role, m.text, true); }); }
      else if (d.greeting) addMsg('assistant', d.greeting, true);
      if (d.primary_color) applyColor(d.primary_color);
      if (d.launcher_icon) setIcon(d.launcher_icon);   // CHATBOT-LOOK-1
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
      persist();
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

    var attempt = function (retried) {
    return api('POST', '/message', {
      session_id: sessionId,
      message: text,
      hp: hp,
      started_at: startedAt
    }).then(function (res) {
      if (!res.ok && !retried && (res.status === 404 || res.status === 410 || res.status === 422 || res.status === 403)) {
        // A remembered session the server no longer has: start a fresh one and try once more.
        sessionId = null;
        return startSession().then(function (id) { if (!id) throw new Error('no_session'); return attempt(true); });
      }
      hideTyping();
      if (!res.ok) {
        var errCode = (res.body && res.body.error) || ('HTTP ' + res.status);
        addMsg('error', 'Error: ' + errCode);
        return;
      }
      var d = (res.body && res.body.data) || {};
      var reply = d.message || 'Sorry, I could not process that.';
      addMsg('assistant', reply);
    });
    };
    attempt(false).catch(function (e) {
      hideTyping();
      console.warn('[CHATBOT888] message error', e);
      addMsg('error', 'Connection error. Please try again.');
    }).finally(function () {
      isLoading = false;
      $('cb888-send').disabled = false;
      $('cb888-input').focus();
    });
  }

  // 2026-05-28 — Fire /config immediately at script load (memoised) so the
  // bubble re-paints to the brand color BEFORE the visitor clicks it. The
  // greeting is appended into the hidden panel; visitor sees it on first
  // open, same as before. open() reuses the memoised promise to avoid a
  // second /config call.
  var configPromise = loadConfig();

  // --- Open/close ---
  var backdrop = null;
  if (BACKDROP) {
    backdrop = document.createElement('div'); backdrop.id = 'cb888-backdrop';
    var blurPx = parseFloat(BACKDROP) > 0 ? parseFloat(BACKDROP) : 3;   // data-backdrop="3" → 3px blur (a quarter of the way to unreadable, per the Owner)
    backdrop.style.cssText = 'position:fixed;inset:0;z-index:99997;display:none;background:rgba(5,8,16,.25);-webkit-backdrop-filter:blur(' + blurPx + 'px);backdrop-filter:blur(' + blurPx + 'px);';
    document.body.appendChild(backdrop);
    backdrop.addEventListener('click', function () { close(); });
  }
  // Phones: keep the whole panel inside the visual viewport (keyboard open or not), shrinking it when needed.
  function fitPanel() {
    if (!isOpen) return;
    var narrow = window.innerWidth <= 520;
    if (!narrow) { panel.style.top = ''; panel.style.height = ''; panel.style.maxHeight = ''; panel.style.bottom = ''; return; }
    var vv = window.visualViewport;
    var vh = vv ? vv.height : window.innerHeight, vt = vv ? vv.offsetTop : 0;
    var pad = 12, h = Math.max(260, Math.min(520, vh - pad * 2));
    panel.style.bottom = 'auto';
    panel.style.top = Math.round(vt + pad) + 'px';
    panel.style.height = h + 'px';
    panel.style.maxHeight = h + 'px';
  }
  try {
    if (window.visualViewport) { window.visualViewport.addEventListener('resize', fitPanel); window.visualViewport.addEventListener('scroll', fitPanel); }
    window.addEventListener('resize', fitPanel);
  } catch (e) {}
  function open() {
    if (isOpen) return;
    isOpen = true;
    if (backdrop) backdrop.style.display = 'block';
    panel.style.display = 'flex';
    bubble.style.display = 'none';
    if (!sessionId) {
      configPromise.then(function () { return startSession(); });
    }
    fitPanel();
    setTimeout(function () { $('cb888-input').focus(); fitPanel(); }, 60);
    setTimeout(fitPanel, 400);
  }

  function close() {
    if (!isOpen) return;
    isOpen = false;
    if (backdrop) backdrop.style.display = 'none';
    panel.style.display = 'none';
    panel.style.top = ''; panel.style.height = ''; panel.style.maxHeight = ''; panel.style.bottom = '';
    bubble.style.display = 'flex';
  }

  bubble.addEventListener('click', open);
  $('cb888-close').addEventListener('click', close);
  $('cb888-reset').addEventListener('click', function () {
    forget();
    $('cb888-messages').innerHTML = '';
    if (greetingText) addMsg('assistant', greetingText, true);
    configPromise.then(function () { return startSession(); });
    $('cb888-input').focus();
  });

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
