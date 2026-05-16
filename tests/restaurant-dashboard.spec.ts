import { expect, expectNoServerErrors, expectPageHealthy, loginAs, test, visitReadOnly } from './fixtures';
import { ownerCredentials } from './config';
import { expectNoRuntimeText, skipOrFail } from './test-helpers';

const restaurantRoutes = [
  { name: 'dashboard analytics', path: '/restaurant/dashboard.php' },
  { name: 'CRM guests', path: '/restaurant/guests.php' },
  { name: 'CRM overview', path: '/restaurant/crm.php' },
  { name: 'loyalty settings', path: '/restaurant/loyalty_settings.php' },
  { name: 'menu management', path: '/restaurant/menu_manage.php' },
];

test.describe('Restaurant dashboard flow', () => {
  for (const route of restaurantRoutes) {
    test(`owner/admin can open ${route.name}`, async ({ page, diagnostics }) => {
      skipOrFail(test.skip, !ownerCredentials, 'Set QRREST_OWNER_EMAIL/PASSWORD or QRREST_ADMIN_EMAIL/PASSWORD to run restaurant dashboard smoke checks.');

      await loginAs(page, ownerCredentials!);
      const response = await visitReadOnly(page, route.path);

      await expectPageHealthy(page, response);
      await expectNoRuntimeText(page);
      expectNoServerErrors(diagnostics);
    });
  }

  test('analytics summary endpoint returns valid JSON for dashboard', async ({ page }) => {
    skipOrFail(test.skip, !ownerCredentials, 'Set QRREST_OWNER_EMAIL/PASSWORD or QRREST_ADMIN_EMAIL/PASSWORD to run analytics endpoint smoke checks.');

    await loginAs(page, ownerCredentials!);
    const response = await page.request.get('/ajax/analytics_summary.php?range=today');

    expect(response.status(), 'analytics_summary HTTP status').toBeLessThan(500);
    await response.json();
  });
});
