/**
 * LU Messages — Unified messaging system v1.0.0
 * Floater button + modal chat + sidebar nav page
 */
(function(){
'use strict';

var _msg = { open: false, agent: 'sarah', conversations: [], messages: [], unread: {}, pollTimer: null };
var AGENT_COLORS = {sarah:'#F59E0B',james:'#3B82F6',alex:'#06B6D4',priya:'#7C3AED',marcus:'#EC4899',elena:'#00E5A8',diana:'#F97316',ryan:'#10B981',sofia:'#8B5CF6',leo:'#EF4444'};

function _msgE(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function _msgAgo(ts){if(!ts)return'';var d=new Date(ts.replace(' ','T'));var m=Math.round((Date.now()-d)/60000);if(m<1)return'now';if(m<60)return m+'m ago';if(m<1440)return Math.floor(m/60)+'h ago';return Math.floor(m/1440)+'d ago';}
function _msgApi(method,path,body){var t=localStorage.getItem('lu_token')||'';var o={method:method,headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+t}};if(body)o.body=JSON.stringify(body);return fetch('/api'+path,o).then(function(r){return r.json();});}

// ── Floater Button ─────────────────────────────────────────────────────────
function _msgCreateFloater(){
  if(document.getElementById('lu-messages-floater'))return;
  var btn=document.createElement('div');
  btn.id='lu-messages-floater';
  btn.innerHTML=''+window.icon("message",14)+'<div id="lu-messages-badge"></div>';
  btn.onclick=_msgToggle;
  document.body.appendChild(btn);

  // Inject styles
  var style=document.createElement('style');
  style.textContent='#lu-messages-floater{position:fixed;bottom:24px;left:228px;width:48px;height:48px;border-radius:50%;background:var(--s2,#1e2030);border:2px solid var(--bd,#2a2d3e);cursor:pointer;z-index:999;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 4px 16px rgba(0,0,0,.3);transition:all .2s}#lu-messages-floater:hover{border-color:var(--p,#6C5CE7);transform:scale(1.05)}#lu-messages-badge{position:absolute;top:-4px;right:-4px;background:#e74c3c;color:#fff;border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;display:none;align-items:center;justify-content:center}#lu-messages-badge.visible{display:flex}'
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
    _msgApi("POST","/messages/"+_msg.agent+"/read").catch(function(){});
    var _fb=document.getElementById("lu-messages-badge");if(_fb){_fb.classList.remove("visible");_fb.textContent="";}
  }catch(e){console.error('[Messages]',e);}
}

function _msgRenderAgentList(){
  var el=document.getElementById('lu-msg-agents');if(!el)return;
  el.innerHTML=_msg.conversations.map(function(c){
    var active=c.slug===_msg.agent;
    var color=AGENT_COLORS[c.slug]||'var(--t3)';
    var uiSlug=c.slug==='sarah'?'dmm':c.slug;
    var unreadBadge=c.unread>0?'<span style="background:#e74c3c;color:#fff;border-radius:50%;width:16px;height:16px;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">'+c.unread+'</span>':'';
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
  _msgApi('POST','/messages/'+slug+'/read').then(function(){if(window._msgPollUnread)window._msgPollUnread();}).catch(function(){});var _fb=document.getElementById('lu-messages-badge');if(_fb){_fb.classList.remove('visible');_fb.textContent='';}
};

async function _msgLoadThread(slug){
  var feed=document.getElementById('lu-msg-feed');if(!feed)return;
  feed.innerHTML='<div style="text-align:center;padding:20px;color:var(--t3);font-size:12px">Loading...</div>';
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
      return'<div style="display:flex;justify-content:'+align+';margin-bottom:8px"><div style="max-width:80%;padding:10px 14px;border-radius:12px;background:'+bg+';border:'+border+';color:'+tc+';font-size:13px;line-height:1.5"><div>'+_msgE(m.content)+'</div><div style="font-size:9px;opacity:.6;margin-top:4px;text-align:right">'+_msgAgo(m.ts)+'</div></div></div>';
    }).join('');
    feed.scrollTop=feed.scrollHeight;
  }catch(e){feed.innerHTML='<div style="color:var(--rd);padding:20px;font-size:12px">Failed to load messages</div>';}
}

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
  try{
    await _msgApi('POST','/agents/'+uiSlug+'/messages',{content:msg,from:'User'});
    // Reload full thread to get both user message + agent reply from DB
    await _msgLoadThread(_msg.agent);
    // Mark current agent as read when modal opens
    _msgApi("POST","/messages/"+_msg.agent+"/read").catch(function(){});
    var _fb=document.getElementById("lu-messages-badge");if(_fb){_fb.classList.remove("visible");_fb.textContent="";}
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
      var unread=c.unread>0?'<span style="background:#e74c3c;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:700">'+c.unread+'</span>':'';
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
  _msgApi('POST','/messages/'+slug+'/read').then(function(){if(window._msgPollUnread)window._msgPollUnread();}).catch(function(){});var _fb=document.getElementById('lu-messages-badge');if(_fb){_fb.classList.remove('visible');_fb.textContent='';}
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
      return'<div style="display:flex;justify-content:'+(isUser?'flex-end':'flex-start')+';margin-bottom:10px"><div style="max-width:70%;padding:12px 16px;border-radius:14px;background:'+(isUser?'var(--p)':color+'12')+';border:'+(isUser?'none':'1px solid '+color+'25')+';color:'+(isUser?'#fff':'var(--t1)')+';font-size:14px;line-height:1.6">'
        +'<div style="font-size:10px;font-weight:600;margin-bottom:4px;opacity:.7">'+(isUser?'You':_msgE(conv.name))+'</div>'
        +_msgE(m.content)
        +'<div style="font-size:10px;opacity:.5;margin-top:6px;text-align:right">'+_msgAgo(m.ts)+'</div></div></div>';
    }).join('');
    feed.scrollTop=feed.scrollHeight;
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
  try{
    await _msgApi('POST','/agents/'+uiSlug+'/messages',{content:msg,from:'User'});
    await _msgLoadPageThread(_msg.agent);
    setTimeout(function(){if(window._msgPollUnread)window._msgPollUnread();},500);
  }catch(e){
    var t=document.getElementById('lu-msg-page-typing');if(t)t.remove();
  }
};

// ── Init ───────────────────────────────────────────────────────────────────
function _msgInit(){
  _msgCreateFloater();
  _msgPollUnread();
  _msg.pollTimer=setInterval(_msgPollUnread,10000);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',_msgInit);
else _msgInit();

})();
