import { expect, expectNoServerErrors, expectPageHealthy, test, visitReadOnly } from './fixtures';
import {
  addFirstMenuItemToCart,
  deliveryMenuPath,
  expectCartVisible,
  expectNoRuntimeText,
  expectOrderTrackingReached,
  fillDeliveryCheckoutFields,
  openCart,
  skipUnlessMutation,
  skipUnlessOrderCreation,
  skipOrFail,
  submitCheckout,
} from './test-helpers';

test.describe('Delivery checkout flow', () => {
  test('delivery menu and checkout shell render safely', async ({ page, diagnostics }) => {
    const response = await visitReadOnly(page, deliveryMenuPath());

    await expectPageHealthy(page, response);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });

  test('delivery checkout validates required address/contact fields', async ({ page, diagnostics }) => {
    skipUnlessMutation(test.skip);

    await page.goto(deliveryMenuPath(), { waitUntil: 'domcontentloaded' });
    await addFirstMenuItemToCart(page);
    await openCart(page, 'delivery');
    await expectCartVisible(page);

    const deliveryRadio = page.locator('input[name="order_type"][value="delivery"]').first();
    skipOrFail(test.skip, await deliveryRadio.count() === 0, 'Delivery order type is not available for this tenant.');
    await deliveryRadio.check({ force: true }).catch(() => undefined);

    await page.locator('input[name="delivery_full_name"]').first().fill('').catch(() => undefined);
    await page.locator('input[name="delivery_phone"]').first().fill('').catch(() => undefined);
    await page.locator('textarea[name="delivery_address"]').first().fill('').catch(() => undefined);
    await submitCheckout(page);

    const bodyText = await page.locator('body').innerText({ timeout: 10_000 }).catch(() => '');
    expect(bodyText).toMatch(/адрес|телефон|имя|обязател|address|phone|required/i);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });

  test('delivery checkout can create a delivery order and reach tracking', async ({ page, diagnostics }) => {
    skipUnlessOrderCreation(test.skip);

    await page.goto(deliveryMenuPath(), { waitUntil: 'domcontentloaded' });
    await addFirstMenuItemToCart(page);
    await openCart(page, 'delivery');
    await expectCartVisible(page);
    await fillDeliveryCheckoutFields(page);
    await submitCheckout(page);

    await expectOrderTrackingReached(page);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });
});
