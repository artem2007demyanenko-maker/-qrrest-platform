#!/usr/bin/env bash
SSH_OPTS=(-o StrictHostKeyChecking=no)

#######################################
# CONFIG
#######################################
LOCAL_PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
REMOTE_USER="root"
REMOTE_HOST="178.159.94.192"
REMOTE_PATH="/opt/qr-rest"

# Если нужен другой ssh-порт, раскомментируй:
# SSH_PORT="22"

EXCLUDES=(
  ".git"
  ".DS_Store"
  ".idea"
  ".vscode"
  "node_modules"
  ".env"
  ".env.local"
  ".env.development"
)

#######################################
# DEFAULT FLAGS
#######################################
MODE="full"              # full | file | dry-run
TARGET_FILE=""
WITH_DELETE="0"          # 0 = не удалять лишнее на сервере
WITH_BACKUP="1"          # 1 = делать бэкап перед деплоем
RESTART_APP="1"          # 1 = рестарт контейнера app после деплоя

#######################################
# HELP
#######################################
print_help() {
  cat <<EOF
Использование:
  ./deploy.sh [опции]

Режимы:
  --full                  Залить весь проект
  --file PATH             Залить только один файл относительно корня проекта
  --dry-run               Только показать, что изменится

Опции:
  --delete                Удалять на сервере файлы, которых нет локально
  --no-backup             Не делать бэкап на сервере
  --no-restart            Не перезапускать app-контейнер
  --help                  Показать эту справку

Примеры:
  ./deploy.sh --full
  ./deploy.sh --dry-run
  ./deploy.sh --file public_html/saas.php
  ./deploy.sh --file public_html/staff/orders.php
  ./deploy.sh --full --delete
EOF
}

#######################################
# PARSE ARGS
#######################################
while [[ $# -gt 0 ]]; do
  case "$1" in
    --full)
      MODE="full"
      shift
      ;;
    --file)
      MODE="file"
      TARGET_FILE="${2:-}"
      if [[ -z "$TARGET_FILE" ]]; then
        echo "❌ После --file нужно указать путь"
        exit 1
      fi
      shift 2
      ;;
    --dry-run)
      MODE="dry-run"
      shift
      ;;
    --delete)
      WITH_DELETE="1"
      shift
      ;;
    --no-backup)
      WITH_BACKUP="0"
      shift
      ;;
    --no-restart)
      RESTART_APP="0"
      shift
      ;;
    --help|-h)
      print_help
      exit 0
      ;;
    *)
      echo "❌ Неизвестный аргумент: $1"
      print_help
      exit 1
      ;;
  esac
done

#######################################
# SSH OPTS
#######################################
SSH_OPTS=(
  -o StrictHostKeyChecking=accept-new
  -o ServerAliveInterval=30
  -o ServerAliveCountMax=10
)

if [[ -n "${SSH_PORT:-}" ]]; then
  SSH_OPTS+=(-p "$SSH_PORT")
fi

#######################################
# CHECKS
#######################################
if [[ ! -d "$LOCAL_PROJECT_DIR" ]]; then
  echo "❌ Локальная папка проекта не найдена: $LOCAL_PROJECT_DIR"
  exit 1
fi

if [[ ! -f "$LOCAL_PROJECT_DIR/docker-compose.prod.yml" ]]; then
  echo "❌ Не найден docker-compose.prod.yml в $LOCAL_PROJECT_DIR"
  echo "   Запускай deploy.sh из корня проекта qr-rest"
  exit 1
fi

if [[ ! -f "$LOCAL_PROJECT_DIR/.env.production" ]]; then
  echo "❌ Не найден .env.production в $LOCAL_PROJECT_DIR"
  exit 1
fi

if [[ "$MODE" == "file" ]]; then
  if [[ ! -f "$LOCAL_PROJECT_DIR/$TARGET_FILE" ]]; then
    echo "❌ Локальный файл не найден: $LOCAL_PROJECT_DIR/$TARGET_FILE"
    exit 1
  fi
fi

#######################################
# BUILD RSYNC EXCLUDES
#######################################
RSYNC_EXCLUDES=()
for item in "${EXCLUDES[@]}"; do
  RSYNC_EXCLUDES+=(--exclude="$item")
done

#######################################
# PRETTY INFO
#######################################
echo "🚀 Запуск деплоя"
echo "   Локальный проект: $LOCAL_PROJECT_DIR"
echo "   Сервер: $REMOTE_USER@$REMOTE_HOST"
echo "   Путь на сервере: $REMOTE_PATH"
echo "   Режим: $MODE"

if [[ "$MODE" == "file" ]]; then
  echo "   Файл: $TARGET_FILE"
fi

if [[ "$WITH_DELETE" == "1" ]]; then
  echo "   Удаление лишних файлов на сервере: ДА"
else
  echo "   Удаление лишних файлов на сервере: НЕТ"
fi

if [[ "$WITH_BACKUP" == "1" ]]; then
  echo "   Бэкап: ДА"
else
  echo "   Бэкап: НЕТ"
fi

#######################################
# SSH CHECK
#######################################
echo
echo "🔐 Проверяю SSH..."
ssh "${SSH_OPTS[@]}" "$REMOTE_USER@$REMOTE_HOST" "echo connected" >/dev/null
echo "✅ SSH OK"

#######################################
# REMOTE PREPARE
#######################################
echo
echo "📁 Готовлю папку на сервере..."
ssh "${SSH_OPTS[@]}" "$REMOTE_USER@$REMOTE_HOST" "mkdir -p '$REMOTE_PATH'"

#######################################
# BACKUP
#######################################
if [[ "$WITH_BACKUP" == "1" && "$MODE" != "dry-run" ]]; then
  echo
  echo "💾 Делаю бэкап на сервере..."
  ssh "${SSH_OPTS[@]}" "$REMOTE_USER@$REMOTE_HOST" "
    if [ -d '$REMOTE_PATH' ]; then
      mkdir -p '${REMOTE_PATH}_backups'
      TS=\$(date +%Y%m%d_%H%M%S)
      tar -czf '${REMOTE_PATH}_backups/backup_\$TS.tar.gz' -C '$REMOTE_PATH' . 2>/dev/null || true
      echo 'backup_done'
    fi
  " >/dev/null
  echo "✅ Бэкап сделан"
fi

#######################################
# RSYNC FLAGS
#######################################
RSYNC_FLAGS=(-avz)

if [[ "$WITH_DELETE" == "1" ]]; then
  RSYNC_FLAGS+=(--delete)
fi

if [[ "$MODE" == "dry-run" ]]; then
  RSYNC_FLAGS+=(--dry-run)
fi

#######################################
# DEPLOY
#######################################
echo
if [[ "$MODE" == "file" ]]; then
  echo "📤 Заливаю один файл..."
  REMOTE_DIR="$(dirname "$REMOTE_PATH/$TARGET_FILE")"

  ssh "${SSH_OPTS[@]}" "$REMOTE_USER@$REMOTE_HOST" "mkdir -p '$REMOTE_DIR'"

  rsync \
    "${RSYNC_FLAGS[@]}" \
    -e "ssh ${SSH_OPTS[*]}" \
    "$LOCAL_PROJECT_DIR/$TARGET_FILE" \
    "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/$TARGET_FILE"

else
  if [[ "$MODE" == "dry-run" ]]; then
    echo "🧪 Показываю изменения без заливки..."
  else
    echo "📤 Заливаю проект..."
  fi

  rsync \
    "${RSYNC_FLAGS[@]}" \
    "${RSYNC_EXCLUDES[@]}" \
    -e "ssh ${SSH_OPTS[*]}" \
    "$LOCAL_PROJECT_DIR/" \
    "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH/"
fi

#######################################
# RESTART
#######################################
if [[ "$MODE" != "dry-run" && "$RESTART_APP" == "1" ]]; then
  echo
  echo "🔄 Перезапускаю app-контейнер..."
  ssh "${SSH_OPTS[@]}" "$REMOTE_USER@$REMOTE_HOST" "
    cd '$REMOTE_PATH' &&
    docker-compose --env-file .env.production -f docker-compose.prod.yml restart app
  "
  echo "✅ app-контейнер перезапущен"
fi

#######################################
# DONE
#######################################
echo
echo "🎉 Готово"