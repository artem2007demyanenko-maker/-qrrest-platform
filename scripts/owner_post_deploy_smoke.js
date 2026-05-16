#!/usr/bin/env node
/**
 * Owner panel post-deploy smoke (desktop + mobile) using Playwright.
 *
 * Goals:
 * - Validate warnings/fallback blocks for partial schema.
 * - Validate POST actions are read-only / disabled when schema missing.
 * - Validate mobile drawer + cookie banner/fab overlays don't break UX.
 * - Fail fast on console/page errors.
 *
 * Usage:
 *   node scripts/owner_post_deploy_smoke.js --base-url https://example.com
 *   node scripts/owner_post_deploy_smoke.js --base-url https://example.com --expect-warnings dashboard,crm,...
 *
 * Requires:
 *   npm i @playwright/test playwright (or playwright installed globally)
 */

const { chromium } = require('playwright');

function arg(name, fallback = '') {
  const idx = process.argv.indexOf(`--${name}`);
  if (idx === -1) return fallback;
  return process.argv[idx + 1] ?? fallback;
}

function argList(name) {
  const v = arg(name, '');
  if (!v) return [];
  return v
    .split(',')
    .map(s => s.trim())
    .filter(Boolean);
}

function uniq(arr) {
  return Array.from(new Set(arr));
}

function overlapArea(a, b) {
  // a/b: {x,y,width,height}
  const x1 = Math.max(a.x, b.x);
  const y1 = Math.max(a.y, b.y);
  const x2 = Math.min(a.x + a.width, b.x + b.width);
  const y2 = Math.min(a.y + a.height, b.y + b.height);
  const w = x2 - x1;
  const h = y2 - y1;
  if (w <= 0 || h <= 0) return 0;
  return w * h;
}

async function requireVisibleText(page, expectedSubstrings, whereLabel) {
  if (!expectedSubstrings || expectedSubstrings.length === 0) return true;
  const text = await page.locator('body').innerText();
  for (const s of expectedSubstrings) {
    if (!text.includes(s)) {
      throw new Error(`Expected substring missing (${whereLabel}): "${s}"`);
    }
  }
  return true;
}

async function verifyDisabledButtonsByTexts(page, texts, whereLabel) {
  if (!texts || texts.length === 0) return;
  for (const t of texts) {
    const btn = page.locator('button[type="submit"], button').filter({ hasText: t }).first();
    if (!(await btn.count())) {
      throw new Error(`Disabled check: button with text not found (${whereLabel}): ${t}`);
    }
    const disabled = await btn.evaluate(el => !!el.disabled);
    if (!disabled) {
      throw new Error(`Disabled check failed (${whereLabel}): expected disabled button "${t}"`);
    }
  }
}

async function verifyCrmPostBlocked(page) {
  // Click first submit button in a POST form and expect read-only error text.
  const readOnlySubstring = 'read-only';
  const bodyText = async () => await page.locator('body').innerText();

  // Trigger: find any POST form submit.
  const submit = page.locator('form[method="post"] button[type="submit"], form[method="post"] button').first();
  if (!(await submit.count())) {
    // Still succeed: page might be fully read-only but without explicit submit buttons.
    return;
  }

  await submit.click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1000);
  const text = await bodyText();

  // The Russian message contains "read-only" English prefix in our code? It's "read-only режиме" but safe to look for English.
  const expectedHints = [
    'read-only',
    'Примените миграции',
    'CRM-возврат гостей доступен',
  ];
  const ok = expectedHints.some(h => text.includes(h));
  if (!ok) {
    throw new Error(`CRM POST read-only check failed: none of expected hints found. Expected one of: ${expectedHints.join(' | ')}`);
  }
}

async function getRectSafely(locator) {
  const box = await locator.boundingBox().catch(() => null);
  return box;
}

async function verifyMobileOverlaps(page) {
  // Cookie banner and a "critical" button (if present).
  const banner = page.locator('#qr-cookie-banner');
  const fab = page.locator('#qr-cookie-settings-fab');

  // Candidate critical buttons:
  // - Loyalty save: button with text "Сохранить"
  // - CRM campaigns: "Добавить шаблон" or "Создать кампанию"
  const critical = page.locator('button').filter({ hasText: 'Сохранить' }).first();
  const critical2 = page.locator('button').filter({ hasText: 'Добавить шаблон' }).first();
  const critical3 = page.locator('button').filter({ hasText: 'Создать кампанию' }).first();

  const candidates = [];
  for (const c of [critical, critical2, critical3]) {
    if (await c.count()) candidates.push(c);
  }
  if (candidates.length === 0) return; // page doesn't have the critical elements we can check.

  const criticalRect = await getRectSafely(candidates[0]);
  const bannerRect = await getRectSafely(banner);
  const fabRect = await getRectSafely(fab);

  // If banner is hidden, overlap check is irrelevant.
  if (!bannerRect && !fabRect) return;

  const checkAgainst = [];
  if (bannerRect) checkAgainst.push(bannerRect);
  if (fabRect) checkAgainst.push(fabRect);

  for (const r of checkAgainst) {
    const area = overlapArea(criticalRect, r);
    const critArea = criticalRect.width * criticalRect.height;
    if (critArea > 0 && area / critArea > 0.15) {
      throw new Error(`Mobile overlap detected: cookie UI overlaps critical button too much (overlapRatio=${area / critArea})`);
    }
  }
}

async function openAndSmoke(page, url, pageKey, expectWarnings) {
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle').catch(() => {});

  // Ensure no fatal errors.
  await page.waitForTimeout(300);

  if (expectWarnings.has(pageKey)) {
    const expected = {
      dashboard: ['Данные ограничены:', 'Данные ограничены'],
      crm: ['Schema missing', 'read-only', 'Примените миграции'],
      loyalty_settings: ['restaurant_loyalty_settings', 'отсутствует'],
      crm_campaigns: ['Schema missing', 'Schema'],
      revenue: ['Данные ограничены:', 'Данные ограничены'],
    }[pageKey] || [];
    await requireVisibleText(page, expected.slice(0, 3), `warning-${pageKey}`);
  }

  // Read-only / disabled checks for specific pages.
  if (pageKey === 'loyalty_settings' && expectWarnings.has(pageKey)) {
    await verifyDisabledButtonsByTexts(page, ['Сохранить'], 'loyalty_settings');
  }

  if (pageKey === 'crm_campaigns' && expectWarnings.has(pageKey)) {
    await verifyDisabledButtonsByTexts(page, ['Добавить шаблон', 'Создать кампанию'], 'crm_campaigns');
  }

  if (pageKey === 'crm' && expectWarnings.has(pageKey)) {
    await verifyCrmPostBlocked(page);
  }

  // Mobile: drawer + cookie overlay + scroll stability.
  if (page.viewportSize().width <= 480) {
    await verifyMobileOverlaps(page);

    // Drawer check: if ids exist.
    const openBtn = page.locator('#dash-nav-open');
    if (await openBtn.count()) {
      await openBtn.click().catch(() => {});
      await page.waitForTimeout(400);
      const overlay = page.locator('#dash-nav-overlay');
      const overlayHidden = await overlay.getAttribute('class');
      if (overlayHidden && overlayHidden.includes('hidden')) {
        throw new Error('Mobile drawer overlay should be visible but still has "hidden" class');
      }
      const closeBtn = page.locator('#dash-nav-close');
      if (await closeBtn.count()) await closeBtn.click().catch(() => {});
      const overlayClassAfter = await overlay.getAttribute('class');
      if (overlayClassAfter && !overlayClassAfter.includes('hidden')) {
        throw new Error('Mobile drawer overlay did not close correctly');
      }
    }

    // Scroll stability: should scroll and not be frozen.
    const yBefore = await page.evaluate(() => window.scrollY);
    await page.mouse.wheel(0, 500).catch(() => {});
    await page.waitForTimeout(300);
    const yAfter = await page.evaluate(() => window.scrollY);
    if (yAfter <= yBefore) {
      throw new Error('Mobile scroll appears frozen (scrollY did not change)');
    }
  }
}

async function run() {
  const baseUrl = arg('base-url');
  if (!baseUrl) {
    console.error('Missing --base-url https://...');
    process.exit(2);
  }

  const pages = [
    ['dashboard', '/restaurant/dashboard.php'],
    ['crm', '/restaurant/crm.php'],
    ['loyalty_settings', '/restaurant/loyalty_settings.php'],
    ['crm_campaigns', '/restaurant/crm_campaigns.php'],
    ['revenue', '/restaurant/revenue.php'],
  ];

  const expectWarningsList = argList('expect-warnings');
  const expectWarnings = new Set(uniq(expectWarningsList));
  const expectedAny = expectWarnings.size > 0;

  const execPages = pages; // smoke all pages always

  const browser = await chromium.launch();
  const errors = [];
  const pagesFailed = [];

  // 1) Desktop
  {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await context.newPage();

    page.on('console', msg => {
      if (msg.type() === 'error') errors.push(`console.error: ${msg.text()}`);
    });
    page.on('pageerror', err => {
      errors.push(`pageerror: ${err.message}`);
    });

    for (const [key, p] of execPages) {
      const url = rtrim(baseUrl) + p;
      try {
        await openAndSmoke(page, url, key, expectWarnings);
      } catch (e) {
        pagesFailed.push(`desktop:${key}:${e.message}`);
      }
    }
    await context.close();
  }

  // 2) Mobile (fresh storage to exercise cookie banner state)
  {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    page.on('console', msg => {
      if (msg.type() === 'error') errors.push(`mobile console.error: ${msg.text()}`);
    });
    page.on('pageerror', err => {
      errors.push(`mobile pageerror: ${err.message}`);
    });

    for (const [key, p] of execPages) {
      const url = rtrim(baseUrl) + p;
      try {
        await openAndSmoke(page, url, key, expectWarnings);
      } catch (e) {
        pagesFailed.push(`mobile:${key}:${e.message}`);
      }
    }
    await context.close();
  }

  await browser.close();

  // Summary + exit code.
  if (errors.length > 0) {
    console.error('FAIL owner-smoke: runtime errors detected');
    for (const e of errors) console.error(' - ' + e);
  }
  if (pagesFailed.length > 0) {
    console.error('FAIL owner-smoke: page checks failed');
    for (const f of pagesFailed) console.error(' - ' + f);
  }
  if (errors.length === 0 && pagesFailed.length === 0) {
    console.log(`PASS owner-smoke${expectedAny ? ` (expectWarnings=${Array.from(expectWarnings).join(',')})` : ''}`);
    process.exit(0);
  } else {
    console.error('owner-smoke finished with failures');
    process.exit(1);
  }
}

function rtrim(s) {
  return String(s || '').replace(/\/+$/, '');
}

run().catch(e => {
  console.error('FAIL owner-smoke: unexpected error', e);
  process.exit(1);
});

