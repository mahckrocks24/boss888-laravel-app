/* sarah-intro.js — SARAH-INTRO-1 (Owner 2026-09-30: "design Sarah's introduction journey … make sure all users go through that"; "okay build it").
   Design: the canvas https://claude.ai/artifact/RqobaBAXcwoi6n6iHRHjyz (8 chapters, glass, brand gradient, phone first).
   1. After the first website is published, Sarah introduces herself full screen: 8 chapters, Back allowed, no Skip.
      Progress and completion live in users.preferences_json.sarah_intro (server, per person), so it resumes on any device.
      Everyone with a published website who has not seen it gets it once, new and existing accounts alike.
   2. Then, the first time a person opens a page, Sarah's bubble introduces that page (preferences_json.page_intros).
   3. A first-time account (created on or after the cut-off) cannot leave the first website unpublished: the editor's Back is
      hidden and closing is refused until Publish succeeds (builder.js checks html.lu-first-site).
   Never decided by localStorage: the server's preferences are the record (EV-1043 rule). */
(function () {
  if (window.luSarahIntro) return;
  var VERSION = 1;
  var FIRST_TIME_CUTOFF = Date.parse('2026-09-30T00:00:00Z');
  var IMG = '/img/agents/', LOGO = '/img/logo-icon-new.png';
  var S = { prefs: null, me: null, sites: null, booted: false, running: false, step: 0, pick: 2, seen: {}, siteName: '', siteUrl: '', bubbleKey: null };

  function tok() { try { return localStorage.getItem('lu_token') || ''; } catch (e) { return ''; } }
  function hdr() { return { 'Authorization': 'Bearer ' + tok(), 'Accept': 'application/json', 'Content-Type': 'application/json' }; }
  function getJ(u) { return fetch(u, { headers: hdr(), cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; }); }
  function putPrefs(o) {
    return fetch('/api/user/preferences', { method: 'PUT', headers: hdr(), body: JSON.stringify(o) })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.preferences) S.prefs = j.preferences; })
      .catch(function () {});
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function isPub(x) { return !!(x && (x.status === 'published' || x.publish_state === 'published')); }
  function listOf(j) { var l = j && (j.websites || j.data || j); return Array.isArray(l) ? l : []; }
  function phone() { return !!(window.matchMedia && window.matchMedia('(max-width: 899px)').matches); }
  function introDone() { var t = S.prefs && S.prefs.sarah_intro; return !!(t && t.done_at && (t.version || 1) >= VERSION); }
  function firstTimer() { var c = S.me && S.me.user && S.me.user.created_at; var t = c ? Date.parse(c) : NaN; return !isNaN(t) && t >= FIRST_TIME_CUTOFF; }
  function lockedToArthur() { return document.documentElement.classList.contains('lu-arthur-lock'); }
  function editorOpen() { return !!document.getElementById('template-editor-view'); }

  /* ───────────── styles ───────────── */
  function css() {
    if (document.getElementById('lsi-css')) return;
    var st = document.createElement('style'); st.id = 'lsi-css';
    st.textContent = [
      '#lsi,#lsi-bubble{--ground:#0A0B16;--bv:rgba(140,37,210,.34);--bb:rgba(76,134,222,.30);--ba:rgba(63,223,223,.20);--ink:#F5F6FC;--ink2:#C0C5D8;--ink3:#9399B2;--glass:rgba(20,22,36,.68);--thick:rgba(20,22,38,.9);--raised:rgba(38,40,62,.94);--rim:rgba(255,255,255,.12);--rimtop:rgba(255,255,255,.22);--hair:rgba(255,255,255,.09);--acc:#CDB8FF;--tint:rgba(194,166,255,.13);--tintrim:rgba(194,166,255,.24);--sat:105%;--sh:0 12px 44px rgba(0,0,0,.5);--ok:#3DDC97;--oksoft:rgba(61,220,151,.14);--warn:#F2B24C;--warnsoft:rgba(242,178,76,.14);--scrim:rgba(6,7,16,.5);--vig:radial-gradient(140% 100% at 50% 0%,transparent 50%,rgba(0,0,0,.38) 100%)}',
      'html[data-theme="light"] #lsi,html[data-theme="light"] #lsi-bubble{--ground:#F3F4F9;--bv:rgba(140,37,210,.30);--bb:rgba(76,134,222,.30);--ba:rgba(63,223,223,.34);--ink:#0D0F1C;--ink2:#454A61;--ink3:#62677F;--glass:rgba(255,255,255,.62);--thick:rgba(255,255,255,.9);--raised:rgba(255,255,255,.96);--rim:rgba(255,255,255,.75);--rimtop:rgba(255,255,255,.95);--hair:rgba(18,16,48,.08);--acc:#6A22C2;--tint:rgba(107,59,223,.10);--tintrim:rgba(107,59,223,.18);--sat:180%;--sh:0 6px 16px rgba(30,24,80,.08),0 28px 64px rgba(30,24,80,.14);--ok:#13875C;--oksoft:rgba(19,135,92,.12);--warn:#9A5B00;--warnsoft:rgba(214,142,0,.14);--scrim:rgba(20,18,40,.22);--vig:none}',
      '#lsi{position:fixed;inset:0;z-index:2147483200;color:var(--ink);font-family:"Plus Jakarta Sans",var(--fb,Inter),system-ui,sans-serif;-webkit-font-smoothing:antialiased;display:flex;flex-direction:column;height:100dvh;overflow:hidden;',
      'background:var(--vig),radial-gradient(60% 38% at 12% 8%,var(--bv),transparent 70%),radial-gradient(55% 40% at 95% 34%,var(--bb),transparent 72%),radial-gradient(75% 42% at 45% 102%,var(--ba),transparent 72%),var(--ground);animation:lsiFade .45s ease both}',
      '#lsi *{box-sizing:border-box}#lsi button{font-family:inherit}',
      '#lsi .glass{background:var(--glass);border:1px solid var(--rim);box-shadow:inset 0 1px 0 var(--rimtop),var(--sh)}',
      '#lsi .thick{background:var(--thick);border:1px solid var(--rim);box-shadow:inset 0 1px 0 var(--rimtop),var(--sh)}',
      '@media (min-width:900px){#lsi .lsi-frost{-webkit-backdrop-filter:blur(5px) saturate(.9);backdrop-filter:blur(5px) saturate(.9)}#lsi .glass{-webkit-backdrop-filter:blur(26px) saturate(var(--sat));backdrop-filter:blur(26px) saturate(var(--sat))}#lsi .thick{-webkit-backdrop-filter:blur(34px) saturate(var(--sat));backdrop-filter:blur(34px) saturate(var(--sat))}}',
      '#lsi .gt{background:linear-gradient(135deg,#8C25D2 0%,#4C86DE 55%,#2FC4C4 100%);-webkit-background-clip:text;background-clip:text;color:transparent}',
      'html:not([data-theme="light"]) #lsi .gt{background:linear-gradient(135deg,#C08BFF 0%,#86B6F7 55%,#6CEDED 100%);-webkit-background-clip:text;background-clip:text;color:transparent}',
      '#lsi .ring,#lsi-bubble .ring{padding:3px;border-radius:50%;background:linear-gradient(135deg,#8C25D2,#4C86DE 55%,#3FDFDF);flex-shrink:0}',
      '#lsi .ring img,#lsi-bubble .ring img{display:block;width:100%;height:100%;border-radius:50%;object-fit:cover;border:2px solid var(--ground)}',
      '#lsi .btn-go,#lsi-bubble .btn-go{height:52px;border:0;border-radius:999px;color:#fff;font-size:16px;font-weight:700;cursor:pointer;background:linear-gradient(180deg,#8A3BEA 0%,#6A45E4 100%);box-shadow:0 10px 26px -8px rgba(107,59,223,.6),inset 0 1px 0 rgba(255,255,255,.28);display:flex;align-items:center;justify-content:center;gap:8px;padding:0 20px}',
      '#lsi .btn-ghost,#lsi-bubble .btn-ghost{height:52px;border-radius:999px;cursor:pointer;font-size:15px;font-weight:600;color:var(--ink);background:var(--glass);border:1px solid var(--rim);box-shadow:inset 0 1px 0 var(--rimtop);display:flex;align-items:center;justify-content:center;gap:6px;padding:0 16px}',
      '#lsi button:focus-visible,#lsi-bubble button:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(63,223,223,.55),0 0 0 1px #2BB8B8}',
      '#lsi .chip,#lsi-bubble .chip{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 12px;border-radius:999px;font-size:12px;font-weight:600;color:var(--acc);background:var(--tint);border:1px solid var(--tintrim)}',
      '#lsi .lsi-top{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:max(14px,env(safe-area-inset-top)) 20px 0;height:auto;min-height:52px}',
      '#lsi .lsi-top img{width:24px;height:24px;object-fit:contain}#lsi .lsi-top b{font-size:15px;font-weight:800;letter-spacing:-.2px}',
      '#lsi .lsi-count{margin-left:auto;font-size:13px;font-weight:700;color:var(--ink3)}',
      '#lsi .lsi-segs{display:flex;gap:5px;padding:12px 20px 0}#lsi .lsi-segs span{flex:1;height:4px;border-radius:4px;background:var(--hair);transition:background .4s,opacity .4s}',
      '#lsi .lsi-segs span.on{background:linear-gradient(90deg,#8C25D2,#4C86DE,#3FDFDF)}#lsi .lsi-segs span.past{opacity:.55}',
      '#lsi .lsi-main{flex:1 1 auto;min-height:0;display:flex;flex-direction:column}',
      '#lsi .lsi-scene{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;padding:14px 20px 0}',
      '#lsi .lsi-eyebrow{font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:var(--acc)}',
      '#lsi h1.lsi-title{margin:6px 0 0;font-size:28px;line-height:1.1;font-weight:800;letter-spacing:-.6px;color:var(--ink)}',
      '#lsi .lsi-fit{flex:1 1 auto;min-height:0;position:relative;margin-top:12px}',
      '#lsi .lsi-stage{position:absolute;left:50%;top:0;width:350px;height:390px;transform-origin:top center}',
      '#lsi .lsi-talk{flex:0 0 auto;margin:10px 12px max(12px,env(safe-area-inset-bottom));border-radius:30px;padding:18px 18px 16px;display:flex;flex-direction:column;gap:10px}',
      '#lsi .lsi-who{display:flex;align-items:center;gap:10px}#lsi .lsi-who b{display:block;font-size:14px}#lsi .lsi-who span{font-size:12px;color:var(--ink3)}',
      '#lsi .lsi-say{margin:0;font-size:16px;line-height:1.5;color:var(--ink);min-height:4.5em}',
      '#lsi .lsi-say .w{display:inline-block;margin-right:.28em;animation:lsiWord .5s cubic-bezier(.2,.8,.2,1) both}',
      '#lsi .lsi-toc{display:none}',
      '#lsi .lsi-btns{display:flex;gap:10px;margin-top:4px}#lsi .lsi-btns .btn-go{flex:1}#lsi .lsi-back{width:52px;padding:0}',
      '#lsi .lsi-back .lbl{display:none}',
      '@media (min-width:900px){',
      '  #lsi .lsi-top{padding:26px 48px 0}#lsi .lsi-top b{font-size:17px}#lsi .lsi-top img{width:28px;height:28px}',
      '  #lsi .lsi-segs{position:absolute;left:50%;top:40px;width:360px;margin-left:-180px;padding:0}',
      '  #lsi .lsi-main{flex-direction:row;gap:32px;padding:28px 48px 36px}',
      '  #lsi .lsi-scene{border-radius:36px;padding:36px 44px 24px;background:var(--glass);border:1px solid var(--rim);box-shadow:inset 0 1px 0 var(--rimtop),var(--sh);-webkit-backdrop-filter:blur(26px) saturate(var(--sat));backdrop-filter:blur(26px) saturate(var(--sat))}',
      '  #lsi h1.lsi-title{font-size:40px;letter-spacing:-1px}',
      '  #lsi .lsi-talk{width:452px;margin:0;border-radius:36px;padding:32px;gap:18px}',
      '  #lsi .lsi-who .ring{width:64px!important;height:64px!important}#lsi .lsi-who b{font-size:18px}#lsi .lsi-who span{font-size:14px}',
      '  #lsi .lsi-say{font-size:19px}',
      '  #lsi .lsi-toc{display:flex;flex-direction:column;gap:2px;padding-top:14px;border-top:1px solid var(--hair);overflow:auto;min-height:0}',
      '  #lsi .lsi-toc button{height:40px;border:0;background:transparent;border-radius:12px;display:flex;align-items:center;gap:10px;padding:0 10px;cursor:pointer;font-size:14px;font-weight:500;color:var(--ink3);text-align:left}',
      '  #lsi .lsi-toc button.cur{background:var(--tint);font-weight:700;color:var(--ink)}#lsi .lsi-toc button.past{color:var(--ink)}',
      '  #lsi .lsi-toc i{font-style:normal;width:22px;height:22px;border-radius:50%;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;background:var(--hair);color:var(--ink3);flex-shrink:0}',
      '  #lsi .lsi-toc .past i{background:var(--oksoft);color:var(--ok)}#lsi .lsi-toc .cur i{background:linear-gradient(135deg,#8C25D2,#4C86DE);color:#fff}',
      '  #lsi .lsi-btns{margin-top:auto}#lsi .lsi-back{width:120px}#lsi .lsi-back .lbl{display:inline}',
      '}',
      '@media (max-height:700px) and (max-width:899px){#lsi h1.lsi-title{font-size:23px}#lsi .lsi-say{font-size:15px;min-height:3em}#lsi .lsi-talk{padding:14px;gap:8px}#lsi .btn-go,#lsi .btn-ghost{height:48px}}',
      '@keyframes lsiFade{from{opacity:0}to{opacity:1}}',
      '@keyframes lsiRise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}',
      '@keyframes lsiPop{0%{opacity:0;transform:scale(.6)}70%{opacity:1;transform:scale(1.04)}100%{opacity:1;transform:scale(1)}}',
      '@keyframes lsiWord{from{opacity:0;filter:blur(6px);transform:translateY(6px)}to{opacity:1;filter:blur(0);transform:none}}',
      '@keyframes lsiDraw{from{stroke-dashoffset:540}to{stroke-dashoffset:0}}',
      '@keyframes lsiLine{from{stroke-dashoffset:200}to{stroke-dashoffset:0}}',
      '@keyframes lsiFrost{from{clip-path:inset(0 100% 0 0)}to{clip-path:inset(0 0 0 0)}}',
      '@keyframes lsiLive{0%,100%{box-shadow:0 0 0 0 rgba(61,220,151,.55)}50%{box-shadow:0 0 0 7px rgba(61,220,151,0)}}',
      '@keyframes lsiScan{from{transform:translateX(-110%)}to{transform:translateX(260%)}}',
      '@keyframes lsiDot{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-2px)}}',
      '@keyframes lsiGrow{from{transform:scaleY(0)}to{transform:scaleY(1)}}',
      '@keyframes lsiTap{0%{opacity:0;transform:scale(.3)}25%{opacity:.95}100%{opacity:0;transform:scale(1.9)}}',
      '@keyframes lsiSeqA{0%,32%{opacity:1;transform:none}40%,100%{opacity:0;transform:translateY(-6px);visibility:hidden}}',
      '@keyframes lsiSeqB{0%,34%{opacity:0;transform:translateY(10px);visibility:hidden}42%,70%{opacity:1;transform:none;visibility:visible}78%,100%{opacity:0;transform:scale(.97);visibility:hidden}}',
      '@keyframes lsiSeqC{0%,76%{opacity:0;transform:scale(.94)}86%,100%{opacity:1;transform:none}}',
      '@keyframes lsiShimmer{from{transform:translateX(-120%) skewX(-18deg)}to{transform:translateX(320%) skewX(-18deg)}}',
      '@keyframes lsiSlide{from{opacity:0;transform:translateX(18px)}to{opacity:1;transform:none}}',
      '@keyframes lsiBubble{0%{opacity:0;transform:translateY(12px) scale(.92)}100%{opacity:1;transform:none}}',
      '#lsi .a-rise{animation:lsiRise .7s cubic-bezier(.2,.8,.2,1) both}#lsi .a-pop{animation:lsiPop .7s cubic-bezier(.2,.8,.2,1) both}',
      '#lsi .a-fade{animation:lsiFade .8s ease both}#lsi .a-slide{animation:lsiSlide .6s cubic-bezier(.2,.8,.2,1) both}',
      /* page bubble */
      '#lsi-bubble{position:fixed;z-index:100050;width:330px;max-width:calc(100vw - 24px);color:var(--ink);font-family:"Plus Jakarta Sans",var(--fb,Inter),system-ui,sans-serif;-webkit-font-smoothing:antialiased;transform-origin:100% 100%;animation:lsiBubble .55s cubic-bezier(.2,.8,.2,1) both}',
      '#lsi-bubble .lsb-card{position:relative;background:var(--thick);border:1px solid var(--rim);box-shadow:inset 0 1px 0 var(--rimtop),var(--sh),0 0 0 1px rgba(138,59,234,.18);border-radius:24px 24px 8px 24px;padding:16px 16px 14px;display:flex;flex-direction:column;gap:10px}',
      '@media (min-width:900px){#lsi-bubble .lsb-card{-webkit-backdrop-filter:blur(34px) saturate(var(--sat));backdrop-filter:blur(34px) saturate(var(--sat))}}',
      '@media (max-width:899px){#lsi-bubble .lsb-card,#lsi-bubble .lsb-tail{background:var(--raised)}}',
      '#lsi-bubble .lsb-head{display:flex;align-items:center;gap:10px}#lsi-bubble .lsb-head b{font-size:13px}',
      '#lsi-bubble .chip{height:22px;font-size:11px;padding:0 8px}',
      '#lsi-bubble p{margin:0;font-size:14.5px;line-height:1.5;color:var(--ink)}',
      '#lsi-bubble .lsb-btns{display:flex;gap:8px}#lsi-bubble .lsb-btns button{height:42px;flex:1;font-size:13.5px}',
      '#lsi-bubble .lsb-tail{position:absolute;right:22px;bottom:-9px;width:18px;height:18px;background:var(--thick);border-right:1px solid var(--rim);border-bottom:1px solid var(--rim);transform:rotate(45deg);border-radius:0 0 4px 0}',
      '@media (prefers-reduced-motion:reduce){#lsi *,#lsi,#lsi-bubble,#lsi-bubble *{animation-duration:.01ms!important;animation-delay:0s!important;animation-iteration-count:1!important}}',
      /* first website: the only way out is Publish */
      'html.lu-first-site #template-editor-view .pe-bar > button[onclick^="wsCloseTemplateEditor"]{display:none!important}',
      '#lsi-pubhint{display:none}html.lu-first-site #lsi-pubhint{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 10px;border-radius:999px;font-size:12px;font-weight:700;color:#fff;background:linear-gradient(135deg,#8C25D2,#4C86DE);white-space:nowrap;flex:0 0 auto}'
    ].join('\n');
    (document.head || document.documentElement).appendChild(st);
  }

  /* ───────────── pieces ───────────── */
  var ICON = {
    attention: '<path d="M10 3l7 12H3z"/><path d="M10 8v3M10 13.5h.01"/>',
    campaigns: '<path d="M3 9v3a1 1 0 0 0 1 1h2l5 3.5V4.5L6 8H4a1 1 0 0 0-1 1Z"/><path d="M14 7.5a3.5 3.5 0 0 1 0 5M16 5a7 7 0 0 1 0 10"/>',
    websites: '<rect x="2" y="3" width="16" height="13" rx="2"/><path d="M2 7h16"/>',
    clients: '<circle cx="8" cy="7" r="3"/><path d="M2 17c0-3 3-5 6-5s6 2 6 5"/><circle cx="14.5" cy="8" r="2"/><path d="M15 12c2 .3 3 2 3 4"/>',
    settings: '<circle cx="10" cy="10" r="7"/><circle cx="10" cy="8" r="2.5"/><path d="M5.5 15.5c1-2 2.6-3 4.5-3s3.5 1 4.5 3"/>',
    aria: '<circle cx="10" cy="10" r="7.5"/><path d="M7.8 8a2.2 2.2 0 014.4 0c0 1.5-2.2 1.7-2.2 3.2"/><path d="M10 14h.01"/>',
    hosting: '<rect x="2.5" y="3.5" width="15" height="5" rx="1.5"/><rect x="2.5" y="11.5" width="15" height="5" rx="1.5"/><path d="M5.5 6h.01M5.5 14h.01"/>',
    advanced: '<path d="M4 6h12M4 10h12M4 14h8"/>',
    chat: '<path d="M4 5h12a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H9l-4 3v-3H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/>',
    calendar: '<rect x="3" y="4" width="14" height="13" rx="2"/><path d="M3 8h14M7 2.5v3M13 2.5v3"/>',
    social: '<circle cx="14.5" cy="5.5" r="2"/><circle cx="5.5" cy="10" r="2"/><circle cx="14.5" cy="14.5" r="2"/><path d="M7.3 9l5.4-2.6M7.3 11l5.4 2.6"/>',
    seo: '<circle cx="9" cy="9" r="5.5"/><path d="M13 13l4 4"/>',
    studio: '<rect x="3" y="4" width="14" height="12" rx="2"/><circle cx="7.5" cy="8.5" r="1.5"/><path d="M17 13l-4-4-7 7"/>',
    check: '<path d="M5 10.5l3.2 3L15 6.5"/>',
    chev: '<path d="M8 5l5 5-5 5"/>',
    back: '<path d="M12 5l-5 5 5 5"/>',
    arrow: '<path d="M4 10h11M11 6l4 4-4 4"/>',
    lock: '<rect x="4" y="9" width="12" height="8" rx="2"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9"/>'
  };
  function ico(k, s, extra) { return '<svg viewBox="0 0 20 20" width="' + (s || 18) + '" height="' + (s || 18) + '" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;' + (extra || '') + '">' + ICON[k] + '</svg>'; }
  function av(name, s) { return '<div class="ring" style="width:' + s + 'px;height:' + s + 'px"><img src="' + IMG + name + '.webp" alt="' + (name.charAt(0).toUpperCase() + name.slice(1)) + '"></div>'; }
  function logoAv(s) { return '<div style="width:' + s + 'px;height:' + s + 'px;border-radius:50%;background:var(--raised);border:1px solid var(--hair);display:flex;align-items:center;justify-content:center;flex-shrink:0"><img src="' + LOGO + '" alt="Arthur" style="width:' + Math.round(s * .62) + 'px;height:' + Math.round(s * .62) + 'px;object-fit:contain"></div>'; }
  function d(s) { return 'animation-delay:' + s + 's'; }

  var CH = [
    ['Your website is live', 'It’s live.', 'Your website is live{first}. Arthur built it with you, and from here I take care of the growth.', 'Meet Sarah'],
    ['Meet your manager', 'I’m Sarah.', 'I’m Sarah, your Digital Marketing Manager. I plan your marketing and my team creates it. You approve it, and only then do I publish. Then I tell you plainly what worked.', 'Next'],
    ['How it works', 'A week with me', 'Here is what a normal week looks like. My specialists do the hands-on work, and I keep it moving on schedule.', 'Next'],
    ['Your OK comes first', 'You stay in charge', 'Nothing goes out without your OK. I bring you the plan, you approve it once, and I handle every step after that.', 'Next'],
    ['Who does what', 'Meet the team', 'I don’t work alone. Arthur looks after your website, Aria answers any question about the platform, and my specialists do the work.', 'Next'],
    ['Finding your way', 'Your menu', 'This is your menu. Tap any item and I’ll tell you what it is for. The first time you open a page, I’ll show you around it.', 'Next'],
    ['What powers it', 'The engines', 'Behind every plan are the engines we work with: a chatbot for your visitors, Clients for every lead, a calendar for your schedule, and more.', 'Next'],
    ['Ready', 'Let’s grow.', 'That’s the tour. I’ll be in the corner of every page whenever you need me. Let’s start your first campaign.', 'Start with Sarah']
  ];
  /* TOUR-TIER-1: the same tour on a plan without Sarah and the team says what the plan does today and what AI Lite adds */
  var CH_NO_AI = {
    1: ['Meet your manager', 'I’m Sarah.', 'I’m Sarah, your Digital Marketing Manager. Your plan runs the website you own today. When you add the AI team, from AI Lite, I plan your marketing and my team creates it, with your OK first.', 'Next'],
    2: ['How it works', 'A week with the team', 'This is what a normal week looks like once the AI team is on: my specialists do the hands-on work, and I keep it moving on schedule.', 'Next'],
    4: ['Who does what', 'Meet the team', 'Arthur looks after your website and Aria answers any question about the platform, both on your plan now. My specialists join you from AI Lite.', 'Next'],
    6: ['What powers it', 'The engines', 'On your plan today: your website, Clients for every lead, a calendar for your schedule, and hosting. The website chatbot, content and social join from AI Lite.', 'Next'],
    7: ['Ready', 'Let’s grow.', 'That’s the tour. Your website is ready, and Arthur is here for any change. When you want me and the team working on your growth, choose AI Lite or above.', 'See the plans']
  };
  var MENU = [
    ['Sarah', 'sarah', 'Talk to me here, in plain words. Every update and every question from me lands in this thread.'],
    ['Needs attention', 'attention', 'Anything waiting on your OK, one closed card each. Open a card to approve or decline.'],
    ['Campaigns', 'campaigns', 'Your growth plan as dated steps: what is done, what is next, and what is waiting on you.'],
    ['Websites', 'websites', 'Your website and Arthur, your builder. Change anything, add pages, connect your own domain.'],
    ['Clients', 'clients', 'Every lead and customer in one place, with where they came from and what they asked.'],
    ['Settings', 'settings', 'Your business profile, brand, plan and credits, and how I reach you.'],
    ['Aria', 'aria', 'Aria answers any question about how the platform works, any time.'],
    ['Hosting', 'hosting', 'Your domains, hosting and business email.'],
    ['Advanced tools →', 'advanced', 'The Command Center: every engine, every specialist and every report, when you want the detail.']
  ];

  function stage(i) {
    var site = esc(S.siteName || 'Your business');
    var host = esc((S.siteUrl || '').replace(/^https?:\/\//, '').replace(/\/$/, '') || 'your website');
    if (i === 0) return '' +
      '<div class="glass a-rise" style="position:absolute;left:10px;top:6px;width:330px;height:236px;border-radius:22px;overflow:hidden">' +
        '<div style="height:40px;display:flex;align-items:center;gap:8px;padding:0 12px;border-bottom:1px solid var(--hair)">' +
          '<div style="display:flex;gap:5px"><span style="width:8px;height:8px;border-radius:50%;background:var(--hair)"></span><span style="width:8px;height:8px;border-radius:50%;background:var(--hair)"></span><span style="width:8px;height:8px;border-radius:50%;background:var(--hair)"></span></div>' +
          '<div style="flex:1;min-width:0;height:24px;border-radius:999px;background:var(--tint);font-size:10px;font-weight:600;color:var(--ink2);display:block;line-height:24px;padding:0 10px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis">' + host + '</div>' +
          '<div style="display:flex;align-items:center;gap:5px;height:22px;padding:0 8px;border-radius:999px;background:var(--oksoft);color:var(--ok);font-size:11px;font-weight:700"><span style="width:7px;height:7px;border-radius:50%;background:var(--ok);animation:lsiLive 1.8s ease-in-out infinite"></span>Live</div>' +
        '</div>' +
        '<div style="position:relative;height:196px;background:linear-gradient(160deg,#1B2B3A 0%,#2E4A5C 45%,#6A45E4 140%);display:flex;flex-direction:column;justify-content:flex-end;padding:18px">' +
          '<div style="position:absolute;right:18px;top:16px;display:flex;gap:12px;font-size:10px;color:rgba(255,255,255,.75);font-weight:600"><span>Home</span><span>About</span><span>Contact</span></div>' +
          '<div style="font-family:Georgia,serif;font-size:28px;line-height:1.08;color:#fff;max-width:280px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">' + site + '</div>' +
          '<div style="margin-top:12px;width:108px;height:30px;border-radius:999px;background:#fff;color:#1B2B3A;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center">Get in touch</div>' +
        '</div>' +
      '</div>' +
      '<div class="glass a-rise" style="' + d(.5) + ';position:absolute;left:30px;top:256px;width:290px;border-radius:18px;padding:12px 14px;display:flex;gap:10px;align-items:center">' + logoAv(34) +
        '<div style="display:flex;flex-direction:column;gap:2px"><div style="font-size:12px;font-weight:700;color:var(--ink)">Arthur <span style="font-weight:500;color:var(--ink3)">· Website builder</span></div><div style="font-size:13px;color:var(--ink2)">Published. Your website is live.</div></div></div>' +
      '<div style="position:absolute;left:0;top:0;width:350px;height:330px;border-radius:24px;background:var(--scrim);animation:lsiFrost 1s cubic-bezier(.6,0,.2,1) 1.4s both" class="lsi-frost"></div>' +
      '<div class="a-pop" data-over="frost" style="' + d(2.2) + ';position:absolute;left:115px;top:44px;display:flex;flex-direction:column;align-items:center;gap:12px">' + av('sarah', 120) +
        '<div class="thick" style="height:32px;padding:0 14px;border-radius:999px;display:flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--ink);white-space:nowrap"><span style="width:7px;height:7px;border-radius:50%;background:var(--ok)"></span>Sarah is joining you</div></div>';

    if (i === 1) {
      var steps = [['1', 'I plan', 'your growth, step by step'], ['2', 'We create', 'posts, articles, images'], ['3', 'You approve', 'your OK comes first', true], ['4', 'I publish', 'on schedule, everywhere it belongs'], ['5', 'I report', 'what worked, plainly']];
      return '<div style="position:absolute;left:0;top:0;width:350px;display:flex;flex-direction:column;align-items:center">' +
        '<div style="position:relative;width:112px;height:112px" class="a-pop">' +
          '<svg width="112" height="112" viewBox="0 0 112 112" style="position:absolute;inset:0" aria-hidden="true"><defs><linearGradient id="lsi-rg1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8C25D2"></stop><stop offset=".55" stop-color="#4C86DE"></stop><stop offset="1" stop-color="#3FDFDF"></stop></linearGradient></defs>' +
          '<circle cx="56" cy="56" r="53" fill="none" stroke="url(#lsi-rg1)" stroke-width="3" stroke-linecap="round" stroke-dasharray="540" style="animation:lsiDraw 1.6s cubic-bezier(.6,0,.2,1) .2s both;transform:rotate(-90deg);transform-origin:50% 50%"></circle></svg>' +
          '<img src="' + IMG + 'sarah.webp" alt="Sarah" style="position:absolute;left:9px;top:9px;width:94px;height:94px;border-radius:50%;object-fit:cover">' +
        '</div>' +
        '<div class="a-rise" style="' + d(.4) + ';margin-top:6px;font-size:28px;font-weight:800;letter-spacing:-.6px;color:var(--ink)">Sarah</div>' +
        '<div class="a-rise gt" style="' + d(.5) + ';font-size:15px;font-weight:700">Digital Marketing Manager</div>' +
        '<div style="position:relative;margin-top:14px;width:330px;display:flex;flex-direction:column;gap:5px">' +
          '<div style="position:absolute;left:17px;top:18px;width:2px;height:160px;background:linear-gradient(180deg,#8C25D2,#4C86DE,#3FDFDF);opacity:.45;transform-origin:top;animation:lsiGrow 1.6s cubic-bezier(.6,0,.2,1) .7s both"></div>' +
          steps.map(function (s, k) {
            return s[3]
              ? '<div class="thick a-slide" style="' + d(.8 + k * .22) + ';position:relative;height:40px;border-radius:14px;display:flex;align-items:center;gap:10px;padding:0 10px 0 6px;box-shadow:0 0 0 1.5px #8A3BEA,0 8px 22px -8px rgba(107,59,223,.55)">' +
                '<span style="width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,#8C25D2,#4C86DE);color:#fff;display:flex;align-items:center;justify-content:center">' + ico('check', 14) + '</span>' +
                '<span style="font-size:14px;font-weight:800;color:var(--ink);white-space:nowrap">' + s[1] + '</span><span style="font-size:12px;color:var(--ink2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0">' + s[2] + '</span>' +
                '<span class="chip" style="margin-left:auto;height:20px;font-size:10px;padding:0 7px;flex-shrink:0">You</span></div>'
              : '<div class="glass a-slide" style="' + d(.8 + k * .22) + ';position:relative;height:34px;border-radius:12px;display:flex;align-items:center;gap:10px;padding:0 10px 0 6px">' +
                '<span style="width:24px;height:24px;border-radius:50%;background:var(--raised);border:1px solid var(--hair);color:var(--acc);font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">' + s[0] + '</span>' +
                '<span style="font-size:13.5px;font-weight:700;color:var(--ink);white-space:nowrap">' + s[1] + '</span><span style="font-size:12px;color:var(--ink3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0">' + s[2] + '</span></div>';
          }).join('') +
        '</div></div>';
    }

    if (i === 2) {
      var rows = [
        ['Mon', av('james', 34), 'SEO check of your new website', 'James · SEO'],
        ['Tue', av('priya', 34), 'An article on your top keyword', 'Priya · Writing'],
        ['Wed', av('marcus', 34), 'A week of social posts', 'Marcus · Social'],
        ['Thu', '<div style="width:34px;height:34px;border-radius:50%;background:var(--tint);color:var(--acc);display:flex;align-items:center;justify-content:center;flex-shrink:0">' + ico('chat', 17) + '</div>', 'Your chatbot answers visitors', 'Chatbot · all week'],
        ['Fri', av('sarah', 34), 'Your weekly report', 'Sarah · what worked']
      ];
      return '<div style="position:absolute;left:0;top:0;width:350px">' +
        '<div class="a-fade" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px"><span class="chip">Preview of a typical week</span>' +
          '<div style="position:relative;width:90px;height:4px;border-radius:4px;background:var(--hair);overflow:hidden"><div style="position:absolute;left:0;top:0;width:40%;height:100%;border-radius:4px;background:linear-gradient(90deg,#8C25D2,#4C86DE,#3FDFDF);animation:lsiScan 1.6s ease-in-out infinite"></div></div></div>' +
        '<div style="position:relative;display:flex;flex-direction:column;gap:8px">' +
          rows.map(function (r, k) {
            return '<div class="glass a-slide" style="' + d(.25 + k * .32) + ';position:relative;height:58px;border-radius:16px;display:flex;align-items:center;gap:10px;padding:0 12px">' +
              '<span style="width:30px;font-size:12px;font-weight:700;color:var(--ink3)">' + r[0] + '</span>' + r[1] +
              '<div style="display:flex;flex-direction:column;gap:1px;min-width:0;flex:1"><span style="font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + r[2] + '</span><span style="font-size:11px;color:var(--ink3)">' + r[3] + '</span></div></div>';
          }).join('') +
        '</div></div>';
    }

    if (i === 3) return '<div style="position:absolute;left:0;top:0;width:350px;height:390px">' +
      '<div class="a-fade" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;color:var(--ink)">' + ico('attention', 18, 'color:var(--warn)') + '<span style="font-size:15px;font-weight:700">Needs your OK</span><span style="margin-left:auto;min-width:22px;height:22px;border-radius:999px;background:var(--warnsoft);color:var(--warn);font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center">1</span></div>' +
      '<div style="position:relative;height:330px">' +
        '<div class="glass" style="position:absolute;left:0;top:0;width:350px;height:76px;border-radius:18px;padding:0 14px;display:flex;align-items:center;gap:12px;animation:lsiSeqA 6s ease .3s both">' + av('marcus', 40) +
          '<div style="display:flex;flex-direction:column;gap:2px;flex:1"><span style="font-size:14px;font-weight:700;color:var(--ink)">Social week · 7 posts</span><span style="font-size:12px;color:var(--ink3)">Marcus · ready for your OK</span></div><span style="color:var(--ink3)">' + ico('chev', 18) + '</span>' +
          '<span style="position:absolute;left:160px;top:22px;width:34px;height:34px;border-radius:50%;background:rgba(205,184,255,.55);animation:lsiTap .9s ease-out 1.9s both"></span></div>' +
        '<div class="thick" style="position:absolute;left:0;top:0;width:350px;border-radius:20px;padding:14px;display:flex;flex-direction:column;gap:12px;animation:lsiSeqB 6s ease .3s both">' +
          '<div style="display:flex;align-items:center;gap:10px">' + av('marcus', 36) + '<div style="display:flex;flex-direction:column"><span style="font-size:14px;font-weight:700;color:var(--ink)">Social week · 7 posts</span><span style="font-size:12px;color:var(--ink3)">Monday to Sunday, one a day</span></div></div>' +
          '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px"><div style="height:96px;border-radius:12px;background:linear-gradient(160deg,#2E4A5C,#C9A27A)"></div><div style="height:96px;border-radius:12px;background:linear-gradient(160deg,#1B2B3A,#4C86DE)"></div><div style="height:96px;border-radius:12px;background:linear-gradient(160deg,#6A45E4,#3FDFDF)"></div></div>' +
          '<div style="font-size:12px;color:var(--ink2);line-height:1.5">“This week at ' + site + '…” and six more.</div>' +
          '<div style="display:flex;gap:8px"><span class="btn-ghost" style="flex:1;height:44px;font-size:14px">Decline</span><span class="btn-go" style="position:relative;flex:1.4;height:44px;font-size:14px">Approve<span style="position:absolute;left:50%;top:5px;width:34px;height:34px;margin-left:-17px;border-radius:50%;background:rgba(255,255,255,.55);animation:lsiTap .9s ease-out 4.2s both"></span></span></div></div>' +
        '<div class="glass" style="position:absolute;left:0;top:0;width:350px;border-radius:18px;padding:18px 16px;display:flex;align-items:center;gap:14px;animation:lsiSeqC 6s ease .3s both">' +
          '<div style="width:44px;height:44px;border-radius:50%;background:var(--oksoft);color:var(--ok);display:flex;align-items:center;justify-content:center;flex-shrink:0">' + ico('check', 22) + '</div>' +
          '<div style="display:flex;flex-direction:column;gap:3px"><span style="font-size:15px;font-weight:700;color:var(--ink)">Approved</span><span style="font-size:12px;color:var(--ink2)">Scheduled from Monday. I’ll tell you how it does.</span></div></div>' +
        '<div class="a-rise" style="' + d(5.6) + ';position:absolute;left:0;top:104px;width:350px;display:flex;flex-direction:column;gap:10px">' +
          '<div style="font-size:12px;font-weight:700;color:var(--ink3);letter-spacing:.4px">OR JUST SAY IT</div>' +
          '<div style="align-self:flex-end;max-width:250px;padding:10px 14px;border-radius:18px 18px 4px 18px;background:linear-gradient(180deg,#8A3BEA,#6A45E4);color:#fff;font-size:13px">Looks good, go ahead.</div>' +
          '<div style="display:flex;gap:8px;align-items:flex-end">' + av('sarah', 28) + '<div class="glass" style="max-width:250px;padding:10px 14px;border-radius:18px 18px 18px 4px;color:var(--ink);font-size:13px">Done. Your week starts Monday.</div></div></div>' +
      '</div></div>';

    if (i === 4) {
      var team = [['logo', 'Arthur', 'Your website', 150, 0], ['aria', 'Aria', 'Platform help', 272, 92], ['marcus', 'Marcus', 'Social', 236, 262], ['priya', 'Priya', 'Writing', 64, 262], ['james', 'James', 'SEO', 28, 92]];
      return '<div style="position:absolute;left:0;top:0;width:350px;height:390px">' +
        '<svg width="350" height="390" viewBox="0 0 350 390" style="position:absolute;inset:0" aria-hidden="true"><defs><linearGradient id="lsi-tl" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8C25D2"></stop><stop offset="1" stop-color="#3FDFDF"></stop></linearGradient></defs>' +
          team.map(function (t, k) { return '<line x1="175" y1="190" x2="' + (t[3] + 25) + '" y2="' + (t[4] + 25) + '" stroke="url(#lsi-tl)" stroke-width="1.5" stroke-dasharray="200" opacity=".6" style="animation:lsiLine 1s ease ' + (.6 + k * .18).toFixed(2) + 's both"></line>'; }).join('') + '</svg>' +
        '<div class="a-pop" style="position:absolute;left:127px;top:142px;display:flex;flex-direction:column;align-items:center">' + av('sarah', 96) + '<span class="thick" style="margin-top:8px;height:22px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:700;color:var(--ink);display:flex;align-items:center;position:relative">Sarah leads</span></div>' +
        team.map(function (t, k) {
          return '<div class="a-pop" style="' + d(.9 + k * .18) + ';position:absolute;left:' + (t[3] - 20) + 'px;top:' + t[4] + 'px;width:90px;display:flex;flex-direction:column;align-items:center;gap:4px">' +
            (t[0] === 'logo' ? logoAv(52) : av(t[0], 52)) +
            '<span style="font-size:13px;font-weight:700;color:var(--ink)">' + t[1] + '</span><span style="font-size:11px;color:var(--ink3);margin-top:-3px">' + t[2] + '</span></div>';
        }).join('') + '</div>';
    }

    if (i === 5) return '<div style="position:absolute;left:0;top:0;width:350px;height:390px">' +
      '<div class="a-fade" style="font-size:12px;font-weight:600;color:var(--ink3);margin-bottom:8px">Tap any item</div>' +
      '<div class="glass a-rise lsi-menu" style="border-radius:20px;padding:6px;display:flex;flex-direction:column">' + menuHtml() + '</div></div>';

    if (i === 6) {
      function tile(delay, icon, name, body) { return '<div class="glass a-rise" style="' + d(delay) + ';position:relative;height:120px;border-radius:18px;padding:12px;overflow:hidden;display:flex;flex-direction:column;gap:8px"><div style="display:flex;align-items:center;gap:7px;color:var(--acc)">' + ico(icon, 16) + '<span style="font-size:13px;font-weight:700;color:var(--ink)">' + name + '</span></div>' + body + '</div>'; }
      var cal = ''; for (var c = 0; c < 14; c++) { cal += '<span style="height:12px;border-radius:4px;background:var(--hair);display:flex;align-items:center;justify-content:center">' + ([2, 5, 9, 12].indexOf(c) >= 0 ? '<span class="a-pop" style="' + d(1 + c * .08) + ';width:5px;height:5px;border-radius:50%;background:' + (c === 9 ? '#3FDFDF' : '#8A3BEA') + '"></span>' : '') + '</span>'; }
      return '<div style="position:absolute;left:0;top:0;width:350px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">' +
        tile(.1, 'chat', 'Chatbot', '<div style="display:flex;flex-direction:column;gap:5px"><div style="align-self:flex-start;padding:5px 9px;border-radius:10px 10px 10px 3px;background:var(--tint);font-size:10.5px;color:var(--ink)">Are you open on Sunday?</div><div style="align-self:flex-end;display:flex;gap:3px;padding:7px 9px;border-radius:10px 10px 3px 10px;background:linear-gradient(180deg,#8A3BEA,#6A45E4)"><span style="width:5px;height:5px;border-radius:50%;background:#fff;animation:lsiDot 1.2s infinite"></span><span style="width:5px;height:5px;border-radius:50%;background:#fff;animation:lsiDot 1.2s .15s infinite"></span><span style="width:5px;height:5px;border-radius:50%;background:#fff;animation:lsiDot 1.2s .3s infinite"></span></div></div>') +
        tile(.22, 'clients', 'Clients', '<div class="a-slide" style="' + d(1) + ';display:flex;align-items:center;gap:7px;padding:7px;border-radius:10px;background:var(--tint)"><span style="width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,#8C25D2,#3FDFDF);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center">JL</span><div style="display:flex;flex-direction:column"><span style="font-size:11px;font-weight:700;color:var(--ink)">New lead</span><span style="font-size:10px;color:var(--ink3)">from your chatbot</span></div></div>') +
        tile(.34, 'calendar', 'Calendar', '<div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px">' + cal + '</div>') +
        tile(.46, 'social', 'Social', '<div style="display:flex;gap:6px">' + ['#2E4A5C,#C9A27A', '#1B2B3A,#4C86DE', '#6A45E4,#3FDFDF'].map(function (g, k) { return '<span class="a-pop" style="' + d(1 + k * .2) + ';width:44px;height:44px;border-radius:9px;background:linear-gradient(160deg,' + g + ')"></span>'; }).join('') + '</div>') +
        tile(.58, 'seo', 'SEO', '<div style="display:flex;align-items:flex-end;gap:6px;height:50px">' + [16, 24, 30, 40, 50].map(function (h, k) { return '<span style="width:16px;height:' + h + 'px;border-radius:5px 5px 2px 2px;background:linear-gradient(180deg,#3FDFDF,#6A45E4);transform-origin:bottom;animation:lsiGrow .7s cubic-bezier(.2,.8,.2,1) ' + (1 + k * .12).toFixed(2) + 's both"></span>'; }).join('') + '</div>') +
        tile(.7, 'studio', 'Studio', '<div style="position:relative;height:52px;border-radius:10px;overflow:hidden;background:linear-gradient(160deg,#2E4A5C,#C9A27A)"><span style="position:absolute;top:0;left:0;width:40%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.45),transparent);animation:lsiShimmer 2.2s ease-in-out 1s infinite"></span></div>') +
        '</div>';
    }

    /* 7 */
    return '<div style="position:absolute;left:0;top:0;width:350px;display:flex;flex-direction:column;align-items:center">' +
      '<div class="a-pop">' + av('sarah', 88) + '</div>' +
      '<div class="a-rise" style="' + d(.3) + ';margin-top:12px;font-size:28px;font-weight:800;letter-spacing:-.6px;line-height:1.1;text-align:center;color:var(--ink)">Let’s grow<br><span class="gt">' + site + '</span></div>' +
      '<div class="glass a-rise" style="' + d(.6) + ';margin-top:16px;width:330px;border-radius:20px;padding:8px 14px;display:flex;flex-direction:column">' +
        (S.noAI ? ['Your website is live', 'Arthur is on hand for any change', 'You know your way around', 'Sarah and the team join from AI Lite'] : ['Your website is live', 'Your team is ready', 'You know your way around', 'Your first campaign is next'])   /* TOUR-TIER-1 */.map(function (t, k) {
          return '<div style="height:44px;display:flex;align-items:center;gap:10px;' + (k < 3 ? 'border-bottom:1px solid var(--hair);' : '') + '"><span class="a-pop" style="' + d(.9 + k * .2) + ';width:24px;height:24px;border-radius:50%;background:' + (k < 3 ? 'var(--oksoft)' : 'var(--tint)') + ';color:' + (k < 3 ? 'var(--ok)' : 'var(--acc)') + ';display:flex;align-items:center;justify-content:center">' + (k < 3 ? ico('check', 14) : ico('arrow', 14)) + '</span><span style="font-size:14px;font-weight:600;color:var(--ink)">' + t + '</span></div>';
        }).join('') + '</div></div>';
  }

  function menuHtml() {
    return MENU.map(function (m, k) {
      var sel = S.pick === k;
      var icon = m[1] === 'sarah' ? '<img src="' + IMG + 'sarah.webp" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover">' : ico(m[1], 18);
      return '<button type="button" data-pick="' + k + '" aria-expanded="' + sel + '" style="height:34px;border:0;border-radius:11px;display:flex;align-items:center;gap:12px;padding:0 10px;cursor:pointer;font-size:13.5px;font-weight:' + (sel ? 700 : 500) + ';color:var(--ink);background:' + (sel ? 'var(--tint)' : 'transparent') + ';box-shadow:' + (sel ? 'inset 0 0 0 1px var(--tintrim)' : 'none') + ';text-align:left">' +
        '<span style="width:22px;height:22px;display:flex;align-items:center;justify-content:center;color:' + (sel ? 'var(--acc)' : 'var(--ink2)') + '">' + icon + '</span><span style="flex:1">' + esc(m[0]) + '</span></button>' +
        (sel ? '<div class="a-rise" style="margin:2px 6px 6px 38px;font-size:12.5px;line-height:1.45;color:var(--ink2)">' + esc(m[2]) + '</div>' : '');
    }).join('');
  }

  /* ───────────── the wizard ───────────── */
  /* The scene is sized by what it really holds (a menu with an item open is taller than a team diagram), measured with
     the scale taken off, then scaled to fill its box and centred; nothing may reach Sarah's panel. */
  function contentHeight(st) {
    /* layout boxes (offsetTop/offsetHeight), never rects: a card still popping in at 60 % must count at its full size */
    var max = 0, els = st.querySelectorAll('*');
    for (var i = 0; i < els.length; i++) {
      var el = els[i]; if (!(el instanceof HTMLElement) || !el.offsetHeight) continue;
      var top = 0, n = el; while (n && n !== st) { top += n.offsetTop; n = n.offsetParent; }
      if (n !== st) continue;
      if (top + el.offsetHeight > max) max = top + el.offsetHeight;
    }
    return Math.max(200, Math.ceil(max + 16));
  }
  function fit() {
    var root = document.getElementById('lsi'); if (!root) return;
    var box = root.querySelector('.lsi-fit'), st = root.querySelector('.lsi-stage'); if (!box || !st) return;
    var w = box.clientWidth, h = box.clientHeight, ch = contentHeight(st);
    st.style.height = ch + 'px';
    var sc = Math.min(w / 350, h / ch, phone() ? (window.innerWidth < 600 ? 1.15 : 1.7) : 2.1); if (!(sc > 0)) sc = 1;
    st.style.transform = 'translateX(-50%) scale(' + sc.toFixed(3) + ')';
    st.style.marginTop = Math.max(0, (h - ch * sc) / 2) + 'px';
  }

  /* the app's own phone styles (touch-target heights, the type scale) land a moment after a chapter is drawn: measure again */
  function refit() { fit(); [60, 250, 700, 1500, 3000].forEach(function (t) { setTimeout(fit, t); }); }

  function render() {
    var root = document.getElementById('lsi'); if (!root) return;
    var c = CH[S.step].map(function (t) { return typeof t === 'string' ? t.replace('{first}', S.first ? ', ' + S.first : '') : t; }), n = CH.length;   // PLATFORM-6: by name, never "Boss"
    root.querySelector('.lsi-count').textContent = (S.step + 1) + ' / ' + n;
    var segs = root.querySelectorAll('.lsi-segs span');
    for (var k = 0; k < segs.length; k++) { segs[k].className = k <= S.step ? ('on' + (k < S.step ? ' past' : '')) : ''; }
    root.querySelector('.lsi-eyebrow').textContent = (phone() ? '' : 'Chapter ' + (S.step + 1) + ' · ') + c[0];
    root.querySelector('.lsi-title').textContent = c[1];
    root.querySelector('.lsi-stage').innerHTML = stage(S.step);
    root.querySelector('.lsi-say').innerHTML = c[2].split(' ').map(function (w, k) { return '<span class="w" style="animation-delay:' + (0.15 + k * 0.045).toFixed(2) + 's">' + esc(w) + '</span>'; }).join('');
    root.querySelector('.lsi-say').setAttribute('aria-label', c[2]);
    var toc = root.querySelector('.lsi-toc');
    toc.innerHTML = CH.map(function (ch, k) { return '<button type="button" data-jump="' + k + '" class="' + (k === S.step ? 'cur' : k < S.step ? 'past' : '') + '"' + (k > S.step ? ' disabled' : '') + '><i>' + (k < S.step ? '✓' : (k + 1)) + '</i>' + esc(ch[1]) + '</button>'; }).join('');
    var back = root.querySelector('.lsi-back'); back.style.visibility = S.step > 0 ? 'visible' : 'hidden';
    var next = root.querySelector('.lsi-next'); next.innerHTML = esc(c[3]) + ico('arrow', 18);
    refit();
    try { next.focus({ preventScroll: true }); } catch (e) {}
  }

  function go(i) {
    var n = CH.length; i = Math.max(0, Math.min(n - 1, i)); if (i === S.step && document.querySelector('#lsi .lsi-stage').innerHTML) return;
    S.step = i; render();
    putPrefs({ sarah_intro: { version: VERSION, last_step: i, device: phone() ? 'mobile' : 'desktop' } });
  }

  function finish() {
    putPrefs({ sarah_intro: { version: VERSION, last_step: CH.length - 1, done_at: new Date().toISOString(), device: phone() ? 'mobile' : 'desktop' } });
    if (!S.prefs) S.prefs = {}; S.prefs.sarah_intro = { version: VERSION, done_at: new Date().toISOString() };
    close();
    /* After a reload the editor can be reopened late (the /app/websites/{id} deep link and lu-back's ?edit= restore both
       open it), so for a few seconds any editor that appears is closed again; then Campaigns and its bubble. */
    var until = Date.now() + 12000, navd = false;
    try { history.replaceState(history.state, '', location.pathname.replace(/\/websites\/\d+.*$/, '/websites')); } catch (e) {}
    (function sweep() {
      var p = (editorOpen() && typeof window.wsCloseTemplateEditor === 'function') ? Promise.resolve(window.wsCloseTemplateEditor({ silent: true, force: true })).catch(function () {}) : Promise.resolve();
      p.then(function () {
        /* TOUR-TIER-1: without the AI team the tour ends on the plans, not on Campaigns (which only Sarah fills) */
        if (!navd) { navd = true; try { if (typeof window.nav === 'function') window.nav(S.noAI ? 'billing' : 'projects'); } catch (e) {} }
        else if (!S.noAI && !editorOpen() && pageKey(curView()) === 'projects' && !S.seen.projects && !document.getElementById('lsi-bubble')) showBubble('projects');
        if (Date.now() < until) setTimeout(sweep, 700);
      });
    })();
  }

  function onKey(e) {
    if (!document.getElementById('lsi')) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopImmediatePropagation(); return; }   /* no Skip on the first run (Owner: every user goes through it) */
    var t = e.target; if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return;
    if (e.key === 'ArrowRight') { e.preventDefault(); if (S.step < CH.length - 1) go(S.step + 1); }
    if (e.key === 'ArrowLeft') { e.preventDefault(); go(S.step - 1); }
  }

  function close() {
    var r = document.getElementById('lsi'); if (r) r.remove();
    S.running = false;
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('resize', fit);
    try { document.documentElement.classList.remove('lsi-open'); } catch (e) {}
  }

  function start(fromStep) {
    if (document.getElementById('lsi')) return;
    css();
    S.running = true; S.step = Math.max(0, Math.min(CH.length - 1, fromStep || 0));
    var root = document.createElement('div');
    root.id = 'lsi'; root.setAttribute('data-lu-nostrip', ''); root.setAttribute('role', 'dialog');   /* lu-responsive.js turns scaled grids into strips otherwise */ root.setAttribute('aria-modal', 'true'); root.setAttribute('aria-labelledby', 'lsi-title');
    root.innerHTML =
      '<header class="lsi-top"><img src="' + LOGO + '" alt=""><b>LevelUpGrowth</b><span class="lsi-count"></span></header>' +
      '<div class="lsi-segs" aria-hidden="true">' + CH.map(function () { return '<span></span>'; }).join('') + '</div>' +
      '<div class="lsi-main">' +
        '<section class="lsi-scene"><span class="lsi-eyebrow"></span><h1 class="lsi-title" id="lsi-title"></h1><div class="lsi-fit"><div class="lsi-stage"></div></div></section>' +
        '<aside class="lsi-talk thick"><div class="lsi-who">' + av('sarah', 40) + '<div><b>Sarah</b><span>Digital Marketing Manager</span></div></div>' +
          '<p class="lsi-say" aria-live="polite"></p><nav class="lsi-toc" aria-label="Chapters"></nav>' +
          '<div class="lsi-btns"><button type="button" class="btn-ghost lsi-back" aria-label="Back">' + ico('back', 20) + '<span class="lbl">Back</span></button><button type="button" class="btn-go lsi-next"></button></div>' +
        '</aside>' +
      '</div>';
    document.body.appendChild(root);
    try { document.documentElement.classList.add('lsi-open'); } catch (e) {}
    root.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('button') : null; if (!t || !root.contains(t)) return;
      if (t.classList.contains('lsi-next')) { if (S.step >= CH.length - 1) finish(); else go(S.step + 1); return; }
      if (t.classList.contains('lsi-back')) { go(S.step - 1); return; }
      if (t.hasAttribute('data-jump')) { var j = +t.getAttribute('data-jump'); if (j <= S.step) go(j); return; }
      if (t.hasAttribute('data-pick')) { S.pick = +t.getAttribute('data-pick'); var m = root.querySelector('.lsi-menu'); if (m) m.innerHTML = menuHtml(); refit(); var b = root.querySelector('[data-pick="' + S.pick + '"]'); if (b) try { b.focus({ preventScroll: true }); } catch (_f) {} }
    });
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('resize', fit);
    render();
    putPrefs({ sarah_intro: { version: VERSION, last_step: S.step, device: phone() ? 'mobile' : 'desktop' } });
    try { if (window.PlatformEvents && PlatformEvents.track) PlatformEvents.track('sarah.intro.started', { step: S.step }); } catch (e) {}
  }

  /* ───────────── page bubbles ───────────── */
  var PAGES = {
    attention: ['Needs attention', 'This is Needs attention. Anything waiting on your OK sits here as one closed card. Open a card to see it, then approve or decline.'],
    projects: ['Campaigns', 'This is Campaigns. Every plan I run lives here as dated steps, so you always see what is done, what is next and what waits on you.'],
    websites: ['Websites', 'This is Websites. Your website lives here, with Arthur beside it. Open it to change anything, add pages or connect your own domain.'],
    crm: ['Clients', 'This is Clients. Every lead from your website lands here, with where they came from and what they asked.'],
    settings: ['Settings', 'This is Settings: your business profile, your brand, your plan and credits, and how I reach you.'],
    aria: ['Aria', 'This is Aria. Ask her anything about how the platform works, any time. For your marketing, come to me.'],
    infrastructure: ['Hosting', 'This is Hosting: your domains, your hosting and your business email, in one place.'],
    calendar: ['Calendar', 'This is your Calendar, for your own schedule: bookings, meetings and reminders. My publishing plan stays in Campaigns.'],
    chatbot: ['Chatbot', 'This is your Chatbot. It answers visitors on your website day and night, and every contact it collects goes to Clients.'],
    seo: ['SEO', 'This is SEO. James checks your website and finds the searches you can win. I turn what he finds into steps in your campaigns.'],
    write: ['Write', 'This is Write, where Priya drafts your articles. Anything meant for your website comes to you for your OK first.'],
    studio: ['Studio', 'This is Studio, where images and designs are made for your posts and pages, in your brand.'],
    social: ['Social', 'This is Social: your connected accounts and every post, planned or published. Posts go out only after your OK.'],
    command: ['Command Center', 'This is the Command Center: everything your team is doing, in detail. You never need it to run your business, but it is all here.'],
    meeting: ['Strategy Room', 'This is the Strategy Room, where I think your plan through with the team. You can read every discussion.'],
    agents: ['Agents', 'These are the specialists who work with me. Each one has a role, and I decide who does what.']
  };
  var ALIAS = { approvals: 'attention', marketing: 'projects', campaigns: 'projects', website: 'websites', customers: 'crm', account: 'settings', billing: 'settings', workspace: 'command', reports: 'meeting' };

  function curView() { try { return typeof currentView !== "undefined" ? currentView : null; } catch (e) { return null; } }
  function pageKey(v) { v = String(v || '').toLowerCase(); return PAGES[v] ? v : (ALIAS[v] || null); }

  function hideBubble(mark) {
    var b = document.getElementById('lsi-bubble'); if (b) b.remove();
    S.bubbleKey = null;
  }

  function placeBubble(b) {
    var fl = document.getElementById('lu-messages-floater');
    var r = null; try { if (fl && getComputedStyle(fl).display !== 'none' && getComputedStyle(fl).visibility !== 'hidden') { r = fl.getBoundingClientRect(); if (!(r.width > 0)) r = null; } } catch (e) {}
    var vw = window.innerWidth, vh = window.innerHeight;
    if (r && r.width) {
      b.style.right = Math.max(12, vw - r.right) + 'px';
      b.style.bottom = Math.max(12, vh - r.top + 14) + 'px';
    } else { b.style.right = '16px'; b.style.bottom = '20px'; }
    if (vw < 480) { b.style.left = '12px'; b.style.right = '12px'; b.style.width = 'auto'; }
  }

  function showBubble(key) {
    if (!PAGES[key] || S.seen[key] || S.running || document.getElementById('lsi')) return;
    if (editorOpen() || lockedToArthur() || document.getElementById('arthur-modal')) return;
    css(); hideBubble();
    S.seen[key] = 1; S.bubbleKey = key;
    putPrefs({ page_intros: [key] });
    var p = PAGES[key];
    var b = document.createElement('div');
    b.id = 'lsi-bubble'; b.setAttribute('data-lu-nostrip', ''); b.setAttribute('role', 'dialog'); b.setAttribute('aria-label', 'Sarah introduces ' + p[0]);
    b.innerHTML = '<div class="lsb-card"><div class="lsb-head">' + av('sarah', 32) + '<b>Sarah</b><span class="chip">' + esc(p[0]) + '</span></div>' +
      '<p>' + esc(p[1]) + '</p>' +
      '<div class="lsb-btns"><button type="button" class="btn-ghost" data-act="ask">Ask Sarah</button><button type="button" class="btn-go" data-act="ok">Got it</button></div>' +
      '<span class="lsb-tail" aria-hidden="true"></span></div>';
    document.body.appendChild(b);
    placeBubble(b);
    b.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('button') : null; if (!t) return;
      var act = t.getAttribute('data-act'); hideBubble();
      if (act === 'ask') { try { if (typeof window.nav === 'function') window.nav('sarah'); } catch (_n) {} }
    });
    try { b.querySelector('[data-act="ok"]').focus({ preventScroll: true }); } catch (e) {}
  }

  function onNav(view) {
    if (!S.booted || !introDone()) return;
    var key = pageKey(view);
    if (S.bubbleKey && S.bubbleKey !== key) hideBubble();
    if (!key || S.seen[key]) return;
    setTimeout(function () { var cur = pageKey(curView()) || key; if (cur === key) showBubble(key); }, 900);
  }

  function hookNav() {
    var o = window.nav;
    if (typeof o !== 'function' || o.__lsi) return;
    var w = function (v) { var r = o.apply(this, arguments); try { onNav(v); } catch (e) {} return r; };
    w.__lsi = true; window.nav = w;
  }

  /* ───────────── first website: publish is the only way out ───────────── */
  function editorOpened(site) {
    if (!S.booted) return;
    if (!firstTimer() || isPub(site)) return;
    var hasPub = (S.sites || []).some(isPub) || (Array.isArray(window.wsSites) && window.wsSites.some(isPub));
    if (hasPub) return;
    document.documentElement.classList.add('lu-first-site');
    css();
    var tries = 0, t = setInterval(function () {
      var bar = document.querySelector('#template-editor-view .pe-bar');
      if (bar && !document.getElementById('lsi-pubhint')) {
        var h = document.createElement('span'); h.id = 'lsi-pubhint'; h.innerHTML = ico('lock', 14) + 'Last step: publish';
        h.title = 'Your first website is published before you move on. Everything else opens after that.';
        bar.insertBefore(h, bar.firstChild);
      }
      if (bar || ++tries > 40) clearInterval(t);
    }, 100);
  }

  function onPublished(e) {
    document.documentElement.classList.remove('lu-first-site');
    var hint = document.getElementById('lsi-pubhint'); if (hint) hint.remove();
    var det = (e && e.detail) || {};
    if (det.url) S.siteUrl = det.url;
    try { var rec = (Array.isArray(window.wsSites) ? window.wsSites : []).filter(function (x) { return x && x.id === det.websiteId; })[0]; if (rec) S.siteName = rec.title || rec.name || S.siteName; } catch (_r) {}
    if (!S.sites) S.sites = []; S.sites.push({ id: det.websiteId, status: 'published' });
    var go2 = function () { if (!introDone()) setTimeout(function () { start(0); }, 1800); };
    if (S.prefs) go2(); else boot().then(go2);
  }

  /* ───────────── boot ───────────── */
  var booting = null;
  function boot() {
    if (booting) return booting;
    if (!tok()) return Promise.resolve();
    booting = Promise.all([getJ('/api/auth/me'), getJ('/api/builder/websites'), getJ('/api/workspace/status')]).then(function (res) {
      /* TOUR-TIER-1: only a definite "no Sarah on this plan" changes the words; an unknown answer keeps the full tour */
      S.noAI = !!(res[2] && res[2].sarah_included === false);
      if (S.noAI) Object.keys(CH_NO_AI).forEach(function (k) { CH[+k] = CH_NO_AI[k]; });
      /* act only on facts: a failed call is not an empty account (a network blip must never send anyone back to Arthur) */
      S.meKnown = !!(res[0] && res[0].user); S.sitesKnown = !!(res[1] && (Array.isArray(res[1]) || Array.isArray(res[1].websites) || Array.isArray(res[1].data)));
      S.me = res[0] || {}; S.prefs = (S.me && S.me.preferences) || {};
      try { S.first = String(((S.me.user && S.me.user.name) || S.me.name || '')).trim().split(/\s+/)[0] || ''; if (/^(boss|admin|test|user)$/i.test(S.first)) S.first = ''; } catch (_fn) { S.first = ''; }   // PLATFORM-6
      var pi = S.prefs.page_intros; if (Array.isArray(pi)) pi.forEach(function (k) { S.seen[k] = 1; });
      S.sites = listOf(res[1]);
      var pubSite = S.sites.filter(isPub)[0];
      if (pubSite) { S.siteName = pubSite.title || pubSite.name || ''; S.siteUrl = pubSite.live_url || pubSite.url || (pubSite.subdomain ? 'https://' + (String(pubSite.subdomain).indexOf('.') > 0 ? pubSite.subdomain : pubSite.subdomain + '.levelupgrowth.io') : ''); }
      if (!S.siteName) { try { var ws = (S.me.workspaces || []).filter(function (w) { return w.id === S.me.current_workspace_id; })[0]; S.siteName = (ws && (ws.business_name || ws.name)) || ''; } catch (_w) {} }
      S.booted = true;
      hookNav();
      decide(0);
    }).catch(function () {});
    return booting;
  }

  function decide(tries) {
    var pubSite = (S.sites || []).filter(isPub)[0];
    {
      /* ARTHUR-LOCK-1 lifts itself when a website exists; wait for it rather than act under it */
      /* FIRST-SCREEN-1: the lock lifts when a website exists - with a published one already known, lift it now instead of polling (it cost ~2 s) */
      if (lockedToArthur() && pubSite && typeof window.__luArthurUnlock === 'function') { try { window.__luArthurUnlock(); } catch (_u) {} }
      if (lockedToArthur()) { if (S.sites.length && tries < 12) setTimeout(function () { decide(tries + 1); }, 700); return; }
      if (!S.meKnown) return;
      if (introDone()) { onNav(curView()); return; }
      if (pubSite) { var t = S.prefs.sarah_intro; start(t && t.last_step ? t.last_step : 0); return; }
      if (!S.meKnown || !S.sitesKnown || !firstTimer()) return;
      if (S.sites.length) {
        /* built but never published: back into the editor, which only lets them out through Publish */
        if (editorOpen()) { editorOpened(S.sites[0]); return; }
        try { if (typeof window.nav === 'function') window.nav('websites', { tail: String(S.sites[0].id) }); } catch (_n) {}
        return;
      }
      /* no website yet (signed up on another device, or the lock was lost): Arthur, locked */
      try { localStorage.setItem('lu_boot_action', 'arthur'); localStorage.setItem('lu_arthur_lock', '1'); } catch (_l) { return; }
      if (!window.__lsiReloaded) { window.__lsiReloaded = true; location.reload(); }
    }
  }

  document.addEventListener('lu:site-published', onPublished);
  var kicked = false;
  function kick() { if (kicked) return; kicked = true; setTimeout(boot, 600); }
  document.addEventListener('lu:bootstrap-complete', kick);
  setTimeout(kick, 5000);

  window.luSarahIntro = { start: function (s) { css(); start(s || 0); }, boot: boot, editorOpened: editorOpened, showBubble: function (k) { delete S.seen[k]; showBubble(k); }, version: VERSION };
})();
