#!/bin/bash
# deploy_checklist.sh
# Финальный проверочный скрипт для закрытия деплой-блока QR Restaurant SaaS

set -e

# --- Конфигурация ---
REMOTE_PATH="/opt/qr-rest"
DOMAIN="qrrest-menu.ru"
APP_CONTAINER="qr-rest_app_1"
DB_CONTAINER="qr-rest_db_1"
NGINX_CONTAINER="qr-rest_nginx_1"
UPLOADS_PATH="$REMOTE_PATH/public_html/uploads"
SNAPSHOT_FILE="$HOME/qr_rest_snapshot.txt"

echo "=== 0. Перейти в проект ==="
cd "$REMOTE_PATH"

# --- 1. Пересборка app ---
echo "=== 1. Пересборка app ==="
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build app
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

# --- 2. Проверка и reload Nginx ---
echo "=== 2. Проверка Nginx ==="
docker exec "$NGINX_CONTAINER" nginx -t
docker exec "$NGINX_CONTAINER" nginx -s reload

# --- 3. Проверка Health ---
echo "=== 3. Проверка health.php ==="
HTTP_CODE=$(curl -sS -o /tmp/qr_health.json -w '%{http_code}' "https://$DOMAIN/health.php")
echo "HTTP code: $HTTP_CODE"
head -c 400 /tmp/qr_health.json
echo
if [ "$HTTP_CODE" -ne 200 ]; then
    echo "Ошибка: Health check вернул не 200"
fi

# --- 4. Проверка PHP лимитов и uploads ---
echo "=== 4. Проверка PHP лимитов ==="
docker exec "$APP_CONTAINER" php -i | grep -E 'upload_max_filesize|post_max_size'

echo "=== Проверка прав uploads ==="
ls -la "$UPLOADS_PATH"
docker exec "$APP_CONTAINER" sh -lc "ls -la /var/www/html/public_html/uploads; \
touch /var/www/html/public_html/uploads/.probe && rm -f /var/www/html/public_html/uploads/.probe && echo write_ok || echo write_fail"

echo "=== Исправление прав если нужно ==="
docker exec "$APP_CONTAINER" sh -lc "touch /var/www/html/public_html/uploads/.probe && echo write_ok || echo write_fail" || \
sudo chown -R www-data:www-data "$UPLOADS_PATH" && sudo chmod -R 775 "$UPLOADS_PATH"

# --- 5. Smoke: curl для brand / статики ---
echo "=== 5. Проверка статики / brand ==="
ASSETS=(
"/assets/brand/favicon.svg"
"/assets/brand/favicon-32.png"
"/favicon.png"
"/favicon.ico"
"/assets/brand/apple-touch-icon.png"
)
for u in "${ASSETS[@]}"; do
    echo "--- $u ---"
    curl -sI "https://$DOMAIN$u"
done

echo "=== JSON-LD на главной ==="
curl -sS "https://$DOMAIN/" | grep -E 'application/ld\+json|"logo"'

# --- 6. Фиксация контейнеров, сети и томов ---
echo "=== 6. Фиксация состояния ==="
echo "Containers:" > "$SNAPSHOT_FILE"
docker ps --format '{{.Names}}\t{{.Status}}\t{{.Ports}}' >> "$SNAPSHOT_FILE"
echo "" >> "$SNAPSHOT_FILE"

echo "Volumes:" >> "$SNAPSHOT_FILE"
docker volume ls | grep -E 'dbdata|storage_logs|backups' >> "$SNAPSHOT_FILE"
echo "" >> "$SNAPSHOT_FILE"

echo "Network:" >> "$SNAPSHOT_FILE"
docker network ls | grep qr-rest >> "$SNAPSHOT_FILE"
docker inspect "$APP_CONTAINER" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' >> "$SNAPSHOT_FILE"

echo "=== ✅ Скрипт выполнен ==="
echo "Снимок состояния сохранён в $SNAPSHOT_FILE"
echo "Дальше: пройдите ручной smoke по docs/RELEASE_SMOKE_CHECKLIST.md для проверки мобильного QR, корзины, cookie и админки."
