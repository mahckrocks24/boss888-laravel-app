#!/usr/bin/env node
/**
 * studio-record.cjs — HTML animated template → MP4
 * =================================================
 * Loads a template.html URL in puppeteer, seeks the CSS animations
 * frame-by-frame via `document.getAnimations()` timeline control,
 * screenshots each frame, and encodes the sequence to H.264 MP4.
 *
 * Usage:
 *   node studio-record.cjs '<json-args>'
 * Args JSON:
 *   { templateUrl, outputPath, duration,
 *     fps=30, width=1080, height=1920,
 *     fields={}, paletteVars={} }
 *
 * Chromium resolution copied from tools/studio-render.cjs — uses
 * PUPPETEER_CACHE_DIR so it works under PHP-FPM where HOME is unset.
 */
const puppeteer = require('puppeteer');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

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
  throw new Error('Chromium binary not found. Set PUPPETEER_EXECUTABLE_PATH or install via PUPPETEER_CACHE_DIR=/var/www/levelup-staging/.puppeteer-cache npm install puppeteer.');
}

async function recordTemplate(args) {
  const {
    templateUrl,
    outputPath,
    duration,
    fps = 30,
    width = 1080,
    height = 1920,
    fields = {},
    paletteVars = {},
  } = args;

  if (!templateUrl || !outputPath || !duration) {
    throw new Error('recordTemplate: missing required arg (templateUrl, outputPath, duration)');
  }

  const execPath = _resolveChromePath();
  const userDataDir = '/tmp/studio-record-' + process.pid + '-' + Date.now();

  const browser = await puppeteer.launch({
    executablePath: execPath,
    userDataDir,
    headless: 'new',
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--no-first-run',
      '--no-zygote',
      '--disable-crash-reporter',
      '--disable-extensions',
      '--mute-audio',
      '--hide-scrollbars',
    ],
  });

  let lastError = null;
  let framesDir = null;

  try {
    const page = await browser.newPage();
    await page.setViewport({ width, height, deviceScaleFactor: 1 });
    await page.goto(templateUrl, { waitUntil: 'networkidle0', timeout: 30000 });

    // Wait for custom fonts to be ready.
    try { await page.evaluate(() => document.fonts.ready); } catch (_e) {}
    await new Promise(r => setTimeout(r, 500));

    // Apply field text overrides.
    if (Object.keys(fields).length > 0) {
      await page.evaluate((fields) => {
        Object.keys(fields).forEach(function (f) {
          var el = document.querySelector('[data-field="' + f + '"]');
          if (el) el.textContent = fields[f];
        });
      }, fields);
    }

    // Apply CSS variable overrides.
    if (Object.keys(paletteVars).length > 0) {
      await page.evaluate((vars) => {
        Object.keys(vars).forEach(function (k) {
          document.documentElement.style.setProperty(k, vars[k]);
        });
      }, paletteVars);
    }

    // Pause all animations and reset to t=0 so we control time manually.
    await page.evaluate(() => {
      document.getAnimations().forEach(a => {
        try { a.currentTime = 0; a.pause(); } catch (_e) {}
      });
    });

    // Prepare frame dir.
    framesDir = outputPath + '_frames';
    if (fs.existsSync(framesDir)) {
      fs.rmSync(framesDir, { recursive: true, force: true });
    }
    fs.mkdirSync(framesDir, { recursive: true });

    const totalFrames = Math.ceil(duration * fps);

    // Capture frames — set animation timeline, wait one paint, screenshot.
    for (let i = 0; i < totalFrames; i++) {
      const timeMs = (i / fps) * 1000;
      await page.evaluate((t) => {
        document.getAnimations().forEach(a => {
          try { a.currentTime = t; } catch (_e) {}
        });
      }, timeMs);
      // Give the engine one animation frame to commit the paint.
      await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));

      const framePath = path.join(framesDir, 'frame-' + String(i).padStart(6, '0') + '.png');
      await page.screenshot({
        path: framePath,
        clip: { x: 0, y: 0, width, height },
        omitBackground: false,
      });
    }

    await browser.close();

    // Encode with FFmpeg — ultrafast preset to stay within job timeout.
    const ffmpegCmd = [
      '/usr/bin/ffmpeg', '-y',
      '-framerate', String(fps),
      '-i', '"' + path.join(framesDir, 'frame-%06d.png') + '"',
      '-c:v', 'libx264',
      '-preset', 'ultrafast',
      '-crf', '20',
      '-pix_fmt', 'yuv420p',
      '-movflags', '+faststart',
      '"' + outputPath + '"',
    ].join(' ');
    execSync(ffmpegCmd, { stdio: 'pipe' });

  } catch (e) {
    lastError = e;
    try { await browser.close(); } catch (_e) {}
  } finally {
    // Cleanup
    if (framesDir && fs.existsSync(framesDir)) {
      try { fs.rmSync(framesDir, { recursive: true, force: true }); } catch (_e) {}
    }
    try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (_e) {}
  }

  if (lastError) throw lastError;
  return outputPath;
}

// CLI entry.
(async () => {
  const argStr = process.argv[2];
  if (!argStr) {
    process.stderr.write('Usage: studio-record.cjs <json-args>\n');
    process.exit(2);
  }
  let args;
  try { args = JSON.parse(argStr); }
  catch (e) {
    process.stderr.write('Invalid JSON args: ' + e.message + '\n');
    process.exit(2);
  }
  try {
    const out = await recordTemplate(args);
    process.stdout.write(JSON.stringify({ ok: true, output: out }) + '\n');
    process.exit(0);
  } catch (e) {
    process.stderr.write('record_error: ' + (e && e.message ? e.message : String(e)) + '\n');
    process.exit(1);
  }
})();
