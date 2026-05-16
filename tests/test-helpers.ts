import type { Locator, Page, Response } from 'playwright/test';
import { expect } from './fixtures';
import {
  ORDER_CREATION_ENABLED,
  MUTATION_ENABLED,
  TEST_TABLE_ID,
  testGuest,
} from './config';

export function qrMenuPath(params: Record<string, string | number | undefined> = {}): string {
  const search = new URLSearchParams();
  const tableId = params.table_id ?? TEST_TABLE_ID;
  if (tableId !== undefined && tableId !== '') {
    search.set('table_id', String(tableId));
  }
  for (const [key, value] of Object.entries(params)) {
    if (key === 'table_id' || value === undefined || value === '') {
      continue;
    }
    search.set(key, String(value));
  }
  const qs = search.toString();
  return `/qr.php${qs ? `?${qs}` : ''}`;
}

export function deliveryMenuPath(params: Record<string, string | number | undefined> = {}): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '') {
      search.set(key, String(value));
    }
  }
  const qs = search.toString();
  return `/qr.php${qs ? `?${qs}` : ''}`;
}

export async function firstVisible(locator: Locator): Promise<Locator | null> {
  const count = await locator.count();
  for (let index = 0; index < count; index += 1) {
    const candidate = locator.nth(index);
    if (await candidate.isVisible().catch(() => false)) {
      return candidate;
    }
  }
  return null;
}

export async function addFirstMenuItemToCart(page: Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded');
  const addButton = await firstVisible(page.locator([
    'form.js-add-to-cart-form button[name="add_item"]',
    'form.js-add-to-cart-form button[type="submit"]',
    'button[name="add_item"]',
    'button:has-text("В корзину")',
    'button:has-text("Добавить")',
  ].join(', ')));
  expect(addButton, 'visible add-to-cart button').not.toBeNull();

  await Promise.all([
    page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => undefined),
    addButton!.click(),
  ]);
  await closeOptionalOverlays(page);
}

export async function openCart(page: Page, mode: 'hall' | 'delivery' = 'hall'): Promise<Response | null> {
  const link = await firstVisible(page.locator('#mini-cart-button, #floating-cart-button, a[href*="view=cart"]'));
  if (link) {
    await Promise.all([
      page.waitForLoadState('domcontentloaded').catch(() => undefined),
      link.click(),
    ]);
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => undefined);
    return null;
  }

  const path = mode === 'delivery'
    ? deliveryMenuPath({ view: 'cart' })
    : qrMenuPath({ view: 'cart' });
  return page.goto(path, { waitUntil: 'domcontentloaded' });
}

export async function expectCartVisible(page: Page): Promise<void> {
  await page.locator('#cart-form, #cart-block').first().waitFor({ state: 'attached', timeout: 10_000 });
  const cartFormVisible = await page.locator('#cart-form').isVisible().catch(() => false);
  const cartBlockVisible = await page.locator('#cart-block').isVisible().catch(() => false);
  expect(cartFormVisible || cartBlockVisible, 'cart form or cart block is visible').toBeTruthy();
}

export async function fillDeliveryCheckoutFields(page: Page): Promise<void> {
  const deliveryRadio = page.locator('input[name="order_type"][value="delivery"]').first();
  if (await deliveryRadio.count()) {
    await deliveryRadio.check({ force: true }).catch(() => undefined);
  }

  await fillIfPresent(page, 'input[name="delivery_full_name"]', testGuest.name);
  await fillIfPresent(page, 'input[name="delivery_phone"]', testGuest.phone);
  await fillIfPresent(page, 'textarea[name="delivery_address"]', testGuest.address);
  await fillIfPresent(page, 'textarea[name="comment"], textarea[name="order_comment"]', testGuest.comment);
}

export async function submitCheckout(page: Page): Promise<void> {
  const checkoutButton = page.locator('#cart-form button[name="checkout"], button[name="checkout"]').first();
  await expect(checkoutButton, 'checkout submit button').toBeVisible({ timeout: 10_000 });
  await Promise.all([
    page.waitForLoadState('domcontentloaded').catch(() => undefined),
    checkoutButton.click(),
  ]);
  await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => undefined);
}

export async function expectOrderTrackingReached(page: Page): Promise<void> {
  const bodyText = await page.locator('body').innerText({ timeout: 10_000 }).catch(() => '');
  expect(
    /order_track\.php/i.test(page.url()) || /заказ|статус|order/i.test(bodyText),
    `Expected order tracking or order success page. url=${page.url()}`
  ).toBeTruthy();
}

export async function expectNoRuntimeText(page: Page): Promise<void> {
  const bodyText = await page.locator('body').innerText({ timeout: 10_000 }).catch(() => '');
  expect(bodyText).not.toMatch(/Fatal error|Parse error|SQLSTATE|Undefined array key|Undefined index|Call to undefined function/i);
}

export function skipUnlessMutation(testSkip: (condition: boolean, description: string) => void): void {
  testSkip(!MUTATION_ENABLED, 'Set QRREST_E2E_MUTATION=1 to run cart-mutating smoke checks.');
}

export function skipUnlessOrderCreation(testSkip: (condition: boolean, description: string) => void): void {
  testSkip(!ORDER_CREATION_ENABLED, 'Set QRREST_E2E_CREATE_ORDER=1 to create a real smoke-test order.');
}

async function fillIfPresent(page: Page, selector: string, value: string): Promise<void> {
  const field = page.locator(selector).first();
  if (await field.count()) {
    await field.fill(value).catch(() => undefined);
  }
}

async function closeOptionalOverlays(page: Page): Promise<void> {
  const closers = [
    '[data-upsell-close]',
    '[data-modal-close]',
    'button:has-text("Продолжить")',
    'button:has-text("Закрыть")',
    'button:has-text("Нет, спасибо")',
  ];
  for (const selector of closers) {
    const button = page.locator(selector).first();
    if (await button.isVisible().catch(() => false)) {
      await button.click().catch(() => undefined);
    }
  }
}
