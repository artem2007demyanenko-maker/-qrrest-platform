#!/usr/bin/env php
<?php
/**
 * Локальная отладка: привязать restaurant_id=1 к поддомену demo и создать стол/заказ.
 *
 * Боевой клиентский демо-показ использует поддомен `demo` и данные из app/demo.php
 * (без записи в БД). Не запускайте на проде, если id=1 — реальный ресторан.
 *
 * Usage: php scripts/seed_demo.php
 */

$baseDir = dirname(__DIR__);
$config = require $baseDir . '/app/config.php';
$db = $config['db'] ?? null;
if (!$db || empty($db['host']) || empty($db['name'])) {
    fwrite(STDERR, "ERROR: DB config missing\n");
    exit(1);
}
$dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=" . ($db['charset'] ?? 'utf8mb4');
$pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Ensure we have an owner user for restaurant
$stmt = $pdo->query("SELECT id FROM users LIMIT 1");
$ownerId = $stmt->fetchColumn();
if (!$ownerId) {
    $pdo->exec("INSERT INTO users (email, password_hash, global_role, created_at) VALUES ('owner@demo.local', '" . password_hash('demo', PASSWORD_DEFAULT) . "', 'owner', NOW())");
    $ownerId = (int)$pdo->lastInsertId();
}

// Restaurant id=1 with subdomain=demo (create if missing, update subdomain)
$stmt = $pdo->query("SELECT id FROM restaurants WHERE id = 1 LIMIT 1");
if (!$stmt->fetchColumn()) {
    $pdo->exec("INSERT INTO restaurants (id, name, subdomain, owner_user_id, status, created_at) VALUES (1, 'Гастропаб «Север»', 'demo', " . (int)$ownerId . ", 'active', NOW())");
} else {
    $pdo->exec("UPDATE restaurants SET subdomain = 'demo' WHERE id = 1");
}

// Table id=1 for restaurant_id=1
$stmt = $pdo->prepare("SELECT id FROM tables WHERE id = 1 AND restaurant_id = 1 LIMIT 1");
$stmt->execute();
if (!$stmt->fetchColumn()) {
    $pdo->exec("INSERT INTO tables (id, restaurant_id, name, created_at) VALUES (1, 1, 'Зал · стол 1', NOW())");
}

// At least one order for restaurant 1, table 1 (create if none)
$stmt = $pdo->prepare("SELECT id FROM orders WHERE restaurant_id = 1 AND table_id = 1 LIMIT 1");
$stmt->execute();
if (!$stmt->fetchColumn()) {
    $cols = ['restaurant_id', 'table_id', 'created_at'];
    $vals = ['1', '1', 'NOW()'];
    if ($pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'total_price'")->fetchColumn()) {
        $cols[] = 'total_price';
        $vals[] = '0';
    }
    if ($pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_status'")->fetchColumn()) {
        $cols[] = 'payment_status';
        $vals[] = "'unpaid'";
    }
    if ($pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'order_status'")->fetchColumn()) {
        $cols[] = 'order_status';
        $vals[] = "'new'";
    }
    $pdo->exec("INSERT INTO orders (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")");
}

echo "seed_demo: done (restaurant 1 subdomain=demo, table 1, order present)\n";
exit(0);
