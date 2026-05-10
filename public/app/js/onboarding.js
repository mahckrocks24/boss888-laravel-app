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
          <img src="/img/logo-icon-40.png" class="ob-logo-img" alt="">
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
          <img src="/img/logo-icon-40.png" class="ob-logo-img" alt="">
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

// ── Quiz state (Phase O3) ────────────────────────────────────────────────
var _ob2QuizStep = 1;

var _OB2_INDUSTRY_ICONS = {
  'Architecture':       '\u{1F3DB}️',
  'Automotive':         '\u{1F697}',
  'Beauty & Wellness':  '\u{1F486}',
  'Cafe & Coffee':      '☕',
  'Childcare':          '\u{1F476}',
  'Cleaning Services':  '\u{1F9F9}',
  'Consulting':         '\u{1F4BC}',
  'Construction':       '\u{1F6A7}',
  'Education':          '\u{1F393}',
  'Events':             '\u{1F389}',
  'Fashion & Retail':   '\u{1F457}',
  'Finance':            '\u{1F4B0}',
  'Fitness & Gym':      '\u{1F4AA}',
  'Healthcare':         '\u{1F3E5}',
  'Hospitality & Hotel':'\u{1F3E8}',
  'Interior Design':    '\u{1F6CB}️',
  'Legal Services':     '⚖️',
  'Logistics':          '\u{1F4E6}',
  'Marketing Agency':   '\u{1F4E2}',
  'Pet Services':       '\u{1F43E}',
  'Photography':        '\u{1F4F8}',
  'Real Estate':        '\u{1F3D8}️',
  'Real Estate Broker': '\u{1F511}',
  'Restaurant':         '\u{1F37D}️',
  'Technology & SaaS':  '\u{1F4BB}',
  'Travel & Tourism':   '✈️',
  'Wellness':           '\u{1F9D8}'
};

var _OB2_CUSTOMER_ICONS = {
  local:         '\u{1F3D9}️',
  national:      '\u{1F5FA}️',
  international: '\u{1F30D}',
  b2b:           '\u{1F91D}'
};

var _OB2_AED_COUNTRIES = ['AE','SA','QA','KW'];
var _OB2_BUDGETS_USD = ['Under $500','$500-2K','$2K-5K','$5K+'];
var _OB2_BUDGETS_AED = ['Under AED 2K','AED 2-8K','AED 8-20K','AED 20K+'];

function _ob2GetBudgets() {
  return _OB2_AED_COUNTRIES.indexOf(_ob2.country) !== -1
    ? _OB2_BUDGETS_AED
    : _OB2_BUDGETS_USD;
}

// ── Entry point: called from _doSignup({}) and on resume ─────────────────
function _renderOnboardingStep2(prefill) {
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
  // Fresh entry (not internal re-render): reset to step 1
  if (prefill !== _ob2) _ob2QuizStep = 1;
  _ob2RenderCurrentQuiz();
}

// ── Internal re-render — preserves _ob2QuizStep ──────────────────────────
function _ob2RenderCurrentQuiz() {
  var root = document.getElementById('lu-auth-root');
  if (!root) return;
  root.style.display = 'flex';
  var appShell = document.querySelector('.app');
  if (appShell) appShell.style.display = 'none';

  var stepBody = '';
  if (_ob2QuizStep === 1) stepBody = _renderQuizStep1();
  else if (_ob2QuizStep === 2) stepBody = _renderQuizStep2();
  else if (_ob2QuizStep === 3) stepBody = _renderQuizStep3();
  else if (_ob2QuizStep === 4) stepBody = _renderQuizStep4();
  else if (_ob2QuizStep === 5) stepBody = _renderQuizStep5();

  var segs = [1,2,3,4,5].map(function(n){
    var cls = (n < _ob2QuizStep ? 'done' : (n === _ob2QuizStep ? 'active' : ''));
    return '<div class="ob-quiz-seg ' + cls + '"></div>';
  }).join('');

  root.innerHTML =
    '<div class="ob-quiz-wrap">' +
      '<div class="ob-quiz-inner">' +
        '<div class="ob-quiz-progress">' + segs + '</div>' +
        '<div id="ob-quiz-content" class="ob-quiz-enter">' + stepBody + '</div>' +
      '</div>' +
    '</div>';
}

// ── Animation-orchestrated transition ────────────────────────────────────
function _ob2QuizGoTo(n) {
  if (n < 1 || n > 5) return;
  var content = document.getElementById('ob-quiz-content');
  if (content) {
    content.classList.remove('ob-quiz-enter');
    content.classList.add('ob-quiz-exit');
    setTimeout(function() {
      _ob2QuizStep = n;
      _ob2RenderCurrentQuiz();
    }, 200);
  } else {
    _ob2QuizStep = n;
    _ob2RenderCurrentQuiz();
  }
}

// ── Capture inputs from current step into _ob2 ───────────────────────────
function _ob2CaptureStep() {
  if (_ob2QuizStep === 1) {
    var nameEl = document.getElementById('ob2-name');
    if (nameEl) _ob2.business_name = nameEl.value.trim();
  } else if (_ob2QuizStep === 2) {
    var cityEl = document.getElementById('ob2-city');
    var ctryEl = document.getElementById('ob2-country');
    var webEl  = document.getElementById('ob2-website');
    if (cityEl) _ob2.city    = cityEl.value.trim();
    if (ctryEl) _ob2.country = ctryEl.value;
    if (webEl)  _ob2.website = webEl.value.trim();
  }
  // Steps 3,4: select handlers already updated state.
  // Step 5: brand_color via onchange/oninput; logo_url via upload.
}

function _ob2QuizCanAdvance() {
  if (_ob2QuizStep === 1) return !!(_ob2.business_name && _ob2.industry);
  if (_ob2QuizStep === 2) return !!(_ob2.city && _ob2.country);
  if (_ob2QuizStep === 3) return !!_ob2.primary_goal;
  if (_ob2QuizStep === 4) return !!(_ob2.customer_type && _ob2.employees);
  if (_ob2QuizStep === 5) return true;
  return false;
}

function _ob2QuizNext() {
  _ob2CaptureStep();
  if (!_ob2QuizCanAdvance()) return;
  if (_ob2QuizStep === 5) { _ob2Submit(); return; }
  _ob2QuizGoTo(_ob2QuizStep + 1);
}

function _ob2QuizBack() {
  _ob2CaptureStep();
  if (_ob2QuizStep === 1) { _renderSignup(); return; }
  _ob2QuizGoTo(_ob2QuizStep - 1);
}

function _ob2UpdateNextBtn() {
  var btn = document.querySelector('.ob-quiz-next');
  if (btn) btn.disabled = !_ob2QuizCanAdvance();
}

// ── Step renderers ───────────────────────────────────────────────────────
function _renderQuizStep1() {
  var indCards = _OB2_INDUSTRIES.map(function(name){
    var icon = _OB2_INDUSTRY_ICONS[name] || '\u{1F3E2}';
    var sel = _ob2.industry === name;
    return '<div class="ob-ind-card' + (sel?' selected':'') + '" data-label="' + _ob2Esc(name) +
      '" onclick="_ob2SelectIndustry(\'' + _ob2Esc(name).replace(/'/g, "\\'") + '\')">' +
      '<div class="ob-ind-emoji">' + icon + '</div>' +
      '<div class="ob-ind-label">' + _ob2Esc(name) + '</div></div>';
  }).join('');

  var canNext = _ob2.business_name && _ob2.industry;
  return (
    '<div class="ob-quiz-step-label">Step 1 of 5</div>' +
    '<h1 class="ob-quiz-question">What\'s your business called?</h1>' +
    '<p class="ob-quiz-sub">We\'ll use this to personalise everything Sarah builds for you.</p>' +
    '<div class="ob-quiz-body">' +
      '<input id="ob2-name" type="text" class="ob-quiz-input" placeholder="e.g. Nour Restaurant" value="' + _ob2Esc(_ob2.business_name) + '" oninput="_ob2.business_name=this.value.trim();_ob2UpdateNextBtn()">' +
      '<div class="ob-quiz-section-label">Pick your industry</div>' +
      '<input id="ob2-ind-search" type="text" class="ob-quiz-input ob-quiz-input--search" placeholder="Search industries…" oninput="_ob2FilterIndustry()">' +
      '<div class="ob-ind-grid">' + indCards + '</div>' +
    '</div>' +
    '<div class="ob-quiz-nav">' +
      '<button class="ob-quiz-back" onclick="_ob2QuizBack()">← Back</button>' +
      '<button class="ob-quiz-next" onclick="_ob2QuizNext()"' + (canNext?'':' disabled') + '>Continue →</button>' +
    '</div>'
  );
}

function _renderQuizStep2() {
  var ctryOptions = _OB2_COUNTRIES.map(function(c){
    return '<option value="' + _ob2Esc(c[0]) + '"' + (_ob2.country===c[0]?' selected':'') + '>' + _ob2Esc(c[1]) + '</option>';
  }).join('');
  var canNext = _ob2.city && _ob2.country;
  return (
    '<div class="ob-quiz-step-label">Step 2 of 5</div>' +
    '<h1 class="ob-quiz-question">Where are you based?</h1>' +
    '<p class="ob-quiz-sub">Sarah will research your local market and competitors.</p>' +
    '<div class="ob-quiz-body">' +
      '<div class="ob-quiz-row">' +
        '<div class="ob-quiz-field">' +
          '<label class="ob-quiz-field-label">City</label>' +
          '<input id="ob2-city" type="text" class="ob-quiz-input" placeholder="Dubai" value="' + _ob2Esc(_ob2.city) + '" oninput="_ob2.city=this.value.trim();_ob2UpdateNextBtn()">' +
        '</div>' +
        '<div class="ob-quiz-field">' +
          '<label class="ob-quiz-field-label">Country</label>' +
          '<select id="ob2-country" class="ob-quiz-input ob-quiz-input--select" onchange="_ob2.country=this.value;_ob2UpdateNextBtn()">' + ctryOptions + '</select>' +
        '</div>' +
      '</div>' +
      '<div class="ob-quiz-field">' +
        '<label class="ob-quiz-field-label ob-quiz-field-label--optional">Business website (optional)</label>' +
        '<input id="ob2-website" type="url" class="ob-quiz-input" placeholder="https://yourbusiness.com" value="' + _ob2Esc(_ob2.website) + '" oninput="_ob2.website=this.value.trim()">' +
      '</div>' +
    '</div>' +
    '<div class="ob-quiz-nav">' +
      '<button class="ob-quiz-back" onclick="_ob2QuizBack()">← Back</button>' +
      '<button class="ob-quiz-next" onclick="_ob2QuizNext()"' + (canNext?'':' disabled') + '>Continue →</button>' +
    '</div>'
  );
}

function _renderQuizStep3() {
  var goalCards = _OB2_GOALS.map(function(g){
    var sel = _ob2.primary_goal === g[0];
    return '<div class="ob-goal-card' + (sel?' selected':'') + '" onclick="_ob2SelectGoal(\'' + g[0] + '\')">' +
      '<div class="ob-goal-emoji">' + g[2] + '</div>' +
      '<div class="ob-goal-label">' + _ob2Esc(g[1]) + '</div></div>';
  }).join('');
  return (
    '<div class="ob-quiz-step-label">Step 3 of 5</div>' +
    '<h1 class="ob-quiz-question">What\'s your #1 marketing goal right now?</h1>' +
    '<p class="ob-quiz-sub">Your whole AI team will focus on this first.</p>' +
    '<div class="ob-quiz-body">' +
      '<div class="ob-goal-grid">' + goalCards + '</div>' +
    '</div>' +
    '<div class="ob-quiz-nav">' +
      '<button class="ob-quiz-back" onclick="_ob2QuizBack()">← Back</button>' +
      '<span style="font-size:13px;color:#6B7280">Tap a card to continue</span>' +
    '</div>'
  );
}

function _renderQuizStep4() {
  var custCards = _OB2_CUSTOMERS.map(function(c){
    var sel = _ob2.customer_type === c[0];
    var icon = _OB2_CUSTOMER_ICONS[c[0]] || '';
    return '<div class="ob-goal-card' + (sel?' selected':'') + '" onclick="_ob2SelectCustomer(\'' + c[0] + '\')">' +
      '<div class="ob-goal-emoji">' + icon + '</div>' +
      '<div class="ob-goal-label">' + _ob2Esc(c[1]) + '</div></div>';
  }).join('');

  var empPills = _OB2_EMPLOYEES.map(function(e){
    var sel = _ob2.employees === e;
    return '<button class="ob-pill' + (sel?' selected':'') + '" onclick="_ob2SelectEmp(\'' + _ob2Esc(e).replace(/'/g, "\\'") + '\')">' + _ob2Esc(e) + '</button>';
  }).join('');

  var budgets = _ob2GetBudgets();
  var budPills = budgets.map(function(b){
    var sel = _ob2.budget === b;
    return '<button class="ob-pill' + (sel?' selected':'') + '" onclick="_ob2SelectBud(\'' + _ob2Esc(b).replace(/'/g, "\\'") + '\')">' + _ob2Esc(b) + '</button>';
  }).join('');

  var canNext = _ob2.customer_type && _ob2.employees;
  return (
    '<div class="ob-quiz-step-label">Step 4 of 5</div>' +
    '<h1 class="ob-quiz-question">Tell us about your business</h1>' +
    '<p class="ob-quiz-sub">Helps Sarah calibrate the right strategy for your stage.</p>' +
    '<div class="ob-quiz-body">' +
      '<div class="ob-quiz-section-label">Who are your customers?</div>' +
      '<div class="ob-goal-grid">' + custCards + '</div>' +
      '<div class="ob-quiz-section-label" style="margin-top:28px">How many employees?</div>' +
      '<div class="ob-pill-row">' + empPills + '</div>' +
      '<div class="ob-quiz-section-label" style="margin-top:28px">Monthly marketing budget <span class="ob-quiz-section-label--optional">(optional)</span></div>' +
      '<div class="ob-pill-row">' + budPills + '</div>' +
    '</div>' +
    '<div class="ob-quiz-nav">' +
      '<button class="ob-quiz-back" onclick="_ob2QuizBack()">← Back</button>' +
      '<button class="ob-quiz-next" onclick="_ob2QuizNext()"' + (canNext?'':' disabled') + '>Continue →</button>' +
    '</div>'
  );
}

function _renderQuizStep5() {
  var swatches = _OB2_COLORS.map(function(c){
    var sel = _ob2.brand_color === c;
    return '<div onclick="_ob2SelectColor(\'' + c + '\')" class="ob-swatch' + (sel?' selected':'') + '" style="background:' + c + '"></div>';
  }).join('');

  var logoPreview = _ob2.logo_url
    ? '<div class="ob-logo-preview"><img src="' + _ob2Esc(_ob2.logo_url) + '" alt="logo"><button type="button" class="ob-logo-remove" onclick="_ob2RemoveLogo()">Remove</button></div>'
    : '';

  return (
    '<div class="ob-quiz-step-label">Step 5 of 5</div>' +
    '<h1 class="ob-quiz-question">Let\'s set up your brand</h1>' +
    '<p class="ob-quiz-sub">Arthur will use these to build your website.</p>' +
    '<div class="ob-quiz-body">' +
      '<div class="ob-quiz-section-label">Brand color</div>' +
      '<div class="ob-swatch-row">' + swatches + '</div>' +
      '<div class="ob-color-row">' +
        '<input id="ob2-color-picker" type="color" value="' + _ob2Esc(_ob2.brand_color) + '" onchange="_ob2ColorFromPicker()">' +
        '<input id="ob2-color-hex" type="text" value="' + _ob2Esc(_ob2.brand_color) + '" oninput="_ob2ColorFromHex()" maxlength="7" class="ob-quiz-input ob-quiz-input--hex">' +
      '</div>' +
      '<div class="ob-quiz-section-label" style="margin-top:28px">Logo <span class="ob-quiz-section-label--optional">(optional)</span></div>' +
      logoPreview +
      '<label for="ob2-logo-file" class="ob-logo-drop">' +
        '<div class="ob-logo-drop-icon">\u{1F4C1}</div>' +
        '<div class="ob-logo-drop-text">Click to upload</div>' +
        '<div class="ob-logo-drop-hint" id="ob2-logo-status">PNG, JPG, SVG — max 5MB</div>' +
      '</label>' +
      '<input id="ob2-logo-file" type="file" accept="image/png,image/jpeg,image/svg+xml" onchange="_ob2UploadLogo(this)" style="display:none">' +
    '</div>' +
    '<div id="ob2-err" class="ob-quiz-err" style="display:none"></div>' +
    '<div class="ob-quiz-nav">' +
      '<button class="ob-quiz-back" onclick="_ob2QuizBack()">← Back</button>' +
      '<button class="ob-quiz-next" onclick="_ob2QuizNext()">Let\'s go →</button>' +
    '</div>' +
    '<p style="text-align:center;font-size:12px;color:#6B7280;margin-top:16px">Sarah will present your strategy within minutes</p>'
  );
}

// ── Helpers + select handlers ────────────────────────────────────────────
function _ob2FilterIndustry() {
  var q = (document.getElementById('ob2-ind-search')||{}).value.toLowerCase();
  document.querySelectorAll('.ob-ind-card').forEach(function(card){
    var label = (card.dataset.label || '').toLowerCase();
    card.style.display = label.indexOf(q) !== -1 ? '' : 'none';
  });
}

function _ob2SelectIndustry(name) {
  _ob2CaptureStep();
  _ob2.industry = name;
  _ob2RenderCurrentQuiz();
}

function _ob2SelectGoal(g) {
  _ob2.primary_goal = g;
  _ob2RenderCurrentQuiz();
  // Auto-advance to step 4 after brief feedback
  setTimeout(function(){ _ob2QuizGoTo(4); }, 300);
}
function _ob2SelectCustomer(c) { _ob2.customer_type = c; _ob2RenderCurrentQuiz(); }
function _ob2SelectEmp(e)      { _ob2.employees = e;      _ob2RenderCurrentQuiz(); }
function _ob2SelectBud(b)      { _ob2.budget = b;         _ob2RenderCurrentQuiz(); }
function _ob2SelectColor(c) {
  _ob2.brand_color = c;
  var picker = document.getElementById('ob2-color-picker');
  var hex = document.getElementById('ob2-color-hex');
  if (picker) picker.value = c;
  if (hex) hex.value = c;
  _ob2RenderCurrentQuiz();
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
  _ob2RenderCurrentQuiz();
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
    _ob2RenderCurrentQuiz();
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
