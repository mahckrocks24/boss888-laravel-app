// BOSS888 Mobile Regression Suite
// Run: npx playwright test tests/mobile/responsive.spec.cjs
const { test, expect } = require('@playwright/test');
const fs = require('fs');

const BASE = 'https://staging.levelupgrowth.io';

const VIEWPORTS = [
  { name: 'iPhone-SE',  width: 375,  height: 667  },
  { name: 'iPhone-14',  width: 390,  height: 844  },
  { name: 'Android',    width: 412,  height: 915  },
  { name: 'iPad',       width: 768,  height: 1024 },
  { name: 'Desktop',    width: 1440, height: 900  },
];

const ROUTES = [
  { path: '/',     name: 'marketing-home' },
  { path: '/app/', name: 'app-login'      },
];

// Test 1 — no horizontal scroll on any viewport
VIEWPORTS.forEach(vp => {
  ROUTES.forEach(route => {
    test(`[${vp.name}] ${route.name} — no horizontal scroll`, async ({ page }) => {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto(BASE + route.path, { waitUntil: 'networkidle' });
      const overflow = await page.evaluate(() =>
        document.body.scrollWidth > window.innerWidth
      );
      expect(overflow, `Horizontal scroll on ${vp.name} at ${route.path}`).toBe(false);
    });
  });
});

// Test 2 — hamburger visible on mobile, hidden on desktop
test('Hamburger: visible on mobile, hidden on desktop', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 667 });
  await page.goto(BASE + '/app/', { waitUntil: 'networkidle' });
  const hamburgerMobile = await page.locator('#lu-hamburger').isVisible().catch(() => false);

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.reload({ waitUntil: 'networkidle' });
  const hamburgerDesktop = await page.locator('#lu-hamburger').isVisible().catch(() => true);

  expect(hamburgerMobile).toBe(true);
  expect(hamburgerDesktop).toBe(false);
});

// Test 3 — marketing viewport meta present
test('Marketing: viewport meta present', async ({ page }) => {
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const viewport = await page.locator('meta[name="viewport"]').getAttribute('content');
  expect(viewport).toContain('width=device-width');
});

// Test 4 — no font-size below 12px on mobile viewports
VIEWPORTS.slice(0, 3).forEach(vp => {
  test(`[${vp.name}] no font-size below 12px`, async ({ page }) => {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.goto(BASE + '/app/', { waitUntil: 'networkidle' });
    const tinyText = await page.evaluate(() => {
      const violations = [];
      document.querySelectorAll('*').forEach(el => {
        const size = parseFloat(window.getComputedStyle(el).fontSize);
        if (size > 0 && size < 12 && el.textContent.trim().length > 0) {
          violations.push({ tag: el.tagName, class: el.className, size });
        }
      });
      return violations.slice(0, 5);
    });
    expect(tinyText.length, JSON.stringify(tinyText)).toBe(0);
  });
});

// Test 5 — screenshots for visual diff
test('Screenshots: all viewports', async ({ page }) => {
  const dir = '/var/www/levelup-staging/tests/mobile/screenshots';
  fs.mkdirSync(dir, { recursive: true });
  for (const vp of VIEWPORTS) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.goto(BASE + '/app/', { waitUntil: 'networkidle' });
    await page.screenshot({
      path: `${dir}/${vp.name}-login.png`,
      fullPage: false,
    });
  }
});
