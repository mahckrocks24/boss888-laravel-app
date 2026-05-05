#!/usr/bin/env node
/**
 * bake-thumbnail.cjs
 * -------------------
 * Captures the FINAL FRAME of an animated template as a thumbnail PNG.
 * No hiding — all text + decorative elements visible. Used for the Studio
 * template picker card previews.
 *
 * Usage:
 *   node bake-thumbnail.cjs <template_url> <out_png> [width] [height] [freeze_t_ms] [thumb_w] [thumb_h]
 *   defaults: width=1080 height=1920 freeze_t_ms=8000 thumb=600x1067 (portrait)
 */
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

function _resolveChromePath() {
  if (process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(process.env.PUPPETEER_EXECUTABLE_PATH)) {
    return process.env.PUPPETEER_EXECUTABLE_PATH;
  }
  const cacheRoot = process.env.PUPPETEER_CACHE_DIR || '/var/www/levelup-staging/.puppeteer-cache';
  try {
    const chromeRoot = path.join(cacheRoot, 'chrome');
    if (fs.existsSync(chromeRoot)) {
      const versions = fs.readdirSync(chromeRoot).filter(v => v.startsWith('linux-')).sort();
      if (versions.length) {
        const p = path.join(chromeRoot, versions[versions.length - 1], 'chrome-linux64', 'chrome');
        if (fs.existsSync(p)) return p;
      }
    }
  } catch (_) {}
  try { return puppeteer.executablePath(); } catch(_) {}
  throw new Error('chromium_not_found');
}

async function bake(templateUrl, outPng, width, height, freezeMs, thumbW, thumbH) {
  const execPath = _resolveChromePath();
  const userDataDir = '/tmp/thumb-' + process.pid + '-' + Date.now();
  const browser = await puppeteer.launch({
    executablePath: execPath,
    userDataDir,
    headless: 'new',
    args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage','--disable-gpu','--no-first-run','--no-zygote','--disable-crash-reporter','--disable-extensions','--mute-audio','--hide-scrollbars'],
  });

  let err = null;
  const fullPng = outPng + '.full.png';
  try {
    const page = await browser.newPage();
    await page.setViewport({ width, height, deviceScaleFactor: 1 });
    await page.goto(templateUrl, { waitUntil: 'networkidle0', timeout: 30000 });
    try { await page.evaluate(() => document.fonts.ready); } catch(_) {}
    await new Promise(r => setTimeout(r, 500));

    await page.evaluate((t) => {
      document.getAnimations().forEach(a => {
        try { a.currentTime = t; a.pause(); } catch(_){}
      });
      document.querySelectorAll('*').forEach(el => {
        var cs = window.getComputedStyle(el);
        if (cs.opacity === '0') el.style.opacity = '1';
        if (cs.transform && cs.transform !== 'none' && cs.transform !== 'matrix(1, 0, 0, 1, 0, 0)') {
          el.style.transform = 'none';
        }
      });
    }, freezeMs);
    await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
    await new Promise(r => setTimeout(r, 500));

    // Full-res screenshot first
    await page.screenshot({
      path: fullPng,
      clip: { x: 0, y: 0, width, height },
      omitBackground: false,
    });

    await browser.close();

    // Downscale via ffmpeg (avoids file:// protocol restrictions in Chromium)
    const { execSync } = require('child_process');
    execSync('/usr/bin/ffmpeg -y -i ' + JSON.stringify(fullPng) +
             ' -vf scale=' + thumbW + ':' + thumbH + ':flags=lanczos ' +
             JSON.stringify(outPng), { stdio: 'pipe' });
  } catch (e) {
    err = e;
    try { await browser.close(); } catch(_){}
  } finally {
    try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch(_) {}
    try { fs.rmSync(fullPng, { force: true }); } catch(_) {}
  }

  if (err) throw err;
  return outPng;
}

(async () => {
  const [templateUrl, outPng, wArg, hArg, tArg, twArg, thArg] = process.argv.slice(2);
  if (!templateUrl || !outPng) {
    process.stderr.write('Usage: bake-thumbnail.cjs <template_url> <out_png> [width] [height] [freeze_t_ms] [thumb_w] [thumb_h]\n');
    process.exit(2);
  }
  const w = parseInt(wArg || '1080', 10);
  const h = parseInt(hArg || '1920', 10);
  const t = parseInt(tArg || '8000', 10);
  // Default thumbnail sizes per format
  const isLandscape = w > h;
  const isSquare    = w === h;
  const defaultTw = isLandscape ? 800 : (isSquare ? 600 : 600);
  const defaultTh = isLandscape ? 450 : (isSquare ? 600 : 1067);
  const tw = parseInt(twArg || String(defaultTw), 10);
  const th = parseInt(thArg || String(defaultTh), 10);
  try {
    await bake(templateUrl, outPng, w, h, t, tw, th);
    process.stdout.write(JSON.stringify({ ok: true, out: outPng, src: [w,h], thumb: [tw,th], freeze_ms: t }) + '\n');
    process.exit(0);
  } catch (e) {
    process.stderr.write('thumb_error: ' + (e && e.message ? e.message : String(e)) + '\n');
    process.exit(1);
  }
})();
