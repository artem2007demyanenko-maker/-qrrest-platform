import { expectNoServerErrors, expectPageHealthy, loginAs, test, visitReadOnly } from './fixtures';
import { staffCredentials, waiterCredentials } from './config';
import { expectNoRuntimeText } from './test-helpers';

test.describe('Staff operational flow', () => {
  test('staff or waiter can open orders panel', async ({ page, diagnostics }) => {
    const credentials = waiterCredentials || staffCredentials;
    test.skip(!credentials, 'Set QRREST_WAITER_EMAIL/PASSWORD or QRREST_STAFF_EMAIL/PASSWORD to run staff smoke checks.');

    await loginAs(page, credentials!);
    const response = await visitReadOnly(page, '/staff/orders.php');

    await expectPageHealthy(page, response);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });

  test('staff or waiter can open floorplan panel', async ({ page, diagnostics }) => {
    const credentials = waiterCredentials || staffCredentials;
    test.skip(!credentials, 'Set QRREST_WAITER_EMAIL/PASSWORD or QRREST_STAFF_EMAIL/PASSWORD to run floorplan smoke checks.');

    await loginAs(page, credentials!);
    const response = await visitReadOnly(page, '/staff/floorplan.php');

    await expectPageHealthy(page, response);
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });
});
