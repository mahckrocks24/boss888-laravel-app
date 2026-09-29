/* GLASS-HOME-1 (2026-09-29): the home's self-playing panels, driven by Motion (vanilla build, served from /assets/mk).
   Ported from the Motion mockup v19 (claude.ai/artifact/DYHZ3BZcM2imQ3AtuVwxBS) minus the mockup chrome (legend, sticky pill).
   Phones (Owner 09-29): every panel starts the moment it reaches the middle of the screen and never before; the Arthur build
   plays once and stays finished; no sweeping text. Reduced motion: every state shown at rest. */
(function () {
  function boot() {
    var M = window.Motion;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var phone = function () { return window.innerWidth <= 1100; };
    var MID = { amount: 0, margin: '0px 0px -50% 0px' };
    var iv = function (desk) { return phone() ? MID : desk; };
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    function fx() {}
    function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
    function show(el, on) { if (el) el.hidden = !on; }
    if (!$('.lg.mk')) return;

    // aurora: the slow lean toward the mouse (desktop only), nothing moves on its own
    (function () {
      var TAU = 2000, tx = 0, ty = 0, cx = 0, cy = 0, raf = 0, last = 0, el = $('.lg.mk .lg-aurora');
      if (!el) return;
      function frame(now) { var dt = last ? Math.min(now - last, 100) : 16; last = now; var k = 1 - Math.exp(-dt / TAU); cx += (tx - cx) * k; cy += (ty - cy) * k; el.style.setProperty('--ax', cx.toFixed(4)); el.style.setProperty('--ay', cy.toFixed(4)); if (Math.abs(tx - cx) > .0004 || Math.abs(ty - cy) > .0004) raf = requestAnimationFrame(frame); else { raf = 0; last = 0; } }
      window.addEventListener('pointermove', function (e) { if (reduce || (e.pointerType !== 'mouse' && e.pointerType !== 'pen')) return; tx = (e.clientX / window.innerWidth) * 2 - 1; ty = (e.clientY / window.innerHeight) * 2 - 1; if (!raf) raf = requestAnimationFrame(frame); }, { passive: true });
    })();

    if (!M || reduce) return;   // the markup already shows every panel at rest

    try { var s = String(M.spring(0.4, 0.3)); if (/linear\(/.test(s)) document.documentElement.style.setProperty('--spring-t', s); } catch (e) {}

    // 1. the two hero cards enter: desktop on load, phones when each reaches the middle of the screen
    if (phone()) $$('[data-hero-v]').forEach(function (el) { el.style.opacity = 0; M.inView(el, function () { M.animate(el, { opacity: [0, 1], y: [24, 0] }, { type: 'spring', bounce: .2, visualDuration: .5 }); }, MID); });
    else M.animate([
      ['[data-hero-v="1"]', { opacity: [0, 1], x: [-24, 0] }, { type: 'spring', bounce: .2, visualDuration: .6 }],
      ['[data-hero-v="2"]', { opacity: [0, 1], y: [28, 0] }, { type: 'spring', bounce: .2, visualDuration: .6, at: '<0.15' }]
    ], { defaultTransition: { ease: [.25, .8, .3, 1] } });

    // 2. ARTHUR builds a site: typed brief, reply, build, live, then the team — loops on desktop, plays once on phones
    (function () {
      var ask = 'A neighbourhood restaurant in Norwich. Twelve dishes, seasonal menu, private hire.';
      var typed = $('#hx-typed'), caret = $('#hx-caret'), askB = $('#hx-ask'), typing = $('#hx-typing'), reply = $('#hx-reply'), liveB = $('#hx-live'),
          img = $('#hx-img'), skel = $('#hx-skel'), stage = $('#hx-stage'), pct = $('#hx-pct'), meter = $('#hx-meter'), says = $$('.hx-say'), sarah = says[0], team = says.slice(1), frost = $('#hx-frost'), tag = $('#hx-tag'), line = $('#hx-line');
      if (!typed || !line) return;
      (function () { var out = []; Array.prototype.slice.call(line.childNodes).forEach(function (n) { if (n.nodeType === 3) { n.nodeValue.split('').forEach(function (ch) { out.push('<span class="ch' + (ch === ' ' ? ' sp' : '') + '">' + (ch === ' ' ? '' : ch) + '</span>'); }); } else { out.push('<b class="t-grad">' + n.textContent.split('').map(function (ch) { return '<span class="ch">' + ch + '</span>'; }).join('') + '</b>'); } }); line.innerHTML = out.join(''); })();
      var letters = $$('.ch', line);
      var visible = false, running = false, done = false, gen = 0;
      function pop(el) { show(el, true); return M.animate(el, { opacity: [0, 1], y: [8, 0], scale: [.96, 1] }, { type: 'spring', bounce: .3, visualDuration: .4 }); }
      function reset() { typed.textContent = ''; show(caret, true); [askB, typing, reply, liveB].forEach(function (e) { show(e, false); }); says.forEach(function (c) { c.style.opacity = 0; }); frost.style.opacity = 0; tag.style.opacity = 0; line.style.transform = 'translateX(100vw)'; img.style.clipPath = 'inset(0 0 100% 0)'; show(skel, true); stage.textContent = 'Waiting for your brief'; pct.textContent = '0'; meter.style.transform = 'scaleX(0)'; }
      function finish() { typed.textContent = ''; show(caret, false); [askB, reply, liveB].forEach(function (e) { show(e, true); e.style.opacity = 1; }); show(typing, false); says.forEach(function (c) { c.style.opacity = 1; }); frost.style.opacity = 1; tag.style.opacity = 0; img.style.clipPath = ''; show(skel, false); stage.textContent = 'Published'; pct.textContent = '100'; meter.style.transform = ''; }
      async function run() {
        var my = ++gen; var live = function () { return my === gen; };
        running = true; reset();
        await M.animate(0, ask.length, { duration: 3.2, ease: 'linear', onUpdate: function (v) { if (live()) typed.textContent = ask.slice(0, Math.round(v)); } });
        if (!live()) return;
        await wait(350); typed.textContent = ''; show(caret, false);
        await pop(askB); await wait(300); show(typing, true); await wait(1200); show(typing, false);
        if (!live()) return;
        await pop(reply); await wait(400);
        var stages = ['Writing the copy', 'Laying out the pages', 'Menu, gallery, booking form'];
        var build = 5.5;
        stages.forEach(function (t, i) { setTimeout(function () { if (running && live()) stage.textContent = t; }, i * build * 333); });
        await Promise.all([
          M.animate(img, { clipPath: ['inset(0 0 100% 0)', 'inset(0 0 0% 0)'] }, { duration: build, ease: 'circOut' }),
          M.animate(meter, { scaleX: [0, 1] }, { duration: build, ease: 'circOut' }),
          M.animate(0, 100, { duration: build, ease: 'circOut', onUpdate: function (v) { if (live()) pct.textContent = Math.round(v); } })
        ]);
        if (!live()) return;
        show(skel, false); stage.textContent = 'Published';
        await pop(liveB); await wait(500);
        var order = team.slice().sort(function () { return Math.random() - .5; });
        var talk = function (el) { return M.animate(el, { opacity: [0, 1], y: [14, 0], scale: [.92, 1] }, { type: 'spring', bounce: .4, visualDuration: .55 }); };
        [sarah].concat(order).forEach(function (el) { el.parentNode.appendChild(el); });
        M.animate(frost, { opacity: [0, 1] }, { duration: .7, ease: 'easeOut' });
        await wait(250); await talk(sarah);
        for (var i = 0; i < order.length; i++) { await wait(900 + Math.random() * 800); if (!live()) return; await talk(order[i]); }
        if (phone()) { running = false; done = true; return; }
        await wait(4600);
        if (!live()) return;
        await M.animate(says, { opacity: [1, 0], y: [0, -8] }, { delay: M.stagger(.06), duration: .3 });
        var vw = tag.clientWidth, lw = line.offsetWidth, speed = 520, D = (lw + vw) / speed;
        tag.style.opacity = 1; letters.forEach(function (l) { l.style.opacity = 0; });
        letters.forEach(function (l) { M.animate(l, { opacity: [0, 1], y: [.28 * parseFloat(getComputedStyle(l).fontSize), 0], scale: [.7, 1] }, { delay: Math.max(0, l.offsetLeft / speed - .05), duration: .42, ease: [.22, 1, .36, 1] }); });
        await M.animate(line, { x: [vw, -lw] }, { duration: D, ease: 'linear' });
        tag.style.opacity = 0;
        await M.animate(frost, { opacity: [1, 0] }, { duration: .5 });
        running = false; if (visible) run();
      }
      M.inView('#hx', function () { visible = true; if (!running && !done) run(); return function () { visible = false; }; }, iv({ amount: .35 }));
      if (phone()) M.inView('#hx', function () { return function (entry) {
        var top = entry && entry.boundingClientRect ? entry.boundingClientRect.bottom <= 0 : $('#hx').getBoundingClientRect().bottom <= 0;
        if (top && running) { gen++; running = false; done = true; finish(); }
      }; }, { amount: 0 });
      setTimeout(function () { if (!visible && !running) finish(); }, 100);
    })();

    // 3. SECTION REVEALS: below the fold only, once
    (function () {
      var groups = $$('.mk-sec');
      var pending = [];
      groups.forEach(function (sec) {
        var items = $$('[data-rv]', sec); if (!items.length) return;
        if (sec.getBoundingClientRect().top < window.innerHeight * .85) return;
        items.forEach(function (el) { el.style.opacity = 0; pending.push(el); });
        if (phone()) {
          items.forEach(function (el) {
            M.inView(el, function () {
              M.animate(el, { opacity: [0, 1], y: [20, 0] }, { duration: .4, ease: [.25, .8, .3, 1] });
              pending = pending.filter(function (e) { return e !== el; });
            }, MID);
          });
          return;
        }
        M.inView(sec, function () {
          M.animate(items, { opacity: [0, 1], y: [24, 0] }, { delay: M.stagger(.07), duration: .6, ease: [.25, .8, .3, 1] });
          pending = pending.filter(function (e) { return items.indexOf(e) < 0; });
        }, { amount: .2, margin: '0px 0px -8% 0px' });
      });
      setTimeout(function () { pending.forEach(function (el) { if (!phone() || el.getBoundingClientRect().top < window.innerHeight * .5) el.style.opacity = 1; }); }, 7000);
    })();

    // 4. THE LOOP: five steps as one sequence, the rail linked to it — loops while on screen
    (function () {
      var steps = $$('#lp .mk-step'), askText = 'Can we put something on the site about cherry blossom season in Japan?';
      var typed = $('#lp-typed'), caret = $('#lp-caret'), s2 = $('#lp-s2'), s2off = $('#lp-s2-off'), s3 = $('#lp-s3'), s3off = $('#lp-s3-off'), words = $('#lp-words'), meter = $('#lp-meter'),
          s4 = $('#lp-s4'), s4off = $('#lp-s4-off'), pendingB = $('#lp-s4-pending'), ok = $('#lp-s4-ok'), s5 = $('#lp-s5'), s5off = $('#lp-s5-off'), score = $('#lp-score'), ring = $('#lp-ring'), rail = $('#lp-rail');
      if (!steps.length || !typed) return;
      var visible = false, running = false, STEP = 3.1;
      function state(i) { steps.forEach(function (s, k) { s.classList.toggle('is-on', k === i); s.classList.toggle('is-done', k < i); }); }
      function reset() { state(-1); typed.textContent = ''; show(caret, true); show(s2, false); show(s2off, true); show(s3, false); show(s3off, true); show(s4, false); show(s4off, true); show(s5, false); show(s5off, true); rail.style.transform = 'scaleX(0)'; words.textContent = '0'; meter.style.transform = 'scaleX(0)'; score.textContent = '0'; ring.setAttribute('stroke-dashoffset', 157); }
      function finish() { state(4); typed.textContent = askText; show(caret, false); show(s2, true); show(s2off, false); show(s3, true); show(s3off, false); show(s4, true); show(s4off, false); show(pendingB, false); show(ok, true); show(s5, true); show(s5off, false); rail.style.transform = ''; words.textContent = '1,016'; meter.style.transform = ''; score.textContent = '90'; ring.setAttribute('stroke-dashoffset', 15.7); }
      function pop(el) { show(el, true); return M.animate(el, { opacity: [0, 1], y: [6, 0] }, { type: 'spring', bounce: .25, visualDuration: .35 }); }
      async function run() {
        running = true; reset();
        M.animate(rail, { scaleX: [0, 1] }, { duration: STEP * 5, ease: 'linear' });
        state(0); await M.animate(0, askText.length, { duration: STEP * .75, ease: 'linear', onUpdate: function (v) { typed.textContent = askText.slice(0, Math.round(v)); } }); show(caret, false); await wait(STEP * 250);
        state(1); show(s2off, false); await pop(s2); await wait(STEP * 1000 - 350);
        state(2); show(s3off, false); await pop(s3);
        await Promise.all([M.animate(0, 1016, { duration: STEP * .8, ease: 'circOut', onUpdate: function (v) { words.textContent = Math.round(v).toLocaleString('en-US'); } }), M.animate(meter, { scaleX: [0, 1] }, { duration: STEP * .8, ease: 'circOut' })]); await wait(STEP * 200);
        state(3); show(s4off, false); show(ok, false); show(pendingB, true); await pop(s4); await wait(STEP * 550); show(pendingB, false); await pop(ok); await wait(STEP * 450);
        state(4); show(s5off, false); await pop(s5);
        await Promise.all([M.animate(0, 90, { duration: STEP * .7, ease: 'circOut', onUpdate: function (v) { score.textContent = Math.round(v); } }), M.animate(ring, { strokeDashoffset: [157, 15.7] }, { duration: STEP * .7, ease: 'circOut' })]);
        await wait(3200); running = false; if (visible) run();
      }
      M.inView('#lp', function () { visible = true; if (!running) run(); return function () { visible = false; }; }, iv({ amount: .4 }));
      setTimeout(function () { if (!visible && !running) finish(); }, 100);
    })();

    // 5. COMMAND CENTER: counters rise once, the feed arrives one event at a time, an approval lands
    (function () {
      var done = false, feed = $$('.cc-evt'), approval = $('#cc-approval'), count = $('#cc-count'), pend = $('#cc-pending');
      if (!approval) return;
      M.inView('#cc .mk-app', function () {
        if (done) return; done = true;
        $$('[data-count]').forEach(function (el) { var n = +el.getAttribute('data-count'); M.animate(0, n, { duration: 1.4, ease: 'circOut', onUpdate: function (v) { el.textContent = Math.round(v); } }); });
        feed.forEach(function (e) { e.style.opacity = 0; }); count.textContent = '0';
        M.animate(feed, { opacity: [0, 1], x: [-18, 0] }, { delay: M.stagger(.9, { startDelay: .3 }), duration: .5, ease: [.25, .8, .3, 1] });
        feed.forEach(function (e, i) { setTimeout(function () { count.textContent = i + 1; }, 300 + i * 900); });
        approval.style.opacity = 0; pend.textContent = '1';
        setTimeout(function () { pend.textContent = '2'; M.animate(approval, { opacity: [0, 1], y: [-10, 0], scale: [.96, 1] }, { type: 'spring', bounce: .35, visualDuration: .5 }); }, 4200);
      }, iv({ amount: .3 }));
    })();

    // 6. CHATBOT to lead: a visitor types, the bot answers, Elena logs the lead — loops while on screen
    (function () {
      var v1 = 'Looking for a honeymoon in Japan. Any ideas?', v2 = 'Yes please. Emma, 07700 900412.';
      var msgs = $$('.bt-msg'), typing = $('#bt-typing'), typed = $('#bt-typed'), caret = $('#bt-caret'), lead = $('#bt-lead'), nolead = $('#bt-nolead');
      if (!msgs.length || !typed) return;
      var visible = false, running = false;
      function pop(el) { show(el, true); return M.animate(el, { opacity: [0, 1], y: [8, 0], scale: [.96, 1] }, { type: 'spring', bounce: .3, visualDuration: .4 }); }
      function type(t) { show(caret, true); return M.animate(0, t.length, { duration: t.length * .055, ease: 'linear', onUpdate: function (v) { typed.textContent = t.slice(0, Math.round(v)); } }).then(function () { return wait(300); }).then(function () { typed.textContent = ''; show(caret, false); }); }
      function reset() { msgs.forEach(function (m) { show(m, false); }); show(typing, false); typed.textContent = ''; show(lead, false); show(nolead, true); }
      function finish() { msgs.forEach(function (m) { show(m, true); m.style.opacity = 1; }); show(typing, false); show(lead, true); lead.style.opacity = 1; show(nolead, false); }
      async function run() {
        running = true; reset();
        await type(v1); await pop(msgs[0]); await wait(250); show(typing, true); await wait(1300); show(typing, false); await pop(msgs[1]); await wait(1100);
        await type(v2); await pop(msgs[2]); await wait(250); show(typing, true); await wait(900); show(typing, false); await pop(msgs[3]); await wait(700);
        show(nolead, false); await pop(lead);
        await wait(3600); running = false; if (visible) run();
      }
      M.inView('#bt', function () { visible = true; if (!running) run(); return function () { visible = false; }; }, iv({ amount: .5 }));
      setTimeout(function () { if (!visible && !running) finish(); }, 100);
    })();

    // 7. REVIEW QUEUE: approve, the card leaves, the gap closes, the queue clears — loops while on screen
    (function () {
      var cards = $$('.rq-card'), clear = $('#rq-clear'), pend = $('#rq-pending'), visible = false, running = false;
      if (!cards.length) return;
      function reset() { cards.forEach(function (c) { c.hidden = false; c.style.cssText = 'padding:10px 12px;border-radius:14px;overflow:hidden'; $('.rq-pulse', c).hidden = true; }); show(clear, false); pend.textContent = '3'; }
      async function leave(c) {
        await M.animate(c, { opacity: [1, 0], x: [0, 40], scale: [1, .96] }, { duration: .4, ease: [.32, .72, 0, 1] });
        var h = c.offsetHeight;
        await M.animate(c, { height: [h + 'px', '0px'], marginTop: ['0px', '-10px'], paddingTop: ['10px', '0px'], paddingBottom: ['10px', '0px'] }, { duration: .35, ease: [.32, .72, 0, 1] });
        c.hidden = true;
      }
      async function run() {
        running = true; reset();
        await M.animate(cards, { opacity: [0, 1], y: [10, 0] }, { delay: M.stagger(.1), duration: .4 });
        for (var i = 0; i < cards.length; i++) {
          var c = cards[i], p = $('.rq-pulse', c), b = $('.rq-approve', c);
          await wait(900); p.hidden = false; await wait(1200); p.hidden = true;
          await M.animate(b, { scale: [1, .94, 1] }, { duration: .3 });
          await leave(c); pend.textContent = String(cards.length - 1 - i);
        }
        show(clear, true); await M.animate(clear, { opacity: [0, 1], y: [-14, 0], scale: [.96, 1] }, { type: 'spring', bounce: .35, visualDuration: .5 });
        await wait(3200); running = false; if (visible) run();
      }
      M.inView('#rq', function () { visible = true; if (!running) run(); return function () { visible = false; }; }, iv({ amount: .5 }));
    })();

    // 8. TEAM: what each specialist is on changes every few seconds
    (function () {
      var rows = $$('#tm .mk-status'), k = 0;
      if (!rows.length) return;
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
    if ($('#mk-progress')) M.scroll(M.animate('#mk-progress', { scaleX: [0, 1] }, { ease: 'linear' }));
    if (!phone() && $('#hero-visual')) M.scroll(M.animate('#hero-visual', { y: [0, -70] }, { ease: 'linear' }), { target: $('#hx-sec'), offset: ['start start', 'end start'] });

    // 10. PRESS: every glass button scales to .96 and springs back — keyboard, touch and mouse alike
    M.press('.lg.mk .lg-btn', function (el) { M.animate(el, { scale: .96 }, { duration: .12 }); return function () { M.animate(el, { scale: 1 }, { type: 'spring', bounce: .45, visualDuration: .45 }); }; });

    // 11. HOVER: cards lift 4px on a real pointer; touch never gets a stuck hover
    if (fine) M.hover('.lg.mk .lg-card--lift', function (el) { M.animate(el, { y: -4 }, { duration: .25, ease: [.22, 1, .36, 1] }); return function () { M.animate(el, { y: 0 }, { duration: .3, ease: [.22, 1, .36, 1] }); }; });

    // 12. CLOSING CTA: one shine sweep when it comes into view, and the featured plan lands on a spring
    if ($('#cta-shine')) M.inView('#start', function () { M.animate('#cta-shine', { x: ['-120%', '120%'] }, { duration: 1.2, ease: 'easeInOut', delay: phone() ? .1 : .6 }); }, iv({ amount: .5 }));
    if ($('#price-featured')) M.inView('#price-featured', function () { M.animate('#price-featured', { scale: [.96, 1] }, { type: 'spring', bounce: .4, visualDuration: .6 }); }, iv({ amount: .5 }));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
