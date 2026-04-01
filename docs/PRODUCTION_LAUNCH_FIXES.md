## Production Launch Fixes — QR Restaurant SaaS

1. **Env guard added**
   - New `app/env_guard.php` validates configuration when `APP_ENV=production`.
   - Blocks startup if main domain/cookie domain/DB password/Stripe keys are obviously unsafe.
   - Logs details to `ENV_GUARD_ERROR` in `error_log`, but shows only a generic error page to users.

2. **HTTPS requirements**
   - `DEPLOY_VPS.md` and `deploy/nginx/ssl_readme.txt` describe enabling TLS for `yourdomain.com` and wildcard `*.yourdomain.com`.
   - Nginx template keeps HTTP and HTTPS blocks separate; production should always terminate TLS at nginx and keep `APP_PROTOCOL=https`.

3. **Request ID logging**
   - `app/bootstrap.php` now generates a per-request RID (`APP_REQUEST_ID`) exposed via `app_rid()`.
   - Global error handlers (`stability_*`) and `app_error_log()` include RID in structured log payloads.

4. **Backup hardening**
   - `BACKUP_RECOVERY.md` expanded with offsite backup recommendations and restore testing.
   - Example helper script `scripts/db_backup_offsite_example.sh` added (template for S3/rsync sync).

5. **Log rotation guidance**
   - Example logrotate config added in `deploy/logrotate/qr-rest.example` for `storage/logs/app.log` and cron logs.
   - Ops can copy/adjust it under `/etc/logrotate.d/` on the host to avoid unbounded log growth.

6. **DB/index tuning notes**
   - `docs/PRODUCTION_DB_TUNING.md` documents key tables (`orders`, `order_items`, `checkout_events`, `app_error_logs`, `cron_runs`, `growth_engine_suggestions`, experiments) and the indexes provided by migrations.
   - Includes guidance on what to verify with `EXPLAIN` and how to extend indexes conservatively if needed.

7. **Demo safety confirmation**
   - `is_demo_mode()` and existing guards ensure demo flows use mock data and avoid writes for growth, experiments, and analytics.
   - New hardening did not relax any demo checks; production write paths remain disabled for the demo subdomain.

