#!/usr/bin/env bash
# Smoke HTTP checks: health, redirects, login, owner pages, demo public pages. Exit 0 = all pass, 1 = fail.
# Run inside web container: docker compose exec -T web bash scripts/smoke_http.sh
# With login: SMOKE_EMAIL=... SMOKE_PASS=... docker compose exec -T web bash scripts/smoke_http.sh
# Production-like: BASE_URL=https://yourdomain.com MAIN_HOST=yourdomain.com DEMO_HOST=demo.yourdomain.com ./scripts/smoke_http.sh

BASE_URL="${BASE_URL:-http://127.0.0.1}"
MAIN_HOST="${MAIN_HOST:-lvh.me}"
DEMO_HOST="${DEMO_HOST:-demo.lvh.me}"
COOKIE_JAR="${COOKIE_JAR:-/tmp/smoke_cookie_jar.txt}"
PASS=0
FAIL=0

check() {
    local name="$1"
    local expect="$2"
    local got="$3"
    if [ "$got" = "$expect" ]; then
        echo "PASS $name (HTTP $got)"
        PASS=$((PASS + 1))
        return 0
    else
        echo "FAIL $name (expected HTTP $expect, got $got)"
        FAIL=$((FAIL + 1))
        return 1
    fi
}

# 1) Health returns 200 and body contains "status" and "app_env"
code=$(curl -s -o /tmp/smoke_health.txt -w "%{http_code}" "$BASE_URL/health.php")
check "health.php returns 200" "200" "$code"
if grep -q '"status"' /tmp/smoke_health.txt 2>/dev/null; then
    echo "PASS health.php body contains status"
    PASS=$((PASS + 1))
else
    echo "FAIL health.php body missing status"
    FAIL=$((FAIL + 1))
fi
if grep -q '"app_env"' /tmp/smoke_health.txt 2>/dev/null; then
    echo "PASS health.php body contains app_env"
    PASS=$((PASS + 1))
else
    echo "FAIL health.php body missing app_env"
    FAIL=$((FAIL + 1))
fi

# 1b) Ready returns 200 and body contains "status"
code_ready=$(curl -s -o /tmp/smoke_ready.txt -w "%{http_code}" "$BASE_URL/ready.php")
check "ready.php returns 200" "200" "$code_ready"
if grep -q '"status"' /tmp/smoke_ready.txt 2>/dev/null; then
    echo "PASS ready.php body contains status"
    PASS=$((PASS + 1))
else
    echo "FAIL ready.php body missing status"
    FAIL=$((FAIL + 1))
fi

# 2) Unauthenticated dashboard -> 302 to login (Host: main domain so redirect is to /login.php)
code=$(curl -sI -c "$COOKIE_JAR" -b "$COOKIE_JAR" -H "Host: $MAIN_HOST" "$BASE_URL/owner/dashboard.php?range=7d&rest_id=all" | head -1 | awk '{print $2}')
check "dashboard unauthenticated -> 302" "302" "$code"
loc=$(curl -sI -b "$COOKIE_JAR" -H "Host: $MAIN_HOST" "$BASE_URL/owner/dashboard.php?range=7d&rest_id=all" 2>/dev/null | grep -i "^Location:" | tr -d '\r' | cut -d' ' -f2-)
if echo "$loc" | grep -q "login\.php"; then
    echo "PASS redirect Location contains login.php"
    PASS=$((PASS + 1))
else
    echo "FAIL redirect Location missing login.php (got: $loc)"
    FAIL=$((FAIL + 1))
fi

# 3) Login page returns 200
code=$(curl -s -o /dev/null -w "%{http_code}" -H "Host: $MAIN_HOST" "$BASE_URL/login.php")
check "login.php returns 200" "200" "$code"

# 4) Demo subdomain public pages (Host: demo.lvh.me)
code=$(curl -s -o /tmp/smoke_qr.txt -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/qr.php?table_id=1")
check "demo qr.php?table_id=1 returns 200" "200" "$code"
if grep -q "qr_offers.php" /tmp/smoke_qr.txt 2>/dev/null; then
    echo "PASS qr.php contains qr_offers.php endpoint"
    PASS=$((PASS + 1))
else
    echo "FAIL qr.php must contain qr_offers.php"
    FAIL=$((FAIL + 1))
fi
if grep -q "restaurant_not_found" /tmp/smoke_qr.txt 2>/dev/null; then
    echo "FAIL demo qr.php must NOT contain restaurant_not_found"
    FAIL=$((FAIL + 1))
elif grep -qE '"success"|"enabled"|success|Ресторан|table_id' /tmp/smoke_qr.txt 2>/dev/null; then
    echo "PASS demo qr.php body valid (success/enabled or HTML)"
    PASS=$((PASS + 1))
else
    echo "PASS demo qr.php (200, no restaurant_not_found)"
    PASS=$((PASS + 1))
fi

code=$(curl -s -o /tmp/smoke_offers.txt -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/qr_offers.php?table_id=1")
check "demo qr_offers.php?table_id=1 returns 200" "200" "$code"
if grep -q '"items"' /tmp/smoke_offers.txt 2>/dev/null; then
    echo "PASS demo qr_offers.php body contains items"
    PASS=$((PASS + 1))
else
    echo "FAIL demo qr_offers.php body missing items"
    FAIL=$((FAIL + 1))
fi
if grep -q 'internal_error' /tmp/smoke_offers.txt 2>/dev/null; then
    echo "FAIL demo qr_offers.php must NOT contain internal_error"
    FAIL=$((FAIL + 1))
else
    echo "PASS demo qr_offers.php no internal_error"
    PASS=$((PASS + 1))
fi

code=$(curl -s -o /tmp/smoke_track.txt -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/order_track.php?order_id=1")
if [ "$code" = "200" ] || [ "$code" = "404" ]; then
    echo "PASS demo order_track.php?order_id=1 (HTTP $code)"
    PASS=$((PASS + 1))
else
    echo "FAIL demo order_track.php?order_id=1 (expected 200 or 404, got $code)"
    FAIL=$((FAIL + 1))
fi
if grep -q "Некорректные параметры" /tmp/smoke_track.txt 2>/dev/null; then
    echo "FAIL demo order_track.php must NOT contain Некорректные параметры for valid order_id"
    FAIL=$((FAIL + 1))
else
    echo "PASS demo order_track.php no invalid-params message"
    PASS=$((PASS + 1))
fi

# Optional: context upsell — add item to cart then GET qr_offers, expect items not empty
rm -f "$COOKIE_JAR"
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o /dev/null \
    -H "Host: $DEMO_HOST" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -X POST "${BASE_URL}/qr.php?table_id=1" \
    --data "add_item=1&item_id=1" 2>/dev/null || true
code_ctx=$(curl -s -o /tmp/smoke_offers_ctx.txt -w "%{http_code}" -b "$COOKIE_JAR" -H "Host: $DEMO_HOST" "$BASE_URL/qr_offers.php?table_id=1")
if [ "$code_ctx" != "200" ] || ! grep -q '"items"' /tmp/smoke_offers_ctx.txt 2>/dev/null || grep -q 'internal_error' /tmp/smoke_offers_ctx.txt 2>/dev/null; then
    echo "FAIL qr_offers context (need 200, items, no internal_error)"
    FAIL=$((FAIL + 1))
elif grep -qE '"items"\s*:\s*\[\s*\]' /tmp/smoke_offers_ctx.txt 2>/dev/null; then
    echo "FAIL qr_offers after add_item: items must not be empty array"
    FAIL=$((FAIL + 1))
else
    echo "PASS qr_offers after add_item (200, items non-empty)"
    PASS=$((PASS + 1))
fi

# 5) Optional: if SMOKE_EMAIL and SMOKE_PASS set, POST login and check owner pages
if [ -n "${SMOKE_EMAIL:-}" ] && [ -n "${SMOKE_PASS:-}" ]; then
    rm -f "$COOKIE_JAR"
    login_code=$(curl -s -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o /tmp/smoke_login.html -w "%{http_code}" \
        -X POST "$BASE_URL/login.php" \
        -d "email=$SMOKE_EMAIL&password=$SMOKE_PASS")
    if [ "$login_code" = "200" ] || [ "$login_code" = "302" ]; then
        echo "PASS POST login (HTTP $login_code)"
        PASS=$((PASS + 1))
    else
        echo "FAIL POST login (expected 200 or 302, got $login_code)"
        FAIL=$((FAIL + 1))
    fi

    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" "$BASE_URL/owner/dashboard.php?range=7d&rest_id=all")
    if [ "$code" = "200" ]; then
        echo "PASS GET dashboard.php (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET dashboard.php (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi

    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" "$BASE_URL/owner/billing.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET billing.php (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET billing.php (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi

    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" "$BASE_URL/owner/growth.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET growth.php (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET growth.php (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi

    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" "$BASE_URL/restaurant_public.php?rest_id=1")
    if [ "$code" = "200" ] || [ "$code" = "404" ]; then
        echo "PASS GET restaurant_public.php (HTTP $code, not 500)"
        PASS=$((PASS + 1))
    elif [ "$code" = "500" ]; then
        echo "FAIL GET restaurant_public.php (got 500)"
        FAIL=$((FAIL + 1))
    else
        echo "PASS GET restaurant_public.php (HTTP $code, not 500)"
        PASS=$((PASS + 1))
    fi
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/upsells.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET restaurant/upsells.php after login (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET restaurant/upsells.php after login (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/activate.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET restaurant/activate.php after login (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET restaurant/activate.php after login (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/crm.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET restaurant/crm.php after login (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET restaurant/crm.php after login (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/upsell_rules.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET restaurant/upsell_rules.php after login (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET restaurant/upsell_rules.php after login (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/crm_campaigns.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET restaurant/crm_campaigns.php after login (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET restaurant/crm_campaigns.php after login (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/import_menu.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET restaurant/import_menu.php after login (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET restaurant/import_menu.php after login (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" -H "Host: $MAIN_HOST" "$BASE_URL/project-admin/leads.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET project-admin/leads.php after login (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET project-admin/leads.php after login (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" -H "Host: $MAIN_HOST" "$BASE_URL/project-admin/lead_scoring.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET project-admin/lead_scoring.php after login (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET project-admin/lead_scoring.php after login (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" -H "Host: $MAIN_HOST" "$BASE_URL/project-admin/diagnostics.php")
    if [ "$code" = "200" ]; then
        echo "PASS GET project-admin/diagnostics.php after login (HTTP 200)"
        PASS=$((PASS + 1))
    else
        echo "FAIL GET project-admin/diagnostics.php after login (expected 200, got $code)"
        FAIL=$((FAIL + 1))
    fi
fi

# --- Demo checkout flow: create order and open tracking page ---
checkout_and_get_order_id() {
    local headers body loc order_id
    headers="$(mktemp)"
    body="$(mktemp)"
    # Add one item to cart, then checkout (use cookie jar so session is kept)
    curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o /dev/null \
        -H "Host: $DEMO_HOST" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -X POST "${BASE_URL}/qr.php?table_id=1" \
        --data "add_item=1&item_id=1" 2>/dev/null || true
    curl -sS -D "$headers" -b "$COOKIE_JAR" -o "$body" \
        -H "Host: $DEMO_HOST" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -X POST "${BASE_URL}/qr.php?table_id=1" \
        --data "checkout=1&payment_type=cash&qty[1]=1" 2>/dev/null || true
    loc="$(grep -i '^Location:' "$headers" 2>/dev/null | tail -n 1 | sed -E 's/^Location:[[:space:]]*//I' | tr -d '\r')"
    order_id="$(echo "$loc" | sed -nE 's/.*order_id=([0-9]+).*/\1/p' | head -n 1)"
    if [ -z "$order_id" ]; then
        order_id="$(grep -Eo 'order_id=([0-9]+)' "$body" 2>/dev/null | head -n 1 | sed -E 's/[^0-9]//g')"
    fi
    if [ -z "$order_id" ]; then
        order_id="$(grep -Eo '"order_id"[[:space:]]*:[[:space:]]*[0-9]+' "$body" 2>/dev/null | head -n 1 | sed -E 's/[^0-9]//g')"
    fi
    rm -f "$headers" "$body"
    echo "$order_id"
}

ORDER_ID="$(checkout_and_get_order_id)"
if [ -n "$ORDER_ID" ]; then
    code=$(curl -s -o /tmp/smoke_track2.txt -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/order_track.php?order_id=${ORDER_ID}")
    check "demo checkout created order -> order_track 200" "200" "$code"
    if grep -q "Некорректные параметры" /tmp/smoke_track2.txt 2>/dev/null; then
        echo "FAIL order_track should not show invalid params for order_id=$ORDER_ID"
        FAIL=$((FAIL + 1))
    else
        echo "PASS order_track no invalid-params message for checkout order"
        PASS=$((PASS + 1))
    fi
    code_ord=$(curl -s -o /tmp/smoke_offers_order.txt -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/qr_offers.php?table_id=1&order_id=${ORDER_ID}")
    if [ "$code_ord" = "200" ] && grep -q '"items"' /tmp/smoke_offers_order.txt 2>/dev/null; then
        echo "PASS qr_offers.php?table_id=1&order_id=ORDER_ID returns 200 with items"
        PASS=$((PASS + 1))
    else
        echo "FAIL qr_offers with order_id (expected 200 and items)"
        FAIL=$((FAIL + 1))
    fi
else
    echo "FAIL demo checkout could not extract order_id"
    FAIL=$((FAIL + 1))
fi

# restaurant/upsells.php: 302 without login, 200 with login (demo subdomain)
code_ups=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/upsells.php")
if [ "$code_ups" = "302" ]; then
    echo "PASS restaurant/upsells.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL restaurant/upsells.php unauthenticated (expected 302, got $code_ups)"
    FAIL=$((FAIL + 1))
fi

# restaurant/crm.php: 302 without login
code_crm=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/crm.php")
if [ "$code_crm" = "302" ]; then
    echo "PASS restaurant/crm.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL restaurant/crm.php unauthenticated (expected 302, got $code_crm)"
    FAIL=$((FAIL + 1))
fi

# restaurant/upsell_rules.php: 302 without login
code_ur=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/upsell_rules.php")
if [ "$code_ur" = "302" ]; then
    echo "PASS restaurant/upsell_rules.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL restaurant/upsell_rules.php unauthenticated (expected 302, got $code_ur)"
    FAIL=$((FAIL + 1))
fi

# restaurant/crm_campaigns.php: 302 without login
code_cc=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/crm_campaigns.php")
if [ "$code_cc" = "302" ]; then
    echo "PASS restaurant/crm_campaigns.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL restaurant/crm_campaigns.php unauthenticated (expected 302, got $code_cc)"
    FAIL=$((FAIL + 1))
fi

# restaurant/revenue.php: 302 without login
code_rev=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/revenue.php")
if [ "$code_rev" = "302" ]; then
    echo "PASS restaurant/revenue.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL restaurant/revenue.php unauthenticated (expected 302, got $code_rev)"
    FAIL=$((FAIL + 1))
fi

# restaurant/analytics_upsell.php: 302 without login
code_au=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/analytics_upsell.php")
if [ "$code_au" = "302" ]; then
    echo "PASS restaurant/analytics_upsell.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL restaurant/analytics_upsell.php unauthenticated (expected 302, got $code_au)"
    FAIL=$((FAIL + 1))
fi

# restaurant/import_menu.php: 302 without login
code_imp=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/import_menu.php")
if [ "$code_imp" = "302" ]; then
    echo "PASS restaurant/import_menu.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL restaurant/import_menu.php unauthenticated (expected 302, got $code_imp)"
    FAIL=$((FAIL + 1))
fi

# restaurant/import_menu_template.csv: 200 without login
code_tpl=$(curl -s -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/import_menu_template.csv")
if [ "$code_tpl" = "200" ]; then
    echo "PASS restaurant/import_menu_template.csv unauthenticated -> 200"
    PASS=$((PASS + 1))
else
    echo "FAIL restaurant/import_menu_template.csv unauthenticated (expected 200, got $code_tpl)"
    FAIL=$((FAIL + 1))
fi

# project-admin/ (index): 302 without login
code_pa=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $MAIN_HOST" "$BASE_URL/project-admin/")
if [ "$code_pa" = "302" ] || [ "$code_pa" = "200" ]; then
    echo "PASS project-admin/ unauthenticated -> $code_pa"
    PASS=$((PASS + 1))
else
    echo "FAIL project-admin/ unauthenticated (expected 302 or 200, got $code_pa)"
    FAIL=$((FAIL + 1))
fi

# project-admin/leads.php: 302 without login
code_paleads=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $MAIN_HOST" "$BASE_URL/project-admin/leads.php")
if [ "$code_paleads" = "302" ]; then
    echo "PASS project-admin/leads.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL project-admin/leads.php unauthenticated (expected 302, got $code_paleads)"
    FAIL=$((FAIL + 1))
fi

# project-admin/diagnostics.php: 302 without login
code_diag=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $MAIN_HOST" "$BASE_URL/project-admin/diagnostics.php")
if [ "$code_diag" = "302" ]; then
    echo "PASS project-admin/diagnostics.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL project-admin/diagnostics.php unauthenticated (expected 302, got $code_diag)"
    FAIL=$((FAIL + 1))
fi

# project-admin/error_view.php?rid=test: 302 without login
code_errview=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $MAIN_HOST" "$BASE_URL/project-admin/error_view.php?rid=test")
if [ "$code_errview" = "302" ]; then
    echo "PASS project-admin/error_view.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL project-admin/error_view.php unauthenticated (expected 302, got $code_errview)"
    FAIL=$((FAIL + 1))
fi

# project-admin/lead_view.php?id=1: 302 without login
code_paview=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $MAIN_HOST" "$BASE_URL/project-admin/lead_view.php?id=1")
if [ "$code_paview" = "302" ]; then
    echo "PASS project-admin/lead_view.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL project-admin/lead_view.php unauthenticated (expected 302, got $code_paview)"
    FAIL=$((FAIL + 1))
fi

# project-admin/lead_scoring.php: 302 without login
code_pascore=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $MAIN_HOST" "$BASE_URL/project-admin/lead_scoring.php")
if [ "$code_pascore" = "302" ]; then
    echo "PASS project-admin/lead_scoring.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL project-admin/lead_scoring.php unauthenticated (expected 302, got $code_pascore)"
    FAIL=$((FAIL + 1))
fi

# project-admin/sales_forecast.php: 302 without login
code_forecast=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $MAIN_HOST" "$BASE_URL/project-admin/sales_forecast.php")
if [ "$code_forecast" = "302" ]; then
    echo "PASS project-admin/sales_forecast.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL project-admin/sales_forecast.php unauthenticated (expected 302, got $code_forecast)"
    FAIL=$((FAIL + 1))
fi

# saas.php: 200 without login (main host)
code_saas=$(curl -s -o /tmp/smoke_saas.txt -w "%{http_code}" -H "Host: $MAIN_HOST" "$BASE_URL/saas.php")
if [ "$code_saas" = "200" ]; then
    echo "PASS GET saas.php -> 200"
    PASS=$((PASS + 1))
else
    echo "FAIL GET saas.php (expected 200, got $code_saas)"
    FAIL=$((FAIL + 1))
fi

# signup.php: 200 and body contains restaurant or регистрация
code_signup=$(curl -s -o /tmp/smoke_signup.txt -w "%{http_code}" -H "Host: $MAIN_HOST" "$BASE_URL/signup.php")
if [ "$code_signup" = "200" ]; then
    echo "PASS GET signup.php -> 200"
    PASS=$((PASS + 1))
else
    echo "FAIL GET signup.php (expected 200, got $code_signup)"
    FAIL=$((FAIL + 1))
fi
if grep -qE 'signup|restaurant|регистрация' /tmp/smoke_signup.txt 2>/dev/null; then
    echo "PASS signup.php body contains signup/restaurant/регистрация"
    PASS=$((PASS + 1))
else
    echo "FAIL signup.php body must contain signup or restaurant or регистрация"
    FAIL=$((FAIL + 1))
fi

# restaurant/setup.php: 302 without login (demo subdomain)
code_setup=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/setup.php")
if [ "$code_setup" = "302" ]; then
    echo "PASS GET restaurant/setup.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL GET restaurant/setup.php unauthenticated (expected 302, got $code_setup)"
    FAIL=$((FAIL + 1))
fi

# restaurant/qr_print.php: 302 without login (demo subdomain)
code_qrprint=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/qr_print.php")
if [ "$code_qrprint" = "302" ]; then
    echo "PASS GET restaurant/qr_print.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL GET restaurant/qr_print.php unauthenticated (expected 302, got $code_qrprint)"
    FAIL=$((FAIL + 1))
fi

# restaurant/dashboard.php: 302 without login (demo subdomain)
code_rdash=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/dashboard.php")
if [ "$code_rdash" = "302" ]; then
    echo "PASS GET restaurant/dashboard.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL GET restaurant/dashboard.php unauthenticated (expected 302, got $code_rdash)"
    FAIL=$((FAIL + 1))
fi

# restaurant/activate.php: 302 without login (demo subdomain)
code_act=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $DEMO_HOST" "$BASE_URL/restaurant/activate.php")
if [ "$code_act" = "302" ]; then
    echo "PASS GET restaurant/activate.php unauthenticated -> 302"
    PASS=$((PASS + 1))
else
    echo "FAIL GET restaurant/activate.php unauthenticated (expected 302, got $code_act)"
    FAIL=$((FAIL + 1))
fi

# stripe_webhook.php: must not 500 (GET may 405, POST without body may 400)
code_wh=$(curl -s -o /tmp/smoke_stripe_webhook.txt -w "%{http_code}" "$BASE_URL/stripe_webhook.php")
if [ "$code_wh" = "500" ]; then
    echo "FAIL GET stripe_webhook.php must not return 500 (got 500)"
    FAIL=$((FAIL + 1))
else
    echo "PASS stripe_webhook.php does not 500 (HTTP $code_wh)"
    PASS=$((PASS + 1))
fi

# ajax/upsell_event.php: POST returns 200 and success:true (no 500 even if table missing)
code_ue=$(curl -s -o /tmp/smoke_upsell_event.txt -w "%{http_code}" -H "Host: $DEMO_HOST" -X POST "$BASE_URL/ajax/upsell_event.php" -d "event=add_click&table_id=1&upsell_item_id=1")
if [ "$code_ue" = "200" ] && grep -q '"success":true' /tmp/smoke_upsell_event.txt 2>/dev/null; then
    echo "PASS ajax/upsell_event.php POST returns 200 and success:true"
    PASS=$((PASS + 1))
else
    echo "FAIL ajax/upsell_event.php (expected 200 and success:true, got $code_ue)"
    FAIL=$((FAIL + 1))
fi

echo "---"
echo "Total: $PASS passed, $FAIL failed"
if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
exit 0
