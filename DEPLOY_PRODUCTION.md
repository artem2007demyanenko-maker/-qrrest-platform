# Deploy Production — QR Restaurant SaaS

Пошаговая подготовка к развёртыванию в production.

## 1. Требования к серверу

- **PHP** 7.4+ (рекомендуется 8.x) с расширениями: pdo_mysql, json, mbstring, session, openssl.
- **MySQL** 5.7+ или MariaDB 10.3+.
- **Docker / Docker Compose** — для запуска приложения в контейнерах (как в локальной среде).
- **Домен** с настроенным DNS (например `app.example.com` и wildcard `*.app.example.com` для поддоменов ресторанов).
- **SSL**: reverse proxy (Nginx или Caddy) с TLS перед контейнером — обязательно для production (cookies secure, HSTS). Поддомены: `APP_HSTS_INCLUDE_SUBDOMAINS` включать только после wildcard TLS (см. `docs/DEPLOY_HTTPS.md`).

Рекомендация по reverse proxy:
- **Caddy**: автоматический HTTPS, минимальная конфигурация.
- **Nginx**: нужна отдельная настройка SSL (certbot и т.д.).

## 2. Переменные окружения

Задайте в `.env` или в среде контейнера:

| Переменная | Описание | Пример production |
|------------|----------|-------------------|
| APP_ENV | Окружение | production |
| APP_DEBUG | Отладочный режим (0/1) | 0 |
| APP_URL | Базовый URL приложения | https://app.example.com |
| APP_PROTOCOL | Протокол | https |
| APP_MAIN_DOMAIN | Основной домен (без протокола) | app.example.com |
| APP_COOKIE_DOMAIN | Домен для cookie (с точкой для поддоменов) | .app.example.com |
| DB_HOST | Хост MySQL | db или внешний хост |
| DB_PORT | Порт MySQL | 3306 |
| DB_NAME | Имя БД | qr_rest |
| DB_USER | Пользователь БД | — |
| DB_PASS | Пароль БД | — |
| APP_VERSION / GIT_SHA | Опционально, для /health | — |
| REDIS_HOST / REDIS_PORT | Опционально, Redis для rate limit | — |

Локально (lvh.me) можно не задавать — подставятся значения по умолчанию.

## 3. Поднятие контейнеров

Из корня проекта:

```bash
docker compose up -d
```

Убедитесь, что в docker-compose.yml (или .env) заданы production env (APP_ENV=production, APP_MAIN_DOMAIN, APP_COOKIE_DOMAIN, DB_* и т.д.).

## 4. Миграции

После первого запуска БД примените миграции (если в проекте есть папка миграций или один скрипт):

```bash
docker compose exec web php scripts/migrate.php
```

Проверка схемы: scripts/check_schema.php — exit 0 при полной схеме.

## 5. Cron

Настройте запуск раз в 5 минут:

```bash
*/5 * * * * cd /path/to/qr-rest && php scripts/cron_runner.php >> storage/logs/cron.log 2>&1
```

Примеры: см. scripts/cron_example.txt.

## 6. Проверка health и ready

- Health (лёгкая проверка, всегда 200): GET https://app.example.com/health.php — JSON с status, db, app_env.
- Ready (readiness): GET https://app.example.com/ready.php — status ok при доступной БД и критичных таблицах.

## 7. Backup

Регулярный backup БД: ./scripts/db_backup.sh. Файлы в `backups/` (или во внешнем томе). Подробнее: BACKUP_RECOVERY.md.

Рекомендация для production:

- синхронизировать дампы в offsite‑хранилище (S3/объектное хранилище, отдельный сервер) — см. `scripts/db_backup_offsite_example.sh`;
- хранить минимум 7–30 дней истории (в зависимости от SLA);
- регулярно проверять восстановление (restore) на staging.

## 8. Откат

1. Восстановить БД: ./scripts/db_restore.sh /path/to/dump.sql --yes
2. Проверить /health.php и /ready.php, затем smoke: bash scripts/smoke_http.sh
3. При откате кода — откатить деплой и при необходимости восстановить БД из backup.
