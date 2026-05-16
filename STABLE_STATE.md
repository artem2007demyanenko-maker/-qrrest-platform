# Stable State

## Release baseline

- Release: `v0.9-stable-smoke-pass`
- Date: `2026-05-08`
- Goal: locked production-safe baseline after stabilization and smoke verification.

## Stable operational flows

### 1) Guest QR ordering

- Entrypoints: `public_html/qr.php`, `public_html/order_track.php`
- Key APIs: form posts in `qr.php`, status polling via `public_html/ajax/order_status.php`
- Critical dependencies: `orders`, `order_items`, `menu_items`, table context resolution
- Known caveats: empty menu data in specific tenant blocks order creation by design

### 2) Kitchen / KDS

- Entrypoints: `public_html/staff/kitchen.php`, `public_html/staff/kds.php`
- Key APIs: `public_html/staff/kitchen_api.php`, `public_html/ajax/kds_orders.php`, `public_html/staff/kitchen_item_update.php`
- Critical dependencies: order statuses, station routing helper, legacy qty compatibility
- Known caveats: optional KDS columns may degrade behavior, but should not crash

### 3) Waiter flow

- Entrypoints: `public_html/staff/orders.php`, `public_html/staff/floorplan.php`
- Key APIs: `public_html/staff/orders_api.php`, `public_html/staff/order_update_status.php`, `public_html/staff/floorplan_api.php`
- Critical dependencies: staff auth, restaurant context resolver, order status/payment updates
- Known caveats: role guards intentionally return `401/403` outside allowed roles

### 4) Courier flow

- Entrypoint: `public_html/staff/courier.php`
- Key APIs: courier-related AJAX endpoints in `public_html/ajax/`
- Critical dependencies: delivery order_type compatibility, courier status lifecycle, shift/assignment fallbacks
- Known caveats: lightweight mode (no map routing engine, no websocket dispatch)

### 5) Loyalty / wallet flow

- Entrypoints: `public_html/staff/loyalty_scan.php`, `public_html/restaurant/guests.php`
- Key APIs: `public_html/ajax/guest_wallet.php`, `public_html/ajax/guest_wallet_adjust.php`
- Critical dependencies: guest phone normalization, unified wallet helper layer, tenant-safe lookup
- Known caveats: if loyalty disabled for tenant, operational notices may appear but flow should not fatal

### 6) Restaurant dashboard flow

- Entrypoint: `public_html/restaurant/dashboard.php`
- Key dependencies: analytics helpers, combo suggestions, runtime schema bootstrap, safe defaults
- Known caveats: optional metrics modules may degrade to fallback values

## Existing observability baseline

- Request RID generation: `app/bootstrap.php`
- Structured runtime error tagging: `app/error_handler.php` (`STABILITY_ERROR`)
- Runtime schema warning tagging: `app/runtime_schema_bootstrap.php`
- Health endpoints: `public_html/health.php`, `public_html/ready.php`

## Guardrail

- New changes should preserve this baseline and pass targeted smoke before release.
