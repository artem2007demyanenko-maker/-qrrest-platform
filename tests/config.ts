import type { Credentials } from './fixtures';

export const TEST_TABLE_ID = process.env.QRREST_E2E_TABLE_ID || '';
export const TEST_ORDER_ID = process.env.QRREST_E2E_ORDER_ID || '';
export const TEST_TRACK_TOKEN = process.env.QRREST_E2E_TRACK_TOKEN || '';
export const MUTATION_ENABLED = process.env.QRREST_E2E_MUTATION === '1';
export const ORDER_CREATION_ENABLED = process.env.QRREST_E2E_CREATE_ORDER === '1';
export const STRICT_E2E = process.env.QRREST_E2E_STRICT === '1';

export function credentials(prefix: string): Credentials | null {
  const email = process.env[`${prefix}_EMAIL`] || '';
  const password = process.env[`${prefix}_PASSWORD`] || '';
  if (!email || !password) {
    return null;
  }
  return { email, password };
}

export const ownerCredentials = credentials('QRREST_OWNER') || credentials('QRREST_ADMIN');
export const staffCredentials = credentials('QRREST_STAFF') || credentials('QRREST_WAITER');
export const waiterCredentials = credentials('QRREST_WAITER') || staffCredentials;
export const kitchenCredentials = credentials('QRREST_KITCHEN');
export const coldCredentials = credentials('QRREST_COLD');
export const barCredentials = credentials('QRREST_BAR');
export const dessertCredentials = credentials('QRREST_DESSERT');

export const testGuest = {
  name: process.env.QRREST_E2E_GUEST_NAME || 'Playwright Smoke Guest',
  phone: process.env.QRREST_E2E_GUEST_PHONE || '+79000000000',
  address: process.env.QRREST_E2E_DELIVERY_ADDRESS || 'Тестовый адрес Playwright, 1',
  comment: process.env.QRREST_E2E_COMMENT || 'Playwright smoke validation',
};

export function trackingPath(): string {
  if (TEST_TRACK_TOKEN) {
    return `/order_track.php?token=${encodeURIComponent(TEST_TRACK_TOKEN)}`;
  }
  if (TEST_ORDER_ID) {
    return `/order_track.php?order_id=${encodeURIComponent(TEST_ORDER_ID)}`;
  }
  return '/order_track.php';
}
