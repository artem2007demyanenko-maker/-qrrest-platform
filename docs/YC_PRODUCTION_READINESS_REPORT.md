# YC Production Readiness Check — QR Restaurant SaaS

**Evaluation date:** 2026-03-02  
**Scope:** Full technical and architectural analysis for production deployment and scaling to hundreds/thousands of restaurants.

---

## STEP 1 — Architecture Review

### Core modules (app/)

| Layer | Modules | Role |
|-------|---------|------|
| **Core** | `bootstrap.php`, `db.php`, `auth.php`, `security.php`, `error_handler.php`, `subdomain.php`, `demo.php`, `permissions.php`, `helpers.php` | Request lifecycle, DB, auth, tenant resolution |
| **Analytics** | `menu_performance.php`, `menu_heatmap.php`, `peak_hours.php`, `table_turnover.php`, `checkout_analytics.php`, `combo_builder.php`, `network_benchmark.php`, `dashboard_intel.php`, `revenue_stats.php` | Per-restaurant analytics; last N orders / 7–30 days |
| **Growth** | `growth_engine.php`, `retention_suggestions.php`, `return_prediction.php` | Opportunities, automated actions, suggestions (drafts only) |
| **CRM** | `crm_repo.php`, `crm_campaign_repo.php` | Guests, campaigns, outbox |
| **AI Copilot** | `ai_copilot.php` | Summary, recommendations, answer_copilot_question (read-only + draft suggestions) |
| **Network / SaaS** | `network_analytics.php`, `saas_metrics.php` | Platform-wide metrics; project_owner only |
| **Billing** | `billing.php`, `stripe_billing.php` | Subscriptions, plans, trials, renewals |
| **Experiments** | `experiments.php`, `feature_flags.php` | A/B tests, feature flags, assignments, metrics |
| **Other** | `onboarding_progress.php`, `referral_repo.php`, `upsell_*`, `loyalty*`, `guest_*`, `menu_import.php`, `growth.php`, `stats.php`, `stats_cache.php` | Onboarding, referrals, upsell, loyalty, stats |

### Entry points (public_html/)

- **restaurant/** — Dashboard, CRM, menu, tables, floorplan, orders, growth suggestions, copilot, revenue, settings, activate, etc. All use `require_login()` + `require_current_restaurant()` + role checks.
- **staff/** — Kitchen, POS, orders API, order status, loyalty. Use `require_login()` and `user_has_restaurant_role(..., ['staff','admin','owner'])`; restaurant context from subdomain.
- **project-admin/** — Index, restaurants, users, leads, transactions, logs, network_dashboard, saas_dashboard, experiments_dashboard, experiment_results, etc. All require `project_owner`.
- **owner/** — Owner dashboard, billing, growth, report. Use `require_role(['owner', 'project_owner'])`.
- **guest/** — Wallet, cabinet, login, register. Guest-specific auth.
- **ajax/** — order_status, upsell_event, loyalty_lookup. JSON APIs with auth.
- **api/lead_request.php** — Public lead form; validated input, prepared statements.

### Modularity and coupling

- **Modular:** Analytics and growth are separate files; dashboard and pages require them on demand. No circular requires observed.
- **Bootstrap** loads a fixed set of core modules (db, auth, permissions, etc.); optional features (e.g. growth_engine, ai_copilot) are required only where used.
- **Bottlenecks:** All analytics and growth run in-request; no queue. Heavy dashboards (e.g. project-admin index with multiple queries) could slow under load; caching (5 min) is used for network/SaaS/benchmark/copilot summary.

**Verdict:** Architecture is modular and maintainable. No critical tight coupling; scaling will depend on DB and optional move to background jobs for heavy analytics.

---

## STEP 2 — Multi-tenant safety

- **Tenant scoping:** All app modules that query tenant data use `WHERE restaurant_id = :rest` (or equivalent) or operate on order/item sets derived from a restaurant-scoped query. Verified across combo_builder, menu_heatmap, peak_hours, table_turnover, checkout_analytics, crm_repo, crm_campaign_repo, growth_engine, experiments, feature_flags, dashboard_intel, restaurant_success, ai_copilot, upsell_*, onboarding_progress, etc.
- **Cross-tenant aggregation:** Only in:
  - `saas_metrics.php` — MRR, growth, churn, feature adoption (platform-wide).
  - `network_analytics.php` — Totals and averages across restaurants.
  - `network_benchmark.php` — Peer AOV/orders (aggregate by restaurant_id, no per-tenant leak).
  - `experiments.php` — `get_experiment_results()` aggregates by variant for admin.
- **Access control:** Network and SaaS dashboards are in project-admin and gated by `global_role === 'project_owner'`; they never serve per-restaurant data to restaurant users.

**Verdict:** Strict tenant isolation; no cross-tenant data leaks. Network analytics are platform-owner only.

---

## STEP 3 — Authentication and authorization

- **require_login():** Used on all restaurant, staff, owner, project-admin, and guest-cabinet entry points. Session and auth are started in bootstrap.
- **require_current_restaurant():** Used on all restaurant/* pages that need `$currentRestaurant`; ensures subdomain context.
- **require_current_restaurant_role(['owner','admin']):** Used for sensitive restaurant actions (dashboard, CRM, upsell rules, growth suggestions, copilot action, revenue, menu items, etc.).
- **require_restaurant_role($restaurantId, ['staff','admin','owner']):** Used for staff (kitchen, POS, orders_api) so staff cannot access owner-only features.
- **require_role(['project_owner']):** Used for project-admin index, diagnostics, error_view, sales_forecast, leads, lead_scoring, lead_view. Other project-admin pages check `(auth_user()['global_role'] ?? null) !== 'project_owner'` and return 403.
- **project_owner:** Bypasses restaurant role checks in `user_has_restaurant_role` and can access all restaurants; project-admin UI is only on main domain.

**Verdict:** Permission system is consistent. Staff cannot access owner features; restaurant cannot access network analytics; project-admin is restricted to project_owner.

---

## STEP 4 — Data integrity

- **Transactions:** Used for multi-step writes in:
  - `restaurant/floorplan.php` (create_plan, set_active, rename_plan, delete_plan).
  - `project-admin/restaurants.php` (create_restaurant), `project-admin/users.php` (update_user).
  - `billing.php`, `onboarding_repo.php`, `menu_import.php`, `growth.php`, `pos_create_order.php`, `checkout.php`, `loyalty_core.php`, `guest_loyalty.php`.
- **Safe inserts:** Prepared statements with bound parameters throughout; no raw concatenation of user input into SQL.
- **Duplicate prevention:** Growth engine uses `growth_engine_suggestion_duplicate_exists()` (same restaurant, type, payload/title within 7 days) before inserting suggestions. Experiment assignments use unique (experiment_id, restaurant_id).
- **Idempotency:** Experiment assignment is “assign only once”; feature-flag checks are read-only. Growth automation and copilot actions only insert suggestions (no automatic execution).
- **Automation:** Growth engine and copilot only create rows in `growth_engine_suggestions` with status `pending`; no automatic modification of menu, CRM, or upsell data.

**Verdict:** Critical write paths use transactions; inserts are safe; deduplication and approval workflow prevent inconsistent automation.

---

## STEP 5 — Growth engine safety

- **Deduplication:** `growth_engine_suggestion_duplicate_exists()` prevents duplicate suggestions (restaurant_id, type, payload/title, 7 days). Used in `run_growth_automation()` and `generate_copilot_action()`.
- **Approval workflow:** All suggestions are created with status `pending`. Accept/dismiss in `growth_suggestions.php` only updates status; no automatic execution of campaign send, menu change, or upsell rule creation.
- **No automatic execution:** `run_growth_automation()` only INSERTs into `growth_engine_suggestions`. No code path automatically sends campaigns or modifies menu/upsell rules from suggestions.

**Verdict:** Suggestion deduplication and status handling are correct; all growth actions require owner approval.

---

## STEP 6 — AI Copilot safety

- **answer_copilot_question():** Reads from menu_performance, peak_hours, benchmark, checkout_analytics, CRM summary, retention; returns strings only. No DB writes.
- **get_ai_copilot_summary() / get_ai_copilot_recommendations():** Read-only; use existing analytics.
- **generate_copilot_action():** Only inserts into `growth_engine_suggestions` (drafts). Allowed actions: create_campaign, create_combo, promote_menu_item, create_upsell — all create pending suggestions only.
- **User input:** Questions are lowercased and matched via strpos() to topic keywords; no eval or direct SQL from question text. Safe fallbacks (“Not enough data”) returned when analytics lack data.

**Verdict:** AI Copilot never writes except to create suggestions; no privileged operations triggered by questions.

---

## STEP 7 — Analytics performance

- **Menu performance / heatmap / peak hours / table_turnover:** Queries limit by `restaurant_id` and time window (e.g. last 7–30 days); combo_builder uses last 100 orders. Indexes exist on orders (restaurant_id, payment_status, created_at), order_items, menu_items.
- **Checkout analytics:** Uses `checkout_events` with indexes on (restaurant_id, event_type, created_at).
- **CRM metrics:** Queries scoped by restaurant_id; crm_guests, crm_visits, crm_outbox have appropriate structure.
- **Network analytics:** Multiple aggregations over orders, crm_guests, checkout_events; **cached 5 minutes** via in-memory cache. Admin-only.
- **Caching in place:** Network overview, SaaS MRR/growth/churn/insights, benchmark (per restaurant), AI copilot summary (per restaurant) use 5-minute TTL. Stats_cache table exists for user-scoped stats (dashboard intel, etc.).

**Risks:** At very high order volume (e.g. 10k/day), per-restaurant analytics that scan last 100 orders or 30 days may need tighter limits or pre-aggregation. No evidence of missing indexes on hot paths.

**Verdict:** Analytics are bounded and cached where heavy; no critical slow-query pattern identified. Recommend monitoring query time at scale.

---

## STEP 8 — Scalability

| Scale | Assessment |
|-------|------------|
| **100 restaurants** | Current design is sufficient: per-tenant queries, 5-min cache for admin metrics, single DB. |
| **1000 restaurants** | Same architecture can hold if DB and PHP workers are adequate. Admin dashboards (network, SaaS) do full scans; 5-min cache reduces repeated load. Consider read replica for admin analytics. |
| **10k daily orders** | Orders table grows ~10k rows/day. Indexes on (restaurant_id, created_at), (restaurant_id, payment_status, order_status) support list and analytics. Order_items will grow proportionally; ensure batch size limits (e.g. LIMIT 100) on list APIs. |
| **Real-time analytics** | No real-time pipeline; dashboard and kitchen refresh on load or polling (e.g. staff/orders_api). For “real-time” feel, current polling is acceptable; true real-time would require WebSockets or similar. |

**Bottlenecks:** (1) All work is request-scoped — no queue for growth suggestions or CRM outbox send; cron_runner exists for outbox and lead scoring. (2) In-memory cache is per-process; multi-instance deployments will have separate caches (acceptable for 5-min TTL). (3) Single DB; at very high scale, read replicas and/or sharding would be needed.

**Verdict:** Can support hundreds to low thousands of restaurants and moderate order volume with current design; scaling to very high scale will require background jobs and possibly DB scaling.

---

## STEP 9 — Background jobs

- **Existing:** `scripts/cron_runner.php` — lock file, inserts into `cron_runs`, runs CRM outbox processing and lead scoring refresh, updates cron_runs on success/failure. Health and ready endpoints can report `recent_cron_ok` from `cron_runs` table.
- **Should be async (currently in-request or cron):**
  - **CRM outbox:** Already in cron_runner; good.
  - **Analytics aggregation:** Currently on-demand with caching; for 1000+ restaurants, consider nightly or hourly aggregation into summary tables.
  - **Growth suggestions:** Currently created on dashboard “Create drafts” or on copilot action; acceptable for moderate scale; at scale could be a scheduled job per restaurant.
  - **Campaign generation:** Not automated; user creates campaigns; sending is likely via cron (crm_outbox).

**Recommendation:** Keep current cron for outbox and lead scoring. Add a queue (e.g. Redis or DB queue) if you need to offload heavy analytics or growth runs from web requests.

**Verdict:** Minimal but present background processing (cron_runner); sufficient for Beta / early Production. Queue recommended for higher scale.

---

## STEP 10 — Database design

- **Migrations:** 33 migration files; growth_experiments, experiment_assignments, experiment_metrics, growth_engine_suggestions, checkout_events, cron_runs, app_error_logs, referrals, billing, CRM, upsell, leads, stats_cache, etc.
- **Indexing:** Perf migrations add indexes on orders (restaurant_id, payment_status, created_at, order_status), experiment_assignments (experiment_id, restaurant_id unique), experiment_metrics (experiment_id, restaurant_id, recorded_at unique), growth_engine_suggestions (restaurant_id, status), (restaurant_id, type, created_at). checkout_events indexed on (restaurant_id, event_type, created_at).
- **Foreign keys:** growth_experiments tables reference restaurants(id) and growth_experiments(id) with ON DELETE CASCADE where appropriate. Not every table has FKs (e.g. orders.restaurant_id may not have FK in some setups); consider adding for referential integrity.
- **Table sizes / growth:** orders and order_items will dominate growth. Soft limits (e.g. LIMIT 100 on order list) and date-bounded analytics keep queries bounded. No partitioning observed; consider time-based partitioning for orders at very large scale.

**Verdict:** Schema and indexing support current feature set and tenant isolation. FKs and partitioning are the next refinements for long-term production.

---

## STEP 11 — SaaS business logic

- **MRR:** Sum of `plans.price_month` for active subscriptions (subscriptions.status = 'active', plans.is_active = 1, price_month > 0). Cached 5 min.
- **ARR:** `MRR * 12`. Correct.
- **Trial conversion:** Trial users = restaurants with owner_user_id set and no active paid subscription; trial_conversion_rate = active_subscriptions / (active_subscriptions + trial_users) * 100. Logic is consistent.
- **Restaurant lifecycle:** Restaurants have owner_user_id, status (active/blocked); billing_assert_can_create_restaurant checks plan limits; subscriptions tied to user_id; no orphaned restaurants in normal flow.
- **Billing periods:** billing.php and stripe_billing handle current_period_end, renewal (billing_renew_if_needed_cronlike), and invoices.

**Verdict:** MRR/ARR and trial conversion are correct; subscription and restaurant lifecycle are coherent.

---

## STEP 12 — Security review

- **SQL injection:** No concatenation of `$_GET`/`$_POST` into SQL; prepared statements and bound parameters used throughout.
- **XSS:** Output escaping via `e()` (htmlspecialchars) is used consistently in templates; ensure every dynamic output in HTML is passed through `e()`.
- **CSRF:** CSRF tokens and validation are present on project-admin (restaurants, users, experiments_dashboard), restaurant (floorplan, menu_categories, settings, tables, growth_suggestions, crm_campaigns, upsell_rules, menu_items, activate, dashboard run_growth_automation), and owner/billing. **restaurant/orders.php** POST (set_status, set_payment_status) should add CSRF (recommended).
- **Access control:** See Step 3; no broken access control identified.
- **File uploads:** Menu import and upload_helpers; ensure MIME/size checks and storage outside webroot or via safe delivery.

**Verdict:** Security posture is good; remaining improvement is CSRF on orders.php and consistent escaping.

---

## STEP 13 — Production stability

- **Error handling:** App modules use try/catch around DB and return safe defaults (empty arrays, 0, false); errors logged via error_log(). Global exception handler (stability_exception_handler) and error handler set in bootstrap.
- **User-facing errors:** Several places previously exposed `$e->getMessage()`; part of them have been replaced with generic messages and server-side logging (e.g. restaurants, users, floorplan). Remainder (login, qr, staff pos, checkout, some project-admin pages) should be reviewed to avoid leaking stack or DB messages.
- **Fallbacks:** Analytics and growth return empty/default structures when tables are missing or queries fail; demo mode returns mock data without DB writes.

**Verdict:** Error handling is generally robust; complete the replacement of user-facing exception messages for production hardening.

---

## STEP 14 — Code quality

- **Duplication:** Some repeated patterns (e.g. “if table exists then query” and “cache get/set”) across analytics modules; could be centralized in a small analytics service layer.
- **Large functions:** Some dashboard and project-admin pages have long procedural blocks; could be split into helpers for readability.
- **Separation of concerns:** Business logic lives in app/; UI in public_html/; separation is clear. Some HTML and logic mixed in same file (typical for PHP).
- **Naming:** Mixed English/Russian in UI strings; function and variable names are English. Inconsistent naming is minor.

**Verdict:** Code quality is adequate for production; refactoring can be incremental (extract helpers, centralize analytics).

---

## STEP 15 — YC readiness score and verdict

### Fix applied during this review

- **restaurant/orders.php:** Fixed parse error (missing closing `}` in `human_payment_status()`). File now passes `php -l`.

---

### YC readiness score: **72 / 100**

| Category | Score | Notes |
|----------|-------|------|
| Architecture | 85 | Modular, clear boundaries; no critical coupling |
| Multi-tenant safety | 95 | Strong isolation; network analytics restricted |
| AuthZ / security | 80 | CSRF and exception-message hardening incomplete |
| Data integrity | 85 | Transactions and deduplication in place |
| Growth / Copilot safety | 90 | Suggestions only; approval workflow |
| Analytics & performance | 75 | Caching helps; no queue for heavy tasks |
| Scalability | 65 | Single DB, in-request analytics; cron only |
| Database design | 80 | Good indexes; FKs and partitioning optional |
| SaaS logic | 85 | MRR/ARR/trials correct |
| Production stability | 75 | Good base; finish error-message sanitization |

---

### Major blockers (must fix for production)

1. **None critical.** The only syntax bug (orders.php) is fixed. No data-leak or auth bypass found.
2. **High priority:** Add CSRF to **restaurant/orders.php** (set_status / set_payment_status) and ensure all other state-changing POSTs have CSRF.
3. **High priority:** Replace remaining user-facing `$e->getMessage()` with generic messages and log details server-side only.

---

### Recommended fixes (before scaling)

1. **CSRF:** Protect **restaurant/orders.php** forms (and any other POST not yet protected).
2. **Error messages:** Audit login, qr, checkout, staff pos, project-admin transactions/logs/qr_themes and replace raw exception output with a generic message + error_log.
3. **Monitoring:** Add simple health/readiness checks (DB, cron status) and optional APM or slow-query logging for production.
4. **Background jobs:** Document cron_runner schedule (e.g. every 5–15 min) and consider a small queue for growth/analytics if you scale beyond ~500 restaurants.
5. **Database:** Add foreign keys where applicable; plan for read replica if admin dashboards become slow.

---

### Production readiness level

**Category: Beta SaaS**

- **Early Prototype** — Not applicable: multi-tenant, auth, and billing are in place.
- **Beta SaaS** — **Current state.** Suitable for controlled production (dozens to low hundreds of restaurants) with monitoring and the recommended fixes. Feature set (orders, CRM, growth, experiments, AI copilot, SaaS metrics) is broad and coherent.
- **Production Ready** — After CSRF and error-message hardening, plus operational runbook (cron, backups, health checks).
- **YC-Level Startup** — After scaling measures (queue, read replica, observability, incident process) and consistent polish (tests, docs, onboarding).

**Summary:** The platform is **production-viable for Beta** and can scale to hundreds of restaurants with the current architecture. Addressing CSRF and user-facing errors, then adding operational and scaling improvements, will move it toward “Production Ready” and “YC-Level Startup.”
