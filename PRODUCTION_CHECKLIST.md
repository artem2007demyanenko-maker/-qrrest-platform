# Production Checklist — QR Restaurant SaaS

Перед и после выката в production пройдите чеклист.

- [ ] **Env заданы**: APP_ENV, APP_DEBUG, APP_URL, APP_PROTOCOL, APP_MAIN_DOMAIN, APP_COOKIE_DOMAIN, APP_HSTS, APP_HSTS_INCLUDE_SUBDOMAINS (см. `.env.production.example`)
- [ ] **APP_ENV=production**
- [ ] **APP_DEBUG=0** (debug выключен)
- [ ] **Cookie domain** задан и подходит для поддоменов (например `.app.example.com`)
- [ ] **App URL** задан (APP_URL), редиректы и ссылки используют его
- [ ] **DB credentials** заданы (DB_HOST, DB_NAME, DB_USER, DB_PASS) и корректны
- [ ] **Backups** настроены и хотя бы раз успешно выполнены
- [ ] **Restore** проверен на копии БД (см. BACKUP_RECOVERY.md)
- [ ] **Cron** настроен (cron_runner.php каждые 5 мин или по расписанию)
- [ ] **Health** OK: GET /health.php → 200, body содержит `status`, `app_env`, `app_protocol: https`, `cookie_domain` (см. docs/DEPLOY_HTTPS.md — production checks)
- [ ] **Ready** OK: GET /ready.php → 200, `status: ok`
- [ ] **Smoke** пройден: `bash scripts/smoke_http.sh` — все проверки PASS
- [ ] **Signup** работает (self-registration ресторана)
- [ ] **Activate** работает: /restaurant/activate.php, выбор тарифа
- [ ] **SaaS landing** открывается: /saas.php
- [ ] **SSL** включён на reverse proxy, cookie secure в production; поддомены: либо wildcard TLS + объединённый `server_name` в Nginx, либо осознанный этап только apex+www (см. `docs/DEPLOY_HTTPS.md`)
