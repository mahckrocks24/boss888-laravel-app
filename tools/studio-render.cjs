#!/usr/bin/env node
/**
 * Studio PNG renderer — puppeteer headless Chrome
 *
 * Usage:
 *   node studio-render.js <html-file> <width> <height> <out-png>
 *
 * Renders the design HTML inside a headless Chrome viewport at exact
 * <width>×<height> resolution (matching the template canvas) and writes
 * a PNG snapshot of the .canvas / .post element.
 *
 * Why server-side: html2canvas 1.4.x has known gaps with object-fit,
 * font-face loading races, and percentage grid layout. Real Chromium
 * sidesteps all of those — whatever the editor shows is what we capture.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const puppeteer = require('puppeteer');

// Resolve the Chromium binary path explicitly so the script works whether
// invoked directly (with PUPPETEER_CACHE_DIR env set) OR via PHP-FPM's
// proc_open (which doesn't inherit that env var).
//
// Order:
//   1. PUPPETEER_EXECUTABLE_PATH env (operator override)
//   2. PUPPETEER_CACHE_DIR env → puppeteer.executablePath()
//   3. Scan the known Laravel-local cache dir
//   4. Fall back to puppeteer.executablePath() with its default (often fails
//      under PHP-FPM but worth trying before we bail)
function _resolveChromePath() {
  if (process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(process.env.PUPPETEER_EXECUTABLE_PATH)) {
    return process.env.PUPPETEER_EXECUTABLE_PATH;
  }
  // Known install location on this host — installed by `npm install puppeteer`
  // with PUPPETEER_CACHE_DIR pointing at the Laravel tree.
  const cacheRoot = process.env.PUPPETEER_CACHE_DIR
    || '/var/www/levelup-staging/.puppeteer-cache';
  try {
    const chromeRoot = path.join(cacheRoot, 'chrome');
    if (fs.existsSync(chromeRoot)) {
      const versions = fs.readdirSync(chromeRoot).filter(v => v.startsWith('linux-')).sort();
      if (versions.length) {
        const p = path.join(chromeRoot, versions[versions.length - 1], 'chrome-linux64', 'chrome');
        if (fs.existsSync(p)) return p;
      }
    }
  } catch (_e) {}
  try {
    const p = puppeteer.executablePath();
    if (p && fs.existsSync(p)) return p;
  } catch (_e) {}
  throw new Error('Chromium binary not found. Set PUPPETEER_EXECUTABLE_PATH or install via `PUPPETEER_CACHE_DIR=/var/www/levelup-staging/.puppeteer-cache npm install puppeteer`.');
}

async function main() {
  const argv = process.argv.slice(2);
  if (argv.length < 4) {
    process.stderr.write('Usage: studio-render.js <html-file> <width> <height> <out-png>\n');
    process.exit(2);
  }
  const [htmlFile, wArg, hArg, outFile] = argv;
  const width  = Math.max(320, Math.min(2400, parseInt(wArg, 10) || 1080));
  const height = Math.max(320, Math.min(2400, parseInt(hArg, 10) || 1080));

  if (!fs.existsSync(htmlFile)) {
    process.stderr.write('Input HTML not found: ' + htmlFile + '\n');
    process.exit(3);
  }
  const html = fs.readFileSync(htmlFile, 'utf8');

  // Fresh profile dir per invocation under a world-writable tmp root.
  // Prevents www-data from trying to write into /root/.cache or
  // $HOME/.config (which causes the crashpad --database error).
  const profileDir = path.join(os.tmpdir(), 'pppt-' + crypto.randomBytes(6).toString('hex'));
  fs.mkdirSync(profileDir, { recursive: true });

  const chromePath = _resolveChromePath();

  const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: chromePath,
    userDataDir: profileDir,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',       // low-memory hosts
      '--disable-gpu',
      '--hide-scrollbars',
      '--disable-crash-reporter',      // crashpad wants a writable home
      '--disable-breakpad',
      '--no-zygote',                   // simpler process model on small VPS
      '--disable-features=VizDisplayCompositor',
      '--font-render-hinting=none'     // match editor rendering
    ]
  });

  try {
    const page = await browser.newPage();
    await page.setViewport({ width, height, deviceScaleFactor: 1 });

    // setContent + waitUntil:networkidle0 lets images/fonts finish loading.
    await page.setContent(html, { waitUntil: 'networkidle0', timeout: 30000 });

    // Belt-and-suspenders: explicit font readiness + one paint tick.
    await page.evaluate(() => document.fonts && document.fonts.ready);
    await new Promise(r => setTimeout(r, 400));

    // Locate the canvas element and its box; fall back to the viewport if
    // the template doesn't follow the .canvas / .post convention.
    const rect = await page.evaluate(() => {
      const el = document.querySelector('.canvas') ||
                 document.querySelector('.post')   ||
                 document.body;
      const r = el.getBoundingClientRect();
      return { x: r.left, y: r.top, width: r.width, height: r.height };
    });

    // Clip to the canvas element so body margin/padding can't leak into
    // the output. Use width/height args as the authoritative dimensions —
    // if the rect is off by a px, the viewport still dictates the PNG size.
    await page.screenshot({
      path: outFile,
      type: 'png',
      clip: {
        x: Math.max(0, rect.x),
        y: Math.max(0, rect.y),
        width:  Math.min(width,  Math.round(rect.width)  || width),
        height: Math.min(height, Math.round(rect.height) || height)
      },
      omitBackground: false
    });
  } finally {
    try { await browser.close(); } catch (_e) {}
    // Clean up the per-run profile dir.
    try { fs.rmSync(profileDir, { recursive: true, force: true }); } catch (_e) {}
  }
}

main().catch(err => {
  process.stderr.write('render_error: ' + (err && err.stack ? err.stack : String(err)) + '\n');
  process.exit(1);
});
