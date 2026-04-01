# HTTPS для qrrest-menu.ru (Docker + Nginx на VPS)

## Аудит текущего стека

| Компонент | Состояние |
|-----------|-----------|
| `docker-compose.yml` | Dev: `web` публикует `:80`, без TLS. |
| `docker-compose.prod.yml` | Сервис `nginx` (Alpine) с `80:80` и `443:443`, приложение `app` без публикации портов — трафик только через Nginx → `app:80`. Все `APP_*` из `.env.production` попадают в контейнер `app` через `env_file`. |
| `deploy/nginx/default.conf` | Два HTTPS-блока: apex+www с TLS; отдельный блок для `*.qrrest-menu.ru` с `ssl_reject_handshake on` **пока нет wildcard в сертификате** (не отдаётся чужой сертификат). |
| Приложение | `app/config.php`: `APP_PROTOCOL`, авто-`https` при `X-Forwarded-Proto`; `APP_MAIN_DOMAIN`, `APP_COOKIE_DOMAIN`; `APP_HSTS`, `APP_HSTS_INCLUDE_SUBDOMAINS`, `APP_HSTS_PRELOAD`. |
| HSTS | В `app/bootstrap.php` заголовок HSTS при `APP_PROTOCOL=https` и `APP_HSTS=1`. По умолчанию **без** `includeSubDomains` и **без** `preload` — включайте после полного wildcard TLS. |

Архитектуру контейнеров не меняли: TLS терминирует Nginx, PHP видит прокси-заголовки.

---

## Два этапа TLS (обязательно различать)

### A. Быстрый HTTPS только для apex + www (HTTP-01)

- Сертификат Let’s Encrypt через **webroot** (`deploy/nginx/certbot`).
- Покрытие: **`qrrest-menu.ru`**, **`www.qrrest-menu.ru`**.
- В `deploy/nginx/default.conf` HTTPS для этих имён — первый `server { listen 443 ssl ... }`.

### B. Поддомены ресторанов `*.qrrest-menu.ru` по HTTPS

- Нужен сертификат с SAN **`*.qrrest-menu.ru`** (и обычно apex в том же cert) — Let’s Encrypt выдаёт **только через DNS-01**, не через HTTP challenge.
- После установки `fullchain.pem` / `privkey.pem`, которые покрывают wildcard:
  1. Удалите второй HTTPS-блок (`server_name *.qrrest-menu.ru; ssl_reject_handshake on;`).
  2. Добавьте `*.qrrest-menu.ru` в `server_name` **первого** HTTPS-блока (те же `ssl_certificate` / `ssl_certificate_key`).
  3. `nginx -s reload`.
  4. В `.env.production` можно включить `APP_HSTS_INCLUDE_SUBDOMAINS=1` (только если все поддомены стабильно открываются по HTTPS).

Пока этап B не выполнен: HTTPS на поддоменах **не обслуживается** корректным сертификатом; конфиг **не** выдаёт сертификат apex для SNI поддомена (нет «ложного» совпадения в браузере — используется `ssl_reject_handshake`).

---

## Обязательные переменные production (`.env.production`)

Скопируйте из `.env.production.example` и проверьте:

| Переменная | Значение (пример) |
|------------|-------------------|
| `APP_ENV` | `production` |
| `APP_URL` | `https://qrrest-menu.ru` |
| `APP_PROTOCOL` | `https` |
| `APP_MAIN_DOMAIN` | `qrrest-menu.ru` |
| `APP_COOKIE_DOMAIN` | `.qrrest-menu.ru` |
| `APP_HSTS` | `1` (или `0` на первые часы раскатки) |
| `APP_HSTS_INCLUDE_SUBDOMAINS` | `0` до wildcard; `1` после |
| `APP_HSTS_PRELOAD` | `0` по умолчанию |

`docker compose ... env_file: .env.production` передаёт эти переменные в контейнер `app` без дублирования в `environment:`.

---

## Команды на сервере (порядок)

Предполагается каталог проекта, например `/opt/qr-rest`, и DNS:

- `A` для `qrrest-menu.ru` → IP VPS  
- `A` или `CNAME` для `www.qrrest-menu.ru`  
- `*.qrrest-menu.ru` → тот же IP (для поддоменов ресторанов)

### 1. Каталоги и env

```bash
cd /opt/qr-rest   # ваш путь
mkdir -p deploy/nginx/certs deploy/nginx/certbot
cp .env.production.example .env.production
nano .env.production
```

Поднимите стек **до** выпуска боевого сертификата можно с **временными** самоподписанными файлами (только чтобы Nginx стартовал):

```bash
openssl req -x509 -nodes -newkey rsa:2048 -days 7 \
  -keyout deploy/nginx/certs/privkey.pem \
  -out deploy/nginx/certs/fullchain.pem \
  -subj "/CN=qrrest-menu.ru"
```

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
```

### 2. Выпуск Let’s Encrypt (HTTP-01, apex + www)

Пока Nginx слушает 80 и отдаёт webroot из `deploy/nginx/certbot`:

```bash
docker run --rm \
  -v "$(pwd)/deploy/nginx/certbot:/var/www/certbot" \
  -v "$(pwd)/deploy/nginx/letsencrypt:/etc/letsencrypt" \
  certbot/certbot certonly --webroot \
  -w /var/www/certbot \
  -d qrrest-menu.ru -d www.qrrest-menu.ru \
  --agree-tos --non-interactive --email YOUR@EMAIL \
  --keep-until-expiring
```

Скопируйте ключ и цепочку в пути, которые читает Nginx:

```bash
DOMAIN=qrrest-menu.ru
mkdir -p deploy/nginx/certs
cp deploy/nginx/letsencrypt/live/$DOMAIN/fullchain.pem deploy/nginx/certs/fullchain.pem
cp deploy/nginx/letsencrypt/live/$DOMAIN/privkey.pem   deploy/nginx/certs/privkey.pem
```

Проверка синтаксиса и перезагрузка:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production exec nginx nginx -t
docker compose -f docker-compose.prod.yml --env-file .env.production exec nginx nginx -s reload
```

Проверка: `curl -sI https://qrrest-menu.ru/health.php`

> **Примечание:** каталог `deploy/nginx/letsencrypt` в примере — отдельный том от `certs`, чтобы не перезаписывать `fullchain.pem` вручную. Можно вместо этого смонтировать один каталог как `/etc/letsencrypt` у certbot и копировать из `live/...` в `deploy/nginx/certs/` — главное, чтобы пути в `deploy/nginx/certs/` совпадали с `ssl_certificate` в конфиге.

### 3. Wildcard (DNS-01), когда нужны поддомены по HTTPS

Пример с плагином (замените на ваш DNS и секреты):

```bash
docker run --rm -it \
  -v "$(pwd)/deploy/nginx/letsencrypt:/etc/letsencrypt" \
  -v "$(pwd)/deploy/certbot-dns-secrets:/secrets" \
  certbot/dns-cloudflare certonly \
  --dns-cloudflare --dns-cloudflare-credentials /secrets/cloudflare.ini \
  -d qrrest-menu.ru -d '*.qrrest-menu.ru' -d www.qrrest-menu.ru
```

Затем снова скопируйте `fullchain.pem` / `privkey.pem` в `deploy/nginx/certs/`, **объедините** HTTPS-блоки в `default.conf` (см. раздел «Два этапа TLS»), `nginx -s reload`.

---

## Поведение Nginx (proxy)

Для основного HTTPS-блока задаются:

- `Host` — исходный хост клиента  
- `X-Forwarded-For` — цепочка прокси  
- `X-Forwarded-Proto` — `https` (от `$scheme` на стороне Nginx)  
- `X-Forwarded-Host` — хост запроса  

PHP в `app/config.php` использует `APP_PROTOCOL` и при пустом значении — `HTTP_X_FORWARDED_PROTO` для определения `https`.

Редирект HTTP→HTTPS для `/.well-known/acme-challenge/` **не** выполняется — отдаётся файловый webroot.

---

## Production readiness checks

1. **Сертификат активен (apex):**  
   `echo | openssl s_client -servername qrrest-menu.ru -connect qrrest-menu.ru:443 2>/dev/null | openssl x509 -noout -dates -subject`

2. **Приложение видит HTTPS:**  
   `curl -s https://qrrest-menu.ru/health.php | jq`  
   Ожидается `"app_protocol":"https"`, `"forwarded_proto":"https"` при запросе через Nginx.

3. **Поддомены и сертификат:**  
   - До wildcard: `curl -vI https://test.qrrest-menu.ru` — ожидается ошибка TLS (handshake rejected), **не** предупреждение о несовпадении имени с сертификатом apex.  
   - После wildcard: тот же URL без ошибки TLS; в сертификате есть SAN `*.qrrest-menu.ru`.

4. **Сессии на поддоменах:**  
   В браузере: логин на `https://qrrest-menu.ru`, затем открытие `https://<sub>.qrrest-menu.ru/...` — сессия при `APP_COOKIE_DOMAIN=.qrrest-menu.ru` и **валидном HTTPS** на поддомене должна передаваться. Пока поддомен без сертификата — вход на поддомене по HTTPS невозможен; cookie `Secure` в production не отправляются по HTTP.

5. **Env guard:** при `APP_ENV=production` и неверном `APP_PROTOCOL` / `APP_URL` / пароле БД приложение ответит 500 с `ENV_GUARD_ERROR` в логах (см. `app/env_guard.php`).

---

## Какие домены покрыты сертификатом

| Этап | Сертификат | HTTPS без ошибки |
|------|------------|-------------------|
| HTTP-01 apex+www | `qrrest-menu.ru`, `www.qrrest-menu.ru` | Только эти имена |
| DNS-01 wildcard | `*.qrrest-menu.ru` (+ apex в SAN) | Поддомены первого уровня |

---

## Файлы

- `deploy/nginx/default.conf` — HTTP→HTTPS, ACME, HTTPS proxy, опционально reject для поддоменов без wildcard.
- `deploy/nginx/ssl_readme.txt` — краткая отсылка.
- `.env.production.example` — эталон env.
- `app/config.php` — протокол, cookie domain, HSTS-флаги.

---

## Verdict

**Apex + www:** готовы к HTTPS после HTTP-01 и корректного `.env.production`.  
**Wildcard / поддомены:** готовы только после DNS-01 и **слияния** HTTPS-блоков в Nginx + при необходимости `APP_HSTS_INCLUDE_SUBDOMAINS=1`. Wildcard через HTTP-01 **невозможен**.
