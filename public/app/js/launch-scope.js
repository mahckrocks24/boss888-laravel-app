/* ═══════════════════════════════════════════════════════════════════════════
 * LAUNCH SCOPE — frontend boundary guard.  W6, 2026-07-22.
 *
 * WHY THIS EXISTS AS A GUARD RATHER THAN A SET OF DELETIONS
 * ---------------------------------------------------------
 * Removing a nav label removes one symptom. This file removes the capability:
 * one authoritative list, enforced at every surface that can put a removed
 * feature in front of a customer — the sidebar, the router, quick actions,
 * command palette, search, and restored browser state. A crafted agent reply,
 * a stale cached payload, or a deep link cannot reintroduce what is listed here.
 *
 * It mirrors App\Core\LaunchScope\LaunchScopePolicy. The backend remains the
 * authority — this is defence in depth, not a substitute. Everything blocked
 * here is ALSO refused server-side (W1 kernel, W4 routes).
 *
 * PUBLISHER IS DELIBERATELY IN THE REMOVED LIST.
 * Its backend exists and is tested, but real Meta transmission is unproven, so
 * it must not be visible to customers. When provider proof passes, remove
 * 'publisher' from REMOVED_VIEWS and its actions from REMOVED_ACTIONS — that is
 * the whole activation switch.
 *
 * Loaded BEFORE core.js so nav() is wrapped before the first render.
 * ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var SCOPE_VERSION = 'dec0028-social-2026-08-29'; // bump purges cached rosters/actions once

  /* Views a customer may never reach. */
  /* DEC-0028 (2026-08-25) put SOCIAL back in launch; RISK-0099 (2026-08-29) applied it here —
   * this file was the third of four un-mirrored layers that kept social invisible. */
  var REMOVED_VIEWS = [
    'mentions', 'marketing', 'campaigns', 'automation',
    'inbox', 'engagement', 'listening', 'competitors',
    'publisher', 'content-publisher'
  ];

  /* Where a removed view sends the user instead. Never a dead end. */
  var SAFE_LANDING = 'workspace';

  /* Tools/actions that must never render as a quick action or chip. */
  var REMOVED_ACTIONS = [
    /* social actions restored (DEC-0028) */
    'create_campaign', 'update_campaign', 'delete_campaign', 'list_campaigns',
    'schedule_campaign', 'send_campaign', 'create_automation', 'toggle_automation',
    'ai_campaign_copy', 'create_template', 'list_templates', 'update_template',
    'delete_template', 'record_metric', 'test_send_email', 'send_email',
    'ai_generate_email', 'ai_rewrite_block', 'ai_suggest_subjects', 'ai_spam_check',
    'enroll_sequence', 'list_sequences', 'create_sequence', 'update_sequence',
    'delete_sequence',
    'reply_comment', 'manage_inbox', 'scan_watchlist', 'competitor_monitor',
    /* Publisher — built, tested, but not customer-visible until provider proof. */
    'publisher_create_draft', 'publisher_publish', 'publisher_schedule',
    'publisher_connect'
  ];

  /* Agents removed from the product. Never assignable, never rendered as available. */
  var REMOVED_AGENTS = [
    /* marcus restored (DEC-0028) — mirrors LaunchScopePolicy::REMOVED_AGENTS */
    'jordan', 'tyler', 'zara', 'zoe', 'maya',
    'vera', 'kai', 'chris', 'leo'
  ];

  /* Nav element ids to strip, and any label text that betrays a removed feature. */
  var REMOVED_NAV_IDS = [
    'ni-mentions', 'ni-marketing', 'ni-automation',
    'ni-campaigns', 'ni-inbox', 'ni-engagement', 'ni-publisher'
  ];

  var REMOVED_LABEL_RX = new RegExp(
    '^\\s*(mentions|marketing' +
    '|marketing\\s*automation|campaigns?|engagement|inbox|listening|competitors?' +
    '|email\\s*(marketing|campaigns?)|newsletters?|sequences?|publisher|content\\s*publisher)\\s*$',
    'i'
  );

  var lc = function (s) { return String(s == null ? '' : s).toLowerCase().trim(); };

  var API = {
    version: SCOPE_VERSION,
    removedViews: REMOVED_VIEWS.slice(),
    removedActions: REMOVED_ACTIONS.slice(),
    removedAgents: REMOVED_AGENTS.slice(),

    isRemovedView: function (v) { return REMOVED_VIEWS.indexOf(lc(v)) !== -1; },
    isRemovedAction: function (t) { return REMOVED_ACTIONS.indexOf(lc(t)) !== -1; },
    isRemovedAgent: function (a) { return REMOVED_AGENTS.indexOf(lc(a)) !== -1; },
    isRemovedLabel: function (l) { return REMOVED_LABEL_RX.test(String(l || '')); },

    /* Filter any list of {tool}/{action}/{id} objects down to retained ones. */
    filterActions: function (list) {
      if (!Array.isArray(list)) return [];
      return list.filter(function (item) {
        if (!item) return false;
        var key = item.tool || item.action || item.id || item.key || item;
        return !API.isRemovedAction(key);
      });
    },

    filterAgents: function (list) {
      if (!Array.isArray(list)) return [];
      return list.filter(function (a) {
        var slug = (a && (a.slug || a.id || a.agent_id || a.key)) || a;
        return !API.isRemovedAgent(slug);
      });
    },

    safeLanding: SAFE_LANDING
  };

  window.LU_SCOPE = API;

  /* ── 1. Strip removed nav items ─────────────────────────────────────────
   * Runs on load AND on mutation: some panels re-render their own nav, and a
   * one-shot pass would let an item reappear after a workspace switch. */
  function stripNav(root) {
    var scope = root || document;

    REMOVED_NAV_IDS.forEach(function (id) {
      var el = scope.getElementById ? scope.getElementById(id) : scope.querySelector('#' + id);
      if (el && el.parentNode) el.parentNode.removeChild(el);
    });

    /* Anything still wired to a removed view, whatever its id or label. */
    var candidates = scope.querySelectorAll ? scope.querySelectorAll('[onclick]') : [];
    Array.prototype.forEach.call(candidates, function (el) {
      var oc = el.getAttribute('onclick') || '';
      var m = oc.match(/nav\(\s*['"]([a-z0-9_-]+)['"]\s*\)/i);
      if (m && API.isRemovedView(m[1]) && el.parentNode) {
        el.parentNode.removeChild(el);
        return;
      }
      if (el.classList && el.classList.contains('nav-item') && API.isRemovedLabel(el.textContent)) {
        if (el.parentNode) el.parentNode.removeChild(el);
      }
    });

    /* A section heading left with no items under it reads as a bug. */
    var sections = scope.querySelectorAll ? scope.querySelectorAll('.nav-section') : [];
    Array.prototype.forEach.call(sections, function (sec) {
      var n = sec.nextElementSibling, count = 0;
      while (n && !(n.classList && n.classList.contains('nav-section'))) {
        if (n.classList && n.classList.contains('nav-item')) count++;
        n = n.nextElementSibling;
      }
      if (count === 0 && sec.parentNode) sec.parentNode.removeChild(sec);
    });
  }


  /* ── 1b. Strip removed AGENTS from the DOM ──────────────────────────────
   * The W6b audit found that isRemovedAgent() and filterAgents() existed but
   * had ZERO callers - the agent half of this guard was dead code. The static
   * Marcus card, his quick-prompt chip and the onboarding goal cards all use
   * openAgentDrawer()/prefill()/_ob2SelectGoal(), none of which is nav(), and
   * carry classes av-card/cq-btn/ob-goal-card, none of which is nav-item. Both
   * of stripNav's selectors therefore missed every one of them.
   *
   * The source has since been cleaned, so this is defence in depth: it also
   * catches anything re-introduced by a stale cached bundle or a server-
   * supplied roster. */
  var REMOVED_GOALS = ['email', 'newsletter', 'campaign'];

  function elMentionsRemovedAgent(el) {
    var oc = el.getAttribute && el.getAttribute('onclick') || '';
    var m = oc.match(/['"]@?([a-z]+)['"]/i);
    if (m && API.isRemovedAgent(m[1])) return true;
    var da = el.getAttribute && (el.getAttribute('data-orb-agent') || el.getAttribute('data-agent'));
    if (da && API.isRemovedAgent(da)) return true;
    return false;
  }

  function stripAgents(root) {
    var scope = root || document;
    if (!scope.querySelectorAll) return;

    /* Cards / chips / orbs bound to a removed agent. */
    var sel = '[onclick],[data-orb-agent],[data-agent]';
    Array.prototype.forEach.call(scope.querySelectorAll(sel), function (el) {
      if (!elMentionsRemovedAgent(el)) return;
      var card = el.closest ? (el.closest('.av-card') || el.closest('.cq-btn') || el) : el;
      if (card && card.parentNode) card.parentNode.removeChild(card);
    });

    /* Onboarding goal cards for removed capabilities. */
    Array.prototype.forEach.call(scope.querySelectorAll('[onclick]'), function (el) {
      var oc = el.getAttribute('onclick') || '';
      var m = oc.match(/_ob2SelectGoal\(\s*['"]([a-z_]+)['"]/i);
      if (m && REMOVED_GOALS.indexOf(lc(m[1])) !== -1 && el.parentNode) {
        el.parentNode.removeChild(el);
      }
    });
  }

  /* ── 1c. Scrub removed agents out of global client-side registries ───────
   * core.js and friends ship hardcoded slug->identity maps. Those are cleaned
   * at source; this deletes any survivor so a removed agent cannot be
   * name-resolved, coloured, mentioned or rendered even if one reappears. */
  function scrubGlobals() {
    var maps = ['AGENTS', 'AGENT_NAMES', 'AGENT_COLORS', 'ENG_AGENTS',
                'ENG_TOOL_AGENTS', 'THINK', 'ACTION_FOR_SLUG', 'ZONE_OF'];
    maps.forEach(function (name) {
      var m = window[name];
      if (!m || typeof m !== 'object') return;
      REMOVED_AGENTS.forEach(function (slug) {
        if (Object.prototype.hasOwnProperty.call(m, slug)) {
          try { delete m[slug]; } catch (e) {}
        }
      });
    });
  }

  /* ── 2. Block navigation to removed views ───────────────────────────────
   * Deep links, restored history and stale bookmarks all funnel through nav().
   * We land the user somewhere real rather than showing a dead panel or a raw
   * not_in_product payload. */
  function guardNav() {
    if (typeof window.nav !== 'function' || window.nav.__luScoped) return;
    var original = window.nav;
    var wrapped = function (view) {
      if (API.isRemovedView(view)) {
        try {
          console.info('[launch-scope] "' + view + '" is not part of this product; redirecting.');
        } catch (e) {}
        return original.call(this, SAFE_LANDING);
      }
      return original.apply(this, arguments);
    };
    wrapped.__luScoped = true;
    window.nav = wrapped;
  }

  /* ── 3. Scoped cache migration ──────────────────────────────────────────
   * A returning user must not see a removed menu restored from local state.
   * Only keys that can resurrect a removed surface are touched — unrelated
   * preferences (theme, drafts, dismissed tips) are deliberately left alone. */
  /* Cheap, idempotent, EVERY boot. A stale pointer to a removed surface can be
   * rewritten at any time by another tab still running the old bundle or by a
   * restored session, so this cannot sit behind a one-time version stamp. */
  function scrubStalePointers() {
    try {
      ['lu_last_view', 'lu_view', 'lu_current_view'].forEach(function (k) {
        if (API.isRemovedView(localStorage.getItem(k))) localStorage.removeItem(k);
      });

      /* An onboarding goal naming a removed capability is neutralised, not
       * deleted wholesale — the rest of the user's onboarding survives. */
      var goal = lc(localStorage.getItem('lu_ob_goal'));
      if (goal && /campaign|newsletter|email/.test(goal)) {
        localStorage.removeItem('lu_ob_goal');
      }

      /* Cached action payloads can only have come from the old bundle. */
      ['lu_quick_actions', 'lu_cached_actions', 'lu_command_actions'].forEach(function (k) {
        var raw = localStorage.getItem(k);
        if (!raw) return;
        if (/send_campaign|create_campaign/.test(raw)) {
          localStorage.removeItem(k);
        }
      });
    } catch (e) { /* private mode / quota — non-fatal */ }
  }

  /* Expensive one-time purge, gated by the version stamp. */
  function migrateCaches() {
    var KEY = 'lu_scope_version';
    try {
      if (localStorage.getItem(KEY) === SCOPE_VERSION) return;

      var PURGE_EXACT = [
        'lu_quick_actions', 'lu_cached_actions', 'lu_agent_roster', 'lu_roster',
        'lu_plan_payload', 'lu_plan_features', 'lu_features', 'lu_nav_state',
        'lu_advanced_state', 'lu_command_actions', 'lu_search_index'
      ];
      PURGE_EXACT.forEach(function (k) {
        try { localStorage.removeItem(k); sessionStorage.removeItem(k); } catch (e) {}
      });

      localStorage.setItem(KEY, SCOPE_VERSION);
    } catch (e) { /* private mode / quota — non-fatal */ }
  }

  /* ── boot ──────────────────────────────────────────────────────────────── */
  function boot() {
    migrateCaches();      /* one-time bulk purge */
    scrubStalePointers(); /* every boot */
    stripNav(document);
    stripAgents(document);
    scrubGlobals();
    guardNav();

    if (window.MutationObserver) {
      var mo = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          if (muts[i].addedNodes && muts[i].addedNodes.length) {
            stripNav(document); stripAgents(document); break;
          }
        }
      });
      try {
        mo.observe(document.body || document.documentElement,
                   { childList: true, subtree: true });
      } catch (e) {}
    }

    /* nav() is defined by core.js, which loads after us. Re-wrap once it exists. */
    var tries = 0;
    var iv = setInterval(function () {
      guardNav();
      scrubGlobals();   /* core.js defines the maps after us */
      if (++tries > 40 || (typeof window.nav === 'function' && window.nav.__luScoped)) {
        clearInterval(iv);
      }
    }, 100);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
