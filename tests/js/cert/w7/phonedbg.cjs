// Phone: what happens after tapping Fonts — every API response with its timing, and the panel's own text, for 60 s.
const { chromium } = require('C:/Users/markr/AppData/Local/Temp/claude/C--Users-markr/a1258e38-7a7c-49ce-9c8f-89952f08f6c5/scratchpad/xb/node_modules/playwright');
const path = require('path'); const S = 'https://staging.levelupgrowth.io';
const rec = (k, v) => console.log(k.padEnd(22), typeof v === 'string' ? v.slice(0, 600) : JSON.stringify(v).slice(0, 600));
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: 'lug-w4-muoo51ky@uberip.com', password: 'EdgeTest2026!q' }) })).json();
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true, userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36' });
  await ctx.addInitScript(([t, r, w]) => { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); }, [login.access_token, login.refresh_token, login.current_workspace_id]);
  const p = await ctx.newPage(); const t0 = Date.now(); const net = []; const pending = new Map();
  p.on('request', r => { if (/\/api\//.test(r.url())) pending.set(r, Date.now()); });
  p.on('response', async r => { const q = r.request(); if (!/\/api\//.test(r.url())) return; const st = pending.get(q) || Date.now(); pending.delete(q); net.push(((st - t0) / 1000).toFixed(1) + 's +' + ((Date.now() - st) / 1000).toFixed(1) + 's ' + q.method() + ' ' + r.status() + ' ' + r.url().replace(S, '').slice(0, 70)); });
  p.on('pageerror', e => rec('PAGE ERROR', String(e.message)));
  p.on('console', m => { if (m.type() === 'error') rec('CONSOLE', m.text()); });
  const click = async (sel, txt) => p.evaluate(([sel, txt]) => { const x = Array.from(document.querySelectorAll(sel)).find(b => b.offsetParent && b.getBoundingClientRect().width && (!txt || (b.innerText || '').trim().startsWith(txt))); if (x) { x.click(); return true; } return false; }, [sel, txt]);
  await p.goto(S + '/app/websites/1016?edit=1016', { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('#t3-preview', { timeout: 60000 }); await p.waitForTimeout(4000); await click('button', 'Got it');
  await p.waitForSelector('#t3-more-btn', { timeout: 20000 }).catch(() => {});
  let okF = false; for (let i = 0; i < 10 && !okF; i++) { await click('#t3-more-btn'); await p.waitForTimeout(900); okF = await click('button', 'Fonts'); if (!okF) await p.waitForTimeout(800); }
  const tF = ((Date.now() - t0) / 1000).toFixed(1); rec('Fonts tapped at', tF + 's ok=' + okF);
  for (let i = 1; i <= 60; i++) {
    await p.waitForTimeout(1000);
    const st = await p.evaluate(() => { const pn = document.getElementById('t3-fonts'); return pn ? [pn.querySelectorAll('button[data-pair]').length, (pn.innerText || '').replace(/\s+/g, ' ').slice(0, 160)] : ['no panel']; });
    if (st[0] > 0 || i % 10 === 0) rec(i + 's panel', st);
    if (st[0] > 0) break;
  }
  rec('pending now', Array.from(pending.keys()).map(q => q.method() + ' ' + q.url().replace(S, '').slice(0, 70)));
  rec('net', net.filter(x => /fonts|flags|preview|changes|arthur/.test(x)));
  await p.screenshot({ path: path.join(__dirname, 'sheet-phone', 'phone-fonts-open.jpg'), type: 'jpeg', quality: 60 });
  await b.close();
})();
