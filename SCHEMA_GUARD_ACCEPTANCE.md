# Schema guard — acceptance / smoke notes

The schema guard allows the app to run when the database has **not** had all enterprise migrations applied (e.g. missing `usage_metrics_daily`, `plans`/`subscriptions`/`invoices`, `referral_codes`, `security_rate_limits`, or `restaurants.deleted_at`). No 500; graceful fallbacks and clear messaging.

---

## 1. Reproduce “missing migration” scenario (no 500)

### Option A: Fresh DB without migrations

1. Use a DB that has only core tables (e.g. `users`, `restaurants` without `deleted_at`, `orders`, `menu_items`, etc.) and **no**:
   - `usage_metrics_daily`
   - `plans`, `subscriptions`, `invoices`
   - `referral_codes`, `referral_clicks`, `referral_conversions`, `coupons`, `coupon_usages`
   - `security_rate_limits`
   - column `restaurants.deleted_at`

2. **Owner dashboard** (`/owner/dashboard.php`):
   - Page loads (no 500).
   - Sidebar shows “Billing unavailable (migration pending)” and optionally “Рост и рефералы” only if growth tables exist.
   - No usage metrics write; one-time log: `SCHEMA_MISSING usage_metrics_daily user_id=... uri=...`.
   - With `?debug=1` (owner only): `debugData.schema_preflight` shows `missing_tables`, `missing_columns`, `features_disabled`.

3. **Owner billing** (`/owner/billing.php`):
   - Friendly page: “Billing unavailable”, “Database migrations for billing have not been applied yet”.
   - With `?debug=1` (owner): command hint (e.g. run migrations) is shown.
   - No 500.

4. **Owner growth** (`/owner/growth.php`):
   - Friendly page: “Growth unavailable”, “Database migrations for growth features have not been applied yet”.
   - Link back to dashboard. No 500.

5. **Login** (`/login.php`):
   - Page loads; login works.
   - With `?ref=CODE`: referral click is **not** stored if growth tables are missing; no error; session still stores ref code.

6. **Rate limit** (login, referral_click, restaurant_public):
   - If `security_rate_limits` table is missing: rate limit is skipped (no-op), one-time log `SCHEMA_MISSING security_rate_limits ... rate_limit_skipped`. No 500.

### Option B: Drop one table/column

- Drop only `usage_metrics_daily`: dashboard loads; usage write skipped; log once.
- Drop only `plans` (or `subscriptions` or `invoices`): billing page shows “Billing unavailable”; dashboard sidebar same.
- Drop only `referral_codes`: growth page shows “Growth unavailable”; dashboard does not add growth alerts; login ref click skipped.
- Drop only `security_rate_limits`: rate limit no-op; log once.
- Remove `restaurants.deleted_at`: dashboard restaurant list still works (no deleted filter); usage/growth alerts that depend on it are skipped.

---

## 2. After applying migrations (full functionality)

1. Apply all migrations (billing, growth, stability, etc.) so that:
   - `plans`, `subscriptions`, `invoices`, `payments` exist
   - `referral_codes`, `referral_clicks`, `referral_conversions`, `coupons`, `coupon_usages`, `usage_metrics_daily` exist
   - `security_rate_limits` exists
   - `restaurants.deleted_at` exists (and any related indexes)

2. **Owner dashboard**:
   - Sidebar shows “Тариф: …” and “Рост и рефералы”.
   - Usage metrics are written when owner opens dashboard (once per day UTC).
   - Growth soft-limit alerts appear when applicable.
   - Restaurant list respects soft delete (`deleted_at`).

3. **Owner billing**:
   - Full billing UI: current plan, history, plan change, cancel.
   - No “Billing unavailable” message.

4. **Owner growth**:
   - Full growth UI: referral link, stats, usage chart, coupon usages, etc.

5. **Login**:
   - `?ref=CODE` records referral click (when growth tables exist).

6. **Rate limit**:
   - MySQL (or Redis) rate limit is active; 429 when over limit.

7. **Debug** (`?debug=1` for owner):
   - `schema_preflight.missing_tables` and `missing_columns` empty (or minimal).
   - `features_disabled` empty when all migrations are applied.

---

## 3. Security / observability

- Non-debug users never see SQL errors or migration hints (except generic “migrations not applied”).
- Logs include `user_id` and `uri` where applicable (`SCHEMA_MISSING`, `SCHEMA_GUARD_*`).
- CSRF, `safe_redirect`, and existing handlers are unchanged.
