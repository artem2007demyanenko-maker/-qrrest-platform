# QRRest QA Pipeline

## Purpose

This pipeline is a production-safe smoke layer for QRRest. It validates runtime stability, auth redirects, JSON API contracts, KDS polling, guest menu rendering, staff pages, and restaurant dashboards without rewriting business flows.

## Safety Modes

- Read-only mode is the default.
- Cart mutation requires `QRREST_E2E_MUTATION=1`.
- Real order creation requires `QRREST_E2E_CREATE_ORDER=1`.
- Use only disposable test tenants and test accounts for mutation/order creation.

## Commands

```bash
npm run smoke
npm run smoke:headed
npm run smoke:report
```

The Playwright config stores screenshots, videos, and traces on failure and writes an HTML report to `playwright-report/`.

## Environment Setup

```bash
cp .env.playwright.example .env.playwright
set -a
source .env.playwright
set +a
npm run smoke
```

Required only for authenticated flows:

- `QRREST_OWNER_EMAIL` / `QRREST_OWNER_PASSWORD`
- `QRREST_WAITER_EMAIL` / `QRREST_WAITER_PASSWORD`
- `QRREST_COLD_EMAIL` / `QRREST_COLD_PASSWORD`
- `QRREST_BAR_EMAIL` / `QRREST_BAR_PASSWORD`
- `QRREST_DESSERT_EMAIL` / `QRREST_DESSERT_PASSWORD`

## Pre-Deploy Checks

```bash
php -l app/auth.php
php -l app/permissions.php
php -l app/error_handler.php
npm run smoke
```

## Post-Deploy Checks

```bash
curl -i https://test.qrrest-menu.ru/staff/kitchen_api.php?status=all
curl -i https://test.qrrest-menu.ru/staff/orders_api.php
curl -i https://test.qrrest-menu.ru/staff/floorplan_api.php
npm run smoke
```

Unauthenticated API checks should return JSON `401/403`, not an HTML login page.

## Failure Triage

- `Unexpected token '<'`: endpoint returned HTML instead of JSON, usually auth redirect or HTML error page.
- Polling not observed: check that login succeeded and the page is actually on KDS, not `/login.php`.
- Add-to-cart not found: verify target tenant has visible menu items and, for table mode, `QRREST_E2E_TABLE_ID` points to an existing table.
- Strict locator violation: fix the test selector to avoid broad comma selectors with visibility assertions.

## Non-Goals

- No payment processor tests.
- No destructive cleanup.
- No checkout architecture changes.
- No production data reset.
