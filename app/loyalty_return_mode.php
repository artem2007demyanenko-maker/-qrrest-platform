<?php
/**
 * Optional loyalty-based return/recovery *suggestions* only.
 * No auto-send, no point credits, no checkout changes.
 */

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

if (!function_exists('loyalty_feedback_resolve_guest_context')) {
    /**
     * Resolve guest / loyalty ledger presence for an order (read-only).
     *
     * @return array{guest_id:int,has_guest:bool,has_loyalty_account:bool}
     */
    function loyalty_feedback_resolve_guest_context(PDO $pdo, int $restaurantId, int $orderId, ?int $orderGuestId): array
    {
        $gid = (int)($orderGuestId ?? 0);
        if ($gid <= 0 && $orderId > 0 && function_exists('db_column_exists') && db_column_exists('orders', 'guest_id')) {
            try {
                $st = $pdo->prepare('SELECT guest_id FROM orders WHERE id = ? LIMIT 1');
                $st->execute([$orderId]);
                $gid = (int)($st->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $gid = 0;
            }
        }
        $hasGuest = $gid > 0;
        $hasLoyalty = false;
        if ($hasGuest && function_exists('db_table_exists') && db_table_exists('guest_loyalty_accounts')) {
            try {
                $st = $pdo->prepare('SELECT 1 FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1');
                $st->execute([$gid, $restaurantId]);
                $hasLoyalty = (bool)$st->fetchColumn();
            } catch (Throwable $e) {
                $hasLoyalty = false;
            }
        }
        return [
            'guest_id' => $hasGuest ? $gid : 0,
            'has_guest' => $hasGuest,
            'has_loyalty_account' => $hasLoyalty,
        ];
    }
}

if (!function_exists('loyalty_return_mode_enabled')) {
    function loyalty_return_mode_enabled(int $restaurantId): bool
    {
        if ($restaurantId <= 0) {
            return false;
        }
        if (function_exists('is_demo_mode') && is_demo_mode()) {
            return false;
        }
        if (!function_exists('db_column_exists') || !db_column_exists('restaurant_loyalty_settings', 'loyalty_return_mode_enabled')) {
            return false;
        }
        try {
            $pdo = db();
            $st = $pdo->prepare('SELECT loyalty_return_mode_enabled FROM restaurant_loyalty_settings WHERE restaurant_id = ? LIMIT 1');
            $st->execute([$restaurantId]);
            $v = $st->fetchColumn();
            return ((int)$v === 1);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('loyalty_return_mode_enabled ' . $e->getMessage());
            }
            return false;
        }
    }
}

if (!function_exists('build_loyalty_return_suggestion')) {
    /**
     * Internal growth suggestion row for happy guests (publish layer only).
     *
     * @param array{feedback_id:int,order_id:int,rating:int,comment_preview?:string,guest_contact?:string,feedback_created_at?:string} $ctx
     * @return array{type:string,priority:string,title:string,description:string,bridge_key:string,payload_json:string,source:string}|null
     */
    function build_loyalty_return_suggestion(array $ctx): ?array
    {
        $feedbackId = (int)($ctx['feedback_id'] ?? 0);
        $orderId = (int)($ctx['order_id'] ?? 0);
        $rating = (int)($ctx['rating'] ?? 0);
        if ($feedbackId <= 0 || $orderId <= 0) {
            return null;
        }
        $type = 'loyalty_return_offer';
        $bridgeKey = 'feedback:' . $feedbackId . ':' . $type;
        $payload = [
            'bridge_key' => $bridgeKey,
            'feedback_id' => $feedbackId,
            'order_id' => $orderId,
            'rating' => $rating,
            'comment_preview' => (string)($ctx['comment_preview'] ?? ''),
            'guest_contact' => ($ctx['guest_contact'] ?? null),
            'contact_available' => (trim((string)($ctx['guest_contact'] ?? '')) !== ''),
            'feedback_created_at' => (string)($ctx['feedback_created_at'] ?? ''),
            'loyalty_return_hint' => true,
            'guest_id' => (int)($ctx['guest_id'] ?? 0),
            'has_guest' => !empty($ctx['has_guest']),
            'has_loyalty_account' => !empty($ctx['has_loyalty_account']),
            'explanation' => (string)($ctx['explanation'] ?? ''),
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            return null;
        }
        $explanation = (string)($ctx['explanation'] ?? '');
        return [
            'type' => $type,
            'priority' => 'low',
            'title' => 'Invite guest back with bonus points',
            'description' => $explanation !== '' ? $explanation : 'Happy guest may respond well to a bonus-based return offer.',
            'bridge_key' => $bridgeKey,
            'payload_json' => $payloadJson,
            'source' => 'loyalty_return_mode',
        ];
    }
}

if (!function_exists('build_loyalty_recovery_suggestion')) {
    /**
     * @param array{feedback_id:int,order_id:int,rating:int,comment_preview?:string,guest_contact?:string,feedback_created_at?:string} $ctx
     * @return array{type:string,priority:string,title:string,description:string,bridge_key:string,payload_json:string,source:string}|null
     */
    function build_loyalty_recovery_suggestion(array $ctx): ?array
    {
        $feedbackId = (int)($ctx['feedback_id'] ?? 0);
        $orderId = (int)($ctx['order_id'] ?? 0);
        $rating = (int)($ctx['rating'] ?? 0);
        if ($feedbackId <= 0 || $orderId <= 0) {
            return null;
        }
        $type = 'loyalty_recovery_offer';
        $bridgeKey = 'feedback:' . $feedbackId . ':' . $type;
        $payload = [
            'bridge_key' => $bridgeKey,
            'feedback_id' => $feedbackId,
            'order_id' => $orderId,
            'rating' => $rating,
            'comment_preview' => (string)($ctx['comment_preview'] ?? ''),
            'guest_contact' => ($ctx['guest_contact'] ?? null),
            'contact_available' => (trim((string)($ctx['guest_contact'] ?? '')) !== ''),
            'feedback_created_at' => (string)($ctx['feedback_created_at'] ?? ''),
            'loyalty_recovery_hint' => true,
            'guest_id' => (int)($ctx['guest_id'] ?? 0),
            'has_guest' => !empty($ctx['has_guest']),
            'has_loyalty_account' => !empty($ctx['has_loyalty_account']),
            'explanation' => (string)($ctx['explanation'] ?? ''),
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            return null;
        }
        $explanation = (string)($ctx['explanation'] ?? '');
        return [
            'type' => $type,
            'priority' => 'medium',
            'title' => 'Offer recovery bonus points',
            'description' => $explanation !== '' ? $explanation : 'Guest left a low rating. Consider compensating with bonus points.',
            'bridge_key' => $bridgeKey,
            'payload_json' => $payloadJson,
            'source' => 'loyalty_return_mode',
        ];
    }
}

if (!function_exists('build_loyalty_return_window_suggestion')) {
    /**
     * Aggregate-window companion (feedback growth automation).
     *
     * @param array{window_start:string,window_end:string,days:int} $window
     * @return array{type:string,priority:string,title:string,description:string,payload_json:string,bridge_key:string,source:string}|null
     */
    function build_loyalty_return_window_suggestion(array $window): ?array
    {
        $start = (string)($window['window_start'] ?? '');
        $end = (string)($window['window_end'] ?? '');
        if ($start === '' || $end === '') {
            return null;
        }
        $type = 'loyalty_return_offer';
        $bridgeKey = 'feedback-auto:' . $type . ':' . $start . ':' . $end;
        $payload = [
            'bridge_key' => $bridgeKey,
            'window_start' => $start,
            'window_end' => $end,
            'days' => (int)($window['days'] ?? 0),
            'loyalty_return_hint' => true,
            'guest_id' => 0,
            'has_guest' => false,
            'has_loyalty_account' => false,
            'explanation' => 'Guest left 5★ rating, good candidate for return',
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            return null;
        }
        return [
            'type' => $type,
            'priority' => 'low',
            'title' => 'Invite guest back with bonus points',
            'description' => 'Guest left 5★ rating, good candidate for return',
            'payload_json' => $payloadJson,
            'bridge_key' => $bridgeKey,
            'source' => 'loyalty_return_mode',
        ];
    }
}

if (!function_exists('build_loyalty_recovery_window_suggestion')) {
    /**
     * @param array{window_start:string,window_end:string,days:int,low_ratings_count?:int} $window
     */
    function build_loyalty_recovery_window_suggestion(array $window): ?array
    {
        $start = (string)($window['window_start'] ?? '');
        $end = (string)($window['window_end'] ?? '');
        if ($start === '' || $end === '') {
            return null;
        }
        $type = 'loyalty_recovery_offer';
        $bridgeKey = 'feedback-auto:' . $type . ':' . $start . ':' . $end;
        $payload = [
            'bridge_key' => $bridgeKey,
            'window_start' => $start,
            'window_end' => $end,
            'days' => (int)($window['days'] ?? 0),
            'low_ratings_count' => (int)($window['low_ratings_count'] ?? 0),
            'loyalty_recovery_hint' => true,
            'guest_id' => 0,
            'has_guest' => false,
            'has_loyalty_account' => false,
            'explanation' => 'Guest left low rating and no contact available',
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson)) {
            return null;
        }
        return [
            'type' => $type,
            'priority' => 'medium',
            'title' => 'Offer recovery bonus points',
            'description' => 'Guest left low rating and no contact available',
            'payload_json' => $payloadJson,
            'bridge_key' => $bridgeKey,
            'source' => 'loyalty_return_mode',
        ];
    }
}

if (!function_exists('loyalty_return_mode_accept_suggestion')) {
    /**
     * Accept loyalty offer → pending loyalty_manual_action (no balance change), then mark offer accepted.
     *
     * @return array{ok:bool,message:string}
     */
    function loyalty_return_mode_accept_suggestion(int $restaurantId, int $suggestionId): array
    {
        $result = ['ok' => false, 'message' => ''];
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
        if (file_exists(__DIR__ . '/growth_engine_arch.php')) {
            require_once __DIR__ . '/growth_engine_arch.php';
        }
        if (file_exists(__DIR__ . '/loyalty_bonus_recommendation.php')) {
            require_once __DIR__ . '/loyalty_bonus_recommendation.php';
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
            $stmt = $pdo->prepare('SELECT id, type, payload_json, status FROM growth_engine_suggestions WHERE id = ? AND restaurant_id = ? LIMIT 1');
            $stmt->execute([$suggestionId, $restaurantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                $result['message'] = 'Предложение не найдено.';
                return $result;
            }
            $type = (string)($row['type'] ?? '');
            if (!in_array($type, ['loyalty_recovery_offer', 'loyalty_return_offer'], true)) {
                if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                $result['message'] = 'Тип предложения не поддерживается.';
                return $result;
            }
            if (($row['status'] ?? 'pending') !== 'pending') {
                if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                $result['ok'] = true;
                $result['message'] = 'Предложение уже обработано.';
                return $result;
            }

            $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $guestId = (int)($payload['guest_id'] ?? 0);
            $orderId = (int)($payload['order_id'] ?? 0);
            $feedbackId = (int)($payload['feedback_id'] ?? 0);
            $ratingFb = (int)($payload['rating'] ?? 0);

            if (!function_exists('loyalty_return_mode_enabled') || !loyalty_return_mode_enabled($restaurantId)) {
                if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                $result['message'] = 'Loyalty return mode is disabled for this restaurant.';
                return $result;
            }

            if ($type === 'loyalty_recovery_offer') {
                $manualTitle = 'Recover guest with bonus';
                $rec = function_exists('get_loyalty_recovery_bonus_recommendation')
                    ? get_loyalty_recovery_bonus_recommendation(
                        $restaurantId,
                        $orderId,
                        $guestId > 0 ? $guestId : null,
                        ($ratingFb === 1 || $ratingFb === 2) ? $ratingFb : null
                    )
                    : null;
            } else {
                $manualTitle = 'Reward loyal guest';
                $rec = function_exists('get_loyalty_return_bonus_recommendation')
                    ? get_loyalty_return_bonus_recommendation(
                        $restaurantId,
                        $orderId,
                        $guestId > 0 ? $guestId : null
                    )
                    : null;
            }

            if (!is_array($rec) || $rec === []) {
                if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
                $result['message'] = 'Не удалось построить рекомендацию по баллам. Проверьте режим лояльности и повторите попытку.';
                return $result;
            }

            $manualPayload = [
                'guest_id' => $guestId,
                'has_guest' => !empty($payload['has_guest']),
                'has_loyalty_account' => !empty($payload['has_loyalty_account']),
                'order_id' => $orderId,
                'order_total' => (float)($rec['order_total'] ?? 0),
                'recommended_bonus_points' => (int)($rec['recommended_bonus_points'] ?? 0),
                'reason_code' => (string)($rec['reason_code'] ?? ''),
                'reason_text' => (string)($rec['reason_text'] ?? ''),
                'reason' => (string)($rec['reason_code'] ?? ''),
                'bonus_percent_equivalent' => $rec['bonus_percent_equivalent'] ?? null,
                'operator_summary' => (string)($rec['operator_summary'] ?? ''),
                'visits_count' => (int)($rec['visits_count'] ?? 0),
                'feedback_id' => $feedbackId,
                'source_offer_suggestion_id' => $suggestionId,
            ];
            $manualJson = json_encode($manualPayload, JSON_UNESCAPED_UNICODE);
            if (!is_string($manualJson)) {
                $manualJson = '{}';
            }

            $manualType = 'loyalty_manual_action';
            $manualDesc = (string)($rec['operator_summary'] ?? 'Ручное начисление баллов (без автокредита). Проверьте и выполните в инструментах лояльности.');
            $manualBridge = 'loyalty-manual:offer:' . $suggestionId;
            $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
            $hasBridgeKey = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'bridge_key');

            $manualExists = false;
            if ($hasBridgeKey) {
                $chk = $pdo->prepare('SELECT 1 FROM growth_engine_suggestions WHERE restaurant_id = ? AND bridge_key = ? LIMIT 1');
                $chk->execute([$restaurantId, $manualBridge]);
                $manualExists = (bool)$chk->fetchColumn();
            }

            if (!$manualExists) {
                if ($hasStatus) {
                    if ($hasBridgeKey) {
                        $ins = $pdo->prepare('
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json, bridge_key, priority, source, status)
                            VALUES
                                (?, ?, ?, ?, ?, ?, \'high\', \'loyalty_return_mode\', \'pending\')
                        ');
                        $ins->execute([$restaurantId, $manualType, $manualTitle, $manualDesc, $manualJson, $manualBridge]);
                    } else {
                        $ins = $pdo->prepare('
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json, priority, source, status)
                            VALUES
                                (?, ?, ?, ?, ?, \'high\', \'loyalty_return_mode\', \'pending\')
                        ');
                        $ins->execute([$restaurantId, $manualType, $manualTitle, $manualDesc, $manualJson]);
                    }
                } else {
                    if ($hasBridgeKey) {
                        $ins = $pdo->prepare('
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json, bridge_key)
                            VALUES
                                (?, ?, ?, ?, ?, ?)
                        ');
                        $ins->execute([$restaurantId, $manualType, $manualTitle, $manualDesc, $manualJson, $manualBridge]);
                    } else {
                        $ins = $pdo->prepare('
                            INSERT INTO growth_engine_suggestions
                                (restaurant_id, type, title, description, payload_json)
                            VALUES
                                (?, ?, ?, ?, ?)
                        ');
                        $ins->execute([$restaurantId, $manualType, $manualTitle, $manualDesc, $manualJson]);
                    }
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
            $result['message'] = 'Создано действие по баллам (без автоначисления). Исходное предложение принято.';
            return $result;
        } catch (Throwable $e) {
            if ($startedTx && isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('error_log')) {
                error_log('loyalty_return_mode_accept_suggestion ' . $e->getMessage());
            }
            $result['message'] = 'Не удалось обработать предложение.';
            return $result;
        }
    }
}
