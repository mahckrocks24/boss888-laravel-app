#!/usr/bin/env node
// Bake source template at native 1080x1920 by overriding .scene/.sw scale.
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

function _resolveChromePath(){
  if (process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(process.env.PUPPETEER_EXECUTABLE_PATH)) return process.env.PUPPETEER_EXECUTABLE_PATH;
  const cacheRoot = process.env.PUPPETEER_CACHE_DIR || '/var/www/levelup-staging/.puppeteer-cache';
  try { const r = path.join(cacheRoot,'chrome'); const vs = fs.readdirSync(r).filter(v=>v.startsWith('linux-')).sort(); return path.join(r,vs[vs.length-1],'chrome-linux64','chrome'); } catch(_){}
  return puppeteer.executablePath();
}

(async()=>{
  const [url, out, tArg, twArg, thArg] = process.argv.slice(2);
  const t  = parseInt(tArg  || '8000', 10);
  const tw = parseInt(twArg || '600', 10);
  const th = parseInt(thArg || '1067',10);
  const full = out + '.full.png';
  const browser = await puppeteer.launch({
    executablePath: _resolveChromePath(),
    userDataDir: '/tmp/src-bake-' + process.pid,
    headless: 'new',
    args: ['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage','--disable-gpu','--no-first-run','--no-zygote','--disable-crash-reporter','--hide-scrollbars'],
  });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1080, height: 1920, deviceScaleFactor: 1 });
    await page.goto(url, { waitUntil: 'networkidle0', timeout: 30000 });
    try { await page.evaluate(() => document.fonts.ready); } catch(_){}
    await new Promise(r => setTimeout(r, 500));

    // Override the responsive .sw/.scale-wrap scale so the reel is native 1080x1920.
    await page.addStyleTag({ content:
      '.scene{padding:0!important;display:block!important;min-height:0!important;background:transparent!important}' +
      '.sw,.scale-wrap{transform:none!important;width:auto!important;height:auto!important;display:block!important}' +
      'html,body{overflow:hidden!important}'
    });

    // Freeze and force-visible
    await page.evaluate((ms) => {
      document.getAnimations().forEach(a => { try { a.currentTime = ms; a.pause(); } catch(_){} });
      document.querySelectorAll('*').forEach(el => {
        var cs = window.getComputedStyle(el);
        if (cs.opacity === '0') el.style.opacity = '1';
        if (cs.transform && cs.transform !== 'none' && cs.transform !== 'matrix(1, 0, 0, 1, 0, 0)') {
          el.style.transform = 'none';
        }
      });
    }, t);
    await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
    await new Promise(r => setTimeout(r, 500));

    // Try to capture just the .reel element (more reliable than viewport clip)
    const reel = await page.$('.reel');
    if (reel){
      await reel.screenshot({ path: full, omitBackground: false });
    } else {
      await page.screenshot({ path: full, clip: { x:0, y:0, width:1080, height:1920 }, omitBackground: false });
    }

    await browser.close();

    execSync('/usr/bin/ffmpeg -y -i ' + JSON.stringify(full) + ' -vf scale=' + tw + ':' + th + ':flags=lanczos ' + JSON.stringify(out), { stdio: 'pipe' });
    process.stdout.write(JSON.stringify({ ok: true, out, thumb: [tw, th] }) + '\n');
  } catch(e){
    try { await browser.close(); } catch(_){}
    process.stderr.write('err: ' + e.message + '\n');
    process.exit(1);
  } finally {
    try { fs.rmSync(full, { force: true }); } catch(_){}
  }
})();
