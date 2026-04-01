# Variant 6 — Growth Features

Слой роста (acquisition, retention, monetization uplift) с сохранением multi-tenant безопасности, идемпотентности и совместимости с billing и analytics.

---

## Новые таблицы (миграция 2026_03_06_growth.sql)

| Таблица | Назначение |
|--------|------------|
| **referral_codes** | Реферальные коды владельцев: user_id, code (UNIQUE), clicks_count, conversions_count, reward_type, reward_value, is_active |
| **referral_clicks** | Клики по реферальной ссылке: referral_code_id, ip_hash, user_agent_hash, created_at. Индекс (referral_code_id, created_at) |
| **referral_conversions** | Конверсии: referral_code_id, new_user_id (UNIQUE), rewarded, created_at |
| **coupons** | Промокоды: code (UNIQUE), type (percent/fixed), value, valid_from, valid_until, usage_limit, used_count, is_active |
| **coupon_usages** | Использования купонов: coupon_id, user_id. UNIQUE(coupon_id, user_id) |
| **usage_metrics_daily** | Дневные метрики по пользователю: user_id, date (UTC), restaurants_count, orders_count, revenue, staff_count, menu_items_count. UNIQUE(user_id, date) |
| **utm_visits** | UTM-визиты по ресторану: restaurant_id, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at |

Дополнительно: в таблицу **invoices** добавлена колонка **coupon_id** (INT UNSIGNED NULL, KEY) для аудита применения купона.

---

## Добавленные файлы

| Файл | Описание |
|------|----------|
| **app/migrations/2026_03_06_growth.sql** | Миграция: referral_codes, referral_clicks, referral_conversions, coupons, coupon_usages, usage_metrics_daily, utm_visits, invoices.coupon_id |
| **app/growth.php** | Логика: referral (ensure code, record click, get by code, create conversion, stats), coupons (validate, apply discount, apply usage), usage (collect, chart), soft limit alerts, UTM record, upsell suggestions |
| **public_html/owner/growth.php** | Страница «Рост и рефералы»: реферальная ссылка и копирование, клики/конверсии, использованные промокоды, метрики за 7/30 дней, рекомендации, debug при debug=1 |

---

## Изменённые файлы

| Файл | Изменения |
|------|-----------|
| **app/billing.php** | `billing_change_plan($userId, $planCode, $couponCode = '')`: валидация купона в транзакции (growth_validate_coupon), скидка через growth_apply_discount, запись coupon_id в invoice, growth_apply_coupon_usage после оплаты. Идемпотентность ключа учитывает coupon_code. |
| **public_html/owner/billing.php** | Поле «Промокод (необязательно)» в форме выбора тарифа; передача coupon_code в billing_change_plan. |
| **public_html/login.php** | Обработка GET ref=CODE: вызов growth_record_click (ip_hash, user_agent_hash), сохранение кода в сессии. |
| **public_html/project-admin/users.php** | Поле «Реферальный код» при создании пользователя; после INSERT вызов growth_create_conversion при наличии кода. |
| **public_html/owner/dashboard.php** | Подключение growth.php; вызов growth_collect_usage_for_user при каждом заходе (внутри — запись раз в день по UTC); объединение growth_soft_limit_alerts с stats_alerts; ссылка «Рост и рефералы» в шапке. |
| **public_html/restaurant_public.php** | Поддержка rest_id на основном домене; запись UTM из GET в сессию и в utm_visits через growth_utm_record; promo=1 — улучшенные title и meta description. |

---

## Безопасность

- **owner/growth.php**: domain guard, require_login, require_role(['owner','project_owner']), редиректы на основной домен через safe_redirect. POST-действий на странице нет; при добавлении — CSRF 32 bytes.
- **billing**: CSRF для choose_plan/cancel; купон проверяется внутри транзакции с FOR UPDATE (race-safe).
- **Multi-tenant**: везде привязка по user_id; реферальные данные только по своему коду; метрики и алерты — по текущему пользователю.
- Все выборки и обновления — через prepared statements. Внешние API не подключаются.

---

## Smoke-сценарии

1. **Регистрация с ref**  
   - Открыть login.php?ref=КОД (где КОД — код из growth у другого владельца).  
   - Ожидание: клик записан (дедуп 24ч по IP), в сессии сохранён referral_code.  
   - В project-admin создать пользователя с полем «Реферальный код» = тот же КОД.  
   - Ожидание: создаётся запись в referral_conversions, conversions_count у кода увеличивается; при настроенном reward — referral_credit в meta_json подписки реферера.

2. **Применение купона**  
   - В БД создать купон (code, type, value, valid_until, usage_limit, is_active=1).  
   - На /owner/billing.php выбрать платный тариф, ввести код купона, отправить форму.  
   - Ожидание: сумма в invoice уменьшена (percent или fixed), в coupon_usages — запись, used_count у купона +1. Повторная отправка с тем же купоном — ошибка «Вы уже использовали этот купон».

3. **Soft limit предупреждение**  
   - У владельца план с restaurants_max=1 и уже 1 ресторан.  
   - Зайти на /owner/dashboard.php.  
   - Ожидание: алерт «Лимит ресторанов достигнут» с CTA на /owner/billing.php. При 80% лимита — предупреждение «Приближение к лимиту».

4. **Запись usage metrics**  
   - Владелец с ресторанами заходит на /owner/dashboard.php.  
   - Ожидание: в usage_metrics_daily появляется/обновляется строка на сегодня (UTC): restaurants_count, orders_count, revenue, staff_count, menu_items_count. Повторный заход в тот же день — обновление той же строки (ON DUPLICATE KEY UPDATE).

5. **Growth dashboard**  
   - Владелец открывает /owner/growth.php.  
   - Ожидание: отображаются реферальная ссылка и кнопка «Копировать», счётчики кликов и регистраций, блок использованных промокодов, таблица метрик за 7/30 дней, блок рекомендаций. При debug=1 (и роль owner) — debug-блок с сырыми данными.

6. **Публичная страница и UTM**  
   - Открыть на основном домене: /restaurant_public.php?rest_id=1&promo=1&utm_source=telegram&utm_campaign=spring.  
   - Ожидание: загружается ресторан с id=1, в utm_visits — запись с restaurant_id=1, utm_source, utm_campaign; в сессии — utm. Title/description страницы — в формате «Название — Меню и заказ онлайн» и описание для SEO.

---

## Команды миграции

```bash
# После применения миграций billing (2026_03_04, 2026_03_05)
mysql -u USER -p DATABASE < app/migrations/2026_03_06_growth.sql
```

Или через свой скрипт применения миграций по порядку имён файлов.

---

## Краткое резюме

**Variant 6 ready.** Реализованы: реферальная система (клики, конверсии, награда в meta_json), промокоды (привязка к смене плана, race-safe, идемпотентность), дневные метрики использования (один раз в день при заходе на dashboard), алерты по лимитам плана (80%/100%) в общем потоке алертов, публичная страница ресторана по rest_id с UTM и promo-SEO, страница «Рост и рефералы» с копированием ссылки, метриками и рекомендациями. Существующие URL и UI не сломаны; billing и analytics не изменены по смыслу.
