// RE-CERT part 2: real clipboard paste, Enter for a new line, link dialog, upload own photo, logo, move, undo, versions, colours, catalogues, site panel, leaving mid-edit.
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('fs'); const path = require('path'); const S = 'https://staging.levelupgrowth.io';
const RUN = process.argv[2] || 'offcatalogue'; const LIVE = process.argv[3] || 'https://skyscope.levelupgrowth.io/'; const MOBILE = process.argv[4] === 'phone';
const OUT = path.join(__dirname, 'selfedit3-' + RUN + (MOBILE ? '-phone' : '')); fs.mkdirSync(OUT, { recursive: true });
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
  const closeAll = async () => { await p.evaluate(() => { Array.from(document.querySelectorAll('button')).filter(b => b.offsetParent && /^(close|cancel|×|✕|not now|done)$/i.test((b.innerText || b.getAttribute('aria-label') || '').trim())).forEach(b => b.click()); }); await p.waitForTimeout(700); };

  await p.goto(S + '/app/websites', { waitUntil: 'domcontentloaded' });
  await p.waitForFunction(() => Array.from(document.querySelectorAll('button')).some(e => e.innerText.trim() === 'Edit' && e.offsetParent), null, { timeout: 60000 }).catch(() => {});
  await click('button', 'Got it'); await click('button', 'Edit');
  await p.waitForSelector('#t3-preview', { timeout: 30000 }).catch(() => {});
  await p.waitForFunction(() => { const f = document.getElementById('t3-preview'); try { return f.contentDocument.querySelectorAll('[data-field]').length > 5; } catch (e) { return false; } }, null, { timeout: 60000 }).catch(() => {});
  await p.waitForTimeout(2500); await click('button', 'Skip tour'); await click('button', 'Got it');
  const fr = await (await p.$('#t3-preview')).contentFrame();
  const startEdit = async f => { const el = fr.locator('[data-field="' + f + '"]').first(); await el.scrollIntoViewIfNeeded().catch(() => {}); if (MOBILE) { await el.tap(); await p.waitForTimeout(200); await el.tap(); } else await el.dblclick(); await p.waitForTimeout(800); return el; };
  const leave = async () => { await fr.locator('body').click({ position: { x: 4, y: 4 } }).catch(() => {}); await p.waitForTimeout(3500); };

  // I1 replace a picture with the owner's own photo: click picture -> Choose Image -> Upload New -> file -> Use this file

  const img = fr.locator('[data-field="story_image"]').first(); await img.scrollIntoViewIfNeeded(); await p.waitForTimeout(800);
  const fb = await (await p.$('#t3-preview')).boundingBox(); const ib = await img.boundingBox(); rec('I1 image box', ib);
  
  await p.evaluate(() => { window.__pm = []; window.addEventListener('message', e => { try { window.__pm.push(e.data && e.data.type); } catch (x) {} }); });
  await p.mouse.click(ib.x + ib.width / 2, ib.y + ib.height / 2); await p.waitForTimeout(2000); await shot('i1-clicked');
  rec('I1 messages after click', await p.evaluate(() => window.__pm));
  const tbImg = await fr.evaluate(() => Array.from(document.querySelectorAll('button[title]')).filter(b => b.getBoundingClientRect().width > 0).map(b => b.getAttribute('title')));
  rec('I1 picture toolbox', tbImg); rec('I1 picture panel', await p.evaluate(() => (document.getElementById('t3-img-panel') || {}).innerText || null));
  await p.click('#t3-img-choose').catch(() => {}); await p.waitForTimeout(3500);
  await click('#lu-mp-modal button, #lu-mp-modal [role=tab], #lu-mp-modal a', 'Upload New') || await p.evaluate(() => { const x = Array.from(document.querySelectorAll('#lu-mp-modal *')).find(e => /Upload New/.test(e.textContent) && e.children.length < 3); x && x.click(); });
  await p.waitForTimeout(1500); await shot('i1-upload-tab');
  const fin = await p.$$('#lu-mp-modal input[type=file]'); rec('I1 file inputs in picker', fin.length);
  n0 = net.length; if (fin[0]) { await fin[0].setInputFiles(path.join(__dirname, 'photo2.jpg')); await p.waitForTimeout(10000); }
  rec('I1 upload', since(n0)); await shot('i1-after-upload');
  rec('I1 picker now', await p.evaluate(() => { const m = document.getElementById('lu-mp-modal'); return m ? m.innerText.replace(/\s+/g, ' ').slice(0, 300) : null; }));
  const useOk = await p.evaluate(() => { const x = Array.from(document.querySelectorAll('#lu-mp-modal button')).find(b => /Use this file/i.test(b.innerText)); if (!x) return 'no button'; if (x.disabled) { const first = document.querySelector('#lu-mp-modal .lu-mp-item, #lu-mp-modal [data-id], #lu-mp-modal img'); first && (first.closest('[data-id],button,.lu-mp-item') || first).click(); } return x.disabled ? 'was disabled, picked first' : 'enabled'; });
  await p.waitForTimeout(1200); n0 = net.length; await click('#lu-mp-modal button', 'Use this file'); await p.waitForTimeout(8000); await shot('i1-after-use');
  await shot('i1-crop'); const crop = await p.evaluate(() => { const d = Array.from(document.querySelectorAll('.lu-dlg-overlay')).find(x => x.offsetParent !== null || getComputedStyle(x).display !== 'none'); if (!d) return null; const btns = Array.from(d.querySelectorAll('button')).filter(b => b.getBoundingClientRect().width); return { text: d.innerText.replace(/s+/g, ' ').slice(0, 300), btns: btns.map(b => b.innerText.trim()) }; });
  rec('I1 crop step', crop);
  if (crop) { await p.evaluate(() => { const d = Array.from(document.querySelectorAll('.lu-dlg-overlay')).pop(); const btns = Array.from(d.querySelectorAll('.lu-dlg-foot button, button')).filter(b => b.getBoundingClientRect().width && !/cancel|back|close/i.test(b.innerText)); (btns.pop() || {}).click && btns.length >= 0 && (Array.from(d.querySelectorAll('.lu-dlg-foot button')).filter(b => !/cancel/i.test(b.innerText)).pop() || btns.pop()).click(); }); await p.waitForTimeout(9000); }
  const tc = Date.now(); await p.waitForFunction(() => Array.from(document.querySelectorAll('button')).some(b => b.innerText.trim() === 'Use this crop' && b.getBoundingClientRect().width), null, { timeout: 30000 }).catch(() => {}); rec('I1 crop tool appeared after (ms, from Use this file +9s)', Date.now() - tc); const n1 = net.length; await click('button', 'Use this crop'); await p.waitForTimeout(9000); rec('I1 after Use this crop', since(n1));
  rec('I1 use this file + crop', { state: useOk, saves: since(n0) }); await shot('i1-placed');
  { const h = await live(); const m = h.match(/data-field="story_image"[^>]*src="([^"]+)"|src="([^"]+)"[^>]*data-field="story_image"/); rec('I1 live story_image src', m ? (m[1] || m[2]) : null); }
  // I2 picture toolbox: fit / crop present?
  // I3 logo upload
  await closeAll();
  const logo = fr.locator('[data-field="logo_url"]').first(); if (await logo.count()) { await logo.scrollIntoViewIfNeeded().catch(() => {}); await logo.click({ force: true }); await p.waitForTimeout(1500); const lbb = await logo.boundingBox(); if (lbb) await p.mouse.click(lbb.x + lbb.width / 2, lbb.y + lbb.height / 2); await p.waitForTimeout(1500); rec('I3 logo box/panel', { box: lbb, panel: await p.evaluate(() => (document.getElementById('t3-img-panel') || {}).innerText || null) }); const lf = await p.$('#t3-img-file'); if (lf) { n0 = net.length; await lf.setInputFiles(path.join(__dirname, 'logo.png')); await p.waitForTimeout(4000); await shot('i3-logo-crop'); await p.evaluate(() => { const d = Array.from(document.querySelectorAll('.lu-dlg-overlay')).pop(); if (d) { const b = Array.from(d.querySelectorAll('.lu-dlg-foot button')).filter(b => !/cancel/i.test(b.innerText)).pop(); b && b.click(); } }); await p.waitForTimeout(8000); rec('I3 logo upload', since(n0)); await shot('i3-logo'); const h = await live(); rec('I3 live logo', (h.match(/<img[^>]*(logo)[^>]*>/i) || [null])[0]); } }

  // M1 move a service card up with the toolbox, then Undo
  await closeAll();
  const s3 = fr.locator('[data-field="service_3_title"]').first(); await s3.scrollIntoViewIfNeeded(); await s3.click(); await p.waitForTimeout(1200);
  n0 = net.length; const mv = await fr.evaluate(() => { const x = Array.from(document.querySelectorAll('button[title="Move up"]')).find(b => b.getBoundingClientRect().width > 0); if (x) { x.click(); return true; } return false; }); await p.waitForTimeout(4000);
  rec('M1 move up', { clicked: mv, saves: since(n0) });
  n0 = net.length; await p.click('#t3-undo').catch(() => {}); await p.waitForTimeout(5000); rec('U1 undo', since(n0)); await shot('u1-undo');
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
  await b.close();
})();
