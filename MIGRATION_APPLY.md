# Applying migrations (MySQL 8, idempotent)

Migrations are in `app/migrations/`. Apply in order: billing → growth → stability (and any perf/owner alerts if present).

## Docker Compose

Assume service name `db`, user `qr`, password `qrpass`, database `qr_rest`. From project root:

```bash
# Billing (Variant 4)
docker compose exec -T db mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_04_billing.sql

# Billing hardened (idempotency keys)
docker compose exec -T db mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_05_billing_hardened.sql

# Growth (Variant 6)
docker compose exec -T db mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_06_growth.sql

# Stability (Variant 7)
docker compose exec -T db mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_07_stability.sql

# Restaurant plans & usage (optional; passive subscription/limits support)
docker compose exec -T db mysql -u qr -pqrpass qr_rest < app/migrations/2026_06_12_restaurant_plans_usage.sql
```

Use `-T` so `mysql` does not allocate a TTY. For non-docker, replace with:

```bash
mysql -u qr -p qr_rest < app/migrations/2026_03_04_billing.sql
# ... etc
```

## Verify tables

```sql
SHOW TABLES LIKE 'plans';
SHOW TABLES LIKE 'subscriptions';
SHOW TABLES LIKE 'invoices';
SHOW TABLES LIKE 'referral_codes';
SHOW TABLES LIKE 'usage_metrics_daily';
SHOW TABLES LIKE 'security_rate_limits';
```

Or in one go:

```bash
docker compose exec -T db mysql -u qr -pqrpass qr_rest -e "
  SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = 'qr_rest'
  AND TABLE_NAME IN ('plans','subscriptions','invoices','referral_codes','usage_metrics_daily','security_rate_limits');
"
```

## Verify columns

```sql
SHOW COLUMNS FROM restaurants LIKE 'deleted_at';
```

```bash
docker compose exec -T db mysql -u qr -pqrpass qr_rest -e "SHOW COLUMNS FROM restaurants LIKE 'deleted_at';"
```

## Health check after migrations

- Full schema applied: `GET /health.php` → `{"status":"ok","db":"ok",...}`.
- Migrations missing: `GET /health.php` → `{"status":"degraded","migrations_pending":["plans",...],...}` (no 500).

See also `SCHEMA_GUARD_ACCEPTANCE.md` and `BACKUP_RECOVERY.md`.

---

## Manual test list (no 500)

After applying migrations (or with a fresh DB without migrations), hit these URLs. **Expected: no HTTP 500.**

| URL | Expected (migrations applied) | Expected (migrations NOT applied) |
|-----|--------------------------------|------------------------------------|
| `/health.php` | 200, `{"status":"ok","db":"ok"}` | 200, `{"status":"degraded","migrations_pending":[...]}` |
| `/owner/dashboard.php?range=7d&rest_id=all` | 200, full dashboard | 200, dashboard with "Billing unavailable (migration pending)" if billing missing |
| `/owner/billing.php` | 200, billing UI | 200, "Billing unavailable, migrations pending" page |
| `/owner/growth.php` | 200, growth UI | 200, "Growth unavailable, migrations pending" page |
| `/restaurant_public.php?rest_id=1&utm_source=test&utm_campaign=test` | 200 or 404 (restaurant) | 200 or 404, never 500 (deleted_at optional) |
| `/login.php` | 200, login form | 200, login form |
| `/login.php?ref=TESTCODE1234` | 200, ref stored in session; click in DB if growth ready | 200, ref in session; no DB write if growth missing |

**Smoke script (CLI):**

```bash
php scripts/check_schema.php
# Exit 0 = all critical tables/columns present; 1 = missing
php scripts/check_schema.php --json
```

**HTTP smoke (inside web container):**

```bash
docker compose exec -T web bash scripts/smoke_http.sh
```

With login (to verify dashboard/billing/growth/restaurant_public return 200 after auth):

```bash
SMOKE_EMAIL=owner@example.com SMOKE_PASS=yourpassword docker compose exec -T web bash scripts/smoke_http.sh
```

Optional: `BASE_URL=http://lvh.me` if running from host against a tunnel.
