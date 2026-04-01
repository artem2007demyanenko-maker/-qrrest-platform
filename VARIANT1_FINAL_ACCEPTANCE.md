# Variant 1 — Final acceptance (Performance / Analytics hardening)

## Checklist 1A–1D

**1A — stats.php**
- [x] Каждая stats_* нормализует restaurantIds через stats__normalize_restaurant_ids; при пустом ids — ранний безопасный return без SQL.
- [x] Полуинтервал по датам: created_at >= startUtc AND created_at < endUtc; нигде нет BETWEEN.
- [x] DATE_ADD(created_at, ...) только в SELECT/GROUP BY (MSK), не в WHERE.
- [x] JOIN в top_items/top_categories/margin: mi.restaurant_id = o.restaurant_id; в A5: mc.restaurant_id = mi.restaurant_id. Утечек между ресторанами нет.
- [x] stats_alerts: все внутренние вызовы и A5 используют нормализованные $ids и stats__placeholders; при ошибке — один error_log и return [] (без падения).

**1B — migrations / indexes**
- [x] orders: idx_orders_base_filter (restaurant_id, payment_status, order_status, created_at).
- [x] order_items: idx_order_items_order_menu (order_id, menu_item_id).
- [x] menu_items: idx_menu_items_restaurant_category (restaurant_id, category_id).
- [x] menu_categories: idx_menu_categories_restaurant (restaurant_id).
- [x] Миграции идемпотентны (INFORMATION_SCHEMA + PREPARE).

**1C — stats_cache.php**
- [x] stats_cache_get: тип placeholder и payload с data === null не считаются hit (возврат null).
- [x] acquire_lock: при занятой записи overwrite разрешён при locked_until < UTC или expires_at <= UTC (lock не вечный).
- [x] cleanup: удаляются только записи с expires_at <= UTC и (locked_until IS NULL OR locked_until <= UTC) — активный lock не трогаем.
- [x] payload: лимит 1MB по длине JSON envelope; при ошибке json_encode envelope_payload возвращает '', set ничего не пишет.
- [x] purge_scope: батчами по 1000.

**1D — owner/dashboard.php**
- [x] remember(): не кэширует исключения, невалидные структуры (per-name whitelist), не считает hit при placeholder/null payload.
- [x] Пустой scopeIds: переменные инициализированы (revenueMap, alerts, scopeHash, prevStart, prevEnd и т.д.); тяжёлый блок не выполняется.
- [x] Rate limit: история ограничена, session_write_close до долгих операций.
- [x] purge_cache: только при debug=1 и с CSRF; debug только для global_role=owner.
- [x] Экспорт отчёта: ссылки используют нормализованные range и rest_id (selectedRest), не сырой $_GET.
- [x] Редиректы: везде safe_redirect.

**Selftest / selfcheck**
- [x] Selftest: структура { name, passed, message? }; без записей в БД; проверки пустой scope, limit 0/3, невалидные даты, range=all, консистентность (revenue/orders >= 0, share_pct 0..100, heatmap sum <= paid).
- [x] debugData.selfcheck: объект ok/fail по ключам; debugData.selfcheck_errors — список ключей с fail; при fail — error_log, страница не падает.

---

## Изменённые файлы (финальный проход)

| Файл | Изменения |
|------|-----------|
| app/stats_cache.php | get: при payload.data === null возвращаем null (не hit). envelope_payload: при ошибке json_encode возвращаем ''. |
| public_html/owner/dashboard.php | Инициализация prevStart, prevEnd до if/else. Экспорт: exportParamsBase только нормализованные range/rest_id. debugData.selfcheck_errors — массив ключей с fail. |

Остальная логика Variant 1 (stats.php helpers, base subquery, remember validators, selftest) уже была введена ранее и не менялась в этом проходе.

---

## Индексы и миграция

Применить оба скрипта (сначала 2026_03_02, затем 2026_03_03):

```bash
mysql -u USER -p DATABASE < app/migrations/2026_03_02_add_indexes.sql
mysql -u USER -p DATABASE < app/migrations/2026_03_03_add_perf_indexes.sql
```

Docker:

```bash
docker exec -i CONTAINER mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_02_add_indexes.sql
docker exec -i CONTAINER mysql -u qr -pqrpass qr_rest < app/migrations/2026_03_03_add_perf_indexes.sql
```

Оба скрипта идемпотентны.

---

## Smoke URL

- `/owner/dashboard.php?range=7d&rest_id=all` — без фаталов, все рестораны.
- `/owner/dashboard.php?range=7d&rest_id=all&debug=1` — (owner) в блоке debug: selftest, selfcheck, selfcheck_errors; кэш hit/miss; ошибки не кэшируются.
- `/owner/dashboard.php?range=7d&rest_id=1&debug=1` — один ресторан, без утечки scope.
- Пустой scope (нет доступных ресторанов): страница открывается, переменные заданы, тяжёлый блок не выполняется.

---

## Интерпретация debug и selftest

**debug=1 (только owner)**  
Внизу страницы выводится JSON-блок debugData.

- **selftest** — массив `{ name, passed, message? }`. Все проверки должны быть passed: true. При passed: false смотрите message (например heatmap_sum vs paid).
- **selfcheck** — объект по ключам (top_items, top_categories, summary_current, conversion, heatmap, margin). Значения `ok` или `fail`. При fail соответствующий error_log уже записан.
- **selfcheck_errors** — массив имён ключей, по которым selfcheck дал fail. Пустой массив — всё ok.
- **cache** — по каждой stats-функции: hit, key, ttl, age_sec, expires_in_sec. hit: true только при реальном payload (не placeholder).

**Проверка приёмки**  
Страница без фаталов; selftest все passed; selfcheck_errors пустой; при повторном заходе без nocache часть cache[].hit = true.
