/* AMG travel theme behaviour — search tabs, mobile nav, reveal, header,
   and the multi-step trip-inquiry wizard (submits to CRM). No realtime
   airline booking — this is a guided quote request. */
(function () {
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };

  /* search tabs (visual) */
  $$('.searchbar__tab').forEach(function (t) {
    t.addEventListener('click', function () {
      $$('.searchbar__tab').forEach(function (x) { x.classList.remove('is-active'); });
      t.classList.add('is-active');
    });
  });

  /* mobile nav */
  var burg = $('#burger'), mob = $('#mobileNav'), mobC = $('#mobileClose');
  if (burg && mob) burg.addEventListener('click', function () { mob.classList.add('is-open'); });
  if (mobC && mob) mobC.addEventListener('click', function () { mob.classList.remove('is-open'); });
  if (mob) $$('a', mob).forEach(function (a) { a.addEventListener('click', function () { mob.classList.remove('is-open'); }); });

  /* reveal on scroll */
  var io = new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
  }, { threshold: 0.1, rootMargin: '0px 0px -40px' });
  $$('.reveal').forEach(function (n) { io.observe(n); });

  /* header scroll state */
  var head = $('#head');
  function onScroll() { if (head) head.classList.toggle('is-scrolled', window.scrollY > 40); }
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  /* ---------------- TRIP INQUIRY WIZARD ---------------- */
  var overlay = $('#bkOverlay');
  if (!overlay) return;
  var bk = $('.bk', overlay);
  var sub = bk ? (bk.getAttribute('data-subdomain') || '') : '';
  var PANES = $$('.bk__pane', overlay);
  var STEPS = $$('.bk__step', overlay);

  var state = {
    services: [], transport: 'Airline', from: '', to: '', tripType: 'Round-trip',
    depart: '', ret: '', adults: 1, children: 0, hotel: 'No', star: '',
    budget: '', occasion: '', notes: '', name: '', email: '', phone: '', contact: 'WhatsApp'
  };

  function selSvc(name) {
    if (state.services.indexOf(name) < 0) state.services.push(name);
    var el = $('.wz-opt[data-svc="' + name + '"]', overlay);
    if (el) el.classList.add('is-on');
  }
  function setStep(n) {
    STEPS.forEach(function (el, i) { el.classList.toggle('done', i < n); el.classList.toggle('active', i === n); });
    PANES.forEach(function (el, i) { el.classList.toggle('is-active', i === n); });
    if (bk) bk.scrollTop = 0;
  }
  function toggleReturn() { var r = $('#wzReturnRow'); if (r) r.style.display = state.tripType === 'One-way' ? 'none' : ''; }
  function toggleStar() { var s = $('#wzStarRow'); if (s) s.style.display = state.hotel === 'Yes' ? '' : 'none'; }
  function setCounter(k) { var el = $('#c' + k.charAt(0).toUpperCase() + k.slice(1)); if (el) el.textContent = state[k]; }
  function showEl(sel, on) { var e = $(sel); if (e) e.style.display = on ? '' : 'none'; }
  function setLabel(sel, txt) { var e = $(sel); if (e) e.textContent = txt; }
  function setSeg(field, val) {
    var seg = $('.wz-seg[data-field="' + field + '"]', overlay); if (!seg) return;
    $$('button', seg).forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-val') === val); });
    state[field] = val;
  }
  /* Tailor the questions to what was picked in step 1. */
  function applyConditional() {
    var has = function (n) { return state.services.indexOf(n) > -1; };
    var travel = has('Flights') || has('Ship / Ferry') || has('Tour package') || has('Complete package');
    var transport = has('Flights') || has('Ship / Ferry');
    var hotelWanted = has('Hotel') || has('Tour package') || has('Complete package');
    var hotelExplicit = has('Hotel');
    var hotelOnly = hotelExplicit && !travel;
    var visaOnly = has('Visa') && state.services.length === 1;

    showEl('#wzTransportRow', transport);
    if (transport) setSeg('transport', (has('Ship / Ferry') && !has('Flights')) ? 'Ship' : 'Airline');

    showEl('#wzFromField', travel);
    var rr = $('#wzRouteRow'); if (rr) rr.style.gridTemplateColumns = travel ? '' : '1fr';
    setLabel('#wzToLabel', visaOnly ? "Which country's visa?" : 'Destination');

    showEl('#wzTripTypeRow', transport || has('Tour package') || has('Complete package'));

    if (hotelOnly) {
      setLabel('#wzDepartLabel', 'Check-in'); setLabel('#wzReturnLabel', 'Check-out'); showEl('#wzReturnRow', true);
    } else {
      setLabel('#wzDepartLabel', travel ? 'Flying / sailing out' : 'Preferred travel date');
      setLabel('#wzReturnLabel', 'Coming back'); toggleReturn();
    }

    if (hotelExplicit) { showEl('#wzHotelRow', false); setSeg('hotel', 'Yes'); toggleStar(); }
    else if (hotelWanted) { showEl('#wzHotelRow', true); }
    else { showEl('#wzHotelRow', false); setSeg('hotel', 'No'); toggleStar(); }
  }

  function open(preset) {
    state.services = [];
    $$('.wz-opt', overlay).forEach(function (o) { o.classList.remove('is-on'); });
    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    if (preset) {
      if (preset.indexOf('→') > -1) { var p = preset.split('→'); $('#wzFrom').value = p[0].trim(); $('#wzTo').value = p[1].trim(); selSvc('Flights'); }
      else { selSvc('Tour package'); $('#wzTo').value = preset.trim(); }
      $('#toStep2').disabled = state.services.length === 0;
      $('#toStep2').style.opacity = state.services.length ? 1 : 0.5;
    }
    setStep(0);
  }
  function close() { overlay.classList.remove('is-open'); document.body.style.overflow = ''; }

  function read() {
    state.from = $('#wzFrom').value; state.to = $('#wzTo').value;
    state.depart = $('#wzDepart').value; state.ret = $('#wzReturn').value;
    state.notes = $('#wzNotes').value; if (state.budget === 'Not sure yet') state.budget = '';
    state.name = $('#wzName').value; state.email = $('#wzEmail').value; state.phone = $('#wzPhone').value;
  }
  function buildMessage() {
    var has = function (n) { return state.services.indexOf(n) > -1; };
    var travel = has('Flights') || has('Ship / Ferry') || has('Tour package') || has('Complete package');
    var L = ['New trip inquiry from the ' + ((window.__TF && window.__TF.site) || 'website') + ' website.'];
    L.push('Looking for: ' + (state.services.join(', ') || '—'));
    if (has('Flights') || has('Ship / Ferry')) L.push('Transport: ' + state.transport);
    if (travel) L.push('Route: ' + (state.from || '—') + ' -> ' + (state.to || '—'));
    else L.push('Destination: ' + (state.to || '—'));
    if (travel) L.push('Trip: ' + state.tripType + ' | Out: ' + (state.depart || '—') + (state.tripType === 'One-way' ? '' : ' | Back: ' + (state.ret || '—')));
    else if (state.depart) L.push('Dates: ' + state.depart + (state.ret ? ' to ' + state.ret : ''));
    L.push('Travelers: ' + state.adults + ' adult(s), ' + state.children + ' child(ren)');
    if (state.hotel === 'Yes') L.push('Hotel: Yes' + (state.star ? ' (' + state.star + ')' : ''));
    if (state.budget) L.push('Budget/person: ' + state.budget);
    if (state.occasion) L.push('Occasion: ' + state.occasion);
    if (state.notes) L.push('Notes: ' + state.notes);
    L.push('Preferred contact: ' + state.contact);
    return L.join('\n');
  }
  function submit() {
    read();
    var err = $('#wzErr'), btn = $('#wzSubmit');
    if (!state.name || !state.email || !state.phone) {
      err.textContent = 'Please add your name, email and mobile so we can reach you.';
      err.style.display = 'block'; return;
    }
    err.style.display = 'none';
    var orig = btn.textContent; btn.disabled = true; btn.textContent = 'Sending…';
    fetch('/api/public/contact/' + encodeURIComponent(sub), {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ firstname: state.name, name: state.name, email: state.email, phone: state.phone, message: buildMessage(), source: 'booking_inquiry' })
    }).then(function (r) { return r.json(); }).then(function (d) {
      btn.disabled = false; btn.textContent = orig;
      if (d && d.success) {
        $('#bkRef').textContent = ((window.__TF && window.__TF.ref) || 'BK') + '-' + Math.random().toString(36).slice(2, 8).toUpperCase();
        setStep(4);
      } else {
        err.textContent = 'Something went wrong. Please try again, or message us on WhatsApp.';
        err.style.display = 'block';
      }
    }).catch(function () {
      btn.disabled = false; btn.textContent = orig;
      err.textContent = 'Network error. Please try again.'; err.style.display = 'block';
    });
  }

  /* option cards (multi-select) */
  $$('.wz-opt', overlay).forEach(function (o) {
    o.addEventListener('click', function () {
      var n = o.getAttribute('data-svc'), i = state.services.indexOf(n);
      if (i < 0) { state.services.push(n); o.classList.add('is-on'); }
      else { state.services.splice(i, 1); o.classList.remove('is-on'); }
      var c = $('#toStep2');
      c.disabled = state.services.length === 0;
      c.style.opacity = state.services.length ? 1 : 0.5;
    });
  });
  /* segmented toggles */
  $$('.wz-seg', overlay).forEach(function (seg) {
    $$('button', seg).forEach(function (b) {
      b.addEventListener('click', function () {
        $$('button', seg).forEach(function (x) { x.classList.remove('is-on'); });
        b.classList.add('is-on');
        state[seg.getAttribute('data-field')] = b.getAttribute('data-val');
        toggleReturn(); toggleStar();
      });
    });
  });
  /* counters */
  $$('.wz-count button', overlay).forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.getAttribute('data-c'), d = parseInt(b.getAttribute('data-d'), 10);
      state[k] = Math.max(k === 'adults' ? 1 : 0, state[k] + d);
      setCounter(k);
    });
  });

  /* hero search → open wizard prefilled from the hero inputs */
  var heroSearch = $('#heroSearch');
  if (heroSearch) heroSearch.addEventListener('click', function () {
    open(null);
    var f = $('#hbFrom'), t = $('#hbTo'), d = $('#hbDate'), p = null; var pxOn = document.querySelector('.wz-seg[data-field="hbPax"] .is-on'); if (pxOn) { state.adults = parseInt(pxOn.getAttribute('data-val'), 10) || 1; setCounter('adults'); }
    if (f && $('#wzFrom')) $('#wzFrom').value = f.value;
    if (t && $('#wzTo')) $('#wzTo').value = t.value;
    if (d && d.value && $('#wzDepart')) $('#wzDepart').value = d.value;
    selSvc('Flights');
    if (p) { state.adults = parseInt(p.value, 10) || 1; setCounter('adults'); }
    var c = $('#toStep2'); if (c) { c.disabled = false; c.style.opacity = 1; }
  });

  /* navigation (delegated) */
  document.addEventListener('click', function (e) {
    var t = e.target;
    var book = t.closest('[data-book]');
    if (book) { e.preventDefault(); open(book.getAttribute('data-book') || null); return; }
    if (t.closest('#bkClose') || t === overlay) close();
    if (t.closest('#toStep2') && state.services.length) { applyConditional(); setStep(1); }
    if (t.closest('#toStep3')) { read(); setStep(2); }
    if (t.closest('#toStep4')) { read(); setStep(3); }
    if (t.closest('#bkBack1')) setStep(0);
    if (t.closest('#bkBack2')) setStep(1);
    if (t.closest('#bkBack3')) setStep(2);
    if (t.closest('#wzSubmit')) submit();
    if (t.closest('#bkDone')) close();
  });
})();

/* SGTRAVEL T1 — site-styled date picker for [data-datepick] inputs (no native calendar popup) */
(function () {
  var open = null, MN = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function fmt(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function close() { if (open) { open.el.remove(); open = null; } }
  function build(input) {
    close();
    var today = new Date(); today.setHours(0, 0, 0, 0);
    var cur = input.value && /^\d{4}-\d{2}-\d{2}$/.test(input.value) ? new Date(input.value + 'T00:00:00') : today;
    var view = new Date(cur.getFullYear(), cur.getMonth(), 1);
    var el = document.createElement('div'); el.className = 'dp'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Choose a date');
    function render() {
      var h = '<div class="dp__h"><button type="button" class="dp__nav" data-m="-1" aria-label="Previous month">&lsaquo;</button><b>' + MN[view.getMonth()] + ' ' + view.getFullYear() + '</b><button type="button" class="dp__nav" data-m="1" aria-label="Next month">&rsaquo;</button></div><div class="dp__g">';
      ['S','M','T','W','T','F','S'].forEach(function (d) { h += '<span class="dp__dn">' + d + '</span>'; });
      var first = new Date(view.getFullYear(), view.getMonth(), 1).getDay(); for (var i = 0; i < first; i++) h += '<span></span>';
      var dim = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
      for (var d = 1; d <= dim; d++) { var dt = new Date(view.getFullYear(), view.getMonth(), d); var past = dt < today; var sel = input.value === fmt(dt); h += '<button type="button" class="dp__d' + (sel ? ' is-sel' : '') + (dt.getTime() === today.getTime() ? ' is-today' : '') + '" data-d="' + fmt(dt) + '"' + (past ? ' disabled' : '') + '>' + d + '</button>'; }
      el.innerHTML = h + '</div>';
    }
    render();
    el.addEventListener('click', function (e) {
      var n = e.target.closest('.dp__nav'); if (n) { view = new Date(view.getFullYear(), view.getMonth() + parseInt(n.getAttribute('data-m'), 10), 1); render(); return; }
      var d = e.target.closest('.dp__d'); if (d) { input.value = d.getAttribute('data-d'); input.dispatchEvent(new Event('change', { bubbles: true })); close(); input.focus(); }
    });
    var wrap = input.parentNode; if (getComputedStyle(wrap).position === 'static') wrap.style.position = 'relative';
    wrap.appendChild(el); open = { el: el, input: input };
  }
  document.addEventListener('click', function (e) {
    var inp = e.target.closest('[data-datepick]');
    if (inp) { if (open && open.input === inp) return; build(inp); return; }
    if (open && !e.target.closest('.dp')) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); if (e.key === 'Backspace' || e.key === 'Delete') { var a = document.activeElement; if (a && a.hasAttribute && a.hasAttribute('data-datepick')) { a.value = ''; a.dispatchEvent(new Event('change', { bubbles: true })); } } });
  document.querySelectorAll('[data-datepick]').forEach(function (i) { i.addEventListener('focus', function () { build(i); }); });
})();