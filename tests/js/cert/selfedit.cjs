// RE-CERT: the owner finishes their own site with the per-element tools in the template editor (real browser, no Arthur).
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('fs'); const path = require('path'); const S = 'https://staging.levelupgrowth.io';
const RUN = process.argv[2] || 'offcatalogue'; const SITE = +(process.argv[3] || 999); const LIVE = process.argv[4] || 'https://skyscope.levelupgrowth.io/';
const MOBILE = process.argv[5] === 'phone';
const OUT = path.join(__dirname, 'selfedit-' + RUN + (MOBILE ? '-phone' : '')); fs.mkdirSync(OUT, { recursive: true });
const acct = JSON.parse(fs.readFileSync(path.join(__dirname, 'run-' + RUN, 'account.json'), 'utf8'));
const log = []; const rec = (k, v) => { const line = { k, v, t: new Date().toISOString() }; log.push(line); console.log(k.padEnd(28), typeof v === 'string' ? v : JSON.stringify(v).slice(0, 600)); fs.writeFileSync(path.join(OUT, 'log.json'), JSON.stringify(log, null, 1)); };
const live = async () => { const r = await fetch(LIVE + '?cb=' + Date.now(), { headers: { 'cache-control': 'no-cache', 'user-agent': 'Mozilla/5.0 Chrome/140' } }); return await r.text(); };
const decodeCf = h => h.replace(/<a[^>]*data-cfemail="([0-9a-f]+)"[^>]*>[\s\S]*?<\/a>/g, (_, x) => { const b = Buffer.from(x, 'hex'); return [...b.slice(1)].map(c => String.fromCharCode(c ^ b[0])).join(''); });
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: acct.address, password: 'CertRun2026!x' }) })).json();
  const b = await chromium.launch();
  const ctx = await b.newContext(MOBILE
    ? { viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true, userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36' }
    : { viewport: { width: 1280, height: 800 }, userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
  await ctx.addInitScript(([t, r, w]) => { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); try { localStorage.removeItem('lu_arthur_lock'); } catch (e) {} }, [login.access_token, login.refresh_token, login.current_workspace_id]);
  const p = await ctx.newPage();
  const net = []; p.on('response', async r => { const u = r.url(); if (/\/api\/builder\/websites\/\d+\/(fields|elements|palette|layout|undo|versions|restore)|\/api\/builder\/(image|logo|media)|\/api\/media/.test(u) && r.request().method() !== 'GET') { let body = ''; try { body = (await r.text()).slice(0, 300); } catch (e) {} net.push({ m: r.request().method(), u: u.replace(S, ''), s: r.status(), body }); } });
  const errs = []; p.on('pageerror', e => errs.push(String(e.message).slice(0, 200))); p.on('dialog', d => { rec('NATIVE DIALOG', d.type() + ': ' + d.message()); d.dismiss(); });
  const shot = n => p.screenshot({ path: path.join(OUT, n + '.jpg'), type: 'jpeg', quality: 55 });
  const lastNet = n => net.slice(-n);
  
  await p.goto(S + '/app/websites', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(9000);
  await p.waitForFunction(() => Array.from(document.querySelectorAll('button, a')).some(e => e.innerText.trim() === 'Edit' && e.offsetParent), null, { timeout: 45000 }).catch(() => {});
  await p.evaluate(() => { const g = Array.from(document.querySelectorAll('button')).find(b => b.innerText.trim() === 'Got it' && b.offsetParent); g && g.click(); }); await p.waitForTimeout(800);
  const opened = await p.evaluate(() => { const x = Array.from(document.querySelectorAll('button, a')).find(e => e.innerText.trim() === 'Edit' && e.offsetParent); if (x) { x.click(); return true; } return false; });
  rec('open editor via Edit', opened);
  await p.waitForSelector('#t3-preview', { timeout: 30000 }).catch(() => {}); await p.waitForTimeout(12000);
  const skipped = await p.evaluate(() => { const x = Array.from(document.querySelectorAll('button')).find(b => /Skip tour/i.test(b.innerText) && b.offsetParent); if (x) { x.click(); return true; } return false; }); rec('tour shown and skipped', skipped); await p.waitForTimeout(1500);
  await shot('00-editor');
  // Sarah's introduction may cover the editor: go through it like an owner, counting taps
  let lsiSteps = 0; const lsiText = [];
  for (let i = 0; i < 25; i++) {
    const st = await p.evaluate(() => { const d = document.getElementById('lsi'); if (!d || !d.offsetParent && getComputedStyle(d).display === 'none') return null; const r = d.getBoundingClientRect(); if (!r.width) return null; const btns = Array.from(d.querySelectorAll('button')).filter(b => b.offsetParent && b.getBoundingClientRect().width); const skip = btns.find(b => /skip|later|not now|close|×/i.test(b.innerText || b.getAttribute('aria-label') || '')); const primary = btns.filter(b => !/back|previous/i.test(b.innerText)).pop(); return { title: (d.querySelector('h1,h2,h3') || {}).innerText || '', btns: btns.map(b => (b.innerText || b.getAttribute('aria-label') || '').trim().slice(0, 24)), skip: skip ? (skip.innerText || skip.getAttribute('aria-label')).trim() : null, primaryIdx: btns.indexOf(primary) }; });
    if (!st) break;
    lsiText.push(st.title.slice(0, 50) + ' [' + st.btns.join(' | ') + ']');
    if (i === 0) rec('Sarah intro over editor', { skipAvailable: st.skip, buttons: st.btns });
    await p.evaluate(idx => { const d = document.getElementById('lsi'); const btns = Array.from(d.querySelectorAll('button')).filter(b => b.offsetParent && b.getBoundingClientRect().width); if (btns[idx]) btns[idx].click(); }, st.primaryIdx);
    lsiSteps++; await p.waitForTimeout(2200);
  }
  rec('Sarah intro taps to reach editor', lsiSteps); rec('Sarah intro screens', lsiText); await shot('00b-after-intro');
  await p.waitForFunction(() => { const f = document.getElementById('t3-preview'); try { return f && f.contentDocument && f.contentDocument.querySelectorAll('[data-field]').length > 5; } catch (e) { return true; } }, null, { timeout: 30000 }).catch(() => {});
  const fh = await p.$('#t3-preview'); const fr = await fh.contentFrame();
  // toolbar reachability
  const bar = await p.evaluate(() => { const vw = innerWidth; return Array.from(document.querySelectorAll('#template-editor-view .pe-bar button')).filter(b => !b.hidden).map(b => { const r = b.getBoundingClientRect(); return (b.innerText.trim() || b.getAttribute('aria-label') || '?').slice(0, 14) + '@' + Math.round(r.left) + (r.right > vw ? '(OFF)' : ''); }); });
  rec('toolbar buttons', bar);
  const inv = await fr.evaluate(() => ({ fields: Array.from(new Set(Array.from(document.querySelectorAll('[data-field]')).map(e => e.getAttribute('data-field')))), blocks: Array.from(document.querySelectorAll('[data-block]')).map(e => e.getAttribute('data-block')) }));
  rec('editable fields', inv.fields.length + ': ' + inv.fields.join(' '));
  rec('sections', inv.blocks.join(' '));
  rec('contact fields present', inv.fields.filter(f => /phone|email|tel|mail|address|hours|contact/.test(f)));

  const editText = async (field, text, how = 'replace') => {
    const el = fr.locator('[data-field="' + field + '"]').first();
    if (!(await el.count())) return 'NO SUCH FIELD';
    await el.scrollIntoViewIfNeeded().catch(() => {});
    if (MOBILE) { await el.tap(); await p.waitForTimeout(250); await el.tap(); } else await el.dblclick();
    await p.waitForTimeout(900);
    const editable = await el.evaluate(e => e.isContentEditable || e.getAttribute('contenteditable'));
    if (how === 'replace') { await p.keyboard.press('Control+A'); await p.keyboard.type(text, { delay: 8 }); }
    else if (how === 'clear') { await p.keyboard.press('Control+A'); await p.keyboard.press('Delete'); }
    const n0 = net.length;
    // leave the field the way an owner does: click somewhere neutral in the page
    await fr.locator('body').click({ position: { x: 5, y: 5 } }).catch(() => {}); await p.waitForTimeout(3500);
    const saves = net.slice(n0).filter(x => x.u.includes('/fields/'));
    return { editable: String(editable), saves: saves.map(s => s.s + ' ' + s.u.split('/fields/')[1] + ' ' + s.body.slice(0, 120)), now: (await el.innerText().catch(() => '')).slice(0, 140) };
  };

  // T1 headline
  rec('T1 edit hero_title', await editText('hero_title', 'Drone roof surveys across Greater Manchester'));
  let L = decodeCf(await live()); rec('T1 live immediately?', L.includes('Drone roof surveys across Greater Manchester'));
  // T2 put the phone and email in: the contact block
  const contactField = inv.fields.find(f => /contact_(subtitle|text|body)/.test(f)) || inv.fields.find(f => /contact/.test(f));
  rec('T2 contact field used', contactField || 'none');
  if (contactField) rec('T2 type phone+email', await editText(contactField, 'Call 0161 555 0199 or email hello@skyscope.co.uk'));
  L = decodeCf(await live()); rec('T2 live has phone / email', { phone: L.includes('0161 555 0199'), email: L.includes('hello@skyscope.co.uk'), tel: /href="tel:/.test(L), mailto: /href="mailto:/.test(L) });
  // T3 kill a wrong service card: plumber/emergency copy
  const svcs = await fr.evaluate(() => Array.from(document.querySelectorAll('[data-field^="service_"][data-field$="_title"]')).map(e => e.getAttribute('data-field') + '=' + e.innerText.trim()));
  rec('T3 service cards before', svcs);
  rec('T3 rewrite service_2_title', await editText('service_2_title', 'Thermal imaging for solar'));
  rec('T3 clear service_2_text', await editText('service_2_text', '', 'clear'));
  L = decodeCf(await live()); rec('T3 empty card left on live?', (() => { const m = L.match(/data-field="service_2_text"[^>]*>([\s\S]{0,80})/); return m ? JSON.stringify(m[1].slice(0, 60)) : 'field gone'; })());
  // T4 paste hostile rich text
  const t4 = fr.locator('[data-field="story_body"], [data-field="offers_subtitle"], [data-field="hero_subtitle"]').first();
  const t4f = await t4.getAttribute('data-field');
  if (MOBILE) { await t4.tap(); await t4.tap(); } else await t4.dblclick(); await p.waitForTimeout(700);
  await p.keyboard.press('Control+A');
  await fr.evaluate(() => { const dt = new DataTransfer(); dt.setData('text/html', '<b style="color:red" onclick="alert(1)">Bold pasted</b><script>alert(2)<\/script><img src=x onerror=alert(3)> <a href="javascript:alert(4)">link</a>'); dt.setData('text/plain', 'Bold pasted link'); const ev = new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }); document.activeElement.dispatchEvent(ev); if (!ev.defaultPrevented) document.execCommand('insertHTML', false, dt.getData('text/html')); });
  const n4 = net.length; await fr.locator('body').click({ position: { x: 5, y: 5 } }).catch(() => {}); await p.waitForTimeout(3500);
  rec('T4 paste save', net.slice(n4).map(s => s.s + ' ' + s.body.slice(0, 200)));
  L = await live(); const seg = (L.match(new RegExp('data-field="' + t4f + '"[^>]*>([\\s\\S]{0,400})')) || [])[1] || '';
  rec('T4 live markup after paste', { script: /<script>alert\(2\)/.test(seg), onerror: /onerror=/i.test(seg), onclick: /onclick=/i.test(seg), jsHref: /javascript:/i.test(seg), sample: seg.slice(0, 200) });
  // T5 link a button to a phone number
  const cta = fr.locator('[data-field="hero_cta"], [data-field="nav_cta"]').first(); const ctaF = await cta.getAttribute('data-field').catch(() => null);
  await cta.scrollIntoViewIfNeeded().catch(() => {}); await cta.click(); await p.waitForTimeout(1500); await shot('05-cta-selected');
  const tb = await fr.evaluate(() => Array.from(document.querySelectorAll('button[title], [role=button][title]')).filter(b => b.offsetParent && b.getBoundingClientRect().width).map(b => b.getAttribute('title')).slice(0, 40));
  rec('T5 toolbox on a button', tb);
  const linkBtn = fr.locator('button[title*="ink" i]').first();
  if (await linkBtn.count()) { await linkBtn.click(); await p.waitForTimeout(1200); }
  else { await cta.dispatchEvent('pointerdown'); await p.waitForTimeout(900); await cta.dispatchEvent('pointerup'); }
  await p.waitForTimeout(1200); await shot('05-link');
  const dlg = await p.$('#t3-link-ov'); const sheet = await p.evaluate(() => { const d = Array.from(document.querySelectorAll('[role=dialog]')).find(x => x.offsetParent); return d ? d.innerText.replace(/\s+/g, ' ').slice(0, 300) : null; });
  rec('T5 link UI reached', { dialog: !!dlg, text: sheet });
  if (!dlg && sheet) { const cl = await p.evaluate(() => { const d = Array.from(document.querySelectorAll('[role=dialog] button')).find(x => /link|change/i.test(x.innerText) && x.offsetParent); if (d) { d.click(); return d.innerText; } return null; }); rec('T5 sheet button', cl); await p.waitForTimeout(1000); }
  if (await p.$('#t3-link-ov')) { await p.fill('#t3-link-href', 'tel:+441615550199'); const n5 = net.length; await p.click('#t3-link-ov [data-role=ok]'); await p.waitForTimeout(4000); rec('T5 set tel link', net.slice(n5).map(s => s.s + ' ' + s.u + ' ' + s.body.slice(0, 160))); L = await live(); rec('T5 live has tel link', /href="tel:\+441615550199"/.test(L)); }
  // T6 picture: click an image, look at the panel, choose from library, upload own photo
  const img = fr.locator('[data-field="story_image"], [data-field="hero_image"], [data-field^="gallery_"]').first(); const imgF = await img.getAttribute('data-field').catch(() => null);
  await img.scrollIntoViewIfNeeded().catch(() => {}); await img.click({ force: true }); await p.waitForTimeout(1500); await shot('06-image-panel');
  const panel = await p.evaluate(() => { const x = document.getElementById('t3-img-panel'); return x ? x.innerText.replace(/\s+/g, ' ') : null; }); rec('T6 image panel (' + imgF + ')', panel);
  const tbImg = await fr.evaluate(() => Array.from(document.querySelectorAll('button[title]')).filter(b => b.offsetParent).map(b => b.getAttribute('title'))); rec('T6 picture toolbox', tbImg);
  if (panel) { await p.click('#t3-img-choose'); await p.waitForTimeout(4000); await shot('06-library');
    const lib = await p.evaluate(() => { const d = Array.from(document.querySelectorAll('[role=dialog], .mp-modal, #media-picker, [id*=picker]')).find(x => x.offsetParent); return d ? d.innerText.replace(/\s+/g, ' ').slice(0, 400) : null; }); rec('T6 library', lib);
    const fileIn = await p.$$('input[type=file]'); rec('T6 file inputs in picker', fileIn.length);
    let up = null; for (const fi of fileIn) { const acc = await fi.getAttribute('accept'); if (!acc || /image/.test(acc)) { const n6 = net.length; try { await fi.setInputFiles(path.join(__dirname, 'photo2.jpg')); } catch (e) { continue; } await p.waitForTimeout(9000); up = net.slice(n6).map(s => s.s + ' ' + s.u + ' ' + s.body.slice(0, 160)); break; } }
    rec('T6 upload own photo', up); await shot('06-after-upload');
    const pick = await p.evaluate(() => { const d = Array.from(document.querySelectorAll('[role=dialog] img, .mp-grid img, [id*=picker] img')).find(i => i.offsetParent); if (d) { (d.closest('button,[role=button],.mp-item,div') || d).click(); return d.getAttribute('src'); } return null; }); rec('T6 picked', pick); await p.waitForTimeout(1500);
    const useBtn = await p.evaluate(() => { const x = Array.from(document.querySelectorAll('button')).find(b => b.offsetParent && /^(use|select|insert|choose|apply)/i.test(b.innerText.trim())); if (x) { const t = x.innerText.trim(); x.click(); return t; } return null; }); rec('T6 confirm button', useBtn); await p.waitForTimeout(6000); await shot('06-after-pick');
    rec('T6 saves', lastNet(4).map(s => s.s + ' ' + s.u + ' ' + s.body.slice(0, 140)));
  }
  // T7 move / size via toolbox on a service card
  const card = fr.locator('[data-field="service_3_title"]').first(); if (await card.count()) { await card.click(); await p.waitForTimeout(1200); const n7 = net.length; const mv = fr.locator('button[title="Move up"]').first(); if (await mv.count()) { await mv.click(); await p.waitForTimeout(3500); } rec('T7 Move up', { button: await mv.count(), saves: net.slice(n7).map(s => s.s + ' ' + s.u + ' ' + s.body.slice(0, 140)) }); }
  // T8 Undo
  const n8 = net.length; await p.click('#t3-undo').catch(() => {}); await p.waitForTimeout(5000); rec('T8 Undo', net.slice(n8).map(s => s.s + ' ' + s.u + ' ' + s.body.slice(0, 160)));
  // T9 Versions
  await p.evaluate(() => { const x = Array.from(document.querySelectorAll('.pe-bar button')).find(b => b.innerText.trim() === 'Versions'); x && x.click(); }); await p.waitForTimeout(4000); await shot('09-versions');
  rec('T9 versions panel', await p.evaluate(() => { const d = Array.from(document.querySelectorAll('[role=dialog], .modal, [id*=version]')).find(x => x.offsetParent); return d ? d.innerText.replace(/\s+/g, ' ').slice(0, 500) : null; }));
  await p.keyboard.press('Escape'); await p.waitForTimeout(800); await p.evaluate(() => { document.querySelectorAll('[id*=version] button, [role=dialog] button').forEach(b => { if (/close|cancel|×/i.test(b.innerText || b.getAttribute('aria-label') || '')) b.click(); }); });
  // T10 Colours
  await p.evaluate(() => { const x = Array.from(document.querySelectorAll('.pe-bar button')).find(b => b.innerText.trim() === 'Colours'); x && x.click(); }); await p.waitForTimeout(4000); await shot('10-colours');
  rec('T10 colours panel', await p.evaluate(() => { const d = Array.from(document.querySelectorAll('[role=dialog], [id*=pal], [class*=pal]')).find(x => x.offsetParent && x.innerText.length > 20); return d ? { text: d.innerText.replace(/\s+/g, ' ').slice(0, 500), colourInputs: d.querySelectorAll('input[type=color], [class*=colorpicker], [class*=lu-cp]').length } : null; }));
  await p.keyboard.press('Escape'); await p.waitForTimeout(800);
  // T11 + Section and + Page catalogues (look, do not buy)
  for (const lbl of ['+ Section', '+ Page', 'Layout', 'Site']) {
    await p.evaluate(l => { const x = Array.from(document.querySelectorAll('.pe-bar button')).find(b => b.innerText.trim() === l); x && x.click(); }, lbl); await p.waitForTimeout(4500); await shot('11-' + lbl.replace(/\W/g, ''));
    rec('T11 ' + lbl, await p.evaluate(() => { const d = Array.from(document.querySelectorAll('[role=dialog], .modal, [id$=-ov], [id*=modal], [id*=panel]')).filter(x => x.offsetParent && x.innerText.length > 30).pop(); return d ? d.innerText.replace(/\s+/g, ' ').slice(0, 700) : null; }));
    await p.keyboard.press('Escape'); await p.waitForTimeout(900);
    await p.evaluate(() => { Array.from(document.querySelectorAll('button')).filter(b => b.offsetParent && /^(close|cancel|×|✕)$/i.test((b.innerText || b.getAttribute('aria-label') || '').trim())).forEach(b => b.click()); }); await p.waitForTimeout(600);
  }
  // T12 fonts: anything anywhere?
  rec('T12 any font control in editor', await p.evaluate(() => /font|typeface/i.test(document.getElementById('template-editor-view')?.innerText || '')));
  // T13 leave with unsaved text: dblclick, type, then Back
  const hs = fr.locator('[data-field="hero_subtitle"]').first(); if (await hs.count()) { if (MOBILE) { await hs.tap(); await hs.tap(); } else await hs.dblclick(); await p.waitForTimeout(600); await p.keyboard.type(' Typed then left.', { delay: 5 }); const n13 = net.length; await p.evaluate(() => { const x = Array.from(document.querySelectorAll('.pe-bar button')).find(b => /Back/.test(b.innerText)); x && x.click(); }); await p.waitForTimeout(4000); await shot('13-after-back'); rec('T13 leave mid-edit', { saves: net.slice(n13).map(s => s.s + ' ' + s.u.split('/').pop()), prompt: await p.evaluate(() => { const d = Array.from(document.querySelectorAll('[role=dialog], [role=alertdialog]')).find(x => x.offsetParent); return d ? d.innerText.replace(/\s+/g, ' ').slice(0, 200) : null; }) }); }
  rec('page errors', errs.slice(0, 8)); rec('all mutating calls', net.map(s => s.m + ' ' + s.s + ' ' + s.u));
  await b.close();
})();
