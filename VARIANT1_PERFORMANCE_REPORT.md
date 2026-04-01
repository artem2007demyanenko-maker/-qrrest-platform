# Variant 1 — Performance wins (owner dashboard)

## 1. Список изменённых файлов

| Файл | Изменения |
|------|-----------|
| **app/stats.php** | Добавлен helper `stats_orders_base_sql()`; refactor `stats_top_items`, `stats_top_categories`, `stats_margin` на base subquery. |
| **app/migrations/2026_03_03_add_perf_indexes.sql** | Новая идемпотентная миграция: индексы для order_items, menu_items, menu_categories. |
| **public_html/owner/dashboard.php** | remember(): один раз strtotime при hit; при пустом scopeIds нет вызовов alerts_repo; self-check в debug (top_items/top_categories). |

---

## 2. Итоговый SQL по функциям

### stats_orders_base_sql (helper)

Возвращает подзапрос (без параметров — они в вызывающем коде):

```sql
( SELECT o.id, o.restaurant_id
  FROM orders o
  WHERE o.restaurant_id IN ($placeholders)
    AND o.payment_status = 'paid'
    AND o.order_status <> 'canceled'
    $whereDate
) o
```

где `$whereDate` — пустая строка или ` AND o.created_at >= ? AND o.created_at < ? ` (UTC, без DATE_ADD в WHERE).

---

### stats_top_items

```sql
SELECT
    mi.name AS name,
    COALESCE(SUM(oi.price * oi.quantity), 0) AS revenue,
    COALESCE(SUM(oi.quantity), 0) AS qty
FROM ( SELECT o.id, o.restaurant_id
       FROM orders o
       WHERE o.restaurant_id IN (?,?,...)
         AND o.payment_status = 'paid'
         AND o.order_status <> 'canceled'
         AND o.created_at >= ? AND o.created_at < ?
     ) o
JOIN order_items oi ON oi.order_id = o.id
JOIN menu_items mi  ON mi.id = oi.menu_item_id AND mi.restaurant_id = o.restaurant_id
GROUP BY mi.id, mi.name
ORDER BY revenue DESC
LIMIT ?
```

---

### stats_top_categories

```sql
SELECT
    mc.name AS name,
    COALESCE(SUM(oi.price * oi.quantity), 0) AS revenue,
    COALESCE(SUM(oi.quantity), 0) AS qty
FROM ( SELECT o.id, o.restaurant_id
       FROM orders o
       WHERE o.restaurant_id IN (?,?,...)
         AND o.payment_status = 'paid'
         AND o.order_status <> 'canceled'
         AND o.created_at >= ? AND o.created_at < ?
     ) o
JOIN order_items oi   ON oi.order_id = o.id
JOIN menu_items mi    ON mi.id = oi.menu_item_id AND mi.restaurant_id = o.restaurant_id
JOIN menu_categories mc ON mc.id = mi.category_id AND mc.restaurant_id = mi.restaurant_id
GROUP BY mc.id, mc.name
ORDER BY revenue DESC
LIMIT ?
```

---

### stats_margin (revenue)

```sql
SELECT COALESCE(SUM(oi.price * oi.quantity), 0) AS revenue
FROM ( SELECT o.id, o.restaurant_id
       FROM orders o
       WHERE o.restaurant_id IN (?,?,...)
         AND o.payment_status = 'paid'
         AND o.order_status <> 'canceled'
         AND o.created_at >= ? AND o.created_at < ?
     ) o
JOIN order_items oi ON oi.order_id = o.id
```

### stats_margin (profit)

```sql
SELECT COALESCE(SUM((oi.price - COALESCE(mi.cost_price,0)) * oi.quantity), 0) AS profit
FROM ( ... base subquery ... ) o
JOIN order_items oi ON oi.order_id = o.id
JOIN menu_items mi  ON mi.id = oi.menu_item_id AND mi.restaurant_id = o.restaurant_id
```

---

### stats_top_share

Без своего SQL: вызывает `stats_revenue_summary()` и `stats_top_items()` — оба уже используют фильтр по заказам; top_items теперь через base subquery, эквивалентность сохранена. Early-return при пустом `restaurantIds` уже был.

### stats_conversion / stats_revenue_summary

Без изменений: работают по `orders` с `created_at >= ? AND created_at < ?` (без функций по полю в WHERE), индексы уже покрывают запросы.

---

## 3. Миграция индексов

Файл: **app/migrations/2026_03_03_add_perf_indexes.sql**

- **order_items**: `idx_order_items_order_menu` (order_id, menu_item_id).
- **menu_items**: `idx_menu_items_restaurant_category` (restaurant_id, category_id).
- **menu_categories**: `idx_menu_categories_restaurant` (restaurant_id).

Проверка через INFORMATION_SCHEMA; при наличии индекса выполняется `SELECT '... exists'`. Повторный запуск безопасен.

---

## 4. Команда для применения миграции в Docker

Если MySQL в контейнере называется `db` (или как в вашем docker-compose):

```bash
docker exec -i db mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_03_add_perf_indexes.sql
```

Если контейнер имеет другое имя (например `mysql`):

```bash
docker exec -i mysql mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_03_add_perf_indexes.sql
```

Или из корня проекта, если путь к миграции задаётся относительно репозитория:

```bash
docker exec -i $(docker ps -qf 'ancestor=mysql' | head -1) mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_03_add_perf_indexes.sql
```

Рекомендуется подставить фактическое имя сервиса/контейнера БД и учётные данные из config.

---

## 5. Критерии приёмки

- Страницы открываются как раньше: `?range=7d&rest_id=all`, `?range=7d&rest_id=1`, `?debug=1`.
- Метрики и алерты по смыслу не изменились (те же агрегаты, paid/non-canceled, MSK→UTC в stats.php).
- top_items, top_categories, margin используют base subquery по orders (paid/non-canceled/period) до join с order_items/menu_items.
- Добавлена идемпотентная миграция 2026_03_03_add_perf_indexes.sql.
- В WHERE нет DATE_ADD(created_at ...); multi-tenant join по restaurant_id сохранён.
- При пустом scopeIds нет вызовов stats_* (блок не выполняется) и alerts_repo (resolve_missing / get_for_scope только при !empty($scopeIds)).
- Debug self-check только при debug=1, без фаталов (error_log при некорректной структуре top_items/top_categories).
