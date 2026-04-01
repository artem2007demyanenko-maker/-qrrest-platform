# Variant 4 — Billing/Subscriptions (Hardened)

Доведение Variant 4 до production-уровня: единый источник истины для лимита ресторанов, идемпотентность смены плана, транзакции, отмена, UTC, защита от гонок при создании ресторана, безопасность.

---

## Что изменено

### 1. Единый источник истины для лимита ресторанов

- **Лимит `restaurants_max`** считается **только** по `restaurants.owner_user_id` (быстрый `COUNT(*)`).
- **users_restaurants** остаётся для ролей/доступов; лимит на создание ресторана от него **не зависит**.
- **Миграция backfill:** в `2026_03_05_billing_hardened.sql` заполняется `restaurants.owner_user_id` из `users_restaurants` (роль `owner`) только там, где у ресторана **ровно один** владелец. Если владельцев несколько — запись не трогается (неоднозначность); такие кейсы можно выявить запросом из комментария в миграции и залогировать вручную.

### 2. Идемпотентность смены плана (anti double-click)

- Введён **idempotency_key** для инвойса/платежа: `sha256(user_id|plan_code|period_start_utc|price)`.
- Добавлены колонки и индексы:
  - `invoices.idempotency_key` VARCHAR(64) NULL, UNIQUE;
  - `payments.idempotency_key` VARCHAR(64) NULL, UNIQUE.
- При повторном POST choose_plan с тем же ключом **не создаются** дубли: находится существующий invoice по ключу, обновляется только подписка, возвращается успех и редирект.

### 3. Транзакции и консистентность

- **choose_plan** выполняется в **одной** DB-транзакции: обновление/вставка подписки + при платном плане создание invoice и payment с idempotency_key.
- Любая ошибка → rollback, пользователь получает **общее** сообщение об ошибке (без деталей).

### 4. Логика отмены (cancel)

- **cancel_now:** `subscription.status = 'canceled'`, `canceled_at = UTC_TIMESTAMP()`, `cancel_at_period_end = 0`. **current_period_end не меняется.**
- **cancel_at_end:** `status` остаётся `active`, `cancel_at_period_end = 1`, `canceled_at = NULL`. Эффективная отмена — в конце периода.
- При следующей смене плана (`choose_plan`) поля `cancel_at_period_end` и `canceled_at` сбрасываются (уже реализовано в UPDATE подписки).

### 5. Время (UTC)

- Все даты подписок/инвойсов/платежей хранятся в **UTC** (DATETIME). В коде используются `UTC_TIMESTAMP()`, `gmdate('Y-m-d H:i:s')`. Локальная TZ для **хранения** не используется.

### 6. Проверка лимита при создании ресторана (project-admin)

- **billing_can_create_restaurant(ownerId)** считает только `COUNT(*)` по `restaurants.owner_user_id`.
- **billing_assert_can_create_restaurant(ownerId, PDO)** вызывается **внутри транзакции** создателя ресторана: блокирует владельца (`SELECT ... FOR UPDATE` по `users.id`), считает рестораны по `owner_user_id`, при превышении лимита возвращает ошибку. INSERT ресторана выполняется в той же транзакции после успешного assert — параллельные запросы не могут «пробить» лимит.

### 7. Безопасность

- **/owner/billing.php:** require_login, require_role(['owner','project_owner']), доменный guard (редирект на main_domain), все редиректы через **safe_redirect**.
- CSRF: 32 байта (bin2hex(random_bytes(32))), проверка для всех POST-действий (choose_plan, cancel_at_end, cancel_now).

---

## SQL и миграции

### Файлы

- **app/migrations/2026_03_04_billing.sql** — без изменений структуры; в seed планов `updated_at` переведён на `CURRENT_TIMESTAMP` для совместимости.
- **app/migrations/2026_03_05_billing_hardened.sql** — новый файл:
  - Backfill `restaurants.owner_user_id` из `users_restaurants` (один owner на ресторан).
  - `invoices.idempotency_key` VARCHAR(64) NULL, UNIQUE.
  - `payments.idempotency_key` VARCHAR(64) NULL, UNIQUE.

### Индексы

- `uniq_inv_idempotency` на `invoices(idempotency_key)`.
- `uniq_pay_idempotency` на `payments(idempotency_key)`.
- Существующий `idx_restaurants_owner (owner_user_id)` (из 2026_03_04) используется для COUNT при проверке лимита.

### Команды миграции

```bash
# После 2026_03_04_billing.sql
mysql -u USER -p DATABASE < app/migrations/2026_03_05_billing_hardened.sql
```

Или через свой скрипт применения миграций по порядку.

---

## Сценарии (smoke)

| Сценарий | Ожидание |
|----------|----------|
| **free → starter** | Выбор плана Starter: подписка обновляется, при цене > 0 создаётся один invoice и один payment с idempotency_key, редирект с успехом. |
| **starter → pro** | Аналогично: новая подписка (plan_id, period), один новый invoice/payment. |
| **cancel_at_end** | Кнопка «Отменить в конце периода»: `cancel_at_period_end=1`, `canceled_at=NULL`, статус active. В UI — сообщение «Подписка будет отменена в конце периода». |
| **cancel_now** | Кнопка «Отменить сейчас»: `status=canceled`, `canceled_at=UTC`, `current_period_end` без изменений. |
| **Повторный submit choose_plan** | Двойной клик или повторная отправка формы с тем же планом/периодом: второй запрос не создаёт новый invoice/payment, обновляется только подписка, редирект с успехом (идемпотентность). |
| **Создание ресторана при лимите** | У владельца уже N ресторанов, лимит = N: в project-admin при создании ресторана с этим владельцем — ошибка «Достигнут лимит ресторанов…», INSERT не выполняется. |
| **Параллельные запросы на создание ресторана** | Два одновременных запроса создания ресторана с одним владельцем при лимите 1: один успешно создаёт ресторан, второй после блокировки (FOR UPDATE) видит count >= 1 и получает ошибку лимита без INSERT. |

---

## Изменённые файлы (кратко)

| Файл | Изменения |
|------|-----------|
| **app/migrations/2026_03_04_billing.sql** | В seed планов: `updated_at = CURRENT_TIMESTAMP`. |
| **app/migrations/2026_03_05_billing_hardened.sql** | Новый: backfill owner_user_id, idempotency_key у invoices и payments + UNIQUE. |
| **app/billing.php** | Лимит по `restaurants.owner_user_id`; добавлена `billing_assert_can_create_restaurant(ownerId, PDO)`; в `billing_change_plan` — idempotency_key, проверка существующего invoice, один INSERT invoice/payment при отсутствии; cancel_at_end с явным `canceled_at = NULL`. |
| **public_html/project-admin/restaurants.php** | Создание ресторана в транзакции: вызов `billing_assert_can_create_restaurant` перед INSERT; при ошибке — rollback и общее сообщение. |
| **public_html/owner/billing.php** | Без изменений (уже: domain guard, require_login, require_role, safe_redirect, CSRF 32 bytes). |
| **VARIANT4_BILLING_HARDENED.md** | Этот отчёт. |

---

Готово, можно переходить к Variant 6.
