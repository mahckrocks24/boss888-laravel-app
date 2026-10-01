// Who is on top at a Fonts card and at a Colours card? (elementFromPoint in the editor document)
const { chromium } = require('C:/Users/markr/AppData/Local/Temp/claude/C--Users-markr/a1258e38-7a7c-49ce-9c8f-89952f08f6c5/scratchpad/xb/node_modules/playwright');
const S = 'https://staging.levelupgrowth.io';
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: 'lug-w4-muoo51ky@uberip.com', password: 'EdgeTest2026!q' }) })).json();
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  await ctx.addInitScript(([t, r, w]) => { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); }, [login.access_token, login.refresh_token, login.current_workspace_id]);
  const p = await ctx.newPage();
  const click = async (sel, txt) => p.evaluate(([sel, txt]) => { const x = Array.from(document.querySelectorAll(sel)).find(b => b.offsetParent && b.getBoundingClientRect().width && (!txt || (b.innerText || '').trim().startsWith(txt))); if (x) { x.click(); return true; } return false; }, [sel, txt]);
  await p.goto(S + '/app/websites', { waitUntil: 'domcontentloaded' });
  await p.waitForFunction(() => Array.from(document.querySelectorAll('button')).some(e => e.innerText.trim() === 'Edit' && e.offsetParent), null, { timeout: 60000 }).catch(() => {});
  await click('button', 'Got it'); await click('button', 'Edit');
  await p.waitForSelector('#t3-preview', { timeout: 30000 }).catch(() => {});
  await p.waitForTimeout(6000); await click('button', 'Skip tour'); await click('button', 'Got it'); await p.waitForTimeout(1000);
  const probe = async (panelId, cardSel) => p.evaluate(([panelId, cardSel]) => {
    const c = document.querySelector(cardSel); if (!c) return 'no card';
    const r = c.getBoundingClientRect(); const x = r.x + r.width / 2, y = r.y + r.height / 2;
    const e = document.elementFromPoint(x, y);
    const panel = document.getElementById(panelId); const st = getComputedStyle(panel); const fr = document.getElementById('t3-preview'); const fs = getComputedStyle(fr);
    return { at: [Math.round(x), Math.round(y)], top: e ? (e.tagName + '#' + e.id + '.' + e.className).slice(0, 60) : null, inPanel: !!(e && panel.contains(e)), panelZ: st.zIndex, panelPos: st.position, parent: panel.parentElement.id || panel.parentElement.className, frameZ: fs.zIndex, framePos: fs.position, stagePos: getComputedStyle(panel.parentElement).position };
  }, [panelId, cardSel]);
  await click('button', 'Colours'); await p.waitForSelector('#t3-pal button[data-pal]', { timeout: 20000 }).catch(() => {}); await p.waitForTimeout(1500);
  console.log('COLOURS', JSON.stringify(await probe('t3-pal', '#t3-pal button[data-pal]')));
  await click('button', 'Fonts'); await p.waitForSelector('#t3-fonts button[data-pair]', { timeout: 20000 }).catch(() => {}); await p.waitForTimeout(1500);
  console.log('FONTS  ', JSON.stringify(await probe('t3-fonts', '#t3-fonts button[data-pair="fraunces_worksans"]')));
  // a real mouse hover on the card, then read the frame
  const r = await p.evaluate(() => { const c = document.querySelector('#t3-fonts button[data-pair="fraunces_worksans"]'); const b = c.getBoundingClientRect(); return [b.x + b.width / 2, b.y + b.height / 2]; });
  await p.mouse.move(r[0], r[1]); await p.waitForTimeout(2500);
  console.log('after mouse.move', JSON.stringify(await p.evaluate(() => { const d = document.getElementById('t3-preview').contentDocument; return [getComputedStyle(d.querySelector('h1')).fontFamily, d.querySelectorAll('[data-t3-fp]').length]; })));
  await p.screenshot({ path: __dirname + '/sheet/fonts-hover-fraunces.jpg', type: 'jpeg', quality: 60 });
  await p.mouse.move(600, 760); await p.waitForTimeout(1200);
  console.log('after leave', JSON.stringify(await p.evaluate(() => { const d = document.getElementById('t3-preview').contentDocument; return [getComputedStyle(d.querySelector('h1')).fontFamily, d.querySelectorAll('[data-t3-fp]').length]; })));
  await p.mouse.click(r[0], r[1]); await p.waitForTimeout(9000);
  console.log('after click', JSON.stringify(await p.evaluate(() => { const d = document.getElementById('t3-preview').contentDocument; return [getComputedStyle(d.querySelector('h1')).fontFamily, Array.from(document.querySelectorAll('#t3-fonts button[data-pair]')).filter(b => /Current/.test(b.textContent)).map(b => b.getAttribute('data-pair'))]; })));
  await p.screenshot({ path: __dirname + '/sheet/fonts-applied-fraunces.jpg', type: 'jpeg', quality: 60 });
  await b.close();
})();
