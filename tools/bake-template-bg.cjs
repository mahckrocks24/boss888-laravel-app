#!/usr/bin/env node
/**
 * bake-template-bg.cjs
 * --------------------
 * Pre-renders an animated HTML template's DECORATIVE layers to a PNG that
 * can then be used as the background image clip in a clip_json design.
 *
 * Usage:
 *   node bake-template-bg.cjs <template_url> <out_png> [width] [height] [freeze_t_ms]
 *
 * Hides everything with a [data-field] attribute so the editable text is
 * stripped — only rings, blobs, brackets, scan line, dividers, vignette,
 * grain etc. end up in the PNG. Freezes animations at `freeze_t_ms`
 * (default 4000ms = mid-reveal) so decorative layers are fully visible.
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

async function bake(templateUrl, outPng, width, height, freezeMs) {
  const execPath = _resolveChromePath();
  const userDataDir = '/tmp/bake-' + process.pid + '-' + Date.now();
  const browser = await puppeteer.launch({
    executablePath: execPath,
    userDataDir,
    headless: 'new',
    args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage','--disable-gpu','--no-first-run','--no-zygote','--disable-crash-reporter','--disable-extensions','--mute-audio','--hide-scrollbars'],
  });

  let err = null;
  try {
    const page = await browser.newPage();
    await page.setViewport({ width, height, deviceScaleFactor: 1 });
    await page.goto(templateUrl, { waitUntil: 'networkidle0', timeout: 30000 });
    try { await page.evaluate(() => document.fonts.ready); } catch(_) {}
    await new Promise(r => setTimeout(r, 500));

    // ── STEP 1: Pause + seek all animations ──
    await page.evaluate((t) => {
      document.getAnimations().forEach(a => {
        try { a.currentTime = t; a.pause(); } catch(_){}
      });
    }, freezeMs);

    // ── STEP 2: Force-visible every element whose computed opacity is 0 ──
    // Headless Chromium doesn't always commit seeked animation values.
    // Elements with CSS `opacity:0` baseline (and `forwards` fill-mode that
    // should end at 1) stay invisible. Override via inline style.
    await page.evaluate(() => {
      document.querySelectorAll('*').forEach(el => {
        var cs = window.getComputedStyle(el);
        if (cs.opacity === '0') el.style.opacity = '1';
        if (cs.transform && cs.transform !== 'none' && cs.transform !== 'matrix(1, 0, 0, 1, 0, 0)') {
          el.style.transform = 'none';
        }
        // Zero-width "draw" animations (divider lines etc.) end at width:100%
        if (cs.width === '0px' && el.style.width === '' && el.offsetParent) {
          // Only override if the element's parent is positioned (i.e. it's a
          // decorative line, not some weird collapsed container).
          el.style.width = '100%';
        }
      });
    });
    await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
    await new Promise(r => setTimeout(r, 500));

    // ── STEP 3: Data-driven text hide ──
    // The editable text lives inside `[data-field]` elements (injected by
    // the converter). Hide these universally so the BG PNG has zero text.
    // Any template produced by the converter becomes bakeable without a
    // per-template selector list.
    await page.evaluate(() => {
      document.querySelectorAll('[data-field]').forEach(function(el){
        // For plain text nodes, visibility:hidden removes glyph rendering
        // but preserves layout box (so parent bg/border/pill shape stays).
        var tag = el.tagName;
        if (tag === 'IMG' || tag === 'VIDEO') return; // images stay in BG
        // Keep any decorative parent pill/button shape — just zero the text.
        // visibility:hidden doesn't leak to siblings, and children (arrows
        // etc.) will also be hidden which is usually what we want.
        el.style.visibility = 'hidden';
        // If the element IS the full pill/button (has its own bg), we want
        // to keep the bg. visibility:hidden on the outer container also
        // hides the bg. Detect that case and use color:transparent instead.
        var bg = window.getComputedStyle(el).backgroundColor;
        var hasBg = bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent';
        var border = window.getComputedStyle(el).borderColor;
        var hasBorder = window.getComputedStyle(el).borderStyle !== 'none' &&
                        window.getComputedStyle(el).borderWidth !== '0px';
        if (hasBg || hasBorder){
          el.style.visibility = '';  // restore
          el.style.color = 'transparent';
          el.style.textShadow = 'none';
        }
      });
    });

    // Final paint commit before screenshot
    await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
    await new Promise(r => setTimeout(r, 500));

    await page.screenshot({
      path: outPng,
      clip: { x: 0, y: 0, width, height },
      omitBackground: false,
    });
  } catch (e) {
    err = e;
  } finally {
    try { await browser.close(); } catch(_) {}
    try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch(_) {}
  }

  if (err) throw err;
  return outPng;
}

(async () => {
  const [templateUrl, outPng, wArg, hArg, tArg] = process.argv.slice(2);
  if (!templateUrl || !outPng) {
    process.stderr.write('Usage: bake-template-bg.cjs <template_url> <out_png> [width] [height] [freeze_t_ms]\n');
    process.exit(2);
  }
  const w = parseInt(wArg || '1080', 10);
  const h = parseInt(hArg || '1920', 10);
  const t = parseInt(tArg || '4000', 10);
  try {
    await bake(templateUrl, outPng, w, h, t);
    process.stdout.write(JSON.stringify({ ok: true, out: outPng, width: w, height: h, freeze_ms: t }) + '\n');
    process.exit(0);
  } catch (e) {
    process.stderr.write('bake_error: ' + (e && e.message ? e.message : String(e)) + '\n');
    process.exit(1);
  }
})();
