#!/usr/bin/env node
/**
 * Wave 18c (2026-05-19) — SEO report HTML → PDF renderer.
 *
 * Reads HTML body from STDIN, renders via puppeteer (same bundled Chromium
 * the studio renderer uses), writes the binary PDF to STDOUT.
 *
 * Usage:
 *   echo "<html>...</html>" | node report-render-pdf.cjs > out.pdf
 *
 * The wrapping PHP handler reads STDOUT as binary and streams it back as
 * a `Content-Type: application/pdf` response.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

(async () => {
  let html = '';
  try {
    html = fs.readFileSync(0, 'utf8'); // 0 = stdin
  } catch (e) {
    process.stderr.write('[report-render-pdf] failed to read stdin: ' + e.message + '\n');
    process.exit(2);
  }
  if (!html || html.length < 30) {
    process.stderr.write('[report-render-pdf] empty/short HTML on stdin\n');
    process.exit(3);
  }

  let browser;
  try {
    browser = await puppeteer.launch({
      headless: 'new',
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--font-render-hinting=none',
      ],
    });
    const page = await browser.newPage();
    await page.setContent(html, { waitUntil: 'networkidle0', timeout: 20000 });
    const pdf = await page.pdf({
      format: 'A4',
      printBackground: true,
      margin: { top: '14mm', right: '12mm', bottom: '14mm', left: '12mm' },
      preferCSSPageSize: false,
    });
    process.stdout.write(pdf);
    process.stdout.on('drain', () => {});
  } catch (e) {
    process.stderr.write('[report-render-pdf] error: ' + (e && e.message ? e.message : String(e)) + '\n');
    process.exit(4);
  } finally {
    if (browser) try { await browser.close(); } catch (_) {}
  }
})();
