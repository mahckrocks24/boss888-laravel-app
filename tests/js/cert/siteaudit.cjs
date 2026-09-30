// Audit a published customer site against the brief: reachability, TLS, every page, facts, images, SEO, a11y, mobile, forms, chatbot, speed.
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('fs'); const path = require('path');
const URL0 = process.argv[2]; const FACTS = JSON.parse(process.argv[3] || '[]'); const OUT = process.argv[4] || __dirname;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
(async () => {
  const res = {};
  const t0 = Date.now(); const r = await fetch(URL0, { headers: { 'user-agent': UA }, redirect: 'manual' }); res.home = { status: r.status, ms: Date.now() - t0, location: r.headers.get('location'), hsts: r.headers.get('strict-transport-security'), csp: !!r.headers.get('content-security-policy'), xfo: r.headers.get('x-frame-options'), bytes: (await r.text()).length };
  const http = await fetch(URL0.replace('https://', 'http://'), { headers: { 'user-agent': UA }, redirect: 'manual' }).catch(e => ({ status: 'ERR ' + e.message })); res.httpToHttps = { status: http.status, location: http.headers && http.headers.get('location') };
  for (const f of ['/robots.txt', '/sitemap.xml', '/favicon.ico', '/does-not-exist-xyz']) { const x = await fetch(URL0.replace(/\/$/, '') + f, { headers: { 'user-agent': UA } }); res[f] = { status: x.status, type: x.headers.get('content-type'), head: (await x.text()).slice(0, 160).replace(/\s+/g, ' ') }; }
  const b = await chromium.launch(); const pages = new Set([URL0]); const report = [];
  for (const [tag, vp, mobile] of [['desk', { width: 1280, height: 800 }, false], ['phone', { width: 412, height: 915 }, true]]) {
    const ctx = await b.newContext({ userAgent: mobile ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36' : UA, viewport: vp, isMobile: mobile, hasTouch: mobile });
    const p = await ctx.newPage(); const errs = []; const failed = [];
    p.on('pageerror', e => errs.push(String(e).slice(0, 140))); p.on('console', m => { if (m.type() === 'error') errs.push('C ' + m.text().slice(0, 140)); });
    p.on('requestfailed', q => failed.push(q.url().slice(0, 120))); p.on('response', q => { if (q.status() >= 400) failed.push(q.status() + ' ' + q.url().slice(0, 120)); });
    const t1 = Date.now(); await p.goto(URL0, { waitUntil: 'load', timeout: 90000 }); const loadMs = Date.now() - t1; await p.waitForTimeout(2500);
    const a = await p.evaluate((facts) => {
      const txt = document.body.innerText; const html = document.documentElement.outerHTML;
      const meta = n => (document.querySelector('meta[name="' + n + '"],meta[property="' + n + '"]') || {}).content || null;
      const imgs = Array.from(document.images).map(i => ({ src: i.currentSrc || i.src, ok: i.complete && i.naturalWidth > 0, alt: i.getAttribute('alt'), w: i.naturalWidth }));
      const links = Array.from(document.querySelectorAll('a[href]')).map(x => ({ href: x.href, text: x.innerText.trim().slice(0, 30) }));
      const placeholders = (txt.match(/lorem|ipsum|\{\{|\}\}|\[.*?(name|phone|address|email).*?\]|placeholder|your business|example\.com|555-0|TODO|undefined|null\b|NaN|template/gi) || []).slice(0, 12);
      const forms = Array.from(document.forms).map(f => ({ action: f.getAttribute('action'), fields: Array.from(f.elements).map(e => e.name || e.type).filter(Boolean).slice(0, 12) }));
      const ld = Array.from(document.querySelectorAll('script[type="application/ld+json"]')).map(s => { try { const j = JSON.parse(s.textContent); return j['@type'] || (j['@graph'] || []).map(g => g['@type']).join(','); } catch (e) { return 'INVALID'; } });
      return { title: document.title, desc: meta('description'), ogTitle: meta('og:title'), ogImage: meta('og:image'), canonical: (document.querySelector('link[rel=canonical]') || {}).href || null, lang: document.documentElement.lang, viewport: meta('viewport'), favicon: !!document.querySelector('link[rel*=icon]'),
        h1: Array.from(document.querySelectorAll('h1')).map(h => h.innerText.trim().slice(0, 80)), h2: Array.from(document.querySelectorAll('h2')).map(h => h.innerText.trim().slice(0, 60)).slice(0, 14),
        facts: facts.map(f => [f, txt.toLowerCase().includes(f.toLowerCase())]), placeholders, imgs: { total: imgs.length, broken: imgs.filter(i => !i.ok).map(i => i.src.slice(0, 100)), noAlt: imgs.filter(i => i.alt === null || i.alt === '').length, uploaded: imgs.filter(i => /upload|tmp|arthur|logo_/.test(i.src)).map(i => i.src.slice(0, 100)) },
        links: links.length, navLinks: Array.from(document.querySelectorAll('nav a, header a')).map(x => x.innerText.trim() + ' -> ' + x.getAttribute('href')).slice(0, 14), telLinks: links.filter(l => /^tel:/.test(l.href)).map(l => l.href), mailLinks: links.filter(l => /^mailto:/.test(l.href)).map(l => l.href),
        forms, ld, overflowX: document.documentElement.scrollWidth - innerWidth, chatbot: !!document.querySelector('[id^=cb888], script[src*=chatbot]'), adBar: /LevelUpGrowth/i.test(txt.slice(-600)) || !!document.querySelector('[class*=lug-ad], [id*=lug-ad]'), words: txt.split(/\s+/).length, poweredBy: (txt.match(/(built|made|powered) (with|by)[^\n]{0,40}/i) || [null])[0] };
    }, FACTS);
    for (const l of await p.evaluate(() => Array.from(document.querySelectorAll('a[href]')).map(a => a.href))) { try { const u = new URL(l); if (u.host === new URL(location.href).host && !u.hash) pages.add(u.origin + u.pathname); } catch (e) {} }
    await p.screenshot({ path: path.join(OUT, 'live-' + tag + '.jpg'), type: 'jpeg', quality: 55, fullPage: true });
    report.push({ tag, loadMs, errors: errs.slice(0, 6), failedRequests: [...new Set(failed)].slice(0, 10), ...a });
    await ctx.close();
  }
  // every internal page
  const pageRes = [];
  for (const u of pages) { const x = await fetch(u, { headers: { 'user-agent': UA } }); const t = await x.text(); pageRes.push({ url: u.replace(URL0.replace(/\/$/, ''), '') || '/', status: x.status, title: (t.match(/<title>([^<]*)/) || [])[1], words: t.replace(/<[^>]+>/g, ' ').split(/\s+/).length }); }
  res.pages = pageRes; res.render = report;
  fs.writeFileSync(path.join(OUT, 'siteaudit.json'), JSON.stringify(res, null, 1));
  console.log(JSON.stringify(res, null, 1).slice(0, 9000));
  await b.close();
})();
