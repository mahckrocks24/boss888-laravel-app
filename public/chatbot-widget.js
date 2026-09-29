(function () {
  'use strict';

  // --- Discover own <script> tag ---
  var script = document.currentScript || (function () {
    var s = document.getElementsByTagName('script');
    return s[s.length - 1];
  })();

  var TOKEN    = script.getAttribute('data-token') || (window.LU_CHATBOT_TOKEN ? String(window.LU_CHATBOT_TOKEN) : '');   // CBTOK-1: template/builder sites pass the token on window
  var COLOR    = script.getAttribute('data-color') || '#6C5CE7';
  var THEME    = script.getAttribute('data-theme') || 'auto';
  var POSITION = script.getAttribute('data-position') || 'bottom-right';
  var API      = script.getAttribute('data-api') || (window.LU_CHATBOT_API ? String(window.LU_CHATBOT_API).replace(/\/+$/, '') + '/api/public/chatbot' : (window.location.origin + '/api/public/chatbot'));   // CBTOK-1: a custom domain talks to the platform, not to itself
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
    '<div id="cb888-powered">LevelUpGrowth is AI and can make mistakes.</div>'
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

/* LU-KEYBOARD — KB-3 (Owner 2026-09-25: "on mobile, on ALL areas where text input is needed and keyboard opens, the place
   where text is entered must be visible … on all devices including iPhones").

   One guard for every document: the app shell, the marketing site, every published website and its editor preview,
   the chatbot widget (also when embedded elsewhere). Whenever a text field has focus and the keyboard is open, the field
   is brought into the VISIBLE part of the screen, whatever it sits in:

     - iPhone/iPad Safari (and any browser that lays the keyboard OVER the page): the layout viewport keeps its height,
       window.visualViewport shrinks. Fixed/sticky containers (chat composers, floaters, dialogs) do not move by
       themselves, so they end up under the keyboard → they are lifted by exactly what is needed, and capped to the
       visible height when they are taller than it.
     - Android Chrome with interactive-widget=resizes-content (the layout shrinks): fields inside non-scrolling panels
       can still be pushed out → the nearest scrollable ancestor, the iframe document, then the window, are scrolled.
     - Fields inside a same-origin iframe (the website editor's preview) are found through the frame chain and
       measured in top-window coordinates.

   It re-checks at 60/320/700/1200 ms after focus (keyboards animate at different speeds) and on every visual-viewport
   change while a field is focused; everything it moved is put back when the keyboard closes. It sets html.lu-kb-open
   and --lu-kb (the height the layout did NOT absorb) so existing CSS keeps working, and retires the older KB-2 handler
   by claiming its flag. No dependencies; safe to load twice. */
(function () {
  if (window.__luKb3) return;
  window.__luKb3 = true;
  window.__luKbInstalled = true;   // KB-2 (core.js) checks this and steps aside

  var vv = window.visualViewport || null;
  var root = document.documentElement;
  var TEXT = /^(text|email|search|url|tel|number|password|date|time|datetime-local|month|week)$/;
  var PAD = 12;
  var lifted = [];          // [{el, transform, transition, maxHeight, overflowY}]
  var ticks = [];           // pending timers after a focus
  var debounce = null;
  var baseH = window.innerHeight;   // tallest layout viewport seen: Android's keyboard shrinks it
  var focusedAt = 0;

  function isField(el) {
    if (!el || el.nodeType !== 1) return false;
    var t = el.tagName;
    if (t === 'TEXTAREA') return true;
    if (t === 'INPUT') return TEXT.test((el.getAttribute('type') || 'text').toLowerCase());
    return !!el.isContentEditable;
  }

  /* Some in-app browsers (Facebook Messenger's on Android, measured 2026-09-25) lay the keyboard over the page and
     tell it NOTHING — no visualViewport change, no resize. On a phone, when a field near the bottom is focused and
     no signal has arrived, a keyboard of ~42% of the screen is assumed until a real signal or the blur says otherwise. */
  var assumedKb = 0;
  var phone = (function () { try { return window.matchMedia('(pointer: coarse)').matches && window.matchMedia('(hover: none)').matches; } catch (e) { return false; } })();

  /** The visible area in top-window coordinates, and how much of the layout the keyboard covers. */
  function vis() {
    if (vv) {
      var kb = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
      if (kb < 60 && assumedKb > 0) { return { top: vv.offsetTop, bottom: vv.offsetTop + window.innerHeight - assumedKb, kb: assumedKb, assumed: true }; }
      return { top: vv.offsetTop, bottom: vv.offsetTop + vv.height, kb: kb };
    }
    if (assumedKb > 0) { return { top: 0, bottom: window.innerHeight - assumedKb, kb: assumedKb, assumed: true }; }
    return { top: 0, bottom: window.innerHeight, kb: 0 };
  }

  /** The focused element, following same-origin iframes down; offsets translate its rect to top-window space. */
  function deepActive() {
    var doc = document, el = doc.activeElement, off = { x: 0, y: 0 }, frames = [];
    var guard = 0;
    while (el && el.tagName === 'IFRAME' && guard++ < 5) {
      var cd = null;
      try { cd = el.contentDocument; } catch (e) { cd = null; }
      if (!cd) break;
      var fr = el.getBoundingClientRect();
      frames.push(el); off.x += fr.left; off.y += fr.top;
      doc = cd; el = cd.activeElement;
    }
    return { el: el, off: off, frames: frames, doc: doc };
  }

  function rectOf(el, off) { var r = el.getBoundingClientRect(); return { top: r.top + off.y, bottom: r.bottom + off.y, height: r.height }; }

  /** Positive = the field sticks out below the visible area; negative = above; 0 = fine. */
  function need(el, off, v) {
    var r = rectOf(el, off);
    if (r.height > (v.bottom - v.top) - 2 * PAD) {
      // taller than the screen (long textarea/contenteditable): the caret end is what matters — keep the bottom visible
      return r.bottom > v.bottom - PAD ? r.bottom - (v.bottom - PAD) : 0;
    }
    if (r.bottom > v.bottom - PAD) return r.bottom - (v.bottom - PAD);
    if (r.top < v.top + PAD) return r.top - (v.top + PAD);
    return 0;
  }

  /** Scroll NOW. A page with scroll-behavior:smooth animates scrollTop writes and scrollIntoView, and four re-checks in
      1.2 s each start a new animation that cancels the last — the field never arrived (measured on a published site). */
  function instantScroll(target, by, win) {
    try {
      if (target === null) { (win || window).scrollBy({ top: by, left: 0, behavior: 'instant' }); return; }
      if (typeof target.scrollBy === 'function') { target.scrollBy({ top: by, left: 0, behavior: 'instant' }); return; }
      target.scrollTop += by;
    } catch (e) { try { target === null ? (win || window).scrollBy(0, by) : (target.scrollTop += by); } catch (e2) {} }
  }

  function scrollParent(el, doc) {
    var win = doc.defaultView, p = el.parentElement;
    while (p && p !== doc.body && p !== doc.documentElement) {
      var cs = win.getComputedStyle(p);
      if (/(auto|scroll|overlay)/.test(cs.overflowY) && p.scrollHeight > p.clientHeight + 2) return p;
      p = p.parentElement;
    }
    return null;
  }

  function fixedAncestor(el, doc) {
    var win = doc.defaultView, p = el;
    while (p && p !== doc.body && p !== doc.documentElement) {
      var cs = win.getComputedStyle(p);
      if (cs.position === 'fixed' || cs.position === 'sticky') return p;
      p = p.parentElement;
    }
    return null;
  }

  function lift(el, by, capTo) {
    var rec = null;
    for (var i = 0; i < lifted.length; i++) if (lifted[i].el === el) rec = lifted[i];
    if (!rec) { rec = { el: el, transform: el.style.transform, transition: el.style.transition, maxHeight: el.style.maxHeight, overflowY: el.style.overflowY, alignItems: el.style.alignItems, by: 0 }; lifted.push(rec); }
    rec.by += by;
    // No transition: the next re-check reads the rect at once, and an animated lift read mid-flight looked like
    // "not lifted yet" — four re-checks then lifted four times (a wizard composer measured at y = −357).
    el.style.transition = 'none';
    el.style.transform = (rec.transform && rec.transform !== 'none' ? rec.transform + ' ' : '') + 'translateY(' + (-rec.by) + 'px)';
    void el.offsetHeight;
    if (capTo) {
      el.style.maxHeight = capTo + 'px'; el.style.overflowY = 'auto';
      // A full-screen overlay that CENTRES a dialog taller than what is visible pushes the dialog's top above the
      // screen (a centred wizard's composer measured at y = −183). Start it at the top and scroll inside instead.
      try { var cs = el.ownerDocument.defaultView.getComputedStyle(el); if (cs.display === 'flex' && /center/.test(cs.alignItems)) el.style.setProperty('align-items', 'flex-start', 'important'); } catch (e) {}
    }
  }

  function restore() {
    for (var i = 0; i < lifted.length; i++) {
      var x = lifted[i];
      try { x.el.style.transform = x.transform; x.el.style.transition = x.transition; x.el.style.maxHeight = x.maxHeight; x.el.style.overflowY = x.overflowY; x.el.style.removeProperty('align-items'); if (x.alignItems) x.el.style.alignItems = x.alignItems; } catch (e) {}
    }
    lifted = [];
    unshrink();
    var room = document.getElementById('lu-kb-room'); if (room) room.parentNode.removeChild(room);   // KB-3 v10
  }

  /* An app-shaped document — html/body at 100%, nothing to scroll, panels pinned to the bottom (a chat composer, an
     editor bar) — cannot be scrolled into the visible area: on iPhone the keyboard lies over the bottom third of it.
     The only fix is the one Android does natively: give the layout the visible height. Done inline, put back on close. */
  var shrunk = null;
  var capped = [];          // [{el, height, maxHeight, minHeight}] — JS-sized panels capped to the visible area
  function shrink(v, a) {
    var layoutShrunk = window.innerHeight < baseH - 120;   // Android: the keyboard took its room from the layout
    if (v.kb <= 0 && !layoutShrunk) return;
    var se = document.scrollingElement || root;
    var appLike = se.scrollHeight <= window.innerHeight + 2;
    if (!appLike && !shrunk) return;
    var h = Math.round(v.bottom - v.top);
    if (!shrunk) { shrunk = { html: root.getAttribute('style'), body: document.body.getAttribute('style') }; }
    root.style.setProperty('height', h + 'px', 'important'); root.style.setProperty('min-height', '0', 'important'); root.style.setProperty('max-height', h + 'px', 'important');
    document.body.style.setProperty('height', h + 'px', 'important'); document.body.style.setProperty('min-height', '0', 'important'); document.body.style.setProperty('max-height', h + 'px', 'important');
    if (v.top > 0) { try { window.scrollTo(0, 0); } catch (e) {} }
    // Panels sized in pixels by script (a chat view = innerHeight − header) do not follow html's height: cap every
    // ancestor of the field that still runs past the visible bottom, so a composer pinned to its bottom comes up.
    if (a && a.el) {
      var p = a.el.parentElement, doc = a.doc, win = doc.defaultView, guard = 0;
      while (p && p !== doc.body && p !== doc.documentElement && guard++ < 25) {
        var r = p.getBoundingClientRect(), top = r.top + a.off.y;
        if (r.height > 120 && top < v.bottom - 100 && r.bottom + a.off.y > v.bottom - PAD && win.getComputedStyle(p).position !== 'fixed') {
          var already = false;
          for (var i = 0; i < capped.length; i++) if (capped[i].el === p) already = true;
          if (!already) capped.push({ el: p, height: p.style.height, maxHeight: p.style.maxHeight, minHeight: p.style.minHeight });
          var cap = Math.max(120, Math.round(v.bottom - PAD - top));
          p.style.setProperty('max-height', cap + 'px', 'important'); p.style.setProperty('height', cap + 'px', 'important'); p.style.setProperty('min-height', '0', 'important');
        }
        p = p.parentElement;
      }
    }
  }
  function unshrink() {
    for (var i = 0; i < capped.length; i++) {
      var c = capped[i];
      try { c.el.style.removeProperty('max-height'); c.el.style.removeProperty('height'); c.el.style.removeProperty('min-height');
            if (c.maxHeight) c.el.style.maxHeight = c.maxHeight; if (c.height) c.el.style.height = c.height; if (c.minHeight) c.el.style.minHeight = c.minHeight; } catch (e) {}
    }
    capped = [];
    if (!shrunk) return;
    try { if (shrunk.html === null) root.removeAttribute('style'); else root.setAttribute('style', shrunk.html); } catch (e) {}
    try { if (shrunk.body === null) document.body.removeAttribute('style'); else document.body.setAttribute('style', shrunk.body); } catch (e) {}
    shrunk = null;
  }

  function reveal() {
    var a = deepActive(), el = a.el;
    if (!isField(el)) return;
    var v = vis(), n = need(el, a.off, v);
    if (Math.abs(n) < 2) return;

    // 0. iPhone: the keyboard lies over a layout that has nowhere to scroll — shrink the layout to what is visible.
    //    Android: the layout shrank but a JS-pixel-sized panel (the editor, a chat view) did not — cap it the same way.
    if (v.kb > 0 || window.innerHeight < baseH - 120) { shrink(v, a); n = need(el, a.off, v); if (Math.abs(n) < 2) return; }

    // 1. Something fixed or sticky holds the field (composer bar, floater, dialog, sheet). When the keyboard
    //    covers the layout, move that container up by what is needed — no more than the room above it — and,
    //    if it is taller than what is visible, cap it and scroll inside it.
    var fx = fixedAncestor(el, a.doc);
    if (fx && n > 0) {
      var fr = fx.getBoundingClientRect(), fxTop = fr.top + a.off.y;
      var room = Math.max(0, fxTop - (v.top + PAD));
      var by = Math.min(n, room);
      if (by > 0) { lift(fx, by); n = need(el, a.off, v); }
      if (Math.abs(n) > 2 && fx.scrollHeight > fx.clientHeight + 2) { instantScroll(fx, n); n = need(el, a.off, v); }
      if (Math.abs(n) > 2) {
        var visibleH = (v.bottom - v.top) - 2 * PAD;
        if (fr.height > visibleH) {
          lift(fx, 0, visibleH);
          fr = fx.getBoundingClientRect(); fxTop = fr.top + a.off.y;
          if (fxTop < v.top + PAD || fxTop + visibleH > v.bottom - PAD) { lift(fx, fxTop - (v.top + PAD)); }
          try { el.scrollIntoView({ block: 'center', behavior: 'instant' }); } catch (e) {}
          n = need(el, a.off, v);
        }
      }
      if (Math.abs(n) < 2) return;
    }

    // 2. Scrollable ancestors, nearest first.
    var sp = scrollParent(el, a.doc), guard = 0;
    while (sp && Math.abs(n) > 2 && guard++ < 5) {
      var before = sp.scrollTop;
      instantScroll(sp, n);
      n = need(el, a.off, v);
      if (sp.scrollTop === before || Math.abs(n) > 2) sp = scrollParent(sp, a.doc);
    }
    if (Math.abs(n) < 2) return;

    // 3. The iframe's own document, then whatever scrolls around the iframe (an editor stage), then the window.
    if (a.frames.length) {
      try { var se = a.doc.scrollingElement || a.doc.documentElement; instantScroll(se, n, a.doc.defaultView); n = need(el, a.off, v); } catch (e) {}
      if (Math.abs(n) < 2) return;
      for (var fi = a.frames.length - 1; fi >= 0 && Math.abs(n) > 2; fi--) {
        var fdoc = a.frames[fi].ownerDocument, fsp = scrollParent(a.frames[fi], fdoc), fg = 0;
        while (fsp && Math.abs(n) > 2 && fg++ < 5) { var fb = fsp.scrollTop; instantScroll(fsp, n); n = need(el, a.off, v); if (fsp.scrollTop === fb || Math.abs(n) > 2) fsp = scrollParent(fsp, fdoc); }
      }
      if (Math.abs(n) < 2) return;
    }
    // KB-3 v10: a field near the end of the page has no page below it to scroll into view above the keyboard — make room
    if (n > 2 && v.kb > 0) {
      var room = document.getElementById('lu-kb-room');
      if (!room) { room = document.createElement('div'); room.id = 'lu-kb-room'; room.setAttribute('aria-hidden', 'true'); room.style.cssText = 'height:0;margin:0;padding:0;border:0;pointer-events:none;clear:both'; document.body.appendChild(room); }
      var want = Math.round(n + v.kb);
      if ((parseInt(room.style.height, 10) || 0) < want) room.style.height = want + 'px';
    }
    try { instantScroll(null, n, window); } catch (e) {}
    n = need(el, a.off, v);
    if (Math.abs(n) < 2) return;

    // 4. Last resort: let the browser do what it can.
    try { el.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'instant' }); } catch (e) {}
  }

  function apply() {
    var v = vis();
    if (window.innerHeight > baseH) baseH = window.innerHeight;
    var shrunk = window.innerHeight < baseH - 120;      // Android: the layout itself gave the keyboard its room
    var open = v.kb > 120 || shrunk;
    var a = deepActive();
    var hasField = isField(a.el);
    if (open && hasField) {
      root.classList.add('lu-kb-open');
      root.style.setProperty('--lu-kb', v.kb + 'px');
      root.style.setProperty('--lu-vvh', Math.round(v.bottom - v.top) + 'px');
      reveal();
    } else {
      if (!hasField || !open) { root.classList.remove('lu-kb-open'); root.style.removeProperty('--lu-kb'); restore(); }
      if (hasField && !open && v.kb === 0 && Date.now() - focusedAt < 1500) reveal();   // a field focused with no keyboard yet: still keep it in view
    }
  }

  function later() { clearTimeout(debounce); debounce = setTimeout(apply, 60); }
  var focusH = 0;   // innerHeight when the field took focus: if it never changes and vv never moves, nobody told us
  function assumeIfSilent() {
    var a = deepActive();
    if (!phone || !isField(a.el) || assumedKb > 0) return;
    var v = vis();
    /* KB-3 v9 (Owner 2026-09-26: "sometime when keyboard closes white background for the keyboard stays"): a field
       re-focused while Android's keyboard is ALREADY open (the composer after Send) saw no NEW signal and assumed a second,
       phantom keyboard; closing the real one grew the page back but nothing cleared the phantom. A layout already shrunk
       below its tallest height IS the signal. */
    var signalled = v.kb >= 60 || window.innerHeight < focusH - 60 || window.innerHeight < baseH - 120;
    if (signalled) return;
    var r = rectOf(a.el, a.off);
    if (r.bottom <= window.innerHeight * 0.55) return;   // high on the screen: even an unannounced keyboard leaves it visible
    assumedKb = Math.round(window.innerHeight * 0.42);
    apply();
  }
  function schedule() {
    ticks.forEach(clearTimeout); ticks = [];
    focusH = window.innerHeight;
    [60, 320, 700, 1200].forEach(function (ms) { ticks.push(setTimeout(apply, ms)); });
    ticks.push(setTimeout(assumeIfSilent, 650));
    ticks.push(setTimeout(assumeIfSilent, 1400));
  }

  document.addEventListener('focusin', function (e) { if (isField(e.target) || (e.target && e.target.tagName === 'IFRAME')) { focusedAt = Date.now(); schedule(); } }, true);
  document.addEventListener('focusout', function () { ticks.forEach(clearTimeout); ticks = []; assumedKb = 0; later(); }, true);
  var lastVvH = vv ? vv.height : 0;
  if (vv) { vv.addEventListener('resize', function () { if (vis().kb >= 60 || vv.height > lastVvH + 60) assumedKb = 0; lastVvH = vv.height; later(); }); vv.addEventListener('scroll', later); }
  var lastH = window.innerHeight;
  window.addEventListener('resize', function () {
    if (window.innerHeight < focusH - 60) assumedKb = 0;
    if (window.innerHeight > lastH + 60) assumedKb = 0;   // v9: the layout grew back — a keyboard closed; no phantom survives it
    lastH = window.innerHeight; later();
  });
  window.addEventListener('orientationchange', function () { baseH = 0; later(); });
  /* v8: the tab comes back (backgrounded with the keyboard open, bfcache, app switch) — nothing is focused any more, so
     the events that would have restored the page never fired. Re-check now, and again after the browser settles. */
  function backFromAway() {
    ticks.forEach(clearTimeout); ticks = [];
    var a = document.activeElement;
    if (!a || a === document.body || !(isField(a) || a.tagName === 'IFRAME')) { assumedKb = 0; }
    baseH = window.innerHeight; later(); setTimeout(later, 250); setTimeout(later, 900);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) backFromAway(); });
  window.addEventListener('pageshow', backFromAway);
  window.addEventListener('focus', backFromAway);

  // A field inside an iframe (the editor's preview) is focused without a focusin on this document: poll lightly while
  // an iframe holds focus so the keyboard opening is not missed on browsers that report it late.
  setInterval(function () { if (document.activeElement && document.activeElement.tagName === 'IFRAME') { var a = deepActive(); if (isField(a.el)) apply(); } }, 500);

  apply();
})();
