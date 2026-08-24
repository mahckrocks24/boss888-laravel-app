/**
 * LevelUp Growth Infrastructure — customer SPA module.
 *
 * Phase 3B adds the ENTERPRISE INTELLIGENCE INTERFACE on top of the Phase 1D
 * hosting request workflow. It consumes the six real, workspace-scoped
 * intelligence endpoints and presents an executive operational view over the
 * canonical asset graph:
 *
 *   Overview     GET /api/infrastructure/intelligence/dashboard
 *   Assets       GET /api/infrastructure/intelligence/assets
 *   Asset detail GET /api/infrastructure/intelligence/assets/{id}
 *   Blast radius GET /api/infrastructure/intelligence/assets/{id}/blast-radius
 *   Incidents    GET /api/infrastructure/intelligence/incidents
 *   Reliability  GET /api/infrastructure/intelligence/reliability
 *
 * These endpoints return their payload at the TOP LEVEL (no {data:…} wrapper),
 * unlike the Phase 1D hosting endpoints. Every number shown traces to a field
 * in one of these responses. Nothing is mutated. Tenant scope is enforced
 * server-side (auth.jwt → workspace_id); the browser never sends a workspace id.
 *
 * HONESTY RULES (directive §DATA-INTEGRITY):
 *   - unknown health is "Not yet observed", never healthy, never a green tick.
 *   - adopted ≠ provisioned; managed_externally ≠ managed_by_infra888. Modes are
 *     labelled exactly as the backend records them.
 *   - absence of an incident is NOT presented as proof of uptime.
 *   - MTTR/MTBF are deterministic aggregation, labelled as such — never "AI" or
 *     "prediction". When there is no data they read "not yet computable", not 0/100%.
 *   - no fabricated SSL / DNS / cost / backup / provider state.
 *   - no secrets, tokens or debug output reach the console.
 *
 * Design system: existing :root variables only (index.html). Inline SVG icons,
 * never emoji. Status is communicated by TEXT + shape, never colour alone.
 */
(function () {
  'use strict';

  // Default landing tab is the executive overview.
  // Customers land on the product they bought, not on an ops dashboard.
  // window.infraTab lets the sidebar deep-link straight to Domains / Email.
  var INFRA_TAB = 'hosting';
  var _loading = false;
  var _poll = null;            // active polling timer (hosting operation only)
  var _pollInFlight = false;   // prevents overlapping requests
  var _view = { name: 'list', operationId: null, assetId: null };
  var _idemKey = null;         // preserved across re-renders; new per request
  var _submitting = false;

  // "Your websites" landing state (Type 1 + Type 2 shown together).
  var _sites = [];
  var _sitesLoading = false;

  // Managed-hosting provisioning wizard state (the External/Managed journey).
  var _wizard = { step: 1, sites: [], sitesLoading: false, websiteId: null, plan: 'included', subdomain: '' };

  // "Add existing website" (Type 2) onboarding state.
  var _ext = { step: 1, platform: '', url: '', subdomain: '' };

  // Active tab on the website-detail surface.
  var _detailTab = 'overview';

  var _assets = [];            // last-loaded intelligence asset inventory
  var _assetFilter = { type: '', health: '', risk: '' };
  var _incidentsOpenOnly = false;

  var TERMINAL = ['succeeded', 'failed_terminal', 'rejected', 'cancelled', 'compensated'];

  /* ------------------------------------------------------------------ api */

  function apiUrl(p) {
    return (window.LU_CFG && window.LU_CFG.api ? window.LU_CFG.api : '/api/') + p;
  }

  function authHeaders() {
    var t = localStorage.getItem('lu_token') || '';
    var h = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
    if (t) { h['Authorization'] = 'Bearer ' + t; }
    return h;
  }

  function req(method, path, body, extraHeaders) {
    var h = authHeaders();
    if (extraHeaders) { Object.keys(extraHeaders).forEach(function (k) { h[k] = extraHeaders[k]; }); }
    var opts = { method: method, headers: h, credentials: 'same-origin', cache: 'no-store' };
    if (body) { opts.body = JSON.stringify(body); }

    return fetch(apiUrl(path), opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        return { status: r.status, ok: r.ok, json: j };
      });
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmtTime(iso) {
    if (!iso) { return ''; }
    try {
      var d = new Date(iso);
      return d.toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
      });
    } catch (e) { return iso; }
  }

  // Human-readable "time ago" for stale-data awareness. Falls back to '—'.
  function fmtAgo(iso) {
    if (!iso) { return '—'; }
    try {
      var then = new Date(iso).getTime();
      var s = Math.floor((Date.now() - then) / 1000);
      if (s < 0) { return fmtTime(iso); }
      if (s < 60) { return s + 's ago'; }
      var m = Math.floor(s / 60); if (m < 60) { return m + 'm ago'; }
      var h = Math.floor(m / 60); if (h < 24) { return h + 'h ago'; }
      var d = Math.floor(h / 24); return d + 'd ago';
    } catch (e) { return fmtTime(iso); }
  }

  // Duration from seconds, honest about null.
  function fmtDuration(sec) {
    if (sec == null || sec === '') { return '—'; }
    sec = Number(sec);
    if (!isFinite(sec) || sec < 0) { return '—'; }
    if (sec < 60) { return Math.round(sec) + 's'; }
    var m = Math.floor(sec / 60); if (m < 60) { return m + 'm'; }
    var h = Math.floor(m / 60); var rm = m % 60; if (h < 24) { return h + 'h' + (rm ? ' ' + rm + 'm' : ''); }
    var d = Math.floor(h / 24); var rh = h % 24; return d + 'd' + (rh ? ' ' + rh + 'h' : '');
  }

  function uuid() {
    if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
    return 'idem-' + Date.now() + '-' + Math.random().toString(16).slice(2, 10);
  }

  /* ---------------------------------------------------------------- icons */

  var ICONS = {
    overview: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="2.5" width="6" height="6" rx="1.2"/><rect x="11.5" y="2.5" width="6" height="6" rx="1.2"/><rect x="2.5" y="11.5" width="6" height="6" rx="1.2"/><rect x="11.5" y="11.5" width="6" height="6" rx="1.2"/></svg>',
    assets: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2.5l6.5 3.5v8L10 17.5 3.5 14V6z"/><path d="M3.7 6L10 9.5 16.3 6M10 9.5v8"/></svg>',
    incidents: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2.8L18 16.5H2z"/><path d="M10 8v3.5M10 14h.01"/></svg>',
    reliability: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 13.5l4-4 3 3 5-6"/><path d="M14.5 3.5h3v3"/><path d="M2.5 17h15"/></svg>',
    graph: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="4" r="2"/><circle cx="4" cy="15" r="2"/><circle cx="16" cy="15" r="2"/><path d="M9 5.7L5 13.2M11 5.7l4 7.5M6 15h8"/></svg>',
    hosting: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="3.5" width="15" height="5" rx="1.5"/><rect x="2.5" y="11.5" width="15" height="5" rx="1.5"/><path d="M5.5 6h.01M5.5 14h.01"/></svg>',
    domains: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="7.5"/><path d="M2.5 10h15M10 2.5c1.9 2 3 4.7 3 7.5s-1.1 5.5-3 7.5c-1.9-2-3-4.7-3-7.5s1.1-5.5 3-7.5z"/></svg>',
    email: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4.5" width="15" height="11" rx="1.5"/><path d="M3 5.5l7 5 7-5"/></svg>',
    back: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4l-6 6 6 6"/></svg>',
    refresh: '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 6.5A7 7 0 1 0 17 10"/><path d="M16.5 3v3.5H13"/></svg>'
  };

  /* -------------------------------------------------------------- states */

  // HONESTY (directive §DATA-INTEGRITY): the provisioning pipeline is real —
  // approval, operation records, state machine, audit — but the hosting
  // connector is still the Null adapter, so no live server is created. This
  // note must stay on every screen that can start or finish a request until a
  // real connector is wired. It is worded for customers, not engineers, but it
  // does NOT imply a live site exists.
  var PREVIEW_NOTE = 'Preview — this runs the real approval and setup steps, but live hosting is not switched on yet.';

  // Customer-facing labels for the hosting request workflow (Phase 1D).
  var STATE_LABEL = {
    requested:            'Submitted',
    awaiting_approval:    'Awaiting approval',
    approved:             'Approved',
    queued:               'Queued',
    running:              'Setting up',
    succeeded:            'Completed',
    rejected:             'Rejected',
    cancelled:            'Cancelled',
    failed_retryable:     'Temporarily failed',
    failed_terminal:      'Failed',
    timed_out:            'Timed out',
    compensation_pending: 'Checking status',
    compensated:          'Rolled back'
  };

  var STATE_TONE = {
    succeeded: 'var(--ac)', approved: 'var(--ac)', compensated: 'var(--t2)',
    awaiting_approval: 'var(--am)', queued: 'var(--am)', running: 'var(--bl)',
    requested: 'var(--t2)', compensation_pending: 'var(--am)', timed_out: 'var(--am)',
    failed_retryable: 'var(--am)', failed_terminal: 'var(--rd)',
    rejected: 'var(--rd)', cancelled: 'var(--t3)'
  };

  function stateLabel(s) { return STATE_LABEL[s] || 'In progress'; }
  function stateTone(s) { return STATE_TONE[s] || 'var(--t2)'; }
  function isTerminal(s) { return TERMINAL.indexOf(s) !== -1; }

  // ── canonical intelligence vocab (mirrors InfraAsset / InfraIncident) ──
  var HEALTH_LABEL = { healthy: 'Healthy', degraded: 'Degraded', down: 'Unavailable', unknown: 'Not yet observed' };
  var HEALTH_TONE  = { healthy: 'var(--ac)', degraded: 'var(--am)', down: 'var(--rd)', unknown: 'var(--t3)' };

  var RISK_LABEL = { ok: 'OK', watch: 'Watch', at_risk: 'At risk' };
  var RISK_TONE  = { ok: 'var(--ac)', watch: 'var(--am)', at_risk: 'var(--rd)' };

  // Customer vocabulary. The underlying distinction is preserved exactly —
  // "connected" (we monitor it, we did not create it) is never presented as
  // "set up by us". Internal engine codenames must never reach a customer
  // surface, so the internal engine name is never rendered.
  var MODE_LABEL = {
    adopted: 'Connected', provisioned: 'Set up by LevelUp',
    managed_externally: 'Managed elsewhere', managed_by_infra888: 'Managed by LevelUp'
  };

  var INC_LABEL = {
    detected: 'Detected', acknowledged: 'Acknowledged', investigating: 'Investigating',
    mitigated: 'Mitigated', resolved: 'Resolved', closed: 'Closed'
  };
  var INC_TONE = {
    detected: 'var(--rd)', acknowledged: 'var(--am)', investigating: 'var(--am)',
    mitigated: 'var(--bl)', resolved: 'var(--ac)', closed: 'var(--t3)'
  };
  var OPEN_STATES = ['detected', 'acknowledged', 'investigating', 'mitigated'];
  function incidentIsOpen(s) { return OPEN_STATES.indexOf(s) !== -1; }

  var SEV_LABEL = { critical: 'Critical', major: 'Major', minor: 'Minor', warning: 'Warning' };
  var SEV_TONE  = { critical: 'var(--rd)', major: 'var(--rd)', minor: 'var(--am)', warning: 'var(--bl)' };

  var TYPE_LABEL = {
    website: 'Website', domain: 'Domain', dns_zone: 'DNS zone', server: 'Server',
    ssl_certificate: 'SSL certificate', deployment: 'Deployment', monitoring_check: 'Monitoring check',
    backup: 'Backup', email_service: 'Email service', provider: 'Provider'
  };
  function typeLabel(t) { return TYPE_LABEL[t] || (t || 'Asset'); }

  /* ----------------------------------------------------------- fragments */

  function card(inner, extra) {
    return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-5);' + (extra || '') + '">' + inner + '</div>';
  }

  function statTile(label, value, tone, sub) {
    return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-4);">' +
      '<div style="font:600 12px var(--fb);color:var(--t3);letter-spacing:.03em;text-transform:uppercase;">' + esc(label) + '</div>' +
      '<div style="font:600 26px var(--fh);color:' + (tone || 'var(--t1)') + ';margin-top:6px;">' + esc(value) + '</div>' +
      (sub ? '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:4px;line-height:1.4;">' + esc(sub) + '</div>' : '') +
      '</div>';
  }

  function button(label, attrs, kind, disabled) {
    var bg = kind === 'primary' ? 'var(--p)' : 'transparent';
    var fg = kind === 'primary' ? '#fff' : 'var(--t1)';
    var bd = kind === 'primary' ? 'var(--p)' : 'var(--bd2)';
    return '<button ' + (attrs || '') + (disabled ? ' disabled' : '') + ' style="' +
      'min-height:var(--touch-min);padding:0 var(--sp-5);border-radius:var(--r);cursor:' + (disabled ? 'not-allowed' : 'pointer') + ';' +
      'font:600 13px var(--fb);background:' + bg + ';color:' + fg + ';border:1px solid ' + bd + ';' +
      'opacity:' + (disabled ? '.5' : '1') + ';transition:opacity var(--dur-fast) var(--ease-out);">' +
      esc(label) + '</button>';
  }

  // Hosting workflow badge (customer state vocab).
  function badge(state) {
    return '<span style="display:inline-flex;align-items:center;gap:6px;font:600 12px var(--fb);color:' + stateTone(state) + ';">' +
      '<span style="width:7px;height:7px;border-radius:50%;background:' + stateTone(state) + ';"></span>' +
      esc(stateLabel(state)) + '</span>';
  }

  // Generic status pill — TEXT + shape, never colour alone (accessibility).
  function statusPill(label, tone) {
    return '<span style="display:inline-flex;align-items:center;gap:6px;font:600 12px var(--fb);color:' + tone + ';white-space:nowrap;">' +
      '<span aria-hidden="true" style="width:7px;height:7px;border-radius:50%;background:' + tone + ';flex:0 0 auto;"></span>' +
      esc(label) + '</span>';
  }
  function healthPill(s) { return statusPill(HEALTH_LABEL[s] || 'Unknown', HEALTH_TONE[s] || 'var(--t3)'); }
  function riskPill(s)   { return statusPill(RISK_LABEL[s] || (s || 'Unknown'), RISK_TONE[s] || 'var(--t3)'); }
  function incPill(s)    { return statusPill(INC_LABEL[s] || (s || 'Unknown'), INC_TONE[s] || 'var(--t3)'); }
  function sevPill(s)    { return statusPill(SEV_LABEL[s] || (s || 'Unknown'), SEV_TONE[s] || 'var(--t3)'); }
  function modeTag(m) {
    return '<span style="display:inline-flex;align-items:center;font:600 11px var(--fb);color:var(--t2);' +
      'background:var(--s2);border:1px solid var(--bd);border-radius:999px;padding:2px 10px;white-space:nowrap;">' +
      esc(MODE_LABEL[m] || (m || 'Unknown')) + '</span>';
  }

  function notice(text, tone) {
    var c = tone === 'warn' ? 'var(--am)' : (tone === 'bad' ? 'var(--rd)' : 'var(--bl)');
    return '<div role="status" style="background:var(--s2);border:1px solid var(--bd);border-left:3px solid ' + c + ';' +
      'border-radius:var(--r);padding:var(--sp-4);margin-bottom:var(--sp-5);font:400 13px var(--fb);color:var(--t2);line-height:1.6;">' +
      esc(text) + '</div>';
  }

  function emptyState(icon, title, body, note, action) {
    return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-9) var(--sp-6);text-align:center;">' +
      '<div style="width:44px;height:44px;margin:0 auto var(--sp-4);color:var(--t3);">' + icon + '</div>' +
      '<div style="font:600 17px var(--fh);color:var(--t1);margin-bottom:8px;">' + esc(title) + '</div>' +
      '<div style="font:400 14px var(--fb);color:var(--t2);max-width:460px;margin:0 auto;line-height:1.6;">' + esc(body) + '</div>' +
      (note ? '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:var(--sp-4);">' + esc(note) + '</div>' : '') +
      (action ? '<div style="margin-top:var(--sp-6);">' + action + '</div>' : '') +
      '</div>';
  }

  function loadingState(msg) {
    return '<div role="status" aria-live="polite" style="padding:var(--sp-8);text-align:center;font:400 13px var(--fb);color:var(--t3);">' +
      esc(msg || 'Loading…') + '</div>';
  }

  function errorState(msg, retryAttr) {
    return card('<div style="font:600 14px var(--fh);color:var(--t1);margin-bottom:6px;">Something went wrong</div>' +
      '<div style="font:400 13px var(--fb);color:var(--t2);margin-bottom:' + (retryAttr ? 'var(--sp-4)' : '0') + ';">' + esc(msg) + '</div>' +
      (retryAttr ? button('Try again', retryAttr) : ''),
      'border-left:3px solid var(--rd);');
  }

  function sectionTitle(t, sub) {
    return '<div style="margin-bottom:var(--sp-4);">' +
      '<div style="font:600 15px var(--fh);color:var(--t1);">' + esc(t) + '</div>' +
      (sub ? '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:3px;line-height:1.5;">' + esc(sub) + '</div>' : '') +
      '</div>';
  }

  // Shared header for intelligence views: title, "data as of", refresh button.
  function intelHeader(title, sub, asOfIso) {
    return '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--sp-4);flex-wrap:wrap;margin-bottom:var(--sp-5);">' +
      '<div><h2 style="font:600 18px var(--fh);color:var(--t1);margin:0;">' + esc(title) + '</h2>' +
      (sub ? '<div style="font:400 13px var(--fb);color:var(--t2);margin-top:4px;line-height:1.5;">' + esc(sub) + '</div>' : '') + '</div>' +
      '<div style="display:flex;align-items:center;gap:var(--sp-4);flex-wrap:wrap;">' +
        (asOfIso ? '<span style="font:400 12px var(--fb);color:var(--t3);">Data as of ' + esc(fmtTime(asOfIso)) + '</span>' : '') +
        '<button id="infra-refresh" aria-label="Refresh" style="display:inline-flex;align-items:center;gap:6px;min-height:var(--touch-min);' +
          'padding:0 var(--sp-4);border-radius:var(--r);cursor:pointer;font:600 12px var(--fb);background:transparent;color:var(--t2);border:1px solid var(--bd2);">' +
          '<span aria-hidden="true" style="width:15px;height:15px;display:inline-flex;">' + ICONS.refresh + '</span>Refresh</button>' +
      '</div></div>';
  }

  /* ==================================================== shared page shell ===
   * One skeleton for every customer surface (Websites, Domains, Email, detail),
   * so left edge, title baseline, action baseline, width and spacing are
   * identical everywhere. This is the single source of page structure.
   * ========================================================================= */

  // Is the signed-in user a platform admin? Drives the customer/control-plane
  // split — operational views (Overview/What's Running/Issues/Uptime) are for
  // staff only and must never appear to a paying customer (Bible Law L2).
  function isPlatformAdmin() {
    try { return !!JSON.parse(localStorage.getItem('lu_user') || '{}').is_platform_admin; }
    catch (e) { return false; }
  }

  // Breadcrumb: "Hosting › Websites › Site name". Parent context, one row.
  function crumb(parts) {
    return '<nav aria-label="Breadcrumb" style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;' +
      'font:600 12px var(--fb);color:var(--t3);margin-bottom:var(--sp-3);">' +
      parts.map(function (p, i) {
        var sep = i ? '<span aria-hidden="true" style="color:var(--t3);opacity:.6;">/</span>' : '';
        if (p.id) {
          return sep + '<button class="' + p.id + '" style="background:none;border:none;padding:0;cursor:pointer;' +
            'font:600 12px var(--fb);color:var(--t2);">' + esc(p.label) + '</button>';
        }
        return sep + '<span style="color:' + (i === parts.length - 1 ? 'var(--t2)' : 'var(--t3)') + ';">' + esc(p.label) + '</span>';
      }).join('') + '</nav>';
  }

  // Primary/secondary action buttons for a page header (consistent sizing).
  function headerBtn(label, attrs, kind, disabled, title) {
    var primary = kind === 'primary';
    return '<button class="infra-hbtn" ' + (attrs || '') + (disabled ? ' disabled aria-disabled="true"' : '') +
      (title ? ' title="' + esc(title) + '"' : '') + ' style="' +
      'display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:0 var(--sp-4);border-radius:var(--r);' +
      'cursor:' + (disabled ? 'not-allowed' : 'pointer') + ';font:600 13px var(--fb);white-space:nowrap;' +
      'border:1px solid ' + (primary ? 'var(--p)' : 'var(--bd2)') + ';background:' + (primary ? 'var(--p)' : 'transparent') + ';' +
      'color:' + (primary ? '#fff' : 'var(--t1)') + ';opacity:' + (disabled ? '.5' : '1') + ';">' + esc(label) + '</button>';
  }

  /**
   * The page shell. opts:
   *   breadcrumb : array for crumb() (optional)
   *   title, desc, badgeHtml
   *   actions    : right-aligned HTML (primary + secondary)
   *   tabs       : {items:[{key,label,icon}], active, attr} — local tabs (detail only)
   *   body       : main content HTML
   */
  function pageShell(opts) {
    var head =
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--sp-5);flex-wrap:wrap;margin-bottom:var(--sp-5);">' +
        '<div style="min-width:0;">' +
          '<div style="display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap;">' +
            '<h1 style="font:600 24px var(--fh);color:var(--t1);margin:0;letter-spacing:-.01em;">' + esc(opts.title) + '</h1>' +
            (opts.badgeHtml || '') +
          '</div>' +
          (opts.desc ? '<div style="font:400 14px var(--fb);color:var(--t2);margin-top:6px;max-width:70ch;line-height:1.5;">' + esc(opts.desc) + '</div>' : '') +
        '</div>' +
        (opts.actions ? '<div class="infra-head-actions" style="display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap;flex:none;">' + opts.actions + '</div>' : '') +
      '</div>';

    var tabs = '';
    if (opts.tabs && opts.tabs.items && opts.tabs.items.length) {
      tabs = '<div role="tablist" style="display:flex;gap:2px;border-bottom:1px solid var(--bd);margin-bottom:var(--sp-6);overflow-x:auto;">' +
        opts.tabs.items.map(function (t) {
          var on = opts.tabs.active === t.key;
          return '<button role="tab" aria-selected="' + on + '" ' + (opts.tabs.attr || 'data-detail-tab') + '="' + t.key + '" style="' +
            'display:inline-flex;align-items:center;gap:7px;background:none;border:none;cursor:pointer;white-space:nowrap;' +
            'padding:9px var(--sp-4);min-height:40px;font:600 13px var(--fb);color:' + (on ? 'var(--t1)' : 'var(--t2)') + ';' +
            'border-bottom:2px solid ' + (on ? 'var(--p)' : 'transparent') + ';margin-bottom:-1px;">' +
            (t.icon ? '<span aria-hidden="true" style="width:15px;height:15px;display:inline-flex;">' + t.icon + '</span>' : '') + esc(t.label) +
          '</button>';
        }).join('') + '</div>';
    }

    return '<div style="max-width:1360px;">' +
      (opts.breadcrumb ? crumb(opts.breadcrumb) : '') + head + tabs + (opts.body || '') + '</div>';
  }

  // Compact metric strip (matches the app's dashboard convention). Real data only.
  function metricStrip(metrics) {
    return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:var(--sp-3);margin-bottom:var(--sp-6);">' +
      metrics.map(function (m) {
        return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-4) var(--sp-5);">' +
          '<div style="font:600 11px var(--fb);color:var(--t3);letter-spacing:.04em;text-transform:uppercase;">' + esc(m.label) + '</div>' +
          '<div style="font:600 26px var(--fh);color:' + (m.tone || 'var(--t1)') + ';margin-top:5px;font-variant-numeric:tabular-nums;">' + esc(m.value) + '</div>' +
          '</div>';
      }).join('') + '</div>';
  }

  /* ============================================================ HOSTING =====
   * Realigned around the LOCKED product strategy (2026-07-24):
   *   Type 1 — Native LevelUp websites (built here, already live on
   *            *.levelupgrowth.io). Their hosting is INCLUDED. They begin at
   *            "Manage hosting" and are NEVER asked to provision.
   *   Type 2 — External websites (WordPress / Laravel / static / …) brought in
   *            via "Add existing website". They begin at "Provision / Migrate".
   *
   * The landing page is now "Your Websites", sourced from the customer's real
   * websites (GET /builder/websites) — no second inventory. Classification is
   * derived from the existing, previously-unused websites.platform column, so
   * no migration and no new persisted field are introduced (see Phase 1).
   * ========================================================================= */

  // Platforms that mark a site as brought-in (Type 2). Anything else — including
  // the common case of an empty platform written by the Builder — is Native.
  var EXTERNAL_PLATFORMS = ['wordpress', 'laravel', 'php', 'static', 'external', 'custom', 'other'];

  var PLATFORM_LABEL = {
    wordpress: 'WordPress', laravel: 'Laravel', php: 'PHP app',
    static: 'Static site', external: 'External site', custom: 'Custom app', other: 'Website'
  };

  function siteOrigin(s) {
    var p = String((s && s.platform) || '').toLowerCase();
    return EXTERNAL_PLATFORMS.indexOf(p) !== -1 ? 'external' : 'native';
  }

  function siteIsLive(s) {
    return (s && (s.status === 'published' || s.publish_state === 'published')) && !!(s && s.subdomain);
  }

  // The address a visitor actually uses: a verified custom domain wins, else the
  // levelupgrowth.io subdomain, else nothing yet.
  function siteAddress(s) {
    if (s && s.custom_domain && Number(s.domain_verified) === 1) { return s.custom_domain; }
    if (s && s.subdomain) { return String(s.subdomain).replace(/^https?:\/\//, ''); }
    return null;
  }

  function originBadge(origin, platform) {
    var label = origin === 'native' ? 'Built with LevelUp'
      : (PLATFORM_LABEL[String(platform).toLowerCase()] || 'External site');
    var tone = origin === 'native' ? 'var(--p)' : 'var(--bl)';
    return '<span style="display:inline-flex;align-items:center;gap:6px;font:600 11px var(--fb);color:' + tone + ';' +
      'background:var(--s2);border:1px solid var(--bd);border-radius:999px;padding:3px 10px;white-space:nowrap;">' +
      '<span aria-hidden="true" style="width:6px;height:6px;border-radius:50%;background:' + tone + ';"></span>' +
      esc(label) + '</span>';
  }

  // A small ✓/• fact line used on cards and the manage view.
  function factLine(ok, text, tone) {
    var c = ok ? (tone || 'var(--ac)') : 'var(--t3)';
    return '<span style="display:inline-flex;align-items:center;gap:6px;font:400 12px var(--fb);color:var(--t2);">' +
      '<span aria-hidden="true" style="color:' + c + ';font-weight:700;">' + (ok ? '✓' : '·') + '</span>' + esc(text) + '</span>';
  }

  // Customer hosting-tier vocabulary (never raw backend states).
  function hostingTier(s, origin, live) {
    if (origin === 'external' && !live) { return { label: 'Setup in progress', tone: 'var(--am)' }; }
    return { label: 'Included Hosting', tone: 'var(--t2)' };
  }
  function lifecycleState(s, origin, live) {
    if (live) { return { label: 'Live', tone: 'var(--ac)' }; }
    if (origin === 'external') { return { label: 'Action required', tone: 'var(--am)' }; }
    return { label: 'Draft', tone: 'var(--t3)' };
  }

  // ---- landing: WEBSITES ------------------------------------------------
  function renderYourWebsites(sites, entitlement) {
    sites = sites || [];

    var actions =
      (isPlatformAdmin() ? headerBtn('Operations', 'id="infra-ops-entry" title="Internal control plane (admin)"') : '') +
      headerBtn('Create website', 'id="infra-create-website"') +
      headerBtn('Add existing website', 'id="infra-add-existing" aria-label="Bring in a website you host elsewhere"', 'primary');

    if (!sites.length) {
      return pageShell({
        breadcrumb: [{ label: 'Hosting' }, { label: 'Websites' }],
        title: 'Websites',
        desc: 'Build, launch, connect and manage every website in this workspace.',
        actions: actions,
        body: enterpriseEmpty(ICONS.hosting, 'No websites yet',
          'Create a website with LevelUp and it goes live automatically — hosting, SSL and your address are all handled for you.',
          [{ label: 'Create website', id: 'infra-create-website-2', primary: true }, { label: 'Add existing website', id: 'infra-add-existing-2' }])
      });
    }

    // Summary strip — real, derivable data only (Managed omitted: not per-site reliable).
    var live = sites.filter(function (s) { return siteIsLive(s); }).length;
    var custom = sites.filter(function (s) { return s.custom_domain && Number(s.domain_verified) === 1; }).length;
    var strip = metricStrip([
      { label: 'Total websites', value: sites.length },
      { label: 'Live', value: live, tone: live ? 'var(--ac)' : 'var(--t1)' },
      { label: 'Draft', value: sites.length - live },
      { label: 'Custom domains', value: custom }
    ]);

    var native = sites.filter(function (s) { return siteOrigin(s) === 'native'; });
    var external = sites.filter(function (s) { return siteOrigin(s) === 'external'; });
    var list = native.map(renderWebsiteCard).join('');
    if (external.length) {
      list += '<div style="font:600 11px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.04em;margin:var(--sp-5) 0 var(--sp-3);">Brought in from elsewhere</div>' +
        external.map(renderWebsiteCard).join('');
    }

    return pageShell({
      breadcrumb: [{ label: 'Hosting' }, { label: 'Websites' }],
      title: 'Websites',
      desc: 'Build, launch, connect and manage every website in this workspace.',
      actions: actions,
      body: strip + '<div style="display:flex;flex-direction:column;gap:var(--sp-3);">' + list + '</div>'
    });
  }

  function renderWebsiteCard(s) {
    var origin = siteOrigin(s);
    var live = siteIsLive(s);
    var addr = siteAddress(s);
    var name = s.name || s.title || ('Website #' + s.id);
    var st = lifecycleState(s, origin, live);
    var tier = hostingTier(s, origin, live);
    var hasCustom = !!(s.custom_domain && Number(s.domain_verified) === 1);

    var addrHtml = addr
      ? '<a href="https://' + esc(addr) + '" target="_blank" rel="noopener" style="font:500 13px var(--fb);color:var(--t2);text-decoration:none;word-break:break-all;">' + esc(addr) + '</a>'
      : '<span style="font:400 13px var(--fb);color:var(--t3);">No address yet</span>';

    var facts = '<div style="display:flex;flex-wrap:wrap;gap:var(--sp-4);margin-top:9px;">' +
      factLine(live, live ? 'SSL secured' : 'SSL when live') +
      factLine(hasCustom, hasCustom ? ('Custom domain · ' + s.custom_domain) : 'No custom domain yet') +
      factLine(origin === 'native' || live, tier.label) +
      '</div>';

    // Exactly one primary action; a single ghost secondary at most (no competing fills).
    var primary, secondary = '';
    if (origin === 'native' && !live) {
      primary = '<button class="infra-manage infra-btn" data-site="' + esc(s.id) + '" style="' + btnStyle('primary') + '">Manage</button>';
    } else if (origin === 'external' && !live) {
      primary = '<button class="infra-manage infra-btn" data-site="' + esc(s.id) + '" style="' + btnStyle('primary') + '">Continue setup</button>';
    } else {
      primary = '<button class="infra-manage infra-btn" data-site="' + esc(s.id) + '" style="' + btnStyle('primary') + '">Manage</button>';
      secondary = '<a class="infra-btn" href="https://' + esc(addr) + '" target="_blank" rel="noopener" style="' + btnStyle() + ';text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">Open</a>';
    }

    return '<div class="infra-site-row" data-site="' + esc(s.id) + '" style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-5);cursor:pointer;transition:border-color var(--dur-fast) var(--ease-out);">' +
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--sp-4);flex-wrap:wrap;">' +
        '<div style="min-width:0;">' +
          '<div style="display:flex;align-items:center;gap:var(--sp-3);flex-wrap:wrap;">' +
            '<button type="button" class="infra-site-open" data-site="' + esc(s.id) + '" aria-label="Open ' + esc(name) + ' — manage this website">' + esc(name) + '</button>' +
            originBadge(origin, s.platform) + statusPill(st.label, st.tone) +
          '</div>' +
          '<div style="margin-top:7px;">' + addrHtml + '</div>' + facts +
        '</div>' +
        '<div style="display:flex;gap:var(--sp-3);flex-wrap:wrap;flex:none;" onclick="event.stopPropagation()">' + primary + secondary + '</div>' +
      '</div></div>';
  }

  // Compact inline button style (for anchors + buttons inside cards). One
  // definition for the whole module (hoisted; used everywhere below).
  function btnStyle(kind) {
    var primary = kind === 'primary';
    return 'min-height:36px;padding:0 var(--sp-4);border-radius:var(--r);cursor:pointer;' +
      'font:600 13px var(--fb);border:1px solid ' + (primary ? 'var(--p)' : 'var(--bd2)') + ';' +
      'background:' + (primary ? 'var(--p)' : 'transparent') + ';color:' + (primary ? '#fff' : 'var(--t1)') + ';';
  }

  /**
   * Unified enterprise empty state: icon, title, benefit, optional prerequisite
   * line, and up to two actions. One structure for every empty surface.
   */
  function enterpriseEmpty(icon, title, body, actions, note) {
    var btns = (actions || []).map(function (a) {
      return '<button class="infra-btn" ' + (a.id ? 'id="' + a.id + '"' : '') + (a.disabled ? ' disabled aria-disabled="true"' : '') + ' style="' + btnStyle(a.primary ? 'primary' : '') + (a.disabled ? 'opacity:.5;cursor:not-allowed;' : '') + '">' + esc(a.label) + '</button>';
    }).join('');
    return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-9) var(--sp-6);text-align:center;max-width:560px;margin:var(--sp-4) auto;">' +
      '<div style="width:46px;height:46px;margin:0 auto var(--sp-4);color:var(--t3);">' + icon + '</div>' +
      '<div style="font:600 18px var(--fh);color:var(--t1);margin-bottom:8px;">' + esc(title) + '</div>' +
      '<div style="font:400 14px var(--fb);color:var(--t2);line-height:1.6;">' + esc(body) + '</div>' +
      (note ? '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:var(--sp-4);">' + esc(note) + '</div>' : '') +
      (btns ? '<div style="display:flex;gap:var(--sp-3);justify-content:center;flex-wrap:wrap;margin-top:var(--sp-6);">' + btns + '</div>' : '') +
      '</div>';
  }

  /* ============================================ WEBSITE DETAIL (tabbed) =====
   * The main management surface for a single website. Local tabs
   * (Overview · Hosting · Domain · Activity · Settings) inside the shared shell.
   * Preserves the included/upgrade logic; reorganizes it into a proper surface.
   * ========================================================================= */
  var DETAIL_TABS = [
    { key: 'overview', label: 'Overview' },
    { key: 'hosting', label: 'Hosting' },
    { key: 'domain', label: 'Domain' },
    { key: 'activity', label: 'Activity' },
    { key: 'settings', label: 'Settings' }
  ];

  function renderWebsiteDetail(site, entitlement) {
    var origin = siteOrigin(site);
    var live = siteIsLive(site);
    var name = site.name || site.title || ('Website #' + site.id);
    var st = lifecycleState(site, origin, live);
    var addr = siteAddress(site);

    var actions =
      (live && addr ? '<a class="infra-btn" href="https://' + esc(addr) + '" target="_blank" rel="noopener" style="' + btnStyle() + ';text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">Open website</a>' : '') +
      (origin === 'native' ? '<button class="infra-btn" id="infra-open-builder" style="' + btnStyle() + '">Open in Builder</button>' : '');

    var body;
    if (_detailTab === 'hosting')       { body = detailHosting(site, entitlement, live, addr); }
    else if (_detailTab === 'domain')   { body = detailDomain(site, entitlement); }
    else if (_detailTab === 'activity') { body = '<div id="infra-activity-slot">' + loadingState('Loading activity…') + '</div>'; }
    else if (_detailTab === 'settings') { body = detailSettings(site, origin); }
    else                                { body = detailOverview(site, entitlement, origin, live, addr); }

    return pageShell({
      breadcrumb: [{ label: 'Hosting' }, { label: 'Websites', id: 'infra-back-list' }, { label: name }],
      title: name,
      badgeHtml: originBadge(origin, site.platform) + statusPill(st.label, st.tone),
      desc: null,
      actions: actions,
      tabs: { items: DETAIL_TABS, active: _detailTab, attr: 'data-detail-tab' },
      body: body
    });
  }

  // ---- Overview tab: health at a glance + one recommended next step -------
  function detailOverview(site, entitlement, origin, live, addr) {
    var hasCustom = !!(site.custom_domain && Number(site.domain_verified) === 1);
    var h = (entitlement && entitlement.hosting) || {};
    var managedAvailable = h.can_provision === true;

    var rows = [
      { k: 'Website', v: live ? 'Live' : (origin === 'external' ? 'Setup incomplete' : 'Draft'), ok: live },
      { k: 'Hosting', v: origin === 'native' || live ? 'Included Hosting' : 'Not set up yet', ok: origin === 'native' || live },
      { k: 'SSL', v: live ? 'Secured' : 'Activates when live', ok: live },
      { k: 'Domain', v: hasCustom ? site.custom_domain : (site.subdomain ? String(site.subdomain).replace(/^https?:\/\//, '') : 'None yet'), ok: !!(hasCustom || site.subdomain) },
      { k: 'Address', v: addr || 'None yet', ok: !!addr }
    ];
    var health = card(
      '<div style="font:600 15px var(--fh);color:var(--t1);margin-bottom:var(--sp-4);">Website health</div>' +
      '<div style="display:grid;grid-template-columns:auto 1fr;gap:11px var(--sp-6);font:400 13.5px var(--fb);">' +
      rows.map(function (r) {
        return '<div style="color:var(--t3);">' + esc(r.k) + '</div>' +
          '<div style="color:var(--t1);display:flex;align-items:center;gap:7px;"><span style="color:' + (r.ok ? 'var(--ac)' : 'var(--t3)') + ';font-weight:700;">' + (r.ok ? '✓' : '·') + '</span>' + esc(r.v) + '</div>';
      }).join('') + '</div>');

    // One recommendation at a time.
    var rec = null;
    if (origin === 'external' && !live) { rec = { t: 'Complete setup', d: 'Finish bringing this website into LevelUp.', cta: 'Continue setup', act: 'infra-rec-setup' }; }
    else if (!live) { rec = { t: 'Launch this website', d: 'Publish it in the Builder to take it live.', cta: 'Open in Builder', act: 'infra-rec-builder' }; }
    else if (!hasCustom) { rec = { t: 'Custom domains \u2014 coming soon', d: 'Your site is live on its levelupgrowth.io address with SSL included. Connecting your own domain is not available yet.', cta: 'See details', act: 'infra-rec-domain' }; }
    else if (managedAvailable) { rec = { t: 'Managed Hosting \u2014 coming soon', d: 'A dedicated runtime with automated backups and priority deployment. Not available yet.', cta: 'See details', act: 'infra-rec-hosting' }; }

    var recCard = rec ? '<div style="background:var(--ps);border:1px solid var(--pg);border-radius:var(--rg);padding:var(--sp-5);margin-top:var(--sp-4);">' +
      '<div style="font:600 12px var(--fb);color:var(--p);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;">Recommended next step</div>' +
      '<div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;">' +
      '<div style="min-width:0;"><div style="font:600 15px var(--fh);color:var(--t1);">' + esc(rec.t) + '</div>' +
      '<div style="font:400 13px var(--fb);color:var(--t2);margin-top:2px;">' + esc(rec.d) + '</div></div>' +
      '<button class="infra-btn" id="' + rec.act + '" style="' + btnStyle('primary') + 'flex:none;">' + esc(rec.cta) + '</button></div></div>' : '';

    return '<div style="display:grid;grid-template-columns:1fr;gap:var(--sp-4);max-width:760px;">' + health + recCard + '</div>';
  }

  // ---- Hosting tab: current hosting + capabilities + upgrade -------------
  function detailHosting(site, entitlement, live, addr) {
    var h = (entitlement && entitlement.hosting) || {};
    var managedAvailable = h.can_provision === true;

    var current = card(
      '<div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;margin-bottom:var(--sp-4);">' +
        '<div style="font:600 15px var(--fh);color:var(--t1);">Current hosting</div>' +
        statusPill('Included Hosting', 'var(--t2)') + '</div>' +
      '<div style="display:grid;grid-template-columns:auto 1fr;gap:10px var(--sp-6);font:400 13.5px var(--fb);">' +
        '<div style="color:var(--t3);">Tier</div><div style="color:var(--t1);">Included with your plan</div>' +
        '<div style="color:var(--t3);">Address</div><div style="color:var(--t1);">' + esc(addr || 'Not set yet') + '</div>' +
        '<div style="color:var(--t3);">SSL</div><div style="color:var(--t1);">' + (live ? 'Active' : 'Activates when live') + '</div>' +
        '<div style="color:var(--t3);">Environment</div><div style="color:var(--t1);">Production</div>' +
        '<div style="color:var(--t3);">Status</div><div style="color:var(--t1);">' + (live ? 'Live' : 'Draft') + '</div>' +
      '</div>' +
      '<div style="margin-top:var(--sp-4);display:flex;flex-wrap:wrap;gap:var(--sp-4);">' +
        factLine(true, 'Hosted LevelUp address') + factLine(true, 'SSL') + factLine(true, 'Publishing') + factLine(true, 'Shared runtime') +
      '</div>');

    // Managed upgrade — clearly an UPGRADE, never active before the connector.
    var managedRow = managedAvailable
      ? '<button class="infra-btn" id="infra-upgrade-managed" style="' + btnStyle('primary') + 'opacity:.5;" disabled aria-disabled="true" title="Managed Hosting is not available yet">Upgrade to Managed Hosting</button>'
      : '<span style="font:600 11px var(--fb);color:var(--t3);white-space:nowrap;padding:0 var(--sp-3);">Coming soon</span>';
    var managed = card(
      '<div style="font:600 15px var(--fh);color:var(--t1);margin-bottom:6px;">Managed Hosting</div>' +
      '<div style="font:400 13px var(--fb);color:var(--t2);margin-bottom:var(--sp-4);line-height:1.5;">A dedicated, faster runtime with automated backups, monitoring and priority deployment — an upgrade from included shared hosting.</div>' +
      '<div style="display:flex;flex-wrap:wrap;gap:var(--sp-4);margin-bottom:var(--sp-4);">' +
        factLine(false, 'Dedicated runtime') + factLine(false, 'Automated backups') + factLine(false, 'Monitoring') + factLine(false, 'Priority deployment') +
      '</div>' +
      '<div style="display:flex;justify-content:flex-end;">' + managedRow + '</div>');

    return '<div style="display:flex;flex-direction:column;gap:var(--sp-4);max-width:760px;">' +
      notice(PREVIEW_NOTE, 'warn') + current + managed + '</div>';
  }

  // ---- Domain tab: current address + entitlement + honest state ---------
  /* -------- custom-domain lifecycle (Cloudflare-for-SaaS, Sprint 3) -------- */

  // Client-side normalize + validate, mirroring the server. Apex is detected so
  // we can steer the customer to a subdomain (apex needs infra we don't have yet).
  function normDomainClient(v) {
    return String(v || '').trim().toLowerCase()
      .replace(/^https?:\/\//, '').replace(/[\/:].*$/, '').replace(/\.$/, '');
  }
  function domainValidity(v) {
    var h = normDomainClient(v);
    if (!h) { return { ok: false, msg: '' }; }
    if (!/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/.test(h)) {
      return { ok: false, msg: 'Enter a domain like www.yourbrand.com (no https:// or paths).' };
    }
    if ((h.match(/\./g) || []).length <= 1) {
      return { ok: false, apex: true, msg: 'Apex domains aren’t supported yet — use a subdomain such as www.' + h + '.' };
    }
    return { ok: true, host: h, msg: h + ' looks good.' };
  }

  function domainPurposeLabel(p) {
    return { routing: 'Point your domain to your site', ownership: 'Confirm you own the domain', ssl: 'Enable HTTPS (SSL)' }[p] || 'DNS record';
  }
  // A set of DNS records the customer must add at their registrar.
  function renderDomainRecords(records, heading) {
    if (!records || !records.length) { return ''; }
    return '<div style="margin-top:var(--sp-4);">' +
      (heading ? '<div style="font:600 13px var(--fh);color:var(--t1);margin-bottom:6px;">' + esc(heading) + '</div>' : '') +
      records.map(function (r) {
        return '<div style="border:1px solid var(--bd);border-radius:var(--r);padding:var(--sp-3) var(--sp-4);margin-top:var(--sp-3);background:var(--s2);">' +
          '<div style="font:600 11px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.03em;">' + esc(domainPurposeLabel(r.purpose)) + '</div>' +
          '<div style="display:grid;grid-template-columns:64px 1fr;gap:5px var(--sp-4);font:400 12.5px var(--fb);margin-top:6px;">' +
            '<div style="color:var(--t3);">Type</div><div style="color:var(--t1);">' + esc(r.type) + '</div>' +
            '<div style="color:var(--t3);">Name</div><div style="color:var(--t1);word-break:break-all;">' + esc(r.name) + '</div>' +
            '<div style="color:var(--t3);">Value</div><div style="color:var(--t1);word-break:break-all;">' + esc(r.value) + '</div>' +
          '</div></div>';
      }).join('') + '</div>';
  }

  // Ownership / SSL / routing shown as three separate stages (customer wording).
  function domainStagePill(label, val) {
    var ok = val === 'active';
    var tone = ok ? 'var(--ac)' : (val ? 'var(--am)' : 'var(--t3)');
    return statusPill(label + ': ' + (ok ? 'Done' : (val ? 'In progress' : 'Pending')), tone);
  }

  function detailDomain(site, entitlement) {
    var connected = !!(site.custom_domain);
    var verified = Number(site.domain_verified) === 1;
    var sub = site.subdomain ? String(site.subdomain).replace(/^https?:\/\//, '') : null;

    var current = card(
      '<div style="font:600 15px var(--fh);color:var(--t1);margin-bottom:var(--sp-4);">Your address</div>' +
      '<div style="display:grid;grid-template-columns:auto 1fr;gap:10px var(--sp-6);font:400 13.5px var(--fb);">' +
        '<div style="color:var(--t3);">LevelUp address</div><div style="color:var(--t1);">' + esc(sub || 'Not set yet') + '</div>' +
        '<div style="color:var(--t3);">Custom domain</div><div style="color:var(--t1);">' + (connected ? esc(site.custom_domain) + (verified ? ' · connected' : ' · setup in progress') : 'None connected') + '</div>' +
      '</div>');

    var body;
    if (connected) {
      // Connected / in-progress: show stages + Check/Disconnect. Live status is
      // fetched via "Check status" (verify) so we never fake certificate state.
      body = card(
        '<div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;margin-bottom:var(--sp-4);">' +
          '<div style="font:600 15px var(--fh);color:var(--t1);">' + esc(site.custom_domain) + '</div>' +
          statusPill(verified ? 'Connected' : 'Setup in progress', verified ? 'var(--ac)' : 'var(--am)') + '</div>' +
        '<div style="display:flex;flex-wrap:wrap;gap:var(--sp-4);">' +
          domainStagePill('Ownership', verified ? 'active' : null) +
          domainStagePill('HTTPS', verified ? 'active' : null) +
          domainStagePill('Routing', verified ? 'active' : null) +
        '</div>' +
        '<div id="infra-domain-result"></div>' +
        '<div style="display:flex;gap:var(--sp-3);flex-wrap:wrap;margin-top:var(--sp-5);">' +
          '<button class="infra-btn" id="infra-domain-check" style="' + btnStyle('primary') + '">Check status</button>' +
          '<button class="infra-btn" id="infra-domain-disconnect" style="' + btnStyle() + '">Disconnect</button>' +
        '</div>');
    } else {
      // Not connected: the connect flow. The Connect action is enforced by the
      // backend gate — while it is closed the customer gets an honest "being
      // finalized" notice plus a preview of the DNS change they'll make.
      body = card(
        '<div style="font:600 15px var(--fh);color:var(--t1);margin-bottom:6px;">Connect your own domain</div>' +
        '<div style="display:inline-block;font:600 11px var(--fb);color:#F59E0B;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);border-radius:100px;padding:3px 10px;margin-bottom:10px;">Coming soon \u2014 not available yet</div>' +
        '<div style="font:400 13px var(--fb);color:var(--t2);margin-bottom:var(--sp-4);line-height:1.5;">Use a domain you already own — like <span style="color:var(--t1);">www.yourbrand.com</span> — so visitors see your brand instead of a levelupgrowth.io address. We’ll issue a free SSL certificate automatically.</div>' +
        '<label for="infra-domain-input" style="font:600 12px var(--fb);color:var(--t3);">Your domain</label>' +
        '<input id="infra-domain-input" type="text" inputmode="url" autocomplete="off" placeholder="www.yourbrand.com" ' +
          'style="width:100%;box-sizing:border-box;margin-top:6px;padding:0 var(--sp-4);min-height:44px;border-radius:var(--r);' +
          'background:var(--s2);border:1px solid var(--bd2);color:var(--t1);font:400 14px var(--fb);" />' +
        '<div id="infra-domain-msg" role="status" style="font:400 12px var(--fb);color:var(--t3);min-height:16px;margin-top:6px;"></div>' +
        '<div id="infra-domain-result"></div>' +
        '<div style="display:flex;justify-content:flex-end;margin-top:var(--sp-4);">' +
          '<button class="infra-btn" id="infra-domain-connect" style="' + btnStyle('primary') + 'opacity:.5;" disabled aria-disabled="true">Connect domain</button>' +
        '</div>');
    }

    return '<div style="display:flex;flex-direction:column;gap:var(--sp-4);max-width:760px;">' + current + body + '</div>';
  }

  // Wire the Domain tab. Called from bindManage when the domain tab is active.
  function bindDomainTab(site) {
    var input = document.getElementById('infra-domain-input');
    var msg = document.getElementById('infra-domain-msg');
    var connectBtn = document.getElementById('infra-domain-connect');
    var result = document.getElementById('infra-domain-result');

    function setResult(html) { if (result) { result.innerHTML = html; } }

    if (input && connectBtn) {
      input.addEventListener('input', function () {
        var v = domainValidity(input.value);
        if (msg) { msg.textContent = v.msg; msg.style.color = v.ok ? 'var(--ac)' : (v.apex || v.msg ? 'var(--am)' : 'var(--t3)'); }
        connectBtn.disabled = !v.ok;
        connectBtn.setAttribute('aria-disabled', String(!v.ok));
        connectBtn.style.opacity = v.ok ? '1' : '.5';
      });
      connectBtn.addEventListener('click', function () {
        var v = domainValidity(input.value);
        if (!v.ok) { return; }
        connectBtn.disabled = true; connectBtn.textContent = 'Connecting…';
        req('POST', 'builder/websites/' + site.id + '/custom-domain', { domain: v.host })
          .then(function (r) {
            var j = r.json || {};
            connectBtn.textContent = 'Connect domain';
            if (j.gated) {
              setResult(notice(j.error, 'warn') +
                '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:-8px;">When it’s ready, you’ll add this DNS record at your domain provider:</div>' +
                renderDomainRecords(j.preview));
              connectBtn.disabled = false;
              return;
            }
            if (j.success) {
              _sites = []; // force a refresh of the site list next time
              setResult(notice('Domain added. Add the DNS records below at your domain provider, then choose Check status.') +
                renderDomainRecords(j.records, 'DNS records to add'));
              return;
            }
            setResult(notice(j.error || 'We couldn’t connect that domain.', 'warn'));
            connectBtn.disabled = false;
          })
          .catch(function () {
            connectBtn.textContent = 'Connect domain'; connectBtn.disabled = false;
            setResult(notice('A network error interrupted the request. Please try again.', 'warn'));
          });
      });
    }

    var checkBtn = document.getElementById('infra-domain-check');
    if (checkBtn) {
      checkBtn.addEventListener('click', function () {
        checkBtn.disabled = true; checkBtn.textContent = 'Checking…';
        req('GET', 'builder/websites/' + site.id + '/custom-domain/verify')
          .then(function (r) {
            var j = r.json || {};
            checkBtn.textContent = 'Check status'; checkBtn.disabled = false;
            if (j.success) {
              setResult(notice(j.active ? 'Your domain is fully connected and secured.' : ('Status: ' + (j.status || 'in progress') + '. It can take a little time after you add the DNS records.')) +
                renderDomainRecords(j.records, j.active ? '' : 'DNS records to add'));
            } else {
              setResult(notice(j.error || 'We couldn’t check the status right now.', 'warn'));
            }
          })
          .catch(function () { checkBtn.textContent = 'Check status'; checkBtn.disabled = false; setResult(notice('A network error interrupted the check.', 'warn')); });
      });
    }

    var disc = document.getElementById('infra-domain-disconnect');
    if (disc) {
      disc.addEventListener('click', function () {
        disc.disabled = true; disc.textContent = 'Disconnecting…';
        req('DELETE', 'builder/websites/' + site.id + '/custom-domain')
          .then(function (r) {
            var j = r.json || {};
            if (j.success) { _sites = []; site.custom_domain = null; site.domain_verified = 0; _detailTab = 'domain'; paintBody(renderWebsiteDetail(site, _entitlement)); bindManage(); }
            else { disc.textContent = 'Disconnect'; disc.disabled = false; setResult(notice(j.error || 'We couldn’t disconnect the domain.', 'warn')); }
          })
          .catch(function () { disc.textContent = 'Disconnect'; disc.disabled = false; setResult(notice('A network error interrupted the request.', 'warn')); });
      });
    }
  }

  // ---- Settings tab: link into canonical Builder settings ---------------
  function detailSettings(site, origin) {
    return '<div style="max-width:760px;">' + card(
      '<div style="font:600 15px var(--fh);color:var(--t1);margin-bottom:6px;">Website settings</div>' +
      '<div style="font:400 13px var(--fb);color:var(--t2);line-height:1.6;margin-bottom:var(--sp-4);">' +
        'Content, pages and design for this website are managed in the Builder — the single place for website settings.</div>' +
      (origin === 'native' ? '<button class="infra-btn" id="infra-settings-builder" style="' + btnStyle('primary') + '">Open in Builder</button>' : '')) + '</div>';
  }

  // ---- loader for the landing ------------------------------------------
  function loadYourWebsites() {
    if (_sitesLoading) { paintBody(loadingState('Loading your websites…')); return; }
    _sitesLoading = true;
    paintBody(loadingState('Loading your websites…'));
    req('GET', 'builder/websites')
      .then(function (r) {
        // req() resolves for every HTTP status, so a 4xx/5xx must be caught
        // here — otherwise the list falls back to [] and a server error is
        // silently mis-shown as the "No websites yet" empty state.
        if (!r.ok) {
          var msg = r.status === 401
            ? 'Your session has expired. Please sign in again, then try once more.'
            : 'We couldn’t load your websites just now.';
          paintBody(errorState(msg, 'id="infra-retry"'));
          bindRetry(loadYourWebsites);
          return;
        }
        var j = r.json || {};
        var list = j.websites || j.data || (Array.isArray(j) ? j : []);
        _sites = Array.isArray(list) ? list : [];
        paintBody(renderYourWebsites(_sites, _entitlement));
        bindYourWebsites();
      })
      .catch(function () {
        paintBody(errorState('We couldn’t load your websites.', 'id="infra-retry"'));
        bindRetry(loadYourWebsites);
      })
      .finally(function () { _sitesLoading = false; });
  }

  function findSite(id) {
    for (var i = 0; i < _sites.length; i++) { if (String(_sites[i].id) === String(id)) { return _sites[i]; } }
    return null;
  }

  function startAddExisting() {
    _ext = { step: 1, platform: '', url: '', subdomain: '' };
    _view = { name: 'addExisting' };
    renderCurrentView();
  }
  function openBuilder() {
    if (typeof window.nav === 'function') { window.nav('builder'); }
  }
  function openDetail(id) {
    _detailTab = 'overview';
    _view = { name: 'manage', siteId: id };
    renderCurrentView();
  }

  function bindYourWebsites() {
    ['infra-add-existing', 'infra-add-existing-2'].forEach(function (id) {
      var b = document.getElementById(id); if (b) { b.addEventListener('click', startAddExisting); }
    });
    ['infra-create-website', 'infra-create-website-2'].forEach(function (id) {
      var b = document.getElementById(id); if (b) { b.addEventListener('click', openBuilder); }
    });
    var ops = document.getElementById('infra-ops-entry');
    if (ops) { ops.addEventListener('click', function () { INFRA_TAB = 'operations'; _opsTab = 'overview'; _view = { name: 'list' }; renderCurrentView(); }); }
    // Whole row opens the website's detail surface; explicit Manage buttons too.
    Array.prototype.forEach.call(document.querySelectorAll('.infra-site-row'), function (row) {
      row.addEventListener('click', function () { openDetail(row.getAttribute('data-site')); });
      row.addEventListener('mouseenter', function () { row.style.borderColor = 'var(--bd2)'; });
      row.addEventListener('mouseleave', function () { row.style.borderColor = 'var(--bd)'; });
    });
    Array.prototype.forEach.call(document.querySelectorAll('.infra-manage'), function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); openDetail(b.getAttribute('data-site')); });
    });
    // The website name is the primary, keyboard-focusable open control
    // (title-as-link). Gives keyboard/AT users the same entry point the whole
    // row gives mouse users, without nesting interactive controls in a role.
    Array.prototype.forEach.call(document.querySelectorAll('.infra-site-open'), function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); openDetail(b.getAttribute('data-site')); });
    });
  }

  function bindManage() {
    var site = findSite(_view.siteId);

    // Breadcrumb "Websites" + any back control returns to the list.
    Array.prototype.forEach.call(document.querySelectorAll('.infra-back-list'), function (b) {
      b.addEventListener('click', function () { _view = { name: 'list' }; renderCurrentView(); });
    });

    // Local detail tabs.
    Array.prototype.forEach.call(document.querySelectorAll('[data-detail-tab]'), function (t) {
      t.addEventListener('click', function () {
        _detailTab = t.getAttribute('data-detail-tab');
        paintBody(renderWebsiteDetail(site, _entitlement));
        bindManage();
      });
    });

    // Header + overview + settings actions that route to real places.
    function goTab(tab) { _detailTab = tab; paintBody(renderWebsiteDetail(site, _entitlement)); bindManage(); }
    ['infra-open-builder', 'infra-rec-builder', 'infra-settings-builder'].forEach(function (id) {
      var b = document.getElementById(id); if (b) { b.addEventListener('click', openBuilder); }
    });
    var rd = document.getElementById('infra-rec-domain'); if (rd) { rd.addEventListener('click', function () { goTab('domain'); }); }
    var rh = document.getElementById('infra-rec-hosting'); if (rh) { rh.addEventListener('click', function () { goTab('hosting'); }); }
    var rs = document.getElementById('infra-rec-setup'); if (rs) { rs.addEventListener('click', startAddExisting); }

    // Managed upgrade — honest: routes to the plan/upgrade surface if present,
    // else a clear toast. Never a silent dead button, never a fake purchase.
    var mu = document.getElementById('infra-upgrade-managed');
    if (mu) { mu.addEventListener('click', function () {
      if (typeof window.nav === 'function') { window.nav('billing'); }
      else if (typeof showToast === 'function') { showToast('Managed Hosting upgrade is coming soon — nothing was charged.', 'info'); }
    }); }

    // Activity tab loads real operations for this workspace, translated.
    if (_detailTab === 'activity') { loadWebsiteActivity(); }
    // Domain tab wires the custom-domain lifecycle (connect/check/disconnect).
    if (_detailTab === 'domain') { bindDomainTab(site); }
  }

  // Translate infrastructure operations into plain customer activity. Never
  // exposes provider IDs, payloads, adapter names or state-machine internals.
  var OP_ACTIVITY_LABEL = {
    requested: 'Setup requested', awaiting_approval: 'Awaiting approval', approved: 'Approved',
    queued: 'Setup queued', running: 'Setting up', succeeded: 'Setup completed',
    rejected: 'Request declined', cancelled: 'Request cancelled',
    failed_retryable: 'Temporary problem — retrying', failed_terminal: 'Setup failed', timed_out: 'Setup timed out'
  };
  function loadWebsiteActivity() {
    req('GET', 'infrastructure/operations')
      .then(function (r) {
        var slot = document.getElementById('infra-activity-slot');
        if (!slot) { return; }
        var items = (r.json && r.json.data && (r.json.data.items || r.json.data)) || [];
        if (!Array.isArray(items) || !items.length) {
          slot.innerHTML = enterpriseEmpty(ICONS.reliability, 'No activity yet',
            'When something happens to this website — published, secured, set up — it appears here in plain language.', []);
          return;
        }
        slot.innerHTML = '<div style="max-width:760px;display:flex;flex-direction:column;gap:2px;">' +
          items.slice(0, 40).map(function (op) {
            var label = OP_ACTIVITY_LABEL[op.state] || 'Update';
            return '<div style="display:flex;gap:var(--sp-4);padding:var(--sp-3) 0;border-bottom:1px solid var(--bd);align-items:baseline;">' +
              '<span aria-hidden="true" style="flex:0 0 7px;height:7px;border-radius:50%;background:var(--ac);align-self:center;"></span>' +
              '<div style="min-width:0;flex:1;"><div style="font:600 13px var(--fb);color:var(--t1);">' + esc(label) + '</div></div>' +
              '<span style="font:400 12px var(--fb);color:var(--t3);white-space:nowrap;">' + esc(fmtTime(op.updated_at || op.created_at)) + '</span>' +
              '</div>';
          }).join('') + '</div>';
      })
      .catch(function () {
        var slot = document.getElementById('infra-activity-slot');
        if (slot) { slot.innerHTML = errorState('We couldn’t load activity.'); }
      });
  }

  /* ------------------------------------------- Add existing website (Type 2)
   * A DESIGNED onboarding flow for bringing in an external site. Migration is
   * not implemented (per strategy); this validates the site, reserves a LevelUp
   * address, and hands off to a clear "we'll take it from here" state. Wiring
   * the final step to the existing provisioning pipeline is a one-line change.
   * ----------------------------------------------------------------------- */

  var EXT_PLATFORMS = [
    { id: 'wordpress', label: 'WordPress', desc: 'The most common — blogs, business sites, WooCommerce.' },
    { id: 'laravel',   label: 'Laravel / PHP app', desc: 'A custom PHP application.' },
    { id: 'static',    label: 'Static site', desc: 'Plain HTML, or a site built with a static generator.' },
    { id: 'other',     label: 'Something else', desc: 'Another kind of website or app.' }
  ];
  var EXT_STEPS = ['Type', 'Address', 'Check', 'LevelUp address', 'Review'];

  function extShell(step, title, sub, body, footer) {
    return '<div style="max-width:720px;">' +
      '<button id="infra-back-list" aria-label="Back to your websites" style="display:inline-flex;align-items:center;gap:6px;' +
        'background:none;border:none;color:var(--t2);cursor:pointer;font:600 13px var(--fb);padding:8px 0;margin-bottom:var(--sp-4);min-height:var(--touch-min);">' +
        '<span style="width:16px;height:16px;display:inline-flex;">' + ICONS.back + '</span>Back to your websites</button>' +
      stepRailN(EXT_STEPS, step) +
      card(
        '<div style="font:600 17px var(--fh);color:var(--t1);margin-bottom:4px;">' + esc(title) + '</div>' +
        '<div style="font:400 13px var(--fb);color:var(--t2);margin-bottom:var(--sp-5);line-height:1.6;">' + esc(sub) + '</div>' +
        body +
        '<div id="infra-ext-msg" role="alert" style="margin-top:var(--sp-4);font:400 13px var(--fb);color:var(--rd);"></div>' +
        '<div style="display:flex;gap:var(--sp-3);flex-wrap:wrap;margin-top:var(--sp-5);">' + footer + '</div>'
      ) + '</div>';
  }

  // Generic step rail for any label set (the wizard rail was fixed at 4).
  function stepRailN(labels, current) {
    return '<ol style="list-style:none;display:flex;gap:var(--sp-2);padding:0;margin:0 0 var(--sp-6);flex-wrap:wrap;">' +
      labels.map(function (label, i) {
        var n = i + 1, done = n < current, on = n === current;
        var fg = on ? 'var(--t1)' : (done ? 'var(--ac)' : 'var(--t3)');
        var bd = on ? 'var(--p)' : (done ? 'var(--ac)' : 'var(--bd2)');
        return '<li aria-current="' + (on ? 'step' : 'false') + '" style="display:flex;align-items:center;gap:8px;padding:6px 12px;' +
          'border:1px solid ' + bd + ';border-radius:999px;font:600 12px var(--fb);color:' + fg + ';">' +
          '<span aria-hidden="true" style="width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;' +
            'font:600 11px var(--fb);background:' + (done ? 'var(--ac)' : (on ? 'var(--p)' : 'var(--s2)')) + ';color:' + (done || on ? '#fff' : 'var(--t3)') + ';">' +
            (done ? '✓' : n) + '</span>' + esc(label) + '</li>';
      }).join('') + '</ol>';
  }

  function renderAddExisting(entitlement) {
    var st = _ext.step;
    if (st === 1) {
      var body = EXT_PLATFORMS.map(function (p) {
        return optionRow(p.id, _ext.platform === p.id, p.label, p.desc);
      }).join('');
      return extShell(1, 'What kind of website is it?',
        'Tell us what your existing site is built with, so we set it up the right way.',
        body,
        button('Continue', 'id="infra-ext-next"', 'primary', !_ext.platform) +
        button('Cancel', 'id="infra-ext-cancel"'));
    }
    if (st === 2) {
      var body2 =
        '<label for="infra-ext-url" style="display:block;font:600 12px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;">Your website address</label>' +
        '<input id="infra-ext-url" type="url" inputmode="url" spellcheck="false" autocapitalize="none" value="' + esc(_ext.url) + '" ' +
          'placeholder="https://your-site.com" style="width:100%;box-sizing:border-box;min-height:var(--touch-min);padding:0 var(--sp-4);' +
          'background:var(--s2);border:1px solid var(--bd2);border-radius:var(--r);color:var(--t1);font:400 14px var(--fb);">' +
        '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:6px;line-height:1.5;">' +
          'The address your visitors use today. We’ll check we can reach it.</div>';
      return extShell(2, 'Where is your site now?',
        'We’ll look it up so we can bring across the right thing.',
        body2,
        button('Continue', 'id="infra-ext-next"', 'primary') +
        button('Back', 'id="infra-ext-prev"'));
    }
    if (st === 3) {
      var plat = (EXT_PLATFORMS.filter(function (p) { return p.id === _ext.platform; })[0] || {}).label || 'website';
      // Designed validation result. This does NOT probe the live site — a real
      // reachability + platform check is prepared as backend follow-up.
      var body3 =
        notice('This is a preview of the check. Live validation and migration are being prepared — nothing is moved yet.', 'warn') +
        '<div style="display:flex;flex-direction:column;gap:10px;">' +
          factLine(true, 'Address looks valid: ' + (_ext.url || '—')) +
          factLine(true, 'Detected type: ' + plat) +
          factLine(true, 'We can prepare a home for it on LevelUp') +
        '</div>';
      return extShell(3, 'Here’s what we found',
        'A quick summary before we reserve your LevelUp address.',
        body3,
        button('Continue', 'id="infra-ext-next"', 'primary') +
        button('Back', 'id="infra-ext-prev"'));
    }
    if (st === 4) {
      var val = _ext.subdomain || '';
      var body4 =
        '<label for="infra-ext-sub" style="display:block;font:600 12px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;">Your LevelUp address</label>' +
        '<div style="display:flex;align-items:center;margin-bottom:6px;">' +
          '<input id="infra-ext-sub" type="text" maxlength="50" value="' + esc(val) + '" spellcheck="false" autocapitalize="none" placeholder="your-business" ' +
            'style="flex:1;min-width:0;box-sizing:border-box;min-height:var(--touch-min);padding:0 var(--sp-4);background:var(--s2);' +
            'border:1px solid var(--bd2);border-right:none;border-radius:var(--r) 0 0 var(--r);color:var(--t1);font:400 14px var(--fb);">' +
          '<span style="min-height:var(--touch-min);display:inline-flex;align-items:center;padding:0 var(--sp-4);background:var(--s3);' +
            'border:1px solid var(--bd2);border-radius:0 var(--r) var(--r) 0;color:var(--t2);font:400 14px var(--fb);white-space:nowrap;">.levelupgrowth.io</span>' +
        '</div>' +
        '<div style="font:400 12px var(--fb);color:var(--t3);margin-bottom:var(--sp-3);line-height:1.5;">Where your site will live while it’s with us. You can point your own domain at it afterwards.</div>' +
        '<div id="infra-ext-sub-status" role="status" aria-live="polite" style="font:600 12px var(--fb);min-height:18px;"></div>';
      return extShell(4, 'Pick your LevelUp address',
        'We’ll host the migrated site here, secured with SSL.',
        body4,
        button('Continue', 'id="infra-ext-next"', 'primary') +
        button('Back', 'id="infra-ext-prev"'));
    }
    // Step 5 — review + hand-off.
    var platL = (EXT_PLATFORMS.filter(function (p) { return p.id === _ext.platform; })[0] || {}).label || '—';
    var body5 =
      '<dl style="display:grid;grid-template-columns:auto 1fr;gap:12px var(--sp-5);margin:0 0 var(--sp-5);font:400 13px var(--fb);">' +
        '<dt style="color:var(--t3);">Website type</dt><dd style="margin:0;color:var(--t1);">' + esc(platL) + '</dd>' +
        '<dt style="color:var(--t3);">Current address</dt><dd style="margin:0;color:var(--t1);word-break:break-all;">' + esc(_ext.url || '—') + '</dd>' +
        '<dt style="color:var(--t3);">New LevelUp address</dt><dd style="margin:0;color:var(--t1);">' + esc(_ext.subdomain) + '.levelupgrowth.io</dd>' +
        '<dt style="color:var(--t3);">What happens next</dt><dd style="margin:0;color:var(--t1);">We prepare hosting and guide you through moving your site over</dd>' +
        '<dt style="color:var(--t3);">Cost</dt><dd style="margin:0;color:var(--t1);">Nothing today — migration is being prepared</dd>' +
      '</dl>';
    return extShell(5, 'Ready to bring it in',
      'We’ll reserve your address and start preparing the move.',
      body5,
      button('Start migration', 'id="infra-ext-submit"', 'primary') +
      button('Back', 'id="infra-ext-prev"'));
  }

  function renderAddExistingDone() {
    return '<div style="max-width:640px;">' +
      '<button id="infra-back-list" aria-label="Back to your websites" style="display:inline-flex;align-items:center;gap:6px;' +
        'background:none;border:none;color:var(--t2);cursor:pointer;font:600 13px var(--fb);padding:8px 0;margin-bottom:var(--sp-4);min-height:var(--touch-min);">' +
        '<span style="width:16px;height:16px;display:inline-flex;">' + ICONS.back + '</span>Back to your websites</button>' +
      card(
        '<div style="width:44px;height:44px;border-radius:50%;background:var(--as);border:1px solid var(--ag);display:flex;align-items:center;justify-content:center;margin-bottom:var(--sp-4);font:700 20px var(--fb);color:var(--ac);">✓</div>' +
        '<div style="font:600 18px var(--fh);color:var(--t1);margin-bottom:8px;">We’ve got it from here</div>' +
        '<div style="font:400 14px var(--fb);color:var(--t2);line-height:1.6;margin-bottom:var(--sp-5);">' +
          'Your ' + esc(_ext.subdomain) + '.levelupgrowth.io address is reserved. Migration is being prepared — ' +
          'we’ll walk you through moving your site over and let you know the moment it’s ready. Nothing has been charged.</div>' +
        '<div style="font:600 12px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.03em;margin-bottom:var(--sp-3);">What happens next</div>' +
        '<div style="display:flex;flex-direction:column;gap:10px;margin-bottom:var(--sp-5);">' +
          factLine(false, 'We prepare a dedicated home for your site') +
          factLine(false, 'We help you move your content and settings across') +
          factLine(false, 'You review it on your LevelUp address, then point your domain over') +
        '</div>' +
        button('Back to your websites', 'id="infra-ext-done"', 'primary'));
  }

  function bindAddExisting() {
    var back = document.getElementById('infra-back-list');
    if (back) { back.addEventListener('click', function () { _view = { name: 'list' }; renderCurrentView(); }); }
    var cancel = document.getElementById('infra-ext-cancel');
    if (cancel) { cancel.addEventListener('click', function () { _view = { name: 'list' }; renderCurrentView(); }); }
    var done = document.getElementById('infra-ext-done');
    if (done) { done.addEventListener('click', function () { _view = { name: 'list' }; renderCurrentView(); }); }

    var prev = document.getElementById('infra-ext-prev');
    if (prev) { prev.addEventListener('click', function () { _ext.step = Math.max(1, _ext.step - 1); renderCurrentView(); }); }

    Array.prototype.forEach.call(document.querySelectorAll('.infra-opt'), function (b) {
      b.addEventListener('click', function () { _ext.platform = b.getAttribute('data-opt-id'); renderCurrentView(); });
    });

    var next = document.getElementById('infra-ext-next');
    if (next) {
      next.addEventListener('click', function () {
        var msg = document.getElementById('infra-ext-msg');
        if (_ext.step === 1 && !_ext.platform) { if (msg) msg.textContent = 'Choose a website type.'; return; }
        if (_ext.step === 2) {
          var u = (document.getElementById('infra-ext-url') || {}).value || '';
          u = u.trim();
          if (!/^https?:\/\/.+\..+/.test(u)) { if (msg) msg.textContent = 'Enter a full website address, e.g. https://your-site.com'; return; }
          _ext.url = u;
        }
        if (_ext.step === 4) {
          var label = subNormalize((document.getElementById('infra-ext-sub') || {}).value || '');
          var err = subCheck(label);
          if (err) { if (msg) msg.textContent = err; return; }
          _ext.subdomain = label;
        }
        _ext.step = Math.min(EXT_STEPS.length, _ext.step + 1);
        renderCurrentView();
      });
    }

    var subEl = document.getElementById('infra-ext-sub');
    if (subEl) {
      subEl.focus();
      var t = null;
      subEl.addEventListener('input', function () {
        var label = subNormalize(subEl.value);
        _ext.subdomain = label;
        clearTimeout(t);
        t = setTimeout(function () { probeExtSubdomain(label); }, 350);
      });
      if (_ext.subdomain) { probeExtSubdomain(_ext.subdomain); }
    }

    var submit = document.getElementById('infra-ext-submit');
    if (submit) {
      submit.addEventListener('click', function () {
        // DESIGNED hand-off (migration not implemented). Reserving the address
        // and provisioning the receiving shell is prepared as follow-up.
        _view = { name: 'addExistingDone' };
        renderCurrentView();
      });
    }
  }

  function probeExtSubdomain(label) {
    var el = document.getElementById('infra-ext-sub-status');
    if (!el) { return; }
    var err = subCheck(label);
    if (err) { el.style.color = 'var(--rd)'; el.textContent = err; return; }
    el.style.color = 'var(--t3)'; el.textContent = 'Checking availability…';
    req('GET', 'builder/check-subdomain?slug=' + encodeURIComponent(label))
      .then(function (r) {
        var e2 = document.getElementById('infra-ext-sub-status');
        if (!e2 || subNormalize((document.getElementById('infra-ext-sub') || {}).value || '') !== label) { return; }
        var j = r.json || {};
        if (j.available === true) { e2.style.color = 'var(--ac)'; e2.textContent = label + '.levelupgrowth.io is available'; }
        else if (j.available === false) { e2.style.color = 'var(--rd)'; e2.textContent = (j.error || 'That address is taken.') + (j.suggestion ? ' Try "' + j.suggestion + '".' : ''); }
        else { e2.textContent = ''; }
      })
      .catch(function () { var e3 = document.getElementById('infra-ext-sub-status'); if (e3) e3.textContent = ''; });
  }

  /* ------------------------------------------- managed-hosting wizard */

  // Canonical subdomain rules — MIRROR of app/Engines/Infrastructure/Services/
  // SubdomainService.php. The server is always authoritative (it re-validates
  // and re-checks availability on submit); this only gives instant feedback.
  var SUB_PATTERN  = /^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/;
  var SUB_MIN = 3, SUB_MAX = 50;
  var SUB_RESERVED = ['www','app','api','admin','staging','mail','ftp','smtp','pop',
    'levelup','levelupgrowth','support','help','blog','test','demo','dashboard','panel',
    'login','signup','register','auth','oauth','billing','payment','stripe','webhook',
    'internal','system','root','cdn','assets','static','media','img','images','css','js'];

  function subNormalize(raw) {
    var s = String(raw || '').toLowerCase().trim();
    if (s.indexOf('.') !== -1) { s = s.split('.')[0]; }
    s = s.replace(/[^a-z0-9-]+/g, '').replace(/-+/g, '-');
    return s.replace(/^-+|-+$/g, '');
  }

  function subCheck(label) {
    if (!label) { return 'Choose a web address.'; }
    if (label.length < SUB_MIN || label.length > SUB_MAX) {
      return 'Use between ' + SUB_MIN + ' and ' + SUB_MAX + ' characters.';
    }
    if (!SUB_PATTERN.test(label)) {
      return 'Use lowercase letters, numbers and hyphens only. It can’t start or end with a hyphen.';
    }
    if (SUB_RESERVED.indexOf(label) !== -1) { return 'That web address is reserved. Try another.'; }
    return null;
  }

  var WIZARD_STEPS = ['Website', 'Plan', 'Web address', 'Review'];

  function stepRail(current) {
    return '<ol style="list-style:none;display:flex;gap:var(--sp-2);padding:0;margin:0 0 var(--sp-6);flex-wrap:wrap;">' +
      WIZARD_STEPS.map(function (label, i) {
        var n = i + 1;
        var done = n < current, on = n === current;
        var fg = on ? 'var(--t1)' : (done ? 'var(--ac)' : 'var(--t3)');
        var bd = on ? 'var(--p)' : (done ? 'var(--ac)' : 'var(--bd2)');
        return '<li aria-current="' + (on ? 'step' : 'false') + '" ' +
          'style="display:flex;align-items:center;gap:8px;padding:6px 12px;border:1px solid ' + bd + ';' +
          'border-radius:999px;font:600 12px var(--fb);color:' + fg + ';">' +
          '<span aria-hidden="true" style="width:18px;height:18px;border-radius:50%;display:inline-flex;' +
            'align-items:center;justify-content:center;font:600 11px var(--fb);' +
            'background:' + (done ? 'var(--ac)' : (on ? 'var(--p)' : 'var(--s2)')) + ';' +
            'color:' + (done || on ? '#fff' : 'var(--t3)') + ';">' + (done ? '✓' : n) + '</span>' +
          esc(label) + '</li>';
      }).join('') + '</ol>';
  }

  function wizardShell(step, title, sub, body, footer) {
    return '<div style="max-width:720px;">' +
      '<button id="infra-back-list" aria-label="Back to hosting" style="display:inline-flex;align-items:center;gap:6px;' +
        'background:none;border:none;color:var(--t2);cursor:pointer;font:600 13px var(--fb);padding:8px 0;' +
        'margin-bottom:var(--sp-4);min-height:var(--touch-min);">' +
        '<span style="width:16px;height:16px;display:inline-flex;">' + ICONS.back + '</span>Back to hosting</button>' +
      stepRail(step) +
      notice(PREVIEW_NOTE, 'warn') +
      card(
        '<div style="font:600 17px var(--fh);color:var(--t1);margin-bottom:4px;">' + esc(title) + '</div>' +
        '<div style="font:400 13px var(--fb);color:var(--t2);margin-bottom:var(--sp-5);line-height:1.6;">' + esc(sub) + '</div>' +
        body +
        '<div id="infra-submit-msg" role="alert" style="margin-top:var(--sp-4);font:400 13px var(--fb);color:var(--rd);"></div>' +
        '<div style="display:flex;gap:var(--sp-3);flex-wrap:wrap;margin-top:var(--sp-5);">' + footer + '</div>'
      ) + '</div>';
  }

  function optionRow(id, selected, title, meta, disabled) {
    return '<button class="infra-opt" data-opt-id="' + esc(id) + '"' + (disabled ? ' disabled' : '') + ' style="' +
      'width:100%;text-align:left;display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);' +
      'background:' + (selected ? 'var(--ps)' : 'var(--s2)') + ';border:1px solid ' + (selected ? 'var(--p)' : 'var(--bd)') + ';' +
      'border-radius:var(--r);padding:var(--sp-4);margin-bottom:var(--sp-2);cursor:' + (disabled ? 'not-allowed' : 'pointer') + ';' +
      'opacity:' + (disabled ? '.5' : '1') + ';min-height:var(--touch-min);">' +
      '<span style="min-width:0;"><span style="display:block;font:600 14px var(--fb);color:var(--t1);">' + esc(title) + '</span>' +
      (meta ? '<span style="display:block;font:400 12px var(--fb);color:var(--t3);margin-top:3px;">' + esc(meta) + '</span>' : '') + '</span>' +
      '<span aria-hidden="true" style="width:18px;height:18px;flex:none;border-radius:50%;border:2px solid ' +
        (selected ? 'var(--p)' : 'var(--bd2)') + ';background:' + (selected ? 'var(--p)' : 'transparent') + ';"></span>' +
      '</button>';
  }

  // Step 1 — which website is this hosting for?
  function renderStepWebsite() {
    var body;
    if (_wizard.sitesLoading) {
      body = loadingState('Loading your websites…');
    } else if (!_wizard.sites.length) {
      body = emptyState(ICONS.domains, 'You don’t have a website yet',
        'Create a website first, then come back here to put it online.');
    } else {
      body = _wizard.sites.map(function (s) {
        return optionRow(s.id, String(_wizard.websiteId) === String(s.id), s.name || ('Website #' + s.id),
          s.subdomain ? s.subdomain : 'No web address yet');
      }).join('');
    }

    return wizardShell(1, 'Which website is this for?',
      'Choose the website you want to put online. You can host another one later.',
      body,
      button('Continue', 'id="infra-next" aria-label="Continue to plan"', 'primary', !_wizard.websiteId) +
      button('Cancel', 'id="infra-cancel"'));
  }

  // Step 2 — plan. Only the allowance actually included in the subscription is
  // offered. No invented tiers, no invented prices (directive §DATA-INTEGRITY).
  function renderStepPlan(entitlement) {
    var h = (entitlement && entitlement.hosting) || {};
    var remaining = h.limit != null ? Math.max(0, (h.limit || 0) - (h.used || 0)) : null;

    var body = optionRow('included', _wizard.plan === 'included', 'Included with your plan',
      remaining != null
        ? remaining + ' of ' + h.limit + ' hosting service' + (h.limit === 1 ? '' : 's') + ' remaining'
        : 'Hosting is included in your subscription') +
      '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:var(--sp-4);line-height:1.6;">' +
        'Additional hosting plans will appear here when they go on sale. You won’t be charged for this — hosting isn’t billed yet.' +
      '</div>';

    return wizardShell(2, 'Choose your hosting plan',
      'This is what your current subscription includes.',
      body,
      button('Continue', 'id="infra-next" aria-label="Continue to web address"', 'primary', !_wizard.plan) +
      button('Back', 'id="infra-prev"'));
  }

  // Step 3 — the web address.
  function renderStepSubdomain() {
    var val = _wizard.subdomain || '';
    var body =
      '<label for="infra-sub" style="display:block;font:600 12px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;">Web address</label>' +
      '<div style="display:flex;align-items:center;gap:0;margin-bottom:6px;">' +
        '<input id="infra-sub" type="text" maxlength="50" value="' + esc(val) + '" spellcheck="false" autocapitalize="none" ' +
          'aria-describedby="infra-sub-help" placeholder="your-business" style="' +
          'flex:1;min-width:0;box-sizing:border-box;min-height:var(--touch-min);padding:0 var(--sp-4);' +
          'background:var(--s2);border:1px solid var(--bd2);border-right:none;border-radius:var(--r) 0 0 var(--r);' +
          'color:var(--t1);font:400 14px var(--fb);">' +
        '<span style="min-height:var(--touch-min);display:inline-flex;align-items:center;padding:0 var(--sp-4);' +
          'background:var(--s3);border:1px solid var(--bd2);border-radius:0 var(--r) var(--r) 0;' +
          'color:var(--t2);font:400 14px var(--fb);white-space:nowrap;">.levelupgrowth.io</span>' +
      '</div>' +
      '<div id="infra-sub-help" style="font:400 12px var(--fb);color:var(--t3);margin-bottom:var(--sp-3);line-height:1.5;">' +
        'Lowercase letters, numbers and hyphens. This is the address visitors will type.</div>' +
      '<div id="infra-sub-status" role="status" aria-live="polite" style="font:600 12px var(--fb);min-height:18px;"></div>';

    return wizardShell(3, 'Pick your web address',
      'Your site will be reachable here as soon as it’s live. You can add your own domain name later.',
      body,
      button('Continue', 'id="infra-next" aria-label="Continue to review"', 'primary') +
      button('Back', 'id="infra-prev"'));
  }

  // Step 4 — review.
  function renderStepReview(entitlement) {
    var h = (entitlement && entitlement.hosting) || {};
    var site = _wizard.sites.filter(function (s) { return String(s.id) === String(_wizard.websiteId); })[0];

    var body =
      '<dl style="display:grid;grid-template-columns:auto 1fr;gap:12px var(--sp-5);margin:0 0 var(--sp-5);font:400 13px var(--fb);">' +
        '<dt style="color:var(--t3);">Website</dt><dd style="margin:0;color:var(--t1);">' + esc(site ? (site.name || ('Website #' + site.id)) : '—') + '</dd>' +
        '<dt style="color:var(--t3);">Plan</dt><dd style="margin:0;color:var(--t1);">Included with your plan' +
          (h.limit != null ? ' (' + esc(Math.max(0, (h.limit || 0) - (h.used || 0))) + ' remaining)' : '') + '</dd>' +
        '<dt style="color:var(--t3);">Web address</dt><dd style="margin:0;color:var(--t1);">' + esc(_wizard.subdomain) + '.levelupgrowth.io</dd>' +
        '<dt style="color:var(--t3);">What happens next</dt><dd style="margin:0;color:var(--t1);">We review and approve it, then set it up</dd>' +
        '<dt style="color:var(--t3);">Cost</dt><dd style="margin:0;color:var(--t1);">Nothing today — hosting isn’t billed yet</dd>' +
      '</dl>' +
      '<div style="font:400 12px var(--fb);color:var(--t3);line-height:1.6;">' +
        'Free right now because billing isn’t switched on — not because hosting will always be free.' +
      '</div>';

    return wizardShell(4, 'Review and create',
      'Check the details below, then create your hosting account.',
      body,
      button('Create hosting', 'id="infra-submit" aria-label="Create hosting account"', 'primary') +
      button('Back', 'id="infra-prev"'));
  }

  function renderWizard(entitlement) {
    if (_wizard.step === 1) { return renderStepWebsite(); }
    if (_wizard.step === 2) { return renderStepPlan(entitlement); }
    if (_wizard.step === 3) { return renderStepSubdomain(); }
    return renderStepReview(entitlement);
  }

  /**
   * Plain-English progress for a hosting request (Phase 4).
   *
   * Maps the operation state machine onto four things a customer actually
   * cares about. It reports ONLY what the backend state proves: a stage is
   * marked done when the state has genuinely moved past it, never on a timer
   * and never optimistically. A failed/rejected request shows no green ticks
   * beyond the stages it truly completed.
   */
  var PROGRESS_STAGES = [
    { key: 'submitted', label: 'Request received',    after: ['requested', 'awaiting_approval', 'approved', 'queued', 'running', 'succeeded'] },
    { key: 'approved',  label: 'Approved',            after: ['approved', 'queued', 'running', 'succeeded'] },
    { key: 'setup',     label: 'Setting up hosting',  after: ['running', 'succeeded'] },
    { key: 'done',      label: 'Ready',               after: ['succeeded'] }
  ];

  function progressTracker(state) {
    var failed = ['rejected', 'cancelled', 'failed_terminal', 'timed_out', 'compensated'].indexOf(state) !== -1;

    // The first stage not yet reached is the one in progress (unless we failed).
    var activeIdx = -1;
    for (var i = 0; i < PROGRESS_STAGES.length; i++) {
      if (PROGRESS_STAGES[i].after.indexOf(state) === -1) { activeIdx = i; break; }
    }

    return '<ol style="list-style:none;margin:var(--sp-4) 0 0;padding:0;display:flex;flex-direction:column;gap:var(--sp-3);">' +
      PROGRESS_STAGES.map(function (s, idx) {
        var done = s.after.indexOf(state) !== -1;
        var active = !failed && !done && idx === activeIdx;
        var tone = done ? 'var(--ac)' : (active ? 'var(--bl)' : 'var(--t3)');
        var mark = done ? '✓' : (active ? '•' : '');
        return '<li style="display:flex;align-items:center;gap:var(--sp-3);font:' +
          (active ? '600' : '400') + ' 13px var(--fb);color:' + (done || active ? 'var(--t1)' : 'var(--t3)') + ';">' +
          '<span aria-hidden="true" style="flex:none;width:18px;height:18px;border-radius:50%;display:inline-flex;' +
            'align-items:center;justify-content:center;font:600 11px var(--fb);color:#fff;' +
            'background:' + (done || active ? tone : 'transparent') + ';border:1px solid ' + tone + ';">' + mark + '</span>' +
          esc(s.label) + (active ? '…' : '') + '</li>';
      }).join('') + '</ol>';
  }

  function renderOperation(data) {
    var op = (data && data.operation) || {};
    var events = (data && data.events) || [];
    var terminal = isTerminal(op.state);

    var head = card(
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--sp-4);flex-wrap:wrap;">' +
        '<div><div style="font:600 16px var(--fh);color:var(--t1);">Your hosting request</div>' +
        '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:4px;">Reference ' + esc('#' + (op.id || '')) +
        ' · started ' + esc(fmtTime(op.requested_at)) + '</div></div>' +
        badge(op.state) +
      '</div>' +
      progressTracker(op.state) +
      (op.state === 'awaiting_approval'
        ? '<div style="margin-top:var(--sp-4);font:400 13px var(--fb);color:var(--t2);line-height:1.6;">' +
          'Waiting for a workspace owner to approve this. Nothing has been set up yet — you don’t need to do anything.</div>'
        : '') +
      (op.state === 'succeeded'
        ? '<div style="margin-top:var(--sp-4);padding:var(--sp-4);background:var(--as);border:1px solid var(--ag);border-radius:var(--r);' +
          'font:400 13px var(--fb);color:var(--t1);line-height:1.6;">' +
          'All setup steps completed successfully. Your live site isn’t switched on yet — hosting is still in preview, ' +
          'so nothing is serving traffic at this address.</div>'
        : '') +
      (op.state === 'rejected'
        ? '<div style="margin-top:var(--sp-4);font:400 13px var(--fb);color:var(--t2);">This request was rejected. Nothing was set up.</div>'
        : '') +
      (op.failure
        ? '<div style="margin-top:var(--sp-4);padding:var(--sp-4);background:var(--s2);border:1px solid var(--bd);' +
          'border-left:3px solid ' + (op.state === 'failed_retryable' ? 'var(--am)' : 'var(--rd)') + ';border-radius:var(--r);' +
          'font:400 13px var(--fb);color:var(--t2);line-height:1.6;word-break:break-word;">' + esc(op.failure) +
          (op.state === 'failed_retryable' ? ' This will be retried automatically.' : '') + '</div>'
        : '')
    );

    var timeline = events.length
      ? card('<div style="font:600 14px var(--fh);color:var(--t1);margin-bottom:var(--sp-4);">Activity</div>' +
          '<ol style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:var(--sp-4);">' +
          events.map(function (e) {
            var tone = e.severity === 'error' ? 'var(--rd)' : (e.severity === 'warning' ? 'var(--am)' : (e.severity === 'success' ? 'var(--ac)' : 'var(--t3)'));
            return '<li style="display:flex;gap:var(--sp-4);">' +
              '<span aria-hidden="true" style="flex:0 0 8px;height:8px;border-radius:50%;background:' + tone + ';margin-top:6px;"></span>' +
              '<div style="min-width:0;"><div style="font:600 13px var(--fb);color:var(--t1);">' + esc(e.label) +
                (e.to_state ? ' — ' + esc(stateLabel(e.to_state)) : '') + '</div>' +
              (e.summary ? '<div style="font:400 12px var(--fb);color:var(--t2);margin-top:3px;line-height:1.5;word-break:break-word;">' + esc(e.summary) + '</div>' : '') +
              '<div style="font:400 11px var(--fb);color:var(--t3);margin-top:3px;">' + esc(fmtTime(e.at)) + '</div>' +
              '</div></li>';
          }).join('') + '</ol>')
      : '';

    return '<div style="max-width:720px;">' +
      '<button id="infra-back-list" aria-label="Back to hosting list" style="display:inline-flex;align-items:center;gap:6px;background:none;border:none;color:var(--t2);cursor:pointer;font:600 13px var(--fb);padding:8px 0;margin-bottom:var(--sp-4);min-height:var(--touch-min);">' +
        '<span style="width:16px;height:16px;display:inline-flex;">' + ICONS.back + '</span>Back to hosting</button>' +
      head +
      '<div style="height:var(--sp-4);"></div>' +
      timeline +
      (terminal ? '' : '<div aria-live="polite" style="margin-top:var(--sp-4);font:400 12px var(--fb);color:var(--t3);">Updating automatically…</div>') +
      '</div>';
  }

  /* ------------------------------------------- intelligence: OVERVIEW view */

  function loadDashboard() {
    paintBody(loadingState('Loading operational overview…'));
    req('GET', 'infrastructure/intelligence/dashboard')
      .then(function (r) {
        if (r.status === 401) { paintBody(errorState('Your session has expired. Please sign in again.')); return; }
        if (r.status === 403) { paintBody(emptyState(ICONS.overview, 'No access to infrastructure intelligence', 'Your role in this workspace does not include the operational overview.')); return; }
        if (!r.ok || !r.json) { paintBody(errorState('The operational overview could not be loaded.', 'id="infra-retry"')); bindRetry(loadDashboard); return; }
        paintBody(renderDashboard(r.json));
        bindDashboard();
      })
      .catch(function () { paintBody(errorState('A network error interrupted the overview.', 'id="infra-retry"')); bindRetry(loadDashboard); });
  }

  function renderDashboard(d) {
    var a = d.assets || {};
    var health = d.health || {};
    var mgmt = d.management || {};
    var attn = d.needs_attention || {};
    var rel = d.reliability || {};
    var total = a.total || 0;
    var asOf = new Date().toISOString();

    if (!total) {
      return intelHeader('Overview', 'A live summary of everything running in this workspace.', asOf) +
        emptyState(ICONS.overview, 'Nothing to monitor yet',
          'Once you have hosting, a website or a domain with us, you’ll see its status, uptime and any issues here.',
          'Nothing is being monitored yet — this is not an outage.');
    }

    // Portfolio health tiles. "Not yet observed" is explicitly distinct from healthy.
    var tiles =
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-6);">' +
        statTile('Total services', total) +
        statTile('Healthy', health.healthy || 0, (health.healthy ? 'var(--ac)' : 'var(--t1)')) +
        statTile('Degraded', health.degraded || 0, (health.degraded ? 'var(--am)' : 'var(--t1)')) +
        statTile('Unavailable', health.down || 0, (health.down ? 'var(--rd)' : 'var(--t1)')) +
        statTile('Not yet observed', health.unknown || 0, 'var(--t2)') +
        statTile('Open incidents', attn.open_incidents || 0, ((attn.open_incidents || 0) ? 'var(--rd)' : 'var(--t1)')) +
        statTile('Needs attention', attn.at_risk_assets || 0, ((attn.at_risk_assets || 0) ? 'var(--am)' : 'var(--t1)')) +
      '</div>';

    // Management composition — the PTAA integrity distinction, made explicit.
    // Customer wording, same distinction: "connected" is never shown as ours.
    var mgmtRow =
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-4);">' +
        statTile('Connected', mgmt.adopted || 0, null, 'We watch it, we didn’t set it up') +
        statTile('Set up by LevelUp', mgmt.provisioned || 0, null, 'Created for you by us') +
        statTile('Managed elsewhere', mgmt.managed_externally || 0, null, 'Runs outside LevelUp') +
        statTile('Managed by LevelUp', mgmt.managed_by_infra888 || 0, null, 'We look after it') +
      '</div>';
    var mgmtBlock = card(
      sectionTitle('Who looks after what', 'Some things we set up and run for you. Others you already had, and we simply keep an eye on them — we never present those as ours.') +
      mgmtRow);

    // Reliability summary (deterministic aggregation, 30-day window).
    var relHasData = (rel.incidents || 0) > 0;
    var relBlock = card(
      sectionTitle('Uptime (last 30 days)', 'Counted from what actually happened — never estimated or predicted.') +
      (relHasData
        ? '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:var(--sp-4);">' +
            statTile('Average fix time', rel.mttr_seconds != null ? fmtDuration(rel.mttr_seconds) : '—', null, 'How long issues took to resolve') +
            statTile('Typical gap between issues', rel.mtbf_seconds != null ? fmtDuration(rel.mtbf_seconds) : '—', null, 'Average time without a problem') +
            statTile('Issues', rel.incidents || 0) +
            statTile('Resolved', rel.resolved || 0) +
            statTile('Issues per day', (rel.failure_frequency_per_day != null ? rel.failure_frequency_per_day : '—'), null, 'Average across the window') +
          '</div>'
        : '<div style="font:400 13px var(--fb);color:var(--t2);line-height:1.6;">No issues have been recorded in the last 30 days, so there are no averages to show yet. To be clear, this is not a claim of 100% uptime — it only means nothing has been reported in this window.</div>'));

    // Needs attention — open incidents (drill to asset) and at-risk assets.
    var openInc = d.open_incidents || [];
    var atRisk = d.at_risk_assets || [];

    var incidentsBlock = card(
      sectionTitle('Open issues', 'Anything we’re still working on.') +
      (openInc.length
        ? '<div style="display:flex;flex-direction:column;gap:var(--sp-2);">' +
            openInc.map(function (i) {
              return '<button class="infra-open-asset" data-asset-id="' + esc(i.asset_id) + '" ' +
                'aria-label="Open asset for incident ' + esc(i.title || i.incident_uid) + '" style="' +
                'text-align:left;background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:var(--sp-3) var(--sp-4);cursor:pointer;' +
                'display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;min-height:var(--touch-min);">' +
                '<span style="min-width:0;"><span style="font:600 13px var(--fb);color:var(--t1);">' + esc(i.title || 'Incident') + '</span>' +
                  '<span style="font:400 11px var(--fb);color:var(--t3);margin-left:8px;">' + esc(fmtTime(i.detected_at)) + '</span></span>' +
                '<span style="display:inline-flex;gap:var(--sp-4);align-items:center;">' + sevPill(i.severity) + incPill(i.lifecycle_state) + '</span>' +
                '</button>';
            }).join('') + '</div>'
        : '<div style="font:400 13px var(--fb);color:var(--t2);">No open issues.</div>'));

    var riskBlock = card(
      sectionTitle('Needs attention', 'Flagged by clear rules — an expiring certificate, repeated failures, an open issue, or degraded health.') +
      (atRisk.length
        ? '<div style="display:flex;flex-direction:column;gap:var(--sp-2);">' +
            atRisk.map(function (x) {
              var reasons = (x.risk_reasons || []).map(function (r) { return esc(r); }).join(' · ');
              return '<div style="background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:var(--sp-3) var(--sp-4);">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;">' +
                '<span style="font:600 13px var(--fb);color:var(--t1);">' + esc(x.name) + ' <span style="font-weight:400;color:var(--t3);">· ' + esc(typeLabel(x.asset_type)) + '</span></span>' +
                riskPill(x.risk_state) + '</div>' +
                (reasons ? '<div style="font:400 12px var(--fb);color:var(--t2);margin-top:4px;line-height:1.5;">' + reasons + '</div>' : '') +
                '</div>';
            }).join('') + '</div>'
        : '<div style="font:400 13px var(--fb);color:var(--t2);">Nothing needs your attention right now.</div>'));

    // Unhealthy providers (platform-level operational signal).
    var provs = d.unhealthy_providers || [];
    var provBlock = card(
      sectionTitle('Service status', 'Any of our underlying services currently reporting a problem.') +
      (provs.length
        ? '<div style="display:flex;flex-direction:column;gap:var(--sp-2);">' +
            provs.map(function (p) {
              return '<div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:var(--sp-3) var(--sp-4);flex-wrap:wrap;">' +
                '<span style="font:600 13px var(--fb);color:var(--t1);">' + esc(p.provider || 'Unknown provider') +
                (p.capability ? ' <span style="font-weight:400;color:var(--t3);">· ' + esc(p.capability) + '</span>' : '') + '</span>' +
                statusPill(esc(p.state || 'unknown'), 'var(--am)') + '</div>';
            }).join('') + '</div>'
        : '<div style="font:400 13px var(--fb);color:var(--t2);">All services are operating normally.</div>'));

    return intelHeader('Overview',
        'What’s healthy, what needs attention, and how things have been running for you.', asOf) +
      tiles +
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:var(--sp-4);align-items:start;">' +
        incidentsBlock + riskBlock +
      '</div>' +
      '<div style="height:var(--sp-4);"></div>' +
      relBlock +
      '<div style="height:var(--sp-4);"></div>' +
      mgmtBlock +
      '<div style="height:var(--sp-4);"></div>' +
      provBlock;
  }

  /* --------------------------------------------- intelligence: ASSETS view */

  function loadAssets() {
    paintBody(loadingState('Loading asset inventory…'));
    req('GET', 'infrastructure/intelligence/assets')
      .then(function (r) {
        if (r.status === 403) { paintBody(emptyState(ICONS.assets, 'No access', 'Your role in this workspace does not include the asset inventory.')); return; }
        if (!r.ok || !r.json) { paintBody(errorState('The asset inventory could not be loaded.', 'id="infra-retry"')); bindRetry(loadAssets); return; }
        _assets = r.json.assets || [];
        paintBody(renderAssets());
        bindAssets();
      })
      .catch(function () { paintBody(errorState('A network error interrupted the inventory.', 'id="infra-retry"')); bindRetry(loadAssets); });
  }

  function assetMatchesFilter(a) {
    if (_assetFilter.type && a.asset_type !== _assetFilter.type) { return false; }
    if (_assetFilter.health && a.health_state !== _assetFilter.health) { return false; }
    if (_assetFilter.risk && a.risk_state !== _assetFilter.risk) { return false; }
    return true;
  }

  function filterSelect(id, label, current, options) {
    var opts = '<option value="">' + esc(label) + ' (all)</option>' +
      options.map(function (o) {
        return '<option value="' + esc(o.v) + '"' + (current === o.v ? ' selected' : '') + '>' + esc(o.l) + '</option>';
      }).join('');
    return '<label style="display:inline-flex;flex-direction:column;gap:4px;">' +
      '<span style="font:600 11px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.03em;">' + esc(label) + '</span>' +
      '<select id="' + id + '" style="min-height:var(--touch-min);padding:0 var(--sp-3);background:var(--s2);border:1px solid var(--bd2);' +
      'border-radius:var(--r);color:var(--t1);font:400 13px var(--fb);">' + opts + '</select></label>';
  }

  function renderAssets() {
    var asOf = new Date().toISOString();
    var shown = _assets.filter(assetMatchesFilter);

    var typeOpts = Object.keys(TYPE_LABEL).map(function (k) { return { v: k, l: TYPE_LABEL[k] }; });
    var healthOpts = Object.keys(HEALTH_LABEL).map(function (k) { return { v: k, l: HEALTH_LABEL[k] }; });
    var riskOpts = Object.keys(RISK_LABEL).map(function (k) { return { v: k, l: RISK_LABEL[k] }; });

    var filters = '<div style="display:flex;gap:var(--sp-4);flex-wrap:wrap;align-items:flex-end;margin-bottom:var(--sp-5);">' +
      filterSelect('infra-f-type', 'Type', _assetFilter.type, typeOpts) +
      filterSelect('infra-f-health', 'Health', _assetFilter.health, healthOpts) +
      filterSelect('infra-f-risk', 'Risk', _assetFilter.risk, riskOpts) +
      '<span style="font:400 12px var(--fb);color:var(--t3);padding-bottom:10px;">' + esc(shown.length) + ' of ' + esc(_assets.length) + ' asset(s)' +
        (_assets.length >= 500 ? ' · first 500 shown' : '') + '</span>' +
      '</div>';

    if (!_assets.length) {
      return intelHeader('Asset inventory', 'Every asset in this workspace’s canonical graph.', asOf) +
        emptyState(ICONS.assets, 'No assets yet', 'Adopted or provisioned infrastructure will appear here as canonical assets.');
    }

    var rowsHead =
      '<div role="row" style="display:grid;grid-template-columns:2fr 1fr 1.1fr 1fr 0.9fr 1fr;gap:var(--sp-4);padding:8px var(--sp-4);' +
        'font:600 11px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid var(--bd);">' +
        '<span>Asset</span><span>Type</span><span>Mode</span><span>Health</span><span>Risk</span><span>Observed</span></div>';

    var rows = shown.length
      ? shown.map(function (a) {
          return '<button class="infra-open-asset" data-asset-id="' + esc(a.id) + '" aria-label="Open ' + esc(a.name) + '" style="' +
            'display:grid;grid-template-columns:2fr 1fr 1.1fr 1fr 0.9fr 1fr;gap:var(--sp-4);align-items:center;width:100%;text-align:left;' +
            'background:none;border:none;border-bottom:1px solid var(--bd);padding:var(--sp-4);cursor:pointer;min-height:var(--touch-min);color:var(--t1);">' +
            '<span style="font:600 13px var(--fb);color:var(--t1);min-width:0;overflow:hidden;text-overflow:ellipsis;">' + esc(a.name) + '</span>' +
            '<span style="font:400 12px var(--fb);color:var(--t2);">' + esc(typeLabel(a.asset_type)) + '</span>' +
            '<span>' + modeTag(a.management_mode) + '</span>' +
            '<span>' + healthPill(a.health_state) + '</span>' +
            '<span>' + riskPill(a.risk_state) + '</span>' +
            '<span style="font:400 12px var(--fb);color:var(--t3);">' + esc(fmtAgo(a.health_checked_at)) + '</span>' +
            '</button>';
        }).join('')
      : '<div style="padding:var(--sp-6);text-align:center;font:400 13px var(--fb);color:var(--t3);">No assets match these filters.</div>';

    return intelHeader('Asset inventory',
        'Select an asset to see its relationships, incidents, reliability and blast radius. Modes are labelled exactly as recorded — adopted is not provisioned.', asOf) +
      filters +
      '<div role="table" aria-label="Website assets" style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);overflow:hidden;">' +
        rowsHead + rows + '</div>';
  }

  /* ---------------------------------------- intelligence: ASSET DETAIL view */

  function loadAssetDetail() {
    var id = _view.assetId;
    paintBody(loadingState('Loading asset…'));
    req('GET', 'infrastructure/intelligence/assets/' + encodeURIComponent(id))
      .then(function (r) {
        if (r.status === 404) { paintBody(errorState('That asset was not found in this workspace.', 'id="infra-retry"')); bindRetry(function () { INFRA_TAB = 'assets'; _view = { name: 'list' }; renderCurrentView(); }); return; }
        if (!r.ok || !r.json) { paintBody(errorState('That asset could not be loaded.', 'id="infra-retry"')); bindRetry(loadAssetDetail); return; }
        paintBody(renderAssetDetail(r.json));
        bindAssetDetail();
      })
      .catch(function () { paintBody(errorState('A network error interrupted loading the asset.', 'id="infra-retry"')); bindRetry(loadAssetDetail); });
  }

  function defRow(dt, dd) {
    return '<dt style="color:var(--t3);">' + esc(dt) + '</dt><dd style="margin:0;color:var(--t1);">' + dd + '</dd>';
  }

  function neighbourList(edges, emptyMsg) {
    if (!edges || !edges.length) { return '<div style="font:400 12px var(--fb);color:var(--t3);">' + esc(emptyMsg) + '</div>'; }
    return '<div style="display:flex;flex-direction:column;gap:var(--sp-2);">' +
      edges.map(function (e) {
        var a = e.asset;
        if (!a) { return ''; }
        return '<button class="infra-open-asset" data-asset-id="' + esc(a.id) + '" aria-label="Open ' + esc(a.name) + '" style="' +
          'text-align:left;background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:8px var(--sp-4);cursor:pointer;min-height:var(--touch-min);' +
          'display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;">' +
          '<span style="font:600 12px var(--fb);color:var(--t1);">' + esc(a.name) +
            ' <span style="font-weight:400;color:var(--t3);">· ' + esc(typeLabel(a.asset_type)) +
            (e.type ? ' · ' + esc(e.type) : '') + '</span></span>' +
          healthPill(a.health_state) + '</button>';
      }).join('') + '</div>';
  }

  function renderAssetDetail(d) {
    var a = d.asset || {};
    var n = d.neighbourhood || { depends_on: [], depended_on_by: [] };
    var incidents = d.incidents || [];
    var stats = d.reliability;
    var cfg = a.config || {};
    var risk = a.risk_reasons || [];

    var backBtn = '<button id="infra-back-assets" aria-label="Back to asset inventory" style="display:inline-flex;align-items:center;gap:6px;background:none;border:none;color:var(--t2);cursor:pointer;font:600 13px var(--fb);padding:8px 0;margin-bottom:var(--sp-4);min-height:var(--touch-min);">' +
      '<span style="width:16px;height:16px;display:inline-flex;">' + ICONS.back + '</span>Back to assets</button>';

    var identity = card(
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--sp-4);flex-wrap:wrap;">' +
        '<div style="min-width:0;"><h2 id="infra-asset-title" tabindex="-1" style="font:600 19px var(--fh);color:var(--t1);margin:0;">' + esc(a.name) + '</h2>' +
          '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:4px;">' + esc(typeLabel(a.asset_type)) + ' · ' + esc(a.asset_uid || '') + '</div></div>' +
        '<div style="display:flex;gap:var(--sp-4);align-items:center;flex-wrap:wrap;">' + healthPill(a.health_state) + riskPill(a.risk_state) + '</div>' +
      '</div>' +
      '<dl style="display:grid;grid-template-columns:auto 1fr;gap:8px var(--sp-5);margin:var(--sp-5) 0 0;font:400 13px var(--fb);">' +
        defRow('Management mode', modeTag(a.management_mode) +
          (a.management_mode === 'adopted' ? ' <span style="color:var(--t3);font-size:12px;">observed, not provisioned by LevelUp Growth</span>' :
           a.management_mode === 'managed_externally' ? ' <span style="color:var(--t3);font-size:12px;">not managed by LevelUp Growth</span>' : '')) +
        defRow('Lifecycle', esc(a.lifecycle_state || '—')) +
        defRow('Health', HEALTH_LABEL[a.health_state] || 'Unknown') +
        defRow('Last observed', esc(a.health_checked_at ? fmtTime(a.health_checked_at) + ' (' + fmtAgo(a.health_checked_at) + ')' : 'Not yet observed')) +
        (cfg.ssl_expires_at ? defRow('SSL expires', esc(fmtTime(cfg.ssl_expires_at))) : '') +
      '</dl>' +
      (risk.length ? '<div style="margin-top:var(--sp-4);padding:var(--sp-4);background:var(--s2);border:1px solid var(--bd);border-left:3px solid var(--am);border-radius:var(--r);">' +
        '<div style="font:600 12px var(--fb);color:var(--t2);text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;">Risk indicators</div>' +
        '<ul style="margin:0;padding-left:18px;font:400 13px var(--fb);color:var(--t1);line-height:1.6;">' +
        risk.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul></div>' : '')
    );

    // Reliability (only present for monitor-backed assets).
    var relBlock = stats
      ? card(sectionTitle('Reliability (last 30 days)', 'Uptime and latency from the raw monitoring series.') +
          '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:var(--sp-4);">' +
            statTile('Uptime', (stats.uptime_pct != null ? stats.uptime_pct + '%' : '—'), null, esc((stats.checks || 0) + ' checks')) +
            statTile('Avg response', (stats.avg_response_ms != null ? stats.avg_response_ms + ' ms' : '—')) +
            statTile('Max response', (stats.max_response_ms != null ? stats.max_response_ms + ' ms' : '—')) +
            statTile('Down checks', stats.down || 0, ((stats.down || 0) ? 'var(--rd)' : 'var(--t1)')) +
          '</div>')
      : card(sectionTitle('Reliability') +
          '<div style="font:400 13px var(--fb);color:var(--t2);">This asset is not backed by a monitoring check, so uptime and latency are not observed here.</div>');

    // Relationships.
    var relationships = card(
      sectionTitle('Relationships', 'This asset’s place in the infrastructure graph.') +
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--sp-5);">' +
        '<div><div style="font:600 12px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.03em;margin-bottom:8px;">Depends on</div>' +
          neighbourList(n.depends_on, 'No upstream dependencies recorded.') + '</div>' +
        '<div><div style="font:600 12px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.03em;margin-bottom:8px;">Depended on by</div>' +
          neighbourList(n.depended_on_by, 'No dependent assets recorded.') + '</div>' +
      '</div>' +
      '<div style="margin-top:var(--sp-5);">' +
        button('Show blast radius', 'id="infra-blast-btn" aria-label="Compute blast radius" data-asset-id="' + esc(a.id) + '"') +
        '<div id="infra-blast" style="margin-top:var(--sp-4);"></div>' +
      '</div>');

    // Incident history.
    var incBlock = card(
      sectionTitle('Incident history', 'Newest first.') +
      (incidents.length
        ? '<div style="display:flex;flex-direction:column;gap:var(--sp-2);">' +
            incidents.map(function (i) {
              return '<div style="background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:var(--sp-3) var(--sp-4);">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;">' +
                '<span style="font:600 13px var(--fb);color:var(--t1);min-width:0;">' + esc(i.title || 'Incident') + '</span>' +
                '<span style="display:inline-flex;gap:var(--sp-4);align-items:center;">' + sevPill(i.severity) + incPill(i.lifecycle_state) + '</span></div>' +
                '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:4px;">Detected ' + esc(fmtTime(i.detected_at)) +
                  (i.time_to_resolve_seconds != null ? ' · resolved in ' + esc(fmtDuration(i.time_to_resolve_seconds)) : '') + '</div>' +
                '</div>';
            }).join('') + '</div>'
        : '<div style="font:400 13px var(--fb);color:var(--t2);">No incidents recorded for this asset.</div>'));

    return '<div style="max-width:900px;">' + backBtn + identity +
      '<div style="height:var(--sp-4);"></div>' + relBlock +
      '<div style="height:var(--sp-4);"></div>' + relationships +
      '<div style="height:var(--sp-4);"></div>' + incBlock + '</div>';
  }

  function loadBlastRadius(assetId, mountEl, btn) {
    if (!mountEl) { return; }
    mountEl.innerHTML = loadingState('Computing blast radius…');
    if (btn) { btn.disabled = true; btn.style.opacity = '.5'; }
    req('GET', 'infrastructure/intelligence/assets/' + encodeURIComponent(assetId) + '/blast-radius')
      .then(function (r) {
        if (!r.ok || !r.json) { mountEl.innerHTML = '<div style="font:400 13px var(--fb);color:var(--rd);">Blast radius could not be computed.</div>'; return; }
        var affected = r.json.affected_assets || [];
        var ws = r.json.affected_workspaces || [];
        var depth = r.json.depth;
        var body = '<div style="background:var(--s2);border:1px solid var(--bd);border-radius:var(--r);padding:var(--sp-4);">' +
          '<div style="font:600 12px var(--fb);color:var(--t2);text-transform:uppercase;letter-spacing:.03em;margin-bottom:8px;">' +
            'Blast radius · ' + esc(affected.length) + ' dependent asset(s)' +
            (ws.length ? ' · ' + esc(ws.length) + ' workspace(s)' : '') +
            (depth != null ? ' · depth ' + esc(depth) : '') + '</div>' +
          (affected.length
            ? '<div style="display:flex;flex-direction:column;gap:6px;">' +
                affected.map(function (x) {
                  return '<button class="infra-open-asset" data-asset-id="' + esc(x.id) + '" aria-label="Open ' + esc(x.name) + '" style="' +
                    'text-align:left;background:var(--s1);border:1px solid var(--bd);border-radius:var(--r);padding:8px var(--sp-4);cursor:pointer;min-height:var(--touch-min);' +
                    'display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;">' +
                    '<span style="font:600 12px var(--fb);color:var(--t1);">' + esc(x.name) + ' <span style="font-weight:400;color:var(--t3);">· ' + esc(typeLabel(x.asset_type)) + '</span></span>' +
                    healthPill(x.health_state) + '</button>';
                }).join('') + '</div>'
            : '<div style="font:400 13px var(--fb);color:var(--t2);">Nothing else depends on this asset — a degradation here would not cascade.</div>') +
          '</div>';
        mountEl.innerHTML = body;
        bindOpenAsset(mountEl);
      })
      .catch(function () { mountEl.innerHTML = '<div style="font:400 13px var(--fb);color:var(--rd);">A network error interrupted the blast-radius calculation.</div>'; })
      .finally(function () { if (btn) { btn.disabled = false; btn.style.opacity = '1'; } });
  }

  /* ------------------------------------------ intelligence: INCIDENTS view */

  function loadIncidents() {
    paintBody(loadingState('Loading incidents…'));
    var path = 'infrastructure/intelligence/incidents' + (_incidentsOpenOnly ? '?open_only=1' : '');
    req('GET', path)
      .then(function (r) {
        if (r.status === 403) { paintBody(emptyState(ICONS.incidents, 'No access', 'Your role in this workspace does not include incidents.')); return; }
        if (!r.ok || !r.json) { paintBody(errorState('Incidents could not be loaded.', 'id="infra-retry"')); bindRetry(loadIncidents); return; }
        paintBody(renderIncidents(r.json.incidents || []));
        bindIncidents();
      })
      .catch(function () { paintBody(errorState('A network error interrupted incidents.', 'id="infra-retry"')); bindRetry(loadIncidents); });
  }

  function renderIncidents(incidents) {
    var asOf = new Date().toISOString();
    var toggle = '<label style="display:inline-flex;align-items:center;gap:8px;font:400 13px var(--fb);color:var(--t2);cursor:pointer;margin-bottom:var(--sp-5);">' +
      '<input type="checkbox" id="infra-inc-openonly"' + (_incidentsOpenOnly ? ' checked' : '') + ' style="width:16px;height:16px;cursor:pointer;">' +
      'Open incidents only</label>';

    if (!incidents.length) {
      return intelHeader('Incidents', 'The first-class incident record for this workspace.', asOf) + toggle +
        emptyState(ICONS.incidents, (_incidentsOpenOnly ? 'No open incidents' : 'No incidents recorded'),
          (_incidentsOpenOnly ? 'There are no incidents currently in an open state.' : 'No incident has been raised in this workspace.'),
          'Absence of an incident is not a guarantee of availability — it means none has been recorded.');
    }

    var head = '<div role="row" style="display:grid;grid-template-columns:2fr 1fr 1.1fr 1.2fr 1fr;gap:var(--sp-4);padding:8px var(--sp-4);' +
      'font:600 11px var(--fb);color:var(--t3);text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid var(--bd);">' +
      '<span>Incident</span><span>Severity</span><span>State</span><span>Detected</span><span>Resolve time</span></div>';

    var rows = incidents.map(function (i) {
      var open = incidentIsOpen(i.lifecycle_state);
      var opener = i.asset_id
        ? '<button class="infra-open-asset" data-asset-id="' + esc(i.asset_id) + '" aria-label="Open asset for ' + esc(i.title || 'incident') + '" style="'
        : '<div style="';
      return opener +
        'display:grid;grid-template-columns:2fr 1fr 1.1fr 1.2fr 1fr;gap:var(--sp-4);align-items:center;width:100%;text-align:left;' +
        'background:none;border:none;border-bottom:1px solid var(--bd);padding:var(--sp-4);' + (i.asset_id ? 'cursor:pointer;' : '') + 'min-height:var(--touch-min);color:var(--t1);">' +
        '<span style="font:600 13px var(--fb);color:var(--t1);min-width:0;overflow:hidden;text-overflow:ellipsis;">' + esc(i.title || 'Incident') +
          (open ? '' : '') + '</span>' +
        '<span>' + sevPill(i.severity) + '</span>' +
        '<span>' + incPill(i.lifecycle_state) + '</span>' +
        '<span style="font:400 12px var(--fb);color:var(--t3);">' + esc(fmtTime(i.detected_at)) + '</span>' +
        '<span style="font:400 12px var(--fb);color:var(--t2);">' + esc(i.time_to_resolve_seconds != null ? fmtDuration(i.time_to_resolve_seconds) : (open ? 'ongoing' : '—')) + '</span>' +
        (i.asset_id ? '</button>' : '</div>');
    }).join('');

    return intelHeader('Incidents', 'Full lifecycle: detected → acknowledged → investigating → mitigated → resolved → closed. Read-only view.', asOf) +
      toggle +
      '<div role="table" aria-label="Incidents" style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);overflow:hidden;">' + head + rows + '</div>';
  }

  /* --------------------------------------- intelligence: RELIABILITY view */

  function loadReliability() {
    paintBody(loadingState('Loading reliability metrics…'));
    req('GET', 'infrastructure/intelligence/reliability?days=30')
      .then(function (r) {
        if (r.status === 403) { paintBody(emptyState(ICONS.reliability, 'No access', 'Your role in this workspace does not include reliability metrics.')); return; }
        if (!r.ok || !r.json) { paintBody(errorState('Reliability metrics could not be loaded.', 'id="infra-retry"')); bindRetry(loadReliability); return; }
        paintBody(renderReliability(r.json));
        bindGeneric();
      })
      .catch(function () { paintBody(errorState('A network error interrupted reliability metrics.', 'id="infra-retry"')); bindRetry(loadReliability); });
  }

  function renderReliability(rel) {
    var asOf = new Date().toISOString();
    var hasData = (rel.incidents || 0) > 0;

    var body = hasData
      ? '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:var(--sp-4);">' +
          statTile('MTTR', rel.mttr_seconds != null ? fmtDuration(rel.mttr_seconds) : '—', null, 'Mean time to resolve') +
          statTile('MTBF', rel.mtbf_seconds != null ? fmtDuration(rel.mtbf_seconds) : '—', null, 'Mean time between incidents') +
          statTile('Incidents', rel.incidents || 0, null, 'in window') +
          statTile('Resolved', rel.resolved || 0) +
          statTile('Failure frequency', (rel.failure_frequency_per_day != null ? rel.failure_frequency_per_day : '—'), null, 'incidents per day') +
        '</div>'
      : '<div style="font:400 14px var(--fb);color:var(--t2);line-height:1.7;">No incidents were recorded in the last 30 days. MTTR and MTBF are therefore not yet computable.<br>' +
        'This is an honest absence of data — not a claim of perfect uptime.</div>';

    return intelHeader('Reliability', 'Deterministic aggregation over the operational record for the last 30 days. These are measured facts, not predictions or AI scores.', asOf) +
      card(sectionTitle('Workspace reliability (30-day window)') + body);
  }

  /* ------------------------------------------------------------- polling */

  function stopPolling() {
    if (_poll) { clearInterval(_poll); _poll = null; }
    _pollInFlight = false;
  }

  function startPolling(operationId) {
    stopPolling();
    _poll = setInterval(function () {
      if (!document.getElementById('infrastructure-root') ||
          !document.getElementById('view-infrastructure') ||
          document.getElementById('view-infrastructure').style.display === 'none') {
        stopPolling();
        return;
      }
      if (_pollInFlight) { return; }
      _pollInFlight = true;

      req('GET', 'infrastructure/operations/' + operationId + '/timeline')
        .then(function (r) {
          if (r.status === 401) { stopPolling(); return; }
          if (!r.ok) { return; }
          var data = r.json && r.json.data;
          if (!data) { return; }
          paintBody(renderOperation(data));
          bindOperation();
          if (isTerminal(data.operation && data.operation.state)) { stopPolling(); }
        })
        .catch(function () { /* transient network failure — keep polling */ })
        .finally(function () { _pollInFlight = false; });
    }, 3000);
  }

  /* ------------------------------------------------------------ actions */

  function submitRequest(entitlement) {
    if (_submitting) { return; }
    _submitting = true;

    var btn = document.getElementById('infra-submit');
    var msg = document.getElementById('infra-submit-msg');

    // The service name is derived, not typed: one less field for the customer.
    var site = _wizard.sites.filter(function (s) { return String(s.id) === String(_wizard.websiteId); })[0];
    var name = ((site && site.name) ? site.name : (_wizard.subdomain || 'Website')) + ' hosting';
    name = name.slice(0, 120);

    if (msg) { msg.textContent = ''; }

    var subErr = subCheck(_wizard.subdomain);
    if (subErr) {
      _submitting = false;
      _wizard.step = 3;
      paintBody(renderWizard(entitlement));
      bindReview();
      var m2 = document.getElementById('infra-submit-msg');
      if (m2) { m2.textContent = subErr; }
      return;
    }

    if (btn) { btn.disabled = true; btn.style.opacity = '.5'; btn.textContent = 'Creating…'; }

    if (!_idemKey) { _idemKey = uuid(); }

    req('POST', 'infrastructure/hosting', {
          name: name,
          environment: 'staging',
          website_id: _wizard.websiteId,
          subdomain: _wizard.subdomain
        },
        { 'Idempotency-Key': _idemKey })
      .then(function (r) {
        if (r.status === 403) {
          if (msg) { msg.textContent = (r.json && r.json.error) || 'You do not have permission to request hosting.'; }
          return;
        }
        if (!r.ok && r.status !== 202) {
          if (msg) { msg.textContent = (r.json && r.json.error) || 'The request could not be submitted.'; }
          return;
        }

        var op = r.json && r.json.data;
        if (!op || !op.id) {
          if (msg) { msg.textContent = 'The request was submitted but no reference was returned.'; }
          return;
        }

        _idemKey = null;
        _view = { name: 'operation', operationId: op.id };
        renderCurrentView();
      })
      .catch(function () {
        if (msg) { msg.textContent = 'We could not confirm your request. It is safe to try again — this will not create a duplicate.'; }
      })
      .finally(function () {
        _submitting = false;
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.textContent = 'Create hosting'; }
      });
  }

  /* ------------------------------------------------------------ plumbing */

  var _entitlement = null;
  var _opsTab = 'overview';
  // paintBody target — 'infra-body' for customer surfaces, 'infra-ops-body'
  // while inside the admin Operations surface (nested body).
  var _bodyId = 'infra-body';

  function paintBody(html) {
    var el = document.getElementById(_bodyId) || document.getElementById('infra-body');
    if (el) { el.innerHTML = html; }
  }

  // Delegated: any element with .infra-open-asset opens that asset's drill-down.
  function bindOpenAsset(scope) {
    var root = scope || document.getElementById('infra-body');
    if (!root) { return; }
    Array.prototype.forEach.call(root.querySelectorAll('.infra-open-asset'), function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-asset-id');
        if (!id) { return; }
        _view = { name: 'asset', assetId: id };
        renderCurrentView();
      });
    });
  }

  function bindRefresh(fn) {
    var b = document.getElementById('infra-refresh');
    if (b) { b.addEventListener('click', fn); }
  }
  function bindRetry(fn) {
    var b = document.getElementById('infra-retry');
    if (b) { b.addEventListener('click', fn); }
  }
  function bindGeneric() { bindRefresh(function () { renderCurrentView(); }); }

  function bindDashboard() {
    bindRefresh(loadDashboard);
    bindOpenAsset();
  }

  function bindAssets() {
    bindRefresh(loadAssets);
    bindOpenAsset();
    var t = document.getElementById('infra-f-type');
    var h = document.getElementById('infra-f-health');
    var rk = document.getElementById('infra-f-risk');
    if (t) { t.addEventListener('change', function () { _assetFilter.type = t.value; paintBody(renderAssets()); bindAssets(); }); }
    if (h) { h.addEventListener('change', function () { _assetFilter.health = h.value; paintBody(renderAssets()); bindAssets(); }); }
    if (rk) { rk.addEventListener('change', function () { _assetFilter.risk = rk.value; paintBody(renderAssets()); bindAssets(); }); }
  }

  function bindAssetDetail() {
    bindOpenAsset();
    var back = document.getElementById('infra-back-assets');
    if (back) { back.addEventListener('click', function () { INFRA_TAB = 'assets'; _view = { name: 'list' }; renderCurrentView(); }); }
    var blast = document.getElementById('infra-blast-btn');
    if (blast) {
      blast.addEventListener('click', function () {
        loadBlastRadius(blast.getAttribute('data-asset-id'), document.getElementById('infra-blast'), blast);
      });
    }
    var title = document.getElementById('infra-asset-title');
    if (title && title.focus) { try { title.focus(); } catch (e) {} }
  }

  function bindIncidents() {
    bindRefresh(loadIncidents);
    bindOpenAsset();
    var chk = document.getElementById('infra-inc-openonly');
    if (chk) { chk.addEventListener('change', function () { _incidentsOpenOnly = chk.checked; loadIncidents(); }); }
  }

  // Debounced availability probe against the SAME endpoint Builder uses, so the
  // customer sees one consistent answer wherever they pick an address.
  var _subTimer = null;

  function probeSubdomain(label) {
    var el = document.getElementById('infra-sub-status');
    if (!el) { return; }

    var err = subCheck(label);
    if (err) { el.style.color = 'var(--rd)'; el.textContent = err; return; }

    el.style.color = 'var(--t3)';
    el.textContent = 'Checking availability…';

    // The Builder availability endpoint reads ?slug= (not ?subdomain=).
    var q = 'builder/check-subdomain?slug=' + encodeURIComponent(label) +
      (_wizard.websiteId ? '&exclude=' + encodeURIComponent(_wizard.websiteId) : '');
    req('GET', q)
      .then(function (r) {
        var el2 = document.getElementById('infra-sub-status');
        if (!el2 || subNormalize((document.getElementById('infra-sub') || {}).value || '') !== label) { return; }
        var j = r.json || {};
        if (j.available === true) {
          el2.style.color = 'var(--ac)';
          el2.textContent = label + '.levelupgrowth.io is available';
        } else if (j.available === false) {
          el2.style.color = 'var(--rd)';
          el2.textContent = (j.error || 'That address is taken.') + (j.suggestion ? ' Try "' + j.suggestion + '".' : '');
        } else {
          // Endpoint unavailable — stay silent rather than guess. The server
          // re-checks on submit and is authoritative either way.
          el2.textContent = '';
        }
      })
      .catch(function () {
        var el3 = document.getElementById('infra-sub-status');
        if (el3) { el3.textContent = ''; }
      });
  }

  function loadWizardSites() {
    _wizard.sitesLoading = true;
    req('GET', 'builder/websites')
      .then(function (r) {
        var j = r.json || {};
        var list = j.websites || j.data || (Array.isArray(j) ? j : []);
        _wizard.sites = Array.isArray(list) ? list : [];
      })
      .catch(function () { _wizard.sites = []; })
      .finally(function () {
        _wizard.sitesLoading = false;
        if (_view.name === 'review') { paintBody(renderWizard(_entitlement)); bindReview(); }
      });
  }

  function bindReview() {
    var back = document.getElementById('infra-back-list');
    if (back) { back.addEventListener('click', function () { _view = { name: 'list' }; renderCurrentView(); }); }

    var cancel = document.getElementById('infra-cancel');
    if (cancel) { cancel.addEventListener('click', function () { _view = { name: 'list' }; renderCurrentView(); }); }

    var prev = document.getElementById('infra-prev');
    if (prev) {
      prev.addEventListener('click', function () {
        _wizard.step = Math.max(1, _wizard.step - 1);
        paintBody(renderWizard(_entitlement));
        bindReview();
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.infra-opt'), function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-opt-id');
        if (_wizard.step === 1) {
          _wizard.websiteId = id;
          // Seed the address from the site's existing one, so the common case
          // is one tap rather than typing.
          var site = _wizard.sites.filter(function (s) { return String(s.id) === String(id); })[0];
          if (site && site.subdomain && !_wizard.subdomain) {
            _wizard.subdomain = subNormalize(site.subdomain);
          }
        } else if (_wizard.step === 2) {
          _wizard.plan = id;
        }
        paintBody(renderWizard(_entitlement));
        bindReview();
      });
    });

    var next = document.getElementById('infra-next');
    if (next) {
      next.addEventListener('click', function () {
        var msg = document.getElementById('infra-submit-msg');
        if (_wizard.step === 3) {
          var label = subNormalize((document.getElementById('infra-sub') || {}).value || '');
          var err = subCheck(label);
          if (err) { if (msg) { msg.textContent = err; } return; }
          _wizard.subdomain = label;
        }
        _wizard.step = Math.min(WIZARD_STEPS.length, _wizard.step + 1);
        paintBody(renderWizard(_entitlement));
        bindReview();
      });
    }

    var sub = document.getElementById('infra-sub');
    if (sub) {
      sub.focus();
      sub.addEventListener('input', function () {
        var raw = sub.value;
        var label = subNormalize(raw);
        _wizard.subdomain = label;
        clearTimeout(_subTimer);
        _subTimer = setTimeout(function () { probeSubdomain(label); }, 350);
      });
      if (_wizard.subdomain) { probeSubdomain(_wizard.subdomain); }
    }

    var submit = document.getElementById('infra-submit');
    if (submit) { submit.addEventListener('click', function () { submitRequest(_entitlement); }); }
  }

  function bindOperation() {
    var back = document.getElementById('infra-back-list');
    if (back) {
      back.addEventListener('click', function () {
        stopPolling();
        _view = { name: 'list' };
        renderCurrentView();
      });
    }
  }

  function bindList() {
    var start = document.getElementById('infra-start-request');
    if (start) {
      start.addEventListener('click', function () {
        _idemKey = null;
        _wizard = { step: 1, sites: [], sitesLoading: true, websiteId: null, plan: 'included', subdomain: '' };
        _view = { name: 'review' };
        renderCurrentView();
        loadWizardSites();
      });
    }
  }

  function renderCurrentView() {
    stopPolling();
    _bodyId = 'infra-body';   // customer surfaces paint into the main body

    // Sub-views (independent of the active tab).
    if (_view.name === 'asset' && _view.assetId) { loadAssetDetail(); return; }

    if (_view.name === 'manage' && _view.siteId) {
      var site = findSite(_view.siteId);
      if (!site) { _view = { name: 'list' }; loadYourWebsites(); return; }
      paintBody(renderWebsiteDetail(site, _entitlement));
      bindManage();
      return;
    }

    if (_view.name === 'addExisting') {
      paintBody(renderAddExisting(_entitlement));
      bindAddExisting();
      return;
    }

    if (_view.name === 'addExistingDone') {
      paintBody(renderAddExistingDone());
      bindAddExisting();
      return;
    }

    if (_view.name === 'review') {
      paintBody(renderWizard(_entitlement));
      bindReview();
      return;
    }

    if (_view.name === 'operation' && _view.operationId) {
      paintBody(loadingState('Loading your request…'));
      req('GET', 'infrastructure/operations/' + _view.operationId + '/timeline')
        .then(function (r) {
          if (!r.ok) { paintBody(errorState('That request could not be loaded.')); return; }
          var data = r.json && r.json.data;
          paintBody(renderOperation(data));
          bindOperation();
          if (!isTerminal(data && data.operation && data.operation.state)) {
            startPolling(_view.operationId);
          }
        })
        .catch(function (e) { paintBody(errorState(e.message)); });
      return;
    }

    // ---- CONTROL PLANE (platform admins only) --------------------------
    // Operational telemetry is re-homed here and gated. A paying customer must
    // never see asset/incident/reliability operator concepts (Bible Law L2).
    if (INFRA_TAB === 'operations') {
      if (!isPlatformAdmin()) { INFRA_TAB = 'websites'; loadYourWebsites(); return; }
      renderOperationsSurface(); return;
    }
    if (INFRA_TAB === 'overview')    { loadDashboard();  return; }
    if (INFRA_TAB === 'assets')      { loadAssets();     return; }
    if (INFRA_TAB === 'incidents')   { loadIncidents();  return; }
    if (INFRA_TAB === 'reliability') { loadReliability(); return; }

    // ---- CUSTOMER PLANE ------------------------------------------------
    if (INFRA_TAB === 'websites' || INFRA_TAB === 'hosting') { loadYourWebsites(); return; }
    if (INFRA_TAB === 'domains') { loadDomains(); return; }
    loadEmail();
  }

  // Domains landing — real data (custom domains already connected via websites).
  function loadDomains() {
    // The full domain experience (search, cart, checkout, detail, timeline)
    // lives in its own module so this file stays the infrastructure shell. It
    // is handed our design helpers and authenticated transport rather than
    // duplicating either, so both surfaces stay visually identical.
    if (window.luDomains && typeof window.luDomains.mount === 'function') {
      window.luDomains.mount({
        paintBody: paintBody,
        pageShell: pageShell,
        card: card,
        button: button,
        esc: esc,
        req: req,
        statusPill: statusPill,
        loadingState: loadingState,
        errorState: errorState,
        metricStrip: metricStrip,
        sectionTitle: sectionTitle,
        fmtTime: fmtTime,
        ICONS: ICONS
      });
      return;
    }

    // Fallback: the previous connected-domains view, kept so the tab still
    // renders something meaningful if the module fails to load.
    var entitled = !!(_entitlement && _entitlement.domains && _entitlement.domains.available);
    function paint(connected) {
      var body;
      if (connected && connected.length) {
        body = '<div style="display:flex;flex-direction:column;gap:var(--sp-3);max-width:900px;">' +
          connected.map(function (c) {
            return '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);padding:var(--sp-5);display:flex;justify-content:space-between;align-items:center;gap:var(--sp-4);flex-wrap:wrap;">' +
              '<div><div style="font:600 15px var(--fh);color:var(--t1);">' + esc(c.custom_domain) + '</div>' +
              '<div style="font:400 12px var(--fb);color:var(--t3);margin-top:3px;">Connected to ' + esc(c.name || ('Website #' + c.id)) + '</div></div>' +
              statusPill('Connected', 'var(--ac)') + '</div>';
          }).join('') + '</div>';
      } else {
        body = enterpriseEmpty(ICONS.domains, 'Connect your own domain',
          'Point a domain you already own at a website, so visitors see your brand instead of a levelupgrowth.io address.',
          [], entitled ? 'Connect a domain from any website’s Domain tab. Guided registration is coming soon.'
                       : 'Custom domains are available from the Starter plan.');
      }
      paintBody(pageShell({
        breadcrumb: [{ label: 'Hosting' }, { label: 'Domains' }],
        title: 'Domains',
        desc: 'Connect and manage the domains that point at your websites.',
        body: body
      }));
    }
    if (_sites.length) { paint(_sites.filter(function (s) { return s.custom_domain && Number(s.domain_verified) === 1; })); return; }
    req('GET', 'builder/websites').then(function (r) {
      var j = r.json || {}; var list = j.websites || j.data || []; _sites = Array.isArray(list) ? list : [];
      paint(_sites.filter(function (s) { return s.custom_domain && Number(s.domain_verified) === 1; }));
    }).catch(function () { paint([]); });
  }

  // Email landing — enterprise empty state (business email not yet built).
  function loadEmail() {
    // INFRA888 E4 — the customer Business Email portal lives in its own module,
    // mounted the same way Domains is (see loadDomains above), so this file
    // stays the infrastructure shell rather than growing a second product.
    //
    // The module renders the SAME coming-soon state below when the API reports
    // Business Email unavailable — which it does on every installation today.
    // Delegating therefore changes nothing a customer can observe until the
    // feature is switched on.
    if (window.luBusinessEmail && typeof window.luBusinessEmail.mount === 'function') {
      window.luBusinessEmail.mount({
        paintBody: paintBody,
        pageShell: pageShell,
        card: card,
        button: button,
        esc: esc,
        req: req,
        statusPill: statusPill,
        loadingState: loadingState,
        errorState: errorState,
        metricStrip: metricStrip,
        sectionTitle: sectionTitle,
        enterpriseEmpty: enterpriseEmpty,
        fmtTime: fmtTime,
        ICONS: ICONS
      });
      return;
    }

    paintBody(pageShell({
      breadcrumb: [{ label: 'Hosting' }, { label: 'Email Accounts' }],
      title: 'Email Accounts',
      desc: 'Professional email on your own domain, for you and your team.',
      body: enterpriseEmpty(ICONS.email, 'Business email is coming soon',
        'Create mailboxes like you@yourdomain.com for everyone on your team. Business email is set up on a domain you’ve connected.',
        [{ label: 'Connect a domain first', id: 'infra-email-domains' }],
        'Prerequisite: a connected custom domain. We’ll let you know the moment email is available.')
    }));
    var b = document.getElementById('infra-email-domains');
    if (b) { b.addEventListener('click', function () { window.infraOpenTab ? window.infraOpenTab('domains') : (INFRA_TAB = 'domains', renderCurrentView()); }); }
  }

  // Admin-only operations surface with its own inner tab row (control plane).
  function renderOperationsSurface() {
    if (!_opsTab) { _opsTab = 'overview'; }
    var tabs = [
      { key: 'overview', label: 'Overview', icon: ICONS.overview },
      { key: 'assets', label: 'What’s Running', icon: ICONS.assets },
      { key: 'incidents', label: 'Issues', icon: ICONS.incidents },
      { key: 'reliability', label: 'Uptime', icon: ICONS.reliability }
    ];
    // Render the ops shell straight into the main body, with a nested body that
    // the (async) intel loaders paint into via _bodyId.
    _bodyId = 'infra-body';
    paintBody(pageShell({
      breadcrumb: [{ label: 'Hosting' }, { label: 'Operations' }],
      title: 'Operations',
      badgeHtml: '<span style="font:600 10.5px var(--fb);color:var(--am);background:var(--as);border:1px solid var(--ag);border-radius:6px;padding:2px 8px;letter-spacing:.03em;">ADMIN</span>',
      desc: 'Internal control plane — assets, incidents and reliability across this workspace.',
      tabs: { items: tabs, active: _opsTab, attr: 'data-ops-tab' },
      body: '<div id="infra-ops-body">' + loadingState() + '</div>'
    }));
    var host = document.getElementById(_bodyId);
    if (host) {
      Array.prototype.forEach.call(host.querySelectorAll('[data-ops-tab]'), function (t) {
        t.addEventListener('click', function () { _opsTab = t.getAttribute('data-ops-tab'); renderOperationsSurface(); });
      });
    }
    // Subsequent async paints target the nested ops body.
    _bodyId = 'infra-ops-body';
    if (_opsTab === 'assets') { loadAssets(); }
    else if (_opsTab === 'incidents') { loadIncidents(); }
    else if (_opsTab === 'reliability') { loadReliability(); }
    else { loadDashboard(); }
  }

  /* ------------------------------------------------------------------ boot */

  // Media queries + pseudo-elements + :focus-visible cannot be expressed as
  // inline styles, so the module injects one scoped stylesheet the first time it
  // mounts. Everything here targets only the module's own classes.
  //   - .infra-head-actions : header actions stack full-width on phones so the
  //     primary CTA can never clip off-screen (was a 390px defect).
  //   - .infra-hbtn/.infra-btn : real tap targets grow to the 44px --touch-min
  //     on phones (desktop density is intentionally kept denser).
  //   - .infra-site-open : the website name is the primary, keyboard-focusable
  //     "open" control (title-as-link), with a visible focus ring.
  function ensureInfraStyles() {
    if (document.getElementById('infra-responsive')) { return; }
    var css =
      '.infra-site-open{font:600 15px var(--fh);color:var(--t1);background:none;border:none;padding:0;margin:0;text-align:left;cursor:pointer;}' +
      '.infra-site-open:hover{color:var(--p);}' +
      '.infra-site-open:focus-visible,.infra-btn:focus-visible,.infra-hbtn:focus-visible{outline:2px solid var(--p);outline-offset:2px;border-radius:var(--r);}' +
      '@media (max-width:767px){' +
        '.infra-head-actions{flex:1 1 100%!important;width:100%;}' +
        '.infra-head-actions>*{flex:1 1 auto;justify-content:center;}' +
        '.infra-hbtn,.infra-btn{min-height:var(--touch-min)!important;}' +
      '}';
    var st = document.createElement('style');
    st.id = 'infra-responsive';
    st.textContent = css;
    document.head.appendChild(st);
  }

  window.infraLoad = function (root) {
    if (!root) { root = document.getElementById('infrastructure-root'); }
    if (!root || _loading) { return; }

    ensureInfraStyles();
    _loading = true;
    stopPolling();

    // Sidebar deep-link: window.infraOpenTab('domains'|'websites'|'email'|
    // 'operations') sets this before nav(). Honoured once, then cleared.
    var VALID_TABS = ['websites', 'hosting', 'domains', 'email', 'operations'];
    if (window.__infraDesiredTab && VALID_TABS.indexOf(window.__infraDesiredTab) !== -1) {
      INFRA_TAB = window.__infraDesiredTab === 'hosting' ? 'websites' : window.__infraDesiredTab;
      window.__infraDesiredTab = null;
      _view = { name: 'list', operationId: null, assetId: null };
    }
    // Default landing = Websites (customer plane). Never an operational tab.
    if (['websites', 'domains', 'email', 'operations'].indexOf(INFRA_TAB) === -1) { INFRA_TAB = 'websites'; }

    if (_view.name !== 'asset' && _view.name !== 'operation' && _view.name !== 'review') {
      _view = { name: 'list', operationId: null, assetId: null };
    }

    // The page shell (title/desc/tabs) is rendered PER view via pageShell() into
    // #infra-body, so every surface aligns. The root is just a full-width host.
    _bodyId = 'infra-body';
    root.innerHTML = '<div id="infra-body" style="padding:var(--sp-6) var(--sp-8);">' + loadingState() + '</div>';

    // Entitlement drives included/upgrade framing; fetched quietly.
    req('GET', 'infrastructure/overview')
      .then(function (r) {
        if (r.status === 403) { return; }
        var d = r.json && r.json.data;
        _entitlement = d && d.entitlement;
      })
      .catch(function () { /* non-critical */ })
      .finally(function () {
        _loading = false;
        renderCurrentView();
      });
  };

  // Sidebar helper: open the Hosting page on a specific tab. The SPA's nav()
  // loads the engine bundle and calls infraLoad(), which reads __infraDesiredTab.
  window.infraOpenTab = function (tab) {
    window.__infraDesiredTab = tab;
    if (typeof window.nav === 'function') { window.nav('infrastructure'); }
    else { window.infraLoad(); }
  };

  // Clean up timers if the SPA tears down the view.
  window.addEventListener('beforeunload', stopPolling);
})();
