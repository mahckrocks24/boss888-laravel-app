/**
 * INFRA888 — Phase 1C browser verification with a REAL authenticated session.
 *
 * AUTHENTICATION METHOD (directive 1C §2)
 * ---------------------------------------
 * Calls the real POST /api/auth/login endpoint from inside the page, then stores
 * BOTH tokens under the exact keys the SPA uses (`lu_token`, `lu_refresh_token`)
 * and reloads so the application boots through its own normal path.
 *
 * This is not a fabricated token. Phase 1B's failure is now understood and fixed:
 * core.js:5613 does `if (!refreshToken) { _renderLogin(); return; }` — only
 * `lu_token` was set, so the SPA correctly rendered the login screen and never
 * called _appEnterDashboard(). The refresh token was the missing piece.
 *
 * Credentials belong to a dedicated staging-only account
 * (infra888-test@levelupgrowth.io). No real customer account is used.
 *
 * SAFETY: read-only browsing. Null connectors only.
 *
 * Usage: node scripts/infra888-browser-verify.cjs <email> <password>
 */
const { chromium } = require('playwright');
const fs = require('fs');

const [EMAIL, PASSWORD] = process.argv.slice(2);
const BASE = 'https://staging.levelupgrowth.io';
const SHOTS = '/tmp/infra888-shots';

let pass = 0, fail = 0;
const results = [];
const consoleErrors = [];
const rejections = [];
const apiFailures = [];

function check(label, ok, detail) {
  if (ok === true) { pass++; results.push(`  PASS  ${label}`); }
  else { fail++; results.push(`  FAIL  ${label}${detail ? ' -> ' + String(detail).slice(0, 160) : ''}`); }
}

(async () => {
  if (!EMAIL || !PASSWORD) { console.error('usage: node script <email> <password>'); process.exit(2); }
  fs.mkdirSync(SHOTS, { recursive: true });

  const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();

  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200)); });
  page.on('pageerror', e => rejections.push(String(e).slice(0, 200)));
  page.on('response', r => {
    if (r.url().includes('/api/') && r.status() >= 400) {
      apiFailures.push(`HTTP ${r.status()} ${r.url().split('/api/')[1]}`);
    }
  });

  try {
    await page.goto(BASE + '/app/', { waitUntil: 'domcontentloaded', timeout: 45000 });

    // ---- REAL LOGIN -------------------------------------------------------
    const login = await page.evaluate(async ([base, email, password]) => {
      const r = await fetch(base + '/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ email, password }),
      });
      const d = await r.json();
      if (r.ok && d.access_token) {
        localStorage.setItem('lu_token', d.access_token);
        localStorage.setItem('lu_refresh_token', d.refresh_token);
        if (d.user) localStorage.setItem('lu_user', JSON.stringify(d.user));
      }
      return { status: r.status, hasAccess: !!d.access_token, hasRefresh: !!d.refresh_token, ws: d.workspace?.id ?? null };
    }, [BASE, EMAIL, PASSWORD]);

    check('real login endpoint returns 200 with both tokens',
      login.status === 200 && login.hasAccess && login.hasRefresh, JSON.stringify(login));

    // Clear pre-login noise, then boot the app through its own path.
    consoleErrors.length = 0; apiFailures.length = 0; rejections.length = 0;

    await page.reload({ waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForTimeout(5000);

    const shell = await page.evaluate(() => getComputedStyle(document.querySelector('.app')).display);
    check('SPA boots into the dashboard on its own (no forced entry)', shell === 'flex', 'app display=' + shell);
    await page.screenshot({ path: `${SHOTS}/1c-00-dashboard.png` });

    // ---- navigation --------------------------------------------------------
    const nav = page.locator('#ni-infrastructure');
    check('Infrastructure nav item exists', await nav.count() === 1);
    check('Infrastructure nav item is VISIBLE to an entitled user',
      await nav.isVisible().catch(() => false));

    const order = await page.evaluate(() => [...document.querySelectorAll('.nav-section, .nav-item')]
      .map(n => (n.className.includes('nav-section') ? 'S:' : 'I:') + n.textContent.trim().split('\n')[0]));
    const iE = order.indexOf('S:Engines'), iI = order.indexOf('S:Infrastructure'), iD = order.indexOf('S:Data');
    check('Infrastructure sits below Engines and above Data', iE >= 0 && iI > iE && iD > iI, `E${iE} I${iI} D${iD}`);

    // ---- open + engine load -----------------------------------------------
    await nav.click();
    await page.waitForTimeout(4000);

    check('infrastructure.js was loaded',
      await page.evaluate(() => typeof infraLoad === 'function'));
    check('Infrastructure view panel is visible',
      await page.locator('#view-infrastructure').isVisible().catch(() => false));

    const body = await page.locator('#infrastructure-root').innerText().catch(() => '');
    check('Infrastructure overview renders', /Infrastructure/.test(body) && /Hosting services/i.test(body));
    check('honest "no provider connected" notice shown', /no infrastructure provider is connected/i.test(body));
    await page.screenshot({ path: `${SHOTS}/1c-01-hosting.png` });

    const tabs = await page.locator('[data-infra-tab]').allTextContents();
    check('three tabs render', tabs.length === 3, JSON.stringify(tabs));
    check('Hosting empty state renders', /No hosting services yet/i.test(body));

    await page.locator('[data-infra-tab="domains"]').click();
    await page.waitForTimeout(1800);
    const dText = await page.locator('#infrastructure-root').innerText();
    check('Domains tab renders its empty state', /No domains yet/i.test(dText));
    await page.screenshot({ path: `${SHOTS}/1c-02-domains.png` });

    await page.locator('[data-infra-tab="email"]').click();
    await page.waitForTimeout(1800);
    const eText = await page.locator('#infrastructure-root').innerText();
    check('Email Accounts tab renders its empty state', /No email accounts yet/i.test(eText));
    await page.screenshot({ path: `${SHOTS}/1c-03-email.png` });

    // ---- honesty -----------------------------------------------------------
    const all = body + dText + eText;
    check('no fabricated metrics / IP / DNS / SSL shown',
      !/(\d+\.\d+\.\d+\.\d+|SSL: Active|99\.9%|ns\d\.|Active since)/i.test(all));
    check('no customer-specific wording', !/(PTAA|AMG|amgtravel|chef ?red|markraymundo)/i.test(all));

    // ---- existing nav ------------------------------------------------------
    await page.locator('#ni-crm').click().catch(() => {});
    await page.waitForTimeout(2500);
    check('existing navigation still works (CRM)', await page.locator('#view-crm').isVisible().catch(() => false));

    // ---- routing -----------------------------------------------------------
    await page.goto(BASE + '/app/#infrastructure', { waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForTimeout(4500);
    check('direct route entry opens Infrastructure',
      await page.locator('#view-infrastructure').isVisible().catch(() => false));

    await page.reload({ waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForTimeout(4500);
    check('refresh restores Infrastructure',
      await page.locator('#view-infrastructure').isVisible().catch(() => false));

    await page.locator('#ni-crm').click().catch(() => {});
    await page.waitForTimeout(2000);
    await page.goBack(); await page.waitForTimeout(2500);
    check('browser back returns to Infrastructure',
      await page.locator('#view-infrastructure').isVisible().catch(() => false));
    await page.goForward(); await page.waitForTimeout(2000);
    check('browser forward works', await page.locator('#view-crm').isVisible().catch(() => false));

    // ---- responsive --------------------------------------------------------
    await page.goto(BASE + '/app/#infrastructure', { waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForTimeout(3500);
    for (const [w, h, name] of [[1440, 900, 'desktop'], [1024, 768, 'laptop'], [390, 844, 'mobile']]) {
      await page.setViewportSize({ width: w, height: h });
      await page.waitForTimeout(1500);
      const overflow = await page.evaluate(() =>
        document.documentElement.scrollWidth > document.documentElement.clientWidth + 2);
      check(`no horizontal overflow at ${w}px (${name})`, overflow === false);
      await page.screenshot({ path: `${SHOTS}/1c-04-${name}.png` });
    }

    // ---- console health ----------------------------------------------------
    const errs = consoleErrors.filter(e => !/favicon/i.test(e));
    check('no console errors after login', errs.length === 0, errs.slice(0, 3).join(' | '));
    check('no unhandled promise rejections', rejections.length === 0, rejections.slice(0, 2).join(' | '));
    const infraFails = apiFailures.filter(f => /infrastructure/.test(f));
    check('no Infrastructure API call returned 4xx/5xx', infraFails.length === 0, infraFails.slice(0, 4).join(' | '));
    if (apiFailures.length) results.push(`  NOTE  other API non-2xx (pre-existing): ${[...new Set(apiFailures)].slice(0,6).join(' | ')}`);

  } catch (e) {
    check('browser run completed without fatal error', false, e.message);
  } finally {
    await browser.close();
  }

  console.log('INFRA888 Phase 1C browser verification (real login)');
  console.log('===================================================');
  console.log(results.join('\n'));
  console.log(`\nScreenshots: ${SHOTS}`);
  console.log(`\nPASS: ${pass}   FAIL: ${fail}`);
  process.exit(fail === 0 ? 0 : 1);
})();
