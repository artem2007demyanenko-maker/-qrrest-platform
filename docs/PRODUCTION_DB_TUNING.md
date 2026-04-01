## Production DB Tuning — QR Restaurant SaaS

This document summarizes key tables and indexes that matter for production load.  
Most indexes are already covered by migrations (see `app/migrations/2026_03_02_add_indexes.sql` and `2026_03_03_add_perf_indexes.sql`), but it is useful to know what to verify.

### 1. Orders / order_items

Tables: `orders`, `order_items`

Used by:
- restaurant dashboards and revenue analytics,
- owner dashboard,
- project-admin transactions and logs,
- order tracking and kitchen/floorplan status.

Indexes to verify (already added by migrations):
- `orders`:
  - `idx_restaurant_created (restaurant_id, created_at)`
  - `idx_restaurant_payment (restaurant_id, payment_status)`
  - `idx_restaurant_status (restaurant_id, order_status)`
  - `idx_restaurant_created_paid (restaurant_id, payment_status, created_at)`
  - `idx_restaurant_created_status (restaurant_id, order_status, created_at)`
  - `idx_orders_base_filter (restaurant_id, payment_status, order_status, created_at)`
- `order_items`:
  - `idx_order (order_id)`
  - `idx_menu_item (menu_item_id)`
  - `idx_order_items_order_menu (order_id, menu_item_id)`

For large installations, ensure these indexes exist and are used by EXPLAIN plans for:
- owner dashboard time‑range queries,
- restaurant revenue/analytics pages,
- project-admin transactions view with filters.

### 2. Checkout events

Table: `checkout_events`

Used by:
- checkout funnel analytics (`app/checkout_analytics.php`).

Indexes (from `2026_05_02_checkout_events.sql`):
- `idx_rest_created (restaurant_id, created_at)`
- `idx_rest_type_created (restaurant_id, event_type, created_at)`

These support time‑series aggregations per restaurant and event type.

### 3. Error logs and cron runs

Tables: `app_error_logs`, `cron_runs`

Used by:
- project-admin diagnostics, health/ready endpoints,
- operational troubleshooting.

Indexes (from `2026_04_01_app_error_logs.sql` and `2026_04_01_cron_runs.sql`):
- `app_error_logs`:
  - `PRIMARY KEY (id)`
  - `idx_level_created (level, created_at)`
  - `idx_rid (rid)`
  - `idx_source_created (source, created_at)`
- `cron_runs`:
  - `PRIMARY KEY (id)`
  - `idx_job_created (job_name, created_at)`
  - `idx_status_created (status, created_at)`

These are sufficient for:
- recent errors/cron tables in project-admin diagnostics,
- `health.php` / `ready.php` checks for recent cron success.

### 4. Growth engine suggestions

Table: `growth_engine_suggestions`

Used by:
- growth suggestions page (`restaurant/growth_suggestions.php`),
- growth engine analytics.

Indexes (from `2026_05_03_growth_engine_suggestions.sql`):
- `idx_rest_created (restaurant_id, created_at)`

If you add heavier filters in the future (by status/type), consider composite indexes such as:
- `(restaurant_id, status, created_at)`
- `(restaurant_id, type, created_at)`

For now, the existing index is sufficient for modest volumes.

### 5. Experiment metrics

Experiments and metrics use their own tables (see `2026_05_10_growth_experiments.sql` and related migrations).  
General guidance:
- ensure there is always an index on `(experiment_id, created_at)` or `(experiment_id, variant, created_at)` for time‑series aggregations;
- add `(restaurant_id, created_at)` if you run per‑restaurant experiment breakdowns.

### 6. Operational checklist for DB performance

Before/after launch:
- Run `EXPLAIN` on:
  - owner dashboard main queries,
  - restaurant revenue/analytics queries,
  - checkout/upsell analytics queries,
  - project-admin transactions/logs,
  - experiment/growth analytics queries.
- Ensure they use the indexes listed above (no full table scans on `orders`/`order_items`/`checkout_events` for common filters).
- Monitor:
  - slow query log,
  - buffer pool hit rate,
  - disk I/O for large reporting windows.

If you see slow queries that do not use these indexes, prefer adding **targeted** composite indexes via new migrations rather than general “index everything” changes.

