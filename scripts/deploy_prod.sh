#!/usr/bin/env bash
# Production deploy: build and up. Requires .env.production. No destructive actions.
# Usage: ./scripts/deploy_prod.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

ENV_FILE=".env.production"
COMPOSE_FILE="docker-compose.prod.yml"

if [ ! -f "$ENV_FILE" ]; then
    echo "ERROR: $ENV_FILE not found. Copy .env.production.example to .env.production and fill values." >&2
    exit 1
fi

echo "Using $ENV_FILE and $COMPOSE_FILE"
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" build --pull
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d

echo ""
echo "Deploy started. Check containers:"
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" ps

echo ""
echo "Health check (set MAIN_DOMAIN if not yourdomain.com):"
MAIN_DOMAIN="${APP_MAIN_DOMAIN:-yourdomain.com}"
if [ -n "$APP_MAIN_DOMAIN" ]; then
    MAIN_DOMAIN="$APP_MAIN_DOMAIN"
fi
# Read from env file if available
if grep -q '^APP_MAIN_DOMAIN=' "$ENV_FILE" 2>/dev/null; then
    MAIN_DOMAIN=$(grep '^APP_MAIN_DOMAIN=' "$ENV_FILE" | cut -d= -f2- | tr -d '\r')
fi
echo "  https://${MAIN_DOMAIN}/health.php"
echo "  https://${MAIN_DOMAIN}/ready.php"
