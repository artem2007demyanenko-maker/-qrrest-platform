<?php

declare(strict_types=1);

$legacyUpsellActions = ['add_upsell_rule', 'delete_upsell_rule', 'toggle_upsell_rule'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (in_array($action, $legacyUpsellActions, true)) {
        require_once __DIR__ . '/../../app/bootstrap.php';

        require_login();
        require_current_restaurant();
        require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        $csrfOk = isset($_POST['csrf'], $_SESSION['csrf'])
            && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
        if (!$csrfOk) {
            http_response_code(400);
            echo 'Неверный запрос. Обновите страницу и попробуйте снова.';
            exit;
        }

        $pdo = db();
        if (!$pdo instanceof PDO) {
            http_response_code(500);
            echo 'DB connection error';
            exit;
        }

        $restaurantId = (int)($currentRestaurant['id'] ?? 0);
        try {
            if (!function_exists('db_table_exists') || !db_table_exists('menu_upsell_rules')) {
                throw new RuntimeException('menu_upsell_rules_missing');
            }

            if ($action === 'add_upsell_rule') {
                $triggerId = (int)($_POST['trigger_item_id'] ?? 0);
                $suggestId = (int)($_POST['suggest_item_id'] ?? 0);
                $priority = (int)($_POST['priority'] ?? 0);

                if ($triggerId > 0 && $suggestId > 0 && $triggerId !== $suggestId) {
                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM menu_upsell_rules
                        WHERE restaurant_id = :rest
                          AND trigger_item_id = :tr
                          AND suggest_item_id = :sg
                        LIMIT 1
                    ");
                    $stmt->execute([
                        ':rest' => $restaurantId,
                        ':tr' => $triggerId,
                        ':sg' => $suggestId,
                    ]);
                    $existingId = (int)$stmt->fetchColumn();

                    if ($existingId > 0) {
                        $upd = $pdo->prepare("
                            UPDATE menu_upsell_rules
                            SET priority = :pr
                            WHERE id = :id AND restaurant_id = :rest
                        ");
                        $upd->execute([
                            ':pr' => $priority,
                            ':id' => $existingId,
                            ':rest' => $restaurantId,
                        ]);
                    } else {
                        $ins = $pdo->prepare("
                            INSERT INTO menu_upsell_rules (restaurant_id, trigger_item_id, suggest_item_id, priority)
                            VALUES (:rest, :tr, :sg, :pr)
                        ");
                        $ins->execute([
                            ':rest' => $restaurantId,
                            ':tr' => $triggerId,
                            ':sg' => $suggestId,
                            ':pr' => $priority,
                        ]);
                    }
                }
            } elseif ($action === 'delete_upsell_rule') {
                $ruleId = (int)($_POST['rule_id'] ?? 0);
                if ($ruleId > 0) {
                    $stmt = $pdo->prepare("
                        DELETE FROM menu_upsell_rules
                        WHERE id = :id AND restaurant_id = :rest
                    ");
                    $stmt->execute([
                        ':id' => $ruleId,
                        ':rest' => $restaurantId,
                    ]);
                }
            } elseif (
                $action === 'toggle_upsell_rule'
                && function_exists('db_column_exists')
                && db_column_exists('menu_upsell_rules', 'enabled')
            ) {
                $ruleId = (int)($_POST['rule_id'] ?? 0);
                $enabled = isset($_POST['enabled']) ? (int)($_POST['enabled'] ?? 0) : 0;
                if ($ruleId > 0 && in_array($enabled, [0, 1], true)) {
                    $stmt = $pdo->prepare("
                        UPDATE menu_upsell_rules
                        SET enabled = :enabled
                        WHERE id = :id AND restaurant_id = :rest
                    ");
                    $stmt->execute([
                        ':enabled' => $enabled,
                        ':id' => $ruleId,
                        ':rest' => $restaurantId,
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('MENU_ITEMS_LEGACY_UPSELL_COMPAT_FAIL rest_id=' . $restaurantId . ' ' . $e->getMessage());
        }

        header('Location: /restaurant/menu_manage.php#dishes', true, 303);
        exit;
    }

    require __DIR__ . '/menu_manage.php';
    exit;
}

$query = $_GET;
$anchor = '#dishes';
if (isset($query['edit']) || isset($query['add'])) {
    $anchor = '#dish-form';
}

$target = '/restaurant/menu_manage.php';
if ($query !== []) {
    $target .= '?' . http_build_query($query);
}
$target .= $anchor;

header('Location: ' . $target, true, 302);
exit;
