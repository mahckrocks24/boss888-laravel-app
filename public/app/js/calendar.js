// LevelUp CALENDAR Engine — v3.0.1 (2026-08-29, LEAD-1 / EV-0870)
// Rebuilt against the REAL API contract. v2.1.0 read start_at / end_at / event_type while the API
// returns starts_at / ends_at / category — so every event was undated and invisible; it fetched
// only the CURRENT month (no from/to) so navigating months showed nothing; and its "Bookings" tab
// read a stub endpoint (/calendar/booking-slots → always []) — a feature that did not exist.
// Bookings here are the real thing: booking/callback requests captured by the chatbot and the
// website forms (calendar_events.category booking_pending → booking_confirmed / booking_declined),
// linked to the CRM lead, with Confirm / Decline / Reschedule.

window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['calendar'] = true;

var _cal = { events: [], loading: false };
var _CAL_CATS = { meeting:'Meeting', call:'Call', deadline:'Deadline', reminder:'Reminder', event:'Event', general:'Event',
  booking_pending:'Booking request', booking_confirmed:'Booking confirmed', booking_declined:'Booking declined',
  callback_pending:'Callback request', callback_confirmed:'Callback confirmed', callback_declined:'Callback declined', strategy_meeting:'Strategy meeting' };

function _calEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function _calStart(ev) { return ev.starts_at || ev.start_at || ev.start_date || ev.date || ev.start || ''; }
function _calEnd(ev) { return ev.ends_at || ev.end_at || ''; }
function _calDate(s) { if (!s) return null; var d = (window._luParseTs ? window._luParseTs(s) : new Date(String(s).replace(' ', 'T'))); return isNaN(d.getTime()) ? null : d; }
function _calPad(n) { return String(n).padStart(2, '0'); }
function _calYmd(d) { return d.getFullYear() + '-' + _calPad(d.getMonth() + 1) + '-' + _calPad(d.getDate()); }
function _calCat(ev) { return ev.category || ev.event_type || ev.type || 'event'; }
function _calCatLabel(ev) { var c = _calCat(ev); return _CAL_CATS[c] || (c.charAt(0).toUpperCase() + c.slice(1).replace(/_/g, ' ')); }
function _calIsBooking(ev) { return /^(booking|callback)_/.test(_calCat(ev)); }
function _calTime(ev) { var d = _calDate(_calStart(ev)); if (!d) return ''; if (ev.all_day) return 'All day'; return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }

function _calConfirm(title, message, okLabel, cancelLabel) {
  // P0-A (2026-08-30): one dialog layer (core.js luDialog) — no native fallback.
  return luConfirm(title, message, { okLabel: okLabel, cancelLabel: cancelLabel });
}

async function _calApi(method, path, body) {
  var opts = { method: method, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') }, cache: 'no-store' };
  if (body) opts.body = JSON.stringify(body);
  var r;
  try { r = await fetch(window.location.origin + '/api' + path, opts); }
  catch (e) { throw new Error('Network error: ' + e.message); }
  if (r.status === 401) throw Object.assign(new Error('Session expired — please refresh.'), { code: 401 });
  if (r.status === 402) throw Object.assign(new Error('Not enough credits for this action.'), { code: 402 });
  if (r.status === 429) throw Object.assign(new Error('Rate limited — please wait a moment.'), { code: 429 });
  var d = await r.json().catch(function(){ return {}; });
  if (!r.ok) throw new Error(d.message || d.error || ('Error ' + r.status));
  if (d && d.success === false) throw new Error(d.error || d.message || 'The server refused this action.');
  return d;
}

// ── LOAD — a 3-month window around the month being viewed, refetched on navigation ─────────────
async function calLoad(el) {
  if (!el) return;
  if (!window._calViewDate) window._calViewDate = new Date();
  var vd = window._calViewDate;
  var from = new Date(vd.getFullYear(), vd.getMonth() - 1, 1);
  var to = new Date(vd.getFullYear(), vd.getMonth() + 2, 0);
  el.innerHTML = loadingCard(300);
  try {
    var res = await _calApi('GET', '/calendar/events?from=' + _calYmd(from) + '&to=' + _calYmd(to) + ' 23:59:59');
    _cal.events = Array.isArray(res) ? res : (res && res.events) || [];
    _calRender(el, _cal.events);
  } catch (e) {
    console.error('[Calendar]', e);
    el.innerHTML = '<div style="padding:60px;text-align:center;color:var(--t2)"><div style="font-size:14px;font-weight:600;margin-bottom:6px">Calendar failed to load</div><div style="font-size:12px;color:var(--t3)">' + _calEsc(friendlyError(e)) + '</div><button class="btn btn-outline btn-sm" style="margin-top:16px" onclick="calLoad(document.getElementById(\'calendar-root\'))">↺ Retry</button></div>';
  }
}

function _calBadge(ev) {
  var c = _calCat(ev);
  var cls = /confirmed/.test(c) ? 'badge-green' : /declined|cancelled/.test(c) ? 'badge-red' : /pending/.test(c) ? 'badge-amber' : 'badge-blue';
  return '<span class="badge ' + cls + '">' + _calEsc(_calCatLabel(ev)) + '</span>';
}

function _calRender(el, evts) {
  var viewDate = window._calViewDate || new Date();
  var year = viewDate.getFullYear(), month = viewDate.getMonth();
  var now = new Date(), today = (now.getFullYear() === year && now.getMonth() === month) ? now.getDate() : -1;
  var monthName = viewDate.toLocaleString('default', { month: 'long' });
  var firstDay = new Date(year, month, 1).getDay(), daysInMonth = new Date(year, month + 1, 0).getDate();
  var monthEvents = evts.filter(function(ev){ var d = _calDate(_calStart(ev)); return d && d.getFullYear() === year && d.getMonth() === month; });
  var eventsByDate = {};
  monthEvents.forEach(function(ev){ var d = _calDate(_calStart(ev)); (eventsByDate[d.getDate()] = eventsByDate[d.getDate()] || []).push(ev); });
  var bookings = evts.filter(_calIsBooking).sort(function(a, b){ return (_calDate(_calStart(a)) || 0) - (_calDate(_calStart(b)) || 0); });
  var pendingCount = bookings.filter(function(b){ return /pending/.test(_calCat(b)); }).length;

  var cells = '', dayNum = 1;
  for (var row = 0; row < 6; row++) {
    for (var col = 0; col < 7; col++) {
      var idx = row * 7 + col;
      if (idx < firstDay || dayNum > daysInMonth) { cells += '<div class="cal-cell empty"></div>'; }
      else {
        var evs = eventsByDate[dayNum] || [], isTod = dayNum === today;
        cells += '<div class="cal-cell' + (isTod ? ' today' : '') + '" onclick="calDayClick(' + dayNum + ')"><span class="cal-day-num' + (isTod ? ' active' : '') + '">' + dayNum + '</span>' +
          evs.slice(0, 2).map(function(ev){ return '<div class="cal-event-dot" title="' + _calEsc(ev.title || '') + '" style="' + (_calIsBooking(ev) ? 'background:rgba(168,85,247,.18);color:#c084fc' : '') + '">' + _calEsc((ev.title || 'Event').slice(0, 14)) + '</div>'; }).join('') +
          (evs.length > 2 ? '<div style="font-size:9px;color:var(--t3)">+' + (evs.length - 2) + '</div>' : '') + '</div>';
        dayNum++;
      }
    }
    if (dayNum > daysInMonth) break;
  }

  var sorted = evts.slice().sort(function(a, b){ return (_calDate(_calStart(a)) || 0) - (_calDate(_calStart(b)) || 0); });

  el.innerHTML =
  '<style>.cal-grid-header{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:4px}.cal-dow{text-align:center;font-size:11px;font-weight:600;color:var(--text-3);padding:6px 0;text-transform:uppercase}.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}.cal-cell{min-height:72px;padding:6px;border:1px solid var(--border);border-radius:4px;cursor:pointer;transition:border-color .15s}.cal-cell:hover{border-color:var(--blue)}.cal-cell.empty{background:var(--surface-0);border-color:transparent;cursor:default}.cal-cell.today{border-color:var(--blue);background:rgba(59,111,245,.06)}.cal-day-num{font-size:12px;font-weight:600;color:var(--text-2);display:block;margin-bottom:3px}.cal-day-num.active{background:var(--blue);color:#fff;width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px}.cal-event-dot{font-size:10px;background:var(--blue-soft);color:var(--blue);border-radius:3px;padding:1px 4px;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}</style>' +
  '<div class="page-header" style="margin-top:10px"><div class="page-header-left"><h1>Calendar</h1><p>' + monthEvents.length + ' event' + (monthEvents.length !== 1 ? 's' : '') + ' in ' + _calEsc(monthName) + ' · ' + pendingCount + ' booking request' + (pendingCount !== 1 ? 's' : '') + ' awaiting your reply</p></div>' +
    '<div class="page-header-actions"><button class="btn btn-outline btn-sm" onclick="calLoad(document.getElementById(\'calendar-root\'))">↺ Refresh</button><button class="btn btn-primary" onclick="calNewEvent()">' + icons.plus + ' New Event</button></div></div>' +
  '<div style="display:flex;gap:8px;margin-bottom:20px">' +
    '<button class="tab active" data-cal-tab="month" onclick="calSetTab(this,\'month\')">' + window.icon('calendar', 14) + ' Month</button>' +
    '<button class="tab" data-cal-tab="list" onclick="calSetTab(this,\'list\')">' + window.icon('more', 14) + ' List</button>' +
    '<button class="tab" data-cal-tab="bookings" onclick="calSetTab(this,\'bookings\')">' + window.icon('more', 14) + ' Bookings' + (pendingCount ? ' <span class="badge badge-amber" style="margin-left:4px">' + pendingCount + '</span>' : '') + '</button>' +
  '</div>' +
  '<div id="cal-month-panel"><div class="card"><div class="card-header"><h3>' + _calEsc(monthName) + ' ' + year + '</h3><div style="display:flex;gap:6px"><button class="btn btn-outline btn-sm" onclick="calNavigate(-1)">‹</button><button class="btn btn-outline btn-sm" onclick="calNavigate(0)">Today</button><button class="btn btn-outline btn-sm" onclick="calNavigate(1)">›</button></div></div>' +
    '<div class="card-body" style="padding:0 16px 16px"><div class="cal-grid-header">' + ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(function(d){ return '<div class="cal-dow">' + d + '</div>'; }).join('') + '</div><div class="cal-grid">' + cells + '</div></div></div></div>' +
  '<div id="cal-list-panel" style="display:none">' +
    (sorted.length === 0 ? '<div class="card card-body" style="text-align:center;padding:60px 20px"><h3 style="margin:0 0 8px">Nothing scheduled around ' + _calEsc(monthName) + '</h3><p style="color:var(--text-3);margin:0 0 20px;font-size:13px">Create events and appointments to manage your schedule.</p><button class="btn btn-primary" onclick="calNewEvent()" style="margin:0 auto">' + icons.plus + ' Create Event</button></div>'
      : '<div class="card"><div class="card-header"><h3>Events (' + _calEsc(new Date(year, month - 1, 1).toLocaleString('default', { month: 'short' })) + ' – ' + _calEsc(new Date(year, month + 1, 1).toLocaleString('default', { month: 'short' })) + ')</h3></div><div class="table-wrap"><table><thead><tr><th>Event</th><th>Date</th><th>Time</th><th>Type</th><th></th></tr></thead><tbody>' +
        sorted.map(function(ev){ var d = _calDate(_calStart(ev)); return '<tr><td><strong>' + _calEsc(ev.title || 'Untitled') + '</strong>' + (ev.description ? '<div style="font-size:11px;color:var(--t3);white-space:pre-line">' + _calEsc(String(ev.description).slice(0, 120)) + '</div>' : '') + '</td><td style="color:var(--t3)">' + (d ? d.toLocaleDateString() : '—') + '</td><td style="color:var(--t3)">' + _calEsc(_calTime(ev) || '—') + '</td><td>' + _calBadge(ev) + '</td><td><div style="display:flex;gap:4px">' + (ev.source === 'meeting' ? '' : '<button class="btn btn-outline btn-sm" onclick="calEditEventById(' + ev.id + ')" style="font-size:11px">' + window.icon('edit', 14) + '</button><button class="btn btn-outline btn-sm" style="color:var(--rd);font-size:11px" onclick="calDeleteEvent(' + ev.id + ')">' + window.icon('delete', 14) + '</button>') + '</div></td></tr>'; }).join('') +
        '</tbody></table></div></div>') +
  '</div>' +
  '<div id="cal-bookings-panel" style="display:none">' +
    (bookings.length === 0 ? '<div class="card card-body" style="text-align:center;padding:60px 20px"><h3 style="margin:0 0 8px">No booking requests yet</h3><p style="color:var(--text-3);margin:0;font-size:13px">When a visitor books through your website\'s chatbot or booking form, the request lands here with the lead attached — confirm it, decline it, or move it.</p></div>'
      : '<div class="card"><div class="card-header"><h3>Booking &amp; callback requests</h3></div><div class="table-wrap"><table><thead><tr><th>Request</th><th>When</th><th>Status</th><th></th></tr></thead><tbody>' +
        bookings.map(function(b){ var d = _calDate(_calStart(b)); var cat = _calCat(b); var pending = /pending/.test(cat); return '<tr><td><strong>' + _calEsc(b.title || 'Request') + '</strong>' + (b.description ? '<div style="font-size:11px;color:var(--t3);white-space:pre-line">' + _calEsc(String(b.description).replace(/\n?Status:.*$/m, '').slice(0, 160)) + '</div>' : '') + (b.reference_type === 'Lead' && b.reference_id ? '<div style="font-size:11px"><a href="#" onclick="nav(\'crm\');return false" style="color:var(--da)">Open lead #' + b.reference_id + ' in CRM →</a></div>' : '') + '</td><td style="font-size:12px">' + (d ? d.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—') + '</td><td>' + _calBadge(b) + '</td><td><div style="display:flex;gap:4px;flex-wrap:wrap">' +
          (pending ? '<button class="btn btn-primary btn-sm" style="font-size:11px" onclick="calBookingDecide(' + b.id + ',\'confirm\')">✓ Confirm</button><button class="btn btn-outline btn-sm" style="font-size:11px;color:var(--rd)" onclick="calBookingDecide(' + b.id + ',\'decline\')">Decline</button>' : '') +
          '<button class="btn btn-outline btn-sm" style="font-size:11px" onclick="calEditEventById(' + b.id + ')">Reschedule</button></div></td></tr>'; }).join('') +
        '</tbody></table></div></div>') +
  '</div>';

  window.calSetTab = function(btn, tab) {
    document.querySelectorAll('[data-cal-tab]').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    document.getElementById('cal-month-panel').style.display = tab === 'month' ? '' : 'none';
    document.getElementById('cal-list-panel').style.display = tab === 'list' ? '' : 'none';
    document.getElementById('cal-bookings-panel').style.display = tab === 'bookings' ? '' : 'none';
  };
  window._calOpenTab = function(tab) { var b = document.querySelector('[data-cal-tab="' + tab + '"]'); if (b) window.calSetTab(b, tab); };

  window.calDayClick = function(day) {
    var evs = eventsByDate[day] || [];
    if (evs.length === 0) { calNewEvent(day); return; }
    var bd = document.createElement('div'); bd.className = 'modal-backdrop'; bd.onclick = function(e){ if (e.target === bd) bd.remove(); };
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    bd.innerHTML = '<div class="modal" style="max-width:460px"><div class="modal-header"><h3>' + months[month] + ' ' + day + ', ' + year + '</h3><button class="modal-close" onclick="this.closest(\'.modal-backdrop\').remove()">✕</button></div><div class="modal-body" style="padding:16px">' +
      evs.map(function(ev){ return '<div style="display:flex;align-items:center;gap:10px;padding:10px;margin-bottom:8px;border:1px solid var(--border);border-radius:var(--radius)"><div style="flex:1"><div style="font-weight:600;font-size:13px">' + _calEsc(ev.title || 'Event') + '</div><div style="font-size:11px;color:var(--t3)">' + _calEsc(_calTime(ev) || 'All day') + ' · ' + _calEsc(_calCatLabel(ev)) + '</div>' + (ev.description ? '<div style="font-size:11px;color:var(--t2);margin-top:4px;white-space:pre-line">' + _calEsc(String(ev.description).slice(0, 200)) + '</div>' : '') + '</div>' +
        (/pending/.test(_calCat(ev)) ? '<button class="btn btn-primary btn-sm" onclick="this.closest(\'.modal-backdrop\').remove();calBookingDecide(' + ev.id + ',\'confirm\')">✓ Confirm</button>' : '') +
        '<button class="btn btn-outline btn-sm" onclick="this.closest(\'.modal-backdrop\').remove();calEditEventById(' + ev.id + ')">' + window.icon('edit', 14) + '</button></div>'; }).join('') +
      '<button class="btn btn-outline btn-sm" onclick="this.closest(\'.modal-backdrop\').remove();calNewEvent(' + day + ')" style="width:100%;margin-top:8px">' + icons.plus + ' Add Event</button></div></div>';
    document.body.appendChild(bd); bd.style.opacity = '1'; bd.style.pointerEvents = 'all';
  };
}

function calNavigate(dir) {
  if (!window._calViewDate) window._calViewDate = new Date();
  window._calViewDate = dir === 0 ? new Date() : new Date(window._calViewDate.getFullYear(), window._calViewDate.getMonth() + dir, 1);
  var el = document.getElementById('calendar-root');
  if (el) calLoad(el);
}

// ── BOOKING DECISIONS — the owner's reply to a request; the lead and the event move together ──
window.calBookingDecide = async function(id, decision) {
  var ev = _cal.events.find(function(e){ return e.id === id; }) || {};
  var verb = decision === 'confirm' ? 'Confirm' : 'Decline';
  var ok = await _calConfirm(verb + ' this booking?', '"' + (ev.title || 'This request') + '" — ' + (decision === 'confirm' ? 'it becomes a confirmed booking and the lead is marked qualified. Nothing is emailed to the visitor automatically — reply to them from the CRM.' : 'it is marked declined; the lead stays in your CRM so you can reply.'), verb, 'Cancel');
  if (!ok) return;
  try {
    var r = await _calApi('POST', '/calendar/events/' + id + '/decision', { decision: decision });
    showToast(decision === 'confirm' ? 'Booking confirmed.' : 'Booking declined.', 'success');
    calLoad(document.getElementById('calendar-root'));
    setTimeout(function(){ if (window._calOpenTab) window._calOpenTab('bookings'); }, 400);
  } catch (e) { showToast(verb + ' failed: ' + e.message, 'error'); }
};

// ── EVENT CRUD ───────────────────────────────────────────────────────────────────────────────
function _calCategoryOptions(selected) {
  return ['meeting','call','deadline','reminder','event'].map(function(t){ return '<option value="' + t + '"' + (selected === t ? ' selected' : '') + '>' + _CAL_CATS[t] + '</option>'; }).join('');
}

window.calNewEvent = function(preDay) {
  var vd = window._calViewDate || new Date(), year = vd.getFullYear(), month = vd.getMonth();
  var defDate = preDay ? year + '-' + _calPad(month + 1) + '-' + _calPad(preDay) : '';
  var bd = document.createElement('div'); bd.className = 'modal-backdrop'; bd.onclick = function(e){ if (e.target === bd) bd.remove(); };
  bd.innerHTML = '<div class="modal" style="max-width:440px"><div class="modal-header"><h3>New Event</h3><button class="modal-close" onclick="this.closest(\'.modal-backdrop\').remove()">✕</button></div><div class="modal-body">' +
    '<div class="form-group"><label class="form-label">Title *</label><input class="form-input" id="ce-t" placeholder="e.g. Client Meeting"></div>' +
    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div class="form-group"><label class="form-label">Date *</label><input type="date" class="form-input" id="ce-d" value="' + defDate + '"></div><div class="form-group"><label class="form-label">Time</label><input type="time" class="form-input" id="ce-tm"></div></div>' +
    '<div class="form-group"><label class="form-label">End Time (optional)</label><input type="time" class="form-input" id="ce-et"></div>' +
    '<div class="form-group"><label class="form-label">Type</label><select class="form-select" id="ce-ty">' + _calCategoryOptions('meeting') + '</select></div>' +
    '<div class="form-group"><label class="form-label">Notes</label><textarea class="form-input" id="ce-n" style="min-height:60px;resize:vertical" placeholder="Optional…"></textarea></div></div>' +
    '<div class="modal-footer"><button class="btn btn-outline" onclick="this.closest(\'.modal-backdrop\').remove()">Cancel</button><button class="btn btn-primary" id="ce-save">' + icons.plus + ' Create Event</button></div></div>';
  document.body.appendChild(bd); bd.style.opacity = '1'; bd.style.pointerEvents = 'all';
  bd.querySelector('#ce-save').onclick = async function(){
    var title = bd.querySelector('#ce-t').value.trim(), d = bd.querySelector('#ce-d').value;
    if (!title) { showToast('Enter a title.', 'error'); return; }
    if (!d) { showToast('Pick a date.', 'error'); return; }
    var btn = bd.querySelector('#ce-save'); btn.disabled = true; btn.textContent = 'Creating…';
    try {
      var tm = bd.querySelector('#ce-tm').value, et = bd.querySelector('#ce-et').value;
      var starts_at = d + ' ' + (tm || '09:00') + ':00', ends_at = et ? d + ' ' + et + ':00' : starts_at;
      await _calApi('POST', '/calendar/events', { title: title, category: bd.querySelector('#ce-ty').value, starts_at: starts_at, ends_at: ends_at, description: bd.querySelector('#ce-n').value.trim() });
      bd.remove(); showToast('Event created.', 'success');
      window._calViewDate = new Date(d + 'T00:00:00');
      calLoad(document.getElementById('calendar-root'));
    } catch (e) { showToast(e.message, 'error'); btn.disabled = false; btn.textContent = 'Create Event'; }
  };
};

window.calEditEventById = async function(id) {
  var ev = _cal.events.find(function(e){ return e.id === id; });
  if (!ev) { try { var r = await _calApi('GET', '/calendar/events/' + id); ev = r.event || r; } catch (e) { showToast('Load failed: ' + e.message, 'error'); return; } }
  if (!ev || !ev.id) { showToast('Event not found.', 'error'); return; }
  var s = _calDate(_calStart(ev)), en = _calDate(_calEnd(ev));
  var startDate = s ? _calYmd(s) : '', startTime = s ? _calPad(s.getHours()) + ':' + _calPad(s.getMinutes()) : '', endTime = en ? _calPad(en.getHours()) + ':' + _calPad(en.getMinutes()) : '';
  var isBooking = _calIsBooking(ev);
  var bd = document.createElement('div'); bd.className = 'modal-backdrop'; bd.onclick = function(e){ if (e.target === bd) bd.remove(); };
  bd.innerHTML = '<div class="modal" style="max-width:440px"><div class="modal-header"><h3>' + (isBooking ? 'Reschedule request' : 'Edit Event') + '</h3><button class="modal-close" onclick="this.closest(\'.modal-backdrop\').remove()">✕</button></div><div class="modal-body">' +
    '<div class="form-group"><label class="form-label">Title *</label><input class="form-input" id="ee-t" value="' + _calEsc(ev.title || '') + '"></div>' +
    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div class="form-group"><label class="form-label">Date *</label><input type="date" class="form-input" id="ee-d" value="' + startDate + '"></div><div class="form-group"><label class="form-label">Start Time</label><input type="time" class="form-input" id="ee-tm" value="' + startTime + '"></div></div>' +
    '<div class="form-group"><label class="form-label">End Time</label><input type="time" class="form-input" id="ee-et" value="' + endTime + '"></div>' +
    (isBooking ? '<div class="form-group"><label class="form-label">Status</label><div>' + _calBadge(ev) + '</div></div>' : '<div class="form-group"><label class="form-label">Type</label><select class="form-select" id="ee-ty">' + _calCategoryOptions(_calCat(ev)) + '</select></div>') +
    '<div class="form-group"><label class="form-label">Notes</label><textarea class="form-input" id="ee-n" style="min-height:60px;resize:vertical">' + _calEsc(ev.description || '') + '</textarea></div></div>' +
    '<div class="modal-footer"><button class="btn btn-outline" onclick="this.closest(\'.modal-backdrop\').remove()">Cancel</button><button class="btn btn-primary" id="ee-save">' + window.icon('save', 14) + ' Save</button></div></div>';
  document.body.appendChild(bd); bd.style.opacity = '1'; bd.style.pointerEvents = 'all';
  bd.querySelector('#ee-save').onclick = async function(){
    var title = bd.querySelector('#ee-t').value.trim(), d = bd.querySelector('#ee-d').value;
    if (!title) { showToast('Enter a title.', 'error'); return; }
    if (!d) { showToast('Pick a date.', 'error'); return; }
    var btn = bd.querySelector('#ee-save'); btn.disabled = true; btn.textContent = 'Saving…';
    try {
      var tm = bd.querySelector('#ee-tm').value, et = bd.querySelector('#ee-et').value;
      var starts_at = d + ' ' + (tm || '09:00') + ':00', ends_at = et ? d + ' ' + et + ':00' : starts_at;
      var payload = { title: title, starts_at: starts_at, ends_at: ends_at, description: bd.querySelector('#ee-n').value.trim() };
      if (!isBooking) payload.category = bd.querySelector('#ee-ty').value;
      await _calApi('PUT', '/calendar/events/' + id, payload);
      bd.remove(); showToast(isBooking ? 'Request rescheduled.' : 'Event updated.', 'success');
      calLoad(document.getElementById('calendar-root'));
    } catch (e) { showToast(e.message, 'error'); btn.disabled = false; btn.textContent = 'Save'; }
  };
};

window.calEditEvent = function(ev) { var m = document.querySelector('.modal-backdrop'); if (m) m.remove(); calEditEventById(ev.id); };

window.calDeleteEvent = async function(id, name) {
  var ev = _cal.events.find(function(e){ return e.id === id; }) || {};
  var ok = await _calConfirm('Delete this event?', '"' + (name || ev.title || 'This event') + '" will be removed from the calendar.', 'Delete', 'Keep');
  if (!ok) return;
  try {
    await _calApi('DELETE', '/calendar/events/' + id);
    _cal.events = _cal.events.filter(function(e){ return e.id !== id; });
    var m = document.querySelector('.modal-backdrop'); if (m) m.remove();
    showToast('Event deleted.', 'success');
    calLoad(document.getElementById('calendar-root'));
  } catch (e) { showToast('Delete failed: ' + e.message, 'error'); }
};

console.log('[LevelUp] calendar engine v3.0.1 loaded');
