// Fonts panel timing: when do the cards arrive, when does an apply finish, what do the tags say — with the network log. Desktop or phone.
const { chromium } = require('C:/Users/markr/AppData/Local/Temp/claude/C--Users-markr/a1258e38-7a7c-49ce-9c8f-89952f08f6c5/scratchpad/xb/node_modules/playwright');
const path = require('path'); const S = 'https://staging.levelupgrowth.io';
const MOBILE = process.argv[2] === 'phone'; const PAIR = process.argv[3] || (MOBILE ? 'lora_lato' : 'syne_worksans');
const rec = (k, v) => console.log(k.padEnd(26), typeof v === 'string' ? v.slice(0, 400) : JSON.stringify(v).slice(0, 400));
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: 'lug-w4-muoo51ky@uberip.com', password: 'EdgeTest2026!q' }) })).json();
  const b = await chromium.launch();
  const ctx = await b.newContext(MOBILE ? { viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true, userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36' } : { viewport: { width: 1280, height: 800 } });
  await ctx.addInitScript(([t, r, w]) => { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); }, [login.access_token, login.refresh_token, login.current_workspace_id]);
  const p = await ctx.newPage(); const t0 = Date.now(); const net = [];
  p.on('response', async r => { const u = r.url(); if (/\/fonts/.test(u)) { let body = ''; try { body = (await r.text()).slice(0, 120); } catch (e) {} net.push(((Date.now() - t0) / 1000).toFixed(1) + 's ' + r.request().method() + ' ' + r.status() + ' ' + u.replace(S, '') + ' ' + body); } });
  p.on('pageerror', e => rec('PAGE ERROR', String(e.message)));
  const click = async (sel, txt) => p.evaluate(([sel, txt]) => { const x = Array.from(document.querySelectorAll(sel)).find(b => b.offsetParent && b.getBoundingClientRect().width && (!txt || (b.innerText || '').trim().startsWith(txt))); if (x) { x.click(); return true; } return false; }, [sel, txt]);
  await p.goto(S + '/app/websites/1016?edit=1016', { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('#t3-preview', { timeout: 60000 }); await p.waitForTimeout(4000); await click('button', 'Got it');
  if (MOBILE) { await p.waitForSelector('#t3-more-btn', { timeout: 20000 }).catch(() => {}); await click('#t3-more-btn'); await p.waitForTimeout(900); }
  let okF = false;
  for (let i = 0; i < 12 && !okF; i++) { if (MOBILE) { const m = await click('#t3-more-btn'); rec('click more ' + i, m); await p.waitForTimeout(900); } okF = await click('button', 'Fonts'); if (!okF) await p.waitForTimeout(1000); }
  rec('click Fonts', okF);
  rec('toolbar now', await p.evaluate(() => Array.from(document.querySelectorAll('#template-editor-view .pe-bar button, #t3-more-menu button')).map(b => (b.innerText || '').replace(/\s+/g, ' ').trim() + (b.offsetParent ? '' : '(h)')).filter(Boolean).join(',')));
  for (let i = 1; i <= 12; i++) { await p.waitForTimeout(1000); const n = await p.evaluate(() => document.querySelectorAll('#t3-fonts button[data-pair]').length); if (n) { rec('cards after ' + i + 's', n); break; } if (i === 12) rec('cards after 12s', 0); }
  rec('net so far', net);
  const cur = await p.evaluate(() => Array.from(document.querySelectorAll('#t3-fonts button[data-pair]')).filter(b => /Current/.test(b.textContent)).map(b => b.getAttribute('data-pair')));
  rec('current before', cur);
  const c = await p.evaluate(id => { const b = document.querySelector('#t3-fonts button[data-pair="' + id + '"]'); b.scrollIntoView({ block: 'center' }); const r = b.getBoundingClientRect(); return [r.x + r.width / 2, r.y + r.height / 2, (document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2) || {}).tagName]; }, PAIR);
  rec('card centre / top', c);
  if (MOBILE) { await p.touchscreen.tap(c[0], c[1]); } else { await p.mouse.click(c[0], c[1]); }
  for (let i = 1; i <= 20; i++) {
    await p.waitForTimeout(1000);
    const st = await p.evaluate(() => { const d = document.getElementById('t3-preview').contentDocument; return [Array.from(document.querySelectorAll('#t3-fonts button[data-pair]')).map(b => ((b.querySelector('.t3-fonts-tag') || {}).textContent || '').trim()).filter(Boolean).join('|'), d && d.querySelector('h1') ? getComputedStyle(d.querySelector('h1')).fontFamily : null]; });
    rec(i + 's tags / h1', st);
    if (/Current/.test(st[0]) && !/Applying/.test(st[0]) && i > 2) break;
  }
  rec('net all', net);
  await p.screenshot({ path: path.join(__dirname, 'sheet' + (MOBILE ? '-phone' : ''), (MOBILE ? 'phone-' : '') + 'fonts-applied-' + PAIR + '.jpg'), type: 'jpeg', quality: 60 });
  await b.close();
})();
