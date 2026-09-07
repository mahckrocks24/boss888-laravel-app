/* RESUME888 — Kabayan CV builder (vanilla JS, site-styled, phone-first). Talks to /api/public/resume on the site host. */
(function () {
  'use strict';
  var root = document.getElementById('kb-resume'); if (!root) return;
  var API = root.getAttribute('data-api') || '/api/public/resume', TOKEN = root.getAttribute('data-token') || '', WID = root.getAttribute('data-website') || '0';
  var KEY = 'rs_token_' + WID, DEV = 'rs_device';
  var S = { session: null, ui: null, lang: null, token: null, busy: false, view: 'chat', cfg: null };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function get(obj, path) { return path.split('.').reduce(function (o, k) { return o == null ? undefined : o[k]; }, obj); }
  function device() { try { var d = localStorage.getItem(DEV); if (!d) { d = 'd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); localStorage.setItem(DEV, d); } return d; } catch (e) { return ''; } }
  try { S.token = localStorage.getItem(KEY); } catch (e) {}
  var toastT; function toast(m) { var t = document.querySelector('.rs-toast'); if (!t) { t = el('div', 'rs-toast'); document.body.appendChild(t); } t.textContent = m; clearTimeout(toastT); toastT = setTimeout(function () { t.remove(); }, 3500); }

  function api(path, method, body, isForm) {
    var h = { 'Accept': 'application/json', 'X-CHATBOT-TOKEN': TOKEN }; if (S.token) h['X-RESUME-SESSION'] = S.token;
    var init = { method: method || 'GET', headers: h, credentials: 'omit' };
    if (isForm) init.body = body; else if (body != null) { h['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
    return fetch(API + path, init).then(function (r) { return r.json().catch(function () { return { success: false, error: 'BAD_JSON' }; }).then(function (j) { j.__status = r.status; return j; }); });
  }

  /* ---------- shell ---------- */
  root.innerHTML = '<div class="rs-chat"><div class="rs-top"><b id="rs-title">Kabayan CV Assistant</b><button type="button" class="rs-btn rs-btn--quiet rs-langbtn" id="rs-lang" aria-label="Language">🌐</button></div><div class="rs-prog"><i id="rs-prog"></i></div><div class="rs-log" id="rs-log" aria-live="polite"></div><div class="rs-input" id="rs-input"></div><div class="rs-mobile-bar" id="rs-mbar"></div></div>' +
    '<aside class="rs-side"><div class="rs-side-top"><span id="rs-side-title">Preview</span><button type="button" class="rs-btn rs-btn--quiet" id="rs-back">←</button></div><div class="rs-prev" id="rs-prev"></div></aside>';
  var log = document.getElementById('rs-log'), input = document.getElementById('rs-input'), prev = document.getElementById('rs-prev'), mbar = document.getElementById('rs-mbar');
  document.getElementById('rs-back').onclick = function () { setView('chat'); };
  document.getElementById('rs-lang').onclick = function () { pickLanguage(true); };
  function setView(v) { S.view = v; root.setAttribute('data-view', v); if (v === 'preview') renderPreview(); }
  function bot(html, small) { var m = el('div', 'rs-msg bot', html + (small ? '<small>' + esc(small) + '</small>' : '')); log.appendChild(m); log.scrollTop = log.scrollHeight; return m; }
  function me(text) { var m = el('div', 'rs-msg me'); m.textContent = text; log.appendChild(m); log.scrollTop = log.scrollHeight; }
  function typing() { var m = el('div', 'rs-msg bot', '<span class="rs-typing"><i></i><i></i><i></i></span>'); log.appendChild(m); log.scrollTop = log.scrollHeight; return m; }
  function ui(k) { return (S.ui && S.ui[k]) || k; }
  function progress(p) { document.getElementById('rs-prog').style.width = (p || 0) + '%'; }

  /* ---------- boot ---------- */
  function boot() {
    api('/config').then(function (c) {
      if (!c.success) { bot('The CV tool is not available right now.'); return; }
      S.cfg = c;
      if (S.token) { api('/session').then(function (j) { if (j.success) { S.session = j.session; S.lang = j.session.language; S.ui = j.session.ui; resume(); } else { S.token = null; try { localStorage.removeItem(KEY); } catch (e) {} pickLanguage(false); } }); }
      else pickLanguage(false);
    });
  }
  function pickLanguage(switching) {
    input.innerHTML = ''; var langs = S.cfg.languages;
    var q = switching ? (S.ui ? S.ui.lang_q : 'Language?') : 'Anong wika ang gusto mo? · Which language do you prefer?';
    bot(esc(q));
    var chips = el('div', 'rs-chips');
    Object.keys(langs).forEach(function (code) { var b = el('button', 'rs-chip', esc(langs[code])); b.type = 'button'; b.onclick = function () { me(langs[code]); S.lang = code; S.ui = S.cfg.ui[code]; if (switching && S.session) { /* language switch mid-session: re-render current step in the new language is server-side; we store locally */ resume(); } else consent(); }; chips.appendChild(b); });
    input.appendChild(chips);
  }
  function consent() {
    input.innerHTML = ''; bot(esc(ui('consent')));
    var row = el('div', 'rs-actions'); var b = el('button', 'rs-btn rs-btn--primary', esc(ui('agree'))); b.type = 'button';
    b.onclick = function () { b.disabled = true; me(ui('agree')); api('/session/start', 'POST', { language: S.lang, consent: true, device_id: device(), hp: '' }).then(function (j) { if (!j.success) { if (j.error === 'DAILY_LIMIT') { bot('<b>' + esc(ui('limit_title')) + '</b><br>' + esc(ui('limit_body'))); } else bot(esc(j.message || 'Sorry, something went wrong.')); return; } S.token = j.token; try { localStorage.setItem(KEY, j.token); } catch (e) {} S.session = j.session; S.ui = j.session.ui; resume(); }); };
    row.appendChild(b); input.appendChild(row);
  }
  function resume() { var s = S.session; progress(s.progress); if (s.state === 'done' || s.completed) { showDone(); return; } ask(s.step); }

  /* ---------- steps ---------- */
  function ask(step) {
    input.innerHTML = ''; var s = S.session; progress(s.progress);
    if (step.kind === 'done') { bot(esc(step.q)); finish(); return; }
    if (step.kind === 'confirm') { showConfirm(step); return; }
    if (step.q) bot(esc(step.q), step.help || null);
    var wrap = el('div');
    var actions = el('div', 'rs-actions');
    function submit(answer, label, skip) { if (S.busy) return; S.busy = true; if (label) me(label); var t = typing(); api('/answer', 'POST', { step: step.id, answer: answer, skip: !!skip, hp: '' }).then(function (j) { t.remove(); S.busy = false; if (!j.success) { if (j.error === 'STEP_MISMATCH' && j.session) { S.session = j.session; ask(j.session.step); return; } bot('<span class="rs-err">' + esc(j.message || ui('required')) + '</span>'); return; } S.session = j.session; if (j.session.state === 'done') { renderPreview(); showDone(j.mode); } else ask(j.session.step); }).catch(function () { t.remove(); S.busy = false; toast('Network error'); }); }
    if (step.kind === 'chips' || step.kind === 'multichips') {
      var chips = el('div', 'rs-chips'); var sel = [];
      (step.chips || []).forEach(function (c) { var b = el('button', 'rs-chip', esc(c.label)); b.type = 'button'; b.onclick = function () { if (step.kind === 'chips') submit(c.value, c.label); else { var i = sel.indexOf(c.value); if (i >= 0) { sel.splice(i, 1); b.classList.remove('is-on'); } else { sel.push(c.value); b.classList.add('is-on'); } } }; chips.appendChild(b); });
      wrap.appendChild(chips);
      if (step.kind === 'multichips') {
        var other = null;
        if (step.other) { other = el('input', 'rs-text'); other.placeholder = ui('other'); other.style.minHeight = '44px'; var of = el('div', 'rs-field'); of.appendChild(other); wrap.appendChild(of); }
        var ok = el('button', 'rs-btn rs-btn--primary', esc(ui('next'))); ok.type = 'button'; ok.onclick = function () { var vals = sel.slice(); if (other && other.value.trim()) vals = vals.concat(other.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean)); if (!vals.length && !step.skippable) { toast(ui('required')); return; } submit(vals, vals.join(', ') || ui('skip'), !vals.length); }; actions.appendChild(ok);
      }
    } else if (step.kind === 'form') {
      var form = el('div', 'rs-form'); var fields = {};
      (step.fields || []).forEach(function (f) {
        var fd = el('div', 'rs-field' + ((step.fields.length === 1 || (f.kind === 'chips')) ? ' full' : '')); fd.innerHTML = '<label>' + esc(f.label) + (f.required ? ' *' : '') + '</label>';
        if (f.kind === 'chips') { var cc = el('div', 'rs-chips'); var val = { v: '' }; (f.chips || []).forEach(function (c) { var b = el('button', 'rs-chip', esc(c.label)); b.type = 'button'; b.onclick = function () { val.v = c.value; cc.querySelectorAll('.rs-chip').forEach(function (x) { x.classList.remove('is-on'); }); b.classList.add('is-on'); }; cc.appendChild(b); }); fd.appendChild(cc); fields[f.name] = function () { return val.v; }; }
        else { var inp = el('input'); inp.placeholder = f.placeholder || ''; inp.type = f.kind === 'year' ? 'text' : 'text'; inp.inputMode = (f.kind === 'year' || f.kind === 'month') ? 'numeric' : 'text'; inp.autocomplete = 'off'; fd.appendChild(inp); fields[f.name] = function () { return inp.value.trim(); }; }
        form.appendChild(fd);
      });
      wrap.appendChild(form);
      var okf = el('button', 'rs-btn rs-btn--primary', esc(ui('next'))); okf.type = 'button'; okf.onclick = function () { var a = {}, label = []; Object.keys(fields).forEach(function (k) { a[k] = fields[k](); if (a[k]) label.push(a[k]); }); var missing = (step.fields || []).filter(function (f) { return f.required && !a[f.name]; }); if (missing.length) { toast(ui('required') + ': ' + missing[0].label); return; } submit(a, label.join(' · ')); }; actions.appendChild(okf);
    } else if (step.kind === 'file') {
      var fz = el('label', 'rs-file', '<div>' + esc(step.q ? '📎 ' + ui('send') : '') + '</div><div class="rs-help">' + esc(step.help || '') + '</div>'); var fi = el('input'); fi.type = 'file'; fi.accept = step.accept || '*/*'; fz.appendChild(fi); wrap.appendChild(fz);
      fi.onchange = function () { var f = fi.files[0]; if (!f) return; upload(f, step.id === 'photo' ? 'photo' : 'cv', step); };
      ['dragenter', 'dragover'].forEach(function (ev) { fz.addEventListener(ev, function (e) { e.preventDefault(); fz.classList.add('is-over'); }); }); ['dragleave', 'drop'].forEach(function (ev) { fz.addEventListener(ev, function (e) { e.preventDefault(); fz.classList.remove('is-over'); if (ev === 'drop' && e.dataTransfer.files[0]) upload(e.dataTransfer.files[0], step.id === 'photo' ? 'photo' : 'cv', step); }); });
    } else {
      var row = el('div', 'rs-row'); var ta = el('textarea', 'rs-text'); ta.rows = step.kind === 'textarea' ? 3 : 1; ta.placeholder = step.help || ''; if (step.input === 'tel') ta.inputMode = 'tel'; if (step.input === 'email') ta.inputMode = 'email';
      var send = el('button', 'rs-send', '➤'); send.type = 'button'; send.setAttribute('aria-label', ui('send'));
      function go() { var v = ta.value.trim(); if (!v) { if (step.skippable) submit('', ui('skip'), true); else toast(ui('required')); return; } submit(v, v); }
      send.onclick = go; ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && step.kind !== 'textarea') { e.preventDefault(); go(); } });
      ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(160, ta.scrollHeight) + 'px'; });
      row.appendChild(ta); row.appendChild(send); wrap.appendChild(row); setTimeout(function () { ta.focus(); }, 50);
    }
    if (step.skippable && step.kind !== 'text' && step.kind !== 'textarea') { var sk = el('button', 'rs-btn rs-btn--quiet', esc(ui('skip'))); sk.type = 'button'; sk.onclick = function () { submit(null, ui('skip'), true); }; actions.appendChild(sk); }
    if (step.skippable && (step.kind === 'text' || step.kind === 'textarea')) { var sk2 = el('button', 'rs-btn rs-btn--quiet', esc(ui('skip'))); sk2.type = 'button'; sk2.onclick = function () { submit('', ui('skip'), true); }; actions.appendChild(sk2); }
    if (actions.children.length) wrap.appendChild(actions);
    input.appendChild(wrap);
    mbar.innerHTML = ''; if (S.session && (S.session.draft.person.full_name || S.session.draft.experience.length)) { var pv = el('button', 'rs-btn', esc(ui('preview'))); pv.type = 'button'; pv.onclick = function () { setView('preview'); }; mbar.appendChild(pv); }
  }

  /* ---------- upload with browser-side downscale (photos) ---------- */
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!/^image\//.test(file.type) || file.size < 400000) return resolve(file);
      var img = new Image(); var url = URL.createObjectURL(file);
      img.onload = function () { var max = 1400, w = img.width, h = img.height, k = Math.min(1, max / Math.max(w, h)); var c = document.createElement('canvas'); c.width = Math.round(w * k); c.height = Math.round(h * k); var x = c.getContext('2d'); x.filter = 'grayscale(1) contrast(1.05)'; x.drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url); c.toBlob(function (b) { resolve(b ? new File([b], (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.72); };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); }; img.src = url;
    });
  }
  function upload(file, purpose, step) {
    if (S.busy) return; if (file.size > 10 * 1024 * 1024) { toast('Max 10 MB'); return; }
    S.busy = true; me('📎 ' + file.name); var t = typing(); bot(esc(purpose === 'photo' ? '…' : ui('upload_reading')));
    (purpose === 'photo' ? Promise.resolve(file) : shrink(file)).then(function (f) { var fd = new FormData(); fd.append('file', f, f.name); fd.append('purpose', purpose); return api('/upload', 'POST', fd, true); })
      .then(function (j) { t.remove(); S.busy = false; if (!j.success) { bot('<span class="rs-err">' + esc(j.message || ui('upload_failed')) + '</span>'); if (step) ask(step); return; } S.session = j.session; if (j.session.state === 'done') { renderPreview(); showDone(j.mode); } else ask(j.session.step); })
      .catch(function () { t.remove(); S.busy = false; toast('Upload failed'); if (step) ask(step); });
  }

  /* ---------- confirm screen (upload path) ---------- */
  function showConfirm(step) {
    bot(esc(step.q)); input.innerHTML = ''; var d = S.session.draft;
    var box = el('div', 'rs-form');
    var fields = [['person.full_name', 'Name'], ['person.phone', 'Phone'], ['person.email', 'Email'], ['person.city', 'City']];
    var refs = {};
    fields.forEach(function (f) { var fd = el('div', 'rs-field'); fd.innerHTML = '<label>' + esc(f[1]) + '</label>'; var i = el('input'); i.value = get(d, f[0]) || ''; fd.appendChild(i); refs[f[0]] = i; box.appendChild(fd); });
    var jobs = el('div', 'rs-field full'); jobs.innerHTML = '<label>' + esc(ui('sections').experience) + ' (' + (d.experience || []).length + ')</label><div class="rs-help">' + esc((d.experience || []).map(function (j) { return (j.title || '?') + ' — ' + (j.employer || '?') + ' (' + (j.start || '?') + ' → ' + (j.end || '?') + ')'; }).join(' · ') || '—') + '</div>'; box.appendChild(jobs);
    input.appendChild(box);
    var act = el('div', 'rs-actions'); var ok = el('button', 'rs-btn rs-btn--primary', esc(ui('next'))); ok.type = 'button';
    ok.onclick = function () { var patch = {}; Object.keys(refs).forEach(function (k) { patch[k] = refs[k].value.trim(); }); S.busy = true; var t = typing(); api('/answer', 'POST', { step: 'confirm', patch: patch, hp: '' }).then(function (j) { t.remove(); S.busy = false; if (!j.success) { toast(j.message || 'Error'); return; } S.session = j.session; if (j.session.state === 'done') { renderPreview(); showDone(j.mode); } else ask(j.session.step); }); };
    var pv = el('button', 'rs-btn', esc(ui('preview'))); pv.type = 'button'; pv.onclick = function () { setView('preview'); };
    act.appendChild(ok); act.appendChild(pv); input.appendChild(act);
  }

  function finish() { var t = typing(); api('/finish', 'POST', {}).then(function (j) { t.remove(); if (!j.success) { bot('<span class="rs-err">' + esc(j.message || 'Error') + '</span>'); return; } S.session = j.session; renderPreview(); showDone(j.mode); }); }

  /* ---------- done: preview + actions ---------- */
  function showDone(mode) {
    progress(100); input.innerHTML = ''; mbar.innerHTML = '';
    bot('<b>' + esc(ui('done_title')) + '</b><br>' + esc(ui('done_body')) + (mode === 'form' || mode === 'economy' ? '<br><small>' + esc(ui('economy')) + '</small>' : ''));
    var box = el('div', 'rs-done');
    var a1 = el('div', 'rs-actions');
    var dl = el('button', 'rs-btn rs-btn--primary', esc(ui('download'))); dl.type = 'button'; dl.onclick = function () { dl.disabled = true; api('/render', 'POST', {}).then(function (j) { dl.disabled = false; if (!j.success) { toast(j.message || 'Error'); return; } var a = document.createElement('a'); a.href = j.url; a.download = j.filename || 'CV.pdf'; a.rel = 'noopener'; document.body.appendChild(a); a.click(); a.remove(); }); };
    var em = el('button', 'rs-btn', esc(ui('email_me'))); em.type = 'button'; em.onclick = function () { sheet('<h4>' + esc(ui('email_me')) + '</h4><div class="rs-field"><input type="email" inputmode="email" placeholder="you@email.com" id="rs-em"></div><div class="rs-help">' + esc(ui('consent')) + '</div>', function (box, close) { var v = box.querySelector('#rs-em').value.trim(); if (!v) return; api('/email', 'POST', { email: v }).then(function (j) { if (!j.success) { toast(j.message || 'Error'); return; } toast('✓'); close(); }); }); };
    var pv = el('button', 'rs-btn', esc(ui('preview'))); pv.type = 'button'; pv.onclick = function () { setView('preview'); };
    a1.appendChild(dl); a1.appendChild(em); a1.appendChild(pv); box.appendChild(a1);
    var a2 = el('div', 'rs-actions'); var del = el('button', 'rs-btn rs-btn--quiet', esc(ui('delete_data'))); del.type = 'button'; del.onclick = function () { sheet('<h4>' + esc(ui('delete_data')) + '?</h4><p class="rs-help">' + esc(ui('consent')) + '</p>', function (box, close) { api('/session', 'DELETE').then(function () { S.token = null; S.session = null; try { localStorage.removeItem(KEY); } catch (e) {} close(); log.innerHTML = ''; prev.innerHTML = ''; input.innerHTML = ''; pickLanguage(false); }); }, true); }; a2.appendChild(del); box.appendChild(a2);
    input.appendChild(box);
    var mb = el('button', 'rs-btn rs-btn--primary', esc(ui('preview'))); mb.type = 'button'; mb.onclick = function () { setView('preview'); }; mbar.appendChild(mb);
  }

  /* ---------- preview with tap-to-edit ---------- */
  function renderPreview() {
    var d = S.session ? S.session.draft : null; if (!d) return; var p = d.person || {};
    function ed(path, html, label, kind) { return '<span class="ed" tabindex="0" data-path="' + esc(path) + '" data-label="' + esc(label) + '" data-kind="' + esc(kind || 'text') + '">' + html + '</span>'; }
    var h = '<div class="rs-cv">';
    if (p.photo_data_uri) h += '<img class="photo" src="' + esc(p.photo_data_uri) + '" alt="">';
    h += '<h1>' + ed('person.full_name', esc(p.full_name || '—'), 'Name') + '</h1><div class="hl">' + ed('person.headline', esc(p.headline || (d.target && d.target.roles ? d.target.roles.slice(0, 2).join(' · ') : '') || '—'), 'Headline') + '</div>';
    h += '<div class="ct">' + ed('person.phone', esc(p.phone || '—'), 'Phone') + ' · ' + ed('person.email', esc(p.email || '—'), 'Email') + ' · ' + ed('person.city', esc(p.city || '—'), 'City') + '</div>';
    h += '<h2>' + esc(ui('sections').summary) + '</h2><p>' + ed('summary', esc(d.summary || '—'), ui('sections').summary, 'textarea') + '</p>';
    h += '<h2>' + esc(ui('sections').experience) + '</h2>';
    (d.experience || []).forEach(function (j, i) { h += '<div class="job"><div><span class="jt">' + ed('experience.' + i + '.title', esc(j.title || '—'), 'Title') + '</span> — ' + ed('experience.' + i + '.employer', esc(j.employer || '—'), 'Employer') + '<span class="jd">' + ed('experience.' + i + '.start', esc(j.start || '—'), 'Start') + ' – ' + ed('experience.' + i + '.end', esc(j.end || '—'), 'End') + '</span></div>' + (j.city ? '<div class="jc">' + esc(j.city) + '</div>' : '') + '<ul>' + (j.bullets || []).map(function (b, k) { return '<li>' + ed('experience.' + i + '.bullets.' + k, esc(b), 'Bullet', 'bullet') + '</li>'; }).join('') + '</ul></div>'; });
    if (d.education && d.education.length) { h += '<h2>' + esc(ui('sections').education) + '</h2>'; d.education.forEach(function (e, i) { h += '<div>' + ed('education.' + i + '.qualification', esc(e.qualification || '—'), 'Qualification') + (e.school ? ' — ' + ed('education.' + i + '.school', esc(e.school), 'School') : '') + (e.year ? ' <span class="jd">' + ed('education.' + i + '.year', esc(e.year), 'Year') + '</span>' : '') + '</div>'; }); }
    if (d.licences && d.licences.length) h += '<h2>' + esc(ui('sections').licences) + '</h2><p>' + ed('licences', esc(d.licences.map(function (l) { return l && l.name ? l.name : l; }).join(' · ')), ui('sections').licences, 'list') + '</p>';
    if (d.skills && d.skills.length) h += '<h2>' + esc(ui('sections').skills) + '</h2><p>' + ed('skills', esc(d.skills.join(' · ')), ui('sections').skills, 'list') + '</p>';
    if (d.languages && d.languages.length) h += '<h2>' + esc(ui('sections').languages) + '</h2><p>' + ed('languages', esc(d.languages.map(function (l) { return l && l.name ? l.name : l; }).join(' · ')), ui('sections').languages, 'list') + '</p>';
    h += '<h2>' + esc(ui('sections').details) + '</h2><table class="det"><tr><td class="k">Nationality</td><td>' + esc(p.nationality || 'Filipino') + '</td></tr><tr><td class="k">Visa status</td><td>' + ed('person.visa_status', esc(p.visa_status || '—'), 'Visa status') + '</td></tr><tr><td class="k">Availability</td><td>' + ed('person.availability', esc(p.availability || '—'), 'Availability') + '</td></tr></table>';
    h += '</div>';
    prev.innerHTML = h;
    prev.querySelectorAll('.ed').forEach(function (n) { n.onclick = function () { editField(n.getAttribute('data-path'), n.getAttribute('data-label'), n.getAttribute('data-kind')); }; n.onkeydown = function (e) { if (e.key === 'Enter') n.click(); }; });
  }
  function editField(path, label, kind) {
    var d = S.session.draft; var cur = get(d, path);
    var body = '<h4>' + esc(ui('edit')) + ': ' + esc(label) + '</h4>';
    if (kind === 'list') { var arr = (cur || []).map(function (x) { return x && x.name ? x.name : x; }); body += '<div class="rs-field"><textarea id="rs-ed">' + esc(arr.join(', ')) + '</textarea><div class="rs-help">Comma-separated</div></div>'; }
    else if (kind === 'textarea' || kind === 'bullet') body += '<div class="rs-field"><textarea id="rs-ed">' + esc(cur || '') + '</textarea></div>' + (S.session.mode !== 'form' ? '<button type="button" class="rs-btn" id="rs-improve">✨ ' + esc(ui('improve')) + '</button>' : '');
    else body += '<div class="rs-field"><input id="rs-ed" value="' + esc(cur || '') + '"></div>';
    sheet(body, function (box, close) {
      var v = box.querySelector('#rs-ed').value; var patch = {};
      if (kind === 'list') { var items = v.split(',').map(function (x) { return x.trim(); }).filter(Boolean); patch[path] = (path === 'licences' || path === 'languages') ? items.map(function (x) { return { name: x }; }) : items; }
      else if (kind === 'bullet') { var m = path.match(/^(experience\.\d+)\.bullets\.(\d+)$/); var arr2 = (get(d, m[1] + '.bullets') || []).slice(); arr2[+m[2]] = v.trim(); patch[m[1] + '.bullets'] = arr2.filter(Boolean); }
      else patch[path] = v;
      api('/patch', 'POST', { patch: patch }).then(function (j) { if (!j.success) { toast(j.message || 'Error'); return; } S.session = j.session; renderPreview(); close(); toast('✓'); });
    }, false, function (box) { var ib = box.querySelector('#rs-improve'); if (ib) ib.onclick = function () { ib.disabled = true; api('/improve', 'POST', { text: box.querySelector('#rs-ed').value, context: label }).then(function (j) { ib.disabled = false; if (!j.success) { toast(j.message || ui('economy')); return; } box.querySelector('#rs-ed').value = j.text; }); }; });
  }
  function sheet(bodyHtml, onOk, danger, onOpen) {
    var ov = el('div', 'rs-sheet'); var box = el('div', 'rs-sheet-box', bodyHtml + '<div class="rs-actions" style="margin-top:12px"><button type="button" class="rs-btn rs-btn--quiet" data-x>' + esc(ui('back')) + '</button><button type="button" class="rs-btn ' + (danger ? '' : 'rs-btn--primary') + '" data-ok>' + esc(danger ? ui('delete_data') : ui('save')) + '</button></div>');
    ov.appendChild(box); document.body.appendChild(ov); document.body.style.overflow = 'hidden';
    function close() { ov.remove(); document.body.style.overflow = ''; }
    box.querySelector('[data-x]').onclick = close; ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    box.querySelector('[data-ok]').onclick = function () { onOk(box, close); };
    if (onOpen) onOpen(box); var f = box.querySelector('input,textarea'); if (f) setTimeout(function () { f.focus(); }, 30);
    document.addEventListener('keydown', function esc_(e) { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc_); } });
  }
  boot();
})();
