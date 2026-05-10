/*
 * LevelUp Growth — Onboarding + Auth Views
 * Phase O1 (2026-05-10): extracted from core.js
 * Phase O2 (2026-05-10): Step 1 redesigned — Mission Control dark theme,
 *                       20-agent orbit, amber CTA, real social proof.
 *
 * Contains:
 *   - _renderLogin / _doLogin (centred .ob-card)
 *   - _renderSignup / _doSignup / _su* (split panel + orbit + strength meter)
 *   - _renderOnboardingStep2 / _ob2 / _ob2Submit (preserved from O1)
 *   - _showOnboardingStep3 / _OB_INDUSTRY_SLUG (preserved from O1)
 *
 * Globals from core.js (must load BEFORE this file via <script defer>):
 *   _luBase, _luFetch, _luEsc, _appBootstrap, _appEnterDashboard,
 *   showToast, nav, window.icon, window.wsShowArthurWizard, window._arthurSend
 *
 * Markup uses .lu-onboard namespace; styles in /app/css/onboarding.css.
 */

// ── Orbit builder (Phase O2.4 — uses real OrbAvatar from orb.js) ─────────────
// orb.js + orb.css (loaded by index.html before onboarding.js) provide the
// canonical agent-orb visual system. We just position them in two orbits.
function _obBuildOrbit() {
  var agents = [
    // [id, ring, size]
    { id: 'sarah',  ring: 'centre', size: 'lg' },
    // Inner ring — 5 agents
    { id: 'james',  ring: 'inner',  size: 'md' },
    { id: 'priya',  ring: 'inner',  size: 'md' },
    { id: 'marcus', ring: 'inner',  size: 'md' },
    { id: 'elena',  ring: 'inner',  size: 'md' },
    { id: 'alex',   ring: 'inner',  size: 'md' },
    // Outer ring — 14 agents
    { id: 'diana',  ring: 'outer',  size: 'sm' },
    { id: 'ryan',   ring: 'outer',  size: 'sm' },
    { id: 'sofia',  ring: 'outer',  size: 'sm' },
    { id: 'leo',    ring: 'outer',  size: 'sm' },
    { id: 'maya',   ring: 'outer',  size: 'sm' },
    { id: 'nora',   ring: 'outer',  size: 'sm' },
    { id: 'zara',   ring: 'outer',  size: 'sm' },
    { id: 'tyler',  ring: 'outer',  size: 'sm' },
    { id: 'jordan', ring: 'outer',  size: 'sm' },
    { id: 'chris',  ring: 'outer',  size: 'sm' },
    { id: 'aria',   ring: 'outer',  size: 'sm' },
    { id: 'kai',    ring: 'outer',  size: 'sm' },
    { id: 'vera',   ring: 'outer',  size: 'sm' },
    { id: 'max',    ring: 'outer',  size: 'sm' }
  ];

  var innerCount = 0, outerCount = 0;
  var innerTotal = 5, outerTotal = 14;

  function makeOrbHTML(id, size) {
    if (window.OrbAvatar && window.OrbAvatar.buildAgentOrb) {
      return window.OrbAvatar.buildAgentOrb(id, size, 'idle');
    }
    // Fallback if orb.js failed to load — minimal placeholder
    return '<div class="orb" data-type="seo" data-size="' + size +
           '" data-state="idle"><div class="orb-core"></div>' +
           '<div class="orb-shine"></div><div class="orb-ring"></div></div>';
  }

  var html = '<div class="ob-orbit-wrap">';
  agents.forEach(function(a) {
    var orbHtml = makeOrbHTML(a.id, a.size);
    if (a.ring === 'centre') {
      html += '<div class="ob-slot ob-slot--centre">' + orbHtml + '</div>';
    } else if (a.ring === 'inner') {
      var angle = (innerCount / innerTotal) * 360;
      html += '<div class="ob-slot ob-slot--inner" style="--ob-angle:' +
              angle + 'deg">' + orbHtml + '</div>';
      innerCount++;
    } else {
      var angle = (outerCount / outerTotal) * 360;
      html += '<div class="ob-slot ob-slot--outer" style="--ob-angle:' +
              angle + 'deg">' + orbHtml + '</div>';
      outerCount++;
    }
  });
  html += '</div>';
  return html;
}

// ── TASK 1.4: Login view (Phase O2 — centred card) ───────────────────────────
function _renderLogin() {
  var root = document.getElementById('lu-auth-root');
  if (!root) return;
  root.style.display = 'flex';
  var appShell = document.querySelector('.app');
  if (appShell) appShell.style.display = 'none';

  root.innerHTML = `
  <div class="lu-onboard">
    <div class="ob-login-wrap">
      <div class="ob-card">
        <div class="ob-login-logo">
          <div class="ob-logo-mark">L</div>
          <span>LevelUp Growth</span>
        </div>
        <h2 class="ob-card-title">Welcome back</h2>
        <p class="ob-card-sub">Sign in to your workspace</p>

        <div class="ob-form">
          <div class="ob-field">
            <input id="ob-login-email" type="email" placeholder="Email address" autocomplete="email">
          </div>
          <div class="ob-field">
            <div class="ob-pwd-wrap">
              <input id="ob-login-pwd" type="password" placeholder="Password" autocomplete="current-password">
              <button type="button" class="ob-pwd-toggle" onclick="_suTogglePwd('ob-login-pwd','ob-login-pwd-tg')" id="ob-login-pwd-tg">Show</button>
            </div>
          </div>
          <div class="ob-field-err" id="ob-login-err" style="display:none"></div>
          <button class="ob-btn-primary" id="ob-login-btn" onclick="_doLogin()">
            <span class="ob-btn-label">Sign in</span>
            <span class="ob-btn-arrow">&rarr;</span>
            <span class="ob-btn-spinner" style="display:none">&#8634;</span>
          </button>
        </div>

        <p class="ob-switch">No account?
          <a href="#" onclick="_renderSignup();return false;">Create one free</a>
        </p>
      </div>
    </div>
  </div>`;
}

function _setBtnLoading(btnId, loading, loadingLabel, idleLabel) {
  var btn = document.getElementById(btnId);
  if (!btn) return;
  var lbl = btn.querySelector('.ob-btn-label');
  var arr = btn.querySelector('.ob-btn-arrow');
  var spn = btn.querySelector('.ob-btn-spinner');
  if (loading) {
    btn.disabled = true;
    if (lbl && loadingLabel) lbl.textContent = loadingLabel;
    if (arr) arr.style.display = 'none';
    if (spn) spn.style.display = '';
  } else {
    btn.disabled = false;
    if (lbl && idleLabel) lbl.textContent = idleLabel;
    if (arr) arr.style.display = '';
    if (spn) spn.style.display = 'none';
  }
}

async function _doLogin() {
  var email = (document.getElementById('ob-login-email')||{}).value||'';
  var pwd   = (document.getElementById('ob-login-pwd')||{}).value||'';
  var err   = document.getElementById('ob-login-err');
  _setBtnLoading('ob-login-btn', true, 'Signing in…', 'Sign in');
  if (err) err.style.display = 'none';
  try {
    var r = await fetch(_luBase + '/api/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ email: email, password: pwd }),
    });
    var d = await r.json();
    if (!d.access_token) throw new Error(d.message || d.error || 'Login failed');
    localStorage.setItem('lu_token', d.access_token);
    if (d.refresh_token) localStorage.setItem('lu_refresh_token', d.refresh_token);
    if (d.user && d.user.id) localStorage.setItem('lu_user_id', String(d.user.id));
    await _appBootstrap();
  } catch(e) {
    if (err) { err.style.display = 'block'; err.textContent = e.message; }
    _setBtnLoading('ob-login-btn', false, null, 'Sign in');
  }
}

// ── Signup view (Onboarding Step 1 — Phase O2 redesign 2026-05-10) ───────────
function _renderSignup() {
  var root = document.getElementById('lu-auth-root');
  if (!root) return;
  root.style.display = 'flex';
  var appShell = document.querySelector('.app');
  if (appShell) appShell.style.display = 'none';

  // Orbit markup is built by _obBuildOrbit() — uses real OrbAvatar orbs
  var orbitHTML = _obBuildOrbit();

  root.innerHTML = `
  <div class="lu-onboard">
    <div class="ob-signup-wrap">

      <div class="ob-left">
        <div class="ob-logo">
          <div class="ob-logo-mark">
            <img src="/img/logo-icon-40.png" style="width:40px;height:40px;object-fit:contain" alt="">
          </div>
          <span class="ob-logo-name">LevelUpGrowth</span>
        </div>

        <h1 class="ob-headline">Your AI marketing<br>team starts now.</h1>

        ${orbitHTML}

        <ul class="ob-props">
          <li>Website live in under 5 minutes</li>
          <li>Sarah analyses your market today</li>
          <li>No credit card required</li>
        </ul>

        <p class="ob-social-proof" id="ob-social-proof" style="display:none"></p>
      </div>

      <div class="ob-right">
        <div class="ob-card">
          <h2 class="ob-card-title">Create your account</h2>
          <p class="ob-card-sub">Free forever. No card needed.</p>

          <div class="ob-form">
            <div class="ob-field">
              <input id="ob-su-name" type="text" placeholder="Full name" autocomplete="name">
              <div class="ob-field-err-line" id="ob-su-name-err" style="display:none"></div>
            </div>

            <div class="ob-field">
              <input id="ob-su-email" type="email" placeholder="Email address" autocomplete="email">
              <div class="ob-field-err-line" id="ob-su-email-err" style="display:none"></div>
            </div>

            <div class="ob-field">
              <div class="ob-pwd-wrap">
                <input id="ob-su-pwd" type="password" placeholder="Password" autocomplete="new-password" oninput="_suCheckPwd()">
                <button type="button" class="ob-pwd-toggle" onclick="_suTogglePwd('ob-su-pwd','ob-su-pwd-tg')" id="ob-su-pwd-tg">Show</button>
              </div>
              <div class="ob-field-err-line" id="ob-su-pwd-err" style="display:none"></div>
              <div class="ob-pwd-strength">
                <div class="ob-pwd-bar">
                  <span class="ob-pwd-seg" id="ob-seg-1"></span>
                  <span class="ob-pwd-seg" id="ob-seg-2"></span>
                  <span class="ob-pwd-seg" id="ob-seg-3"></span>
                  <span class="ob-pwd-seg" id="ob-seg-4"></span>
                </div>
                <div class="ob-pwd-rules">
                  <span class="ob-rule" id="ob-rule-len">8+ chars</span>
                  <span class="ob-rule" id="ob-rule-upper">Uppercase</span>
                  <span class="ob-rule" id="ob-rule-num">Number</span>
                </div>
              </div>
            </div>

            <div class="ob-field">
              <div class="ob-pwd-wrap">
                <input id="ob-su-pwd2" type="password" placeholder="Confirm password" autocomplete="new-password">
                <button type="button" class="ob-pwd-toggle" onclick="_suTogglePwd('ob-su-pwd2','ob-su-pwd2-tg')" id="ob-su-pwd2-tg">Show</button>
              </div>
              <div class="ob-field-err-line" id="ob-su-pwd2-err" style="display:none"></div>
            </div>

            <div class="ob-field-err" id="ob-su-err" style="display:none"></div>

            <button class="ob-btn-primary" id="ob-su-submit" onclick="_doSignup()">
              <span class="ob-btn-label">Join the team</span>
              <span class="ob-btn-arrow">&rarr;</span>
              <span class="ob-btn-spinner" style="display:none">&#8634;</span>
            </button>
          </div>

          <p class="ob-switch">Already have an account?
            <a href="#" onclick="_renderLogin();return false;">Sign in</a>
          </p>
          <p class="ob-legal">
            By continuing you agree to our
            <a href="https://levelupgrowth.io/terms" target="_blank">Terms</a> and
            <a href="https://levelupgrowth.io/privacy" target="_blank">Privacy Policy</a>
          </p>
        </div>
      </div>

    </div>
  </div>`;

  _obFetchSocialProof();
}

function _obFetchSocialProof() {
  fetch(_luBase + '/api/public/workspace-count', { headers: { 'Accept': 'application/json' } })
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(d){
      if (d && typeof d.count === 'number' && d.count >= 50) {
        var el = document.getElementById('ob-social-proof');
        if (el) {
          el.textContent = 'Join ' + d.count + '+ businesses in MENA & beyond';
          el.style.display = 'block';
        }
      }
    })
    .catch(function(){ /* silently fail — never show fake number */ });
}

function _suTogglePwd(inputId, btnId){
  var inp = document.getElementById(inputId);
  var btn = document.getElementById(btnId);
  if (!inp || !btn) return;
  if (inp.type === 'password') { inp.type = 'text'; btn.textContent = 'Hide'; }
  else { inp.type = 'password'; btn.textContent = 'Show'; }
}

function _suCheckPwd(){
  var p = (document.getElementById('ob-su-pwd')||{}).value || '';
  var hasLen   = p.length >= 8;
  var hasUpper = /[A-Z]/.test(p);
  var hasNum   = /[0-9]/.test(p);
  var hasLong  = p.length >= 12;

  var rL = document.getElementById('ob-rule-len');
  var rU = document.getElementById('ob-rule-upper');
  var rN = document.getElementById('ob-rule-num');
  if (rL) rL.classList.toggle('met', hasLen);
  if (rU) rU.classList.toggle('met', hasUpper);
  if (rN) rN.classList.toggle('met', hasNum);

  var rulesMet = (hasLen?1:0) + (hasUpper?1:0) + (hasNum?1:0);
  var allRules = (rulesMet === 3);
  var strong   = allRules && hasLong;
  for (var i = 1; i <= 4; i++) {
    var s = document.getElementById('ob-seg-' + i);
    if (!s) continue;
    s.classList.remove('active');
    s.classList.remove('strong');
    if (i <= rulesMet) {
      if (strong) s.classList.add('strong');
      else        s.classList.add('active');
    } else if (i === 4 && strong) {
      s.classList.add('strong');
    }
  }
}

function _suShowFieldErr(fieldId, msg) {
  var el = document.getElementById(fieldId + '-err');
  var inp = document.getElementById(fieldId);
  if (el) { el.style.display = 'block'; el.textContent = msg; }
  if (inp) inp.classList.add('ob-input-err');
}

function _suClearFieldErrs() {
  ['ob-su-name','ob-su-email','ob-su-pwd','ob-su-pwd2'].forEach(function(id){
    var el = document.getElementById(id + '-err');
    var inp = document.getElementById(id);
    if (el) el.style.display = 'none';
    if (inp) inp.classList.remove('ob-input-err');
  });
  var ge = document.getElementById('ob-su-err');
  if (ge) ge.style.display = 'none';
}

async function _doSignup() {
  var name  = (document.getElementById('ob-su-name')||{}).value.trim();
  var email = (document.getElementById('ob-su-email')||{}).value.trim();
  var pwd   = (document.getElementById('ob-su-pwd')||{}).value || '';
  var pwd2  = (document.getElementById('ob-su-pwd2')||{}).value || '';

  _suClearFieldErrs();
  var bad = false;
  if (!name)  { _suShowFieldErr('ob-su-name', 'Please enter your full name.'); bad = true; }
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { _suShowFieldErr('ob-su-email', 'Please enter a valid email.'); bad = true; }
  if (pwd.length < 8) { _suShowFieldErr('ob-su-pwd', 'Password must be at least 8 characters.'); bad = true; }
  else if (!/[A-Z]/.test(pwd)) { _suShowFieldErr('ob-su-pwd', 'Password must include an uppercase letter.'); bad = true; }
  else if (!/[0-9]/.test(pwd)) { _suShowFieldErr('ob-su-pwd', 'Password must include a number.'); bad = true; }
  if (pwd !== pwd2) { _suShowFieldErr('ob-su-pwd2', 'Passwords do not match.'); bad = true; }
  if (bad) return;

  _setBtnLoading('ob-su-submit', true, 'Creating account…', 'Join the team');
  try {
    var r = await fetch(_luBase + '/api/auth/register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ name: name, email: email, password: pwd, password_confirmation: pwd2 }),
    });
    var d = await r.json().catch(function(){ return {}; });
    if (!r.ok || !d.access_token) {
      if (d.errors) {
        if (d.errors.email)    _suShowFieldErr('ob-su-email', d.errors.email[0]);
        if (d.errors.password) _suShowFieldErr('ob-su-pwd', d.errors.password[0]);
        if (d.errors.name)     _suShowFieldErr('ob-su-name', d.errors.name[0]);
      } else {
        var ge = document.getElementById('ob-su-err');
        if (ge) { ge.style.display = 'block'; ge.textContent = d.message || d.error || 'Signup failed.'; }
      }
      throw new Error('validation');
    }
    localStorage.setItem('lu_token', d.access_token);
    if (d.refresh_token) localStorage.setItem('lu_refresh_token', d.refresh_token);
    if (d.user && d.user.id) localStorage.setItem('lu_user_id', String(d.user.id));
    if (d.user && d.user.name) localStorage.setItem('lu_user_name', d.user.name);

    // Success: pulse all orbs into 'success' state via orb.css, then advance.
    var orbs = document.querySelectorAll('.lu-onboard .orb');
    orbs.forEach(function(o){ o.dataset.state = 'success'; });
    setTimeout(function(){
      orbs.forEach(function(o){ o.dataset.state = 'idle'; });
      _renderOnboardingStep2({});
    }, 500);
  } catch(e) {
    _setBtnLoading('ob-su-submit', false, null, 'Join the team');
  }
}

// ── Onboarding Step 2 — Business Info (runbook 2026-04-25) ──────────────────
var _ob2 = {
  business_name: '', industry: '', city: '', country: 'AE',
  website: '', primary_goal: '', customer_type: '',
  employees: '', budget: '',
  brand_color: '#6C5CE7', logo_url: '',
};

var _OB2_INDUSTRIES = [
  'Architecture','Automotive','Beauty & Wellness','Cafe & Coffee','Childcare',
  'Cleaning Services','Consulting','Construction','Education','Events',
  'Fashion & Retail','Finance','Fitness & Gym','Healthcare','Hospitality & Hotel',
  'Interior Design','Legal Services','Logistics','Marketing Agency','Pet Services',
  'Photography','Real Estate','Real Estate Broker','Restaurant',
  'Technology & SaaS','Travel & Tourism','Wellness'
];

var _OB2_COUNTRIES = [
  ['AE','United Arab Emirates'],['SA','Saudi Arabia'],['US','United States'],
  ['GB','United Kingdom'],['AU','Australia'],['DE','Germany'],['SG','Singapore'],
  ['PH','Philippines'],
  ['BH','Bahrain'],['CA','Canada'],['CH','Switzerland'],['EG','Egypt'],['ES','Spain'],
  ['FR','France'],['HK','Hong Kong'],['ID','Indonesia'],['IN','India'],['IT','Italy'],
  ['JO','Jordan'],['JP','Japan'],['KW','Kuwait'],['LB','Lebanon'],['MY','Malaysia'],
  ['NL','Netherlands'],['NZ','New Zealand'],['OM','Oman'],['QA','Qatar'],['TH','Thailand'],
  ['TR','Turkey'],['VN','Vietnam']
];

var _OB2_GOALS = [
  ['leads','Get more leads & customers','🚀'],
  ['social','Grow my social media presence','📈'],
  ['seo','Rank higher on Google','🔍'],
  ['email','Build my email list','📧'],
  ['website','Launch my website','🌐'],
  ['agency','Replace my marketing agency','💼'],
];

var _OB2_CUSTOMERS = [
  ['local','Local customers in my city'],
  ['national','Customers across my country'],
  ['international','International customers'],
  ['b2b','Other businesses (B2B)'],
];

var _OB2_EMPLOYEES = ['Just me','2-10','11-50','50+'];
var _OB2_BUDGETS = ['Under $500','$500-2K','$2K-5K','$5K+'];
var _OB2_COLORS = ['#6C5CE7','#00E5A8','#F43F5E','#F59E0B','#3B82F6','#111827'];

function _ob2Esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

function _renderOnboardingStep2(prefill) {
  // Merge prefill from /onboarding/status resume
  prefill = prefill || {};
  if (prefill.business_name) _ob2.business_name = prefill.business_name;
  if (prefill.industry)      _ob2.industry = prefill.industry;
  if (prefill.brand_color)   _ob2.brand_color = prefill.brand_color;
  if (prefill.logo_url)      _ob2.logo_url = prefill.logo_url;
  if (prefill.onboarding_data) {
    var od = prefill.onboarding_data;
    ['business_name','industry','city','country','website','primary_goal','customer_type','employees','budget'].forEach(function(k){
      if (od[k]) _ob2[k] = od[k];
    });
  }

  var root = document.getElementById('lu-auth-root');
  if (!root) return;
  root.style.display = 'flex';
  var appShell = document.querySelector('.app');
  if (appShell) appShell.style.display = 'none';

  var indOptions = _OB2_INDUSTRIES.map(function(v){
    return '<option value="'+_ob2Esc(v)+'"'+(_ob2.industry===v?' selected':'')+'>'+_ob2Esc(v)+'</option>';
  }).join('');
  var ctryOptions = _OB2_COUNTRIES.map(function(c){
    return '<option value="'+_ob2Esc(c[0])+'"'+(_ob2.country===c[0]?' selected':'')+'>'+_ob2Esc(c[1])+'</option>';
  }).join('');

  var goalCards = _OB2_GOALS.map(function(g){
    var sel = _ob2.primary_goal === g[0];
    return '<div class="ob2-goal-card" data-val="'+_ob2Esc(g[0])+'" onclick="_ob2SelectGoal(\''+g[0]+'\')" style="background:'+(sel?'rgba(108,92,231,.08)':'rgba(255,255,255,.03)')+';border:1px solid '+(sel?'#6C5CE7':'rgba(255,255,255,.07)')+';border-radius:12px;padding:18px;cursor:pointer;text-align:left;transition:all .15s"><div style="font-size:26px;margin-bottom:8px">'+g[2]+'</div><div style="font-size:14px;font-weight:600;color:#F0F0F8;line-height:1.3">'+_ob2Esc(g[1])+'</div></div>';
  }).join('');

  var custCards = _OB2_CUSTOMERS.map(function(c){
    var sel = _ob2.customer_type === c[0];
    return '<div onclick="_ob2SelectCustomer(\''+c[0]+'\')" style="background:'+(sel?'rgba(108,92,231,.08)':'rgba(255,255,255,.03)')+';border:1px solid '+(sel?'#6C5CE7':'rgba(255,255,255,.07)')+';border-radius:10px;padding:14px 16px;cursor:pointer;font-size:14px;color:#F0F0F8;transition:all .15s">'+_ob2Esc(c[1])+'</div>';
  }).join('');

  var empBtns = _OB2_EMPLOYEES.map(function(e){
    var sel = _ob2.employees === e;
    return '<button type="button" onclick="_ob2SelectEmp(\''+_ob2Esc(e)+'\')" style="flex:1;padding:10px 12px;background:'+(sel?'rgba(108,92,231,.15)':'rgba(255,255,255,.04)')+';border:1px solid '+(sel?'#6C5CE7':'rgba(255,255,255,.1)')+';color:#F0F0F8;border-radius:8px;font-size:13px;cursor:pointer;font-family:inherit">'+_ob2Esc(e)+'</button>';
  }).join('');

  var budBtns = _OB2_BUDGETS.map(function(b){
    var sel = _ob2.budget === b;
    return '<button type="button" onclick="_ob2SelectBud(\''+_ob2Esc(b)+'\')" style="flex:1;padding:10px 12px;background:'+(sel?'rgba(108,92,231,.15)':'rgba(255,255,255,.04)')+';border:1px solid '+(sel?'#6C5CE7':'rgba(255,255,255,.1)')+';color:#F0F0F8;border-radius:8px;font-size:13px;cursor:pointer;font-family:inherit">'+_ob2Esc(b)+'</button>';
  }).join('');

  var swatches = _OB2_COLORS.map(function(c){
    var sel = _ob2.brand_color === c;
    return '<div onclick="_ob2SelectColor(\''+c+'\')" style="width:36px;height:36px;border-radius:8px;background:'+c+';cursor:pointer;border:2px solid '+(sel?'#F0F0F8':'rgba(255,255,255,.1)')+';box-shadow:'+(sel?'0 0 0 2px rgba(108,92,231,.3)':'none')+'"></div>';
  }).join('');

  root.innerHTML = `
  <div id="lu-ob2-page" style="min-height:100vh;background:#06070D;color:#F0F0F8;font-family:'DM Sans',system-ui,sans-serif;padding:40px 20px">
    <div style="max-width:720px;margin:0 auto">
      <!-- Progress -->
      <div style="display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:40px;font-size:12px;color:rgba(240,240,248,.52)">
        <span style="color:rgba(240,240,248,.4)">1 Account</span>
        <span style="opacity:.3">→</span>
        <span style="color:#F0F0F8;font-weight:600">2 Your Business</span>
        <span style="opacity:.3">→</span>
        <span style="color:rgba(240,240,248,.4)">3 Setup</span>
      </div>
      <div style="background:linear-gradient(90deg,#6C5CE7 66%,rgba(255,255,255,.07) 66%);height:3px;border-radius:2px;max-width:480px;margin:0 auto 40px"></div>

      <h1 style="font-family:Syne,sans-serif;font-size:30px;font-weight:800;margin:0 0 10px;text-align:center">Tell us about your business</h1>
      <p style="color:rgba(240,240,248,.72);font-size:14px;text-align:center;margin:0 0 36px">This takes 2 minutes and powers everything your AI team does.</p>

      <div style="background:#0C0D15;border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:32px">
        <!-- Section 1: Basics -->
        <div style="font-size:12px;font-weight:700;color:rgba(240,240,248,.52);text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px">The basics</div>

        <div style="margin-bottom:16px">
          <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:6px">Business name <span style="color:#F87171">*</span></label>
          <input id="ob2-name" type="text" value="${_ob2Esc(_ob2.business_name)}" placeholder="e.g. Nour Restaurant" class="ob2-inp" style="width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 16px;color:#F0F0F8;font-size:15px;font-family:inherit;box-sizing:border-box">
        </div>

        <div style="margin-bottom:16px">
          <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:6px">Industry <span style="color:#F87171">*</span></label>
          <input id="ob2-ind-search" type="text" oninput="_ob2FilterIndustry()" placeholder="Search industries…" class="ob2-inp" style="width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:10px 14px;color:#F0F0F8;font-size:14px;font-family:inherit;box-sizing:border-box;margin-bottom:6px">
          <select id="ob2-industry" size="1" class="ob2-inp" style="width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 16px;color:#F0F0F8;font-size:15px;font-family:inherit;box-sizing:border-box">
            <option value="">Select your industry…</option>
            ${indOptions}
          </select>
        </div>

        <div style="display:flex;gap:12px;margin-bottom:16px">
          <div style="flex:1">
            <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:6px">City <span style="color:#F87171">*</span></label>
            <input id="ob2-city" type="text" value="${_ob2Esc(_ob2.city)}" placeholder="Dubai" class="ob2-inp" style="width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 16px;color:#F0F0F8;font-size:15px;font-family:inherit;box-sizing:border-box">
          </div>
          <div style="flex:1">
            <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:6px">Country <span style="color:#F87171">*</span></label>
            <select id="ob2-country" class="ob2-inp" style="width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 16px;color:#F0F0F8;font-size:15px;font-family:inherit;box-sizing:border-box">${ctryOptions}</select>
          </div>
        </div>

        <div style="margin-bottom:28px">
          <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:6px">Business website <span style="color:rgba(240,240,248,.52)">(optional)</span></label>
          <input id="ob2-website" type="url" value="${_ob2Esc(_ob2.website)}" placeholder="https://yourbusiness.com (if you have one)" class="ob2-inp" style="width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 16px;color:#F0F0F8;font-size:15px;font-family:inherit;box-sizing:border-box">
        </div>

        <!-- Section 2: Goal -->
        <div style="font-size:12px;font-weight:700;color:rgba(240,240,248,.52);text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px">Your goal</div>

        <div style="margin-bottom:24px">
          <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:10px">What's your main goal right now? <span style="color:#F87171">*</span></label>
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px">${goalCards}</div>
        </div>

        <div style="margin-bottom:20px">
          <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:10px">Who are your customers? <span style="color:#F87171">*</span></label>
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px">${custCards}</div>
        </div>

        <div style="margin-bottom:20px">
          <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:10px">How many employees?</label>
          <div style="display:flex;gap:8px">${empBtns}</div>
        </div>

        <div style="margin-bottom:28px">
          <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:10px">Monthly marketing budget <span style="color:rgba(240,240,248,.52)">(optional)</span></label>
          <div style="display:flex;gap:8px">${budBtns}</div>
        </div>

        <!-- Section 3: Brand -->
        <div style="font-size:12px;font-weight:700;color:rgba(240,240,248,.52);text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px">Brand basics</div>

        <div style="margin-bottom:20px">
          <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:10px">Brand colors <span style="color:rgba(240,240,248,.52)">(optional)</span></label>
          <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">${swatches}</div>
          <div style="display:flex;gap:10px;align-items:center">
            <input id="ob2-color-picker" type="color" value="${_ob2Esc(_ob2.brand_color)}" onchange="_ob2ColorFromPicker()" style="width:44px;height:38px;border:1px solid rgba(255,255,255,.1);border-radius:8px;background:transparent;cursor:pointer;padding:2px">
            <input id="ob2-color-hex" type="text" value="${_ob2Esc(_ob2.brand_color)}" oninput="_ob2ColorFromHex()" maxlength="7" class="ob2-inp" style="flex:1;max-width:140px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:10px 14px;color:#F0F0F8;font-size:14px;font-family:'SF Mono',monospace;box-sizing:border-box">
            <span style="font-size:12px;color:rgba(240,240,248,.52)">We'll use this in your website and designs</span>
          </div>
        </div>

        <div style="margin-bottom:28px">
          <label style="display:block;font-size:13px;color:rgba(240,240,248,.72);margin-bottom:10px">Logo <span style="color:rgba(240,240,248,.52)">(optional)</span></label>
          <div id="ob2-logo-preview" style="display:${_ob2.logo_url?'flex':'none'};align-items:center;gap:12px;margin-bottom:10px">
            <img src="${_ob2Esc(_ob2.logo_url)}" alt="logo" style="max-width:96px;max-height:64px;border-radius:8px;background:rgba(255,255,255,.05);padding:6px">
            <button type="button" onclick="_ob2RemoveLogo()" style="background:transparent;border:1px solid rgba(255,255,255,.1);color:rgba(240,240,248,.72);padding:6px 12px;border-radius:8px;cursor:pointer;font-size:12px;font-family:inherit">Remove</button>
          </div>
          <div style="display:flex;gap:10px;align-items:center">
            <label for="ob2-logo-file" id="ob2-logo-label" style="padding:10px 16px;background:rgba(255,255,255,.05);border:1px dashed rgba(255,255,255,.2);border-radius:10px;color:rgba(240,240,248,.72);cursor:pointer;font-size:13px">Upload logo</label>
            <input id="ob2-logo-file" type="file" accept="image/png,image/jpeg,image/svg+xml" onchange="_ob2UploadLogo(this)" style="display:none">
            <span id="ob2-logo-status" style="font-size:12px;color:rgba(240,240,248,.52)">PNG, JPG, SVG, max 5MB</span>
          </div>
        </div>

        <div id="ob2-err" style="color:#F87171;font-size:13px;margin-bottom:16px;display:none;text-align:center"></div>

        <button id="ob2-submit" onclick="_ob2Submit()" style="width:100%;padding:14px;background:#6C5CE7;color:#fff;border:0;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;font-family:inherit">Let's go →</button>
        <p style="text-align:center;font-size:12px;color:rgba(240,240,248,.52);margin:12px 0 0">Sarah will present your strategy within minutes of completing setup</p>
      </div>
    </div>
  </div>
  <style>
    /* PATCH (onboarding polish) — overrides on top of inline styles */
    #lu-ob2-page { --ob-p:#6C5CE7; --ob-p-deep:#5948D9; --ob-bg:#0C0D15; --ob-fg:#F0F0F8; --ob-muted:rgba(240,240,248,.52); --ob-border:rgba(255,255,255,.08); }
    #lu-ob2-page > div { max-width: 640px !important; }              /* spec: 640px max */
    #lu-ob2-page > div > div[style*="background:#0C0D15"] { padding: 40px 48px !important; border-radius: 12px !important; }

    /* Inputs / selects — 48px height, 10px radius, 1.5px border, 15px font */
    #lu-ob2-page .ob2-inp {
      height: 48px !important; min-height: 48px !important;
      border: 1.5px solid var(--ob-border) !important;
      border-radius: 8px !important;
      padding: 0 16px !important;
      font-size: 15px !important;
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    #lu-ob2-page select.ob2-inp { padding: 0 38px 0 16px !important; appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'><path d='M1 1.5L6 6.5L11 1.5' stroke='%23F0F0F8' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round' opacity='.7'/></svg>") !important; background-position: right 14px center !important; background-repeat: no-repeat !important; }
    #lu-ob2-page textarea.ob2-inp { height:auto !important; padding:12px 16px !important; }
    #lu-ob2-page .ob2-inp:focus { outline:none !important; border-color:var(--ob-p) !important; box-shadow:0 0 0 3px rgba(108,92,231,.15) !important; }

    /* Goal cards — equal-height grid, 100px min, 1.5px border, 10px radius, 12px gap, 28px icon */
    #lu-ob2-page [style*="grid-template-columns:repeat(2,1fr)"] { gap: 12px !important; }
    #lu-ob2-page .ob2-goal-card {
      min-height: 100px !important;
      padding: 20px !important;
      border-width: 1.5px !important;
      border-radius: 10px !important;
      transition: border-color .15s ease, background-color .15s ease, transform .1s ease !important;
    }
    #lu-ob2-page .ob2-goal-card > div:first-child { font-size: 28px !important; }
    #lu-ob2-page .ob2-goal-card > div:last-child  { font-size: 14px !important; font-weight: 500 !important; line-height: 1.3 !important; }
    #lu-ob2-page .ob2-goal-card:hover {
      border-color: var(--ob-p) !important;
      background-color: rgba(108,92,231,.04) !important;
    }

    /* Section labels (YOUR GOAL etc.) — 11px / 0.08em — already close, normalise */
    #lu-ob2-page div[style*="text-transform:uppercase"][style*="letter-spacing:.08em"] {
      font-size: 11px !important; letter-spacing: 0.08em !important; color: var(--ob-muted) !important; margin-bottom: 18px !important;
    }
    /* Question labels — 15px / weight 500 */
    #lu-ob2-page label[style*="display:block"][style*="font-size:13px"] { font-size: 13px !important; }
    /* Section spacing — 32px */
    #lu-ob2-page div[style*="margin-bottom:28px"] { margin-bottom: 32px !important; }

    /* Submit button — full-width, 52px, weight 600 */
    #lu-ob2-page button#ob2-submit {
      height: 52px !important; padding: 0 20px !important;
      border-radius: 10px !important;
      font-size: 15px !important; font-weight: 600 !important;
      transition: background-color .15s ease, transform .1s ease;
    }
    #lu-ob2-page button#ob2-submit:hover { background:var(--ob-p-deep) !important; transform: translateY(-1px); }
    #lu-ob2-page button#ob2-submit:disabled { opacity:.6; cursor:not-allowed; transform:none; }

    /* Mobile — single-col goal cards under 480px, reduce padding under 640px */
    @media (max-width: 640px) {
      #lu-ob2-page > div > div[style*="background:#0C0D15"] { padding: 24px !important; }
      #lu-ob2-page [style*="grid-template-columns:repeat(2,1fr)"] { grid-template-columns: 1fr !important; }
      #lu-ob2-page [style*="display:flex;gap:12px"] { flex-direction:column !important; }
    }
  </style>`;

  // Wire industry select change back to state
  var indSel = document.getElementById('ob2-industry');
  if (indSel) indSel.addEventListener('change', function(){ _ob2.industry = this.value; });
  // Wire country select change back to state
  var ctrySel = document.getElementById('ob2-country');
  if (ctrySel) ctrySel.addEventListener('change', function(){ _ob2.country = this.value; });
}

function _ob2FilterIndustry() {
  var q = (document.getElementById('ob2-ind-search')||{}).value.toLowerCase();
  var sel = document.getElementById('ob2-industry');
  if (!sel) return;
  var matches = _OB2_INDUSTRIES.filter(function(v){ return v.toLowerCase().indexOf(q) !== -1; });
  var html = '<option value="">Select your industry…</option>' + matches.map(function(v){
    return '<option value="'+_ob2Esc(v)+'"'+(_ob2.industry===v?' selected':'')+'>'+_ob2Esc(v)+'</option>';
  }).join('');
  sel.innerHTML = html;
}

function _ob2SelectGoal(g) { _ob2.primary_goal = g; _renderOnboardingStep2(_ob2); }
function _ob2SelectCustomer(c) { _ob2.customer_type = c; _renderOnboardingStep2(_ob2); }
function _ob2SelectEmp(e) { _ob2.employees = e; _renderOnboardingStep2(_ob2); }
function _ob2SelectBud(b) { _ob2.budget = b; _renderOnboardingStep2(_ob2); }
function _ob2SelectColor(c) {
  _ob2.brand_color = c;
  var picker = document.getElementById('ob2-color-picker');
  var hex = document.getElementById('ob2-color-hex');
  if (picker) picker.value = c;
  if (hex) hex.value = c;
  _renderOnboardingStep2(_ob2);
}
function _ob2ColorFromPicker() {
  var v = (document.getElementById('ob2-color-picker')||{}).value;
  if (!v) return;
  _ob2.brand_color = v;
  var hex = document.getElementById('ob2-color-hex');
  if (hex) hex.value = v;
}
function _ob2ColorFromHex() {
  var v = (document.getElementById('ob2-color-hex')||{}).value;
  if (/^#[0-9A-Fa-f]{6}$/.test(v)) {
    _ob2.brand_color = v;
    var picker = document.getElementById('ob2-color-picker');
    if (picker) picker.value = v;
  }
}
function _ob2RemoveLogo() {
  _ob2.logo_url = '';
  _renderOnboardingStep2(_ob2);
}

async function _ob2UploadLogo(inp) {
  var file = inp && inp.files ? inp.files[0] : null;
  var status = document.getElementById('ob2-logo-status');
  if (!file) return;
  if (file.size > 5 * 1024 * 1024) {
    if (status) { status.textContent = 'File too large (max 5MB)'; status.style.color = '#F87171'; }
    return;
  }
  if (status) { status.textContent = 'Uploading…'; status.style.color = 'rgba(240,240,248,.72)'; }
  try {
    var fd = new FormData();
    fd.append('file', file);
    fd.append('purpose', 'brand_logo');
    var r = await fetch(_luBase + '/api/media/upload', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token')||''), 'Accept': 'application/json' },
      body: fd,
    });
    var d = await r.json().catch(function(){ return {}; });
    if (!r.ok) throw new Error(d.message || 'Upload failed');
    var url = d.url || d.file_url || (d.media && d.media.url) || (d.data && d.data.url);
    if (!url) throw new Error('No URL returned from upload');
    _ob2.logo_url = url;
    if (status) { status.textContent = 'Uploaded'; status.style.color = '#00E5A8'; }
    _renderOnboardingStep2(_ob2);
  } catch(e) {
    if (status) { status.textContent = 'Upload failed: ' + (e.message || 'error'); status.style.color = '#F87171'; }
  }
}

async function _ob2Submit() {
  // Capture live inputs back into state
  _ob2.business_name = (document.getElementById('ob2-name')||{}).value.trim();
  _ob2.industry      = (document.getElementById('ob2-industry')||{}).value;
  _ob2.city          = (document.getElementById('ob2-city')||{}).value.trim();
  _ob2.country       = (document.getElementById('ob2-country')||{}).value;
  _ob2.website       = (document.getElementById('ob2-website')||{}).value.trim();

  var err = document.getElementById('ob2-err');
  var missing = [];
  if (!_ob2.business_name) missing.push('Business name');
  if (!_ob2.industry)      missing.push('Industry');
  if (!_ob2.city)          missing.push('City');
  if (!_ob2.country)       missing.push('Country');
  if (!_ob2.primary_goal)  missing.push('Main goal');
  if (!_ob2.customer_type) missing.push('Customer type');
  if (missing.length) {
    if (err) { err.style.display = 'block'; err.textContent = 'Please fill in: ' + missing.join(', ') + '.'; }
    return;
  }
  if (err) err.style.display = 'none';

  var btn = document.getElementById('ob2-submit');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
  try {
    var r = await fetch(_luBase + '/api/onboarding/business-info', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': 'Bearer ' + (localStorage.getItem('lu_token')||''),
      },
      body: JSON.stringify({
        business_name: _ob2.business_name,
        industry:      _ob2.industry,
        city:          _ob2.city,
        country:       _ob2.country,
        website:       _ob2.website || null,
        primary_goal:  _ob2.primary_goal,
        customer_type: _ob2.customer_type,
        employees:     _ob2.employees || null,
        budget:        _ob2.budget || null,
        brand_color:   _ob2.brand_color || null,
        logo_url:      _ob2.logo_url || null,
      }),
    });
    var d = await r.json().catch(function(){ return {}; });
    if (!r.ok || !d.success) {
      var msg = d.message || d.error || (d.errors ? Object.values(d.errors)[0][0] : 'Save failed.');
      if (err) { err.style.display = 'block'; err.textContent = msg; }
      throw new Error(msg);
    }
    _showOnboardingStep3();
  } catch(e) {
    if (btn) { btn.disabled = false; btn.textContent = "Let's go →"; }
  }
}

// ── Onboarding Step 3 — Arthur Wizard prefill + complete (runbook 2026-04-25)
// Launches the existing Arthur wizard with Step 2 data pre-seeded. When the
// wizard emits `lu:website-generated`, fires POST /api/onboarding/complete
// and enters the dashboard. No duplicate generation system — reuses the full
// `/api/builder/arthur/message` pipeline.

// Step 2 industry labels → template slugs on disk (26 templates).
// Any label not found here falls back to `consulting`.
var _OB_INDUSTRY_SLUG = {
  'Architecture':'architecture','Automotive':'automotive','Beauty & Wellness':'beauty',
  'Cafe & Coffee':'cafe','Childcare':'childcare','Cleaning Services':'cleaning',
  'Consulting':'consulting','Construction':'construction','Education':'education',
  'Events':'events','Fashion & Retail':'fashion','Finance':'finance',
  'Fitness & Gym':'fitness','Healthcare':'healthcare','Hospitality & Hotel':'hospitality',
  'Interior Design':'interior_design','Legal Services':'legal','Logistics':'logistics',
  'Marketing Agency':'marketing_agency','Pet Services':'pet_services','Photography':'photography',
  'Real Estate':'real_estate','Real Estate Broker':'real_estate_broker','Restaurant':'restaurant',
  'Technology & SaaS':'technology','Travel & Tourism':'hospitality','Wellness':'wellness'
};

async function _showOnboardingStep3() {
  // 1. Canonical prefill from server (handles refresh/resume cases)
  var ws = {};
  try {
    var sr = await fetch(_luBase + '/api/onboarding/status', {
      headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token')||''), 'Accept': 'application/json' },
      cache: 'no-store',
    });
    if (sr.ok) { var s = await sr.json(); ws = s.workspace_data || {}; }
  } catch(_) {}

  // 2. Merge with in-memory _ob2 (current-session fallback)
  var od = ws.onboarding_data || {};
  var businessName = ws.business_name || od.business_name || _ob2.business_name || 'My Business';
  var rawIndustry  = ws.industry || od.industry || _ob2.industry || '';
  var industrySlug = _OB_INDUSTRY_SLUG[rawIndustry] || 'consulting';
  var city         = od.city || _ob2.city || '';
  var country      = od.country || _ob2.country || '';
  var location     = (ws.location || '').trim() || (city + (country ? ', ' + country : ''));
  var brandColor   = ws.brand_color || _ob2.brand_color || null;
  var logoUrl      = ws.logo_url || _ob2.logo_url || null;
  var primaryGoal  = od.primary_goal || _ob2.primary_goal || '';
  var customerType = od.customer_type || _ob2.customer_type || '';

  // 3. User first-name (best-effort)
  var userName = localStorage.getItem('lu_user_name') || '';
  if (!userName) {
    try {
      var mr = await fetch(_luBase + '/api/auth/me', {
        headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token')||''), 'Accept': 'application/json' },
        cache: 'no-store',
      });
      if (mr.ok) {
        var me = await mr.json();
        userName = (me && me.user && me.user.name) || '';
        if (userName) localStorage.setItem('lu_user_name', userName);
      }
    } catch(_) {}
  }

  // 4. Derive services seed + target_market from Step 2 goal/customer
  var goalLabel = {
    leads:   'Lead generation',
    social:  'Social media marketing',
    seo:     'Search engine optimisation',
    email:   'Email marketing',
    website: 'Website launch',
    agency:  'Full-service marketing',
  };
  var servicesSeed = [];
  if (primaryGoal && goalLabel[primaryGoal]) servicesSeed.push(goalLabel[primaryGoal]);

  var targetMarket = '';
  if      (customerType === 'local')         targetMarket = 'Local customers in ' + (city || 'our city');
  else if (customerType === 'national')      targetMarket = 'Customers across ' + (country || 'the country');
  else if (customerType === 'international') targetMarket = 'International customers';
  else if (customerType === 'b2b')           targetMarket = 'Other businesses (B2B)';

  // 5. Listen for Arthur's website-generated event (once)
  if (!window._obStep3Listening) {
    window._obStep3Listening = true;
    window.addEventListener('lu:website-generated', async function _onGen(ev) {
      try {
        await fetch(_luBase + '/api/onboarding/complete', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + (localStorage.getItem('lu_token')||''),
          },
        });
      } catch(_) { /* non-blocking — workspace is still usable */ }
      localStorage.setItem('lu_onboarded', '1');
      // Close Arthur modal + hide onboarding wrapper
      var m = document.getElementById('arthur-modal');
      if (m) m.remove();
      if (typeof _appEnterDashboard === 'function') _appEnterDashboard();
      if (typeof showToast === 'function') {
        showToast('Welcome to LevelUp Growth! 🚀 Sarah is preparing your first strategy — check back soon.', 'success');
      }
      setTimeout(function(){ if (typeof nav === 'function') nav('command'); }, 300);
    }, { once: true });
  }

  // 6. Open Arthur wizard with full prefill
  if (typeof window.wsShowArthurWizard !== 'function') {
    if (typeof showToast === 'function') {
      showToast('Website builder failed to load. Please refresh the page.', 'error');
    }
    return;
  }
  window.wsShowArthurWizard({
    industry:      industrySlug,
    business_name: businessName,
    location:      location,
    services:      servicesSeed,
    target_market: targetMarket,
    brand_color:   brandColor,
    logo_url:      logoUrl,
    user_name:     userName,
  });

  // 7. Auto-send synthetic build trigger after greeting renders (800ms)
  setTimeout(function() {
    try {
      if (window._arthur && window._arthur.onboardingMode) {
        var inp = document.getElementById('arthur-chat-input');
        if (inp) {
          inp.value = 'Build my website now';
          if (typeof window._arthurSend === 'function') window._arthurSend();
        }
      }
    } catch(_) {}
  }, 800);
}
