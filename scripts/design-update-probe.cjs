// DESIGN-UPDATES-1 — the browser check of a design update: the page the owner has now and the candidate, each at 1440x900
// and 390x844, for rect overflow, page errors, squeezed text (390) and text-over-photo contrast measured on pixels (the same
// instruments as the v3 render pass, /root/tplgen3/matrix-shoot.cjs). Writes the job's out JSON and four screenshots for the
// owner's card (after-desk/after-phone at 1280x800 and 390x844, now-* too).
// Rules of the box: one Chrome in its own process group with its own profile (/tmp/lug-render-dupd-<pid>), never relaunched;
// the caller holds /run/lug-render.lock and the load gate; a 240 s deadline for the whole job.
//   node scripts/design-update-probe.cjs <job.json>      (run by DesignUpdateService::probe, never by hand on a busy box)
const puppeteer = require('/var/www/levelup-staging/node_modules/puppeteer'); const fs = require('fs'); const path = require('path');
const ROOT = '/var/www/levelup-staging';
const chromeRoot = path.join(ROOT, '.puppeteer-cache', 'chrome'); const v = fs.readdirSync(chromeRoot).filter(x => x.startsWith('linux-')).sort();
const sleep = ms => new Promise(r => setTimeout(r, ms));
const job = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const CLIPPED = '.marq,.aurora,.nav-sheet,[data-plx],.media,.lu-cursor,.bento .m';
const overflowProbe = sel => { const W = document.documentElement.clientWidth; const bad = []; document.querySelectorAll('body *').forEach(el => { const r = el.getBoundingClientRect(); if (r.width > 0 && (r.right > W + 2 || r.left < -2) && getComputedStyle(el).position !== 'fixed' && !el.closest(sel)) bad.push(el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.split(' ')[0] : '')); }); return { sw: document.documentElement.scrollWidth, W, bad: [...new Set(bad)].slice(0, 8) }; };
const narrowProbe = () => { const bad = []; document.querySelectorAll('h1,h2,h3,h4,p,li,blockquote,figcaption,label,span,b,a').forEach(el => { if (el.closest('.marq,.nav-sheet,[aria-hidden="true"]')) return; if (el.closest('.facts,.nums-sec') && (el.innerText || '').trim().split(/\s+/).length <= 5) return; const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity === 0) return; const t = (el.innerText || '').trim(); if (t.length < 18 || t.split(/\s+/).length < 3) return; if ([...el.children].some(c => (c.innerText || '').trim().length > t.length * .6)) return; const r = el.getBoundingClientRect(); if (r.width > 0 && r.width < 160 && r.height > parseFloat(cs.lineHeight || cs.fontSize * 1.4) * 2.5) bad.push(el.tagName.toLowerCase() + ' "' + t.slice(0, 24) + '"'); }); return bad.slice(0, 6); };
const markOverImage = () => {
  const out = []; const H = innerHeight, W = innerWidth;
  document.querySelectorAll('[data-pxc]').forEach(e => e.removeAttribute('data-pxc'));
  document.querySelectorAll('h1,h2,h3,p,a,span,b,small,figcaption,blockquote,li,label').forEach(el => {
    const t = [...el.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent).join('').trim(); if (t.length < 2) return;
    const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity < .5) return;
    if (el.closest('.marq,.nav-sheet,[aria-hidden="true"]')) return;
    const r = el.getBoundingClientRect(); if (r.width < 8 || r.height < 8 || r.top < 70 || r.bottom > H - 4 || r.left < 0 || r.right > W) return;
    const under = document.elementsFromPoint(r.left + r.width / 2, r.top + r.height / 2); if (!under.some(u => u.tagName === 'IMG' && !u.closest('.lu-cursor'))) return;
    const m = cs.color.match(/rgba?\(([^)]+)\)/); if (!m) return; const c = m[1].split(',').map(Number);
    const size = parseFloat(cs.fontSize), weight = +cs.fontWeight || 400;
    el.setAttribute('data-pxc', out.length);
    out.push({ i: out.length, x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height), rgb: c.slice(0, 3), large: size >= 24 || (size >= 18.66 && weight >= 700), text: t.slice(0, 28) });
  });
  return out;
};
const scoreBoxes = async (png, boxes) => {
  const img = new Image(); img.src = 'data:image/png;base64,' + png; await img.decode();
  const cv = document.createElement('canvas'); cv.width = img.width; cv.height = img.height; const cx = cv.getContext('2d'); cx.drawImage(img, 0, 0);
  const lum = (r, g, b) => { const f = c => { c /= 255; return c <= .03928 ? c / 12.92 : Math.pow((c + .055) / 1.055, 2.4); }; return .2126 * f(r) + .7152 * f(g) + .0722 * f(b); };
  return boxes.map(bx => {
    const d = cx.getImageData(bx.x, bx.y, Math.max(1, bx.w), Math.max(1, bx.h)).data; const L = [];
    for (let k = 0; k < d.length; k += 16) L.push(lum(d[k], d[k + 1], d[k + 2]));
    L.sort((a, b) => a - b); const tl = lum(...bx.rgb);
    const bg = tl > .4 ? L[Math.floor(L.length * .85)] : L[Math.floor(L.length * .15)];
    const ratio = (Math.max(tl, bg) + .05) / (Math.min(tl, bg) + .05);
    return { text: bx.text, ratio: Math.round(ratio * 100) / 100, need: bx.large ? 3 : 4.5 };
  });
};
async function contrastPass(p, h) {
  const fails = []; const H = await p.evaluate(() => document.documentElement.scrollHeight);
  for (let y = 0; y < H; y += h - 120) {
    await p.evaluate(y => scrollTo(0, y), y); await sleep(350);
    const boxes = await p.evaluate(markOverImage); if (!boxes.length) continue;
    await p.addStyleTag({ content: '[data-pxc]{color:transparent!important;text-shadow:none!important;-webkit-text-fill-color:transparent!important}' });
    const ux = Math.max(0, Math.min(...boxes.map(b => b.x))), uy = Math.max(0, Math.min(...boxes.map(b => b.y))), uw = Math.max(...boxes.map(b => b.x + b.w)) - ux, uh = Math.max(...boxes.map(b => b.y + b.h)) - uy;
    boxes.forEach(b => { b.x -= ux; b.y -= uy; });
    const png = await p.screenshot({ encoding: 'base64', type: 'png', clip: { x: ux, y: uy + await p.evaluate(() => scrollY), width: Math.max(1, uw), height: Math.max(1, uh) } });
    await p.evaluate(() => document.querySelectorAll('style').forEach(s => { if (s.textContent.startsWith('[data-pxc]')) s.remove(); }));
    const res = await p.evaluate(scoreBoxes, png, boxes);
    res.forEach(r => { if (r.ratio < r.need) fails.push(`"${r.text}" below ${r.need}:1`); });   // position-free, so now and after compare
  }
  return [...new Set(fails)].slice(0, 8);
}
let browser = null;
const killGroup = () => { try { const pid = browser && browser.process() && browser.process().pid; if (pid) process.kill(-pid, 'SIGKILL'); } catch (e) {} };
for (const sig of ['SIGTERM', 'SIGINT', 'SIGHUP']) process.on(sig, () => { killGroup(); process.exit(130); });
process.on('exit', killGroup);
async function check(url, which) {
  const out = {};
  for (const [w, h, m, sw, sh] of [[1440, 900, 'desk', 1280, 800], [390, 844, 'phone', 390, 844]]) {
    const p = await browser.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message.slice(0, 120)));
    try {
      // the screenshot for the owner's card: what the page shows first, at the Owner's laptop size and a phone
      await p.setViewport({ width: sw, height: sh, isMobile: sw < 500, hasTouch: sw < 500 });
      await p.goto(job.base + url, { waitUntil: 'networkidle2', timeout: 60000 });
      await p.evaluate(() => document.fonts && document.fonts.ready); await sleep(1200);
      await p.evaluate(() => document.querySelectorAll('[data-lu-dupd-mark]').forEach(e => e.removeAttribute('data-lu-dupd-mark')));
      await p.screenshot({ path: path.join(job.shots, `${which}-${m}.webp`), type: 'webp', quality: 78 });
      if (w !== sw) { await p.setViewport({ width: w, height: h }); await sleep(600); }
      await p.addStyleTag({ content: 'html{scroll-behavior:auto!important}' });
      await p.evaluate(() => { document.querySelectorAll('.reveal,.wipe').forEach(e => e.classList.add('in')); });
      await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 700) { scrollTo(0, y); await new Promise(r => setTimeout(r, 30)); } scrollTo(0, 0); });
      await sleep(700);
      const o = await p.evaluate(overflowProbe, CLIPPED);
      out[m] = { overflow: o.bad.concat(o.sw > o.W + 2 ? ['page wider than the screen'] : []), errors: errs, narrow: m === 'phone' ? await p.evaluate(narrowProbe) : [] };
      out[m].contrast = await contrastPass(p, h);
    } finally { await p.close().catch(() => {}); }
  }
  return out;
}
(async () => {
  const deadline = setTimeout(() => { console.error('deadline'); killGroup(); process.exit(4); }, 240000);
  const userDataDir = '/tmp/lug-render-dupd-' + process.pid; process.on('exit', () => { try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (e) {} });
  browser = await puppeteer.launch({ executablePath: path.join(chromeRoot, v[v.length - 1], 'chrome-linux64', 'chrome'), headless: 'new', userDataDir, args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--renderer-process-limit=1'], protocolTimeout: 90000 });
  const res = { at: new Date().toISOString() };
  try { res.now = await check(job.urls.now, 'now'); res.after = await check(job.urls.after, 'after'); }
  catch (e) { console.error('probe error', e.message); await browser.close().catch(() => {}); killGroup(); process.exit(4); }
  fs.writeFileSync(job.out, JSON.stringify(res, null, 1));
  await browser.close().catch(() => {}); killGroup(); clearTimeout(deadline);
  console.log('ok'); process.exit(0);
})();
