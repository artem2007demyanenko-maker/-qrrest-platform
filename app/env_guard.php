<?php

/**
 * Production environment guard.
 * Fails fast when APP_ENV=production but core env/config is obviously unsafe.
 *
 * Usage: included from bootstrap.php right after loading config.php.
 *
 * Does nothing for non-production environments.
 */

if (!function_exists('env_guard_check')) {
    /**
     * @param array $config app config from app/config.php
     */
    function env_guard_check(array $config): void
    {
        $appEnv = (string)($config['app']['env'] ?? 'local');
        if ($appEnv !== 'production') {
            return;
        }

        $errors = [];

        $mainDomain = trim((string)($config['app']['main_domain'] ?? ''));
        $badDomains = ['lvh.me', 'localhost', '127.0.0.1', 'yourdomain.com', ''];
        if (in_array(strtolower($mainDomain), $badDomains, true)) {
            $errors[] = 'invalid_main_domain';
        }

        $cookieDomain = trim((string)($config['app']['cookie_domain'] ?? ''));
        if ($cookieDomain === '') {
            $errors[] = 'missing_cookie_domain';
        }

        $protocol = strtolower((string)($config['app']['protocol'] ?? ''));
        if ($protocol !== 'https') {
            $errors[] = 'app_protocol_not_https';
        }

        $appUrl = rtrim((string)($config['app']['url'] ?? ''), '/');
        if ($appUrl !== '' && !str_starts_with($appUrl, 'https://')) {
            $errors[] = 'app_url_not_https';
        }

        $dbPass = (string)($config['db']['pass'] ?? '');
        $weakPasswords = ['', 'qrpass', 'change_me', 'password', 'test'];
        if (in_array($dbPass, $weakPasswords, true)) {
            $errors[] = 'weak_db_password';
        }

        $stripe = $config['stripe'] ?? [];
        if (!empty($stripe['enabled'])) {
            if (empty($stripe['secret_key'])) {
                $errors[] = 'stripe_secret_key_missing';
            }
            if (empty($stripe['webhook_secret'])) {
                $errors[] = 'stripe_webhook_secret_missing';
            }
            $priceIds = $stripe['price_ids'] ?? [];
            foreach (['starter', 'basic', 'pro'] as $code) {
                if (empty($priceIds[$code])) {
                    $errors[] = 'stripe_price_id_' . $code . '_missing';
                }
            }
        }

        if (!$errors) {
            return;
        }

        $payload = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'type'      => 'ENV_GUARD_ERROR',
            'app_env'   => $appEnv,
            'errors'    => $errors,
            'host'      => $_SERVER['HTTP_HOST'] ?? null,
        ];
        error_log('ENV_GUARD_ERROR ' . json_encode($payload, JSON_UNESCAPED_UNICODE));

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Environment is not safe for production. See logs for details.\n");
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка конфигурации</title></head><body>'
            . '<p>Приложение временно недоступно. Обратитесь к администратору.</p>'
            . '</body></html>';
        exit;
    }
}

