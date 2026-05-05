// LevelUp CALENDAR Engine — v2.1.0
// Patches: direct calendar REST calls, fixed nonce, list-view edit/delete, proper error handling

window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['calendar'] = true;

// ═══════════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════════
var _cal = {
  events:       [],
  currentEvent: null,
  filters:      { date: '', type: '' },
  loading:      false,
};

// ═══════════════════════════════════════════════════════════════════
// API HELPER (Patches 4+5)
// ═══════════════════════════════════════════════════════════════════
async function _calApi(method, path, body) {
  const nonce = window.LU_CFG?.nonce || '';
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization':'Bearer '+(localStorage.getItem('lu_token')||'') },
    cache: 'no-store',
  };
  if (body) opts.body = JSON.stringify(body);
  let r;
  try { r = await fetch(window.location.origin + '/api' + path, opts); }
  catch(e) { throw new Error('Network error: ' + e.message); }

  if (r.status === 401) throw Object.assign(new Error('Session expired — please refresh.'), { code: 401 });
  if (r.status === 402) throw Object.assign(new Error('Insufficient credits.'), { code: 402 });
  if (r.status === 429) throw Object.assign(new Error('Rate limited — please wait.'), { code: 429 });
  if (!r.ok) { const d=await r.json().catch(()=>({})); throw new Error(d.message||d.error||'Error '+r.status); }
  return r.json();
}

// ═══════════════════════════════════════════════════════════════════
// LOAD
// ═══════════════════════════════════════════════════════════════════
async function calLoad(el) {
  if (!el) return;
  el.innerHTML = loadingCard(300);
  try {
    const [events, bookings] = await Promise.all([
      _calApi('GET', '/calendar/events'),
      _calApi('GET', '/calendar/booking-slots').catch(() => []),
    ]);
    _cal.events = Array.isArray(events) ? events : (events?.events || []);
    const _bookings = Array.isArray(bookings) ? bookings : (bookings?.slots || []);
    _calRender(el, _cal.events, _bookings);
  } catch(e) {
    console.error('[Calendar]', e);
    el.innerHTML = `<div style="padding:60px;text-align:center;color:var(--t2)">
      <div style="font-size:32px;margin-bottom:12px">${window.icon('warning',14)}</div>
      <div style="font-size:14px;font-weight:600;margin-bottom:6px">Calendar failed to load</div>
      <div style="font-size:12px;color:var(--t3)">${friendlyError(e)}</div>
      <button class="btn btn-outline btn-sm" style="margin-top:16px" onclick="calLoad(document.getElementById('calendar-root'))">↺ Retry</button></div>`;
  }
}

function _calRender(el, evts, bookings) {
  if (!window._calViewDate) window._calViewDate = new Date();
  const viewDate = window._calViewDate;
  const year=viewDate.getFullYear(), month=viewDate.getMonth();
  const now=new Date(), today=(now.getFullYear()===year&&now.getMonth()===month)?now.getDate():-1;
  const monthName=viewDate.toLocaleString('default',{month:'long'});
  const firstDay=new Date(year,month,1).getDay();
  const daysInMonth=new Date(year,month+1,0).getDate();
  const eventsByDate={};
  evts.forEach(ev=>{
    const d=ev.start_at||ev.start_date||ev.date||ev.start;
    if(!d) return;
    const day=new Date(d).getDate();
    const m=new Date(d).getMonth(), y=new Date(d).getFullYear();
    if(y===year&&m===month){if(!eventsByDate[day])eventsByDate[day]=[];eventsByDate[day].push(ev);}
  });

  let cells='', dayNum=1;
  for(let row=0;row<6;row++){
    for(let col=0;col<7;col++){
      const idx=row*7+col;
      if(idx<firstDay||dayNum>daysInMonth){cells+=`<div class="cal-cell empty"></div>`;}
      else{
        const evs=eventsByDate[dayNum]||[];
        const isTod=dayNum===today;
        cells+=`<div class="cal-cell${isTod?' today':''}" onclick="calDayClick(${dayNum})">
          <span class="cal-day-num${isTod?' active':''}">${dayNum}</span>
          ${evs.slice(0,2).map(ev=>`<div class="cal-event-dot">${(ev.title||ev.name||'').slice(0,12)||'Event'}</div>`).join('')}
          ${evs.length>2?`<div style="font-size:9px;color:var(--t3)">+${evs.length-2}</div>`:''}
        </div>`;
        dayNum++;
      }
    }
    if(dayNum>daysInMonth) break;
  }

  el.innerHTML = `
  <style>
  .cal-grid-header{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:4px}
  .cal-dow{text-align:center;font-size:11px;font-weight:600;color:var(--text-3);padding:6px 0;text-transform:uppercase}
  .cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
  .cal-cell{min-height:72px;padding:6px;border:1px solid var(--border);border-radius:4px;cursor:pointer;transition:border-color .15s}
  .cal-cell:hover{border-color:var(--blue)}
  .cal-cell.empty{background:var(--surface-0);border-color:transparent;cursor:default}
  .cal-cell.today{border-color:var(--blue);background:rgba(59,111,245,.06)}
  .cal-day-num{font-size:12px;font-weight:600;color:var(--text-2);display:block;margin-bottom:3px}
  .cal-day-num.active{background:var(--blue);color:#fff;width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px}
  .cal-event-dot{font-size:10px;background:var(--blue-soft);color:var(--blue);border-radius:3px;padding:1px 4px;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  </style>
  <div class="page-header" style="margin-top:10px">
    <div class="page-header-left"><h1>Calendar</h1><p>${evts.length} event${evts.length!==1?'s':''} · ${bookings.length} booking slot${bookings.length!==1?'s':''}</p></div>
    <div class="page-header-actions">
      <button class="btn btn-outline btn-sm" onclick="calLoad(document.getElementById('calendar-root'))">↺ Refresh</button>
      <button class="btn btn-primary" onclick="calNewEvent()">${icons.plus} New Event</button>
    </div>
  </div>

  <div style="display:flex;gap:8px;margin-bottom:20px">
    <button class="tab active" data-cal-tab="month"    onclick="calSetTab(this,'month')">${window.icon('calendar',14)} Month</button>
    <button class="tab"        data-cal-tab="list"     onclick="calSetTab(this,'list')">${window.icon('more',14)} List</button>
    <button class="tab"        data-cal-tab="bookings" onclick="calSetTab(this,'bookings')">${window.icon('more',14)} Bookings</button>
  </div>

  <!-- MONTH VIEW -->
  <div id="cal-month-panel">
    <div class="card">
      <div class="card-header"><h3>${monthName} ${year}</h3>
        <div style="display:flex;gap:6px">
          <button class="btn btn-ghost btn-sm" onclick="calNavigate(-1)">‹</button>
          <button class="btn btn-ghost btn-sm" onclick="calNavigate(1)">›</button>
        </div>
      </div>
      <div class="card-body" style="padding:0 16px 16px">
        <div class="cal-grid-header">${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(d=>`<div class="cal-dow">${d}</div>`).join('')}</div>
        <div class="cal-grid">${cells}</div>
      </div>
    </div>
  </div>

  <!-- LIST VIEW -->
  <div id="cal-list-panel" style="display:none">
    ${evts.length===0
      ? `<div class="card card-body" style="text-align:center;padding:60px 20px">
          <div style="font-size:40px;margin-bottom:14px">${window.icon('calendar',14)}</div>
          <h3 style="margin:0 0 8px">No events yet</h3>
          <p style="color:var(--text-3);margin:0 0 20px;font-size:13px">Create events and appointments to manage your schedule.</p>
          <button class="btn btn-primary" onclick="calNewEvent()" style="margin:0 auto">${icons.plus} Create Event</button>
        </div>`
      : `<div class="card"><div class="card-header"><h3>All Events</h3></div>
          <div class="table-wrap"><table>
            <thead><tr><th>Event</th><th>Date</th><th>Time</th><th>Type</th><th>Status</th><th></th></tr></thead>
            <tbody>${[...evts].sort((a,b)=>new Date(a.start_at||a.start_date||a.date||0)-new Date(b.start_at||b.start_date||b.date||0)).map(ev=>`
            <tr>
              <td><strong>${ev.title||ev.name||'Untitled'}</strong>${ev.description?`<div style="font-size:11px;color:var(--t3)">${ev.description.slice(0,60)}</div>`:''}</td>
              <td style="color:var(--t3)">${ev.start_at||ev.start_date||ev.date?new Date(ev.start_at||ev.start_date||ev.date).toLocaleDateString():'—'}</td>
              <td style="color:var(--t3)">${ev.start_time||ev.time||'All day'}</td>
              <td><span class="badge badge-blue">${ev.event_type||ev.type||'Event'}</span></td>
              <td><span class="badge ${ev.status==='cancelled'?'badge-red':ev.status==='completed'?'badge-grey':'badge-green'}">${ev.status||'upcoming'}</span></td>
              <td><div style="display:flex;gap:4px">
                <button class="btn btn-outline btn-sm" onclick="calEditEventById(${ev.id})" style="font-size:11px">${window.icon('edit',14)}</button>
                <button class="btn btn-ghost btn-sm" style="color:var(--rd);font-size:11px" onclick="calDeleteEvent(${ev.id},'${(ev.title||'').replace(/'/g,'')}')">${window.icon('delete',14)}</button>
              </div></td>
            </tr>`).join('')}</tbody>
          </table></div>
        </div>`}
  </div>

  <!-- BOOKINGS VIEW -->
  <div id="cal-bookings-panel" style="display:none">
    ${bookings.length===0
      ? `<div class="card card-body" style="text-align:center;padding:60px 20px">
          <div style="font-size:40px;margin-bottom:14px">${window.icon('more',14)}</div>
          <h3 style="margin:0 0 8px">No booking slots yet</h3>
          <p style="color:var(--text-3);margin:0 0 16px;font-size:13px">Create booking slots so clients can schedule appointments.</p>
          <button class="btn btn-primary btn-sm" onclick="calNewBookingSlot()">+ New Booking Slot</button>
        </div>`
      : `<div class="card">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
            <h3>Booking Slots</h3><button class="btn btn-primary btn-sm" onclick="calNewBookingSlot()">+ New Slot</button>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>Slot</th><th>Duration</th><th>Date</th><th>Status</th></tr></thead>
            <tbody>${bookings.map(b=>`<tr>
              <td><strong>${b.title||b.service||b.name||'Slot'}</strong>${b.description?`<div style="font-size:11px;color:var(--t3)">${b.description.slice(0,60)}</div>`:''}</td>
              <td style="font-size:12px">${b.duration_minutes||b.duration?((b.duration_minutes||b.duration)+' min'):'—'}</td>
              <td style="color:var(--t3)">${b.start_at||b.date?new Date(b.start_at||b.date).toLocaleDateString():'—'}</td>
              <td><span class="badge ${b.status==='booked'?'badge-green':b.status==='cancelled'?'badge-red':'badge-amber'}">${b.status||'available'}</span></td>
            </tr>`).join('')}</tbody>
          </table></div>
        </div>`}
  </div>`;

  // Tab switcher
  window.calSetTab = (btn, tab) => {
    document.querySelectorAll('[data-cal-tab]').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('cal-month-panel').style.display    = tab==='month' ? '' : 'none';
    document.getElementById('cal-list-panel').style.display     = tab==='list'  ? '' : 'none';
    document.getElementById('cal-bookings-panel').style.display = tab==='bookings' ? '' : 'none';
  };

  // Day click — show events for day
  window.calDayClick = (day) => {
    const evs = eventsByDate[day] || [];
    if (evs.length===0) { calNewEvent(day); return; }
    const bd=document.createElement('div');bd.className='modal-backdrop';bd.onclick=e=>{if(e.target===bd)bd.remove();};
    const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    bd.innerHTML=`<div class="modal" style="max-width:440px">
      <div class="modal-header"><h3>${months[month]} ${day}, ${year}</h3><button class="modal-close" onclick="this.closest('.modal-backdrop').remove()">✕</button></div>
      <div class="modal-body" style="padding:16px">
        ${evs.map(ev=>`<div style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;border:1px solid var(--border);border-radius:var(--radius)">
          <div style="flex:1">
            <div style="font-weight:600;font-size:13px">${_escCal(ev.title||ev.name||'Event')}</div>
            <div style="font-size:11px;color:var(--t3)">${ev.start_time||ev.time||'All day'}${ev.event_type||ev.type?' · '+(ev.event_type||ev.type):''}</div>
            ${ev.description?'<div style="font-size:11px;color:var(--t2);margin-top:4px">'+_escCal(ev.description)+'</div>':''}
          </div>
          <button class="btn btn-ghost btn-sm" onclick="this.closest('.modal-backdrop').remove();calEditEventById(${ev.id})">${window.icon('edit',14)}</button>
          <button class="btn btn-ghost btn-sm" style="color:var(--rd)" onclick="calDeleteEvent(${ev.id},'${_escCal(ev.title||'').replace(/'/g,'')}')">${window.icon('delete',14)}</button>
        </div>`).join('')}
        <button class="btn btn-outline btn-sm" onclick="this.closest('.modal-backdrop').remove();calNewEvent(${day})" style="width:100%;margin-top:8px">${icons.plus} Add Event</button>
      </div>
    </div>`;
    document.body.appendChild(bd);bd.style.opacity='1';bd.style.pointerEvents='all';
  };
}

// ═══════════════════════════════════════════════════════════════════
// NAVIGATION
// ═══════════════════════════════════════════════════════════════════
function calNavigate(dir) {
  if (!window._calViewDate) window._calViewDate = new Date();
  window._calViewDate = new Date(window._calViewDate.getFullYear(), window._calViewDate.getMonth()+dir, 1);
  const el = document.getElementById('calendar-root');
  if (el) calLoad(el);
}

// ═══════════════════════════════════════════════════════════════════
// EVENT CRUD — all direct calendar calls (Patch 4)
// ═══════════════════════════════════════════════════════════════════
window.calNewEvent = function(preDay) {
  const vd=window._calViewDate||new Date();
  const year=vd.getFullYear(), month=vd.getMonth();
  const defDate=preDay?year+'-'+String(month+1).padStart(2,'0')+'-'+String(preDay).padStart(2,'0'):'';
  const bd=document.createElement('div');bd.className='modal-backdrop';bd.onclick=e=>{if(e.target===bd)bd.remove();};
  bd.innerHTML=`<div class="modal" style="max-width:440px">
    <div class="modal-header"><h3>New Event</h3><button class="modal-close" onclick="this.closest('.modal-backdrop').remove()">✕</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Title *</label><input class="form-input" id="ce-t" placeholder="e.g. Client Meeting"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Date</label><input type="date" class="form-input" id="ce-d" value="${defDate}"></div>
        <div class="form-group"><label class="form-label">Time</label><input type="time" class="form-input" id="ce-tm"></div>
      </div>
      <div class="form-group"><label class="form-label">End Time (optional)</label><input type="time" class="form-input" id="ce-et"></div>
      <div class="form-group"><label class="form-label">Type</label>
        <select class="form-select" id="ce-ty"><option value="meeting">Meeting</option><option value="call">Call</option><option value="deadline">Deadline</option><option value="reminder">Reminder</option><option value="event">Event</option></select>
      </div>
      <div class="form-group"><label class="form-label">Notes</label><textarea class="form-input" id="ce-n" style="min-height:60px;resize:vertical" placeholder="Optional…"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="this.closest('.modal-backdrop').remove()">Cancel</button>
      <button class="btn btn-primary" id="ce-save">${icons.plus} Create Event</button>
    </div></div>`;
  document.body.appendChild(bd);bd.style.opacity='1';bd.style.pointerEvents='all';
  bd.querySelector('#ce-save').onclick=async function(){
    const title=bd.querySelector('#ce-t').value.trim();
    if(!title){showToast('Enter a title.','error');return;}
    const btn2=bd.querySelector('#ce-save');btn2.disabled=true;btn2.textContent='Creating…';
    try {
      const d=bd.querySelector('#ce-d').value;
      const tm=bd.querySelector('#ce-tm').value;
      const et=bd.querySelector('#ce-et').value;
      const start_at=d?d+' '+(tm||'09:00')+':00':null;
      const end_at=d&&et?d+' '+et+':00':start_at;
      const ev=await _calApi('POST','/calendar/events',{
        title, event_type: bd.querySelector('#ce-ty').value,
        start_at, end_at,
        description: bd.querySelector('#ce-n').value.trim(),
        workspace_id: 1,
      });
      _cal.events.unshift(ev.event||ev);
      bd.remove();showToast('Event created!','success');
      calLoad(document.getElementById('calendar-root'));
    } catch(e) {
      showToast(e.message,'error');btn2.disabled=false;btn2.textContent='Create Event';
    }
  };
};

window.calEditEventById = async function(id) {
  let ev=_cal.events.find(e=>e.id===id)||{};
  if(!ev.id){
    try{ ev=await _calApi('GET','/calendar/events/'+id); }
    catch(e){showToast('Load failed: '+e.message,'error');return;}
  }
  const startAt=ev.start_at||'';
  const startDate=startAt.slice(0,10);
  const startTime=startAt.slice(11,16);
  const endAt=ev.end_at||'';
  const endTime=endAt.slice(11,16);

  const bd=document.createElement('div');bd.className='modal-backdrop';bd.onclick=e=>{if(e.target===bd)bd.remove();};
  bd.innerHTML=`<div class="modal" style="max-width:440px">
    <div class="modal-header"><h3>Edit Event</h3><button class="modal-close" onclick="this.closest('.modal-backdrop').remove()">✕</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Title *</label><input class="form-input" id="ee-t" value="${_escCal(ev.title||'')}"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Date</label><input type="date" class="form-input" id="ee-d" value="${startDate}"></div>
        <div class="form-group"><label class="form-label">Start Time</label><input type="time" class="form-input" id="ee-tm" value="${startTime}"></div>
      </div>
      <div class="form-group"><label class="form-label">End Time</label><input type="time" class="form-input" id="ee-et" value="${endTime}"></div>
      <div class="form-group"><label class="form-label">Type</label>
        <select class="form-select" id="ee-ty">
          ${['meeting','call','deadline','reminder','event'].map(t=>`<option value="${t}"${(ev.event_type||ev.type||'meeting')===t?' selected':''}>${t.charAt(0).toUpperCase()+t.slice(1)}</option>`).join('')}
        </select></div>
      <div class="form-group"><label class="form-label">Notes</label><textarea class="form-input" id="ee-n" style="min-height:60px;resize:vertical">${_escCal(ev.description||ev.notes||'')}</textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="this.closest('.modal-backdrop').remove()">Cancel</button>
      <button class="btn btn-primary" id="ee-save">${window.icon('save',14)} Save</button>
    </div></div>`;
  document.body.appendChild(bd);bd.style.opacity='1';bd.style.pointerEvents='all';
  bd.querySelector('#ee-save').onclick=async function(){
    const title=bd.querySelector('#ee-t').value.trim();
    if(!title){showToast('Enter a title.','error');return;}
    const btn2=bd.querySelector('#ee-save');btn2.disabled=true;btn2.textContent='Saving…';
    try{
      const d=bd.querySelector('#ee-d').value;
      const tm=bd.querySelector('#ee-tm').value;
      const et=bd.querySelector('#ee-et').value;
      const start_at=d?d+' '+(tm||'09:00')+':00':null;
      const end_at=d&&et?d+' '+et+':00':start_at;
      const updated=await _calApi('PUT','/calendar/events/'+id,{
        title, event_type: bd.querySelector('#ee-ty').value,
        start_at, end_at,
        description: bd.querySelector('#ee-n').value.trim(),
      });
      const idx=_cal.events.findIndex(e=>e.id===id);
      if(idx>=0) _cal.events[idx]=Object.assign(_cal.events[idx],updated.event||updated);
      bd.remove();showToast('Event updated!','success');
      calLoad(document.getElementById('calendar-root'));
    } catch(e){
      showToast(e.message,'error');btn2.disabled=false;btn2.textContent=''+window.icon("save",14)+' Save';
    }
  };
};

// Backward compat: calEditEvent accepts event object (called from day modal)
window.calEditEvent = function(ev) {
  document.querySelector('.modal-backdrop')?.remove();
  calEditEventById(ev.id);
};

window.calDeleteEvent = async function(id, name) {
  const ok=await luConfirm('Delete event "'+(name||'this event')+'"?','Delete Event','Delete','Keep');
  if(!ok) return;
  showToast('Deleting…','info');
  try {
    await _calApi('DELETE', '/calendar/events/'+id);
    _cal.events=_cal.events.filter(e=>e.id!==id);
    document.querySelector('.modal-backdrop')?.remove();
    showToast('Event deleted.','success');
    calLoad(document.getElementById('calendar-root'));
  } catch(e) { showToast('Delete failed: '+e.message,'error'); }
};

// ═══════════════════════════════════════════════════════════════════
// BOOKING SLOTS
// ═══════════════════════════════════════════════════════════════════
window.calNewBookingSlot = function() {
  const bd=document.createElement('div');bd.className='modal-backdrop';bd.onclick=e=>{if(e.target===bd)bd.remove();};
  bd.innerHTML=`<div class="modal" style="max-width:440px">
    <div class="modal-header"><h3>New Booking Slot</h3><button class="modal-close" onclick="this.closest('.modal-backdrop').remove()">✕</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Service / Title *</label><input class="form-input" id="cbs-t" placeholder="e.g. 30-min Consultation"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Date</label><input type="date" class="form-input" id="cbs-d"></div>
        <div class="form-group"><label class="form-label">Start Time</label><input type="time" class="form-input" id="cbs-tm"></div>
      </div>
      <div class="form-group"><label class="form-label">Duration (minutes)</label><input type="number" class="form-input" id="cbs-dur" value="30" min="15" step="15"></div>
      <div class="form-group"><label class="form-label">Notes</label><textarea class="form-input" id="cbs-n" style="min-height:50px;resize:vertical" placeholder="Optional…"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="this.closest('.modal-backdrop').remove()">Cancel</button>
      <button class="btn btn-primary" id="cbs-save">${icons.plus} Create Slot</button>
    </div></div>`;
  document.body.appendChild(bd);bd.style.opacity='1';bd.style.pointerEvents='all';
  bd.querySelector('#cbs-save').onclick=async function(){
    const title=bd.querySelector('#cbs-t').value.trim();
    if(!title){showToast('Enter a title.','error');return;}
    const btn2=bd.querySelector('#cbs-save');btn2.disabled=true;btn2.textContent='Creating…';
    try{
      const d=bd.querySelector('#cbs-d').value;
      const tm=bd.querySelector('#cbs-tm').value;
      await _calApi('POST','/calendar/booking-slots',{
        title, duration_minutes: parseInt(bd.querySelector('#cbs-dur').value,10)||30,
        start_at: d&&tm?d+' '+tm+':00':null,
        description: bd.querySelector('#cbs-n').value.trim(),
        workspace_id: 1,
      });
      bd.remove();showToast('Booking slot created!','success');
      calLoad(document.getElementById('calendar-root'));
    }catch(e){showToast(e.message,'error');btn2.disabled=false;btn2.textContent='Create Slot';}
  };
};

// ═══════════════════════════════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════════════════════════════
function _escCal(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

console.log('[LevelUp] calendar engine v2.1.0 loaded');
