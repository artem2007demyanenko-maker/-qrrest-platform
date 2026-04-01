<?php
/**
 * Viral growth: restaurant-based referral codes and invite tracking.
 * Code format: restaurant_slug + '-' + random (e.g. pasta-bistro-7A9C).
 */

require_once __DIR__ . '/db.php';

const REFERRAL_MAX_INVITES_PER_DAY = 10;
const REFERRAL_STATUS_INVITED = 'invited';
const REFERRAL_STATUS_SIGNED_UP = 'signed_up';
const REFERRAL_STATUS_ACTIVE = 'active';

/**
 * Ensure table referral_restaurant_codes exists.
 */
function referral_table_codes_exists(): bool
{
    if (!function_exists('db_table_exists')) {
        return false;
    }
    return db_table_exists('referral_restaurant_codes');
}

/**
 * Ensure table referrals exists.
 */
function referral_table_exists(): bool
{
    if (!function_exists('db_table_exists')) {
        return false;
    }
    return db_table_exists('referrals');
}

/**
 * Generate random suffix (4 alphanumeric, e.g. 7A9C).
 */
function referral_random_suffix(): string
{
    $alphabet = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $len = 4;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

/**
 * Get or create referral code for restaurant. Format: slug-XXXX.
 * @return string code (e.g. pasta-bistro-7A9C) or '' if tables missing
 */
function referral_ensure_code_for_restaurant(int $restaurantId): string
{
    if (!referral_table_codes_exists()) {
        return '';
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT code FROM referral_restaurant_codes WHERE restaurant_id = ? LIMIT 1");
    $stmt->execute([$restaurantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (string)$row['code'];
    }
    $stmt = $pdo->prepare("SELECT subdomain FROM restaurants WHERE id = ? LIMIT 1");
    $stmt->execute([$restaurantId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    $slug = $r ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string)$r['subdomain'])) : 'rest';
    if ($slug === '') {
        $slug = 'rest';
    }
    $maxAttempts = 10;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $code = $slug . '-' . referral_random_suffix();
        try {
            $ins = $pdo->prepare("INSERT INTO referral_restaurant_codes (restaurant_id, code) VALUES (?, ?)");
            $ins->execute([$restaurantId, $code]);
            return $code;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                continue;
            }
            throw $e;
        }
    }
    return '';
}

/**
 * Get referrer_restaurant_id by code. For signup?ref=CODE.
 * @return array{referrer_restaurant_id:int}|null
 */
function referral_get_by_code(string $code): ?array
{
    $code = trim($code);
    if ($code === '' || !referral_table_codes_exists()) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT restaurant_id FROM referral_restaurant_codes WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return ['referrer_restaurant_id' => (int)$row['restaurant_id']];
}

/**
 * Count invites sent today (UTC) by this restaurant. Anti-spam.
 */
function referral_count_invites_today(int $restaurantId): int
{
    if (!referral_table_exists()) {
        return 0;
    }
    $pdo = db();
    $today = gmdate('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM referrals
        WHERE referrer_restaurant_id = ? AND DATE(created_at) = ?
    ");
    $stmt->execute([$restaurantId, $today]);
    return (int)$stmt->fetchColumn();
}

/**
 * Create invite: insert referrals row and send email. Returns [ok, error message].
 * Enforces max 10 invites per day.
 */
function referral_create_invite(int $referrerRestaurantId, string $referredEmail, string $signupUrl, string $demoUrl): array
{
    $referredEmail = trim(strtolower($referredEmail));
    if ($referredEmail === '' || !filter_var($referredEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Некорректный email.'];
    }
    if (!referral_table_exists()) {
        return [false, 'Сервис приглашений временно недоступен.'];
    }
    $count = referral_count_invites_today($referrerRestaurantId);
    if ($count >= REFERRAL_MAX_INVITES_PER_DAY) {
        return [false, 'Достигнут лимит приглашений на сегодня (' . REFERRAL_MAX_INVITES_PER_DAY . '). Попробуйте завтра.'];
    }
    $pdo = db();
    $code = referral_ensure_code_for_restaurant($referrerRestaurantId);
    if ($code !== '') {
        $signupUrl = rtrim($signupUrl, '/') . (strpos($signupUrl, '?') !== false ? '&' : '?') . 'ref=' . urlencode($code);
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO referrals (referrer_restaurant_id, referred_email, status)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$referrerRestaurantId, $referredEmail, REFERRAL_STATUS_INVITED]);
    } catch (PDOException $e) {
        error_log('REFERRAL_CREATE_INVITE rest=' . $referrerRestaurantId . ' ' . $e->getMessage());
        return [false, 'Не удалось сохранить приглашение.'];
    }
    referral_send_invite_email($referredEmail, $signupUrl, $demoUrl);
    return [true, ''];
}

/**
 * Send invitation email (plain text). No-op if mail fails (log only).
 */
function referral_send_invite_email(string $to, string $signupUrl, string $demoUrl): void
{
    $subject = 'Invitation to try QR Restaurant System';
    $body = "You were invited to try the QR Restaurant system.\n\n";
    $body .= "See demo:\n" . $demoUrl . "\n\n";
    $body .= "Create your restaurant:\n" . $signupUrl . "\n";
    $headers = 'From: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
    try {
        @mail($to, $subject, $body, $headers);
    } catch (Throwable $e) {
        error_log('REFERRAL_SEND_INVITE_EMAIL to=' . $to . ' ' . $e->getMessage());
    }
}

/**
 * Mark referral as signed_up when the referred email completes registration.
 */
function referral_mark_signed_up(int $referrerRestaurantId, string $referredEmail): void
{
    if (!referral_table_exists()) {
        return;
    }
    $referredEmail = trim(strtolower($referredEmail));
    if ($referredEmail === '') {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare("
        UPDATE referrals
        SET status = ?
        WHERE referrer_restaurant_id = ? AND LOWER(TRIM(referred_email)) = ? AND status = ?
    ");
    $stmt->execute([REFERRAL_STATUS_SIGNED_UP, $referrerRestaurantId, $referredEmail, REFERRAL_STATUS_INVITED]);
}

/**
 * Mark referral as signed_up by referral code (when user signed up with ?ref=CODE).
 * Call after onboarding_full_register with the new user's email.
 */
function referral_mark_signed_up_by_code(string $code, string $newUserEmail): void
{
    $ref = referral_get_by_code($code);
    if (!$ref) {
        return;
    }
    referral_mark_signed_up($ref['referrer_restaurant_id'], $newUserEmail);
}

/**
 * Count invited restaurants (rows in referrals for this restaurant).
 */
function referral_count_invited(int $restaurantId): int
{
    if (!referral_table_exists()) {
        return 0;
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_restaurant_id = ?");
    $stmt->execute([$restaurantId]);
    return (int)$stmt->fetchColumn();
}
