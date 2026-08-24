/**
 * MISSION-018 WS-4 — OTG-1 driven browser walk.
 *
 * Registers a THROWAWAY account through the real API, boots the SPA the way a
 * customer does (both tokens under the SPA's own keys), drives the meet-Sarah
 * chat with real clicks/typing against the real Runtime, and asserts:
 *   - the interview chat renders (not the quiz)
 *   - Sarah's greeting appears (agent bubble, non-empty)
 *   - typing a run-on sentence + Send produces a reply and a "Noted:" line
 *   - the recognised facts actually persisted server-side
 * Then hard-deletes the throwaway account via an artisan cleanup.
 *
 * SAFETY: throwaway account only; cleaned up in the finally. No customer data.
 * Usage: node otg1-meet-sarah-walk.cjs
 */
const puppeteer = require('puppeteer');
const { execSync } = require('child_process');
const fs = require('fs');
const sleep = ms => new Promise(r => setTimeout(r, ms));

const BASE = 'https://staging.levelupgrowth.io';
const SHOTS = '/tmp/otg1-shots';
const EMAIL = 'otg1+' + Math.random().toString(36).slice(2, 10) + '@levelupgrowth.io';
const PASSWORD = 'Xy12345678';

let pass = 0, fail = 0;
const results = [];
function check(label, ok, detail) {
  if (ok === true) { pass++; results.push(`  PASS  ${label}`); }
  else { fail++; results.push(`  FAIL  ${label}${detail ? ' -> ' + String(detail).slice(0, 200) : ''}`); }
}

(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox', '--disable-dev-shm-usage', '--ignore-certificate-errors'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 900 });
  const consoleErrors = [];
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 160)); });
  page.on('pageerror', e => consoleErrors.push('PAGEERR ' + String(e).slice(0, 160)));

  try {
    await page.goto(BASE + '/app/', { waitUntil: 'domcontentloaded', timeout: 45000 });

    // Register through the real API from inside the page, store tokens as the SPA does.
    const reg = await page.evaluate(async ([base, email, password]) => {
      const r = await fetch(base + '/api/auth/register', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ name: 'OTG1 Walk', email, password, password_confirmation: password })
      });
      const j = await r.json().catch(() => ({}));
      if (j.access_token) localStorage.setItem('lu_token', j.access_token);
      if (j.refresh_token) localStorage.setItem('lu_refresh_token', j.refresh_token);
      return { status: r.status, hasToken: !!j.access_token };
    }, [BASE, EMAIL, PASSWORD]);
    check('registration returns a token', reg.hasToken, 'HTTP ' + reg.status);

    // Boot the SPA through its own path.
    await page.goto(BASE + '/app/', { waitUntil: 'networkidle0', timeout: 45000 });
    await sleep(2500);

    // The interview mounts into #lu-auth-root with the chat form.
    const hasChat = await page.evaluate(() => !!document.getElementById('iv-form') && !!document.getElementById('iv-input'));
    check('meet-Sarah chat rendered (not the quiz)', hasChat);
    await page.screenshot({ path: SHOTS + '/1-greeting.png' }).catch(() => {});

    // Wait for Sarah's greeting bubble (agent bubble text non-empty).
    let greeting = '';
    for (let i = 0; i < 20 && !greeting; i++) {
      greeting = await page.evaluate(() => {
        const t = document.getElementById('iv-thread');
        if (!t || !t.children.length) return '';
        return (t.children[0].textContent || '').trim();
      });
      if (!greeting) await sleep(1000);
    }
    check('Sarah greets in her own voice', greeting.length > 15, greeting.slice(0, 80));

    // Type a run-on sentence and Send.
    const MSG = 'We run a boutique bakery in Seattle selling artisan bread and pastries to local cafes and walk-in customers, and we want more wholesale orders; we already have a website but no analytics set up.';
    await page.type('#iv-input', MSG);
    await page.click('#iv-send');

    // Wait for a second agent bubble (her reply to our message).
    let replied = false;
    for (let i = 0; i < 60 && !replied; i++) {
      await sleep(1000);
      replied = await page.evaluate(() => {
        const t = document.getElementById('iv-thread');
        if (!t) return false;
        // bubbles: greeting(agent), our message(user), her reply(agent) => >=3
        return t.children.length >= 3;
      });
    }
    check('Sarah replied to the message', replied);

    const noted = await page.evaluate(() => (document.getElementById('iv-progress') || {}).textContent || '');
    check('recognised facts surfaced in the UI ("Noted:")', /noted/i.test(noted), noted.slice(0, 120));
    await page.screenshot({ path: SHOTS + '/2-after-reply.png' }).catch(() => {});

    // Server-side truth: the facts persisted for this workspace.
    const persisted = execSync('php scripts/otg1-check.php ' + EMAIL, { encoding: 'utf8' }).trim();
    check('facts persisted server-side (>=4)', parseInt(persisted, 10) >= 4, persisted + ' facts');

  } catch (e) {
    check('walk completed without throwing', false, e.message);
  } finally {
    // hard cleanup of the throwaway account
    try {
      execSync('php scripts/otg1-cleanup.php ' + EMAIL, { encoding: 'utf8' });
      results.push('  cleanup: throwaway account deleted');
    } catch (e) { results.push('  cleanup FAILED: ' + e.message); }
    await browser.close();
  }

  console.log('\nOTG-1 MEET-SARAH BROWSER WALK');
  console.log(results.join('\n'));
  if (consoleErrors.length) console.log('  console errors:\n    ' + consoleErrors.slice(0, 5).join('\n    '));
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail === 0 ? 0 : 1);
})();
