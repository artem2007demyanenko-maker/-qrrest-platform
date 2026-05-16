#!/usr/bin/env bash
set -euo pipefail

# Automated smoke checks for split upsell (menu/cart).
# Requires:
# - running app endpoint (BASE_URL)
# - DB access from .env in current project
#
# Usage:
#   BASE_URL="https://test.qrrest-menu.ru" SUBDOMAIN="test" ./scripts/upsell_split_smoke.sh

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

BASE_URL="${BASE_URL:-https://test.qrrest-menu.ru}"
SUBDOMAIN="${SUBDOMAIN:-test}"
ENV_FILE="${ENV_FILE:-}"
if [[ -z "$ENV_FILE" ]]; then
  if [[ -f ".env" ]]; then
    ENV_FILE=".env"
  elif [[ -f ".env.production" ]]; then
    ENV_FILE=".env.production"
  fi
fi

if [[ -z "$ENV_FILE" || ! -f "$ENV_FILE" ]]; then
  echo "ERROR: env file not found. Set ENV_FILE=/path/to/.env (or place .env/.env.production in project root)."
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

: "${DB_HOST:?DB_HOST missing}"
: "${DB_PORT:=3306}"
: "${DB_DATABASE:?DB_DATABASE missing}"
: "${DB_USERNAME:?DB_USERNAME missing}"
: "${DB_PASSWORD:?DB_PASSWORD missing}"

mysql_q() {
  mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -Nse "$1"
}

json_get_bool() {
  local key="$1"
  php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    $k = $argv[1];
    $v = $d[$k] ?? null;
    echo ($v ? "true" : "false");
  ' "$key"
}

json_get_count() {
  local key="$1"
  php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    $k = $argv[1];
    $v = $d[$k] ?? [];
    echo is_array($v) ? count($v) : 0;
  ' "$key"
}

assert_true() {
  local msg="$1"
  local cond="$2"
  if [[ "$cond" != "true" ]]; then
    echo "FAIL: $msg"
    exit 1
  fi
  echo "OK: $msg"
}

assert_false() {
  local msg="$1"
  local cond="$2"
  if [[ "$cond" != "false" ]]; then
    echo "FAIL: $msg"
    exit 1
  fi
  echo "OK: $msg"
}

assert_le() {
  local msg="$1"
  local v="$2"
  local max="$3"
  if (( v > max )); then
    echo "FAIL: $msg (got=$v max=$max)"
    exit 1
  fi
  echo "OK: $msg"
}

assert_eq() {
  local msg="$1"
  local a="$2"
  local b="$3"
  if [[ "$a" != "$b" ]]; then
    echo "FAIL: $msg (got=$a expected=$b)"
    exit 1
  fi
  echo "OK: $msg"
}

echo "== Resolve restaurant/table/items =="
RID="$(mysql_q "SELECT id FROM restaurants WHERE subdomain='${SUBDOMAIN}' LIMIT 1;")"
if [[ -z "$RID" ]]; then
  echo "ERROR: restaurant not found for subdomain=${SUBDOMAIN}"
  exit 1
fi
echo "restaurant_id=$RID"

TABLE_ID="$(mysql_q "SELECT id FROM tables WHERE restaurant_id=${RID} ORDER BY id ASC LIMIT 1;")"
if [[ -z "$TABLE_ID" ]]; then
  echo "ERROR: no table found for restaurant_id=${RID}"
  exit 1
fi
echo "table_id=$TABLE_ID"

ITEMS="$(mysql_q "SELECT id FROM menu_items WHERE restaurant_id=${RID} AND available=1 ORDER BY id DESC LIMIT 3;")"
ITEM1="$(echo "$ITEMS" | sed -n '1p')"
ITEM2="$(echo "$ITEMS" | sed -n '2p')"
ITEM3="$(echo "$ITEMS" | sed -n '3p')"
if [[ -z "$ITEM1" || -z "$ITEM2" ]]; then
  echo "ERROR: need at least 2 available menu items"
  exit 1
fi
if [[ -z "$ITEM3" ]]; then
  ITEM3="$ITEM2"
fi
echo "trigger_item=$ITEM1 suggested=[$ITEM2,$ITEM3]"

echo "== Verify required columns for split + combo =="
REQUIRED_COLS=(
  guest_menu_upsell_enabled
  guest_menu_upsell_limit
  guest_menu_upsell_manual_only
  guest_cart_upsell_enabled
  guest_cart_upsell_limit
  guest_cart_upsell_use_manual
  guest_cart_upsell_use_contextual
  guest_cart_upsell_use_popular
  guest_menu_combo_enabled
  guest_menu_combo_limit
  guest_cart_combo_enabled
  guest_cart_combo_limit
)
for c in "${REQUIRED_COLS[@]}"; do
  v="$(mysql_q "SHOW COLUMNS FROM restaurants LIKE '${c}';" || true)"
  if [[ -z "$v" ]]; then
    echo "ERROR: missing column restaurants.${c}"
    exit 1
  fi
done

combo_table="$(mysql_q "SHOW TABLES LIKE 'combo_rules';" || true)"
if [[ -z "$combo_table" ]]; then
  echo "ERROR: combo_rules table is missing"
  exit 1
fi

echo "== Backup restaurant settings =="
ORIG="$(mysql_q "
SELECT
  guest_menu_upsell_enabled, guest_menu_upsell_limit, guest_menu_upsell_manual_only,
  guest_cart_upsell_enabled, guest_cart_upsell_limit,
  guest_cart_upsell_use_manual, guest_cart_upsell_use_contextual, guest_cart_upsell_use_popular,
  guest_menu_combo_enabled, guest_menu_combo_limit,
  guest_cart_combo_enabled, guest_cart_combo_limit
FROM restaurants WHERE id=${RID} LIMIT 1;
")"

cleanup() {
  set +e
  if [[ -n "${COMBO_ID:-}" ]]; then
    mysql_q "DELETE FROM combo_rules WHERE id=${COMBO_ID} AND restaurant_id=${RID};" >/dev/null 2>&1
  fi
  if [[ -n "${ORIG:-}" ]]; then
    IFS=$'\t' read -r m_en m_lim m_man c_en c_lim c_man c_ctx c_pop mc_en mc_lim cc_en cc_lim <<< "$ORIG"
    mysql_q "
      UPDATE restaurants SET
        guest_menu_upsell_enabled=${m_en},
        guest_menu_upsell_limit=${m_lim},
        guest_menu_upsell_manual_only=${m_man},
        guest_cart_upsell_enabled=${c_en},
        guest_cart_upsell_limit=${c_lim},
        guest_cart_upsell_use_manual=${c_man},
        guest_cart_upsell_use_contextual=${c_ctx},
        guest_cart_upsell_use_popular=${c_pop},
        guest_menu_combo_enabled=${mc_en},
        guest_menu_combo_limit=${mc_lim},
        guest_cart_combo_enabled=${cc_en},
        guest_cart_combo_limit=${cc_lim}
      WHERE id=${RID};
    " >/dev/null 2>&1
  fi
}
trap cleanup EXIT

echo "== Seed combo rule for deterministic checks =="
mysql_q "
INSERT INTO combo_rules (restaurant_id, trigger_item_id, suggested_item_ids, priority, active)
VALUES (${RID}, ${ITEM1}, JSON_ARRAY(${ITEM2}, ${ITEM3}), 999, 1);
"
COMBO_ID="$(mysql_q "SELECT LAST_INSERT_ID();")"
if [[ -z "$COMBO_ID" ]]; then
  COMBO_ID="$(mysql_q "SELECT id FROM combo_rules WHERE restaurant_id=${RID} AND trigger_item_id=${ITEM1} ORDER BY id DESC LIMIT 1;")"
fi
echo "combo_id=$COMBO_ID"

req_json() {
  local item_id="$1"
  curl -fsS -c /tmp/upsell_split_cookie.txt -b /tmp/upsell_split_cookie.txt \
    -X POST \
    -d "add_item=1" \
    -d "item_id=${item_id}" \
    "${BASE_URL}/qr.php?table_id=${TABLE_ID}&ajax=1"
}

echo "== Case A: menu ON, cart OFF => only one-tap =="
mysql_q "
UPDATE restaurants SET
  guest_menu_upsell_enabled=1,
  guest_menu_upsell_limit=1,
  guest_menu_upsell_manual_only=1,
  guest_menu_combo_enabled=1,
  guest_menu_combo_limit=1,
  guest_cart_upsell_enabled=0,
  guest_cart_combo_enabled=0
WHERE id=${RID};
"
JSON_A="$(req_json "$ITEM1")"
A_MENU_FLAG="$(printf '%s' "$JSON_A" | json_get_bool menu_upsell_guest_on)"
A_CART_FLAG="$(printf '%s' "$JSON_A" | json_get_bool cart_upsell_guest_on)"
A_MENU_CNT="$(printf '%s' "$JSON_A" | json_get_count menu_upsells)"
A_CART_CNT="$(printf '%s' "$JSON_A" | json_get_count cart_upsells)"
assert_true "menu flag true in Case A" "$A_MENU_FLAG"
assert_false "cart flag false in Case A" "$A_CART_FLAG"
assert_le "menu_upsells respects limit<=1 in Case A" "$A_MENU_CNT" 1
assert_eq "cart_upsells hidden in Case A" "$A_CART_CNT" "0"

echo "== Case B: menu OFF, cart ON => only cart block =="
mysql_q "
UPDATE restaurants SET
  guest_menu_upsell_enabled=0,
  guest_cart_upsell_enabled=1,
  guest_cart_upsell_limit=3,
  guest_cart_upsell_use_manual=1,
  guest_cart_upsell_use_contextual=0,
  guest_cart_upsell_use_popular=0,
  guest_cart_combo_enabled=1,
  guest_cart_combo_limit=3
WHERE id=${RID};
"
JSON_B="$(req_json "$ITEM1")"
B_MENU_FLAG="$(printf '%s' "$JSON_B" | json_get_bool menu_upsell_guest_on)"
B_CART_FLAG="$(printf '%s' "$JSON_B" | json_get_bool cart_upsell_guest_on)"
B_MENU_CNT="$(printf '%s' "$JSON_B" | json_get_count menu_upsells)"
B_CART_CNT="$(printf '%s' "$JSON_B" | json_get_count cart_upsells)"
assert_false "menu flag false in Case B" "$B_MENU_FLAG"
assert_true "cart flag true in Case B" "$B_CART_FLAG"
assert_eq "menu_upsells hidden in Case B" "$B_MENU_CNT" "0"
assert_true "cart_upsells present in Case B" "$([[ "$B_CART_CNT" -ge 1 ]] && echo true || echo false)"

echo "== Case C: cart sources all OFF + combo OFF => empty cart_upsells =="
mysql_q "
UPDATE restaurants SET
  guest_menu_upsell_enabled=0,
  guest_cart_upsell_enabled=1,
  guest_cart_upsell_use_manual=0,
  guest_cart_upsell_use_contextual=0,
  guest_cart_upsell_use_popular=0,
  guest_cart_combo_enabled=0
WHERE id=${RID};
"
JSON_C="$(req_json "$ITEM1")"
C_CART_CNT="$(printf '%s' "$JSON_C" | json_get_count cart_upsells)"
assert_eq "cart_upsells empty when all cart sources OFF" "$C_CART_CNT" "0"

echo "== DB checks =="
echo "-- combo_rules:"
mysql_q "SELECT id, restaurant_id, trigger_item_id, suggested_item_ids, priority, active FROM combo_rules WHERE restaurant_id=${RID} ORDER BY id DESC LIMIT 5;"
echo "-- menu_item_upsells:"
mysql_q "SELECT id, restaurant_id, base_item_id, upsell_item_id, weight, active FROM menu_item_upsells WHERE restaurant_id=${RID} ORDER BY id DESC LIMIT 5;"

echo "All split upsell smoke checks passed."
