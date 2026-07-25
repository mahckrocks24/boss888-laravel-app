/* ============================================================================
 * LevelUp Growth — Marketing Analytics  (MRC-1)
 * ----------------------------------------------------------------------------
 * Consent-gated GA4 + dataLayer event taxonomy.
 *
 * ⚠ NO IDENTIFIERS ARE CONFIGURED. Nothing transmits until the owner supplies a
 *   real GA4 Measurement ID (and/or GTM ID) below. Until then this file only
 *   builds the dataLayer locally and no network request is made.
 *
 *   Set GA4_ID (e.g. "G-XXXXXXXXXX") and, optionally, GTM_ID ("GTM-XXXXXXX").
 * ========================================================================== */
(function () {
  'use strict';

  // ── Owner-supplied identifiers — REQUIRED before anything transmits ──
  var GA4_ID = null;   // [[OWNER:GA4_MEASUREMENT_ID]]  e.g. "G-XXXXXXXXXX"
  var GTM_ID = null;   // [[OWNER:GTM_CONTAINER_ID]]    optional, e.g. "GTM-XXXXXXX"

  // ── Local dataLayer (always available; used for QA even without an ID) ──
  window.dataLayer = window.dataLayer || [];
  function push(evt, params) {
    var payload = Object.assign({ event: evt, ts: undefined }, params || {});
    window.dataLayer.push(payload);
  }
  window.luTrack = push; // exposed so pages can fire custom events

  // ── Consent (cookie-policy aligned) ──
  var CONSENT_KEY = 'lu_analytics_consent';
  function consentGranted() { try { return localStorage.getItem(CONSENT_KEY) === 'granted'; } catch (e) { return false; } }
  window.luSetAnalyticsConsent = function (granted) {
    try { localStorage.setItem(CONSENT_KEY, granted ? 'granted' : 'denied'); } catch (e) {}
    if (granted) loadGA();
  };

  // ── GA4 loader — only runs with a real ID AND consent ──
  var gaLoaded = false;
  function loadGA() {
    if (gaLoaded || !GA4_ID || !consentGranted()) return; // hard gate: no ID or no consent → no transmit
    gaLoaded = true;
    var s = document.createElement('script');
    s.async = true; s.src = 'https://www.googletagmanager.com/gtag/js?id=' + GA4_ID;
    document.head.appendChild(s);
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', GA4_ID, { anonymize_ip: true, send_page_view: true });
    // flush queued events
    _queue.forEach(function (q) { window.gtag('event', q.evt, q.params); });
    _queue = [];
  }

  // events captured before GA is live are queued (and mirrored to dataLayer)
  var _queue = [];
  function track(evt, params) {
    push(evt, params);
    if (window.gtag && GA4_ID && consentGranted()) window.gtag('event', evt, params || {});
    else _queue.push({ evt: evt, params: params || {} });
  }

  // ── EVENT TAXONOMY ────────────────────────────────────────────────
  function pageGroup() {
    var p = location.pathname;
    if (p === '/' || p === '') return 'home';
    if (/\/pages\/(privacy|terms|cookies|ai-disclosure|refund)/.test(p)) return 'legal';
    if (/\/pages\/(ai-assistant|ai-agents|automation)/.test(p)) return 'ai';
    if (/\/pages\/pricing/.test(p)) return 'pricing';
    if (/\/pages\//.test(p)) return 'product';
    return 'other';
  }
  function aiSurface() {
    if (/ai-assistant/.test(location.pathname)) return 'aria';
    if (/ai-agents/.test(location.pathname)) return 'workforce';
    if (/automation/.test(location.pathname)) return 'automation';
    return null;
  }

  function init() {
    // page_view
    track('page_view', { page_path: location.pathname, page_group: pageGroup() });

    // cta_click + waitlist + outbound + nav
    document.addEventListener('click', function (e) {
      var a = e.target.closest('a, button'); if (!a) return;
      var href = a.getAttribute('href') || '';
      var label = (a.textContent || '').trim().slice(0, 60);

      if (a.classList.contains('btn') || /get notified|start free|view pricing/i.test(label)) {
        track('cta_click', { cta_label: label, cta_location: pageGroup(), gated: /mailto:/.test(href) });
      }
      if (/mailto:.*Notify|get notified/i.test(href + label)) {
        track('waitlist_signup', { source_page: location.pathname });
      }
      if (a.closest('#nav, .mega, #site-footer')) {
        track('nav_interaction', { item: label, area: a.closest('#site-footer') ? 'footer' : 'nav' });
      }
      if (/^https?:\/\//.test(href) && href.indexOf(location.hostname) === -1) {
        track('outbound_click', { target: href });
      }
      if (pageGroup() === 'pricing' && /plan|choose|select|start/i.test(label)) {
        track('plan_select', { plan: label });
      }
    }, true);

    // scroll_depth (25/50/75/100) + product/ai engagement
    var marks = { 25: false, 50: false, 75: false, 100: false };
    var engaged = false;
    window.addEventListener('scroll', function () {
      var h = document.documentElement;
      var pct = Math.round(((h.scrollTop + window.innerHeight) / h.scrollHeight) * 100);
      [25, 50, 75, 100].forEach(function (m) {
        if (!marks[m] && pct >= m) { marks[m] = true; track('scroll_depth', { percent: m, page_group: pageGroup() }); }
      });
      if (!engaged && pct >= 50) {
        engaged = true;
        if (pageGroup() === 'product') track('product_interest', { product: location.pathname.replace(/\/pages\/|\//g, '') });
        if (pageGroup() === 'ai') track('ai_page_engagement', { ai_surface: aiSurface() });
        if (pageGroup() === 'pricing') track('pricing_view', {});
      }
    }, { passive: true });

    // conversion goal placeholder — waitlist_signup is the pre-launch conversion.
    if (GA4_ID && consentGranted()) loadGA();
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
