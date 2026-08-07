{{--
    Engineer888 — Chat.

    THE SERVER DECIDES EVERYTHING. This page renders what the API returns and
    nothing else: it computes no permissions, infers no workflow state, and
    issues no cards. If the server returns no action cards, none are drawn —
    there are no placeholders, no disabled buttons and no "approve" affordance
    waiting to be enabled, because a control that looks available implies an
    authority the holder may not have.

    Free text never approves. The composer posts to /chat/messages, which routes
    intent server-side; a message that reads like an approval comes back as a
    refusal explaining that a secure action card is required.
--}}

<style>
  .e8-wrap{display:flex;flex-direction:column;height:calc(100vh - 130px);gap:12px}
  .e8-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .e8-proj{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted)}
  .e8-proj select{background:#141824;color:#e6e9f2;border:1px solid #2a3145;border-radius:8px;padding:7px 10px;font-size:13px}
  .e8-pill{padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:.02em}
  .e8-pill.ok{background:rgba(52,211,153,.14);color:#34d399}
  .e8-pill.warn{background:rgba(251,191,36,.14);color:#fbbf24}
  .e8-log{flex:1;overflow-y:auto;border:1px solid #222839;border-radius:14px;padding:18px;background:#0e111a}
  .e8-msg{margin-bottom:16px;max-width:78%}
  .e8-msg.user{margin-left:auto}
  .e8-who{font-size:11px;color:var(--muted);margin-bottom:5px;letter-spacing:.04em;text-transform:uppercase}
  .e8-body{white-space:pre-wrap;line-height:1.6;font-size:14px;padding:12px 15px;border-radius:12px}
  .e8-msg.user .e8-body{background:#2b2f6b;color:#eef0ff}
  .e8-msg.engineer888 .e8-body{background:#161b28;border:1px solid #242c3e}
  .e8-msg.system .e8-body{background:transparent;border:1px dashed #2e3purple;border:1px dashed #2e3648;color:var(--muted);font-size:12.5px;font-style:italic}
  .e8-card{margin-top:10px;border:1px solid #2c3446;border-radius:12px;padding:13px 15px;background:#121722}
  .e8-card h4{margin:0 0 8px;font-size:13px;letter-spacing:.03em;text-transform:uppercase;color:#9aa4bf}
  .e8-kv{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;font-size:12.5px}
  .e8-kv span:nth-child(odd){color:var(--muted)}
  .e8-projlist{margin-top:9px;display:flex;gap:8px;flex-wrap:wrap}
  .e8-projlist button{background:#1d2333;border:1px solid #313a52;color:#dfe4f0;border-radius:8px;padding:6px 11px;font-size:12.5px;cursor:pointer}
  .e8-projlist button:hover{border-color:#5b6ef5}
  .e8-compose{display:flex;gap:10px}
  .e8-compose textarea{flex:1;background:#141824;color:#e6e9f2;border:1px solid #2a3145;border-radius:12px;padding:12px 14px;font-size:14px;resize:none;font-family:inherit;min-height:52px}
  .e8-compose button{background:var(--p);color:#fff;border:0;border-radius:12px;padding:0 22px;font-weight:600;cursor:pointer;font-size:14px}
  .e8-compose button:disabled{opacity:.5;cursor:default}
  .e8-note{font-size:12px;color:var(--muted)}
  .e8-empty{color:var(--muted);text-align:center;padding:40px 20px;font-size:13.5px;line-height:1.7}
  .e8-err{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.35);color:#fca5a5;border-radius:10px;padding:10px 13px;font-size:13px}
</style>

<div class="e8-wrap">

  <div class="e8-bar">
    <div class="e8-proj">
      <span>Active project</span>
      <select id="e8-project"><option value="">— none selected —</option></select>
      <span id="e8-projstate"></span>
    </div>
    <div style="margin-left:auto" class="e8-note">
      <span id="e8-unread"></span> · <a id="e8-cc" href="/admin/engineer888" style="color:var(--p);text-decoration:none">Command Center →</a>
    </div>
  </div>

  <div id="e8-error" class="e8-err" style="display:none"></div>

  <div class="e8-log" id="e8-log">
    <div class="e8-empty">Loading…</div>
  </div>

  <div class="e8-compose">
    <textarea id="e8-input" rows="2" placeholder="Ask Engineer888 to investigate something, or ask for status…"></textarea>
    <button id="e8-send">Send</button>
  </div>
  <div class="e8-note">
    Free text can create tasks and ask for status. It can never approve, execute or recover —
    those require a secure action card the server issues against exact evidence.
  </div>
</div>

<script>
// The Admin shell calls window.page() for the current page key. This view
// previously ran as a self-executing IIFE, so the shell found no renderer and
// logged "No renderer for page key e888Chat" on every load. Registering through
// the same mechanism every other Admin page uses — not a second mechanism.
window.page = async function () {
  var CONV = null, CURSOR = null, BUSY = false;

  // The card currently awaiting confirmation, if any.
  //
  // WHY THIS EXISTS. render() replaces the log's innerHTML, so a poll landing
  // mid-confirmation destroys the armed button and every character typed into
  // it. Rejection tolerated that — its reason is a short sentence. Approval
  // does not: the required statement is around 130 characters naming a uuid
  // and a 64-character fingerprint, and the poll interval is 15 seconds, so
  // the form was being wiped out from under the approver before they could
  // finish. Proven in the browser on 2026-08-07: armed, typed, and 16 seconds
  // later the textarea no longer existed.
  //
  // Polling pauses while a card is armed. Nothing else pauses: the server
  // still revalidates every binding on the press, and a card that expires or
  // is superseded while the form is open is refused there, not here.
  var ARMED = null;

  // Navigating away and back calls window.page() again. Without this the old
  // interval keeps running and every return doubles the polling rate.
  if (window.__e8ChatPoll) { clearInterval(window.__e8ChatPoll); window.__e8ChatPoll = null; }

  function el(id) { return document.getElementById(id); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function showError(msg) {
    var e = el('e8-error');
    if (!msg) { e.style.display = 'none'; return; }
    e.textContent = msg; e.style.display = 'block';
  }

  // Every card drawn here came from the server's own filtered list. The page
  // never decides whether an action is permitted, never calculates a
  // fingerprint, and never names an action the server did not send.
  //
  // The endpoint is derived from the STORED action_type the server returned.
  // The client does not send action_type anywhere — the route carries it, and
  // the server re-proves the stored card matches that route.
  var ACTION_ROUTE = {
    approve_candidate: 'approve-candidate',
    reject_candidate: 'reject-candidate',
    execute_task: 'execute-task',
    approve_recovery: 'approve-recovery',
    approve_migration: 'approve-migration',
    revoke_approval: 'revoke'
  };

  var ACTION_LABEL = {
    approve_candidate: 'Approve candidate',
    reject_candidate: 'Reject candidate',
    execute_task: 'Execute task',
    approve_recovery: 'Approve recovery',
    approve_migration: 'Approve migration',
    revoke_approval: 'Revoke approval'
  };

  var HIGH_RISK = ['approve_candidate', 'execute_task', 'approve_recovery', 'approve_migration'];

  // The exact sentence the server requires for each approval card, keyed by
  // card uuid. The page NEVER composes one: it displays what the server sent and
  // sends back only what the human typed. If the server sent none, the approval
  // cannot be armed at all.
  var REQUIRED_STATEMENT = {};

  function cardHtml(c) {
    var route = ACTION_ROUTE[c.action_type];
    if (!route) { return ''; }   // unknown type: draw nothing rather than guess

    if (c.required_statement) { REQUIRED_STATEMENT[c.uuid] = c.required_statement; }

    var kv = '';
    if (c.project) { kv += '<span>Project</span><span>' + esc(c.project) + '</span>'; }
    if (c.task_title) { kv += '<span>Task</span><span>' + esc(c.task_title) + '</span>'; }
    if (c.task_uuid) { kv += '<span>Task ref</span><span>' + esc(c.task_uuid.slice(0, 8)) + '</span>'; }
    if (c.candidate_uuid) { kv += '<span>Candidate</span><span>' + esc(c.candidate_uuid.slice(0, 8)) + '</span>'; }
    if (c.workflow_state) { kv += '<span>Workflow</span><span>' + esc(c.workflow_state) + '</span>'; }
    if (c.file_count !== null && c.file_count !== undefined) {
      kv += '<span>Files affected</span><span>' + esc(c.file_count) + '</span>';
    }
    kv += '<span>Unresolved</span><span>' + esc(c.unknown_count || 0) + '</span>';
    if (c.provider) { kv += '<span>Provider</span><span>' + esc(c.provider) + '</span>'; }
    if (c.expires_at) { kv += '<span>Expires</span><span>' + esc(c.expires_at) + '</span>'; }

    // MFA is stated honestly. It is not enrolled, so it is not protecting this.
    var mfaNote = '';
    if (HIGH_RISK.indexOf(c.action_type) !== -1 && !(c.mfa && c.mfa.satisfied)) {
      mfaNote = '<div class="e8-note" style="margin-top:8px;color:#fbbf24">'
        + 'MFA is not enrolled. The active safeguard is exact-fingerprint binding, '
        + 'revalidated by the server when you press this.</div>';
    }

    return '<div class="e8-card" data-card="' + esc(c.uuid) + '">'
      + '<h4>' + esc(ACTION_LABEL[c.action_type] || c.action_type) + '</h4>'
      + '<div class="e8-kv">' + kv + '</div>'
      + mfaNote
      + '<div class="e8-projlist" style="margin-top:11px">'
      + '<button data-act="' + esc(c.uuid) + '" data-route="' + esc(route) + '" data-label="'
      + esc(ACTION_LABEL[c.action_type] || c.action_type) + '">'
      + esc(ACTION_LABEL[c.action_type] || c.action_type) + '</button>'
      + '<a href="' + esc(c.command_center_url || '/admin/engineer888') + '" '
      + 'style="color:var(--p);font-size:12.5px;text-decoration:none;align-self:center">'
      + 'Open evidence in Command Center →</a>'
      + '</div>'
      + '<div class="e8-note" id="e8-res-' + esc(c.uuid) + '" style="margin-top:8px"></div>'
      + '</div>';
  }

  // The confirmation restates exactly what the server said, then requires a
  // second explicit click. No chat reply is ever accepted as consent.
  // Actions the domain requires a written reason for. A rejection with no
  // stated reason is an engineering decision with no record of why, and the
  // server refuses it — so the confirmation collects one rather than letting
  // the press fail at the boundary.
  var NEEDS_INSTRUCTION = ['reject-candidate'];

  // Approval is not a click. The approver retypes a sentence naming the exact
  // candidate and fingerprint, and the server compares it character for
  // character. Nothing here generates that sentence from a press — doing so
  // would turn the typed statement back into the click it exists to replace.
  var NEEDS_STATEMENT = ['approve-candidate'];

  async function pressCard(uuid, route, label, btn) {
    var box = document.getElementById('e8-res-' + uuid);
    var needs = NEEDS_INSTRUCTION.indexOf(route) !== -1;
    var needsStatement = NEEDS_STATEMENT.indexOf(route) !== -1;

    if (btn.getAttribute('data-armed') !== '1') {
      btn.setAttribute('data-armed', '1');
      btn.textContent = 'Confirm: ' + label;
      ARMED = uuid;               // hold the poll until this is resolved

      if (needsStatement) {
        var required = REQUIRED_STATEMENT[uuid];

        if (!required) {
          box.innerHTML = '<div style="color:#fca5a5">The server did not supply an approval statement '
            + 'for this card, so it cannot be approved from here. Refresh and try again.</div>';
          btn.removeAttribute('data-armed');
          btn.textContent = label;
          ARMED = null;
          return;
        }

        box.innerHTML = '<div style="margin-bottom:7px">Approving binds this exact candidate, this exact '
          + 'fingerprint and the exact file changes you reviewed. Type or paste the statement below to '
          + 'confirm you are approving those bytes and nothing else.</div>'
          + '<div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:7px">'
          + '<code id="e8-req-' + esc(uuid) + '" style="flex:1;display:block;background:#0b0f18;'
          + 'border:1px solid #2a3145;border-radius:8px;padding:8px 10px;font-size:12px;'
          + 'line-height:1.5;word-break:break-all;color:#c9d3ea">' + esc(required) + '</code>'
          + '<button type="button" data-copy="' + esc(uuid) + '" style="background:#1d2333;'
          + 'border:1px solid #313a52;color:#dfe4f0;border-radius:8px;padding:6px 11px;'
          + 'font-size:12.5px;cursor:pointer;white-space:nowrap">Copy</button></div>'
          + '<textarea id="e8-stmt-' + esc(uuid) + '" rows="3" '
          + 'style="width:100%;background:#141824;color:#e6e9f2;border:1px solid #2a3145;'
          + 'border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit" '
          + 'placeholder="Type or paste the statement above, exactly."></textarea>'
          + '<div style="margin-top:7px;margin-bottom:4px">Comment (optional) — recorded against the approval.</div>'
          + '<textarea id="e8-cmt-' + esc(uuid) + '" rows="2" '
          + 'style="width:100%;background:#141824;color:#e6e9f2;border:1px solid #2a3145;'
          + 'border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit" '
          + 'placeholder="e.g. Reviewed the full diff and the test it adds."></textarea>'
          + '<div class="e8-note" style="margin-top:7px">Live updates are paused while this is open, so '
          + 'nothing rewrites what you are typing. The server still revalidates every binding when you confirm.</div>';

        var copyBtn = box.querySelector('[data-copy]');
        if (copyBtn) {
          copyBtn.onclick = function () {
            var t = REQUIRED_STATEMENT[uuid] || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(t).then(function () {
                copyBtn.textContent = 'Copied';
                setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1500);
              });
              return;
            }
            // No clipboard API (insecure context): select it so it can be
            // copied by hand rather than pretending the copy succeeded.
            var r = document.createRange();
            r.selectNodeContents(document.getElementById('e8-req-' + uuid));
            var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
          };
        }

        var st = document.getElementById('e8-stmt-' + uuid);
        if (st) { st.focus(); }
        return;
      }

      if (needs) {
        box.innerHTML = '<div style="margin-bottom:6px">State why you are rejecting this. '
          + 'The reason is recorded against the engineering decision.</div>'
          + '<textarea id="e8-instr-' + esc(uuid) + '" rows="2" '
          + 'style="width:100%;background:#141824;color:#e6e9f2;border:1px solid #2a3145;'
          + 'border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit" '
          + 'placeholder="e.g. Candidate proposes no file changes; nothing to implement."></textarea>';
        var ta = document.getElementById('e8-instr-' + uuid);
        if (ta) { ta.focus(); }
      } else {
        box.textContent = 'Press again to send this to the server. It will revalidate every binding before acting.';
      }
      return;
    }

    var body = {};

    if (needsStatement) {
      var sfield = document.getElementById('e8-stmt-' + uuid);
      var typed = sfield ? sfield.value : '';

      if (!typed.trim()) {
        box.innerHTML = '<div style="color:#fca5a5">The approval statement is required. '
          + 'The server will refuse an approval that does not state exactly what is being approved.</div>';
        if (sfield) { sfield.focus(); }
        return;
      }

      // Sent as typed. Only the server decides whether it matches, and the only
      // forgiveness is its own trim — this page normalises nothing.
      body.statement = typed;

      var cfield = document.getElementById('e8-cmt-' + uuid);
      var comment = cfield ? cfield.value.trim() : '';
      if (comment) { body.comment = comment; }
    }

    if (needs) {
      var field = document.getElementById('e8-instr-' + uuid);
      var instruction = field ? field.value.trim() : '';

      if (!instruction) {
        box.innerHTML = '<div style="color:#fca5a5">A reason is required. '
          + 'The server will refuse a rejection that does not say why.</div>' + (field ? '' : '');
        if (field) { field.focus(); }
        return;
      }

      body.instruction = instruction;
    }

    btn.disabled = true;
    box.textContent = 'Sending…';

    // No action_type in the body. The route carries the authority.
    var d = await api('/engineer888/chat/actions/' + uuid + '/' + route, 'POST', body);

    if (!d) {
      box.textContent = 'The server refused this action. Refreshing state.';
      btn.disabled = false;
      btn.removeAttribute('data-armed');
      btn.textContent = label;
      ARMED = null;               // decision resolved; polling may resume
      await refresh(true);
      return;
    }

    box.textContent = 'Accepted: ' + (d.result || 'done') + ' at ' + (d.consumed_at || '');
    ARMED = null;
    await refresh(true);
  }

  function messageHtml(m) {
    var meta = m.metadata || {};
    var extra = '';

    if (meta.kind === 'task_created') {
      extra = '<div class="e8-card"><h4>Engineering task</h4><div class="e8-kv">'
        + '<span>Task</span><span>' + esc((m.task_uuid || '').slice(0, 8)) + '</span>'
        + '<span>Project</span><span>' + esc(meta.project_name || '—') + '</span>'
        + '</div><div style="margin-top:9px"><a href="/admin/engineer888" style="color:var(--p);font-size:12.5px;text-decoration:none">Open in Command Center →</a></div></div>';
    }

    if (meta.kind === 'project_selection_required') {
      var btns = (meta.projects || []).map(function (p) {
        return '<button data-projkey="' + esc(p.key) + '">' + esc(p.name) + '</button>';
      }).join('');
      extra = '<div class="e8-card"><h4>Project required</h4>'
        + '<div class="e8-note">No task was created. Select where the work belongs:</div>'
        + '<div class="e8-projlist">' + btns + '</div></div>';
    }

    return '<div class="e8-msg ' + esc(m.role) + '">'
      + '<div class="e8-who">' + esc(m.role === 'user' ? 'You' : m.role === 'system' ? 'System' : 'Engineer888') + '</div>'
      + '<div class="e8-body">' + esc(m.body) + '</div>' + extra + '</div>';
  }

  function render(messages, cards) {
    var log = el('e8-log');
    if (!messages.length) {
      log.innerHTML = '<div class="e8-empty">No messages yet.<br>Ask Engineer888 to investigate something.</div>';
      return;
    }
    log.innerHTML = messages.map(messageHtml).join('')
      + (cards && cards.length ? cards.map(cardHtml).join('') : '');
    log.scrollTop = log.scrollHeight;

    Array.prototype.forEach.call(log.querySelectorAll('[data-projkey]'), function (b) {
      b.onclick = function () { selectProject(b.getAttribute('data-projkey')); };
    });

    Array.prototype.forEach.call(log.querySelectorAll('[data-act]'), function (b) {
      b.onclick = function () {
        pressCard(b.getAttribute('data-act'), b.getAttribute('data-route'), b.getAttribute('data-label'), b);
      };
    });
  }

  function applyConversation(conv, projects) {
    CONV = conv;
    var sel = el('e8-project');
    if (projects) {
      sel.innerHTML = '<option value="">— none selected —</option>'
        + projects.map(function (p) { return '<option value="' + esc(p.key) + '">' + esc(p.name) + '</option>'; }).join('');
    }
    var active = conv.active_project;
    sel.value = active ? active.key : '';
    el('e8-projstate').innerHTML = active
      ? '<span class="e8-pill ok">selected</span>'
      : '<span class="e8-pill warn">none — task creation blocked</span>';
  }

  async function bootstrap() {
    try {
      var d = await api('/engineer888/chat/bootstrap');
      if (!d) { showError('Chat bootstrap failed. See console.'); return; }
      showError(null);
      applyConversation(d.conversation, d.projects);
      el('e8-unread').textContent = (d.unread || 0) + ' unread';
      var h = await api('/engineer888/chat/messages');
      if (h) { CURSOR = h.cursor; render(h.messages || [], d.cards || []); }
    } catch (e) { showError('Chat failed to load: ' + e.message); }
  }

  async function selectProject(key) {
    if (!key) { return; }
    var d = await api('/engineer888/chat/project', 'POST', { project_key: key });
    if (!d) { showError('That project could not be selected.'); return; }
    showError(null);
    applyConversation(d.conversation, null);
    await refresh(true);
  }

  async function refresh(full) {
    var h = await api('/engineer888/chat/messages');
    if (!h) { return; }
    CURSOR = h.cursor;
    var ev = await api('/engineer888/chat/events' + (CURSOR ? '?cursor=' + CURSOR : ''));
    render(h.messages || [], (ev && ev.cards) || []);
    if (ev) { el('e8-unread').textContent = (ev.unread || 0) + ' unread'; }
  }

  async function send() {
    var box = el('e8-input'), body = box.value.trim();
    if (!body || BUSY) { return; }
    BUSY = true; el('e8-send').disabled = true;
    try {
      var d = await api('/engineer888/chat/messages', 'POST', { body: body });
      if (!d) { showError('Message failed to send.'); return; }
      showError(null);
      box.value = '';
      if (d.conversation) { applyConversation(d.conversation, null); }
      await refresh(true);
    } finally { BUSY = false; el('e8-send').disabled = false; }
  }

  el('e8-send').onclick = send;
  el('e8-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { send(); }
  });
  el('e8-project').onchange = function () { selectProject(this.value); };

  await bootstrap();

  // Stored on window, not in closure scope, so the guard above can find and
  // clear it when the page is re-entered.
  window.__e8ChatPoll = setInterval(function () {
    // ARMED holds the poll while a confirmation is open, so a re-render cannot
    // destroy a statement the approver is part-way through typing.
    if (!BUSY && !ARMED) { refresh(false); }
  }, 15000);
};
</script>
