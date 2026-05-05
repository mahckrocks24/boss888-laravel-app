#!/usr/bin/env node
/**
 * Render an email-template HTML file to a 1200x800 PNG thumbnail.
 * Reuses the Studio puppeteer cache. 2-arg invocation: inHtml outPng.
 */
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

function _chrome() {
  if (process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(process.env.PUPPETEER_EXECUTABLE_PATH)) return process.env.PUPPETEER_EXECUTABLE_PATH;
  const root = process.env.PUPPETEER_CACHE_DIR || '/var/www/levelup-staging/.puppeteer-cache';
  try {
    const r = path.join(root, 'chrome');
    const vs = fs.readdirSync(r).filter(v => v.startsWith('linux-')).sort();
    return path.join(r, vs[vs.length - 1], 'chrome-linux64', 'chrome');
  } catch (_) {}
  return puppeteer.executablePath();
}

(async () => {
  const [inHtml, outPng] = process.argv.slice(2);
  if (!inHtml || !outPng) { process.stderr.write('usage: bake-email-thumbnail.cjs <in.html> <out.png>\n'); process.exit(2); }

  const browser = await puppeteer.launch({
    executablePath: _chrome(),
    userDataDir: '/tmp/email-thumb-' + process.pid,
    headless: 'new',
    args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage','--disable-gpu','--no-first-run','--no-zygote','--hide-scrollbars'],
  });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 600, height: 800, deviceScaleFactor: 1 });
    const file = 'file://' + path.resolve(inHtml);
    await page.goto(file, { waitUntil: 'networkidle0', timeout: 30000 });
    try { await page.evaluate(() => document.fonts.ready); } catch (_) {}
    await new Promise(r => setTimeout(r, 400));
    await page.screenshot({ path: outPng, clip: { x: 0, y: 0, width: 600, height: 800 } });
    process.stdout.write('ok\n');
  } finally {
    try { await browser.close(); } catch (_) {}
  }
})().catch(e => { process.stderr.write('err: ' + e.message + '\n'); process.exit(1); });
