/**
 * LevelUpGrowth — App Bootstrap + Router
 * Manages auth state, routing, and shell rendering.
 */
'use strict';

// ── Utilities ──────────────────────────────────────────────
function _esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function _sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function _showToast(msg, type = 'info') {
    const t = document.createElement('div');
    t.className = 'toast toast-' + type;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.classList.add('toast-show'), 10);
    setTimeout(() => { t.classList.remove('toast-show'); setTimeout(() => t.remove(), 350); }, 3500);
}
window._esc = _esc;
window._sleep = _sleep;
window._showToast = _showToast;

// ── Router ─────────────────────────────────────────────────
const Router = {
    _routes: {},
    _current: null,

    register(path, fn) { this._routes[path] = fn; },

    go(path, param = null) {
        const hash = param ? `#${path}/${param}` : `#${path}`;
        window.location.hash = hash;
    },

    handle() {
        const hash  = window.location.hash.replace('#', '') || 'dashboard';
        const parts = hash.split('/');
        const path  = parts[0];
        const param = parts[1] || null;

        if (this._routes[path]) {
            this._current = path;
            // Update sidebar active state
            document.querySelectorAll('.sidebar-link').forEach(el => {
                el.classList.toggle('active', el.dataset.route === path);
            });
            const view = document.getElementById('app-view');
            if (view) this._routes[path](view, param);
        }
    },
};
window.Router = Router;

// ── App Shell ──────────────────────────────────────────────
const App = {

    async boot() {
        // Check token
        const token = API.Auth.getToken();
        if (!token) { this.showAuth('login'); return; }

        // Attempt token refresh
        try {
            const { data } = await API.AuthAPI.refresh();
            if (data.token) API.Auth.setToken(data.token);
        } catch (_) {
            API.Auth.clear();
            this.showAuth('login');
            return;
        }

        // Check onboarding
        if (localStorage.getItem('lu_onboarded') !== '1') {
            try {
                const { data: ws } = await API.WorkspaceAPI.status();
                if (ws.industry && ws.website_count > 0) {
                    localStorage.setItem('lu_onboarded', '1');
                } else {
                    this.showOnboarding();
                    return;
                }
            } catch (_) {
                this.showOnboarding();
                return;
            }
        }

        this.showDashboard();
    },

    afterAuth: async function() {
        // After successful login/signup — decide where to go
        const onboarded = localStorage.getItem('lu_onboarded') === '1';
        if (onboarded) {
            App.showDashboard();
        } else {
            // Quick check
            try {
                const { data: ws } = await API.WorkspaceAPI.status();
                if (ws.industry && ws.website_count > 0) {
                    localStorage.setItem('lu_onboarded', '1');
                    App.showDashboard();
                } else {
                    App.showOnboarding();
                }
            } catch (_) {
                App.showOnboarding();
            }
        }
    },

    showAuth(view) {
        document.getElementById('app-shell').style.display  = 'none';
        document.getElementById('auth-shell').style.display = 'flex';
        const container = document.getElementById('auth-view');
        if (view === 'signup') {
            AuthViews.renderSignup(container);
        } else {
            AuthViews.renderLogin(container);
        }
    },

    showOnboarding() {
        document.getElementById('app-shell').style.display  = 'none';
        document.getElementById('auth-shell').style.display = 'flex';
        const container = document.getElementById('auth-view');
        Onboarding.render(container);
    },

    showDashboard() {
        document.getElementById('auth-shell').style.display = 'none';
        document.getElementById('app-shell').style.display  = 'flex';
        Notifications.init();
        App._checkTrialStatus();

        // Setup routing
        Router.register('dashboard', (c) => Dashboard.render(c));
        Router.register('tasks',     (c, p) => Tasks.render(c, p));
        Router.register('analytics', (c) => Analytics.render(c));
        Router.register('engines',   (c) => Engines.renderHub(c));
        Router.register('strategy',  (c) => Engines.renderStrategyRoom(c));
        // Engine-specific views
        Router.register('builder',   (c) => Engines.renderEnginePlaceholder(c, 'Website Builder', '🏗️', '#3B82F6', 'Generate, edit, and publish AI-built websites for your business.'));
        Router.register('seo',       (c) => Engines.renderEnginePlaceholder(c, 'SEO Engine', '📈', '#7C3AED', 'Keyword research, content optimisation, and technical SEO powered by AI.'));
        Router.register('write',     (c) => Engines.renderEnginePlaceholder(c, 'Write Engine', '✍️', '#A78BFA', 'AI-generated blog articles, landing pages, and marketing copy.'));
        Router.register('creative',  (c) => Engines.renderEnginePlaceholder(c, 'Creative Engine', '🎨', '#F87171', 'AI image and video generation for campaigns and social media.'));
        Router.register('crm',       (c) => Engines.renderEnginePlaceholder(c, 'CRM', '🤝', '#00E5A8', 'Lead management, pipeline tracking, and contact organisation.'));
        Router.register('marketing', (c) => Engines.renderEnginePlaceholder(c, 'Marketing Engine', '📣', '#F59E0B', 'Email campaigns, sequences, and marketing automation.'));
        Router.register('social',    (c) => Engines.renderEnginePlaceholder(c, 'Social Media', '📱', '#F59E0B', 'AI content creation and scheduling across all social platforms.'));
        Router.register('calendar',  (c) => Engines.renderEnginePlaceholder(c, 'Calendar', '📅', '#3B82F6', 'Content calendar and marketing schedule management.'));
        Router.register('login',     () => App.showAuth('login'));
        Router.register('signup',    () => App.showAuth('signup'));
        Router.register('logout',    () => App.logout());

        window.addEventListener('hashchange', () => Router.handle());

        // Route to current hash or dashboard
        Router.handle();
    },

    logout() {
        API.Auth.clear();
        localStorage.removeItem('lu_onboarded');
        Notifications.stop();
        // Redirect to website home (base path)
        const base = window.LU_BASE_PATH || '/';
        window.location.href = base + '/';
    },

    async _checkTrialStatus() {
        if (sessionStorage.getItem('lu_trial_warned')) return;
        try {
            const { data } = await API.WorkspaceAPI.billing();
            const pct = data.monthly_credit_limit > 0 ? (data.credit_balance / data.monthly_credit_limit) * 100 : 100;
            if (pct < 20 && data.credit_balance < 15) {
                sessionStorage.setItem('lu_trial_warned', '1');
                _showToast(`${Math.round(data.credit_balance)} trial credits remaining.`, 'info');
            }
        } catch (_) {}
    },
};

window.App = App;

// ── Init ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => App.boot());
