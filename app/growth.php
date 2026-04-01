<?php
/**
 * Growth Features (Variant 6): referral, coupons, usage metrics, soft limits, UTM.
 * Multi-tenant by user_id; all writes via prepared statements.
 */

require_once __DIR__ . '/db.php';

/** Base62 (0-9a-zA-Z) from random bytes, min length 10. */
function growth_base62_code(int $minLen = 10): string
{
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $bytes = random_bytes(max(8, (int)ceil($minLen * 0.6)));
    $n = 0;
    for ($i = 0; $i < strlen($bytes); $i++) {
        $n = ($n << 8) | ord($bytes[$i]);
    }
    $out = '';
    $base = strlen($alphabet);
    while ($n > 0) {
        $out = $alphabet[$n % $base] . $out;
        $n = (int)floor($n / $base);
    }
    return str_pad($out, $minLen, $alphabet[random_int(0, $base - 1)], STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------
// REFERRAL
// ---------------------------------------------------------------------------

/**
 * Гарантирует наличие referral_code у владельца. Код минимум 10 символов, base62.
 * @return array{id:int,code:string,clicks_count:int,conversions_count:int}
 */
function growth_ensure_referral_code(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, code, clicks_count, conversions_count FROM referral_codes WHERE user_id = :uid AND is_active = 1 LIMIT 1");
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return [
            'id'                => (int)$row['id'],
            'code'              => (string)$row['code'],
            'clicks_count'      => (int)$row['clicks_count'],
            'conversions_count' => (int)$row['conversions_count'],
        ];
    }
    $code = growth_base62_code(10);
    $maxAttempts = 10;
    for ($i = 0; $i < $maxAttempts; $i++) {
        try {
            $ins = $pdo->prepare("INSERT INTO referral_codes (user_id, code, reward_type, is_active) VALUES (:uid, :code, 'none', 1)");
            $ins->execute(['uid' => $userId, 'code' => $code]);
            $id = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT id, code, clicks_count, conversions_count FROM referral_codes WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'id'                => (int)$row['id'],
                'code'              => (string)$row['code'],
                'clicks_count'      => (int)$row['clicks_count'],
                'conversions_count' => (int)$row['conversions_count'],
            ];
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $code = growth_base62_code(10);
                continue;
            }
            throw $e;
        }
    }
    error_log('GROWTH_ENSURE_REFERRAL_CODE failed to generate unique code user_id=' . $userId);
    return ['id' => 0, 'code' => '', 'clicks_count' => 0, 'conversions_count' => 0];
}

/**
 * Записывает клик по реферальной ссылке. Дедуп: UNIQUE(referral_code_id, ip_hash, day_bucket).
 * day_bucket считается в UTC (gmdate('Y-m-d')) для единообразия.
 * @return bool true если клик записан (первый за день с этого IP для этого кода)
 */
function growth_record_click(string $code, string $ipHash, string $userAgentHash = ''): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM referral_codes WHERE code = :code AND is_active = 1 LIMIT 1");
    $stmt->execute(['code' => $code]);
    $ref = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ref) {
        return false;
    }
    $refId = (int)$ref['id'];
    $dayBucket = gmdate('Y-m-d');
    try {
        $ins = $pdo->prepare("INSERT INTO referral_clicks (referral_code_id, ip_hash, user_agent_hash, day_bucket) VALUES (:rid, :ip, :ua, :day)");
        $ins->execute(['rid' => $refId, 'ip' => $ipHash, 'ua' => $userAgentHash, 'day' => $dayBucket]);
        $pdo->prepare("UPDATE referral_codes SET clicks_count = clicks_count + 1 WHERE id = :id")->execute(['id' => $refId]);
        return true;
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            return false;
        }
        throw $e;
    }
}

/**
 * Находит referral_code по коду. Для сохранения в сессию и последующей конверсии.
 * @return array{id:int,user_id:int}|null
 */
function growth_get_referral_by_code(string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, user_id FROM referral_codes WHERE code = :code AND is_active = 1 LIMIT 1");
    $stmt->execute(['code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return ['id' => (int)$row['id'], 'user_id' => (int)$row['user_id']];
}

/**
 * Создаёт конверсию только если: new_user_id != referrer, был клик до регистрации, один conversion на new_user_id.
 * Reward выдаётся только при rewarded=0 внутри транзакции.
 */
function growth_create_conversion(int $referralCodeId, int $newUserId): void
{
    $pdo = db();
    try {
        $stmt = $pdo->prepare("SELECT user_id, reward_type, reward_value FROM referral_codes WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $referralCodeId]);
        $ref = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ref) {
            return;
        }
        $referrerId = (int)$ref['user_id'];
        if ($referrerId === $newUserId) {
            if (function_exists('abuse_signal')) {
                require_once __DIR__ . '/audit.php';
                abuse_signal($newUserId, 'referral_self_signup', 10, ['referral_code_id' => $referralCodeId]);
            }
            return;
        }
        $stmt = $pdo->prepare("SELECT created_at FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $newUserId]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            return;
        }
        $userCreated = $userRow['created_at'];
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
        $chk = $pdo->prepare("SELECT id FROM referral_clicks WHERE referral_code_id = :rid AND created_at <= :uc AND created_at >= :cutoff LIMIT 1");
        $chk->execute(['rid' => $referralCodeId, 'uc' => $userCreated, 'cutoff' => $cutoff]);
        if (!$chk->fetchColumn()) {
            if (strtotime($userCreated) < strtotime($cutoff)) {
                error_log('GROWTH_CONVERSION_SKIP_CLICK_TOO_OLD ref_code_id=' . $referralCodeId . ' new_user_id=' . $newUserId);
            }
            return;
        }
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO referral_conversions (referral_code_id, new_user_id, rewarded) VALUES (:rid, :uid, 0)");
        $ins->execute(['rid' => $referralCodeId, 'uid' => $newUserId]);
        $pdo->prepare("UPDATE referral_codes SET conversions_count = conversions_count + 1 WHERE id = :id")->execute(['id' => $referralCodeId]);
        $rewardType = $ref['reward_type'] ?? 'none';
        $rewardValue = isset($ref['reward_value']) ? (float)$ref['reward_value'] : 0.0;
        if ($rewardType !== 'none' && $rewardValue > 0) {
            $conv = $pdo->prepare("SELECT id, rewarded FROM referral_conversions WHERE new_user_id = :uid AND referral_code_id = :rid FOR UPDATE");
            $conv->execute(['uid' => $newUserId, 'rid' => $referralCodeId]);
            $convRow = $conv->fetch(PDO::FETCH_ASSOC);
            if ($convRow && (int)$convRow['rewarded'] === 0) {
                $sub = $pdo->prepare("SELECT id, meta_json FROM subscriptions WHERE user_id = :uid ORDER BY current_period_end DESC LIMIT 1");
                $sub->execute(['uid' => $referrerId]);
                $subRow = $sub->fetch(PDO::FETCH_ASSOC);
                if ($subRow) {
                    $meta = $subRow['meta_json'];
                    $decoded = is_string($meta) ? json_decode($meta, true) : $meta;
                    $decoded = is_array($decoded) ? $decoded : [];
                    $decoded['referral_credit'] = ($decoded['referral_credit'] ?? 0) + $rewardValue;
                    $metaJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                    $pdo->prepare("UPDATE subscriptions SET meta_json = :meta WHERE id = :id")->execute(['meta' => $metaJson, 'id' => $subRow['id']]);
                }
                $pdo->prepare("UPDATE referral_conversions SET rewarded = 1 WHERE id = :id")->execute(['id' => $convRow['id']]);
                if (function_exists('audit_log')) {
                    require_once __DIR__ . '/audit.php';
                    audit_log('referral_reward', 'referral_conversion', (string)$convRow['id']);
                }
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() == 23000) {
            return;
        }
        error_log('GROWTH_CREATE_CONVERSION_ERROR ref_code_id=' . $referralCodeId . ' new_user_id=' . $newUserId . ' ' . $e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('GROWTH_CREATE_CONVERSION_ERROR ref_code_id=' . $referralCodeId . ' new_user_id=' . $newUserId . ' ' . $e->getMessage());
    }
}

/**
 * Статистика реферала для владельца.
 */
function growth_get_referral_stats(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, code, clicks_count, conversions_count, reward_type, reward_value FROM referral_codes WHERE user_id = :uid AND is_active = 1 LIMIT 1");
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['code' => '', 'clicks_count' => 0, 'conversions_count' => 0, 'reward_type' => 'none', 'reward_value' => null];
    }
    return [
        'code'              => (string)$row['code'],
        'clicks_count'      => (int)$row['clicks_count'],
        'conversions_count' => (int)$row['conversions_count'],
        'reward_type'       => (string)$row['reward_type'],
        'reward_value'       => $row['reward_value'] !== null ? (float)$row['reward_value'] : null,
    ];
}

// ---------------------------------------------------------------------------
// COUPONS
// ---------------------------------------------------------------------------

/**
 * Валидирует купон для пользователя. Race-safe: внутри транзакции, coupon FOR UPDATE.
 * Проверки: usage_limit, max_per_user, first_time_only (нет предыдущих invoices), min_plan_price <= planPrice.
 * @param float|null $planPrice цена плана для проверки min_plan_price
 */
function growth_validate_coupon(string $code, int $userId, PDO $pdo, ?float $planPrice = null): array
{
    $code = trim($code);
    if ($code === '') {
        return ['ok' => false, 'message' => 'Код не указан.'];
    }
    $stmt = $pdo->prepare("SELECT id, type, value, valid_from, valid_until, usage_limit, used_count, is_active, max_per_user, min_plan_price, first_time_only FROM coupons WHERE code = :code FOR UPDATE");
    $stmt->execute(['code' => $code]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        return ['ok' => false, 'message' => 'Купон не найден.'];
    }
    if ((int)$c['is_active'] !== 1) {
        return ['ok' => false, 'message' => 'Купон недействителен.'];
    }
    $now = gmdate('Y-m-d H:i:s');
    if ($c['valid_from'] !== null && $c['valid_from'] > $now) {
        return ['ok' => false, 'message' => 'Купон ещё не действует.'];
    }
    if ($c['valid_until'] !== null && $c['valid_until'] < $now) {
        return ['ok' => false, 'message' => 'Срок действия купона истёк.'];
    }
    $limit = (int)$c['usage_limit'];
    $used = (int)$c['used_count'];
    if ($limit > 0 && $used >= $limit) {
        return ['ok' => false, 'message' => 'Лимит использований купона исчерпан.'];
    }
    $maxPerUser = isset($c['max_per_user']) ? (int)$c['max_per_user'] : 1;
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = :cid AND user_id = :uid");
    $cnt->execute(['cid' => $c['id'], 'uid' => $userId]);
    if ((int)$cnt->fetchColumn() >= $maxPerUser) {
        return ['ok' => false, 'message' => 'Вы уже использовали этот купон.'];
    }
    $firstTimeOnly = isset($c['first_time_only']) ? (int)$c['first_time_only'] : 0;
    if ($firstTimeOnly) {
        $invCnt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = :uid");
        $invCnt->execute(['uid' => $userId]);
        if ((int)$invCnt->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Купон только для первых покупок.'];
        }
    }
    $minPlanPrice = isset($c['min_plan_price']) && $c['min_plan_price'] !== null ? (float)$c['min_plan_price'] : null;
    if ($minPlanPrice !== null && $planPrice !== null && $planPrice < $minPlanPrice) {
        return ['ok' => false, 'message' => 'Купон не действует для выбранного тарифа.'];
    }
    $type = $c['type'] === 'fixed' ? 'fixed' : 'percent';
    $value = (float)$c['value'];
    return ['ok' => true, 'coupon_id' => (int)$c['id'], 'type' => $type, 'value' => $value];
}

/**
 * Применяет скидку к сумме. percent: 0-100, fixed: вычитаем из amount (не ниже 0).
 */
function growth_apply_discount(float $amount, string $type, float $value): float
{
    if ($type === 'percent') {
        $value = min(100.0, max(0.0, $value));
        return max(0.0, $amount * (1 - $value / 100.0));
    }
    return max(0.0, $amount - $value);
}

/**
 * Регистрирует использование купона (идемпотентно по UNIQUE coupon_id+user_id).
 * Вызывать внутри той же транзакции, что и growth_validate_coupon + invoice.
 */
function growth_apply_coupon_usage(int $couponId, int $userId, PDO $pdo): void
{
    $ins = $pdo->prepare("INSERT IGNORE INTO coupon_usages (coupon_id, user_id) VALUES (:cid, :uid)");
    $ins->execute(['cid' => $couponId, 'uid' => $userId]);
    if ($ins->rowCount() > 0) {
        $pdo->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = :id")->execute(['id' => $couponId]);
    }
}

// ---------------------------------------------------------------------------
// USAGE METRICS
// ---------------------------------------------------------------------------

/**
 * Собирает и записывает/обновляет usage_metrics_daily за сегодня (UTC) для пользователя.
 * Вызывать не по крону, а при заходе на dashboard 1 раз в день (если записи нет).
 * Если таблица/колонки отсутствуют — выходит без ошибки (graceful degradation).
 */
function growth_collect_usage_for_user(int $userId): void
{
    if (function_exists('db_table_exists') && !db_table_exists('usage_metrics_daily')) {
        return;
    }
    $restDeletedSql = (function_exists('db_column_exists') && db_column_exists('restaurants', 'deleted_at')) ? ' AND (deleted_at IS NULL)' : '';
    try {
        $pdo = db();
        $date = gmdate('Y-m-d');
        $stmt = $pdo->prepare("SELECT id FROM usage_metrics_daily WHERE user_id = :uid AND date = :date LIMIT 1");
        $stmt->execute(['uid' => $userId, 'date' => $date]);
        if ($stmt->fetchColumn()) {
            return;
        }
        $restaurants = 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM restaurants WHERE owner_user_id = :uid" . $restDeletedSql);
        $stmt->execute(['uid' => $userId]);
        $restaurants = (int)$stmt->fetchColumn();
    $todayStart = $date . ' 00:00:00';
    $todayEnd   = $date . ' 23:59:59';
    $orders = 0;
    $revenue = 0.0;
    $ids = [];
    if ($restaurants > 0) {
        $rids = $pdo->prepare("SELECT id FROM restaurants WHERE owner_user_id = :uid" . $restDeletedSql);
        $rids->execute(['uid' => $userId]);
        $ids = array_column($rids->fetchAll(PDO::FETCH_ASSOC), 'id');
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $ordersWhere = '';
            if (function_exists('db_column_exists')) {
                if (db_column_exists('orders', 'payment_status')) {
                    $ordersWhere = " AND payment_status = 'paid'";
                } elseif (db_column_exists('orders', 'order_status')) {
                    $ordersWhere = " AND order_status IN ('paid','done','completed')";
                }
            }
            $amountCol = (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount')) ? 'total_amount' : 'total_price';
            $sql = "SELECT COUNT(*), COALESCE(SUM({$amountCol}), 0) FROM orders WHERE restaurant_id IN ($placeholders){$ordersWhere} AND created_at >= ? AND created_at <= ?";
            $params = array_merge($ids, [$todayStart, $todayEnd]);
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $orders = (int)$row[0];
                $revenue = (float)$row[1];
            } catch (Throwable $e) {
                error_log('GROWTH_COLLECT_USAGE_ORDERS_ERROR user_id=' . $userId . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' ' . $e->getMessage());
                $orders = 0;
                $revenue = 0.0;
            }
        }
    }
    $staff = 0;
    $menuItems = 0;
    if (!empty($ids)) {
        $ph = implode(',', array_map('intval', $ids));
        $stmt = $pdo->query("SELECT COUNT(*) FROM users_restaurants WHERE restaurant_id IN ($ph)");
        $staff = (int)$stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM menu_items WHERE restaurant_id IN ($ph) AND available = 1");
        $menuItems = (int)$stmt->fetchColumn();
    }
    try {
        $ins = $pdo->prepare("
            INSERT INTO usage_metrics_daily (user_id, date, restaurants_count, orders_count, revenue, staff_count, menu_items_count)
            VALUES (:uid, :date, :rest, :orders, :rev, :staff, :menu)
            ON DUPLICATE KEY UPDATE
                orders_count = VALUES(orders_count),
                revenue = VALUES(revenue),
                staff_count = VALUES(staff_count),
                menu_items_count = VALUES(menu_items_count),
                restaurants_count = VALUES(restaurants_count),
                updated_at = CURRENT_TIMESTAMP
        ");
        $ins->execute([
            'uid' => $userId, 'date' => $date,
            'rest' => $restaurants, 'orders' => $orders, 'rev' => $revenue,
            'staff' => $staff, 'menu' => $menuItems,
        ]);
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR growth_collect_usage file=' . __FILE__ . ' line=' . $e->getLine() . ' user_id=' . $userId . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' message=' . $e->getMessage());
    }
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR growth_collect_usage file=' . __FILE__ . ' line=' . $e->getLine() . ' user_id=' . $userId . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' message=' . $e->getMessage());
    }
}

/**
 * Данные для графика usage за последние N дней (по дням UTC).
 * Если таблица отсутствует или запрос падает — возвращает [].
 */
function growth_get_usage_for_chart(int $userId, int $days = 30): array
{
    if (function_exists('db_table_exists') && !db_table_exists('usage_metrics_daily')) {
        return [];
    }
    $days = max(7, min(90, $days));
    $since = gmdate('Y-m-d', strtotime("-{$days} days"));
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT date, restaurants_count, orders_count, revenue, staff_count, menu_items_count
            FROM usage_metrics_daily
            WHERE user_id = :uid AND date >= :since
            ORDER BY date ASC
        ");
        $stmt->execute(['uid' => $userId, 'since' => $since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR growth_get_usage_for_chart file=' . __FILE__ . ' line=' . $e->getLine() . ' user_id=' . $userId . ' message=' . $e->getMessage());
        return [];
    }
}

// ---------------------------------------------------------------------------
// SOFT LIMIT ALERTS (upsell)
// ---------------------------------------------------------------------------

/**
 * Алерты по лимитам плана (80% — предупреждение, 100% — CTA на billing).
 * Формат как stats_alerts: key, severity, title, message, meta, actions.
 * При отсутствии таблиц/колонок или ошибке — возвращает [] (dashboard не падает).
 */
function growth_soft_limit_alerts(int $userId): array
{
    try {
        if (!function_exists('billing_ensure_default_subscription')) {
            require_once __DIR__ . '/billing.php';
        }
        $sub = billing_ensure_default_subscription($userId);
        $limits = $sub['limits_json'] ?? [];
        if (!is_array($limits)) {
            return [];
        }
        $restMax = isset($limits['restaurants_max']) ? (int)$limits['restaurants_max'] : 0;
        $staffMax = isset($limits['staff_max']) ? (int)$limits['staff_max'] : 0;
        $menuMax = isset($limits['menu_items_max']) ? (int)$limits['menu_items_max'] : 0;
        $restDeletedSql = (function_exists('db_column_exists') && db_column_exists('restaurants', 'deleted_at')) ? ' AND (deleted_at IS NULL)' : '';
        $pdo = db();
        $restaurants = 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM restaurants WHERE owner_user_id = :uid" . $restDeletedSql);
        $stmt->execute(['uid' => $userId]);
        $restaurants = (int)$stmt->fetchColumn();
        $staff = 0;
        $menuItems = 0;
        $rids = $pdo->prepare("SELECT id FROM restaurants WHERE owner_user_id = :uid" . $restDeletedSql);
        $rids->execute(['uid' => $userId]);
        $ids = array_column($rids->fetchAll(PDO::FETCH_ASSOC), 'id');
    if (!empty($ids)) {
        $ph = implode(',', array_map('intval', $ids));
        $stmt = $pdo->query("SELECT COUNT(*) FROM users_restaurants WHERE restaurant_id IN ($ph)");
        $staff = (int)$stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM menu_items WHERE restaurant_id IN ($ph) AND available = 1");
        $menuItems = (int)$stmt->fetchColumn();
    }
    $alerts = [];
    $billingUrl = '/owner/billing.php';
    if ($restMax > 0) {
        $pct = $restMax > 0 ? ($restaurants / $restMax * 100) : 0;
        if ($pct >= 100) {
            $alerts[] = [
                'key'      => 'growth_limit_restaurants_full',
                'severity' => 'critical',
                'title'    => 'Лимит ресторанов достигнут',
                'message'  => "Вы используете {$restaurants} из {$restMax} ресторанов. Обновите план, чтобы добавить ещё.",
                'meta'     => ['current' => $restaurants, 'limit' => $restMax, 'type' => 'restaurants'],
                'actions'  => [['label' => 'Обновить план', 'url' => $billingUrl]],
            ];
        } elseif ($pct >= 80) {
            $alerts[] = [
                'key'      => 'growth_limit_restaurants_80',
                'severity' => 'warning',
                'title'    => 'Приближение к лимиту ресторанов',
                'message'  => "Вы используете {$restaurants} из {$restMax} ресторанов. Обновите план при необходимости.",
                'meta'     => ['current' => $restaurants, 'limit' => $restMax, 'type' => 'restaurants'],
                'actions'  => [['label' => 'Тарифы', 'url' => $billingUrl]],
            ];
        }
    }
    if ($staffMax > 0) {
        $pct = $staffMax > 0 ? ($staff / $staffMax * 100) : 0;
        if ($pct >= 100) {
            $alerts[] = [
                'key'      => 'growth_limit_staff_full',
                'severity' => 'critical',
                'title'    => 'Лимит сотрудников достигнут',
                'message'  => "У вас {$staff} из {$staffMax} сотрудников. Обновите план.",
                'meta'     => ['current' => $staff, 'limit' => $staffMax, 'type' => 'staff'],
                'actions'  => [['label' => 'Обновить план', 'url' => $billingUrl]],
            ];
        } elseif ($pct >= 80) {
            $alerts[] = [
                'key'      => 'growth_limit_staff_80',
                'severity' => 'warning',
                'title'    => 'Приближение к лимиту сотрудников',
                'message'  => "У вас {$staff} из {$staffMax} сотрудников.",
                'meta'     => ['current' => $staff, 'limit' => $staffMax, 'type' => 'staff'],
                'actions'  => [['label' => 'Тарифы', 'url' => $billingUrl]],
            ];
        }
    }
    if ($menuMax > 0) {
        $pct = $menuMax > 0 ? ($menuItems / $menuMax * 100) : 0;
        if ($pct >= 100) {
            $alerts[] = [
                'key'      => 'growth_limit_menu_full',
                'severity' => 'critical',
                'title'    => 'Лимит позиций меню достигнут',
                'message'  => "У вас {$menuItems} из {$menuMax} позиций. Обновите план.",
                'meta'     => ['current' => $menuItems, 'limit' => $menuMax, 'type' => 'menu_items'],
                'actions'  => [['label' => 'Обновить план', 'url' => $billingUrl]],
            ];
        } elseif ($pct >= 80) {
            $alerts[] = [
                'key'      => 'growth_limit_menu_80',
                'severity' => 'warning',
                'title'    => 'Приближение к лимиту позиций меню',
                'message'  => "У вас {$menuItems} из {$menuMax} позиций.",
                'meta'     => ['current' => $menuItems, 'limit' => $menuMax, 'type' => 'menu_items'],
                'actions'  => [['label' => 'Тарифы', 'url' => $billingUrl]],
            ];
        }
    }
        return $alerts;
    } catch (Throwable $e) {
        error_log('STABILITY_ERROR growth_soft_limit_alerts file=' . __FILE__ . ' line=' . $e->getLine() . ' user_id=' . $userId . ' message=' . $e->getMessage());
        return [];
    }
}

// ---------------------------------------------------------------------------
// UTM
// ---------------------------------------------------------------------------

/**
 * Записывает UTM-визит по ресторану. Вызывать с restaurant_public при наличии utm_* в GET.
 */
function growth_utm_record(int $restaurantId, array $utm): void
{
    $pdo = db();
    $source   = isset($utm['utm_source']) ? trim((string)$utm['utm_source']) : null;
    $medium   = isset($utm['utm_medium']) ? trim((string)$utm['utm_medium']) : null;
    $campaign = isset($utm['utm_campaign']) ? trim((string)$utm['utm_campaign']) : null;
    $content  = isset($utm['utm_content']) ? trim((string)$utm['utm_content']) : null;
    $term     = isset($utm['utm_term']) ? trim((string)$utm['utm_term']) : null;
    if ($source === '' && $medium === '' && $campaign === '') {
        return;
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO utm_visits (restaurant_id, utm_source, utm_medium, utm_campaign, utm_content, utm_term)
            VALUES (:rid, :s, :m, :c, :cnt, :t)
        ");
        $stmt->execute([
            'rid' => $restaurantId, 's' => $source ?: null, 'm' => $medium ?: null,
            'c' => $campaign ?: null, 'cnt' => $content ?: null, 't' => $term ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('GROWTH_UTM_RECORD_ERROR rest_id=' . $restaurantId . ' ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// UPSELL SUGGESTIONS (простые эвристики)
// ---------------------------------------------------------------------------

/**
 * Простые подсказки для growth dashboard: нет заказов N дней, средний чек ниже и т.д.
 */
function growth_upsell_suggestions(int $userId): array
{
    $suggestions = [];
    $rows = growth_get_usage_for_chart($userId, 30);
    $last7 = array_slice($rows, -7);
    $ordersLast7 = array_sum(array_column($last7, 'orders_count'));
    if (count($last7) >= 3 && $ordersLast7 === 0) {
        $suggestions[] = [
            'type'    => 'no_orders',
            'title'   => 'Нет заказов 3 дня подряд',
            'message' => 'За последние дни не было оплаченных заказов. Проверьте меню и продвижение.',
        ];
    }
    $withRevenue = array_filter($rows, fn($r) => (float)($r['revenue'] ?? 0) > 0);
    $avgChecks = [];
    foreach ($withRevenue as $r) {
        $o = (int)($r['orders_count'] ?? 0);
        if ($o > 0) {
            $avgChecks[] = (float)$r['revenue'] / $o;
        }
    }
    if (count($avgChecks) >= 5) {
        $userAvg = array_sum($avgChecks) / count($avgChecks);
        if ($userAvg > 0 && $userAvg < 500) {
            $suggestions[] = [
                'type'    => 'low_avg_check',
                'title'   => 'Средний чек ниже типичного',
                'message' => 'Попробуйте добавить комбо или рекомендации в меню.',
            ];
        }
    }
    return $suggestions;
}
