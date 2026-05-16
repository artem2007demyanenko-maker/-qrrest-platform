# Safe Deploy Workflow

## Scope

- Workflow for production-safe releases without architectural changes.
- Optimized for hotfixes, stabilization, and controlled incremental delivery.

## Pre-deploy checklist

1. Pull latest code and verify target branch/tag.
2. Run syntax lint on critical files:
   - `php -l public_html/qr.php`
   - `php -l public_html/staff/kitchen_api.php`
   - `php -l public_html/staff/orders_api.php`
   - `php -l public_html/staff/order_update_status.php`
   - `php -l public_html/order_track.php`
   - `php -l app/runtime_schema_bootstrap.php`
3. Validate Docker config:
   - `docker compose -f docker-compose.prod.yml config`
4. Confirm no unintended edits in hotspots.
5. Prepare rollback pointer (previous image/tag or previous commit).

## Deploy steps

1. Deploy with existing production script/process.
2. Wait for app container healthy state.
3. Verify:
   - `/health.php`
   - `/ready.php`

## Post-deploy smoke

1. Guest path:
   - Open `/qr.php?table_id=<valid>`
   - Add item to cart
   - Create order
   - Open `/order_track.php?order_id=<new>`
2. Kitchen path:
   - Open `/staff/kitchen.php`
   - `GET /staff/kitchen_api.php?status=all` must return `200` JSON
3. Waiter path:
   - Open `/staff/orders.php`
   - Confirm order visible in `/staff/orders_api.php`
4. Dashboard path:
   - Open `/restaurant/dashboard.php`
5. Courier path:
   - Open `/staff/courier.php`

## Logs verification

- Run: `docker logs --tail 150 <web-container>`
- Classify messages:
  - Critical: fatal/uncaught exception/SQL error
  - Stability warning: schema fallback warnings
  - Informational notices

## Emergency rollback criteria

- Any `500` on core flows (`qr`, `kitchen_api`, `orders_api`, `dashboard`).
- Malformed JSON on operational APIs.
- Repeating fatal in logs after reload.

If any rollback criteria is met, stop rollout and revert to previous stable snapshot.
