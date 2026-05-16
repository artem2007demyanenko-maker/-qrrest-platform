# RELEASE_SMOKE_CHECKLIST — после деплоя (~15–20 мин)

Отметьте чекбоксы. Домен подставьте свой (ниже пример `qrrest-menu.ru`, тест-поддомен — из вашей конфигурации).

**Предусловия:** прод отвечает по HTTPS; залогинен тестовый owner (или есть учётка для проверки).

---

## Инфра и health

- [ ] `GET /health.php` → JSON, `"db": "ok"`, нет неожиданного `"status": "error"` (degraded с `migrations_pending` — зафиксировать, не игнорировать вечно).
- [ ] CLI: `curl -sS https://qrrest-menu.ru/health.php | jq .` (или без `jq`: первые строки JSON).
- [ ] `curl -sI https://qrrest-menu.ru/health.php` → `200`, `Content-Type` JSON.
- [ ] VPS (опционально): `docker exec <app> php -i | grep -E 'upload_max_filesize|post_max_size'` — согласовано с `deploy/php/zz-uploads.ini`.

**CLI-пакет (подставьте домен):**

```bash
curl -sS "https://qrrest-menu.ru/health.php" | jq .
docker exec qr-rest_app_1 php -i | grep -E 'upload_max_filesize|post_max_size'
```

## Главная и маркетинг

- [ ] `https://qrrest-menu.ru/` (или ваш landing) открывается без 500.

## Статика / ассеты (brand)

Проверить **отсутствие 404** на путях из `docs/BRAND_ASSETS.md` (подставьте домен):

- [ ] `curl -sI https://qrrest-menu.ru/assets/brand/favicon.svg` → `200`
- [ ] `curl -sI https://qrrest-menu.ru/assets/brand/favicon-32.png` → `200`
- [ ] `curl -sI https://qrrest-menu.ru/favicon.png` → `200`
- [ ] `curl -sI https://qrrest-menu.ru/favicon.ico` → `200`
- [ ] `curl -sI https://qrrest-menu.ru/assets/brand/apple-touch-icon.png` → `200`
- [ ] Главная: View Source — в JSON-LD поле `logo` указывает на тот же хост + путь из `brand_logo_path_for_schema()` (обычно `…/assets/brand/apple-touch-icon.png` или `…/assets/brand/favicon.svg`).
- [ ] Любая страница с `brand_head_tags()` (меню QR, логин): в DevTools → Network нет 404 на запрошенных `<link rel="icon" …>`.
- [ ] Страница `/privacy.php` и типовая ошибка (`/error.php` при 404): иконка подгружается (не обязательно дублировать все curl — достаточно одной проверки HTML).

## Guest menu / QR

- [ ] Открыть гостевое меню (например `https://test.qrrest-menu.ru/qr.php?...` с валидным `table_id`).
- [ ] Категории / список блюд отображаются.
- [ ] **Mobile QR menu** (узкая ширина или реальное устройство): прокрутка, floating cart / CTA не перекрыты, нет горизонтального «слома».
- [ ] **Mobile** (или эмулятор): меню прокручивается, нет «сломанной» вёрстки; корзина/CTA доступны.

## Корзина

- [ ] Добавить позицию в корзину.
- [ ] Изменить количество / убрать позицию — состояние ожидаемое.

## Checkout

- [ ] Пройти checkout до ожидаемого конца (успех или контролируемая остановка тестовой оплаты — как у вас настроено).

## Трекинг заказа

- [ ] `order_track.php` (или ваш URL) — заказ виден / статус не падает с ошибкой.

## Owner / admin

- [ ] Логин в кабинет (`/login.php` → owner dashboard).
- [ ] Открыть раздел управления меню: `restaurant/menu_manage.php` (или ваш путь).
- [ ] **Mobile:** `restaurant/dashboard.php` и страницы с боковым меню — выдвижная навигация не перекрывается критично cookie-баннером / FAB (z-index consent выше обычного UI; проверить тапы).

## Загрузка фото блюда (menu manage)

- [ ] В `restaurant/menu_manage.php` загрузить фото блюда: JPG/PNG/WebP **< 10 MB** — успех, превью/сохранение без ошибки.
- [ ] При ошибке: `docs/PROD_RUNBOOK.md` (PHP limits, `public_html/uploads` writable).

## Cookie consent

- [ ] Баннер внизу страницы появляется при чистом `localStorage` (или инкогнито).
- [ ] «Принять все» / «Только необходимые» / «Настроить» — работают; повторное открытие через «Настройки cookies».
- [ ] Ссылка «Политика…» → `/privacy.php` не 404.

## Активный заказ (guest)

- [ ] При активном заказе блок статуса/таймера (`order_timer` / stripe в UI) отображается и обновляется без 500 (см. сценарий гостя).

## HTTPS тест-поддомена

- [ ] Браузер: `https://test.<ваш-домен>/` и `.../qr.php?...` — нет предупреждения сертификата.
- [ ] CLI: `echo | openssl s_client -servername test.<домен> -connect test.<домен>:443 2>/dev/null | openssl x509 -noout -subject -dates` (при необходимости добавить `-ext subjectAltName`).
- [ ] **Direct asset на поддомене**: URL картинки блюда с того же хоста → `curl -sI` → `200` (или проверка в DevTools → Network).

---

**Fail:** любой 500 на критическом пути, `health.php` с `db: error`, полная недоступность HTTPS на поддоменах гостевого меню.

## Do not do this (кратко)

См. `docs/PROD_RUNBOOK.md` — не копировать артефакты из `~/Downloads` на неизвестный путь; не путать `docker-compose.yml` (dev) и `docker-compose.prod.yml`; не считать git-nginx истиной без `docker exec <nginx> nginx -T` на VPS; не делать `compose rm` / `--force-recreate` без проверки томов (`dbdata_prod`) и сети.

**Ручное восстановление контейнеров:** `docs/PROD_RUNBOOK.md` (§0, Fast recovery, Manual fallback).

**Заметки (дата / коммит / кто):**  
…
