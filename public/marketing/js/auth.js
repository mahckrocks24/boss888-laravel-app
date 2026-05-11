/**
 * LevelUpGrowth — Auth Views
 * Login + Signup, wired to real API endpoints.
 */
'use strict';

const AuthViews = {

    renderLogin(container) {
        container.innerHTML = `
        <div class="auth-wrap">
          <div class="auth-card">
            <div class="auth-logo"><div class="nav-logo-icon"><img src="/img/logo-icon-40.png" alt=""></div><span>LevelUpGrowth</span></div>
            <h2 class="auth-title">Welcome back</h2>
            <p class="auth-sub">Sign in to your AI marketing workspace.</p>

            <div class="form-group">
              <label class="form-label">Email</label>
              <input class="form-input" id="au-email" type="email" placeholder="you@company.com" autocomplete="email">
            </div>
            <div class="form-group" style="margin-bottom:22px">
              <label class="form-label">Password</label>
              <input class="form-input" id="au-pwd" type="password" autocomplete="current-password">
            </div>

            <button class="btn btn-primary" style="width:100%;justify-content:center;padding:14px" id="au-btn" onclick="AuthViews.doLogin()">
              Sign In →
            </button>
            <div id="au-err" class="auth-err"></div>

            <div class="auth-switch">
              No account? <a href="#" onclick="if(['levelupgrowth.io','www.levelupgrowth.io'].indexOf(location.hostname)!==-1){return false}Router.go('signup');return false">Create one free →</a>
            </div>
            <div class="auth-switch" style="margin-top:6px;font-size:11.5px">
              <a href="/" style="color:var(--faint)">← Back to website</a>
            </div>
          </div>
        </div>`;
        // Allow Enter key
        setTimeout(() => {
            const pwd = document.getElementById('au-pwd');
            if (pwd) pwd.addEventListener('keydown', e => { if (e.key === 'Enter') AuthViews.doLogin(); });
            document.getElementById('au-email')?.focus();
        }, 50);
    },

    async doLogin() {
        const email = document.getElementById('au-email')?.value?.trim();
        const pwd   = document.getElementById('au-pwd')?.value;
        const btn   = document.getElementById('au-btn');
        const err   = document.getElementById('au-err');
        if (!email || !pwd) { AuthViews._err('Enter your email and password.'); return; }
        btn.disabled = true; btn.textContent = 'Signing in…';
        err.style.display = 'none';
        try {
            const { data } = await API.AuthAPI.login(email, pwd);
            if (!data.token) throw new Error(data.message || data.error || 'Login failed');
            API.Auth.setToken(data.token);
            if (data.user_id) API.Auth.setUserId(data.user_id);
            await App.afterAuth();
        } catch (e) {
            AuthViews._err(e.message);
            btn.disabled = false; btn.textContent = 'Sign In →';
        }
    },

    renderSignup(container) {
        // 2026-05-11: signups blocked on production hosts only — staging
        // (and other hosts) fall through to the real signup form below.
        if (['levelupgrowth.io', 'www.levelupgrowth.io'].indexOf(location.hostname) !== -1) {
            container.innerHTML = `
            <div class="auth-wrap">
              <div class="auth-card">
                <div class="auth-logo"><div class="nav-logo-icon"><img src="/img/logo-icon-40.png" alt=""></div><span>LevelUpGrowth</span></div>
                <h2 class="auth-title">Signups are temporarily disabled</h2>
                <p class="auth-sub">We are not accepting new accounts at the moment. Existing customers can sign in below.</p>
                <div class="auth-switch" style="margin-top:18px">
                  <a href="#" onclick="Router.go('login');return false">Go to sign in →</a>
                </div>
                <div class="auth-switch" style="margin-top:6px;font-size:11.5px">
                  <a href="/" style="color:var(--faint)">← Back to website</a>
                </div>
              </div>
            </div>`;
            return;
        }
        container.innerHTML = `
        <div class="auth-wrap">
          <div class="auth-card">
            <div class="auth-logo"><div class="nav-logo-icon"><img src="/img/logo-icon-40.png" alt=""></div><span>LevelUpGrowth</span></div>
            <h2 class="auth-title">Create your account</h2>
            ${planBadge}
            <p class="auth-sub">Free plan available · Build your website · AI trial unlocks after first website · No credit card required.</p>

            <div class="form-group">
              <label class="form-label">Your name</label>
              <input class="form-input" id="su-name" type="text" placeholder="Alex Johnson" autocomplete="name">
            </div>
            <div class="form-group">
              <label class="form-label">Work email</label>
              <input class="form-input" id="su-email" type="email" placeholder="alex@company.com" autocomplete="email">
            </div>
            <div class="form-group" style="margin-bottom:22px">
              <label class="form-label">Password <span style="font-size:10.5px;color:var(--faint);font-weight:400">min 8 characters</span></label>
              <input class="form-input" id="su-pwd" type="password" autocomplete="new-password">
            </div>

            <button class="btn btn-primary" style="width:100%;justify-content:center;padding:14px" id="su-btn" onclick="AuthViews.doSignup()">
              Create Account →
            </button>
            <div id="su-err" class="auth-err"></div>

            <div class="auth-switch">
              Already have an account? <a href="#" onclick="Router.go('login');return false">Sign in →</a>
            </div>
          </div>
        </div>`;
        setTimeout(() => document.getElementById('su-name')?.focus(), 50);
    },

    async doSignup() {
        // 2026-05-11: signups blocked on production hosts only.
        if (['levelupgrowth.io', 'www.levelupgrowth.io'].indexOf(location.hostname) !== -1) {
            AuthViews._err('Signups are temporarily disabled.', 'su');
            return;
        }
        const name  = document.getElementById('su-name')?.value?.trim();
        const email = document.getElementById('su-email')?.value?.trim();
        const pwd   = document.getElementById('su-pwd')?.value;
        const btn   = document.getElementById('su-btn');
        if (!email || !pwd) { AuthViews._err('Email and password are required.', 'su'); return; }
        if (pwd.length < 8) { AuthViews._err('Password must be at least 8 characters.', 'su'); return; }
        btn.disabled = true; btn.textContent = 'Creating account…';
        try {
            const { data } = await API.AuthAPI.register(name, email, pwd);
            if (!data.token) throw new Error(data.message || data.error || 'Signup failed');
            API.Auth.setToken(data.token);
            if (data.user_id) API.Auth.setUserId(data.user_id);
            Router.go('onboarding');
        } catch (e) {
            AuthViews._err(e.message, 'su');
            btn.disabled = false; btn.textContent = 'Create Account →';
        }
    },

    _err(msg, prefix = 'au') {
        const el = document.getElementById(prefix + '-err');
        if (el) { el.textContent = msg; el.style.display = 'block'; }
    },
};

window.AuthViews = AuthViews;
