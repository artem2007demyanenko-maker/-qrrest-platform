# Variant 7 — Stability & Anti-Abuse Hardening

Enterprise stability baseline: rate limiting, referral/coupon abuse protection, billing consistency, audit log, error handling, health endpoint, abuse signals, backup.

---

## Новые таблицы

| Таблица | Назначение |
|--------|------------|
| **security_rate_limits** | Rate limit fallback (key_hash, hits, window_start, updated_at). Используется при отсутствии Redis. |
| **audit_logs** | user_id, action, entity_type, entity_id, ip_hash, created_at. Логирование plan_change, coupon_usage, referral_reward, restaurant_create, login_success. |
| **abuse_signals** | user_id, signal_type, score, meta_json, created_at. Сигналы: referral_self_signup, too_many_coupon_attempts, rate_limit_triggered. |

## Изменения в существующих таблицах

| Таблица | Изменения |
|--------|-----------|
| **referral_clicks** | day_bucket DATE, UNIQUE(referral_code_id, ip_hash, day_bucket). Дедуп один клик в день на IP. |
| **coupons** | max_per_user INT DEFAULT 1, min_plan_price DECIMAL NULL, first_time_only TINYINT DEFAULT 0. |
| **payments** | provider_ref VARCHAR(128) NULL UNIQUE. |
| **restaurants** | deleted_at DATETIME NULL (soft delete; 404 при не NULL). |

---

## Новые файлы

| Файл | Назначение |
|------|------------|
| **app/security.php** | security_rate_limit($key, $limit, $window_sec), security_hash_ip, security_hash_ua. Redis или MySQL security_rate_limits. При превышении — HTTP 429 + sleep(0.3–0.8s) + abuse_signal. |
| **app/error_handler.php** | stability_exception_handler, stability_error_handler. error_log JSON (timestamp, user_id, uri, error_type, message). Пользователю — HTTP 500 "Internal error". |
| **app/audit.php** | audit_log($action, $entity_type, $entity_id), abuse_signal($userId, $signalType, $score, $meta). |
| **app/migrations/2026_03_07_stability.sql** | security_rate_limits, referral_clicks.day_bucket + UNIQUE, coupons columns, payments.provider_ref, audit_logs, abuse_signals, restaurants.deleted_at. |
| **public_html/health.php** | GET /health.php — проверка DB, опционально migrations table. Ответ JSON: status, db, time. HTTP 200. |
| **scripts/db_backup.sh** | mysqldump ключевых таблиц с timestamp. |
| **BACKUP_RECOVERY.md** | Инструкции по бэкапу и восстановлению. |

---

## Изменённые файлы

| Файл | Изменения |
|------|-----------|
| **app/bootstrap.php** | Подключение security.php, error_handler.php. set_exception_handler, set_error_handler. X-Frame-Options: DENY. |
| **app/growth.php** | growth_base62_code(10), referral code min 10 символов base62. referral_clicks: INSERT с day_bucket, ловля duplicate key. growth_create_conversion: проверки referrerId != newUserId, клик до регистрации; rewarded=0, награда в транзакции с FOR UPDATE; audit_log referral_reward. growth_validate_coupon: max_per_user, first_time_only, min_plan_price, $planPrice. |
| **app/billing.php** | growth_validate_coupon(..., $planPrice). Rate limit coupon_usage 5/hour/user. INSERT payments с provider_ref. audit_log plan_change, coupon_usage. |
| **app/subdomain.php** | Условие deleted_at IS NULL при выборе ресторана по subdomain. |
| **public_html/login.php** | Rate limit login 10/min/IP, referral_click 20/hour/IP. audit_log login_success. |
| **public_html/restaurant_public.php** | Rate limit 200/hour/IP. rest_id: проверка deleted_at IS NULL. Санитизация UTM: max 100 символов, только a-zA-Z0-9_. |
| **public_html/project-admin/restaurants.php** | audit_log restaurant_create после создания ресторана. |

---

## Rate limits

| Ключ | Лимит | Окно |
|------|--------|------|
| login | 10 | 1 мин (per IP) |
| referral_click | 20 | 1 час (per IP) |
| coupon_usage | 5 | 1 час (per user) |
| restaurant_public | 200 | 1 час (per IP) |

При превышении: HTTP 429, Retry-After: 60, sleep(0.3–0.8s), запись в abuse_signals (rate_limit_triggered).

---

## Abuse protection

- **Referral:** код base62 мин. 10 символов; дедуп кликов по (code, ip_hash, day_bucket); конверсия только если new_user_id != referrer, был клик до регистрации; награда только при rewarded=0 в транзакции; referral_self_signup → abuse_signal.
- **Coupon:** проверки usage_limit, max_per_user, first_time_only (нет предыдущих invoices), invoice.amount >= min_plan_price; все под SELECT FOR UPDATE.
- **Billing:** idempotency_key (уже было), provider_ref на payments; повторный POST с тем же ключом не создаёт новые invoice/payment.

---

## Smoke-сценарии

1. **Referral spam**  
   > 20 запросов login.php?ref=CODE с одного IP за час.  
   Ожидание: после 20-го — HTTP 429, в abuse_signals запись rate_limit_triggered.

2. **Coupon race**  
   Два параллельных POST choose_plan с одним и тем же купоном для одного user.  
   Ожидание: один успех, второй — ошибка (уже использован или rate limit). Нет двух invoice/payment за один план.

3. **Double plan change**  
   Два быстрых POST choose_plan с одним планом и без купона.  
   Ожидание: один успех, второй — тот же успех (идемпотентность по idempotency_key), без дубля invoice/payment.

4. **restaurant_public abuse**  
   > 200 запросов /restaurant_public.php?rest_id=1 с одного IP за час.  
   Ожидание: после 200 — HTTP 429.

5. **Health endpoint**  
   GET /health.php.  
   Ожидание: HTTP 200, JSON с status "ok", db "ok", time (ISO).

---

## Команды миграции

```bash
mysql -u USER -p DATABASE < app/migrations/2026_03_07_stability.sql
```

## Backup

```bash
export MYSQL_USER=qr MYSQL_PASS=pass MYSQL_DB=qr_rest
./scripts/db_backup.sh
```

---

Итог: rate limiting (Redis/MySQL), защита рефералов и купонов, консистентность billing, глобальный error handler, audit_log и abuse_signals, health, бэкапы и документация по восстановлению. Backwards-compatible, текущий функционал не ломается.
