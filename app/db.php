<?php
// app/db.php

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $config = require __DIR__ . '/config.php';
        $db = $config['db'];
        $port = isset($db['port']) ? (int)$db['port'] : 3306;
        $dsn = "mysql:host={$db['host']};port={$port};dbname={$db['name']};charset=" . ($db['charset'] ?? 'utf8mb4');
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
    }

    return $pdo;
}
