#!/usr/bin/env bash
# Link audit: request key URLs and print HTTP status codes. No pass/fail, info only.
# Usage: BASE_URL=http://127.0.0.1 MAIN_HOST=lvh.me DEMO_HOST=demo.lvh.me ./scripts/link_audit.sh
# From container: docker compose exec -T web bash scripts/link_audit.sh

BASE_URL="${BASE_URL:-http://127.0.0.1}"
MAIN_HOST="${MAIN_HOST:-lvh.me}"
DEMO_HOST="${DEMO_HOST:-demo.lvh.me}"

audit() {
    local method="${1:-GET}"
    local url="$2"
    local host="$3"
    local code
    if [ -n "$host" ]; then
        code=$(curl -sI -o /dev/null -w "%{http_code}" -H "Host: $host" "$url" 2>/dev/null || echo "ERR")
    else
        code=$(curl -sI -o /dev/null -w "%{http_code}" "$url" 2>/dev/null || echo "ERR")
    fi
    printf "  %-6s %s\n" "$code" "$url"
}

echo "=== PUBLIC / MAIN (Host: $MAIN_HOST) ==="
audit GET "$BASE_URL/health.php" "$MAIN_HOST"
audit GET "$BASE_URL/ready.php" "$MAIN_HOST"
audit GET "$BASE_URL/saas.php" "$MAIN_HOST"
audit GET "$BASE_URL/signup.php" "$MAIN_HOST"
audit GET "$BASE_URL/login.php" "$MAIN_HOST"

echo ""
echo "=== PUBLIC / DEMO (Host: $DEMO_HOST) ==="
audit GET "$BASE_URL/qr.php?table_id=1" "$DEMO_HOST"
audit GET "$BASE_URL/qr_offers.php?table_id=1" "$DEMO_HOST"
audit GET "$BASE_URL/order_track.php?order_id=1" "$DEMO_HOST"

echo ""
echo "=== RESTAURANT unauthenticated (Host: $DEMO_HOST) — expect 302 ==="
for path in dashboard.php setup.php qr_print.php revenue.php crm.php activate.php import_menu.php upsells.php upsell_rules.php analytics_upsell.php crm_campaigns.php; do
    audit GET "$BASE_URL/restaurant/$path" "$DEMO_HOST"
done

echo ""
echo "=== PROJECT-ADMIN unauthenticated (Host: $MAIN_HOST) — expect 302 ==="
audit GET "$BASE_URL/project-admin/" "$MAIN_HOST"
audit GET "$BASE_URL/project-admin/leads.php" "$MAIN_HOST"
audit GET "$BASE_URL/project-admin/lead_view.php?id=1" "$MAIN_HOST"
audit GET "$BASE_URL/project-admin/lead_scoring.php" "$MAIN_HOST"
audit GET "$BASE_URL/project-admin/sales_forecast.php" "$MAIN_HOST"
audit GET "$BASE_URL/project-admin/diagnostics.php" "$MAIN_HOST"
audit GET "$BASE_URL/project-admin/error_view.php?rid=test" "$MAIN_HOST"

echo ""
echo "=== OWNER unauthenticated (Host: $MAIN_HOST) — expect 302 ==="
audit GET "$BASE_URL/owner/dashboard.php?range=7d&rest_id=all" "$MAIN_HOST"
audit GET "$BASE_URL/owner/billing.php" "$MAIN_HOST"

echo ""
echo "Done. Check codes: 200 OK, 302 redirect, 404 not found, 500 server error."
