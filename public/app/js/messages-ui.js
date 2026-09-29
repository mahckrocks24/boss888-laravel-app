/**
 * LU Messages — Unified messaging system v1.0.0
 * Floater button + modal chat + sidebar nav page
 */
(function(){
'use strict';

var _msg = { open: false, agent: 'sarah', conversations: [], messages: [], unread: {}, pollTimer: null };
var AGENT_COLORS = {};   /* Owner 2026-09-15: no colour per agent — the neutral fallback var(--t3) applies everywhere */

function _msgE(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function _msgAgo(ts){if(!ts)return'';var d=(typeof window!=='undefined'&&window._luParseTs)?window._luParseTs(ts):new Date(ts.replace(' ','T')+'Z');var m=Math.round((Date.now()-d)/60000);if(m<1)return'now';if(m<60)return m+'m ago';if(m<1440)return Math.floor(m/60)+'h ago';return Math.floor(m/1440)+'d ago';}
function _msgApi(method,path,body){var t=localStorage.getItem('lu_token')||'';var o={method:method,headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+t}};if(body)o.body=JSON.stringify(body);return fetch('/api'+path,o).then(function(r){return r.json();});}

// ── 2026-07-26 READ/UNREAD STATE MACHINE ──────────────────────────────────
// D2: six call sites used to blank the badge locally, then _msgPollUnread
// restored the server truth 10s later — a visible clear-then-reappear
// flicker. The badge is now SERVER-AUTHORITATIVE: mark read, then re-poll.
// Nothing may set the badge by hand.
function _msgMarkRead(slug){
  if(!slug) return Promise.resolve();
  return _msgApi('POST','/messages/'+slug+'/read')
    .then(function(){ if(window._msgPollUnread) return window._msgPollUnread(); })
    .catch(function(){});
}
window._msgMarkRead=_msgMarkRead;

// D6 — clear the entire workspace. Wired to the modal header control.
window._msgMarkAllRead=function(){
  return _msgApi('POST','/messages/read-all')
    .then(function(){
      if(window._msgPollUnread) window._msgPollUnread();
      if(_msg.open) _msgLoadConversations();
    })
    .catch(function(){});
};

// ── Floater Button ─────────────────────────────────────────────────────────
/* Owner 2026-09-10: the floating chat must not exist in Basic at all.
   It was already data-adv="1", which the stylesheet hides via
   html[data-mode="basic"] [data-adv="1"]{display:none!important} — but that is a
   CSS hide on an element that is still built, still focusable by assistive tech and
   still one attribute away from being visible. Basic now never CONSTRUCTS it, and a
   later switch to Basic removes it. Advanced is unchanged apart from the icon. */
function _msgBasicMode(){
  try{
    var m=document.documentElement.getAttribute('data-mode');
    if(m) return m==='basic';
    return (localStorage.getItem('lu_visibility_mode')||'basic')!=='advanced';
  }catch(e){ return true; }   // unreadable mode = treat as Basic, the smaller surface
}
function _msgRemoveFloater(){
  var el=document.getElementById('lu-messages-floater'); if(el) el.remove();
  var md=document.getElementById('lu-msg-modal'); if(md) md.style.display='none';
}
function _msgCreateFloater(){
  /* FLOATER-3c (Owner 2026-09-25: "she should also be visible as a floater on basic"): the mode no longer removes her. */
  if(document.getElementById('lu-messages-floater'))return;
  var btn=document.createElement('div');
  btn.id='lu-messages-floater'; btn.setAttribute('aria-label','Messages'); // FLOATER-3: visible in Basic too (Owner 2026-09-25); hidden only on chat surfaces
  // Owner 2026-09-10: the LevelUp mark instead of the generic speech bubble — the same
  // asset Arthur wears in the wizard (CP-0449), so one file governs both surfaces.
  // AVATAR888 (DEC-0055, Owner: "the floater Sarah"): the button IS Sarah — her portrait, not the company mark.
  btn.innerHTML='<img src="/img/agents/sarah.webp" alt="" width="34" height="34" style="display:block;width:34px;height:34px;border-radius:50%;object-fit:cover;pointer-events:none" onerror="this.src=\'/img/logo-icon-48.png\';this.style.borderRadius=\'0\';this.style.boxShadow=\'none\'"><div id="lu-messages-badge"></div>';
  btn.onclick=_msgToggle;
  document.body.appendChild(btn);
  try{ _msgMakeDraggable(btn); _msgRestorePos(btn); }catch(e){}

  // Inject styles
  var style=document.createElement('style');
  style.textContent='#lu-messages-floater{position:fixed;bottom:24px;right:24px;left:auto;width:48px;height:48px;border-radius:50%;background:var(--s2,#1e2030);border:2px solid var(--bd,#2a2d3e);cursor:pointer;z-index:999;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 4px 16px rgba(0,0,0,.3);transition:all .2s}#lu-messages-floater:hover{border-color:var(--p,#6C5CE7);transform:scale(1.05)}#lu-messages-badge{position:absolute;top:-4px;right:-4px;background:#C0392B;color:#fff;border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;display:none;align-items:center;justify-content:center}#lu-messages-badge.visible{display:flex}'
    +'#lu-msg-modal{position:fixed;bottom:80px;right:24px;left:auto;width:560px;height:480px;background:var(--s1,#161927);border:1px solid var(--bd,#2a2d3e);border-radius:16px;z-index:1000;display:none;flex-direction:column;overflow:hidden;box-shadow:0 12px 48px rgba(0,0,0,.5);animation:msgSlideUp .2s ease}'
    +'@keyframes msgSlideUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}'
    +'@media(max-width:768px){#lu-messages-floater{left:16px;bottom:80px}#lu-msg-modal{left:8px;right:8px;width:auto;bottom:136px;height:60vh}}'
    /* FLOATER-2: she IS the conversation, so on a conversation she is only in the way. */
    +'#lu-messages-floater{touch-action:none;cursor:grab;user-select:none;-webkit-user-select:none}'
    +'#lu-messages-floater.lu-dragging{cursor:grabbing;transition:none}'
    +'body.lu-chat-surface #lu-messages-floater{display:none!important}'
    /* FLOATER-3: nudges (clear of buttons) and the keyboard lift ride on a transform, so her anchored or dragged
       position underneath is never touched. */
    +'#lu-messages-floater{transform:translate(var(--lu-fl-dx,0px),var(--lu-fl-dy,0px))}'
    +'html.lu-kb-open #lu-messages-floater{transform:translate(var(--lu-fl-dx,0px),calc(var(--lu-fl-dy,0px) - var(--lu-kb,0px)))}';
  document.head.appendChild(style);
  try{ _msgKeepClear(btn); }catch(e){}
}

/* Toggling Basic/Advanced without a reload must add or remove the floater, not just
   restyle it — the toggle dispatches this event after setting data-mode. */
try{
  window.addEventListener('lu:visibility-mode', function(e){
    var mode=(e&&e.detail&&e.detail.mode)||null;
    _msgCreateFloater(); try{ _msgSyncSurface(); }catch(e){}   /* FLOATER-3c: both modes keep her; only a chat surface hides her */
  });
}catch(e){}

/* Owner 2026-09-21: the floater is Sarah and opens Sarah — never the all-agents modal, in Advanced too. The modal
   code below is kept only for the /app/messages page it shares helpers with; nothing calls _msgCreateModal any more. */
window._msgToggle=function(){
  _msg.open=false;
  var modal=document.getElementById('lu-msg-modal'); if(modal) modal.style.display='none';
  try{ document.documentElement.classList.add('lu-sarah-from-floater'); }catch(e){}   /* SARAH-X-2: opened from the floater, so she can be closed again */
  if(typeof window.nav==='function'){ window.nav('sarah'); } else { location.href='/app/sarah'; }
}

// ── Modal ──────────────────────────────────────────────────────────────────
function _msgCreateModal(){
  if(document.getElementById('lu-msg-modal'))return;
  var modal=document.createElement('div');
  modal.id='lu-msg-modal';
  modal.innerHTML='<div style="display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid var(--bd);flex-shrink:0">'
    +'<span style="font-size:16px;margin-right:8px">'+window.icon("message",14)+'</span>'
    +'<span style="font-weight:700;font-size:14px;color:var(--t1);flex:1">Messages</span>'
    +'<button onclick="_msgMarkAllRead()" title="Mark all as read" style="background:none;border:none;color:var(--t3);font-size:11px;cursor:pointer;padding:4px 8px;margin-right:4px">Mark all read</button>'
    +'<button onclick="_msgToggle()" style="background:none;border:none;color:var(--t3);font-size:18px;cursor:pointer;padding:4px">\u2715</button>'
    +'</div>'
    +'<div style="display:flex;flex:1;min-height:0">'
      +'<div id="lu-msg-agents" style="width:160px;border-right:1px solid var(--bd);overflow-y:auto;flex-shrink:0"></div>'
      +'<div style="flex:1;display:flex;flex-direction:column;min-width:0">'
        +'<div id="lu-msg-feed" style="flex:1;overflow-y:auto;padding:12px"></div>'
        +'<div style="padding:8px 12px;border-top:1px solid var(--bd);display:flex;gap:8px">'
          +'<input id="lu-msg-input" type="text" placeholder="Type a message..." style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--t1);padding:8px 12px;font-size:13px;outline:none;font-family:inherit" onkeydown="if(event.key===\'Enter\')_msgSend()">'
          +'<button onclick="_msgSend()" style="background:var(--p,#6C5CE7);color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;font-weight:600">\u2192</button>'
        +'</div>'
        +'<div id="lu-msg-fineprint" class="lu-ai-fineprint" style="font-size:10px;color:var(--t3);text-align:center;padding:2px 12px 6px"></div>'
      +'</div>'
    +'</div>';
  document.body.appendChild(modal);
}

async function _msgLoadConversations(){
  try{
    var d=await _msgApi('GET','/messages/conversations');
    _msg.conversations=d.conversations||[];
    _msgRenderAgentList();
    if(_msg.conversations.length>0&&!_msg.conversations.find(function(c){return c.slug===_msg.agent;})){
      _msg.agent=_msg.conversations[0].slug;
    }
    _msgLoadThread(_msg.agent);
    // Mark current agent as read when modal opens
    _msgMarkRead(_msg.agent);   // server-authoritative (was: local badge blanking)
  }catch(e){console.error('[Messages]',e);}
}

/* DISCLAIMER-1 (Owner 2026-09-25): every agent composer carries the fine print, named for the agent in the thread. */
function _msgFineprint(){
  var n=((_msg.conversations||[]).find(function(c){return c.slug===_msg.agent;})||{}).name||(_msg.agent==='sarah'?'Sarah':'Your agent');
  ['lu-msg-fineprint','lu-msg-page-fineprint'].forEach(function(id){var e=document.getElementById(id);if(e)e.textContent=n+' is AI and can make mistakes.';});
}
function _msgRenderAgentList(){
  _msgFineprint();
  var el=document.getElementById('lu-msg-agents');if(!el)return;
  el.innerHTML=_msg.conversations.map(function(c){
    var active=c.slug===_msg.agent;
    var color=AGENT_COLORS[c.slug]||'var(--t3)';
    var uiSlug=c.slug==='sarah'?'dmm':c.slug;
    var unreadBadge=c.unread>0?'<span style="background:#C0392B;color:#fff;border-radius:50%;width:16px;height:16px;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">'+c.unread+'</span>':'';
    var lastMsg=c.last_message?'<div style="font-size:10px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px">'+_msgE(c.last_message.content).substring(0,30)+'</div>':'';
    return'<div onclick="_msgSelectAgent(\''+c.slug+'\')" style="padding:10px 12px;cursor:pointer;border-left:3px solid '+(active?color:'transparent')+';background:'+(active?'var(--s2)':'transparent')+';transition:all .15s" onmouseover="this.style.background=\'var(--s2)\'" onmouseout="this.style.background=\''+(active?'var(--s2)':'transparent')+'\'">'
      +'<div style="display:flex;align-items:center;gap:8px">'+(window.luAvatar?luAvatar(c.slug,28):'<div style="width:28px;height:28px;border-radius:50%;background:'+color+'22;border:1px solid '+color+'44;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;color:'+color+';font-weight:700">'+c.name.charAt(0)+'</div>')+''
      +'<div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:'+(active?'700':'500')+';color:'+(active?'var(--t1)':'var(--t2)')+'">'+_msgE(c.name)+'</div>'+lastMsg+'</div>'+unreadBadge+'</div></div>';
  }).join('');
}

window._msgSelectAgent=function(slug){
  _msg.agent=slug;
  _msgFineprint();
  _msgRenderAgentList();
  _msgLoadThread(slug);
  // Mark as read
  _msgMarkRead(slug);   // server-authoritative
};

async function _msgLoadThread(slug, silent){
  var feed=document.getElementById('lu-msg-feed');if(!feed)return;
  // 2026-07-26 live-refresh fix — `silent` is used by the background poller so
  // a routine refresh neither flashes "Loading..." nor jumps the scroll.
  var _stick = silent ? ((feed.scrollHeight - (feed.scrollTop + feed.clientHeight)) < 60) : true;
  if(!silent) feed.innerHTML='<div style="text-align:center;padding:20px;color:var(--t3);font-size:12px">Loading...</div>';
  var uiSlug=slug==='sarah'?'dmm':slug;
  try{
    var msgs=await _msgApi('GET','/agents/'+uiSlug+'/messages');
    _msg.messages=Array.isArray(msgs)?msgs:[];
    if(_msg.messages.length===0){
      var agentName=(_msg.conversations.find(function(c){return c.slug===slug;})||{}).name||slug;
      feed.innerHTML='<div style="text-align:center;padding:40px;color:var(--t3)"><div style="font-size:32px;margin-bottom:12px">'+window.icon("message",14)+'</div><div style="font-size:13px">No messages with '+_msgE(agentName)+' yet.</div><div style="font-size:11px;margin-top:4px;color:var(--t3)">Send a message to start a conversation.</div></div>';
      return;
    }
    var color=AGENT_COLORS[slug]||'var(--t3)';
    feed.innerHTML=_msg.messages.map(function(m){
      var isUser=m.from==='User'||m.from==='user';
      var align=isUser?'flex-end':'flex-start';
      var bg=isUser?'var(--p,#6C5CE7)':color+'18';
      var tc=isUser?'#fff':'var(--t1)';
      var border=isUser?'none':'1px solid '+color+'30';
      return'<div style="display:flex;justify-content:'+align+';margin-bottom:8px"><div style="max-width:80%;padding:10px 14px;border-radius:12px;background:'+bg+';border:'+border+';color:'+tc+';font-size:13px;line-height:1.5"><div>'+(isUser?_msgE(m.content):(function(x){return typeof fmt==='function'?fmt(x):_msgE(x);})(m.card?String(m.content||'').split('\n\n\u200B')[0]:m.content))+'</div>'+(!isUser&&m.card&&window.LU_brandCard?window.LU_brandCard.slotHtml(m.card):'')+'<div style="font-size:9px;opacity:.6;margin-top:4px;text-align:right">'+_msgAgo(m.ts)+'</div></div></div>';
    }).join('');
    // 2026-05-22 FIX 12 — was scrollIntoView({block:'start'}) which yanked
    // the last message to the TOP of the feed (chat history shifted out of
    // view). Scroll to bottom to match standard chat-app conventions.
    if(!silent || _stick) feed.scrollTop = feed.scrollHeight;
  }catch(e){ if(!silent) feed.innerHTML='<div style="color:var(--rd);padding:20px;font-size:12px">Failed to load messages</div>'; }
}

// ── 2026-07-26 LIVE REFRESH ───────────────────────────────────────────────
// The widget previously polled only the unread badge, so an open thread never
// updated until the modal was reopened. This re-checks the open conversation
// and re-renders ONLY when it actually changed.
async function _msgRefreshOpenThread(){
  try{
    if(!_msg.open || !_msg.agent) return;                 // modal closed
    if(typeof document!=='undefined' && document.hidden) return;  // tab backgrounded
    if(document.getElementById('lu-msg-typing')) return;   // send in flight
    var feed=document.getElementById('lu-msg-feed'); if(!feed) return;

    var uiSlug=_msg.agent==='sarah'?'dmm':_msg.agent;
    var msgs=await _msgApi('GET','/agents/'+uiSlug+'/messages');
    if(!Array.isArray(msgs)) return;

    var cur=_msg.messages||[];
    var lastNew=msgs.length?msgs[msgs.length-1]:null;
    var lastCur=cur.length?cur[cur.length-1]:null;
    var changed = msgs.length!==cur.length
      || (lastNew&&lastCur&&String(lastNew.id||lastNew.ts)!==String(lastCur.id||lastCur.ts));
    if(!changed) return;                                   // nothing new — no DOM work

    _msgLoadThread(_msg.agent, true);
    // D4 — thread is open and visible, so new arrivals are read.
    _msgMarkRead(_msg.agent);
  }catch(e){ /* transient — next tick retries */ }
}
window._msgRefreshOpenThread=_msgRefreshOpenThread;

// ── 2026-07-26 TWO-PHASE PARITY ───────────────────────────────────────────
// POST /agents/{slug}/messages answers {pending, ack, ack_message_id, ...} —
// NOT {reply}. core.js handled this; this file did not, so replies never
// rendered here. Shared by the floater modal and the full-page view so the two
// surfaces cannot drift apart again.
function _msgTwoPhase(feed, resp, uiSlug, opts){
  opts = opts || {};
  var big       = !!opts.big;
  var typingId  = opts.typingId  || 'lu-msg-typing';
  var workingId = opts.workingId || 'lu-msg-working';
  if(!feed || !resp || !resp.pending) return false;   // ACK-OFF: a pending turn no longer carries an acknowledgement line

  var t=document.getElementById(typingId); if(t) t.remove();

  var pad  = big ? '12px 16px' : '10px 14px';
  var rad  = big ? '14px' : '12px';
  var fs   = big ? '14px' : '13px';
  var mb   = big ? '10px' : '8px';
  var aname = resp.agent_name || _msg.agent || 'Agent';

  // Phase 1 — the instant acknowledgement (ACK-OFF: only if the server still sends one).
  if (resp.ack) feed.innerHTML += '<div style="display:flex;justify-content:flex-start;margin-bottom:'+mb+'">'
    + '<div style="max-width:80%;padding:'+pad+';border-radius:'+rad+';background:var(--s2);color:var(--t1);'
    + 'font-size:'+fs+';line-height:1.5;border:1px solid var(--bd);opacity:.92">'
    + '<div style="font-size:9px;font-weight:700;color:var(--t3);margin-bottom:3px">'+_msgE(aname)+'</div>'
    + ((typeof fmt==='function')?fmt(resp.ack):_msgE(resp.ack))
    + '</div></div>';

  // "working…" pulse, mirroring the agent drawer.
  feed.innerHTML += '<div id="'+workingId+'" style="display:flex;align-items:center;gap:6px;'
    + 'padding:6px 12px;font-size:11px;color:var(--t3);opacity:.8;margin-bottom:'+mb+'">working…</div>';
  feed.scrollTop = feed.scrollHeight;

  // Phase 2 — bounded poll for the final row (same cadence/cap as core.js).
  var ackId    = resp.ack_message_id || 0;
  var everyMs  = resp.poll_interval_ms || 5000;
  var maxPolls = Math.ceil(90000 / everyMs);
  var polls    = 0;
  var key      = uiSlug + ':' + ackId;
  _msg.activePoll = _msg.activePoll || {};
  _msg.activePoll[key] = true;

  var tick = async function(){
    if(!_msg.activePoll[key]) return;
    polls++;
    if(polls > maxPolls){
      var w0=document.getElementById(workingId);
      if(w0) w0.innerHTML='<span style="color:var(--am,#F59E0B)">Taking longer than usual — reopen the chat to see the reply.</span>';
      delete _msg.activePoll[key];
      return;
    }
    try{
      var msgs = await _msgApi('GET','/agents/'+uiSlug+'/messages');
      if(Array.isArray(msgs)){
        for(var i=0;i<msgs.length;i++){
          var m=msgs[i];
          var isFinal = m && m.id && m.id > ackId && !m.is_ack
                        && (m.role==='agent' || (m.from!=='User' && m.from!=='user'));
          if(isFinal){
            delete _msg.activePoll[key];
            var w=document.getElementById(workingId); if(w) w.remove();
            var stick=(feed.scrollHeight-(feed.scrollTop+feed.clientHeight))<80;
            var col = m.error ? 'var(--rd,#dc2626)' : 'var(--t3)';
            feed.innerHTML += '<div style="display:flex;justify-content:flex-start;margin-bottom:'+mb+'">'
              + '<div style="max-width:80%;padding:'+pad+';border-radius:'+rad+';background:var(--s2);color:var(--t1);'
              + 'font-size:'+fs+';line-height:1.5;border:1px solid var(--bd)">'
              + '<div style="font-size:9px;font-weight:700;color:'+col+';margin-bottom:3px">'+_msgE(aname)+(m.error?' · error':'')+'</div>'
              + ((typeof fmt==='function')?fmt(m.content||''):_msgE(m.content||''))
              + '</div></div>';
            if(stick) feed.scrollTop = feed.scrollHeight;
            // keep the silent refresher's snapshot in step so it does not re-render
            _msg.messages = msgs;
            // D4 — you are looking at it, so it is read.
            _msgMarkRead(_msg.agent);
            return;
          }
        }
      }
    }catch(e){ /* transient — next tick retries */ }
    setTimeout(tick, everyMs);
  };
  setTimeout(tick, everyMs);
  return true;
}
window._msgTwoPhase=_msgTwoPhase;
window._msgSend=async function(){
  var inp=document.getElementById('lu-msg-input');if(!inp)return;
  var msg=inp.value.trim();if(!msg)return;
  inp.value='';

  var feed=document.getElementById('lu-msg-feed');

  // Show user message + typing immediately
  if(feed){
    feed.innerHTML+='<div style="display:flex;justify-content:flex-end;margin-bottom:8px"><div style="max-width:80%;padding:10px 14px;border-radius:12px;background:var(--p);color:#fff;font-size:13px;line-height:1.5">'+_msgE(msg)+'</div></div>';
    feed.innerHTML+='<div id="lu-msg-typing" style="display:flex;margin-bottom:8px"><div style="padding:10px 14px;border-radius:12px;background:var(--s2);color:var(--t3);font-size:12px;font-style:italic">typing...</div></div>';
    feed.scrollTop=feed.scrollHeight;
  }

  var uiSlug=_msg.agent==='sarah'?'dmm':_msg.agent;
  // v1.4.4 attach (floater) — fold uploaded attachments + clear chips.
  var _msgFloatBody = Object.assign({content:msg,from:'User'}, window._lgseActiveSiteUrl?{site_url:window._lgseActiveSiteUrl}:{});
  if (window.LU_attachComposer) {
    var _floatAtts = window.LU_attachComposer.getPending('lu-msg-input');
    if (_floatAtts && _floatAtts.length) _msgFloatBody.attachments = _floatAtts;
    window.LU_attachComposer.clear('lu-msg-input');
  }
  try{
    var _msgResp = await _msgApi('POST','/agents/'+uiSlug+'/messages',_msgFloatBody);
    // Wave 24 — Update chat counter badge.
    try {
      if (_msgResp && _msgResp.chat_meter && typeof window._lgseUpdateChatMeter === 'function') {
        window._lgseUpdateChatMeter(_msgResp.chat_meter.counter, !!_msgResp.chat_meter.debited);
      }
    } catch (_e) {}
    // 2026-05-22 FIX 12 — was: await _msgLoadThread(_msg.agent); which
    // rebuilt the entire feed from the DB on every send, visually wiping
    // the user's just-typed bubble + typing indicator and reconstructing
    // the whole panel. User reported this as "chat refreshes the page".
    // Now: remove typing, append the agent's reply directly from the
    // response (same pattern Aria/sendAssistant uses).
    var typing=document.getElementById('lu-msg-typing');if(typing)typing.remove();
    // 2026-07-26 — two-phase first; legacy `reply` retained below for
    // quick_action / image / non-fpm responses that still answer synchronously.
    if (_msgTwoPhase(feed, _msgResp, uiSlug, {typingId:'lu-msg-typing', workingId:'lu-msg-working'})) {
      _msgApi("POST","/messages/"+_msg.agent+"/read").catch(function(){});
      var _fb0=document.getElementById("lu-messages-badge");if(_fb0){_fb0.classList.remove("visible");_fb0.textContent="";}
      return;
    }
    if (feed && _msgResp && _msgResp.reply) {
      var aname = (_msgResp.agent_name || _msg.agent || 'Agent');
      var rendered = (typeof fmt === 'function') ? fmt(_msgResp.reply) : _msgE(_msgResp.reply);
      feed.innerHTML += '<div style="display:flex;justify-content:flex-start;margin-bottom:8px">'
                     +    '<div style="max-width:80%;padding:10px 14px;border-radius:12px;background:var(--s2);color:var(--t1);font-size:13px;line-height:1.5;border:1px solid var(--bd)">'
                     +      '<div style="font-size:9px;font-weight:700;color:var(--t3);margin-bottom:3px">'+_msgE(aname)+'</div>'
                     +      rendered
                     +    '</div>'
                     +  '</div>';
      feed.scrollTop = feed.scrollHeight;
    }
    // Mark current agent as read when modal opens
    _msgMarkRead(_msg.agent);   // server-authoritative (was: local badge blanking)
    // Poll badge immediately after send
    setTimeout(function(){if(window._msgPollUnread)window._msgPollUnread();},500);
  }catch(e){
    var typing=document.getElementById('lu-msg-typing');if(typing)typing.remove();
    feed=document.getElementById('lu-msg-feed');
    if(feed)feed.innerHTML+='<div style="text-align:center;padding:8px;color:var(--rd);font-size:11px">Send failed</div>';
  }
};

// ── Unread Badge Polling ───────────────────────────────────────────────────
window._msgPollUnread=async function(){
  try{
    var d=await _msgApi('GET','/messages/unread-count');
    var total=d.total||0;
    var badge=document.getElementById('lu-messages-badge');
    if(badge){
      if(total>0){badge.textContent=total;badge.classList.add('visible');}
      else{badge.classList.remove('visible');}
    }
    _msg.unread=d.by_agent||{};
  }catch(e){}
}

// ── Full Page Messages View ────────────────────────────────────────────────
window.messagesLoad=function(el){console.log("[Messages] messagesLoad called",el?el.id:"null");
  if(!el)return;
  _msg.agent='sarah';
  el.innerHTML='<div style="display:flex;height:100%;min-height:0">'
    +'<div id="lu-msg-page-agents" style="width:240px;border-right:1px solid var(--bd);overflow-y:auto;flex-shrink:0;padding:12px 0"></div>'
    +'<div style="flex:1;display:flex;flex-direction:column;min-width:0">'
      +'<div id="lu-msg-page-header" style="padding:16px 20px;border-bottom:1px solid var(--bd);flex-shrink:0"></div>'
      +'<div id="lu-msg-page-feed" style="flex:1;overflow-y:auto;padding:16px 20px"></div>'
      +'<div style="padding:12px 20px;border-top:1px solid var(--bd);display:flex;gap:10px">'
        +'<input id="lu-msg-page-input" type="text" placeholder="Type a message..." style="flex:1;background:var(--s2);border:1px solid var(--bd);border-radius:10px;color:var(--t1);padding:12px 16px;font-size:14px;outline:none;font-family:inherit" onkeydown="if(event.key===\'Enter\')_msgPageSend()">'
        +'<button onclick="_msgPageSend()" aria-label="Send" title="Send" style="display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;flex:0 0 42px;padding:0;background:#6C5CE7;color:#fff;border:none;border-radius:12px;cursor:pointer"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"></path><path d="m22 2-7 20-4-9-9-4Z"></path></svg></button>'
      +'</div>'
      +'<div id="lu-msg-page-fineprint" class="lu-ai-fineprint" style="font-size:11px;color:var(--t3);text-align:center;padding:4px 16px 8px;line-height:1.3"></div>'
    +'</div></div>';
  _msgLoadConversationsPage();
};

async function _msgLoadConversationsPage(){
  try{
    var d=await _msgApi('GET','/messages/conversations');
    _msg.conversations=d.conversations||[];
    _msgRenderPageAgents();
    _msgLoadPageThread(_msg.agent);
  }catch(e){}
}

function _msgRenderPageAgents(){
  _msgFineprint();
  var el=document.getElementById('lu-msg-page-agents');if(!el)return;
  el.innerHTML='<div style="padding:8px 16px 12px;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.05em">Conversations</div>'
    +_msg.conversations.map(function(c){
      var active=c.slug===_msg.agent;
      var color=AGENT_COLORS[c.slug]||'var(--t3)';
      var unread=c.unread>0?'<span style="background:#C0392B;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:700">'+c.unread+'</span>':'';
      var lastLine=c.last_message?_msgE(c.last_message.content).substring(0,40):'No messages yet';
      return'<div onclick="_msgPageSelect(\''+c.slug+'\')" style="padding:12px 16px;cursor:pointer;background:'+(active?'var(--s2)':'transparent')+';border-left:3px solid '+(active?color:'transparent')+';transition:all .15s">'
        +'<div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">'+(window.luAvatar?luAvatar(c.slug,32):'<div style="width:32px;height:32px;border-radius:50%;background:'+color+'22;border:1px solid '+color+'44;display:flex;align-items:center;justify-content:center;font-size:14px;color:'+color+';font-weight:700">'+c.name.charAt(0)+'</div>')+''
        +'<div style="flex:1"><div style="font-size:13px;font-weight:'+(active?'700':'500')+';color:var(--t1)">'+_msgE(c.name)+'</div><div style="font-size:10px;color:var(--t3)">'+_msgE(c.title||'')+'</div></div>'+unread+'</div>'
        +'<div style="font-size:11px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-left:42px">'+lastLine+'</div>'
        +'</div>';
    }).join('');
}

window._msgPageSelect=function(slug){
  _msg.agent=slug;
  _msgFineprint();
  _msgRenderPageAgents();
  _msgLoadPageThread(slug);
  _msgMarkRead(slug);   // server-authoritative; exactly once per action (RD-07)
};

async function _msgLoadPageThread(slug){
  var header=document.getElementById('lu-msg-page-header');
  var feed=document.getElementById('lu-msg-page-feed');
  if(!feed)return;

  var conv=_msg.conversations.find(function(c){return c.slug===slug;})||{name:slug,title:''};
  var color=AGENT_COLORS[slug]||'var(--t3)';
  if(header)header.innerHTML='<div style="display:flex;align-items:center;gap:12px">'+(window.luAvatar?luAvatar(slug,36):'<div style="width:36px;height:36px;border-radius:50%;background:'+color+'22;border:1px solid '+color+'44;display:flex;align-items:center;justify-content:center;font-size:16px;color:'+color+';font-weight:700">'+conv.name.charAt(0)+'</div>')+'<div><div style="font-size:15px;font-weight:700;color:var(--t1)">'+_msgE(conv.name)+'</div><div style="font-size:11px;color:var(--t3)">'+_msgE(conv.title||'Agent')+'</div></div></div>';

  feed.innerHTML='<div style="text-align:center;padding:20px;color:var(--t3);font-size:12px">Loading...</div>';
  var uiSlug=slug==='sarah'?'dmm':slug;
  try{
    var msgs=await _msgApi('GET','/agents/'+uiSlug+'/messages');
    var arr=Array.isArray(msgs)?msgs:[];
    if(arr.length===0){feed.innerHTML='<div style="text-align:center;padding:60px;color:var(--t3)"><div style="font-size:40px;margin-bottom:12px">'+window.icon("message",14)+'</div><div style="font-size:14px">No messages with '+_msgE(conv.name)+' yet.</div><div style="font-size:12px;margin-top:6px">Send a message to start working together.</div></div>';return;}
    feed.innerHTML=arr.map(function(m){
      var isUser=m.from==='User'||m.from==='user';
      // v1.4.4 — historical agent messages go through fmt() to match the
      // markdown + paragraph spacing applied to new replies. User messages
      // stay plain (typed text, no markdown).
      var __c = (!isUser && m.card) ? String(m.content || '').split('\n\n\u200B')[0] : m.content;   // CHAT-FIRST-1: the card shows the plain-words part
      var body = isUser ? _msgE(__c) : (typeof fmt === 'function' ? fmt(__c) : _msgE(__c));
      if (!isUser && m.card && window.LU_brandCard) body += window.LU_brandCard.slotHtml(m.card);   // BRAND-B1
      return'<div style="display:flex;justify-content:'+(isUser?'flex-end':'flex-start')+';margin-bottom:10px"><div style="max-width:70%;padding:12px 16px;border-radius:14px;background:'+(isUser?'var(--p)':color+'12')+';border:'+(isUser?'none':'1px solid '+color+'25')+';color:'+(isUser?'#fff':'var(--t1)')+';font-size:14px;line-height:1.6">'
        +'<div style="font-size:10px;font-weight:600;margin-bottom:4px;opacity:.7">'+(isUser?'You':_msgE(conv.name))+'</div>'
        +body
        +'<div style="font-size:10px;opacity:.5;margin-top:6px;text-align:right">'+_msgAgo(m.ts)+'</div></div></div>';
    }).join('');
    // 2026-05-22 FIX 12 — was scrollIntoView({block:'start'}) which yanked
    // the last message to the TOP of the feed (chat history shifted out of
    // view). Scroll to bottom to match standard chat-app conventions.
    feed.scrollTop = feed.scrollHeight;
  }catch(e){feed.innerHTML='<div style="color:var(--rd);padding:20px">Failed to load</div>';}
}

window._msgPageSend=async function(){
  var inp=document.getElementById('lu-msg-page-input');if(!inp)return;
  var msg=inp.value.trim();if(!msg)return;
  inp.value='';

  var feed=document.getElementById('lu-msg-page-feed');
  if(feed){
    feed.innerHTML+='<div style="display:flex;justify-content:flex-end;margin-bottom:10px"><div style="max-width:70%;padding:12px 16px;border-radius:14px;background:var(--p);color:#fff;font-size:14px;line-height:1.6"><div style="font-size:10px;font-weight:600;margin-bottom:4px;opacity:.7">You</div>'+_msgE(msg)+'</div></div>';
    feed.innerHTML+='<div id="lu-msg-page-typing" style="display:flex;margin-bottom:10px"><div style="padding:12px 16px;border-radius:14px;background:var(--s2);color:var(--t3);font-size:13px;font-style:italic">typing...</div></div>';
    feed.scrollTop=feed.scrollHeight;
  }

  var uiSlug=_msg.agent==='sarah'?'dmm':_msg.agent;
  // v1.4.4 attach (page) — fold uploaded attachments + clear chips.
  var _msgPageBody = Object.assign({content:msg,from:'User'}, window._lgseActiveSiteUrl?{site_url:window._lgseActiveSiteUrl}:{});
  if (window.LU_attachComposer) {
    var _pageAtts = window.LU_attachComposer.getPending('lu-msg-page-input');
    if (_pageAtts && _pageAtts.length) _msgPageBody.attachments = _pageAtts;
    window.LU_attachComposer.clear('lu-msg-page-input');
  }
  try{
    var _msgResp = await _msgApi('POST','/agents/'+uiSlug+'/messages',_msgPageBody);
    // Wave 24 — Update chat counter badge.
    try {
      if (_msgResp && _msgResp.chat_meter && typeof window._lgseUpdateChatMeter === 'function') {
        window._lgseUpdateChatMeter(_msgResp.chat_meter.counter, !!_msgResp.chat_meter.debited);
      }
    } catch (_e) {}
    // 2026-05-22 FIX 12 — same pattern as _msgSend: append agent reply
    // directly from POST response instead of rebuilding the entire feed.
    var typing=document.getElementById('lu-msg-page-typing');if(typing)typing.remove();
    // 2026-07-26 — same two-phase parity as the floater modal.
    if (_msgTwoPhase(feed, _msgResp, uiSlug, {big:true, typingId:'lu-msg-page-typing', workingId:'lu-msg-page-working'})) {
      return;
    }
    if (feed && _msgResp && _msgResp.reply) {
      var aname = (_msgResp.agent_name || _msg.agent || 'Agent');
      var rendered = (typeof fmt === 'function') ? fmt(_msgResp.reply) : _msgE(_msgResp.reply);
      feed.innerHTML += '<div style="display:flex;justify-content:flex-start;margin-bottom:10px">'
                     +    '<div style="max-width:70%;padding:12px 16px;border-radius:14px;background:var(--s2);color:var(--t1);font-size:14px;line-height:1.6;border:1px solid var(--bd)">'
                     +      '<div style="font-size:10px;font-weight:600;margin-bottom:4px;opacity:.7">'+_msgE(aname)+'</div>'
                     +      rendered
                     +    '</div>'
                     +  '</div>';
      feed.scrollTop = feed.scrollHeight;
    }
    setTimeout(function(){if(window._msgPollUnread)window._msgPollUnread();},500);
  }catch(e){
    var t=document.getElementById('lu-msg-page-typing');if(t)t.remove();
  }
};

// ── Init ───────────────────────────────────────────────────────────────────
function _msgInit(){ _msgCreateFloater(); }
// PLATFORM888 Phase 6: badge/thread polling is a governed background service.
// Starts after the primary route is interactive (luBg); singleton; pauses
// while the tab is hidden; refreshes the thread only when one is open.
function _msgStartPolling(){
  if(_msg.pollTimer) return;
  _msgPollUnread();
  _msg.pollTimer=setInterval(function(){ if(!document.hidden) _msgPollUnread(); },30000);   // perf 2026-09-21: was 10 s
  _msg.threadTimer=setInterval(function(){ if(_msg.open && !document.hidden) _msgRefreshOpenThread(); },5000);
}
function _msgStopPolling(){ if(_msg.pollTimer){clearInterval(_msg.pollTimer);_msg.pollTimer=null;} if(_msg.threadTimer){clearInterval(_msg.threadTimer);_msg.threadTimer=null;} }
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',_msgInit);
else _msgInit();
if(window.luBg){ window.luBg.register('messages',{start:_msgStartPolling,stop:_msgStopPolling}); }
else { (window.requestIdleCallback||function(f){setTimeout(f,1500);})(_msgStartPolling); }

})();


/* ══════════════════════════════════════════════════════════════════════════════════════════════
   FLOATER-2 (Owner 2026-09-24) — Sarah is draggable, and absent from chat surfaces.
   ══════════════════════════════════════════════════════════════════════════════════════════════ */

var _MSG_POS_KEY = 'lu.floater.pos';

/* Write a coordinate that survives the shell's own !important mobile pins. */
function _msgPlace(el, x, y){
  el.style.setProperty('left',   x + 'px', 'important');
  el.style.setProperty('top',    y + 'px', 'important');
  el.style.setProperty('right',  'auto',   'important');
  el.style.setProperty('bottom', 'auto',   'important');
}

/* Keep her fully on screen: a remembered position from a wide window must not strand her
   off the edge of a narrow one, and the keyboard opening counts as a resize. */
function _msgClamp(el, x, y){
  var m = 8, w = el.offsetWidth || 48, h = el.offsetHeight || 48;
  var maxX = Math.max(m, (window.innerWidth  || 360) - w - m);
  var maxY = Math.max(m, (window.innerHeight || 640) - h - m);
  return { x: Math.min(Math.max(m, x), maxX), y: Math.min(Math.max(m, y), maxY) };
}

function _msgRestorePos(el){
  var raw = null;
  try{ raw = localStorage.getItem(_MSG_POS_KEY); }catch(e){}
  if(!raw) return;                                   // never moved: keep the CSS default corner
  var p; try{ p = JSON.parse(raw); }catch(e){ return; }
  if(!p || typeof p.x !== 'number' || typeof p.y !== 'number') return;
  var c = _msgClamp(el, p.x, p.y);
  _msgPlace(el, c.x, c.y);
}

function _msgMakeDraggable(el){
  if(el._luDrag) return; el._luDrag = 1;
  var startX = 0, startY = 0, originX = 0, originY = 0, moved = false, id = null;

  el.addEventListener('pointerdown', function(e){
    if(e.button && e.button !== 0) return;
    var r = el.getBoundingClientRect();
    startX = e.clientX; startY = e.clientY; originX = r.left; originY = r.top;
    moved = false; id = e.pointerId;
    try{ el.setPointerCapture(id); }catch(err){}
  });

  el.addEventListener('pointermove', function(e){
    if(id === null || e.pointerId !== id) return;
    var dx = e.clientX - startX, dy = e.clientY - startY;
    /* Below the threshold this is still a tap. Treating every pixel as a drag would swallow the click
       that opens Sarah, which is the whole point of the button. */
    if(!moved && Math.abs(dx) + Math.abs(dy) < 6) return;
    if(!moved){ moved = true; el.classList.add('lu-dragging'); }
    e.preventDefault();
    var c = _msgClamp(el, originX + dx, originY + dy);
    _msgPlace(el, c.x, c.y);
  });

  function end(e){
    if(id === null || (e && e.pointerId !== id)) return;
    try{ el.releasePointerCapture(id); }catch(err){}
    id = null;
    if(!moved) return;
    el.classList.remove('lu-dragging');
    var r = _msgBaseRect(el);   /* FLOATER-4: the anchored spot, not the nudged/lifted one */
    try{ localStorage.setItem(_MSG_POS_KEY, JSON.stringify({ x: Math.round(r.left), y: Math.round(r.top) })); }catch(err){}
    /* Swallow exactly the click this drag would otherwise produce, and nothing after it. */
    var swallow = function(ev){ ev.stopPropagation(); ev.preventDefault(); };
    el.addEventListener('click', swallow, true);
    setTimeout(function(){ el.removeEventListener('click', swallow, true); moved = false; }, 0);
  }
  el.addEventListener('pointerup', end);
  el.addEventListener('pointercancel', end);

  window.addEventListener('resize', function(){
    if(!el.style.left) return;
    var r = _msgBaseRect(el), c = _msgClamp(el, r.left, r.top);   /* FLOATER-4 */
    _msgPlace(el, c.x, c.y);
  }, { passive: true });
}

/* FLOATER-4: where she is anchored, ignoring the nudge/keyboard transform that rides on top. */
function _msgBaseRect(el){
  var t = el.style.transform; el.style.transform = 'none';
  var r = el.getBoundingClientRect(); var out = { left: r.left, top: r.top, width: r.width, height: r.height };
  el.style.transform = t; return out;
}
/* FLOATER-4 watchdog: not on a chat surface, yet nowhere to be seen (off-screen, sizeless, or fully covered at her centre for
   two ticks) -> back to her corner, nudges cleared. Whatever moved her, the customer gets her back. */
(function(){
  var misses = 0;
  setInterval(function(){
    try{
      var el = document.getElementById('lu-messages-floater'); if(!el) return;
      if(document.hidden || document.body.classList.contains('lu-chat-surface') || el.classList.contains('lu-dragging')) { misses = 0; return; }
      if(getComputedStyle(el).display === 'none') return;
      var r = el.getBoundingClientRect(), W = window.innerWidth, H = (window.visualViewport && window.visualViewport.height) || window.innerHeight;
      var off = r.width < 8 || r.height < 8 || r.right < 4 || r.left > W - 4 || r.bottom < 4 || r.top > H - 4;
      var at = off ? null : document.elementFromPoint(r.left + r.width/2, r.top + r.height/2);
      var covered = !off && !(at && (at === el || el.contains(at)));
      if(!off && !covered){ misses = 0; return; }
      if(++misses < 2 && !off) return;
      misses = 0;
      el.style.removeProperty('--lu-fl-dx'); el.style.removeProperty('--lu-fl-dy');
      el.style.left = ''; el.style.top = ''; el.style.right = ''; el.style.bottom = '';
      try{ localStorage.removeItem(_MSG_POS_KEY); }catch(e){}
      if(typeof _msgSyncSoon === 'function') _msgSyncSoon();
    }catch(e){}
  }, 1500);
})()

/* A surface is a "chat surface" when a conversation is already on screen. */
function _msgOnChatSurface(){
  try{
    var v = (typeof currentView !== 'undefined' && currentView) ? String(currentView).toLowerCase() : '';
    /* FLOATER-3: her own view, Messages, Aria (an agent). Not the Chatbot engine, not anything else. */
    if(/^(sarah|messages|inbox|aria)$/.test(v)) return true;
    var vis = function(sel){
      var n = document.querySelector(sel);
      if(!n) return false;
      if(n.hidden || n.hasAttribute('inert') || n.getAttribute('aria-hidden') === 'true') return false;
      var cs = getComputedStyle(n);
      if(cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity || '1') < 0.05) return false;
      var r = n.getBoundingClientRect();
      if(r.width <= 40 || r.height <= 40) return false;
      /* FLOATER-3: a drawer parked off-canvas (translateX(100%)) is not on screen — the bug that hid her in Advanced. */
      return r.right > 8 && r.left < window.innerWidth - 8 && r.bottom > 8 && r.top < window.innerHeight - 8;
    };
    if(document.body.classList.contains('ai-panel-open')) return true;
    return vis('#agent-drawer') || vis('.agent-drawer.open') || vis('#task-drawer') || vis('#lu-msg-modal')
      || vis('#t3-arthur-feed') || vis('#arthur-input') || vis('#arthur-chat-input') || vis('.ai-panel.open') || vis('#ai-panel');
  }catch(e){ return false; }
}

var _msgSyncTimers = [];
function _msgSyncSoon(){
  _msgSyncTimers.forEach(clearTimeout); _msgSyncTimers = [];
  [60, 450, 1000].forEach(function(ms){ _msgSyncTimers.push(setTimeout(function(){ try{ document.body.classList.toggle('lu-chat-surface', _msgOnChatSurface()); }catch(e){} try{ _msgAvoidSoon(); }catch(e){} }, ms)); });
}
function _msgSyncSurface(){
  try{ document.body.classList.toggle('lu-chat-surface', _msgOnChatSurface()); }catch(e){}
  try{ _msgAvoidSoon(); }catch(e){}   /* FLOATER-3b: scheduled, never inline from a mutation */
  _msgSyncSoon();                     /* FLOATER-3f: and again once whatever is sliding has settled */
}

/* FLOATER-3: she must not sit on anything a person needs to press. Sample her footprint; if anything interactive
   (button, link, field, tab, toggle) is under it and is not hers, step up in 56px moves, then mirror to the other side,
   until the footprint is clear. Re-checked whenever the page changes, scrolls, resizes, or the keyboard opens. */
var _msgAvoidTimer = null;
function _msgAvoidNow(){
  var el = document.getElementById('lu-messages-floater'); if(!el) return;
  if(getComputedStyle(el).display === 'none') return;
  var INTER = 'button,a[href],a[onclick],input,textarea,select,[role="button"],[role="tab"],[role="switch"],[role="checkbox"],[role="link"],[onclick],[tabindex]:not([tabindex="-1"]),label,summary';
  /* FLOATER-3d/5: blocked = she overlaps a control at all, or covers its centre. */
  function blockedAt(){
    var r = el.getBoundingClientRect(), fa = r.width * r.height;
    /* FLOATER-3e: the field being typed into is never touched, not even at a corner. */
    var ae = document.activeElement;
    if(ae && ae !== el && !el.contains(ae) && ae !== document.body && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName || '') || (ae && ae.isContentEditable)){
      var ar = ae.getBoundingClientRect();
      if(ar.width > 0 && !(r.right <= ar.left || r.left >= ar.right || r.bottom <= ar.top || r.top >= ar.bottom)) return ae;
    }
    var pts = [[r.left+r.width/2, r.top+r.height/2],[r.left+6,r.top+6],[r.right-6,r.top+6],[r.left+6,r.bottom-6],[r.right-6,r.bottom-6]];
    var seen = [], worst = null, worstRatio = 0;
    for(var i=0;i<pts.length;i++){
      var list = document.elementsFromPoint(pts[i][0], pts[i][1]);
      for(var j=0;j<list.length;j++){
        var n = list[j];
        if(n === el || el.contains(n) || (n.closest && n.closest('#lu-messages-floater,#lu-msg-modal'))) continue;
        if(seen.indexOf(n) !== -1) continue; seen.push(n);
        var isField = (n === document.activeElement);
        if(!isField && !(n.matches && n.matches(INTER))) continue;
        if(n === document.body || n === document.documentElement) continue;
        var cs = getComputedStyle(n); if(cs.pointerEvents === 'none' || cs.visibility === 'hidden') continue;
        var b = n.getBoundingClientRect(); if(b.width < 4 || b.height < 4) continue;
        if(b.width * b.height > window.innerWidth * window.innerHeight * 0.6) continue;   // a page-sized click target is not "a button"
        var ix = Math.max(0, Math.min(r.right, b.right) - Math.max(r.left, b.left)), iy = Math.max(0, Math.min(r.bottom, b.bottom) - Math.max(r.top, b.top));
        var ratio = (ix * iy) / Math.max(1, b.width * b.height);
        var cx = b.left + b.width/2, cy = b.top + b.height/2, centre = cx >= r.left && cx <= r.right && cy >= r.top && cy <= r.bottom;
        if(ratio > 0.02 || centre || isField){   /* FLOATER-5 (Owner 09-29: SHOW button under the orb): ANY overlap with a control counts, not 35% */ if(ratio > worstRatio || !worst){ worst = n; worstRatio = Math.max(ratio, centre ? 0.5 : 0); } }
      }
    }
    return worst;
  }
  function blockedRatio(){ var n = blockedAt(); if(!n) return 0; var r = el.getBoundingClientRect(), b = n.getBoundingClientRect(); var ix = Math.max(0, Math.min(r.right, b.right) - Math.max(r.left, b.left)), iy = Math.max(0, Math.min(r.bottom, b.bottom) - Math.max(r.top, b.top)); return Math.max(0.01, (ix*iy)/Math.max(1,b.width*b.height)); }
  var setVars = function(dx, dy){
    var vx = dx + 'px', vy = dy + 'px';
    if(el.style.getPropertyValue('--lu-fl-dx') !== vx) el.style.setProperty('--lu-fl-dx', vx);
    if(el.style.getPropertyValue('--lu-fl-dy') !== vy) el.style.setProperty('--lu-fl-dy', vy);
  };
  if(_msgAvoidNow._busy) return; _msgAvoidNow._busy = true;
  var prevTransition = el.style.transition;
  el.style.transition = 'none';   /* FLOATER-3c: measure where she IS, not where a transition will put her */
  try{
  setVars(0, 0);
  void el.offsetWidth;
  var hit = blockedAt(); if(!hit) return;
  var base = el.getBoundingClientRect(), step = 56, dy = 0, dx = 0, tries = 0;
  var canMirror = true, best = { dx: 0, dy: 0, ratio: blockedRatio() };
  while(hit && tries++ < 30){
    if(base.top + dy - step >= 8){ dy -= step; }
    else if(canMirror){ canMirror = false; dy = 0; dx = (base.left > window.innerWidth/2) ? -(base.left - 8) : (window.innerWidth - base.right - 8); }
    else break;
    setVars(dx, dy);
    void el.offsetWidth;
    hit = blockedAt();
    var ratio = hit ? blockedRatio() : 0;
    if(ratio < best.ratio){ best = { dx: dx, dy: dy, ratio: ratio }; }
  }
  if(hit){ setVars(best.dx, best.dy); }   /* nothing fully clear: the least-blocking spot */
  } finally {
    void el.offsetWidth;
    el.style.transition = prevTransition;
    _msgAvoidNow._busy = false;
  }
}
function _msgAvoidSoon(){ clearTimeout(_msgAvoidTimer); _msgAvoidTimer = setTimeout(_msgAvoidNow, 180); }
function _msgKeepClear(el){
  window.addEventListener('resize', _msgAvoidSoon);
  window.addEventListener('scroll', _msgAvoidSoon, true);
  document.addEventListener('focusin', _msgAvoidSoon, true);
  document.addEventListener('focusout', _msgAvoidSoon, true);
  try{ if(window.visualViewport){ window.visualViewport.addEventListener('resize', _msgAvoidSoon); window.visualViewport.addEventListener('scroll', _msgAvoidSoon); } }catch(e){}
  el.addEventListener('pointerup', function(){ setTimeout(_msgAvoidSoon, 50); });
  setTimeout(_msgAvoidNow, 400); setTimeout(_msgAvoidNow, 1500);
}

try{
  /* The drawers are toggled by class and style rather than by being added and removed, so watch
     attributes as well as children, and re-check after navigation. */
  var mo = new MutationObserver(function(ms){
    /* FLOATER-3b: her own style writes (nudges, keyboard lift) must not re-trigger the sync — that loop froze the page. */
    for(var i=0;i<ms.length;i++){
      var t = ms[i].target;
      if(t && t.nodeType === 1 && (t.id === 'lu-messages-floater' || (t.closest && t.closest('#lu-messages-floater,#lu-msg-modal')))) continue;
      _msgSyncSurface(); return;
    }
  });
  mo.observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'] });
  window.addEventListener('hashchange', _msgSyncSurface);
  /* FLOATER-3f: drawers and panels slide; the check at the class change sees them still on screen. */
  document.addEventListener('transitionend', function(){ _msgSyncSoon(); }, true);
  document.addEventListener('animationend', function(){ _msgSyncSoon(); }, true);
  window.addEventListener('popstate', _msgSyncSurface);
  document.addEventListener('DOMContentLoaded', _msgSyncSurface);
  _msgSyncSurface();
}catch(e){}
