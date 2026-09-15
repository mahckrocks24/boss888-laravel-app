// SITE THUMBNAIL (2026-09-15): a small 480x300 JPEG of a site's exported home page for the Websites-page card.
// usage: node site-thumb.cjs <url> <out.jpg>
const puppeteer = require('/var/www/levelup-staging/node_modules/puppeteer');
const [url, out] = process.argv.slice(2);
if (!url || !out) { console.error('usage: site-thumb.cjs <url> <out.jpg>'); process.exit(2); }
(async () => {
  const b = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--hide-scrollbars'] });
  try {
    const p = await b.newPage();
    // the page lays out at the Owner's 1280x800 viewport; the scale factor makes the bitmap 480x300 (low-res, card only)
    await p.setViewport({ width: 1280, height: 800, deviceScaleFactor: 0.375 });
    await p.goto(url, { waitUntil: 'networkidle2', timeout: 45000 }).catch(() => {});
    await p.evaluate(() => new Promise(r => { if (document.fonts && document.fonts.ready) document.fonts.ready.then(r); setTimeout(r, 1500); }));
    await new Promise(r => setTimeout(r, 1200));
    await p.screenshot({ path: out, type: 'jpeg', quality: 70, clip: { x: 0, y: 0, width: 1280, height: 800 } });
    console.log('ok ' + out);
  } finally { await b.close(); }
})().catch(e => { console.error('ERR ' + e.message); process.exit(1); });
