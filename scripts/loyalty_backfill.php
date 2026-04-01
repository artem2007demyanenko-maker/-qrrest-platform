#!/usr/bin/env php
<?php
/**
 * One-time backfill: legacy loyalty_accounts → guest_loyalty_accounts (canonical).
 * - Does NOT overwrite existing canonical balances.
 * - Only inserts rows when (guest_id, restaurant_id) has no canonical row.
 * - Maps legacy by phone (→ guests) or by card_id (→ guest_cards → guest_id).
 * Usage: php scripts/loyalty_backfill.php [--dry-run]
 * Run from project root. Requires DB config.
 */

$baseDir = dirname(__DIR__);
if (!is_file($baseDir . '/app/config.php')) {
    fwrite(STDERR, "ERROR: app/config.php not found. Run from project root.\n");
    exit(1);
}
$config = require $baseDir . '/app/config.php';
$db = $config['db'] ?? null;
if (!$db || empty($db['name'])) {
    fwrite(STDERR, "ERROR: DB config missing.\n");
    exit(1);
}

require_once $baseDir . '/app/db.php';
$pdo = db();

$dryRun = in_array('--dry-run', $argv ?? [], true);

function normalize_phone(string $raw): ?string {
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') return null;
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits[0] = '7';
    }
    return $digits;
}

function table_has_columns(PDO $pdo, string $table, array $cols): bool {
    foreach ($cols as $c) {
        $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $st->execute([$table, $c]);
        if (!$st->fetchColumn()) return false;
    }
    return true;
}

// Ensure canonical tables exist
foreach (['guest_loyalty_accounts', 'guests'] as $t) {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$t]);
    if (!$st->fetchColumn()) {
        fwrite(STDERR, "ERROR: Table {$t} not found. Run migrations first.\n");
        exit(1);
    }
}

$st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loyalty_accounts' LIMIT 1");
$st->execute();
if (!$st->fetchColumn()) {
    echo "No legacy table loyalty_accounts. Nothing to backfill.\n";
    exit(0);
}

$stats = ['phone_skipped_no_guest' => 0, 'phone_skipped_canonical_exists' => 0, 'phone_inserted' => 0, 'card_skipped_canonical_exists' => 0, 'card_inserted' => 0];

// ---- Phone-based legacy (restaurant_id, phone, points_balance) ----
$phoneBased = table_has_columns($pdo, 'loyalty_accounts', ['restaurant_id', 'phone']);
$balanceCol = 'points_balance';
if ($phoneBased) {
    if (!table_has_columns($pdo, 'loyalty_accounts', ['points_balance'])) {
        $balanceCol = 'balance';
    }
    $sql = "SELECT id, restaurant_id, phone, " . $balanceCol . " AS bal FROM loyalty_accounts WHERE restaurant_id IS NOT NULL AND phone IS NOT NULL AND TRIM(phone) != ''";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $ins = $pdo->prepare("INSERT INTO guest_loyalty_accounts (guest_id, restaurant_id, balance) VALUES (?, ?, ?)");
    $chk = $pdo->prepare("SELECT 1 FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");
    $guestByPhone = $pdo->prepare("SELECT id FROM guests WHERE phone = ? LIMIT 1");

    foreach ($rows as $r) {
        $phoneNorm = normalize_phone((string)$r['phone']);
        if (!$phoneNorm) {
            $stats['phone_skipped_no_guest']++;
            continue;
        }
        $guestByPhone->execute([$phoneNorm]);
        $guestId = $guestByPhone->fetchColumn();
        if (!$guestId) {
            $stats['phone_skipped_no_guest']++;
            continue;
        }
        $guestId = (int)$guestId;
        $restaurantId = (int)$r['restaurant_id'];
        $chk->execute([$guestId, $restaurantId]);
        if ($chk->fetchColumn()) {
            $stats['phone_skipped_canonical_exists']++;
            continue;
        }
        $balance = max(0, (int)($r['bal'] ?? 0));
        if (!$dryRun) {
            $pdo->beginTransaction();
            try {
                $ins->execute([$guestId, $restaurantId, $balance]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                fwrite(STDERR, "Insert failed guest_id={$guestId} restaurant_id={$restaurantId}: " . $e->getMessage() . "\n");
            }
        }
        $stats['phone_inserted']++;
    }
}

// ---- Card-based legacy (card_id, balance) → guest_cards.guest_id, guest_cards.restaurant_id ----
$cardBased = table_has_columns($pdo, 'loyalty_accounts', ['card_id']);
if ($cardBased && table_has_columns($pdo, 'guest_cards', ['id', 'guest_id', 'restaurant_id'])) {
    $balanceColCard = 'balance';
    if (!table_has_columns($pdo, 'loyalty_accounts', ['balance'])) {
        $balanceColCard = 'points_balance';
    }
    $sql = "SELECT la.id, la.card_id, la.{$balanceColCard} AS bal, gc.guest_id, gc.restaurant_id
            FROM loyalty_accounts la
            INNER JOIN guest_cards gc ON gc.id = la.card_id
            WHERE la.card_id IS NOT NULL";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $insCard = $pdo->prepare("INSERT INTO guest_loyalty_accounts (guest_id, restaurant_id, balance) VALUES (?, ?, ?)");
    $chkCard = $pdo->prepare("SELECT 1 FROM guest_loyalty_accounts WHERE guest_id = ? AND restaurant_id = ? LIMIT 1");

    foreach ($rows as $r) {
        $guestId = (int)$r['guest_id'];
        $restaurantId = (int)$r['restaurant_id'];
        $chkCard->execute([$guestId, $restaurantId]);
        if ($chkCard->fetchColumn()) {
            $stats['card_skipped_canonical_exists']++;
            continue;
        }
        $balance = max(0, (int)($r['bal'] ?? 0));
        if (!$dryRun) {
            $pdo->beginTransaction();
            try {
                $insCard->execute([$guestId, $restaurantId, $balance]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                fwrite(STDERR, "Insert failed guest_id={$guestId} restaurant_id={$restaurantId}: " . $e->getMessage() . "\n");
            }
        }
        $stats['card_inserted']++;
    }
}

echo $dryRun ? "[DRY-RUN] " : "";
echo "Backfill done. Phone-based: skipped_no_guest={$stats['phone_skipped_no_guest']}, skipped_canonical_exists={$stats['phone_skipped_canonical_exists']}, inserted={$stats['phone_inserted']}. ";
echo "Card-based: skipped_canonical_exists={$stats['card_skipped_canonical_exists']}, inserted={$stats['card_inserted']}.\n";
if ($dryRun && ($stats['phone_inserted'] > 0 || $stats['card_inserted'] > 0)) {
    echo "Run without --dry-run to apply.\n";
}
