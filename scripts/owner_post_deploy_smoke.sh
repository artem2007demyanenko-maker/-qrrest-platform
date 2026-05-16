#!/usr/bin/env bash
set -euo pipefail

# Owner post-deploy smoke for QR Restaurant SaaS.
#
# What it checks:
# 1) health.php payload (db ok + writable_checks)
# 2) owner UI pages via Playwright (desktop + mobile)
#    - warning/fallback content for partial schema (optional expectations)
#    - POST blocked / buttons disabled where applicable
#    - mobile drawer + cookie banner overlay sanity
# 3) Optional MySQL schema simulation via RENAME TABLE ... TO ..._backup_owner_smoke
#
# Usage (baseline, no schema simulation):
#   ./scripts/owner_post_deploy_smoke.sh --domain qrrest-menu.ru --base-url https://qrrest-menu.ru
#
# Usage (with schema simulation):
#   ./scripts/owner_post_deploy_smoke.sh \
#     --domain qrrest-menu.ru \
#     --base-url https://qrrest-menu.ru \
#     --project-path /opt/qr-rest \
#     --simulate-missing \
#     --db-container qr-rest_db_1
#
# Notes:
# - For simulation you must provide a MySQL container where `mysql` client exists.
# - The script always restores renamed tables via trap, even if checks fail.

PROJECT_PATH="/opt/qr-rest"
BASE_URL=""
DOMAIN=""
DB_CONTAINER=""
SIMULATE="0"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --project-path)
      PROJECT_PATH="${2:?}"; shift 2;;
    --domain)
      DOMAIN="${2:?}"; shift 2;;
    --base-url)
      BASE_URL="${2:?}"; shift 2;;
    --db-container)
      DB_CONTAINER="${2:?}"; shift 2;;
    --simulate-missing)
      SIMULATE="1"; shift 1;;
    -h|--help)
      echo "Usage: $0 --base-url https://<domain> [--project-path /opt/qr-rest] [--db-container <db_container>] [--simulate-missing]"
      exit 0;;
    *)
      echo "Unknown arg: $1"; exit 2;;
  esac
done

if [[ -z "$BASE_URL" ]]; then
  if [[ -n "$DOMAIN" ]]; then
    BASE_URL="https://${DOMAIN}"
  else
    echo "Missing --base-url (or --domain)"; exit 2
  fi
fi

if [[ "$SIMULATE" == "1" && -z "$DB_CONTAINER" ]]; then
  echo "When using --simulate-missing you must pass --db-container"
  exit 2
fi

ENV_FILE="${PROJECT_PATH}/.env.production"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing env file: $ENV_FILE"
  exit 2
fi

TS="$(date +%Y%m%d_%H%M%S)"
OUT_LOG="${PROJECT_PATH}/storage/logs/owner_smoke_${TS}.log"
ERR_LOG="${PROJECT_PATH}/storage/logs/owner_smoke_${TS}.error.log"
mkdir -p "${PROJECT_PATH}/storage/logs" || true
touch "$OUT_LOG" "$ERR_LOG"
exec > >(tee -a "$OUT_LOG") 2> >(tee -a "$ERR_LOG" >&2)
echo "Logs: stdout=${OUT_LOG} stderr=${ERR_LOG}"

read_env() {
  local key="$1"
  # shellcheck disable=SC2002
  grep -E "^${key}=" "$ENV_FILE" | tail -n 1 | sed -E 's/^'${key}'=//' | sed -E 's/^"//; s/"$//'
}

DB_USER="$(read_env DB_USER)"
DB_PASS="$(read_env DB_PASS)"
DB_NAME="$(read_env DB_NAME)"
DB_PORT="$(read_env DB_PORT)"

if [[ -z "$DB_USER" || -z "$DB_NAME" ]]; then
  echo "Could not read DB_USER/DB_NAME from $ENV_FILE"
  exit 2
fi

echo "== Owner smoke: health.php =="
HEALTH_JSON="$(curl -sS "${BASE_URL}/health.php")"
echo "$HEALTH_JSON" | php "${SCRIPT_DIR}/owner_post_deploy_smoke_health.php" "$BASE_URL"
echo "health writable_checks:"
echo "$HEALTH_JSON" | jq '.writable_checks' || true

echo "== Owner smoke: baseline UI (no simulation) =="
node "${SCRIPT_DIR}/owner_post_deploy_smoke.js" \
  --base-url "$BASE_URL" \
  --expect-warnings ""

if [[ "$SIMULATE" != "1" ]]; then
  echo "== Done (no simulation) =="
  exit 0
fi

mysql_exec() {
  # Runs a single SQL string inside the DB container.
  local sql="$1"
  docker exec \
    -e MYSQL_PWD="$DB_PASS" \
    "$DB_CONTAINER" \
    mysql -u"$DB_USER" -D"$DB_NAME" -N -s -e "$sql"
}

table_exists() {
  local t="$1"
  mysql_exec "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='${t}' LIMIT 1;"
}

rename_table_if_exists() {
  local t="$1"
  local backup="${t}_backup_owner_smoke"
  if [[ -z "$(table_exists "$t")" ]]; then
    return 0
  fi
  if [[ -n "$(table_exists "$backup")" ]]; then
    return 0
  fi
  echo "Renaming ${t} -> ${backup}"
  mysql_exec "RENAME TABLE \`${t}\` TO \`${backup}\`;"
  RENAMED_TABLES+=("$t")
}

restore_table() {
  local t="$1"
  local backup="${t}_backup_owner_smoke"
  if [[ -n "$(table_exists "$backup")" ]]; then
    echo "Restoring ${backup} -> ${t}"
    mysql_exec "RENAME TABLE \`${backup}\` TO \`${t}\`;"
  fi
}

cleanup() {
  if [[ "${#RENAMED_TABLES[@]:-0}" -gt 0 ]]; then
    echo "== Cleanup: restoring renamed tables =="
    for t in "${RENAMED_TABLES[@]}"; do
      restore_table "$t"
    done
  fi
}
trap cleanup EXIT

echo "== Owner smoke: simulation stages =="
RENAMED_TABLES=()
TOTAL_CHECKS=0
FAILED_CHECKS=0

run_stage() {
  local stage="$1"
  local expect="$2"
  shift 2
  local -a tables=("$@")
  TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
  echo "-- Stage ${stage}: missing tables => ${tables[*]} --"
  for t in "${tables[@]}"; do rename_table_if_exists "$t"; done
  if node "${SCRIPT_DIR}/owner_post_deploy_smoke.js" --base-url "$BASE_URL" --expect-warnings "$expect"; then
    echo "PASS stage:${stage}"
  else
    echo "FAIL stage:${stage}"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
  fi
  for t in "${tables[@]}"; do restore_table "$t"; done
  RENAMED_TABLES=()
}

stage_dashboard_tables=(orders order_items menu_items guests loyalty_transactions usage_metrics_daily)
run_stage "dashboard" "dashboard,crm,revenue" "${stage_dashboard_tables[@]}"

stage_crm_tables=(guests crm_campaigns crm_visits)
run_stage "crm" "crm,crm_campaigns" "${stage_crm_tables[@]}"

stage_loyalty_tables=(restaurant_loyalty_settings)
run_stage "loyalty_settings" "loyalty_settings" "${stage_loyalty_tables[@]}"

stage_cc_tables=(crm_templates crm_campaigns)
run_stage "crm_campaigns" "crm_campaigns" "${stage_cc_tables[@]}"

stage_rev_tables=(orders order_items menu_items)
run_stage "revenue" "revenue,dashboard" "${stage_rev_tables[@]}"

echo "== Done (with simulation) =="
if [[ "$FAILED_CHECKS" -gt 0 ]]; then
  echo "FAIL owner smoke: ${FAILED_CHECKS}/${TOTAL_CHECKS} simulation stages failed"
  exit 1
fi
echo "PASS owner smoke: all simulation stages passed (${TOTAL_CHECKS})"

