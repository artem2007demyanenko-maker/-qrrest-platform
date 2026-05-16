<?php
/**
 * SMS delivery for guest OTP. Replace send_sms() body with SMS.ru / Twilio / etc.
 */

if (!function_exists('guest_sms_can_fake_deliver')) {
    function guest_sms_can_fake_deliver(): bool {
        $path = __DIR__ . '/config.php';
        $cfg = is_file($path) ? require $path : [];
        $appEnv = strtolower((string)($cfg['app']['env'] ?? 'local'));
        return $appEnv !== 'production';
    }
}

if (!function_exists('guest_sms_provider_configured')) {
    function guest_sms_provider_configured(): bool {
        return false;
    }
}

if (!function_exists('send_sms')) {
    function send_sms(string $phone, string $message): bool {
        if (!guest_sms_can_fake_deliver()) {
            if (function_exists('error_log')) {
                error_log('SMS_PROVIDER_UNCONFIGURED phone=' . $phone);
            }
            return false;
        }
        if (function_exists('error_log')) {
            error_log('SMS DEV/STUB to ' . $phone . ': ' . $message);
        }
        return true;
    }
}
