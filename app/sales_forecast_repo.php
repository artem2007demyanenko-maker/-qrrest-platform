<?php
/**
 * Sales pipeline forecast: summary stats and active deals list for project-admin.
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

const SALES_FORECAST_ACTIVE_STATUSES = ['contacted', 'demo_scheduled', 'negotiation'];

/**
 * @return array{total_leads:int, active_deals:int, won_deals:int, lost_deals:int, forecast_mrr:float, closed_mrr:float}
 */
function sales_forecast_summary(): array
{
    $out = [
        'total_leads'   => 0,
        'active_deals'  => 0,
        'won_deals'     => 0,
        'lost_deals'    => 0,
        'forecast_mrr'  => 0.0,
        'closed_mrr'    => 0.0,
    ];
    try {
        $pdo = db();
        if (!function_exists('db_table_exists') || !db_table_exists('lead_requests')) {
            return $out;
        }
        $stmt = $pdo->query("SELECT COUNT(*) FROM lead_requests");
        $out['total_leads'] = (int)$stmt->fetchColumn();

        $placeholders = implode(',', array_fill(0, count(SALES_FORECAST_ACTIVE_STATUSES), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lead_requests WHERE status IN ($placeholders)");
        $stmt->execute(SALES_FORECAST_ACTIVE_STATUSES);
        $out['active_deals'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lead_requests WHERE status = 'won'");
        $stmt->execute();
        $out['won_deals'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lead_requests WHERE status = 'lost'");
        $stmt->execute();
        $out['lost_deals'] = (int)$stmt->fetchColumn();

        $hasMrr = function_exists('db_column_exists') && db_column_exists('lead_requests', 'expected_mrr');
        if ($hasMrr) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(expected_mrr), 0) FROM lead_requests WHERE status IN ($placeholders)");
            $stmt->execute(SALES_FORECAST_ACTIVE_STATUSES);
            $out['forecast_mrr'] = (float)$stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(expected_mrr), 0) FROM lead_requests WHERE status = 'won'");
            $stmt->execute();
            $out['closed_mrr'] = (float)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR sales_forecast_summary ' . $e->getMessage());
    }
    return $out;
}

/**
 * Active deals (contacted, demo_scheduled, negotiation) for table. Returns id, restaurant_name, city, status, expected_mrr, created_at.
 * @return list<array{id:int, restaurant_name:string, city:string, status:string, expected_mrr:?float, created_at:?string}>
 */
function sales_forecast_active_deals(int $limit = 200): array
{
    try {
        $pdo = db();
        if (!function_exists('db_table_exists') || !db_table_exists('lead_requests')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count(SALES_FORECAST_ACTIVE_STATUSES), '?'));
        $cols = 'id, restaurant_name, status, created_at';
        if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'city')) {
            $cols .= ', city';
        }
        if (function_exists('db_column_exists') && db_column_exists('lead_requests', 'expected_mrr')) {
            $cols .= ', expected_mrr';
        }
        $sql = "SELECT {$cols} FROM lead_requests WHERE status IN ($placeholders) ORDER BY created_at DESC LIMIT " . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(SALES_FORECAST_ACTIVE_STATUSES);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['city'] = $r['city'] ?? '';
            $r['expected_mrr'] = isset($r['expected_mrr']) && $r['expected_mrr'] !== null && $r['expected_mrr'] !== '' ? (float)$r['expected_mrr'] : null;
        }
        return $rows;
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR sales_forecast_active_deals ' . $e->getMessage());
        return [];
    }
}

/**
 * Monthly forecast data for chart: sum of expected_mrr by month(created_at) for active-status leads.
 * @return list<array{month:string, year:int, mrr:float}>
 */
function sales_forecast_monthly_forecast(): array
{
    try {
        $pdo = db();
        if (!function_exists('db_table_exists') || !db_table_exists('lead_requests')) {
            return [];
        }
        if (!function_exists('db_column_exists') || !db_column_exists('lead_requests', 'expected_mrr')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count(SALES_FORECAST_ACTIVE_STATUSES), '?'));
        $sql = "SELECT YEAR(created_at) AS y, MONTH(created_at) AS m, COALESCE(SUM(expected_mrr), 0) AS mrr
                FROM lead_requests
                WHERE status IN ($placeholders) AND created_at IS NOT NULL
                GROUP BY y, m
                ORDER BY y DESC, m DESC
                LIMIT 12";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(SALES_FORECAST_ACTIVE_STATUSES);
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'month' => sprintf('%04d-%02d', (int)$row['y'], (int)$row['m']),
                'year'  => (int)$row['y'],
                'mrr'   => (float)$row['mrr'],
            ];
        }
        return array_reverse($rows);
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR sales_forecast_monthly_forecast ' . $e->getMessage());
        return [];
    }
}
