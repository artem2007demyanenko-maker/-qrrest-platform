# Production Deploy on VPS (QR Restaurant SaaS)

Пошаговый деплой на VPS с Docker, Nginx и SSL.

## 1. Требования к серверу

- **OS**: Ubuntu 22.04 LTS (или 20.04)
- **Docker**: установленный Docker Engine
- **Docker Compose**: плагин `docker compose` (v2) или standalone
- **Домен**: зарегистрированный домен, указывающий на сервер (A‑запись и при необходимости wildcard)

Установка Docker (если ещё нет):

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# logout/login, затем:
docker compose version
```

## 2. Подготовка DNS

1. **A‑запись** для основного домена:
   - `yourdomain.com` → IP вашего VPS

2. **Wildcard поддомен** (для ресторанов):
   - `*.yourdomain.com` → тот же IP VPS

Пример (условно):
- `yourdomain.com` → 203.0.113.10
- `*.yourdomain.com` → 203.0.113.10

Проверка: `dig yourdomain.com`, `dig demo.yourdomain.com` — оба указывают на ваш IP.

## 3. Создание .env.production

На сервере в каталоге проекта:

```bash
cp .env.production.example .env.production
nano .env.production
```

Обязательно задать:

- `APP_MAIN_DOMAIN=yourdomain.com`
- `APP_COOKIE_DOMAIN=.yourdomain.com`
- `APP_URL=https://yourdomain.com`
- `APP_PROTOCOL=https`
- `APP_HSTS` / `APP_HSTS_INCLUDE_SUBDOMAINS` / `APP_HSTS_PRELOAD` — см. `.env.production.example` и `docs/DEPLOY_HTTPS.md` (`includeSubDomains` только после wildcard TLS)
- `DB_PASS` — надёжный пароль
- Stripe ключи и Price ID, если используется оплата

Сохранить и выйти.

## 4. Запуск

Из корня проекта:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
```

Проверка контейнеров:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production ps
```

Должны быть в состоянии Up: `app`, `db`, `nginx`.

## 5. Миграции

После первого запуска БД:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production exec app php scripts/check_schema.php
```

Если в проекте есть скрипт миграций (например `scripts/migrate.php` или папка `app/migrations`), применить их по документации проекта (часто через тот же контейнер `app`).

## 6. Проверка health и ready

- **Health:**  
  `curl -s https://yourdomain.com/health.php`  
  Ожидается JSON с `"status"` и `"app_env":"production"`.

- **Ready:**  
  `curl -s https://yourdomain.com/ready.php`  
  Ожидается `"status":"ok"` при доступной БД и нужных таблицах.

Если пока только HTTP (без SSL): заменить `https://` на `http://` и использовать IP или домен.

## 7. Настройка SSL (HTTPS)

Актуальная пошаговая инструкция: **`docs/DEPLOY_HTTPS.md`** (apex + www через HTTP-01, wildcard через DNS-01, раздельные HTTPS-блоки в Nginx, env `APP_PROTOCOL`, `APP_HSTS*`, проверки `health.php`).

Кратко:

1. `mkdir -p deploy/nginx/certs deploy/nginx/certbot`
2. Выпустить сертификат Let's Encrypt (webroot или DNS), положить `fullchain.pem` и `privkey.pem` в `deploy/nginx/certs/`.
3. Конфиг Nginx: `deploy/nginx/default.conf` (HTTPS уже включён в репозитории).
4. `docker compose -f docker-compose.prod.yml --env-file .env.production exec nginx nginx -s reload`
5. Проверка: `https://qrrest-menu.ru/health.php` и при наличии wildcard — поддомены ресторанов.

## 8. Cron

На хосте добавить в crontab (`crontab -e`):

```bash
*/5 * * * * cd /path/to/project && docker compose -f docker-compose.prod.yml --env-file .env.production exec -T app php scripts/cron_runner.php >> /var/log/qr-rest-cron.log 2>&1
```

Заменить `/path/to/project` на реальный путь к проекту (например `/opt/qr-rest`). Доп. примеры: `deploy/cron/cron_example.prod.txt`.

## 9. Backup

Ручной бэкап БД:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production exec app bash scripts/db_backup.sh
```

Файлы появятся в `backups/` (внутри тома/проекта). Рекомендуется настроить ежедневный cron (см. пример в `deploy/cron/cron_example.prod.txt`) и при необходимости копировать дампы на внешнее хранилище.

Подробнее: `BACKUP_RECOVERY.md`.

## 10. Восстановление (restore)

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production exec -T app bash scripts/db_restore.sh /var/www/html/backups/qr_rest_YYYYMMDD_HHMMSS.sql --yes
```

Путь к файлу — внутри контейнера. Если дамп лежит на хосте, смонтировать каталог в compose или скопировать в контейнер.

## 11. Обновление (deploy новой версии)

1. На сервере: `cd /path/to/project`, обновить код (git pull или загрузка файлов).
2. Пересобрать и перезапустить:
   ```bash
   docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
   ```
3. При необходимости выполнить миграции (см. п. 5).
4. Проверить `/health.php` и `/ready.php`.

Или использовать скрипт (если настроен): `./scripts/deploy_prod.sh`.

## 12. Откат (rollback)

1. Откатить код (git checkout нужный тег/коммит).
2. Выполнить:
   ```bash
   docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
   ```
3. Если менялась схема БД — восстановить БД из backup (п. 10) и снова проверить health/ready.

---

**Важно:** локальный режим (lvh.me, обычный `docker-compose.yml`) не меняется. Production-деплой использует только `docker-compose.prod.yml` и `.env.production`.
