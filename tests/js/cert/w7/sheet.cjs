// FIX-ALL G5: the Fonts panel by eye — desktop (1280x800) and phone (412x915) — on harness site 1016.
const { chromium } = require('C:/Users/markr/AppData/Local/Temp/claude/C--Users-markr/a1258e38-7a7c-49ce-9c8f-89952f08f6c5/scratchpad/xb/node_modules/playwright');
const fs = require('fs'); const path = require('path'); const S = 'https://staging.levelupgrowth.io';
const MOBILE = process.argv[2] === 'phone';
const OUT = path.join(__dirname, 'sheet' + (MOBILE ? '-phone' : '')); fs.mkdirSync(OUT, { recursive: true });
const rec = (k, v) => console.log(k.padEnd(28), typeof v === 'string' ? v.slice(0, 500) : JSON.stringify(v).slice(0, 500));
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: 'lug-w4-muoo51ky@uberip.com', password: 'EdgeTest2026!q' }) })).json();
  const b = await chromium.launch();
  const ctx = await b.newContext(MOBILE
    ? { viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true, userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36' }
    : { viewport: { width: 1280, height: 800 }, userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
  await ctx.addInitScript(([t, r, w]) => { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); }, [login.access_token, login.refresh_token, login.current_workspace_id]);
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(String(e.message).slice(0, 160)));
  const shot = n => p.screenshot({ path: path.join(OUT, n + '.jpg'), type: 'jpeg', quality: 60 });
  const click = async (sel, txt) => p.evaluate(([sel, txt]) => { const x = Array.from(document.querySelectorAll(sel)).find(b => b.offsetParent && b.getBoundingClientRect().width && (!txt || (b.innerText || '').trim().startsWith(txt))); if (x) { x.click(); return (x.innerText || '').trim().slice(0, 30); } return null; }, [sel, txt]);
  // the app opens the editor from ?edit= on load; clicking Edit as well opened it twice (two views, the top one empty)
  await p.goto(S + '/app/websites/1016?edit=1016', { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('#t3-preview', { timeout: 60000 }).catch(() => {});
  await p.waitForTimeout(3000); await click('button', 'Got it');
  rec('editor views', await p.evaluate(() => [document.querySelectorAll('#template-editor-view').length, document.querySelectorAll('#t3-preview').length]));
  await p.waitForFunction(() => { const f = document.getElementById('t3-preview'); try { return f.contentDocument.querySelectorAll('[data-field]').length > 5; } catch (e) { return false; } }, null, { timeout: 60000 }).catch(() => {});
  await p.waitForTimeout(2500); await click('button', 'Skip tour'); await click('button', 'Got it'); await p.waitForTimeout(1500);
  rec('flags', await p.evaluate(() => window._t3Flags));
  rec('toolbar buttons', await p.evaluate(() => Array.from(document.querySelectorAll('#template-editor-view .pe-bar button, #t3-more-menu button')).map(b => (b.innerText || '').replace(/\s+/g, ' ').trim() + (b.offsetParent ? '' : '(hidden)')).filter(Boolean)));
  if (MOBILE) { await click('#t3-more-btn'); await p.waitForTimeout(600); await shot('phone-more'); }
  rec('click Fonts', await click('button', 'Fonts'));
  await p.waitForSelector('#t3-fonts button[data-pair]', { timeout: 20000 }).catch(() => {});
  await p.waitForTimeout(2500);
  rec('panel cards', await p.evaluate(() => Array.from(document.querySelectorAll('#t3-fonts button[data-pair]')).map(b => b.getAttribute('data-pair') + ':' + ((b.querySelector('.t3-fonts-tag') || {}).textContent || '').trim()).slice(0, 8)));
  rec('panel rect', await p.evaluate(() => { const r = document.getElementById('t3-fonts').getBoundingClientRect(); return [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height), innerWidth, innerHeight]; }));
  await shot('fonts-open');
  if (!MOBILE) {
    const before = await p.evaluate(() => { const d = document.getElementById('t3-preview').contentDocument; return getComputedStyle(d.querySelector('h1')).fontFamily; });
    const cc = await p.evaluate(() => { const b = document.querySelector('#t3-fonts button[data-pair="fraunces_worksans"]').getBoundingClientRect(); return [b.x + b.width / 2, b.y + b.height / 2, (document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2) || {}).tagName]; });
    rec('card centre / on top', cc);
    await p.mouse.move(cc[0], cc[1]); await p.waitForTimeout(2500);
    const during = await p.evaluate(() => { const d = document.getElementById('t3-preview').contentDocument; return [getComputedStyle(d.querySelector('h1')).fontFamily, d.querySelectorAll('[data-t3-fp]').length, (d.getElementById('lug-design-style') || {}).disabled]; });
    rec('h1 font before hover', before); rec('h1 font during hover', during);
    await shot('fonts-hover-fraunces');
    await p.mouse.move(600, 700); await p.waitForTimeout(1200);
    rec('h1 font after leave', await p.evaluate(() => { const d = document.getElementById('t3-preview').contentDocument; return [getComputedStyle(d.querySelector('h1')).fontFamily, d.querySelectorAll('[data-t3-fp]').length]; }));
    const pc = await p.evaluate(() => { const b = document.querySelector('#t3-fonts button[data-pair="playfair_source"]').getBoundingClientRect(); return [b.x + b.width / 2, b.y + b.height / 2]; });
    await p.mouse.click(pc[0], pc[1]); await p.waitForTimeout(9000);
    rec('after apply tag', await p.evaluate(() => Array.from(document.querySelectorAll('#t3-fonts button[data-pair]')).filter(b => /Current/.test(b.textContent)).map(b => b.getAttribute('data-pair'))));
    rec('h1 font after apply', await p.evaluate(() => { const d = document.getElementById('t3-preview').contentDocument; return getComputedStyle(d.querySelector('h1')).fontFamily; }));
    await shot('fonts-applied-playfair');
  } else {
    await p.tap('#t3-fonts button[data-pair="lora_lato"]'); await p.waitForTimeout(9000);
    rec('after tap tag', await p.evaluate(() => Array.from(document.querySelectorAll('#t3-fonts button[data-pair]')).filter(b => /Current/.test(b.textContent)).map(b => b.getAttribute('data-pair'))));
    await shot('phone-fonts-applied-lora');
  }
  rec('page errors', errs);
  await b.close();
})();
