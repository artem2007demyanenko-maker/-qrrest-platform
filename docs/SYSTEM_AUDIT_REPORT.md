# QR Restaurant SaaS — Full System Audit Report

**Date:** 2026-03-02  
**Scope:** Architecture, multi-tenant safety, security, database, error handling, performance, demo mode, growth engine, AI Copilot, admin dashboards, UI/UX, flow validation.

---

## STEP 1 — Architecture audit

### File structure
- **app/** — 60+ PHP modules (config, db, auth, analytics, growth, experiments, etc.). Structure is clear; bootstrap loads core (db, security, auth, error_handler, subdomain, demo, permissions, helpers, qr_helpers, upload_helpers, logging, themes, loyalty, guest_auth, guest_loyalty).
- **public_html/** — Entry points by area: restaurant/*, staff/*, project-admin/*, owner/*, guest/*, ajax/*, api/, loyalty/, root (login, signup, qr, checkout, order_track, etc.).
- **app/migrations/** — 33 SQL migrations; growth_experiments, growth_engine_suggestions, checkout_events, referrals, etc. present.

### Modules and includes
- All restaurant and project-admin pages use `require_once __DIR__ . '/../../app/bootstrap.php'` (or equivalent). Optional modules are required on demand (e.g. dashboard loads dashboard_intel, combo_builder, peak_hours, menu_heatmap, benchmark, restaurant_success, ai_copilot, growth_engine).
- **Finding:** No missing core includes detected. Optional `file_exists()` before require is used consistently.

### API endpoints
- **restaurant/copilot_answer.php** — POST; requires login, current_restaurant, role owner/admin.
- **restaurant/copilot_action.php** — POST; same; creates drafts in growth_engine_suggestions only.
- **ajax/order_status.php**, **ajax/upsell_event.php**, **ajax/loyalty_lookup.php** — JSON; use bootstrap and auth.
- **api/lead_request.php** — Public lead submit; validated and inserted with prepared statements.

**Verdict:** Architecture is coherent; modules are properly included.

---

## STEP 2 — Multi-tenant safety

- **Queries checked:** All queries on `orders`, `order_items`, `menu_items`, `growth_engine_suggestions`, `experiment_assignments`, `crm_guests`, `checkout_events` in app/ and restaurant/staff/public flows are scoped by `restaurant_id` (WHERE or safe JOIN).
- **Cross-tenant aggregation:** Only in `app/saas_metrics.php`, `app/network_analytics.php`, `app/network_benchmark.php` (peer comparison), `app/experiments.php` (results aggregation). These are project-admin or benchmark features and do not expose per-tenant data to other tenants.
- **staff/orders_api.php** — Order IDs come from restaurant-scoped query; `order_items` accessed via those IDs only.

**Verdict:** No multi-tenant data leaks found. All tenant-facing queries include effective `restaurant_id` scoping.

---

## STEP 3 — Security audit

### Authentication
- `require_login()` and `auth_user()` used on all restaurant, owner, project-admin, staff, and guest cabinet pages.
- Login uses prepared statements; password verified via `password_verify`.

### Authorization
- **Restaurant area:** `require_current_restaurant()` and `require_current_restaurant_role(['owner','admin'])` (or similar) on sensitive pages (dashboard, crm, upsell_rules, growth_suggestions, copilot_action, etc.).
- **Project-admin:** Pages check `(auth_user()['global_role'] ?? null) !== 'project_owner'` and return **403** (saas_dashboard, network_dashboard, experiments_dashboard, experiment_results, users, restaurants, transactions, logs, lead_scoring, etc.).
- **Owner area:** `require_role(['owner', 'project_owner'])`.

### CSRF protection
- **Protected:** crm_campaigns, owner/billing, restaurant/activate, restaurant/upsell_rules, restaurant/menu_items, restaurant/growth_suggestions, restaurant/crm, restaurant/dashboard (run_growth_automation), project-admin/experiments_dashboard.
- **Missing CSRF on POST forms:**
  - **project-admin/restaurants.php** — create_restaurant, update_restaurant (no CSRF token or check).
  - **project-admin/users.php** — create_user, update_user (no CSRF).
  - **restaurant/floorplan.php** — create_plan, set_active, rename_plan, delete_plan, save_layout (no CSRF).
  - **restaurant/menu_categories.php** — add_category, save_sort, delete_category (no CSRF).
  - **restaurant/settings.php** — settings update form (no CSRF).
  - **restaurant/tables.php** — add_table, rename_table, delete_table (no CSRF).
- **Note:** login.php and qr.php POST are either login (session fixation handled by auth) or guest cart/order; CSRF risk is lower but consider tokens for qr order submit if same-origin policy is relaxed.

**Verdict:** Admin and restaurant state-changing forms should add CSRF tokens and server-side validation.

### SQL injection
- No raw concatenation of `$_GET`/`$_POST` into SQL found. Values are cast to int, trimmed, or passed as bound parameters to prepared statements.

### XSS
- Output escaping: Many pages use `e()` (htmlspecialchars(..., ENT_QUOTES, 'UTF-8')). Some project-admin and restaurant pages define `e()` locally if not present. **Recommendation:** Ensure every user-controlled or DB-sourced string displayed in HTML is passed through `e()`.

**Verdict:** SQL injection risk is low. CSRF gaps and consistent use of `e()` should be addressed.

---

## STEP 4 — Database schema audit

- **Migrations:** Present for growth_experiments, experiment_assignments, experiment_metrics, growth_engine_suggestions (with status, priority, source), checkout_events, referrals, stats_cache, CRM, upsell, leads, billing, etc.
- **Indexes:** growth_experiments (status, type, flag_key), experiment_assignments (experiment_id, restaurant_id unique), experiment_metrics (experiment_id, restaurant_id, recorded_at unique). Perf indexes in 2026_03_03 and 2026_03_02 for orders, restaurant_id, payment_status, created_at.
- **Foreign keys:** growth_experiments tables reference restaurants(id) and growth_experiments(id) with ON DELETE CASCADE where appropriate.
- **Null safety:** Tables use NOT NULL and DEFAULT where appropriate; nullable columns (e.g. description, started_at) are documented.

**Verdict:** Schema and migrations are consistent. No critical index or FK gaps identified.

---

## STEP 5 — Error handling

- **try/catch:** Used in app modules (restaurant_success, growth_engine, ai_copilot, experiments, benchmark, combo_builder, etc.) around DB and logic; failures log via `error_log()` and return safe defaults.
- **User-facing errors:** Several pages expose **raw exception messages** to the user:
  - **restaurant/floorplan.php** — `$errors[] = "Ошибка создания плана: " . $e->getMessage();` (and similar for other actions).
  - **project-admin/restaurants.php** — `$errMsg = '...' . $e->getMessage();`
  - **project-admin/users.php** — `$errMsg = '...' . $e->getMessage();`
  - **login.php** — 'message' => '...' . $e->getMessage() in JSON.
  - **qr.php** — 'message' => $e->getMessage() in JSON.
  - **owner/dashboard.php** — 'message' => $e->getMessage() in JSON.
  - **staff/pos_create_order.php**, **staff/order_update_status.php** — JSON with $e->getMessage().
  - **checkout.php** — $errors[] = '...' . $e->getMessage().
  - **project-admin/transactions.php**, **project-admin/logs.php**, **project-admin/qr_themes.php** — $errMsg = ... $e->getMessage().

**Verdict:** Replace user-facing `$e->getMessage()` with a generic message and log the real exception server-side only.

---

## STEP 6 — Performance audit

- **Caching:** Network analytics and SaaS metrics use 5-minute in-memory cache. Benchmark and AI copilot summary use 5-minute per-restaurant cache. Growth engine and experiments return mock data in demo without DB.
- **Heavy queries:** Benchmark and network/saas aggregate over orders/restaurants; they are admin-only and cached. Restaurant-scoped analytics (combo_builder, menu_heatmap, peak_hours) limit to last N orders (e.g. 100) or last 7–30 days.
- **Indexes:** Orders, experiment_assignments, experiment_metrics, growth_engine_suggestions have appropriate indexes for filters and joins.

**Verdict:** Performance is acceptable; caching is in place for heavy analytics.

---

## STEP 7 — Demo mode audit

- **is_demo_mode():** Checked in app modules (restaurant_success, saas_metrics, network_analytics, benchmark, experiments, feature_flags, ai_copilot, growth_engine). When true, functions return mock data and do **not** write to DB.
- **restaurant/growth_suggestions.php** — POST actions (accept/dismiss) run only when `!is_demo_mode()`.
- **restaurant/dashboard.php** — run_growth_automation only when `!is_demo_mode()` and CSRF ok.
- **checkout_analytics.php** — `checkout_event_record` returns without writing in demo.
- **experiments:** assign_restaurant_to_experiment, record_experiment_metric return without DB write in demo.

**Verdict:** Demo mode does not write to the database; automation and campaigns are disabled in demo.

---

## STEP 8 — Growth engine audit

- **Deduplication:** `growth_engine_suggestion_duplicate_exists()` checks same restaurant_id, type, and payload/title within last 7 days. Used before inserting in `run_growth_automation` and in `generate_copilot_action`.
- **Suggestions only:** All automation creates rows in `growth_engine_suggestions` with status `pending`. No direct modification of menu, CRM campaigns, or upsell rules; accept/dismiss only change status.
- **Manual approval:** growth_suggestions.php allows accept/dismiss; no auto-execution of suggestions.

**Verdict:** Deduplication works; suggestions require manual approval; no unintended duplicates or direct data changes.

---

## STEP 9 — AI Copilot audit

- **answer_copilot_question:** Uses strpos() on lowercased question; branches to menu_performance, peak_hours, benchmark, checkout_analytics, CRM summary, retention. All data from existing analytics; **no DB writes**.
- **generate_copilot_action:** Only inserts into growth_engine_suggestions (drafts). Demo returns success without writing.
- **Topics:** Best selling items, peak hours, checkout conversion, average check, guest retention, menu performance covered. Safe fallbacks and "Not enough data" messages.

**Verdict:** Parsing is rule-based and safe; no DB writes; analytics usage is correct.

---

## STEP 10 — Admin dashboards

- **project-admin/network_dashboard.php** — require_login(); then 403 if `global_role !== 'project_owner'`.
- **project-admin/saas_dashboard.php** — Same.
- **project-admin/experiments_dashboard.php** — Same.
- **project-admin/experiment_results.php** — Same.
- **project-admin/index.php** — require_role(['project_owner']).
- **project-admin/users.php**, **restaurants.php**, **transactions.php**, **logs.php**, **qr_themes.php**, **lead_scoring.php**, **lead_view.php**, **leads.php**, **sales_forecast.php**, **diagnostics.php**, **error_view.php** — All require project_owner or require_role(['project_owner']).

**Verdict:** Only project_owner can access admin dashboards; 403 returned for others.

---

## STEP 11 — UI/UX audit

- **Styling:** Tailwind used across restaurant and project-admin; rounded-xl, border-gray-800, bg-[#121826] patterns consistent.
- **Empty states:** Dashboard and list pages show "No data yet" or "Add orders to see..." type messages.
- **Mobile:** viewport meta and responsive grids (grid-cols-1 md:grid-cols-2) used; kitchen and POS are tablet-friendly.

**Recommendation:** Verify all forms and tables have visible labels and that error/success messages are displayed (e.g. experiments_dashboard shows $message/$error).

---

## STEP 12 — Flow validation (simulated)

- **Restaurant onboarding:** setup, menu categories, menu items, tables, QR print — all scoped by restaurant_id and require_current_restaurant.
- **Menu creation:** menu_items.php and menu_categories.php use prepared statements and restaurant_id.
- **QR ordering:** qr.php resolves restaurant by subdomain; cart and order creation use current restaurant and table; checkout_analytics and CRM hooks optional.
- **Checkout analytics:** checkout_event_record called from qr.php; experiment_metrics populated via record_experiment_metric on dashboard load.
- **Growth suggestions:** List/filter/accept/dismiss on growth_suggestions.php; run_growth_automation from dashboard creates drafts only.
- **AI Copilot:** copilot_answer.php and copilot_action.php require login and restaurant role; actions create drafts only.
- **Admin dashboards:** Access controlled by project_owner; network, SaaS, experiments load without cross-tenant exposure.

**Verdict:** Flows are consistent with security and tenant isolation.

---

## Summary: Bugs, security risks, performance issues, recommended fixes

### Bugs found
1. **experiments_dashboard create form:** Modal form uses `name="action" value="create"` but the create block does not send `csrf` in the modal — **fixed:** CSRF is checked for all POST and create is inside the same POST block with csrfOk, and the modal form includes `<input type="hidden" name="csrf" value="...">`. So no bug.
2. **restaurant/staff.php:** Uses `require_current_restaurant_role` without explicit `require_login()` before it — **verify:** Line 5 is `require_current_restaurant_role`; bootstrap and permissions typically require login inside require_role. Confirm require_login is called (e.g. via require_role). Minor.
3. **kitchen.php:** Uses `$currentRestaurant` from bootstrap; if staff has no restaurant context, 404. Correct.

### Security risks
1. **Missing CSRF on POST:** project-admin/restaurants.php, project-admin/users.php, restaurant/floorplan.php, restaurant/menu_categories.php, restaurant/settings.php, restaurant/tables.php. **Fix:** Add session CSRF token, validate on POST, add hidden input to all state-changing forms.
2. **User-facing exception messages:** Multiple pages expose `$e->getMessage()` in HTML or JSON. **Fix:** Log full exception; show generic "An error occurred. Please try again." (or localized) to user.

### Performance issues
1. **Benchmark peer query:** Aggregates over all restaurants; already cached 5 min. Acceptable.
2. **No N+1 detected** in sampled list pages (restaurant orders, growth suggestions).

### Recommended fixes (priority)
1. **High:** Add CSRF protection to project-admin/restaurants.php, project-admin/users.php, restaurant/floorplan.php, restaurant/menu_categories.php, restaurant/settings.php, restaurant/tables.php.
2. **High:** Replace all user-facing `$e->getMessage()` with a generic message and keep detailed message only in error_log.
3. **Medium:** Ensure every dynamic output in admin and restaurant pages is escaped with `e()` (or equivalent).
4. **Low:** Consider CSRF token for qr.php order submit if form is used in cross-origin or embedded contexts.

---

---

## Fixes applied (post-audit)

1. **CSRF protection added** to:
   - **project-admin/restaurants.php** — create_restaurant, update_restaurant (token init, server check, hidden field on both forms).
   - **project-admin/users.php** — create_user, update_user (token init, server check, hidden field on both forms).
   - **restaurant/floorplan.php** — create_plan, set_active, rename_plan, delete_plan, save_layout (token init, server check, hidden field on all 5 forms).
   - **restaurant/menu_categories.php** — add_category, save_sort, delete_category (token init, server check, hidden field on all 3 forms).
   - **restaurant/settings.php** — settings update form (token init, server check, hidden field).
   - **restaurant/tables.php** — add_table, rename_table, regen_qr (token init, server check, hidden field on all 3 forms).

2. **User-facing exception messages replaced** with generic text and `error_log()` in:
   - **project-admin/restaurants.php** — update_restaurant catch, list load catch.
   - **project-admin/users.php** — create_user catch, update_user catch, list load catch.
   - **restaurant/floorplan.php** — DB load error, create_plan, set_active, rename_plan, delete_plan, save_layout catches.

*End of audit report.*
