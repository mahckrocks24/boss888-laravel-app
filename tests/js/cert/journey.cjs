// CERTIFICATION: the "Build my website" journey end to end, as a real customer, through the UI.
// Records every step, every API response, timings, screenshots. Audit only.
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('fs'); const path = require('path');
const S = 'https://staging.levelupgrowth.io';
const TAG = process.argv[2] || 'bakery';
const SCEN = JSON.parse(fs.readFileSync(path.join(__dirname, 'scenarios.json'), 'utf8'))[TAG];
const OUT = path.join(__dirname, 'run-' + TAG); fs.mkdirSync(OUT, { recursive: true });
const log = []; const L = (k, v) => { const e = { t: new Date().toISOString().slice(11, 19), k, v }; log.push(e); console.log('[' + e.t + '] ' + k + ':', typeof v === 'string' ? v : JSON.stringify(v)); fs.writeFileSync(path.join(OUT, 'log.json'), JSON.stringify(log, null, 1)); };
const shot = (p, n) => p.screenshot({ path: path.join(OUT, n + '.jpg'), type: 'jpeg', quality: 60 }).catch(() => {});
(async () => {
  const d = await (await fetch('https://api.mail.tm/domains')).json(); const dom = (d['hydra:member'] || d)[0].domain;
  const address = 'lug-cert-' + TAG + '-' + Date.now().toString(36) + '@' + dom; const mpw = 'Mt-' + Math.random().toString(36).slice(2, 10) + 'Q9';
  await fetch('https://api.mail.tm/accounts', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ address, password: mpw }) });
  const b = await chromium.launch();
  const vp = SCEN.phone ? { width: 412, height: 915 } : { width: 1280, height: 800 };
  const ua = SCEN.phone ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36' : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
  const ctx = await b.newContext({ userAgent: ua, viewport: vp, isMobile: !!SCEN.phone, hasTouch: !!SCEN.phone }); const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push('PAGE ' + String(e).slice(0, 160))); p.on('console', m => { if (m.type() === 'error') errs.push('CONSOLE ' + m.text().slice(0, 160)); });
  const api = []; p.on('response', async r => { const u = r.url(); if (/\/api\/(builder|auth\/register|agents\/dmm)/.test(u)) { let body = ''; try { body = (await r.text()).slice(0, 1500); } catch (e) {} api.push({ t: Date.now(), m: r.request().method(), u: u.replace(S, ''), s: r.status(), body }); } });
  // 1. sign up
  await p.goto(S + '/start/', { waitUntil: 'networkidle', timeout: 90000 });
  await p.fill('#wl-name, input[name=name]', SCEN.person); await p.fill('#wl-email, input[name=email]', address); await p.fill('#wl-pass', 'CertRun2026!x');
  if (SCEN.company) { const biz = await p.$('input[name=business_name], input[name=workspace_name], #wl-business, input[name=company]'); if (biz) await biz.fill(SCEN.company); }
  await p.click('button[type=submit]'); for (let i = 0; i < 25 && !/\/app\//.test(p.url()); i++) await p.waitForTimeout(1000);
  await p.waitForTimeout(8000);
  const tok = await p.evaluate(() => localStorage.getItem('lu_token')); const ws = await p.evaluate(() => localStorage.getItem('lu_workspace_id'));
  L('signup', { address, ws, url: p.url(), lock: await p.evaluate(() => document.documentElement.classList.contains('lu-arthur-lock')), arthur: await p.evaluate(() => !!document.getElementById('arthur-modal')) });
  fs.writeFileSync(path.join(OUT, 'account.json'), JSON.stringify({ address, mpw, ws, tok }));
  await shot(p, '01-arthur');
  // 2. converse until the finishing touches appear
  const feedText = () => p.evaluate(() => { const f = document.getElementById('arthur-feed'); return f ? Array.from(f.children).map(c => c.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean) : []; });
  let touches = false;
  for (let turn = 0; turn < SCEN.replies.length && !touches; turn++) {
    const before = (await feedText()).length; const t0 = Date.now();
    const inp = await p.$('#arthur-chat-input'); if (!inp || !(await inp.isEnabled())) { L('input disabled at turn', turn + 1); break; }
    await inp.fill(SCEN.replies[turn]); await inp.press('Enter');
    let reply = '';
    for (let i = 0; i < 80; i++) { await p.waitForTimeout(1000);
      touches = await p.evaluate(() => !!Array.from(document.querySelectorAll('#arthur-modal button')).find(x => /Build my website/i.test(x.innerText)));
      const f = await feedText(); const typing = await p.evaluate(() => !!document.getElementById('arthur-typing'));
      if (touches || (f.length > before + 1 && !typing && i > 2)) { reply = f.slice(before + 1).join(' || '); break; } }
    L('turn ' + (turn + 1), { you: SCEN.replies[turn], arthur: reply.slice(0, 700), secs: Math.round((Date.now() - t0) / 1000), touches });
  }
  await shot(p, '02-touches');
  if (!touches) { L('RESULT', 'finishing touches never appeared'); fs.writeFileSync(path.join(OUT, 'api.json'), JSON.stringify(api, null, 1)); L('errors', errs); await b.close(); return; }
  // 3. finishing touches: logo, photos, theme
  if (SCEN.logo) { const li = await p.$('#arthur-logo-input'); if (li) { await li.setInputFiles(path.join(__dirname, SCEN.logo)); await p.waitForTimeout(6000); } }
  const logoState = await p.evaluate(() => ({ logo: window._arthurLogoUrl || null }));
  if (SCEN.photos) { try { await p.evaluate(() => window._arthurStepGo && window._arthurStepGo(2)); } catch (e) {} const ii = await p.$('#arthur-images-input'); if (ii) { await ii.setInputFiles(SCEN.photos.map(f => path.join(__dirname, f))); await p.waitForTimeout(9000); } }
  const upState = await p.evaluate(() => ({ logo: window._arthurLogoUrl || null, images: (window._arthurImages || []).length, theme: window._arthurTheme ? (window._arthurTheme.name || window._arthurTheme.id || 'set') : null, colors: window._arthurColors || null, build_data: window._arthurBuildData || null }));
  L('finishing touches', { logoAfterUpload: logoState.logo, ...upState });
  await shot(p, '03-touches-filled');
  // 4. build
  const tB = Date.now();
  await p.evaluate(() => { const x = Array.from(document.querySelectorAll('#arthur-modal button')).find(b => /Build my website/i.test(b.innerText)); if (x) x.click(); });
  if (SCEN.doubleClickBuild) { await p.waitForTimeout(300); await p.evaluate(() => { const x = Array.from(document.querySelectorAll('#arthur-modal button')).find(b => /Build my website/i.test(b.innerText)); if (x) x.click(); }); }
  let card = false; for (let i = 0; i < 240; i++) { await p.waitForTimeout(1000); card = await p.evaluate(() => /Website Created/.test((document.getElementById('arthur-feed') || {}).innerText || '')); const err = await p.evaluate(() => /Build (error|failed|incomplete)|⚠️/.test((document.getElementById('arthur-feed') || {}).innerText || '')); if (card || err) break; }
  const confirmResp = api.filter(a => /arthur\/message/.test(a.u) && a.m === 'POST').slice(-1)[0];
  L('build', { secs: Math.round((Date.now() - tB) / 1000), card, http: confirmResp && confirmResp.s, resp: confirmResp && confirmResp.body.slice(0, 900), lastFeed: (await feedText()).slice(-3) });
  await shot(p, '04-built');
  let websiteId = null; try { websiteId = JSON.parse(confirmResp.body).website_id; } catch (e) {}
  if (!card || !websiteId) { fs.writeFileSync(path.join(OUT, 'api.json'), JSON.stringify(api, null, 1)); L('errors', errs); await b.close(); return; }
  L('after build', { lockStill: await p.evaluate(() => document.documentElement.classList.contains('lu-arthur-lock')), inputDisabled: await p.evaluate(() => { const i = document.getElementById('arthur-chat-input'); return i ? i.disabled : null; }) });
  // draft preview
  const prev = await fetch(S + '/storage/sites/' + websiteId + '/index.html', { headers: { 'user-agent': ua } }); L('draft preview /storage/sites/' + websiteId, { status: prev.status });
  // 5. open editor
  await p.evaluate(() => { const x = Array.from(document.querySelectorAll('#arthur-feed button')).find(b => /editor|Open/i.test(b.innerText)); if (x) x.click(); });
  await p.waitForTimeout(12000);
  L('editor', { url: p.url(), lock: await p.evaluate(() => document.documentElement.classList.contains('lu-arthur-lock')), modal: await p.evaluate(() => !!document.getElementById('arthur-modal')), lockKey: await p.evaluate(() => localStorage.getItem('lu_arthur_lock')), publishBtn: await p.evaluate(() => !!document.getElementById('pe-publish') || !!Array.from(document.querySelectorAll('button')).find(b => /^\s*Publish/i.test(b.innerText) && b.offsetParent)) });
  await shot(p, '05-editor');
  // 6. publish through the UI
  const pubBtn = await p.evaluateHandle(() => document.getElementById('pe-publish') || Array.from(document.querySelectorAll('button')).find(b => /^\s*Publish/i.test(b.innerText) && b.offsetParent));
  if (pubBtn && (await pubBtn.evaluate(e => !!e))) { await pubBtn.evaluate(e => e.click()); } else { await p.evaluate(id => window.wsPublishFromEditor && window.wsPublishFromEditor(id, ''), websiteId); }
  await p.waitForTimeout(2500); await shot(p, '06-subdomain-picker');
  const pick = await p.evaluate(() => { const i = document.getElementById('subdomain-input'); return i ? { value: i.value, status: (document.getElementById('subdomain-status') || {}).innerText || '' } : null; });
  L('subdomain picker', pick);
  if (SCEN.subdomain !== undefined && pick) { await p.fill('#subdomain-input', SCEN.subdomain); await p.waitForTimeout(2500); L('subdomain typed', { value: SCEN.subdomain, status: await p.evaluate(() => (document.getElementById('subdomain-status') || {}).innerText || '') }); }
  for (let i = 0; i < 20; i++) { const en = await p.evaluate(() => { const c = document.getElementById('subdomain-confirm'); return c && !c.disabled; }); if (en) break; await p.waitForTimeout(500); }
  await p.evaluate(() => { const c = document.getElementById('subdomain-confirm'); if (c) c.click(); });
  await p.waitForTimeout(8000); await shot(p, '07-published');
  const pubResp = api.filter(a => /\/publish/.test(a.u)).slice(-1)[0]; L('publish', pubResp ? { s: pubResp.s, body: pubResp.body.slice(0, 400) } : 'no publish call');
  let url = null; try { url = JSON.parse(pubResp.body).url; } catch (e) {}
  fs.writeFileSync(path.join(OUT, 'site.json'), JSON.stringify({ websiteId, url, ws }));
  // 7. Sarah after publish
  const H = { authorization: 'Bearer ' + tok, accept: 'application/json', 'X-Workspace-Id': String(ws) };
  let sarah = []; for (let i = 0; i < 12; i++) { await new Promise(r => setTimeout(r, 5000)); const m = await (await fetch(S + '/api/agents/dmm/messages?limit=50', { headers: H })).json(); const l = m.messages || m.data || m; if (Array.isArray(l) && l.length) { sarah = l.map(x => String(x.content || '').replace(/\s+/g, ' ').slice(0, 500)); break; } }
  L('sarah after publish', sarah);
  fs.writeFileSync(path.join(OUT, 'api.json'), JSON.stringify(api, null, 1)); L('errors', errs);
  await b.close();
})();
