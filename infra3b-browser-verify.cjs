/**
 * INFRA888 Phase 3B — REAL headless-browser validation of the intelligence UI.
 *
 * Loads the same-origin harness (which runs the REAL public infrastructure.js
 * against the LIVE HTTPS API) in headless Chromium, injects a PTAA-scoped JWT at
 * runtime (from env, never logged), renders every intelligence view, and checks:
 *   - functional render + data binding against live PTAA data
 *   - zero JS/console errors, zero failed /api requests
 *   - honest labels (adopted vs provisioned, "Not yet observed", empty states)
 *   - tab navigation, asset drill-down, blast radius
 *   - accessibility landmarks (tablist, aria-selected, headings, aria-labels)
 *   - no horizontal overflow at desktop and narrow widths
 * Screenshots are written to /tmp/infra3b-shots. Read-only. Nothing is mutated.
 */
const puppeteer = require('puppeteer');
const fs = require('fs');

const EXEC = '/var/www/levelup-staging/.puppeteer-cache/chrome/linux-147.0.7727.57/chrome-linux64/chrome';
const URL = 'https://levelupgrowth.io/app/infra-verify-3b.html';
const TOKEN = process.env.PTAA_TOKEN || '';
const SHOTS = '/tmp/infra3b-shots';
fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const lines = [];
const consoleErrors = [];
const pageErrors = [];
const apiFailures = [];
function check(label, ok, detail) {
  if (ok) { pass++; lines.push('  PASS  ' + label); }
  else { fail++; lines.push('  FAIL  ' + label + (detail ? ' -> ' + String(detail).slice(0, 200) : '')); }
}

async function waitText(page, txt, ms) {
  await page.waitForFunction((t) => document.body && document.body.innerText.includes(t), { timeout: ms || 15000 }, txt);
}

(async () => {
  if (!TOKEN) { console.log('NO TOKEN'); process.exit(2); }
  const browser = await puppeteer.launch({
    executablePath: EXEC, headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => pageErrors.push(e.message));
  page.on('response', (r) => { try { const u = r.url(); if (u.includes('/api/') && r.status() >= 400) apiFailures.push(r.status() + ' ' + u); } catch (e) {} });

  // Load harness, inject token, start the real module.
  await page.goto(URL, { waitUntil: 'networkidle2', timeout: 30000 });
  await page.evaluate((t) => { localStorage.setItem('lu_token', t); }, TOKEN);
  await page.evaluate(() => window.__startInfra());

  // ---- OVERVIEW ----
  await waitText(page, 'Operational overview');
  await new Promise(r => setTimeout(r, 800)); // let secondary panels settle
  const ov = await page.evaluate(() => document.body.innerText);
  // innerText applies CSS text-transform (labels are uppercased), so normalise.
  const L = ov.replace(/\n/g, ' ').toLowerCase();
  check('overview heading renders', L.includes('operational overview'));
  check('total assets tile = 3', /total assets\s*3/.test(L), ov.match(/[Tt]otal assets\s*\S+/i));
  check('management: Adopted 2', /adopted\s*2/.test(L));
  check('management: Managed by INFRA888 1', /managed by infra888\s*1/.test(L));
  check('management: Provisioned 0 (honest)', /provisioned\s*0/.test(L));
  check('health: Not yet observed present (unknown != healthy)', L.includes('not yet observed'));
  check('reliability honest empty (no fake uptime)', L.includes('not yet computable'));
  // The ONLY "100%" on the page is the honest disclaimer that this is NOT a 100% claim.
  check('honest: explicitly disclaims 100% uptime', ov.includes('not a claim of 100% uptime'));
  await page.screenshot({ path: SHOTS + '/01-overview.png', fullPage: true });

  // Accessibility landmarks
  const a11y = await page.evaluate(() => ({
    tablist: !!document.querySelector('[role="tablist"]'),
    tabs: document.querySelectorAll('[role="tab"]').length,
    selected: document.querySelectorAll('[role="tab"][aria-selected="true"]').length,
    h2: document.querySelectorAll('h2').length,
    refreshLabel: !!document.querySelector('#infra-refresh[aria-label]')
  }));
  check('a11y: role=tablist present', a11y.tablist);
  check('a11y: 7 tabs, exactly 1 selected', a11y.tabs === 7 && a11y.selected === 1, JSON.stringify(a11y));
  check('a11y: refresh has aria-label', a11y.refreshLabel);

  // No horizontal overflow at desktop
  const ofDesktop = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  check('no horizontal overflow (desktop)', ofDesktop <= 2, 'overflow=' + ofDesktop);

  // ---- ASSETS ----
  await page.click('[data-infra-tab="assets"]');
  await waitText(page, 'Asset inventory');
  await new Promise(r => setTimeout(r, 500));
  const rows = await page.evaluate(() => document.querySelectorAll('.infra-open-asset').length);
  check('assets: 3 rows render', rows === 3, 'rows=' + rows);
  const assetsText = await page.evaluate(() => document.body.innerText);
  check('assets: shows Adopted mode tag', assetsText.includes('Adopted'));
  check('assets: shows Managed by INFRA888 tag', assetsText.includes('Managed by INFRA888'));
  await page.screenshot({ path: SHOTS + '/02-assets.png', fullPage: true });

  // ---- ASSET DRILL-DOWN ----
  await page.click('.infra-open-asset');
  await waitText(page, 'Relationships');
  const detail = await page.evaluate(() => document.body.innerText);
  check('drill-down: shows Relationships', detail.includes('Relationships'));
  check('drill-down: shows Incident history', detail.includes('Incident history'));
  check('drill-down: shows Management mode', detail.includes('Management mode'));
  // blast radius
  await page.click('#infra-blast-btn');
  await new Promise(r => setTimeout(r, 1200));
  const afterBlast = await page.evaluate(() => document.body.innerText);
  check('blast radius: renders result', afterBlast.includes('Blast radius') || afterBlast.includes('dependent'));
  await page.screenshot({ path: SHOTS + '/03-asset-detail.png', fullPage: true });

  // ---- INCIDENTS ----
  await page.click('[data-infra-tab="incidents"]');
  await waitText(page, 'Incidents');
  await new Promise(r => setTimeout(r, 400));
  const inc = await page.evaluate(() => document.body.innerText);
  check('incidents: honest empty (no incident recorded)', inc.includes('No incidents recorded') || inc.includes('No open incidents') || inc.includes('Absence of an incident'));
  await page.screenshot({ path: SHOTS + '/04-incidents.png', fullPage: true });

  // ---- RELIABILITY ----
  await page.click('[data-infra-tab="reliability"]');
  await waitText(page, 'Reliability');
  await new Promise(r => setTimeout(r, 400));
  const rel = await page.evaluate(() => document.body.innerText);
  check('reliability: honest (not computable / not prediction)', rel.includes('not yet computable') || rel.includes('not predictions'));
  await page.screenshot({ path: SHOTS + '/05-reliability.png', fullPage: true });

  // ---- NARROW VIEWPORT (responsive) ----
  await page.setViewport({ width: 390, height: 844 });
  await page.click('[data-infra-tab="overview"]');
  await waitText(page, 'Operational overview');
  await new Promise(r => setTimeout(r, 600));
  const ofNarrow = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  check('no horizontal overflow (390px)', ofNarrow <= 2, 'overflow=' + ofNarrow);
  await page.screenshot({ path: SHOTS + '/06-overview-narrow.png', fullPage: true });

  check('no console errors', consoleErrors.length === 0, consoleErrors.join(' | '));
  check('no uncaught page errors', pageErrors.length === 0, pageErrors.join(' | '));
  check('no failed /api requests', apiFailures.length === 0, apiFailures.join(' | '));

  await browser.close();

  console.log(lines.join('\n'));
  console.log('\nconsole errors: ' + consoleErrors.length + (consoleErrors.length ? '\n  ' + consoleErrors.join('\n  ') : ''));
  console.log('api failures: ' + apiFailures.length + (apiFailures.length ? '\n  ' + apiFailures.join('\n  ') : ''));
  console.log('\nscreenshots: ' + fs.readdirSync(SHOTS).join(', '));
  console.log('\n==================== BROWSER VALIDATION: ' + pass + ' passed, ' + fail + ' failed ====================');
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('FATAL', e.message); process.exit(3); });
