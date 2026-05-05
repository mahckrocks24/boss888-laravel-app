#!/usr/bin/env node
// Debug: render the template with EVERYTHING visible at freeze-time, for comparison.
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
function _resolveChromePath(){
  if (process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(process.env.PUPPETEER_EXECUTABLE_PATH)) return process.env.PUPPETEER_EXECUTABLE_PATH;
  const cacheRoot = process.env.PUPPETEER_CACHE_DIR || '/var/www/levelup-staging/.puppeteer-cache';
  try { const r = path.join(cacheRoot,'chrome'); const vs = fs.readdirSync(r).filter(v=>v.startsWith('linux-')).sort(); return path.join(r,vs[vs.length-1],'chrome-linux64','chrome'); } catch(_){}
  return puppeteer.executablePath();
}
(async()=>{
  const [url, out, fT] = process.argv.slice(2);
  const t = parseInt(fT||'6000',10);
  const browser = await puppeteer.launch({ executablePath:_resolveChromePath(), userDataDir:'/tmp/naked-'+process.pid, headless:'new', args:['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage','--disable-gpu','--no-first-run','--no-zygote','--disable-crash-reporter','--hide-scrollbars'] });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width:1080, height:1920, deviceScaleFactor:1 });
    await page.goto(url, { waitUntil:'networkidle0', timeout:30000 });
    try { await page.evaluate(()=>document.fonts.ready); } catch(_){}
    await new Promise(r=>setTimeout(r,500));
    await page.evaluate((ms)=>{ document.getAnimations().forEach(a=>{ try{a.currentTime=ms;a.pause();}catch(_){}}); }, t);
    await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
    await new Promise(r=>setTimeout(r,500));
    await page.screenshot({ path:out, clip:{x:0,y:0,width:1080,height:1920}, omitBackground:false });
    process.stdout.write('ok\n');
  } finally { try{ await browser.close(); }catch(_){} }
})();
