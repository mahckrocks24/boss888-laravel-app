{{--
    Engineer888 - Chat.

    THE SERVER DECIDES EVERYTHING. This page renders what the API returns and
    nothing else: it computes no permissions, infers no workflow state, and
    issues no cards. If the server returns no action cards, none are drawn:
    there are no placeholders, no disabled buttons and no "approve" affordance
    waiting to be enabled, because a control that looks available implies an
    authority the holder may not have.

    Free text never approves. The composer posts to /chat/messages, which routes
    intent server-side; a message that reads like an approval comes back as a
    refusal explaining that a secure action card is required.

    WHY THIS WAS REBUILT (2026-08-13)
    The previous version appended EVERY live action card after EVERY message
    and then ran `log.scrollTop = log.scrollHeight`. visibleFor() returns up to
    50 cards; at roughly 322px each that is about 16,000px of cards below the
    conversation. Sending a message scrolled the viewport to the bottom of that
    stack, roughly twenty screens past the message that had just been sent, so
    the message looked like it had vanished. It had not: it was persisted,
    returned by the API, and drawn into the DOM. It was scrolled past.

    Three things changed, and none of them touch governance:
      1. The user's message is drawn optimistically the instant it is sent and
         is never removed by a poll; a failed send marks it and offers Retry.
      2. Cards no longer live at the bottom of the log. Cards for the active
         project attach to the conversation; everything else collapses into
         one line the reader can open.
      3. Scrolling targets the last MESSAGE, not the end of the document.

    This file is deliberately ASCII-only. It is shipped over SSH and has been
    corrupted once by a UTF-8 re-encode in transit.
--}}

<style>
  /* Scoped to this page. `e8c-` = Engineer888 chat. */
  .e8c{--e8c-gap:20px;--e8c-read:760px;display:flex;flex-direction:column;
       height:calc(100vh - 118px);background:#0b0e15;border-radius:16px;overflow:hidden;
       border:1px solid #1b2130}

  /* header: compact, 58px */
  .e8c-hd{display:flex;align-items:center;gap:14px;padding:0 20px;height:58px;flex:0 0 58px;
          border-bottom:1px solid #1b2130;background:#0d1119}
  .e8c-id{display:flex;align-items:center;gap:9px;font-weight:650;font-size:14.5px;color:#eef1f8}
  .e8c-orb{width:22px;height:22px;border-radius:50%;flex:0 0 22px;
           background:linear-gradient(135deg,#7C5CFF,#4f8cff);box-shadow:0 0 0 3px rgba(124,92,255,.14)}
  .e8c-state{display:flex;align-items:center;gap:6px;font-size:12px;color:#8d97ad}
  .e8c-dot{width:6px;height:6px;border-radius:50%;background:#4b5568;flex:0 0 6px}
  .e8c-dot.busy{background:#fbbf24;animation:e8cpulse 1.4s ease-in-out infinite}
  .e8c-dot.ok{background:#34d399}
  .e8c-dot.warn{background:#fbbf24}
  @keyframes e8cpulse{0%,100%{opacity:.35}50%{opacity:1}}
  .e8c-hd-r{margin-left:auto;display:flex;align-items:center;gap:10px}
  .e8c-proj{background:#131826;color:#dbe1ef;border:1px solid #252d40;border-radius:9px;
            padding:6px 10px;font-size:12.5px;font-family:inherit;max-width:230px}
  .e8c-lnk{color:#8d97ad;font-size:12.5px;text-decoration:none;padding:6px 9px;border-radius:8px}
  .e8c-lnk:hover{color:#dbe1ef;background:#151b28}
  /* The decision counter. Quiet by design: it states that something is waiting
     and gets out of the way. It is hidden entirely at zero - an empty badge is
     noise that trains the reader to ignore the badge. */
  .e8c-dec{background:rgba(91,110,245,.13);color:#93a3f7;border:1px solid rgba(91,110,245,.3);
           border-radius:999px;padding:5px 12px;font-size:12.5px;font-weight:600;cursor:pointer;
           font-family:inherit;white-space:nowrap}
  .e8c-dec:hover{background:rgba(91,110,245,.22);color:#c3ccff}

  /* conversation */
  .e8c-log{flex:1;overflow-y:auto;overflow-x:hidden;padding:26px 20px 8px}
  .e8c-thread{max-width:var(--e8c-read);margin:0 auto;display:flex;flex-direction:column;gap:var(--e8c-gap)}
  .e8c-m{display:flex;flex-direction:column;gap:6px;animation:e8cin .22s ease-out}
  @keyframes e8cin{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
  .e8c-m.user{align-items:flex-end}
  .e8c-who{font-size:11px;color:#6f798f;letter-spacing:.03em;padding:0 3px}
  .e8c-txt{white-space:pre-wrap;line-height:1.68;font-size:14.5px;color:#dde3f0;
           max-width:100%;word-wrap:break-word;overflow-wrap:anywhere}
  .e8c-m.user .e8c-txt{background:#232a63;color:#eef0ff;padding:11px 15px;
                       border-radius:15px 15px 4px 15px;max-width:82%}
  .e8c-m.engineer888 .e8c-txt{padding:1px 3px}
  .e8c-m.system .e8c-txt{color:#6f798f;font-size:12.5px;font-style:italic;text-align:center;width:100%}
  .e8c-m.pending .e8c-txt{opacity:.55}
  .e8c-fail{font-size:12px;color:#fca5a5;display:flex;align-items:center;gap:9px;padding:0 3px}
  .e8c-fail button{background:none;border:1px solid #5b2b2b;color:#fca5a5;border-radius:7px;
                   padding:3px 9px;font-size:12px;cursor:pointer;font-family:inherit}
  .e8c-fail button:hover{background:#2a1618}

  /* inline action card: compact, human first */
  .e8c-card{border:1px solid #262f44;border-radius:13px;background:#0f1420;overflow:hidden;max-width:100%}
  .e8c-card-b{padding:14px 16px}
  .e8c-card-t{font-size:12px;color:#8d97ad;letter-spacing:.03em;margin-bottom:7px}
  .e8c-card-h{font-size:14.5px;color:#eef1f8;font-weight:600;margin-bottom:3px}
  .e8c-card-s{font-size:12.5px;color:#8d97ad;margin-bottom:11px}
  .e8c-acts{display:flex;gap:9px;align-items:center;flex-wrap:wrap}
  .e8c-btn{background:#5b6ef5;color:#fff;border:0;border-radius:9px;padding:8px 15px;
           font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
  .e8c-btn:hover{background:#6b7cf7}
  .e8c-btn:disabled{opacity:.5;cursor:default}
  .e8c-btn.g{background:#159a63}
  .e8c-btn.g:hover{background:#18ad70}
  .e8c-btn.q{background:transparent;color:#8d97ad;border:1px solid #262f44}
  .e8c-btn.q:hover{color:#dbe1ef;border-color:#3a445e}
  .e8c-res{font-size:12.5px;color:#8d97ad;margin-top:9px}

  /* collapsed "other pending actions" */
  .e8c-more{max-width:var(--e8c-read);margin:4px auto 0;font-size:12.5px}
  .e8c-more summary{cursor:pointer;color:#8d97ad;padding:9px 12px;border:1px dashed #262f44;
                    border-radius:10px;list-style:none}
  .e8c-more summary::-webkit-details-marker{display:none}
  .e8c-more summary:hover{color:#dbe1ef;border-color:#3a445e}
  .e8c-more[open] summary{margin-bottom:11px}
  .e8c-more-l{display:flex;flex-direction:column;gap:9px}

  /* review drawer */
  .e8c-scrim{position:fixed;inset:0;background:rgba(4,6,11,.62);z-index:900;
             opacity:0;pointer-events:none;transition:opacity .18s}
  .e8c-scrim.on{opacity:1;pointer-events:auto}
  .e8c-dw{position:fixed;top:0;right:0;bottom:0;width:min(920px,94vw);background:#0d1119;
          border-left:1px solid #1f2637;z-index:901;transform:translateX(100%);
          transition:transform .24s cubic-bezier(.32,.72,0,1);display:flex;flex-direction:column}
  .e8c-dw.on{transform:none}
  .e8c-dw-h{display:flex;align-items:center;gap:12px;padding:0 22px;height:60px;flex:0 0 60px;
            border-bottom:1px solid #1f2637}
  .e8c-dw-h h3{margin:0;font-size:15px;color:#eef1f8;font-weight:650}
  .e8c-x{margin-left:auto;background:none;border:0;color:#8d97ad;font-size:22px;cursor:pointer;
         line-height:1;padding:4px 8px;border-radius:8px}
  .e8c-x:hover{color:#eef1f8;background:#161d2b}
  .e8c-dw-b{flex:1;overflow-y:auto;padding:22px}
  .e8c-sec{margin-bottom:26px;max-width:100%}
  .e8c-sec h4{margin:0 0 9px;font-size:11.5px;letter-spacing:.05em;text-transform:uppercase;color:#6f798f}
  .e8c-sec p{margin:0 0 9px;font-size:13.5px;line-height:1.68;color:#c8d1e3}
  .e8c-file{border:1px solid #222a3c;border-radius:11px;margin-bottom:10px;overflow:hidden}
  .e8c-file summary{cursor:pointer;padding:11px 14px;display:flex;align-items:center;gap:10px;
                    list-style:none;background:#101623}
  .e8c-file summary::-webkit-details-marker{display:none}
  .e8c-file summary:hover{background:#131a29}
  .e8c-file-p{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;color:#dbe1ef}
  .e8c-tag{font-size:10.5px;padding:2px 7px;border-radius:5px;letter-spacing:.03em;font-weight:600}
  .e8c-tag.c{background:rgba(52,211,153,.14);color:#34d399}
  .e8c-tag.u{background:rgba(251,191,36,.14);color:#fbbf24}
  .e8c-tag.n{background:rgba(141,151,173,.13);color:#8d97ad;font-weight:500}
  .e8c-code{margin:0;padding:14px;background:#080b12;overflow-x:auto;font-size:12px;line-height:1.62;
            font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#c8d1e3;max-height:460px}
  .e8c-code .a{color:#34d399}
  .e8c-code .d{color:#fca5a5}
  .e8c-code .h{color:#6f798f}
  .e8c-tech summary{cursor:pointer;color:#6f798f;font-size:12.5px;list-style:none;padding:9px 0}
  .e8c-tech summary::-webkit-details-marker{display:none}
  .e8c-tech summary:hover{color:#b6c0d6}
  .e8c-kv{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;font-size:12px;
          font-family:ui-monospace,SFMono-Regular,Menlo,monospace;padding:11px 0}
  .e8c-kv span:nth-child(odd){color:#6f798f;font-family:inherit}
  .e8c-kv span:nth-child(even){color:#b6c0d6;word-break:break-all}
  .e8c-ap{border:1px solid #1c5f43;background:rgba(21,154,99,.07);border-radius:13px;padding:17px}
  .e8c-ap h4{color:#34d399}
  .e8c-stmt{display:block;background:#080b12;border:1px solid #222a3c;border-radius:9px;
            padding:11px 13px;font-size:12px;line-height:1.6;word-break:break-all;color:#c8d1e3;
            font-family:ui-monospace,SFMono-Regular,Menlo,monospace;margin-bottom:9px}
  .e8c-ta{width:100%;background:#0f1420;color:#e6e9f2;border:1px solid #252d40;border-radius:9px;
          padding:10px 12px;font-size:13px;font-family:inherit;resize:vertical}

  /* composer */
  .e8c-cp{flex:0 0 auto;padding:12px 20px 14px;border-top:1px solid #1b2130;background:#0d1119}
  .e8c-cp-i{max-width:var(--e8c-read);margin:0 auto;display:flex;align-items:flex-end;gap:10px;
            background:#131826;border:1px solid #252d40;border-radius:15px;padding:9px 9px 9px 15px}
  .e8c-cp-i:focus-within{border-color:#3d4a6b}
  .e8c-cp textarea{flex:1;background:none;color:#e6e9f2;border:0;outline:0;font-size:14.5px;
                   font-family:inherit;resize:none;line-height:1.6;padding:5px 0;max-height:190px}
  .e8c-cp textarea::placeholder{color:#5c667d}
  .e8c-snd{width:34px;height:34px;flex:0 0 34px;border-radius:10px;border:0;background:#5b6ef5;
           color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center}
  .e8c-snd:hover{background:#6b7cf7}
  .e8c-snd:disabled{opacity:.35;cursor:default}
  .e8c-hint{max-width:var(--e8c-read);margin:8px auto 0;font-size:11.5px;color:#5c667d;text-align:center}

  /* status strip: quiet */
  .e8c-sb{display:flex;align-items:center;gap:16px;padding:0 20px;height:30px;flex:0 0 30px;
          border-top:1px solid #161c29;background:#0a0d14;font-size:11px;color:#5c667d;overflow-x:auto}
  .e8c-sb b{font-weight:500;color:#8d97ad}
  .e8c-err{max-width:var(--e8c-read);margin:0 auto 14px;background:rgba(248,113,113,.09);
           border:1px solid rgba(248,113,113,.3);color:#fca5a5;border-radius:10px;
           padding:10px 13px;font-size:13px}
  .e8c-empty{max-width:var(--e8c-read);margin:0 auto;color:#6f798f;text-align:center;
             padding:56px 20px;font-size:13.5px;line-height:1.8}

  /* Engineer888 is the assistant on this page. Bella stays available but stops
     competing for attention. The class is removed when the page is left. */
  body.e8c-active .bella-toggle{opacity:.28;transform:scale(.82);transition:opacity .2s,transform .2s}
  body.e8c-active .bella-toggle:hover{opacity:1;transform:none}

  @media (max-width:820px){
    .e8c{height:calc(100vh - 96px)}
    .e8c-hd{flex-wrap:wrap;height:auto;flex:0 0 auto;padding:10px 14px;gap:9px}
    .e8c-hd-r{width:100%;margin-left:0}
    .e8c-proj{flex:1;max-width:none}
    .e8c-log{padding:18px 14px 8px}
    .e8c-m.user .e8c-txt{max-width:92%}
    .e8c-dw{width:100%}
    .e8c-sb{gap:12px}
  }
</style>

{{--
    THE SHELL'S CONTRACT. shell.blade.php does @include($viewPath) AFTER the
    .main container closes, so any raw markup a page view emits lands as a
    direct child of <body> - which is display:flex - and becomes a third column
    beside the sidebar and .main, while #content sits there still reading
    "Loading...". The previous chat view emitted its markup raw and did exactly
    that. A page view is supposed to define window.page and mount into
    content(); this template is inert until window.page clones it there.
--}}
<template id="e8c-tpl">
<div class="e8c">

  <div class="e8c-hd">
    <div class="e8c-id"><span class="e8c-orb"></span>Engineer888</div>
    <div class="e8c-state"><span class="e8c-dot" id="e8c-dot"></span><span id="e8c-status">Idle</span></div>
    <div class="e8c-hd-r">
      <button class="e8c-dec" id="e8c-decisions" style="display:none"></button>
      <select class="e8c-proj" id="e8c-project"><option value="">Select a project...</option></select>
      <a class="e8c-lnk" href="/admin/engineer888">Evidence</a>
    </div>
  </div>

  <div class="e8c-log" id="e8c-log">
    <div class="e8c-empty">Loading...</div>
  </div>

  <div class="e8c-cp">
    <div id="e8c-error" class="e8c-err" style="display:none"></div>
    <div class="e8c-cp-i">
      <textarea id="e8c-input" rows="1" placeholder="Message Engineer888..."></textarea>
      <button class="e8c-snd" id="e8c-send" title="Send">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 19V5M5 12l7-7 7 7"/></svg>
      </button>
    </div>
    <div class="e8c-hint">
      Engineer888 can plan, build and verify. It can never approve, execute or recover from a typed
      message - those need a card the server issues against exact evidence.
    </div>
  </div>

  <div class="e8c-sb" id="e8c-sb"></div>
</div>

<div class="e8c-scrim" id="e8c-scrim"></div>
<aside class="e8c-dw" id="e8c-dw" role="dialog" aria-label="Candidate review">
  <div class="e8c-dw-h">
    <h3 id="e8c-dw-t">Review</h3>
    <button class="e8c-x" id="e8c-dw-x" title="Close">&times;</button>
  </div>
  <div class="e8c-dw-b" id="e8c-dw-b"></div>
</aside>
</template>

<script>
window.page = async function () {
  var CONV = null, CURSOR = null, BUSY = false, ACTIVE = null;

  // Messages the server has not yet acknowledged. render() re-emits these after
  // the persisted list, so a poll can never erase what the user just typed. An
  // entry leaves only when the same body comes back from the server, or when
  // the user discards a failure.
  var PENDING = [];

  // A card confirmation is open. Polling pauses so a re-render cannot destroy a
  // statement being typed. Proven necessary in the browser on 2026-08-07.
  var ARMED = null;

  if (window.__e8ChatPoll) { clearInterval(window.__e8ChatPoll); window.__e8ChatPoll = null; }
  document.body.classList.add('e8c-active');

  /* ---- mount into the shell's #content, per shell.blade.php's contract ---- */

  var tpl = document.getElementById('e8c-tpl');
  if (!tpl) { content().innerHTML = '<div class="card" style="padding:32px">Chat template missing.</div>'; return; }
  var frag = tpl.content.cloneNode(true);

  // The drawer and its scrim are position:fixed and must not inherit a
  // transformed or clipped ancestor, so they are hoisted to <body> rather than
  // left inside #content. Re-entering the page replaces them rather than
  // stacking a second copy.
  var oldDw = document.getElementById('e8c-dw'), oldScrim = document.getElementById('e8c-scrim');
  if (oldDw) { oldDw.remove(); }
  if (oldScrim) { oldScrim.remove(); }
  var dw = frag.getElementById ? frag.getElementById('e8c-dw') : frag.querySelector('#e8c-dw');
  var scrim = frag.querySelector('#e8c-scrim');
  if (scrim) { document.body.appendChild(scrim); }
  if (dw) { document.body.appendChild(dw); }

  content().innerHTML = '';
  content().appendChild(frag);

  function el(id) { return document.getElementById(id); }

  // The shell's chrome above #content varies (topbar height, content padding),
  // so the conversation is sized from the measured box rather than a guessed
  // viewport offset. Re-measured on resize.
  function fit() {
    var w = document.querySelector('.e8c');
    if (!w) { return; }
    var top = w.getBoundingClientRect().top;
    w.style.height = Math.max(420, window.innerHeight - top - 18) + 'px';
  }
  fit();
  if (window.__e8ChatFit) { window.removeEventListener('resize', window.__e8ChatFit); }
  window.__e8ChatFit = fit;
  window.addEventListener('resize', fit);
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function showError(m) {
    var e = el('e8c-error');
    if (!m) { e.style.display = 'none'; return; }
    e.textContent = m; e.style.display = 'block';
  }

  function setStatus(text, kind) {
    el('e8c-status').textContent = text;
    el('e8c-dot').className = 'e8c-dot' + (kind ? ' ' + kind : '');
  }

  /* ---- action cards ---------------------------------------------------- */

  var ACTION_ROUTE = {
    approve_candidate: 'approve-candidate', reject_candidate: 'reject-candidate',
    execute_task: 'execute-task', approve_recovery: 'approve-recovery',
    approve_migration: 'approve-migration', revoke_approval: 'revoke'
  };
  var ACTION_LABEL = {
    approve_candidate: 'Review changes', reject_candidate: 'Reject',
    execute_task: 'Execute task', approve_recovery: 'Approve recovery',
    approve_migration: 'Approve migration', revoke_approval: 'Revoke approval'
  };
  var ACTION_TITLE = {
    approve_candidate: 'Candidate ready for review', execute_task: 'Approved - ready to execute',
    approve_recovery: 'Recovery needs approval', approve_migration: 'Migration needs approval',
    revoke_approval: 'Approval can be withdrawn', reject_candidate: 'Candidate ready for review'
  };

  // The server's own sentence, per card uuid. Never composed here.
  var REQUIRED_STATEMENT = {};
  var CARD_BY_UUID = {};

  // Cards arrive as a flat list of single actions. A human reads one decision
  // with options, not two rows, so approve/reject for the same candidate fold
  // into one card. Nothing is hidden: each button still presses its own card
  // uuid on its own route, and the server still re-proves the pairing.
  function groupCards(cards) {
    var byKey = {}, order = [];
    (cards || []).forEach(function (c) {
      if (!ACTION_ROUTE[c.action_type]) { return; }
      if (c.required_statement) { REQUIRED_STATEMENT[c.uuid] = c.required_statement; }
      CARD_BY_UUID[c.uuid] = c;
      var key = (c.candidate_uuid || c.recovery_uuid || c.task_uuid || c.uuid);
      if (!byKey[key]) { byKey[key] = { key: key, primary: null, actions: [], project: c.project }; order.push(key); }
      var g = byKey[key];
      g.actions.push(c);
      var rank = { approve_candidate: 3, execute_task: 4, approve_recovery: 3, approve_migration: 3 };
      if (!g.primary || (rank[c.action_type] || 0) > (rank[g.primary.action_type] || 0)) { g.primary = c; }
    });
    return order.map(function (k) { return byKey[k]; });
  }

  function cardSubtitle(g) {
    var p = g.primary, bits = [];
    if (p.file_count !== null && p.file_count !== undefined) {
      bits.push(p.file_count + ' file' + (p.file_count === 1 ? '' : 's'));
    }
    if (p.candidate_status) { bits.push(String(p.candidate_status).toLowerCase()); }
    if (p.unknown_count) { bits.push(p.unknown_count + ' unresolved'); }
    if (!bits.length) { return ''; }
    return '<div class="e8c-card-s">' + esc(bits.join(' - ')) + '</div>';
  }

  function cardHtml(g) {
    var p = g.primary;
    var title = ACTION_TITLE[p.action_type] || ACTION_LABEL[p.action_type] || p.action_type;
    var btns = '';

    g.actions.forEach(function (c) {
      var route = ACTION_ROUTE[c.action_type];
      if (!route) { return; }
      if (c.action_type === 'approve_candidate') {
        // Approval is never a button on a card. It happens in the review panel,
        // after the code has actually been shown.
        btns += '<button class="e8c-btn" data-review="' + esc(c.uuid) + '">Review changes</button>';
        return;
      }
      var secondary = (c.action_type === 'reject_candidate' || c.action_type === 'revoke_approval');
      btns += '<button class="e8c-btn ' + (secondary ? 'q' : '') + '" data-act="' + esc(c.uuid)
        + '" data-route="' + esc(route) + '" data-label="' + esc(ACTION_LABEL[c.action_type])
        + '">' + esc(ACTION_LABEL[c.action_type]) + '</button>';
    });

    return '<div class="e8c-card" data-group="' + esc(g.key) + '"><div class="e8c-card-b">'
      + '<div class="e8c-card-t">' + esc(title) + '</div>'
      + '<div class="e8c-card-h">' + esc(p.task_title || 'Engineering change') + '</div>'
      + cardSubtitle(g)
      + '<div class="e8c-acts">' + btns + '</div>'
      + '<div class="e8c-res" id="e8c-res-' + esc(p.uuid) + '"></div>'
      + '</div></div>';
  }

  /* ---- messages -------------------------------------------------------- */

  function messageHtml(m) {
    var role = m.role === 'user' ? 'user' : (m.role === 'system' ? 'system' : 'engineer888');
    var who = role === 'user' ? 'You' : (role === 'system' ? '' : 'Engineer888');
    var meta = m.metadata || {};
    var extra = '';

    if (meta.kind === 'project_selection_required') {
      extra = '<div class="e8c-card"><div class="e8c-card-b">'
        + '<div class="e8c-card-t">Project required</div>'
        + '<div class="e8c-card-s">No task was created. Choose where the work belongs.</div>'
        + '<div class="e8c-acts">'
        + (meta.projects || []).map(function (p) {
            return '<button class="e8c-btn q" data-projkey="' + esc(p.key) + '">' + esc(p.name) + '</button>';
          }).join('')
        + '</div></div></div>';
    }

    return '<div class="e8c-m ' + role + (m.__pending ? ' pending' : '') + '">'
      + (who ? '<div class="e8c-who">' + esc(who) + '</div>' : '')
      + '<div class="e8c-txt">' + esc(m.body) + '</div>'
      + (m.__failed
          ? '<div class="e8c-fail">Not delivered'
            + ' <button data-retry="' + esc(m.__key) + '">Retry</button>'
            + '<button data-drop="' + esc(m.__key) + '">Discard</button></div>'
          : '')
      + extra + presentationHtml(m) + '</div>';
  }

  /**
   * Decisions Boss has closed, keyed by turn + logical decision.
   *
   * Close is presentation only. It removes the card from THIS turn and nothing
   * else: the approval stays pending, the card stays unconsumed, and the
   * decision stays in Decisions. Held here so the 15-second poll cannot reopen
   * something he has just dismissed - which it otherwise would, because the
   * server keeps returning the reference for as long as the decision is real.
   */
  var CLOSED = {};

  function closedKey(messageId, logicalKey) { return messageId + '::' + logicalKey; }

  /**
   * The compact card.
   *
   * Everything drawn here came from the server's own hydration of a logical
   * decision reference. The page composes no governed field: the uuid on the
   * Review button was resolved server-side, now, from the live card table, and
   * is never what the message remembered.
   */
  function presentationHtml(m) {
    var list = m.presentations;

    if (!list || !list.length) { return ''; }

    var out = '';

    list.forEach(function (p) {
      var d = p.decision || {};
      if (CLOSED[closedKey(m.id, p.logical_key)]) { return; }

      var bits = [];
      if (d.file_count !== null && d.file_count !== undefined) {
        bits.push(d.file_count + ' file' + (d.file_count === 1 ? '' : 's'));
      }
      if (d.confidence) { bits.push(esc(d.confidence) + ' confidence'); }
      if (d.earlier) { bits.push(d.earlier + ' earlier attempt' + (d.earlier === 1 ? '' : 's')); }

      out += '<div class="e8c-card" data-pres="' + esc(closedKey(m.id, p.logical_key)) + '">'
        + '<div class="e8c-card-b">'
        + '<div class="e8c-card-t">Candidate ready</div>'
        + '<div class="e8c-card-h">' + esc(d.title || 'Engineering change') + '</div>'
        + '<div class="e8c-card-s">' + bits.join(' &middot; ') + '</div>'
        + '<div class="e8c-acts">'
        + (d.review_card
            ? '<button class="e8c-btn" data-review="' + esc(d.review_card) + '">Review</button>'
            : '<span class="e8c-card-s" style="margin:0">Reopening this decision - refresh in a moment.</span>')
        + '<button class="e8c-btn q" data-close="' + esc(closedKey(m.id, p.logical_key)) + '">Close</button>'
        + '</div></div></div>';
    });

    return out;
  }

  // The last thing render() was given. A failed send redraws the same
  // conversation with one entry marked, without going back to the network.
  var LAST = { messages: [], cards: [] };
  function redraw() { render(LAST.messages, LAST.cards, true); }

  /**
   * A stable identity for one message.
   *
   * The server sends an id; optimistic entries have not been given one yet and
   * carry __key instead. Both are stable for the lifetime of the node, which is
   * the only property that matters here.
   */
  function msgKey(m) {
    return m.id !== undefined && m.id !== null ? 'm' + m.id : (m.__key || 'x' + m.role + ':' + m.body.length);
  }

  /**
   * What the server just said, reduced to a comparable string.
   *
   * CHAT-FOLLOWUP-001. The poll used to run `log.innerHTML = html` every 15
   * seconds whether or not anything had changed, which destroyed every piece of
   * local state the reader owned: open <details>, scroll position, the file
   * they had expanded in a diff, and their place in it. Nothing was lost from
   * the database and everything was lost from the screen.
   *
   * A signature makes the common case free. Most polls change nothing, and a
   * poll that changes nothing must now touch no DOM at all.
   */
  function signature(messages, cards) {
    var m = (messages || []).map(function (x) { return msgKey(x) + ':' + (x.body || '').length; }).join(',');
    var c = (cards || []).map(function (x) {
      return x.uuid + ':' + (x.workflow_state || '') + ':' + (x.expires_at || '') + ':' + (x.file_count || '');
    }).join(',');
    return m + '|' + c + '|' + PENDING.length + ':' + PENDING.filter(function (p) { return p.__failed; }).length;
  }

  var LAST_SIG = null;

  // The decisions currently waiting on this project, canonical first, and the
  // count belonging to other projects. Held here rather than drawn into the
  // conversation; see the note in render().
  var DECISIONS = [];
  var OTHER_PROJECT_COUNT = 0;

  // The authoritative count, published by the server from
  // DecisionProjection. Null until a payload carries one; the badge
  // stays hidden rather than guessing from the card list, which is what
  // made it say 25 when there were 2 decisions.
  var DECISION_COUNT = null;

  /**
   * The counter.
   *
   * Hidden at zero. A badge that is always present, showing nothing, is a badge
   * the reader stops seeing - and this one has to still mean something on the
   * day it says a candidate is waiting.
   */
  function paintDecisionCount() {
    var btn = el('e8c-decisions');
    if (!btn) { return; }

    var n = DECISION_COUNT;

    if (n === null || n === 0) { btn.style.display = 'none'; return; }

    btn.style.display = '';
    btn.textContent = n === 1 ? '1 decision waiting' : n + ' decisions waiting';
    btn.title = OTHER_PROJECT_COUNT
      ? OTHER_PROJECT_COUNT + ' more on other projects'
      : 'Review the current decision';
  }

  /**
   * Open the canonical decision.
   *
   * The canonical one is the most recent for the active project - the same
   * relevance the server already applies when it decides which cards to issue.
   * Older decisions on the same project remain pending and reachable; they are
   * simply not what "review the decision" means right now.
   */
  function openCanonicalDecision() {
    if (!DECISIONS.length) { return; }

    var g = DECISIONS[0];
    var review = null;

    g.actions.forEach(function (c) {
      if (c.action_type === 'approve_candidate') { review = c.uuid; }
    });

    if (review) { openReview(review); }
  }

  function render(messages, cards, force) {
    var sig = signature(messages, cards);

    if (! force && sig === LAST_SIG) { return; }   // nothing changed: touch nothing

    LAST_SIG = sig;
    LAST = { messages: messages || [], cards: cards || [] };
    var log = el('e8c-log');
    var groups = groupCards(cards);

    // Cards for the project the conversation is actually on belong in the
    // conversation. Everything else is one collapsed line: present, openable,
    // and never 16,000px of stack between the reader and their own last message.
    var here = [], elsewhere = [];
    groups.forEach(function (g) {
      if (!ACTIVE || !g.project || g.project === ACTIVE.name) { here.push(g); } else { elsewhere.push(g); }
    });

    // Even scoped to one project the backlog can be large - this conversation
    // carries 45 tasks' worth of pending candidates. A conversation that ends in
    // sixteen stacked cards is still a queue with a chat box on top, so only the
    // most recent few stay inline and the remainder collapse behind one line.
    var INLINE_MAX = 3;
    var inline = here.slice(-INLINE_MAX);
    var older = here.slice(0, Math.max(0, here.length - INLINE_MAX));

    var all = (messages || []).concat(PENDING);

    // WAS THE READER AT THE BOTTOM? Decided BEFORE anything moves. Someone
    // reading back through the conversation must not be yanked forward because
    // a poll landed; someone sitting at the live edge expects to follow along.
    var atBottom = (log.scrollHeight - log.scrollTop - log.clientHeight) < 120;
    var keepTop = log.scrollTop;

    // ── SCAFFOLD, ONCE ───────────────────────────────────────────────
    var thread = log.querySelector('.e8c-thread');
    if (!thread) {
      log.innerHTML = '<div class="e8c-thread"></div>';
      thread = log.querySelector('.e8c-thread');
    }

    if (!all.length) {
      thread.innerHTML = '<div class="e8c-empty">Ask Engineer888 to investigate, plan or build something.<br>'
        + 'It reads the repository before it answers.</div>';
      return;
    }

    var empty = thread.querySelector('.e8c-empty');
    if (empty) { empty.remove(); }

    // ── MESSAGES: append what is new, leave what is not ──────────────
    var seen = {};
    all.forEach(function (m) {
      var k = msgKey(m);
      seen[k] = true;
      var node = thread.querySelector('[data-k="' + k + '"]');

      if (!node) {
        var holder = document.createElement('div');
        holder.innerHTML = messageHtml(m);
        node = holder.firstChild;
        node.setAttribute('data-k', k);
        // Cards live after the messages, so a new message is inserted before
        // them rather than appended to the end of the thread.
        var firstCard = thread.querySelector('.e8c-card-slot');
        thread.insertBefore(node, firstCard || null);
        wire(node);
        return;
      }

      // An existing message is rewritten ONLY when its own rendering changed -
      // a pending bubble that failed, or one the server has now confirmed.
      var want = messageHtml(m);
      if (node.getAttribute('data-h') !== String(want.length) || /pending|e8c-fail/.test(node.className + node.innerHTML) !== /pending|e8c-fail/.test(want)) {
        var h2 = document.createElement('div');
        h2.innerHTML = want;
        var fresh = h2.firstChild;
        fresh.setAttribute('data-k', k);
        fresh.setAttribute('data-h', String(want.length));
        node.replaceWith(fresh);
        wire(fresh);
      }
    });

    // Messages the server no longer returns (an optimistic entry that was
    // discarded). Explicit removal, never a redraw.
    Array.prototype.forEach.call(thread.querySelectorAll('.e8c-m[data-k]'), function (n) {
      if (!seen[n.getAttribute('data-k')]) { n.remove(); }
    });

    // ── GOVERNED DECISIONS DO NOT LIVE IN THE TRANSCRIPT ─────────────
    //
    // They used to. Every pending decision was drawn into the conversation, so
    // a chat with 79 messages carried a stack of identical "Candidate ready for
    // review / Build a small internal Bug Tracker application from scratch"
    // cards and stopped reading as a conversation at all. Scoping them to the
    // active project and capping the inline count made the stack smaller; it
    // did not make it belong there.
    //
    // A decision is not a remark. It is a thing with its own lifecycle that
    // outlives the sentence that produced it, and it belongs on its own
    // surface. What the conversation carries now is the fact that one is
    // waiting - a counter in the header - and nothing else.
    //
    // NOTHING ABOUT GOVERNANCE MOVED. The counter and the drawer consume the
    // same server-issued cards, with the same required_statement, the same
    // fingerprint and the same consumption rules. This is presentation.
    DECISIONS = inline.concat(older);     // same project, canonical first
    OTHER_PROJECT_COUNT = elsewhere.length;
    paintDecisionCount();

    // Any card left in the transcript from a previous render is removed. A
    // decision that is mid-confirmation is left alone rather than yanked out
    // from under the person using it.
    var slot = thread.querySelector('.e8c-card-slot');
    if (slot && !slot.querySelector('[data-armed="1"]')) { slot.remove(); }
    ['older', 'elsewhere'].forEach(function (k) {
      var n = log.querySelector('[data-more="' + k + '"]');
      if (n) { n.remove(); }
    });

    // ── SCROLL ───────────────────────────────────────────────────────
    if (atBottom) {
      var msgs = thread.querySelectorAll('.e8c-m');
      if (msgs.length) {
        var last = msgs[msgs.length - 1];
        log.scrollTop = Math.max(0, last.offsetTop + last.offsetHeight - log.clientHeight + 24);
      }
    } else {
      log.scrollTop = keepTop;   // reading history: stay exactly where they were
    }
  }

  /** One collapsed group, preserving whether the reader had it open. */
  function renderMore(log, key, groups, noun, suffix) {
    var existing = log.querySelector('[data-more="' + key + '"]');

    if (!groups.length) { if (existing) { existing.remove(); } return; }

    var label = groups.length + ' ' + noun + (groups.length === 1 ? '' : 's') + suffix;

    if (existing && existing.getAttribute('data-count') === String(groups.length)) { return; }

    var wasOpen = existing ? existing.open : false;
    var d = document.createElement('details');
    d.className = 'e8c-more';
    d.setAttribute('data-more', key);
    d.setAttribute('data-count', String(groups.length));
    d.open = wasOpen;
    d.innerHTML = '<summary>' + esc(label) + '</summary><div class="e8c-more-l">'
      + groups.map(cardHtml).join('') + '</div>';

    if (existing) { existing.replaceWith(d); } else { log.appendChild(d); }
    wire(d);
  }

  function wire(root) {
    Array.prototype.forEach.call(root.querySelectorAll('[data-projkey]'), function (b) {
      b.onclick = function () { selectProject(b.getAttribute('data-projkey')); };
    });
    Array.prototype.forEach.call(root.querySelectorAll('[data-act]'), function (b) {
      b.onclick = function () {
        pressCard(b.getAttribute('data-act'), b.getAttribute('data-route'), b.getAttribute('data-label'), b);
      };
    });
    Array.prototype.forEach.call(root.querySelectorAll('[data-close]'), function (b) {
      b.onclick = function () {
        // Presentation only. Nothing is sent to the server: no consume, no
        // reject, no revoke. The decision is untouched and still in Decisions.
        CLOSED[b.getAttribute('data-close')] = true;
        var card = b.closest('[data-pres]');
        if (card) { card.remove(); }
      };
    });
    Array.prototype.forEach.call(root.querySelectorAll('[data-review]'), function (b) {
      b.onclick = function () { openReview(b.getAttribute('data-review')); };
    });
    Array.prototype.forEach.call(root.querySelectorAll('[data-retry]'), function (b) {
      b.onclick = function () { retry(b.getAttribute('data-retry')); };
    });
    Array.prototype.forEach.call(root.querySelectorAll('[data-drop]'), function (b) {
      b.onclick = function () {
        var k = b.getAttribute('data-drop');
        PENDING = PENDING.filter(function (p) { return p.__key !== k; });
        showError(null);
        redraw();
      };
    });
  }

  /* ---- review drawer --------------------------------------------------- */

  function closeDrawer() {
    el('e8c-dw').classList.remove('on');
    el('e8c-scrim').classList.remove('on');
    ARMED = null;
  }
  el('e8c-dw-x').onclick = closeDrawer;
  el('e8c-scrim').onclick = closeDrawer;
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && el('e8c-dw').classList.contains('on')) { closeDrawer(); }
  });

  function diffHtml(d) {
    if (!d) { return ''; }
    return String(d).split('\n').map(function (l) {
      var c0 = l.charAt(0);
      var cls = c0 === '+' ? 'a' : (c0 === '-' ? 'd' : (c0 === '@' ? 'h' : ''));
      return '<span class="' + cls + '">' + esc(l) + '</span>';
    }).join('\n');
  }

  function riskText(r) {
    if (typeof r === 'string') { return r; }
    return r.risk || r.description || r.detail || JSON.stringify(r);
  }

  async function openReview(cardUuid) {
    var card = CARD_BY_UUID[cardUuid];
    if (!card) { return; }

    ARMED = cardUuid;                       // hold polling while this is open
    el('e8c-dw-t').textContent = card.task_title || 'Candidate review';
    el('e8c-dw-b').innerHTML = '<div class="e8c-empty">Loading the candidate...</div>';
    el('e8c-dw').classList.add('on');
    el('e8c-scrim').classList.add('on');

    var d = card.candidate_uuid ? await api('/engineer888/candidates/' + card.candidate_uuid) : null;
    if (!d) {
      el('e8c-dw-b').innerHTML = '<div class="e8c-err">The candidate could not be loaded. '
        + 'Nothing has been approved.</div>';
      return;
    }

    var c = d.candidate || d;
    var files = d.files || [];
    var reasoning = d.reasoning || c.reasoning || {};
    var h = '';

    if (reasoning.problem_understanding || reasoning.implementation_strategy) {
      h += '<div class="e8c-sec"><h4>What Engineer888 intends to do</h4>'
        + (reasoning.problem_understanding ? '<p>' + esc(reasoning.problem_understanding) + '</p>' : '')
        + (reasoning.implementation_strategy ? '<p>' + esc(reasoning.implementation_strategy) + '</p>' : '')
        + '</div>';
    }

    h += '<div class="e8c-sec"><h4>' + esc(files.length) + ' file'
      + (files.length === 1 ? '' : 's') + ' changed</h4>';
    files.forEach(function (f, i) {
      var isNew = String(f.action).toUpperCase() === 'CREATE';
      h += '<details class="e8c-file"' + (i === 0 ? ' open' : '') + '><summary>'
        + '<span class="e8c-tag ' + (isNew ? 'c' : 'u') + '">' + esc(f.action) + '</span>'
        + '<span class="e8c-file-p">' + esc(f.path) + '</span>'
        + '<span class="e8c-tag n" style="margin-left:auto">' + esc(f.bytes) + ' B</span>'
        + '</summary>'
        + (f.rationale
            ? '<div style="padding:11px 14px 0;font-size:13px;color:#8d97ad">' + esc(f.rationale) + '</div>'
            : '')
        + '<pre class="e8c-code">' + (isNew ? esc(f.proposed) : diffHtml(f.diff)) + '</pre>'
        + '</details>';
    });
    h += '</div>';

    if ((reasoning.risks || []).length) {
      h += '<div class="e8c-sec"><h4>Risk</h4>'
        + reasoning.risks.map(function (r) { return '<p>' + esc(riskText(r)) + '</p>'; }).join('')
        + '</div>';
    }
    if (reasoning.testing_strategy) {
      h += '<div class="e8c-sec"><h4>Tests</h4><p>' + esc(reasoning.testing_strategy) + '</p></div>';
    }
    if (reasoning.rollback) {
      h += '<div class="e8c-sec"><h4>Rollback</h4><p>' + esc(reasoning.rollback) + '</p></div>';
    }

    // Technical evidence: present, complete, closed by default.
    h += '<div class="e8c-sec"><details class="e8c-tech"><summary>View technical details</summary>'
      + '<div class="e8c-kv">'
      + '<span>Candidate</span><span>' + esc(card.candidate_uuid || '-') + '</span>'
      + '<span>Task</span><span>' + esc(card.task_uuid || '-') + '</span>'
      + '<span>Workflow</span><span>' + esc(card.workflow_state || '-') + '</span>'
      + '<span>Provider</span><span>' + esc((card.provider || '-') + (c.model ? ' / ' + c.model : '')) + '</span>'
      + '<span>Card expires</span><span>' + esc(card.expires_at || '-') + '</span>'
      + (d.binding && d.binding.fingerprint
          ? '<span>Fingerprint</span><span>' + esc(d.binding.fingerprint) + '</span>' : '')
      + '</div>'
      + files.map(function (f) {
          return '<div class="e8c-kv"><span>' + esc(f.path) + '</span><span>'
            + esc(f.ownership || '') + (f.governed ? ' (governed)' : '') + '</span></div>';
        }).join('')
      + '</details></div>';

    // Approval. The statement is the server's, verbatim.
    var approveCard = null;
    Object.keys(CARD_BY_UUID).forEach(function (k) {
      var x = CARD_BY_UUID[k];
      if (x.action_type === 'approve_candidate' && x.candidate_uuid === card.candidate_uuid) { approveCard = x; }
    });

    if (approveCard && REQUIRED_STATEMENT[approveCard.uuid]) {
      h += '<div class="e8c-ap"><h4>Approval required</h4>'
        + '<p style="font-size:13.5px;color:#c8d1e3;line-height:1.68;margin:0 0 12px">'
        + 'You are authorising Engineer888 to write these exact ' + esc(files.length)
        + ' file' + (files.length === 1 ? '' : 's') + ' to <b>' + esc(card.project || 'this project')
        + '</b>. Type the statement below to confirm you are approving these bytes and nothing else.</p>'
        + '<code class="e8c-stmt" id="e8c-req">' + esc(REQUIRED_STATEMENT[approveCard.uuid]) + '</code>'
        + '<div style="display:flex;gap:9px;margin-bottom:11px">'
        + '<button class="e8c-btn q" id="e8c-copy">Copy statement</button></div>'
        + '<textarea class="e8c-ta" id="e8c-stmt" rows="3" '
        + 'placeholder="Type or paste the statement above, exactly."></textarea>'
        + '<div style="height:9px"></div>'
        + '<textarea class="e8c-ta" id="e8c-cmt" rows="2" '
        + 'placeholder="Comment (optional) - recorded against the approval."></textarea>'
        + '<div class="e8c-acts" style="margin-top:13px">'
        + '<button class="e8c-btn g" id="e8c-approve">Approve candidate</button>'
        + '</div>'
        + '<div class="e8c-res" id="e8c-res-' + esc(approveCard.uuid) + '"></div>'
        + '<div class="e8c-hint" style="text-align:left;margin-top:10px">Live updates are paused while '
        + 'this is open. The server revalidates every binding when you confirm.</div>'
        + '</div>';
    } else if (approveCard) {
      h += '<div class="e8c-err">The server did not supply an approval statement for this card, '
        + 'so it cannot be approved from here. Refresh and try again.</div>';
    }

    el('e8c-dw-b').innerHTML = h;
    wire(el('e8c-dw-b'));

    var copy = el('e8c-copy');
    if (copy && approveCard) {
      copy.onclick = function () {
        var t = REQUIRED_STATEMENT[approveCard.uuid] || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(t).then(function () {
            copy.textContent = 'Copied';
            setTimeout(function () { copy.textContent = 'Copy statement'; }, 1500);
          });
          return;
        }
        var r = document.createRange();
        r.selectNodeContents(el('e8c-req'));
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      };
    }

    var ap = el('e8c-approve');
    if (ap && approveCard) {
      ap.onclick = async function () {
        var typed = el('e8c-stmt').value;
        var box = el('e8c-res-' + approveCard.uuid);
        if (!typed.trim()) {
          box.innerHTML = '<span style="color:#fca5a5">The approval statement is required. '
            + 'The server will refuse an approval that does not state exactly what is being approved.</span>';
          el('e8c-stmt').focus();
          return;
        }
        ap.disabled = true;
        box.textContent = 'Sending...';
        var body = { statement: typed };                    // sent exactly as typed
        var cmt = el('e8c-cmt').value.trim();
        if (cmt) { body.comment = cmt; }
        var r = await api('/engineer888/chat/actions/' + approveCard.uuid + '/approve-candidate', 'POST', body);
        if (!r) {
          box.innerHTML = '<span style="color:#fca5a5">The server refused this approval. '
            + 'Nothing was approved.</span>';
          ap.disabled = false;
          return;
        }
        box.innerHTML = '<span style="color:#34d399">Approved: ' + esc(r.result || 'done') + '</span>';
        setTimeout(function () { closeDrawer(); refresh(); }, 900);
      };
    }
  }

  /* ---- card press (non-approval actions) ------------------------------- */

  var NEEDS_INSTRUCTION = ['reject-candidate'];

  async function pressCard(uuid, route, label, btn) {
    var box = el('e8c-res-' + uuid);
    if (!box) {
      var holder = btn.closest ? btn.closest('.e8c-card-b') : null;
      box = holder ? holder.querySelector('.e8c-res') : null;
    }
    if (!box) { return; }

    var needs = NEEDS_INSTRUCTION.indexOf(route) !== -1;

    if (btn.getAttribute('data-armed') !== '1') {
      btn.setAttribute('data-armed', '1');
      btn.textContent = 'Confirm: ' + label;
      ARMED = uuid;
      if (needs) {
        box.innerHTML = '<div style="margin-bottom:7px">State why you are rejecting this. '
          + 'The reason is recorded against the engineering decision.</div>'
          + '<textarea class="e8c-ta" id="e8c-instr-' + esc(uuid) + '" rows="2" '
          + 'placeholder="e.g. Candidate proposes no file changes; nothing to implement."></textarea>';
        var ta = el('e8c-instr-' + uuid);
        if (ta) { ta.focus(); }
      } else {
        box.textContent = 'Press again to send this to the server. '
          + 'It revalidates every binding before acting.';
      }
      return;
    }

    var body = {};
    if (needs) {
      var f = el('e8c-instr-' + uuid);
      var instruction = f ? f.value.trim() : '';
      if (!instruction) {
        box.innerHTML = '<span style="color:#fca5a5">A reason is required. '
          + 'The server will refuse a rejection that does not say why.</span>';
        if (f) { f.focus(); }
        return;
      }
      body.instruction = instruction;
    }

    btn.disabled = true;
    box.textContent = 'Sending...';

    // No action_type in the body. The route carries the authority.
    var d = await api('/engineer888/chat/actions/' + uuid + '/' + route, 'POST', body);
    if (!d) {
      box.textContent = 'The server refused this action. Refreshing state.';
      btn.disabled = false;
      btn.removeAttribute('data-armed');
      btn.textContent = label;
      ARMED = null;
      await refresh();
      return;
    }
    box.textContent = 'Accepted: ' + (d.result || 'done');
    ARMED = null;
    if (el('e8c-dw').classList.contains('on')) { closeDrawer(); }
    await refresh();
  }

  /* ---- conversation state ---------------------------------------------- */

  function applyConversation(conv, projects) {
    CONV = conv;
    var sel = el('e8c-project');
    if (projects) {
      sel.innerHTML = '<option value="">Select a project...</option>'
        + projects.map(function (p) {
            return '<option value="' + esc(p.key) + '">' + esc(p.name) + '</option>';
          }).join('');
    }
    ACTIVE = conv.active_project || null;
    sel.value = ACTIVE ? ACTIVE.key : '';
  }

  function statusBar(ev) {
    el('e8c-sb').innerHTML =
      '<span><b>Project</b> ' + esc(ACTIVE ? ACTIVE.name : 'none') + '</span>'
      + '<span><b>Pipeline</b> ' + esc(BUSY ? 'working' : 'idle') + '</span>'
      + '<span><b>Messages</b> ' + esc(CURSOR || 0) + '</span>'
      + (ev && ev.unread !== undefined ? '<span><b>Unread</b> ' + esc(ev.unread) + '</span>' : '');
  }

  function idle() {
    setStatus(ACTIVE ? 'Idle' : 'No project selected', ACTIVE ? 'ok' : 'warn');
    // The shell's topbar status is left reading "Loading..." forever unless the
    // page claims it. Engineer888 owns its own state line, so this stays quiet.
    var tb = document.getElementById('topbar-status');
    if (tb) { tb.textContent = ACTIVE ? ACTIVE.name : 'No project selected'; }
  }

  async function bootstrap() {
    try {
      var d = await api('/engineer888/chat/bootstrap');
      if (!d) { showError('Chat failed to load.'); return; }
      showError(null);
      if (d.decisions !== undefined) { DECISION_COUNT = d.decisions; }
      applyConversation(d.conversation, d.projects);
      var h = await api('/engineer888/chat/messages');
      if (h) { CURSOR = h.cursor; render(h.messages || [], d.cards || []); }
      idle();
      statusBar(d);
    } catch (e) {
      showError('Chat failed to load: ' + e.message);
    }
  }

  async function selectProject(key) {
    if (!key) { return; }
    var d = await api('/engineer888/chat/project', 'POST', { project_key: key });
    if (!d) { showError('That project could not be selected.'); return; }
    showError(null);
    applyConversation(d.conversation, null);
    idle();
    await refresh();
  }

  async function refresh() {
    var h = await api('/engineer888/chat/messages');
    if (!h) { return; }
    CURSOR = h.cursor;

    // Drop optimistic entries the server has now confirmed. A failed entry is
    // kept until the user retries or discards it.
    if (PENDING.length) {
      var known = {};
      (h.messages || []).forEach(function (m) { if (m.role === 'user') { known[m.body] = true; } });
      PENDING = PENDING.filter(function (p) { return p.__failed || !known[p.body]; });
    }

    var ev = await api('/engineer888/chat/events' + (CURSOR ? '?cursor=' + CURSOR : ''));
    if (ev && ev.decisions !== undefined) { DECISION_COUNT = ev.decisions; }
    render(h.messages || [], (ev && ev.cards) || []);
    statusBar(ev);
  }

  /* ---- composer -------------------------------------------------------- */

  var input = el('e8c-input');
  function autogrow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 190) + 'px';
  }
  input.addEventListener('input', autogrow);

  async function deliver(entry) {
    var d = await api('/engineer888/chat/messages', 'POST', { body: entry.body });
    if (!d) {
      // The message stays on screen, marked, with the text recoverable. It is
      // never silently discarded: that is the defect this rebuild exists for.
      entry.__failed = true;
      entry.__pending = false;
      showError('Message not delivered. It is still here - retry or discard it.');
      redraw();
      return false;
    }
    showError(null);
    if (d.conversation) { applyConversation(d.conversation, null); }
    return true;
  }

  async function send() {
    var body = input.value.trim();
    if (!body || BUSY) { return; }

    // Draw it first. The composer clears only once the message is in local
    // conversation state, so nothing typed can be lost by a failure.
    var entry = { role: 'user', body: body, __pending: true,
                  __key: 'p' + Date.now() + '_' + Math.random().toString(36).slice(2) };
    PENDING.push(entry);
    input.value = '';
    autogrow();
    redraw();

    BUSY = true;
    el('e8c-send').disabled = true;
    setStatus('Thinking...', 'busy');
    try {
      if (await deliver(entry)) { await refresh(); }
    } finally {
      BUSY = false;
      el('e8c-send').disabled = false;
      idle();
    }
  }

  async function retry(key) {
    var entry = null;
    PENDING.forEach(function (p) { if (p.__key === key) { entry = p; } });
    if (!entry || BUSY) { return; }
    entry.__failed = false;
    entry.__pending = true;
    showError(null);
    redraw();
    BUSY = true;
    el('e8c-send').disabled = true;
    setStatus('Thinking...', 'busy');
    try {
      if (await deliver(entry)) { await refresh(); }
    } finally {
      BUSY = false;
      el('e8c-send').disabled = false;
      idle();
    }
  }

  el('e8c-send').onclick = send;
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });
  el('e8c-project').onchange = function () { selectProject(this.value); };
  el('e8c-decisions').onclick = openCanonicalDecision;

  await bootstrap();

  window.__e8ChatPoll = setInterval(function () {
    if (!BUSY && !ARMED) { refresh(); }
  }, 15000);
};
</script>
