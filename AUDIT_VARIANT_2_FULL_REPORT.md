# Полный аудит Variant 2 (2.3 → 2.7) — отчёт и исправления

## 1. Найденные проблемы

### Phase 1 — Global Architecture

| # | Проблема | Файл | Риск | Статус |
|---|----------|------|------|--------|
| 1.1 | **stats_active_orders**: присвоение по ключу `$st` без проверки — при неожиданном значении из БД возможна запись произвольного ключа в результат | stats.php | Низкий (SQL уже фильтрует IN (...)) | **Исправлено**: только `in_array($st, ['new','accepted','cooking','ready'])` |
| 1.2 | **owner_alerts_upsert**: при одновременном INSERT двух запросов — дубликат ключа и исключение | alerts_repo.php | Средний | **Исправлено**: catch PDOException duplicate → повторный SELECT и путь UPDATE |
| 1.3 | **owner_alerts_log seen_count**: неограниченный рост до overflow INT | alerts_repo.php | Низкий (долгий срок) | **Исправлено**: `LEAST(seen_count + 1, 2147483647)` в UPDATE |
| 1.4 | **dashboard**: при пустом `scopeIds` переменная `$alerts` не инициализирована → возможный notice | dashboard.php | Низкий | **Исправлено**: в ветке else задаётся `$alerts = []` |

Остальное по Phase 1: early return при empty(restaurantIds) во всех stats_* есть; tenant-фильтр по restaurant_id в WHERE присутствует; GROUP BY по нужным полям; division by zero прикрыт проверками ($total > 0 и т.д.); stats_alerts обёрнут в try/catch и возвращает [].

---

### Phase 2 — Cache

| # | Проблема | Файл | Статус |
|---|----------|------|--------|
| 2.1 | **stats_cache_set**: при сбое `json_encode` возвращается `false` — в БД мог уйти невалидный payload | stats_cache.php | **Исправлено**: проверка `$envelope === false || $envelope === ''` и приведение к string перед strlen |
| 2.2 | Placeholder не считается hit, null кэшируется, legacy читается, version mismatch = MISS, locked_until обрабатывается — **проверено, ок** | stats_cache.php | Без изменений |
| 2.3 | purge_scope по user_id + scope_hash — чужие не удаляются — **ок** | — | Без изменений |
| 2.4 | wait_for_value ограничен 600 ms, без бесконечного цикла — **ок** | — | Без изменений |

---

### Phase 3 — Security

| # | Проблема | Файл | Статус |
|---|----------|------|--------|
| 3.1 | **safe_redirect**: не блокировались CRLF/NUL в URL (injection в Location) | helpers.php | **Исправлено**: `strpbrk($url, "\r\n\0")` → redirect to / |
| 3.2 | **safe_redirect**: protocol-relative `//evil.com` пропускался как относительный путь | helpers.php | **Исправлено**: явная проверка `strpos($url, '//') === 0` до проверки относительного пути |
| 3.3 | CSRF: 32 байта, hash_equals, только POST — **ок** | dashboard.php | Без изменений |
| 3.4 | XSS: вывод через e() или (int)/format_money; debug JSON через htmlspecialchars — **ок** | dashboard.php | Без изменений |
| 3.5 | CSP/HSTS — без дублирования, HSTS только при HTTPS — **ок** | bootstrap.php | Без изменений |

---

### Phase 4 — Rate Limit

| # | Проблема | Статус |
|---|----------|--------|
| 4.1 | Рост массива в сессии без ограничения длины | **Исправлено**: при count > 30 делается `array_slice(..., -30, 30)` перед 429 |
| 4.2 | После 429 есть exit — **ок** | Без изменений |

---

### Phase 5 — Failure Isolation

| # | Проблема | Статус |
|---|----------|--------|
| 5.1 | Непойманное исключение в dashboard → white screen | **Исправлено**: `set_exception_handler` в dashboard — логирование + 500 + короткое сообщение без деталей |
| 5.2 | remember() и stats_alerts уже с try/catch и безопасным возвратом — **ок** | Без изменений |

---

### Phase 6 — Edge Cases

| Сценарий | Результат |
|----------|-----------|
| restaurantIds = [] | Во всех stats_* early return с пустым/безопасным результатом |
| periodStart/End = null | stats_period_to_utc_bounds возвращает [null,null]; WHERE по датам не добавляется |
| nocache=1 | bypass кэша, callback выполняется |
| purge одновременно с запросом | purge по user_id/scope_hash; параллельный запрос либо hit, либо miss + compute |
| debug=1 не owner | $debugEnabled = false, блок отладки и purge не показываются |
| memory_limit = -1 | **Исправлено**: парсинг `-1` даёт limitBytes = 0 → memory guard не срабатывает |
| memory_limit = 128MB | **Исправлено**: regex `(\d+)\s*([KMG])?B?` учитывает суффикс B |

---

### Phase 7 — Performance

- Лишних повторных SQL по одному scope не выявлено.
- Двойных вычислений в remember() нет (callback один раз при miss).
- O(n²) в циклах stats не обнаружено.

---

## 2. Что исправлено (кратко)

1. **app/helpers.php** — safe_redirect: блокировка CRLF/NUL, явный запрет protocol-relative `//`.
2. **app/stats_cache.php** — stats_cache_set: проверка на false/пустую строку после json_encode, приведение к string перед strlen.
3. **app/stats.php** — stats_active_orders: запись в результат только для статусов из списка `['new','accepted','cooking','ready']`.
4. **app/alerts_repo.php** — UPDATE с `LEAST(seen_count + 1, 2147483647)`; при duplicate key на INSERT — повторный SELECT и выполнение пути UPDATE.
5. **public_html/owner/dashboard.php** — парсинг memory_limit с учётом `-1` и суффикса `B`; при rate limit > 30 обрезка массива до последних 30; инициализация `$alerts = []` в ветке пустого scopeIds; set_exception_handler для непойманных исключений.

---

## 3. Diff по файлам

### app/helpers.php

- В начале safe_redirect: проверка `strpbrk($url, "\r\n\0")` → редирект на `/`.
- Отдельная проверка `strpos($url, '//') === 0` → редирект на `/`.
- Относительный путь: условие оставлено как «начинается с `/`» (после запрета `//`).

### app/stats_cache.php

- После `stats_cache_envelope_payload($payload)` добавлена проверка `$envelope === false || $envelope === ''` и `$envelope = (string)$envelope` перед проверкой размера.

### app/stats.php

- В stats_active_orders в цикле по строкам: замена `if (isset($result[$rid][$st])) { $result[$rid][$st] = ... }` на `if (in_array($st, ['new', 'accepted', 'cooking', 'ready'], true)) { $result[$rid][$st] = (int)$row['cnt']; }`.

### app/alerts_repo.php

- В UPDATE: `seen_count + 1` заменено на `LEAST(seen_count + 1, 2147483647)`.
- Ветка `if (!$existing)`: INSERT обёрнут в try/catch PDOException; при duplicate (code 23000 или "Duplicate" в сообщении) — повторный SELECT и использование того же блока обновления по $existing.

### public_html/owner/dashboard.php

- После require alerts_repo: `set_exception_handler` — логирование, 500, короткий HTML без деталей, exit.
- Парсинг memory_limit: учёт `$ml === '' || $ml === '-1'` и regex с `B?`.
- Rate limit: при `count(...) > 30` перед sleep/429 выполняется `array_slice(..., -30, 30)`.
- В else при пустом scopeIds: добавлено `$alerts = [];`.

---

## 4. Production Risk Checklist

- [x] Все редиректы через safe_redirect; CRLF/protocol-relative заблокированы.
- [x] CSRF только в теле POST, 32 байта, hash_equals.
- [x] stats_*: early return при empty(restaurantIds); только разрешённые статусы в active_orders.
- [x] Кэш: placeholder ≠ hit; null кэшируется; при сбое json_encode запись не выполняется; размер payload ≤ 1MB.
- [x] Memory guard при limit ≥ 1M; корректный парсинг -1 и 128MB.
- [x] owner_alerts: cap seen_count; race при INSERT обрабатывается через catch duplicate + UPDATE.
- [x] Rate limit: ограничение по количеству запросов и обрезка массива в сессии; после 429 — exit.
- [x] Dashboard: глобальный exception handler; при пустом scope — инициализирован $alerts.
- [x] Нет изменения бизнес-логики, метрик, расчётов и UI.

---

## 5. Final verdict

**System is production-grade stable** для варианта 2 (2.3 → 2.7) в рамках проведённого аудита: устранены выявленные риски по безопасности редиректов, кэшу, алертам, rate limit и изоляции сбоев без изменения бизнес-логики и результатов.
