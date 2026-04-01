# Plan Limits & Enforcement Strategy

_Last updated: internal soft-enforcement pass (no hard blocks by default)._

## 1. Current plans and limits

- **Plans** (restaurant-level, via `restaurant_subscriptions` + `app/plans_config.php`):
  - `FREE`
  - `GROWTH`
  - `PRO`

- **Configured limits (per month)**:
  - `max_orders` per plan (e.g. FREE = 50, GROWTH = 500, PRO = unlimited/null).

- **Metrics tracked in `usage_metrics`** (per restaurant, per month):
  - `orders`
  - `crm_messages`
  - `upsell_shown`
  - `upsell_accepted`
  - `loyalty_transactions`

All metrics are **read-only** for enforcement; they do not block flows by themselves.

## 2. Soft vs hard enforcement

### 2.1 Soft enforcement (current default)

Soft enforcement is implemented via:

- **Helpers** in `app/subscription_plans.php`:
  - `get_restaurant_plan($restaurantId)` → effective plan (`free|growth|pro`) and status (`active|trial`), with expired/inactive rows falling back to `free`.
  - `check_feature($restaurantId, $feature)` → soft gating for:
    - `crm_enabled`
    - `upsell_enabled`
    - `loyalty_enabled`
  - `check_limit($restaurantId, 'orders')` → used for warnings and upgrade nudges, but not hard blocks.
  - `get_usage_percent($restaurantId, 'orders')` → % of order limit (for UI only).
  - `get_monthly_usage_snapshot($restaurantId)` → current-month counts for:
    - `orders`, `crm_messages`, `upsell_shown`, `upsell_accepted`, `loyalty_transactions`
  - `get_limit_state($restaurantId, $metric)` → current limit state (see below).

- **Feature gating**:
  - CRM / Upsell / Loyalty are **already soft-gated** by plan via `check_feature()`.
  - Disabled features:
    - Hide/disable mutation UI.
    - Short upgrade banners / CTAs.
    - Server-side guards that no-op or return safe errors if user bypasses UI.

- **Orders**:
  - Orders are currently **NOT hard-blocked** by plan limits.
  - We only show:
    - Soft warnings on the dashboard and `order_track.php`.
    - Usage and limit progress bars on the billing page.

### 2.2 Hard enforcement (future)

Hard enforcement (e.g., blocking new orders after hitting plan limit) is **NOT enabled by default**.

The only hook for future hard enforcement is:

- **Environment variable**: `APP_HARD_ORDER_LIMIT`
  - When `APP_HARD_ORDER_LIMIT=1`, `get_limit_state($restaurantId, 'orders')` will:
    - Return `allowed = false` when `state === 'exceeded'`.
  - No production code currently reads `allowed === false` to block orders; this is reserved for future use.

Any actual hard block must:

1. Explicitly check `get_limit_state(..., 'orders')['allowed']` in the order creation logic.
2. Provide a graceful user-facing message / redirect path.
3. Be enabled intentionally (e.g., by env, feature flag, or config), not by default.

## 3. `get_limit_state` helper (read-only)

`get_limit_state(int $restaurantId, string $metric): array` (in `app/subscription_plans.php`) returns:

```php
[
  'allowed' => bool,   // true in soft mode, may be false in hard mode when exceeded
  'usage'   => int,    // current month usage for metric
  'limit'   => ?int,   // configured max for plan (null = unlimited)
  'percent' => int,    // 0–100+ (0 if no limit or no data)
  'state'   => 'ok' | 'warning' | 'exceeded' | 'unlimited',
]
```

Behavior:

- For `metric = 'orders'`:
  - Uses plan config `max_orders` as limit.
  - Reads current-month `usage_metrics` row (`metric = 'orders'`).
  - Thresholds:
    - `< 80%` → `state = 'ok'`
    - `80–99%` → `state = 'warning'`
    - `>= 100%` → `state = 'exceeded'`
  - `limit = null` (e.g. PRO) → `state = 'unlimited'`, `allowed = true`.

- For other metrics:
  - Currently treated as `unlimited` (no numeric limit defined).

- `allowed`:
  - In **soft mode** (default):
    - Always `true`, even when `state = 'exceeded'`.
  - In **future hard mode** (`APP_HARD_ORDER_LIMIT=1` and `metric = 'orders'`):
    - `allowed = false` when `state = 'exceeded'`.

This helper is **purely descriptive** and does not write to the database or throw.

## 4. Where limit state is surfaced

### 4.1 Owner billing page (`public_html/owner/billing.php`)

- Uses:
  - Existing orders usage (X / Y) for the first restaurant.
  - `get_limit_state($usageRestaurantId, 'orders')` for descriptive state.

- In the “Текущий тариф” section:
  - Shows:
    - **"Заказы в этом месяце"**: `X / Y` + progress bar.
    - Limit state line (translated from `state`):
      - `ok`:
        - “Состояние лимита: в пределах тарифа.”
      - `warning`:
        - “Состояние лимита: вы приближаетесь к лимиту заказов.”
      - `exceeded` + `allowed=true` (soft mode):
        - “Лимит заказов превышен: новые заказы пока продолжают приниматься (мягкий режим). Жёсткое ограничение не включено.”

This makes it clear to the owner:

- Whether they are within, near, or beyond the plan limit.
- That the system is currently in **soft mode** (no hard block applied).

### 4.2 Restaurant dashboard (`public_html/restaurant/dashboard.php`)

- Uses metrics and max-orders to show:
  - Plan + usage in header (e.g. `Тариф: FREE · Заказы: 32 / 50`).
  - A single top-level upgrade nudge with priority:
    1. Order limit warning/exceeded.
    2. CRM unavailable.
    3. Upsell unavailable.
    4. Loyalty unavailable.

Thresholds mirror `get_limit_state`:

- `>= 80%`:
  - “Вы приближаетесь к лимиту заказов…”
- `>= 100%`:
  - “Вы превысили лимит заказов на текущем тарифе…”

Even when exceeded, **orders are not blocked**; messaging is purely advisory with upgrade CTAs.

## 5. What is currently soft-gated vs hard-blocked

### Soft-gated (feature availability)

- **CRM**:
  - `check_feature($restaurantId, 'crm_enabled')`:
    - Disabled plans:
      - UI: read-only pages, upgrade banners, disabled forms/buttons.
      - Backend: POST handlers early-return with safe error messages (no writes).
    - Enabled plans:
      - Full functionality.

- **Upsell**:
  - `check_feature($restaurantId, 'upsell_enabled')`:
    - Disabled plans:
      - No upsell offers in QR flow.
      - Management UI read-only with upgrade banners.
      - Upsell tracking endpoints no-op.

- **Loyalty**:
  - `check_feature($restaurantId, 'loyalty_enabled')`:
    - Disabled plans:
      - No accrual/spending; staff/guest pages show safe messages and disable forms.
      - Guest balance endpoints return 0 or neutral messages.

### Hard-blocked

- **Nothing** is currently hard-blocked by plan limits:
  - QR ordering still works for all plans, even above configured `max_orders`.
  - CRM/Upsell/Loyalty are disabled where plan does not include them, but this is **feature access**, not per-month usage blocking.

## 6. Current default mode

- **Default**:
  - **Soft enforcement only**:
    - Orders: tracked, nudged, but **not blocked**.
    - Features (CRM/Upsell/Loyalty): gated by plan, but no per-month quota blocks.

- **Environment**:
  - `APP_HARD_ORDER_LIMIT` is **not set** or is `0` in normal deployments.
  - Turning it on only changes `get_limit_state()['allowed']` and does not block by itself.

## 7. Future enforcement options

To move towards real hard enforcement, recommended path:

1. **Keep `get_limit_state` as the single source of truth** for limit state.
2. In order creation (e.g., `public_html/qr.php`), add an optional guard:

   ```php
   $state = function_exists('get_limit_state') ? get_limit_state((int)$currentRestaurant['id'], 'orders') : null;
   if ($state && !$state['allowed']) {
       // Future hard-block path:
       // - Show friendly message to guest/owner
       // - Optionally redirect to upgrade/billing
       // - Do NOT 500; respond gracefully
   }
   ```

3. Gate actual hard-blocking behind:
   - Environment flag (`APP_HARD_ORDER_LIMIT=1`),
   - Or internal feature flag / config,
   - With clear owner-facing messaging on the billing and dashboard pages.

Until such a guard is wired in, the system remains in **business-safe soft mode**.

