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

## Stable E2E Baseline

The suite is designed to run against a real disposable tenant, not mocks. To create/update the minimal baseline data, run this on the target environment with secrets supplied from env:

```bash
export QRREST_E2E_PASSWORD='set-a-real-secret-outside-git'
php tools/e2e_baseline.php --apply
```

The provisioner is idempotent and creates/updates only the dedicated E2E tenant rows:

- Restaurant subdomain: `QRREST_E2E_RESTAURANT_SUBDOMAIN` (default `test`)
- One table for QR hall ordering
- One visible category and visible menu items for `kitchen`, `cold`, `bar`, and `dessert`
- Dedicated owner, waiter, kitchen, bar, cold, and dessert accounts
- One active KDS baseline order when `QRREST_E2E_BASELINE_KDS_ORDER=1`

Passwords are never stored in the repository. Use `QRREST_E2E_PASSWORD` for all test accounts or per-role variables like `QRREST_BAR_PASSWORD`. After the provisioner finishes, copy the printed `QRREST_E2E_TABLE_ID` and account emails into `.env.playwright`.

Required only for authenticated flows:

- `QRREST_OWNER_EMAIL` / `QRREST_OWNER_PASSWORD`
- `QRREST_WAITER_EMAIL` / `QRREST_WAITER_PASSWORD`
- `QRREST_KITCHEN_EMAIL` / `QRREST_KITCHEN_PASSWORD`
- `QRREST_COLD_EMAIL` / `QRREST_COLD_PASSWORD`
- `QRREST_BAR_EMAIL` / `QRREST_BAR_PASSWORD`
- `QRREST_DESSERT_EMAIL` / `QRREST_DESSERT_PASSWORD`

For CI or pre-deploy gates, enable strict mode:

```bash
QRREST_E2E_STRICT=1 npm run smoke
```

Strict mode turns missing credentials/data/mutation flags into clear failures instead of silent skips.

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
