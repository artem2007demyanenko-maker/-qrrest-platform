# Protected Hotspot List

Critical areas below are protected. Changes require targeted smoke-test before deploy.

## `public_html/qr.php`

- Why critical: primary guest ordering flow.
- Historically broken by: context drift, menu availability edge cases, checkout coupling.
- Safeguards: defensive fallbacks, tenant-aware guards, order creation event logging.

## `public_html/staff/pos_create_order.php`

- Why critical: manual POS order creation and compatibility with unified orders.
- Historically broken by: schema drift in optional fields.
- Safeguards: schema-safe writes, backward-compatible defaults.

## `public_html/staff/kitchen_api.php`

- Why critical: kitchen queue source for KDS polling.
- Historically broken by: missing legacy columns (`quantity/qty`, `payment_type`, `total_amount` variants).
- Safeguards: schema-safe column resolution and fallback aliases.

## `public_html/ajax/kds_orders.php`

- Why critical: legacy KDS clients and kitchen feed compatibility.
- Historically broken by: strict column assumptions.
- Safeguards: legacy-compatible select expressions and safe JSON response.

## `public_html/staff/orders_api.php`

- Why critical: waiter and staff operational order center.
- Historically broken by: status/field mismatches and context enforcement regressions.
- Safeguards: normalized order_type/status labels and fallback data shaping.

## `public_html/staff/order_update_status.php`

- Why critical: payment/order status transitions.
- Historically broken by: backend ignoring payment-only updates.
- Safeguards: dual-field validation (`order_status` + `payment_status`) and transaction wrapper.

## `public_html/order_track.php`

- Why critical: guest post-order visibility and trust.
- Historically broken by: strict restaurant context/subdomain assumptions.
- Safeguards: fallback context resolution and safe status rendering.

## `public_html/staff/loyalty_scan.php`

- Why critical: in-service loyalty earn/spend operations.
- Historically broken by: mismatch between live schema and legacy loyalty paths.
- Safeguards: live-schema alignment and failure markers in logs.

## `app/runtime_schema_bootstrap.php`

- Why critical: runtime schema compatibility across mixed environments.
- Historically broken by: duplicate index/column spam and non-idempotent DDL attempts.
- Safeguards: existence checks, ignorable duplicate handling, tagged warnings.

## `app/helpers.php`

- Why critical: shared helper layer used by most operational endpoints.
- Historically broken by: undefined helper drift after staged deployments.
- Safeguards: `function_exists` wrapping and schema-safe utility patterns.
