// Arthur's editing after publish, through the editor's own Ask Arthur box. Each ask: reply, time, credits, did the page change, did the LIVE site change.
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('fs'); const path = require('path'); const S = 'https://staging.levelupgrowth.io';
const TAG = process.argv[2]; const OUT = path.join(__dirname, 'run-' + TAG); const acct = JSON.parse(fs.readFileSync(path.join(OUT, 'account.json'), 'utf8')); const site = JSON.parse(fs.readFileSync(path.join(OUT, 'site.json'), 'utf8'));
const ASKS = JSON.parse(process.argv[3]); const LIVE = process.argv[4];
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: acct.address, password: 'CertRun2026!x' }) })).json();
  const H = { authorization: 'Bearer ' + login.access_token, accept: 'application/json' };
  const bal = async () => { const r = await (await fetch(S + '/api/billing/credits', { headers: H })).json().catch(() => ({})); return r.balance ?? r.available ?? (r.data && r.data.balance) ?? JSON.stringify(r).slice(0, 60); };
  const live = async () => (await (await fetch(LIVE, { headers: { 'user-agent': 'Mozilla/5.0 Chrome/140' }, cache: 'no-store' })).text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1280, height: 800 }, userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
  await ctx.addInitScript(([t, r, w]) => { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); }, [login.access_token, login.refresh_token, login.current_workspace_id]);
  const p = await ctx.newPage(); const api = []; p.on('response', async r => { if (/arthur-edit|arthur\/|\/pages\//.test(r.url()) && r.request().method() !== 'GET') { let body = ''; try { body = (await r.text()).slice(0, 700); } catch (e) {} api.push({ u: r.url().replace(S, ''), s: r.status(), body }); } });
  await p.goto(S + '/app/websites/' + site.websiteId + '?edit=' + site.websiteId, { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(14000);
  await p.evaluate(() => { const x = Array.from(document.querySelectorAll('button')).find(b => /Skip tour/i.test(b.innerText) && b.offsetParent); if (x) x.click(); }); await p.waitForTimeout(1500);
  for (const ask of ASKS) {
    const b0 = await bal(); const n0 = api.length; const t0 = Date.now();
    const box = p.locator('textarea[placeholder*="Ask Arthur"]:visible, input[placeholder*="Ask Arthur"]:visible').first(); if (!(await box.count())) { console.log('no visible Ask Arthur box'); break; }
    await box.fill(ask.say); await box.press('Enter');
    let reply = ''; for (let i = 0; i < 90; i++) { await p.waitForTimeout(1000); if (api.length > n0 && i > 3) { await p.waitForTimeout(3000); break; } }
    reply = await p.evaluate(() => { const panel = Array.from(document.querySelectorAll('div')).find(d => /Ask Arthur/.test((d.querySelector('textarea, input') || {}).placeholder || '')); const msgs = Array.from(document.querySelectorAll('[class*=arthur] [class*=msg], [class*=pe-chat] div, #pe-arthur-feed > div')).map(x => x.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean); return msgs.slice(-2).join(' || ').slice(0, 500); });
    const frame = p.frames().find(f => f !== p.mainFrame()); const draft = frame ? (await frame.evaluate(() => document.body.innerText).catch(() => '')).replace(/\s+/g, ' ') : '';
    const lv = await live();
    const check = (t, needles) => needles.map(n => [n, t.toLowerCase().includes(n.toLowerCase())]);
    console.log(JSON.stringify({ ask: ask.say, secs: Math.round((Date.now() - t0) / 1000), api: api.slice(n0).map(a => a.s + ' ' + a.u + ' ' + a.body.slice(0, 220)), reply, credits: b0 + ' -> ' + (await bal()), draftHas: check(draft, ask.expect || []), draftLacks: check(draft, ask.gone || []), liveHas: check(lv, ask.expect || []), liveLacks: check(lv, ask.gone || []) }));
  }
  await p.screenshot({ path: path.join(OUT, '08-after-edits.jpg'), type: 'jpeg', quality: 60 });
  await b.close();
})();
