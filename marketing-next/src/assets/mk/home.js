/* GLASS-HOME-2 (2026-09-30): the home's self-playing panels, driven by Motion (vanilla build, served from /assets/mk).
   Ported from the Motion mockup v28 (claude.ai/artifact/DYHZ3BZcM2imQ3AtuVwxBS) minus the mockup chrome. Phones: every panel starts
   when it reaches the middle of the screen; the Arthur build plays once and stays finished. Reduced motion: every state at rest. */
(function () {
  var M = window.Motion;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var phone = function () { return window.innerWidth <= 979; };
  // Owner 09-29: on phones every area starts the moment it reaches the middle of the screen, no waiting for a
  // share of it to be visible. The root is the top half of the viewport, so any part crossing the middle counts.
  // Owner 2026-09-30: no waiting - on phones a block comes in as soon as any part of it is about to enter the screen
  var MID = { amount: 0, margin: '0px 0px 12% 0px' };
  var iv = function (desk) { return phone() ? MID : desk; };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function fx() {}
  if (!$('.lg.mk')) return;
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function show(el, on) { if (el) el.hidden = !on; }

  // aurora: the slow lean toward the mouse (desktop only), nothing moves on its own
  (function () {
    var TAU = 2000, tx = 0, ty = 0, cx = 0, cy = 0, raf = 0, last = 0, el = $('.lg.mk .lg-aurora') || document.createElement('i');
    function frame(now) { var dt = last ? Math.min(now - last, 100) : 16; last = now; var k = 1 - Math.exp(-dt / TAU); cx += (tx - cx) * k; cy += (ty - cy) * k; el.style.setProperty('--ax', cx.toFixed(4)); el.style.setProperty('--ay', cy.toFixed(4)); if (Math.abs(tx - cx) > .0004 || Math.abs(ty - cy) > .0004) raf = requestAnimationFrame(frame); else { raf = 0; last = 0; } }
    window.addEventListener('pointermove', function (e) { if (reduce || (e.pointerType !== 'mouse' && e.pointerType !== 'pen')) return; tx = (e.clientX / window.innerWidth) * 2 - 1; ty = (e.clientY / window.innerHeight) * 2 - 1; if (!raf) raf = requestAnimationFrame(frame); }, { passive: true });
  })();

  if (!M || reduce) return;   // the markup already shows every panel at rest

  // the CSS spring: one easing string from spring(), used by the loop steps and every transition below
  try { var s = String(M.spring(0.4, 0.3)); if (/linear\(/.test(s)) document.documentElement.style.setProperty('--spring-t', s); } catch (e) {}

  // 1. HERO entrance: one sequence, the headline word by word, the CTA on a spring
  var h1 = $('#hero-h1');
  // the headline is animated by the live wipe and glare (CSS), not word by word
  M.animate([
    ['[data-hero="1"]', { opacity: [0, 1], y: [14, 0] }, { duration: .5 }],
    ['[data-hero="3"]', { opacity: [0, 1], y: [16, 0] }, { duration: .55, at: '-0.35' }],
    ['[data-hero="4"]', { opacity: [0, 1], scale: [.92, 1] }, { type: 'spring', bounce: .32, visualDuration: .5, at: '-0.3' }],
    ['[data-hero="5"], [data-hero="6"]', { opacity: [0, 1] }, { duration: .4, at: '-0.2' }]
  ].concat(phone() ? [] : [
    ['[data-hero-v="1"]', { opacity: [0, 1], x: [-24, 0] }, { type: 'spring', bounce: .2, visualDuration: .6, at: 0.25 }],
    ['[data-hero-v="2"]', { opacity: [0, 1], y: [28, 0] }, { type: 'spring', bounce: .2, visualDuration: .6, at: '<0.15' }]
  ]), { defaultTransition: { ease: [.25, .8, .3, 1] } }).then(function () { fx('hero'); });
  // phones: the two hero cards sit below the phone hero, so each enters when it reaches the middle of the screen
  if (phone()) $$('[data-hero-v]').forEach(function (el) { el.style.opacity = 0; M.inView(el, function () { M.animate(el, { opacity: [0, 1], y: [24, 0] }, { type: 'spring', bounce: .2, visualDuration: .5 }); }, MID); });

  // 2. ARTHUR builds a site: typed brief, reply, build, live, then the results — loops while on screen
  (function () {
    var ask = 'A neighbourhood restaurant in Norwich. Twelve dishes, seasonal menu, private hire.';
    var typed = $('#hx-typed'), caret = $('#hx-caret'), askB = $('#hx-ask'), typing = $('#hx-typing'), reply = $('#hx-reply'), liveB = $('#hx-live'),
        img = $('#hx-img'), skel = $('#hx-skel'), stage = $('#hx-stage'), pct = $('#hx-pct'), meter = $('#hx-meter'), says = $$('.hx-say'), sarah = says[0], team = says.slice(1), frost = $('#hx-frost');
    // phones (Owner 09-29): the build plays ONCE; once the reader has passed the section it stays at rest, finished
    var visible = false, running = false, done = false, gen = 0;
    function pop(el) { show(el, true); return M.animate(el, { opacity: [0, 1], y: [8, 0], scale: [.96, 1] }, { type: 'spring', bounce: .3, visualDuration: .4 }); }
    function reset() { typed.textContent = ''; show(caret, true); [askB, typing, reply, liveB].forEach(function (e) { show(e, false); }); says.forEach(function (c) { c.style.opacity = 0; }); frost.style.opacity = 0; img.style.clipPath = 'inset(0 0 100% 0)'; show(skel, true); stage.textContent = 'Waiting for your brief'; pct.textContent = '0'; meter.style.transform = 'scaleX(0)'; }
    function finish() { typed.textContent = ''; show(caret, false); [askB, reply, liveB].forEach(function (e) { show(e, true); e.style.opacity = 1; }); show(typing, false); says.forEach(function (c) { c.style.opacity = 1; }); frost.style.opacity = 1; img.style.clipPath = ''; show(skel, false); stage.textContent = 'Published'; pct.textContent = '100'; meter.style.transform = ''; }
    async function run() {
      var my = ++gen; var live = function () { return my === gen; };
      running = true; fx('arthur', true); reset();
      await M.animate(0, ask.length, { duration: 3.2, ease: 'linear', onUpdate: function (v) { if (live()) typed.textContent = ask.slice(0, Math.round(v)); } });
      if (!live()) return;
      await wait(350); typed.textContent = ''; show(caret, false);
      await pop(askB); await wait(300); show(typing, true); await wait(1200); show(typing, false);
      if (!live()) return;
      await pop(reply); await wait(400);
      var stages = ['Writing the copy', 'Laying out the pages', 'Menu, gallery, booking form'];
      var build = 5.5;
      stages.forEach(function (t, i) { setTimeout(function () { if (running) stage.textContent = t; }, i * build * 333); });
      await Promise.all([
        M.animate(img, { clipPath: ['inset(0 0 100% 0)', 'inset(0 0 0% 0)'] }, { duration: build, ease: 'circOut' }),
        M.animate(meter, { scaleX: [0, 1] }, { duration: build, ease: 'circOut' }),
        M.animate(0, 100, { duration: build, ease: 'circOut', onUpdate: function (v) { pct.textContent = Math.round(v); } })
      ]);
      if (!live()) return;
      show(skel, false); stage.textContent = 'Published';
      await pop(liveB); await wait(500);
      // the team shows up: Sarah first, then the specialists in a random order, each after a random pause, as if talking
      var order = team.slice().sort(function () { return Math.random() - .5; });
      var talk = function (el) { return M.animate(el, { opacity: [0, 1], y: [14, 0], scale: [.92, 1] }, { type: 'spring', bounce: .4, visualDuration: .55 }); };
      [sarah].concat(order).forEach(function (el) { el.parentNode.appendChild(el); });   // the thread reads top to bottom in speaking order
      M.animate(frost, { opacity: [0, 1] }, { duration: .7, ease: 'easeOut' });   // the chat and the site frost over while the team talks
      await wait(250); await talk(sarah);
      for (var i = 0; i < order.length; i++) { await wait(900 + Math.random() * 800); if (!live()) return; await talk(order[i]); }
      fx('arthur', false);
      await wait(4600);
      if (!live()) return;
      // Owner 2026-09-30: no flying tagline. The thread fades, the frost lifts, and the build simply starts again.
      await M.animate(says, { opacity: [1, 0], y: [0, -8] }, { delay: M.stagger(.06), duration: .3 });
      await M.animate(frost, { opacity: [1, 0] }, { duration: .5 });
      running = false; if (visible) run();
    }
    M.inView('#hx', function () { visible = true; if (!running) run(); return function () { visible = false; }; }, iv({ amount: .35 }));
    // before the block is reached: desktop shows the finished state at rest, phones wait empty so the thread only ever arrives one message at a time
    setTimeout(function () { if (!visible && !running) { if (phone()) reset(); else finish(); } }, 100);
  })();

  // 3. SECTION REVEALS: one rule for the whole page — below the fold only, once, staggered
  (function () {
    var groups = $$('.mk-sec, .mk-tpls').filter(function (s) { return s.id !== 'hero-glass'; });
    var pending = [];
    groups.forEach(function (sec) {
      var items = $$('[data-rv]', sec); if (!items.length) return;
      if (sec.getBoundingClientRect().top < window.innerHeight * .85) return;   // already on screen: leave it at rest
      items.forEach(function (el) { el.style.opacity = 0; pending.push(el); });
      if (phone()) {
        // phones (Owner 09-29): each area on its own, the moment IT reaches the middle of the screen, never before
        items.forEach(function (el) {
          M.inView(el, function () {
            M.animate(el, { opacity: [0, 1], y: [20, 0] }, { duration: .4, ease: [.25, .8, .3, 1] });
            fx('reveal'); pending = pending.filter(function (e) { return e !== el; });
          }, MID);
        });
        return;
      }
      M.inView(sec, function () {
        M.animate(items, { opacity: [0, 1], y: [24, 0] }, { delay: M.stagger(.07), duration: .6, ease: [.25, .8, .3, 1] });
        fx('reveal');
        pending = pending.filter(function (e) { return items.indexOf(e) < 0; });
      }, { amount: .2, margin: '0px 0px -8% 0px' });
    });
    // nothing may stay hidden: after 7 s anything that should have shown by now is shown (on phones only what has
    // already passed the middle of the screen; the rest still waits for its turn)
    setTimeout(function () { pending.forEach(function (el) { el.style.opacity = 1; }); }, 5000);   // nothing may stay hidden
  })();

  // 4. THE LOOP: five steps as one sequence, the rail linked to it — loops while on screen
  (function () {
    var steps = $$('#lp .mk-step'), askText = 'Can we put something on the site about cherry blossom season in Japan?';
    var typed = $('#lp-typed'), caret = $('#lp-caret'), s2 = $('#lp-s2'), s2off = $('#lp-s2-off'), s3 = $('#lp-s3'), s3off = $('#lp-s3-off'), words = $('#lp-words'), meter = $('#lp-meter'),
        s4 = $('#lp-s4'), s4off = $('#lp-s4-off'), pendingB = $('#lp-s4-pending'), ok = $('#lp-s4-ok'), s5 = $('#lp-s5'), s5off = $('#lp-s5-off'), score = $('#lp-score'), ring = $('#lp-ring'), rail = $('#lp-rail');
    var visible = false, running = false, STEP = 3.1;
    function state(i) { steps.forEach(function (s, k) { s.classList.toggle('is-on', k === i); s.classList.toggle('is-done', k < i); }); }
    function reset() { state(-1); typed.textContent = ''; show(caret, true); show(s2, false); show(s2off, true); show(s3, false); show(s3off, true); show(s4, false); show(s4off, true); show(s5, false); show(s5off, true); rail.style.transform = 'scaleX(0)'; words.textContent = '0'; meter.style.transform = 'scaleX(0)'; score.textContent = '0'; ring.setAttribute('stroke-dashoffset', 157); }
    function finish() { state(4); typed.textContent = askText; show(caret, false); show(s2, true); show(s2off, false); show(s3, true); show(s3off, false); show(s4, true); show(s4off, false); show(pendingB, false); show(ok, true); show(s5, true); show(s5off, false); rail.style.transform = ''; words.textContent = '1,016'; meter.style.transform = ''; score.textContent = '90'; ring.setAttribute('stroke-dashoffset', 15.7); }
    function pop(el) { show(el, true); return M.animate(el, { opacity: [0, 1], y: [6, 0] }, { type: 'spring', bounce: .25, visualDuration: .35 }); }
    async function run() {
      running = true; fx('loop', true); reset();
      M.animate(rail, { scaleX: [0, 1] }, { duration: STEP * 5, ease: 'linear' });
      state(0); await M.animate(0, askText.length, { duration: STEP * .75, ease: 'linear', onUpdate: function (v) { typed.textContent = askText.slice(0, Math.round(v)); } }); show(caret, false); await wait(STEP * 250);
      state(1); show(s2off, false); await pop(s2); await wait(STEP * 1000 - 350);
      state(2); show(s3off, false); await pop(s3);
      await Promise.all([M.animate(0, 1016, { duration: STEP * .8, ease: 'circOut', onUpdate: function (v) { words.textContent = Math.round(v).toLocaleString('en-US'); } }), M.animate(meter, { scaleX: [0, 1] }, { duration: STEP * .8, ease: 'circOut' })]); await wait(STEP * 200);
      state(3); show(s4off, false); show(ok, false); show(pendingB, true); await pop(s4); await wait(STEP * 550); show(pendingB, false); await pop(ok); await wait(STEP * 450);
      state(4); show(s5off, false); await pop(s5);
      await Promise.all([M.animate(0, 90, { duration: STEP * .7, ease: 'circOut', onUpdate: function (v) { score.textContent = Math.round(v); } }), M.animate(ring, { strokeDashoffset: [157, 15.7] }, { duration: STEP * .7, ease: 'circOut' })]);
      fx('loop', false); await wait(3200); running = false; if (visible) run();
    }
    M.inView('#lp', function () { visible = true; if (!running) run(); return function () { visible = false; }; }, iv({ amount: .4 }));
    setTimeout(function () { if (!visible && !running) finish(); }, 100);
  })();

  // 5. COMMAND CENTER: counters rise once, the feed arrives one event at a time, an approval lands
  (function () {
    var done = false, feed = $$('.cc-evt'), approval = $('#cc-approval'), count = $('#cc-count'), pend = $('#cc-pending');
    M.inView('#cc .mk-app', function () {
      if (done) return; done = true;
      $$('[data-count]').forEach(function (el) { var n = +el.getAttribute('data-count'); M.animate(0, n, { duration: 1.4, ease: 'circOut', onUpdate: function (v) { el.textContent = Math.round(v); } }); });
      fx('count');
      feed.forEach(function (e) { e.style.opacity = 0; }); count.textContent = '0';
      M.animate(feed, { opacity: [0, 1], x: [-18, 0] }, { delay: M.stagger(.9, { startDelay: .3 }), duration: .5, ease: [.25, .8, .3, 1] });
      feed.forEach(function (e, i) { setTimeout(function () { count.textContent = i + 1; }, 300 + i * 900); });
      fx('feed');
      approval.style.opacity = 0; pend.textContent = '1';
      setTimeout(function () { pend.textContent = '2'; M.animate(approval, { opacity: [0, 1], y: [-10, 0], scale: [.96, 1] }, { type: 'spring', bounce: .35, visualDuration: .5 }); }, 4200);
    }, iv({ amount: .3 }));
  })();

  // 6. CHATBOT to lead: a visitor types, the bot answers, Elena logs the lead — loops while on screen
  (function () {
    var v1 = 'Looking for a honeymoon in Japan. Any ideas?', v2 = 'Yes please. Emma, 07700 900412.';
    var msgs = $$('.bt-msg'), typing = $('#bt-typing'), typed = $('#bt-typed'), caret = $('#bt-caret'), lead = $('#bt-lead'), nolead = $('#bt-nolead');
    var visible = false, running = false;
    function pop(el) { show(el, true); return M.animate(el, { opacity: [0, 1], y: [8, 0], scale: [.96, 1] }, { type: 'spring', bounce: .3, visualDuration: .4 }); }
    function type(t) { show(caret, true); return M.animate(0, t.length, { duration: t.length * .055, ease: 'linear', onUpdate: function (v) { typed.textContent = t.slice(0, Math.round(v)); } }).then(function () { return wait(300); }).then(function () { typed.textContent = ''; show(caret, false); }); }
    function reset() { msgs.forEach(function (m) { show(m, false); }); show(typing, false); typed.textContent = ''; show(lead, false); show(nolead, true); }
    function finish() { msgs.forEach(function (m) { show(m, true); m.style.opacity = 1; }); show(typing, false); show(lead, true); lead.style.opacity = 1; show(nolead, false); }
    async function run() {
      running = true; fx('chat', true); reset();
      await type(v1); await pop(msgs[0]); await wait(250); show(typing, true); await wait(1300); show(typing, false); await pop(msgs[1]); await wait(1100);
      await type(v2); await pop(msgs[2]); await wait(250); show(typing, true); await wait(900); show(typing, false); await pop(msgs[3]); await wait(700);
      show(nolead, false); await pop(lead);
      fx('chat', false); await wait(3600); running = false; if (visible) run();
    }
    M.inView('#bt', function () { visible = true; if (!running) run(); return function () { visible = false; }; }, iv({ amount: .5 }));
    setTimeout(function () { if (!visible && !running) finish(); }, 100);
  })();

  // 7. REVIEW QUEUE: approve, the card leaves, the gap closes, the queue clears — loops while on screen
  (function () {
    var cards = $$('.rq-card'), clear = $('#rq-clear'), pend = $('#rq-pending'), visible = false, running = false;
    function reset() { cards.forEach(function (c) { c.hidden = false; c.style.cssText = 'padding:10px 12px;border-radius:14px;overflow:hidden;transform:none'; $('.rq-pulse', c).hidden = true; }); show(clear, false); pend.textContent = '3'; }
    async function leave(c) {
      await M.animate(c, { opacity: [1, 0], transform: ['none', 'translateX(40px) scale(.96)'] }, { duration: .4, ease: [.32, .72, 0, 1] });
      var h = c.offsetHeight;
      await M.animate(c, { height: [h + 'px', '0px'], marginTop: ['0px', '-10px'], paddingTop: ['10px', '0px'], paddingBottom: ['10px', '0px'] }, { duration: .35, ease: [.32, .72, 0, 1] });
      c.hidden = true;
    }
    async function run() {
      running = true; fx('queue', true); reset();
      await M.animate(cards, { opacity: [0, 1], transform: ['translateY(10px)', 'none'] }, { delay: M.stagger(.1), duration: .4 });
      for (var i = 0; i < cards.length; i++) {
        var c = cards[i], p = $('.rq-pulse', c), b = $('.rq-approve', c);
        await wait(900); p.hidden = false; await wait(1200); p.hidden = true;
        await M.animate(b, { scale: [1, .94, 1] }, { duration: .3 });
        await leave(c); pend.textContent = String(cards.length - 1 - i);
      }
      show(clear, true); await M.animate(clear, { opacity: [0, 1], y: [-14, 0], scale: [.96, 1] }, { type: 'spring', bounce: .35, visualDuration: .5 });
      fx('queue', false); await wait(3200); running = false; if (visible) run();
    }
    M.inView('#rq', function () { visible = true; if (!running) run(); return function () { visible = false; }; }, iv({ amount: .5 }));
  })();

  // 8. TEAM: what each specialist is on changes every few seconds
  (function () {
    var rows = $$('#tm .mk-status'), k = 0;
    setInterval(async function () {
      k++; for (var i = 0; i < rows.length; i++) {
        var el = rows[i], list = el.getAttribute('data-status').split('|'), span = $('span', el);
        await M.animate(span, { opacity: [1, 0], y: [0, -6] }, { duration: .18, delay: i * .05 });
        span.textContent = list[k % list.length];
        M.animate(span, { opacity: [0, 1], y: [6, 0] }, { duration: .25 });
      }
    }, 3600);
  })();

  // 9. SCROLL-LINKED: reading progress on the top edge; the hero visual lifts as you scroll away (desktop)
  M.scroll(M.animate('#mk-progress', { scaleX: [0, 1] }, { ease: 'linear' }));
  M.scroll(function (p) { if (p > .02) fx('scroll'); });

  $$('.mk-scroll').forEach(function (b) { b.addEventListener('click', function () { var t = $('#tpls'); if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }); });

  // 10. PRESS: every button scales to .96 and springs back — keyboard, touch and mouse alike
  M.press('.lg-btn', function (el) { M.animate(el, { scale: .96 }, { duration: .12 }); return function () { M.animate(el, { scale: 1 }, { type: 'spring', bounce: .45, visualDuration: .45 }); fx('press'); }; });

  // 11. HOVER: cards lift 4px on a real pointer; touch never gets a stuck hover
  if (fine) M.hover('.lg-card--lift', function (el) { M.animate(el, { y: -4 }, { duration: .25, ease: [.22, 1, .36, 1] }); fx('hover'); return function () { M.animate(el, { y: 0 }, { duration: .3, ease: [.22, 1, .36, 1] }); }; });

  // 12. CLOSING CTA: one shine sweep when it comes into view, and the featured plan lands on a spring
  M.inView('#start', function () { M.animate('#cta-shine', { x: ['-120%', '120%'] }, { duration: 1.2, ease: 'easeInOut', delay: phone() ? .1 : .6 }); }, iv({ amount: .5 }));
  M.inView('#price-featured', function () { M.animate('#price-featured', { scale: [.96, 1] }, { type: 'spring', bounce: .4, visualDuration: .6 }); }, iv({ amount: .5 }));
})();
