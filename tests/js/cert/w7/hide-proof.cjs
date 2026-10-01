// HIDE-CONNECT-1 proof: the door is shut — route 404, flag false, the Websites page shows no Connect button, the picker opens Arthur.
const { chromium } = require('C:/Users/markr/AppData/Local/Temp/claude/C--Users-markr/a1258e38-7a7c-49ce-9c8f-89952f08f6c5/scratchpad/xb/node_modules/playwright');
const fs = require('fs'); const path = require('path'); const S = 'https://staging.levelupgrowth.io';
const rec = (k, v) => console.log(k.padEnd(26), typeof v === 'string' ? v.slice(0, 300) : String(JSON.stringify(v)).slice(0, 300));
(async () => {
  const acct = JSON.parse(fs.readFileSync(path.join(__dirname, 'connect-acct.json'), 'utf8'));
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: acct.email, password: 'EdgeTest2026!q' }) })).json();
  const H = { authorization: 'Bearer ' + login.access_token, accept: 'application/json', 'content-type': 'application/json' };
  const r = await fetch(S + '/api/builder/websites/connect-existing', { method: 'POST', headers: H, body: JSON.stringify({ url: 'https://example.org' }) });
  rec('route with token', r.status + ' ' + (await r.text()).slice(0, 80));
  rec('flags', JSON.stringify(await (await fetch(S + '/api/builder/flags', { headers: H })).json()));
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  await ctx.addInitScript(([t, r, w]) => { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); }, [login.access_token, login.refresh_token, login.current_workspace_id]);
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e.message).slice(0, 120)));
  await p.goto(S + '/app/websites', { waitUntil: 'domcontentloaded' });
  await p.waitForFunction(() => document.querySelectorAll('.ws-card').length > 0 || /No websites|New Website/.test(document.body.innerText), null, { timeout: 60000 }).catch(() => {});
  await p.waitForTimeout(3000); rec('where', await p.evaluate(() => location.href + ' :: ' + (document.body ? document.body.innerText.replace(/s+/g, ' ').slice(0, 160) : 'nobody') + ' :: wsLoadSites=' + typeof wsLoadSites + ' cards=' + document.querySelectorAll('.ws-card').length));
  rec('header buttons', await p.evaluate(() => Array.from(document.querySelectorAll('#ws-connect-existing-btn, button.ct-btn.primary')).filter(b => b.closest('#websites-page, [id*=website]') || true).map(b => (b.innerText || '').trim() + (b.offsetParent ? '' : '(hidden)')).filter(t => /Connect|New Website/.test(t)).slice(0, 6)));
  rec('connect btn display', await p.evaluate(() => { const b = document.getElementById('ws-connect-existing-btn'); return b ? getComputedStyle(b).display : 'no element'; }));
  rec('luFlags', await p.evaluate(() => window._luFlags));
  await p.evaluate(() => window._bldShowTemplatePicker && window._bldShowTemplatePicker()); await p.waitForTimeout(1500);
  rec('after picker call', await p.evaluate(() => ({ picker: !!document.getElementById('lu-wizard-picker'), create: !!(document.getElementById('ws-create-modal') && document.getElementById('ws-create-modal').style.display !== 'none'), text: (document.body.innerText.match(/Use Existing Website|Connect Your Existing Website/g) || []).length })));
  await p.screenshot({ path: path.join(__dirname, 'sheet', 'hide-connect.jpg'), type: 'jpeg', quality: 55 });
  rec('page errors', errs);
  await b.close();
})();
