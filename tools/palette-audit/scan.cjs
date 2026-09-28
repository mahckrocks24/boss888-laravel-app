// PALETTE AUDIT scanner: opens every template x palette page (generated on the server by the real palette switch)
// in a local Chrome, with the live contrast fix switched OFF, and measures every piece of text against what is behind it.
const puppeteer = require('puppeteer-core');
const fs = require('fs'); const os = require('os'); const path = require('path');
const BASE = 'https://staging.levelupgrowth.io/palaudit-pa7c19e3/';
const LIST = fs.readFileSync(path.join(__dirname, 'list.txt'), 'utf8').split(/\r?\n/).filter(Boolean);   // "slug/palette"
const OUT = path.join(__dirname, 'results.jsonl');
const CONC = +(process.env.CONC || 4);
const done = new Set(fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8').split('\n').filter(Boolean).map(l => { try { return JSON.parse(l).k; } catch (e) { return null; } }) : []);

const ANALYSE = `(() => {
  function parse(s){ if(!s) return null; s=String(s).trim(); var m=s.match(/^rgba?\\(([^)]+)\\)$/i); if(m){var v=m[1].split(/[\\s,\\/]+/).filter(Boolean).map(parseFloat);return{r:v[0],g:v[1],b:v[2],a:v.length>3?v[3]:1}} m=s.match(/^color\\(srgb\\s+([^)]+)\\)$/i); if(m){var w=m[1].split(/[\\s\\/]+/).filter(Boolean).map(parseFloat);return{r:w[0]*255,g:w[1]*255,b:w[2]*255,a:w.length>3?w[3]:1}} if(s==='transparent')return{r:0,g:0,b:0,a:0}; return null }
  function colorsIn(s){ var out=[],re=/rgba?\\([^)]*\\)|color\\(srgb[^)]*\\)/gi,m; while((m=re.exec(s))){var c=parse(m[0]); if(c) out.push(c)} return out }
  function blend(t,b){var a=t.a;return{r:t.r*a+b.r*(1-a),g:t.g*a+b.g*(1-a),b:t.b*a+b.b*(1-a),a:1}}
  function lum(c){function f(x){x/=255;return x<=0.03928?x/12.92:Math.pow((x+0.055)/1.055,2.4)}return 0.2126*f(c.r)+0.7152*f(c.g)+0.0722*f(c.b)}
  function ratio(a,b){var x=lum(a),y=lum(b);return(Math.max(x,y)+0.05)/(Math.min(x,y)+0.05)}
  function bgBehind(el){ var chain=[],n=el; while(n&&n.nodeType===1){chain.push(n);n=n.parentElement} var base={r:255,g:255,b:255,a:1};
    for(var i=chain.length-1;i>=0;i--){ var e=chain[i],cs=getComputedStyle(e); if(/^(absolute|fixed|sticky)$/.test(cs.position)) base=null;
      var img=cs.backgroundImage; if(img&&img!=='none'){ if(/url\\(/i.test(img)) base=null; else { var st=colorsIn(img); if(st.some(function(c){return c.a>0.35})||!st.length) base=null } }
      var bc=parse(cs.backgroundColor); if(bc&&bc.a>0){ if(bc.a>=0.95||(!base&&bc.a>=0.85)) base={r:bc.r,g:bc.g,b:bc.b,a:1}; else if(base) base=blend(bc,base) }
      var sh=cs.boxShadow; if(sh&&sh!=='none'&&/inset/.test(sh)&&/100vmax|\\d{3,}px/.test(sh)){ var vc=colorsIn(sh)[0]; if(vc&&base) base=blend(vc,base) } }
    return base }
  var media=[]; document.querySelectorAll("img,video,picture,canvas,iframe").forEach(function(m){var r=m.getBoundingClientRect(); if(r.width<40||r.height<40) return; media.push({el:m,x:r.left+scrollX,y:r.top+scrollY,w:r.width,h:r.height})});
  function overMedia(el){var r=el.getBoundingClientRect(); if(!r.width||!r.height) return false; var x=r.left+scrollX,y=r.top+scrollY,area=r.width*r.height; for(var i=0;i<media.length;i++){var m=media[i]; if(el.contains(m.el)||m.el.contains(el)) continue; var ox=Math.max(0,Math.min(x+r.width,m.x+m.w)-Math.max(x,m.x)),oy=Math.max(0,Math.min(y+r.height,m.y+m.h)-Math.max(y,m.y)); if(ox*oy>area*0.3) return true} return false}
  var bad=[],weak=0,checked=0,seen=new Set(); var tw=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT,null),t;
  while((t=tw.nextNode())){ if(!t.nodeValue||t.nodeValue.trim().length<2) continue; var el=t.parentElement; if(!el||seen.has(el)) continue; seen.add(el);
    if(el.closest('script,style,noscript,svg,iframe,[aria-hidden="true"]')) continue; if(!el.getClientRects().length) continue;
    var cs=getComputedStyle(el); if(cs.visibility==='hidden') continue; if(/text/.test(cs.backgroundClip||cs.webkitBackgroundClip||'')) continue;
    var fill=parse(cs.webkitTextFillColor); if(fill&&fill.a===0) continue; var fg=parse(cs.color); if(!fg||fg.a<0.15) continue;
    var bg=bgBehind(el); if(!bg) continue; if(overMedia(el)) continue; checked++; var on=fg.a<1?blend(fg,bg):fg; var r=ratio(on,bg);
    var big=parseFloat(cs.fontSize)>=24||(parseFloat(cs.fontSize)>=18.6&&+cs.fontWeight>=700);
    if(r<3){ var blk=el.closest('[data-block]'); bad.push({ r:+r.toFixed(2), block: blk?blk.getAttribute('data-block'):(el.closest('section,footer,header,nav')||{}).tagName||'-', tag: el.tagName, cls: String(el.className||'').split(' ')[0].slice(0,30), text: el.textContent.trim().slice(0,40), fg: cs.color, bg: 'rgb('+Math.round(bg.r)+','+Math.round(bg.g)+','+Math.round(bg.b)+')' }) }
    else if(r<(big?3:4.5)) weak++ }
  return { checked: checked, unreadable: bad.length, weak: weak, bad: bad.slice(0, 12) };
})()`;

(async () => {
  const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new',
    userDataDir: fs.mkdtempSync(path.join(os.tmpdir(), 'palaudit-')), args: ['--no-first-run', '--disable-extensions'] });
  const todo = LIST.filter(k => !done.has(k));
  let i = 0, ok = 0, t0 = Date.now();
  async function worker() {
    const p = await b.newPage(); await p.setViewport({ width: 1280, height: 900 });
    await p.evaluateOnNewDocument(() => { window.__luContrastLive = 1; });   // measure the template, not the fix
    await p.setRequestInterception(true);
    p.on('request', r => { const ty = r.resourceType(); if (ty === 'image' || ty === 'media' || ty === 'font' || /chatbot|__lug|\/api\//.test(r.url())) r.abort(); else r.continue(); });
    while (i < todo.length) {
      const k = todo[i++];
      let rec = { k };
      try {
        await p.goto(BASE + k + '.html', { waitUntil: 'domcontentloaded', timeout: 45000 });
        await new Promise(r => setTimeout(r, 400));
        rec = Object.assign(rec, await p.evaluate(ANALYSE));
      } catch (e) { rec.error = String(e.message || e).slice(0, 120); }
      fs.appendFileSync(OUT, JSON.stringify(rec) + '\n'); ok++;
      if (ok % 50 === 0) console.log(ok + '/' + todo.length + ' in ' + Math.round((Date.now() - t0) / 1000) + 's');
    }
    await p.close();
  }
  await Promise.all(Array.from({ length: CONC }, worker));
  await b.close();
  console.log('scanned ' + ok + ' pages in ' + Math.round((Date.now() - t0) / 1000) + 's');
})();
