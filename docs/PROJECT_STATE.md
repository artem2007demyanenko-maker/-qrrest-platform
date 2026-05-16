# PROJECT_STATE — техническая карта (QR REST)

Краткий снимок для solo-разработчика. Обновляйте после крупных релизов.

---

## Что реально работает (по дизайну кода + заявленный прод)

- Гостевое меню, корзина, оформление заказа — ядро в `public_html/qr.php`, `checkout.php`, связанные ajax.
- Трекинг заказа — `public_html/order_track.php` и ajax статусов.
- Кабинет владельца/админа — `public_html/restaurant/*`, `public_html/owner/*`, auth через `app/auth.php` + bootstrap.
- Управление меню — `public_html/restaurant/menu_manage.php`, загрузки — `app/upload_helpers.php`, файлы под `public_html/uploads/`.
- Лояльность / гость — `app/guest_*.php`, `app/loyalty*.php`, страницы `public_html/guest/*`.
- Upsell — `app/upsell_*.php`, UI в restaurant/public частях.
- Биллинг / trial — `app/billing.php`, `app/stripe_billing.php`, настройки в env.
- Cookie consent — инжект через `app/cookie_consent.php` + `app/bootstrap.php` (HTML-страницы).
- Health — `public_html/health.php` (JSON, БД, деградации по таблицам).

---

## Частично / зависит от окружения

- **Поддомены SaaS по HTTPS:** в репозитории `deploy/nginx/default.conf` — один HTTPS-блок с `*.MAIN_DOMAIN` в `server_name` при wildcard в fullchain; после renew сверять серты и `nginx -T` на VPS (`docs/DEPLOY_SOURCE_OF_TRUTH.md`).
- **HSTS includeSubdomains:** в `.env.production.example` выключено до уверенности в TLS на всех поддоменах.
- **Health `degraded`:** отсутствие некоторых billing/CRM таблиц — приложение может жить, но поле `migrations_pending` в JSON.

---

## Канонические entrypoints

| Назначение | Путь |
|------------|------|
| Публичный сайт / лендинг | `public_html/index.php` |
| Меню столика (guest) | `public_html/qr.php` |
| Оформление | `public_html/checkout.php` |
| Заказ | `public_html/order_track.php` |
| Вход | `public_html/login.php` |
| Health | `public_html/health.php` |
| Политика (заглушка) | `public_html/privacy.php` |

Bootstrap (сессии, конфиг, consent): `app/bootstrap.php` → почти все публичные страницы.

---

## Важные модули (куда смотреть при багах)

| Область | Файлы / папки |
|---------|----------------|
| Конфиг / env | `app/config.php`, `.env.production` (на сервере) |
| БД | `app/db.php`, миграции `app/migrations/` (и legacy `db/patches` для dev compose) |
| Auth | `app/auth.php`, `public_html/login.php` |
| Гость + OTP | `app/guest_otp.php`, `public_html/guest/send_otp.php`, `verify_otp.php` |
| Заказы / корзина | `public_html/qr.php`, `checkout.php`, `public_html/ajax/order_*.php` |
| Загрузки | `app/upload_helpers.php`, `public_html/restaurant/menu_manage.php` |
| TLS / reverse proxy | `deploy/nginx/default.conf`, `deploy/nginx/certs/` |
| Docker прод | `docker-compose.prod.yml`, `Dockerfile` |
| Деплой-скрипты | `deploy.sh`, `deploy_full.sh` |

---

## Известные ограничения

- Два compose-файла: **prod** (`app` + `nginx`) vs **dev** (`web` без nginx) — разные имена сервисов.
- Скрипт `deploy_full.sh` жёстко рестартит `qr-rest_app_1` — имя контейнера может отличаться.
- Старый бинарник `docker-compose` на сервере мог ломать recreate (`KeyError: 'ContainerConfig'`) — предпочтителен `docker compose` v2.

---

## Опасные зоны (трогать осторожно)

- `app/bootstrap.php` — глобальные заголовки, CSP, cookie consent buffer, сессии.
- `app/config.php` / `app/env_guard.php` — прод может «молча» падать при неверном env.
- `public_html/qr.php` — большой файл, критичный путь гостя.
- Том MySQL `dbdata_prod` — не удалять при «починке» compose без бэкапа.

---

## Последние зафиксированные прод-темы (из инцидентов, не из git-blame)

- Wildcard SSL + ручное копирование сертов в `deploy/nginx/certs/`.
- Ручной recreate nginx / ручной запуск контейнеров при баге compose.
- Лимиты загрузок: `deploy/php/zz-uploads.ini` + константа в `app/upload_helpers.php`.
- Права на `public_html/uploads`.

---

## Operational risks

- **Compose recreate fragility:** старый `docker-compose` (v1) может падать с `KeyError: 'ContainerConfig'`; «лечение» через слепой recreate опасно без проверки томов/сети.
- **Manual container fallback dependence:** прод может зависеть от ручного `docker start` / `docker run` после инцидента — документируйте фактические имена сетей и томов на VPS.
- **Wildcard SSL drift risk:** сертификаты и nginx на сервере могут уйти вперёд относительно git; после renew — копирование в `deploy/nginx/certs/` и reload.
- **Uploads permissions sensitivity:** смена UID/после rsync/chmod — загрузки меню ломаются при слишком жёстких правах или чужом владельце vs `www-data` в контейнере.
- **Static assets / permissions drift:** `public_html/assets/`, brand-файлы; при неполном деплое или `--delete` в rsync возможны 404 на иконках/логотипах.

---

## Связанные документы

- `docs/BRAND_ASSETS.md` — favicon/PNG/ICO, schema logo, генератор без GD на проде.
- `docs/DEPLOY_SOURCE_OF_TRUTH.md` — как устроен прод и расхождения.
- `docs/PROD_RUNBOOK.md` — восстановление и проверки.
- `docs/RELEASE_SMOKE_CHECKLIST.md` — смоук после релиза.
- `docs/DEPLOY_HTTPS.md` — Let’s Encrypt, пути certbot.
