# OWNER_STABILITY_CHECKLIST

Ручной smoke для owner-панели после деплоя QR Restaurant SaaS.

Покрывает:

- `public_html/restaurant/dashboard.php`
- `public_html/restaurant/crm.php`
- `public_html/restaurant/loyalty_settings.php`
- `public_html/restaurant/crm_campaigns.php`
- `public_html/restaurant/revenue.php`

---

## 0) Preconditions и safety

- Запускать **сначала на staging**. На production — только в согласованное окно.
- Перед SQL-симуляцией сделать бэкап БД.
- Работать под пользователем MySQL с правами `RENAME TABLE`.
- Для входа в owner UI использовать тестовую owner/admin учётку.

Пример подключения к MySQL:

```bash
mysql -h 127.0.0.1 -P 3306 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME"
```

Быстрый snapshot таблиц (до теста):

```sql
SHOW TABLES LIKE 'orders';
SHOW TABLES LIKE 'order_items';
SHOW TABLES LIKE 'menu_items';
SHOW TABLES LIKE 'guests';
SHOW TABLES LIKE 'loyalty_transactions';
SHOW TABLES LIKE 'usage_metrics_daily';
SHOW TABLES LIKE 'crm_campaigns';
SHOW TABLES LIKE 'crm_visits';
SHOW TABLES LIKE 'crm_templates';
SHOW TABLES LIKE 'restaurant_loyalty_settings';
```

Логи (пример для Docker):

```bash
docker logs --tail 200 qr-rest_app_1
```

---

## 1) Dashboard (`dashboard.php`)

### 1.1 Симуляция missing tables

```sql
RENAME TABLE orders TO orders_backup_owner_smoke;
RENAME TABLE order_items TO order_items_backup_owner_smoke;
RENAME TABLE menu_items TO menu_items_backup_owner_smoke;
RENAME TABLE guests TO guests_backup_owner_smoke;
RENAME TABLE loyalty_transactions TO loyalty_transactions_backup_owner_smoke;
RENAME TABLE usage_metrics_daily TO usage_metrics_daily_backup_owner_smoke;
```

### 1.2 Browser check

Открыть:

- `https://<domain>/restaurant/dashboard.php`

Ожидание:

- Виден warning-блок о missing schema.
- KPI/карточки/графики рендерятся, значения fallback (0/empty), без 500/fatal.
- Страница интерактивна (скролл, навигация, mobile меню).

### 1.3 Проверка логов

Проверить наличие записей вида:

- `DASHBOARD_SCHEMA_MISSING table=...`

### 1.4 Восстановление

```sql
RENAME TABLE orders_backup_owner_smoke TO orders;
RENAME TABLE order_items_backup_owner_smoke TO order_items;
RENAME TABLE menu_items_backup_owner_smoke TO menu_items;
RENAME TABLE guests_backup_owner_smoke TO guests;
RENAME TABLE loyalty_transactions_backup_owner_smoke TO loyalty_transactions;
RENAME TABLE usage_metrics_daily_backup_owner_smoke TO usage_metrics_daily;
```

---

## 2) CRM (`crm.php`)

### 2.1 Симуляция missing tables

```sql
RENAME TABLE guests TO guests_backup_owner_smoke;
RENAME TABLE crm_campaigns TO crm_campaigns_backup_owner_smoke;
RENAME TABLE crm_visits TO crm_visits_backup_owner_smoke;
```

### 2.2 Browser check

Открыть:

- `https://<domain>/restaurant/crm.php`

Ожидание:

- Warning UI о partial schema.
- Read-only режим: списки/блоки деградируют в пустые структуры без падений.
- POST-действия (кнопки запуска/сохранения) блокируются понятным сообщением.

### 2.3 POST check (должен быть blocked)

В браузере нажать любой action-контрол в CRM (например создание черновика/кампании).

Ожидание:

- Сообщение о read-only/schema missing.
- Нет 500, нет белого экрана.

### 2.4 Логи

Проверить:

- `CRM_SCHEMA_MISSING table=...`

### 2.5 Восстановление

```sql
RENAME TABLE guests_backup_owner_smoke TO guests;
RENAME TABLE crm_campaigns_backup_owner_smoke TO crm_campaigns;
RENAME TABLE crm_visits_backup_owner_smoke TO crm_visits;
```

---

## 3) Loyalty settings (`loyalty_settings.php`)

### 3.1 Симуляция missing table

```sql
RENAME TABLE restaurant_loyalty_settings TO restaurant_loyalty_settings_backup_owner_smoke;
```

### 3.2 Browser check

Открыть:

- `https://<domain>/restaurant/loyalty_settings.php`

Ожидание:

- Warning о missing `restaurant_loyalty_settings`.
- Поля формы disabled/readonly.
- Дефолтные значения отображаются корректно.
- Страница не падает.

### 3.3 POST check

Попробовать нажать "Сохранить".

Ожидание:

- Запись блокируется (конфликт/ошибка с понятным текстом).

### 3.4 Восстановление

```sql
RENAME TABLE restaurant_loyalty_settings_backup_owner_smoke TO restaurant_loyalty_settings;
```

---

## 4) CRM Campaigns (`crm_campaigns.php`)

### 4.1 Симуляция missing tables

```sql
RENAME TABLE crm_templates TO crm_templates_backup_owner_smoke;
RENAME TABLE crm_campaigns TO crm_campaigns_backup_owner_smoke;
```

### 4.2 Browser check

Открыть:

- `https://<domain>/restaurant/crm_campaigns.php`

Ожидание:

- Warning UI о schema missing.
- Формы (add template/add campaign) disabled/readonly.
- Списки шаблонов/кампаний пустые, но UI цел.

### 4.3 POST check

Попробовать отправить любую форму.

Ожидание:

- Понятная ошибка про неготовую схему, без 500.

### 4.4 Логи

Проверить:

- `CRM_CAMPAIGNS_SCHEMA_MISSING table=...`

### 4.5 Восстановление

```sql
RENAME TABLE crm_templates_backup_owner_smoke TO crm_templates;
RENAME TABLE crm_campaigns_backup_owner_smoke TO crm_campaigns;
```

---

## 5) Revenue (`revenue.php`)

### 5.1 Симуляция missing tables

```sql
RENAME TABLE orders TO orders_backup_owner_smoke;
RENAME TABLE order_items TO order_items_backup_owner_smoke;
RENAME TABLE menu_items TO menu_items_backup_owner_smoke;
```

### 5.2 Browser check

Открыть:

- `https://<domain>/restaurant/revenue.php`

Ожидание:

- Warning UI про missing tables.
- KPI переходят в нули/fallback.
- Графики/блоки рендерятся стабильно (без JS/PHP ошибок).

### 5.3 Логи

Проверить:

- `REVENUE_SCHEMA_MISSING table=...`

### 5.4 Восстановление

```sql
RENAME TABLE orders_backup_owner_smoke TO orders;
RENAME TABLE order_items_backup_owner_smoke TO order_items;
RENAME TABLE menu_items_backup_owner_smoke TO menu_items;
```

---

## 6) Mobile UX smoke (все owner страницы)

Страницы:

- `/restaurant/dashboard.php`
- `/restaurant/crm.php`
- `/restaurant/loyalty_settings.php`
- `/restaurant/crm_campaigns.php`
- `/restaurant/revenue.php`

Проверить на width ~390px (или реальном телефоне):

- Боковое mobile-меню открывается/закрывается.
- Fixed/FAB/warning-слои не перекрывают критичные кнопки.
- Вертикальный скролл стабильный, без “залипания”.
- Нет горизонтального overflow.

---

## 7) Curl sanity checks (после rollback)

Проверка, что страницы снова отдают 200:

```bash
curl -sI "https://<domain>/restaurant/dashboard.php" | head -n 1
curl -sI "https://<domain>/restaurant/crm.php" | head -n 1
curl -sI "https://<domain>/restaurant/loyalty_settings.php" | head -n 1
curl -sI "https://<domain>/restaurant/crm_campaigns.php" | head -n 1
curl -sI "https://<domain>/restaurant/revenue.php" | head -n 1
```

Проверка health:

```bash
curl -sS "https://<domain>/health.php" | jq .
```

---

## 8) Final checklist (pass/fail)

- [ ] Все временно переименованные таблицы восстановлены.
- [ ] На partial schema нет fatal/500 на owner страницах.
- [ ] Read-only режимы и warning UI корректны.
- [ ] POST блокируется там, где схема неполная.
- [ ] Mobile UX стабилен (меню, overlay, scroll).
- [ ] Логи содержат ожидаемые `*_SCHEMA_MISSING` записи.
- [ ] После rollback owner страницы снова показывают рабочие данные.

