# Enterprise-Safe Baseline — финальный отчёт (Variant 2.3 → 2.7)

## 1. Найденные микро-уязвимости и шероховатости

| # | Категория | Проблема | Риск |
|---|-----------|----------|------|
| 1 | safe_redirect | Не проверялись embedded credentials (user:pass@), encoded CRLF (%0d%0a), trailing dot в host | Средний |
| 2 | safe_redirect | Относительный путь определялся по строке, а не по parse_url | Низкий |
| 3 | Cache | locked_until мог «зависнуть» навсегда при падении воркера после lock | Низкий |
| 4 | Cache | Envelope не требовал наличия __v для строгой валидации | Низкий |
| 5 | Cache | Ключ не гарантировался <= 128 символов явно | Низкий |
| 6 | wait_for_value | Параметры не ограничивались (теоретически бесконечный цикл) | Низкий |
| 7 | Exception handler | Не логировались user_id и URI; вызывался до auth | Низкий |
| 8 | Rate limit | Сессия не закрывалась после проверки → блокировка других запросов | Средний |
| 9 | SLOW_STATS | Не логировался scope_hash для диагностики | Низкий |
| 10 | owner_alerts | snooze_until в прошлом не переводил status в open автоматически | Низкий |
| 11 | XSS | e() не принимал null → возможный тип-ошибка при null | Низкий |

---

## 2. Что изменено

### Phase 1 — safe_redirect (app/helpers.php)

- Полная переработка на основе **parse_url**: сначала разбор, затем решение по частям.
- **Host**: сравнение в lowercase, **rtrim($host, '.')** (без trailing dot); то же для main_domain.
- **Запрещено**: protocol-relative (//), любые схемы кроме http/https, **user/pass** в URL, CRLF/NUL, **rawurldecode** для проверки encoded CRLF.
- Относительные пути разрешены только при отсутствии host и scheme в parse_url и path, начинающемся с `/` (без `//`).
- После каждого **header('Location: ...')** вызывается **exit**.
- В комментарии добавлены примеры запрещённых URL (unit-level self-check).

### Phase 2 — Cache (app/stats_cache.php)

- **Lock**: в UPDATE при acquire_lock добавлено условие **OR expires_at <= UTC_TIMESTAMP()** — запись с истёкшим TTL может быть перезаписана, locked_until не зависает навсегда.
- **wait_for_value**: жёсткие ограничения **maxWaitMs ≤ 600**, **stepMs ∈ [50, 200]**; не более 3–4 попыток за 600 ms; в комментарии явно указано «без бесконечного цикла».
- **Envelope**: строгая проверка — требуются **__type** и **__v**; только `__type === 'payload'` или `'placeholder'`; иначе MISS.
- **Legacy**: при отсутствии __type: decoded === null → MISS; иначе HIT с version = 0.
- **Ключ**: **substr($key, 0, 128)**; в комментарии к make_key указано: длина ≤ 128, коллизии между функциями исключены.

### Phase 3–4 — Memory/Time, Rate limit (public_html/owner/dashboard.php)

- **Memory_limit**: парсинг уже поддерживает -1, 128M, 256MB, 1G, 512K (regex с опциональным B).
- **Memory guard**: при -1 limitBytes = 0 → guard отключён; при usage > 70% → bypass кэша.
- **Time guard**: замер через **microtime(true)**; при > 5.0 с результат не кэшируется; в лог **SLOW_STATS** добавлены **name**, **scope_hash**, **elapsed_sec**.
- **Rate limit**: после 429 вызывается **session_write_close()**, затем **sleep(1)**, **429**, вывод текста, **exit**. После успешной проверки лимита вызывается **session_write_close()**, чтобы не держать сессию до конца запроса. Массив обрезается до последних 30 элементов.

### Phase 5 — Exception handler (public_html/owner/dashboard.php)

- **set_exception_handler** вызывается **после** require_login и **$user = auth_user()**.
- В лог пишутся **user_id**, **uri** (REQUEST_URI), **message** (без stack trace).
- Ответ: **500**, нейтральный HTML, без деталей ошибки.
- Debug-вывод (JSON) не затрагивается — handler срабатывает только при необработанном исключении.

### Phase 6 — Multi-tenant

- Проверено: во всех stats_* при **empty(restaurantIds)** выполняется early return; в запросах везде есть **restaurant_id** в WHERE/JOIN; при **restaurantIds = []** SQL не выполняется. Изменений не требовалось.

### Phase 7 — owner_alerts (app/alerts_repo.php)

- **seen_count**: уже ограничен через LEAST(..., 2147483647).
- В **owner_alerts_get_for_scope** в начале добавлен **UPDATE**: при **status = 'snoozed'** и **snooze_until <= UTC_TIMESTAMP()** устанавливаются **status = 'open'**, **snooze_until = NULL**. Таким образом, snooze_until в прошлом не остаётся без авто-перехода в open.

### Phase 8 — XSS (app/helpers.php, dashboard)

- **e(?string $value)**: аргумент сделан nullable, внутри **(string)($value ?? '')** — безопасный вывод при null.
- Debug JSON выводится через **htmlspecialchars(json_encode(...), ENT_QUOTES, 'UTF-8')** — без изменений.

---

## 3. Diff по файлам (сводка)

### app/helpers.php

- **e()**: сигнатура `e(?string $value)`, тело `htmlspecialchars((string)($value ?? ''), ...)`.
- **safe_redirect()**: переписан с использованием parse_url; проверки на CRLF/NUL, rawurldecode для encoded CRLF; запрет scheme не http/https, user/pass, protocol-relative; host и main без trailing dot; относительные пути только при path, начинающемся с `/`; комментарий с примерами запрещённых URL.

### app/stats_cache.php

- **stats_cache_make_key**: комментарий про длину ≤ 128 и отсутствие коллизий; `return substr($key, 0, 128)`.
- **stats_cache_get**: строгая проверка envelope (__type и __v обязательны; только payload/placeholder); legacy: null → MISS.
- **stats_cache_acquire_lock**: в WHERE добавлено **OR expires_at <= UTC_TIMESTAMP()**.
- **stats_cache_wait_for_value**: ограничение параметров (maxWaitMs ≤ 600, stepMs 50–200); комментарий про 3–4 попытки и отсутствие бесконечного цикла.

### public_html/owner/dashboard.php

- **set_exception_handler** перенесён после auth; в лог: user_id, uri, message; ответ 500 без stack trace.
- **Rate limit**: при count > 30 перед sleep/429 вызывается **session_write_close()**; после блока проверки лимита — **session_write_close()**.
- **SLOW_STATS**: формат лога `sprintf('SLOW_STATS name=%s scope_hash=%s elapsed_sec=%.2f', ...)`.

### app/alerts_repo.php

- В **owner_alerts_get_for_scope** в начале: UPDATE для перевода snoozed → open при истекшем snooze_until.

---

## 4. Production-grade verdict

**Состояние: enterprise-safe baseline достигнут.**

- Редиректы проходят через единый безопасный слой (parse_url, запрет credentials/CRLF/внешних доменов).
- Кэш: lock не зависает навсегда, envelope проверяется строго, ключ ограничен по длине, wait ограничен по времени и числу попыток.
- Ограничения по памяти и времени соблюдаются; медленные вызовы логируются с name и scope_hash.
- Rate limit не блокирует сессию дольше необходимого и не раздувает массив.
- Необработанные исключения логируются с user_id и URI без раскрытия stack trace; ответ — нейтральный 500.
- Multi-tenant инварианты сохранены; алерты с истёкшим snooze автоматически переходят в open.
- Вывод в шаблонах защищён (e() с поддержкой null, debug JSON экранирован).

Бизнес-логика, расчёты, UI и SQL-метрики не менялись.
