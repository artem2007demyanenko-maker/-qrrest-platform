## Product Features V2 — QR Restaurant SaaS

This pack introduces small, backwards‑compatible product improvements focused on menu presentation and kitchen operations.

### 1. Menu item images

- Schema: new migration `2026_06_01_add_menu_images.sql` adds an optional `image_path VARCHAR(255) NULL` column to `menu_items` (idempotent).
- UI: `restaurant/menu_items.php` already supports:
  - image upload field (optional),
  - thumbnail preview for existing images,
  - image display in the menu item list.
- Storage:
  - images are stored under `storage/menu/` on disk,
  - referenced as `/storage/{image_path}` in public pages (QR menu, restaurant public pages, staff POS).
- Supported formats:
  - `jpg`, `jpeg`, `png`, `webp` (validated in the upload helper).

### 2. QR table generator

- New page: `restaurant/tables_qr.php` (owner/admin only).
- Features:
  - Lists all tables for the current restaurant.
  - Shows the exact QR target URL for each table:
    - `https://{restaurant_subdomain}.{main_domain}/qr.php?table_id={id}`
  - Provides a **“Скачать PNG”** button per table pointing at a QR image generated via the same external service used in `qr_print.php`.
- For full printable sheets, `restaurant/qr_print.php` remains the recommended tool.

### 3. Kitchen display improvements

- Page: `staff/kitchen.php` (staff/admin/owner, restaurant‑scoped).
- Behaviour:
  - Live columns: **New**, **Cooking**, **Ready**, with auto‑refresh every 10 seconds.
  - Uses `/staff/orders_api.php` (JSON) to fetch the latest 100 orders.
- Order timer:
  - Each order now shows “N мин назад” (minutes since `created_at`).
  - Implemented both for:
    - demo data (server‑rendered blocks),
    - live data (JSON field `since_minutes` from `orders_api.php` and JS rendering).

