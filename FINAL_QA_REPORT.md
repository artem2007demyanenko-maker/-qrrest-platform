# Final QA Report — QR Restaurant SaaS

**Date:** 2026-03-02  
**Scope:** Ultimate Final Audit / Full Verify / Auto-Fix Pass

---

## 1. Overall status

**GREEN** — Critical flows verified; schema guards and domain/auth rules in place; no blocking issues found. Optional migrations may limit some features until applied; smoke and schema audit available for deployment verification.

---

## 2. What was audited

- **Static / route / file:** All `href`, `action`, form targets, redirects in saas, signup, login, restaurant/*, project-admin/*, owner/*, activate, setup, qr_print, diagnostics. Cross-check with existing files.
- **Domain / subdomain:** Main vs restaurant subdomain; redirect loops; safe_redirect usage; project-admin domain guard; signup/login/saas on main; restaurant panel on subdomain.
- **Schema safety:** Optional tables/columns (deleted_at, upsell_events, crm_visits, crm_outbox, lead_requests, billing, app_error_logs, cron_runs); DATETIME vs `''`; use of schema_guard and try/catch.
- **Auth / access:** require_login, require_role, require_restaurant_role on entry points; stripe_webhook and health/ready without auth; return_to / redirect after login.
- **Billing / Stripe:** Trial flow, billing banners, activate.php, Stripe disabled mode, webhook signature, no 500 when billing schema missing (billing.php uses schema_guard_billing_ready).
- **Diagnostics / monitoring:** app_error_logs, cron_runs, diagnostics.php, error_view.php; fallback when tables missing; context_json display.
- **Production readiness:** config.php, cookie domain, forwarded proto, env fallbacks.
- **Smoke:** health, ready, saas, signup, login, demo qr/offers/order_track, restaurant 302 list, project-admin 302 list, optional post-login 200s.
- **Link audit:** scripts/link_audit.sh — key URLs and status codes.
- **Schema audit:** scripts/schema_audit.php — critical/optional tables, exit 0/1.

---

## 3. Fixed issues (this pass)

| Area | Issue | Fix |
|------|--------|-----|
| **Schema** | `project-admin/index.php` used `r.deleted_at IS NULL OR r.deleted_at = ''` — fails if column missing or bad for DATETIME | Required schema_guard; use `schema_guard_restaurants_deleted_sql('r')` in recentRestaurants query. |
| **Schema** | `owner/report.php` same deleted_at usage in two queries | Required schema_guard; use `schema_guard_restaurants_deleted_sql('r')` in both branches. |
| **Schema** | `login.php` staff redirect query used `AND (r.deleted_at IS NULL)` in JOIN — fails if column missing | Require schema_guard when needed; use `schema_guard_restaurants_deleted_sql('r')` as JOIN fragment. |
| **Tooling** | No DB preflight script | Added `scripts/schema_audit.php`: checks critical (users, restaurants, orders) and optional tables; prints missing/ok; exit 1 if critical missing. |
| **Link audit** | error_view not in list | Added `project-admin/error_view.php?rid=test` to link_audit.sh. |

Previous pass fixes (retained): sidebar qr_print links on restaurant pages; revenue_stats try/catch for upsell_events and crm_visits/crm_outbox; smoke project-admin/ and restaurant/analytics_upsell 302.

---

## 4. Remaining limitations

- **Optional migrations:** CRM, leads (lead_notes, lead_tasks, lead_history), upsell_events, crm_campaigns, etc. — pages may 500 if those tables are missing. Revenue and billing entry points are guarded.
- **order_track.php:** Invalid `order_id` can show "Некорректные параметры"; smoke expects 200 or 404 for order_id=1, not 500.
- **Stripe:** Webhook and billing depend on env; smoke only checks webhook does not 500 on GET.
- **Smoke login block:** Post-login 200 checks require `SMOKE_EMAIL` and `SMOKE_PASS`.
- **config['app']['name']:** Not in config; diagnostics uses `?? 'QR-Rest Cloud'` — no change needed.

---

## 5. Verified routes

### Public / main (Host: main)

- `/health.php` → 200, body has `status`, `app_env`
- `/ready.php` → 200, body has `status`
- `/saas.php` → 200
- `/signup.php` → 200 (body: signup/регистрация/restaurant)
- `/login.php` → 200
- `/owner/dashboard.php` (unauthenticated) → 302 → login

### Public / demo subdomain

- `/qr.php?table_id=1` → 200
- `/qr_offers.php?table_id=1` → 200, body has `items`, no internal_error
- `/order_track.php?order_id=1` → 200 or 404 (not 500)

### Restaurant (subdomain, unauthenticated → 302)

- dashboard, setup, qr_print, revenue, crm, activate, import_menu, upsells, upsell_rules, analytics_upsell, crm_campaigns

### Project-admin (main host, unauthenticated → 302)

- `/project-admin/`, leads.php, lead_view.php?id=1, lead_scoring.php, sales_forecast.php, diagnostics.php, error_view.php?rid=test

---

## 6. Verified modules

| Module | Notes |
|--------|--------|
| setup / qr_print | Sidebars consistent; schema_guard where needed. |
| revenue | revenue_stats_get() safe when optional tables missing. |
| crm / campaigns | Pages and links present; backend may require tables. |
| leads / scoring / sales_forecast | project-admin routes 302 when not logged in. |
| Stripe / billing | schema_guard_billing_ready; webhook no-auth; no 500 when disabled. |
| diagnostics / error_view | Optional tables in try/catch; error_view shows "not found" when rid/id missing. |
| auth / login | project_owner → project-admin; staff → subdomain; deleted_at via schema_guard. |

---

## 7. Smoke coverage

- **Public main:** health 200 + status/app_env; ready 200 + status; saas 200; signup 200 + body substring; login 200.
- **Demo:** qr.php 200; qr_offers 200 + items, no internal_error; order_track 200 or 404, no invalid params.
- **Restaurant (no login):** dashboard, setup, qr_print, revenue, crm, activate, import_menu, upsells, upsell_rules, analytics_upsell, crm_campaigns → 302.
- **Project-admin (no login):** /, leads, lead_view?id=1, lead_scoring, sales_forecast, diagnostics, error_view?rid=test → 302.
- **With SMOKE_EMAIL/SMOKE_PASS:** post-login 200 for owner dashboard, billing, growth; restaurant upsells, activate, crm, upsell_rules, crm_campaigns, import_menu; project-admin leads, lead_scoring, diagnostics.

---

## 8. Production readiness notes

- **config.php:** APP_ENV, APP_MAIN_DOMAIN, APP_COOKIE_DOMAIN, APP_PROTOCOL (X-Forwarded-Proto), DB_*, STRIPE_*; local fallbacks for lvh.me.
- **safe_redirect:** Used in owner/billing, owner/dashboard, owner/growth, login; validates URL, blocks CRLF and external hosts.
- **Domain guard:** project-admin index, diagnostics, error_view, owner billing/dashboard/report redirect to main domain when host is not main.
- **health/ready:** Do not require auth or restaurant context; always 200 JSON; ready requires users, restaurants, orders.

---

## 9. Recommended next steps

1. Run smoke after every deploy: `docker compose exec -T web bash scripts/smoke_http.sh` (or `BASE_URL=... bash scripts/smoke_http.sh`).
2. Run link audit for quick URL check: `bash scripts/link_audit.sh`.
3. Run schema audit before/after migrations: `php scripts/schema_audit.php` (exit 1 if critical tables missing).
4. Apply all migrations (billing, CRM, leads, cron_runs, app_error_logs, etc.) in staging/production to enable full features.
5. Keep SMOKE_EMAIL/SMOKE_PASS in CI for post-login smoke checks.

---

*Generated as part of Ultimate Final Audit / Full Verify / Auto-Fix Pass.*
