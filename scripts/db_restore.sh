#!/usr/bin/env bash
# DB restore. Reads DB_* from env. Requires path to .sql dump.
# Asks confirmation unless --yes is passed.
# Usage: ./scripts/db_restore.sh /path/to/dump.sql [--yes]

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-qr_rest}"
DB_USER="${DB_USER:-qr}"
DB_PASS="${DB_PASS:-qrpass}"

DUMP_FILE=""
CONFIRM_YES=""
for arg in "$@"; do
    if [ "$arg" = "--yes" ] || [ "$arg" = "-y" ]; then
        CONFIRM_YES=1
    elif [ -z "$DUMP_FILE" ] && [ -f "$arg" ]; then
        DUMP_FILE="$arg"
    fi
done

if [ -z "$DUMP_FILE" ]; then
    echo "Usage: $0 /path/to/dump.sql [--yes]" >&2
    exit 1
fi

if [ -z "$CONFIRM_YES" ]; then
    echo "Restore $DUMP_FILE into $DB_NAME at $DB_HOST:$DB_PORT ? (y/N)"
    read -r ans
    if [ "$ans" != "y" ] && [ "$ans" != "Y" ]; then
        echo "Aborted."
        exit 0
    fi
fi

if ! command -v mysql &>/dev/null; then
    echo "ERROR: mysql client not found" >&2
    exit 1
fi

export MYSQL_PWD="$DB_PASS"
if mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" < "$DUMP_FILE" 2>/dev/null; then
    unset MYSQL_PWD
    echo "OK: restore completed"
    exit 0
fi
unset MYSQL_PWD
echo "ERROR: restore failed" >&2
exit 1
