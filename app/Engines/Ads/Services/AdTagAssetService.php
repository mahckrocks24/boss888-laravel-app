<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdSettings;

/**
 * AdTagAssetService — builds the JavaScript served at /ads.js.
 *
 * Generated in PHP rather than shipped as a static file so the tag can carry
 * server-side settings (decide timeout, session cap, disclosure label) without
 * a second round trip, and so a settings change takes effect on the next tag
 * fetch rather than needing a deploy.
 *
 * CONSTRAINTS THIS TAG RESPECTS — it runs on someone else's website
 *   - Small: well under 8 KB, no dependencies, no framework.
 *   - Async and non-blocking: a slow or failed ad server leaves the page
 *     exactly as it was. The slot stays hidden; nothing shifts.
 *   - No layout shift: the slot's height is reserved in CSS before fill.
 *   - Fails silent: every path is wrapped. A tenant's console must not fill
 *     with our errors.
 *   - No cookies, no cross-site identifiers. Session frequency capping uses
 *     `sessionStorage` on the TENANT's own origin, which is first-party and
 *     dies with the tab — consistent with clause 9 of the advertising terms.
 *
 * MEASUREMENT
 *   - impression : fired when the creative is inserted into the DOM
 *   - viewable   : IAB/MRC display standard — >=50% of the slot's pixels in the
 *                  viewport for >=1 continuous second, via IntersectionObserver
 *   - click      : navigation goes through the signed redirect; the beacon is
 *                  advisory only. A client-reported click is never trusted for
 *                  billing.
 */
final class AdTagAssetService
{
    public function __construct(
        private readonly AdSettingsService $settings,
    ) {
    }

    /**
     * Full JavaScript body for /ads.js.
     *
     * The source below is heavily commented on purpose — the reasoning behind
     * the viewability window, the refresh rules and the space reservation is
     * worth keeping next to the code. Those comments are for whoever maintains
     * this class, though, not for every tenant visitor to download, so
     * `compact()` strips them from the served asset.
     */
    public function build(int $websiteId): string
    {
        $origin       = $this->origin();
        $timeoutMs    = max(50, $this->settings->int(AdSettings::DECIDE_TIMEOUT_MS));
        $sessionCap   = max(0, $this->settings->int(AdSettings::FREQ_CAP_PER_SESSION));
        $refreshMs    = max(0, $this->settings->int(AdSettings::REFRESH_INTERVAL_MS));
        $refreshMax   = max(0, $this->settings->int(AdSettings::REFRESH_MAX_PER_PAGE));

        $modalOn      = $this->settings->bool(AdSettings::MODAL_ENABLED) ? 'true' : 'false';
        $modalMinPv   = max(1, $this->settings->int(AdSettings::MODAL_MIN_PAGEVIEWS));
        $modalDwell   = max(0, $this->settings->int(AdSettings::MODAL_DWELL_MS));
        $modalNoSearch= $this->settings->bool(AdSettings::MODAL_SUPPRESS_FROM_SEARCH) ? 'true' : 'false';
        $modalCap     = max(0, $this->settings->int(AdSettings::MODAL_SESSION_CAP));
        $modalDelay   = max(0, $this->settings->int(AdSettings::MODAL_CLOSE_DELAY_MS));
        $modalSuppress= max(0, $this->settings->int(AdSettings::MODAL_SUPPRESS_FOOTER_MS));
        $modalPaths   = json_encode(
            array_values(array_filter((array) $this->settings->array(AdSettings::MODAL_EXCLUDED_PATHS), 'is_string')),
            JSON_UNESCAPED_SLASHES
        ) ?: '[]';

        $js = <<<JS
/* LevelUp Ads tag — site {$websiteId} */
(function () {
  "use strict";

  var ORIGIN = "{$origin}";
  var SITE = {$websiteId};
  var TIMEOUT = {$timeoutMs};
  var SESSION_CAP = {$sessionCap};
  var REFRESH_MS = {$refreshMs};      /* 0 disables rotation entirely */
  var REFRESH_MAX = {$refreshMax};
  var VIEWABLE_RATIO = 0.5;      /* IAB/MRC: >=50% of pixels ... */
  var VIEWABLE_MS = 1000;        /* ... for >=1 continuous second */

  var MODAL_ON = {$modalOn};
  var MODAL_MIN_PV = {$modalMinPv};
  var MODAL_DWELL = {$modalDwell};
  var MODAL_NO_SEARCH = {$modalNoSearch};
  var MODAL_CAP = {$modalCap};
  var MODAL_CLOSE_DELAY = {$modalDelay};
  var MODAL_SUPPRESS_FOOTER = {$modalSuppress};
  var MODAL_EXCLUDED = {$modalPaths};
  /* ADS-ALWAYS-1 (Owner 2026-09-28): a pop-up every 3 minutes of browsing. The first still never comes on arrival:
     it waits for the second page or for 3 minutes on the first one. */
  var MODAL_EVERY = 180000;
  var modalBusy = false;

  var refreshes = 0, refreshTimer = null, footerBlockedUntil = 0;

  function slot() { return document.getElementById("lu-ad-slot"); }

  /* sessionStorage is first-party to the TENANT origin and dies with the tab.
     Not a cross-site identifier — see clause 9 of the advertising terms. */
  function seen() {
    try { return parseInt(sessionStorage.getItem("lu_ad_n") || "0", 10) || 0; }
    catch (e) { return 0; }
  }
  function bumpSeen() {
    try { sessionStorage.setItem("lu_ad_n", String(seen() + 1)); } catch (e) {}
  }

  function beacon(event, token, extra) {
    try {
      var body = JSON.stringify({
        event: event, token: token, site: SITE,
        dwell_ms: extra && extra.dwell_ms, js: true
      });
      var url = ORIGIN + "/api/ads/event";
      /* sendBeacon survives page unload, which a fetch() may not. */
      if (navigator.sendBeacon) {
        navigator.sendBeacon(url, new Blob([body], { type: "text/plain;charset=UTF-8" }));   /* ADS-IAB-1: a simple request, no CORS preflight */
      } else {
        fetch(url, { method: "POST", headers: { "Content-Type": "text/plain;charset=UTF-8" },
                     body: body, keepalive: true, mode: "no-cors" });
      }
    } catch (e) {}
  }

  function watchViewability(el, token) {
    if (!("IntersectionObserver" in window)) return;   /* no fake viewability */
    var timer = null, fired = false;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (fired) return;
        if (entry.intersectionRatio >= VIEWABLE_RATIO) {
          if (timer) return;
          timer = setTimeout(function () {
            fired = true;
            beacon("viewable", token);
            io.disconnect();
          }, VIEWABLE_MS);
        } else if (timer) {
          /* Left the viewport before the dwell completed — the clock restarts.
             Partial time never accumulates into a viewable impression. */
          clearTimeout(timer); timer = null;
        }
      });
    }, { threshold: [0, VIEWABLE_RATIO, 1] });
    io.observe(el);
  }

  function render(fill) {
    var el = slot();
    if (!el || !fill) return;

    var body = el.querySelector(".lu-ad-body");
    if (!body) return;

    var link = document.createElement("a");
    link.href = fill.click_url;
    link.target = "_blank";
    link.rel = "nofollow sponsored noopener";

    if (fill.type === "image" && fill.asset_url) {
      var img = document.createElement("img");
      img.src = fill.asset_url;
      img.alt = fill.alt || "";
      if (fill.width) img.width = fill.width;
      if (fill.height) img.height = fill.height;
      img.loading = "eager";
      /* A broken asset URL would otherwise leave an empty bar covering the
         footer with reserved space for nothing. Fail all the way closed. */
      img.addEventListener("error", function () {
        var s = slot();
        if (s) s.hidden = true;
        releaseSpace();
      });
      link.appendChild(img);
    } else {
      /* html creatives are sanitised server-side at review; assigning to
         textContent here would strip intended markup, so we accept the
         reviewed HTML but never inject anything the server did not send. */
      link.innerHTML = fill.html || fill.alt || "";
    }

    var shownAt = Date.now();
    link.addEventListener("click", function () {
      beacon("click", fill.token, { dwell_ms: Date.now() - shownAt });
    });

    /* ADS-CLICK-1 (Owner 2026-09-28: "entire banner and modal clickable"): a tap anywhere on the bar opens the ad */
    el.style.cursor = "pointer";
    el.onclick = function (e) { if (e.target && e.target.closest && (e.target.closest("a") || e.target.closest(".lu-ad-x") || e.target.closest(".lu-ad-tab"))) return; if (el.classList.contains("lu-ad-closed")) return; link.click(); };
    drawer(el);
    body.replaceChildren(link);  /* replaceChildren, not append: a refresh must
                                    SWAP the creative, never stack a second one */
    el.hidden = false;           /* height was reserved in CSS — no shift */

    /* Reserve document bottom padding ONLY once something actually rendered.
       A position:fixed bar otherwise permanently covers the end of the tenant's
       footer for any visitor scrolled to the bottom. Measured before the fix:
       50px occluded at 390px, 99px at 1280px. */
    document.documentElement.classList.add("lu-ad-filled");
    reserveSpace();

    bumpSeen();
    beacon("impression", fill.token);
    watchViewability(el, fill.token);
    scheduleRefresh();
  }

  /* ── Rotation ─────────────────────────────────────────────────────────────
     Each refresh is a fresh decision and therefore a fresh BILLABLE impression,
     so the rules are deliberately conservative:
       - never faster than REFRESH_MS (the IAB / AdSense floor is 30s);
       - only while the slot is genuinely viewable — refreshing an off-screen ad
         bills an advertiser for something nobody saw;
       - only while the tab is visible;
       - capped per pageview, so a tab left open overnight cannot mint thousands
         of impressions.
     Relax any of those and the inventory stops being defensible. */
  function scheduleRefresh() {
    if (REFRESH_MS <= 0 || refreshes >= REFRESH_MAX) return;
    if (refreshTimer) clearTimeout(refreshTimer);
    refreshTimer = setTimeout(function () {
      if (document.hidden || !slotIsViewable()) { scheduleRefresh(); return; }
      if (SESSION_CAP > 0 && seen() >= SESSION_CAP) return;
      refreshes++;
      decide();
    }, REFRESH_MS);
  }

  /* The CSS rule is only a floor: it cannot know the bar's RENDERED height,
     which depends on the creative. At 1280px a 90px creative plus 8px padding
     plus a 1px border makes the bar 99px, so a fixed 90px of padding still left
     9px of the footer covered. Measuring the live box is the only exact answer,
     and it stays correct for any future creative size or slot format. */
  function reserveSpace() {
    var el = slot();
    if (!el || el.hidden) return;
    var h = el.classList.contains("lu-ad-closed") ? 30 : el.offsetHeight;   /* ADS-DRAWER-1: closed = just the tab */
    if (h > 0) document.body.style.paddingBottom = h + "px";
  }

  /* ADS-DRAWER-1 (Owner 2026-09-28): the bottom ad is a drawer. The x slides it down behind a logo tab; the tab pulls it
     out again; closed, it reopens by itself a minute later (the minute carries across pages of the visit). */
  var DRAWER_EVERY = 60000, drawerTimer = null, drawerBound = false;
  function drawerClosedAt() { try { return parseInt(sessionStorage.getItem("lu_ad_drawer_at") || "0", 10) || 0; } catch (e) { return 0; } }
  function setDrawer(el, open) {
    el.classList.toggle("lu-ad-closed", !open);
    var tab = el.querySelector(".lu-ad-tab"); if (tab) { tab.setAttribute("aria-expanded", open ? "true" : "false"); tab.setAttribute("aria-label", open ? "Hide the ad" : "Show the ad"); }
    try { if (open) sessionStorage.removeItem("lu_ad_drawer_at"); else sessionStorage.setItem("lu_ad_drawer_at", String(Date.now())); } catch (e) {}
    if (drawerTimer) { clearTimeout(drawerTimer); drawerTimer = null; }
    if (!open) drawerTimer = setTimeout(function () { setDrawer(el, true); }, DRAWER_EVERY);
    reserveSpace();
    try { window.dispatchEvent(new Event("lu-ad-drawer")); } catch (e) {}
  }
  function drawer(el) {
    if (drawerBound) return;
    drawerBound = true;
    var x = el.querySelector(".lu-ad-x"), tab = el.querySelector(".lu-ad-tab");
    if (x) x.addEventListener("click", function (e) { e.stopPropagation(); setDrawer(el, false); });
    if (tab) tab.addEventListener("click", function (e) { e.stopPropagation(); setDrawer(el, el.classList.contains("lu-ad-closed")); });
    var at = drawerClosedAt();
    if (at && Date.now() - at < DRAWER_EVERY) {   /* closed on the last page less than a minute ago: stay closed for the rest of it */
      el.classList.add("lu-ad-closed");
      drawerTimer = setTimeout(function () { setDrawer(el, true); }, DRAWER_EVERY - (Date.now() - at));
      reserveSpace();
    }
  }

  function releaseSpace() {
    document.documentElement.classList.remove("lu-ad-filled");
    document.body.style.paddingBottom = "";
  }

  /* A viewport resize flips the media query and re-lays the creative out, so the
     reservation has to be recomputed. Debounced — resize fires continuously. */
  var resizeTimer = null;
  window.addEventListener("resize", function () {
    if (resizeTimer) clearTimeout(resizeTimer);
    resizeTimer = setTimeout(reserveSpace, 150);
  });

  function slotIsViewable() {
    var el = slot();
    if (!el || el.hidden) return false;
    var r = el.getBoundingClientRect();
    var vh = window.innerHeight || document.documentElement.clientHeight;
    return (Math.min(r.bottom, vh) - Math.max(r.top, 0)) >= r.height * 0.5;
  }

  var decideTries = 0;
  function decide() {
    var el = slot();
    if (!el) return;
    if (SESSION_CAP > 0 && seen() >= SESSION_CAP) return;

    var ctrl = ("AbortController" in window) ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, TIMEOUT);

    fetch(ORIGIN + "/api/ads/decide", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      mode: "cors",
      signal: ctrl ? ctrl.signal : undefined,
      body: JSON.stringify({
        website_id: SITE,
        slots: [el.getAttribute("data-lu-slot") || "footer_sticky"],
        ctx: {
          device: window.matchMedia && window.matchMedia("(min-width:768px)").matches ? "desktop" : "mobile",
          language: (navigator.language || "en").slice(0, 2),
          page_type: el.getAttribute("data-lu-page") || "home"
        }
      })
    })
      .then(function (r) {
        clearTimeout(timer);
        /* Distinguish "the server said no" from "we could not ask". */
        if (r.status === 204) return { fills: [] };
        return r.ok ? r.json() : null;
      })
      .then(function (data) {
        if (!data) return;                       /* could not ask — leave as-is */

        if (!data.fills || !data.fills.length) {
          /* An EXPLICIT no-fill. If a creative is currently on screen it must
             come down: this is the path a visitor takes when the site owner
             upgrades mid-session. Leaving the previous creative up would keep
             showing an advertisement on a site that is now paying not to have
             one — the single promise this product makes. */
          var cur = slot();
          if (cur && !cur.hidden) {
            cur.hidden = true;
            var body = cur.querySelector(".lu-ad-body");
            if (body) body.replaceChildren();
            releaseSpace();
          }
          if (refreshTimer) { clearTimeout(refreshTimer); refreshTimer = null; }
          return;
        }

        render(data.fills[0]);
      })
      /* A network error or timeout is NOT a no-fill. Blanking a legitimately
         sold ad because one request failed would cost an advertiser delivery
         they paid for, so the current creative stays and the next refresh
         retries. */
      .catch(function () {
        clearTimeout(timer);
        /* ADS-IAB-1: a slow answer (phone network, busy server) aborted the only try and the bar never came. Retry, spaced. */
        var cur = slot();
        if (decideTries < 3 && cur && cur.hidden) { decideTries++; setTimeout(decide, 3000 * decideTries); }
      });
  }

  /* @@MODAL_BLOCK_START@@
     ══ SESSION INTERSTITIAL ═════════════════════════════════════════════
     Trigger rules exist for SEO reasons as much as UX ones. Google penalises
     interstitials shown when a visitor arrives from search; never firing on the
     first pageview and suppressing on a search referrer is what keeps this
     format outside that pattern. Do not relax either without an SEO decision. */

  var SEARCH_HOSTS = /(^|\\.)(google|bing|yahoo|duckduckgo|yandex|baidu|ecosia|brave)\\./i;

  function pageviews() {
    try {
      var n = (parseInt(sessionStorage.getItem("lu_ad_pv") || "0", 10) || 0) + 1;
      sessionStorage.setItem("lu_ad_pv", String(n));
      return n;
    } catch (e) { return 1; }
  }

  function modalsSeen() {
    try { return parseInt(sessionStorage.getItem("lu_ad_modal") || "0", 10) || 0; }
    catch (e) { return 0; }
  }
  function bumpModalSeen() {
    try { sessionStorage.setItem("lu_ad_modal", String(modalsSeen() + 1)); } catch (e) {}
    try { localStorage.setItem("lu_ad_modal_at", String(Date.now())); } catch (e) {}
  }
  function lastModalAt() {
    try { return parseInt(localStorage.getItem("lu_ad_modal_at") || "0", 10) || 0; } catch (e) { return 0; }
  }
  function visitStart() {
    try { var t = parseInt(sessionStorage.getItem("lu_ad_t0") || "0", 10); if (!t) { t = Date.now(); sessionStorage.setItem("lu_ad_t0", String(t)); } return t; }
    catch (e) { return Date.now(); }
  }

  function cameFromSearch() {
    try {
      if (!document.referrer) return false;
      var h = new URL(document.referrer).hostname;
      if (h === location.hostname) return false;   /* internal navigation */
      return SEARCH_HOSTS.test(h);
    } catch (e) { return false; }
  }

  function onExcludedPath() {
    var p = (location.pathname || "/").toLowerCase();
    for (var i = 0; i < MODAL_EXCLUDED.length; i++) {
      if (p.indexOf(String(MODAL_EXCLUDED[i]).toLowerCase()) === 0) return true;
    }
    return false;
  }

  function anotherDialogOpen() {
    /* 17 of the 31 templates ship their own modal/lightbox markup. Opening ours
       on top of a tenant's dialog is a z-index fight and an accessibility
       failure, so we defer instead. */
    return !!document.querySelector("dialog[open], [aria-modal='true']:not(#lu-ad-modal)");
  }

  function modalEligible(pv) {
    if (!MODAL_ON) return false;
    if (MODAL_CAP > 0 && modalsSeen() >= MODAL_CAP) return false;
    if (Date.now() - lastModalAt() < MODAL_EVERY) return false;     /* ADS-ALWAYS-1: 3 minutes apart */
    if (pv < MODAL_MIN_PV && Date.now() - visitStart() < 20000) return false;   /* ADS-IAB-1: not on arrival — 20 s in, or the next page */
    if (MODAL_NO_SEARCH && cameFromSearch()) return false;  /* the SEO guard */
    if (onExcludedPath()) return false;               /* never break a conversion */
    return true;
  }

  function buildModal(fill) {
    var wrap = document.createElement("div");
    wrap.id = "lu-ad-modal";
    wrap.setAttribute("role", "dialog");
    wrap.setAttribute("aria-modal", "true");
    wrap.setAttribute("aria-label", fill.label || "Sponsored message");
    wrap.setAttribute("tabindex", "-1");
    wrap.className = "lu-ad-modal-backdrop";

    var box = document.createElement("div");
    box.className = "lu-ad-modal";

    var label = document.createElement("span");
    label.className = "lu-ad-modal-label";
    label.textContent = fill.label || "Sponsored";

    var close = buildCloseControl();

    var media = document.createElement("div");
    media.className = "lu-ad-modal-media";
    media.setAttribute("data-ar", fill.aspect_ratio || "1:1");

    var link = document.createElement("a");
    link.href = fill.click_url;
    link.target = "_blank";
    link.rel = "nofollow sponsored noopener";

    var shownAt = Date.now();

    if (fill.media_type === "video" && fill.video_url) {
      var v = document.createElement("video");
      v.src = fill.video_url;
      if (fill.poster_url) v.poster = fill.poster_url;
      v.muted = true; v.defaultMuted = true;   /* muted is the only autoplay browsers allow */
      v.autoplay = true; v.playsInline = true;
      v.setAttribute("playsinline", "");
      v.controls = false;
      v.preload = "auto";
      watchQuartiles(v, fill.token);
      /* If playback fails the poster becomes the creative — never a blank modal. */
      v.addEventListener("error", function () {
        if (!fill.poster_url) { closeModal("error"); return; }
        var img = document.createElement("img");
        img.src = fill.poster_url; img.alt = fill.alt || "";
        v.replaceWith(img);
      });
      link.appendChild(v);
    } else {
      var img2 = document.createElement("img");
      img2.src = fill.asset_url || fill.poster_url || "";
      img2.alt = fill.alt || "";
      img2.addEventListener("error", function () { closeModal("error"); });
      link.appendChild(img2);
    }

    link.addEventListener("click", function () {
      beacon("click", fill.token, { dwell_ms: Date.now() - shownAt });
    });

    media.appendChild(link);
    box.appendChild(label);
    box.appendChild(close);
    box.appendChild(media);
    box.style.cursor = "pointer";   /* ADS-CLICK-1: anywhere on the pop-up but the close button */
    box.addEventListener("click", function (e) { if (e.target && e.target.closest && (e.target.closest("a") || e.target.closest(".lu-ad-modal-close"))) return; link.click(); });
    wrap.appendChild(box);

    close.addEventListener("click", function () { if (canClose()) closeModal("button"); });
    wrap.addEventListener("click", function (e) {
      if (e.target === wrap && canClose()) closeModal("backdrop");
    });

    modalState = {
      el: wrap, token: fill.token, shownAt: shownAt,
      lastFocus: document.activeElement, closeBtn: close,
      closeUnlocked: MODAL_CLOSE_DELAY <= 0
    };

    document.body.appendChild(wrap);
    document.body.style.overflow = "hidden";       /* lock scroll while open */
    /* Next frame, so the entrance transition has an initial state to animate
       from rather than snapping straight to its final position. */
    requestAnimationFrame(function () { wrap.classList.add("on"); });
    /* Focus the DIALOG, not the close control. Focus has to move into the modal
       for screen-reader and keyboard users, but focusing the button makes
       Chromium paint its focus ring immediately — drawing a box around the
       countdown for every visitor, including mouse users who never asked for
       one. The dialog takes focus silently; Tab still reaches the button. */
    wrap.focus();

    document.addEventListener("keydown", onModalKey, true);

    bumpModalSeen();
    beacon("impression", fill.token);
    beacon("viewable", fill.token);   /* a blocking overlay is viewable by construction */

    footerBlockedUntil = Date.now() + MODAL_SUPPRESS_FOOTER;
  }

  var modalState = null;

  /* Close control: the remaining seconds, then an X. Nothing else.
     Built with namespaced DOM calls rather than innerHTML so nothing here can
     be influenced by creative content. */
  function buildCloseControl() {
    var SVGNS = "http://www.w3.org/2000/svg";
    var secs = Math.ceil(MODAL_CLOSE_DELAY / 1000);

    var btn = document.createElement("button");
    btn.className = "lu-ad-modal-close";
    btn.type = "button";

    var count = document.createElement("span");
    count.className = "lu-count";
    count.setAttribute("aria-hidden", "true");
    count.textContent = String(secs);

    var svg = document.createElementNS(SVGNS, "svg");
    svg.setAttribute("class", "lu-x");
    svg.setAttribute("viewBox", "0 0 19 19");
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");

    var mark = document.createElementNS(SVGNS, "path");
    mark.setAttribute("d", "M4 4 L15 15 M15 4 L4 15");
    svg.appendChild(mark);

    btn.appendChild(count);
    btn.appendChild(svg);

    if (MODAL_CLOSE_DELAY <= 0) {
      unlockClose(btn, count);
      return btn;
    }

    /* Screen readers get the same information the number conveys visually. */
    btn.setAttribute("aria-label", "Advertisement — closes in " + secs + " seconds");
    btn.setAttribute("aria-disabled", "true");

    var startedAt = Date.now();
    var timer = null;

    function tick() {
      var left = Math.max(0, Math.ceil((MODAL_CLOSE_DELAY - (Date.now() - startedAt)) / 1000));

      if (left <= 0) { unlockClose(btn, count); return; }

      if (count.textContent !== String(left)) {
        count.textContent = String(left);
        btn.setAttribute("aria-label", "Advertisement — closes in " + left + " seconds");
      }
      timer = setTimeout(tick, 200);
    }

    timer = setTimeout(tick, 200);
    btn.__luStop = function () { if (timer) clearTimeout(timer); };

    return btn;
  }

  function unlockClose(btn, count) {
    btn.setAttribute("data-ready", "1");
    btn.removeAttribute("aria-disabled");
    btn.setAttribute("aria-label", "Close advertisement");
    if (count) count.textContent = "";
    if (modalState) modalState.closeUnlocked = true;
  }

  /* One gate for EVERY dismissal path. Gating only the button would leave ESC
     and the backdrop as ways around the countdown, which is both a broken
     contract with the advertiser and an inconsistent experience. */
  function canClose() {
    return !!(modalState && modalState.closeUnlocked);
  }

  function onModalKey(e) {
    if (!modalState) return;
    if (e.key === "Escape") { if (canClose()) closeModal("esc"); return; }
    if (e.key === "Tab") {
      /* Trap focus: the only interactive elements are the close button and the
         creative link, so cycling between them is the whole trap. */
      var f = modalState.el.querySelectorAll("button, a[href]");
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
      else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
    }
  }

  function closeModal(how) {
    if (!modalState) return;
    if (modalState.closeBtn && modalState.closeBtn.__luStop) modalState.closeBtn.__luStop();
    beacon("dismiss", modalState.token, { dwell_ms: Date.now() - modalState.shownAt, how: how });
    document.removeEventListener("keydown", onModalKey, true);
    if (modalState.el && modalState.el.parentNode) modalState.el.parentNode.removeChild(modalState.el);
    document.body.style.overflow = "";
    if (modalState.lastFocus && modalState.lastFocus.focus) modalState.lastFocus.focus();
    modalState = null;
  }

  /* IAB quartile measurement. `timeupdate` is the only event that works across
     browsers for this; `ended` alone would miss a scrubbed or stalled play. */
  function watchQuartiles(video, token) {
    var fired = {};
    function mark(name) {
      if (fired[name]) return;
      fired[name] = true;
      beacon(name, token);
    }
    video.addEventListener("play", function () { mark("video_start"); });
    video.addEventListener("timeupdate", function () {
      if (!video.duration || !isFinite(video.duration)) return;
      var p = video.currentTime / video.duration;
      if (p >= 0.25) mark("video_q1");
      if (p >= 0.50) mark("video_q2");
      if (p >= 0.75) mark("video_q3");
    });
    video.addEventListener("ended", function () { mark("video_complete"); });
  }

  function decideModal() {
    fetch(ORIGIN + "/api/ads/decide", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      mode: "cors",
      body: JSON.stringify({
        website_id: SITE,
        slots: ["interstitial_modal"],
        ctx: {
          device: window.matchMedia && window.matchMedia("(min-width:768px)").matches ? "desktop" : "mobile",
          language: (navigator.language || "en").slice(0, 2),
          page_type: (slot() && slot().getAttribute("data-lu-page")) || "home",
          viewport_w: window.innerWidth,
          viewport_h: window.innerHeight
        }
      })
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.fills || !data.fills.length) return;
        if (anotherDialogOpen()) return;   /* re-checked at the last moment */
        buildModal(data.fills[0]);
      })
      .catch(function () {});
  }

  function armModal(pv) {
    visitStart();
    var tryShow = function () {
      if (modalBusy || modalState || document.hidden || anotherDialogOpen()) return;
      if (!modalEligible(pv)) return;
      modalBusy = true;
      decideModal();
      setTimeout(function () { modalBusy = false; }, 10000);
    };
    setTimeout(tryShow, MODAL_DWELL);
    /* ADS-ALWAYS-1: keep looking while the page is open, so the next one comes 3 minutes after the last */
    setInterval(tryShow, 15000);
  }

  /* @@MODAL_BLOCK_END@@ */

  /* ADS-ALWAYS-1 (Owner 2026-09-28): a second ad inside the page, right below the hero. Same house rules as the bar:
     the server decides (no ad on a paying site), a tap anywhere on it opens the ad. */
  function heroEl() {
    return document.querySelector('[data-block="hero"]') || document.querySelector('section[class^="hero"], section[class*=" hero"], section#hero') || document.querySelector("main section, body > section");
  }
  function decideInline() {
    var hero = heroEl();
    if (!hero || document.getElementById("lu-ad-inline")) return;
    fetch(ORIGIN + "/api/ads/decide", {
      method: "POST", headers: { "Content-Type": "application/json" }, mode: "cors",
      body: JSON.stringify({ website_id: SITE, slots: ["in_content_mrec"], ctx: {
        device: window.matchMedia && window.matchMedia("(min-width:768px)").matches ? "desktop" : "mobile",
        language: (navigator.language || "en").slice(0, 2), page_type: (slot() && slot().getAttribute("data-lu-page")) || "home" } })
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.fills || !data.fills.length || document.getElementById("lu-ad-inline")) return;
        var fill = data.fills[0];
        var box = document.createElement("aside");
        box.id = "lu-ad-inline"; box.className = "lu-ad-inline"; box.setAttribute("aria-label", "Advertisement");
        var label = document.createElement("span"); label.className = "lu-ad-label"; label.textContent = fill.label || "Sponsored";
        var link = document.createElement("a");
        link.href = fill.click_url; link.target = "_blank"; link.rel = "nofollow sponsored noopener";
        if (fill.type === "image" && fill.asset_url) { var im = document.createElement("img"); im.src = fill.asset_url; im.alt = fill.alt || ""; link.appendChild(im); }
        else link.innerHTML = fill.html || fill.alt || "";
        var shownAt = Date.now();
        link.addEventListener("click", function () { beacon("click", fill.token, { dwell_ms: Date.now() - shownAt }); });
        box.addEventListener("click", function (e) { if (e.target && e.target.closest && e.target.closest("a")) return; link.click(); });
        box.appendChild(label); box.appendChild(link);
        hero.insertAdjacentElement("afterend", box);
        beacon("impression", fill.token);
        watchViewability(box, fill.token);
      })
      .catch(function () {});
  }

  function start() {
    decideInline();
    var pv = (typeof pageviews === "function") ? pageviews() : 1;
    if (typeof armModal === "function") armModal(pv);
    /* The footer bar is suppressed briefly after a modal so a visitor never gets
       a full-screen ad and a bar at once. */
    if (Date.now() >= footerBlockedUntil) decide();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
JS;

        // The interstitial roughly doubles the tag. A site with the modal
        // switched off should not download six kilobytes of modal machinery on
        // every pageview, so the block is removed at generation time rather
        // than merely skipped at runtime.
        if (! $this->settings->bool(AdSettings::MODAL_ENABLED)) {
            // The pattern MUST consume the opening `/*` of the start marker's
            // comment. Matching only from `@@MODAL_BLOCK_START@@` leaves an
            // orphaned `/*` behind, which swallows the rest of the file as an
            // unterminated comment and ships syntactically invalid JavaScript to
            // every tenant — in the DEFAULT configuration. Guarded by
            // test_generated_javascript_is_syntactically_balanced.
            $js = preg_replace(
                '#/\*\s*@@MODAL_BLOCK_START@@.*?@@MODAL_BLOCK_END@@\s*\*/#s',
                '',
                $js
            ) ?? $js;
        } else {
            $js = str_replace(['@@MODAL_BLOCK_START@@', '@@MODAL_BLOCK_END@@'], '', $js);
        }

        $js = $this->compact($js);

        // Fail loud rather than serve broken JavaScript: an unbalanced comment
        // is the exact failure this transform can cause, and a tenant page is
        // the worst place to discover it.
        if (substr_count($js, '/*') !== substr_count($js, '*/')) {
            return $this->disabled('tag build error');
        }

        return $js;
    }

    /**
     * Strip comments and indentation from the SERVED asset.
     *
     * Deliberately conservative: it only removes lines whose trimmed content
     * *starts* with a comment token, and tracks open block comments. A line such
     * as `var u = "https://x";` is never touched, because the `//` is not at the
     * start of the line. Trailing same-line comments are left alone for the same
     * reason — safety over a few extra bytes. This is not a minifier and does not
     * try to be one; it does not rename, reorder or rewrite a single statement.
     */
    private function compact(string $js): string
    {
        $out      = [];
        $inBlock  = false;

        foreach (explode("\n", $js) as $line) {
            $trimmed = trim($line);

            if ($inBlock) {
                if (str_contains($trimmed, '*/')) {
                    $inBlock = false;
                }
                continue;
            }

            if ($trimmed === '') {
                continue;
            }

            // Whole-line block comment, possibly multi-line.
            if (str_starts_with($trimmed, '/*')) {
                if (! str_contains($trimmed, '*/')) {
                    $inBlock = true;
                }
                continue;
            }

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue;
            }

            $out[] = $trimmed;
        }

        return "/* LevelUp Ads */\n" . implode("\n", $out) . "\n";
    }

    /** Valid, harmless JS returned on every rejection path — mirrors /chatbot.js. */
    public function disabled(string $reason): string
    {
        $safe = preg_replace('/[^a-z0-9_\- ]/i', '', $reason) ?? 'disabled';

        return "/* LevelUp Ads — disabled: {$safe} */";
    }

    private function origin(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';

        return str_contains($host, 'levelupgrowth.io')
            ? 'https://' . $host
            : 'https://staging.levelupgrowth.io';
    }
}
