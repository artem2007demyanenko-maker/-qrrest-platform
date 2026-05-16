import { expectNoServerErrors, expectPageHealthy, test, visitReadOnly } from './fixtures';
import {
  TEST_TABLE_ID,
} from './config';
import {
  addFirstMenuItemToCart,
  expectCartVisible,
  expectNoRuntimeText,
  expectOrderTrackingReached,
  openCart,
  qrMenuPath,
  skipUnlessMutation,
  skipUnlessOrderCreation,
  submitCheckout,
} from './test-helpers';

test.describe('Guest QR flow', () => {
  test('guest QR menu renders without runtime errors', async ({ page, diagnostics }) => {
    const response = await visitReadOnly(page, qrMenuPath());

    await expectPageHealthy(page, response);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });

  test('guest can add an item and see the cart shell', async ({ page, diagnostics }) => {
    skipUnlessMutation(test.skip);

    await page.goto(qrMenuPath(), { waitUntil: 'domcontentloaded' });
    await addFirstMenuItemToCart(page);
    await openCart(page, TEST_TABLE_ID ? 'hall' : 'delivery');

    await expectCartVisible(page);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });

  test('guest checkout can create a hall order and reach order tracking', async ({ page, diagnostics }) => {
    skipUnlessOrderCreation(test.skip);
    test.skip(!TEST_TABLE_ID, 'Set QRREST_E2E_TABLE_ID to create a table-based hall smoke order.');

    await page.goto(qrMenuPath(), { waitUntil: 'domcontentloaded' });
    await addFirstMenuItemToCart(page);
    await openCart(page, 'hall');
    await expectCartVisible(page);
    await submitCheckout(page);

    await expectOrderTrackingReached(page);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });
});
