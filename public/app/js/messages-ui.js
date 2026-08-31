/**
 * LU Messages — Unified messaging system v1.0.0
 * Floater button + modal chat + sidebar nav page
 */
(function(){
'use strict';

var _msg = { open: false, agent: 'sarah', conversations: [], messages: [], unread: {}, pollTimer: null };
var AGENT_COLORS = {sarah:'#F59E0B',james:'#3B82F6',alex:'#06B6D4',priya:'#7C3AED',marcus:'#EC4899',elena:'#00E5A8',diana:'#F97316',ryan:'#10B981',sofia:'#8B5CF6',leo:'#EF4444'};

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
function _msgCreateFloater(){
  if(document.getElementById('lu-messages-floater'))return;
  var btn=document.createElement('div');
  btn.id='lu-messages-floater'; btn.setAttribute('data-adv','1'); btn.setAttribute('aria-label','Messages'); // P1-U2: Advanced-only — Basic talks to Sarah on the home
  btn.innerHTML=''+window.icon("message",14)+'<div id="lu-messages-badge"></div>';
  btn.onclick=_msgToggle;
  document.body.appendChild(btn);

  // Inject styles
  var style=document.createElement('style');
  style.textContent='#lu-messages-floater{position:fixed;bottom:24px;left:228px;width:48px;height:48px;border-radius:50%;background:var(--s2,#1e2030);border:2px solid var(--bd,#2a2d3e);cursor:pointer;z-index:999;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 4px 16px rgba(0,0,0,.3);transition:all .2s}#lu-messages-floater:hover{border-color:var(--p,#6C5CE7);transform:scale(1.05)}#lu-messages-badge{position:absolute;top:-4px;right:-4px;background:#C0392B;color:#fff;border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;display:none;align-items:center;justify-content:center}#lu-messages-badge.visible{display:flex}'
    +'#lu-msg-modal{position:fixed;bottom:80px;left:228px;width:560px;height:480px;background:var(--s1,#161927);border:1px solid var(--bd,#2a2d3e);border-radius:16px;z-index:1000;display:none;flex-direction:column;overflow:hidden;box-shadow:0 12px 48px rgba(0,0,0,.5);animation:msgSlideUp .2s ease}'
    +'@keyframes msgSlideUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}'
    +'@media(max-width:768px){#lu-messages-floater{left:16px;bottom:80px}#lu-msg-modal{left:8px;right:8px;width:auto;bottom:136px;height:60vh}}';
  document.head.appendChild(style);
}

window._msgToggle=function(){
  _msg.open=!_msg.open;
  var modal=document.getElementById('lu-msg-modal');
  if(!modal){_msgCreateModal();modal=document.getElementById('lu-msg-modal');}
  if(_msg.open){
    modal.style.display='flex';
    _msgLoadConversations();
  }else{
    modal.style.display='none';
  }
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

function _msgRenderAgentList(){
  var el=document.getElementById('lu-msg-agents');if(!el)return;
  el.innerHTML=_msg.conversations.map(function(c){
    var active=c.slug===_msg.agent;
    var color=AGENT_COLORS[c.slug]||'var(--t3)';
    var uiSlug=c.slug==='sarah'?'dmm':c.slug;
    var unreadBadge=c.unread>0?'<span style="background:#C0392B;color:#fff;border-radius:50%;width:16px;height:16px;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">'+c.unread+'</span>':'';
    var lastMsg=c.last_message?'<div style="font-size:10px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px">'+_msgE(c.last_message.content).substring(0,30)+'</div>':'';
    return'<div onclick="_msgSelectAgent(\''+c.slug+'\')" style="padding:10px 12px;cursor:pointer;border-left:3px solid '+(active?color:'transparent')+';background:'+(active?'var(--s2)':'transparent')+';transition:all .15s" onmouseover="this.style.background=\'var(--s2)\'" onmouseout="this.style.background=\''+(active?'var(--s2)':'transparent')+'\'">'
      +'<div style="display:flex;align-items:center;gap:8px"><div style="width:28px;height:28px;border-radius:50%;background:'+color+'22;border:1px solid '+color+'44;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;color:'+color+';font-weight:700">'+c.name.charAt(0)+'</div>'
      +'<div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:'+(active?'700':'500')+';color:'+(active?'var(--t1)':'var(--t2)')+'">'+_msgE(c.name)+'</div>'+lastMsg+'</div>'+unreadBadge+'</div></div>';
  }).join('');
}

window._msgSelectAgent=function(slug){
  _msg.agent=slug;
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
      return'<div style="display:flex;justify-content:'+align+';margin-bottom:8px"><div style="max-width:80%;padding:10px 14px;border-radius:12px;background:'+bg+';border:'+border+';color:'+tc+';font-size:13px;line-height:1.5"><div>'+(isUser?_msgE(m.content):(typeof fmt==='function'?fmt(m.content):_msgE(m.content)))+'</div><div style="font-size:9px;opacity:.6;margin-top:4px;text-align:right">'+_msgAgo(m.ts)+'</div></div></div>';
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
  if(!feed || !resp || !resp.pending || !resp.ack) return false;

  var t=document.getElementById(typingId); if(t) t.remove();

  var pad  = big ? '12px 16px' : '10px 14px';
  var rad  = big ? '14px' : '12px';
  var fs   = big ? '14px' : '13px';
  var mb   = big ? '10px' : '8px';
  var aname = resp.agent_name || _msg.agent || 'Agent';

  // Phase 1 — the instant acknowledgement.
  feed.innerHTML += '<div style="display:flex;justify-content:flex-start;margin-bottom:'+mb+'">'
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
  var everyMs  = resp.poll_interval_ms || 2500;
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
        +'<button onclick="_msgPageSend()" style="background:var(--p);color:#fff;border:none;border-radius:10px;padding:12px 20px;font-size:14px;cursor:pointer;font-weight:600">Send \u2192</button>'
      +'</div>'
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
  var el=document.getElementById('lu-msg-page-agents');if(!el)return;
  el.innerHTML='<div style="padding:8px 16px 12px;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.05em">Conversations</div>'
    +_msg.conversations.map(function(c){
      var active=c.slug===_msg.agent;
      var color=AGENT_COLORS[c.slug]||'var(--t3)';
      var unread=c.unread>0?'<span style="background:#C0392B;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:700">'+c.unread+'</span>':'';
      var lastLine=c.last_message?_msgE(c.last_message.content).substring(0,40):'No messages yet';
      return'<div onclick="_msgPageSelect(\''+c.slug+'\')" style="padding:12px 16px;cursor:pointer;background:'+(active?'var(--s2)':'transparent')+';border-left:3px solid '+(active?color:'transparent')+';transition:all .15s">'
        +'<div style="display:flex;align-items:center;gap:10px;margin-bottom:4px"><div style="width:32px;height:32px;border-radius:50%;background:'+color+'22;border:1px solid '+color+'44;display:flex;align-items:center;justify-content:center;font-size:14px;color:'+color+';font-weight:700">'+c.name.charAt(0)+'</div>'
        +'<div style="flex:1"><div style="font-size:13px;font-weight:'+(active?'700':'500')+';color:var(--t1)">'+_msgE(c.name)+'</div><div style="font-size:10px;color:var(--t3)">'+_msgE(c.title||'')+'</div></div>'+unread+'</div>'
        +'<div style="font-size:11px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-left:42px">'+lastLine+'</div>'
        +'</div>';
    }).join('');
}

window._msgPageSelect=function(slug){
  _msg.agent=slug;
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
  if(header)header.innerHTML='<div style="display:flex;align-items:center;gap:12px"><div style="width:36px;height:36px;border-radius:50%;background:'+color+'22;border:1px solid '+color+'44;display:flex;align-items:center;justify-content:center;font-size:16px;color:'+color+';font-weight:700">'+conv.name.charAt(0)+'</div><div><div style="font-size:15px;font-weight:700;color:var(--t1)">'+_msgE(conv.name)+'</div><div style="font-size:11px;color:var(--t3)">'+_msgE(conv.title||'Agent')+'</div></div></div>';

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
      var body = isUser ? _msgE(m.content) : (typeof fmt === 'function' ? fmt(m.content) : _msgE(m.content));
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
  _msg.pollTimer=setInterval(function(){ if(!document.hidden) _msgPollUnread(); },10000);
  _msg.threadTimer=setInterval(function(){ if(_msg.open && !document.hidden) _msgRefreshOpenThread(); },5000);
}
function _msgStopPolling(){ if(_msg.pollTimer){clearInterval(_msg.pollTimer);_msg.pollTimer=null;} if(_msg.threadTimer){clearInterval(_msg.threadTimer);_msg.threadTimer=null;} }
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',_msgInit);
else _msgInit();
if(window.luBg){ window.luBg.register('messages',{start:_msgStartPolling,stop:_msgStopPolling}); }
else { (window.requestIdleCallback||function(f){setTimeout(f,1500);})(_msgStartPolling); }

})();
