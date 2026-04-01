# Variant 4 — Billing / Subscriptions

## Что добавлено

- Минимально жизнеспособная система тарифов и подписок: планы (free/starter/pro), подписка на владельца, счета и платежи (MVP без внешних платёжных API).
- Ограничение по лимиту ресторанов при создании ресторана в project-admin (проверка тарифа выбранного владельца).
- Страница «Тарифы и подписка» в личном кабинете владельца: текущий тариф, смена плана (POST + CSRF), отмена подписки, история счетов/платежей.
- В dashboard в сайдбаре добавлен индикатор тарифа и ссылка на /owner/billing.php.

## Таблицы и поля

**plans**
- id (PK), code (UNIQUE), name, description, price_month, currency, limits_json (JSON), is_active, sort_order, created_at, updated_at.
- Seed: free (1 ресторан), starter (3, 990 ₽/мес), pro (999, 2990 ₽/мес).

**subscriptions**
- id (PK), user_id (FK users), plan_id (FK plans), status (active/trial/past_due/canceled), current_period_start, current_period_end, cancel_at_period_end, canceled_at, meta_json, created_at, updated_at.
- Индексы: user_id, status, current_period_end, (user_id, status).

**invoices**
- id (PK), user_id (FK users), subscription_id (FK subscriptions, NULL), amount, currency, status (draft/issued/paid/void/refunded), period_start, period_end, provider, provider_invoice_id, payload_json, issued_at, paid_at, created_at.
- Индексы: (user_id, created_at), subscription_id, status.

**payments**
- id (PK), user_id (FK users), invoice_id (FK invoices, NULL), amount, currency, status (created/succeeded/failed/refunded), provider (default manual), provider_payment_id, error_text, created_at, updated_at.
- Индексы: (user_id, created_at), invoice_id, status.

Связь владельца с ресторанами: через users_restaurants (user_id, restaurant_id, restaurant_role = 'owner'). Лимит restaurants_max из плана сравнивается с количеством записей users_restaurants с restaurant_role = 'owner'.

## Изменённые и новые файлы

| Файл | Действие |
|------|----------|
| app/migrations/2026_03_04_billing.sql | Новый: CREATE TABLE plans, subscriptions, invoices, payments; seed планов. |
| app/billing.php | Новый: billing_get_plans, billing_get_subscription, billing_ensure_default_subscription, billing_can_create_restaurant, billing_change_plan, billing_cancel_subscription, billing_renew_if_needed_cronlike, billing_get_history. |
| public_html/owner/billing.php | Новый: страница тарифов, текущая подписка, выбор плана (POST choose_plan + CSRF), отмена (cancel_at_end / cancel_now), история, debug-блок при debug=1 и owner. |
| public_html/owner/dashboard.php | Подключён billing.php, в сайдбаре индикатор «Тариф: {plan} • до {date}» и ссылка на /owner/billing.php. |
| public_html/project-admin/restaurants.php | Подключён billing.php; перед созданием ресторана вызов billing_can_create_restaurant(ownerId); при превышении лимита — сообщение и отмена INSERT. |

## Команды миграции

Применить миграцию (после 2026_03_02 и 2026_03_03, т.к. есть FK на users):

```bash
mysql -u USER -p DATABASE < app/migrations/2026_03_04_billing.sql
```

Docker (подставьте имя контейнера и учётные данные):

```bash
docker exec -i CONTAINER mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_04_billing.sql
```

Миграция идемпотентна: CREATE TABLE IF NOT EXISTS, seed планов через INSERT ... ON DUPLICATE KEY UPDATE по полю code.

## Smoke URL и сценарии

1. **/owner/billing.php** — открывается под owner/project_owner, отображаются текущий тариф и карточки планов (free/starter/pro). При первом заходе создаётся подписка free (billing_ensure_default_subscription).
2. **POST choose_plan** — выбор плана (plan_code + csrf). Тариф меняется, при платном плане создаются invoice (issued → paid) и payment (succeeded). Редирект на /owner/billing.php, flash «Тариф изменён на …».
3. **Отмена в конце периода** — cancel_at_period_end = 1, сообщение «Подписка будет отменена в конце периода».
4. **Отмена сейчас** — status = canceled, canceled_at = now.
5. **Dashboard** — в сайдбаре строка «Тариф: Бесплатный • до …» (или Pro/Старт) и ссылка на /owner/billing.php.
6. **Создание ресторана сверх лимита** — в project-admin при выборе владельца с тарифом free и уже одним рестораном попытка создать второй ресторан даёт ошибку «Достигнут лимит ресторанов по тарифу…» и INSERT не выполняется.

## Ручное тестирование (free → pro → cancel)

1. Войти как owner.
2. Открыть /owner/billing.php — должен быть тариф «Бесплатный», период до даты через 100 лет.
3. Нажать «Выбрать» у тарифа «Про» — редирект, сообщение об успехе, в блоке «Текущий тариф» — «Про», до даты через 30 дней. В истории — один счёт (paid) и один платёж (succeeded).
4. Нажать «Отменить в конце периода» — сообщение, в блоке текущего тарифа — «Подписка будет отменена в конце периода».
5. Нажать «Отменить сейчас» — подписка отменена, можно снова выбрать тариф (например free).
6. В dashboard проверить индикатор тарифа и переход по ссылке на billing.
7. Под владельцем с тарифом free создать один ресторан в project-admin. Второй ресторан с тем же владельцем — ошибка лимита, ресторан не создаётся.

## Безопасность и ограничения

- Все действия по смене тарифа и отмене — только POST с проверкой CSRF.
- Редиректы на billing — через safe_redirect.
- Подписка и лимиты привязаны к user_id (владелец); смена плана только для текущего пользователя (owner/project_owner).
- Лимит ресторанов проверяется по users_restaurants (restaurant_role = 'owner'), не по restaurants.owner_user_id.
- Внешние платёжные API не подключаются; провайдер платежей — manual, оплата в MVP не списывается.

## Cron (опционально)

Функция billing_renew_if_needed_cronlike() продлевает активные подписки с истёкшим current_period_end на 30 дней и создаёт invoice/payment. Вызов из cron или планировщика не добавлен — при необходимости можно вызывать её по расписанию (например раз в час).
