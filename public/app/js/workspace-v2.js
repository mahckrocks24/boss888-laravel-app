/* ──────────────────────────────────────────────────────────────────────────
 * workspace-v2.js — rebuilt workspace tab
 * Version 1.0.5  (2026-05-31) — never blank canvas after agents once loaded; recover via refresh()
 *
 * Layout model:
 *  - Viewport: overflow:auto, native touch/mouse scrolling
 *  - Canvas:   3000x2000 absolute area with dotted background
 *  - Agents:   position:absolute, freely drag anywhere
 *  - Initial:  radial layout (Sarah center, others in rings)
 *  - Lasso:    desktop = mousedown on empty canvas; mobile = double-tap-and-hold
 *  - Card drag: desktop = mousedown; mobile = double-tap-and-hold on the card
 *  - Zoom:     DISABLED — no pinch zoom on canvas, no ctrl+wheel browser zoom
 *
 * Data: single fetch from GET /api/workspace/state (returns enabled-only agents).
 * Render: idempotent, 5s polling, stale-drop on concurrent fetches.
 * ────────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  // ── Module state ─────────────────────────────────────────────────────────
  var STATE = {
    agents: [],
    tasks: [],
    positions: {},          // server-side saved positions {slug:{x,y}}
    overrides: {},          // local drag overrides {slug:{x,y}}
    selected: new Set(),
    pollTimer: null,
    inFlight: null,
    fetchSeq: 0,
    lastConnSig: '',
    activePairsFlash: {},
    drag: { active: false, slug: null, startX: 0, startY: 0, origX: 0, origY: 0 },
    taskDrag: { active: false, taskId: null, startX: 0, startY: 0, origX: 0, origY: 0, moved: false },
    taskNodePos: {},  // user-dragged task-node positions {taskId:{x,y}}
    dismissed: null,  // Set<taskId> hidden from canvas (persisted to localStorage); lazy-init
    lasso: { active: false, startX: 0, startY: 0, shift: false },
    canvasScale: 1,  // RETAINED at 1 — moveAgentDrag still references it. Pinch zoom removed.
    tap: { lastTime: 0, lastX: 0, lastY: 0, lastSlug: null },  // mobile double-tap detection
    suppressClick: false,  // mobile: set on every touch tap of a card so the
                           // emulated click never opens the drawer
    hadAgentsOnce: false,  // once true, an empty render must NOT blank the canvas —
                           // a transient empty response triggers a refresh instead
    initialLayoutDone: false,
    world: { minX: 0, minY: 0, w: 3000, h: 2000 },   // WS-CANVAS-1: the visible world (canvas) around the cards
    padExtra: { l: 0, t: 0, r: 0, b: 0 },   // (WS-CANVAS-8, unused since WS-CANVAS-10)
    view: { tx: 0, ty: 0 },   // WS-CANVAS-10: the board's pan, in screen pixels - the world is translate(tx,ty) scale(s)
  };
  var WORLD_MARGIN = 480;   // WS-CANVAS-1: safe distance kept beyond the farthest card in every direction

  // Mobile double-tap thresholds: second tap must arrive within DBL_TAP_MS
  // and within DBL_TAP_PX of the first tap to count.
  var DBL_TAP_MS = 350;
  var DBL_TAP_PX = 28;

  // Canvas dimensions (matches v1: 3000x2000 minimum)
  var CANVAS_W = 3000;
  var CANVAS_H = 2000;
  var CARD_W = 160;          // matches v1 .agent-node width
  var CARD_H = 170;          // matches v1 vertical footprint (head+3 rows+bar+btn)

  // Zone-only inference (orbs themselves rendered via global window.buildAgentOrb).
  // The global Orb Avatar System (orb.css + orb.js) is the canonical source of
  // truth for orb visuals — DO NOT redefine emoji/color here. See
  // project_orb_system_locked memory.
  var ZONE_OF = {
    sarah: 'leadership', dmm: 'leadership',
    james: 'strategy', diana: 'strategy', sofia: 'strategy',
    priya: 'strategy', nora: 'strategy', max: 'strategy',
    elena: 'execution',
    alex: 'execution', ryan: 'execution',
  };

  var ZONE_STYLE = {
    leadership: { bg: 'rgba(108,92,231,.05)', border: 'rgba(108,92,231,.35)', label: '#9D8AF7' },
    strategy:   { bg: 'rgba(0,229,168,.04)',  border: 'rgba(0,229,168,.25)',  label: '#3DD9B0' },
    execution:  { bg: 'rgba(255,255,255,.02)', border: 'rgba(255,255,255,.10)', label: '#888' },
  };

  // ── Public init ──────────────────────────────────────────────────────────
  window.initWorkspaceV2 = function () {
    var root = document.getElementById('view-workspace');
    if (!root) return;
    if (STATE.dismissed === null) STATE.dismissed = loadDismissed();
    if (root.dataset.wired === '1') { refresh(); ensurePollLoop(); return; }
    root.dataset.wired = '1';
    buildShell(root);
    wireEvents(root);
    /* WS-FAST-1 (Owner 2026-09-28: "it takes 3 sec before the cards appear"): the team is drawn at once from the last state
       this device saw (same workspace, under a day old); the live fetch that follows replaces it. */
    try {
      var cached = JSON.parse(localStorage.getItem('wsv2_state_' + getWorkspaceId()) || 'null');
      if (cached && Array.isArray(cached.agents) && cached.agents.length && Date.now() - (cached.at || 0) < 86400000 && !STATE.agents.length) {
        STATE.agents = cached.agents; STATE.tasks = cached.tasks || []; STATE.positions = cached.positions || {};
        syncGlobalAgentsMap(); computeInitialLayout(); STATE.initialLayoutDone = true; render();
        try { campCard(false); } catch (_c) {}
      }
    } catch (_e) {}
    refresh();
    ensurePollLoop();
  };

  // ── Canvas-dismissal persistence ─────────────────────────────────────────
  // Hides completed/failed task-nodes from the canvas. The task remains in
  // the DB, drawer history, pipeline, and agent counters — only the floating
  // canvas card is hidden. Persisted in localStorage per workspace.
  function dismissKey() { return 'wsv2_dismissed_' + getWorkspaceId(); }
  function loadDismissed() {
    try {
      var raw = localStorage.getItem(dismissKey());
      if (!raw) return new Set();
      var arr = JSON.parse(raw);
      return new Set(Array.isArray(arr) ? arr.map(Number) : []);
    } catch (e) { return new Set(); }
  }
  function saveDismissed() {
    try {
      localStorage.setItem(dismissKey(), JSON.stringify(Array.from(STATE.dismissed)));
    } catch (e) {}
  }
  function dismissTaskNode(taskId) {
    if (!STATE.dismissed) STATE.dismissed = loadDismissed();
    STATE.dismissed.add(Number(taskId));
    saveDismissed();
    var el = document.querySelector('.wsv2-task-node[data-task-id="' + taskId + '"]');
    if (el) el.remove();
    // Full re-render so the agent-pair Bezier disappears when this was
    // the last task between the pair. (renderLines alone wouldn't redraw
    // the SVG without picking up the updated pair map.)
    requestAnimationFrame(function () {
      renderLines();
      updateDismissedIndicator();
    });
  }
  window.wsv2_clearDismissed = function () {
    STATE.dismissed = new Set();
    saveDismissed();
    render();
    toast('Canvas reset — all dismissed cards restored', 'info');
  };
  // 24h auto-dismiss horizon
  var AUTO_DISMISS_MS = 24 * 60 * 60 * 1000;

  // ── Build shell DOM ──────────────────────────────────────────────────────
  function buildShell(root) {
    root.innerHTML = ''
      + '<style>' + STYLES + '</style>'
      + '<div class="wsv2-viewport" id="wsv2-viewport">'
      + '  <div class="wsv2-canvas" id="wsv2-canvas" style="width:100%;height:100%">'   /* WS-CANVAS-10: fills the viewport; the world moves inside it */
      + '  <div class="wsv2-world" id="wsv2-world" style="width:' + CANVAS_W + 'px;height:' + CANVAS_H + 'px">'   /* WS-CANVAS-1: the world moves and scales as one */
      + '    <svg class="wsv2-svg" id="wsv2-svg" width="' + CANVAS_W + '" height="' + CANVAS_H + '" viewBox="0 0 ' + CANVAS_W + ' ' + CANVAS_H + '"></svg>'
      + '    <div class="wsv2-zones" id="wsv2-zones"></div>'
      + '    <div class="wsv2-agents" id="wsv2-agents"></div>'
      + '    <div class="wsv2-lasso" id="wsv2-lasso" style="display:none"></div>'
      + '  </div>'
      + '  </div>'
      + '</div>'
      + '<div class="wsv2-toolbar">'
      + '  <button class="wsv2-btn" onclick="wsv2_resetLayout()" title="Restore radial layout">&#x21bb; Reset Layout</button>'
      + '  <button class="wsv2-btn" onclick="wsv2_fitToScreen()" title="Center view">&#x2316; Center</button>'
      + '  <button class="wsv2-btn" onclick="nav(\'meeting\')">+ New Meeting</button>'
      + '  <button class="wsv2-btn wsv2-btn-primary" onclick="nav(\'meeting\')">Strategy Room</button>'
      + '  <button class="wsv2-btn" onclick="wsv2_toggleActivity()">Activity</button>'
      + '</div>'
      + '<div class="wsv2-zoomctl" id="wsv2-zoomctl" aria-label="Zoom"><button type="button" class="wsv2-btn" onclick="wsv2_zoom(1)" title="Zoom in (Ctrl + wheel, or pinch)" aria-label="Zoom in">+</button><button type="button" class="wsv2-btn" id="wsv2-zoom-pct" onclick="wsv2_zoomReset()" title="Back to 100%">100%</button><button type="button" class="wsv2-btn" onclick="wsv2_zoom(-1)" title="Zoom out (Ctrl + wheel, or pinch)" aria-label="Zoom out">&minus;</button></div>'   /* WS-CANVAS-2: its own cluster, visible on every screen */
      + '<div class="wsv2-selection" id="wsv2-selection" style="display:none">'
      + '  <span class="wsv2-sel-info" id="wsv2-sel-count">0 agents selected</span>'
      + '  <button class="wsv2-btn wsv2-btn-primary" data-adv="1" onclick="wsv2_assignTask()">+ Assign Task</button>'
      + '  <button class="wsv2-btn" onclick="wsv2_startMeeting()">Start Meeting</button>'
      + '  <button class="wsv2-btn" onclick="wsv2_analyzeWorkload()">Workload</button>'
      + '  <button class="wsv2-btn" onclick="wsv2_clearSelection()">&#x2715; Clear</button>'
      + '</div>'
      + '<div class="wsv2-legend">'
      + '  <div class="wsv2-legend-title">Status</div>'
      + '  <div class="wsv2-legend-row"><span class="wsv2-sw" style="background:#3DD9B0"></span> Ongoing tasks</div>'
      + '  <div class="wsv2-legend-row"><span class="wsv2-sw" style="background:#F59E0B"></span> Upcoming tasks</div>'
      + '  <div class="wsv2-legend-row"><span class="wsv2-sw" style="background:#6C5CE7"></span> Completed tasks</div>'
      + '  <div class="wsv2-legend-title" style="margin-top:10px;border-top:1px solid var(--bd);padding-top:8px">Category</div>'
      + '  <div class="wsv2-legend-row"><span class="wsv2-sw" style="background:#3B82F6"></span> Research</div>'
      + '  <div class="wsv2-legend-row"><span class="wsv2-sw" style="background:#7C3AED"></span> Create</div>'
      + '  <div class="wsv2-legend-row"><span class="wsv2-sw" style="background:#00E5A8"></span> Optimize</div>'
      + '  <div class="wsv2-legend-row"><span class="wsv2-sw" style="background:#F59E0B"></span> Publish</div>'
      + '  <div class="wsv2-legend-row"><span class="wsv2-sw" style="background:#EC4899"></span> CRM</div>'
      + '  <div class="wsv2-legend-row"><span class="wsv2-sw" style="background:#6B7280"></span> Operations</div>'
      + '  <div class="wsv2-legend-row" id="wsv2-dismiss-info" style="display:none;border-top:1px solid var(--bd);margin-top:6px;padding-top:6px;cursor:pointer" onclick="wsv2_clearDismissed()" title="Restore all dismissed cards">'
      + '    <span id="wsv2-dismiss-count" style="font-size:10px;color:var(--t3)">0</span>&nbsp;<span style="font-size:10px;color:var(--t3)">hidden &middot; restore</span>'
      + '  </div>'
      + '</div>'
      + '<div class="wsv2-activity" id="wsv2-activity">'
      + '  <div class="wsv2-activity-head"><span>&#9728; Live Activity</span><button onclick="wsv2_toggleActivity()" class="wsv2-act-close">&#x2715;</button></div>'
      + '  <div class="wsv2-activity-feed" id="wsv2-activity-feed"><div class="wsv2-act-empty">No activity yet.</div></div>'
      + '</div>';
  }

  // ── Inline CSS (mirrors v1 .agent-node / .an-* exactly, scoped to wsv2-*) ─
  // Uses the SAME CSS variables defined at :root in index.html (--s1, --bd,
  // --rg, --fh, --p, etc.) so visuals match v1 identically.
  var STYLES = ''
    + '#view-workspace{position:relative;overflow:hidden;background:#0D0D0F;height:100%;font-family:var(--fb)}'
    + '.wsv2-viewport{position:absolute;inset:0;overflow:hidden;touch-action:none;overscroll-behavior:contain;background:#0D0D0F}'   /* WS-CANVAS-10: no native scrolling - pan and zoom are one transform */   /* WS-CANVAS-4: every touch gesture is ours; WS-CANVAS-7: no scroll anchoring under a gesture */
    + '#wsv2-viewport,#wsv2-viewport *{touch-action:none!important}'   /* WS-CANVAS-6 (forensic 2026-09-23): lu-mobile.css sets #wsv2-viewport{touch-action:pan-x pan-y} on phones by ID - the browser was panning and pinch-zooming underneath our handlers; ID + !important wins on every screen */
    + '.wsv2-canvas{position:relative;'
    +   'background:#0D0D0F;'
    +   'background-image:radial-gradient(circle,rgba(255,255,255,0.08) 1px,transparent 1px);'
    +   'background-size:24px 24px;background-position:0 0;'
    +   'transform-origin:0 0}'
    + '.wsv2-world{position:absolute;left:0;top:0;transform-origin:0 0}'
    + '.wsv2-zoomctl{position:absolute;left:12px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:21;display:flex;flex-direction:column;gap:4px;background:rgba(15,17,23,.85);backdrop-filter:blur(8px);padding:4px;border-radius:var(--r);border:1px solid var(--bd)}'
    + '.wsv2-zoomctl .wsv2-btn{width:38px;height:38px;padding:0;justify-content:center;font-size:16px;font-weight:700}.wsv2-zoomctl #wsv2-zoom-pct{font-size:10px;font-weight:600;letter-spacing:.02em}'   /* WS-CANVAS-2 */
    + '.wsv2-svg{position:absolute;left:0;top:0;pointer-events:none;z-index:1;overflow:visible}'
    + '.wsv2-svg path{pointer-events:stroke;cursor:pointer}'
    + '.wsv2-zones{position:absolute;inset:0;pointer-events:none;z-index:0}'
    + '.wsv2-zone-overlay{position:absolute;border-radius:16px;pointer-events:none;z-index:0;transition:opacity .3s}'
    + '.wsv2-zone-label{position:absolute;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;opacity:.35;pointer-events:none;z-index:0;font-family:var(--fh)}'
    + '.wsv2-agents{position:absolute;inset:0;z-index:2}'
    /* card — mirrors .agent-node exactly */
    + '.wsv2-agent{position:absolute;width:' + CARD_W + 'px;cursor:grab;user-select:none;'
    +   'background:var(--s1);border:1px solid var(--bd);border-radius:var(--rg);'
    +   'padding:14px;box-shadow:0 4px 20px rgba(0,0,0,.3);'
    +   'transition:box-shadow .2s,border-color .2s}'
    + '.wsv2-agent:hover{box-shadow:0 8px 32px rgba(0,0,0,.4);border-color:var(--bd2)}'
    + '.wsv2-agent:active{cursor:grabbing}'
    + '.wsv2-agent.selected{border-color:rgba(108,92,231,.3);box-shadow:0 0 0 2px var(--pg),0 8px 32px rgba(0,0,0,.4)}'
    + '.wsv2-agent.dragging{opacity:.85;z-index:100;cursor:grabbing}'
    + '.wsv2-agent,.wsv2-task-node{-webkit-touch-callout:none}'
    + '.wsv2-agent img,.wsv2-agent .orb,.wsv2-agent .orb *,.wsv2-agent svg{-webkit-user-drag:none;user-select:none;pointer-events:none}'
    /* head row — mirrors .an-top */
    + '.wsv2-agent-head{display:flex;align-items:center;gap:9px;margin-bottom:10px}'
    + '.wsv2-agent .orb{flex-shrink:0}'
    + '.wsv2-agent-info{flex:1;min-width:0}'
    /* name + role — mirror .an-name / .an-role */
    + '.wsv2-name{font-family:var(--fh);font-size:12px;font-weight:700;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;line-height:1.2}'
    + '.wsv2-name:hover{text-decoration:underline}'
    + '.wsv2-title{font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.05em;margin-top:1px}'
    /* stats — mirror .an-tasks / .an-task-row / .an-task-lbl / .an-task-count */
    + '.wsv2-stats{display:flex;flex-direction:column;gap:3px}'
    + '.wsv2-stat-row{display:flex;align-items:center;justify-content:space-between}'
    + '.wsv2-stat-lbl{font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.05em}'
    + '.wsv2-stat-val{font-family:var(--fh);font-size:13px;font-weight:700;color:var(--t1);line-height:1}'
    + '.wsv2-stat.ongoing .wsv2-stat-val{color:#3DD9B0}'
    + '.wsv2-stat.upcoming .wsv2-stat-val{color:var(--am)}'
    /* progress bar — mirrors .an-task-bar / .an-task-fill */
    + '.wsv2-bar{height:2px;background:var(--s3);border-radius:99px;overflow:hidden;margin-top:3px}'
    + '.wsv2-bar-fill{height:100%;border-radius:99px;transition:width .5s ease;background:var(--p)}'
    /* blocked pill */
    + '.wsv2-blocked{margin-top:6px;font-size:9px;color:var(--rd);background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.2);padding:3px 6px;border-radius:4px;text-align:center;display:none;font-family:var(--fb)}'
    + '.wsv2-blocked.show{display:block}'
    /* CTA — mirrors .an-open-btn */
    + '.wsv2-cta{margin-top:10px;width:100%;height:26px;background:var(--s2);border:1px solid var(--bd);border-radius:6px;color:var(--t2);font-size:10px;font-weight:600;font-family:var(--fb);cursor:pointer;transition:all var(--tr)}'
    + '.wsv2-cta:hover{border-color:var(--p);color:var(--pu);background:var(--ps)}'
    /* lasso + toolbar + selection + legend + activity */
    /* task-node — mirrors v1 .task-node / .tn-* exactly (scoped) */
    + '.wsv2-task-node{position:absolute;width:140px;background:var(--s2);border:1px solid var(--bd);border-left:3px solid var(--p);border-radius:10px;padding:10px 11px;box-shadow:0 2px 12px rgba(0,0,0,.35);z-index:3;cursor:grab;transition:border-color .2s,box-shadow .2s;user-select:none}'
    /* Category dot used in the title prefix + agent card stats row */
    + '.wsv2-cat-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;vertical-align:middle;flex-shrink:0}'
    /* Per-agent category breakdown row on the agent card */
    + '.wsv2-cat-strip{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px;font-family:var(--fb)}'
    + '.wsv2-cat-chip{display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:600;color:var(--t2);padding:1px 5px;border-radius:99px;background:var(--s3);border:1px solid var(--bd)}'
    + '.wsv2-task-node:hover{border-color:var(--bd2);box-shadow:0 6px 22px rgba(0,0,0,.45)}'
    + '.wsv2-task-node:active{cursor:grabbing}'
    + '.wsv2-task-node.dragging{opacity:.85;z-index:101}'
    + '.wsv2-task-node.awaiting-approval{border-color:var(--rd) !important;animation:wsv2-approval-blink 1.4s ease-in-out infinite}'
    + '.wsv2-task-node.just-completed{border-color:#3DD9B0 !important;animation:wsv2-completed-flash 1.4s ease-in-out 4}'
    + '.wsv2-task-node.status-completed{border-color:rgba(108,92,231,.55) !important}'
    + '.wsv2-task-node.status-completed .tn-status{color:#3DD9B0}'
    + '.wsv2-task-node.status-running{border-color:rgba(61,217,176,.55) !important}'
    + '.wsv2-task-node.status-running .tn-status{color:#3DD9B0}'
    + '.wsv2-task-node.status-failed{border-color:rgba(248,113,113,.55) !important}'
    + '.wsv2-task-node.status-failed .tn-status{color:var(--rd)}'
    + '@keyframes wsv2-completed-flash{0%,100%{box-shadow:0 2px 12px rgba(0,0,0,.35)}50%{box-shadow:0 0 0 4px rgba(61,217,176,.4),0 2px 12px rgba(0,0,0,.35)}}'
    /* dismiss ✕ — visible only on hover, only on terminal tasks */
    + '.wsv2-tn-close{position:absolute;top:5px;right:5px;width:18px;height:18px;border:none;background:rgba(255,255,255,.06);color:var(--t2);cursor:pointer;border-radius:50%;font-size:13px;line-height:1;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s,background .15s,color .15s;padding:0;font-family:var(--fb)}'
    + '.wsv2-task-node:hover .wsv2-tn-close{opacity:.85}'
    + '.wsv2-task-node.status-completed .wsv2-tn-close,.wsv2-task-node.status-failed .wsv2-tn-close{opacity:.85}'   /* TN-CLOSE-1 (Owner 2026-09-24): a finished card always shows its x - a phone has no hover */
    + '@media (hover:none),(pointer:coarse){.wsv2-tn-close{opacity:1;width:30px!important;height:30px!important;min-width:30px!important;min-height:30px!important;font-size:18px;top:3px;right:3px;background:var(--s1);border:1px solid var(--bd);color:var(--t1);box-shadow:0 1px 4px rgba(0,0,0,.18)}'
    + '.wsv2-task-node.status-completed .tn-title,.wsv2-task-node.status-failed .tn-title{padding-right:32px}}'   /* STRAT-X-1: the x sits in the corner as a real button; the title no longer runs under it */
    + '.wsv2-tn-close:hover{background:var(--rd);color:#fff;opacity:1!important}'
    + '.wsv2-task-node .tn-title{font-size:10px;font-weight:700;color:var(--t1);line-height:1.4;margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:var(--fb)}'
    + '.wsv2-task-node .tn-assignees{display:flex;gap:3px;flex-wrap:wrap;margin-bottom:5px}'
    + '.wsv2-task-node .tn-av{width:18px;height:18px;border-radius:4px;font-size:9px;display:flex;align-items:center;justify-content:center;border:1px solid transparent;color:var(--t1)}'
    + '.wsv2-task-node .tn-meta{display:flex;align-items:center;justify-content:space-between}'
    + '.wsv2-task-node .tn-pri{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:1px 6px;border-radius:99px;font-family:var(--fh)}'
    + '.wsv2-task-node .tn-pri.high{background:rgba(248,113,113,.12);color:var(--rd);border:1px solid rgba(248,113,113,.2)}'
    + '.wsv2-task-node .tn-pri.medium{background:rgba(245,158,11,.12);color:var(--am);border:1px solid rgba(245,158,11,.2)}'
    + '.wsv2-task-node .tn-pri.low{background:rgba(139,151,176,.1);color:var(--t2);border:1px solid rgba(139,151,176,.18)}'
    + '.wsv2-task-node .tn-pri.urgent{background:rgba(248,113,113,.2);color:#fff;border:1px solid var(--rd)}'
    + '.wsv2-task-node .tn-status{font-size:8px;color:var(--t3);font-family:var(--fb)}'
    + '@keyframes wsv2-approval-blink{0%,100%{box-shadow:0 2px 12px rgba(0,0,0,.35)}50%{box-shadow:0 0 0 3px rgba(248,113,113,.4),0 2px 12px rgba(0,0,0,.35)}}'
    + '.wsv2-lasso{position:absolute;background:rgba(108,92,231,.15);border:1px dashed var(--p);pointer-events:none;z-index:50}'
    + '.wsv2-toolbar{position:absolute;top:14px;right:14px;display:flex;gap:6px;z-index:20;background:rgba(15,17,23,.85);backdrop-filter:blur(8px);padding:6px;border-radius:var(--r);border:1px solid var(--bd)}'
    + '.wsv2-btn{height:32px;padding:0 14px;background:var(--s1);border:1px solid var(--bd);border-radius:var(--r);color:var(--t2);font-family:var(--fb);font-size:11px;font-weight:600;cursor:pointer;transition:all var(--tr);display:inline-flex;align-items:center;gap:6px;touch-action:manipulation}'
    + '.wsv2-btn:hover{border-color:var(--bd2);color:var(--t1)}'
    + '.wsv2-btn-primary{background:var(--p);border-color:var(--p);color:#fff;box-shadow:0 4px 14px var(--pg)}'
    + '.wsv2-btn-primary:hover{opacity:.9;color:#fff}'
    + '.wsv2-selection{position:absolute;left:50%;transform:translateX(-50%);bottom:calc(20px + env(safe-area-inset-bottom,0px));display:flex;gap:8px;align-items:center;z-index:25;background:var(--s1);border:1px solid var(--bd);padding:8px 12px;border-radius:var(--rg);box-shadow:0 8px 24px rgba(0,0,0,.4)}'
    + '.wsv2-sel-info{font-size:12px;color:var(--t2);font-weight:500;padding:0 6px;font-family:var(--fb)}'
    + '.wsv2-legend{position:absolute;left:14px;top:calc(14px + env(safe-area-inset-top,0px));background:rgba(15,17,23,.85);backdrop-filter:blur(8px);border:1px solid var(--bd);border-radius:var(--r);padding:10px 12px;font-size:11px;z-index:15;color:var(--t2);font-family:var(--fb)}'
    + '.wsv2-legend-title{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);margin-bottom:6px;font-weight:700;font-family:var(--fh)}'
    + '.wsv2-legend-row{display:flex;align-items:center;gap:6px;margin:3px 0}'
    + '.wsv2-sw{display:inline-block;width:12px;height:3px;border-radius:2px}'
    + '.wsv2-activity{position:absolute;left:0;right:0;bottom:0;height:200px;background:var(--s1);border-top:1px solid var(--bd);transform:translateY(100%);transition:transform .25s ease;z-index:30;display:flex;flex-direction:column}'
    + '.wsv2-activity.open{transform:translateY(0)}'
    + '.wsv2-activity-head{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--bd);font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.06em;font-family:var(--fh)}'
    + '.wsv2-act-close{background:none;border:none;color:var(--t2);cursor:pointer;font-size:14px;padding:0 4px}'
    + '.wsv2-activity-feed{flex:1;overflow-y:auto;padding:10px 14px;font-size:12px;color:var(--t2);font-family:var(--fb)}'
    + '.wsv2-act-empty{text-align:center;color:var(--t3);padding:20px;font-size:11px}'
    + '@keyframes wsv2-flow{to{stroke-dashoffset:-22}}'
    + '@keyframes wsv2-pulse{0%,100%{opacity:.4}50%{opacity:1}}'
    + '.wsv2-line{fill:none;stroke-width:2;stroke-dasharray:7,4}'
    + '.wsv2-line.active{animation:wsv2-flow 1.4s linear infinite}'
    + '.wsv2-line.flash{animation:wsv2-pulse .6s ease-in-out 0s 3}'
    + '@media (max-width:767px){'
    +   '.wsv2-agent{width:150px;padding:12px}'
    +   '.wsv2-toolbar{top:8px;left:8px;right:8px;padding:4px;gap:4px;max-width:calc(100% - 16px);overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch;scrollbar-width:none;justify-content:flex-start}.wsv2-toolbar::-webkit-scrollbar{display:none}.wsv2-toolbar .wsv2-btn{flex:0 0 auto;white-space:nowrap}'   /* WS-CANVAS-3 (Owner 2026-09-23): one row inside the screen, scrolls sideways */
    +   '.wsv2-btn{height:28px;padding:0 10px;font-size:10px}'
    +   '.wsv2-selection{flex-wrap:wrap;justify-content:center;left:8px;right:8px;transform:none;max-width:none;padding:6px 8px}'
    +   '.wsv2-legend{display:none}'
    + '}';

  // ── Fetch + render loop ──────────────────────────────────────────────────
  function refresh() {
    var seq = ++STATE.fetchSeq;
    if (STATE.inFlight) { try { STATE.inFlight.abort(); } catch (e) {} }
    var ac = new AbortController();
    STATE.inFlight = ac;
    var wsId = getWorkspaceId();
    fetch('/api/workspace/state', {
      headers: { 'Authorization': 'Bearer ' + getToken(), 'X-Workspace-Id': String(wsId) },
      signal: ac.signal,
    })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (data) {
        if (seq !== STATE.fetchSeq) return;
        var prevTasksById = {};
        STATE.tasks.forEach(function (t) { prevTasksById[t.id] = t.status; });
        STATE.agents = data.agents || [];
        STATE.tasks = data.tasks || [];
        STATE.positions = data.positions || {};
        try { localStorage.setItem('wsv2_state_' + getWorkspaceId(), JSON.stringify({ at: Date.now(), agents: STATE.agents, tasks: STATE.tasks, positions: STATE.positions })); } catch (_q) {}   /* WS-FAST-1 */
        syncGlobalAgentsMap();
        detectStatusTransitions(prevTasksById);
        var unplaced = STATE.agents.some(function (a) { return !(STATE.overrides[a.slug] || STATE.positions[a.slug]); });   // WS-CANVAS-1: newly added agents
        if (!STATE.initialLayoutDone || unplaced) {
          computeInitialLayout();
          STATE.initialLayoutDone = true;
        }
        render();
        try { campCard(false); } catch (_c) {}   /* WS-CAMP-1 */
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        console.warn('[wsv2] fetch failed', err);
      });
  }

  function ensurePollLoop() {
    if (STATE.pollTimer) return;
    STATE.pollTimer = setInterval(function () {
      var view = document.getElementById('view-workspace');
      if (view && view.classList.contains('active')) refresh();
    }, 5000);
  }

  // ── Radial initial layout (Sarah center, others in rings by zone) ────────
  function computeInitialLayout() {
    var centerX = CANVAS_W / 2;
    var centerY = CANVAS_H / 2;
    var zones = { leadership: [], strategy: [], execution: [] };
    STATE.agents.forEach(function (a) {
      var zone = zoneOf(a);
      zones[zone].push(a);
    });

    // Sarah dead center
    if (zones.leadership[0]) {
      var s = zones.leadership[0];
      if (!STATE.positions[s.slug]) {
        STATE.overrides[s.slug] = { x: centerX - CARD_W / 2, y: centerY - CARD_H / 2 };
      }
    }
    // Strategy ring (radius 400)
    placeOnRing(zones.strategy, centerX, centerY, 420, -Math.PI / 2);
    // Execution ring (radius 750)
    placeOnRing(zones.execution, centerX, centerY, 760, -Math.PI / 2);
    // Any additional leadership agents (rare)
    if (zones.leadership.length > 1) {
      placeOnRing(zones.leadership.slice(1), centerX, centerY, 200, Math.PI / 2);
    }
  }

  function placeOnRing(agents, cx, cy, r, startAngle) {
    if (!agents.length) return;
    var step = (2 * Math.PI) / Math.max(agents.length, 1);
    agents.forEach(function (a, i) {
      if (STATE.positions[a.slug]) return; // honor saved position
      var ang = startAngle + step * i;
      var x = cx + r * Math.cos(ang) - CARD_W / 2;
      var y = cy + r * Math.sin(ang) - CARD_H / 2;
      STATE.overrides[a.slug] = { x: x, y: y };
    });
  }

  // ── Render ───────────────────────────────────────────────────────────────
  function render() {
    renderAgents();
    try { fitCanvas(); } catch (e) {}   // WS-CANVAS-1
    if (!STATE.fittedOnce && STATE.agents.length) { STATE.fittedOnce = true; try { fitAll(); } catch (e) {} }   // WS-FIT-1: the first view holds the whole team
    requestAnimationFrame(function () {
      try { layoutTaskNodes(); } catch (e) { console.warn('[wsv2] task placement failed', e); }   // WS-TASKS-1
      renderZones();
      renderTaskNodes();
      renderLines();
      updateDismissedIndicator();
    });
  }

  function updateDismissedIndicator() {
    var info = document.getElementById('wsv2-dismiss-info');
    var countEl = document.getElementById('wsv2-dismiss-count');
    if (!info) return;
    var n = STATE.dismissed ? STATE.dismissed.size : 0;
    info.style.display = n > 0 ? 'flex' : 'none';
    if (countEl) countEl.textContent = String(n);
  }

  // ── Task-node midpoint cards ─────────────────────────────────────────────
  // Mirrors v1's renderTaskNode behavior: for each multi-assignee task,
  // render a draggable card at the centroid of its assignees. Lines from
  // the card radiate to each assigned agent (in addition to the existing
  // agent-to-agent pair lines).
  var TN_W = 140;
  var TN_H = 88;
  var TN_GAP = 16;   // WS-TASKS-1: clear space a task card keeps from every other card
  function rectsOverlap(a, b, gap) { return !(a.x + a.w + gap <= b.x || b.x + b.w + gap <= a.x || a.y + a.h + gap <= b.y || b.y + b.h + gap <= a.y); }
  // WS-TASKS-1: where every visible task card goes this render. Manual positions are kept; the rest never touch another card.
  function layoutTaskNodes() {
    if (!STATE.dismissed) STATE.dismissed = loadDismissed();
    var obstacles = [];
    STATE.agents.forEach(function (a) { var p = STATE.overrides[a.slug] || STATE.positions[a.slug]; if (p) obstacles.push({ x: p.x, y: p.y, w: CARD_W, h: CARD_H }); });
    var out = {}, cache = STATE.taskAutoPos || (STATE.taskAutoPos = {});
    var assigneesOf = function (t) { return effectiveAssignees(t).filter(function (id) { return !!(STATE.overrides[id] || STATE.positions[id]); }); };
    var visible = STATE.tasks.filter(function (t) {
      if (STATE.dismissed.has(Number(t.id))) return false;
      if (['completed', 'failed', 'cancelled', 'degraded'].indexOf(t.status) >= 0) { var doneAt = t.completed_at || t.updated_at; if (doneAt && (Date.now() - new Date(doneAt).getTime()) > AUTO_DISMISS_MS) return false; }
      if (effectiveAssignees(t).length < 2) return false;
      return assigneesOf(t).length >= 2;
    }).sort(function (a, b) { return Number(a.id) - Number(b.id); });
    visible.forEach(function (t) { var m = STATE.taskNodePos[t.id]; if (m) { out[t.id] = { x: m.x, y: m.y, manual: true }; obstacles.push({ x: m.x, y: m.y, w: TN_W, h: TN_H }); } });
    visible.forEach(function (t) {
      if (out[t.id]) return;
      var ag = assigneesOf(t), sx = 0, sy = 0;
      ag.forEach(function (id) { var p = STATE.overrides[id] || STATE.positions[id]; sx += p.x + CARD_W / 2; sy += p.y + CARD_H / 2; });
      var dx = sx / ag.length - TN_W / 2, dy = sy / ag.length - TN_H / 2, key = Math.round(dx) + ',' + Math.round(dy);
      var free = function (x, y) { var r = { x: x, y: y, w: TN_W, h: TN_H }; for (var i = 0; i < obstacles.length; i++) { if (rectsOverlap(r, obstacles[i], TN_GAP)) return false; } return true; };
      var c = cache[t.id], pos = null;
      if (c && c.key === key && free(c.x, c.y)) pos = { x: c.x, y: c.y };
      if (!pos && free(dx, dy)) pos = { x: dx, y: dy };
      for (var r = 24; r <= 2400 && !pos; r += 24) {
        var steps = Math.max(8, Math.min(48, Math.round(r / 12)));
        for (var k = 0; k < steps && !pos; k++) { var ang = -Math.PI / 2 + (2 * Math.PI * k) / steps; var x = Math.round(dx + r * Math.cos(ang)), y = Math.round(dy + r * Math.sin(ang)); if (free(x, y)) pos = { x: x, y: y }; }
      }
      if (!pos) pos = { x: dx, y: dy };
      cache[t.id] = { x: pos.x, y: pos.y, key: key };
      out[t.id] = { x: pos.x, y: pos.y, manual: false };
      obstacles.push({ x: pos.x, y: pos.y, w: TN_W, h: TN_H });
    });
    STATE.taskRenderPos = out;
  }

  function renderTaskNodes() {
    var host = document.getElementById('wsv2-agents');
    if (!host) return;
    // Remove stale
    Array.prototype.forEach.call(host.querySelectorAll('.wsv2-task-node'), function (n) { n.remove(); });

    if (!STATE.dismissed) STATE.dismissed = loadDismissed();
    STATE.tasks.forEach(function (task) {
      // User-dismissed via ✕ → skip
      if (STATE.dismissed.has(Number(task.id))) return;
      // 24h auto-dismiss for terminal-state tasks
      var isTerminal = (['completed', 'failed', 'cancelled', 'degraded'].indexOf(task.status) >= 0);
      if (isTerminal) {
        var doneAt = task.completed_at || task.updated_at;
        if (doneAt) {
          var ageMs = Date.now() - new Date(doneAt).getTime();
          if (ageMs > AUTO_DISMISS_MS) {
            STATE.dismissed.add(Number(task.id));
            saveDismissed();
            return;
          }
        }
      }
      var agents = effectiveAssignees(task);
      var rp = STATE.taskRenderPos && STATE.taskRenderPos[task.id]; if (!rp) return;   // WS-TASKS-1: placed by layoutTaskNodes
      var cx = rp.x, cy = rp.y;

      var priCls = (task.priority === 'high' || task.priority === 'urgent') ? task.priority
                 : (task.priority === 'low' ? 'low' : 'medium');
      var stLabel = (task.status === 'running' || task.status === 'verifying') ? 'Active'
                  : (['pending', 'queued', 'awaiting_approval'].indexOf(task.status) >= 0) ? 'Planned'
                  : task.status === 'completed' ? 'Done'
                  : task.status === 'blocked' ? 'Blocked'
                  : task.status === 'failed' ? 'Failed'
                  : (task.status || 'Planned');
      var awaiting = (task.status === 'awaiting_approval');
      var justCompleted = STATE.recentlyCompleted && STATE.recentlyCompleted[task.id] && (Date.now() - STATE.recentlyCompleted[task.id] < 6000);
      var statusClass = task.status === 'completed' ? ' status-completed'
                     : task.status === 'running' || task.status === 'verifying' ? ' status-running'
                     : (task.status === 'failed' || task.status === 'cancelled' || task.status === 'degraded') ? ' status-failed'
                     : '';
      var title = task.action ? humanize(task.action) : ('Task #' + task.id);

      var orbsHtml = agents.slice(0, 5).map(function (slug) {
        var rc = roleColorOf(slug);
        var em = (window.AGENTS && window.AGENTS[slug] && window.AGENTS[slug].emoji) || initialOf(slug);
        var bg = hexA(rc, 0.13);
        var bd = hexA(rc, 0.27);
        return '<div class="tn-av" style="background:' + bg + ';border-color:' + bd + ';color:' + rc + '" title="' + escapeHtml(slug) + '">' + escapeHtml(em) + '</div>';
      }).join('');

      var node = document.createElement('div');
      node.className = 'wsv2-task-node'
        + (awaiting ? ' awaiting-approval' : '')
        + statusClass
        + (justCompleted ? ' just-completed' : '');
      node.dataset.taskId = String(task.id);
      node.style.left = cx + 'px';
      node.style.top = cy + 'px';
      // 2026-05-27 — category color on the left border (Phase 1 visual)
      var catColor = task.category_color || '#6B7280';
      node.style.borderLeftColor = catColor;
      node.dataset.category = task.category || 'operations';
      // ✕ button only on terminal-state tasks (completed/failed/cancelled)
      var isTerminalRender = (['completed', 'failed', 'cancelled', 'degraded'].indexOf(task.status) >= 0);
      var closeBtnHtml = isTerminalRender
        ? '<button class="wsv2-tn-close" data-dismiss-task="' + task.id + '" title="Dismiss from canvas (task stays in history)">&times;</button>'
        : '';
      var catDot = '<span class="wsv2-cat-dot" style="background:' + catColor + '" title="' + escapeHtml(task.category_label || task.category || '') + '"></span>';
      node.innerHTML = closeBtnHtml
        + '<div class="tn-title" title="' + escapeHtml(title) + '">' + catDot + escapeHtml(title) + '</div>'
        + '<div class="tn-assignees">' + orbsHtml + '</div>'
        + '<div class="tn-meta">'
        + '  <span class="tn-pri ' + priCls + '">' + escapeHtml(task.priority || 'medium') + '</span>'
        + '  <span class="tn-status">' + escapeHtml(stLabel) + '</span>'
        + '</div>';
      host.appendChild(node);
    });
  }

  function startTaskDrag(taskId, node, cx, cy) {
    var left = parseFloat(node.style.left) || 0;
    var top = parseFloat(node.style.top) || 0;
    STATE.taskDrag.active = true;
    STATE.taskDrag.taskId = taskId;
    STATE.taskDrag.startX = cx;
    STATE.taskDrag.startY = cy;
    STATE.taskDrag.origX = left;
    STATE.taskDrag.origY = top;
    STATE.taskDrag.moved = false;
    node.classList.add('dragging');
    lockCanvasTouchScroll(true);
  }

  function moveTaskDrag(cx, cy) {
    if (!STATE.taskDrag.active) return;
    var scale = STATE.canvasScale || 1;
    var dx = (cx - STATE.taskDrag.startX) / scale;
    var dy = (cy - STATE.taskDrag.startY) / scale;
    if (Math.abs(dx) > 3 || Math.abs(dy) > 3) STATE.taskDrag.moved = true;
    var nx = STATE.taskDrag.origX + dx, ny = STATE.taskDrag.origY + dy;   // WS-CANVAS-1
    STATE.taskNodePos[STATE.taskDrag.taskId] = { x: nx, y: ny };
    if (STATE.taskAutoPos) delete STATE.taskAutoPos[STATE.taskDrag.taskId];   // WS-TASKS-1: manual from now on
    if (STATE.taskRenderPos && STATE.taskRenderPos[STATE.taskDrag.taskId]) STATE.taskRenderPos[STATE.taskDrag.taskId] = { x: nx, y: ny, manual: true };
    scheduleFit();
    var el = document.querySelector('.wsv2-task-node[data-task-id="' + STATE.taskDrag.taskId + '"]');
    if (el) { el.style.left = nx + 'px'; el.style.top = ny + 'px'; }
    requestAnimationFrame(renderLines);
  }

  function endTaskDrag() {
    if (!STATE.taskDrag.active) return;
    var el = document.querySelector('.wsv2-task-node[data-task-id="' + STATE.taskDrag.taskId + '"]');
    if (el) el.classList.remove('dragging');
    STATE.taskDrag.active = false;
    STATE.taskDrag.taskId = null;
    lockCanvasTouchScroll(false);
  }

  // Disable native pan-scroll on the canvas during an active drag so the
  // browser doesn't fight my touchmove handler on mobile (one finger would
  // simultaneously move the agent AND pan the viewport, feels slippery).
  function lockCanvasTouchScroll(lock) {
    var c = document.getElementById('wsv2-canvas');
    var vp = document.getElementById('wsv2-viewport');
    if (c) c.style.touchAction = lock ? 'none' : '';
    if (vp) vp.style.touchAction = lock ? 'none' : 'pan-x pan-y';
  }

  function renderAgents() {
    var host = document.getElementById('wsv2-agents');
    if (!host) return;
    if (!STATE.agents.length) {
      // Only show the empty-state on a TRULY fresh view. If we've ever had
      // agents before, this empty render is a transient anomaly (polling
      // race, momentary 401, workspace switch mid-flight) — don't wipe the
      // canvas; kick off a refresh to recover.
      if (STATE.hadAgentsOnce) {
        refresh();
        return;
      }
      host.innerHTML = '<div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);color:#8E8E96;font-size:13px;text-align:center;width:300px">No agents enabled for this workspace yet.<br><br>Go to <b>Agents</b> tab to activate your team.</div>';
      return;
    }
    STATE.hadAgentsOnce = true;

    var existing = {};
    Array.prototype.forEach.call(host.querySelectorAll('.wsv2-agent'), function (el) {
      existing[el.dataset.slug] = el;
    });

    var seen = {};
    STATE.agents.forEach(function (a) {
      seen[a.slug] = true;
      var el = existing[a.slug];
      if (!el) {
        el = document.createElement('div');
        el.className = 'wsv2-agent';
        el.dataset.slug = a.slug;
        host.appendChild(el);
      }
      el.classList.toggle('selected', STATE.selected.has(a.slug));
      // 2026-05-27 — defensive: one bad agent must not blank the whole canvas.
      try {
        el.innerHTML = agentMarkup(a);
      } catch (e) {
        console.error('[wsv2] agentMarkup threw for', a && a.slug, e);
        el.innerHTML = '<div style="padding:14px;color:var(--rd);font-size:11px">Could not render ' + escapeHtml(a && a.slug || 'agent') + '</div>';
      }
      var pos = STATE.overrides[a.slug] || STATE.positions[a.slug];
      if (pos) {
        el.style.left = pos.x + 'px';
        el.style.top  = pos.y + 'px';
      }
    });
    Object.keys(existing).forEach(function (slug) {
      if (!seen[slug]) existing[slug].remove();
    });
  }

  function agentMarkup(a) {
    var s = a.stats || {};
    var done = (s.completed | 0) + (s.failed | 0);
    var total = Math.max((s.ongoing | 0) + (s.upcoming | 0) + done, 1);
    var pct = Math.round((done / total) * 100);
    // Use the canonical global Orb Avatar System. NEVER inline emoji/CSS here.
    // See project_orb_system_locked memory + /app/js/orb.js for full API.
    var orbHtml = (typeof window.buildAgentOrb === 'function')
      ? window.buildAgentOrb(a.slug, 'md', 'idle')
      : '<div class="orb" data-agent="' + escapeHtml(a.slug) + '" data-type="dmm" data-size="md" data-state="idle"><div class="orb-core"></div><div class="orb-shine"></div><div class="orb-ring"></div></div>';
    var roleColor = (window.OrbAvatar && window.OrbAvatar.ORB_CONFIG)
      ? (window.OrbAvatar.ORB_CONFIG[(window.OrbAvatar.AGENT_ORB_MAP || {})[a.slug] || 'dmm'] || {}).color
      : '#6C5CE7';
    return ''
      + '<div class="wsv2-agent-head">'
      + '  ' + orbHtml
      + '  <div class="wsv2-agent-info">'
      + '    <div class="wsv2-name">' + escapeHtml(a.name || a.slug) + '</div>'
      + '    <div class="wsv2-title">' + escapeHtml(truncate(a.title || a.category || '', 22)) + '</div>'
      + '  </div>'
      + '</div>'
      + '<div class="wsv2-stats">'
      + '  <div class="wsv2-stat-row wsv2-stat ongoing"><span class="wsv2-stat-lbl">Ongoing</span><span class="wsv2-stat-val">' + (s.ongoing | 0) + '</span></div>'
      + '  <div class="wsv2-stat-row wsv2-stat upcoming"><span class="wsv2-stat-lbl">Upcoming</span><span class="wsv2-stat-val">' + (s.upcoming | 0) + '</span></div>'
      + '  <div class="wsv2-stat-row"><span class="wsv2-stat-lbl">Completed</span><span class="wsv2-stat-val">' + (s.completed | 0) + '</span></div>'
      + '</div>'
      + '<div class="wsv2-bar"><div class="wsv2-bar-fill" style="width:' + pct + '%;background:' + (roleColor || '#6C5CE7') + '"></div></div>'
      + categoryStripFor(a.slug)
      + '<div class="wsv2-blocked' + ((s.blocked | 0) > 0 ? ' show' : '') + '">&#9888; ' + (s.blocked | 0) + ' blocked &mdash; tap to review</div>'
      + '<button class="wsv2-cta" type="button" data-slug="' + escapeHtml(a.slug) + '">View Profile &amp; Tasks &rarr;</button>';
  }

  // Build the per-agent category-breakdown strip shown on each card.
  // Counts only NON-terminal tasks for this agent (effectiveAssignees logic
  // so Sarah picks up her orchestration share). Empty when the agent has no
  // active work, to avoid a row of zeros that adds visual noise.
  function categoryStripFor(slug) {
    var counts = {};
    // Defensive: STATE.tasks should always be an array (init + every refresh),
    // but a partial-render edge case could leave it undefined. Bail cleanly
    // rather than throw and break the whole agent card render.
    if (!Array.isArray(STATE.tasks)) return '';
    STATE.tasks.forEach(function (t) {
      if (!t) return;
      var terminal = (['completed', 'failed', 'cancelled', 'degraded'].indexOf(t.status) >= 0);
      if (terminal) return;
      var agents = effectiveAssignees(t);
      if (!Array.isArray(agents) || agents.indexOf(slug) === -1) return;
      var c = t.category || 'operations';
      counts[c] = (counts[c] | 0) + 1;
    });
    var keys = Object.keys(counts);
    if (!keys.length) return '';
    // Stable display order matches the taxonomy
    var ORDER = ['research', 'create', 'optimize', 'publish', 'crm', 'operations'];
    var COLOR = { research:'#3B82F6', create:'#7C3AED', optimize:'#00E5A8', publish:'#F59E0B', crm:'#EC4899', operations:'#6B7280' };
    var LABEL = { research:'R', create:'C', optimize:'O', publish:'P', crm:'CRM', operations:'OPS' };
    var FULL  = { research:'Research', create:'Create', optimize:'Optimize', publish:'Publish', crm:'CRM', operations:'Operations' };
    var chips = ORDER.filter(function (k) { return counts[k]; }).map(function (k) {
      return '<span class="wsv2-cat-chip" title="' + FULL[k] + ': ' + counts[k] + '">'
        + '<span class="wsv2-cat-dot" style="background:' + COLOR[k] + ';margin-right:3px"></span>'
        + LABEL[k] + ' ' + counts[k]
        + '</span>';
    });
    return '<div class="wsv2-cat-strip">' + chips.join('') + '</div>';
  }

  // ── Zones ────────────────────────────────────────────────────────────────
  function renderZones() {
    var host = document.getElementById('wsv2-zones');
    var canvas = document.getElementById('wsv2-canvas');
    if (!host || !canvas) return;
    host.innerHTML = '';

    // Compute bounding box per zone from current absolute card positions
    var groups = { leadership: [], strategy: [], execution: [] };
    STATE.agents.forEach(function (a) {
      var z = zoneOf(a);
      var pos = STATE.overrides[a.slug] || STATE.positions[a.slug];
      if (!pos) return;
      groups[z].push({ x: pos.x, y: pos.y, w: CARD_W, h: CARD_H });
    });

    Object.keys(groups).forEach(function (zKey) {
      var arr = groups[zKey];
      if (!arr.length) return;
      var minX = Math.min.apply(null, arr.map(function (r) { return r.x; }));
      var minY = Math.min.apply(null, arr.map(function (r) { return r.y; }));
      var maxX = Math.max.apply(null, arr.map(function (r) { return r.x + r.w; }));
      var maxY = Math.max.apply(null, arr.map(function (r) { return r.y + r.h; }));
      var pad = 30;
      var s = ZONE_STYLE[zKey];
      var overlay = document.createElement('div');
      overlay.className = 'wsv2-zone-overlay';
      overlay.style.cssText = 'left:' + (minX - pad) + 'px;top:' + (minY - pad) + 'px;'
        + 'width:' + (maxX - minX + pad * 2) + 'px;height:' + (maxY - minY + pad * 2) + 'px;'
        + 'background:' + s.bg + ';border-color:' + s.border;
      host.appendChild(overlay);
      var label = document.createElement('div');
      label.className = 'wsv2-zone-label';
      label.style.cssText = 'left:' + (minX - pad + 14) + 'px;top:' + (minY - pad + 8) + 'px;color:' + s.label;
      label.textContent = zKey;
      host.appendChild(label);
    });
  }

  // ── Lines ────────────────────────────────────────────────────────────────
  function renderLines() {
    var svg = document.getElementById('wsv2-svg');
    if (!svg) return;
    var pairMap = buildPairMap();
    var pairKeys = Object.keys(pairMap);
    svg.innerHTML = '';
    if (!pairKeys.length) { STATE.lastConnSig = ''; return; }

    var sig = pairKeys.sort().join('|');
    var prevKeys = STATE.lastConnSig.split('|');
    pairKeys.forEach(function (k) {
      if (prevKeys.indexOf(k) === -1) STATE.activePairsFlash[k] = Date.now();
    });
    STATE.lastConnSig = sig;

    pairKeys.forEach(function (k) {
      var info = pairMap[k];
      var pa = STATE.overrides[info.a] || STATE.positions[info.a];
      var pb = STATE.overrides[info.b] || STATE.positions[info.b];
      if (!pa || !pb) return;
      var x1 = pa.x + CARD_W / 2, y1 = pa.y + CARD_H / 2;
      var x2 = pb.x + CARD_W / 2, y2 = pb.y + CARD_H / 2;
      var mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
      var dx = x2 - x1, dy = y2 - y1;
      var len = Math.sqrt(dx * dx + dy * dy);
      var ox = len > 0 ? -dy / len * 30 : 0;
      var oy = len > 0 ?  dx / len * 30 : 0;
      var d = 'M ' + x1 + ' ' + y1 + ' Q ' + (mx + ox) + ' ' + (my + oy) + ' ' + x2 + ' ' + y2;
      var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      p.setAttribute('d', d);
      var color = info.status === 'active' ? '#3DD9B0' : (info.status === 'upcoming' ? '#F5A623' : '#6C5CE7');
      p.setAttribute('class', 'wsv2-line' + (info.status === 'active' ? ' active' : ''));
      p.setAttribute('stroke', color);
      p.setAttribute('opacity', info.status === 'settled' ? '0.5' : '0.9');
      if (STATE.activePairsFlash[k] && Date.now() - STATE.activePairsFlash[k] < 1800) {
        p.classList.add('flash');
      }
      var titleEl = document.createElementNS('http://www.w3.org/2000/svg', 'title');
      titleEl.textContent = info.tasks.length + ' task' + (info.tasks.length === 1 ? '' : 's');
      p.appendChild(titleEl);
      svg.appendChild(p);
    });

    // Radial thin lines from each task-node to each of its assignees.
    // Matches v1's renderTaskNode behaviour (the per-task spokes).
    if (!STATE.dismissed) STATE.dismissed = loadDismissed();
    STATE.tasks.forEach(function (task) {
      if (STATE.dismissed.has(Number(task.id))) return; // dismissed task-node → no spokes
      var agents = effectiveAssignees(task);
      if (agents.length < 2) return;
      var positioned = agents.filter(function (id) { return !!(STATE.overrides[id] || STATE.positions[id]); });
      if (positioned.length < 2) return;
      var rp = STATE.taskRenderPos && STATE.taskRenderPos[task.id]; if (!rp) return;   // WS-TASKS-1
      var cx = rp.x + TN_W / 2;
      var cy = rp.y + TN_H / 2;
      var stored = STATE.taskNodePos[task.id];
      var tnCx = stored ? stored.x + TN_W / 2 : cx;
      var tnCy = stored ? stored.y + TN_H / 2 : cy;
      positioned.forEach(function (id) {
        var pos = STATE.overrides[id] || STATE.positions[id];
        var ax = pos.x + CARD_W / 2;
        var ay = pos.y + CARD_H / 2;
        var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', tnCx);
        line.setAttribute('y1', tnCy);
        line.setAttribute('x2', ax);
        line.setAttribute('y2', ay);
        line.setAttribute('stroke', roleColorOf(id));
        line.setAttribute('stroke-width', '1.5');
        line.setAttribute('stroke-dasharray', '4,3');
        line.setAttribute('opacity', '0.55');
        line.style.pointerEvents = 'none';
        svg.appendChild(line);
      });
    });
  }

  function buildPairMap() {
    var map = {};
    if (!STATE.dismissed) STATE.dismissed = loadDismissed();
    STATE.tasks.forEach(function (t) {
      // Honor the same dismissal + 24h-horizon rules as renderTaskNodes
      // so the agent-to-agent Bezier line disappears when its last
      // task-node card is dismissed.
      if (STATE.dismissed.has(Number(t.id))) return;
      var isTerminal = (['completed', 'failed', 'cancelled', 'degraded'].indexOf(t.status) >= 0);
      if (isTerminal) {
        var doneAt = t.completed_at || t.updated_at;
        if (doneAt && (Date.now() - new Date(doneAt).getTime()) > AUTO_DISMISS_MS) return;
      }
      var agents = effectiveAssignees(t);
      if (agents.length < 2) return;
      var status = (t.status === 'running' || t.status === 'verifying') ? 'active'
                 : (['pending','queued','awaiting_approval','blocked'].indexOf(t.status) >= 0) ? 'upcoming'
                 : 'settled';
      for (var i = 0; i < agents.length; i++) {
        for (var j = i + 1; j < agents.length; j++) {
          var a = agents[i], b = agents[j];
          if (!a || !b) continue;
          var key = a < b ? a + '|' + b : b + '|' + a;
          if (!map[key]) map[key] = { a: a < b ? a : b, b: a < b ? b : a, tasks: [], status: status };
          map[key].tasks.push(t);
          if (status === 'active' || (status === 'upcoming' && map[key].status === 'settled')) {
            map[key].status = status;
          }
        }
      }
    });
    return map;
  }

  // Effective assignees for visualization = recorded assignees + implicit
  // orchestrator (Sarah) when the task was created via her chat OR extracted
  // from a meeting synthesis. The DB row stays untouched — this is purely a
  // canvas-render concern so Sarah↔specialist relationships are visible.
  function effectiveAssignees(t) {
    var agents = Array.isArray(t.assigned_agents) ? t.assigned_agents.slice() : [];
    var isOrchestrated = (t.created_via === 'sarah_chat' || t.created_via === 'sarah_proactive')
      || (t.from_meeting && Number(t.from_meeting) > 0);
    if (isOrchestrated && agents.indexOf('sarah') === -1) {
      agents.push('sarah');
    }
    return agents;
  }

  // ── Events ───────────────────────────────────────────────────────────────
  function wireEvents(root) {
    var viewport = root.querySelector('#wsv2-viewport');
    var canvas = root.querySelector('#wsv2-canvas');
    var agentsHost = root.querySelector('#wsv2-agents');

    // Task-node drag start (mouse) — checked first so a task-node click
    // doesn't bubble to agent-drag or lasso behaviour.
    canvas.addEventListener('mousedown', function (e) {
      // CTA button gets its own dedicated click handler — bail out completely
      // here so we don't start a lasso under the button.
      if (e.target.closest('.wsv2-cta')) return;
      // ✕ dismiss button — handled by delegated click below, don't drag-start.
      if (e.target.closest('.wsv2-tn-close')) return;
      var tn = e.target.closest('.wsv2-task-node');
      if (tn) {
        e.preventDefault();
        e.stopPropagation();
        startTaskDrag(tn.dataset.taskId, tn, e.clientX, e.clientY);
        return;
      }
      var node = e.target.closest('.wsv2-agent');
      if (node) {
        e.preventDefault();
        startAgentDrag(node, e.clientX, e.clientY);
        return;
      }
      // Empty canvas — start lasso (use canvas coords, not viewport)
      if (e.button !== 0) return;
      if (e.target.closest('.wsv2-toolbar') || e.target.closest('.wsv2-selection') || e.target.closest('.wsv2-activity') || e.target.closest('.wsv2-legend')) return;
      var c = canvasCoords(canvas, e.clientX, e.clientY);
      STATE.lasso.active = true;
      STATE.lasso.startX = c.x;
      STATE.lasso.startY = c.y;
      STATE.lasso.shift = e.shiftKey;
      var box = document.getElementById('wsv2-lasso');
      box.style.display = 'block';
      box.style.left = c.x + 'px';
      box.style.top = c.y + 'px';
      box.style.width = '0px';
      box.style.height = '0px';
    });

    // WS-CANVAS-10: two fingers zoom the transform around the world point that was between them at the start; the
    // last move is the final state, so lifting the fingers changes nothing. One finger pans the transform.
    var pinch = null, pan = null;
    function pinchDist(e) { var a = e.touches[0], b = e.touches[1]; return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY) || 1; }
    function pinchMid(e) { var a = e.touches[0], b = e.touches[1]; return { x: (a.clientX + b.clientX) / 2, y: (a.clientY + b.clientY) / 2 }; }
    function ownGesture() { return STATE.drag.active || STATE.taskDrag.active || STATE.lasso.active; }
    function endAllGesturesSafe() { try { if (STATE.drag.active) endAgentDrag(); if (STATE.taskDrag.active) endTaskDrag(); if (STATE.lasso.active) endLasso(canvas); } catch (e) {} }
    function rebasePan(t) { pan = { id: t.identifier, x: t.clientX, y: t.clientY, tx: STATE.view.tx, ty: STATE.view.ty }; }
    viewport.addEventListener('touchstart', function (e) {
      if (e.touches.length === 2) {
        endAllGesturesSafe(); pan = null;
        var m0 = pinchMid(e), a0 = canvasCoords(canvas, m0.x, m0.y);
        pinch = { dist: pinchDist(e), scale: STATE.canvasScale || 1, ax: a0.x, ay: a0.y };
        try { var wEl0 = document.getElementById('wsv2-world'); if (wEl0) wEl0.style.willChange = 'transform'; } catch (_w) {}
        e.preventDefault(); return;
      }
      if (e.touches.length !== 1) { pan = null; return; }
      if (e.target.closest('.wsv2-toolbar, .wsv2-selection, .wsv2-activity, .wsv2-legend, .wsv2-zoomctl, .wsv2-cta, .wsv2-tn-close')) { pan = null; return; }
      rebasePan(e.touches[0]);
    }, { passive: false });
    viewport.addEventListener('touchmove', function (e) {
      if (pinch && e.touches.length === 2) {
        e.preventDefault();
        var m = pinchMid(e); STATE.userAdjusted = true;
        setZoomAt(pinch.scale * (pinchDist(e) / pinch.dist), pinch.ax, pinch.ay, m.x, m.y);
        return;
      }
      if (pinch || e.touches.length !== 1 || ownGesture()) return;
      var t = e.touches[0];
      if (!pan || pan.id !== t.identifier) { rebasePan(t); return; }
      e.preventDefault();
      STATE.userAdjusted = true; STATE.view.tx = pan.tx + (t.clientX - pan.x); STATE.view.ty = pan.ty + (t.clientY - pan.y); applyView();
    }, { passive: false });
    function endPinch() { pinch = null; try { var wEl = document.getElementById('wsv2-world'); if (wEl) wEl.style.willChange = ''; } catch (_w) {} }
    viewport.addEventListener('touchend', function (e) {
      if (pinch && e.touches.length < 2) endPinch();
      if (e.touches.length === 1) rebasePan(e.touches[0]); else if (e.touches.length === 0) pan = null;
    });
    viewport.addEventListener('touchcancel', function () { if (pinch) endPinch(); pan = null; });
    viewport.addEventListener('wheel', function (e) {
      e.preventDefault();
      if (e.ctrlKey || e.metaKey) { setZoom((STATE.canvasScale || 1) * (e.deltaY < 0 ? 1.1 : 1 / 1.1), e.clientX, e.clientY); return; }
      var k = e.deltaMode === 1 ? 16 : (e.deltaMode === 2 ? viewport.clientHeight : 1);
      panBy(-e.deltaX * k, -e.deltaY * k);   // the wheel pans the board (there is no scrollbar any more)
    }, { passive: false });
    document.addEventListener('mousemove', function (e) {
      if (STATE.taskDrag.active) {
        moveTaskDrag(e.clientX, e.clientY);
      } else if (STATE.drag.active) {
        moveAgentDrag(e.clientX, e.clientY);
      } else if (STATE.lasso.active) {
        var c = canvasCoords(canvas, e.clientX, e.clientY);
        var box = document.getElementById('wsv2-lasso');
        var x = Math.min(c.x, STATE.lasso.startX);
        var y = Math.min(c.y, STATE.lasso.startY);
        box.style.left = x + 'px';
        box.style.top = y + 'px';
        box.style.width = Math.abs(c.x - STATE.lasso.startX) + 'px';
        box.style.height = Math.abs(c.y - STATE.lasso.startY) + 'px';
      }
    });

    document.addEventListener('mouseup', function (e) {
      if (STATE.taskDrag.active) {
        var movedTask = STATE.taskDrag.moved;
        var taskId = STATE.taskDrag.taskId;
        endTaskDrag();
        // Click-not-drag → open task drawer if available
        if (!movedTask && taskId && typeof window.openTaskDrawer === 'function') {
          window.openTaskDrawer(parseInt(taskId, 10));
        }
        return;
      }
      if (STATE.drag.active) endAgentDrag();
      if (STATE.lasso.active) endLasso(canvas);
    });

    // Touch gesture model (v1.0.2):
    //   • Single tap on agent card     → drawer (via click after touchend)
    //   • Double-tap-and-hold on card   → drag the card to a new position
    //   • Single tap on canvas         → nothing (passes through)
    //   • Double-tap-and-hold on canvas → marquee select (lasso)
    //   • Task-node touch              → drag immediately (single touch, as before)
    //   • Pinch zoom                    → DISABLED (two-finger gestures ignored)
    canvas.addEventListener('touchstart', function (e) {
      if (e.touches.length !== 1) return;  // ignore multi-touch entirely (no pinch)
      var t = e.touches[0];
      var now = Date.now();
      var prev = STATE.tap;

      // STRAT-X-1 (Owner 2026-09-25): a touch on the card's x is a dismiss, not a drag. Before this the
      // touch started a drag (preventDefault swallowed the emulated click that the capture handler below
      // relies on) and touchend, seeing no movement, opened the drawer instead of removing the card.
      var xb = e.target.closest('.wsv2-tn-close');
      if (xb) {
        e.preventDefault(); e.stopPropagation();
        var xid = xb.getAttribute('data-dismiss-task');
        if (xid) dismissTaskNode(xid);
        STATE.tap = { lastTime: 0, lastX: 0, lastY: 0, lastSlug: null };
        return;
      }
      // Task-node: single touch still starts drag immediately (unchanged).
      var tn = e.target.closest('.wsv2-task-node');
      if (tn) {
        e.preventDefault();  // cancel the browser's pan/scroll for this gesture
        startTaskDrag(tn.dataset.taskId, tn, t.clientX, t.clientY);
        STATE.tap = { lastTime: 0, lastX: 0, lastY: 0, lastSlug: null };
        return;
      }

      // Agent card — double-tap-and-hold to begin drag.
      // Single tap is intentionally a no-op on mobile so the gesture is
      // reserved for double-tap. Drawer opens via the CTA button only.
      var node = e.target.closest('.wsv2-agent');
      if (node && !e.target.classList.contains('wsv2-cta')) {
        var slug = node.dataset.slug;
        var dt = now - prev.lastTime;
        var dx = Math.abs(t.clientX - prev.lastX);
        var dy = Math.abs(t.clientY - prev.lastY);
        if (prev.lastSlug === slug && dt < DBL_TAP_MS && dx < DBL_TAP_PX && dy < DBL_TAP_PX) {
          e.preventDefault();  // second tap → drag starts; cancel browser pan for this gesture
          startAgentDrag(node, t.clientX, t.clientY);
          STATE.tap = { lastTime: 0, lastX: 0, lastY: 0, lastSlug: null };
        } else {
          STATE.tap = { lastTime: now, lastX: t.clientX, lastY: t.clientY, lastSlug: slug };
        }
        STATE.suppressClick = true;  // emulated click after touchend must NOT open drawer
        return;
      }

      // Empty canvas (ignore overlays) — double-tap-and-hold to begin lasso.
      if (e.target.closest('.wsv2-toolbar') ||
          e.target.closest('.wsv2-selection') ||
          e.target.closest('.wsv2-activity') ||
          e.target.closest('.wsv2-legend')) {
        return;
      }
      var dt2 = now - prev.lastTime;
      var dx2 = Math.abs(t.clientX - prev.lastX);
      var dy2 = Math.abs(t.clientY - prev.lastY);
      if (prev.lastSlug === '__canvas__' && dt2 < DBL_TAP_MS && dx2 < DBL_TAP_PX && dy2 < DBL_TAP_PX) {
        e.preventDefault();  // second tap → lasso starts; cancel browser pan for this gesture
        var c = canvasCoords(canvas, t.clientX, t.clientY);
        STATE.lasso.active = true;
        STATE.lasso.startX = c.x;
        STATE.lasso.startY = c.y;
        STATE.lasso.shift = false;  // no shift on mobile — fresh select
        var box = document.getElementById('wsv2-lasso');
        if (box) {
          box.style.display = 'block';
          box.style.left = c.x + 'px';
          box.style.top = c.y + 'px';
          box.style.width = '0px';
          box.style.height = '0px';
        }
        // Mobile: freeze canvas pan/scroll while the finger draws the marquee.
        // Released in endLasso. Desktop never sets the lock, so endLasso's
        // unlock is harmless there.
        lockCanvasTouchScroll(true);
        STATE.tap = { lastTime: 0, lastX: 0, lastY: 0, lastSlug: null };
      } else {
        STATE.tap = { lastTime: now, lastX: t.clientX, lastY: t.clientY, lastSlug: '__canvas__' };
      }
    }, { passive: false });

    document.addEventListener('touchmove', function (e) {
      if (e.touches.length !== 1) return;
      // Any of our gestures active → block native pan/scroll for THIS finger.
      // touch-action CSS only affects gestures that haven't started yet;
      // preventDefault on touchmove is the only thing that stops an in-progress
      // browser scroll.
      var gestureActive = STATE.taskDrag.active || STATE.drag.active || STATE.lasso.active;
      if (gestureActive) e.preventDefault();
      var t = e.touches[0];
      if (STATE.taskDrag.active) {
        moveTaskDrag(t.clientX, t.clientY);
      } else if (STATE.drag.active) {
        moveAgentDrag(t.clientX, t.clientY);
      } else if (STATE.lasso.active) {
        var c = canvasCoords(canvas, t.clientX, t.clientY);
        var box = document.getElementById('wsv2-lasso');
        if (!box) return;
        var x = Math.min(c.x, STATE.lasso.startX);
        var y = Math.min(c.y, STATE.lasso.startY);
        box.style.left = x + 'px';
        box.style.top = y + 'px';
        box.style.width = Math.abs(c.x - STATE.lasso.startX) + 'px';
        box.style.height = Math.abs(c.y - STATE.lasso.startY) + 'px';
      }
    }, { passive: false });

    // Owner 2026-09-18 (phone): the browser CANCELS a touch it takes over — a long press on the portrait
    // starts a native image drag / context menu, a system gesture or a tab switch interrupts — and then
    // touchend never comes. Without this the drag stayed active: every later swipe moved the card instead
    // of scrolling, the canvas stayed locked (touch-action:none) and the page looked broken.
    function endAllGestures() {
      if (STATE.taskDrag.active) endTaskDrag();
      if (STATE.drag.active) endAgentDrag();
      if (STATE.lasso.active) endLasso(canvas);
      lockCanvasTouchScroll(false);
    }
    document.addEventListener('touchcancel', endAllGestures);
    window.addEventListener('blur', endAllGestures);
    document.addEventListener('visibilitychange', function () { if (document.hidden) endAllGestures(); });
    // A long press on a card must not open the browser's image/link menu mid-gesture.
    canvas.addEventListener('contextmenu', function (e) {
      if (e.target.closest('.wsv2-agent') || e.target.closest('.wsv2-task-node')) e.preventDefault();
    });

    document.addEventListener('touchend', function (e) {
      if (STATE.taskDrag.active) {
        var movedTask = STATE.taskDrag.moved;
        var taskId = STATE.taskDrag.taskId;
        endTaskDrag();
        if (!movedTask && taskId && typeof window.openTaskDrawer === 'function') {
          window.openTaskDrawer(parseInt(taskId, 10));
        }
      }
      if (STATE.drag.active) endAgentDrag();
      if (STATE.lasso.active) endLasso(canvas);
    });

    // Block desktop browser zoom (ctrl/cmd + wheel) inside the workspace canvas.
    // Browser keyboard zoom (ctrl + / - / 0) is system-level and cannot be
    // intercepted reliably — those shortcuts still work and are out of scope.
    canvas.addEventListener('wheel', function (e) {
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
      }
    }, { passive: false });

    // Delegated click handler for the ✕ dismiss button on task-node cards.
    // Lives on the canvas (since task-nodes are children of #wsv2-agents
    // which is inside canvas). e.stopPropagation prevents click→drawer-open.
    canvas.addEventListener('click', function (e) {
      var btn = e.target.closest('.wsv2-tn-close');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      var id = btn.getAttribute('data-dismiss-task');
      if (id) dismissTaskNode(id);
    }, true); // capture phase so we beat the bubble click on task-node

    // Single delegated click handler for the whole agents host. Handles BOTH
    // the CTA button and the rest-of-card click (both open the drawer).
    agentsHost.addEventListener('click', function (e) {
      var cta = e.target.closest('.wsv2-cta');
      if (cta) {
        e.stopPropagation();
        var slugC = cta.getAttribute('data-slug');
        console.log('[wsv2] CTA clicked for', slugC);
        wsv2_openDrawer(slugC);
        return;
      }
      var node = e.target.closest('.wsv2-agent');
      if (!node) return;
      // Mobile: any touch interaction on a card sets suppressClick. The
      // emulated click that fires after touchend must NOT open the drawer —
      // drawer-open on mobile is reserved for the CTA button, double-tap
      // is reserved for drag. Desktop never sets this flag, so behaviour
      // there is unchanged.
      if (STATE.suppressClick) { STATE.suppressClick = false; return; }
      if (STATE.drag.moved) { STATE.drag.moved = false; return; }
      if (e.shiftKey || e.metaKey || e.ctrlKey) {
        toggleSelect(node.dataset.slug);
      } else {
        console.log('[wsv2] agent card clicked for', node.dataset.slug);
        wsv2_openDrawer(node.dataset.slug);
      }
    });

    // Resize → re-render lines + zones
    window.addEventListener('resize', function () {
      requestAnimationFrame(function () { renderLines(); renderZones(); });
    });

    // After initial layout, center viewport on Sarah
    setTimeout(centerOnCenter, 200);
  }

  function startAgentDrag(node, cx, cy) {
    var slug = node.dataset.slug;
    var pos = STATE.overrides[slug] || STATE.positions[slug] || { x: 0, y: 0 };
    STATE.drag.active = true;
    STATE.drag.slug = slug;
    STATE.drag.startX = cx;
    STATE.drag.startY = cy;
    STATE.drag.origX = pos.x;
    STATE.drag.origY = pos.y;
    STATE.drag.moved = false;
    node.classList.add('dragging');
    lockCanvasTouchScroll(true);
  }

  function moveAgentDrag(cx, cy) {
    if (!STATE.drag.active) return;
    var canvas = document.getElementById('wsv2-canvas');
    var scale = STATE.canvasScale || 1;
    var dx = (cx - STATE.drag.startX) / scale;
    var dy = (cy - STATE.drag.startY) / scale;
    if (Math.abs(dx) > 3 || Math.abs(dy) > 3) STATE.drag.moved = true;
    var nx = STATE.drag.origX + dx, ny = STATE.drag.origY + dy;   // WS-CANVAS-1: any direction; the world grows around the card
    STATE.overrides[STATE.drag.slug] = { x: nx, y: ny };
    scheduleFit();
    var el = document.querySelector('.wsv2-agent[data-slug="' + STATE.drag.slug + '"]');
    if (el) { el.style.left = nx + 'px'; el.style.top = ny + 'px'; }
    requestAnimationFrame(renderLines);
  }

  function endAgentDrag() {
    if (!STATE.drag.active) return;
    var slug = STATE.drag.slug;
    var el = document.querySelector('.wsv2-agent[data-slug="' + slug + '"]');
    if (el) el.classList.remove('dragging');
    var pos = STATE.overrides[slug];
    STATE.drag.active = false;
    if (pos && STATE.drag.moved) persistPosition(slug, pos);
    STATE.drag.slug = null;
    lockCanvasTouchScroll(false);
    requestAnimationFrame(function () { renderLines(); renderZones(); });
  }

  function endLasso(canvas) {
    var box = document.getElementById('wsv2-lasso');
    var br = box.getBoundingClientRect();
    var lassoLeft = parseInt(box.style.left, 10);
    var lassoTop = parseInt(box.style.top, 10);
    var lassoW = parseInt(box.style.width, 10);
    var lassoH = parseInt(box.style.height, 10);
    box.style.display = 'none';
    STATE.lasso.active = false;
    lockCanvasTouchScroll(false);  // release the touch lock applied at lasso start (mobile path)
    var moved = (br.width > 4 || br.height > 4);
    if (!moved) {
      if (!STATE.lasso.shift) clearSelection();
      return;
    }
    if (!STATE.lasso.shift) STATE.selected.clear();
    // AABB intersection — select any agent whose card overlaps the lasso,
    // even by a single pixel. Previously used a center-point check, which
    // missed cards that the rectangle only grazed.
    var lx2 = lassoLeft + lassoW;
    var ly2 = lassoTop + lassoH;
    STATE.agents.forEach(function (a) {
      var pos = STATE.overrides[a.slug] || STATE.positions[a.slug];
      if (!pos) return;
      var ax1 = pos.x;
      var ay1 = pos.y;
      var ax2 = pos.x + CARD_W;
      var ay2 = pos.y + CARD_H;
      // Rectangles overlap iff none of A is strictly outside any side of L.
      if (ax1 < lx2 && ax2 > lassoLeft && ay1 < ly2 && ay2 > lassoTop) {
        STATE.selected.add(a.slug);
      }
    });
    renderAgents();
    updateSelectionUI();
  }

  // WS-CANVAS-10: the world is translate(tx,ty) scale(s) inside a clipped viewport. World coordinates come from the
  // world's own transform; nothing scrolls, so a gesture's last frame is its final state.
  function canvasCoords(canvas, clientX, clientY) {
    var world = document.getElementById('wsv2-world'), sc = STATE.canvasScale || 1;
    var r = world ? world.getBoundingClientRect() : canvas.getBoundingClientRect();
    return { x: (clientX - r.left) / sc, y: (clientY - r.top) / sc };
  }
  function applyView() {
    var world = document.getElementById('wsv2-world'); if (!world) return;
    var v = STATE.view || (STATE.view = { tx: 0, ty: 0 }), sc = STATE.canvasScale || 1;
    world.style.transform = 'translate(' + v.tx + 'px,' + v.ty + 'px) scale(' + sc + ')';
    var lbl = document.getElementById('wsv2-zoom-pct'); if (lbl) lbl.textContent = Math.round(sc * 100) + '%';
  }
  function fitCanvas() {   // the world's own box only bounds the SVG lines and the dot grid; cards may sit anywhere
    var world = document.getElementById('wsv2-world'), svg = document.getElementById('wsv2-svg'); if (!world) return;
    var maxX = CANVAS_W, maxY = CANVAS_H;
    var add = function (p, w, h) { if (!p) return; maxX = Math.max(maxX, p.x + w + WORLD_MARGIN); maxY = Math.max(maxY, p.y + h + WORLD_MARGIN); };
    STATE.agents.forEach(function (ag) { add(STATE.overrides[ag.slug] || STATE.positions[ag.slug], CARD_W, CARD_H); });
    Object.keys(STATE.taskNodePos).forEach(function (k) { add(STATE.taskNodePos[k], TN_W, TN_H); });
    Object.keys(STATE.taskRenderPos || {}).forEach(function (k) { add(STATE.taskRenderPos[k], TN_W, TN_H); });   // WS-TASKS-1
    STATE.world.minX = 0; STATE.world.minY = 0; STATE.world.w = maxX; STATE.world.h = maxY;
    world.style.width = maxX + 'px'; world.style.height = maxY + 'px';
    if (svg) { svg.setAttribute('width', String(maxX)); svg.setAttribute('height', String(maxY)); svg.setAttribute('viewBox', '0 0 ' + maxX + ' ' + maxY); }
    applyView();
  }
  var fitRaf = 0; function scheduleFit() { if (fitRaf) return; fitRaf = requestAnimationFrame(function () { fitRaf = 0; fitCanvas(); }); }

  // zoom so that WORLD point (wx,wy) sits under SCREEN point (fx,fy)
  function setZoomAt(next, wx, wy, fx, fy) {
    var canvas = document.getElementById('wsv2-canvas'); if (!canvas) return;
    next = Math.max(0.15, Math.min(2.5, next));   // WS-FIT-1: a phone needs to go small enough to hold the whole team
    var cr = canvas.getBoundingClientRect();
    STATE.canvasScale = next;
    STATE.view.tx = (fx - cr.left) - wx * next; STATE.view.ty = (fy - cr.top) - wy * next;
    applyView();
  }
  function setZoom(next, fx, fy) {   // around a screen point, default the centre of the viewport
    var viewport = document.getElementById('wsv2-viewport'), canvas = document.getElementById('wsv2-canvas'); if (!viewport || !canvas) return;
    var vr = viewport.getBoundingClientRect();
    if (fx == null || fy == null) { fx = vr.left + viewport.clientWidth / 2; fy = vr.top + viewport.clientHeight / 2; }
    var w = canvasCoords(canvas, fx, fy);
    setZoomAt(next, w.x, w.y, fx, fy);
  }
  function setZoomAnchored(next, ax, ay, fx, fy) { setZoomAt(next, ax, ay, fx, fy); }
  function panBy(dx, dy) { STATE.userAdjusted = true; STATE.view.tx += dx; STATE.view.ty += dy; applyView(); }
  window.addEventListener('resize', function () { clearTimeout(STATE.fitTimer); STATE.fitTimer = setTimeout(function () { if (!STATE.userAdjusted && document.getElementById('wsv2-viewport')) fitAll(); }, 150); });   // WS-FIT-1: an orientation change refits until the user takes over
  window.wsv2_fitCanvas = fitCanvas;
  window.wsv2_zoom = function (dir) { STATE.userAdjusted = true; setZoom((STATE.canvasScale || 1) * (dir > 0 ? 1.2 : 1 / 1.2)); };
  window.wsv2_zoomReset = function () { setZoom(1); };

  // WS-FIT-1: every agent card (and task card) inside the viewport, centred, never above 100 %; the legend and the
  // toolbar are kept clear. Runs on the first render, on Center, after Reset Layout and on a resize until the user
  // pans or zooms themselves.
  function fitAll() {
    var viewport = document.getElementById('wsv2-viewport'); if (!viewport) return;
    var vw = viewport.clientWidth, vh = viewport.clientHeight; if (!vw || !vh) return;
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    var add = function (p, w, h) { if (!p) return; minX = Math.min(minX, p.x); minY = Math.min(minY, p.y); maxX = Math.max(maxX, p.x + w); maxY = Math.max(maxY, p.y + h); };
    STATE.agents.forEach(function (a) { add(STATE.overrides[a.slug] || STATE.positions[a.slug], CARD_W, CARD_H); });
    Object.keys(STATE.taskRenderPos || {}).forEach(function (k) { add(STATE.taskRenderPos[k], TN_W, TN_H); });
    if (!isFinite(minX)) { centerOnCenter(); return; }
    var mobile = vw < 768, legend = document.querySelector('.wsv2-legend'), legendW = (!mobile && legend && legend.offsetParent) ? legend.offsetWidth + 24 : 0;
    var pad = { t: mobile ? 64 : 72, r: mobile ? 16 : 32, b: mobile ? 72 : 32, l: (mobile ? 16 : 32) + legendW };
    var bw = Math.max(1, maxX - minX), bh = Math.max(1, maxY - minY);
    var s = Math.max(0.15, Math.min(1, (vw - pad.l - pad.r) / bw, (vh - pad.t - pad.b) / bh));
    STATE.canvasScale = s;
    STATE.view.tx = pad.l + ((vw - pad.l - pad.r) - bw * s) / 2 - minX * s;
    STATE.view.ty = pad.t + ((vh - pad.t - pad.b) - bh * s) / 2 - minY * s;
    applyView();
  }
  window.wsv2_fitAll = fitAll;
  function centerOnCenter() {   // the layout's centre in the middle of the viewport, at the current zoom
    var viewport = document.getElementById('wsv2-viewport'); if (!viewport) return;
    var sc = STATE.canvasScale || 1;
    STATE.view.tx = viewport.clientWidth / 2 - (CANVAS_W / 2) * sc; STATE.view.ty = viewport.clientHeight / 2 - (CANVAS_H / 2) * sc;
    applyView();
  }

  // ── Selection ────────────────────────────────────────────────────────────
  function toggleSelect(slug) {
    if (STATE.selected.has(slug)) STATE.selected.delete(slug);
    else STATE.selected.add(slug);
    renderAgents();
    updateSelectionUI();
  }
  function clearSelection() {
    STATE.selected.clear();
    renderAgents();
    updateSelectionUI();
  }
  function updateSelectionUI() {
    var panel = document.getElementById('wsv2-selection');
    var label = document.getElementById('wsv2-sel-count');
    if (!panel) return;
    var n = STATE.selected.size;
    panel.style.display = n > 0 ? 'flex' : 'none';
    if (label) label.textContent = n + ' agent' + (n === 1 ? '' : 's') + ' selected';
  }

  // ── Persistence ──────────────────────────────────────────────────────────
  function persistPosition(slug, pos) {
    fetch('/api/workspace/agents/positions', {
      method: 'PUT',
      headers: {
        'Authorization': 'Bearer ' + getToken(),
        'X-Workspace-Id': String(getWorkspaceId()),
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ agent: slug, x: Math.round(pos.x), y: Math.round(pos.y) }),
    }).catch(function (e) { console.warn('[wsv2] position save failed', e); });
  }

  // ── Sync v2 agent data into globals used by v1 drawer + assign modal ────
  // The drawer (`openAgentDrawer`) reads `AGENTS[id]`, and the assign modal
  // (`openDirectAssign`) reads `selectedAgents` Set + `AGENTS[id]` for chips.
  // Both globals are populated by v1's IIFE — but v2 bypasses that IIFE, so
  // we mirror the structure here per fetch so the drawer + assign work.
  function syncGlobalAgentsMap() {
    if (!window.AGENTS) window.AGENTS = {};
    if (!window._agentStatsData) window._agentStatsData = {};
    var ORB = window.OrbAvatar || {};
    var MAP = ORB.AGENT_ORB_MAP || {};
    var CFG = ORB.ORB_CONFIG || {};
    STATE.agents.forEach(function (a) {
      var roleColor = (CFG[MAP[a.slug] || 'dmm'] || {}).color || 'var(--p)';
      if (!window.AGENTS[a.slug]) {
        window.AGENTS[a.slug] = {
          name: a.name || a.slug,
          role: a.title || 'Specialist',
          title: a.title || 'Specialist',  // task-drawer reads .title; agent-drawer reads .role
          emoji: '👤',
          color: roleColor,
          expertise: [],
        };
      } else {
        // Fill missing fields on existing entries (v1's hardcoded AGENTS has
        // .role but not .title; the task-drawer reads .title and prints
        // 'undefined' otherwise).
        if (!window.AGENTS[a.slug].title) window.AGENTS[a.slug].title = window.AGENTS[a.slug].role || a.title || 'Specialist';
      }
      // Mirror correct multi-assignee counts into _agentStatsData so the
      // drawer's counter strip (which reads from this global, populated by
      // v1's loadAgentStats from the buggy /api/agents/dashboard) shows the
      // SAME numbers as the v2 card. Sarah is keyed as 'dmm' in v1.
      var key = (a.slug === 'sarah') ? 'dmm' : a.slug;
      var s = a.stats || {};
      window._agentStatsData[key] = {
        ongoing:      s.ongoing   | 0,
        upcoming:     s.upcoming  | 0,
        blocked:      s.blocked   | 0,
        completed:    s.completed | 0,
        failed:       s.failed    | 0,
        success_rate: (window._agentStatsData[key] && window._agentStatsData[key].success_rate) || 0,
      };
    });
    // If the drawer is open, re-render its counter strip with the fresh data.
    if (window._agentDrawerOpen && typeof window.renderDrawerTasks === 'function') {
      try { window.renderDrawerTasks(window._agentDrawerOpen, window._drawerTaskFilter || 'all'); } catch (e) {}
    }
    if (window._agentDrawerOpen && typeof window.renderProfilePane === 'function') {
      try { window.renderProfilePane(window._agentDrawerOpen, window.AGENTS[window._agentDrawerOpen] || {}); } catch (e) {}
    }
  }

  // ── Toolbar / window-exposed ─────────────────────────────────────────────
  window.wsv2_openDrawer = function (slug) {
    console.log('[wsv2_openDrawer] called for', slug,
      '— openAgentDrawer typeof:', typeof window.openAgentDrawer,
      '— drawer-bg exists:', !!document.getElementById('drawer-bg'),
      '— agent-drawer exists:', !!document.getElementById('agent-drawer'));
    if (typeof window.openAgentDrawer !== 'function') {
      console.warn('[wsv2] openAgentDrawer not loaded — drawer cannot open');
      return;
    }
    // v1 stores Sarah under the 'dmm' key in BOTH AGENTS map (hardcoded at
    // core.js:707) and _agentStatsData (loadAgentStats remaps sarah→dmm at
    // core.js:2305). Passing 'sarah' to openAgentDrawer would read
    // _agentStatsData['sarah'] which is undefined → drawer shows zeros.
    // ALWAYS map for the drawer; the backend slug stays 'sarah' for tasks.
    var drawerKey = (slug === 'sarah') ? 'dmm' : slug;
    try {
      window.openAgentDrawer(drawerKey);
    } catch (err) {
      console.error('[wsv2] openAgentDrawer threw:', err);
    }
  };
  window.wsv2_resetLayout = function () {
    STATE.overrides = {};
    STATE.positions = {};
    STATE.taskNodePos = {};
    STATE.padExtra = { l: 0, t: 0, r: 0, b: 0 };   // WS-CANVAS-8
    STATE.initialLayoutDone = false;
    computeInitialLayout();   // every agent, including ones added since the last layout
    STATE.initialLayoutDone = true;
    // WS-CANVAS-1: the reset is the default view again after a reload too - clear the saved positions
    try { fetch('/api/workspace/agents/positions', { method: 'DELETE', headers: { 'Authorization': 'Bearer ' + getToken(), 'X-Workspace-Id': String(getWorkspaceId()) } }).catch(function () {}); } catch (e) {}
    render();
    STATE.userAdjusted = false; STATE.fittedOnce = true;
    setTimeout(fitAll, 100);   // WS-FIT-1: the default view after a reset holds the whole team
  };
  window.wsv2_fitToScreen = function () {
    STATE.userAdjusted = false; fitAll();   // WS-FIT-1: Center = the whole team on one screen
  };
  window.wsv2_toggleActivity = function () {
    var p = document.getElementById('wsv2-activity');
    if (p) p.classList.toggle('open');
  };
  // ── Action mapping: slug → real engine/action with required param ────────
  // v1's createDirectTask hardcoded action='manual_brief' which has no
  // registered capability → Orchestrator throws and task fails. We map each
  // agent slug to a real, dispatchable action so the task actually fires.
  var ACTION_FOR_SLUG = {
    // SEO
    james:  { engine: 'seo',    action: 'deep_audit',     paramKey: 'url',   paramLabel: 'Target URL',   paramHint: 'https://example.com/page-to-audit' },
    diana:  { engine: 'seo',    action: 'deep_audit',     paramKey: 'url',   paramLabel: 'Target URL',   paramHint: 'https://example.com/page-to-audit' },
    sofia:  { engine: 'seo',    action: 'deep_audit',     paramKey: 'url',   paramLabel: 'Target URL',   paramHint: 'https://example.com/page-to-audit' },
    alex:   { engine: 'seo',    action: 'deep_audit',     paramKey: 'url',   paramLabel: 'Target URL',   paramHint: 'https://example.com/page-to-audit' },
    ryan:   { engine: 'seo',    action: 'deep_audit',     paramKey: 'url',   paramLabel: 'Target URL',   paramHint: 'https://example.com/page-to-audit' },
    // Content / Write
    priya:  { engine: 'write',  action: 'write_article',  paramKey: 'topic', paramLabel: 'Topic / keyword', paramHint: 'e.g. best SEO tools for SMBs' },
    nora:   { engine: 'write',  action: 'write_article',  paramKey: 'topic', paramLabel: 'Topic / keyword', paramHint: 'e.g. content strategy tips' },
    max:    { engine: 'write',  action: 'write_article',  paramKey: 'topic', paramLabel: 'Topic / keyword', paramHint: 'e.g. brand voice guide' },
    // Social
    // CRM (list_leads needs no params)
    elena:  { engine: 'crm',    action: 'list_leads',     paramKey: null,    paramLabel: null,           paramHint: null },
    sam:    { engine: 'crm',    action: 'list_leads',     paramKey: null,    paramLabel: null,           paramHint: null },
    // Ads / Marketing
  };

  function resolveAction(slugs) {
    var firstSpec = slugs.find(function (s) { return s !== 'sarah'; }) || slugs[0];
    return ACTION_FOR_SLUG[firstSpec]
      || { engine: 'write', action: 'write_article', paramKey: 'topic', paramLabel: 'Topic', paramHint: 'e.g. SEO basics for SMBs' };
  }

  // v1's openDirectAssign() reads from the GLOBAL `selectedAgents` Set + the
  // GLOBAL `AGENTS` map. We mirror our v2 selection into both before calling
  // so chips render. Then we inject a URL/Topic field and rewire the Create
  // button to a v2 path that sends a REAL dispatchable action.
  window.wsv2_assignTask = function () {
    if (!STATE.selected.size) return;
    syncGlobalAgentsMap();
    if (!window.selectedAgents) window.selectedAgents = new Set();
    window.selectedAgents.clear();
    STATE.selected.forEach(function (s) { window.selectedAgents.add(s); });
    if (typeof window.openDirectAssign === 'function') {
      window.openDirectAssign();
      // Inject after a tick so the modal layout is settled.
      setTimeout(injectV2ModalFields, 30);
    } else {
      showToast('Assign modal not loaded — please refresh the page.', 'error');
    }
  };

  function injectV2ModalFields() {
    var modal = document.querySelector('#da-backdrop .da-modal');
    if (!modal) return;
    var slugs = Array.from(STATE.selected);
    var mapping = resolveAction(slugs);

    // Update subtitle to show what will fire.
    var sub = document.getElementById('da-sub');
    if (sub) {
      sub.innerHTML = 'Action: <code style="background:var(--s3);padding:1px 5px;border-radius:4px;color:var(--t1);font-family:var(--fh);font-size:11px">' + escapeHtml(mapping.engine + '/' + mapping.action) + '</code> — will execute immediately.';
    }

    // Inject or refresh the param field (URL / Topic / Campaign name).
    var existing = document.getElementById('wsv2-param-wrap');
    if (existing) existing.remove();
    if (mapping.paramKey) {
      var wrap = document.createElement('div');
      wrap.className = 'da-field';
      wrap.id = 'wsv2-param-wrap';
      wrap.innerHTML = '<label>' + escapeHtml(mapping.paramLabel) + ' <span style="color:var(--rd)">*</span></label>'
        + '<input type="text" id="wsv2-param-input" placeholder="' + escapeHtml(mapping.paramHint || '') + '" style="width:100%;padding:8px 10px;background:var(--s3);border:1px solid var(--bd);border-radius:6px;color:var(--t1);font-family:var(--fb);font-size:13px">';
      var titleField = document.getElementById('da-title');
      var titleWrap = titleField ? titleField.closest('.da-field') : null;
      if (titleWrap && titleWrap.parentNode) {
        titleWrap.parentNode.insertBefore(wrap, titleWrap.nextSibling);
      }
    }

    // Rewire the Create button onclick — clobber v1's createDirectTask call.
    var createBtn = modal.querySelector('.da-btn-create');
    if (createBtn) {
      createBtn.onclick = function (ev) {
        ev.preventDefault();
        wsv2_createDirectTask(mapping);
      };
    }
  }

  window.wsv2_createDirectTask = async function (mapping) {
    var title    = (document.getElementById('da-title')  || {}).value || '';
    var desc     = (document.getElementById('da-desc')   || {}).value || '';
    var priority = (document.getElementById('da-priority') || {}).value || 'normal';
    var time     = parseInt((document.getElementById('da-time') || {}).value, 10) || 60;
    var metric   = (document.getElementById('da-metric') || {}).value || '';
    var paramVal = mapping.paramKey ? ((document.getElementById('wsv2-param-input') || {}).value || '').trim() : '';
    title = String(title).trim();
    if (!title) { toast('Task title required', 'warning'); return; }
    if (mapping.paramKey && !paramVal) { toast(mapping.paramLabel + ' is required', 'warning'); return; }
    if (!window.selectedAgents || !window.selectedAgents.size) { toast('Select agents first', 'warning'); return; }

    var assignees = Array.from(window.selectedAgents).map(function (s) { return s === 'dmm' ? 'sarah' : s; });
    var payload = {
      title: title,
      description: String(desc).trim(),
      priority: priority,
      estimated_time: time,
      success_metric: String(metric).trim(),
      created_via: 'wsv2_direct_assign',
      client_ts: Date.now(),
      nonce: Math.random().toString(36).slice(2, 10),
    };
    if (mapping.paramKey) payload[mapping.paramKey] = paramVal;

    var btn = document.querySelector('#da-backdrop .da-btn-create');
    if (btn) { btn.disabled = true; btn.textContent = 'Creating…'; }

    try {
      var resp = await fetch('/api/tasks', {
        method: 'POST',
        headers: {
          'Authorization': 'Bearer ' + getToken(),
          'X-Workspace-Id': String(getWorkspaceId()),
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          engine: mapping.engine,
          action: mapping.action,
          source: 'manual',
          assigned_agents: assignees,
          priority: priority === 'medium' ? 'normal' : priority,
          requires_approval: false,
          credit_cost: 0,
          payload: payload,
        }),
      });
      var data = null;
      try { data = await resp.json(); } catch (e) {}
      if (resp.ok && data && (data.task || data.id)) {
        toast('Task created: ' + title + ' → ' + mapping.engine + '/' + mapping.action, 'success');
        if (typeof window.closeDirectAssign === 'function') window.closeDirectAssign();
        clearSelection();
        refresh();
      } else if (resp.status === 409 && data && data.task) {
        toast('Identical task already exists (#' + data.task.id + ').', 'warning');
        if (typeof window.closeDirectAssign === 'function') window.closeDirectAssign();
      } else {
        throw new Error((data && (data.message || data.error)) || ('HTTP ' + resp.status));
      }
    } catch (err) {
      console.error('[wsv2] createDirectTask failed:', err);
      toast('Task creation failed: ' + (err && err.message || err), 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Create Task →'; }
    }
  };

  function toast(msg, kind) {
    if (typeof window.showToast === 'function') window.showToast(msg, kind || 'info');
    else console.log('[wsv2 toast]', kind, msg);
  }

  // ── Detect task status transitions for toast + visual flair ──────────────
  var TERMINAL_STATUSES = ['completed', 'failed', 'cancelled', 'degraded'];
  function detectStatusTransitions(prevById) {
    STATE.tasks.forEach(function (t) {
      var prev = prevById[t.id];
      if (!prev) return;
      if (prev === t.status) return;
      // Just completed
      if (t.status === 'completed' && prev !== 'completed') {
        toast('✓ Task completed: ' + (t.action ? humanize(t.action) : ('#' + t.id)), 'success');
        STATE.recentlyCompleted = STATE.recentlyCompleted || {};
        STATE.recentlyCompleted[t.id] = Date.now();
        // Auto-refresh task drawer if it's showing this task
        if (window.activeTaskDrawer === t.id && typeof window.openTaskDrawer === 'function') {
          window.openTaskDrawer(t.id);
        }
      }
      // Just failed
      if (t.status === 'failed' && prev !== 'failed') {
        toast('✗ Task failed: ' + (t.action ? humanize(t.action) : ('#' + t.id)), 'error');
        if (window.activeTaskDrawer === t.id && typeof window.openTaskDrawer === 'function') {
          window.openTaskDrawer(t.id);
        }
      }
      // Started running
      if (t.status === 'running' && prev !== 'running') {
        if (window.activeTaskDrawer === t.id && typeof window.openTaskDrawer === 'function') {
          window.openTaskDrawer(t.id);
        }
      }
    });
  }
  window.wsv2_startMeeting = function () {
    if (!STATE.selected.size) return;
    syncGlobalAgentsMap();
    if (!window.selectedAgents) window.selectedAgents = new Set();
    window.selectedAgents.clear();
    STATE.selected.forEach(function (s) { window.selectedAgents.add(s); });
    if (typeof window.startMeetingWithSelected === 'function') {
      window.startMeetingWithSelected();
    } else if (typeof window.nav === 'function') {
      window.nav('meeting');
    }
  };
  window.wsv2_analyzeWorkload = function () {
    if (!STATE.selected.size) return;
    syncGlobalAgentsMap();
    if (!window.selectedAgents) window.selectedAgents = new Set();
    window.selectedAgents.clear();
    STATE.selected.forEach(function (s) { window.selectedAgents.add(s); });
    if (typeof window.analyzeWorkload === 'function') window.analyzeWorkload();
  };
  window.wsv2_clearSelection = function () { clearSelection(); };

  // ── Helpers ──────────────────────────────────────────────────────────────
  function zoneOf(a) {
    if (a.is_orchestrator) return 'leadership';
    if (ZONE_OF[a.slug]) return ZONE_OF[a.slug];
    var t = String(a.title || a.category || '').toLowerCase();
    if (t.indexOf('seo') >= 0 || t.indexOf('content') >= 0 || t.indexOf('social') >= 0 || t.indexOf('analytics') >= 0 || t.indexOf('design') >= 0 || t.indexOf('research') >= 0 || t.indexOf('pr') >= 0) return 'strategy';
    return 'execution';
  }

  function roleColorOf(slug) {
    var ORB = window.OrbAvatar || {};
    var MAP = ORB.AGENT_ORB_MAP || {};
    var CFG = ORB.ORB_CONFIG || {};
    return (CFG[MAP[slug] || 'dmm'] || {}).color || '#6C5CE7';
  }

  function hexA(hex, alpha) {
    var h = String(hex || '').replace('#', '');
    if (h.length !== 6) return 'rgba(108,92,231,' + alpha + ')';
    var r = parseInt(h.substring(0, 2), 16);
    var g = parseInt(h.substring(2, 4), 16);
    var b = parseInt(h.substring(4, 6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  function initialOf(slug) {
    return String(slug || '?').charAt(0).toUpperCase();
  }

  function humanize(action) {
    return String(action || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  function truncate(s, n) {
    s = String(s || '');
    return s.length > n ? s.substring(0, n - 1) + '…' : s;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }

  function getToken() {
    try { return localStorage.getItem('lu_token') || ''; } catch (e) { return ''; }
  }
  function getWorkspaceId() {
    try { return Number(localStorage.getItem('lu_workspace_id') || 1); } catch (e) { return 1; }
  }

  // ── Auto-init when view becomes active ───────────────────────────────────
  function watchActivation() {
    var target = document.getElementById('view-workspace');
    if (!target) { setTimeout(watchActivation, 500); return; }
    if (target.classList.contains('active')) window.initWorkspaceV2();
    var mo = new MutationObserver(function () {
      if (target.classList.contains('active')) window.initWorkspaceV2();
    });
    mo.observe(target, { attributes: true, attributeFilter: ['class'] });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', watchActivation);
  } else {
    watchActivation();
  }

  /* ── WS-CAMPAIGNS-1 (Owner 2026-09-28: "introduce campaigns card in the workspace") ─────────────────────────
     Sarah's plans on the canvas, in the agent-card language: how many are live, ideas waiting for the owner, what
     finished, the live ones with their progress, and the next dated step. It sits beside Sarah until the owner moves
     it (remembered per workspace), refreshes every minute, and opens Campaigns. */
  var CAMP = { data: null, at: 0, loading: false };
  var CAMP_W = 232;
  function campPosKey() { return 'wsv2_camp_pos_' + getWorkspaceId(); }
  function campLoadPos() { try { return JSON.parse(localStorage.getItem(campPosKey()) || 'null'); } catch (e) { return null; } }
  function campSavePos(pos) { try { localStorage.setItem(campPosKey(), JSON.stringify(pos)); } catch (e) {} }
  function campEsc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function campFetch() {
    if (CAMP.loading) return; CAMP.loading = true;
    fetch((window.LU_API_BASE || '/api') + '/growth/campaigns', { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('lu_token') || ''), 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { CAMP.loading = false; if (j && j.success !== false) { CAMP.data = j; CAMP.at = Date.now(); campCard(true); } })
      .catch(function () { CAMP.loading = false; });
  }
  function campWhen(iso) {
    if (!iso) return '';
    var d = new Date(iso); if (isNaN(d)) return '';
    return d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
  }
  function campCard(fresh) {
    var world = document.getElementById('wsv2-world'); if (!world) return;
    if (!fresh && (!CAMP.data || Date.now() - CAMP.at > 60000)) campFetch();
    var el = document.getElementById('wsv2-camp');
    if (!el) {
      el = document.createElement('div'); el.id = 'wsv2-camp'; el.className = 'wsv2-camp'; el.setAttribute('role', 'region'); el.setAttribute('aria-label', 'Campaigns');   /* own class: selection / lasso / agent drag never treat it as an agent */
      world.appendChild(el); campDrag(el);
    }
    // position: where the owner left it, else beside Sarah
    var pos = campLoadPos();
    if (!pos) {
      var sp = STATE.overrides.sarah || STATE.positions.sarah;
      /* first free spot around Sarah that does not cover an agent card (the card is about 300px tall) */
      var cards = STATE.agents.map(function (a) { var q = STATE.overrides[a.slug] || STATE.positions[a.slug]; return q ? { x: q.x, y: q.y, w: CARD_W, h: CARD_H } : null; }).filter(Boolean);
      var H = 310, cand = sp ? [[-CAMP_W - 60, -H - 20], [CARD_W + 60, -H - 20], [-CAMP_W - 60, -20], [CARD_W + 60, -20], [-CAMP_W - 60, CARD_H + 40], [CARD_W + 60, CARD_H + 40], [-(CAMP_W - CARD_W) / 2, -H - 40]] : [];
      pos = null;
      for (var ci = 0; ci < cand.length && !pos; ci++) { var r = { x: sp.x + cand[ci][0], y: sp.y + cand[ci][1], w: CAMP_W, h: H }; if (r.x < 10 || r.y < 10) continue; if (!cards.some(function (c) { return rectsOverlap(r, c, 16); })) pos = { x: r.x, y: r.y }; }
      if (!pos) pos = sp ? { x: Math.max(20, sp.x - CAMP_W - 60), y: Math.max(20, sp.y - H - 20) } : { x: CANVAS_W / 2 - CAMP_W - 200, y: CANVAS_H / 2 - 120 };
    }
    el.style.left = pos.x + 'px'; el.style.top = pos.y + 'px';
    var d = CAMP.data || {}, list = Array.isArray(d.campaigns) ? d.campaigns : [];
    var live = list.filter(function (c) { return c.status === 'active' || c.status === 'live' || c.status === 'running'; });
    var ideas = typeof d.ideas_pending === 'number' ? d.ideas_pending : list.filter(function (c) { return c.status === 'idea'; }).length;
    var done = list.filter(function (c) { return c.status === 'completed' || c.status === 'done' || c.status === 'finished'; }).length;
    var next = live.map(function (c) { return c.next_step && c.next_step.at ? { t: c.next_step.title, at: c.next_step.at, c: c.title } : null; }).filter(Boolean)
      .sort(function (a, b) { return new Date(a.at) - new Date(b.at); })[0];
    var sig = JSON.stringify([live.map(function (c) { return [c.id, c.steps_done, c.steps_total]; }), ideas, done, next && next.at, !!CAMP.data]);
    if (el.__sig === sig) return; el.__sig = sig;
    var ICON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/></svg>';
    var liveHtml = live.slice(0, 2).map(function (c) {
      var tot = +c.steps_total || 0, dn = +c.steps_done || 0, pct = tot ? Math.round(dn * 100 / tot) : 0;
      return '<div class="wsv2-camp-item"><div class="wsv2-camp-t" title="' + campEsc(c.title) + '">' + campEsc(c.title) + '</div>' +
        '<div class="wsv2-camp-m">' + campEsc(c.business_name || '') + (tot ? ' · ' + dn + '/' + tot + ' steps' : '') + '</div>' +
        '<div class="wsv2-bar"><div class="wsv2-bar-fill" style="width:' + pct + '%;background:#3DD9B0"></div></div></div>';
    }).join('') + (live.length > 2 ? '<div class="wsv2-camp-m">+ ' + (live.length - 2) + ' more live</div>' : '');
    el.innerHTML =
      '<div class="wsv2-agent-head"><span class="wsv2-camp-ic">' + ICON + '</span><div class="wsv2-agent-info"><div class="wsv2-name">Campaigns</div><div class="wsv2-title">Sarah’s plans</div></div></div>' +
      (!CAMP.data ? '<div class="wsv2-camp-m">Loading…</div>' :
      '<div class="wsv2-stats">' +
        '<div class="wsv2-stat-row wsv2-stat ongoing"><span class="wsv2-stat-lbl">Live</span><span class="wsv2-stat-val">' + live.length + '</span></div>' +
        '<div class="wsv2-stat-row wsv2-stat upcoming"><span class="wsv2-stat-lbl">Ideas waiting</span><span class="wsv2-stat-val">' + ideas + '</span></div>' +
        '<div class="wsv2-stat-row wsv2-stat"><span class="wsv2-stat-lbl">Finished</span><span class="wsv2-stat-val">' + done + '</span></div>' +
      '</div>' +
      (liveHtml ? '<div class="wsv2-camp-list">' + liveHtml + '</div>' : '<div class="wsv2-camp-m" style="margin-top:10px">No campaign is live yet — Sarah proposes ideas for each business.</div>') +
      (next ? '<div class="wsv2-camp-next"><span>Next · ' + campEsc(campWhen(next.at)) + '</span>' + campEsc(next.t) + '</div>' : '') +
      (ideas ? '<button type="button" class="wsv2-cta wsv2-camp-go" data-go="ideas">Review ' + ideas + ' idea' + (ideas === 1 ? '' : 's') + ' →</button>' : '') +
      '<button type="button" class="wsv2-cta" data-go="open">Open Campaigns →</button>');
    Array.prototype.forEach.call(el.querySelectorAll('[data-go]'), function (b) {
      b.addEventListener('click', function (e) { e.stopPropagation(); if (el.__moved) return; if (typeof window.nav === 'function') window.nav('projects'); });
    });
  }
  // drag: same feel as the agent cards (mouse and touch), stays put where dropped; never starts the canvas lasso or pan
  function campDrag(el) {
    var st = null;
    function scale() { var w = document.getElementById('wsv2-world'); return (w && w.getBoundingClientRect().width / CANVAS_W) || 1; }
    function down(x, y, e) { if (e.target.closest && e.target.closest('button')) return; e.stopPropagation(); st = { x: x, y: y, l: parseFloat(el.style.left) || 0, t: parseFloat(el.style.top) || 0, sc: scale() }; el.__moved = false; }
    function move(x, y, e) { if (!st) return; var dx = (x - st.x) / st.sc, dy = (y - st.y) / st.sc; if (Math.abs(dx) + Math.abs(dy) > 4) el.__moved = true; if (!el.__moved) return; e.preventDefault(); el.classList.add('dragging'); el.style.left = Math.max(0, Math.min(CANVAS_W - CAMP_W, st.l + dx)) + 'px'; el.style.top = Math.max(0, Math.min(CANVAS_H - 120, st.t + dy)) + 'px'; }
    function up() { if (!st) return; if (el.__moved) campSavePos({ x: parseFloat(el.style.left), y: parseFloat(el.style.top) }); el.classList.remove('dragging'); st = null; setTimeout(function () { el.__moved = false; }, 0); }
    el.addEventListener('mousedown', function (e) { down(e.clientX, e.clientY, e); });
    window.addEventListener('mousemove', function (e) { move(e.clientX, e.clientY, e); });
    window.addEventListener('mouseup', up);
    // CAMP-DBLTAP-1: double-tap-and-hold, exactly as the agent cards; a single touch arms the next one and nothing else
    var tap = { at: 0, x: 0, y: 0 };
    el.addEventListener('touchstart', function (e) {
      var t = e.touches[0]; if (!t || e.touches.length !== 1) return;
      if (e.target.closest && e.target.closest('button')) return;
      e.stopPropagation();   // the canvas must not see it (no lasso, no pan under the card)
      var now = Date.now();
      if (now - tap.at < DBL_TAP_MS && Math.abs(t.clientX - tap.x) < DBL_TAP_PX && Math.abs(t.clientY - tap.y) < DBL_TAP_PX) {
        tap = { at: 0, x: 0, y: 0 }; e.preventDefault(); down(t.clientX, t.clientY, e);
      } else { tap = { at: now, x: t.clientX, y: t.clientY }; st = null; }
    }, { passive: false });
    el.addEventListener('touchmove', function (e) { var t = e.touches[0]; if (t) move(t.clientX, t.clientY, e); }, { passive: false });
    el.addEventListener('touchend', up);
  }
  (function campCss() {
    if (document.getElementById('wsv2-camp-css')) return;
    var s = document.createElement('style'); s.id = 'wsv2-camp-css';
    s.textContent = '.wsv2-camp{position:absolute;width:' + CAMP_W + 'px;z-index:4;cursor:grab;user-select:none;-webkit-touch-callout:none;background:var(--s1);border:1px solid var(--bd);border-top:3px solid #3DD9B0;border-radius:var(--rg);padding:14px;box-shadow:0 4px 20px rgba(0,0,0,.3);transition:box-shadow .2s,border-color .2s}.wsv2-camp:hover{box-shadow:0 8px 32px rgba(0,0,0,.4)}.wsv2-camp.dragging{opacity:.9;z-index:100;cursor:grabbing}.wsv2-camp .wsv2-name{cursor:default}' +
      '.wsv2-camp-ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:rgba(61,217,176,.14);color:#3DD9B0;flex-shrink:0}' +
      '.wsv2-camp-list{margin-top:10px;padding-top:8px;border-top:1px solid var(--bd);display:flex;flex-direction:column;gap:8px}' +
      '.wsv2-camp-t{font-size:11.5px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
      '.wsv2-camp-m{font-size:10px;color:var(--t3);margin-top:1px}' +
      '.wsv2-camp-next{margin-top:10px;padding:7px 8px;border-radius:7px;background:var(--s2);border:1px solid var(--bd);font-size:10.5px;color:var(--t2);line-height:1.35}' +
      '.wsv2-camp-next span{display:block;font-size:9px;text-transform:uppercase;letter-spacing:.05em;color:#F59E0B;margin-bottom:2px}' +
      '.wsv2-camp-go{background:var(--p)!important;border-color:var(--p)!important;color:#fff!important}';
    document.head.appendChild(s);
  })();
})();
