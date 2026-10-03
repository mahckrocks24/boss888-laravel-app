#!/usr/bin/env node
/**
 * brand-layer-shots.cjs — RFC-0025 P1: the brand layer's STATES as transparent PNGs (one browser, one shot per state).
 * ffmpeg animates the states onto the clip (eased rise, fades), so a 6-second layer costs three screenshots, not ~70.
 * Usage: node brand-layer-shots.cjs '<json>'  { htmlPath, width, height, shots: [{ cls, out }], fonts: [] }
 * Each shot sets <body class="cls"> (the page's CSS hides everything else) and screenshots with omitBackground.
 * Prints { ok, fontsLoaded: { family: bool } }.
 */
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

function chromePath() {
  if (process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(process.env.PUPPETEER_EXECUTABLE_PATH)) return process.env.PUPPETEER_EXECUTABLE_PATH;
  const root = path.join(process.env.PUPPETEER_CACHE_DIR || '/var/www/levelup-staging/.puppeteer-cache', 'chrome');
  try {
    const v = fs.readdirSync(root).filter(x => x.startsWith('linux-')).sort();
    if (v.length) { const p = path.join(root, v[v.length - 1], 'chrome-linux64', 'chrome'); if (fs.existsSync(p)) return p; }
  } catch (_e) {}
  return puppeteer.executablePath();
}

(async () => {
  const a = JSON.parse(process.argv[2] || '{}');
  const userDataDir = '/tmp/brand-shots-' + process.pid + '-' + Date.now();
  const browser = await puppeteer.launch({ executablePath: chromePath(), userDataDir, headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--no-first-run', '--no-zygote', '--disable-extensions', '--mute-audio', '--hide-scrollbars'] });
  let out = { ok: false };
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: a.width, height: a.height, deviceScaleFactor: 1 });
    await page.goto('file://' + a.htmlPath, { waitUntil: 'networkidle0', timeout: 30000 });
    try { await page.evaluate(() => document.fonts.ready); } catch (_e) {}
    await new Promise(r => setTimeout(r, 250));

    for (const s of a.shots || []) {
      await page.evaluate((c) => { document.documentElement.style.background = 'transparent'; document.body.style.background = 'transparent'; document.body.className = c; }, s.cls);
        try { await page.evaluate(() => document.fonts.ready); } catch (_e) {}
      await new Promise(r => setTimeout(r, 120));
      await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
      await page.screenshot({ path: s.out, clip: { x: 0, y: 0, width: a.width, height: a.height }, omitBackground: true });
    }
    const fontsLoaded = await page.evaluate((fams) => {
      const loaded = new Set([...document.fonts].filter(f => f.status === 'loaded').map(f => String(f.family).replace(/["']/g, '')));
      const r = {}; for (const f of fams) r[f] = loaded.has(f); return r;
    }, a.fonts || []);
    out = { ok: true, fontsLoaded };
  } catch (e) {
    out = { ok: false, error: String(e && e.message || e).slice(0, 300) };
  } finally {
    try { await browser.close(); } catch (_e) {}
    try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (_e) {}
  }
  console.log(JSON.stringify(out));
  process.exit(out.ok ? 0 : 1);
})();
