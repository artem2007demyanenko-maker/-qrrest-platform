<?php
// public_html/owner/dashboard.php
// Сначала bootstrap (auth) и проверка авторизации — до любого вывода и domain guard.
require_once __DIR__ . '/../../app/bootstrap.php';
require_login();

require_once __DIR__ . '/../../app/schema_guard.php';
require_once __DIR__ . '/../../app/stats.php';
require_once __DIR__ . '/../../app/stats_cache.php';
require_once __DIR__ . '/../../app/alerts_repo.php';
require_once __DIR__ . '/../../app/billing.php';
require_once __DIR__ . '/../../app/growth.php';

// Domain guard: owner-панель только на основном домене (после require_login).
$config     = require __DIR__ . '/../../app/config.php';
$mainDomain = $config['app']['main_domain'] ?? 'localhost';
$protocol   = $config['app']['protocol'] ?? 'http';
$host       = $_SERVER['HTTP_HOST'] ?? '';
$host       = preg_replace('/:\d+$/', '', $host);
$isMainHost = (strtolower($host) === strtolower($mainDomain))
    || (strtolower($host) === strtolower('www.' . $mainDomain));
if (!$isMainHost) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/owner/dashboard.php';
    safe_redirect($protocol . '://' . $mainDomain . $uri);
}

require_role(['owner', 'project_owner']);
$user = auth_user();

set_exception_handler(function (Throwable $e) use ($user) {
    $uid = $user !== null ? (int)($user['id'] ?? 0) : (int)($_SESSION['user_id'] ?? 0);
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    error_log('STABILITY_ERROR ' . json_encode([
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'level' => 'exception',
        'user_id' => $uid,
        'uri' => $uri,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE));
    if (!headers_sent()) {
        http_response_code(500);
        $wantsJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Internal error. Try later.']);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка</title></head><body><p>Internal error. Try later.</p></body></html>';
        }
    }
    exit;
});
$pdo           = db();
// Usage metrics: раз в день (UTC) записываем срез для retention (только если таблица и restaurants.deleted_at есть)
if (($user['id'] ?? 0) > 0) {
    if (schema_guard_usage_metrics_ready() && db_column_exists('restaurants', 'deleted_at')) {
        try {
            growth_collect_usage_for_user((int)$user['id']);
        } catch (Throwable $e) {
            error_log('STABILITY_ERROR dashboard growth_collect_usage user_id=' . (int)$user['id'] . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' ' . $e->getMessage());
        }
    } else {
        if (!schema_guard_usage_metrics_ready()) {
            static $usageMetricsLogged = false;
            if (!$usageMetricsLogged) {
                $usageMetricsLogged = true;
                error_log('SCHEMA_MISSING usage_metrics_daily user_id=' . (int)$user['id'] . ' uri=' . ($_SERVER['REQUEST_URI'] ?? ''));
            }
        }
    }
}
$requestedRange = $_GET['range'] ?? '7d';
$nocache        = isset($_GET['nocache']) && $_GET['nocache'] === '1';
$debugEnabled  = isset($_GET['debug']) && (($user['global_role'] ?? '') === 'owner');

// Rate limit: не более 30 запросов за 10 секунд (session-based)
if (!isset($_SESSION['owner_dashboard_requests'])) {
    $_SESSION['owner_dashboard_requests'] = [];
}
$now = time();
$_SESSION['owner_dashboard_requests'] = array_values(array_filter(
    $_SESSION['owner_dashboard_requests'],
    fn($t) => $t > $now - 10
));
$_SESSION['owner_dashboard_requests'][] = $now;
if (count($_SESSION['owner_dashboard_requests']) > 30) {
    $_SESSION['owner_dashboard_requests'] = array_slice($_SESSION['owner_dashboard_requests'], -30, 30);
    session_write_close();
    sleep(1);
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Too Many Requests';
    exit;
}
session_write_close();

// CSRF токен для POST-действий (alert_status, purge_cache)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$isProjectOwner = (($user['global_role'] ?? '') === 'project_owner');

// ===== Загружаем рестораны =====
// - если project_owner → показываем ВСЕ рестораны
// - если owner → показываем рестораны, где он owner в users_restaurants
$restaurantsDeletedSql = schema_guard_restaurants_deleted_sql('r');
if ($isProjectOwner) {
    $stmt = $pdo->query("
        SELECT r.*
        FROM restaurants r
        WHERE 1=1 {$restaurantsDeletedSql}
        ORDER BY r.id DESC
    ");
    $restaurants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT r.*
        FROM restaurants r
        JOIN users_restaurants ur ON ur.restaurant_id = r.id
        WHERE ur.user_id = :uid
          AND ur.restaurant_role = 'owner'
          {$restaurantsDeletedSql}
        ORDER BY r.id DESC
    ");
    $stmt->execute(['uid' => (int)$user['id']]);
    $restaurants = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== Статистика (период) =====
// ?range=today | 7d | 30d | all
[$periodStart, $periodEnd, $range] = stats_period_range($requestedRange);

$revenueMap = [];
$activeMap  = [];
$restIds    = [];
$chartData  = [];
$topItems   = [];
$topCategories = [];
$margin        = ['revenue' => 0.0, 'profit' => 0.0, 'margin_pct' => null];
$topShare      = ['top3_revenue' => 0.0, 'total_revenue' => 0.0, 'share_pct' => null];
$heatmap       = array_fill(0, 24, 0);
$conversion = [
    'total'               => 0,
    'paid'                => 0,
    'canceled'            => 0,
    'conversion_paid_pct' => null,
    'canceled_pct'        => null,
];
$summaryCurrent = ['revenue' => 0.0, 'orders' => 0, 'avg' => 0.0];
$summaryPrev    = ['revenue' => 0.0, 'orders' => 0, 'avg' => 0.0];
$prevStart      = null;
$prevEnd        = null;
$kpi            = [
    'revenue_delta' => 0.0,
    'revenue_pct'   => null,
    'orders_delta'  => 0,
    'orders_pct'    => null,
    'avg_delta'     => 0.0,
    'avg_pct'       => null,
];

// ===== Срез по ресторанам (all vs конкретный) =====
$allowedIds = array_map(fn($r) => (int)$r['id'], $restaurants);
$requestedRest = $_GET['rest_id'] ?? 'all';
$selectedRest = 'all';
$scopeIds = $allowedIds;

if ($requestedRest !== 'all') {
    $reqId = (int)$requestedRest;
    if ($reqId > 0 && in_array($reqId, $allowedIds, true)) {
        $scopeIds    = [$reqId];
        $selectedRest = $reqId;
    } else {
        // неверный rest_id: редирект на all с нормализованным range
        $backRange = urlencode($range);
        safe_redirect("/owner/dashboard.php?range={$backRange}&rest_id=all");
    }
}

// очистка просроченного stats-кэша (лениво)
stats_cache_cleanup_expired();

// Кэш для stats_*
$cacheDebug = [];
$statsBlockElapsed = null;

if (!empty($scopeIds)) {
    $tStatsStart = microtime(true);
    $restIds = $scopeIds;

    // Хэш/лейбл scope для лога алертов и кэша
    $scopeHash  = stats_scope_hash($restIds, $range, $periodStart, $periodEnd, $selectedRest);
    $scopeLabel = ($selectedRest === 'all') ? 'rest_id=all' : ('rest_id=' . (int)$selectedRest);

    $userId = (int)$user['id'];

    $remember = function (string $name, array $params, int $ttl, callable $cb) use ($userId, $scopeHash, &$cacheDebug, $nocache) {
        $exceptionOccurred = false;
        $runCb = function () use ($cb, $name, &$exceptionOccurred) {
            try {
                return $cb();
            } catch (Throwable $e) {
                $exceptionOccurred = true;
                error_log('STATS_CACHE_CALLBACK_ERROR name=' . $name . ' ' . $e->getMessage());
                return [];
            }
        };
        $shouldCache = function ($val, string $name) use (&$exceptionOccurred) {
            if ($exceptionOccurred) {
                return false;
            }
            if (!is_array($val)) {
                return false;
            }
            switch ($name) {
                case 'stats_revenue_summary':
                    return array_key_exists('revenue', $val) && array_key_exists('orders', $val) && array_key_exists('avg', $val);
                case 'stats_revenue_by_restaurants':
                    return true;
                case 'stats_conversion':
                    return array_key_exists('total', $val) && array_key_exists('paid', $val) && array_key_exists('canceled', $val);
                case 'stats_daily_revenue':
                    return true;
                case 'stats_top_items':
                case 'stats_top_categories':
                    foreach ($val as $row) {
                        if (!is_array($row) || !array_key_exists('name', $row) || !array_key_exists('revenue', $row) || !array_key_exists('qty', $row)) {
                            return false;
                        }
                    }
                    return true;
                case 'stats_margin':
                    return array_key_exists('revenue', $val) && array_key_exists('profit', $val) && array_key_exists('margin_pct', $val);
                case 'stats_top_share':
                    return array_key_exists('top3_revenue', $val) && array_key_exists('total_revenue', $val);
                case 'stats_hourly_heatmap':
                    if (count($val) !== 24) {
                        return false;
                    }
                    for ($h = 0; $h < 24; $h++) {
                        if (!array_key_exists($h, $val) && !isset($val[$h])) {
                            return false;
                        }
                    }
                    return true;
                case 'stats_alerts':
                    return true;
                case 'stats_revenue_summary_prev':
                    return array_key_exists('revenue', $val) && array_key_exists('orders', $val) && array_key_exists('avg', $val);
                default:
                    return true;
            }
        };

        if ($nocache) {
            $val = $runCb();
            $cacheDebug[] = [
                'name'           => $name,
                'hit'            => null,
                'key'            => null,
                'ttl'            => $ttl,
                'age_sec'        => null,
                'expires_in_sec' => null,
                'bypass'         => true,
            ];
            return $val;
        }

        $limitBytes = 0;
        $ml = trim((string)ini_get('memory_limit'));
        if ($ml === '' || $ml === '-1') {
            $limitBytes = 0;
        } elseif (preg_match('/^(\d+)\s*([KMG])?B?$/i', $ml, $m)) {
            $limitBytes = (int)$m[1];
            $u = isset($m[2]) ? strtoupper($m[2]) : '';
            if ($u === 'K') { $limitBytes *= 1024; }
            elseif ($u === 'M') { $limitBytes *= 1024 * 1024; }
            elseif ($u === 'G') { $limitBytes *= 1024 * 1024 * 1024; }
            elseif ($u === '') { $limitBytes = 0; }
        }
        if ($limitBytes >= 1024 * 1024 && memory_get_usage(true) > 0.7 * $limitBytes) {
            $val = $runCb();
            $cacheDebug[] = [
                'name'           => $name,
                'hit'            => null,
                'key'            => null,
                'ttl'            => $ttl,
                'age_sec'        => null,
                'expires_in_sec' => null,
                'bypass_memory'  => true,
            ];
            return $val;
        }

        $key = stats_cache_make_key($userId, $scopeHash, $name, $params);
        $hit = false;
        $meta = stats_cache_get($key);
        $ageSec = null;
        $expiresIn = null;
        // Placeholder/null payload не считать hit — пересчитываем
        if ($meta !== null && array_key_exists('payload', $meta) && $meta['payload'] !== null) {
            $val = $meta['payload'];
            $hit = true;
            $createdTs = strtotime($meta['created_at']);
            $expiresTs = strtotime($meta['expires_at']);
            $ageSec   = ($createdTs !== false) ? max(0, time() - $createdTs) : null;
            $expiresIn = ($expiresTs !== false) ? max(0, $expiresTs - time()) : null;
        }
        if (!$hit) {
            $locked = stats_cache_acquire_lock($key, 10);
            if (!$locked) {
                $metaRetry = stats_cache_wait_for_value($key, 600, 150);
                if ($metaRetry !== null && array_key_exists('payload', $metaRetry) && $metaRetry['payload'] !== null) {
                    $val = $metaRetry['payload'];
                    $hit = true;
                    $createdTs = strtotime($metaRetry['created_at']);
                    $expiresTs = strtotime($metaRetry['expires_at']);
                    $ageSec    = ($createdTs !== false) ? max(0, time() - $createdTs) : null;
                    $expiresIn = ($expiresTs !== false) ? max(0, $expiresTs - time()) : null;
                } else {
                    $t0 = microtime(true);
                    $val = $runCb();
                    $elapsed = microtime(true) - $t0;
                    if ($elapsed > 5.0) {
                        error_log(sprintf('SLOW_STATS name=%s scope_hash=%s elapsed_sec=%.2f', $name, $scopeHash, $elapsed));
                    } elseif ($shouldCache($val, $name)) {
                        stats_cache_set($key, $userId, $scopeHash, $ttl, $val);
                    } elseif (!$exceptionOccurred) {
                        error_log(sprintf('STATS_CACHE_INVALID user_id=%s scope_hash=%s name=%s', $userId, $scopeHash, $name));
                    }
                }
            } else {
                $t0 = microtime(true);
                $val = $runCb();
                $elapsed = microtime(true) - $t0;
                if ($elapsed > 5.0) {
                    error_log(sprintf('SLOW_STATS name=%s scope_hash=%s elapsed_sec=%.2f', $name, $scopeHash, $elapsed));
                } elseif ($shouldCache($val, $name)) {
                    stats_cache_set($key, $userId, $scopeHash, $ttl, $val);
                } elseif (!$exceptionOccurred) {
                    error_log(sprintf('STATS_CACHE_INVALID user_id=%s scope_hash=%s name=%s', $userId, $scopeHash, $name));
                }
            }
        }

        $cacheDebug[] = [
            'name' => $name,
            'hit'  => $hit,
            'key'  => $key,
            'ttl'  => $ttl,
            'age_sec' => $ageSec,
            'expires_in_sec' => $expiresIn,
        ];
        return $val;
    };

    $revenueMap = $remember(
        'stats_revenue_by_restaurants',
        [$restIds, $periodStart, $periodEnd],
        60,
        fn() => stats_revenue_by_restaurants($restIds, $periodStart, $periodEnd)
    );

    $activeMap = stats_active_orders_by_restaurants($restIds);

    $summaryCurrent = $remember(
        'stats_revenue_summary',
        [$restIds, $periodStart, $periodEnd],
        60,
        fn() => stats_revenue_summary($restIds, $periodStart, $periodEnd)
    );

    [$prevStart, $prevEnd] = stats_prev_period_range($range, $periodStart, $periodEnd);
    if ($prevStart !== null && $prevEnd !== null) {
        $summaryPrev = $remember(
            'stats_revenue_summary_prev',
            [$restIds, $prevStart, $prevEnd],
            60,
            fn() => stats_revenue_summary($restIds, $prevStart, $prevEnd)
        );
    }

    $revDelta = $summaryCurrent['revenue'] - $summaryPrev['revenue'];
    $ordDelta = $summaryCurrent['orders']  - $summaryPrev['orders'];
    $avgDelta = $summaryCurrent['avg']     - $summaryPrev['avg'];

    $kpi['revenue_delta'] = $revDelta;
    $kpi['orders_delta']  = $ordDelta;
    $kpi['avg_delta']     = $avgDelta;

    if ($summaryPrev['revenue'] > 0) {
        $kpi['revenue_pct'] = ($revDelta / $summaryPrev['revenue']) * 100.0;
    }
    if ($summaryPrev['orders'] > 0) {
        $kpi['orders_pct'] = ($ordDelta / $summaryPrev['orders']) * 100.0;
    }
    if ($summaryPrev['avg'] > 0) {
        $kpi['avg_pct'] = ($avgDelta / $summaryPrev['avg']) * 100.0;
    }

    $daysForChart = null;
    if ($range === '7d') {
        $daysForChart = 7;
    } elseif ($range === '30d') {
        $daysForChart = 30;
    }

    if ($daysForChart !== null) {
        $chartData = $remember(
            'stats_daily_revenue',
            [$restIds, $daysForChart],
            60,
            fn() => stats_daily_revenue($restIds, $daysForChart)
        );
    }

    $topItems = $remember(
        'stats_top_items',
        [$restIds, $periodStart, $periodEnd, 3],
        60,
        fn() => stats_top_items($restIds, $periodStart, $periodEnd, 3)
    );

    $conversion = $remember(
        'stats_conversion',
        [$restIds, $periodStart, $periodEnd],
        60,
        fn() => stats_conversion($restIds, $periodStart, $periodEnd)
    );

    $topCategories = $remember(
        'stats_top_categories',
        [$restIds, $periodStart, $periodEnd, 3],
        60,
        fn() => stats_top_categories($restIds, $periodStart, $periodEnd, 3)
    );

    $margin = $remember(
        'stats_margin',
        [$restIds, $periodStart, $periodEnd],
        60,
        fn() => stats_margin($restIds, $periodStart, $periodEnd)
    );

    $topShare = $remember(
        'stats_top_share',
        [$restIds, $periodStart, $periodEnd],
        60,
        fn() => stats_top_share($restIds, $periodStart, $periodEnd)
    );

    $heatmap = $remember(
        'stats_hourly_heatmap',
        [$restIds, $periodStart, $periodEnd],
        60,
        fn() => stats_hourly_heatmap($restIds, $periodStart, $periodEnd)
    );

    $alerts = $remember(
        'stats_alerts',
        [$restIds, $range, $periodStart, $periodEnd],
        30,
        fn() => stats_alerts($restIds, $range, $periodStart, $periodEnd)
    );
    $statsBlockElapsed = microtime(true) - $tStatsStart;
} else {
    // даже при пустом scopeIds нужно иметь корректный scopeHash для кэша/логов
    $scopeHash  = stats_scope_hash([], $range, $periodStart, $periodEnd, $selectedRest);
    $scopeLabel = ($selectedRest === 'all') ? 'rest_id=all' : ('rest_id=' . (int)$selectedRest);
    $alerts     = [];
}

// Обработка purge кэша только через POST (debug=1, CSRF в теле)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge_cache' && $debugEnabled) {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf'])
        && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if ($csrfOk) {
        $purged = stats_cache_purge_scope((int)$user['id'], $scopeHash);
        $_SESSION['stats_purge_count'] = $purged;
    }
    safe_redirect($_SERVER['REQUEST_URI'] ?? '/owner/dashboard.php');
}

// Обработка POST-действий по алертам (ack/snooze/reset)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'alert_status') {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf'])
        && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);

    if (!$csrfOk) {
        http_response_code(400);
        echo 'Invalid CSRF token';
        exit;
    }

    $alertKey = (string)($_POST['alert_key'] ?? '');
    $do       = (string)($_POST['do'] ?? '');

    if ($alertKey !== '' && $scopeHash !== '') {
        owner_alerts_set_status((int)$user['id'], $scopeHash, $alertKey, $do);
    }

    safe_redirect($_SERVER['REQUEST_URI'] ?? '/owner/dashboard.php');
}

// Growth: алерты по лимитам плана (80% / 100%) — объединяем со stats-алертами (только если таблицы и restaurants.deleted_at есть)
$growthAlerts = [];
if (schema_guard_growth_ready() && db_column_exists('restaurants', 'deleted_at')) {
    try {
        $growthAlerts = growth_soft_limit_alerts((int)$user['id']);
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR dashboard growth_soft_limit_alerts user_id=' . (int)$user['id'] . ' ' . $e->getMessage());
    }
}
$alerts = array_merge($alerts ?? [], $growthAlerts);

// Сохраняем текуще сгенерированные алерты в лог
if (!empty($alerts) && !empty($restIds)) {
    owner_alerts_upsert_from_generated(
        (int)$user['id'],
        $scopeHash,
        $scopeLabel,
        $range,
        $periodStart,
        $periodEnd,
        $alerts
    );
}

// Помечаем алерты и загружаем для UI только при непустом scope (не вызываем alerts_repo при пустом scopeIds)
$generatedKeys = array_map(
    fn($a) => (string)($a['key'] ?? ''),
    $alerts ?? []
);
$generatedKeys = array_values(array_filter($generatedKeys, fn($k) => $k !== ''));
if (!empty($scopeIds)) {
    owner_alerts_resolve_missing((int)$user['id'], $scopeHash, $generatedKeys);
}
$includeHidden = isset($_GET['show_hidden']) && $_GET['show_hidden'] === '1';
$alertsForUi   = !empty($scopeIds)
    ? owner_alerts_get_for_scope((int)$user['id'], $scopeHash, $includeHidden)
    : [];

$purgeCacheCount = null;
if (isset($_SESSION['stats_purge_count'])) {
    $purgeCacheCount = (int)$_SESSION['stats_purge_count'];
    unset($_SESSION['stats_purge_count']);
}

$debugData = null;
if ($debugEnabled) {
    require_once __DIR__ . '/../../app/stats_selftest.php';
    $debugData = [
        'now'                => date('c'),
        'range_raw'          => (string)$requestedRange,
        'range_normalized'   => (string)$range,
        'period_start'       => $periodStart,
        'period_end'         => $periodEnd,
        'prev_period_start'  => $prevStart ?? null,
        'prev_period_end'    => $prevEnd ?? null,
        'restaurants_count'  => count($restaurants),
        'allowed_rest_ids'   => $allowedIds,
        'scope_rest_ids'     => $scopeIds,
        'rest_id_raw'        => $requestedRest,
        'rest_ids'           => $restIds,
        'scope_hash'         => $scopeHash,
        'include_hidden'     => $includeHidden,
        'nocache'            => $nocache,
        'purge_cache_count'  => $purgeCacheCount,
        'revenue_map'        => $revenueMap,
        'chart_data'         => $chartData,
        'summary_current'    => $summaryCurrent,
        'summary_prev'       => $summaryPrev,
        'kpi'                => $kpi,
        'top_items'          => $topItems,
        'conversion'         => $conversion,
        'top_categories'     => $topCategories,
        'margin'             => $margin,
        'top_share'          => $topShare,
        'heatmap'            => $heatmap,
        'alerts_generated'   => $alerts ?? [],
        'alerts_db_count'    => count($alertsForUi),
        'alerts_db_keys'     => array_map(fn($a) => $a['key'] ?? null, $alertsForUi),
        'cache'              => $cacheDebug,
        'cache_hit_rate'      => (function () use ($cacheDebug) {
            $entries = array_filter($cacheDebug, fn($e) => array_key_exists('hit', $e) && $e['hit'] !== null && !isset($e['bypass']) && !isset($e['bypass_memory']));
            $total = count($entries);
            if ($total === 0) {
                return ['hits' => 0, 'total' => 0, 'rate' => null];
            }
            $hits = count(array_filter($entries, fn($e) => $e['hit'] === true));
            return ['hits' => $hits, 'total' => $total, 'rate' => round($hits / $total, 3)];
        })(),
        'selfcheck_errors'    => [],
        'selftest'            => function_exists('stats_selftest_run') ? stats_selftest_run() : [],
        'selfcheck'           => [],
        'timers'              => ['stats_block_sec' => $statsBlockElapsed !== null ? round($statsBlockElapsed, 3) : null],
        'schema_preflight'    => schema_guard_preflight_report(),
    ];
    $selfcheck = [];
    if (!is_array($topItems)) {
        error_log('DEBUG_SELFCHECK top_items is not array');
        $selfcheck['top_items'] = 'fail';
    } else {
        $ok = true;
        foreach ($topItems as $i => $row) {
            if (!is_array($row) || !array_key_exists('name', $row) || !array_key_exists('revenue', $row) || !array_key_exists('qty', $row)) {
                error_log('DEBUG_SELFCHECK top_items[' . $i . '] bad structure');
                $ok = false;
                break;
            }
        }
        $selfcheck['top_items'] = $ok ? 'ok' : 'fail';
    }
    if (!is_array($topCategories)) {
        error_log('DEBUG_SELFCHECK top_categories is not array');
        $selfcheck['top_categories'] = 'fail';
    } else {
        $ok = true;
        foreach ($topCategories as $i => $row) {
            if (!is_array($row) || !array_key_exists('name', $row) || !array_key_exists('revenue', $row) || !array_key_exists('qty', $row)) {
                error_log('DEBUG_SELFCHECK top_categories[' . $i . '] bad structure');
                $ok = false;
                break;
            }
        }
        $selfcheck['top_categories'] = $ok ? 'ok' : 'fail';
    }
    if (!is_array($summaryCurrent) || !array_key_exists('revenue', $summaryCurrent) || !array_key_exists('orders', $summaryCurrent) || !array_key_exists('avg', $summaryCurrent)) {
        error_log('DEBUG_SELFCHECK summary_current bad shape');
        $selfcheck['summary_current'] = 'fail';
    } else {
        $selfcheck['summary_current'] = 'ok';
    }
    if (!is_array($conversion) || !array_key_exists('total', $conversion) || !array_key_exists('paid', $conversion) || !array_key_exists('canceled', $conversion)) {
        error_log('DEBUG_SELFCHECK conversion bad shape');
        $selfcheck['conversion'] = 'fail';
    } else {
        $selfcheck['conversion'] = 'ok';
    }
    if (!is_array($heatmap) || count($heatmap) !== 24) {
        error_log('DEBUG_SELFCHECK heatmap bad shape (expected 24 keys)');
        $selfcheck['heatmap'] = 'fail';
    } else {
        $ok = true;
        foreach ($heatmap as $h => $v) {
            if (!is_int($h) || $h < 0 || $h > 23 || !is_numeric($v)) {
                error_log('DEBUG_SELFCHECK heatmap[' . $h . '] invalid');
                $ok = false;
                break;
            }
        }
        $selfcheck['heatmap'] = $ok ? 'ok' : 'fail';
    }
    if (!is_array($margin) || !array_key_exists('revenue', $margin) || !array_key_exists('profit', $margin) || !array_key_exists('margin_pct', $margin)) {
        error_log('DEBUG_SELFCHECK margin bad shape');
        $selfcheck['margin'] = 'fail';
    } else {
        $selfcheck['margin'] = 'ok';
    }
    $debugData['selfcheck'] = $selfcheck;
    $debugData['selfcheck_errors'] = array_keys(array_filter($selfcheck, fn($v) => $v === 'fail'));
}

// Базовый ресторан и URL для действий из алертов
$actionRestaurantId      = null;
$actionRestaurantBaseUrl = null;

// helper: находим ресторан по id
$restaurantsById = [];
foreach ($restaurants as $r) {
    $restaurantsById[(int)$r['id']] = $r;
}

if ($selectedRest !== 'all') {
    $rid = (int)$selectedRest;
    if (isset($restaurantsById[$rid])) {
        $row = $restaurantsById[$rid];
        $sub = trim((string)($row['subdomain'] ?? ''));
        if ($sub !== '') {
            $actionRestaurantId      = $rid;
            $actionRestaurantBaseUrl = $protocol . '://' . $sub . '.' . $mainDomain;
        }
    }
} else {
    // rest_id=all: пробуем взять action_rest_id или единственный ресторан
    $actionRequestedId = isset($_GET['action_rest_id']) ? (int)$_GET['action_rest_id'] : null;
    $candidateId       = null;

    if ($actionRequestedId !== null && in_array($actionRequestedId, $allowedIds, true)) {
        $candidateId = $actionRequestedId;
    } elseif (count($allowedIds) === 1) {
        $candidateId = $allowedIds[0];
    }

    if ($candidateId !== null && isset($restaurantsById[$candidateId])) {
        $row = $restaurantsById[$candidateId];
        $sub = trim((string)($row['subdomain'] ?? ''));
        if ($sub !== '') {
            $actionRestaurantId      = $candidateId;
            $actionRestaurantBaseUrl = $protocol . '://' . $sub . '.' . $mainDomain;
        }
    }
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Мои рестораны — QR-Rest</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex">

<aside class="w-64 bg-slate-950/80 border-r border-slate-800 p-4 hidden md:block">
    <h1 class="text-xl font-semibold mb-2">Личный кабинет</h1>
    <?php
    if (schema_guard_billing_ready()) {
        $billingSub = billing_ensure_default_subscription((int)$user['id']);
        $billingPlanName = $billingSub['plan_name'] ?? 'Бесплатный';
        $billingPeriodEnd = !empty($billingSub['current_period_end']) ? date('d.m.Y', strtotime($billingSub['current_period_end'])) : '';
    } else {
        $billingPlanName = null;
        $billingPeriodEnd = null;
    }
    ?>
    <div class="mb-4 text-xs text-slate-400 flex flex-wrap gap-3">
        <?php if (schema_guard_billing_ready()): ?>
            <a href="/owner/billing.php" class="hover:text-slate-200">Тариф: <?= e($billingPlanName) ?><?= $billingPeriodEnd ? ' • до ' . e($billingPeriodEnd) : '' ?></a>
            <a href="/owner/growth.php" class="hover:text-slate-200">Рост и рефералы</a>
        <?php else: ?>
            <span class="text-amber-400/90">Billing unavailable (migration pending)</span>
            <?php if (schema_guard_growth_ready()): ?>
                <a href="/owner/growth.php" class="hover:text-slate-200">Рост и рефералы</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    $currentRestParam = ($selectedRest === 'all') ? 'all' : (string)$selectedRest;
    $rangeLink = function(string $r) use ($currentRestParam): string {
        return '/owner/dashboard.php?range=' . urlencode($r) . '&rest_id=' . urlencode($currentRestParam);
    };
    $reportLink = function(string $fmt) use ($range, $currentRestParam): string {
        return '/owner/report.php?range=' . urlencode($range) . '&rest_id=' . urlencode($currentRestParam) . '&format=' . urlencode($fmt);
    };
    ?>
    <div class="mb-4 text-sm flex flex-wrap gap-2">
        <a class="px-3 py-1 rounded-lg <?= ($range==='today'?'bg-slate-700':'bg-slate-800/60') ?>" href="<?= e($rangeLink('today')) ?>">Сегодня</a>
        <a class="px-3 py-1 rounded-lg <?= ($range==='7d'?'bg-slate-700':'bg-slate-800/60') ?>" href="<?= e($rangeLink('7d')) ?>">7 дней</a>
        <a class="px-3 py-1 rounded-lg <?= ($range==='30d'?'bg-slate-700':'bg-slate-800/60') ?>" href="<?= e($rangeLink('30d')) ?>">30 дней</a>
        <a class="px-3 py-1 rounded-lg <?= ($range==='all'?'bg-slate-700':'bg-slate-800/60') ?>" href="<?= e($rangeLink('all')) ?>">Всё время</a>
    </div>

    <div class="mb-2 text-xs text-slate-400">Срез по ресторанам</div>
    <nav class="space-y-1 text-sm mb-4">
        <?php
        $allSelected = ($selectedRest === 'all');
        $restSelLink = function(string $restId, string $range) {
            return '/owner/dashboard.php?range=' . urlencode($range) . '&rest_id=' . urlencode($restId);
        };
        ?>
        <a href="<?= e($restSelLink('all', $range)) ?>"
           class="block px-3 py-2 rounded-xl <?= $allSelected ? 'bg-emerald-600/20 text-emerald-200 border border-emerald-500/50' : 'bg-slate-950/40 text-slate-200 border border-slate-800 hover:bg-slate-800/60' ?>">
            Все рестораны
        </a>
        <?php foreach ($restaurants as $r): ?>
            <?php $rid = (int)$r['id']; ?>
            <a href="<?= e($restSelLink((string)$rid, $range)) ?>"
               class="block px-3 py-2 rounded-xl text-xs <?php
                   if ($selectedRest !== 'all' && (int)$selectedRest === $rid) {
                       echo 'bg-emerald-600/20 text-emerald-200 border border-emerald-500/50';
                   } else {
                       echo 'bg-slate-950/40 text-slate-200 border border-slate-800 hover:bg-slate-800/60';
                   }
               ?>">
                <?= e($r['name'] ?? ('Ресторан #' . $rid)) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="mt-4 space-y-1 text-xs">
        <div class="text-slate-400 mb-1">Экспорт отчёта</div>
        <div class="flex flex-col gap-1">
            <a href="<?= e($reportLink('csv')) ?>"
               class="inline-flex items-center justify-center px-3 py-1.5 rounded-lg bg-slate-900/70 border border-slate-700 text-slate-100 hover:bg-slate-800/80">
                Экспорт CSV
            </a>
            <a href="<?= e($reportLink('pdf')) ?>"
               class="inline-flex items-center justify-center px-3 py-1.5 rounded-lg bg-slate-900/70 border border-slate-700 text-slate-100 hover:bg-slate-800/80">
                Экспорт PDF
            </a>
        </div>
    </div>
    <nav class="space-y-2 text-sm">
        <a href="/owner/dashboard.php"
           class="block px-3 py-2 rounded-xl bg-slate-800/70">
            Мои рестораны
        </a>

        <?php if ($isProjectOwner): ?>
            <a href="/project-admin/"
               class="block px-3 py-2 rounded-xl hover:bg-slate-800/60">
                Панель проекта
            </a>
        <?php endif; ?>

        <a href="/logout.php"
           class="block px-3 py-2 rounded-xl hover:bg-slate-800/60 text-red-300">
            Выйти
        </a>
    </nav>
</aside>

<main class="flex-1 p-4">
    <div class="max-w-6xl mx-auto space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold mb-1">Мои рестораны</h2>
                <div class="text-xs text-slate-500">
                    Пользователь: <?= e($user['name'] ?? $user['email']) ?>
                </div>
            </div>
            <div class="text-xs text-slate-500">
                Сегодня: <?= date('d.m.Y') ?>
            </div>
        </header>

        <?php if ($debugData): ?>
            <div class="bg-slate-900/80 border border-amber-500/50 rounded-3xl p-3 text-[11px] text-amber-100 font-mono whitespace-pre overflow-x-auto">
                <?= htmlspecialchars(json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php
        $totalRevenue = (float)$summaryCurrent['revenue'];
        $totalOrders  = (int)$summaryCurrent['orders'];
        $avgCheck     = (float)$summaryCurrent['avg'];

        $revPct = $kpi['revenue_pct'];
        $ordPct = $kpi['orders_pct'];
        $avgPct = $kpi['avg_pct'];

        $revClass = $revPct === null ? 'text-slate-400'
            : ($revPct > 0 ? 'text-emerald-400' : 'text-rose-400');
        $ordClass = $ordPct === null ? 'text-slate-400'
            : ($ordPct > 0 ? 'text-emerald-400' : 'text-rose-400');
        $avgClass = $avgPct === null ? 'text-slate-400'
            : ($avgPct > 0 ? 'text-emerald-400' : 'text-rose-400');

        $formatPct = function (?float $v): string {
            if ($v === null) return '—';
            $r = round($v, 1);
            if ($r > 0) return '+' . $r . '%';
            return $r . '%';
        };

        // ссылки экспорта отчёта по текущим параметрам (нормализованные range/rest_id)
        $exportParamsBase = ['range' => $range, 'rest_id' => ($selectedRest === 'all') ? 'all' : (string)(int)$selectedRest];

        $exportCsvUrl = '/owner/report.php?' . http_build_query(array_merge($exportParamsBase, ['format' => 'csv']));
        $exportPdfUrl = '/owner/report.php?' . http_build_query(array_merge($exportParamsBase, ['format' => 'pdf']));
        ?>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 grid gap-4 md:grid-cols-3">
            <div>
                <div class="text-xs text-slate-400 mb-1">Выручка за период</div>
                <div class="text-2xl font-semibold text-emerald-400">
                    <?= format_money($totalRevenue) ?>
                </div>
                <div class="text-xs mt-1 <?= $revClass ?>">
                    Рост к прошлому периоду: <?= $formatPct($revPct) ?>
                </div>
            </div>
            <div>
                <div class="text-xs text-slate-400 mb-1">Оплаченные заказы</div>
                <div class="text-2xl font-semibold text-slate-100">
                    <?= (int)$totalOrders ?>
                </div>
                <div class="text-xs mt-1 <?= $ordClass ?>">
                    Рост к прошлому периоду: <?= $formatPct($ordPct) ?>
                </div>
            </div>
            <div>
                <div class="text-xs text-slate-400 mb-1">Средний чек</div>
                <div class="text-2xl font-semibold text-emerald-300">
                    <?= format_money($avgCheck) ?>
                </div>
                <div class="text-xs mt-1 <?= $avgClass ?>">
                    Рост к прошлому периоду: <?= $formatPct($avgPct) ?>
                </div>
            </div>
        </section>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-xs text-slate-400 mb-1">⚠️ Уведомления</div>
                    <div class="text-sm text-slate-300">Аналитика по выбранному периоду и ресторанам</div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-1">
                        <a href="<?= e($exportCsvUrl) ?>"
                           class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-900/70 border border-slate-700 text-[11px] text-slate-100 hover:bg-slate-800/80">
                            Экспорт CSV
                        </a>
                        <a href="<?= e($exportPdfUrl) ?>"
                           target="_blank"
                           class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-900/70 border border-slate-700 text-[11px] text-slate-100 hover:bg-slate-800/80">
                            Экспорт PDF
                        </a>
                    </div>
                    <?php
                    $showHiddenParams = $_GET;
                    if ($includeHidden) {
                        unset($showHiddenParams['show_hidden']);
                    } else {
                        $showHiddenParams['show_hidden'] = '1';
                    }
                    $showHiddenUrl = '/owner/dashboard.php?' . http_build_query($showHiddenParams);
                    ?>
                    <a href="<?= e($showHiddenUrl) ?>"
                       class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-900/70 border border-slate-700 text-[11px] text-slate-100 hover:bg-slate-800/80">
                        <?= $includeHidden ? 'Скрыть закрытые' : 'Показать скрытые' ?>
                    </a>
                    <?php if ($debugEnabled): ?>
                        <form method="post" action="<?= e($_SERVER['REQUEST_URI'] ?? '/owner/dashboard.php') ?>"
                              class="inline">
                            <input type="hidden" name="action" value="purge_cache" />
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>" />
                            <button type="submit"
                                    class="inline-flex items-center px-2.5 py-1 rounded-full bg-red-500/10 border border-red-500/60 text-[11px] text-red-200 hover:bg-red-500/20">
                                Очистить кэш
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($selectedRest === 'all' && count($allowedIds) > 1): ?>
                        <?php
                        // небольшой селектор ресторана для действий
                        $actionSelectorOptions = [];
                        foreach ($restaurants as $r) {
                            $rid = (int)$r['id'];
                            if (!in_array($rid, $allowedIds, true)) {
                                continue;
                            }
                            $sub = trim((string)($r['subdomain'] ?? ''));
                            if ($sub === '') {
                                continue;
                            }
                            $actionSelectorOptions[] = $r;
                        }
                        ?>
                        <?php if (!empty($actionSelectorOptions)): ?>
                            <form method="get" class="flex items-center gap-2 text-[11px] text-slate-400">
                                <span>Открыть в:</span>
                                <?php
                                // сохраняем базовые параметры
                                ?>
                                <input type="hidden" name="range" value="<?= e($range) ?>">
                                <input type="hidden" name="rest_id" value="all">
                                <?php if ($debugEnabled): ?>
                                    <input type="hidden" name="debug" value="1">
                                <?php endif; ?>
                                <select name="action_rest_id"
                                        class="bg-slate-950/80 border border-slate-700 rounded-lg px-2 py-1 text-[11px] text-slate-100"
                                        onchange="this.form.submit()">
                                    <?php foreach ($actionSelectorOptions as $opt): ?>
                                        <?php $rid = (int)$opt['id']; ?>
                                        <option value="<?= $rid ?>" <?= ($actionRestaurantId === $rid ? 'selected' : '') ?>>
                                            <?= e($opt['name'] ?? ('Ресторан #' . $rid)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($alertsForUi ?? [])): ?>
                <div class="text-sm text-slate-500">
                    Нет критичных уведомлений за период.
                </div>
            <?php else: ?>
                <div class="grid gap-2 md:grid-cols-2">
                    <?php foreach ($alertsForUi as $alert): ?>
                        <?php
                        $sev = $alert['severity'] ?? 'info';
                        if ($sev === 'critical') {
                            $cls = 'bg-rose-500/10 border-rose-500/30 text-rose-200';
                        } elseif ($sev === 'warning') {
                            $cls = 'bg-amber-500/10 border-amber-500/30 text-amber-200';
                        } else {
                            $cls = 'bg-slate-800/40 border-slate-700 text-slate-200';
                        }

                        $alertActions = $alert['actions'] ?? [];
                        $status       = $alert['status'] ?? 'open';
                        $snoozeUntil  = $alert['snooze_until'] ?? null;
                        ?>
                        <div class="rounded-2xl border px-3 py-2 <?= $cls ?>">
                            <div class="text-xs font-semibold mb-0.5">
                                <?= e($alert['title'] ?? 'Алерт') ?>
                            </div>
                            <div class="text-[11px] leading-snug">
                                <?= e($alert['message'] ?? '') ?>
                            </div>
                            <?php if (!empty($alert['meta'])): ?>
                                <div class="mt-1 text-[10px] opacity-80">
                                    <?php if (isset($alert['meta']['threshold_pct']) || isset($alert['meta']['threshold_min'])): ?>
                                        Порог:
                                        <?php if (isset($alert['meta']['threshold_pct'])): ?>
                                            <?= e((string)$alert['meta']['threshold_pct']) ?>
                                        <?php endif; ?>
                                        <?php if (isset($alert['meta']['threshold_min'])): ?>
                                            , заказов: &ge;<?= (int)$alert['meta']['threshold_min'] ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= e(json_encode($alert['meta'], JSON_UNESCAPED_UNICODE)) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($status !== 'open'): ?>
                                <div class="mt-1 text-[10px] text-slate-300 flex items-center gap-2">
                                    <?php if ($status === 'ack'): ?>
                                        <span class="inline-flex px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/50 text-emerald-200">
                                            Принято
                                        </span>
                                    <?php elseif ($status === 'snoozed'): ?>
                                        <span class="inline-flex px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/50 text-amber-200">
                                            Отложено
                                            <?php if ($snoozeUntil): ?>
                                                до <?= e(date('d.m.Y H:i', strtotime($snoozeUntil))) ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($alertActions)): ?>
                                <div class="mt-2 flex flex-wrap gap-1">
                                    <?php if ($actionRestaurantBaseUrl): ?>
                                        <?php foreach ($alertActions as $act): ?>
                                            <?php
                                            $rel = (string)($act['url'] ?? '');
                                            $label = (string)($act['label'] ?? 'Перейти');
                                            // допускаем только относительные пути от корня
                                            if ($rel === '' || $rel[0] !== '/') {
                                                continue;
                                            }
                                            $full = $actionRestaurantBaseUrl . $rel;
                                            ?>
                                            <a href="<?= e($full) ?>" target="_blank"
                                               class="inline-flex items-center px-2 py-1 rounded-full text-[10px] bg-slate-900/60 border border-current/40 hover:bg-slate-900/90">
                                                <?= e($label) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <button type="button"
                                                class="inline-flex items-center px-2 py-1 rounded-full text-[10px] bg-slate-800/40 border border-slate-600 text-slate-400 cursor-not-allowed"
                                                disabled>
                                            Выберите ресторан слева или через селектор выше
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="mt-2 flex flex-wrap gap-1">
                                <form method="post" class="inline-block">
                                    <input type="hidden" name="action" value="alert_status">
                                    <input type="hidden" name="alert_key" value="<?= e($alert['key']) ?>">
                                    <input type="hidden" name="do" value="ack">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                                    <button type="submit"
                                            class="px-2 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/50 text-[10px] text-emerald-100 hover:bg-emerald-500/25">
                                        ✅ Принять
                                    </button>
                                </form>
                                <form method="post" class="inline-block">
                                    <input type="hidden" name="action" value="alert_status">
                                    <input type="hidden" name="alert_key" value="<?= e($alert['key']) ?>">
                                    <input type="hidden" name="do" value="snooze_24h">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                                    <button type="submit"
                                            class="px-2 py-0.5 rounded-full bg-amber-500/15 border border-amber-500/50 text-[10px] text-amber-100 hover:bg-amber-500/25">
                                        ⏰ 24ч
                                    </button>
                                </form>
                                <form method="post" class="inline-block">
                                    <input type="hidden" name="action" value="alert_status">
                                    <input type="hidden" name="alert_key" value="<?= e($alert['key']) ?>">
                                    <input type="hidden" name="do" value="snooze_7d">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                                    <button type="submit"
                                            class="px-2 py-0.5 rounded-full bg-amber-500/15 border border-amber-500/50 text-[10px] text-amber-100 hover:bg-amber-500/25">
                                        ⏰ 7д
                                    </button>
                                </form>
                                <form method="post" class="inline-block">
                                    <input type="hidden" name="action" value="alert_status">
                                    <input type="hidden" name="alert_key" value="<?= e($alert['key']) ?>">
                                    <input type="hidden" name="do" value="reset">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
                                    <button type="submit"
                                            class="px-2 py-0.5 rounded-full bg-slate-800/60 border border-slate-600 text-[10px] text-slate-200 hover:bg-slate-800/80">
                                        ↩️ Снять
                                    </button>
                                </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!empty($chartData)): ?>
            <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
                <div class="flex items-center justify-between mb-2">
                    <div>
                        <div class="text-xs text-slate-400 mb-1">Динамика выручки по дням</div>
                        <div class="text-sm text-slate-300">
                            За последние <?= ($range === '30d' ? '30' : '7') ?> дней
                        </div>
                    </div>
                </div>
                <div class="h-48">
                    <canvas id="revenueChart" class="w-full h-full"></canvas>
                </div>
            </section>
        <?php endif; ?>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 grid gap-4 md:grid-cols-[2fr,1.3fr]">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <div>
                        <div class="text-xs text-slate-400 mb-1">Топ блюд по выручке</div>
                        <div class="text-sm text-slate-300">За выбранный период</div>
                    </div>
                </div>
                <?php if (empty($topItems)): ?>
                    <div class="text-sm text-slate-500">Нет данных</div>
                <?php else: ?>
                    <div class="space-y-2 text-sm">
                        <?php foreach ($topItems as $item): ?>
                            <div class="flex items-center justify-between gap-3 rounded-2xl bg-slate-950/60 border border-slate-800 px-3 py-2">
                                <div class="min-w-0">
                                    <div class="font-medium text-slate-50 truncate">
                                        <?= e($item['name']) ?>
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Кол-во: <?= (int)$item['qty'] ?>
                                    </div>
                                </div>
                                <div class="text-right text-sm font-semibold text-emerald-300 whitespace-nowrap">
                                    <?= format_money((float)$item['revenue']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <div class="text-xs text-slate-400 mb-1">Конверсия заказов</div>
                <?php
                $convTotal = (int)$conversion['total'];
                $convPaid  = (int)$conversion['paid'];
                $convCanc  = (int)$conversion['canceled'];
                $convPaidPct = $conversion['conversion_paid_pct'];
                $convCancPct = $conversion['canceled_pct'];

                $fmtPctShort = function (?float $v): string {
                    if ($v === null) return '—';
                    return round($v, 1) . '%';
                };

                $paidClass = $convPaidPct === null ? 'text-slate-200'
                    : ($convPaidPct >= 50 ? 'text-emerald-300' : 'text-amber-300');
                $cancClass = $convCancPct === null ? 'text-slate-200'
                    : ($convCancPct > 0 ? 'text-rose-300' : 'text-slate-200');
                ?>

                <?php if ($convTotal === 0): ?>
                    <div class="mt-2 text-sm text-slate-500">Нет данных по заказам за период.</div>
                <?php else: ?>
                    <div class="mt-2 space-y-2 text-sm">
                        <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 border border-slate-800 px-3 py-2">
                            <div class="text-slate-300">Всего заказов</div>
                            <div class="font-semibold text-slate-50"><?= $convTotal ?></div>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 border border-slate-800 px-3 py-2">
                            <div>
                                <div class="text-slate-300">Оплачено</div>
                            </div>
                            <div class="text-right">
                                <div class="font-semibold text-slate-50"><?= $convPaid ?></div>
                                <div class="text-xs <?= $paidClass ?>">
                                    <?= $fmtPctShort($convPaidPct) ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-slate-950/60 border border-slate-800 px-3 py-2">
                            <div>
                                <div class="text-slate-300">Отменено</div>
                            </div>
                            <div class="text-right">
                                <div class="font-semibold text-slate-50"><?= $convCanc ?></div>
                                <div class="text-xs <?= $cancClass ?>">
                                    <?= $fmtPctShort($convCancPct) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 space-y-6">
            <div class="grid gap-4 md:grid-cols-[2fr,1.3fr]">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <div class="text-xs text-slate-400 mb-1">Топ категорий по выручке</div>
                            <div class="text-sm text-slate-300">По всем ресторанам за период</div>
                        </div>
                    </div>
                    <?php if (empty($topCategories)): ?>
                        <div class="text-sm text-slate-500">Нет данных</div>
                    <?php else: ?>
                        <div class="space-y-2 text-sm">
                            <?php foreach ($topCategories as $cat): ?>
                                <div class="flex items-center justify-between gap-3 rounded-2xl bg-slate-950/60 border border-slate-800 px-3 py-2">
                                    <div class="min-w-0">
                                        <div class="font-medium text-slate-50 truncate">
                                            <?= e($cat['name']) ?>
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            Кол-во: <?= (int)$cat['qty'] ?>
                                        </div>
                                    </div>
                                    <div class="text-right text-sm font-semibold text-emerald-300 whitespace-nowrap">
                                        <?= format_money((float)$cat['revenue']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <?php
                    $mRev   = (float)$margin['revenue'];
                    $mProf  = (float)$margin['profit'];
                    $mPct   = $margin['margin_pct'];
                    $mClass = $mPct === null ? 'text-slate-400'
                        : ($mPct >= 50 ? 'text-emerald-300' : ($mPct >= 20 ? 'text-amber-300' : 'text-rose-300'));
                    $fmtMargin = function (?float $v): string {
                        if ($v === null) return '—';
                        return round($v, 1) . '%';
                    };
                    $sharePct = $topShare['share_pct'] ?? null;
                    $shareStr = $sharePct === null ? '—' : round($sharePct, 1) . '%';
                    ?>
                    <div class="mb-4">
                        <div class="text-xs text-slate-400 mb-1">Маржинальность</div>
                        <?php if ($mRev <= 0): ?>
                            <div class="text-sm text-slate-500">Нет данных по оплаченной выручке за период.</div>
                        <?php else: ?>
                            <div class="space-y-1 text-sm">
                                <div class="flex items-center justify-between">
                                    <div class="text-slate-300">Выручка</div>
                                    <div class="font-semibold text-slate-50">
                                        <?= format_money($mRev) ?>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div class="text-slate-300">Прибыль</div>
                                    <div class="font-semibold text-emerald-300">
                                        <?= format_money($mProf) ?>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div class="text-slate-300">Маржа</div>
                                    <div class="font-semibold <?= $mClass ?>">
                                        <?= $fmtMargin($mPct) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400 mb-1">Доля топ-3 блюд</div>
                        <?php if (($topShare['total_revenue'] ?? 0) <= 0): ?>
                            <div class="text-sm text-slate-500">Нет данных по выручке за период.</div>
                        <?php else: ?>
                            <div class="space-y-1 text-sm">
                                <div class="flex items-center justify-between">
                                    <div class="text-slate-300">Топ-3 блюда</div>
                                    <div class="font-semibold text-slate-50">
                                        <?= format_money((float)$topShare['top3_revenue']) ?>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <div class="text-slate-300">Доля в выручке</div>
                                    <div class="font-semibold text-emerald-300">
                                        <?= $shareStr ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div>
                <div class="text-xs text-slate-400 mb-2">Почасовая загрузка (оплаченные заказы)</div>
                <?php
                $maxHeat = max($heatmap) ?: 0;
                ?>
                <?php if ($maxHeat === 0): ?>
                    <div class="text-sm text-slate-500">Нет данных по заказам за период.</div>
                <?php else: ?>
                    <div class="grid grid-cols-12 gap-1 text-[10px]">
                        <?php for ($row = 0; $row < 2; $row++): ?>
                            <?php for ($col = 0; $col < 12; $col++): ?>
                                <?php
                                $h = $row * 12 + $col;
                                $v = $heatmap[$h] ?? 0;
                                $ratio = $maxHeat > 0 ? ($v / $maxHeat) : 0;
                                if ($ratio <= 0) {
                                    $bg = 'bg-slate-900 border border-slate-800';
                                } elseif ($ratio < 0.25) {
                                    $bg = 'bg-emerald-500/10 border border-emerald-500/20';
                                } elseif ($ratio < 0.5) {
                                    $bg = 'bg-emerald-500/20 border border-emerald-500/30';
                                } elseif ($ratio < 0.75) {
                                    $bg = 'bg-emerald-500/40 border border-emerald-500/40';
                                } else {
                                    $bg = 'bg-emerald-500/70 border border-emerald-400/80';
                                }
                                ?>
                                <div class="aspect-square rounded-lg flex flex-col items-center justify-center <?= $bg ?>">
                                    <div><?= $h ?></div>
                                    <div class="text-[9px] text-slate-200"><?= $v ?></div>
                                </div>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php
        // Рестораны для отображения карточек (с учётом выбранного среза)
        $visibleRestaurants = $restaurants;
        if ($selectedRest !== 'all') {
            $visibleRestaurants = array_values(array_filter(
                $restaurants,
                fn($r) => (int)$r['id'] === (int)$selectedRest
            ));
        }
        ?>

        <?php if (empty($visibleRestaurants)): ?>
            <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 text-sm text-slate-300">
                У вас пока нет ни одного ресторана, закреплённого за вашим аккаунтом.
                <?php if ($isProjectOwner): ?>
                    <br>Создайте ресторан через <a href="/project-admin/" class="text-emerald-300 hover:text-emerald-200">панель проекта</a>.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <section class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($visibleRestaurants as $r): ?>
                    <?php
                    $rid   = (int)$r['id'];
                    $stats = $revenueMap[$rid] ?? ['revenue' => 0, 'orders' => 0];
                    $acts  = $activeMap[$rid] ?? ['new' => 0, 'accepted' => 0, 'cooking' => 0, 'ready' => 0];
                    $ordersCount = (int)($stats['orders'] ?? 0);

                    $subdomain = $r['subdomain'] ?? '';
                    $restUrl   = $protocol . '://' . $subdomain . '.' . $mainDomain;
                    $adminUrl  = $restUrl . '/restaurant/dashboard.php';

                    $statusLabel = (($r['status'] ?? 'active') === 'blocked') ? 'Заблокирован' : 'Активен';
                    $statusColor = (($r['status'] ?? 'active') === 'blocked')
                        ? 'bg-red-500/10 border-red-500/60 text-red-100'
                        : 'bg-emerald-500/10 border-emerald-500/60 text-emerald-100';
                    ?>
                    <article class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 flex flex-col gap-3">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-50">
                                    <?= e($r['name'] ?? ('Ресторан #' . $rid)) ?>
                                </h3>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    Поддомен:
                                    <a href="<?= e($restUrl) ?>" target="_blank"
                                       class="text-emerald-300 hover:text-emerald-200">
                                        <?= e($subdomain) ?>.<?= e($mainDomain) ?>
                                    </a>
                                </div>
                                <div class="text-[11px] text-slate-500">
                                    ID ресторана: <?= $rid ?>
                                </div>
                            </div>
                            <div>
                                <span class="inline-flex items-center px-2 py-1 rounded-full border text-[11px] <?= $statusColor ?>">
                                    <?= e($statusLabel) ?>
                                </span>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-3 text-[11px] text-slate-300">
                            <div>
                                <div class="text-slate-400 mb-0.5">Выручка за период</div>
                                <div class="text-sm font-semibold text-emerald-400">
                                    <?= format_money((float)$stats['revenue']) ?>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    <?php if ($ordersCount > 0): ?>
                                        Оплачено заказов: <?= $ordersCount ?>
                                    <?php else: ?>
                                        Нет оплаченных заказов за период
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div class="text-slate-400 mb-0.5">Заказы (оплаченные)</div>
                                <div class="text-sm font-semibold text-slate-100">
                                    <?= (int)$stats['orders'] ?>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 text-[11px] text-slate-300">
                            <div>Новые: <span class="font-semibold text-sky-300"><?= (int)$acts['new'] ?></span></div>
                            <div>Приняты: <span class="font-semibold text-sky-300"><?= (int)$acts['accepted'] ?></span></div>
                            <div>Готовятся: <span class="font-semibold text-amber-300"><?= (int)$acts['cooking'] ?></span></div>
                            <div>Готовы: <span class="font-semibold text-emerald-300"><?= (int)$acts['ready'] ?></span></div>
                        </div>

                        <div class="mt-2 flex flex-wrap gap-2">
                            <a href="<?= e($adminUrl) ?>"
                               class="inline-flex items-center justify-center flex-1 px-3 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-semibold">
                                Перейти в панель ресторана
                            </a>
                            <a href="<?= e($restUrl) ?>/qr.php?table_id=1"
                               target="_blank"
                               class="inline-flex items-center justify-center px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-100">
                                Открыть QR-меню (стол 1)
                            </a>
                            <?php /* dashboard read-only */ ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php if (!empty($chartData)): ?>
<script>
(function () {
    const el = document.getElementById('revenueChart');
    if (!el || !el.getContext) return;

    const labels = <?= json_encode(array_keys($chartData), JSON_UNESCAPED_UNICODE) ?>;
    const data   = <?= json_encode(array_values($chartData), JSON_UNESCAPED_UNICODE) ?>;

    const ctx = el.getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Выручка',
                data: data,
                borderColor: 'rgb(16, 185, 129)',
                backgroundColor: 'rgba(16, 185, 129, 0.15)',
                borderWidth: 2,
                tension: 0.25,
                pointRadius: 3,
                pointBackgroundColor: 'rgb(16, 185, 129)',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
            },
            scales: {
                x: {
                    ticks: {
                        color: '#94a3b8',
                    },
                    grid: {
                        color: 'rgba(148, 163, 184, 0.15)',
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#94a3b8',
                    },
                    grid: {
                        color: 'rgba(148, 163, 184, 0.12)',
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

</body>
</html>