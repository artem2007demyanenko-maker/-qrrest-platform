<?php
/**
 * Feedback -> CRM/Growth bridge.
 * Draft suggestions only. No auto-send.
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}
if (file_exists(__DIR__ . '/growth_engine_arch.php')) {
    require_once __DIR__ . '/growth_engine_arch.php';
}
if (file_exists(__DIR__ . '/upsell_engine.php')) {
    require_once __DIR__ . '/upsell_engine.php';
}
if (file_exists(__DIR__ . '/loyalty_return_mode.php')) {
    require_once __DIR__ . '/loyalty_return_mode.php';
}
if (file_exists(__DIR__ . '/flow_id.php')) {
    require_once __DIR__ . '/flow_id.php';
}

if (!function_exists('feedback_crm_bridge_select_guest_phone_sql')) {
    function feedback_crm_bridge_select_guest_phone_sql(): string
    {
        $parts = [];
        if (function_exists('db_column_exists') && db_column_exists('orders', 'crm_phone')) {
            $parts[] = "NULLIF(o.crm_phone, '')";
        }
        if (function_exists('db_column_exists') && db_column_exists('orders', 'customer_phone')) {
            $parts[] = "NULLIF(o.customer_phone, '')";
        }
        if (function_exists('db_column_exists') && db_column_exists('orders', 'loyalty_phone')) {
            $parts[] = "NULLIF(o.loyalty_phone, '')";
        }
        if ($parts === []) {
            return "NULL AS guest_contact";
        }
        return "COALESCE(" . implode(', ', $parts) . ") AS guest_contact";
    }
}

if (!function_exists('feedback_upsell_bridge_get_order_base_item_ids')) {
    /**
     * Detect base item ids from a finished order.
     * Tenant safety: order_id is assumed to belong to $restaurantId (validated by caller join).
     *
     * @return int[]
     */
    function feedback_upsell_bridge_get_order_base_item_ids(PDO $pdo, int $restaurantId, int $orderId): array
    {
        $restaurantId = (int)$restaurantId;
        $orderId = (int)$orderId;
        if ($restaurantId <= 0 || $orderId <= 0) {
            return [];
        }

        // Schema guards: support both menu_item_id and item_name fallback.
        $hasMenuItemId = function_exists('db_column_exists') ? db_column_exists('order_items', 'menu_item_id') : true;
        if (!function_exists('db_column_exists') && file_exists(__DIR__ . '/schema_guard.php')) {
            require_once __DIR__ . '/schema_guard.php';
            $hasMenuItemId = function_exists('db_column_exists') ? db_column_exists('order_items', 'menu_item_id') : true;
        }
        $hasItemName = function_exists('db_column_exists') ? db_column_exists('order_items', 'item_name') : false;
        if (!function_exists('db_column_exists') && file_exists(__DIR__ . '/schema_guard.php')) {
            require_once __DIR__ . '/schema_guard.php';
            $hasItemName = function_exists('db_column_exists') ? db_column_exists('order_items', 'item_name') : false;
        }

        $ids = [];

        try {
            if ($hasMenuItemId) {
                $oiStmt = $pdo->prepare("
                    SELECT DISTINCT menu_item_id
                    FROM order_items
                    WHERE order_id = ?
                      AND menu_item_id IS NOT NULL
                      AND menu_item_id > 0
                ");
                $oiStmt->execute([$orderId]);
                $ids = array_map('intval', (array)$oiStmt->fetchAll(PDO::FETCH_COLUMN, 0));
            }

            if ($ids === [] && $hasItemName) {
                $oiStmt = $pdo->prepare("
                    SELECT DISTINCT item_name
                    FROM order_items
                    WHERE order_id = ?
                      AND TRIM(COALESCE(item_name, '')) <> ''
                    LIMIT 60
                ");
                $oiStmt->execute([$orderId]);
                $names = (array)$oiStmt->fetchAll(PDO::FETCH_COLUMN, 0);
                $names = array_values(array_unique(array_map(static fn($x) => trim((string)$x), $names)));
                $names = array_values(array_filter($names, static fn($x) => $x !== ''));

                foreach ($names as $itemName) {
                    $mStmt = $pdo->prepare("
                        SELECT id
                        FROM menu_items
                        WHERE restaurant_id = ?
                          AND name = ?
                          AND available = 1
                        LIMIT 1
                    ");
                    $mStmt->execute([$restaurantId, $itemName]);
                    $mid = (int)$mStmt->fetchColumn();
                    if ($mid > 0) {
                        $ids[] = $mid;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('feedback_upsell_bridge_get_order_base_item_ids rid=' . $restaurantId . ' order_id=' . $orderId . ' reason=' . $e->getMessage());
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($x) => $x > 0)));
        return $ids;
    }
}

if (!function_exists('feedback_upsell_bridge_crm_message_ru')) {
    function feedback_upsell_bridge_crm_message_ru(
        string $type,
        string $itemName,
        int $returnWindowDays,
        ?string $incentiveText = null,
        ?string $expirationHint = null
    ): string
    {
        $itemName = trim($itemName);
        if ($itemName === '') {
            $itemName = 'ваше любимое блюдо';
        }

        $days = max(1, min(14, (int)$returnWindowDays));
        $urgency = 'Только ' . $days . ' дн.';
        if ($expirationHint !== null && trim($expirationHint) !== '') {
            $urgency = trim($expirationHint);
        }
        $inc = ($incentiveText !== null && trim($incentiveText) !== '') ? (' ' . trim($incentiveText)) : '';

        if ($type === 'feedback_recovery_upsell') {
            // apology + compensation + urgency
            return "Извините, что визит не оправдал ожиданий. Хотим исправить впечатление: в следующий раз попробуйте {$itemName}. {$urgency}.{$inc}";
        }
        if ($type === 'feedback_neutral_upsell') {
            // recommendation + social proof + urgency
            return "Рекомендуем попробовать {$itemName} — часто берут вместе. {$urgency}.";
        }
        // appreciation + soft upsell + urgency
        return "Спасибо за высокую оценку! Будем рады видеть вас снова — попробуйте {$itemName} при следующем визите. {$urgency}.{$inc}";
    }
}

if (!function_exists('feedback_upsell_bridge_default_return_window_days')) {
    function feedback_upsell_bridge_default_return_window_days(string $type): int
    {
        // Conversion-focused windows: faster for recovery, slightly longer for happy guests.
        if ($type === 'feedback_recovery_upsell') return 3;
        if ($type === 'feedback_neutral_upsell') return 5;
        return 7;
    }
}

if (!function_exists('feedback_upsell_bridge_build_incentive_text')) {
    /**
     * Incentive text is a hint only (manual approval; no auto-credit).
     *
     * @return array{incentive_text:?string,recommended_bonus_points:?int}
     */
    function feedback_upsell_bridge_build_incentive_text(PDO $pdo, int $restaurantId, string $type, int $orderId, int $guestId, ?int $rating): array
    {
        $restaurantId = (int)$restaurantId;
        $orderId = (int)$orderId;
        $guestId = (int)$guestId;
        $rating = $rating !== null ? (int)$rating : null;
        $out = ['incentive_text' => null, 'recommended_bonus_points' => null];

        $loyaltyOn = function_exists('loyalty_return_mode_enabled') && loyalty_return_mode_enabled($restaurantId);
        if (!$loyaltyOn) {
            return $out;
        }

        // Use deterministic recommendation layer when available (manual suggestion only).
        if (file_exists(__DIR__ . '/loyalty_bonus_recommendation.php')) {
            require_once __DIR__ . '/loyalty_bonus_recommendation.php';
        }

        $rec = null;
        if ($type === 'feedback_recovery_upsell' && function_exists('get_loyalty_recovery_bonus_recommendation')) {
            $rec = get_loyalty_recovery_bonus_recommendation($restaurantId, $orderId, $guestId > 0 ? $guestId : null, $rating);
        } elseif ($type === 'feedback_loyalty_upsell' && function_exists('get_loyalty_return_bonus_recommendation')) {
            $rec = get_loyalty_return_bonus_recommendation($restaurantId, $orderId, $guestId > 0 ? $guestId : null);
        }

        if (is_array($rec) && $rec !== []) {
            $pts = (int)($rec['recommended_bonus_points'] ?? 0);
            if ($pts > 0) {
                $out['recommended_bonus_points'] = $pts;
                if ($type === 'feedback_recovery_upsell') {
                    $out['incentive_text'] = 'Если удобно — подготовим компенсацию: +' . $pts . ' баллов при следующем визите (вручную, без автосписаний).';
                } else {
                    // high rating: small bonus or none
                    if ($pts >= 20) {
                        $out['incentive_text'] = 'По желанию — небольшой бонус: +' . $pts . ' баллов при следующем визите (вручную).';
                    } else {
                        $out['incentive_text'] = null;
                    }
                }
            }
        }
        return $out;
    }
}

if (!function_exists('get_feedback_based_suggestions')) {
    /**
     * Read-only suggestion candidates from real feedback rows.
     *
     * @return array<int, array{
     *   type:string,
     *   priority:string,
     *   title:string,
     *   description:string,
     *   payload_json:string,
     *   source:string
     * }>
     */
    function get_feedback_based_suggestions(int $restaurantId, int $limit = 20): array
    {
        $restaurantId = (int)$restaurantId;
        $limit = max(1, min(100, (int)$limit));
        if ($restaurantId <= 0) {
            return [];
        }
        if (!function_exists('db_table_exists') || !db_table_exists('order_feedback') || !db_table_exists('orders')) {
            return [];
        }

        try {
            $pdo = db();
            $guestContactSql = feedback_crm_bridge_select_guest_phone_sql();
            $orderGuestSql = (function_exists('db_column_exists') && db_column_exists('orders', 'guest_id'))
                ? 'o.guest_id AS order_guest_id'
                : 'NULL AS order_guest_id';
            $stmt = $pdo->prepare("
                SELECT
                    f.id AS feedback_id,
                    f.order_id,
                    f.rating,
                    f.comment,
                    f.created_at,
                    {$orderGuestSql},
                    {$guestContactSql}
                FROM order_feedback f
                INNER JOIN orders o
                    ON o.id = f.order_id
                   AND o.restaurant_id = f.restaurant_id
                WHERE f.restaurant_id = :rid
                  AND f.rating IN (1, 2, 3, 4, 5)
                ORDER BY f.created_at DESC
                LIMIT {$limit}
            ");
            $stmt->execute(['rid' => $restaurantId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                return [];
            }

            $out = [];
            foreach ($rows as $row) {
                $rating = (int)($row['rating'] ?? 0);
                $feedbackId = (int)($row['feedback_id'] ?? 0);
                $orderId = (int)($row['order_id'] ?? 0);
                if ($feedbackId <= 0 || $orderId <= 0) {
                    continue;
                }
                $comment = trim((string)($row['comment'] ?? ''));
                $commentPreview = $comment === '' ? '' : mb_substr($comment, 0, 180);
                if ($comment !== '' && mb_strlen($comment) > 180) {
                    $commentPreview .= '...';
                }
                $orderGuestId = isset($row['order_guest_id']) ? (int)$row['order_guest_id'] : 0;
                $guestCtx = function_exists('loyalty_feedback_resolve_guest_context')
                    ? loyalty_feedback_resolve_guest_context($pdo, $restaurantId, $orderId, $orderGuestId > 0 ? $orderGuestId : null)
                    : ['guest_id' => 0, 'has_guest' => false, 'has_loyalty_account' => false];

                $type = '';
                $priority = 'low';
                $reason = '';
                $retentionSegment = 'neutral';
                if ($rating <= 2) {
                    $type = 'feedback_recovery_upsell';
                    $priority = 'high';
                    $reason = 'low_rating_recovery';
                    $retentionSegment = 'low';
                } elseif ($rating >= 5) {
                    $type = 'feedback_loyalty_upsell';
                    $priority = 'medium';
                    $reason = 'loyal_guest_return';
                    $retentionSegment = 'high';
                } else {
                    $type = 'feedback_neutral_upsell';
                    $priority = 'low';
                    $reason = 'experience_improve';
                    $retentionSegment = 'neutral';
                }

                if ($type === '') {
                    continue;
                }

                // 1) Detect base item(s) from the real order context.
                $baseItemIds = feedback_upsell_bridge_get_order_base_item_ids($pdo, $restaurantId, $orderId);
                if ($baseItemIds === []) {
                    continue;
                }

                $returnWindowDays = feedback_upsell_bridge_default_return_window_days($type);
                $createdAt = date('Y-m-d H:i:s');
                $expiresAt = date('Y-m-d H:i:s', strtotime('+' . max(1, $returnWindowDays) . ' days'));
                $expirationHint = 'Действует ' . $returnWindowDays . ' дн.';

                // 2) Use order items as the "cart" context for upsell selection.
                $cartItems = [];
                foreach ($baseItemIds as $bid) {
                    $bid = (int)$bid;
                    if ($bid > 0) {
                        $cartItems[$bid] = 1;
                    }
                }

                // get_contextual_upsells(..., 2) caps to two items; we add deterministic scenario preference.
                $suggestedItems = [];
                try {
                    if (function_exists('get_contextual_upsells')) {
                        $suggestedItems = get_contextual_upsells($restaurantId, $cartItems, [], 0, 0, 2);
                    }
                } catch (Throwable $e) {
                    $suggestedItems = [];
                    error_log('feedback_upsell_bridge get_contextual_upsells rid=' . $restaurantId . ' order_id=' . $orderId . ' reason=' . $e->getMessage());
                }

                if ($suggestedItems === []) {
                    continue;
                }

                if ($type === 'feedback_recovery_upsell') {
                    // Prefer drinks or desserts first.
                    $pref = [];
                    $rest = [];
                    foreach ($suggestedItems as $it) {
                        $cls = function_exists('upsell_engine_classify_text')
                            ? upsell_engine_classify_text((string)($it['name'] ?? ''), null)
                            : [];
                        $isGood = !empty($cls['is_drink']) || !empty($cls['is_dessert']);
                        if ($isGood) $pref[] = $it; else $rest[] = $it;
                    }
                    if ($pref !== []) {
                        $suggestedItems = array_slice(array_merge($pref, $rest), 0, 2);
                    } else {
                        // Fallback: prefer "popular complement" labeled items.
                        $pop = [];
                        $other = [];
                        foreach ($suggestedItems as $it) {
                            $r = (string)($it['reason'] ?? '');
                            if (mb_strpos($r, 'Популярное') !== false) $pop[] = $it; else $other[] = $it;
                        }
                        $suggestedItems = array_slice(array_merge($pop, $other), 0, 2);
                    }
                } elseif ($type === 'feedback_loyalty_upsell') {
                    // Prefer items marked as "popular complement".
                    $pop = [];
                    $rest = [];
                    foreach ($suggestedItems as $it) {
                        $r = (string)($it['reason'] ?? '');
                        if (mb_strpos($r, 'Популярное') !== false) $pop[] = $it; else $rest[] = $it;
                    }
                    $suggestedItems = array_slice(array_merge($pop, $rest), 0, 2);
                } else {
                    // Neutral: keep engine order as-is (non-aggressive).
                    $suggestedItems = array_slice($suggestedItems, 0, 2);
                }

                $suggestedItemIds = [];
                $suggestedNames = [];
                foreach ($suggestedItems as $it) {
                    $id = (int)($it['id'] ?? 0);
                    if ($id > 0) {
                        $suggestedItemIds[] = $id;
                        $suggestedNames[] = (string)($it['name'] ?? '');
                    }
                }
                $suggestedItemIds = array_values(array_unique(array_filter($suggestedItemIds, static fn($x) => (int)$x > 0)));
                $suggestedNames = array_values(array_filter($suggestedNames, static fn($x) => trim((string)$x) !== ''));
                if ($suggestedItemIds === []) {
                    continue;
                }

                $firstItemName = $suggestedNames[0] ?? '';
                $itemNamesStr = $suggestedNames !== [] ? implode(', ', array_slice($suggestedNames, 0, 2)) : '—';

                $bridgeKey = 'feedback:' . $feedbackId . ':' . $type;
                $title = '';
                $description = '';
                if ($type === 'feedback_recovery_upsell') {
                    $title = 'Извиняемся и предложим вместе';
                    $description = 'Причина: ' . $reason . '. В следующий раз попробуйте: ' . $itemNamesStr . '.';
                } elseif ($type === 'feedback_loyalty_upsell') {
                    $title = 'Приглашаем вернуться';
                    $description = 'Причина: ' . $reason . '. Попробуйте при следующем визите: ' . $itemNamesStr . '.';
                } else {
                    $title = 'Рекомендуем улучшить впечатление';
                    $description = 'Причина: ' . $reason . '. Рекомендуем попробовать: ' . $itemNamesStr . '.';
                }

                $payload = [
                    'feedback_id' => $feedbackId,
                    'order_id' => $orderId,
                    'rating' => $rating,
                    'comment_preview' => $commentPreview,
                    'guest_id' => (int)($guestCtx['guest_id'] ?? 0),
                    'base_item_ids' => array_values(array_unique(array_map('intval', $baseItemIds))),
                    'suggested_item_ids' => $suggestedItemIds,
                    'reason' => $reason,
                    'return_window_days' => $returnWindowDays,
                    'campaign_id' => null,
                    'created_at' => $createdAt,
                    'expires_at' => $expiresAt,
                ];

                $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
                if (!is_string($payloadJson)) {
                    continue;
                }

                $out[] = [
                    'type' => $type,
                    'priority' => $priority,
                    'title' => $title,
                    'description' => $description,
                    'bridge_key' => $bridgeKey,
                    'payload_json' => $payloadJson,
                    'source' => 'feedback_upsell_bridge',
                ];
            }

            return $out;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('get_feedback_based_suggestions ' . $e->getMessage());
            }
            return [];
        }
    }
}

if (!function_exists('feedback_crm_bridge_exists_for_feedback')) {
    /**
     * One suggestion per feedback id + type (hard dedupe).
     */
    function feedback_crm_bridge_exists_for_feedback(PDO $pdo, int $restaurantId, string $type, int $feedbackId): bool
    {
        if ($restaurantId <= 0 || $feedbackId <= 0 || $type === '') {
            return false;
        }
        if (!function_exists('db_table_exists') || !db_table_exists('growth_engine_suggestions')) {
            return false;
        }
        try {
            $bridgeKey = 'feedback:' . $feedbackId . ':' . $type;
            if (function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'bridge_key')) {
                $stmt = $pdo->prepare("
                    SELECT 1
                    FROM growth_engine_suggestions
                    WHERE restaurant_id = :rid
                      AND type = :type
                      AND bridge_key = :bkey
                    LIMIT 1
                ");
                $stmt->execute([
                    'rid' => $restaurantId,
                    'type' => $type,
                    'bkey' => $bridgeKey,
                ]);
            } else {
                // Fallback for old schema: exact token match, no prefix collision.
                $needle = '"bridge_key":"' . $bridgeKey . '"';
                $stmt = $pdo->prepare("
                    SELECT 1
                    FROM growth_engine_suggestions
                    WHERE restaurant_id = :rid
                      AND type = :type
                      AND payload_json LIKE :needle
                    LIMIT 1
                ");
                $stmt->execute([
                    'rid' => $restaurantId,
                    'type' => $type,
                    'needle' => '%' . $needle . '%',
                ]);
            }
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('feedback_crm_bridge_exists_for_feedback ' . $e->getMessage());
            }
            return false;
        }
    }
}

if (!function_exists('feedback_crm_bridge_any_suggestion_for_feedback')) {
    /**
     * True if any growth_engine row already exists for this feedback (any type).
     * Uses bridge_key pattern ^feedback:{id}: when available; else payload fallback.
     */
    function feedback_crm_bridge_any_suggestion_for_feedback(PDO $pdo, int $restaurantId, int $feedbackId): bool
    {
        if ($restaurantId <= 0 || $feedbackId <= 0) {
            return false;
        }
        if (!function_exists('db_table_exists') || !db_table_exists('growth_engine_suggestions')) {
            return false;
        }
        try {
            if (function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'bridge_key')) {
                $pattern = '^feedback:' . (int)$feedbackId . ':';
                $stmt = $pdo->prepare("
                    SELECT 1
                    FROM growth_engine_suggestions
                    WHERE restaurant_id = :rid
                      AND bridge_key REGEXP :pat
                    LIMIT 1
                ");
                $stmt->execute(['rid' => $restaurantId, 'pat' => $pattern]);
                return (bool)$stmt->fetchColumn();
            }
            $needle = '"feedback_id":' . (int)$feedbackId;
            $stmt = $pdo->prepare("
                SELECT 1
                FROM growth_engine_suggestions
                WHERE restaurant_id = :rid
                  AND payload_json LIKE :needle
                LIMIT 1
            ");
            $stmt->execute(['rid' => $restaurantId, 'needle' => '%' . $needle . '%']);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('feedback_crm_bridge_any_suggestion_for_feedback ' . $e->getMessage());
            }
            return false;
        }
    }
}

if (!function_exists('publish_feedback_based_suggestions')) {
    /**
     * Explicit write action only. Creates pending internal suggestions.
     */
    function publish_feedback_based_suggestions(int $restaurantId, int $limit = 20): int
    {
        $restaurantId = (int)$restaurantId;
        $limit = max(1, min(100, (int)$limit));
        if ($restaurantId <= 0) {
            return 0;
        }
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return 0;
        }
        if (!function_exists('db_table_exists') || !db_table_exists('growth_engine_suggestions')) {
            return 0;
        }

        $suggestions = get_feedback_based_suggestions($restaurantId, $limit);
        if ($suggestions === []) {
            return 0;
        }

        try {
            $pdo = db();
            $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
            $created = 0;

            foreach ($suggestions as $s) {
                $payloadJson = (string)($s['payload_json'] ?? '');
                $bridgeKey = (string)($s['bridge_key'] ?? '');
                if ($payloadJson === '') {
                    continue;
                }
                $payload = json_decode($payloadJson, true);
                $feedbackId = (int)($payload['feedback_id'] ?? 0);
                $type = (string)($s['type'] ?? '');
                if ($feedbackId <= 0 || $type === '') {
                    continue;
                }

                if (feedback_crm_bridge_any_suggestion_for_feedback($pdo, $restaurantId, $feedbackId)) {
                    continue;
                }

                if (feedback_crm_bridge_exists_for_feedback($pdo, $restaurantId, $type, $feedbackId)) {
                    continue;
                }

                $title = (string)($s['title'] ?? 'Feedback suggestion');
                $description = (string)($s['description'] ?? '');
                $priority = (string)($s['priority'] ?? 'medium');
                if (!in_array($priority, ['high', 'medium', 'low'], true)) {
                    $priority = 'medium';
                }
                $source = (string)($s['source'] ?? 'feedback_crm_bridge');

                if (function_exists('growth_engine_suggestion_duplicate_exists')
                    && growth_engine_suggestion_duplicate_exists($pdo, $restaurantId, $type, $payloadJson, $title)
                ) {
                    continue;
                }

                if (function_exists('app_flow_id_inject_payload_json')) {
                    $payloadJson = app_flow_id_inject_payload_json($payloadJson, $restaurantId);
                }

                if ($hasStatus) {
                    if (function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'bridge_key')) {
                        $stmt = $pdo->prepare("
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json, bridge_key, priority, source, status)
                            VALUES
                                (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                        ");
                        $stmt->execute([$restaurantId, $type, $title, $description, $payloadJson, ($bridgeKey !== '' ? $bridgeKey : null), $priority, $source]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json, priority, source, status)
                            VALUES
                                (?, ?, ?, ?, ?, ?, ?, 'pending')
                        ");
                        $stmt->execute([$restaurantId, $type, $title, $description, $payloadJson, $priority, $source]);
                    }
                } else {
                    if (function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'bridge_key')) {
                        $stmt = $pdo->prepare("
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json, bridge_key)
                            VALUES
                                (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$restaurantId, $type, $title, $description, $payloadJson, ($bridgeKey !== '' ? $bridgeKey : null)]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json)
                            VALUES
                                (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$restaurantId, $type, $title, $description, $payloadJson]);
                    }
                }
                $created++;
            }

            return $created;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('publish_feedback_based_suggestions ' . $e->getMessage());
            }
            return 0;
        }
    }
}

if (!function_exists('feedback_crm_bridge_accept_suggestion')) {
    /**
     * Accept feedback suggestion and create CRM-oriented follow-up draft when possible.
     * Returns:
     *  - ok=true, created_draft=true|false, contact_available=true|false
     */
    function feedback_crm_bridge_accept_suggestion(int $restaurantId, int $suggestionId): array
    {
        $result = ['ok' => false, 'created_draft' => false, 'contact_available' => false, 'message' => ''];
        $restaurantId = (int)$restaurantId;
        $suggestionId = (int)$suggestionId;
        if ($restaurantId <= 0 || $suggestionId <= 0) {
            $result['message'] = 'Некорректное предложение.';
            return $result;
        }
        if (!function_exists('db_table_exists') || !db_table_exists('growth_engine_suggestions')) {
            $result['message'] = 'Хранилище предложений недоступно.';
            return $result;
        }
        if (!function_exists('growth_engine_accept_suggestion')) {
            $result['message'] = 'Lifecycle недоступен.';
            return $result;
        }

        $startedTx = false;
        try {
            $pdo = db();
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTx = true;
            }
            $stmt = $pdo->prepare("
                SELECT id, type, title, description, payload_json, status
                FROM growth_engine_suggestions
                WHERE id = :id AND restaurant_id = :rid
                LIMIT 1
            ");
            $stmt->execute(['id' => $suggestionId, 'rid' => $restaurantId]);
            $s = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$s) {
                if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                $result['message'] = 'Предложение не найдено.';
                return $result;
            }
            $type = (string)($s['type'] ?? '');
            $supported = [
                'feedback_recovery_draft',
                'feedback_positive_return_draft',
                'feedback_recovery_upsell',
                'feedback_neutral_upsell',
                'feedback_loyalty_upsell',
            ];
            if (!in_array($type, $supported, true)) {
                if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                $result['message'] = 'Тип предложения не поддерживается bridge-обработчиком.';
                return $result;
            }
            if (($s['status'] ?? 'pending') !== 'pending') {
                if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                $result['ok'] = true;
                $result['message'] = 'Предложение уже обработано.';
                return $result;
            }

            $payload = json_decode((string)($s['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $upsellTypes = ['feedback_recovery_upsell', 'feedback_neutral_upsell', 'feedback_loyalty_upsell'];
            if (in_array($type, $upsellTypes, true)) {
                $createdDraft = false;

                $guestId = (int)($payload['guest_id'] ?? 0);
                $orderId = (int)($payload['order_id'] ?? 0);
                $reason = (string)($payload['reason'] ?? '');
                $returnWindowDays = (int)($payload['return_window_days'] ?? 0);
                if ($returnWindowDays <= 0) {
                    $returnWindowDays = feedback_upsell_bridge_default_return_window_days($type);
                }
                $createdAt = date('Y-m-d H:i:s');
                $expiresAt = date('Y-m-d H:i:s', strtotime('+' . max(1, $returnWindowDays) . ' days'));
                $expirationHint = 'Действует ' . $returnWindowDays . ' дн. (до ' . date('d.m', strtotime($expiresAt)) . ')';
                $suggestedItemIds = $payload['suggested_item_ids'] ?? [];
                if (!is_array($suggestedItemIds)) $suggestedItemIds = [];
                $suggestedItemIds = array_values(array_unique(array_filter(array_map('intval', $suggestedItemIds), static fn($x) => $x > 0)));

                if ($orderId <= 0 || $suggestedItemIds === []) {
                    if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                    $result['message'] = 'Некорректные данные предложения.';
                    return $result;
                }

                // Fetch item names for message construction.
                $menuItemNames = [];
                try {
                    $ph = implode(',', array_fill(0, count($suggestedItemIds), '?'));
                    $params = array_merge([$restaurantId], $suggestedItemIds);
                    $stmt = $pdo->prepare("SELECT id, name FROM menu_items WHERE restaurant_id = ? AND id IN ($ph) LIMIT 100");
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $mid = (int)($r['id'] ?? 0);
                        if ($mid > 0) {
                            $menuItemNames[$mid] = (string)($r['name'] ?? '');
                        }
                    }
                } catch (Throwable $e) {
                    if (function_exists('error_log')) {
                        error_log('feedback_upsell_bridge_accept_menu_items rid=' . $restaurantId . ' ' . $e->getMessage());
                    }
                }

                $firstId = (int)($suggestedItemIds[0] ?? 0);
                $firstName = $firstId > 0 && !empty($menuItemNames[$firstId]) ? $menuItemNames[$firstId] : '';
                $incent = function_exists('feedback_upsell_bridge_build_incentive_text')
                    ? feedback_upsell_bridge_build_incentive_text($pdo, $restaurantId, $type, $orderId, $guestId, ($type === 'feedback_recovery_upsell' ? (int)($payload['rating'] ?? 0) : null))
                    : ['incentive_text' => null, 'recommended_bonus_points' => null];
                $incentiveText = $incent['incentive_text'] ?? null;
                $recommendedBonusPoints = isset($incent['recommended_bonus_points']) ? (int)$incent['recommended_bonus_points'] : null;

                $messageText = function_exists('feedback_upsell_bridge_crm_message_ru')
                    ? feedback_upsell_bridge_crm_message_ru($type, $firstName, $returnWindowDays, $incentiveText, $expirationHint)
                    : ('Рекомендуем вернуться. Попробуйте: ' . $firstName);

                $why = '';
                if ($type === 'feedback_recovery_upsell') {
                    $why = 'Почему сработает: короткое окно + компенсация снижает барьер возвращения после негативного опыта.';
                } elseif ($type === 'feedback_neutral_upsell') {
                    $why = 'Почему сработает: социальное доказательство + конкретная рекомендация повышают шанс повторного визита.';
                } else {
                    $why = 'Почему сработает: благодарность + мягкий upsell укрепляют привычку возвращаться.';
                }

                $suggestedItemsPayload = [];
                foreach ($suggestedItemIds as $sid) {
                    $suggestedItemsPayload[] = [
                        'id' => (int)$sid,
                        'name' => (string)($menuItemNames[$sid] ?? ''),
                    ];
                }

                $draftPayload = [
                    'guest_id' => $guestId,
                    'order_id' => $orderId,
                    'reason' => $reason,
                    'retention_segment' => $retentionSegment,
                    'message_text' => $messageText,
                    'suggested_item_ids' => $suggestedItemIds,
                    'suggested_items' => $suggestedItemsPayload,
                    'incentive_text' => $incentiveText,
                    'recommended_bonus_points' => $recommendedBonusPoints,
                    'return_window_days' => $returnWindowDays,
                    'expiration_hint' => $expirationHint,
                    'expires_at' => $expiresAt,
                    'campaign_id' => $payload['campaign_id'] ?? null,
                    'created_at' => $createdAt,
                    'why_this_works' => $why,
                    'source_offer_suggestion_id' => $suggestionId,
                ];
                $draftPayloadJson = json_encode($draftPayload, JSON_UNESCAPED_UNICODE);
                if (!is_string($draftPayloadJson)) {
                    $draftPayloadJson = '{}';
                }

                $draftType = 'crm_retention_with_offer';
                $draftTitle = 'CRM offer draft: ' . ($firstName !== '' ? $firstName : ('order #' . $orderId));
                $draftDescription = 'Черновик CRM на основе feedback->upsell->return сценария. Откройте в CRM для ручного просмотра.';
                $draftSource = 'feedback_upsell_bridge';

                if (function_exists('app_flow_id_inject_payload_json')) {
                    $draftPayloadJson = app_flow_id_inject_payload_json($draftPayloadJson, $restaurantId);
                }

                if (!function_exists('growth_engine_suggestion_duplicate_exists')
                    || !growth_engine_suggestion_duplicate_exists($pdo, $restaurantId, $draftType, $draftPayloadJson, $draftTitle)
                ) {
                    $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
                    if ($hasStatus) {
                        $ins = $pdo->prepare("
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json, priority, source, status)
                            VALUES
                                (?, ?, ?, ?, ?, 'medium', ?, 'pending')
                        ");
                        $ins->execute([$restaurantId, $draftType, $draftTitle, $draftDescription, $draftPayloadJson, $draftSource]);
                    } else {
                        $ins = $pdo->prepare("
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json)
                            VALUES
                                (?, ?, ?, ?, ?)
                        ");
                        $ins->execute([$restaurantId, $draftType, $draftTitle, $draftDescription, $draftPayloadJson]);
                    }
                    $createdDraft = true;
                }

                if (!growth_engine_accept_suggestion($restaurantId, $suggestionId)) {
                    if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                    $result['message'] = 'Не удалось принять предложение.';
                    return $result;
                }

                if ($startedTx && $pdo->inTransaction()) {
                    $pdo->commit();
                }

                $result['ok'] = true;
                $result['created_draft'] = $createdDraft;
                $result['message'] = $createdDraft
                    ? 'Предложение принято и CRM-черновик создан.'
                    : 'Предложение принято. CRM-черновик уже существует.';
                return $result;
            }

            $contact = trim((string)($payload['guest_contact'] ?? ''));
            $result['contact_available'] = ($contact !== '');

            $createdDraft = false;
            if ($contact !== '') {
                $draftPayload = [
                    'guest_contact' => $contact,
                    'message' => (string)($payload['suggested_message'] ?? ''),
                    'feedback_id' => (int)($payload['feedback_id'] ?? 0),
                    'order_id' => (int)($payload['order_id'] ?? 0),
                    'rating' => (int)($payload['rating'] ?? 0),
                    'source_suggestion_id' => $suggestionId,
                ];
                $draftPayloadJson = json_encode($draftPayload, JSON_UNESCAPED_UNICODE);
                if (!is_string($draftPayloadJson)) {
                    $draftPayloadJson = '{}';
                }

                if ($type === 'feedback_recovery_draft') {
                    $draftType = 'crm_retention_draft';
                    $draftTitle = 'Recovery draft for ' . $contact;
                    $draftDescription = 'Draft from low-rating feedback. Review manually in CRM.';
                    $draftSource = 'feedback_crm_bridge';
                } else {
                    $draftType = 'guest_return_offer';
                    $draftTitle = 'Guest return: ' . $contact;
                    $draftDescription = 'Comeback draft from positive feedback. Review manually in CRM.';
                    $draftSource = 'feedback_crm_bridge';
                }

                if (!function_exists('growth_engine_suggestion_duplicate_exists')
                    || !growth_engine_suggestion_duplicate_exists($pdo, $restaurantId, $draftType, $draftPayloadJson, $draftTitle)
                ) {
                    $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
                    if ($hasStatus) {
                        $ins = $pdo->prepare("
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json, priority, source, status)
                            VALUES
                                (?, ?, ?, ?, ?, 'medium', ?, 'pending')
                        ");
                        $ins->execute([$restaurantId, $draftType, $draftTitle, $draftDescription, $draftPayloadJson, $draftSource]);
                    } else {
                        $ins = $pdo->prepare("
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json)
                            VALUES
                                (?, ?, ?, ?, ?)
                        ");
                        $ins->execute([$restaurantId, $draftType, $draftTitle, $draftDescription, $draftPayloadJson]);
                    }
                    $createdDraft = true;
                }
            }

            if (!growth_engine_accept_suggestion($restaurantId, $suggestionId)) {
                if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                $result['message'] = 'Не удалось принять предложение.';
                return $result;
            }

            if ($startedTx && $pdo->inTransaction()) {
                $pdo->commit();
            }

            $result['ok'] = true;
            $result['created_draft'] = $createdDraft;
            if ($contact === '') {
                $result['message'] = 'Предложение принято. Контакт гостя не найден — доступен только internal follow-up.';
            } elseif ($createdDraft) {
                $result['message'] = 'Предложение принято и CRM-черновик создан.';
            } else {
                $result['message'] = 'Предложение принято. CRM-черновик уже существует.';
            }
            return $result;
        } catch (Throwable $e) {
            if ($startedTx && isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('error_log')) {
                error_log('feedback_crm_bridge_accept_suggestion ' . $e->getMessage());
            }
            $result['message'] = 'Не удалось обработать предложение.';
            return $result;
        }
    }
}

