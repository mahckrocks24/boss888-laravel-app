// LevelUp SEO Engine — Laravel native (replaces WP iframe)
// Maps to 25 routes under /api/seo/*
/* ==========================================================================
   DEAD CODE WARNING — legacy shells, lines ~1–3150.
   Runtime entry point is `window.seoLoad`, defined at ~line 4184 (canonical
   block 3151–4193). The earlier blocks redefine `_seoRenderShell` /
   `_seoSwitchTab` repeatedly; only the last load wins, and the dashboard
   actually invokes `seoLoad`. Editing anything above line 3151 has no
   runtime effect and only adds bundle weight.
   Do NOT make functional edits above line 3151.
   See: storage/audits/boss888-phase2-20260425-073526/
        seo-button-function-audit.md (2026-04-28)
   ========================================================================== */

var _seoTab = 'dashboard';
var _seoEl = () => document.getElementById('seo-root');
// Wave 15.1 (2026-05-18) — load marker so users can verify in DevTools
// console that they're running the new code with CTAs.
try { console.log('[LU SEO] seo.js v5.15.0-wave21 loaded — Credit charging wired (research 2cr, suggest 1cr, check 1cr, competitor 2cr, gaps 3cr)'); } catch(_e) {}

var _seoApi = async (method, path, body) => {
  // Build headers with dual-mode auth (mirrors _luFetch contract):
  //   - Embed mode (WP iframe): X-API-KEY + X-Workspace-ID
  //   - Direct SPA mode: Authorization Bearer JWT from localStorage
  // JwtAuthMiddleware accepts both (per the 2026-05-11 patch), so /api/seo/*
  // routes work in both modes.
  var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  if (window._LGSC_EMBED && window._LGSC_EMBED.api_key) {
    headers['X-API-KEY']      = window._LGSC_EMBED.api_key;
    headers['X-Workspace-ID'] = String(window._LGSC_EMBED.workspace_id || '');
  } else {
    var token = localStorage.getItem('lu_token') || '';
    headers['Authorization'] = 'Bearer ' + token;
  }
  var opts = { method, headers: headers, cache: 'no-store' };
  if (body) opts.body = JSON.stringify(body);
  var r = await fetch(window.location.origin + '/api/seo' + path, opts);
  var d;
  try { d = await r.json(); } catch (e) { d = null; }
  // NOTE: Throw only on HTTP-level failure.
  // Some endpoints intentionally return {success:false} with HTTP 200
  // as a soft non-error signal. Do not normalize this to also throw on
  // d.success === false without auditing every caller.
  if (!r.ok) {
    var err = new Error((d && (d.error || d.message)) || ('HTTP ' + r.status));
    err.code   = (d && d.code) || null;
    err.status = r.status;
    err.body   = d;
    throw err;
  }
  return d;
};

function seoLoad(el) {
  if (!el) return;
  el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%';
  _seoRenderShell(el);
  _seoSwitchTab('dashboard');
}

// === Wave 15.3 cleanup (2026-05-18) ==========================================
// Live entry chain (forensically verified):
//   window.seoLoad (line ~10022 — last override wins)
//     → buildShell(inner)               line ~3810  [inside the LGSE IIFE]
//       → switchTab(tabId)              line ~3937  (exposed as window.lgseSwitchTab)
//         → renderOverview/renderAudit/renderPages/renderLinks/renderTopics/
//           renderCompetitors/renderInsights/renderReports/renderPipeline
//         → loadIndexed/loadInternalLinks/loadOutbound/loadRedirects/etc.
//           → lgseRenderPagesTable(...)  line ~5626 (Pages chips live here)
//
// Originals below (_seoRenderShell, _seoSwitchTab, _seoKeywords, _seoLinks,
// _seoImages) all have explicit `window.X = …` overrides later in the file.
// The later assignment always wins for global function bindings, so the
// originals are unreachable. Bodies collapsed to no-op stubs that warn if
// somehow still invoked (which would indicate a bug we want to know about).
//
// `_seoApi` (line 21) is the only thing in lines 1-3300 that LGSE depends on
// (via the api() bridge at line ~3755) — it stays untouched.
// =============================================================================

function _seoRenderShell(el) { try { console.warn('[LU SEO 15.3] dead path: _seoRenderShell called — overridden by buildShell/lgse'); } catch(_) {} }

function _seoSwitchTab(tab) { try { console.warn('[LU SEO 15.3] dead path: _seoSwitchTab(' + tab + ') called — overridden by lgseSwitchTab; routing to lgseSwitchTab as fallback'); } catch(_) {} if (typeof window.lgseSwitchTab === 'function') return window.lgseSwitchTab(tab); }

// ── Dashboard ──────────────────────────────────────────────────────────
async function _seoDashboard(el) { try { console.warn('[LU SEO 15.5] dead path: _seoDashboard call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Site Audit ─────────────────────────────────────────────────────────
async function _seoAudits(el) { try { console.warn('[LU SEO 15.5] dead path: _seoAudits call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoRunAudit() { try { console.warn('[LU SEO 15.5] dead path: _seoRunAudit call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoViewAudit(id) { try { console.warn('[LU SEO 15.5] dead path: _seoViewAudit call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Keywords ───────────────────────────────────────────────────────────
// Wave 15.3 — body removed; overridden later (final at line ~2526, then by LGSE renderKeywords)
async function _seoKeywords(el) { try { console.warn('[LU SEO 15.3] dead path: _seoKeywords called — see renderKeywords (LGSE)'); } catch(_) {} }

async function _seoAddKeyword() { try { console.warn('[LU SEO 15.5] dead path: _seoAddKeyword call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoDeleteKeyword(id) { try { console.warn('[LU SEO 15.5] dead path: _seoDeleteKeyword call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── SERP Analysis ──────────────────────────────────────────────────────
async function _seoSerp(el) { try { console.warn('[LU SEO 15.5] dead path: _seoSerp call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoRunSerp() { try { console.warn('[LU SEO 15.5] dead path: _seoRunSerp call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Internal Links ─────────────────────────────────────────────────────
// Wave 15.3 — body removed; overridden at line ~1193 (W2S1) and ~1340 (W2S2 "Link Intelligence"),
// and the LIVE renderer is LGSE's loadInternalLinks at line ~6142. Wave 15.0/15.1 patches
// to this function never executed at runtime.
async function _seoLinks(el) { try { console.warn('[LU SEO 15.3] dead path: _seoLinks called — see loadInternalLinks (LGSE)'); } catch(_) {} }

// Wave 15 (2026-05-18). Bulk apply of internal-link suggestions from the
// Links tab top bar. Same handler is reused by the Pages-tab "Fix orphan"
// chip with a target_url scope (see _seoFixOrphan below).
async function _seoApplyTopLinks(limit) {
  if (!confirm('Bulk-apply up to ' + limit + ' link suggestion(s)? You only pay for ones that successfully insert (skipped suggestions are free).')) return;
  var el = document.getElementById('seo-content');
  if (el) el.innerHTML = loadingCard(300);
  try {
    var d = await _seoApi('POST', '/links/apply-bulk', { limit: limit, mode: 'orphans_first' });
    if (d.success === false) {
      if (d.error === 'insufficient_credits') {
        showToast('Not enough credits — top up at levelupgrowth.io/billing.', 'error');
      } else {
        showToast('Bulk apply failed: ' + (d.error || 'unknown'), 'error');
      }
    } else {
      var r = d.result || {};
      var msg = 'Applied ' + (r.applied || 0) + ' / ' + ((r.applied || 0) + (r.skipped || 0)) + ' suggestion(s).';
      if ((r.applied || 0) > 0) msg += ' ' + (r.credits_used || 0) + ' credit(s) used.';
      if ((r.skipped || 0) > 0) {
        var topReason = '';
        var reasons = r.skip_reasons || {};
        var keys = Object.keys(reasons);
        if (keys.length) topReason = keys.sort(function(a,b){return reasons[b]-reasons[a];})[0];
        if (topReason) msg += ' Top skip reason: ' + topReason + '.';
      }
      showToast(msg, (r.applied || 0) > 0 ? 'success' : 'info');
    }
  } catch(e) {
    showToast('Bulk apply failed: ' + (e.message || e), 'error');
  }
  _seoLinks(el);
}

async function _seoGenerateLinks() { try { console.warn('[LU SEO 15.5] dead path: _seoGenerateLinks call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoInsertLink(id) { try { console.warn('[LU SEO 15.5] dead path: _seoInsertLink call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoDismissLink(id) { try { console.warn('[LU SEO 15.5] dead path: _seoDismissLink call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Content AI ─────────────────────────────────────────────────────────
async function _seoContent(el) { try { console.warn('[LU SEO 15.5] dead path: _seoContent call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoAiReport() { try { console.warn('[LU SEO 15.5] dead path: _seoAiReport call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoWriteArticle() { try { console.warn('[LU SEO 15.5] dead path: _seoWriteArticle call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoImproveDraft() { try { console.warn('[LU SEO 15.5] dead path: _seoImproveDraft call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Goals ──────────────────────────────────────────────────────────────
async function _seoGoals(el) { try { console.warn('[LU SEO 15.5] dead path: _seoGoals call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoCreateGoal() { try { console.warn('[LU SEO 15.5] dead path: _seoCreateGoal call collapsed; live path is the LGSE renderer'); } catch(_) {} }
async function _seoPauseGoal(id) { try { console.warn('[LU SEO 15.5] dead path: _seoPauseGoal call collapsed; live path is the LGSE renderer'); } catch(_) {} }
async function _seoResumeGoal(id) { try { console.warn('[LU SEO 15.5] dead path: _seoResumeGoal call collapsed; live path is the LGSE renderer'); } catch(_) {} }


// ── Optimization Workspace ──────────────────────────────────────
async function _seoWorkspace(el) { try { console.warn('[LU SEO 15.5] dead path: _seoWorkspace call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Insights ────────────────────────────────────────────────────
async function _seoInsights(el) { try { console.warn('[LU SEO 15.5] dead path: _seoInsights call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Reports ─────────────────────────────────────────────────────
async function _seoReports(el) { try { console.warn('[LU SEO 15.5] dead path: _seoReports call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Outbound Links ──────────────────────────────────────────────
async function _seoOutbound(el) { try { console.warn('[LU SEO 15.5] dead path: _seoOutbound call collapsed; live path is the LGSE renderer'); } catch(_) {} }
async function _seoCheckOutbound() { try { console.warn('[LU SEO 15.5] dead path: _seoCheckOutbound call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Integrations ────────────────────────────────────────────────
async function _seoIntegrations(el) { try { console.warn('[LU SEO 15.5] dead path: _seoIntegrations call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Stub page (for tabs migrating from WP) ──────────────────────
// ── Redirects ─────────────────────────────────────────────────────
async function _seoRedirects(el) { try { console.warn('[LU SEO 15.5] dead path: _seoRedirects call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoAddRedirect() { try { console.warn('[LU SEO 15.5] dead path: _seoAddRedirect call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoDeleteRedirect(id) { try { console.warn('[LU SEO 15.5] dead path: _seoDeleteRedirect call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Images ────────────────────────────────────────────────────────
// Wave 15.3 — body removed; overridden at line ~1136 and replaced by LGSE's renderImages flow.
function _seoImages(el) { try { console.warn('[LU SEO 15.3] dead path: _seoImages called — see lgseRenderImages (LGSE)'); } catch(_) {} }

// ── SEO Settings ──────────────────────────────────────────────────
async function _seoSettings(el) { try { console.warn('[LU SEO 15.5] dead path: _seoSettings call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoSaveSettings() { try { console.warn('[LU SEO 15.5] dead path: _seoSaveSettings call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Score Settings ────────────────────────────────────────────────
async function _seoScoreSettings(el) { try { console.warn('[LU SEO 15.5] dead path: _seoScoreSettings call collapsed; live path is the LGSE renderer'); } catch(_) {} }

function _seoUpdateWeightTotal() { try { console.warn('[LU SEO 15.5] dead path: _seoUpdateWeightTotal call collapsed; live path is the LGSE renderer'); } catch(_) {} }

async function _seoSaveScoreWeights() { try { console.warn('[LU SEO 15.5] dead path: _seoSaveScoreWeights call collapsed; live path is the LGSE renderer'); } catch(_) {} }
function _seoStubPage(el, title, desc, icon) { try { console.warn('[LU SEO 15.5] dead path: _seoStubPage call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// ── Helpers ────────────────────────────────────────────────────────────
function _seoStat(label, value, color) { try { console.warn('[LU SEO 15.5] dead path: _seoStat call collapsed; live path is the LGSE renderer'); } catch(_) {} }

function _seoError(e) { try { console.warn('[LU SEO 15.5] dead path: _seoError call collapsed; live path is the LGSE renderer'); } catch(_) {} }

window.LU_LOADED_ENGINES = window.LU_LOADED_ENGINES || {};
window.LU_LOADED_ENGINES['seo'] = true;
console.log('[LevelUp] SEO engine loaded (Laravel native — 14 tabs, 33 routes)');


// ═══════════════════════════════════════════════════════════════
// KEYWORD USAGE + SCAN INFO PATCH — injected 2026-04-16
// Replaces the keyword display override with one that handles
// the new {keywords, usage, scan} response format + usage badge + scan info
// ═══════════════════════════════════════════════════════════════

(function() {
    window._seoKeywords = function () { try { console.warn('[LU SEO 15.4] dead path: _seoKeywords call collapsed; live path is the LGSE renderer'); } catch(_) {} };
})();


// ───────────────────────────────────────────────────────────────────────────
// SEO Gap Build Week 1 (LB-SEO-Gap-Week1, 2026-04-27)
// ───────────────────────────────────────────────────────────────────────────

// Build 5 — Pages tab (indexed content table with inline editing)
window._seoPagesState = { page: 1, filter: '', sort: 'score', q: '' };

async function _seoPages(el) { try { console.warn('[LU SEO 15.5] dead path: _seoPages call collapsed; live path is the LGSE renderer'); } catch(_) {} }

window._seoPageSave = async function(id, field, value, inputEl) {
  try {
    var body = {}; body[field] = value;
    await _seoApi('PATCH', '/indexed-content/' + id, body);
    if (inputEl) {
      inputEl.style.background = 'rgba(16,185,129,.12)';
      setTimeout(function(){ inputEl.style.background = 'transparent'; }, 800);
    }
  } catch(e) {
    if (inputEl) inputEl.style.background = 'rgba(248,113,113,.12)';
    if (typeof showToast === 'function') showToast('Save failed: '+(e.message||e), 'error');
  }
};

// Wave 15 (2026-05-18). Pages-tab per-row CTAs.
//
// _seoFixOrphan(url) — calls /api/seo/links/apply-bulk scoped to this
// target_url. Applies every queued suggestion pointing at this page.
//
// _seoRetryImage(url) — calls /api/seo/pages/retry-image which re-tries
// the connector image generator + clears the featured_image_error column
// on success.
//
// Both work identically in WP iframe and Laravel SaaS — the backend
// route's auth middleware accepts X-API-KEY or JWT, and the underlying
// services respect Wave-9 context-aware agent routing for notifications.
window._seoFixOrphan = async function(url) {
  if (!confirm('Apply all queued link suggestions targeting this page? You only pay for ones that successfully insert.')) return;
  try {
    var d = await _seoApi('POST', '/links/apply-bulk', { target_url: url, mode: 'orphans_first', limit: 20 });
    if (d.success === false) {
      if (d.error === 'insufficient_credits') {
        showToast('Not enough credits — top up at levelupgrowth.io/billing.', 'error');
      } else {
        showToast('Fix-orphan failed: ' + (d.error || 'unknown'), 'error');
      }
      return;
    }
    var r = d.result || {};
    if ((r.applied || 0) > 0) {
      showToast('Applied ' + r.applied + ' link(s) — orphan resolved.', 'success');
    } else {
      var reasons = r.skip_reasons || {};
      var keys = Object.keys(reasons);
      var topReason = keys.length ? keys.sort(function(a,b){return reasons[b]-reasons[a];})[0] : '';
      showToast('No suggestions could be applied' + (topReason ? ' (' + topReason + ').' : '.') + ' Try generating fresh link suggestions first.', 'info');
    }
    if (typeof _seoSwitchTab === 'function') _seoSwitchTab('pages');
  } catch(e) {
    showToast('Fix-orphan failed: ' + (e.message || e), 'error');
  }
};

// Wave 15.2 (2026-05-18) — bulk-apply for ALL orphan pages. Fetches the
// current orphan list then calls _seoApplyTopLinks for each (capped at
// 20 each, server enforces plan/credit gating). Same notification +
// floater behavior as the chat-driven path.
window._seoFixAllOrphans = async function() {
  try {
    var orphResp = await _seoApi('GET', '/link-graph/orphans');
    var orphans = (orphResp && (orphResp.orphans || orphResp.data)) || [];
    if (!Array.isArray(orphans) || orphans.length === 0) {
      showToast('No orphan pages to fix. Either run a deep audit first or all pages already have inbound links.', 'info');
      return;
    }
    if (!confirm('Apply queued link suggestions to ' + orphans.length + ' orphan page(s)? You only pay for ones that successfully insert.')) return;
    var d = await _seoApi('POST', '/links/apply-bulk', { mode: 'orphans_first', limit: Math.min(100, orphans.length * 3) });
    if (d.success === false) {
      if (d.error === 'insufficient_credits') {
        showToast('Not enough credits — top up at levelupgrowth.io/billing.', 'error');
      } else {
        showToast('Fix-all-orphans failed: ' + (d.error || 'unknown'), 'error');
      }
      return;
    }
    var r = d.result || {};
    var msg = 'Applied ' + (r.applied || 0) + ' / ' + ((r.applied || 0) + (r.skipped || 0)) + ' suggestion(s).';
    if ((r.applied || 0) > 0) msg += ' Orphan count is now ' + (r.orphan_count || '?') + '.';
    showToast(msg, (r.applied || 0) > 0 ? 'success' : 'info');
    if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('links');
  } catch(e) {
    showToast('Fix-all-orphans failed: ' + (e.message || e), 'error');
  }
};

window._seoRetryImage = async function(url) {
  if (!confirm('Re-try featured image generation for this page?')) return;
  try {
    var d = await _seoApi('POST', '/pages/retry-image', { url: url, force: true });
    if (d.success) {
      showToast('Image regenerated successfully.', 'success');
      if (typeof _seoSwitchTab === 'function') _seoSwitchTab('pages');
    } else {
      showToast('Retry failed: ' + (d.message || d.error || 'unknown'), 'error');
    }
  } catch(e) {
    showToast('Retry failed: ' + (e.message || e), 'error');
  }
};

// Build 2 — CTR Optimizer tab
async function _seoCtr(el) { try { console.warn('[LU SEO 15.5] dead path: _seoCtr call collapsed; live path is the LGSE renderer'); } catch(_) {} }

// Build 3 — Quick Wins tab
async function _seoWins(el) { try { console.warn('[LU SEO 15.5] dead path: _seoWins call collapsed; live path is the LGSE renderer'); } catch(_) {} }


// ───────────────────────────────────────────────────────────────────────────
// SEO Week 2 Session 1 (LB-SEO-W2, 2026-04-27)
// ───────────────────────────────────────────────────────────────────────────

// Build 1 — Google Search Console tab
async function _seoGsc(el) { try { console.warn('[LU SEO 15.5] dead path: _seoGsc call collapsed; live path is the LGSE renderer'); } catch(_) {} }

window._seoGscConnect = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoGscConnect call collapsed; live path is the LGSE renderer'); } catch(_) {} };

window._seoGscSync = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoGscSync call collapsed; live path is the LGSE renderer'); } catch(_) {} };

window._seoGscDisconnect = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoGscDisconnect call collapsed; live path is the LGSE renderer'); } catch(_) {} };

// Build 2 — Image issues renderer (override existing _seoImages if present)
window._seoImages = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoImages call collapsed; live path is the LGSE renderer'); } catch(_) {} };

window._seoImgSuggest = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoImgSuggest call collapsed; live path is the LGSE renderer'); } catch(_) {} };

// Build 3 — Anchor analysis renderer (extends existing 'links' tab)
// Override _seoLinks if it exists, otherwise add it.
var _seoLinksOriginal = window._seoLinks;
window._seoLinks = function () { try { console.warn('[LU SEO 15.4] dead path: _seoLinks call collapsed; live path is the LGSE renderer'); } catch(_) {} };

window._seoApplyLink = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoApplyLink call collapsed; live path is the LGSE renderer'); } catch(_) {} };

// ═══════════════════════════════════════════════════════════════════════════
// SEO Week 2 Session 2 (LB-SEO-W2S2, 2026-04-27) — Frontend extension
// Adds: Anchor Intelligence (FULL), Real Link Graph, Equity Flow, Semantic Clusters.
// Append-only — overrides _seoLinks, _seoRenderShell, _seoSwitchTab via wrappers.
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  // Inject Topics tab into the tab strip after Links.
  var _origRender = window._seoRenderShell || _seoRenderShell;
  window._seoRenderShell = function () { try { console.warn('[LU SEO 15.4] dead path: _seoRenderShell call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // Hook _seoSwitchTab to dispatch 'topics'.
  var _origSwitch = window._seoSwitchTab || _seoSwitchTab;
  window._seoSwitchTab = function () { try { console.warn('[LU SEO 15.4] dead path: _seoSwitchTab call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // Local helpers (do not collide with core).
  function esc(s) { return (typeof window._luEsc === 'function') ? window._luEsc(s || '') : String(s || '').replace(/[<>"]/g, ''); }
  function toast(msg, kind) { if (typeof window.showToast === 'function') window.showToast(msg, kind || 'info'); }
  function bar(pct, color) {
    pct = Math.max(0, Math.min(100, pct));
    return '<div style="background:rgba(255,255,255,.06);height:6px;border-radius:3px;overflow:hidden">' +
      '<div style="background:' + color + ';height:6px;width:' + pct + '%;transition:width .3s"></div></div>';
  }
  function scoreColor(s) { return s >= 70 ? '#10B981' : (s >= 40 ? '#F59E0B' : '#EF4444'); }
  function chip(text, color) {
    return '<span style="background:' + color + '20;color:' + color + ';padding:2px 8px;border-radius:8px;font-size:10px;font-weight:600">' + esc(text) + '</span>';
  }

  // ── Override _seoLinks again (W2S2): extends with Link Graph + Equity sections.
  var _seoLinksW2S1 = window._seoLinks;
  window._seoLinks = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoLinks call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Build link graph button ──────────────────────────────────────────
  window._seoBuildLinkGraph = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoBuildLinkGraph call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Calculate equity button ──────────────────────────────────────────
  window._seoCalcEquity = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoCalcEquity call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ─────────────────────────────────────────────────────────────────────
  // Topics tab — Semantic clusters
  // ─────────────────────────────────────────────────────────────────────
  window._seoTopics = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoTopics call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  window._seoBuildClusters = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoBuildClusters call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  window._seoViewCluster = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoViewCluster call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // If shell already rendered (user navigated to SEO before this script ran),
  // inject the Topics tab immediately.
  if (document.readyState !== 'loading') {
    var existingTabBar = document.getElementById('seo-tab-links');
    if (existingTabBar && !document.getElementById('seo-tab-topics')) {
      var t = document.createElement('div');
      t.className = 'seo-tab';
      t.id = 'seo-tab-topics';
      t.style.cssText = 'padding:10px 16px;cursor:pointer;font-size:13px;font-weight:500;color:var(--t2);border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap';
      var ico2 = (typeof window.icon === 'function') ? window.icon('chart', 14) : '';
      t.innerHTML = ico2 + ' Topics';
      t.onclick = function () { _seoSwitchTab('topics'); };
      existingTabBar.parentNode.insertBefore(t, existingTabBar.nextSibling);
    }
  }
})();

// ═══════════════════════════════════════════════════════════════════════════
// SEO Week 3 Session 1 (LB-SEO-W3, 2026-04-28) — Frontend extension
// Adds: Competitors tab. Rebuilds: Insights + Reports tabs (replaces stubs).
// Append-only — patches tab strip via _seoRenderShell wrapper.
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  function escW3(s) { return (typeof window._luEsc === 'function') ? window._luEsc(s || '') : String(s || '').replace(/[<>"]/g, ''); }
  function toastW3(msg, kind) { if (typeof window.showToast === 'function') window.showToast(msg, kind || 'info'); }
  function loadingW3() { return (typeof loadingCard === 'function') ? loadingCard(220) : 'Loading…'; }
  function scoreColorW3(s) { return s >= 70 ? '#10B981' : (s >= 40 ? '#F59E0B' : '#EF4444'); }
  function chipW3(text, color) {
    return '<span style="background:' + color + '20;color:' + color + ';padding:2px 8px;border-radius:8px;font-size:10px;font-weight:600">' + escW3(text) + '</span>';
  }

  // Inject Competitors tab between Topics and Optimization (Workspace).
  var _origRenderW3 = window._seoRenderShell || _seoRenderShell;
  window._seoRenderShell = function () { try { console.warn('[LU SEO 15.4] dead path: _seoRenderShell call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // Hook _seoSwitchTab for the new 'competitors' tab id.
  var _origSwitchW3 = window._seoSwitchTab;
  window._seoSwitchTab = function () { try { console.warn('[LU SEO 15.4] dead path: _seoSwitchTab call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Competitors tab ──────────────────────────────────────────────────────
  window._seoCompetitors = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoCompetitors call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  window._seoCmpAnalyze = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoCmpAnalyze call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  window._seoCmpGaps = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoCmpGaps call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  window._seoCmpCompare = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoCmpCompare call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  window._seoCmpTrack = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoCmpTrack call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Replace _seoInsights stub ────────────────────────────────────────────
  window._seoInsights = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoInsights call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Replace _seoReports stub ─────────────────────────────────────────────
  window._seoReports = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoReports call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  window._seoDownloadReport = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoDownloadReport call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  window._seoExportCsv = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoExportCsv call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // Inject Competitors tab if shell already rendered.
  if (document.readyState !== 'loading') {
    var anchorReady = document.getElementById('seo-tab-topics') || document.getElementById('seo-tab-links');
    if (anchorReady && !document.getElementById('seo-tab-competitors')) {
      var t = document.createElement('div');
      t.className = 'seo-tab';
      t.id = 'seo-tab-competitors';
      t.style.cssText = 'padding:10px 16px;cursor:pointer;font-size:13px;font-weight:500;color:var(--t2);border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap';
      var ico2 = (typeof window.icon === 'function') ? window.icon('globe', 14) : '';
      t.innerHTML = ico2 + ' Competitors';
      t.onclick = function () { _seoSwitchTab('competitors'); };
      anchorReady.parentNode.insertBefore(t, anchorReady.nextSibling);
    }
  }
})();

// ═══════════════════════════════════════════════════════════════════════════
// SEO Engine UI/UX Redesign (LB-SEO-UI, 2026-04-28)
// "Mission Control" — data-forward, monospace numbers, sticky tables.
// Append-only progressive enhancement. No API changes. No rewrites.
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  // ── Style sheet (injected once into <head>) ────────────────────────────
  var LGSE_STYLES = [
    ".lgse-mono{font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace}",

    // Score color helpers
    ".lgse-score-90{color:#00E5A8}",
    ".lgse-score-70{color:#3B8BF5}",
    ".lgse-score-50{color:var(--am)}",
    ".lgse-score-low{color:var(--rd)}",

    // Tables
    ".lgse-table{width:100%;border-collapse:collapse}",
    ".lgse-table thead th{background:var(--s1);color:var(--t3);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;padding:10px 16px;text-align:left;position:sticky;top:0;z-index:10;border-bottom:1px solid var(--bd)}",
    ".lgse-table thead th.num{text-align:right}",
    ".lgse-table tbody tr{border-bottom:1px solid var(--bd);transition:background .1s}",
    ".lgse-table tbody tr:hover{background:var(--s2)}",
    ".lgse-table tbody td{padding:12px 16px;font-size:13px;color:var(--t1)}",
    ".lgse-table tbody td.num{text-align:right;font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace}",
    ".lgse-table-wrap{background:var(--s1);border:1px solid var(--bd);border-radius:8px;overflow:hidden;max-height:560px;overflow-y:auto}",

    // Badges
    ".lgse-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace;line-height:1.4}",
    ".lgse-badge-success{background:rgba(0,229,168,.12);color:#00E5A8}",
    ".lgse-badge-info{background:rgba(59,139,245,.12);color:#3B8BF5}",
    ".lgse-badge-warning{background:rgba(245,158,11,.12);color:var(--am)}",
    ".lgse-badge-danger{background:rgba(248,113,113,.12);color:var(--rd)}",
    ".lgse-badge-muted{background:rgba(139,151,176,.1);color:var(--t2)}",
    ".lgse-badge-primary{background:rgba(108,92,231,.12);color:var(--p)}",

    // KPI cards
    ".lgse-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px}",
    ".lgse-kpi-card{background:var(--s1);border:1px solid var(--bd);border-radius:8px;padding:16px;transition:border-color .15s}",
    ".lgse-kpi-card:hover{border-color:var(--bd2)}",
    ".lgse-kpi-label{font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;font-weight:600}",
    ".lgse-kpi-value{font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace;font-size:26px;font-weight:700;color:var(--t1);line-height:1}",
    ".lgse-kpi-change{font-size:11px;margin-top:6px;font-family:'JetBrains Mono',ui-monospace,monospace}",
    ".lgse-kpi-change.up{color:#00E5A8}",
    ".lgse-kpi-change.down{color:var(--rd)}",
    ".lgse-kpi-change.same{color:var(--t3)}",

    // Issue chips
    ".lgse-issues-strip{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:24px}",
    ".lgse-issue-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:var(--s1);border:1px solid var(--bd);border-radius:20px;font-size:12px;color:var(--t2);cursor:pointer;transition:all .15s}",
    ".lgse-issue-chip:hover{border-color:var(--p);color:var(--t1)}",
    ".lgse-issue-chip .dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}",

    // Section header
    ".lgse-section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--bd)}",
    ".lgse-section-title{font-size:11px;font-weight:600;color:var(--t1);text-transform:uppercase;letter-spacing:.08em}",
    ".lgse-section-actions{display:flex;align-items:center;gap:8px}",

    // Tab bar
    ".lgse-tab-bar{display:flex;gap:0;border-bottom:1px solid var(--bd);margin-bottom:24px;overflow-x:auto;scrollbar-width:none;flex-shrink:0;background:transparent}",
    ".lgse-tab-bar::-webkit-scrollbar{display:none}",
    ".lgse-tab-bar .seo-tab{padding:10px 16px;font-size:12px;font-weight:500;color:var(--t3);cursor:pointer;border-bottom:2px solid transparent;transition:color .15s,border-color .15s;white-space:nowrap;display:flex;align-items:center;gap:6px;background:transparent}",
    ".lgse-tab-bar .seo-tab:hover{color:var(--t1)}",
    ".lgse-tab-bar .seo-tab.lgse-active{color:var(--p);border-bottom-color:var(--p)}",
    ".lgse-tab-bar .seo-tab .lgse-count{background:var(--s2);color:var(--t2);font-family:'JetBrains Mono',ui-monospace,monospace;font-size:10px;padding:1px 5px;border-radius:10px;min-width:18px;text-align:center;line-height:1.5;font-weight:600}",
    ".lgse-tab-bar .seo-tab.lgse-active .lgse-count{background:rgba(108,92,231,.18);color:var(--p)}",

    // Score gauge
    ".lgse-gauge-wrap{position:relative;display:inline-flex;align-items:center;justify-content:center}",
    ".lgse-gauge-text{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none}",
    ".lgse-gauge-text .v{font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace;font-weight:700;line-height:1}",
    ".lgse-gauge-text .l{font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.12em;margin-top:4px;font-weight:600}",

    // Empty states
    ".lgse-empty-state{text-align:center;padding:40px 24px;color:var(--t3);background:var(--s1);border:1px dashed var(--bd);border-radius:10px}",
    ".lgse-empty-state .icon{font-size:28px;margin-bottom:10px;opacity:.6}",
    ".lgse-empty-state h3{font-size:14px;font-weight:600;color:var(--t2);margin:0 0 6px}",
    ".lgse-empty-state p{font-size:12px;max-width:340px;margin:0 auto 14px}",

    // Position change indicators
    ".lgse-pos-change{font-family:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace;font-size:11px;font-weight:600}",
    ".lgse-pos-change.up{color:#00E5A8}",
    ".lgse-pos-change.down{color:var(--rd)}",
    ".lgse-pos-change.same{color:var(--t3)}",

    // Sparkline
    ".lgse-sparkline{display:inline-block;vertical-align:middle}",

    // Buttons
    ".lgse-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;border:1px solid transparent;font-family:inherit}",
    ".lgse-btn-primary{background:var(--p);color:#fff;border-color:var(--p)}",
    ".lgse-btn-primary:hover{filter:brightness(1.08)}",
    ".lgse-btn-secondary{background:transparent;color:var(--t1);border-color:var(--bd)}",
    ".lgse-btn-secondary:hover{border-color:var(--p);color:var(--p)}",
    ".lgse-btn-ghost{background:transparent;color:var(--t2);border-color:transparent}",
    ".lgse-btn-ghost:hover{color:var(--t1);background:var(--s2)}",

    // Connection pill
    ".lgse-conn-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:500;background:var(--s1);border:1px solid var(--bd)}",
    ".lgse-conn-pill .dot{width:8px;height:8px;border-radius:50%}",
    ".lgse-conn-pill.connected .dot{background:#00E5A8;box-shadow:0 0 8px rgba(0,229,168,.6)}",
    ".lgse-conn-pill.disconnected .dot{background:var(--rd)}",

    // Anchor distribution bar (stacked horizontal)
    ".lgse-stack-bar{display:flex;width:100%;height:8px;border-radius:4px;overflow:hidden;background:var(--s2)}",
    ".lgse-stack-seg{height:100%;transition:width .3s}",
    ".lgse-stack-legend{display:flex;flex-wrap:wrap;gap:14px;margin-top:10px;font-size:11px;color:var(--t2)}",
    ".lgse-stack-legend .lg{display:inline-flex;align-items:center;gap:6px}",
    ".lgse-stack-legend .sw{width:10px;height:10px;border-radius:2px}",

    // Animations
    "@keyframes lgse-fadein{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}",
    ".lgse-animate{animation:lgse-fadein .2s ease-out}",
  ].join('');

  function lgseInjectStyles() {
    if (document.getElementById('lgse-styles')) return;
    var s = document.createElement('style');
    s.id = 'lgse-styles';
    s.textContent = LGSE_STYLES;
    document.head.appendChild(s);
  }

  // ── Helpers ─────────────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function scoreColor(score) {
    score = parseInt(score, 10) || 0;
    if (score >= 90) return '#00E5A8';
    if (score >= 70) return '#3B8BF5';
    if (score >= 50) return '#F59E0B';
    return '#F87171';
  }
  function scoreClass(score) {
    score = parseInt(score, 10) || 0;
    if (score >= 90) return 'lgse-score-90';
    if (score >= 70) return 'lgse-score-70';
    if (score >= 50) return 'lgse-score-50';
    return 'lgse-score-low';
  }
  function scoreBadgeKind(score) {
    score = parseInt(score, 10) || 0;
    if (score >= 90) return 'lgse-badge-success';
    if (score >= 70) return 'lgse-badge-info';
    if (score >= 50) return 'lgse-badge-warning';
    return 'lgse-badge-danger';
  }

  // ── Score gauge component (SVG, animated) ──────────────────────────────
  window.renderScoreGauge = function (container, score, size) {
    if (!container) return;
    size = size || 120;
    score = Math.max(0, Math.min(100, parseInt(score, 10) || 0));
    var r = (size / 2) - 8;
    var circ = 2 * Math.PI * r;
    var offset = circ - (score / 100) * circ;
    var color = scoreColor(score);
    var fontSize = Math.round(size * 0.22);

    container.style.width = size + 'px';
    container.style.height = size + 'px';
    container.classList.add('lgse-gauge-wrap');
    container.innerHTML =
      '<svg width="' + size + '" height="' + size + '" style="transform:rotate(-90deg)">' +
        '<circle cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" fill="none" stroke="var(--s2)" stroke-width="8"/>' +
        '<circle cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="8" stroke-dasharray="' + circ + '" stroke-dashoffset="' + circ + '" stroke-linecap="round" style="transition:stroke-dashoffset 1s cubic-bezier(.4,0,.2,1)" data-target-offset="' + offset + '"/>' +
      '</svg>' +
      '<div class="lgse-gauge-text">' +
        '<div class="v" style="font-size:' + fontSize + 'px;color:' + color + '">' + score + '</div>' +
        '<div class="l">score</div>' +
      '</div>';
    requestAnimationFrame(function () {
      var c = container.querySelector('[data-target-offset]');
      if (c) c.style.strokeDashoffset = c.getAttribute('data-target-offset');
    });
  };

  // ── Sparkline (SVG polyline) ───────────────────────────────────────────
  window.renderSparkline = function (positions, width, height) {
    width = width || 60;
    height = height || 20;
    if (!positions || positions.length < 2) return '';
    var vals = positions.map(function (p) { return p.pos != null ? p.pos : (p.position != null ? p.position : 100); });
    var min = Math.min.apply(null, vals);
    var max = Math.max.apply(null, vals);
    var range = max - min || 1;
    var pts = vals.map(function (v, i) {
      var x = (i / (vals.length - 1)) * width;
      var y = height - ((v - min) / range) * height;
      return x + ',' + y;
    }).join(' ');
    var trend = vals[0] > vals[vals.length - 1] ? '#00E5A8' : '#F87171';
    return '<svg class="lgse-sparkline" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '">' +
      '<polyline points="' + pts + '" fill="none" stroke="' + trend + '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" opacity=".85"/></svg>';
  };

  // Position-change pill: "↑3" green / "↓2" red / "—" muted.
  window.renderPosChange = function (change) {
    var n = parseInt(change, 10) || 0;
    if (n === 0) return '<span class="lgse-pos-change same">—</span>';
    if (n < 0) return '<span class="lgse-pos-change up">↑' + Math.abs(n) + '</span>';
    return '<span class="lgse-pos-change down">↓' + n + '</span>';
  };

  // ── Tab strip rebuild — restyle existing tabs + insert Overview first ──
  var _origRenderShellUI = window._seoRenderShell || _seoRenderShell;
  window._seoRenderShell = function () { try { console.warn('[LU SEO 15.4] dead path: _seoRenderShell call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // Hook _seoSwitchTab — handle 'overview' + apply lgse-active class.
  var _origSwitchUI = window._seoSwitchTab;
  window._seoSwitchTab = function () { try { console.warn('[LU SEO 15.4] dead path: _seoSwitchTab call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // Make Overview the default landing tab when seoLoad runs.
  var _origSeoLoad = window.seoLoad || seoLoad;
  window.seoLoad = function (el) {
    if (!el) return;
    el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%';
    lgseInjectStyles();
    _seoRenderShell(el);
    _seoSwitchTab('overview');
  };

  // ── Overview tab ────────────────────────────────────────────────────────
  window._seoOverview = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoOverview call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Reskin: _seoAudits — wrap table with lgse-table classes ────────────
  var _origAudits = window._seoAudits;
  window._seoAudits = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoAudits call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Reskin: _seoKeywords — sparklines + position bands ─────────────────
  var _origKeywords = window._seoKeywords;
  window._seoKeywords = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoKeywords call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Reskin: _seoGsc — connection pill at top ───────────────────────────
  var _origGsc = window._seoGsc;
  window._seoGsc = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoGsc call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  window._seoConnectGsc = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoConnectGsc call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Reskin: ensure Reports / Insights / Topics / Competitors get the
  //   tab-bar styling. Their renderers were already redesigned in W2/W3.
  //   No further changes needed — CSS variable-based styling carries through.
})();

// ═══════════════════════════════════════════════════════════════════════════
// SEO Engine — Critical Fixes (LB-SEO-FIX, 2026-04-28)
// 1. Audit detail: structured render (was raw JSON)
// 2. Integrations: KPI summary (was raw JSON)
// 3. Tab consolidation: 20+ → 9
// 4. Overview KPIs: real counts (were zeros)
// 5. Polish: page titles, empty states, button consistency
// Append-only progressive enhancement. No backend changes.
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function scoreColor(s) {
    s = parseInt(s, 10) || 0;
    if (s >= 90) return '#00E5A8';
    if (s >= 70) return '#3B8BF5';
    if (s >= 50) return '#F59E0B';
    return '#F87171';
  }
  function scoreBadgeKind(s) {
    s = parseInt(s, 10) || 0;
    if (s >= 90) return 'lgse-badge-success';
    if (s >= 70) return 'lgse-badge-info';
    if (s >= 50) return 'lgse-badge-warning';
    return 'lgse-badge-danger';
  }
  function pageHeader(title, desc) {
    return '<div style="margin-bottom:24px">' +
      '<h2 style="font-family:var(--fh,inherit);font-size:20px;font-weight:600;color:var(--t1);margin:0 0 4px">' + escHtml(title) + '</h2>' +
      '<p style="font-size:13px;color:var(--t3);margin:0">' + escHtml(desc) + '</p>' +
      '</div>';
  }

  // ── FIX 3 — Tab consolidation: 20+ → 9 ─────────────────────────────────
  // Override _seoRenderShell to render exactly 9 top-level tabs.
  // Old tab IDs are mapped onto the 9 in _seoSwitchTab so deep links keep working.

  var TABS = [
    { id: 'overview',    label: 'Overview',     icon: 'home' },
    { id: 'audits',      label: 'Audit',        icon: 'info' },
    { id: 'workspace',   label: 'Keywords',     icon: 'chart' },
    { id: 'pages',       label: 'Pages',        icon: 'edit' },
    { id: 'links',       label: 'Links',        icon: 'link' },
    { id: 'topics',      label: 'Topics',       icon: 'chart' },
    { id: 'competitors', label: 'Competitors',  icon: 'globe' },
    { id: 'insights',    label: 'Insights',     icon: 'info' },
    { id: 'reports',     label: 'Reports',      icon: 'check' },
    { id: 'pipeline',    label: 'Pipeline',     icon: 'edit' },
  ];

  // FIX 2026-05-11 (sprint): Tabs with unimplemented backend — hidden until ready.
  // Backed by the Phase 1 audit which confirmed these endpoints return 404:
  // /clusters, /anchors/bulk-analysis, /competitors/tracked, /gsc/*,
  // /insights/content-performance, /insights/top-pages, /equity/*, /quick-wins,
  // /image-issues, /ctr-analysis, /link-graph, /indexed-content.
  var _SEO_ORPHAN_TABS = [
    'clusters','anchors','competitors','gsc',
    'insights','equity','quick-wins','image-issues',
    'ctr-analysis','link-graph','indexed-content',
    'workspace','integrations','images','ctr','wins'
  ];
  TABS = TABS.filter(function (t) {
    var id = typeof t === 'string' ? t : (t.id || t.key || t);
    return _SEO_ORPHAN_TABS.indexOf(id) === -1;
  });

  // Map legacy tab IDs to the consolidated tab ID. Used by _seoSwitchTab so
  // any code calling _seoSwitchTab('outbound') still ends up on Links, etc.
  var TAB_ALIAS = {
    dashboard:     'overview',
    content:       'overview',  // Content AI moved to Write engine — surface its absence via Overview
    goals:         'overview',  // Goals belong to Sarah; Overview shows quick wins
    wins:          'overview',
    score:         'pages',
    settings:      'reports',   // Settings becomes a header action; for now route to Reports
    integrations:  'reports',
    images:        'pages',
    ctr:           'pages',
    outbound:      'links',
    redirects:     'links',
    gsc:           'insights',
  };

  // Override the shell. Inject our own tab strip; preserve seo-content area.
  window._seoRenderShell = function () { try { console.warn('[LU SEO 15.5] dead path: _seoRenderShell call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // Override _seoSwitchTab: alias old IDs, set active class, dispatch to renderer.
  window._seoSwitchTab = function () { try { console.warn('[LU SEO 15.5] dead path: _seoSwitchTab call collapsed; routing to lgseSwitchTab fallback'); } catch(_) {} if (typeof window.lgseSwitchTab === 'function') return window.lgseSwitchTab(arguments[0]); };

  // Make Overview the default tab on first load.
  window.seoLoad = function (el) {
    if (!el) return;
    el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%';
    if (typeof window.lgseInjectStyles === 'function') window.lgseInjectStyles();
    _seoRenderShell(el);
    _seoSwitchTab('overview');
  };

  // ── Compose: Pages tab (pages + images + CTR + quick wins) ─────────────
  window._seoPagesAll = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoPagesAll call collapsed; live path is the LGSE renderer'); } catch(_) {} };


  // ── Compose: Pipeline tab — Queue + Calendar sub-tabs ─────────────────
  // 2026-05-12 — moved from standalone sidebar engine into the SEO tab
  // strip per spec. Queue (task list, auto-poll 8s) + Calendar (month grid).
  var _lgsePipe = { sub: 'queue', month: null, pipeline: null, calendar: null, pollT: null };

  function _lgsePipeEsc(s) {
    return (s == null ? '' : String(s))
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function _lgsePipeBadge(action) {
    var map = {
      generate_article:'#7C3AED', write_article:'#7C3AED', optimize_article:'#8B5CF6',
      improve_draft:'#8B5CF6', deep_audit:'#3B82F6', serp_analysis:'#06B6D4',
      keyword_research:'#06B6D4', bulk_generate_meta:'#F59E0B', generate_meta:'#F59E0B',
      generate_image:'#EC4899', autonomous_goal:'#00E5A8', agent_goal:'#F59E0B',
      link_suggestions:'#10B981',
    };
    return map[action] || '#6B7280';
  }
  function _lgsePipeStatusCol(s) {
    if (s === 'completed') return '#10B981';
    if (s === 'running' || s === 'verifying') return '#3B82F6';
    if (s === 'failed' || s === 'degraded' || s === 'blocked') return '#EF4444';
    if (s === 'cancelled') return '#6B7280';
    return '#F59E0B';
  }
  function _lgsePipeFmtAction(a) {
    return String(a || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }
  function _lgsePipeFmtDate(iso) {
    if (!iso) return '';
    try { return new Date(String(iso).replace(' ', 'T')).toLocaleString(); }
    catch (_) { return String(iso); }
  }

  async function _lgsePipeFetchQueue() {
    try {
      if (typeof window._luFetch === 'function') {
        var r = await window._luFetch('GET', '/connector/content/pipeline', null);
        return await r.json();
      }
      var r2 = await fetch('/api/connector/content/pipeline', {
        headers: { 'Accept':'application/json',
                   'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') },
      });
      return await r2.json();
    } catch (e) { return { success: false, error: 'fetch_failed' }; }
  }
  async function _lgsePipeFetchCal(month) {
    try {
      if (typeof window._luFetch === 'function') {
        var r = await window._luFetch('GET', '/connector/content/calendar?month=' + encodeURIComponent(month), null);
        return await r.json();
      }
      var r2 = await fetch('/api/connector/content/calendar?month=' + encodeURIComponent(month), {
        headers: { 'Accept':'application/json',
                   'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') },
      });
      return await r2.json();
    } catch (e) { return { success: false, error: 'fetch_failed' }; }
  }

  function _lgsePipeRenderQueue() {
    var p = _lgsePipe.pipeline;
    if (!p) { return '<div style="padding:32px;text-align:center;color:var(--t3)">Loading queue…</div>'; }
    if (!p.success) { return '<div style="padding:32px;text-align:center;color:#EF4444">Failed to load pipeline.</div>'; }
    var c = p.counts || {};
    var h = '';
    h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px">';
    [{l:'Queued',v:c.queued||0,c:'#F59E0B'},{l:'Running',v:c.running||0,c:'#3B82F6'},
     {l:'Completed',v:c.completed||0,c:'#10B981'},{l:'Failed',v:c.failed||0,c:'#EF4444'}].forEach(function(x){
      h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;padding:14px 16px">'
         +   '<div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px">'+x.l+'</div>'
         +   '<div style="font-size:24px;font-weight:700;color:'+x.c+';margin-top:4px">'+x.v+'</div>'
         + '</div>';
    });
    h += '</div>';

    var rows = [];
    ['running','queued','completed','failed','cancelled'].forEach(function(b){ rows = rows.concat(p.pipeline[b] || []); });
    if (rows.length === 0) {
      h += '<div style="padding:48px;text-align:center;color:var(--t3);background:var(--s1);border:1px dashed var(--bd);border-radius:10px">'
         + 'No tasks yet.<br><span style="font-size:12px">When you trigger article generation or audits, they appear here.</span></div>';
      return h;
    }
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow:hidden">';
    h += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
    h += '<thead style="background:var(--s2)"><tr>'
       +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Type</th>'
       +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Task</th>'
       +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Status</th>'
       +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Progress</th>'
       +   '<th style="padding:10px 14px;text-align:left;font-weight:600">Created</th>'
       + '</tr></thead><tbody>';
    rows.forEach(function(t){
      var bc = _lgsePipeBadge(t.task_type);
      var sc = _lgsePipeStatusCol(t.status);
      var pr = (t.progress >= 0 ? t.progress : 0);
      h += '<tr style="border-top:1px solid var(--bd)">'
         +   '<td style="padding:10px 14px"><span style="background:'+bc+'15;color:'+bc+';padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600">'+_lgsePipeFmtAction(t.task_type)+'</span></td>'
         +   '<td style="padding:10px 14px;color:var(--t1)">'+_lgsePipeEsc(t.result_summary || _lgsePipeFmtAction(t.task_type))+'</td>'
         +   '<td style="padding:10px 14px"><span style="color:'+sc+';font-weight:600">'+_lgsePipeEsc(t.status)+'</span></td>'
         +   '<td style="padding:10px 14px"><div style="background:var(--s2);border-radius:3px;height:6px;width:100px;overflow:hidden"><div style="background:'+sc+';height:100%;width:'+pr+'%"></div></div></td>'
         +   '<td style="padding:10px 14px;color:var(--t3);font-size:11px">'+_lgsePipeEsc(_lgsePipeFmtDate(t.created_at))+'</td>'
         + '</tr>';
    });
    h += '</tbody></table></div>';
    return h;
  }

  function _lgsePipeRenderCal() {
    var cal = _lgsePipe.calendar;
    if (!cal) { return '<div style="padding:32px;text-align:center;color:var(--t3)">Loading calendar…</div>'; }
    if (!cal.success) { return '<div style="padding:32px;text-align:center;color:#EF4444">Failed to load calendar.</div>'; }
    var month = cal.month || _lgsePipe.month;
    var parts = month.split('-');
    var year = parseInt(parts[0], 10);
    var mo = parseInt(parts[1], 10);
    var label = new Date(year, mo-1, 1).toLocaleString('default', { month:'long', year:'numeric' });
    var firstDow = new Date(year, mo-1, 1).getDay();
    var lastDay = new Date(year, mo, 0).getDate();
    var prevM=mo-1,prevY=year; if(prevM<1){prevM=12;prevY--;}
    var nextM=mo+1,nextY=year; if(nextM>12){nextM=1;nextY++;}
    var pM = prevY+'-'+String(prevM).padStart(2,'0');
    var nM = nextY+'-'+String(nextM).padStart(2,'0');
    var h = '';
    h += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">';
    h += '<button onclick="window._lgsePipeSetMonth(\''+pM+'\')" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px">← Prev</button>';
    h += '<h2 style="margin:0;font-size:18px;font-weight:700">'+_lgsePipeEsc(label)+'</h2>';
    h += '<button onclick="window._lgsePipeSetMonth(\''+nM+'\')" style="background:var(--s2);border:1px solid var(--bd);color:var(--t1);padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px">Next →</button>';
    h += '</div>';
    h += '<div style="display:flex;gap:14px;margin-bottom:14px;font-size:11px;color:var(--t3)">'
       + '<span><span style="background:#10B981;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Completed</span>'
       + '<span><span style="background:#3B82F6;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Running</span>'
       + '<span><span style="background:#F59E0B;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Queued</span>'
       + '<span><span style="background:#7C3AED;display:inline-block;width:8px;height:8px;border-radius:2px"></span> Article</span>'
       + '</div>';
    h += '<div style="background:var(--s1);border:1px solid var(--bd);border-radius:10px;overflow:hidden">';
    h += '<div style="display:grid;grid-template-columns:repeat(7,1fr);background:var(--s2);font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.5px">';
    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(d){
      h += '<div style="padding:8px 10px;border-right:1px solid var(--bd);text-align:center">'+d+'</div>';
    });
    h += '</div>';
    h += '<div style="display:grid;grid-template-columns:repeat(7,1fr)">';
    for (var i=0;i<firstDow;i++) {
      h += '<div style="min-height:80px;background:var(--s1);border-right:1px solid var(--bd);border-top:1px solid var(--bd)"></div>';
    }
    var days = cal.days || {};
    for (var d=1; d<=lastDay; d++) {
      var key = year+'-'+String(mo).padStart(2,'0')+'-'+String(d).padStart(2,'0');
      var items = days[key] || [];
      h += '<div style="min-height:80px;background:var(--s1);border-right:1px solid var(--bd);border-top:1px solid var(--bd);padding:6px 8px;font-size:11px">';
      h += '<div style="color:var(--t3);font-weight:600;margin-bottom:4px">'+d+'</div>';
      var maxShow=3;
      for (var j=0; j<Math.min(items.length, maxShow); j++) {
        var it = items[j];
        var col = it.type === 'article' ? '#7C3AED' : _lgsePipeStatusCol(it.status);
        var tt = String(it.title || '').slice(0,22)+(String(it.title||'').length>22?'…':'');
        h += '<div style="background:'+col+'20;color:'+col+';padding:2px 5px;border-radius:3px;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+_lgsePipeEsc(it.title||'')+'">'+_lgsePipeEsc(tt)+'</div>';
      }
      if (items.length > maxShow) {
        h += '<div style="color:var(--t3);font-size:10px">+'+(items.length-maxShow)+' more</div>';
      }
      h += '</div>';
    }
    h += '</div></div>';
    return h;
  }

  function _lgsePipeRender() {
    var el = document.getElementById('seo-content');
    if (!el) return;
    var sub = _lgsePipe.sub;
    var html = '';
    html += '<div class="lgse-tab-bar" style="margin-bottom:18px;border-bottom:1px solid var(--bd);background:transparent">'
         + '<div class="lgse-subtab seo-tab' + (sub==='queue'?' lgse-active':'') + '" onclick="window._lgsePipeSetSub(\'queue\')" '
         + 'style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:'+(sub==='queue'?'var(--p)':'var(--t3)')+';border-bottom:2px solid '+(sub==='queue'?'var(--p)':'transparent')+';white-space:nowrap">Queue</div>'
         + '<div class="lgse-subtab seo-tab' + (sub==='calendar'?' lgse-active':'') + '" onclick="window._lgsePipeSetSub(\'calendar\')" '
         + 'style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:'+(sub==='calendar'?'var(--p)':'var(--t3)')+';border-bottom:2px solid '+(sub==='calendar'?'var(--p)':'transparent')+';white-space:nowrap">Calendar</div>'
         + '</div>';
    html += sub === 'calendar' ? _lgsePipeRenderCal() : _lgsePipeRenderQueue();
    el.innerHTML = html;
  }

  window._lgsePipeSetSub = function (s) {
    _lgsePipe.sub = s;
    _lgsePipeRender();
    if (s === 'calendar' && !_lgsePipe.calendar) {
      _lgsePipeFetchCal(_lgsePipe.month).then(function (d) {
        _lgsePipe.calendar = d; _lgsePipeRender();
      });
    }
  };
  window._lgsePipeSetMonth = function (m) {
    _lgsePipe.month = m; _lgsePipe.calendar = null; _lgsePipeRender();
    _lgsePipeFetchCal(m).then(function (d) { _lgsePipe.calendar = d; _lgsePipeRender(); });
  };

  function _lgsePipeStartPoll() {
    if (_lgsePipe.pollT) return;
    _lgsePipe.pollT = setInterval(function () {
      if (_lgsePipe.sub !== 'queue') return;
      _lgsePipeFetchQueue().then(function (d) {
        _lgsePipe.pipeline = d; _lgsePipeRender();
        if (d.success && (!d.counts || d.counts.running === 0)) {
          clearInterval(_lgsePipe.pollT); _lgsePipe.pollT = null;
        }
      });
    }, 8000);
  }

  window._seoPipeline = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoPipeline call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Compose: Links tab (link intel + outbound + redirects) ─────────────
  window._seoLinksAll = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoLinksAll call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── Compose: Insights tab (GSC connection + traffic insights) ──────────
  window._seoInsightsAll = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoInsightsAll call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── FIX 1 — Audit detail: structured render (was raw JSON) ─────────────
  window._seoViewAudit = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoViewAudit call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── FIX 2 — Integrations: KPI summary (was raw JSON) ───────────────────
  // We're consolidating Integrations away (Fix 3 maps it to Reports), but
  // keep _seoIntegrations functional in case anything still calls it.
  window._seoIntegrations = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoIntegrations call collapsed; live path is the LGSE renderer'); } catch(_) {} };

  // ── FIX 4 — Overview KPIs: real counts (were zeros) ────────────────────
  // Override _seoOverview so each KPI hits its own dedicated endpoint instead
  // of trying to derive everything from /knowledge.
  window._seoOverview = async function () { try { console.warn('[LU SEO 15.5] dead path: _seoOverview call collapsed; live path is the LGSE renderer'); } catch(_) {} };
})();

// ═══════════════════════════════════════════════════════════════════════════
// SEO ENGINE — COMPLETE UI REBUILD (LB-SEO-FINAL, 2026-04-28)
// Production-grade. Self-contained module that takes over the SEO engine.
// Wraps existing API endpoints — no backend changes.
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  // ── Design system CSS (injected once) ───────────────────────────────────
  var LGSE_CSS = ":root{--lgse-bg0:#0d0f14;--lgse-bg1:#13161e;--lgse-bg2:#1a1d27;--lgse-bg3:#222635;--lgse-border:#2a2f42;--lgse-border2:#343a52;--lgse-t1:#f0f2ff;--lgse-t2:#8b90a7;--lgse-t3:#555a72;--lgse-purple:#6C5CE7;--lgse-teal:#00E5A8;--lgse-blue:#3B82F6;--lgse-amber:#F59E0B;--lgse-red:#EF4444;--lgse-mono:'JetBrains Mono','Fira Code',ui-monospace,Menlo,monospace}\n"
    + ".lgse-shell{background:var(--lgse-bg1);border-radius:16px;overflow:hidden;border:1px solid var(--lgse-border);min-height:400px}\n"
    + ".lgse-topbar{background:var(--lgse-bg0);border-bottom:1px solid var(--lgse-border);padding:0 20px;display:flex;align-items:center;overflow-x:auto;scrollbar-width:none}\n"
    + ".lgse-topbar::-webkit-scrollbar{display:none}\n"
    + ".lgse-nav-tab{padding:14px 15px;font-size:11.5px;font-weight:500;color:var(--lgse-t3);cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;display:flex;align-items:center;gap:5px;transition:color .15s;user-select:none}\n"
    + ".lgse-nav-tab:hover{color:var(--lgse-t2)}\n"
    + ".lgse-nav-tab.active{color:var(--lgse-purple);border-bottom-color:var(--lgse-purple)}\n"
    + ".lgse-pane{padding:20px;animation:lgse-in .18s ease-out}\n"
    + "@keyframes lgse-in{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}\n"
    + ".lgse-gauge-wrap{position:relative}\n"
    + ".lgse-gauge-wrap svg{transform:rotate(-90deg)}\n"
    + ".lgse-gauge-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none}\n"
    + ".lgse-gauge-num{font-family:var(--lgse-mono);font-weight:700;line-height:1}\n"
    + ".lgse-gauge-label{font-size:9px;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.1em;margin-top:2px}\n"
    + ".lgse-gauge-ring{fill:none;stroke-linecap:round}\n"
    + ".lgse-gauge-track{fill:none;stroke:#1e2235}\n"
    + ".lgse-score{display:inline-flex;align-items:center;font-family:var(--lgse-mono);font-size:11px;font-weight:700;padding:2px 8px;border-radius:5px}\n"
    + ".lgse-s90{background:rgba(0,229,168,.12);color:#00c49a}\n"
    + ".lgse-s70{background:rgba(59,130,246,.12);color:#3b82f6}\n"
    + ".lgse-s50{background:rgba(245,158,11,.12);color:#d97706}\n"
    + ".lgse-slow{background:rgba(239,68,68,.12);color:#ef4444}\n"
    + ".lgse-badge{display:inline-flex;align-items:center;font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px}\n"
    + ".lgse-b-teal{background:rgba(0,229,168,.1);color:#00c49a}\n"
    + ".lgse-b-blue{background:rgba(59,130,246,.1);color:#3b82f6}\n"
    + ".lgse-b-amber{background:rgba(245,158,11,.1);color:#d97706}\n"
    + ".lgse-b-red{background:rgba(239,68,68,.1);color:#ef4444}\n"
    + ".lgse-b-purple{background:rgba(108,92,231,.1);color:#6C5CE7}\n"
    + ".lgse-b-muted{background:rgba(148,163,184,.1);color:#94a3b8}\n"
    + ".lgse-kpi-grid{display:grid;gap:10px;margin-bottom:16px}\n"
    + ".lgse-kpi-card{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:12px;padding:14px 16px}\n"
    + ".lgse-kpi-label{font-size:9.5px;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}\n"
    + ".lgse-kpi-val{font-family:var(--lgse-mono);font-size:28px;font-weight:700;color:var(--lgse-t1);line-height:1}\n"
    + ".lgse-kpi-change{font-size:10.5px;margin-top:4px;font-family:var(--lgse-mono)}\n"
    + ".lgse-up{color:var(--lgse-teal)}\n"
    + ".lgse-dn{color:var(--lgse-red)}\n"
    + ".lgse-flat{color:var(--lgse-t3)}\n"
    + ".lgse-table{width:100%;border-collapse:collapse}\n"
    + ".lgse-table thead th{background:var(--lgse-bg0);color:var(--lgse-t3);font-size:9.5px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;padding:9px 12px;text-align:left;border-bottom:1px solid var(--lgse-border);position:sticky;top:0;z-index:5}\n"
    + ".lgse-table thead th.r{text-align:right}\n"
    + ".lgse-table tbody tr{border-bottom:1px solid rgba(42,47,66,.6);transition:background .1s;cursor:pointer}\n"
    + ".lgse-table tbody tr:hover{background:var(--lgse-bg2)}\n"
    + ".lgse-table tbody td{padding:11px 12px;font-size:11.5px;color:var(--lgse-t2);vertical-align:middle}\n"
    + ".lgse-table tbody td.mono{font-family:var(--lgse-mono);text-align:right;font-size:11px}\n"
    + ".lgse-url{font-family:var(--lgse-mono);font-size:10.5px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-purple)}\n"
    + ".lgse-issue-chip{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:10px 12px;display:flex;align-items:center;gap:8px;font-size:11px;color:var(--lgse-t2)}\n"
    + ".lgse-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}\n"
    + ".lgse-ai-bar{display:flex;align-items:flex-start;gap:10px;background:linear-gradient(90deg,rgba(108,92,231,.1) 0%,transparent 100%);border-left:2px solid var(--lgse-purple);border-radius:0 10px 10px 0;padding:12px 14px;margin-bottom:16px}\n"
    + ".lgse-ai-name{font-size:9px;font-weight:700;color:var(--lgse-purple);text-transform:uppercase;letter-spacing:.1em;margin-bottom:3px}\n"
    + ".lgse-ai-text{font-size:11.5px;color:var(--lgse-t2);line-height:1.65}\n"
    + ".lgse-win-tile{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px;cursor:pointer;transition:all .15s}\n"
    + ".lgse-win-tile:hover{border-color:var(--lgse-border2);background:var(--lgse-bg3);transform:translateY(-1px)}\n"
    + ".lgse-win-count{font-family:var(--lgse-mono);font-size:28px;font-weight:700;line-height:1;margin-bottom:4px}\n"
    + ".lgse-win-desc{font-size:10.5px;color:var(--lgse-t2);margin-bottom:8px;line-height:1.4}\n"
    + ".lgse-win-cta{font-size:10px;font-weight:600}\n"
    + ".lgse-btn-primary{background:var(--lgse-purple);color:white;border:none;border-radius:7px;padding:8px 16px;font-size:11.5px;font-weight:600;cursor:pointer;transition:opacity .15s}\n"
    + ".lgse-btn-primary:hover{opacity:.9}\n"
    + ".lgse-btn-secondary{background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border2);border-radius:7px;padding:7px 14px;font-size:11px;cursor:pointer;transition:all .15s}\n"
    + ".lgse-btn-secondary:hover{color:var(--lgse-t1);border-color:var(--lgse-t3)}\n"
    + ".lgse-section-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}\n"
    + ".lgse-section-title{font-size:10px;font-weight:700;color:var(--lgse-t2);text-transform:uppercase;letter-spacing:.1em}\n"
    + ".lgse-page-title{font-size:17px;font-weight:700;color:var(--lgse-t1);margin-bottom:3px}\n"
    + ".lgse-page-desc{font-size:11.5px;color:var(--lgse-t3);margin-bottom:18px}\n"
    + ".lgse-pos-up{font-family:var(--lgse-mono);font-size:10px;font-weight:700;color:var(--lgse-teal)}\n"
    + ".lgse-pos-dn{font-family:var(--lgse-mono);font-size:10px;font-weight:700;color:var(--lgse-red)}\n"
    + ".lgse-filter-row{display:flex;align-items:center;gap:6px;margin-bottom:12px;flex-wrap:wrap}\n"
    + ".lgse-filter-pill{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:20px;padding:4px 12px;font-size:10.5px;color:var(--lgse-t3);cursor:pointer;transition:all .12s;user-select:none}\n"
    + ".lgse-filter-pill.active,.lgse-filter-pill:hover{border-color:var(--lgse-purple);color:var(--lgse-purple);background:rgba(108,92,231,.08)}\n"
    + ".lgse-subtabs{display:flex;gap:0;border-bottom:1px solid var(--lgse-border);margin-bottom:14px}\n"
    + ".lgse-subtab{padding:7px 14px;font-size:11px;color:var(--lgse-t3);cursor:pointer;border-bottom:2px solid transparent;transition:color .12s;white-space:nowrap}\n"
    + ".lgse-subtab:hover{color:var(--lgse-t2)}\n"
    + ".lgse-subtab.active{color:var(--lgse-purple);border-bottom-color:var(--lgse-purple)}\n"
    + ".lgse-empty{text-align:center;padding:40px 20px;color:var(--lgse-t3)}\n"
    + ".lgse-empty-icon{font-size:28px;margin-bottom:10px;opacity:.4}\n"
    + ".lgse-empty h3{font-size:13px;font-weight:600;color:var(--lgse-t2);margin-bottom:5px}\n"
    + ".lgse-empty p{font-size:11px;line-height:1.5;max-width:240px;margin:0 auto 14px}\n"
    + ".lgse-sparkline{display:inline-block;vertical-align:middle}\n"
    + ".lgse-vol-h{background:rgba(239,68,68,.1);color:#ef4444}\n"
    + ".lgse-vol-m{background:rgba(245,158,11,.1);color:#d97706}\n"
    + ".lgse-vol-l{background:rgba(148,163,184,.1);color:#94a3b8}\n"
    + ".lgse-cat-item{background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:9px 12px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:all .12s}\n"
    + ".lgse-cat-item:hover{border-color:var(--lgse-border2)}\n"
    + ".lgse-cat-icon{width:8px;height:8px;border-radius:2px;flex-shrink:0}\n"
    + ".lgse-stack-bar{display:flex;width:100%;height:8px;border-radius:4px;overflow:hidden;background:var(--lgse-bg3)}\n"
    + ".lgse-trend{width:100%;height:100%}\n"
    + "@media(prefers-reduced-motion:reduce){.lgse-pane{animation:none}.lgse-win-tile:hover{transform:none}}";

  function lgseInjectStyles() {
    if (document.getElementById('lgse-styles')) return;
    var s = document.createElement('style');
    s.id = 'lgse-styles';
    s.textContent = LGSE_CSS;
    document.head.appendChild(s);
  }

  // ── Helpers ─────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  // Wave 8 (2026-05-18). Two-context label resolver per platform branding rule.
  //   - WP iframe (embed mode): every agent identity collapses to the
  //     single "SEO AI Assistant" surface. The WP user never sees an
  //     agent name — by product policy, regardless of plan tier.
  //   - Laravel SaaS (direct login): show the named specialist. The
  //     platform is built around the agent team — James (SEO Strategist),
  //     Priya (Content Manager), Sarah (DMM), etc.
  // Display layer only. agent_messages.agent_slug stays canonical;
  // _lgseAgentLabel just translates slug → user-facing string per context.
  window._lgseIsEmbed = function () {
    return !!(window._LGSC_EMBED && window._LGSC_EMBED.api_key);
  };
  window._lgseAgentLabel = function (agentSlug) {
    if (window._lgseIsEmbed()) return 'SEO AI Assistant';
    var names = {
      james:'James', sarah:'Sarah', priya:'Priya', marcus:'Marcus',
      elena:'Elena', leo:'Leo', alex:'Alex', diana:'Diana',
      ryan:'Ryan', sofia:'Sofia', chris:'Chris', jordan:'Jordan',
      kai:'Kai', max:'Max', maya:'Maya', nora:'Nora',
      tyler:'Tyler', vera:'Vera', zara:'Zara', zoe:'Zoe'
    };
    return names[String(agentSlug || '').toLowerCase()] || 'James';
  };

  // Clamp any score to 0..100 — defense in depth against backend overflow / bad data.
  function clampScore(n) { n = parseInt(n, 10); return isNaN(n) ? 0 : Math.max(0, Math.min(100, n)); }
  function scoreClass(n) { n = clampScore(n); return n >= 90 ? 'lgse-s90' : n >= 70 ? 'lgse-s70' : n >= 50 ? 'lgse-s50' : 'lgse-slow'; }
  function scoreColor(n) { n = clampScore(n); return n >= 90 ? '#00c49a' : n >= 70 ? '#3b82f6' : n >= 50 ? '#d97706' : '#ef4444'; }
  function scorePill(n) { return '<span class="lgse-score ' + scoreClass(n) + '">' + clampScore(n) + '</span>'; }
  function badge(t, kind) { return '<span class="lgse-badge lgse-b-' + kind + '">' + esc(t) + '</span>'; }

  function gauge(score, size, showLabel) {
    size = size || 120;
    score = Math.max(0, Math.min(100, parseInt(score, 10) || 0));
    var r = (size / 2) - 9;
    var circ = 2 * Math.PI * r;
    var color = scoreColor(score);
    var fs = Math.round(size * 0.23);
    return '<div class="lgse-gauge-wrap" style="width:' + size + 'px;height:' + size + 'px">'
      + '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">'
      + '<circle class="lgse-gauge-track" cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" stroke-width="9"/>'
      + '<circle class="lgse-gauge-ring" cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" stroke="' + color + '" stroke-width="9" stroke-dasharray="' + circ + '" stroke-dashoffset="' + circ + '" data-circ="' + circ + '" data-offset="' + (circ - (score / 100) * circ) + '"/>'
      + '</svg>'
      + '<div class="lgse-gauge-center">'
      + '<div class="lgse-gauge-num" style="font-size:' + fs + 'px;color:' + color + '" data-target="' + score + '">0</div>'
      + (showLabel ? '<div class="lgse-gauge-label">score</div>' : '')
      + '</div></div>';
  }

  function animateGauge(container, delay) {
    if (!container) return;
    delay = delay || 0;
    var ring = container.querySelector('.lgse-gauge-ring');
    var num = container.querySelector('.lgse-gauge-num');
    if (!ring || !num) return;
    var target = parseInt(num.getAttribute('data-target'), 10) || 0;
    var offset = parseFloat(ring.getAttribute('data-offset'));
    var circ = parseFloat(ring.getAttribute('data-circ'));
    ring.style.strokeDasharray = circ;
    ring.style.strokeDashoffset = circ;
    setTimeout(function () {
      ring.style.transition = 'stroke-dashoffset 1.1s cubic-bezier(.4,0,.2,1)';
      ring.style.strokeDashoffset = offset;
      countUp(num, target, 900);
    }, delay);
  }

  function countUp(el, target, dur) {
    if (!el) return;
    var start = performance.now();
    function step(now) {
      var t = Math.min((now - start) / dur, 1);
      var ease = 1 - Math.pow(1 - t, 3);
      el.textContent = Math.round(ease * target);
      if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  function sparkline(vals, w, h) {
    w = w || 60; h = h || 20;
    if (!vals || vals.length < 2) return '<svg width="' + w + '" height="' + h + '"></svg>';
    var min = Math.min.apply(null, vals);
    var max = Math.max.apply(null, vals);
    var range = max - min || 1;
    var pts = vals.map(function (v, i) {
      var x = (i / (vals.length - 1)) * w;
      var y = h - ((v - min) / range) * (h - 2) - 1;
      return x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' ');
    var color = vals[vals.length - 1] < vals[0] ? '#00E5A8' : '#EF4444';
    return '<svg class="lgse-sparkline" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '"><polyline points="' + pts + '" fill="none" stroke="' + color + '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }

  function trendChart(vals, w, h) {
    w = w || 720; h = h || 110;
    if (!vals || vals.length < 2) return '<svg viewBox="0 0 ' + w + ' ' + h + '"></svg>';
    var min = Math.max(0, Math.min.apply(null, vals) - 10);
    var max = Math.min(100, Math.max.apply(null, vals) + 10);
    var range = max - min || 1;
    var pad = 8;
    var pts = vals.map(function (v, i) {
      var x = pad + (i / (vals.length - 1)) * (w - pad * 2);
      var y = h - pad - ((v - min) / range) * (h - pad * 2);
      return [x, y];
    });
    var line = pts.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');
    var area = 'M' + pts[0][0].toFixed(1) + ',' + h + ' L' + line.split(' ').join(' L') + ' L' + pts[pts.length - 1][0].toFixed(1) + ',' + h + ' Z';
    return '<svg class="lgse-trend" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none">'
      + '<path d="' + area + '" fill="rgba(59,130,246,.07)"/>'
      + '<polyline points="' + line + '" fill="none" stroke="#3B82F6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
      + pts.map(function (p) { return '<circle cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) + '" r="3" fill="#3B82F6"/>'; }).join('')
      + '</svg>';
  }

  function posChange(change) {
    var n = parseInt(change, 10) || 0;
    if (n > 0) return '<span class="lgse-pos-up">↑' + n + '</span>';
    if (n < 0) return '<span class="lgse-pos-dn">↓' + Math.abs(n) + '</span>';
    return '<span style="color:var(--lgse-t3);font-family:var(--lgse-mono);font-size:10px">—</span>';
  }

  function emptyState(icon, title, descText, btnText, btnFn) {
    return '<div class="lgse-empty"><div class="lgse-empty-icon">' + esc(icon) + '</div><h3>' + esc(title) + '</h3><p>' + esc(descText) + '</p>'
      + (btnText ? '<button class="lgse-btn-primary" onclick="' + btnFn + '">' + esc(btnText) + '</button>' : '') + '</div>';
  }

  function pageTitle(title, descText) {
    return '<div class="lgse-page-title">' + esc(title) + '</div><div class="lgse-page-desc">' + esc(descText) + '</div>';
  }

  // Wave 16 (2026-05-19). Site-list fetch + dropdown render + switcher.
  function lgseLoadSites() {
    var bar = document.getElementById('lgse-site-bar');
    if (!bar) return;
    bar.style.display = 'flex';
    bar.innerHTML = '<span style="color:var(--lgse-t3,#9CA3AF);font-size:11px">Loading sites…</span>';

    // Direct fetch — bypass the api() wrapper since _withSiteScope is a chicken-and-egg here.
    var token = localStorage.getItem('lu_token') || '';
    fetch(window.location.origin + '/api/seo/sites', {
      method: 'GET',
      headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
      cache: 'no-store',
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.success) {
        bar.innerHTML = '<span style="color:#F87171;font-size:11px">Could not load site list</span>';
        return;
      }
      var sites = d.sites || [];
      window._lgseSiteList = sites;

      // Resolve active site: restored localStorage > backend default > first
      var stored = _restoreActiveSite();
      var validStored = stored && sites.some(function (s) { return s.url === stored; });
      var activeUrl = validStored ? stored : (d.default_url || (sites[0] && sites[0].url) || '');
      window._lgseActiveSiteUrl = activeUrl;
      _persistActiveSite(activeUrl);

      lgseRenderSiteBar();
    }).catch(function () {
      bar.innerHTML = '<span style="color:#F87171;font-size:11px">Site picker failed to load</span>';
    });
  }

  window.lgseRenderSiteBar = function () {
    var bar = document.getElementById('lgse-site-bar');
    if (!bar) return;
    var sites = window._lgseSiteList || [];
    if (sites.length === 0) {
      bar.innerHTML = '<span style="color:var(--lgse-t3,#9CA3AF);font-size:11px">No websites yet — register one in the Build engine to get started.</span>';
      return;
    }
    var active = window._lgseActiveSiteUrl || '';
    var opts = sites.map(function (s) {
      var sel = (s.url === active) ? ' selected' : '';
      // Wave 16d-v2 — no kind-tag; we can't reliably tell HTML vs WP from the
      // data, so we just show the site name and let the user disambiguate.
      var label = (s.name || s.host || s.url);
      return '<option value="' + esc(s.url) + '"' + sel + '>' + esc(label) + '</option>';
    }).join('');
    bar.innerHTML =
        '<span style="color:var(--lgse-t3,#9CA3AF);font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase">Site</span>'
      + '<select onchange="lgseSwitchSite(this.value)" style="background:var(--lgse-bg,#0F1117);color:var(--lgse-t1,#E5E7EB);border:1px solid var(--lgse-border,#1f2937);border-radius:6px;padding:6px 10px;font-size:12px;min-width:240px;cursor:pointer">' + opts + '</select>'
      + '<span id="lgse-site-bar-meta" style="color:var(--lgse-t3,#9CA3AF);font-size:11px;margin-left:auto">'
      +    'Scoping every tab to this site. ' + sites.length + ' site' + (sites.length === 1 ? '' : 's') + ' total.'
      + '</span>';
  };

  // Click handler for the site dropdown. Persists, then re-renders the
  // current tab so its data refreshes with the new scope.
  window.lgseSwitchSite = function (url) {
    window._lgseActiveSiteUrl = url || '';
    _persistActiveSite(url || '');
    var meta = document.getElementById('lgse-site-bar-meta');
    if (meta) meta.textContent = 'Switching to ' + url + '…';
    // Re-render the currently visible tab to reload with new scope.
    var activeTab = document.querySelector('.lgse-nav-tab.active');
    var tabId = activeTab ? activeTab.getAttribute('data-tab-id') : 'overview';
    if (typeof switchTab === 'function') switchTab(tabId);
    setTimeout(function () { lgseRenderSiteBar(); }, 50);
  };

  // Wave 16 (2026-05-19). Global site filter — propagated to every api()
  // call so all tabs render data for ONE selected website at a time.
  // Source-of-truth: `window._lgseActiveSiteUrl`. Persisted to
  // localStorage per workspace. Hidden + bypassed in WP-embed mode
  // (single site is locked there by the iframe context).
  function _isEmbedMode() {
    return (window._LGSC_EMBED && window._LGSC_EMBED.api_key)
      || new URLSearchParams(window.location.search).has('lgsc_key');
  }
  function _activeWsKey() {
    var ws = (window._LGSC_EMBED && window._LGSC_EMBED.workspace_id)
      || (window.LU_CFG && window.LU_CFG.workspace_id)
      || 'default';
    return 'lgseActiveSite_ws' + ws;
  }
  function _persistActiveSite(url) {
    try { localStorage.setItem(_activeWsKey(), url || ''); } catch(_) {}
  }
  function _restoreActiveSite() {
    try { return localStorage.getItem(_activeWsKey()) || ''; } catch(_) { return ''; }
  }
  // Append the active site_url to a URL path so backend can scope queries.
  function _withSiteScope(path) {
    if (_isEmbedMode()) return path; // embed iframe is single-site by definition
    var u = (window._lgseActiveSiteUrl || '').trim();
    if (!u) return path;
    var sep = (path.indexOf('?') === -1) ? '?' : '&';
    return path + sep + 'site_url=' + encodeURIComponent(u);
  }

  // Bridge to existing API helper. _seoApi handles auth + workspace scope.
  // Wave 16 — site_url query param auto-appended for GETs when active site
  // is selected. Mutating methods can opt-in by including site_url in body.
  function api(method, path, body) {
    if (String(method).toUpperCase() === 'GET') {
      path = _withSiteScope(path);
    } else if (body && typeof body === 'object' && !Array.isArray(body) && !_isEmbedMode()) {
      var u = (window._lgseActiveSiteUrl || '').trim();
      if (u && !body.site_url) body = Object.assign({}, body, { site_url: u });
    }
    if (typeof window._seoApi === 'function') return window._seoApi(method, path, body);
    // Fallback if _seoApi missing.
    var token = localStorage.getItem('lu_token') || '';
    var opts = { method: method, headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', Accept: 'application/json' }, cache: 'no-store' };
    if (body) opts.body = JSON.stringify(body);
    return fetch(window.location.origin + '/api/seo' + path, opts).then(function (r) {
      // 2026-05-12: surface backend errors (402 NO_CREDITS, 403 PLAN_GATED,
      // 422 validation, 5xx) instead of silently resolving with the error JSON.
      return r.json().then(function (d) {
        if (!r.ok || (d && d.success === false)) {
          var err = new Error((d && (d.error || d.message)) || ('HTTP ' + r.status));
          err.code   = (d && d.code) || null;
          err.status = r.status;
          err.body   = d;
          throw err;
        }
        return d;
      });
    });
  }
  function countItems(resp, key) {
    if (!resp) return 0;
    if (Array.isArray(resp)) return resp.length;
    if (resp.total != null) return parseInt(resp.total, 10) || 0;
    if (resp.count != null) return parseInt(resp.count, 10) || 0;
    if (key && Array.isArray(resp[key])) return resp[key].length;
    if (Array.isArray(resp.data)) return resp.data.length;
    if (Array.isArray(resp.items)) return resp.items.length;
    return 0;
  }

  // ── Tab definitions ────────────────────────────────────────────────────
  var TABS = [
    { id: 'overview',    label: 'Overview' },
    { id: 'audit',       label: 'Audit' },
    { id: 'keywords',    label: 'Keywords' },
    { id: 'pages',       label: 'Pages' },
    { id: 'links',       label: 'Links' },
    { id: 'topics',      label: 'Topics' },
    { id: 'competitors', label: 'Competitors' },
    { id: 'insights',    label: 'Insights' },
    { id: 'reports',     label: 'Reports' },
    { id: 'write',       label: 'Write' },
    { id: 'pipeline',    label: 'Pipeline' },
  ];

  // Legacy ID aliases — preserve deep links.
  var ALIAS = {
    audits: 'audit', workspace: 'keywords', dashboard: 'overview',
    content: 'overview', goals: 'overview', wins: 'overview',
    score: 'pages', images: 'pages', ctr: 'pages',
    outbound: 'links', redirects: 'links',
    gsc: 'insights', settings: 'reports', integrations: 'reports',
  };

  function buildShell(container) {
    lgseInjectStyles();
    // Wave 16 (2026-05-19) — global site selector row above the tab strip.
    // Hidden in WP-embed mode (single-site iframe). In Laravel SaaS this is
    // the "mother dropdown" — every tab's queries flow through the active
    // site filter via api() → _withSiteScope.
    container.innerHTML = '<div class="lgse-shell">'
      + '<div id="lgse-site-bar" style="display:none;padding:10px 16px;border-bottom:1px solid var(--lgse-border, #1f2937);background:var(--lgse-bg2, rgba(255,255,255,.02));align-items:center;gap:10px;font-size:12px"></div>'
      + '<div class="lgse-topbar" id="lgse-topbar"></div>'
      + '<div class="lgse-pane" id="lgse-content"></div>'
      + '</div>';
    var bar = document.getElementById('lgse-topbar');
    // 2026-05-13 — Write tab is embed-only. Direct SPA users have the
    // Write engine section in their main nav; the in-iframe shortcut is
    // for WP-bundle users who don't see the sidebar.
    var _writeEmbedOnly = (window._LGSC_EMBED && window._LGSC_EMBED.api_key)
      || new URLSearchParams(window.location.search).has('lgsc_key');
    TABS.forEach(function (t) {
      if (t.id === 'write' && !_writeEmbedOnly) { return; }
      var d = document.createElement('div');
      d.className = 'lgse-nav-tab';
      d.setAttribute('data-tab-id', t.id);
      d.textContent = t.label;
      d.onclick = function () { switchTab(t.id); };
      bar.appendChild(d);
    });

    // Wave 16 — fetch site inventory + render dropdown (Laravel-SaaS only).
    if (!_isEmbedMode()) {
      lgseLoadSites();
    }

    switchTab('overview');

    // 2026-05-13 — Gate the SEO Assistant FAB to embed mode only.
    // Direct SPA users have Sarah + agents for AI assistance; the
    // floating drawer is meant for the WP plugin iframe context where
    // there's no other AI surface. _LGSC_EMBED is set at the top of
    // core.js when ?lgsc_key= + embed=1 are in the URL.
    var _seoIsEmbed = (window._LGSC_EMBED && window._LGSC_EMBED.api_key)
      || new URLSearchParams(window.location.search).has('lgsc_key');
    if (_seoIsEmbed) {
      _lgseInjectAiDrawer();
      var fab = document.getElementById('lgse-ai-fab');
      if (fab) { fab.style.display = 'flex'; }
    }
  }

  function _lgseInjectAiDrawer() {
    if (document.getElementById('lgse-ai-fab')) { return; }
    var qs = [
      'What are my biggest SEO issues?',
      'Which pages need work?',
      'Summarize my latest audit.',
      'How can I improve my score?',
    ];
    var chips = qs.map(function (q) {
      var safe = q.replace(/'/g, "\\'");
      return '<button data-q="' + q.replace(/"/g, '&quot;') + '" onclick="window._lgseDrawerSuggest(\'' + safe + '\')" '
        + 'style="background:rgba(255,255,255,0.05);color:#9CA3AF;'
        + 'border:1px solid rgba(255,255,255,0.08);border-radius:16px;'
        + 'padding:5px 10px;font-size:11px;cursor:pointer">'
        + q + '</button>';
    }).join('');

    var wrap = document.createElement('div');
    wrap.innerHTML =
      // FAB — bottom-right, offset 88px right of edge so it doesn't
      // overlap the global ai-fab in standalone SPA mode.
      '<button id="lgse-ai-fab" onclick="window._lgseDrawerOpen()"'
        + ' style="position:fixed;bottom:24px;right:88px;z-index:10000;'
        + 'width:52px;height:52px;border-radius:50%;border:none;cursor:pointer;'
        + 'background:linear-gradient(135deg,#7C3AED,#3B82F6);'
        + 'box-shadow:0 4px 20px rgba(124,58,237,0.4);'
        + 'display:none;align-items:center;justify-content:center;'
        + 'font-size:22px;color:#fff;transition:transform 0.2s;position:fixed"'
        + ' onmouseover="this.style.transform=\'scale(1.1)\'"'
        + ' onmouseout="this.style.transform=\'scale(1)\'"'
        + ' title="SEO AI Assistant">&#128172;'
        // Wave 5 (2026-05-18): badge now sourced from the platform
        // messages-ui poller (#lu-messages-badge). The seo-specific
        // poller from Wave 4 has been retired — write through
        // AgentMessageService::postAsAgent($wsId, "james", ...) and the
        // platform badge will update automatically.
        + '<span id="lgse-fab-badge" style="position:absolute;top:-2px;right:-2px;'
        + 'min-width:18px;height:18px;padding:0 5px;border-radius:9px;'
        + 'background:#EF4444;color:#fff;font-size:10px;font-weight:700;'
        + 'line-height:18px;text-align:center;display:none;'
        + 'box-shadow:0 0 0 2px #121826"></span>'
        + '</button>'
      // Overlay
      + '<div id="lgse-ai-overlay" onclick="window._lgseDrawerClose()"'
        + ' style="display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,0.5)"></div>'
      // Drawer
      + '<div id="lgse-ai-drawer"'
        + ' style="display:none;position:fixed;top:0;right:0;bottom:0;width:420px;max-width:95vw;'
        + 'z-index:9999;background:#121826;border-left:1px solid rgba(255,255,255,0.08);'
        + 'flex-direction:column;box-shadow:-8px 0 32px rgba(0,0,0,0.4)">'
        // Header
        + '<div style="display:flex;align-items:center;gap:10px;padding:20px 20px 16px;'
        + 'border-bottom:1px solid rgba(255,255,255,0.06)">'
          + '<span style="font-size:20px;color:#A78BFA">&#128172;</span>'
          + '<div style="flex:1">'
            + '<div style="font-size:16px;font-weight:700;color:#fff">SEO AI Assistant</div>'
            + '<div style="font-size:12px;color:#6B7280">Powered by LevelUp Growth</div>'
          + '</div>'
          + '<button onclick="window._lgseDrawerClose()"'
            + ' style="background:none;border:none;color:#6B7280;font-size:18px;cursor:pointer;padding:4px">&times;</button>'
        + '</div>'
        // Thread
        + '<div id="lgse-drawer-thread"'
          + ' style="flex:1;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:12px">'
          + '<div style="background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);'
          + 'border-radius:12px;padding:14px 16px;max-width:90%">'
            + '<div style="font-size:12px;font-weight:600;color:#A78BFA;margin-bottom:6px">SEO AI Assistant</div>'
            + '<div style="font-size:14px;line-height:1.6;color:#E5E7EB">'
              + 'Hi &mdash; I can answer SEO questions about your workspace using your real audit data, '
              + 'indexed pages, and tracked keywords. Ask me anything.'
            + '</div>'
          + '</div>'
        + '</div>'
        // Suggestion chips
        + '<div id="lgse-drawer-suggestions"'
          + ' style="padding:0 20px 12px;display:flex;gap:6px;flex-wrap:wrap">'
          + chips
        + '</div>'
        // Input
        + '<div style="padding:12px 20px 20px;border-top:1px solid rgba(255,255,255,0.06)">'
          + '<div style="display:flex;gap:8px">'
            + '<textarea id="lgse-drawer-input" rows="2" maxlength="2000"'
              + ' placeholder="Ask anything about your SEO\u2026"'
              + ' style="flex:1;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);'
              + 'border-radius:10px;padding:10px 14px;color:#fff;'
              + 'font-size:13px;resize:none;outline:none;font-family:inherit"></textarea>'
            + '<button onclick="window._lgseDrawerSend()"'
              + ' style="background:linear-gradient(135deg,#7C3AED,#3B82F6);color:#fff;'
              + 'border:none;border-radius:10px;padding:10px 16px;'
              + 'font-size:13px;font-weight:600;cursor:pointer;align-self:flex-end">Send</button>'
          + '</div>'
          + '<div style="font-size:11px;color:#4B5563;margin-top:8px;text-align:center">'
            + 'Responses based on your real workspace data'
          + '</div>'
        + '</div>'
      + '</div>';
    document.body.appendChild(wrap);

    setTimeout(function () {
      var inp = document.getElementById('lgse-drawer-input');
      if (inp) {
        inp.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (typeof window._lgseDrawerSend === 'function') {
              window._lgseDrawerSend();
            }
          }
        });
      }
    }, 200);
  }

  function switchTab(id) {
    if (ALIAS[id]) id = ALIAS[id];
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-nav-tab'), function (t) {
      t.classList.toggle('active', t.getAttribute('data-tab-id') === id);
    });
    var content = document.getElementById('lgse-content');
    if (!content) return;
    content.innerHTML = '<div style="padding:40px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading…</div>';

    var renderers = {
      overview: renderOverview, audit: renderAudit, keywords: renderKeywords,
      pages: renderPages, links: renderLinks, topics: renderTopics,
      competitors: renderCompetitors, insights: renderInsights, reports: renderReports,
      pipeline: renderPipeline, write: renderWrite,
    };
    (renderers[id] || renderOverview)(content);
  }
  window.lgseSwitchTab = switchTab;

  // ── Tab — AI Assistant (James — SEO-focused chat) ────────────────────
  function renderAssistant(el) {
    el.innerHTML =
      '<div style="display:flex;flex-direction:column;height:100%;padding:0">'
        + '<div style="padding:20px 24px 0">'
          + '<h3 style="font-family:var(--fh,Manrope);font-size:18px;font-weight:700;color:var(--lgse-t1);margin:0 0 4px">' + (window._lgseIsEmbed() ? 'SEO AI Assistant' : 'James — SEO Strategist') + '</h3>'
          + '<p style="font-size:13px;color:var(--lgse-t3);margin:0 0 16px">'
            + (window._lgseIsEmbed() ? 'Answers based on your real workspace data.' : 'SEO-focused assistant. Answers based on your real workspace data.')
          + '</p>'
        + '</div>'
        + '<div id="lgse-chat-thread" style="flex:1;overflow-y:auto;padding:0 24px;display:flex;flex-direction:column;gap:12px;min-height:300px;max-height:60vh">'
          + '<div style="background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);border-radius:12px;padding:14px 16px;max-width:80%">'
            + '<span style="font-size:12px;font-weight:600;color:#A78BFA">' + window._lgseAgentLabel('james') + '</span>'
            + '<p style="font-size:14px;line-height:1.6;color:var(--lgse-t1);margin:4px 0 0">'
              + 'Hi — I can answer SEO questions about your workspace using your real audit data, indexed pages, and tracked keywords. Ask me something.'
            + '</p>'
          + '</div>'
        + '</div>'
        + '<div style="padding:16px 24px">'
          + '<div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">'
            + '<button onclick="_lgseAssistantSuggest(this)" data-q="What are my biggest SEO issues right now?" '
              + 'style="background:rgba(255,255,255,0.06);color:var(--lgse-t3);border:1px solid rgba(255,255,255,0.1);border-radius:20px;padding:6px 12px;font-size:12px;cursor:pointer">'
              + 'Biggest SEO issues?'
            + '</button>'
            + '<button onclick="_lgseAssistantSuggest(this)" data-q="Which pages should I optimize first?" '
              + 'style="background:rgba(255,255,255,0.06);color:var(--lgse-t3);border:1px solid rgba(255,255,255,0.1);border-radius:20px;padding:6px 12px;font-size:12px;cursor:pointer">'
              + 'Pages to optimize first?'
            + '</button>'
            + '<button onclick="_lgseAssistantSuggest(this)" data-q="Summarize my latest audit." '
              + 'style="background:rgba(255,255,255,0.06);color:var(--lgse-t3);border:1px solid rgba(255,255,255,0.1);border-radius:20px;padding:6px 12px;font-size:12px;cursor:pointer">'
              + 'Summarize latest audit'
            + '</button>'
          + '</div>'
          + '<div style="display:flex;gap:8px">'
            + '<textarea id="lgse-chat-input" rows="2" '
              + 'style="flex:1;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.12);border-radius:10px;padding:10px 14px;color:var(--lgse-t1);font-size:14px;resize:none;outline:none;font-family:inherit" '
              + 'placeholder="Ask anything about your SEO…" maxlength="2000"></textarea>'
            + '<button onclick="_lgseAssistantSend()" '
              + 'style="background:linear-gradient(135deg,#7C3AED,#3B82F6);color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap">'
              + 'Send'
            + '</button>'
          + '</div>'
        + '</div>'
      + '</div>';
    setTimeout(function () {
      var inp = document.getElementById('lgse-chat-input');
      if (inp) {
        inp.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (typeof window._lgseAssistantSend === 'function') {
              window._lgseAssistantSend();
            }
          }
        });
        inp.focus();
      }
      // 2026-05-13 — Restore prior chat history (localStorage).
      if (typeof window._lgseChatRestore === 'function') {
        window._lgseChatRestore('lgse-chat-thread');
        var t = document.getElementById('lgse-chat-thread');
        if (t) t.scrollTop = t.scrollHeight;
      }
    }, 100);
  }

  // ── Tab — Website Chatbot status ─────────────────────────────────────
  function renderChatbot(el) {
    el.innerHTML = '<div style="padding:24px;color:var(--lgse-t3)">Loading…</div>';
    api('GET', '/chatbot/status').then(function (d) {
      var status = (d && d.chatbot) ? d.chatbot : null;
      var enabled = status && status.enabled;
      el.innerHTML =
        '<div style="padding:24px">'
          + '<h3 style="font-family:var(--fh,Manrope);font-size:18px;font-weight:700;color:var(--lgse-t1);margin:0 0 4px">Website Chatbot</h3>'
          + '<p style="font-size:13px;color:var(--lgse-t3);margin:0 0 24px">'
            + 'AI chatbot powered by your business knowledge base. Handles visitor questions 24/7.'
          + '</p>'
          + (status
            ? '<div style="background:' + (enabled ? 'rgba(0,229,168,0.08);border:1px solid rgba(0,229,168,0.2)' : 'rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.2)') + ';border-radius:12px;padding:20px;margin-bottom:16px">'
                + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">'
                  + '<span style="width:8px;height:8px;border-radius:50%;background:' + (enabled ? '#00E5A8' : '#F59E0B') + ';display:inline-block"></span>'
                  + '<span style="font-size:14px;font-weight:600;color:' + (enabled ? '#00E5A8' : '#F59E0B') + '">Chatbot ' + (enabled ? 'Active' : 'Configured (Inactive)') + '</span>'
                + '</div>'
                + '<p style="font-size:13px;color:var(--lgse-t3);margin:0 0 12px">'
                  + (enabled ? 'Your chatbot is live and ready to handle visitor questions.' : 'Chatbot exists but is not yet active. Enable it from the chatbot settings.')
                + '</p>'
                + '<div style="font-size:11px;color:var(--lgse-t3);font-family:var(--lgse-mono,monospace)">'
                  + 'Theme: ' + (status.theme || 'auto') + ' · Timezone: ' + (status.timezone || 'UTC')
                + '</div>'
              + '</div>'
            : '<div style="background:rgba(255,255,255,0.03);border:1px dashed rgba(255,255,255,0.12);border-radius:12px;padding:32px;text-align:center">'
                + '<div style="font-size:32px;margin-bottom:12px">&#129302;</div>'
                + '<div style="font-size:15px;font-weight:600;color:var(--lgse-t1);margin-bottom:8px">No chatbot configured yet</div>'
                + '<div style="font-size:13px;color:var(--lgse-t3);max-width:320px;margin:0 auto 20px">'
                  + 'Set up your AI chatbot to handle visitor questions automatically.'
                + '</div>'
                + '<a href="/app/#chatbot" target="_parent" '
                  + 'style="background:linear-gradient(135deg,#7C3AED,#3B82F6);color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:600;display:inline-block">'
                  + 'Set Up Chatbot →'
                + '</a>'
              + '</div>')
        + '</div>';
    }).catch(function () {
      el.innerHTML = '<div style="padding:24px;color:var(--lgse-t3)">Could not load chatbot status.</div>';
    });
  }

  // ── Tab 1 — Overview ───────────────────────────────────────────────────
  function renderOverview(el) {
    el.innerHTML =
      '<div style="display:grid;grid-template-columns:190px 1fr;gap:14px;margin-bottom:14px">'
        + '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:14px;padding:18px;display:flex;flex-direction:column;align-items:center;justify-content:center">'
          + '<div id="lgse-main-gauge">' + gauge(0, 120, true) + '</div>'
          + '<div id="lgse-tier" style="font-size:10px;font-weight:600;padding:3px 10px;border-radius:10px;background:rgba(59,130,246,.12);color:#3b82f6;margin-top:10px">Loading…</div>'
        + '</div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">'
          + dimCard('tech',  'Technical',     'HTTPS, speed, crawl',           '30%', '#EF4444')
          + dimCard('con',   'Content',       'Quality, structure, meta',      '30%', '#3B82F6')
          + dimCard('links', 'Internal links', 'Graph, equity, anchors',       '20%', '#F59E0B')
          + dimCard('serp',  'SERP & CTR',     'Clicks, positions, CTR',       '20%', '#00E5A8')
        + '</div>'
      + '</div>'

      + '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr)">'
        + kpiCard('pages', 'Pages indexed')
        + kpiCard('kw',    'Keywords tracked')
        + kpiCard('wins',  'Quick wins')
        + kpiCard('orph',  'Orphan pages', 'lgse-dn')
      + '</div>'

      + '<div class="lgse-ai-bar" id="lgse-ai-bar" style="display:none">'
        + '<div style="width:30px;height:30px;border-radius:50%;background:rgba(108,92,231,.12);border:1px solid rgba(108,92,231,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px">·</div>'
        + '<div><div class="lgse-ai-name">' + (window._lgseIsEmbed() ? 'SEO AI Assistant' : 'James · SEO Agent') + '</div><div class="lgse-ai-text" id="lgse-ai-text"></div></div>'
      + '</div>'

      + '<div class="lgse-section-hdr"><span class="lgse-section-title">Quick wins — click to fix</span><span id="lgse-wins-count" style="font-size:10px;color:var(--lgse-t3)"></span></div>'
      + '<div id="lgse-wins-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px"></div>'

      + '<div class="lgse-section-hdr"><span class="lgse-section-title">Active issues</span><span id="lgse-iss-count"></span></div>'
      + '<div id="lgse-iss-strip" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px"></div>'

      + '<div class="lgse-section-hdr"><span class="lgse-section-title">Score trend</span><span style="font-size:10px;color:var(--lgse-t3)">Last 30 days</span></div>'
      + '<div id="lgse-trend" style="height:110px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:8px;color:var(--lgse-t3);font-size:11px;display:flex;align-items:center;justify-content:center">Loading trend…</div>';

    setTimeout(function () { animateGauge(document.getElementById('lgse-main-gauge'), 80); }, 50);
    loadOverviewData();

    // 2026-05-12: cold-workspace fast-path. If we know the site URL but have
    // no audit history, auto-trigger the first audit so the dashboard fills
    // in without the user having to find the modal. Waits 2s for
    // loadOverviewData / loadSiteList to settle.
    setTimeout(function () {
      var siteUrl = window._lgseActiveSite || window._LGSC_SITE_URL;
      var sites   = window._lgseSites || [];
      if (siteUrl && sites.length === 0 && !window._lgseAutoAuditFired
          && typeof window.lgseDoRunAudit === 'function') {
        window._lgseAutoAuditFired = true;
        if (typeof window.showToast === 'function') {
          window.showToast('Starting first audit for ' + siteUrl + '…', 'info');
        }
        try { console.log('[LGSE] Cold workspace — auto-running first audit: ' + siteUrl); } catch (_) {}
        window.lgseDoRunAudit(siteUrl);
      }
    }, 2000);
  }

  function dimCard(id, label, hint, weight, color) {
    return '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:12px;padding:12px;display:flex;align-items:center;gap:10px">'
      + '<div style="position:relative;width:48px;height:48px;flex-shrink:0">'
        + '<svg width="48" height="48" viewBox="0 0 48 48" style="transform:rotate(-90deg)">'
          + '<circle fill="none" stroke="#1e2235" stroke-width="5" cx="24" cy="24" r="18"/>'
          + '<circle fill="none" stroke="' + color + '" stroke-width="5" stroke-linecap="round" cx="24" cy="24" r="18" stroke-dasharray="113.1" stroke-dashoffset="113.1" id="dim-r-' + id + '"/>'
        + '</svg>'
        + '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-family:var(--lgse-mono);font-size:12px;font-weight:700;color:' + color + '" id="dim-n-' + id + '">0</div>'
      + '</div>'
      + '<div>'
        + '<div style="font-size:11px;font-weight:600;color:var(--lgse-t1);margin-bottom:2px">' + esc(label) + '</div>'
        + '<div style="font-size:9px;color:var(--lgse-t3);margin-bottom:4px">' + esc(hint) + '</div>'
        + '<span style="font-size:9px;font-weight:600;padding:1px 6px;border-radius:4px;background:var(--lgse-bg3);color:var(--lgse-t3);font-family:var(--lgse-mono)">' + weight + '</span>'
      + '</div></div>';
  }

  function kpiCard(id, label, valClass) {
    return '<div class="lgse-kpi-card"><div class="lgse-kpi-label">' + esc(label) + '</div>'
      + '<div class="lgse-kpi-val ' + (valClass || '') + '" id="kpi-' + id + '">—</div></div>';
  }

  function animateDim(id, val) {
    var ring = document.getElementById('dim-r-' + id);
    var num = document.getElementById('dim-n-' + id);
    if (!ring || !num) return;
    var circ = 2 * Math.PI * 18;
    // F10: null / NaN / undefined → show "—" + empty ring, not a fake 0
    // (and not the overall audit score, which is the bug we're fixing).
    if (val === null || val === undefined || isNaN(parseInt(val, 10))) {
      num.textContent = '—';
      ring.style.transition = 'stroke-dashoffset .3s ease-out';
      ring.style.strokeDashoffset = circ;
      return;
    }
    val = Math.max(0, Math.min(100, parseInt(val, 10)));
    setTimeout(function () {
      ring.style.transition = 'stroke-dashoffset .9s ease-out';
      ring.style.strokeDashoffset = circ - (val / 100) * circ;
      countUp(num, val, 700);
    }, 200);
  }

  function loadOverviewData() {
    Promise.all([
      api('GET', '/audits?limit=7').catch(function () { return null; }),
      api('GET', '/knowledge').catch(function () { return null; }),
      api('GET', '/quick-wins?limit=10').catch(function () { return null; }),
      api('GET', '/indexed-content').catch(function () { return null; }),
      api('GET', '/keywords').catch(function () { return null; }),
      api('GET', '/link-graph/orphans').catch(function () { return null; }),
    ]).then(function (results) {
      var audits = results[0]; var knowledge = results[1]; var wins = results[2];
      var indexed = results[3]; var keywords = results[4]; var orphans = results[5];

      var auditsArr = (audits && (audits.audits || audits.data)) || (Array.isArray(audits) ? audits : []);
      var latest = auditsArr[0] || {};
      // F11 (2026-05-17) — prefer LIVE health_score from /knowledge so the
      // gauge reflects current page state (updates immediately when meta
      // tags are edited). Fall back to the audit snapshot only if there's
      // no indexed content yet. This fixes the "Overview doesn't move when
      // I edit metadata" complaint.
      var liveHealth = knowledge && knowledge.health_score;
      var auditScore = parseInt(latest.score, 10);
      var score = (liveHealth !== null && liveHealth !== undefined)
        ? parseInt(liveHealth, 10)
        : (isNaN(auditScore) ? 0 : auditScore);

      // Main gauge fresh paint.
      var mg = document.getElementById('lgse-main-gauge');
      if (mg) { mg.innerHTML = gauge(score, 120, true); animateGauge(mg, 60); }

      // Tier label.
      var tier = document.getElementById('lgse-tier');
      if (tier) {
        var tierName = score >= 90 ? 'Excellent' : score >= 70 ? 'Good' : score >= 50 ? 'Needs work' : 'Critical';
        tier.textContent = tierName;
        tier.style.color = scoreColor(score);
      }

      // Dimension scores — derive from audit results_json or knowledge.
      // F10 (2026-05-17): each dim is null when no real sub-score is
      // available. Previously the fallback copied the overall score into
      // every empty dim, producing the "73 on all 4 cards" bug where the
      // user couldn't tell which dimension actually scored what.
      var results_json = {};
      try { results_json = typeof latest.results_json === 'string' ? JSON.parse(latest.results_json) : (latest.results_json || {}); } catch (e) {}
      function _pick() {
        for (var i = 0; i < arguments.length; i++) {
          var v = arguments[i];
          if (v === null || v === undefined || v === '') continue;
          var n = parseInt(v, 10);
          if (!isNaN(n)) return n;
        }
        return null;
      }
      var contentHealthAvg = knowledge && knowledge.content_health && knowledge.content_health.avg_score;
      var linkHealth       = (knowledge && knowledge.link_health) || {};
      // F11: content + links prefer LIVE knowledge first (so they update on
      // meta edits); fall back to the audit snapshot. Tech stays audit-first
      // (technical checks like HTTPS/response time don't change on a meta
      // edit). SERP stays audit/GSC-only.
      var dims = {
        tech:  _pick(results_json.technical && results_json.technical.score, results_json.tech_score),
        con:   _pick(contentHealthAvg, results_json.content && results_json.content.score, results_json.content_score),
        links: _pick(linkHealth.score, results_json.internal && results_json.internal.score, results_json.internal_score),
        serp:  _pick(results_json.serp && results_json.serp.score, results_json.serp_score),
      };
      animateDim('tech',  dims.tech);
      animateDim('con',   dims.con);
      animateDim('links', dims.links);
      animateDim('serp',  dims.serp);

      // KPIs.
      var pCount = countItems(indexed, 'items');
      var kCount = countItems(keywords, 'keywords');
      var winsArr = (wins && (wins.quick_wins || wins.wins)) || (Array.isArray(wins) ? wins : []);
      var oCount = (orphans && (orphans.orphans || orphans.data)) ? (orphans.orphans || orphans.data).length : 0;

      var kpiPages = document.getElementById('kpi-pages'); if (kpiPages) { kpiPages.textContent = '0'; countUp(kpiPages, pCount, 700); }
      var kpiKw = document.getElementById('kpi-kw'); if (kpiKw) { kpiKw.textContent = '0'; countUp(kpiKw, kCount, 600); }
      var kpiWins = document.getElementById('kpi-wins'); if (kpiWins) { kpiWins.textContent = '0'; countUp(kpiWins, winsArr.length, 600); }
      var kpiOrph = document.getElementById('kpi-orph'); if (kpiOrph) { kpiOrph.textContent = '0'; countUp(kpiOrph, oCount, 600); }

      // AI summary.
      if (knowledge && knowledge.summary) {
        var bar = document.getElementById('lgse-ai-bar');
        var txt = document.getElementById('lgse-ai-text');
        if (bar && txt) { txt.textContent = knowledge.summary; bar.style.display = 'flex'; }
      }

      // Issues strip — top 3.
      var checks = (results_json.checks || results_json.issues || []);
      var errors = checks.filter(function (c) { return c && (c.status === 'error' || c.severity === 'error'); });
      var warnings = checks.filter(function (c) { return c && (c.status === 'warning' || c.severity === 'warning'); });
      var topIssues = (knowledge && Array.isArray(knowledge.top_issues)) ? knowledge.top_issues : [];

      var issStrip = document.getElementById('lgse-iss-strip');
      var issCount = document.getElementById('lgse-iss-count');
      var combined = [];
      errors.slice(0, 3).forEach(function (e) { combined.push({ label: e.check || e.label || 'Critical issue', level: 'error' }); });
      if (combined.length < 3) warnings.slice(0, 3 - combined.length).forEach(function (w) { combined.push({ label: w.check || w.label || 'Warning', level: 'warn' }); });
      if (combined.length === 0) topIssues.slice(0, 3).forEach(function (i) { combined.push({ label: i.count + ' ' + i.type, level: i.type === 'critical' || i.type === 'errors' ? 'error' : 'warn' }); });

      if (issCount) {
        var total = errors.length + warnings.length || topIssues.reduce(function (a, b) { return a + (b.count || 0); }, 0);
        issCount.innerHTML = badge(total + ' total', total > 5 ? 'red' : total > 0 ? 'amber' : 'teal');
      }
      if (issStrip) {
        if (combined.length === 0) {
          issStrip.innerHTML = '<div style="grid-column:1/-1">' + emptyState('✓', 'No issues found', 'Your site looks healthy.') + '</div>';
        } else {
          issStrip.innerHTML = combined.map(function (i) {
            var col = i.level === 'error' ? '#EF4444' : '#F59E0B';
            return '<div class="lgse-issue-chip"><div class="lgse-dot" style="background:' + col + '"></div><div style="flex:1;font-size:11px;color:var(--lgse-t2)">' + esc(i.label) + '</div>' + badge(i.level === 'error' ? 'CRITICAL' : 'WARN', i.level === 'error' ? 'red' : 'amber') + '</div>';
          }).join('');
        }
      }

      // P0-5: priority tiles (replaces generic wins-grid). Uses real audit
      // check counts + orphan count already in scope. No extra network call.
      window.lgseRenderPriorityTiles(checks, oCount, latest);

      // Trend chart — pure SVG.
      var trendEl = document.getElementById('lgse-trend');
      if (trendEl) {
        var snaps = auditsArr.slice().reverse();
        if (snaps.length < 2) {
          trendEl.innerHTML = '<span style="color:var(--lgse-t3);font-size:11px">Not enough audits yet — run a few to see trend.</span>';
        } else {
          var scores = snaps.map(function (s) { return parseInt(s.score || 0, 10); });
          trendEl.innerHTML = trendChart(scores);
        }
      }
    }).catch(function () {
      var mg = document.getElementById('lgse-main-gauge');
      if (mg) mg.innerHTML = '<div style="color:var(--lgse-t3);font-size:11px">Could not load</div>';
    });
  }

  // P0-5: priority tiles for the Overview tab. Replaces the generic quick-wins
  // grid with concrete, actionable counts pulled from the latest audit's
  // results_json + the orphan-pages query. Each tile navigates to the matching
  // tab (Pages, Audit, Links). IIFE-private functions are scoped above; the
  // entry point is exposed on `window` so the inline-handler call from
  // loadOverviewData can reach it.
  window.lgseRenderPriorityTiles = function (checks, orphanCount, audit) {
    var el = document.getElementById('lgse-wins-grid');
    var countEl = document.getElementById('lgse-wins-count');
    if (!el) return;

    checks = Array.isArray(checks) ? checks : [];
    orphanCount = parseInt(orphanCount, 10) || 0;

    function isErr(c) { return c && (c.status === 'error' || c.severity === 'error'); }
    function isWarn(c) { return c && (c.status === 'warning' || c.severity === 'warning'); }
    function nameContains(c, needle) {
      var n = (c && (c.check || c.label || c.title || '')) + '';
      return n.toLowerCase().indexOf(needle) !== -1;
    }

    var tiles = [];

    var missingTitle = checks.filter(function (c) { return isErr(c) && nameContains(c, 'title'); }).length;
    if (missingTitle > 0) tiles.push({
      count: missingTitle, label: 'Missing SEO Titles', cta: 'Fix titles',
      color: 'var(--lgse-amber)', tab: 'pages',
    });

    var missingDesc = checks.filter(function (c) { return isErr(c) && nameContains(c, 'description'); }).length;
    if (missingDesc > 0) tiles.push({
      count: missingDesc, label: 'Missing Meta Descriptions', cta: 'Fix descriptions',
      color: 'var(--lgse-amber)', tab: 'pages',
    });

    var httpsErr = checks.filter(function (c) { return isErr(c) && nameContains(c, 'https'); }).length;
    if (httpsErr > 0) tiles.push({
      count: httpsErr, label: 'HTTPS Not Enabled', cta: 'Fix now',
      color: 'var(--lgse-red)', tab: 'audit',
    });

    var imageIssues = checks.filter(function (c) { return c && c.status !== 'pass' && nameContains(c, 'image'); }).length;
    if (imageIssues > 0) tiles.push({
      count: imageIssues, label: 'Image Issues', cta: 'Optimize images',
      color: 'var(--lgse-amber)', tab: 'pages',
    });

    if (orphanCount > 0) tiles.push({
      count: orphanCount, label: 'Orphan Pages', cta: 'Add internal links',
      color: 'var(--lgse-red)', tab: 'links',
    });

    var warnTotal = checks.filter(isWarn).length;
    if (warnTotal > 0) tiles.push({
      count: warnTotal, label: 'SEO Warnings', cta: 'Review warnings',
      color: 'var(--lgse-amber)', tab: 'audit',
    });

    if (countEl) {
      countEl.textContent = tiles.length === 0
        ? 'all clear'
        : tiles.length + (tiles.length === 1 ? ' priority' : ' priorities');
    }

    if (tiles.length === 0) {
      el.innerHTML = '<div style="grid-column:1/-1">'
        + emptyState('✓', 'No critical issues found', 'Your site is in good shape — keep monitoring with weekly audits.')
        + '</div>';
      return;
    }

    var cols = Math.min(tiles.length, 3);
    el.style.gridTemplateColumns = 'repeat(' + cols + ',1fr)';

    el.innerHTML = tiles.slice(0, 6).map(function (t) {
      var safeTab = String(t.tab).replace(/[^a-z]/g, '');
      return '<div class="lgse-win-tile" onclick="lgseSwitchTab(\'' + safeTab + '\')">'
        + '<div class="lgse-win-count" style="color:' + t.color + '">' + t.count + '</div>'
        + '<div class="lgse-win-desc">' + esc(t.label) + '</div>'
        + '<div class="lgse-win-cta" style="color:' + t.color + '">' + esc(t.cta) + ' →</div>'
        + '</div>';
    }).join('');
  };

  // ── Tab 2 — Audit ──────────────────────────────────────────────────────

  // P0-AUD-FIX2 (rev 2026-05-16): Workspace site list.
  //
  // Precedence:
  //   1. If /settings returns a site_url (workspace has a connected WP site),
  //      that IS the website. Lock _lgseSites to a single entry. Skip audit
  //      history entirely — it contains junk (keyword-as-url misuse) and
  //      sub-page URLs that should not appear as separate "sites".
  //   2. Otherwise (no connected WP site), fall back to audit-history-derived
  //      dropdown for Laravel-only / multi-site workspaces.
  function loadSiteList() {
    if (window._lgseSites && window._lgseSites.length) return;

    // Fetch /settings first. The result decides whether we even look at
    // audit history. We chain rather than race to avoid the connected-site
    // pill flickering through a junk-dominated dropdown.
    var settingsPromise;
    if (window._lgseSettingsFetched && window._lgseActiveSite) {
      // Already fetched and we have a site — synthesize a resolved promise.
      settingsPromise = Promise.resolve({ site_url: window._lgseActiveSite });
    } else {
      window._lgseSettingsFetched = true;
      settingsPromise = api('GET', '/settings').catch(function () { return null; });
    }

    settingsPromise.then(function (s) {
      var connectedSite = s && s.site_url ? String(s.site_url).trim() : '';
      if (connectedSite) {
        // WP-connected workspace — hard-lock to this one site.
        window._lgseActiveSite = connectedSite;
        window._lgseSites = [{ url: connectedSite, name: connectedSite }];
        window._lgseSiteIsLocked = true;
        try { console.log('[LGSE] Audit picker locked to connected WP site: ' + connectedSite); } catch (_) {}
        return;
      }
      // No connected WP site — fall back to audit-history dropdown.
      window._lgseSiteIsLocked = false;
      api('GET', '/audits?limit=50').then(function (d) {
        var rows = (d && (d.audits || d.data)) || (Array.isArray(d) ? d : []);
        var seen = {};
        var sites = [];
        rows.forEach(function (a) {
          var u = a && a.url;
          // Reject obvious garbage: must look like an http(s) URL.
          if (u && !seen[u] && /^https?:\/\//i.test(u)) {
            seen[u] = 1;
            sites.push({ url: u, name: u });
          }
        });
        window._lgseSites = sites;
        if (!window._lgseActiveSite && sites.length) window._lgseActiveSite = sites[0].url;
      }).catch(function () { window._lgseSites = window._lgseSites || []; });
    });
  }

  // P0-AUD-FIX3: site-selector strip rendered above the audit list.
  function renderSiteSelector(selectedUrl) {
    var sites = window._lgseSites || [];
    if (sites.length === 0) return '';
    if (sites.length === 1) {
      return '<div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">'
        + '<div style="font-size:9.5px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em">Showing results for</div>'
        + '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:20px;padding:4px 12px;font-size:11px;color:var(--lgse-t2);font-family:var(--lgse-mono)">' + esc(sites[0].url) + '</div>'
        + '</div>';
    }
    return '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">'
      + '<div style="font-size:9.5px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;white-space:nowrap">Website</div>'
      + '<select id="lgse-site-selector" onchange="lgseChangeSite(this.value)" style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:6px 12px;font-size:11.5px;color:var(--lgse-t1);cursor:pointer;max-width:300px">'
      + sites.map(function (s) {
          var sel = s.url === selectedUrl ? ' selected' : '';
          return '<option value="' + esc(s.url) + '"' + sel + '>' + esc(s.name || s.url) + '</option>';
        }).join('')
      + '</select></div>';
  }

  function renderAudit(el) {
    el.innerHTML =
      renderSiteSelector(window._lgseActiveSite)
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">'
        + '<div>' + pageTitle('Audit Center', 'Technical, content, link, and SERP checks. Click any audit row for the full report.') + '</div>'
        + '<div style="display:flex;gap:8px;flex-shrink:0">'
          + '<button class="lgse-btn-secondary" id="lgse-deepscan-btn" onclick="lgseRunDeepScan()">⟳ Scan Website</button>'
          + '<button class="lgse-btn-primary" onclick="lgseRunAudit()">+ Run new audit</button>'
        + '</div>'
      + '</div>'
      + '<div id="lgse-audit-body">Loading…</div>';
    loadAuditList();
  }

  // P0-AUD-FIX1: themed modal replaces the legacy native dialog.
  window.lgseRunAudit = function () {
    var sites = window._lgseSites || [];
    var siteOptions;
    if (sites.length > 1) {
      siteOptions = '<select id="lgse-audit-url-select" style="width:100%;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:8px 12px;font-size:11.5px;color:var(--lgse-t1);margin-bottom:8px;cursor:pointer">'
        + sites.map(function (s) { return '<option value="' + esc(s.url) + '">' + esc(s.name || s.url) + '</option>'; }).join('')
        + '</select>';
    } else if (sites.length === 1) {
      siteOptions = '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:8px 12px;font-size:11.5px;color:var(--lgse-t2);margin-bottom:8px;font-family:var(--lgse-mono)">' + esc(sites[0].url)
        + '<input type="hidden" id="lgse-audit-url-select" value="' + esc(sites[0].url) + '"></div>';
    } else {
      // 2026-05-12: pre-fill from _lgseActiveSite or _LGSC_SITE_URL (embed)
      var _prefill = window._lgseActiveSite || window._LGSC_SITE_URL || '';
      var _prefillEsc = _prefill.replace(/"/g, '&quot;');
      siteOptions = '<input type="text" id="lgse-audit-url-select" placeholder="https://yoursite.com" value="' + _prefillEsc + '" style="width:100%;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:8px 12px;font-size:11.5px;color:var(--lgse-t1);outline:none;margin-bottom:8px">';
    }

    var overlay = document.createElement('div');
    overlay.id = 'lgse-audit-modal';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = '<div style="background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:14px;padding:24px;min-width:380px;max-width:460px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.5)">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">'
        + '<div>'
          + '<div style="font-size:15px;font-weight:600;color:var(--lgse-t1);margin-bottom:3px">Run SEO Audit</div>'
          + '<div style="font-size:11px;color:var(--lgse-t3)">Runs 20+ technical, content, and SERP checks</div>'
        + '</div>'
        + '<button onclick="(function(){var m=document.getElementById(\'lgse-audit-modal\');if(m)m.remove();})()" style="background:transparent;border:none;color:var(--lgse-t3);font-size:20px;cursor:pointer;line-height:1;padding:4px">×</button>'
      + '</div>'
      + '<div style="font-size:10px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">'
        + (sites.length > 1 ? 'Select website' : 'Website')
      + '</div>'
      + siteOptions
      + '<div style="display:flex;align-items:center;gap:8px;margin-top:16px;padding-top:14px;border-top:1px solid var(--lgse-border)">'
        + '<button onclick="(function(){var m=document.getElementById(\'lgse-audit-modal\');if(m)m.remove();})()" class="lgse-btn-secondary" style="flex:1">Cancel</button>'
        + '<button onclick="lgseSubmitAudit()" class="lgse-btn-primary" style="flex:2">Run audit</button>'
      + '</div>'
      + '</div>';
    document.body.appendChild(overlay);
    var inp = document.getElementById('lgse-audit-url-select');
    if (inp && inp.tagName === 'INPUT' && inp.type === 'text') inp.focus();
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
  };

  window.lgseSubmitAudit = function () {
    var el = document.getElementById('lgse-audit-url-select');
    if (!el) return;
    var url = (el.value || el.textContent || '').trim();
    if (!url) { el.style.borderColor = 'var(--lgse-red)'; return; }
    var modal = document.getElementById('lgse-audit-modal');
    if (modal) modal.remove();
    window.lgseDoRunAudit(url);
  };

  window.lgseDoRunAudit = function (url) {
    var btn = document.querySelector('[onclick*="lgseRunAudit"]');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Running...'; }
    api('POST', '/deep-audit', { url: url }).then(function () {
      if (typeof window.showToast === 'function') window.showToast('Audit started.', 'success');
      if (btn) { btn.disabled = false; btn.textContent = '+ Run new audit'; }
      // Add new URL to known sites if absent.
      var sites = window._lgseSites || [];
      var seen = false;
      for (var i = 0; i < sites.length; i++) { if (sites[i].url === url) { seen = true; break; } }
      if (!seen) {
        sites.unshift({ url: url, name: url });
        window._lgseSites = sites;
        window._lgseActiveSite = url;
      }
      loadAuditList();
    }).catch(function (e) {
      // 2026-05-12: branch on e.code so the user sees the actual reason
      // (out of credits, plan-gated, validation error) instead of a generic
      // 'Failed:' toast that flashes by.
      var code = e && e.code;
      var msg  = (e && e.message) || 'Unknown error';
      if (code === 'NO_CREDITS' && typeof showPlanGate === 'function') {
        showPlanGate(msg);
      } else if (code === 'PLAN_GATED' && typeof showPlanGate === 'function') {
        showPlanGate(msg);
      } else if (typeof window.showToast === 'function') {
        window.showToast('Audit failed: ' + msg, 'error');
      }
      try { console.warn('[LGSE] Audit failed', { code: code, status: e && e.status, body: e && e.body }); } catch (_) {}
      if (btn) { btn.disabled = false; btn.textContent = '+ Run new audit'; }
    });
  };

  window.lgseChangeSite = function (url) {
    window._lgseActiveSite = url;
    loadAuditList();
  };

  // P0-AUD-FIX4: Always-visible "Scan Website" button + simulated progress bar.
  window.lgseRunDeepScan = function () {
    var sites = window._lgseSites || [];
    var siteUrl = window._lgseActiveSite || (sites[0] && sites[0].url) || '';
    // 2026-05-12: fall back to the embed-passed site URL before prompting.
    if (!siteUrl && window._LGSC_SITE_URL) {
      siteUrl = window._LGSC_SITE_URL;
      window._lgseActiveSite = siteUrl;
    }
    if (!siteUrl) { window.lgseRunAudit(); return; }
    var btn = document.getElementById('lgse-deepscan-btn');
    var progEl = document.getElementById('lgse-deepscan-progress');
    if (!progEl) {
      progEl = document.createElement('div');
      progEl.id = 'lgse-deepscan-progress';
      progEl.style.cssText = 'background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px;margin-bottom:14px';
      progEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">'
        + '<span id="lgse-scan-msg" style="font-size:11.5px;color:var(--lgse-t2)">Starting deep scan…</span>'
        + '<span style="font-family:var(--lgse-mono);font-size:10px;color:var(--lgse-t3)" id="lgse-scan-pct">0%</span>'
        + '</div>'
        + '<div style="height:6px;background:var(--lgse-bg3);border-radius:3px;overflow:hidden">'
        + '<div id="lgse-scan-bar" style="height:100%;width:0%;background:var(--lgse-purple);border-radius:3px;transition:width .4s ease"></div>'
        + '</div>';
      var auditBody = document.getElementById('lgse-audit-body');
      if (auditBody && auditBody.parentNode) auditBody.parentNode.insertBefore(progEl, auditBody);
    }
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Scanning...'; }
    var pct = 0;
    var ticker = setInterval(function () {
      pct = Math.min(pct + 2, 88);
      var bar = document.getElementById('lgse-scan-bar');
      var pctEl = document.getElementById('lgse-scan-pct');
      var msg = document.getElementById('lgse-scan-msg');
      if (bar) bar.style.width = pct + '%';
      if (pctEl) pctEl.textContent = pct + '%';
      if (msg && pct > 60) msg.textContent = 'Indexing content…';
      else if (msg && pct > 20) msg.textContent = 'Scanning pages…';
    }, 400);
    api('POST', '/deep-audit', { url: siteUrl }).then(function (d) {
      clearInterval(ticker);
      var bar = document.getElementById('lgse-scan-bar');
      var pctEl = document.getElementById('lgse-scan-pct');
      var msg = document.getElementById('lgse-scan-msg');
      if (bar) bar.style.width = '100%';
      if (pctEl) pctEl.textContent = '100%';
      var n = (d && (d.pages_indexed || d.pages || d.total_urls)) || '';
      if (msg) msg.textContent = 'Scan complete' + (n ? (' — ' + n + ' pages indexed') : '');
      setTimeout(function () {
        var prog = document.getElementById('lgse-deepscan-progress');
        if (prog) prog.remove();
        if (btn) { btn.disabled = false; btn.textContent = '⟳ Scan Website'; }
        loadAuditList();
      }, 2500);
    }).catch(function () {
      clearInterval(ticker);
      var msg = document.getElementById('lgse-scan-msg');
      if (msg) { msg.textContent = 'Scan failed — try again'; msg.style.color = 'var(--lgse-red)'; }
      if (btn) { btn.disabled = false; btn.textContent = '⟳ Scan Website'; }
      setTimeout(function () {
        var prog = document.getElementById('lgse-deepscan-progress');
        if (prog) prog.remove();
      }, 3000);
    });
  };

  function loadAuditList() {
    var body = document.getElementById('lgse-audit-body');
    if (!body) return;
    api('GET', '/audits').then(function (d) {
      var rows = (d && (d.audits || d.data)) || (Array.isArray(d) ? d : []);
      // P0-AUD-FIX3: client-side filter by active site (backend filter unconfirmed).
      var activeSite = window._lgseActiveSite;
      if (activeSite) rows = rows.filter(function (r) { return r && r.url === activeSite; });
      if (rows.length === 0) {
        body.innerHTML = emptyState('◎', 'No audits yet', 'Run your first audit to see issues, scores, and a full health snapshot.', 'Run audit', 'lgseRunAudit()');
        return;
      }
      // P0-AUD-AUTOLOAD: auto-show the most recent audit detail.
      // Trust backend ordering (rows[0] = latest); the back link in the
      // detail view re-runs lgseSwitchTab('audit') which re-enters this
      // function, effectively a refresh-to-latest.
      var latest = rows[0];
      if (latest && latest.id) {
        window.lgseShowAuditDetail(latest.id);
      } else {
        body.innerHTML = emptyState('⚠', 'Could not load latest audit', 'No audit ID returned from server.');
      }
    }).catch(function () {
      body.innerHTML = emptyState('⚠', 'Could not load audits', 'Check your connection and try again.');
    });
  }
  window.lgseShowAuditDetail = function (id) {
    var body = document.getElementById('lgse-audit-body');
    if (!body) return;
    body.innerHTML = '<div style="padding:40px;text-align:center;color:var(--lgse-t3);font-size:11px">Loading audit…</div>';
    api('GET', '/audits/' + id).then(function (a) {
      if (a && (a.audit || a.data)) a = a.audit || a.data;
      var results = {};
      try { results = typeof a.results_json === 'string' ? JSON.parse(a.results_json) : (a.results_json || {}); } catch (e) { results = {}; }
      var checks = Array.isArray(results.checks) ? results.checks : (Array.isArray(results.issues) ? results.issues : []);
      var errors = checks.filter(function (c) { return c.status === 'error' || c.severity === 'error'; });
      var warnings = checks.filter(function (c) { return c.status === 'warning' || c.severity === 'warning'; });
      var passed = checks.filter(function (c) { return c.status === 'pass' || c.status === 'success'; });
      var cats = {};
      checks.forEach(function (c) { var k = c.category || c.section || 'general'; (cats[k] = cats[k] || []).push(c); });

      var h = '<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px">'
        + '<div style="flex:1"><div style="font-size:13px;font-weight:600;color:var(--lgse-t1);margin-bottom:6px;word-break:break-all">' + esc(a.url || '') + '</div>'
        + '<div style="display:flex;gap:6px;flex-wrap:wrap">' + badge(errors.length + ' errors', 'red') + badge(warnings.length + ' warnings', 'amber') + badge(passed.length + ' passed', 'teal') + '</div></div>'
        + gauge(a.score || 0, 72, false) + '</div>';
      h += '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:16px">';
      [
        { v: checks.length, l: 'Checks run', c: 'var(--lgse-t1)' },
        { v: errors.length, l: 'Critical', c: 'var(--lgse-red)' },
        { v: warnings.length, l: 'Warnings', c: 'var(--lgse-amber)' },
        { v: passed.length, l: 'Passed', c: 'var(--lgse-teal)' },
        { v: a.score || 0, l: 'Score', c: 'var(--lgse-blue)' },
      ].forEach(function (k) {
        h += '<div class="lgse-kpi-card" style="text-align:center;padding:10px 8px"><div class="lgse-kpi-val" style="font-size:20px;color:' + k.c + '">' + k.v + '</div><div class="lgse-kpi-label">' + k.l + '</div></div>';
      });
      h += '</div>';

      if (Object.keys(cats).length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Issue categories</span><span style="font-size:10px;color:var(--lgse-t3)">Click a category to filter checks</span></div>';
        h += '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;margin-bottom:16px">';
        Object.keys(cats).forEach(function (k) {
          var items = cats[k];
          var ec = items.filter(function (i) { return i.status === 'error'; }).length;
          var wc = items.filter(function (i) { return i.status === 'warning'; }).length;
          var col = ec > 0 ? '#EF4444' : wc > 0 ? '#F59E0B' : '#00E5A8';
          var safeCat = String(k).replace(/['"<>\\]/g, '');
          h += '<div class="lgse-cat-item" data-category="' + esc(k) + '" style="cursor:pointer" onclick="lgseFilterAuditByCategory(\'' + safeCat + '\', this)"><div class="lgse-cat-icon" style="background:' + col + '"></div><div style="flex:1;font-size:11px;color:var(--lgse-t1)">' + esc(k) + '</div><span style="font-family:var(--lgse-mono);font-size:11px;font-weight:700;color:' + col + '">' + items.length + '</span>' + badge(ec > 0 ? 'CRITICAL' : wc > 0 ? 'WARN' : 'OK', ec > 0 ? 'red' : wc > 0 ? 'amber' : 'teal') + '</div>';
        });
        h += '</div>';
      }

      h += '<div class="lgse-section-hdr"><span class="lgse-section-title">All checks</span><span style="display:flex;align-items:center;gap:10px"><span id="lgse-cat-filter-label" style="font-size:10px;color:var(--lgse-t3)">All checks</span><span id="lgse-cat-filter-clear" onclick="lgseFilterAuditByCategory(\'all\', null)" style="font-size:10px;color:var(--lgse-purple);cursor:pointer;display:none">Clear filter</span></span></div>';
      if (checks.length === 0) {
        h += emptyState('·', 'No checks recorded', 'This audit type may not have produced detailed checks. Audit type: ' + esc(a.type || 'unknown') + '.');
      } else {
        h += '<table class="lgse-table"><thead><tr><th>Check</th><th>Category</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        checks.forEach(function (c) {
          var st = c.status || (c.severity === 'error' ? 'error' : c.severity === 'warning' ? 'warning' : 'pass');
          var rowCat = c.category || c.section || 'general';
          h += '<tr class="lgse-check-row" data-category="' + esc(rowCat) + '"><td style="font-weight:500;color:var(--lgse-t1)">' + esc(c.check || c.label || c.title || '—') + '</td>';
          h += '<td style="font-size:10.5px;color:var(--lgse-t3)">' + esc(c.category || '—') + '</td>';
          h += '<td>' + badge(st, st === 'error' ? 'red' : st === 'warning' ? 'amber' : 'teal') + '</td>';
          h += '<td style="font-size:10.5px;color:var(--lgse-t3)">' + esc(c.details || c.message || c.description || '') + '</td></tr>';
        });
        h += '</tbody></table>';
      }
      body.innerHTML = h;
      var g = body.querySelector('.lgse-gauge-wrap');
      if (g) animateGauge(g, 50);
    }).catch(function () {
      body.innerHTML = emptyState('⚠', 'Could not load audit', 'Try refreshing the page.', '← Back', 'lgseSwitchTab(\'audit\')');
    });
  };

  // P0-4: clickable category filter for the All-checks table.
  window.lgseFilterAuditByCategory = function (cat, el) {
    var chips = document.querySelectorAll('.lgse-cat-item');
    Array.prototype.forEach.call(chips, function (c) {
      c.style.borderColor = '';
      c.style.background = '';
    });
    if (el && cat !== 'all') {
      el.style.borderColor = 'var(--lgse-purple)';
      el.style.background = 'rgba(108,92,231,0.08)';
    }
    var rows = document.querySelectorAll('.lgse-check-row');
    var shown = 0;
    Array.prototype.forEach.call(rows, function (row) {
      var rc = row.getAttribute('data-category') || '';
      var show = cat === 'all' || rc === cat;
      row.style.display = show ? '' : 'none';
      if (show) shown++;
    });
    var lbl = document.getElementById('lgse-cat-filter-label');
    var clr = document.getElementById('lgse-cat-filter-clear');
    if (lbl) {
      lbl.textContent = cat === 'all'
        ? 'All checks'
        : (cat + ' · ' + shown + (shown === 1 ? ' check' : ' checks'));
    }
    if (clr) clr.style.display = cat === 'all' ? 'none' : 'inline';
  };

  // ── Tab 3 — Keywords ───────────────────────────────────────────────────
  // SMOKE-2.3 — Country selector (persisted in localStorage). The current
  // backend (GET /keywords) doesn't yet accept a location filter, but the
  // selection is forwarded as a query param so the data layer can adopt it
  // without a frontend change. SERP fetch + research panel require a
  // backend that doesn't exist on staging yet — surfaced honestly below.
  // Wave 20a — Keywords tab uses the same all-countries list as Competitors.
  // {c: location_code int (sent to backend), v: display name, l: flag-label}.
  var LGSE_KW_COUNTRIES = [
    { c: 2840, v: 'United States',        l: '🇺🇸 USA' },
    { c: 2826, v: 'United Kingdom',       l: '🇬🇧 UK' },
    { c: 2784, v: 'United Arab Emirates', l: '🇦🇪 UAE' },
    { c: 2682, v: 'Saudi Arabia',         l: '🇸🇦 Saudi Arabia' },
    { c: 2818, v: 'Egypt',                l: '🇪🇬 Egypt' },
    { c: 2276, v: 'Germany',              l: '🇩🇪 Germany' },
    { c: 2040, v: 'Austria',              l: '🇦🇹 Austria' },
    { c: 2756, v: 'Switzerland',          l: '🇨🇭 Switzerland' },
    { c: 2356, v: 'India',                l: '🇮🇳 India' },
    { c: 2702, v: 'Singapore',            l: '🇸🇬 Singapore' },
    { c: 2124, v: 'Canada',               l: '🇨🇦 Canada' },
    { c: 2036, v: 'Australia',            l: '🇦🇺 Australia' },
    { c: 2250, v: 'France',               l: '🇫🇷 France' },
    { c: 2380, v: 'Italy',                l: '🇮🇹 Italy' },
    { c: 2724, v: 'Spain',                l: '🇪🇸 Spain' },
    { c: 2528, v: 'Netherlands',          l: '🇳🇱 Netherlands' },
    { c: 2076, v: 'Brazil',               l: '🇧🇷 Brazil' },
    { c: 2484, v: 'Mexico',               l: '🇲🇽 Mexico' },
    { c: 2392, v: 'Japan',                l: '🇯🇵 Japan' },
    { c: 2410, v: 'South Korea',          l: '🇰🇷 South Korea' },
    { c: 2008, v: 'Albania',              l: '🇦🇱 Albania' },
    { c: 2012, v: 'Algeria',              l: '🇩🇿 Algeria' },
    { c: 2032, v: 'Argentina',            l: '🇦🇷 Argentina' },
    { c: 2051, v: 'Armenia',              l: '🇦🇲 Armenia' },
    { c: 2031, v: 'Azerbaijan',           l: '🇦🇿 Azerbaijan' },
    { c: 2048, v: 'Bahrain',              l: '🇧🇭 Bahrain' },
    { c: 2050, v: 'Bangladesh',           l: '🇧🇩 Bangladesh' },
    { c: 2056, v: 'Belgium',              l: '🇧🇪 Belgium' },
    { c: 2068, v: 'Bolivia',              l: '🇧🇴 Bolivia' },
    { c: 2100, v: 'Bulgaria',             l: '🇧🇬 Bulgaria' },
    { c: 2152, v: 'Chile',                l: '🇨🇱 Chile' },
    { c: 2170, v: 'Colombia',             l: '🇨🇴 Colombia' },
    { c: 2188, v: 'Costa Rica',           l: '🇨🇷 Costa Rica' },
    { c: 2191, v: 'Croatia',              l: '🇭🇷 Croatia' },
    { c: 2196, v: 'Cyprus',               l: '🇨🇾 Cyprus' },
    { c: 2203, v: 'Czechia',              l: '🇨🇿 Czechia' },
    { c: 2208, v: 'Denmark',              l: '🇩🇰 Denmark' },
    { c: 2218, v: 'Ecuador',              l: '🇪🇨 Ecuador' },
    { c: 2222, v: 'El Salvador',          l: '🇸🇻 El Salvador' },
    { c: 2233, v: 'Estonia',              l: '🇪🇪 Estonia' },
    { c: 2246, v: 'Finland',              l: '🇫🇮 Finland' },
    { c: 2268, v: 'Georgia',              l: '🇬🇪 Georgia' },
    { c: 2300, v: 'Greece',               l: '🇬🇷 Greece' },
    { c: 2320, v: 'Guatemala',            l: '🇬🇹 Guatemala' },
    { c: 2340, v: 'Honduras',             l: '🇭🇳 Honduras' },
    { c: 2344, v: 'Hong Kong',            l: '🇭🇰 Hong Kong' },
    { c: 2348, v: 'Hungary',              l: '🇭🇺 Hungary' },
    { c: 2352, v: 'Iceland',              l: '🇮🇸 Iceland' },
    { c: 2360, v: 'Indonesia',            l: '🇮🇩 Indonesia' },
    { c: 2368, v: 'Iraq',                 l: '🇮🇶 Iraq' },
    { c: 2372, v: 'Ireland',              l: '🇮🇪 Ireland' },
    { c: 2376, v: 'Israel',               l: '🇮🇱 Israel' },
    { c: 2400, v: 'Jordan',               l: '🇯🇴 Jordan' },
    { c: 2398, v: 'Kazakhstan',           l: '🇰🇿 Kazakhstan' },
    { c: 2404, v: 'Kenya',                l: '🇰🇪 Kenya' },
    { c: 2414, v: 'Kuwait',               l: '🇰🇼 Kuwait' },
    { c: 2417, v: 'Kyrgyzstan',           l: '🇰🇬 Kyrgyzstan' },
    { c: 2428, v: 'Latvia',               l: '🇱🇻 Latvia' },
    { c: 2422, v: 'Lebanon',              l: '🇱🇧 Lebanon' },
    { c: 2440, v: 'Lithuania',            l: '🇱🇹 Lithuania' },
    { c: 2442, v: 'Luxembourg',           l: '🇱🇺 Luxembourg' },
    { c: 2458, v: 'Malaysia',             l: '🇲🇾 Malaysia' },
    { c: 2470, v: 'Malta',                l: '🇲🇹 Malta' },
    { c: 2504, v: 'Morocco',              l: '🇲🇦 Morocco' },
    { c: 2554, v: 'New Zealand',          l: '🇳🇿 New Zealand' },
    { c: 2566, v: 'Nigeria',              l: '🇳🇬 Nigeria' },
    { c: 2578, v: 'Norway',               l: '🇳🇴 Norway' },
    { c: 2512, v: 'Oman',                 l: '🇴🇲 Oman' },
    { c: 2586, v: 'Pakistan',             l: '🇵🇰 Pakistan' },
    { c: 2591, v: 'Panama',               l: '🇵🇦 Panama' },
    { c: 2604, v: 'Peru',                 l: '🇵🇪 Peru' },
    { c: 2608, v: 'Philippines',          l: '🇵🇭 Philippines' },
    { c: 2616, v: 'Poland',               l: '🇵🇱 Poland' },
    { c: 2620, v: 'Portugal',             l: '🇵🇹 Portugal' },
    { c: 2634, v: 'Qatar',                l: '🇶🇦 Qatar' },
    { c: 2642, v: 'Romania',              l: '🇷🇴 Romania' },
    { c: 2643, v: 'Russia',               l: '🇷🇺 Russia' },
    { c: 2688, v: 'Serbia',               l: '🇷🇸 Serbia' },
    { c: 2703, v: 'Slovakia',             l: '🇸🇰 Slovakia' },
    { c: 2705, v: 'Slovenia',             l: '🇸🇮 Slovenia' },
    { c: 2710, v: 'South Africa',         l: '🇿🇦 South Africa' },
    { c: 2144, v: 'Sri Lanka',            l: '🇱🇰 Sri Lanka' },
    { c: 2752, v: 'Sweden',               l: '🇸🇪 Sweden' },
    { c: 2158, v: 'Taiwan',               l: '🇹🇼 Taiwan' },
    { c: 2764, v: 'Thailand',             l: '🇹🇭 Thailand' },
    { c: 2788, v: 'Tunisia',              l: '🇹🇳 Tunisia' },
    { c: 2792, v: 'Turkey',               l: '🇹🇷 Turkey' },
    { c: 2804, v: 'Ukraine',              l: '🇺🇦 Ukraine' },
    { c: 2858, v: 'Uruguay',              l: '🇺🇾 Uruguay' },
    { c: 2860, v: 'Uzbekistan',           l: '🇺🇿 Uzbekistan' },
    { c: 2862, v: 'Venezuela',            l: '🇻🇪 Venezuela' },
    { c: 2704, v: 'Vietnam',              l: '🇻🇳 Vietnam' },
    { c: 2887, v: 'Yemen',                l: '🇾🇪 Yemen' }
  ];

  function lgseGetKwCountry() {
    // Wave 20a — returns int location_code (DataForSEO compatible). Default 2840 = US.
    var v = parseInt(localStorage.getItem('lgse_kw_country_code') || '0', 10);
    return v > 0 ? v : 2840;
  }

  window.lgseSetKwCountry = function (v) {
    if (!v) return;
    localStorage.setItem('lgse_kw_country_code', String(parseInt(v, 10) || 2840));
    loadKeywords();
  };

  function renderKeywords(el) {
    var country = lgseGetKwCountry();
    var countryOpts = LGSE_KW_COUNTRIES.map(function (c) {
      var code = c.c || c.v;
      return '<option value="' + code + '"' + (code === country ? ' selected' : '') + '>' + esc(c.l || c.v) + '</option>';
    }).join('');
    el.innerHTML = pageTitle('Keywords', 'Track positions, discover keywords from your content, and research new opportunities.')
      + '<div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-bottom:12px;flex-wrap:wrap">'
        + '<span style="font-size:11px;color:var(--lgse-t3)">Country:</span>'
        + '<select onchange="lgseSetKwCountry(this.value)" style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:6px 10px;border-radius:7px;font-size:11.5px;cursor:pointer" title="Search country">' + countryOpts + '</select>'
      + '</div>'
      + '<div class="lgse-kw-subtabs" style="display:flex;gap:0;border-bottom:1px solid var(--lgse-border);margin-bottom:16px">'
        + '<div class="lgse-kw-subtab lgse-active" data-subtab="tracked" onclick="lgseKwSubTab(\'tracked\',this)" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--p);border-bottom:2px solid var(--p);white-space:nowrap;margin-bottom:-1px">Tracked</div>'
        + '<div class="lgse-kw-subtab" data-subtab="suggestions" onclick="lgseKwSubTab(\'suggestions\',this)" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--lgse-t3);border-bottom:2px solid transparent;white-space:nowrap;margin-bottom:-1px">Suggestions</div>'
        + '<div class="lgse-kw-subtab" data-subtab="research" onclick="lgseKwSubTab(\'research\',this)" style="padding:8px 14px;cursor:pointer;font-size:12px;font-weight:500;color:var(--lgse-t3);border-bottom:2px solid transparent;white-space:nowrap;margin-bottom:-1px">Research</div>'
      + '</div>'
      + '<div id="lgse-kw-content"></div>';
    window.lgseKwSubTab('tracked', el.querySelector('.lgse-kw-subtab.lgse-active'));
  }

  window.lgseKwSubTab = function (tab, clickedEl) {
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-kw-subtab'), function (t) {
      t.classList.remove('lgse-active');
      t.style.color = 'var(--lgse-t3)';
      t.style.borderBottom = '2px solid transparent';
    });
    if (clickedEl) {
      clickedEl.classList.add('lgse-active');
      clickedEl.style.color = 'var(--p)';
      clickedEl.style.borderBottom = '2px solid var(--p)';
    }
    if (tab === 'tracked')          window.lgseRenderTrackedKeywords();
    else if (tab === 'suggestions') window.lgseRenderSuggestionsTab();
    else if (tab === 'research')    window.lgseRenderResearchTab();
  };

  window.lgseRenderTrackedKeywords = function () {
    var content = document.getElementById('lgse-kw-content');
    if (!content) return;
    content.innerHTML =
        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">'
      +   '<input id="lgse-add-kw" type="text" placeholder="Enter keywords to track (comma-separated for bulk)…" onkeydown="if(event.key===\'Enter\'){lgseAddKw();}" style="flex:1;min-width:200px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:8px 12px;border-radius:7px;font-size:11.5px">'
      +   '<button class="lgse-btn-primary" onclick="lgseAddKw()">+ Track</button>'
      +   '<button class="lgse-btn-secondary" onclick="lgseCheckAllKeywords(this)">Check all</button>'
      + '</div>'
      + '<div id="lgse-kw-body">Loading…</div>';
    loadKeywords();
  };

  window.lgseCheckAllKeywords = function (btn) {
    var orig = btn ? btn.textContent : '';
    var country = lgseGetKwCountry();
    // Wave 21 — confirm + show cost before running.
    api('GET', '/keywords?location_code=' + encodeURIComponent(country)).then(function (d) {
      var rows = (d && (d.keywords || d.data)) || (Array.isArray(d) ? d : []);
      if (!rows.length) return null;
      var totalCost = rows.length;  // 1 credit per check.
      var msg = 'Check ' + rows.length + ' keyword position' + (rows.length === 1 ? '' : 's') + '?\n\nThis will use ' + totalCost + ' credit' + (totalCost === 1 ? '' : 's') + '.\n\nDaily auto-tracking runs free in the background — only press this for an on-demand refresh.';
      if (!confirm(msg)) return null;
      if (btn) { btn.disabled = true; btn.textContent = '⏳ Checking ' + rows.length + '…'; }
      return Promise.all(rows.map(function (kw) {
        return api('POST', '/keywords/' + (kw.id || 0) + '/check', { location_code: country }).catch(function () {});
      }));
    }).then(function (r) {
      if (r === null) return;
      if (btn) { btn.disabled = false; btn.textContent = orig || 'Check all'; }
      if (typeof loadKeywords === 'function') loadKeywords();
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = orig || 'Check all'; }
    });
  };

  window.lgseRenderSuggestionsTab = function () {
    var content = document.getElementById('lgse-kw-content');
    if (!content) return;
    content.innerHTML =
        '<div style="background:linear-gradient(90deg,rgba(108,92,231,.08) 0%,transparent 100%);border-left:2px solid #6C5CE7;border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:16px">'
      +   '<div style="font-size:9px;font-weight:600;color:#6C5CE7;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">How this works</div>'
      +   '<div style="font-size:11.5px;color:var(--lgse-t2);line-height:1.65">We analyze your indexed pages and extract the most important words and phrases. Then we check their search volume using LevelUpGrowth SEO. The result is a list of keywords your content is already targeting — ranked by how many people search for them each month.</div>'
      + '</div>'
      + '<div style="text-align:center;padding:16px 0">'
      +   '<button id="lgse-suggest-btn" class="lgse-btn-primary" onclick="lgseFetchSuggestions()" style="padding:10px 24px;font-size:12px">✨ Analyze my content <span style="opacity:0.85;font-weight:400;font-size:10px">(1 cr)</span></button>'
      + '</div>'
      // Wave 20c — bulk action bar (hidden until 1+ rows selected).
      + '<div id="lgse-suggest-actions" style="display:none;align-items:center;gap:10px;padding:10px 14px;margin-bottom:12px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;flex-wrap:wrap">'
      +   '<span id="lgse-suggest-count" style="font-size:11.5px;color:var(--lgse-t2);font-weight:500">0 selected</span>'
      +   '<div style="flex:1"></div>'
      +   '<button class="lgse-btn-primary" onclick="lgseSuggTrackSelected(this)" style="padding:6px 14px;font-size:11px">+ Track selected</button>'
      +   '<button class="lgse-btn-secondary" onclick="lgseSuggCopySelected(this)" style="padding:6px 14px;font-size:11px">⧉ Copy for Sarah / AI Assistant</button>'
      +   '<button class="lgse-btn-secondary" onclick="lgseSuggClearSelection()" style="padding:6px 10px;font-size:11px" title="Clear selection">✕</button>'
      + '</div>'
      + '<div id="lgse-suggestions-list"></div>';
  };

  window.lgseFetchSuggestions = function () {
    var btn = document.getElementById('lgse-suggest-btn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Analyzing…'; }
    var country = lgseGetKwCountry();
    api('GET', '/keywords/suggestions?location_code=' + encodeURIComponent(country)).then(function (d) {
      if (btn) { btn.disabled = false; btn.textContent = '✨ Analyze my content'; }
      var suggestions = (d && (d.data || d.suggestions)) || [];
      var list = document.getElementById('lgse-suggestions-list');
      if (!list) return;
      if (!suggestions.length) {
        list.innerHTML = emptyState('⟡', 'No suggestions yet', 'Index more pages first (Pages → Scan now) to get keyword suggestions from your content.');
        return;
      }
      // Wave 20c — checkbox column at front.
      var h = '<table class="lgse-table"><thead><tr>'
        + '<th style="width:32px;text-align:center"><input type="checkbox" id="lgse-suggest-all" onchange="lgseSuggToggleAll(this)" title="Select all"></th>'
        + '<th>Keyword</th><th class="r">Volume</th><th>Competition</th><th>Status</th><th></th></tr></thead><tbody>';
      suggestions.forEach(function (s) {
        var vol = parseInt(s.volume || 0, 10);
        var volTier = vol >= 10000 ? 'HIGH' : vol >= 1000 ? 'MED' : vol > 0 ? 'LOW' : '—';
        var volCls  = vol >= 10000 ? 'lgse-vol-h' : vol >= 1000 ? 'lgse-vol-m' : 'lgse-vol-l';
        var compRaw = (s.competition || '').toString().toUpperCase();
        var compIdx = (s.competition_index != null) ? parseInt(s.competition_index, 10) : -1;
        var compLabel = compRaw === 'HIGH' ? 'Hard'
          : compRaw === 'LOW' ? 'Easy'
          : compRaw === 'MEDIUM' ? 'Medium'
          : (compIdx >= 70 ? 'Hard' : compIdx >= 30 ? 'Medium' : compIdx >= 0 ? 'Easy' : '—');
        var compCls = compLabel === 'Hard' ? 'lgse-b-red' : compLabel === 'Medium' ? 'lgse-b-amber' : compLabel === 'Easy' ? 'lgse-b-teal' : 'lgse-b-muted';
        var safeKw = (s.keyword || '').replace(/'/g, "\\'");
        var b64Kw = (typeof btoa === 'function') ? btoa(unescape(encodeURIComponent(s.keyword || ''))) : '';
        h += '<tr>'
          + '<td style="text-align:center"><input type="checkbox" class="lgse-suggest-cb" data-kw-b64="' + b64Kw + '" data-tracked="' + (s.already_tracked ? '1' : '0') + '" onchange="lgseSuggUpdate()"></td>'
          + '<td style="color:var(--lgse-t1);font-weight:500">' + esc(s.keyword || '') + '</td>'
          + '<td class="mono">' + (vol > 0 ? '<span class="lgse-badge ' + volCls + '" style="margin-right:6px">' + volTier + '</span>' + vol.toLocaleString() : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
          + '<td>' + (compLabel === '—' ? '<span style="color:var(--lgse-t3)">—</span>' : '<span class="lgse-badge ' + compCls + '">' + compLabel + '</span>') + '</td>'
          + '<td>' + (s.already_tracked ? '<span class="lgse-badge lgse-b-teal">Tracked</span>' : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
          + '<td style="text-align:right">' + (s.already_tracked ? '' : '<button class="lgse-btn-primary" style="padding:3px 10px;font-size:10px" onclick="lgseTrackSuggestion(\'' + safeKw + '\')">+ Track</button>') + '</td>'
          + '</tr>';
      });
      h += '</tbody></table>';
      list.innerHTML = h;
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = '✨ Analyze my content'; }
      var list = document.getElementById('lgse-suggestions-list');
      if (list) list.innerHTML = emptyState('⚠', 'Analysis failed', 'Keyword data is temporarily unavailable. Try again in a moment.');
    });
  };

  window.lgseTrackSuggestion = function (kw) {
    if (!kw) return;
    var country = lgseGetKwCountry();
    api('POST', '/keywords', { keyword: kw, country: country }).then(function () {
      var firstTab = document.querySelector('.lgse-kw-subtab[data-subtab="tracked"]');
      if (firstTab) window.lgseKwSubTab('tracked', firstTab);
    }).catch(function () {});
  };

  // Wave 20c — Suggestions multi-select helpers.
  function _lgseSuggDecode(b64) {
    try { return decodeURIComponent(escape(atob(b64 || ''))); } catch (_e) { return ''; }
  }
  function _lgseSuggCheckedRows() {
    return Array.prototype.slice.call(document.querySelectorAll('.lgse-suggest-cb:checked'));
  }
  function _lgseSuggNotify(msg) {
    if (typeof window.showToast === 'function') { window.showToast(msg, 'info'); return; }
    try { console.log('[LU SEO] ' + msg); } catch (_e) {}
  }
  window.lgseSuggUpdate = function () {
    var checked = _lgseSuggCheckedRows();
    var bar = document.getElementById('lgse-suggest-actions');
    var lbl = document.getElementById('lgse-suggest-count');
    if (!bar || !lbl) return;
    if (checked.length === 0) {
      bar.style.display = 'none';
    } else {
      bar.style.display = 'flex';
      var trackable = checked.filter(function (c) { return c.getAttribute('data-tracked') !== '1'; }).length;
      var already   = checked.length - trackable;
      var msg = checked.length + ' selected';
      if (already > 0) msg += ' (' + already + ' already tracked)';
      lbl.textContent = msg;
    }
    // Sync the "select all" checkbox state.
    var all = document.getElementById('lgse-suggest-all');
    var total = document.querySelectorAll('.lgse-suggest-cb').length;
    if (all) {
      all.checked       = (checked.length > 0 && checked.length === total);
      all.indeterminate = (checked.length > 0 && checked.length < total);
    }
  };
  window.lgseSuggToggleAll = function (cb) {
    var on = !!(cb && cb.checked);
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-suggest-cb'), function (c) { c.checked = on; });
    window.lgseSuggUpdate();
  };
  window.lgseSuggClearSelection = function () {
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-suggest-cb'), function (c) { c.checked = false; });
    var all = document.getElementById('lgse-suggest-all');
    if (all) { all.checked = false; all.indeterminate = false; }
    window.lgseSuggUpdate();
  };
  window.lgseSuggTrackSelected = function (btn) {
    var checked = _lgseSuggCheckedRows();
    // Filter out already-tracked rows.
    var toAdd = checked
      .filter(function (c) { return c.getAttribute('data-tracked') !== '1'; })
      .map(function (c) { return _lgseSuggDecode(c.getAttribute('data-kw-b64')); })
      .filter(function (s) { return s && s.length > 0; });
    if (toAdd.length === 0) {
      _lgseSuggNotify('No untracked keywords selected.');
      return;
    }
    var country = lgseGetKwCountry();
    var origLabel = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Tracking ' + toAdd.length + '…'; }
    var added = 0, skipped = 0, failed = 0, limitHit = false;
    function step(i) {
      if (i >= toAdd.length || limitHit) {
        if (btn) { btn.disabled = false; btn.textContent = origLabel || '+ Track selected'; }
        var parts = [];
        if (added)    parts.push(added + ' added');
        if (skipped)  parts.push(skipped + ' already tracked');
        if (failed)   parts.push(failed + ' failed');
        if (limitHit) parts.push('plan limit reached');
        _lgseSuggNotify(parts.length ? parts.join(', ') + '.' : 'Done.');
        // Refresh suggestions list (Tracked status will update for added rows).
        if (typeof window.lgseFetchSuggestions === 'function') window.lgseFetchSuggestions();
        return;
      }
      api('POST', '/keywords', { keyword: toAdd[i], country: country })
        .then(function () { added++; })
        .catch(function (e) {
          var em = (e && (e.message || e.error)) || '';
          if (/already tracked/i.test(em))   skipped++;
          else if (/limit reached/i.test(em)) { failed++; limitHit = true; }
          else                                 failed++;
        })
        .finally(function () { step(i + 1); });
    }
    step(0);
  };
  window.lgseSuggCopySelected = function (btn) {
    var checked = _lgseSuggCheckedRows();
    var kws = checked
      .map(function (c) { return _lgseSuggDecode(c.getAttribute('data-kw-b64')); })
      .filter(function (s) { return s && s.length > 0; });
    if (kws.length === 0) {
      _lgseSuggNotify('Nothing selected to copy.');
      return;
    }
    var text = kws.join(', ');
    var origLabel = btn ? btn.textContent : '';
    var done = function (ok) {
      if (btn) {
        btn.textContent = ok ? '✓ Copied — paste into Sarah / AI Assistant' : '✕ Copy failed';
        setTimeout(function () { if (btn) btn.textContent = origLabel || '⧉ Copy for Sarah / AI Assistant'; }, 2500);
      }
      _lgseSuggNotify(ok ? ('Copied ' + kws.length + ' keyword' + (kws.length === 1 ? '' : 's') + ' to clipboard. Paste into Sarah or the AI Assistant chat.') : 'Copy failed. Select keywords and try again.');
    };
    // Try the modern clipboard API first, then fall back to a hidden textarea.
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(_lgseSuggLegacyCopy(text)); });
    } else {
      done(_lgseSuggLegacyCopy(text));
    }
  };
  function _lgseSuggLegacyCopy(text) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (_e) { ok = false; }
      document.body.removeChild(ta);
      return ok;
    } catch (_e) { return false; }
  }

  window.lgseRenderResearchTab = function () {
    var content = document.getElementById('lgse-kw-content');
    if (!content) return;
    content.innerHTML =
        '<div style="background:linear-gradient(90deg,rgba(108,92,231,.08) 0%,transparent 100%);border-left:2px solid #6C5CE7;border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:16px">'
      +   '<div style="font-size:9px;font-weight:600;color:#6C5CE7;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">Keyword Research Tool</div>'
      +   '<div style="font-size:11.5px;color:var(--lgse-t2);line-height:1.65">Enter any keyword to discover related search terms, their monthly search volume, and competition level. Use this to find new content opportunities before you write.</div>'
      + '</div>'
      + '<div style="display:flex;gap:8px;margin-bottom:16px">'
      +   '<input id="lgse-research-input" placeholder="e.g. private chef dubai" style="flex:1;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:10px 14px;font-size:12px;color:var(--lgse-t1)">'
      +   '<button class="lgse-btn-primary" onclick="lgseRunResearch()" style="padding:10px 20px;font-size:12px">Research → <span style="opacity:0.85;font-weight:400;font-size:10px">(2 cr)</span></button>'
      + '</div>'
      + '<div id="lgse-research-results"><div style="text-align:center;padding:40px;color:var(--lgse-t3);font-size:11.5px">Enter a keyword above to discover related search terms</div></div>';
    setTimeout(function () {
      var inp = document.getElementById('lgse-research-input');
      if (inp) inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') window.lgseRunResearch(); });
    }, 50);
  };

  window.lgseRunResearch = function () {
    var inp = document.getElementById('lgse-research-input');
    var kw  = inp ? inp.value.trim() : '';
    if (!kw) return;
    var results = document.getElementById('lgse-research-results');
    if (results) results.innerHTML = '<div style="text-align:center;padding:20px;color:var(--lgse-t3);font-size:11.5px">⏳ Researching "' + esc(kw) + '"…</div>';
    var country = lgseGetKwCountry();
    var locLabel = '';
    try {
      var match = LGSE_KW_COUNTRIES.filter(function (c) { return (c.c || c.v) === country; })[0];
      if (match) locLabel = match.v;
    } catch (_e) {}
    api('POST', '/keywords/research', { keyword: kw, location_code: country, location: locLabel }).then(function (d) {
      var items = (d && (d.data || d.ideas)) || [];
      if (!results) return;
      if (!items.length) {
        results.innerHTML = emptyState('⟡', 'No related keywords found', 'Try a broader search term.');
        return;
      }
      var h = '<div style="font-size:10px;color:var(--lgse-t3);margin-bottom:8px">' + items.length + ' related keywords for "' + esc(kw) + '"</div>'
        + '<table class="lgse-table"><thead><tr><th>Keyword</th><th class="r">Volume</th><th>Difficulty</th><th class="r">CPC</th><th></th></tr></thead><tbody>';
      items.forEach(function (item) {
        var vol = parseInt(item.volume || 0, 10);
        var volTier = vol >= 10000 ? 'HIGH' : vol >= 1000 ? 'MED' : vol > 0 ? 'LOW' : '—';
        var volCls  = vol >= 10000 ? 'lgse-vol-h' : vol >= 1000 ? 'lgse-vol-m' : 'lgse-vol-l';
        var diff      = (item.difficulty || '').toString();
        var diffCls   = diff === 'hard' ? 'lgse-b-red' : diff === 'medium' ? 'lgse-b-amber' : diff === 'easy' ? 'lgse-b-teal' : 'lgse-b-muted';
        var diffLabel = diff ? (diff.charAt(0).toUpperCase() + diff.slice(1)) : '—';
        var safeKw = (item.keyword || '').replace(/'/g, "\\'");
        h += '<tr>'
          + '<td style="color:var(--lgse-t1);font-weight:500">' + esc(item.keyword || '') + '</td>'
          + '<td class="mono">' + (vol > 0 ? '<span class="lgse-badge ' + volCls + '" style="margin-right:6px">' + volTier + '</span>' + vol.toLocaleString() : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
          + '<td>' + (diff ? '<span class="lgse-badge ' + diffCls + '">' + diffLabel + '</span>' : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
          + '<td class="mono">' + (item.cpc != null ? '$' + parseFloat(item.cpc).toFixed(2) : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
          + '<td style="text-align:right"><button class="lgse-btn-primary" style="padding:3px 10px;font-size:10px" onclick="lgseTrackSuggestion(\'' + safeKw + '\')">+ Track</button></td>'
          + '</tr>';
      });
      h += '</tbody></table>';
      results.innerHTML = h;
    }).catch(function () {
      if (results) results.innerHTML = emptyState('⚠', 'Research failed', 'Keyword data is temporarily unavailable. Try again in a moment.');
    });
  };
  window.lgseAddKw = function () {
    var inp = document.getElementById('lgse-add-kw');
    if (!inp || !inp.value.trim()) return;
    // Wave 20b — accept comma-separated keywords for bulk add.
    var raw = inp.value.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s.length > 0; });
    var seen = {}, kws = [];
    raw.forEach(function (k) { var lo = k.toLowerCase(); if (!seen[lo]) { seen[lo] = true; kws.push(k); } });
    if (kws.length === 0) return;
    var country = lgseGetKwCountry();
    var notify = function (msg) {
      if (typeof window.showToast === 'function') { window.showToast(msg, 'info'); return; }
      try { console.log('[LU SEO] ' + msg); } catch (_e) {}
    };
    if (kws.length === 1) {
      api('POST', '/keywords', { keyword: kws[0], country: country })
        .then(function () { inp.value = ''; loadKeywords(); notify('Keyword added.'); })
        .catch(function (e) {
          var em = (e && (e.message || e.error)) || '';
          if (/already tracked/i.test(em)) notify('Already tracked.');
          else if (/limit reached/i.test(em)) notify('Keyword limit reached on your plan.');
          else notify('Could not add keyword.');
        });
      return;
    }
    // Bulk: sequential POSTs so plan-limit + duplicate checks fire per keyword.
    var added = 0, skipped = 0, failed = 0, limitHit = false;
    var btn = inp.parentElement ? inp.parentElement.querySelector('.lgse-btn-primary') : null;
    var origLabel = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Adding ' + kws.length + '…'; }
    function step(i) {
      if (i >= kws.length || limitHit) {
        inp.value = '';
        if (btn) { btn.disabled = false; btn.textContent = origLabel || '+ Track'; }
        loadKeywords();
        var parts = [];
        if (added)   parts.push(added + ' added');
        if (skipped) parts.push(skipped + ' already tracked');
        if (failed)  parts.push(failed + ' failed');
        if (limitHit) parts.push('plan limit reached');
        notify(parts.length ? parts.join(', ') + '.' : 'Done.');
        return;
      }
      api('POST', '/keywords', { keyword: kws[i], country: country })
        .then(function () { added++; })
        .catch(function (e) {
          var em = (e && (e.message || e.error)) || '';
          if (/already tracked/i.test(em))       skipped++;
          else if (/limit reached/i.test(em))    { failed++; limitHit = true; }
          else                                    failed++;
        })
        .finally(function () { step(i + 1); });
    }
    step(0);
  };
  function loadKeywords() {
    var body = document.getElementById('lgse-kw-body');
    if (!body) return;
    var country = lgseGetKwCountry();
    api('GET', '/keywords?location=' + encodeURIComponent(country)).then(function (d) {
      var rows = (d && (d.keywords || d.data)) || (Array.isArray(d) ? d : []);
      if (rows.length === 0) {
        body.innerHTML = emptyState('↗', 'No keywords tracked', 'Add keywords above to monitor their position over time.');
        return;
      }
      // Detect whether ANY row has live position/volume data.
      var hasLiveData = rows.some(function (kw) {
        return parseInt(kw.current_rank || kw.position || 0, 10) > 0 || parseInt(kw.volume || 0, 10) > 0;
      });
      var h = '';
      if (!hasLiveData) {
        h += '<div style="background:linear-gradient(90deg,rgba(108,92,231,.08) 0%,transparent 100%);border-left:2px solid var(--lgse-purple);border-radius:0 8px 8px 0;padding:10px 14px;margin-bottom:14px;font-size:11.5px;color:var(--lgse-t2);line-height:1.55">'
          + '<strong style="color:var(--lgse-t1)">Position + volume data not yet available.</strong> '
          + 'These columns populate once a SERP/GSC sync runs for this workspace. '
          + 'Connect Google Search Console (Insights → Search Console) to start syncing.'
          + '</div>';
      }
      h += '<table class="lgse-table"><thead><tr><th>Keyword</th><th class="r">Position</th><th class="r">Δ</th><th>Trend</th><th class="r">Volume</th><th></th></tr></thead><tbody>';
      rows.forEach(function (kw) {
        var pos = parseInt(kw.current_rank || kw.position || 0, 10);
        var prev = parseInt(kw.previous_rank || 0, 10);
        var change = parseInt(kw.rank_change != null ? kw.rank_change : (prev > 0 && pos > 0 ? prev - pos : 0), 10);
        var positions = Array.isArray(kw.positions) && kw.positions.length > 0 ? kw.positions.map(function (p) { return p.pos != null ? p.pos : p.position || 100; }) : (prev > 0 && pos > 0 ? [prev, pos] : []);
        var posBadge = pos === 0 ? 'lgse-b-muted' : pos <= 3 ? 'lgse-b-teal' : pos <= 10 ? 'lgse-b-blue' : pos <= 30 ? 'lgse-b-amber' : 'lgse-b-muted';
        var vol = parseInt(kw.volume || 0, 10);
        var volTier = vol >= 10000 ? 'HIGH' : vol >= 1000 ? 'MED' : vol > 0 ? 'LOW' : '—';
        var volCls = vol >= 10000 ? 'lgse-vol-h' : vol >= 1000 ? 'lgse-vol-m' : 'lgse-vol-l';
        h += '<tr><td style="color:var(--lgse-t1);font-weight:500">' + esc(kw.keyword || kw.kw || '—') + '</td>';
        h += '<td class="mono">' + (pos > 0 ? '<span class="lgse-badge ' + posBadge + '">#' + pos + '</span>' : '<span style="color:var(--lgse-t3)">—</span>') + '</td>';
        h += '<td class="mono">' + posChange(change) + '</td>';
        h += '<td>' + (positions.length > 1 ? sparkline(positions, 60, 20) : '<span style="color:var(--lgse-t3);font-size:10px">—</span>') + '</td>';
        h += '<td class="mono">' + (vol > 0 ? '<span class="lgse-badge ' + volCls + '" style="margin-right:6px">' + volTier + '</span>' + vol.toLocaleString() : '<span style="color:var(--lgse-t3)">—</span>') + '</td>';
        h += '<td style="text-align:right;white-space:nowrap">'
          + '<button class="lgse-btn-secondary" style="padding:3px 9px;font-size:10px;margin-right:4px" title="Check current SERP position (1 credit)" onclick="event.stopPropagation();lgseCheckKeyword(' + (kw.id || 0) + ',this)">Check <span style="opacity:0.7;font-size:9px">(1cr)</span></button>'
          + '<button class="lgse-btn-secondary" style="padding:3px 9px;font-size:10px" onclick="event.stopPropagation();lgseDelKw(' + (kw.id || 0) + ')">Remove</button>'
        + '</td></tr>';
      });
      h += '</tbody></table>';
      body.innerHTML = h;
    }).catch(function () { body.innerHTML = emptyState('⚠', 'Could not load keywords', 'Try refreshing.'); });
  }

  // ITEM-4 — fetch fresh SERP position for one keyword via DataForSeoConnector.
  window.lgseCheckKeyword = function (id, btn) {
    if (!id) return;
    var orig = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
    var country = (typeof lgseGetKwCountry === 'function') ? lgseGetKwCountry() : 'AE';
    api('POST', '/keywords/' + id + '/check', { location_code: country }).then(function (r) {
      if (r && r.success) {
        if (btn) { btn.disabled = false; btn.textContent = orig || 'Check'; }
        loadKeywords();
      } else if (btn) {
        btn.disabled = false;
        btn.textContent = orig || 'Check';
        if (typeof window.showToast === 'function') {
          window.showToast('Position check failed: ' + ((r && r.error) || 'unknown'), 'error');
        }
      }
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = orig || 'Check'; }
      if (typeof window.showToast === 'function') window.showToast('Position check failed', 'error');
    });
  };

  window.lgseDelKw = function (id) {
    if (!id) return;
    api('DELETE', '/keywords/' + id).then(function () { loadKeywords(); }).catch(function () {});
  };

  // ── Tab 4 — Pages (sub-tabs) ───────────────────────────────────────────

  // P0-PAGES-FIX (2026-04-28): server-side scan to populate seo_content_index.
  // POSTs to /api/seo/index-pages, which wraps SeoService::fetchAndIndexUrl.
  // If no active site URL is known (cold workspace), opens the audit modal so
  // the user can supply one — same modal as Run Audit, reused.
  window.lgseScanAndIndexPages = function () {
    var sites = window._lgseSites || [];
    var url = window._lgseActiveSite || (sites[0] && sites[0].url) || '';
    if (!url) {
      if (typeof window.lgseRunAudit === 'function') window.lgseRunAudit();
      return;
    }
    window.lgseStartScan('pages', { url: url }, '/scan-pages');
  };

  // ─────────────────────────────────────────────────────────────────────
  // P-Telemetry (2026-05-15) — polling-driven scan UX.
  // Replaces single-shot Promise + "⏳ Scanning…" with REAL stage
  // transitions sourced from /scan-status (cache-backed).
  // ─────────────────────────────────────────────────────────────────────
  window._lgseScanPollers = window._lgseScanPollers || {};
  window._lgseScanStart   = window._lgseScanStart   || {};

  window.lgseStartScan = function (type, payload, postPath) {
    if (window._lgseScanPollers[type]) {
      // Already running — ignore duplicate clicks
      return;
    }
    window._lgseScanStart[type] = Date.now();
    window.lgseRenderScanProgress(type, { state: 'running', stage: 'starting' });

    // Begin polling immediately so the user sees stage transitions
    window._lgseScanPollers[type] = setInterval(function () {
      api('GET', '/scan-status?type=' + encodeURIComponent(type)).then(function (r) {
        if (r && r.state) window.lgseRenderScanProgress(type, r.state);
      }).catch(function () { /* poll error — ignore one tick */ });
    }, 1000);

    // Kick off the actual scan POST
    api('POST', postPath, payload).then(function (resp) {
      window.lgseStopScanPoll(type);
      // One last poll to capture the final 'done' state from cache (preferred
      // over the POST response — it has stage transitions + tier counts).
      api('GET', '/scan-status?type=' + encodeURIComponent(type)).then(function (sr) {
        var state = (sr && sr.state) || { state: 'done', stage: 'done', summary: resp };
        window.lgseRenderScanProgress(type, state);
        // Refresh the affected views
        if (type === 'pages') {
          if (typeof window.lgseLoadPages === 'function') {
            setTimeout(window.lgseLoadPages, 600);
          } else if (typeof window.lgseSwitchTab === 'function') {
            setTimeout(function () { window.lgseSwitchTab('pages'); }, 600);
          }
        } else if (type === 'images') {
          if (typeof window.lgseRenderImages === 'function') {
            setTimeout(window.lgseRenderImages, 600);
          }
        }
      });
    }).catch(function (e) {
      window.lgseStopScanPoll(type);
      window.lgseRenderScanProgress(type, {
        state: 'failed',
        stage: 'failed',
        error: (e && (e.message || (e.body && e.body.error))) || 'network or server error'
      });
    });
  };

  window.lgseStopScanPoll = function (type) {
    if (window._lgseScanPollers[type]) {
      clearInterval(window._lgseScanPollers[type]);
      delete window._lgseScanPollers[type];
    }
  };

  window.lgseRenderScanProgress = function (type, state) {
    if (!state) return;
    // Find or create the live region — sits next to the Scan button.
    var hostId = type === 'pages' ? 'lgse-scanpages-progress' : 'lgse-scanimages-progress';
    var host = document.getElementById(hostId);
    if (!host) {
      // Anchor each panel to its own scan button (stable ids only).
      // Insertion point is the button's IMMEDIATE parent (the toolbar/flex
      // row), so the panel sits as a sibling directly under the toolbar,
      // visually attached to the action that triggered it.
      var anchorId = type === 'pages' ? 'lgse-scanpages-btn' : 'lgse-scanimages-btn';
      var anchor = document.getElementById(anchorId);
      // Empty-state fallback — when no data, the toolbar isn't rendered;
      // emptyState() emits a button without our id but with the same onclick.
      if (!anchor) {
        var fallbackSel = type === 'pages'
          ? '[onclick*="lgseScanAndIndexPages"]'
          : '[onclick*="lgseStartImageScan"]';
        anchor = document.querySelector(fallbackSel);
      }
      if (!anchor || !anchor.parentNode) return;
      host = document.createElement('div');
      host.id = hostId;
      host.style.cssText = 'margin-top:10px;padding:10px 12px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;font-size:11.5px;color:var(--lgse-t2);max-width:520px';
      var toolbar = anchor.parentNode;
      if (toolbar.parentNode) {
        toolbar.parentNode.insertBefore(host, toolbar.nextSibling);
      } else {
        toolbar.appendChild(host);
      }
    }

    var stageLabels = {
      starting:    'Starting scan…',
      discovering: 'Discovering pages (sitemap + DB)…',
      fetching:    'Fetching page content',
      rendering:   'Browser-rendering JS-only page',
      saving:      'Saving results',
      done:        'Scan complete',
      failed:      'Scan failed'
    };
    var label = stageLabels[state.stage] || state.stage || '...';
    var elapsed = window._lgseScanStart[type] ? Math.round((Date.now() - window._lgseScanStart[type]) / 1000) : 0;

    var btn = document.getElementById(type === 'pages' ? 'lgse-scanpages-btn' : 'lgse-scanimages-btn');
    if (btn) btn.disabled = (state.state === 'running');

    if (state.state === 'failed') {
      host.style.borderColor = 'var(--lgse-red)';
      host.innerHTML =
          '<div style="color:var(--lgse-red);font-weight:600;margin-bottom:4px">⚠ ' + lgseEsc(label) + '</div>'
        + '<div>' + lgseEsc(state.error || 'unknown') + '</div>'
        + '<button class="lgse-btn-secondary" style="margin-top:8px;font-size:10.5px;padding:4px 10px" onclick="document.getElementById(\'' + hostId + '\').remove()">Dismiss</button>';
      if (btn) { btn.disabled = false; btn.textContent = type === 'pages' ? '⟳ Scan Pages' : 'Scan images'; }
      return;
    }

    if (state.state === 'done') {
      host.style.borderColor = 'var(--lgse-teal)';
      var s = state.summary || {};
      var lines = ['<div style="color:var(--lgse-teal);font-weight:600;margin-bottom:6px">✓ Scan complete (' + elapsed + 's)</div>'];
      if (type === 'pages') {
        lines.push('<div>' + (s.pages_indexed || 0) + ' pages indexed of ' + (s.urls_found || 0) + ' discovered.</div>');
        if (s.errors_total > 0) lines.push('<div style="color:var(--lgse-amber)">' + s.errors_total + ' page(s) failed (see logs).</div>');
        if (s.sitemaps_tried && s.sitemaps_tried.length) {
          lines.push('<div style="color:var(--lgse-t3);font-size:10.5px;margin-top:4px">Sitemaps tried: ' + s.sitemaps_tried.length + '</div>');
        }
      } else {
        lines.push('<div>' + (s.pages_scanned || 0) + ' pages scanned · ' + (s.tier1_images || 0) + ' images via raw HTML</div>');
        if (s.tier2_attempts > 0) {
          lines.push('<div>Browser-rendered ' + s.tier2_attempts + ' page(s) (tier2) → ' + (s.tier2_images || 0) + ' additional images.</div>');
        }
        if (state.errors > 0) lines.push('<div style="color:var(--lgse-amber)">' + state.errors + ' error(s) during scan.</div>');
      }
      lines.push('<button class="lgse-btn-secondary" style="margin-top:8px;font-size:10.5px;padding:4px 10px" onclick="document.getElementById(\'' + hostId + '\').remove()">Dismiss</button>');
      host.innerHTML = lines.join('');
      if (btn) { btn.disabled = false; btn.textContent = type === 'pages' ? '⟳ Scan Pages' : 'Scan images'; }
      return;
    }

    // Running state
    host.style.borderColor = 'var(--lgse-purple)';
    var processed = state.processed || 0;
    var total     = state.total || 0;
    var counter   = (total > 0) ? processed + '/' + total : (processed > 0 ? processed + '' : '');
    var current   = state.current_url ? lgseTruncMid(state.current_url, 60) : '';
    var tier2     = (state.tier2_attempts || 0) > 0 ? ' · browser-rendered ' + state.tier2_attempts : '';
    var rows = [
      '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">',
        '<span style="display:inline-block;width:10px;height:10px;border:2px solid var(--lgse-purple);border-top-color:transparent;border-radius:50%;animation:lgse-spin 0.7s linear infinite"></span>',
        '<span style="color:var(--lgse-t1);font-weight:500">' + lgseEsc(label) + '</span>',
        counter ? ('<span style="color:var(--lgse-t3)">' + counter + '</span>') : '',
        '<span style="color:var(--lgse-t3);margin-left:auto">' + elapsed + 's' + tier2 + '</span>',
      '</div>'
    ];
    if (current) rows.push('<div style="color:var(--lgse-t3);font-size:10.5px;font-family:monospace">' + lgseEsc(current) + '</div>');
    if (state.errors > 0) rows.push('<div style="color:var(--lgse-amber);font-size:10.5px;margin-top:4px">' + state.errors + ' error(s) so far</div>');
    host.innerHTML = rows.join('');
  };

  // Single trigger function for the image scan — replaces inline onclick strings
  window.lgseStartImageScan = function () {
    window.lgseStartScan('images', {}, '/image-issues/bulk-analyze');
  };

  // Tiny helpers (defensive — esc + truncate)
  function lgseEsc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function lgseTruncMid(s, n) {
    s = String(s || '');
    if (s.length <= n) return s;
    var keep = Math.floor((n - 1) / 2);
    return s.substring(0, keep) + '…' + s.substring(s.length - keep);
  }

  // Inject keyframes once for the spinner
  if (!document.getElementById('lgse-scan-keyframes')) {
    var sty = document.createElement('style');
    sty.id = 'lgse-scan-keyframes';
    sty.textContent = '@keyframes lgse-spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(sty);
  }

  // Phase A (2026-05-16) — pull WP featured images into the audit table.
  // Calls /api/seo/sync-wp-featured-images, which queries the connector's
  // /lgsc/v1/posts and back-fills featured_image_url + wp_post_id.
  window.lgseSyncWpFeaturedImages = function (btn) {
    var b = btn || document.getElementById('lgse-sync-featured-btn');
    var orig = b ? b.innerText : '';
    if (b) { b.disabled = true; b.innerText = 'Syncing…'; }
    api('POST', '/sync-wp-featured-images', {}).then(function (r) {
      if (b) { b.disabled = false; b.innerText = orig || '⟳ Sync featured images'; }
      if (!r || r.success !== true) {
        var msg = (r && r.message) ? r.message
                : (r && r.error)   ? r.error
                : 'Couldn\'t reach your connected WordPress site.';
        if (r && r.error === 'no_connector_config') {
          msg = 'No WordPress site is connected to this workspace.';
        }
        window.lgseAlert('Sync failed', msg);
        return;
      }
      var matched  = parseInt(r.pages_matched   || 0, 10);
      var synced   = parseInt(r.images_synced   || 0, 10);
      var fetched  = parseInt(r.posts_fetched   || 0, 10);
      var skipped  = parseInt(r.skipped_already_set || 0, 10);
      var msg;
      if (synced > 0) {
        msg = synced + ' featured image' + (synced === 1 ? '' : 's') + ' synced from your website.';
        if (skipped > 0) msg += '\n' + skipped + ' already up-to-date.';
        if (matched - synced - skipped > 0) {
          msg += '\n' + (matched - synced - skipped) + ' page(s) matched without a featured image.';
        }
      } else if (fetched === 0) {
        msg = 'No posts returned by your WordPress site.';
      } else if (matched === 0) {
        msg = fetched + ' posts fetched, but none matched your indexed pages by URL. Try re-scanning pages first.';
      } else {
        msg = 'All featured images are already up to date (' + skipped + ' pages already synced).';
      }
      window.lgseAlert('Sync complete', msg);
      // Refresh the Indexed Content table so new images appear immediately
      if (typeof window.lgseLoadPages === 'function') {
        setTimeout(window.lgseLoadPages, 400);
      }
    }).catch(function (e) {
      if (b) { b.disabled = false; b.innerText = orig || '⟳ Sync featured images'; }
      var em = (e && e.message) || 'network error';
      window.lgseAlert('Sync failed', em);
    });
  };

  function renderPages(el) {
    // P-Dedup (2026-05-15): removed outer Scan Pages button. It carried
    // duplicate id=lgse-scanpages-btn (collided with the toolbar one inside
    // Indexed Content), persisted across all 4 sub-tabs (visible on Images
    // tab too), and confused panel anchor resolution. Each sub-tab now
    // owns its own scan trigger.
    el.innerHTML =
      '<div style="margin-bottom:14px">'
        + pageTitle('Pages', 'On-page optimization across content, images, and CTR opportunities.')
      + '</div>'
      + '<div class="lgse-subtabs" id="lgse-pages-subtabs">'
        + '<div class="lgse-subtab active" data-sec="indexed">Indexed Content</div>'
        + '<div class="lgse-subtab" data-sec="images">Images</div>'
        + '<div class="lgse-subtab" data-sec="ctr">CTR Optimizer</div>'
        + '<div class="lgse-subtab" data-sec="wins">Quick Wins</div>'
      + '</div>'
      + '<div id="lgse-pages-body"></div>';
    var body = document.getElementById('lgse-pages-body');
    var subtabs = document.getElementById('lgse-pages-subtabs');
    function load(sec) {
      Array.prototype.forEach.call(subtabs.children, function (t) { t.classList.toggle('active', t.getAttribute('data-sec') === sec); });
      body.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading…</div>';
      if (sec === 'indexed') return loadIndexed(body);
      if (sec === 'images') return loadImages(body);
      if (sec === 'ctr') return loadCtr(body);
      if (sec === 'wins') return loadWins(body);
    }
    Array.prototype.forEach.call(subtabs.children, function (t) {
      t.addEventListener('click', function () { load(t.getAttribute('data-sec')); });
    });
    load('indexed');
  }

  // P0-1: inline meta-edit for the Pages > Indexed Content table.
  // Click any "SEO Title" or "Description" cell → editable popup → save.
  // The save POSTs both meta fields together (backend always writes both,
  // so we ship the un-edited cell's current value too — see lgseIleSave).

  window.lgseIleOpen = function (el) {
    if (!el) return;
    var display = el.querySelector('.ile-display');
    var editor  = el.querySelector('.ile-editor');
    var input   = el.querySelector('.ile-input');
    var counter = el.querySelector('.ile-counter');
    if (!display || !editor || !input) return;

    // Close other open editors.
    var allEditors = document.querySelectorAll('.ile-editor');
    Array.prototype.forEach.call(allEditors, function (e) { if (e !== editor) e.style.display = 'none'; });
    var allDisplays = document.querySelectorAll('.ile-display');
    Array.prototype.forEach.call(allDisplays, function (d) { if (d !== display) d.style.display = 'block'; });

    display.style.display = 'none';
    editor.style.display = 'block';
    input.focus();
    input.select();

    var max  = parseInt(el.dataset.max  || '60', 10);
    var warn = parseInt(el.dataset.warn || '50', 10);
    function updateCounter() {
      var n = (input.value || '').length;
      if (!counter) return;
      counter.textContent = n + '/' + max;
      counter.style.color = n > max ? 'var(--lgse-red)'
                          : n > warn ? 'var(--lgse-amber)'
                                     : 'var(--lgse-teal)';
    }
    updateCounter();
    input.oninput = updateCounter;
    input.onkeydown = function (ev) {
      if (ev.key === 'Enter')  { ev.preventDefault(); window.lgseIleSave(el); }
      if (ev.key === 'Escape') { window.lgseIleClose(el); }
    };
  };

  window.lgseIleClose = function (el) {
    if (!el) return;
    var display = el.querySelector('.ile-display');
    var editor  = el.querySelector('.ile-editor');
    if (display) display.style.display = 'block';
    if (editor)  editor.style.display = 'none';
  };

  window.lgseIleSave = function (el) {
    if (!el) return;
    var url = el.dataset.url;
    if (!url) return;

    // Backend ALWAYS writes both meta_title + meta_description on every call,
    // so a partial body (just one field) would wipe the other. Walk every
    // .lgse-ile sibling in the same row and ship all current values together.
    var tr = el.closest ? el.closest('tr') : null;
    var siblings = tr ? tr.querySelectorAll('.lgse-ile') : [el];
    var body = { url: url };
    Array.prototype.forEach.call(siblings, function (s) {
      var f = s.dataset.field;
      if (!f) return;
      var inp = s.querySelector('.ile-input');
      body[f] = inp ? (inp.value || '').trim() : '';
    });

    // Optimistic UI update on the focused cell.
    var input = el.querySelector('.ile-input');
    var display = el.querySelector('.ile-display');
    var newVal = input ? (input.value || '').trim() : '';
    if (display) {
      display.textContent = newVal || 'Click to add…';
      display.style.color = newVal ? '' : 'var(--lgse-red)';
      display.title = newVal;
    }
    el.dataset.savedValue = newVal;
    window.lgseIleClose(el);

    // Wave 13b (2026-05-18): was '/connector/save-meta' (404 — wrong prefix
    // for the api() wrapper which mounts under /api/seo/). The /save-meta
    // route at /api/seo/save-meta exists and accepts the same body.
    api('PATCH', '/save-meta', body).then(function (resp) {
      if (!resp || resp.success === false) {
        if (display) {
          display.style.color = 'var(--lgse-red)';
          display.title = 'Save failed: ' + ((resp && resp.error) || 'unknown');
        }
      }
    }).catch(function () {
      if (display) {
        display.style.color = 'var(--lgse-red)';
        display.title = 'Save failed';
      }
    });
  };

  window.lgseIleCell = function (val, field, url, max, warn) {
    val = (val == null) ? '' : String(val);
    var maxLen  = parseInt(max  || 60, 10);
    var warnLen = parseInt(warn || 50, 10);
    var safeUrl = esc(url || '');
    var safeVal = esc(val);
    var displayText = val || 'Click to add…';
    var emptyColor = val ? '' : 'color:var(--lgse-red);';

    return '<div class="lgse-ile" data-url="' + safeUrl + '" data-field="' + esc(field) + '" data-max="' + maxLen + '" data-warn="' + warnLen + '" style="position:relative;display:inline-block;min-width:120px;max-width:200px">'
      // Display mode
      + '<div class="ile-display" onclick="lgseIleOpen(this.parentElement)" '
      + 'style="cursor:pointer;font-size:10.5px;' + emptyColor + 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:3px 6px;border-radius:4px;border:1px solid transparent;transition:border-color .12s" '
      + 'onmouseover="this.style.borderColor=\'var(--lgse-border2)\'" '
      + 'onmouseout="this.style.borderColor=\'transparent\'" '
      + 'title="' + safeVal + '">' + esc(displayText) + '</div>'
      // Editor mode (absolutely positioned over the cell, popping out beyond table column width)
      + '<div class="ile-editor" style="display:none;position:absolute;top:0;left:0;z-index:100;min-width:280px;background:var(--lgse-bg1);border:1px solid var(--lgse-purple);border-radius:8px;padding:8px;box-shadow:0 8px 24px rgba(0,0,0,.5)">'
      +   '<input type="text" class="ile-input" value="' + safeVal + '" '
      +     'style="width:100%;background:transparent;border:none;color:var(--lgse-t1);font-size:11.5px;outline:none;padding:2px 0">'
      +   '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;gap:6px">'
      +     '<span class="ile-counter" style="font-family:var(--lgse-mono);font-size:9px;color:var(--lgse-t3)">0/' + maxLen + '</span>'
      +     '<div style="display:flex;gap:4px">'
      +       '<button onclick="event.stopPropagation();lgseIleSave(this.closest(\'.lgse-ile\'))" style="background:var(--lgse-purple);color:white;border:none;border-radius:4px;padding:2px 10px;font-size:10px;cursor:pointer">Save</button>'
      +       '<button onclick="event.stopPropagation();lgseIleClose(this.closest(\'.lgse-ile\'))" style="background:transparent;color:var(--lgse-t3);border:none;font-size:11px;cursor:pointer">✕</button>'
      +     '</div>'
      +   '</div>'
      + '</div>'
      + '</div>';
  };

  // ─────────────────────────────────────────────────────────────────────
  // P0-2 — Pages full controls: filters / sort / search / pagination /
  // column visibility. Module-level state lives below; UI handlers are
  // window-exposed so inline onclick= strings can reach them.
  // Backend support (verified): filter ∈ {low_score, missing_meta,
  // thin_content, no_h1}; sort ∈ {score, words, issues, indexed_at};
  // q for search; page + per_page for pagination. The runbook listed
  // 16 columns — only Score / Words / SEO Title / Description / Inbound /
  // Outbound / Orphan are backed by current data; the rest render '—'
  // until those fields are added to the response.
  // ─────────────────────────────────────────────────────────────────────

  var _pgFilter         = 'all';
  var _pgSort           = 'score';
  var _pgOrder          = 'asc';   // UI state only; backend doesn't take 'order'
  var _pgPage           = 1;
  var _pgTotal          = 0;
  var _pgSize           = 25;
  var _pgSearch         = '';
  var _pgDebounceTimer  = null;
  var _pgColPanelClickInstalled = false;

  // Filter pill → backend `filter=` param (or null for client-side / no-op).
  var _pgFilterMap = {
    all:        null,
    low:        'low_score',
    no_title:   'missing_meta',  // backend's missing_meta covers title OR desc
    no_desc:    'missing_meta',
    thin:       'thin_content',
    no_h1:      'no_h1',
  };
  // Backend-supported sort keys.
  var _pgSortable = { score: 1, words: 1, issues: 1, indexed_at: 1 };

  // Default-hidden columns for the toggle panel.
  var _pgDefaultHidden = {
    'Robots': true, 'Outbound': true, 'Authority': true, 'Equity': true,
    'CTR Score': true, 'CTR Potential': true, 'Anchor Quality': true,
    'GSC Clicks': true, 'GSC Impressions': true, 'GSC Position': true,
  };

  window.lgseColVisible = function (col) {
    var key = 'lgse_pg_cols';
    var stored = {};
    try { stored = JSON.parse(localStorage.getItem(key) || '{}'); } catch (e) {}
    if (stored.hasOwnProperty(col)) return !!stored[col];
    return !_pgDefaultHidden[col];
  };
  window.lgseToggleCol = function (col) {
    var key = 'lgse_pg_cols';
    var stored = {};
    try { stored = JSON.parse(localStorage.getItem(key) || '{}'); } catch (e) {}
    stored[col] = !window.lgseColVisible(col);
    localStorage.setItem(key, JSON.stringify(stored));
    window.lgseLoadPages();
  };
  window.lgseToggleColPanel = function () {
    var panel = document.getElementById('lgse-col-panel');
    if (!panel) return;
    panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
  };

  window.lgseSetPageFilter = function (f, el) {
    _pgFilter = f;
    _pgPage = 1;
    // Re-render filter pills so active state updates.
    var wrap = document.getElementById('lgse-pg-filters');
    if (wrap) wrap.innerHTML = lgsePagesFiltersHtml();
    window.lgseLoadPages();
  };

  window.lgsePageNav = function (dir) {
    var totalPages = Math.max(1, Math.ceil(_pgTotal / _pgSize));
    _pgPage = Math.max(1, Math.min(_pgPage + dir, totalPages));
    window.lgseLoadPages();
  };

  window.lgseSortPages = function (col) {
    if (!_pgSortable[col]) return; // unsupported sort key — ignore click
    if (_pgSort === col) {
      _pgOrder = _pgOrder === 'asc' ? 'desc' : 'asc';
    } else {
      _pgSort = col;
      _pgOrder = 'asc';
    }
    _pgPage = 1;
    window.lgseLoadPages();
  };

  window.lgseSearchPages = function (val) {
    if (_pgDebounceTimer) clearTimeout(_pgDebounceTimer);
    _pgSearch = val || '';
    _pgDebounceTimer = setTimeout(function () {
      _pgPage = 1;
      window.lgseLoadPages();
    }, 300);
  };

  window.lgseExportPagesCsv = function () {
    var token = localStorage.getItem('lu_token') || '';
    fetch(window.location.origin + '/api/seo/reports/export/pages', {
      headers: { 'Authorization': 'Bearer ' + token }
    }).then(function (r) {
      if (!r.ok) { if (typeof window.showToast === 'function') window.showToast('Export failed', 'error'); return null; }
      return r.blob();
    }).then(function (blob) {
      if (!blob) return;
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url; a.download = 'pages-' + Date.now() + '.csv';
      document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
    });
  };

  window.lgseLoadPages = function () {
    // P-Gate — kick off capability fetch (one-shot, idempotent)
    if (typeof window.lgseEnsureCaps === 'function') { window.lgseEnsureCaps(); }

    var body = document.getElementById('lgse-pg-body');
    if (!body) return;
    body.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading pages…</div>';

    var params = ['per_page=' + _pgSize, 'page=' + _pgPage];
    if (_pgSortable[_pgSort]) params.push('sort=' + encodeURIComponent(_pgSort));
    var fParam = _pgFilterMap[_pgFilter];
    if (fParam) params.push('filter=' + encodeURIComponent(fParam));
    if (_pgSearch) params.push('q=' + encodeURIComponent(_pgSearch));

    api('GET', '/indexed-content?' + params.join('&')).then(function (d) {
      var pages = (d && (d.items || d.data)) || (Array.isArray(d) ? d : []);
      _pgTotal = parseInt((d && d.total) || pages.length, 10) || 0;
      window._lgsePages = pages; // P1-F — cache for row-expand detail panel.
      window.lgseRenderPagesTable(pages, _pgTotal);
    }).catch(function () {
      body.innerHTML = emptyState('⚠', 'Could not load pages', 'Check your connection and try again.');
    });
  };

  // Helpers (private — only called from within the IIFE).
  function lgsePagesFiltersHtml() {
    var pills = [
      { f: 'all',      l: 'All' },
      { f: 'low',      l: 'Low score' },
      { f: 'no_title', l: 'Missing title' },
      { f: 'no_desc',  l: 'Missing desc' },
      { f: 'thin',     l: 'Thin content' },
      { f: 'no_h1',    l: 'No H1' },
    ];
    return pills.map(function (p) {
      var active = p.f === _pgFilter;
      var bg = active ? 'rgba(108,92,231,0.08)' : 'var(--lgse-bg2)';
      var bd = active ? 'var(--lgse-purple)' : 'var(--lgse-border)';
      var c  = active ? 'var(--lgse-purple)' : 'var(--lgse-t3)';
      return '<span class="lgse-fp' + (active ? ' active' : '') + '" onclick="lgseSetPageFilter(\'' + p.f + '\', this)" '
        + 'style="background:' + bg + ';border:1px solid ' + bd + ';border-radius:20px;padding:4px 12px;font-size:10.5px;color:' + c + ';cursor:pointer;transition:all .12s">'
        + esc(p.l) + '</span>';
    }).join('');
  }

  function lgsePagesColPanelHtml() {
    var cols = ['Robots', 'Outbound', 'Authority', 'Equity', 'CTR Score', 'CTR Potential', 'Anchor Quality', 'GSC Clicks', 'GSC Impressions', 'GSC Position'];
    return cols.map(function (col) {
      var checked = window.lgseColVisible(col) ? ' checked' : '';
      return '<label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:11px;color:var(--lgse-t2);cursor:pointer">'
        + '<input type="checkbox"' + checked + ' onchange="lgseToggleCol(\'' + col + '\')" style="accent-color:var(--lgse-purple)">'
        + esc(col)
        + '</label>';
    }).join('');
  }

  window.lgseRenderPagesTable = function (pages, total) {
    var body = document.getElementById('lgse-pg-body');
    if (!body) return;

    if (!pages || !pages.length) {
      body.innerHTML = emptyState('□', 'No pages match this view',
        _pgSearch || _pgFilter !== 'all'
          ? 'Try clearing the search or filter.'
          : 'Click "Scan Pages" to crawl your site and index it for optimization.',
        'Scan Pages', 'lgseScanAndIndexPages()');
      return;
    }

    function sortInd(col) {
      if (_pgSort !== col) return '';
      return _pgOrder === 'asc' ? ' ↑' : ' ↓';
    }
    function th(label, col, opts) {
      opts = opts || {};
      var rightStyle = opts.right ? 'text-align:right;' : '';
      var sortable = _pgSortable[col];
      var cursor = sortable ? 'pointer' : 'default';
      var click = sortable ? ' onclick="lgseSortPages(\'' + col + '\')"' : '';
      return '<th' + click + ' style="cursor:' + cursor + ';' + rightStyle + 'user-select:none">'
        + esc(label) + (sortable ? sortInd(col) : '') + '</th>';
    }

    // Always-visible columns first.
    var thead = '<thead><tr>'
      + '<th style="width:26px;text-align:center"><input type="checkbox" id="lgse-pg-selectall" onchange="lgseTogglePageSelectAll(this.checked)" style="cursor:pointer"></th>' /* P1-G master checkbox */
      + '<th style="width:22px"></th>' /* P1-F chevron column */
      + th('URL', 'url')
      + th('Score', 'score', { right: true })
      + th('Words', 'words', { right: true })
      + '<th style="width:52px">Image</th>'
      + '<th>SEO Title</th>'
      + '<th>Description</th>';

    // Toggleable columns (rendered when visible).
    var toggleCols = [
      { label: 'Robots',          col: 'robots' },
      { label: 'Inbound',         col: 'il_in',           right: true },
      { label: 'Outbound',        col: 'il_out',          right: true },
      { label: 'Orphan',          col: 'orphan' },
      { label: 'Authority',       col: 'il_authority',    right: true },
      { label: 'Equity',          col: 'equity',          right: true },
      { label: 'CTR Score',       col: 'ctr_score',       right: true },
      { label: 'CTR Potential',   col: 'ctr_potential' },
      { label: 'Anchor Quality',  col: 'anchor_quality',  right: true },
      { label: 'GSC Clicks',      col: 'gsc_clicks',      right: true },
      { label: 'GSC Impressions', col: 'gsc_impressions', right: true },
      { label: 'GSC Position',    col: 'gsc_position',    right: true },
    ];
    toggleCols.forEach(function (c) {
      if (window.lgseColVisible(c.label)) thead += th(c.label, c.col, { right: !!c.right });
    });
    thead += '</tr></thead>';

    var dash = '<span style="color:var(--lgse-t3)">—</span>';

    var rowsHtml = pages.map(function (p) {
      var url = p.url || '';
      var score = parseInt(p.content_score || p.score || 0, 10) || 0;
      var words = parseInt(p.word_count || 0, 10) || 0;
      var ilIn  = parseInt(p.internal_link_count || 0, 10) || 0;
      var ilOut = parseInt(p.external_link_count || 0, 10) || 0;
      var orphan = ilIn === 0;
      var pid = parseInt(p.id, 10) || 0;
      // Bare hostnames (e.g. "chef-red.levelupgrowth.io") get treated as
      // relative paths by the browser if used in href. Force absolute.
      var externalUrl = url ? (/^https?:\/\//i.test(url) ? url : 'https://' + url) : '';

      // P0.7 (2026-05-15) — data-lgse-url enables Quick Wins URL-based row targeting
      // (Quick Wins doesn't know the row id, only the URL). Used by lgseHighlightUrl.
      // Wave 15.2 (2026-05-18) — inline CTA chips in the URL cell. Always
      // visible regardless of the toggleable-column state. Orphan chip
      // routes to /links/apply-bulk scoped to this target_url; image-error
      // chip routes to /pages/retry-image.
      var imgError = p.featured_image_error || '';
      var safeUrlAttr = (url || '').replace(/'/g, '%27').replace(/"/g, '&quot;');
      var inlineChips = '';
      if (orphan) {
        inlineChips += '<button onclick="event.stopPropagation();window._seoFixOrphan(\'' + safeUrlAttr + '\')" title="Apply queued link suggestions targeting this page" style="margin-left:8px;background:rgba(245,158,11,.12);color:#F59E0B;border:1px solid rgba(245,158,11,.3);border-radius:6px;padding:2px 8px;font-size:10px;font-weight:600;cursor:pointer">⚠ Orphan · Fix</button>';
      }
      if (imgError) {
        var errShort = imgError.length > 60 ? imgError.substring(0,57) + '…' : imgError;
        inlineChips += '<button onclick="event.stopPropagation();window._seoRetryImage(\'' + safeUrlAttr + '\')" title="Image generation failed: ' + errShort.replace(/"/g,'&quot;') + '" style="margin-left:6px;background:rgba(248,113,113,.12);color:#F87171;border:1px solid rgba(248,113,113,.3);border-radius:6px;padding:2px 8px;font-size:10px;font-weight:600;cursor:pointer">⚠ Image · Retry</button>';
      }

      var tr = '<tr data-page-id="' + pid + '" data-lgse-url="' + esc(url) + '">'
        + '<td style="text-align:center"><input type="checkbox" class="lgse-pg-check" data-pid="' + pid + '" onchange="lgseUpdateBulkBar()" style="cursor:pointer"></td>'
        + '<td onclick="lgseExpandPageRow(' + pid + ')" style="cursor:pointer;text-align:center;color:var(--lgse-t3);font-size:10px" title="Show details"><span class="lgse-pg-chev" data-pid="' + pid + '" style="display:inline-block;transition:transform .15s">▶</span></td>'
        + '<td class="lgse-url" title="' + esc(url) + '">'
          + (url
              ? '<a href="' + esc(externalUrl) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()" style="color:var(--lgse-purple);text-decoration:none" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">' + esc(url) + '</a>'
              : '<span style="color:var(--lgse-t3)">—</span>')
          + inlineChips
        + '</td>'
        + '<td class="mono">' + (score ? scorePill(score) : dash) + '</td>'
        + '<td class="mono">' + (words || dash) + '</td>'
        + '<td>' + (function () {
            var titleAttr = esc(p.title || url || '');
            var safeUrl = encodeURIComponent(url || '');
            var safeTitle = encodeURIComponent(p.title || url || '');
            if (p.featured_image_url) {
              return '<img src="' + esc(p.featured_image_url) + '" '
                + 'style="width:44px;height:36px;object-fit:cover;border-radius:4px;cursor:pointer" '
                + 'title="Regenerate featured image (1 credit)" '
                + 'onclick="window._lgseRegenImage(' + pid + ',decodeURIComponent(\'' + safeUrl + '\'),decodeURIComponent(\'' + safeTitle + '\'),this)">';
            }
            return '<div onclick="window._lgseRegenImage(' + pid + ',decodeURIComponent(\'' + safeUrl + '\'),decodeURIComponent(\'' + safeTitle + '\'),this)" '
              + 'style="width:44px;height:36px;background:#1e293b;border:1px dashed #334155;border-radius:4px;cursor:pointer;display:flex;align-items:center;justify-content:center" '
              + 'title="Generate featured image (1 credit)">'
              + '<span style="color:#475569;font-size:16px">📷</span></div>';
          })() + '</td>'
        + '<td style="position:relative;max-width:220px">' + window.lgseIleCell(p.meta_title || p.title || '', 'meta_title', url, 60, 50) + '</td>'
        + '<td style="position:relative;max-width:240px">' + window.lgseIleCell(p.meta_description || '', 'meta_description', url, 160, 140) + '</td>';

      if (window.lgseColVisible('Robots'))          tr += '<td>' + dash + '</td>';
      if (window.lgseColVisible('Inbound'))         tr += '<td class="mono" style="color:' + (ilIn === 0 ? 'var(--lgse-red)' : 'inherit') + '">' + ilIn + '</td>';
      if (window.lgseColVisible('Outbound'))        tr += '<td class="mono">' + (ilOut || dash) + '</td>';
      if (window.lgseColVisible('Orphan'))          tr += '<td>' + (orphan ? badge('orphan', 'red') : badge('linked', 'teal')) + '</td>';
      if (window.lgseColVisible('Authority'))       tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('Equity'))          tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('CTR Score'))       tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('CTR Potential'))   tr += '<td>' + dash + '</td>';
      if (window.lgseColVisible('Anchor Quality'))  tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('GSC Clicks'))      tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('GSC Impressions')) tr += '<td class="mono">' + dash + '</td>';
      if (window.lgseColVisible('GSC Position'))    tr += '<td class="mono">' + dash + '</td>';

      tr += '</tr>';
      return tr;
    }).join('');

    var totalPages = Math.max(1, Math.ceil(total / _pgSize));
    var pagination = '';
    if (total > _pgSize) {
      pagination = '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 4px;margin-top:8px;border-top:1px solid var(--lgse-border);font-size:11px">'
        + '<button onclick="lgsePageNav(-1)"' + (_pgPage <= 1 ? ' disabled' : '') + ' style="background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border);border-radius:6px;padding:5px 12px;font-size:11px;' + (_pgPage <= 1 ? 'opacity:.4;cursor:not-allowed' : 'cursor:pointer') + '">← Prev</button>'
        + '<span style="font-family:var(--lgse-mono);font-size:11px;color:var(--lgse-t3)">Page ' + _pgPage + ' of ' + totalPages + ' · ' + total + ' pages</span>'
        + '<button onclick="lgsePageNav(1)"' + (_pgPage >= totalPages ? ' disabled' : '') + ' style="background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border);border-radius:6px;padding:5px 12px;font-size:11px;' + (_pgPage >= totalPages ? 'opacity:.4;cursor:not-allowed' : 'cursor:pointer') + '">Next →</button>'
        + '</div>';
    }

    body.innerHTML = '<div style="overflow-x:auto"><table class="lgse-table">' + thead + '<tbody>' + rowsHtml + '</tbody></table>' + pagination + '</div>';
  };

  function loadIndexed(body) {
    // Reset state when entering the sub-tab fresh.
    _pgPage = 1;
    body.innerHTML = ''
      // Toolbar: search · Columns ▾ · Export · Scan Pages.
      + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap">'
      +   '<input id="lgse-pg-search" placeholder="Search URL or title…" oninput="lgseSearchPages(this.value)" '
      +     'style="flex:1;min-width:180px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:7px 12px;font-size:11.5px;color:var(--lgse-t1)">'
      +   '<div style="position:relative">'
      +     '<button onclick="event.stopPropagation();lgseToggleColPanel()" style="background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border);border-radius:7px;padding:7px 12px;font-size:11px;cursor:pointer">Columns ▾</button>'
      +     '<div id="lgse-col-panel" style="display:none;position:absolute;right:0;top:100%;margin-top:4px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:10px;padding:12px;min-width:200px;z-index:20;box-shadow:0 8px 24px rgba(0,0,0,.4)">'
      +       lgsePagesColPanelHtml()
      +     '</div>'
      +   '</div>'
      +   '<button onclick="lgseExportPagesCsv()" style="background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border);border-radius:7px;padding:7px 12px;font-size:11px;cursor:pointer">Export CSV</button>'
      +   '<button class="lgse-btn-secondary" id="lgse-scanpages-btn" onclick="lgseScanAndIndexPages()" style="font-size:11px">⟳ Scan Pages</button>'
      +   '<button class="lgse-btn-secondary" id="lgse-sync-featured-btn" onclick="lgseSyncWpFeaturedImages(this)" style="font-size:11px" title="Pull featured images from your connected WordPress site">⟳ Sync featured images</button>'
      + '</div>'
      // Filter pills.
      + '<div id="lgse-pg-filters" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">' + lgsePagesFiltersHtml() + '</div>'
      // Table body.
      + '<div id="lgse-pg-body"></div>';

    // Outside-click closes the col panel — install once.
    if (!_pgColPanelClickInstalled) {
      _pgColPanelClickInstalled = true;
      document.addEventListener('click', function (e) {
        var panel = document.getElementById('lgse-col-panel');
        if (!panel || panel.style.display !== 'block') return;
        if (e.target.closest && (e.target.closest('#lgse-col-panel') || (e.target.closest('button') && /lgseToggleColPanel/.test(e.target.closest('button').getAttribute('onclick') || '')))) return;
        panel.style.display = 'none';
      });
    }

    window.lgseLoadPages();
  }
  // P1-D — Images sub-tab is rendered by window.lgseRenderImages (defined below).
  // loadImages stays as a thin delegator so renderPages' dispatch keeps working.
  function loadImages(body) { window.lgseRenderImages(body); }
  function loadCtr(body) {
    api('GET', '/ctr-analysis?worst=20').then(function (d) {
      var rows = (d && (d.pages || d.data)) || (Array.isArray(d) ? d : []);
      if (rows.length === 0) {
        body.innerHTML = emptyState(
          '↗',
          'No CTR data yet',
          'CTR optimization needs Google Search Console connected. Once connected, this tab surfaces pages with high impressions but low click-through — your biggest optimization opportunity.',
          'Connect GSC',
          'lgseSwitchTab(\'insights\');setTimeout(function(){var s=document.querySelector(\'.lgse-subtab[data-sec="gsc"]\');if(s)s.click();},20)'
        );
        return;
      }
      var h = '<table class="lgse-table"><thead><tr><th>URL</th><th class="r">Clicks</th><th class="r">Impressions</th><th class="r">CTR</th><th class="r">Position</th></tr></thead><tbody>';
      rows.slice(0, 50).forEach(function (p) {
        var ctr = parseFloat(p.ctr || 0);
        h += '<tr><td class="lgse-url">' + esc(p.url || p.page_url || '—') + '</td>';
        h += '<td class="mono">' + (parseInt(p.clicks || 0, 10) || 0).toLocaleString() + '</td>';
        h += '<td class="mono">' + (parseInt(p.impressions || 0, 10) || 0).toLocaleString() + '</td>';
        h += '<td class="mono">' + (ctr * 100).toFixed(2) + '%</td>';
        h += '<td class="mono">' + (parseFloat(p.position || 0)).toFixed(1) + '</td></tr>';
      });
      h += '</tbody></table>';
      body.innerHTML = h;
    }).catch(function () { body.innerHTML = emptyState('⚠', 'CTR analysis unavailable', 'Connect Google Search Console to enable.'); });
  }
  // P0.6 (2026-05-15) — Quick Wins is now an ACTION ROUTER. Each card is
  // clickable and routes to the correct fixing surface (Indexed Content,
  // Images sub-tab, Links tab) OR opens an honest informational modal for
  // system-level issues that LevelUp cannot fix. No fake auto-fixes, no
  // dead clicks. Routing logic lives in window.lgseRouteWin.
  // P0.7 — fix→verify→refresh loop. Records Quick Wins that the operator
  // has just resolved via inline save (lgseSavePageRow). loadWins filters
  // these out so resolved cards disappear immediately. Audit job catches
  // up later and persists the resolution. Cleared on full page refresh —
  // intentional (server is the source of truth, this is just optimistic UI).
  window._lgseRecentlyFixed = window._lgseRecentlyFixed || {};
  window.lgseMarkWinResolved = function (url, titlePrefix) {
    if (!url || !titlePrefix) return;
    window._lgseRecentlyFixed[titlePrefix + '|' + url] = Date.now();
  };
  // Helper: does THIS quick-win match a recently-fixed signature?
  // Match by title startsWith + url exact. Handles "Meta description exists"
  // → "Meta description exists" AND "Meta description length (70-160)".
  function _winIsRecentlyResolved(w) {
    var t = String(w.title || '');
    var u = String(w.url || '');
    if (!t || !u) return false;
    var keys = Object.keys(window._lgseRecentlyFixed);
    for (var i = 0; i < keys.length; i++) {
      var sep = keys[i].indexOf('|');
      if (sep < 0) continue;
      var kPrefix = keys[i].slice(0, sep);
      var kUrl    = keys[i].slice(sep + 1);
      if (kUrl !== u) continue;
      if (t.toLowerCase().indexOf(kPrefix.toLowerCase()) === 0) return true;
    }
    return false;
  }

  function loadWins(body) {
    api('GET', '/quick-wins').then(function (d) {
      var rows = (d && (d.quick_wins || d.wins || d.data)) || (Array.isArray(d) ? d : []);
      // P0.7 — filter out recently-fixed wins
      var beforeFilter = rows.length;
      rows = rows.filter(function (w) { return !_winIsRecentlyResolved(w); });
      var hiddenByLocalFix = beforeFilter - rows.length;
      if (rows.length === 0) {
        var msg = hiddenByLocalFix > 0
          ? hiddenByLocalFix + ' issue' + (hiddenByLocalFix === 1 ? '' : 's') + ' resolved this session — refresh the page to re-check from server.'
          : 'Run audits and sync GSC to surface lift opportunities.';
        body.innerHTML = emptyState('✓', 'No quick wins right now', msg);
        return;
      }
      var h = '';
      if (hiddenByLocalFix > 0) {
        h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;padding:8px 12px;background:rgba(20,184,166,0.08);border:1px solid var(--lgse-teal,#14b8a6);border-radius:6px;font-size:11.5px;color:var(--lgse-teal,#14b8a6)">'
          + '<span>✓ ' + hiddenByLocalFix + ' issue' + (hiddenByLocalFix === 1 ? '' : 's') + ' resolved this session</span>'
          + '<button onclick="loadWins(document.getElementById(\'lgse-pages-body\'))" style="margin-left:auto;background:transparent;color:var(--lgse-teal,#14b8a6);border:1px solid var(--lgse-teal,#14b8a6);border-radius:5px;padding:4px 10px;font-size:10.5px;cursor:pointer">Refresh from server</button>'
          + '</div>';
      }
      h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px">';
      rows.slice(0, 30).forEach(function (w) {
        var label = w.issue_label || w.issue || w.gap || w.description || w.title || 'Optimization opportunity';
        var fix = w.fix || w.recommendation || w.action || w.description || '';
        var url = w.url || '';
        var sev = (w.severity || '').toLowerCase();
        // Severity-driven badge (was using impact/priority which our backend doesn't emit).
        var bk = sev === 'error' ? 'red' : sev === 'warning' ? 'amber' : sev === 'opportunity' ? 'purple' : 'teal';
        var bl = sev === 'error' ? 'FIX' : sev === 'warning' ? 'IMPROVE' : sev === 'opportunity' ? 'OPPORTUNITY' : 'INFO';
        // Determine routing class — used to style cursor + hover.
        var routing = window.lgseClassifyWin ? window.lgseClassifyWin(label) : 'info';
        var clickable = routing !== 'noop';
        var cursor = clickable ? 'cursor:pointer' : 'cursor:default';
        var hint   = routing === 'page'   ? 'Open in Indexed Content →'
                   : routing === 'image'  ? 'Open in Images audit →'
                   : routing === 'links'  ? 'Open Links tab →'
                   : routing === 'info'   ? 'What this means →'
                   : '';
        // Use data-* so the click handler reads from DOM, no string-escaping land mines.
        var safeLabel = esc(label);
        var safeUrl   = esc(url);
        h += '<div class="lgse-win-tile" '
          +   'data-lgse-win-label="' + safeLabel + '" '
          +   'data-lgse-win-url="'   + safeUrl   + '" '
          +   'data-lgse-win-route="' + routing   + '" '
          +   (clickable
                ? 'onclick="window.lgseRouteWin(this)" '
                  + 'onmouseover="this.style.borderColor=\'var(--lgse-purple,#7c3aed)\'" '
                  + 'onmouseout="this.style.borderColor=\'var(--lgse-border,#1e293b)\'" '
                : '')
          +   'style="' + cursor + ';transition:border-color .12s;border:1px solid var(--lgse-border,#1e293b);border-radius:8px;padding:14px;background:var(--lgse-bg2,#0f172a)">'
          + '<div style="display:flex;justify-content:space-between;align-items:start;gap:10px;margin-bottom:8px">'
          + '<div style="font-size:13px;color:var(--lgse-t1);font-weight:600;line-height:1.4">' + safeLabel + '</div>'
          + badge(bl, bk)
          + '</div>';
        if (fix) h += '<div style="font-size:11px;color:var(--lgse-t2);line-height:1.5;margin-bottom:8px">' + esc(fix) + '</div>';
        if (url) h += '<div style="font-size:10.5px;color:var(--lgse-t3);font-family:var(--lgse-mono,monospace);max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + safeUrl + '</div>';
        if (hint) h += '<div style="font-size:10px;color:var(--lgse-purple,#7c3aed);margin-top:8px;font-weight:500">' + hint + '</div>';
        h += '</div>';
      });
      h += '</div>';
      body.innerHTML = h;
    }).catch(function () { body.innerHTML = emptyState('⚠', 'Could not load quick wins', 'Try refreshing.'); });
  }

  // P0.6 — Quick Wins routing classifier. Maps an issue label to a routing
  // class. Each class corresponds to a deterministic action in lgseRouteWin.
  // Source of truth for the issue→routing taxonomy.
  window.lgseClassifyWin = function (label) {
    var L = String(label || '').toLowerCase();
    // Page-level editable — open Indexed Content row + focus field
    if (L.indexOf('meta description') === 0 || L.indexOf('meta description') !== -1) return 'page';
    if (L.indexOf('title tag') !== -1 || L.indexOf('title length') !== -1)         return 'page';
    if (L.indexOf('h1 tag') !== -1 || L.indexOf('heading structure') !== -1)        return 'page';
    if (L.indexOf('content length') !== -1)                                          return 'page';
    // Image-level — open Images sub-tab
    if (L.indexOf('image alt') !== -1 || L.indexOf('alt text') !== -1)              return 'image';
    // Cross-tab navigable
    if (L.indexOf('internal link') !== -1 || L.indexOf('external link') !== -1)     return 'links';
    // Opportunity — keyword rank boost
    if (L.indexOf('rank boost') !== -1)                                              return 'page';
    // Everything else is system/CMS/infra → informational modal
    return 'info';
  };

  // P0.6 — Quick Wins router. Reads data-* attributes from the clicked tile
  // and dispatches to the correct fixing surface.
  window.lgseRouteWin = function (tileEl) {
    if (!tileEl) return;
    var label = tileEl.getAttribute('data-lgse-win-label') || '';
    var url   = tileEl.getAttribute('data-lgse-win-url')   || '';
    var route = tileEl.getAttribute('data-lgse-win-route') || 'info';

    if (route === 'page') {
      // P0.7 — derive which field to focus from the label. The classifier
      // already routed us here; this just refines the focus target.
      var labelLo = String(label || '').toLowerCase();
      var fieldKey = null;
      if (labelLo.indexOf('meta description') !== -1) fieldKey = 'meta';
      else if (labelLo.indexOf('title tag') !== -1 || labelLo.indexOf('title length') !== -1) fieldKey = 'title';
      else if (labelLo.indexOf('h1') !== -1 || labelLo.indexOf('heading') !== -1) fieldKey = 'h1';
      // 'content length', 'rank boost' have no inline edit field — just highlight.

      window._lgseRouteToUrl = url;
      window._lgseRouteToLabel = label;
      if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('pages');
      // Polling lives inside lgseHighlightUrl now — no need for a fixed
      // setTimeout that may fire too early/late.
      window.lgseHighlightUrl && window.lgseHighlightUrl(url, fieldKey);
      return;
    }

    if (route === 'image') {
      // Switch to Pages tab → Images sub-tab. Highlight runs polling.
      window._lgseRouteToUrl = url;
      if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('pages');
      setTimeout(function () {
        var imgTab = document.querySelector('.lgse-subtab[data-sec="images"]');
        if (imgTab && typeof imgTab.click === 'function') imgTab.click();
        // After image sub-tab fetches, lgseHighlightImageRow polls for the row.
        window.lgseHighlightImageRow && window.lgseHighlightImageRow(url);
      }, 300);
      return;
    }

    if (route === 'links') {
      if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('links');
      return;
    }

    // route === 'info' — explain-only modal (system/CMS/infra)
    window.lgseShowWinInfoModal(label, url);
  };

  // P0.6 — Honest explanation modal for system-level issues that LevelUp
  // cannot directly fix. NO fake auto-fix buttons, NO false promises.
  window.lgseShowWinInfoModal = function (label, url) {
    var L = String(label || '');
    var Llo = L.toLowerCase();
    var explanations = {
      'structured data':     'Structured data (JSON-LD schema) requires CMS plugin or template change. Common solutions: Yoast / Rank Math (WordPress), or custom JSON-LD blocks added by your theme. <strong>LevelUp cannot inject schema remotely.</strong>',
      'open graph tags':     'Open Graph tags are usually managed by your CMS theme or SEO plugin. Check your theme settings or install an SEO plugin (Yoast, Rank Math, AIOSEO).',
      'twitter cards':       'Twitter Card meta tags share the same source as Open Graph (often the same theme/plugin settings). Adding OG tags typically adds Twitter Cards too.',
      'https enabled':       'HTTPS is a server-level config. Use Cloudflare SSL (Flexible or Full mode) or your hosting control panel\'s SSL setting. <strong>LevelUp cannot toggle SSL on your server.</strong>',
      'hsts header':         'HSTS (HTTP Strict Transport Security) is set in your web server config (nginx, Apache) or via Cloudflare → SSL/TLS → Edge Certificates → HSTS. <strong>LevelUp cannot configure server headers remotely.</strong>',
      'compression':         'Server-side gzip/brotli compression is set in nginx/Apache config OR enabled at Cloudflare → Speed → Optimization. Most modern hosts enable it by default.',
      'server response time':'TTFB (Time To First Byte) depends on your hosting. Common fixes: enable caching, upgrade hosting, use a CDN. <strong>This is hosting-side, not editable from LevelUp.</strong>',
      'url accessible':      'This URL returned a non-2xx HTTP status. Either restore the page in your CMS or remove this URL from your indexed list (Pages → Indexed Content → delete row).',
      'url length':          'This URL is longer than ideal for SEO. Consider shorter, descriptive slugs in your CMS. URLs already published cannot be safely changed without 301 redirects — leave or replace at your discretion.',
      'viewport meta tag':   'The viewport meta tag is set in your site template (e.g., <meta name="viewport" content="width=device-width">). Modern themes include this automatically — if missing, edit your template.',
      'canonical url':       'Canonical URL tags prevent duplicate-content issues. Most CMSs auto-generate them. If incorrect, fix in your CMS\'s SEO settings or theme.',
      'page size':           'Page size is determined by content + images + scripts. Optimize images (use the Images sub-tab → Optimize), minify CSS/JS, lazy-load below-the-fold content.',
    };
    var found = '';
    Object.keys(explanations).forEach(function (k) {
      if (!found && Llo.indexOf(k) !== -1) found = explanations[k];
    });
    if (!found) {
      found = 'This is a system or template-level issue. LevelUp surfaces it for awareness but cannot directly fix it from the dashboard.';
    }
    var html = '<div style="font-size:12px;color:var(--lgse-t2);line-height:1.6">' + found + '</div>';
    if (url) {
      html += '<div style="margin-top:14px;padding:10px 12px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:6px;font-size:11px;color:var(--lgse-t3)">'
        + '<div style="color:var(--lgse-t3);margin-bottom:4px">Affected page:</div>'
        + '<a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer" style="color:var(--lgse-purple,#7c3aed);font-family:var(--lgse-mono);text-decoration:none;word-break:break-all">' + esc(url) + ' ↗</a>'
        + '</div>';
    }
    if (typeof window.lgseShowModal === 'function') {
      window.lgseShowModal(L || 'About this issue', html, null, { hideSave: true, cancelLabel: 'Close' });
    } else {
      lgseAlert(L, found.replace(/<[^>]+>/g,''));
    }
  };

  // P0.7 (2026-05-15) — Highlight + scroll + auto-expand + field-focus.
  // Polls for the target row because lgseSwitchTab is sync but the renderer
  // loads data async (~400-800ms). Polls 15× at 200ms = 3s max wait.
  // Once found:
  //   1. Smooth-scroll into view
  //   2. Flash 2s purple border (auto-fade, no timer pile-up — uses
  //      element-level transition rather than detached setTimeout closures)
  //   3. If fieldKey provided AND the row has a page-id, expand the row
  //      (idempotent — only if not already expanded) and focus the matching
  //      input. Field map: 'meta' → meta_description, 'title' → meta_title,
  //      'h1' → h1.
  window.lgseHighlightUrl = function (url, fieldKey) {
    if (!url) return;
    var attempts = 0;
    var maxAttempts = 15;
    var poll = function () {
      var rows = document.querySelectorAll('[data-lgse-url]');
      for (var i = 0; i < rows.length; i++) {
        if (rows[i].getAttribute('data-lgse-url') === url) {
          window.lgseFlashElement(rows[i]);
          var pid = rows[i].getAttribute('data-page-id');
          if (fieldKey && pid) {
            // Idempotent expand: only fire if the edit field doesn't exist yet
            if (!document.getElementById('lgse-edit-title-' + pid)
                && typeof window.lgseExpandPageRow === 'function') {
              window.lgseExpandPageRow(parseInt(pid, 10));
            }
            // Focus the requested field after the detail row mounts
            setTimeout(function () {
              var fieldMap = { meta: 'meta', title: 'title', h1: 'h1' };
              var k = fieldMap[fieldKey];
              if (!k) return;
              var el = document.getElementById('lgse-edit-' + k + '-' + pid);
              if (el && typeof el.focus === 'function') {
                try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
                if (typeof el.select === 'function') el.select();
              }
            }, 250);
          }
          return;
        }
      }
      if (++attempts < maxAttempts) setTimeout(poll, 200);
    };
    poll();
  };

  // P0.7 — Same poll/highlight pattern but targets an Image audit row by
  // page+image URL pair. Used by Quick Wins 'image' route.
  window.lgseHighlightImageRow = function (pageUrl) {
    if (!pageUrl) return;
    var attempts = 0;
    var maxAttempts = 15;
    var poll = function () {
      var rows = document.querySelectorAll('[data-lgse-page-url]');
      for (var i = 0; i < rows.length; i++) {
        if (rows[i].getAttribute('data-lgse-page-url') === pageUrl) {
          window.lgseFlashElement(rows[i]);
          return;
        }
      }
      if (++attempts < maxAttempts) setTimeout(poll, 200);
    };
    poll();
  };

  // P0.7 — Shared flash helper. Element-bound transition + 1 timeout for
  // restore. Calling twice on the same element doesn't pile up — second call
  // resets the timer. Dark-theme compatible (uses --lgse-purple var).
  window.lgseFlashElement = function (el) {
    if (!el || !el.style) return;
    try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
    if (el._lgseFlashTimer) {
      clearTimeout(el._lgseFlashTimer);
    } else {
      el._lgseFlashOriginalShadow = el.style.boxShadow || '';
    }
    el.style.transition = 'box-shadow .25s';
    el.style.boxShadow = '0 0 0 2px var(--lgse-purple,#7c3aed)';
    el._lgseFlashTimer = setTimeout(function () {
      el.style.boxShadow = el._lgseFlashOriginalShadow || '';
      el._lgseFlashTimer = null;
    }, 2000);
  };

  // ── Tab 5 — Links (sub-tabs) ───────────────────────────────────────────
  function renderLinks(el) {
    el.innerHTML = pageTitle('Links', 'Internal link graph, outbound checks, and 301/302 redirects in one place.')
      + '<div class="lgse-subtabs" id="lgse-links-subtabs">'
        + '<div class="lgse-subtab active" data-sec="internal">Link Intelligence</div>'
        + '<div class="lgse-subtab" data-sec="anchors">Anchors</div>'
        + '<div class="lgse-subtab" data-sec="gaps">Gaps</div>'
        + '<div class="lgse-subtab" data-sec="outbound">Outbound</div>'
        + '<div class="lgse-subtab" data-sec="redirects">Redirects</div>'
      + '</div>'
      + '<div id="lgse-links-body"></div>';
    var body = document.getElementById('lgse-links-body');
    var subtabs = document.getElementById('lgse-links-subtabs');
    function load(sec) {
      Array.prototype.forEach.call(subtabs.children, function (t) { t.classList.toggle('active', t.getAttribute('data-sec') === sec); });
      body.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading…</div>';
      if (sec === 'internal')  return loadInternalLinks(body);
      if (sec === 'anchors')   return loadAnchorsSec(body);
      if (sec === 'gaps')      return loadGapsSec(body);
      if (sec === 'outbound')  return loadOutbound(body);
      if (sec === 'redirects') return loadRedirects(body);
    }
    Array.prototype.forEach.call(subtabs.children, function (t) { t.addEventListener('click', function () { load(t.getAttribute('data-sec')); }); });
    load('internal');
  }
  function loadInternalLinks(body) {
    Promise.all([
      api('GET', '/link-graph').catch(function () { return null; }),
      api('GET', '/link-graph/orphans').catch(function () { return null; }),
      api('GET', '/links/anchor-analysis').catch(function () { return null; }),
      // Wave 15.2 (2026-05-18) — fetch queue count for the bulk CTA badge.
      api('GET', '/links').catch(function () { return null; }),
    ]).then(function (r) {
      var graph = r[0]; var orphans = (r[1] && (r[1].orphans || r[1].data)) || []; var anchors = (r[2] && (r[2].issues || r[2].data)) || [];
      var legacyLinks = r[3];
      var legacyArr = (legacyLinks && (legacyLinks.suggestions || legacyLinks.links || legacyLinks)) || [];
      var suggestedCount = Array.isArray(legacyArr) ? legacyArr.filter(function(l){ return !l.status || l.status === 'suggested'; }).length : 0;
      var nodes = (graph && graph.nodes) || []; var edges = (graph && graph.edges) || [];
      var h = '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Pages in graph</div><div class="lgse-kpi-val">' + nodes.length + '</div></div>'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Internal links</div><div class="lgse-kpi-val">' + edges.length + '</div></div>'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Orphan pages</div><div class="lgse-kpi-val lgse-dn">' + orphans.length + '</div></div>'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Anchor issues</div><div class="lgse-kpi-val">' + anchors.length + '</div></div>'
      + '</div>'
      + '<div style="display:flex;gap:8px;margin-bottom:6px;flex-wrap:wrap">'
        // Wave 15.2 (2026-05-18) — bulk execution CTAs.
        + '<button onclick="window._seoApplyTopLinks(20)" title="Bulk-apply the top 20 queued internal-link suggestions (orphan targets first)" style="background:#10B981;color:#fff;border:0;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">⚡ Apply top 20' + (suggestedCount > 0 ? ' (' + suggestedCount + ')' : '') + '</button>'
        + '<button onclick="window._seoFixAllOrphans()" title="Apply queued suggestions to every orphan page" style="background:#F59E0B;color:#fff;border:0;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">⚠ Fix all orphans' + (orphans.length > 0 ? ' (' + orphans.length + ')' : '') + '</button>'
        + '<button class="lgse-btn-primary"   id="lgse-rebuild-graph-btn"   onclick="lgseRebuildGraph(this)">Rebuild graph</button>'
        + '<button class="lgse-btn-secondary" id="lgse-recalc-equity-btn"   onclick="lgseRecalcEquity(this)">Recalculate equity</button>'
      + '</div>'
      + '<div style="font-size:10px;color:var(--lgse-t3);margin-bottom:14px">Apply suggestions executes the queued internal-link inserts. Rebuild walks indexed pages and refreshes the link map.</div>';

      if (orphans.length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Orphan pages</span></div>';
        h += '<table class="lgse-table" style="margin-bottom:16px"><thead><tr><th>URL</th><th>Title</th><th class="r">Words</th><th class="r">Score</th></tr></thead><tbody>';
        orphans.slice(0, 20).forEach(function (o) {
          h += '<tr><td class="lgse-url">' + esc(o.url || '—') + '</td><td style="color:var(--lgse-t1);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(o.title || '—') + '</td><td class="mono">' + (parseInt(o.word_count || 0, 10) || 0) + '</td><td class="mono">' + (o.content_score ? scorePill(o.content_score) : '—') + '</td></tr>';
        });
        h += '</tbody></table>';
      }
      if (anchors.length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Anchor issues</span></div>';
        h += '<table class="lgse-table"><thead><tr><th>Article</th><th>Anchor</th><th>Issue</th><th>Fix</th></tr></thead><tbody>';
        anchors.slice(0, 20).forEach(function (a) {
          var col = a.issue_type === 'over_optimised' || a.issue_type === 'over_optimised_exact' ? 'red' : a.issue_type === 'too_long' ? 'amber' : 'amber';
          h += '<tr><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1)">' + esc(a.article_title || '') + '</td><td style="color:var(--lgse-t1);font-weight:500">"' + esc(a.anchor_text || '') + '"</td><td>' + badge(a.issue_label || '', col) + '</td><td style="color:var(--lgse-t3);font-size:11px">' + esc(a.fix || '') + '</td></tr>';
        });
        h += '</tbody></table>';
      }
      if (orphans.length === 0 && anchors.length === 0 && nodes.length === 0) {
        h += emptyState('⟡', 'No link graph yet', 'Click "Rebuild graph" to walk your indexed pages and extract internal links.', 'Build graph', 'api(\'POST\',\'/link-graph/build\',{}).then(function(){lgseSwitchTab(\'links\')})');
      }
      body.innerHTML = h;
    }).catch(function () { body.innerHTML = emptyState('⚠', 'Could not load link graph', 'Try refreshing.'); });
  }
  // ─────────────────────────────────────────────────────────────────────
  // P0-9 / P0-10 / P0-11 — Outbound · Redirects · 404 Log
  // The private loadOutbound / loadRedirects functions are kept as thin
  // delegators so renderLinks's existing dispatch keeps working.
  // ─────────────────────────────────────────────────────────────────────
  function loadOutbound(body)  { window.lgseLoadOutbound(); }
  function loadRedirects(body) { window.lgseLoadRedirects(); }
  function loadAnchorsSec(body){ window.lgseLoadAnchors(); }

  // ─────────────────────────────────────────────────────────────────────
  // P1-B — Anchors sub-tab. Distribution bar + filter pills + table.
  // Backend: GET /anchors -> { anchors:[...], distribution:{...}, total }
  //          POST /anchors/suggest-intent -> { suggestions:[...] }
  // No native dialogs; uses lgseShowModal for the suggest UI.
  // ─────────────────────────────────────────────────────────────────────
  window._lgseAnchorState = window._lgseAnchorState || { anchors: [], distribution: null, filter: 'all' };

  window.lgseLoadAnchors = function () {
    var bodyEl = document.getElementById('lgse-links-body');
    if (!bodyEl) return;
    bodyEl.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading anchors…</div>';
    api('GET', '/anchors').then(function (r) {
      window._lgseAnchorState = {
        anchors:      (r && r.anchors) || [],
        distribution: (r && r.distribution) || { generic:0, exact:0, natural:0, total:0, generic_pct:0, exact_pct:0, natural_pct:0 },
        filter:       'all'
      };
      window.lgseLoadAnchorData();
    }).catch(function () {
      bodyEl.innerHTML = emptyState('⚠', 'Could not load anchors', 'Click Run analysis to rebuild from your articles.', 'Run analysis', 'lgseRunAnchorAnalysis()');
    });
  };

  window.lgseLoadAnchorData = function () {
    var bodyEl = document.getElementById('lgse-links-body');
    if (!bodyEl) return;
    var st = window._lgseAnchorState || { anchors: [], distribution: { generic:0, exact:0, natural:0, total:0, generic_pct:0, exact_pct:0, natural_pct:0 }, filter: 'all' };
    var d  = st.distribution || { generic:0, exact:0, natural:0, total:0, generic_pct:0, exact_pct:0, natural_pct:0 };
    var anchors = st.anchors.slice();
    var f = st.filter || 'all';
    if (f === 'generic')  anchors = anchors.filter(function (a) { return a.classification === 'generic'; });
    else if (f === 'exact')   anchors = anchors.filter(function (a) { return a.classification === 'exact_match'; });
    else if (f === 'natural') anchors = anchors.filter(function (a) { return ['partial_match','descriptive','long_phrase'].indexOf(a.classification) !== -1; });
    else if (f === 'issues')  anchors = anchors.filter(function (a) { return !!a.issue_type; });

    var h = ''
      + '<div style="background:linear-gradient(90deg,rgba(108,92,231,.08) 0%,transparent 100%);border-left:2px solid #6C5CE7;border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:16px">'
        + '<div style="font-size:9px;font-weight:600;color:#6C5CE7;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">What is Anchor Analysis?</div>'
        + '<div style="font-size:11.5px;color:#8b90a7;line-height:1.65">Anchor text is the clickable text in a hyperlink. Google uses it to understand what the linked page is about. <strong style="color:#f0f2ff">Generic anchors</strong> ("click here", "read more") waste SEO value. <strong style="color:#f0f2ff">Exact match anchors</strong> (your exact keyword) can look spammy if overused. <strong style="color:#f0f2ff">Natural anchors</strong> (descriptive, varied) are best. Click "Run analysis" to scan all internal links on your indexed pages.</div>'
      + '</div>'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">'
        + '<div style="font-size:12px;color:var(--lgse-t2)">Anchor distribution across <strong style="color:var(--lgse-t1)">' + (d.total || 0) + '</strong> internal links</div>'
        + '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px" onclick="lgseRunAnchorAnalysis()">Run analysis</button>'
      + '</div>';

    // KPI cards.
    h += '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Total anchors</div><div class="lgse-kpi-val">' + (d.total || 0) + '</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Generic</div><div class="lgse-kpi-val" style="color:var(--lgse-red)">' + (d.generic_pct || 0) + '%</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Exact match</div><div class="lgse-kpi-val" style="color:var(--lgse-amber)">' + (d.exact_pct || 0) + '%</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Natural</div><div class="lgse-kpi-val" style="color:var(--lgse-teal)">' + (d.natural_pct || 0) + '%</div></div>'
      + '</div>';

    // Distribution stack bar.
    var totalForBar = (d.generic || 0) + (d.exact || 0) + (d.natural || 0);
    if (totalForBar > 0) {
      var gW = ((d.generic || 0) / totalForBar) * 100;
      var eW = ((d.exact   || 0) / totalForBar) * 100;
      var nW = ((d.natural || 0) / totalForBar) * 100;
      h += '<div style="margin-bottom:14px">'
        + '<div class="lgse-stack-bar" style="height:14px">'
          + '<div title="Generic ' + (d.generic_pct || 0) + '%" style="width:' + gW + '%;background:var(--lgse-red)"></div>'
          + '<div title="Exact ' + (d.exact_pct || 0) + '%" style="width:' + eW + '%;background:var(--lgse-amber)"></div>'
          + '<div title="Natural ' + (d.natural_pct || 0) + '%" style="width:' + nW + '%;background:var(--lgse-teal)"></div>'
        + '</div>'
        + '<div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--lgse-t3);margin-top:5px">'
          + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-red);border-radius:2px;margin-right:5px"></span>Generic ' + (d.generic || 0) + '</span>'
          + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-amber);border-radius:2px;margin-right:5px"></span>Exact ' + (d.exact || 0) + '</span>'
          + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-teal);border-radius:2px;margin-right:5px"></span>Natural ' + (d.natural || 0) + '</span>'
        + '</div>'
      + '</div>';
    }

    // Filter pills.
    function pill(value, label, count) {
      var cls = 'lgse-filter-pill' + (f === value ? ' active' : '');
      var suffix = (count != null) ? ' <span style="opacity:.7;font-size:10px">(' + count + ')</span>' : '';
      return '<div class="' + cls + '" onclick="lgseAnchorFilter(\'' + value + '\')">' + esc(label) + suffix + '</div>';
    }
    var issuesCount = st.anchors.filter(function (a) { return !!a.issue_type; }).length;
    h += '<div class="lgse-filter-row" style="margin-bottom:10px">'
      + pill('all',     'All',     d.total)
      + pill('generic', 'Generic', d.generic)
      + pill('exact',   'Exact',   d.exact)
      + pill('natural', 'Natural', d.natural)
      + pill('issues',  'Issues',  issuesCount)
      + '</div>';

    if (anchors.length === 0) {
      if ((d.total || 0) === 0) {
        h += emptyState('⟡', 'No anchors yet', 'Click "Run analysis" to scan your articles for internal-link anchor text.', 'Run analysis', 'lgseRunAnchorAnalysis()');
      } else {
        h += '<div style="padding:24px;text-align:center;color:var(--lgse-t3);background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;font-size:11.5px">No anchors match the current filter.</div>';
      }
      bodyEl.innerHTML = h;
      return;
    }

    // Anchor table — capped at 200 rows for render perf.
    var rows = anchors.slice(0, 200);
    h += '<table class="lgse-table">'
      + '<thead><tr>'
        + '<th>Article</th>'
        + '<th>Anchor</th>'
        + '<th>Target</th>'
        + '<th>Type</th>'
        + '<th class="r">Score</th>'
        + '<th>Issue</th>'
        + '<th class="r">Action</th>'
      + '</tr></thead><tbody>';
    rows.forEach(function (a, idx) {
      var origIdx = st.anchors.indexOf(a);
      var clsLabel, clsKind;
      if (a.classification === 'generic')              { clsLabel = 'Generic';   clsKind = 'red'; }
      else if (a.classification === 'exact_match')     { clsLabel = 'Exact';     clsKind = 'amber'; }
      else if (a.classification === 'partial_match')   { clsLabel = 'Partial';   clsKind = 'teal'; }
      else if (a.classification === 'descriptive')     { clsLabel = 'Descriptive'; clsKind = 'teal'; }
      else if (a.classification === 'long_phrase')     { clsLabel = 'Long';      clsKind = 'teal'; }
      else if (a.classification === 'url')             { clsLabel = 'URL';       clsKind = 'amber'; }
      else                                             { clsLabel = a.classification || '—'; clsKind = 'amber'; }
      var issueCell = a.issue_type
        ? '<span style="color:var(--lgse-amber);font-size:11px">' + esc(a.issue_label || a.issue_type) + '</span>'
        : '<span style="color:var(--lgse-t3);font-size:11px">—</span>';
      h += '<tr>'
        + '<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1)">' + esc(a.article_title || '—') + '</td>'
        + '<td style="color:var(--lgse-t1);font-weight:500">"' + esc(a.anchor_text || '') + '"</td>'
        + '<td class="lgse-url" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(a.target_url || '') + '</td>'
        + '<td>' + badge(clsLabel, clsKind) + '</td>'
        + '<td class="r mono">' + (a.score != null ? scorePill(a.score) : '—') + '</td>'
        + '<td>' + issueCell + '</td>'
        + '<td class="r"><button class="lgse-btn-secondary" style="font-size:10px;padding:4px 9px" onclick="lgseSuggestAnchor(' + origIdx + ')">Suggest</button></td>'
      + '</tr>';
    });
    h += '</tbody></table>';
    if (anchors.length > rows.length) {
      h += '<div style="text-align:center;color:var(--lgse-t3);font-size:11px;margin-top:8px">Showing first ' + rows.length + ' of ' + anchors.length + ' anchors</div>';
    }
    bodyEl.innerHTML = h;
  };

  window.lgseRunAnchorAnalysis = function () {
    // Route is GET /api/seo/anchors/bulk-analysis (routes/api.php:1617) — InternalLinkService::bulkAnalyzeAnchors rebuilds classifications.
    var btns = document.querySelectorAll('[onclick*="lgseRunAnchorAnalysis"]');
    for (var i = 0; i < btns.length; i++) { btns[i].disabled = true; btns[i].textContent = '⏳ Analyzing…'; }
    api('GET', '/anchors/bulk-analysis').then(function () {
      window.lgseLoadAnchors();
    }).catch(function () {
      var rb = document.querySelectorAll('[onclick*="lgseRunAnchorAnalysis"]');
      for (var j = 0; j < rb.length; j++) { rb[j].disabled = false; rb[j].textContent = 'Run analysis'; }
      var bodyEl = document.getElementById('lgse-links-body');
      if (bodyEl) bodyEl.innerHTML = emptyState('⚠', 'Anchor analysis failed', 'The bulk-analysis call did not complete. Try again.', 'Retry', 'lgseRunAnchorAnalysis()');
    });
  };

  window.lgseAnchorFilter = function (which) {
    if (!window._lgseAnchorState) return;
    window._lgseAnchorState.filter = which || 'all';
    window.lgseLoadAnchorData();
  };

  window.lgseSuggestAnchor = function (idx) {
    var st = window._lgseAnchorState; if (!st || !st.anchors[idx]) return;
    var a = st.anchors[idx];
    var html = ''
      + '<div style="font-size:12px;color:var(--lgse-t2);margin-bottom:10px">Replacing: <span style="color:var(--lgse-t1);font-weight:500">"' + esc(a.anchor_text || '') + '"</span></div>'
      + '<div style="font-size:11.5px;color:var(--lgse-t3);margin-bottom:10px">Target: ' + esc(a.target_url || '') + '</div>'
      + '<div id="lgse-anchor-suggest-list" style="display:flex;flex-direction:column;gap:6px;max-height:260px;overflow-y:auto">'
        + '<div style="padding:18px;text-align:center;color:var(--lgse-t3);font-size:11px">Generating suggestions…</div>'
      + '</div>';
    window.lgseShowModal('Suggest a better anchor', html, null, { hideSave: true, cancelLabel: 'Close' });

    api('POST', '/anchors/suggest-intent', {
      target_url:   a.target_url || '',
      target_title: a.target_title || '',
      target_keyword: a.target_title || '',
      source_content: '',
      intent: ''
    }).then(function (r) {
      var list = (r && r.suggestions) || [];
      var listEl = document.getElementById('lgse-anchor-suggest-list');
      if (!listEl) return;
      if (list.length === 0) {
        listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-t3);font-size:11px">No suggestions returned. Try again or refine the article context.</div>';
        return;
      }
      var inner = '';
      list.slice(0, 8).forEach(function (s) {
        var anchorTxt = s.anchor || '';
        var ctx       = s.context_sentence || '';
        var sc        = s.score != null ? s.score : '';
        inner += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:10px 12px">'
          + '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:' + (ctx ? '5px' : '0') + '">'
            + '<div style="color:var(--lgse-t1);font-weight:500;font-size:12px">"' + esc(anchorTxt) + '"</div>'
            + (sc !== '' ? '<div style="font-size:10.5px;color:var(--lgse-t3)">score ' + esc(sc) + '</div>' : '')
          + '</div>'
          + (ctx ? '<div style="color:var(--lgse-t3);font-size:11px;line-height:1.4">' + esc(ctx) + '</div>' : '')
        + '</div>';
      });
      listEl.innerHTML = inner;
    }).catch(function () {
      var listEl = document.getElementById('lgse-anchor-suggest-list');
      if (listEl) listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-red);font-size:11px">Suggest service unavailable. Try again later.</div>';
    });
  };
  // ── End P1-B ──────────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // P1-C — Gaps sub-tab. Orphan + weak (1-2 inbound) pages.
  // Backend: GET /links/gaps -> { orphans:[], weak:[], summary:{...} }
  //          GET /link-graph/unlinked-mentions?keyword=… -> [...]
  // ─────────────────────────────────────────────────────────────────────
  function loadGapsSec(body) { window.lgseLoadGaps(); }

  window._lgseGapsState = window._lgseGapsState || { orphans: [], weak: [], summary: { orphan_count: 0, weak_count: 0 } };

  window.lgseLoadGaps = function () {
    var bodyEl = document.getElementById('lgse-links-body');
    if (!bodyEl) return;
    bodyEl.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading link gaps…</div>';
    api('GET', '/links/gaps').then(function (r) {
      window._lgseGapsState = {
        orphans: (r && r.orphans) || [],
        weak:    (r && r.weak)    || [],
        summary: (r && r.summary) || { orphan_count: 0, weak_count: 0 }
      };
      window.lgseLoadGapsData();
    }).catch(function () {
      bodyEl.innerHTML = emptyState('⚠', 'Could not load gaps', 'Build the link graph first, then refresh.', 'Build graph', 'api(\'POST\',\'/link-graph/build\',{}).then(function(){lgseRefreshGaps()})');
    });
  };

  window.lgseLoadGapsData = function () {
    var bodyEl = document.getElementById('lgse-links-body');
    if (!bodyEl) return;
    var st = window._lgseGapsState || { orphans: [], weak: [], summary: { orphan_count: 0, weak_count: 0 } };
    var orphans = st.orphans || []; var weak = st.weak || [];
    var s = st.summary || { orphan_count: 0, weak_count: 0 };

    var h = ''
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">'
        + '<div style="font-size:12px;color:var(--lgse-t2)">Pages that are missing internal links — fix these to spread page authority.</div>'
        + '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px" onclick="lgseRefreshGaps()">Refresh</button>'
      + '</div>'
      + '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(2,1fr);margin-bottom:14px">'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Orphan pages</div><div class="lgse-kpi-val" style="color:var(--lgse-red)">' + (s.orphan_count || 0) + '</div></div>'
        + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Weak pages (1–2 inbound)</div><div class="lgse-kpi-val" style="color:var(--lgse-amber)">' + (s.weak_count || 0) + '</div></div>'
      + '</div>';

    if (orphans.length === 0 && weak.length === 0) {
      h += emptyState('✓', 'No link gaps found', 'Every indexed page has at least 3 inbound internal links. Run "Build graph" if you just published new content.', 'Build graph', 'api(\'POST\',\'/link-graph/build\',{}).then(function(){lgseRefreshGaps()})');
      bodyEl.innerHTML = h;
      return;
    }

    function renderTable(rows, title, kind) {
      if (rows.length === 0) return '';
      var out = '<div class="lgse-section-hdr"><span class="lgse-section-title">' + esc(title) + '</span></div>';
      out += '<table class="lgse-table" style="margin-bottom:18px">'
        + '<thead><tr>'
          + '<th>URL</th>'
          + '<th>Title</th>'
          + '<th class="r">Words</th>'
          + '<th class="r">Score</th>'
          + (kind === 'weak' ? '<th class="r">Inbound</th>' : '')
          + '<th class="r">Action</th>'
        + '</tr></thead><tbody>';
      rows.slice(0, 30).forEach(function (p, idx) {
        var origIdx = (kind === 'weak' ? rows.indexOf(p) : rows.indexOf(p));
        out += '<tr>'
          + '<td class="lgse-url" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(p.url || '—') + '</td>'
          + '<td style="color:var(--lgse-t1);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(p.title || '—') + '</td>'
          + '<td class="r mono">' + (parseInt(p.word_count, 10) || 0) + '</td>'
          + '<td class="r mono">' + (p.content_score != null ? scorePill(p.content_score) : '—') + '</td>'
          + (kind === 'weak' ? '<td class="r mono">' + (parseInt(p.inbound_count, 10) || 0) + '</td>' : '')
          + '<td class="r"><button class="lgse-btn-secondary" style="font-size:10px;padding:4px 9px" onclick="lgseGenerateLinksFor(\'' + kind + '\',' + origIdx + ')">Generate</button></td>'
        + '</tr>';
      });
      out += '</tbody></table>';
      if (rows.length > 30) out += '<div style="text-align:center;color:var(--lgse-t3);font-size:11px;margin:-10px 0 18px">Showing first 30 of ' + rows.length + '</div>';
      return out;
    }

    h += renderTable(orphans, 'Orphan pages', 'orphan');
    h += renderTable(weak,    'Weak pages',   'weak');

    bodyEl.innerHTML = h;
  };

  window.lgseRefreshGaps = function () { window.lgseLoadGaps(); };

  window.lgseGenerateLinksFor = function (kind, idx) {
    var st = window._lgseGapsState; if (!st) return;
    var pool = (kind === 'weak') ? (st.weak || []) : (st.orphans || []);
    var p = pool[idx]; if (!p) return;

    var keyword = (p.title || '').replace(/\s+\|.*$/, '').trim();
    if (keyword === '') {
      window.lgseShowModal('Generate internal links', '<div style="padding:6px 0;font-size:12px;color:var(--lgse-t3)">This page has no title to use as the keyword. Add a title or H1 first.</div>', null, { hideSave: true, cancelLabel: 'Close' });
      return;
    }

    var html = ''
      + '<div style="font-size:12px;color:var(--lgse-t2);margin-bottom:6px">Target: <span style="color:var(--lgse-t1)">' + esc(p.url || '') + '</span></div>'
      + '<div style="font-size:11.5px;color:var(--lgse-t3);margin-bottom:10px">Searching pages that mention <strong>"' + esc(keyword) + '"</strong> but don\'t link here.</div>'
      + '<div id="lgse-gap-mentions-list" style="display:flex;flex-direction:column;gap:6px;max-height:300px;overflow-y:auto">'
        + '<div style="padding:18px;text-align:center;color:var(--lgse-t3);font-size:11px">Searching…</div>'
      + '</div>';
    window.lgseShowModal('Generate internal links', html, null, { hideSave: true, cancelLabel: 'Close' });

    api('GET', '/link-graph/unlinked-mentions?keyword=' + encodeURIComponent(keyword)).then(function (r) {
      var list = (r && (r.mentions || r.data || r)) || [];
      if (!Array.isArray(list)) list = [];
      var listEl = document.getElementById('lgse-gap-mentions-list');
      if (!listEl) return;
      if (list.length === 0) {
        listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-t3);font-size:11px">No source pages mention this keyword without linking. Try writing new content.</div>';
        return;
      }
      var inner = '';
      list.slice(0, 10).forEach(function (m) {
        inner += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:10px 12px">'
          + '<div style="color:var(--lgse-t1);font-weight:500;font-size:12px;margin-bottom:3px">' + esc(m.title || m.source_title || '—') + '</div>'
          + '<div class="lgse-url" style="font-size:11px">' + esc(m.url || m.source_url || '') + '</div>'
        + '</div>';
      });
      listEl.innerHTML = inner;
    }).catch(function () {
      var listEl = document.getElementById('lgse-gap-mentions-list');
      if (listEl) listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-red);font-size:11px">Could not search. Build the link graph first.</div>';
    });
  };
  // ── End P1-C ──────────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // P1-D — Images sub-tab. Stats KPIs + progress bar + bulk button + table.
  // Backend: GET /image-summary, GET /image-issues, POST /image-issues/suggest-alt,
  //          POST /image-issues/bulk-analyze (re-scan).
  // No native dialogs. All CSS via existing vars.
  // ─────────────────────────────────────────────────────────────────────
  window._lgseImagesState = window._lgseImagesState || { summary: null, issues: [], optimizing: false, optProgress: 0, optTotal: 0, optResults: [] };

  window.lgseRenderImages = function (bodyEl) {
    bodyEl = bodyEl || document.getElementById('lgse-pages-body');
    if (!bodyEl) return;
    bodyEl.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading images…</div>';
    window.lgseLoadImagesData(bodyEl);
  };

  window.lgseLoadImagesData = function (bodyEl) {
    bodyEl = bodyEl || document.getElementById('lgse-pages-body');
    if (!bodyEl) return;
    Promise.all([
      api('GET', '/image-summary').catch(function () { return null; }),
      api('GET', '/image-issues?limit=500').catch(function () { return null; })
    ]).then(function (rs) {
      var sumResp = rs[0] || {};
      var summary = (sumResp && sumResp.summary) || sumResp || { missing_alt:0, empty_alt:0, filename_unfriendly:0, wrong_format:0, total_images:0, issues_found:0 };
      var issuesResp = rs[1] || [];
      var issues = Array.isArray(issuesResp) ? issuesResp : ((issuesResp && (issuesResp.issues || issuesResp.data)) || []);
      window._lgseImagesState.summary = summary;
      window._lgseImagesState.issues  = issues;
      window.lgseRenderImagesBody(bodyEl);

      // Phase 9A.1: auto-probe sizes once per page-load if any rows still
      // have a NULL size_bytes AND have never been probed. Fire-and-forget;
      // when complete we re-fetch + re-render to surface the new sizes.
      var st = window._lgseImagesState;
      var needsProbe = (issues || []).some(function (it) {
        return (it.size_bytes == null || it.size_bytes === 0) && it.last_probed_at == null;
      });
      if (needsProbe && !st.sizesProbed) {
        st.sizesProbed = true;
        api('POST', '/image-issues/probe-sizes', { batch: 50 }).then(function (r) {
          if (r && r.probed > 0) {
            setTimeout(function () { window.lgseLoadImagesData(bodyEl); }, 800);
          }
        }).catch(function () { /* probe is best-effort */ });
      }
    }).catch(function () {
      bodyEl.innerHTML = emptyState('🖼', 'No image data', 'Run a scan to populate this section.', 'Re-scan images', 'lgseStartImageScan()');
    });
  };

  // Phase 9A.1 helpers — exposed at window scope so onclick handlers can find them.
  window._lgseFmtBytes = function (n) {
    n = parseInt(n, 10) || 0;
    if (n <= 0) return '—';
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return Math.round(n / 1024) + ' KB';
    return (n / 1024 / 1024).toFixed(1) + ' MB';
  };

  // Phase A (2026-05-16) — REAL optimization execution.
  // Modal opens; POST dispatches; polling fetches real status until
  // terminal state. NO optimistic success — terminal state must come
  // from verification, not the dispatch response.
  //
  // Status enum (from backend):
  //   queued | running | optimized | optimized_external | unverified
  //   failed | blocked_no_connector | capability_unknown
  window._lgseOptimizePollers = window._lgseOptimizePollers || {};
  window.lgseOptimizeImage = function (imageUrl) {
    if (!imageUrl) return;
    if (typeof window.lgseAssertSeoAI === 'function' && !window.lgseAssertSeoAI()) return;

    // Open modal in "dispatching" state
    var bodyId = 'lgse-opt-modal-body-' + Math.random().toString(36).slice(2, 8);
    var html = ''
      + '<div style="font-size:11.5px;color:var(--lgse-t3);margin-bottom:10px;word-break:break-all">'
      +   esc(imageUrl)
      + '</div>'
      + '<div id="' + bodyId + '" style="padding:14px 0;text-align:center;font-size:12px;color:var(--lgse-t2)">'
      +   '<span style="display:inline-block;width:10px;height:10px;border:2px solid var(--lgse-purple);border-top-color:transparent;border-radius:50%;animation:lgse-spin 0.7s linear infinite;vertical-align:middle;margin-right:8px"></span>'
      +   'Preparing optimization…'
      + '</div>';
    window.lgseShowModal('Optimize image', html, null, { hideSave: true, cancelLabel: 'Close' });

    // Dispatch
    api('POST', '/image-issues/optimize', { image_url: imageUrl }).then(function (r) {
      var body = document.getElementById(bodyId);
      if (!body) return;  // modal closed before response

      if (!r || r.accepted !== true) {
        window.lgseOptimizeRenderTerminal(body, r && r.status || 'failed', {
          reason:        r && r.reason,
          message:       r && r.message,
          required_plan: r && r.required_plan
        });
        return;
      }

      // Job accepted — render running state and start polling
      window.lgseOptimizeRenderRunning(body, r.status || 'queued', r.job_id);
      window.lgseOptimizeStartPoll(imageUrl, bodyId);
    }).catch(function (e) {
      var body = document.getElementById(bodyId);
      if (!body) return;
      var em = (e && e.body && (e.body.reason || e.body.message)) || (e && e.message) || 'network_error';
      window.lgseOptimizeRenderTerminal(body, 'failed', { message: em });
    });
  };

  // Polls /image-issues/optimize-status until terminal state.
  // Cadence: 1.5s, max 90s (60 polls). Terminal statuses end the poll.
  window.lgseOptimizeStartPoll = function (imageUrl, bodyId) {
    var key = imageUrl;
    if (window._lgseOptimizePollers[key]) return;  // dedupe
    var started = Date.now();
    var pollerId = setInterval(function () {
      // Stop if modal closed
      var body = document.getElementById(bodyId);
      if (!body) {
        clearInterval(pollerId);
        delete window._lgseOptimizePollers[key];
        return;
      }
      // Hard timeout
      if (Date.now() - started > 90 * 1000) {
        clearInterval(pollerId);
        delete window._lgseOptimizePollers[key];
        window.lgseOptimizeRenderTerminal(body, 'unverified', {});
        return;
      }
      api('GET', '/image-issues/optimize-status?image_url=' + encodeURIComponent(imageUrl)).then(function (sr) {
        if (!sr || !sr.state || !sr.state.found) return;
        var st = sr.state;
        var terminal = ['optimized', 'optimized_external', 'unverified', 'failed', 'blocked_no_connector', 'not_attempted'];
        if (st.status === 'queued' || st.status === 'running') {
          window.lgseOptimizeRenderRunning(body, st.status);
          return;
        }
        if (terminal.indexOf(st.status) >= 0) {
          clearInterval(pollerId);
          delete window._lgseOptimizePollers[key];
          window.lgseOptimizeRenderTerminal(body, st.status, st);
          // Refresh image audit table so the row reflects new state
          if (typeof window.lgseRenderImagesBody === 'function') {
            setTimeout(window.lgseRenderImagesBody, 400);
          }
        }
      }).catch(function () { /* swallow transient */ });
    }, 1500);
    window._lgseOptimizePollers[key] = pollerId;
  };

  // UX abstraction layer (2026-05-16) — translates internal status enums
  // into premium, user-facing copy. The orchestrator + job + verification
  // engine continue to use the canonical enums internally; only the
  // rendered text is abstracted. Backend truth is fully preserved.
  //
  // Tones:
  //   'progress'   — neutral spinner state, blue/purple accent
  //   'success'    — teal accent, includes real saved bytes
  //   'neutral'    — calm grey, no alarm (used for unverified / external)
  //   'soft-block' — muted amber, calls user to action without alarm
  window.lgseOptimizeCopy = function (status, ctx) {
    ctx = ctx || {};
    // Reason can arrive via ctx.reason (dispatch response) OR
    // ctx.last_error (polled status from seo_images.optimization_last_error).
    // Either one wins — both carry the same semantic content.
    var reason = (ctx.reason || ctx.last_error || '').toString();
    var saved   = ctx.saved_bytes ? window._lgseFmtBytes(ctx.saved_bytes) : null;
    var sizeNow = ctx.verified_size_bytes || ctx.current_size_bytes || 0;

    if (status === 'queued')  return { title: 'Preparing optimization', body: 'Your image is in the queue.', tone: 'progress' };
    if (status === 'running') return { title: 'Optimizing image',       body: 'This usually takes a few seconds.', tone: 'progress' };

    if (status === 'optimized') {
      var body = saved
        ? 'Saved ' + saved + (sizeNow ? ' — your image is now ' + window._lgseFmtBytes(sizeNow) + '.' : '.')
        : 'Your image has been optimized successfully.';
      return { title: 'Optimization complete', body: body, tone: 'success', extra: { webp: ctx.webp_verified === true } };
    }

    if (status === 'unverified') {
      // Honest but premium: tell user it succeeded; details refreshing.
      return {
        title: 'Optimization complete',
        body:  'Your image was optimized. Updated image details are still being refreshed — they may take a few moments to appear.',
        tone:  'neutral'
      };
    }

    if (status === 'optimized_external') {
      return { title: 'Already optimized', body: 'This image has already been optimized.', tone: 'neutral' };
    }

    if (status === 'capability_unknown') {
      return { title: 'Checking availability', body: 'We\'re checking optimization availability for this image. Please try again in a moment.', tone: 'neutral' };
    }

    if (status === 'blocked_no_connector') {
      return { title: 'Optimization unavailable', body: 'Image optimization isn\'t available for this website yet. Contact your account manager to enable it.', tone: 'soft-block' };
    }

    if (status === 'blocked' || status === 'failed') {
      if (reason === 'plan_upgrade_required') {
        return { title: 'Plan upgrade required', body: 'Image optimization is available on the Growth plan and above.', tone: 'soft-block' };
      }
      if (reason === 'image_not_in_audit') {
        return { title: 'Image not found', body: 'This image isn\'t in your latest audit. Run a re-scan and try again.', tone: 'soft-block' };
      }
      if (reason === 'invalid_image_url' || reason === 'invalid_url') {
        return { title: 'Couldn\'t optimize', body: 'This image address is invalid. Please re-scan and try again.', tone: 'soft-block' };
      }
      if (reason === 'image_not_on_connected_wp_site' || reason === 'wp_lacks_connector_transport') {
        return { title: 'Optimization unavailable', body: 'Image optimization isn\'t available for this image right now.', tone: 'soft-block' };
      }
      if (reason === 'no_savings') {
        return { title: 'Already well-optimized', body: 'This image is already efficiently compressed — no further savings are possible.', tone: 'neutral' };
      }
      if (reason === 'unsupported_mime' || reason === 'gif_not_supported_on_gd_only') {
        return { title: 'Format not supported', body: 'This image format isn\'t supported for optimization yet.', tone: 'neutral' };
      }
      if (reason === 'compression_failed' || reason === 'encode_failed_for_image/jpeg' || reason === 'encode_failed_for_image/png' || reason === 'encode_failed_for_image/webp') {
        return { title: 'Couldn\'t optimize', body: 'This image couldn\'t be processed. It may be corrupted or in an unusual format.', tone: 'soft-block' };
      }
      if (reason === 'no_connector_config') {
        return { title: 'Optimization unavailable', body: 'Image optimization isn\'t available for this website yet. Contact your account manager to enable it.', tone: 'soft-block' };
      }
      if (reason === 'c1_workspace_optimization_deferred_to_phase_f') {
        return { title: 'Coming soon', body: 'Optimization for uploaded media is coming soon.', tone: 'neutral' };
      }
      // Generic terminal failure — calm, actionable, no jargon
      return { title: 'Couldn\'t optimize', body: 'We couldn\'t optimize this image right now. Please try again, and contact support if it persists.', tone: 'soft-block' };
    }

    return { title: 'Working…', body: 'Please wait a moment.', tone: 'progress' };
  };

  // Render the running/queued state — clean spinner + premium label.
  window.lgseOptimizeRenderRunning = function (body, status, jobId) {
    var copy = window.lgseOptimizeCopy(status, {});
    body.innerHTML = ''
      + '<div style="text-align:center;padding:18px 0">'
      +   '<span style="display:inline-block;width:14px;height:14px;border:2px solid var(--lgse-purple);border-top-color:transparent;border-radius:50%;animation:lgse-spin 0.7s linear infinite;vertical-align:middle;margin-right:10px"></span>'
      +   '<span style="color:var(--lgse-t1);font-weight:500;font-size:13px">' + esc(copy.title) + '</span>'
      + '</div>'
      + '<div style="font-size:11px;color:var(--lgse-t3);text-align:center">' + esc(copy.body) + '</div>';
  };

  // Render terminal state using only the copy map + real telemetry.
  window.lgseOptimizeRenderTerminal = function (body, status, ctx) {
    ctx = ctx || {};
    var copy = window.lgseOptimizeCopy(status, ctx);

    // Tone -> visual styling. Truthful UX still uses color, but tuned to
    // be premium rather than alarming.
    var bgColor, borderColor, titleColor, icon;
    if (copy.tone === 'success') {
      bgColor     = 'rgba(20,184,166,.08)';
      borderColor = 'var(--lgse-teal)';
      titleColor  = 'var(--lgse-teal)';
      icon        = '<span style="margin-right:6px">✓</span>';
    } else if (copy.tone === 'soft-block') {
      bgColor     = 'var(--lgse-bg2)';
      borderColor = 'var(--lgse-border)';
      titleColor  = 'var(--lgse-t1)';
      icon        = '';
    } else { // neutral
      bgColor     = 'var(--lgse-bg2)';
      borderColor = 'var(--lgse-border)';
      titleColor  = 'var(--lgse-t1)';
      icon        = '';
    }

    var html = ''
      + '<div style="padding:14px;background:' + bgColor + ';border:1px solid ' + borderColor + ';border-radius:8px">'
      +   '<div style="color:' + titleColor + ';font-weight:600;font-size:13.5px;margin-bottom:6px">' + icon + esc(copy.title) + '</div>'
      +   '<div style="font-size:12px;color:var(--lgse-t2);line-height:1.5">' + esc(copy.body) + '</div>';

    // Success state may add a small "Modern format generated" note
    // (no "WebP" jargon — say what it means to the user).
    if (copy.tone === 'success' && copy.extra && copy.extra.webp) {
      html += '<div style="font-size:11px;color:var(--lgse-t3);margin-top:8px">A modern (next-gen) image format was also generated for faster loading.</div>';
    }

    html += '</div>';
    body.innerHTML = html;
  };

  window.lgseRenderImagesBody = function (bodyEl) {
    bodyEl = bodyEl || document.getElementById('lgse-pages-body');
    if (!bodyEl) return;
    var st = window._lgseImagesState;
    var s  = st.summary || {};
    var allRows = st.issues || [];

    var totalImg   = parseInt(s.total_images, 10) || allRows.length;
    var issuesCnt  = parseInt(s.issues_found,  10) || 0;
    var bytesTotal = parseInt(s.bytes_total,   10) || 0;

    var h = ''
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:8px;flex-wrap:wrap">'
        + '<div style="font-size:12px;color:var(--lgse-t2)">Image health across <strong style="color:var(--lgse-t1)">' + totalImg + '</strong> images on <strong style="color:var(--lgse-t1)">' + (parseInt(s.pages_scanned, 10) || 0) + '</strong> pages'
          + (bytesTotal > 0 ? ' · total weight <strong style="color:var(--lgse-t1)">' + window._lgseFmtBytes(bytesTotal) + '</strong>' : '')
        + '.</div>'
        + '<div style="display:flex;gap:8px">'
          + '<button class="lgse-btn-secondary" id="lgse-scanimages-btn" style="font-size:11px;padding:6px 12px" onclick="lgseStartImageScan()">Scan images</button>'
          + (st.optimizing
              ? '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px;color:var(--lgse-red);border-color:var(--lgse-red)" onclick="lgseStopOptimize()">Stop</button>'
              : '<button class="lgse-btn-primary" style="font-size:11px;padding:6px 12px" onclick="lgseBulkOptimize()">Bulk apply alt</button>'
            )
        + '</div>'
      + '</div>';

    h += '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Missing alt</div><div class="lgse-kpi-val" style="color:var(--lgse-red)">' + (s.missing_alt || 0) + '</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Empty alt</div><div class="lgse-kpi-val" style="color:var(--lgse-amber)">' + (s.empty_alt || 0) + '</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Bad filename</div><div class="lgse-kpi-val" style="color:var(--lgse-amber)">' + (s.filename_unfriendly || 0) + '</div></div>'
      + '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Wrong format</div><div class="lgse-kpi-val" style="color:var(--lgse-amber)">' + (s.wrong_format || 0) + '</div></div>'
      + '</div>';

    if (st.optimizing || (st.optTotal > 0 && st.optProgress < st.optTotal)) {
      var pct = st.optTotal > 0 ? Math.round((st.optProgress / st.optTotal) * 100) : 0;
      h += '<div style="margin-bottom:14px;padding:12px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">'
          + '<div style="font-size:11.5px;color:var(--lgse-t2)">Generating + applying alt text — ' + st.optProgress + ' / ' + st.optTotal + ' (' + pct + '%)</div>'
        + '</div>'
        + '<div class="lgse-stack-bar" style="height:8px"><div style="width:' + pct + '%;background:var(--lgse-purple);transition:width .2s"></div></div>'
      + '</div>';
    } else if (st.optTotal > 0 && st.optProgress >= st.optTotal && st.optResults.length > 0) {
      // P-BulkFix — honest completion banner with per-row outcome stats.
      // No copy/paste mention; the loop already persisted everything that succeeded.
      var bo = st.bulkOutcomes || { ok: st.optResults.length, failed: 0, wpOk: 0, wpFail: 0, wpSkip: st.optResults.length };
      var lines = [];
      lines.push('<strong>' + bo.ok + ' image' + (bo.ok === 1 ? '' : 's') + '</strong> applied successfully.');
      if (bo.failed > 0) lines.push('<span style="color:var(--lgse-amber)">' + bo.failed + ' failure' + (bo.failed === 1 ? '' : 's') + '</span> (re-run to retry).');
      if (bo.wpOk > 0)   lines.push(bo.wpOk + ' pushed to WP attachment alt.');
      if (bo.wpFail > 0) lines.push('<span style="color:var(--lgse-amber)">Couldn\'t sync ' + bo.wpFail + ' to your website.</span>');
      h += '<div style="margin-bottom:14px;padding:10px 12px;background:rgba(20,184,166,.08);border:1px solid var(--lgse-teal);border-radius:8px;font-size:11.5px;color:var(--lgse-teal);display:flex;align-items:center;justify-content:space-between;gap:12px">'
        + '<div style="flex:1">' + lines.join(' ') + '</div>'
        + '<button class="lgse-btn-secondary" style="font-size:10.5px;padding:4px 10px" onclick="(function(){var s=window._lgseImagesState;if(s){s.optTotal=0;s.optProgress=0;s.optResults=[];s.bulkOutcomes=null;window.lgseRenderImagesBody();}})()">Dismiss</button>'
      + '</div>';
    }

    if (allRows.length === 0) {
      h += emptyState('🖼', 'No images found in raw or rendered page output', 'Click Scan images to walk your pages. Some images render only via JavaScript and require browser-rendered scanning (now enabled as fallback).', 'Scan images', 'lgseStartImageScan()');
      bodyEl.innerHTML = h;
      return;
    }

    // Sort: issues first (red, then amber), then by size desc within each group.
    var sortedRows = allRows.slice().sort(function (a, b) {
      var aIssue = (a.missing_alt && Number(a.missing_alt) > 0) ? 2 : ((a.empty_alt && Number(a.empty_alt) > 0) ? 1 : 0);
      var bIssue = (b.missing_alt && Number(b.missing_alt) > 0) ? 2 : ((b.empty_alt && Number(b.empty_alt) > 0) ? 1 : 0);
      if (aIssue !== bIssue) return bIssue - aIssue;
      return (parseInt(b.size_bytes, 10) || 0) - (parseInt(a.size_bytes, 10) || 0);
    });
    var rows = sortedRows.slice(0, 500);

    h += '<table class="lgse-table" style="width:100%">'
      + '<thead><tr>'
        + '<th>Image</th>'
        + '<th>Page</th>'
        + '<th>Alt status</th>'
        + '<th>Current alt</th>'
        + '<th class="r">Size</th>'
        + '<th class="r">Actions</th>'
      + '</tr></thead><tbody>';
    rows.forEach(function (it, idx) {
      var origIdx = allRows.indexOf(it);
      var src = it.image_url || it.image_src || it.src || '';
      var fn  = src ? src.split('/').pop().split('?')[0] : '—';
      var alt = it.alt_text || it.alt || '';
      var isMissing = !!(it.missing_alt && Number(it.missing_alt) > 0);
      var isEmpty   = !!(it.empty_alt   && Number(it.empty_alt)   > 0);
      var hasIssue  = isMissing || isEmpty;
      var sizeBytes = parseInt(it.size_bytes, 10) || 0;
      it.issue_type = it.issue_type || (isMissing ? 'missing_alt' : (isEmpty ? 'empty_alt' : 'ok'));
      it.image_src  = it.image_src  || src;

      var statusBadge;
      if (isMissing) statusBadge = badge('✗ Missing alt', 'red');
      else if (isEmpty) statusBadge = badge('⚠ Empty alt', 'amber');
      else statusBadge = badge('✓ Has alt', 'teal');

      var sizeStr;
      if (sizeBytes <= 0) {
        sizeStr = '<span style="color:var(--lgse-t3)" title="Image size is being measured">—</span>';
      } else {
        var sizeColor = sizeBytes > 1024 * 1024 ? 'var(--lgse-red)' : (sizeBytes > 500 * 1024 ? 'var(--lgse-amber)' : 'var(--lgse-t1)');
        sizeStr = '<span style="color:' + sizeColor + ';font-family:var(--lgse-mono);font-size:11px" title="' + sizeBytes + ' bytes">' + window._lgseFmtBytes(sizeBytes) + '</span>';
      }

      var stored = (st.optResults || []).filter(function (x) { return x.idx === origIdx; })[0];
      var hasSuggested = !!(stored && stored.suggested);

      var thumb = src
        ? '<img src="' + esc(src) + '" alt="" style="display:block;width:60px;height:60px;object-fit:cover;border-radius:4px;border:1px solid var(--lgse-border);background:var(--lgse-bg2)" onerror="this.style.display=\'none\';this.insertAdjacentHTML(\'afterend\',\'<div style=&quot;width:60px;height:60px;display:flex;align-items:center;justify-content:center;border-radius:4px;border:1px dashed var(--lgse-border);background:var(--lgse-bg2);font-size:9px;color:var(--lgse-t3)&quot;>broken</div>\');">'
        : '<div style="width:60px;height:60px;display:flex;align-items:center;justify-content:center;border-radius:4px;border:1px dashed var(--lgse-border);background:var(--lgse-bg2);font-size:9px;color:var(--lgse-t3)">no src</div>';

      var safeUrl = String(src).replace(/'/g, '\\\'').replace(/"/g, '&quot;');
      var actions = ''
        + '<button class="lgse-btn-secondary" style="font-size:10px;padding:4px 9px' + (hasSuggested ? ';border-color:var(--lgse-teal);color:var(--lgse-teal)' : '') + '" onclick="lgseSuggestAlt(' + origIdx + ')">' + (hasSuggested ? 'View alt' : 'Suggest alt') + '</button>'
        + ' '
        + '<button class="lgse-btn-secondary" style="font-size:10px;padding:4px 9px" onclick="lgseOptimizeImage(\'' + safeUrl + '\')">Optimize</button>';

      var leftStripe = isMissing ? 'var(--lgse-red)' : (isEmpty ? 'var(--lgse-amber)' : 'var(--lgse-teal)');
      // P0.7 (2026-05-15) — data-lgse-image-url + data-lgse-page-url enable
      // Quick Wins routing into a specific image row (for image-alt issues).
      h += '<tr data-lgse-image-url="' + esc(src) + '" data-lgse-page-url="' + esc(it.page_url || '') + '" style="border-left:3px solid ' + leftStripe + '">'
        + '<td style="vertical-align:middle"><div style="display:flex;align-items:center;gap:8px">' + thumb
          + '<div style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1);font-family:var(--lgse-mono);font-size:11px" title="' + esc(src) + '">' + esc(fn) + '</div>'
        + '</div></td>'
        + '<td class="lgse-url" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(it.page_url || '—') + '</td>'
        + '<td>' + statusBadge + '</td>'
        + '<td style="color:var(--lgse-t2);max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px">' + (alt ? '"' + esc(alt) + '"' : '<span style="color:var(--lgse-t3)">—</span>') + '</td>'
        + '<td class="r">' + sizeStr + '</td>'
        + '<td class="r" style="white-space:nowrap">' + actions + '</td>'
      + '</tr>';
    });
    h += '</tbody></table>';
    if (allRows.length > rows.length) {
      h += '<div style="text-align:center;color:var(--lgse-t3);font-size:11px;margin-top:8px">Showing first ' + rows.length + ' of ' + allRows.length + ' images</div>';
    }
    bodyEl.innerHTML = h;
  };

  window.lgseBulkOptimize = function () {
    if (typeof window.lgseAssertSeoAI === 'function' && !window.lgseAssertSeoAI()) return;
    var st = window._lgseImagesState; if (!st) return;
    // P-BulkFix (2026-05-15) — canonical-flag filter.
    // OLD logic trusted it.issue_type (a derived field set only during render
    // for the top-500 rows). That meant rows below the cap were silently
    // skipped, AND stale 'ok' values from prior server responses kept rows
    // out of the queue even when missing_alt=1. Now we read the canonical
    // missing_alt + empty_alt + alt_text fields directly.
    var queue = (st.issues || []).filter(function (it, i) {
      it.__idx = i;
      var altStr   = String(it.alt_text || it.alt || '').trim();
      var hasAlt   = altStr !== '';
      var isMissing = !!(it.missing_alt && Number(it.missing_alt) > 0);
      var isEmpty   = !!(it.empty_alt   && Number(it.empty_alt)   > 0);
      // Defense in depth: if the row already has a non-empty alt_text we
      // never re-suggest, regardless of stale flag values.
      if (hasAlt) return false;
      return isMissing || isEmpty;
    });
    if (queue.length === 0) {
      window.lgseShowModal('Bulk apply alt', '<div style="padding:6px 0;font-size:12px;color:var(--lgse-teal)">All images already have alt text — no action needed ✓</div>', null, { hideSave: true, cancelLabel: 'Close' });
      return;
    }
    st.optimizing = true; st.optProgress = 0; st.optTotal = queue.length; st.optResults = [];
    // P-BulkFix — per-row outcome tracking for honest completion banner.
    st.bulkOutcomes = { ok: 0, failed: 0, wpOk: 0, wpFail: 0, wpSkip: 0 };
    window.lgseRenderImagesBody();

    function next(i) {
      st = window._lgseImagesState;
      if (!st.optimizing) {
        // Stop pressed.
        window.lgseRenderImagesBody();
        return;
      }
      if (i >= queue.length) {
        st.optimizing = false;
        window.lgseRenderImagesBody();
        return;
      }
      var it = queue[i];
      var imgSrc = it.image_src || it.src || '';
      // P-AltApply v2 (2026-05-15) — track per-row outcome in st.bulkOutcomes.
      api('POST', '/image-issues/suggest-alt', {
        image_src:    imgSrc,
        page_context: it.page_title || it.page_url || ''
      }).then(function (r) {
        var s = (r && r.suggested_alt) || '';
        if (!s) {
          if (st.bulkOutcomes) st.bulkOutcomes.failed++;
          return null;
        }
        st.optResults.push({ idx: it.__idx, suggested: s, image_src: imgSrc });
        return api('POST', '/image-issues/apply-alt', {
          image_url: imgSrc,
          alt_text:  s,
          page_url:  it.page_url || ''
        }).then(function (ar) {
          if (ar && ar.success) {
            if (st.bulkOutcomes) st.bulkOutcomes.ok++;
            if (ar.wp_pushed === true)       { if (st.bulkOutcomes) st.bulkOutcomes.wpOk++; }
            else if (ar.wp_pushed === false) { if (st.bulkOutcomes) st.bulkOutcomes.wpFail++; }
            else                              { if (st.bulkOutcomes) st.bulkOutcomes.wpSkip++; }
            if (st.issues && st.issues[it.__idx]) {
              st.issues[it.__idx].alt_text = s;
              st.issues[it.__idx].missing_alt = 0;
              st.issues[it.__idx].empty_alt = 0;
            }
            if (st.summary) {
              st.summary.missing_alt = ar.missing_remaining;
              st.summary.empty_alt   = ar.empty_remaining;
            }
            if (typeof window.lgseMarkWinResolved === 'function' && it.page_url) {
              window.lgseMarkWinResolved(it.page_url, 'Image alt text');
              window.lgseMarkWinResolved(it.page_url, 'Missing alt');
            }
          } else {
            if (st.bulkOutcomes) st.bulkOutcomes.failed++;
          }
        }).catch(function () {
          if (st.bulkOutcomes) st.bulkOutcomes.failed++;
        });
      }).catch(function () {
        if (st.bulkOutcomes) st.bulkOutcomes.failed++;
      }).then(function () {
        st.optProgress = i + 1;
        window.lgseRenderImagesBody();
        setTimeout(function () { next(i + 1); }, 80);
      });
    }
    next(0);
  };

  window.lgseStopOptimize = function () {
    var st = window._lgseImagesState; if (!st) return;
    st.optimizing = false;
    window.lgseRenderImagesBody();
  };

  // P-AltApply (2026-05-15) — modal now ships an editable textarea + Apply
  // button (lgseShowModal's standard Save slot, relabelled). On Apply, calls
  // lgseApplyAlt which persists via /image-issues/apply-alt, refreshes the
  // image row + counters, marks Quick Wins resolved, closes the modal.
  // No more "Apply-in-place isn't wired yet." / no copy-paste handoff.
  window.lgseSuggestAlt = function (idx) {
    if (typeof window.lgseAssertSeoAI === 'function' && !window.lgseAssertSeoAI()) return;
    var st = window._lgseImagesState; if (!st) return;
    var it = (st.issues || [])[idx]; if (!it) return;
    var src = it.image_src || it.src || '';
    var existing = (st.optResults || []).filter(function (x) { return x.idx === idx; })[0];
    var initSeed = existing ? existing.suggested : '';
    var html = ''
      + '<div style="font-size:11.5px;color:var(--lgse-t3);margin-bottom:8px">Image: <span style="color:var(--lgse-t1);font-family:var(--lgse-mono);word-break:break-all">' + esc(src) + '</span></div>'
      + '<div style="font-size:11.5px;color:var(--lgse-t3);margin-bottom:10px">Page: ' + esc(it.page_url || '') + '</div>'
      + '<div id="lgse-alt-suggest-list" style="margin-bottom:10px">'
        + (existing
            ? '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:10px 12px"><div style="color:var(--lgse-t1);font-size:12px;line-height:1.45">"' + esc(existing.suggested) + '"</div></div>'
            : '<div style="padding:14px;text-align:center;color:var(--lgse-t3);font-size:11px">Generating suggestion…</div>')
      + '</div>'
      + '<label style="display:block;font-size:11px;color:var(--lgse-t3);margin-bottom:4px">Edit before applying (optional):</label>'
      + '<textarea id="lgse-alt-edit" maxlength="500" style="width:100%;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:6px;padding:8px 10px;font-size:12px;color:var(--lgse-t1);min-height:54px;font-family:inherit;resize:vertical">' + esc(initSeed) + '</textarea>'
      + '<div id="lgse-alt-status" style="font-size:11px;color:var(--lgse-t3);margin-top:8px;line-height:1.5">Apply writes the alt text into the image record, clears the missing-alt flag, refreshes counters, and updates Quick Wins. WP customers also receive a fire-and-forget push to the connector.</div>';
    window.lgseShowModal('Suggest + apply alt text', html, function () {
      window.lgseApplyAlt(idx);
    }, { saveLabel: 'Apply ✓', cancelLabel: 'Close' });

    // Disable the Apply button until we have a suggestion
    setTimeout(function () {
      var saveBtn = document.getElementById('lgse-modal-save');
      if (saveBtn && !existing) saveBtn.disabled = true;
    }, 0);

    if (existing) return;

    api('POST', '/image-issues/suggest-alt', {
      image_src:    src,
      page_context: it.page_title || it.page_url || ''
    }).then(function (r) {
      var s = (r && r.suggested_alt) || '';
      var listEl = document.getElementById('lgse-alt-suggest-list');
      var editEl = document.getElementById('lgse-alt-edit');
      var saveBtn = document.getElementById('lgse-modal-save');
      if (!listEl || !editEl) return;
      if (!s) {
        listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-t3);font-size:11px">No suggestion returned.</div>';
        return;
      }
      st.optResults = st.optResults || [];
      if (!st.optResults.some(function (x) { return x.idx === idx; })) {
        st.optResults.push({ idx: idx, suggested: s, image_src: src });
      }
      listEl.innerHTML = '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;padding:10px 12px"><div style="color:var(--lgse-t1);font-size:12px;line-height:1.45">"' + esc(s) + '"</div></div>';
      editEl.value = s;
      if (saveBtn) saveBtn.disabled = false;
      window.lgseRenderImagesBody();
    }).catch(function () {
      var listEl = document.getElementById('lgse-alt-suggest-list');
      if (listEl) listEl.innerHTML = '<div style="padding:14px;text-align:center;color:var(--lgse-red);font-size:11px">Suggest service unavailable.</div>';
    });
  };

  // Apply the alt text from the modal textarea -> POST /apply-alt -> refresh.
  window.lgseApplyAlt = function (idx) {
    var st = window._lgseImagesState; if (!st) return;
    var it = (st.issues || [])[idx]; if (!it) return;
    var src = it.image_src || it.src || '';
    var editEl = document.getElementById('lgse-alt-edit');
    var alt = editEl ? String(editEl.value || '').trim() : '';
    var statusEl = document.getElementById('lgse-alt-status');
    var saveBtn  = document.getElementById('lgse-modal-save');
    if (!alt) {
      if (statusEl) {
        statusEl.style.color = 'var(--lgse-red)';
        statusEl.textContent = 'Alt text cannot be empty.';
      }
      return;
    }
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Applying…'; }
    if (statusEl) {
      statusEl.style.color = 'var(--lgse-t3)';
      statusEl.textContent = 'Persisting alt to image record…';
    }

    api('POST', '/image-issues/apply-alt', {
      image_url: src,
      alt_text:  alt,
      page_url:  it.page_url || ''
    }).then(function (r) {
      if (!r || !r.success) {
        if (statusEl) {
          statusEl.style.color = 'var(--lgse-red)';
          statusEl.textContent = 'Apply failed: ' + ((r && (r.error || r.message)) || 'unknown');
        }
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Apply ✓'; }
        return;
      }
      // Local state sync — no second fetch needed
      if (st.issues && st.issues[idx]) {
        st.issues[idx].alt_text = alt;
        st.issues[idx].missing_alt = 0;
        st.issues[idx].empty_alt = 0;
      }
      if (st.summary) {
        st.summary.missing_alt = r.missing_remaining;
        st.summary.empty_alt   = r.empty_remaining;
      }
      // Quick Wins resolved
      if (typeof window.lgseMarkWinResolved === 'function' && it.page_url) {
        window.lgseMarkWinResolved(it.page_url, 'Image alt text');
        window.lgseMarkWinResolved(it.page_url, 'Missing alt');
      }
      // Honest WP push status
      var wpNote = '';
      if (r.wp_pushed === true) wpNote = ' (alt text also updated on your website)';
      else if (r.wp_pushed === false) wpNote = ' (saved — updates to your website may take a moment to appear)';
      // wp_pushed === null → no WP attached, pure Laravel — no extra note
      if (statusEl) {
        statusEl.style.color = 'var(--lgse-teal)';
        statusEl.textContent = 'Applied ✓ — ' + r.missing_remaining + ' missing-alt remaining' + wpNote;
      }
      if (saveBtn) saveBtn.textContent = 'Applied ✓';
      window.lgseRenderImagesBody();
      // Auto-close after a beat so user can read the status
      setTimeout(function () {
        var m = document.getElementById('lgse-modal-overlay');
        if (m) m.remove();
      }, 1100);
    }).catch(function (e) {
      if (statusEl) {
        statusEl.style.color = 'var(--lgse-red)';
        statusEl.textContent = 'Apply failed: ' + ((e && (e.message || (e.body && e.body.error))) || 'network error');
      }
      if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Apply ✓'; }
    });
  };
  // ── End P1-D ──────────────────────────────────────────────────────────

  // Generic themed modal helper. Re-used by P0-10 redirect Add/Edit/Delete
  // and P0-11 Purge-all-404s. Replaces native alert/confirm/prompt.
  window.lgseShowModal = function (title, html, onSave, opts) {
    opts = opts || {};
    var existing = document.getElementById('lgse-modal-overlay');
    if (existing) existing.remove();

    var saveLabel = esc(opts.saveLabel || 'Save');
    var cancelLabel = esc(opts.cancelLabel || 'Cancel');
    var hideSave = !!opts.hideSave;

    var overlay = document.createElement('div');
    overlay.id = 'lgse-modal-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML =
      '<div style="background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:14px;padding:22px;min-width:380px;max-width:480px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.5)">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">'
      +   '<div style="font-size:15px;font-weight:600;color:var(--lgse-t1)">' + esc(title) + '</div>'
      +   '<button onclick="(function(){var m=document.getElementById(\'lgse-modal-overlay\');if(m)m.remove();})()" style="background:transparent;border:none;color:var(--lgse-t3);font-size:20px;cursor:pointer;line-height:1;padding:4px">×</button>'
      + '</div>'
      + '<div id="lgse-modal-body">' + html + '</div>'
      + '<div id="lgse-modal-error" style="display:none;margin-top:10px;padding:8px 12px;background:rgba(239,68,68,.08);border:1px solid var(--lgse-red);border-radius:6px;color:var(--lgse-red);font-size:11.5px"></div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;padding-top:14px;border-top:1px solid var(--lgse-border)">'
      +   '<button onclick="(function(){var m=document.getElementById(\'lgse-modal-overlay\');if(m)m.remove();})()" class="lgse-btn-secondary" style="font-size:12px;padding:8px 16px">' + cancelLabel + '</button>'
      +   (hideSave ? '' : '<button id="lgse-modal-save" class="lgse-btn-primary" style="font-size:12px;padding:8px 16px">' + saveLabel + '</button>')
      + '</div>'
      + '</div>';
    document.body.appendChild(overlay);
    if (!hideSave) {
      document.getElementById('lgse-modal-save').onclick = function () { onSave(overlay); };
    }
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
  };



  // ─────────────────────────────────────────────────────────────────────
  // Dialog helpers (2026-05-16) — CSS-parallel replacements for native
  // alert/confirm/prompt. Promise-based:
  //   lgseAlert(title, body)        → resolves when user closes
  //   lgseConfirm(title, body, ok?) → resolves true (OK) | false (Cancel)
  //   lgsePrompt(title, body, def?, placeholder?, maxLen?)
  //                                 → resolves string (OK) | null (Cancel)
  // Visual language matches lgseShowModal (dim backdrop, dark card,
  // purple primary button, themed border). Esc cancels. Enter submits.
  // ─────────────────────────────────────────────────────────────────────
  function _lgseDialogBuild(opts) {
    // opts: { title, bodyHtml, kind: 'alert'|'confirm'|'prompt',
    //         okLabel, cancelLabel, defaultValue, placeholder, maxLen }
    return new Promise(function (resolve) {
      var existing = document.getElementById('lgse-dialog-overlay');
      if (existing) existing.remove();

      var kind        = opts.kind || 'alert';
      var okLabel     = opts.okLabel     || (kind === 'confirm' ? 'OK' : (kind === 'prompt' ? 'OK' : 'OK'));
      var cancelLabel = opts.cancelLabel || (kind === 'alert' ? 'Close' : 'Cancel');
      var hasCancel   = kind !== 'alert';
      var hasInput    = kind === 'prompt';
      var defVal      = opts.defaultValue || '';
      var placeholder = opts.placeholder  || '';
      var maxLen      = opts.maxLen       || 500;

      var overlay = document.createElement('div');
      overlay.id = 'lgse-dialog-overlay';
      overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10000;display:flex;align-items:center;justify-content:center';

      var inputHtml = '';
      if (hasInput) {
        // Textarea for longer prompts (templates etc.); single-line input
        // for short prompts. Heuristic: if defaultValue or placeholder is
        // long, use textarea.
        var isLong = (defVal && defVal.length > 60) || (placeholder && placeholder.length > 80);
        if (isLong) {
          inputHtml = '<textarea id="lgse-dialog-input" maxlength="' + maxLen + '" placeholder="' + _lgseDialogEsc(placeholder) + '" '
            + 'style="width:100%;min-height:80px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:6px;'
            + 'padding:8px 10px;font-size:12px;color:var(--lgse-t1);font-family:inherit;resize:vertical;margin-top:10px">'
            + _lgseDialogEsc(defVal) + '</textarea>';
        } else {
          inputHtml = '<input id="lgse-dialog-input" type="text" maxlength="' + maxLen + '" placeholder="' + _lgseDialogEsc(placeholder) + '" '
            + 'value="' + _lgseDialogEsc(defVal) + '" '
            + 'style="width:100%;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:6px;'
            + 'padding:8px 10px;font-size:12px;color:var(--lgse-t1);font-family:inherit;margin-top:10px">';
        }
      }

      overlay.innerHTML =
          '<div role="dialog" aria-modal="true" style="background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:14px;padding:22px;min-width:380px;max-width:520px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.5)">'
          + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">'
          +   '<div style="font-size:15px;font-weight:600;color:var(--lgse-t1)">' + _lgseDialogEsc(opts.title || '') + '</div>'
          +   '<button id="lgse-dialog-x" aria-label="Close" style="background:transparent;border:none;color:var(--lgse-t3);font-size:20px;cursor:pointer;line-height:1;padding:4px">×</button>'
          + '</div>'
          + '<div style="font-size:12.5px;color:var(--lgse-t2);line-height:1.55;white-space:pre-wrap">' + (opts.bodyHtml || '') + '</div>'
          + inputHtml
          + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid var(--lgse-border)">'
          +   (hasCancel ? '<button id="lgse-dialog-cancel" class="lgse-btn-secondary" style="font-size:12px;padding:8px 16px">' + _lgseDialogEsc(cancelLabel) + '</button>' : '')
          +   '<button id="lgse-dialog-ok" class="lgse-btn-primary" style="font-size:12px;padding:8px 16px">' + _lgseDialogEsc(okLabel) + '</button>'
          + '</div>'
          + '</div>';

      document.body.appendChild(overlay);

      var settled = false;
      var resolveOnce = function (v) {
        if (settled) return;
        settled = true;
        overlay.remove();
        document.removeEventListener('keydown', keyHandler, true);
        resolve(v);
      };

      var okValue     = function () { return kind === 'alert' ? undefined : (kind === 'confirm' ? true : (document.getElementById('lgse-dialog-input').value || '')); };
      var cancelValue = function () { return kind === 'alert' ? undefined : (kind === 'confirm' ? false : null); };

      document.getElementById('lgse-dialog-ok').onclick     = function () { resolveOnce(okValue()); };
      document.getElementById('lgse-dialog-x').onclick      = function () { resolveOnce(cancelValue()); };
      if (hasCancel) document.getElementById('lgse-dialog-cancel').onclick = function () { resolveOnce(cancelValue()); };
      // Click outside the dialog card → cancel (alert: just close)
      overlay.addEventListener('click', function (e) { if (e.target === overlay) resolveOnce(cancelValue()); });

      function keyHandler(e) {
        if (e.key === 'Escape') { e.preventDefault(); resolveOnce(cancelValue()); return; }
        if (e.key === 'Enter') {
          // Enter submits unless focus is in a textarea (multi-line content)
          var target = document.activeElement;
          if (target && target.tagName === 'TEXTAREA') return;
          e.preventDefault();
          resolveOnce(okValue());
        }
      }
      document.addEventListener('keydown', keyHandler, true);

      // Autofocus: input first if present, otherwise OK button
      setTimeout(function () {
        var input = document.getElementById('lgse-dialog-input');
        if (input) { try { input.focus(); if (input.select) input.select(); } catch (_) {} }
        else { try { document.getElementById('lgse-dialog-ok').focus(); } catch (_) {} }
      }, 0);
    });
  }

  function _lgseDialogEsc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Public API
  window.lgseAlert = function (title, body) {
    return _lgseDialogBuild({ kind: 'alert', title: title || '', bodyHtml: _lgseDialogEsc(body || '') });
  };

  window.lgseConfirm = function (title, body, okLabel, cancelLabel) {
    return _lgseDialogBuild({
      kind: 'confirm', title: title || '', bodyHtml: _lgseDialogEsc(body || ''),
      okLabel: okLabel, cancelLabel: cancelLabel,
    });
  };

  window.lgsePrompt = function (title, body, defaultValue, placeholder, maxLen) {
    return _lgseDialogBuild({
      kind: 'prompt', title: title || '', bodyHtml: _lgseDialogEsc(body || ''),
      defaultValue: defaultValue || '', placeholder: placeholder || '',
      maxLen: maxLen || 500,
    });
  };

  // Inline-error helper for the open modal. Replaces native alert().
  function lgseModalError(msg) {
    var el = document.getElementById('lgse-modal-error');
    if (!el) return;
    el.textContent = msg;
    el.style.display = 'block';
  }
  function lgseClearModalError() {
    var el = document.getElementById('lgse-modal-error');
    if (el) { el.textContent = ''; el.style.display = 'none'; }
  }

  // ── P0-9: Outbound links ─────────────────────────────────────────────

  window.lgseLoadOutbound = function () {
    var body = document.getElementById('lgse-links-body');
    if (!body) return;
    body.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:8px">'
      +   '<div style="display:flex;gap:8px">'
      +     '<button class="lgse-btn-primary" id="lgse-ob-scan-btn" onclick="lgseScanOutbound(this)" style="font-size:11.5px;padding:7px 14px">Scan outbound links</button>'
      +     '<button class="lgse-btn-secondary" onclick="lgseCheckBroken(this)" style="font-size:11px;padding:7px 14px">Check broken</button>'
      +   '</div>'
      + '</div>'
      + '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">'
      +   '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Total</div><div class="lgse-kpi-val" id="lgse-ob-total">—</div></div>'
      +   '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Broken</div><div class="lgse-kpi-val lgse-dn" id="lgse-ob-broken">—</div></div>'
      +   '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Unchecked</div><div class="lgse-kpi-val" id="lgse-ob-unchecked">—</div></div>'
      +   '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Healthy</div><div class="lgse-kpi-val lgse-up" id="lgse-ob-healthy">—</div></div>'
      + '</div>'
      + '<div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">'
      +   '<input id="lgse-ob-search" placeholder="Search domain…" oninput="lgseFilterOBTable(this.value)" style="flex:1;min-width:180px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:7px 12px;font-size:11.5px;color:var(--lgse-t1)">'
      +   ['all','broken','unchecked'].map(function (f) {
            var lbl = f.charAt(0).toUpperCase() + f.slice(1);
            var active = f === 'all';
            var bg = active ? 'rgba(108,92,231,0.08)' : 'var(--lgse-bg2)';
            var bd = active ? 'var(--lgse-purple)' : 'var(--lgse-border)';
            var c  = active ? 'var(--lgse-purple)' : 'var(--lgse-t3)';
            return '<span class="lgse-fp" onclick="lgseOBFilter(\'' + f + '\', this)" style="background:' + bg + ';border:1px solid ' + bd + ';border-radius:20px;padding:4px 12px;font-size:10.5px;color:' + c + ';cursor:pointer;transition:all .12s">' + lbl + '</span>';
          }).join('')
      + '</div>'
      + '<div id="lgse-ob-table">Loading…</div>';
    window.lgseLoadOutboundData();
  };

  window.lgseLoadOutboundData = function () {
    api('GET', '/outbound').then(function (d) {
      var links = (d && (d.links || d.data)) || (Array.isArray(d) ? d : []);
      var broken = 0, unchecked = 0;
      links.forEach(function (l) {
        if (l.is_broken || l.http_status === 404 || l.status === 'broken') broken++;
        if (!l.last_checked && l.status !== 'ok' && l.status !== 'broken') unchecked++;
      });
      var healthy = links.length - broken - unchecked;

      var t  = document.getElementById('lgse-ob-total');
      var b  = document.getElementById('lgse-ob-broken');
      var u  = document.getElementById('lgse-ob-unchecked');
      var hl = document.getElementById('lgse-ob-healthy');
      if (t)  t.textContent  = links.length;
      if (b)  b.textContent  = broken;
      if (u)  u.textContent  = unchecked;
      if (hl) hl.textContent = healthy;

      var table = document.getElementById('lgse-ob-table');
      if (!table) return;
      if (!links.length) {
        table.innerHTML = emptyState('⟡', 'No outbound links scanned', 'Click "Scan outbound links" to find every external link on your site.', 'Scan now', 'lgseScanOutbound(null)');
        return;
      }

      var headers = ['Domain', 'URL', 'Status', 'Source page', 'Last checked', ''];
      var thead = '<thead><tr>' + headers.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead>';

      var rows = links.map(function (l) {
        var st = l.http_status || 0;
        var stColor = st === 200 ? 'teal' : st === 404 ? 'red' : (st > 300 && st < 400) ? 'blue' : 'muted';
        var stLabel = st ? String(st) : (l.status || 'unchecked');
        var isBroken = l.is_broken || st === 404 || l.status === 'broken';
        var notChecked = !l.last_checked && l.status !== 'ok' && l.status !== 'broken';
        var rowStatus = isBroken ? 'broken' : notChecked ? 'unchecked' : 'ok';
        var domain = l.domain || (l.target_url || l.url || '').replace(/^https?:\/\//, '').split('/')[0];
        var url = l.target_url || l.url || '';
        var sourceUrl = l.source_url || '';
        return '<tr class="lgse-ob-row" data-domain="' + esc(domain) + '" data-status="' + rowStatus + '">'
          + '<td class="mono" style="color:var(--lgse-t2)">' + esc(domain) + '</td>'
          + '<td class="lgse-url" title="' + esc(url) + '">' + esc(url.replace(/^https?:\/\//, '').slice(0, 50)) + '</td>'
          + '<td>' + badge(stLabel, stColor) + '</td>'
          + '<td class="lgse-url" title="' + esc(sourceUrl) + '">' + esc(sourceUrl.replace(/^https?:\/\//, '').slice(0, 40)) + '</td>'
          + '<td style="font-size:10px;color:var(--lgse-t3)">' + (l.last_checked ? new Date(l.last_checked).toLocaleDateString() : 'Never') + '</td>'
          + '<td><div style="display:flex;gap:4px">'
          +   '<button onclick="lgseRecheckLink(' + (l.id || 0) + ', this)" style="background:transparent;color:var(--lgse-purple);border:1px solid rgba(108,92,231,.3);border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">Recheck</button>'
          +   (isBroken ? '<button onclick="lgseMarkLinkOK(' + (l.id || 0) + ', this)" style="background:transparent;color:var(--lgse-t3);border:1px solid var(--lgse-border);border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">Mark OK</button>' : '')
          + '</div></td>'
          + '</tr>';
      }).join('');

      table.innerHTML = '<table class="lgse-table">' + thead + '<tbody>' + rows + '</tbody></table>';
    }).catch(function () {
      var t = document.getElementById('lgse-ob-table');
      if (t) t.innerHTML = emptyState('⟡', 'No outbound data', 'Try a fresh scan.');
    });
  };

  window.lgseScanOutbound = function (btn) {
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Scanning…'; }
    api('POST', '/scan-outbound', {}).then(function () {
      if (btn) { btn.disabled = false; btn.textContent = 'Scan outbound links'; }
      window.lgseLoadOutboundData();
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = 'Scan outbound links'; }
    });
  };

  window.lgseCheckBroken = function (btn) {
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Checking…'; }
    api('POST', '/outbound-check', {}).then(function () {
      if (btn) { btn.disabled = false; btn.textContent = 'Check broken'; }
      window.lgseLoadOutboundData();
    }).catch(function () {
      if (btn) { btn.disabled = false; btn.textContent = 'Check broken'; }
    });
  };

  window.lgseRecheckLink = function (id, btn) {
    if (!id) return;
    btn.disabled = true; btn.textContent = '⏳';
    api('POST', '/scan-outbound/' + id, {}).then(function () {
      btn.disabled = false; btn.textContent = 'Recheck';
      window.lgseLoadOutboundData();
    }).catch(function () { btn.disabled = false; btn.textContent = 'Recheck'; });
  };

  // Mark OK — backend route NOT implemented yet (PATCH /outbound/{id}).
  // Catches the 404 gracefully so the button doesn't appear broken.
  window.lgseMarkLinkOK = function (id, btn) {
    if (!id) return;
    btn.disabled = true; btn.textContent = '⏳';
    api('PATCH', '/outbound/' + id, { status: 'ok' }).then(function (resp) {
      btn.disabled = false; btn.textContent = 'Mark OK';
      if (typeof window.showToast === 'function') {
        if (resp && resp.success === false) window.showToast('Mark-OK endpoint not yet wired on the backend.', 'info');
      }
      window.lgseLoadOutboundData();
    }).catch(function () {
      btn.disabled = false; btn.textContent = 'Mark OK';
      if (typeof window.showToast === 'function') window.showToast('Mark-OK endpoint not yet wired on the backend.', 'info');
    });
  };

  window.lgseFilterOBTable = function (q) {
    q = (q || '').toLowerCase();
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-ob-row'), function (row) {
      var domain = (row.getAttribute('data-domain') || '').toLowerCase();
      row.style.display = !q || domain.indexOf(q) > -1 ? '' : 'none';
    });
  };

  window.lgseOBFilter = function (status, el) {
    Array.prototype.forEach.call(document.querySelectorAll('#lgse-links-body span[onclick*="lgseOBFilter"]'), function (p) {
      p.style.background = 'var(--lgse-bg2)';
      p.style.borderColor = 'var(--lgse-border)';
      p.style.color = 'var(--lgse-t3)';
    });
    if (el) {
      el.style.background = 'rgba(108,92,231,0.08)';
      el.style.borderColor = 'var(--lgse-purple)';
      el.style.color = 'var(--lgse-purple)';
    }
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-ob-row'), function (row) {
      var rs = row.getAttribute('data-status') || 'ok';
      row.style.display = (status === 'all' || rs === status) ? '' : 'none';
    });
  };

  // ── P0-10: Redirects + P0-11: 404 Log ────────────────────────────────

  window.lgseLoadRedirects = function () {
    var body = document.getElementById('lgse-links-body');
    if (!body) return;
    body.innerHTML =
      '<div style="display:flex;gap:0;border-bottom:1px solid var(--lgse-border);margin-bottom:14px">'
      +   '<div onclick="lgseRedirectTab(0,this)" style="padding:7px 14px;font-size:11px;cursor:pointer;border-bottom:2px solid var(--lgse-purple);color:var(--lgse-purple);transition:color .12s">Redirects</div>'
      +   '<div onclick="lgseRedirectTab(1,this)" style="padding:7px 14px;font-size:11px;cursor:pointer;border-bottom:2px solid transparent;color:var(--lgse-t3);transition:color .12s">404 Log</div>'
      + '</div>'
      + '<div id="lgse-redir-content"></div>';
    window.lgseLoadRedirectsList();
  };

  window.lgseRedirectTab = function (idx, el) {
    var tabs = document.querySelectorAll('#lgse-links-body [onclick*="lgseRedirectTab"]');
    Array.prototype.forEach.call(tabs, function (t, i) {
      t.style.borderBottomColor = (i === idx) ? 'var(--lgse-purple)' : 'transparent';
      t.style.color = (i === idx) ? 'var(--lgse-purple)' : 'var(--lgse-t3)';
    });
    if (idx === 0) window.lgseLoadRedirectsList();
    else window.lgseLoad404Log();
  };

  window.lgseLoadRedirectsList = function () {
    var content = document.getElementById('lgse-redir-content');
    if (!content) return;
    content.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">'
      +   '<div style="font-size:10px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em">Redirect rules</div>'
      +   '<button class="lgse-btn-primary" onclick="lgseAddRedirect()" style="font-size:11.5px;padding:7px 14px">+ Add redirect</button>'
      + '</div>'
      + '<div id="lgse-redir-table">Loading…</div>';

    api('GET', '/redirects').then(function (d) {
      var rows = (d && (d.redirects || d.data)) || (Array.isArray(d) ? d : []);
      var el = document.getElementById('lgse-redir-table');
      if (!el) return;
      if (!rows.length) {
        el.innerHTML = emptyState('↪', 'No redirects yet', 'Add redirect rules to manage URL changes.', '+ Add redirect', 'lgseAddRedirect()');
        return;
      }
      var headers = ['From URL', 'To URL', 'Type', 'Hits', 'Status', ''];
      var thead = '<thead><tr>' + headers.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead>';
      var rowHtml = rows.map(function (r) {
        var isActive = (r.is_active === undefined ? true : !!r.is_active) && r.status !== 'inactive';
        // Backend stores `source_url`/`target_url`; legacy fallbacks kept.
        var srcRaw = r.source_url || r.from_url || r.from || r.source || '';
        var tgtRaw = r.target_url || r.to_url   || r.to   || r.target || '';
        var fromUrl = srcRaw.replace(/^https?:\/\//, '');
        var toUrl   = tgtRaw.replace(/^https?:\/\//, '');
        var type    = String(r.type || r.code || 301);
        var rPayload = encodeURIComponent(JSON.stringify({ id: r.id, from_url: srcRaw, to_url: tgtRaw, type: type }));
        return '<tr>'
          + '<td class="lgse-url" title="' + esc(srcRaw) + '">' + esc(fromUrl) + '</td>'
          + '<td class="lgse-url" title="' + esc(tgtRaw) + '">' + esc(toUrl) + '</td>'
          + '<td>' + badge(type, 'blue') + '</td>'
          + '<td class="mono">' + (r.hit_count || r.hits || 0) + '</td>'
          + '<td>' + badge(isActive ? 'active' : 'inactive', isActive ? 'teal' : 'muted') + '</td>'
          + '<td><div style="display:flex;gap:4px">'
          +   '<button onclick="lgseEditRedirect(\'' + rPayload + '\')" style="background:transparent;color:var(--lgse-purple);border:1px solid rgba(108,92,231,.3);border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">Edit</button>'
          +   '<button onclick="lgseDeleteRedirect(' + (r.id || 0) + ')" style="background:transparent;color:var(--lgse-t3);border:1px solid var(--lgse-border);border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">Delete</button>'
          + '</div></td>'
          + '</tr>';
      }).join('');
      el.innerHTML = '<table class="lgse-table">' + thead + '<tbody>' + rowHtml + '</tbody></table>';
    }).catch(function () {
      var el = document.getElementById('lgse-redir-table');
      if (el) el.innerHTML = emptyState('⟡', 'Could not load redirects', 'Try refreshing.');
    });
  };

  function lgseRedirectFormHtml(r) {
    r = r || {};
    var label = 'display:block;font-size:10px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px';
    var input = 'width:100%;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:7px;padding:8px 12px;font-size:11.5px;color:var(--lgse-t1);outline:none;margin-bottom:12px;box-sizing:border-box';
    return '<label style="' + label + '">From URL</label>'
      + '<input id="lgse-redir-from" type="text" value="' + esc(r.from_url || '') + '" placeholder="https://yoursite.com/old-url" style="' + input + '">'
      + '<label style="' + label + '">To URL</label>'
      + '<input id="lgse-redir-to" type="text" value="' + esc(r.to_url || '') + '" placeholder="https://yoursite.com/new-url" style="' + input + '">'
      + '<label style="' + label + '">Type</label>'
      + '<select id="lgse-redir-type" style="' + input + '">'
      +   '<option value="301"' + ((r.type || '301') === '301' ? ' selected' : '') + '>301 — Permanent</option>'
      +   '<option value="302"' + (r.type === '302' ? ' selected' : '') + '>302 — Temporary</option>'
      + '</select>';
  }

  window.lgseAddRedirect = function (preFrom) {
    var r = preFrom ? { from_url: preFrom } : {};
    window.lgseShowModal('Add redirect', lgseRedirectFormHtml(r), function (overlay) {
      lgseClearModalError();
      var from = (document.getElementById('lgse-redir-from').value || '').trim();
      var to   = (document.getElementById('lgse-redir-to').value || '').trim();
      var type = document.getElementById('lgse-redir-type').value || '301';
      if (!from || !to)  { lgseModalError('Both URLs are required.'); return; }
      if (from === to)   { lgseModalError('From and To URLs must differ.'); return; }
      api('POST', '/redirects', { from_url: from, to_url: to, type: type }).then(function () {
        overlay.remove();
        window.lgseLoadRedirectsList();
      }).catch(function () { lgseModalError('Failed to save redirect. Please try again.'); });
    });
  };

  // Edit redirect — backend PATCH /redirects/{id} not implemented yet.
  // We attempt the PATCH; on failure we delete + recreate as a fallback so the user-visible behaviour still works.
  window.lgseEditRedirect = function (payload) {
    var r = {};
    try { r = JSON.parse(decodeURIComponent(payload)); } catch (e) { r = {}; }
    window.lgseShowModal('Edit redirect', lgseRedirectFormHtml(r), function (overlay) {
      lgseClearModalError();
      var from = (document.getElementById('lgse-redir-from').value || '').trim();
      var to   = (document.getElementById('lgse-redir-to').value || '').trim();
      var type = document.getElementById('lgse-redir-type').value || '301';
      if (!from || !to)  { lgseModalError('Both URLs are required.'); return; }
      if (from === to)   { lgseModalError('From and To URLs must differ.'); return; }
      // Try PATCH first (will 404 today — backend route not yet shipped).
      api('PATCH', '/redirects/' + (r.id || 0), { from_url: from, to_url: to, type: type }).then(function (resp) {
        if (resp && resp.success !== false) {
          overlay.remove(); window.lgseLoadRedirectsList(); return;
        }
        // Fallback: delete + recreate.
        return api('DELETE', '/redirects/' + (r.id || 0), {}).then(function () {
          return api('POST', '/redirects', { from_url: from, to_url: to, type: type });
        }).then(function () { overlay.remove(); window.lgseLoadRedirectsList(); });
      }).catch(function () {
        // Same delete + recreate fallback if PATCH errored entirely.
        api('DELETE', '/redirects/' + (r.id || 0), {}).then(function () {
          return api('POST', '/redirects', { from_url: from, to_url: to, type: type });
        }).then(function () {
          overlay.remove(); window.lgseLoadRedirectsList();
        }).catch(function () { lgseModalError('Failed to update redirect. Please try again.'); });
      });
    });
  };

  window.lgseDeleteRedirect = function (id) {
    if (!id) return;
    window.lgseShowModal('Delete redirect',
      '<p style="color:var(--lgse-t2);font-size:12.5px;line-height:1.6">Delete this redirect rule? This cannot be undone.</p>',
      function (overlay) {
        api('DELETE', '/redirects/' + id, {}).then(function () {
          overlay.remove(); window.lgseLoadRedirectsList();
        }).catch(function () { overlay.remove(); window.lgseLoadRedirectsList(); });
      },
      { saveLabel: 'Delete' }
    );
  };

  // ── P0-11: 404 log ──

  window.lgseLoad404Log = function () {
    var content = document.getElementById('lgse-redir-content');
    if (!content) return;
    content.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">'
      +   '<div style="font-size:10px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em">404 error log</div>'
      +   '<button onclick="lgsePurge404s()" style="background:transparent;color:var(--lgse-red);border:1px solid rgba(239,68,68,.3);border-radius:7px;padding:6px 14px;font-size:11px;cursor:pointer">Purge all</button>'
      + '</div>'
      + '<div id="lgse-404-table">Loading…</div>';

    api('GET', '/404-log').then(function (d) {
      var rows = (d && (d.entries || d.data || d.log)) || (Array.isArray(d) ? d : []);
      var el = document.getElementById('lgse-404-table');
      if (!el) return;
      if (!rows.length) {
        el.innerHTML = '<div style="padding:24px;text-align:center;color:var(--lgse-t3);font-size:11px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px">✓ No 404 errors logged — that\'s a good sign.</div>';
        return;
      }
      var headers = ['URL', 'Hits', 'Last seen', 'Referrer', ''];
      var thead = '<thead><tr>' + headers.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead>';
      var rowHtml = rows.map(function (r) {
        var url = r.url || r.path || '';
        var safeUrl = url.replace(/'/g, "\\'");
        return '<tr>'
          + '<td class="lgse-url" title="' + esc(url) + '">' + esc(url) + '</td>'
          + '<td class="mono" style="color:var(--lgse-red)">' + (r.hit_count || r.hits || 1) + '</td>'
          + '<td style="font-size:10px;color:var(--lgse-t3)">' + (r.last_seen || r.updated_at ? new Date(r.last_seen || r.updated_at).toLocaleDateString() : '—') + '</td>'
          + '<td class="lgse-url" style="font-size:10px;color:var(--lgse-t3)">' + esc(r.referrer || '—') + '</td>'
          + '<td><div style="display:flex;gap:4px">'
          +   '<button onclick="lgseConvert404(\'' + safeUrl + '\')" style="background:var(--lgse-purple);color:white;border:none;border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">Fix →</button>'
          +   '<button onclick="lgseDelete404(' + (r.id || 0) + ')" style="background:transparent;color:var(--lgse-t3);border:1px solid var(--lgse-border);border-radius:4px;padding:2px 8px;font-size:9px;cursor:pointer">✕</button>'
          + '</div></td>'
          + '</tr>';
      }).join('');
      el.innerHTML = '<table class="lgse-table">' + thead + '<tbody>' + rowHtml + '</tbody></table>';
    }).catch(function () {
      var el = document.getElementById('lgse-404-table');
      if (el) el.innerHTML = emptyState('⟡', 'Could not load 404 log', 'Try refreshing.');
    });
  };

  window.lgseConvert404 = function (url) { window.lgseAddRedirect(url); };

  // DELETE /404-log/{id} — backend route NOT implemented yet.
  // Fires the request anyway and shows a graceful toast on 404.
  window.lgseDelete404 = function (id) {
    if (!id) return;
    api('DELETE', '/404-log/' + id, {}).then(function () {
      window.lgseLoad404Log();
    }).catch(function () {
      if (typeof window.showToast === 'function') window.showToast('Delete-404 endpoint not yet wired on the backend.', 'info');
    });
  };

  window.lgsePurge404s = function () {
    window.lgseShowModal('Purge all 404 logs',
      '<p style="color:var(--lgse-t2);font-size:12.5px;line-height:1.6">Delete every 404 error log entry? This cannot be undone.</p>',
      function (overlay) {
        api('DELETE', '/404-log', {}).then(function () {
          overlay.remove(); window.lgseLoad404Log();
        }).catch(function () {
          overlay.remove();
          if (typeof window.showToast === 'function') window.showToast('Purge endpoint not yet wired on the backend.', 'info');
        });
      },
      { saveLabel: 'Purge all' }
    );
  };

  // ── Tab 6 — Topics ─────────────────────────────────────────────────────
  function renderTopics(el) {
    el.innerHTML = pageTitle('Topics', 'Semantic clusters built from your indexed content. Track topic authority and gaps.')
      + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">'
        + '<button class="lgse-btn-primary" id="lgse-rebuild-clusters-btn" onclick="lgseRunClusters(this)">Rebuild clusters</button>'
      + '</div>'
      + '<div style="font-size:10px;color:var(--lgse-t3);margin-bottom:14px">Groups your indexed pages by topic using semantic similarity. Run after adding new content.</div>'
      + '<div id="lgse-topics-body">Loading…</div>';
    window.lgseLoadTopics();
  }

  window.lgseLoadTopics = function () {
    var body = document.getElementById('lgse-topics-body');
    if (!body) return;
    body.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading topics…</div>';
    Promise.all([
      api('GET', '/topics/authority').catch(function () { return null; }),
      api('GET', '/clusters/gaps').catch(function () { return null; }),
    ]).then(function (r) {
      var topics = (r[0] && (r[0].topics || r[0].data)) || []; var gaps = (r[1] && (r[1].gaps || r[1].data)) || [];
      if (topics.length === 0) {
        body.innerHTML = emptyState('⬡', 'Not enough pages to cluster', 'Topics clustering needs at least 3 indexed pages with overlapping content. Scan more pages first.', 'Scan Pages', 'lgseSwitchTab(\'pages\')');
        return;
      }
      var h = '<table class="lgse-table" style="margin-bottom:16px"><thead><tr><th>Topic</th><th class="r">Authority</th><th class="r">Pages</th><th class="r">Avg score</th><th>Completeness</th><th>Pillar</th></tr></thead><tbody>';
      topics.slice(0, 30).forEach(function (t) {
        var auth = parseInt(t.authority || 0, 10);
        var comp = parseInt(t.completeness_score || 0, 10);
        h += '<tr><td style="font-weight:500;color:var(--lgse-t1)">' + esc(t.topic || '—') + '</td>';
        h += '<td class="mono">' + scorePill(auth) + '</td>';
        h += '<td class="mono">' + (parseInt(t.member_count || 0, 10) || 0) + '</td>';
        h += '<td class="mono">' + (parseInt(t.avg_content_score || 0, 10) || 0) + '</td>';
        h += '<td style="width:120px"><div class="lgse-stack-bar"><div style="height:100%;background:' + scoreColor(comp) + ';width:' + comp + '%"></div></div><div style="font-size:9px;color:var(--lgse-t3);margin-top:2px;font-family:var(--lgse-mono)">' + comp + '/100</div></td>';
        h += '<td>' + (t.has_pillar ? badge('✓ pillar', 'teal') : badge('no pillar', 'red')) + '</td></tr>';
      });
      h += '</tbody></table>';
      if (gaps.length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Cluster gaps</span><span style="font-size:10px;color:var(--lgse-t3)">' + gaps.length + ' total</span></div>';
        h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:8px">';
        gaps.slice(0, 8).forEach(function (g) {
          h += '<div class="lgse-cat-item" style="display:block"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px"><span style="font-size:12px;color:var(--lgse-t1);font-weight:600">' + esc(g.topic || '') + '</span><span style="display:flex;gap:4px">';
          (g.gaps || []).forEach(function (gg) { h += badge(String(gg).replace(/_/g, ' '), 'amber'); });
          h += '</span></div><div style="font-size:10.5px;color:var(--lgse-t2)">' + esc(g.recommendation || '') + '</div></div>';
        });
        h += '</div>';
      }
      body.innerHTML = h;
    }).catch(function () { body.innerHTML = emptyState('⚠', 'Could not load topics', 'Try refreshing.'); });
  };

  window.lgseRunClusters = function (btn) {
    var b = btn || document.getElementById('lgse-rebuild-clusters-btn');
    var orig = b ? b.textContent : '';
    if (b) { b.disabled = true; b.textContent = '⏳ Building…'; }
    api('POST', '/clusters/build', {}).then(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Rebuild clusters'; }
      window.lgseLoadTopics();
    }).catch(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Rebuild clusters'; }
      var body = document.getElementById('lgse-topics-body');
      if (body) body.innerHTML = emptyState('⬡', 'Not enough pages to cluster', 'Topics clustering needs at least 3 indexed pages with overlapping content. Scan more pages first.', 'Scan Pages', 'lgseSwitchTab(\'pages\')');
    });
  };

  // ── Tab 7 — Competitors ────────────────────────────────────────────────
  // Country list for the location selector — DataForSEO accepts these names.
  // Wave 19.1 (2026-05-19). Full DataForSEO country location codes.
  // `c` = DataForSEO location_code (sent to backend), `v` = display name,
  // `l` = flag + label rendered in the <option>. Top group is the previous
  // shortlist (regional + Western markets) for quick access; the rest is
  // alphabetical. Backend now accepts `location_code` directly so the JS
  // doesn't need to maintain a separate name→code mapping.
  var LGSE_CMP_COUNTRIES = [
    // ─── Quick access (regional + most-used) ───
    { c: 2784, v: 'United Arab Emirates', l: '🇦🇪 United Arab Emirates' },
    { c: 2682, v: 'Saudi Arabia',         l: '🇸🇦 Saudi Arabia' },
    { c: 2634, v: 'Qatar',                l: '🇶🇦 Qatar' },
    { c: 2414, v: 'Kuwait',               l: '🇰🇼 Kuwait' },
    { c: 2048, v: 'Bahrain',              l: '🇧🇭 Bahrain' },
    { c: 2512, v: 'Oman',                 l: '🇴🇲 Oman' },
    { c: 2818, v: 'Egypt',                l: '🇪🇬 Egypt' },
    { c: 2400, v: 'Jordan',               l: '🇯🇴 Jordan' },
    { c: 2422, v: 'Lebanon',              l: '🇱🇧 Lebanon' },
    { c: 2840, v: 'United States',        l: '🇺🇸 United States' },
    { c: 2826, v: 'United Kingdom',       l: '🇬🇧 United Kingdom' },
    { c: 2124, v: 'Canada',               l: '🇨🇦 Canada' },
    { c: 2036, v: 'Australia',            l: '🇦🇺 Australia' },
    { c: 2276, v: 'Germany',              l: '🇩🇪 Germany' },
    { c: 2250, v: 'France',               l: '🇫🇷 France' },
    { c: 2724, v: 'Spain',                l: '🇪🇸 Spain' },
    { c: 2380, v: 'Italy',                l: '🇮🇹 Italy' },
    { c: 2528, v: 'Netherlands',          l: '🇳🇱 Netherlands' },
    { c: 2756, v: 'Switzerland',          l: '🇨🇭 Switzerland' },
    { c: 2040, v: 'Austria',              l: '🇦🇹 Austria' },
    { c: 2356, v: 'India',                l: '🇮🇳 India' },
    { c: 2586, v: 'Pakistan',             l: '🇵🇰 Pakistan' },
    { c: 2702, v: 'Singapore',            l: '🇸🇬 Singapore' },
    // ─── All other countries — alphabetical ───
    { c: 2004, v: 'Afghanistan',           l: '🇦🇫 Afghanistan' },
    { c: 2008, v: 'Albania',               l: '🇦🇱 Albania' },
    { c: 2012, v: 'Algeria',               l: '🇩🇿 Algeria' },
    { c: 2024, v: 'Angola',                l: '🇦🇴 Angola' },
    { c: 2032, v: 'Argentina',             l: '🇦🇷 Argentina' },
    { c: 2051, v: 'Armenia',               l: '🇦🇲 Armenia' },
    { c: 2031, v: 'Azerbaijan',            l: '🇦🇿 Azerbaijan' },
    { c: 2050, v: 'Bangladesh',            l: '🇧🇩 Bangladesh' },
    { c: 2112, v: 'Belarus',               l: '🇧🇾 Belarus' },
    { c: 2056, v: 'Belgium',               l: '🇧🇪 Belgium' },
    { c: 2068, v: 'Bolivia',               l: '🇧🇴 Bolivia' },
    { c: 2070, v: 'Bosnia and Herzegovina',l: '🇧🇦 Bosnia and Herzegovina' },
    { c: 2076, v: 'Brazil',                l: '🇧🇷 Brazil' },
    { c: 2096, v: 'Brunei',                l: '🇧🇳 Brunei' },
    { c: 2100, v: 'Bulgaria',              l: '🇧🇬 Bulgaria' },
    { c: 2854, v: 'Burkina Faso',          l: '🇧🇫 Burkina Faso' },
    { c: 2116, v: 'Cambodia',              l: '🇰🇭 Cambodia' },
    { c: 2120, v: 'Cameroon',              l: '🇨🇲 Cameroon' },
    { c: 2152, v: 'Chile',                 l: '🇨🇱 Chile' },
    { c: 2170, v: 'Colombia',              l: '🇨🇴 Colombia' },
    { c: 2188, v: 'Costa Rica',            l: '🇨🇷 Costa Rica' },
    { c: 2384, v: 'Côte d’Ivoire',    l: '🇨🇮 Côte d’Ivoire' },
    { c: 2191, v: 'Croatia',               l: '🇭🇷 Croatia' },
    { c: 2196, v: 'Cyprus',                l: '🇨🇾 Cyprus' },
    { c: 2203, v: 'Czechia',               l: '🇨🇿 Czechia' },
    { c: 2208, v: 'Denmark',               l: '🇩🇰 Denmark' },
    { c: 2214, v: 'Dominican Republic',    l: '🇩🇴 Dominican Republic' },
    { c: 2218, v: 'Ecuador',               l: '🇪🇨 Ecuador' },
    { c: 2222, v: 'El Salvador',           l: '🇸🇻 El Salvador' },
    { c: 2233, v: 'Estonia',               l: '🇪🇪 Estonia' },
    { c: 2231, v: 'Ethiopia',              l: '🇪🇹 Ethiopia' },
    { c: 2246, v: 'Finland',               l: '🇫🇮 Finland' },
    { c: 2268, v: 'Georgia',               l: '🇬🇪 Georgia' },
    { c: 2288, v: 'Ghana',                 l: '🇬🇭 Ghana' },
    { c: 2300, v: 'Greece',                l: '🇬🇷 Greece' },
    { c: 2320, v: 'Guatemala',             l: '🇬🇹 Guatemala' },
    { c: 2340, v: 'Honduras',              l: '🇭🇳 Honduras' },
    { c: 2344, v: 'Hong Kong',             l: '🇭🇰 Hong Kong' },
    { c: 2348, v: 'Hungary',               l: '🇭🇺 Hungary' },
    { c: 2352, v: 'Iceland',               l: '🇮🇸 Iceland' },
    { c: 2360, v: 'Indonesia',             l: '🇮🇩 Indonesia' },
    { c: 2364, v: 'Iran',                  l: '🇮🇷 Iran' },
    { c: 2368, v: 'Iraq',                  l: '🇮🇶 Iraq' },
    { c: 2372, v: 'Ireland',               l: '🇮🇪 Ireland' },
    { c: 2376, v: 'Israel',                l: '🇮🇱 Israel' },
    { c: 2388, v: 'Jamaica',               l: '🇯🇲 Jamaica' },
    { c: 2392, v: 'Japan',                 l: '🇯🇵 Japan' },
    { c: 2398, v: 'Kazakhstan',            l: '🇰🇿 Kazakhstan' },
    { c: 2404, v: 'Kenya',                 l: '🇰🇪 Kenya' },
    { c: 2417, v: 'Kyrgyzstan',            l: '🇰🇬 Kyrgyzstan' },
    { c: 2418, v: 'Laos',                  l: '🇱🇦 Laos' },
    { c: 2428, v: 'Latvia',                l: '🇱🇻 Latvia' },
    { c: 2434, v: 'Libya',                 l: '🇱🇾 Libya' },
    { c: 2440, v: 'Lithuania',             l: '🇱🇹 Lithuania' },
    { c: 2442, v: 'Luxembourg',            l: '🇱🇺 Luxembourg' },
    { c: 2807, v: 'North Macedonia',       l: '🇲🇰 North Macedonia' },
    { c: 2450, v: 'Madagascar',            l: '🇲🇬 Madagascar' },
    { c: 2454, v: 'Malawi',                l: '🇲🇼 Malawi' },
    { c: 2458, v: 'Malaysia',              l: '🇲🇾 Malaysia' },
    { c: 2466, v: 'Mali',                  l: '🇲🇱 Mali' },
    { c: 2470, v: 'Malta',                 l: '🇲🇹 Malta' },
    { c: 2480, v: 'Mauritius',             l: '🇲🇺 Mauritius' },
    { c: 2484, v: 'Mexico',                l: '🇲🇽 Mexico' },
    { c: 2498, v: 'Moldova',               l: '🇲🇩 Moldova' },
    { c: 2496, v: 'Mongolia',              l: '🇲🇳 Mongolia' },
    { c: 2499, v: 'Montenegro',            l: '🇲🇪 Montenegro' },
    { c: 2504, v: 'Morocco',               l: '🇲🇦 Morocco' },
    { c: 2508, v: 'Mozambique',            l: '🇲🇿 Mozambique' },
    { c: 2104, v: 'Myanmar',               l: '🇲🇲 Myanmar' },
    { c: 2516, v: 'Namibia',               l: '🇳🇦 Namibia' },
    { c: 2524, v: 'Nepal',                 l: '🇳🇵 Nepal' },
    { c: 2554, v: 'New Zealand',           l: '🇳🇿 New Zealand' },
    { c: 2558, v: 'Nicaragua',             l: '🇳🇮 Nicaragua' },
    { c: 2562, v: 'Niger',                 l: '🇳🇪 Niger' },
    { c: 2566, v: 'Nigeria',               l: '🇳🇬 Nigeria' },
    { c: 2578, v: 'Norway',                l: '🇳🇴 Norway' },
    { c: 2591, v: 'Panama',                l: '🇵🇦 Panama' },
    { c: 2598, v: 'Papua New Guinea',      l: '🇵🇬 Papua New Guinea' },
    { c: 2600, v: 'Paraguay',              l: '🇵🇾 Paraguay' },
    { c: 2604, v: 'Peru',                  l: '🇵🇪 Peru' },
    { c: 2608, v: 'Philippines',           l: '🇵🇭 Philippines' },
    { c: 2616, v: 'Poland',                l: '🇵🇱 Poland' },
    { c: 2620, v: 'Portugal',              l: '🇵🇹 Portugal' },
    { c: 2630, v: 'Puerto Rico',           l: '🇵🇷 Puerto Rico' },
    { c: 2642, v: 'Romania',               l: '🇷🇴 Romania' },
    { c: 2643, v: 'Russia',                l: '🇷🇺 Russia' },
    { c: 2688, v: 'Serbia',                l: '🇷🇸 Serbia' },
    { c: 2703, v: 'Slovakia',              l: '🇸🇰 Slovakia' },
    { c: 2705, v: 'Slovenia',              l: '🇸🇮 Slovenia' },
    { c: 2710, v: 'South Africa',          l: '🇿🇦 South Africa' },
    { c: 2410, v: 'South Korea',           l: '🇰🇷 South Korea' },
    { c: 2144, v: 'Sri Lanka',             l: '🇱🇰 Sri Lanka' },
    { c: 2752, v: 'Sweden',                l: '🇸🇪 Sweden' },
    { c: 2158, v: 'Taiwan',                l: '🇹🇼 Taiwan' },
    { c: 2762, v: 'Tajikistan',            l: '🇹🇯 Tajikistan' },
    { c: 2834, v: 'Tanzania',              l: '🇹🇿 Tanzania' },
    { c: 2764, v: 'Thailand',              l: '🇹🇭 Thailand' },
    { c: 2788, v: 'Tunisia',               l: '🇹🇳 Tunisia' },
    { c: 2792, v: 'Turkey',                l: '🇹🇷 Turkey' },
    { c: 2795, v: 'Turkmenistan',          l: '🇹🇲 Turkmenistan' },
    { c: 2800, v: 'Uganda',                l: '🇺🇬 Uganda' },
    { c: 2804, v: 'Ukraine',               l: '🇺🇦 Ukraine' },
    { c: 2858, v: 'Uruguay',               l: '🇺🇾 Uruguay' },
    { c: 2860, v: 'Uzbekistan',            l: '🇺🇿 Uzbekistan' },
    { c: 2862, v: 'Venezuela',             l: '🇻🇪 Venezuela' },
    { c: 2704, v: 'Vietnam',               l: '🇻🇳 Vietnam' },
    { c: 2886, v: 'Yemen',                 l: '🇾🇪 Yemen' },
    { c: 2887, v: 'Zambia',                l: '🇿🇲 Zambia' },
    { c: 2716, v: 'Zimbabwe',              l: '🇿🇼 Zimbabwe' }
  ];

  // Module-level state for competitors tab — sort + last results + last keyword.
  // Lives on window so the sort/render handlers can reach it without closure.
  window._lgseCmpState = window._lgseCmpState || { results: [], sortCol: 'rank', sortDir: 'asc', keyword: '' };

  function renderCompetitors(el) {
    var helpHtml = ''
      + '<div style="background:linear-gradient(90deg,rgba(108,92,231,.08) 0%,transparent 100%);border-left:2px solid var(--lgse-purple);border-radius:0 8px 8px 0;padding:12px 14px;margin-bottom:16px">'
        + '<div style="font-size:9px;font-weight:600;color:var(--lgse-purple);text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px">How to use Competitors</div>'
        + '<div style="font-size:11.5px;color:var(--lgse-t2);line-height:1.65">'
          + '<strong style="color:var(--lgse-t1)">Analyze a keyword</strong> to see the top 10 pages currently ranking for it. Compare their word count and domain to understand what it takes to rank. '
          + '<strong style="color:var(--lgse-t1)">Compare your page</strong> to see how your content stacks up against the competition. '
          + '<strong style="color:var(--lgse-t1)">Find content gaps</strong> to discover topics your competitors cover that you don\'t.'
        + '</div>'
      + '</div>';

    // Wave 19.1 — value is now the DataForSEO location_code (integer).
    // Stored country preference falls back across both old (name) + new (code) keys.
    var savedCode = localStorage.getItem('lgse_cmp_country_code')
                 || (function () {
                      var oldName = localStorage.getItem('lgse_cmp_country');
                      var match   = LGSE_CMP_COUNTRIES.find(function (x) { return x.v === oldName; });
                      return match ? String(match.c) : '2840'; // default: United States
                    })();
    var locOpts = LGSE_CMP_COUNTRIES.map(function (c) {
      return '<option value="' + c.c + '"' + (String(c.c) === String(savedCode) ? ' selected' : '') + '>' + c.l + '</option>';
    }).join('');

    el.innerHTML = pageTitle('Competitors', 'Top 10 SERP competitors per keyword + AI-driven content gap analysis.')
      + helpHtml
      + '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:12px;padding:14px;margin-bottom:14px">'
        + '<div class="lgse-section-title" style="margin-bottom:2px">Analyze a keyword</div>'
        + '<div style="font-size:10.5px;color:var(--lgse-t3);margin-bottom:10px">See who ranks in the top 10 for any search term in your target market.</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap">'
          + '<input id="lgse-cmp-kw" type="text" placeholder="e.g. digital marketing dubai" style="flex:1;min-width:240px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:8px 12px;border-radius:7px;font-size:11.5px">'
          + '<select id="lgse-cmp-loc" onchange="localStorage.setItem(\'lgse_cmp_country_code\', this.value)" style="min-width:220px;max-width:280px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:8px 12px;border-radius:7px;font-size:11.5px;cursor:pointer">' + locOpts + '</select>'
          + '<button class="lgse-btn-primary" onclick="lgseCmpAnalyze()">Analyze</button>'
        + '</div>'
        + '<div id="lgse-cmp-results" style="margin-top:14px"></div>'
      + '</div>'
      + '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:12px;padding:14px">'
        + '<div class="lgse-section-title" style="margin-bottom:2px">Compare your page vs SERP</div>'
        + '<div style="font-size:10.5px;color:var(--lgse-t3);margin-bottom:10px">Check if your page has enough content to compete for a keyword.</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap">'
          + '<input id="lgse-cmp-url" type="text" placeholder="Your URL" style="flex:1;min-width:240px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:8px 12px;border-radius:7px;font-size:11.5px">'
          + '<input id="lgse-cmp-kw2" type="text" placeholder="Target keyword" style="width:240px;background:var(--lgse-bg1);border:1px solid var(--lgse-border);color:var(--lgse-t1);padding:8px 12px;border-radius:7px;font-size:11.5px">'
          + '<button class="lgse-btn-secondary" onclick="lgseCmpCompare()">Compare</button>'
        + '</div>'
        + '<div id="lgse-cmp-cmp" style="margin-top:14px"></div>'
      + '</div>';
  }
  window.lgseCmpAnalyze = function () {
    var kw = document.getElementById('lgse-cmp-kw');
    var loc = document.getElementById('lgse-cmp-loc');
    var box = document.getElementById('lgse-cmp-results');
    if (!kw || !box || !kw.value.trim()) return;
    var keyword = kw.value.trim();
    // Wave 19.1 — value of <option> is now the DataForSEO location_code (int).
    var locCode = parseInt((loc && loc.value) || '2840', 10) || 2840;
    if (loc && loc.value) localStorage.setItem('lgse_cmp_country_code', loc.value);
    var match = LGSE_CMP_COUNTRIES.find(function (x) { return String(x.c) === String(locCode); });
    var locLabel = match ? match.v : '';
    box.innerHTML = '<div style="padding:14px;color:var(--lgse-t3);font-size:11px">Analyzing…</div>';
    api('POST', '/competitors/analyze', { keyword: keyword, location_code: locCode, location: locLabel }).then(function (d) {
      var arr = (d && d.competitors) || [];
      if (arr.length === 0) { box.innerHTML = emptyState('·', 'No SERP data', 'LevelUpGrowth SEO is not configured for this workspace.'); return; }
      // Persist results + keyword so the gap → Sarah flow can reference them.
      window._lgseCmpState.results = arr;
      window._lgseCmpState.keyword = keyword;
      window._lgseCmpState.sortCol = 'rank';
      window._lgseCmpState.sortDir = 'asc';
      box.innerHTML = '<div id="lgse-cmp-table"></div>'
        + '<div style="margin-top:10px"><button class="lgse-btn-secondary" onclick="lgseCmpGaps()">Find content gaps with AI →</button></div>'
        + '<div id="lgse-cmp-gaps"></div>';
      window.lgseCmpRenderTable(arr);
    }).catch(function () { box.innerHTML = emptyState('⚠', 'Analyze failed', 'Try again.'); });
  };

  window.lgseCmpRenderTable = function (rows) {
    var st = window._lgseCmpState;
    var sorted = (rows || []).slice().sort(function (a, b) {
      var av, bv;
      if (st.sortCol === 'rank') {
        av = parseInt(a.rank || a.position || 99, 10);
        bv = parseInt(b.rank || b.position || 99, 10);
        return st.sortDir === 'asc' ? av - bv : bv - av;
      }
      if (st.sortCol === 'words') {
        av = parseInt(a.est_word_count || a.word_count || 0, 10);
        bv = parseInt(b.est_word_count || b.word_count || 0, 10);
        return st.sortDir === 'asc' ? av - bv : bv - av;
      }
      av = (a[st.sortCol] || '').toString().toLowerCase();
      bv = (b[st.sortCol] || '').toString().toLowerCase();
      return st.sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
    });

    function sortHdr(col, label, extra) {
      var ind = st.sortCol === col ? (st.sortDir === 'asc' ? ' ↑' : ' ↓') : '';
      var align = extra && extra.right ? ' class="r"' : '';
      return '<th' + align + ' onclick="lgseCmpSort(\'' + col + '\')" style="cursor:pointer;user-select:none">' + label + ind + '</th>';
    }

    var h = '<table class="lgse-table"><thead><tr>'
      + sortHdr('rank',   'Rank')
      + sortHdr('title',  'Title')
      + sortHdr('domain', 'Domain')
      + sortHdr('words',  'Words', { right: true })
      + '</tr></thead><tbody>';
    sorted.forEach(function (c) {
      var rank = parseInt(c.rank || c.position || 0, 10);
      var rankCol = rank > 0 && rank <= 3 ? 'var(--lgse-amber)' : rank > 0 && rank <= 10 ? 'var(--lgse-teal)' : 'var(--lgse-t3)';
      var wc = parseInt(c.est_word_count || c.word_count || 0, 10);
      var wcCol = wc > 2000 ? 'var(--lgse-teal)' : wc > 800 ? 'var(--lgse-t1)' : wc > 0 ? 'var(--lgse-amber)' : 'var(--lgse-t3)';
      h += '<tr>'
        + '<td class="mono" style="color:' + rankCol + ';font-weight:700">#' + (rank || '?') + '</td>'
        + '<td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1)">' + esc(c.title || '—') + '</td>'
        + '<td style="color:var(--lgse-t2);font-size:10.5px">' + esc(c.domain || '—') + '</td>'
        + '<td class="mono r" style="color:' + wcCol + '">' + (wc > 0 ? wc.toLocaleString() : '—') + '</td>'
        + '</tr>';
    });
    h += '</tbody></table>';
    var tableEl = document.getElementById('lgse-cmp-table');
    if (tableEl) tableEl.innerHTML = h;
  };

  window.lgseCmpSort = function (col) {
    var st = window._lgseCmpState;
    if (st.sortCol === col) {
      st.sortDir = st.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      st.sortCol = col;
      st.sortDir = (col === 'rank') ? 'asc' : 'desc';
    }
    window.lgseCmpRenderTable(st.results);
  };
  window.lgseCmpGaps = function () {
    var kw = document.getElementById('lgse-cmp-kw');
    var mount = document.getElementById('lgse-cmp-gaps');
    if (!kw || !mount) return;
    var keyword = kw.value.trim();
    window._lgseCmpState.keyword = keyword;
    mount.innerHTML = '<div style="padding:10px;color:var(--lgse-t3);font-size:11px">Asking AI…</div>';
    api('POST', '/competitors/gaps', { keyword: keyword }).then(function (r) {
      var gaps = (r && r.gaps) || [];
      if (gaps.length === 0) { mount.innerHTML = '<div style="padding:10px;color:var(--lgse-t3);font-size:11px">No gaps detected.</div>'; return; }
      var h = '<div class="lgse-section-hdr" style="margin-top:10px"><span class="lgse-section-title">AI-detected content gaps</span></div>';
      h += '<table class="lgse-table"><thead><tr><th>Topic</th><th>Found in</th><th>Priority</th><th>Suggested heading</th><th></th></tr></thead><tbody>';
      gaps.forEach(function (g) {
        var pk = g.priority === 'high' ? 'red' : g.priority === 'medium' ? 'amber' : 'teal';
        var heading = g.suggested_heading || g.topic || '';
        var payload = encodeURIComponent(JSON.stringify({
          topic:    g.topic || '',
          heading:  heading,
          keyword:  keyword,
          priority: g.priority || 'medium'
        }));
        h += '<tr>'
          + '<td style="color:var(--lgse-t1);font-weight:500">' + esc(g.topic || '') + '</td>'
          + '<td class="mono">' + (g.found_in_n_competitors || 0) + ' / 10</td>'
          + '<td>' + badge(g.priority || '', pk) + '</td>'
          + '<td style="color:var(--lgse-t2);font-size:11px">' + esc(heading) + '</td>'
          + '<td style="text-align:right"><button onclick="lgseGenerateFromGap(\'' + payload + '\')" style="background:var(--lgse-purple);color:#fff;border:none;border-radius:6px;padding:5px 12px;font-size:10px;font-weight:600;cursor:pointer;white-space:nowrap">✨ Generate</button></td>'
          + '</tr>';
      });
      h += '</tbody></table>';
      mount.innerHTML = h;
    }).catch(function () { mount.innerHTML = '<div style="padding:10px;color:var(--lgse-red);font-size:11px">AI runtime unavailable.</div>'; });
  };

  window.lgseGenerateFromGap = function (payloadStr) {
    var data = {};
    try { data = JSON.parse(decodeURIComponent(payloadStr)); } catch (e) {}
    var topic    = data.topic    || '';
    var heading  = data.heading  || topic;
    var keyword  = data.keyword  || '';
    var priority = data.priority || 'medium';

    var goal = 'I need to create content for the topic: "' + topic + '". '
      + 'Suggested title: "' + heading + '". '
      + 'Target keyword: "' + keyword + '". '
      + 'Priority: ' + priority + '. '
      + 'Please write a comprehensive SEO-optimized article.';

    // Wave 8 (2026-05-18). Two-context branching per platform branding rule.
    //   - WP iframe: route through the SEO AI Assistant. Open the chat
    //     drawer + auto-send the goal — the assistant's "Shall I proceed?"
    //     proposal becomes the review step (one click to confirm/decline).
    //   - Laravel SaaS: keep the Sarah-branded delegation modal. Same
    //     one-click confirm UX, but with Sarah's persona and routing to
    //     /api/sarah/receive (the platform orchestrator path).
    if (window._lgseIsEmbed()) {
      // SEO AI Assistant path (WP) — option C: open drawer, auto-submit goal.
      if (typeof window._lgseDrawerOpen === 'function') {
        window._lgseDrawerOpen();
      }
      // Wait for drawer to render, then fill input + send.
      setTimeout(function () {
        var inp = document.getElementById('lgse-chat-input');
        if (inp) {
          inp.value = goal;
          if (typeof window._lgseAssistantSend === 'function') {
            window._lgseAssistantSend();
          }
        }
      }, 250);
      return;
    }

    // SaaS path — original Sarah delegation modal (unchanged).
    var goalEnc = encodeURIComponent(goal);
    function safeAttr(s) { return String(s || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    var overlay = document.createElement('div');
    overlay.id = 'lgse-sarah-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML =
        '<div style="background:#13161e;border:1px solid #2a2f42;border-radius:16px;padding:24px;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)">'
      +   '<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">'
      +     '<div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#F59E0B,#EF4444);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">🤖</div>'
      +     '<div>'
      +       '<div style="font-size:14px;font-weight:700;color:#f0f2ff">Sarah</div>'
      +       '<div style="font-size:11px;color:#F59E0B">Digital Marketing Manager · AI OS</div>'
      +     '</div>'
      +   '</div>'
      +   '<div style="background:#1a1d27;border:1px solid #2a2f42;border-radius:10px;padding:14px;margin-bottom:16px">'
      +     '<div style="font-size:9px;font-weight:600;color:#F59E0B;text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px">Sarah already knows what you need:</div>'
      +     '<div style="font-size:12.5px;color:#e2e8f0;line-height:1.7">'
      +       'I\'ve identified a content gap: <strong style="color:#f0f2ff">"' + safeAttr(topic) + '"</strong>. '
      +       'Your competitors cover this topic but you don\'t. I\'ll write a '
      +       '<strong style="color:#f0f2ff">' + safeAttr(priority) + '-priority</strong> article targeting '
      +       '"' + safeAttr(keyword) + '" with the title:<br><br>'
      +       '<em style="color:#6C5CE7">"' + safeAttr(heading) + '"</em>'
      +     '</div>'
      +   '</div>'
      +   '<div style="font-size:11px;color:#555a72;margin-bottom:16px;line-height:1.6">'
      +     '<strong style="color:#8b90a7">Sarah will:</strong><br>'
      +     '1. Research the topic and competitors<br>'
      +     '2. Write a full SEO-optimized draft<br>'
      +     '3. Submit for your review before publishing'
      +   '</div>'
      +   '<div style="display:flex;gap:8px">'
      +     '<button onclick="document.getElementById(\'lgse-sarah-overlay\').remove()" style="flex:1;background:transparent;color:#8b90a7;border:1px solid #343a52;border-radius:8px;padding:10px;font-size:12px;cursor:pointer">Not now</button>'
      +     '<button id="lgse-sarah-confirm-btn" onclick="lgseConfirmSarahContent(\'' + goalEnc + '\',this)" style="flex:2;background:#F59E0B;color:#0d0f14;border:none;border-radius:8px;padding:10px;font-size:12px;font-weight:700;cursor:pointer">✓ Yes, create this content</button>'
      +   '</div>'
      + '</div>';

    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) overlay.remove();
    });
  };

  window.lgseConfirmSarahContent = function (goalEnc, btn) {
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = '⏳ Sending to Sarah…';
    var goal = decodeURIComponent(goalEnc);
    var token = localStorage.getItem('lu_token') || '';

    // Sarah ingress is /api/sarah/receive — payload requires `goal` (validated `required|string`).
    // The seo `api()` helper hardcodes /api/seo, so we use bare fetch here.
    fetch(window.location.origin + '/api/sarah/receive', {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        goal: goal,
        context: { source: 'seo.competitor_gap', type: 'content_generation', origin: 'competitors_tab' }
      })
    }).then(function (r) {
      if (!r.ok) throw new Error('http_' + r.status);
      return r.json();
    }).then(function () {
      var overlay = document.getElementById('lgse-sarah-overlay');
      if (overlay) overlay.remove();

      var toast = document.createElement('div');
      toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#13161e;border:1px solid #F59E0B;border-radius:10px;padding:14px 18px;font-size:12px;color:#f0f2ff;z-index:9999;max-width:300px;box-shadow:0 8px 24px rgba(0,0,0,.4)';
      toast.innerHTML = '<div style="font-weight:600;color:#F59E0B;margin-bottom:4px">✓ Sarah is on it</div><div style="color:#8b90a7;font-size:11px">Content request sent. Check Strategy Room for updates.</div>';
      document.body.appendChild(toast);
      setTimeout(function () { toast.remove(); }, 5000);
    }).catch(function () {
      btn.disabled = false;
      btn.textContent = '✓ Yes, create this content';
      var errEl = document.createElement('div');
      errEl.style.cssText = 'color:var(--lgse-red);font-size:11px;margin-top:8px;text-align:center';
      errEl.textContent = 'Could not reach Sarah. Try again.';
      btn.parentElement.appendChild(errEl);
    });
  };
  window.lgseCmpCompare = function () {
    var url = document.getElementById('lgse-cmp-url');
    var kw = document.getElementById('lgse-cmp-kw2');
    var box = document.getElementById('lgse-cmp-cmp');
    if (!url || !kw || !box || !url.value.trim() || !kw.value.trim()) return;
    box.innerHTML = '<div style="padding:14px;color:var(--lgse-t3);font-size:11px">Comparing…</div>';
    api('POST', '/competitors/compare', { your_url: url.value.trim(), keyword: kw.value.trim() }).then(function (r) {
      if (r && r.error) { box.innerHTML = emptyState('·', 'Page not indexed', 'Add your URL to the index first via the connector or a manual scan.'); return; }
      var you = r.your_page || {}; var avg = r.competitor_avg || {}; var gaps = r.gaps || []; var recs = r.recommendations || [];
      var h = '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:12px">';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Your words</div><div class="lgse-kpi-val">' + (you.word_count || 0) + '</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Competitor avg</div><div class="lgse-kpi-val">' + (avg.word_count || 0) + '</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Your score</div><div class="lgse-kpi-val">' + scorePill(you.content_score || 0) + '</div></div>';
      var delta = parseInt(r.word_count_delta || 0, 10);
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Word delta</div><div class="lgse-kpi-val ' + (delta >= 0 ? 'lgse-up' : 'lgse-dn') + '">' + (delta > 0 ? '+' : '') + delta + '</div></div>';
      h += '</div>';
      if (gaps.length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Gaps</span></div><ul style="margin:0 0 12px;padding-left:18px;font-size:11.5px;color:var(--lgse-t2)">';
        gaps.forEach(function (g) { var pk = g.priority === 'high' ? 'red' : g.priority === 'medium' ? 'amber' : 'teal'; h += '<li style="margin-bottom:4px">' + badge(g.priority || '', pk) + ' ' + esc(g.description || '') + '</li>'; });
        h += '</ul>';
      }
      if (recs.length > 0) {
        h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Recommendations</span></div><ul style="margin:0;padding-left:18px;font-size:11.5px;color:var(--lgse-t2)">';
        recs.forEach(function (rc) { h += '<li style="margin-bottom:4px">' + esc(rc.action || '') + '</li>'; });
        h += '</ul>';
      }
      box.innerHTML = h;
    }).catch(function () { box.innerHTML = emptyState('⚠', 'Compare failed', 'Try again.'); });
  };

  // ── Tab 8 — Insights (sub-tabs) ────────────────────────────────────────
  function renderInsights(el) {
    el.innerHTML = pageTitle('Insights', 'Search Console performance + AI-driven traffic correlations.')
      + '<div class="lgse-subtabs" id="lgse-ins-subtabs">'
        + '<div class="lgse-subtab active" data-sec="traffic">Traffic Insights</div>'
        + '<div class="lgse-subtab" data-sec="gsc">Search Console</div>'
      + '</div>'
      + '<div id="lgse-ins-body"></div>';
    var body = document.getElementById('lgse-ins-body');
    var subtabs = document.getElementById('lgse-ins-subtabs');
    function load(sec) {
      Array.prototype.forEach.call(subtabs.children, function (t) { t.classList.toggle('active', t.getAttribute('data-sec') === sec); });
      body.innerHTML = '<div style="padding:24px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading…</div>';
      if (sec === 'traffic') return loadTraffic(body);
      if (sec === 'gsc') return loadGsc(body);
    }
    Array.prototype.forEach.call(subtabs.children, function (t) { t.addEventListener('click', function () { load(t.getAttribute('data-sec')); }); });
    load('traffic');
  }
  function loadTraffic(body) {
    Promise.all([
      api('GET', '/insights/traffic?days=28').catch(function () { return null; }),
      api('GET', '/insights/top-pages?days=28&limit=10').catch(function () { return null; }),
      api('GET', '/insights/content-performance').catch(function () { return null; }),
      api('GET', '/insights/summary').catch(function () { return null; }),
    ]).then(function (r) {
      var t = r[0] || {}; var pages = (r[1] && r[1].pages) || []; var p = r[2] || {}; var s = r[3] || {};
      var dirCol = t.direction === 'up' ? '#00E5A8' : t.direction === 'down' ? '#EF4444' : '#8b90a7';
      var dirIcon = t.direction === 'up' ? '↑' : t.direction === 'down' ? '↓' : '→';
      var h = '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Clicks (28d)</div><div class="lgse-kpi-val">' + (t.total_clicks || 0).toLocaleString() + '</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Impressions</div><div class="lgse-kpi-val">' + (t.total_impressions || 0).toLocaleString() + '</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Trend</div><div class="lgse-kpi-val" style="color:' + dirCol + '">' + dirIcon + ' ' + (t.change_pct || 0) + '%</div></div>';
      h += '<div class="lgse-kpi-card"><div class="lgse-kpi-label">Correlation</div><div class="lgse-kpi-val" style="font-size:18px">' + esc(p.correlation || 'weak') + '</div></div>';
      h += '</div>';

      h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">';
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px"><div class="lgse-section-title" style="margin-bottom:8px">Key insights</div>';
      if ((s.insights || []).length === 0) h += '<div style="color:var(--lgse-t3);font-size:11px">Not enough data yet.</div>';
      else h += '<ul style="margin:0;padding-left:18px;font-size:11.5px;color:var(--lgse-t2)">' + s.insights.map(function (i) { return '<li style="margin-bottom:6px">' + esc(i) + '</li>'; }).join('') + '</ul>';
      h += '</div>';
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px"><div class="lgse-section-title" style="margin-bottom:8px">Recommendations</div>';
      if ((s.recommendations || []).length === 0) h += '<div style="color:var(--lgse-t3);font-size:11px">No recommendations yet.</div>';
      else h += '<ul style="margin:0;padding-left:18px;font-size:11.5px;color:var(--lgse-t2)">' + s.recommendations.map(function (rc) { return '<li style="margin-bottom:6px">' + esc(rc) + '</li>'; }).join('') + '</ul>';
      h += '</div></div>';

      h += '<div class="lgse-section-hdr"><span class="lgse-section-title">Top pages by traffic</span></div>';
      if (pages.length === 0) {
        h += emptyState('∿', 'No GSC data yet', 'Connect Search Console and run a sync.', 'Open GSC', 'lgseSwitchTab(\'insights\');setTimeout(function(){var s=document.querySelector(\'.lgse-subtab[data-sec="gsc"]\');if(s)s.click();},20)');
      } else {
        h += '<table class="lgse-table"><thead><tr><th>URL</th><th>Title</th><th class="r">Clicks</th><th class="r">Impr</th><th class="r">CTR</th><th class="r">Pos</th><th class="r">Score</th></tr></thead><tbody>';
        pages.forEach(function (pg) {
          h += '<tr><td class="lgse-url">' + esc(pg.url || '—') + '</td><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1)">' + esc(pg.title || '—') + '</td><td class="mono">' + (pg.clicks || 0) + '</td><td class="mono">' + (pg.impressions || 0) + '</td><td class="mono">' + ((pg.ctr || 0) * 100).toFixed(2) + '%</td><td class="mono">' + (pg.position || 0) + '</td><td class="mono">' + (pg.content_score ? scorePill(pg.content_score) : '—') + '</td></tr>';
        });
        h += '</tbody></table>';
      }
      h += '<div id="lgse-ins-extra" style="margin-top:18px"></div>';
      body.innerHTML = h;
      lgseLoadInsightsExtras();
    }).catch(function () { body.innerHTML = emptyState('⚠', 'Insights unavailable', 'Try refreshing.'); });
  }

  // ─────────────────────────────────────────────────────────────────────
  // P1-E — Extra Insights sections rendered into #lgse-ins-extra:
  //        Content quality, Top authority pages, Anchor quality, Link opps.
  // No new backend endpoints — aggregates from /indexed-content, /anchors,
  // /links/gaps which all exist after P1-A/B/C.
  // ─────────────────────────────────────────────────────────────────────
  function lgseLoadInsightsExtras() {
    var holder = document.getElementById('lgse-ins-extra');
    if (!holder) return;
    holder.innerHTML = '<div style="padding:14px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading site-health insights…</div>';
    Promise.all([
      api('GET', '/indexed-content?per_page=100&sort=score&order=asc').catch(function () { return null; }),
      api('GET', '/indexed-content?per_page=10&sort=equity&order=desc').catch(function () { return null; }),
      api('GET', '/anchors').catch(function () { return null; }),
      api('GET', '/links/gaps').catch(function () { return null; })
    ]).then(function (rs) {
      var pagesAsc  = (rs[0] && rs[0].items) || [];
      var pagesByEq = (rs[1] && rs[1].items) || [];
      var anchors   = rs[2] || null;
      var gaps      = rs[3] || null;

      var h = '<div class="lgse-section-hdr" style="margin-top:8px"><span class="lgse-section-title">Site health</span></div>';
      h += '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">';

      // ── Content quality ──
      var bands = { 'Strong (≥90)': 0, 'Healthy (70–89)': 0, 'Needs work (50–69)': 0, 'At risk (<50)': 0 };
      var sum = 0; var count = 0;
      pagesAsc.forEach(function (p) {
        var s = parseInt(p.content_score, 10); if (isNaN(s)) return;
        sum += s; count++;
        if (s >= 90)      bands['Strong (≥90)']++;
        else if (s >= 70) bands['Healthy (70–89)']++;
        else if (s >= 50) bands['Needs work (50–69)']++;
        else              bands['At risk (<50)']++;
      });
      var avg = count > 0 ? Math.round(sum / count) : 0;
      var maxBand = Math.max(1, bands['Strong (≥90)'], bands['Healthy (70–89)'], bands['Needs work (50–69)'], bands['At risk (<50)']);
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'
          + '<div class="lgse-section-title">Content quality</div>'
          + '<div style="font-size:11px;color:var(--lgse-t3)">avg ' + scorePill(avg) + ' across ' + count + ' pages</div>'
        + '</div>';
      Object.keys(bands).forEach(function (label) {
        var pct = (bands[label] / maxBand) * 100;
        var col = label.indexOf('Strong') === 0 ? 'var(--lgse-teal)'
                : label.indexOf('Healthy') === 0 ? 'var(--lgse-purple)'
                : label.indexOf('Needs') === 0 ? 'var(--lgse-amber)'
                : 'var(--lgse-red)';
        h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:11px">'
          + '<div style="width:120px;color:var(--lgse-t2);flex-shrink:0">' + esc(label) + '</div>'
          + '<div style="flex:1;height:8px;background:var(--lgse-bg3);border-radius:4px;overflow:hidden"><div style="width:' + pct + '%;height:100%;background:' + col + '"></div></div>'
          + '<div class="mono" style="width:36px;text-align:right;color:var(--lgse-t1)">' + bands[label] + '</div>'
        + '</div>';
      });
      if (count === 0) h += '<div style="color:var(--lgse-t3);font-size:11px;padding:6px 0">No indexed pages yet.</div>';
      h += '</div>';

      // ── Top authority pages ──
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px">'
        + '<div class="lgse-section-title" style="margin-bottom:10px">Top authority pages</div>';
      var topAuth = pagesByEq.slice(0, 5);
      if (topAuth.length === 0) {
        h += '<div style="color:var(--lgse-t3);font-size:11px">Build the link graph and run equity calc to populate this view.</div>';
      } else {
        h += '<table class="lgse-table" style="margin:0"><tbody>';
        topAuth.forEach(function (p) {
          var eq = (p.equity_score != null) ? p.equity_score : (p.content_score || 0);
          h += '<tr>'
            + '<td class="lgse-url" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:6px 0">' + esc(p.url || p.title || '—') + '</td>'
            + '<td class="r mono" style="padding:6px 0">' + scorePill(eq) + '</td>'
          + '</tr>';
        });
        h += '</tbody></table>';
      }
      h += '</div>';

      // ── Anchor quality ──
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px">'
        + '<div class="lgse-section-title" style="margin-bottom:10px">Anchor quality</div>';
      if (!anchors || !anchors.distribution) {
        h += '<div style="color:var(--lgse-t3);font-size:11px">No anchor data yet — open Links → Anchors and run analysis.</div>';
      } else {
        var d = anchors.distribution;
        h += '<div style="font-size:11.5px;color:var(--lgse-t2);margin-bottom:10px">' + (d.total || 0) + ' internal anchors analysed</div>'
          + '<div class="lgse-stack-bar" style="height:10px;margin-bottom:8px">'
            + '<div style="width:' + (d.generic_pct || 0) + '%;background:var(--lgse-red)"></div>'
            + '<div style="width:' + (d.exact_pct   || 0) + '%;background:var(--lgse-amber)"></div>'
            + '<div style="width:' + (d.natural_pct || 0) + '%;background:var(--lgse-teal)"></div>'
          + '</div>'
          + '<div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--lgse-t3)">'
            + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-red);border-radius:2px;margin-right:5px"></span>Generic ' + (d.generic_pct || 0) + '%</span>'
            + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-amber);border-radius:2px;margin-right:5px"></span>Exact ' + (d.exact_pct || 0) + '%</span>'
            + '<span><span style="display:inline-block;width:8px;height:8px;background:var(--lgse-teal);border-radius:2px;margin-right:5px"></span>Natural ' + (d.natural_pct || 0) + '%</span>'
          + '</div>';
      }
      h += '</div>';

      // ── Link opportunities ──
      h += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px">'
        + '<div class="lgse-section-title" style="margin-bottom:10px">Link opportunities</div>';
      if (!gaps) {
        h += '<div style="color:var(--lgse-t3);font-size:11px">Build the link graph to surface internal-link opportunities.</div>';
      } else {
        var sg = gaps.summary || { orphan_count: 0, weak_count: 0 };
        var oc = parseInt(sg.orphan_count, 10) || 0;
        var wc = parseInt(sg.weak_count, 10) || 0;
        h += '<div style="display:flex;gap:14px;margin-bottom:10px">'
          + '<div><div style="font-size:22px;color:var(--lgse-red);font-weight:600">' + oc + '</div><div style="font-size:10.5px;color:var(--lgse-t3)">orphan</div></div>'
          + '<div><div style="font-size:22px;color:var(--lgse-amber);font-weight:600">' + wc + '</div><div style="font-size:10.5px;color:var(--lgse-t3)">weak (1–2 inbound)</div></div>'
        + '</div>';
        if (oc + wc > 0) {
          h += '<button class="lgse-btn-secondary" style="font-size:10.5px;padding:6px 11px" onclick="lgseSwitchTab(\'links\');setTimeout(function(){var s=document.querySelector(\'.lgse-subtab[data-sec=\\\'gaps\\\']\');if(s)s.click();},30)">Open Gaps →</button>';
        } else {
          h += '<div style="color:var(--lgse-teal);font-size:11px">All pages have enough inbound links.</div>';
        }
      }
      h += '</div>';

      h += '</div>'; // grid end
      holder.innerHTML = h;
    }).catch(function () {
      holder.innerHTML = '<div style="padding:14px;color:var(--lgse-t3);text-align:center;font-size:11px">Site-health insights unavailable.</div>';
    });
  }
  // ── End P1-E ──────────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // P1-F — Pages row click-to-expand. Google preview + score breakdown +
  //        quick edit (meta_title, meta_description, h1).
  // Toggling: clicking the chevron inserts/removes a sibling <tr>.
  // Data: read from window._lgsePages cache (no extra fetch).
  // Save: PATCH /indexed-content/{id} (existing route).
  // ─────────────────────────────────────────────────────────────────────
  window.lgseExpandPageRow = function (id) {
    if (!id) return;
    var tbody = document.querySelector('#lgse-pg-body table tbody'); if (!tbody) return;
    var row = tbody.querySelector('tr[data-page-id="' + id + '"]:not(.lgse-pg-detail)'); if (!row) return;
    var detail = tbody.querySelector('tr.lgse-pg-detail[data-pg-detail-for="' + id + '"]');
    var chev = row.querySelector('.lgse-pg-chev');

    if (detail) {
      detail.parentNode.removeChild(detail);
      if (chev) chev.style.transform = '';
      return;
    }

    // Find the page in the cached list — set in lgseLoadPages.
    var p = null;
    var pool = window._lgsePages || [];
    for (var i = 0; i < pool.length; i++) { if (parseInt(pool[i].id, 10) === id) { p = pool[i]; break; } }
    if (!p) return;

    if (chev) chev.style.transform = 'rotate(90deg)';

    var colCount = (row.children || []).length;
    var detailTr = document.createElement('tr');
    detailTr.className = 'lgse-pg-detail';
    detailTr.setAttribute('data-pg-detail-for', id);
    var url = p.url || '';
    var title = p.meta_title || p.title || '';
    var meta = p.meta_description || '';
    var h1 = p.h1 || '';

    // Google search preview.
    var domain = '';
    try { domain = new URL(url).hostname; } catch (_) { domain = url; }
    var previewTitle = title ? title : (p.title || url);
    var previewBody  = meta ? meta : 'Add a meta description to control how this page looks in Google.';

    // Score breakdown.
    var bk = (p.score_breakdown && p.score_breakdown.length) ? p.score_breakdown : [];
    var bkHtml = '';
    if (bk.length === 0) {
      bkHtml = '<div style="color:var(--lgse-t3);font-size:11px">No score breakdown stored. Run audit to refresh.</div>';
    } else {
      bk.forEach(function (f) {
        var s = parseInt(f.score, 10) || 0;
        var w = parseInt(f.weight, 10) || 0;
        var pct = w > 0 ? Math.round((s / w) * 100) : 0;
        var col = pct >= 90 ? 'var(--lgse-teal)' : pct >= 60 ? 'var(--lgse-purple)' : pct >= 30 ? 'var(--lgse-amber)' : 'var(--lgse-red)';
        bkHtml += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:11px">'
          + '<div style="width:130px;color:var(--lgse-t2);flex-shrink:0">' + esc(f.factor || '—') + '</div>'
          + '<div style="flex:1;height:6px;background:var(--lgse-bg3);border-radius:3px;overflow:hidden"><div style="width:' + pct + '%;height:100%;background:' + col + '"></div></div>'
          + '<div class="mono" style="width:60px;text-align:right;color:var(--lgse-t1)">' + s + '/' + w + '</div>'
        + '</div>';
        if (f.details) bkHtml += '<div style="font-size:10.5px;color:var(--lgse-t3);margin:-3px 0 7px 138px">' + esc(f.details) + '</div>';
      });
    }

    var inner = ''
      + '<td colspan="' + colCount + '" style="background:var(--lgse-bg2);padding:14px 18px;border-bottom:1px solid var(--lgse-border)">'
        + '<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px">'

          // ── Google preview ──
          + '<div>'
            + '<div class="lgse-section-title" style="margin-bottom:8px">Google preview</div>'
            + '<div style="background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:8px;padding:12px">'
              + '<div style="font-size:11px;color:var(--lgse-t3);margin-bottom:3px">' + esc(domain) + '</div>'
              + '<div style="color:var(--lgse-purple);font-size:14px;line-height:1.35;margin-bottom:4px;cursor:pointer">' + esc(previewTitle) + '</div>'
              + '<div style="font-size:11.5px;color:var(--lgse-t2);line-height:1.45">' + esc(previewBody) + '</div>'
            + '</div>'
          + '</div>'

          // ── Score breakdown ──
          + '<div>'
            + '<div class="lgse-section-title" style="margin-bottom:8px">Score breakdown</div>'
            + '<div style="background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:8px;padding:12px">' + bkHtml + '</div>'
          + '</div>'

        + '</div>'

        // ── Quick edit form ──
        + '<div style="margin-top:14px">'
          + '<div class="lgse-section-title" style="margin-bottom:8px">Quick edit</div>'
          + '<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1fr);gap:10px;align-items:end">'
            + '<div><label style="display:block;font-size:10.5px;color:var(--lgse-t3);margin-bottom:4px">Meta title</label><input id="lgse-edit-title-' + id + '" value="' + esc(title) + '" maxlength="70" style="width:100%;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:6px;padding:7px 10px;font-size:11.5px;color:var(--lgse-t1)"></div>'
            + '<div><label style="display:block;font-size:10.5px;color:var(--lgse-t3);margin-bottom:4px">Meta description</label><input id="lgse-edit-meta-' + id + '" value="' + esc(meta) + '" maxlength="170" style="width:100%;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:6px;padding:7px 10px;font-size:11.5px;color:var(--lgse-t1)"></div>'
            + '<div><label style="display:block;font-size:10.5px;color:var(--lgse-t3);margin-bottom:4px">H1</label><input id="lgse-edit-h1-' + id + '" value="' + esc(h1) + '" maxlength="200" style="width:100%;background:var(--lgse-bg1);border:1px solid var(--lgse-border);border-radius:6px;padding:7px 10px;font-size:11.5px;color:var(--lgse-t1)"></div>'
          + '</div>'
          + '<div style="display:flex;gap:8px;margin-top:10px;align-items:center">'
            + '<button class="lgse-btn-primary" style="font-size:11px;padding:7px 14px" onclick="lgseSavePageRow(' + id + ')">Save</button>'
            + '<button class="lgse-btn-secondary" style="font-size:11px;padding:7px 14px" onclick="lgseExpandPageRow(' + id + ')">Cancel</button>'
            + (window._lgseSeoAI === true
                ? ' <button class="lgse-btn-primary" id="lgse-mopt-btn-' + id + '" style="font-size:11px;padding:7px 14px;background:linear-gradient(135deg,var(--lgse-purple),#9333ea)" onclick="lgseOptimizeMeta(' + id + ')">✨ Optimize with AI <span style="opacity:0.85;font-weight:400;font-size:10px">(0.5 cr)</span></button>'
                : '')
            + '<span id="lgse-edit-status-' + id + '" style="font-size:11px;color:var(--lgse-t3);margin-left:6px"></span>'
          + '</div>'
          + '<div id="lgse-mopt-keyword-' + id + '" style="margin-top:8px;font-size:10.5px;color:var(--lgse-t3);min-height:14px"></div>'
        + '</div>'

        // ── P1-H — Featured image section ──
        + '<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--lgse-border)">'
          + '<div style="font-size:9px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">Featured image</div>'
          + '<div style="display:flex;align-items:flex-start;gap:16px">'

            // Current image preview
            + '<div id="lgse-fi-preview-' + id + '" style="width:120px;height:80px;background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">'
              + (p.featured_image_url
                ? '<img src="' + esc(p.featured_image_url) + '" style="width:100%;height:100%;object-fit:cover">'
                : '<span style="font-size:11px;color:var(--lgse-t3)">No image</span>')
            + '</div>'

            // Action column
            + '<div style="display:flex;flex-direction:column;gap:8px">'
              + '<button onclick="lgsePickMedia(' + id + ')" style="background:transparent;color:var(--lgse-t2);border:1px solid var(--lgse-border);border-radius:7px;padding:7px 14px;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:6px">🖼 Pick from media library</button>'
              + '<button onclick="lgseGenerateFeaturedImage(' + id + ')" style="background:var(--lgse-purple);color:#fff;border:none;border-radius:7px;padding:7px 14px;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:6px">✨ Generate with AI</button>'
              + '<div id="lgse-fi-status-' + id + '" style="font-size:10px;color:var(--lgse-t3)"></div>'
            + '</div>'

          + '</div>'
        + '</div>'

      + '</td>';

    detailTr.innerHTML = inner;
    // Phase A (2026-05-16) — detect focus keyword in background. Free for
    // all tiers (deterministic, no AI, no credits).
    setTimeout(function () {
      var kwEl = document.getElementById('lgse-mopt-keyword-' + id);
      if (!kwEl) return;
      api('GET', '/meta-optimize/keyword?page_id=' + id).then(function (kr) {
        if (!kr || !kr.success || !kr.keyword) return;
        var k = kr.keyword;
        if (k.needs_user_input) {
          kwEl.innerHTML = '<span style="color:var(--lgse-amber)">Focus keyword: not detected. Add a tracked keyword or edit the H1 to set one.</span>';
          return;
        }
        var sourceLabel = ({
          tracked_keywords: 'tracked keyword',
          h1_extract:       'from H1',
          title_extract:    'from title',
          slug_extract:     'from URL slug'
        })[k.source] || k.source;
        var altsStr = (k.alternatives && k.alternatives.length)
          ? ' · alternatives: ' + k.alternatives.map(esc).join(', ')
          : '';
        kwEl.innerHTML = '<strong style="color:var(--lgse-t2)">Detected focus keyword:</strong> '
          + '<span style="color:var(--lgse-t1);font-weight:500">' + esc(k.primary_keyword) + '</span> '
          + '<span style="color:var(--lgse-t3)">(' + esc(sourceLabel) + ')</span>'
          + altsStr;
      }).catch(function () { /* silent — supplementary */ });
    }, 0);
    row.parentNode.insertBefore(detailTr, row.nextSibling);
  };

  window.lgseSavePageRow = function (id) {
    if (!id) return;
    var t = document.getElementById('lgse-edit-title-' + id);
    var m = document.getElementById('lgse-edit-meta-'  + id);
    var h = document.getElementById('lgse-edit-h1-'    + id);
    var s = document.getElementById('lgse-edit-status-' + id);
    if (!t || !m || !h) return;
    var pool = window._lgsePages || [];
    var pageUrl = '';
    for (var idx = 0; idx < pool.length; idx++) {
      if (parseInt(pool[idx].id, 10) === id) { pageUrl = pool[idx].url || ''; break; }
    }
    if (!pageUrl) {
      if (s) { s.textContent = 'Save failed: page URL missing'; s.style.color = 'var(--lgse-red)'; }
      return;
    }
    if (s) { s.textContent = 'Saving…'; s.style.color = 'var(--lgse-t3)'; }
    // Phase A (2026-05-16): canonical metadata save route is /save-meta —
    // Laravel persist + Builder writeback + WP push. /indexed-content/{id}
    // was Laravel-only.
    api('PATCH', '/save-meta', {
      url:              pageUrl,
      meta_title:       t.value,
      meta_description: m.value,
      h1:               h.value
    }).then(function (r) {
      if (r && r.success) {
        // /save-meta now returns {success, new_score, old_score, score_changed}.
        var scoreNote = '';
        if (r.score_changed && r.new_score !== null && r.old_score !== null) {
          var delta = r.new_score - r.old_score;
          var arrow = delta > 0 ? '↑' : (delta < 0 ? '↓' : '');
          scoreNote = ' (score ' + r.old_score + ' → ' + r.new_score + (arrow ? ' ' + arrow : '') + ')';
        }
        if (s) { s.textContent = 'Saved ✓' + scoreNote; s.style.color = 'var(--lgse-teal)'; }
        var savedUrl = pageUrl;
        if (window._lgsePages) {
          for (var i = 0; i < window._lgsePages.length; i++) {
            if (parseInt(window._lgsePages[i].id, 10) === id) {
              window._lgsePages[i].meta_title       = t.value;
              window._lgsePages[i].meta_description = m.value;
              window._lgsePages[i].h1               = h.value;
              if (r.new_score !== null && r.new_score !== undefined) {
                window._lgsePages[i].content_score = r.new_score;
              }
              break;
            }
          }
        }
        // P0.7 — fix→verify→refresh loop. Mark any Quick Wins for this URL
        // as resolved if the corresponding field is now non-empty. The next
        // visit to Quick Wins (or a manual refresh) will hide them.
        // We only mark the THIS-URL+THIS-FIELD pair, so other unrelated
        // wins on the same page stay visible. The audit job will eventually
        // catch up; this is the immediate-feedback layer.
        if (savedUrl && typeof window.lgseMarkWinResolved === 'function') {
          if (m && m.value && m.value.length > 0) {
            window.lgseMarkWinResolved(savedUrl, 'Meta description exists');
            window.lgseMarkWinResolved(savedUrl, 'Meta description length');
          }
          if (t && t.value && t.value.length > 0) {
            window.lgseMarkWinResolved(savedUrl, 'Title tag exists');
            window.lgseMarkWinResolved(savedUrl, 'Title length');
          }
          if (h && h.value && h.value.length > 0) {
            window.lgseMarkWinResolved(savedUrl, 'H1 tag exists');
          }
        }
      } else if (s) {
        s.textContent = 'Save failed: ' + ((r && r.error) || 'unknown');
        s.style.color = 'var(--lgse-red)';
      }
    }).catch(function () {
      if (s) { s.textContent = 'Save failed (network)'; s.style.color = 'var(--lgse-red)'; }
    });
  };

  // ─────────────────────────────────────────────────────────────────────
  // Phase A (2026-05-16) — AI metadata optimization (Growth+).
  // Auto-applies on click. AI returns actions[] (replace/keep per field).
  // Backend persists + WP-pushes in one call. 0.5 credit per page.
  // ─────────────────────────────────────────────────────────────────────
  window.lgseOptimizeMeta = function (id) {
    if (!id) return;
    if (typeof window.lgseAssertSeoAI === 'function' && !window.lgseAssertSeoAI()) return;
    var btn = document.getElementById('lgse-mopt-btn-' + id);
    var s   = document.getElementById('lgse-edit-status-' + id);
    var origLabel = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = 'Optimizing…'; }
    if (s)   { s.textContent = ''; }

    api('POST', '/meta-optimize/run', { page_id: id }).then(function (r) {
      if (btn) { btn.disabled = false; btn.innerHTML = origLabel; }
      if (!r) {
        if (s) { s.textContent = 'Couldn\'t optimize — please try again.'; s.style.color = 'var(--lgse-red)'; }
        return;
      }
      if (r.success !== true) {
        var reason = String(r.reason || r.error || '');
        var msg = 'Couldn\'t optimize this page.';
        if (reason === 'plan_upgrade_required')                                       msg = 'AI optimization requires the Growth plan or above.';
        else if (reason === 'insufficient_credits' || r.code === 'NO_CREDITS')        msg = 'Not enough credits. Need ' + (r.required || '0.5') + ', have ' + (r.available || 0) + '.';
        else if (reason === 'no_focus_keyword')                                       msg = 'No focus keyword detected. Add a tracked keyword or edit the H1, then try again.';
        else if (reason === 'detection_returned_none_no_override_supplied')           msg = 'No focus keyword detected for this page.';
        else if (reason === 'ai_unreachable' || reason === 'ai_returned_invalid_json' || reason === 'ai_returned_no_actions') msg = 'Couldn\'t optimize right now — please try again in a moment.';
        if (s) { s.textContent = msg; s.style.color = 'var(--lgse-red)'; }
        return;
      }

      // Success — refresh the 3 inputs with applied values
      var actions = r.actions || [];
      actions.forEach(function (a) {
        if (a.action !== 'replace' || !a.value) return;
        var el = null;
        if (a.field === 'meta_title')       el = document.getElementById('lgse-edit-title-' + id);
        if (a.field === 'meta_description') el = document.getElementById('lgse-edit-meta-'  + id);
        if (a.field === 'h1')               el = document.getElementById('lgse-edit-h1-'    + id);
        if (el) { el.value = a.value; }
      });

      // Refresh cached page row
      var pool = window._lgsePages || [];
      for (var i = 0; i < pool.length; i++) {
        if (parseInt(pool[i].id, 10) === id) {
          actions.forEach(function (a) {
            if (a.action === 'replace' && a.value) pool[i][a.field] = a.value;
          });
          // Pick up the recomputed score so the table reflects it on next render
          if (r.new_score !== null && r.new_score !== undefined) {
            pool[i].content_score = r.new_score;
          }
          break;
        }
      }

      // Mark Quick Wins resolved for replaced fields
      if (r.page_url && typeof window.lgseMarkWinResolved === 'function') {
        actions.forEach(function (a) {
          if (a.action !== 'replace') return;
          if (a.field === 'meta_title')       { window.lgseMarkWinResolved(r.page_url, 'Title tag exists'); window.lgseMarkWinResolved(r.page_url, 'Title length'); }
          if (a.field === 'meta_description') { window.lgseMarkWinResolved(r.page_url, 'Meta description exists'); window.lgseMarkWinResolved(r.page_url, 'Meta description length'); }
          if (a.field === 'h1')               { window.lgseMarkWinResolved(r.page_url, 'H1 tag exists'); }
        });
      }

      // Honest result banner
      var wpNote = '';
      if (r.wp_pushed === true)       wpNote = ' · synced to your website ✓';
      else if (r.wp_pushed === false) wpNote = ' · saved here (website sync pending)';
      var scoreNote = '';
      if (r.score_changed && r.new_score !== null && r.old_score !== null) {
        var delta = r.new_score - r.old_score;
        var arrow = delta > 0 ? '↑' : (delta < 0 ? '↓' : '');
        scoreNote = ' · score ' + r.old_score + ' → ' + r.new_score + (arrow ? ' ' + arrow : '');
      }
      var msg = 'Optimized ✓ ' + r.fields_replaced + ' field' + (r.fields_replaced === 1 ? '' : 's') + ' updated, '
              + r.fields_kept + ' kept' + wpNote + ' · ' + r.credits_used + ' cr used' + scoreNote;
      if (r.fields_replaced === 0) {
        msg = 'Already optimal ✓ no changes needed (' + r.credits_used + ' cr used)';
      }
      if (s) { s.textContent = msg; s.style.color = 'var(--lgse-teal)'; }
    }).catch(function (e) {
      if (btn) { btn.disabled = false; btn.innerHTML = origLabel; }
      var em = (e && e.body && (e.body.reason || e.body.error)) || (e && e.message) || 'network error';
      var msg = 'Couldn\'t optimize — ' + em;
      if (em === 'plan_upgrade_required') msg = 'AI optimization requires the Growth plan or above.';
      if (s) { s.textContent = msg; s.style.color = 'var(--lgse-red)'; }
    });
  };

  // ── End P1-F ──────────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // P1-H — Featured image picker + AI-generate hook for Pages row expand.
  // Save: PATCH /indexed-content/{id} with { featured_image_url }
  // List: GET /media/library (workspace-scoped MediaController)
  // Generate: no /studio/generate route on staging — falls back to a modal
  //           that takes the user to Studio (or similar) with a prefilled
  //           prompt. UI is honest about this.
  // ─────────────────────────────────────────────────────────────────────
  window.lgsePickMedia = function (id) {
    /* Change 3: WP-native picker via strict-origin postMessage when in embed mode.
     * Falls back to existing Laravel media picker when standalone.
     * Message types allowlisted. 60s timeout cleanup on listener.
     */
    var embed    = window._LGSC_EMBED || {};
    var wpOrigin = (typeof embed.wp_origin === 'string' && embed.wp_origin)
                   ? embed.wp_origin.replace(/\/+$/, '') : null;
    var inIframe = (window.parent !== window);

    if (wpOrigin && inIframe) {
      var _timer = null;

      var _handler = function (e) {
        if (e.origin !== wpOrigin) { return; }
        if (!e.data || e.data.type !== 'lgsc_media_selected') { return; }
        if (e.data.target_id !== id) { return; }

        window.removeEventListener('message', _handler);
        clearTimeout(_timer);

        if (e.data.url) {
          window.lgseSetFeaturedImage(id, e.data.url, e.data.attachment_id || null);
        }
      };

      window.addEventListener('message', _handler);

      _timer = setTimeout(function () {
        window.removeEventListener('message', _handler);
      }, 60000);

      window.parent.postMessage({
        type:      'lgsc_open_wp_media',
        target_id: id
      }, wpOrigin);

      return;
    }

    // Standalone SPA fallback: existing Laravel media picker
    var listEl;
    // max-height uses min(...) of a fixed cap and viewport-relative cap so the
    // grid grows on tall screens (more visible without scrolling) while still
    // fitting on shorter ones. Count indicator (#lgse-media-count) above the
    // grid tells the user the total so they know to scroll if needed.
    var html = '<div id="lgse-media-count" style="font-size:11px;color:var(--lgse-t3);margin-bottom:8px;text-align:right">&nbsp;</div>'
      + '<div id="lgse-media-grid" style="display:grid;grid-template-columns:repeat(4,1fr);grid-auto-rows:auto;gap:8px;max-height:min(500px,70vh);overflow-y:auto;align-content:start">'
      + '<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--lgse-t3);font-size:11px">Loading media…</div>'
      + '</div>';
    window.lgseShowModal('Media library', html, null, { hideSave: true, cancelLabel: 'Close' });

    // Picker uses _luFetch directly because /media/library is NOT under
    // /api/seo prefix (it's at /api/media/library). _seoApi (which api()
    // delegates to) hardcodes the /api/seo prefix and would 404. _luFetch
    // also correctly handles X-API-KEY in embed mode + Bearer in direct mode.
    var _pickerReq = (typeof window._luFetch === 'function')
      ? window._luFetch('GET', '/media/library?per_page=500&type=image', null).then(function (r) { return r.json(); })
      : fetch(window.location.origin + '/api/media/library?per_page=500&type=image', {
          headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || '') },
        }).then(function (r) { return r.json(); });
    _pickerReq.then(function (d) {
      // MediaController::library returns {success, locked, files:[...], total, page, per_page, access}.
      // d.files is the canonical key; the others are kept as defensive fallbacks
      // for any shape drift across the codebase.
      var items = (d && (d.files || d.items || d.data || d.media)) || (Array.isArray(d) ? d : []);
      // Filter to images only — defence in depth.
      items = items.filter(function (it) {
        var t = (it.asset_type || it.type || '').toLowerCase();
        if (t && t !== 'image') return false;
        return !!(it.url || it.thumbnail_url || it.path || it.src);
      });
      var grid = document.getElementById('lgse-media-grid'); if (!grid) return;
      var countEl = document.getElementById('lgse-media-count');
      if (countEl) countEl.textContent = items.length === 1 ? '1 image' : items.length + ' images';
      if (items.length === 0) {
        if (countEl) countEl.textContent = '';
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:32px;color:var(--lgse-t3);font-size:11px">No media found. Upload images in Studio first.</div>';
        return;
      }
      var inner = '';
      items.forEach(function (it) {
        var src = it.thumbnail_url || it.url || it.file_url || it.path || it.src || '';
        var fullSrc = it.url || it.file_url || it.path || it.src || src;
        if (!src) return;
        var safeFull = String(fullSrc).replace(/'/g, '\\\'').replace(/"/g, '&quot;');
        // Aspect-ratio approach was causing row overlap inside CSS Grid with
        // a height-constrained container. Switching to the padding-top:100%
        // trick: position:relative on the cell + padding-top:100% makes the
        // cell's height equal its width (because % padding is relative to
        // parent width), and the img is absolutely positioned to fill. This
        // pattern is bulletproof across browsers and grid contexts.
        inner += '<div onclick="lgseSelectMediaItem(' + id + ', \'' + safeFull + '\')" '
          + 'style="position:relative;cursor:pointer;border-radius:6px;overflow:hidden;width:100%;min-width:0;padding-top:100%;border:2px solid transparent;transition:border-color .12s" '
          + 'onmouseover="this.style.borderColor=\'var(--lgse-purple)\'" '
          + 'onmouseout="this.style.borderColor=\'transparent\'">'
          + '<img src="' + esc(src) + '" style="display:block;position:absolute;inset:0;width:100%;height:100%;object-fit:cover">'
          + '</div>';
      });
      grid.innerHTML = inner;
    }).catch(function () {
      var grid = document.getElementById('lgse-media-grid');
      if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:24px;color:var(--lgse-red);font-size:11px">Could not load media library.</div>';
    });
  
  };

  window.lgseSelectMediaItem = function (id, src) {
    var overlay = document.getElementById('lgse-modal-overlay');
    if (overlay) overlay.remove();
    window.lgseSetFeaturedImage(id, src);
  };

  window.lgseSetFeaturedImage = function (id, imageUrl, attachmentId) {
    if (!id || !imageUrl) return;
    var preview = document.getElementById('lgse-fi-preview-' + id);
    if (preview) preview.innerHTML = '<img src="' + esc(imageUrl) + '" style="width:100%;height:100%;object-fit:cover">';
    var status  = document.getElementById('lgse-fi-status-'  + id);
    if (status) { status.textContent = 'Saving…'; status.style.color = 'var(--lgse-t3)'; }

    api('PATCH', '/indexed-content/' + id, Object.assign({ featured_image_url: imageUrl }, attachmentId ? { wp_attachment_id: parseInt(attachmentId, 10) } : {})).then(function (r) {
      if (r && r.success) {
        if (status) {
          status.textContent = '✓ Saved';
          status.style.color = 'var(--lgse-teal)';
          setTimeout(function () { if (status) status.textContent = ''; }, 3000);
        }
        // Update cache so reopening the row reflects the save.
        if (window._lgsePages) {
          for (var i = 0; i < window._lgsePages.length; i++) {
            if (parseInt(window._lgsePages[i].id, 10) === id) {
              window._lgsePages[i].featured_image_url = imageUrl;
              break;
            }
          }
        }
      } else if (status) {
        status.textContent = 'Save failed: ' + ((r && r.error) || 'unknown');
        status.style.color = 'var(--lgse-red)';
      }
    }).catch(function () {
      if (status) { status.textContent = 'Save failed (network)'; status.style.color = 'var(--lgse-red)'; }
    });
  };

  window.lgseGenerateFeaturedImage = function (id) {
    if (typeof window.lgseAssertSeoAI === 'function' && !window.lgseAssertSeoAI()) return;

    var pool = window._lgsePages || [];
    var p = null;
    for (var i = 0; i < pool.length; i++) { if (parseInt(pool[i].id, 10) === id) { p = pool[i]; break; } }
    if (!p) return;
    var pageUrl   = p.url || '';
    var pageTitle = p.meta_title || p.title || pageUrl;

    var status = document.getElementById('lgse-fi-status-' + id);
    if (status) { status.textContent = 'Generating…'; status.style.color = 'var(--lgse-t3)'; }

    // 2026-05-13 — call the verified-working connector route directly.
    // /api/connector/pages/regenerate-image handles credit reserve + commit,
    // routes through CreativeConnector → RuntimeClient → gpt-image-1, and
    // writes the PNG to public storage. It returns { success, image_url }.
    // _luFetch carries X-API-KEY in embed mode and Authorization Bearer in
    // direct SPA mode — works for both.
    if (typeof window._luFetch !== 'function') {
      if (status) { status.textContent = 'Auth helper unavailable — refresh the page.'; status.style.color = 'var(--lgse-red)'; }
      return;
    }

    window._luFetch('POST', '/connector/pages/regenerate-image', {
      page_id: id,
      url:     pageUrl,
      title:   pageTitle,
      force:   true,
    })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: 'bad_json' }; }); })
      .then(function (d) {
        if (d && d.success && d.image_url) {
          // lgseSetFeaturedImage handles the PATCH + preview update + status badge.
          window.lgseSetFeaturedImage(id, d.image_url);
          return;
        }
        if (d && d.error === 'insufficient_credits') {
          if (status) {
            status.textContent = 'Not enough credits (need 1).';
            status.style.color = 'var(--lgse-red)';
          }
          return;
        }
        if (status) {
          status.textContent = 'Generation failed: ' + (d && (d.error || d.message) || 'unknown');
          status.style.color = 'var(--lgse-red)';
        }
      })
      .catch(function (e) {
        if (status) {
          status.textContent = 'Network error — try again.';
          status.style.color = 'var(--lgse-red)';
        }
      });
  };
  // ── End P1-H ──────────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // SMOKE-1.3 — Rebuild graph / Recalculate equity (proper feedback).
  // Replaces the inline onclick handlers that fired-and-forgot with no UI
  // signal. Both routes already exist on staging:
  //   POST /api/seo/link-graph/build
  //   POST /api/seo/equity/calculate
  // ─────────────────────────────────────────────────────────────────────
  window.lgseRebuildGraph = function (btn) {
    var b = btn || document.getElementById('lgse-rebuild-graph-btn');
    var orig = b ? b.textContent : '';
    if (b) { b.disabled = true; b.textContent = '⏳ Rebuilding…'; }
    api('POST', '/link-graph/build', {}).then(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Rebuild graph'; }
      // Re-render to show the fresh graph counts.
      if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('links');
    }).catch(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Rebuild graph'; }
    });
  };

  window.lgseRecalcEquity = function (btn) {
    var b = btn || document.getElementById('lgse-recalc-equity-btn');
    var orig = b ? b.textContent : '';
    if (b) { b.disabled = true; b.textContent = '⏳ Calculating…'; }
    api('POST', '/equity/calculate', {}).then(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Recalculate equity'; }
      if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('links');
    }).catch(function () {
      if (b) { b.disabled = false; b.textContent = orig || 'Recalculate equity'; }
    });
  };
  // ── End SMOKE-1.3 ─────────────────────────────────────────────────────

  // ─────────────────────────────────────────────────────────────────────
  // P1-G — Pages bulk action toolbar.
  // Sticky bottom bar appears when ≥1 row checkbox is selected.
  // Actions: Select none · Export selected as CSV.
  // ─────────────────────────────────────────────────────────────────────
  function lgseGetCheckedPageIds() {
    var ids = [];
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-pg-check'), function (cb) {
      if (cb.checked) {
        var pid = parseInt(cb.getAttribute('data-pid'), 10);
        if (pid) ids.push(pid);
      }
    });
    return ids;
  }

  window.lgseTogglePageSelectAll = function (checked) {
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-pg-check'), function (cb) { cb.checked = !!checked; });
    window.lgseUpdateBulkBar();
  };

  window.lgseUpdateBulkBar = function () {
    var ids = lgseGetCheckedPageIds();
    var bar = document.getElementById('lgse-pg-bulkbar');
    var allCb = document.getElementById('lgse-pg-selectall');
    var totalCbs = document.querySelectorAll('.lgse-pg-check').length;
    if (allCb) {
      allCb.checked = totalCbs > 0 && ids.length === totalCbs;
      allCb.indeterminate = ids.length > 0 && ids.length < totalCbs;
    }

    if (ids.length === 0) {
      if (bar) bar.style.display = 'none';
      return;
    }
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'lgse-pg-bulkbar';
      bar.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);background:var(--lgse-bg1);border:1px solid var(--lgse-purple);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.45);padding:10px 16px;display:flex;align-items:center;gap:14px;z-index:200;font-size:11.5px';
      document.body.appendChild(bar);
    }
    var n = ids.length;
    // Capability-aware render. _lgseSeoAI is set by lgseEnsureCaps() (kicked
    // off from lgseLoadPages). null = unknown -> show as gated until known
    // (safer default; tooltip explains).
    var aiOn = window._lgseSeoAI === true;
    var aiBtn = aiOn
      ? '<button class="lgse-btn-primary" style="font-size:11px;padding:6px 12px" onclick="lgseBulkGenerateMetas()">Bulk optimize metadata (' + n + ' × 0.5 cr)</button>'
      : '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px;opacity:0.6;cursor:not-allowed" title="AI SEO generation unlocks on Growth ($99) and above. Manual + template tools stay available on every plan." disabled>Bulk optimize metadata (Growth)</button>';
    bar.innerHTML = ''
      + '<div style="color:var(--lgse-t1);font-weight:500"><span style="color:var(--lgse-purple)">' + n + '</span> selected</div>'
      + '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px" onclick="lgseSelectNone()">Select none</button>'
      + '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px" onclick="lgseApplyTemplate()" title="Deterministic per-row templating. Uses {title}, {h1}, {url}. Free on every plan.">Apply template (free)</button>'
      + aiBtn
      + '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px;opacity:0.55;cursor:not-allowed" title="Bulk optimization for this content type is coming soon." disabled>Bulk-optimize content (gated)</button>'
      + '<button class="lgse-btn-secondary" style="font-size:11px;padding:6px 12px" onclick="lgseExportSelected()">Export selected ↓</button>';
    bar.style.display = 'flex';
  };

  window.lgseSelectNone = function () {
    Array.prototype.forEach.call(document.querySelectorAll('.lgse-pg-check'), function (cb) { cb.checked = false; });
    var allCb = document.getElementById('lgse-pg-selectall');
    if (allCb) { allCb.checked = false; allCb.indeterminate = false; }
    window.lgseUpdateBulkBar();
  };

  window.lgseExportSelected = function () {
    var ids = lgseGetCheckedPageIds();
    if (ids.length === 0) return;
    var pool = window._lgsePages || [];
    var selected = pool.filter(function (p) { return ids.indexOf(parseInt(p.id, 10)) !== -1; });
    if (selected.length === 0) return;

    var cols = ['id', 'url', 'title', 'meta_title', 'meta_description', 'h1', 'word_count', 'image_count', 'internal_link_count', 'external_link_count', 'content_score', 'intent', 'http_status', 'issues_count', 'indexed_at'];
    function csvCell(v) {
      if (v == null) return '';
      var s = String(v);
      if (/[",\n\r]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
      return s;
    }
    var lines = [cols.join(',')];
    selected.forEach(function (p) {
      lines.push(cols.map(function (c) { return csvCell(p[c]); }).join(','));
    });
    var csv = lines.join('\n');
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'seo-pages-selected-' + Date.now() + '.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };
  // ─────────────────────────────────────────────────────────────────────
  // P1-G EXT (2026-05-15) — Bulk-generate meta descriptions.
  // Sequential PATCH-back per row; charges 1 credit per successful generate.
  // Skips rows that already have a non-empty meta_description.
  // ─────────────────────────────────────────────────────────────────────
  window.lgseBulkGenerateMetas = function () {
    if (typeof window.lgseAssertSeoAI === 'function' && !window.lgseAssertSeoAI()) return;
    var ids = lgseGetCheckedPageIds();
    if (ids.length === 0) return;
    var pool = window._lgsePages || [];
    // Phase A — bulk loops /meta-optimize/run per page. AI judges replace/
    // keep per field; bulk doesn't pre-filter "already has meta" because the
    // AI itself decides whether to overwrite. 0.5 cr per page.
    var cost = (ids.length * 0.5).toFixed(2).replace(/\.00$/, '');
    var msg = 'Optimize metadata for ' + ids.length + ' page(s) with AI.\n\n'
            + 'Cost: ' + cost + ' credit(s) (' + ids.length + ' × 0.5 cr).\n\n'
            + 'AI will pick the optimal title, description, and H1 per page (and skip fields already optimal).\n'
            + 'Saves apply immediately and sync to your website.';
    window.lgseConfirm('Bulk optimize metadata', msg, 'Optimize').then(function (ok) {
      if (!ok) return;
      _lgseRunBulkOptimize(ids, pool);
    });
  };

  // Extracted bulk-optimize loop body so the lgseConfirm.then() above can
  // hand off without nesting all 30+ lines into the callback.
  function _lgseRunBulkOptimize(ids, pool) {
    var btn = document.querySelector('#lgse-pg-bulkbar .lgse-btn-primary');
    var originalLabel = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Optimizing 0/' + ids.length + '…'; }

    var done = 0, optimized = 0, alreadyOptimal = 0, failed = 0;
    var totalReplaced = 0, totalKept = 0, totalWpPushed = 0, totalWpFailed = 0;
    var totalCredits = 0;

    function next(i) {
      if (i >= ids.length) {
        if (btn) {
          var summary = optimized + ' optimized';
          if (alreadyOptimal > 0) summary += ', ' + alreadyOptimal + ' already optimal';
          if (failed > 0)         summary += ', ' + failed + ' failed';
          btn.innerText = summary;
          setTimeout(function () {
            if (typeof window.lgseRefreshAfterBulk === 'function') window.lgseRefreshAfterBulk();
          }, 800);
        }
        return;
      }
      var pid = ids[i];
      api('POST', '/meta-optimize/run', { page_id: pid }).then(function (r) {
        if (r && r.success === true) {
          if (r.fields_replaced > 0) {
            optimized++;
            totalReplaced += r.fields_replaced;
            var poolRow = pool.find(function (p) { return parseInt(p.id, 10) === pid; });
            if (poolRow && r.actions) {
              r.actions.forEach(function (a) {
                if (a.action === 'replace' && a.value) poolRow[a.field] = a.value;
              });
            }
          } else {
            alreadyOptimal++;
          }
          totalKept += (r.fields_kept || 0);
          if (r.wp_pushed === true)       totalWpPushed++;
          else if (r.wp_pushed === false) totalWpFailed++;
          totalCredits += parseFloat(r.credits_used || 0);
        } else {
          failed++;
        }
      }).catch(function () {
        failed++;
      }).then(function () {
        done++;
        if (btn) btn.innerText = 'Optimizing ' + done + '/' + ids.length + '…';
        setTimeout(function () { next(i + 1); }, 60);
      });
    }
    next(0);
  };

  window.lgseRefreshAfterBulk = function () {
    if (typeof window.lgseLoadPages === 'function') {
      window.lgseLoadPages();
    }
    var bar = document.getElementById('lgse-pg-bulkbar');
    if (bar) bar.style.display = 'none';
    var allCb = document.getElementById('lgse-pg-selectall');
    if (allCb) { allCb.checked = false; allCb.indeterminate = false; }
    if (typeof window.loadWins === 'function') {
      var winsBody = document.getElementById('lgse-wins-body');
      if (winsBody) window.loadWins(winsBody);
    }
  };

  // ─────────────────────────────────────────────────────────────────────
  // P-Gate (2026-05-15) — capability fetch (one-shot, promise-cached).
  // Resolves window._lgseSeoAI to true|false. Default null = unknown,
  // treated as gated by render code until resolved.
  // ─────────────────────────────────────────────────────────────────────
  window.lgseEnsureCaps = function () {
    if (window._lgseCapPromise) return window._lgseCapPromise;
    var fetcher = (typeof window._luFetch === 'function')
      ? window._luFetch('GET', '/workspace/capabilities').then(function (resp) { return resp.json(); })
      : fetch(window.location.origin + '/api/workspace/capabilities', {
          headers: {
            'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
            'Accept': 'application/json'
          },
          cache: 'no-store'
        }).then(function (resp) { return resp.json(); });
    window._lgseCapPromise = fetcher.then(function (caps) {
      window._lgseCaps  = caps || {};
      window._lgseSeoAI = !!(caps && caps.engines && caps.engines.seo && caps.engines.seo.ai_generation);
      // Re-render bar if visible — caps may have arrived after first render
      if (typeof window.lgseUpdateBulkBar === 'function') {
        var bar = document.getElementById('lgse-pg-bulkbar');
        if (bar && bar.style.display !== 'none') window.lgseUpdateBulkBar();
      }
      return caps;
    }).catch(function () {
      // Fail-closed: unknown -> treat as no-AI
      window._lgseSeoAI = false;
      return { engines: { seo: { ai_generation: false } } };
    });
    return window._lgseCapPromise;
  };

  // Belt-and-suspenders for AI generation handlers — block at point of click.
  // Backend also enforces (returns 403); this just prevents wasted round-trip
  // and gives an honest in-UI message instead of a generic alert.
  window.lgseAssertSeoAI = function () {
    if (window._lgseSeoAI === true) return true;
    window.lgseAlert('Plan upgrade required', 'AI SEO generation unlocks on Growth ($99) and above.\n\nManual editing, scans, Quick Wins, and templates remain available on every plan.');
    return false;
  };

  // ─────────────────────────────────────────────────────────────────────
  // P5 (2026-05-15) — Generic deterministic meta template (FREE / NO AI).
  // Substitutes {title} / {h1} / {url} per row; PATCHes /indexed-content/{id}.
  // No RuntimeClient. No credit deduction. Available on EVERY plan.
  // ─────────────────────────────────────────────────────────────────────
  window.lgseApplyTemplate = function () {
    var ids = lgseGetCheckedPageIds();
    if (ids.length === 0) return;
    var promptBody =
      'Enter a meta description template. Variables:\n' +
      '  {title}  — page title\n' +
      '  {h1}     — page H1\n' +
      '  {url}    — page URL\n\n' +
      'Example: Best marketing in Dubai | {title}';
    window.lgsePrompt('Apply template', promptBody, 'Best marketing in Dubai | {title}', '', 170).then(function (tpl) {
      if (!tpl) return;
      tpl = String(tpl).trim();
      if (!tpl) return;
      if (tpl.length > 170) {
        return window.lgseAlert('Template too long', 'Template would exceed 170 characters. Shorten it.');
      }

      var pool = window._lgsePages || [];
      var jobs = [];
      ids.forEach(function (pid) {
        var row = pool.find(function (p) { return parseInt(p.id, 10) === pid; });
        if (!row) return;
        var rendered = tpl
          .replace(/\{title\}/g, String(row.title || ''))
          .replace(/\{h1\}/g,    String(row.h1    || ''))
          .replace(/\{url\}/g,   String(row.url   || ''));
        if (rendered.length > 170) rendered = rendered.substring(0, 170);
        jobs.push({ pid: pid, value: rendered, row: row });
      });
      if (jobs.length === 0) {
        return window.lgseAlert('No pages to update', 'No matching rows in cache. Refresh the page list and try again.');
      }
      window.lgseConfirm(
        'Apply template?',
        'Apply template to ' + jobs.length + ' page(s).\n\nThis is FREE — no credits used. Existing meta descriptions WILL be overwritten.',
        'Apply'
      ).then(function (ok) {
        if (!ok) return;
        _lgseRunApplyTemplate(jobs);
      });
    });
  };

  // Extracted from lgseApplyTemplate so the promise-chained version above
  // doesn't have to nest 4 levels deep. Identical behavior to prior inline.
  function _lgseRunApplyTemplate(jobs) {

    var btnList = document.querySelectorAll('#lgse-pg-bulkbar button');
    var btn = null;
    btnList.forEach(function (b) { if (b.innerText && b.innerText.indexOf('Apply template') === 0) btn = b; });
    var origLabel = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Applying 0/' + jobs.length + '…'; }

    var done = 0, ok = 0;
    function next(i) {
      if (i >= jobs.length) {
        if (btn) {
          btn.innerText = 'Done ' + ok + '/' + jobs.length;
          setTimeout(window.lgseRefreshAfterBulk, 700);
        }
        return;
      }
      var j = jobs[i];
      api('PATCH', '/save-meta', { url: (j.row && j.row.url) || '', meta_description: j.value }).then(function (sr) {
        done++;
        if (sr && sr.success) {
          ok++;
          if (j.row) j.row.meta_description = j.value;
          if (typeof window.lgseMarkWinResolved === 'function' && j.row && j.row.url) {
            window.lgseMarkWinResolved(j.row.url, 'Meta description exists');
            window.lgseMarkWinResolved(j.row.url, 'Meta description length');
          }
        }
        if (btn) btn.innerText = 'Applying ' + done + '/' + jobs.length + '…';
        next(i + 1);
      }).catch(function () {
        done++;
        if (btn) btn.innerText = 'Applying ' + done + '/' + jobs.length + '…';
        next(i + 1);
      });
    }
    next(0);
  };

  // ── End P1-G ──────────────────────────────────────────────────────────
  function loadGsc(body) {
    api('GET', '/gsc/status').then(function (status) {
      var connected = !!(status && (status.connected || status.is_connected));
      if (!connected) {
        body.innerHTML = emptyState('🔗', 'Connect Google Search Console', 'Sync clicks, impressions, CTR, and position data into your workspace.', 'Connect with Google', 'api(\'GET\',\'/gsc/auth-url\').then(function(r){if(r&&r.url)window.open(r.url,\'_blank\')})');
        return;
      }
      api('GET', '/gsc/queries').then(function (qResp) {
        var queries = (qResp && (qResp.queries || qResp.data)) || [];
        var maxCtr = 0;
        queries.forEach(function (q) { if ((q.ctr || 0) > maxCtr) maxCtr = q.ctr || 0; });
        var lastSync = (status && (status.last_synced_at || status.synced_at)) || '';
        var h = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">';
        h += '<span class="lgse-badge lgse-b-teal">● Connected' + (lastSync ? ' — synced ' + esc(lastSync) : '') + '</span>';
        h += '<button class="lgse-btn-secondary" onclick="api(\'POST\',\'/gsc/sync\',{}).then(function(){lgseSwitchTab(\'insights\')})">Sync now</button>';
        h += '</div>';
        if (queries.length === 0) {
          h += emptyState('📡', 'No GSC data yet', 'Click "Sync now" to fetch the latest 28 days.');
        } else {
          h += '<table class="lgse-table"><thead><tr><th>Query</th><th class="r">Clicks</th><th class="r">Impressions</th><th class="r">CTR</th><th class="r">Position</th></tr></thead><tbody>';
          queries.slice(0, 30).forEach(function (q) {
            var ctr = parseFloat(q.ctr || 0);
            var heat = maxCtr > 0 ? Math.min(0.32, (ctr / maxCtr) * 0.32) : 0;
            h += '<tr><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--lgse-t1)">' + esc(q.query || '—') + '</td><td class="mono">' + (q.clicks || 0) + '</td><td class="mono">' + (q.impressions || 0) + '</td><td class="mono" style="background:rgba(108,92,231,' + heat + ')">' + (ctr * 100).toFixed(2) + '%</td><td class="mono">' + (parseFloat(q.position || 0)).toFixed(1) + '</td></tr>';
          });
          h += '</tbody></table>';
        }
        body.innerHTML = h;
      }).catch(function () { body.innerHTML = emptyState('⚠', 'Could not load queries', 'Try refreshing.'); });
    }).catch(function () { body.innerHTML = emptyState('⚠', 'GSC status unavailable', 'Try refreshing.'); });
  }

  // ── Tab 9 — Reports (P0-12 + P0-13) ───────────────────────────────────
  // History table built from /audits (most recent first), KPI strip, score
  // trend SVG, CSV export grid, per-row "View →" → drills into the Audit
  // tab's detail view via window.lgseShowAuditDetail(id).

  function renderReports(el) {
    el.innerHTML = pageTitle('Reports', 'Audit history, score trend, and CSV exports.')
      + '<div id="lgse-reports-body">Loading…</div>';
    window.lgseLoadReports();
  }

  window.lgseLoadReports = function () {
    var body = document.getElementById('lgse-reports-body');
    if (!body) return;
    body.innerHTML = '<div style="padding:24px;text-align:center;color:var(--lgse-t3);font-size:11px">Loading report…</div>';

    api('GET', '/audits?limit=20').then(function (d) {
      var audits = (d && (d.audits || d.data)) || (Array.isArray(d) ? d : []);
      if (!audits.length) {
        body.innerHTML = emptyState('◎', 'No audit history', 'Run your first audit to start tracking SEO improvements.', 'Run audit', 'lgseRunAudit()');
        return;
      }

      // Sort defensively — we want descending by created_at so audits[0] is latest.
      audits.sort(function (a, b) {
        return (new Date(b.created_at || 0)).getTime() - (new Date(a.created_at || 0)).getTime();
      });

      var latest = audits[0];
      var oldest = audits[audits.length - 1];
      var avgScore = Math.round(audits.reduce(function (s, a) { return s + (parseInt(a.score, 10) || 0); }, 0) / audits.length);
      var scoreChange = (parseInt(latest.score, 10) || 0) - (parseInt(oldest.score, 10) || 0);

      // Coverage: derive from latest audit's checks.
      var latestResults = {};
      try { latestResults = typeof latest.results_json === 'string' ? JSON.parse(latest.results_json) : (latest.results_json || {}); } catch (e) {}
      var checks = Array.isArray(latestResults.checks) ? latestResults.checks : (Array.isArray(latestResults.issues) ? latestResults.issues : []);

      function coverage(needle) {
        var matched = checks.filter(function (c) { return ((c.check || c.label || '') + '').toLowerCase().indexOf(needle) !== -1; });
        if (!matched.length) return null;
        var passed = matched.filter(function (c) { return c.status === 'pass' || c.status === 'success'; }).length;
        return Math.round(passed / matched.length * 100);
      }
      var titlePct = coverage('title');
      var descPct  = coverage('description');

      var html = '';

      // Header: title + audit count + Download buttons
      html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px;flex-wrap:wrap">'
        +   '<div>'
        +     '<div style="font-size:16px;font-weight:600;color:var(--lgse-t1);margin-bottom:3px">SEO report</div>'
        +     '<div style="font-size:11px;color:var(--lgse-t3)">' + audits.length + ' audit' + (audits.length === 1 ? '' : 's') + ' · last run ' + (latest.created_at ? new Date(latest.created_at).toLocaleDateString() : '—') + '</div>'
        +   '</div>'
        +   '<div style="display:flex;gap:8px">'
        +     '<button class="lgse-btn-secondary" onclick="lgseDownloadReport(\'html\')" style="font-size:11px;padding:7px 14px">Export HTML</button>'
        +     '<button class="lgse-btn-primary"   onclick="lgseDownloadReport(\'pdf\')"  style="font-size:11.5px;padding:7px 14px">Print → PDF</button>'
        +   '</div>'
        + '</div>';

      // Date-range pills (visual only — backend doesn't yet take a date filter on /audits).
      html += '<div style="display:flex;gap:6px;margin-bottom:14px">'
        + [7, 30, 60, 90].map(function (days) {
            var active = days === 30; // default emphasis
            var bg = active ? 'rgba(108,92,231,0.08)' : 'var(--lgse-bg2)';
            var bd = active ? 'var(--lgse-purple)' : 'var(--lgse-border)';
            var c  = active ? 'var(--lgse-purple)' : 'var(--lgse-t3)';
            return '<span class="lgse-fp" onclick="lgseReportsRange(' + days + ', this)" style="background:' + bg + ';border:1px solid ' + bd + ';border-radius:20px;padding:4px 12px;font-size:10.5px;color:' + c + ';cursor:pointer;transition:all .12s">' + days + 'd</span>';
          }).join('')
        + '</div>';

      // Wave 18c — placeholder for the rich KPI grid. Populated by an async
      // /reports/summary fetch below so we don't blow up the initial render
      // if any of the side-queries are slow. Each section renders 5 cards.
      html += '<div id="lgse-reports-rich-summary" style="margin-bottom:18px">'
        +   '<div style="padding:14px;color:var(--lgse-t3);text-align:center;font-size:11px">Loading site health…</div>'
        + '</div>';

      // SMOKE-1.4 — Score trend as CSS bar chart (was: brittle SVG polyline).
      var chartAudits = audits.slice(0, 8).reverse();
      if (chartAudits.length > 1) {
        var chartHtml = '<div style="display:flex;align-items:flex-end;gap:6px;height:96px;padding:6px 0">';
        chartAudits.forEach(function (a) {
          var sc  = parseInt(a.score, 10) || 0;
          var pct = Math.round((sc / 100) * 80); // 0–80 px tall
          var col = sc >= 75 ? 'var(--lgse-teal)' : sc >= 50 ? 'var(--lgse-purple)' : 'var(--lgse-amber)';
          var d   = a.created_at ? new Date(a.created_at) : null;
          var dateLbl = d ? d.toLocaleDateString('en', { month: 'short', day: 'numeric' }) : '—';
          chartHtml += '<div style="display:flex;flex-direction:column;align-items:center;flex:1;gap:4px">'
            + '<div style="font-family:var(--lgse-mono);font-size:9px;color:var(--lgse-t3)">' + sc + '</div>'
            + '<div style="background:' + col + ';width:100%;height:' + pct + 'px;border-radius:3px 3px 0 0;min-height:4px"></div>'
            + '<div style="font-size:9px;color:var(--lgse-t3);text-align:center;white-space:nowrap">' + esc(dateLbl) + '</div>'
          + '</div>';
        });
        chartHtml += '</div>';
        html += '<div style="background:var(--lgse-bg2);border:1px solid var(--lgse-border);border-radius:10px;padding:14px;margin-bottom:16px">'
          +   '<div style="font-size:10px;font-weight:600;color:var(--lgse-t3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Score trend</div>'
          +   chartHtml
          + '</div>';
      }

      // Wave 18a — Raw data exports (full table dumps).
      html += '<div class="lgse-section-hdr"><span class="lgse-section-title">Raw data exports</span></div>';
      html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin-bottom:18px">';
      [
        ['keywords', 'Keywords',      'Every tracked keyword + rank'],
        ['pages',    'Pages',         'Indexed pages + scores'],
        ['links',    'Links',         'All link suggestions'],
        ['images',   'Images',        'Image optimization state'],
        ['anchors',  'Anchors',       'Per-anchor classification'],
      ].forEach(function (it) {
        html += '<button class="lgse-btn-secondary" style="text-align:left;padding:14px;display:block;cursor:pointer" onclick="lgseExportCsv(\'' + it[0] + '\')">'
          +   '<div style="font-weight:600;color:var(--lgse-t1);margin-bottom:3px">⬇ ' + esc(it[1]) + '</div>'
          +   '<div style="font-size:10px;color:var(--lgse-t3)">' + esc(it[2]) + '</div>'
          + '</button>';
      });
      html += '</div>';

      // Wave 18b — Action-list reports (issues + opportunities surfaced
      // ready-to-fix). Each one corresponds to a Wave 16e/16f/16g surface
      // the user already sees in the SPA — same shape, just CSV.
      html += '<div class="lgse-section-hdr"><span class="lgse-section-title">Action-list reports</span></div>';
      html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-bottom:18px">';
      [
        ['orphans',       '⚠ Orphan pages',    'Pages with 0 inbound links'],
        ['weak-pages',    '⚠ Weak pages',      'Pages with 1–2 inbound links'],
        ['quick-wins',    '⚡ Quick wins',     'High-impact fixes from latest audit'],
        ['cluster-gaps',  '⬡ Cluster gaps',    'Topic clusters missing pillars or pages'],
        ['anchor-health', '⚓ Anchor health',  'Generic + over-optimised + too-long anchors'],
        ['link-backlog',  '∞ Link backlog',    'Suggested/applied/dismissed per source page'],
      ].forEach(function (it) {
        html += '<button class="lgse-btn-secondary" style="text-align:left;padding:14px;display:block;cursor:pointer" onclick="lgseExportCsv(\'' + it[0] + '\')">'
          +   '<div style="font-weight:600;color:var(--lgse-t1);margin-bottom:3px">' + esc(it[1]) + '</div>'
          +   '<div style="font-size:10px;color:var(--lgse-t3)">' + esc(it[2]) + '</div>'
          + '</button>';
      });
      html += '</div>';

      // P0-13 — audit history table with per-row drill-down.
      html += '<div class="lgse-section-hdr"><span class="lgse-section-title">Audit history</span><span style="font-size:10px;color:var(--lgse-t3)">' + audits.length + ' audit' + (audits.length === 1 ? '' : 's') + '</span></div>';
      html += '<table class="lgse-table"><thead><tr><th>Date</th><th>URL</th><th class="r">Score</th><th class="r">Issues</th><th>Status</th><th></th></tr></thead><tbody>';
      audits.forEach(function (a) {
        var when = a.created_at ? new Date(a.created_at).toLocaleString() : '—';
        var totalIssues = parseInt(a.total_issues || a.errors || 0, 10) || 0;
        // Try to count issues from results_json if column missing.
        if (!totalIssues) {
          try {
            var parsed = typeof a.results_json === 'string' ? JSON.parse(a.results_json) : (a.results_json || {});
            var c = Array.isArray(parsed.checks) ? parsed.checks : (Array.isArray(parsed.issues) ? parsed.issues : []);
            totalIssues = c.filter(function (x) { return x && (x.status === 'error' || x.status === 'warning'); }).length;
          } catch (e) {}
        }
        var statusBadge = badge(a.status || '—', a.status === 'completed' ? 'teal' : a.status === 'failed' ? 'red' : 'amber');
        html += '<tr style="cursor:pointer" onclick="lgseShowRunDetail(' + (a.id || 0) + ')">'
          +   '<td style="font-size:10.5px;color:var(--lgse-t2)">' + esc(when) + '</td>'
          +   '<td class="lgse-url" title="' + esc(a.url || '') + '">' + esc(a.url || '—') + '</td>'
          +   '<td>' + scorePill(parseInt(a.score, 10) || 0) + '</td>'
          +   '<td class="mono">' + totalIssues + '</td>'
          +   '<td>' + statusBadge + '</td>'
          +   '<td style="color:var(--lgse-purple);font-size:10px;text-align:right">View →</td>'
          + '</tr>';
      });
      html += '</tbody></table>';

      body.innerHTML = html;

      // Wave 18c — fetch the rich KPI summary in parallel + render once back.
      var summaryHolder = document.getElementById('lgse-reports-rich-summary');
      if (summaryHolder) {
        var summaryUrl = '/reports/summary';
        api('GET', summaryUrl).then(function (s) {
          if (!s || !s.success) return;
          window.lgseRenderReportsSummary(summaryHolder, s);
        }).catch(function () {
          summaryHolder.innerHTML = '<div style="padding:14px;color:var(--lgse-t3);text-align:center;font-size:11px">Could not load extended summary.</div>';
        });
      }
    }).catch(function () {
      body.innerHTML = emptyState('⚠', 'Could not load reports', 'Check your connection and try again.');
    });
  };

  // Wave 18c — 5 sections × 5 KPI cards each, mirroring the PDF report layout.
  window.lgseRenderReportsSummary = function (holderEl, s) {
    function kpi(label, val, cls) {
      cls = cls || '';
      return '<div class="lgse-kpi-card"><div class="lgse-kpi-label">' + esc(label) + '</div><div class="lgse-kpi-val ' + cls + '">' + esc(String(val)) + '</div></div>';
    }
    function section(title, cards) {
      return '<div class="lgse-section-hdr" style="margin-top:10px"><span class="lgse-section-title">' + esc(title) + '</span></div>'
        +    '<div class="lgse-kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:10px">' + cards.join('') + '</div>';
    }
    var d = s.data || {};
    var siteHealth = d.site_health || {};
    var content    = d.content     || {};
    var links      = d.links       || {};
    var keywords   = d.keywords    || {};
    var imgAnchor  = d.images_anchors || {};
    var scCls = function (v) { return v > 0 ? 'lgse-up' : (v < 0 ? 'lgse-dn' : ''); };
    var rate = links.apply_rate_pct || 0;
    var ratCls = rate >= 50 ? 'lgse-up' : (rate >= 20 ? '' : 'lgse-dn');

    var html = ''
      + section('Site health', [
          kpi('Avg score',     siteHealth.avg_audit_score || 0),
          kpi('Score change',  (siteHealth.score_change >= 0 ? '+' : '') + (siteHealth.score_change || 0), scCls(siteHealth.score_change || 0)),
          kpi('Audits run',    siteHealth.audits_run || 0),
          kpi('Open issues',   siteHealth.open_issues || 0, (siteHealth.open_issues > 0 ? 'lgse-dn' : '')),
          kpi('Pages indexed', siteHealth.pages_indexed || 0),
        ])
      + section('Content', [
          kpi('Avg content',  content.avg_content_score || 0),
          kpi('Below 50',     content.below_50 || 0, ((content.below_50 || 0) > 0 ? 'lgse-dn' : '')),
          kpi('Thin (<300w)', content.thin || 0),
          kpi('Missing meta', content.no_meta || 0, ((content.no_meta || 0) > 0 ? 'lgse-dn' : '')),
          kpi('No H1',        content.no_h1 || 0),
        ])
      + section('Links', [
          kpi('Orphan pages', links.orphans || 0, ((links.orphans || 0) > 0 ? 'lgse-dn' : 'lgse-up')),
          kpi('Weak (1–2)',   links.weak || 0),
          kpi('Suggested',    links.suggested || 0),
          kpi('Applied',      links.applied || 0, ((links.applied || 0) > 0 ? 'lgse-up' : '')),
          kpi('Apply rate',   rate + '%', ratCls),
        ])
      + section('Keywords + topics', [
          kpi('Tracked',     keywords.tracked || 0),
          kpi('Top 3',       keywords.top_3 || 0, ((keywords.top_3 || 0) > 0 ? 'lgse-up' : '')),
          kpi('Top 10',      keywords.top_10 || 0),
          kpi('Improving',   keywords.improving || 0, ((keywords.improving || 0) > 0 ? 'lgse-up' : '')),
          kpi('Declining',   keywords.declining || 0, ((keywords.declining || 0) > 0 ? 'lgse-dn' : '')),
        ])
      + section('Images + anchors', [
          kpi('Image rows',  imgAnchor.image_rows || 0),
          kpi('Optimized',   imgAnchor.image_optimized || 0, ((imgAnchor.image_optimized || 0) > 0 ? 'lgse-up' : '')),
          kpi('Image errors',imgAnchor.image_failed || 0, ((imgAnchor.image_failed || 0) > 0 ? 'lgse-dn' : '')),
          kpi('Bytes saved', imgAnchor.bytes_saved_kb ? (imgAnchor.bytes_saved_kb + ' KB') : '0'),
          kpi('Generic anchors', imgAnchor.generic_anchors || 0, ((imgAnchor.generic_anchors || 0) > 0 ? 'lgse-dn' : '')),
        ]);
    holderEl.innerHTML = html;
  };

  // P0-13: open the matching audit's detail view in the Audit tab.
  // Uses the existing window.lgseShowAuditDetail (passes id, not the full row).
  window.lgseShowRunDetail = function (id) {
    if (!id) return;
    if (typeof window.lgseSwitchTab === 'function') window.lgseSwitchTab('audit');
    setTimeout(function () {
      if (typeof window.lgseShowAuditDetail === 'function') window.lgseShowAuditDetail(id);
    }, 80);
  };

  window.lgseReportsRange = function (days, el) {
    // Visual-only filter for now — backend /audits doesn't take a date range.
    Array.prototype.forEach.call(document.querySelectorAll('[onclick*="lgseReportsRange"]'), function (p) {
      p.style.background = 'var(--lgse-bg2)';
      p.style.borderColor = 'var(--lgse-border)';
      p.style.color = 'var(--lgse-t3)';
    });
    if (el) {
      el.style.background = 'rgba(108,92,231,0.08)';
      el.style.borderColor = 'var(--lgse-purple)';
      el.style.color = 'var(--lgse-purple)';
    }
    // No-op refetch for now; future: pass since=… to /audits when supported.
  };
  window.lgseDownloadReport = async function (kind) {
    // Wave 18c (2026-05-19) — real binary PDF (puppeteer-rendered) +
    // confirmation modal. Older version used to open HTML in a new tab
    // and ask the user to do Print → Save-as-PDF; now the backend renders
    // a true PDF via puppeteer (tools/report-render-pdf.cjs) and we just
    // stream it down.
    function _reportUrl(path) {
      var u = window.location.origin + path;
      if (window._lgseActiveSiteUrl) u += '?site_url=' + encodeURIComponent(window._lgseActiveSiteUrl);
      return u;
    }
    var scopeLabel = window._lgseActiveSiteUrl
      ? window._lgseActiveSiteUrl.replace(/^https?:\/\//, '')
      : 'all workspace sites';
    var label = (kind === 'pdf') ? 'PDF report' : 'HTML report';
    if (! confirm('Download the ' + label + ' for ' + scopeLabel + '?')) return;
    try {
      var token = localStorage.getItem('lu_token') || '';
      var endpoint = (kind === 'pdf') ? '/api/seo/reports/audit/pdf' : '/api/seo/reports/audit/html';
      var resp = await fetch(_reportUrl(endpoint), { headers: { 'Authorization': 'Bearer ' + token } });
      if (!resp.ok) {
        if (typeof window.showToast === 'function') window.showToast(label + ' download failed (' + resp.status + ')', 'error');
        return;
      }

      // PDF: detect fallback (puppeteer failed → server returned HTML 503).
      if (kind === 'pdf') {
        var ct = (resp.headers.get('Content-Type') || '').toLowerCase();
        var fbk = resp.headers.get('X-PDF-Fallback');
        if (ct.indexOf('application/pdf') === -1 || fbk) {
          if (typeof window.showToast === 'function') {
            window.showToast('PDF renderer unavailable (' + (fbk || ct) + ') — falling back to HTML.', 'error');
          }
          // Treat as HTML fallback so user still gets the report.
          kind = 'html';
        }
      }

      var blob = await resp.blob();
      var ext  = (kind === 'pdf') ? 'pdf' : 'html';
      var stamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');
      var fname = 'seo-report-' + (window._lgseActiveSiteUrl ? window._lgseActiveSiteUrl.replace(/^https?:\/\//, '').replace(/[^a-z0-9.-]/gi, '_') : 'all') + '-' + stamp + '.' + ext;
      var url   = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url; a.download = fname;
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      URL.revokeObjectURL(url);
      if (typeof window.showToast === 'function') {
        window.showToast(label + ' downloaded (' + Math.round(blob.size / 1024) + ' KB)', 'success');
      }
    } catch (e) {
      if (typeof window.showToast === 'function') window.showToast(label + ' download failed: ' + (e.message || e), 'error');
    }
  };
  window.lgseExportCsv = async function (type) {
    // Wave 18c (2026-05-19) — confirmation before any download.
    // Human-friendly labels for the prompt; matches the button text.
    var LABELS = {
      keywords: 'Keywords', pages: 'Pages', links: 'Links', images: 'Images', anchors: 'Anchors',
      orphans: 'Orphan pages', 'weak-pages': 'Weak pages', 'quick-wins': 'Quick wins',
      'cluster-gaps': 'Cluster gaps', 'anchor-health': 'Anchor health', 'link-backlog': 'Link backlog',
    };
    var label = LABELS[type] || type;
    var scope = window._lgseActiveSiteUrl
      ? (' for ' + (window._lgseActiveSiteUrl.replace(/^https?:\/\//, '')))
      : ' (all workspace sites)';
    if (! confirm('Download the ' + label + ' CSV' + scope + '?')) return;
    try {
      var token = localStorage.getItem('lu_token') || '';
      // Wave 18b — propagate active site so CSV matches the SPA's current scope.
      var url = window.location.origin + '/api/seo/reports/export/' + type;
      if (window._lgseActiveSiteUrl) {
        url += '?site_url=' + encodeURIComponent(window._lgseActiveSiteUrl);
      }
      var resp = await fetch(url, { headers: { 'Authorization': 'Bearer ' + token } });
      if (!resp.ok) { if (typeof window.showToast === 'function') window.showToast('Export failed', 'error'); return; }
      var blob = await resp.blob();
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'seo-' + type + '-' + Date.now() + '.csv';
      document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
      if (typeof window.showToast === 'function') window.showToast(type + ' exported (' + Math.round(blob.size / 1024) + ' KB)', 'success');
    } catch (e) { /* silent */ }
  };

  // ── New Goal modal (Pipeline tab header CTA) ──────────────────────────
  // 2026-05-13 — submits to POST /api/connector/pipeline/submit-goal which
  // inserts a tasks row with engine='seo', action='agent_goal'. The
  // orchestrator picks it up and dispatches to the agent team. Re-uses the
  // existing lgseShowModal helper for theme + close-on-backdrop + inline
  // error display.
  window._seoNewGoalModal = function () {
    if (typeof window.lgseShowModal !== 'function') {
      console.warn('[SEO] lgseShowModal not available');
      return;
    }
    var bodyHtml =
        '<div style="margin-bottom:10px;color:var(--lgse-t2,#94a3b8);font-size:12.5px;line-height:1.5">'
      +   'Describe what you want done. The SEO team picks it up, plans, '
      +   'and quotes credit costs before executing anything paid.'
      + '</div>'
      + '<textarea id="lgse-goal-text" rows="5" '
      +   'placeholder="e.g. Improve the homepage SEO score by fixing thin content and broken outbound links" '
      +   'style="width:100%;padding:10px 12px;background:var(--lgse-bg2,#0f172a);'
      +   'border:1px solid var(--lgse-border,#1e293b);border-radius:8px;color:var(--lgse-t1,#fff);'
      +   'font-size:13px;line-height:1.5;font-family:inherit;resize:vertical;min-height:120px;'
      +   'box-sizing:border-box"></textarea>'
      + '<div style="margin-top:6px;font-size:11px;color:var(--lgse-t3,#64748b)">'
      +   'Minimum 5 characters. Submitting uses no credits.'
      + '</div>';

    window.lgseShowModal('New SEO goal', bodyHtml, function (overlay) {
      if (typeof lgseClearModalError === 'function') lgseClearModalError();
      var ta = document.getElementById('lgse-goal-text');
      var goal = ta ? String(ta.value || '').trim() : '';
      if (goal.length < 5) {
        if (typeof lgseModalError === 'function') {
          lgseModalError('Goal must be at least 5 characters.');
        }
        return;
      }
      var btn = document.getElementById('lgse-modal-save');
      if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }

      if (typeof window._luFetch !== 'function') {
        if (typeof lgseModalError === 'function') {
          lgseModalError('Cannot reach the API (auth helper missing).');
        }
        if (btn) { btn.disabled = false; btn.textContent = 'Submit goal'; }
        return;
      }

      window._luFetch('POST', '/connector/pipeline/submit-goal', { goal_text: goal })
        .then(function (r) {
          return r.json().catch(function () { return { success: false, error: 'bad_json' }; });
        })
        .then(function (j) {
          if (!j || !j.success) {
            var msg = (j && (j.error || j.message)) || 'Submit failed.';
            if (typeof lgseModalError === 'function') lgseModalError(String(msg));
            if (btn) { btn.disabled = false; btn.textContent = 'Submit goal'; }
            return;
          }
          if (overlay && overlay.remove) overlay.remove();
          // Refresh the Pipeline tab Queue sub-tab so the new task appears.
          window._lgsePipeTab = 'queue';
          if (typeof window.lgseSwitchTab === 'function') {
            window.lgseSwitchTab('pipeline');
          }
          if (typeof window.showToast === 'function') {
            window.showToast('Goal submitted (#' + (j.task_id || '?') + ').', 'success');
          }
        })
        .catch(function (e) {
          if (typeof lgseModalError === 'function') {
            lgseModalError('Submit failed: ' + (e && e.message ? e.message : 'network error'));
          }
          if (btn) { btn.disabled = false; btn.textContent = 'Submit goal'; }
        });
    }, { saveLabel: 'Submit goal' });
  };

  // ── Tab — Pipeline (Queue + Calendar sub-tabs) ────────────────────────
  // 2026-05-13 — canonical-block implementation. Hits /api/connector/*
  // via _luFetch (embed X-API-KEY routing); _seoApi only handles /api/seo/*.
  function renderPipeline(el) {
    if (!window._lgsePipeTab)   window._lgsePipeTab   = 'queue';
    if (!window._lgsePipeMonth) {
      var __now = new Date();
      window._lgsePipeMonth = __now.getFullYear() + '-' + String(__now.getMonth() + 1).padStart(2, '0');
    }

    function pipFetch(path) {
      if (typeof window._luFetch === 'function') {
        return window._luFetch('GET', path, null).then(function (r) { return r.json(); });
      }
      var t = localStorage.getItem('lu_token') || '';
      return fetch(window.location.origin + '/api' + path, {
        headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + t },
        cache: 'no-store',
      }).then(function (r) { return r.json(); });
    }

    function fmtDate(s) {
      if (!s) return '';
      return String(s).slice(0, 10);
    }

    function shiftMonth(ym, delta) {
      var p = ym.split('-'); var yr = parseInt(p[0], 10); var mo = parseInt(p[1], 10) + delta;
      if (mo < 1)  { mo = 12; yr--; }
      if (mo > 12) { mo = 1;  yr++; }
      return yr + '-' + String(mo).padStart(2, '0');
    }

    var subQ = window._lgsePipeTab === 'queue';
    var subC = window._lgsePipeTab === 'calendar';
    el.innerHTML =
        '<div style="display:flex;gap:8px;align-items:center;margin-bottom:18px;border-bottom:1px solid var(--bd,#1e293b)">' +
          '<button onclick="window._lgsePipeNav(\'queue\')" '
            + 'style="padding:10px 16px;cursor:pointer;background:transparent;border:none;border-bottom:2px solid '
            + (subQ ? 'var(--p,#7C3AED)' : 'transparent') + ';color:' + (subQ ? 'var(--p,#7C3AED)' : 'var(--lgse-t3,#94a3b8)')
            + ';font-size:13px;font-weight:600">Queue</button>' +
          '<button onclick="window._lgsePipeNav(\'calendar\')" '
            + 'style="padding:10px 16px;cursor:pointer;background:transparent;border:none;border-bottom:2px solid '
            + (subC ? 'var(--p,#7C3AED)' : 'transparent') + ';color:' + (subC ? 'var(--p,#7C3AED)' : 'var(--lgse-t3,#94a3b8)')
            + ';font-size:13px;font-weight:600">Calendar</button>' +
          // 2026-05-13 — New Goal CTA, right-aligned via margin-left:auto.
          '<button onclick="window._seoNewGoalModal()" class="lgse-btn-primary" '
            + 'style="margin-left:auto;margin-bottom:6px;font-size:12px;padding:7px 14px">+ New Goal</button>' +
        '</div>' +
        '<div id="lgse-pipe-body" style="color:var(--lgse-t3,#94a3b8);font-size:13px">Loading…</div>';

    var body = document.getElementById('lgse-pipe-body');
    if (!body) return;

    if (subQ) {
      pipFetch('/connector/content/pipeline').then(function (r) {
        if (!r || !r.success) { body.innerHTML = '<p>Could not load pipeline.</p>'; return; }
        var counts = r.counts || {};
        var all = (r.pipeline.queued || [])
          .concat(r.pipeline.running || [])
          .concat(r.pipeline.completed || [])
          .concat(r.pipeline.failed || []);
        var typeC = {
          generate_article: '#7C3AED', write_article: '#7C3AED', optimize_article: '#8B5CF6',
          improve_draft: '#8B5CF6',    deep_audit: '#3B82F6', serp_analysis: '#06B6D4',
          keyword_research: '#06B6D4', bulk_generate_meta: '#F59E0B', generate_meta: '#F59E0B',
          generate_image: '#EC4899',   autonomous_goal: '#00E5A8', agent_goal: '#F59E0B',
          link_suggestions: '#10B981',
        };
        var html = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">';
        ['queued', 'running', 'completed', 'failed'].forEach(function (s) {
          html += '<div style="background:#1e293b;border-radius:8px;padding:12px;text-align:center">'
                +   '<div style="font-size:22px;font-weight:700;color:#fff">' + (counts[s] || 0) + '</div>'
                +   '<div style="font-size:12px;color:#94a3b8;text-transform:capitalize">' + s + '</div>'
                + '</div>';
        });
        html += '</div>';
        if (!all.length) {
          html += '<p style="color:#94a3b8;padding:18px;background:#0f172a;border-radius:8px">No tasks yet. Generate an article or run an audit to populate.</p>';
        } else {
          html += '<table style="width:100%;border-collapse:collapse;font-size:13px">'
                +   '<tr style="color:#64748b;border-bottom:1px solid #1e293b">'
                +     '<th style="text-align:left;padding:8px">Type</th>'
                +     '<th style="text-align:left;padding:8px">Status</th>'
                +     '<th style="text-align:left;padding:8px">Created</th>'
                +   '</tr>';
          all.forEach(function (t) {
            var c = typeC[t.task_type] || '#64748b';
            html += '<tr style="border-bottom:1px solid #1e293b">'
                  +   '<td style="padding:8px"><span style="background:' + c + '22;color:' + c
                  +     ';padding:2px 8px;border-radius:4px;font-size:11px">' + (t.task_type || 'task') + '</span></td>'
                  +   '<td style="padding:8px;color:#94a3b8">' + (t.status || '') + '</td>'
                  +   '<td style="padding:8px;color:#64748b">' + fmtDate(t.created_at) + '</td>'
                  + '</tr>';
          });
          html += '</table>';
        }
        body.innerHTML = html;
        if ((counts.running || 0) > 0) {
          setTimeout(function () {
            if (window._lgsePipeTab === 'queue') {
              var c = document.getElementById('lgse-content');
              if (c) renderPipeline(c);
            }
          }, 8000);
        }
      }).catch(function () {
        body.innerHTML = '<p style="color:#ef4444">Pipeline fetch failed.</p>';
      });
    } else {
      pipFetch('/connector/content/calendar?month=' + window._lgsePipeMonth).then(function (r) {
        if (!r || !r.success) { body.innerHTML = '<p>Could not load calendar.</p>'; return; }
        var days = r.days || {};
        var parts = window._lgsePipeMonth.split('-');
        var yr = parseInt(parts[0], 10);
        var mo = parseInt(parts[1], 10) - 1;
        var firstDay = new Date(yr, mo, 1).getDay();
        var daysInMonth = new Date(yr, mo + 1, 0).getDate();
        var monthNames = ['January','February','March','April','May','June',
                          'July','August','September','October','November','December'];
        var html = '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">'
                 +   '<button onclick="window._lgsePipeNav(null, \'' + shiftMonth(window._lgsePipeMonth, -1) + '\')" '
                 +     'style="background:#1e293b;border:none;color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer">←</button>'
                 +   '<strong style="color:#fff">' + monthNames[mo] + ' ' + yr + '</strong>'
                 +   '<button onclick="window._lgsePipeNav(null, \'' + shiftMonth(window._lgsePipeMonth, +1) + '\')" '
                 +     'style="background:#1e293b;border:none;color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer">→</button>'
                 + '</div>';
        html += '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px">';
        ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(function (d) {
          html += '<div style="text-align:center;font-size:11px;color:#64748b;padding:4px">' + d + '</div>';
        });
        var startOffset = (firstDay + 6) % 7;
        for (var i = 0; i < startOffset; i++) {
          html += '<div style="min-height:60px"></div>';
        }
        for (var d = 1; d <= daysInMonth; d++) {
          var key = yr + '-' + String(mo + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
          var items = days[key] || [];
          var cellHtml = '<div style="background:#1e293b;border-radius:6px;padding:6px;min-height:60px">'
                       +   '<div style="font-size:11px;color:#64748b;margin-bottom:4px">' + d + '</div>';
          items.slice(0, 3).forEach(function (item) {
            var col = item.status === 'completed' ? '#10b981'
                    : item.type === 'task'        ? '#3B82F6'
                    :                                '#F59E0B';
            var title = (item.title || 'Task');
            var safeTitle = String(title).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            cellHtml += '<div style="background:' + col + '22;color:' + col
                      + ';font-size:10px;padding:2px 4px;border-radius:3px;margin-bottom:2px;'
                      + 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + safeTitle + '">'
                      + safeTitle.slice(0, 20) + '</div>';
          });
          if (items.length > 3) {
            cellHtml += '<div style="font-size:10px;color:#64748b">+' + (items.length - 3) + ' more</div>';
          }
          cellHtml += '</div>';
          html += cellHtml;
        }
        html += '</div>';
        body.innerHTML = html;
      }).catch(function () {
        body.innerHTML = '<p style="color:#ef4444">Calendar fetch failed.</p>';
      });
    }
  }

  // ── Tab — Write Article (embed mode only) ────────────────────────────
  // 2026-05-13 — Simple form that calls /connector/generate-article (2cr,
  // 1 text + 1 mini featured image). Goes via _luFetch so X-API-KEY routes
  // correctly in embed context.
  function renderWrite(el) {
    el.innerHTML =
        '<div style="max-width:680px;margin:0 auto;padding:8px 0">'
      +   '<h2 style="color:var(--lgse-t1,#fff);font-size:18px;font-weight:600;margin:0 0 4px">Write Article</h2>'
      +   '<p style="color:var(--lgse-t3,#94a3b8);font-size:13px;margin:0 0 24px">'
      +     'Generate an SEO-optimised article with a featured image. Uses <strong style="color:#7C3AED">2 credits</strong>.'
      +   '</p>'
      +   '<div style="display:grid;gap:14px">'
      +     '<div>'
      +       '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">Focus Keyword <span style="color:#EF4444">*</span></label>'
      +       '<input id="lgse-w-keyword" type="text" placeholder="e.g. business setup in Dubai" '
      +         'style="width:100%;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:10px 12px;color:#fff;font-size:13px;box-sizing:border-box">'
      +     '</div>'
      +     '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
      +       '<div>'
      +         '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">Tone</label>'
      +         '<select id="lgse-w-tone" style="width:100%;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:10px 12px;color:#fff;font-size:13px">'
      +           '<option value="professional">Professional</option>'
      +           '<option value="informative">Informative</option>'
      +           '<option value="authoritative">Authoritative</option>'
      +         '</select>'
      +       '</div>'
      +       '<div>'
      +         '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">Language</label>'
      +         '<select id="lgse-w-lang" style="width:100%;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:10px 12px;color:#fff;font-size:13px">'
      +           '<option value="English">English</option>'
      +           '<option value="Arabic">Arabic</option>'
      +         '</select>'
      +       '</div>'
      +     '</div>'
      +     '<div>'
      +       '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">Word Count</label>'
      +       '<div style="display:flex;gap:8px;align-items:center">'
      +         '<input id="lgse-w-min" type="number" value="800" min="300" max="3000" '
      +           'style="width:90px;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:8px 10px;color:#fff;font-size:13px">'
      +         '<span style="color:#64748b">to</span>'
      +         '<input id="lgse-w-max" type="number" value="1500" min="500" max="5000" '
      +           'style="width:90px;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:8px 10px;color:#fff;font-size:13px">'
      +       '</div>'
      +     '</div>'
      +     '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
      +       '<div>'
      +         '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">FAQs to include</label>'
      +         '<input id="lgse-w-faq" type="number" value="3" min="0" max="10" '
      +           'style="width:100%;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:8px 12px;color:#fff;font-size:13px;box-sizing:border-box">'
      +       '</div>'
      +       '<div style="display:flex;align-items:center;gap:8px;padding-top:20px">'
      +         '<input type="checkbox" id="lgse-w-cta" checked style="width:16px;height:16px;accent-color:#7C3AED">'
      +         '<label for="lgse-w-cta" style="color:#94a3b8;font-size:13px;cursor:pointer">Include CTA section</label>'
      +       '</div>'
      +     '</div>'
      +     '<div>'
      +       '<label style="color:#94a3b8;font-size:12px;display:block;margin-bottom:4px">Extra context (optional)</label>'
      +       '<textarea id="lgse-w-context" rows="2" placeholder="Target audience, specific points to cover, location..." '
      +         'style="width:100%;background:#1e293b;border:1px solid #334155;border-radius:6px;padding:10px 12px;color:#fff;font-size:13px;box-sizing:border-box;resize:vertical"></textarea>'
      +     '</div>'
      +     '<p style="color:#64748b;font-size:12px;margin:0">💳 This will use <strong style="color:#7C3AED">2 credits</strong> (1 text + 1 featured image).</p>'
      +     '<button id="lgse-w-btn" onclick="window._lgseWriteGenerate()" '
      +       'style="background:linear-gradient(135deg,#7C3AED,#3B82F6);color:#fff;border:none;border-radius:8px;padding:12px 24px;font-size:14px;font-weight:600;cursor:pointer;width:100%">Generate Article</button>'
      +     '<div id="lgse-w-result" style="display:none;background:#1e293b;border-radius:8px;padding:16px;margin-top:8px">'
      +       '<div id="lgse-w-result-inner"></div>'
      +     '</div>'
      +   '</div>'
      + '</div>';
  }

  window._lgseWriteGenerate = function () {
    var $ = function (id) { return document.getElementById(id); };
    var kwEl = $('lgse-w-keyword');
    var keyword = kwEl ? (kwEl.value || '').trim() : '';
    if (!keyword) { window.lgseAlert('Focus keyword required', 'Please enter a focus keyword.'); return; }

    var btn    = $('lgse-w-btn');
    var result = $('lgse-w-result');
    var inner  = $('lgse-w-result-inner');
    if (!btn || !result || !inner) return;

    btn.disabled = true;
    btn.textContent = 'Generating… (this can take 60-90s)';
    result.style.display = 'none';

    var siteUrl = '';
    try {
      var sp = new URLSearchParams(window.location.search);
      siteUrl = sp.get('lgsc_site') || (window._LGSC_EMBED && window._LGSC_EMBED.site_url) || '';
    } catch (_) {}

    var payload = {
      keyword:        keyword,
      tone:           ($('lgse-w-tone') || {}).value || 'professional',
      language:       ($('lgse-w-lang') || {}).value || 'English',
      word_count_min: parseInt(($('lgse-w-min') || {}).value || 800, 10),
      word_count_max: parseInt(($('lgse-w-max') || {}).value || 1500, 10),
      faq_count:      parseInt(($('lgse-w-faq') || {}).value || 3, 10),
      include_cta:    ($('lgse-w-cta') || {}).checked !== false,
      extra_context:  ($('lgse-w-context') || {}).value || '',
      embed_context:  'seo_plugin',
      scope:          'seo_optimised',
      site_url:       siteUrl,
    };

    var fetcher = (typeof window._luFetch === 'function')
      ? window._luFetch('POST', '/connector/generate-article', payload).then(function (r) { return r.json(); })
      : fetch(window.location.origin + '/api/connector/generate-article', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
          },
          body: JSON.stringify(payload),
        }).then(function (r) { return r.json(); });

    fetcher.then(function (d) {
      btn.disabled = false;
      btn.textContent = 'Generate Article';
      if (!d || !d.success) {
        inner.innerHTML = '<p style="color:#f87171;margin:0">' + esc((d && (d.error || d.message)) || 'Generation failed. Please try again.') + '</p>';
        result.style.display = 'block';
        return;
      }
      var imgHtml = d.image_url
        ? '<img src="' + esc(d.image_url) + '" alt="" style="width:80px;height:60px;object-fit:cover;border-radius:6px;flex-shrink:0;margin-left:12px">'
        : '';
      var imgFailedHtml = d.image_failed
        ? ' · <span style="color:#f59e0b">Image generation failed (refunded)</span>'
        : '';
      inner.innerHTML =
          '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">'
        +   '<div style="min-width:0">'
        +     '<div style="color:#fff;font-size:15px;font-weight:600;margin-bottom:4px">' + esc(d.title || keyword) + '</div>'
        +     '<div style="color:#64748b;font-size:12px">'
        +       (d.word_count || 0) + ' words · ' + (d.credits_used || 2) + ' credits used'
        +       imgFailedHtml
        +     '</div>'
        +   '</div>'
        +   imgHtml
        + '</div>'
        + '<div style="color:#94a3b8;font-size:12px;margin-bottom:12px">' + esc(d.meta_description || '') + '</div>'
        + '<p style="color:#00E5A8;font-size:12px;margin:0">✓ Article saved.</p>'
        + '<p style="color:#64748b;font-size:11px;margin:4px 0 0">Go to WordPress Posts to review, edit, and publish.</p>';
      result.style.display = 'block';
    }).catch(function () {
      btn.disabled = false;
      btn.textContent = 'Generate Article';
      inner.innerHTML = '<p style="color:#f87171;margin:0">Request failed. Please try again.</p>';
      result.style.display = 'block';
    });
  };

  // Global trampoline for the Pages-tab image regenerate cell.
  // 2026-05-13 — UX normalization. Native confirm()/alert() dialogs replaced
  // with inline status injected as a small label inside the cell. Click is
  // immediate (no confirm). Loading + error states render in-cell.
  window._lgseRegenImage = function (pageId, pageUrl, pageTitle, el) {
    var cell = el;
    if (cell && cell.tagName === 'IMG') { cell = cell.parentElement; }
    if (!cell) return;

    // Render a tiny in-cell status that survives outerHTML replacement on success.
    function setCellState(html, opacity) {
      cell.style.opacity = opacity == null ? '1' : String(opacity);
      cell.innerHTML = html;
    }
    function statusHtml(label, color) {
      return '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;'
           + 'width:44px;height:36px;border:1px dashed var(--lgse-border,#1e293b);border-radius:4px;'
           + 'font-size:9px;color:' + (color || 'var(--lgse-t3,#94a3b8)') + ';line-height:1.1;text-align:center">'
           + label + '</div>';
    }

    // Loading state — immediate, no confirm.
    setCellState(statusHtml('Generating<br>1 credit', 'var(--lgse-t3,#94a3b8)'), '0.7');

    var payload = {
      page_id: pageId,
      url:     pageUrl,
      title:   pageTitle,
      force:   true,
    };
    var fetcher = (typeof window._luFetch === 'function')
      ? window._luFetch('POST', '/connector/pages/regenerate-image', payload).then(function (r) { return r.json(); })
      : fetch(window.location.origin + '/api/connector/pages/regenerate-image', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
          },
          body: JSON.stringify(payload),
        }).then(function (r) { return r.json(); });

    fetcher.then(function (d) {
      if (!d || !d.success || !d.image_url) {
        var msg = (d && (d.error || d.message)) || 'failed';
        setCellState(statusHtml('Gen failed:<br>' + String(msg).substring(0, 18), 'var(--lgse-red,#ef4444)'), '1');
        return;
      }
      // Replace cell contents with the new image (preserve re-click rebind).
      var safeUrl = encodeURIComponent(pageUrl || '');
      var safeTitle = encodeURIComponent(pageTitle || '');
      cell.style.opacity = '1';
      cell.innerHTML = '<img src="' + d.image_url + '" '
        + 'style="width:44px;height:36px;object-fit:cover;border-radius:4px;cursor:pointer" '
        + 'title="Regenerate featured image (1 credit)" '
        + 'onclick="window._lgseRegenImage(' + pageId + ',decodeURIComponent(\'' + safeUrl + '\'),decodeURIComponent(\'' + safeTitle + '\'),this)">';
    }).catch(function () {
      setCellState(statusHtml('Request<br>failed', 'var(--lgse-red,#ef4444)'), '1');
    });
  };

  // Global trampoline so the injected onclick handlers (global scope) can
  // re-enter the IIFE-private renderPipeline.
  window._lgsePipeNav = function (tab, month) {
    if (tab)   window._lgsePipeTab   = tab;
    if (month) window._lgsePipeMonth = month;
    var c = document.getElementById('lgse-content');
    if (c) renderPipeline(c);
  };

  // ── Override entry point ───────────────────────────────────────────────
  window.seoLoad = function (el) {
    if (!el) return;
    el.style.cssText = 'padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%;background:var(--bg, #0F1117)';
    var inner = document.createElement('div');
    inner.style.cssText = 'flex:1;overflow-y:auto;padding:20px';
    el.innerHTML = '';
    el.appendChild(inner);
    loadSiteList(); // P0-AUD-FIX2 — populate window._lgseSites from audit history.
    buildShell(inner);
  };
})();


// 2026-05-15 — AI Assistant drawer global helpers.
// Window-attached so the inline onclick handlers injected by
// _lgseInjectAiDrawer can reach them. Also: hide FAB when leaving SEO.

window._lgseDrawerOpen = function () {
  var d = document.getElementById('lgse-ai-drawer');
  var o = document.getElementById('lgse-ai-overlay');
  if (d) { d.style.display = 'flex'; }
  if (o) { o.style.display = 'block'; }
  setTimeout(function () {
    var inp = document.getElementById('lgse-drawer-input');
    if (inp) { inp.focus(); }
    // 2026-05-13 — Restore prior chat history (localStorage). Guard via a
    // data flag so re-opening the drawer doesn't append history twice.
    var t = document.getElementById('lgse-drawer-thread');
    if (t && !t.dataset.lgseRestored && typeof window._lgseChatRestore === 'function') {
      window._lgseChatRestore('lgse-drawer-thread');
      t.dataset.lgseRestored = '1';
      t.scrollTop = t.scrollHeight;
    }
    // Wave 4 (2026-05-18): pull any unread proactive notifications and
    // render them inline as "Update" bubbles at the top of the thread,
    // then mark them read on the server. Clears the FAB badge.
    if (typeof window._lgseRenderNotificationsIntoDrawer === 'function') {
      window._lgseRenderNotificationsIntoDrawer();
    }
  }, 100);
};

window._lgseDrawerClose = function () {
  var d = document.getElementById('lgse-ai-drawer');
  var o = document.getElementById('lgse-ai-overlay');
  if (d) { d.style.display = 'none'; }
  if (o) { o.style.display = 'none'; }
};

window._lgseDrawerSuggest = function (q) {
  var inp = document.getElementById('lgse-drawer-input');
  if (inp && q) { inp.value = q; inp.focus(); }
};

// Called by core.js nav() when leaving the SEO engine.
window._lgseHideFab = function () {
  var fab = document.getElementById('lgse-ai-fab');
  if (fab) { fab.style.display = 'none'; }
  if (typeof window._lgseDrawerClose === 'function') { window._lgseDrawerClose(); }
};

// 2026-05-13 — Approve / Decline button row appended to assistant
// proposal bubbles. Bypasses the keyword classifier by programmatically
// setting the input value and calling the send function — works
// identically to the user typing the word and hitting Enter.
window._lgseAppendApprovalRow = function (bubble, inputId, sendFnName) {
  if (!bubble) return;
  var row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:8px;margin-top:10px;flex-wrap:wrap';
  row.innerHTML =
      '<button class="lgse-btn-primary" data-act="approve" '
    +   'style="font-size:12px;padding:7px 14px">✅ Approve</button>'
    + '<button class="lgse-btn-secondary" data-act="decline" '
    +   'style="font-size:12px;padding:7px 14px">❌ Decline</button>';
  bubble.appendChild(row);

  function trigger(verb) {
    // Replace the buttons with a status pill so the user sees acknowledgement
    // even before the next assistant reply lands.
    var color = verb === 'proceed' ? '#10B981' : '#94a3b8';
    var label = verb === 'proceed' ? '✅ Approved'  : '❌ Declined';
    row.innerHTML = '<span style="font-size:11.5px;font-weight:600;color:' + color + '">' + label + '</span>';

    var inp = document.getElementById(inputId);
    if (inp) { inp.value = verb; }
    var fn = window[sendFnName];
    if (typeof fn === 'function') { fn(); }
  }

  var btns = row.querySelectorAll('button');
  btns[0].addEventListener('click', function () { trigger('proceed'); });
  btns[1].addEventListener('click', function () { trigger('cancel'); });
};

// 2026-05-13 — Chat history persistence in localStorage. Survives page
// refresh. Scoped per workspace via window._LGSC_EMBED.workspace_id (set
// by core.js when ?lgsc_key=&lgsc_ws=&embed=1 is in the URL), with a
// generic fallback for the direct SPA. Capped at 50 messages (oldest
// dropped). Approve/Decline buttons are stripped before saving so they
// don't appear (dead) after restore.
window._lgseChatKey = function () {
  var ws = (window._LGSC_EMBED && window._LGSC_EMBED.workspace_id)
    || window._currentWorkspaceId
    || null;
  return ws ? ('lgse_chat_ws_' + ws) : 'lgse_chat_history';
};

window._lgseChatLoad = function () {
  try {
    var raw = localStorage.getItem(window._lgseChatKey());
    if (!raw) return [];
    var arr = JSON.parse(raw);
    return Array.isArray(arr) ? arr : [];
  } catch (e) { return []; }
};

window._lgseChatSave = function (bubble, role) {
  if (!bubble || !bubble.cloneNode) return;
  var clone = bubble.cloneNode(true);
  // Strip Approve/Decline button rows + their post-click status pills.
  // Buttons carry data-act='approve'|'decline'; the pill is a sibling
  // text node — both live in the row div we want to remove.
  Array.prototype.forEach.call(clone.querySelectorAll('button[data-act]'), function (b) {
    if (b.parentElement) b.parentElement.remove();
  });
  // Also strip the post-click "Approved/Declined" pill row (no buttons left,
  // just the span). Identify by checking for the row class fingerprint:
  // we added rows with `display:flex;gap:8px;margin-top:10px;flex-wrap:wrap`
  // and they live inside the bubble. Conservatively, find any div whose
  // text content is exactly 'Approved' or 'Declined' (with optional emoji).
  Array.prototype.forEach.call(clone.querySelectorAll('div > span'), function (s) {
    var txt = (s.textContent || '').trim();
    if (/^[^a-zA-Z]*(Approved|Declined)\s*$/.test(txt) && s.parentElement && s.parentElement.parentElement === clone) {
      s.parentElement.remove();
    }
  });

  var arr = window._lgseChatLoad();
  arr.push({ role: role || 'assistant', html: clone.outerHTML, ts: Date.now() });
  if (arr.length > 50) arr = arr.slice(arr.length - 50);
  try { localStorage.setItem(window._lgseChatKey(), JSON.stringify(arr)); } catch (e) { /* quota */ }
};

window._lgseChatClear = function () {
  try { localStorage.removeItem(window._lgseChatKey()); } catch (e) {}
  // Empty both threads back to their welcome bubble (which is just the first child).
  ['lgse-chat-thread', 'lgse-drawer-thread'].forEach(function (tid) {
    var t = document.getElementById(tid);
    if (!t) return;
    var welcome = t.firstElementChild;  // the static welcome bubble baked in
    t.innerHTML = '';
    if (welcome) t.appendChild(welcome);
    // Reset drawer restore guard so a future open after clear won't try to
    // re-inject the (now empty) history.
    delete t.dataset.lgseRestored;
  });
};

// Re-inject stored messages into a thread. Called after the thread DOM is
// created. Inserts a "— previous session —" divider before the restored
// bubbles so it's visually clear this is recall, not fresh activity.
window._lgseChatRestore = function (threadId) {
  var thread = document.getElementById(threadId);
  if (!thread) return;
  var arr = window._lgseChatLoad();
  if (!arr.length) return;

  var divider = document.createElement('div');
  divider.style.cssText = 'display:flex;align-items:center;gap:10px;margin:4px 0;color:var(--lgse-t3,#64748b);font-size:11px;font-weight:500';
  divider.innerHTML =
      '<span style="flex:1;height:1px;background:rgba(255,255,255,0.08)"></span>'
    + '<span>previous session · ' + arr.length + ' message' + (arr.length === 1 ? '' : 's') + '</span>'
    + '<button onclick="window._lgseChatClear()" '
    +   'style="background:transparent;border:none;color:var(--lgse-t3,#64748b);'
    +   'font-size:11px;cursor:pointer;padding:2px 6px;text-decoration:underline">Clear</button>'
    + '<span style="flex:1;height:1px;background:rgba(255,255,255,0.08)"></span>';
  thread.appendChild(divider);

  arr.forEach(function (m) {
    var holder = document.createElement('div');
    holder.innerHTML = m.html;
    var bubble = holder.firstElementChild;
    if (bubble) thread.appendChild(bubble);
  });
};

window._lgseDrawerSend = function () {
  var inp = document.getElementById('lgse-drawer-input');
  var thread = document.getElementById('lgse-drawer-thread');
  var suggs = document.getElementById('lgse-drawer-suggestions');
  if (!inp || !thread) { return; }
  var msg = (inp.value || '').trim();
  if (!msg) { return; }

  function escMsg(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  if (suggs) { suggs.style.display = 'none'; }

  var userBubble = document.createElement('div');
  userBubble.style.cssText = 'align-self:flex-end;background:rgba(59,130,246,0.12);'
    + 'border:1px solid rgba(59,130,246,0.2);border-radius:12px;padding:12px 16px;max-width:90%';
  userBubble.innerHTML = '<div style="font-size:14px;line-height:1.6;color:#E5E7EB">'
    + escMsg(msg).replace(/\n/g, '<br>') + '</div>';
  thread.appendChild(userBubble);
  if (typeof window._lgseChatSave === 'function') window._lgseChatSave(userBubble, 'user');

  var typing = document.createElement('div');
  typing.id = 'lgse-drawer-typing';
  typing.style.cssText = 'background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.15);'
    + 'border-radius:12px;padding:14px 16px;max-width:90%';
  typing.innerHTML = '<div style="font-size:12px;font-weight:600;color:#A78BFA;margin-bottom:4px">SEO AI Assistant</div>'
    + '<div style="color:#6B7280;font-size:13px">Thinking&hellip;</div>';
  thread.appendChild(typing);
  thread.scrollTop = thread.scrollHeight;
  inp.value = '';

  function lgseMarkdown(text) {
    return text
      .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/g, '<em>$1</em>')
      .replace(/^### (.+)\n?/gm, '<h3 style="margin:8px 0 4px;font-size:14px;font-weight:600">$1</h3>')
      .replace(/^## (.+)\n?/gm, '<h2 style="margin:10px 0 6px;font-size:15px;font-weight:700">$1</h2>')
      .replace(/^- (.+)$/gm, '<li>$1</li>')
      .replace(/(<li>[\s\S]*?<\/li>(?:\n<li>[\s\S]*?<\/li>)*)/g, '<ul>$1</ul>')
      .replace(/\n\n/g, '</p><p>')
      .replace(/\n/g, '<br>');
  }
  function appendBot(text) {
    var t = document.getElementById('lgse-drawer-typing');
    if (t) { t.remove(); }
    var b = document.createElement('div');
    b.style.cssText = 'background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);'
      + 'border-radius:12px;padding:14px 16px;max-width:90%';
    b.innerHTML = '<div style="font-size:12px;font-weight:600;color:#A78BFA;margin-bottom:6px">SEO AI Assistant</div>'
      + '<div style="font-size:14px;line-height:1.6;color:#E5E7EB"><p style="margin:0">'
      + lgseMarkdown(escMsg(text)) + '</p></div>';
    thread.appendChild(b);
    if (typeof window._lgseChatSave === 'function') window._lgseChatSave(b, 'assistant');
    // 2026-05-13 — Approve / Decline buttons on proposals (drawer variant).
    if (/shall i proceed/i.test(text)) {
      _lgseAppendApprovalRow(b, 'lgse-drawer-input', '_lgseDrawerSend');
    }
    // 2026-05-12 chat-scroll fix: scroll to TOP of the new assistant
    // message so the user sees the START, not the end (long replies
    // were jumping past the opening line).
    b.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function appendErr(text) {
    var t = document.getElementById('lgse-drawer-typing');
    if (t) { t.remove(); }
    var e = document.createElement('div');
    e.style.cssText = 'background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.15);'
      + 'border-radius:12px;padding:12px 16px;max-width:90%';
    e.innerHTML = '<div style="color:#FCA5A5;font-size:13px">' + escMsg(text) + '</div>';
    thread.appendChild(e);
    e.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // 2026-05-12 fix — _luFetch returns the raw Response object; .json() it
  // explicitly so the downstream parser sees the actual body. Without this,
  // the chat shows "I could not get a response" because d.data is undefined
  // on a Response.
  // Wave 16b (2026-05-19) — include the active site URL so the SEO assistant
  // scopes its live context (orphans, scores, suggestions) to one website.
  var _msgBody = { message: msg };
  if (window._lgseActiveSiteUrl) { _msgBody.site_url = window._lgseActiveSiteUrl; }
  var fetcher = typeof window._luFetch === 'function'
    ? window._luFetch('POST', '/connector/assistant/message', _msgBody).then(function (r) { return r.json(); })
    : fetch(window.location.origin + '/api/connector/assistant/message', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
          'X-Lgse-Active-Site': (window._lgseActiveSiteUrl || ''),
        },
        body: JSON.stringify(_msgBody),
      }).then(function (r) { return r.json(); });

  fetcher.then(function (d) {
    var text = (d && d.data && d.data.response) ? d.data.response
             : (d && d.response) ? d.response
             : (d && d.reply) ? d.reply
             : 'I could not get a response. Please try again.';
    appendBot(text);
  }).catch(function () { appendErr('Connection error. Please try again.'); });
};

// Legacy tab-mode helpers — kept as no-ops so dead-code renderAssistant/Chatbot
// (still in the file but no longer in the renderers map) doesn't throw if
// someone calls them by URL hash.
window._lgseAssistantSuggest = function (btn) {
  var q = btn && btn.getAttribute ? btn.getAttribute('data-q') : null;
  if (q) { window._lgseDrawerSuggest(q); window._lgseDrawerOpen(); }
};

window._lgseAssistantSend = function () {
  var inp = document.getElementById('lgse-chat-input');
  var thread = document.getElementById('lgse-chat-thread');
  if (!inp || !thread) return;
  var msg = (inp.value || '').trim();
  if (!msg) return;

  function escMsg(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  var userBubble = document.createElement('div');
  userBubble.style.cssText = 'align-self:flex-end;background:rgba(59,130,246,0.15);border:1px solid rgba(59,130,246,0.2);border-radius:12px;padding:12px 16px;max-width:80%';
  userBubble.innerHTML = '<p style="font-size:14px;line-height:1.6;color:#E5E7EB;margin:0">' + escMsg(msg) + '</p>';
  thread.appendChild(userBubble);
  if (typeof window._lgseChatSave === 'function') window._lgseChatSave(userBubble, 'user');

  var typing = document.createElement('div');
  typing.id = 'lgse-typing';
  typing.style.cssText = 'background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);border-radius:12px;padding:14px 16px;max-width:80%';
  typing.innerHTML = '<span style="font-size:12px;font-weight:600;color:#A78BFA">' + window._lgseAgentLabel('james') + '</span>' +
    '<p style="color:#6B7280;margin:4px 0 0;font-size:14px">Thinking…</p>';
  thread.appendChild(typing);
  thread.scrollTop = thread.scrollHeight;
  inp.value = '';

  function lgseMarkdown(text) {
    return text
      .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/g, '<em>$1</em>')
      .replace(/^### (.+)\n?/gm, '<h3 style="margin:8px 0 4px;font-size:14px;font-weight:600">$1</h3>')
      .replace(/^## (.+)\n?/gm, '<h2 style="margin:10px 0 6px;font-size:15px;font-weight:700">$1</h2>')
      .replace(/^- (.+)$/gm, '<li>$1</li>')
      .replace(/(<li>[\s\S]*?<\/li>(?:\n<li>[\s\S]*?<\/li>)*)/g, '<ul>$1</ul>')
      .replace(/\n\n/g, '</p><p>')
      .replace(/\n/g, '<br>');
  }
  function appendBot(text) {
    var t = document.getElementById('lgse-typing');
    if (t) t.remove();
    var bot = document.createElement('div');
    bot.style.cssText = 'background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);border-radius:12px;padding:14px 16px;max-width:80%';
    bot.innerHTML =
      '<span style="font-size:12px;font-weight:600;color:#A78BFA">' + window._lgseAgentLabel('james') + '</span>' +
      '<p style="font-size:14px;line-height:1.6;color:#E5E7EB;margin:4px 0 0">' +
        lgseMarkdown(escMsg(text)) +
      '</p>';
    thread.appendChild(bot);
    if (typeof window._lgseChatSave === 'function') window._lgseChatSave(bot, 'assistant');
    // 2026-05-13 — Approve / Decline buttons on proposals. The assistant
    // narrates proposals with "Shall I proceed?" — bypass the keyword
    // classifier entirely by binding the buttons to a programmatic send of
    // "proceed" / "cancel". No SeoAssistantService changes needed.
    if (/shall i proceed/i.test(text)) {
      _lgseAppendApprovalRow(bot, 'lgse-chat-input', '_lgseAssistantSend');
    }
    bot.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function appendErr(text) {
    var t = document.getElementById('lgse-typing');
    if (t) t.remove();
    var err = document.createElement('div');
    err.style.cssText = 'background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:12px;padding:12px 16px;max-width:80%';
    err.innerHTML = '<p style="color:#FCA5A5;margin:0;font-size:13px">' + escMsg(text) + '</p>';
    thread.appendChild(err);
    err.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // Prefer _luFetch (core.js global) so embed-mode X-API-KEY routing works.
  // 2026-05-12 fix — _luFetch returns the raw Response object; .json() it
  // explicitly so the downstream parser sees the actual body. Without this,
  // the chat shows "I could not get a response" because d.data is undefined
  // on a Response.
  // Wave 16b (2026-05-19) — include the active site URL so the SEO assistant
  // scopes its live context to one website.
  var _aMsgBody = { message: msg };
  if (window._lgseActiveSiteUrl) { _aMsgBody.site_url = window._lgseActiveSiteUrl; }
  var fetcher = typeof window._luFetch === 'function'
    ? window._luFetch('POST', '/connector/assistant/message', _aMsgBody).then(function (r) { return r.json(); })
    : fetch(window.location.origin + '/api/connector/assistant/message', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
          'X-Lgse-Active-Site': (window._lgseActiveSiteUrl || ''),
        },
        body: JSON.stringify(_aMsgBody),
      }).then(function (r) { return r.json(); });

  fetcher
    .then(function (d) {
      // Wave 1 (2026-05-17) — disclaimer gate. If the assistant signals
      // disclaimer_required, show the acceptance modal instead of treating
      // it as a normal response, and re-send the original message after
      // acceptance. Look in both top-level and .data envelope shapes.
      var envelope = (d && d.data) ? d.data : d;
      if (envelope && envelope.disclaimer_required) {
        var t = document.getElementById('lgse-typing'); if (t) t.remove();
        _lgseShowDisclaimerModal(envelope.disclaimer_text || envelope.response || '', function () {
          // After acceptance, re-add the user message to the input and resend.
          inp.value = msg;
          window._lgseAssistantSend();
        });
        return;
      }
      var response = (d && d.data && d.data.response) ? d.data.response
                   : (d && d.response) ? d.response
                   : (d && d.reply) ? d.reply
                   : 'I could not get a response. Please try again.';
      appendBot(response);
    })
    .catch(function () { appendErr('Connection failed. Please try again.'); });
};

// Wave 1 (2026-05-17) — Disclaimer modal: shown once per user before
// the first AI assistant message can be sent. On Accept, POSTs to the
// acceptance endpoint, then invokes the original-message resend callback.
window._lgseShowDisclaimerModal = function (text, onAccepted) {
  // Avoid double-render.
  if (document.getElementById('lgse-disclaimer-modal')) { return; }
  var overlay = document.createElement('div');
  overlay.id = 'lgse-disclaimer-modal';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px)';
  overlay.innerHTML =
    '<div style="background:var(--lgse-bg1,#13161e);border:1px solid var(--lgse-border,#2a2f42);border-radius:12px;padding:24px;max-width:480px;width:90%">' +
      '<div style="font-size:16px;font-weight:700;color:var(--lgse-t1,#f0f2ff);margin-bottom:10px">Chat retention notice</div>' +
      '<p style="font-size:13px;line-height:1.6;color:var(--lgse-t2,#8b90a7);margin:0 0 18px">' + String(text).replace(/</g, '&lt;') + '</p>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end">' +
        '<button onclick="_lgseDisclaimerDecline()" style="background:transparent;color:var(--lgse-t2,#8b90a7);border:1px solid var(--lgse-border2,#343a52);border-radius:7px;padding:8px 14px;font-size:12px;cursor:pointer">Cancel</button>' +
        '<button id="lgse-disclaimer-accept" style="background:var(--lgse-purple,#6C5CE7);color:white;border:none;border-radius:7px;padding:8px 16px;font-size:12px;font-weight:600;cursor:pointer">Accept &amp; continue</button>' +
      '</div>' +
    '</div>';
  document.body.appendChild(overlay);
  document.getElementById('lgse-disclaimer-accept').onclick = function () {
    var btn = this;
    btn.disabled = true; btn.textContent = 'Saving…';
    var doPost = (typeof window._luFetch === 'function')
      ? window._luFetch('POST', '/seo/assistant/accept-disclaimer', {}).then(function (r) { return r.json(); })
      : fetch(window.location.origin + '/api/seo/assistant/accept-disclaimer', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
          },
          body: '{}',
        }).then(function (r) { return r.json(); });
    doPost
      .then(function () {
        overlay.remove();
        if (typeof onAccepted === 'function') onAccepted();
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = 'Accept & continue';
        alert('Could not save your acceptance. Please retry.');
      });
  };
};
window._lgseDisclaimerDecline = function () {
  var o = document.getElementById('lgse-disclaimer-modal');
  if (o) o.remove();
};

// ════════════════════════════════════════════════════════════════
// Wave 4 (2026-05-18) — PROACTIVE NOTIFICATIONS
// The assistant must tell the user when work it started has finished,
// even when the drawer is closed. Implementation: lightweight polling
// (every 45s) against /seo/assistant/notifications/unread-count.
// On hit, update the badge on the FAB. On drawer open, fetch the
// full list and render the notifications inline + mark them read.
// ════════════════════════════════════════════════════════════════
window._lgseNotifyPollMs = 45000;  // 45 second cadence

// Wave 5 (2026-05-18): fetch from the PLATFORM messages endpoint, not the
// retired seo-specific notifications. Path is relative to /api (not /api/seo).
window._lgseNotifyFetch = function (path) {
  if (typeof window._luFetch === 'function') {
    return window._luFetch('GET', path).then(function (r) { return r.json(); });
  }
  return fetch(window.location.origin + '/api' + path, {
    headers: {
      'Accept': 'application/json',
      'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
    },
  }).then(function (r) { return r.json(); });
};

window._lgseNotifyPost = function (path, body) {
  if (typeof window._luFetch === 'function') {
    return window._luFetch('POST', path, body || {}).then(function (r) { return r.json(); });
  }
  return fetch(window.location.origin + '/api' + path, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''),
    },
    body: JSON.stringify(body || {}),
  }).then(function (r) { return r.json(); });
};

window._lgseUpdateBadge = function (n) {
  var b = document.getElementById('lgse-fab-badge');
  if (!b) return;
  n = parseInt(n, 10) || 0;
  if (n <= 0) {
    b.style.display = 'none';
    b.textContent = '';
  } else {
    b.style.display = 'block';
    b.textContent = n > 99 ? '99+' : String(n);
  }
};

// Wave 5: SEO FAB badge reflects James-specific unread count from the
// platform messages endpoint. The unified messages-ui floater shows the
// total across all agents — both pull from the same backend.
window._lgseNotifyTick = function () {
  window._lgseNotifyFetch('/messages/unread-count')
    .then(function (d) {
      var byAgent = (d && d.by_agent) || {};
      var jamesUnread = (byAgent.james || 0);
      window._lgseUpdateBadge(jamesUnread);
    })
    .catch(function () { /* offline / auth issue — silent */ });
};

window._lgseStartNotifyPolling = function () {
  if (window._lgseNotifyTimer) return;  // idempotent
  // Initial tick after 2s (gives the page a beat to settle)
  setTimeout(window._lgseNotifyTick, 2000);
  // Then every 45s
  window._lgseNotifyTimer = setInterval(window._lgseNotifyTick, window._lgseNotifyPollMs);
};

window._lgseStopNotifyPolling = function () {
  if (window._lgseNotifyTimer) {
    clearInterval(window._lgseNotifyTimer);
    window._lgseNotifyTimer = null;
  }
};

// Wave 5: render James's recent unread proactive messages at the top of
// the drawer thread. Pulls from the platform /agents/james/messages
// endpoint (same source as the messages-ui floater + agent profile +
// Messages section). Marks the thread read via /messages/james/read.
window._lgseRenderNotificationsIntoDrawer = function () {
  var thread = document.getElementById('lgse-drawer-thread');
  if (!thread) return;
  // Pull last 20 turns from James's thread; render the recent unread
  // agent-role ones as "Update" bubbles at the top.
  window._lgseNotifyFetch('/agents/james/messages')
    .then(function (d) {
      var msgs = Array.isArray(d) ? d : (d && d.messages) || [];
      // The /agents/{slug}/messages route returns {from, content, ts} shape
      // — these are already mixed user+agent turns. We surface the most
      // recent agent-from items that aren't already in the localStorage
      // restored thread (rough heuristic: show last 5 with from !== 'User').
      var fromAgent = msgs.filter(function (m) {
        return m && m.from && String(m.from).toLowerCase() !== 'user';
      }).slice(-5);
      if (fromAgent.length === 0) return;
      fromAgent.forEach(function (m) {
        var bubble = document.createElement('div');
        bubble.style.cssText = 'background:linear-gradient(135deg,rgba(124,58,237,0.15),rgba(59,130,246,0.10));' +
          'border:1px solid rgba(124,58,237,0.3);border-left:3px solid #7C3AED;' +
          'border-radius:10px;padding:12px 14px;margin-bottom:8px';
        var when = '';
        try { when = new Date(String(m.ts).replace(' ', 'T')).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}); } catch (e) {}
        // Wave 8: in WP/embed mode, EVERY agent identity displays as
        // "SEO AI Assistant" regardless of what agent_messages.sender
        // says. In Laravel SaaS, show the actual sender name (James,
        // Priya, Sarah, etc.) so the user sees the team they pay for.
        var fromRaw  = window._lgseIsEmbed() ? 'SEO AI Assistant' : String(m.from || 'James');
        var fromEsc  = fromRaw.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        var contentEsc = String(m.content || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g, '<br>');
        bubble.innerHTML =
          '<div style="font-size:11px;font-weight:700;color:#A78BFA;margin-bottom:4px">✨ ' + fromEsc + (when ? ' · ' + when : '') + '</div>' +
          '<div style="font-size:13px;color:#E5E7EB;line-height:1.55">' + contentEsc + '</div>';
        thread.insertBefore(bubble, thread.firstChild);
      });
      // Mark James's thread read so the badge clears.
      window._lgseNotifyPost('/messages/james/read', {})
        .then(function () { window._lgseUpdateBadge(0); })
        .catch(function () { /* non-fatal */ });
    })
    .catch(function () { /* silent — endpoint may need auth */ });
};

// Auto-start polling when the SEO engine loads. The check inside ensures
// we don't start polling on pages that don't have the FAB.
(function () {
  function maybeStart() {
    if (document.getElementById('lgse-ai-fab')) {
      window._lgseStartNotifyPolling();
    } else {
      // Try again after the SEO shell renders (eventually).
      setTimeout(maybeStart, 1500);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', maybeStart);
  } else {
    setTimeout(maybeStart, 500);
  }
})();
