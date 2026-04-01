#!/usr/bin/env bash
# DB backup. Reads DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS from env.
# Writes to backups/ with timestamp. Exit 0 = success, 1 = failure.
# Usage: ./scripts/db_backup.sh   or   DB_NAME=qr_rest ./scripts/db_backup.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-$PROJECT_ROOT/backups}"
STAMP="$(date +%Y%m%d_%H%M%S)"

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-qr_rest}"
DB_USER="${DB_USER:-qr}"
DB_PASS="${DB_PASS:-qrpass}"

mkdir -p "$BACKUP_DIR"
OUTPUT_FILE="$BACKUP_DIR/${DB_NAME}_${STAMP}.sql"

if ! command -v mysqldump &>/dev/null; then
    echo "ERROR: mysqldump not found" >&2
    exit 1
fi

export MYSQL_PWD="$DB_PASS"
if mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --single-transaction --routines --triggers "$DB_NAME" > "$OUTPUT_FILE" 2>/dev/null; then
    unset MYSQL_PWD
    echo "OK: $OUTPUT_FILE"
    exit 0
fi
unset MYSQL_PWD
echo "ERROR: backup failed" >&2
exit 1
