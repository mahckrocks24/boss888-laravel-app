/* GLASS-SITE-2 (2026-09-30): the live panels' sequences (markup: live-panels.php, styles: live.css), driven by Motion.
   Every panel is complete at rest; with Motion each one resets, plays while it is on screen, holds, and loops.
   Reduced motion or no Motion: the finished state stays. */
(function () {
  var M = window.Motion;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var panels = Array.prototype.slice.call(document.querySelectorAll('[data-live]'));
  if (!panels.length || !M || reduce) return;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var lv = function (r, k) { return $$('[data-lv="' + k + '"]', r); };
  var one = function (r, k) { return $('[data-lv="' + k + '"]', r); };
  function wait(ms) { return new Promise(function (res) { setTimeout(res, ms); }); }
  function pop(el, o) { if (!el) return Promise.resolve(); el.hidden = false; return M.animate(el, { opacity: [0, 1], y: [8, 0], scale: [.97, 1] }, Object.assign({ type: 'spring', bounce: .3, visualDuration: .4 }, o || {})); }
  function hide(els) { els.forEach(function (e) { e.style.opacity = 0; }); }
  function showAll(els) { els.forEach(function (e) { e.style.opacity = 1; e.hidden = false; }); }
  function type(el, caret, text, secs) { if (caret) caret.hidden = false; return M.animate(0, text.length, { duration: secs, ease: 'linear', onUpdate: function (v) { el.textContent = text.slice(0, Math.round(v)); } }).then(function () { if (caret) caret.hidden = true; }); }
  function count(el, from, to, secs, fmt) { if (!el) return Promise.resolve(); return M.animate(from, to, { duration: secs, ease: 'circOut', onUpdate: function (v) { el.textContent = fmt ? fmt(v) : Math.round(v).toLocaleString('en-US'); } }); }
  function meter(el, to, secs) { if (!el) return Promise.resolve(); return M.animate(el, { scaleX: [0, to] }, { duration: secs, ease: 'circOut' }); }
  function stagger(els, o, gap, extra) { return M.animate(els, o, Object.assign({ delay: M.stagger(gap), duration: .45, ease: [.25, .8, .3, 1] }, extra || {})); }
  var seq = {};

  seq.build = { ask: 'A neighbourhood restaurant in Norwich. Twelve dishes, seasonal menu, private hire.',
    reset: function (r) { one(r, 'typed').textContent = ''; ['ask', 'reply', 'live'].forEach(function (k) { one(r, k).hidden = true; }); one(r, 'typing').hidden = true; one(r, 'img').style.clipPath = 'inset(0 0 100% 0)'; one(r, 'skel').hidden = false; one(r, 'stage').textContent = 'Waiting for your brief'; one(r, 'pct').textContent = '0'; one(r, 'meter').style.transform = 'scaleX(0)'; },
    run: async function (r) { var t = one(r, 'typed');
      await type(t, one(r, 'caret'), this.ask, 3); await wait(300); t.textContent = ''; await pop(one(r, 'ask')); await wait(250); one(r, 'typing').hidden = false; await wait(1000); one(r, 'typing').hidden = true; await pop(one(r, 'reply')); await wait(300);
      var stages = ['Writing the copy', 'Laying out the pages', 'Menu, gallery, booking form']; stages.forEach(function (s, i) { setTimeout(function () { one(r, 'stage').textContent = s; }, i * 900); });
      await Promise.all([M.animate(one(r, 'img'), { clipPath: ['inset(0 0 100% 0)', 'inset(0 0 0% 0)'] }, { duration: 2.75, ease: 'circOut' }), meter(one(r, 'meter'), 1, 2.75), count(one(r, 'pct'), 0, 100, 2.75)]);
      one(r, 'skel').hidden = true; one(r, 'stage').textContent = 'Published'; await pop(one(r, 'live')); await wait(3500); } };

  seq.team = { reset: function (r) { hide($$('.lv-evt', r)); hide(lv(r, 'appr1').concat(lv(r, 'appr2'))); one(r, 'count').textContent = '0'; one(r, 'pending').textContent = '0'; },
    run: async function (r) { var ev = $$('.lv-evt', r); stagger(ev, { opacity: [0, 1], x: [-14, 0] }, .7); ev.forEach(function (e, i) { setTimeout(function () { one(r, 'count').textContent = i + 1; }, i * 700 + 200); });
      await wait(900); one(r, 'pending').textContent = '1'; await pop(one(r, 'appr1')); await wait(2200); one(r, 'pending').textContent = '2'; await pop(one(r, 'appr2'));
      var rows = $$('.lv-status', r); for (var k = 1; k < 3; k++) { await wait(1800); for (var i = 0; i < rows.length; i++) { var list = rows[i].getAttribute('data-lv-status').split('|'), sp = $('span', rows[i]); await M.animate(sp, { opacity: [1, 0], y: [0, -5] }, { duration: .15 }); sp.textContent = list[k % list.length]; M.animate(sp, { opacity: [0, 1], y: [5, 0] }, { duration: .2 }); } }
      await wait(2500); } };

  seq.seo = { reset: function (r) { one(r, 'score').textContent = '42'; one(r, 'ring').setAttribute('stroke-dashoffset', String(327 * .58)); one(r, 'scoreline').textContent = '9 checks passed · 15 to fix'; lv(r, 'kw').forEach(function (k) { one(k, 'pos').textContent = '#' + k.getAttribute('data-from'); one(k, 'delta').style.opacity = 0; }); hide(lv(r, 'win')); one(r, 'handed').style.opacity = 0; },
    run: async function (r) { await wait(400);
      M.animate(one(r, 'ring'), { strokeDashoffset: [327 * .58, 327 * .12] }, { duration: 2, ease: 'circOut' }); await count(one(r, 'score'), 42, 88, 2); one(r, 'scoreline').textContent = '21 checks passed · 3 to fix';
      var kws = lv(r, 'kw'); for (var i = 0; i < kws.length; i++) { var k = kws[i]; count(one(k, 'pos'), +k.getAttribute('data-from'), +k.getAttribute('data-to'), 1.4, function (v) { return '#' + Math.round(v); }).then((function (k) { return function () { M.animate(one(k, 'delta'), { opacity: [0, 1], x: [6, 0] }, { duration: .3 }); }; })(k)); await wait(250); }
      await wait(1200); var wins = lv(r, 'win'); for (var j = 0; j < wins.length; j++) { await pop(wins[j]); await wait(300); }
      await pop(one(r, 'handed')); await wait(3500); } };

  seq.write = { title: 'Cherry blossom season in Japan: when to go and what to book',
    reset: function (r) { one(r, 'title').textContent = ''; hide($$('.lv-lines i', r)); one(r, 'words').textContent = '0'; one(r, 'meter').style.transform = 'scaleX(0)'; hide(lv(r, 'chk')); one(r, 'score').textContent = '0'; one(r, 'ring').setAttribute('stroke-dashoffset', '327'); one(r, 'published').style.opacity = 0; },
    run: async function (r) { await type(one(r, 'title'), null, this.title, 1.6); stagger($$('.lv-lines i', r), { opacity: [0, 1], scaleX: [0, 1] }, .15);
      await Promise.all([count(one(r, 'words'), 0, 1016, 2.2), meter(one(r, 'meter'), 1, 2.2)]);
      var chks = lv(r, 'chk'); for (var j = 0; j < chks.length; j++) { await pop(chks[j]); await wait(220); }
      M.animate(one(r, 'ring'), { strokeDashoffset: [327, 327 * .1] }, { duration: 1.4, ease: 'circOut' }); await count(one(r, 'score'), 0, 90, 1.4);
      await pop(one(r, 'published')); await wait(3500); } };

  seq.social = { cap: 'Friday is private hire night. Twelve seats, one long table, the menu we cook for you. Book the room.',
    reset: function (r) { hide(lv(r, 'post')); one(r, 'badge').textContent = 'Marcus is planning the week…'; one(r, 'caption').textContent = ''; ['reach', 'clicks', 'bookings'].forEach(function (k) { one(r, k).textContent = '0'; }); },
    run: async function (r) { var posts = lv(r, 'post'); for (var i = 0; i < posts.length; i++) { await pop(posts[i]); one(r, 'badge').textContent = (i + 1) + (i ? ' posts' : ' post') + ' planned'; await wait(450); }
      one(r, 'badge').textContent = '4 posts · waiting for your OK'; await type(one(r, 'caption'), null, this.cap, 2.2);
      await Promise.all(['reach', 'clicks', 'bookings'].map(function (k) { var el = one(r, k); return count(el, 0, +el.getAttribute('data-n'), 1.4); })); await wait(3500); } };

  seq.crm = { reset: function (r) { ['lead-new', 'lead-mid', 'lead-done'].forEach(function (k) { one(r, k).hidden = true; }); one(r, 'n1').textContent = '1'; one(r, 'n2').textContent = '1'; one(r, 'n3').textContent = '2'; },
    run: async function (r) { await wait(500); await pop(one(r, 'lead-new')); one(r, 'n1').textContent = '2'; await wait(1800);
      await M.animate(one(r, 'lead-new'), { opacity: [1, 0], x: [0, 24] }, { duration: .3 }); one(r, 'lead-new').hidden = true; one(r, 'n1').textContent = '1'; await pop(one(r, 'lead-mid')); one(r, 'n2').textContent = '2'; await wait(1800);
      await M.animate(one(r, 'lead-mid'), { opacity: [1, 0], x: [0, 24] }, { duration: .3 }); one(r, 'lead-mid').hidden = true; one(r, 'n2').textContent = '1'; await pop(one(r, 'lead-done')); one(r, 'n3').textContent = '3'; await wait(3500); } };

  seq.calendar = { reset: function (r) { hide(lv(r, 'ev')); one(r, 'badge').textContent = 'This week is filling up'; one(r, 'toast').style.opacity = 0; },
    run: async function (r) { var ev = lv(r, 'ev'); for (var i = 0; i < ev.length; i++) { await pop(ev[i]); one(r, 'badge').textContent = (i + 1) + ' in the diary'; await wait(500); } one(r, 'badge').textContent = '3 bookings · 1 call'; await wait(400); await pop(one(r, 'toast')); await wait(3500); } };

  seq.bot = { v1: 'Looking for a honeymoon in Japan. Any ideas?', v2: 'Yes please. Emma, 07700 900412.',
    reset: function (r) { lv(r, 'm').forEach(function (m) { m.hidden = true; }); one(r, 'typing').hidden = true; one(r, 'typed').textContent = ''; one(r, 'lead').hidden = true; one(r, 'nolead').hidden = false; },
    run: async function (r) { var m = lv(r, 'm'), t = one(r, 'typed'), c = one(r, 'caret');
      await type(t, c, this.v1, 2.2); await wait(250); t.textContent = ''; await pop(m[0]); await wait(200); one(r, 'typing').hidden = false; await wait(1100); one(r, 'typing').hidden = true; await pop(m[1]); await wait(900);
      await type(t, c, this.v2, 1.6); await wait(250); t.textContent = ''; await pop(m[2]); await wait(200); one(r, 'typing').hidden = false; await wait(800); one(r, 'typing').hidden = true; await pop(m[3]); await wait(500);
      one(r, 'nolead').hidden = true; await pop(one(r, 'lead')); await wait(3500); } };

  seq.creative = { brief: 'Candlelit long table, twelve seats, autumn menu, warm and quiet. Our colours.',
    reset: function (r) { one(r, 'brief').textContent = ''; one(r, 'meter').style.transform = 'scaleX(0)'; one(r, 'state').textContent = 'Waiting for your brief'; lv(r, 'tile').forEach(function (t) { $('.lv-art', t).style.opacity = .12; }); lv(r, 'shimmer').forEach(function (s) { s.style.opacity = 1; }); },
    run: async function (r) { await type(one(r, 'brief'), null, this.brief, 2); one(r, 'state').textContent = 'Generating 4 variations…'; await meter(one(r, 'meter'), 1, 3);
      var tiles = lv(r, 'tile'); for (var i = 0; i < tiles.length; i++) { var art = $('.lv-art', tiles[i]); $('.lv-art__shimmer', tiles[i]).style.opacity = 0; M.animate(art, { opacity: [.12, 1], scale: [.96, 1] }, { duration: .6, ease: [.25, .8, .3, 1] }); await wait(450); }
      one(r, 'state').textContent = '4 ready · pick one'; await wait(3500); } };

  seq.video = { reset: function (r) { lv(r, 'scene').forEach(function (t) { $('.lv-art', t).style.opacity = .12; $('.lv-art__shimmer', t).style.opacity = 1; }); one(r, 'stage').textContent = 'Storyboard from your brief'; one(r, 'pct').textContent = '0'; one(r, 'meter').style.transform = 'scaleX(0)'; },
    run: async function (r) { var sc = lv(r, 'scene'); for (var i = 0; i < sc.length; i++) { $('.lv-art__shimmer', sc[i]).style.opacity = 0; M.animate($('.lv-art', sc[i]), { opacity: [.12, 1], scale: [.96, 1] }, { duration: .5 }); await wait(500); }
      one(r, 'stage').textContent = 'Rendering · voice-over, captions, your logo'; await Promise.all([count(one(r, 'pct'), 0, 100, 3), meter(one(r, 'meter'), 1, 3)]); one(r, 'stage').textContent = 'Ready · 14 s · 9:16 and 16:9'; await wait(3500); } };

  seq.automation = { reset: function (r) { lv(r, 'node').forEach(function (n) { n.classList.remove('is-on'); }); one(r, 'toast').style.opacity = 0; },
    run: async function (r) { var nodes = lv(r, 'node'), links = lv(r, 'link'); await wait(300);
      for (var i = 0; i < nodes.length; i++) { nodes[i].classList.add('is-on'); await pop(nodes[i]); if (links[i]) { var dot = $('i', links[i]); var vert = links[i].offsetHeight > links[i].offsetWidth; await M.animate(dot, vert ? { opacity: [0, 1, 0], y: [0, links[i].offsetHeight] } : { opacity: [0, 1, 0], x: [0, links[i].offsetWidth] }, { duration: .6, ease: 'easeInOut' }); } await wait(200); }
      await pop(one(r, 'toast')); await wait(3500); } };

  seq.aria = { q1: 'How do I connect the domain I already own?', q2: 'Yes please.',
    reset: function (r) { lv(r, 'm').forEach(function (m) { m.hidden = true; }); one(r, 'typing').hidden = true; one(r, 'typed').textContent = ''; },
    run: async function (r) { var m = lv(r, 'm'), t = one(r, 'typed'), c = one(r, 'caret');
      await type(t, c, this.q1, 2); await wait(250); t.textContent = ''; await pop(m[0]); await wait(200); one(r, 'typing').hidden = false; await wait(1200); one(r, 'typing').hidden = true; await pop(m[1]); await wait(1400);
      await type(t, c, this.q2, .7); await wait(200); t.textContent = ''; await pop(m[2]); await wait(200); one(r, 'typing').hidden = false; await wait(700); one(r, 'typing').hidden = true; await pop(m[3]); await wait(3500); } };

  seq.domains = { reset: function (r) { one(r, 'typed').textContent = ''; hide(lv(r, 'dom')); hide(lv(r, 'step')); },
    run: async function (r) { await type(one(r, 'typed'), one(r, 'caret'), 'blackdoor.com', 1.3); await wait(400); var d = lv(r, 'dom'); for (var i = 0; i < d.length; i++) { await pop(d[i]); await wait(150); }
      await wait(700); var st = lv(r, 'step'); for (var j = 0; j < st.length; j++) { await pop(st[j]); await wait(550); } await wait(3500); } };

  seq.email = { reset: function (r) { hide(lv(r, 'mail')); one(r, 'count').textContent = '0 new'; },
    run: async function (r) { var m = lv(r, 'mail'); for (var i = 0; i < m.length; i++) { await M.animate(m[i], { opacity: [0, 1], x: [-16, 0] }, { duration: .45, ease: [.25, .8, .3, 1] }); one(r, 'count').textContent = (i + 1) + ' new'; await wait(800); } await wait(3500); } };

  seq.hosting = { reset: function (r) { lv(r, 'meter').forEach(function (m) { m.style.transform = 'scaleX(0)'; }); lv(r, 'bar').forEach(function (b) { b.style.transform = 'scaleY(0)'; }); one(r, 'up').textContent = '99.10%'; one(r, 'ms').textContent = '240'; },
    run: async function (r) { lv(r, 'meter').forEach(function (m) { meter(m, +m.getAttribute('data-to') / 100, 1.6); }); stagger(lv(r, 'bar'), { scaleY: [0, 1] }, .06, { duration: .5 });
      await Promise.all([count(one(r, 'up'), 99.1, 99.99, 1.6, function (v) { return v.toFixed(2) + '%'; }), count(one(r, 'ms'), 240, 118, 1.6)]); await wait(4500); } };

  seq.cc = { reset: function (r) { lv(r, 'count').forEach(function (c) { c.textContent = '0'; }); hide($$('.lv-evt', r)); one(r, 'pending').textContent = '0'; },
    run: async function (r) { lv(r, 'count').forEach(function (c) { count(c, 0, +c.getAttribute('data-n'), 1.4); }); await wait(600); var ev = $$('.lv-evt', r); for (var i = 0; i < ev.length; i++) { await M.animate(ev[i], { opacity: [0, 1], x: [-14, 0] }, { duration: .45 }); await wait(600); } one(r, 'pending').textContent = '2'; await wait(4000); } };

  seq.workspaces = { reset: function (r) { hide(lv(r, 'ws')); lv(r, 'meter').forEach(function (m) { m.style.transform = 'scaleX(0)'; }); hide($$('.lv-evt', r)); one(r, 'toast').style.opacity = 0; },
    run: async function (r) { var ws = lv(r, 'ws'); for (var i = 0; i < ws.length; i++) { await pop(ws[i]); meter(one(ws[i], 'meter'), +one(ws[i], 'meter').getAttribute('data-to') / 100, 1.4); stagger($$('.lv-evt', ws[i]), { opacity: [0, 1], x: [-12, 0] }, .5, { delay: M.stagger(.5, { startDelay: .4 }) }); await wait(700); }
      await wait(1200); await pop(one(r, 'toast')); await wait(3500); } };

  panels.forEach(function (root) {
    var s = seq[root.getAttribute('data-live')]; if (!s) return;
    var visible = false, running = false;
    // Owner 09-30 ('section appears and disappears like it is gonna explode'): a panel may grow while it plays but never shrink, so the page below it never jumps. The markup at rest is the finished state, so the lock is the fullest height from the first frame.
    var lock = 0; var ratchet = function () { var h = root.getBoundingClientRect().height; if (h > lock + 1) { lock = h; root.style.minHeight = Math.ceil(h) + 'px'; } };
    ratchet(); if (window.ResizeObserver) new ResizeObserver(ratchet).observe(root);
    var rw = window.innerWidth; window.addEventListener('resize', function () { if (window.innerWidth === rw) return; rw = window.innerWidth; lock = 0; root.style.minHeight = ''; ratchet(); });
    async function cycle() { running = true; try { s.reset(root); await s.run(root); } catch (e) {} running = false; if (visible) cycle(); }
    M.inView(root, function () { visible = true; if (!running) cycle(); return function () { visible = false; }; }, { amount: .3, margin: '0px 0px 8% 0px' });
  });
})();
