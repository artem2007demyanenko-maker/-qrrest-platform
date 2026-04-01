# Smoke / Acceptance checklist (Variants 1, 4, 6, 7)

## Variant 1 — Stats / Analytics

- [ ] **Time filter**  
  Dashboard с `?range=7d` / `today` / `30d`: данные за период; границы полуинтервала `[start, end)` в UTC, без дублей на границах.

- [ ] **Base scope**  
  Top items, top categories, margin используют один и тот же базовый scope (оплаченные, не отменённые, restaurant_id IN scope, даты в UTC).

- [ ] **Empty scope**  
  При пустом списке ресторанов (или невалидном rest_id) stats возвращают пустые/нулевые структуры без SQL-ошибок.

- [ ] **Invalid dates**  
  Невалидные даты в запросе не приводят к SQL-ошибке; безопасный fallback.

- [ ] **Cache**  
  При повторном открытии дашборда с тем же scope/range — cache hit (в debug=1 видно cache_hit_rate). Placeholder (payload null) не считается hit. При исключении в callback результат не кэшируется.

- [ ] **Debug (owner)**  
  `?debug=1` только для owner: в выводе есть cache_hit_rate (hits/total/rate), selfcheck_errors, timers.stats_block_sec.

- [ ] **Large scope**  
  Очень большой список ресторанов (>500) мягко обрезается с логом, UI не ломается.

---

## Variant 4 — Billing

- [ ] **Idempotency**  
  Повторный POST choose_plan с теми же данными (user, plan, coupon, период) не создаёт второй invoice/payment; используется существующий invoice (idempotency_key UNIQUE).

- [ ] **Race**  
  Смена плана при существующей подписке: в транзакции выполняется SELECT ... FOR UPDATE по subscription, затем UPDATE.

- [ ] **Limits**  
  Лимит ресторанов считается только по активным (deleted_at IS NULL). billing_can_create_restaurant / billing_assert_can_create_restaurant учитывают только не удалённые рестораны.

- [ ] **provider_ref**  
  Платежи manual имеют стабильный provider_ref вида `manual:inv:{invoice_id}`, UNIQUE в БД.

- [ ] **Audit**  
  В audit_log попадают: plan_change, subscription_cancel (at_period_end / now), coupon_usage, invoice_paid (при manual succeed).

---

## Variant 6 — Growth

- [ ] **Referral click**  
  Клик дедуплицируется по day_bucket (UTC). Код реферала: base62, минимальная длина соблюдена. Конверсия: клик до создания юзера, клик не старше 30 дней; при слишком старом клике — skip с логом.

- [ ] **Coupon**  
  max_per_user, min_plan_price, first_time_only проверяются в коде и в транзакции. Двойное применение купона: INSERT IGNORE в coupon_usages (UNIQUE coupon_id+user_id), used_count увеличивается только при реальной вставке.

- [ ] **UTM**  
  Сохранение только whitelist-ключей (utm_source, utm_medium, utm_campaign, utm_content, utm_term), значения trim + max length, без SQL/HTML-инъекций.

- [ ] **Growth alerts**  
  Алерты по лимитам плана не спамят: учитываются ack/snooze и scope_hash; alert_key стабилен.

---

## Variant 7 — Stability / Anti-abuse

- [ ] **Rate limit**  
  MySQL-режим: атомарный INSERT ... ON DUPLICATE KEY UPDATE по key_hash/window_start. При превышении — HTTP 429, короткое сообщение без деталей; session_write_close() перед ответом 429.

- [ ] **Error handler**  
  Глобальный handler логирует JSON (timestamp, level, message, file, line, uri, user_id). Пользователю не показывается stacktrace. Для Accept: application/json или пути /health.php ответ 500 в JSON.

- [ ] **Soft delete**  
  Рестораны с deleted_at IS NULL не показываются в списках (owner dashboard, project-admin, report, login redirect); по subdomain/rest_id выбор только не удалённых; настройки ресторана по id — 404 для удалённого.

- [ ] **Backup**  
  `scripts/db_backup.sh`: креды из env, пароль не печатается, файл с timestamp, корректный exit code. BACKUP_RECOVERY.md: инструкции verify restore, список таблиц.
