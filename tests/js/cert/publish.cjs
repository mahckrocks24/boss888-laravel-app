// Continue a certification run from the editor: skip the tour, press Publish, use the address picker, verify live, watch Sarah.
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('fs'); const path = require('path');
const S = 'https://staging.levelupgrowth.io'; const TAG = process.argv[2]; const SUB = process.argv[3];
const OUT = path.join(__dirname, 'run-' + TAG); const acct = JSON.parse(fs.readFileSync(path.join(OUT, 'account.json'), 'utf8'));
const log = JSON.parse(fs.readFileSync(path.join(OUT, 'log.json'), 'utf8'));
const L = (k, v) => { const e = { t: new Date().toISOString().slice(11, 19), k, v }; log.push(e); console.log('[' + e.t + '] ' + k + ':', typeof v === 'string' ? v : JSON.stringify(v)); fs.writeFileSync(path.join(OUT, 'log.json'), JSON.stringify(log, null, 1)); };
const shot = (p, n) => p.screenshot({ path: path.join(OUT, n + '.jpg'), type: 'jpeg', quality: 60 }).catch(() => {});
(async () => {
  const site = JSON.parse(fs.readFileSync(path.join(OUT, 'site.json'), 'utf8')); const wid = site.websiteId;
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: acct.address, password: 'CertRun2026!x' }) })).json();
  const b = await chromium.launch(); const ctx = await b.newContext({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', viewport: { width: 1280, height: 800 } });
  await ctx.addInitScript(([t, r, w]) => { try { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); } catch (e) {} }, [login.access_token, login.refresh_token, login.current_workspace_id]);
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e).slice(0, 160)));
  const api = []; p.on('response', async r => { if (/\/api\//.test(r.url()) && !/dmm|agent|notif|batch|dashboard|approvals|growth|events|unread|auth\/me|refresh/.test(r.url())) { let body = ''; try { body = (await r.text()).slice(0, 600); } catch (e) {} api.push({ m: r.request().method(), u: r.url().replace(S, ''), s: r.status(), body }); } });
  await p.goto(S + '/app/websites/' + wid + '?edit=' + wid, { waitUntil: 'domcontentloaded', timeout: 90000 }); await p.waitForTimeout(12000);
  const tour = await p.evaluate(() => { const x = Array.from(document.querySelectorAll('button')).find(b => /Skip tour/i.test(b.innerText) && b.offsetParent); if (x) { x.click(); return true; } return false; });
  L('editor tour shown and skipped', tour); await p.waitForTimeout(1500);
  const clicked = await p.evaluate(() => { const x = Array.from(document.querySelectorAll('button')).find(b => /wsPublishFromEditor/.test(b.getAttribute('onclick') || '') && b.offsetParent); if (x) { x.click(); return x.innerText.trim(); } return null; });
  L('publish button clicked', clicked); await p.waitForTimeout(3000); await shot(p, '06-subdomain-picker');
  const pick = await p.evaluate(() => { const i = document.getElementById('subdomain-input'); return i ? { value: i.value, status: (document.getElementById('subdomain-status') || {}).innerText || '', confirmEnabled: !document.getElementById('subdomain-confirm').disabled } : null; });
  L('subdomain picker', pick);
  if (SUB && pick) { await p.fill('#subdomain-input', SUB); await p.waitForTimeout(3000); L('subdomain typed', { value: SUB, status: await p.evaluate(() => (document.getElementById('subdomain-status') || {}).innerText || ''), confirmEnabled: await p.evaluate(() => !document.getElementById('subdomain-confirm').disabled) }); }
  for (let i = 0; i < 20; i++) { if (await p.evaluate(() => { const c = document.getElementById('subdomain-confirm'); return !!(c && !c.disabled); })) break; await p.waitForTimeout(500); }
  const t0 = Date.now(); await p.evaluate(() => { const c = document.getElementById('subdomain-confirm'); if (c) c.click(); });
  for (let i = 0; i < 30 && !api.some(a => /\/publish/.test(a.u)); i++) await p.waitForTimeout(1000);
  await p.waitForTimeout(4000); await shot(p, '07-published');
  const pub = api.filter(a => /\/publish|subdomain/.test(a.u)); L('publish calls', pub.map(a => ({ m: a.m, u: a.u, s: a.s, body: a.body.slice(0, 300) })));
  let url = null; for (const a of pub) { try { const j = JSON.parse(a.body); if (j.url) url = j.url; } catch (e) {} }
  L('published url', { url, secs: Math.round((Date.now() - t0) / 1000), screenText: await p.evaluate(() => (document.body.innerText.match(/(live|published)[^\n]{0,120}/i) || [''])[0]) });
  site.url = url; fs.writeFileSync(path.join(OUT, 'site.json'), JSON.stringify(site));
  const H = { authorization: 'Bearer ' + login.access_token, accept: 'application/json', 'X-Workspace-Id': String(login.current_workspace_id) };
  let sarah = []; for (let i = 0; i < 14; i++) { await new Promise(r => setTimeout(r, 5000)); const m = await (await fetch(S + '/api/agents/dmm/messages?limit=50', { headers: H })).json(); const l = m.messages || m.data || m; if (Array.isArray(l) && l.length) { sarah = l.map(x => String(x.content || '').replace(/\s+/g, ' ').slice(0, 600)); break; } }
  L('sarah after publish', sarah); L('errors', errs);
  await b.close();
})();
