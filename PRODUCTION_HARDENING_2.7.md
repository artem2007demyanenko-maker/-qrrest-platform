# Production Hardening Variant 2.7 — итог

## Изменённые файлы

| Файл | Назначение изменений |
|------|----------------------|
| `app/bootstrap.php` | Security headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, CSP, HSTS при HTTPS) |
| `app/helpers.php` | Функция `safe_redirect()` для безопасных редиректов |
| `app/stats_cache.php` | Лимит payload 1MB, батчирование purge_scope (LIMIT 1000) |
| `app/stats.php` | Early-return в `stats_top_share` при пустом `restaurantIds`, try/catch в `stats_alerts` |
| `public_html/owner/dashboard.php` | safe_redirect, CSRF 32 bytes, rate limit, memory/time guards и error isolation в remember(), debug только для owner, использование $debugEnabled |

**Без изменений (уже соответствовали):**

- `app/db.php` — уже заданы `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` и `PDO::ATTR_EMULATE_PREPARES => false`.
- Остальные `stats_*` — ранние выходы при `empty($restaurantIds)` уже были.

---

## Краткие диффы по файлам

### app/bootstrap.php

- После подключения конфига добавлены заголовки:
  - `X-Frame-Options: SAMEORIGIN`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Content-Security-Policy`: default-src 'self'; script/style с 'self' и 'unsafe-inline' и CDN (Tailwind/jsdelivr); img-src 'self' data:
  - `Strict-Transport-Security` только при `protocol === 'https'`

### app/helpers.php

- Добавлена функция `safe_redirect(string $url)`:
  - Разрешены только относительные пути (начинаются с `/`, не с `//`) или абсолютный URL с хостом из `config app.main_domain` / `www.main_domain`.
  - Иначе редирект на `/`.

### app/stats_cache.php

- В `stats_cache_set()`: после `json_encode` проверка `strlen($envelope) > 1024*1024` → выход без записи.
- В `stats_cache_purge_scope()`: удаление батчами по 1000 строк в цикле (без массового DELETE всего объёма).

### app/stats.php

- В `stats_top_share()`: в начале добавлен `if (empty($restaurantIds)) return ['top3_revenue'=>0,'total_revenue'=>0,'share_pct'=>null];`
- В `stats_alerts()`: тело функции обёрнуто в `try { ... } catch (Throwable $e) { error_log('STATS_ALERTS_ERROR ...'); return []; }`

### public_html/owner/dashboard.php

- Все редиректы переведены на `safe_redirect(...)` (4 места).
- CSRF: генерация через `bin2hex(random_bytes(32))`, проверка через `hash_equals` (уже была).
- Rate limit (session): массив последних времён запросов в `$_SESSION['owner_dashboard_requests']`, окно 10 сек; при >30 запросах — `sleep(1)`, `http_response_code(429)`, вывод "Too Many Requests", `exit`.
- `$debugEnabled = isset($_GET['debug']) && (($user['global_role'] ?? '') === 'owner')`; все проверки `$_GET['debug']` заменены на `$debugEnabled` (в т.ч. POST purge и блок отладки).
- В `remember()`:
  - Обёртка `$runCb`: вызов callback в try/catch, при Throwable — `error_log('STATS_CACHE_CALLBACK_ERROR ...')`, возврат `[]`.
  - Memory guard: при `memory_get_usage(true) > 0.7 * memory_limit` (если limit >= 1M) — без кэша, только callback, в debug — `bypass_memory`.
  - Time guard: если время выполнения callback > 5 сек — результат не кэшируется, `error_log('SLOW_STATS name=... elapsed_sec=...')`, значение возвращается.
- POST purge проверяет `$debugEnabled` вместо `$_GET['debug']`.

---

## Production checklist

- [x] **Security headers** — в bootstrap выставляются X-Frame-Options, X-Content-Type-Options, Referrer-Policy, CSP, HSTS при HTTPS.
- [x] **Безопасные редиректы** — в owner/dashboard все редиректы через `safe_redirect()`; разрешены только относительные пути или main_domain.
- [x] **CSRF** — токен 32 байта, проверка `hash_equals`; изменение состояния только через POST (alert_status, purge_cache).
- [x] **Кэш** — payload не более 1MB; при переполнении памяти (>70% limit) кэш не используется; при запросе >5 сек результат не кэшируется, пишется SLOW_STATS в лог.
- [x] **БД** — PDO с ERRMODE_EXCEPTION и EMULATE_PREPARES = false; purge_scope выполняется батчами по 1000 строк.
- [x] **Multi-tenant** — во всех stats_* при `empty($restaurantIds)` возвращается безопасный пустой результат; stats_alerts при любой ошибке возвращает `[]` и логирует.
- [x] **Rate limit** — owner dashboard: не более 30 запросов за 10 сек (session), при превышении — 429 и sleep(1).
- [x] **Error isolation** — в remember() callback в try/catch, при ошибке возврат `[]` и error_log; stats_alerts в try/catch с возвратом `[]` и логом.
- [x] **Debug** — `debug=1` учитывается только при `global_role === 'owner'`.

Бизнес-логика, метрики, расчёты, алерты и UI не менялись.
