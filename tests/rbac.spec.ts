import { expect, expectNoServerErrors, expectPageHealthy, expectRestrictedOrRedirected, loginAs, test, visitReadOnly } from './fixtures';
import { barCredentials, staffCredentials, waiterCredentials } from './config';
import { expectNoRuntimeText, skipOrFail } from './test-helpers';

const protectedRoutes = [
  '/staff/orders.php',
  '/staff/kitchen.php?station=cold',
  '/staff/bar.php',
  '/restaurant/dashboard.php',
];

test.describe('RBAC smoke checks', () => {
  for (const path of protectedRoutes) {
    test(`anonymous request is redirected or restricted: ${path}`, async ({ page }) => {
      const response = await visitReadOnly(page, path);
      await expectRestrictedOrRedirected(page, response);
    });
  }

  test('waiter/staff account does not get station KDS access', async ({ page, diagnostics }) => {
    const credentials = waiterCredentials || staffCredentials;
    skipOrFail(test.skip, !credentials, 'Set QRREST_WAITER_EMAIL/PASSWORD or QRREST_STAFF_EMAIL/PASSWORD to run waiter RBAC smoke checks.');

    await loginAs(page, credentials!);
    const response = await visitReadOnly(page, '/staff/kitchen.php?station=cold');
    const bodyText = await page.locator('body').innerText({ timeout: 10_000 }).catch(() => '');

    expect(
      (response?.status() ?? 0) === 401
        || (response?.status() ?? 0) === 403
        || /access denied|доступ запрещ|нет доступа|вход/i.test(bodyText)
        || /\/login\.php/i.test(page.url()),
      `Waiter/staff should not see KDS. status=${response?.status()} url=${page.url()}`
    ).toBeTruthy();
    expectNoServerErrors(diagnostics);
  });

  test('bar account can open bar panel without generic kitchen leakage', async ({ page, diagnostics }) => {
    skipOrFail(test.skip, !barCredentials, 'Set QRREST_BAR_EMAIL/PASSWORD to run bar RBAC smoke checks.');

    await loginAs(page, barCredentials!);
    const response = await visitReadOnly(page, '/staff/bar.php');

    await expectPageHealthy(page, response);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });
});
