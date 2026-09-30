// RE-CERT part 2: real clipboard paste, Enter for a new line, link dialog, upload own photo, logo, move, undo, versions, colours, catalogues, site panel, leaving mid-edit.
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('fs'); const path = require('path'); const S = 'https://staging.levelupgrowth.io';
const RUN = process.argv[2] || 'offcatalogue'; const LIVE = process.argv[3] || 'https://skyscope.levelupgrowth.io/'; const MOBILE = process.argv[4] === 'phone';
const OUT = path.join(__dirname, 'selfedit5-' + RUN + (MOBILE ? '-phone' : '')); fs.mkdirSync(OUT, { recursive: true });
const acct = JSON.parse(fs.readFileSync(path.join(__dirname, 'run-' + RUN, 'account.json'), 'utf8'));
const log = []; const rec = (k, v) => { log.push({ k, v }); console.log(k.padEnd(30), typeof v === 'string' ? v.slice(0, 700) : JSON.stringify(v).slice(0, 700)); fs.writeFileSync(path.join(OUT, 'log.json'), JSON.stringify(log, null, 1)); };
const live = async () => (await (await fetch(LIVE + '?cb=' + Date.now(), { headers: { 'user-agent': 'Mozilla/5.0 Chrome/140' } })).text());
const liveField = async f => { const h = await live(); const m = h.match(new RegExp('data-field="' + f + '"[^>]*>([\\s\\S]{0,260}?)</(p|h[1-6]|span|div|a|li)>')); return m ? m[1] : null; };
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: acct.address, password: 'CertRun2026!x' }) })).json();
  const b = await chromium.launch();
  const ctx = await b.newContext(MOBILE
    ? { viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true, userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36' }
    : { viewport: { width: 1280, height: 800 }, userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: S });
  await ctx.addInitScript(([t, r, w]) => { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); }, [login.access_token, login.refresh_token, login.current_workspace_id]);
  const p = await ctx.newPage();
  const net = []; p.on('response', async r => { const u = r.url(); const m = r.request().method(); if (m !== 'GET' && /\/api\/(builder|media)/.test(u)) { let body = ''; try { body = (await r.text()).slice(0, 260); } catch (e) {} net.push({ m, u: u.replace(S, ''), s: r.status(), body }); } });
  p.on('dialog', d => { rec('NATIVE DIALOG', d.type() + ': ' + d.message()); d.dismiss(); });
  const shot = n => p.screenshot({ path: path.join(OUT, n + '.jpg'), type: 'jpeg', quality: 55 });
  const since = n0 => net.slice(n0).map(s => s.m + ' ' + s.s + ' ' + s.u.replace('/api/builder/websites/', 'w/') + ' ' + s.body.slice(0, 150));
  const click = async (sel, txt) => p.evaluate(([sel, txt]) => { const x = Array.from(document.querySelectorAll(sel)).find(b => b.offsetParent && b.getBoundingClientRect().width && (!txt || (b.innerText || '').trim().startsWith(txt))); if (x) { x.click(); return (x.innerText || '').trim().slice(0, 30) || true; } return false; }, [sel, txt]);
  const topDialog = async () => p.evaluate(() => { const c = Array.from(document.querySelectorAll('[role=dialog], [role=alertdialog], .modal, [id$=-ov], [id*=modal], .lu-mp-overlay, [id*=panel], [class*=drawer]')).filter(x => x.offsetParent !== null || getComputedStyle(x).position === 'fixed').filter(x => x.getBoundingClientRect().width > 100 && x.innerText.trim().length > 20 && !x.closest('#template-editor-view .pe-side')); const d = c.pop(); return d ? d.innerText.replace(/\s+/g, ' ').slice(0, 900) : null; });
  const closeAll = async () => { await p.evaluate(() => { const scopes = Array.from(document.querySelectorAll('[role=dialog], [role=alertdialog], .lu-dlg-overlay, .lu-mp-overlay, [id$=-ov], [id*=modal]')).filter(d => d.getBoundingClientRect().width > 0); scopes.forEach(d => { const b = Array.from(d.querySelectorAll('button')).find(b => /^(close|cancel|×|✕|not now|done|keep)$/i.test((b.innerText || b.getAttribute('aria-label') || '').trim()) && !/delete/i.test(b.innerText)); b && b.click(); }); }); await p.waitForTimeout(900); };

  await p.goto(S + '/app/websites', { waitUntil: 'domcontentloaded' });
  await p.waitForFunction(() => Array.from(document.querySelectorAll('button')).some(e => e.innerText.trim() === 'Edit' && e.offsetParent), null, { timeout: 60000 }).catch(() => {});
  await click('button', 'Got it'); await click('button', 'Edit');
  await p.waitForSelector('#t3-preview', { timeout: 30000 }).catch(() => {});
  await p.waitForFunction(() => { const f = document.getElementById('t3-preview'); try { return f.contentDocument.querySelectorAll('[data-field]').length > 5; } catch (e) { return false; } }, null, { timeout: 60000 }).catch(() => {});
  await p.waitForTimeout(2500); await click('button', 'Skip tour'); await click('button', 'Got it');
  const fr = await (await p.$('#t3-preview')).contentFrame();
  const startEdit = async f => { const el = fr.locator('[data-field="' + f + '"]').first(); await el.scrollIntoViewIfNeeded().catch(() => {}); if (MOBILE) { await el.tap(); await p.waitForTimeout(200); await el.tap(); } else await el.dblclick(); await p.waitForTimeout(800); return el; };
  const leave = async () => { await fr.locator('body').click({ position: { x: 4, y: 4 } }).catch(() => {}); await p.waitForTimeout(3500); };

  // M1 move a service card up with the toolbox, then Undo
  await closeAll();
  const s3 = fr.locator('[data-field="service_3_title"]').first(); await s3.scrollIntoViewIfNeeded(); const sb = await s3.boundingBox(); await p.mouse.click(sb.x + sb.width / 2, sb.y + sb.height / 2); await p.waitForTimeout(1500); await shot('m1-selected'); rec('M1 toolbox titles', await fr.evaluate(() => Array.from(document.querySelectorAll('button[title]')).filter(b => b.getBoundingClientRect().width > 0).map(b => b.getAttribute('title'))));
  n0 = net.length; const mv = await fr.evaluate(() => { const x = Array.from(document.querySelectorAll('button[title="Move up"]')).find(b => b.getBoundingClientRect().width > 0); if (x) { x.click(); return true; } return false; }); await p.waitForTimeout(4000);
  rec('M1 move up', { clicked: mv, saves: since(n0) });
  n0 = net.length; await click('#t3-undo'); await p.waitForTimeout(5000); rec('U1 undo', since(n0)); await shot('u1-undo');
  rec('U1 toast/feed', await p.evaluate(() => { const f = document.getElementById('t3-arthur-feed'); return f ? f.innerText.replace(/\s+/g, ' ').slice(-250) : null; }));
  // V1 versions
  await click('.pe-bar button', 'Versions'); await p.waitForTimeout(4000); await shot('v1-versions'); rec('V1 versions', await topDialog()); await closeAll();
  // C1 colours
  await click('.pe-bar button', 'Colours'); await p.waitForTimeout(4000); await shot('c1-colours');
  rec('C1 colours', await topDialog()); rec('C1 custom colour inputs', await p.evaluate(() => document.querySelectorAll('input[type=color], .lu-cp, [class*=colorpicker]:not(script)').length)); await closeAll();
  // K1 catalogues and panels (look, do not buy)
  for (const lbl of ['Services & pri', '+ Section', '+ Page', 'Layout', 'Site']) { if (!(await click('.pe-bar button', lbl))) { rec('K1 ' + lbl, 'no button'); continue; } await p.waitForTimeout(5000); await shot('k1-' + lbl.replace(/\W/g, '')); rec('K1 ' + lbl, await topDialog()); await closeAll(); await closeAll(); }
  // F1 fonts anywhere
  rec('F1 font control', await p.evaluate(() => /\bfont|typeface/i.test(document.body.innerText)));
  // X1 leave mid-edit with the Back button
  await startEdit('faq_title'); await p.keyboard.press('End'); await p.keyboard.type(' (edited)', { delay: 5 }); n0 = net.length; await click('.pe-bar button', '←'); await p.waitForTimeout(5000); await shot('x1-left');
  rec('X1 leave mid-edit', { saves: since(n0), prompt: await topDialog() }); rec('X1 live faq_title', await liveField('faq_title'));
  rec('X1 still in editor?', !!(await p.$('#template-editor-view')));
  await b.close();
})();
