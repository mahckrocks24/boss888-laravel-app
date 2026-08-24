/*
 * MISSION-018 WS-4 (2026-08-24) — "meet Sarah" onboarding chat UI.
 * Replaces the Owner-rejected five-step quiz (MISSION-018 §7). Talks to the
 * real-LLM interview endpoints (POST /api/onboarding/interview/open|message);
 * the quiz remains as _renderOnboardingStep2 and this file falls back to
 * it if the interview endpoint is unreachable, so a Runtime outage can never
 * strand a new customer at signup.
 *
 * Self-contained: no build step, no framework, matches the SPA's global-window
 * idiom. Bearer token from localStorage.lu_token, same as the quiz.
 */
(function () {
  var _iv = { history: [], busy: false, sufficient: false };

  function _ivToken() { return localStorage.getItem('lu_token') || ''; }

  function _ivEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function _ivPost(path, body) {
    return fetch('/api/onboarding/interview/' + path, {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + _ivToken(),
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }

  function _ivShell() {
    return '' +
      '<div class="iv-wrap" style="max-width:640px;margin:0 auto;display:flex;flex-direction:column;height:100vh;padding:24px 16px;box-sizing:border-box">' +
      '  <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">' +
      '    <div style="width:40px;height:40px;border-radius:50%;background:#6C5CE7;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-family:sans-serif">S</div>' +
      '    <div><div style="font-weight:700;font-family:sans-serif">Sarah</div>' +
      '    <div style="font-size:12px;color:#888;font-family:sans-serif">Your Digital Marketing Manager</div></div>' +
      '  </div>' +
      '  <div id="iv-thread" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:12px;padding:8px 0"></div>' +
      '  <div id="iv-progress" style="font-size:12px;color:#888;font-family:sans-serif;min-height:16px;margin:4px 0"></div>' +
      '  <form id="iv-form" style="display:flex;gap:8px;margin-top:8px">' +
      '    <input id="iv-input" autocomplete="off" placeholder="Type your reply…" ' +
      '      style="flex:1;padding:12px 14px;border:1px solid #ddd;border-radius:8px;font-size:15px;font-family:sans-serif" />' +
      '    <button id="iv-send" type="submit" ' +
      '      style="padding:12px 20px;background:#6C5CE7;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-family:sans-serif">Send</button>' +
      '  </form>' +
      '</div>';
  }

  function _ivBubble(who, text) {
    var mine = who === 'user';
    var wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;justify-content:' + (mine ? 'flex-end' : 'flex-start');
    wrap.innerHTML = '<div style="max-width:80%;padding:10px 14px;border-radius:14px;font-size:15px;' +
      'font-family:sans-serif;line-height:1.45;white-space:pre-wrap;' +
      (mine ? 'background:#6C5CE7;color:#fff;border-bottom-right-radius:4px' :
              'background:#f1f0f5;color:#222;border-bottom-left-radius:4px') + '">' + _ivEsc(text) + '</div>';
    return wrap;
  }

  function _ivAppend(who, text) {
    var thread = document.getElementById('iv-thread');
    if (!thread) return;
    thread.appendChild(_ivBubble(who, text));
    thread.scrollTop = thread.scrollHeight;
  }

  function _ivTyping(on) {
    var p = document.getElementById('iv-progress');
    if (p) p.textContent = on ? 'Sarah is thinking…' : '';
    var b = document.getElementById('iv-send');
    var i = document.getElementById('iv-input');
    if (b) b.disabled = on;
    if (i) i.disabled = on;
    _iv.busy = on;
  }

  function _ivProgress(recognised) {
    if (!recognised || !recognised.length) return;
    var p = document.getElementById('iv-progress');
    if (p) p.textContent = 'Noted: ' + recognised.map(function (r) {
      return String(r.key || '').replace(/_/g, ' ');
    }).join(' · ');
  }

  function _ivFinish() {
    _iv.sufficient = true;
    var p = document.getElementById('iv-progress');
    if (p) p.textContent = "Great — I have what I need to get started.";
    // Hand off to whatever the app does after onboarding. complete() marks the
    // workspace onboarded and routes into the app; guarded so a missing helper
    // does not strand the customer on the chat.
    setTimeout(function () {
      try {
        if (typeof _ob2Submit === 'function' && window._IV_USE_LEGACY_COMPLETE) { _ob2Submit(); return; }
        if (typeof completeOnboarding === 'function') { completeOnboarding(); return; }
        if (typeof nav === 'function') { nav('workspace'); return; }
        window.location.href = '/app';
      } catch (e) { window.location.href = '/app'; }
    }, 1200);
  }

  function _ivHandleTurn(res) {
    _ivTyping(false);
    if (!res || res.ok === false || !res.reply) {
      _ivAppend('agent', (res && res.reply) || "Sorry — I lost that. Could you say it once more?");
      return;
    }
    _iv.history.push({ role: 'agent', content: res.reply });
    _ivAppend('agent', res.reply);
    _ivProgress(res.recognised);
    if (res.sufficient) _ivFinish();
  }

  function _ivSend(message) {
    if (_iv.busy || !message) return;
    _iv.history.push({ role: 'user', content: message });
    _ivAppend('user', message);
    _ivTyping(true);
    _ivPost('message', { message: message, history: _iv.history.slice(0, -1) })
      .then(_ivHandleTurn)
      .catch(function () {
        _ivTyping(false);
        _ivAppend('agent', "I had trouble reaching my tools just now — one moment.");
      });
  }

  // Public entry — called from the SPA in place of the quiz.
  window._renderMeetSarah = function () {
    var root = document.getElementById('lu-auth-root');
    if (!root) { if (typeof _renderOnboardingStep2 === 'function') _renderOnboardingStep2({}); return; }
    root.style.display = 'flex';
    var appShell = document.querySelector('.app');
    if (appShell) appShell.style.display = 'none';
    root.innerHTML = _ivShell();
    _iv = { history: [], busy: false, sufficient: false };

    var form = document.getElementById('iv-form');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var input = document.getElementById('iv-input');
      var v = (input.value || '').trim();
      if (!v) return;
      input.value = '';
      _ivSend(v);
    });

    _ivTyping(true);
    _ivPost('open', {})
      .then(function (res) {
        _ivTyping(false);
        if (!res || res.ok === false || !res.reply) {
          // Runtime unreachable — fall back to the quiz rather than a dead chat.
          if (typeof _renderOnboardingStep2 === 'function') { _renderOnboardingStep2({}); return; }
          _ivAppend('agent', "Hi, I'm Sarah. Let's get started — tell me about your business.");
          return;
        }
        _iv.history.push({ role: 'agent', content: res.reply });
        _ivAppend('agent', res.reply);
        var i = document.getElementById('iv-input'); if (i) i.focus();
      })
      .catch(function () {
        _ivTyping(false);
        if (typeof _renderOnboardingStep2 === 'function') _renderOnboardingStep2({});
      });
  };
})();
