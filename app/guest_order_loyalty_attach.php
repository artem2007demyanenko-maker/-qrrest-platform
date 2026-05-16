<?php
/**
 * Idempotent: attach a completed QR order to session guest, accrue guest_loyalty points once per order.
 */

if (file_exists(__DIR__ . '/schema_guard.php')) {
    require_once __DIR__ . '/schema_guard.php';
}

if (!function_exists('guest_loyalty_phone_key')) {
    function guest_loyalty_phone_key(?string $phone): string {
        if ($phone === null || trim($phone) === '') {
            return '';
        }
        if (function_exists('loyalty_normalize_phone')) {
            $n = loyalty_normalize_phone($phone);
            return $n !== null ? (string)$n : '';
        }
        return preg_replace('/\D+/', '', $phone);
    }
}

if (!function_exists('guest_order_loyalty_payment_status')) {
    function guest_order_loyalty_payment_status(?string $status): string {
        $status = strtolower(trim((string)$status));
        $map = [
            'pending_payment' => 'pending',
            'cancelled' => 'canceled',
        ];
        return $map[$status] ?? $status;
    }
}

if (!function_exists('guest_order_loyalty_is_final_status')) {
    function guest_order_loyalty_is_final_status(?string $status): bool {
        $status = strtolower(trim((string)$status));
        return in_array($status, ['delivered', 'completed'], true);
    }
}

if (!function_exists('guest_order_loyalty_is_eligible')) {
    function guest_order_loyalty_is_eligible(array $order): bool {
        $orderStatus = strtolower(trim((string)($order['order_status'] ?? '')));
        if (in_array($orderStatus, ['canceled', 'cancelled'], true)) {
            return false;
        }
        if (!guest_order_loyalty_is_final_status($orderStatus)) {
            return false;
        }
        return guest_order_loyalty_payment_status((string)($order['payment_status'] ?? 'unpaid')) === 'paid';
    }
}

if (!function_exists('guest_order_loyalty_percent')) {
    function guest_order_loyalty_percent(PDO $pdo, array $restaurantRow): float {
        $percent = 5.0;

        if (function_exists('loyalty_get_settings')) {
            try {
                $settings = loyalty_get_settings($pdo, (int)($restaurantRow['id'] ?? 0));
                if (isset($settings['earn_percent'])) {
                    $candidate = (float)$settings['earn_percent'];
                    if ($candidate >= 0 && $candidate <= 100) {
                        $percent = $candidate;
                    }
                }
            } catch (Throwable $e) {
                // fallback to restaurant row / default below
            }
        }

        if (isset($restaurantRow['loyalty_percent'])) {
            $candidate = (float)$restaurantRow['loyalty_percent'];
            if ($candidate >= 0 && $candidate <= 100) {
                $percent = $candidate;
            }
        }

        return $percent;
    }
}

if (!function_exists('guest_order_loyalty_existing_points')) {
    function guest_order_loyalty_existing_points(PDO $pdo, int $restaurantId, int $orderId): int {
        try {
            if (function_exists('db_table_exists') && db_table_exists('guest_loyalty_tx')) {
                $stmt = $pdo->prepare("
                    SELECT points
                    FROM guest_loyalty_tx
                    WHERE restaurant_id = ?
                      AND order_id = ?
                      AND type IN ('accrual', 'earn')
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt->execute([$restaurantId, $orderId]);
                $points = $stmt->fetchColumn();
                if ($points !== false) {
                    return max(0, (int)$points);
                }
            }

            if (
                function_exists('db_table_exists') && db_table_exists('loyalty_transactions')
                && function_exists('db_table_exists') && db_table_exists('loyalty_accounts')
                && function_exists('db_column_exists') && db_column_exists('loyalty_transactions', 'order_id')
            ) {
                if (
                    function_exists('db_column_exists') && db_column_exists('loyalty_transactions', 'card_id')
                    && function_exists('db_column_exists') && db_column_exists('loyalty_accounts', 'card_id')
                    && function_exists('db_table_exists') && db_table_exists('guest_cards')
                    && function_exists('db_column_exists') && db_column_exists('guest_cards', 'restaurant_id')
                ) {
                    $stmt = $pdo->prepare("
                        SELECT lt.points
                        FROM loyalty_transactions lt
                        INNER JOIN loyalty_accounts la ON la.card_id = lt.card_id
                        INNER JOIN guest_cards gc ON gc.id = la.card_id
                        WHERE gc.restaurant_id = ?
                          AND lt.order_id = ?
                          AND lt.type IN ('earn', 'accrual')
                        ORDER BY lt.id DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$restaurantId, $orderId]);
                    $points = $stmt->fetchColumn();
                    if ($points !== false) {
                        return max(0, (int)$points);
                    }
                }

                if (
                    function_exists('db_column_exists') && db_column_exists('loyalty_transactions', 'account_id')
                    && function_exists('db_column_exists') && db_column_exists('loyalty_accounts', 'restaurant_id')
                ) {
                    $stmt = $pdo->prepare("
                        SELECT lt.points
                        FROM loyalty_transactions lt
                        INNER JOIN loyalty_accounts la ON la.id = lt.account_id
                        WHERE la.restaurant_id = ?
                          AND lt.order_id = ?
                          AND lt.type IN ('earn', 'accrual')
                        ORDER BY lt.id DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$restaurantId, $orderId]);
                    $points = $stmt->fetchColumn();
                    if ($points !== false) {
                        return max(0, (int)$points);
                    }
                }
            }

            return 0;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('guest_order_loyalty_accrual_exists')) {
    function guest_order_loyalty_accrual_exists(PDO $pdo, int $restaurantId, int $orderId): bool {
        return guest_order_loyalty_existing_points($pdo, $restaurantId, $orderId) > 0;
    }
}

if (!function_exists('guest_order_loyalty_sync')) {
    /**
     * Canonical loyalty sync for QR orders.
     * - orders.guest_id is the primary guest link when available
     * - orders.guest_card_id is the primary restaurant-card link when available
     * - loyalty_phone stays compat/snapshot-only for old flows and legacy lookups
     * - loyalty_points_* in orders are snapshot/display-only fields and are written only after real ledger accrual
     *
     * @return array{ok: bool, error?: string, already?: bool, points?: int, balance?: int, pending_payment?: bool, attached?: bool, skipped?: bool}
     */
    function guest_order_loyalty_sync(PDO $pdo, array $restaurantRow, int $orderId, ?int $guestId = null): array {
        $orderId = (int)$orderId;
        $guestId = $guestId !== null ? (int)$guestId : 0;
        $restaurantId = (int)($restaurantRow['id'] ?? 0);
        if ($orderId <= 0 || $restaurantId <= 0) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $hasOrderGuestIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'guest_id');
        $hasOrderGuestCardIdCol = function_exists('db_column_exists') && db_column_exists('orders', 'guest_card_id');

        if (function_exists('db_column_exists') && db_column_exists('orders', 'total_amount')) {
            $amtCol = 'COALESCE(total_amount, total_price, 0)';
        } else {
            $amtCol = 'total_price';
        }

        $guestIdCol = $hasOrderGuestIdCol ? 'guest_id' : '0 AS guest_id';
        $guestCardIdCol = $hasOrderGuestCardIdCol ? 'guest_card_id' : '0 AS guest_card_id';

        $stmt = $pdo->prepare("
            SELECT id, restaurant_id, order_status, payment_status, {$amtCol} AS total_amt,
                   {$guestIdCol},
                   {$guestCardIdCol},
                   loyalty_phone, loyalty_points_accrued, loyalty_points_balance_after
            FROM orders
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || (int)$order['restaurant_id'] !== $restaurantId) {
            return ['ok' => false, 'error' => 'order_not_found'];
        }

        $orderStatus = strtolower((string)($order['order_status'] ?? ''));
        if (in_array($orderStatus, ['canceled', 'cancelled'], true)) {
            return ['ok' => false, 'error' => 'order_canceled'];
        }

        $loyaltyEnabled = true;
        if (function_exists('loyalty_is_enabled_for_restaurant')) {
            $loyaltyEnabled = loyalty_is_enabled_for_restaurant($restaurantRow);
        } elseif (empty($restaurantRow['loyalty_enabled'])) {
            $loyaltyEnabled = false;
        }
        if ($loyaltyEnabled && !is_demo_mode() && file_exists(__DIR__ . '/subscription_plans.php')) {
            require_once __DIR__ . '/subscription_plans.php';
            if (function_exists('check_feature') && !check_feature($restaurantId, 'loyalty_enabled')) {
                $loyaltyEnabled = false;
            }
        }
        if (!$loyaltyEnabled) {
            return ['ok' => false, 'error' => 'loyalty_disabled'];
        }

        $tx = null;
        try {
            if (function_exists('guest_loyalty_tx_begin')) {
                $tx = guest_loyalty_tx_begin($pdo);
            } elseif (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $tx = ['own' => true, 'sp' => null];
            } else {
                $tx = ['own' => false, 'sp' => null];
            }

            $existingPhone = trim((string)($order['loyalty_phone'] ?? ''));
            $resolvedPhone = guest_loyalty_phone_key($existingPhone);
            $guest = null;
            $existingOrderGuestId = $hasOrderGuestIdCol ? (int)($order['guest_id'] ?? 0) : 0;
            $existingOrderGuestCardId = $hasOrderGuestCardIdCol ? (int)($order['guest_card_id'] ?? 0) : 0;

            if ($guestId > 0) {
                if ($existingOrderGuestId > 0 && $existingOrderGuestId !== $guestId) {
                    if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                        guest_loyalty_tx_undo($pdo, $tx);
                    }
                    return ['ok' => false, 'error' => 'order_guest_conflict'];
                }
                $gStmt = $pdo->prepare('SELECT id, phone, name FROM guests WHERE id = ? LIMIT 1');
                $gStmt->execute([$guestId]);
                $guest = $gStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$guest) {
                    if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                        guest_loyalty_tx_undo($pdo, $tx);
                    }
                    return ['ok' => false, 'error' => 'guest_not_found'];
                }
                $guestPhone = guest_loyalty_phone_key((string)($guest['phone'] ?? ''));
                if ($guestPhone === '') {
                    if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                        guest_loyalty_tx_undo($pdo, $tx);
                    }
                    return ['ok' => false, 'error' => 'bad_phone'];
                }
                if ($resolvedPhone !== '' && $resolvedPhone !== $guestPhone) {
                    if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                        guest_loyalty_tx_undo($pdo, $tx);
                    }
                    return ['ok' => false, 'error' => 'order_phone_conflict'];
                }
                $resolvedPhone = $guestPhone;
            }

            if (!$guest && $existingOrderGuestId > 0) {
                $gStmt = $pdo->prepare('SELECT id, phone, name FROM guests WHERE id = ? LIMIT 1');
                $gStmt->execute([$existingOrderGuestId]);
                $guest = $gStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($guest) {
                    $guestPhone = guest_loyalty_phone_key((string)($guest['phone'] ?? ''));
                    if ($resolvedPhone !== '' && $guestPhone !== '' && $resolvedPhone !== $guestPhone) {
                        if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                            guest_loyalty_tx_undo($pdo, $tx);
                        }
                        return ['ok' => false, 'error' => 'order_guest_conflict'];
                    }
                    if ($resolvedPhone === '' && $guestPhone !== '') {
                        $resolvedPhone = $guestPhone;
                    }
                }
            }

            if ($resolvedPhone !== '' && !$guest && function_exists('guest_find_by_phone')) {
                $guest = guest_find_by_phone($pdo, $resolvedPhone);
            }

            $resolvedGuestId = (int)($guest['id'] ?? 0);
            $resolvedCardId = $existingOrderGuestCardId;
            if ($resolvedGuestId > 0 && function_exists('guest_loyalty_card_by_guest_rest')) {
                $existingCard = guest_loyalty_card_by_guest_rest($pdo, $restaurantId, $resolvedGuestId);
                if ($existingCard) {
                    $resolvedCardId = (int)($existingCard['id'] ?? 0);
                }
            }

            $orderLinkSet = [];
            $orderLinkParams = [
                ':id' => $orderId,
                ':rid' => $restaurantId,
            ];
            if ($resolvedPhone !== '' && $resolvedPhone !== $existingPhone) {
                $orderLinkSet[] = 'loyalty_phone = :phone';
                $orderLinkParams[':phone'] = $resolvedPhone;
                $order['loyalty_phone'] = $resolvedPhone;
            }
            if ($hasOrderGuestIdCol && $resolvedGuestId > 0 && $resolvedGuestId !== $existingOrderGuestId) {
                $orderLinkSet[] = 'guest_id = :guest_id';
                $orderLinkParams[':guest_id'] = $resolvedGuestId;
                $order['guest_id'] = $resolvedGuestId;
            }
            if ($hasOrderGuestCardIdCol && $resolvedCardId > 0 && $resolvedCardId !== $existingOrderGuestCardId) {
                $orderLinkSet[] = 'guest_card_id = :guest_card_id';
                $orderLinkParams[':guest_card_id'] = $resolvedCardId;
                $order['guest_card_id'] = $resolvedCardId;
            }
            if ($orderLinkSet !== []) {
                $updPhone = $pdo->prepare("
                    UPDATE orders
                    SET " . implode(', ', $orderLinkSet) . "
                    WHERE id = :id AND restaurant_id = :rid
                ");
                $updPhone->execute($orderLinkParams);
            }

            if (!guest_order_loyalty_is_eligible($order)) {
                if ($tx !== null && function_exists('guest_loyalty_tx_release')) {
                    guest_loyalty_tx_release($pdo, $tx);
                } elseif (!empty($tx['own']) && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                return [
                    'ok' => true,
                    'attached' => ($resolvedPhone !== '' || $resolvedGuestId > 0),
                    'pending_payment' => ($resolvedPhone !== '' || $resolvedGuestId > 0),
                    'points' => 0,
                    'balance' => 0,
                    'already' => false,
                ];
            }

            if ($resolvedPhone === '') {
                if ($tx !== null && function_exists('guest_loyalty_tx_release')) {
                    guest_loyalty_tx_release($pdo, $tx);
                } elseif (!empty($tx['own']) && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                return ['ok' => true, 'skipped' => true, 'points' => 0, 'balance' => 0, 'already' => false];
            }

            if (!$guest && function_exists('guest_loyalty_find_or_create_guest_by_phone')) {
                $guest = guest_loyalty_find_or_create_guest_by_phone($pdo, $resolvedPhone);
            }
            if (!$guest) {
                if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                    guest_loyalty_tx_undo($pdo, $tx);
                }
                return ['ok' => false, 'error' => 'guest_not_found'];
            }

            if (function_exists('guest_issue_card_for_restaurant')) {
                $cardRes = guest_issue_card_for_restaurant($pdo, $restaurantId, $resolvedPhone, $guest['name'] ?? null);
                if (!is_array($cardRes) || empty($cardRes['ok'])) {
                    if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                        guest_loyalty_tx_undo($pdo, $tx);
                    }
                    return ['ok' => false, 'error' => 'card_issue_failed'];
                }
                if ($hasOrderGuestCardIdCol) {
                    $resolvedCardId = (int)($cardRes['card']['id'] ?? $resolvedCardId);
                }
            }

            $guestIdResolved = (int)($guest['id'] ?? 0);
            $total = (float)($order['total_amt'] ?? 0);
            if ($guestIdResolved <= 0 || $total <= 0) {
                if ($tx !== null && function_exists('guest_loyalty_tx_release')) {
                    guest_loyalty_tx_release($pdo, $tx);
                } elseif (!empty($tx['own']) && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                return ['ok' => true, 'skipped' => true, 'points' => 0, 'balance' => 0, 'already' => false];
            }

            $loyaltyPercent = guest_order_loyalty_percent($pdo, $restaurantRow);
            $points = $loyaltyPercent > 0 ? (int)floor($total * $loyaltyPercent / 100) : 0;
            $balanceAfter = function_exists('guest_loyalty_balance_by_guest_rest')
                ? guest_loyalty_balance_by_guest_rest($pdo, $restaurantId, $guestIdResolved)
                : 0;
            $already = false;

            if ($points > 0) {
                if (guest_order_loyalty_accrual_exists($pdo, $restaurantId, $orderId)) {
                    $already = true;
                    $points = guest_order_loyalty_existing_points($pdo, $restaurantId, $orderId);
                } elseif (function_exists('loyalty_wallet_adjust')) {
                    $phoneCandidates = [$resolvedPhone];
                    if (strlen($resolvedPhone) === 11 && str_starts_with($resolvedPhone, '7')) {
                        $phoneCandidates[] = '+' . $resolvedPhone;
                        $phoneCandidates[] = '8' . substr($resolvedPhone, 1);
                    }
                    $phoneCandidates = array_values(array_unique(array_filter($phoneCandidates, static function ($v): bool {
                        return is_string($v) && trim($v) !== '';
                    })));

                    $resolvedGuestPayload = [
                        'guest_id' => $guestIdResolved,
                        'guest_name' => (string)($guest['name'] ?? ''),
                        'phone_normalized' => $resolvedPhone,
                        'phone_candidates' => $phoneCandidates,
                    ];

                    $accrual = loyalty_wallet_adjust(
                        $pdo,
                        $restaurantId,
                        $resolvedGuestPayload,
                        'earn',
                        $points,
                        [
                            'order_id' => $orderId,
                            'note' => 'AUTO_LOYALTY_ACCRUAL order#' . $orderId,
                        ]
                    );
                    if (!is_array($accrual) || empty($accrual['ok'])) {
                        if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                            guest_loyalty_tx_undo($pdo, $tx);
                        }
                        return ['ok' => false, 'error' => 'accrual_failed'];
                    }

                    if (function_exists('loyalty_guest_balance')) {
                        $walletAfter = loyalty_guest_balance($pdo, $restaurantId, $resolvedGuestPayload);
                        $balanceAfter = (int)($walletAfter['current_balance'] ?? $balanceAfter);
                    } else {
                        $balanceAfter = (int)($accrual['balance'] ?? $balanceAfter);
                    }
                } elseif (function_exists('guest_loyalty_add_points')) {
                    // Legacy fallback for installs where unified wallet layer is unavailable.
                    $accrual = guest_loyalty_add_points($pdo, $restaurantId, $guestIdResolved, $points, null, $orderId, 'AUTO_LOYALTY_ACCRUAL order#' . $orderId);
                    if (!is_array($accrual) || empty($accrual['ok'])) {
                        if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                            guest_loyalty_tx_undo($pdo, $tx);
                        }
                        return ['ok' => false, 'error' => 'accrual_failed'];
                    }
                    $already = !empty($accrual['duplicate_order']);
                    $balanceAfter = (int)($accrual['balance'] ?? $balanceAfter);
                } else {
                    if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                        guest_loyalty_tx_undo($pdo, $tx);
                    }
                    return ['ok' => false, 'error' => 'accrual_layer_unavailable'];
                }

                if ($already) {
                    if ($points <= 0) {
                        $points = max(0, (int)($order['loyalty_points_accrued'] ?? 0));
                    }
                    if (function_exists('loyalty_guest_balance')) {
                        $walletAfter = loyalty_guest_balance($pdo, $restaurantId, [
                            'guest_id' => $guestIdResolved,
                            'phone_normalized' => $resolvedPhone,
                            'phone_candidates' => [$resolvedPhone],
                            'guest_name' => (string)($guest['name'] ?? ''),
                        ]);
                        $balanceAfter = (int)($walletAfter['current_balance'] ?? $balanceAfter);
                    }
                }
            }

            $snapshotSet = [
                'loyalty_phone = :phone',
                'loyalty_points_accrued = :accr',
                'loyalty_points_balance_after = :bal',
            ];
            $snapshotParams = [
                ':phone' => $resolvedPhone,
                ':accr' => max(0, $points),
                ':bal' => max(0, (int)$balanceAfter),
                ':id' => $orderId,
                ':rid' => $restaurantId,
            ];
            if ($hasOrderGuestIdCol) {
                $snapshotSet[] = 'guest_id = :guest_id';
                $snapshotParams[':guest_id'] = $guestIdResolved;
            }
            if ($hasOrderGuestCardIdCol && $resolvedCardId > 0) {
                $snapshotSet[] = 'guest_card_id = :guest_card_id';
                $snapshotParams[':guest_card_id'] = $resolvedCardId;
            }
            $upd = $pdo->prepare("
                UPDATE orders
                SET " . implode(",\n                    ", $snapshotSet) . "
                WHERE id = :id AND restaurant_id = :rid
            ");
            $upd->execute($snapshotParams);

            $crmEligible = ($points > 0 && !$already && file_exists(__DIR__ . '/crm_repo.php'));
            if ($crmEligible) {
                require_once __DIR__ . '/crm_repo.php';
                if (function_exists('crm_touch_visit_after_qr_order')) {
                    try {
                        crm_touch_visit_after_qr_order(
                            $restaurantId,
                            $orderId,
                            (string)$resolvedPhone,
                            $total,
                            0,
                            false
                        );
                    } catch (Throwable $crmEx) {
                        if (function_exists('error_log')) {
                            error_log('guest_order_loyalty_sync CRM ' . $crmEx->getMessage());
                        }
                    }
                }
            }

            if ($tx !== null && function_exists('guest_loyalty_tx_release')) {
                guest_loyalty_tx_release($pdo, $tx);
            } elseif (!empty($tx['own']) && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'ok' => true,
                'already' => $already,
                'points' => max(0, (int)$points),
                'balance' => max(0, (int)$balanceAfter),
                'pending_payment' => false,
                'attached' => true,
            ];
        } catch (Throwable $e) {
            if ($tx !== null && function_exists('guest_loyalty_tx_undo')) {
                guest_loyalty_tx_undo($pdo, $tx);
            } elseif (!empty($tx['own']) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('error_log')) {
                error_log('guest_order_loyalty_sync ' . $e->getMessage());
            }
            return ['ok' => false, 'error' => 'save_failed'];
        }
    }
}

if (!function_exists('guest_order_attach_loyalty')) {
    /**
     * @return array{ok: bool, error?: string, already?: bool, points?: int, balance?: int, pending_payment?: bool, attached?: bool, skipped?: bool}
     */
    function guest_order_attach_loyalty(PDO $pdo, array $restaurantRow, int $orderId, int $guestId): array {
        return guest_order_loyalty_sync($pdo, $restaurantRow, $orderId, $guestId);
    }
}
