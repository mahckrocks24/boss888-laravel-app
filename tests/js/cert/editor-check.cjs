const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('fs'); const path = require('path'); const S = 'https://staging.levelupgrowth.io';
const acct = JSON.parse(fs.readFileSync(path.join(__dirname, 'run-bakery', 'account.json'), 'utf8'));
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: acct.address, password: 'CertRun2026!x' }) })).json();
  const b = await chromium.launch();
  for (const [tag, vp, mobile] of [['desk', { width: 1280, height: 800 }, false], ['phone', { width: 412, height: 915 }, true]]) {
    const ctx = await b.newContext({ viewport: vp, isMobile: mobile, hasTouch: mobile, userAgent: mobile ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36' : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
    await ctx.addInitScript(([t, r, w]) => { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); }, [login.access_token, login.refresh_token, login.current_workspace_id]);
    const p = await ctx.newPage();
    // the way a returning owner gets there: app -> Websites -> the site
    await p.goto(S + '/app/', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(8000);
    await p.goto(S + '/app/websites', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(8000);
    const card = await p.evaluate(() => { const x = Array.from(document.querySelectorAll('button, a')).find(e => e.innerText.trim() === 'Edit' && e.offsetParent); if (x) { const t = x.innerText.trim(); x.click(); return t; } return null; });
    await p.waitForTimeout(15000);
    await p.evaluate(() => { const x = Array.from(document.querySelectorAll('button')).find(b => /Skip tour/i.test(b.innerText) && b.offsetParent); if (x) x.click(); }); await p.waitForTimeout(2000);
    const frameText = await Promise.all(p.frames().filter(f => f !== p.mainFrame()).map(f => f.evaluate(() => document.body ? document.body.innerText.length : 0).catch(() => -1)));
    console.log(tag, 'opened via', JSON.stringify(card), 'url', p.url().replace(S, ''), 'preview iframe text lengths', frameText);
    await p.screenshot({ path: path.join(__dirname, 'run-bakery', 'editor-' + tag + '.jpg'), type: 'jpeg', quality: 55 });
    if (tag === 'desk') {
      const box = p.locator('textarea[placeholder*="Ask Arthur"]:visible, input[placeholder*="Ask Arthur"]:visible').first();
      await box.fill('Remove the testimonials section.'); await box.press('Enter'); await p.waitForTimeout(15000);
      const panel = await p.evaluate(() => { const el = document.querySelector('textarea[placeholder*="Ask Arthur"]:not([style*="display: none"])'); let n = el; for (let i = 0; i < 6 && n; i++) n = n.parentElement; return n ? n.innerText.replace(/\s+/g, ' ').slice(-600) : ''; });
      console.log('desk Arthur panel after ask:', panel);
      await p.screenshot({ path: path.join(__dirname, 'run-bakery', 'editor-after-ask.jpg'), type: 'jpeg', quality: 55 });
    }
    await ctx.close();
  }
  await b.close();
})();
