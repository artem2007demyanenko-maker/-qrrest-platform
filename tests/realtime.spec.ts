import { expect, expectJsonResponseHealthy, expectNoServerErrors, expectPageHealthy, loginAs, test, visitReadOnly, waitForJsonPolling } from './fixtures';
import { barCredentials, coldCredentials, ownerCredentials, staffCredentials, waiterCredentials } from './config';
import { expectNoRuntimeText, skipOrFail } from './test-helpers';

test.describe('Realtime and polling smoke checks', () => {
  test('KDS polling request is observed and returns JSON', async ({ page, diagnostics }) => {
    const credentials = coldCredentials || barCredentials || ownerCredentials;
    const station = coldCredentials ? 'cold' : (barCredentials ? 'bar' : 'all');
    skipOrFail(test.skip, !credentials, 'Set station or owner credentials to run KDS polling smoke checks.');

    await loginAs(page, credentials!);
    const polling = waitForJsonPolling(page, /\/staff\/kitchen_api\.php/i).catch(() => null);
    const response = await visitReadOnly(page, `/staff/kitchen.php?station=${encodeURIComponent(station)}`);

    await expectPageHealthy(page, response);
    const pollResult = await polling;
    expect(pollResult, 'KDS polling response').not.toBeNull();
    await expectNoRuntimeText(page);
    expectNoServerErrors(diagnostics);
  });

  test('staff orders polling endpoint responds with JSON', async ({ page }) => {
    const credentials = waiterCredentials || staffCredentials || ownerCredentials;
    skipOrFail(test.skip, !credentials, 'Set staff/waiter/owner credentials to run orders polling smoke checks.');

    await loginAs(page, credentials!);
    const response = await page.request.get('/staff/orders_api.php');

    expect(response.status(), 'orders_api HTTP status').toBeLessThan(500);
    await response.json();
  });

  test('floorplan polling endpoint responds safely', async ({ page }) => {
    const credentials = waiterCredentials || staffCredentials || ownerCredentials;
    skipOrFail(test.skip, !credentials, 'Set staff/waiter/owner credentials to run floorplan polling smoke checks.');

    await loginAs(page, credentials!);
    const response = await page.request.get('/staff/floorplan_api.php');

    expect(response.status(), 'floorplan_api HTTP status').toBeLessThan(500);
    await response.json();
  });

  test('unsafe kitchen item update without POST is rejected without 500', async ({ page }) => {
    const credentials = coldCredentials || barCredentials || ownerCredentials;
    skipOrFail(test.skip, !credentials, 'Set station or owner credentials to run item update guard smoke checks.');

    await loginAs(page, credentials!);
    const response = await page.request.get('/staff/kitchen_item_update.php');

    expect(response.status(), 'GET kitchen_item_update status').toBeLessThan(500);
    expect([200, 400, 401, 403, 405]).toContain(response.status());
  });
});
