import { expectNoServerErrors, expectPageHealthy, loginAs, test, visitReadOnly } from './fixtures';
import { ownerCredentials, staffCredentials, waiterCredentials } from './config';
import { expectNoRuntimeText } from './test-helpers';

test.describe('Loyalty operational smoke checks', () => {
  test('staff loyalty lookup page renders safely', async ({ page, diagnostics }) => {
    const credentials = waiterCredentials || staffCredentials || ownerCredentials;
    test.skip(!credentials, 'Set staff/waiter/owner credentials to run loyalty staff smoke checks.');

    await loginAs(page, credentials!);
    const response = await visitReadOnly(page, '/staff/loyalty.php');

    await expectPageHealthy(page, response);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });

  test('restaurant loyalty settings page renders safely', async ({ page, diagnostics }) => {
    test.skip(!ownerCredentials, 'Set QRREST_OWNER_EMAIL/PASSWORD or QRREST_ADMIN_EMAIL/PASSWORD to run loyalty owner smoke checks.');

    await loginAs(page, ownerCredentials!);
    const response = await visitReadOnly(page, '/restaurant/loyalty_settings.php');

    await expectPageHealthy(page, response);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });
});
