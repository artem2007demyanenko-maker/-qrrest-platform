# PROD_RUNBOOK — эксплуатация и восстановление (QR REST)

Практичные шаги для solo-оператора. Команды — с SSH на VPS; пути и имена контейнеров проверяйте на своей машине (**assumption**: проект в `/opt/qr-rest`, контейнер app как `qr-rest_app_1` — см. `deploy_full.sh`).

Имена контейнеров вида `qr-rest_*` — от имени проекта compose (часто каталог `qr-rest`). Подставьте свои: `docker ps --format '{{.Names}}'`.

**Статика / favicon:** после деплоя быстрый контроль — `curl -sI https://<домен>/assets/brand/favicon.svg` и см. `docs/BRAND_ASSETS.md`, `docs/RELEASE_SMOKE_CHECKLIST.md` (раздел brand).

---

## 0. Однократная фиксация на VPS (имена контейнеров, сеть, тома)

Сохраните вывод в заметке рядом с сервером. Пример пути проекта: `/opt/qr-rest`. Имена вида `qr-rest_app_1` задаёт **имя каталога** при `docker compose` (часто `qr-rest`).

```bash
docker ps --format '{{.Names}}\t{{.Status}}'
docker network ls | grep qr-rest
docker volume ls | grep -E 'dbdata|storage_logs|backups'
docker inspect qr-rest_app_1 --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
```

Ожидаемая **тройка**: `…_app_1`, `…_db_1`, `…_nginx_1`. Сеть чаще всего `qr-rest_default`. Тома из compose: `dbdata_prod`, `storage_logs`, `backups_data` (в `docker volume ls` с префиксом проекта, например `qr-rest_dbdata_prod`).

**Минимальный ручной fast recovery (копипаста, подставьте свои имена):**

```bash
docker start qr-rest_db_1 qr-rest_app_1 qr-rest_nginx_1
docker exec qr-rest_app_1 getent hosts db
docker exec qr-rest_nginx_1 nginx -t && docker exec qr-rest_nginx_1 nginx -s reload
docker volume ls
```

PDO из app — см. шаг **5** в разделе ниже (или блок «Manual fallback»).

---

## Fast recovery in 5 minutes

Выполнить **по порядку** на VPS (домен при необходимости заменить):

```bash
# 1) Контейнеры — кто жив, кто упал
docker ps -a --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'

# 2) Быстрый сигнал приложения + БД (снаружи, через nginx)
curl -sS -o /tmp/qr_health.json -w 'http:%{http_code}\n' https://qrrest-menu.ru/health.php
head -c 400 /tmp/qr_health.json; echo

# 3) App / db / nginx по логам (имена из docker ps)
docker logs --tail 80 qr-rest_app_1
docker logs --tail 40 qr-rest_db_1
docker logs --tail 40 qr-rest_nginx_1

# 4) Резолв имени db из контейнера app (должен вернуть IP, не NXDOMAIN)
docker exec qr-rest_app_1 getent hosts db

# 5) PDO к MySQL изнутри app (ожидается: pdo_ok)
docker exec qr-rest_app_1 php -r '$c=require "/var/www/html/app/config.php"; $db=$c["db"]??[]; $h=$db["host"]??"db"; $p=(int)($db["port"]??3306); $n=$db["name"]??""; $pdo=new PDO("mysql:host=$h;port=$p;dbname=$n;charset=utf8mb4", (string)($db["user"]??""), (string)($db["pass"]??""), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); echo ($pdo->query("SELECT 1")->fetchColumn()?"pdo_ok\n":"pdo_fail\n");'

# 6) SSL (apex + тестовый поддомен)
echo | openssl s_client -servername qrrest-menu.ru -connect qrrest-menu.ru:443 2>/dev/null | openssl x509 -noout -subject -dates
echo | openssl s_client -servername test.qrrest-menu.ru -connect test.qrrest-menu.ru:443 2>/dev/null | openssl x509 -noout -subject -dates

# 7) Лимиты загрузки PHP в app
docker exec qr-rest_app_1 php -i | grep -E 'upload_max_filesize|post_max_size'

# 8) Writable: storage (health) + uploads (фото блюд)
docker exec qr-rest_app_1 sh -lc 'ls -la /var/www/html/storage/logs /var/www/html/public_html/uploads 2>/dev/null; touch /var/www/html/public_html/uploads/.probe 2>/dev/null && echo uploads_touch_ok || echo uploads_touch_fail'
```

Если шаг **2** даёт не `200` или в JSON `"db":"error"` — не считать прод восстановленным; идти в **Manual fallback commands** и раздел про сеть/`db`.

---

## Do not do this

- Не ожидать на сервере путь вида `~/Downloads` для `scp` с локальной машины — загружайте в **известный** каталог на VPS (например `/opt/qr-rest/` или `/tmp/`).
- Не путать **`docker-compose.yml`** (dev, сервис `web`) и **`docker-compose.prod.yml`** (прод, `app` + `nginx`).
- Не считать конфиг в git единственной правдой: сверяйте с **`docker exec <nginx> nginx -T`** на VPS.
- Не делать `docker compose ... rm` / `--force-recreate` **вслепую** без проверки: `docker volume ls` (том `dbdata_prod`), `docker network inspect`, что app/db в одной сети.
- Не считать, что app «поднялся», пока нет: **`docker ps` = Up**, **`getent hosts db`** из app, **PDO** (выше), **`/health.php`** с `"db":"ok"`.

---

## Owner panel stability smoke
Для проверки стабильности owner-панели (dashboard, crm, loyalty_settings, crm_campaigns, revenue) используйте:
docs/OWNER_STABILITY_CHECKLIST.md

---
## 1. Какие контейнеры должны быть живы

Ожидаемая тройка из `docker-compose.prod.yml`:

| Сервис | Роль |
|--------|------|
| **nginx** | 80/443, TLS, reverse proxy → `app:80`. |
| **app** | PHP/Apache, код из bind mount. |
| **db** | MySQL 8, том `dbdata_prod`. |

Проверка:

```bash
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
```

Ожидается `Up` у всех трёх (или эквивалентные имена).

---

## 2. Первая диагностика (порядок)

1. Сайт открывается? `curl -sS -o /dev/null -w '%{http_code}\n' https://qrrest-menu.ru/health.php`  
2. JSON health: `curl -sS https://qrrest-menu.ru/health.php | head -c 500`  
   - `db: ok`, `status: ok` (или `degraded` с понятным `migrations_pending` — не всегда блокер меню).  
3. Контейнеры: `docker ps -a` — кто `Exited`?  
4. Логи: `docker logs --tail 100 <имя_app>` и при необходимости `docker logs --tail 50 <имя_nginx>`.  
5. Nginx: `docker exec <имя_nginx> nginx -t` и при OK — конфиг реальный: `docker exec <имя_nginx> nginx -T | head -n 80`.

---

## 3. Проверка DB connection

- Через приложение: **`/health.php`** — поле `db` должно быть `ok`.  
- Из контейнера app (тот же one-liner, что в Fast recovery):

```bash
docker exec qr-rest_app_1 php -r '$c=require "/var/www/html/app/config.php"; $db=$c["db"]??[]; $h=$db["host"]??"db"; $p=(int)($db["port"]??3306); $n=$db["name"]??""; $pdo=new PDO("mysql:host=$h;port=$p;dbname=$n;charset=utf8mb4", (string)($db["user"]??""), (string)($db["pass"]??""), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); echo ($pdo->query("SELECT 1")->fetchColumn()?"pdo_ok\n":"pdo_fail\n");'
```

Если health показывает `db: error` — проверить `DB_*` в `/opt/qr-rest/.env.production`, что app и `db` в **одной** user-defined сети и хост в конфиге совпадает с тем, что резолвится (`getent hosts db` внутри app).

---

## 4. SSL

```bash
echo | openssl s_client -servername qrrest-menu.ru -connect qrrest-menu.ru:443 2>/dev/null | openssl x509 -noout -subject -dates
echo | openssl s_client -servername test.qrrest-menu.ru -connect test.qrrest-menu.ru:443 2>/dev/null | openssl x509 -noout -subject -dates
```

Ожидание после wildcard: в SAN есть `*.qrrest-menu.ru` (и нужные имена). После деплоя сверьте, что на VPS `nginx -T` совпадает с `deploy/nginx/default.conf` в git (без лишнего `ssl_reject_handshake` для поддоменов).

---

## 5. Upload limits (PHP)

Внутри контейнера **app**:

```bash
docker exec qr-rest_app_1 php -i | grep -E 'upload_max_filesize|post_max_size'
```

Ожидается согласовано с `deploy/php/zz-uploads.ini` (12M / 16M). Nginx: `client_max_body_size` в `deploy/nginx/default.conf` (32m) — выше лимита приложения.

---

## 6. Права на uploads

Каталоги под загрузки меню (см. код): `public_html/uploads/menu/` (и родитель `public_html/uploads`).

На сервере (пути от корня проекта; внутри контейнера — `/var/www/html/public_html/uploads`):

```bash
ls -la /opt/qr-rest/public_html/uploads
touch /opt/qr-rest/public_html/uploads/.probe && rm -f /opt/qr-rest/public_html/uploads/.probe
# при необходимости (владелец = пользователь Apache в контейнере, обычно www-data):
# chown -R www-data:www-data /opt/qr-rest/public_html/uploads
# chmod -R 775 /opt/qr-rest/public_html/uploads
```

Проверка из контейнера app: см. шаг **8** в «Fast recovery» (`uploads_touch_ok`).

**Риск:** слишком открытые права — дыра в безопасности; слишком жёсткие — `move_uploaded_file` падает.

---

## 7. Если пропал контейнер app (`qr-rest_app_1`)

1. `docker ps -a | grep app` — статус `Exited`? Смотреть `docker logs`.  
2. Если контейнера нет — **не терять тома**: `docker volume ls | grep dbdata`.  
3. Предпочтительно поднять через compose из каталога проекта:

```bash
cd /opt/qr-rest
docker compose -f docker-compose.prod.yml --env-file .env.production up -d app
```

Если **compose снова падает** — см. раздел 10.

---

## 8. Если `db` не резолвится из app

Симптом: health `db: error`, в логах PDO «could not find driver» / «Connection refused» / «Unknown MySQL server host 'db'».

Проверить:

```bash
docker inspect qr-rest_app_1 --format '{{json .NetworkSettings.Networks}}'
docker inspect qr-rest_db_1 --format '{{json .NetworkSettings.Networks}}'
```

Оба должны быть в **одной** user-defined сети. Если app запускали вручную с `--network host` или другой сетью — hostname `db` не работает; либо вернуть compose-сеть, либо в `.env.production` временно указать IP контейнера db (костыль; лучше починить сеть).

---

## 9. `KeyError: 'ContainerConfig'` при recreate (legacy docker-compose)

Типично для старого бинарника **`docker-compose` (v1)** при `up`/`recreate` против актуальных образов/метаданных.

**Важно:**

- **Не считать** `docker-compose ... recreate` надёжным recovery path, если команда уже один раз упала с `KeyError: 'ContainerConfig'`.
- Предпочтительно: **`docker compose`** (CLI v2 plugin) — `docker compose version`.
- **Ручной** запуск контейнеров (`docker start` / `docker run` по шаблону из `docker inspect`) — **допустим временный** рабочий сценарий, пока стек не приведён к стабильному `docker compose up` с бэкапом и проверкой сетей/томов.

**Безопасная стратегия после появления ошибки:**

1. **Бэкап БД** перед любыми `rm` контейнеров.  
2. Проверить, что используете **`docker compose`**, не старый `docker-compose`.  
3. Поднять стек:  
   `cd /opt/qr-rest && docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build`  
4. Если нужно только app:  
   `docker compose -f docker-compose.prod.yml --env-file .env.production up -d --force-recreate app`  
5. Если compose снова ломается — **не зацикливаться на recreate**; см. **Manual fallback commands**.

---

## 10. Manual fallback commands (реальные шаблоны под этот прод)

Используйтесь, когда **`docker compose` недоступен** или падает с `ContainerConfig`, но нужно быстро вернуть сервис. Подставьте имена из `docker ps -a` (ниже — как в `deploy_full.sh`).

### Быстрый рестарт без пересоздания (самый безопасный первый шаг)

```bash
docker start qr-rest_db_1
docker start qr-rest_app_1
docker start qr-rest_nginx_1
# если имена другие — только docker start <имя>
```

### Проверка сети и алиаса `db` перед ручным `docker run`

```bash
docker inspect qr-rest_app_1 --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
docker inspect qr-rest_db_1 --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}'
docker network inspect qr-rest_default --format '{{range .Containers}}{{.Name}} {{end}}' 2>/dev/null || true
```

`getent` изнутри app (ожидается строка с IP для `db`):

```bash
docker exec qr-rest_app_1 getent hosts db
```

### PDO изнутри app (дубль быстрой проверки)

```bash
docker exec qr-rest_app_1 php -r '$c=require "/var/www/html/app/config.php"; $db=$c["db"]??[]; $h=$db["host"]??"db"; $p=(int)($db["port"]??3306); $n=$db["name"]??""; $pdo=new PDO("mysql:host=$h;port=$p;dbname=$n;charset=utf8mb4", (string)($db["user"]??""), (string)($db["pass"]??""), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); echo ($pdo->query("SELECT 1")->fetchColumn()?"pdo_ok\n":"pdo_fail\n");'
```

### Ручной запуск контейнеров, если их удалили (НЕ новый том БД)

Сначала найти **точный** том и сеть старого `db`:

```bash
docker volume ls | grep -E 'dbdata|mysql|qr'
docker inspect qr-rest_db_1 2>/dev/null || echo "db container missing — inspect backup notes or docker volume inspect"
```

Шаблон **восстановления только app** (образ и сеть — из `docker images` / `docker network ls`; имя сети часто `qr-rest_default`):

```bash
cd /opt/qr-rest
docker volume ls | grep -E 'qr-rest|dbdata|storage'
IMAGE_APP=$(docker inspect -f '{{.Config.Image}}' qr-rest_app_1 2>/dev/null)
# если контейнера нет: docker compose -f docker-compose.prod.yml build app
# затем: IMAGE_APP=$(docker compose -f docker-compose.prod.yml images -q app)  # или docker images | grep <ваш_тег>
NETWORK=qr-rest_default
docker run -d --name qr-rest_app_1 \
  --network "$NETWORK" \
  --env-file /opt/qr-rest/.env.production \
  -e APP_RUNTIME=docker \
  -e DB_HOST=db -e DB_PORT=3306 \
  -v /opt/qr-rest:/var/www/html \
  -v qr-rest_storage_logs:/var/www/html/storage/logs \
  -v qr-rest_backups_data:/var/www/html/backups \
  "$IMAGE_APP"
```

**Внимание:** флаги `-v` для named volumes должны **совпасть** с теми, что в `docker-compose.prod.yml` (`storage_logs`, `backups_data` — имена томов смотреть `docker volume ls`). Если контейнер уже существует — не создавать второй с тем же именем; используйте `docker start` или `docker rm` только после бэкапа и уверенности.

Шаблон **db** (только если контейнера нет и том известен — **не пересоздавать том с данными случайно**):

```bash
docker run -d --name qr-rest_db_1 \
  --network qr-rest_default \
  --env-file /opt/qr-rest/.env.production \
  -e MYSQL_DATABASE="${DB_NAME:-qr_rest}" -e MYSQL_USER="${DB_USER:-qr}" \
  -e MYSQL_PASSWORD="..." -e MYSQL_ROOT_PASSWORD="..." \
  -v qr-rest_dbdata_prod:/var/lib/mysql \
  mysql:8.0 --default-authentication-plugin=mysql_native_password
```

Пароли и имя тома **взять** с существующего стека или бэкапа; иначе получите пустую БД.

### Nginx: проверка конфига и перезагрузка / recreate

```bash
docker exec qr-rest_nginx_1 nginx -t
docker exec qr-rest_nginx_1 nginx -s reload
```

После смены `default.conf` или сертов на хосте — при необходимости:

```bash
cd /opt/qr-rest
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --force-recreate nginx
```

Если compose недоступен:

```bash
docker restart qr-rest_nginx_1
```

**Критично:** не пересоздавать том MySQL без бэкапа — потеряете данные.

---

## 11. Nginx после правок сертов

```bash
docker exec qr-rest_nginx_1 nginx -t && docker exec qr-rest_nginx_1 nginx -s reload
```

(Если контейнер назван иначе — подставить имя из `docker ps`.) Серты на хосте: `/opt/qr-rest/deploy/nginx/certs/fullchain.pem`, `privkey.pem` (см. compose volumes).

---

## 12. Минимальный «всё ожило» чеклист

- [ ] `docker ps` — nginx, app, db — Up.  
- [ ] `curl` `/health.php` — `db: ok`.  
- [ ] HTTPS на основном домене и на тестовом поддомене (если используете).  
- [ ] Загрузка фото блюда в UI (лимит 10 MB app + PHP ini).  

Подробный продуктовый смоук — `docs/RELEASE_SMOKE_CHECKLIST.md`.
