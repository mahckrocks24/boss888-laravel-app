/**
 * LevelUpGrowth — API Client
 * Single source of truth for all API calls.
 * Base is configurable via window.LU_API_BASE (set in app.html before this loads).
 * Default: /api
 */
'use strict';

// Configurable API base — override window.LU_API_BASE before this script loads
if (typeof window.LU_API_BASE === 'undefined') {
    window.LU_API_BASE = '/api';
}
const API_BASE = window.LU_API_BASE;

// ── Token storage ──────────────────────────────────────────
const Auth = {
    getToken:   ()  => localStorage.getItem('lu_token'),
    setToken:   (t) => localStorage.setItem('lu_token', t),
    getUserId:  ()  => localStorage.getItem('lu_user_id'),
    setUserId:  (id)=> localStorage.setItem('lu_user_id', String(id)),
    clear:      ()  => { localStorage.removeItem('lu_token'); localStorage.removeItem('lu_user_id'); },
    isLoggedIn: ()  => !!localStorage.getItem('lu_token'),
};

// ── Core fetch wrapper ─────────────────────────────────────
async function apiFetch(method, path, body = null, opts = {}) {
    const token = Auth.getToken();
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = 'Bearer ' + token;

    const config = {
        method,
        headers,
        cache: 'no-store',
    };
    if (body) config.body = JSON.stringify(body);

    const res = await fetch(API_BASE + path, config);

    // Token expired — clear and redirect to login
    if (res.status === 401 && !opts.skipAuthRedirect) {
        Auth.clear();
        Router.go('login');
        throw new Error('Session expired');
    }

    const data = await res.json().catch(() => ({}));

    if (!res.ok && !opts.allowErrors) {
        throw new Error(data.message || data.error || `HTTP ${res.status}`);
    }

    return { status: res.status, ok: res.ok, data };
}

// ── Auth endpoints ─────────────────────────────────────────
const AuthAPI = {
    login: (email, password) =>
        apiFetch('POST', '/auth/token', { username: email, password }, { allowErrors: true }),

    register: (name, email, password) =>
        apiFetch('POST', '/register', { name, email, password }, { allowErrors: true }),

    refresh: () =>
        apiFetch('POST', '/auth/refresh', null, { skipAuthRedirect: true }),

    logout: () =>
        apiFetch('POST', '/auth/logout'),
};

// ── Workspace ──────────────────────────────────────────────
const WorkspaceAPI = {
    status:  () => apiFetch('GET', '/workspace/status'),
    billing: () => apiFetch('GET', '/billing/status'),
    settings: (data) => apiFetch('POST', '/workspace/settings', data),
};

// ── Websites / Wizard ─────────────────────────────────────
const WebsiteAPI = {
    create: (data) => apiFetch('POST', '/websites/create', data),
    get:    (id)   => apiFetch('GET', `/websites/${id}`),
    list:   ()     => apiFetch('GET', '/websites'),
};

// ── Tasks ─────────────────────────────────────────────────
const TasksAPI = {
    list:   (params = '') => apiFetch('GET', '/tasks' + params),
    get:    (id)          => apiFetch('GET', `/tasks/${id}`),
    cancel: (id)          => apiFetch('POST', `/tasks/${id}/cancel`),
    retry:  (id)          => apiFetch('POST', `/tasks/${id}/retry`),
};

// ── Notifications ─────────────────────────────────────────
const NotifAPI = {
    list:    (unread = false) => apiFetch('GET', `/notifications${unread ? '?unread=true&limit=10' : '?limit=20'}`),
    markRead: (id)            => apiFetch('POST', `/notifications/${id}/read`),
};

// ── Analytics / Insights ──────────────────────────────────
const AnalyticsAPI = {
    summary:  () => apiFetch('GET', '/insights/summary'),
    overview: (days = 30) => apiFetch('GET', `/analytics/overview?period=${days}`),
};

// ── Agents ────────────────────────────────────────────────
const AgentsAPI = {
    list:     () => apiFetch('GET', '/agents'),
    dispatch: (data) => apiFetch('POST', '/agent/dispatch', data),
};

// ── Credits ───────────────────────────────────────────────
const CreditsAPI = {
    balance:      () => apiFetch('GET', '/workspace/credits'),
    transactions: () => apiFetch('GET', '/workspace/credits/transactions?limit=20'),
};

// ── Export ────────────────────────────────────────────────
window.API    = { Auth, AuthAPI, WorkspaceAPI, WebsiteAPI, TasksAPI, NotifAPI, AnalyticsAPI, AgentsAPI, CreditsAPI };
window.apiFetch = apiFetch;
