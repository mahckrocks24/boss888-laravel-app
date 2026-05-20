#!/usr/bin/env node
/**
 * SEO Image Audit — Tier 2 rendered-DOM image extractor.
 *
 * Used by SeoService::tier2ExtractRendered() when raw-HTML extraction
 * (Tier 1) yields zero images, typical for JS-rendered SPA / React /
 * Vue marketing pages.
 *
 * Usage: node page-image-extract.cjs <url>
 *
 * Output (stdout, single JSON line):
 *   { ok: true, count: N, images: [{src, alt, width, height, kind}] }
 *
 * Errors print { ok:false, error:'...' } and exit 1.
 *
 * Strict scope:
 *   - Read-only fetch + DOM eval; no clicks, no form interaction
 *   - 30s wall-clock cap (browser launch + nav + extract)
 *   - Same-origin enforcement is the CALLER's responsibility (PHP layer)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

function _resolveChromePath() {
  if (process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(process.env.PUPPETEER_EXECUTABLE_PATH)) {
    return process.env.PUPPETEER_EXECUTABLE_PATH;
  }
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
  throw new Error('Chromium binary not found.');
}

async function main() {
  const argv = process.argv.slice(2);
  if (argv.length < 1) {
    process.stdout.write(JSON.stringify({ ok: false, error: 'usage: page-image-extract.cjs <url>' }));
    process.exit(2);
  }
  const url = argv[0];

  // Hard wall clock — kill the process if puppeteer hangs.
  const watchdog = setTimeout(() => {
    process.stdout.write(JSON.stringify({ ok: false, error: 'wall_clock_exceeded' }));
    process.exit(3);
  }, 30000);

  let browser = null;
  try {
    browser = await puppeteer.launch({
      executablePath: _resolveChromePath(),
      headless: 'new',
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--disable-crash-reporter',
        '--disable-breakpad',
        '--disable-features=VizDisplayCompositor',
      ],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 1024, deviceScaleFactor: 1 });
    await page.setUserAgent('LevelUpSEO/1.0 (rendered-extractor; +https://levelupgrowth.io)');

    // Block obvious heavyweight resources to keep the render fast — we don't
    // need video/font streams to enumerate <img> tags, but we DO need CSS
    // (for background-image computation) and JS (for hydration).
    await page.setRequestInterception(true);
    page.on('request', (req) => {
      const t = req.resourceType();
      if (t === 'media' || t === 'font') return req.abort();
      req.continue();
    });

    await page.goto(url, { waitUntil: 'networkidle2', timeout: 25000 });

    // Give SPA frameworks a beat to hydrate beyond initial paint.
    await new Promise(r => setTimeout(r, 800));

    const images = await page.evaluate(() => {
      const out = [];
      const seen = new Set();
      const push = (src, alt, width, height, kind) => {
        if (!src) return;
        if (src.startsWith('data:')) return;
        const norm = src.split('#')[0];
        if (seen.has(norm)) return;
        seen.add(norm);
        out.push({
          src: norm,
          alt: alt || null,
          width: width || null,
          height: height || null,
          kind: kind || 'img',
        });
      };

      // 1) <img src> + img attrs
      document.querySelectorAll('img').forEach(el => {
        const src = el.currentSrc || el.src || el.getAttribute('data-src')
          || el.getAttribute('data-lazy-src') || el.getAttribute('data-original') || '';
        const alt = el.getAttribute('alt');
        const w = el.naturalWidth || el.width || null;
        const h = el.naturalHeight || el.height || null;
        push(src, alt, w, h, 'img');
      });

      // 2) <source srcset> from <picture> — pick first URL of largest descriptor
      document.querySelectorAll('picture source[srcset]').forEach(el => {
        const ss = (el.getAttribute('srcset') || '').trim();
        if (!ss) return;
        let best = '';
        let bestScore = -1;
        ss.split(',').forEach(part => {
          const bits = part.trim().split(/\s+/);
          if (!bits[0]) return;
          let score = 0;
          if (bits[1]) {
            const m = bits[1].match(/^(\d+)([wx])$/);
            if (m) score = parseInt(m[1], 10) * (m[2] === 'x' ? 1000 : 1);
          }
          if (score >= bestScore) { bestScore = score; best = bits[0]; }
        });
        push(best, null, null, null, 'source');
      });

      // 3) CSS background-image — walk up to N visible elements
      let bgScanned = 0;
      const all = document.querySelectorAll('*');
      for (let i = 0; i < all.length && bgScanned < 5000; i++) {
        const el = all[i];
        const cs = window.getComputedStyle(el);
        const bg = cs.getPropertyValue('background-image');
        if (!bg || bg === 'none') continue;
        bgScanned++;
        // Match url(...) — handles 'url("...")', 'url(\'...\')', 'url(...)'
        const re = /url\((['"]?)([^'")]+)\1\)/g;
        let m;
        while ((m = re.exec(bg)) !== null) {
          let u = m[2];
          if (u && !u.startsWith('data:')) {
            // Resolve relative URLs against page origin
            try { u = new URL(u, window.location.href).toString(); } catch (_e) {}
            push(u, null, null, null, 'background');
          }
        }
      }

      return out;
    });

    clearTimeout(watchdog);
    process.stdout.write(JSON.stringify({ ok: true, count: images.length, images }));
    await browser.close();
    process.exit(0);
  } catch (e) {
    clearTimeout(watchdog);
    if (browser) { try { await browser.close(); } catch (_e) {} }
    process.stdout.write(JSON.stringify({ ok: false, error: String(e && e.message || e) }));
    process.exit(1);
  }
}

main().catch(e => {
  process.stdout.write(JSON.stringify({ ok: false, error: 'unhandled: ' + String(e && e.message || e) }));
  process.exit(1);
});
