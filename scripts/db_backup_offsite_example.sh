#!/usr/bin/env bash
# Example: sync DB backups to offsite storage.
# This is a template for ops teams, not used automatically by the app.
#
# Usage (cron, on host or inside app container):
#   BACKUP_DIR=/var/www/html/backups \
#   OFFSITE_CMD="aws s3 sync /var/www/html/backups s3://my-bucket/qr-rest-backups" \
#   ./scripts/db_backup_offsite_example.sh
#
# Or with rsync:
#   OFFSITE_CMD="rsync -az --delete /var/www/html/backups backup@backup-host:/data/qr-rest-backups"

set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/backups}"
OFFSITE_CMD="${OFFSITE_CMD:-}"

if [[ -z "$OFFSITE_CMD" ]]; then
  echo "OFFSITE_CMD is not set. Example:"
  echo "  OFFSITE_CMD=\"aws s3 sync $BACKUP_DIR s3://my-bucket/qr-rest-backups\" $0"
  exit 1
fi

if [[ ! -d "$BACKUP_DIR" ]]; then
  echo "Backup directory not found: $BACKUP_DIR"
  exit 1
fi

echo "Running offsite backup sync from $BACKUP_DIR ..."
eval "$OFFSITE_CMD"
echo "Offsite backup sync completed."

