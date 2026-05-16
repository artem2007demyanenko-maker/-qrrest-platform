# DEPLOY_SOURCE_OF_TRUTH — как устроен прод (репозиторий + известные прод-инциденты)

Короткий снимок «как задумано в коде» и «что на практике уже случалось». Если прод на сервере расходится с файлом в git — **истина для живого сервера**: то, что показывают `docker ps`, `nginx -T` и рабочий `/health.php`.

---

## Do not assume

- **Git / репозиторий на ноутбуке** может отличаться от того, что задеплоено на live VPS (ручные правки, незакоммиченный nginx, старый rsync).
- **`deploy/nginx/default.conf` в git** не всегда побитово равен тому, что отдаёт **`nginx -T` на сервере** (hotfix на месте, другой volume, другой путь монтирования).
- **`docker-compose.prod.yml`** описывает *целевой* стек; фактический recovery мог идти через **`docker start` / `docker run`**, обходя compose — compose-файл тогда **не** отражает последний фактический путь восстановления без сверки с `docker inspect`.
- **Source of truth для прода** = инспекция на **VPS** (`docker ps`, `docker network inspect`, `docker volume inspect`, `docker exec nginx nginx -T`) + **живые проверки** (`curl /health.php`, TLS, PDO из контейнера app). Git — ориентир для следующего деплоя, не автоматический снимок прод-состояния.

---

## Канонический прод-стек (из репозитория)

| Что | Факт |
|-----|------|
| **Compose (прод)** | `docker-compose.prod.yml` — сервисы `app`, `db`, `nginx`. |
| **Compose (локальная разработка)** | `docker-compose.yml` — сервис **`web`** (не `app`), свой `db`, порт `80` на web, MySQL `3307:3306`. Это **другая схема имён**, не путать с продом. |
| **Образ приложения** | `Dockerfile` в корне: `php:8.2-apache`, document root `public_html`, `COPY deploy/php/zz-uploads.ini` → лимиты загрузки. |
| **Nginx** | `deploy/nginx/default.conf` монтируется в контейнер `nginx` как `/etc/nginx/conf.d/default.conf` (см. `docker-compose.prod.yml`). |
| **TLS** | В compose: `./deploy/nginx/certs` → `/etc/nginx/certs`. Ожидаемые имена: `fullchain.pem`, `privkey.pem` (как в конфиге). |
| **ACME webroot** | `./deploy/nginx/certbot` → `/var/www/certbot` (HTTP-01). |
| **Env прод** | Файл **`.env.production`** на сервере (в git не коммитится); шаблон — `.env.production.example`. В `app` пробрасывается через `env_file: .env.production`. Переменные `DB_*` также используются для сервиса `db`. |
| **Сеть Docker** | `app` резолвит хост **`db`** — имя сервиса из compose. Nginx ходит на **`app:80`** (`upstream app_backend`). |
| **Тома** | `dbdata_prod` (MySQL), `storage_logs`, `backups_data`; код репозитория — bind mount `./:/var/www/html` у `app`. |

---

## Как «на самом деле» может называться контейнер на сервере

Имя вроде **`qr-rest_app_1`** берётся из **имени проекта compose** (часто имя каталога на сервере, например `/opt/qr-rest`) + сервис + реплика. Скрипт `deploy_full.sh` в репозитории явно делает `docker restart qr-rest_app_1` — это **зашито под конкретный прод** (путь `/opt/qr-rest`, хост `178.159.94.192`).

**Assumption:** на вашем VPS имена могут отличаться (`docker ps --format '{{.Names}}'` — источник правды).

---

## SSL / wildcard

- В **`deploy/nginx/default.conf` в git** один HTTPS `server` с `server_name` apex + `www` + `*.MAIN_DOMAIN` и теми же `ssl_certificate` / `ssl_certificate_key`. Ожидается **fullchain с wildcard SAN** (DNS-01), иначе браузеры на поддоменах покажут ошибку сертификата.
- После renew Let’s Encrypt — снова скопировать `fullchain.pem` / `privkey.pem` в `deploy/nginx/certs/` и `nginx -s reload` (см. `docs/DEPLOY_HTTPS.md`).

**Проверка на VPS:** `docker exec <nginx> nginx -T | grep -E 'server_name|ssl_certificate|ssl_reject'` — для актуального репо **не** должно быть `ssl_reject_handshake` для поддоменов.

---

## PHP upload limits

- Файл образа: `deploy/php/zz-uploads.ini` → `upload_max_filesize = 12M`, `post_max_size = 16M`.
- Лимит приложения для фото блюд: `MENU_ITEM_IMAGE_MAX_BYTES` в `app/upload_helpers.php` (10 MB логически; PHP должен быть не ниже).

---

## Известные прод-отклонения (инциденты)

| Тема | Что было | Зачем помнить |
|------|----------|----------------|
| **Legacy `docker-compose` / recreate** | Ошибка вида `KeyError: 'ContainerConfig'` при recreate старым бинарником | Часто лечится переходом на **`docker compose` (v2 plugin)** или пересозданием контейнеров без «наследия» старого metadata. Не удалять тома БД без бэкапа. |
| **Ручной запуск app/db** | Контейнеры поднимали `docker run` / вручную | Имена сети, volume и env должны совпасть с тем, что ожидает приложение (`DB_HOST=db` только если в одной user-defined network с MySQL). |
| **Ручное копирование certs** | fullchain/privkey в `deploy/nginx/certs/` | После renew — снова скопировать и `nginx -s reload`. |
| **Права на uploads** | hotfix прав на каталоги загрузок | Владелец должен совпадать с пользователем внутри контейнера (Apache обычно `www-data`). Путь меню: `public_html/uploads/menu/...`. |

---

## Скрипты деплоя в репо

| Файл | Назначение |
|------|------------|
| `deploy.sh` | rsync на сервер, опции бэкапа/рестарта app; рестарт сервиса `app` через **`docker compose`** (v2 plugin), не `docker-compose` v1. |
| `deploy_full.sh` | Упрощённый сценарий: rsync + `chmod` + **`docker restart qr-rest_app_1`** (жёстко заданные хост/путь). |

Не считать их взаимозаменяемыми без проверки имён контейнеров на VPS.

---

## Что считать «источником правды» при споре

1. `docker ps -a` и `docker network inspect` на проде.  
2. `docker exec <nginx> nginx -T` (полный конфиг nginx).  
3. `curl -sS https://<домен>/health.php` (JSON, `db`, `status`).  
4. Файлы на диске сервера: `/opt/qr-rest` (или ваш `REMOTE_PATH`), особенно `.env.production` и `deploy/nginx/certs/*.pem`.  
5. Git — как **целевое** состояние для следующего деплоя, но не всегда как снимок текущего прод без синхронизации.
