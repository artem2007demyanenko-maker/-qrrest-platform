#!/usr/bin/env bash

########################################
# НАСТРОЙКИ — ЗАПОЛНИ ПОД СЕБЯ
########################################

SERVER_USER="root"
SERVER_HOST="178.159.94.192"
SERVER_PATH="/opt/qr-rest"

SSH_OPTS=(-o StrictHostKeyChecking=no)

# Если нужен нестандартный ssh key:
# SSH_KEY="$HOME/.ssh/id_ed25519"
SSH_KEY=""

COMPOSE_FILE="docker-compose.prod.yml"
ENV_FILE=".env.production"

EXCLUDES=(
  ".git"
  ".DS_Store"
  "node_modules"
  ".idea"
  ".vscode"
)

########################################
# СЛУЖЕБНОЕ
########################################

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ ! -f "$PROJECT_DIR/$COMPOSE_FILE" ]]; then
  echo "❌ Не найден $COMPOSE_FILE в $PROJECT_DIR"
  exit 1
fi

SSH_OPTS=()
if [[ -n "$SSH_KEY" ]]; then
  SSH_OPTS+=(-i "$SSH_KEY")
fi

RSYNC_EXCLUDE_ARGS=()
for item in "${EXCLUDES[@]}"; do
  RSYNC_EXCLUDE_ARGS+=(--exclude "$item")
done

REMOTE="${SERVER_USER}@${SERVER_HOST}"

echo "🚀 Начинаю деплой"
echo "   Локальный проект: $PROJECT_DIR"
echo "   Сервер: $REMOTE"
echo "   Путь на сервере: $SERVER_PATH"
echo

echo "🔐 Проверяю SSH..."
ssh "${SSH_OPTS[@]}" "$REMOTE" "echo 'SSH OK: ' \$(hostname)"
echo

echo "📦 Заливаю файлы через rsync..."
rsync -avz --delete \
  "${RSYNC_EXCLUDE_ARGS[@]}" \
  -e "ssh ${SSH_KEY:+-i $SSH_KEY}" \
  "$PROJECT_DIR/" \
  "$REMOTE:$SERVER_PATH/"
echo

echo "♻️ Перезапускаю docker-контейнеры..."
ssh "${SSH_OPTS[@]}" "$REMOTE" bash <<EOF
set -Eeuo pipefail
cd "$SERVER_PATH"
docker-compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" restart app
EOF
echo

echo "📜 Последние логи app:"
ssh "${SSH_OPTS[@]}" "$REMOTE" bash <<EOF
set -Eeuo pipefail
cd "$SERVER_PATH"
docker logs qr-rest_app_1 --tail=40
EOF
echo

echo "✅ Деплой завершён"