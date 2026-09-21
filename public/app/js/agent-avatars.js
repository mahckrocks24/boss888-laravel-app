/* ═══════════════════════════════════════════════════════════════════════════════════════════════════
   agent-avatars.js — ONE face per agent (AVATAR888, DEC-0055, 2026-09-15; audit REPORT-0058).
   The registry below is generated from the `agents` table (php /root/aria-build/gen-registry.php) plus two static
   rows (Arthur, Aria). Every surface that shows an agent calls window.luAvatar(slug, size, state) and gets the
   portrait inside a coloured ring; the ring animates with the orb states (idle / thinking / executing / success /
   error) so live status still reads. agents.color is the only colour truth. No emoji, no initials, no gradients.
   ═══════════════════════════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var REG = {"sarah":{"id":1,"slug":"sarah","name":"Sarah","title":"Digital Marketing Manager","category":"dmm","level":"senior","color":"#F59E0B","img":"/img/agents/sarah.webp","skills":["Strategy","Campaign Management","Team Coordination","Goal Setting"],"desc":"Lead agent. Orchestrates the team, sets goals, monitors execution."},"james":{"id":2,"slug":"james","name":"James","title":"SEO Strategist","category":"seo","level":"junior","color":"#3B82F6","img":"/img/agents/james.webp","skills":["Keyword Research","SERP Analysis","Competitor Research"],"desc":"Keyword research, SERP analysis, and organic growth strategy."},"alex":{"id":3,"slug":"alex","name":"Alex","title":"Technical SEO Engineer","category":"seo","level":"senior","color":"#06B6D4","img":"/img/agents/alex.webp","skills":["Site Audits","Core Web Vitals","Schema Markup"],"desc":"Site audits, Core Web Vitals, schema markup, and indexing."},"diana":{"id":4,"slug":"diana","name":"Diana","title":"Local SEO Specialist","category":"seo","level":"junior","color":"#3B82F6","img":"/img/agents/diana.webp","skills":["Google Business","Local Citations","Map Pack"],"desc":"Google Business, local citations, and map pack rankings."},"ryan":{"id":5,"slug":"ryan","name":"Ryan","title":"Link Building Specialist","category":"seo","level":"junior","color":"#06B6D4","img":"/img/agents/ryan.webp","skills":["Link Outreach","Digital PR","Authority Building"],"desc":"Backlink acquisition, digital PR, and authority building."},"sofia":{"id":6,"slug":"sofia","name":"Sofia","title":"International SEO Director","category":"seo","level":"senior","color":"#3B82F6","img":"/img/agents/sofia.webp","skills":["Multi-market SEO","Hreflang","International SEO"],"desc":"Multi-market SEO strategy, hreflang, and international search presence."},"priya":{"id":7,"slug":"priya","name":"Priya","title":"Content Manager","category":"content","level":"specialist","color":"#7C3AED","img":"/img/agents/priya.webp","skills":["Blog Writing","Landing Pages","SEO Copy"],"desc":"Blog articles, website copy, and marketing content creation."},"leo":{"id":8,"slug":"leo","name":"Leo","title":"Brand Copywriter","category":"content","level":"specialist","color":"#7C3AED","img":"/img/agents/leo.webp","skills":["Ad Copy","Brand Voice","Conversion Copy"],"desc":"Conversion-focused copy, brand messaging, and ad headlines."},"maya":{"id":9,"slug":"maya","name":"Maya","title":"Social Content Writer","category":"content","level":"junior","color":"#7C3AED","img":"/img/agents/maya.webp","skills":["Captions","Hashtags","Platform Formats"],"desc":"Captions, hashtag strategies, and platform-specific content."},"chris":{"id":10,"slug":"chris","name":"Chris","title":"Video Script Writer","category":"content","level":"junior","color":"#F97316","img":"/img/agents/chris.webp","skills":["Video Scripts","Reel Hooks","TikTok Copy"],"desc":"Short-form video scripts, reels hooks, and ad video copy."},"nora":{"id":11,"slug":"nora","name":"Nora","title":"Content Strategy Director","category":"content","level":"senior","color":"#7C3AED","img":"/img/agents/nora.webp","skills":["Editorial Strategy","Content Calendars","Thought Leadership"],"desc":"Full content calendars, thought leadership, and editorial strategy."},"marcus":{"id":12,"slug":"marcus","name":"Marcus","title":"Social Media Manager","category":"social","level":"specialist","color":"#EC4899","img":"/img/agents/marcus.webp","skills":["Content Creation","Scheduling","Engagement"],"desc":"Multi-platform content creation and posting schedule management."},"zara":{"id":13,"slug":"zara","name":"Zara","title":"Instagram Growth Specialist","category":"social","level":"junior","color":"#EC4899","img":"/img/agents/zara.webp","skills":["Instagram Reels","Stories","Follower Growth"],"desc":"Instagram Reels, Stories, and follower growth strategies."},"tyler":{"id":14,"slug":"tyler","name":"Tyler","title":"LinkedIn Marketing Expert","category":"social","level":"junior","color":"#EC4899","img":"/img/agents/tyler.webp","skills":["LinkedIn Posts","B2B Strategy","Lead Gen"],"desc":"B2B LinkedIn strategy, thought leadership posts, and lead gen."},"zoe":{"id":15,"slug":"zoe","name":"Zoe","title":"TikTok & Reels Creator","category":"social","level":"junior","color":"#EC4899","img":"/img/agents/zoe.webp","skills":["TikTok Strategy","Trending Formats","Viral Hooks"],"desc":"Short-form video strategy, trending formats, and viral hooks."},"jordan":{"id":16,"slug":"jordan","name":"Jordan","title":"Social Analytics Director","category":"social","level":"senior","color":"#EC4899","img":"/img/agents/jordan.webp","skills":["Analytics","ROI Reporting","Audience Insights"],"desc":"Cross-platform analytics, ROI reporting, and audience insights."},"elena":{"id":17,"slug":"elena","name":"Elena","title":"Lead & CRM Manager","category":"crm","level":"junior","color":"#00E5A8","img":"/img/agents/elena.webp","skills":["Lead Capture","Pipeline","Follow-up Sequences"],"desc":"Lead capture, pipeline management, and automated follow-ups."},"kai":{"id":19,"slug":"kai","name":"Kai","title":"Lead Nurturing Specialist","category":"crm","level":"junior","color":"#00E5A8","img":"/img/agents/kai.webp","skills":["Drip Sequences","Lead Scoring","Nurture Flows"],"desc":"Drip sequences, lead scoring, and conversion optimization."},"vera":{"id":20,"slug":"vera","name":"Vera","title":"Marketing Automation Expert","category":"crm","level":"junior","color":"#F97316","img":"/img/agents/vera.webp","skills":["Workflow Builder","Trigger Logic","Multi-channel"],"desc":"Workflow automation, trigger logic, and multi-channel sequences."},"max":{"id":21,"slug":"max","name":"Max","title":"Growth & CRO Director","category":"crm","level":"senior","color":"#00E5A8","img":"/img/agents/max.webp","skills":["CRO","Funnel Analysis","A/B Testing"],"desc":"Conversion rate optimization, funnel analysis, and A/B testing."},"arthur":{"id":900,"slug":"arthur","name":"Arthur","title":"Website Builder","category":"builder","level":"senior","color":"#6C5CE7","img":"/img/agents/arthur.webp","skills":["Site build","Editing","Sections","Design"],"desc":"Builds and edits your website from a brief, in plain language."},"aria":{"id":901,"slug":"aria","name":"Aria","title":"Platform FAQ","category":"help","level":"specialist","color":"#00B8D9","img":"/img/agents/aria.webp","skills":["Platform help","Plans","Credits"],"desc":"Answers questions about how the platform works."}};
  REG.dmm = REG.sarah;                        // the UI alias Sarah has always had
  window.LU_AGENTS = REG;

  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function ensureCss() {
    if (document.getElementById('lu-av-css')) return;
    var st = document.createElement('style'); st.id = 'lu-av-css';
    st.textContent = [
      '.lu-av{position:relative;display:inline-flex;flex:none;border-radius:50%;width:var(--avs,40px);height:var(--avs,40px);background:#0B1020;vertical-align:middle}',   /* Owner 2026-09-15: no coloured ring, no glow, no colour per agent */
      '.lu-av img{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block}',
      '.lu-av .lu-av-i{position:absolute;inset:0;border-radius:50%;display:flex;align-items:center;justify-content:center;font:700 calc(var(--avs,40px)*.42) var(--fh,sans-serif);color:#fff;background:#1B2033}',
      '.lu-av::after{content:"";position:absolute;inset:-2px;border-radius:50%;border:2px solid transparent;pointer-events:none}',
      '.lu-av[data-state="thinking"]::after{border-top-color:rgba(255,255,255,.75);animation:luAvSpin 1.1s linear infinite}',
      '.lu-av[data-state="executing"]::after{border-top-color:rgba(255,255,255,.75);border-right-color:rgba(255,255,255,.75);animation:luAvSpin .5s linear infinite}',
      '.lu-av[data-state="success"]::after{border-color:#00E5A8}',
      '.lu-av[data-state="error"]::after{border-color:#F87171}',
      '@keyframes luAvSpin{to{transform:rotate(360deg)}}',
      '@media (prefers-reduced-motion:reduce){.lu-av::after{animation:none !important}}'
    ].join('\n');
    (document.head || document.documentElement).appendChild(st);
  }
  var SIZES = { xs: 20, sm: 28, md: 40, lg: 64, xl: 96 };

  /** Portrait HTML. size = 'xs'|'sm'|'md'|'lg'|'xl' or a pixel number. */
  window.luAvatar = function (slug, size, state, opts) {
    ensureCss(); opts = opts || {};
    var a = REG[String(slug || '').toLowerCase()] || null;
    var px = typeof size === 'number' ? size : (SIZES[size] || SIZES.md);
    var color = (a && a.color) || '#6C5CE7';
    var name = (a && a.name) || String(slug || '?');
    var inner = (a && a.img) ? '<img src="' + esc(a.img) + '" alt="" loading="lazy" decoding="async" onerror="this.replaceWith(Object.assign(document.createElement(\'span\'),{className:\'lu-av-i\',textContent:\'' + esc(name.charAt(0).toUpperCase()) + '\'}))">'
                              : '<span class="lu-av-i">' + esc(name.charAt(0).toUpperCase()) + '</span>';
    return '<span class="lu-av' + (opts.cls ? ' ' + esc(opts.cls) : '') + '" data-agent="' + esc(a ? a.slug : slug) + '" data-state="' + esc(state || 'idle') + '" style="--avs:' + px + 'px" title="' + esc(opts.title || name) + '" role="img" aria-label="' + esc(name) + '">' + inner + '</span>';
  };
  window.luAgent = function (slug) { return REG[String(slug || '').toLowerCase()] || null; };
  window.luAgentColor = function () { return 'var(--t1)'; };   // Owner 2026-09-15: agents carry no colour
  window.luAvatarUrl = function (slug) { var a = window.luAgent(slug); return a && a.img ? a.img : ''; };
  /** Set the live state on every rendered face of an agent. */
  window.luAvatarState = function (slug, state, autoresetMs) {
    var s = String(slug || '').toLowerCase(); if (s === 'dmm') s = 'sarah';
    document.querySelectorAll('.lu-av[data-agent="' + s + '"]').forEach(function (n) { n.dataset.state = state || 'idle'; });
    if (autoresetMs > 0) setTimeout(function () { window.luAvatarState(s, 'idle'); }, autoresetMs);
  };
  /** The workforce for listing surfaces: Sarah first, then Arthur, then specialists by category. Aria is not a workforce member. */
  window.luWorkforce = function () {
    var order = ['dmm', 'seo', 'content', 'social', 'crm', 'builder'];
    return Object.keys(REG).filter(function (k) { return k !== 'dmm' && k !== 'aria'; }).map(function (k) { return REG[k]; })
      .sort(function (a, b) { if (a.slug === 'sarah') return -1; if (b.slug === 'sarah') return 1; if (a.slug === 'arthur') return -1; if (b.slug === 'arthur') return 1; var ca = order.indexOf(a.category), cb = order.indexOf(b.category); return ca !== cb ? ca - cb : a.id - b.id; });
  };
  ensureCss();
})();
/* Agents page: every workforce member from the registry (was five hard-coded cards). Sarah first, then Arthur, then the
   specialists by team. Stats ids keep the av-ongoing-<uiId> convention loadAgentStats fills. */
(function () {
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  var TEAM = { dmm: 'Leadership', builder: 'Website', seo: 'Search', content: 'Content', social: 'Social', crm: 'Customers' };
  /* Owner 2026-09-21: only the agents enabled on THIS workspace are the team (window._luEnabledAgents, from
     /api/agents/dashboard via loadAgentStats); everyone else waits behind one "More specialists" toggle. Until the
     enabled list is known the grid shows a one-line placeholder rather than the whole registry (no 12-then-5 flash). */
  window.luRenderAgentsGrid = function (force) {
    var g = document.getElementById('av-grid'); if (!g || (!force && g.dataset.built === '1') || typeof window.luWorkforce !== 'function') return;
    var enabled = window._luEnabledAgents;
    if (!enabled) { g.dataset.built = '0'; g.innerHTML = '<div style="grid-column:1/-1;color:var(--t3);font-size:12px">Loading your team…</div>'; return; }
    g.dataset.built = '1';
    var html = '', lastTeam = null, more = 0, moreHtml = '';
    var card = function (a) {
      var ui = a.slug === 'sarah' ? 'dmm' : a.slug;
      var open = a.slug === 'arthur' ? "window.nav&&nav('websites')" : "openAgentDrawer('" + ui + "')";
      return '<div class="av-card" onclick="' + open + '" data-agent="' + esc(a.slug) + '">'
        + '<div class="av-card-top"><div class="av-card-av" style="background:transparent;border:none">' + window.luAvatar(a.slug, 'md') + '</div>'
        + '<div><div class="av-card-name" style="color:var(--t1)">' + esc(a.name) + '</div><div class="av-card-role">' + esc(a.title) + '</div></div></div>'
        + '<div class="av-card-stats"><div class="av-stat"><div class="av-stat-val" id="av-ongoing-' + ui + '">—</div><div class="av-stat-lbl">Ongoing</div></div>'
        + '<div class="av-stat"><div class="av-stat-val" id="av-upcoming-' + ui + '">—</div><div class="av-stat-lbl">Upcoming</div></div>'
        + '<div class="av-stat"><div class="av-stat-val" id="av-completed-' + ui + '">—</div><div class="av-stat-lbl">Done</div></div></div>'
        + '<div class="av-card-expertise">' + esc((a.skills || []).join(' · ')) + '</div></div>';
    };
    window.luWorkforce().forEach(function (a) {
      var on = a.slug === 'sarah' || enabled.indexOf(a.slug) >= 0;
      if (!on) { more++; moreHtml += card(a); return; }
      var team = TEAM[a.category] || a.category;
      if (team !== lastTeam) { html += '<div class="av-team" style="grid-column:1/-1;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--t3);font-weight:700;margin:' + (lastTeam ? '10px' : '0') + ' 0 -4px">' + esc(team) + '</div>'; lastTeam = team; }
      html += card(a);
    });
    if (more) {
      var label = function (o) { return (o ? 'Hide ' : 'Show ') + more + ' more specialist' + (more === 1 ? '' : 's') + ' you can enable'; };
      html += '<div style="grid-column:1/-1;margin-top:8px"><button type="button" class="ct-btn" id="av-more-btn" aria-expanded="false" aria-controls="av-more">' + label(false) + '</button></div>'
        + '<div id="av-more" class="av-grid" style="grid-column:1/-1;display:none">' + moreHtml + '</div>';
    }
    g.innerHTML = html;
    var btn = document.getElementById('av-more-btn');
    if (btn) { btn.addEventListener('click', function () { var m = document.getElementById('av-more'); var open = m.style.display === 'none'; m.style.display = open ? 'grid' : 'none'; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); btn.textContent = (open ? 'Hide ' : 'Show ') + more + ' more specialist' + (more === 1 ? '' : 's') + ' you can enable'; }); }
    if (typeof window.updateNodeCounts === 'function') { try { window.updateNodeCounts(); } catch (e) {} }
  };
})();
