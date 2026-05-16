import { expect, expectJsonResponseHealthy, loginAs, test } from './fixtures';
import { barCredentials, coldCredentials } from './config';
import { skipOrFail } from './test-helpers';

function firstForeignItemId(payload: unknown, forbiddenStation: string): number | null {
  if (!payload || typeof payload !== 'object') return null;
  const tickets = Array.isArray((payload as { tickets?: unknown }).tickets)
    ? (payload as { tickets: Array<Record<string, unknown>> }).tickets
    : [];
  for (const ticket of tickets) {
    const items = Array.isArray(ticket.items) ? ticket.items as Array<Record<string, unknown>> : [];
    for (const item of items) {
      const station = String(item.station || item.production_station || '').toLowerCase();
      const id = Number(item.id || 0);
      if (id > 0 && station === forbiddenStation) {
        return id;
      }
    }
  }
  return null;
}

test.describe('KDS station security guards', () => {
  test('bar account cannot update a cold station item when one is visible to cold API', async ({ page }) => {
    skipOrFail(test.skip, !barCredentials || !coldCredentials, 'Set QRREST_BAR_* and QRREST_COLD_* credentials to run cross-station security smoke checks.');

    await loginAs(page, coldCredentials!);
    const coldPayload = await expectJsonResponseHealthy(
      await page.request.get('/staff/kitchen_api.php?status=all&station=cold')
    );
    const coldItemId = firstForeignItemId(coldPayload, 'cold');
    skipOrFail(test.skip, !coldItemId, 'No cold item is currently available to validate cross-station update denial.');

    await loginAs(page, barCredentials!);
    const response = await page.request.post('/staff/kitchen_item_update.php', {
      form: {
        item_id: String(coldItemId),
        station: 'cold',
        action: 'ready',
      },
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });

    expect(response.status(), 'cross-station update should not 500').toBeLessThan(500);
    const text = await response.text();
    expect(text).toMatch(/success|denied|csrf|forbidden|station|access|доступ/i);
    if (/^\s*\{/.test(text)) {
      const payload = JSON.parse(text) as { success?: boolean };
      expect(payload.success, 'cross-station update success flag').not.toBe(true);
    }
  });
});
