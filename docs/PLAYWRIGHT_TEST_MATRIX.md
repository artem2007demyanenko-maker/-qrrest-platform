# Playwright Test Matrix

## Base

- Config: `playwright.config.ts`
- Default base URL: `https://test.qrrest-menu.ru`
- Browser: Chromium
- Artifacts: screenshot/video/trace on failure, HTML report

## Test Groups

| File | Flow | Mode | Required env |
| --- | --- | --- | --- |
| `tests/smoke.spec.ts` | Public pages and health shell | Read-only | none |
| `tests/guest-qr-flow.spec.ts` | QR menu, cart, hall order tracking | Read-only + gated mutation | `QRREST_E2E_MUTATION`, `QRREST_E2E_CREATE_ORDER`, optional `QRREST_E2E_TABLE_ID` |
| `tests/delivery-flow.spec.ts` | Delivery menu, validation, delivery order tracking | Read-only + gated mutation | `QRREST_E2E_MUTATION`, `QRREST_E2E_CREATE_ORDER` |
| `tests/staff-flow.spec.ts` | Staff orders and floorplan | Auth read-only | `QRREST_WAITER_*` or `QRREST_STAFF_*` |
| `tests/kds-flow.spec.ts` | Cold/bar/dessert KDS, station APIs, polling | Auth read-only | `QRREST_COLD_*`, `QRREST_BAR_*`, `QRREST_DESSERT_*`, optional owner/admin |
| `tests/kds-station-security.spec.ts` | Cross-station update denial | Auth guarded negative test | `QRREST_BAR_*` and `QRREST_COLD_*` |
| `tests/rbac.spec.ts` | Anonymous restrictions, waiter KDS denial, bar access | Read-only + auth read-only | optional waiter/staff/bar |
| `tests/realtime.spec.ts` | KDS/orders/floorplan polling endpoints | Auth read-only | station or staff credentials |
| `tests/restaurant-dashboard.spec.ts` | Dashboard, CRM, loyalty, menu management, analytics JSON | Auth read-only | `QRREST_OWNER_*` or `QRREST_ADMIN_*` |
| `tests/loyalty-flow.spec.ts` | Staff loyalty and owner loyalty settings | Auth read-only | staff/waiter/owner |

## JSON Contract Expectations

AJAX/API endpoints should return JSON for auth failures:

```json
{"success":false,"message":"auth_required"}
```

or:

```json
{"success":false,"message":"access_denied"}
```

HTML redirects are expected for normal browser page navigation, not for API/AJAX calls.

## Mutation Policy

Mutation tests are skipped unless enabled:

```bash
QRREST_E2E_MUTATION=1 npm run smoke
QRREST_E2E_MUTATION=1 QRREST_E2E_CREATE_ORDER=1 npm run smoke
```

Use a disposable tenant and test menu items for mutating flows.

## Current Stable Baseline

Without credentials and mutation flags, the suite validates public/read-only flows and anonymous RBAC checks. Authenticated tests skip safely until credentials are provided.
