<?php
/** @var array $page */ /** @var array $data */
$page['title'] = 'Start';
$page['description'] = 'Create your LevelUpGrowth account in a minute — no approval, no waiting — and start building with Arthur and Sarah.';
$page['chrome'] = 'no-signup';   // DEC-0053 (2026-09-15): the header does not sell the door you are standing in
$industries = ['Restaurant or cafe', 'Gym or fitness', 'Dental or medical clinic', 'Real estate', 'Beauty or barbershop', 'Consulting or agency', 'Retail or e-commerce', 'Hotel or rental', 'Education or training', 'Home services', 'Other'];
// SELF-SERVICE SIGN-UP (DEC-0053, EV-1039, 2026-09-15). Ported from the served page on 2026-09-16 (RISK-0181): the page-drawn
// select (a bottom sheet on phones), the visible error box, and the register call that stores the session and opens /app/.
$page['head'] = <<<'HTML'
<style id="lu-css-select">
.select.lu-sel{position:relative}
.select.lu-sel>select{position:absolute;opacity:0;pointer-events:none;width:1px;height:1px;left:0;top:0}
.select.lu-sel>.select-icon{display:none}
.lu-sel-btn{width:100%;min-height:52px;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0 16px;border:1px solid rgba(255,255,255,.14);border-radius:12px;background:#0b0e18;color:#fff;font:inherit;font-size:16px;text-align:left;cursor:pointer}
.lu-sel-btn:focus-visible{outline:2px solid #7c5cff;outline-offset:2px}
.lu-sel-btn .lu-sel-caret{width:16px;height:16px;flex:0 0 auto;opacity:.7;transition:transform .15s}
.lu-sel[data-open="1"] .lu-sel-btn .lu-sel-caret{transform:rotate(180deg)}
.lu-sel-btn.is-placeholder{color:rgba(255,255,255,.6)}
.lu-sel-list{position:absolute;left:0;right:0;top:calc(100% + 6px);z-index:50;margin:0;padding:6px;list-style:none;background:#101426;border:1px solid rgba(124,92,255,.5);border-radius:12px;box-shadow:0 14px 40px rgba(0,0,0,.55);max-height:min(60vh,420px);overflow:auto;-webkit-overflow-scrolling:touch}
.lu-sel-list[hidden]{display:none}
.lu-sel-list li{padding:12px 12px;border-radius:8px;cursor:pointer;color:#fff;font-size:15px;line-height:1.3}
.lu-sel-list li:hover,.lu-sel-list li.is-active{background:rgba(124,92,255,.18)}
.lu-sel-list li[aria-selected="true"]{color:#c4b5fd;font-weight:600}
/* phone: a bottom sheet drawn by the page */
.lu-sheet-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2147482000}
.lu-sheet-backdrop[hidden]{display:none}
.lu-sheet{position:fixed;left:0;right:0;bottom:0;z-index:2147482001;background:#101426;border:1px solid rgba(124,92,255,.5);border-bottom:0;border-radius:16px 16px 0 0;box-shadow:0 -14px 40px rgba(0,0,0,.55);display:flex;flex-direction:column;max-height:80vh;max-height:80dvh;padding-bottom:env(safe-area-inset-bottom)}
.lu-sheet[hidden]{display:none}
.lu-sheet-head{display:flex;align-items:center;justify-content:space-between;padding:12px 8px 8px 16px;border-bottom:1px solid rgba(255,255,255,.1);font:600 15px system-ui,-apple-system,sans-serif;color:#fff}
.lu-sheet-close{min-width:44px;height:44px;border:0;background:transparent;color:rgba(255,255,255,.75);font-size:20px;cursor:pointer;border-radius:10px}
.lu-sheet-list{margin:0;padding:6px 8px 10px;list-style:none;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;touch-action:pan-y;flex:1 1 auto;min-height:0}
.lu-sheet-list li{padding:14px 12px;min-height:48px;border-radius:10px;color:#fff;font-size:16px;line-height:1.3;cursor:pointer}
.lu-sheet-list li[aria-selected="true"]{color:#c4b5fd;font-weight:600;background:rgba(124,92,255,.14)}
html.lu-sheet-open,html.lu-sheet-open body{overflow:hidden!important}
/* consent tick box visible on the dark theme (Owner 2026-09-15) */
.check .check-box{width:24px!important;height:24px!important;border:2px solid rgba(255,255,255,.6)!important;border-radius:7px!important;background:#0b0e18!important;display:inline-flex!important;align-items:center;justify-content:center;flex:0 0 auto}
.check .check-box svg{opacity:0;color:#fff;transition:opacity .12s}
.check input:checked ~ .check-box{background:#7c5cff!important;border-color:#7c5cff!important}
.check input:checked ~ .check-box svg{opacity:1}
.check input:focus-visible ~ .check-box{outline:2px solid #7c5cff;outline-offset:2px}
</style>
<style id="lu-start-error">#wl-error{color:#fecaca!important;background:rgba(248,113,113,.14);border:1px solid rgba(248,113,113,.45);border-radius:10px;padding:10px 12px;font-weight:600;font-size:15px;line-height:1.4;margin:10px 0}#wl-error[hidden]{display:none!important}form [aria-invalid="true"]{border-color:#f87171!important;box-shadow:0 0 0 3px rgba(248,113,113,.18)!important}</style>
HTML;
$page['scripts'] = <<<'HTML'
<script id="lu-css-select-js">
(function(){
  var isPhone = function(){ return window.innerWidth < 700 || (window.matchMedia && window.matchMedia('(pointer:coarse)').matches); };
  function build(wrap){
    var sel = wrap.querySelector('select'); if (!sel || wrap.dataset.luSel) return; wrap.dataset.luSel = '1'; wrap.classList.add('lu-sel');
    var lbl = document.querySelector('label[for="' + sel.id + '"]'); var title = lbl ? lbl.textContent.trim() : 'Choose';
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'lu-sel-btn'; btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-label', title);
    var lab = document.createElement('span'); lab.className = 'lu-sel-label'; btn.appendChild(lab);
    var caret = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); caret.setAttribute('viewBox', '0 0 24 24'); caret.setAttribute('class', 'lu-sel-caret'); caret.innerHTML = '<path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'; btn.appendChild(caret);
    // desktop: inline list under the button
    var list = document.createElement('ul'); list.className = 'lu-sel-list'; list.setAttribute('role', 'listbox'); list.hidden = true; list.id = (sel.id || 'sel') + '-list'; btn.setAttribute('aria-controls', list.id);
    // phone: bottom sheet at the end of <body>
    var backdrop = document.createElement('div'); backdrop.className = 'lu-sheet-backdrop'; backdrop.hidden = true;
    var sheet = document.createElement('div'); sheet.className = 'lu-sheet'; sheet.hidden = true; sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-label', title);
    var head = document.createElement('div'); head.className = 'lu-sheet-head'; head.innerHTML = '<span></span><button type="button" class="lu-sheet-close" aria-label="Close">✕</button>'; head.firstChild.textContent = title;
    var slist = document.createElement('ul'); slist.className = 'lu-sheet-list'; slist.setAttribute('role', 'listbox');
    sheet.appendChild(head); sheet.appendChild(slist);
    function fill(ul){ ul.innerHTML = ''; Array.prototype.forEach.call(sel.options, function(o, i){ var li = document.createElement('li'); li.setAttribute('role', 'option'); li.dataset.value = o.value; li.dataset.index = String(i); li.textContent = o.textContent; ul.appendChild(li); }); }
    fill(list); fill(slist);
    function paint(){ var o = sel.options[sel.selectedIndex]; lab.textContent = o ? o.textContent : ''; btn.classList.toggle('is-placeholder', !sel.value); [list, slist].forEach(function(ul){ Array.prototype.forEach.call(ul.children, function(li){ li.setAttribute('aria-selected', li.dataset.value === sel.value ? 'true' : 'false'); }); }); }
    var active = -1;
    function setActive(i){ var items = list.children; if (!items.length) return; i = Math.max(0, Math.min(items.length - 1, i)); Array.prototype.forEach.call(items, function(li, k){ li.classList.toggle('is-active', k === i); }); active = i; }
    function openSheet(){ backdrop.hidden = false; sheet.hidden = false; document.documentElement.classList.add('lu-sheet-open'); wrap.dataset.open = '1'; btn.setAttribute('aria-expanded', 'true'); var cur = slist.querySelector('[aria-selected="true"]'); if (cur && cur.scrollIntoView) { try { cur.scrollIntoView({ block: 'center' }); } catch (e) {} } }
    function closeSheet(){ backdrop.hidden = true; sheet.hidden = true; document.documentElement.classList.remove('lu-sheet-open'); wrap.dataset.open = '0'; btn.setAttribute('aria-expanded', 'false'); }
    function openList(){ list.hidden = false; wrap.dataset.open = '1'; btn.setAttribute('aria-expanded', 'true'); setActive(Math.max(0, sel.selectedIndex)); }
    function closeList(){ list.hidden = true; wrap.dataset.open = '0'; btn.setAttribute('aria-expanded', 'false'); }
    function isOpen(){ return !list.hidden || !sheet.hidden; }
    function open(){ if (isPhone()) openSheet(); else openList(); }
    function close(){ closeSheet(); closeList(); }
    function choose(i){ var o = sel.options[i]; if (!o) return; sel.value = o.value; sel.dispatchEvent(new Event('change', { bubbles: true })); paint(); close(); btn.focus({ preventScroll: true }); }
    btn.addEventListener('click', function(e){ e.preventDefault(); if (isOpen()) close(); else open(); });
    btn.addEventListener('keydown', function(e){ if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === ' ' || e.key === 'Enter') { e.preventDefault(); if (!isOpen()) open(); else if (!list.hidden) { if (e.key === 'Enter' || e.key === ' ') choose(active); else setActive(active + (e.key === 'ArrowDown' ? 1 : -1)); } } else if (e.key === 'Escape') { close(); } });
    list.addEventListener('click', function(e){ var li = e.target.closest('li'); if (li) choose(Number(li.dataset.index)); });
    list.addEventListener('mousemove', function(e){ var li = e.target.closest('li'); if (li) setActive(Number(li.dataset.index)); });
    slist.addEventListener('click', function(e){ var li = e.target.closest('li'); if (li) choose(Number(li.dataset.index)); });
    head.querySelector('.lu-sheet-close').addEventListener('click', close);
    backdrop.addEventListener('click', close);
    document.addEventListener('click', function(e){ if (!list.hidden && !wrap.contains(e.target)) closeList(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !sheet.hidden) close(); });
    sel.addEventListener('change', paint);
    wrap.appendChild(btn); wrap.appendChild(list); document.body.appendChild(backdrop); document.body.appendChild(sheet); paint();
  }
  function init(){ Array.prototype.forEach.call(document.querySelectorAll('form .select'), build); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
</script>
<script id="lu-start-error-js">
(function(){
  var e = document.getElementById('wl-error'); if (!e) return;
  new MutationObserver(function(){
    if (e.hidden || !e.textContent.trim()) return;
    try { e.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (_s) {}
    var bad = document.querySelector('#waitlist [aria-invalid="true"]'); if (bad && bad.focus) { try { bad.focus({ preventScroll: true }); } catch (_f) {} }
  }).observe(e, { attributes: true, attributeFilter: ['hidden'], childList: true, characterData: true, subtree: true });
})();
</script>
<script id="lu-signup-js">
(function(){
  var form = document.getElementById('signup'); if (!form) return;
  var err = document.getElementById('wl-error');
  var show = function(msg){ err.innerHTML = msg || ''; err.hidden = !msg; };
  form.addEventListener('submit', function(e){
    e.preventDefault(); show('');
    var btn = form.querySelector('button[type=submit]'); var label = btn.innerHTML; btn.disabled = true; btn.textContent = 'Creating your account…';
    form.querySelectorAll('[aria-invalid]').forEach(function(i){ i.removeAttribute('aria-invalid'); });
    var name = (form.querySelector('[name=name]').value || '').trim();
    var email = (form.querySelector('[name=email]').value || '').trim();
    var pass = form.querySelector('[name=password]').value || '';
    var company = (form.querySelector('[name=company]').value || '').trim();
    var industry = (form.querySelector('[name=industry]') || {}).value || '';
    var mark = function(k){ var i = form.querySelector('[name=' + k + ']'); if (i) i.setAttribute('aria-invalid', 'true'); };
    if (name.length < 2) { mark('name'); show('Please tell us your name.'); btn.disabled = false; btn.innerHTML = label; return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { mark('email'); show('Please enter a valid email address.'); btn.disabled = false; btn.innerHTML = label; return; }
    if (pass.length < 8 || !/[A-Z]/.test(pass) || !/[0-9]/.test(pass)) { mark('password'); show('Your password needs at least 8 characters, one capital letter and one number.'); btn.disabled = false; btn.innerHTML = label; return; }
    var done = function(){ btn.disabled = false; btn.innerHTML = label; };
    fetch('/api/auth/register', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ name: name, email: email, password: pass, password_confirmation: pass, workspace_name: company || null, industry: industry || null }) })
      .then(function(r){ return r.json().catch(function(){ return {}; }).then(function(j){ return { status: r.status, json: j }; }); })
      .then(function(res){
        var j = res.json || {};
        if ((res.status === 200 || res.status === 201) && j.access_token) {
          try {
            localStorage.setItem('lu_token', j.access_token);
            if (j.refresh_token) localStorage.setItem('lu_refresh_token', j.refresh_token);
            if (j.current_workspace_id) localStorage.setItem('lu_workspace_id', String(j.current_workspace_id));
            if (j.user && j.user.id) localStorage.setItem('lu_user_id', String(j.user.id));
            if (industry) localStorage.setItem('lu_signup_industry', industry);
            if (company) localStorage.setItem('lu_signup_business', company);
          } catch (_s) {}
          btn.textContent = 'Opening your workspace…';
          window.location.href = '/app/';
          return;
        }
        if (res.status === 422 && j.errors) {
          var first = null; Object.keys(j.errors).forEach(function(k){ mark(k); if (!first) first = k; });
          var msg = j.errors[first] ? j.errors[first][0] : 'Please check the highlighted fields.';
          if (first === 'email' && /taken|already|unique/i.test(msg)) msg = 'That email already has an account. <a href="/next/login/">Log in</a> or <a href="/next/login/#forgot">reset your password</a>.';
          show(msg); done(); return;
        }
        if (res.status === 403) { show('Sign-ups are closed on this address right now. Please try again shortly.'); done(); return; }
        if (res.status === 429) { show('Too many attempts from this connection. Please wait a minute and try again.'); done(); return; }
        show(j.message || 'Something went wrong. Please email hello@levelupgrowth.io.'); done();
      })
      .catch(function(){ show('Network problem. Please try again.'); done(); });
  });
  // password show/hide (site.js binds its own only on the login page)
  var t = form.querySelector('[data-pw-toggle]'); var pw = document.getElementById('wl-pass');
  if (t && pw) t.addEventListener('click', function(){ var vis = pw.type === 'text'; pw.type = vis ? 'password' : 'text'; t.textContent = vis ? 'Show' : 'Hide'; t.setAttribute('aria-label', vis ? 'Show password' : 'Hide password'); });
})();
</script>
HTML;
?>
<section class="section-tight page-head">
  <div class="container grid-2 align-start">
    <div>
      <p class="eyebrow">Start</p>
      <h1>Your account, one email away.</h1>
      <p class="lede">Create your account and start building — no approval, no waiting.</p>
    </div>
    <form class="form card" id="signup" method="post" action="/api/auth/register" novalidate autocomplete="on">
      <div class="form-row">
        <label for="wl-name">Your name</label>
        <input id="wl-name" name="name" type="text" autocomplete="name" required minlength="2" maxlength="120">
      </div>
      <div class="form-row">
        <label for="wl-email">Email</label>
        <input id="wl-email" name="email" type="email" autocomplete="email" required maxlength="190">
      </div>
      <div class="form-row">
        <label for="wl-pass">Password</label>
        <div class="pw-wrap">
          <input id="wl-pass" name="password" type="password" autocomplete="new-password" required minlength="8">
          <button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password">Show</button>
        </div>
        <p class="fine" style="margin:6px 0 0">At least 8 characters, one capital letter and one number.</p>
      </div>
      <div class="form-row">
        <label for="wl-company">Business name <span class="opt">optional</span></label>
        <input id="wl-company" name="company" type="text" autocomplete="organization" maxlength="150">
      </div>
      <div class="form-row form-row-2">
        <div>
          <label for="wl-industry">Industry</label>
          <div class="select">
            <select id="wl-industry" name="industry">
              <option value="">Choose</option>
              <?php foreach ($industries as $i): ?><option value="<?= e($i) ?>"><?= e($i) ?></option><?php endforeach; ?>            </select>
            <?= icon('arrow-right', 16, 'select-icon') ?>          </div>
        </div>
      </div>
      <div class="hp" aria-hidden="true"><label>Leave this empty<input type="text" name="website_confirm" tabindex="-1" autocomplete="off"></label></div>
      <input type="hidden" name="source_page" value="/next/start/">
      <p class="form-error" id="wl-error" role="alert" hidden></p>
      <button class="btn btn-primary btn-lg" type="submit" style="justify-content:center;text-align:center;width:100%">Level Up Now <?= icon('arrow-right', 18) ?></button>
      <p class="fine form-fine">By creating an account you agree to the <a href="/next/legal/terms/">terms</a>.</p>
      <p class="fine form-fine" style="text-align:center">Already have an account? <a href="/next/login/">Log in</a></p>
    </form>
  </div>
</section>
