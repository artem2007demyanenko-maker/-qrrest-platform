## FINAL LAUNCH CHECKLIST — QR Restaurant SaaS

1. **Security hardening completed**
   - Global error handler (`stability_*`) enabled in `app/bootstrap.php`.
   - No raw exception messages shown to end users (HTML or JSON).
   - Admin/project-owner tools avoid exposing SQL/errors directly.

2. **CSRF coverage**
   - Browser form POST pages in `restaurant/*`, `owner/*`, `project-admin/*`, and key `staff/*` screens use CSRF tokens:
     - Token stored in `$_SESSION['csrf']`, validated with `hash_equals`.
     - Hidden `<input name="csrf" ...>` present in forms for state-changing actions.

3. **Raw error exposure removed**
   - User-facing pages show generic, friendly messages only.
   - Detailed context (exception type, message, file, line, RID) is written to logs (`error_log`, `STABILITY_ERROR`, `app_error_log`, `logs` table).

4. **Demo safety verified**
   - `is_demo_mode()` used in growth, analytics, experiments, and CRM paths to avoid DB writes in demo.
   - Demo QR/menu/orders use demo data only; no real orders, campaigns, or billing changes are persisted.

5. **Cron configured**
   - `scripts/cron_runner.php` scheduled every 5 minutes (see `deploy/cron/cron_example.prod.txt`).
   - Recent successful cron runs visible in `cron_runs` table and project-admin diagnostics.

6. **Backups configured**
   - `scripts/db_backup.sh` scheduled daily; backups stored in the configured backups volume/directory.
   - At least one restore test completed using `db_restore.sh` on a staging database.

7. **Health/ready verified**
   - `/health.php` returns JSON with `status: ok` or `degraded` and DB checks.
   - `/ready.php` returns `status: ok` (users/restaurants/orders tables present and DB reachable).
   - External uptime monitors use these endpoints.

8. **Migrations applied**
   - All SQL migrations in `app/migrations/` applied to the production database.
   - `ready.php` and project-admin diagnostics report no critical missing tables.

9. **Billing/Stripe verified**
   - Stripe env vars (`STRIPE_ENABLED`, keys, webhook secret, price IDs) set correctly for the target environment.
   - `stripe_webhook.php` reachable over HTTPS; webhook events processed without 5xx.
   - Trial → paid upgrade path tested via `/restaurant/activate.php` when billing is enabled.

10. **Final smoke/manual checks**
    - `scripts/smoke_http.sh` passes against production base URL and hosts.
    - Manual flows verified on production:
      - Signup → login → restaurant dashboard.
      - QR ordering and checkout.
      - Staff order handling / status updates.
      - CRM basics and growth suggestions (non-demo).
      - Project-admin diagnostics, logs, and transactions.

