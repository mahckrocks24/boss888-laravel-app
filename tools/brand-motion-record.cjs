#!/usr/bin/env node
/**
 * brand-motion-record.cjs — RFC-0025 P1: the brand motion layer, recorded on a TRANSPARENT background.
 * Loads a local HTML file, pauses every CSS animation, seeks the timeline frame by frame, screenshots each frame with
 * omitBackground so only the brand layer is opaque, and writes a PNG sequence for ffmpeg to overlay on the clip.
 * Frames inside [holdFrom, holdTo) ms are copies of the previous frame (nothing animates there), which keeps a
 * 6-second 1080x1920 layer to a few dozen real screenshots.
 *
 * Usage: node brand-motion-record.cjs '<json>'
 *   { htmlPath, framesDir, duration, fps=24, width, height, holdFrom=null, holdTo=null, fonts=[] }
 * Prints one JSON line: { ok, frames, shots, fontsLoaded: { family: bool } }
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
  const { htmlPath, framesDir, duration, width, height } = a;
  const fps = a.fps || 24;
  if (!htmlPath || !framesDir || !duration || !width || !height) { console.log(JSON.stringify({ ok: false, error: 'missing_args' })); process.exit(2); }
  const userDataDir = '/tmp/brand-motion-' + process.pid + '-' + Date.now();
  const browser = await puppeteer.launch({ executablePath: chromePath(), userDataDir, headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--no-first-run', '--no-zygote', '--disable-extensions', '--mute-audio', '--hide-scrollbars'] });
  let out = { ok: false };
  try {
    const page = await browser.newPage();
    await page.setViewport({ width, height, deviceScaleFactor: 1 });
    await page.goto('file://' + htmlPath, { waitUntil: 'networkidle0', timeout: 30000 });
    try { await page.evaluate(() => document.fonts.ready); } catch (_e) {}
    await new Promise(r => setTimeout(r, 300));
    const fontsLoaded = await page.evaluate((fams) => {
      const loaded = new Set([...document.fonts].filter(f => f.status === 'loaded').map(f => String(f.family).replace(/["']/g, '')));
      const r = {}; for (const f of fams) r[f] = loaded.has(f); return r;
    }, a.fonts || []);
    await page.evaluate(() => {
      document.documentElement.style.background = 'transparent';
      document.body.style.background = 'transparent';
      document.getAnimations().forEach(x => { try { x.pause(); x.currentTime = 0; } catch (_e) {} });
    });
    fs.rmSync(framesDir, { recursive: true, force: true });
    fs.mkdirSync(framesDir, { recursive: true });
    const total = Math.round(duration * fps);
    let shots = 0, prev = null;
    for (let i = 0; i < total; i++) {
      const t = (i / fps) * 1000;
      const file = path.join(framesDir, 'f' + String(i).padStart(6, '0') + '.png');
      const holds = a.holds || (a.holdFrom != null ? [[a.holdFrom, a.holdTo]] : []);
      if (prev && holds.some(r => t >= r[0] && t < r[1])) { fs.copyFileSync(prev, file); prev = file; continue; }
      await page.evaluate((ms) => { document.getAnimations().forEach(x => { try { x.currentTime = ms; } catch (_e) {} }); }, t);
      await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
      await page.screenshot({ path: file, clip: { x: 0, y: 0, width, height }, omitBackground: true, optimizeForSpeed: true });
      shots++; prev = file;
    }
    out = { ok: true, frames: total, shots, fontsLoaded };
  } catch (e) {
    out = { ok: false, error: String(e && e.message || e).slice(0, 300) };
  } finally {
    try { await browser.close(); } catch (_e) {}
    try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (_e) {}
  }
  console.log(JSON.stringify(out));
  process.exit(out.ok ? 0 : 1);
})();
