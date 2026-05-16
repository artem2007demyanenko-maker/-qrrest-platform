# Brand и статические иконки (QR REST)

Единая точка входа в `<head>` для иконок и стиля бренда — **`brand_head_tags()`** в `app/brand.php` (подключается через `app/bootstrap.php` на большинстве страниц).

---

## Канонические пути (относительно `public_html/`)

| Файл | Назначение |
|------|------------|
| **`/assets/brand/favicon.svg`** | Основной векторный favicon (в репозитории всегда). Fallback для apple-touch, если PNG нет. |
| **`/assets/brand/apple-touch-icon.png`** | 180×180 для iOS/закладок (желательно в проде). |
| **`/assets/brand/favicon-32.png`** | Промежуточный 32×32 (используется при сборке ICO/PNG в корне). |
| **`/favicon.png`** | 32×32 в корне сайта (дубль для `<link rel="icon" type="image/png">`). |
| **`/favicon.ico`** | ICO в корне (старые клиенты). |
| **`/assets/css/brand.css`** | Стили wordmark/кластера. |

**Не канонично:** `qr_rest_logo_transparent.png` — в проекте не используется; логотип в UI — inline SVG (`brand_logo_svg_inline`).

---

## Обязательные vs сгенерированные

- **Обязательно в git для деплоя без сюрпризов:** `favicon.svg`, и **сгенерированные** `apple-touch-icon.png`, `favicon.png`, `favicon.ico`, `favicon-32.png` — чтобы не зависеть от запуска скрипта на сервере.
- **Генерация:** `php tools/generate_brand_pngs.php` (нужен **ext-gd**). Обновляйте файлы локально и коммитьте после смены дизайна марки.

---

## Логика `brand_head_tags()`

- Всегда отдаёт ссылку на **`favicon.svg`**.
- Ссылки на **`/favicon.png`**, **`/favicon.ico`**, **`/assets/brand/apple-touch-icon.png`** добавляются **только если файлы существуют** на диске — нет лишних 404 в HTML.
- Если `apple-touch-icon.png` отсутствует, apple-touch указывает на **`favicon.svg`**.

Страница **`maintenance.php`** не грузит bootstrap — в ней статически указан только **`/assets/brand/favicon.svg`** + `theme-color`.

---

## JSON-LD (главная)

`public_html/index.php` использует **`brand_logo_path_for_schema()`** — абсолютный URL логотипа для schema.org Organization (PNG при наличии, иначе SVG).

---

## Docker / prod

- В контейнере `app` код монтируется из репозитория; **главное — чтобы сгенерированные бинарники были в git** перед деплоем.
- Установка **gd** в Dockerfile не требуется для рантайма, если не генерируете иконки внутри образа.
