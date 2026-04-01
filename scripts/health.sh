#!/usr/bin/env bash
set -e

echo "== web config =="
docker compose exec -T web php -r '$c=require "/var/www/html/app/config.php"; var_export($c["app"]); echo PHP_EOL;'

echo "== db ping =="
docker compose exec -T web php -r 'require "/var/www/html/app/db.php"; $pdo=db(); echo "DB=" . $pdo->query("SELECT DATABASE()")->fetchColumn() . PHP_EOL;'

echo "== tables count =="
docker compose exec -T web php -r 'require "/var/www/html/app/db.php"; $pdo=db(); echo "tables=" . count($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;'
