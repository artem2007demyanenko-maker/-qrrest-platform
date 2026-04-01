# Variant 1 — Full closure (1A–1D)

## Checklist: 4 stages

**1A — stats.php "single query engine"**
- [x] stats__normalize_restaurant_ids, stats__placeholders, stats__utc_bounds, stats__orders_where_sql, stats__orders_base_subquery
- [x] All heavy functions use base subquery or unified WHERE (revenue_summary, conversion, top_items, top_categories, margin, heatmap, daily_revenue)
- [x] Empty restaurantIds → safe empty/zero structures, no SQL
- [x] Semi-interval everywhere: created_at >= ? AND created_at < ? (no BETWEEN, no DATE_ADD in WHERE)
- [x] ONLY_FULL_GROUP_BY respected (GROUP BY id, name etc.)
- [x] cost_price fallback: profit=0, margin_pct=null when column missing
- [x] stats_top_share: early return on empty normalized ids, same period/scope as summary and top_items

**1B — Indexes**
- [x] orders: idx_orders_base_filter (restaurant_id, payment_status, order_status, created_at) — covers base subquery WHERE
- [x] order_items: idx_order_items_order_menu (order_id, menu_item_id)
- [x] menu_items: idx_menu_items_restaurant_category (restaurant_id, category_id)
- [x] menu_categories: idx_menu_categories_restaurant (restaurant_id)
- [x] Migration idempotent via INFORMATION_SCHEMA

**1C — owner/dashboard.php cache and stability**
- [x] remember(): do not cache when callback throws; do not cache when result invalid (per-name validator)
- [x] Cache hit only when meta.payload !== null (placeholder null is not a hit)
- [x] error_log on STATS_CACHE_CALLBACK_ERROR and STATS_CACHE_INVALID (user_id, scope_hash, name)
- [x] debug self-check (debug=1, owner only): error_log + debugData.selfcheck (top_items, top_categories, summary_current, conversion, heatmap, margin) — no fatals
- [x] No new POST actions (only existing alert_status, purge_cache)

**1D — Selftest and smoke**
- [x] app/stats_selftest.php: empty scope, invalid dates, range=all, limit=0/3, shape checks
- [x] Consistency: revenue >= 0, orders >= 0, top_share_pct in [0,100], heatmap sum <= paid orders (read-only)
- [x] Selftest included in debugData when debug=1 and owner
- [x] Smoke URLs documented below

---

## What changed (by file)

**app/stats.php**
- stats_top_share: normalizes restaurantIds via stats__normalize_restaurant_ids; early return on empty; uses same $ids for summary and top_items. No logic change.

**public_html/owner/dashboard.php**
- remember(): Cache hit only if meta !== null and meta.payload !== null (placeholder/null not a hit). metaRetry same rule.
- shouldCache($val, $name): per-name validation (revenue_summary: revenue/orders/avg; conversion: total/paid/canceled; heatmap: 24 keys; top lists: name/revenue/qty; margin: revenue/profit/margin_pct; etc.). Invalid result not cached; STATS_CACHE_INVALID logged.
- debugData.selfcheck: structured object { top_items, top_categories, summary_current, conversion, heatmap, margin } with values 'ok' or 'fail'. error_log unchanged for failures.

**app/stats_selftest.php**
- New checks: consistency_revenue_non_neg, consistency_orders_non_neg, consistency_top_share_pct_range, consistency_heatmap_sum_lte_paid (with optional message when heatmap_sum > paid).

**app/migrations/2026_03_03_add_perf_indexes.sql**
- No code change this round. Already contains idx_orders_base_filter and join indexes; idempotent.

---

## Indexes and migration

**Orders**
- idx_orders_base_filter (restaurant_id, payment_status, order_status, created_at) — base subquery filter.

**order_items**
- idx_order_items_order_menu (order_id, menu_item_id) — JOIN with menu_items.

**menu_items**
- idx_menu_items_restaurant_category (restaurant_id, category_id) — category JOIN/filter.

**menu_categories**
- idx_menu_categories_restaurant (restaurant_id) — multi-tenant.

**Apply migration**
```bash
mysql -u USER -p DATABASE < app/migrations/2026_03_03_add_perf_indexes.sql
```
Or with Docker:
```bash
docker exec -i CONTAINER mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_03_add_perf_indexes.sql
```
Script is idempotent (INFORMATION_SCHEMA checks).

---

## Smoke URLs

- /owner/dashboard.php?range=7d&rest_id=all — no fatals; scope all.
- /owner/dashboard.php?range=7d&rest_id=all&debug=1 — owner only: debug block with selftest and selfcheck; no fatals; errors not cached.
- /owner/dashboard.php?range=7d&rest_id=1&debug=1 — single rest; no scope leak.
- Empty scopeIds: dashboard still renders (defaults); no stats block executed when scopeIds empty; no alerts_repo when scopeIds empty.

---

## Selftest: what is run and what counts as pass

**Checks (all must pass)**
- empty_scope_*: each stats_* with [] returns safe empty/zero (no SQL for orders).
- limit_zero_top_items / limit_zero_top_categories: return [].
- top_items_shape_limit3: result is array; if non-empty, first row has name, revenue, qty.
- invalid_dates_conversion: conversion([1], 'invalid','invalid') returns array with key total (no throw).
- range_all_prev_period_null: prev period for range=all is null,null.
- consistency_revenue_non_neg: summary.revenue >= 0.
- consistency_orders_non_neg: summary.orders >= 0.
- consistency_top_share_pct_range: share_pct is null or in [0, 100].
- consistency_heatmap_sum_lte_paid: sum(heatmap) <= conversion.paid (sanity); pass=true; on fail, message includes heatmap_sum and paid.

**Example selftest output (all pass)**
```json
[
  {"name": "empty_scope_revenue_by_restaurants", "passed": true},
  {"name": "empty_scope_revenue_summary", "passed": true},
  {"name": "empty_scope_conversion", "passed": true},
  {"name": "empty_scope_top_items", "passed": true},
  {"name": "empty_scope_top_categories", "passed": true},
  {"name": "empty_scope_margin", "passed": true},
  {"name": "empty_scope_top_share", "passed": true},
  {"name": "empty_scope_heatmap", "passed": true},
  {"name": "empty_scope_daily_revenue", "passed": true},
  {"name": "empty_scope_alerts", "passed": true},
  {"name": "limit_zero_top_items", "passed": true},
  {"name": "limit_zero_top_categories", "passed": true},
  {"name": "top_items_shape_limit3", "passed": true},
  {"name": "invalid_dates_conversion", "passed": true},
  {"name": "range_all_prev_period_null", "passed": true},
  {"name": "consistency_revenue_non_neg", "passed": true},
  {"name": "consistency_orders_non_neg", "passed": true},
  {"name": "consistency_top_share_pct_range", "passed": true},
  {"name": "consistency_heatmap_sum_lte_paid", "passed": true}
]
```
On failure, item has "message" key, e.g. `{"name": "consistency_heatmap_sum_lte_paid", "passed": false, "message": "heatmap_sum=5 paid=3"}`.

---

## Acceptance

- /owner/dashboard.php?range=7d&rest_id=all&debug=1: no fatals; selftest in debugData; selfcheck ok/fail; cache hit/miss; errors not cached.
- /owner/dashboard.php?range=7d&rest_id=1&debug=1: no scope leak.
- SQL: no DATE_ADD(created_at...) in WHERE; no BETWEEN; semi-interval only.
- Empty scopeIds: no stats queries in block; UI does not break.
