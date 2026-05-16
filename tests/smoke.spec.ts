import { expectNoServerErrors, expectPageHealthy, test, visitReadOnly } from './fixtures';

const readOnlyRoutes = [
  { name: 'home', path: '/' },
  { name: 'qr menu', path: '/qr.php' },
  { name: 'login', path: '/login.php' },
  { name: 'order tracking shell', path: '/order_track.php' },
  { name: 'health', path: '/health.php' },
];

test.describe('QRRest production smoke checks', () => {
  for (const route of readOnlyRoutes) {
    test(`read-only page is healthy: ${route.name}`, async ({ page, diagnostics }) => {
      const response = await visitReadOnly(page, route.path);

      await expectPageHealthy(page, response);
      expectNoServerErrors(diagnostics);
    });
  }

  test('public QR menu does not expose PHP/runtime errors', async ({ page, diagnostics }) => {
    const response = await visitReadOnly(page, '/qr.php');

    await expectPageHealthy(page, response);
    expectNoServerErrors(diagnostics);
  });
});
