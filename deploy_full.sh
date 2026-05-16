#!/bin/bash
set -e

LOCAL_PROJECT="/Users/artemdemyanenko/Documents/qr-rest"
REMOTE_HOST="root@178.159.94.192"
REMOTE_PATH="/opt/qr-rest"
BACKUP_PATH="/opt/qr-rest-backups/backup_$(date +%Y%m%d_%H%M%S)"

echo "🚀 Запуск деплоя"
echo "   Локальный проект: $LOCAL_PROJECT"
echo "   Сервер: $REMOTE_HOST"
echo "   Путь на сервере: $REMOTE_PATH"
echo "   Бэкап: ДА"

echo ""
echo "🔐 Проверяю SSH..."
ssh "$REMOTE_HOST" "echo '✅ SSH OK'"

echo ""
echo "📁 Готовлю папку на сервере..."
ssh "$REMOTE_HOST" "mkdir -p '$REMOTE_PATH'"

echo ""
echo "💾 Делаю бэкап на сервере..."
ssh "$REMOTE_HOST" "if [ -d '$REMOTE_PATH' ]; then mkdir -p /opt/qr-rest-backups && cp -a '$REMOTE_PATH' '$BACKUP_PATH'; fi"
echo "✅ Бэкап сделан"

echo ""
echo "📤 Заливаю проект..."
rsync -avz \
  --exclude='.git' \
  --exclude='.DS_Store' \
  --exclude='node_modules' \
  --exclude='.env' \
  "$LOCAL_PROJECT"/ "$REMOTE_HOST:$REMOTE_PATH"/

echo ""
echo "🔐 Фиксирую права на сервере..."
ssh "$REMOTE_HOST" "chmod -R 755 '$REMOTE_PATH'"
echo "✅ Права исправлены"

echo ""
echo "🔄 Перезапускаю app-контейнер..."
ssh "$REMOTE_HOST" "cd '$REMOTE_PATH' && docker restart qr-rest_app_1"
echo "✅ app-контейнер перезапущен"

echo ""
echo "🎉 Готово"