// How many editor views / stages / preview frames exist, and which one is visible?
const { chromium } = require('C:/Users/markr/AppData/Local/Temp/claude/C--Users-markr/a1258e38-7a7c-49ce-9c8f-89952f08f6c5/scratchpad/xb/node_modules/playwright');
const S = 'https://staging.levelupgrowth.io';
(async () => {
  const login = await (await fetch(S + '/api/auth/login', { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ email: 'lug-w4-muoo51ky@uberip.com', password: 'EdgeTest2026!q' }) })).json();
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  await ctx.addInitScript(([t, r, w]) => { localStorage.setItem('lu_token', t); if (r) localStorage.setItem('lu_refresh_token', r); localStorage.setItem('lu_workspace_id', String(w)); }, [login.access_token, login.refresh_token, login.current_workspace_id]);
  const p = await ctx.newPage();
  const click = async (sel, txt) => p.evaluate(([sel, txt]) => { const x = Array.from(document.querySelectorAll(sel)).find(b => b.offsetParent && b.getBoundingClientRect().width && (!txt || (b.innerText || '').trim().startsWith(txt))); if (x) { x.click(); return true; } return false; }, [sel, txt]);
  await p.goto(S + '/app/websites', { waitUntil: 'domcontentloaded' });
  await p.waitForFunction(() => Array.from(document.querySelectorAll('button')).some(e => e.innerText.trim() === 'Edit' && e.offsetParent), null, { timeout: 60000 }).catch(() => {});
  await click('button', 'Got it');
  console.log('edit buttons', await p.evaluate(() => Array.from(document.querySelectorAll('button')).filter(e => e.innerText.trim() === 'Edit' && e.offsetParent).length));
  await click('button', 'Edit');
  await p.waitForSelector('#t3-preview', { timeout: 30000 }).catch(() => {});
  await p.waitForTimeout(8000); await click('button', 'Skip tour'); await click('button', 'Got it'); await p.waitForTimeout(1000);
  const dom = () => p.evaluate(() => {
    const r = el => { const b = el.getBoundingClientRect(); return [Math.round(b.x), Math.round(b.y), Math.round(b.width), Math.round(b.height), getComputedStyle(el).display, getComputedStyle(el).visibility]; };
    return {
      views: Array.from(document.querySelectorAll('#template-editor-view')).map(r),
      stages: Array.from(document.querySelectorAll('.pe-stage')).map(e => [r(e), e.parentElement.id, e.children.length]),
      frames: Array.from(document.querySelectorAll('#t3-preview')).map(e => [r(e), e.src.slice(-60), (() => { try { return e.contentDocument.body ? e.contentDocument.body.innerText.slice(0, 40) : 'nobody'; } catch (x) { return 'x'; } })()]),
      bars: Array.from(document.querySelectorAll('.pe-bar')).map(r),
      topAtCentre: (() => { const e = document.elementFromPoint(800, 400); return e ? e.tagName + '#' + e.id : null; })(),
      url: location.href
    };
  });
  console.log(JSON.stringify(await dom(), null, 0));
  await p.screenshot({ path: __dirname + '/sheet/dom-editor.jpg', type: 'jpeg', quality: 50 });
  await b.close();
})();
