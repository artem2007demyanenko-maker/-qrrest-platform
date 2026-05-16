<?php
/**
 * Legacy compatibility layer.
 * Canonical production loyalty flow lives in app/guest_loyalty.php.
 */
require_once __DIR__ . '/db.php';

if (!function_exists('loyalty_uuid_v4')) {
function loyalty_uuid_v4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  $hex = bin2hex($data);
  return sprintf('%s-%s-%s-%s-%s',
    substr($hex,0,8),
    substr($hex,8,4),
    substr($hex,12,4),
    substr($hex,16,4),
    substr($hex,20,12)
  );
}
}

if (!function_exists('loyalty_get_or_create_card')) {
function loyalty_get_or_create_card(PDO $pdo, int $guest_id, int $restaurant_id): array {
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT * FROM guest_cards WHERE guest_id=? AND restaurant_id=? LIMIT 1");
    $st->execute([$guest_id, $restaurant_id]);
    $card = $st->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
      $uid = loyalty_uuid_v4();
      $pdo->prepare("INSERT INTO guest_cards (guest_id, restaurant_id, card_uid) VALUES (?,?,?)")
          ->execute([$guest_id, $restaurant_id, $uid]);

      $card_id = (int)$pdo->lastInsertId();
      $pdo->prepare("INSERT INTO loyalty_accounts (card_id, balance) VALUES (?,0)")
          ->execute([$card_id]);

      $st = $pdo->prepare("SELECT * FROM guest_cards WHERE id=? LIMIT 1");
      $st->execute([$card_id]);
      $card = $st->fetch(PDO::FETCH_ASSOC);
    }

    $pdo->commit();
    return $card;
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}
}

if (!function_exists('loyalty_get_settings')) {
function loyalty_get_settings(PDO $pdo, int $restaurant_id): array {
  $st = $pdo->prepare("SELECT * FROM restaurant_loyalty_settings WHERE restaurant_id=? LIMIT 1");
  $st->execute([$restaurant_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    // Read-only default; do NOT auto-write settings on read.
    $row = [
      'restaurant_id' => $restaurant_id,
      'earn_percent' => '0.00',
      'enabled' => 0,
      'loyalty_return_mode_enabled' => 0,
    ];
  }

  return $row;
}
}

if (!function_exists('loyalty_earn_for_order')) {
function loyalty_earn_for_order(PDO $pdo, int $card_id, int $order_id, int $points): void {
  if ($points <= 0) return;

  $pdo->beginTransaction();
  try {
    $pdo->prepare("UPDATE loyalty_accounts SET balance = balance + ? WHERE card_id=?")
        ->execute([$points, $card_id]);

    $pdo->prepare("INSERT INTO loyalty_transactions (card_id, order_id, type, points) VALUES (?,?,?,?)")
        ->execute([$card_id, $order_id, 'earn', $points]);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}
}
if (!function_exists('loyalty_lookup_by_uid')) {
function loyalty_lookup_by_uid(PDO $pdo, string $uid): ?array {
  $st = $pdo->prepare("
    SELECT gc.*, la.balance
    FROM guest_cards gc
    JOIN loyalty_accounts la ON la.card_id = gc.id
    WHERE gc.card_uid = ?
    LIMIT 1
  ");
  $st->execute([$uid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
}
