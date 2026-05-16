import { expect, expectJsonResponseHealthy, expectNoServerErrors, expectPageHealthy, loginAs, test, visitReadOnly, waitForJsonPolling } from './fixtures';
import { barCredentials, coldCredentials, dessertCredentials, ownerCredentials } from './config';
import { expectNoRuntimeText } from './test-helpers';

type StationName = 'cold' | 'bar' | 'dessert';

const stationCredentials: Record<StationName, ReturnType<typeof credentialsForStation>> = {
  cold: coldCredentials,
  bar: barCredentials,
  dessert: dessertCredentials,
};

function credentialsForStation(station: StationName) {
  if (station === 'cold') return coldCredentials;
  if (station === 'bar') return barCredentials;
  return dessertCredentials;
}

function kdsPath(station: StationName): string {
  return station === 'bar'
    ? '/staff/bar.php'
    : `/staff/kitchen.php?station=${encodeURIComponent(station)}`;
}

function ticketItems(payload: unknown): Array<Record<string, unknown>> {
  if (!payload || typeof payload !== 'object') return [];
  const tickets = Array.isArray((payload as { tickets?: unknown }).tickets)
    ? (payload as { tickets: Array<Record<string, unknown>> }).tickets
    : [];
  return tickets.flatMap((ticket) => Array.isArray(ticket.items) ? ticket.items as Array<Record<string, unknown>> : []);
}

test.describe('Station KDS flow', () => {
  for (const station of ['cold', 'bar', 'dessert'] as StationName[]) {
    test(`${station} station opens and API payload is station-scoped`, async ({ page, diagnostics }) => {
      const credentials = stationCredentials[station];
      test.skip(!credentials, `Set QRREST_${station.toUpperCase()}_EMAIL/PASSWORD to run ${station} KDS smoke checks.`);

      await loginAs(page, credentials!);
      const response = await visitReadOnly(page, kdsPath(station));

      await expectPageHealthy(page, response);
      await expectNoRuntimeText(page);

      const apiResponse = await page.request.get(`/staff/kitchen_api.php?status=all&station=${encodeURIComponent(station)}`);
      const payload = await expectJsonResponseHealthy(apiResponse) as Record<string, unknown>;
      expect(payload.success, 'kitchen_api success flag').not.toBe(false);

      for (const item of ticketItems(payload)) {
        expect(String(item.station || item.production_station || '').toLowerCase(), `item routed to ${station}`).toBe(station);
      }
      expectNoServerErrors(diagnostics);
    });

    test(`${station} station polling returns valid JSON`, async ({ page, diagnostics }) => {
      const credentials = stationCredentials[station];
      test.skip(!credentials, `Set QRREST_${station.toUpperCase()}_EMAIL/PASSWORD to run ${station} polling smoke checks.`);

      await loginAs(page, credentials!);
      const pollingPattern = new RegExp(`/staff/kitchen_api\\.php.*station=${encodeURIComponent(station)}`, 'i');
      const polling = waitForJsonPolling(page, pollingPattern).catch(() => null);
      const response = await visitReadOnly(page, kdsPath(station));

      await expectPageHealthy(page, response);
      const pollResult = await polling;
      expect(pollResult, `${station} polling response observed`).not.toBeNull();
      if (pollResult) {
        const payload = pollResult.payload as Record<string, unknown>;
        expect(payload.success, `${station} polling success flag`).not.toBe(false);
      }
      expectNoServerErrors(diagnostics);
    });
  }

  test('owner/admin KDS super-view can request all stations', async ({ page, diagnostics }) => {
    test.skip(!ownerCredentials, 'Set QRREST_OWNER_EMAIL/PASSWORD or QRREST_ADMIN_EMAIL/PASSWORD to run owner KDS smoke checks.');

    await loginAs(page, ownerCredentials!);
    const response = await visitReadOnly(page, '/staff/kitchen.php?station=all');

    await expectPageHealthy(page, response);
    const apiResponse = await page.request.get('/staff/kitchen_api.php?status=all');
    const payload = await expectJsonResponseHealthy(apiResponse) as Record<string, unknown>;
    expect(payload.success, 'owner kitchen_api success flag').not.toBe(false);
    expectNoServerErrors(diagnostics);
  });
});
